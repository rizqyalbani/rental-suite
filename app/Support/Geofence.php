<?php

namespace App\Support;

use App\Models\StoreSetting;

/**
 * Per-store login geofence for Admin/Supervisor — opt-in (a store with no
 * geofence configured never blocks anything), checked once at login (main
 * /login, the inline password form on a public item page, and the Stock
 * Opname PIN gate). Stored as plain key/value rows on store_settings, same
 * pattern as store_primary_color/store_admin_phone_1 (see StoreController),
 * rather than new columns — this is arbitrary-store lookup, unlike
 * StoreCache which always reads the CURRENT session's store, so it can't be
 * reused here: at login there is no store context yet, and the opname PIN
 * check is keyed to the scanned item's own store, not the caller's.
 */
class Geofence
{
    /**
     * @return array{lat: float, lng: float, radius_m: int}|null null when
     *                                                            the store has no geofence configured, or it's turned off.
     */
    public static function configFor(int $storeId): ?array
    {
        $rows = StoreSetting::query()
            ->where('store_id', $storeId)
            ->whereIn('key', ['geofence_enabled', 'geofence_latitude', 'geofence_longitude', 'geofence_radius_m'])
            ->pluck('value', 'key');

        $enabled = isset($rows['geofence_enabled']) && json_decode((string) $rows['geofence_enabled'], true);
        if (! $enabled) {
            return null;
        }

        $lat = isset($rows['geofence_latitude']) ? json_decode((string) $rows['geofence_latitude'], true) : null;
        $lng = isset($rows['geofence_longitude']) ? json_decode((string) $rows['geofence_longitude'], true) : null;
        $radius = isset($rows['geofence_radius_m']) ? json_decode((string) $rows['geofence_radius_m'], true) : null;

        if ($lat === null || $lng === null || $radius === null) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng, 'radius_m' => (int) $radius];
    }

    /**
     * True when the store has no geofence configured (opt-in — never blocks
     * by default), or the given coordinates fall within its radius. Missing
     * coordinates (permission denied, or the browser hasn't resolved a
     * position yet) fail the check for a configured store — there's nothing
     * to verify against. $accuracyM, when given, is added to the allowed
     * radius (capped) so a device with a wide GPS error margin isn't
     * unfairly rejected while genuinely standing in range.
     */
    public static function passes(int $storeId, ?float $lat, ?float $lng, ?float $accuracyM = null): bool
    {
        $config = self::configFor($storeId);
        if ($config === null) {
            return true;
        }
        if ($lat === null || $lng === null) {
            return false;
        }

        $buffer = min($accuracyM ?? 0, 300);
        $distance = self::distanceMeters($config['lat'], $config['lng'], $lat, $lng);

        return $distance <= ($config['radius_m'] + $buffer);
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }
}
