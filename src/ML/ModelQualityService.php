<?php

namespace App\ML;

/**
 * Reproducible read-only model report for the public Model Card. It evaluates
 * the checked-in weights on the same deterministic holdout split used by the
 * training command, so the dashboard never reports training accuracy as if it
 * were unseen-data quality.
 */
final class ModelQualityService
{
    public function __construct(
        private string $mlpWeightsPath,
        private string $softmaxWeightsPath,
        private ?string $feedbackPath = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $rows = (new Dataset(seed: 42))->generate(samples: 600);
        mt_srand(42);
        shuffle($rows);
        $splitAt = (int) (count($rows) * 0.8);
        $trainRows = array_slice($rows, 0, $splitAt);
        $holdoutRows = array_slice($rows, $splitAt);
        $validationRows = array_slice($holdoutRows, 0, (int) (count($holdoutRows) / 2));
        $testRows = array_slice($holdoutRows, count($validationRows));
        [$validationX, $validationY] = $this->encodeRows($validationRows);
        [$testX, $testY] = $this->encodeRows($testRows);

        $mlp = new MLPClassifier(Dataset::CLASSES);
        $mlp->setWeights($this->loadWeights($this->mlpWeightsPath));
        $softmax = new SoftmaxClassifier(Dataset::CLASSES);
        $softmax->setWeights($this->loadWeights($this->softmaxWeightsPath));
        $evaluator = new ModelEvaluator();

        $mlpValidationMetrics = $evaluator->evaluateProbabilities($mlp, $validationX, $validationY, Dataset::CLASSES);
        $softmaxValidationMetrics = $evaluator->evaluateProbabilities($softmax, $validationX, $validationY, Dataset::CLASSES);
        $mlpMetrics = $evaluator->evaluateProbabilities($mlp, $testX, $testY, Dataset::CLASSES);
        $softmaxMetrics = $evaluator->evaluateProbabilities($softmax, $testX, $testY, Dataset::CLASSES);
        $training = $this->trainingReport();
        $trainingVersions = is_array($training['model_versions'] ?? null) ? $training['model_versions'] : [];
        $training['matches_active_model'] = ($trainingVersions['mlp'] ?? null)
            === $this->versionFor($this->mlpWeightsPath, 'mlp')
            && ($trainingVersions['softmax'] ?? null) === $this->versionFor($this->softmaxWeightsPath, 'softmax');
        $trainedAt = is_string($training['generated_at'] ?? null) ? $training['generated_at'] : null;
        $feedbackCount = $this->feedbackPath !== null
            ? count((new FeedbackStore($this->feedbackPath))->events())
            : 0;

        return [
            'generated_at' => gmdate('c'),
            'dataset' => [
                'name' => 'synthetic_transport_seed_42',
                'source' => 'synthetic_rule_with_8_percent_label_noise',
                'seed' => 42,
                'total_samples' => count($rows),
                'train_samples' => count($trainRows),
                'holdout_samples' => count($holdoutRows),
                'validation_samples' => count($validationRows),
                'test_samples' => count($testRows),
                'features' => ['distance_km', 'stops'],
                'classes' => Dataset::CLASSES,
                'validation_class_balance' => array_count_values($validationY),
                'test_class_balance' => array_count_values($testY),
                'contains_personal_data' => false,
            ],
            'models' => [
                'mlp' => [
                    'version' => $this->versionFor($this->mlpWeightsPath, 'mlp'),
                    'architecture' => '2 → 8 tanh → 3 softmax',
                    'parameters' => $this->parameterCount($mlp->getWeights()),
                    'metrics' => $mlpMetrics,
                    'validation_metrics' => $mlpValidationMetrics,
                ],
                'softmax' => [
                    'version' => $this->versionFor($this->softmaxWeightsPath, 'softmax'),
                    'architecture' => '2 → 3 softmax',
                    'parameters' => $this->parameterCount($softmax->getWeights()),
                    'metrics' => $softmaxMetrics,
                    'validation_metrics' => $softmaxValidationMetrics,
                ],
            ],
            'training' => $training,
            'cross_validation' => is_array($training['cross_validation'] ?? null)
                ? $training['cross_validation']
                : [],
            'model_card' => [
                'name' => 'Smart Route Transport Classifier',
                'version' => $this->versionFor($this->mlpWeightsPath, 'mlp'),
                'trained_at' => $trainedAt,
                'purpose' => 'Educational recommendation of a typical transport class for a route.',
                'purpose_ru' => 'Учебная рекомендация типичного вида транспорта для выбранного маршрута.',
                'intended_uses' => [
                    'Explainable ML demonstration',
                    'Comparison of nonlinear MLP and linear softmax baseline',
                    'Input for a transparent multi-objective transport ranker',
                ],
                'intended_uses_ru' => [
                    'Демонстрация объяснимого машинного обучения',
                    'Сравнение нелинейной MLP и линейной Softmax-модели',
                    'Входной сигнал для прозрачного многокритериального рейтинга транспорта',
                ],
                'out_of_scope' => [
                    'Safety-critical navigation',
                    'Guaranteed public-transit availability',
                    'Real-time traffic or ticket availability',
                    'Automated decisions about people',
                ],
                'out_of_scope_ru' => [
                    'Критически важная навигация',
                    'Гарантия доступности общественного транспорта',
                    'Трафик и наличие билетов в реальном времени',
                    'Автоматизированные решения о людях',
                ],
                'limitations' => [
                    'The training set is synthetic, not real user history.',
                    'The classifier uses only distance and stop count.',
                    'Softmax percentages are model scores and require calibration before being interpreted as guarantees.',
                    'Road distance distribution may differ from the synthetic training distribution.',
                ],
                'limitations_ru' => [
                    'Обучающая выборка синтетическая, а не история реальных пользователей.',
                    'Классификатор использует только дистанцию и число остановок.',
                    'Проценты Softmax являются оценками модели и не должны восприниматься как гарантия.',
                    'Распределение дорожных дистанций может отличаться от синтетической обучающей выборки.',
                ],
                'privacy' => 'Evaluation and feedback use anonymous numeric features only; route labels and addresses are excluded.',
                'privacy_ru' => 'Оценка и отзывы используют только обезличенные числовые признаки; адреса и подписи маршрута исключены.',
            ],
            'feedback' => [
                'queued_corrections' => $feedbackCount,
                'contains_addresses' => false,
                'deduplicated_by_event_id' => true,
                'persistence' => 'runtime_storage',
            ],
            'release_policy' => [
                'feedback_is_queued' => true,
                'single_feedback_mutates_production' => false,
                'candidate_requires_holdout_improvement' => true,
                'rollback_supported' => true,
            ],
        ];
    }

    /** @param array<int, array{distance: float, stops: int, label: string}> $rows @return array{0: array<int, array{0: float, 1: float}>, 1: string[]} */
    private function encodeRows(array $rows): array
    {
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
    }

    /** @return array<string, mixed> */
    private function loadWeights(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Model weights are unavailable.');
        }
        $weights = json_decode((string) file_get_contents($path), true);
        if (!is_array($weights)) {
            throw new \RuntimeException('Model weights are invalid.');
        }

        return $weights;
    }

    /** @param array<string, mixed> $weights */
    private function parameterCount(array $weights): int
    {
        return $this->countNumericValues($weights);
    }

    private function countNumericValues(mixed $value): int
    {
        if (!is_array($value)) {
            return is_numeric($value) ? 1 : 0;
        }

        $count = 0;
        foreach ($value as $nested) {
            $count += $this->countNumericValues($nested);
        }

        return $count;
    }

    private function versionFor(string $path, string $type): string
    {
        $hash = is_file($path) ? hash_file('sha256', $path) : false;

        return $type . '-' . substr($hash !== false ? $hash : 'unknown00000000', 0, 8);
    }

    /** @return array<string, mixed> */
    private function trainingReport(): array
    {
        $path = __DIR__ . '/training_report.json';
        if (!is_file($path)) {
            return [];
        }

        $report = json_decode((string) file_get_contents($path), true);

        return is_array($report) ? $report : [];
    }
}
