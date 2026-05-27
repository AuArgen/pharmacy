<?php
declare(strict_types=1);

/**
 * API трекинга визита (fire-and-forget с фронтенда).
 * Принимает POST JSON: { lat, lng, accuracy, nearest_pharmacy_id, nearest_distance_km }
 * Сохраняет IP, координаты, регион/район (геокодирование) и дату в таблицу visits.
 */

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/visit.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    return;
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        $in = $_POST;
    }

    $lat = isset($in['lat']) ? (float) $in['lat'] : null;
    $lng = isset($in['lng']) ? (float) $in['lng'] : null;

    $valid = $lat !== null && $lng !== null
        && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;

    if (!$valid) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad coords']);
        return;
    }

    $pdo = get_db();
    $geo = reverse_geocode_cached($pdo, $lat, $lng);

    $clip = static fn($v) => is_string($v) ? mb_substr($v, 0, 40) : null;

    log_visit($pdo, [
        'ip'                  => client_ip(),
        'lat'                 => $lat,
        'lng'                 => $lng,
        'accuracy'            => isset($in['accuracy']) ? (float) $in['accuracy'] : null,
        'country'             => $geo['country'],
        'region'              => $geo['region'],
        'city'                => $geo['city'],
        'district'            => $geo['district'],
        'nearest_pharmacy_id' => isset($in['nearest_pharmacy_id']) ? (int) $in['nearest_pharmacy_id'] : null,
        'nearest_distance_km' => isset($in['nearest_distance_km']) ? (float) $in['nearest_distance_km'] : null,
        'device_type'         => $clip($in['device_type'] ?? null),
        'device_os'           => $clip($in['device_os'] ?? null),
        'device_browser'      => $clip($in['device_browser'] ?? null),
        'event'               => (($in['event'] ?? '') === 'leave') ? 'leave' : 'ping',
        'user_agent'          => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error']);
}
