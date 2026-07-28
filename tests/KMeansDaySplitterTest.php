<?php

namespace Tests;

use App\ML\KMeansDaySplitter;

class KMeansDaySplitterTest
{
    public function run(TestReporter $t): void
    {
        $splitter = new KMeansDaySplitter();

        // --- k=1 всегда возвращает один день со всеми перегонами ---
        $legs = [100.0, 150.0, 200.0, 50.0];
        $oneDay = $splitter->splitIntoDays($legs, 1);
        $t->assertEquals('k=1 -> один день', 1, count($oneDay));
        $t->assertEquals('k=1 -> вся дистанция в одном дне', 500.0, $oneDay[0]['distance_km']);
        $t->assertEquals('k=1 -> охватывает все перегоны (leg_end)', 3, $oneDay[0]['leg_end']);

        // --- пустой маршрут (0 перегонов) -> пустой план ---
        $empty = $splitter->splitIntoDays([], 3);
        $t->assertEquals('0 перегонов -> пустой план', 0, count($empty));

        // --- дни идут по порядку (leg_start/leg_end монотонно возрастают, без пропусков и наложений) ---
        $legsLong = [500.0, 480.0, 510.0, 490.0, 520.0, 470.0]; // ~ 6 дней по машине
        $plan = $splitter->splitIntoDays($legsLong, 3);

        $t->assertTrue('Число дней <= запрошенного k', count($plan) <= 3);

        $covered = 0;
        $prevEnd = -1;
        $orderOk = true;
        foreach ($plan as $day) {
            if ($day['leg_start'] !== $prevEnd + 1) {
                $orderOk = false;
            }
            $prevEnd = $day['leg_end'];
            $covered += $day['leg_end'] - $day['leg_start'] + 1;
        }
        $t->assertTrue('Дни идут подряд без пропусков/наложений перегонов', $orderOk);
        $t->assertEquals('Все перегоны распределены по дням', count($legsLong), $covered);
        $t->assertEquals('Последний день заканчивается на последнем перегоне', count($legsLong) - 1, $prevEnd);

        $totalPlanned = array_sum(array_column($plan, 'distance_km'));
        $t->assertEquals('Сумма дистанций по дням = общая дистанция', round(array_sum($legsLong), 1), round($totalPlanned, 1));

        // --- k больше, чем перегонов -> урезается до количества перегонов ---
        $shortLegs = [100.0, 200.0];
        $tooManyDays = $splitter->splitIntoDays($shortLegs, 10);
        $t->assertTrue('k урезается до числа перегонов', count($tooManyDays) <= count($shortLegs));

        // --- сбалансированность: примерно равные по длине дни, а не "всё в первый" ---
        $evenLegs = array_fill(0, 9, 100.0); // 900 км, 9 равных перегонов
        $balanced = $splitter->splitIntoDays($evenLegs, 3);
        $t->assertEquals('Равные перегоны -> ровно 3 дня', 3, count($balanced));
        foreach ($balanced as $day) {
            $t->assertTrue(
                "День {$day['day']} сбалансирован (200-400 км из 900 на 3 дня)",
                $day['distance_km'] >= 200.0 && $day['distance_km'] <= 400.0
            );
        }

        // --- suggestDays: разумные значения ---
        $t->assertEquals('500 км при цели 500 км/день -> 1 день', 1, $splitter->suggestDays(500, 500));
        $t->assertEquals('1000 км при цели 500 км/день -> 2 дня', 2, $splitter->suggestDays(1000, 500));
        $t->assertEquals('1001 км при цели 500 км/день -> 3 дня (округление вверх)', 3, $splitter->suggestDays(1001, 500));
        $t->assertEquals('0 км -> 1 день (не 0)', 1, $splitter->suggestDays(0, 500));

        // --- детерминированность: два прогона на одних данных дают одинаковый результат ---
        $splitter2 = new KMeansDaySplitter();
        $planAgain = $splitter2->splitIntoDays($legsLong, 3);
        $t->assertEquals('Результат детерминирован между прогонами', json_encode($plan), json_encode($planAgain));
    }
}
