<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/support/TmpDir.php';
require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/DisputesPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DisputesPageLoader::buildDisputeRows() (via reflection - the
 * days_until_due computation and urgency sort) and the full load() flow
 * through a mocked Shopify GraphQL transport.
 */
class DisputesPageLoaderTest extends TestCase
{
    private static \ReflectionMethod $buildRowsMethod;
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(DisputesPageLoader::class);
        self::$buildRowsMethod = $ref->getMethod('buildDisputeRows');
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/disputes_loader_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        RunLog::setDataDir($this->tmpDir);

        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        TmpDir::remove($this->tmpDir);
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    private function dispute(array $overrides = []): array
    {
        return array_merge([
            'id' => 1, 'status' => 'needs_response', 'reason' => 'fraudulent',
            'network_reason_code' => '10.4', 'initiated_at' => '2026-06-01T00:00:00Z',
            'evidence_due_by' => null, 'amount' => '50.00', 'currency' => 'USD',
            'order_id' => 1001, 'order_name' => '#1001',
        ], $overrides);
    }

    private function buildRows(array $disputes, int $now): array
    {
        return self::$buildRowsMethod->invoke(null, $disputes, $now);
    }

    public function testComputesDaysUntilDue(): void
    {
        $now = strtotime('2026-06-01T00:00:00Z');
        $rows = $this->buildRows([$this->dispute(['evidence_due_by' => '2026-06-04T00:00:00Z'])], $now);

        $this->assertSame(3, $rows[0]['days_until_due']);
    }

    public function testNullDueByYieldsNullDaysUntilDue(): void
    {
        $rows = $this->buildRows([$this->dispute(['evidence_due_by' => null])], time());

        $this->assertNull($rows[0]['days_until_due']);
    }

    public function testSortsMostUrgentDeadlineFirst(): void
    {
        $now = strtotime('2026-06-01T00:00:00Z');
        $rows = $this->buildRows([
            $this->dispute(['order_name' => '#1', 'evidence_due_by' => '2026-06-10T00:00:00Z']),
            $this->dispute(['order_name' => '#2', 'evidence_due_by' => '2026-06-02T00:00:00Z']),
        ], $now);

        $this->assertSame('#2', $rows[0]['order_name']);
        $this->assertSame('#1', $rows[1]['order_name']);
    }

    public function testDisputesWithoutDeadlineSortLast(): void
    {
        $now = strtotime('2026-06-01T00:00:00Z');
        $rows = $this->buildRows([
            $this->dispute(['order_name' => '#1', 'evidence_due_by' => null]),
            $this->dispute(['order_name' => '#2', 'evidence_due_by' => '2026-06-10T00:00:00Z']),
        ], $now);

        $this->assertSame('#2', $rows[0]['order_name']);
        $this->assertSame('#1', $rows[1]['order_name']);
    }

    public function testMissingShopifyCredentials(): void
    {
        $_POST = ['action' => 'scan_disputes'];

        $data = DisputesPageLoader::load('disputes', 'scan_disputes', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['dpResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['dpError']);
    }

    public function testSuccessFlowReturnsScannedAndRows(): void
    {
        $stack = HandlerStack::create(new MockHandler([$this->graphQLDisputes([
            $this->disputeNode(),
        ])]));

        $data = DisputesPageLoader::load('disputes', 'scan_disputes', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['dpError']);
        $this->assertSame(1, $data['dpResult']['scanned']);
        $this->assertCount(1, $data['dpResult']['rows']);
        $this->assertSame('#1001', $data['dpResult']['rows'][0]['order_name']);
    }

    public function testInitialLoadWithoutActionReturnsNullResult(): void
    {
        $data = DisputesPageLoader::load('disputes', '', $this->ctx());

        $this->assertNull($data['dpResult']);
        $this->assertSame('', $data['dpError']);
    }

    private function graphQLDisputes(array $nodes): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['disputes' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => array_map(fn($n) => ['node' => $n], $nodes),
            ]],
        ]));
    }

    private function disputeNode(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'gid://shopify/ShopifyPaymentsDispute/1', 'legacyResourceId' => '1',
            'status' => 'NEEDS_RESPONSE', 'initiatedAt' => '2026-06-01T00:00:00Z',
            'evidenceDueBy' => '2026-06-10T00:00:00Z',
            'amount' => ['amount' => '50.00', 'currencyCode' => 'USD'],
            'reasonDetails' => ['reason' => 'FRAUDULENT', 'networkReasonCode' => '10.4'],
            'order' => ['id' => 'gid://shopify/Order/1001', 'legacyResourceId' => '1001', 'name' => '#1001'],
        ], $overrides);
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
        ];
    }
}
