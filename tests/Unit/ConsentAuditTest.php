<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';

/**
 * Tests for OrderPolicyPageLoader::buildConsentAuditRows() via reflection (private method).
 */
class ConsentAuditTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderPolicyPageLoader::class);
        self::$method = $ref->getMethod('buildConsentAuditRows');
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id'                      => 1,
            'name'                    => '#1001',
            'created_at'              => '2026-06-01T00:00:00Z',
            'email'                   => 'a@example.com',
            'financial_status'        => 'paid',
            'total_price'             => 50.0,
            'customer_email_consent'  => 'not_subscribed',
            'customer_sms_consent'    => 'not_subscribed',
        ], $overrides);
    }

    private function buildRows(array $orders): array
    {
        return self::$method->invoke(null, $orders);
    }

    public function testSubscribedCustomerIsNotFlagged(): void
    {
        $rows = $this->buildRows([$this->order(['customer_email_consent' => 'subscribed'])]);

        $this->assertSame([], $rows);
    }

    public function testNotSubscribedCustomerIsFlagged(): void
    {
        $rows = $this->buildRows([$this->order()]);

        $this->assertCount(1, $rows);
        $this->assertSame('not_subscribed', $rows[0]['email_consent']);
        $this->assertSame('not_subscribed', $rows[0]['sms_consent']);
    }

    public function testMissingConsentDataDefaultsToUnknown(): void
    {
        $rows = $this->buildRows([$this->order(['customer_email_consent' => '', 'customer_sms_consent' => ''])]);

        $this->assertSame('unknown', $rows[0]['email_consent']);
        $this->assertSame('unknown', $rows[0]['sms_consent']);
    }

    public function testSortedByCreatedAtDescending(): void
    {
        $rows = $this->buildRows([
            $this->order(['name' => '#1001', 'created_at' => '2026-06-01T00:00:00Z']),
            $this->order(['name' => '#1002', 'created_at' => '2026-06-15T00:00:00Z']),
        ]);

        $this->assertSame('#1002', $rows[0]['order_number']);
        $this->assertSame('#1001', $rows[1]['order_number']);
    }
}
