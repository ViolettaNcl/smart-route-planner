<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\ML\Dataset;
use App\ML\TransportPredictor;
use App\Http\RateLimitGuard;

/**
 * "Живое" дообучение модели: пользователь поправляет предсказание ("на
 * самом деле это bus, а не car") — сервер делает ОДИН шаг градиентного
 * спуска на этом примере (App\ML\MLPClassifier::trainOnExample) и сохраняет
 * обновлённые веса. Работает только для MLP (см. TransportPredictor::learnFromExample).
 *
 * ⚠️ Демо-механика: веса — общий файл на диске, обновление видно всем
 * посетителям сайта (не привязано к сессии/пользователю). Это осознанный
 * выбор ради простоты и наглядности демонстрации online learning, а не то,
 * что стоит переносить в прод без изоляции по пользователю. Отдельный,
 * более строгий rate limit (10/минуту) — минимальная защита от спама по
 * этой кнопке, портящего общую демо-модель всем сразу.
 * Кнопка "сбросить модель" (api/reset_model.php) возвращает изначально
 * обученные веса.
 *
 * POST /api/learn.php
 * body: distance_km, stops, correct_label (walk|car|bus)
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

RateLimitGuard::enforce('learn', capacity: 10, refillSeconds: 60);

$distanceKm = isset($_POST['distance_km']) && is_numeric($_POST['distance_km']) ? (float) $_POST['distance_km'] : null;
$stops = isset($_POST['stops']) && is_numeric($_POST['stops']) ? (int) $_POST['stops'] : null;
$correctLabel = $_POST['correct_label'] ?? null;

if ($distanceKm === null || $stops === null || !in_array($correctLabel, Dataset::CLASSES, true)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Нужны distance_km, stops (>=2) и correct_label (walk|car|bus).',
    ]);
    exit;
}

$mlpWeightsPath = __DIR__ . '/../../src/ML/mlp_weights.json';

try {
    $predictor = new TransportPredictor($mlpWeightsPath, __DIR__ . '/../../src/ML/model_weights.json');

    $before = $predictor->predict($distanceKm, $stops);
    $applied = $predictor->learnFromExample($distanceKm, $stops, $correctLabel, $mlpWeightsPath);

    if (!$applied) {
        echo json_encode([
            'ok' => true,
            'applied' => false,
            'note' => 'Активна softmax-модель (нет файла весов MLP) — точечное дообучение поддерживается только для MLP.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Перечитываем предиктор из только что сохранённых весов — так ответ
    // отражает ровно то, что увидит следующий запрос (никакого расхождения
    // между "что вернули" и "что реально лежит в файле").
    $predictorAfter = new TransportPredictor($mlpWeightsPath, __DIR__ . '/../../src/ML/model_weights.json');
    $after = $predictorAfter->predict($distanceKm, $stops);

    echo json_encode([
        'ok' => true,
        'applied' => true,
        'before' => $before,
        'after' => $after,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось применить дообучение.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] learn.php: ' . $e->getMessage());
}
