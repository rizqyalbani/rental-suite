<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use App\Support\StoreCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class RentalPaymentValidationTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_store_requires_payment_fields_when_pricing_enabled(): void
    {
        StoreCache::forever('pricing_enabled', true);

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('shifts.open'), ['period' => 'morning']);

        $outfit = Outfit::create([
            'code' => 'PAY-REQ-01',
            'description' => 'Payment test',
            'price' => 100000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->from(route('rentals.index'))->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'No Payment',
            'phone' => '08123456789',
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            // payment_* intentionally omitted
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors(['payment_method', 'payment_kind', 'amount_paid']);
    }

    public function test_store_accepts_when_payment_fields_are_present(): void
    {
        StoreCache::forever('pricing_enabled', true);

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('shifts.open'), ['period' => 'morning']);

        $outfit = Outfit::create([
            'code' => 'PAY-OK-01',
            'description' => 'Payment test',
            'price' => 120000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Paid Customer',
            'phone' => '08123456780',
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '120000',
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();
    }

    public function test_store_rejects_full_payment_below_total_due(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 10);

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('shifts.open'), ['period' => 'morning']);

        $outfit = Outfit::create([
            'code' => 'PAY-FULL-LOW',
            'description' => 'Payment test',
            'price' => 100000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->from(route('rentals.index'))->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Underpaid',
            'phone' => '08123456780',
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '100000',
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors('amount_paid');
    }

    public function test_store_allows_down_payment_below_total_due(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 10);

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('shifts.open'), ['period' => 'morning']);

        $outfit = Outfit::create([
            'code' => 'PAY-DOWN-OK',
            'description' => 'Payment test',
            'price' => 100000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Down pay',
            'phone' => '08123456780',
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => '50000',
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();
    }

    public function test_update_accepts_increment_payment_adds_to_saved_amount_and_sets_full_when_settled(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 0);

        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'PAY-INC-01',
            'description' => 'Increment payment test',
            'price' => 100000,
            'stock' => 1,
        ]);

        $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Pay Later',
            'phone' => '08123456780',
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => '30000',
        ]);

        $rental = Rental::query()->latest('id')->first();
        $this->assertNotNull($rental);
        $this->assertSame('down_payment', $rental->payment_kind);
        $this->assertEquals(30000.0, (float) $rental->amount_paid);

        $response = $this->actingAs($user)->put(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Pay Later',
            'phone' => '08123456780',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'payment_entry' => 'increment',
            'payment_amount_add' => '70000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();

        $rental->refresh();
        // A down payment that gets settled is stored as the final payment.
        $this->assertSame('final_payment', $rental->payment_kind);
        $this->assertEquals(100000.0, (float) $rental->amount_paid);
    }

    public function test_update_increment_mode_prohibits_amount_paid_field(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 0);

        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'PAY-INC-02',
            'description' => 'Prohibited amount_paid test',
            'price' => 50000,
            'stock' => 1,
        ]);

        $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'A',
            'phone' => '08111111111',
            'notes' => 'X',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => '10000',
        ]);

        $rental = Rental::query()->latest('id')->first();

        $response = $this->actingAs($user)->from(route('rentals.index'))->put(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'A',
            'phone' => '08111111111',
            'instagram' => null,
            'notes' => 'X',
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'payment_entry' => 'increment',
            'payment_amount_add' => '10000',
            'amount_paid' => '99999',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors('amount_paid');
    }

    public function test_update_keeps_saved_payment_when_rental_was_full_payment_even_if_request_changes_amount(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 0);

        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'PAY-FULL-LOCK',
            'description' => 'Full lock',
            'price' => 80000,
            'stock' => 1,
        ]);

        $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Full Payer',
            'phone' => '08123456780',
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '80000',
        ]);

        $rental = Rental::query()->latest('id')->first();
        $this->assertNotNull($rental);
        $this->assertSame('full', $rental->payment_kind);
        $this->assertEquals(80000.0, (float) $rental->amount_paid);

        $response = $this->actingAs($user)->put(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Full Payer',
            'phone' => '08123456780',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'transfer',
            'payment_kind' => 'down_payment',
            'amount_paid' => '1000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();

        $rental->refresh();
        $this->assertSame('full', $rental->payment_kind);
        $this->assertSame('cash', $rental->payment_method);
        $this->assertEquals(80000.0, (float) $rental->amount_paid);
    }

    public function test_update_keeps_saved_payment_when_down_payment_already_covers_total(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 0);

        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'PAY-DOWN-LOCK',
            'description' => 'Down settled lock',
            'price' => 100000,
            'stock' => 1,
        ]);

        $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Settled',
            'phone' => '08123456780',
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => '30000',
        ]);

        $rental = Rental::query()->latest('id')->first();
        $this->assertNotNull($rental);

        $this->actingAs($user)->put(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Settled',
            'phone' => '08123456780',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'payment_entry' => 'increment',
            'payment_amount_add' => '70000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ])->assertRedirect(route('rentals.index'));

        $rental->refresh();
        // A down payment that gets settled is stored as the final payment.
        $this->assertSame('final_payment', $rental->payment_kind);
        $this->assertEquals(100000.0, (float) $rental->amount_paid);

        $response = $this->actingAs($user)->put(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Settled',
            'phone' => '08123456780',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'transfer',
            'payment_kind' => 'down_payment',
            'payment_entry' => 'increment',
            'payment_amount_add' => '50000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();

        $rental->refresh();
        // Already settled: the saved payment (and its final_payment kind) is kept as is.
        $this->assertSame('final_payment', $rental->payment_kind);
        $this->assertSame('cash', $rental->payment_method);
        $this->assertEquals(100000.0, (float) $rental->amount_paid);
    }

    public function test_update_json_returns_payment_change_when_overpaid(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 0);

        $user = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'PAY-JSON-CHG',
            'description' => 'JSON change',
            'price' => 50000,
            'stock' => 1,
        ]);

        // Staff type in exactly what was received (the client's exact-payment rule), so a
        // customer can only end up overpaid when the bill later drops: here management
        // gives a discount on a rent that is already paid in full.
        $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'A',
            'phone' => '08111111111',
            'notes' => 'X',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '50000',
        ]);

        $rental = Rental::query()->latest('id')->first();
        $this->assertEquals(50000.0, (float) $rental->amount_paid);

        $response = $this->actingAs($manager)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'A',
            'phone' => '08111111111',
            'instagram' => null,
            'notes' => 'X',
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '50000',
            'discount' => '5000',
            'discount_type' => 'fixed',
            'discount_note' => 'Loyal customer',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertEquals(5000.0, (float) $response->json('payment_change'));
    }
}
