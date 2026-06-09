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
