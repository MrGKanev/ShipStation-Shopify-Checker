<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderHoldLookup;

/**
 * Tests for OrderHoldLookup::isOnHold() - backs Comparator::applyOnHoldSkip()
 * (see docs: "OrderHoldLookup backs isOnHold used by the on_hold skip logic,
 * currently untested"). Comparator::applyOnHoldSkip() was tested with a fake
 * callable; this is the first test of the real implementation behind it.
 */
class OrderHoldLookupTest extends TestCase
{
    private function makeStack(array $responses, array &$history = []): HandlerStack
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return $stack;
    }

    private function lookup(array $responses, array &$history = [], ?Cache $cache = null): OrderHoldLookup
    {
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $this->makeStack($responses, $history));
        return new OrderHoldLookup($client, $cache);
    }

    private function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
    }

    private function fulfillmentOrdersResponse(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): Response
    {
        return $this->json([
            'data' => [
                'order' => [
                    'fulfillmentOrders' => [
                        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                        'nodes'    => $nodes,
                    ],
                ],
            ],
        ]);
    }

    public function testReturnsFalseWhenNoFulfillmentOrdersOnHold(): void
    {
        $lookup = $this->lookup([
            $this->fulfillmentOrdersResponse([['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN']]),
        ]);

        $this->assertFalse($lookup->isOnHold('12345'));
    }

    public function testReturnsTrueWhenAFulfillmentOrderIsOnHold(): void
    {
        $lookup = $this->lookup([
            $this->fulfillmentOrdersResponse([
                ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN'],
                ['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'ON_HOLD'],
            ]),
        ]);

        $this->assertTrue($lookup->isOnHold('12345'));
    }

    public function testStatusComparisonIsCaseInsensitive(): void
    {
        $lookup = $this->lookup([
            $this->fulfillmentOrdersResponse([['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'on_hold']]),
        ]);

        $this->assertTrue($lookup->isOnHold('12345'));
    }

    public function testPaginatesAcrossMultiplePagesAndFindsHoldOnLaterPage(): void
    {
        $history = [];
        $lookup = $this->lookup([
            $this->fulfillmentOrdersResponse([['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN']], true, 'cursor-1'),
            $this->fulfillmentOrdersResponse([['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'ON_HOLD']]),
        ], $history);

        $this->assertTrue($lookup->isOnHold('12345'));
        $this->assertCount(2, $history);

        $secondBody = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('cursor-1', $secondBody['variables']['after']);
    }

    public function testMissingOrderDataReturnsFalseWithoutThrowing(): void
    {
        $lookup = $this->lookup([$this->json(['data' => ['order' => null]])]);

        $this->assertFalse($lookup->isOnHold('99999'));
    }

    public function testPassesNormalisedOrderGidToQuery(): void
    {
        $history = [];
        $lookup = $this->lookup([$this->fulfillmentOrdersResponse([])], $history);

        $lookup->isOnHold('12345');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('gid://shopify/Order/12345', $body['variables']['id']);
    }

    public function testResultIsCachedAndDoesNotRepeatTheHttpCall(): void
    {
        $tmpDir = sys_get_temp_dir() . '/order_hold_lookup_' . uniqid();
        $cache = new Cache($tmpDir, ttl: 3600);
        $history = [];

        try {
            $lookup = $this->lookup([
                $this->fulfillmentOrdersResponse([['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'ON_HOLD']]),
            ], $history, $cache);

            $this->assertTrue($lookup->isOnHold('12345'));
            $this->assertTrue($lookup->isOnHold('12345'));
            $this->assertCount(1, $history, 'second call should be served from cache, not a repeat HTTP request');
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) unlink($f);
            @rmdir($tmpDir);
        }
    }
}
