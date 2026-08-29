<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\ML\KMeansDaySplitter;
use App\Routing\HaversineCalculator;

/**
 * "AI-планировщик поездки по дням": разбивает уже посчитанный маршрут на
 * сбалансированные дни вождения с помощью K-Means (см. App\ML\KMeansDaySplitter
 * — unsupervised-кластеризация, написанная с нуля, без внешних ML-библиотек).
 *
 * POST /api/day_plan.php
 * body: points = JSON-массив ТОЧНО В ПОРЯДКЕ МАРШРУТА
 *                [{ "lat": 48.7, "lon": 44.5, "label": "Волгоград" }, ...]
 *       days    = желаемое число дней (необязательно — если не задано,
 *                 сервер сам предложит разумное количество через
 *                 KMeansDaySplitter::suggestDays())
 *
 * Отдельный эндпоинт (как weather.php/poi.php/assistant.php): основной
 * расчёт маршрута не должен ждать эту фичу — фронтенд запрашивает план по
 * дням отдельным вызовом по кнопке, уже после того, как маршрут посчитан.
 *
 * Дистанции между точками здесь считаются заново по Haversine (прямая
 * линия) из присланных координат — того же порядка точности достаточно для
 * планирования дней (нам важны ОТНОСИТЕЛЬНЫЕ пропорции перегонов, а не
 * метр в метр реальная дорога).
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.', 'error_code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$pointsRaw = $_POST['points'] ?? '';
$points = is_string($pointsRaw) ? json_decode($pointsRaw, true) : null;

if (!is_array($points) || count($points) < 2) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Ожидается JSON-массив минимум из 2 точек маршрута (в порядке следования) в поле points.',
        'error_code' => 'MIN_TWO_POINTS',
    ]);
    exit;
}

foreach ($points as $p) {
    if (!isset($p['lat'], $p['lon'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Каждая точка должна содержать lat и lon.']);
        exit;
    }
}

$requestedDays = isset($_POST['days']) && is_numeric($_POST['days']) ? (int) $_POST['days'] : null;

try {
    $calculator = new HaversineCalculator();

    // Дистанция каждого перегона маршрута (между соседними точками, в порядке следования).
    $legs = [];
    for ($i = 0; $i < count($points) - 1; $i++) {
        $legs[] = $calculator->distanceKm(
            ['lat' => (float) $points[$i]['lat'], 'lon' => (float) $points[$i]['lon']],
            ['lat' => (float) $points[$i + 1]['lat'], 'lon' => (float) $points[$i + 1]['lon']],
        );
    }

    $splitter = new KMeansDaySplitter();
    $totalDistance = array_sum($legs);

    $days = $requestedDays !== null && $requestedDays >= 1
        ? $requestedDays
        : $splitter->suggestDays($totalDistance);

    $plan = $splitter->splitIntoDays($legs, $days);

    // Разворачиваем индексы перегонов обратно в реальные точки/города для
    // фронтенда — ему не нужно знать про внутреннее представление algorithm'а.
    $labeledPlan = array_map(function (array $day) use ($points) {
        $fromPoint = $points[$day['leg_start']];
        $toPoint = $points[$day['leg_end'] + 1];

        $waypoints = [];
        for ($i = $day['leg_start']; $i <= $day['leg_end'] + 1; $i++) {
            $waypoints[] = $points[$i]['label'] ?? null;
        }

        return [
            'day' => $day['day'],
            'from' => $fromPoint['label'] ?? null,
            'to' => $toPoint['label'] ?? null,
            'waypoints' => $waypoints,
            'distance_km' => $day['distance_km'],
        ];
    }, $plan);

    echo json_encode([
        'ok' => true,
        'total_distance_km' => round($totalDistance, 1),
        'days_requested' => $days,
        'days' => $labeledPlan,
        'algorithm' => 'kmeans_1d',
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось построить план по дням.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] day_plan.php: ' . $e->getMessage());
}
