<?php

namespace App\Application\Reports;

use App\Domain\Reports\HighValueNoPhoneAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunHighValueNoPhoneReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly HighValueNoPhoneAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate, float $minimum, string $currency): HighValueNoPhoneResult
    {
        $result = $this->shopify->highValueOrderCandidates($store, $startDate, $endDate);

        return new HighValueNoPhoneResult($startDate, $endDate, $minimum, $currency, count($result['orders']), $this->analyzer->analyze($result['orders'], $minimum, $currency), $result['pages'], $result['truncated']);
    }
}
