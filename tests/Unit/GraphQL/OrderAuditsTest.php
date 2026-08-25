<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\OrderAudits;
use Shopify\GraphQL\OrderFetcher;

/**
 * Wiring smoke test for the OrderAudits facade - query-based methods must
 * reach OrderQueryAudits and event-based methods must reach OrderEventAudits,
 * previously unverified. OrderFetcher is stubbed to log which of its methods
 * each facade call actually reaches.
 */
class OrderAuditsTest extends TestCase
{
    private function auditsLogging(array &$calls): OrderAudits
    {
        $orders = $this->createStub(OrderFetcher::class);
        $orders->method('fetchOrdersByQuery')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'fetchOrdersByQuery';
            return [];
        });
        $orders->method('fetchEventsByQuery')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'fetchEventsByQuery';
            return [];
        });
        $orders->method('fetchOrdersByIds')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'fetchOrdersByIds';
            return [];
        });
        return new OrderAudits($orders);
    }

    public function testFetchOrdersForHighValueRoutesThroughQueryAudits(): void
    {
        $calls = [];
        $this->auditsLogging($calls)->fetchOrdersForHighValue('2026-01-01', '2026-01-31');

        $this->assertSame(['fetchOrdersByQuery'], $calls);
    }

    public function testFetchCancelledOrdersRoutesThroughQueryAudits(): void
    {
        $calls = [];
        $this->auditsLogging($calls)->fetchCancelledOrders('2026-01-01', '2026-01-31');

        $this->assertSame(['fetchOrdersByQuery'], $calls);
    }

    public function testFetchEditedOrdersRoutesThroughEventAudits(): void
    {
        $calls = [];
        $this->auditsLogging($calls)->fetchEditedOrders('2026-01-01', '2026-01-31');

        $this->assertSame(['fetchEventsByQuery'], $calls);
    }

    public function testFetchOrdersWithAddressChangesRoutesThroughEventAudits(): void
    {
        $calls = [];
        $this->auditsLogging($calls)->fetchOrdersWithAddressChanges('2026-01-01', '2026-01-31');

        $this->assertSame(['fetchEventsByQuery'], $calls);
    }
}
