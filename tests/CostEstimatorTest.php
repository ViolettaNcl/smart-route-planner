<?php

namespace Tests;

use App\Routing\CostEstimator;

class CostEstimatorTest
{
    public function run(TestReporter $t): void
    {
        $estimator = new CostEstimator();

        // --- car: 500 км, дефолтный расход 8л/100км, дефолтная цена топлива 60₽/л ---
        // (500/100) * 8 * 60 = 2400
        $car = $estimator->estimate(500.0, 'car');
        $t->assertEquals('Стоимость поездки на авто = расход * дистанция * цена топлива', 2400.0, $car['amount']);
        $t->assertEquals('Единица валюты — RUB', 'RUB', $car['currency']);

        // --- car с кастомными параметрами ---
        $carCustom = $estimator->estimate(200.0, 'car', fuelPricePerLiter: 50.0, fuelConsumptionL100km: 10.0);
        $t->assertEquals('Кастомные параметры расхода/цены применяются', 1000.0, $carCustom['amount']);

        // --- bus: длинная поездка считается по цене за км ---
        $bus = $estimator->estimate(500.0, 'bus');
        $t->assertEquals('Стоимость на автобусе = дистанция * цена билета за км', 1500.0, $bus['amount']);

        // --- bus: короткая поездка не должна быть дешевле минимального тарифа ---
        $shortBus = $estimator->estimate(5.0, 'bus');
        $t->assertTrue('Короткая поездка на автобусе не дешевле базового тарифа', $shortBus['amount'] >= CostEstimator::DEFAULT_TICKET_BASE_FARE);

        // --- walk: всегда бесплатно ---
        $walk = $estimator->estimate(10.0, 'walk');
        $t->assertEquals('Пешая поездка бесплатна', 0.0, $walk['amount']);

        // --- sanitizePositive: отбрасывает некорректный ввод ---
        $t->assertTrue('sanitizePositive отбрасывает 0', CostEstimator::sanitizePositive(0) === null);
        $t->assertTrue('sanitizePositive отбрасывает отрицательные числа', CostEstimator::sanitizePositive(-5) === null);
        $t->assertTrue('sanitizePositive отбрасывает нечисловые значения', CostEstimator::sanitizePositive('abc') === null);
        $t->assertEquals('sanitizePositive принимает валидное число', 42.5, CostEstimator::sanitizePositive('42.5'));
    }
}
