<?php

namespace Tests;

use App\ML\Dataset;
use App\ML\FeatureEncoder;
use App\ML\SoftmaxClassifier;

class SoftmaxClassifierTest
{
    public function run(TestReporter $t): void
    {
        $dataset = new Dataset(seed: 1);
        $rows = $dataset->generate(samples: 300);

        $features = array_map(
            fn ($r) => [FeatureEncoder::distanceFeature($r['distance']), FeatureEncoder::stopsFeature($r['stops'])],
            $rows
        );
        $labels = array_map(fn ($r) => $r['label'], $rows);

        $model = new SoftmaxClassifier(Dataset::CLASSES);
        $model->train($features, $labels, learningRate: 0.5, epochs: 500);

        $accuracy = $model->accuracy($features, $labels);

        // Датасет с шумом (8% нетипичных меток), поэтому 100% недостижимо и не нужно —
        // но модель должна явно превзойти случайное угадывание (1/3 для 3 классов).
        $t->assertTrue(
            "Точность модели ({$accuracy}) заметно выше случайного угадывания (0.33)",
            $accuracy > 0.7
        );

        // Явно короткое расстояние (1 км), мало точек — модель должна предсказывать "пешком".
        $probs = $model->softmax(FeatureEncoder::distanceFeature(1.0), FeatureEncoder::stopsFeature(2));
        arsort($probs);
        $t->assertEquals('Короткая дистанция (1 км) классифицируется как "walk"', 'walk', array_key_first($probs));

        // Явно большое межгороднее расстояние — модель должна предсказывать "bus".
        $probs = $model->softmax(FeatureEncoder::distanceFeature(900.0), FeatureEncoder::stopsFeature(3));
        arsort($probs);
        $t->assertEquals('Дальняя дистанция (900 км) классифицируется как "bus"', 'bus', array_key_first($probs));

        // Вероятности должны суммироваться в 1.0 (это softmax).
        $sum = array_sum($model->softmax(0.2, 0.3));
        $t->assertApprox('Вероятности softmax суммируются в 1.0', 1.0, $sum, 0.0001);
    }
}
