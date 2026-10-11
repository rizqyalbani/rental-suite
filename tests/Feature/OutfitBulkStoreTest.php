<?php

namespace Tests\Feature;

use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitBulkStoreTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_bulk_store_assigns_current_store_id_to_imported_codes(): void
    {
        $admin = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($admin)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->post(route('outfits.bulk'), [
                'codes' => "BR 90\nBR 91",
            ]);

        $response->assertRedirect(route('outfits.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('outfits', [
            'store_id' => 1,
            'code' => 'BR 90',
        ]);
        $this->assertDatabaseHas('outfits', [
            'store_id' => 1,
            'code' => 'BR 91',
        ]);

        $this->assertSame(2, Outfit::query()->whereIn('code', ['BR 90', 'BR 91'])->count());
    }
}
