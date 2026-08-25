<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\AdminLookups;
use Shopify\GraphQL\Client;

/**
 * Wiring smoke test for the AdminLookups facade - each method must reach the
 * matching underlying service (orders / customData / insights), previously
 * unverified.
 */
class AdminLookupsTest extends TestCase
{
    private function lookups(array $responses): AdminLookups
    {
        $stack  = HandlerStack::create(new MockHandler($responses));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new AdminLookups($client);
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

    private function json(array $data): Response
    {
        return new Response(200, [], json_encode($data));
    }

    public function testFindByOrderNumberDelegatesToOrderLookup(): void
    {
        $lookups = $this->lookups([$this->json([
            'data' => ['orders' => ['edges' => [['node' => $this->orderNode('#1001')]]]],
        ])]);

        $this->assertSame('#1001', $lookups->findByOrderNumber('1001')[0]['name']);
    }

    public function testGetOrderMetafieldsDelegatesToCustomDataLookups(): void
    {
        $lookups = $this->lookups([$this->json([
            'data' => ['order' => ['metafields' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes'    => [['namespace' => 'custom', 'key' => 'gift_note', 'value' => 'hi', 'type' => 'single_line_text_field']],
            ]]],
        ])]);

        $result = $lookups->getOrderMetafields('1001');

        $this->assertSame('gift_note', $result[0]['key']);
    }

    public function testLookupCustomerDelegatesToInsights(): void
    {
        $node = $this->orderNode('#1001') + [
            'cancelledAt' => null,
            'tags'        => [],
            'customer'    => ['id' => 'gid://shopify/Customer/1', 'firstName' => 'Jane', 'lastName' => 'Doe', 'createdAt' => '2026-01-01', 'verifiedEmail' => true],
        ];
        $lookups = $this->lookups([$this->json([
            'data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => [['node' => $node]],
            ]],
        ])]);

        $result = $lookups->lookupCustomer('jane@example.com');

        $this->assertSame('Jane', $result['customer']['firstName']);
    }
}
