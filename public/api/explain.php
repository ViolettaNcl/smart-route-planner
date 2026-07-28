<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\ML\TransportPredictor;

/**
 * "Почему такой транспорт?" — разбор одного предсказания модели по числам
 * (см. App\ML\MLPClassifier::explain / SoftmaxClassifier::explain).
 *
 * GET /api/explain.php?distance_km=738&stops=4
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте GET.']);
    exit;
}

$distanceKm = isset($_GET['distance_km']) && is_numeric($_GET['distance_km']) ? (float) $_GET['distance_km'] : null;
$stops = isset($_GET['stops']) && is_numeric($_GET['stops']) ? (int) $_GET['stops'] : null;

if ($distanceKm === null || $stops === null || $distanceKm < 0 || $stops < 2) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Нужны параметры distance_km (>=0) и stops (>=2).']);
    exit;
}

try {
    $predictor = new TransportPredictor(
        __DIR__ . '/../../src/ML/mlp_weights.json',
        __DIR__ . '/../../src/ML/model_weights.json'
    );

    $explanation = $predictor->explain($distanceKm, $stops);

    echo json_encode([
        'ok' => true,
        'model' => $predictor->modelType(),
        'explanation' => $explanation,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось построить объяснение предсказания.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] explain.php: ' . $e->getMessage());
}
