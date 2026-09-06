<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\CountryMismatchAnalyzer;
use PHPUnit\Framework\TestCase;

class CountryMismatchAnalyzerTest extends TestCase
{
    public function test_flags_different_iso_codes_and_sorts_deterministically(): void
    {
        $result = (new CountryMismatchAnalyzer)->analyze([$this->order('#1', 'us', 'US', '2026-09-01'), $this->order('#2', 'US', 'CA', '2026-09-02'), $this->order('#3', 'GB', 'FR', '2026-09-02')]);
        $this->assertSame(['#3', '#2'], array_column($result['rows'], 'number'));
        $this->assertSame('US', $result['rows'][1]['billing_country']);
        $this->assertSame(0, $result['skipped_missing_country']);
    }

    public function test_missing_or_non_iso_codes_are_counted_not_compared_as_names(): void
    {
        $result = (new CountryMismatchAnalyzer)->analyze([$this->order('#1', '', 'CA'), $this->order('#2', 'United States', 'CA'), $this->order('#3', 'US', '')]);
        $this->assertSame([], $result['rows']);
        $this->assertSame(3, $result['skipped_missing_country']);
    }

    private function order(string $number, string $billing, string $shipping, string $date = '2026-09-01'): array
    {
        return ['id' => 1, 'name' => $number, 'created_at' => $date, 'email' => 'a@example.com', 'total_price' => '10.25', 'currency' => 'USD', 'financial_status' => 'paid', 'billing_address' => ['country_code' => $billing, 'first_name' => 'Ada'], 'shipping_address' => ['country_code' => $shipping]];
    }
}
