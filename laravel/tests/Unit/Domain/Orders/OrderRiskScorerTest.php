<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\OrderRiskScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderRiskScorerTest extends TestCase
{
    public function test_clean_order_returns_zero_low_risk_without_signals(): void
    {
        $result = $this->scorer()->score([
            'email' => 'buyer@example.com',
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => [
                'country_code' => 'US',
                'phone' => '+1 555 0100',
                'address1' => '123 Main Street',
            ],
            'total_price' => '200.00',
            'financial_status' => 'paid',
            'tags' => ['vip'],
            'risk_level' => 'MEDIUM',
        ]);

        $this->assertSame([
            'score' => 0,
            'level' => 'low',
            'signals' => [],
        ], $result);
    }

    /** @param array<string, mixed> $order */
    #[DataProvider('individualRiskSignals')]
    public function test_each_legacy_signal_returns_its_exact_weight(array $order, string $label, int $points): void
    {
        $result = $this->scorer()->score($order);

        $this->assertSame($points, $result['score']);
        $this->assertSame([$label], $result['signals']);
    }

    /** @return array<string, array{array<string, mixed>, string, int}> */
    public static function individualRiskSignals(): array
    {
        return [
            'invalid email' => [['email' => 'not-an-email'], 'Disposable/invalid email', 30],
            'disposable email domain' => [['email' => 'buyer@Mailinator.com'], 'Disposable/invalid email', 30],
            'billing and shipping country mismatch' => [[
                'billing_address' => ['country_code' => ' us '],
                'shipping_address' => ['countryCodeV2' => 'ca'],
            ], 'Billing ≠ shipping country', 25],
            'missing phone above high value threshold' => [[
                'totalPriceSet' => ['shopMoney' => ['amount' => '200.01']],
                'shipping_address' => ['phone' => '', 'address1' => '123 Main Street'],
            ], 'Missing phone on high-value order', 15],
            'PO Box address' => [['shipping_address' => ['address_1' => 'P.O. Box 42']], 'PO Box address', 10],
            'partially paid' => [['displayFinancialStatus' => 'PARTIALLY PAID'], 'Partially paid', 10],
            'fraud tag' => [['tags' => ['vip', 'HIGH-RISK']], 'Fraud/high-risk tag', 35],
            'Shopify high risk level' => [['risk_level' => ' high '], 'Shopify HIGH risk level', 40],
            'explicitly missing shipping address' => [['shipping_address' => null], 'No shipping address', 20],
        ];
    }

    public function test_high_value_order_with_null_shipping_address_accumulates_both_legacy_signals(): void
    {
        $result = $this->scorer()->score([
            'total_price' => '201.00',
            'shipping_address' => null,
        ]);

        $this->assertSame(35, $result['score']);
        $this->assertSame([
            'Missing phone on high-value order',
            'No shipping address',
        ], $result['signals']);
    }

    public function test_absent_optional_fields_do_not_create_risk_signals(): void
    {
        $result = $this->scorer()->score([]);

        $this->assertSame([], $result['signals']);
        $this->assertSame(0, $result['score']);
    }

    /** @param array<string, mixed> $order */
    #[DataProvider('scoreLevels')]
    public function test_score_levels_preserve_legacy_boundaries(array $order, int $score, string $level): void
    {
        $result = $this->scorer()->score($order);

        $this->assertSame($score, $result['score']);
        $this->assertSame($level, $result['level']);
    }

    /** @return array<string, array{array<string, mixed>, int, string}> */
    public static function scoreLevels(): array
    {
        return [
            'twenty remains low' => [['shipping_address' => null], 20, 'low'],
            'twenty five is medium' => [[
                'billing_address' => ['country_code' => 'US'],
                'shipping_address' => ['country_code' => 'CA'],
            ], 25, 'medium'],
            'fifty remains medium' => [['email' => 'invalid', 'shipping_address' => null], 50, 'medium'],
            'fifty five is high' => [[
                'email' => 'invalid',
                'billing_address' => ['country_code' => 'US'],
                'shipping_address' => ['country_code' => 'CA'],
            ], 55, 'high'],
        ];
    }

    public function test_multiple_signals_accumulate_in_legacy_evaluation_order(): void
    {
        $result = $this->scorer()->score([
            'email' => 'invalid',
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => [
                'country_code' => 'CA',
                'phone' => '',
                'address1' => 'PO Box 5',
            ],
            'total_price' => '201.00',
            'financial_status' => 'partially_paid',
            'tags' => 'vip, fraud',
            'risk_level' => 'HIGH',
        ]);

        $this->assertSame(165, $result['score']);
        $this->assertSame('high', $result['level']);
        $this->assertSame([
            'Disposable/invalid email',
            'Billing ≠ shipping country',
            'Missing phone on high-value order',
            'PO Box address',
            'Partially paid',
            'Fraud/high-risk tag',
            'Shopify HIGH risk level',
        ], $result['signals']);
    }

    private function scorer(): OrderRiskScorer
    {
        return new OrderRiskScorer;
    }
}
