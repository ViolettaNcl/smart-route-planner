<?php

namespace Tests;

use App\ML\ABTestStats;

class ABTestStatsTest
{
    public function run(TestReporter $t): void
    {
        $path = sys_get_temp_dir() . '/ab_stats_test_' . uniqid() . '.json';

        $stats = new ABTestStats($path);

        $t->assertEquals('Пустая статистика: 0 верных для mlp', 0, $stats->getStats()['mlp']['correct']);

        $stats->record('mlp', true);
        $stats->record('mlp', true);
        $stats->record('mlp', false);
        $stats->record('softmax', true);

        $result = $stats->getStats();

        $t->assertEquals('MLP: 2 верных предсказания', 2, $result['mlp']['correct']);
        $t->assertEquals('MLP: 1 неверное предсказание', 1, $result['mlp']['incorrect']);
        $t->assertApprox('MLP accuracy = 66.7%', 66.7, $result['mlp']['accuracy'], 0.1);
        $t->assertEquals('Softmax: 1 верное предсказание', 1, $result['softmax']['correct']);
        $t->assertEquals('Softmax accuracy = 100%', 100.0, $result['softmax']['accuracy']);

        @unlink($path);
    }
}
