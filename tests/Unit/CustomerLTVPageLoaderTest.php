<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DateRange.php';
require_once __DIR__ . '/../../src/CustomerLTVPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CustomerLTVPageLoader::build() via reflection (private static method).
 */
class CustomerLTVPageLoaderTest extends TestCase
{
    private static \ReflectionMethod $build;
    private array $previousPost;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(CustomerLTVPageLoader::class);
        self::$build = $ref->getMethod('build');
    }

    protected function setUp(): void
    {
        $this->previousPost = $_POST;
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->previousPost;
    }

    /**
     * Call the private build() method with the given orders.
     */
    private function build(array $orders, string $start = '2024-01-01', string $end = '2024-12-31'): array
    {
        return self::$build->invoke(null, $orders, $start, $end);
    }

    private function ctx(array $overrides = []): array
    {
        return array_merge(['shopifyToken' => 'tok', 'shopifyStore' => 'store.myshopify.com'], $overrides);
    }

    // ── load() ────────────────────────────────────────────────────────────────

    public function testLoadReturnsDefaultsWhenActionIsNotScanLtv(): void
    {
        $data = CustomerLTVPageLoader::load('cohort', '', $this->ctx());

        $this->assertNull($data['ltvResult']);
        $this->assertSame('', $data['ltvError']);
    }

    public function testLoadReturnsValidationErrorForInvalidDateRange(): void
    {
        $_POST = ['ltv_start' => '2024-12-31', 'ltv_end' => '2024-01-01'];

        $data = CustomerLTVPageLoader::load('cohort', 'scan_ltv', $this->ctx());

        $this->assertNull($data['ltvResult']);
        $this->assertNotSame('', $data['ltvError']);
    }

    public function testLoadReturnsErrorForMissingShopifyCredentials(): void
    {
        $_POST = ['ltv_start' => '2024-01-01', 'ltv_end' => '2024-01-31'];

        $data = CustomerLTVPageLoader::load('cohort', 'scan_ltv', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['ltvResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['ltvError']);
    }

    public function testLoadSurfacesErrorWhenShopifyThrows(): void
    {
        $_POST = ['ltv_start' => '2024-01-01', 'ltv_end' => '2024-01-31'];

        $stack = HandlerStack::create(new MockHandler([new Response(500, [], 'Internal Server Error')]));
        $data  = CustomerLTVPageLoader::load('cohort', 'scan_ltv', $this->ctx(['httpStack' => $stack]));

        $this->assertNull($data['ltvResult']);
        $this->assertNotSame('', $data['ltvError']);
    }

    public function testLoadReturnsErrorForPlaceholderStoreWithToken(): void
    {
        $_POST = ['ltv_start' => '2024-01-01', 'ltv_end' => '2024-01-31'];

        $data = CustomerLTVPageLoader::load('cohort', 'scan_ltv', $this->ctx(['shopifyStore' => 'N/A']));

        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['ltvError']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function testEmptyOrdersReturnsEmptyTopCustomers(): void
    {
        $result = $this->build([]);
        $this->assertSame([], $result['top_customers']);
    }

    public function testEmptyOrdersReturnsEmptyCohort(): void
    {
        $result = $this->build([]);
        $this->assertSame([], $result['cohort']);
    }

    public function testEmptyOrdersZeroTotals(): void
    {
        $result = $this->build([]);
        $this->assertSame(0, $result['total_orders']);
        $this->assertSame(0, $result['total_customers']);
        $this->assertEqualsWithDelta(0.0, $result['total_revenue'], 0.001);
    }

    // ── Cancelled orders excluded ─────────────────────────────────────────────

    public function testCancelledOrdersExcludedFromRevenue(): void
    {
        $orders = [
            [
                'email'        => 'alice@example.com',
                'total_price'  => '100.00',
                'created_at'   => '2024-03-01T10:00:00Z',
                'cancelled_at' => '2024-03-02T10:00:00Z',
            ],
        ];
        $result = $this->build($orders);
        $this->assertSame([], $result['top_customers']);
        $this->assertEqualsWithDelta(0.0, $result['total_revenue'], 0.001);
    }

    public function testCancelledOrdersNotCountedInCustomers(): void
    {
        $orders = [
            [
                'email'        => 'bob@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-04-01T10:00:00Z',
                'cancelled_at' => '2024-04-02T10:00:00Z',
            ],
        ];
        $result = $this->build($orders);
        $this->assertSame(0, $result['total_customers']);
    }

    public function testMixedCancelledAndActiveOrders(): void
    {
        $orders = [
            [
                'email'        => 'carol@example.com',
                'total_price'  => '200.00',
                'created_at'   => '2024-05-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'carol@example.com',
                'total_price'  => '999.00',
                'created_at'   => '2024-05-15T10:00:00Z',
                'cancelled_at' => '2024-05-16T10:00:00Z',
            ],
        ];
        $result = $this->build($orders);
        $this->assertCount(1, $result['top_customers']);
        $this->assertEqualsWithDelta(200.0, $result['top_customers'][0]['total'], 0.001);
    }

    // ── Email normalisation ───────────────────────────────────────────────────

    public function testEmailIsCaseFolded(): void
    {
        $orders = [
            [
                'email'        => 'Dave@Example.COM',
                'total_price'  => '30.00',
                'created_at'   => '2024-06-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'dave@example.com',
                'total_price'  => '40.00',
                'created_at'   => '2024-06-05T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        // Two orders for the same customer once email is lowercased
        $this->assertCount(1, $result['top_customers']);
        $this->assertSame('dave@example.com', $result['top_customers'][0]['email']);
        $this->assertEqualsWithDelta(70.0, $result['top_customers'][0]['total'], 0.001);
    }

    public function testOrdersWithBlankEmailAreIgnored(): void
    {
        $orders = [
            [
                'email'        => '',
                'total_price'  => '100.00',
                'created_at'   => '2024-07-01T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $this->assertSame([], $result['top_customers']);
    }

    // ── Sorting by total descending ───────────────────────────────────────────

    public function testTopCustomersSortedByTotalDescending(): void
    {
        $orders = [
            [
                'email'        => 'low@example.com',
                'total_price'  => '10.00',
                'created_at'   => '2024-01-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'high@example.com',
                'total_price'  => '500.00',
                'created_at'   => '2024-01-02T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'mid@example.com',
                'total_price'  => '150.00',
                'created_at'   => '2024-01-03T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $tops = $result['top_customers'];
        $this->assertSame('high@example.com', $tops[0]['email']);
        $this->assertSame('mid@example.com',  $tops[1]['email']);
        $this->assertSame('low@example.com',  $tops[2]['email']);
    }

    public function testTopCustomersLimitedTo100(): void
    {
        $orders = [];
        for ($i = 1; $i <= 150; $i++) {
            $orders[] = [
                'email'        => "customer{$i}@example.com",
                'total_price'  => (string) $i,
                'created_at'   => '2024-02-01T10:00:00Z',
                'cancelled_at' => null,
            ];
        }
        $result = $this->build($orders);
        $this->assertCount(100, $result['top_customers']);
        // Highest total should be first
        $this->assertEqualsWithDelta(150.0, $result['top_customers'][0]['total'], 0.001);
    }

    // ── avg (average order value) ─────────────────────────────────────────────

    public function testAvgOrderValueSingleOrder(): void
    {
        $orders = [
            [
                'email'        => 'single@example.com',
                'total_price'  => '80.00',
                'created_at'   => '2024-03-01T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $this->assertEqualsWithDelta(80.0, $result['top_customers'][0]['avg'], 0.001);
    }

    public function testAvgOrderValueMultipleOrders(): void
    {
        $orders = [
            [
                'email'        => 'multi@example.com',
                'total_price'  => '100.00',
                'created_at'   => '2024-03-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'multi@example.com',
                'total_price'  => '200.00',
                'created_at'   => '2024-03-15T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        // avg = 300 / 2 = 150
        $this->assertEqualsWithDelta(150.0, $result['top_customers'][0]['avg'], 0.001);
    }

    // ── first_date / last_date ────────────────────────────────────────────────

    public function testFirstDateAndLastDateSet(): void
    {
        $orders = [
            [
                'email'        => 'dates@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-04-10T08:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'dates@example.com',
                'total_price'  => '60.00',
                'created_at'   => '2024-04-20T08:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $c = $result['top_customers'][0];
        $this->assertSame('2024-04-10', $c['first_date']);
        $this->assertSame('2024-04-20', $c['last_date']);
    }

    // ── Cohort grouping by month of first order ───────────────────────────────

    public function testCohortGroupsByMonth(): void
    {
        $orders = [
            [
                'email'        => 'jan@example.com',
                'total_price'  => '100.00',
                'created_at'   => '2024-01-15T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'feb@example.com',
                'total_price'  => '200.00',
                'created_at'   => '2024-02-10T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $months = array_column($result['cohort'], 'month');
        $this->assertContains('2024-01', $months);
        $this->assertContains('2024-02', $months);
        $this->assertCount(2, $result['cohort']);
    }

    public function testCohortNewCountCorrect(): void
    {
        $orders = [
            [
                'email'        => 'c1@example.com',
                'total_price'  => '10.00',
                'created_at'   => '2024-03-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'c2@example.com',
                'total_price'  => '20.00',
                'created_at'   => '2024-03-15T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'c3@example.com',
                'total_price'  => '30.00',
                'created_at'   => '2024-04-01T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $cohortByMonth = array_column($result['cohort'], null, 'month');
        $this->assertSame(2, $cohortByMonth['2024-03']['new']);
        $this->assertSame(1, $cohortByMonth['2024-04']['new']);
    }

    public function testCohortSortedAscendingByMonth(): void
    {
        $orders = [
            [
                'email'        => 'z@example.com',
                'total_price'  => '10.00',
                'created_at'   => '2024-06-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'a@example.com',
                'total_price'  => '20.00',
                'created_at'   => '2024-01-01T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $months = array_column($result['cohort'], 'month');
        $this->assertSame(['2024-01', '2024-06'], $months);
    }

    // ── retention_rate ────────────────────────────────────────────────────────

    public function testRetentionRateZeroWhenNoRepeats(): void
    {
        $orders = [
            [
                'email'        => 'once@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-05-01T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $this->assertEqualsWithDelta(0.0, $result['cohort'][0]['retention_rate'], 0.001);
    }

    public function testRetentionRateCorrectWithRepeats(): void
    {
        // 2 customers in same cohort; 1 has repeat orders
        $orders = [
            // customer 1: 2 orders → repeat buyer
            [
                'email'        => 'repeat@example.com',
                'total_price'  => '100.00',
                'created_at'   => '2024-05-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'repeat@example.com',
                'total_price'  => '100.00',
                'created_at'   => '2024-05-20T10:00:00Z',
                'cancelled_at' => null,
            ],
            // customer 2: 1 order → not a repeat buyer
            [
                'email'        => 'once2@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-05-10T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        // 1 repeat / 2 new = 50.0%
        $this->assertEqualsWithDelta(50.0, $result['cohort'][0]['retention_rate'], 0.001);
    }

    public function testRetentionRateHundredPercentWhenAllRepeat(): void
    {
        $orders = [
            [
                'email'        => 'r1@example.com',
                'total_price'  => '10.00',
                'created_at'   => '2024-07-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'r1@example.com',
                'total_price'  => '20.00',
                'created_at'   => '2024-07-15T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'r2@example.com',
                'total_price'  => '30.00',
                'created_at'   => '2024-07-05T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'r2@example.com',
                'total_price'  => '40.00',
                'created_at'   => '2024-07-20T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $this->assertEqualsWithDelta(100.0, $result['cohort'][0]['retention_rate'], 0.001);
    }

    // ── avg_orders / avg_revenue in cohort ────────────────────────────────────

    public function testCohortAvgOrdersCorrect(): void
    {
        // 2 customers; one with 2 orders, one with 1 → avg = 3/2 = 1.5
        $orders = [
            [
                'email'        => 'x1@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-08-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'x1@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-08-10T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'x2@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-08-05T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $this->assertEqualsWithDelta(1.5, $result['cohort'][0]['avg_orders'], 0.001);
    }

    public function testCohortAvgRevenueCorrect(): void
    {
        // 2 customers; combined revenue 150; avg = 75
        $orders = [
            [
                'email'        => 'y1@example.com',
                'total_price'  => '100.00',
                'created_at'   => '2024-09-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'y2@example.com',
                'total_price'  => '50.00',
                'created_at'   => '2024-09-05T10:00:00Z',
                'cancelled_at' => null,
            ],
        ];
        $result = $this->build($orders);
        $this->assertEqualsWithDelta(75.0, $result['cohort'][0]['avg_revenue'], 0.001);
    }

    // ── total_revenue ─────────────────────────────────────────────────────────

    public function testTotalRevenueExcludesCancelled(): void
    {
        $orders = [
            [
                'email'        => 'rev@example.com',
                'total_price'  => '300.00',
                'created_at'   => '2024-10-01T10:00:00Z',
                'cancelled_at' => null,
            ],
            [
                'email'        => 'rev@example.com',
                'total_price'  => '999.00',
                'created_at'   => '2024-10-05T10:00:00Z',
                'cancelled_at' => '2024-10-06T10:00:00Z',
            ],
        ];
        $result = $this->build($orders);
        $this->assertEqualsWithDelta(300.0, $result['total_revenue'], 0.001);
    }

    // ── start / end passthrough ───────────────────────────────────────────────

    public function testStartEndPassthrough(): void
    {
        $result = $this->build([], '2024-06-01', '2024-06-30');
        $this->assertSame('2024-06-01', $result['start']);
        $this->assertSame('2024-06-30', $result['end']);
    }
}
