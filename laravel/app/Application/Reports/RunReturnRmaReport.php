<?php

namespace App\Application\Reports;

use App\Domain\Reports\ReturnRmaAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunReturnRmaReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ReturnRmaAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): ReturnRmaResult
    {
        $candidates = $this->shopify->refundTrackerCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($candidates['orders']);

        return new ReturnRmaResult($startDate, $endDate, count($candidates['orders']), $analysis['rows'], $analysis['sku_stats'], $candidates['pages'], $candidates['truncated']);
    }
}
