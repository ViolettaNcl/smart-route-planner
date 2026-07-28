<?php

namespace App\ML;

/**
 * "AI-планировщик поездки по дням": разбивает уже посчитанный (упорядоченный)
 * маршрут на сбалансированные дни вождения с помощью K-Means (алгоритм
 * Ллойда), написанного с нуля — без внешних ML-библиотек.
 *
 * ## Почему это отдельный, новый класс ML, а не ещё один классификатор
 *
 * MLPClassifier/SoftmaxClassifier — это **обучение с учителем** (supervised
 * learning): у каждого примера есть заранее известная правильная метка
 * (walk/car/bus), и модель учится её предсказывать. K-Means — это
 * **обучение без учителя** (unsupervised learning): у нас нет "правильных"
 * дней поездки — алгоритм сам находит структуру (естественные группы) в
 * данных, без единого размеченного примера. Это принципиально другая задача
 * машинного обучения, и её стоит показать в портфолио отдельно от
 * классификации транспорта.
 *
 * ## Почему 1D K-Means, а не K-Means по координатам (lat/lon)
 *
 * Наивная идея — кластеризовать города по географическим координатам. Но
 * для ROAD TRIP это в корне неверно: два города могут быть рядом
 * географически, но находиться в разных концах маршрута (представьте
 * маршрут-петлю). Дни поездки должны идти **строго по порядку следования**
 * маршрута — нельзя запланировать "день 2" через город, который проезжаем
 * на "дне 4". Поэтому кластеризуем не координаты, а **позицию каждого
 * перегона (leg) на маршруте** — кумулятивную дистанцию от старта до
 * середины перегона (1 число на перегон, т.е. одномерные данные).
 *
 * Важное свойство 1D K-Means: при отсортированном входе (а кумулятивная
 * дистанция вдоль маршрута всегда монотонно возрастает) кластеры,
 * назначенные по ближайшему центроиду, автоматически получаются смежными
 * интервалами — что и нужно для дней поездки (см. также классический
 * результат: Wang & Song, "Ckmeans.1d.dp" — оптимальная 1D-кластеризация
 * эквивалентна разбиению на непрерывные интервалы). Плюс мы всё равно
 * подстраховываемся явной монотонной коррекцией после сходимости — код не
 * полагается только на теорию, а гарантирует инвариант физически.
 *
 * ## Алгоритм (Lloyd's algorithm)
 *
 *   1. Инициализация: k центроидов равномерно по диапазону [min, max]
 *      кумулятивных позиций перегонов (детерминированная инициализация —
 *      воспроизводимый результат без разброса от случайного старта).
 *   2. Присвоение (assignment step): каждый перегон — кластеру с
 *      ближайшим по значению центроидом.
 *   3. Обновление (update step): каждый центроид = среднее позиций
 *      перегонов своего кластера.
 *   4. Повтор шагов 2-3 до сходимости (центроиды перестают меняться) или
 *      достижения предела итераций.
 */
class KMeansDaySplitter
{
    public function __construct(private int $maxIterations = 100)
    {
    }

    /**
     * @param float[] $legDistancesKm Дистанция каждого перегона маршрута (n-1 значений на n точек)
     * @param int $days Желаемое число дней (k). Автоматически урезается до
     *                  количества перегонов, если запрошено больше дней, чем
     *                  перегонов (день без единого перегона не имеет смысла).
     * @return array<int, array{day: int, leg_start: int, leg_end: int, distance_km: float}>
     *         Для каждого дня: индексы первого/последнего перегона (включительно)
     *         и суммарная дистанция за день. Индекс точки маршрута, где
     *         начинается день N+1, всегда совпадает с точкой, где закончился
     *         день N (общая точка — место ночёвки).
     */
    public function splitIntoDays(array $legDistancesKm, int $days): array
    {
        $legCount = count($legDistancesKm);

        if ($legCount === 0) {
            return [];
        }

        $k = max(1, min($days, $legCount));

        if ($k === 1) {
            return [[
                'day' => 1,
                'leg_start' => 0,
                'leg_end' => $legCount - 1,
                'distance_km' => round(array_sum($legDistancesKm), 1),
            ]];
        }

        // Позиция середины каждого перегона вдоль маршрута (кумулятивная
        // дистанция) — это и есть 1D-признак, который кластеризуем.
        $midpoints = [];
        $cumulative = 0.0;
        foreach ($legDistancesKm as $leg) {
            $midpoints[] = $cumulative + $leg / 2;
            $cumulative += $leg;
        }
        $totalDistance = $cumulative;

        $centroids = $this->initCentroids($totalDistance, $k);
        $assignments = array_fill(0, $legCount, 0);

        for ($iter = 0; $iter < $this->maxIterations; $iter++) {
            $changed = false;

            // --- assignment step ---
            for ($i = 0; $i < $legCount; $i++) {
                $nearest = $this->nearestCentroidIndex($midpoints[$i], $centroids);
                if ($nearest !== $assignments[$i]) {
                    $assignments[$i] = $nearest;
                    $changed = true;
                }
            }

            // --- update step ---
            $sums = array_fill(0, $k, 0.0);
            $counts = array_fill(0, $k, 0);
            foreach ($assignments as $i => $cluster) {
                $sums[$cluster] += $midpoints[$i];
                $counts[$cluster]++;
            }

            for ($c = 0; $c < $k; $c++) {
                if ($counts[$c] > 0) {
                    $centroids[$c] = $sums[$c] / $counts[$c];
                }
                // Пустой кластер (редко, но возможно при неудачной
                // инициализации) — оставляем центроид как есть, следующая
                // итерация assignment step может снова его заполнить.
            }

            sort($centroids); // центроиды должны оставаться упорядоченными для монотонности дней

            if (!$changed) {
                break; // сошлись — дальнейшие итерации ничего не изменят
            }
        }

        // Финальное присвоение по сошедшимся центроидам.
        for ($i = 0; $i < $legCount; $i++) {
            $assignments[$i] = $this->nearestCentroidIndex($midpoints[$i], $centroids);
        }

        // Физическая подстраховка: маршрут проезжается строго по порядку,
        // поэтому номер дня не может уменьшаться по ходу движения — даже
        // если 1D K-Means в редком вырожденном случае (пустой кластер)
        // назначил бы что-то не монотонно, здесь это гарантированно чинится.
        $dayOfLeg = [];
        $maxSoFar = 0;
        foreach ($assignments as $i => $cluster) {
            $maxSoFar = max($maxSoFar, $cluster);
            $dayOfLeg[$i] = $maxSoFar;
        }

        // Группируем перегоны по дню.
        $days = [];
        $currentDay = $dayOfLeg[0];
        $start = 0;
        for ($i = 1; $i <= $legCount; $i++) {
            if ($i === $legCount || $dayOfLeg[$i] !== $currentDay) {
                $distance = array_sum(array_slice($legDistancesKm, $start, $i - $start));
                $days[] = [
                    'day' => count($days) + 1,
                    'leg_start' => $start,
                    'leg_end' => $i - 1,
                    'distance_km' => round($distance, 1),
                ];
                if ($i < $legCount) {
                    $currentDay = $dayOfLeg[$i];
                    $start = $i;
                }
            }
        }

        return $days;
    }

    /**
     * Предлагает разумное число дней исходя из целевого дневного пробега —
     * "сколько дней нужно, чтобы никто не ехал за рулём слишком долго".
     */
    public function suggestDays(float $totalDistanceKm, float $targetKmPerDay = 500.0): int
    {
        if ($totalDistanceKm <= 0 || $targetKmPerDay <= 0) {
            return 1;
        }

        return max(1, (int) ceil($totalDistanceKm / $targetKmPerDay));
    }

    /**
     * Равномерная детерминированная инициализация центроидов — для 1D
     * данных это надёжный и воспроизводимый старт (в отличие от K-Means
     * для многомерных данных, где случайные точки датасета — стандартный
     * выбор, здесь равномерная сетка по диапазону не хуже и не зависит от
     * генератора случайных чисел).
     *
     * @return float[]
     */
    private function initCentroids(float $totalDistance, int $k): array
    {
        if ($k === 1) {
            return [$totalDistance / 2];
        }

        $centroids = [];
        for ($c = 0; $c < $k; $c++) {
            // Центры k равных интервалов [0, totalDistance] — c-й центроид
            // в середине своего интервала.
            $centroids[] = $totalDistance * (2 * $c + 1) / (2 * $k);
        }

        return $centroids;
    }

    /**
     * @param float[] $centroids
     */
    private function nearestCentroidIndex(float $value, array $centroids): int
    {
        $bestIndex = 0;
        $bestDist = INF;

        foreach ($centroids as $i => $centroid) {
            $dist = abs($value - $centroid);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }
}
