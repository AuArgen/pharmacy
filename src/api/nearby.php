<?php
declare(strict_types=1);

/**
 * API: ближайшие аптеки.
 * GET /api/nearby.php?lat=42.87&lng=74.60&limit=10
 * Возвращает JSON со списком аптек, отсортированных по расстоянию.
 */

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/geo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
    $limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 10;

    $validCoords = $lat !== null && $lng !== null
        && $lat >= -90 && $lat <= 90
        && $lng >= -180 && $lng <= 180;

    $pdo = get_db();
    $pharmacies = $pdo->query('SELECT * FROM pharmacies')->fetchAll();

    foreach ($pharmacies as &$p) {
        $p['id']     = (int) $p['id'];
        $p['lat']    = (float) $p['lat'];
        $p['lng']    = (float) $p['lng'];
        $p['is_24h'] = (bool) $p['is_24h'];

        if ($validCoords) {
            $km = haversine_km($lat, $lng, $p['lat'], $p['lng']);
            $p['distance_km']   = round($km, 3);
            $p['distance_text'] = format_distance($km);
        } else {
            $p['distance_km']   = null;
            $p['distance_text'] = null;
        }
    }
    unset($p);

    if ($validCoords) {
        usort($pharmacies, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    }

    $pharmacies = array_slice($pharmacies, 0, $limit);

    echo json_encode([
        'ok'             => true,
        'has_location'   => $validCoords,
        'count'          => count($pharmacies),
        'pharmacies'     => $pharmacies,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Внутренняя ошибка сервера',
    ], JSON_UNESCAPED_UNICODE);
}
