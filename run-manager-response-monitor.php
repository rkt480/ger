<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-response-monitor.php';
try {
    echo json_encode(['ok' => true] + crm_manager_response_run(crm_db()), JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    error_log('Acompanhamento de respostas falhou: ' . $error->getMessage());
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
