<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class DiscountNoteApprovalTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_manager_approve_create_with_discount_requires_note(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $outfit = Outfit::create(['code' => 'APPROVAL-NOTE-01', 'stock' => 1, 'price' => 100000]);

        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $manager->id,
            'source_shift_session_id' => null,
            'status' => 'pending',
            'payload' => [
                'kind' => 'create',
                'rental' => [
                    'customer_name' => 'A',
                    'phone' => '080000000010',
                    'instagram' => null,
                    'notes' => 'X',
                    'payment_method' => 'cash',
                    'amount_paid' => 95000,
                    'payment_kind' => 'full',
                    'discount' => 5000,
                    'discount_type' => 'fixed',
                    'start_date' => now()->addDays(2)->toDateString(),
                    'end_date' => now()->addDays(4)->toDateString(),
                    'status' => 'active',
                    'handled_by_user_id' => $manager->id,
                    'customer_pending' => false,
                ],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [$outfit->id => ''],
                'item_discount_types' => [$outfit->id => 'fixed'],
            ],
        ]);

        $res = $this->actingAs($manager)->postJson(route('approvals.rentals.approve', $approval), []);

        $res->assertStatus(422);
        $res->assertJsonPath('errors.discount_note.0', 'Discount note is required when applying a discount.');
        $this->assertDatabaseCount('rentals', 0);
    }

    public function test_manager_approve_edit_with_discount_requires_note(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $outfit = Outfit::create(['code' => 'APPROVAL-NOTE-02', 'stock' => 1, 'price' => 100000]);

        $existing = Rental::create([
            'customer_name' => 'Existing',
            'phone' => '080000000011',
            'instagram' => null,
            'notes' => 'Y',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'active',
            'discount' => null,
            'discount_type' => 'fixed',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100000,
        ]);
        $existing->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed']);

        // An edit request is filed by someone else (Admin); the requester can never approve it.
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'source_shift_session_id' => null,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $existing->id,
                'rental' => [
                    'customer_name' => $existing->customer_name,
                    'phone' => $existing->phone,
                    'instagram' => null,
                    'notes' => $existing->notes,
                    'payment_method' => 'cash',
                    'amount_paid' => 95000,
                    'payment_kind' => 'full',
                    'discount' => 5000,
                    'discount_type' => 'fixed',
                    'start_date' => $existing->start_date->toDateString(),
                    'end_date' => $existing->end_date->toDateString(),
                    'status' => 'active',
                    'handled_by_user_id' => $manager->id,
                    'customer_pending' => false,
                ],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [$outfit->id => ''],
                'item_discount_types' => [$outfit->id => 'fixed'],
                'takeover_from' => [],
            ],
        ]);

        $res = $this->actingAs($manager)->postJson(route('approvals.rentals.approve', $approval), []);

        $res->assertStatus(422);
        $res->assertJsonPath('errors.discount_note.0', 'Discount note is required when applying a discount.');
        $this->assertDatabaseHas('rentals', ['id' => $existing->id, 'discount' => null]);
    }
}
