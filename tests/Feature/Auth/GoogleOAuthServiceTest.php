<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Exceptions\OAuth\EmailNotVerifiedException;
use App\Exceptions\OAuth\RegistrationNotAllowedException;
use App\Models\OAuthConnection;
use App\Models\User;
use App\Notifications\GoogleAccountLinked;
use App\Service\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private function socialiteUser(string $email, string $id = 'sub-1', bool $verified = true, string $name = 'Test User'): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $user->user = ['email_verified' => $verified];

        return $user;
    }

    public function test_existing_connection_logs_in_same_user(): void
    {
        $user = User::factory()->create();
        OAuthConnection::create(['user_id' => $user->getKey(), 'provider' => 'google', 'provider_user_id' => 'sub-1']);

        $resolved = app(GoogleOAuthService::class)->resolve($this->socialiteUser('whatever@example.com'));

        $this->assertTrue($resolved->is($user));
        $this->assertSame(1, OAuthConnection::count());
    }

    public function test_existing_user_by_email_is_auto_linked_and_notified(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'existing@example.com']);

        $resolved = app(GoogleOAuthService::class)->resolve($this->socialiteUser('existing@example.com'));

        $this->assertTrue($resolved->is($user));
        $this->assertDatabaseHas('oauth_connections', ['user_id' => $user->getKey(), 'provider_user_id' => 'sub-1']);
        Notification::assertSentTo($user, GoogleAccountLinked::class);
    }

    public function test_new_user_is_created_with_org_and_verified_email(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => null]);

        $resolved = app(GoogleOAuthService::class)->resolve($this->socialiteUser('new@example.com', name: 'New Person'));

        $this->assertSame('new@example.com', $resolved->email);
        $this->assertNull($resolved->password);
        $this->assertNotNull($resolved->email_verified_at);
        $this->assertNotNull($resolved->ownedTeams()->first());
        $this->assertDatabaseHas('oauth_connections', ['user_id' => $resolved->getKey(), 'provider_user_id' => 'sub-1']);
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->expectException(EmailNotVerifiedException::class);
        app(GoogleOAuthService::class)->resolve($this->socialiteUser('new@example.com', verified: false));
    }

    public function test_new_user_blocked_by_allowlist(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => '@allowed.com']);
        $this->expectException(RegistrationNotAllowedException::class);
        app(GoogleOAuthService::class)->resolve($this->socialiteUser('outsider@blocked.com'));
    }

    public function test_existing_user_bypasses_allowlist(): void
    {
        config(['app.registration_allowlist' => '@allowed.com']);
        $user = User::factory()->create(['email' => 'existing@blocked.com']);

        $resolved = app(GoogleOAuthService::class)->resolve($this->socialiteUser('existing@blocked.com'));

        $this->assertTrue($resolved->is($user));
    }

    public function test_placeholder_user_is_not_linked(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => null]);
        User::factory()->create(['email' => 'ghost@example.com', 'is_placeholder' => true]);

        $resolved = app(GoogleOAuthService::class)->resolve($this->socialiteUser('ghost@example.com'));

        $this->assertFalse($resolved->is_placeholder);
        $this->assertSame(2, User::count());
    }
}
