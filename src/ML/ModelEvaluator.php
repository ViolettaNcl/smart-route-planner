<?php

namespace App\ML;

/**
 * Строгая оценка качества классификатора — то, чего не хватало проекту:
 * до этого класса единственной метрикой была accuracy (доля правильных
 * ответов). У accuracy есть классическая слабость — на несбалансированных
 * классах она может выглядеть высокой, даже если модель почти никогда не
 * угадывает редкий класс (например, если бы модель ВСЕГДА отвечала "car",
 * она бы всё равно получила неплохую accuracy, потому что car — самый частый
 * класс в датасете). Confusion matrix и precision/recall/F1 по каждому
 * классу вскрывают именно такие перекосы.
 *
 * Работает с любым классификатором через ClassifierInterface — не знает,
 * softmax перед ним или нейросеть.
 */
class ModelEvaluator
{
    /**
     * @param array<int, array{0: float, 1: float}> $features
     * @param string[] $labels
     * @param string[] $classes
     * @return array{
     *   confusion_matrix: array<string, array<string, int>>,
     *   per_class: array<string, array{precision: float, recall: float, f1: float, support: int}>,
     *   accuracy: float,
     *   macro_f1: float
     * }
     */
    public function evaluate(ClassifierInterface $model, array $features, array $labels, array $classes): array
    {
        // confusion_matrix[истинный_класс][предсказанный_класс] = количество примеров
        $matrix = [];
        foreach ($classes as $actual) {
            foreach ($classes as $predicted) {
                $matrix[$actual][$predicted] = 0;
            }
        }

        $correct = 0;
        foreach ($features as $i => [$x1, $x2]) {
            $actual = $labels[$i];
            $predicted = $model->predict($x1, $x2);
            $matrix[$actual][$predicted]++;

            if ($actual === $predicted) {
                $correct++;
            }
        }

        $perClass = [];
        $f1Sum = 0.0;

        foreach ($classes as $class) {
            // True Positives: предсказали class, и class действительно был правильным.
            $tp = $matrix[$class][$class];

            // False Positives: предсказали class, а на самом деле был другой класс.
            $fp = 0;
            foreach ($classes as $actual) {
                if ($actual !== $class) {
                    $fp += $matrix[$actual][$class];
                }
            }

            // False Negatives: реально был class, а модель предсказала другой.
            $fn = 0;
            foreach ($classes as $predicted) {
                if ($predicted !== $class) {
                    $fn += $matrix[$class][$predicted];
                }
            }

            $support = array_sum($matrix[$class]); // сколько примеров этого класса было всего

            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

            $perClass[$class] = [
                'precision' => round($precision, 3),
                'recall' => round($recall, 3),
                'f1' => round($f1, 3),
                'support' => $support,
            ];

            $f1Sum += $f1;
        }

        return [
            'confusion_matrix' => $matrix,
            'per_class' => $perClass,
            'accuracy' => count($labels) > 0 ? round($correct / count($labels), 4) : 0.0,
            // Macro-F1 — среднее F1 по классам БЕЗ учёта их размера (support).
            // В отличие от accuracy, редкий класс (walk) влияет на macro-F1
            // так же сильно, как частый (car) — это специально: нам важно,
            // чтобы модель не игнорировала редкие классы ради общей точности.
            'macro_f1' => round($f1Sum / max(count($classes), 1), 3),
        ];
    }

    /**
     * Adds probability-quality metrics to the class metrics above. The
     * reliability bins answer a different question than accuracy: when the
     * model says "about 70%", is it actually correct about 70% of the time?
     *
     * @param array<int, array{0: float, 1: float}> $features
     * @param string[] $labels
     * @param string[] $classes
     * @return array<string, mixed>
     */
    public function evaluateProbabilities(
        ClassifierInterface $model,
        array $features,
        array $labels,
        array $classes,
        int $binCount = 10
    ): array {
        $metrics = $this->evaluate($model, $features, $labels, $classes);
        $bins = array_fill(0, $binCount, [
            'count' => 0,
            'confidence_sum' => 0.0,
            'correct_sum' => 0,
        ]);
        $perClassBins = [];
        foreach ($classes as $class) {
            $perClassBins[$class] = array_fill(0, $binCount, [
                'count' => 0,
                'probability_sum' => 0.0,
                'positive_sum' => 0,
            ]);
        }

        $logLoss = 0.0;
        $brier = 0.0;
        $n = count($features);

        foreach ($features as $index => [$x1, $x2]) {
            $actual = $labels[$index];
            $probabilities = $model->softmax($x1, $x2);
            arsort($probabilities);
            $predicted = (string) array_key_first($probabilities);
            $confidence = (float) reset($probabilities);
            $bin = min($binCount - 1, (int) floor($confidence * $binCount));
            $bins[$bin]['count']++;
            $bins[$bin]['confidence_sum'] += $confidence;
            $bins[$bin]['correct_sum'] += $predicted === $actual ? 1 : 0;
            $logLoss += -log(max((float) ($probabilities[$actual] ?? 0.0), 1e-12));

            foreach ($classes as $class) {
                $probability = (float) ($probabilities[$class] ?? 0.0);
                $target = $class === $actual ? 1.0 : 0.0;
                $brier += ($probability - $target) ** 2;
                $classBin = min($binCount - 1, (int) floor($probability * $binCount));
                $perClassBins[$class][$classBin]['count']++;
                $perClassBins[$class][$classBin]['probability_sum'] += $probability;
                $perClassBins[$class][$classBin]['positive_sum'] += (int) $target;
            }
        }

        $ece = 0.0;
        $reliability = [];
        foreach ($bins as $index => $bin) {
            if ($bin['count'] === 0) {
                continue;
            }
            $averageConfidence = $bin['confidence_sum'] / $bin['count'];
            $observedAccuracy = $bin['correct_sum'] / $bin['count'];
            $ece += ($bin['count'] / max($n, 1)) * abs($averageConfidence - $observedAccuracy);
            $reliability[] = [
                'range_start' => round($index / $binCount, 2),
                'range_end' => round(($index + 1) / $binCount, 2),
                'count' => $bin['count'],
                'predicted' => round($averageConfidence, 3),
                'observed' => round($observedAccuracy, 3),
            ];
        }

        $classCalibration = [];
        foreach ($perClassBins as $class => $classBins) {
            $classCalibration[$class] = [];
            foreach ($classBins as $index => $bin) {
                if ($bin['count'] === 0) {
                    continue;
                }
                $classCalibration[$class][] = [
                    'range_start' => round($index / $binCount, 2),
                    'range_end' => round(($index + 1) / $binCount, 2),
                    'count' => $bin['count'],
                    'predicted' => round($bin['probability_sum'] / $bin['count'], 3),
                    'observed' => round($bin['positive_sum'] / $bin['count'], 3),
                ];
            }
        }

        $metrics['log_loss'] = $n > 0 ? round($logLoss / $n, 4) : 0.0;
        $metrics['brier_score'] = $n > 0 ? round($brier / $n, 4) : 0.0;
        $metrics['expected_calibration_error'] = round($ece, 4);
        $metrics['reliability'] = $reliability;
        $metrics['calibration_by_class'] = $classCalibration;

        return $metrics;
    }

    /**
     * K-fold кросс-валидация: honest-оценка вместо одного случайного
     * train/val разбиения. Данные делятся на $k примерно равных частей;
     * модель обучается k раз, каждый раз на (k-1) частях и проверяется на
     * оставшейся. Итоговая метрика — среднее по всем k прогонам, что менее
     * подвержено "везению" одного конкретного разбиения данных.
     *
     * @param array<int, array{0: float, 1: float}> $features
     * @param string[] $labels
     * @param callable(): ClassifierInterface $modelFactory Создаёт свежую (необученную) модель для каждого фолда
     * @return array{fold_accuracies: float[], mean_accuracy: float, std_accuracy: float}
     */
    public function kFoldCrossValidate(
        array $features,
        array $labels,
        callable $modelFactory,
        int $k = 5,
        float $learningRate = 0.5,
        int $epochs = 1000
    ): array {
        $n = count($features);
        $indices = range(0, $n - 1);

        $foldSize = (int) floor($n / $k);
        $foldAccuracies = [];

        for ($fold = 0; $fold < $k; $fold++) {
            $valStart = $fold * $foldSize;
            $valEnd = ($fold === $k - 1) ? $n : $valStart + $foldSize; // последний фолд забирает остаток

            $trainX = $trainY = $valX = $valY = [];

            foreach ($indices as $i) {
                if ($i >= $valStart && $i < $valEnd) {
                    $valX[] = $features[$i];
                    $valY[] = $labels[$i];
                } else {
                    $trainX[] = $features[$i];
                    $trainY[] = $labels[$i];
                }
            }

            $model = $modelFactory();
            $model->train($trainX, $trainY, $learningRate, $epochs);
            $foldAccuracies[] = round($model->accuracy($valX, $valY), 4);
        }

        $mean = array_sum($foldAccuracies) / count($foldAccuracies);
        $variance = array_sum(array_map(fn ($a) => ($a - $mean) ** 2, $foldAccuracies)) / count($foldAccuracies);

        return [
            'fold_accuracies' => $foldAccuracies,
            'mean_accuracy' => round($mean, 4),
            'std_accuracy' => round(sqrt($variance), 4),
        ];
    }
}
