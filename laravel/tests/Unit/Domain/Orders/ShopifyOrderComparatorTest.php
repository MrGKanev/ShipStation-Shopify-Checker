<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\ShopifyOrderComparator;
use PHPUnit\Framework\TestCase;

class ShopifyOrderComparatorTest extends TestCase
{
    public function test_identical_orders_have_no_differences(): void
    {
        $order = $this->order();

        $result = $this->comparator()->compare($order, $order, '1001', '1001', false, null, null);

        $this->assertSame(0, $result['difference_count']);
        $this->assertCount(10, $result['rows']);
        $this->assertSame([], array_filter($result['rows'], fn (array $row): bool => $row['different']));
    }

    public function test_legacy_fields_and_shipstation_statuses_are_compared(): void
    {
        $orderB = $this->order([
            'name' => '#1002',
            'email' => 'other@example.com',
            'fulfillment_status' => null,
            'total_price' => '95',
            'line_items' => [['quantity' => 1, 'title' => 'Other', 'variant_title' => null]],
            'shipping_address' => ['address1' => 'Other Street'],
            'tags' => ['priority'],
            'note' => 'Changed',
        ]);

        $result = $this->comparator()->compare($this->order(), $orderB, '1001', '1002', true, 'shipped', 'on_hold');
        $rows = array_column($result['rows'], null, 'label');

        $this->assertTrue($rows['Order #']['different']);
        $this->assertTrue($rows['Email']['different']);
        $this->assertSame('unfulfilled', $rows['Fulfillment status']['b']);
        $this->assertSame('$95.00', $rows['Total']['b']);
        $this->assertSame('1× Other', $rows['Items']['b']);
        $this->assertSame('Other Street', $rows['Ship to']['b']);
        $this->assertSame('shipped', $rows['ShipStation status']['a']);
        $this->assertSame('on_hold', $rows['ShipStation status']['b']);
    }

    public function test_missing_order_is_rendered_without_dereferencing_it(): void
    {
        $result = $this->comparator()->compare(null, $this->order(), '9999', '1001', true, 'Not found', 'shipped');
        $rows = array_column($result['rows'], null, 'label');

        $this->assertSame('#9999', $rows['Order #']['a']);
        $this->assertSame('—', $rows['Email']['a']);
        $this->assertSame('Not found', $rows['ShipStation status']['a']);
        $this->assertGreaterThan(0, $result['difference_count']);
    }

    /** @param array<string, mixed> $overrides */
    private function order(array $overrides = []): array
    {
        return [
            'name' => '#1001',
            'created_at' => '2026-09-01T10:00:00Z',
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'total_price' => '100.00',
            'line_items' => [['quantity' => 2, 'title' => 'Widget', 'variant_title' => 'Blue']],
            'shipping_address' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '1 Main Street',
                'city' => 'Sofia', 'province_code' => 'SOF', 'zip' => '1000', 'country_code' => 'BG',
            ],
            'tags' => ['vip', 'priority'],
            'note' => 'Handle carefully',
            ...$overrides,
        ];
    }

    private function comparator(): ShopifyOrderComparator
    {
        return new ShopifyOrderComparator;
    }
}
