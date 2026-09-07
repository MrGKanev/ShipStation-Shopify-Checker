<?php

namespace Tests\Unit;

use App\Domain\Reports\TagPolicyAnalyzer;
use Tests\TestCase;

class TagPolicyAnalyzerTest extends TestCase
{
    public function test_configured_state_and_required_rule_semantics(): void
    {
        $analyzer = new TagPolicyAnalyzer;
        $this->assertFalse($analyzer->hasRules([]));
        $this->assertTrue($analyzer->hasRules(['required' => [['when' => ['vip'], 'must_have' => ['priority']]]]));
        $config = ['required' => [['name' => 'VIP needs priority', 'when' => ['vip', 'wholesale'], 'must_have' => ['priority']]]];
        $this->assertSame([], $analyzer->analyze([$this->order(['vip'])], $config));
        $this->assertSame([], $analyzer->analyze([$this->order(['VIP', 'wholesale', 'PRIORITY'])], $config));
        $rows = $analyzer->analyze([$this->order(' VIP, wholesale ')], $config);
        $this->assertCount(1, $rows);
        $this->assertSame('required', $rows[0]['violations'][0]['type']);
        $this->assertSame('Missing: priority', $rows[0]['violations'][0]['detail']);
    }

    public function test_forbidden_rules_require_the_complete_combination(): void
    {
        $analyzer = new TagPolicyAnalyzer;
        $config = ['forbidden' => [['name' => 'conflict', 'tags' => ['wholesale', 'retail']]]];
        $this->assertSame([], $analyzer->analyze([$this->order(['wholesale'])], $config));
        $rows = $analyzer->analyze([$this->order(['Wholesale', ' retail ', ''])], $config);
        $this->assertCount(1, $rows);
        $this->assertSame('forbidden', $rows[0]['violations'][0]['type']);
    }

    public function test_invalid_rules_are_ignored_and_rows_are_newest_first(): void
    {
        $analyzer = new TagPolicyAnalyzer;
        $config = ['required' => ['invalid', ['when' => [], 'must_have' => ['x']], ['when' => ['vip'], 'must_have' => ['priority']]], 'forbidden' => [['tags' => ['only-one']]]];
        $rows = $analyzer->analyze([$this->order(['vip'], ['name' => '#1', 'created_at' => '2026-06-01']), $this->order(['vip'], ['name' => '#2', 'created_at' => '2026-06-03'])], $config);
        $this->assertSame(['#2', '#1'], array_column($rows, 'order_number'));
    }

    /** @return array<string, mixed> */
    private function order(array|string $tags, array $overrides = []): array
    {
        return array_merge(['id' => '1', 'name' => '#1001', 'created_at' => '2026-06-01T10:00:00Z', 'tags' => $tags], $overrides);
    }
}
