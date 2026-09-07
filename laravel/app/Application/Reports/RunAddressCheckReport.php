<?php

namespace App\Application\Reports;

use App\Domain\Reports\AddressCheckAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunAddressCheckReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly AddressCheckAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end, bool $poBoxOnly, bool $unfulfilledOnly): AddressCheckResult
    {
        $result = $this->shopify->addressCheckCandidates($store, $start, $end, $unfulfilledOnly);
        $rows = $this->analyzer->analyze($result['orders'], $poBoxOnly);

        return new AddressCheckResult($start, $end, count($result['orders']), $rows, count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'critical')), count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'warning')), $poBoxOnly, $unfulfilledOnly, $result['pages'], $result['truncated']);
    }
}
