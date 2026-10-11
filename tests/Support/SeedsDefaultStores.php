<?php

namespace Tests\Support;

use App\Support\StoreContext;
use Illuminate\Support\Facades\DB;

/**
 * Gives a test the two stores production always has (id 1 and id 2).
 *
 * The stores are normally inserted by the 2026_05_10_150000 migration, but the sqlite
 * test database is built from database/schema/sqlite-schema.sql, which only carries the
 * tables, not that seed. Without a store row the {outfit}/{rental}/{shift} route bindings
 * resolve against an empty store list (404) and any model that falls back to a stale
 * StoreContext id fails with a FOREIGN KEY error.
 *
 * Laravel runs setUpSeedsDefaultStores() after RefreshDatabase has opened its transaction,
 * so the rows disappear again when the test ends. StoreContext is static and survives
 * between tests in one phpunit process, so it is reset here too.
 *
 * Usage: `use RefreshDatabase, SeedsDefaultStores;` in the test class.
 */
trait SeedsDefaultStores
{
    protected function setUpSeedsDefaultStores(): void
    {
        StoreContext::set(null, false);

        foreach ([1 => 'Store 1', 2 => 'Store 2'] as $id => $name) {
            DB::table('stores')->insertOrIgnore([
                'id' => $id,
                'name' => $name,
                'code' => 'store-'.$id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
