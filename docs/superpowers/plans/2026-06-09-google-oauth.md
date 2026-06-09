# Google OAuth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add "Continue with Google" sign-up/sign-in to Solidtime via Laravel Socialite, with auto-link by verified email and a configurable email allowlist gating new self-registration.

**Architecture:** A thin `GoogleOAuthController` (redirect + callback) delegates to a testable `GoogleOAuthService` that resolves Socialite users to app users (login existing connection → auto-link by verified email → create new user + personal org). Provider linkage lives in a dedicated `oauth_connections` table. A `RegistrationAllowlist` service gates new self-registration for both Google and password paths.

**Tech Stack:** Laravel 12, Jetstream/Fortify, Inertia + Vue 3, Laravel Socialite, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-09-google-oauth-design.md`

**Conventions in this repo:**
- All files start with `declare(strict_types=1);`.
- Run all PHP commands inside the app container if using Docker, otherwise directly. Tests: `./vendor/bin/phpunit` (or `composer test`).
- UUID models use `use App\Models\Concerns\HasUuids;`.
- The Inertia shared prop `flash.message` is already rendered as a red error box on `Login.vue`; redirect errors use `->with('message', '...')`.

---

### Task 1: Install Socialite and add Google config

**Files:**
- Modify: `composer.json` (via composer require)
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Require the package**

Run: `composer require laravel/socialite`
Expected: package added to `composer.json` require block, `composer.lock` updated.

- [ ] **Step 2: Add the google + allowlist config**

Modify `config/services.php` — add inside the returned array:

```php
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'hosted_domain' => env('GOOGLE_HOSTED_DOMAIN'),
    ],
```

- [ ] **Step 3: Document env vars**

Append to `.env.example`:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
GOOGLE_HOSTED_DOMAIN=
REGISTRATION_ALLOWLIST=
```

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/services.php .env.example
git commit -m "feat(oauth): install socialite and add google config"
```

---

### Task 2: oauth_connections table + model + relation

**Files:**
- Create: `database/migrations/2026_06_09_000000_create_oauth_connections_table.php`
- Create: `app/Models/OAuthConnection.php`
- Modify: `app/Models/User.php` (add relation + use HasMany — HasMany already imported)
- Test: `tests/Unit/Model/OAuthConnectionModelTest.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_06_09_000000_create_oauth_connections_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_connections');
    }
};
```

- [ ] **Step 2: Write the model**

Create `app/Models/OAuthConnection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class OAuthConnection extends Model
{
    use HasUuids;

    protected $table = 'oauth_connections';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Add the relation on User**

In `app/Models/User.php`, add this method inside the class (near other relations). `HasMany` is already imported:

```php
    /**
     * @return HasMany<OAuthConnection, $this>
     */
    public function oauthConnections(): HasMany
    {
        return $this->hasMany(OAuthConnection::class);
    }
```

- [ ] **Step 4: Write the failing test**

Create `tests/Unit/Model/OAuthConnectionModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Models\OAuthConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthConnectionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_a_user_and_is_listed_in_user_relation(): void
    {
        $user = User::factory()->create();
        $connection = OAuthConnection::create([
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
        ]);

        $this->assertTrue($connection->user->is($user));
        $this->assertTrue($user->refresh()->oauthConnections->contains($connection));
    }
}
```

- [ ] **Step 5: Run migration + test, verify pass**

Run: `./vendor/bin/phpunit tests/Unit/Model/OAuthConnectionModelTest.php`
Expected: PASS (RefreshDatabase runs the new migration).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_09_000000_create_oauth_connections_table.php app/Models/OAuthConnection.php app/Models/User.php tests/Unit/Model/OAuthConnectionModelTest.php
git commit -m "feat(oauth): add oauth_connections table, model, user relation"
```

---

### Task 3: RegistrationAllowlist service

**Files:**
- Create: `app/Service/RegistrationAllowlist.php`
- Test: `tests/Unit/Service/RegistrationAllowlistTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/RegistrationAllowlistTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\RegistrationAllowlist;
use Tests\TestCase;

class RegistrationAllowlistTest extends TestCase
{
    private function allowlist(?string $config): RegistrationAllowlist
    {
        config(['app.registration_allowlist' => $config]);

        return new RegistrationAllowlist;
    }

    public function test_empty_or_unset_list_allows_everyone(): void
    {
        $this->assertTrue($this->allowlist(null)->allows('anyone@example.com'));
        $this->assertTrue($this->allowlist('')->allows('anyone@example.com'));
        $this->assertTrue($this->allowlist('   ')->allows('anyone@example.com'));
    }

    public function test_exact_email_match_is_case_insensitive_and_whitespace_tolerant(): void
    {
        $list = $this->allowlist(' Alice@Example.com , bob@example.com ');
        $this->assertTrue($list->allows('alice@example.com'));
        $this->assertTrue($list->allows('BOB@EXAMPLE.COM'));
        $this->assertFalse($list->allows('carol@example.com'));
    }

    public function test_domain_match(): void
    {
        $list = $this->allowlist('@3dlab.co.id, specific@gmail.com');
        $this->assertTrue($list->allows('anyone@3dlab.co.id'));
        $this->assertTrue($list->allows('ANYONE@3DLAB.CO.ID'));
        $this->assertTrue($list->allows('specific@gmail.com'));
        $this->assertFalse($list->allows('random@gmail.com'));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Service/RegistrationAllowlistTest.php`
Expected: FAIL — class `App\Service\RegistrationAllowlist` not found.

- [ ] **Step 3: Add the config key**

In `config/app.php`, add to the returned array:

```php
    'registration_allowlist' => env('REGISTRATION_ALLOWLIST'),
```

- [ ] **Step 4: Write the service**

Create `app/Service/RegistrationAllowlist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

class RegistrationAllowlist
{
    /**
     * Whether the given email is allowed to self-register.
     * Empty/unset allowlist => fail-open (everyone allowed).
     */
    public function allows(string $email): bool
    {
        $raw = config('app.registration_allowlist');
        if (! is_string($raw) || trim($raw) === '') {
            return true;
        }

        $email = strtolower(trim($email));
        $domain = '@'.substr(strrchr($email, '@') ?: '@', 1);

        foreach (explode(',', $raw) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if (str_starts_with($entry, '@')) {
                if ($entry === $domain) {
                    return true;
                }
            } elseif ($entry === $email) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run test, verify pass**

Run: `./vendor/bin/phpunit tests/Unit/Service/RegistrationAllowlistTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Service/RegistrationAllowlist.php config/app.php tests/Unit/Service/RegistrationAllowlistTest.php
git commit -m "feat(oauth): add registration allowlist service"
```

---

### Task 4: Allow password-less user creation in UserService

**Files:**
- Modify: `app/Service/UserService.php:23-65` (the `createUser` method)
- Test: `tests/Unit/Service/UserServicePasswordlessTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/UserServicePasswordlessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Enums\Weekday;
use App\Service\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserServicePasswordlessTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_accepts_null_password_and_can_verify_email(): void
    {
        $user = app(UserService::class)->createUser(
            name: 'Grace Hopper',
            email: 'grace@example.com',
            password: null,
            timezone: 'UTC',
            weekStart: Weekday::Monday,
            currency: null,
            verifyEmail: true,
        );

        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->ownedTeams()->first(), 'personal organization should be created');
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Service/UserServicePasswordlessTest.php`
Expected: FAIL — `createUser()` type error (password is non-nullable `string`).

- [ ] **Step 3: Make password nullable in createUser**

In `app/Service/UserService.php`, change the `createUser` signature parameter `string $password` to `?string $password`, and change the password assignment line:

From:
```php
        $user->password = Hash::make($password);
```
To:
```php
        $user->password = $password !== null ? Hash::make($password) : null;
```

(The `bool $verifyEmail = false` parameter already exists and sets `email_verified_at`.)

- [ ] **Step 4: Run test + full UserService suite, verify pass**

Run: `./vendor/bin/phpunit tests/Unit/Service/UserServicePasswordlessTest.php`
Expected: PASS.
Run: `./vendor/bin/phpunit --filter UserService`
Expected: existing UserService tests still PASS (signature widened, not narrowed).

- [ ] **Step 5: Commit**

```bash
git add app/Service/UserService.php tests/Unit/Service/UserServicePasswordlessTest.php
git commit -m "feat(oauth): allow password-less user creation"
```

---

### Task 5: GoogleAccountLinked notification

**Files:**
- Create: `app/Notifications/GoogleAccountLinked.php`
- Test: covered in Task 6 feature tests (assert `Notification::fake()` sent). No standalone test needed here.

- [ ] **Step 1: Write the notification**

Create `app/Notifications/GoogleAccountLinked.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GoogleAccountLinked extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('A Google sign-in was connected to your account'))
            ->line(__('A Google account was just linked to your :app account and can now be used to sign in.', ['app' => config('app.name')]))
            ->line(__('If this was not you, please change your password and contact support immediately.'));
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Notifications/GoogleAccountLinked.php
git commit -m "feat(oauth): add google-account-linked notification"
```

---

### Task 6: GoogleOAuthService (resolve / link / create)

**Files:**
- Create: `app/Exceptions/OAuth/EmailNotVerifiedException.php`
- Create: `app/Exceptions/OAuth/RegistrationNotAllowedException.php`
- Create: `app/Service/GoogleOAuthService.php`
- Test: `tests/Feature/Auth/GoogleOAuthServiceTest.php`

- [ ] **Step 1: Write the exceptions**

Create `app/Exceptions/OAuth/EmailNotVerifiedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\OAuth;

use Exception;

class EmailNotVerifiedException extends Exception
{
}
```

Create `app/Exceptions/OAuth/RegistrationNotAllowedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\OAuth;

use Exception;

class RegistrationNotAllowedException extends Exception
{
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Auth/GoogleOAuthServiceTest.php`:

```php
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
        config(['app.registration_allowlist' => null]);

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
        config(['app.registration_allowlist' => '@allowed.com']);
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
        config(['app.registration_allowlist' => null]);
        User::factory()->create(['email' => 'ghost@example.com', 'is_placeholder' => true]);

        $resolved = app(GoogleOAuthService::class)->resolve($this->socialiteUser('ghost@example.com'));

        // A brand-new real user is created, the placeholder is left untouched.
        $this->assertFalse($resolved->is_placeholder);
        $this->assertSame(2, User::count());
    }
}
```

- [ ] **Step 3: Run test, verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Auth/GoogleOAuthServiceTest.php`
Expected: FAIL — `App\Service\GoogleOAuthService` not found.

- [ ] **Step 4: Write the service**

Create `app/Service/GoogleOAuthService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\Weekday;
use App\Exceptions\OAuth\EmailNotVerifiedException;
use App\Exceptions\OAuth\RegistrationNotAllowedException;
use App\Models\OAuthConnection;
use App\Models\User;
use App\Notifications\GoogleAccountLinked;
use App\Service\IpLookup\IpLookupServiceContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

class GoogleOAuthService
{
    public function __construct(
        private readonly RegistrationAllowlist $allowlist,
        private readonly UserService $userService,
    ) {}

    /**
     * Resolve a Socialite Google user to an authenticated application user.
     *
     * @throws EmailNotVerifiedException
     * @throws RegistrationNotAllowedException
     */
    public function resolve(SocialiteUser $googleUser): User
    {
        $providerUserId = (string) $googleUser->getId();
        $email = strtolower((string) $googleUser->getEmail());

        // 1. Existing connection -> log in.
        $connection = OAuthConnection::query()
            ->where('provider', '=', 'google')
            ->where('provider_user_id', '=', $providerUserId)
            ->first();
        if ($connection !== null) {
            return $connection->user;
        }

        // Verified email is required for any link-by-email or create.
        if ($email === '' || ($googleUser->user['email_verified'] ?? false) !== true) {
            throw new EmailNotVerifiedException;
        }

        // 2. Existing user by (non-placeholder) email -> auto-link + notify.
        $existing = User::query()
            ->where('email', '=', $email)
            ->where('is_placeholder', '=', false)
            ->first();
        if ($existing !== null) {
            $this->linkConnection($existing, $providerUserId);
            $existing->notify(new GoogleAccountLinked);

            return $existing;
        }

        // 3. New user -> gate on global registration flag + allowlist, then create.
        if (! config('app.enable_registration')) {
            throw new RegistrationNotAllowedException;
        }
        if (! $this->allowlist->allows($email)) {
            throw new RegistrationNotAllowedException;
        }

        return $this->createUser($googleUser, $email, $providerUserId);
    }

    private function linkConnection(User $user, string $providerUserId): OAuthConnection
    {
        return OAuthConnection::create([
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'provider_user_id' => $providerUserId,
        ]);
    }

    private function createUser(SocialiteUser $googleUser, string $email, string $providerUserId): User
    {
        $ipLookup = app(IpLookupServiceContract::class)->lookup(request()->ip());
        $timezone = $ipLookup?->timezone ?? 'UTC';
        $startOfWeek = $ipLookup?->startOfWeek ?? Weekday::Monday;
        $currency = $ipLookup?->currency;

        return DB::transaction(function () use ($googleUser, $email, $providerUserId, $timezone, $startOfWeek, $currency): User {
            $user = $this->userService->createUser(
                name: (string) ($googleUser->getName() ?: $email),
                email: $email,
                password: null,
                timezone: $timezone,
                weekStart: $startOfWeek,
                currency: $currency,
                verifyEmail: true,
            );
            $this->linkConnection($user, $providerUserId);

            return $user;
        });
    }
}
```

- [ ] **Step 5: Run test, verify pass**

Run: `./vendor/bin/phpunit tests/Feature/Auth/GoogleOAuthServiceTest.php`
Expected: PASS (all 7 tests).

> If `IpLookupServiceContract` returns a non-null object in the test environment that lacks `timezone`/`startOfWeek`/`currency`, confirm those are nullable on its response DTO (they are used the same way in `CreateNewUser`). The null-coalescing here mirrors that usage.

- [ ] **Step 6: Commit**

```bash
git add app/Exceptions/OAuth app/Service/GoogleOAuthService.php tests/Feature/Auth/GoogleOAuthServiceTest.php
git commit -m "feat(oauth): add google oauth resolver service"
```

---

### Task 7: GoogleOAuthController + routes

**Files:**
- Create: `app/Http/Controllers/Auth/GoogleOAuthController.php`
- Modify: `routes/web.php` (add guest OAuth routes)
- Test: `tests/Feature/Auth/GoogleOAuthControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/GoogleOAuthControllerTest.php`:

```php
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
        config(['app.registration_allowlist' => null]);
        $this->mockCallback($this->fakeGoogleUser('new@example.com'));

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(config('fortify.home'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $this->assertDatabaseHas('oauth_connections', ['provider_user_id' => 'sub-1']);
    }

    public function test_callback_blocked_by_allowlist_redirects_to_login_with_message(): void
    {
        config(['app.registration_allowlist' => '@allowed.com']);
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
```

- [ ] **Step 2: Run test, verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Auth/GoogleOAuthControllerTest.php`
Expected: FAIL — route `auth.google.redirect` not defined.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Auth/GoogleOAuthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\OAuth\EmailNotVerifiedException;
use App\Exceptions\OAuth\RegistrationNotAllowedException;
use App\Http\Controllers\Controller;
use App\Service\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class GoogleOAuthController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        $driver = Socialite::driver('google')->enablePkce();

        $hostedDomain = config('services.google.hosted_domain');
        if (is_string($hostedDomain) && $hostedDomain !== '') {
            $driver->with(['hd' => $hostedDomain]);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, GoogleOAuthService $service): RedirectResponse
    {
        if ($request->query('error') !== null) {
            return $this->fail();
        }

        try {
            $googleUser = Socialite::driver('google')->user();
            $user = $service->resolve($googleUser);
        } catch (InvalidStateException|EmailNotVerifiedException|RegistrationNotAllowedException $e) {
            return $this->fail();
        }

        auth()->login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    private function fail(): RedirectResponse
    {
        return redirect()->route('login')
            ->with('message', __('Google sign-in failed or is not permitted for this account.'));
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add near the top-level public routes (after the `/shared-report` route, before the `auth:web` group), and add the controller import at the top:

```php
use App\Http\Controllers\Auth\GoogleOAuthController;
```

```php
Route::middleware(['throttle:30,1'])->group(function (): void {
    Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect'])
        ->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('auth.google.callback');
});
```

- [ ] **Step 5: Run test, verify pass**

Run: `./vendor/bin/phpunit tests/Feature/Auth/GoogleOAuthControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Auth/GoogleOAuthController.php routes/web.php tests/Feature/Auth/GoogleOAuthControllerTest.php
git commit -m "feat(oauth): add google oauth controller and routes"
```

---

### Task 8: Gate password registration with the allowlist

**Files:**
- Modify: `app/Actions/Fortify/CreateNewUser.php` (the `create` method, after the `enable_registration` check)
- Test: `tests/Feature/Auth/PasswordRegistrationAllowlistTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/PasswordRegistrationAllowlistTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PasswordRegistrationAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private function input(string $email): array
    {
        return [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms' => true,
        ];
    }

    public function test_blocked_email_cannot_register(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => '@allowed.com']);

        $this->expectException(ValidationException::class);
        app(CreateNewUser::class)->create($this->input('outsider@blocked.com'));
    }

    public function test_allowed_email_can_register(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => '@allowed.com']);

        $user = app(CreateNewUser::class)->create($this->input('insider@allowed.com'));
        $this->assertSame('insider@allowed.com', $user->email);
    }

    public function test_empty_allowlist_allows_registration(): void
    {
        config(['app.enable_registration' => true, 'app.registration_allowlist' => null]);

        $user = app(CreateNewUser::class)->create($this->input('anyone@example.com'));
        $this->assertSame('anyone@example.com', $user->email);
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Auth/PasswordRegistrationAllowlistTest.php`
Expected: FAIL — `test_blocked_email_cannot_register` does NOT throw (no gate yet).

- [ ] **Step 3: Add the allowlist gate**

In `app/Actions/Fortify/CreateNewUser.php`, add the import:

```php
use App\Service\RegistrationAllowlist;
```

Then, immediately after the existing `enable_registration` check block (which throws "Registration is disabled."), add:

```php
        if (isset($input['email']) && is_string($input['email']) && ! app(RegistrationAllowlist::class)->allows($input['email'])) {
            throw ValidationException::withMessages([
                'email' => [__('Registration is restricted to approved email addresses.')],
            ]);
        }
```

- [ ] **Step 4: Run test, verify pass**

Run: `./vendor/bin/phpunit tests/Feature/Auth/PasswordRegistrationAllowlistTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Fortify/CreateNewUser.php tests/Feature/Auth/PasswordRegistrationAllowlistTest.php
git commit -m "feat(oauth): gate password registration with allowlist"
```

---

### Task 9: Expose `googleOAuthEnabled` to the frontend

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php:54-66` (the `share` return array)
- Test: `tests/Feature/Auth/InertiaGoogleFlagTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/InertiaGoogleFlagTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InertiaGoogleFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shares_google_enabled_true_when_configured(): void
    {
        config(['services.google.client_id' => 'configured-id']);
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('googleOAuthEnabled', true));
    }

    public function test_login_page_shares_google_enabled_false_when_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('googleOAuthEnabled', false));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Auth/InertiaGoogleFlagTest.php`
Expected: FAIL — prop `googleOAuthEnabled` missing.

> If `assertInertia` reports the login route does not render Inertia (Fortify view), instead assert via the shared-prop snapshot the project uses. The prop addition in Step 3 is the real deliverable.

- [ ] **Step 3: Add the shared prop**

In `app/Http/Middleware/HandleInertiaRequests.php`, inside the `array_merge(parent::share($request), [ ... ])`, add:

```php
            'googleOAuthEnabled' => filled(config('services.google.client_id')),
```

- [ ] **Step 4: Run test, verify pass**

Run: `./vendor/bin/phpunit tests/Feature/Auth/InertiaGoogleFlagTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Auth/InertiaGoogleFlagTest.php
git commit -m "feat(oauth): share googleOAuthEnabled inertia prop"
```

---

### Task 10: Frontend — Google button on Login and Register

**Files:**
- Create: `resources/js/Components/GoogleLoginButton.vue`
- Modify: `resources/js/Pages/Auth/Login.vue`
- Modify: `resources/js/Pages/Auth/Register.vue`

> This task is UI; it has no PHPUnit test. Verify by building assets and a manual smoke check. Keep the component visually consistent with the existing auth pages.

- [ ] **Step 1: Create the button component**

Create `resources/js/Components/GoogleLoginButton.vue`:

```vue
<script setup lang="ts">
const label = 'Continue with Google';
</script>

<template>
    <a
        :href="route('auth.google.redirect')"
        class="flex items-center justify-center gap-3 w-full px-4 py-2 border border-border-secondary rounded-lg bg-white text-gray-700 text-sm font-medium hover:bg-gray-50 transition">
        <svg class="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
            <path
                fill="#EA4335"
                d="M12 10.2v3.9h5.5c-.24 1.4-1.7 4.1-5.5 4.1-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.9 3.1 14.7 2 12 2 6.9 2 2.8 6.1 2.8 12S6.9 22 12 22c6 0 9.3-4.2 9.3-9.2 0-.6-.1-1-.2-1.6H12z" />
        </svg>
        <span>{{ label }}</span>
    </a>
</template>
```

- [ ] **Step 2: Add a divider + button to Login.vue**

In `resources/js/Pages/Auth/Login.vue`, add the import in `<script setup>`:

```ts
import GoogleLoginButton from '@/Components/GoogleLoginButton.vue';
```

Then, inside the `<AuthenticationCard>` immediately **before** the `<form>` element, add:

```vue
        <template v-if="$page.props.googleOAuthEnabled">
            <GoogleLoginButton />
            <div class="flex items-center my-6">
                <div class="flex-grow border-t border-border-secondary"></div>
                <span class="px-3 text-xs text-text-secondary uppercase">or</span>
                <div class="flex-grow border-t border-border-secondary"></div>
            </div>
        </template>
```

- [ ] **Step 3: Add the same to Register.vue**

In `resources/js/Pages/Auth/Register.vue`, add the import:

```ts
import GoogleLoginButton from '@/Components/GoogleLoginButton.vue';
```

Then add the same `<template v-if="$page.props.googleOAuthEnabled"> … </template>` block (identical to Step 2) inside the authentication card, immediately before the registration `<form>`.

- [ ] **Step 4: Type-check / build the frontend**

Run: `npm run build`
Expected: build succeeds with no TypeScript errors for the new component/pages.

- [ ] **Step 5: Manual smoke check**

With `GOOGLE_CLIENT_ID` set in `.env`, run the app and confirm:
- The "Continue with Google" button appears on `/login` and `/register`.
- With `GOOGLE_CLIENT_ID` empty, the button is hidden.
- Clicking it redirects to `accounts.google.com`.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/GoogleLoginButton.vue resources/js/Pages/Auth/Login.vue resources/js/Pages/Auth/Register.vue
git commit -m "feat(oauth): add google sign-in button to auth pages"
```

---

### Task 11: Full suite + static analysis

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: all tests PASS.

- [ ] **Step 2: Run PHPStan (repo is level 7)**

Run: `./vendor/bin/phpstan analyse`
Expected: no new errors in the added files. Fix any type issues inline.

- [ ] **Step 3: Run Pint (code style)**

Run: `./vendor/bin/pint --dirty`
Expected: files formatted to repo style. Commit any reformatting.

- [ ] **Step 4: Commit any fixups**

```bash
git add -A
git commit -m "chore(oauth): static analysis and style fixups"
```

---

## Notes for the implementer

- **Verify the `IpLookupServiceContract` response shape** when writing Task 6: it is used identically in `app/Actions/Fortify/CreateNewUser.php` (properties `timezone`, `startOfWeek`, `currency`, all nullable). Mirror that usage.
- **Google `email_verified`** comes back as a real boolean `true` from Google's OIDC userinfo; the strict `=== true` check is intentional (rejects string `"true"` or missing key).
- **Do not call `->stateless()`** on the Socialite driver — the stateful flow is what gives us CSRF `state` protection.
- **Session regeneration** after `auth()->login()` is a required security step (session fixation); it is covered implicitly by the controller flow.
- The Google button SVG in Task 10 is a single-path simplification; if the team wants the official multi-color mark, swap in Google's official asset per their branding guidelines — same component, no logic change.
