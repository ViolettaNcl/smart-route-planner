<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Weather\OpenMeteoClient;
use App\Http\RateLimitGuard;

/**
 * Погода по точкам маршрута (Open-Meteo, без ключа).
 *
 * POST /api/weather.php
 * body: points = JSON-массив [{ "lat": 48.7, "lon": 44.5, "label": "Волгоград" }, ...]
 *
 * Отдельный эндпоинт (а не часть route.php) специально: расчёт маршрута не
 * должен ждать/падать из-за погодного API — фронтенд запрашивает погоду
 * отдельным вызовом уже после того, как маршрут посчитан и показан.
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

RateLimitGuard::enforce('weather', capacity: 20, refillSeconds: 60);

$pointsRaw = $_POST['points'] ?? '';
$points = is_string($pointsRaw) ? json_decode($pointsRaw, true) : null;

if (!is_array($points) || count($points) === 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ожидается непустой JSON-массив точек в поле points.']);
    exit;
}

// Не даём случайно запросить погоду для сотни точек за один вызов —
// разумный потолок для маршрута.
$points = array_slice($points, 0, 15);

try {
    $client = new OpenMeteoClient();
    $forecast = $client->forecastForPoints($points);

    echo json_encode(['ok' => true, 'forecast' => $forecast], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось получить прогноз погоды.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] weather.php: ' . $e->getMessage());
}
