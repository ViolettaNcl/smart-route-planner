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

    public function record(string $variant, bool $isCorrect, ?string $eventId = null): bool
    {
        $variant = $variant === 'softmax' ? 'softmax' : 'mlp';

        $fp = fopen($this->filePath, 'c+');
        if ($fp === false) {
            return false;
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $stats = $this->decodeState((string) $raw);
        $eventHash = $eventId !== null && trim($eventId) !== '' ? hash('sha256', trim($eventId)) : null;
        if ($eventHash !== null && in_array($eventHash, $stats['_seen'], true)) {
            flock($fp, LOCK_UN);
            fclose($fp);

            return false;
        }

        $stats[$variant][$isCorrect ? 'correct' : 'incorrect']++;
        if ($eventHash !== null) {
            $stats['_seen'][] = $eventHash;
            $stats['_seen'] = array_slice($stats['_seen'], -2000);
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stats, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    /**
     * @return array{
     *     mlp: array{correct: int, incorrect: int, accuracy: ?float, total: int, confidence_interval: array{low: ?float, high: ?float, level: float}, result_ready: bool},
     *     softmax: array{correct: int, incorrect: int, accuracy: ?float, total: int, confidence_interval: array{low: ?float, high: ?float, level: float}, result_ready: bool}
     * }
     */
    public function getStats(): array
    {
        if (!is_file($this->filePath)) {
            $stats = $this->emptyStats();
        } else {
            $stats = $this->decodeState((string) file_get_contents($this->filePath));
        }

        return [
            'mlp' => $this->formatStatsRow($stats['mlp']),
            'softmax' => $this->formatStatsRow($stats['softmax']),
        ];
    }

    /**
     * @param array{correct: int, incorrect: int} $counts
     * @return array{correct: int, incorrect: int, accuracy: ?float, total: int, confidence_interval: array{low: ?float, high: ?float, level: float}, result_ready: bool}
     */
    private function formatStatsRow(array $counts): array
    {
        $total = $counts['correct'] + $counts['incorrect'];
        [$low, $high] = $this->wilsonInterval($counts['correct'], $total);

        return [
            'correct' => $counts['correct'],
            'incorrect' => $counts['incorrect'],
            'accuracy' => $total > 0 ? round($counts['correct'] / $total * 100, 1) : null,
            'total' => $total,
            'confidence_interval' => [
                'low' => $low,
                'high' => $high,
                'level' => 0.95,
            ],
            'result_ready' => $total >= 30,
        ];
    }

    /** @return array{mlp: array{correct: int, incorrect: int}, softmax: array{correct: int, incorrect: int}, _seen: string[]} */
    private function emptyStats(): array
    {
        return [
            'mlp' => ['correct' => 0, 'incorrect' => 0],
            'softmax' => ['correct' => 0, 'incorrect' => 0],
            '_seen' => [],
        ];
    }

    /** @return array{mlp: array{correct: int, incorrect: int}, softmax: array{correct: int, incorrect: int}, _seen: string[]} */
    private function decodeState(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->emptyStats();
        }

        $state = $this->emptyStats();
        foreach (['mlp', 'softmax'] as $variant) {
            $counts = is_array($decoded[$variant] ?? null) ? $decoded[$variant] : [];
            $state[$variant]['correct'] = max(0, (int) ($counts['correct'] ?? 0));
            $state[$variant]['incorrect'] = max(0, (int) ($counts['incorrect'] ?? 0));
        }
        if (is_array($decoded['_seen'] ?? null)) {
            foreach ($decoded['_seen'] as $seenValue) {
                if (is_string($seenValue) && preg_match('/^[a-f0-9]{64}$/', $seenValue) === 1) {
                    $state['_seen'][] = $seenValue;
                }
            }
        }

        return $state;
    }

    /** @return array{0: ?float, 1: ?float} */
    private function wilsonInterval(int $successes, int $total): array
    {
        if ($total === 0) {
            return [null, null];
        }

        $z = 1.959963984540054;
        $p = $successes / $total;
        $denominator = 1 + ($z ** 2 / $total);
        $centre = ($p + ($z ** 2 / (2 * $total))) / $denominator;
        $margin = ($z / $denominator) * sqrt(($p * (1 - $p) / $total) + ($z ** 2 / (4 * $total ** 2)));

        return [round(max(0.0, $centre - $margin) * 100, 1), round(min(1.0, $centre + $margin) * 100, 1)];
    }
}
