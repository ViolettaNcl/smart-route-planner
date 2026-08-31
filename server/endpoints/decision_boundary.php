<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Http\RateLimitGuard;
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
header('Cache-Control: public, max-age=900');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте GET.']);
    exit;
}

RateLimitGuard::enforce('decision_boundary', capacity: 20, refillSeconds: 60);

$modelParam = $_GET['model'] ?? 'mlp';
$modelParam = in_array($modelParam, ['mlp', 'softmax'], true) ? $modelParam : 'mlp';

try {
    $mlpWeightsPath = RuntimeStorage::modelWeightsPath();
    $softmaxWeightsPath = __DIR__ . '/../../src/ML/model_weights.json';
    if (!is_file($mlpWeightsPath) || !is_file($softmaxWeightsPath)) {
        throw new \RuntimeException('Веса одной из сравниваемых моделей не найдены.');
    }

    $mlp = new MLPClassifier(Dataset::CLASSES);
    $mlp->setWeights(json_decode((string) file_get_contents($mlpWeightsPath), true));
    $softmax = new SoftmaxClassifier(Dataset::CLASSES);
    $softmax->setWeights(json_decode((string) file_get_contents($softmaxWeightsPath), true));
    $model = $modelParam === 'softmax' ? $softmax : $mlp;

    // --- сетка для карты решений ---
    // Дистанция: логарифмическая сетка от 0.2 до 1500 км — так и низкие, и
    // высокие значения представлены достаточно плотно (см. FeatureEncoder про
    // логарифмическое масштабирование признака дистанции).
    $distanceSteps = 70;
    $minDist = 0.2;
    $maxDist = 1500.0;
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
            $mlpProbs = $mlp->softmax($x1, $x2);
            $softmaxProbs = $softmax->softmax($x1, $x2);
            arsort($probs);
            arsort($mlpProbs);
            arsort($softmaxProbs);
            $best = array_key_first($probs);
            $mlpBest = array_key_first($mlpProbs);
            $softmaxBest = array_key_first($softmaxProbs);

            $grid[] = [
                'distance_km' => $distanceKm,
                'stops' => $stops,
                'mode' => $best,
                'confidence' => round($probs[$best] * 100, 1),
                'models' => [
                    'mlp' => [
                        'mode' => $mlpBest,
                        'confidence' => round($mlpProbs[$mlpBest] * 100, 1),
                        'probabilities' => array_map(static fn (float $p): float => round($p * 100, 1), $mlpProbs),
                    ],
                    'softmax' => [
                        'mode' => $softmaxBest,
                        'confidence' => round($softmaxProbs[$softmaxBest] * 100, 1),
                        'probabilities' => array_map(static fn (float $p): float => round($p * 100, 1), $softmaxProbs),
                    ],
                ],
                'disagreement' => $mlpBest !== $softmaxBest,
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
        'comparison_available' => true,
        'model_versions' => [
            'mlp' => 'mlp-' . substr((string) hash_file('sha256', $mlpWeightsPath), 0, 8),
            'softmax' => 'softmax-' . substr((string) hash_file('sha256', $softmaxWeightsPath), 0, 8),
        ],
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
