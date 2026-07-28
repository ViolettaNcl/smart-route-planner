<?php

namespace App\Geocoding;

interface GeocoderInterface
{
    /**
     * Возвращает координаты города или null, если город не найден / произошла ошибка сети.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function geocode(string $place): ?array;
}
