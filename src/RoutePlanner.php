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
 * Application service for geocoding, stop-order optimisation and routing.
 *
 * The legacy semicolon-separated API remains available through plan(), while
 * planStops() is the canonical contract used by the structured route editor.
 */
class RoutePlanner
{
    public const MAX_STOPS = 12;

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
     * Backward-compatible entry point for the original textarea contract.
     *
     * @param array{fuel_price_per_liter?: mixed, fuel_consumption_l_100km?: mixed,
     *              ticket_price_per_km?: mixed, ticket_base_fare?: mixed} $costParams
     * @return array<string, mixed>
     */
    public function plan(string $rawPoints, array $costParams = []): array
    {
        $stops = array_map(
            static fn (string $label): array => ['label' => $label],
            $this->parsePoints($rawPoints)
        );

        return $this->planStops($stops, $costParams, true);
    }

    /**
     * @param array<int, mixed> $inputStops
     * @param array{fuel_price_per_liter?: mixed, fuel_consumption_l_100km?: mixed,
     *              ticket_price_per_km?: mixed, ticket_base_fare?: mixed} $costParams
     * @return array<string, mixed>
     */
    public function planStops(array $inputStops, array $costParams = [], bool $optimizeOrder = true): array
    {
        if (count($inputStops) > self::MAX_STOPS) {
            return [
                'ok' => false,
                'error' => 'Можно добавить не более ' . self::MAX_STOPS . ' точек.',
                'error_code' => 'TOO_MANY_STOPS',
                'max_stops' => self::MAX_STOPS,
            ];
        }

        $requestedStops = $this->normalizeStops($inputStops);
        [$validStops, $coordsById, $skipped] = $this->resolveStops($requestedStops);

        if (count($validStops) < 2) {
            return [
                'ok' => false,
                'error' => 'Нужно минимум 2 распознанные точки, чтобы построить маршрут.',
                'error_code' => 'MIN_TWO_POINTS',
                'skipped' => $skipped,
            ];
        }

        $inputIds = [];
        foreach ($validStops as $stop) {
            $inputIds[] = $stop['id'];
        }
        $orderedIds = $optimizeOrder
            ? $this->optimizer->optimize($inputIds, $coordsById, true)
            : $inputIds;
        $stopsById = [];
        foreach ($validStops as $stop) {
            $stopsById[$stop['id']] = $stop;
        }

        $orderedStops = array_map(
            static fn (string $id): array => $stopsById[$id],
            $orderedIds
        );
        $orderedCoords = array_map(
            static fn (string $id): array => $coordsById[$id],
            $orderedIds
        );
        $orderedLabels = array_column($orderedStops, 'label');

        $airDistance = round($this->calculator->totalDistanceKm($orderedCoords), 1);
        $roadRoute = $this->roadRouter?->route($orderedCoords);
        if ($this->roadRouter !== null && $roadRoute === null) {
            $this->logger->warning('routing_fallback', [
                'stop_count' => count($orderedCoords),
                'fallback' => 'great_circle',
            ]);
        }

        $primaryDistance = (float) ($roadRoute['distance_km'] ?? $airDistance);
        $prediction = $this->predictor->predict($primaryDistance, count($orderedIds));
        $routeOptions = $this->buildRouteOptions(
            $roadRoute,
            $orderedCoords,
            $prediction['mode'],
            $costParams,
            $airDistance
        );
        $primary = $routeOptions[0];

        return [
            'ok' => true,
            'requested_points' => array_column($requestedStops, 'label'),
            'points' => $orderedLabels,
            'coords' => $orderedCoords,
            'route_stops' => array_map(
                static fn (array $stop, array $coord): array => [
                    'id' => $stop['id'],
                    'label' => $stop['label'],
                    'lat' => $coord['lat'],
                    'lon' => $coord['lon'],
                    'coordinate_source' => $stop['coordinate_source'],
                ],
                $orderedStops,
                $orderedCoords
            ),
            'optimized' => $orderedIds !== $inputIds,
            'optimize_order' => $optimizeOrder,
            'distance_km' => $primary['distance_km'],
            'routing_source' => $roadRoute !== null ? 'osrm_road' : 'straight_line',
            'routing_provider' => $roadRoute['provider'] ?? 'great_circle_fallback',
            'routing_cached' => (bool) ($roadRoute['cached'] ?? false),
            'routing_cache_status' => $roadRoute['cache_status'] ?? 'none',
            'routing_failover_used' => (bool) ($roadRoute['failover_used'] ?? false),
            'route_geometry' => $primary['geometry'],
            'route_options' => $routeOptions,
            'legs' => $primary['legs'],
            'duration' => $primary['duration'],
            'stops' => count($orderedIds),
            'transport' => $prediction,
            'cost' => $primary['cost'],
            'emissions' => $primary['emissions'],
            'skipped' => $skipped,
            'maps' => [
                'google' => $this->googleMapsUrl($orderedLabels),
                'yandex' => $this->yandexMapsUrl($orderedLabels),
            ],
            'calculated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed>|null $roadRoute
     * @param array<int, array{lat: float, lon: float}> $orderedCoords
     * @param array<string, mixed> $costParams
     * @return array<int, array<string, mixed>>
     */
    private function buildRouteOptions(
        ?array $roadRoute,
        array $orderedCoords,
        string $mode,
        array $costParams,
        float $airDistance
    ): array {
        $rawOptions = [];
        if (is_array($roadRoute['options'] ?? null) && $roadRoute['options'] !== []) {
            $rawOptions = $roadRoute['options'];
        } elseif ($roadRoute !== null) {
            $rawOptions[] = [
                'id' => 'route-1',
                'rank' => 1,
                'distance_km' => $roadRoute['distance_km'],
                'duration_min' => $roadRoute['duration_min'],
                'geometry' => $roadRoute['geometry'] ?? $orderedCoords,
                'legs' => $roadRoute['legs'] ?? [],
            ];
        } else {
            $rawOptions[] = [
                'id' => 'route-1',
                'rank' => 1,
                'distance_km' => $airDistance,
                'duration_min' => null,
                'geometry' => $orderedCoords,
                'legs' => [],
            ];
        }

        $options = [];
        foreach (array_slice($rawOptions, 0, 3) as $index => $rawOption) {
            if (!is_array($rawOption)) {
                continue;
            }
            $distance = max(0.0, (float) ($rawOption['distance_km'] ?? $airDistance));
            $roadDuration = isset($rawOption['duration_min']) && is_numeric($rawOption['duration_min'])
                ? (float) $rawOption['duration_min']
                : null;
            $durationRoute = $roadDuration === null ? null : ['duration_min' => $roadDuration];
            $duration = $this->resolveDuration($durationRoute, $distance, $mode);

            $options[] = [
                'id' => (string) ($rawOption['id'] ?? ('route-' . ($index + 1))),
                'rank' => $index + 1,
                'distance_km' => round($distance, 1),
                'driving_duration_min' => $roadDuration,
                'duration' => $duration,
                'geometry' => is_array($rawOption['geometry'] ?? null)
                    ? $rawOption['geometry']
                    : $orderedCoords,
                'legs' => is_array($rawOption['legs'] ?? null) ? $rawOption['legs'] : [],
                'navigation_available' => $this->hasNavigationSteps($rawOption['legs'] ?? []),
                'cost' => $this->estimateCost($distance, $mode, $costParams),
                'emissions' => $this->emissionsEstimator->estimate($distance, $mode),
                'source' => $roadRoute !== null ? 'osrm_road' : 'straight_line',
            ];
        }

        if ($options === []) {
            $distance = max(0.0, $airDistance);
            $options[] = [
                'id' => 'route-1',
                'rank' => 1,
                'distance_km' => round($distance, 1),
                'driving_duration_min' => null,
                'duration' => $this->resolveDuration(null, $distance, $mode),
                'geometry' => $orderedCoords,
                'legs' => [],
                'navigation_available' => false,
                'cost' => $this->estimateCost($distance, $mode, $costParams),
                'emissions' => $this->emissionsEstimator->estimate($distance, $mode),
                'source' => 'straight_line',
            ];
        }

        return $options;
    }

    /** @param mixed $legs */
    private function hasNavigationSteps(mixed $legs): bool
    {
        if (!is_array($legs)) {
            return false;
        }
        foreach ($legs as $leg) {
            if (is_array($leg) && !empty($leg['steps'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $roadRoute
     * @return array{minutes: float, label: string, exact: bool}
     */
    private function resolveDuration(?array $roadRoute, float $distanceKm, string $mode): array
    {
        if ($roadRoute !== null && $mode === 'car') {
            $minutes = (float) $roadRoute['duration_min'];

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
     * @param array<string, mixed> $costParams
     * @return array{amount: float, currency: string, mode: string, basis: string}
     */
    private function estimateCost(float $distance, string $mode, array $costParams): array
    {
        return $this->costEstimator->estimate(
            distanceKm: $distance,
            mode: $mode,
            fuelPricePerLiter: CostEstimator::sanitizePositive($costParams['fuel_price_per_liter'] ?? null),
            fuelConsumptionL100km: CostEstimator::sanitizePositive($costParams['fuel_consumption_l_100km'] ?? null),
            ticketPricePerKm: CostEstimator::sanitizePositive($costParams['ticket_price_per_km'] ?? null),
            ticketBaseFare: CostEstimator::sanitizePositive($costParams['ticket_base_fare'] ?? null),
        );
    }

    /** @return string[] */
    private function parsePoints(string $raw): array
    {
        $points = array_map('trim', explode(';', $raw));

        return array_values(array_filter($points, static fn (string $point): bool => $point !== ''));
    }

    /**
     * @param array<int, mixed> $inputStops
     * @return array<int, array{id: string, label: string, lat: float|null, lon: float|null, coordinate_source: string}>
     */
    private function normalizeStops(array $inputStops): array
    {
        $normalized = [];
        foreach ($inputStops as $index => $inputStop) {
            $stop = is_array($inputStop) ? $inputStop : ['label' => $inputStop];
            $labelValue = $stop['label'] ?? '';
            $label = is_scalar($labelValue) ? trim((string) $labelValue) : '';
            $lat = $this->validLatitude($stop['lat'] ?? null);
            $lon = $this->validLongitude($stop['lon'] ?? null);
            if ($label === '' && $lat !== null && $lon !== null) {
                $label = sprintf('Точка %d (%.5f, %.5f)', $index + 1, $lat, $lon);
            }
            if ($label === '') {
                continue;
            }

            $normalized[] = [
                'id' => 'stop-' . ($index + 1),
                'label' => mb_substr($label, 0, 180),
                'lat' => $lat,
                'lon' => $lon,
                'coordinate_source' => $lat !== null && $lon !== null ? 'provided' : 'geocoded',
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{id: string, label: string, lat: float|null, lon: float|null, coordinate_source: string}> $stops
     * @return array{0: array<int, array{id: string, label: string, lat: float|null, lon: float|null, coordinate_source: string}>, 1: array<string, array{lat: float, lon: float}>, 2: string[]}
     */
    private function resolveStops(array $stops): array
    {
        $valid = [];
        $coords = [];
        $skipped = [];

        foreach ($stops as $stop) {
            $coordinate = $stop['lat'] !== null && $stop['lon'] !== null
                ? ['lat' => $stop['lat'], 'lon' => $stop['lon']]
                : $this->geocoder->geocode($stop['label']);
            if ($coordinate === null) {
                $skipped[] = $stop['label'];
                continue;
            }

            $valid[] = $stop;
            $coords[$stop['id']] = $coordinate;
        }

        return [$valid, $coords, $skipped];
    }

    private function validLatitude(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $latitude = (float) $value;

        return $latitude >= -90 && $latitude <= 90 ? $latitude : null;
    }

    private function validLongitude(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $longitude = (float) $value;

        return $longitude >= -180 && $longitude <= 180 ? $longitude : null;
    }

    /** @param string[] $points */
    private function googleMapsUrl(array $points): string
    {
        return 'https://www.google.com/maps/dir/' . implode('/', array_map('urlencode', $points));
    }

    /** @param string[] $points */
    private function yandexMapsUrl(array $points): string
    {
        return 'https://yandex.ru/maps/?rtext=' . implode('~', array_map('urlencode', $points)) . '&rtt=auto';
    }
}
