<?php

namespace Tests\Unit;

use App\Domain\Authentication\GoogleIdentityPolicy;
use Laravel\Socialite\Two\User;
use PHPUnit\Framework\TestCase;

class GoogleIdentityPolicyTest extends TestCase
{
    public function test_parses_domains_and_allows_verified_workspace_identity(): void
    {
        $policy = new GoogleIdentityPolicy(' @Example.com, example.com subsidiary.example ');

        $this->assertSame(['example.com', 'subsidiary.example'], $policy->allowedDomains());
        $this->assertTrue($policy->allows($this->identity(['email' => 'person@example.com', 'email_verified' => true, 'hd' => 'EXAMPLE.COM'])));
    }

    public function test_rejects_missing_subject_invalid_email_unverified_email_and_unlisted_hosted_domain(): void
    {
        $policy = new GoogleIdentityPolicy('example.com');

        $this->assertFalse($policy->allows($this->identity(['id' => '', 'email_verified' => true, 'hd' => 'example.com'])));
        $this->assertFalse($policy->allows($this->identity(['email' => 'bad', 'email_verified' => true, 'hd' => 'example.com'])));
        $this->assertFalse($policy->allows($this->identity(['email_verified' => false, 'hd' => 'example.com'])));
        $this->assertFalse($policy->allows($this->identity(['email_verified' => true, 'hd' => 'outsider.example'])));
        $this->assertFalse($policy->allows($this->identity(['email_verified' => true, 'hd' => ''])));
    }

    /** @param array<string, mixed> $overrides */
    private function identity(array $overrides): User
    {
        return User::fake(array_replace(['id' => 'google-123', 'email' => 'person@example.com'], $overrides));
    }
}
