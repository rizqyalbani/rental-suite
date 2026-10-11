<?php

namespace Tests\Feature;

use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitBulkNewCodeTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_bulk_new_code_json_updates_alternative_codes(): void
    {
        $user = User::factory()->create(['role' => 'management']);
        $a = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'OLD-A',
            'description' => '',
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->postJson(route('outfits.bulk-new-code'), [
                'pairs' => "OLD-A\tALT-A",
            ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('ALT-A', $a->fresh()->new_code);
    }

    public function test_bulk_new_code_json_422_when_duplicate_new_code_in_store(): void
    {
        $user = User::factory()->create(['role' => 'management']);
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'ITEM-A',
            'new_code' => 'TAKEN',
            'stock' => 1,
        ]);
        $b = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'ITEM-B',
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->postJson(route('outfits.bulk-new-code'), [
                'pairs' => "ITEM-B\tTAKEN",
            ]);

        $response->assertStatus(422)->assertJsonStructure(['errors']);
        $this->assertNull($b->fresh()->new_code);
    }
}
