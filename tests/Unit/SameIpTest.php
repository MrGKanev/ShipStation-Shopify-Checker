<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';

/**
 * Tests for OrderPolicyPageLoader::buildSameIpRows() via reflection
 * (private method) - groups paid orders by client IP and keeps only IPs
 * used by 2+ distinct customer emails.
 */
class SameIpTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderPolicyPageLoader::class);
        self::$method = $ref->getMethod('buildSameIpRows');
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id'         => 1,
            'name'       => '#1001',
            'created_at' => '2026-06-01T00:00:00Z',
            'email'      => 'a@example.com',
            'client_ip'  => '203.0.113.5',
            'total_price'=> 50.0,
        ], $overrides);
    }

    private function buildRows(array $orders): array
    {
        return self::$method->invoke(null, $orders);
    }

    public function testDifferentEmailsAtSameIpAreGrouped(): void
    {
        $rows = $this->buildRows([
            $this->order(['name' => '#1001', 'email' => 'a@example.com']),
            $this->order(['name' => '#1002', 'email' => 'b@example.com']),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('203.0.113.5', $rows[0]['ip']);
        $this->assertSame(2, $rows[0]['email_count']);
        $this->assertSame(2, $rows[0]['order_count']);
    }

    public function testSameEmailRepeatedAtSameIpIsNotFlagged(): void
    {
        $rows = $this->buildRows([
            $this->order(['name' => '#1001', 'email' => 'a@example.com']),
            $this->order(['name' => '#1002', 'email' => 'a@example.com']),
        ]);

        $this->assertSame([], $rows);
    }

    public function testEmptyClientIpIsSkipped(): void
    {
        $rows = $this->buildRows([
            $this->order(['client_ip' => '']),
            $this->order(['email' => 'b@example.com', 'client_ip' => '']),
        ]);

        $this->assertSame([], $rows);
    }

    public function testDifferentIpsAreNotGroupedTogether(): void
    {
        $rows = $this->buildRows([
            $this->order(['email' => 'a@example.com', 'client_ip' => '203.0.113.5']),
            $this->order(['email' => 'b@example.com', 'client_ip' => '198.51.100.9']),
        ]);

        $this->assertSame([], $rows);
    }

    public function testSortedByEmailCountDescending(): void
    {
        $rows = $this->buildRows([
            $this->order(['email' => 'a@example.com', 'client_ip' => '1.1.1.1']),
            $this->order(['email' => 'b@example.com', 'client_ip' => '1.1.1.1']),
            $this->order(['email' => 'c@example.com', 'client_ip' => '2.2.2.2']),
            $this->order(['email' => 'd@example.com', 'client_ip' => '2.2.2.2']),
            $this->order(['email' => 'e@example.com', 'client_ip' => '2.2.2.2']),
        ]);

        $this->assertSame('2.2.2.2', $rows[0]['ip']);
        $this->assertSame(3, $rows[0]['email_count']);
        $this->assertSame('1.1.1.1', $rows[1]['ip']);
    }
}
