<?php

namespace App\Http;

/**
 * Rate limiting алгоритмом token bucket ("ведро с токенами"), написанным с
 * нуля — без Redis, без внешних библиотек, простое файловое хранилище с
 * flock (та же схема, что уже используется в App\ML\ABTestStats).
 *
 * ## Зачем это вообще нужно в pet-проекте
 *
 * Несколько эндпоинтов (`suggest.php`, `weather.php`, `poi.php`) обращаются
 * к БЕСПЛАТНЫМ публичным API (Nominatim/Open-Meteo/Overpass) без ключа.
 * У всех троих в правилах использования прямо прописаны лимиты частоты
 * запросов — и если сайт получит внезапный всплеск трафика (или кто-то
 * специально накрутит запросы), можно попасть под бан IP-адреса сервера на
 * этих сервисах для ВСЕХ посетителей сайта разом. Ограничение на СВОЕЙ
 * стороне защищает от этого сценария.
 *
 * Отдельно — `learn.php` и `feedback.php` пишут в общий файл на диске без
 * привязки к пользователю (см. их докблоки) — без rate limit это ещё и
 * дешёвый вектор "испортить" демо-статистику простым спамом запросов.
 *
 * ## Алгоритм: token bucket
 *
 * У каждого клиента (идентифицируется по IP, см. ClientIdentity) есть
 * воображаемое "ведро" ёмкостью $capacity токенов. Каждый запрос тратит
 * 1 токен. Токены пополняются со скоростью $capacity/$refillSeconds токенов
 * в секунду, но не выше ёмкости ведра. Если токенов нет — запрос отклоняется.
 *
 * Почему token bucket, а не наивный "счётчик запросов за фиксированное окно"
 * (fixed window counter): у fixed window есть классический краевой эффект —
 * пользователь может сделать целый лимит запросов в последнюю секунду одного
 * окна и сразу же целый лимит в первую секунду следующего, получив фактически
 * 2x лимита за короткий промежуток на границе окон. Token bucket пополняется
 * непрерывно (плавно), а не скачком по границе окна, поэтому не имеет этой
 * уязвимости и даёт более равномерное ограничение скорости.
 */
class RateLimiter
{
    public function __construct(
        private string $storagePath,
        private int $capacity,
        private int $refillSeconds,
    ) {
    }

    /**
     * @return array{allowed: bool, remaining: float, retry_after_seconds: int}
     */
    public function attempt(string $clientKey): array
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $fp = @fopen($this->storagePath, 'c+');
        if ($fp === false) {
            // Не удалось открыть файл состояния (например, нет прав на диск) —
            // безопаснее пропустить запрос, чем уронить весь эндпоинт из-за
            // проблемы с необязательной защитной механикой.
            return ['allowed' => true, 'remaining' => (float) $this->capacity, 'retry_after_seconds' => 0];
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $state = json_decode((string) $raw, true) ?: [];

        $now = microtime(true);
        $bucket = $state[$clientKey] ?? ['tokens' => (float) $this->capacity, 'updated_at' => $now];

        // Пополняем токены пропорционально прошедшему времени с последнего
        // запроса этого клиента — непрерывное пополнение, а не скачок по
        // границе фиксированного окна (см. докблок класса).
        $elapsed = max(0.0, $now - $bucket['updated_at']);
        $refillRate = $this->capacity / $this->refillSeconds; // токенов в секунду
        $tokens = min($this->capacity, $bucket['tokens'] + $elapsed * $refillRate);

        $allowed = $tokens >= 1.0;
        if ($allowed) {
            $tokens -= 1.0;
        }

        $state[$clientKey] = ['tokens' => $tokens, 'updated_at' => $now];

        // Мелкая уборка: не даём файлу расти бесконечно, забывая клиентов,
        // которые давно не появлялись (их ведро всё равно уже полное).
        $state = $this->pruneStaleEntries($state, $now);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $retryAfter = $allowed ? 0 : (int) ceil((1.0 - $tokens) / $refillRate);

        return ['allowed' => $allowed, 'remaining' => round($tokens, 2), 'retry_after_seconds' => max(1, $retryAfter)];
    }

    /**
     * @param array<string, array{tokens: float, updated_at: float}> $state
     * @return array<string, array{tokens: float, updated_at: float}>
     */
    private function pruneStaleEntries(array $state, float $now): array
    {
        // Клиент "остывает" после refillSeconds * 4 без запросов — к этому
        // моменту его ведро гарантированно снова полное, хранить запись дальше
        // незачем (иначе файл рос бы вечно на каждый уникальный IP).
        $staleAfter = $this->refillSeconds * 4;

        return array_filter($state, fn ($entry) => ($now - $entry['updated_at']) < $staleAfter);
    }
}
