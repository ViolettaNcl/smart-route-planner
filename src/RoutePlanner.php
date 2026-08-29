<?php

namespace App;

use App\Geocoding\GeocoderInterface;
use App\ML\TransportPredictor;
use App\Routing\CostEstimator;
use App\Routing\EmissionsEstimator;
use App\Routing\HaversineCalculator;
use App\Routing\RoadRouterInterface;
use App\Routing\RouteOptimizer;
use App\Routing\TravelTimeEstimator;
use App\Support\Logger;
use App\Support\RuntimeStorage;

/**
 * Основной сервис приложения: принимает сырой пользовательский ввод,
 * возвращает полностью посчитанный маршрут — геокодированные точки,
 * оптимизированный порядок обхода, суммарную дистанцию, предсказанный
 * транспорт и ссылки на карты.
 *
 * Никакого обращения к $_POST/$_GET внутри — весь ввод приходит параметром,
 * поэтому класс легко покрыть тестами без поднятия HTTP-сервера.
 */
class RoutePlanner
{
    private Logger $logger;

    public function __construct(
        private GeocoderInterface $geocoder,
        private HaversineCalculator $calculator,
        private RouteOptimizer $optimizer,
        private TransportPredictor $predictor,
        private ?RoadRouterInterface $roadRouter = null,
        private ?TravelTimeEstimator $timeEstimator = null,
        private ?CostEstimator $costEstimator = null,
        private ?EmissionsEstimator $emissionsEstimator = null,
        ?Logger $logger = null,
    ) {
        $this->timeEstimator ??= new TravelTimeEstimator();
        $this->costEstimator ??= new CostEstimator();
        $this->emissionsEstimator ??= new EmissionsEstimator();
        $this->logger = $logger ?? new Logger(RuntimeStorage::path('app.log'));
    }

    /**
     * @param string $rawPoints Строка городов, разделённых ';'
     * @param array{fuel_price_per_liter?: float|null, fuel_consumption_l_100km?: float|null,
     *              ticket_price_per_km?: float|null, ticket_base_fare?: float|null} $costParams
     *              Необязательные параметры расчёта стоимости поездки — если не заданы,
     *              используются дефолты CostEstimator.
     * @return array<string, mixed>
     */
    public function plan(string $rawPoints, array $costParams = []): array
    {
        $requestedPoints = $this->parsePoints($rawPoints);

        [$validPoints, $coords, $skipped] = $this->geocodeAll($requestedPoints);

        if (count($validPoints) < 2) {
            return [
                'ok' => false,
                'error' => 'Нужно минимум 2 распознанных города, чтобы построить маршрут.',
                'error_code' => 'MIN_TWO_POINTS',
                'skipped' => $skipped,
            ];
        }

        $optimizedOrder = $this->optimizer->optimize($validPoints, $coords);
        $orderedCoords = array_map(fn ($p) => $coords[$p], $optimizedOrder);

        $airDistance = round($this->calculator->totalDistanceKm($orderedCoords));

        $roadRoute = $this->roadRouter?->route($orderedCoords);

        if ($this->roadRouter !== null && $roadRoute === null) {
            // OSRM был сконфигурирован, но не ответил (сервис недоступен,
            // rate limit, таймаут) — приложение продолжает работать на
            // Haversine-дистанции, но это стоит видеть в логах, а не только
            // в honest-метке routing_source в ответе API.
            $this->logger->warning('OSRM routing unavailable, falling back to great-circle distance', [
                'points' => count($orderedCoords),
            ]);
        }

        // Реальная дорожная дистанция обычно длиннее прямой "по воздуху" —
        // используем её, если удалось построить маршрут (точнее для пользователя
        // и для модели: прогноз транспорта опирается на более честное число).
        $distance = $roadRoute['distance_km'] ?? $airDistance;
        $prediction = $this->predictor->predict($distance, count($optimizedOrder));

        $duration = $this->resolveDuration($roadRoute, $distance, $prediction['mode']);

        $cost = $this->costEstimator->estimate(
            distanceKm: $distance,
            mode: $prediction['mode'],
            fuelPricePerLiter: CostEstimator::sanitizePositive($costParams['fuel_price_per_liter'] ?? null),
            fuelConsumptionL100km: CostEstimator::sanitizePositive($costParams['fuel_consumption_l_100km'] ?? null),
            ticketPricePerKm: CostEstimator::sanitizePositive($costParams['ticket_price_per_km'] ?? null),
            ticketBaseFare: CostEstimator::sanitizePositive($costParams['ticket_base_fare'] ?? null),
        );

        return [
            'ok' => true,
            'points' => $optimizedOrder,
            'coords' => $orderedCoords,
            'distance_km' => $distance,
            'routing_source' => $roadRoute !== null ? 'osrm_road' : 'straight_line',
            'route_geometry' => $roadRoute['geometry'] ?? $orderedCoords, // для карты
            'duration' => $duration,
            'stops' => count($optimizedOrder),
            'transport' => $prediction,
            'cost' => $cost,
            'emissions' => $this->emissionsEstimator->estimate($distance, $prediction['mode']),
            'skipped' => $skipped,
            'maps' => [
                'google' => $this->googleMapsUrl($optimizedOrder),
                'yandex' => $this->yandexMapsUrl($optimizedOrder),
            ],
            'calculated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array{distance_km: float, duration_min: float, geometry: array<int, array{0: float, 1: float}>}|null $roadRoute
     * @return array{minutes: float, label: string, exact: bool}
     */
    private function resolveDuration(?array $roadRoute, float $distanceKm, string $mode): array
    {
        // Точное время в пути от OSRM доступно только для автомобиля
        // (публичный демо-сервер поддерживает лишь профиль driving).
        if ($roadRoute !== null && $mode === 'car') {
            $minutes = $roadRoute['duration_min'];
            return [
                'minutes' => $minutes,
                'label' => $this->timeEstimator->formatDuration($minutes),
                'exact' => true,
            ];
        }

        $minutes = $this->timeEstimator->estimateMinutes($distanceKm, $mode);

        return [
            'minutes' => $minutes,
            'label' => '≈ ' . $this->timeEstimator->formatDuration($minutes),
            'exact' => false,
        ];
    }

    /**
     * @return string[]
     */
    private function parsePoints(string $raw): array
    {
        $points = array_map('trim', explode(';', $raw));

        return array_values(array_filter($points, fn ($p) => $p !== ''));
    }

    /**
     * @param string[] $requestedPoints
     * @return array{0: string[], 1: array<string, array{lat: float, lon: float}>, 2: string[]}
     */
    private function geocodeAll(array $requestedPoints): array
    {
        $valid = [];
        $coords = [];
        $skipped = [];

        foreach ($requestedPoints as $point) {
            $c = $this->geocoder->geocode($point);

            if ($c === null) {
                $skipped[] = $point;
                continue;
            }

            $valid[] = $point;
            $coords[$point] = $c;
        }

        return [$valid, $coords, $skipped];
    }

    /**
     * @param string[] $points
     */
    private function googleMapsUrl(array $points): string
    {
        return 'https://www.google.com/maps/dir/' . implode('/', array_map('urlencode', $points));
    }

    /**
     * @param string[] $points
     */
    private function yandexMapsUrl(array $points): string
    {
        return 'https://yandex.ru/maps/?rtext=' . implode('~', array_map('urlencode', $points)) . '&rtt=auto';
    }
}
