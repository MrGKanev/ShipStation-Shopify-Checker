<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use Tests\TestCase;

class ShopifyOrderEventNormalizerTest extends TestCase
{
    public function test_returns_the_legacy_event_shape(): void
    {
        $event = (new ShopifyOrderEventNormalizer)->normalize([
            'id' => 'gid://shopify/OrderEvent/987',
            'action' => 'FULFILLMENT_SUCCESS',
            'appTitle' => 'Shopify Flow',
            'createdAt' => '2026-09-05T12:30:00Z',
            'message' => 'Fulfillment completed',
            'subjectId' => 'gid://shopify/Order/123',
            'subjectType' => 'ORDER',
        ], 'gid://shopify/Order/456');

        $this->assertSame([
            'id' => 987,
            'admin_graphql_api_id' => 'gid://shopify/OrderEvent/987',
            'verb' => 'fulfillment_success',
            'action' => 'fulfillment_success',
            'created_at' => '2026-09-05T12:30:00Z',
            'message' => 'Fulfillment completed',
            'subject_id' => 123,
            'subject_type' => 'order',
            'subject_graphql_api_id' => 'gid://shopify/Order/123',
            'app_title' => 'Shopify Flow',
        ], $event);
    }

    public function test_uses_the_order_as_the_subject_for_non_basic_events(): void
    {
        $event = (new ShopifyOrderEventNormalizer)->normalize([
            'id' => 'gid://shopify/OrderEvent/987',
            'action' => 'CONFIRMED',
            'createdAt' => '2026-09-05T12:30:00Z',
            'message' => 'Order confirmed',
        ], 'gid://shopify/Order/456');

        $this->assertSame(456, $event['subject_id']);
        $this->assertSame('order', $event['subject_type']);
        $this->assertSame('gid://shopify/Order/456', $event['subject_graphql_api_id']);
        $this->assertSame('', $event['app_title']);
    }
}
