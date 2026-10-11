<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class RentalOverdueMergeTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_overdue_fee_persists_without_changing_end_date(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'OUTFIT-OVERDUE-1',
            'description' => 'Overdue Outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        // 3 calendar days = one pricing block (other lengths need an additional charge).
        $start = now()->subDays(3)->toDateString();
        $oldEnd = now()->subDay()->toDateString();

        $rental = Rental::create([
            'customer_name' => 'Customer',
            'phone' => '081200000000',
            'instagram' => null,
            'notes' => 'Address',
            'start_date' => $start,
            'end_date' => $oldEnd,
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => 1000,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $payload = [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Customer',
            'phone' => '081200000000',
            'instagram' => null,
            'notes' => 'Address',
            'start_date' => $start,
            'end_date' => $oldEnd,
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => 1000,
            'discount' => null,
            'discount_type' => 'fixed',
            'overdue_base_end_date' => $oldEnd,
            'overdue_fee_rate' => 100000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 100000,
        ];

        $res = $this->actingAs($user)->from(route('rentals.index'))->put(route('rentals.update', $rental), $payload);
        $res->assertRedirect(route('rentals.index'));
        $res->assertSessionHasNoErrors();

        $rental->refresh();
        $this->assertSame($oldEnd, $rental->end_date?->toDateString());
        $this->assertSame($oldEnd, optional($rental->overdue_base_end_date)->toDateString());
        $this->assertNotNull($rental->overdue_merged_at);
        $this->assertSame(100000.0, (float) $rental->overdue_fee_rate);
        $this->assertSame(1, (int) $rental->overdue_fee_days);
        $this->assertSame(100000.0, (float) $rental->overdue_fee_amount);
        $this->assertNotNull($rental->overdue_fee_applied_at);
        $mergedAt = $rental->overdue_merged_at;

        $payload2 = $payload;
        $payload2['customer_name'] = 'Customer Updated';
        $payload2['overdue_fee_amount'] = 200000;
        $payload2['overdue_fee_days'] = 2;

        $res2 = $this->actingAs($user)->from(route('rentals.index'))->put(route('rentals.update', $rental), $payload2);
        $res2->assertRedirect(route('rentals.index'));
        $res2->assertSessionHasNoErrors();

        $rental->refresh();
        $this->assertSame($oldEnd, $rental->end_date?->toDateString());
        $this->assertSame($oldEnd, optional($rental->overdue_base_end_date)->toDateString());
        $this->assertSame($mergedAt->toDateTimeString(), $rental->overdue_merged_at?->toDateTimeString());
        $this->assertSame(100000.0, (float) $rental->overdue_fee_amount);
    }

    public function test_overdue_fee_not_set_when_base_end_does_not_match_previous_end_date(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'OUTFIT-OVERDUE-2',
            'description' => 'Overdue Outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        // 3 calendar days = one pricing block (other lengths need an additional charge).
        $start = now()->subDays(3)->toDateString();
        $oldEnd = now()->subDay()->toDateString();

        $rental = Rental::create([
            'customer_name' => 'Customer',
            'phone' => '081200000001',
            'instagram' => null,
            'notes' => 'Address',
            'start_date' => $start,
            'end_date' => $oldEnd,
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => 1000,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $payload = [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Customer',
            'phone' => '081200000001',
            'instagram' => null,
            'notes' => 'Address',
            'start_date' => $start,
            'end_date' => $oldEnd,
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => 1000,
            'discount' => null,
            'discount_type' => 'fixed',
            'overdue_base_end_date' => now()->subDays(10)->toDateString(),
            'overdue_fee_rate' => 100000,
            'overdue_fee_days' => 2,
            'overdue_fee_amount' => 200000,
        ];

        $res = $this->actingAs($user)->from(route('rentals.index'))->put(route('rentals.update', $rental), $payload);
        $res->assertRedirect(route('rentals.index'));
        $res->assertSessionHasNoErrors();

        $rental->refresh();
        $this->assertNull($rental->overdue_base_end_date);
        $this->assertNull($rental->overdue_merged_at);
        $this->assertNull($rental->overdue_fee_amount);
        $this->assertNull($rental->overdue_fee_applied_at);
    }
}
