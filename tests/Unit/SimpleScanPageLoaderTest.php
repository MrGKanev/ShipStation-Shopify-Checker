<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/AuditSnapshot.php';
require_once __DIR__ . '/support/TmpDir.php';
require_once __DIR__ . '/../../src/SlackRules.php';
require_once __DIR__ . '/../../src/SlackNotifier.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/ScanRunner.php';
require_once __DIR__ . '/../../src/SimpleScanPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SimpleScanPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;
    private string|false $previousSlackWebhook;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/simple_scan_loader_' . uniqid();
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

    public function testTagAuditInitialStateUsesRequestRange(): void
    {
        $_GET = ['ta_start' => '2026-06-01', 'ta_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('tagaudit', '', $this->ctx());

        $this->assertNull($data['tagAuditResult']);
        $this->assertSame('', $data['tagAuditError']);
        $this->assertSame('2026-06-01', $data['taStart']);
        $this->assertSame('2026-06-20', $data['taEnd']);
        $this->assertSame([], RunLog::all());
    }

    public function testTagAuditMissingShopifyCredentials(): void
    {
        $_POST = ['ta_start' => '2026-06-01', 'ta_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('tagaudit', 'tag_audit', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['tagAuditResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['tagAuditError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testEmailCheckMissingShopifyCredentials(): void
    {
        $_POST = ['email_start' => '2026-06-01', 'email_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('emailcheck', 'scan_emails', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['emailResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['emailError']);
        $this->assertSame('2026-06-01', $data['emailStart']);
        $this->assertSame('2026-06-20', $data['emailEnd']);
    }

    public function testHighValueOrdersCarriesMinimumAndMissingCredentials(): void
    {
        $_GET = ['hv_min' => '350'];
        $initial = SimpleScanPageLoader::load('hvorders', '', $this->ctx());

        $this->assertNull($initial['hvResult']);
        $this->assertSame('', $initial['hvError']);
        $this->assertSame(350, $initial['hvMin']);

        $_GET = [];
        $_POST = ['hv_start' => '2026-06-01', 'hv_end' => '2026-06-20', 'hv_min' => '500'];
        $submitted = SimpleScanPageLoader::load('hvorders', 'scan_hvorders', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['hvResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['hvError']);
        $this->assertSame(500, $submitted['hvMin']);
    }

    public function testCountryMismatchMissingShopifyCredentials(): void
    {
        $_POST = ['cm_start' => '2026-06-01', 'cm_end' => '2026-06-20'];

        $data = SimpleScanPageLoader::load('countrymismatch', 'scan_country_mismatch', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['cmResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['cmError']);
        $this->assertSame('2026-06-01', $data['cmStart']);
        $this->assertSame('2026-06-20', $data['cmEnd']);
    }

    public function testTaxAuditMissingShopifyCredentials(): void
    {
        $_POST = ['tx_start' => '2026-06-01', 'tx_end' => '2026-06-20', 'tx_min' => '10'];

        $data = SimpleScanPageLoader::load('taxaudit', 'scan_taxaudit', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['txResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['txError']);
        $this->assertSame(10.0, $data['txMin']);
    }

    public function testPartialFulfillCarriesThresholdAndMissingCredentials(): void
    {
        $_GET = ['pf_threshold' => '12'];
        $initial = SimpleScanPageLoader::load('partialfulfill', '', $this->ctx());

        $this->assertNull($initial['pfResult']);
        $this->assertSame('', $initial['pfError']);
        $this->assertSame(12, $initial['pfThreshold']);

        $_GET = [];
        $_POST = ['pf_start' => '2026-06-01', 'pf_end' => '2026-06-20', 'pf_threshold' => '9'];
        $submitted = SimpleScanPageLoader::load('partialfulfill', 'scan_partial_fulfill', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($submitted['pfResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $submitted['pfError']);
        $this->assertSame(9, $submitted['pfThreshold']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], SimpleScanPageLoader::load('unknown', '', $this->ctx()));
    }

    // ── returneditems ────────────────────────────────────────────────────────

    public function testReturnedItemsInitialStateUsesRequestRange(): void
    {
        $_GET = ['ri_start' => '2026-07-01', 'ri_end' => '2026-07-31'];

        $data = SimpleScanPageLoader::load('returneditems', '', $this->ctx());

        $this->assertNull($data['riResult']);
        $this->assertSame('', $data['riError']);
        $this->assertSame('2026-07-01', $data['riStart']);
        $this->assertSame('2026-07-31', $data['riEnd']);
    }

    public function testReturnedItemsRequiresShopifyCredentialsFirst(): void
    {
        $_POST = ['ri_start' => '2026-07-01', 'ri_end' => '2026-07-31'];

        $data = SimpleScanPageLoader::load(
            'returneditems',
            'scan_returneditems',
            $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A'])
        );

        $this->assertNull($data['riResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['riError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testEmailReturnedItemsValidatesDateRangeFirst(): void
    {
        $_POST = ['ri_start' => 'not-a-date', 'ri_end' => '2026-07-31'];

        $data = SimpleScanPageLoader::load('returneditems', 'email_returneditems', $this->ctx());

        $this->assertSame('', $data['riEmailMessage']);
        $this->assertSame('Invalid date format. Use YYYY-MM-DD.', $data['riEmailError']);
    }

    public function testEmailReturnedItemsRequiresShopifyCredentials(): void
    {
        $_POST = ['ri_start' => '2026-07-01', 'ri_end' => '2026-07-31'];

        $data = SimpleScanPageLoader::load(
            'returneditems',
            'email_returneditems',
            $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A'])
        );

        $this->assertSame('', $data['riEmailMessage']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['riEmailError']);
    }

    public function testEmailReturnedItemsRequiresSmtpConfiguration(): void
    {
        $previousSmtpHost   = getenv('SMTP_HOST');
        $previousAlertEmail = getenv('ALERT_EMAIL');
        putenv('SMTP_HOST');
        putenv('ALERT_EMAIL');

        try {
            $_POST = ['ri_start' => '2026-07-01', 'ri_end' => '2026-07-31'];

            $data = SimpleScanPageLoader::load('returneditems', 'email_returneditems', $this->ctx());

            $this->assertSame('', $data['riEmailMessage']);
            $this->assertSame('SMTP_HOST / ALERT_EMAIL not set in .env.', $data['riEmailError']);
        } finally {
            $previousSmtpHost === false ? putenv('SMTP_HOST') : putenv("SMTP_HOST={$previousSmtpHost}");
            $previousAlertEmail === false ? putenv('ALERT_EMAIL') : putenv("ALERT_EMAIL={$previousAlertEmail}");
        }
    }

    public function testReturnedItemsScanReturnsAggregatedRowsFromShopify(): void
    {
        $_POST = ['ri_start' => '2026-07-01', 'ri_end' => '2026-07-31'];

        $data = SimpleScanPageLoader::load(
            'returneditems',
            'scan_returneditems',
            $this->ctx(['httpStack' => $this->refundedOrdersStack()])
        );

        $this->assertSame('', $data['riError']);
        $this->assertSame(1, $data['riResult']['scanned']);
        $this->assertSame([['product' => 'Widget - blue', 'quantity' => 2]], $data['riResult']['rows']);
    }

    public function testEmailReturnedItemsSendsCsvAttachmentOnSuccess(): void
    {
        $previousAlertEmail = getenv('ALERT_EMAIL');
        putenv('ALERT_EMAIL=ops@test.com');

        try {
            $_POST    = ['ri_start' => '2026-07-01', 'ri_end' => '2026-07-31'];
            $notifier = new RecordingEmailNotifier('smtp.test', 587, 'user@test.com', 'pw', 'from@test.com', 'ops@test.com', 'tls');

            $data = SimpleScanPageLoader::load(
                'returneditems',
                'email_returneditems',
                $this->ctx(['httpStack' => $this->refundedOrdersStack(), 'emailNotifier' => $notifier])
            );

            $this->assertSame('', $data['riEmailError']);
            $this->assertSame('Emailed to ops@test.com.', $data['riEmailMessage']);
            $this->assertSame([['product' => 'Widget - blue', 'quantity' => 2]], $data['riResult']['rows']);

            $this->assertCount(1, $notifier->sent);
            $this->assertSame('ops@test.com', $notifier->sent[0]['to']);
            $this->assertStringContainsString('Widget - blue', $notifier->sent[0]['body']);

            preg_match('/Content-Disposition: attachment.*?\r\n\r\n(.*?)\r\n--/s', $notifier->sent[0]['body'], $m);
            $csv = base64_decode($m[1] ?? '', true);
            $this->assertNotFalse($csv);
            $this->assertStringContainsString('Widget - blue', $csv);
            $this->assertStringContainsString(',2', $csv);
        } finally {
            $previousAlertEmail === false ? putenv('ALERT_EMAIL') : putenv("ALERT_EMAIL={$previousAlertEmail}");
        }
    }

    private function refundedOrdersStack(): HandlerStack
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
                                'displayFinancialStatus'   => 'PARTIALLY_REFUNDED',
                                'displayFulfillmentStatus' => 'FULFILLED',
                                'totalPriceSet'            => ['shopMoney' => ['amount' => '80.00', 'currencyCode' => 'USD']],
                                'refunds' => [[
                                    'id'                 => 'gid://shopify/Refund/1',
                                    'legacyResourceId'   => '1',
                                    'createdAt'          => '2026-07-06T10:00:00Z',
                                    'note'               => 'Wrong size',
                                    'totalRefundedSet'   => ['shopMoney' => ['amount' => '40.00', 'currencyCode' => 'USD']],
                                    'refundLineItems'    => ['nodes' => [[
                                        'quantity'   => 2,
                                        'subtotalSet' => ['shopMoney' => ['amount' => '40.00', 'currencyCode' => 'USD']],
                                        'lineItem'   => [
                                            'id'       => 'gid://shopify/LineItem/1',
                                            'title'    => 'Widget',
                                            'name'     => 'Widget - blue',
                                            'sku'      => 'WIDGET-BLUE',
                                            'quantity' => 2,
                                        ],
                                    ]]],
                                    'transactions' => ['nodes' => []],
                                ]],
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
        ];
    }
}
