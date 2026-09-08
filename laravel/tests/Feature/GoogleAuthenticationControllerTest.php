<?php

namespace Tests\Feature;

use App\Models\User as AppUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthenticationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google', ['client_id' => 'client', 'client_secret' => 'secret', 'redirect' => '/auth/google/callback', 'allowed_domains' => 'example.com', 'login_only' => false]);
    }

    public function test_login_page_and_redirect_expose_configured_google_flow(): void
    {
        $this->get(route('login'))->assertOk()->assertSeeText('Continue with Google');
        Socialite::fake('google');
        $this->get(route('auth.google.redirect'))->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_callback_links_existing_user_regenerates_session_and_does_not_store_tokens(): void
    {
        $user = AppUser::factory()->unverified()->create(['email' => 'person@example.com']);
        $identity = $this->identity();
        Socialite::fake('google', $identity);
        $oldSessionId = session()->getId();

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_id' => 'google-123']);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('users', ['email' => 'fake-token']);
    }

    public function test_callback_rejects_unknown_user_disallowed_domain_and_google_id_conflict(): void
    {
        Socialite::fake('google', $this->identity());
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->assertDatabaseCount('users', 0);

        AppUser::factory()->create(['email' => 'person@example.com']);
        Socialite::fake('google', $this->identity(['hd' => 'outsider.example']));
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->assertGuest();

        AppUser::query()->where('email', 'person@example.com')->update(['google_id' => 'another-google-id']);
        Socialite::fake('google', $this->identity());
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_cancelled_unconfigured_and_provider_failures_are_safe(): void
    {
        $this->get(route('auth.google.callback', ['error' => 'access_denied']))->assertRedirect(route('login'))->assertSessionHasErrors('google');
        config()->set('services.google.client_secret', '');
        $this->get(route('auth.google.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('google');

        config()->set('services.google.client_secret', 'secret');
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('driver')->with('google')->andThrow(new RuntimeException('oauth secret'));
        Socialite::swap($factory);
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors(['google' => 'Google sign-in could not be completed. Please try again.']);
        $this->assertGuest();
    }

    public function test_google_only_mode_hides_and_rejects_password_login(): void
    {
        config()->set('services.google.login_only', true);

        $this->get(route('login'))->assertOk()->assertSeeText('Continue with Google')->assertDontSee('name="password"', false);
        $user = AppUser::factory()->create(['email' => 'person@example.com', 'password' => 'password']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** @param array<string, mixed> $overrides */
    private function identity(array $overrides = []): SocialiteUser
    {
        return SocialiteUser::fake(array_replace(['id' => 'google-123', 'name' => 'Example Person', 'email' => 'person@example.com', 'email_verified' => true, 'hd' => 'example.com', 'token' => 'fake-token'], $overrides));
    }
}
