<?php
declare(strict_types=1);

/**
 * Утилиты для логирования визитов: определение IP клиента и
 * обратное геокодирование координат (регион/город/район) через Nominatim (OSM).
 */

/**
 * Возвращает IP-адрес клиента с учётом прокси (X-Forwarded-For).
 */
function client_ip(): string
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Может быть список "client, proxy1, proxy2" — берём первый.
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidates[] = trim($parts[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
    }
    $candidates[] = $_SERVER['REMOTE_ADDR'] ?? '';

    foreach ($candidates as $ip) {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $candidates[count($candidates) - 1] ?: 'unknown';
}

/**
 * Обратное геокодирование через Nominatim (OpenStreetMap).
 * Best-effort: при ошибке/таймауте возвращает массив с null-значениями.
 * Возвращает: ['country'=>?, 'region'=>?, 'city'=>?, 'district'=>?]
 */
function reverse_geocode(float $lat, float $lng): array
{
    $empty = ['country' => null, 'region' => null, 'city' => null, 'district' => null];

    // Можно отключить внешний запрос через переменную окружения.
    if (getenv('DISABLE_GEOCODING') === '1') {
        return $empty;
    }

    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'format'          => 'jsonv2',
        'lat'             => $lat,
        'lon'             => $lng,
        'zoom'            => 14,
        'accept-language' => 'ru',
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 4,
            // Nominatim требует валидный User-Agent.
            'header'  => "User-Agent: AptekaRyadom/1.0 (demo)\r\n",
        ],
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return $empty;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['address'])) {
        return $empty;
    }

    $a = $data['address'];

    return [
        'country'  => $a['country'] ?? null,
        'region'   => $a['state'] ?? $a['region'] ?? $a['province'] ?? null,
        'city'     => $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? null,
        'district' => $a['city_district'] ?? $a['suburb'] ?? $a['district'] ?? $a['neighbourhood'] ?? null,
    ];
}

/**
 * Обратное геокодирование с кэшем в БД.
 * Координаты округляются до 3 знаков (~110 м), результат переиспользуется —
 * поэтому частые пинги (раз в 3 сек) НЕ дёргают Nominatim повторно.
 */
function reverse_geocode_cached(PDO $pdo, float $lat, float $lng): array
{
    $key = round($lat, 3) . ',' . round($lng, 3);

    $stmt = $pdo->prepare('SELECT country, region, city, district FROM geocode_cache WHERE coord_key = :k');
    $stmt->execute([':k' => $key]);
    $hit = $stmt->fetch();
    if ($hit) {
        return $hit;
    }

    $geo = reverse_geocode($lat, $lng);

    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO geocode_cache (coord_key, country, region, city, district, created_at)
         VALUES (:k, :country, :region, :city, :district, :ts)'
    );
    $ins->execute([
        ':k'        => $key,
        ':country'  => $geo['country'],
        ':region'   => $geo['region'],
        ':city'     => $geo['city'],
        ':district' => $geo['district'],
        ':ts'       => gmdate('Y-m-d H:i:s'),
    ]);

    return $geo;
}

/**
 * Записывает визит в таблицу visits.
 */
function log_visit(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO visits
            (ip, lat, lng, accuracy, country, region, city, district,
             nearest_pharmacy_id, nearest_distance_km,
             device_type, device_os, device_browser, event, user_agent, created_at)
         VALUES
            (:ip, :lat, :lng, :accuracy, :country, :region, :city, :district,
             :npid, :ndist,
             :dtype, :dos, :dbrowser, :event, :ua, :created_at)'
    );

    $stmt->execute([
        ':ip'         => $data['ip'] ?? null,
        ':lat'        => $data['lat'] ?? null,
        ':lng'        => $data['lng'] ?? null,
        ':accuracy'   => $data['accuracy'] ?? null,
        ':country'    => $data['country'] ?? null,
        ':region'     => $data['region'] ?? null,
        ':city'       => $data['city'] ?? null,
        ':district'   => $data['district'] ?? null,
        ':npid'       => $data['nearest_pharmacy_id'] ?? null,
        ':ndist'      => $data['nearest_distance_km'] ?? null,
        ':dtype'      => $data['device_type'] ?? null,
        ':dos'        => $data['device_os'] ?? null,
        ':dbrowser'   => $data['device_browser'] ?? null,
        ':event'      => $data['event'] ?? null,
        ':ua'         => $data['user_agent'] ?? null,
        ':created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}
