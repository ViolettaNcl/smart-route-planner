<?php

namespace Tests;

use App\Geocoding\FileCache;
use App\Routing\OsrmRoadRouter;

class OsrmRoadRouterTest
{
    public function run(TestReporter $t): void
    {
        $cacheDir = sys_get_temp_dir() . '/srp-osrm-cache-' . bin2hex(random_bytes(4));
        $cache = new FileCache($cacheDir);
        $calls = [];
        $fixture = json_encode([
            'code' => 'Ok',
            'routes' => [[
                'distance' => 635000,
                'duration' => 27000,
                'weight' => 27000,
                'geometry' => [
                    'coordinates' => [[37.6173, 55.7558], [30.3351, 59.9343]],
                ],
                'legs' => [[
                    'distance' => 635000,
                    'duration' => 27000,
                    'summary' => 'M-11',
                    'steps' => [],
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $fetcher = static function (string $url, int $timeout, array $headers) use (&$calls, $fixture): ?string {
            $calls[] = ['url' => $url, 'timeout' => $timeout, 'headers' => $headers];

            return str_contains($url, 'router.project-osrm.org') ? null : $fixture;
        };

        $coordinates = [
            ['lat' => 55.7558, 'lon' => 37.6173],
            ['lat' => 59.9343, 'lon' => 30.3351],
        ];
        $endpoints = [
            'https://router.project-osrm.org/route/v1/driving',
            'https://routing.openstreetmap.de/routed-car/route/v1/driving',
        ];
        $router = new OsrmRoadRouter(
            timeoutSeconds: 3,
            alternatives: 1,
            cache: $cache,
            endpoints: $endpoints,
            httpGet: $fetcher,
        );

        $route = $router->route($coordinates);
        $t->assertTrue('При отказе первого OSRM используется резервный endpoint', is_array($route));
        $t->assertEquals('Провайдер резервного маршрута прозрачно указан', 'osrm_fossgis_public', $route['provider'] ?? null);
        $t->assertTrue('Ответ отмечает использование failover', $route['failover_used'] ?? false);
        $t->assertEquals('До успешного ответа выполнены две попытки', 2, $route['upstream_attempts'] ?? null);
        $t->assertEquals('Первый ответ пришёл из сети', 'live', $route['cache_status'] ?? null);

        $cachedRoute = $router->route($coordinates);
        $t->assertEquals('Повторный маршрут не обращается к публичным сервисам', 2, count($calls));
        $t->assertTrue('Повторный ответ отмечен как кэшированный', $cachedRoute['cached'] ?? false);
        $t->assertEquals('Свежий кэш имеет отдельный статус', 'fresh', $cachedRoute['cache_status'] ?? null);

        $cacheFiles = glob($cacheDir . '/*.json') ?: [];
        if (isset($cacheFiles[0])) {
            $entry = json_decode((string) file_get_contents($cacheFiles[0]), true);
            if (is_array($entry)) {
                $entry['cached_at'] = time() - 3600;
                file_put_contents($cacheFiles[0], json_encode($entry));
            }
        }

        $offlineRouter = new OsrmRoadRouter(
            timeoutSeconds: 1,
            alternatives: 1,
            cache: $cache,
            endpoints: $endpoints,
            httpGet: static fn (string $url, int $timeout, array $headers): ?string => null,
            cacheTtlSeconds: 60,
            staleTtlSeconds: 7200,
        );
        $staleRoute = $offlineRouter->route($coordinates);
        $t->assertTrue('При полном отказе upstream используется недавний дорожный кэш', is_array($staleRoute));
        $t->assertEquals('Резервный кэш явно помечен как stale', 'stale', $staleRoute['cache_status'] ?? null);

        foreach ($cacheFiles as $file) {
            @unlink($file);
        }
        @rmdir($cacheDir);
    }
}
