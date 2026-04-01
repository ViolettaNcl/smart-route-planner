<?php

function getCoords($city)
{
    $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($city);

    $context = stream_context_create([
        "http" => ["header" => "User-Agent: route-app\r\n"]
    ]);

    $data = json_decode(file_get_contents($url, false, $context), true);

    return !empty($data)
        ? ['lat' => (float)$data[0]['lat'], 'lon' => (float)$data[0]['lon']]
        : null;
}

$points = !empty($_POST['points'])
    ? array_values(array_filter(array_map('trim', explode(';', $_POST['points']))))
    : ['Волгоград, Россия', 'Ростов-на-Дону, Россия'];

$coords = [];
$validPoints = [];

foreach ($points as $p) {
    if ($c = getCoords($p)) {
        $coords[$p] = $c;
        $validPoints[] = $p;
        usleep(200000);
    }
}

$points = $validPoints;

function distanceKm($a, $b, $coords)
{
    $R = 6371;

    $lat1 = $coords[$a]['lat'];
    $lon1 = $coords[$a]['lon'];
    $lat2 = $coords[$b]['lat'];
    $lon2 = $coords[$b]['lon'];

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    // Формула гаверсинусов
    $h = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;

    return $R * 2 * atan2(sqrt($h), sqrt(1-$h));
}

$distance = 0;
$n = count($points);

for ($i = 0; $i < $n - 1; $i++) {
    $distance += distanceKm($points[$i], $points[$i + 1], $coords);
}

$distance = round($distance);
$count = $n;

function predictTransportNN($distance, $count)
{
    $x1 = $distance / 1000;
    $x2 = $count / 10;

    $W = [
        'walk' => [-3.0, -1.5, 2.0],
        'car'  => [1.0, 0.5, 0.5],
        'bus'  => [2.5, 1.2, -1.0],
    ];

    $scores = [];
    foreach ($W as $k => $w) {
        $scores[$k] = exp($w[0]*$x1 + $w[1]*$x2 + $w[2]);
    }

    $best = array_keys($scores, max($scores))[0];

    return match ($best) {
        'walk' => 'пешком',
        'car'  => 'авто',
        default => 'общественный транспорт',
    };
}

$mode = predictTransportNN($distance, $count);

$googleUrl = 'https://www.google.com/maps/dir/' . implode('/', array_map('urlencode', $points));
$yandexUrl = 'https://yandex.ru/maps/?rtext=' . implode('~', array_map('urlencode', $points)) . '&rtt=auto';

$time = date('Y-m-d H:i:s');