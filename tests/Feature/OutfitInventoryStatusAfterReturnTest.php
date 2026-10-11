<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitInventoryStatusAfterReturnTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_outfits_index_shows_available_after_rental_is_returned(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'RET-STATUS-1',
            'description' => 'Test',
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Tester',
            'phone' => '081234567890',
            'instagram' => null,
            'notes' => 'Here',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'picked_up_at' => now(),
        ]);
        $rental->outfits()->attach($outfit->id);

        $this->assertSame(1, $outfit->fresh()->activeRentals()->count());

        $activePage = $this->actingAs($user)->get(route('outfits.index'));
        $activePage->assertOk();
        $this->assertMatchesRegularExpression(
            '#<td[^>]*class="[^"]*\boutfit-status\b[^"]*"[^>]*>.*?active#is',
            $activePage->getContent()
        );

        $this->actingAs($user)->post(route('rentals.status', $rental), [
            'status' => 'returned',
        ])->assertRedirect(route('rentals.index'));

        $this->assertSame('returned', $rental->fresh()->status);
        $this->assertSame(0, $outfit->fresh()->activeRentals()->count());

        $freePage = $this->actingAs($user)->get(route('outfits.index'));
        $freePage->assertOk();
        $this->assertMatchesRegularExpression(
            '#<td[^>]*class="[^"]*\boutfit-status\b[^"]*"[^>]*>.*?Available</span>#is',
            $freePage->getContent()
        );
    }

    public function test_outfits_available_json_marks_item_free_in_date_range_after_return(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($user);

        $outfit = Outfit::create([
            'code' => 'RET-STATUS-2',
            'description' => 'Test',
            'stock' => 1,
        ]);

        $start = now()->addDay()->toDateString();
        $end = now()->addDays(3)->toDateString();

        $rental = Rental::create([
            'customer_name' => 'Tester',
            'phone' => '081234567890',
            'instagram' => null,
            'notes' => 'Here',
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'picked_up_at' => now(),
        ]);
        $rental->outfits()->attach($outfit->id);

        $this->actingAs($user)->post(route('rentals.status', $rental), [
            'status' => 'returned',
        ])->assertRedirect(route('rentals.index'));

        $json = $this->actingAs($user)->getJson(route('outfits.available', [
            'start' => $start,
            'end' => $end,
        ]));

        $json->assertOk();
        $row = collect($json->json('outfits'))->firstWhere('id', $outfit->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['available'], 'Item should be available in range after rental is returned.');
    }
}
