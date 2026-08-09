<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\DuplicateOrderInsights;

/**
 * Tests for DuplicateOrderInsights::findDuplicateOrders() - real matching
 * logic (group by email+amount, pair within a 600s window) behind the
 * "dupes" search feature, previously untested (docs: GraphQL facade classes
 * with genuine parsing/aggregation logic had no dedicated tests).
 */
class DuplicateOrderInsightsTest extends TestCase
{
    private function insights(array $responses): DuplicateOrderInsights
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new DuplicateOrderInsights($client);
    }

    private function json(array $edges, bool $hasNextPage = false, ?string $endCursor = null): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
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

    public function testSameEmailAndAmountWithin600SecondsIsAPair(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'createdAt' => '2026-06-01T10:00:00Z']),
            $this->order(['name' => '#2', 'createdAt' => '2026-06-01T10:05:00Z']), // 300s later
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertCount(1, $result['pairs']);
    }

    public function testExactly600SecondsApartIsWithinWindow(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'createdAt' => '2026-06-01T10:00:00Z']),
            $this->order(['name' => '#2', 'createdAt' => '2026-06-01T10:10:00Z']), // exactly 600s
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertCount(1, $result['pairs']);
    }

    public function testJustOver600SecondsIsOutsideWindow(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'createdAt' => '2026-06-01T10:00:00Z']),
            $this->order(['name' => '#2', 'createdAt' => '2026-06-01T10:10:01Z']), // 601s
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertSame([], $result['pairs']);
    }

    public function testDifferentAmountsAreNotPaired(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']]]),
            $this->order(['name' => '#2', 'totalPriceSet' => ['shopMoney' => ['amount' => '75.00', 'currencyCode' => 'USD']]]),
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertSame([], $result['pairs']);
    }

    public function testOrdersWithoutEmailAreIgnored(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'email' => '']),
            $this->order(['name' => '#2', 'email' => '']),
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertSame([], $result['pairs']);
    }

    public function testEmailComparisonIsCaseInsensitiveAndTrimmed(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'email' => 'Jane@Example.com ']),
            $this->order(['name' => '#2', 'email' => ' jane@example.com']),
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertCount(1, $result['pairs']);
    }

    public function testScannedCountsAllFetchedOrders(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'email' => 'a@b.com']),
            $this->order(['name' => '#2', 'email' => 'c@d.com']),
        ])]);

        $result = $insights->findDuplicateOrders('2026-06-01', '2026-06-02');

        $this->assertSame(2, $result['scanned']);
    }
}
