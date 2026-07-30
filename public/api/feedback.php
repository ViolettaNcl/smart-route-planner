<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Http\RateLimitGuard;
use App\ML\ABTestStats;

/**
 * A/B-тест MLP vs Softmax: фиксирует "угадала ли модель" для варианта,
 * назначенного этому визиту (см. api/route.php про model_variant).
 *
 * POST /api/feedback.php
 * body: variant = mlp|softmax, is_correct = 1|0
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

RateLimitGuard::enforce('feedback', capacity: 10, refillSeconds: 60);

$variant = $_POST['variant'] ?? null;
$isCorrectRaw = $_POST['is_correct'] ?? null;

if (!in_array($variant, ['mlp', 'softmax'], true) || !in_array($isCorrectRaw, ['0', '1'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Нужны variant (mlp|softmax) и is_correct (0|1).']);
    exit;
}

try {
    $stats = new ABTestStats(__DIR__ . '/../../var/ab_stats.json');
    $stats->record($variant, $isCorrectRaw === '1');

    echo json_encode(['ok' => true, 'stats' => $stats->getStats()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось сохранить отзыв.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] feedback.php: ' . $e->getMessage());
}
