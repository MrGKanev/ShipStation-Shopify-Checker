<?php

namespace Tests\Unit;

use App\Domain\Reports\AddressChangeAnalyzer;
use PHPUnit\Framework\TestCase;

class AddressChangeAnalyzerTest extends TestCase
{
    public function test_selects_only_address_events_and_keeps_latest_change(): void
    {
        $events = [
            ['subject_id' => 1, 'verb' => 'edit_complete', 'message' => 'Line item was edited', 'created_at' => '2026-01-04'],
            ['subject_id' => 1, 'verb' => 'edit_complete', 'message' => 'Shipping address was updated', 'created_at' => '2026-01-02'],
            ['subject_id' => 1, 'verb' => 'shipping_address_updated', 'message' => '', 'created_at' => '2026-01-03'],
            ['subject_id' => '', 'verb' => 'edit_complete', 'message' => 'Shipping address was updated', 'created_at' => '2026-01-05'],
        ];

        $this->assertSame(['1' => '2026-01-03'], (new AddressChangeAnalyzer)->latestChanges($events));
    }

    public function test_builds_legacy_fields_gap_and_current_address_newest_first(): void
    {
        $orders = [
            '1' => ['name' => '#1', 'created_at' => '2026-01-01T10:00:00Z', 'email' => 'jane@example.com', 'total_price' => '99.50', 'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'shipping_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '1 Main St', 'city' => 'Boston', 'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US']],
            '2' => ['name' => '#2', 'created_at' => '2026-01-05T10:00:00Z', 'shipping_address' => null],
        ];
        $rows = (new AddressChangeAnalyzer)->rows($orders, ['1' => '2026-01-01T11:30:00Z', '2' => '2026-01-04T10:00:00Z']);

        $this->assertSame(['#2', '#1'], array_column($rows, 'order_number'));
        $this->assertSame(0, $rows[0]['gap_mins']);
        $this->assertSame(90, $rows[1]['gap_mins']);
        $this->assertSame('Jane Doe', $rows[1]['addr_name']);
        $this->assertSame('1 Main St, Boston, MA, 02101, US', $rows[1]['addr_line']);
    }
}
