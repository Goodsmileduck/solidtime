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
        /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
        $driver = Socialite::driver('google');
        $driver->enablePkce();

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
            /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $driver->enablePkce();
            /** @var \Laravel\Socialite\Two\User $googleUser */
            $googleUser = $driver->user();
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
