<?php

namespace App\Application\Reports;

use App\Domain\Reports\CatalogQualityAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunCatalogQualityReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly CatalogQualityAnalyzer $analyzer) {}

    public function handle(Store $store): CatalogQualityResult
    {
        $result = $this->shopify->catalogQualityCandidates($store);

        return new CatalogQualityResult(count($result['products']), $this->analyzer->analyze($result['products']), $result['pages'], $result['truncated']);
    }
}
