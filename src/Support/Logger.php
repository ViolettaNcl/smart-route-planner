<?php

namespace App\Support;

/**
 * Пишет строки в runtime app.log — без внешних зависимостей (Monolog и т.п.),
 * специально под минимальную задачу проекта: зафиксировать, когда и как
 * часто срабатывают fallback-переключения (OSRM недоступен → Haversine,
 * LLM недоступна → rule-based текст и т.п.), чтобы это было видно не только
 * по honest-меткам в ответе API, но и постфактум в логах сервера.
 *
 * Логирование намеренно best-effort: если запись в файл не удалась (runtime
 * storage не смонтирован на запись, диск переполнен), это не должно уронить
 * основной сценарий — тот же принцип fail-open, что и у остального проекта.
 */
class Logger
{
    public function __construct(private string $path)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $event = [
            'timestamp' => gmdate('c'),
            'level' => strtolower($level),
            'service' => 'smart-route-planner',
            'event' => $message,
            'context' => $context,
        ];
        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Vercel and most container platforms collect stderr. Keeping the
        // same structured event there makes failures searchable without a
        // writable or persistent filesystem. Context deliberately contains
        // counts and provider names, never user-entered addresses.
        error_log($line);
    }
}
