<?php

namespace App\Application\Health;

use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Throwable;

class CheckApiHealth
{
    private const array REQUIRED_SHOPIFY_SCOPES = ['read_orders', 'read_fulfillments'];

    public function __construct(
        private readonly ShopifyAdminGateway $shopify,
        private readonly ShipStationClientFactory $shipStationFactory,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Store $store): array
    {
        return [
            'checked_at' => now()->toDateTimeString(),
            'shopify' => $this->checkShopify($store),
            'shipstation' => $this->checkShipStation($store),
        ];
    }

    /** @return array<string, mixed> */
    private function checkShopify(Store $store): array
    {
        if (trim((string) $store->shopify_store) === '' || trim((string) $store->shopify_access_token) === '') {
            return ['ok' => false, 'configured' => false, 'error' => 'Shopify credentials are incomplete.', 'latency_ms' => null, 'shop_name' => '', 'requested_version' => '', 'scopes' => [], 'missing_scopes' => []];
        }

        $startedAt = hrtime(true);
        try {
            $result = $this->shopify->healthCheck($store);
            $missingScopes = array_values(array_diff(self::REQUIRED_SHOPIFY_SCOPES, $result['scopes']));

            return [...$result, 'ok' => $missingScopes === [], 'configured' => true, 'error' => $missingScopes === [] ? '' : 'Required Shopify scopes are missing.', 'latency_ms' => $this->elapsedMilliseconds($startedAt), 'missing_scopes' => $missingScopes];
        } catch (Throwable) {
            return ['ok' => false, 'configured' => true, 'error' => 'Shopify could not be reached or rejected the request.', 'latency_ms' => $this->elapsedMilliseconds($startedAt), 'shop_name' => '', 'requested_version' => '', 'scopes' => [], 'missing_scopes' => []];
        }
    }

    /** @return array<string, mixed> */
    private function checkShipStation(Store $store): array
    {
        if (trim((string) $store->shipstation_api_key) === '' || trim((string) $store->shipstation_api_secret) === '') {
            return ['ok' => false, 'configured' => false, 'error' => 'ShipStation credentials are incomplete.', 'latency_ms' => null];
        }

        $startedAt = hrtime(true);
        try {
            $this->shipStationFactory->forStore($store)?->healthCheck();

            return ['ok' => true, 'configured' => true, 'error' => '', 'latency_ms' => $this->elapsedMilliseconds($startedAt)];
        } catch (Throwable) {
            return ['ok' => false, 'configured' => true, 'error' => 'ShipStation could not be reached or rejected the request.', 'latency_ms' => $this->elapsedMilliseconds($startedAt)];
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
