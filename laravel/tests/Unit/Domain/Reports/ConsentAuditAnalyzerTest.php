<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ConsentAuditAnalyzer;
use Tests\TestCase;

class ConsentAuditAnalyzerTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_consent_rules_defaults_and_sorting(): void
    {
        $base = ['id' => 1, 'name' => '#1', 'created_at' => '2026-06-01T00:00:00Z', 'customer_email_consent' => 'not_subscribed', 'customer_sms_consent' => 'not_subscribed'];
        $rows = (new ConsentAuditAnalyzer)->analyze([$base, [...$base, 'name' => '#2', 'created_at' => '2026-06-15'], [...$base, 'customer_email_consent' => 'subscribed'], [...$base, 'name' => '#3', 'customer_email_consent' => '', 'customer_sms_consent' => '']]);
        $this->assertSame(['#2', '#1', '#3'], array_column($rows, 'number'));
        $this->assertSame(['unknown', 'unknown'], [$rows[2]['email_consent'], $rows[2]['sms_consent']]);
    }
}
