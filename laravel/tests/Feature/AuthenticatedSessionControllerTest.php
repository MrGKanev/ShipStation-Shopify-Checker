<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthenticatedSessionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_can_render_the_login_form(): void
    {
        $response = $this->get(route('login'));

        $response
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertSeeText('Sign in');
    }

    public function test_valid_credentials_authenticate_the_user_and_regenerate_the_session(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'correct-password',
        ]);
        $originalSessionId = session()->getId();

        $response = $this->post(route('login.store'), [
            'email' => 'operator@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($originalSessionId, session()->getId());
    }

    public function test_invalid_credentials_are_rejected_with_a_generic_message(): void
    {
        User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'operator@example.com',
                'password' => 'wrong-password',
            ]);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'email' => 'The provided credentials do not match our records.',
            ]);
        $this->assertGuest();
    }

    public function test_login_requires_an_email_and_password(): void
    {
        $response = $this
            ->from(route('login'))
            ->post(route('login.store'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'email' => 'The email field is required.',
                'password' => 'The password field is required.',
            ]);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_by_email_and_ip_address(): void
    {
        User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'correct-password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'operator@example.com',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), [
            'email' => 'operator@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_authenticated_user_is_redirected_away_from_the_login_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('login'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
