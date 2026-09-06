<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\OrderTimelineBuilder;
use PHPUnit\Framework\TestCase;

class OrderTimelineBuilderTest extends TestCase
{
    public function test_builds_every_supported_timeline_source_in_reverse_chronological_order(): void
    {
        $items = $this->builder()->build([
            'created_at' => '2026-06-01T10:00:00Z',
            'processed_at' => '2026-06-01T11:00:00Z',
            'financial_status' => 'paid',
            'total_price' => '49.99',
            'email' => 'buyer@example.com',
            'fulfillments' => [[
                'created_at' => '2026-06-02T10:00:00Z',
                'line_items' => [['id' => 1, 'quantity' => 2], ['id' => 2, 'quantity' => 1]],
                'tracking_company' => 'UPS',
                'tracking_number' => 'TRACK-1',
                'tracking_url' => 'https://tracking.example/TRACK-1',
            ]],
            'refunds' => [[
                'created_at' => '2026-06-03T10:00:00Z',
                'note' => 'Wrong size',
                'transactions' => [
                    ['kind' => 'refund', 'status' => 'success', 'amount' => '10.00'],
                    ['kind' => 'refund', 'status' => 'failure', 'amount' => '99.00'],
                ],
            ]],
            'cancelled_at' => '2026-06-04T10:00:00Z',
            'cancel_reason' => 'customer_request',
            'closed_at' => '2026-06-05T10:00:00Z',
        ], [
            ['verb' => 'placed', 'created_at' => '2026-06-01T10:00:00Z', 'message' => 'Duplicate'],
            ['verb' => 'note_added', 'created_at' => '2026-06-02T12:00:00Z', 'message' => 'Left a note'],
        ], [[
            'orderId' => 555,
            'orderStatus' => 'on_hold',
            'createDate' => '2026-06-01T12:00:00Z',
        ]], [[
            'shipDate' => '2026-06-03T12:00:00Z',
            'carrierCode' => 'ups',
            'trackingNumber' => 'TRACK-SS',
        ]]);

        $this->assertSame([
            'closed', 'cancelled', 'shipstation_shipment', 'refund', 'shopify_event',
            'fulfillment', 'shipstation_order', 'payment', 'order_placed',
        ], array_column($items, 'type'));
        $this->assertSame('$10.00 · Wrong size', $items[3]['detail']);
        $this->assertSame('3 items · UPS', $items[5]['detail']);
        $this->assertSame('https://tracking.example/TRACK-1', $items[5]['url']);
        $this->assertSame('SS ID 555', $items[6]['detail']);
    }

    public function test_invalid_timestamps_and_redundant_events_are_ignored(): void
    {
        $items = $this->builder()->build(
            ['created_at' => 'not-a-date', 'closed_at' => ''],
            [
                ['verb' => 'confirmed', 'created_at' => '2026-01-01T10:00:00Z'],
                ['verb' => 'note_added', 'created_at' => null, 'message' => 'No timestamp'],
            ],
            [['orderStatus' => 'on_hold']],
            [['trackingNumber' => 'TRACK-1']],
        );

        $this->assertSame([], $items);
    }

    public function test_equal_timestamps_keep_a_deterministic_source_order(): void
    {
        $items = $this->builder()->build([
            'created_at' => '2026-06-01T10:00:00Z',
            'closed_at' => '2026-06-01T10:00:00Z',
        ], [], [], []);

        $this->assertSame(['order_placed', 'closed'], array_column($items, 'type'));
    }

    public function test_unsafe_tracking_url_is_not_exposed_as_a_link(): void
    {
        $items = $this->builder()->build([
            'fulfillments' => [[
                'created_at' => '2026-06-01T10:00:00Z',
                'tracking_number' => 'TRACK-1',
                'tracking_url' => 'javascript:alert(1)',
            ]],
        ], [], [], []);

        $this->assertSame('', $items[0]['url']);
        $this->assertSame('TRACK-1', $items[0]['tracking']);
    }

    public function test_shipstation_order_date_is_used_when_create_date_is_blank(): void
    {
        $items = $this->builder()->build([], [], [[
            'orderId' => 555,
            'orderStatus' => 'awaiting_shipment',
            'createDate' => '',
            'orderDate' => '2026-06-01T10:00:00Z',
        ]], []);

        $this->assertSame('2026-06-01T10:00:00Z', $items[0]['timestamp']);
    }

    public function test_money_uses_the_shopify_order_currency_instead_of_a_hardcoded_dollar(): void
    {
        $items = $this->builder()->build([
            'processed_at' => '2026-06-01T10:00:00Z',
            'financial_status' => 'paid',
            'total_price' => '49.99',
            'currency' => 'EUR',
        ], [], [], []);

        $this->assertSame('€49.99', $items[0]['detail']);
    }

    private function builder(): OrderTimelineBuilder
    {
        return new OrderTimelineBuilder;
    }
}
