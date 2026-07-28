<?php

/**
 * Обучение классификаторов транспорта.
 *
 * Запуск:  php bin/train_model.php
 *
 * Что делает скрипт:
 * 1. Генерирует синтетический датасет (App\ML\Dataset).
 * 2. Делит его на обучающую и валидационную выборки (80/20) — один и тот же
 *    split используется для обеих моделей, чтобы сравнение было честным.
 * 3. Обучает MLPClassifier (нейросеть со скрытым слоем, backprop с нуля) —
 *    это модель, которую использует приложение по умолчанию.
 * 4. Обучает SoftmaxClassifier (линейная модель) как baseline для сравнения —
 *    показывает, какой прирост качества даёт скрытый слой.
 * 5. Печатает точность обеих моделей на валидационной выборке (данные,
 *    которые они не видели при обучении, — честная проверка).
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
$valRows = array_slice($rows, $splitAt);

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

echo "Обучающая выборка: " . count($trainRows) . " примеров\n";
echo "Валидационная выборка: " . count($valRows) . " примеров\n";

// ---------------------------------------------------------------------
// 1) MLP (нейросеть со скрытым слоем) — основная модель приложения
// ---------------------------------------------------------------------

echo "\n=== Обучение MLPClassifier (скрытый слой: 8 нейронов, tanh) ===\n";

$mlp = new MLPClassifier(Dataset::CLASSES, hiddenSize: 8, seed: 42);
$mlpLossHistory = $mlp->train($trainX, $trainY, learningRate: 0.5, epochs: 1500);

echo "Ход обучения (кросс-энтропийная потеря):\n";
foreach ($mlpLossHistory as $epoch => $loss) {
    echo "  эпоха {$epoch}: loss = {$loss}\n";
}

$mlpTrainAcc = $mlp->accuracy($trainX, $trainY);
$mlpValAcc = $mlp->accuracy($valX, $valY);

$mlpWeightsPath = __DIR__ . '/../src/ML/mlp_weights.json';
file_put_contents($mlpWeightsPath, json_encode($mlp->getWeights(), JSON_PRETTY_PRINT));
echo "Веса MLP сохранены в: {$mlpWeightsPath}\n";

// Отдельная "чистая" копия — используется кнопкой "Сбросить модель" в
// интерфейсе (после демонстрации живого дообучения, см. api/learn.php),
// чтобы вернуть модель к изначально обученному состоянию без повторного
// прогона всего обучения.
$mlpBackupPath = __DIR__ . '/../src/ML/mlp_weights.trained.json';
file_put_contents($mlpBackupPath, json_encode($mlp->getWeights(), JSON_PRETTY_PRINT));
echo "Резервная копия (для сброса после live-обучения): {$mlpBackupPath}\n";

// ---------------------------------------------------------------------
// 2) Softmax-регрессия (линейная модель) — baseline для сравнения
// ---------------------------------------------------------------------

echo "\n=== Обучение SoftmaxClassifier (линейный baseline) ===\n";

$softmax = new SoftmaxClassifier(Dataset::CLASSES);
$softmax->train($trainX, $trainY, learningRate: 0.5, epochs: 2000);

$softmaxTrainAcc = $softmax->accuracy($trainX, $trainY);
$softmaxValAcc = $softmax->accuracy($valX, $valY);

$softmaxWeightsPath = __DIR__ . '/../src/ML/model_weights.json';
file_put_contents($softmaxWeightsPath, json_encode($softmax->getWeights(), JSON_PRETTY_PRINT));
echo "Веса Softmax сохранены в: {$softmaxWeightsPath}\n";

// ---------------------------------------------------------------------
// Сравнение
// ---------------------------------------------------------------------

echo "\n=== Сравнение моделей (валидационная выборка — данные не видели при обучении) ===\n";
printf("  %-18s %10s %10s\n", 'Модель', 'Train acc', 'Val acc');
printf("  %-18s %9s%% %9s%%\n", 'MLP (скрытый слой)', round($mlpTrainAcc * 100, 1), round($mlpValAcc * 100, 1));
printf("  %-18s %9s%% %9s%%\n", 'Softmax (линейная)', round($softmaxTrainAcc * 100, 1), round($softmaxValAcc * 100, 1));

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

function printEvaluation(string $modelName, array $evaluation, array $classes): void
{
    echo "\n--- {$modelName}: confusion matrix (строки — истинный класс, столбцы — предсказанный) ---\n";

    printf("  %-8s", '');
    foreach ($classes as $c) {
        printf("%8s", $c);
    }
    echo "\n";

    foreach ($classes as $actual) {
        printf("  %-8s", $actual);
        foreach ($classes as $predicted) {
            printf("%8d", $evaluation['confusion_matrix'][$actual][$predicted]);
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

$mlpEvaluation = $evaluator->evaluate($mlp, $valX, $valY, Dataset::CLASSES);
printEvaluation('MLP', $mlpEvaluation, Dataset::CLASSES);

$softmaxEvaluation = $evaluator->evaluate($softmax, $valX, $valY, Dataset::CLASSES);
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

echo "  Accuracy по фолдам: " . implode(', ', array_map(fn ($a) => round($a * 100, 1) . '%', $cv['fold_accuracies'])) . "\n";
echo "  Среднее: " . round($cv['mean_accuracy'] * 100, 1) . "% ± " . round($cv['std_accuracy'] * 100, 1) . "%\n";
echo "  (Разброс между фолдами показывает, насколько оценка качества зависит\n";
echo "   от конкретного разбиения данных — маленький разброс = стабильный результат.)\n";
