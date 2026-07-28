<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Geocoding\FileCache;
use App\Geocoding\NominatimGeocoder;
use App\ML\TransportPredictor;
use App\Routing\CostEstimator;
use App\Routing\HaversineCalculator;
use App\Routing\OsrmRoadRouter;
use App\Routing\RouteOptimizer;
use App\RoutePlanner;
use App\Http\RateLimitGuard;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.', 'error_code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Самый "дорогой" по внешним вызовам эндпоинт — геокодирует каждый город
// через Nominatim и строит маршрут через OSRM. Лимит щедрый (обычный
// сценарий использования — несколько расчётов подряд), но защищает оба
// бесплатных сервиса от шквала запросов с одного IP.
RateLimitGuard::enforce('route', capacity: 20, refillSeconds: 60);

$rawPoints = $_POST['points'] ?? '';

if (!is_string($rawPoints) || trim($rawPoints) === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите хотя бы два города через «;».', 'error_code' => 'EMPTY_POINTS']);
    exit;
}

// Необязательные параметры расчёта стоимости поездки — если пользователь их
// не указал (или указал некорректно), CostEstimator подставит дефолты сам.
$costParams = [
    'fuel_price_per_liter' => $_POST['fuel_price_per_liter'] ?? null,
    'fuel_consumption_l_100km' => $_POST['fuel_consumption_l_100km'] ?? null,
    'ticket_price_per_km' => $_POST['ticket_price_per_km'] ?? null,
    'ticket_base_fare' => $_POST['ticket_base_fare'] ?? null,
];

$mlpWeightsPath = __DIR__ . '/../../src/ML/mlp_weights.json';
$softmaxWeightsPath = __DIR__ . '/../../src/ML/model_weights.json';

// A/B-тест (см. App\ML\ABTestStats, api/feedback.php, api/ab_stats.php):
// фронтенд присылает вариант, назначенный этому визиту в localStorage.
// Некорректное/отсутствующее значение — не ошибка, просто используем
// поведение по умолчанию (мягкий приоритет MLP).
$modelVariant = $_POST['model_variant'] ?? null;
$modelVariant = in_array($modelVariant, ['mlp', 'softmax'], true) ? $modelVariant : null;

try {
    $planner = new RoutePlanner(
        geocoder: new NominatimGeocoder(new FileCache(__DIR__ . '/../../var/geocache')),
        calculator: $calculator = new HaversineCalculator(),
        optimizer: new RouteOptimizer($calculator),
        predictor: new TransportPredictor($mlpWeightsPath, $softmaxWeightsPath, $modelVariant),
        roadRouter: new OsrmRoadRouter(),
        costEstimator: new CostEstimator(),
    );

    $result = $planner->plan($rawPoints, $costParams);

    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Внутренняя ошибка сервера. Попробуйте позже.',
        'error_code' => 'INTERNAL_ERROR',
        // В проде подробности исключения в ответ не отдаём — только логируем.
    ]);
    error_log('[smart-route-planner] ' . $e->getMessage());
}
