<?php

namespace App\Application\Reports;

use App\Domain\Reports\TaxAuditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunTaxAuditReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly TaxAuditAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end, float $minimum): TaxAuditResult
    {
        $result = $this->shopify->taxAuditCandidates($store, $start, $end);

        return new TaxAuditResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders'], $minimum), $result['pages'], $result['truncated']);
    }
}
