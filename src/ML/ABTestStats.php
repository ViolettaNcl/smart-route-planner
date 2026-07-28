<?php

namespace App\ML;

/**
 * Простейшее хранилище статистики A/B-теста "MLP vs Softmax": каждый
 * посетитель случайно (50/50) получает одну из двух моделей на весь свой
 * визит (variant хранится в localStorage на фронтенде), а после расчёта
 * маршрута может отметить, угадала ли модель ("👍/👎"). Здесь просто
 * копится счётчик verно/неверно по каждому варианту в JSON-файле.
 *
 * Не претендует на промышленный event-pipeline (Kafka/ClickHouse и т.п.) —
 * это специально простое файловое хранилище с flock для наглядной
 * демонстрации идеи A/B-тестирования моделей в проде на уровне пет-проекта.
 */
class ABTestStats
{
    public function __construct(private string $filePath)
    {
    }

    public function record(string $variant, bool $isCorrect): void
    {
        $variant = $variant === 'softmax' ? 'softmax' : 'mlp';

        $fp = fopen($this->filePath, 'c+');
        if ($fp === false) {
            return;
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $stats = json_decode((string) $raw, true) ?: $this->emptyStats();

        $stats[$variant][$isCorrect ? 'correct' : 'incorrect']++;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stats, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * @return array<string, array{correct: int, incorrect: int, accuracy: ?float}>
     */
    public function getStats(): array
    {
        if (!is_file($this->filePath)) {
            $stats = $this->emptyStats();
        } else {
            $stats = json_decode((string) file_get_contents($this->filePath), true) ?: $this->emptyStats();
        }

        foreach ($stats as $variant => $counts) {
            $total = $counts['correct'] + $counts['incorrect'];
            $stats[$variant]['accuracy'] = $total > 0 ? round($counts['correct'] / $total * 100, 1) : null;
            $stats[$variant]['total'] = $total;
        }

        return $stats;
    }

    private function emptyStats(): array
    {
        return [
            'mlp' => ['correct' => 0, 'incorrect' => 0],
            'softmax' => ['correct' => 0, 'incorrect' => 0],
        ];
    }
}
