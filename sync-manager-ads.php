<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-ads.php';

crm_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

crm_require_valid_csrf();

$clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$clientId = is_int($clientId) ? $clientId : 0;
$period = trim((string) ($_POST['period'] ?? 'last7'));

try {
    if ($clientId <= 0) {
        throw new InvalidArgumentException('Selecione um cliente válido.');
    }

    $yesterday = new DateTimeImmutable('yesterday');

    if ($period === 'month_to_date') {
        $dateFrom = $yesterday->modify('first day of this month')->format('Y-m-d');
        $dateTo = $yesterday->format('Y-m-d');

        if ($dateFrom > $dateTo) {
            throw new InvalidArgumentException('Ainda não há dias encerrados no mês atual. Escolha o mês anterior.');
        }
    } elseif ($period === 'previous_month') {
        $previousMonth = (new DateTimeImmutable('first day of this month'))->modify('-1 day');
        $dateFrom = $previousMonth->modify('first day of this month')->format('Y-m-d');
        $dateTo = $previousMonth->format('Y-m-d');
    } elseif ($period === 'last7') {
        $dateFrom = $yesterday->modify('-6 days')->format('Y-m-d');
        $dateTo = $yesterday->format('Y-m-d');
    } else {
        throw new InvalidArgumentException('Selecione um período de relatório válido.');
    }

    set_time_limit(240);
    crm_manager_ads_run_client_report(crm_db(), $clientId, $dateFrom, $dateTo, true);
    header('Location: manager-dashboard.php?saved=ads_report&client_id=' . $clientId . '#manager-campaign-reports');
} catch (InvalidArgumentException $error) {
    header('Location: manager-dashboard.php?error=ads_period&client_id=' . $clientId . '#manager-campaign-reports');
} catch (Throwable $error) {
    error_log('Falha ao sincronizar anúncios e gerar relatório: ' . $error->getMessage());
    header('Location: manager-dashboard.php?error=ads_sync&client_id=' . $clientId . '#manager-campaign-reports');
}

exit;
