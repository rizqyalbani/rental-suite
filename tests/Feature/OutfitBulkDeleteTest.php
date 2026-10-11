<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitBulkDeleteTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_bulk_delete_removes_selected_items_without_active_rentals(): void
    {
        $admin = User::factory()->create(['role' => 'management']);
        $a = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BDEL-A',
            'stock' => 1,
        ]);
        $b = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BDEL-B',
            'stock' => 1,
        ]);

        $this->actingAs($admin)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->post(route('outfits.bulk-delete'), [
                'ids' => [$a->id, $b->id],
            ])
            ->assertRedirect(route('outfits.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('outfits', ['id' => $a->id]);
        $this->assertDatabaseMissing('outfits', ['id' => $b->id]);
    }

    public function test_bulk_delete_skips_item_with_active_rental_and_deletes_the_rest(): void
    {
        $admin = User::factory()->create(['role' => 'management']);
        $free = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BDEL-FREE',
            'stock' => 1,
        ]);
        $blocked = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BDEL-BLOCK',
            'stock' => 1,
        ]);
        $rental = Rental::create([
            'customer_name' => 'A',
            'phone' => '081',
            'notes' => 'X',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($blocked->id);

        $this->actingAs($admin)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->post(route('outfits.bulk-delete'), [
                'ids' => [$free->id, $blocked->id],
            ])
            ->assertRedirect(route('outfits.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('outfits', ['id' => $free->id]);
        $this->assertDatabaseHas('outfits', ['id' => $blocked->id]);
    }
}
