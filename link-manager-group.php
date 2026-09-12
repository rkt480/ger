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

try {
    crm_manager_monitor_link_group_to_client(
        crm_db(),
        (int) ($_POST['group_id'] ?? 0),
        (int) ($_POST['client_id'] ?? 0)
    );
    header('Location: manager-dashboard.php?saved=group');
} catch (InvalidArgumentException $error) {
    header('Location: manager-dashboard.php?error=invalid_group');
} catch (Throwable $error) {
    error_log('Erro ao vincular grupo do monitor: ' . $error->getMessage());
    header('Location: manager-dashboard.php?error=link_group');
}

exit;
