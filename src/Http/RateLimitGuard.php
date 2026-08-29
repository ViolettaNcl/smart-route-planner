<?php

namespace App\Http;

use App\Support\RuntimeStorage;

/**
 * Тонкая обвязка над RateLimiter для использования прямо в начале
 * api/*.php файлов: одна строчка вместо повторения одного и того же
 * boilerplate (открыть лимитер, проверить, красиво ответить 429) в каждом
 * эндпоинте.
 *
 * Каждый защищённый эндпоинт получает свой файл состояния в
 * runtime/ratelimit/{name}.json — так лимиты разных эндпоинтов не мешают друг
 * другу (частые подсказки городов не должны блокировать погоду, и наоборот).
 */
class RateLimitGuard
{
    /**
     * Если лимит превышен — сразу отправляет 429 и завершает скрипт
     * (exit), как и остальные ранние возвраты в api/*.php (405/422).
     * Если лимит не превышен — просто возвращает управление вызывающему
     * коду, эндпоинт продолжает работу как обычно.
     */
    public static function enforce(string $bucketName, int $capacity, int $refillSeconds): void
    {
        $storagePath = RuntimeStorage::path('ratelimit/' . $bucketName . '.json');
        $limiter = new RateLimiter($storagePath, $capacity, $refillSeconds);

        $result = $limiter->attempt(ClientIdentity::ip());

        if ($result['allowed']) {
            return;
        }

        http_response_code(429);
        header('Retry-After: ' . $result['retry_after_seconds']);
        echo json_encode([
            'ok' => false,
            'error' => 'Слишком много запросов, подождите немного и попробуйте снова.',
            'error_code' => 'RATE_LIMITED',
            'retry_after_seconds' => $result['retry_after_seconds'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
