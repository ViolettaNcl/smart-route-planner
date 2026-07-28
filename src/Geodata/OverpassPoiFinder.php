<?php

namespace App\Geodata;

/**
 * Точки интереса рядом с точками маршрута (АЗС, кафе, рестораны, отели)
 * через Overpass API (overpass-api.de) — бесплатная надстройка над данными
 * OpenStreetMap, ключ не нужен.
 *
 * Используется как "умные остановки" на длинных маршрутах: пользователь
 * видит не просто линию на карте, а где можно заправиться/перекусить/
 * переночевать рядом с конкретным городом маршрута.
 *
 * Как и другие интеграции с бесплатными публичными API в проекте
 * (NominatimGeocoder, OsrmRoadRouter, OpenMeteoClient) — сетевые сбои не
 * должны ломать основной сценарий (расчёт маршрута), поэтому все ошибки
 * гасятся внутри класса и возвращается пустой список, а не исключение.
 */
class OverpassPoiFinder
{
    private const ENDPOINT = 'https://overpass-api.de/api/interpreter';

    /**
     * amenity/tourism-теги OSM -> человекочитаемая категория + иконка для карты.
     */
    private const CATEGORIES = [
        'amenity=fuel' => ['category' => 'fuel', 'label_ru' => 'АЗС', 'icon' => '⛽'],
        'amenity=cafe' => ['category' => 'cafe', 'label_ru' => 'кафе', 'icon' => '☕'],
        'amenity=restaurant' => ['category' => 'restaurant', 'label_ru' => 'ресторан', 'icon' => '🍽️'],
        'tourism=hotel' => ['category' => 'hotel', 'label_ru' => 'отель', 'icon' => '🛏️'],
    ];

    public function __construct(private int $timeoutSeconds = 12)
    {
    }

    /**
     * @param int $radiusMeters радиус поиска вокруг точки
     * @param int $limitPerPoint сколько объектов максимум вернуть на одну точку
     * @return array<int, array{name: string, category: string, label_ru: string, icon: string, lat: float, lon: float}>
     */
    public function findNear(float $lat, float $lon, int $radiusMeters = 3000, int $limitPerPoint = 8): array
    {
        $query = $this->buildQuery($lat, $lon, $radiusMeters);
        $body = $this->fetch($query);

        if ($body === null) {
            return [];
        }

        $data = json_decode($body, true);
        $elements = $data['elements'] ?? null;

        if (!is_array($elements)) {
            return [];
        }

        $results = [];
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? null;

            // Безымянные объекты (например, автоматические АЗС без указанного
            // названия в OSM) неинформативны на карте — пропускаем.
            if (!$name) {
                continue;
            }

            $categoryInfo = $this->categoryFor($tags);
            if ($categoryInfo === null) {
                continue;
            }

            $elLat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $elLon = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($elLat === null || $elLon === null) {
                continue;
            }

            $results[] = [
                'name' => $name,
                'category' => $categoryInfo['category'],
                'label_ru' => $categoryInfo['label_ru'],
                'icon' => $categoryInfo['icon'],
                'lat' => (float) $elLat,
                'lon' => (float) $elLon,
            ];

            if (count($results) >= $limitPerPoint) {
                break;
            }
        }

        return $results;
    }

    private function categoryFor(array $tags): ?array
    {
        foreach (self::CATEGORIES as $tagExpr => $info) {
            [$key, $value] = explode('=', $tagExpr);
            if (($tags[$key] ?? null) === $value) {
                return $info;
            }
        }

        return null;
    }

    private function buildQuery(float $lat, float $lon, int $radiusMeters): string
    {
        // Overpass QL: ищем узлы (node) с любым из четырёх тегов в радиусе
        // вокруг точки. `out center 40` — вернуть до 40 объектов с координатами
        // (для way/relation `center` даёт усреднённую точку — на случай, если
        // объект замаплен полигоном, а не точкой).
        $around = "around:{$radiusMeters},{$lat},{$lon}";

        return "[out:json][timeout:{$this->timeoutSeconds}];"
            . "("
            . "node[\"amenity\"=\"fuel\"]({$around});"
            . "node[\"amenity\"=\"cafe\"]({$around});"
            . "node[\"amenity\"=\"restaurant\"]({$around});"
            . "node[\"tourism\"=\"hotel\"]({$around});"
            . ");"
            . "out center 40;";
    }

    private function fetch(string $query): ?string
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['User-Agent: smart-route-planner (portfolio project)'],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $error !== '' || $status !== 200) {
            return null;
        }

        return $body;
    }
}
