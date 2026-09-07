<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\TaxAuditAnalyzer;
use Tests\TestCase;

class TaxAuditAnalyzerTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_tax_rules_and_sorting(): void
    {
        $base = ['id' => 1, 'name' => '#1', 'total_price' => 50, 'total_tax' => 0, 'customer_tax_exempt' => false, 'currency' => 'USD'];
        $rows = (new TaxAuditAnalyzer)->analyze([$base, [...$base, 'name' => '#2', 'total_price' => 100], [...$base, 'total_tax' => 1], [...$base, 'customer_tax_exempt' => true], [...$base, 'total_price' => 4]], 5);
        $this->assertSame(['#2', '#1'], array_column($rows, 'number'));
    }
}
