<?php

namespace App\ML;

/**
 * Общий контракт для классификаторов транспорта.
 *
 * У нас в проекте теперь два классификатора с одной и той же задачей
 * (по [дистанция, число_точек] предсказать walk/car/bus), но разной
 * "силой": SoftmaxClassifier — линейная модель (мультиклассовая логистическая
 * регрессия), MLPClassifier — настоящая нейросеть со скрытым слоем и
 * backpropagation. TransportPredictor и bin/train_model.php работают с обеими
 * через этот интерфейс, не зная деталей реализации.
 */
interface ClassifierInterface
{
    /**
     * @param array<int, array{0: float, 1: float}> $features
     * @param string[] $labels
     * @return array<int, float> история потерь по эпохам (для графика обучения)
     */
    public function train(array $features, array $labels, float $learningRate, int $epochs): array;

    /**
     * Полный прямой проход (forward pass) сети: от входных признаков до
     * вероятностей классов на выходе. Название сохранено как `softmax()`
     * для совместимости с существующим кодом (TransportPredictor вызывает
     * именно этот метод) — у MLPClassifier это не только softmax на
     * выходном слое, а весь forward pass через скрытый слой.
     *
     * @return array<string, float> вероятность по каждому классу (в сумме 1.0)
     */
    public function softmax(float $x1, float $x2): array;

    public function predict(float $x1, float $x2): string;

    /**
     * @param array<int, array{0: float, 1: float}> $features
     * @param string[] $labels
     */
    public function accuracy(array $features, array $labels): float;

    /** @return array<string, mixed> */
    public function getWeights(): array;

    /** @param array<string, mixed> $weights */
    public function setWeights(array $weights): void;

    /**
     * Разбор одного предсказания "по числам": какие внутренние величины
     * реально формируют итоговое решение. У MLP — активации скрытого слоя
     * и их вклад в счёт класса; у Softmax — вклад каждого входного признака.
     *
     * @return array{probs: array<string, float>, predicted: string, contributions: array<string, float>}
     */
    public function explain(float $x1, float $x2): array;
}
