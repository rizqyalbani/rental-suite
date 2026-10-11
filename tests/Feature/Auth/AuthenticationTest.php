<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_using_their_name_as_username(): void
    {
        $user = User::factory()->create(['name' => 'Jane Admin']);

        $response = $this->post('/login', [
            'login' => 'Jane Admin',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_never_redirects_to_json_endpoints(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => url('/rentals/search?page=1&per_page=10')])
            ->post('/login', [
                'login' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_method_can_be_restricted_to_email_only(): void
    {
        \App\Support\StoreCache::forever('login_method', 'email');
        $user = User::factory()->create(['name' => 'Jane Admin']);

        $response = $this->post('/login', [
            'login' => 'Jane Admin',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('login');

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_login_method_can_be_restricted_to_username_only(): void
    {
        \App\Support\StoreCache::forever('login_method', 'username');
        $user = User::factory()->create(['name' => 'Jane Admin']);

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('login');

        $response = $this->post('/login', [
            'login' => 'Jane Admin',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
