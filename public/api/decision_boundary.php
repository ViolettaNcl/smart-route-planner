<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\ML\Dataset;
use App\ML\FeatureEncoder;
use App\ML\MLPClassifier;
use App\ML\SoftmaxClassifier;
use App\Support\RuntimeStorage;

/**
 * Данные для интерактивной визуализации decision boundary классификатора
 * транспорта (см. README/docs/neural_net.md).
 *
 * Идея: вместо того чтобы переносить прямой проход сети на JS (дублируя
 * MLPClassifier на двух языках — источник рассинхронизации при любом
 * изменении архитектуры), сервер сам считает предсказание на регулярной
 * сетке точек [дистанция x число_точек] и отдаёт готовый список классов —
 * фронтенд просто рисует то, что получил (Chart.js scatter/heatmap).
 *
 * GET /api/decision_boundary.php?model=mlp|softmax
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте GET.']);
    exit;
}

$modelParam = $_GET['model'] ?? 'mlp';
$modelParam = in_array($modelParam, ['mlp', 'softmax'], true) ? $modelParam : 'mlp';

try {
    if ($modelParam === 'mlp') {
        $weightsPath = RuntimeStorage::modelWeightsPath();
        $model = new MLPClassifier(Dataset::CLASSES);
    } else {
        $weightsPath = __DIR__ . '/../../src/ML/model_weights.json';
        $model = new SoftmaxClassifier(Dataset::CLASSES);
    }

    if (!is_file($weightsPath)) {
        throw new \RuntimeException("Веса модели не найдены: {$weightsPath}. Запустите php bin/train_model.php.");
    }

    $model->setWeights(json_decode(file_get_contents($weightsPath), true));

    // --- сетка для карты решений ---
    // Дистанция: логарифмическая сетка от 0.2 до 1200 км — так и низкие, и
    // высокие значения представлены достаточно плотно (см. FeatureEncoder про
    // логарифмическое масштабирование признака дистанции).
    $distanceSteps = 70;
    $minDist = 0.2;
    $maxDist = 1200.0;
    $logMin = log($minDist);
    $logMax = log($maxDist);

    // Число точек маршрута: несколько характерных "срезов" — от одиночной
    // поездки до тура с большим числом остановок.
    $stopsSlices = [2, 4, 6, 8, 10, 12];

    $grid = [];
    for ($i = 0; $i < $distanceSteps; $i++) {
        $logD = $logMin + ($logMax - $logMin) * $i / ($distanceSteps - 1);
        $distanceKm = round(exp($logD), 2);

        foreach ($stopsSlices as $stops) {
            $x1 = FeatureEncoder::distanceFeature($distanceKm);
            $x2 = FeatureEncoder::stopsFeature($stops);

            $probs = $model->softmax($x1, $x2);
            arsort($probs);
            $best = array_key_first($probs);

            $grid[] = [
                'distance_km' => $distanceKm,
                'stops' => $stops,
                'mode' => $best,
                'confidence' => round($probs[$best] * 100, 1),
            ];
        }
    }

    // --- точки из обучающего датасета (для scatter поверх карты решений) ---
    // Тот же seed, что и в bin/train_model.php — те же примеры, на которых
    // модель реально обучалась, а не случайный новый набор.
    $dataset = new Dataset(seed: 42);
    $rows = $dataset->generate(samples: 600);

    // Присылать все 600 точек в браузер избыточно — берём равномерную
    // подвыборку, чтобы график оставался читаемым и лёгким.
    $sampleEvery = 4;
    $samples = [];
    foreach ($rows as $i => $row) {
        if ($i % $sampleEvery !== 0) {
            continue;
        }
        $samples[] = [
            'distance_km' => round($row['distance'], 2),
            'stops' => $row['stops'],
            'label' => $row['label'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'model' => $modelParam,
        'classes' => Dataset::CLASSES,
        'stops_slices' => $stopsSlices,
        'grid' => $grid,
        'samples' => $samples,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Не удалось построить карту решений модели.',
        'error_code' => 'INTERNAL_ERROR',
    ]);
    error_log('[smart-route-planner] decision_boundary.php: ' . $e->getMessage());
}
