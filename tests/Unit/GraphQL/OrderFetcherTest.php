<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderFetcher;

/**
 * Tests for OrderFetcher - id dedup/empty handling, pagination wiring,
 * node filtering, and the cache pass-through, previously untested.
 */
class OrderFetcherTest extends TestCase
{
    private array $history = [];

    private function fetcher(array $responses, ?Cache $cache = null): OrderFetcher
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new OrderFetcher($client, $cache);
    }

    private function orderNode(string $name): array
    {
        return [
            'id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'name' => $name,
            'email' => 'jane@example.com', 'createdAt' => '2026-06-01T00:00:00Z',
            'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
        ];
    }

    private function eventNode(string $message): array
    {
        return ['id' => 'gid://shopify/OrderEvent/1', 'createdAt' => '2026-06-01T00:00:00Z', 'message' => $message];
    }

    private function connectionResponse(string $rootKey, array $nodes): Response
    {
        return new Response(200, [], json_encode([
            'data' => [$rootKey => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => array_map(fn($n) => ['node' => $n], $nodes),
            ]],
        ]));
    }

    public function testFetchEventsByQueryReturnsNormalizedEvents(): void
    {
        $fetcher = $this->fetcher([$this->connectionResponse('events', [$this->eventNode('Order created')])]);

        $result = $fetcher->fetchEventsByQuery('created_at:>2026-01-01');

        $this->assertCount(1, $result);
        $this->assertSame('Order created', $result[0]['message']);
    }

    public function testFetchOrdersByIdsSkipsEmptyAndDedupes(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], json_encode([
            'data' => ['nodes' => [$this->orderNode('#1001')]],
        ]))]);

        $result = $fetcher->fetchOrdersByIds(['1', '', '1'], '{{unused}}');

        $this->assertCount(1, $result);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(['gid://shopify/Order/1'], $body['variables']['ids']);
    }

    public function testFetchOrdersByIdsReturnsEmptyArrayWithoutRequestWhenNoIds(): void
    {
        $fetcher = $this->fetcher([]);

        $this->assertSame([], $fetcher->fetchOrdersByIds([], '{{unused}}'));
        $this->assertCount(0, $this->history);
    }

    public function testFetchOrdersByIdsKeysResultByNormalizedId(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], json_encode([
            'data' => ['nodes' => [$this->orderNode('#1001')]],
        ]))]);

        $result = $fetcher->fetchOrdersByIds(['1'], '{{unused}}');

        $this->assertArrayHasKey('1', $result);
        $this->assertSame('#1001', $result['1']['name']);
    }

    public function testFetchOrdersByQueryAppliesNodeFilter(): void
    {
        $fetcher = $this->fetcher([$this->connectionResponse('orders', [
            $this->orderNode('#1001'),
            $this->orderNode('#1002'),
        ])]);

        $result = $fetcher->fetchOrdersByQuery(
            'created_at:>2026-01-01',
            '{{unused}}',
            fn(array $node) => $node['name'] === '#1002',
        );

        $this->assertCount(1, $result);
        $this->assertSame('#1002', $result[0]['name']);
    }

    public function testFetchOrdersByQueryUsesCacheOnSecondCall(): void
    {
        $dir   = sys_get_temp_dir() . '/of_cache_' . uniqid();
        $cache = new Cache($dir, 60);
        $fetcher = $this->fetcher([$this->connectionResponse('orders', [$this->orderNode('#1001')])], $cache);

        $first  = $fetcher->fetchOrdersByQuery('q', '{{f}}');
        $second = $fetcher->fetchOrdersByQuery('q', '{{f}}');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->history);
    }
}
