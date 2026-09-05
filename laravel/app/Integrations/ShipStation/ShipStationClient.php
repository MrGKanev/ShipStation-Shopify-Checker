<?php

namespace App\Integrations\ShipStation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

class ShipStationClient implements ShipStationClientContract
{
    private const string BASE_URL = 'https://ssapi.shipstation.com';

    private const int PAGE_SIZE = 500;

    private const array RETRY_DELAYS_IN_MILLISECONDS = [100, 500, 1000];

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        #[SensitiveParameter] private readonly string $apiSecret,
    ) {}

    public function findByOrderNumber(string $orderNumber): array
    {
        $payload = $this->get('/orders', [
            'orderNumber' => $orderNumber,
            'pageSize' => 50,
        ]);

        return $this->items($payload, 'orders');
    }

    public function getOrderShipments(string $orderNumber): array
    {
        $payload = $this->get('/shipments', [
            'orderNumber' => $orderNumber,
            'pageSize' => 100,
        ]);

        return $this->items($payload, 'shipments');
    }

    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        $filters = [
            'createDateStart' => $startDate.' 00:00:00',
            'createDateEnd' => $endDate.' 23:59:59',
            'sortBy' => 'OrderDate',
            'sortDir' => 'ASC',
        ];

        return $this->paginate('/orders', $filters, 'orders');
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $response = $this->request()
            ->retry(
                self::RETRY_DELAYS_IN_MILLISECONDS,
                when: fn (Throwable $exception, PendingRequest $request, ?string $method): bool => $method === 'GET' && $this->isTransientFailure($exception),
            )
            ->get($path, $query)
            ->throw();

        return $this->decode($response);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($this->apiKey, $this->apiSecret)
            ->connectTimeout(3)
            ->timeout(10);
    }

    private function isTransientFailure(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        return $exception->response->status() === 429
            || $exception->response->serverError();
    }

    /**
     * @param  array<string, scalar>  $filters
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $filters, string $itemsKey): array
    {
        $items = [];
        $page = 1;

        do {
            $payload = $this->get($path, [
                ...$filters,
                'pageSize' => self::PAGE_SIZE,
                'page' => $page,
            ]);
            $pageItems = $this->items($payload, $itemsKey);
            array_push($items, ...$pageItems);

            $totalPages = max(1, (int) ($payload['pages'] ?? 1));
            $page++;
        } while ($page <= $totalPages);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new UnexpectedValueException('ShipStation returned an invalid JSON payload.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload, string $key): array
    {
        $items = $payload[$key] ?? [];

        if (! is_array($items)) {
            throw new UnexpectedValueException("ShipStation returned an invalid {$key} collection.");
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException("ShipStation returned an invalid {$key} collection.");
            }
        }

        return array_values($items);
    }
}
