<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\TagUsageAnalyzer;
use PHPUnit\Framework\TestCase;

class TagUsageAnalyzerTest extends TestCase
{
    public function test_counts_each_tag_once_per_order_and_tracks_the_most_recent_order(): void
    {
        $rows = (new TagUsageAnalyzer)->analyze([
            ['name' => '#1001', 'createdAt' => '2026-05-01T10:00:00Z', 'tags' => ['VIP', 'VIP', 'Wholesale']],
            ['name' => '#1002', 'createdAt' => '2026-08-10T10:00:00Z', 'tags' => ['VIP']],
        ], '2026-06-08');

        $this->assertSame([
            ['tag' => 'VIP', 'count' => 2, 'last_order' => '#1002', 'last_date' => '2026-08-10', 'orphan' => false],
            ['tag' => 'Wholesale', 'count' => 1, 'last_order' => '#1001', 'last_date' => '2026-05-01', 'orphan' => true],
        ], $rows);
    }

    public function test_keeps_case_variants_separate_and_sorts_ties_deterministically(): void
    {
        $rows = (new TagUsageAnalyzer)->analyze([
            ['name' => '#1', 'createdAt' => '2026-09-01', 'tags' => ['vip', 'VIP']],
        ], '2026-06-08');

        $this->assertSame(['VIP', 'vip'], array_column($rows, 'tag'));
    }

    public function test_ignores_blank_and_malformed_values_without_creating_false_orphans(): void
    {
        $rows = (new TagUsageAnalyzer)->analyze([
            ['name' => ['bad'], 'createdAt' => 'invalid', 'tags' => ['', '  ', ['bad'], 'Valid']],
            ['tags' => 'not-an-array'],
        ], '2026-06-08');

        $this->assertSame([
            ['tag' => 'Valid', 'count' => 1, 'last_order' => '', 'last_date' => '', 'orphan' => false],
        ], $rows);
    }
}
