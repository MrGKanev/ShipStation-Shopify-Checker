<?php

namespace App\Integrations\Shopify\Contracts;

use App\Models\Store;

interface ShopifyAdminGateway
{
    /** @return array{shop_name: string, scopes: list<string>, requested_version: string} */
    public function healthCheck(Store $store): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderNumber(Store $store, string $orderNumber): array;

    /**
     * @param  list<string>  $orderNumbers
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function findByOrderNumbers(Store $store, array $orderNumbers): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderEvents(Store $store, string $orderId): array;

    /**
     * @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(Store $store, string $tag, ?string $startDate = null, ?string $endDate = null): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function highValueOrderCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function countryMismatchCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function taxAuditCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function tagAuditCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function productCompletenessCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function skuDuplicatesCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function inventoryOversellCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, orders: list<array<string, mixed>>, product_pages: int, order_pages: int, products_truncated: bool, orders_truncated: bool} */
    public function inventoryAgingCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{products: list<array<string, mixed>>, orders: list<array<string, mixed>>, product_pages: int, order_pages: int, products_truncated: bool, orders_truncated: bool} */
    public function inventoryForecastCandidates(Store $store, string $startDate, string $endDate): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function zombieProductsCandidates(Store $store): array;

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function catalogQualityCandidates(Store $store): array;

    /** @return array{gift_cards: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function giftCardCandidates(Store $store): array;

    /**
     * @param  array<string, bool|int|string>  $query
     * @return array<string, mixed>
     */
    public function get(Store $store, string $resource, array $query = []): array;

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(Store $store, string $query, array $variables = []): array;

    /**
     * @param  array<string, mixed>  $variables
     * @return array{edges: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function paginateGraphql(
        Store $store,
        string $query,
        string $rootKey,
        array $variables = [],
        int $maxPages = 20,
    ): array;
}
