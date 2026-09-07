<?php

namespace App\Application\Reports;

use App\Domain\Reports\DisputeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDisputeReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DisputeAnalyzer $analyzer) {}

    public function handle(Store $store, int $now): DisputeResult
    {
        $result = $this->shopify->openDisputes($store);

        return new DisputeResult(count($result['disputes']), $this->analyzer->analyze($result['disputes'], $now), $result['pages'], $result['truncated']);
    }
}
