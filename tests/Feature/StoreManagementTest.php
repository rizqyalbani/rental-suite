<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Store::query()->firstOrCreate(['code' => 'store-1'], ['name' => 'Store 1', 'is_active' => true]);
        Store::query()->firstOrCreate(['code' => 'store-2'], ['name' => 'Store 2', 'is_active' => true]);
    }

    public function test_management_can_access_store_index(): void
    {
        $management = $this->userWithRole('management');
        $management->stores()->sync(Store::query()->pluck('id'));

        $this->actingAs($management)
            ->withSession(['current_store_id' => 1])
            ->get(route('stores.index'))
            ->assertOk()
            ->assertSeeText('Store Management');
    }

    public function test_admin_cannot_access_store_index(): void
    {
        $admin = $this->userWithRole('admin');
        $admin->stores()->sync([1]);

        $this->actingAs($admin)
            ->withSession(['current_store_id' => 1])
            ->get(route('stores.index'))
            ->assertForbidden();
    }

    public function test_management_can_assign_store_users(): void
    {
        $management = $this->userWithRole('management');
        $management->stores()->sync([1, 2]);
        $admin = $this->userWithRole('admin');
        $admin->stores()->sync([1]);
        $store2 = Store::query()->where('code', 'store-2')->firstOrFail();

        $this->actingAs($management)
            ->withSession(['current_store_id' => 1])
            ->post(route('stores.users.assign', $store2), [
                'user_ids' => [$management->id, $admin->id],
            ])
            ->assertRedirect(route('stores.index'));

        $this->assertTrue($store2->fresh()->users->contains('id', $admin->id));
    }
}
