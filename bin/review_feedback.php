<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\ML\Dataset;
use App\ML\FeatureEncoder;
use App\ML\FeedbackStore;
use App\ML\MLPClassifier;
use App\ML\ModelEvaluator;
use App\ML\ModelRegistry;
use App\Support\RuntimeStorage;

$promote = in_array('--promote', $argv, true);
$listOnly = in_array('--list', $argv, true);
$minimum = 30;
$approvedPath = null;
foreach ($argv as $argument) {
    if (preg_match('/^--min=(\d+)$/', $argument, $matches) === 1) {
        $minimum = max(10, (int) $matches[1]);
    }
    if (preg_match('/^--approved=(.+)$/', $argument, $matches) === 1) {
        $approvedPath = trim($matches[1]);
    }
}

$store = new FeedbackStore(RuntimeStorage::path('ml_feedback.ndjson'));
$queued = $store->events();
echo 'Queued corrections: ' . count($queued) . "\n";

if ($listOnly) {
    echo json_encode($queued, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($approvedPath === null || !is_file($approvedPath)) {
    fwrite(STDERR, "A reviewed allow-list is required.\n");
    fwrite(STDERR, "1. php bin/review_feedback.php --list\n");
    fwrite(STDERR, "2. Put approved hashed event_id values into a text file, one per line.\n");
    fwrite(STDERR, "3. php bin/review_feedback.php --approved=/path/approved.txt [--promote]\n");
    exit(2);
}

$approvedIds = [];
foreach (file($approvedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $eventId = trim($line);
    if (preg_match('/^[a-f0-9]{64}$/', $eventId) === 1) {
        $approvedIds[$eventId] = true;
    }
}

$reviewed = array_values(array_filter(
    $queued,
    static fn (array $event): bool => isset($approvedIds[(string) ($event['event_id'] ?? '')])
));
$accepted = [];
$rejected = 0;
$clusterCounts = [];
foreach ($reviewed as $event) {
    $distance = $event['distance_km'] ?? null;
    $stops = $event['stops'] ?? null;
    $correctLabel = $event['correct_label'] ?? null;
    $predictedLabel = $event['predicted_label'] ?? null;
    $eventId = $event['event_id'] ?? null;
    if (
        !is_numeric($distance)
        || (float) $distance < 0.2
        || (float) $distance > 1500
        || !is_numeric($stops)
        || (int) $stops < 2
        || (int) $stops > 12
        || !in_array($correctLabel, Dataset::CLASSES, true)
        || !in_array($predictedLabel, Dataset::CLASSES, true)
        || $correctLabel === $predictedLabel
        || !is_string($eventId)
        || preg_match('/^[a-f0-9]{64}$/', $eventId) !== 1
    ) {
        $rejected++;
        continue;
    }

    // A flood of near-identical corrections cannot dominate a candidate.
    $cluster = round((float) $distance, 1) . ':' . (int) $stops . ':' . $correctLabel;
    $clusterCounts[$cluster] = ($clusterCounts[$cluster] ?? 0) + 1;
    if ($clusterCounts[$cluster] > 3) {
        $rejected++;
        continue;
    }
    $accepted[] = $event;
}

$classCounts = array_fill_keys(Dataset::CLASSES, 0);
foreach ($accepted as $event) {
    $classCounts[(string) $event['correct_label']]++;
}
$representedClasses = count(array_filter($classCounts, static fn (int $count): bool => $count > 0));
$largestClassShare = $accepted === [] ? 1.0 : max($classCounts) / count($accepted);

echo 'Approved: ' . count($reviewed) . '; accepted after anomaly checks: ' . count($accepted) . '; rejected: ' . $rejected . "\n";
echo 'Target class balance: ' . json_encode($classCounts, JSON_UNESCAPED_SLASHES) . "\n";
if (count($accepted) < $minimum || $representedClasses < 2 || $largestClassShare > 0.85) {
    fwrite(STDERR, "Candidate not trained: require at least {$minimum} accepted events, two target classes, and no class above 85%.\n");
    exit(2);
}

$rows = (new Dataset(seed: 42))->generate(samples: 600);
mt_srand(42);
shuffle($rows);
$splitAt = (int) (count($rows) * 0.8);
$trainRows = array_slice($rows, 0, $splitAt);
$holdoutRows = array_slice($rows, $splitAt);
$validationRows = array_slice($holdoutRows, 0, (int) (count($holdoutRows) / 2));

/** @param array<int, array{distance: float, stops: int, label: string}> $source @return array{0: array<int, array{0: float, 1: float}>, 1: string[]} */
$encode = static function (array $source): array {
    $features = [];
    $labels = [];
    foreach ($source as $row) {
        $features[] = [
            FeatureEncoder::distanceFeature((float) $row['distance']),
            FeatureEncoder::stopsFeature((int) $row['stops']),
        ];
        $labels[] = (string) $row['label'];
    }

    return [$features, $labels];
};

[$trainX, $trainY] = $encode($trainRows);
[$validationX, $validationY] = $encode($validationRows);
foreach ($accepted as $event) {
    // Three copies give reviewed feedback a voice without drowning out the
    // stable baseline dataset.
    for ($weight = 0; $weight < 3; $weight++) {
        $trainX[] = [
            FeatureEncoder::distanceFeature((float) $event['distance_km']),
            FeatureEncoder::stopsFeature((int) $event['stops']),
        ];
        $trainY[] = (string) $event['correct_label'];
    }
}

$weightsPath = RuntimeStorage::modelWeightsPath();
$weights = json_decode((string) file_get_contents($weightsPath), true);
if (!is_array($weights)) {
    throw new RuntimeException('Active MLP weights are invalid.');
}
$baseline = new MLPClassifier(Dataset::CLASSES);
$baseline->setWeights($weights);
$candidate = new MLPClassifier(Dataset::CLASSES);
$candidate->setWeights($weights);
$candidate->train($trainX, $trainY, learningRate: 0.05, epochs: 250);

$evaluator = new ModelEvaluator();
$before = $evaluator->evaluateProbabilities($baseline, $validationX, $validationY, Dataset::CLASSES);
$after = $evaluator->evaluateProbabilities($candidate, $validationX, $validationY, Dataset::CLASSES);
$perClassPass = true;
foreach (Dataset::CLASSES as $class) {
    if (($after['per_class'][$class]['f1'] ?? 0.0) + 0.03 < ($before['per_class'][$class]['f1'] ?? 0.0)) {
        $perClassPass = false;
    }
}
$passes = $after['macro_f1'] >= $before['macro_f1']
    && $after['log_loss'] <= $before['log_loss'] + 0.01
    && $perClassPass;

echo 'Validation baseline macro-F1/log-loss: ' . $before['macro_f1'] . ' / ' . $before['log_loss'] . "\n";
echo 'Validation candidate macro-F1/log-loss: ' . $after['macro_f1'] . ' / ' . $after['log_loss'] . "\n";
echo 'Per-class regression guard: ' . ($perClassPass ? 'PASS' : 'REJECT') . "\n";
echo 'Release gate: ' . ($passes ? 'PASS' : 'REJECT') . "\n";

if (!$passes || !$promote) {
    echo $passes
        ? "Dry run only. Re-run with the same --approved file and --promote after reviewing this report.\n"
        : "Production weights were not changed.\n";
    exit($passes ? 0 : 3);
}

$registry = new ModelRegistry(RuntimeStorage::path('model_versions'));
$version = $registry->promote($candidate->getWeights(), [
    'macro_f1' => $after['macro_f1'],
    'log_loss' => $after['log_loss'],
    'feedback_count' => count($accepted),
    'class_counts' => $classCounts,
    'gate_split' => 'validation',
], RuntimeStorage::path('mlp_weights.json'));

$eventIds = array_map(static fn (array $event): string => (string) $event['event_id'], $accepted);
$archivePath = RuntimeStorage::path('feedback_archive/' . gmdate('Ymd-His') . '-' . $version . '.ndjson');
$archive = $store->archiveSelected($eventIds, $archivePath);
echo "Promoted {$version}; archived {$archive['archived']} reviewed events; {$archive['remaining']} remain pending.\n";
echo "Rollback remains available through bin/model_admin.php.\n";
