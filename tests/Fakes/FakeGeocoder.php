<?php

namespace Tests\Fakes;

use App\Geocoding\GeocoderInterface;

/**
 * Геокодер-заглушка для тестов: возвращает координаты из захардкоженного
 * списка вместо реального запроса к Nominatim. Позволяет тестировать всю
 * цепочку RoutePlanner без сети.
 */
class FakeGeocoder implements GeocoderInterface
{
    private array $db = [
        'Волгоград' => ['lat' => 48.708, 'lon' => 44.5133],
        'Ростов-на-Дону' => ['lat' => 47.2357, 'lon' => 39.7015],
        'Воронеж' => ['lat' => 51.6720, 'lon' => 39.1843],
        'Москва' => ['lat' => 55.7558, 'lon' => 37.6173],
    ];

    public function geocode(string $place): ?array
    {
        return $this->db[$place] ?? null;
    }
}
