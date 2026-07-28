<?php

namespace Tests;

use App\Routing\EmissionsEstimator;

class EmissionsEstimatorTest
{
    public function run(TestReporter $t): void
    {
        $estimator = new EmissionsEstimator();

        $walk = $estimator->estimate(500, 'walk');
        $t->assertEquals('Пешком — нулевые выбросы', 0.0, $walk['co2_kg']);

        $car = $estimator->estimate(500, 'car');
        $t->assertEquals('500 км на авто — 60.0 кг CO2 (120 г/км)', 60.0, $car['co2_kg']);

        $bus = $estimator->estimate(500, 'bus');
        $t->assertEquals('500 км на автобусе — 34.0 кг CO2 (68 г/км)', 34.0, $bus['co2_kg']);

        $t->assertTrue(
            'Автобус эффективнее авто на одинаковой дистанции',
            $car['comparison']['car'] > $car['comparison']['bus']
        );
        $t->assertTrue(
            'Сравнение включает все три вида транспорта',
            isset($car['comparison']['walk'], $car['comparison']['car'], $car['comparison']['bus'])
        );
    }
}
