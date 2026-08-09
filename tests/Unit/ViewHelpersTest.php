<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ViewHelpersTest extends TestCase
{
    // ── formatPrice ───────────────────────────────────────────────────────────

    public function testFormatPriceNull(): void
    {
        $this->assertSame('-', formatPrice(null));
    }

    public function testFormatPriceEmptyString(): void
    {
        $this->assertSame('-', formatPrice(''));
    }

    public function testFormatPriceZero(): void
    {
        $this->assertSame('$0.00', formatPrice(0));
    }

    public function testFormatPriceInteger(): void
    {
        $this->assertSame('$25.00', formatPrice(25));
    }

    public function testFormatPriceFloat(): void
    {
        $this->assertSame('$19.99', formatPrice(19.99));
    }

    public function testFormatPriceStringFloat(): void
    {
        $this->assertSame('$99.90', formatPrice('99.9'));
    }

    public function testFormatPriceThousandsSeparator(): void
    {
        $this->assertSame('$1,234.50', formatPrice(1234.5));
    }

    // ── financialChip ─────────────────────────────────────────────────────────

    public function testFinancialChipPaid(): void
    {
        $this->assertSame('chip-paid', financialChip('paid'));
    }

    public function testFinancialChipCaseInsensitive(): void
    {
        $this->assertSame('chip-paid', financialChip('PAID'));
    }

    public function testFinancialChipPartiallyPaid(): void
    {
        $this->assertSame('chip-partial', financialChip('partially_paid'));
    }

    public function testFinancialChipUnpaid(): void
    {
        $this->assertSame('chip-unpaid', financialChip('unpaid'));
    }

    public function testFinancialChipPending(): void
    {
        $this->assertSame('chip-unpaid', financialChip('pending'));
    }

    public function testFinancialChipUnknown(): void
    {
        $this->assertSame('chip-unknown', financialChip('voided'));
    }

    // ── formatAddressLine ─────────────────────────────────────────────────────

    public function testFormatAddressLineNull(): void
    {
        $this->assertSame('', formatAddressLine(null));
    }

    public function testFormatAddressLineEmpty(): void
    {
        $this->assertSame('', formatAddressLine([]));
    }

    public function testFormatAddressLineCityCountry(): void
    {
        $result = formatAddressLine(['city' => 'New York', 'country_code' => 'US']);
        $this->assertSame('New York, US', $result);
    }

    public function testFormatAddressLineFull(): void
    {
        $result = formatAddressLine([
            'address1'     => '123 Main St',
            'address2'     => 'Apt 4',
            'city'         => 'Boston',
            'province_code'=> 'MA',
            'zip'          => '02101',
            'country_code' => 'US',
        ]);
        $this->assertSame('123 Main St Apt 4, Boston, MA, 02101, US', $result);
    }

    public function testFormatAddressLineSkipsEmpty(): void
    {
        $result = formatAddressLine(['city' => 'Paris', 'zip' => '', 'country_code' => 'FR']);
        $this->assertSame('Paris, FR', $result);
    }

    public function testFormatAddressLineFallsBackToState(): void
    {
        // province_code absent, state present
        $result = formatAddressLine(['city' => 'Austin', 'state' => 'TX', 'country_code' => 'US']);
        $this->assertSame('Austin, TX, US', $result);
    }

    // ── topbar ────────────────────────────────────────────────────────────────

    public function testTopbarContainsTitle(): void
    {
        $html = topbar('My Page');
        $this->assertStringContainsString('<h1>My Page</h1>', $html);
        $this->assertStringContainsString('class="topbar"', $html);
    }

    public function testTopbarContainsMeta(): void
    {
        $html = topbar('Title', 'Some subtitle');
        $this->assertStringContainsString('<div class="meta">Some subtitle</div>', $html);
    }

    public function testTopbarNoMetaWhenEmpty(): void
    {
        $html = topbar('Title');
        $this->assertStringNotContainsString('class="meta"', $html);
    }

    public function testTopbarEscapesMeta(): void
    {
        $html = topbar('T', '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── featureInfoStart / featureInfoEnd ─────────────────────────────────────

    public function testFeatureInfoStructure(): void
    {
        $html = featureInfoStart('mykey', 'My Label') . '<p>body</p>' . featureInfoEnd();

        $this->assertStringContainsString('data-info-key="mykey"', $html);
        $this->assertStringContainsString('About: My Label', $html);
        $this->assertStringContainsString('class="feature-info-body"', $html);
        $this->assertStringContainsString('<p>body</p>', $html);
    }

    // ── orderNumCell ──────────────────────────────────────────────────────────

    public function testOrderNumCellNoUrl(): void
    {
        $html = orderNumCell('#1001', null);
        $this->assertStringContainsString('class="order-num"', $html);
        $this->assertStringContainsString('#1001', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('data-copy="1001"', $html);
    }

    public function testOrderNumCellWithUrl(): void
    {
        $html = orderNumCell('#1002', 'https://example.com/orders/1002');
        $this->assertStringContainsString('<a href="https://example.com/orders/1002"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('data-copy="1002"', $html);
    }

    public function testOrderNumCellCustomCopyVal(): void
    {
        $html = orderNumCell('#1003', null, 'CUSTOM');
        $this->assertStringContainsString('data-copy="CUSTOM"', $html);
    }

    public function testOrderNumCellRowspan(): void
    {
        $html = orderNumCell('#1004', null, null, 3);
        $this->assertStringContainsString('rowspan="3"', $html);
    }

    public function testOrderNumCellNoRowspanByDefault(): void
    {
        $html = orderNumCell('#1005', null);
        $this->assertStringNotContainsString('rowspan', $html);
    }

    public function testOrderNumCellEscapesUrl(): void
    {
        $html = orderNumCell('#1006', 'https://example.com/?a=1&b=2');
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringNotContainsString('"https://example.com/?a=1&b=2"', $html);
    }

    // ── actionLinks ───────────────────────────────────────────────────────────

    public function testActionLinksEmpty(): void
    {
        $html = actionLinks([]);
        $this->assertStringContainsString('class="td-actions"', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testActionLinksSsUrl(): void
    {
        $html = actionLinks(['ssUrl' => 'https://ship.example.com/order/1']);
        $this->assertStringContainsString('href="https://ship.example.com/order/1"', $html);
        $this->assertStringContainsString('Open in SS', $html);
    }

    public function testActionLinksSsUrlCustomLabel(): void
    {
        $html = actionLinks(['ssUrl' => 'https://ship.example.com/order/1', 'ssLabel' => 'View SS']);
        $this->assertStringContainsString('View SS', $html);
    }

    public function testActionLinksShopifyUrl(): void
    {
        $html = actionLinks(['shopifyUrl' => 'https://store.myshopify.com/orders/99']);
        $this->assertStringContainsString('View in Shopify', $html);
        $this->assertStringContainsString('href="https://store.myshopify.com/orders/99"', $html);
    }

    public function testActionLinksSpotcheck(): void
    {
        $html = actionLinks(['orderNum' => '#2001', 'spotcheck' => true]);
        $this->assertStringContainsString('page=spotcheck', $html);
        $this->assertStringContainsString('prefill=2001', $html);
    }

    public function testActionLinksTimeline(): void
    {
        $html = actionLinks(['orderNum' => '#2002', 'timeline' => true]);
        $this->assertStringContainsString('page=timeline', $html);
        $this->assertStringContainsString('order=2002', $html);
    }

    public function testActionLinksEmail(): void
    {
        $html = actionLinks(['email' => 'test@example.com']);
        $this->assertStringContainsString('page=customer', $html);
        $this->assertStringContainsString('email=test%40example.com', $html);
    }

    public function testActionLinksRowspan(): void
    {
        $html = actionLinks(['rowspan' => 2]);
        $this->assertStringContainsString('rowspan="2"', $html);
    }

    public function testActionLinksNoSpotcheckWithoutOrderNum(): void
    {
        $html = actionLinks(['spotcheck' => true]);
        $this->assertStringNotContainsString('spotcheck', $html);
    }

    // ── searchInput ───────────────────────────────────────────────────────────

    public function testSearchInputStructure(): void
    {
        $html = searchInput('tbl-foo', 'Filter by order…');
        $this->assertStringContainsString('class="search-wrap mb-3"', $html);
        $this->assertStringContainsString('data-target="tbl-foo"', $html);
        $this->assertStringContainsString('placeholder="Filter by order…"', $html);
        $this->assertStringContainsString('type="search"', $html);
    }

    public function testSearchInputEscapesValues(): void
    {
        $html = searchInput('tbl-<bad>', '"quoted"');
        $this->assertStringNotContainsString('<bad>', $html);
        $this->assertStringNotContainsString('"quoted"', $html);
    }

    // ── tableWrapEmpty ────────────────────────────────────────────────────────

    public function testTableWrapEmptyStructure(): void
    {
        $html = tableWrapEmpty('Nothing found', 'No results in this range.');
        $this->assertStringContainsString('class="table-wrap"', $html);
        $this->assertStringContainsString('class="empty"', $html);
        $this->assertStringContainsString('<h3>Nothing found</h3>', $html);
        $this->assertStringContainsString('<p>No results in this range.</p>', $html);
        $this->assertStringContainsString('✅', $html);
    }

    public function testTableWrapEmptyEscapesStrings(): void
    {
        $html = tableWrapEmpty('<b>Bad</b>', '<script>x</script>');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ── tableWrapHeader ───────────────────────────────────────────────────────

    public function testTableWrapHeaderSingular(): void
    {
        $html = tableWrapHeader([['x' => 1]], 'tbl-foo', 'My Title', 'my-slug', '2025-01-01');
        $this->assertStringContainsString('<h2>My Title</h2>', $html);
        $this->assertStringContainsString('1 order</span>', $html);
        $this->assertStringContainsString('data-csv-btn="#tbl-foo"', $html);
        $this->assertStringContainsString('data-csv-filename="my-slug-2025-01-01.csv"', $html);
        $this->assertStringNotContainsString('search-wrap', $html);
    }

    public function testTableWrapHeaderPlural(): void
    {
        $html = tableWrapHeader([[], []], 'tbl-bar', 'Title', 'slug', '2025-06-01');
        $this->assertStringContainsString('2 orders</span>', $html);
    }

    public function testTableWrapHeaderCustomUnit(): void
    {
        $html = tableWrapHeader([[], []], 'tbl-x', 'T', 's', '2025-01-01', 'shipment');
        $this->assertStringContainsString('2 shipments</span>', $html);
    }

    public function testTableWrapHeaderWithSearch(): void
    {
        $html = tableWrapHeader([['x' => 1]], 'tbl-s', 'T', 's', '2025-01-01', 'order', 'Filter…');
        $this->assertStringContainsString('search-wrap', $html);
        $this->assertStringContainsString('data-target="tbl-s"', $html);
        $this->assertStringContainsString('Filter…', $html);
    }

    public function testTableWrapHeaderEscapesTitle(): void
    {
        $html = tableWrapHeader([['x' => 1]], 'tbl-x', '<script>', 'slug', '2025-01-01');
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ── timeGapDisplay ───────────────────────────────────────────────────────
    // Documented thresholds (Order Edit History / Address Changes): red >= 1
    // day, yellow >= 1 hour, otherwise muted.

    public function testUnderOneHourIsMuted(): void
    {
        $gap = timeGapDisplay(45);
        $this->assertSame('45m', $gap['label']);
        $this->assertSame('var(--muted)', $gap['color']);
    }

    public function testExactlyOneHourIsWarn(): void
    {
        $gap = timeGapDisplay(60);
        $this->assertSame('1h', $gap['label']);
        $this->assertSame('var(--warn)', $gap['color']);
    }

    public function testHoursAndMinutesLabel(): void
    {
        $gap = timeGapDisplay(125); // 2h 5m
        $this->assertSame('2h 5m', $gap['label']);
        $this->assertSame('var(--warn)', $gap['color']);
    }

    public function testExactlyOneDayIsDanger(): void
    {
        $gap = timeGapDisplay(1440);
        $this->assertSame('1d', $gap['label']);
        $this->assertSame('var(--danger)', $gap['color']);
    }

    public function testJustUnderOneDayIsWarnNotDanger(): void
    {
        $gap = timeGapDisplay(1439);
        $this->assertSame('var(--warn)', $gap['color']);
    }

    public function testDaysAndHoursLabel(): void
    {
        $gap = timeGapDisplay(1440 * 2 + 60 * 3); // 2d 3h
        $this->assertSame('2d 3h', $gap['label']);
        $this->assertSame('var(--danger)', $gap['color']);
    }

    public function testNegativeMinutesClampedToZero(): void
    {
        $gap = timeGapDisplay(-5);
        $this->assertSame('0m', $gap['label']);
        $this->assertSame('var(--muted)', $gap['color']);
    }

    // ── injectCsrfTokens ─────────────────────────────────────────────────────
    // Extracted from index.php's post-render pass, previously untested
    // (docs: index.php is a top-level script, not directly unit-testable).

    public function testInjectsHiddenFieldIntoPostForm(): void
    {
        $html = injectCsrfTokens('<form method="post"><input name="x"></form>', 'tok-123');

        $this->assertStringContainsString('<input type="hidden" name="_csrf" value="tok-123">', $html);
    }

    public function testDoesNotInjectIntoGetForm(): void
    {
        $html = injectCsrfTokens('<form method="get"><input name="x"></form>', 'tok-123');

        $this->assertStringNotContainsString('_csrf', $html);
    }

    public function testDoesNotInjectIntoFormWithoutMethodAttribute(): void
    {
        $html = injectCsrfTokens('<form action="?"><input name="x"></form>', 'tok-123');

        $this->assertStringNotContainsString('_csrf', $html);
    }

    public function testSkipsFormThatAlreadyHasCsrfField(): void
    {
        $original = '<form method="post"><input type="hidden" name="_csrf" value="existing"></form>';

        $html = injectCsrfTokens($original, 'tok-123');

        $this->assertSame($original, $html);
    }

    public function testMethodAttributeMatchIsCaseInsensitive(): void
    {
        $html = injectCsrfTokens('<form METHOD="POST"><input name="x"></form>', 'tok-123');

        $this->assertStringContainsString('name="_csrf"', $html);
    }

    public function testHandlesMultipleFormsIndependently(): void
    {
        $html = injectCsrfTokens(
            '<form method="post"></form><form method="get"></form><form method="post"></form>',
            'tok-123'
        );

        $this->assertSame(2, substr_count($html, '_csrf'));
    }

    public function testTokenValueIsHtmlEscaped(): void
    {
        $html = injectCsrfTokens('<form method="post"></form>', '"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── severityColor ────────────────────────────────────────────────────────
    // Backs the previously-duplicated (and, in 3 other views, mistyped
    // "var(--warning)" instead of the actually-defined "var(--warn)") aging
    // color logic in partialfulfill.php, onholdstall.php, notracking.php.

    public function testAtOrAboveDangerThresholdIsDanger(): void
    {
        $this->assertSame('var(--danger)', severityColor(30, 30, 14));
        $this->assertSame('var(--danger)', severityColor(45, 30, 14));
    }

    public function testAtOrAboveWarnThresholdBelowDangerIsWarn(): void
    {
        $this->assertSame('var(--warn)', severityColor(14, 30, 14));
        $this->assertSame('var(--warn)', severityColor(29, 30, 14));
    }

    public function testJustUnderWarnThresholdIsNeutral(): void
    {
        $this->assertSame('inherit', severityColor(13, 30, 14));
    }

    public function testNullValueIsAlwaysNeutralRegardlessOfDirection(): void
    {
        $this->assertSame('inherit', severityColor(null, 30, 14));
        $this->assertSame('', severityColor(null, 30, 14, false, ''));
        $this->assertSame('inherit', severityColor(null, 7, 14, true));
    }

    public function testLowerIsWorseInvertsComparison(): void
    {
        // "days of stock remaining" style metric: 6 days left is critical.
        $this->assertSame('var(--danger)', severityColor(6, 7, 14, true));
        $this->assertSame('var(--warn)', severityColor(10, 7, 14, true));
        $this->assertSame('inherit', severityColor(20, 7, 14, true));
    }

    public function testLowerIsWorseBoundariesAreExclusiveAtTheThreshold(): void
    {
        // Exactly at the threshold is not yet in the worse band.
        $this->assertSame('var(--warn)', severityColor(7, 7, 14, true));
        $this->assertSame('inherit', severityColor(14, 7, 14, true));
    }

    public function testCustomNeutralColorIsRespected(): void
    {
        $this->assertSame('', severityColor(1, 30, 14, false, ''));
    }
}
