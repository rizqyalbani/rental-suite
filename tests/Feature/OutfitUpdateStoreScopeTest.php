<?php

namespace Tests\Feature;

use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitUpdateStoreScopeTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_update_allows_changing_code_to_one_used_only_in_another_store(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        $mine = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'ALPHA',
            'description' => '',
            'stock' => 1,
        ]);

        Outfit::withoutGlobalScopes()->create([
            'store_id' => 2,
            'code' => 'OTHER-STORE-CODE',
            'description' => '',
            'stock' => 1,
        ]);

        $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->put(route('outfits.update', $mine), [
                'code' => 'OTHER-STORE-CODE',
                'new_code' => null,
                'description' => 'updated',
                'stock' => 1,
                'form_source' => 'update',
            ])
            ->assertRedirect(route('outfits.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('OTHER-STORE-CODE', $mine->fresh()->code);
    }

    public function test_update_rejects_duplicate_code_within_same_store(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'TAKEN',
            'description' => '',
            'stock' => 1,
        ]);

        $mine = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'MINE',
            'description' => '',
            'stock' => 1,
        ]);

        $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->put(route('outfits.update', $mine), [
                'code' => 'TAKEN',
                'new_code' => null,
                'description' => 'x',
                'stock' => 1,
                'form_source' => 'update',
            ])
            ->assertRedirect(route('outfits.index'))
            ->assertSessionHasErrors('code');

        $this->assertSame('MINE', $mine->fresh()->code);
    }

    public function test_update_returns_json_validation_errors_when_accept_json(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'TAKEN',
            'description' => '',
            'stock' => 1,
        ]);

        $mine = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'MINE',
            'description' => '',
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->putJson(route('outfits.update', $mine), [
                'code' => 'TAKEN',
                'new_code' => null,
                'description' => 'x',
                'stock' => 1,
                'form_source' => 'update',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->assertSame('MINE', $mine->fresh()->code);
    }

    public function test_update_rejects_blank_code(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        $mine = Outfit::withoutGlobalScopes()->create([
            'store_id' => 1,
            'code' => 'MINE',
            'description' => '',
            'stock' => 1,
        ]);

        $this->actingAs($user)
            ->withSession(['current_store_id' => 1])
            ->from(route('outfits.index'))
            ->put(route('outfits.update', $mine), [
                'code' => '',
                'new_code' => null,
                'description' => 'x',
                'stock' => 1,
                'form_source' => 'update',
            ])
            ->assertRedirect(route('outfits.index'))
            ->assertSessionHasErrors('code');

        $this->assertSame('MINE', $mine->fresh()->code);
    }
}
