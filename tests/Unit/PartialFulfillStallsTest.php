<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';

/**
 * Tests for SimpleScanPageLoader::buildPartialFulfillRows() via reflection
 * (private method). See "Partial Fulfillment Stalls" gap in
 * docs/audit-test-coverage-gaps.md.
 */
class PartialFulfillStallsTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(SimpleScanPageLoader::class);
        self::$method = $ref->getMethod('buildPartialFulfillRows');
    }

    private const NOW = 1_800_000_000;

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'name'              => '#1001',
            'created_at'        => gmdate('c', self::NOW - 10 * 86400),
            'email'             => 'jane@example.com',
            'total_price'       => '99.00',
            'financial_status'  => 'paid',
            'fulfillments'      => [],
            'line_items'        => [['name' => 'Widget', 'sku' => 'W-1', 'fulfillable_quantity' => 2]],
        ], $overrides);
    }

    private function build(array $orders, int $threshold): array
    {
        return self::$method->invoke(null, $orders, $threshold, self::NOW);
    }

    public function testOrderNeverFulfilledStalledFromOrderDate(): void
    {
        $rows = $this->build([$this->order()], 7);

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['days_stalled']);
    }

    public function testOrderStalledFromLastFulfillmentDate(): void
    {
        $order = $this->order([
            'created_at'   => gmdate('c', self::NOW - 30 * 86400),
            'fulfillments' => [['created_at' => gmdate('c', self::NOW - 8 * 86400)]],
        ]);

        $rows = $this->build([$order], 7);

        $this->assertCount(1, $rows);
        $this->assertSame(8, $rows[0]['days_stalled']);
    }

    public function testUnderThresholdIsExcluded(): void
    {
        $order = $this->order(['created_at' => gmdate('c', self::NOW - 2 * 86400)]);

        $this->assertSame([], $this->build([$order], 7));
    }

    public function testExactlyAtThresholdIsFlagged(): void
    {
        $order = $this->order(['created_at' => gmdate('c', self::NOW - 7 * 86400)]);

        $rows = $this->build([$order], 7);

        $this->assertCount(1, $rows, 'condition is "< threshold" excludes, so days === threshold should flag');
    }

    public function testNoFulfillableLineItemsIsExcluded(): void
    {
        $order = $this->order(['line_items' => [['name' => 'Widget', 'sku' => 'W-1', 'fulfillable_quantity' => 0]]]);

        $this->assertSame([], $this->build([$order], 7));
    }

    public function testRowsSortedByDaysStalledDescending(): void
    {
        $rows = $this->build([
            $this->order(['name' => '#1', 'created_at' => gmdate('c', self::NOW - 8 * 86400)]),
            $this->order(['name' => '#2', 'created_at' => gmdate('c', self::NOW - 20 * 86400)]),
        ], 7);

        $this->assertSame(['#2', '#1'], array_column($rows, 'order_number'));
    }
}
