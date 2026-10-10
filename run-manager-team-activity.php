<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-ai.php';

set_time_limit(0);

try {
    $result = crm_manager_ai_run_daily_team_activity(crm_db());
    echo json_encode(['ok' => true, ...$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    error_log('Worker diário de acompanhamento da equipe falhou: ' . $error->getMessage());
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
