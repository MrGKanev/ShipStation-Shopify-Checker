<?php

namespace App\Application\Reports;

use App\Domain\Reports\DuplicateAddressAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDuplicateAddressReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DuplicateAddressAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): DuplicateAddressResult
    {
        $result = $this->shopify->addressCheckCandidates($store, $start, $end, false);

        return new DuplicateAddressResult($start, $end, count($result['orders']), $this->analyzer->analyze($result['orders']), $result['pages'], $result['truncated']);
    }
}
