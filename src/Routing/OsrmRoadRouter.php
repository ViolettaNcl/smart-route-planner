<?php

namespace App\Routing;

use App\Geocoding\FileCache;
use App\Http\RateLimiter;
use App\Http\SafeHttpClient;

/**
 * OSRM-compatible road router with cache and provider failover.
 *
 * Production can point OSRM_ROUTE_ENDPOINTS at an ordered comma-separated
 * list of self-hosted/managed instances. Without configuration, the Project
 * OSRM demo is tried first and the FOSSGIS OpenStreetMap routing service is a
 * rate-limited reserve. A fresh file-cache avoids unnecessary public requests;
 * a stale cached road route can keep a repeated trip usable during a short
 * upstream outage. The straight-line fallback remains the final safe option.
 */
class OsrmRoadRouter implements RoadRouterInterface
{
    private const PUBLIC_ENDPOINT = 'https://router.project-osrm.org/route/v1/driving';
    private const FOSSGIS_ENDPOINT = 'https://routing.openstreetmap.de/routed-car/route/v1/driving';
    private const DEFAULT_CACHE_TTL_SECONDS = 21600;
    private const DEFAULT_STALE_TTL_SECONDS = 604800;

    /** @var list<string> */
    private array $endpoints;
    private string $userAgent;
    private int $alternatives;
    private int $cacheTtlSeconds;
    private int $staleTtlSeconds;

    /** @var \Closure(string, int, list<string>): ?string */
    private \Closure $httpGet;

    private ?string $lastProvider = null;

    /**
     * @param list<string>|null $endpoints
     * @param callable(string, int, list<string>): ?string|null $httpGet
     */
    public function __construct(
        private int $timeoutSeconds = 7,
        ?string $endpoint = null,
        ?int $alternatives = null,
        private ?FileCache $cache = null,
        ?array $endpoints = null,
        private ?RateLimiter $publicEndpointLimiter = null,
        ?callable $httpGet = null,
        ?int $cacheTtlSeconds = null,
        ?int $staleTtlSeconds = null,
    ) {
        $this->endpoints = $this->resolveEndpoints($endpoint, $endpoints);

        $configuredUserAgent = getenv('ROUTING_USER_AGENT');
        $this->userAgent = is_string($configuredUserAgent) && trim($configuredUserAgent) !== ''
            ? trim($configuredUserAgent)
            : 'smart-route-planner/2.0 (+https://github.com/ViolettaNcl/smart-route-planner)';

        $configuredAlternatives = $alternatives ?? (int) (getenv('OSRM_ALTERNATIVES') ?: 2);
        $this->alternatives = max(0, min($configuredAlternatives, 3));

        $configuredCacheTtl = $cacheTtlSeconds ?? (int) (getenv('OSRM_CACHE_TTL_SECONDS') ?: self::DEFAULT_CACHE_TTL_SECONDS);
        $configuredStaleTtl = $staleTtlSeconds ?? (int) (getenv('OSRM_STALE_TTL_SECONDS') ?: self::DEFAULT_STALE_TTL_SECONDS);
        $this->cacheTtlSeconds = max(60, min($configuredCacheTtl, 86400));
        $this->staleTtlSeconds = max($this->cacheTtlSeconds, min($configuredStaleTtl, 2592000));
        $this->httpGet = $httpGet !== null
            ? \Closure::fromCallable($httpGet)
            : static fn (string $url, int $timeout, array $headers): ?string => SafeHttpClient::get($url, $timeout, $headers);
    }

    public function route(array $orderedCoords): ?array
    {
        if (count($orderedCoords) < 2) {
            return null;
        }

        $cacheKey = $this->cacheKey($orderedCoords);
        $cached = $this->readCache($cacheKey);
        if ($cached !== null && $cached['age_seconds'] <= $this->cacheTtlSeconds) {
            return $this->cachedRoute($cached, 'fresh');
        }

        $coordsParam = implode(';', array_map(
            static fn (array $coord): string => $coord['lon'] . ',' . $coord['lat'],
            $orderedCoords
        ));

        $attempts = 0;
        foreach ($this->endpoints as $endpointIndex => $candidateEndpoint) {
            if (!$this->endpointRequestAllowed($candidateEndpoint)) {
                continue;
            }

            $attempts++;
            $url = $candidateEndpoint . '/' . $coordsParam . '?' . http_build_query([
                'overview' => 'full',
                'geometries' => 'geojson',
                'steps' => 'true',
                'alternatives' => $this->alternatives,
            ]);

            try {
                $response = ($this->httpGet)($url, $this->timeoutSeconds, [
                    "User-Agent: {$this->userAgent}",
                    'Accept: application/json',
                ]);
            } catch (\Throwable) {
                $response = null;
            }

            $result = $response !== null ? $this->normalizeResponse($response) : null;
            if ($result === null) {
                continue;
            }

            $provider = $this->providerForEndpoint($candidateEndpoint);
            $this->lastProvider = $provider;
            $result['provider'] = $provider;
            $result['cached'] = false;
            $result['cache_status'] = 'live';
            $result['failover_used'] = $endpointIndex > 0;
            $result['upstream_attempts'] = $attempts;
            $this->writeCache($cacheKey, $result);

            return $result;
        }

        if ($cached !== null && $cached['age_seconds'] <= $this->staleTtlSeconds) {
            $route = $this->cachedRoute($cached, 'stale');
            $route['upstream_attempts'] = $attempts;

            return $route;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function normalizeResponse(string $response): ?array
    {
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
        ];
    }

    /**
     * @param list<array{lat: float, lon: float}> $orderedCoords
     */
    private function cacheKey(array $orderedCoords): string
    {
        $coordinates = array_map(
            static fn (array $coord): array => [
                round((float) $coord['lat'], 5),
                round((float) $coord['lon'], 5),
            ],
            $orderedCoords
        );

        return 'osrm:' . hash('sha256', (string) json_encode([
            'coordinates' => $coordinates,
            'alternatives' => $this->alternatives,
            'endpoints' => $this->endpoints,
        ], JSON_UNESCAPED_SLASHES));
    }

    /** @return array{route: array<string, mixed>, cached_at: int, age_seconds: int}|null */
    private function readCache(string $cacheKey): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $entry = $this->cache->get($cacheKey);
        } catch (\Throwable) {
            return null;
        }

        $route = $entry['route'] ?? null;
        $cachedAt = $entry['cached_at'] ?? null;
        if (!is_array($route) || !is_numeric($cachedAt)) {
            return null;
        }

        $timestamp = (int) $cachedAt;

        return [
            'route' => $route,
            'cached_at' => $timestamp,
            'age_seconds' => max(0, time() - $timestamp),
        ];
    }

    /** @param array<string, mixed> $route */
    private function writeCache(string $cacheKey, array $route): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set($cacheKey, [
                'cached_at' => time(),
                'route' => $route,
            ]);
        } catch (\Throwable) {
            // Routing remains available when optional runtime cache is unwritable.
        }
    }

    /**
     * @param array{route: array<string, mixed>, cached_at: int, age_seconds: int} $cached
     * @return array<string, mixed>
     */
    private function cachedRoute(array $cached, string $status): array
    {
        $route = $cached['route'];
        $route['cached'] = true;
        $route['cache_status'] = $status;
        $route['cache_age_seconds'] = $cached['age_seconds'];
        $route['failover_used'] = (bool) ($route['failover_used'] ?? false);
        $provider = $route['provider'] ?? null;
        $this->lastProvider = is_string($provider) ? $provider : null;

        return $route;
    }

    private function endpointRequestAllowed(string $endpoint): bool
    {
        if ($this->publicEndpointLimiter === null || !$this->isPublicEndpoint($endpoint)) {
            return true;
        }

        $host = (string) (parse_url($endpoint, PHP_URL_HOST) ?: 'public-osrm');

        return $this->publicEndpointLimiter->attempt($host)['allowed'];
    }

    private function isPublicEndpoint(string $endpoint): bool
    {
        $host = strtolower((string) (parse_url($endpoint, PHP_URL_HOST) ?: ''));

        return in_array($host, ['router.project-osrm.org', 'routing.openstreetmap.de'], true);
    }

    /**
     * @param list<string>|null $endpoints
     * @return list<string>
     */
    private function resolveEndpoints(?string $endpoint, ?array $endpoints): array
    {
        $candidates = $endpoints;
        if ($candidates === null) {
            $configuredList = getenv('OSRM_ROUTE_ENDPOINTS');
            if (is_string($configuredList) && trim($configuredList) !== '') {
                $candidates = explode(',', $configuredList);
            } else {
                $configuredEndpoint = $endpoint ?? getenv('OSRM_ROUTE_ENDPOINT');
                $candidates = is_string($configuredEndpoint) && trim($configuredEndpoint) !== ''
                    ? [$configuredEndpoint]
                    : [self::PUBLIC_ENDPOINT, self::FOSSGIS_ENDPOINT];
            }
        }

        $valid = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = rtrim(trim($candidate), '/');
            if (preg_match('#^https?://#i', $normalized) === 1) {
                $valid[] = $normalized;
            }
        }

        $valid = array_values(array_unique($valid));

        return $valid !== [] ? $valid : [self::PUBLIC_ENDPOINT, self::FOSSGIS_ENDPOINT];
    }

    private function providerForEndpoint(string $endpoint): string
    {
        $host = strtolower((string) (parse_url($endpoint, PHP_URL_HOST) ?: ''));
        if ($host === 'router.project-osrm.org') {
            return 'osrm_public_demo';
        }
        if ($host === 'routing.openstreetmap.de') {
            return 'osrm_fossgis_public';
        }

        return 'osrm_configured';
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>|null
     */
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

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
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
        if ($this->lastProvider !== null) {
            return $this->lastProvider;
        }

        return count($this->endpoints) > 1
            ? 'osrm_failover_chain'
            : $this->providerForEndpoint($this->endpoints[0]);
    }
}
