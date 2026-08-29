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

if (($_GET['home'] ?? null) === '1') {
    require $projectRoot . '/public/index.php';
    return;
}

$requestedEndpoint = $_GET['endpoint'] ?? '';
$requestedEndpoint = is_string($requestedEndpoint) ? $requestedEndpoint : '';
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

if (!in_array($requestedEndpoint, $endpoints, true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'API endpoint not found.',
        'error_code' => 'NOT_FOUND',
    ], JSON_UNESCAPED_UNICODE);
    return;
}

require $projectRoot . '/public/api/' . $requestedEndpoint . '.php';
