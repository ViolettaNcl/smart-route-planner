<?php

namespace App\Support;

/**
 * Пишет строки в var/app.log — без внешних зависимостей (Monolog и т.п.),
 * специально под минимальную задачу проекта: зафиксировать, когда и как
 * часто срабатывают fallback-переключения (OSRM недоступен → Haversine,
 * LLM недоступна → rule-based текст и т.п.), чтобы это было видно не только
 * по honest-меткам в ответе API, но и постфактум в логах сервера.
 *
 * Логирование намеренно best-effort: если запись в файл не удалась (var/
 * не смонтирована на запись, диск переполнен), это не должно уронить
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
        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $level, $message);

        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
