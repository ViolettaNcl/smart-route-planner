<?php

namespace App\ML;

/**
 * Read-only explainability layer used by ML Lab 2.0.
 *
 * It intentionally works only with anonymous numeric route features. Labels,
 * addresses and coordinates never enter this service or its API response.
 */
final class ModelInsightService
{
    private const MODE_LABELS_RU = [
        'walk' => 'пешком',
        'car' => 'авто',
        'bus' => 'общественный транспорт',
    ];

    private const MODE_SPEED_KMH = ['walk' => 5.0, 'car' => 70.0, 'bus' => 55.0];
    private const MODE_COST_PER_KM = ['walk' => 0.0, 'car' => 4.8, 'bus' => 3.0];
    private const MODE_CO2_KG_PER_KM = ['walk' => 0.0, 'car' => 0.12, 'bus' => 0.068];

    public function __construct(
        private string $mlpWeightsPath,
        private string $softmaxWeightsPath,
    ) {
    }

    /** @return array<string, mixed> */
    public function analyze(
        float $distanceKm,
        int $stops,
        string $priority = 'balanced',
        string $activeModel = 'mlp'
    ): array {
        $distanceKm = max(0.2, min(1500.0, $distanceKm));
        $stops = max(2, min(12, $stops));
        $priority = in_array($priority, ['balanced', 'fast', 'cheap', 'eco'], true)
            ? $priority
            : 'balanced';

        $mlp = new TransportPredictor($this->mlpWeightsPath, $this->softmaxWeightsPath, 'mlp');
        $softmax = new TransportPredictor($this->mlpWeightsPath, $this->softmaxWeightsPath, 'softmax');
        $mlpPrediction = $mlp->predict($distanceKm, $stops);
        $softmaxPrediction = $softmax->predict($distanceKm, $stops);
        $activeModel = $activeModel === 'softmax' ? 'softmax' : 'mlp';
        $active = $activeModel === 'softmax' ? $softmax : $mlp;
        $activePrediction = $activeModel === 'softmax' ? $softmaxPrediction : $mlpPrediction;
        $rawExplanation = $active->explain($distanceKm, $stops);
        $mlpExplanation = $activeModel === 'mlp' ? $rawExplanation : $mlp->explain($distanceKm, $stops);

        return [
            'input' => [
                'distance_km' => round($distanceKm, 2),
                'stops' => $stops,
                'normalized' => [
                    'distance' => round(FeatureEncoder::distanceFeature($distanceKm), 4),
                    'stops' => round(FeatureEncoder::stopsFeature($stops), 4),
                ],
            ],
            'active_model' => $activeModel,
            'prediction' => $activePrediction,
            'comparison' => [
                'agreement' => $mlpPrediction['mode'] === $softmaxPrediction['mode'],
                'models' => [
                    'mlp' => $mlpPrediction,
                    'softmax' => $softmaxPrediction,
                ],
            ],
            'feature_influence' => $this->featureInfluence($active, $activePrediction['mode'], $distanceKm, $stops),
            'counterfactuals' => $this->counterfactuals($active, $activePrediction['mode'], $distanceKm, $stops),
            'nearest_examples' => $this->nearestExamples($distanceKm, $stops),
            'ranking' => [
                'method' => 'hybrid_probability_utility',
                'priority' => $priority,
                'options' => $this->rankTransportOptions($activePrediction['probabilities'], $distanceKm, $priority),
            ],
            'network' => [
                'architecture' => '2 → 8 tanh → 3 softmax',
                'inputs' => ['distance', 'stops'],
                'outputs' => Dataset::CLASSES,
                'hidden_activations' => $mlpExplanation['hidden_activations'] ?? [],
                'hidden_contributions' => $mlpExplanation['contributions'],
                'note' => 'Hidden-neuron contributions are exact terms in the winning-class logit, not causal effects.',
            ],
            'privacy' => [
                'anonymous_features_only' => true,
                'addresses_processed' => false,
            ],
        ];
    }

    /**
     * Local sensitivity is easier to explain to people than raw hidden-neuron
     * activations. Each feature is perturbed while the other stays fixed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function featureInfluence(
        TransportPredictor $predictor,
        string $winningMode,
        float $distanceKm,
        int $stops
    ): array {
        $distanceLow = max(0.2, $distanceKm * 0.8);
        $distanceHigh = min(1500.0, $distanceKm * 1.2);
        $stopsLow = max(2, $stops - 1);
        $stopsHigh = min(12, $stops + 1);

        $distanceLowProbability = $this->probability($predictor, $winningMode, $distanceLow, $stops);
        $distanceHighProbability = $this->probability($predictor, $winningMode, $distanceHigh, $stops);
        $stopsLowProbability = $this->probability($predictor, $winningMode, $distanceKm, $stopsLow);
        $stopsHighProbability = $this->probability($predictor, $winningMode, $distanceKm, $stopsHigh);

        return [
            $this->influenceRow(
                'distance',
                round($distanceKm, 2),
                round($distanceLow, 2),
                round($distanceHigh, 2),
                $distanceLowProbability,
                $distanceHighProbability
            ),
            $this->influenceRow(
                'stops',
                $stops,
                $stopsLow,
                $stopsHigh,
                $stopsLowProbability,
                $stopsHighProbability
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function influenceRow(
        string $feature,
        float|int $current,
        float|int $lowerValue,
        float|int $upperValue,
        float $lowerProbability,
        float $upperProbability
    ): array {
        $impact = ($upperProbability - $lowerProbability) * 100;

        return [
            'feature' => $feature,
            'current' => $current,
            'lower' => ['value' => $lowerValue, 'probability' => round($lowerProbability * 100, 1)],
            'upper' => ['value' => $upperValue, 'probability' => round($upperProbability * 100, 1)],
            'impact_pp' => round($impact, 1),
            'direction' => abs($impact) < 1.0 ? 'neutral' : ($impact > 0 ? 'higher_supports' : 'lower_supports'),
            'method' => 'local_perturbation',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function counterfactuals(
        TransportPredictor $predictor,
        string $currentMode,
        float $distanceKm,
        int $stops
    ): array {
        $candidates = [];
        $closestDistance = null;
        $closestDistanceScore = INF;
        $logMin = log(0.2);
        $logMax = log(1500.0);

        for ($i = 0; $i < 240; $i++) {
            $candidateDistance = exp($logMin + ($logMax - $logMin) * $i / 239);
            $prediction = $predictor->predict($candidateDistance, $stops);
            if ($prediction['mode'] === $currentMode) {
                continue;
            }
            $score = abs(log($candidateDistance / max($distanceKm, 0.2)));
            if ($score < $closestDistanceScore) {
                $closestDistanceScore = $score;
                $closestDistance = [
                    'feature' => 'distance',
                    'value' => round($candidateDistance, 1),
                    'delta' => round($candidateDistance - $distanceKm, 1),
                    'mode' => $prediction['mode'],
                    'probability' => $prediction['confidence'],
                ];
            }
        }

        if ($closestDistance !== null) {
            $candidates[] = $closestDistance;
        }

        $closestStops = null;
        foreach (range(2, 12) as $candidateStops) {
            if ($candidateStops === $stops) {
                continue;
            }
            $prediction = $predictor->predict($distanceKm, $candidateStops);
            if ($prediction['mode'] === $currentMode) {
                continue;
            }
            $candidate = [
                'feature' => 'stops',
                'value' => $candidateStops,
                'delta' => $candidateStops - $stops,
                'mode' => $prediction['mode'],
                'probability' => $prediction['confidence'],
            ];
            if ($closestStops === null || abs($candidate['delta']) < abs($closestStops['delta'])) {
                $closestStops = $candidate;
            }
        }
        if ($closestStops !== null) {
            $candidates[] = $closestStops;
        }

        return $candidates;
    }

    /** @return array<int, array{distance_km: float, stops: int, label: string, similarity: float}> */
    private function nearestExamples(float $distanceKm, int $stops): array
    {
        $rows = (new Dataset(seed: 42))->generate(samples: 600);
        $targetDistance = FeatureEncoder::distanceFeature($distanceKm);
        $targetStops = FeatureEncoder::stopsFeature($stops);

        foreach ($rows as &$row) {
            $distanceDelta = FeatureEncoder::distanceFeature($row['distance']) - $targetDistance;
            $stopsDelta = FeatureEncoder::stopsFeature($row['stops']) - $targetStops;
            $row['_distance'] = sqrt($distanceDelta ** 2 + $stopsDelta ** 2);
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => $a['_distance'] <=> $b['_distance']);

        return array_map(
            static fn (array $row): array => [
                'distance_km' => round((float) $row['distance'], 1),
                'stops' => (int) $row['stops'],
                'label' => (string) $row['label'],
                'similarity' => round(max(0.0, 1.0 - (float) $row['_distance']) * 100, 1),
            ],
            array_slice($rows, 0, 5)
        );
    }

    /**
     * The classifier answers "what looks typical". This transparent utility
     * ranker adds practical time/cost/CO2 trade-offs without pretending those
     * extra signals were inputs to the two-feature neural network.
     *
     * @param array<string, float> $probabilities
     * @return array<int, array<string, mixed>>
     */
    private function rankTransportOptions(array $probabilities, float $distanceKm, string $priority): array
    {
        $weights = match ($priority) {
            'fast' => ['model' => 0.25, 'time' => 0.55, 'cost' => 0.10, 'co2' => 0.10],
            'cheap' => ['model' => 0.25, 'time' => 0.10, 'cost' => 0.55, 'co2' => 0.10],
            'eco' => ['model' => 0.25, 'time' => 0.10, 'cost' => 0.10, 'co2' => 0.55],
            default => ['model' => 0.45, 'time' => 0.25, 'cost' => 0.15, 'co2' => 0.15],
        };

        /** @var array<int, array{mode: string, mode_ru: string, model_probability: float, duration_min: float, cost_rub: float, co2_kg: float, viable: bool}> $options */
        $options = [];
        foreach (Dataset::CLASSES as $mode) {
            $options[$mode] = [
                'mode' => $mode,
                'mode_ru' => self::MODE_LABELS_RU[$mode],
                'model_probability' => (float) ($probabilities[$mode] ?? 0.0),
                'duration_min' => round($distanceKm / self::MODE_SPEED_KMH[$mode] * 60),
                'cost_rub' => round($distanceKm * self::MODE_COST_PER_KM[$mode]),
                'co2_kg' => round($distanceKm * self::MODE_CO2_KG_PER_KM[$mode], 1),
                'viable' => !($mode === 'walk' && $distanceKm > 80.0),
            ];
        }

        $durationValues = array_map(static fn (array $option): float => $option['duration_min'], $options);
        $costValues = array_map(static fn (array $option): float => $option['cost_rub'], $options);
        $co2Values = array_map(static fn (array $option): float => $option['co2_kg'], $options);

        /** @var array<int, array{mode: string, mode_ru: string, model_probability: float, duration_min: float, cost_rub: float, co2_kg: float, viable: bool, score: float, reason_codes: string[]}> $scoredOptions */
        $scoredOptions = [];
        foreach ($options as $option) {
            $timeScore = $this->inverseNormalized($option['duration_min'], $durationValues);
            $costScore = $this->inverseNormalized($option['cost_rub'], $costValues);
            $co2Score = $this->inverseNormalized($option['co2_kg'], $co2Values);
            $score = $weights['model'] * ($option['model_probability'] / 100)
                + $weights['time'] * $timeScore
                + $weights['cost'] * $costScore
                + $weights['co2'] * $co2Score;
            if (!$option['viable']) {
                $score *= 0.12;
            }
            $option['score'] = round($score * 100, 1);
            $option['reason_codes'] = $this->reasonCodes($option, $priority);
            $scoredOptions[] = $option;
        }

        usort($scoredOptions, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        foreach ($scoredOptions as $index => &$option) {
            $option['rank'] = $index + 1;
        }
        unset($option);

        return $scoredOptions;
    }

    /** @param float[] $values */
    private function inverseNormalized(float $value, array $values): float
    {
        $minimum = min($values);
        $range = max($values) - $minimum;

        return $range > 0 ? 1.0 - (($value - $minimum) / $range) : 1.0;
    }

    /**
     * @param array<string, mixed> $option
     * @return string[]
     */
    private function reasonCodes(array $option, string $priority): array
    {
        $reasons = ['model_probability'];
        if (!$option['viable']) {
            $reasons[] = 'distance_limit';
        } elseif ($priority === 'fast') {
            $reasons[] = 'travel_time';
        } elseif ($priority === 'cheap') {
            $reasons[] = 'trip_cost';
        } elseif ($priority === 'eco') {
            $reasons[] = 'emissions';
        } else {
            $reasons[] = 'balanced_tradeoff';
        }

        return $reasons;
    }

    private function probability(
        TransportPredictor $predictor,
        string $mode,
        float $distanceKm,
        int $stops
    ): float {
        $probabilities = $predictor->model()->softmax(
            FeatureEncoder::distanceFeature($distanceKm),
            FeatureEncoder::stopsFeature($stops)
        );

        return (float) ($probabilities[$mode] ?? 0.0);
    }
}
