<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderInsights;

/**
 * Wiring smoke test for the OrderInsights facade - each method must reach
 * the matching underlying insights class, previously unverified.
 */
class OrderInsightsTest extends TestCase
{
    private function insights(array $responses): OrderInsights
    {
        $stack  = HandlerStack::create(new MockHandler($responses));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new OrderInsights($client);
    }

    private function ordersJson(array $edges): Response
    {
        return new Response(200, [], json_encode([
            'data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => array_map(fn($n) => ['node' => $n], $edges),
            ]],
        ]));
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'name' => '#1001',
            'email' => 'jane@example.com', 'createdAt' => '2026-06-01T10:00:00Z',
            'displayFinancialStatus' => 'PAID',
            'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']],
        ], $overrides);
    }

    public function testSearchOrdersByTagDelegatesToTagInsights(): void
    {
        $insights = $this->insights([$this->ordersJson([$this->order(['tags' => ['VIP']])])]);

        $result = $insights->searchOrdersByTag('VIP');

        $this->assertSame(1, $result['scanned']);
    }

    public function testFindDuplicateOrdersDelegatesToDuplicateInsights(): void
    {
        $insights = $this->insights([$this->ordersJson([
            $this->order(['name' => '#1', 'createdAt' => '2026-06-01T10:00:00Z']),
            $this->order(['name' => '#2', 'createdAt' => '2026-06-01T10:01:00Z']),
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertCount(1, $result['pairs']);
    }
}
