<?php

namespace App\Application\Reports;

use App\Domain\Reports\SameIpAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunSameIpReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly SameIpAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): SameIpResult
    {
        $result = $this->shopify->sameIpCandidates($store, $start, $end);

        return new SameIpResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders']), $result['pages'], $result['truncated']);
    }
}
