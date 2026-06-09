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
