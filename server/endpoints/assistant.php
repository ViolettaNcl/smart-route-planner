<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use App\AI\TripAssistantService;

/**
 * AI-описание поездки: короткий человеческий комментарий к посчитанному
 * маршруту (см. App\AI\TripAssistantService — Vercel AI Gateway или прямой
 * LLM-провайдер, иначе честный офлайн fallback по правилам).
 *
 * POST /api/assistant.php
 * body: route   = JSON результата api/route.php (весь объект целиком)
 *       weather = JSON результата api/weather.php -> поле forecast (необязательно)
 *
 * Отдельный эндпоинт: фронтенд сначала показывает посчитанный маршрут
 * (быстро), затем отдельным вызовом подтягивает AI-комментарий — так
 * основной сценарий не ждёт медленный LLM-запрос.
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается, используйте POST.']);
    exit;
}

$routeRaw = $_POST['route'] ?? '';
$route = is_string($routeRaw) ? json_decode($routeRaw, true) : null;

if (!is_array($route) || empty($route['points'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ожидается JSON результата api/route.php в поле route.']);
    exit;
}

$weatherRaw = $_POST['weather'] ?? '';
$weather = is_string($weatherRaw) ? json_decode($weatherRaw, true) : null;
$weather = is_array($weather) ? $weather : [];

try {
    $assistant = new TripAssistantService();
    $narrative = $assistant->generateNarrative($route, $weather);

    echo json_encode([
        'ok' => true,
        'narrative' => $narrative,
        'llm_configured' => $assistant->isLlmConfigured(),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось сгенерировать AI-комментарий.', 'error_code' => 'INTERNAL_ERROR']);
    error_log('[smart-route-planner] assistant.php: ' . $e->getMessage());
}
