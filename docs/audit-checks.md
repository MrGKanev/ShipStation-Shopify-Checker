# Audit Checks

Individual audit pages that surface specific order and product issues. All pages support CSV export unless noted otherwise.

---

## Fulfillment & Logistics

### Partial Fulfillment Stalls
Finds paid Shopify orders in `partial` fulfillment status where unfulfilled items haven't progressed. "Stalled for" counts days since the last fulfillment was created (or from order date if no fulfillment exists at all). Excludes cancelled, fully refunded, and closed orders.

- Configurable stall threshold in days
- Color-coded: red ≥ 30 days, yellow ≥ 14 days
- Shows unfulfilled line items with SKUs and quantities

### Fulfillment SLA Breaches
Finds paid Shopify orders whose time-to-first-fulfillment exceeds a configurable threshold. Fulfilled orders are measured from placement to first fulfillment; open orders are measured from placement to today.

- Groups context by shipping method, destination region, and configured order type
- Useful for finding slow lanes, regions, or product types

### Shipment Aging
Scans the live ShipStation awaiting-shipment queue and flags orders older than N days.

- Includes SKU and order-type summaries
- Links directly to ShipStation and Spot-check

### Voided Shipments
Shows shipments voided in ShipStation within the selected date range. Intended for proactive follow-up before the customer notices a tracking link has gone dead.

- Displays: order#, void date, original ship date, carrier, service, tracking number, ship-to address

### Address Changes
Uses Shopify order events via GraphQL to find orders where the shipping address was edited after placement. Only surfaces orders with an explicit "shipping address updated" event - not just any edit.

- Useful for catching last-minute requests, fraudulent address swaps, or support edits that didn't reach ShipStation
- Large date ranges are slower due to order event pagination
- Shows time gap between placement and change

### Order Edit History
Uses Shopify order events via GraphQL to detect post-placement edits to line items, discounts, notes, or custom attributes. Distinct from Address Changes (which is tracked separately).

- Edit summary shows the actual event messages Shopify logged
- Time gap color-coded: red ≥ 1 day, yellow ≥ 1 hour
- In-table search/filter by order#, email

### Post-Ship Address Change
Finds orders where the shipping address was updated *after* the first fulfillment was created - the package is already in transit and the new address cannot be applied.

- Different from Address Changes, which flags any post-placement edit regardless of fulfillment state
- Shows the change timestamp, the first-fulfillment timestamp, and the gap between them

### SS Shipped / Shopify Unfulfilled
The reverse of the standard audit: finds orders ShipStation has marked *shipped* that Shopify still shows as unfulfilled or partially fulfilled - a sync failure.

- Common causes: webhook delivery failure, API timeout during fulfillment sync, or a manually shipped ShipStation order without a Shopify fulfillment hook
- Orders fulfilled in both systems are excluded; only the discrepancy is shown
- True orphans (no matching Shopify order at all) are excluded - use Orphan Detector for those

### On-Hold Stall
Finds Shopify orders currently in on-hold fulfillment status, placed within the scanned date range.

- Uses the GraphQL `fulfillmentOrders` API - requires the `read_merchant_managed_fulfillment_orders` or `read_assigned_fulfillment_orders` scope
- "Waiting" counts days since the order was placed
- Hold Reason is reported by Shopify (e.g. `MANUAL`, `HIGH_RISK_OF_FRAUD`, `AWAITING_PAYMENT`)

### Fulfilled Without Tracking
Finds fulfilled (or partially fulfilled) orders where one or more fulfillments have no tracking number after a configurable grace period (default 24h).

- Grace period avoids flagging fulfillments where tracking is added within minutes
- Fulfillments with a `tracking_company` set but no `tracking_number` are also included

### Shipped Item Mismatch
Checks what ShipStation actually shipped against what the customer ordered in Shopify, at the SKU and quantity level - the standard audit only confirms an order exists in both systems, never that the contents match.

- Especially valuable for multi-part bundles, where a picker can grab the wrong variant or omit an accessory undetected
- Only ShipStation orders marked shipped are checked; cancelled, refunded/voided, or zero-value orders are excluded
- "Missing Required" rows are the most urgent: a bundle accessory the customer ordered was left out of the shipment

### Carrier Performance
Groups ShipStation shipment records for a date range by carrier code.

- Avg Delivery Days: calculated from `shipDate` to `deliveryDate` where both are present
- Late %: percentage of shipments taking more than 5 days to deliver
- Shipments without a delivery date are counted but excluded from the averages; availability of `deliveryDate` varies by carrier/service level

### Shipping Margin Erosion
Compares what ShipStation actually charged to ship a package against what the customer paid for shipping at checkout.

- Ship Cost = ShipStation `shipmentCost + insuranceCost`; Shipping Charged = sum of the order's Shopify shipping line prices
- Only rows where Loss (Ship Cost − Shipping Charged) exceeds a configurable threshold appear
- Common causes: free-shipping promos on heavy/bulky items, underpriced flat-rate options, unpriced carrier zone surcharges
- Voided shipments are excluded; only shipments matching a Shopify order by order number are checked

### Fulfilled Items Report
Itemized quantity totals for orders fulfilled in a date range, filtered by order creation date (Shopify's search API doesn't support filtering by fulfillment date).

- Partially fulfilled and unfulfilled orders are excluded
- "Show order #" breaks totals out per order instead of summing across the range
- "Group by product" totals each product and lists the orders containing it

---

## Fraud & Compliance

### Country Mismatch
Finds paid orders where the billing country differs from the shipping country - a documented Shopify fraud signal. Common in freight forwarding, stolen card abuse, and drop-ship fraud. Most matches are legitimate, but outliers are worth reviewing manually.

- In-table search by order#, email, country

### High-Value No Phone
Surfaces paid, unfulfilled orders above a configurable dollar threshold where the shipping address has no phone number. Carriers increasingly require a phone number for high-value shipments; catching this before dispatch avoids delivery delays.

### Address Scanner
Validates shipping addresses on all paid/partially paid orders in the date range.

| Severity | Checks |
| --- | --- |
| **Critical** | Missing street, city, ZIP, country, or recipient name |
| **Warning** | Invalid ZIP format (US/CA), missing state/province, PO Box, no phone on express shipment |

Filters: PO Box only, unfulfilled only. Critical issues sorted to top.

### Email Checker
Scans paid/partially paid orders for email issues before they ship.

| Severity | Checks |
| --- | --- |
| **Critical** | Missing/invalid email, known disposable domains (Mailinator, YOPmail, 10MinuteMail, etc.) |
| **Warning** | Placeholder-like addresses (`test@`, `noemail@`), very short local parts, suspicious repeated characters |

### Repeat Refunds
Identifies customers with 2+ refunded orders in the selected date range. Configurable minimum refund count (default ≥ 2). Groups by email, sorted by refund count descending. Each customer links to their full Customer Lookup page.

### Duplicate Shipping Addresses
Finds paid orders where two or more *different* customer emails ship to the exact same address - a signal for multi-account abuse, reseller networks, or dropshipping schemes.

- Matching key: `address1 + city + ZIP + country`, normalized to lowercase (province and name excluded so minor variations don't hide matches)
- Multiple orders from the *same* email to the same address are excluded - only cross-email duplicates are flagged
- Sort by Emails descending to find the most suspicious clusters first

### Note Flags
Scans paid, unfulfilled orders for configurable keywords in the order note - surfacing orders that need attention before shipment.

- Only paid/partially-paid, unfulfilled/partial orders are scanned; already-fulfilled orders are excluded
- Keyword matching is case-insensitive substring matching (e.g. `cancel` matches "please cancel this order")

### Tax Audit
Finds paid orders above a minimum amount where no tax was charged and the customer is not marked tax-exempt in Shopify.

- No jurisdiction logic is applied - this is a review signal (likely tax-setting or rate misconfiguration), not a definitive compliance verdict
- Orders below the minimum amount are skipped to avoid noise from free or heavily-discounted orders

### Marketing Consent Audit
Finds paid orders placed by customers whose Shopify email marketing consent state is not `subscribed`.

- Useful before running a marketing/win-back campaign off an order list - these customers should be excluded or handled separately
- SMS consent is shown as an informational column but doesn't affect the flag

### Fraud Risk Report
Scores every paid order in the date range with the same composite risk model used by Spot-check and Metafields search (disposable/invalid email, billing ≠ shipping country, missing phone on a high-value order, PO Box address, partially paid, fraud/high-risk tag, Shopify HIGH risk assessment, no shipping address), then lists everything above *low* risk.

- Only medium (score ≥ 21) and high (score ≥ 51) orders are shown; low is too noisy to review at scale
- Sorted by score descending; each row expands to show the exact signals that contributed
- Custom signal weights can be set in `data/risk_weights.json`

### Same IP, Different Emails
Finds paid orders where two or more *different* customer emails share the same client IP address recorded at checkout - a signal for multi-account abuse or a fraud ring operating from one device/network.

- Multiple orders from the *same* email at the same IP are excluded - only cross-email matches are flagged
- Shared IPs are common for offices, universities, or carrier-grade NAT - treat clusters as a review signal, not automatic proof of fraud
- Sort by Emails descending to find the most suspicious clusters first

### Chargebacks / Disputes
Lists open Shopify Payments disputes - buyers who questioned a charge with their bank - sorted by how urgent the evidence response deadline is.

- Only Needs Response (has a hard deadline) and Under Review (evidence submitted, awaiting the card network) are shown; resolved disputes (won, lost, accepted, prevented) are excluded
- Days Until Due goes negative once the deadline has passed - Shopify auto-accepts the dispute at that point
- Requires the `read_shopify_payments_disputes` access scope and a store on Shopify Payments; stores without either simply return zero disputes, not an error

---

## Order Quality

### Orphan Detector
Reverse audit: finds ShipStation orders with no matching Shopify order. Common causes include manually created SS orders, test/dummy entries, orders imported from other channels (Amazon, eBay, CSV), or a disconnected Shopify store.

- Matching uses normalized order numbers (same logic as the main audit engine)

### Active SS Conflicts
Finds Shopify orders that are refunded or cancelled but still active in ShipStation (`awaiting_payment`, `awaiting_shipment`, or `on_hold`).

- Prevents accidental fulfillment after refund/cancellation
- Uses the same normalized order matching as the audit engine

### Bundle Check
Scans for orders missing required companion items as defined in `order_types.json` under `required_items`. Covers fulfilled orders too - catching shipped bundles missing a component is the most urgent case. See [order-types.md](order-types.md) for configuration.

### Return / RMA Tracker
Fetches refunded and partially-refunded orders in a date range and shows the returned items from each refund.

- Each row is one refund event, with the items returned and the refund amount
- Reason column shows the note attached to the refund, if any
- SKU Return Summary totals units returned and revenue refunded per SKU across all refunds in the range

### Returned Items Report
Pulls orders refunded in a date range and sums refund line-item quantities per product/variant, filtered by order creation date.

- Useful for spotting which SKUs come back most - a signal for quality issues or restock decisions

---

## Product & Catalogue

### Product Completeness
Scans all active products for missing content that breaks fulfillment or affects storefront quality.

| Severity | Check |
| --- | --- |
| **Critical** | Variants with no SKU |
| **Warning** | No images, no description |

Links directly to the Shopify product editor for quick fixes.

### SKU Duplicates
Scans all products (active, draft, archived) for SKUs appearing more than once. Variants with no SKU are ignored. Duplicate SKUs cause inventory tracking errors and fulfillment routing issues.

### Inventory Oversell Risk
Compares current Shopify inventory levels against ShipStation orders awaiting shipment. Only considers variants where Shopify inventory management is enabled with a `deny` policy. Shows the shortfall when awaiting quantity exceeds available stock.

- Real-time check (no date range needed)

### Inventory Aging
Finds active tracked variants at zero or negative inventory that still sold in the selected recent window.

- Helps identify stale stock settings, inventory sync issues, or demand that needs replenishment

### Inventory Forecast
Calculates the projected days until each tracked variant runs out of stock, based on the last 30 days of actual sales.

- Only variants with inventory tracking enabled and a `deny` oversell policy are included
- Daily Rate = units sold in last 30 days ÷ 30; Days to Zero = current stock ÷ daily rate (blank means no sales or already zero)
- Color-coded: red < 7 days remaining, yellow 7-13 days

### Zombie Products
Finds active (published) products that cannot be purchased - either no variants defined, or every tracked variant permanently out of stock with a `deny` oversell policy.

- "No variants": the product exists but has no purchasable options at all
- "All at 0": every tracked, deny-policy variant is at zero or negative stock, showing "Sold Out" indefinitely
- Variants set to continue selling when out of stock, or with untracked inventory, are excluded from the zero-stock check

### Catalog Quality
Finds active products with gaps that hurt storefront visibility or SEO.

- Not published: not published to the Online Store sales channel, invisible to customers
- Missing SEO title/description: no custom search-engine listing preview set
- Not in any collection: customers can't discover the product by browsing

### Gift Cards
Finds enabled gift cards with a remaining balance that are either expiring soon or have never been redeemed.

- Expiring soon: balance > 0 and expiry date falls within a configured window
- Never redeemed: balance still equals the full initial value
- Disabled or fully-redeemed cards are excluded

### Discount Abuse
Groups paid orders by discount code and shipping address, then flags clusters where multiple distinct customer emails used the same code at the same destination.

### Tag Policy Audit
Validates paid orders against `tag_policy.json`.

- `required`: when all trigger tags are present, required tags must also be present
- `forbidden`: listed tags must not appear together on one order
