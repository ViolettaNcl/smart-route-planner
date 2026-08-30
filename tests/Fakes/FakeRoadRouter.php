<?php

namespace Tests\Fakes;

use App\Routing\RoadRouterInterface;

/**
 * Заглушка OSRM: имитирует "дорожный" маршрут, который на 15% длиннее
 * прямой (реалистичное соотношение дорога/воздух), без реального запроса.
 */
class FakeRoadRouter implements RoadRouterInterface
{
    public function __construct(private bool $available = true)
    {
    }

    public function route(array $orderedCoords): ?array
    {
        if (!$this->available) {
            return null; // имитация недоступности OSRM — проверяем откат на Haversine
        }

        $calc = new \App\Routing\HaversineCalculator();
        $airDistance = $calc->totalDistanceKm($orderedCoords);
        $roadDistance = round($airDistance * 1.15, 1);

        $primary = [
            'id' => 'route-1',
            'rank' => 1,
            'distance_km' => $roadDistance,
            'duration_min' => round(($roadDistance / 70) * 60, 1),
            'geometry' => $orderedCoords, // для теста упрощаем geometry = точки маршрута
            'legs' => [[
                'index' => 0,
                'distance_km' => $roadDistance,
                'duration_min' => round(($roadDistance / 70) * 60, 1),
                'summary' => 'Test road',
                'steps' => [[
                    'id' => 'leg-1-step-1',
                    'distance_m' => round($roadDistance * 1000),
                    'duration_min' => round(($roadDistance / 70) * 60, 1),
                    'name' => 'Test road',
                    'maneuver' => ['type' => 'depart', 'modifier' => 'straight'],
                    'geometry' => $orderedCoords,
                ]],
            ]],
        ];
        $alternative = $primary;
        $alternative['id'] = 'route-2';
        $alternative['rank'] = 2;
        $alternative['distance_km'] = round($roadDistance * 1.08, 1);
        $alternative['duration_min'] = round($primary['duration_min'] * 1.04, 1);

        return [
            'distance_km' => $primary['distance_km'],
            'duration_min' => $primary['duration_min'],
            'geometry' => $primary['geometry'],
            'legs' => $primary['legs'],
            'options' => [$primary, $alternative],
            'provider' => 'fake_osrm',
        ];
    }
}
