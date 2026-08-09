<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

/**
 * Tests for OrderAnomalyPageLoader::buildAddrChangeRows() via reflection
 * (private method). Covers the "time gap between placement and change"
 * field documented for Address Changes but previously missing from both
 * the row data and the view (docs/audit-test-coverage-gaps.md).
 */
class AddressChangesTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderAnomalyPageLoader::class);
        self::$method = $ref->getMethod('buildAddrChangeRows');
    }

    private function entry(string $createdAt, string $changedAt, array $orderOverrides = []): array
    {
        return [
            'order' => array_merge([
                'id'                 => 1,
                'name'               => '#1001',
                'created_at'         => $createdAt,
                'email'              => 'jane@example.com',
                'total_price'        => '99.00',
                'financial_status'   => 'paid',
                'fulfillment_status' => null,
                'shipping_address'   => ['first_name' => 'Jane', 'last_name' => 'Doe', 'city' => 'Boston'],
            ], $orderOverrides),
            'changed_at' => $changedAt,
        ];
    }

    private function build(array $entries): array
    {
        return self::$method->invoke(null, $entries);
    }

    public function testGapMinsComputedFromPlacementToChange(): void
    {
        $rows = $this->build([
            $this->entry('2026-06-01T10:00:00Z', '2026-06-01T11:30:00Z'),
        ]);

        $this->assertSame(90, $rows[0]['gap_mins']);
    }

    public function testGapMinsZeroWhenTimestampsMissing(): void
    {
        $rows = $this->build([
            $this->entry('', '2026-06-01T11:30:00Z'),
        ]);

        $this->assertSame(0, $rows[0]['gap_mins']);
    }

    public function testGapMinsNeverNegative(): void
    {
        // changed_at somehow before created_at (clock skew / bad data) - should clamp to 0, not go negative.
        $rows = $this->build([
            $this->entry('2026-06-01T12:00:00Z', '2026-06-01T10:00:00Z'),
        ]);

        $this->assertSame(0, $rows[0]['gap_mins']);
    }

    public function testRowIncludesAddressAndOrderFields(): void
    {
        $rows = $this->build([
            $this->entry('2026-06-01T10:00:00Z', '2026-06-01T10:05:00Z'),
        ]);

        $this->assertSame('#1001', $rows[0]['order_number']);
        $this->assertSame('Jane Doe', $rows[0]['addr_name']);
        $this->assertStringContainsString('Boston', $rows[0]['addr_line']);
    }
}
