<?php

namespace App\Application\Reports;

use App\Domain\Reports\SkuDuplicatesAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunSkuDuplicatesReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly SkuDuplicatesAnalyzer $analyzer) {}

    public function handle(Store $store): SkuDuplicatesResult
    {
        $catalogue = $this->shopify->skuDuplicatesCandidates($store);
        $result = $this->analyzer->analyze($catalogue['products']);

        return new SkuDuplicatesResult(count($catalogue['products']), $result['rows'], $result['totalVariants'], $catalogue['pages'], $catalogue['truncated']);
    }
}
