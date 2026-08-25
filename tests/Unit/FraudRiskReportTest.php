<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/RiskScorer.php';
require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';

/**
 * Tests for OrderPolicyPageLoader::buildFraudRiskRows() via reflection
 * (private method) - the low/medium/high filtering and score-descending
 * sort that back the Fraud Risk Report.
 */
class FraudRiskReportTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderPolicyPageLoader::class);
        self::$method = $ref->getMethod('buildFraudRiskRows');
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'name'              => '#1001',
            'created_at'        => '2026-06-01T00:00:00Z',
            'email'             => 'jane@example.com',
            'financial_status'  => 'paid',
            'total_price'       => 50.0,
        ], $overrides);
    }

    private function buildRows(array $orders): array
    {
        return self::$method->invoke(null, $orders);
    }

    public function testLowRiskOrderIsExcluded(): void
    {
        $rows = $this->buildRows([$this->order()]);

        $this->assertSame([], $rows);
    }

    public function testMediumRiskOrderIsIncludedWithSignalBreakdown(): void
    {
        $rows = $this->buildRows([$this->order([
            'billing_address'  => ['country_code' => 'US'],
            'shipping_address' => ['address1' => 'PO Box 42', 'country_code' => 'CA'],
        ])]);

        $this->assertCount(1, $rows);
        $this->assertSame('medium', $rows[0]['risk']['level']);
        $this->assertGreaterThanOrEqual(21, $rows[0]['risk']['score']);
        $this->assertNotEmpty($rows[0]['risk']['signals']);
    }

    public function testHighRiskOrderFromShopifyRiskLevelIsIncluded(): void
    {
        $rows = $this->buildRows([$this->order(['risk_level' => 'HIGH', 'tags' => 'fraud, review'])]);

        $this->assertCount(1, $rows);
        $this->assertSame('high', $rows[0]['risk']['level']);
    }

    public function testSortedByScoreDescending(): void
    {
        $rows = $this->buildRows([
            $this->order(['name' => '#1001', 'risk_level' => 'HIGH']),
            $this->order(['name' => '#1002', 'risk_level' => 'HIGH', 'tags' => 'fraud']),
        ]);

        $this->assertSame('#1002', $rows[0]['order_number']);
        $this->assertGreaterThan($rows[1]['risk']['score'], $rows[0]['risk']['score']);
    }
}
