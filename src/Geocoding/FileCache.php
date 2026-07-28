<?php

namespace App\Geocoding;

/**
 * Простой файловый кэш результатов геокодирования.
 *
 * Nominatim просит не долбить API повторными запросами для одних и тех же
 * значений и соблюдать лимит ~1 запрос/сек. Кэш решает обе проблемы: одинаковый
 * город внутри проекта геокодируется только один раз, а повторные расчёты
 * маршрута с теми же городами не делают новых сетевых запросов вообще.
 */
class FileCache
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function set(string $key, array $value): void
    {
        file_put_contents($this->pathFor($key), json_encode($value));
    }

    private function pathFor(string $key): string
    {
        return $this->dir . '/' . sha1(mb_strtolower(trim($key))) . '.json';
    }
}
