<?php

namespace Tests\Feature;

use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class UserManagementAccessTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    public function test_management_can_create_admin_account(): void
    {
        $management = $this->createUserWithRole('management');

        $response = $this->actingAs($management)->post(route('users.store'), [
            'name' => 'Admin Operator',
            'email' => 'admin.operator@example.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'admin.operator@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_management_can_create_management_but_not_super_admin_account(): void
    {
        $management = $this->createUserWithRole('management');

        // Since commit 8cfe967 management assigns admin/supervisor/management roles;
        // only super_admin stays reserved for a super admin (UserController::ensureRoleMutationAllowed).
        $this->actingAs($management)->post(route('users.store'), [
            'name' => 'Another Management',
            'email' => 'management2@example.com',
            'password' => 'password123',
            'role' => 'management',
        ])->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'management2@example.com', 'role' => 'management']);

        $this->actingAs($management)->post(route('users.store'), [
            'name' => 'Another Super Admin',
            'email' => 'super2@example.com',
            'password' => 'password123',
            'role' => 'super_admin',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'super2@example.com']);
    }

    public function test_user_with_shift_history_cannot_be_deleted_and_keeps_the_shift(): void
    {
        $management = $this->createUserWithRole('management');
        $staff = $this->createUserWithRole('admin');
        // Shift of another store on purpose: the check must not depend on the viewer's store.
        $shift = ShiftSession::create(['store_id' => 2, 'opened_by_user_id' => $staff->id, 'opened_at' => '2026-09-19 08:00:00', 'period' => ShiftSession::PERIOD_MORNING]);
        $shift->forceFill(['closed_at' => '2026-09-19 15:00:00', 'total_revenue' => 50000])->save();

        $this->actingAs($management)->delete(route('users.destroy', $staff))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('shift_sessions', ['id' => $shift->id, 'total_revenue' => 50000]);
    }

    public function test_user_without_shifts_can_still_be_deleted(): void
    {
        $management = $this->createUserWithRole('management');
        $staff = $this->createUserWithRole('admin');

        $this->actingAs($management)->delete(route('users.destroy', $staff))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_delete_guards_next_to_the_shift_check_still_hold(): void
    {
        $management = $this->createUserWithRole('management');
        $admin = $this->createUserWithRole('admin');
        $other = $this->createUserWithRole('admin');

        // Own account stays blocked.
        $this->actingAs($management)->delete(route('users.destroy', $management))->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $management->id]);

        // An admin never manages accounts.
        $this->actingAs($admin)->delete(route('users.destroy', $other))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_management_cannot_edit_non_admin_user(): void
    {
        $management = $this->createUserWithRole('management');
        $superAdmin = $this->createUserWithRole('super_admin');

        $this->actingAs($management)->put(route('users.update', $superAdmin), [
            'name' => 'Changed',
            'email' => $superAdmin->email,
            'role' => 'admin',
            'password' => '',
        ])->assertForbidden();
    }
}
