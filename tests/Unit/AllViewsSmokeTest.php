<?php
declare(strict_types=1);

require_once __DIR__ . '/support/TmpDir.php';
require_once __DIR__ . '/../../src/Cache.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/AuditSnapshot.php';
require_once __DIR__ . '/../../src/IgnoreList.php';
require_once __DIR__ . '/../../src/PushLog.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/JobQueue.php';
require_once __DIR__ . '/../../src/PrintQueue.php';
require_once __DIR__ . '/../../src/UserActionLog.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/EmailRules.php';
require_once __DIR__ . '/../../src/EmailNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/ReportRegistry.php';
require_once __DIR__ . '/../../src/ToolRegistry.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ShipStation.php';
require_once __DIR__ . '/../../src/ViewHelpers.php';
require_once __DIR__ . '/../../src/ManageSettingsPageLoader.php';
require_once __DIR__ . '/../../src/SearchLookupPageLoader.php';
require_once __DIR__ . '/../../src/PackingSlipPageLoader.php';
require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';
require_once __DIR__ . '/../../src/FulfillmentIssuePageLoader.php';
require_once __DIR__ . '/../../src/ProductInventoryPageLoader.php';
require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';
require_once __DIR__ . '/../../src/OrderPolicyPageLoader.php';
require_once __DIR__ . '/../../src/OrderInsightPageLoader.php';
require_once __DIR__ . '/../../src/CustomerLTVPageLoader.php';
require_once __DIR__ . '/../../src/ItemizedFulfillmentReport.php';
require_once __DIR__ . '/../../src/GiftCardPageLoader.php';
require_once __DIR__ . '/../../src/DisputesPageLoader.php';
require_once __DIR__ . '/../../src/PageLoader.php';

use PHPUnit\Framework\TestCase;

/**
 * Renders the initial GET (no action submitted) state of every page in
 * views/ through the real PageLoader::load() routing, the same code path
 * index.php uses. Catches undefined-variable/array-key warnings (via
 * phpunit.xml's failOnWarning) and fatal errors in the view templates,
 * which have no other coverage anywhere in this suite - PageLoader tests
 * only exercise the data-building logic, never the .php file that
 * consumes it (see ViewSmokeTest for the hand-built-fixture, populated-
 * state counterpart covering the three newest pages in more depth).
 *
 * Pages that legitimately can't be exercised this way (they need a real
 * external HTTP round trip even on a bare GET) are listed in EXCLUDED
 * with the reason.
 */
class AllViewsSmokeTest extends TestCase
{
    /** @var array<string, string> page => reason it's skipped */
    private const EXCLUDED = [];

    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private array $previousSession;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/all_views_smoke_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        Auth::setDataDir($this->tmpDir);
        AuditSnapshot::setDataDir($this->tmpDir);
        IgnoreList::setDataDir($this->tmpDir);
        PushLog::setDataDir($this->tmpDir);
        RunLog::setDataDir($this->tmpDir);
        JobQueue::setDataDir($this->tmpDir);
        PrintQueue::setDataDir($this->tmpDir);
        UserActionLog::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);
        EmailRules::setDataDir($this->tmpDir);

        $this->previousGet     = $_GET;
        $this->previousPost    = $_POST;
        $this->previousSession = $_SESSION ?? [];
        $_GET  = [];
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        TmpDir::remove($this->tmpDir);
        $_GET     = $this->previousGet;
        $_POST    = $this->previousPost;
        $_SESSION = $this->previousSession;
    }

    public function testEveryPageRendersWithoutErrorsOnInitialLoad(): void
    {
        $failures = [];

        foreach ($this->pages() as $page) {
            if (isset(self::EXCLUDED[$page])) continue;

            try {
                $ctx = $this->ctx();
                // index.php defines these as plain variables before calling
                // PageLoader::load(), and extract(..., EXTR_SKIP) never
                // overwrites them - so several views read them directly
                // rather than through the loader's return value.
                $ignoredOrders = $ctx['ignoredOrders'];
                $storeId       = $ctx['storeId'];
                $storeLabel    = $ctx['storeLabel'];
                $userRole      = $ctx['userRole'];

                $data = PageLoader::load($page, '', $ctx);
                extract($data);
                $shopifyAdminBase ??= 'https://test.myshopify.com/admin/orders';

                ob_start();
                require dirname(__DIR__, 2) . "/views/{$page}.php";
                ob_end_clean();
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) ob_end_clean();
                $failures[] = "{$page}: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
        }

        $this->assertSame([], $failures, "Pages failed to render:\n" . implode("\n", $failures));
    }

    /** @return array<int, string> */
    private function pages(): array
    {
        $pages = [];
        foreach (glob(dirname(__DIR__, 2) . '/views/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (in_array($name, ['layout', 'login'], true)) continue;
            $pages[] = $name;
        }
        sort($pages);
        return $pages;
    }

    private function ctx(): array
    {
        return [
            'authed'        => true,
            'action'        => '',
            'ssKey'         => 'ss_key',
            'ssSecret'      => 'ss_secret',
            'shopifyToken'  => 'shpat_test',
            'shopifyStore'  => 'test.myshopify.com',
            'cacheObj'      => new Cache($this->tmpDir . '/cache', 3600),
            'cacheTtl'      => 3600,
            'reportDir'     => $this->tmpDir . '/reports',
            'ignoredOrders' => [],
            'appVersion'    => '2.0.0',
            'storeId'       => null,
            'storeLabel'    => 'Test Store',
            'userRole'      => 'admin',
            'flash'         => [],
        ];
    }
}
