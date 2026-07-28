<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

/**
 * Сброс модели к изначально обученному состоянию — отменяет весь эффект
 * "живого" дообучения через api/learn.php (см. его docblock про общий
 * файл весов и демо-характер механики).
 *
 * POST /api/reset_model.php
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

$backupPath = __DIR__ . '/../../src/ML/mlp_weights.trained.json';
$liveWeightsPath = __DIR__ . '/../../src/ML/mlp_weights.json';

if (!is_file($backupPath)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Резервная копия весов не найдена. Запустите php bin/train_model.php.',
        'error_code' => 'INTERNAL_ERROR',
    ]);
    exit;
}

if (copy($backupPath, $liveWeightsPath)) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось сбросить модель.', 'error_code' => 'INTERNAL_ERROR']);
}
