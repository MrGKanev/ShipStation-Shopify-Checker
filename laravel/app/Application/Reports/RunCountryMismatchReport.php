<?php

namespace App\Application\Reports;

use App\Domain\Reports\CountryMismatchAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunCountryMismatchReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly CountryMismatchAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate): CountryMismatchResult
    {
        $result = $this->shopify->countryMismatchCandidates($store, $startDate, $endDate);
        $analysis = $this->analyzer->analyze($result['orders']);

        return new CountryMismatchResult($startDate, $endDate, count($result['orders']), $analysis['skipped_missing_country'], $analysis['rows'], $result['pages'], $result['truncated']);
    }
}
