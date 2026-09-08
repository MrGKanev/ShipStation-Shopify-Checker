<?php

namespace App\Application\Reports;

use App\Domain\Reports\OrderEditAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunOrderEditReport
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly OrderEditAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): OrderEditResult
    {
        $data = $this->shopify->orderEditCandidates($store, $start, $end);

        return new OrderEditResult($start, $end, $this->analyzer->rows($data['orders'], $this->analyzer->group($data['events'])), $data['pages'], $data['truncated']);
    }
}
