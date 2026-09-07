<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\SameIpAnalyzer;
use PHPUnit\Framework\TestCase;

class SameIpAnalyzerTest extends TestCase
{
    public function test_different_emails_at_same_ip_are_grouped(): void
    {
        $rows = $this->analyzer()->analyze([$this->order('a@example.com'), $this->order('b@example.com', ['name' => '#2'])]);
        $this->assertCount(1, $rows);
        $this->assertSame(['ip' => '203.0.113.5', 'email_count' => 2, 'order_count' => 2], array_intersect_key($rows[0], array_flip(['ip', 'email_count', 'order_count'])));
    }

    public function test_same_email_empty_ip_and_different_ips_are_excluded(): void
    {
        $analyzer = $this->analyzer();
        $this->assertSame([], $analyzer->analyze([$this->order('a@example.com'), $this->order('A@EXAMPLE.COM')]));
        $this->assertSame([], $analyzer->analyze([$this->order('a@example.com', ['client_ip' => '']), $this->order('b@example.com', ['client_ip' => ''])]));
        $this->assertSame([], $analyzer->analyze([$this->order('a@example.com'), $this->order('b@example.com', ['client_ip' => '198.51.100.9'])]));
    }

    public function test_groups_sort_by_distinct_email_count_descending(): void
    {
        $rows = $this->analyzer()->analyze([$this->order('a@x.com', ['client_ip' => '1.1.1.1']), $this->order('b@x.com', ['client_ip' => '1.1.1.1']), $this->order('c@x.com', ['client_ip' => '2.2.2.2']), $this->order('d@x.com', ['client_ip' => '2.2.2.2']), $this->order('e@x.com', ['client_ip' => '2.2.2.2'])]);
        $this->assertSame(['2.2.2.2', '1.1.1.1'], array_column($rows, 'ip'));
        $this->assertSame([3, 2], array_column($rows, 'email_count'));
    }

    private function analyzer(): SameIpAnalyzer
    {
        return new SameIpAnalyzer;
    }

    /** @return array<string, mixed> */
    private function order(string $email, array $overrides = []): array
    {
        return [...['id' => '42', 'name' => '#1', 'created_at' => '2026-09-02', 'email' => $email, 'client_ip' => '203.0.113.5', 'total_price' => 50], ...$overrides];
    }
}
