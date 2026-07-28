<?php

namespace App\Http;

/**
 * Определение "личности" клиента для rate limiting — по IP-адресу.
 *
 * Намеренно простая логика (REMOTE_ADDR, с опциональным доверием одному
 * заголовку прокси) — этого достаточно для демо-проекта за один сервер.
 * "Правильное" решение для продакшена за балансировщиком/CDN — доверять
 * X-Forwarded-For только от конкретных доверенных прокси-адресов, но это
 * требует конфигурации инфраструктуры, которой у пет-проекта нет.
 */
class ClientIdentity
{
    public static function ip(): string
    {
        // За реверс-прокси (например, при деплое за Nginx) REMOTE_ADDR может
        // быть адресом самого прокси, а не клиента — тогда реальный IP
        // передаётся в X-Forwarded-For. Берём первый адрес из списка (сам
        // клиент), если заголовок присутствует.
        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($forwardedFor !== null) {
            $first = trim(explode(',', $forwardedFor)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
