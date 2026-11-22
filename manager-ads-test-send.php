<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-ads-comparison.php';

crm_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

crm_require_valid_csrf();

$clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$clientId = is_int($clientId) ? $clientId : 0;
$action = is_scalar($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
$periodChoice = is_scalar($_POST['period'] ?? null) ? (string) $_POST['period'] : 'last7';
$status = 'invalid';
$detail = '';

try {
    $pdo = crm_db();
    crm_manager_ads_ensure_schema($pdo);
    $query = $pdo->prepare('SELECT id, name, manager_user_id, ads_report_test_enabled FROM manager_clients WHERE id = :id AND active = 1 LIMIT 1');
    $query->execute(['id' => $clientId]);
    $client = $query->fetch(PDO::FETCH_ASSOC);

    if (!is_array($client)) {
        throw new InvalidArgumentException('Cliente não encontrado.');
    }

    if ($action === 'enable' || $action === 'disable') {
        $enabled = $action === 'enable' ? 1 : 0;
        $update = $pdo->prepare('UPDATE manager_clients SET ads_report_test_enabled = :enabled WHERE id = :id AND active = 1');
        $update->execute(['enabled' => $enabled, 'id' => $clientId]);
        $status = $enabled ? 'enabled' : 'disabled';
    } elseif ($action === 'send' && (int) $client['ads_report_test_enabled'] === 1) {
        [$dateFrom, $dateTo] = crm_manager_ads_selected_period($periodChoice);
        set_time_limit(240);
        $comparison = crm_manager_ads_build_comparison($pdo, $clientId, $dateTo, true);
        $pdo = crm_db();
        $report = [
            'metrics' => crm_manager_ads_read_metrics($pdo, $clientId, $dateFrom, $dateTo),
            'comparison' => $comparison,
        ];
        $result = crm_manager_ads_send_scheduled_report($pdo, $client, $report, $dateFrom, $dateTo, true);
        $status = !empty($result['sent']) ? 'sent' : 'failed';

        if ($status === 'failed') {
            $detail = crm_manager_monitor_limit((string) ($result['error'] ?? 'O provedor não confirmou o envio.'), 300);
            error_log('Falha no envio de teste do relatório de anúncios para o cliente ' . $clientId . ': ' . (string) ($result['error'] ?? 'erro desconhecido'));
        }
    }
} catch (Throwable $error) {
    error_log('Erro no envio de teste do relatório de anúncios para o cliente ' . $clientId . ': ' . $error->getMessage());
    $status = 'failed';
    $detail = crm_manager_monitor_limit($error->getMessage(), 300);
}

$_SESSION['manager_ads_test_feedback'] = ['client_id' => $clientId, 'status' => $status, 'detail' => $detail];
header('Location: manager-dashboard.php?ads_test=' . $status . '&client_id=' . $clientId . '#manager-campaign-reports');
exit;
