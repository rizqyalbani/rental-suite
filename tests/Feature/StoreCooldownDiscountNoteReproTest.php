<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StoreCooldownDiscountNoteReproTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_with_cooldown_clash_and_noteless_item_discount(): void
    {
        Cache::forever('rental_return_buffer_days', 1);
        Cache::forever('pricing_enabled', true);

        $staff = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($staff);

        $outfit = Outfit::create(['code' => 'OUTFIT-STORE-CD', 'stock' => 1, 'price' => 15000]);
        $blocker = Rental::create([
            'customer_name' => 'Blocker',
            'phone' => '081000000041',
            'notes' => 'X',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $blocker->outfits()->attach($outfit->id);

        // Cool-down boundary: start on end+buffer day.
        $res = $this->actingAs($staff)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Clash Customer',
            'phone' => '081000000042',
            'notes' => 'test',
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '13500',
            'item_discounts' => [$outfit->id => '10'],
            'item_discount_types' => [$outfit->id => 'percent'],
            'item_discount_notes' => [$outfit->id => ''],
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, RentalApprovalRequest::count());
    }
}
