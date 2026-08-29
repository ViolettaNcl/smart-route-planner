<?php

declare(strict_types=1);

/**
 * Dedicated Vercel entry point for the main route calculation.
 *
 * The web UI calls /api/route.php. Vercel rewrites that one URL directly to
 * this function, bypassing the generic API dispatcher. This avoids any
 * ambiguity around rewrite query parameters while keeping the remaining API
 * endpoints behind api/index.php, so the Hobby function count stays low.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (function_exists('set_time_limit')) {
    @set_time_limit(25);
}

header('X-Smart-Route-Direct: 1');

// Keep accidental PHP warnings/notices out of the JSON body. If a fatal error
// occurs, replace buffered output with a small valid JSON response so the
// frontend does not mistake an HTML/runtime error page for a network failure.
ob_start();
$completed = false;

register_shutdown_function(static function () use (&$completed): void {
    if ($completed) {
        return;
    }

    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error === null || !in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_log('[smart-route-planner route-direct fatal] ' . ($error['message'] ?? 'unknown fatal error'));

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Smart-Route-Direct: 1');
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Внутренняя ошибка сервера. Попробуйте позже.',
        'error_code' => 'INTERNAL_ERROR',
    ], JSON_UNESCAPED_UNICODE);
});

try {
    require dirname(__DIR__) . '/public/api/route.php';
    $completed = true;

    if (ob_get_level() > 0) {
        ob_end_flush();
    }
} catch (\Throwable $e) {
    $completed = true;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_log('[smart-route-planner route-direct] ' . $e->getMessage());

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Smart-Route-Direct: 1');
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Внутренняя ошибка сервера. Попробуйте позже.',
        'error_code' => 'INTERNAL_ERROR',
    ], JSON_UNESCAPED_UNICODE);
}
