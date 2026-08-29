<?php

/**
 * Простой PSR-4-совместимый автозагрузчик для namespace App\.
 *
 * Проект специально не требует обязательного `composer install`: PHP-версии
 * XAMPP «из коробки» не всегда идут с установленным Composer, а этот
 * автозагрузчик даёт тот же результат без дополнительных зависимостей —
 * достаточно скопировать файлы проекта и открыть index.php.
 *
 * Если Composer установлен и предпочтителен — можно использовать
 * `composer dump-autoload` и мы всё равно подключим сгенерированный
 * vendor/autoload.php, если он существует (см. ниже).
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

// Если проект развёрнут с Composer — используем его автозагрузчик тоже
// (не мешает, просто добавляет альтернативный путь автозагрузки).
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

// Необязательный локальный конфиг (не в git) — сюда можно положить
// putenv('AI_GATEWAY_API_KEY=...') для App\AI\TripAssistantService. Удобно
// для XAMPP/Apache, где переменные окружения не всегда просто прокинуть
// через shell export. Шаблон см. в config.local.php.example.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}
