<?php

namespace App\Application\Reports;

use App\Domain\Reports\GiftCardsAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class RunGiftCardsReport
{
    public function __construct(private readonly ShopifyAdminGateway $shopify, private readonly GiftCardsAnalyzer $analyzer) {}

    public function handle(Store $store, int $days, int $now): GiftCardsResult
    {
        $result = $this->shopify->giftCardCandidates($store);

        return new GiftCardsResult(count($result['gift_cards']), $days, $this->analyzer->analyze($result['gift_cards'], $days, $now), $result['pages'], $result['truncated']);
    }
}
