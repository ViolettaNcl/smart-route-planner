<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Geocoding\FileCache;
use App\Geocoding\NominatimGeocoder;
use App\Http\RateLimitGuard;
use App\Http\RateLimiter;
use App\ML\TransportPredictor;
use App\RoutePlanner;
use App\Routing\CostEstimator;
use App\Routing\HaversineCalculator;
use App\Routing\OsrmRoadRouter;
use App\Routing\RouteOptimizer;
use App\Support\Logger;
use App\Support\RuntimeStorage;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.', 'error_code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

RateLimitGuard::enforce('route', capacity: 20, refillSeconds: 60);

$rawPoints = $_POST['points'] ?? '';
$stopsJson = $_POST['stops_json'] ?? '';
$structuredStops = null;

if (is_string($stopsJson) && trim($stopsJson) !== '') {
    if (strlen($stopsJson) > 32768) {
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'error' => 'Список точек превышает допустимый размер.',
            'error_code' => 'PAYLOAD_TOO_LARGE',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = json_decode($stopsJson, true, 64);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'Список точек имеет неверный формат.',
            'error_code' => 'INVALID_STOPS',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $structuredStops = $decoded;
}

if ($structuredStops === null && (!is_string($rawPoints) || trim($rawPoints) === '')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите хотя бы две точки.', 'error_code' => 'EMPTY_POINTS'], JSON_UNESCAPED_UNICODE);
    exit;
}

$costParams = [
    'fuel_price_per_liter' => $_POST['fuel_price_per_liter'] ?? null,
    'fuel_consumption_l_100km' => $_POST['fuel_consumption_l_100km'] ?? null,
    'ticket_price_per_km' => $_POST['ticket_price_per_km'] ?? null,
    'ticket_base_fare' => $_POST['ticket_base_fare'] ?? null,
];

$optimizeValue = strtolower(trim((string) ($_POST['optimize_order'] ?? '1')));
$optimizeOrder = !in_array($optimizeValue, ['0', 'false', 'off', 'no'], true);
$modelVariant = $_POST['model_variant'] ?? null;
$modelVariant = in_array($modelVariant, ['mlp', 'softmax'], true) ? $modelVariant : null;

$logger = new Logger(RuntimeStorage::path('app.log'));
$startedAt = microtime(true);
$requestId = bin2hex(random_bytes(6));
$inputCount = $structuredStops !== null
    ? count($structuredStops)
    : count(array_filter(array_map('trim', explode(';', (string) $rawPoints))));

$logger->info('route_request_started', [
    'request_id' => $requestId,
    'stop_count' => $inputCount,
    'structured_input' => $structuredStops !== null,
    'optimize_order' => $optimizeOrder,
]);

try {
    $calculator = new HaversineCalculator();
    $planner = new RoutePlanner(
        geocoder: new NominatimGeocoder(new FileCache(RuntimeStorage::path('geocache'))),
        calculator: $calculator,
        optimizer: new RouteOptimizer($calculator),
        predictor: new TransportPredictor(
            RuntimeStorage::modelWeightsPath(),
            __DIR__ . '/../../src/ML/model_weights.json',
            $modelVariant
        ),
        roadRouter: new OsrmRoadRouter(
            cache: new FileCache(RuntimeStorage::path('routecache')),
            publicEndpointLimiter: new RateLimiter(
                RuntimeStorage::path('ratelimit/osrm_upstreams.json'),
                capacity: 1,
                refillSeconds: 1,
            ),
        ),
        costEstimator: new CostEstimator(),
        logger: $logger,
    );

    $result = $structuredStops !== null
        ? $planner->planStops($structuredStops, $costParams, $optimizeOrder)
        : $planner->plan((string) $rawPoints, $costParams);
    $result['request_id'] = $requestId;

    $logger->info('route_request_finished', [
        'request_id' => $requestId,
        'ok' => (bool) ($result['ok'] ?? false),
        'status' => ($result['ok'] ?? false) ? 200 : 422,
        'duration_ms' => round((microtime(true) - $startedAt) * 1000),
        'resolved_stop_count' => (int) ($result['stops'] ?? 0),
        'skipped_count' => count($result['skipped'] ?? []),
        'routing_provider' => $result['routing_provider'] ?? null,
        'route_option_count' => count($result['route_options'] ?? []),
    ]);

    http_response_code(($result['ok'] ?? false) ? 200 : 422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $exception) {
    $logger->error('route_request_failed', [
        'request_id' => $requestId,
        'duration_ms' => round((microtime(true) - $startedAt) * 1000),
        'exception' => get_class($exception),
    ]);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Внутренняя ошибка сервера. Попробуйте позже.',
        'error_code' => 'INTERNAL_ERROR',
        'request_id' => $requestId,
    ], JSON_UNESCAPED_UNICODE);
}
