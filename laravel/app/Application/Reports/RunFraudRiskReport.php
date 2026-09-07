<?php

namespace App\Application\Reports;

use App\Domain\Reports\FraudRiskAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunFraudRiskReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly FraudRiskAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): FraudRiskResult
    {
        $result = $this->shopify->fraudRiskCandidates($store, $start, $end);

        return new FraudRiskResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders']), $result['pages'], $result['truncated']);
    }
}
