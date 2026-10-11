<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitBulkFieldUniqueCheckTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_unique_check_returns_available_true_when_primary_code_unchanged(): void
    {
        $outfit = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'KEEP-CODE',
            'description' => 'x',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->getJson(route('outfits.unique-check', [
                'outfit_id' => $outfit->id,
                'field' => 'code',
                'value' => 'KEEP-CODE',
            ]));

        $response->assertOk();
        $response->assertJson(['available' => true]);
    }

    public function test_unique_check_returns_available_false_when_primary_code_belongs_to_another_item(): void
    {
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'OTHER-CODE',
            'description' => 'other',
            'stock' => 1,
        ]);

        $editor = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'MY-CODE',
            'description' => 'mine',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->getJson(route('outfits.unique-check', [
                'outfit_id' => $editor->id,
                'field' => 'code',
                'value' => 'OTHER-CODE',
            ]));

        $response->assertOk();
        $response->assertJson([
            'available' => false,
        ]);
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_unique_check_returns_available_false_when_new_code_belongs_to_another_item(): void
    {
        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'A',
            'new_code' => 'ALT-TAKEN',
            'description' => 'a',
            'stock' => 1,
        ]);

        $editor = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'B',
            'new_code' => null,
            'description' => 'b',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->getJson(route('outfits.unique-check', [
                'outfit_id' => $editor->id,
                'field' => 'new_code',
                'value' => 'ALT-TAKEN',
            ]));

        $response->assertOk();
        $response->assertJson(['available' => false]);
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_unique_check_returns_404_for_unknown_outfit_id(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->getJson(route('outfits.unique-check', [
                'outfit_id' => 999999,
                'field' => 'code',
                'value' => 'X',
            ]));

        $response->assertNotFound();
        $response->assertJson([
            'available' => false,
            'message' => 'Item not found.',
        ]);
    }

    public function test_unique_check_resolves_outfit_in_other_accessible_store_when_session_store_differs(): void
    {
        $storeA = Store::query()->firstOrCreate(['code' => 'sunq-a'], ['name' => 'Store A', 'is_active' => true]);
        $storeB = Store::query()->firstOrCreate(['code' => 'sunq-b'], ['name' => 'Store B', 'is_active' => true]);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->stores()->sync([$storeA->id, $storeB->id]);

        $outfitB = Outfit::withoutGlobalScopes()->create([
            'store_id' => $storeB->id,
            'code' => 'UNQ-B-KEEP',
            'description' => 'x',
            'stock' => 1,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['current_store_id' => $storeA->id])
            ->getJson(route('outfits.unique-check', [
                'outfit_id' => $outfitB->id,
                'field' => 'code',
                'value' => 'UNQ-B-KEEP',
            ]));

        $response->assertOk();
        $response->assertJson(['available' => true]);
    }
}
