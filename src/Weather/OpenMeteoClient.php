<?php

namespace App\Weather;

/**
 * Погода по точкам маршрута через Open-Meteo (open-meteo.com) — бесплатный
 * публичный API без ключа и без регистрации. Для каждой точки маршрута
 * запрашивается текущая температура, вероятность осадков и общий код погоды
 * (WMO weather code), который переводится в человекочитаемое описание и
 * иконку.
 *
 * Как и с OsrmRoadRouter/NominatimGeocoder — это бесплатный публичный
 * сервис без SLA, поэтому все сетевые ошибки гасятся внутри класса:
 * вызывающий код получает null для точки, которую не удалось запросить,
 * вместо падения всего маршрута.
 */
class OpenMeteoClient
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    /**
     * Пороговые значения, при которых точка помечается предупреждением —
     * используются и во фронтенде (бейдж ⚠️), и в AI-ассистенте (текстовое
     * предупреждение о погоде на маршруте).
     */
    private const HEAVY_RAIN_PROBABILITY = 50;   // %, вероятность осадков
    private const HOT_TEMP_C = 30.0;
    private const COLD_TEMP_C = -15.0;

    /** @var array<int, array{code: int, min: int, description_ru: string, icon: string}> */
    private const WEATHER_CODES = [
        // Диапазоны сгруппированы по семействам кодов WMO (open-meteo использует
        // их как есть, см. https://open-meteo.com/en/docs — раздел WMO Weather codes).
        ['min' => 0, 'max' => 0, 'description_ru' => 'ясно', 'icon' => '☀️'],
        ['min' => 1, 'max' => 2, 'description_ru' => 'малооблачно', 'icon' => '🌤️'],
        ['min' => 3, 'max' => 3, 'description_ru' => 'облачно', 'icon' => '☁️'],
        ['min' => 45, 'max' => 48, 'description_ru' => 'туман', 'icon' => '🌫️'],
        ['min' => 51, 'max' => 57, 'description_ru' => 'морось', 'icon' => '🌦️'],
        ['min' => 61, 'max' => 67, 'description_ru' => 'дождь', 'icon' => '🌧️'],
        ['min' => 71, 'max' => 77, 'description_ru' => 'снег', 'icon' => '🌨️'],
        ['min' => 80, 'max' => 82, 'description_ru' => 'ливень', 'icon' => '🌧️'],
        ['min' => 85, 'max' => 86, 'description_ru' => 'снегопад', 'icon' => '🌨️'],
        ['min' => 95, 'max' => 99, 'description_ru' => 'гроза', 'icon' => '⛈️'],
    ];

    public function __construct(private int $timeoutSeconds = 5)
    {
    }

    /**
     * @param array<int, array{lat: float, lon: float, label?: string}> $points
     * @return array<int, array{lat: float, lon: float, label: ?string, temperature_c: ?float,
     *                           precipitation_probability: ?int, description_ru: string, icon: string,
     *                           warning: bool, warning_reason: ?string}|null>
     */
    public function forecastForPoints(array $points): array
    {
        $results = [];
        foreach ($points as $point) {
            $results[] = $this->forecastForPoint($point['lat'], $point['lon'], $point['label'] ?? null);
        }

        return $results;
    }

    /**
     * @return array{lat: float, lon: float, label: ?string, temperature_c: ?float,
     *               precipitation_probability: ?int, description_ru: string, icon: string,
     *               warning: bool, warning_reason: ?string}
     */
    public function forecastForPoint(float $lat, float $lon, ?string $label = null): array
    {
        $fallback = [
            'lat' => $lat,
            'lon' => $lon,
            'label' => $label,
            'temperature_c' => null,
            'precipitation_probability' => null,
            'description_ru' => 'нет данных',
            'icon' => '❔',
            'warning' => false,
            'warning_reason' => null,
        ];

        $url = self::ENDPOINT . '?' . http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,precipitation_probability,weather_code',
            'timezone' => 'auto',
            'forecast_days' => 1,
        ]);

        $body = $this->fetch($url);

        if ($body === null) {
            return $fallback;
        }

        $data = json_decode($body, true);
        $current = $data['current'] ?? null;

        if (!is_array($current) || !isset($current['weather_code'])) {
            return $fallback;
        }

        $code = (int) $current['weather_code'];
        $temp = isset($current['temperature_2m']) ? (float) $current['temperature_2m'] : null;
        $precipProb = isset($current['precipitation_probability'])
            ? (int) $current['precipitation_probability']
            : null;

        $weatherInfo = $this->describeCode($code);

        [$warning, $reason] = $this->evaluateWarning($temp, $precipProb, $weatherInfo['description_ru']);

        return [
            'lat' => $lat,
            'lon' => $lon,
            'label' => $label,
            'temperature_c' => $temp,
            'precipitation_probability' => $precipProb,
            'description_ru' => $weatherInfo['description_ru'],
            'icon' => $weatherInfo['icon'],
            'warning' => $warning,
            'warning_reason' => $reason,
        ];
    }

    /**
     * @return array{description_ru: string, icon: string}
     */
    private function describeCode(int $code): array
    {
        foreach (self::WEATHER_CODES as $entry) {
            if ($code >= $entry['min'] && $code <= $entry['max']) {
                return ['description_ru' => $entry['description_ru'], 'icon' => $entry['icon']];
            }
        }

        return ['description_ru' => 'переменная облачность', 'icon' => '🌡️'];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function evaluateWarning(?float $temp, ?int $precipProb, string $description): array
    {
        if ($precipProb !== null && $precipProb >= self::HEAVY_RAIN_PROBABILITY) {
            return [true, "высокая вероятность осадков ({$precipProb}%)"];
        }

        if ($temp !== null && $temp >= self::HOT_TEMP_C) {
            return [true, 'сильная жара (' . round($temp) . '°C)'];
        }

        if ($temp !== null && $temp <= self::COLD_TEMP_C) {
            return [true, 'сильный мороз (' . round($temp) . '°C)'];
        }

        if (str_contains($description, 'гроза')) {
            return [true, 'гроза'];
        }

        return [false, null];
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
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
