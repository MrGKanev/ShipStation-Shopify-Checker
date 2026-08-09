<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\OrderEventAudits;

require_once __DIR__ . '/../../../src/Shopify/GraphQL/Ids.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/EventNormalizer.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/Normalizer.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/OrderEventAudits.php';

/**
 * Tests for OrderEventAudits::groupEditEventsByOrder() and
 * buildEditedOrderRows() - the "Order Edit History" grouping gap in
 * docs/audit-test-coverage-gaps.md (per-order grouping: latest timestamp
 * wins, summary capped at 4 unique messages).
 */
class OrderEventAuditsTest extends TestCase
{
    private function editEvent(string $orderId, string $createdAt, string $message): array
    {
        return [
            'subject_id' => $orderId,
            'verb'       => 'edit_complete',
            'created_at' => $createdAt,
            'message'    => $message,
        ];
    }

    // ── groupEditEventsByOrder ───────────────────────────────────────────────

    public function testLatestTimestampWinsForAnOrderWithMultipleEvents(): void
    {
        $events = [
            $this->editEvent('1001', '2026-06-01T10:00:00Z', 'note was updated'),
            $this->editEvent('1001', '2026-06-05T10:00:00Z', 'item was added'),
            $this->editEvent('1001', '2026-06-03T10:00:00Z', 'item was removed'),
        ];

        $grouped = OrderEventAudits::groupEditEventsByOrder($events);

        $this->assertSame('2026-06-05T10:00:00Z', $grouped['1001']['latest_at']);
    }

    public function testSummaryCappedAtFourUniqueMessages(): void
    {
        $events = [
            $this->editEvent('1001', '2026-06-01T10:00:00Z', 'Message 1'),
            $this->editEvent('1001', '2026-06-01T10:01:00Z', 'Message 2'),
            $this->editEvent('1001', '2026-06-01T10:02:00Z', 'Message 3'),
            $this->editEvent('1001', '2026-06-01T10:03:00Z', 'Message 4'),
            $this->editEvent('1001', '2026-06-01T10:04:00Z', 'Message 5'),
        ];

        $grouped = OrderEventAudits::groupEditEventsByOrder($events);

        $this->assertCount(4, $grouped['1001']['summary']);
        $this->assertSame(['Message 1', 'Message 2', 'Message 3', 'Message 4'], $grouped['1001']['summary']);
    }

    public function testDuplicateMessagesAreNotRepeatedInSummary(): void
    {
        $events = [
            $this->editEvent('1001', '2026-06-01T10:00:00Z', 'note was updated'),
            $this->editEvent('1001', '2026-06-01T10:01:00Z', 'note was updated'),
        ];

        $grouped = OrderEventAudits::groupEditEventsByOrder($events);

        $this->assertCount(1, $grouped['1001']['summary']);
    }

    public function testNonEditEventsAreIgnored(): void
    {
        $events = [[
            'subject_id' => '1001',
            'verb'       => 'placed',
            'created_at' => '2026-06-01T10:00:00Z',
            'message'    => 'Order was placed',
        ]];

        $this->assertSame([], OrderEventAudits::groupEditEventsByOrder($events));
    }

    public function testEventsWithoutSubjectIdAreIgnored(): void
    {
        $events = [$this->editEvent('', '2026-06-01T10:00:00Z', 'note was updated')];

        $this->assertSame([], OrderEventAudits::groupEditEventsByOrder($events));
    }

    public function testDifferentOrdersGroupedSeparately(): void
    {
        $events = [
            $this->editEvent('1001', '2026-06-01T10:00:00Z', 'note was updated'),
            $this->editEvent('1002', '2026-06-02T10:00:00Z', 'item was added'),
        ];

        $grouped = OrderEventAudits::groupEditEventsByOrder($events);

        $this->assertCount(2, $grouped);
        $this->assertArrayHasKey('1001', $grouped);
        $this->assertArrayHasKey('1002', $grouped);
    }

    // ── buildEditedOrderRows ─────────────────────────────────────────────────

    public function testRowJoinsOrderWithGroupedEditEvent(): void
    {
        $ordersById = ['1001' => [
            'name'         => '#1001',
            'created_at'   => '2026-06-01T10:00:00Z',
            'email'        => 'jane@example.com',
            'total_price'  => '50.00',
        ]];
        $byOrder = ['1001' => ['latest_at' => '2026-06-01T11:30:00Z', 'summary' => ['Note was updated']]];

        $rows = OrderEventAudits::buildEditedOrderRows($ordersById, $byOrder);

        $this->assertCount(1, $rows);
        $this->assertSame(90, $rows[0]['diff_mins']);
        $this->assertSame(['Note was updated'], $rows[0]['edit_summary']);
    }

    public function testRowsSortedByEditedAtDescending(): void
    {
        $ordersById = [
            '1001' => ['name' => '#1001', 'created_at' => '2026-06-01T00:00:00Z'],
            '1002' => ['name' => '#1002', 'created_at' => '2026-06-01T00:00:00Z'],
        ];
        $byOrder = [
            '1001' => ['latest_at' => '2026-06-01T05:00:00Z', 'summary' => []],
            '1002' => ['latest_at' => '2026-06-10T05:00:00Z', 'summary' => []],
        ];

        $rows = OrderEventAudits::buildEditedOrderRows($ordersById, $byOrder);

        $this->assertSame(['#1002', '#1001'], array_column($rows, 'order_number'));
    }
}
