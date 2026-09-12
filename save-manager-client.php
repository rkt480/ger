<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';

crm_require_sales_manager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

crm_require_valid_csrf();

$groupId = 0;

try {
    $pdo = crm_db();
    $rawGroupId = $_POST['group_id'] ?? null;
    $groupId = is_scalar($rawGroupId) ? filter_var($rawGroupId, FILTER_VALIDATE_INT) : false;
    $groupId = is_int($groupId) && $groupId > 0 ? $groupId : 0;
    $rawName = $_POST['name'] ?? '';
    $rawExternalRef = $_POST['external_ref'] ?? '';
    $name = is_scalar($rawName) ? (string) $rawName : '';
    $externalRef = is_scalar($rawExternalRef) ? (string) $rawExternalRef : '';

    if ($groupId > 0) {
        crm_manager_monitor_create_client_and_link_group($pdo, $name, $externalRef, $groupId);
        header('Location: manager-dashboard.php?saved=client_group');
    } else {
        crm_manager_monitor_create_client($pdo, $name, $externalRef);
        header('Location: manager-dashboard.php?saved=client');
    }
} catch (InvalidArgumentException $error) {
    header('Location: manager-dashboard.php?error=' . ($groupId > 0 ? 'invalid_client_group' : 'invalid_client'));
} catch (Throwable $error) {
    error_log('Erro ao cadastrar cliente do monitor: ' . $error->getMessage());
    header('Location: manager-dashboard.php?error=' . ($groupId > 0 ? 'save_client_group' : 'save_client'));
}

exit;
