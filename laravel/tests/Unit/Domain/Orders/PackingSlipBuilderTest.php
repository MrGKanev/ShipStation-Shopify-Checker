<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\PackingSlipBuilder;
use PHPUnit\Framework\TestCase;

class PackingSlipBuilderTest extends TestCase
{
    public function test_builds_all_fields_filters_internal_options_and_splits_notes(): void
    {
        $slip = (new PackingSlipBuilder)->build([
            'orderNumber' => 1001, 'orderDate' => '2026-09-01T10:00:00Z', 'shipByDate' => 'bad-date', 'customerUsername' => 'buyer',
            'shipTo' => ['name' => 'Ada', 'street1' => '1 Main'],
            'items' => [['name' => 'Shirt', 'quantity' => 0, 'options' => [['name' => 'Size', 'value' => '["S","M"]'], ['name' => ' GPO OPTIONS ', 'value' => 'secret']]]],
            'internalNotes' => 'First<br/>Second', 'customerNotes' => 'Third', 'advancedOptions' => ['customField1' => 'Fourth'],
        ]);
        $this->assertSame('9/1/2026', $slip['orderDate']);
        $this->assertSame('', $slip['shipByDate']);
        $this->assertSame(['name' => 'Size', 'value' => 'S, M', 'highlighted' => true], $slip['items'][0]['options'][0]);
        $this->assertCount(1, $slip['items'][0]['options']);
        $this->assertSame(['First', 'Second', 'Third', 'Fourth'], $slip['notes']);
        $this->assertSame(0, $slip['items'][0]['quantity']);
    }

    public function test_malformed_nested_values_are_safely_ignored(): void
    {
        $slip = (new PackingSlipBuilder)->build(['shipTo' => 'bad', 'items' => ['bad', ['options' => 'bad']], 'advancedOptions' => 'bad']);
        $this->assertCount(1, $slip['items']);
        $this->assertSame([], $slip['items'][0]['options']);
        $this->assertSame([], $slip['notes']);
    }
}
