<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Cache.php';
require_once __DIR__ . '/../../src/JobQueue.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/UserActionLog.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ApiHealth.php';
require_once __DIR__ . '/../../src/ConfigValidator.php';
require_once __DIR__ . '/../../src/ManageSettingsPageLoader.php';

use PHPUnit\Framework\TestCase;

class ManageSettingsPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private Cache $cache;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/manage_settings_loader_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->cache = new Cache($this->tmpDir . '/cache', ttl: 3600);
        JobQueue::setDataDir($this->tmpDir);
        SlackRules::setDataDir($this->tmpDir);
        UserActionLog::setDataDir($this->tmpDir);
        RunLog::setDataDir($this->tmpDir);

        $this->previousSlackWebhook = getenv('SLACK_WEBHOOK_URL');
        putenv('SLACK_WEBHOOK_URL');
        $_GET = [];
    }

    protected function tearDown(): void
    {
        if ($this->previousSlackWebhook === false) {
            putenv('SLACK_WEBHOOK_URL');
        } else {
            putenv('SLACK_WEBHOOK_URL=' . $this->previousSlackWebhook);
        }

        $this->removeDir($this->tmpDir);
    }

    public function testSettingsListsAndFlushesCacheEntries(): void
    {
        $this->cache->put('shop', 'one', ['a' => 1]);
        $this->cache->put('ss', 'two', ['b' => 2]);

        $settings = ManageSettingsPageLoader::load('settings', '', $this->ctx());

        $this->assertNull($settings['connResults']);
        $this->assertSame(0, $settings['cacheFlushed']);
        $this->assertSame(3600, $settings['cacheTtl']);
        $this->assertCount(2, $settings['cacheEntries']);

        $flushed = ManageSettingsPageLoader::load('settings', 'flush_cache', $this->ctx());

        $this->assertSame(0, $flushed['cacheFlushed']);
        $this->assertCount(2, $flushed['cacheEntries']);
    }

    public function testSettingsReadsConnectionResultsFromFlash(): void
    {
        $settings = ManageSettingsPageLoader::load('settings', '', $this->ctx([
            'flash' => [
                'conn_results' => [
                    'ss' => ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SS_API_KEY / SS_API_SECRET not set in .env'],
                    'shopify' => ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env'],
                ],
            ],
        ]));

        $this->assertFalse($settings['connResults']['ss']['ok']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env', $settings['connResults']['ss']['error']);
        $this->assertFalse($settings['connResults']['shopify']['ok']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env', $settings['connResults']['shopify']['error']);
    }

    public function testApiHealthListsShopifyFlowStatusesFromRunLog(): void
    {
        RunLog::append([
            'tool'       => 'scan_addresses',
            'status'     => 'error',
            'created_at' => '2026-06-20 09:00:00',
            'error'      => 'Shopify GraphQL: missing scope',
        ]);
        RunLog::append([
            'tool'       => 'scan_bundle',
            'status'     => 'issues_found',
            'created_at' => '2026-06-20 10:00:00',
            'rows_found' => 2,
        ]);

        $data = ManageSettingsPageLoader::load('apihealth', '', $this->ctx());

        $this->assertNull($data['apiHealth']);
        $this->assertGreaterThan(20, $data['shopifyFlowHealth']['summary']['total']);
        $this->assertSame(1, $data['shopifyFlowHealth']['summary']['attention']);
        $this->assertSame(1, $data['shopifyFlowHealth']['summary']['healthy']);

        $addressFlow = $this->flowByTool($data['shopifyFlowHealth']['flows'], 'scan_addresses');
        $this->assertSame('Address validation', $addressFlow['label']);
        $this->assertSame('error', $addressFlow['status']);
        $this->assertSame('Shopify GraphQL: missing scope', $addressFlow['error_message']);
        $this->assertSame(1, $addressFlow['errors']);

        $bundleFlow = $this->flowByTool($data['shopifyFlowHealth']['flows'], 'scan_bundle');
        $this->assertSame('issues_found', $bundleFlow['status']);
        $this->assertSame(2, $bundleFlow['latest']['rows_found']);

        $productFlow = $this->flowByTool($data['shopifyFlowHealth']['flows'], 'scan_products');
        $this->assertSame('never_run', $productFlow['status']);
    }

    public function testApiHealthReadsCheckResultsFromFlash(): void
    {
        $data = ManageSettingsPageLoader::load('apihealth', '', $this->ctx([
            'flash' => [
                'api_health' => [
                    'shopify' => ['ok' => true],
                    'shipstation' => ['ok' => false],
                    'checked_at' => '2026-07-23 10:00:00',
                ],
            ],
        ]));

        $this->assertSame('2026-07-23 10:00:00', $data['apiHealth']['checked_at']);
        $this->assertTrue($data['apiHealth']['shopify']['ok']);
        $this->assertFalse($data['apiHealth']['shipstation']['ok']);
    }

    public function testLoadsManageAndSettingsDataSources(): void
    {
        JobQueue::enqueue('audit', ['start' => '2026-06-01'], 'Audit');
        SlackRules::save(['audit_enabled' => false, 'scan_enabled' => true, 'scan_min_rows' => 3]);
        UserActionLog::append('ignore_order', ['order_number' => '1001']);
        putenv('SLACK_WEBHOOK_URL=https://hooks.slack.test/example');

        $jobs = ManageSettingsPageLoader::load('jobs', '', $this->ctx());
        $slack = ManageSettingsPageLoader::load('slackrules', '', $this->ctx());
        $actions = ManageSettingsPageLoader::load('actionlog', '', $this->ctx());

        $this->assertSame('audit', $jobs['jobs'][0]['type']);
        $this->assertFalse($slack['slackRules']['audit_enabled']);
        $this->assertTrue($slack['slackRules']['scan_enabled']);
        $this->assertSame(3, $slack['slackRules']['scan_min_rows']);
        $this->assertTrue($slack['slackConfigured']);
        $this->assertSame('ignore_order', $actions['actionLog'][0]['action']);
        $this->assertSame('1001', $actions['actionLog'][0]['details']['order_number']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], ManageSettingsPageLoader::load('unknown', '', $this->ctx()));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'cacheObj'     => $this->cache,
            'cacheTtl'     => 3600,
            'shopifyStore' => 'N/A',
            'shopifyToken' => '',
            'ssKey'        => '',
            'ssSecret'     => '',
            'flash'        => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $flows
     * @return array<string, mixed>
     */
    private function flowByTool(array $flows, string $tool): array
    {
        foreach ($flows as $flow) {
            if (($flow['tool'] ?? '') === $tool) {
                return $flow;
            }
        }

        $this->fail("Flow {$tool} was not listed.");
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
