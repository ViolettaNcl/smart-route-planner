<?php

namespace Tests;

use App\ML\Dataset;
use App\ML\FeatureEncoder;
use App\ML\MLPClassifier;

class MLPClassifierTest
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

        $model = new MLPClassifier(Dataset::CLASSES, hiddenSize: 8, seed: 7);
        $lossHistory = $model->train($features, $labels, learningRate: 0.5, epochs: 400);

        // Backprop должен реально уменьшать потерю: последняя эпоха заметно
        // ниже первой — иначе градиенты посчитаны неверно (сеть "не учится").
        $firstLoss = reset($lossHistory);
        $lastLoss = end($lossHistory);
        $t->assertTrue(
            "Потеря убывает при обучении (было {$firstLoss}, стало {$lastLoss})",
            $lastLoss < $firstLoss
        );

        $accuracy = $model->accuracy($features, $labels);

        // Датасет с шумом (8% нетипичных меток), поэтому 100% недостижимо и не нужно —
        // но модель должна явно превзойти случайное угадывание (1/3 для 3 классов).
        $t->assertTrue(
            "Точность MLP ({$accuracy}) заметно выше случайного угадывания (0.33)",
            $accuracy > 0.7
        );

        // Явно короткое расстояние (1 км), мало точек — модель должна предсказывать "walk".
        $probs = $model->softmax(FeatureEncoder::distanceFeature(1.0), FeatureEncoder::stopsFeature(2));
        arsort($probs);
        $t->assertEquals('Короткая дистанция (1 км) классифицируется как "walk"', 'walk', array_key_first($probs));

        // Явно большое межгороднее расстояние — модель должна предсказывать "bus".
        $probs = $model->softmax(FeatureEncoder::distanceFeature(900.0), FeatureEncoder::stopsFeature(3));
        arsort($probs);
        $t->assertEquals('Дальняя дистанция (900 км) классифицируется как "bus"', 'bus', array_key_first($probs));

        // Вероятности выходного слоя — валидное распределение (сумма 1.0, все >= 0).
        $probs = $model->softmax(0.2, 0.3);
        $sum = array_sum($probs);
        $t->assertApprox('Вероятности на выходе MLP суммируются в 1.0', 1.0, $sum, 0.0001);
        $t->assertTrue('Все вероятности неотрицательны', min($probs) >= 0.0);

        // Сериализация весов в JSON и обратно должна давать идентичную модель
        // (иначе TransportPredictor после загрузки из файла будет отвечать иначе).
        $weights = $model->getWeights();
        $restored = json_decode(json_encode($weights), true);
        $reloaded = new MLPClassifier(Dataset::CLASSES);
        $reloaded->setWeights($restored);

        $t->assertEquals(
            'Предсказание после JSON round-trip весов не меняется',
            $model->predict(0.4, 0.5),
            $reloaded->predict(0.4, 0.5)
        );
        $t->assertApprox(
            'Вероятность класса после JSON round-trip совпадает',
            $model->softmax(0.4, 0.5)['car'],
            $reloaded->softmax(0.4, 0.5)['car'],
            0.0001
        );

        // Проверка "разбиения симметрии": скрытые нейроны должны стартовать с
        // разными весами (см. docblock MLPClassifier про Xavier-инициализацию) —
        // иначе backprop не имел бы смысла, все нейроны учились бы одинаково.
        $freshWeights = (new MLPClassifier(Dataset::CLASSES, hiddenSize: 8, seed: 99))->getWeights();
        $t->assertTrue(
            'Веса скрытых нейронов при инициализации не идентичны (симметрия разбита)',
            $freshWeights['w1'][0] !== $freshWeights['w1'][1]
        );

        // --- "живое" дообучение на одном примере (trainOnExample) ---
        $liveModel = new MLPClassifier(Dataset::CLASSES, hiddenSize: 8, seed: 7);
        $liveModel->train($features, $labels, learningRate: 0.5, epochs: 300);

        $x1 = FeatureEncoder::distanceFeature(50.0);
        $x2 = FeatureEncoder::stopsFeature(3);
        $probsBefore = $liveModel->softmax($x1, $x2);

        // Много раз "убеждаем" модель, что правильный ответ — bus, даже если
        // изначально она была уверена в чём-то другом — вероятность bus
        // должна вырасти (или как минимум не упасть) после каждой итерации.
        for ($i = 0; $i < 20; $i++) {
            $liveModel->trainOnExample($x1, $x2, 'bus', learningRate: 0.3);
        }
        $probsAfter = $liveModel->softmax($x1, $x2);

        $t->assertTrue(
            'После 20 шагов live-обучения на метке "bus" её вероятность выросла',
            $probsAfter['bus'] > $probsBefore['bus']
        );

        // --- explain(): числа реально складываются в предсказание ---
        $explanation = $liveModel->explain(0.3, 0.4);
        $t->assertTrue(
            'explain() возвращает вклад по всем скрытым нейронам',
            count($explanation['contributions']) === 8
        );
        $t->assertEquals(
            'explain() согласован с predict()',
            $liveModel->predict(0.3, 0.4),
            $explanation['predicted']
        );
    }
}
