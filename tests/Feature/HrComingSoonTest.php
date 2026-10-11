<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HR is announced but not built: the page is read-only and visible only to the
 * roles that already manage users.
 */
class HrComingSoonTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_sees_the_coming_soon_page_with_the_planned_scope(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'management']))
            ->get(route('hr.index'))
            ->assertOk()
            ->assertSee('Coming soon')
            ->assertSee('Attendance per store')
            ->assertSee('Shift scheduling');
    }

    public function test_admin_cannot_open_the_hr_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('hr.index'))
            ->assertForbidden();
    }
}
