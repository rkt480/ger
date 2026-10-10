<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-ads.php';

set_time_limit(0);

try {
    $pdo = crm_db();
    crm_manager_ads_ensure_schema($pdo);
    $clients = $pdo->query(
        'SELECT id, name FROM manager_clients
         WHERE active = 1
           AND (COALESCE(meta_ads_account_ids, "") <> "" OR COALESCE(google_ads_customer_ids, "") <> "")
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $yesterday = new DateTimeImmutable('yesterday');
    $dateFrom = $yesterday->modify('-6 days')->format('Y-m-d');
    $dateTo = $yesterday->format('Y-m-d');
    $monthReportDue = date('j') === '1';
    $monthEnd = (new DateTimeImmutable('first day of this month'))->modify('-1 day');
    $monthFrom = $monthEnd->modify('first day of this month')->format('Y-m-d');
    $monthTo = $monthEnd->format('Y-m-d');
    $results = [];

    foreach ($clients as $client) {
        $clientId = (int) ($client['id'] ?? 0);

        if ($clientId <= 0) {
            continue;
        }

        try {
            $results[] = crm_manager_ads_run_client_report(crm_db(), $clientId, $dateFrom, $dateTo, true);

            if ($monthReportDue) {
                $results[] = crm_manager_ads_run_client_report(crm_db(), $clientId, $monthFrom, $monthTo, true);
            }
        } catch (Throwable $error) {
            $results[] = [
                'client_id' => $clientId,
                'client' => (string) ($client['name'] ?? ''),
                'ok' => false,
                'error' => $error->getMessage(),
            ];
            error_log('Relatório automático de anúncios falhou para o cliente ' . $clientId . ': ' . $error->getMessage());
        }
    }

    echo json_encode([
        'ok' => true,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'monthly_report_due' => $monthReportDue,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    error_log('Worker de análise de anúncios falhou: ' . $error->getMessage());
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
