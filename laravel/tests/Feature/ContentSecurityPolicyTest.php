<?php

namespace Tests\Feature;

use App\Support\ContentSecurityPolicy;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    public function test_policy_starts_in_report_only_mode_with_a_nonce(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()->assertHeaderMissing('Content-Security-Policy');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $policy);
        $this->assertStringNotContainsString('unsafe-inline', $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function test_policy_can_be_switched_to_enforcing_mode(): void
    {
        config()->set('csp.presets', [ContentSecurityPolicy::class]);
        config()->set('csp.report_only_presets', []);

        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
