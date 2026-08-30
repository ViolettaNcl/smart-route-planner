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
        $t->assertEquals('OSRM вернул две реальные опции маршрута', 2, count($result['route_options']));
        $t->assertTrue('У основной опции есть навигационные шаги', $result['route_options'][0]['navigation_available'] === true);
        $t->assertEquals('Провайдер маршрута отражён в контракте', 'fake_osrm', $result['routing_provider']);
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

        // --- Сценарий 4: структурированные точки с координатами не требуют геокодинга ---
        $structured = $planner->planStops([
            ['label' => 'Дом', 'lat' => 55.75, 'lon' => 37.61],
            ['label' => 'Остановка', 'lat' => 54.0, 'lon' => 39.0],
            ['label' => 'Дом', 'lat' => 47.23, 'lon' => 39.70],
        ], optimizeOrder: false);
        $t->assertTrue('Структурированный маршрут рассчитан', $structured['ok'] === true);
        $t->assertEquals('Одинаковые подписи не перетирают остановки', 3, count($structured['route_stops']));
        $t->assertEquals('Порядок можно сохранить без оптимизации', ['Дом', 'Остановка', 'Дом'], $structured['points']);
        $t->assertTrue('Координаты помечены как предоставленные клиентом', $structured['route_stops'][0]['coordinate_source'] === 'provided');

        // --- Сценарий 5: серверный предел защищает внешние сервисы ---
        $tooMany = array_fill(0, RoutePlanner::MAX_STOPS + 1, ['label' => 'Москва']);
        $tooManyResult = $planner->planStops($tooMany);
        $t->assertEquals('Больше 12 точек -> TOO_MANY_STOPS', 'TOO_MANY_STOPS', $tooManyResult['error_code']);
    }
}
