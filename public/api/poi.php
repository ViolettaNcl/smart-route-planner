<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Geodata\OverpassPoiFinder;
use App\Http\RateLimitGuard;

/**
 * Точки интереса (АЗС/кафе/рестораны/отели) рядом с точками маршрута
 * через Overpass API (без ключа).
 *
 * POST /api/poi.php
 * body: points = JSON-массив [{ "lat": 48.7, "lon": 44.5, "label": "Волгоград" }, ...]
 *       radius  = радиус поиска в метрах (необязательно, по умолчанию 3000)
 *
 * Отдельный эндпоинт, вызывается фронтендом по кнопке "Показать точки
 * интереса" уже после расчёта маршрута — не блокирует основной сценарий и
 * не расходует лимиты бесплатного Overpass API, если пользователь не просил.
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

RateLimitGuard::enforce('poi', capacity: 15, refillSeconds: 60);

$pointsRaw = $_POST['points'] ?? '';
$points = is_string($pointsRaw) ? json_decode($pointsRaw, true) : null;

if (!is_array($points) || count($points) === 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ожидается непустой JSON-массив точек в поле points.']);
    exit;
}

$radius = isset($_POST['radius']) && is_numeric($_POST['radius'])
    ? max(500, min((int) $_POST['radius'], 10000))
    : 3000;

// Ограничиваем число точек, для которых реально ходим в Overpass: у большого
// маршрута с 15 городами это было бы 15 последовательных запросов к
// бесплатному публичному серверу — слишком много. Берём не больше 6:
// старт, финиш и до 4 промежуточных точек, равномерно распределённых.
$points = limitPoints($points, 6);

try {
    $finder = new OverpassPoiFinder();
    $byPoint = [];

    foreach ($points as $point) {
        if (!isset($point['lat'], $point['lon'])) {
            continue;
        }

        $poi = $finder->findNear((float) $point['lat'], (float) $point['lon'], $radius);

        $byPoint[] = [
            'label' => $point['label'] ?? null,
            'lat' => (float) $point['lat'],
            'lon' => (float) $point['lon'],
            'places' => $poi,
        ];
    }

    echo json_encode(['ok' => true, 'points' => $byPoint], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось получить точки интереса.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] poi.php: ' . $e->getMessage());
}

/**
 * @param array<int, array<string, mixed>> $points
 * @return array<int, array<string, mixed>>
 */
function limitPoints(array $points, int $max): array
{
    $count = count($points);
    if ($count <= $max) {
        return $points;
    }

    // Равномерная подвыборка индексов от 0 до count-1 включительно
    // (гарантированно включает первую и последнюю точку маршрута).
    $selected = [];
    for ($i = 0; $i < $max; $i++) {
        $index = (int) round($i * ($count - 1) / ($max - 1));
        $selected[$index] = $points[$index];
    }

    return array_values($selected);
}
