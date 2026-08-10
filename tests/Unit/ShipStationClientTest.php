<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShipStationClientTest extends TestCase
{
    private function makeStack(array $responses, array &$history = []): HandlerStack
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return $stack;
    }

    private function ss(array $responses, array &$history = []): ShipStation
    {
        return new ShipStation('key', 'secret', null, $this->makeStack($responses, $history));
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    // ── Auth header ───────────────────────────────────────────────────────────

    public function testSendsBasicAuthHeader(): void
    {
        $history = [];
        $ss      = $this->ss([$this->json(['orders' => [], 'pages' => 1])], $history);

        $ss->findByOrderNumber('1001');

        $auth = $history[0]['request']->getHeaderLine('Authorization');
        $this->assertStringStartsWith('Basic ', $auth);
        $this->assertSame(base64_encode('key:secret'), substr($auth, 6));
    }

    // ── findByOrderNumber ─────────────────────────────────────────────────────

    public function testFindByOrderNumberReturnsOrders(): void
    {
        $orders = [['orderId' => 1, 'orderNumber' => '1001']];
        $ss     = $this->ss([$this->json(['orders' => $orders, 'pages' => 1])]);

        $result = $ss->findByOrderNumber('1001');

        $this->assertSame($orders, $result);
    }

    public function testFindByOrderNumberPassesQueryParam(): void
    {
        $history = [];
        $ss      = $this->ss([$this->json(['orders' => [], 'pages' => 1])], $history);

        $ss->findByOrderNumber('5555');

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('orderNumber=5555', $uri);
    }

    // ── 429 retry ─────────────────────────────────────────────────────────────

    public function testRetryOn429(): void
    {
        $orders = [['orderId' => 1, 'orderNumber' => '1001']];
        $mock   = new MockHandler([
            new Response(429, ['Retry-After' => '0']),
            $this->json(['orders' => $orders, 'pages' => 1]),
        ]);
        $ss = new ShipStation('key', 'secret', null, HandlerStack::create($mock));

        $result = $ss->findByOrderNumber('1001');

        $this->assertSame(0, $mock->count()); // both responses consumed: 429 then 200
        $this->assertSame($orders, $result);
    }

    public function testStopsRetryingAfterFiveAttempts(): void
    {
        // 6 responses: original + 5 retries, all 429 - 6th passes through and throws
        $mock = new MockHandler(array_fill(0, 6, new Response(429, ['Retry-After' => '0'], '')));
        $ss   = new ShipStation('key', 'secret', null, HandlerStack::create($mock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        $ss->findByOrderNumber('1001');
    }

    // ── createOrder / buildPayload ────────────────────────────────────────────

    public function testCreateOrderPostsCorrectPayload(): void
    {
        $history = [];
        $ss      = $this->ss([$this->json(['orderId' => 99])], $history);

        $shopifyOrder = [
            'order_number'    => 1001,
            'created_at'      => '2024-01-15T10:00:00Z',
            'email'           => 'test@example.com',
            'total_price'     => '99.00',
            'total_tax'       => '8.00',
            'shipping_lines'  => [['price' => '5.00']],
            'line_items'      => [['id' => 1, 'title' => 'Widget', 'sku' => 'WGT-1', 'quantity' => 2, 'price' => '47.00']],
            'billing_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '1 Main St', 'city' => 'NY', 'zip' => '10001', 'country_code' => 'US'],
        ];

        $ss->createOrder($shopifyOrder);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('1001', $body['orderNumber']);
        $this->assertSame('test@example.com', $body['customerEmail']);
        $this->assertEqualsWithDelta(99.0, $body['amountPaid'], 0.001);
        $this->assertEqualsWithDelta(5.0, $body['shippingAmount'], 0.001);
        $this->assertCount(1, $body['items']);
        $this->assertSame('WGT-1', $body['items'][0]['sku']);
    }

    public function testBuildPayloadShippingFallsBackToBilling(): void
    {
        $ss = $this->ss([]);

        $shopifyOrder = [
            'order_number'    => 1002,
            'billing_address' => ['first_name' => 'John', 'last_name' => 'Doe', 'city' => 'LA', 'country_code' => 'US'],
        ];

        $payload = $ss->buildPayload($shopifyOrder);

        $this->assertSame('LA', $payload['shipTo']['city']);
    }

    // ── error handling ────────────────────────────────────────────────────────

    public function testThrowsOnApiError(): void
    {
        $ss = $this->ss([$this->json(['message' => 'Unauthorized'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/401/');

        $ss->findByOrderNumber('1001');
    }

    public function testThrowsOnNonJsonResponse(): void
    {
        $ss = $this->ss([new Response(200, [], 'not json')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-JSON/');

        $ss->findByOrderNumber('1001');
    }

    public function testFetchActiveOrdersRequestsAllActiveStatuses(): void
    {
        $history = [];
        $ss = $this->ss([
            $this->json(['orders' => [['orderId' => 1]], 'pages' => 1]),
            $this->json(['orders' => [['orderId' => 2]], 'pages' => 1]),
            $this->json(['orders' => [['orderId' => 3]], 'pages' => 1]),
        ], $history);

        $orders = $ss->fetchActiveOrders();

        $this->assertCount(3, $orders);
        $uris = array_map(fn($h) => urldecode((string) $h['request']->getUri()), $history);
        $this->assertStringContainsString('orderStatus=awaiting_payment', $uris[0]);
        $this->assertStringContainsString('orderStatus=awaiting_shipment', $uris[1]);
        $this->assertStringContainsString('orderStatus=on_hold', $uris[2]);
    }

    // ── fetchVoidedShipments ─────────────────────────────────────────────────

    public function testFetchVoidedShipmentsPassesVoidDateRangeParams(): void
    {
        $history = [];
        $ss = $this->ss([$this->json(['shipments' => [], 'total' => 0])], $history);

        $ss->fetchVoidedShipments('2026-06-01', '2026-06-20');

        $uri = urldecode((string) $history[0]['request']->getUri());
        $this->assertStringContainsString('voidDate_start=2026-06-01 00:00:00', $uri);
        $this->assertStringContainsString('voidDate_end=2026-06-20 23:59:59', $uri);
    }

    public function testFetchVoidedShipmentsReturnsEmptyArrayWhenNoneVoided(): void
    {
        $ss = $this->ss([$this->json(['shipments' => [], 'total' => 0])]);

        $result = $ss->fetchVoidedShipments('2026-06-01', '2026-06-20');

        $this->assertSame([], $result);
    }

    public function testFetchVoidedShipmentsPaginatesUntilTotalReached(): void
    {
        $history = [];
        $ss = $this->ss([
            $this->json(['shipments' => [['shipmentId' => 1]], 'total' => 2]),
            $this->json(['shipments' => [['shipmentId' => 2]], 'total' => 2]),
        ], $history);

        $result = $ss->fetchVoidedShipments('2026-06-01', '2026-06-20');

        $this->assertSame([['shipmentId' => 1], ['shipmentId' => 2]], $result);
        $this->assertCount(2, $history);
        $this->assertStringContainsString('page=1', urldecode((string) $history[0]['request']->getUri()));
        $this->assertStringContainsString('page=2', urldecode((string) $history[1]['request']->getUri()));
    }

    public function testFetchAllOrdersWritesCheckpointUnderProvidedCacheDir(): void
    {
        $tmpDir = sys_get_temp_dir() . '/ss_checkpoint_' . uniqid();
        $cache = new Cache($tmpDir, ttl: 60);
        $history = [];
        $ss = new ShipStation('key', 'secret', $cache, $this->makeStack([
            $this->json(['orders' => [['orderId' => 1]], 'pages' => 2]),
            $this->json(['orders' => [['orderId' => 2]], 'pages' => 2]),
        ], $history));

        try {
            $orders = $ss->fetchAllOrders('2026-01-01', '2026-01-02');
            $dir = $cache->checkpointDir('ss', '2026-01-01|2026-01-02');

            $this->assertSame([['orderId' => 1], ['orderId' => 2]], $orders);
            $this->assertTrue(file_exists($dir . '/page_1.json'));
            $this->assertTrue(file_exists($dir . '/page_2.json'));
            $this->assertTrue(file_exists($dir . '/_meta.json'));
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testExpiredFetchAllOrdersCheckpointRestartsFromFirstPage(): void
    {
        $tmpDir = sys_get_temp_dir() . '/ss_checkpoint_' . uniqid();
        $cache = new Cache($tmpDir, ttl: 60);
        $dir = $cache->checkpointDir('ss', '2026-02-01|2026-02-02');
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/page_1.json', json_encode([['orderId' => 'old']]));
        file_put_contents($dir . '/_meta.json', json_encode(['expires_at' => time() - 10]));

        $history = [];
        $ss = new ShipStation('key', 'secret', $cache, $this->makeStack([
            $this->json(['orders' => [['orderId' => 'fresh']], 'pages' => 1]),
        ], $history));

        try {
            $orders = $ss->fetchAllOrders('2026-02-01', '2026-02-02');
            $uri = urldecode((string) $history[0]['request']->getUri());

            $this->assertSame([['orderId' => 'fresh']], $orders);
            $this->assertStringContainsString('page=1', $uri);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testExpiredFetchAllOrdersCheckpointRethrowsOnRefreshFailureInsteadOfSilentlyServingStaleData(): void
    {
        $tmpDir = sys_get_temp_dir() . '/ss_checkpoint_' . uniqid();
        $cache = new Cache($tmpDir, ttl: 60);
        $dir = $cache->checkpointDir('ss', '2026-03-01|2026-03-02');
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/page_1.json', json_encode([['orderId' => 'stale']]));
        file_put_contents($dir . '/_meta.json', json_encode(['expires_at' => time() - 10]));

        $ss = new ShipStation('key', 'secret', $cache, $this->makeStack([
            $this->json(['message' => 'temporary outage'], 500),
        ]));

        try {
            $threw = false;
            try {
                $ss->fetchAllOrders('2026-03-01', '2026-03-02');
            } catch (RuntimeException) {
                $threw = true;
            }

            $this->assertTrue($threw, 'refresh failure must propagate instead of silently returning stale data');
            $this->assertTrue(file_exists($dir . '/page_1.json'), 'stale checkpoint must remain on disk so the next run can retry');
            $this->assertSame([], glob(dirname($dir) . '/.' . basename($dir) . '.tmp_*') ?: [], 'tmp refresh dir must still be cleaned up');
        } finally {
            $this->removeDir($tmpDir);
        }
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
