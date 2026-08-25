<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderArchive;

/**
 * Tests for OrderArchive::fetchAllOrders() - the date-range query string,
 * pagination, and cache pass-through, previously untested.
 */
class OrderArchiveTest extends TestCase
{
    private array $history = [];

    private function archive(array $responses, ?Cache $cache = null): OrderArchive
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new OrderArchive($client, $cache);
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

    private function ordersPage(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): Response
    {
        return new Response(200, [], json_encode([
            'data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                'edges'    => array_map(fn($n) => ['node' => $n], $nodes),
            ]],
        ]));
    }

    public function testBuildsInclusiveDateRangeQuery(): void
    {
        $archive = $this->archive([$this->ordersPage([])]);

        $archive->fetchAllOrders('2026-01-01', '2026-01-31');

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(
            'status:any created_at:>=2026-01-01T00:00:00Z created_at:<=2026-01-31T23:59:59Z',
            $body['variables']['query']
        );
    }

    public function testReturnsNormalizedOrdersAcrossPages(): void
    {
        $archive = $this->archive([
            $this->ordersPage([$this->orderNode('#1')], true, 'cursor-1'),
            $this->ordersPage([$this->orderNode('#2')], false, null),
        ]);

        $result = $archive->fetchAllOrders('2026-01-01', '2026-01-31');

        $this->assertCount(2, $result);
        $this->assertSame('#1', $result[0]['name']);
        $this->assertSame('#2', $result[1]['name']);
    }

    public function testUsesCacheOnSecondCall(): void
    {
        $dir   = sys_get_temp_dir() . '/oa_cache_' . uniqid();
        $cache = new Cache($dir, 60);
        $archive = $this->archive([$this->ordersPage([$this->orderNode('#1')])], $cache);

        $first  = $archive->fetchAllOrders('2026-01-01', '2026-01-31');
        $second = $archive->fetchAllOrders('2026-01-01', '2026-01-31');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->history);
    }
}
