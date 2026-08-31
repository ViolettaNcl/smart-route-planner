<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Http\RateLimitGuard;
use App\ML\Dataset;
use App\ML\FeedbackStore;
use App\ML\TransportPredictor;
use App\Support\RuntimeStorage;

/**
 * Privacy-safe correction queue. A public request never mutates shared model
 * weights. Corrections are anonymised and later reviewed as a batch by the
 * CLI release gate before any candidate can be promoted.
 *
 * POST /api/learn.php
 * body: distance_km, stops, correct_label (walk|car|bus), model_variant,
 *       event_id
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

RateLimitGuard::enforce('learn', capacity: 6, refillSeconds: 60);

$distanceKm = isset($_POST['distance_km']) && is_numeric($_POST['distance_km']) ? (float) $_POST['distance_km'] : null;
$stops = isset($_POST['stops']) && is_numeric($_POST['stops']) ? (int) $_POST['stops'] : null;
$correctLabel = $_POST['correct_label'] ?? null;
$modelVariant = $_POST['model_variant'] ?? null;
$eventId = is_string($_POST['event_id'] ?? null) ? trim($_POST['event_id']) : '';

if (
    $distanceKm === null
    || $distanceKm < 0.2
    || $distanceKm > 1500
    || $stops === null
    || $stops < 2
    || $stops > 12
    || !in_array($correctLabel, Dataset::CLASSES, true)
    || !in_array($modelVariant, ['mlp', 'softmax'], true)
    || preg_match('/^[a-zA-Z0-9._:-]{4,128}$/', $eventId) !== 1
) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Нужны distance_km, stops, correct_label, model_variant и корректный event_id.',
    ]);
    exit;
}

$mlpWeightsPath = RuntimeStorage::modelWeightsPath();

try {
    $predictor = new TransportPredictor(
        $mlpWeightsPath,
        __DIR__ . '/../../src/ML/model_weights.json',
        (string) $modelVariant
    );

    $prediction = $predictor->predict($distanceKm, $stops);
    if ($prediction['mode'] === $correctLabel) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'Для подтверждения текущего прогноза используйте оценку A/B; исправление должно менять класс.',
            'error_code' => 'NO_CORRECTION',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $queue = new FeedbackStore(RuntimeStorage::path('ml_feedback.ndjson'));
    $result = $queue->enqueue(
        $distanceKm,
        $stops,
        (string) $correctLabel,
        $prediction['mode'],
        $predictor->modelVersion(),
        $eventId
    );

    echo json_encode([
        'ok' => true,
        'applied' => false,
        'queued' => true,
        'duplicate' => $result['duplicate'],
        'queue_size' => $result['queue_size'],
        'prediction' => $prediction,
        'release_policy' => 'batch_review_then_holdout_gate',
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось сохранить исправление.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] learn.php: ' . $e->getMessage());
}
