<?php

/**
 * Обучение классификаторов транспорта.
 *
 * Запуск:  php bin/train_model.php
 *
 * Что делает скрипт:
 * 1. Генерирует синтетический датасет (App\ML\Dataset).
 * 2. Делит его на train/validation/test (80/10/10) — один и тот же split
 *    используется для обеих моделей, а test не участвует в выборе модели.
 * 3. Обучает MLPClassifier (нейросеть со скрытым слоем, backprop с нуля) —
 *    это модель, которую использует приложение по умолчанию.
 * 4. Обучает SoftmaxClassifier (линейная модель) как baseline для сравнения —
 *    показывает, какой прирост качества даёт скрытый слой.
 * 5. Печатает качество обеих моделей отдельно на validation и test.
 * 6. Сохраняет веса в src/ML/mlp_weights.json и src/ML/model_weights.json —
 *    их использует TransportPredictor во время работы приложения (мягкий
 *    приоритет MLP, softmax как fallback — см. TransportPredictor).
 */

require __DIR__ . '/../bootstrap.php';

use App\ML\Dataset;
use App\ML\FeatureEncoder;
use App\ML\MLPClassifier;
use App\ML\ModelEvaluator;
use App\ML\SoftmaxClassifier;

$dataset = new Dataset(seed: 42);
$rows = $dataset->generate(samples: 600);

mt_srand(42); // тот же seed для shuffle, чтобы train/val split был воспроизводим
shuffle($rows);
$splitAt = (int) (count($rows) * 0.8);
$trainRows = array_slice($rows, 0, $splitAt);
$holdoutRows = array_slice($rows, $splitAt);
$valRows = array_slice($holdoutRows, 0, (int) (count($holdoutRows) / 2));
$testRows = array_slice($holdoutRows, count($valRows));

$toFeaturesAndLabels = function (array $rows): array {
    $features = [];
    $labels = [];
    foreach ($rows as $row) {
        $features[] = [
            FeatureEncoder::distanceFeature($row['distance']),
            FeatureEncoder::stopsFeature($row['stops']),
        ];
        $labels[] = $row['label'];
    }
    return [$features, $labels];
};

[$trainX, $trainY] = $toFeaturesAndLabels($trainRows);
[$valX, $valY] = $toFeaturesAndLabels($valRows);
[$testX, $testY] = $toFeaturesAndLabels($testRows);

echo 'Обучающая выборка: ' . count($trainRows) . " примеров\n";
echo 'Валидационная выборка: ' . count($valRows) . " примеров\n";
echo 'Финальная тестовая выборка: ' . count($testRows) . " примеров\n";

$snapshotEpochs = [0, 100, 300, 700, 1100, 1499];
$snapshotDistances = [];
for ($i = 0; $i < 32; $i++) {
    $snapshotDistances[] = round(exp(log(0.2) + (log(1200.0) - log(0.2)) * $i / 31), 2);
}
$snapshotStops = [2, 4, 6, 8, 10, 12];
$snapshotEncoding = ['walk' => 'w', 'car' => 'c', 'bus' => 'b'];

/** @return string Compact row-major class map: stop slices × logarithmic distances. */
$encodeBoundary = static function (MLPClassifier $model) use (
    $snapshotDistances,
    $snapshotStops,
    $snapshotEncoding
): string {
    $encoded = '';
    foreach ($snapshotStops as $stops) {
        foreach ($snapshotDistances as $distance) {
            $encoded .= $snapshotEncoding[$model->predict(
                FeatureEncoder::distanceFeature($distance),
                FeatureEncoder::stopsFeature($stops)
            )];
        }
    }

    return $encoded;
};

// ---------------------------------------------------------------------
// 1) MLP (нейросеть со скрытым слоем) — основная модель приложения
// ---------------------------------------------------------------------

echo "\n=== Обучение MLPClassifier (скрытый слой: 8 нейронов, tanh) ===\n";

$mlp = new MLPClassifier(Dataset::CLASSES, hiddenSize: 8, seed: 42);
$mlpSnapshots = [];
$mlpLossHistory = $mlp->train(
    $trainX,
    $trainY,
    learningRate: 0.5,
    epochs: 1500,
    observer: static function (int $epoch, float $loss, MLPClassifier $model) use (
        &$mlpSnapshots,
        $snapshotEpochs,
        $encodeBoundary,
        $valX,
        $valY
    ): void {
        if (!in_array($epoch, $snapshotEpochs, true)) {
            return;
        }
        $mlpSnapshots[] = [
            'epoch' => $epoch,
            'loss' => $loss,
            'validation_accuracy' => round($model->accuracy($valX, $valY), 4),
            'classes' => $encodeBoundary($model),
        ];
    }
);

echo "Ход обучения (кросс-энтропийная потеря):\n";
foreach ($mlpLossHistory as $epoch => $loss) {
    echo "  эпоха {$epoch}: loss = {$loss}\n";
}

$mlpTrainAcc = $mlp->accuracy($trainX, $trainY);
$mlpValAcc = $mlp->accuracy($valX, $valY);
$mlpTestAcc = $mlp->accuracy($testX, $testY);

$mlpWeightsPath = __DIR__ . '/../src/ML/mlp_weights.json';
file_put_contents($mlpWeightsPath, json_encode($mlp->getWeights(), JSON_PRETTY_PRINT));
echo "Веса MLP сохранены в: {$mlpWeightsPath}\n";

// Отдельная проверенная копия нужна только административному rollback.
// Публичные HTTP-запросы никогда не перезаписывают production-веса.
$mlpBackupPath = __DIR__ . '/../src/ML/mlp_weights.trained.json';
file_put_contents($mlpBackupPath, json_encode($mlp->getWeights(), JSON_PRETTY_PRINT));
echo "Административный baseline для rollback: {$mlpBackupPath}\n";

// ---------------------------------------------------------------------
// 2) Softmax-регрессия (линейная модель) — baseline для сравнения
// ---------------------------------------------------------------------

echo "\n=== Обучение SoftmaxClassifier (линейный baseline) ===\n";

$softmax = new SoftmaxClassifier(Dataset::CLASSES);
$softmaxLossHistory = $softmax->train($trainX, $trainY, learningRate: 0.5, epochs: 2000);

$softmaxTrainAcc = $softmax->accuracy($trainX, $trainY);
$softmaxValAcc = $softmax->accuracy($valX, $valY);
$softmaxTestAcc = $softmax->accuracy($testX, $testY);

$softmaxWeightsPath = __DIR__ . '/../src/ML/model_weights.json';
file_put_contents($softmaxWeightsPath, json_encode($softmax->getWeights(), JSON_PRETTY_PRINT));
echo "Веса Softmax сохранены в: {$softmaxWeightsPath}\n";

/**
 * @param array<int, float> $history
 * @return array<int, array{epoch: int, loss: float}>
 */
$historyRows = static function (array $history): array {
    $result = [];
    foreach ($history as $epoch => $loss) {
        $result[] = ['epoch' => (int) $epoch, 'loss' => $loss];
    }

    return $result;
};

$trainingReport = [
    'schema_version' => 1,
    'generated_at' => gmdate('c'),
    'dataset_seed' => 42,
    'model_versions' => [
        'mlp' => 'mlp-' . substr((string) hash_file('sha256', $mlpWeightsPath), 0, 8),
        'softmax' => 'softmax-' . substr((string) hash_file('sha256', $softmaxWeightsPath), 0, 8),
    ],
    'split' => [
        'train_samples' => count($trainRows),
        'validation_samples' => count($valRows),
        'test_samples' => count($testRows),
    ],
    'grid' => [
        'distances_km' => $snapshotDistances,
        'stops' => $snapshotStops,
        'encoding' => array_flip($snapshotEncoding),
    ],
    'models' => [
        'mlp' => [
            'epochs' => 1500,
            'learning_rate' => 0.5,
            'loss_history' => $historyRows($mlpLossHistory),
            'snapshots' => $mlpSnapshots,
            'validation_accuracy' => round($mlpValAcc, 4),
            'test_accuracy' => round($mlpTestAcc, 4),
        ],
        'softmax' => [
            'epochs' => 2000,
            'learning_rate' => 0.5,
            'loss_history' => $historyRows($softmaxLossHistory),
            'validation_accuracy' => round($softmaxValAcc, 4),
            'test_accuracy' => round($softmaxTestAcc, 4),
        ],
    ],
];
// ---------------------------------------------------------------------
// Сравнение
// ---------------------------------------------------------------------

echo "\n=== Сравнение моделей (валидационная выборка — данные не видели при обучении) ===\n";
printf("  %-18s %10s %10s %10s\n", 'Модель', 'Train acc', 'Val acc', 'Test acc');
printf("  %-18s %9s%% %9s%% %9s%%\n", 'MLP (скрытый слой)', round($mlpTrainAcc * 100, 1), round($mlpValAcc * 100, 1), round($mlpTestAcc * 100, 1));
printf("  %-18s %9s%% %9s%% %9s%%\n", 'Softmax (линейная)', round($softmaxTrainAcc * 100, 1), round($softmaxValAcc * 100, 1), round($softmaxTestAcc * 100, 1));

echo "\nПриложение по умолчанию использует MLP (см. App\\ML\\TransportPredictor).\n";
echo "Softmax остаётся как baseline/fallback — подробнее см. docs/neural_net.md.\n";

// ---------------------------------------------------------------------
// 3) Строгая оценка: accuracy — далеко не всё. Confusion matrix и
//    precision/recall/F1 по каждому классу вскрывают перекосы, которые
//    accuracy маскирует (например, если бы модель игнорировала редкий
//    класс "walk", accuracy всё равно осталась бы неплохой за счёт частых
//    "car"/"bus" — macro-F1 такую модель бы наказал).
// ---------------------------------------------------------------------

$evaluator = new ModelEvaluator();

/**
 * @param array{confusion_matrix: array<string, array<string, int>>, per_class: array<string, array{precision: float, recall: float, f1: float, support: int}>, accuracy: float, macro_f1: float} $evaluation
 * @param array<int, string> $classes
 */
function printEvaluation(string $modelName, array $evaluation, array $classes): void
{
    echo "\n--- {$modelName}: confusion matrix (строки — истинный класс, столбцы — предсказанный) ---\n";

    printf('  %-8s', '');
    foreach ($classes as $c) {
        printf('%8s', $c);
    }
    echo "\n";

    foreach ($classes as $actual) {
        printf('  %-8s', $actual);
        foreach ($classes as $predicted) {
            printf('%8d', $evaluation['confusion_matrix'][$actual][$predicted]);
        }
        echo "\n";
    }

    echo "\n--- {$modelName}: precision / recall / F1 по классам ---\n";
    printf("  %-8s %10s %10s %10s %10s\n", 'Класс', 'Precision', 'Recall', 'F1', 'Support');
    foreach ($classes as $class) {
        $m = $evaluation['per_class'][$class];
        printf("  %-8s %10s %10s %10s %10d\n", $class, $m['precision'], $m['recall'], $m['f1'], $m['support']);
    }

    echo "\n  Accuracy: {$evaluation['accuracy']}   Macro-F1: {$evaluation['macro_f1']}\n";
    echo "  (Macro-F1 усредняет F1 по классам поровну — редкий класс 'walk' влияет\n";
    echo "   на эту метрику так же сильно, как частый 'car', в отличие от accuracy.)\n";
}

$mlpEvaluation = $evaluator->evaluate($mlp, $testX, $testY, Dataset::CLASSES);
printEvaluation('MLP', $mlpEvaluation, Dataset::CLASSES);

$softmaxEvaluation = $evaluator->evaluate($softmax, $testX, $testY, Dataset::CLASSES);
printEvaluation('Softmax', $softmaxEvaluation, Dataset::CLASSES);

// ---------------------------------------------------------------------
// 4) K-fold кросс-валидация — честнее одного случайного train/val сплита:
//    единственное разбиение может оказаться "удачным" или "неудачным" по
//    случайности; 5-fold усредняет результат по 5 разным разбиениям.
// ---------------------------------------------------------------------

echo "\n=== 5-fold кросс-валидация MLP (весь датасет, не только train-часть) ===\n";

[$allX, $allY] = $toFeaturesAndLabels($rows);

$cv = $evaluator->kFoldCrossValidate(
    $allX,
    $allY,
    fn () => new MLPClassifier(Dataset::CLASSES, hiddenSize: 8, seed: 42),
    k: 5,
    learningRate: 0.5,
    epochs: 800 // меньше, чем в основном обучении — 5 отдельных прогонов, иначе скрипт станет заметно медленнее
);

echo '  Accuracy по фолдам: ' . implode(', ', array_map(fn ($a) => round($a * 100, 1) . '%', $cv['fold_accuracies'])) . "\n";
echo '  Среднее: ' . round($cv['mean_accuracy'] * 100, 1) . '% ± ' . round($cv['std_accuracy'] * 100, 1) . "%\n";
echo "  (Разброс между фолдами показывает, насколько оценка качества зависит\n";
echo "   от конкретного разбиения данных — маленький разброс = стабильный результат.)\n";

$trainingReport['cross_validation'] = [
    'model' => 'mlp',
    'folds' => 5,
    'epochs_per_fold' => 800,
    'fold_accuracies' => $cv['fold_accuracies'],
    'mean_accuracy' => $cv['mean_accuracy'],
    'std_accuracy' => $cv['std_accuracy'],
];
$trainingReportPath = __DIR__ . '/../src/ML/training_report.json';
$encodedTrainingReport = json_encode($trainingReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($encodedTrainingReport) || file_put_contents($trainingReportPath, $encodedTrainingReport) === false) {
    throw new RuntimeException('Не удалось сохранить воспроизводимый отчёт обучения.');
}
echo "Отчёт обучения, снимки границы и cross-validation сохранены в: {$trainingReportPath}\n";
