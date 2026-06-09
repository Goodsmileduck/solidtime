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
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

class GoogleOAuthService
{
    public function __construct(
        private readonly RegistrationAllowlist $allowlist,
        private readonly UserService $userService,
        private readonly IpLookupServiceContract $ipLookup,
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
            ->active()
            ->first();
        if ($existing !== null) {
            $this->linkConnection($existing, $providerUserId);
            $existing->notify(new GoogleAccountLinked);

            return $existing;
        }

        // 3. New user -> gate on global registration flag + allowlist, then create.
        if (! config('app.enable_registration') || ! $this->allowlist->allows($email)) {
            throw new RegistrationNotAllowedException;
        }

        return $this->createUser($googleUser, $email, $providerUserId);
    }

    private function linkConnection(User $user, string $providerUserId): OAuthConnection
    {
        // firstOrCreate keeps this idempotent: two concurrent callbacks for the same
        // Google identity won't violate the unique(provider, provider_user_id) index.
        return OAuthConnection::firstOrCreate(
            ['provider' => 'google', 'provider_user_id' => $providerUserId],
            ['user_id' => $user->getKey()],
        );
    }

    private function createUser(SocialiteUser $googleUser, string $email, string $providerUserId): User
    {
        $timezone = 'UTC';
        $startOfWeek = Weekday::Monday;
        $currency = null;
        $ipLookup = $this->ipLookup->lookup(request()->ip());
        if ($ipLookup !== null) {
            $timezone = $ipLookup->timezone ?? 'UTC';
            $startOfWeek = $ipLookup->startOfWeek ?? Weekday::Monday;
            $currency = $ipLookup->currency;
        }

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

            return $user->refresh();
        });
    }
}
