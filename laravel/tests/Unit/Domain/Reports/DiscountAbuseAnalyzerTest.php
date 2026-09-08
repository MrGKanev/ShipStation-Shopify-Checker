<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\DiscountAbuseAnalyzer;
use PHPUnit\Framework\TestCase;

class DiscountAbuseAnalyzerTest extends TestCase
{
    public function test_exact_threshold_flags_and_same_email_is_deduplicated(): void
    {
        $analyzer = new DiscountAbuseAnalyzer;
        $rows = $analyzer->analyze([$this->order('a@x.com'), $this->order('b@x.com'), $this->order('c@x.com')], 3);
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['email_count']);
        $this->assertSame([], $analyzer->analyze([$this->order('a@x.com'), $this->order('a@x.com'), $this->order('a@x.com')], 3));
    }

    public function test_below_threshold_and_different_addresses_are_excluded(): void
    {
        $analyzer = new DiscountAbuseAnalyzer;
        $this->assertSame([], $analyzer->analyze([$this->order('a@x.com'), $this->order('b@x.com')], 3));
        $this->assertSame([], $analyzer->analyze([$this->order('a@x.com'), $this->order('b@x.com'), $this->order('c@x.com', ['shipping_address' => [...$this->address(), 'address1' => '999 Other Ave']])], 3));
    }

    public function test_groups_normalize_code_email_and_address_case_and_sort_by_email_then_orders(): void
    {
        $orders = [$this->order('A@x.com'), $this->order('b@x.com', ['discount_codes' => [['code' => ' save10 ']], 'shipping_address' => [...$this->address(), 'address1' => '123 MAIN ST']]), $this->order('c@x.com'), $this->order('d@x.com', ['discount_codes' => [['code' => 'VIP']], 'shipping_address' => [...$this->address(), 'address1' => 'Other Street']]), $this->order('e@x.com', ['discount_codes' => [['code' => 'VIP']], 'shipping_address' => [...$this->address(), 'address1' => 'Other Street']])];
        $rows = (new DiscountAbuseAnalyzer)->analyze($orders, 2);
        $this->assertSame(['SAVE10', 'VIP'], array_column($rows, 'code'));
        $this->assertSame(3, $rows[0]['order_count']);
    }

    /** @return array<string, mixed> */
    private function order(string $email, array $overrides = []): array
    {
        return [...['id' => '42', 'name' => '#1', 'email' => $email, 'created_at' => '2026-09-02', 'total_price' => '50', 'currency' => 'USD', 'discount_codes' => [['code' => 'SAVE10']], 'shipping_address' => $this->address()], ...$overrides];
    }

    /** @return array<string, string> */
    private function address(): array
    {
        return ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '123 Main St', 'city' => 'Boston', 'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US'];
    }
}
