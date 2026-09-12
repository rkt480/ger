<?php

declare(strict_types=1);

/**
 * Operational monitoring for WhatsApp groups.
 *
 * This module intentionally stores normalized message data instead of sending
 * the webhook payload directly to the browser or to the AI. The webhook is an
 * untrusted public input, so every value is length-limited and persisted with
 * prepared statements before any later analysis is scheduled.
 */

function crm_manager_monitor_ensure_schema(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $statements = [
        'CREATE TABLE IF NOT EXISTS manager_clients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            external_ref VARCHAR(120) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_manager_clients_active (active, name),
            INDEX idx_manager_clients_external_ref (external_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS manager_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            provider_number_id VARCHAR(160) NOT NULL,
            external_group_id VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT "Grupo sem nome",
            active TINYINT(1) NOT NULL DEFAULT 1,
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_manager_groups_provider_external (provider_number_id, external_group_id),
            INDEX idx_manager_groups_client (client_id, active),
            INDEX idx_manager_groups_activity (last_message_at, active),
            CONSTRAINT fk_manager_groups_client
              FOREIGN KEY (client_id) REFERENCES manager_clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS manager_group_participants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT UNSIGNED NOT NULL,
            external_participant_id VARCHAR(255) NOT NULL,
            phone VARCHAR(40) NULL,
            display_name VARCHAR(180) NULL,
            is_business_number TINYINT(1) NOT NULL DEFAULT 0,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            UNIQUE KEY uq_manager_participants_group_external (group_id, external_participant_id),
            INDEX idx_manager_participants_phone (phone),
            CONSTRAINT fk_manager_participants_group
              FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS manager_group_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NULL,
            provider_number_id VARCHAR(160) NOT NULL,
            external_message_id VARCHAR(255) NOT NULL,
            source_payload_hash CHAR(64) NOT NULL,
            sender_external_id VARCHAR(255) NULL,
            sender_phone VARCHAR(40) NULL,
            sender_name VARCHAR(180) NULL,
            message_type VARCHAR(40) NOT NULL DEFAULT "text",
            body LONGTEXT NULL,
            media_metadata LONGTEXT NULL,
            reply_to_external_id VARCHAR(255) NULL,
            from_me TINYINT(1) NOT NULL DEFAULT 0,
            sent_at DATETIME NULL,
            received_at DATETIME NOT NULL,
            analysis_status VARCHAR(30) NOT NULL DEFAULT "pending",
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_manager_messages_provider_id (provider_number_id, external_message_id),
            INDEX idx_manager_messages_group_time (group_id, sent_at, id),
            INDEX idx_manager_messages_analysis (analysis_status, received_at),
            INDEX idx_manager_messages_sender_phone (sender_phone),
            CONSTRAINT fk_manager_messages_group
              FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_manager_messages_participant
              FOREIGN KEY (participant_id) REFERENCES manager_group_participants(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS manager_ai_analyses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            model VARCHAR(100) NOT NULL,
            prompt_version VARCHAR(80) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "completed",
            severity VARCHAR(20) NULL,
            categories LONGTEXT NULL,
            summary TEXT NULL,
            evidence TEXT NULL,
            confidence DECIMAL(5,4) NULL,
            result_json LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_manager_ai_analyses_group (group_id, created_at),
            INDEX idx_manager_ai_analyses_message (message_id, created_at),
            CONSTRAINT fk_manager_ai_analyses_group
              FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_manager_ai_analyses_message
              FOREIGN KEY (message_id) REFERENCES manager_group_messages(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS manager_alerts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            alert_type VARCHAR(60) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT "medium",
            status VARCHAR(30) NOT NULL DEFAULT "open",
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            evidence TEXT NULL,
            confidence DECIMAL(5,4) NULL,
            assigned_user_id INT NULL,
            resolved_by_user_id INT NULL,
            resolved_at DATETIME NULL,
            resolution_note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_manager_alerts_status (status, severity, created_at),
            INDEX idx_manager_alerts_client (client_id, status, created_at),
            INDEX idx_manager_alerts_group (group_id, status, created_at),
            CONSTRAINT fk_manager_alerts_client
              FOREIGN KEY (client_id) REFERENCES manager_clients(id) ON DELETE SET NULL,
            CONSTRAINT fk_manager_alerts_group
              FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_manager_alerts_message
              FOREIGN KEY (message_id) REFERENCES manager_group_messages(id) ON DELETE SET NULL,
            CONSTRAINT fk_manager_alerts_assigned_user
              FOREIGN KEY (assigned_user_id) REFERENCES crm_users(id) ON DELETE SET NULL,
            CONSTRAINT fk_manager_alerts_resolved_user
              FOREIGN KEY (resolved_by_user_id) REFERENCES crm_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS manager_report_requirements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(180) NOT NULL,
            frequency VARCHAR(20) NOT NULL DEFAULT "daily",
            due_weekday TINYINT UNSIGNED NULL,
            due_time TIME NULL,
            responsible_external_id VARCHAR(255) NULL,
            keywords_json LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_manager_reports_due (active, frequency, due_weekday, due_time),
            INDEX idx_manager_reports_group (group_id, active),
            CONSTRAINT fk_manager_reports_client
              FOREIGN KEY (client_id) REFERENCES manager_clients(id) ON DELETE SET NULL,
            CONSTRAINT fk_manager_reports_group
              FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    // Existing installations already have manager_alerts, so keep the
    // resolution note backwards-compatible without requiring a manual SQL
    // migration after deployment.
    $resolutionNoteColumn = $pdo->query("SHOW COLUMNS FROM manager_alerts LIKE 'resolution_note'");

    if (!$resolutionNoteColumn->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE manager_alerts ADD COLUMN resolution_note TEXT NULL AFTER resolved_at');
    }

    $ensured = true;
}

function crm_manager_monitor_limit(string $value, int $maxLength): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength, 'UTF-8')
        : substr($value, 0, $maxLength);
}

function crm_manager_monitor_scalar_at(array $payload, array $paths): string
{
    foreach ($paths as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (is_scalar($value)) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function crm_manager_monitor_truthy_at(array $payload, array $paths): bool
{
    foreach ($paths as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'sim'], true)) {
            return true;
        }
    }

    return false;
}

function crm_manager_monitor_collect_items(array $payload): array
{
    $items = [];

    if (is_array($payload['entry'] ?? null)) {
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || !is_array($entry['changes'] ?? null)) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                foreach (($value['messages'] ?? []) as $message) {
                    if (is_array($message)) {
                        $message['_metadata'] = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                        $items[] = $message;
                    }
                }
            }
        }
    }

    if ($items === []) {
        foreach ([
            ['messages'],
            ['data', 'messages'],
            ['payload', 'messages'],
            ['events'],
            ['data', 'events'],
            ['payload', 'events'],
        ] as $path) {
            $value = pilot_status_read_path($payload, $path);

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }
    }

    if ($items === []) {
        $items[] = $payload;
    }

    return $items;
}

function crm_manager_monitor_group_id(array $payload): string
{
    $paths = [
        ['key', 'remoteJid'],
        ['remoteJid'],
        ['remote_jid'],
        ['groupId'],
        ['group_id'],
        ['groupJid'],
        ['group_jid'],
        ['chatId'],
        ['chat_id'],
        ['chat', 'id'],
        ['group', 'id'],
        ['data', 'key', 'remoteJid'],
        ['data', 'remoteJid'],
        ['data', 'groupId'],
        ['data', 'group', 'id'],
        ['data', 'chat', 'id'],
        ['message', 'key', 'remoteJid'],
        ['message', 'remoteJid'],
        ['message', 'groupId'],
        ['message', 'group', 'id'],
        ['payload', 'key', 'remoteJid'],
        ['payload', 'remoteJid'],
        ['payload', 'groupId'],
    ];

    foreach ($paths as $path) {
        $candidate = crm_manager_monitor_scalar_at($payload, [$path]);

        if (str_contains(strtolower($candidate), '@g.us')) {
            return crm_manager_monitor_limit($candidate, 255);
        }
    }

    if (!crm_manager_monitor_truthy_at($payload, [
        ['isGroup'],
        ['is_group'],
        ['group'],
        ['data', 'isGroup'],
        ['data', 'is_group'],
        ['data', 'group'],
    ])) {
        $event = strtolower(crm_manager_monitor_scalar_at($payload, [['event'], ['eventType'], ['type'], ['data', 'event']]));

        if ($event !== 'message.group' && $event !== 'group.message' && $event !== 'messages-group.received') {
            return '';
        }
    }

    foreach ($paths as $path) {
        $candidate = crm_manager_monitor_scalar_at($payload, [$path]);

        if ($candidate !== '') {
            return crm_manager_monitor_limit($candidate, 255);
        }
    }

    return '';
}

function crm_manager_monitor_participant(array $payload, string $groupId): array
{
    $raw = crm_manager_monitor_scalar_at($payload, [
        ['key', 'participant'],
        ['participant'],
        ['participantId'],
        ['participant_id'],
        ['sender', 'id'],
        ['sender', 'phone'],
        ['sender', 'number'],
        ['data', 'key', 'participant'],
        ['data', 'participant'],
        ['data', 'participantId'],
        ['data', 'sender', 'id'],
        ['data', 'sender', 'phone'],
        ['message', 'key', 'participant'],
        ['message', 'participant'],
        ['from'],
        ['data', 'from'],
    ]);

    if ($raw === '' || str_contains(strtolower($raw), '@g.us')) {
        $raw = crm_manager_monitor_scalar_at($payload, [
            ['from'],
            ['data', 'from'],
            ['message', 'from'],
        ]);
    }

    $raw = crm_manager_monitor_limit($raw, 255);
    $phone = '';

    if ($raw !== '') {
        $phone = pilot_status_normalize_phone_candidate($raw);
    }

    if ($raw === '') {
        $raw = 'unknown:' . hash('sha256', $groupId);
    }

    $name = crm_manager_monitor_limit(crm_manager_monitor_scalar_at($payload, [
        ['pushName'],
        ['push_name'],
        ['participantName'],
        ['participant_name'],
        ['sender', 'name'],
        ['data', 'sender', 'name'],
        ['data', 'participantName'],
        ['message', 'sender', 'name'],
    ]), 180);

    return [
        'external_id' => $raw,
        'phone' => $phone !== '' ? $phone : null,
        'name' => $name !== '' ? $name : null,
    ];
}

function crm_manager_monitor_message_timestamp(array $payload): ?string
{
    $raw = crm_manager_monitor_scalar_at($payload, [
        ['createdAt'],
        ['timestamp'],
        ['messageTimestamp'],
        ['message_timestamp'],
        ['sentAt'],
        ['sent_at'],
        ['message', 'timestamp'],
        ['data', 'createdAt'],
        ['data', 'timestamp'],
        ['data', 'messageTimestamp'],
        ['data', 'message', 'timestamp'],
    ]);

    if ($raw === '') {
        return null;
    }

    if (ctype_digit($raw)) {
        $timestamp = (int) $raw;

        if ($timestamp > 100000000000) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        if ($timestamp >= 946684800 && $timestamp <= 4102444800) {
            return date('Y-m-d H:i:s', $timestamp);
        }
    }

    $timestamp = strtotime($raw);

    return $timestamp !== false && $timestamp >= 946684800 && $timestamp <= 4102444800
        ? date('Y-m-d H:i:s', $timestamp)
        : null;
}

function crm_manager_monitor_provider_number_id(array $payload): string
{
    $value = crm_manager_monitor_scalar_at($payload, [
        ['_metadata', 'phone_number_id'],
        ['metadata', 'phone_number_id'],
        ['data', 'whatsappNumberId'],
        ['data', 'numberId'],
        ['phone_number_id'],
        ['phoneNumberId'],
        ['numberId'],
        ['number_id'],
        ['instanceId'],
        ['instance_id'],
        ['_metadata', 'display_phone_number'],
        ['metadata', 'display_phone_number'],
        ['display_phone_number'],
        ['connected_number'],
        ['business_number'],
    ]);

    return crm_manager_monitor_limit($value !== '' ? $value : 'pilot-status-default', 160);
}

function crm_manager_monitor_group_name(array $payload): string
{
    $value = crm_manager_monitor_scalar_at($payload, [
        ['data', 'groupName'],
        ['data', 'group_name'],
        ['groupName'],
        ['group_name'],
        ['subject'],
        ['group', 'name'],
        ['chat', 'name'],
        ['data', 'group', 'name'],
        ['data', 'chat', 'name'],
        ['message', 'groupName'],
    ]);

    return crm_manager_monitor_limit($value !== '' ? $value : 'Grupo sem nome', 255);
}

/**
 * Canonical Pilot Status group events may omit groupName. In that case use
 * the number-scoped groups endpoint to resolve all names in one request.
 * The result is cached for the lifetime of the webhook request so a batch of
 * messages does not cause one API call per message.
 */
function crm_manager_monitor_provider_group_names(): array
{
    static $cached = null;

    if (is_array($cached)) {
        return $cached;
    }

    $cached = [];

    if (!function_exists('pilot_status_api_request') || !function_exists('pilot_status_settings')) {
        return $cached;
    }

    $settings = pilot_status_settings();

    if (trim((string) ($settings['api_key'] ?? '')) === '') {
        return $cached;
    }

    // The webhook validation may have opened a MySQL connection. Release it
    // before waiting on the provider, then crm_db() will reconnect when the
    // normalized message is persisted.
    if (function_exists('crm_db_release')) {
        crm_db_release();
    }

    $result = pilot_status_api_request('/groups', 'GET', null, 8);

    if (($result['ok'] ?? false) !== true) {
        if (function_exists('pilot_status_log')) {
            pilot_status_log('Não foi possível resolver nomes dos grupos na Pilot Status.', [
                'error' => (string) ($result['error'] ?? 'Resposta inválida.'),
            ]);
        }

        return $cached;
    }

    $response = $result['response'] ?? [];
    $rows = [];

    if (is_array($response) && is_array($response['groups'] ?? null)) {
        $rows = $response['groups'];
    } elseif (is_array($response) && is_array($response['data']['groups'] ?? null)) {
        $rows = $response['data']['groups'];
    } elseif (is_array($response) && array_is_list($response)) {
        $rows = $response;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $groupId = crm_manager_monitor_scalar_at($row, [
            ['id'],
            ['groupId'],
            ['group_id'],
        ]);
        $groupName = crm_manager_monitor_scalar_at($row, [
            ['name'],
            ['subject'],
            ['groupName'],
            ['group_name'],
        ]);

        if ($groupId === '' || $groupName === '') {
            continue;
        }

        $cached[crm_manager_monitor_limit($groupId, 255)] = crm_manager_monitor_limit($groupName, 255);
    }

    return $cached;
}

function crm_manager_monitor_enrich_group_names(array $messages): array
{
    $needsResolution = false;

    foreach ($messages as $message) {
        if ((string) ($message['group_name'] ?? '') === 'Grupo sem nome') {
            $needsResolution = true;
            break;
        }
    }

    if (!$needsResolution) {
        return $messages;
    }

    $providerNames = crm_manager_monitor_provider_group_names();

    if ($providerNames === []) {
        return $messages;
    }

    foreach ($messages as &$message) {
        if ((string) ($message['group_name'] ?? '') !== 'Grupo sem nome') {
            continue;
        }

        $groupId = (string) ($message['group_id'] ?? '');
        $resolvedName = trim((string) ($providerNames[$groupId] ?? ''));

        if ($resolvedName !== '') {
            $message['group_name'] = $resolvedName;
        }
    }

    unset($message);

    return $messages;
}

/**
 * Backfills names for groups that were stored before the provider lookup was
 * added. Returns the active PDO connection because the lookup releases the
 * old connection before waiting on the external API.
 */
function crm_manager_monitor_refresh_missing_group_names(PDO $pdo): PDO
{
    crm_manager_monitor_ensure_schema($pdo);

    $pendingQuery = $pdo->prepare(
        'SELECT id, external_group_id
         FROM manager_groups
         WHERE active = 1 AND name = :placeholder
         ORDER BY id ASC
         LIMIT 200'
    );
    $pendingQuery->execute(['placeholder' => 'Grupo sem nome']);
    $pendingGroups = $pendingQuery->fetchAll(PDO::FETCH_ASSOC);

    if ($pendingGroups === []) {
        return $pdo;
    }

    $providerNames = crm_manager_monitor_provider_group_names();

    if (function_exists('crm_db')) {
        $pdo = crm_db();
    }

    if ($providerNames === []) {
        return $pdo;
    }

    $update = $pdo->prepare(
        'UPDATE manager_groups
         SET name = :name, updated_at = :updated_at
         WHERE id = :id AND name = :placeholder'
    );
    $updatedAt = date('Y-m-d H:i:s');

    foreach ($pendingGroups as $group) {
        $externalGroupId = trim((string) ($group['external_group_id'] ?? ''));
        $name = trim((string) ($providerNames[$externalGroupId] ?? ''));

        if ($externalGroupId === '' || $name === '') {
            continue;
        }

        $update->execute([
            'name' => crm_manager_monitor_limit($name, 255),
            'updated_at' => $updatedAt,
            'id' => (int) ($group['id'] ?? 0),
            'placeholder' => 'Grupo sem nome',
        ]);
    }

    return $pdo;
}

function crm_manager_monitor_message_id(array $payload, string $groupId, array $participant, string $body, string $type): string
{
    $value = crm_manager_monitor_scalar_at($payload, [
        ['key', 'id'],
        ['id'],
        ['messageId'],
        ['message_id'],
        ['data', 'key', 'id'],
        ['data', 'id'],
        ['data', 'messageId'],
        ['data', 'message_id'],
        ['message', 'key', 'id'],
        ['message', 'id'],
    ]);

    if ($value !== '') {
        return crm_manager_monitor_limit($value, 255);
    }

    return 'hash:' . hash('sha256', implode('|', [
        $groupId,
        (string) ($participant['external_id'] ?? ''),
        crm_manager_monitor_message_timestamp($payload) ?? '',
        $type,
        $body,
        crm_manager_monitor_scalar_at($payload, [['media', 'id'], ['data', 'media', 'id'], ['message', 'media', 'id']]),
    ]));
}

function crm_manager_monitor_media_metadata(array $payload): ?string
{
    $media = pilot_status_extract_incoming_media($payload);

    if (!is_array($media) || $media === []) {
        return null;
    }

    $safe = [];

    foreach (['id', 'type', 'mime_type', 'filename', 'caption', 'temporary_url'] as $key) {
        if (!isset($media[$key]) || !is_scalar($media[$key])) {
            continue;
        }

        if (is_bool($media[$key])) {
            if ($media[$key] === true) {
                $safe[$key] = true;
            }

            continue;
        }

        $value = crm_manager_monitor_limit((string) $media[$key], 500);

        if ($value !== '') {
            $safe[$key] = $value;
        }
    }

    return $safe === [] ? null : json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function crm_manager_monitor_extract_group_messages(array $payload): array
{
    $messages = [];

    foreach (crm_manager_monitor_collect_items($payload) as $item) {
        $groupId = crm_manager_monitor_group_id($item);

        if ($groupId === '') {
            $groupId = crm_manager_monitor_group_id($payload);
        }

        if ($groupId === '') {
            continue;
        }

        $body = crm_manager_monitor_limit(pilot_status_extract_text($item), 20000);
        $type = crm_manager_monitor_limit(pilot_status_normalize_message_type(pilot_status_extract_message_type($item) ?: 'text'), 40) ?: 'text';
        $mediaMetadata = crm_manager_monitor_media_metadata($item);

        if ($body === '' && $mediaMetadata === null) {
            continue;
        }

        $participant = crm_manager_monitor_participant($item, $groupId);
        $messageId = crm_manager_monitor_message_id($item, $groupId, $participant, $body, $type);
        $encodedItem = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $messages[] = [
            'group_id' => $groupId,
            'group_name' => crm_manager_monitor_group_name($item) !== 'Grupo sem nome'
                ? crm_manager_monitor_group_name($item)
                : crm_manager_monitor_group_name($payload),
            'provider_number_id' => crm_manager_monitor_provider_number_id($item) !== 'pilot-status-default'
                ? crm_manager_monitor_provider_number_id($item)
                : crm_manager_monitor_provider_number_id($payload),
            'external_message_id' => $messageId,
            'source_payload_hash' => hash('sha256', is_string($encodedItem) ? $encodedItem : serialize($item)),
            'participant' => $participant,
            'sender_name' => crm_manager_monitor_limit(crm_manager_monitor_scalar_at($item, [
                ['participantName'],
                ['data', 'participantName'],
                ['pushName'],
                ['push_name'],
                ['sender', 'name'],
                ['data', 'sender', 'name'],
            ]), 180) ?: ($participant['name'] ?? null),
            'message_type' => $type,
            'body' => $body !== '' ? $body : null,
            'media_metadata' => $mediaMetadata,
            'reply_to_external_id' => crm_manager_monitor_limit(crm_manager_monitor_scalar_at($item, [
                ['contextInfo', 'stanzaId'],
                ['quotedMessageId'],
                ['replyToMessageId'],
                ['reply_to_message_id'],
                ['data', 'contextInfo', 'stanzaId'],
                ['data', 'quotedMessageId'],
            ]), 255) ?: null,
            'from_me' => pilot_status_payload_is_from_me($item) ? 1 : 0,
            'sent_at' => crm_manager_monitor_message_timestamp($item),
        ];
    }

    return crm_manager_monitor_enrich_group_names($messages);
}

function crm_manager_monitor_ingest_payload(array $payload): array
{
    $messages = crm_manager_monitor_extract_group_messages($payload);

    if ($messages === []) {
        return ['processed' => 0, 'duplicates' => 0, 'groups' => []];
    }

    $pdo = crm_db();
    crm_manager_monitor_ensure_schema($pdo);
    $now = date('Y-m-d H:i:s');
    $processed = 0;
    $duplicates = 0;
    $groups = [];

    $pdo->beginTransaction();

    try {
        $groupUpsert = $pdo->prepare(
            'INSERT INTO manager_groups
             (provider_number_id, external_group_id, name, last_message_at, created_at, updated_at)
             VALUES (:provider_number_id, :external_group_id, :name, :last_message_at, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                name = IF(VALUES(name) <> "Grupo sem nome", VALUES(name), name),
                last_message_at = CASE
                    WHEN VALUES(last_message_at) IS NULL THEN last_message_at
                    WHEN last_message_at IS NULL THEN VALUES(last_message_at)
                    WHEN VALUES(last_message_at) > last_message_at THEN VALUES(last_message_at)
                    ELSE last_message_at
                END,
                updated_at = VALUES(updated_at)'
        );
        $groupSelect = $pdo->prepare(
            'SELECT id FROM manager_groups
             WHERE provider_number_id = :provider_number_id AND external_group_id = :external_group_id
             LIMIT 1'
        );
        $participantUpsert = $pdo->prepare(
            'INSERT INTO manager_group_participants
             (group_id, external_participant_id, phone, display_name, first_seen_at, last_seen_at)
             VALUES (:group_id, :external_participant_id, :phone, :display_name, :first_seen_at, :last_seen_at)
             ON DUPLICATE KEY UPDATE
                phone = COALESCE(VALUES(phone), phone),
                display_name = COALESCE(VALUES(display_name), display_name),
                last_seen_at = VALUES(last_seen_at)'
        );
        $participantSelect = $pdo->prepare(
            'SELECT id FROM manager_group_participants
             WHERE group_id = :group_id AND external_participant_id = :external_participant_id
             LIMIT 1'
        );
        $messageInsert = $pdo->prepare(
            'INSERT IGNORE INTO manager_group_messages
             (group_id, participant_id, provider_number_id, external_message_id, source_payload_hash,
              sender_external_id, sender_phone, sender_name, message_type, body, media_metadata,
              reply_to_external_id, from_me, sent_at, received_at, analysis_status, created_at)
             VALUES
             (:group_id, :participant_id, :provider_number_id, :external_message_id, :source_payload_hash,
              :sender_external_id, :sender_phone, :sender_name, :message_type, :body, :media_metadata,
              :reply_to_external_id, :from_me, :sent_at, :received_at, "pending", :created_at)'
        );

        foreach ($messages as $message) {
            $groupUpsert->execute([
                'provider_number_id' => $message['provider_number_id'],
                'external_group_id' => $message['group_id'],
                'name' => $message['group_name'],
                'last_message_at' => $message['sent_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $groupSelect->execute([
                'provider_number_id' => $message['provider_number_id'],
                'external_group_id' => $message['group_id'],
            ]);
            $groupId = (int) $groupSelect->fetchColumn();

            if ($groupId <= 0) {
                throw new RuntimeException('Grupo não pôde ser identificado após o cadastro.');
            }

            $participantUpsert->execute([
                'group_id' => $groupId,
                'external_participant_id' => $message['participant']['external_id'],
                'phone' => $message['participant']['phone'],
                'display_name' => $message['sender_name'],
                'first_seen_at' => $message['sent_at'] ?? $now,
                'last_seen_at' => $now,
            ]);
            $participantSelect->execute([
                'group_id' => $groupId,
                'external_participant_id' => $message['participant']['external_id'],
            ]);
            $participantId = (int) $participantSelect->fetchColumn();

            $messageInsert->execute([
                'group_id' => $groupId,
                'participant_id' => $participantId > 0 ? $participantId : null,
                'provider_number_id' => $message['provider_number_id'],
                'external_message_id' => $message['external_message_id'],
                'source_payload_hash' => $message['source_payload_hash'],
                'sender_external_id' => $message['participant']['external_id'],
                'sender_phone' => $message['participant']['phone'],
                'sender_name' => $message['sender_name'],
                'message_type' => $message['message_type'],
                'body' => $message['body'],
                'media_metadata' => $message['media_metadata'],
                'reply_to_external_id' => $message['reply_to_external_id'],
                'from_me' => $message['from_me'],
                'sent_at' => $message['sent_at'],
                'received_at' => $now,
                'created_at' => $now,
            ]);

            if ($messageInsert->rowCount() === 0) {
                $duplicates++;
            } else {
                $processed++;
            }

            $groups[$message['group_id']] = $message['group_name'];
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    return [
        'processed' => $processed,
        'duplicates' => $duplicates,
        'groups' => $groups,
    ];
}

/**
 * Reads the normalized monitoring data for the manager dashboard. This query
 * deliberately works from the normalized tables only: the browser never
 * receives a provider payload or an API credential.
 */
function crm_manager_monitor_read_dashboard(PDO $pdo, string $search = ''): array
{
    crm_manager_monitor_ensure_schema($pdo);

    $search = crm_manager_monitor_limit($search, 120);
    $hasSearch = $search !== '';
    $searchLike = '%' . $search . '%';

    $clientSql = '
        SELECT
            c.id,
            c.name,
            c.external_ref,
            c.active,
            COUNT(DISTINCT g.id) AS group_count,
            COUNT(DISTINCT CASE WHEN a.status IN ("open", "acknowledged", "in_progress") THEN a.id END) AS open_alerts,
            COUNT(DISTINCT CASE WHEN a.status IN ("open", "acknowledged", "in_progress") AND a.severity = "critical" THEN a.id END) AS critical_alerts,
            COUNT(DISTINCT CASE WHEN a.status IN ("open", "acknowledged", "in_progress") AND a.severity IN ("high", "critical") THEN a.id END) AS priority_alerts,
            MAX(g.last_message_at) AS last_message_at
        FROM manager_clients c
        LEFT JOIN manager_groups g ON g.client_id = c.id AND g.active = 1
        LEFT JOIN manager_alerts a ON a.client_id = c.id
        WHERE c.active = 1
          AND (:has_search = 0 OR c.name LIKE :search_name OR c.external_ref LIKE :search_ref)
        GROUP BY c.id, c.name, c.external_ref, c.active
        ORDER BY priority_alerts DESC, critical_alerts DESC, open_alerts DESC, c.name ASC';
    $clientQuery = $pdo->prepare($clientSql);
    $clientQuery->bindValue(':has_search', $hasSearch ? 1 : 0, PDO::PARAM_INT);
    $clientQuery->bindValue(':search_name', $searchLike, PDO::PARAM_STR);
    $clientQuery->bindValue(':search_ref', $searchLike, PDO::PARAM_STR);
    $clientQuery->execute();
    $clients = $clientQuery->fetchAll(PDO::FETCH_ASSOC);

    $groupSql = '
        SELECT
            g.id,
            g.client_id,
            g.provider_number_id,
            g.external_group_id,
            g.name,
            g.last_message_at,
            c.name AS client_name,
            COUNT(DISTINCT m.id) AS message_count,
            SUM(CASE WHEN m.analysis_status IN ("pending", "processing") THEN 1 ELSE 0 END) AS pending_analysis,
            COUNT(DISTINCT CASE WHEN a.status IN ("open", "acknowledged", "in_progress") THEN a.id END) AS open_alerts,
            COUNT(DISTINCT CASE WHEN a.status IN ("open", "acknowledged", "in_progress") AND a.severity = "critical" THEN a.id END) AS critical_alerts,
            COUNT(DISTINCT CASE WHEN a.status IN ("open", "acknowledged", "in_progress") AND a.severity IN ("high", "critical") THEN a.id END) AS priority_alerts,
            (
                SELECT a2.id
                FROM manager_alerts a2
                WHERE a2.group_id = g.id
                  AND a2.status IN ("open", "acknowledged", "in_progress")
                ORDER BY FIELD(a2.severity, "critical", "high", "medium", "low"), a2.created_at DESC, a2.id DESC
                LIMIT 1
            ) AS latest_open_alert_id,
            (
                SELECT ma.status
                FROM manager_ai_analyses ma
                WHERE ma.group_id = g.id
                ORDER BY ma.created_at DESC, ma.id DESC
                LIMIT 1
            ) AS latest_analysis_status,
            (
                SELECT ma.severity
                FROM manager_ai_analyses ma
                WHERE ma.group_id = g.id
                ORDER BY ma.created_at DESC, ma.id DESC
                LIMIT 1
            ) AS latest_analysis_severity,
            (
                SELECT ma.summary
                FROM manager_ai_analyses ma
                WHERE ma.group_id = g.id AND ma.status = "completed"
                ORDER BY ma.created_at DESC, ma.id DESC
                LIMIT 1
            ) AS latest_ai_summary,
            (
                SELECT ma.created_at
                FROM manager_ai_analyses ma
                WHERE ma.group_id = g.id
                ORDER BY ma.created_at DESC, ma.id DESC
                LIMIT 1
            ) AS latest_analysis_at,
            (
                SELECT COALESCE(NULLIF(m2.body, ""), CONCAT("[", m2.message_type, "]"))
                FROM manager_group_messages m2
                WHERE m2.group_id = g.id
                ORDER BY COALESCE(m2.sent_at, m2.received_at) DESC, m2.id DESC
                LIMIT 1
            ) AS last_message_preview,
            (
                SELECT COALESCE(NULLIF(m3.sender_name, ""), NULLIF(m3.sender_phone, ""), "Participante")
                FROM manager_group_messages m3
                WHERE m3.group_id = g.id
                ORDER BY COALESCE(m3.sent_at, m3.received_at) DESC, m3.id DESC
                LIMIT 1
            ) AS last_sender_name
        FROM manager_groups g
        LEFT JOIN manager_clients c ON c.id = g.client_id
        LEFT JOIN manager_group_messages m ON m.group_id = g.id
        LEFT JOIN manager_alerts a ON a.group_id = g.id
        WHERE g.active = 1
          AND (
              :has_search = 0
              OR g.name LIKE :search_group
              OR c.name LIKE :search_client
              OR g.external_group_id LIKE :search_external
          )
        GROUP BY g.id, g.client_id, g.provider_number_id, g.external_group_id, g.name, g.last_message_at, g.created_at, c.name
        ORDER BY priority_alerts DESC, critical_alerts DESC, open_alerts DESC, COALESCE(g.last_message_at, g.created_at) DESC, g.name ASC';
    $groupQuery = $pdo->prepare($groupSql);
    $groupQuery->bindValue(':has_search', $hasSearch ? 1 : 0, PDO::PARAM_INT);
    $groupQuery->bindValue(':search_group', $searchLike, PDO::PARAM_STR);
    $groupQuery->bindValue(':search_client', $searchLike, PDO::PARAM_STR);
    $groupQuery->bindValue(':search_external', $searchLike, PDO::PARAM_STR);
    $groupQuery->execute();
    $groups = $groupQuery->fetchAll(PDO::FETCH_ASSOC);

    $statsQuery = $pdo->query('
        SELECT
            (SELECT COUNT(*) FROM manager_clients WHERE active = 1) AS clients,
            (SELECT COUNT(*) FROM manager_groups WHERE active = 1) AS groups,
            (SELECT COUNT(*) FROM manager_group_messages) AS messages,
            (SELECT COUNT(*)
             FROM manager_group_messages m
             INNER JOIN manager_groups g ON g.id = m.group_id
             WHERE m.analysis_status IN ("pending", "processing") AND g.active = 1 AND g.client_id IS NOT NULL) AS pending_analysis,
            (SELECT COUNT(*) FROM manager_alerts WHERE status IN ("open", "acknowledged", "in_progress")) AS open_alerts,
            (SELECT COUNT(*) FROM manager_alerts WHERE status IN ("open", "acknowledged", "in_progress") AND severity = "critical") AS critical_alerts,
            (SELECT COUNT(*) FROM manager_alerts WHERE status IN ("open", "acknowledged", "in_progress") AND severity IN ("high", "critical")) AS priority_alerts
    ');
    $stats = $statsQuery->fetch(PDO::FETCH_ASSOC) ?: [];

    $unassignedGroups = array_values(array_filter(
        $groups,
        static fn(array $group): bool => (int) ($group['client_id'] ?? 0) === 0
    ));

    return [
        'clients' => $clients,
        'groups' => $groups,
        'unassigned_groups' => $unassignedGroups,
        'stats' => [
            'clients' => (int) ($stats['clients'] ?? 0),
            'groups' => (int) ($stats['groups'] ?? 0),
            'messages' => (int) ($stats['messages'] ?? 0),
            'pending_analysis' => (int) ($stats['pending_analysis'] ?? 0),
            'open_alerts' => (int) ($stats['open_alerts'] ?? 0),
            'critical_alerts' => (int) ($stats['critical_alerts'] ?? 0),
            'priority_alerts' => (int) ($stats['priority_alerts'] ?? 0),
        ],
    ];
}

/**
 * Returns a compact version marker for the group inbox polling endpoint.
 * Message ingestion updates manager_groups.updated_at, so this detects new
 * messages, newly discovered groups and late name resolution without exposing
 * group content to the browser.
 */
function crm_manager_monitor_feed_version(PDO $pdo): string
{
    crm_manager_monitor_ensure_schema($pdo);
    $query = $pdo->query(
        'SELECT id, name, last_message_at, updated_at
         FROM manager_groups
         WHERE active = 1
         ORDER BY id ASC'
    );
    $rows = [];

    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $group) {
        $rows[] = [
            (int) ($group['id'] ?? 0),
            (string) ($group['name'] ?? ''),
            (string) ($group['last_message_at'] ?? ''),
            (string) ($group['updated_at'] ?? ''),
        ];
    }

    return hash(
        'sha256',
        json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
    );
}

function crm_manager_monitor_read_active_clients(PDO $pdo): array
{
    crm_manager_monitor_ensure_schema($pdo);
    $query = $pdo->query('SELECT id, name FROM manager_clients WHERE active = 1 ORDER BY name ASC');

    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function crm_manager_monitor_create_client(PDO $pdo, string $name, string $externalRef = ''): int
{
    crm_manager_monitor_ensure_schema($pdo);
    $name = crm_manager_monitor_limit($name, 180);
    $externalRef = crm_manager_monitor_limit($externalRef, 120);

    if ($name === '') {
        throw new InvalidArgumentException('Informe o nome do cliente.');
    }

    $now = date('Y-m-d H:i:s');
    $query = $pdo->prepare(
        'INSERT INTO manager_clients (name, external_ref, active, created_at, updated_at)
         VALUES (:name, :external_ref, 1, :created_at, :updated_at)'
    );
    $query->execute([
        'name' => $name,
        'external_ref' => $externalRef !== '' ? $externalRef : null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Creates the client and assigns a first WhatsApp group as one atomic action.
 * The dashboard uses this path so a client can never be left half-created if
 * the selected group disappears between the form submission and the update.
 */
function crm_manager_monitor_create_client_and_link_group(PDO $pdo, string $name, string $externalRef, int $groupId): int
{
    if ($groupId <= 0) {
        throw new InvalidArgumentException('Selecione um grupo válido.');
    }

    try {
        $pdo->beginTransaction();
        $clientId = crm_manager_monitor_create_client($pdo, $name, $externalRef);
        crm_manager_monitor_link_group_to_client($pdo, $groupId, $clientId);
        $pdo->commit();

        return $clientId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function crm_manager_monitor_link_group_to_client(PDO $pdo, int $groupId, int $clientId): void
{
    crm_manager_monitor_ensure_schema($pdo);

    if ($groupId <= 0 || $clientId <= 0) {
        throw new InvalidArgumentException('Selecione um grupo e um cliente válidos.');
    }

    $clientQuery = $pdo->prepare('SELECT id FROM manager_clients WHERE id = :id AND active = 1 LIMIT 1');
    $clientQuery->execute(['id' => $clientId]);

    if ((int) $clientQuery->fetchColumn() <= 0) {
        throw new InvalidArgumentException('Cliente não encontrado.');
    }

    $groupQuery = $pdo->prepare('UPDATE manager_groups SET client_id = :client_id, updated_at = :updated_at WHERE id = :id AND active = 1 AND client_id IS NULL');
    $groupQuery->execute([
        'client_id' => $clientId,
        'updated_at' => date('Y-m-d H:i:s'),
        'id' => $groupId,
    ]);

    if ($groupQuery->rowCount() === 0) {
        throw new InvalidArgumentException('Grupo não encontrado ou já está vinculado.');
    }
}

function crm_manager_monitor_read_group(PDO $pdo, int $groupId): ?array
{
    crm_manager_monitor_ensure_schema($pdo);
    $query = $pdo->prepare(
        'SELECT g.*, c.name AS client_name
         FROM manager_groups g
         LEFT JOIN manager_clients c ON c.id = g.client_id
         WHERE g.id = :id AND g.active = 1
         LIMIT 1'
    );
    $query->execute(['id' => $groupId]);
    $group = $query->fetch(PDO::FETCH_ASSOC);

    return is_array($group) ? $group : null;
}

function crm_manager_monitor_read_group_messages(PDO $pdo, int $groupId, int $limit = 150): array
{
    crm_manager_monitor_ensure_schema($pdo);
    $limit = max(20, min($limit, 500));
    $query = $pdo->prepare(
        'SELECT id, sender_name, sender_phone, message_type, body, media_metadata, from_me, sent_at, received_at, analysis_status
         FROM manager_group_messages
         WHERE group_id = :group_id
         ORDER BY COALESCE(sent_at, received_at) DESC, id DESC
         LIMIT ' . $limit
    );
    $query->execute(['group_id' => $groupId]);
    $messages = $query->fetchAll(PDO::FETCH_ASSOC);

    return array_reverse($messages);
}

function crm_manager_monitor_read_group_alerts(PDO $pdo, int $groupId): array
{
    crm_manager_monitor_ensure_schema($pdo);
    $query = $pdo->prepare(
        'SELECT id, alert_type, severity, status, title, description, evidence, confidence, resolved_at, resolution_note, created_at, updated_at
         FROM manager_alerts
         WHERE group_id = :group_id
         ORDER BY FIELD(status, "open", "acknowledged", "in_progress", "resolved"), created_at DESC
         LIMIT 100'
    );
    $query->execute(['group_id' => $groupId]);

    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function crm_manager_monitor_read_group_resolutions(PDO $pdo, int $groupId, int $limit = 5): array
{
    crm_manager_monitor_ensure_schema($pdo);
    $limit = max(1, min($limit, 20));
    $query = $pdo->prepare(
        'SELECT id, title, description, evidence, resolution_note, resolved_at
         FROM manager_alerts
         WHERE group_id = :group_id
           AND alert_type = "ai_group_status"
           AND status = "resolved"
           AND resolution_note IS NOT NULL
           AND resolution_note <> ""
         ORDER BY resolved_at DESC, id DESC
         LIMIT ' . $limit
    );
    $query->execute(['group_id' => $groupId]);

    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function crm_manager_monitor_resolve_alert(PDO $pdo, int $alertId, ?int $userId = null, string $resolutionNote = ''): array
{
    crm_manager_monitor_ensure_schema($pdo);

    if ($alertId <= 0) {
        throw new InvalidArgumentException('Alerta inválido.');
    }

    $query = $pdo->prepare(
        'SELECT id, group_id, status
         FROM manager_alerts
         WHERE id = :id AND alert_type = "ai_group_status"
         LIMIT 1'
    );
    $query->execute(['id' => $alertId]);
    $alert = $query->fetch(PDO::FETCH_ASSOC);

    if (!is_array($alert)) {
        throw new InvalidArgumentException('Alerta não encontrado.');
    }

    $status = (string) ($alert['status'] ?? '');

    if ($status === 'resolved') {
        return [
            'alert_id' => (int) $alert['id'],
            'group_id' => (int) $alert['group_id'],
            'already_resolved' => true,
        ];
    }

    if (!in_array($status, ['open', 'acknowledged', 'in_progress'], true)) {
        throw new InvalidArgumentException('Este alerta não pode ser resolvido neste estado.');
    }

    $resolutionNote = crm_manager_monitor_limit($resolutionNote, 2000);
    $resolutionNote = $resolutionNote !== '' ? $resolutionNote : 'Resolvido pelo gestor.';
    $now = date('Y-m-d H:i:s');
    $update = $pdo->prepare(
        'UPDATE manager_alerts
         SET status = "resolved",
             resolved_by_user_id = :resolved_by_user_id,
             resolved_at = :resolved_at,
             resolution_note = :resolution_note,
             updated_at = :updated_at
         WHERE id = :id
           AND status IN ("open", "acknowledged", "in_progress")'
    );
    $update->execute([
        'resolved_by_user_id' => $userId !== null && $userId > 0 ? $userId : null,
        'resolved_at' => $now,
        'resolution_note' => $resolutionNote,
        'updated_at' => $now,
        'id' => $alertId,
    ]);

    if ($update->rowCount() === 0) {
        throw new InvalidArgumentException('O alerta já foi resolvido por outro gestor.');
    }

    $touch = $pdo->prepare('UPDATE manager_groups SET updated_at = :updated_at WHERE id = :id');
    $touch->execute([
        'updated_at' => $now,
        'id' => (int) $alert['group_id'],
    ]);

    return [
        'alert_id' => $alertId,
        'group_id' => (int) $alert['group_id'],
        'resolution_note' => $resolutionNote,
        'already_resolved' => false,
    ];
}

function crm_manager_monitor_read_latest_group_analysis(PDO $pdo, int $groupId): ?array
{
    crm_manager_monitor_ensure_schema($pdo);
    $query = $pdo->prepare(
        'SELECT id, group_id, message_id, model, prompt_version, status, severity,
                categories, summary, evidence, confidence, result_json, error_message, created_at
         FROM manager_ai_analyses
         WHERE group_id = :group_id
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $query->execute(['group_id' => $groupId]);
    $analysis = $query->fetch(PDO::FETCH_ASSOC);

    if (!is_array($analysis)) {
        return null;
    }

    foreach (['categories', 'evidence', 'result_json'] as $key) {
        $decoded = json_decode((string) ($analysis[$key] ?? ''), true);
        $analysis[$key . '_decoded'] = is_array($decoded) ? $decoded : [];
    }

    return $analysis;
}
