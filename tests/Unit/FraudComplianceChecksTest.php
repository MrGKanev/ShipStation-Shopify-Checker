<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';

/**
 * Tests for the pure row-building logic behind the Fraud & Compliance checks
 * that previously had only "missing credentials" wiring tests (see priority
 * #3 in docs/audit-test-coverage-gaps.md): Country Mismatch, High-Value No
 * Phone, and Email Checker. All accessed via reflection (private methods).
 */
class FraudComplianceChecksTest extends TestCase
{
    private static \ReflectionMethod $countryMismatch;
    private static \ReflectionMethod $hvOrders;
    private static \ReflectionMethod $emailCheck;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(SimpleScanPageLoader::class);
        self::$countryMismatch = $ref->getMethod('buildCountryMismatchRows');
        self::$hvOrders        = $ref->getMethod('buildHvOrderRows');
        self::$emailCheck      = $ref->getMethod('buildEmailCheckRows');
    }

    // ── Country Mismatch ─────────────────────────────────────────────────────

    private function orderWithAddresses(?array $bill, ?array $ship, array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'name'               => '#1001',
            'created_at'         => '2026-06-01T10:00:00Z',
            'email'              => 'jane@example.com',
            'total_price'        => '99.00',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'billing_address'    => $bill,
            'shipping_address'   => $ship,
        ], $overrides);
    }

    public function testDifferentCountriesAreFlagged(): void
    {
        $order = $this->orderWithAddresses(
            ['country_code' => 'US', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            ['country_code' => 'CA']
        );

        $rows = self::$countryMismatch->invoke(null, [$order]);

        $this->assertCount(1, $rows);
        $this->assertSame('US', $rows[0]['bill_country']);
        $this->assertSame('CA', $rows[0]['ship_country']);
        $this->assertSame('Jane Doe', $rows[0]['bill_name']);
    }

    public function testSameCountryDifferentCaseIsNotFlagged(): void
    {
        $order = $this->orderWithAddresses(
            ['country_code' => 'us'],
            ['country_code' => 'US']
        );

        $rows = self::$countryMismatch->invoke(null, [$order]);

        $this->assertSame([], $rows);
    }

    public function testGenuinelyDifferentCountriesFlaggedFalseNegativeCheck(): void
    {
        $order = $this->orderWithAddresses(
            ['country_code' => 'GB'],
            ['country_code' => 'FR']
        );

        $rows = self::$countryMismatch->invoke(null, [$order]);

        $this->assertCount(1, $rows);
    }

    public function testMissingBillingAddressIsNotFlagged(): void
    {
        $order = $this->orderWithAddresses(null, ['country_code' => 'US']);

        $rows = self::$countryMismatch->invoke(null, [$order]);

        $this->assertSame([], $rows);
    }

    public function testMissingShippingAddressIsNotFlagged(): void
    {
        $order = $this->orderWithAddresses(['country_code' => 'US'], null);

        $rows = self::$countryMismatch->invoke(null, [$order]);

        $this->assertSame([], $rows);
    }

    public function testFallsBackToCountryFieldWhenCountryCodeAbsent(): void
    {
        $order = $this->orderWithAddresses(
            ['country' => 'United States'],
            ['country' => 'Canada']
        );

        $rows = self::$countryMismatch->invoke(null, [$order]);

        $this->assertCount(1, $rows);
    }

    // ── High-Value No Phone ──────────────────────────────────────────────────

    private function hvOrder(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'name'              => '#1001',
            'created_at'        => '2026-06-01T10:00:00Z',
            'email'             => 'jane@example.com',
            'total_price'       => '500.00',
            'shipping_address'  => ['phone' => ''],
        ], $overrides);
    }

    public function testHighValueOrderWithNoPhoneIsFlagged(): void
    {
        $rows = self::$hvOrders->invoke(null, [$this->hvOrder()], 200.0);

        $this->assertCount(1, $rows);
        $this->assertSame(500.0, $rows[0]['total']);
    }

    public function testHighValueOrderWithPhoneIsExcluded(): void
    {
        $order = $this->hvOrder(['shipping_address' => ['phone' => '555-1234']]);

        $rows = self::$hvOrders->invoke(null, [$order], 200.0);

        $this->assertSame([], $rows);
    }

    public function testBelowThresholdOrderWithNoPhoneIsExcluded(): void
    {
        $order = $this->hvOrder(['total_price' => '50.00']);

        $rows = self::$hvOrders->invoke(null, [$order], 200.0);

        $this->assertSame([], $rows);
    }

    public function testExactlyAtThresholdIsFlaggedStrictLessThan(): void
    {
        $order = $this->hvOrder(['total_price' => '200.00']);

        $rows = self::$hvOrders->invoke(null, [$order], 200.0);

        $this->assertCount(1, $rows, 'condition is strict "<", so total === min should still flag');
    }

    public function testJustBelowThresholdIsExcluded(): void
    {
        $order = $this->hvOrder(['total_price' => '199.99']);

        $rows = self::$hvOrders->invoke(null, [$order], 200.0);

        $this->assertSame([], $rows);
    }

    public function testHvOrdersSortedByTotalDescending(): void
    {
        $rows = self::$hvOrders->invoke(null, [
            $this->hvOrder(['name' => '#A', 'total_price' => '300.00']),
            $this->hvOrder(['name' => '#B', 'total_price' => '900.00']),
        ], 200.0);

        $this->assertSame(['#B', '#A'], array_column($rows, 'order_number'));
    }

    // ── Email Checker ────────────────────────────────────────────────────────

    private function emailOrder(string $email, array $overrides = []): array
    {
        return array_merge([
            'id'         => 1,
            'name'       => '#1001',
            'created_at' => '2026-06-01T10:00:00Z',
            'email'      => $email,
        ], $overrides);
    }

    public function testDisposableDomainFlaggedCritical(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('user@mailinator.com')]);

        $this->assertCount(1, $rows);
        $this->assertSame('critical', $rows[0]['severity']);
    }

    public function testLegitDomainNotFlaggedFalsePositiveCheck(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('jane.doe@gmail.com')]);

        $this->assertSame([], $rows);
    }

    public function testMissingEmailFlaggedCritical(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('')]);

        $this->assertCount(1, $rows);
        $this->assertSame('critical', $rows[0]['severity']);
    }

    public function testInvalidEmailFormatFlaggedCritical(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('not-an-email')]);

        $this->assertCount(1, $rows);
        $this->assertSame('critical', $rows[0]['severity']);
    }

    public function testTwoCharLocalPartFlaggedWarning(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('ab@example.com')]);

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    public function testThreeCharLocalPartNotFlagged(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('abc@example.com')]);

        $this->assertSame([], $rows);
    }

    public function testPlaceholderLocalPartFlaggedWarning(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('test@example.com')]);

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    public function testFourRepeatedCharsNotFlagged(): void
    {
        // local part "aaaab" has 4 consecutive 'a's - regex requires 5 (\1{4,} after the first char = 5 total)
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('aaaab@example.com')]);

        $this->assertSame([], $rows);
    }

    public function testFiveRepeatedCharsFlaggedWarning(): void
    {
        $rows = self::$emailCheck->invoke(null, [$this->emailOrder('aaaaab@example.com')]);

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    public function testRowsSortedCriticalFirst(): void
    {
        $rows = self::$emailCheck->invoke(null, [
            $this->emailOrder('ab@example.com'),        // warning (short local part)
            $this->emailOrder('user@mailinator.com'),   // critical (disposable)
        ]);

        $this->assertSame(['critical', 'warning'], array_column($rows, 'severity'));
    }
}
