<?php

namespace App\Application\Reports;

use App\Domain\Reports\InventoryForecastAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunInventoryForecastReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly InventoryForecastAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): InventoryForecastResult
    {
        $candidates = $this->shopify->inventoryForecastCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($candidates['products'], $candidates['orders']);

        return new InventoryForecastResult($startDate, $endDate, count($candidates['products']), $analysis['variants'], count($candidates['orders']), $analysis['rows'], $analysis['critical'], $analysis['warning'], $candidates['product_pages'], $candidates['order_pages'], $candidates['products_truncated'], $candidates['orders_truncated']);
    }
}
