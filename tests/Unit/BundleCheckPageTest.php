<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';

/**
 * Tests for ProductInventoryPageLoader::buildBundleCheckRows() via
 * reflection (private method). See "Bundle Check" gap in
 * docs/audit-test-coverage-gaps.md - the most urgent case is proving a
 * *fulfilled* order with a missing bundle component is still flagged,
 * since loadBundleCheck reimplements skip logic without the
 * fulfilled/restocked exclusion that Comparator::compare() has.
 */
class BundleCheckPageTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(ProductInventoryPageLoader::class);
        self::$method = $ref->getMethod('buildBundleCheckRows');
    }

    protected function setUp(): void
    {
        Comparator::setOrderTypesConfig([
            'fallback' => 'Other',
            'rules'    => [[
                'name'       => 'Z1',
                'match'      => 'sku_starts_with',
                'value'      => 'z1-',
                'required_items' => [
                    ['label' => 'Accent Piece', 'match' => 'title_contains', 'value' => 'accent piece'],
                    ['label' => 'Funnel Cap',   'match' => 'title_contains', 'value' => 'funnel cap'],
                    ['label' => 'Burr Set',     'match' => 'title_contains', 'value' => 'burr set'],
                ],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        Comparator::resetOrderTypesConfig();
    }

    private function order(array $lineItems, array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'name'               => '#1001',
            'created_at'         => '2026-06-01T10:00:00Z',
            'email'              => 'jane@example.com',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'cancelled_at'       => null,
            'total_price'        => '99.00',
            'shipping_lines'     => [['title' => 'Standard Shipping']],
            'line_items'         => $lineItems,
        ], $overrides);
    }

    private function completeZ1LineItems(): array
    {
        return [
            ['sku' => 'z1-main', 'title' => 'Z1 Grinder'],
            ['sku' => '', 'title' => 'Accent Piece'],
            ['sku' => '', 'title' => 'Funnel Cap'],
            ['sku' => '', 'title' => 'Burr Set'],
        ];
    }

    private function incompleteZ1LineItems(): array
    {
        return [
            ['sku' => 'z1-main', 'title' => 'Z1 Grinder'],
            ['sku' => '', 'title' => 'Funnel Cap'],
        ];
    }

    private function build(array $orders): array
    {
        return self::$method->invoke(null, $orders);
    }

    // ── The most urgent case: fulfilled orders are still flagged ───────────────

    public function testFulfilledOrderWithMissingComponentIsStillFlagged(): void
    {
        $order = $this->order($this->incompleteZ1LineItems(), ['fulfillment_status' => 'fulfilled']);

        $rows = $this->build([$order]);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Accent Piece', $rows[0]['missing_text']);
        $this->assertStringContainsString('Burr Set', $rows[0]['missing_text']);
    }

    public function testCompleteBundleIsNotFlagged(): void
    {
        $order = $this->order($this->completeZ1LineItems());

        $this->assertSame([], $this->build([$order]));
    }

    // ── Skip logic ───────────────────────────────────────────────────────────

    public function testCancelledOrderIsSkipped(): void
    {
        $order = $this->order($this->incompleteZ1LineItems(), ['cancelled_at' => '2026-06-02T00:00:00Z']);

        $this->assertSame([], $this->build([$order]));
    }

    public function testRefundedOrderIsSkipped(): void
    {
        $order = $this->order($this->incompleteZ1LineItems(), ['financial_status' => 'refunded']);

        $this->assertSame([], $this->build([$order]));
    }

    public function testPendingFinancialStatusIsSkipped(): void
    {
        $order = $this->order($this->incompleteZ1LineItems(), ['financial_status' => 'pending']);

        $this->assertSame([], $this->build([$order]));
    }

    public function testZeroValueOrderIsSkipped(): void
    {
        $order = $this->order($this->incompleteZ1LineItems(), ['total_price' => '0']);

        $this->assertSame([], $this->build([$order]));
    }

    public function testNoShippingLinesOrderIsSkipped(): void
    {
        $order = $this->order($this->incompleteZ1LineItems(), ['shipping_lines' => []]);

        $this->assertSame([], $this->build([$order]));
    }

    public function testRestockedOrderIsStillFlaggedUnlikeMainEngine(): void
    {
        // Comparator::compare() would skip 'restocked' orders; loadBundleCheck
        // deliberately doesn't reimplement that exclusion.
        $order = $this->order($this->incompleteZ1LineItems(), ['fulfillment_status' => 'restocked']);

        $this->assertCount(1, $this->build([$order]));
    }

    public function testRowsSortedByCreatedAtDescending(): void
    {
        $orders = [
            $this->order($this->incompleteZ1LineItems(), ['name' => '#1', 'created_at' => '2026-06-01T00:00:00Z']),
            $this->order($this->incompleteZ1LineItems(), ['name' => '#2', 'created_at' => '2026-06-15T00:00:00Z']),
        ];

        $rows = $this->build($orders);

        $this->assertSame(['#2', '#1'], array_column($rows, 'order_number'));
    }
}
