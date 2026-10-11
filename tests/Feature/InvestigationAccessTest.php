<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestigationAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    public function test_management_can_access_investigation_page(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('MySQL specific functions are used in the controller.');
        }

        $management = $this->userWithRole('management');

        $this->actingAs($management)
            ->get(route('investigations.index'))
            ->assertOk()
            ->assertSeeText('Legacy Pricing Anomalies');
    }

    public function test_admin_cannot_access_investigation_page(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('investigations.index'))
            ->assertForbidden();
    }
}
