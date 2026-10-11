<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitQuickDeleteTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_json_delete_removes_outfit_when_no_active_rentals(): void
    {
        $user = User::factory()->create(['role' => 'management']);
        $outfit = Outfit::create([
            'code' => 'DEL-001',
            'description' => 'To delete',
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->deleteJson(route('outfits.destroy', $outfit));

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('outfits', ['id' => $outfit->id]);
    }

    public function test_json_delete_returns_422_when_active_rental_exists(): void
    {
        $user = User::factory()->create(['role' => 'management']);
        $outfit = Outfit::create([
            'code' => 'DEL-002',
            'description' => 'Rented',
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
        $rental->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->deleteJson(route('outfits.destroy', $outfit));

        $response->assertStatus(422);
        $this->assertDatabaseHas('outfits', ['id' => $outfit->id]);
    }

    public function test_json_delete_resolves_outfit_when_staff_has_access_even_if_session_store_differs(): void
    {
        $storeA = Store::query()->firstOrCreate(['code' => 'sdel-1'], ['name' => 'Store A', 'is_active' => true]);
        $storeB = Store::query()->firstOrCreate(['code' => 'sdel-2'], ['name' => 'Store B', 'is_active' => true]);

        $admin = User::factory()->create(['role' => 'management']);
        $admin->stores()->sync([$storeA->id, $storeB->id]);

        $outfitStoreB = Outfit::withoutGlobalScopes()->create([
            'store_id' => $storeB->id,
            'code' => 'DEL-XSTORE',
            'stock' => 1,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['current_store_id' => $storeA->id])
            ->deleteJson(route('outfits.destroy', $outfitStoreB));

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('outfits', ['id' => $outfitStoreB->id]);
    }

    public function test_json_delete_returns_403_for_admin_accounts(): void
    {
        $storeA = Store::query()->firstOrCreate(['code' => 'sdel-a'], ['name' => 'Store A', 'is_active' => true]);
        $storeB = Store::query()->firstOrCreate(['code' => 'sdel-b'], ['name' => 'Store B', 'is_active' => true]);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->stores()->sync([$storeA->id]);

        $outfitStoreB = Outfit::withoutGlobalScopes()->create([
            'store_id' => $storeB->id,
            'code' => 'DEL-NO-ACCESS',
            'stock' => 1,
        ]);

        $this->actingAs($admin)
            ->withSession(['current_store_id' => $storeA->id])
            ->deleteJson(route('outfits.destroy', $outfitStoreB))
            ->assertNotFound();

        $this->assertDatabaseHas('outfits', ['id' => $outfitStoreB->id]);
    }
}
