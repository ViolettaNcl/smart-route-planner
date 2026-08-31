<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Http\RateLimitGuard;
use App\ML\ABTestStats;
use App\Support\RuntimeStorage;

/**
 * A/B-тест MLP vs Softmax: фиксирует "угадала ли модель" для варианта,
 * назначенного этому визиту (см. api/route.php про model_variant).
 *
 * POST /api/feedback.php
 * body: variant = mlp|softmax, is_correct = 1|0, event_id = route request id
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
$eventId = is_string($_POST['event_id'] ?? null) ? trim($_POST['event_id']) : '';

if (
    !in_array($variant, ['mlp', 'softmax'], true)
    || !in_array($isCorrectRaw, ['0', '1'], true)
    || preg_match('/^[a-zA-Z0-9._:-]{4,128}$/', $eventId) !== 1
) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Нужны variant, is_correct и корректный event_id.']);
    exit;
}

try {
    $stats = new ABTestStats(RuntimeStorage::path('ab_stats.json'));
    $accepted = $stats->record($variant, $isCorrectRaw === '1', $eventId);

    echo json_encode([
        'ok' => true,
        'accepted' => $accepted,
        'duplicate' => !$accepted,
        'stats' => $stats->getStats(),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось сохранить отзыв.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] feedback.php: ' . $e->getMessage());
}
