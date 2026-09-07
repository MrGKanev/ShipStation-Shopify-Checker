<?php

namespace App\Application\Reports;

use App\Domain\Reports\EmailCheckAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunEmailCheckReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly EmailCheckAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end): EmailCheckResult
    {
        $result = $this->shopify->emailCheckCandidates($store, $start, $end);
        $rows = $this->analyzer->analyze($result['orders']);

        return new EmailCheckResult($start, $end, count($result['orders']), $rows, count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'critical')), count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'warning')), $result['pages'], $result['truncated']);
    }
}
