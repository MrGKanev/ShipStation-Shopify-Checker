<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';

/**
 * Tests for OrderPolicyPageLoader::dedupeShopifyOrdersById() and
 * buildActiveSsConflictRows() via reflection (private methods). See "Active
 * SS Conflicts" gap in docs/audit-test-coverage-gaps.md.
 */
class ActiveSsConflictsTest extends TestCase
{
    private static \ReflectionMethod $dedupe;
    private static \ReflectionMethod $build;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderPolicyPageLoader::class);
        self::$dedupe = $ref->getMethod('dedupeShopifyOrdersById');
        self::$build  = $ref->getMethod('buildActiveSsConflictRows');
    }

    private function shopifyOrder(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'order_number'       => '1001',
            'name'               => '#1001',
            'created_at'         => '2026-06-01T10:00:00Z',
            'email'              => 'jane@example.com',
            'total_price'        => '99.00',
            'financial_status'   => 'refunded',
            'cancelled_at'       => null,
        ], $overrides);
    }

    private function ssOrder(array $overrides = []): array
    {
        return array_merge([
            'orderId'     => 555,
            'orderNumber' => '1001',
            'orderStatus' => 'awaiting_shipment',
            'orderDate'   => '2026-06-01T10:00:00Z',
            'orderTotal'  => 99.00,
        ], $overrides);
    }

    // ── dedupeShopifyOrdersById ──────────────────────────────────────────────

    public function testDedupesSameOrderAppearingInBothRefundedAndCancelled(): void
    {
        $order = $this->shopifyOrder();

        $result = self::$dedupe->invoke(null, [$order], [$order]);

        $this->assertCount(1, $result);
    }

    public function testKeepsDistinctOrders(): void
    {
        $a = $this->shopifyOrder(['id' => 1]);
        $b = $this->shopifyOrder(['id' => 2]);

        $result = self::$dedupe->invoke(null, [$a], [$b]);

        $this->assertCount(2, $result);
    }

    // ── buildActiveSsConflictRows ────────────────────────────────────────────

    public function testRefundedOrderMatchingActiveSsOrderIsFlagged(): void
    {
        $shopifyRows = [1 => $this->shopifyOrder(['financial_status' => 'refunded'])];
        $activeSs    = [$this->ssOrder()];

        $rows = self::$build->invoke(null, $shopifyRows, $activeSs);

        $this->assertCount(1, $rows);
        $this->assertSame('refunded', $rows[0]['issue']);
        $this->assertSame(555, $rows[0]['ss_order_id']);
    }

    public function testCancelledOrderIssueLabelIsCancelledNotFinancialStatus(): void
    {
        $shopifyRows = [1 => $this->shopifyOrder(['cancelled_at' => '2026-06-02T00:00:00Z', 'financial_status' => 'paid'])];
        $activeSs    = [$this->ssOrder()];

        $rows = self::$build->invoke(null, $shopifyRows, $activeSs);

        $this->assertCount(1, $rows);
        $this->assertSame('cancelled', $rows[0]['issue']);
    }

    public function testNoMatchingActiveSsOrderProducesNoRow(): void
    {
        $shopifyRows = [1 => $this->shopifyOrder(['order_number' => '9999', 'name' => '#9999'])];
        $activeSs    = [$this->ssOrder(['orderNumber' => '1001'])];

        $rows = self::$build->invoke(null, $shopifyRows, $activeSs);

        $this->assertSame([], $rows);
    }

    public function testRowsSortedByCreatedAtDescending(): void
    {
        $shopifyRows = [
            1 => $this->shopifyOrder(['id' => 1, 'order_number' => '1001', 'name' => '#1001', 'created_at' => '2026-06-01T00:00:00Z']),
            2 => $this->shopifyOrder(['id' => 2, 'order_number' => '1002', 'name' => '#1002', 'created_at' => '2026-06-15T00:00:00Z']),
        ];
        $activeSs = [
            $this->ssOrder(['orderNumber' => '1001']),
            $this->ssOrder(['orderNumber' => '1002']),
        ];

        $rows = self::$build->invoke(null, $shopifyRows, $activeSs);

        $this->assertSame(['#1002', '#1001'], array_column($rows, 'order_number'));
    }
}
