<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "System readiness test" floating checklist (partials/readiness-checklist) is a
 * browser-only helper: it must render for admin / management / super admin only,
 * must never break a page for any role, and must add no route or table.
 */
class ReadinessChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $store = Store::first() ?? Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        StoreContext::set($store->id);
        $user = User::factory()->create(['role' => $role]);
        $user->stores()->attach($store->id);

        return $user;
    }

    public function test_allowed_roles_get_the_panel_script_and_the_help_start_button(): void
    {
        foreach (['admin', 'management', 'super_admin'] as $role) {
            $user = $this->userWithRole($role);

            $help = $this->actingAs($user)->get(route('help.index'));
            $help->assertOk();
            $help->assertSee('data-start-readiness', false);
            $help->assertSee('rental_readiness_v1_', false);

            $dashboard = $this->actingAs($user)->get(route('dashboard'));
            $dashboard->assertOk();
            $dashboard->assertSee('rental_readiness_v1_', false);
        }
    }

    public function test_other_roles_never_get_the_panel_and_pages_still_render(): void
    {
        foreach (['supervisor', 'accounting'] as $role) {
            $user = $this->userWithRole($role);

            $help = $this->actingAs($user)->get(route('help.index'));
            $help->assertOk();
            $help->assertDontSee('data-start-readiness', false);
            $help->assertDontSee('rental_readiness_v1_', false);

            $dashboard = $this->actingAs($user)->get(route('dashboard'));
            $this->assertContains($dashboard->getStatusCode(), [200, 302]);
            $this->assertStringNotContainsString('rental_readiness_v1_', (string) $dashboard->getContent());
        }
    }

    public function test_checklist_links_point_at_routes_that_exist_for_an_allowed_role(): void
    {
        $user = $this->userWithRole('management');

        foreach (['/dashboard', '/rentals', '/accounting/reports/balance-sheet', '/accounting/reports/profit-loss',
            '/accounting/reports/general-ledger', '/approvals/edit-requests', '/my-requests', '/users', '/help',
            '/expenses', '/accounting/journal-entries', '/accounting/reports/fixed-assets', '/stock-opname'] as $path) {
            $this->assertNotSame(404, $this->actingAs($user)->get($path)->getStatusCode(), $path.' is a dead link');
        }
    }

    public function test_guests_see_no_panel_on_the_login_page(): void
    {
        $this->get('/login')->assertDontSee('rental_readiness_v1_', false);
    }
}
