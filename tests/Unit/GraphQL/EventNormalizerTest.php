<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/Shopify/GraphQL/Ids.php';
require_once __DIR__ . '/../../../src/Shopify/GraphQL/EventNormalizer.php';

use PHPUnit\Framework\TestCase;

class EventNormalizerTest extends TestCase
{
    // ── normalizeEvent ────────────────────────────────────────────────────────

    public function testNormalizeEventBasicWithSubjectId(): void
    {
        $event = [
            'id'          => 'gid://shopify/BasicEvent/9001',
            'subjectId'   => 'gid://shopify/Order/10001',
            'subjectType' => 'ORDER',
            'action'      => 'PLACED',
            'createdAt'   => '2024-05-01T09:00:00Z',
            'message'     => 'Order was placed',
            'appTitle'    => 'ShopifyAdmin',
        ];

        $result = \Shopify\GraphQL\EventNormalizer::normalizeEvent($event);

        $this->assertSame(9001, $result['id']);
        $this->assertSame('gid://shopify/BasicEvent/9001', $result['admin_graphql_api_id']);
        $this->assertSame('placed', $result['verb']);
        $this->assertSame('placed', $result['action']);
        $this->assertSame('2024-05-01T09:00:00Z', $result['created_at']);
        $this->assertSame('Order was placed', $result['message']);
        $this->assertSame(10001, $result['subject_id']);
        $this->assertSame('order', $result['subject_type']);
        $this->assertSame('gid://shopify/Order/10001', $result['subject_graphql_api_id']);
        $this->assertSame('ShopifyAdmin', $result['app_title']);
    }

    public function testNormalizeEventNoSubjectIdUsesFallbackOrderId(): void
    {
        $event = [
            'id'          => 'gid://shopify/BasicEvent/9002',
            'subjectType' => 'ORDER',
            'action'      => 'EDITED',
            'createdAt'   => '2024-05-02T10:00:00Z',
            'message'     => 'The order was edited',
        ];

        $result = \Shopify\GraphQL\EventNormalizer::normalizeEvent($event, '20002');

        $this->assertSame(20002, $result['subject_id']);
        $this->assertSame('gid://shopify/Order/20002', $result['subject_graphql_api_id']);
    }

    public function testNormalizeEventNoSubjectIdNoFallbackUsesEmptyString(): void
    {
        $event = [
            'id'       => 'gid://shopify/BasicEvent/9003',
            'action'   => 'COMMENT',
            'createdAt' => '2024-05-03T11:00:00Z',
            'message'  => 'An internal note was added',
        ];

        $result = \Shopify\GraphQL\EventNormalizer::normalizeEvent($event, null);

        $this->assertSame('', $result['subject_id']);
        $this->assertSame('', $result['subject_graphql_api_id']);
    }

    public function testNormalizeEventActionIsLowercased(): void
    {
        $event = [
            'id'       => 'gid://shopify/BasicEvent/9004',
            'action'   => 'FULFILLMENT_CREATED',
            'createdAt' => '2024-05-04T12:00:00Z',
            'message'  => 'A fulfillment was created',
        ];

        $result = \Shopify\GraphQL\EventNormalizer::normalizeEvent($event);

        $this->assertSame('fulfillment_created', $result['verb']);
        $this->assertSame('fulfillment_created', $result['action']);
    }

    public function testNormalizeEventDefaultSubjectTypeIsOrder(): void
    {
        $event = [
            'id'        => 'gid://shopify/BasicEvent/9005',
            'createdAt' => '2024-05-05T13:00:00Z',
            'message'   => 'Something happened',
        ];

        $result = \Shopify\GraphQL\EventNormalizer::normalizeEvent($event);

        $this->assertSame('order', $result['subject_type']);
    }

    public function testNormalizeEventSubjectIdEmptyStringFallsBackToFallback(): void
    {
        $event = [
            'id'        => 'gid://shopify/BasicEvent/9006',
            'subjectId' => '',
            'action'    => 'PLACED',
            'createdAt' => '2024-05-06T14:00:00Z',
            'message'   => 'Order placed',
        ];

        $result = \Shopify\GraphQL\EventNormalizer::normalizeEvent($event, '30003');

        $this->assertSame(30003, $result['subject_id']);
    }

    // ── isAddressChangeEvent ──────────────────────────────────────────────────

    public function testIsAddressChangeEventTrueForShippingAddressInMessage(): void
    {
        $event = ['verb' => '', 'action' => '', 'message' => 'The shipping address was updated.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isAddressChangeEvent($event));
    }

    public function testIsAddressChangeEventTrueForAddressWasPhrase(): void
    {
        $event = ['verb' => '', 'action' => '', 'message' => 'The address was changed to 123 Main St.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isAddressChangeEvent($event));
    }

    public function testIsAddressChangeEventTrueForShippingAddressInVerb(): void
    {
        $event = ['verb' => 'shipping_address_updated', 'action' => '', 'message' => ''];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isAddressChangeEvent($event));
    }

    public function testIsAddressChangeEventTrueForShippingUnderscoreAddressInMessage(): void
    {
        $event = ['verb' => '', 'action' => '', 'message' => 'Field shipping_address updated.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isAddressChangeEvent($event));
    }

    public function testIsAddressChangeEventFalseForUnrelatedMessage(): void
    {
        $event = ['verb' => 'placed', 'action' => 'placed', 'message' => 'Order was placed by customer.'];
        $this->assertFalse(\Shopify\GraphQL\EventNormalizer::isAddressChangeEvent($event));
    }

    public function testIsAddressChangeEventFalseForEmptyEvent(): void
    {
        $this->assertFalse(\Shopify\GraphQL\EventNormalizer::isAddressChangeEvent([]));
    }

    public function testIsAddressChangeEventCaseInsensitive(): void
    {
        $event = ['verb' => '', 'action' => '', 'message' => 'SHIPPING ADDRESS was updated.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isAddressChangeEvent($event));
    }

    // ── isOrderEditEvent ──────────────────────────────────────────────────────

    public function testIsOrderEditEventTrueForEditCompleteVerb(): void
    {
        $event = ['verb' => 'edit_complete', 'message' => ''];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForEditCompleteAction(): void
    {
        $event = ['action' => 'edit_complete', 'message' => ''];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForWasEditedMessage(): void
    {
        $event = ['verb' => 'comment', 'message' => 'This order was edited by staff.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForWereEditedMessage(): void
    {
        $event = ['verb' => 'comment', 'message' => 'Line items were edited.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForItemWasAdded(): void
    {
        $event = ['verb' => 'comment', 'message' => 'An item was added to the order.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForItemWasRemoved(): void
    {
        $event = ['verb' => 'comment', 'message' => '1 item was removed from the order.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForDiscountWasAdded(): void
    {
        $event = ['verb' => 'comment', 'message' => 'A discount was added.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForDiscountWasRemoved(): void
    {
        $event = ['verb' => 'comment', 'message' => 'A discount was removed from the order.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForNoteWasUpdated(): void
    {
        $event = ['verb' => 'comment', 'message' => 'The note was updated.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventTrueForCustomAttributes(): void
    {
        $event = ['verb' => 'comment', 'message' => 'Custom attributes were changed.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventFalseForOrderPlacedEvent(): void
    {
        $event = ['verb' => 'placed', 'message' => 'Order was placed.'];
        $this->assertFalse(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }

    public function testIsOrderEditEventFalseForEmptyEvent(): void
    {
        $this->assertFalse(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent([]));
    }

    public function testIsOrderEditEventCaseInsensitiveOnMessage(): void
    {
        $event = ['verb' => '', 'message' => 'THE ORDER WAS EDITED BY ADMIN.'];
        $this->assertTrue(\Shopify\GraphQL\EventNormalizer::isOrderEditEvent($event));
    }
}
