<?php

namespace Tests;

use App\Http\RateLimiter;

class RateLimiterTest
{
    public function run(TestReporter $t): void
    {
        $path = sys_get_temp_dir() . '/srp_ratelimit_test_' . uniqid() . '.json';
        @unlink($path);

        // --- в пределах ёмкости ведра — все запросы разрешены ---
        $limiter = new RateLimiter($path, capacity: 3, refillSeconds: 60);
        $r1 = $limiter->attempt('client-a');
        $r2 = $limiter->attempt('client-a');
        $r3 = $limiter->attempt('client-a');
        $t->assertTrue('1-й запрос из 3 разрешён', $r1['allowed']);
        $t->assertTrue('2-й запрос из 3 разрешён', $r2['allowed']);
        $t->assertTrue('3-й запрос из 3 разрешён', $r3['allowed']);

        // --- 4-й запрос сверх ёмкости — отклонён ---
        $r4 = $limiter->attempt('client-a');
        $t->assertTrue('4-й запрос сверх лимита отклонён', !$r4['allowed']);
        $t->assertTrue('retry_after_seconds > 0 при отказе', $r4['retry_after_seconds'] > 0);

        @unlink($path);

        // --- разные клиенты не мешают друг другу (отдельные "вёдра") ---
        $limiter2 = new RateLimiter($path, capacity: 1, refillSeconds: 60);
        $a1 = $limiter2->attempt('client-a');
        $b1 = $limiter2->attempt('client-b');
        $t->assertTrue('Клиент A получает свой токен', $a1['allowed']);
        $t->assertTrue('Клиент B не блокируется активностью клиента A', $b1['allowed']);

        @unlink($path);

        // --- пополнение токенов со временем (не мгновенное, а постепенное) ---
        $limiter3 = new RateLimiter($path, capacity: 2, refillSeconds: 1); // 2 токена/сек = быстрое пополнение для теста
        $limiter3->attempt('client-c');
        $limiter3->attempt('client-c');
        $blocked = $limiter3->attempt('client-c');
        $t->assertTrue('Ведро пусто сразу после исчерпания лимита', !$blocked['allowed']);

        usleep(600_000); // 0.6 сек — при refillSeconds=1 и capacity=2 должно хватить на >=1 токен
        $afterWait = $limiter3->attempt('client-c');
        $t->assertTrue('После паузы токены пополняются и запрос снова разрешён', $afterWait['allowed']);

        @unlink($path);

        // --- при сбое доступа к файлу лимитер не должен ронять запрос (fail-open) ---
        // Кладём "блокирующий" обычный файл вместо директории на пути хранилища —
        // так mkdir() и fopen() гарантированно не смогут создать/открыть путь,
        // даже если тесты запущены от root (которому обычно можно mkdir где угодно).
        $blockerFile = sys_get_temp_dir() . '/srp_ratelimit_blocker_' . uniqid();
        file_put_contents($blockerFile, 'not a directory');
        $unreachablePath = $blockerFile . '/subdir/ratelimit.json';

        $limiter4 = new RateLimiter($unreachablePath, capacity: 1, refillSeconds: 60);
        $fallback = $limiter4->attempt('client-d');
        $t->assertTrue('При недоступном хранилище лимитер по умолчанию пропускает запрос (fail-open)', $fallback['allowed']);

        @unlink($blockerFile);
    }
}
