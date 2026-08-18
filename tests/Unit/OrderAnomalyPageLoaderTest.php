<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/AuditSnapshot.php';
require_once __DIR__ . '/support/TmpDir.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ShipStation.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OrderAnomalyPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/order_anomaly_loader_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        RunLog::setDataDir($this->tmpDir);
        AuditSnapshot::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);
        EmailRules::setDataDir($this->tmpDir);

        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];

        $this->previousSlackWebhook = getenv('SLACK_WEBHOOK_URL');
        putenv('SLACK_WEBHOOK_URL');
    }

    protected function tearDown(): void
    {
        if ($this->previousSlackWebhook === false) {
            putenv('SLACK_WEBHOOK_URL');
        } else {
            putenv('SLACK_WEBHOOK_URL=' . $this->previousSlackWebhook);
        }

        TmpDir::remove($this->tmpDir);

        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testAddrCheckInitialStateUsesRequestRangeAndFlags(): void
    {
        $_GET = ['addr_start' => '2026-06-01', 'addr_end' => '2026-06-20'];
        $_POST = ['po_box_only' => '1', 'unfulfilled_only' => '1'];

        $data = OrderAnomalyPageLoader::load('addrcheck', '', $this->ctx());

        $this->assertNull($data['addrResult']);
        $this->assertSame('', $data['addrError']);
        $this->assertSame('2026-06-01', $data['addrStart']);
        $this->assertSame('2026-06-20', $data['addrEnd']);
        $this->assertTrue($data['poBoxOnly']);
        $this->assertTrue($data['unfulfilledOnly']);
    }

    public function testAddrCheckMissingShopifyCredentials(): void
    {
        $_POST = ['addr_start' => '2026-06-01', 'addr_end' => '2026-06-20'];

        $data = OrderAnomalyPageLoader::load('addrcheck', 'scan_addresses', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['addrResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['addrError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testRefundsMissingShopifyCredentials(): void
    {
        $_POST = ['refunds_start' => '2026-06-01', 'refunds_end' => '2026-06-20'];

        $data = OrderAnomalyPageLoader::load('refunds', 'find_refunds', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['refundsResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['refundsError']);
        $this->assertSame('2026-06-01', $data['refundsStart']);
        $this->assertSame('2026-06-20', $data['refundsEnd']);
    }

    public function testDuplicatesInitialAndMissingShopifyCredentials(): void
    {
        $_GET = ['dupes_start' => '2026-06-01', 'dupes_end' => '2026-06-20'];
        $initial = OrderAnomalyPageLoader::load('dupes', '', $this->ctx());

        $this->assertNull($initial['dupesResult']);
        $this->assertSame('', $initial['dupesError']);
        $this->assertSame('2026-06-01', $initial['dupesStart']);
        $this->assertSame('2026-06-20', $initial['dupesEnd']);

        $_GET = [];
        $_POST = ['dupes_start' => '2026-06-01', 'dupes_end' => '2026-06-20'];
        $submitted = OrderAnomalyPageLoader::load('dupes', 'find_dupes', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['dupesResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['dupesError']);
    }

    public function testDuplicatesSurfacesErrorMessageWhenShopifyThrows(): void
    {
        $_POST = ['dupes_start' => '2026-06-01', 'dupes_end' => '2026-06-20'];

        $stack = HandlerStack::create(new MockHandler([new Response(500, [], 'Internal Server Error')]));
        $data  = OrderAnomalyPageLoader::load('dupes', 'find_dupes', $this->ctx(['httpStack' => $stack]));

        $this->assertNull($data['dupesResult']);
        $this->assertNotSame('', $data['dupesError']);
    }

    public function testOrphansRequiresShipStationBeforeShopify(): void
    {
        $_POST = ['orphan_start' => '2026-06-01', 'orphan_end' => '2026-06-20'];

        $data = OrderAnomalyPageLoader::load('orphans', 'find_orphans', $this->ctx([
            'shopifyToken' => '',
            'shopifyStore' => 'N/A',
            'ssKey'        => '',
            'ssSecret'     => '',
        ]));

        $this->assertNull($data['orphanResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $data['orphanError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testRepeatRefundsCarriesMinimumAndMissingShopifyCredentials(): void
    {
        $_GET = ['rr_min_count' => '4'];
        $initial = OrderAnomalyPageLoader::load('repeatrefunds', '', $this->ctx());

        $this->assertNull($initial['rrResult']);
        $this->assertSame('', $initial['rrError']);
        $this->assertSame(4, $initial['rrMinCount']);

        $_GET = [];
        $_POST = ['rr_start' => '2026-06-01', 'rr_end' => '2026-06-20', 'rr_min_count' => '5'];
        $submitted = OrderAnomalyPageLoader::load('repeatrefunds', 'scan_repeat_refunds', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['rrResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['rrError']);
        $this->assertSame(5, $submitted['rrMinCount']);
    }

    public function testFailedShipmentsUsesLegacyCredentialMessage(): void
    {
        $_POST = ['fs_start' => '2026-06-01', 'fs_end' => '2026-06-20'];

        $data = OrderAnomalyPageLoader::load('failedship', 'scan_failed_shipments', $this->ctx([
            'ssKey'    => '',
            'ssSecret' => '',
        ]));

        $this->assertNull($data['fsResult']);
        $this->assertSame('SHIPSTATION_API_KEY / SHIPSTATION_API_SECRET not set in .env.', $data['fsError']);
    }

    public function testAddrChangesMissingShopifyCredentials(): void
    {
        $_POST = ['ac_start' => '2026-06-01', 'ac_end' => '2026-06-20'];

        $data = OrderAnomalyPageLoader::load('addrchanges', 'scan_addr_changes', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['acResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['acError']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], OrderAnomalyPageLoader::load('unknown', '', $this->ctx()));
    }

    // ── Success paths (full scan through the mocked HTTP transport) ───────────

    public function testAddrCheckSuccessFlagsOrderMissingCity(): void
    {
        $_POST = ['addr_start' => '2026-06-01', 'addr_end' => '2026-06-20'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['shippingAddress' => $this->addressNode(['city' => ''])]),
        ])]);

        $data = OrderAnomalyPageLoader::load('addrcheck', 'scan_addresses', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['addrError']);
        $this->assertCount(1, $data['addrResult']['rows']);
        $this->assertSame('critical', $data['addrResult']['rows'][0]['severity']);
    }

    public function testRefundsSuccessFlagsMissingShipStationCounterpart(): void
    {
        $_POST = ['refunds_start' => '2026-06-01', 'refunds_end' => '2026-06-20'];
        $stack = $this->shopifyStack([
            $this->graphQLOrders([$this->orderNode(['name' => '#2001', 'displayFinancialStatus' => 'REFUNDED'])]),
            $this->shipStationOrders([]),
        ]);

        $data = OrderAnomalyPageLoader::load('refunds', 'find_refunds', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['refundsError']);
        $this->assertCount(1, $data['refundsResult']['rows']);
        $this->assertSame('missing', $data['refundsResult']['rows'][0]['risk']);
    }

    public function testDuplicatesSurfacesDateValidationError(): void
    {
        $_POST = ['dupes_start' => '2026-06-20', 'dupes_end' => '2026-06-01'];

        $data = OrderAnomalyPageLoader::load('dupes', 'find_dupes', $this->ctx());

        $this->assertNull($data['dupesResult']);
        $this->assertNotSame('', $data['dupesError']);
    }

    public function testDuplicatesSuccessPairsSameEmailAndAmountWithinTenMinutes(): void
    {
        $_POST = ['dupes_start' => '2026-06-01', 'dupes_end' => '2026-06-20'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->dupeOrderNode('#3001', 'same@example.com', '50.00', '2026-06-10T10:00:00Z'),
            $this->dupeOrderNode('#3002', 'same@example.com', '50.00', '2026-06-10T10:05:00Z'),
        ])]);

        $data = OrderAnomalyPageLoader::load('dupes', 'find_dupes', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['dupesError']);
        $this->assertCount(1, $data['dupesResult']['pairs']);
    }

    public function testOrphansSuccessFlagsShipStationOrderWithNoShopifyMatch(): void
    {
        $_POST = ['orphan_start' => '2026-06-01', 'orphan_end' => '2026-06-20'];
        $stack = $this->shopifyStack([
            $this->shipStationOrders([['orderId' => 5, 'orderNumber' => '9999', 'orderStatus' => 'awaiting_shipment']]),
            $this->graphQLOrders([]),
        ]);

        $data = OrderAnomalyPageLoader::load('orphans', 'find_orphans', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['orphanError']);
        $this->assertCount(1, $data['orphanResult']['rows']);
        $this->assertSame('9999', $data['orphanResult']['rows'][0]['order_number']);
    }

    public function testRepeatRefundsSuccessGroupsCustomerWithMultipleRefunds(): void
    {
        $_POST = ['rr_start' => '2026-06-01', 'rr_end' => '2026-06-20', 'rr_min_count' => '2'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['name' => '#4001', 'email' => 'rep@example.com', 'displayFinancialStatus' => 'REFUNDED']),
            $this->orderNode(['name' => '#4002', 'email' => 'rep@example.com', 'displayFinancialStatus' => 'REFUNDED']),
        ])]);

        $data = OrderAnomalyPageLoader::load('repeatrefunds', 'scan_repeat_refunds', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['rrError']);
        $this->assertCount(1, $data['rrResult']['rows']);
        $this->assertSame('rep@example.com', $data['rrResult']['rows'][0]['email']);
        $this->assertSame(2, $data['rrResult']['rows'][0]['refund_count']);
    }

    public function testFailedShipmentsSuccessListsVoidedShipment(): void
    {
        $_POST = ['fs_start' => '2026-06-01', 'fs_end' => '2026-06-20'];
        $stack = $this->shipStationOnlyStack([$this->json([
            'shipments' => [['orderNumber' => '5001', 'shipmentId' => 77, 'voidDate' => '2026-06-10T00:00:00Z']],
            'total'     => 1,
        ])]);

        $data = OrderAnomalyPageLoader::load('failedship', 'scan_failed_shipments', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['fsError']);
        $this->assertCount(1, $data['fsResult']['rows']);
        $this->assertSame('5001', $data['fsResult']['rows'][0]['order_number']);
    }

    public function testFailedShipmentsSurfacesDateValidationError(): void
    {
        $_POST = ['fs_start' => '2026-06-20', 'fs_end' => '2026-06-01'];

        $data = OrderAnomalyPageLoader::load('failedship', 'scan_failed_shipments', $this->ctx());

        $this->assertNull($data['fsResult']);
        $this->assertNotSame('', $data['fsError']);
    }

    public function testFailedShipmentsSurfacesErrorWhenShipStationThrows(): void
    {
        $_POST = ['fs_start' => '2026-06-01', 'fs_end' => '2026-06-20'];
        $stack = $this->shipStationOnlyStack([new Response(500, [], 'Internal Server Error')]);

        $data = OrderAnomalyPageLoader::load('failedship', 'scan_failed_shipments', $this->ctx(['httpStack' => $stack]));

        $this->assertNull($data['fsResult']);
        $this->assertNotSame('', $data['fsError']);
    }

    public function testAddrChangesSuccessReturnsEmptyRowsWhenNoAddressEvents(): void
    {
        $_POST = ['ac_start' => '2026-06-01', 'ac_end' => '2026-06-20'];
        $stack = $this->shopifyStack([$this->graphQLEvents([])]);

        $data = OrderAnomalyPageLoader::load('addrchanges', 'scan_addr_changes', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['acError']);
        $this->assertSame(['rows' => [], 'start' => '2026-06-01', 'end' => '2026-06-20'], $data['acResult']);
    }

    // ── Mock HTTP helpers ───────────────────────────────────────────────────

    private function shopifyStack(array $responses): HandlerStack
    {
        return HandlerStack::create(new MockHandler($responses));
    }

    private function shipStationOnlyStack(array $responses): HandlerStack
    {
        return HandlerStack::create(new MockHandler($responses));
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

    private function shipStationOrders(array $orders): Response
    {
        return $this->json(['orders' => $orders, 'pages' => 1]);
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

    private function dupeOrderNode(string $name, string $email, string $amount, string $createdAt): array
    {
        return [
            'id'                     => 'gid://shopify/Order/' . preg_replace('/\D+/', '', $name),
            'legacyResourceId'       => preg_replace('/\D+/', '', $name),
            'name'                   => $name,
            'email'                  => $email,
            'createdAt'              => $createdAt,
            'displayFinancialStatus' => 'PAID',
            'totalPriceSet'          => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']],
        ];
    }

    private function addressNode(array $fields): array
    {
        return [
            'firstName'     => $fields['first_name'] ?? 'Jane',
            'lastName'      => $fields['last_name'] ?? 'Doe',
            'address1'      => $fields['address1'] ?? '123 Main St',
            'city'          => $fields['city'] ?? 'Austin',
            'provinceCode'  => $fields['province_code'] ?? 'TX',
            'countryCodeV2' => $fields['country_code'] ?? 'US',
            'zip'           => $fields['zip'] ?? '78701',
            'phone'         => $fields['phone'] ?? '5551234567',
        ];
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
            'ssKey'        => 'ss_key',
            'ssSecret'     => 'ss_secret',
            'cacheObj'     => null,
        ];
    }
}
