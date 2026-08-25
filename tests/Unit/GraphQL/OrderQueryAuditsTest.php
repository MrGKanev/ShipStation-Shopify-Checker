<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\OrderFetcher;
use Shopify\GraphQL\OrderQueryAudits;
use Shopify\GraphQL\Queries;

/**
 * Tests for OrderQueryAudits - the node-filter closures passed to
 * OrderFetcher::fetchOrdersByQuery() are the only real logic here and were
 * previously untested. OrderFetcher is mocked so each filter can be invoked
 * directly against sample nodes.
 */
class OrderQueryAuditsTest extends TestCase
{
    private function audits(callable $capture): OrderQueryAudits
    {
        $orders = $this->createStub(OrderFetcher::class);
        $orders->method('fetchOrdersByQuery')->willReturnCallback(
            function (string $query, string $fields, ?callable $filter = null) use ($capture) {
                $capture($query, $fields, $filter);
                return [];
            }
        );
        return new OrderQueryAudits($orders);
    }

    private function node(array $overrides = []): array
    {
        return array_merge(['displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'cancelledAt' => null], $overrides);
    }

    public function testFetchOrdersForAddressScanPassesNoFilter(): void
    {
        $filter = null;
        $this->audits(function ($q, $f, $filt) use (&$filter) { $filter = $filt; })
            ->fetchOrdersForAddressScan('2026-01-01', '2026-01-31');

        $this->assertNull($filter);
    }

    public function testFetchOrdersForAddressScanUsesPaidOrdersQuery(): void
    {
        $query = null;
        $this->audits(function ($q, $f, $filt) use (&$query) { $query = $q; })
            ->fetchOrdersForAddressScan('2026-01-01', '2026-01-31', true);

        $this->assertSame(Queries::paidOrdersQuery('2026-01-01', '2026-01-31', true), $query);
    }

    public function testFetchRefundedOrdersFilterMatchesOnlyRefundedStatuses(): void
    {
        $filter = null;
        $this->audits(function ($q, $f, $filt) use (&$filter) { $filter = $filt; })
            ->fetchRefundedOrders('2026-01-01', '2026-01-31');

        $this->assertTrue($filter($this->node(['displayFinancialStatus' => 'REFUNDED'])));
        $this->assertTrue($filter($this->node(['displayFinancialStatus' => 'PARTIALLY_REFUNDED'])));
        $this->assertFalse($filter($this->node(['displayFinancialStatus' => 'PAID'])));
    }

    public function testFetchOrdersRefundedSinceUsesSameFilterAsRefundedOrders(): void
    {
        $filter = null;
        $this->audits(function ($q, $f, $filt) use (&$filter) { $filter = $filt; })
            ->fetchOrdersRefundedSince('2026-01-01');

        $this->assertTrue($filter($this->node(['displayFinancialStatus' => 'REFUNDED'])));
        $this->assertFalse($filter($this->node(['displayFinancialStatus' => 'PAID'])));
    }

    public function testFetchPartiallyFulfilledOrdersFilterMatchesOnlyPartial(): void
    {
        $filter = null;
        $this->audits(function ($q, $f, $filt) use (&$filter) { $filter = $filt; })
            ->fetchPartiallyFulfilledOrders('2026-01-01', '2026-01-31');

        $this->assertTrue($filter($this->node(['displayFulfillmentStatus' => 'PARTIALLY_FULFILLED'])));
        $this->assertFalse($filter($this->node(['displayFulfillmentStatus' => 'FULFILLED'])));
        $this->assertFalse($filter($this->node(['displayFulfillmentStatus' => 'UNFULFILLED'])));
    }

    public function testFetchOrdersFulfilledSinceFilterMatchesFulfilledAndPartial(): void
    {
        $filter = null;
        $this->audits(function ($q, $f, $filt) use (&$filter) { $filter = $filt; })
            ->fetchOrdersFulfilledSince('2026-01-01');

        $this->assertTrue($filter($this->node(['displayFulfillmentStatus' => 'FULFILLED'])));
        $this->assertTrue($filter($this->node(['displayFulfillmentStatus' => 'PARTIALLY_FULFILLED'])));
        $this->assertFalse($filter($this->node(['displayFulfillmentStatus' => 'UNFULFILLED'])));
    }

    public function testFetchOrdersFulfilledSinceWithShippingUsesSameFilter(): void
    {
        $filter = null;
        $this->audits(function ($q, $f, $filt) use (&$filter) { $filter = $filt; })
            ->fetchOrdersFulfilledSinceWithShipping('2026-01-01');

        $this->assertTrue($filter($this->node(['displayFulfillmentStatus' => 'FULFILLED'])));
        $this->assertFalse($filter($this->node(['displayFulfillmentStatus' => 'UNFULFILLED'])));
    }

    public function testFetchCancelledOrdersFilterMatchesOnlyCancelled(): void
    {
        $filter = null;
        $this->audits(function ($q, $f, $filt) use (&$filter) { $filter = $filt; })
            ->fetchCancelledOrders('2026-01-01', '2026-01-31');

        $this->assertTrue($filter($this->node(['cancelledAt' => '2026-01-15T00:00:00Z'])));
        $this->assertFalse($filter($this->node(['cancelledAt' => null])));
    }

    public function testFetchOrdersForFraudRiskPassesNoFilterAndIncludesRiskFields(): void
    {
        $filter = null;
        $fields = null;
        $this->audits(function ($q, $f, $filt) use (&$filter, &$fields) { $filter = $filt; $fields = $f; })
            ->fetchOrdersForFraudRisk('2026-01-01', '2026-01-31');

        $this->assertNull($filter);
        $this->assertStringContainsString('risk {', $fields);
        $this->assertStringContainsString('shippingAddress', $fields);
        $this->assertStringContainsString('billingAddress', $fields);
    }

    public function testFetchOrdersForSameIpIncludesClientIpField(): void
    {
        $fields = null;
        $this->audits(function ($q, $f, $filt) use (&$fields) { $fields = $f; })
            ->fetchOrdersForSameIp('2026-01-01', '2026-01-31');

        $this->assertStringContainsString('clientIp', $fields);
    }
}
