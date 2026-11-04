<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-monitor.php';

crm_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

crm_require_valid_csrf();

$clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$clientId = is_int($clientId) ? $clientId : 0;

try {
    crm_manager_monitor_delete_client(crm_db(), $clientId);
    header('Location: manager-dashboard.php?saved=client_deleted');
} catch (InvalidArgumentException $error) {
    header('Location: manager-dashboard.php?error=delete_client_not_found');
} catch (Throwable $error) {
    error_log('Erro ao excluir carteira monitorada: ' . $error->getMessage());
    header('Location: manager-dashboard.php?error=delete_client');
}

exit;
