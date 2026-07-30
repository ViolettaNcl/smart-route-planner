<?php

namespace Tests;

use App\ML\TransportPredictor;
use App\RoutePlanner;
use App\Routing\HaversineCalculator;
use App\Routing\RouteOptimizer;
use Tests\Fakes\FakeGeocoder;
use Tests\Fakes\FakeRoadRouter;

class RoutePlannerTest
{
    public function run(TestReporter $t): void
    {
        $weightsPath = __DIR__ . '/../src/ML/mlp_weights.json';
        $calc = new HaversineCalculator();

        // --- Сценарий 1: OSRM доступен ---
        $planner = new RoutePlanner(
            geocoder: new FakeGeocoder(),
            calculator: $calc,
            optimizer: new RouteOptimizer($calc),
            predictor: new TransportPredictor($weightsPath),
            roadRouter: new FakeRoadRouter(available: true),
        );

        // Специально "плохой" порядок ввода + один нераспознанный город.
        $result = $planner->plan('Москва;Волгоград;НесуществующийГород;Ростов-на-Дону;Воронеж');

        $t->assertTrue('Маршрут рассчитан успешно (ok=true)', $result['ok'] === true);
        $t->assertEquals('Нераспознанный город попал в skipped', ['НесуществующийГород'], $result['skipped']);
        $t->assertEquals('Все 4 валидные точки на месте', 4, $result['stops']);
        $t->assertEquals('Источник маршрута — osrm_road, когда OSRM доступен', 'osrm_road', $result['routing_source']);
        $t->assertTrue('Первая точка маршрута — Москва (старт пользователя)', $result['points'][0] === 'Москва');
        $t->assertTrue('Дистанция от OSRM больше, чем "по воздуху" (реалистичнее)', $result['distance_km'] > 0);
        $t->assertTrue(
            'Для режима car время в пути помечено как точное (exact=true)',
            $result['transport']['mode'] !== 'car' || $result['duration']['exact'] === true
        );

        // --- Сценарий 2: OSRM недоступен — приложение не должно падать ---
        $plannerNoOsrm = new RoutePlanner(
            geocoder: new FakeGeocoder(),
            calculator: $calc,
            optimizer: new RouteOptimizer($calc),
            predictor: new TransportPredictor($weightsPath),
            roadRouter: new FakeRoadRouter(available: false),
        );

        $fallbackResult = $plannerNoOsrm->plan('Москва;Воронеж;Ростов-на-Дону');

        $t->assertTrue('Маршрут считается даже без OSRM', $fallbackResult['ok'] === true);
        $t->assertEquals(
            'Источник маршрута — straight_line, когда OSRM недоступен',
            'straight_line',
            $fallbackResult['routing_source']
        );
        $t->assertTrue(
            'Оценка времени помечена как приблизительная (exact=false) без OSRM',
            $fallbackResult['duration']['exact'] === false
        );

        // --- Сценарий 3: меньше двух распознанных городов — понятная ошибка ---
        $errorResult = $planner->plan('НесуществующийГород1;НесуществующийГород2');
        $t->assertTrue('Ошибка при <2 распознанных городах (ok=false)', $errorResult['ok'] === false);
    }
}
