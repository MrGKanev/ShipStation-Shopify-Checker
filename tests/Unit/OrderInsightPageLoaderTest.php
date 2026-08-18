<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ShipStation.php';
require_once __DIR__ . '/../../src/OrderInsightPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OrderInsightPageLoaderTest extends TestCase
{
    private array $previousGet;
    private array $previousPost;

    protected function setUp(): void
    {
        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testCompareInitialStateUsesQueryInputs(): void
    {
        $_GET = ['a' => '#1001', 'b' => '1002'];

        $data = OrderInsightPageLoader::load('compare', '', $this->ctx());

        $this->assertNull($data['compareResult']);
        $this->assertSame('', $data['compareError']);
        $this->assertSame('#1001', $data['compareA']);
        $this->assertSame('1002', $data['compareB']);
    }

    public function testCompareRequiresTwoOrderNumbersBeforeCredentials(): void
    {
        $_POST = ['compare_a' => '#1001', 'compare_b' => ''];

        $data = OrderInsightPageLoader::load('compare', 'compare_orders', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['compareResult']);
        $this->assertSame('Enter two order numbers to compare.', $data['compareError']);
        $this->assertSame('1001', $data['compareA']);
        $this->assertSame('', $data['compareB']);
    }

    public function testCompareMissingShopifyCredentials(): void
    {
        $_POST = ['compare_a' => '#1001', 'compare_b' => '1002'];

        $data = OrderInsightPageLoader::load('compare', 'compare_orders', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['compareResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['compareError']);
        $this->assertSame('1001', $data['compareA']);
        $this->assertSame('1002', $data['compareB']);
    }

    public function testCompareSurfacesErrorMessageWhenShopifyThrows(): void
    {
        $_POST = ['compare_a' => '#1001', 'compare_b' => '1002'];

        $stack = HandlerStack::create(new MockHandler([new Response(500, [], 'Internal Server Error')]));
        $data  = OrderInsightPageLoader::load('compare', 'compare_orders', $this->ctx(['httpStack' => $stack]));

        $this->assertNull($data['compareResult']);
        $this->assertNotSame('', $data['compareError']);
    }

    public function testTimelineInitialStateUsesQueryInput(): void
    {
        $_GET = ['order' => '#1001'];

        $data = OrderInsightPageLoader::load('timeline', '', $this->ctx());

        $this->assertSame('#1001', $data['tlInput']);
        $this->assertNull($data['tlResult']);
        $this->assertSame('', $data['tlError']);
    }

    public function testTimelineRequiresOrderNumberBeforeCredentials(): void
    {
        $_POST = ['tl_order' => ''];

        $data = OrderInsightPageLoader::load('timeline', 'order_timeline', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertSame('', $data['tlInput']);
        $this->assertNull($data['tlResult']);
        $this->assertSame('Enter an order number.', $data['tlError']);
    }

    public function testTimelineMissingShopifyCredentials(): void
    {
        $_POST = ['tl_order' => '#1001'];

        $data = OrderInsightPageLoader::load('timeline', 'order_timeline', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertSame('#1001', $data['tlInput']);
        $this->assertNull($data['tlResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['tlError']);
    }

    public function testCompareSuccessFetchesBothOrders(): void
    {
        $_POST = ['compare_a' => '#1001', 'compare_b' => '#1002'];
        $nodeA = $this->orderNode(['name' => '#1001', 'legacyResourceId' => '1001']);
        $nodeB = $this->orderNode(['name' => '#1002', 'legacyResourceId' => '1002']);

        $stack = HandlerStack::create(new MockHandler([
            $this->graphQLOrders([$nodeA]),
            $this->json(['data' => ['order' => $nodeA]]),
            $this->json(['orders' => []]),
            $this->graphQLOrders([$nodeB]),
            $this->json(['data' => ['order' => $nodeB]]),
            $this->json(['orders' => []]),
        ]));

        $data = OrderInsightPageLoader::load('compare', 'compare_orders', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['compareError']);
        $this->assertSame('#1001', $data['compareResult']['a']['shopify']['name']);
        $this->assertSame('#1002', $data['compareResult']['b']['shopify']['name']);
    }

    public function testTimelineSuccessBuildsTimelineWithSsData(): void
    {
        $_POST = ['tl_order' => '#1001'];
        $node = $this->orderNode();

        $stack = HandlerStack::create(new MockHandler([
            $this->graphQLOrders([$node]),
            $this->json(['data' => ['order' => $node]]),
            $this->graphQLEvents([]),
            $this->json(['orders' => [['orderId' => 5, 'orderNumber' => '1001', 'orderStatus' => 'awaiting_shipment']]]),
            $this->json(['shipments' => []]),
        ]));

        $data = OrderInsightPageLoader::load('timeline', 'order_timeline', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['tlError']);
        $this->assertNotNull($data['tlResult']);
        $this->assertSame('#1001', $data['tlResult']['label']);
        $this->assertCount(1, $data['tlResult']['ss_orders']);
        $this->assertIsArray($data['tlResult']['timeline']);
    }

    public function testTimelineSurfacesErrorWhenShipStationThrows(): void
    {
        $_POST = ['tl_order' => '#1001'];
        $node = $this->orderNode();

        $stack = HandlerStack::create(new MockHandler([
            $this->graphQLOrders([$node]),
            $this->json(['data' => ['order' => $node]]),
            $this->graphQLEvents([]),
            new Response(500, [], 'Internal Server Error'),
        ]));

        $data = OrderInsightPageLoader::load('timeline', 'order_timeline', $this->ctx(['httpStack' => $stack]));

        $this->assertNull($data['tlResult']);
        $this->assertNotSame('', $data['tlError']);
    }

    public function testTimelineSurfacesErrorWhenOrderNotFound(): void
    {
        $_POST = ['tl_order' => '#9999'];
        $stack = HandlerStack::create(new MockHandler([$this->graphQLOrders([])]));

        $data = OrderInsightPageLoader::load('timeline', 'order_timeline', $this->ctx(['httpStack' => $stack]));

        $this->assertNull($data['tlResult']);
        $this->assertSame('Order #9999 not found in Shopify.', $data['tlError']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], OrderInsightPageLoader::load('unknown', '', $this->ctx()));
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    private function graphQLOrders(array $nodes): Response
    {
        return $this->json([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges'    => array_map(fn($node) => ['node' => $node], $nodes),
                ],
            ],
        ]);
    }

    private function graphQLEvents(array $nodes): Response
    {
        return $this->json([
            'data' => [
                'events' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges'    => array_map(fn($node) => ['node' => $node], $nodes),
                ],
            ],
        ]);
    }

    private function orderNode(array $overrides = []): array
    {
        return array_replace_recursive([
            'id'                       => 'gid://shopify/Order/1001',
            'legacyResourceId'         => '1001',
            'name'                     => '#1001',
            'createdAt'                => '2026-06-15T10:00:00Z',
            'cancelledAt'              => null,
            'email'                    => 'buyer@example.com',
            'displayFinancialStatus'   => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet'            => ['shopMoney' => ['amount' => '99.00', 'currencyCode' => 'USD']],
        ], $overrides);
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
            'ssKey'        => 'ss_key',
            'ssSecret'     => 'ss_secret',
        ];
    }
}
