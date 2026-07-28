<?php

namespace App\Routing;

/**
 * Оценка выбросов CO2 для поездки — по тем же усреднённым коэффициентам,
 * что использует, например, Google Flights/EU environment agency для
 * грубых прикидок (грамм CO2 на пассажиро-километр). Не претендует на
 * точность конкретного автомобиля/маршрута — цель фичи в другом: наглядно
 * показать пользователю разницу между видами транспорта, а не выдать
 * бухгалтерски точный отчёт об углеродном следе.
 */
class EmissionsEstimator
{
    private const G_PER_KM = [
        'walk' => 0,   // пешком — нулевой прямой след
        'car' => 120,  // легковой авто, средний расход, 1 пассажир (усреднённый ориентир EU/EEA)
        'bus' => 68,   // междугородний автобус/поезд на пассажира — обычно эффективнее авто
    ];

    /**
     * @return array{mode: string, co2_kg: float, comparison: array<string, float>}
     */
    public function estimate(float $distanceKm, string $mode): array
    {
        $comparison = [];
        foreach (self::G_PER_KM as $m => $gPerKm) {
            $comparison[$m] = round($distanceKm * $gPerKm / 1000, 1);
        }

        return [
            'mode' => $mode,
            'co2_kg' => $comparison[$mode] ?? 0.0,
            'comparison' => $comparison,
        ];
    }
}
