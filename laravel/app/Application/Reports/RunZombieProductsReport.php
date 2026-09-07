<?php

namespace App\Application\Reports;

use App\Domain\Reports\ZombieProductsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunZombieProductsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ZombieProductsAnalyzer $analyzer) {}

    public function handle(Store $store): ZombieProductsResult
    {
        $result = $this->shopify->zombieProductsCandidates($store);

        return new ZombieProductsResult(count($result['products']), $this->analyzer->analyze($result['products']), $result['pages'], $result['truncated']);
    }
}
