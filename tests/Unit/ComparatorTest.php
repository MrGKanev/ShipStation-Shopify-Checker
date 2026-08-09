<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ComparatorTest extends TestCase
{
    protected function setUp(): void
    {
        Comparator::setOrderTypesConfig([
            'fallback' => 'Addons',
            'rules'    => [
                [
                    'name'       => 'TypeA',
                    'match'      => 'sku_starts_with',
                    'value'      => 'widget-a-',
                    'exclude_if' => [
                        ['match' => 'sku_contains',   'value' => 'warranty'],
                        ['match' => 'title_contains', 'value' => 'warranty'],
                    ],
                    'required_items' => [
                        ['label' => 'Part X', 'match' => 'title_contains',  'value' => 'part x'],
                        ['label' => 'Part Y', 'match' => 'title_contains',  'value' => 'part y'],
                        ['label' => 'Part Z', 'match' => 'sku_starts_with', 'value' => ['cmp-', 'cmp2-', 'cmp3-', 'cmp4-']],
                    ],
                ],
                [
                    'name'       => 'TypeB',
                    'match'      => 'sku_starts_with',
                    'value'      => 'widget-b-',
                    'exclude_if' => [
                        ['match' => 'sku_contains',   'value' => 'warranty'],
                        ['match' => 'title_contains', 'value' => 'warranty'],
                    ],
                    'required_items' => [
                        ['label' => 'Part X', 'match' => 'title_contains',  'value' => 'part x'],
                        ['label' => 'Part Y', 'match' => 'title_contains',  'value' => 'part y'],
                        ['label' => 'Part Z', 'match' => 'sku_starts_with', 'value' => ['cmp-', 'cmp2-', 'cmp3-', 'cmp4-']],
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Comparator::resetOrderTypesConfig();
    }

    // ── normalise ─────────────────────────────────────────────────────────────

    public function testNormaliseStripsHash(): void
    {
        $this->assertSame('12345', Comparator::normalise('#12345'));
    }

    public function testNormaliseStripsWhitespace(): void
    {
        $this->assertSame('12345', Comparator::normalise('  #12345  '));
    }

    public function testNormaliseKeepsOnlyDigits(): void
    {
        $this->assertSame('12345', Comparator::normalise('ORDER-12345'));
    }

    public function testNormaliseJoinsMultipleSegments(): void
    {
        // "100042-B2" → digits only joined → "1000422"
        $this->assertSame('1000422', Comparator::normalise('100042-B2'));
    }

    public function testNormaliseEmptyString(): void
    {
        $this->assertSame('', Comparator::normalise(''));
    }

    // ── buildSSIndex ──────────────────────────────────────────────────────────

    public function testBuildSSIndexPrimaryKey(): void
    {
        $orders = [['orderNumber' => '#12345', 'orderId' => 1]];
        $index  = Comparator::buildSSIndex($orders);

        $this->assertArrayHasKey('12345', $index);
        $this->assertCount(1, $index['12345']);
    }

    public function testBuildSSIndexCompoundNumber(): void
    {
        $orders = [['orderNumber' => '100042-B2', 'orderId' => 1]];
        $index  = Comparator::buildSSIndex($orders);

        // Full normalised key
        $this->assertArrayHasKey('1000422', $index);
        // Individual segment keys
        $this->assertArrayHasKey('100042', $index);
        $this->assertArrayHasKey('2', $index);
    }

    public function testBuildSSEmailIndex(): void
    {
        $orders = [
            ['orderNumber' => '1', 'customerEmail' => 'Alice@Example.com'],
            ['orderNumber' => '2', 'customerEmail' => 'alice@example.com'],
        ];
        $index = Comparator::buildSSEmailIndex($orders);

        $this->assertArrayHasKey('alice@example.com', $index);
        $this->assertCount(2, $index['alice@example.com']);
    }

    // ── compare ───────────────────────────────────────────────────────────────

    private function makeShopifyOrder(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'order_number'       => 65001,
            'name'               => '#165001',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'cancelled_at'       => null,
            'total_price'        => '99.00',
            'email'              => 'test@example.com',
            'shipping_lines'     => [['title' => 'Standard Shipping']],
        ], $overrides);
    }

    public function testCompareFoundByOrderNumber(): void
    {
        $order   = $this->makeShopifyOrder();
        $ssIndex = ['65001' => [['orderNumber' => '65001']]];

        $result = Comparator::compare([$order], $ssIndex);

        $this->assertCount(1, $result['found']);
        $this->assertCount(0, $result['missing']);
        $this->assertSame('order_number', $result['found'][0]['_match_method']);
    }

    public function testCompareMissingOrder(): void
    {
        $order  = $this->makeShopifyOrder();
        $result = Comparator::compare([$order], []);

        $this->assertCount(1, $result['missing']);
        $this->assertCount(0, $result['found']);
    }

    public function testCompareSkipsCancelled(): void
    {
        $order  = $this->makeShopifyOrder(['cancelled_at' => '2024-01-01T10:00:00Z']);
        $result = Comparator::compare([$order], []);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame('cancelled', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsPendingFinancial(): void
    {
        $order  = $this->makeShopifyOrder(['financial_status' => 'pending']);
        $result = Comparator::compare([$order], []);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame('financial', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsRefunded(): void
    {
        $order  = $this->makeShopifyOrder(['financial_status' => 'refunded']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('financial', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsFulfilled(): void
    {
        $order  = $this->makeShopifyOrder(['fulfillment_status' => 'fulfilled']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('fulfilled', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsRestocked(): void
    {
        $order  = $this->makeShopifyOrder(['fulfillment_status' => 'restocked']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('fulfilled', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsZeroValue(): void
    {
        $order  = $this->makeShopifyOrder(['total_price' => '0.00']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('zero_value', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsNoShipping(): void
    {
        $order  = $this->makeShopifyOrder(['shipping_lines' => []]);
        $result = Comparator::compare([$order], []);

        $this->assertSame('no_shipping', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareIgnoredOrder(): void
    {
        $order   = $this->makeShopifyOrder();
        $ignored = ['65001' => ['reason' => 'test']];
        $result  = Comparator::compare([$order], [], $ignored);

        $this->assertCount(1, $result['ignored']);
        $this->assertCount(0, $result['missing']);
    }

    public function testCompareEmailFallback(): void
    {
        $order       = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 100.00, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);

        $this->assertCount(1, $result['found']);
        $this->assertSame('email+amount', $result['found'][0]['_match_method']);
    }

    public function testCompareEmailFallbackToleranceWithin1Percent(): void
    {
        // Shopify: $100.00, SS: $100.50 → 0.5% difference → should match
        $order        = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 100.50, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);
        $this->assertCount(1, $result['found']);
    }

    public function testCompareEmailFallbackExceeds1Percent(): void
    {
        // Shopify: $100.00, SS: $102.00 → 2% difference → no match
        $order        = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 102.00, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);
        $this->assertCount(1, $result['missing']);
    }

    public function testCompareEmailFallbackExactly1PercentDoesNotMatch(): void
    {
        // Shopify: $100.00, SS: $101.00 → exactly 1.0% difference; the
        // documented tolerance is a strict "< 0.01", so this boundary
        // should NOT match (see priority docs/audit-test-coverage-gaps.md
        // "Core audit engine" bullet on the 1.0% boundary).
        $order        = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 101.00, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);
        $this->assertCount(1, $result['missing']);
        $this->assertCount(0, $result['found']);
    }

    public function testCompareEmailFallbackJustUnder1PercentMatches(): void
    {
        // Shopify: $100.00, SS: $100.99 → 0.99% difference → should match
        $order        = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 100.99, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);
        $this->assertCount(1, $result['found']);
    }

    // ── Orders missing fields entirely (not just empty) ────────────────────────

    public function testCompareHandlesOrderMissingAllOptionalFields(): void
    {
        // No order_number/name/email/total_price/financial_status/etc at all -
        // everything relies on `??` fallbacks inside compare().
        $result = Comparator::compare([[]], []);

        $this->assertCount(1, $result['missing']);
        $this->assertSame([], $result['missing'][0]);
    }

    public function testCompareHandlesOrderMissingFieldsWithEmailFallback(): void
    {
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 50, 'orderNumber' => '1']]];

        // Missing order_number/name/email/total_price entirely: normalise('')
        // → '', email fallback also empty, so this must land in missing
        // rather than throwing or false-matching.
        $result = Comparator::compare([[]], [], [], $ssEmailIndex);

        $this->assertCount(1, $result['missing']);
    }

    public function testBuildSSIndexHandlesOrderMissingOrderNumberField(): void
    {
        $index = Comparator::buildSSIndex([[]]);

        $this->assertSame([], $index);
    }

    public function testBuildSSEmailIndexHandlesOrderMissingEmailField(): void
    {
        $index = Comparator::buildSSEmailIndex([[]]);

        $this->assertSame([], $index);
    }

    public function testFindDuplicatesHandlesOrderMissingAllFields(): void
    {
        // No email/total_price/created_at at all: should be filtered out
        // (no email, amount <= 0) rather than throwing.
        $result = Comparator::findDuplicates([[], []]);

        $this->assertSame([], $result);
    }

    // ── findDuplicates ────────────────────────────────────────────────────────

    private function makeOrder(string $email, string $total, string $createdAt): array
    {
        return [
            'email'       => $email,
            'total_price' => $total,
            'created_at'  => $createdAt,
            'order_number'=> rand(1000, 9999),
        ];
    }

    public function testFindDuplicatesWithinWindow(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:30:00Z'),
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(1, $result);
        $this->assertSame('a@b.com', $result[0]['email']);
        $this->assertCount(2, $result[0]['orders']);
    }

    public function testFindDuplicatesExactly86400SecondsApartIsWithinWindow(): void
    {
        // The documented 24h window is inclusive ("<= 86400"), so orders
        // exactly 86400 seconds apart must still cluster as duplicates.
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '50.00', '2024-01-02T10:00:00Z'), // exactly 24h later
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(1, $result);
        $this->assertCount(2, $result[0]['orders']);
    }

    public function testFindDuplicatesOneSecondPast86400IsOutsideWindow(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '50.00', '2024-01-02T10:00:01Z'), // 86401s later
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(0, $result);
    }

    public function testFindDuplicatesOutsideWindow(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '50.00', '2024-01-03T10:00:00Z'), // 48h apart
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(0, $result);
    }

    public function testFindDuplicatesDifferentAmounts(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '75.00', '2024-01-01T10:30:00Z'),
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(0, $result);
    }

    public function testFindDuplicatesDifferentEmails(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('c@d.com', '50.00', '2024-01-01T10:30:00Z'),
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(0, $result);
    }

    // ── classifyOrder ─────────────────────────────────────────────────────────

    private function makeLineItem(array $overrides = []): array
    {
        return array_merge(['sku' => '', 'title' => '', 'vendor' => ''], $overrides);
    }

    private function orderWith(array ...$items): array
    {
        return ['line_items' => array_map(fn($i) => $this->makeLineItem($i), $items)];
    }

    public function testClassifyTypeABySkuPrefix(): void
    {
        $this->assertSame('TypeA', Comparator::classifyOrder($this->orderWith(['sku' => 'widget-a-red'])));
    }

    public function testClassifyTypeBBySkuPrefix(): void
    {
        $this->assertSame('TypeB', Comparator::classifyOrder($this->orderWith(['sku' => 'widget-b-blue'])));
    }

    public function testClassifyBothTypesInOneOrder(): void
    {
        $order = $this->orderWith(['sku' => 'widget-a-red'], ['sku' => 'widget-b-blue']);
        $this->assertSame('TypeA + TypeB', Comparator::classifyOrder($order));
    }

    public function testClassifyFallbackWhenNoMatch(): void
    {
        $this->assertSame('Addons', Comparator::classifyOrder($this->orderWith(['sku' => 'other-sku'])));
    }

    public function testClassifyEmptyLineItemsFallback(): void
    {
        $this->assertSame('Addons', Comparator::classifyOrder(['line_items' => []]));
    }

    public function testClassifyIsCaseInsensitive(): void
    {
        $this->assertSame('TypeA', Comparator::classifyOrder($this->orderWith(['sku' => 'WIDGET-A-RED'])));
    }

    // ── findMissingRequired ───────────────────────────────────────────────────

    public function testFindMissingRequiredAllPresent(): void
    {
        $order = $this->orderWith(
            ['sku' => 'widget-a-red'],
            ['title' => 'Part X - Premium'],
            ['title' => 'Part Y'],
            ['sku' => 'cmp-64-steel'],
        );
        $this->assertSame([], Comparator::findMissingRequired($order));
    }

    public function testFindMissingRequiredMissingPartX(): void
    {
        $order  = $this->orderWith(['sku' => 'widget-a-red'], ['title' => 'Part Y'], ['sku' => 'cmp-64-steel']);
        $result = Comparator::findMissingRequired($order);
        $this->assertContains('Part X', $result['TypeA']);
        $this->assertNotContains('Part Y', $result['TypeA']);
        $this->assertNotContains('Part Z', $result['TypeA']);
    }

    public function testFindMissingRequiredMissingPartY(): void
    {
        $order  = $this->orderWith(['sku' => 'widget-a-red'], ['title' => 'Part X'], ['sku' => 'cmp-64-steel']);
        $result = Comparator::findMissingRequired($order);
        $this->assertContains('Part Y', $result['TypeA']);
        $this->assertNotContains('Part X', $result['TypeA']);
    }

    public function testFindMissingRequiredAllMissing(): void
    {
        $result = Comparator::findMissingRequired($this->orderWith(['sku' => 'widget-a-red']));
        $this->assertArrayHasKey('TypeA', $result);
        $this->assertCount(3, $result['TypeA']);
        $this->assertSame(['Part X', 'Part Y', 'Part Z'], $result['TypeA']);
    }

    public function testFindMissingRequiredPartZMatchesAnyArrayPrefix(): void
    {
        foreach (['cmp-64-steel', 'cmp2-80-ti', 'cmp3-light', 'cmp4-fine'] as $sku) {
            $order = $this->orderWith(
                ['sku' => 'widget-a-red'],
                ['title' => 'Part X'],
                ['title' => 'Part Y'],
                ['sku' => $sku],
            );
            $this->assertSame([], Comparator::findMissingRequired($order), "Expected no missing with component SKU: {$sku}");
        }
    }

    public function testFindMissingRequiredExcludeIfSkuContainsWarranty(): void
    {
        $order = $this->orderWith(['sku' => 'widget-a-warranty']);
        $this->assertSame([], Comparator::findMissingRequired($order));
    }

    public function testFindMissingRequiredExcludeIfTitleContainsWarranty(): void
    {
        $order = $this->orderWith(['sku' => 'widget-a-red', 'title' => 'Warranty Extension']);
        $this->assertSame([], Comparator::findMissingRequired($order));
    }

    public function testFindMissingRequiredUnmatchedTypeReturnsEmpty(): void
    {
        $this->assertSame([], Comparator::findMissingRequired($this->orderWith(['sku' => 'other-sku'])));
    }

    public function testFindMissingRequiredTypeBAllMissing(): void
    {
        $result = Comparator::findMissingRequired($this->orderWith(['sku' => 'widget-b-blue']));
        $this->assertArrayHasKey('TypeB', $result);
        $this->assertCount(3, $result['TypeB']);
    }

    public function testFindMissingRequiredTypeBAllPresent(): void
    {
        $order = $this->orderWith(
            ['sku' => 'widget-b-blue'],
            ['title' => 'Part X'],
            ['title' => 'Part Y'],
            ['sku' => 'cmp-64-steel'],
        );
        $this->assertSame([], Comparator::findMissingRequired($order));
    }

    // ── diffShippedItems ──────────────────────────────────────────────────────

    private function makeShopifyLineItem(array $overrides = []): array
    {
        return array_merge(['sku' => '', 'title' => '', 'quantity' => 1], $overrides);
    }

    private function shopifyOrderWith(array ...$items): array
    {
        return ['line_items' => array_map(fn($i) => $this->makeShopifyLineItem($i), $items)];
    }

    private function makeSsItem(array $overrides = []): array
    {
        return array_merge(['sku' => '', 'quantity' => 1, 'name' => ''], $overrides);
    }

    public function testDiffShippedItemsExactMatchNoMismatch(): void
    {
        $order = $this->shopifyOrderWith(
            ['sku' => 'widget-a-red', 'quantity' => 1],
            ['sku' => 'part-x', 'title' => 'Part X', 'quantity' => 1],
        );
        $ssItems = [
            $this->makeSsItem(['sku' => 'widget-a-red', 'quantity' => 1, 'name' => 'Widget A Red']),
            $this->makeSsItem(['sku' => 'part-x', 'quantity' => 1, 'name' => 'Part X']),
        ];

        $result = Comparator::diffShippedItems($order, $ssItems);

        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['extra']);
        $this->assertSame([], $result['missingRequired']);
    }

    public function testDiffShippedItemsMissingAccessoryFlagsBundleGap(): void
    {
        // Ordered: full TypeA bundle (widget + Part X + Part Y + Part Z component)
        $order = $this->shopifyOrderWith(
            ['sku' => 'widget-a-red', 'quantity' => 1],
            ['sku' => 'px', 'title' => 'Part X', 'quantity' => 1],
            ['sku' => 'py', 'title' => 'Part Y', 'quantity' => 1],
            ['sku' => 'cmp-64-steel', 'title' => 'Part Z', 'quantity' => 1],
        );
        // Shipped: everything except the Part Z component SKU
        $ssItems = [
            $this->makeSsItem(['sku' => 'widget-a-red', 'quantity' => 1, 'name' => 'Widget A Red']),
            $this->makeSsItem(['sku' => 'px', 'quantity' => 1, 'name' => 'Part X']),
            $this->makeSsItem(['sku' => 'py', 'quantity' => 1, 'name' => 'Part Y']),
        ];

        $result = Comparator::diffShippedItems($order, $ssItems);

        $this->assertSame(['cmp-64-steel' => 1], $result['missing']);
        $this->assertSame([], $result['extra']);
        $this->assertArrayHasKey('TypeA', $result['missingRequired']);
        $this->assertSame(['Part Z'], $result['missingRequired']['TypeA']);
    }

    public function testDiffShippedItemsExtraOrWrongItemShipped(): void
    {
        $order = $this->shopifyOrderWith(['sku' => 'widget-a-red', 'quantity' => 1]);
        $ssItems = [
            $this->makeSsItem(['sku' => 'widget-a-red', 'quantity' => 1]),
            $this->makeSsItem(['sku' => 'widget-b-blue', 'quantity' => 1, 'name' => 'Wrong Item']),
        ];

        $result = Comparator::diffShippedItems($order, $ssItems);

        $this->assertSame([], $result['missing']);
        $this->assertSame(['widget-b-blue' => 1], $result['extra']);
    }

    public function testDiffShippedItemsPartialQuantityShortfall(): void
    {
        $order   = $this->shopifyOrderWith(['sku' => 'widget-a-red', 'quantity' => 2]);
        $ssItems = [$this->makeSsItem(['sku' => 'widget-a-red', 'quantity' => 1])];

        $result = Comparator::diffShippedItems($order, $ssItems);

        $this->assertSame(['widget-a-red' => 1], $result['missing']);
        $this->assertSame([], $result['extra']);
    }

    public function testDiffShippedItemsNormalisesSkuCaseAndWhitespace(): void
    {
        $order   = $this->shopifyOrderWith(['sku' => '  WIDGET-A-RED  ', 'quantity' => 1]);
        $ssItems = [$this->makeSsItem(['sku' => 'widget-a-red', 'quantity' => 1])];

        $result = Comparator::diffShippedItems($order, $ssItems);

        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['extra']);
        $this->assertSame(['widget-a-red' => 1], $result['ordered']);
        $this->assertSame(['widget-a-red' => 1], $result['shipped']);
    }

    public function testDiffShippedItemsEmptyLineItemsAndEmptyItems(): void
    {
        $result = Comparator::diffShippedItems(['line_items' => []], []);

        $this->assertSame([], $result['ordered']);
        $this->assertSame([], $result['shipped']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['extra']);
        $this->assertSame([], $result['missingRequired']);
    }

    public function testDiffShippedItemsDoesNotDuplicateOrderLevelGap(): void
    {
        // Order itself never had the Part Z component - findMissingRequired($order)
        // already flags TypeA => ['Part Z']. Shipment also lacks it, but this is an
        // ordering gap the bundle check already surfaces, not a shipping gap.
        $order = $this->shopifyOrderWith(
            ['sku' => 'widget-a-red', 'quantity' => 1],
            ['sku' => 'px', 'title' => 'Part X', 'quantity' => 1],
            ['sku' => 'py', 'title' => 'Part Y', 'quantity' => 1],
        );
        $ssItems = [
            $this->makeSsItem(['sku' => 'widget-a-red', 'quantity' => 1]),
            $this->makeSsItem(['sku' => 'px', 'quantity' => 1, 'name' => 'Part X']),
            $this->makeSsItem(['sku' => 'py', 'quantity' => 1, 'name' => 'Part Y']),
        ];

        // Sanity: the order-level bundle check already flags this gap.
        $this->assertSame(['TypeA' => ['Part Z']], Comparator::findMissingRequired($order));

        $result = Comparator::diffShippedItems($order, $ssItems);

        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['extra']);
        $this->assertSame([], $result['missingRequired']);
    }

    // ── shippingLoss ─────────────────────────────────────────────────────────

    public function testShippingLossComputesCostChargedAndLoss(): void
    {
        $shipment = ['shipmentCost' => 18.50, 'insuranceCost' => 1.50, 'orderNumber' => '1001'];
        $shippingLines = [['price' => 5.00]];

        $result = Comparator::shippingLoss($shipment, $shippingLines);

        $this->assertSame(20.0, $result['shipCost']);
        $this->assertSame(5.0, $result['shippingCharged']);
        $this->assertSame(15.0, $result['loss']);
    }

    public function testShippingLossSumsMultipleShippingLines(): void
    {
        $shipment = ['shipmentCost' => 10.0, 'insuranceCost' => 0.0];
        $shippingLines = [['price' => 3.00], ['price' => 2.50]];

        $result = Comparator::shippingLoss($shipment, $shippingLines);

        $this->assertSame(5.5, $result['shippingCharged']);
        $this->assertSame(4.5, $result['loss']);
    }

    public function testShippingLossZeroCostEdgeCase(): void
    {
        $shipment = ['shipmentCost' => 0.0, 'insuranceCost' => 0.0];
        $result = Comparator::shippingLoss($shipment, []);

        $this->assertSame(0.0, $result['shipCost']);
        $this->assertSame(0.0, $result['shippingCharged']);
        $this->assertSame(0.0, $result['loss']);
    }

    public function testShippingLossMissingCostFieldsDefaultToZero(): void
    {
        $result = Comparator::shippingLoss([], [['price' => 4.99]]);

        $this->assertSame(0.0, $result['shipCost']);
        $this->assertSame(4.99, $result['shippingCharged']);
        $this->assertSame(-4.99, $result['loss']);
    }

    public function testShippingLossReturnsNullForVoidedShipment(): void
    {
        $shipment = ['shipmentCost' => 50.0, 'insuranceCost' => 0.0, 'voided' => true];

        $this->assertNull(Comparator::shippingLoss($shipment, []));
    }

    public function testShippingLossDoesNotSkipNonVoidedShipment(): void
    {
        $shipment = ['shipmentCost' => 50.0, 'insuranceCost' => 0.0, 'voided' => false];

        $this->assertNotNull(Comparator::shippingLoss($shipment, []));
    }

    public function testShippingLossNoShippingLinesTreatsChargedAsZero(): void
    {
        $shipment = ['shipmentCost' => 12.0, 'insuranceCost' => 3.0];

        $result = Comparator::shippingLoss($shipment, []);

        $this->assertSame(0.0, $result['shippingCharged']);
        $this->assertSame(15.0, $result['loss']);
    }

    // ── applyOnHoldSkip ───────────────────────────────────────────────────────

    private function baseResult(array $missing): array
    {
        return ['missing' => $missing, 'found' => [], 'skipped' => [], 'ignored' => []];
    }

    public function testOnHoldOrderMovedFromMissingToSkipped(): void
    {
        $result = $this->baseResult([['id' => 1, 'order_number' => '1001']]);

        $out = Comparator::applyOnHoldSkip($result, fn(array $o) => true);

        $this->assertSame([], $out['missing']);
        $this->assertCount(1, $out['skipped']);
        $this->assertSame('on_hold', $out['skipped'][0]['_skip_reason']);
        $this->assertSame('1001', $out['skipped'][0]['order_number']);
    }

    public function testNotOnHoldOrderStaysMissing(): void
    {
        $result = $this->baseResult([['id' => 1, 'order_number' => '1001']]);

        $out = Comparator::applyOnHoldSkip($result, fn(array $o) => false);

        $this->assertCount(1, $out['missing']);
        $this->assertSame([], $out['skipped']);
    }

    public function testOnHoldCheckOnlyRunsForEachMissingOrderOnce(): void
    {
        $result = $this->baseResult([
            ['id' => 1, 'order_number' => '1001'],
            ['id' => 2, 'order_number' => '1002'],
        ]);
        $checkedIds = [];

        $out = Comparator::applyOnHoldSkip($result, function (array $o) use (&$checkedIds) {
            $checkedIds[] = $o['id'];
            return $o['id'] === 2;
        });

        $this->assertSame([1, 2], $checkedIds);
        $this->assertSame(['1001'], array_column($out['missing'], 'order_number'));
        $this->assertSame(['1002'], array_column($out['skipped'], 'order_number'));
    }

    public function testEmptyMissingSkipsOnHoldCheckEntirely(): void
    {
        $result = $this->baseResult([]);
        $called = false;

        $out = Comparator::applyOnHoldSkip($result, function () use (&$called) {
            $called = true;
            return true;
        });

        $this->assertFalse($called);
        $this->assertSame($result, $out);
    }

    public function testExistingSkippedAndFoundOrdersArePreserved(): void
    {
        $result = [
            'missing' => [['id' => 1, 'order_number' => '1001']],
            'found'   => [['id' => 2]],
            'skipped' => [['id' => 3, '_skip_reason' => 'cancelled']],
            'ignored' => [['id' => 4]],
        ];

        $out = Comparator::applyOnHoldSkip($result, fn(array $o) => true);

        $this->assertSame([['id' => 2]], $out['found']);
        $this->assertSame([['id' => 4]], $out['ignored']);
        $this->assertCount(2, $out['skipped']);
        $this->assertSame('cancelled', $out['skipped'][0]['_skip_reason']);
        $this->assertSame('on_hold', $out['skipped'][1]['_skip_reason']);
    }

    public function testThrownExceptionFromIsOnHoldPropagatesWithoutSwallowing(): void
    {
        $result = $this->baseResult([['id' => 1, 'order_number' => '1001']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify API down');

        Comparator::applyOnHoldSkip($result, function () {
            throw new RuntimeException('Shopify API down');
        });
    }
}
