<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/manager-ai.php';

crm_require_sales_manager();
crm_send_security_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

crm_require_valid_csrf();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

set_time_limit(0);

$groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$limit = filter_var($_POST['limit'] ?? 2, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);

try {
    $pdo = crm_db();
    $result = is_int($groupId) && $groupId > 0
        ? crm_manager_ai_process_group($pdo, $groupId)
        : crm_manager_ai_process_pending($pdo, is_int($limit) ? $limit : 2);

    echo json_encode([
        'ok' => true,
        ...$result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
