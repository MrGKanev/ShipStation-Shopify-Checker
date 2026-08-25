<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderEventLookup;

/**
 * Tests for OrderEventLookup::getOrderEvents() - pagination, the missing
 * order/connection short-circuit, and event normalization, previously
 * untested.
 */
class OrderEventLookupTest extends TestCase
{
    private array $history = [];

    private function lookup(array $responses): OrderEventLookup
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new OrderEventLookup($client);
    }

    private function eventsPage(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): Response
    {
        return new Response(200, [], json_encode([
            'data' => ['order' => ['events' => [
                'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                'edges'    => array_map(fn($n) => ['node' => $n], $nodes),
            ]]],
        ]));
    }

    private function eventNode(string $message): array
    {
        return ['id' => 'gid://shopify/OrderEvent/1', 'action' => 'CONFIRMED', 'createdAt' => '2026-06-01T00:00:00Z', 'message' => $message];
    }

    public function testReturnsNormalizedEvents(): void
    {
        $lookup = $this->lookup([$this->eventsPage([$this->eventNode('Order confirmed')])]);

        $result = $lookup->getOrderEvents('1001');

        $this->assertCount(1, $result);
        $this->assertSame('Order confirmed', $result[0]['message']);
        $this->assertSame('confirmed', $result[0]['action']);
    }

    public function testFollowsPaginationCursor(): void
    {
        $lookup = $this->lookup([
            $this->eventsPage([$this->eventNode('page 1')], true, 'cursor-1'),
            $this->eventsPage([$this->eventNode('page 2')], false, null),
        ]);

        $result = $lookup->getOrderEvents('1001');

        $this->assertCount(2, $result);
        $this->assertSame('page 1', $result[0]['message']);
        $this->assertSame('page 2', $result[1]['message']);

        $secondBody = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('cursor-1', $secondBody['variables']['after']);
    }

    public function testReturnsEmptyArrayWhenOrderMissing(): void
    {
        $lookup = $this->lookup([new Response(200, [], json_encode(['data' => ['order' => null]]))]);

        $this->assertSame([], $lookup->getOrderEvents('999999'));
    }
}
