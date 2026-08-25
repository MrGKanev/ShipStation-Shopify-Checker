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

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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

    public function testOrderEditsSurfacesErrorWhenShopifyThrows(): void
    {
        $_POST = ['oe_start' => '2026-06-01', 'oe_end' => '2026-06-20'];

        $stack = HandlerStack::create(new MockHandler([new Response(500, [], 'Internal Server Error')]));
        $data  = OrderPolicyPageLoader::load('orderedits', 'scan_order_edits', $this->ctx(['httpStack' => $stack]));

        $this->assertNull($data['oeResult']);
        $this->assertNotSame('', $data['oeError']);
    }

    public function testOrderEditsSurfacesDateValidationError(): void
    {
        $_POST = ['oe_start' => '2026-06-20', 'oe_end' => '2026-06-01'];

        $data = OrderPolicyPageLoader::load('orderedits', 'scan_order_edits', $this->ctx());

        $this->assertNull($data['oeResult']);
        $this->assertNotSame('', $data['oeError']);
    }

    public function testOrderEditsSuccessReturnsEmptyRowsWhenNoEditEvents(): void
    {
        $_POST = ['oe_start' => '2026-06-01', 'oe_end' => '2026-06-20'];

        $stack = $this->shopifyStack([$this->graphQLEvents([])]);
        $data  = OrderPolicyPageLoader::load('orderedits', 'scan_order_edits', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['oeError']);
        $this->assertSame(['rows' => [], 'start' => '2026-06-01', 'end' => '2026-06-20'], $data['oeResult']);
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

    public function testConsentAuditMissingShopifyCredentials(): void
    {
        $_POST = ['ca_start' => '2026-06-01', 'ca_end' => '2026-06-20'];

        $data = OrderPolicyPageLoader::load('consentaudit', 'scan_consentaudit', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['caResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['caError']);
        $this->assertSame('validation_error', RunLog::all()[0]['status']);
    }

    public function testFraudRiskReportMissingShopifyCredentials(): void
    {
        $_POST = ['fr_start' => '2026-06-01', 'fr_end' => '2026-06-20'];

        $data = OrderPolicyPageLoader::load('riskreport', 'scan_riskreport', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['frResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['frError']);
    }

    public function testSameIpMissingShopifyCredentials(): void
    {
        $_POST = ['si_start' => '2026-06-01', 'si_end' => '2026-06-20'];

        $data = OrderPolicyPageLoader::load('sameip', 'scan_sameip', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['siResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['siError']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], OrderPolicyPageLoader::load('unknown', '', $this->ctx()));
    }

    // ── Success paths (full scan through the mocked HTTP transport) ───────────

    public function testNoteFlagsSuccessMatchesKeywordInNote(): void
    {
        $_POST = ['nf_start' => '2026-06-01', 'nf_end' => '2026-06-20', 'nf_keywords' => 'hold'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['note' => 'please hold this order']),
        ])]);

        $data = OrderPolicyPageLoader::load('noteflags', 'scan_noteflags', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['nfError']);
        $this->assertNotNull($data['nfResult']);
        $this->assertCount(1, $data['nfResult']['rows']);
        $this->assertSame(['hold'], $data['nfResult']['rows'][0]['matched']);
    }

    public function testNoteFlagsSurfacesErrorWhenNoKeywordsGiven(): void
    {
        $_POST = ['nf_start' => '2026-06-01', 'nf_end' => '2026-06-20', 'nf_keywords' => ' , , '];

        $data = OrderPolicyPageLoader::load('noteflags', 'scan_noteflags', $this->ctx());

        $this->assertNull($data['nfResult']);
        $this->assertSame('Enter at least one keyword.', $data['nfError']);
    }

    public function testAddrDupesSuccessGroupsSameAddressAcrossEmails(): void
    {
        $_POST = ['ad_start' => '2026-06-01', 'ad_end' => '2026-06-20'];
        $addr = ['address1' => '1 Main St', 'city' => 'Austin', 'zip' => '78701', 'country_code' => 'US'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['name' => '#1001', 'email' => 'a@example.com', 'shippingAddress' => $this->addressNode($addr)]),
            $this->orderNode(['name' => '#1002', 'email' => 'b@example.com', 'shippingAddress' => $this->addressNode($addr)]),
        ])]);

        $data = OrderPolicyPageLoader::load('addrdupes', 'scan_addrdupes', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['adError']);
        $this->assertCount(1, $data['adResult']['rows']);
        $this->assertSame(2, $data['adResult']['rows'][0]['email_count']);
    }

    public function testFraudRiskReportSuccessFlagsHighRiskOrder(): void
    {
        $_POST = ['fr_start' => '2026-06-01', 'fr_end' => '2026-06-20'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['name' => '#3001', 'tags' => ['fraud'], 'risk' => ['recommendation' => 'CANCEL', 'assessments' => [
                ['riskLevel' => 'HIGH', 'provider' => ['title' => 'Shopify'], 'facts' => []],
            ]]]),
        ])]);

        $data = OrderPolicyPageLoader::load('riskreport', 'scan_riskreport', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['frError']);
        $this->assertCount(1, $data['frResult']['rows']);
        $this->assertSame('high', $data['frResult']['rows'][0]['risk']['level']);
    }

    public function testSameIpSuccessGroupsSameIpAcrossEmails(): void
    {
        $_POST = ['si_start' => '2026-06-01', 'si_end' => '2026-06-20'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['name' => '#4001', 'email' => 'a@example.com', 'clientIp' => '203.0.113.5']),
            $this->orderNode(['name' => '#4002', 'email' => 'b@example.com', 'clientIp' => '203.0.113.5']),
        ])]);

        $data = OrderPolicyPageLoader::load('sameip', 'scan_sameip', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['siError']);
        $this->assertCount(1, $data['siResult']['rows']);
        $this->assertSame('203.0.113.5', $data['siResult']['rows'][0]['ip']);
        $this->assertSame(2, $data['siResult']['rows'][0]['email_count']);
    }

    public function testActiveSsConflictsSuccessFlagsRefundedOrderStillActiveInSs(): void
    {
        $_POST = ['as_start' => '2026-06-01', 'as_end' => '2026-06-20'];

        $shopifyStack = $this->shopifyStack([
            $this->graphQLOrders([$this->orderNode(['name' => '#2001', 'legacyResourceId' => '2001', 'displayFinancialStatus' => 'REFUNDED'])]),
            $this->graphQLOrders([]),
        ]);
        $ssStack = $this->shipStationStack([
            $this->shipStationOrders([['orderId' => 9, 'orderNumber' => '2001', 'orderStatus' => 'awaiting_shipment']]),
            $this->shipStationOrders([]),
            $this->shipStationOrders([]),
        ]);

        $data = OrderPolicyPageLoader::load('activess', 'scan_activess', $this->ctx(['httpStack' => $shopifyStack, 'ssStack' => $ssStack]));

        $this->assertSame('', $data['asError']);
        $this->assertCount(1, $data['asResult']['rows']);
        $this->assertSame('refunded', $data['asResult']['rows'][0]['issue']);
    }

    public function testDiscountAbuseSuccessFlagsCodeSharedAcrossEmails(): void
    {
        $_POST = ['da_start' => '2026-06-01', 'da_end' => '2026-06-20', 'da_min_emails' => '2'];
        $addr = ['address1' => '1 Main St', 'city' => 'Austin', 'zip' => '78701', 'country_code' => 'US'];
        $discount = $this->discountCodeNode('SAVE10');
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['name' => '#3001', 'email' => 'a@example.com', 'shippingAddress' => $this->addressNode($addr), 'discountApplications' => ['nodes' => [$discount]]]),
            $this->orderNode(['name' => '#3002', 'email' => 'b@example.com', 'shippingAddress' => $this->addressNode($addr), 'discountApplications' => ['nodes' => [$discount]]]),
        ])]);

        $data = OrderPolicyPageLoader::load('discountabuse', 'scan_discountabuse', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['daError']);
        $this->assertCount(1, $data['daResult']['rows']);
        $this->assertSame('SAVE10', $data['daResult']['rows'][0]['code']);
    }

    public function testTagPolicySuccessSkipsShopifyCallWhenUnconfigured(): void
    {
        // No tag_policy.json present in this environment -> tagPolicyConfig()
        // returns [] and the closure short-circuits before touching Shopify,
        // so an empty response queue proves the HTTP call never happens.
        $this->assertFileDoesNotExist(__DIR__ . '/../../tag_policy.json');

        $_POST = ['tp_start' => '2026-06-01', 'tp_end' => '2026-06-20'];
        $stack = $this->shopifyStack([]);

        $data = OrderPolicyPageLoader::load('tagpolicy', 'scan_tagpolicy', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['tpError']);
        $this->assertFalse($data['tpResult']['configured']);
        $this->assertSame([], $data['tpResult']['rows']);
    }

    public function testConsentAuditSuccessFlagsUnsubscribedCustomer(): void
    {
        $_POST = ['ca_start' => '2026-06-01', 'ca_end' => '2026-06-20'];
        $stack = $this->shopifyStack([$this->graphQLOrders([
            $this->orderNode(['name' => '#5001', 'customer' => ['emailMarketingConsent' => ['marketingState' => 'NOT_SUBSCRIBED']]]),
        ])]);

        $data = OrderPolicyPageLoader::load('consentaudit', 'scan_consentaudit', $this->ctx(['httpStack' => $stack]));

        $this->assertSame('', $data['caError']);
        $this->assertCount(1, $data['caResult']['rows']);
        $this->assertSame('not_subscribed', $data['caResult']['rows'][0]['email_consent']);
    }

    // ── Mock HTTP helpers ───────────────────────────────────────────────────

    private function shopifyStack(array $responses): HandlerStack
    {
        return HandlerStack::create(new MockHandler($responses));
    }

    private function shipStationStack(array $responses): HandlerStack
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

    private function addressNode(array $fields): array
    {
        return [
            'firstName'    => $fields['first_name'] ?? 'Jane',
            'lastName'     => $fields['last_name'] ?? 'Doe',
            'address1'     => $fields['address1'] ?? '',
            'city'         => $fields['city'] ?? '',
            'provinceCode' => $fields['province_code'] ?? '',
            'countryCodeV2' => $fields['country_code'] ?? '',
            'zip'          => $fields['zip'] ?? '',
        ];
    }

    private function discountCodeNode(string $code): array
    {
        return [
            '__typename' => 'DiscountCodeApplication',
            'code'       => $code,
            'value'      => ['__typename' => 'MoneyV2', 'amount' => '10.00'],
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
