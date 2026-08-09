<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\CustomerOrderInsights;

/**
 * Tests for CustomerOrderInsights::lookupCustomer() - total_spent summation,
 * customer extraction, and query-string email escaping, previously untested.
 */
class CustomerOrderInsightsTest extends TestCase
{
    private function insights(array $responses, array &$history = []): CustomerOrderInsights
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new CustomerOrderInsights($client);
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

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'name' => '#1001',
            'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
            'cancelledAt' => null, 'createdAt' => '2026-06-01T10:00:00Z',
            'email' => 'jane@example.com', 'tags' => [],
            'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']],
            'customer' => null,
        ], $overrides);
    }

    public function testTotalSpentSumsAcrossAllOrders(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']]]),
            $this->order(['totalPriceSet' => ['shopMoney' => ['amount' => '25.50', 'currencyCode' => 'USD']]]),
        ])]);

        $result = $insights->lookupCustomer('jane@example.com');

        $this->assertSame(75.5, $result['totalSpent']);
        $this->assertSame('USD', $result['currency']);
    }

    public function testCustomerTakenFromFirstOrderThatHasOne(): void
    {
        $insights = $this->insights([$this->json([
            $this->order(['name' => '#1', 'customer' => null]),
            $this->order(['name' => '#2', 'customer' => ['id' => 'gid://shopify/Customer/1', 'firstName' => 'Jane', 'lastName' => 'Doe', 'createdAt' => '2026-01-01', 'verifiedEmail' => true]]),
            $this->order(['name' => '#3', 'customer' => ['id' => 'gid://shopify/Customer/2', 'firstName' => 'Other', 'lastName' => 'Person', 'createdAt' => '2026-01-01', 'verifiedEmail' => true]]),
        ])]);

        $result = $insights->lookupCustomer('jane@example.com');

        $this->assertSame('Jane', $result['customer']['firstName']);
    }

    public function testCustomerIsNullWhenNoOrderHasOne(): void
    {
        $insights = $this->insights([$this->json([$this->order(['customer' => null])])]);

        $result = $insights->lookupCustomer('jane@example.com');

        $this->assertNull($result['customer']);
    }

    public function testEmailIsLowercasedAndTrimmedInQuery(): void
    {
        $history = [];
        $insights = $this->insights([$this->json([])], $history);

        $insights->lookupCustomer(' Jane@Example.COM ');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('email:\"jane@example.com\"', $body['query']);
    }

    public function testQuoteInEmailIsEscapedInQueryString(): void
    {
        $history = [];
        $insights = $this->insights([$this->json([])], $history);

        // Malicious/malformed input attempting to break out of the quoted query string.
        $insights->lookupCustomer('a"@example.com');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        // addslashes() puts exactly one backslash before the embedded quote,
        // so the query text must not contain an *unescaped* quote that would
        // break out of the query: "email:\"...\"" wrapper.
        $this->assertStringContainsString('email:\"a\"@example.com\"', $body['query']);
    }

    public function testEmptyOrdersReturnsZeroSpentAndDefaultCurrency(): void
    {
        $insights = $this->insights([$this->json([])]);

        $result = $insights->lookupCustomer('nobody@example.com');

        $this->assertSame(0.0, $result['totalSpent']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame([], $result['orders']);
    }
}
