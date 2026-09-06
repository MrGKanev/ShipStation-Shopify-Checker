<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\OrderTimelineRiskAnalyzer;
use PHPUnit\Framework\TestCase;

class OrderTimelineRiskAnalyzerTest extends TestCase
{
    public function test_time_to_ship_preserves_legacy_boundaries_and_clamps_negative_values(): void
    {
        $analyzer = $this->analyzer();

        $this->assertNull($analyzer->timeToShip(['created_at' => '2026-06-01T10:00:00Z']));
        $this->assertSame(4, $analyzer->timeToShip($this->order('2026-06-05T10:00:00Z')));
        $this->assertSame(0, $analyzer->timeToShip($this->order('2026-05-30T10:00:00Z')));
        $this->assertNull($analyzer->timeToShip($this->order('not-a-date')));

        $unsortedOrder = $this->order('not-a-date');
        $unsortedOrder['fulfillments'][] = [
            'created_at' => '2026-06-03T10:00:00Z',
            'tracking_number' => 'TRACK-2',
        ];
        $unsortedOrder['fulfillments'][] = [
            'created_at' => '2026-06-02T10:00:00Z',
            'tracking_number' => 'TRACK-1',
        ];
        $this->assertSame(1, $analyzer->timeToShip($unsortedOrder));
    }

    public function test_slow_shipping_thresholds_are_strictly_above_three_and_seven_days(): void
    {
        $analyzer = $this->analyzer();

        $threeDays = $analyzer->analyze($this->order('2026-06-04T10:00:00Z'), []);
        $fourDays = $analyzer->analyze($this->order('2026-06-05T10:00:00Z'), []);
        $sevenDays = $analyzer->analyze($this->order('2026-06-08T10:00:00Z'), []);
        $eightDays = $analyzer->analyze($this->order('2026-06-09T10:00:00Z'), []);

        $this->assertSame([], $threeDays);
        $this->assertSame('warn', $fourDays[0]['level']);
        $this->assertSame('warn', $sevenDays[0]['level']);
        $this->assertSame('danger', $eightDays[0]['level']);
    }

    public function test_established_fulfillment_and_refund_risks_are_reported_once(): void
    {
        $order = $this->order('2026-06-02T10:00:00Z');
        $order['fulfillments'][0]['tracking_number'] = '';
        $order['cancelled_at'] = '2026-06-03T10:00:00Z';
        $order['financial_status'] = 'refunded';
        $order['fulfillments'][] = [
            'created_at' => '2026-06-03T10:00:00Z',
            'tracking_number' => 'TRACK-2',
        ];

        $risks = $this->analyzer()->analyze($order, [
            ['orderStatus' => 'awaiting_shipment'],
            ['orderStatus' => 'on_hold'],
        ]);

        $this->assertSame([
            'Order is cancelled but has fulfillments - items may have already shipped',
            'Order is refunded in Shopify but still active in ShipStation (awaiting_shipment)',
            'Fulfillment exists without a tracking number',
            'Order has 2 separate fulfillments (split shipment)',
        ], array_column($risks, 'message'));
    }

    private function order(string $fulfilledAt): array
    {
        return [
            'created_at' => '2026-06-01T10:00:00Z',
            'fulfillments' => [[
                'created_at' => $fulfilledAt,
                'tracking_number' => 'TRACK-1',
            ]],
        ];
    }

    private function analyzer(): OrderTimelineRiskAnalyzer
    {
        return new OrderTimelineRiskAnalyzer;
    }
}
