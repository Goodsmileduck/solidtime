<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\OAuthConnection;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GoogleOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $email, string $id = 'sub-1', bool $verified = true): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->map(['id' => $id, 'name' => 'Test User', 'email' => $email]);
        $user->user = ['email_verified' => $verified];

        return $user;
    }

    private function mockCallback(SocialiteUser $user): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_redirect_route_redirects_to_google(): void
    {
        config(['services.google.client_id' => 'x', 'services.google.client_secret' => 'y', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $response = $this->get(route('auth.google.redirect'));
        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_callback_creates_and_authenticates_new_user(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => null]);
        $this->mockCallback($this->fakeGoogleUser('new@example.com'));

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(config('fortify.home'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $this->assertDatabaseHas('oauth_connections', ['provider_user_id' => 'sub-1']);
    }

    public function test_callback_blocked_by_allowlist_redirects_to_login_with_message(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => '@allowed.com']);
        $this->mockCallback($this->fakeGoogleUser('outsider@blocked.com'));

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('message');
        $this->assertGuest();
    }

    public function test_callback_unverified_email_redirects_to_login(): void
    {
        $this->mockCallback($this->fakeGoogleUser('new@example.com', verified: false));

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('message');
        $this->assertGuest();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
