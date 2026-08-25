<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\OrderLookup;

/**
 * Wiring smoke test for the OrderLookup facade - each method must reach the
 * matching underlying lookup class, previously unverified.
 */
class OrderLookupTest extends TestCase
{
    private function lookup(array $responses): OrderLookup
    {
        $stack  = HandlerStack::create(new MockHandler($responses));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new OrderLookup($client);
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

    public function testFindByOrderNumberDelegatesToDirectLookup(): void
    {
        $lookup = $this->lookup([$this->json([
            'data' => ['orders' => ['edges' => [['node' => $this->orderNode('#1001')]]]],
        ])]);

        $result = $lookup->findByOrderNumber('1001');

        $this->assertSame('#1001', $result[0]['name']);
    }

    public function testGetOrderDelegatesToDirectLookup(): void
    {
        $lookup = $this->lookup([$this->json(['data' => ['order' => $this->orderNode('#1001')]])]);

        $this->assertSame('#1001', $lookup->getOrder('1001')['name']);
    }

    public function testIsOnHoldDelegatesToHoldLookup(): void
    {
        $lookup = $this->lookup([$this->json([
            'data' => ['order' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes'    => [['id' => 'fo1', 'status' => 'ON_HOLD']],
            ]]],
        ])]);

        $this->assertTrue($lookup->isOnHold('1001'));
    }

    public function testGetOrderEventsDelegatesToEventLookup(): void
    {
        $lookup = $this->lookup([$this->json([
            'data' => ['order' => ['events' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => [['node' => ['id' => 'e1', 'action' => 'CONFIRMED', 'createdAt' => '2026-06-01T00:00:00Z', 'message' => 'hi']]],
            ]]],
        ])]);

        $this->assertSame('hi', $lookup->getOrderEvents('1001')[0]['message']);
    }
}
