<?php

namespace App\Routing;

use App\Http\SafeHttpClient;

/**
 * OSRM-compatible road router with an honest demo-service fallback.
 *
 * Production can point OSRM_ROUTE_ENDPOINT at a self-hosted/managed instance;
 * otherwise the public Project OSRM demo endpoint is used without assuming an
 * SLA. The response keeps the legacy primary-route fields and adds normalized
 * alternatives plus machine-readable navigation steps.
 */
class OsrmRoadRouter implements RoadRouterInterface
{
    private const PUBLIC_ENDPOINT = 'https://router.project-osrm.org/route/v1/driving';

    private string $endpoint;
    private string $userAgent;
    private int $alternatives;

    public function __construct(
        private int $timeoutSeconds = 7,
        ?string $endpoint = null,
        ?int $alternatives = null,
    ) {
        $configuredEndpoint = $endpoint ?? getenv('OSRM_ROUTE_ENDPOINT');
        $candidate = is_string($configuredEndpoint) ? rtrim(trim($configuredEndpoint), '/') : '';
        $this->endpoint = preg_match('#^https?://#i', $candidate) === 1
            ? $candidate
            : self::PUBLIC_ENDPOINT;

        $configuredUserAgent = getenv('ROUTING_USER_AGENT');
        $this->userAgent = is_string($configuredUserAgent) && trim($configuredUserAgent) !== ''
            ? trim($configuredUserAgent)
            : 'smart-route-planner/2.0 (+https://github.com/ViolettaNcl/smart-route-planner)';

        $configuredAlternatives = $alternatives ?? (int) (getenv('OSRM_ALTERNATIVES') ?: 2);
        $this->alternatives = max(0, min($configuredAlternatives, 3));
    }

    public function route(array $orderedCoords): ?array
    {
        if (count($orderedCoords) < 2) {
            return null;
        }

        $coordsParam = implode(';', array_map(
            static fn (array $coord): string => $coord['lon'] . ',' . $coord['lat'],
            $orderedCoords
        ));

        $url = $this->endpoint . '/' . $coordsParam . '?' . http_build_query([
            'overview' => 'full',
            'geometries' => 'geojson',
            'steps' => 'true',
            'alternatives' => $this->alternatives,
        ]);

        $response = SafeHttpClient::get($url, $this->timeoutSeconds, [
            "User-Agent: {$this->userAgent}",
            'Accept: application/json',
        ]);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['code'] ?? null) !== 'Ok' || empty($data['routes'][0])) {
            return null;
        }

        $options = [];
        foreach (array_slice($data['routes'], 0, 1 + $this->alternatives) as $index => $route) {
            if (!is_array($route)) {
                continue;
            }
            $normalized = $this->normalizeRoute($route, $index);
            if ($normalized !== null) {
                $options[] = $normalized;
            }
        }

        if ($options === []) {
            return null;
        }

        $primary = $options[0];

        return [
            'distance_km' => $primary['distance_km'],
            'duration_min' => $primary['duration_min'],
            'geometry' => $primary['geometry'],
            'legs' => $primary['legs'],
            'options' => $options,
            'provider' => $this->providerName(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeRoute(array $route, int $index): ?array
    {
        $coordinates = $route['geometry']['coordinates'] ?? null;
        if (!is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }
        $geometry = $this->normalizeGeometry($coordinates, 2200);
        if (count($geometry) < 2) {
            return null;
        }

        $legs = [];
        foreach (($route['legs'] ?? []) as $legIndex => $leg) {
            if (!is_array($leg)) {
                continue;
            }

            $steps = [];
            foreach (($leg['steps'] ?? []) as $stepIndex => $step) {
                if (!is_array($step)) {
                    continue;
                }
                $steps[] = $this->normalizeStep($step, (int) $legIndex, (int) $stepIndex);
            }

            $legs[] = [
                'index' => (int) $legIndex,
                'distance_km' => round(((float) ($leg['distance'] ?? 0)) / 1000, 1),
                'duration_min' => round(((float) ($leg['duration'] ?? 0)) / 60, 1),
                'summary' => (string) ($leg['summary'] ?? ''),
                'steps' => $steps,
            ];
        }

        return [
            'id' => 'route-' . ($index + 1),
            'rank' => $index + 1,
            'distance_km' => round(((float) ($route['distance'] ?? 0)) / 1000, 1),
            'duration_min' => round(((float) ($route['duration'] ?? 0)) / 60, 1),
            'weight' => isset($route['weight']) ? round((float) $route['weight'], 1) : null,
            'geometry' => $geometry,
            'legs' => $legs,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeStep(array $step, int $legIndex, int $stepIndex): array
    {
        $maneuver = is_array($step['maneuver'] ?? null) ? $step['maneuver'] : [];
        $location = $maneuver['location'] ?? null;
        $normalizedLocation = is_array($location) && count($location) >= 2
            ? ['lat' => (float) $location[1], 'lon' => (float) $location[0]]
            : null;
        $geometry = $step['geometry']['coordinates'] ?? [];

        return [
            'id' => 'leg-' . ($legIndex + 1) . '-step-' . ($stepIndex + 1),
            'leg_index' => $legIndex,
            'step_index' => $stepIndex,
            'distance_m' => round((float) ($step['distance'] ?? 0)),
            'duration_min' => round(((float) ($step['duration'] ?? 0)) / 60, 1),
            'name' => (string) ($step['name'] ?? ''),
            'ref' => (string) ($step['ref'] ?? ''),
            'destinations' => (string) ($step['destinations'] ?? ''),
            'exits' => (string) ($step['exits'] ?? ''),
            'mode' => (string) ($step['mode'] ?? 'driving'),
            'maneuver' => [
                'type' => (string) ($maneuver['type'] ?? 'continue'),
                'modifier' => (string) ($maneuver['modifier'] ?? 'straight'),
                'exit' => isset($maneuver['exit']) ? (int) $maneuver['exit'] : null,
                'bearing_before' => isset($maneuver['bearing_before']) ? (int) $maneuver['bearing_before'] : null,
                'bearing_after' => isset($maneuver['bearing_after']) ? (int) $maneuver['bearing_after'] : null,
                'location' => $normalizedLocation,
            ],
            'geometry' => is_array($geometry) ? $this->normalizeGeometry($geometry, 140) : [],
        ];
    }

    /**
     * @param array<int, mixed> $coordinates GeoJSON [lon, lat]
     * @return array<int, array{0: float, 1: float}> Application [lat, lon]
     */
    private function normalizeGeometry(array $coordinates, int $maxPoints): array
    {
        $valid = [];
        foreach ($coordinates as $point) {
            if (!is_array($point) || count($point) < 2) {
                continue;
            }
            $lon = (float) $point[0];
            $lat = (float) $point[1];
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }
            $valid[] = [$lat, $lon];
        }

        if (count($valid) <= $maxPoints) {
            return $valid;
        }

        $step = (int) ceil(count($valid) / $maxPoints);
        $sampled = [];
        foreach ($valid as $index => $point) {
            if ($index % $step === 0) {
                $sampled[] = $point;
            }
        }
        $last = $valid[array_key_last($valid)];
        if ($sampled[array_key_last($sampled)] !== $last) {
            $sampled[] = $last;
        }

        return $sampled;
    }

    public function providerName(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === 'router.project-osrm.org'
            ? 'osrm_public_demo'
            : 'osrm_configured';
    }
}
