<?php

namespace App\Routing;

/**
 * Прикидочный расчёт стоимости поездки по предсказанному транспорту.
 *
 * Это не точный расчёт (реальные цены на топливо и билеты сильно зависят от
 * региона, марки авто, перевозчика и т.д.) — поэтому расчёт всегда явно
 * подписан как "≈" (примерно) на фронтенде. Пользователь может подставить
 * свои значения (цена топлива, расход, цена билета за км) вместо дефолтных —
 * см. параметры estimate().
 *
 * Правила расчёта:
 * - "car"  — топливо: (дистанция_км / 100) * расход_л_на_100км * цена_за_литр;
 * - "bus"  — билет: дистанция_км * цена_за_км, но не меньше минимальной
 *            стоимости проезда (base fare) — короткие поездки на автобусе
 *            всё равно стоят хотя бы фиксированную сумму;
 * - "walk" — бесплатно.
 */
class CostEstimator
{
    // Значения по умолчанию — грубые ориентиры для РФ, июль 2026.
    // Их всегда можно переопределить параметрами запроса (см. route.php).
    public const DEFAULT_FUEL_PRICE_PER_LITER = 60.0;      // ₽ / литр
    public const DEFAULT_FUEL_CONSUMPTION_L_100KM = 8.0;   // л / 100 км
    public const DEFAULT_TICKET_PRICE_PER_KM = 3.0;        // ₽ / км
    public const DEFAULT_TICKET_BASE_FARE = 100.0;         // ₽, минимальная стоимость билета

    /**
     * @return array{amount: float, currency: string, mode: string, basis: string}
     */
    public function estimate(
        float $distanceKm,
        string $mode,
        ?float $fuelPricePerLiter = null,
        ?float $fuelConsumptionL100km = null,
        ?float $ticketPricePerKm = null,
        ?float $ticketBaseFare = null,
    ): array {
        $fuelPricePerLiter ??= self::DEFAULT_FUEL_PRICE_PER_LITER;
        $fuelConsumptionL100km ??= self::DEFAULT_FUEL_CONSUMPTION_L_100KM;
        $ticketPricePerKm ??= self::DEFAULT_TICKET_PRICE_PER_KM;
        $ticketBaseFare ??= self::DEFAULT_TICKET_BASE_FARE;

        return match ($mode) {
            'car' => [
                'amount' => round(($distanceKm / 100) * $fuelConsumptionL100km * $fuelPricePerLiter),
                'currency' => 'RUB',
                'mode' => 'car',
                'basis' => 'fuel',
            ],
            'bus' => [
                'amount' => round(max($distanceKm * $ticketPricePerKm, $ticketBaseFare)),
                'currency' => 'RUB',
                'mode' => 'bus',
                'basis' => 'ticket',
            ],
            default => [
                'amount' => 0.0,
                'currency' => 'RUB',
                'mode' => $mode,
                'basis' => 'free',
            ],
        };
    }

    /**
     * Санитизация пользовательского ввода (числовые параметры из формы):
     * отбрасывает отрицательные/нулевые/нечисловые значения, возвращает null,
     * чтобы вызывающий код мог подставить дефолт.
     */
    public static function sanitizePositive(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }
}
