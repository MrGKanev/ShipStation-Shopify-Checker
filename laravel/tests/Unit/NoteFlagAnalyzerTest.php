<?php

namespace Tests\Unit;

use App\Domain\Reports\NoteFlagAnalyzer;
use Tests\TestCase;

class NoteFlagAnalyzerTest extends TestCase
{
    public function test_matches_case_insensitive_substrings_and_sorts_newest_first(): void
    {
        $rows = (new NoteFlagAnalyzer)->analyze([['name' => '#1', 'created_at' => '2026-01-01', 'note' => 'Please HOLD'], ['name' => '#2', 'created_at' => '2026-01-03', 'note' => 'do not ship this'], ['name' => '#3', 'note' => 'fine']], ['hold', 'do not ship']);
        $this->assertSame(['#2', '#1'], array_column($rows, 'order_number'));
        $this->assertSame(['do not ship'], $rows[0]['matched']);
    }
}
