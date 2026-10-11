<?php

namespace App\Support;

class StoreContext
{
    private static ?int $storeId = null;

    private static bool $allStores = false;

    public static function set(?int $storeId, bool $allStores = false): void
    {
        self::$storeId = $storeId;
        self::$allStores = $allStores;
    }

    /**
     * Run a callback as if $storeId were the selected store (store scopes, store
     * settings), then restore the caller's selection. For work on a record of
     * another store than the one the user has selected, e.g. a shift's totals.
     */
    public static function runAs(?int $storeId, callable $callback): mixed
    {
        if ($storeId === null || $storeId <= 0) {
            return $callback();
        }

        $previous = [self::$storeId, self::$allStores];
        self::set($storeId, false);
        try {
            return $callback();
        } finally {
            self::set($previous[0], $previous[1]);
        }
    }

    public static function storeId(): ?int
    {
        return self::$storeId;
    }

    public static function allStores(): bool
    {
        return self::$allStores;
    }
}
