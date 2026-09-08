<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\RefundTrackerAnalyzer;
use PHPUnit\Framework\TestCase;

class RefundTrackerAnalyzerTest extends TestCase
{
    public function test_it_classifies_matches_and_calculates_refunded_amounts(): void
    {
        $orders = [
            $this->order('#1001', ['refunds' => [['refund_line_items' => [['subtotal' => 10], ['subtotal' => 5.5]]]]]),
            $this->order('#1002', ['financial_status' => 'partially_refunded', 'refunds' => []]),
            $this->order('#1003'),
        ];
        $shipStation = [
            ['orderNumber' => '1001', 'orderStatus' => 'shipped'],
            ['orderNumber' => '#1002', 'orderStatus' => 'on_hold'],
        ];

        $rows = (new RefundTrackerAnalyzer)->analyze($orders, $shipStation);

        $this->assertSame(['#1002', '#1003', '#1001'], array_column($rows, 'order_number'));
        $this->assertSame(['active', 'missing', 'ok'], array_column($rows, 'risk'));
        $this->assertSame(0.0, $rows[0]['refunded_amount']);
        $this->assertSame(49.99, $rows[1]['refunded_amount']);
        $this->assertSame(15.5, $rows[2]['refunded_amount']);
        $this->assertSame(['shipped'], $rows[2]['shipstation_statuses']);
    }

    public function test_compound_shipstation_number_does_not_match_legacy_contract(): void
    {
        $rows = (new RefundTrackerAnalyzer)->analyze(
            [$this->order('#1001')],
            [['orderNumber' => '1001-B2', 'orderStatus' => 'shipped']],
        );

        $this->assertSame('missing', $rows[0]['risk']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function order(string $number, array $overrides = []): array
    {
        return array_replace([
            'id' => '1',
            'name' => $number,
            'order_number' => ltrim($number, '#'),
            'created_at' => '2026-01-10T12:00:00Z',
            'email' => 'customer@example.com',
            'financial_status' => 'refunded',
            'total_price' => 49.99,
            'refunds' => [],
        ], $overrides);
    }
}
