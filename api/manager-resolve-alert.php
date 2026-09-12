<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';

crm_require_sales_manager();
crm_send_security_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

crm_require_valid_csrf();

$alertId = filter_var($_POST['alert_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$rawNote = $_POST['resolution_note'] ?? '';
$resolutionNote = is_scalar($rawNote) ? (string) $rawNote : '';
$currentUser = crm_current_user();
$userId = is_array($currentUser) && (int) ($currentUser['id'] ?? 0) > 0
    ? (int) $currentUser['id']
    : null;

try {
    if (!is_int($alertId) || $alertId <= 0) {
        throw new InvalidArgumentException('Alerta inválido.');
    }

    $result = crm_manager_monitor_resolve_alert(crm_db(), $alertId, $userId, $resolutionNote);

    echo json_encode([
        'ok' => true,
        ...$result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Erro ao resolver alerta do monitor: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível marcar o alerta como resolvido.'], JSON_UNESCAPED_UNICODE);
}
