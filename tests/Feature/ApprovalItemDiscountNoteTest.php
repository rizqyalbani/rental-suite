<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class ApprovalItemDiscountNoteTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_approving_create_request_keeps_item_discount_notes(): void
    {
        Cache::forever('rental_return_buffer_days', 1);
        Cache::forever('pricing_enabled', true);

        $staff = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($staff);

        $outfit = Outfit::create(['code' => 'OUTFIT-NOTE-PVT', 'stock' => 1, 'price' => 15000]);
        $blocker = Rental::create([
            'customer_name' => 'Blocker',
            'phone' => '081000000031',
            'notes' => 'X',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $blocker->outfits()->attach($outfit->id);

        $queue = $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'customer_name' => 'Discounted Customer',
            'phone' => '081000000032',
            'item_discounts' => [$outfit->id => '10'],
            'item_discount_types' => [$outfit->id => 'percent'],
            'item_discount_notes' => [$outfit->id => 'loyal customer'],
        ]);
        $queue->assertOk();

        $approval = RentalApprovalRequest::firstOrFail();
        $this->assertSame('loyal customer', $approval->payload['item_discount_notes'][$outfit->id] ?? null);

        $approve = $this->actingAs($manager)->postJson(route('approvals.rentals.approve', $approval));
        $approve->assertOk();

        $rental = Rental::whereKey($approve->json('rental_id'))->firstOrFail();
        $pivot = $rental->outfits()->first()->pivot;
        $this->assertEquals(10.0, (float) $pivot->discount);
        $this->assertSame('percent', $pivot->discount_type);
        $this->assertSame('loyal customer', $pivot->discount_note);
    }
}
