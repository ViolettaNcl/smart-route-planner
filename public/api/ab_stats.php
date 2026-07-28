<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\ML\ABTestStats;

/**
 * Текущая агрегированная статистика A/B-теста MLP vs Softmax.
 *
 * GET /api/ab_stats.php
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте GET.']);
    exit;
}

try {
    $stats = new ABTestStats(__DIR__ . '/../../var/ab_stats.json');
    echo json_encode(['ok' => true, 'stats' => $stats->getStats()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось загрузить статистику.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] ab_stats.php: ' . $e->getMessage());
}
