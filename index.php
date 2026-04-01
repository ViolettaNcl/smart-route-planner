<?php include 'route_logic.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Маршрут</title>
    <link rel="stylesheet" href="route.css">
</head>
<body>

<div class="container">
    <h1>🚗 Калькулятор маршрута</h1>

    <form method="post" class="form">
        <label>Введите точки через «;»</label>

        <textarea name="points"
        placeholder="Например: Волгоград, Россия;Ростов-на-Дону, Россия;Воронеж, Россия;Москва, Россия"><?= isset($_POST['points']) ? htmlspecialchars($_POST['points']) : '' ?></textarea>

        <button type="submit">Рассчитать маршрут</button>
    </form>

    <div class="result">
        <div class="card">
            <span>📍 Точек</span>
            <strong><?= $count ?></strong>
        </div>

        <div class="card">
            <span>📏 Дистанция</span>
            <strong><?= $distance ?> км</strong>
        </div>

        <div class="card">
            <span>🚘 Транспорт</span>
            <strong><?= $mode ?></strong>
        </div>

        <div class="card">
            <span>⏱ Время</span>
            <strong><?= $time ?></strong>
        </div>
    </div>

    <ul class="points-list">
        <?php foreach ($points as $i => $p): ?>
            <li><span><?= $i + 1 ?></span><?= $p ?></li>
        <?php endforeach; ?>
    </ul>

    <div class="links">
        <a class="btn" href="<?= $googleUrl ?>" target="_blank">Google Maps</a>
        <a class="btn secondary" href="<?= $yandexUrl ?>" target="_blank">Yandex Maps</a>
    </div>
</div>

</body>
</html>