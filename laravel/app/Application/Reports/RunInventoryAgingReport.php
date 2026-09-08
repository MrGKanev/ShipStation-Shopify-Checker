<?php

namespace App\Application\Reports;

use App\Domain\Reports\InventoryAgingAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunInventoryAgingReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly InventoryAgingAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): InventoryAgingResult
    {
        $candidates = $this->shopify->inventoryAgingCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($candidates['products'], $candidates['orders']);

        return new InventoryAgingResult($startDate, $endDate, count($candidates['products']), $analysis['variants'], count($candidates['orders']), $analysis['rows'], $candidates['product_pages'], $candidates['order_pages'], $candidates['products_truncated'], $candidates['orders_truncated']);
    }
}
