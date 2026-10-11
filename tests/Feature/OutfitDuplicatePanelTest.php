<?php

namespace Tests\Feature;

use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitDuplicatePanelTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_outfits_index_shows_duplicate_panel_when_codes_normalize_same(): void
    {
        foreach (['BR8', 'BR08', 'BR 08', 'BR 8'] as $code) {
            Outfit::withoutGlobalScopes()->create([
                'store_id' => 1,
                'code' => $code,
                'description' => '',
                'stock' => 1,
            ]);
        }

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->get(route('outfits.index', ['insights' => 1]));

        $response->assertOk()
            ->assertSeeText('Possible duplicate codes', false)
            ->assertSeeText('Highlight in table', false)
            ->assertSeeText('Edit group', false);
    }

    public function test_outfits_index_hides_duplicate_panel_when_no_duplicate_groups(): void
    {
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'UNIQUE-ONLY',
            'description' => '',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->get(route('outfits.index', ['insights' => 1]));

        $response->assertOk()
            ->assertDontSeeText('Possible duplicate codes', false);
    }
}
