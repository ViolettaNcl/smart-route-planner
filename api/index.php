<?php

/**
 * Single Vercel PHP front controller.
 *
 * All public API endpoints are routed through this one function so the
 * Vercel Hobby deployment stays well below the Serverless Function limit.
 * Endpoint implementations live outside any /api directory to prevent
 * Vercel from auto-detecting each implementation file as a separate function.
 */

$projectRoot = dirname(__DIR__);
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');

$metaDocument = $_GET['meta'] ?? '';
$metaDocument = is_string($metaDocument) ? trim($metaDocument) : '';
if (in_array($metaDocument, ['robots', 'sitemap'], true)) {
    require_once $projectRoot . '/bootstrap.php';
    $publicUrl = \App\Support\PublicUrl::resolve();

    if ($metaDocument === 'robots') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\n\nSitemap: {$publicUrl}/sitemap.xml\n";
        return;
    }

    header('Content-Type: application/xml; charset=utf-8');
    $location = htmlspecialchars($publicUrl . '/', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . "  <url><loc>{$location}</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>\n"
        . '</urlset>' . "\n";
    return;
}

if (($_GET['home'] ?? null) === '1' || $requestPath === '/') {
    require $projectRoot . '/public/index.php';
    return;
}

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
    'model_insights',
    'model_quality',
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
    require $projectRoot . '/server/endpoints/' . $requestedEndpoint . '.php';
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
