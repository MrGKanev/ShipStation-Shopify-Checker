<?php

namespace Tests\Unit;

use App\Domain\Reports\DisputeAnalyzer;
use Tests\TestCase;

class DisputeAnalyzerTest extends TestCase
{
    public function test_deadlines_and_urgency_sort_match_legacy_contract(): void
    {
        $now = strtotime('2026-06-01T00:00:00Z');
        $rows = (new DisputeAnalyzer)->analyze([['order_name' => '#none', 'evidence_due_by' => null], ['order_name' => '#late', 'evidence_due_by' => '2026-06-10T00:00:00Z'], ['order_name' => '#urgent', 'evidence_due_by' => '2026-06-04T00:00:00Z']], $now);
        $this->assertSame(['#urgent', '#late', '#none'], array_column($rows, 'order_name'));
        $this->assertSame(3, $rows[0]['days_until_due']);
        $this->assertNull($rows[2]['days_until_due']);
    }
}
