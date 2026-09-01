<?php

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/TestReporter.php';
require __DIR__ . '/Fakes/FakeGeocoder.php';
require __DIR__ . '/Fakes/FakeRoadRouter.php';
require __DIR__ . '/HaversineCalculatorTest.php';
require __DIR__ . '/RouteOptimizerTest.php';
require __DIR__ . '/SoftmaxClassifierTest.php';
require __DIR__ . '/MLPClassifierTest.php';
require __DIR__ . '/ModelEvaluatorTest.php';
require __DIR__ . '/ModelInsightServiceTest.php';
require __DIR__ . '/FeedbackStoreTest.php';
require __DIR__ . '/TravelTimeEstimatorTest.php';
require __DIR__ . '/CostEstimatorTest.php';
require __DIR__ . '/EmissionsEstimatorTest.php';
require __DIR__ . '/ABTestStatsTest.php';
require __DIR__ . '/KMeansDaySplitterTest.php';
require __DIR__ . '/RateLimiterTest.php';
require __DIR__ . '/OsrmRoadRouterTest.php';
require __DIR__ . '/RoutePlannerTest.php';
require __DIR__ . '/Http/HttpTestServer.php';
require __DIR__ . '/Http/ApiHttpTest.php';

use Tests\ABTestStatsTest;
use Tests\CostEstimatorTest;
use Tests\EmissionsEstimatorTest;
use Tests\FeedbackStoreTest;
use Tests\HaversineCalculatorTest;
use Tests\Http\ApiHttpTest;
use Tests\Http\HttpTestServer;
use Tests\KMeansDaySplitterTest;
use Tests\MLPClassifierTest;
use Tests\ModelEvaluatorTest;
use Tests\ModelInsightServiceTest;
use Tests\OsrmRoadRouterTest;
use Tests\RateLimiterTest;
use Tests\RouteOptimizerTest;
use Tests\RoutePlannerTest;
use Tests\SoftmaxClassifierTest;
use Tests\TestReporter;
use Tests\TravelTimeEstimatorTest;

$reporter = new TestReporter();

echo "HaversineCalculator:\n";
(new HaversineCalculatorTest())->run($reporter);

echo "\nRouteOptimizer:\n";
(new RouteOptimizerTest())->run($reporter);

echo "\nSoftmaxClassifier:\n";
(new SoftmaxClassifierTest())->run($reporter);

echo "\nMLPClassifier:\n";
(new MLPClassifierTest())->run($reporter);

echo "\nModelEvaluator (confusion matrix, precision/recall/F1, k-fold):\n";
(new ModelEvaluatorTest())->run($reporter);

echo "\nModel Insights, quality report and safe feedback:\n";
(new ModelInsightServiceTest())->run($reporter);
(new FeedbackStoreTest())->run($reporter);

echo "\nTravelTimeEstimator:\n";
(new TravelTimeEstimatorTest())->run($reporter);

echo "\nCostEstimator:\n";
(new CostEstimatorTest())->run($reporter);

echo "\nEmissionsEstimator:\n";
(new EmissionsEstimatorTest())->run($reporter);

echo "\nABTestStats:\n";
(new ABTestStatsTest())->run($reporter);

echo "\nKMeansDaySplitter (кластеризация маршрута по дням поездки):\n";
(new KMeansDaySplitterTest())->run($reporter);

echo "\nRateLimiter (token bucket):\n";
(new RateLimiterTest())->run($reporter);

echo "\nOsrmRoadRouter (cache + provider failover):\n";
(new OsrmRoadRouterTest())->run($reporter);

echo "\nRoutePlanner (интеграционный):\n";
(new RoutePlannerTest())->run($reporter);

echo "\nHTTP API (интеграционные тесты через настоящий php -S сервер):\n";
$publicDir = realpath(__DIR__ . '/../public');
$httpServer = new HttpTestServer($publicDir);
try {
    $httpServer->start();
    (new ApiHttpTest($httpServer))->run($reporter);
} catch (\Throwable $e) {
    echo "  ❌ Не удалось прогнать HTTP-тесты: {$e->getMessage()}\n";
} finally {
    $httpServer->stop();

    // HTTP-тесты реально стучались в эндпоинты и оставили состояние на диске
    // (счётчики rate limiter'а, тестовую запись A/B-статистики) — подчищаем,
    // чтобы прогон тестов не влиял на реальное состояние приложения.
    foreach (glob(__DIR__ . '/../var/ratelimit/*.json') ?: [] as $file) {
        @unlink($file);
    }
    @unlink(__DIR__ . '/../var/ab_stats.json');
    @unlink(__DIR__ . '/../var/ml_feedback.ndjson');
}

$summary = $reporter->summary();
echo "\n" . str_repeat('-', 40) . "\n";
echo "Пройдено: {$summary['passed']}, провалено: {$summary['failed']}\n";

exit($summary['failed'] > 0 ? 1 : 0);
