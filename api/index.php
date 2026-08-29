<?php

/**
 * Single Vercel PHP front controller.
 *
 * The Hobby plan allows at most 12 Serverless Functions per deployment, so
 * every public API endpoint is routed through this one function. The actual
 * endpoint scripts remain in public/api/ and still work unchanged on Apache,
 * XAMPP, Docker, and PHP's built-in server.
 */

$projectRoot = dirname(__DIR__);
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');

// Vercel normally passes ?home=1 from vercel.json. Keep a REQUEST_URI fallback
// as well, because some runtime/router combinations do not expose rewrite query
// parameters exactly like a normal web server would.
if (($_GET['home'] ?? null) === '1' || $requestPath === '/') {
    require $projectRoot . '/public/index.php';
    return;
}

// Primary dispatch comes from ?endpoint=$1 in vercel.json. If that value is
// missing, derive the endpoint from /api/<name>.php so API requests still work
// instead of falling through to a Vercel/HTML 404 response that the frontend
// cannot parse as JSON.
$requestedEndpoint = $_GET['endpoint'] ?? '';
$requestedEndpoint = is_string($requestedEndpoint) ? trim($requestedEndpoint) : '';

if ($requestedEndpoint === '' && str_starts_with($requestPath, '/api/')) {
    $requestedEndpoint = substr($requestPath, strlen('/api/'));
}

$requestedEndpoint = preg_replace('/\.php$/', '', basename($requestedEndpoint)) ?? '';

$endpoints = [
    'ab_stats',
    'assistant',
    'day_plan',
    'decision_boundary',
    'explain',
    'feedback',
    'health',
    'learn',
    'poi',
    'reset_model',
    'route',
    'suggest',
    'weather',
];

header('X-Smart-Route-Endpoint: ' . ($requestedEndpoint !== '' ? $requestedEndpoint : 'unknown'));

if (!in_array($requestedEndpoint, $endpoints, true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'API endpoint not found.',
        'error_code' => 'NOT_FOUND',
        'path' => $requestPath,
    ], JSON_UNESCAPED_UNICODE);
    return;
}

try {
    require $projectRoot . '/public/api/' . $requestedEndpoint . '.php';
} catch (\Throwable $e) {
    error_log('[smart-route-planner front controller] ' . $requestedEndpoint . ': ' . $e->getMessage());

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Внутренняя ошибка сервера. Попробуйте позже.',
        'error_code' => 'INTERNAL_ERROR',
    ], JSON_UNESCAPED_UNICODE);
}
