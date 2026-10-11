<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OverdueChargePaymentTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_overdue_payment_requires_existing_overdue_fee(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create(['code' => 'OVP-01', 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'A',
            'phone' => '08111111111',
            'notes' => 'X',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100000,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $res = $this->actingAs($user)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '100000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_payment_entry' => 'increment',
            'overdue_payment_amount_add' => '10000',
            'overdue_payment_method' => 'cash',
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('errors.overdue_payment.0', 'No overdue charge exists for this rent.');
    }

    public function test_overdue_payment_requires_full_balance_or_higher_with_change(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create(['code' => 'OVP-02', 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'B',
            'phone' => '08111111112',
            'notes' => 'Y',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100000,
            'overdue_fee_rate' => 100000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 100000,
            'overdue_fee_applied_at' => now(),
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $base = [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '100000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_payment_method' => 'cash',
        ];

        $res = $this->actingAs($user)->putJson(route('rentals.update', $rental), array_merge($base, [
            'overdue_payment_entry' => 'increment',
            'overdue_payment_amount_add' => '110000',
        ]));
        $res->assertOk();
        $res->assertJsonPath('overdue_payment_settled', true);
        $res->assertJsonPath('overdue_payment_change', 10000);

        $rental->refresh();
        $this->assertEquals(100000.0, (float) $rental->overdue_amount_paid);
        $this->assertEquals(10000.0, (float) $rental->overdue_change_amount);
        $this->assertSame('full', $rental->overdue_payment_kind);
    }

    public function test_overdue_payment_rejects_when_already_settled(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create(['code' => 'OVP-03', 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'C',
            'phone' => '08111111113',
            'notes' => 'Z',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100000,
            'overdue_fee_rate' => 100000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 100000,
            'overdue_fee_applied_at' => now(),
            'overdue_amount_paid' => 100000,
            'overdue_payment_kind' => 'full',
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $res = $this->actingAs($user)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '100000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_payment_method' => 'cash',
            'overdue_payment_entry' => 'increment',
            'overdue_payment_amount_add' => '1000',
        ]);

        $res->assertStatus(409);
        $res->assertJsonPath('errors.overdue_payment.0', 'Overdue charge is already settled.');
    }

    public function test_overdue_payment_accepts_additional_charge_after_previous_overdue_was_settled(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create(['code' => 'OVP-04', 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'D',
            'phone' => '08111111114',
            'notes' => 'W',
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100000,
            'overdue_base_end_date' => now()->subDays(2)->toDateString(),
            'overdue_charge_through_date' => now()->subDay()->toDateString(),
            'overdue_fee_rate' => 100000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 100000,
            'overdue_fee_applied_at' => now()->subDay(),
            'overdue_amount_paid' => 100000,
            'overdue_payment_kind' => 'full',
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $res = $this->actingAs($user)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '100000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_base_end_date' => $rental->end_date->toDateString(),
            'overdue_charge_through_date' => now()->toDateString(),
            'overdue_fee_rate' => '100000',
            'overdue_fee_days' => '2',
            'overdue_fee_amount' => '200000',
            'overdue_payment_method' => 'cash',
            'overdue_payment_entry' => 'increment',
            'overdue_payment_amount_add' => '100000',
        ]);

        $res->assertOk();
        $res->assertJsonPath('overdue_payment_settled', true);

        $rental->refresh();
        $this->assertSame(now()->toDateString(), $rental->overdue_charge_through_date?->toDateString());
        $this->assertEquals(200000.0, (float) $rental->overdue_fee_amount);
        $this->assertEquals(200000.0, (float) $rental->overdue_amount_paid);
        $this->assertSame('full', $rental->overdue_payment_kind);
    }

    public function test_overdue_payment_allowed_when_saved_rent_payment_is_below_recomputed_total(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create(['code' => 'OVP-06', 'price' => 100000, 'stock' => 1]);
        // Saved as settled full payment, but the recomputed total is now higher
        // (e.g. price/tax changed after payment was accepted).
        $rental = Rental::create([
            'customer_name' => 'F',
            'phone' => '08111111116',
            'notes' => 'U',
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 99000,
            'overdue_fee_rate' => 50000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 50000,
            'overdue_fee_applied_at' => now(),
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $res = $this->actingAs($user)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            // Saved rent payment resent untouched — 1.000 below the computed due.
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '99000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_payment_method' => 'cash',
            'overdue_payment_entry' => 'increment',
            'overdue_payment_amount_add' => '50000',
        ]);

        $res->assertOk();
        $res->assertJsonPath('overdue_payment_settled', true);

        $rental->refresh();
        $this->assertEquals(50000.0, (float) $rental->overdue_amount_paid);
        // Rent payment stays exactly as saved.
        $this->assertEquals(99000.0, (float) $rental->amount_paid);
    }

    public function test_update_still_rejects_changed_full_payment_below_total_due(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create(['code' => 'OVP-07', 'price' => 100000, 'stock' => 1]);
        // Unsettled down payment — the only state where the rent payment is editable.
        $rental = Rental::create([
            'customer_name' => 'G',
            'phone' => '08111111117',
            'notes' => 'T',
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => 50000,
            'overdue_fee_rate' => 50000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 50000,
            'overdue_fee_applied_at' => now(),
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $res = $this->actingAs($user)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            // Rent payment CHANGED (final payment below total) — floor must still apply.
            'payment_method' => 'cash',
            'payment_kind' => 'final_payment',
            'amount_paid' => '60000',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_payment_method' => 'cash',
            'overdue_payment_entry' => 'increment',
            'overdue_payment_amount_add' => '50000',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('amount paid must be at least the total due', (string) $res->json('errors.amount_paid.0'));
    }

    public function test_overdue_payment_allowed_on_rent_without_rent_payment(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create(['code' => 'OVP-05', 'price' => 100000, 'stock' => 1]);
        // Placeholder-style rent (e.g. created via approval): no rent payment yet.
        $rental = Rental::create([
            'customer_name' => 'E',
            'phone' => '08111111115',
            'notes' => 'V',
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active',
            'payment_method' => null,
            'payment_kind' => null,
            'amount_paid' => null,
            'overdue_fee_rate' => 50000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 50000,
            'overdue_fee_applied_at' => now(),
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $res = $this->actingAs($user)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            // Rent payment fields untouched — mirrors the Pay overdue button submit.
            'payment_method' => '',
            'payment_kind' => 'full',
            'amount_paid' => '0',
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_payment_method' => 'cash',
            'overdue_payment_entry' => 'increment',
            'overdue_payment_amount_add' => '50000',
        ]);

        $res->assertOk();
        $res->assertJsonPath('overdue_payment_settled', true);

        $rental->refresh();
        $this->assertEquals(50000.0, (float) $rental->overdue_amount_paid);
        $this->assertSame('full', $rental->overdue_payment_kind);
        // Rent payment must remain untouched.
        $this->assertEqualsWithDelta(0.0, (float) ($rental->amount_paid ?? 0), 0.001);
        $this->assertTrue($rental->payment_method === null || $rental->payment_method === '');
    }
    /** A rent with no rent payment yet and an applied overdue fee of 50.000 (e.g. a placeholder booking created via approval). */
    private function placeholderRentWithFee(): array
    {
        $outfit = Outfit::create(['code' => 'OVP-'.uniqid(), 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'E', 'phone' => '08111111115', 'notes' => 'V',
            'start_date' => now()->subDays(3)->toDateString(), 'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active', 'payment_method' => null, 'payment_kind' => null, 'amount_paid' => null,
            'overdue_fee_rate' => 50000, 'overdue_fee_days' => 1, 'overdue_fee_amount' => 50000, 'overdue_fee_applied_at' => now(),
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        return [$rental, $outfit, [
            'outfit_ids' => [$outfit->id], 'customer_name' => $rental->customer_name, 'phone' => $rental->phone, 'instagram' => null,
            'notes' => $rental->notes, 'start_date' => $rental->start_date->toDateString(), 'end_date' => $rental->end_date->toDateString(),
            'payment_method' => '', 'payment_kind' => 'full', 'amount_paid' => '0', 'discount' => null, 'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => ''], 'item_discount_types' => [$outfit->id => 'fixed'],
            'overdue_payment_method' => 'cash', 'overdue_payment_entry' => 'increment', 'overdue_payment_amount_add' => '50000',
        ]];
    }

    // F1: the same Pay overdue payload from management is applied and leaves the rent payment fields as they were.
    public function test_management_overdue_payment_without_rent_payment_keeps_rent_payment_fields_empty(): void
    {
        $user = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($user);
        [$rental, , $payload] = $this->placeholderRentWithFee();

        $this->actingAs($user)->putJson(route('rentals.update', $rental), $payload)->assertOk()->assertJsonPath('overdue_payment_settled', true);

        $rental->refresh();
        $this->assertEquals(50000.0, (float) $rental->overdue_amount_paid);
        $this->assertNull($rental->payment_kind);
        $this->assertEqualsWithDelta(0.0, (float) ($rental->amount_paid ?? 0), 0.001);
    }

    // F1 guard: an admin who also changes a regular field (notes) in the same submit still goes through approval, nothing is applied.
    public function test_admin_overdue_payment_with_another_change_still_needs_approval(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        [$rental, , $payload] = $this->placeholderRentWithFee();
        $payload['notes'] = 'changed note';

        $this->actingAs($user)->putJson(route('rentals.update', $rental), $payload)
            ->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');

        $rental->refresh();
        $this->assertEquals(0.0, (float) ($rental->overdue_amount_paid ?? 0));
        $this->assertSame('V', $rental->notes);
    }
}
