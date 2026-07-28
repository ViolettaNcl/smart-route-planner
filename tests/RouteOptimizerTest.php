<?php

namespace Tests;

use App\Routing\HaversineCalculator;
use App\Routing\RouteOptimizer;

class RouteOptimizerTest
{
    public function run(TestReporter $t): void
    {
        $calc = new HaversineCalculator();
        $optimizer = new RouteOptimizer($calc);

        // Точки на одной прямой, введены в "плохом" порядке (зигзагом).
        // Оптимизатор должен выстроить их по возрастанию координаты,
        // а не оставить зигзаг — иначе оптимизация не работает.
        $coords = [
            'A' => ['lat' => 0.0, 'lon' => 0.0],
            'B' => ['lat' => 0.0, 'lon' => 3.0],
            'C' => ['lat' => 0.0, 'lon' => 1.0],
            'D' => ['lat' => 0.0, 'lon' => 2.0],
        ];

        $badOrder = ['A', 'B', 'C', 'D']; // A(0) -> B(3) -> C(1) -> D(2) — зигзаг
        $optimized = $optimizer->optimize($badOrder, $coords);

        $badDistance = $calc->totalDistanceKm(array_map(fn ($p) => $coords[$p], $badOrder));
        $optimizedDistance = $calc->totalDistanceKm(array_map(fn ($p) => $coords[$p], $optimized));

        $t->assertTrue(
            'Оптимизированный маршрут короче или равен исходному',
            $optimizedDistance <= $badDistance + 1e-9
        );

        $t->assertEquals('Первая точка маршрута не меняется (это старт пользователя)', 'A', $optimized[0]);

        $t->assertTrue(
            'Оптимизированный маршрут содержит все исходные точки',
            count(array_diff($badOrder, $optimized)) === 0 && count($optimized) === count($badOrder)
        );

        // Маршрут из 2 точек не должен падать и должен возвращаться как есть.
        $twoPoints = $optimizer->optimize(['A', 'B'], $coords);
        $t->assertEquals('Маршрут из 2 точек не меняется', ['A', 'B'], $twoPoints);
    }
}
