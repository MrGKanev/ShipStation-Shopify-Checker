<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class FulfillmentIssuePageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fulfillment_issue_loader_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        RunLog::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);

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

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);

        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testOnHoldInitialStateUsesRequestRange(): void
    {
        $_GET = ['oh_start' => '2026-06-01', 'oh_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('onholdstall', '', $this->ctx());

        $this->assertNull($data['ohResult']);
        $this->assertSame('', $data['ohError']);
        $this->assertSame('2026-06-01', $data['ohStart']);
        $this->assertSame('2026-06-20', $data['ohEnd']);
    }

    public function testNoTrackingCarriesThresholdAndMissingShopifyCredentials(): void
    {
        $_GET = ['nt_threshold' => '36'];
        $initial = FulfillmentIssuePageLoader::load('notracking', '', $this->ctx());

        $this->assertNull($initial['ntResult']);
        $this->assertSame('', $initial['ntError']);
        $this->assertSame(36, $initial['ntThreshold']);

        $_GET = [];
        $_POST = ['nt_start' => '2026-06-01', 'nt_end' => '2026-06-20', 'nt_threshold' => '48'];
        $submitted = FulfillmentIssuePageLoader::load('notracking', 'scan_notracking', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['ntResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['ntError']);
        $this->assertSame(48, $submitted['ntThreshold']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testPostShipAddressChangeMissingShopifyCredentials(): void
    {
        $_POST = ['ps_start' => '2026-06-01', 'ps_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('postshipaddr', 'scan_postshipaddr', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['psResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['psError']);
        $this->assertSame('2026-06-01', $data['psStart']);
        $this->assertSame('2026-06-20', $data['psEnd']);
    }

    public function testSsShippedRequiresShipStationCredentialsFirst(): void
    {
        $_POST = ['ssu_start' => '2026-06-01', 'ssu_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('ssshipped', 'scan_ssshipped', $this->ctx(['ssKey' => '', 'ssSecret' => '']));

        $this->assertNull($data['ssuResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $data['ssuError']);
        $this->assertSame('2026-06-01', $data['ssuStart']);
        $this->assertSame('2026-06-20', $data['ssuEnd']);
    }

    public function testSlaBreachesCarriesThresholdAndMissingShopifyCredentials(): void
    {
        $_GET = ['sla_threshold' => '5'];
        $initial = FulfillmentIssuePageLoader::load('slabreaches', '', $this->ctx());

        $this->assertNull($initial['slaResult']);
        $this->assertSame('', $initial['slaError']);
        $this->assertSame(5, $initial['slaThreshold']);

        $_GET = [];
        $_POST = ['sla_start' => '2026-06-01', 'sla_end' => '2026-06-20', 'sla_threshold' => '7'];
        $submitted = FulfillmentIssuePageLoader::load('slabreaches', 'scan_sla', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['slaResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['slaError']);
        $this->assertSame(7, $submitted['slaThreshold']);
    }

    public function testShipmentAgingThresholdAndMissingShipStationCredentials(): void
    {
        $_GET = ['sa_threshold' => '8'];
        $initial = FulfillmentIssuePageLoader::load('shipmentaging', '', $this->ctx());

        $this->assertNull($initial['saResult']);
        $this->assertSame('', $initial['saError']);
        $this->assertSame(8, $initial['saThreshold']);
        $this->assertSame([], RunLog::all());

        $_GET = [];
        $_POST = ['sa_threshold' => '10'];
        $submitted = FulfillmentIssuePageLoader::load('shipmentaging', 'scan_shipmentaging', $this->ctx(['ssKey' => '', 'ssSecret' => '']));

        $this->assertNull($submitted['saResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $submitted['saError']);
        $this->assertSame(10, $submitted['saThreshold']);
        $this->assertSame('config_error', RunLog::all()[0]['status']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], FulfillmentIssuePageLoader::load('unknown', '', $this->ctx()));
    }

    // ── itemmismatch ──────────────────────────────────────────────────────────

    public function testItemMismatchInitialStateUsesRequestRange(): void
    {
        $_GET = ['im_start' => '2026-06-01', 'im_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('itemmismatch', '', $this->ctx());

        $this->assertNull($data['imResult']);
        $this->assertSame('', $data['imError']);
        $this->assertSame('2026-06-01', $data['imStart']);
        $this->assertSame('2026-06-20', $data['imEnd']);
    }

    public function testItemMismatchRequiresShipStationCredentialsFirst(): void
    {
        $_POST = ['im_start' => '2026-06-01', 'im_end' => '2026-06-20'];

        $data = FulfillmentIssuePageLoader::load('itemmismatch', 'scan_itemmismatch', $this->ctx(['ssKey' => '', 'ssSecret' => '']));

        $this->assertNull($data['imResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $data['imError']);
        $this->assertSame('2026-06-01', $data['imStart']);
        $this->assertSame('2026-06-20', $data['imEnd']);
    }

    /**
     * Happy-path row-building test. loadItemMismatch() itself instantiates real
     * Shopify/ShipStation HTTP clients with no injectable transport, so - mirroring
     * CustomerLTVPageLoaderTest's approach for the same problem - we exercise the
     * pure row-builder it delegates to directly via reflection, using raw
     * Shopify/ShipStation order shapes as documented in ShopifyClientTest.php and
     * ShipStationClientTest.php fixtures.
     */
    public function testBuildItemMismatchRowsFlagsMissingAccessoryOnShippedOrder(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildItemMismatchRows');

        $ssOrders = [
            [
                'orderId'      => 555,
                'orderNumber'  => '2001',
                'orderStatus'  => 'shipped',
                'customerEmail'=> 'buyer@example.com',
                'items'        => [
                    ['sku' => 'widget-a-blue', 'quantity' => 1, 'name' => 'Widget A'],
                    ['sku' => 'trim-1', 'quantity' => 1, 'name' => 'Trim Piece'],
                    // Spare Part intentionally not shipped
                ],
            ],
        ];

        $shOrders = [
            [
                'id'                 => 9001,
                'order_number'       => 2001,
                'name'               => '#2001',
                'created_at'         => '2026-06-05T10:00:00Z',
                'email'              => 'buyer@example.com',
                'total_price'        => '199.00',
                'financial_status'   => 'paid',
                'fulfillment_status' => 'fulfilled',
                'cancelled_at'       => null,
                'line_items'         => [
                    ['sku' => 'widget-a-blue', 'quantity' => 1, 'title' => 'Widget A'],
                    ['sku' => 'trim-1', 'quantity' => 1, 'title' => 'Trim Piece'],
                    ['sku' => 'spare-64', 'quantity' => 1, 'title' => 'Spare Part'],
                ],
            ],
        ];

        Comparator::setOrderTypesConfig([
            'fallback' => 'Other',
            'rules'    => [
                [
                    'name'  => 'Widget A',
                    'match' => 'sku_starts_with',
                    'value' => 'widget-a-',
                    'required_items' => [
                        ['label' => 'Trim Piece', 'match' => 'title_contains', 'value' => 'trim piece'],
                        ['label' => 'Spare Part', 'match' => 'sku_starts_with', 'value' => ['spare-']],
                    ],
                ],
            ],
        ]);

        try {
            $rows = $method->invoke(null, $ssOrders, $shOrders);
        } finally {
            Comparator::resetOrderTypesConfig();
        }

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(2001, $row['order_number']);
        $this->assertSame('buyer@example.com', $row['email']);
        $this->assertSame('Widget A', $row['order_type']);
        $this->assertSame(['spare-64' => 1], $row['missing']);
        $this->assertSame([], $row['extra']);
        $this->assertSame(['Widget A: Spare Part'], $row['missing_required']);
        $this->assertSame('https://app.shipstation.com/#!/orders/order-details/555', $row['ss_url']);
    }

    public function testBuildItemMismatchRowsSkipsExactMatches(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildItemMismatchRows');

        $ssOrders = [[
            'orderId' => 1, 'orderNumber' => '3001', 'orderStatus' => 'shipped',
            'items'   => [['sku' => 'widget', 'quantity' => 1]],
        ]];
        $shOrders = [[
            'id' => 1, 'order_number' => 3001, 'total_price' => '10.00',
            'financial_status' => 'paid', 'cancelled_at' => null,
            'line_items' => [['sku' => 'widget', 'quantity' => 1]],
        ]];

        $rows = $method->invoke(null, $ssOrders, $shOrders);
        $this->assertSame([], $rows);
    }

    public function testBuildItemMismatchRowsSkipsRefundedShopifyOrders(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildItemMismatchRows');

        $ssOrders = [[
            'orderId' => 1, 'orderNumber' => '4001', 'orderStatus' => 'shipped',
            'items'   => [['sku' => 'widget', 'quantity' => 1]],
        ]];
        $shOrders = [[
            'id' => 1, 'order_number' => 4001, 'total_price' => '10.00',
            'financial_status' => 'refunded', 'cancelled_at' => null,
            // Would otherwise be a mismatch (nothing shipped matches nothing ordered here)
            'line_items' => [['sku' => 'other-widget', 'quantity' => 1]],
        ]];

        $rows = $method->invoke(null, $ssOrders, $shOrders);
        $this->assertSame([], $rows);
    }

    public function testBuildItemMismatchRowsIgnoresNonShippedSsOrders(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildItemMismatchRows');

        $ssOrders = [[
            'orderId' => 1, 'orderNumber' => '5001', 'orderStatus' => 'awaiting_shipment',
            'items'   => [['sku' => 'widget', 'quantity' => 1]],
        ]];
        $shOrders = [[
            'id' => 1, 'order_number' => 5001, 'total_price' => '10.00',
            'financial_status' => 'paid', 'cancelled_at' => null,
            'line_items' => [['sku' => 'other-widget', 'quantity' => 1]],
        ]];

        $rows = $method->invoke(null, $ssOrders, $shOrders);
        $this->assertSame([], $rows);
    }

    // ── shipmargin ───────────────────────────────────────────────────────────

    public function testShippingMarginInitialStateUsesRequestRangeAndThreshold(): void
    {
        $_GET = ['sm_start' => '2026-06-01', 'sm_end' => '2026-06-20', 'sm_threshold' => '20'];

        $data = FulfillmentIssuePageLoader::load('shipmargin', '', $this->ctx());

        $this->assertNull($data['smResult']);
        $this->assertSame('', $data['smError']);
        $this->assertSame('2026-06-01', $data['smStart']);
        $this->assertSame('2026-06-20', $data['smEnd']);
        $this->assertSame(20, $data['smThreshold']);
    }

    public function testShippingMarginRequiresShipStationCredentialsFirst(): void
    {
        $_POST = ['sm_start' => '2026-06-01', 'sm_end' => '2026-06-20', 'sm_threshold' => '25'];

        $data = FulfillmentIssuePageLoader::load('shipmargin', 'scan_shipmargin', $this->ctx(['ssKey' => '', 'ssSecret' => '']));

        $this->assertNull($data['smResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $data['smError']);
        $this->assertSame('2026-06-01', $data['smStart']);
        $this->assertSame('2026-06-20', $data['smEnd']);
        $this->assertSame(25, $data['smThreshold']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    /**
     * Happy-path row-building test. loadShippingMargin() itself instantiates real
     * Shopify/ShipStation HTTP clients with no injectable transport, so - mirroring
     * the approach already used for buildItemMismatchRows() - we exercise the pure
     * row-builder it delegates to directly via reflection.
     */
    public function testBuildShippingMarginRowsIncludesOverThresholdExcludesUnderThreshold(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildShippingMarginRows');

        $shipments = [
            [
                'orderId'      => 111,
                'orderNumber'  => '6001',
                'carrierCode'  => 'fedex',
                'serviceCode'  => 'fedex_ground',
                'shipDate'     => '2026-06-10T00:00:00Z',
                'shipmentCost' => 40.0,
                'insuranceCost'=> 0.0,
            ],
            [
                'orderId'      => 222,
                'orderNumber'  => '6002',
                'carrierCode'  => 'ups',
                'serviceCode'  => 'ups_ground',
                'shipDate'     => '2026-06-11T00:00:00Z',
                'shipmentCost' => 12.0,
                'insuranceCost'=> 0.0,
            ],
        ];

        $shOrders = [
            [
                'id' => 7001, 'order_number' => 6001, 'email' => 'loss@example.com',
                'total_price' => '199.00',
                'shipping_lines' => [['price' => '10.00']],
            ],
            [
                'id' => 7002, 'order_number' => 6002, 'email' => 'fine@example.com',
                'total_price' => '50.00',
                'shipping_lines' => [['price' => '10.00']],
            ],
        ];

        $rows = $method->invoke(null, $shipments, $shOrders, 15.0);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('6001', $row['order_number']);
        $this->assertSame('fedex', $row['carrier']);
        $this->assertSame('fedex_ground', $row['service']);
        $this->assertSame(40.0, $row['ship_cost']);
        $this->assertSame(10.0, $row['shipping_charged']);
        $this->assertSame(30.0, $row['loss']);
        $this->assertSame('loss@example.com', $row['email']);
        $this->assertSame('https://app.shipstation.com/#!/orders/order-details/111', $row['ss_url']);
    }

    public function testBuildShippingMarginRowsSkipsVoidedShipments(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildShippingMarginRows');

        $shipments = [[
            'orderId' => 1, 'orderNumber' => '7001', 'shipmentCost' => 100.0,
            'insuranceCost' => 0.0, 'voided' => true,
        ]];
        $shOrders = [[
            'id' => 1, 'order_number' => 7001, 'shipping_lines' => [],
        ]];

        $rows = $method->invoke(null, $shipments, $shOrders, 1.0);
        $this->assertSame([], $rows);
    }

    public function testBuildShippingMarginRowsSkipsShipmentsWithNoShopifyMatch(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildShippingMarginRows');

        $shipments = [[
            'orderId' => 1, 'orderNumber' => '8001', 'shipmentCost' => 100.0, 'insuranceCost' => 0.0,
        ]];

        $rows = $method->invoke(null, $shipments, [], 1.0);
        $this->assertSame([], $rows);
    }

    public function testBuildShippingMarginRowsSumsMultipleShippingLines(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildShippingMarginRows');

        $shipments = [[
            'orderId' => 1, 'orderNumber' => '9001', 'shipmentCost' => 30.0, 'insuranceCost' => 2.0,
        ]];
        $shOrders = [[
            'id' => 1, 'order_number' => 9001,
            'shipping_lines' => [['price' => '5.00'], ['price' => '2.50']],
        ]];

        $rows = $method->invoke(null, $shipments, $shOrders, 1.0);

        $this->assertCount(1, $rows);
        $this->assertSame(32.0, $rows[0]['ship_cost']);
        $this->assertSame(7.5, $rows[0]['shipping_charged']);
        $this->assertSame(24.5, $rows[0]['loss']);
    }

    public function testBuildShippingMarginCarrierSummaryAggregatesByCarrier(): void
    {
        $ref    = new \ReflectionClass(FulfillmentIssuePageLoader::class);
        $method = $ref->getMethod('buildShippingMarginCarrierSummary');

        $rows = [
            ['carrier' => 'fedex', 'loss' => 30.0],
            ['carrier' => 'fedex', 'loss' => 10.0],
            ['carrier' => 'ups',   'loss' => 50.0],
        ];

        $summary = $method->invoke(null, $rows);

        $this->assertSame([
            ['carrier' => 'ups',   'count' => 1, 'total_loss' => 50.0, 'avg_loss' => 50.0],
            ['carrier' => 'fedex', 'count' => 2, 'total_loss' => 40.0, 'avg_loss' => 20.0],
        ], $summary);
    }

    // ── fulfilleditems ──────────────────────────────────────────────────────

    public function testFulfilledItemsInitialStateUsesRequestRange(): void
    {
        $_GET = ['fi_start' => '2026-07-01', 'fi_end' => '2026-07-31'];

        $data = FulfillmentIssuePageLoader::load('fulfilleditems', '', $this->ctx());

        $this->assertNull($data['fiResult']);
        $this->assertSame('', $data['fiError']);
        $this->assertSame('2026-07-01', $data['fiStart']);
        $this->assertSame('2026-07-31', $data['fiEnd']);
    }

    public function testFulfilledItemsRequiresShopifyCredentialsFirst(): void
    {
        $_POST = ['fi_start' => '2026-07-01', 'fi_end' => '2026-07-31'];

        $data = FulfillmentIssuePageLoader::load(
            'fulfilleditems',
            'scan_fulfilleditems',
            $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A'])
        );

        $this->assertNull($data['fiResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['fiError']);
        $this->assertSame('2026-07-01', $data['fiStart']);
        $this->assertSame('2026-07-31', $data['fiEnd']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testEmailFulfilledItemsValidatesDateRangeFirst(): void
    {
        $_POST = ['fi_start' => 'not-a-date', 'fi_end' => '2026-07-31'];

        $data = FulfillmentIssuePageLoader::load('fulfilleditems', 'email_fulfilleditems', $this->ctx());

        $this->assertSame('', $data['fiEmailMessage']);
        $this->assertSame('Invalid date format. Use YYYY-MM-DD.', $data['fiEmailError']);
    }

    public function testEmailFulfilledItemsRequiresShopifyCredentials(): void
    {
        $_POST = ['fi_start' => '2026-07-01', 'fi_end' => '2026-07-31'];

        $data = FulfillmentIssuePageLoader::load(
            'fulfilleditems',
            'email_fulfilleditems',
            $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A'])
        );

        $this->assertSame('', $data['fiEmailMessage']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['fiEmailError']);
    }

    public function testEmailFulfilledItemsRequiresSmtpConfiguration(): void
    {
        $previousSmtpHost   = getenv('SMTP_HOST');
        $previousAlertEmail = getenv('ALERT_EMAIL');
        putenv('SMTP_HOST');
        putenv('ALERT_EMAIL');

        try {
            $_POST = ['fi_start' => '2026-07-01', 'fi_end' => '2026-07-31'];

            $data = FulfillmentIssuePageLoader::load('fulfilleditems', 'email_fulfilleditems', $this->ctx());

            $this->assertSame('', $data['fiEmailMessage']);
            $this->assertSame('SMTP_HOST / ALERT_EMAIL not set in .env.', $data['fiEmailError']);
        } finally {
            $previousSmtpHost === false ? putenv('SMTP_HOST') : putenv("SMTP_HOST={$previousSmtpHost}");
            $previousAlertEmail === false ? putenv('ALERT_EMAIL') : putenv("ALERT_EMAIL={$previousAlertEmail}");
        }
    }

    public function testFulfilledItemsScanReturnsAggregatedRowsFromShopify(): void
    {
        $_POST = ['fi_start' => '2026-07-01', 'fi_end' => '2026-07-31'];

        $data = FulfillmentIssuePageLoader::load(
            'fulfilleditems',
            'scan_fulfilleditems',
            $this->ctx(['httpStack' => $this->fulfilledOrdersStack()])
        );

        $this->assertSame('', $data['fiError']);
        $this->assertSame(1, $data['fiResult']['scanned']);
        $this->assertSame([['product' => 'Widget blue', 'quantity' => 2]], $data['fiResult']['rows']);
    }

    public function testFulfilledItemsScanCanGroupProductsWithOrders(): void
    {
        $_POST = ['fi_start' => '2026-07-01', 'fi_end' => '2026-07-31', 'fi_mode' => 'grouped'];

        $data = FulfillmentIssuePageLoader::load(
            'fulfilleditems',
            'scan_fulfilleditems',
            $this->ctx(['httpStack' => $this->fulfilledOrdersStack()])
        );

        $this->assertSame('', $data['fiError']);
        $this->assertSame('grouped', $data['fiResult']['mode']);
        $this->assertSame([['product' => 'Widget blue', 'quantity' => 2, 'orders' => '#1001']], $data['fiResult']['rows']);
    }

    public function testEmailFulfilledItemsSendsCsvAttachmentOnSuccess(): void
    {
        $previousAlertEmail = getenv('ALERT_EMAIL');
        putenv('ALERT_EMAIL=ops@test.com');

        try {
            $_POST    = ['fi_start' => '2026-07-01', 'fi_end' => '2026-07-31'];
            $notifier = new RecordingEmailNotifier('smtp.test', 587, 'user@test.com', 'pw', 'from@test.com', 'ops@test.com', 'tls');

            $data = FulfillmentIssuePageLoader::load(
                'fulfilleditems',
                'email_fulfilleditems',
                $this->ctx(['httpStack' => $this->fulfilledOrdersStack(), 'emailNotifier' => $notifier])
            );

            $this->assertSame('', $data['fiEmailError']);
            $this->assertSame('Emailed to ops@test.com.', $data['fiEmailMessage']);
            $this->assertSame([['product' => 'Widget blue', 'quantity' => 2]], $data['fiResult']['rows']);

            $this->assertCount(1, $notifier->sent);
            $this->assertSame('ops@test.com', $notifier->sent[0]['to']);
            $this->assertStringContainsString('Widget blue', $notifier->sent[0]['body']);

            preg_match('/Content-Disposition: attachment.*?\r\n\r\n(.*?)\r\n--/s', $notifier->sent[0]['body'], $m);
            $csv = base64_decode($m[1] ?? '', true);
            $this->assertNotFalse($csv);
            $this->assertStringContainsString('Widget blue', $csv);
            $this->assertStringContainsString(',2', $csv);
        } finally {
            $previousAlertEmail === false ? putenv('ALERT_EMAIL') : putenv("ALERT_EMAIL={$previousAlertEmail}");
        }
    }

    private function fulfilledOrdersStack(): HandlerStack
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges'    => [[
                            'node' => [
                                'id'                       => 'gid://shopify/Order/1',
                                'legacyResourceId'         => '1',
                                'name'                     => '#1001',
                                'createdAt'                => '2026-07-05T10:00:00Z',
                                'cancelledAt'              => null,
                                'email'                    => 'buyer@example.com',
                                'displayFinancialStatus'   => 'PAID',
                                'displayFulfillmentStatus' => 'FULFILLED',
                                'totalPriceSet'            => ['shopMoney' => ['amount' => '80.00', 'currencyCode' => 'USD']],
                                'lineItems'                => ['nodes' => [[
                                    'id'                    => 'gid://shopify/LineItem/1',
                                    'title'                 => 'Widget',
                                    'name'                  => 'Widget - blue',
                                    'sku'                   => 'WIDGET-BLUE',
                                    'quantity'              => 2,
                                    'variantTitle'          => 'blue',
                                    'originalUnitPriceSet'  => ['shopMoney' => ['amount' => '40.00', 'currencyCode' => 'USD']],
                                ]]],
                                'shippingLines' => ['nodes' => []],
                            ],
                        ]],
                    ],
                ],
            ])),
        ]);

        return HandlerStack::create($mock);
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
