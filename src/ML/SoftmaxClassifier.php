<?php

namespace App\ML;

/**
 * Мультиклассовая логистическая регрессия (softmax regression), обучаемая
 * батч-градиентным спуском на кросс-энтропийной функции потерь.
 *
 * Это настоящее обучение, а не подобранные вручную веса: класс не содержит
 * заранее прописанных чисел — все веса $this->weights заполняются с нуля и
 * итеративно корректируются по производной ошибки на обучающей выборке.
 *
 * СТАТУС: с добавлением MLPClassifier (нейросеть со скрытым слоем и полным
 * backpropagation) этот класс стал baseline-моделью для сравнения — линейный
 * классификатор без скрытых слоёв. TransportPredictor по умолчанию использует
 * MLPClassifier, а SoftmaxClassifier остаётся в проекте как более простая
 * модель для сопоставления точности (см. docs/neural_net.md, раздел
 * "MLP vs Softmax") и как эталонная реализация backprop без скрытого слоя.
 */
class SoftmaxClassifier implements ClassifierInterface
{
    /** @var array<string, float[]> Веса по классам: [w_distance, w_stops, bias] */
    private array $weights = [];

    /** @var string[] */
    private array $classes;

    /** @param string[] $classes */
    public function __construct(array $classes)
    {
        $this->classes = $classes;

        foreach ($classes as $class) {
            $this->weights[$class] = [0.0, 0.0, 0.0];
        }
    }

    /**
     * @param array<int, array{0: float, 1: float}> $features Массив [x1, x2] на каждый пример
     * @param string[] $labels Метка класса на каждый пример (тот же порядок, что и $features)
     */
    public function train(array $features, array $labels, float $learningRate = 0.1, int $epochs = 2000): array
    {
        $n = count($features);
        $lossHistory = [];

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            $gradients = [];
            foreach ($this->classes as $class) {
                $gradients[$class] = [0.0, 0.0, 0.0];
            }

            $totalLoss = 0.0;

            for ($i = 0; $i < $n; $i++) {
                [$x1, $x2] = $features[$i];
                $trueClass = $labels[$i];

                $probs = $this->softmax($x1, $x2);
                $totalLoss += -log(max($probs[$trueClass], 1e-12));

                foreach ($this->classes as $class) {
                    $target = ($class === $trueClass) ? 1.0 : 0.0;
                    $error = $probs[$class] - $target; // производная softmax+CE

                    $gradients[$class][0] += $error * $x1;
                    $gradients[$class][1] += $error * $x2;
                    $gradients[$class][2] += $error;
                }
            }

            foreach ($this->classes as $class) {
                for ($j = 0; $j < 3; $j++) {
                    $this->weights[$class][$j] -= $learningRate * $gradients[$class][$j] / $n;
                }
            }

            if ($epoch % 100 === 0 || $epoch === $epochs - 1) {
                $lossHistory[$epoch] = round($totalLoss / $n, 4);
            }
        }

        return $lossHistory;
    }

    /**
     * @return array<string, float> Вероятность по каждому классу (в сумме 1.0)
     */
    public function softmax(float $x1, float $x2): array
    {
        $scores = [];
        foreach ($this->classes as $class) {
            [$w1, $w2, $bias] = $this->weights[$class];
            $scores[$class] = $w1 * $x1 + $w2 * $x2 + $bias;
        }

        $max = max($scores); // вычитаем максимум для численной устойчивости exp()
        $exp = array_map(fn ($s) => exp($s - $max), $scores);
        $sum = array_sum($exp);

        $probs = [];
        foreach ($this->classes as $class) {
            $probs[$class] = $exp[$class] / $sum;
        }

        return $probs;
    }

    public function predict(float $x1, float $x2): string
    {
        $probs = $this->softmax($x1, $x2);
        arsort($probs);

        return array_key_first($probs);
    }

    /**
     * Линейный аналог MLPClassifier::explain() — вклад каждого входного
     * признака в счёт выигравшего класса (weight * x, слагаемое суммы перед
     * softmax). Для линейной модели это ровно все "внутренности": нет
     * скрытого слоя, который нужно было бы разбирать отдельно.
     *
     * @return array{probs: array<string, float>, predicted: string, contributions: array<string, float>}
     */
    public function explain(float $x1, float $x2): array
    {
        $probs = $this->softmax($x1, $x2);
        arsort($probs);
        $predicted = array_key_first($probs);

        [$w1, $w2, $bias] = $this->weights[$predicted];

        return [
            'probs' => $probs,
            'predicted' => $predicted,
            'contributions' => [
                'distance_feature' => round($w1 * $x1, 4),
                'stops_feature' => round($w2 * $x2, 4),
                'bias' => round($bias, 4),
            ],
        ];
    }

    /**
     * @param array<int, array{0: float, 1: float}> $features
     * @param string[] $labels
     */
    public function accuracy(array $features, array $labels): float
    {
        $correct = 0;
        foreach ($features as $i => [$x1, $x2]) {
            if ($this->predict($x1, $x2) === $labels[$i]) {
                $correct++;
            }
        }

        return count($labels) > 0 ? $correct / count($labels) : 0.0;
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public function getWeights(): array
    {
        return $this->weights;
    }

    /** @param array<string, array{0: float, 1: float, 2: float}> $weights */
    public function setWeights(array $weights): void
    {
        $this->weights = $weights;
    }
}
