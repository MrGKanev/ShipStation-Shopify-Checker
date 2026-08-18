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
require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ProductInventoryPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;
    private static \ReflectionMethod $buildOversellRows;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(ProductInventoryPageLoader::class);
        self::$buildOversellRows = $ref->getMethod('buildOversellRows');
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/product_inventory_loader_' . uniqid();
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

    public function testBundleCheckInitialStateUsesRequestRange(): void
    {
        $_GET = ['bc_start' => '2026-06-01', 'bc_end' => '2026-06-20'];

        $data = ProductInventoryPageLoader::load('bundlecheck', '', $this->ctx());

        $this->assertNull($data['bcResult']);
        $this->assertSame('', $data['bcError']);
        $this->assertSame('2026-06-01', $data['bcStart']);
        $this->assertSame('2026-06-20', $data['bcEnd']);
        $this->assertIsArray($data['bcConfig']);
        $this->assertSame([], RunLog::all());
    }

    public function testBundleCheckMissingShopifyCredentials(): void
    {
        $_POST = ['bc_start' => '2026-06-01', 'bc_end' => '2026-06-20'];

        $data = ProductInventoryPageLoader::load('bundlecheck', 'scan_bundle', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['bcResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['bcError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testProductCheckInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('productcheck', '', $this->ctx());

        $this->assertNull($initial['pcResult']);
        $this->assertSame('', $initial['pcError']);

        $submitted = ProductInventoryPageLoader::load('productcheck', 'scan_products', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['pcResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['pcError']);
    }

    public function testProductCheckSurfacesErrorAndLogsFailureWhenShopifyThrows(): void
    {
        $data = ProductInventoryPageLoader::load('productcheck', 'scan_products', $this->ctx(['httpStack' => $this->errorStack()]));

        $this->assertNull($data['pcResult']);
        $this->assertNotSame('', $data['pcError']);
        $this->assertSame('error', RunLog::all()[0]['status']);
    }

    public function testSkuDupesInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('skudupes', '', $this->ctx());

        $this->assertNull($initial['sdResult']);
        $this->assertSame('', $initial['sdError']);

        $submitted = ProductInventoryPageLoader::load('skudupes', 'scan_skudupes', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['sdResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['sdError']);
    }

    public function testSkuDupesSurfacesErrorAndLogsFailureWhenShopifyThrows(): void
    {
        $data = ProductInventoryPageLoader::load('skudupes', 'scan_skudupes', $this->ctx(['httpStack' => $this->errorStack()]));

        $this->assertNull($data['sdResult']);
        $this->assertNotSame('', $data['sdError']);
        $this->assertSame('error', RunLog::all()[0]['status']);
    }

    public function testInventoryOversellChecksShopifyBeforeShipStationCredentials(): void
    {
        $missingShopify = ProductInventoryPageLoader::load('inventoryoversell', 'scan_inventory', $this->ctx([
            'shopifyToken' => '',
            'shopifyStore' => 'N/A',
            'ssKey'        => '',
            'ssSecret'     => '',
        ]));

        $this->assertNull($missingShopify['ioResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $missingShopify['ioError']);

        $missingShipStation = ProductInventoryPageLoader::load('inventoryoversell', 'scan_inventory', $this->ctx([
            'ssKey'    => '',
            'ssSecret' => '',
        ]));

        $this->assertNull($missingShipStation['ioResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $missingShipStation['ioError']);
    }

    public function testInventoryOversellSurfacesErrorAndLogsFailureWhenShopifyThrows(): void
    {
        $data = ProductInventoryPageLoader::load('inventoryoversell', 'scan_inventory', $this->ctx(['httpStack' => $this->errorStack()]));

        $this->assertNull($data['ioResult']);
        $this->assertNotSame('', $data['ioError']);
        $this->assertSame('error', RunLog::all()[0]['status']);
    }

    public function testBuildOversellRowsFlagsShortfallWhenAwaitingExceedsStock(): void
    {
        $products = [$this->ioProduct('1', 'Widget', [$this->ioVariant('SKU-A', 'Blue', 5)])];
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 8]])];

        $rows = self::$buildOversellRows->invoke(null, $products, $ssOrders);

        $this->assertCount(1, $rows);
        $this->assertSame('SKU-A', $rows[0]['sku']);
        $this->assertSame(5, $rows[0]['stock']);
        $this->assertSame(8, $rows[0]['awaiting']);
        $this->assertSame(3, $rows[0]['shortfall']);
        $this->assertFalse($rows[0]['duplicate_sku']);
        $this->assertSame('1', $rows[0]['product_id']);
    }

    public function testBuildOversellRowsSkipsWhenStockCoversDemand(): void
    {
        $products = [$this->ioProduct('1', 'Widget', [$this->ioVariant('SKU-A', 'Blue', 10)])];
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 8]])];

        $rows = self::$buildOversellRows->invoke(null, $products, $ssOrders);

        $this->assertSame([], $rows);
    }

    public function testBuildOversellRowsSkipsSkuNotTrackedInShopify(): void
    {
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 8]])];

        $rows = self::$buildOversellRows->invoke(null, [], $ssOrders);

        $this->assertSame([], $rows);
    }

    public function testBuildOversellRowsSkipsVariantsWithContinueSellingPolicy(): void
    {
        $products = [$this->ioProduct('1', 'Widget', [$this->ioVariant('SKU-A', 'Blue', 0, 'continue')])];
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 8]])];

        $rows = self::$buildOversellRows->invoke(null, $products, $ssOrders);

        $this->assertSame([], $rows);
    }

    public function testBuildOversellRowsSumsStockButFlagsDuplicateSkuAcrossProducts(): void
    {
        $products = [
            $this->ioProduct('1', 'Widget A', [$this->ioVariant('SKU-A', 'Blue', 3)]),
            $this->ioProduct('2', 'Widget B', [$this->ioVariant('SKU-A', 'Red', 2)]),
        ];
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 8]])];

        $rows = self::$buildOversellRows->invoke(null, $products, $ssOrders);

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['stock']);
        $this->assertSame(3, $rows[0]['shortfall']);
        $this->assertTrue($rows[0]['duplicate_sku']);
        $this->assertSame('', $rows[0]['product_id']);
        $this->assertSame('2 products share this SKU', $rows[0]['product_title']);
    }

    public function testBuildOversellRowsExactBoundaryAwaitingEqualsStockNotFlagged(): void
    {
        $products = [$this->ioProduct('1', 'Widget', [$this->ioVariant('SKU-A', 'Blue', 8)])];
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 8]])];

        $rows = self::$buildOversellRows->invoke(null, $products, $ssOrders);

        $this->assertSame([], $rows, 'shortfall <= 0 must not flag; awaiting === stock is shortfall 0');
    }

    public function testBuildOversellRowsBlankInventoryManagementTreatedAsNotTrackedNotFlagged(): void
    {
        $variant = array_merge($this->ioVariant('SKU-A', 'Blue', 0), ['inventory_management' => '']);
        $products = [$this->ioProduct('1', 'Widget', [$variant])];
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 8]])];

        $rows = self::$buildOversellRows->invoke(null, $products, $ssOrders);

        $this->assertSame([], $rows, 'an untracked variant (blank inventory_management) must be excluded just like a SKU absent from Shopify entirely');
    }

    public function testBuildOversellRowsNegativeExistingStockAddsToShortfall(): void
    {
        $products = [$this->ioProduct('1', 'Widget', [$this->ioVariant('SKU-A', 'Blue', -3)])];
        $ssOrders = [$this->ioAwaitingOrder([['sku' => 'SKU-A', 'quantity' => 5]])];

        $rows = self::$buildOversellRows->invoke(null, $products, $ssOrders);

        $this->assertCount(1, $rows);
        $this->assertSame(-3, $rows[0]['stock']);
        $this->assertSame(8, $rows[0]['shortfall']);
    }

    private function ioProduct(string $id, string $title, array $variants): array
    {
        return ['id' => $id, 'title' => $title, 'variants' => $variants];
    }

    private function ioVariant(string $sku, string $title, int $stock, string $policy = 'deny'): array
    {
        return [
            'sku'                  => $sku,
            'title'                => $title,
            'inventory_management' => 'shopify',
            'inventory_policy'     => $policy,
            'inventory_quantity'   => $stock,
        ];
    }

    private function ioAwaitingOrder(array $items): array
    {
        return ['items' => $items];
    }

    public function testZombieProductsInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('zombieproducts', '', $this->ctx());

        $this->assertNull($initial['zpResult']);
        $this->assertSame('', $initial['zpError']);

        $submitted = ProductInventoryPageLoader::load('zombieproducts', 'scan_zombieproducts', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['zpResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['zpError']);
    }

    public function testZombieProductsSurfacesErrorAndLogsFailureWhenShopifyThrows(): void
    {
        $data = ProductInventoryPageLoader::load('zombieproducts', 'scan_zombieproducts', $this->ctx(['httpStack' => $this->errorStack()]));

        $this->assertNull($data['zpResult']);
        $this->assertNotSame('', $data['zpError']);
        $this->assertSame('error', RunLog::all()[0]['status']);
    }

    public function testInventoryAgingInitialRangeAndMissingShopifyCredentials(): void
    {
        $_GET = ['ia_start' => '2026-05-01', 'ia_end' => '2026-06-20'];

        $initial = ProductInventoryPageLoader::load('inventoryaging', '', $this->ctx());

        $this->assertNull($initial['iaResult']);
        $this->assertSame('', $initial['iaError']);
        $this->assertSame('2026-05-01', $initial['iaStart']);
        $this->assertSame('2026-06-20', $initial['iaEnd']);

        $_GET = [];
        $_POST = ['ia_start' => '2026-05-01', 'ia_end' => '2026-06-20'];
        $submitted = ProductInventoryPageLoader::load('inventoryaging', 'scan_inventoryaging', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['iaResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['iaError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testCatalogQualityInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('catalogquality', '', $this->ctx());

        $this->assertNull($initial['cqResult']);
        $this->assertSame('', $initial['cqError']);

        $submitted = ProductInventoryPageLoader::load('catalogquality', 'scan_catalogquality', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['cqResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['cqError']);
    }

    public function testInventoryForecastInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('inventoryforecast', '', $this->ctx());

        $this->assertNull($initial['ifResult']);
        $this->assertSame('', $initial['ifError']);

        $submitted = ProductInventoryPageLoader::load('inventoryforecast', 'scan_inventoryforecast', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['ifResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['ifError']);
    }

    public function testInventoryForecastSurfacesErrorAndLogsFailureWhenShopifyThrows(): void
    {
        $data = ProductInventoryPageLoader::load('inventoryforecast', 'scan_inventoryforecast', $this->ctx(['httpStack' => $this->errorStack()]));

        $this->assertNull($data['ifResult']);
        $this->assertNotSame('', $data['ifError']);
        $this->assertSame('error', RunLog::all()[0]['status']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], ProductInventoryPageLoader::load('unknown', '', $this->ctx()));
    }

    // ── Success paths (full scan through the mocked HTTP transport) ───────────

    public function testProductCheckSuccessFlagsMissingSku(): void
    {
        $stack = $this->shopifyStack([$this->graphQLProducts([
            $this->productNode(['variants' => ['edges' => [['node' => $this->variantNode(['sku' => ''])]]]]),
        ])]);

        $data = ProductInventoryPageLoader::load('productcheck', 'scan_products', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['pcError']);
        $this->assertCount(1, $data['pcResult']['rows']);
        $this->assertSame('critical', $data['pcResult']['rows'][0]['severity']);
    }

    public function testSkuDupesSuccessFlagsSkuSharedAcrossVariants(): void
    {
        $stack = $this->shopifyStack([$this->graphQLProducts([
            $this->productNode(['id' => 'gid://shopify/Product/1', 'legacyResourceId' => '1', 'variants' => [
                'edges' => [['node' => $this->variantNode(['sku' => 'SKU-A'])]],
            ]]),
            $this->productNode(['id' => 'gid://shopify/Product/2', 'legacyResourceId' => '2', 'variants' => [
                'edges' => [['node' => $this->variantNode(['sku' => 'SKU-A'])]],
            ]]),
        ])]);

        $data = ProductInventoryPageLoader::load('skudupes', 'scan_skudupes', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['sdError']);
        $this->assertCount(1, $data['sdResult']['rows']);
        $this->assertSame('SKU-A', $data['sdResult']['rows'][0]['sku']);
        $this->assertSame(2, $data['sdResult']['rows'][0]['count']);
    }

    public function testInventoryOversellSuccessFlagsShortfall(): void
    {
        $stack = $this->shopifyStack([
            $this->graphQLProducts([$this->productNode(['variants' => [
                'edges' => [['node' => $this->variantNode(['sku' => 'SKU-A', 'inventoryQuantity' => 2])]],
            ]])]),
            $this->shipStationOrders([['items' => [['sku' => 'SKU-A', 'quantity' => 5]]]]),
        ]);

        $data = ProductInventoryPageLoader::load('inventoryoversell', 'scan_inventory', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['ioError']);
        $this->assertCount(1, $data['ioResult']['rows']);
        $this->assertSame(3, $data['ioResult']['rows'][0]['shortfall']);
    }

    public function testZombieProductsSuccessFlagsAllTrackedVariantsAtZeroStock(): void
    {
        $stack = $this->shopifyStack([$this->graphQLProducts([
            $this->productNode(['variants' => ['edges' => [['node' => $this->variantNode(['sku' => 'SKU-A', 'inventoryQuantity' => 0])]]]]),
        ])]);

        $data = ProductInventoryPageLoader::load('zombieproducts', 'scan_zombieproducts', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['zpError']);
        $this->assertCount(1, $data['zpResult']['rows']);
        $this->assertSame('zero_stock', $data['zpResult']['rows'][0]['reason']);
    }

    public function testInventoryAgingSuccessFlagsZeroStockVariantWithRecentSales(): void
    {
        $_POST = ['ia_start' => '2026-06-01', 'ia_end' => '2026-06-20'];

        $stack = $this->shopifyStack([
            $this->graphQLProducts([$this->productNode(['variants' => [
                'edges' => [['node' => $this->variantNode(['sku' => 'SKU-A', 'inventoryQuantity' => 0])]],
            ]])]),
            $this->graphQLOrders([$this->orderNodeWithLineItems([['sku' => 'SKU-A', 'quantity' => 4]])]),
        ]);

        $data = ProductInventoryPageLoader::load('inventoryaging', 'scan_inventoryaging', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['iaError']);
        $this->assertCount(1, $data['iaResult']['rows']);
        $this->assertSame('SKU-A', $data['iaResult']['rows'][0]['sku']);
        $this->assertSame(4, $data['iaResult']['rows'][0]['recent_qty']);
    }

    public function testCatalogQualitySurfacesErrorAndLogsFailureWhenShopifyThrows(): void
    {
        $data = ProductInventoryPageLoader::load('catalogquality', 'scan_catalogquality', $this->ctx(['httpStack' => $this->errorStack()]));

        $this->assertNull($data['cqResult']);
        $this->assertNotSame('', $data['cqError']);
        $this->assertSame('error', RunLog::all()[0]['status']);
    }

    public function testInventoryForecastSuccessProjectsDaysToZero(): void
    {
        $stack = $this->shopifyStack([
            $this->graphQLProducts([$this->productNode(['variants' => [
                'edges' => [['node' => $this->variantNode(['sku' => 'SKU-A', 'inventoryQuantity' => 30])]],
            ]])]),
            $this->graphQLOrders([$this->orderNodeWithLineItems([['sku' => 'SKU-A', 'quantity' => 30]])]),
        ]);

        $data = ProductInventoryPageLoader::load('inventoryforecast', 'scan_inventoryforecast', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['ifError']);
        $this->assertCount(1, $data['ifResult']['rows']);
        $this->assertSame('SKU-A', $data['ifResult']['rows'][0]['sku']);
        $this->assertSame(30, $data['ifResult']['rows'][0]['days_to_zero']);
    }

    public function testCatalogQualitySuccessFlagsUnpublishedProduct(): void
    {
        $stack = $this->shopifyStack([$this->graphQLProducts([
            $this->productNode(['onlineStoreUrl' => null]),
        ])]);

        $data = ProductInventoryPageLoader::load('catalogquality', 'scan_catalogquality', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['cqError']);
        $this->assertCount(1, $data['cqResult']['rows']);
        $this->assertContains('Not published to Online Store', $data['cqResult']['rows'][0]['issues']);
    }

    public function testBundleCheckSuccessFlagsOrderMissingRequiredComponent(): void
    {
        $_POST = ['bc_start' => '2026-06-01', 'bc_end' => '2026-06-20'];

        $stack = $this->shopifyStack([$this->graphQLOrders([$this->orderNodeWithShippingLine()])]);

        $data = ProductInventoryPageLoader::load('bundlecheck', 'scan_bundle', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['bcError']);
        $this->assertIsArray($data['bcResult']['rows']);
    }

    // ── Mock HTTP helpers ───────────────────────────────────────────────────

    private function shopifyStack(array $responses): HandlerStack
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

    private function graphQLProducts(array $nodes): Response
    {
        return $this->json([
            'data' => [
                'products' => [
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

    private function orderNodeWithLineItems(array $lineItems): array
    {
        return $this->orderNode([
            'lineItems' => ['nodes' => array_map(fn($li) => array_merge([
                'id' => 'gid://shopify/LineItem/1', 'title' => 'Widget', 'name' => 'Widget', 'variantTitle' => null,
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
            ], $li), $lineItems)],
        ]);
    }

    private function orderNodeWithShippingLine(): array
    {
        return $this->orderNode([
            'totalPriceSet'  => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']],
            'shippingLines'  => ['nodes' => [[
                'id' => 'gid://shopify/ShippingLine/1', 'title' => 'Standard', 'code' => 'STANDARD',
                'originalPriceSet' => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']],
            ]]],
        ]);
    }

    private function productNode(array $overrides = []): array
    {
        return array_replace_recursive([
            'id'                 => 'gid://shopify/Product/1',
            'legacyResourceId'   => '1',
            'title'              => 'Widget',
            'status'             => 'ACTIVE',
            'descriptionHtml'    => '<p>A widget</p>',
            'vendor'             => 'Acme',
            'productType'        => 'Widgets',
            'onlineStoreUrl'     => 'https://example.com/products/widget',
            'seo'                => ['title' => 'Widget', 'description' => 'A widget'],
            'collections'        => ['edges' => [['node' => ['id' => 'gid://shopify/Collection/1']]]],
            'mediaCount'         => ['count' => 1],
            'variants'           => ['edges' => [['node' => $this->variantNode()]]],
        ], $overrides);
    }

    private function variantNode(array $overrides = []): array
    {
        return array_replace_recursive([
            'id'                   => 'gid://shopify/ProductVariant/1',
            'legacyResourceId'     => '1',
            'title'                => 'Default Title',
            'sku'                  => 'SKU-A',
            'barcode'              => null,
            'inventoryQuantity'    => 10,
            'inventoryPolicy'      => 'DENY',
            'inventoryItem'        => ['tracked' => true],
        ], $overrides);
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

    /**
     * A handler stack whose first request blows up with a server error,
     * simulating a Shopify/ShipStation API outage mid-scan.
     */
    private function errorStack(): HandlerStack
    {
        return HandlerStack::create(new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]));
    }
}
