<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-ads.php';

set_time_limit(0);

/** Return the calendar date for a schedule occurrence that is due now. */
function crm_manager_ads_due_occurrence(array $client, DateTimeImmutable $now): ?string
{
    $frequency = (string) ($client['ads_report_schedule_frequency'] ?? 'weekly');
    $scheduleTime = substr((string) ($client['ads_report_schedule_time'] ?? '08:00:00'), 0, 5);

    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $scheduleTime)) {
        return null;
    }

    $today = $now->setTime(0, 0);
    $todayIsDue = $now->format('H:i') >= $scheduleTime;

    if ($frequency === 'daily') {
        return $todayIsDue ? $today->format('Y-m-d') : null;
    }

    if ($frequency === 'weekly') {
        $weekday = max(1, min(7, (int) ($client['ads_report_schedule_weekday'] ?? 1)));
        $monday = $today->modify('monday this week');
        $occurrence = $monday->modify('+' . ($weekday - 1) . ' days');

        if ($occurrence > $today || ($occurrence == $today && !$todayIsDue)) {
            return null;
        }

        return $occurrence->format('Y-m-d');
    }

    if ($frequency === 'monthly') {
        $day = max(1, min(28, (int) ($client['ads_report_schedule_day'] ?? 1)));
        $occurrence = $today->modify('first day of this month')->modify('+' . ($day - 1) . ' days');

        if ($occurrence > $today || ($occurrence == $today && !$todayIsDue)) {
            return null;
        }

        return $occurrence->format('Y-m-d');
    }

    return null;
}

/** The report period is anchored to its scheduled date, including catch-up runs. */
function crm_manager_ads_scheduled_period(string $frequency, string $occurrenceDate): array
{
    $timezone = new DateTimeZone(date_default_timezone_get());
    $occurrence = DateTimeImmutable::createFromFormat('!Y-m-d', $occurrenceDate, $timezone);

    if (!$occurrence || $occurrence->format('Y-m-d') !== $occurrenceDate) {
        throw new InvalidArgumentException('A data de execução da programação é inválida.');
    }

    if ($frequency === 'daily') {
        $date = $occurrence->modify('-1 day')->format('Y-m-d');
        return [$date, $date];
    }

    if ($frequency === 'weekly') {
        $dateTo = $occurrence->modify('-1 day');
        return [$dateTo->modify('-6 days')->format('Y-m-d'), $dateTo->format('Y-m-d')];
    }

    if ($frequency === 'monthly') {
        $lastMonth = $occurrence->modify('first day of this month')->modify('-1 day');
        return [$lastMonth->modify('first day of this month')->format('Y-m-d'), $lastMonth->format('Y-m-d')];
    }

    throw new InvalidArgumentException('Frequência de programação inválida.');
}

try {
    $pdo = crm_db();
    crm_manager_ads_ensure_schema($pdo);
    $clients = $pdo->query(
        'SELECT id, name, manager_user_id, ads_report_schedule_frequency,
                ads_report_schedule_weekday, ads_report_schedule_day, ads_report_schedule_time
         FROM manager_clients
         WHERE active = 1 AND ads_report_schedule_enabled = 1
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $results = [];

    foreach ($clients as $client) {
        $clientId = (int) ($client['id'] ?? 0);
        $frequency = (string) ($client['ads_report_schedule_frequency'] ?? '');
        $occurrenceDate = crm_manager_ads_due_occurrence($client, $now);

        if ($clientId <= 0 || $occurrenceDate === null) {
            continue;
        }

        $claimed = false;

        try {
            [$dateFrom, $dateTo] = crm_manager_ads_scheduled_period($frequency, $occurrenceDate);
            $attempt = crm_manager_ads_claim_scheduled_delivery(crm_db(), $clientId, $occurrenceDate, $frequency, $dateFrom, $dateTo);

            if ($attempt === 0) {
                $results[] = [
                    'client_id' => $clientId,
                    'occurrence_date' => $occurrenceDate,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'sent' => false,
                    'skipped' => true,
                    'reason' => 'A ocorrência já foi processada e não há uma nova entrega elegível no momento.',
                ];
                continue;
            }

            $claimed = true;

            $report = $attempt > 1 ? crm_manager_ads_read_saved_report(crm_db(), $clientId, $dateFrom, $dateTo) : null;

            if ($report === null) {
                $run = crm_manager_ads_run_client_report(crm_db(), $clientId, $dateFrom, $dateTo, true);
                $report = $run['report'];
            }

            $delivery = crm_manager_ads_send_scheduled_report(crm_db(), $client, $report, $dateFrom, $dateTo);
            crm_manager_ads_finish_scheduled_delivery(crm_db(), $clientId, $occurrenceDate, $delivery['sent'], (string) ($delivery['error'] ?? ''));
            $results[] = [
                'client_id' => $clientId,
                'occurrence_date' => $occurrenceDate,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sent' => $delivery['sent'],
                'error' => $delivery['error'] ?? '',
            ];
        } catch (Throwable $error) {
            if ($claimed) {
                crm_manager_ads_finish_scheduled_delivery(crm_db(), $clientId, $occurrenceDate, false, $error->getMessage());
            }
            $results[] = [
                'client_id' => $clientId,
                'occurrence_date' => $occurrenceDate,
                'sent' => false,
                'error' => crm_manager_monitor_limit($error->getMessage(), 500),
            ];
            error_log('Relatório programado de anúncios falhou para o cliente ' . $clientId . ': ' . $error->getMessage());
        }
    }

    echo json_encode(['ok' => true, 'checked_at' => $now->format(DATE_ATOM), 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    error_log('Worker de relatórios programados de anúncios falhou: ' . $error->getMessage());
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
