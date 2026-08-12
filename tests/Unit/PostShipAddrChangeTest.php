<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';

/**
 * Tests for FulfillmentIssuePageLoader::buildPostShipAddrChangeRows() via
 * reflection (private method). Previously lived inline in
 * loadPostShipAddrChange() with zero coverage beyond the wrapper's
 * missing-credentials test.
 */
class PostShipAddrChangeTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        self::$method = $ref->getMethod('buildPostShipAddrChangeRows');
    }

    private function entry(array $overrides = []): array
    {
        return array_merge([
            'order' => [
                'id' => 111, 'name' => '#1001', 'created_at' => '2026-06-01T10:00:00Z',
                'email' => 'jane@example.com', 'total_price' => '49.99',
                'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled',
                'shipping_address' => [
                    'first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '123 Main St',
                    'city' => 'Anytown', 'province_code' => 'CA', 'zip' => '90210', 'country_code' => 'US',
                ],
            ],
            'fulfillment_at' => '2026-06-02T10:00:00Z',
            'changed_at'     => '2026-06-02T12:30:00Z',
        ], $overrides);
    }

    private function buildRows(array $entries): array
    {
        return self::$method->invoke(null, $entries);
    }

    public function testComputesMinutesAfterShipFromFulfillmentToChange(): void
    {
        $rows = $this->buildRows([$this->entry([
            'fulfillment_at' => '2026-06-02T10:00:00Z',
            'changed_at'     => '2026-06-02T12:30:00Z',
        ])]);

        $this->assertSame(150, $rows[0]['mins_after_ship']);
    }

    public function testZeroMinutesAfterShipWhenTimestampsMissing(): void
    {
        $rows = $this->buildRows([$this->entry(['fulfillment_at' => '', 'changed_at' => '2026-06-02T12:30:00Z'])]);

        $this->assertSame(0, $rows[0]['mins_after_ship']);
    }

    public function testBuildsAddressLineFromParts(): void
    {
        $rows = $this->buildRows([$this->entry()]);

        $this->assertSame('123 Main St, Anytown, CA, 90210, US', $rows[0]['addr_line']);
        $this->assertSame('Jane Doe', $rows[0]['addr_name']);
    }

    public function testMissingAddressYieldsEmptyLineAndName(): void
    {
        $entry = $this->entry();
        $entry['order']['shipping_address'] = null;

        $rows = $this->buildRows([$entry]);

        $this->assertSame('', $rows[0]['addr_line']);
        $this->assertSame('', $rows[0]['addr_name']);
    }

    public function testSortedByChangedAtDescending(): void
    {
        $entries = [
            $this->entry(['order' => ['name' => '#OLD'] + $this->entry()['order'], 'changed_at' => '2026-06-01T10:00:00Z']),
            $this->entry(['order' => ['name' => '#NEW'] + $this->entry()['order'], 'changed_at' => '2026-06-10T10:00:00Z']),
        ];

        $rows = $this->buildRows($entries);

        $this->assertSame(['#NEW', '#OLD'], array_column($rows, 'order_number'));
    }
}
