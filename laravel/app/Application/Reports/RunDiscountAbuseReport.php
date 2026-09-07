<?php

namespace App\Application\Reports;

use App\Domain\Reports\DiscountAbuseAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunDiscountAbuseReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly DiscountAbuseAnalyzer $analyzer) {}

    public function handle(Store $store, string $start, string $end, int $minimumEmails): DiscountAbuseResult
    {
        $result = $this->shopify->discountAbuseCandidates($store, $start, $end);

        return new DiscountAbuseResult($start, $end, count($result['orders']), $minimumEmails, $this->analyzer->analyze($result['orders'], $minimumEmails), $result['pages'], $result['truncated']);
    }
}
