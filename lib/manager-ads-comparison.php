<?php

declare(strict_types=1);

require_once __DIR__ . '/manager-ads.php';

function crm_manager_ads_comparison_schema(PDO $pdo): void
{
    crm_manager_ads_ensure_schema($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_ads_comparisons (
        client_id BIGINT UNSIGNED NOT NULL, date_to DATE NOT NULL,
        comparison_json LONGTEXT NOT NULL, history_status TEXT NOT NULL,
        updated_at DATETIME NOT NULL, PRIMARY KEY (client_id, date_to),
        FOREIGN KEY (client_id) REFERENCES manager_clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function crm_manager_ads_fetch_history(string $platform, string $accountId, string $dateFrom, string $dateTo, array $settings, ?string $googleToken): array
{
    if ($platform === 'meta') {
        $token = trim((string) ($settings['meta_ads_access_token'] ?? ''));
        $version = ltrim(trim((string) ($settings['meta_ads_api_version'] ?? '')), 'v');
        if ($token === '' || !preg_match('/^\d{1,2}\.\d$/', $version)) throw new RuntimeException('Meta Ads não configurado.');
        $query = ['fields' => 'event_time,event_type,object_id,object_name,actor_name',
            'since' => strtotime($dateFrom . ' 00:00:00'), 'until' => strtotime($dateTo . ' 23:59:59'), 'limit' => 100];
        $secret = trim((string) ($settings['meta_ads_app_secret'] ?? ''));
        if ($secret !== '') $query['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        $url = 'https://graph.facebook.com/v' . $version . '/act_' . rawurlencode($accountId) . '/activities?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $events = [];
        for ($page = 0; $page < 10 && $url !== ''; $page++) {
            $response = crm_manager_ads_http($url, ['Authorization: Bearer ' . $token]);
            foreach (($response['data'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $events[] = ['platform' => 'meta', 'account_id' => $accountId,
                    'at' => (string) ($row['event_time'] ?? ''), 'type' => (string) ($row['event_type'] ?? ''),
                    'campaign_id' => (string) ($row['object_id'] ?? ''),
                    'description' => crm_manager_monitor_limit((string) ($row['object_name'] ?? ''), 200),
                    'author' => 'Autor não confirmado'];
            }
            $next = (string) ($response['paging']['next'] ?? '');
            $url = str_starts_with($next, 'https://graph.facebook.com/') ? $next : '';
        }
        return $events;
    }
    if ($googleToken === null) throw new RuntimeException('Google Ads não configurado.');
    $developerToken = trim((string) ($settings['google_ads_developer_token'] ?? ''));
    if ($developerToken === '') throw new RuntimeException('Developer Token ausente.');
    $customerId = preg_replace('/\D+/', '', $accountId) ?? '';
    $query = "SELECT change_event.resource_name, change_event.change_date_time, change_event.campaign, change_event.change_resource_type, change_event.resource_change_operation FROM change_event WHERE change_event.change_date_time >= '" . $dateFrom . " 00:00:00' AND change_event.change_date_time <= '" . $dateTo . " 23:59:59' ORDER BY change_event.change_date_time DESC LIMIT 1000";
    $headers = ['Authorization: Bearer ' . $googleToken, 'developer-token: ' . $developerToken, 'Content-Type: application/json'];
    $login = preg_replace('/\D+/', '', (string) ($settings['google_ads_login_customer_id'] ?? '')) ?? '';
    if ($login !== '') $headers[] = 'login-customer-id: ' . $login;
    $response = crm_manager_ads_http('https://googleads.googleapis.com/v25/customers/' . rawurlencode($customerId) . '/googleAds:searchStream',
        $headers, json_encode(['query' => $query], JSON_THROW_ON_ERROR), 'POST');
    $events = [];
    foreach ((array_is_list($response) ? $response : [$response]) as $batch) {
        foreach (($batch['results'] ?? []) as $row) {
            $event = $row['changeEvent'] ?? [];
            $campaign = (string) ($event['campaign'] ?? '');
            $events[] = ['platform' => 'google', 'account_id' => $accountId,
                'at' => (string) ($event['changeDateTime'] ?? ''),
                'type' => (string) ($event['resourceChangeOperation'] ?? ''),
                'campaign_id' => basename($campaign),
                'description' => crm_manager_monitor_limit((string) ($event['changeResourceType'] ?? ''), 200),
                'author' => 'Autor não confirmado'];
        }
    }
    return $events;
}

function crm_manager_ads_build_comparison(PDO $pdo, int $clientId, string $dateTo, bool $sync = true): array
{
    crm_manager_ads_comparison_schema($pdo);
    $end = new DateTimeImmutable($dateTo);
    $first = $end->modify('-27 days')->format('Y-m-d');
    $clientQuery = $pdo->prepare('SELECT meta_ads_account_ids, google_ads_customer_ids FROM manager_clients WHERE id = :id');
    $clientQuery->execute(['id' => $clientId]);
    $client = $clientQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) throw new InvalidArgumentException('Cliente não encontrado.');
    $syncErrors = [];
    if ($sync) {
        $syncResult = crm_manager_ads_sync_client($pdo, $clientId, $first, $dateTo);
        if ((int) ($syncResult['accounts_failed'] ?? 0) > 0) {
            throw new RuntimeException('Algumas contas não puderam ser atualizadas: ' . implode(' · ', $syncResult['errors'] ?? []));
        }
        $pdo = crm_db();
    }
    $campaigns = [];
    foreach ([7, 14, 28] as $days) {
        $from = $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        foreach (crm_manager_ads_read_metrics($pdo, $clientId, $from, $dateTo) as $metric) {
            $key = implode(':', [(string) $metric['platform'], (string) $metric['account_id'], (string) $metric['campaign_id'], (string) ($metric['currency'] ?? '')]);
            if (!isset($campaigns[$key])) $campaigns[$key] = ['platform' => $metric['platform'], 'account_id' => $metric['account_id'],
                'campaign_id' => $metric['campaign_id'], 'campaign_name' => $metric['campaign_name'],
                'currency' => $metric['currency'], 'result_type' => $metric['result_type'], 'periods' => []];
            $campaigns[$key]['periods'][$days] = ['from' => $from, 'to' => $dateTo,
                'impressions' => (int) $metric['impressions'], 'clicks' => (int) $metric['clicks'],
                'spend' => (float) $metric['spend'], 'results' => $metric['result_count'] === null ? null : (float) $metric['result_count'],
                'ctr' => $metric['ctr'] === null ? null : (float) $metric['ctr']];
        }
    }
    $settings = crm_read_settings();
    $history = [];
    $historyStatus = $syncErrors;
    $googleToken = null;
    if (trim((string) ($client['google_ads_customer_ids'] ?? '')) !== '') {
        try { $googleToken = crm_manager_ads_google_access_token($settings); }
        catch (Throwable $error) { $historyStatus[] = 'Google Ads: ' . $error->getMessage(); }
    }
    foreach (['meta' => 'meta_ads_account_ids', 'google' => 'google_ads_customer_ids'] as $platform => $field) {
        foreach (crm_manager_ads_account_ids((string) ($client[$field] ?? '')) as $accountId) {
            try { $history = array_merge($history, crm_manager_ads_fetch_history($platform, $accountId, $first, $dateTo, $settings, $googleToken)); }
            catch (Throwable $error) { $historyStatus[] = strtoupper($platform) . ' ' . $accountId . ': ' . $error->getMessage(); }
        }
    }
    $pdo = crm_db();
    foreach ($history as $event) {
        foreach ($campaigns as &$campaign) {
            if ($campaign['platform'] === $event['platform'] && $campaign['account_id'] === $event['account_id']
                && $campaign['campaign_id'] === $event['campaign_id']) {
                $campaign['changes'][] = $event;
            }
        }
        unset($campaign);
    }
    $weekly = [];
    foreach (['current' => [$end->modify('-6 days')->format('Y-m-d'), $dateTo],
              'previous' => [$end->modify('-13 days')->format('Y-m-d'), $end->modify('-7 days')->format('Y-m-d')]] as $week => [$from, $to]) {
        foreach (crm_manager_ads_read_metrics($pdo, $clientId, $from, $to) as $metric) {
            $key = implode(':', [(string) $metric['platform'], (string) $metric['account_id'], (string) $metric['campaign_id'], (string) ($metric['currency'] ?? '')]);
            if (!isset($weekly[$key])) $weekly[$key] = [
                'campaign_name' => $metric['campaign_name'], 'platform' => $metric['platform'],
                'account_name' => $metric['account_name'], 'currency' => $metric['currency'],
                'result_type' => $metric['result_type'], 'current' => null, 'previous' => null,
            ];
            $weekly[$key][$week] = [
                'spend' => (float) $metric['spend'], 'impressions' => (int) $metric['impressions'],
                'clicks' => (int) $metric['clicks'],
                'results' => $metric['result_count'] === null ? null : (float) $metric['result_count'],
            ];
        }
    }
    $aiSummary = '';
    if ($history === [] && $historyStatus === []) $historyStatus[] = 'Nenhuma alteração retornada pelas contas no período.';
    $data = ['campaigns' => array_values($campaigns), 'history' => array_slice($history, 0, 300),
        'history_status' => $historyStatus, 'ai_summary' => $aiSummary, 'weekly' => array_values($weekly), 'date_to' => $dateTo];
    $save = $pdo->prepare('INSERT INTO manager_ads_comparisons (client_id, date_to, comparison_json, history_status, updated_at)
        VALUES (:client_id, :date_to, :comparison_json, :history_status, :updated_at)
        ON DUPLICATE KEY UPDATE comparison_json = VALUES(comparison_json), history_status = VALUES(history_status), updated_at = VALUES(updated_at)');
    $save->execute(['client_id' => $clientId, 'date_to' => $dateTo,
        'comparison_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'history_status' => implode(' · ', $historyStatus), 'updated_at' => date('Y-m-d H:i:s')]);
    return $data;
}

function crm_manager_ads_comparison_messages(array $comparison): array
{
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($comparison['date_to'] ?? ''));
    $currentPeriod = $end ? $end->modify('-6 days')->format('d/m') . ' a ' . $end->format('d/m') : 'últimos 7 dias';
    $previousPeriod = $end ? $end->modify('-13 days')->format('d/m') . ' a ' . $end->modify('-7 days')->format('d/m') : '7 dias anteriores';
    $heading = "📈 Resumo comparativo\n" . $currentPeriod . ' x ' . $previousPeriod;
    $weekly = (array) ($comparison['weekly'] ?? []);
    if ($weekly === []) return [$heading . "\nDados insuficientes para comparar as campanhas."];
    $messages = [];
    $currentMessage = $heading;
    foreach ($weekly as $campaign) {
        if (!is_array($campaign)) continue;
        $account = trim((string) ($campaign['account_name'] ?? ''));
        $name = trim((string) ($campaign['campaign_name'] ?? 'Campanha'));
        $platform = strtoupper((string) ($campaign['platform'] ?? ''));
        $currency = strtoupper(trim((string) ($campaign['currency'] ?? '')));
        $formatMoney = static fn(?float $value): string => $value === null ? 'sem dados' : ($currency === 'BRL' ? 'R$ ' : ($currency !== '' ? $currency . ' ' : '')) . number_format($value, 2, ',', '.');
        $formatNumber = static fn(?float $value): string => $value === null ? 'sem dados' : number_format($value, 2, ',', '.');
        $lines = [];
        foreach (['current' => $currentPeriod, 'previous' => $previousPeriod] as $key => $label) {
            $week = $campaign[$key] ?? null;
            if (!is_array($week)) { $lines[] = $label . ': sem dados'; continue; }
            $spend = (float) ($week['spend'] ?? 0);
            $impressions = (int) ($week['impressions'] ?? 0);
            $clicks = (int) ($week['clicks'] ?? 0);
            $results = $week['results'] === null ? null : (float) $week['results'];
            $cost = $results !== null && $results > 0 ? $spend / $results : null;
            $cpm = $impressions > 0 ? $spend / $impressions * 1000 : null;
            $ctr = $impressions > 0 ? $clicks / $impressions * 100 : null;
            $lines[] = $label . ': custo/resultado ' . $formatMoney($cost)
                . ' · investimento ' . $formatMoney($spend)
                . ' · CPM ' . $formatMoney($cpm)
                . ' · CTR ' . ($ctr === null ? 'sem dados' : $formatNumber($ctr) . '%');
        }
        $block = "\n\n📊 " . $name . ($account !== '' ? ' · ' . $account : '') . ' · ' . $platform . "\n" . implode("\n", $lines);
        if ((function_exists('mb_strlen') ? mb_strlen($currentMessage . $block, 'UTF-8') : strlen($currentMessage . $block)) > 3000 && $currentMessage !== $heading) {
            $messages[] = $currentMessage;
            $currentMessage = $heading;
        }
        $currentMessage .= $block;
    }
    $messages[] = $currentMessage;
    return $messages;
}
