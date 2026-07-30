<?php

namespace Tests;

/**
 * Минималистичная замена PHPUnit-ассертов: проект намеренно не тянет
 * PHPUnit через Composer, чтобы для запуска тестов не требовалось
 * подключение к packagist.org — достаточно локального PHP.
 * Для более серьёзного проекта это первое, что стоит заменить на PHPUnit.
 */
class TestReporter
{
    private int $passed = 0;
    private int $failed = 0;

    public function assertEquals(string $description, mixed $expected, mixed $actual): void
    {
        $this->report($description, $expected === $actual, 'ожидалось: ' . var_export($expected, true) . ', получено: ' . var_export($actual, true));
    }

    public function assertApprox(string $description, float $expected, float $actual, float $tolerance): void
    {
        $ok = abs($expected - $actual) <= $tolerance;
        $this->report($description, $ok, "ожидалось ~{$expected} (±{$tolerance}), получено {$actual}");
    }

    public function assertTrue(string $description, bool $condition): void
    {
        $this->report($description, $condition, 'условие не выполнено');
    }

    private function report(string $description, bool $ok, string $detail): void
    {
        if ($ok) {
            $this->passed++;
            echo "  ✅ {$description}\n";
        } else {
            $this->failed++;
            echo "  ❌ {$description} — {$detail}\n";
        }
    }

    public function summary(): array
    {
        return ['passed' => $this->passed, 'failed' => $this->failed];
    }
}
