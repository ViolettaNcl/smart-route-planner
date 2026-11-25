<?php

/**
 * Router for PHP's built-in server used by HTTP integration tests.
 *
 * Mirrors Apache's production routing through api/index.php.
 */

$projectRoot = dirname(__DIR__, 2);
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if (preg_match('#^/api/([A-Za-z_]+)\.php$#', $requestPath, $matches) === 1) {
    $_GET['endpoint'] = $matches[1];
    require $projectRoot . '/api/index.php';
    return true;
}

$publicFile = $projectRoot . '/public' . $requestPath;
if ($requestPath !== '/' && is_file($publicFile)) {
    return false;
}

if ($requestPath === '/') {
    require $projectRoot . '/public/index.php';
    return true;
}

return false;
