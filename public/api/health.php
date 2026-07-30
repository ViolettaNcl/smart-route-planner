<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

/**
 * Health-check эндпоинт: не диагностика на все случаи жизни, а быстрая
 * проверка, что процесс жив и минимально дееспособен — без сети наружу
 * (Nominatim/OSRM намеренно не дёргаем, иначе health-check сам стал бы
 * зависеть от чужого аптайма).
 *
 * GET /api/health.php
 *
 * 200 — всё в порядке, годится для UptimeRobot/аптайм-мониторинга и для
 *       HEALTHCHECK-директивы в Docker.
 * 503 — что-то не так (веса модели повреждены/отсутствуют, var/ не
 *       доступна на запись) — сигнал для оркестратора перезапустить
 *       контейнер или для алерта, что деплой прошёл не полностью.
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте GET.']);
    exit;
}

$checks = [];

// 1) Веса модели читаются и это валидный JSON — без них TransportPredictor
//    не сможет ничего предсказать.
$weightsPath = __DIR__ . '/../../src/ML/mlp_weights.json';
$fallbackWeightsPath = __DIR__ . '/../../src/ML/model_weights.json';
$weightsOk = false;
foreach ([$weightsPath, $fallbackWeightsPath] as $path) {
    if (is_file($path) && is_readable($path)) {
        $raw = file_get_contents($path);
        if ($raw !== false && json_decode($raw, true) !== null) {
            $weightsOk = true;
            break;
        }
    }
}
$checks['model_weights'] = $weightsOk;

// 2) var/ доступна на запись — там живут geocode-кэш, rate limiter и
//    A/B-статистика; без записи туда приложение всё ещё отвечает на
//    статические запросы, но деградирует, поэтому это тоже часть health-check.
$checks['var_writable'] = is_dir(__DIR__ . '/../../var') && is_writable(__DIR__ . '/../../var');

$healthy = !in_array(false, $checks, true);

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'ok' => $healthy,
    'checks' => $checks,
    'php_version' => PHP_VERSION,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE);
