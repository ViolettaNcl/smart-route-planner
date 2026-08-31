<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Http\RateLimitGuard;
use App\ML\TransportPredictor;
use App\Support\RuntimeStorage;

/**
 * Administrative rollback to the checked-in reference weights. Public
 * feedback never reaches this code path and never mutates shared weights.
 *
 * POST /api/reset_model.php with X-Model-Admin-Token.
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

RateLimitGuard::enforce('reset_model', capacity: 3, refillSeconds: 300);

$configuredToken = trim((string) (getenv('APP_MODEL_ADMIN_TOKEN') ?: ''));
$providedToken = trim((string) ($_SERVER['HTTP_X_MODEL_ADMIN_TOKEN'] ?? ''));
if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Операция доступна только администратору модели.',
        'error_code' => 'ADMIN_REQUIRED',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_file(__DIR__ . '/../../src/ML/mlp_weights.trained.json')) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Резервная копия весов не найдена. Запустите php bin/train_model.php.',
        'error_code' => 'INTERNAL_ERROR',
    ]);
    exit;
}

if (RuntimeStorage::resetModelWeights()) {
    $predictor = new TransportPredictor(
        RuntimeStorage::modelWeightsPath(),
        __DIR__ . '/../../src/ML/model_weights.json'
    );
    echo json_encode([
        'ok' => true,
        'model' => $predictor->modelType(),
        'model_version' => $predictor->modelVersion(),
    ], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось сбросить модель.', 'error_code' => 'INTERNAL_ERROR']);
}
