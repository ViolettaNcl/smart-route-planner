<?php

namespace Tests;

use App\Routing\TravelTimeEstimator;

class TravelTimeEstimatorTest
{
    public function run(TestReporter $t): void
    {
        $estimator = new TravelTimeEstimator();

        // 100 км пешком при 5 км/ч — это 20 часов, проверяем математику.
        $t->assertApprox('100 км пешком ≈ 1200 мин', 1200, $estimator->estimateMinutes(100, 'walk'), 0.5);

        // 70 км на машине при ~70 км/ч — около часа.
        $t->assertApprox('70 км на авто ≈ 60 мин', 60, $estimator->estimateMinutes(70, 'car'), 1);

        // Неизвестный режим транспорта не должен ронять приложение —
        // используется запасная скорость (как у авто).
        $t->assertTrue(
            'Неизвестный режим транспорта не вызывает ошибку',
            $estimator->estimateMinutes(70, 'unknown_mode') > 0
        );

        $t->assertEquals('Форматирование: 45 минут', '45 мин', $estimator->formatDuration(45));
        $t->assertEquals('Форматирование: 90 минут -> 1ч 30мин', '1 ч 30 мин', $estimator->formatDuration(90));
        $t->assertEquals('Форматирование: 0 минут', '0 мин', $estimator->formatDuration(0));
    }
}
