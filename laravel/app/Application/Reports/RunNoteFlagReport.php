<?php

namespace App\Application\Reports;

use App\Domain\Reports\NoteFlagAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunNoteFlagReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly NoteFlagAnalyzer $analyzer) {}

    /** @param list<string> $keywords */
    public function handle(Store $store, string $start, string $end, array $keywords): NoteFlagResult
    {
        $result = $this->shopify->noteFlagCandidates($store, $start, $end);

        return new NoteFlagResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders'], $keywords), $keywords, $result['pages'], $result['truncated']);
    }
}
