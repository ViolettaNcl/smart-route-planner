<?php

namespace App\Routing;

/**
 * Оптимизация порядка обхода точек маршрута — упрощённое решение задачи
 * коммивояжёра (TSP): точный алгоритм для произвольного числа точек требует
 * перебора n! вариантов, поэтому используется стандартная для практических
 * задач комбинация из двух эвристик:
 *
 * 1. Nearest Neighbor — быстро строит достаточно хороший начальный маршрут:
 *    из текущей точки всегда идём в ближайшую ещё не посещённую.
 * 2. 2-opt — локальный поиск: пробует "распутывать" пересечения маршрута,
 *    разворачивая отрезки между двумя точками, и оставляет улучшение, если
 *    оно действительно сокращает суммарную длину.
 *
 * Первая точка, введённая пользователем, всегда остаётся стартом маршрута —
 * это ожидаемое поведение (человек начинает поездку оттуда, где находится).
 */
class RouteOptimizer
{
    public function __construct(private HaversineCalculator $calculator)
    {
    }

    /**
     * @param string[] $labels Названия точек в порядке, введённом пользователем
     * @param array<string, array{lat: float, lon: float}> $coords Координаты по названию точки
     * @return string[] Названия точек в оптимизированном порядке
     */
    public function optimize(array $labels, array $coords): array
    {
        if (count($labels) <= 2) {
            return $labels; // Оптимизировать нечего — 0 или 1 отрезок
        }

        $order = $this->nearestNeighborOrder($labels, $coords);

        return $this->twoOptImprove($order, $coords);
    }

    /**
     * @param string[] $labels
     * @param array<string, array{lat: float, lon: float}> $coords
     * @return string[]
     */
    private function nearestNeighborOrder(array $labels, array $coords): array
    {
        $remaining = $labels;
        $route = [array_shift($remaining)]; // старт — первая точка пользователя

        while (!empty($remaining)) {
            $current = $coords[end($route)];
            $nearestIndex = null;
            $nearestDistance = INF;

            foreach ($remaining as $i => $candidate) {
                $d = $this->calculator->distanceKm($current, $coords[$candidate]);
                if ($d < $nearestDistance) {
                    $nearestDistance = $d;
                    $nearestIndex = $i;
                }
            }

            $route[] = $remaining[$nearestIndex];
            unset($remaining[$nearestIndex]);
            $remaining = array_values($remaining);
        }

        return $route;
    }

    /**
     * @param string[] $route
     * @param array<string, array{lat: float, lon: float}> $coords
     * @return string[]
     */
    private function twoOptImprove(array $route, array $coords, int $maxPasses = 25): array
    {
        $n = count($route);
        $improved = true;
        $pass = 0;

        while ($improved && $pass < $maxPasses) {
            $improved = false;
            $pass++;

            // Первая и последняя точка (i=0) не трогаются — старт маршрута фиксирован.
            for ($i = 1; $i < $n - 2; $i++) {
                for ($k = $i + 1; $k < $n - 1; $k++) {
                    $delta = $this->reversalDelta($route, $coords, $i, $k);

                    if ($delta < -1e-9) {
                        $route = $this->reverseSegment($route, $i, $k);
                        $improved = true;
                    }
                }
            }
        }

        return $route;
    }

    /**
     * Насколько изменится длина маршрута, если развернуть участок [i..k].
     * Отрицательное значение — маршрут станет короче.
     *
     * @param string[] $route
     * @param array<string, array{lat: float, lon: float}> $coords
     */
    private function reversalDelta(array $route, array $coords, int $i, int $k): float
    {
        $a = $coords[$route[$i - 1]];
        $b = $coords[$route[$i]];
        $c = $coords[$route[$k]];
        $d = $coords[$route[$k + 1]];

        $before = $this->calculator->distanceKm($a, $b) + $this->calculator->distanceKm($c, $d);
        $after = $this->calculator->distanceKm($a, $c) + $this->calculator->distanceKm($b, $d);

        return $after - $before;
    }

    /**
     * @param string[] $route
     * @return string[]
     */
    private function reverseSegment(array $route, int $i, int $k): array
    {
        $segment = array_slice($route, $i, $k - $i + 1);
        array_splice($route, $i, $k - $i + 1, array_reverse($segment));

        return $route;
    }
}
