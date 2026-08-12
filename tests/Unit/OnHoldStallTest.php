<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';

/**
 * Tests for FulfillmentIssuePageLoader::buildOnHoldStallRows() via
 * reflection (private method). Previously lived inline in loadOnHoldStall()
 * with zero coverage beyond the wrapper's initial-state test.
 */
class OnHoldStallTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        self::$method = $ref->getMethod('buildOnHoldStallRows');
    }

    private function node(array $overrides = []): array
    {
        return array_merge([
            'order' => array_merge([
                'legacyResourceId' => '111',
                'name'             => '#1001',
                'createdAt'        => '2026-06-01T10:00:00Z',
                'email'            => 'jane@example.com',
                'totalPriceSet'    => ['shopMoney' => ['amount' => '49.99']],
                'displayFinancialStatus'   => 'PAID',
                'displayFulfillmentStatus' => 'ON_HOLD',
            ], $overrides['order'] ?? []),
            'fulfillmentHolds' => $overrides['fulfillmentHolds'] ?? [['reason' => 'FRAUD_RISK', 'reasonNotes' => 'Review needed']],
        ]);
    }

    private function buildRows(array $nodes, int $now): array
    {
        return self::$method->invoke(null, $nodes, $now);
    }

    public function testComputesDaysWaitingFromOrderCreation(): void
    {
        $now = strtotime('2026-06-06T10:00:00Z');
        $rows = $this->buildRows([$this->node(['order' => ['createdAt' => '2026-06-01T10:00:00Z']])], $now);

        $this->assertSame(5, $rows[0]['days_waiting']);
    }

    public function testSurfacesFirstHoldReasonAndNotes(): void
    {
        $rows = $this->buildRows([$this->node(['fulfillmentHolds' => [
            ['reason' => 'FRAUD_RISK', 'reasonNotes' => 'Review needed'],
            ['reason' => 'INVENTORY_OUT_OF_STOCK', 'reasonNotes' => 'second'],
        ]])], time());

        $this->assertSame('FRAUD_RISK', $rows[0]['hold_reason']);
        $this->assertSame('Review needed', $rows[0]['hold_notes']);
    }

    public function testNoHoldsYieldsEmptyReasonAndNotes(): void
    {
        $rows = $this->buildRows([$this->node(['fulfillmentHolds' => []])], time());

        $this->assertSame('', $rows[0]['hold_reason']);
        $this->assertSame('', $rows[0]['hold_notes']);
    }

    public function testSortedByDaysWaitingDescending(): void
    {
        $now = strtotime('2026-06-20T10:00:00Z');
        $nodes = [
            $this->node(['order' => ['name' => '#RECENT', 'createdAt' => '2026-06-18T10:00:00Z']]),
            $this->node(['order' => ['name' => '#OLD', 'createdAt' => '2026-06-01T10:00:00Z']]),
        ];

        $rows = $this->buildRows($nodes, $now);

        $this->assertSame(['#OLD', '#RECENT'], array_column($rows, 'order_number'));
    }

    public function testMissingCreatedAtYieldsZeroDaysWaiting(): void
    {
        $rows = $this->buildRows([$this->node(['order' => ['createdAt' => '']])], time());

        $this->assertSame(0, $rows[0]['days_waiting']);
    }
}
