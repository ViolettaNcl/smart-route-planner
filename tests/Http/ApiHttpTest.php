<?php

namespace Tests\Http;

use Tests\TestReporter;

/**
 * HTTP-интеграционные тесты: бьют по-настоящему через HTTP в реальные
 * api/*.php файлы (через встроенный `php -S`, см. HttpTestServer), а не
 * вызывают классы напрямую. Проверяют то, что unit-тесты в принципе не
 * видят: разбор $_POST/$_GET, HTTP-коды ответа, error_code в JSON, и
 * поведение rate limiter'а на реальном запросе.
 *
 * Специально не трогаем сценарии, которым нужен реальный интернет
 * (геокодирование городов через Nominatim, дорожный маршрут через OSRM,
 * погода через Open-Meteo) — они недоступны в изолированных CI-раннерах
 * без сетевого доступа к внешним доменам, и тест на них был бы нестабильным
 * (flaky) не по вине кода. Вместо этого проверяем:
 *   - обработку ошибок (метод не тот, невалидные данные) — не требует сети;
 *   - day_plan.php — работает от координат, присланных клиентом, вообще без
 *     геокодирования;
 *   - decision_boundary.php / explain.php / ab_stats.php — работают с уже
 *     обученными локальными весами модели, без внешних вызовов;
 *   - assistant.php — без настроенных Gateway/Anthropic/OpenAI-токенов
 *     работает в honest-fallback режиме (см. TripAssistantService), тоже
 *     без сети;
 *   - rate limiter — 'suggest' с query короче 2 символов отвечает мгновенно,
 *     не дожидаясь Nominatim, что даёт возможность быстро протестировать
 *     реальный HTTP 429 без сети.
 */
class ApiHttpTest
{
    public function __construct(private HttpTestServer $server)
    {
    }

    public function run(TestReporter $t): void
    {
        $this->testMethodNotAllowed($t);
        $this->testRouteValidation($t);
        $this->testDayPlan($t);
        $this->testDecisionBoundary($t);
        $this->testExplain($t);
        $this->testAbStatsAndFeedback($t);
        $this->testAssistantFallback($t);
        $this->testRateLimiting($t);
    }

    private function testMethodNotAllowed(TestReporter $t): void
    {
        $res = $this->server->get('/api/route.php');
        $t->assertEquals('GET /api/route.php -> 405', 405, $res['status']);
        $t->assertEquals('GET /api/route.php -> error_code METHOD_NOT_ALLOWED', 'METHOD_NOT_ALLOWED', $res['body']['error_code'] ?? null);
    }

    private function testRouteValidation(TestReporter $t): void
    {
        $res = $this->server->post('/api/route.php', ['points' => '']);
        $t->assertEquals('POST /api/route.php с пустыми points -> 422', 422, $res['status']);
        $t->assertEquals('POST /api/route.php с пустыми points -> error_code EMPTY_POINTS', 'EMPTY_POINTS', $res['body']['error_code'] ?? null);
    }

    private function testDayPlan(TestReporter $t): void
    {
        // Координаты подряд по трассе М4 "Дон", без геокодирования — day_plan.php
        // работает от уже готовых lat/lon, поэтому не требует сети.
        $points = json_encode([
            ['lat' => 48.708, 'lon' => 44.5133, 'label' => 'Волгоград'],
            ['lat' => 47.2357, 'lon' => 39.7015, 'label' => 'Ростов-на-Дону'],
            ['lat' => 55.7558, 'lon' => 37.6173, 'label' => 'Москва'],
        ]);

        $res = $this->server->post('/api/day_plan.php', ['points' => $points, 'days' => '2']);
        $t->assertEquals('POST /api/day_plan.php -> 200', 200, $res['status']);
        $t->assertTrue('day_plan.php -> ok=true', $res['body']['ok'] ?? false);
        $t->assertEquals('day_plan.php -> algorithm=kmeans_1d', 'kmeans_1d', $res['body']['algorithm'] ?? null);
        $t->assertTrue('day_plan.php -> вернул хотя бы 1 день', count($res['body']['days'] ?? []) >= 1);

        // --- невалидный JSON в points -> 422 ---
        $bad = $this->server->post('/api/day_plan.php', ['points' => 'not-json']);
        $t->assertEquals('day_plan.php с невалидным points -> 422', 422, $bad['status']);
    }

    private function testDecisionBoundary(TestReporter $t): void
    {
        $res = $this->server->get('/api/decision_boundary.php?model=mlp');
        $t->assertEquals('GET /api/decision_boundary.php -> 200', 200, $res['status']);
        $t->assertTrue('decision_boundary.php -> ok=true', $res['body']['ok'] ?? false);
    }

    private function testExplain(TestReporter $t): void
    {
        $res = $this->server->get('/api/explain.php?distance_km=738&stops=4');
        $t->assertEquals('GET /api/explain.php -> 200', 200, $res['status']);
        $t->assertTrue('explain.php -> ok=true', $res['body']['ok'] ?? false);
        $t->assertTrue('explain.php -> есть поле model', isset($res['body']['model']));

        $bad = $this->server->get('/api/explain.php?distance_km=-5&stops=1');
        $t->assertEquals('explain.php с невалидными параметрами -> 422', 422, $bad['status']);
    }

    private function testAbStatsAndFeedback(TestReporter $t): void
    {
        $before = $this->server->get('/api/ab_stats.php');
        $t->assertEquals('GET /api/ab_stats.php -> 200', 200, $before['status']);

        $fb = $this->server->post('/api/feedback.php', ['variant' => 'mlp', 'is_correct' => '1']);
        $t->assertEquals('POST /api/feedback.php -> 200', 200, $fb['status']);
        $t->assertTrue('feedback.php -> ok=true', $fb['body']['ok'] ?? false);

        $badVariant = $this->server->post('/api/feedback.php', ['variant' => 'gpt5', 'is_correct' => '1']);
        $t->assertEquals('feedback.php с неизвестным variant -> 422', 422, $badVariant['status']);
    }

    private function testAssistantFallback(TestReporter $t): void
    {
        $route = json_encode([
            'points' => ['Волгоград', 'Ростов-на-Дону', 'Москва'],
            'distance_km' => 1350,
            'duration' => ['label' => '18 ч'],
            'transport' => ['mode' => 'car', 'mode_ru' => 'авто', 'confidence' => 82],
        ]);

        $res = $this->server->post('/api/assistant.php', ['route' => $route]);
        $t->assertEquals('POST /api/assistant.php -> 200', 200, $res['status']);
        $t->assertTrue('assistant.php -> ok=true', $res['body']['ok'] ?? false);
        $t->assertTrue('assistant.php -> непустой текст', strlen($res['body']['narrative']['text'] ?? '') > 0);
        $t->assertTrue(
            'assistant.php -> source это llm либо fallback',
            in_array($res['body']['narrative']['source'] ?? null, ['llm', 'fallback'], true)
        );
    }

    private function testRateLimiting(TestReporter $t): void
    {
        // 'suggest' с однобуквенным запросом отвечает мгновенно (ok, пустой
        // список подсказок) без обращения к Nominatim — но каждый вызов всё
        // равно тратит токен лимитера (см. порядок проверок в suggest.php).
        // Лимит эндпоинта — 30 запросов/минуту (см. api/suggest.php).
        $lastStatus = 200;
        $sawRateLimited = false;

        for ($i = 0; $i < 35; $i++) {
            $res = $this->server->get('/api/suggest.php?q=a');
            $lastStatus = $res['status'];
            if ($lastStatus === 429) {
                $sawRateLimited = true;
                $t->assertEquals('429 -> error_code RATE_LIMITED', 'RATE_LIMITED', $res['body']['error_code'] ?? null);
                $t->assertTrue('429 -> retry_after_seconds присутствует и положителен', ($res['body']['retry_after_seconds'] ?? 0) > 0);
                break;
            }
        }

        $t->assertTrue('Rate limiter вернул 429 после превышения лимита suggest (30/мин)', $sawRateLimited);
    }
}
