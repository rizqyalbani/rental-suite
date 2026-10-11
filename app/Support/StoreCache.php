<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Cache;

class StoreCache
{
    public static function key(string $key): string
    {
        $storeId = StoreContext::storeId();
        if ($storeId === null) {
            return $key;
        }

        return 'store:'.$storeId.':'.$key;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $storeId = StoreContext::storeId();
        if ($storeId !== null) {
            $storeValue = self::readFromDatabase($storeId, $key);
            if ($storeValue !== null) {
                return $storeValue;
            }
        }

        $globalValue = self::readFromDatabase(null, $key);
        if ($globalValue !== null) {
            return $globalValue;
        }

        $legacy = self::readLegacyCache($key);
        if ($legacy !== null) {
            return $legacy;
        }

        return $default;
    }

    /**
     * $alsoGlobal controls whether the value is also written to the shared
     * store_id=null fallback row. Leave it true (the default, and what every
     * existing caller relies on) for settings meant to have one shared
     * default that any store can override. Pass false for settings that
     * must stay fully isolated per store (e.g. fonnte_token) — otherwise
     * whichever store saves last silently becomes every other store's
     * fallback value.
     */
    public static function forever(string $key, mixed $value, bool $alsoGlobal = true): void
    {
        self::writeToDatabase($key, $value, StoreContext::storeId());
        if ($alsoGlobal) {
            self::writeToDatabase($key, $value, null);
        }
    }

    public static function forget(string $key, bool $alsoGlobal = true): void
    {
        $storeId = StoreContext::storeId();
        if ($storeId !== null) {
            StoreSetting::query()
                ->where('store_id', $storeId)
                ->where('key', $key)
                ->delete();
        }

        if ($alsoGlobal) {
            StoreSetting::query()
                ->whereNull('store_id')
                ->where('key', $key)
                ->delete();
            Cache::forget($key);
        }

        Cache::forget(self::key($key));
    }

    private static function readFromDatabase(?int $storeId, string $key): mixed
    {
        $query = StoreSetting::query()->where('key', $key);
        if ($storeId === null) {
            $query->whereNull('store_id');
        } else {
            $query->where('store_id', $storeId);
        }

        $row = $query->first();
        if ($row === null || $row->value === null) {
            return null;
        }

        return self::decodeValue($row->value);
    }

    private static function writeToDatabase(string $key, mixed $value, ?int $storeId): void
    {
        StoreSetting::query()->updateOrCreate(
            [
                'store_id' => $storeId,
                'key' => $key,
            ],
            [
                'value' => self::encodeValue($value),
            ],
        );
    }

    private static function readLegacyCache(string $key): mixed
    {
        $storeKey = self::key($key);
        $storeValue = Cache::get($storeKey);
        if ($storeValue !== null) {
            return $storeValue;
        }

        return Cache::get($key);
    }

    private static function encodeValue(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function decodeValue(string $value): mixed
    {
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
