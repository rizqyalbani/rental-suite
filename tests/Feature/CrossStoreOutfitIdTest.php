<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Rental;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A raw `exists:outfits,id` ignored the store scope, so an outfit id of the OTHER store passed validation on
 * create, update and the cool-down request (overnight review P6). Only outfits of the current store may pass.
 */
class CrossStoreOutfitIdTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        foreach ([
            ['1100', 'Store Cash', 'asset', 'debit', true], ['1200', 'Bank', 'asset', 'debit', true],
            ['1300', 'Rental Receivable', 'asset', 'debit', false], ['2300', 'Customer Deposits', 'liability', 'credit', false],
            ['4100', 'Rental Revenue', 'revenue', 'credit', false], ['4200', 'Late Fee Revenue', 'revenue', 'credit', false],
            ['4400', 'Forfeited Deposits', 'revenue', 'credit', false], ['4910', 'Rental Refunds', 'revenue', 'debit', false],
        ] as [$code, $name, $type, $normal, $cash]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_cash_equivalent' => $cash, 'is_active' => true]);
        }
        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $this->otherStore = Store::create(['name' => 'Other Store', 'code' => 'other-store', 'is_active' => true]);
        $this->manager = User::factory()->create(['role' => 'management']);
        $this->manager->stores()->attach($this->store->id);
        ShiftSession::create(['opened_by_user_id' => $this->manager->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        StoreContext::set($this->store->id);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['current_store_id' => $this->store->id]);
    }

    private function outfit(Store $store, int $price = 300000): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $store->id, 'code' => 'X-'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    private function createBody(Outfit $outfit): array
    {
        return [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'Cross Store', 'phone' => '0811',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '300000', 'discount' => null, 'discount_type' => 'fixed',
        ];
    }

    public function test_create_rejects_a_outfit_of_another_store_and_accepts_an_own_one(): void
    {
        $foreign = $this->outfit($this->otherStore);
        $own = $this->outfit($this->store);

        $this->as($this->manager)->postJson(route('rentals.store'), $this->createBody($foreign))
            ->assertStatus(422)->assertJsonValidationErrors(['outfit_ids.0']);
        $this->assertSame(0, Rental::withoutGlobalScopes()->count(), 'nothing is booked for a foreign outfit');

        $this->as($this->manager)->postJson(route('rentals.store'), $this->createBody($own))->assertSuccessful();
        $this->assertSame(1, Rental::withoutGlobalScopes()->count());
    }

    public function test_create_with_one_own_and_one_foreign_outfit_is_refused_as_a_whole(): void
    {
        $body = $this->createBody($this->outfit($this->store));
        $body['outfit_ids'][] = $this->outfit($this->otherStore)->id;
        $body['amount_paid'] = '600000';

        $this->as($this->manager)->postJson(route('rentals.store'), $body)->assertStatus(422)->assertJsonValidationErrors(['outfit_ids.1']);
        $this->assertSame(0, Rental::withoutGlobalScopes()->count());
    }

    public function test_update_cannot_swap_in_a_outfit_of_another_store(): void
    {
        $own = $this->outfit($this->store);
        $this->as($this->manager)->postJson(route('rentals.store'), $this->createBody($own))->assertSuccessful();
        $rental = Rental::withoutGlobalScopes()->firstOrFail();

        $body = $this->createBody($this->outfit($this->otherStore));
        $this->as($this->manager)->putJson(route('rentals.update', $rental), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['outfit_ids.0']);

        $this->assertSame([$own->id], $rental->fresh('outfits')->outfits->pluck('id')->all(), 'the rent keeps its own outfit');
    }

    public function test_cooldown_request_rejects_a_outfit_of_another_store(): void
    {
        $foreign = $this->outfit($this->otherStore);

        $this->as($this->manager)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$foreign->id],
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['outfit_ids.0']);
    }
}
