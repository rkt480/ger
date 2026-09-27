<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

crm_require_valid_csrf();

$clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$managerUserId = filter_var($_POST['manager_user_id'] ?? null, FILTER_VALIDATE_INT);
$clientId = is_int($clientId) ? $clientId : 0;
$managerUserId = is_int($managerUserId) && $managerUserId > 0 ? $managerUserId : null;

try {
    crm_manager_monitor_assign_client_to_manager(crm_db(), $clientId, $managerUserId);
    header('Location: manager-dashboard.php?saved=manager');
} catch (InvalidArgumentException $error) {
    header('Location: manager-dashboard.php?error=' . ($clientId > 0 ? 'invalid_manager' : 'invalid_client'));
} catch (Throwable $error) {
    error_log('Erro ao vincular gestor ao cliente do monitor: ' . $error->getMessage());
    header('Location: manager-dashboard.php?error=assign_manager');
}

exit;
