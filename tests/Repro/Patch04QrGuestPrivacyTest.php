<?php

namespace Tests\Repro;

use App\Models\Rental;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Guard test for finding C7-04 ("public QR page /i/{token} prints renter name and phone").
 * Result of the C16 analysis: the finding does not hold for the current code. outfits/status.blade.php shows the
 * renter block only inside the @else of `@if(! $opnameUser)`; a guest, and any logged-in user who may not take
 * part in stock opname (e.g. accounting), only gets the login/PIN prompt. So there is NO patch 04.
 * This test PASSES on the current code and pins that behaviour (and the intended access of staff / PIN users),
 * so a later template change cannot silently expose renters. Consider moving it to tests/Feature.
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Repro/Patch04QrGuestPrivacyTest.php
 */
class Patch04QrGuestPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Outfit $outfit;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        $this->store = Store::create(['name' => 'Demo Store One', 'code' => 'store-a', 'is_active' => true]);
        StoreContext::set($this->store->id);
        StoreCache::forever('pricing_tax_percent', 0);
        StoreCache::forever('pricing_prices_include_tax', false);

        $handler = User::factory()->create(['role' => 'admin', 'name' => 'Handler Wendy']);
        $this->token = str_repeat('b', 40);
        $this->outfit = Outfit::withoutGlobalScopes()->create([
            'store_id' => $this->store->id, 'code' => 'QR-1', 'price' => 300000, 'stock' => 2,
        ]);
        // qr_token is generated on create; pin it to a known value.
        Outfit::withoutGlobalScopes()->whereKey($this->outfit->id)->update(['qr_token' => $this->token]);
        $start = now()->subDay()->startOfDay();
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Mrs Secret', 'phone' => '081299990000',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
            'handled_by_user_id' => $handler->id,
        ]);
        $rental->outfits()->attach($this->outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        StoreContext::set(null);
    }

    private function assertNoRenterData(string $html): void
    {
        $this->assertStringNotContainsString('Mrs Secret', $html);
        $this->assertStringNotContainsString('081299990000', $html);
        $this->assertStringNotContainsString('Handler Wendy', $html);
        $this->assertStringNotContainsString('Current Rentals', $html);
    }

    public function test_guest_on_the_printed_qr_link_sees_only_the_login_prompt(): void
    {
        $response = $this->get('/i/'.$this->token)->assertOk();
        $this->assertNoRenterData($response->getContent());
        $response->assertSee('Log in first to see the info for this item.');
    }

    public function test_guest_on_the_signed_status_link_sees_only_the_login_prompt(): void
    {
        $url = URL::signedRoute('outfits.status', ['outfit' => $this->outfit], absolute: false);
        $response = $this->get($url)->assertOk();
        $this->assertNoRenterData($response->getContent());
    }

    public function test_logged_in_user_who_may_not_do_opname_sees_no_renter_data(): void
    {
        $accounting = User::factory()->create(['role' => 'accounting']);

        $response = $this->actingAs($accounting)->get('/i/'.$this->token)->assertOk();
        $this->assertNoRenterData($response->getContent());
    }

    /** Intended behaviour (control): staff who take part in opname keep seeing the renter block. */
    public function test_logged_in_staff_sees_the_renter_block(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->stores()->attach($this->store->id);

        $this->actingAs($admin)->withSession(['current_store_id' => $this->store->id])
            ->get('/i/'.$this->token)->assertOk()
            ->assertSee('Mrs Secret')->assertSee('081299990000')->assertSee('Handler Wendy');
    }

    /** Intended behaviour (control): a PIN-verified opname user (no real login) keeps seeing it too. */
    public function test_pin_verified_opname_user_sees_the_renter_block(): void
    {
        $staff = User::factory()->create(['role' => 'supervisor']);

        $this->withSession([
            'stock_opname_pin_user_id' => $staff->id,
            'stock_opname_pin_verified_at' => now()->timestamp,
            'stock_opname_pin_store_id' => $this->store->id,
        ])->get('/i/'.$this->token)->assertOk()
            ->assertSee('Mrs Secret')->assertSee('081299990000');
    }
}
