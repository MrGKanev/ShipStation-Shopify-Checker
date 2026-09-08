<?php

namespace App\Application\Reports;

use App\Domain\Reports\ConsentAuditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunConsentAuditReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ConsentAuditAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): ConsentAuditResult
    {
        $result = $this->shopify->consentAuditCandidates($store, $start, $end);

        return new ConsentAuditResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders']), $result['pages'], $result['truncated']);
    }
}
