<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderTagInsights;

/**
 * Tests for OrderTagInsights: searchOrdersByTag()'s query building and
 * fetchTagStats()'s tag-count/last-order aggregation - previously untested.
 */
class OrderTagInsightsTest extends TestCase
{
    private function insights(array $responses, array &$history = []): OrderTagInsights
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new OrderTagInsights($client);
    }

    private function json(array $edges): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => array_map(fn($n) => ['node' => $n], $edges),
            ]],
        ]));
    }

    // ── searchOrdersByTag ────────────────────────────────────────────────────

    public function testSearchQuotesTagAndIncludesDateFilters(): void
    {
        $history = [];
        $insights = $this->insights([$this->json([])], $history);

        $insights->searchOrdersByTag('VIP', '2026-06-01', '2026-06-20');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('tag:"VIP"', $body['query']);
        $this->assertStringContainsString('created_at:>=2026-06-01T00:00:00Z', $body['query']);
        $this->assertStringContainsString('created_at:<=2026-06-20T23:59:59Z', $body['query']);
    }

    public function testSearchOmitsDateFilterWhenDatesNotProvided(): void
    {
        $history = [];
        $insights = $this->insights([$this->json([])], $history);

        $insights->searchOrdersByTag('VIP');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringNotContainsString('created_at', $body['query']);
    }

    public function testSearchQuoteInTagIsEscaped(): void
    {
        $history = [];
        $insights = $this->insights([$this->json([])], $history);

        $insights->searchOrdersByTag('a"b');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('tag:"a\"b"', $body['query']);
    }

    public function testSearchReturnsMatchedOrders(): void
    {
        $insights = $this->insights([$this->json([
            ['id' => 'gid://shopify/Order/1', 'name' => '#1001', 'tags' => ['VIP']],
        ])]);

        $result = $insights->searchOrdersByTag('VIP');

        $this->assertCount(1, $result['matches']);
        $this->assertSame(1, $result['scanned']);
    }

    // ── fetchTagStats ────────────────────────────────────────────────────────

    private function orderNode(string $name, string $createdAt, array $tags): array
    {
        return ['name' => $name, 'createdAt' => $createdAt, 'tags' => $tags];
    }

    public function testCountsOrdersPerTag(): void
    {
        $insights = $this->insights([$this->json([
            $this->orderNode('#1', '2026-06-01T00:00:00Z', ['VIP']),
            $this->orderNode('#2', '2026-06-02T00:00:00Z', ['VIP', 'Rush']),
        ])]);

        $result = $insights->fetchTagStats();

        $byTag = array_column($result['tags'], 'count', 'tag');
        $this->assertSame(2, $byTag['VIP']);
        $this->assertSame(1, $byTag['Rush']);
        $this->assertSame(2, $result['total_orders']);
    }

    public function testBlankTagsAreIgnored(): void
    {
        $insights = $this->insights([$this->json([
            $this->orderNode('#1', '2026-06-01T00:00:00Z', ['', 'VIP']),
        ])]);

        $result = $insights->fetchTagStats();

        $this->assertSame(['VIP'], array_column($result['tags'], 'tag'));
    }

    public function testTagsSortedByCountDescending(): void
    {
        $insights = $this->insights([$this->json([
            $this->orderNode('#1', '2026-06-01T00:00:00Z', ['Rush']),
            $this->orderNode('#2', '2026-06-02T00:00:00Z', ['VIP']),
            $this->orderNode('#3', '2026-06-03T00:00:00Z', ['VIP']),
        ])]);

        $result = $insights->fetchTagStats();

        $this->assertSame(['VIP', 'Rush'], array_column($result['tags'], 'tag'));
    }

    public function testLastOrderTracksMostRecentOrderPerTag(): void
    {
        $insights = $this->insights([$this->json([
            $this->orderNode('#1', '2026-06-01T00:00:00Z', ['VIP']),
            $this->orderNode('#2', '2026-06-15T00:00:00Z', ['VIP']),
            $this->orderNode('#3', '2026-06-05T00:00:00Z', ['VIP']),
        ])]);

        $result = $insights->fetchTagStats();

        $vip = $result['tags'][0];
        $this->assertSame('#2', $vip['last_order']);
        $this->assertSame('2026-06-15', $vip['last_date']);
    }
}
