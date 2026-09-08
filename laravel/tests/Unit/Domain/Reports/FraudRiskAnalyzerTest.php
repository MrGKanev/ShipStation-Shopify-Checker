<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Orders\OrderRiskScorer;
use App\Domain\Reports\FraudRiskAnalyzer;
use PHPUnit\Framework\TestCase;

class FraudRiskAnalyzerTest extends TestCase
{
    public function test_low_risk_orders_are_excluded_and_medium_risk_keeps_signal_breakdown(): void
    {
        $rows = $this->analyzer()->analyze([
            ['id' => '1', 'name' => '#1', 'email' => 'buyer@example.com'],
            ['id' => '2', 'name' => '#2', 'email' => 'buyer@mailinator.com'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('#2', $rows[0]['number']);
        $this->assertSame(['score' => 30, 'level' => 'medium', 'signals' => ['Disposable/invalid email']], $rows[0]['risk']);
    }

    public function test_shopify_high_risk_is_included_and_rows_are_sorted_by_score_descending(): void
    {
        $rows = $this->analyzer()->analyze([
            ['id' => '40', 'name' => '#40', 'risk_level' => 'HIGH'],
            ['id' => '55', 'name' => '#55', 'email' => 'invalid', 'billing_address' => ['country_code' => 'US'], 'shipping_address' => ['country_code' => 'CA']],
        ]);

        $this->assertSame(['#55', '#40'], array_column($rows, 'number'));
        $this->assertSame('Shopify HIGH risk level', $rows[1]['risk']['signals'][0]);
    }

    private function analyzer(): FraudRiskAnalyzer
    {
        return new FraudRiskAnalyzer(new OrderRiskScorer);
    }
}
