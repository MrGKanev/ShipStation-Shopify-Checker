<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderDirectLookup;

/**
 * Tests for OrderDirectLookup - # stripping, batch dedup/keying, and the
 * cache pass-through, previously untested.
 */
class OrderDirectLookupTest extends TestCase
{
    private array $history = [];

    private function lookup(array $responses, ?Cache $cache = null): OrderDirectLookup
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new OrderDirectLookup($client, $cache);
    }

    private function json(array $edges): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['orders' => ['edges' => array_map(fn($n) => ['node' => $n], $edges)]],
        ]));
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

    public function testFindByOrderNumberStripsHashAndWhitespace(): void
    {
        $lookup = $this->lookup([$this->json([$this->orderNode('#1001')])]);

        $lookup->findByOrderNumber(' #1001 ');

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('name:1001', $body['variables']['query']);
    }

    public function testFindByOrderNumberReturnsNormalizedOrders(): void
    {
        $lookup = $this->lookup([$this->json([$this->orderNode('#1001')])]);

        $result = $lookup->findByOrderNumber('1001');

        $this->assertCount(1, $result);
        $this->assertSame('#1001', $result[0]['name']);
    }

    public function testFindByOrderNumbersReturnsEmptyArrayForNoInput(): void
    {
        $lookup = $this->lookup([]);

        $this->assertSame([], $lookup->findByOrderNumbers([]));
        $this->assertSame([], $lookup->findByOrderNumbers(['', '  ']));
    }

    public function testFindByOrderNumbersDedupesAndBuildsOrQuery(): void
    {
        $lookup = $this->lookup([$this->json([])]);

        $lookup->findByOrderNumbers(['#1001', '1001', ' #1002 ']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('(name:1001 OR name:1002)', $body['variables']['query']);
    }

    public function testFindByOrderNumbersKeysResultsByCleanNumberAndFillsMisses(): void
    {
        $lookup = $this->lookup([$this->json([$this->orderNode('#1001')])]);

        $result = $lookup->findByOrderNumbers(['#1001', '#1002']);

        $this->assertCount(1, $result['1001']);
        $this->assertSame('#1001', $result['1001'][0]['name']);
        $this->assertSame([], $result['1002']);
    }

    public function testGetOrderReturnsNormalizedOrder(): void
    {
        $lookup = $this->lookup([new Response(200, [], json_encode([
            'data' => ['order' => $this->orderNode('#1001')],
        ]))]);

        $result = $lookup->getOrder('1001');

        $this->assertSame('#1001', $result['name']);
    }

    public function testGetOrderReturnsEmptyArrayWhenOrderMissing(): void
    {
        $lookup = $this->lookup([new Response(200, [], json_encode(['data' => ['order' => null]]))]);

        $this->assertSame([], $lookup->getOrder('999999'));
    }

    public function testFindByOrderNumberUsesCacheOnSecondCall(): void
    {
        $dir   = sys_get_temp_dir() . '/odl_cache_' . uniqid();
        $cache = new Cache($dir, 60);
        $lookup = $this->lookup([$this->json([$this->orderNode('#1001')])], $cache);

        $first  = $lookup->findByOrderNumber('1001');
        $second = $lookup->findByOrderNumber('1001');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->history);
    }
}
