<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';

/**
 * Tests for SimpleScanPageLoader::buildReturnRows() via reflection (private
 * method). This logic previously lived inline in loadReturns() and had zero
 * test coverage - unlike every other check in this file (buildEmailCheckRows,
 * buildHvOrderRows, buildCountryMismatchRows, buildPartialFulfillRows), which
 * all had dedicated reflection-based test coverage.
 */
class ReturnRmaTrackerTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(SimpleScanPageLoader::class);
        self::$method = $ref->getMethod('buildReturnRows');
    }

    private function order(array $refunds, array $overrides = []): array
    {
        return array_merge([
            'id'                => 111,
            'name'              => '#1001',
            'created_at'        => '2026-06-01T10:00:00Z',
            'email'             => 'jane@example.com',
            'financial_status'  => 'refunded',
            'refunds'           => $refunds,
        ], $overrides);
    }

    private function refund(array $lineItems, array $overrides = []): array
    {
        return array_merge([
            'created_at'         => '2026-06-05T10:00:00Z',
            'total_refunded'     => '10.00',
            'note'               => '',
            'refund_line_items'  => $lineItems,
        ], $overrides);
    }

    private function refundLineItem(string $sku, int $qty, float $subtotal, array $overrides = []): array
    {
        return [
            'line_item' => array_merge(['sku' => $sku, 'name' => 'Widget'], $overrides),
            'quantity'  => $qty,
            'subtotal'  => $subtotal,
        ];
    }

    private function buildRows(array $orders): array
    {
        return self::$method->invoke(null, $orders);
    }

    public function testOneRowPerRefundNotPerOrder(): void
    {
        $order = $this->order([$this->refund([]), $this->refund([])]);

        [$rows] = $this->buildRows([$order]);

        $this->assertCount(2, $rows);
        $this->assertSame('#1001', $rows[0]['order_number']);
        $this->assertSame('#1001', $rows[1]['order_number']);
    }

    public function testRowIncludesReasonFromRefundNote(): void
    {
        $order = $this->order([$this->refund([], ['note' => ' Damaged in transit '])]);

        [$rows] = $this->buildRows([$order]);

        $this->assertSame('Damaged in transit', $rows[0]['reason']);
    }

    public function testItemsCarrySkuQuantityAndSubtotal(): void
    {
        $order = $this->order([$this->refund([
            $this->refundLineItem('SKU1', 2, 19.98),
        ])]);

        [$rows] = $this->buildRows([$order]);

        $this->assertSame([
            ['name' => 'Widget', 'sku' => 'SKU1', 'quantity' => 2, 'subtotal' => 19.98],
        ], $rows[0]['items']);
    }

    public function testSkuStatAggregatesUnitsOrdersAndRevenueAcrossRefunds(): void
    {
        $orders = [
            $this->order([$this->refund([$this->refundLineItem('SKU1', 2, 20.0)])]),
            $this->order([$this->refund([$this->refundLineItem('SKU1', 1, 10.0)])], ['id' => 222, 'name' => '#1002']),
        ];

        [, $skuStat] = $this->buildRows($orders);

        $this->assertSame('SKU1', $skuStat[0]['sku']);
        $this->assertSame(3, $skuStat[0]['units']);
        $this->assertSame(2, $skuStat[0]['orders']);
        $this->assertSame(30.0, $skuStat[0]['revenue']);
    }

    public function testSkuStatIgnoresBlankSkuAndZeroQuantity(): void
    {
        $order = $this->order([$this->refund([
            $this->refundLineItem('', 1, 5.0),
            $this->refundLineItem('SKU1', 0, 5.0),
        ])]);

        [, $skuStat] = $this->buildRows([$order]);

        $this->assertSame([], $skuStat);
    }

    public function testSkuStatSortedByUnitsDescending(): void
    {
        $orders = [
            $this->order([$this->refund([$this->refundLineItem('LOW', 1, 5.0)])]),
            $this->order([$this->refund([$this->refundLineItem('HIGH', 5, 50.0)])], ['id' => 222, 'name' => '#1002']),
        ];

        [, $skuStat] = $this->buildRows($orders);

        $this->assertSame(['HIGH', 'LOW'], array_column($skuStat, 'sku'));
    }

    public function testRowsSortedByRefundDateDescending(): void
    {
        $orders = [
            $this->order([$this->refund([], ['created_at' => '2026-06-01T10:00:00Z'])], ['name' => '#OLD']),
            $this->order([$this->refund([], ['created_at' => '2026-06-10T10:00:00Z'])], ['name' => '#NEW']),
        ];

        [$rows] = $this->buildRows($orders);

        $this->assertSame(['#NEW', '#OLD'], array_column($rows, 'order_number'));
    }
}
