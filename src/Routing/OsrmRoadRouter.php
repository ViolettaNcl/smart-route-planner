<?php

namespace App\Routing;

use App\Http\SafeHttpClient;

/**
 * Реальный маршрут по дорогам через публичный демо-сервер OSRM
 * (Open Source Routing Machine, router.project-osrm.org).
 *
 * До этого класса расстояние считалось "по воздуху" (Haversine), а на карте
 * рисовались прямые линии между городами. OSRM строит маршрут по настоящей
 * дорожной сети и возвращает точную геометрию пути, дистанцию и время в
 * пути на автомобиле.
 *
 * Важные ограничения (честно, не спрятано в коде):
 * - публичный демо-сервер OSRM поддерживает только профиль `driving`
 *   (автомобиль) — пешеходного/общественного транспорта там нет;
 * - это бесплатный публичный сервис без SLA — он может быть недоступен или
 *   медленным, поэтому вызывающий код (RoutePlanner) обязан аккуратно
 *   откатываться на Haversine-дистанцию и прямые линии, если OSRM не ответил;
 * - сетевой вызов использует переносимый SafeHttpClient: cURL при наличии и
 *   PHP streams как fallback на serverless-хостингах.
 */
class OsrmRoadRouter implements RoadRouterInterface
{
    private const ENDPOINT = 'https://router.project-osrm.org/route/v1/driving/';

    public function __construct(private int $timeoutSeconds = 5)
    {
    }

    public function route(array $orderedCoords): ?array
    {
        if (count($orderedCoords) < 2) {
            return null;
        }

        $coordsParam = implode(';', array_map(
            fn ($c) => $c['lon'] . ',' . $c['lat'], // OSRM ждёт именно lon,lat
            $orderedCoords
        ));

        $url = self::ENDPOINT . $coordsParam . '?' . http_build_query([
            'overview' => 'full',
            'geometries' => 'geojson',
        ]);

        $response = SafeHttpClient::get($url, $this->timeoutSeconds, [
            'User-Agent: smart-route-planner (portfolio project)',
            'Accept: application/json',
        ]);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);

        if (($data['code'] ?? null) !== 'Ok' || empty($data['routes'][0])) {
            return null;
        }

        $route = $data['routes'][0];

        // GeoJSON отдаёт координаты как [lon, lat] — разворачиваем в [lat, lon]
        // для совместимости с контрактом API приложения. MapLibre на фронтенде
        // преобразует их обратно при создании GeoJSON-источника.
        $geometry = array_map(
            fn ($point) => [$point[1], $point[0]],
            $route['geometry']['coordinates'] ?? []
        );

        return [
            'distance_km' => round($route['distance'] / 1000, 1),
            'duration_min' => round($route['duration'] / 60, 1),
            'geometry' => $geometry,
        ];
    }
}
