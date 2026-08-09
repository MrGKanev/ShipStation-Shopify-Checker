<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

/**
 * Tests for OrderAnomalyPageLoader::buildAddrCheckRows() via reflection
 * (private method): the page-level severity sorting and po_box_only filter
 * that sit on top of the well-tested checkAddress() unit
 * (docs/audit-test-coverage-gaps.md "Address Scanner" minor gaps).
 */
class AddressScannerPageTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderAnomalyPageLoader::class);
        self::$method = $ref->getMethod('buildAddrCheckRows');
    }

    private function order(?array $addr, array $overrides = []): array
    {
        return array_merge([
            'id'              => 1,
            'name'            => '#1001',
            'created_at'      => '2026-06-01T10:00:00Z',
            'email'           => 'jane@example.com',
            'shipping_address'=> $addr,
            'shipping_lines'  => [['title' => 'Standard Shipping']],
        ], $overrides);
    }

    private function build(array $orders, bool $poBoxOnly = false): array
    {
        return self::$method->invoke(null, $orders, $poBoxOnly);
    }

    private function validAddr(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'address1' => '123 Main St', 'city' => 'Boston',
            'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US',
            'phone' => '617-555-0100',
        ], $overrides);
    }

    public function testCriticalSortedBeforeWarning(): void
    {
        $orders = [
            $this->order($this->validAddr(['zip' => '021011']), ['name' => '#warn']), // bad_zip_us format = warning
            $this->order($this->validAddr(['address1' => '']), ['name' => '#crit']),  // no_address1 = critical
        ];

        $rows = $this->build($orders);

        $this->assertSame('critical', $rows[0]['severity']);
        $this->assertSame('warning', $rows[1]['severity']);
    }

    public function testPoBoxOnlyFiltersOutNonPoBoxIssues(): void
    {
        $orders = [
            $this->order($this->validAddr(['address1' => '']), ['name' => '#crit']), // no_address1, not PO box related
            $this->order($this->validAddr(['address1' => 'PO Box 123']), ['name' => '#pobox']),
        ];

        $rows = $this->build($orders, true);

        $this->assertCount(1, $rows);
        $this->assertSame('#pobox', $rows[0]['order_number']);
    }

    public function testPoBoxOnlyFalseKeepsAllIssues(): void
    {
        $orders = [
            $this->order($this->validAddr(['address1' => '']), ['name' => '#crit']),
            $this->order($this->validAddr(['address1' => 'PO Box 123']), ['name' => '#pobox']),
        ];

        $rows = $this->build($orders, false);

        $this->assertCount(2, $rows);
    }

    public function testCleanAddressProducesNoRow(): void
    {
        $rows = $this->build([$this->order($this->validAddr())]);

        $this->assertSame([], $rows);
    }
}
