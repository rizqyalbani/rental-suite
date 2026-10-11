<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class RentalFlowTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    /** @return array{payment_method: string, payment_kind: string, amount_paid: string} */
    private function paymentFields(string $amountPaid = '150000'): array
    {
        return [
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => $amountPaid,
        ];
    }

    public function test_authenticated_user_can_create_rental(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-001',
            'description' => 'Black Outfit',
            'price' => 150000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->post(route('rentals.store'), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'John Doe',
            'phone' => '081234567890',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('150000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rentals', [
            'customer_name' => 'John Doe',
            'phone' => '081234567890',
            'status' => 'active',
            'handled_by_user_id' => $user->id,
        ]);

        $rental = Rental::firstOrFail();
        $this->assertTrue($rental->outfits()->whereKey($outfit->id)->exists());
    }

    public function test_create_rental_item_discount_without_note_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-001B',
            'description' => 'Black Outfit',
            'price' => 150000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->post(route('rentals.store'), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'John Doe',
            'phone' => '081234567890',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => '10000'],
            'item_discount_types' => [$outfit->id => 'fixed'],
            'item_discount_notes' => [$outfit->id => ''],
        ], $this->paymentFields('140000')));

        $response->assertSessionHasErrors(['item_discount_notes']);
        $this->assertDatabaseCount('rentals', 0);
    }

    public function test_create_rental_fails_when_item_date_conflicts(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-002',
            'description' => 'Blue Outfit',
            'price' => 200000,
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111111',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->from(route('rentals.index'))->post(route('rentals.store'), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'New Customer',
            'phone' => '082222222222',
            'instagram' => null,
            'notes' => 'Surabaya',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('200000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors(['outfit_ids']);

        $this->assertDatabaseCount('rentals', 1);
    }

    public function test_create_rental_allows_overlap_until_stock_capacity(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-STOCK-2',
            'description' => 'Stock Two Outfit',
            'price' => 200000,
            'stock' => 2,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing One',
            'phone' => '081999000001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->from(route('rentals.index'))->post(route('rentals.store'), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Second Customer',
            'phone' => '081999000002',
            'instagram' => null,
            'notes' => 'Surabaya',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('200000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('rentals', 2);
    }

    public function test_create_rental_blocks_overlap_when_stock_capacity_reached(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-STOCK-CAP',
            'description' => 'Stock Cap Outfit',
            'price' => 200000,
            'stock' => 2,
        ]);

        $first = Rental::create([
            'customer_name' => 'Existing One',
            'phone' => '081999000011',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'active',
        ]);
        $first->outfits()->attach($outfit->id);

        $second = Rental::create([
            'customer_name' => 'Existing Two',
            'phone' => '081999000012',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'active',
        ]);
        $second->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->from(route('rentals.index'))->post(route('rentals.store'), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Third Customer',
            'phone' => '081999000013',
            'instagram' => null,
            'notes' => 'Surabaya',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('200000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors(['outfit_ids']);
        $this->assertDatabaseCount('rentals', 2);
    }

    public function test_create_rental_with_discount_requires_discount_note(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-003',
            'description' => 'Gray Outfit',
            'price' => 175000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->from(route('rentals.index'))->post(route('rentals.store'), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Discount Customer',
            'phone' => '083333333333',
            'instagram' => null,
            'notes' => 'Yogyakarta',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'discount' => 10000,
            'discount_type' => 'fixed',
        ], $this->paymentFields('175000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors(['discount_note']);
        $this->assertDatabaseCount('rentals', 0);
    }

    public function test_user_can_update_rental_and_change_items(): void
    {
        // Management saves directly; an admin edit only queues an approval request.
        $user = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($user);
        $outfitA = Outfit::create(['code' => 'OUTFIT-A', 'description' => 'Outfit A', 'price' => 120000, 'stock' => 1]);
        $outfitB = Outfit::create(['code' => 'OUTFIT-B', 'description' => 'Outfit B', 'price' => 140000, 'stock' => 1]);

        $rental = Rental::create([
            'customer_name' => 'Original Name',
            'phone' => '084444444444',
            'instagram' => null,
            'notes' => 'Semarang',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfitA->id, ['discount' => null, 'discount_type' => 'fixed']);

        $response = $this->actingAs($user)->put(route('rentals.update', $rental), array_merge([
            'outfit_ids' => [$outfitB->id],
            'customer_name' => 'Updated Name',
            'phone' => '085555555555',
            'instagram' => '@updated',
            'notes' => 'Malang',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('140000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'customer_name' => 'Updated Name',
            'phone' => '085555555555',
            'notes' => 'Malang',
        ]);

        $this->assertTrue($rental->fresh()->outfits()->whereKey($outfitB->id)->exists());
        $this->assertFalse($rental->fresh()->outfits()->whereKey($outfitA->id)->exists());
    }

    public function test_update_with_discount_without_note_returns_validation_error(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-ERR',
            'description' => 'Outfit Error',
            'price' => 130000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Original Name',
            'phone' => '086666666666',
            'instagram' => null,
            'notes' => 'Bogor',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $response = $this->actingAs($user)
            ->from(route('rentals.index'))
            ->put(route('rentals.update', $rental), array_merge([
                'outfit_ids' => [$outfit->id],
                'customer_name' => 'Original Name',
                'phone' => '086666666666',
                'instagram' => null,
                'notes' => 'Bogor',
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'discount' => 5000,
                'discount_type' => 'fixed',
            ], $this->paymentFields('130000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors(['discount_note']);
    }

    public function test_update_existing_discounted_rental_succeeds_with_discount_note(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'OUTFIT-DISC-01',
            'description' => 'Discounted outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Discounted',
            'phone' => '080000000001',
            'instagram' => null,
            'notes' => 'Branch B',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'discount' => 5000,
            'discount_type' => 'fixed',
            'discount_note' => 'Loyal customer',
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => 20000,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $response = $this->actingAs($user)->from(route('rentals.index'))->put(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'instagram' => null,
            'notes' => $rental->notes,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'payment_entry' => 'increment',
            // Total due is 95.000 (100.000 - 5.000), 20.000 already paid: type in exactly the
            // 75.000 left (RentalController::rejectIfPaymentExceedsTotalDue).
            'payment_amount_add' => '75000',
            'discount' => 5000,
            'discount_type' => 'fixed',
            'discount_note' => 'Loyal customer',
            'item_discounts' => [$outfit->id => ''],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();
    }


    public function test_update_add_item_discount_does_not_require_order_discount_note(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'OUTFIT-ITEM-DISC',
            'description' => 'Item discount outfit',
            'price' => 110000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Item Disc',
            'phone' => '080000000003',
            'instagram' => null,
            'notes' => 'Maros',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'discount' => null,
            'discount_type' => 'fixed',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 110000,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $response = $this->actingAs($user)
            ->from(route('rentals.index'))
            ->put(route('rentals.update', $rental), [
                'outfit_ids' => [$outfit->id],
                'customer_name' => $rental->customer_name,
                'phone' => $rental->phone,
                'instagram' => null,
                'notes' => $rental->notes,
                'start_date' => $rental->start_date->toDateString(),
                'end_date' => $rental->end_date->toDateString(),
                'payment_method' => 'cash',
                'payment_kind' => 'full',
                'amount_paid' => '110000',
                'discount' => null,
                'discount_type' => 'fixed',
                'item_discounts' => [$outfit->id => '5000'],
                'item_discount_types' => [$outfit->id => 'fixed'],
                'item_discount_notes' => [$outfit->id => 'Regular customer'],
            ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();
    }

    public function test_update_item_discount_without_note_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'OUTFIT-ITEM-DISC-NOTE',
            'description' => 'Item discount note outfit',
            'price' => 110000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Item Disc Note',
            'phone' => '080000000004',
            'instagram' => null,
            'notes' => 'Maros',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'discount' => null,
            'discount_type' => 'fixed',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 110000,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $response = $this->actingAs($user)
            ->from(route('rentals.index'))
            ->put(route('rentals.update', $rental), [
                'outfit_ids' => [$outfit->id],
                'customer_name' => $rental->customer_name,
                'phone' => $rental->phone,
                'instagram' => null,
                'notes' => $rental->notes,
                'start_date' => $rental->start_date->toDateString(),
                'end_date' => $rental->end_date->toDateString(),
                'payment_method' => 'cash',
                'payment_kind' => 'full',
                'amount_paid' => '110000',
                'discount' => null,
                'discount_type' => 'fixed',
                'item_discounts' => [$outfit->id => '5000'],
                'item_discount_types' => [$outfit->id => 'fixed'],
                'item_discount_notes' => [$outfit->id => '   '],
            ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors(['item_discount_notes']);
    }

    public function test_returned_rental_cannot_be_reactivated_when_item_conflicts(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-REACT',
            'description' => 'Outfit React',
            'price' => 210000,
            'stock' => 1,
        ]);

        $returnedRental = Rental::create([
            'customer_name' => 'Returned Rental',
            'phone' => '087000000000',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'returned',
        ]);
        $returnedRental->outfits()->attach($outfit->id);

        $activeRental = Rental::create([
            'customer_name' => 'Active Rental',
            'phone' => '087111111111',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
        ]);
        $activeRental->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)
            ->from(route('rentals.index'))
            ->post(route('rentals.status', $returnedRental), [
                'status' => 'active',
            ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors(['status']);
        $this->assertSame('returned', $returnedRental->fresh()->status);
    }

    public function test_return_and_cancelled_status_clear_pickup_timestamp(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-PICKUP',
            'description' => 'Outfit Pickup',
            'price' => 190000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Pickup Rental',
            'phone' => '087222222222',
            'instagram' => null,
            'notes' => 'Surabaya',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'picked_up_at' => now(),
            // Returning needs a pickup and full payment (RentalController::returnBlockReason).
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 190000,
        ]);
        $rental->outfits()->attach($outfit->id);

        $this->actingAs($user)->post(route('rentals.status', $rental), [
            'status' => 'returned',
        ])->assertRedirect(route('rentals.index'));

        $this->assertSame('returned', $rental->fresh()->status);
        $this->assertNull($rental->fresh()->picked_up_at);

        $rental->update([
            'status' => 'active',
            'picked_up_at' => now(),
        ]);

        $this->actingAs($user)->post(route('rentals.status', $rental), [
            'status' => 'cancelled',
        ])->assertRedirect(route('rentals.index'));

        $this->assertNull($rental->fresh()->picked_up_at);
    }

    public function test_update_rental_sets_latest_handler_user(): void
    {
        $creator = User::factory()->create(['role' => 'admin']);
        // A direct edit (management) takes over as handler; an admin edit only queues an approval.
        $editor = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($editor);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-HANDLER',
            'description' => 'Outfit Handler',
            'price' => 130000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Handler Name',
            'phone' => '088100000001',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'handled_by_user_id' => $creator->id,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $this->actingAs($editor)->put(route('rentals.update', $rental), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Handler Name Updated',
            'phone' => '088100000001',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('130000')))->assertRedirect(route('rentals.index'));

        $this->assertSame($editor->id, (int) $rental->fresh()->handled_by_user_id);
    }

    public function test_cannot_create_rental_without_open_shift(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-NOSHIFT',
            'description' => 'No shift',
            'price' => 100000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->from(route('rentals.index'))->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'No Shift Customer',
            'phone' => '081200000000',
            'instagram' => null,
            'notes' => 'Test',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors('shift');
        $this->assertDatabaseCount('rentals', 0);
    }

    public function test_cannot_update_rental_without_open_shift(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $outfit = Outfit::create(['code' => 'OUTFIT-NOSHIFT2', 'description' => 'X', 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'A',
            'phone' => '081200000001',
            'instagram' => null,
            'notes' => 'Addr',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->from(route('rentals.index'))->put(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'B',
            'phone' => '081200000001',
            'instagram' => null,
            'notes' => 'Addr',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ]);

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasErrors('shift');
        $this->assertSame('A', $rental->fresh()->customer_name);
    }

    public function test_admin_boundary_cooldown_conflict_creates_manager_approval_request(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-BOUNDARY',
            'description' => 'Boundary Outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $response = $this->actingAs($admin)
            ->from(route('rentals.index'))
            ->post(route('rentals.store'), array_merge([
                'outfit_ids' => [$outfit->id],
                'customer_name' => 'Needs Approval',
                'phone' => '082222220000',
                'instagram' => null,
                'notes' => 'Surabaya',
                'start_date' => now()->addDays(4)->toDateString(), // exact boundary for buffer=1
                'end_date' => now()->addDays(5)->toDateString(),
                'discount' => null,
                'discount_type' => 'fixed',
            ], $this->paymentFields('100000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('approval_request_sent', true);
        $this->assertDatabaseCount('rental_approval_requests', 1);
        $this->assertDatabaseCount('rentals', 1);
    }

    public function test_duplicate_boundary_approval_submission_does_not_create_second_request(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-DUP-APR',
            'description' => 'Dup approval outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $payload = array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Needs Approval',
            'phone' => '082222220000',
            'instagram' => null,
            'notes' => 'Surabaya',
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('100000'));

        $this->actingAs($admin)
            ->from(route('rentals.index'))
            ->post(route('rentals.store'), $payload)
            ->assertRedirect(route('rentals.index'))
            ->assertSessionHas('approval_request_sent', true);

        $this->assertDatabaseCount('rental_approval_requests', 1);

        $this->actingAs($admin)
            ->from(route('rentals.index'))
            ->post(route('rentals.store'), $payload)
            ->assertRedirect(route('rentals.index'))
            ->assertSessionHas('approval_duplicate', true);

        $this->assertDatabaseCount('rental_approval_requests', 1);
    }

    public function test_management_can_approve_boundary_cooldown_request(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($admin);

        $outfit = Outfit::create([
            'code' => 'OUTFIT-APPROVE',
            'description' => 'Approve Outfit',
            'price' => 150000,
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing',
            'phone' => '080000000001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($admin)
            ->from(route('rentals.index'))
            ->post(route('rentals.store'), array_merge([
                'outfit_ids' => [$outfit->id],
                'customer_name' => 'Boundary Candidate',
                'phone' => '080000000002',
                'instagram' => null,
                'notes' => 'Jakarta',
                'start_date' => now()->addDays(4)->toDateString(),
                'end_date' => now()->addDays(6)->toDateString(),
                'discount' => null,
                'discount_type' => 'fixed',
            ], $this->paymentFields('150000')))
            ->assertRedirect(route('rentals.index'))
            ->assertSessionHas('approval_request_sent', true);

        $approval = RentalApprovalRequest::firstOrFail();

        $this->actingAs($manager)
            ->post(route('approvals.rentals.approve', $approval))
            ->assertStatus(302);

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertDatabaseCount('rentals', 2);
    }

    public function test_outfits_available_json_marks_cooldown_boundary_as_selectable_with_needs_manager_approval(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $user = User::factory()->create(['role' => 'admin']);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-BOUNDARY-API',
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $start = now()->addDays(4)->toDateString();
        $end = now()->addDays(5)->toDateString();

        $response = $this->actingAs($user)->getJson(route('outfits.available', ['start' => $start, 'end' => $end]));

        $response->assertOk();
        $row = collect($response->json('outfits'))->firstWhere('id', $outfit->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['available']);
        $this->assertTrue($row['selectable']);
        $this->assertTrue($row['needs_manager_approval']);
    }

    public function test_outfits_available_excluded_rental_saved_dates_and_items_skip_cooldown_approval_flag(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $user = User::factory()->create(['role' => 'admin']);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-EDIT-SAVED-STATE',
            'stock' => 1,
        ]);

        $other = Rental::create([
            'customer_name' => 'Other Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $other->outfits()->attach($outfit->id);

        $mine = Rental::create([
            'customer_name' => 'My Rent',
            'phone' => '082222222222',
            'instagram' => null,
            'notes' => 'Surabaya',
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'status' => 'active',
        ]);
        $mine->outfits()->attach($outfit->id);

        $start = $mine->start_date->toDateString();
        $end = $mine->end_date->toDateString();

        $response = $this->actingAs($user)->getJson(route('outfits.available', [
            'start' => $start,
            'end' => $end,
            'exclude' => $mine->id,
        ]));

        $response->assertOk();
        $row = collect($response->json('outfits'))->firstWhere('id', $outfit->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['available']);
        $this->assertFalse($row['needs_manager_approval']);
    }

    public function test_update_after_sequential_cooldown_booking_does_not_report_false_outfit_conflict(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        // Management saves directly; an admin edit would only queue an approval request.
        $user = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'JAB2',
            'description' => 'Boundary edit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $firstEnd = now()->addDays(3)->toDateString();
        $firstStart = now()->addDays(1)->toDateString();

        $first = Rental::create([
            'customer_name' => 'First Customer',
            'phone' => '081111111001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => $firstStart,
            'end_date' => $firstEnd,
            'status' => 'active',
        ]);
        $first->outfits()->attach($outfit->id);

        $secondStart = Carbon::parse($firstEnd)->addDay()->toDateString();
        $secondEnd = Carbon::parse($secondStart)->addDays(2)->toDateString();

        $second = Rental::create([
            'customer_name' => 'Second Customer',
            'phone' => '081111111002',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => $secondStart,
            'end_date' => $secondEnd,
            'status' => 'active',
        ]);
        $second->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->put(route('rentals.update', $second), array_merge([
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Second Customer Updated',
            'phone' => '081111111002',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => $secondStart,
            'end_date' => $secondEnd,
            'discount' => null,
            'discount_type' => 'fixed',
        ], $this->paymentFields('100000')));

        $response->assertRedirect(route('rentals.index'));
        $response->assertSessionHasNoErrors();

        $this->assertSame('Second Customer Updated', $second->fresh()->customer_name);
    }

    public function test_rentals_search_total_price_includes_tax_percent_setting(): void
    {
        Cache::forever('pricing_enabled', true);
        Cache::forever('pricing_tax_percent', 10);

        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-TAX-01',
            'description' => 'Taxable outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Tax Customer',
            'phone' => '081222333444',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
            'discount' => null,
            'discount_type' => 'fixed',
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        $response = $this->actingAs($user)->getJson(route('rentals.search', ['id' => $rental->id, 'per_page' => 10]));
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $rental->id);
        $this->assertNotNull($row);
        $this->assertSame(110000.0, (float) ($row['total_price'] ?? 0));
    }

    public function test_rentals_search_overdue_filter_returns_only_active_past_end_date(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-OVERDUE-1',
            'description' => 'Overdue Outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $overdue = Rental::create([
            'customer_name' => 'Overdue Customer',
            'phone' => '080000000001',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'status' => 'active',
        ]);
        $overdue->outfits()->attach($outfit->id);

        $activeFuture = Rental::create([
            'customer_name' => 'Future Active',
            'phone' => '080000000002',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
        ]);
        $activeFuture->outfits()->attach($outfit->id);

        $returned = Rental::create([
            'customer_name' => 'Already Returned',
            'phone' => '080000000003',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->subDays(6)->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
            'status' => 'returned',
        ]);
        $returned->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->getJson('/rentals/search?per_page=10&overdue=1');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($overdue->id, $ids);
        $this->assertNotContains($activeFuture->id, $ids);
        $this->assertNotContains($returned->id, $ids);
    }

    public function test_rentals_search_pickup_waiting_and_done_filters(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-PICKUP-1',
            'description' => 'Pickup filter outfit',
            'price' => 100000,
            'stock' => 3,
        ]);

        $waiting = Rental::create([
            'customer_name' => 'Waiting Pickup',
            'phone' => '081000000001',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'picked_up_at' => null,
        ]);
        $waiting->outfits()->attach($outfit->id);

        $done = Rental::create([
            'customer_name' => 'Picked Up Already',
            'phone' => '081000000002',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'picked_up_at' => now()->subHour(),
        ]);
        $done->outfits()->attach($outfit->id);

        $returned = Rental::create([
            'customer_name' => 'Returned No Pickup Field',
            'phone' => '081000000003',
            'instagram' => null,
            'notes' => 'Branch A',
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'status' => 'returned',
            'picked_up_at' => null,
        ]);
        $returned->outfits()->attach($outfit->id);

        $w = $this->actingAs($user)->getJson('/rentals/search?per_page=10&pickup_waiting=1');
        $w->assertOk();
        $waitingIds = collect($w->json('data'))->pluck('id')->all();
        $this->assertContains($waiting->id, $waitingIds);
        $this->assertNotContains($done->id, $waitingIds);
        $this->assertNotContains($returned->id, $waitingIds);

        $d = $this->actingAs($user)->getJson('/rentals/search?per_page=10&pickup_done=1');
        $d->assertOk();
        $doneIds = collect($d->json('data'))->pluck('id')->all();
        $this->assertContains($done->id, $doneIds);
        $this->assertNotContains($waiting->id, $doneIds);
    }

    public function test_bulk_import_matches_outfit_when_pasted_code_has_spaces_but_db_code_does_not(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $existing = Outfit::create([
            'code' => 'BR03',
            'description' => 'Bulk match test',
            'stock' => 1,
        ]);

        $start = now()->addDay()->toDateString();
        $end = now()->addDays(3)->toDateString();
        $line = "Bulk Customer\t081234567890\t{$start}\t{$end}\tBR 03";

        $beforeCount = Outfit::count();

        $this->actingAs($user)
            ->post(route('rentals.bulk-import'), ['data' => $line])
            ->assertRedirect(route('rentals.index'));

        $this->assertSame($beforeCount, Outfit::count());

        $rental = Rental::where('customer_name', 'Bulk Customer')->first();
        $this->assertNotNull($rental);
        $this->assertTrue($rental->outfits()->whereKey($existing->id)->exists());
    }

    public function test_bulk_import_matches_outfit_when_db_has_spaces_but_pasted_code_does_not(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $existing = Outfit::create([
            'code' => 'BR 03',
            'description' => 'Bulk match test 2',
            'stock' => 1,
        ]);

        $start = now()->addDay()->toDateString();
        $end = now()->addDays(3)->toDateString();
        $line = "Bulk Customer Two\t081234567891\t{$start}\t{$end}\tBR03";

        $beforeCount = Outfit::count();

        $this->actingAs($user)
            ->post(route('rentals.bulk-import'), ['data' => $line])
            ->assertRedirect(route('rentals.index'));

        $this->assertSame($beforeCount, Outfit::count());

        $rental = Rental::where('customer_name', 'Bulk Customer Two')->first();
        $this->assertNotNull($rental);
        $this->assertTrue($rental->outfits()->whereKey($existing->id)->exists());
    }

    public function test_rentals_search_includes_outfit_ids_as_integers_for_edit_modal(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);
        $outfit = Outfit::create([
            'code' => 'EDIT-MODAL-OUTFIT',
            'description' => 'Test',
            'price' => 100000,
            'stock' => 1,
        ]);
        $rental = Rental::create([
            'customer_name' => 'Edit Modal Customer',
            'phone' => '081234567890',
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'handled_by_user_id' => $user->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->getJson('/rentals/search?per_page=10&page=1');
        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertIsArray($row);
        $this->assertArrayHasKey('outfit_ids', $row);
        $this->assertSame([(int) $outfit->id], $row['outfit_ids']);
        foreach ($row['outfit_ids'] as $id) {
            $this->assertIsInt($id);
        }
    }
}
