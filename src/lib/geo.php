<?php
declare(strict_types=1);

/**
 * Расстояние между двумя точками по формуле гаверсинусов (в километрах).
 */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371.0; // км

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Человекочитаемое расстояние: «350 м» или «2.4 км».
 */
function format_distance(float $km): string
{
    if ($km < 1.0) {
        return round($km * 1000) . ' м';
    }
    return number_format($km, 1, '.', ' ') . ' км';
}
