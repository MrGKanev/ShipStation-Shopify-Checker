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

    public function testSkuDupesInitialAndMissingShopifyCredentials(): void
    {
        $initial = ProductInventoryPageLoader::load('skudupes', '', $this->ctx());

        $this->assertNull($initial['sdResult']);
        $this->assertSame('', $initial['sdError']);

        $submitted = ProductInventoryPageLoader::load('skudupes', 'scan_skudupes', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['sdResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['sdError']);
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

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], ProductInventoryPageLoader::load('unknown', '', $this->ctx()));
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
