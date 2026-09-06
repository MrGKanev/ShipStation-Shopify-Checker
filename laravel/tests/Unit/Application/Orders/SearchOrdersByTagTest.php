<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\SearchOrdersByTag;
use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\TestCase;

class SearchOrdersByTagTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_only_exact_case_insensitive_tag_matches_in_source_order(): void
    {
        $store = new Store;
        $shopify = Mockery::mock(ShopifyAdminGateway::class);
        $shopify->shouldReceive('searchOrdersByTag')->once()->with($store, 'VIP Member', null, '2026-09-06')->andReturn([
            'orders' => [
                ['name' => '#3', 'tags' => ['vip member']],
                ['name' => '#2', 'tags' => ['VIP']],
                ['name' => '#1', 'tags' => ['VIP Member', 'Wholesale']],
                ['name' => '#0', 'tags' => 'malformed'],
            ],
            'pages' => 2,
            'truncated' => true,
        ]);

        $result = (new SearchOrdersByTag($shopify))->handle($store, 'VIP Member', null, '2026-09-06');

        $this->assertSame(['#3', '#1'], array_column($result->orders, 'name'));
        $this->assertSame(2, $result->pages);
        $this->assertTrue($result->truncated);
    }
}
