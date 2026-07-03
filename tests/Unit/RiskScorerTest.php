<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/RiskScorer.php';

use PHPUnit\Framework\TestCase;

class RiskScorerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static weight cache between tests
        $ref = new \ReflectionClass(RiskScorer::class);
        $ref->getProperty('customWeights')->setValue(null, null);
        $ref->getProperty('weightsLoaded')->setValue(null, false);
    }

    // ── Clean order ───────────────────────────────────────────────────────────

    public function testCleanOrderScoresZero(): void
    {
        $result = RiskScorer::score([
            'email'            => 'good@example.com',
            'billing_address'  => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US', 'phone' => '555-1234', 'address1' => '123 Main St'],
            'total_price'      => '50.00',
            'financial_status' => 'paid',
            'tags'             => '',
        ]);

        $this->assertSame(0, $result['score']);
        $this->assertSame('low', $result['level']);
        $this->assertSame([], $result['signals']);
    }

    // ── Email signal ──────────────────────────────────────────────────────────

    public function testInvalidEmailAddsSignal(): void
    {
        $result = RiskScorer::score(['email' => 'not-an-email']);

        $this->assertSame(30, $result['score']);
        $this->assertSame('medium', $result['level']);
        $this->assertSame('Disposable/invalid email', $result['signals'][0]['label']);
    }

    public function testDisposableDomainAddsSignal(): void
    {
        $result = RiskScorer::score(['email' => 'someone@mailinator.com']);

        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Disposable/invalid email', $labels);
    }

    public function testEmptyEmailSkipsSignal(): void
    {
        $result = RiskScorer::score(['email' => '']);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Disposable/invalid email', $labels);
    }

    // ── Country mismatch ──────────────────────────────────────────────────────

    public function testBillingShippingCountryMismatch(): void
    {
        $result = RiskScorer::score([
            'billing_address'  => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'CA', 'address1' => ''],
        ]);

        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Billing ≠ shipping country', $labels);
    }

    public function testSameCountryNoSignal(): void
    {
        $result = RiskScorer::score([
            'billing_address'  => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US', 'address1' => ''],
        ]);

        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Billing ≠ shipping country', $labels);
    }

    public function testGraphQLCountryCodeV2Field(): void
    {
        $result = RiskScorer::score([
            'billing_address'  => ['countryCodeV2' => 'DE'],
            'shipping_address' => ['countryCodeV2' => 'FR', 'address1' => ''],
        ]);

        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Billing ≠ shipping country', $labels);
    }

    // ── Missing phone on high-value order ─────────────────────────────────────

    public function testMissingPhoneHighValueOrder(): void
    {
        $result = RiskScorer::score([
            'shipping_address' => ['country_code' => 'US', 'phone' => '', 'address1' => ''],
            'total_price'      => '201.00',
        ]);

        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Missing phone on high-value order', $labels);
    }

    public function testPhonePresentHighValueNoSignal(): void
    {
        $result = RiskScorer::score([
            'shipping_address' => ['phone' => '555-9999', 'address1' => ''],
            'total_price'      => '500.00',
        ]);

        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Missing phone on high-value order', $labels);
    }

    public function testLowValueOrderSkipsPhoneCheck(): void
    {
        $result = RiskScorer::score([
            'shipping_address' => ['phone' => '', 'address1' => ''],
            'total_price'      => '199.99',
        ]);

        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Missing phone on high-value order', $labels);
    }

    public function testGraphQLTotalPriceSet(): void
    {
        $result = RiskScorer::score([
            'totalPriceSet'    => ['shopMoney' => ['amount' => '300.00']],
            'shipping_address' => ['phone' => '', 'address1' => ''],
        ]);

        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Missing phone on high-value order', $labels);
    }

    // ── PO Box ────────────────────────────────────────────────────────────────

    public function testPoBoxDetected(): void
    {
        foreach (['PO Box 123', 'P.O. Box 5', 'P O Box 99', 'po box 1'] as $addr) {
            $result = RiskScorer::score(['shipping_address' => ['address1' => $addr]]);
            $labels = array_column($result['signals'], 'label');
            $this->assertContains('PO Box address', $labels, "Failed for: $addr");
        }
    }

    public function testNormalAddressNotPoBox(): void
    {
        $result = RiskScorer::score(['shipping_address' => ['address1' => '123 Main St']]);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('PO Box address', $labels);
    }

    // ── Financial status ──────────────────────────────────────────────────────

    public function testPartiallyPaidAddsSignal(): void
    {
        $result = RiskScorer::score(['financial_status' => 'partially_paid']);
        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Partially paid', $labels);
    }

    public function testGraphQLDisplayFinancialStatus(): void
    {
        $result = RiskScorer::score(['displayFinancialStatus' => 'PARTIALLY PAID']);
        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Partially paid', $labels);
    }

    public function testPaidStatusNoSignal(): void
    {
        $result = RiskScorer::score(['financial_status' => 'paid']);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Partially paid', $labels);
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    public function testFraudTagStringAddsSignal(): void
    {
        $result = RiskScorer::score(['tags' => 'vip, fraud, returning']);
        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Fraud/high-risk tag', $labels);
    }

    public function testHighRiskTagArrayAddsSignal(): void
    {
        $result = RiskScorer::score(['tags' => ['vip', 'high-risk']]);
        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Fraud/high-risk tag', $labels);
    }

    public function testInnocuousTagsNoSignal(): void
    {
        $result = RiskScorer::score(['tags' => 'wholesale, vip']);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Fraud/high-risk tag', $labels);
    }

    public function testNullTagsSkipped(): void
    {
        $result = RiskScorer::score(['tags' => null]);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Fraud/high-risk tag', $labels);
    }

    public function testMissingTagsKeySkipped(): void
    {
        $result = RiskScorer::score([]);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Fraud/high-risk tag', $labels);
    }

    // ── Risk level ────────────────────────────────────────────────────────────

    public function testShopifyHighRiskLevel(): void
    {
        $result = RiskScorer::score(['risk_level' => 'HIGH']);
        $labels = array_column($result['signals'], 'label');
        $this->assertContains('Shopify HIGH risk level', $labels);
        $this->assertSame(40, $result['score']);
    }

    public function testRiskLevelMediumNoSignal(): void
    {
        $result = RiskScorer::score(['risk_level' => 'MEDIUM']);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('Shopify HIGH risk level', $labels);
    }

    // ── No shipping address ───────────────────────────────────────────────────

    public function testExplicitNullShippingAddressAddsSignal(): void
    {
        $result = RiskScorer::score(['shipping_address' => null]);
        $labels = array_column($result['signals'], 'label');
        $this->assertContains('No shipping address', $labels);
    }

    public function testMissingShippingAddressKeySkipped(): void
    {
        $result = RiskScorer::score([]);
        $labels = array_column($result['signals'], 'label');
        $this->assertNotContains('No shipping address', $labels);
    }

    // ── Level thresholds ──────────────────────────────────────────────────────

    public function testScoreLevelLow(): void
    {
        // Only PO box (10pts) → low
        $result = RiskScorer::score(['shipping_address' => ['address1' => 'PO Box 1']]);
        $this->assertSame('low', $result['level']);
    }

    public function testScoreLevelMedium(): void
    {
        // Invalid email (30pts) → medium (21-50)
        $result = RiskScorer::score(['email' => 'bad-email']);
        $this->assertSame('medium', $result['level']);
    }

    public function testScoreLevelHigh(): void
    {
        // HIGH risk (40) + PO box (10) = 50 → still medium; add partially_paid (10) = 60 → high
        $result = RiskScorer::score([
            'risk_level'       => 'HIGH',
            'shipping_address' => ['address1' => 'PO Box 1'],
            'financial_status' => 'partially_paid',
        ]);
        $this->assertSame('high', $result['level']);
        $this->assertSame(60, $result['score']);
    }

    // ── Multiple signals accumulate ───────────────────────────────────────────

    public function testMultipleSignalsAccumulate(): void
    {
        $result = RiskScorer::score([
            'email'            => 'x@mailinator.com',  // 30
            'financial_status' => 'partially_paid',     // 10
        ]);

        $this->assertSame(40, $result['score']);
        $this->assertCount(2, $result['signals']);
    }
}
