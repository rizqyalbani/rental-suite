<?php

namespace Tests\Repro;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for patch 01-close-public-registration (finding C6-01).
 * FAILS on the current code (register is routed and creates an Admin), PASSES once the patch is applied.
 * Not part of the default suites; run by path:
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Repro/Patch01RegistrationClosedTest.php
 */
class Patch01RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_screen_is_not_routed(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_register_post_creates_no_user_and_no_session(): void
    {
        $this->post('/register', [
            'name' => 'Stranger', 'email' => 'stranger@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertNull(User::where('email', 'stranger@example.test')->first());
    }

    /** Control: the login and forgot-password screens keep working (guest routes next to the removed ones). */
    public function test_login_and_forgot_password_screens_still_render(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    /** Control: the welcome view is guarded with Route::has('register'), so the missing route must not break any named-route lookup. */
    public function test_register_route_name_is_gone_without_breaking_route_has(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('register'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('login'));
    }
}
