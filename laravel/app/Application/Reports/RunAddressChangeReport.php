<?php

namespace App\Application\Reports;

use App\Domain\Reports\AddressChangeAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunAddressChangeReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly AddressChangeAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): AddressChangeResult
    {
        $data = $this->shopify->addressChangeCandidates($store, $start, $end);
        $changes = $this->analyzer->latestChanges($data['events']);

        return new AddressChangeResult($start, $end, $this->analyzer->rows($data['orders'], $changes), $data['pages'], $data['truncated']);
    }
}
