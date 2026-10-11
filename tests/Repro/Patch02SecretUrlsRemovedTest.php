<?php

namespace Tests\Repro;

use App\Models\Rental;
use App\Models\Store;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for patch 02-remove-secret-url-routes (finding C6-04).
 * FAILS on the current code when the secrets are set (the URLs act), PASSES once the patch is applied (404).
 * Not part of the default suites; run by path:
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Repro/Patch02SecretUrlsRemovedTest.php
 */
class Patch02SecretUrlsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        $this->store = Store::create(['name' => 'Demo Store One', 'code' => 'store-a', 'is_active' => true]);
        StoreContext::set($this->store->id);
        StoreCache::forever('pricing_enabled', true);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        unset($_SERVER['DELETE_ALL_SECRET'], $_ENV['DELETE_ALL_SECRET'], $_SERVER['SITE_SECRET'], $_ENV['SITE_SECRET']);
        parent::tearDown();
    }

    private function rental(): Rental
    {
        return Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Mrs Secret', 'phone' => '081299990000',
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(12)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
        ]);
    }

    public function test_delete_all_url_is_gone_and_wipes_nothing(): void
    {
        $this->rental();
        $this->rental();
        StoreContext::set(null);
        $_SERVER['DELETE_ALL_SECRET'] = $_ENV['DELETE_ALL_SECRET'] = 'repro-secret';

        $this->post('/delete-all/repro-secret/rentals', ['confirm' => 'DELETE'])->assertNotFound();
        $this->get('/delete-all/repro-secret/rentals')->assertNotFound();

        $this->assertSame(2, Rental::withoutGlobalScope('store_scope')->count(), 'no rental was soft-deleted');
    }

    public function test_pricing_toggle_url_is_gone_for_every_method_and_changes_nothing(): void
    {
        $_SERVER['SITE_SECRET'] = $_ENV['SITE_SECRET'] = 'repro-site-secret';

        $this->get('/toggle/repro-site-secret/pricing')->assertNotFound();
        $this->post('/toggle/repro-site-secret/pricing')->assertNotFound();

        $this->assertTrue((bool) StoreCache::get('pricing_enabled', true), 'pricing flag unchanged');
    }

    /** Control: Management still has the supported way to switch pricing (Settings form), so removing the secret URL loses no feature. */
    public function test_settings_pricing_route_still_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('settings.pricing'));
    }
}
