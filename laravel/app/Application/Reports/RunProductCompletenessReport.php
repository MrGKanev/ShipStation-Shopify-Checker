<?php

namespace App\Application\Reports;

use App\Domain\Reports\ProductCompletenessAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunProductCompletenessReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly ProductCompletenessAnalyzer $analyzer) {}

    public function handle(Store $store): ProductCompletenessResult
    {
        $result = $this->shopify->productCompletenessCandidates($store);
        $rows = $this->analyzer->analyze($result['products']);

        return new ProductCompletenessResult(
            count($result['products']),
            $rows,
            count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'critical')),
            count(array_filter($rows, fn (array $row): bool => $row['severity'] === 'warning')),
            $result['pages'],
            $result['truncated'],
        );
    }
}
