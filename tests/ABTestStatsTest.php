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
        $t->assertTrue('Первый event_id принят', $stats->record('mlp', true, 'event-unique-1'));
        $t->assertTrue('Повторный event_id отклонён', !$stats->record('mlp', true, 'event-unique-1'));

        $result = $stats->getStats();

        $t->assertEquals('MLP: 3 верных предсказания', 3, $result['mlp']['correct']);
        $t->assertEquals('MLP: 1 неверное предсказание', 1, $result['mlp']['incorrect']);
        $t->assertApprox('MLP accuracy = 75%', 75.0, $result['mlp']['accuracy'], 0.1);
        $t->assertEquals('Softmax: 1 верное предсказание', 1, $result['softmax']['correct']);
        $t->assertEquals('Softmax accuracy = 100%', 100.0, $result['softmax']['accuracy']);
        $t->assertTrue('Wilson 95% interval рассчитан', is_float($result['mlp']['confidence_interval']['low']));
        $t->assertTrue('Результат A/B не объявляется до 30 ответов', !$result['mlp']['result_ready']);

        for ($i = 0; $i < 26; $i++) {
            $stats->record('mlp', $i % 2 === 0, 'event-fill-' . $i);
        }
        $t->assertTrue('После 30 ответов вариант помечен готовым к интерпретации', $stats->getStats()['mlp']['result_ready']);

        @unlink($path);
    }
}
