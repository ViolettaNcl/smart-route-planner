<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Http\RateLimitGuard;
use App\ML\ModelQualityService;
use App\Support\RuntimeStorage;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=900');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается.', 'error_code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

RateLimitGuard::enforce('model_quality', capacity: 20, refillSeconds: 60);

try {
    $service = new ModelQualityService(
        RuntimeStorage::modelWeightsPath(),
        __DIR__ . '/../../src/ML/model_weights.json',
        RuntimeStorage::path('ml_feedback.ndjson')
    );
    echo json_encode([
        'ok' => true,
        'report' => $service->report(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Не удалось построить отчёт качества модели.',
        'error_code' => 'INTERNAL_ERROR',
    ], JSON_UNESCAPED_UNICODE);
    error_log('[smart-route-planner] model_quality.php: ' . $e->getMessage());
}
