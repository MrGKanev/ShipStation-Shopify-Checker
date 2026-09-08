<?php

namespace Tests\Unit;

use App\Domain\Reports\DuplicateAddressAnalyzer;
use Tests\TestCase;

class DuplicateAddressAnalyzerTest extends TestCase
{
    public function test_groups_normalized_addresses_only_across_distinct_emails(): void
    {
        $analyzer = new DuplicateAddressAnalyzer;
        $address = ['address1' => '1 Main St', 'city' => 'Austin', 'zip' => '78701', 'country_code' => 'US'];
        $rows = $analyzer->analyze([$this->order('A@example.com', $address), $this->order('a@example.com', $address), $this->order('B@example.com', [...$address, 'address1' => ' 1 MAIN ST '])]);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['email_count']);
        $this->assertSame(3, $rows[0]['order_count']);
    }

    public function test_excludes_missing_identity_and_sorts_riskiest_clusters_first(): void
    {
        $analyzer = new DuplicateAddressAnalyzer;
        $one = ['address1' => 'One', 'city' => 'X', 'zip' => '1', 'country_code' => 'US'];
        $two = ['address1' => 'Two', 'city' => 'X', 'zip' => '2', 'country_code' => 'US'];
        $rows = $analyzer->analyze([$this->order('', $one), $this->order('a@x.com', []), $this->order('a@x.com', $one), $this->order('b@x.com', $one), $this->order('c@x.com', $two), $this->order('d@x.com', $two), $this->order('e@x.com', $two)]);
        $this->assertSame([3, 2], array_column($rows, 'email_count'));
    }

    /** @param array<string, mixed> $address @return array<string, mixed> */
    private function order(string $email, array $address): array
    {
        return ['id' => '42', 'name' => '#1', 'created_at' => '2026-09-01', 'email' => $email, 'shipping_address' => $address];
    }
}
