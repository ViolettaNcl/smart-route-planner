<?php

namespace App\AI;

use App\Support\Logger;

/**
 * AI-ассистент поездки: превращает посчитанный маршрут (точки, дистанция,
 * время, предсказанный транспорт) + погоду по точкам в человеческое
 * текстовое описание поездки — что-то вроде "через Воронеж стоит заехать
 * пообедать, дальше долгий перегон — предлагаю ночёвку в Ростове" плюс
 * предупреждения о погоде.
 *
 * ## Два режима работы — честно, не спрятано в коде
 *
 * 1. **LLM-режим**: если задан ключ API (переменная окружения
 *    ANTHROPIC_API_KEY или OPENAI_API_KEY — см. .env.example / setup_guide),
 *    сервис реально обращается к LLM (Anthropic Messages API или
 *    OpenAI Chat Completions) и просит модель написать короткий, дружелюбный
 *    комментарий к маршруту на русском языке.
 * 2. **Офлайн fallback**: если ключа нет (например, сразу после git clone,
 *    без какой-либо настройки), сервис генерирует текст по понятным
 *    правилам — без обращения к сети. Функциональность работает "из коробки"
 *    для демонстрации, но это не настоящий LLM-вывод, а шаблон: это явно
 *    помечено полем `source` в ответе ('llm' | 'fallback'), и то же самое
 *    видно пользователю в интерфейсе.
 *
 * Так же, как Dataset честно предупреждает, что обучающие данные —
 * синтетические (см. docs/neural_net.md), этот класс честно помечает,
 * когда текст сгенерирован реальной моделью, а когда — шаблоном.
 */
class TripAssistantService
{
    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private ?string $anthropicKey;
    private ?string $openaiKey;
    private string $anthropicModel;
    private string $openaiModel;
    private Logger $logger;

    public function __construct(
        ?string $anthropicKey = null,
        ?string $openaiKey = null,
        string $anthropicModel = 'claude-haiku-4-5-20251001',
        string $openaiModel = 'gpt-4o-mini',
        private int $timeoutSeconds = 12,
        ?Logger $logger = null,
    ) {
        // Ключи можно передать явно (удобно для тестов) — если не переданы,
        // читаем из окружения. Так секреты не попадают в код и в git.
        $this->anthropicKey = $anthropicKey ?? (getenv('ANTHROPIC_API_KEY') ?: null);
        $this->openaiKey = $openaiKey ?? (getenv('OPENAI_API_KEY') ?: null);
        $this->anthropicModel = getenv('AI_MODEL_ANTHROPIC') ?: $anthropicModel;
        $this->openaiModel = getenv('AI_MODEL_OPENAI') ?: $openaiModel;
        $this->logger = $logger ?? new Logger(__DIR__ . '/../../var/app.log');
    }

    /**
     * @param array<string, mixed> $route Результат RoutePlanner::plan() (ok=true)
     * @param array<int, array<string, mixed>|null> $weatherPoints Результат OpenMeteoClient::forecastForPoints(),
     *                                          в том же порядке, что и $route['points']
     * @return array{text: string, source: 'llm'|'fallback', provider: ?string}
     */
    public function generateNarrative(array $route, array $weatherPoints = []): array
    {
        if ($this->anthropicKey !== null) {
            $text = $this->callAnthropic($route, $weatherPoints);
            if ($text !== null) {
                return ['text' => $text, 'source' => 'llm', 'provider' => 'anthropic'];
            }
            $this->logger->warning('Anthropic API call failed, trying next provider');
        }

        if ($this->openaiKey !== null) {
            $text = $this->callOpenAi($route, $weatherPoints);
            if ($text !== null) {
                return ['text' => $text, 'source' => 'llm', 'provider' => 'openai'];
            }
            $this->logger->warning('OpenAI API call failed, falling back to rule-based text');
        }

        // Ни один LLM недоступен (нет ключа или сбой сети) — офлайн fallback.
        if ($this->anthropicKey === null && $this->openaiKey === null) {
            $this->logger->info('No LLM API key configured, using rule-based fallback text');
        }

        return [
            'text' => $this->fallbackNarrative($route, $weatherPoints),
            'source' => 'fallback',
            'provider' => null,
        ];
    }

    public function isLlmConfigured(): bool
    {
        return $this->anthropicKey !== null || $this->openaiKey !== null;
    }

    // -----------------------------------------------------------------
    // LLM-режим
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>|null> $weatherPoints
     */
    private function callAnthropic(array $route, array $weatherPoints): ?string
    {
        $prompt = $this->buildPrompt($route, $weatherPoints);

        $payload = json_encode([
            'model' => $this->anthropicModel,
            'max_tokens' => 400,
            'system' => $this->systemPrompt(),
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $body = $this->post(self::ANTHROPIC_ENDPOINT, $payload, [
            'x-api-key: ' . $this->anthropicKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ]);

        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        $blocks = $data['content'] ?? null;

        if (!is_array($blocks)) {
            return null;
        }

        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        return $text !== '' ? trim($text) : null;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>|null> $weatherPoints
     */
    private function callOpenAi(array $route, array $weatherPoints): ?string
    {
        $prompt = $this->buildPrompt($route, $weatherPoints);

        $payload = json_encode([
            'model' => $this->openaiModel,
            'max_tokens' => 400,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $body = $this->post(self::OPENAI_ENDPOINT, $payload, [
            'Authorization: Bearer ' . $this->openaiKey,
            'content-type: application/json',
        ]);

        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        $text = $data['choices'][0]['message']['content'] ?? null;

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    private function systemPrompt(): string
    {
        return 'Ты — дружелюбный ассистент планировщика автомобильных и пеших маршрутов. '
            . 'Тебе дают посчитанный маршрут (города по порядку, дистанцию, время в пути, '
            . 'предсказанный вид транспорта) и погоду по точкам. Напиши короткий (3-5 предложений), '
            . 'человеческий комментарий на русском языке: где стоит сделать привал/пообедать, '
            . 'нужна ли ночёвка на длинном маршруте, и предупреди о погоде, если она заслуживает '
            . 'внимания (дождь, жара, мороз, гроза). Пиши по существу, без вступлений вроде '
            . '"Конечно, вот...", сразу к сути. Не придумывай названия кафе/отелей — только общие советы.';
    }

    /**
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>|null> $weatherPoints
     */
    private function buildPrompt(array $route, array $weatherPoints): string
    {
        $points = $route['points'] ?? [];
        $lines = [];
        $lines[] = 'Маршрут (' . count($points) . ' точек): ' . implode(' → ', $points);
        $lines[] = 'Суммарная дистанция: ' . ($route['distance_km'] ?? '?') . ' км';
        $lines[] = 'Оценка времени в пути: ' . ($route['duration']['label'] ?? '?');
        $lines[] = 'Предсказанный транспорт: ' . ($route['transport']['mode_ru'] ?? '?')
            . ' (уверенность модели ' . ($route['transport']['confidence'] ?? '?') . '%)';

        if (!empty($weatherPoints)) {
            $lines[] = 'Погода по точкам:';
            foreach ($weatherPoints as $w) {
                if ($w === null) {
                    continue;
                }
                $label = $w['label'] ?? '?';
                $temp = $w['temperature_c'] !== null ? round($w['temperature_c']) . '°C' : 'н/д';
                $desc = $w['description_ru'] ?? 'н/д';
                $warn = $w['warning'] ? ' [ВНИМАНИЕ: ' . $w['warning_reason'] . ']' : '';
                $lines[] = "  - {$label}: {$temp}, {$desc}{$warn}";
            }
        }

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------
    // Офлайн fallback (без LLM)
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $route
     * @param array<int, array<string, mixed>|null> $weatherPoints
     */
    private function fallbackNarrative(array $route, array $weatherPoints): string
    {
        $points = $route['points'] ?? [];
        $distance = (float) ($route['distance_km'] ?? 0);
        $durationLabel = $route['duration']['label'] ?? null;
        $mode = $route['transport']['mode'] ?? 'car';

        $sentences = [];

        // --- привал / обед в середине маршрута ---
        if (count($points) >= 3) {
            $midIndex = (int) floor(count($points) / 2);
            $midPoint = $points[$midIndex];
            $sentences[] = "Маршрут проходит через {$midPoint} примерно на середине пути — "
                . 'логичное место для остановки на перекус и короткий отдых.';
        }

        // --- ночёвка на длинных маршрутах ---
        if ($distance > 500 && $mode !== 'walk') {
            $overnightCandidate = count($points) >= 3 ? $points[(int) floor(count($points) * 0.6)] : null;
            $sentences[] = $overnightCandidate
                ? 'Дистанция большая (' . round($distance) . ' км), за один день без остановок будет тяжело — '
                    . "стоит запланировать ночёвку в районе {$overnightCandidate}."
                : 'Дистанция большая (' . round($distance) . ' км) — рассмотрите ночёвку по пути, '
                    . 'а не один длинный перегон.';
        } elseif ($distance > 250) {
            $sentences[] = 'Маршрут неблизкий (' . round($distance) . ' км) — стоит сделать пару остановок '
                . 'размять ноги, особенно если едете за рулём.';
        }

        // --- время в пути ---
        if ($durationLabel !== null) {
            $sentences[] = "Ориентировочное время в пути: {$durationLabel}.";
        }

        // --- погода ---
        $warnings = array_values(array_filter($weatherPoints, fn ($w) => $w !== null && $w['warning']));
        if (!empty($warnings)) {
            $parts = array_map(
                fn ($w) => ($w['label'] ?? '?') . ' — ' . $w['warning_reason'],
                $warnings
            );
            $sentences[] = '⚠️ Погода на маршруте требует внимания: ' . implode('; ', $parts) . '.';
        } elseif (!empty($weatherPoints)) {
            $sentences[] = 'Погода по маршруту без сюрпризов — заметных предупреждений нет.';
        }

        if (empty($sentences)) {
            $sentences[] = 'Короткий маршрут без особых нюансов — можно отправляться.';
        }

        return implode(' ', $sentences);
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /**
     * @param array<int, string> $headers
     */
    private function post(string $url, string $payload, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $error !== '' || $status !== 200) {
            if ($status !== 200) {
                $this->logger->error('LLM API returned a non-200 status', [
                    'status' => $status,
                    'body_excerpt' => substr((string) $body, 0, 300),
                ]);
            } elseif ($error !== '') {
                $this->logger->error('LLM API request failed', ['curl_error' => $error]);
            }
            return null;
        }

        return $body;
    }
}
