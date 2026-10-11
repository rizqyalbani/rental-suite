<?php

namespace Tests\Feature;

use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitSkippedNumberPanelTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_outfits_index_shows_missing_02_between_br_01_and_br_03(): void
    {
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BR 01',
            'description' => '',
            'stock' => 1,
        ]);
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BR 03',
            'description' => '',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->get(route('outfits.index', ['insights' => 1]));

        $response->assertOk()
            ->assertSeeText('Skipped numbers in sequences', false)
            ->assertSeeText('Missing: 02', false);
    }

    public function test_outfits_index_shows_skipped_numbers_when_gap_exists(): void
    {
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BR08',
            'description' => '',
            'stock' => 1,
        ]);
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BR11',
            'description' => '',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->get(route('outfits.index', ['insights' => 1]));

        $response->assertOk()
            ->assertSeeText('Skipped numbers in sequences', false)
            ->assertSeeText('Missing:', false)
            ->assertSeeText('09, 10', false);
    }

    public function test_outfits_index_hides_skipped_panel_when_sequence_complete(): void
    {
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BR1',
            'description' => '',
            'stock' => 1,
        ]);
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'BR2',
            'description' => '',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->get(route('outfits.index', ['insights' => 1]));

        $response->assertOk()
            ->assertDontSeeText('Skipped numbers in sequences', false);
    }
}
