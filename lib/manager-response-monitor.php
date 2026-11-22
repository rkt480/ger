<?php

declare(strict_types=1);

require_once __DIR__ . '/manager-ai.php';

function crm_manager_response_schema(PDO $pdo): void
{
    crm_manager_monitor_ensure_schema($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_group_response_checks (
        group_id BIGINT UNSIGNED NOT NULL, message_id BIGINT UNSIGNED NOT NULL,
        manager_user_id INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT "pending",
        notified_at DATETIME NULL, error_message TEXT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (group_id, message_id), INDEX idx_response_status (status, updated_at),
        FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (message_id) REFERENCES manager_group_messages(id) ON DELETE CASCADE,
        FOREIGN KEY (manager_user_id) REFERENCES crm_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_group_daily_summaries (
        group_id BIGINT UNSIGNED NOT NULL, summary_date DATE NOT NULL,
        client_message_count INT UNSIGNED NOT NULL DEFAULT 0,
        manager_message_count INT UNSIGNED NOT NULL DEFAULT 0,
        pending_count INT UNSIGNED NOT NULL DEFAULT 0,
        summary TEXT NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (group_id, summary_date),
        FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function crm_manager_response_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function crm_manager_response_business_minutes(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    $zone = new DateTimeZone('America/Sao_Paulo');
    $from = $from->setTimezone($zone);
    $to = $to->setTimezone($zone);
    $minutes = 0;
    for ($day = $from->setTime(0, 0); $day < $to; $day = $day->modify('+1 day')) {
        if ((int) $day->format('N') > 5) continue;
        $start = max($from->getTimestamp(), $day->setTime(9, 0)->getTimestamp());
        $end = min($to->getTimestamp(), $day->setTime(18, 0)->getTimestamp());
        if ($end > $start) $minutes += intdiv($end - $start, 60);
    }
    return $minutes;
}

function crm_manager_response_run(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    crm_manager_response_schema($pdo);
    $zone = new DateTimeZone('America/Sao_Paulo');
    $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    $today = $now->format('Y-m-d');
    $rows = $pdo->query('SELECT g.id AS group_id, g.name AS group_name, c.name AS client_name,
        u.id AS manager_id, u.name AS manager_name, u.whatsapp_phone
        FROM manager_groups g JOIN manager_clients c ON c.id = g.client_id
        JOIN crm_users u ON u.id = c.manager_user_id
        WHERE g.active = 1 AND c.active = 1 AND u.active = 1')->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;
    $summaries = 0;
    foreach ($rows as $group) {
        $phone = crm_manager_response_phone((string) $group['whatsapp_phone']);
        $groupId = (int) $group['group_id'];
        $query = $pdo->prepare('SELECT id, sender_phone, sender_name, body, from_me,
            COALESCE(sent_at, received_at) AS message_at FROM manager_group_messages
            WHERE group_id = :group_id AND COALESCE(sent_at, received_at) >= :since
            ORDER BY COALESCE(sent_at, received_at), id');
        $query->execute(['group_id' => $groupId, 'since' => $now->modify('-8 days')->format('Y-m-d H:i:s')]);
        $messages = $query->fetchAll(PDO::FETCH_ASSOC);
        $pending = [];
        $todayClient = 0;
        $todayManager = 0;
        foreach ($messages as $message) {
            $sender = crm_manager_response_phone((string) ($message['sender_phone'] ?? ''));
            $isManager = $phone !== '' && $sender !== '' && $sender === $phone;
            $at = (string) $message['message_at'];
            if ($isManager) {
                if (substr($at, 0, 10) === $today) $todayManager++;
                $pending = [];
            } elseif ((int) $message['from_me'] === 0) {
                if (substr($at, 0, 10) === $today) $todayClient++;
                $pending[] = $message;
            }
        }
        $summary = $todayClient === 0 && $todayManager === 0
            ? 'Sem conversa registrada hoje.'
            : sprintf('%d mensagem(ns) de participantes e %d do gestor hoje. %d mensagem(ns) aguardando resposta do gestor.', $todayClient, $todayManager, count($pending));
        $saveSummary = $pdo->prepare('INSERT INTO manager_group_daily_summaries
            (group_id, summary_date, client_message_count, manager_message_count, pending_count, summary, updated_at)
            VALUES (:group_id, :summary_date, :client_count, :manager_count, :pending_count, :summary, :updated_at)
            ON DUPLICATE KEY UPDATE client_message_count = VALUES(client_message_count),
            manager_message_count = VALUES(manager_message_count), pending_count = VALUES(pending_count),
            summary = VALUES(summary), updated_at = VALUES(updated_at)');
        $saveSummary->execute(['group_id' => $groupId, 'summary_date' => $today,
            'client_count' => $todayClient, 'manager_count' => $todayManager,
            'pending_count' => count($pending), 'summary' => $summary,
            'updated_at' => $now->format('Y-m-d H:i:s')]);
        $summaries++;
        if ($phone === '' || $pending === []) continue;
        $first = $pending[0];
        $at = new DateTimeImmutable((string) $first['message_at'], $zone);
        if (crm_manager_response_business_minutes($at, $now) < 120
            || (int) $now->format('N') > 5 || $now->format('H:i') < '09:00' || $now->format('H:i') >= '18:00') continue;
        $messageId = (int) $first['id'];
        $claim = $pdo->prepare('INSERT IGNORE INTO manager_group_response_checks
            (group_id, message_id, manager_user_id, status, updated_at)
            VALUES (:group_id, :message_id, :manager_id, "processing", :updated_at)');
        $claim->execute(['group_id' => $groupId, 'message_id' => $messageId,
            'manager_id' => (int) $group['manager_id'], 'updated_at' => $now->format('Y-m-d H:i:s')]);
        if ($claim->rowCount() === 0) {
            $retry = $pdo->prepare('UPDATE manager_group_response_checks SET status = "processing", updated_at = :now
                WHERE group_id = :group_id AND message_id = :message_id AND status = "failed" AND updated_at < :retry_before');
            $retry->execute(['now' => $now->format('Y-m-d H:i:s'), 'group_id' => $groupId,
                'message_id' => $messageId, 'retry_before' => $now->modify('-15 minutes')->format('Y-m-d H:i:s')]);
            if ($retry->rowCount() === 0) continue;
        }
        $notice = '⏰ Mensagem aguardando resposta no grupo ' . $group['group_name'] . ' (' . $group['client_name'] . '). '
            . 'Primeira mensagem: ' . $at->format('d/m H:i') . '. '
            . 'Confira a conversa e dê um retorno ao cliente.';
        try {
            $result = crm_whatsapp_send_text($phone, crm_manager_monitor_limit($notice, 1500), false);
            $ok = ($result['ok'] ?? false) === true;
            $error = $ok ? null : crm_manager_monitor_limit((string) ($result['error'] ?? 'Envio não confirmado'), 500);
        } catch (Throwable $failure) {
            $ok = false;
            $error = crm_manager_monitor_limit($failure->getMessage(), 500);
        }
        $pdo = crm_db();
        $update = $pdo->prepare('UPDATE manager_group_response_checks SET status = :status,
            notified_at = :notified_at, error_message = :error_message, updated_at = :updated_at
            WHERE group_id = :group_id AND message_id = :message_id');
        $update->execute(['status' => $ok ? 'sent' : 'failed', 'notified_at' => $ok ? $now->format('Y-m-d H:i:s') : null,
            'error_message' => $error, 'updated_at' => $now->format('Y-m-d H:i:s'),
            'group_id' => $groupId, 'message_id' => $messageId]);
        if ($ok) $sent++;
    }
    return ['groups_summarized' => $summaries, 'notices_sent' => $sent];
}
