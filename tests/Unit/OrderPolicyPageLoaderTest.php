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
require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';

use PHPUnit\Framework\TestCase;

class OrderPolicyPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/order_policy_loader_' . uniqid();
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

    public function testOrderEditsInitialAndMissingShopifyCredentials(): void
    {
        $_GET = ['oe_start' => '2026-06-01', 'oe_end' => '2026-06-20'];
        $initial = OrderPolicyPageLoader::load('orderedits', '', $this->ctx());

        $this->assertNull($initial['oeResult']);
        $this->assertSame('', $initial['oeError']);
        $this->assertSame('2026-06-01', $initial['oeStart']);
        $this->assertSame('2026-06-20', $initial['oeEnd']);

        $_GET = [];
        $_POST = ['oe_start' => '2026-06-01', 'oe_end' => '2026-06-20'];
        $submitted = OrderPolicyPageLoader::load('orderedits', 'scan_order_edits', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['oeResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['oeError']);
    }

    public function testNoteFlagsInitialKeywordsAndMissingShopifyCredentials(): void
    {
        $_GET = ['nf_start' => '2026-06-01', 'nf_end' => '2026-06-20', 'nf_keywords' => 'hold, wait'];
        $initial = OrderPolicyPageLoader::load('noteflags', '', $this->ctx());

        $this->assertNull($initial['nfResult']);
        $this->assertSame('', $initial['nfError']);
        $this->assertSame('2026-06-01', $initial['nfStart']);
        $this->assertSame('2026-06-20', $initial['nfEnd']);
        $this->assertSame('hold, wait', $initial['nfKeywordsRaw']);

        $_GET = [];
        $_POST = ['nf_start' => '2026-06-01', 'nf_end' => '2026-06-20', 'nf_keywords' => 'cancel'];
        $submitted = OrderPolicyPageLoader::load('noteflags', 'scan_noteflags', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['nfResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['nfError']);
        $this->assertSame('cancel', $submitted['nfKeywordsRaw']);
    }

    public function testAddrDupesMissingShopifyCredentials(): void
    {
        $_POST = ['ad_start' => '2026-06-01', 'ad_end' => '2026-06-20'];

        $data = OrderPolicyPageLoader::load('addrdupes', 'scan_addrdupes', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['adResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['adError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testActiveSsRequiresShipStationBeforeShopify(): void
    {
        $_POST = ['as_start' => '2026-06-01', 'as_end' => '2026-06-20'];

        $data = OrderPolicyPageLoader::load('activess', 'scan_activess', $this->ctx([
            'shopifyToken' => '',
            'shopifyStore' => 'N/A',
            'ssKey'        => '',
            'ssSecret'     => '',
        ]));

        $this->assertNull($data['asResult']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $data['asError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testDiscountAbuseCarriesMinimumAndMissingShopifyCredentials(): void
    {
        $_GET = ['da_min_emails' => '4'];
        $initial = OrderPolicyPageLoader::load('discountabuse', '', $this->ctx());

        $this->assertNull($initial['daResult']);
        $this->assertSame('', $initial['daError']);
        $this->assertSame(4, $initial['daMinEmails']);

        $_GET = [];
        $_POST = ['da_start' => '2026-06-01', 'da_end' => '2026-06-20', 'da_min_emails' => '5'];
        $submitted = OrderPolicyPageLoader::load('discountabuse', 'scan_discountabuse', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['daResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['daError']);
        $this->assertSame(5, $submitted['daMinEmails']);
    }

    public function testTagPolicyInitialConfigAndMissingShopifyCredentials(): void
    {
        $_GET = ['tp_start' => '2026-06-01', 'tp_end' => '2026-06-20'];
        $initial = OrderPolicyPageLoader::load('tagpolicy', '', $this->ctx());

        $this->assertNull($initial['tpResult']);
        $this->assertSame('', $initial['tpError']);
        $this->assertSame('2026-06-01', $initial['tpStart']);
        $this->assertSame('2026-06-20', $initial['tpEnd']);
        $this->assertIsArray($initial['tpConfig']);

        $_GET = [];
        $_POST = ['tp_start' => '2026-06-01', 'tp_end' => '2026-06-20'];
        $submitted = OrderPolicyPageLoader::load('tagpolicy', 'scan_tagpolicy', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['tpResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['tpError']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], OrderPolicyPageLoader::load('unknown', '', $this->ctx()));
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
