<?php

namespace App\Routing;

class HaversineCalculator
{
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * @param array{lat: float, lon: float} $a
     * @param array{lat: float, lon: float} $b
     */
    public function distanceKm(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLon = deg2rad($b['lon'] - $a['lon']);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    /**
     * Суммарная длина маршрута по заданному порядку точек.
     *
     * @param array<int, array{lat: float, lon: float}> $orderedCoords
     */
    public function totalDistanceKm(array $orderedCoords): float
    {
        $total = 0.0;

        for ($i = 0; $i < count($orderedCoords) - 1; $i++) {
            $total += $this->distanceKm($orderedCoords[$i], $orderedCoords[$i + 1]);
        }

        return $total;
    }
}
