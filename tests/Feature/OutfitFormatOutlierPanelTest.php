<?php

namespace Tests\Feature;

use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitFormatOutlierPanelTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_outfits_index_shows_format_outlier_panel_when_spacing_differs(): void
    {
        foreach (['KBL 1', 'KBL 3', 'KBL 4'] as $code) {
            Outfit::withoutGlobalScopes()->create([
                'store_id' => 1,
                'code' => $code,
                'description' => '',
                'stock' => 1,
            ]);
        }
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'KBL2',
            'description' => '',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->get(route('outfits.index', ['insights' => 1]));

        $response->assertOk()
            ->assertSeeText('Different spelling / spacing', false)
            ->assertSeeText('KBL2', false);
    }

    public function test_outfits_index_hides_format_panel_when_only_one_pattern(): void
    {
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'KBL 1',
            'description' => '',
            'stock' => 1,
        ]);
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'KBL 2',
            'description' => '',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->get(route('outfits.index', ['insights' => 1]));

        $response->assertOk()
            ->assertDontSeeText('Different spelling / spacing', false);
    }
}
