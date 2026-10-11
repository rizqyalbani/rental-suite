<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitBulkUpdateTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_bulk_update_updates_each_selected_row_from_items_payload(): void
    {
        $a = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'A-1',
            'description' => 'old',
            'stock' => 1,
        ]);
        $b = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'B-1',
            'description' => 'old',
            'stock' => 1,
        ]);

        $user = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->post(route('outfits.bulk-update'), [
                'items' => [
                    [
                        'id' => $a->id,
                        'code' => 'A-1',
                        'description' => 'Renovation batch A',
                        'new_code' => '',
                        'stock' => 2,
                        'qr_redirect_url' => '',
                        'price' => '',
                    ],
                    [
                        'id' => $b->id,
                        'code' => 'B-1',
                        'description' => 'Renovation batch B',
                        'new_code' => '',
                        'stock' => 3,
                        'qr_redirect_url' => '',
                        'price' => '',
                    ],
                ],
            ]);

        $response->assertRedirect(route('outfits.index'));
        $this->assertSame('Renovation batch A', $a->fresh()->description);
        $this->assertSame('Renovation batch B', $b->fresh()->description);
        $this->assertSame(2, (int) $a->fresh()->stock);
        $this->assertSame(3, (int) $b->fresh()->stock);
    }

    public function test_bulk_update_preserves_stock_zero(): void
    {
        $outfit = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'ZERO-STOCK',
            'description' => 'x',
            'stock' => 0,
        ]);

        $user = User::factory()->create(['role' => 'management']);

        $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->post(route('outfits.bulk-update'), [
                'items' => [
                    [
                        'id' => $outfit->id,
                        'description' => 'still zero',
                        'new_code' => '',
                        'stock' => 0,
                        'qr_redirect_url' => '',
                        'price' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('outfits.index'));

        $this->assertSame(0, (int) $outfit->fresh()->stock);
        $this->assertSame('still zero', $outfit->fresh()->description);
    }

    public function test_bulk_update_rejects_invalid_outfit_id(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->post(route('outfits.bulk-update'), [
                'items' => [
                    [
                        'id' => 999999,
                        'code' => 'X',
                        'description' => 'X',
                        'new_code' => '',
                        'stock' => 1,
                        'qr_redirect_url' => '',
                        'price' => '',
                    ],
                ],
            ]);

        $response->assertRedirect(route('outfits.index'))
            ->assertSessionHas('error');
    }

    public function test_bulk_update_updates_outfit_in_other_accessible_store_when_session_store_differs(): void
    {
        $storeA = Store::query()->firstOrCreate(['code' => 'sbulk-a'], ['name' => 'Store A', 'is_active' => true]);
        $storeB = Store::query()->firstOrCreate(['code' => 'sbulk-b'], ['name' => 'Store B', 'is_active' => true]);

        $admin = User::factory()->create(['role' => 'management']);
        $admin->stores()->sync([$storeA->id, $storeB->id]);

        $outfitB = Outfit::withoutGlobalScopes()->create([
            'store_id' => $storeB->id,
            'code' => 'BULK-B-1',
            'description' => 'before',
            'stock' => 1,
        ]);

        $this->actingAs($admin)
            ->withSession(['current_store_id' => $storeA->id])
            ->from(route('outfits.index'))
            ->post(route('outfits.bulk-update'), [
                'items' => [
                    [
                        'id' => $outfitB->id,
                        'code' => 'BULK-B-1',
                        'description' => 'after bulk from group edit',
                        'new_code' => '',
                        'stock' => 5,
                        'qr_redirect_url' => '',
                        'price' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('outfits.index'))
            ->assertSessionHas('success');

        $this->assertSame('after bulk from group edit', $outfitB->fresh()->description);
        $this->assertSame(5, (int) $outfitB->fresh()->stock);
    }
}
