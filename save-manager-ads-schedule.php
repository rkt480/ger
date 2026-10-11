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
$enabled = isset($_POST['enabled']) && is_scalar($_POST['enabled']) && (string) $_POST['enabled'] === '1';
$frequency = is_scalar($_POST['frequency'] ?? null) ? (string) $_POST['frequency'] : '';
$weekday = filter_var($_POST['weekday'] ?? null, FILTER_VALIDATE_INT);
$monthDay = filter_var($_POST['month_day'] ?? null, FILTER_VALIDATE_INT);
$time = is_scalar($_POST['time'] ?? null) ? (string) $_POST['time'] : '';
$pdo = crm_db();

try {
    crm_manager_ads_ensure_schema($pdo);

    if ($clientId <= 0
        || !in_array($frequency, ['daily', 'weekly', 'monthly'], true)
        || !is_int($weekday) || $weekday < 1 || $weekday > 7
        || !is_int($monthDay) || $monthDay < 1 || $monthDay > 28
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new InvalidArgumentException('Configuração de programação inválida.');
    }

    $clientQuery = $pdo->prepare('SELECT manager_user_id FROM manager_clients WHERE id = :id AND active = 1 LIMIT 1');
    $clientQuery->execute(['id' => $clientId]);
    $managerUserId = $clientQuery->fetchColumn();

    if ($managerUserId === false || ($enabled && (int) $managerUserId <= 0)) {
        throw new InvalidArgumentException('Vincule um gestor ativo ao cliente antes de ativar o envio.');
    }

    $update = $pdo->prepare(
        'UPDATE manager_clients
         SET ads_report_schedule_enabled = :enabled,
             ads_report_schedule_frequency = :frequency,
             ads_report_schedule_weekday = :weekday,
             ads_report_schedule_day = :month_day,
             ads_report_schedule_time = :schedule_time,
             updated_at = :updated_at
         WHERE id = :id AND active = 1'
    );
    $update->execute([
        'enabled' => $enabled ? 1 : 0,
        'frequency' => $frequency,
        'weekday' => $weekday,
        'month_day' => $monthDay,
        'schedule_time' => $time . ':00',
        'updated_at' => date('Y-m-d H:i:s'),
        'id' => $clientId,
    ]);
    header('Location: manager-dashboard.php?saved=ads_schedule');
} catch (InvalidArgumentException $error) {
    header('Location: manager-dashboard.php?error=invalid_ads_schedule');
} catch (Throwable $error) {
    error_log('Erro ao salvar a programação de relatório de anúncios: ' . $error->getMessage());
    header('Location: manager-dashboard.php?error=save_ads_schedule');
}

exit;
