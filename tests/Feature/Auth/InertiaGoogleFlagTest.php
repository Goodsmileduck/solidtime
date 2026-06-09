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
