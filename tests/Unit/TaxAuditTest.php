<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';

/**
 * Tests for SimpleScanPageLoader::buildTaxAuditRows() via reflection (private method).
 */
class TaxAuditTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(SimpleScanPageLoader::class);
        self::$method = $ref->getMethod('buildTaxAuditRows');
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 1,
            'name'                => '#1001',
            'created_at'          => '2026-06-01T00:00:00Z',
            'email'               => 'a@example.com',
            'total_price'         => 50.0,
            'total_tax'           => 0.0,
            'financial_status'    => 'paid',
            'customer_tax_exempt' => false,
        ], $overrides);
    }

    private function buildRows(array $orders, float $min = 5): array
    {
        return self::$method->invoke(null, $orders, $min);
    }

    public function testZeroTaxNonExemptOrderIsFlagged(): void
    {
        $rows = $this->buildRows([$this->order()]);

        $this->assertCount(1, $rows);
        $this->assertSame('#1001', $rows[0]['order_number']);
        $this->assertSame(50.0, $rows[0]['total']);
    }

    public function testOrderWithTaxIsNotFlagged(): void
    {
        $rows = $this->buildRows([$this->order(['total_tax' => 4.5])]);

        $this->assertSame([], $rows);
    }

    public function testTaxExemptCustomerIsNotFlagged(): void
    {
        $rows = $this->buildRows([$this->order(['customer_tax_exempt' => true])]);

        $this->assertSame([], $rows);
    }

    public function testOrderBelowMinimumIsExcluded(): void
    {
        $rows = $this->buildRows([$this->order(['total_price' => 2.0])], 5);

        $this->assertSame([], $rows);
    }

    public function testOrderAtExactMinimumIsIncluded(): void
    {
        $rows = $this->buildRows([$this->order(['total_price' => 5.0])], 5);

        $this->assertCount(1, $rows);
    }

    public function testSortedByTotalDescending(): void
    {
        $rows = $this->buildRows([
            $this->order(['name' => '#1001', 'total_price' => 20.0]),
            $this->order(['name' => '#1002', 'total_price' => 80.0]),
        ]);

        $this->assertSame('#1002', $rows[0]['order_number']);
        $this->assertSame('#1001', $rows[1]['order_number']);
    }
}
