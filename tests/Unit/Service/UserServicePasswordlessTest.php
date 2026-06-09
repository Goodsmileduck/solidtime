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
