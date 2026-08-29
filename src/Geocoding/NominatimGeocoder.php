<?php

namespace App\Geocoding;

use App\Http\SafeHttpClient;

/**
 * Геокодер на основе публичного API OpenStreetMap Nominatim.
 *
 * По сравнению с первой версией проекта:
 * - используется короткий сетевой таймаут;
 * - результаты кэшируются на диске (см. FileCache) — тот же город второй раз
 *   не запрашивается по сети;
 * - выдерживается пауза между запросами согласно политике использования
 *   Nominatim (не более 1 запроса в секунду);
 * - сетевой слой не зависит жёстко от ext-curl: на serverless/Vercel есть
 *   fallback на PHP streams, чтобы отсутствие curl не роняло весь API.
 */
class NominatimGeocoder implements GeocoderInterface
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const MIN_INTERVAL_SECONDS = 1.0;

    private ?FileCache $cache;
    private string $userAgent;
    private int $timeoutSeconds;
    private float $lastRequestAt = 0.0;

    public function __construct(
        ?FileCache $cache = null,
        string $userAgent = 'smart-route-planner (portfolio project)',
        int $timeoutSeconds = 5
    ) {
        $this->cache = $cache;
        $this->userAgent = $userAgent;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function geocode(string $place): ?array
    {
        $place = trim($place);

        if ($place === '') {
            return null;
        }

        if ($this->cache !== null) {
            $cached = $this->cache->get($place);
            if ($cached !== null) {
                return $cached['found'] ? ['lat' => $cached['lat'], 'lon' => $cached['lon']] : null;
            }
        }

        $result = $this->fetchFromApi($place);

        if ($this->cache !== null) {
            $this->cache->set($place, $result !== null
                ? ['found' => true, 'lat' => $result['lat'], 'lon' => $result['lon']]
                : ['found' => false]);
        }

        return $result;
    }

    /**
     * Автоподсказки городов при вводе (для UI): в отличие от geocode(),
     * возвращает несколько вариантов с человекочитаемым названием, а не
     * только координаты одного лучшего совпадения. Использует тот же
     * rate-limiter, что и geocode(), — политика Nominatim (не больше 1
     * запроса в секунду) действует и здесь.
     *
     * @return array<int, array{display_name: string, lat: float, lon: float}>
     */
    public function suggest(string $query, int $limit = 5): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $this->respectRateLimit();

        $url = self::ENDPOINT . '?' . http_build_query([
            'format' => 'jsonv2',
            'q' => $query,
            'limit' => max(1, min($limit, 10)),
            'addressdetails' => 0,
        ]);

        $body = SafeHttpClient::get($url, $this->timeoutSeconds, [
            "User-Agent: {$this->userAgent}",
            'Accept: application/json',
        ]);

        if ($body === null) {
            return [];
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return [];
        }

        $suggestions = [];
        foreach ($data as $row) {
            if (!isset($row['display_name'], $row['lat'], $row['lon'])) {
                continue;
            }

            $suggestions[] = [
                'display_name' => $row['display_name'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $suggestions;
    }

    /** @return array{lat: float, lon: float}|null */
    private function fetchFromApi(string $place): ?array
    {
        $this->respectRateLimit();

        $url = self::ENDPOINT . '?' . http_build_query([
            'format' => 'json',
            'q' => $place,
            'limit' => 1,
        ]);

        $body = SafeHttpClient::get($url, $this->timeoutSeconds, [
            "User-Agent: {$this->userAgent}",
            'Accept: application/json',
        ]);

        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);

        if (empty($data) || !isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }

        return ['lat' => (float) $data[0]['lat'], 'lon' => (float) $data[0]['lon']];
    }

    private function respectRateLimit(): void
    {
        $elapsed = microtime(true) - $this->lastRequestAt;
        $wait = self::MIN_INTERVAL_SECONDS - $elapsed;

        if ($this->lastRequestAt > 0 && $wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }
}
