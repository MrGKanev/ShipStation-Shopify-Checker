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
