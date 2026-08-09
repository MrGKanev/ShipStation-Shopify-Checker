<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\CatalogAndFulfillment;
use Shopify\GraphQL\Client;

/**
 * Tests for CatalogAndFulfillment::fetchOnHoldFulfillmentOrders() - the
 * client-side date-range filter (Shopify's fulfillmentOrders query doesn't
 * support date filtering server-side), previously untested.
 */
class CatalogAndFulfillmentTest extends TestCase
{
    private function client(array $responses): CatalogAndFulfillment
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new CatalogAndFulfillment($client);
    }

    private function json(array $edges): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => array_map(fn($n) => ['node' => $n], $edges),
            ]],
        ]));
    }

    private function fulfillmentOrderNode(string $name, string $createdAt): array
    {
        return [
            'id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'ON_HOLD',
            'order' => [
                'id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'name' => $name,
                'email' => 'jane@example.com', 'createdAt' => $createdAt,
                'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'ON_HOLD',
                'totalPriceSet' => ['shopMoney' => ['amount' => '10.00']],
            ],
            'fulfillmentHolds' => [['reason' => 'INVENTORY_OUT_OF_STOCK', 'reasonNotes' => null]],
        ];
    }

    public function testOrderWithinRangeIsIncluded(): void
    {
        $catalog = $this->client([$this->json([
            $this->fulfillmentOrderNode('#1', '2026-06-10T10:00:00Z'),
        ])]);

        $result = $catalog->fetchOnHoldFulfillmentOrders('2026-06-01', '2026-06-20');

        $this->assertCount(1, $result);
    }

    public function testOrderExactlyOnStartDateIsIncluded(): void
    {
        $catalog = $this->client([$this->json([
            $this->fulfillmentOrderNode('#1', '2026-06-01T00:00:00Z'),
        ])]);

        $result = $catalog->fetchOnHoldFulfillmentOrders('2026-06-01', '2026-06-20');

        $this->assertCount(1, $result);
    }

    public function testOrderExactlyOnEndDateIsIncluded(): void
    {
        $catalog = $this->client([$this->json([
            $this->fulfillmentOrderNode('#1', '2026-06-20T23:59:00Z'),
        ])]);

        $result = $catalog->fetchOnHoldFulfillmentOrders('2026-06-01', '2026-06-20');

        $this->assertCount(1, $result);
    }

    public function testOrderBeforeStartDateIsExcluded(): void
    {
        $catalog = $this->client([$this->json([
            $this->fulfillmentOrderNode('#1', '2026-05-31T23:59:00Z'),
        ])]);

        $result = $catalog->fetchOnHoldFulfillmentOrders('2026-06-01', '2026-06-20');

        $this->assertSame([], $result);
    }

    public function testOrderAfterEndDateIsExcluded(): void
    {
        $catalog = $this->client([$this->json([
            $this->fulfillmentOrderNode('#1', '2026-06-21T00:00:00Z'),
        ])]);

        $result = $catalog->fetchOnHoldFulfillmentOrders('2026-06-01', '2026-06-20');

        $this->assertSame([], $result);
    }
}
