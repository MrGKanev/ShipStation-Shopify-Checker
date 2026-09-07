<?php

namespace Tests\Feature\Integrations\ShipStation;

use App\Integrations\ShipStation\ShipStationClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use UnexpectedValueException;

class ShipStationClientTest extends TestCase
{
    public function test_order_lookup_returns_raw_orders_with_basic_auth_and_expected_query(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [
                    ['orderId' => 41, 'orderNumber' => '1001'],
                ],
                'pages' => 1,
            ]),
        ]);

        $orders = $this->client()->findByOrderNumber('1001');

        $this->assertSame([
            ['orderId' => 41, 'orderNumber' => '1001'],
        ], $orders);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/orders'
                && $query === ['orderNumber' => '1001', 'pageSize' => '50']
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('api-key:api-secret'))
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_order_shipments_lookup_returns_raw_shipments_with_expected_query(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => [
                    ['shipmentId' => 72, 'trackingNumber' => '1Z999'],
                ],
            ]),
        ]);

        $shipments = $this->client()->getOrderShipments('1001');

        $this->assertSame([
            ['shipmentId' => 72, 'trackingNumber' => '1Z999'],
        ], $shipments);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_PATH) === '/shipments'
                && $query === ['orderNumber' => '1001', 'pageSize' => '100'];
        });
    }

    public function test_date_range_fetch_returns_orders_from_every_page_with_expected_filters(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->push(['orders' => [['orderId' => 1]], 'pages' => 2])
                ->push(['orders' => [['orderId' => 2]], 'pages' => 2]),
        ]);

        $orders = $this->client()->fetchAllOrders('2026-08-01', '2026-08-02');

        $this->assertSame([['orderId' => 1], ['orderId' => 2]], $orders);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === [
                'createDateStart' => '2026-08-01 00:00:00',
                'createDateEnd' => '2026-08-02 23:59:59',
                'sortBy' => 'OrderDate',
                'sortDir' => 'ASC',
                'pageSize' => '500',
                'page' => '1',
            ];
        });
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['page'] === '2';
        });
    }

    public function test_awaiting_fetch_returns_every_page_with_expected_status_filter(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->push(['orders' => [['orderId' => 1]], 'pages' => 2])
                ->push(['orders' => [['orderId' => 2]], 'pages' => 2]),
        ]);

        $this->assertSame([['orderId' => 1], ['orderId' => 2]], $this->client()->fetchAwaitingOrders());
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === [
                'orderStatus' => 'awaiting_shipment',
                'sortBy' => 'OrderDate',
                'sortDir' => 'ASC',
                'pageSize' => '500',
                'page' => '1',
            ];
        });
    }

    public function test_401_response_throws_without_retrying(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'message' => 'Unauthorized',
            ], 401),
        ]);

        try {
            $this->client()->findByOrderNumber('1001');
            $this->fail('Expected the ShipStation request to fail.');
        } catch (RequestException $exception) {
            $this->assertSame(401, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    public function test_429_response_is_retried_and_then_returns_orders(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->push(['message' => 'Too Many Requests'], 429)
                ->push(['orders' => [['orderId' => 91]], 'pages' => 1]),
        ]);
        Sleep::fake();

        $orders = $this->client()->findByOrderNumber('1001');

        $this->assertSame([['orderId' => 91]], $orders);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_500_response_is_retried_and_then_returns_orders(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->push(['message' => 'Unavailable'], 500)
                ->push(['orders' => [['orderId' => 92]], 'pages' => 1]),
        ]);
        Sleep::fake();

        $orders = $this->client()->findByOrderNumber('1001');

        $this->assertSame([['orderId' => 92]], $orders);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_connection_failure_is_retried_and_then_returns_orders(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->pushFailedConnection()
                ->push(['orders' => [['orderId' => 93]], 'pages' => 1]),
        ]);
        Sleep::fake();

        $orders = $this->client()->findByOrderNumber('1001');

        $this->assertSame([['orderId' => 93]], $orders);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_transient_failures_stop_after_three_retries(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::sequence()
                ->push(['message' => 'Unavailable'], 503)
                ->push(['message' => 'Unavailable'], 503)
                ->push(['message' => 'Unavailable'], 503)
                ->push(['message' => 'Unavailable'], 503),
        ]);
        Sleep::fake();

        try {
            $this->client()->findByOrderNumber('1001');
            $this->fail('Expected the ShipStation request to fail.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
    }

    public function test_non_json_payload_throws_an_explicit_exception(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::response('not-json'),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('ShipStation returned an invalid JSON payload.');

        $this->client()->findByOrderNumber('1001');
    }

    public function test_invalid_orders_collection_throws_an_explicit_exception(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => 'invalid',
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('ShipStation returned an invalid orders collection.');

        $this->client()->findByOrderNumber('1001');
    }

    public function test_invalid_shipments_collection_throws_an_explicit_exception(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => false,
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('ShipStation returned an invalid shipments collection.');

        $this->client()->getOrderShipments('1001');
    }

    public function test_malformed_order_element_throws_instead_of_returning_partial_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::response([
                'orders' => [
                    ['orderId' => 1],
                    'invalid',
                ],
            ]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('ShipStation returned an invalid orders collection.');

        $this->client()->findByOrderNumber('1001');
    }

    public function test_missing_orders_collection_returns_an_empty_result(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/orders*' => Http::response([]),
        ]);

        $orders = $this->client()->findByOrderNumber('1001');

        $this->assertSame([], $orders);
        Http::assertSentCount(1);
    }

    public function test_empty_shipments_collection_returns_an_empty_result(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ssapi.shipstation.com/shipments*' => Http::response([
                'shipments' => [],
            ]),
        ]);

        $shipments = $this->client()->getOrderShipments('1001');

        $this->assertSame([], $shipments);
        Http::assertSentCount(1);
    }

    private function client(): ShipStationClient
    {
        return new ShipStationClient('api-key', 'api-secret');
    }
}
