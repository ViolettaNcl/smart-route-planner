<?php

namespace App\ML;

/**
 * Инференс на уже обученной модели: загружает веса, сохранённые
 * bin/train_model.php, и по дистанции/числу точек маршрута предсказывает
 * подходящий транспорт вместе с "уверенностью" модели (вероятностью класса).
 *
 * По умолчанию используется MLPClassifier (нейросеть со скрытым слоем,
 * src/ML/mlp_weights.json) — он немного точнее на валидации, чем линейная
 * softmax-регрессия, и умеет проводить нелинейную границу решений. Если
 * файл весов MLP не найден (например, в форке проекта, где ещё не
 * запускали bin/train_model.php после обновления), предиктор аккуратно
 * откатывается на старый SoftmaxClassifier (src/ML/model_weights.json) —
 * приложение не падает, просто использует более простую модель.
 */
class TransportPredictor
{
    private const LABELS_RU = [
        'walk' => 'пешком',
        'car' => 'авто',
        'bus' => 'общественный транспорт',
    ];

    private ClassifierInterface $model;

    private string $modelType;

    public function __construct(string $mlpWeightsPath, ?string $softmaxWeightsPath = null, ?string $forceVariant = null)
    {
        // A/B-тест (см. App\ML\ABTestStats): фронтенд может явно запросить
        // конкретный вариант модели для этого визита — используется вместо
        // "мягкого приоритета MLP" по умолчанию.
        if ($forceVariant === 'softmax' && $softmaxWeightsPath !== null && is_file($softmaxWeightsPath)) {
            $weights = json_decode(file_get_contents($softmaxWeightsPath), true);
            $this->model = new SoftmaxClassifier(Dataset::CLASSES);
            $this->model->setWeights($weights);
            $this->modelType = 'softmax';

            return;
        }

        if (is_file($mlpWeightsPath)) {
            $weights = json_decode(file_get_contents($mlpWeightsPath), true);
            $this->model = new MLPClassifier(Dataset::CLASSES);
            $this->model->setWeights($weights);
            $this->modelType = 'mlp';

            return;
        }

        if ($softmaxWeightsPath !== null && is_file($softmaxWeightsPath)) {
            $weights = json_decode(file_get_contents($softmaxWeightsPath), true);
            $this->model = new SoftmaxClassifier(Dataset::CLASSES);
            $this->model->setWeights($weights);
            $this->modelType = 'softmax';

            return;
        }

        throw new \RuntimeException(
            "Файлы весов модели не найдены ({$mlpWeightsPath}). " .
            'Запустите php bin/train_model.php, чтобы обучить и сохранить модель.'
        );
    }

    /**
     * @return array{mode: string, mode_ru: string, confidence: float, probabilities: array<string, float>, model: string}
     */
    public function predict(float $distanceKm, int $stopsCount): array
    {
        $x1 = FeatureEncoder::distanceFeature($distanceKm);
        $x2 = FeatureEncoder::stopsFeature($stopsCount);

        $probs = $this->model->softmax($x1, $x2);
        arsort($probs);
        $best = array_key_first($probs);

        return [
            'mode' => $best,
            'mode_ru' => self::LABELS_RU[$best] ?? $best,
            'confidence' => round($probs[$best] * 100, 1),
            'probabilities' => array_map(fn ($p) => round($p * 100, 1), $probs),
            'model' => $this->modelType, // 'mlp' или 'softmax' — какая модель реально ответила
        ];
    }

    public function modelType(): string
    {
        return $this->modelType;
    }

    public function model(): ClassifierInterface
    {
        return $this->model;
    }

    /**
     * @return array{probs: array<string, float>, predicted: string, contributions: array<string, float>}
     */
    public function explain(float $distanceKm, int $stopsCount): array
    {
        $x1 = FeatureEncoder::distanceFeature($distanceKm);
        $x2 = FeatureEncoder::stopsFeature($stopsCount);

        return $this->model->explain($x1, $x2);
    }

    /**
     * "Живое" дообучение на одном примере — работает только для MLP (у него
     * есть скрытый слой, есть чему учиться отдельно от полного батч-обучения).
     * Для softmax-fallback возвращает false — там для честного обновления
     * веса пришлось бы переобучать всю модель заново, это не мгновенная
     * операция и не то, что ожидается от кнопки "поправить прогноз" в UI.
     */
    public function learnFromExample(float $distanceKm, int $stopsCount, string $correctLabel, string $weightsPath): bool
    {
        if (!($this->model instanceof MLPClassifier)) {
            return false;
        }

        $x1 = FeatureEncoder::distanceFeature($distanceKm);
        $x2 = FeatureEncoder::stopsFeature($stopsCount);

        $this->model->trainOnExample($x1, $x2, $correctLabel);
        file_put_contents($weightsPath, json_encode($this->model->getWeights(), JSON_PRETTY_PRINT));

        return true;
    }
}
