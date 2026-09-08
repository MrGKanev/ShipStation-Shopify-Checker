<?php

namespace App\Application\Reports;

use App\Domain\Reports\RepeatRefundAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunRepeatRefundReport
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly RepeatRefundAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end, int $minimum): RepeatRefundResult
    {
        $result = $this->shopify->repeatRefundCandidates($store, $start, $end);

        return new RepeatRefundResult($start, $end, count($result['orders']), $minimum, $this->analyzer->analyze($result['orders'], $minimum), $result['pages'], $result['truncated']);
    }
}
