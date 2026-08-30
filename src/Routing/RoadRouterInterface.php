<?php

namespace App\Routing;

interface RoadRouterInterface
{
    /**
     * Строит реальный маршрут по дорогам через все точки по порядку.
     *
     * @param array<int, array{lat: float, lon: float}> $orderedCoords Точки в оптимизированном порядке
     * @return array<string, mixed>|null
     *         null, если сервис маршрутизации недоступен — вызывающий код должен
     *         в этом случае аккуратно откатиться на прямые линии и Haversine-дистанцию.
     */
    public function route(array $orderedCoords): ?array;
}
