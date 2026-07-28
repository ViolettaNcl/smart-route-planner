<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\Geocoding\NominatimGeocoder;
use App\Http\RateLimitGuard;

/**
 * Автоподсказки городов при вводе (используется фронтендом для textarea
 * с точками маршрута). Проксирует запрос к Nominatim, чтобы:
 * - не обращаться к Nominatim прямо из браузера пользователя: там обязателен
 *   свой User-Agent по их политике использования, а из браузера его не
 *   выставить;
 * - соблюсти общий rate-limit (см. NominatimGeocoder::respectRateLimit) —
 *   один и тот же лимитер, что и у обычного геокодирования маршрута.
 *
 * Плюс отдельный, "внешний" rate limit на сам эндпоинт (см.
 * App\Http\RateLimiter) — пользователь печатает быстро, поэтому лимит
 * щедрый (30 запросов/минуту с одного IP), но не бесконечный: это защита
 * бесплатного Nominatim от случайного шквала запросов, а не от честного
 * набора текста.
 *
 * GET /api/suggest.php?q=Волгог
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте GET.']);
    exit;
}

RateLimitGuard::enforce('suggest', capacity: 30, refillSeconds: 60);

$query = $_GET['q'] ?? '';

if (!is_string($query) || mb_strlen(trim($query)) < 2) {
    echo json_encode(['ok' => true, 'suggestions' => []]);
    exit;
}

try {
    $geocoder = new NominatimGeocoder();
    $suggestions = $geocoder->suggest($query, 5);

    echo json_encode(['ok' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // Автоподсказки — необязательная функция UX: при сбое просто отдаём
    // пустой список, чтобы не ломать основную форму ввода городов.
    error_log('[smart-route-planner] suggest.php: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'suggestions' => []]);
}
