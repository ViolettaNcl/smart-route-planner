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

        return [
            'distance_km' => $roadDistance,
            'duration_min' => round(($roadDistance / 70) * 60, 1),
            'geometry' => $orderedCoords, // для теста упрощаем geometry = точки маршрута
        ];
    }
}
