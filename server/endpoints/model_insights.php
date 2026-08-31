<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Http\RateLimitGuard;
use App\ML\ModelInsightService;
use App\Support\RuntimeStorage;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается.', 'error_code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

RateLimitGuard::enforce('model_insights', capacity: 60, refillSeconds: 60);

$distanceKm = isset($_GET['distance_km']) && is_numeric($_GET['distance_km']) ? (float) $_GET['distance_km'] : null;
$stops = isset($_GET['stops']) && is_numeric($_GET['stops']) ? (int) $_GET['stops'] : null;
$priority = is_string($_GET['priority'] ?? null) ? (string) $_GET['priority'] : 'balanced';
$model = ($_GET['model'] ?? 'mlp') === 'softmax' ? 'softmax' : 'mlp';

if ($distanceKm === null || $distanceKm < 0.2 || $distanceKm > 1500 || $stops === null || $stops < 2 || $stops > 12) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Нужны distance_km (0.2–1500) и stops (2–12).',
        'error_code' => 'INVALID_MODEL_INPUT',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new ModelInsightService(
        RuntimeStorage::modelWeightsPath(),
        __DIR__ . '/../../src/ML/model_weights.json'
    );
    echo json_encode([
        'ok' => true,
        'insight' => $service->analyze($distanceKm, $stops, $priority, $model),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Не удалось построить объяснение модели.',
        'error_code' => 'INTERNAL_ERROR',
    ], JSON_UNESCAPED_UNICODE);
    error_log('[smart-route-planner] model_insights.php: ' . $e->getMessage());
}
