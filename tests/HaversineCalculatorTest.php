<?php

namespace Tests;

use App\Routing\HaversineCalculator;

class HaversineCalculatorTest
{
    public function run(TestReporter $t): void
    {
        $calc = new HaversineCalculator();

        // Волгоград и Ростов-на-Дону — известное расстояние по прямой, ~394 км
        // (проверено независимо: haversine по этим координатам = 393.75 км).
        $volgograd = ['lat' => 48.708, 'lon' => 44.5133];
        $rostov = ['lat' => 47.2357, 'lon' => 39.7015];

        $distance = $calc->distanceKm($volgograd, $rostov);
        $t->assertApprox('Расстояние Волгоград–Ростов ~394 км', 393.75, $distance, 1);

        // Расстояние точки до самой себя должно быть 0.
        $t->assertApprox('Расстояние точки до себя = 0', 0, $calc->distanceKm($volgograd, $volgograd), 0.001);

        // Суммарная дистанция по маршруту из одной точки = 0.
        $t->assertApprox('Маршрут из одной точки = 0 км', 0, $calc->totalDistanceKm([$volgograd]), 0.001);

        // Суммарная дистанция по 3 точкам — сумма двух отрезков.
        $voronezh = ['lat' => 51.6720, 'lon' => 39.1843];
        $total = $calc->totalDistanceKm([$volgograd, $rostov, $voronezh]);
        $expected = $calc->distanceKm($volgograd, $rostov) + $calc->distanceKm($rostov, $voronezh);
        $t->assertApprox('Суммарная дистанция = сумме отрезков', $expected, $total, 0.001);
    }
}
