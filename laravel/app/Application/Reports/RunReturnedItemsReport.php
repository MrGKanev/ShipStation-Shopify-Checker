<?php

namespace App\Application\Reports;

use App\Domain\Reports\ReturnedItemsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunReturnedItemsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ReturnedItemsAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): ReturnedItemsResult
    {
        $candidates = $this->shopify->returnedItemCandidates($store, $startDate);

        return new ReturnedItemsResult($startDate, $endDate, count($candidates['orders']), $this->analyzer->analyze($candidates['orders'], $startDate, $endDate), $candidates['pages'], $candidates['truncated']);
    }
}
