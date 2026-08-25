<?php
declare(strict_types=1);

use GuzzleHttp\HandlerStack;
use Shopify\GraphQL\AdminLookups;
use Shopify\GraphQL\CatalogAndFulfillment;
use Shopify\GraphQL\Client as GraphQLClient;
use Shopify\GraphQL\DisputeLookup;
use Shopify\GraphQL\OrderArchive;
use Shopify\GraphQL\OrderAudits;
use Shopify\GraphQL\OrderFetcher;

require_once __DIR__ . '/Shopify/GraphQL/bootstrap.php';

/**
 * Shopify Admin API client.
 *
 * Uses Admin GraphQL for migrated resources and keeps legacy REST-shaped
 * arrays at the public method boundary for the rest of the app.
 */
class Shopify
{
    public const string API_VERSION = '2026-07';

    private readonly GraphQLClient $graphqlClient;
    private readonly OrderArchive $orderArchive;
    private readonly OrderFetcher $orderFetcher;
    private readonly OrderAudits $orderAudits;
    private readonly AdminLookups $adminLookups;
    private readonly CatalogAndFulfillment $catalogAndFulfillment;
    private readonly DisputeLookup $disputeLookup;
    private readonly string $baseUrl;
    private readonly string $accessToken;

    public function __construct(
        string $store,
        string $accessToken,
        ?Cache $cache = null,
        ?HandlerStack $stack = null
    ) {
        $host = str_contains($store, '.') ? $store : "{$store}.myshopify.com";
        $baseUrl = "https://{$host}/admin/api/" . self::API_VERSION;

        $this->baseUrl       = $baseUrl;
        $this->accessToken   = $accessToken;
        $this->graphqlClient = new GraphQLClient($baseUrl, $accessToken, $stack);
        $this->orderArchive          = new OrderArchive($this->graphqlClient, $cache);
        $this->orderFetcher          = new OrderFetcher($this->graphqlClient, $cache);
        $this->orderAudits           = new OrderAudits($this->orderFetcher);
        $this->adminLookups          = new AdminLookups($this->graphqlClient, $cache);
        $this->catalogAndFulfillment = new CatalogAndFulfillment($this->graphqlClient, $cache);
        $this->disputeLookup         = new DisputeLookup($this->graphqlClient);
    }

    // ── Public ────────────────────────────────────────────────────────

    /**
     * Returns every order created between $start and $end (inclusive).
     * Includes total_price and shipping_lines for filtering logic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        return $this->orderArchive->fetchAllOrders($startDate, $endDate);
    }

    /**
     * Look up orders by order number (the short numeric part, e.g. "65075").
     * Tries both the plain number and the #1xxxxx name format.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        return $this->adminLookups->findByOrderNumber($orderNumber);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function findByOrderNumbers(array $orderNumbers): array
    {
        return $this->adminLookups->findByOrderNumbers($orderNumbers);
    }

    /**
     * Fetches a single order by its Shopify numeric ID for detail views and ShipStation push.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        return $this->adminLookups->getOrder($orderId);
    }

    /**
     * Returns true if any fulfillment order for this Shopify order ID has
     * status ON_HOLD. Hold state is exposed through fulfillment orders rather
     * than the order object's fulfillment status.
     *
     * Results are cached per order ID to avoid redundant calls during
     * large historical audits.
     */
    public function isOnHold(string $orderId): bool
    {
        return $this->adminLookups->isOnHold($orderId);
    }

    /**
     * Batch variant of isOnHold(), intended for audit result sets.
     *
     * @param array<int, int|string> $orderIds
     * @return array<string, true> IDs of orders currently on hold
     */
    public function findOnHoldOrderIds(array $orderIds): array
    {
        return $this->adminLookups->findOnHoldOrderIds($orderIds);
    }

    /**
     * Fetches all metafield definitions for a given owner type via GraphQL (default: ORDER).
     * REST API does not expose metafield_definitions - GraphQL is required.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchMetafieldDefinitions(string $ownerType = 'ORDER'): array
    {
        return $this->adminLookups->fetchMetafieldDefinitions($ownerType);
    }

    /**
     * Fetches all metafields for a specific order by its Shopify numeric ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderMetafields(string $orderId): array
    {
        return $this->adminLookups->getOrderMetafields($orderId);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function getOrderMetafieldsByOrderIds(array $orderIds): array
    {
        return $this->adminLookups->getOrderMetafieldsByOrderIds($orderIds);
    }

    /**
     * Searches orders by Shopify tag via GraphQL. The `tag:` filter is natively
     * supported in the orders query string, so this is a fast indexed lookup.
     *
     * @return array{matches: array, scanned: int, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(
        string $tag,
        string $startDate = '',
        string $endDate   = '',
        int    $maxPages  = 20
    ): array {
        return $this->adminLookups->searchOrdersByTag($tag, $startDate, $endDate, $maxPages);
    }

    /**
     * Searches orders by metafield value by paginating through orders in a date
     * range and filtering client-side. Shopify does not support metafield value
     * filtering in the GraphQL query string, so we fetch each page with the
     * metafield inline and keep only matching rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchOrdersByMetafield(
        string $namespace,
        string $key,
        string $value,
        string $startDate = '',
        string $endDate   = '',
        int    $maxPages  = 10
    ): array {
        return $this->adminLookups->searchOrdersByMetafield($namespace, $key, $value, $startDate, $endDate, $maxPages);
    }

    /**
     * Paginates through orders in a date range and returns aggregate tag statistics:
     * count per tag, last-seen order name and date. Used for the Tag Audit page.
     *
     * @return array{tags: list<array>, total_orders: int, truncated: bool, pages: int}
     */
    public function fetchTagStats(string $startDate = '', string $endDate = '', int $maxPages = 40): array
    {
        return $this->adminLookups->fetchTagStats($startDate, $endDate, $maxPages);
    }

    /**
     * Fetches paid orders in a date range with full shipping address fields for address validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddressScan(string $startDate, string $endDate, bool $unfulfilledOnly = false): array
    {
        return $this->orderAudits->fetchOrdersForAddressScan($startDate, $endDate, $unfulfilledOnly);
    }

    /**
     * Returns Shopify orders with refunded or partially_refunded financial status
     * in the given date range, including refund line details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForHighValue(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForHighValue($startDate, $endDate);
    }

    /**
     * Fetches orders whose shipping address was changed after the order was placed.
     * Strategy: paginate GraphQL order events in the window, filter for address-change
     * messages, then fetch the matching orders by ID.
     *
     * @return array<int, array<string, mixed>>  each entry has 'order' + 'changed_at'
     */
    public function fetchOrdersWithAddressChanges(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersWithAddressChanges($startDate, $endDate);
    }

    /**
     * Finds orders that had content edits (line items, notes, custom attributes, discounts)
     * after placement, using Shopify order events. Returns orders sorted by edit date desc.
     *
     * Each entry: shopify_id, order_number, created_at, edited_at, diff_mins,
     *             email, total, financial, fulfillment, edit_summary (string[])
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEditedOrders(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchEditedOrders($startDate, $endDate);
    }

    public function fetchRefundedOrders(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchRefundedOrders($startDate, $endDate);
    }

    /**
     * Returns all orders for a given customer email, plus customer summary data.
     * Uses GraphQL email: filter (indexed, fast).
     *
     * @return array{orders: array, customer: array|null, total_spent: float, currency: string, truncated: bool}
     */
    public function lookupCustomer(string $email, int $maxPages = 20): array
    {
        return $this->adminLookups->lookupCustomer($email, $maxPages);
    }

    /**
     * Finds potential duplicate orders: same email + same total within 10 minutes.
     * Paginates through the given date range via GraphQL.
     *
     * @return array{pairs: list<array>, scanned: int, truncated: bool}
     */
    public function findDuplicateOrders(string $startDate, string $endDate): array
    {
        return $this->adminLookups->findDuplicateOrders($startDate, $endDate);
    }

    /**
     * Fetches the event/audit log for a specific order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderEvents(string $orderId): array
    {
        return $this->adminLookups->getOrderEvents($orderId);
    }

    /**
     * Paid orders where billing country != shipping country.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForCountryMismatch(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForCountryMismatch($startDate, $endDate);
    }

    /**
     * Open orders in 'partial' fulfillment status - includes line_items + fulfillments
     * so callers can determine which items remain unfulfilled and for how long.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchPartiallyFulfilledOrders(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchPartiallyFulfilledOrders($startDate, $endDate);
    }

    /**
     * Fetches all products from the store. $status can be 'active', 'draft', 'archived', or 'any'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(string $status = 'active'): array
    {
        return $this->catalogAndFulfillment->fetchAllProducts($status);
    }

    /**
     * Fetches on-hold fulfillment orders via GraphQL, filtered to the given order creation date range.
     * Requires the read_merchant_managed_fulfillment_orders or read_assigned_fulfillment_orders scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOnHoldFulfillmentOrders(string $startDate, string $endDate): array
    {
        return $this->catalogAndFulfillment->fetchOnHoldFulfillmentOrders($startDate, $endDate);
    }

    /**
     * Fetches fulfilled or partially-fulfilled orders with their fulfillment records (tracking data).
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Fetches fulfilled/partially-fulfilled orders by fulfillment activity (updated_at)
     * rather than order creation date, so orders created before $startDate but shipped
     * after it are still included. Callers should filter each order's fulfillments by
     * their own created_at for an exact date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersFulfilledSince(string $startDate): array
    {
        return $this->orderAudits->fetchOrdersFulfilledSince($startDate);
    }

    /**
     * Same order set as fetchOrdersFulfilledSince(), but includes shipping_lines
     * instead of fulfillments, for matching shipments to orders regardless of the
     * order's creation date (e.g. shipping-margin scans).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersFulfilledSinceWithShipping(string $startDate): array
    {
        return $this->orderAudits->fetchOrdersFulfilledSinceWithShipping($startDate);
    }

    /**
     * Fetches refunded/partially-refunded orders by refund activity (updated_at)
     * rather than order creation date, so orders created before $startDate but
     * refunded after it are still included. Callers should filter each order's
     * refunds by their own created_at for an exact date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersRefundedSince(string $startDate): array
    {
        return $this->orderAudits->fetchOrdersRefundedSince($startDate);
    }

    /**
     * Returns orders where the shipping address was changed AFTER the first fulfillment was created.
     * Builds on the same GraphQL events strategy as fetchOrdersWithAddressChanges but includes
     * fulfillments in the batch order fetch to compare timestamps.
     *
     * @return array<int, array{order: array, changed_at: string, fulfillment_at: string}>
     */
    public function fetchPostShipAddressChanges(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchPostShipAddressChanges($startDate, $endDate);
    }

    /**
     * Fetches paid, unfulfilled orders including the note field for keyword scanning.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersWithNotes(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersWithNotes($startDate, $endDate);
    }

    /**
     * Fetches paid orders with shipping address data for duplicate-address analysis.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForAddrDupes(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForAddrDupes($startDate, $endDate);
    }

    /**
     * Fetches paid orders with shipping method, destination, and fulfillment data for SLA checks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSla(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForSla($startDate, $endDate);
    }

    /**
     * Fetches cancelled Shopify orders in a date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCancelledOrders(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchCancelledOrders($startDate, $endDate);
    }

    /**
     * Fetches paid orders with discount and shipping address fields for abuse clustering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForDiscountAudit(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForDiscountAudit($startDate, $endDate);
    }

    /**
     * Fetches paid orders with tags for policy validation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTagPolicy(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForTagPolicy($startDate, $endDate);
    }

    /**
     * Fetches paid orders with total tax and customer tax-exempt status for the Tax Audit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForTaxAudit(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForTaxAudit($startDate, $endDate);
    }

    /**
     * Fetches paid orders with customer marketing consent state for the Marketing Consent Audit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForConsentAudit(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForConsentAudit($startDate, $endDate);
    }

    /**
     * Fetches paid orders with billing/shipping address, tags, and Shopify's
     * fraud risk assessment for the Fraud Risk Report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForFraudRisk(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForFraudRisk($startDate, $endDate);
    }

    /**
     * Fetches paid orders with client IP for the Same IP, Different Emails audit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrdersForSameIp(string $startDate, string $endDate): array
    {
        return $this->orderAudits->fetchOrdersForSameIp($startDate, $endDate);
    }

    /**
     * Fetches open/actionable Shopify Payments disputes (NEEDS_RESPONSE and
     * UNDER_REVIEW). Requires the read_shopify_payments_disputes scope;
     * returns an empty array (not an error) on stores without Shopify
     * Payments or without the scope granted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOpenDisputes(): array
    {
        return $this->disputeLookup->fetchDisputes();
    }

    /**
     * Fetches all gift cards from the store.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchGiftCards(): array
    {
        return $this->catalogAndFulfillment->fetchGiftCards();
    }

    /**
     * Fetches all registered Shopify webhooks via the Admin REST API.
     * Returns the raw webhook objects from the API.
     *
     * @param  ?callable(string, array<int, string>): array{ok: bool, code: int, error: string, json: array} $request
     *         Injectable for tests; defaults to a real cURL GET.
     * @return array{webhooks: array<int, array<string, mixed>>, error: string}
     */
    public function fetchWebhooks(?callable $request = null): array
    {
        $url     = $this->baseUrl . '/webhooks.json?limit=250';
        $request ??= [$this, 'curlGetJson'];

        $result = $request($url, [
            "X-Shopify-Access-Token: {$this->accessToken}",
            'Accept: application/json',
        ]);

        if ($result['error'] !== '') {
            return ['webhooks' => [], 'error' => "cURL error: {$result['error']}"];
        }
        if (!$result['ok']) {
            return ['webhooks' => [], 'error' => "Shopify returned HTTP {$result['code']}"];
        }

        return ['webhooks' => $result['json']['webhooks'] ?? [], 'error' => ''];
    }

    /**
     * @param  array<int, string> $headers
     * @return array{ok: bool, code: int, error: string, json: array}
     */
    private function curlGetJson(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'ShopifyOps/1.0',
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'ok'    => $code >= 200 && $code < 300,
            'code'  => $code,
            'error' => $err ?: '',
            'json'  => is_array($json) ? $json : [],
        ];
    }

    /**
     * Updates the internal note on a Shopify order via the Admin GraphQL API.
     *
     * @param  string $orderId  Numeric Shopify order ID (legacy resource ID).
     * @param  string $note     New note text (empty string clears the note).
     * @throws \RuntimeException on API or user errors.
     */
    public function updateOrderNote(string $orderId, string $note): void
    {
        $gid    = \Shopify\GraphQL\Ids::orderGid($orderId);
        $result = $this->graphqlClient->graphql(
            'mutation UpdateOrderNote($id: ID!, $note: String!) {
               orderUpdate(input: {id: $id, note: $note}) {
                 order { id note }
                 userErrors { field message }
               }
             }',
            ['id' => $gid, 'note' => $note]
        );
        $errors = $result['data']['orderUpdate']['userErrors'] ?? [];
        if (!empty($errors)) {
            $msg = implode('; ', array_map(fn($e) => $e['message'], $errors));
            throw new \RuntimeException("Shopify orderUpdate error: {$msg}");
        }
    }

}
