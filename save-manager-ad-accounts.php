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
$clientId = is_int($clientId) ? $clientId : 0;

try {
    $metaAccountIds = $_POST['meta_ads_account_ids'] ?? '';
    $googleCustomerIds = $_POST['google_ads_customer_ids'] ?? '';

    crm_manager_monitor_save_ad_accounts(
        crm_db(),
        $clientId,
        is_scalar($metaAccountIds) ? (string) $metaAccountIds : '',
        is_scalar($googleCustomerIds) ? (string) $googleCustomerIds : ''
    );
    header('Location: manager-dashboard.php?saved=ad_accounts');
} catch (InvalidArgumentException $error) {
    header('Location: manager-dashboard.php?error=invalid_ad_accounts');
} catch (Throwable $error) {
    error_log('Erro ao salvar as contas de anúncios do cliente: ' . $error->getMessage());
    header('Location: manager-dashboard.php?error=save_ad_accounts');
}

exit;
