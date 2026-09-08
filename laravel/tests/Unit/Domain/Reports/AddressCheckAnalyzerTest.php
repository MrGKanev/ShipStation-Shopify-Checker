<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\AddressCheckAnalyzer;
use PHPUnit\Framework\TestCase;

class AddressCheckAnalyzerTest extends TestCase
{
    public function test_valid_address_and_missing_address_boundaries(): void
    {
        $analyzer = new AddressCheckAnalyzer;
        $this->assertSame([], $analyzer->check($this->validAddress()));
        $this->assertSame('no_address', $analyzer->check(null)[0]['code']);
    }

    public function test_required_fields_zip_province_and_malformed_numeric_zip(): void
    {
        $analyzer = new AddressCheckAnalyzer;
        $codes = array_column($analyzer->check(['first_name' => '', 'last_name' => '', 'address1' => '', 'city' => '', 'zip' => '', 'country_code' => '']), 'code');
        $this->assertSame(['no_name', 'no_address1', 'no_city', 'no_zip', 'no_country'], $codes);
        $this->assertContains('bad_zip_us', array_column($analyzer->check($this->validAddress(['zip' => 123])), 'code'));
        $this->assertSame([], $analyzer->check($this->validAddress(['zip' => 90210])));
        $this->assertContains('bad_zip_ca', array_column($analyzer->check($this->validAddress(['country_code' => 'CA', 'province_code' => 'QC', 'zip' => '12345'])), 'code'));
        $this->assertNotContains('bad_zip_ca', array_column($analyzer->check($this->validAddress(['country_code' => 'CA', 'province_code' => 'QC', 'zip' => 'H3A 1A1'])), 'code'));
        $this->assertContains('no_province', array_column($analyzer->check($this->validAddress(['province_code' => ''])), 'code'));
        $this->assertNotContains('no_province', array_column($analyzer->check($this->validAddress(['country_code' => 'DE', 'province_code' => '', 'zip' => '10115'])), 'code'));
    }

    public function test_short_street_po_box_carriers_and_express_phone_rules(): void
    {
        $analyzer = new AddressCheckAnalyzer;
        $this->assertContains('short_address', array_column($analyzer->check($this->validAddress(['address1' => '1 St'])), 'code'));
        $this->assertContains('po_box', array_column($analyzer->check($this->validAddress(['address1' => 'PO Box 2']), ['shipping_lines' => [['title' => 'Standard']]]), 'code'));
        $this->assertContains('po_box_carrier', array_column($analyzer->check($this->validAddress(['address1' => 'PO Box 2']), ['shipping_lines' => [['title' => 'FedEx Ground']]]), 'code'));
        $this->assertContains('no_phone_express', array_column($analyzer->check($this->validAddress(['phone' => '']), ['shipping_lines' => [['title' => 'Express']]]), 'code'));
        $this->assertNotContains('no_phone_express', array_column($analyzer->check($this->validAddress(['phone' => '']), ['shipping_lines' => [['title' => 'Standard']]]), 'code'));
    }

    public function test_rows_sort_critical_first_and_po_box_filter_keeps_only_po_boxes(): void
    {
        $analyzer = new AddressCheckAnalyzer;
        $orders = [['name' => '#warning', 'shipping_address' => $this->validAddress(['zip' => 'bad'])], ['name' => '#critical', 'shipping_address' => $this->validAddress(['address1' => ''])], ['name' => '#box', 'shipping_address' => $this->validAddress(['address1' => 'PO Box 2'])]];
        $this->assertSame(['critical', 'warning', 'warning'], array_column($analyzer->analyze($orders), 'severity'));
        $this->assertSame(['#box'], array_column($analyzer->analyze($orders, true), 'number'));
    }

    /** @return array<string, mixed> */
    private function validAddress(array $overrides = []): array
    {
        return [...['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '123 Main Street', 'city' => 'Boston', 'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US', 'phone' => '617-555-0100'], ...$overrides];
    }
}
