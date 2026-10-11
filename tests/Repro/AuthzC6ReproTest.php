<?php

namespace Tests\Repro;

use App\Models\Rental;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\User;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Throwaway repros for the C6 overnight authz review (docs/overnight-2026-10-09/05a-authz-review.md).
 * test_BUG_* assert the CURRENT (suspected wrong) behaviour: a passing test = issue confirmed.
 * test_OK_* are negative controls.
 * Not part of the default suites; run by path.
 */
class AuthzC6ReproTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        $this->store = Store::create(['name' => 'Demo Store One', 'code' => 'store-a', 'is_active' => true]);
        StoreContext::set($this->store->id);
        StoreCache::forever('pricing_tax_percent', 0);
        StoreCache::forever('pricing_prices_include_tax', false);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        unset($_SERVER['DELETE_ALL_SECRET'], $_ENV['DELETE_ALL_SECRET']);
        parent::tearDown();
    }

    private function realRental(array $over = []): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'S-'.uniqid(), 'price' => 300000, 'stock' => 1, 'qr_token' => null]);
        $start = now()->addDays(10)->startOfDay();
        $rental = Rental::create(array_merge([
            'store_id' => $this->store->id, 'customer_name' => 'Mrs Secret', 'phone' => '081299990000',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
        ], $over));
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);

        return $rental->fresh('outfits');
    }

    /** C6-1 (closed by patch 01): public self-registration no longer exists. Was test_BUG_public_registration_yields_admin_with_store_data_access. */
    public function test_FIXED_public_registration_is_closed_and_creates_no_user(): void
    {
        $this->realRental();
        StoreContext::set(null);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Stranger', 'email' => 'stranger@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertNotFound();

        $this->assertNull(User::where('email', 'stranger@example.test')->first(), 'no account is created');
        $this->assertGuest();

        // Customer PII of store 1 stays behind the login.
        $this->get('/emergency')->assertRedirect(route('login'));
    }

    /** Control (suspicion disproved): the public QR page hides renter PII from guests behind a login/PIN prompt. */
    public function test_OK_public_qr_page_hides_renter_from_guest(): void
    {
        $rental = $this->realRental();
        $outfit = $rental->outfits->first();
        $token = str_repeat('a', 40);
        Outfit::withoutGlobalScopes()->whereKey($outfit->id)->update(['qr_token' => $token]);
        StoreContext::set(null);

        $this->get('/i/'.$token)->assertOk()->assertDontSee('Mrs Secret')->assertDontSee('081299990000');
    }

    /** Control: without the flag an admin with no open shift is blocked. */
    public function test_OK_admin_without_shift_cannot_cancel_real_rent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->stores()->attach($this->store->id);
        $rental = $this->realRental();

        $this->actingAs($admin)->withSession(['current_store_id' => $this->store->id])
            ->postJson(route('rentals.status', $rental), ['status' => 'cancelled'])
            ->assertStatus(422);
        $this->assertSame('active', $rental->fresh()->status);
    }

    /** C6-3 (FIXED by WP1): the client-supplied tutorial_practice=1 flag no longer skips the open-shift rule for a REAL rent. */
    public function test_FIXED_tutorial_practice_flag_cannot_bypass_open_shift_on_real_rent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->stores()->attach($this->store->id);
        $rental = $this->realRental();

        $this->actingAs($admin)->withSession(['current_store_id' => $this->store->id])
            ->postJson(route('rentals.status', $rental), ['status' => 'cancelled', 'tutorial_practice' => 1])
            ->assertStatus(422);
        $this->assertSame('active', $rental->fresh()->status, 'the real rent stays untouched without an open shift');
    }

    /**
     * C6-4 (FIXED by WP1): practice card actions (status/pickup/edit/free/invoice) post to /rentals/{id}/... WITHOUT ?tutorial=1
     * (rentals-index.js 3904/4064/4283/5855/6272/6285). The {rental} binding prefers the REAL rent with that id.
     */
    public function test_FIXED_practice_status_action_hits_the_practice_rent_not_the_real_one(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $real = $this->realRental(['customer_name' => 'Real Customer']);
        $practice = TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(12)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
            'is_tutorial' => true, 'created_by_user_id' => $manager->id,
        ]);
        $this->assertSame($real->id, $practice->id, 'precondition: ids collide (separate sequences)');

        // Exactly what the practice card's Cancel sends (body carries tutorial_practice, URL has no ?tutorial=1).
        $this->actingAs($manager)->withSession(['current_store_id' => $this->store->id])
            ->postJson('/rentals/'.$practice->id.'/status', ['status' => 'cancelled', 'tutorial_practice' => 1])
            ->assertOk();

        $this->assertSame('active', $real->fresh()->status, 'the REAL rent is untouched by a practice action');
        $this->assertSame('cancelled', $practice->fresh()->status);
    }

    /** C6-6: Admin's "expense" form accepts ANY active leaf accounts server-side: here Dr Cash / Cr Rental Revenue. */
    public function test_BUG_admin_expense_can_post_to_non_expense_non_cash_accounts(): void
    {
        $cash = \App\Models\Account::create(['code' => '1100', 'name' => 'Store Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]);
        $revenue = \App\Models\Account::create(['code' => '4100', 'name' => 'Rental Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_equivalent' => false, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->stores()->attach($this->store->id);

        $this->actingAs($admin)->withSession(['current_store_id' => $this->store->id])
            ->post(route('expenses.store'), [
                'expense_date' => now()->toDateString(), 'account_id' => $cash->id,
                'payment_account_id' => $revenue->id, 'amount' => '1000000', 'description' => 'repro',
            ])->assertSessionHasNoErrors();

        $line = \App\Models\JournalEntryLine::where('account_id', $revenue->id)->first();
        $this->assertNotNull($line, 'a journal line hit the revenue account from the expense form');
        $this->assertEquals(1000000, (float) $line->credit);
    }

    /** C6-5 (closed by patch 02): the secret-URL routes are gone; nothing is deleted without login. Was test_BUG_delete_all_secret_url_wipes_all_stores_without_login. */
    public function test_FIXED_delete_all_secret_url_is_removed_and_deletes_nothing(): void
    {
        $other = Store::create(['name' => 'Demo Store Two', 'code' => 'store-two', 'is_active' => true]);
        $this->realRental();
        $this->realRental(['store_id' => $other->id]);
        StoreContext::set(null);
        $_SERVER['DELETE_ALL_SECRET'] = $_ENV['DELETE_ALL_SECRET'] = 'repro-secret';

        $this->post('/delete-all/repro-secret/rentals', ['confirm' => 'DELETE'])->assertNotFound();
        $this->get('/delete-all/repro-secret/rentals')->assertNotFound();
        $this->assertSame(2, Rental::withoutGlobalScope('store_scope')->count(), 'both stores untouched');
        $this->assertSame(0, Rental::withoutGlobalScope('store_scope')->onlyTrashed()->count(), 'nothing soft-deleted');
    }
}
