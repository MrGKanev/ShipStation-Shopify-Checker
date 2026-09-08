<?php

namespace Tests\Unit;

use App\Domain\Reports\OrderEditAnalyzer;
use Tests\TestCase;

class OrderEditAnalyzerTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_groups_only_edit_events_with_latest_time_and_four_unique_messages(): void
    {
        $events = [['subject_id' => '1', 'verb' => 'placed', 'created_at' => '2026-01-01', 'message' => 'placed'], ['subject_id' => '', 'verb' => 'edit_complete', 'created_at' => '2026-01-01', 'message' => 'bad']];
        foreach (range(1, 5) as $number) {
            $events[] = ['subject_id' => '1', 'verb' => 'edit_complete', 'created_at' => "2026-01-0{$number}", 'message' => "message {$number}"];
        }
        $events[] = ['subject_id' => '1', 'verb' => 'edit_complete', 'created_at' => '2026-01-06', 'message' => 'message 1'];
        $group = (new OrderEditAnalyzer)->group($events);
        $this->assertSame('2026-01-06', $group['1']['latest_at']);
        $this->assertCount(4, $group['1']['summary']);
    }

    public function test_builds_rows_with_clamped_gap_and_newest_edit_first(): void
    {
        $analyzer = new OrderEditAnalyzer;
        $groups = ['1' => ['latest_at' => '2026-01-01T11:30:00Z', 'summary' => ['Changed']], '2' => ['latest_at' => '2026-01-03T09:00:00Z', 'summary' => []]];
        $orders = ['1' => ['name' => '#1', 'created_at' => '2026-01-01T10:00:00Z'], '2' => ['name' => '#2', 'created_at' => '2026-01-04T10:00:00Z']];
        $rows = $analyzer->rows($orders, $groups);
        $this->assertSame(['#2', '#1'], array_column($rows, 'order_number'));
        $this->assertSame(0, $rows[0]['diff_mins']);
        $this->assertSame(90, $rows[1]['diff_mins']);
    }
}
