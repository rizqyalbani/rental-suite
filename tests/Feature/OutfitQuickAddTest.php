<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutfitQuickAddTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_add_creates_outfit_with_code_only(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($user)->postJson(route('outfits.quick-add'), [
            'code' => 'QA-001',
        ]);

        $response->assertOk();
        $response->assertJsonPath('outfit.code', 'QA-001');
        $this->assertDatabaseHas('outfits', [
            'code' => 'QA-001',
        ]);
    }

    public function test_quick_add_accepts_optional_new_code(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($user)->postJson(route('outfits.quick-add'), [
            'code' => 'QA-002',
            'new_code' => 'ALT-02',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('outfits', [
            'code' => 'QA-002',
            'new_code' => 'ALT-02',
        ]);
    }

    public function test_quick_add_accepts_optional_description(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($user)->postJson(route('outfits.quick-add'), [
            'code' => 'QA-DESC',
            'description' => 'Navy blazer, size L',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('outfits', [
            'code' => 'QA-DESC',
            'description' => 'Navy blazer, size L',
        ]);
    }
}
