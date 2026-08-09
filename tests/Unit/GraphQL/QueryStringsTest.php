<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/Shopify/GraphQL/QueryStrings.php';

use PHPUnit\Framework\TestCase;

class QueryStringsTest extends TestCase
{
    // ── partiallyFulfilledOrdersQuery ────────────────────────────────────────
    //
    // docs/audit-checks.md documents Partial Fulfillment Stalls as excluding
    // "cancelled, fully refunded, and closed orders". status:open covers
    // cancelled/closed; fully-refunded needs its own filter (priority #2 in
    // docs/audit-test-coverage-gaps.md).

    public function testPartiallyFulfilledOrdersQueryExcludesFullyRefundedOrders(): void
    {
        $query = \Shopify\GraphQL\QueryStrings::partiallyFulfilledOrdersQuery('2026-06-01', '2026-06-20');

        $this->assertStringContainsString('-financial_status:refunded', $query);
    }

    public function testPartiallyFulfilledOrdersQueryStillFiltersOpenAndPartial(): void
    {
        $query = \Shopify\GraphQL\QueryStrings::partiallyFulfilledOrdersQuery('2026-06-01', '2026-06-20');

        $this->assertStringContainsString('status:open', $query);
        $this->assertStringContainsString('fulfillment_status:partial', $query);
        $this->assertStringContainsString('created_at:>=2026-06-01T00:00:00Z', $query);
        $this->assertStringContainsString('created_at:<=2026-06-20T23:59:59Z', $query);
    }
}
