<?php

namespace Tests;

use App\ML\FeedbackStore;

class FeedbackStoreTest
{
    public function run(TestReporter $t): void
    {
        $path = sys_get_temp_dir() . '/ml_feedback_test_' . uniqid('', true) . '.ndjson';
        $store = new FeedbackStore($path);

        $first = $store->enqueue(382.4, 3, 'bus', 'car', 'mlp-12345678', 'route:test:1');
        $duplicate = $store->enqueue(382.4, 3, 'bus', 'car', 'mlp-12345678', 'route:test:1');
        $second = $store->enqueue(10.0, 2, 'walk', 'car', 'mlp-12345678', 'route:test:2');
        $events = $store->events();

        $t->assertTrue('Первое исправление принято', $first['accepted']);
        $t->assertTrue('Повторный event_id распознан как дубликат', $duplicate['duplicate']);
        $t->assertEquals('Дубликат не увеличивает очередь', 1, $duplicate['queue_size']);
        $t->assertEquals('В очереди только два уникальных события', 2, count($events));
        $t->assertTrue('Исходный event_id хранится только как hash', ($events[0]['event_id'] ?? '') !== 'route:test:1');
        $t->assertTrue('Очередь не содержит адресов и подписей точек', !isset($events[0]['address']) && !isset($events[0]['points']));
        $t->assertEquals('Размер после второго уникального события равен двум', 2, $second['queue_size']);

        $archivePath = $path . '.reviewed';
        $archive = $store->archiveSelected([(string) $events[0]['event_id']], $archivePath);
        $t->assertEquals('Только одобренное событие перенесено в архив', 1, $archive['archived']);
        $t->assertEquals('Неодобренное событие осталось в активной очереди', 1, $archive['remaining']);
        $t->assertEquals('Активная очередь содержит только pending-событие', 1, count($store->events()));
        $t->assertTrue('Архив создан как отдельный восстанавливаемый файл', is_file($archivePath));

        @unlink($path);
        @unlink($archivePath);
    }
}
