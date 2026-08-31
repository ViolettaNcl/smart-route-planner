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

    private string $modelVersion;

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
            $this->modelVersion = $this->versionFor($softmaxWeightsPath, 'softmax');

            return;
        }

        if (is_file($mlpWeightsPath)) {
            $weights = json_decode(file_get_contents($mlpWeightsPath), true);
            $this->model = new MLPClassifier(Dataset::CLASSES);
            $this->model->setWeights($weights);
            $this->modelType = 'mlp';
            $this->modelVersion = $this->versionFor($mlpWeightsPath, 'mlp');

            return;
        }

        if ($softmaxWeightsPath !== null && is_file($softmaxWeightsPath)) {
            $weights = json_decode(file_get_contents($softmaxWeightsPath), true);
            $this->model = new SoftmaxClassifier(Dataset::CLASSES);
            $this->model->setWeights($weights);
            $this->modelType = 'softmax';
            $this->modelVersion = $this->versionFor($softmaxWeightsPath, 'softmax');

            return;
        }

        throw new \RuntimeException(
            "Файлы весов модели не найдены ({$mlpWeightsPath}). " .
            'Запустите php bin/train_model.php, чтобы обучить и сохранить модель.'
        );
    }

    /**
     * @return array{mode: string, mode_ru: string, confidence: float,
     *               probabilities: array<string, float>, model: string,
     *               model_version: string, margin: float, certainty: string}
     */
    public function predict(float $distanceKm, int $stopsCount): array
    {
        $x1 = FeatureEncoder::distanceFeature($distanceKm);
        $x2 = FeatureEncoder::stopsFeature($stopsCount);

        $probs = $this->model->softmax($x1, $x2);
        arsort($probs);
        $best = array_key_first($probs);
        $ranked = array_values($probs);
        $margin = (($ranked[0] ?? 0.0) - ($ranked[1] ?? 0.0)) * 100;

        return [
            'mode' => $best,
            'mode_ru' => self::LABELS_RU[$best] ?? $best,
            'confidence' => round($probs[$best] * 100, 1),
            'probabilities' => array_map(fn ($p) => round($p * 100, 1), $probs),
            'model' => $this->modelType, // 'mlp' или 'softmax' — какая модель реально ответила
            'model_version' => $this->modelVersion,
            'margin' => round($margin, 1),
            'certainty' => match (true) {
                $margin < 10 => 'ambiguous',
                $margin < 25 => 'moderate',
                default => 'stable',
            },
        ];
    }

    public function modelType(): string
    {
        return $this->modelType;
    }

    public function modelVersion(): string
    {
        return $this->modelVersion;
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

    private function versionFor(string $weightsPath, string $modelType): string
    {
        $hash = is_file($weightsPath) ? hash_file('sha256', $weightsPath) : false;

        return $modelType . '-' . substr($hash !== false ? $hash : 'unknown00000000', 0, 8);
    }
}
