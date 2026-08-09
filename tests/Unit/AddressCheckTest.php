<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

/**
 * Tests for OrderAnomalyPageLoader::checkAddress() via reflection (private method).
 */
class AddressCheckTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderAnomalyPageLoader::class);
        self::$method = $ref->getMethod('checkAddress');
    }

    private function check(?array $addr, array $order = []): array
    {
        $order = array_merge(['shipping_lines' => [['title' => 'Standard Shipping']]], $order);
        return self::$method->invoke(null, $addr, $order);
    }

    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    // ── Null address ──────────────────────────────────────────────────────────

    public function testNullAddrReturnsCritical(): void
    {
        $issues = $this->check(null);
        $this->assertContains('no_address', $this->codes($issues));
    }

    // ── Valid address ─────────────────────────────────────────────────────────

    public function testValidUsAddressNoIssues(): void
    {
        $issues = $this->check([
            'first_name'    => 'Jane',
            'last_name'     => 'Doe',
            'address1'      => '123 Main Street',
            'city'          => 'Boston',
            'province_code' => 'MA',
            'zip'           => '02101',
            'country_code'  => 'US',
            'phone'         => '617-555-0100',
        ]);
        $this->assertCount(0, $issues);
    }

    // ── Missing fields ────────────────────────────────────────────────────────

    public function testMissingNameIsCritical(): void
    {
        $issues = $this->check([
            'first_name'  => '',
            'last_name'   => '',
            'address1'    => '123 Main St',
            'city'        => 'Boston',
            'zip'         => '02101',
            'country_code'=> 'US',
        ]);
        $this->assertContains('no_name', $this->codes($issues));
        $levels = array_column($issues, 'level');
        $this->assertContains('critical', $levels);
    }

    public function testMissingStreetIsCritical(): void
    {
        $issues = $this->check([
            'first_name'  => 'Jane',
            'last_name'   => 'Doe',
            'address1'    => '',
            'city'        => 'Boston',
            'zip'         => '02101',
            'country_code'=> 'US',
        ]);
        $this->assertContains('no_address1', $this->codes($issues));
    }

    public function testShortStreetIsWarning(): void
    {
        $issues = $this->check([
            'first_name'  => 'Jane',
            'last_name'   => 'Doe',
            'address1'    => '1 St',
            'city'        => 'Boston',
            'zip'         => '02101',
            'country_code'=> 'US',
        ]);
        $this->assertContains('short_address', $this->codes($issues));
        $this->assertSame('warning', $issues[array_search('short_address', $this->codes($issues))]['level']);
    }

    public function testMissingCityIsCritical(): void
    {
        $issues = $this->check([
            'first_name'  => 'Jane', 'last_name' => 'Doe',
            'address1'    => '123 Main St',
            'city'        => '',
            'zip'         => '02101',
            'country_code'=> 'US',
        ]);
        $this->assertContains('no_city', $this->codes($issues));
    }

    public function testMissingZipIsCritical(): void
    {
        $issues = $this->check([
            'first_name'  => 'Jane', 'last_name' => 'Doe',
            'address1'    => '123 Main St',
            'city'        => 'Boston',
            'zip'         => '',
            'country_code'=> 'US',
        ]);
        $this->assertContains('no_zip', $this->codes($issues));
    }

    public function testMissingCountryIsCritical(): void
    {
        $issues = $this->check([
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'address1'   => '123 Main St',
            'city'       => 'Boston',
            'zip'        => '02101',
        ]);
        $this->assertContains('no_country', $this->codes($issues));
    }

    // ── ZIP validation ────────────────────────────────────────────────────────

    public function testInvalidUsZipIsWarning(): void
    {
        $issues = $this->check([
            'first_name'  => 'Jane', 'last_name' => 'Doe',
            'address1'    => '123 Main St',
            'city'        => 'Boston',
            'zip'         => 'ABCDE',
            'country_code'=> 'US',
        ]);
        $this->assertContains('bad_zip_us', $this->codes($issues));
    }

    public function testValidUsZipPlusFourNoWarning(): void
    {
        $issues = $this->check([
            'first_name'   => 'Jane', 'last_name' => 'Doe',
            'address1'     => '123 Main St',
            'city'         => 'Boston',
            'province_code'=> 'MA',
            'zip'          => '02101-1234',
            'country_code' => 'US',
            'phone'        => '617-555-0100',
        ]);
        $this->assertNotContains('bad_zip_us', $this->codes($issues));
    }

    public function testInvalidCaPostalIsWarning(): void
    {
        $issues = $this->check([
            'first_name'   => 'Pierre', 'last_name' => 'Dupont',
            'address1'     => '1 Rue Principale',
            'city'         => 'Montreal',
            'province_code'=> 'QC',
            'zip'          => '12345',
            'country_code' => 'CA',
        ]);
        $this->assertContains('bad_zip_ca', $this->codes($issues));
    }

    public function testValidCaPostalNoWarning(): void
    {
        $issues = $this->check([
            'first_name'   => 'Pierre', 'last_name' => 'Dupont',
            'address1'     => '1 Rue Principale',
            'city'         => 'Montreal',
            'province_code'=> 'QC',
            'zip'          => 'H3A 1A1',
            'country_code' => 'CA',
            'phone'        => '514-555-0100',
        ]);
        $this->assertNotContains('bad_zip_ca', $this->codes($issues));
    }

    // ── Province check ────────────────────────────────────────────────────────

    public function testMissingProvinceForUsIsWarning(): void
    {
        $issues = $this->check([
            'first_name'  => 'Jane', 'last_name' => 'Doe',
            'address1'    => '123 Main St',
            'city'        => 'Boston',
            'zip'         => '02101',
            'country_code'=> 'US',
        ]);
        $this->assertContains('no_province', $this->codes($issues));
    }

    public function testMissingProvinceForNonUsNoWarning(): void
    {
        $issues = $this->check([
            'first_name'  => 'Hans', 'last_name' => 'Schmidt',
            'address1'    => 'Hauptstraße 1',
            'city'        => 'Berlin',
            'zip'         => '10115',
            'country_code'=> 'DE',
        ]);
        $this->assertNotContains('no_province', $this->codes($issues));
    }

    // ── PO Box ────────────────────────────────────────────────────────────────

    public function testPoBoxWithFedexIsWarning(): void
    {
        $issues = $this->check(
            [
                'first_name'   => 'Jane', 'last_name' => 'Doe',
                'address1'     => 'PO Box 123',
                'city'         => 'Boston',
                'province_code'=> 'MA',
                'zip'          => '02101',
                'country_code' => 'US',
                'phone'        => '617-555-0100',
            ],
            ['shipping_lines' => [['title' => 'FedEx Ground']]]
        );
        $this->assertContains('po_box_carrier', $this->codes($issues));
    }

    public function testPoBoxWithStandardShippingIsWarning(): void
    {
        $issues = $this->check([
            'first_name'   => 'Jane', 'last_name' => 'Doe',
            'address1'     => 'PO Box 123',
            'city'         => 'Boston',
            'province_code'=> 'MA',
            'zip'          => '02101',
            'country_code' => 'US',
            'phone'        => '617-555-0100',
        ]);
        $this->assertContains('po_box', $this->codes($issues));
    }

    // ── Express shipping without phone ────────────────────────────────────────

    public function testNoPhoneWithExpressIsWarning(): void
    {
        $issues = $this->check(
            [
                'first_name'   => 'Jane', 'last_name' => 'Doe',
                'address1'     => '123 Main St',
                'city'         => 'Boston',
                'province_code'=> 'MA',
                'zip'          => '02101',
                'country_code' => 'US',
                'phone'        => '',
            ],
            ['shipping_lines' => [['title' => 'FedEx Overnight']]]
        );
        $this->assertContains('no_phone_express', $this->codes($issues));
    }

    public function testNoPhoneWithStandardNoWarning(): void
    {
        $issues = $this->check([
            'first_name'   => 'Jane', 'last_name' => 'Doe',
            'address1'     => '123 Main St',
            'city'         => 'Boston',
            'province_code'=> 'MA',
            'zip'          => '02101',
            'country_code' => 'US',
            'phone'        => '',
        ]);
        $this->assertNotContains('no_phone_express', $this->codes($issues));
    }

    // ── Non-string fields from malformed payloads ───────────────────────────

    public function testIntegerZipDoesNotThrow(): void
    {
        $issues = $this->check([
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'address1'   => '123 Main St', 'city' => 'Boston',
            'province_code' => 'CA', 'country_code' => 'US',
            'zip' => 90210,
        ]);
        $this->assertSame([], $issues);
    }

    public function testIntegerZipStillValidatedAsBadFormatWhenInvalid(): void
    {
        $issues = $this->check([
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'address1'   => '123 Main St', 'city' => 'Boston',
            'province_code' => 'MA', 'country_code' => 'US',
            'zip' => 123, // too short to be a valid 5-digit US zip
        ]);
        $this->assertContains('bad_zip_us', $this->codes($issues));
    }
}
