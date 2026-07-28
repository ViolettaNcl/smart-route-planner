<?php

namespace App\ML;

/**
 * Полносвязная нейросеть (Multi-Layer Perceptron) с одним скрытым слоем,
 * обучаемая методом обратного распространения ошибки (backpropagation),
 * написанным с нуля — без numpy/matrix-библиотек, только массивы PHP и
 * циклы. Никакого sklearn/tensorflow: если что-то похоже на матричное
 * умножение — это ручной вложенный foreach.
 *
 * Архитектура: 2 входа -> H скрытых нейронов (активация tanh) -> 3 выхода
 * (softmax + кросс-энтропия). Тот же вход/выход, что и у SoftmaxClassifier
 * (см. его docblock про "апгрейд" в docs/neural_net.md) — но теперь между
 * входом и выходом есть нелинейный скрытый слой, поэтому модель способна
 * выучить нелинейную границу решений, а не только прямую линию, как
 * линейная softmax-регрессия.
 *
 * ## Почему нельзя инициализировать веса нулями (как в SoftmaxClassifier)
 *
 * У SoftmaxClassifier нулевая инициализация — не проблема: это выпуклая
 * (convex) модель без скрытого слоя, градиентный спуск сходится к одному и
 * тому же оптимуму независимо от старта.
 *
 * У MLP это стало бы "проблемой симметрии" (symmetry breaking problem): если
 * все веса скрытого слоя одинаковы (например, нули), то на каждом шаге
 * обучения все H скрытых нейронов получают абсолютно одинаковый градиент и
 * вечно остаются идентичными друг другу — скрытый слой из H нейронов
 * фактически выродится в скрытый слой из 1 нейрона, и вся выгода от глубины
 * потеряется. Поэтому веса скрытого слоя инициализируются случайными
 * числами (Xavier/Glorot-инициализация: масштаб ~1/sqrt(n_inputs)), а не
 * нулями. Инициализация детерминирована по seed — прогон воспроизводим.
 *
 * ## Прямой проход (forward pass)
 *
 *   z1[i] = sum_j( w1[i][j] * x[j] ) + b1[i]      для каждого скрытого нейрона i
 *   a1[i] = tanh(z1[i])                            нелинейность скрытого слоя
 *   z2[k] = sum_i( w2[k][i] * a1[i] ) + b2[k]      для каждого класса k
 *   probs = softmax(z2)                            вероятности по 3 классам
 *
 * ## Обратный проход (backpropagation) — вручную, по цепному правилу
 *
 * Для кросс-энтропийной потери поверх softmax производная по z2 сворачивается
 * в красивую формулу (тот же трюк, что и в SoftmaxClassifier):
 *
 *   dz2[k] = probs[k] - target[k]                  target — one-hot истинного класса
 *   dW2[k][i] = dz2[k] * a1[i]                      градиент весов выходного слоя
 *   db2[k] = dz2[k]
 *
 * Дальше ошибка "протаскивается" через скрытый слой по цепному правилу:
 *
 *   da1[i] = sum_k( w2[k][i] * dz2[k] )             сколько каждый скрытый нейрон
 *                                                    "виноват" в ошибке на выходе
 *   dz1[i] = da1[i] * (1 - a1[i]^2)                 производная tanh: tanh'(z) = 1 - tanh(z)^2
 *   dW1[i][j] = dz1[i] * x[j]                       градиент весов входного слоя
 *   db1[i] = dz1[i]
 *
 * Это и есть backpropagation: ошибка, посчитанная на выходе, распространяется
 * назад через слои, и на каждом слое используется производная его функции
 * активации (здесь — tanh) и веса следующего слоя.
 */
class MLPClassifier implements ClassifierInterface
{
    /** @var string[] */
    private array $classes;

    private int $hiddenSize;

    /** @var float[][] Веса входного слоя: w1[i][j] — вес входа j -> скрытый нейрон i */
    private array $w1 = [];

    /** @var float[] Смещения скрытого слоя */
    private array $b1 = [];

    /** @var float[][] Веса выходного слоя: w2[k][i] — вес скрытого нейрона i -> класс k */
    private array $w2 = [];

    /** @var float[] Смещения выходного слоя (по числу классов) */
    private array $b2 = [];

    public function __construct(array $classes, int $hiddenSize = 8, int $seed = 42)
    {
        $this->classes = $classes;
        $this->hiddenSize = $hiddenSize;
        $this->randomInit($seed);
    }

    /**
     * Xavier/Glorot-инициализация: случайные веса в диапазоне
     * ±1/sqrt(n_inputs_слоя), нули для смещений. Разбивает симметрию между
     * скрытыми нейронами (см. docblock класса) и держит начальные активации
     * в разумном диапазоне, чтобы tanh не сразу уходил в насыщение.
     */
    private function randomInit(int $seed): void
    {
        mt_srand($seed);

        $inputSize = 2; // [дистанция, число_точек] — см. FeatureEncoder
        $limitW1 = 1 / sqrt($inputSize);
        $limitW2 = 1 / sqrt($this->hiddenSize);

        $this->w1 = [];
        $this->b1 = [];
        for ($i = 0; $i < $this->hiddenSize; $i++) {
            $this->w1[$i] = [$this->randomWeight($limitW1), $this->randomWeight($limitW1)];
            $this->b1[$i] = 0.0;
        }

        $this->w2 = [];
        $this->b2 = [];
        foreach ($this->classes as $class) {
            $row = [];
            for ($i = 0; $i < $this->hiddenSize; $i++) {
                $row[] = $this->randomWeight($limitW2);
            }
            $this->w2[$class] = $row;
            $this->b2[$class] = 0.0;
        }
    }

    private function randomWeight(float $limit): float
    {
        // mt_rand даёт целые числа — превращаем в равномерное [-limit, +limit].
        return (mt_rand() / mt_getrandmax() * 2 - 1) * $limit;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $features
     * @param string[] $labels
     * @return array<int, float> история потерь (кросс-энтропия) по эпохам
     */
    public function train(array $features, array $labels, float $learningRate = 0.5, int $epochs = 3000): array
    {
        $n = count($features);
        $lossHistory = [];

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            // Накопители градиентов за эпоху (batch gradient descent — как и
            // у SoftmaxClassifier: усредняем градиент по всей выборке перед
            // шагом обновления, а не обновляем веса после каждого примера).
            $gradW1 = array_fill(0, $this->hiddenSize, [0.0, 0.0]);
            $gradB1 = array_fill(0, $this->hiddenSize, 0.0);
            $gradW2 = [];
            $gradB2 = [];
            foreach ($this->classes as $class) {
                $gradW2[$class] = array_fill(0, $this->hiddenSize, 0.0);
                $gradB2[$class] = 0.0;
            }

            $totalLoss = 0.0;

            for ($n_i = 0; $n_i < $n; $n_i++) {
                [$x1, $x2] = $features[$n_i];
                $trueClass = $labels[$n_i];

                // --- forward pass (с сохранением промежуточных активаций для backprop) ---
                [$a1, $probs] = $this->forward($x1, $x2);

                $totalLoss += -log(max($probs[$trueClass], 1e-12));

                // --- backward pass: выходной слой ---
                $dz2 = []; // dz2[class] = probs[class] - target[class]
                foreach ($this->classes as $class) {
                    $target = ($class === $trueClass) ? 1.0 : 0.0;
                    $dz2[$class] = $probs[$class] - $target;

                    for ($i = 0; $i < $this->hiddenSize; $i++) {
                        $gradW2[$class][$i] += $dz2[$class] * $a1[$i];
                    }
                    $gradB2[$class] += $dz2[$class];
                }

                // --- backward pass: скрытый слой (цепное правило через w2 и tanh') ---
                for ($i = 0; $i < $this->hiddenSize; $i++) {
                    $da1 = 0.0;
                    foreach ($this->classes as $class) {
                        $da1 += $this->w2[$class][$i] * $dz2[$class];
                    }
                    $dz1 = $da1 * (1 - $a1[$i] * $a1[$i]); // производная tanh

                    $gradW1[$i][0] += $dz1 * $x1;
                    $gradW1[$i][1] += $dz1 * $x2;
                    $gradB1[$i] += $dz1;
                }
            }

            // --- шаг градиентного спуска: усредняем по n примеров и вычитаем ---
            for ($i = 0; $i < $this->hiddenSize; $i++) {
                $this->w1[$i][0] -= $learningRate * $gradW1[$i][0] / $n;
                $this->w1[$i][1] -= $learningRate * $gradW1[$i][1] / $n;
                $this->b1[$i] -= $learningRate * $gradB1[$i] / $n;
            }
            foreach ($this->classes as $class) {
                for ($i = 0; $i < $this->hiddenSize; $i++) {
                    $this->w2[$class][$i] -= $learningRate * $gradW2[$class][$i] / $n;
                }
                $this->b2[$class] -= $learningRate * $gradB2[$class] / $n;
            }

            if ($epoch % 100 === 0 || $epoch === $epochs - 1) {
                $lossHistory[$epoch] = round($totalLoss / $n, 4);
            }
        }

        return $lossHistory;
    }

    /**
     * Один шаг SGD на ОДНОМ примере (в отличие от train(), который усредняет
     * градиент по всей выборке). Используется для "живого" дообучения:
     * пользователь в интерфейсе поправляет предсказание модели ("на самом деле
     * это bus, а не car") — и веса обновляются немедленно, без переобучения с
     * нуля. Та же математика backprop, что и в train(), просто на 1 примере
     * и с шагом обновления сразу после него (а не в конце эпохи).
     */
    public function trainOnExample(float $x1, float $x2, string $trueClass, float $learningRate = 0.3): void
    {
        [$a1, $probs] = $this->forward($x1, $x2);

        $dz2 = [];
        foreach ($this->classes as $class) {
            $target = ($class === $trueClass) ? 1.0 : 0.0;
            $dz2[$class] = $probs[$class] - $target;
        }

        // Скрытый слой: та же цепочка градиентов, что в train(), но применяем
        // обновление сразу (learningRate обычно берут поменьше, чем при батч-
        // обучении, чтобы один пример не "перекосил" уже обученную модель).
        for ($i = 0; $i < $this->hiddenSize; $i++) {
            $da1 = 0.0;
            foreach ($this->classes as $class) {
                $da1 += $this->w2[$class][$i] * $dz2[$class];
            }
            $dz1 = $da1 * (1 - $a1[$i] * $a1[$i]);

            $this->w1[$i][0] -= $learningRate * $dz1 * $x1;
            $this->w1[$i][1] -= $learningRate * $dz1 * $x2;
            $this->b1[$i] -= $learningRate * $dz1;
        }

        foreach ($this->classes as $class) {
            for ($i = 0; $i < $this->hiddenSize; $i++) {
                $this->w2[$class][$i] -= $learningRate * $dz2[$class] * $a1[$i];
            }
            $this->b2[$class] -= $learningRate * $dz2[$class];
        }
    }

    /**
     * Прямой проход сети целиком: скрытый слой (tanh) + выходной (softmax).
     *
     * @return array{0: float[], 1: array<string, float>} [активации скрытого слоя, вероятности классов]
     */
    private function forward(float $x1, float $x2): array
    {
        $a1 = [];
        for ($i = 0; $i < $this->hiddenSize; $i++) {
            $z = $this->w1[$i][0] * $x1 + $this->w1[$i][1] * $x2 + $this->b1[$i];
            $a1[$i] = tanh($z);
        }

        $scores = [];
        foreach ($this->classes as $class) {
            $z = $this->b2[$class];
            for ($i = 0; $i < $this->hiddenSize; $i++) {
                $z += $this->w2[$class][$i] * $a1[$i];
            }
            $scores[$class] = $z;
        }

        $max = max($scores); // вычитаем максимум для численной устойчивости exp()
        $exp = array_map(fn ($s) => exp($s - $max), $scores);
        $sum = array_sum($exp);

        $probs = [];
        foreach ($this->classes as $class) {
            $probs[$class] = $exp[$class] / $sum;
        }

        return [$a1, $probs];
    }

    /**
     * "Разбор" одного предсказания для интерфейса ("почему такой транспорт?"):
     * активации скрытого слоя + вклад каждого скрытого нейрона в счёт
     * выигравшего класса (w2[class][i] * a1[i], т.е. слагаемое суммы z2 из
     * forward pass до softmax). Не псевдо-объяснение задним числом — это
     * ровно те числа, которые реально складываются в предсказание.
     *
     * @return array{probs: array<string, float>, predicted: string,
     *               hidden_activations: float[],
     *               contributions: array<string, float>}
     */
    public function explain(float $x1, float $x2): array
    {
        [$a1, $probs] = $this->forward($x1, $x2);

        arsort($probs);
        $predicted = array_key_first($probs);

        $contributions = [];
        for ($i = 0; $i < $this->hiddenSize; $i++) {
            $contributions["neuron_{$i}"] = round($this->w2[$predicted][$i] * $a1[$i], 4);
        }

        return [
            'probs' => $probs,
            'predicted' => $predicted,
            'hidden_activations' => array_map(fn ($v) => round($v, 4), $a1),
            'contributions' => $contributions,
        ];
    }

    /**
     * @return array<string, float> вероятность по каждому классу (в сумме 1.0)
     */
    public function softmax(float $x1, float $x2): array
    {
        [, $probs] = $this->forward($x1, $x2);

        return $probs;
    }

    public function predict(float $x1, float $x2): string
    {
        $probs = $this->softmax($x1, $x2);
        arsort($probs);

        return array_key_first($probs);
    }

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

    public function getWeights(): array
    {
        return [
            'architecture' => 'mlp',
            'hidden_size' => $this->hiddenSize,
            'classes' => $this->classes,
            'w1' => $this->w1,
            'b1' => $this->b1,
            'w2' => $this->w2,
            'b2' => $this->b2,
        ];
    }

    public function setWeights(array $weights): void
    {
        $this->hiddenSize = $weights['hidden_size'];
        $this->classes = $weights['classes'];
        $this->w1 = $weights['w1'];
        $this->b1 = $weights['b1'];
        $this->w2 = $weights['w2'];
        $this->b2 = $weights['b2'];
    }
}
