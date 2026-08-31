<?php

namespace Tests;

use App\ML\ClassifierInterface;
use App\ML\ModelEvaluator;

/**
 * Игрушечный классификатор с известным, предсказуемым поведением —
 * чтобы проверять арифметику ModelEvaluator на числах, которые можно
 * посчитать в уме, а не гадать по случайно обученной модели.
 */
class StubClassifier implements ClassifierInterface
{
    /** @param array<int, string> $script Что предсказывать на i-м вызове predict(), по порядку */
    public function __construct(private array $script)
    {
    }

    private int $callIndex = 0;

    public function train(array $features, array $labels, float $learningRate, int $epochs): array
    {
        return [];
    }

    public function softmax(float $x1, float $x2): array
    {
        return ['walk' => 0.34, 'car' => 0.33, 'bus' => 0.33];
    }

    public function predict(float $x1, float $x2): string
    {
        return $this->script[$this->callIndex++] ?? 'car';
    }

    public function accuracy(array $features, array $labels): float
    {
        return 0.0;
    }

    public function getWeights(): array
    {
        return [];
    }

    public function setWeights(array $weights): void
    {
    }

    public function explain(float $x1, float $x2): array
    {
        return ['probs' => [], 'predicted' => 'car', 'contributions' => []];
    }
}

class ModelEvaluatorTest
{
    public function run(TestReporter $t): void
    {
        $evaluator = new ModelEvaluator();
        $classes = ['walk', 'car', 'bus'];

        // 6 примеров: 2 walk, 2 car, 2 bus.
        // Модель (StubClassifier) угадывает оба car, путает один walk как car,
        // угадывает один walk, путает один bus как walk, угадывает один bus.
        $labels = ['walk', 'walk', 'car', 'car', 'bus', 'bus'];
        $predictions = ['car', 'walk', 'car', 'car', 'walk', 'bus'];
        $features = array_fill(0, 6, [0.1, 0.1]); // значения фич тут не важны — предсказания зашиты в StubClassifier

        $model = new StubClassifier($predictions);
        $result = $evaluator->evaluate($model, $features, $labels, $classes);

        // accuracy = 4 правильных из 6
        $t->assertApprox('Accuracy = 4/6', 0.6667, $result['accuracy'], 0.001);

        // walk: TP=1 (второй walk угадан), FN=1 (первый walk предсказан как car),
        //       FP=1 (пятый пример, реально bus, предсказан как walk)
        // precision = TP/(TP+FP) = 1/2 = 0.5; recall = TP/(TP+FN) = 1/2 = 0.5
        $t->assertApprox('walk: precision = 0.5', 0.5, $result['per_class']['walk']['precision'], 0.001);
        $t->assertApprox('walk: recall = 0.5', 0.5, $result['per_class']['walk']['recall'], 0.001);

        // car: TP=2 (оба car угаданы), FP=1 (первый walk предсказан как car), FN=0
        $t->assertApprox('car: precision = 2/3', 0.6667, $result['per_class']['car']['precision'], 0.001);
        $t->assertApprox('car: recall = 1.0 (все car угаданы)', 1.0, $result['per_class']['car']['recall'], 0.001);

        // bus: TP=1, FN=1 (второй bus предсказан как walk), FP=0
        $t->assertApprox('bus: recall = 0.5', 0.5, $result['per_class']['bus']['recall'], 0.001);
        $t->assertApprox('bus: precision = 1.0 (ни разу не предсказан ошибочно)', 1.0, $result['per_class']['bus']['precision'], 0.001);

        // support — просто количество примеров каждого класса в выборке
        $t->assertEquals('support(walk) = 2', 2, $result['per_class']['walk']['support']);
        $t->assertEquals('support(car) = 2', 2, $result['per_class']['car']['support']);

        // confusion_matrix[walk][car] должен быть 1 (один walk предсказан как car)
        $t->assertEquals('confusion_matrix[walk][car] = 1', 1, $result['confusion_matrix']['walk']['car']);
        $t->assertEquals('confusion_matrix[car][car] = 2', 2, $result['confusion_matrix']['car']['car']);

        // macro_f1 должен быть строго между 0 и 1 для неидеальной модели
        $t->assertTrue('macro_f1 в диапазоне (0, 1)', $result['macro_f1'] > 0 && $result['macro_f1'] < 1);

        $probabilityResult = $evaluator->evaluateProbabilities(
            new StubClassifier($predictions),
            $features,
            $labels,
            $classes,
            5
        );
        $t->assertTrue('Log loss рассчитан и неотрицателен', $probabilityResult['log_loss'] >= 0);
        $t->assertTrue('Multiclass Brier score рассчитан и неотрицателен', $probabilityResult['brier_score'] >= 0);
        $t->assertTrue('ECE лежит в [0,1]', $probabilityResult['expected_calibration_error'] >= 0 && $probabilityResult['expected_calibration_error'] <= 1);
        $t->assertTrue('Reliability diagram содержит непустые bins', count($probabilityResult['reliability']) > 0);
        $t->assertEquals('Калибровка рассчитана для каждого класса', 3, count($probabilityResult['calibration_by_class']));

        $this->testKFold($t, $evaluator);
    }

    private function testKFold(TestReporter $t, ModelEvaluator $evaluator): void
    {
        $dataset = new \App\ML\Dataset(seed: 7);
        $rows = $dataset->generate(samples: 200);

        $features = array_map(
            fn ($r) => [\App\ML\FeatureEncoder::distanceFeature($r['distance']), \App\ML\FeatureEncoder::stopsFeature($r['stops'])],
            $rows
        );
        $labels = array_map(fn ($r) => $r['label'], $rows);

        $result = $evaluator->kFoldCrossValidate(
            $features,
            $labels,
            fn () => new \App\ML\SoftmaxClassifier(\App\ML\Dataset::CLASSES),
            k: 5,
            learningRate: 0.5,
            epochs: 300
        );

        $t->assertEquals('5-fold даёт 5 значений accuracy', 5, count($result['fold_accuracies']));
        $t->assertTrue(
            'Средняя accuracy по фолдам заметно выше случайного угадывания (0.33)',
            $result['mean_accuracy'] > 0.6
        );
        $t->assertTrue('Стандартное отклонение между фолдами неотрицательно', $result['std_accuracy'] >= 0);
    }
}
