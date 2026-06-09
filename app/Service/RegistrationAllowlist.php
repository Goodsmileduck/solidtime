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
