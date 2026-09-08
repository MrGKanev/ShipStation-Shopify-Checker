<?php

namespace App\Application\Reports;

use App\Domain\Reports\TagPolicyAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunTagPolicyReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly TagPolicyAnalyzer $analyzer) {}

    /** @param array<string, mixed> $config */
    public function handle(Store $store, string $start, string $end, array $config): TagPolicyResult
    {
        $result = $this->shopify->tagPolicyCandidates($store, $start, $end);

        return new TagPolicyResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders'], $config), $result['pages'], $result['truncated']);
    }
}
