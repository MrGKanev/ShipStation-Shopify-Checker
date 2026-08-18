<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Comparator.php';
require_once __DIR__ . '/../../src/SearchLookupPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SearchLookupPageLoaderTest extends TestCase
{
    private array $previousGet;
    private array $previousPost;

    protected function setUp(): void
    {
        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testGlobalSearchReturnsNullWhenQueryIsEmpty(): void
    {
        $data = SearchLookupPageLoader::load('globalsearch', '', $this->ctx(), []);

        $this->assertNull($data['gsResults']);
    }

    public function testGlobalSearchMatchesReportsPushLogAndIgnoredOrders(): void
    {
        $_GET['q'] = '#1001';

        $data = SearchLookupPageLoader::load('globalsearch', '', $this->ctx([
            'ignoredOrders' => [
                '1001' => ['reason' => 'test ignore', 'ignored_at' => '2026-06-01'],
                '2002' => ['reason' => 'other'],
            ],
        ]), [
            'orderHistory' => [
                '1001' => ['count' => 2, 'first' => '2026-06-01', 'last' => '2026-06-10'],
                '9999' => ['count' => 1, 'first' => '2026-06-01', 'last' => '2026-06-01'],
            ],
            'pushLog' => [
                ['order_number' => '#1001', 'pushed_at' => '2026-06-11'],
                ['order_number' => '#2002', 'pushed_at' => '2026-06-12'],
            ],
        ]);

        $this->assertSame('#1001', $data['gsResults']['query']);
        $this->assertSame('1001', $data['gsResults']['reports'][0]['number']);
        $this->assertSame('#1001', $data['gsResults']['push'][0]['order_number']);
        $this->assertSame('1001', $data['gsResults']['ignored'][0]['number']);
    }

    public function testTagSearchInitialStateAndValidationError(): void
    {
        $initial = SearchLookupPageLoader::load('tagsearch', '', $this->ctx());

        $this->assertNull($initial['tagSearch']);
        $this->assertSame('', $initial['tagSearchError']);
        $this->assertSame('', $initial['tagInput']);

        $_POST = ['tag_input' => '   ', 'tag_start' => '2026-06-01', 'tag_end' => '2026-06-20'];
        $submitted = SearchLookupPageLoader::load('tagsearch', 'tag_search', $this->ctx());

        $this->assertSame('Enter at least one tag.', $submitted['tagSearchError']);
        $this->assertSame('', $submitted['tagInput']);
        $this->assertSame('2026-06-01', $submitted['tagStart']);
        $this->assertSame('2026-06-20', $submitted['tagEnd']);
    }

    public function testTagSearchReportsMissingShopifyCredentials(): void
    {
        $_POST = ['tag_input' => 'vip'];

        $data = SearchLookupPageLoader::load('tagsearch', 'tag_search', $this->ctx());

        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['tagSearchError']);
        $this->assertSame('vip', $data['tagInput']);
    }

    public function testTagSearchSurfacesErrorWhenShopifyThrows(): void
    {
        $_POST = ['tag_input' => 'vip'];

        $data = SearchLookupPageLoader::load('tagsearch', 'tag_search', $this->ctx([
            'shopifyStore' => 'test.myshopify.com',
            'shopifyToken' => 'tok_test',
            'httpStack'    => $this->errorStack(),
        ]));

        $this->assertNull($data['tagSearch']);
        $this->assertNotSame('', $data['tagSearchError']);
    }

    public function testSpotCheckPrefillAndValidationErrors(): void
    {
        $_GET['prefill'] = '#1001';
        $initial = SearchLookupPageLoader::load('spotcheck', '', $this->ctx());

        $this->assertSame('#1001', $initial['spotInput']);
        $this->assertSame('', $initial['spotError']);
        $this->assertNull($initial['spotResults']);

        $_GET = [];
        $_POST = ['orders' => " \n "];
        $empty = SearchLookupPageLoader::load('spotcheck', 'spotcheck', $this->ctx());

        $this->assertSame('Enter at least one order number.', $empty['spotError']);

        $_POST = ['orders' => implode(' ', range(1, 51))];
        $tooMany = SearchLookupPageLoader::load('spotcheck', 'spotcheck', $this->ctx());

        $this->assertSame('Maximum 50 order numbers at once.', $tooMany['spotError']);

        $_POST = ['orders' => '1001', 'spotcheck_mode' => 'both'];
        $missingCredentials = SearchLookupPageLoader::load('spotcheck', 'spotcheck', $this->ctx());

        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $missingCredentials['spotError']);
    }

    public function testSpotCheckSurfacesErrorWhenShipStationThrows(): void
    {
        $_POST = ['orders' => '1001', 'spotcheck_mode' => 'ss'];

        $data = SearchLookupPageLoader::load('spotcheck', 'spotcheck', $this->ctx([
            'ssKey'     => 'ss_key',
            'ssSecret'  => 'ss_secret',
            'httpStack' => $this->errorStack(),
        ]));

        $this->assertSame([], $data['spotResults'], 'spotResults is initialised before the per-order loop that throws');
        $this->assertStringContainsString('Error:', $data['spotError']);
    }

    public function testSpotCheckShopifyOnlyWithoutCredentialsPreservesNullLookupResult(): void
    {
        $_POST = ['orders' => '#1001', 'spotcheck_mode' => 'shopify'];

        $data = SearchLookupPageLoader::load('spotcheck', 'spotcheck', $this->ctx());

        $this->assertSame('', $data['spotError']);
        $this->assertSame('1001', $data['spotResults'][0]['number']);
        $this->assertSame('shopify', $data['spotResults'][0]['mode']);
        $this->assertNull($data['spotResults'][0]['ss_orders']);
        $this->assertNull($data['spotResults'][0]['shopify_orders']);
        $this->assertFalse($data['spotResults'][0]['found']);
    }

    public function testMetafieldsReportsMissingShopifyCredentials(): void
    {
        $data = SearchLookupPageLoader::load('metafields', '', $this->ctx());

        $this->assertNull($data['metafieldDefs']);
        $this->assertNull($data['metafieldOrders']);
        $this->assertSame('', $data['metafieldInput']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['metafieldError']);
        $this->assertSame('', $data['metafieldFilter']);
        $this->assertNull($data['metafieldSearch']);
        $this->assertSame('', $data['metafieldSearchError']);
    }

    public function testMetafieldsSurfacesErrorWhenFetchingDefinitionsThrows(): void
    {
        $data = SearchLookupPageLoader::load('metafields', '', $this->ctx([
            'shopifyStore' => 'test.myshopify.com',
            'shopifyToken' => 'tok_test',
            'httpStack'    => $this->errorStack(),
        ]));

        $this->assertNull($data['metafieldDefs']);
        $this->assertStringContainsString('Could not load metafield definitions:', $data['metafieldError']);
    }

    public function testMetafieldsSearchSurfacesErrorWhenShopifyThrows(): void
    {
        $_POST = ['mf_ns' => 'custom', 'mf_key' => 'gift_message'];

        $stack = HandlerStack::create(new MockHandler([
            $this->json(['data' => ['metafieldDefinitions' => ['edges' => []]]]),
            new Response(500, [], 'Internal Server Error'),
        ]));

        $data = SearchLookupPageLoader::load('metafields', 'metafield_search', $this->ctx([
            'shopifyStore' => 'test.myshopify.com',
            'shopifyToken' => 'tok_test',
            'httpStack'    => $stack,
        ]));

        $this->assertSame('', $data['metafieldError'], 'definitions fetch succeeded, only the search call should fail');
        $this->assertNull($data['metafieldSearch']);
        $this->assertNotSame('', $data['metafieldSearchError']);
    }

    public function testCustomerLookupPrefillAndValidationErrors(): void
    {
        $_GET['email'] = 'customer@example.com';
        $initial = SearchLookupPageLoader::load('customer', '', $this->ctx());

        $this->assertSame('customer@example.com', $initial['customerEmail']);
        $this->assertSame('', $initial['customerError']);
        $this->assertNull($initial['customerResult']);

        $_GET = [];
        $_POST = ['customer_email' => 'not-an-email'];
        $invalid = SearchLookupPageLoader::load('customer', 'customer_lookup', $this->ctx());

        $this->assertSame('Enter a valid email address.', $invalid['customerError']);
        $this->assertSame('not-an-email', $invalid['customerEmail']);

        $_POST = ['customer_email' => 'customer@example.com'];
        $missingCredentials = SearchLookupPageLoader::load('customer', 'customer_lookup', $this->ctx());

        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $missingCredentials['customerError']);
    }

    public function testCustomerLookupSurfacesErrorWhenShopifyThrows(): void
    {
        $_POST = ['customer_email' => 'customer@example.com'];

        $data = SearchLookupPageLoader::load('customer', 'customer_lookup', $this->ctx([
            'shopifyStore' => 'test.myshopify.com',
            'shopifyToken' => 'tok_test',
            'httpStack'    => $this->errorStack(),
        ]));

        $this->assertNull($data['customerResult']);
        $this->assertNotSame('', $data['customerError']);
    }

    public function testTrackingPrefillAndValidationErrors(): void
    {
        $_GET['prefill'] = '#1001';
        $initial = SearchLookupPageLoader::load('tracking', '', $this->ctx());

        $this->assertSame('#1001', $initial['trackingInput']);
        $this->assertSame('', $initial['trackingError']);
        $this->assertNull($initial['trackingResults']);

        $_GET = [];
        $_POST = ['tracking_orders' => " \n "];
        $empty = SearchLookupPageLoader::load('tracking', 'lookup_tracking', $this->ctx());

        $this->assertSame('Enter at least one order number.', $empty['trackingError']);

        $_POST = ['tracking_orders' => implode(' ', range(1, 31))];
        $tooMany = SearchLookupPageLoader::load('tracking', 'lookup_tracking', $this->ctx());

        $this->assertSame('Maximum 30 order numbers at once.', $tooMany['trackingError']);

        $_POST = ['tracking_orders' => '1001'];
        $missingCredentials = SearchLookupPageLoader::load('tracking', 'lookup_tracking', $this->ctx());

        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $missingCredentials['trackingError']);
    }

    public function testTrackingSurfacesErrorWhenShipStationThrows(): void
    {
        $_POST = ['tracking_orders' => '1001'];

        $data = SearchLookupPageLoader::load('tracking', 'lookup_tracking', $this->ctx([
            'ssKey'     => 'ss_key',
            'ssSecret'  => 'ss_secret',
            'httpStack' => $this->errorStack(),
        ]));

        $this->assertSame([], $data['trackingResults'], 'trackingResults is initialised before the per-order loop that throws');
        $this->assertNotSame('', $data['trackingError']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], SearchLookupPageLoader::load('unknown', '', $this->ctx()));
    }

    // ── filterMetafields ─────────────────────────────────────────────────────

    private function filterMetafields(array $mfs, string $filter): array
    {
        $ref = new \ReflectionClass(SearchLookupPageLoader::class);
        return $ref->getMethod('filterMetafields')->invoke(null, $mfs, $filter);
    }

    public function testFilterMetafieldsMatchesNamespaceAndKeyCaseInsensitively(): void
    {
        $mfs = [
            ['namespace' => 'custom', 'key' => 'GiftMessage', 'value' => 'x'],
            ['namespace' => 'custom', 'key' => 'other', 'value' => 'y'],
        ];

        $result = $this->filterMetafields($mfs, 'giftmessage');

        $this->assertCount(1, $result);
        $this->assertSame('GiftMessage', $result[0]['key']);
    }

    public function testFilterMetafieldsMatchesValue(): void
    {
        $mfs = [
            ['namespace' => 'custom', 'key' => 'note', 'value' => 'Handle with care'],
            ['namespace' => 'custom', 'key' => 'other', 'value' => 'nothing special'],
        ];

        $result = $this->filterMetafields($mfs, 'handle with');

        $this->assertCount(1, $result);
        $this->assertSame('note', $result[0]['key']);
    }

    public function testFilterMetafieldsReturnsEmptyWhenNoMatch(): void
    {
        $this->assertSame([], $this->filterMetafields([['namespace' => 'a', 'key' => 'b', 'value' => 'c']], 'nomatch'));
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyStore'  => 'N/A',
            'shopifyToken'  => '',
            'ssKey'         => '',
            'ssSecret'      => '',
            'ignoredOrders' => [],
        ];
    }

    /**
     * A handler stack whose first request blows up with a server error,
     * simulating a Shopify/ShipStation API outage mid-lookup.
     */
    private function errorStack(): HandlerStack
    {
        return HandlerStack::create(new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]));
    }

    private function json(mixed $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
    }
}
