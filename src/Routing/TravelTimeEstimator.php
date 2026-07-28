<?php

namespace App\Routing;

/**
 * Грубая оценка времени в пути по средней скорости движения.
 *
 * Используется как запасной вариант, когда точное время недоступно:
 * - OSRM даёт точное время в пути только для автомобиля (публичный демо-сервер
 *   поддерживает лишь профиль `driving`);
 * - для "пешком" и "общественный транспорт" точного расчёта нет вообще —
 *   оценка по средней скорости честно приблизительная, о чём и говорит
 *   пользователю UI ("≈" перед временем).
 */
class TravelTimeEstimator
{
    private const AVERAGE_SPEED_KMH = [
        'walk' => 5.0,
        'car' => 70.0,
        'bus' => 60.0,
    ];

    public function estimateMinutes(float $distanceKm, string $mode): float
    {
        $speed = self::AVERAGE_SPEED_KMH[$mode] ?? self::AVERAGE_SPEED_KMH['car'];

        return round(($distanceKm / $speed) * 60, 1);
    }

    /**
     * Человекочитаемое форматирование минут в "Xч Yмин" / "Yмин".
     */
    public function formatDuration(float $minutes): string
    {
        $totalMinutes = (int) round($minutes);
        $hours = intdiv($totalMinutes, 60);
        $mins = $totalMinutes % 60;

        if ($hours === 0) {
            return "{$mins} мин";
        }

        return "{$hours} ч {$mins} мин";
    }
}
