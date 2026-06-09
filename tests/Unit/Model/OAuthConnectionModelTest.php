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
