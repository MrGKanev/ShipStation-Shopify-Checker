<?php

namespace App\Application\Reports;

use App\Domain\Reports\TagUsageAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunTagAuditReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly TagUsageAnalyzer $analyzer) {}

    public function handle(Store $store, string $startDate, string $endDate, string $orphanCutoff): TagAuditResult
    {
        $result = $this->shopify->tagAuditCandidates($store, $startDate, $endDate);

        return new TagAuditResult($startDate, $endDate, count($result['orders']), $this->analyzer->analyze($result['orders'], $orphanCutoff), $result['pages'], $result['truncated']);
    }
}
