# All Tools

## Audit

_The tables in this section are generated from `ToolRegistry::hubSections('audit')` - run `composer docs` after adding or editing an audit page, don't hand-edit between the AUTO-GENERATED markers. `composer test` fails if this file drifts out of sync._

<!-- AUTO-GENERATED:AUDIT-SECTION:START -->

### Core Audit

| Page | What it does |
| --- | --- |
| **Reports** | View and download saved audit reports |
| **Run Audit** | Compare Shopify vs ShipStation for any date range |
| **Trends** | Aggregated stats across all audit reports |

### Order Issues

| Page | What it does |
| --- | --- |
| **Duplicate Detector** | Same customer, same total - placed within 10 minutes |
| **Refunds Tracker** | Refunded Shopify orders cross-checked against ShipStation |
| **Repeat Refunds** | Customers with multiple refunded orders in a date range |
| **Return / RMA Tracker** | Refunded orders with item-level return details and per-SKU return rate summary |
| **Returned Items Report** | Itemized quantity totals for refunded line items in a date range |
| **Orphan Detector** | ShipStation orders with no matching Shopify order |
| **Active SS Conflicts** | Refunded or cancelled Shopify orders still active in ShipStation |
| **SS Shipped / Shopify Unfulfilled** | ShipStation shipped orders that Shopify still shows as unfulfilled (sync failure) |
| **Order Edit History** | Orders with post-placement edits: line items, discounts, notes or custom attributes |
| **Note Flags** | Paid unfulfilled orders with flagged keywords in the order note |

### Address & Contact

| Page | What it does |
| --- | --- |
| **Address Scanner** | Paid orders with incomplete or invalid shipping addresses |
| **Email Checker** | Orders with invalid, disposable or suspicious emails |
| **High-Value No Phone** | High-value unfulfilled orders missing a shipping phone |
| **Address Changes** | Orders whose shipping address was edited after placement |
| **Post-Ship Address Change** | Address edited AFTER the order was already fulfilled - package already in transit |
| **Duplicate Shipping Addresses** | Different customer emails shipping to the exact same address |

### Fulfillment

| Page | What it does |
| --- | --- |
| **Voided Shipments** | ShipStation shipments voided in the selected date range |
| **Fulfillment SLA Breaches** | Orders exceeding your time-to-first-fulfillment SLA by shipping method and region |
| **Bundle Check** | Bundled orders missing required companion items (Addon items) |
| **Partial Fulfillment Stalls** | Open orders partially shipped with unfulfilled items stalled for N+ days |
| **On-Hold Stall** | Fulfillment orders sitting on hold - sorted by how long the order has been waiting |
| **Fulfilled Without Tracking** | Fulfilled orders with no tracking number after a configurable grace period |
| **Shipment Aging** | ShipStation awaiting-shipment orders older than a configurable threshold |
| **Shipped Item Mismatch** | ShipStation shipped items that don't match what was ordered in Shopify - catches picking errors, especially missing accessories on bundled products |
| **Fulfilled Items Report** | Itemized quantity totals for orders fulfilled in a date range |

### Carrier Analytics

| Page | What it does |
| --- | --- |
| **Carrier Performance** | Avg delivery time, late rate, and order count grouped by carrier for a date range |
| **Shipping Margin Erosion** | Orders where the ShipStation label cost exceeds what the customer was charged for shipping — flags orders shipped at a loss |

### Products & Inventory

| Page | What it does |
| --- | --- |
| **Product Completeness** | Active products missing images, descriptions, or variant SKUs |
| **SKU Duplicates** | Variants sharing the same SKU across your product catalog |
| **Inventory Oversell Risk** | SKUs where ShipStation awaiting qty exceeds available Shopify stock |
| **Inventory Aging** | Zero-stock active variants that still sold recently |
| **Inventory Forecast** | Days until zero stock based on 30-day sell-through rate per SKU |
| **Zombie Products** | Active products with no variants or all tracked variants permanently out of stock |
| **Catalog Quality** | Active products not published to Online Store, missing SEO fields, or not in any collection |

### Gift Cards

| Page | What it does |
| --- | --- |
| **Gift Cards** | Unused or soon-to-expire gift card balances |

### Fraud & Compliance

| Page | What it does |
| --- | --- |
| **Billing ≠ Shipping Country** | Paid orders where billing and shipping countries differ - a documented fraud signal |
| **Discount Abuse** | Discount code clusters at the same shipping address across different emails |
| **Tag Policy Audit** | Required and forbidden Shopify tag combinations from local policy rules |
| **Tax Audit** | Paid orders above a minimum amount with $0 tax charged to a non-exempt customer |
| **Marketing Consent Audit** | Orders from customers without active email marketing consent - a compliance risk if targeted |
| **Fraud Risk Report** | Paid orders scored by combined fraud signals - disposable email, country mismatch, HIGH risk level, and more |
| **Same IP, Different Emails** | Client IP addresses used by two or more distinct customer emails - a fraud ring signal |
| **Chargebacks / Disputes** | Open Shopify Payments disputes needing evidence, sorted by response deadline |

<!-- AUTO-GENERATED:AUDIT-SECTION:END -->

## Search & Lookup

| Page | What it does |
| --- | --- |
| **Spot-check** | Live lookup of 1–50 order numbers in ShipStation and/or Shopify simultaneously. |
| **Metafields** | Browse metafield definitions, search orders by metafield value, or look up all metafields on a specific order. |
| **Tag Search** | Find all Shopify orders with a specific tag - fast, native index, no full scan. |
| **Tag Audit** | Build a complete tag inventory across a date range with frequency and last-seen info. |
| **Customer Lookup** | Full order history for a customer by email, with lifetime spend summary and CSV export. |
| **Customer LTV** | Top customers by lifetime value and monthly cohort retention for the selected period. |
| **Tracking Feed** | Live tracking details for 1–30 orders with direct links to carrier tracking pages. |
| **Order Compare** | Side-by-side comparison of two Shopify orders with differing fields highlighted. |
| **Order Timeline** | Merged Shopify + ShipStation event timeline for a single order. |
| **Global Search** | Search order number across audit reports, push log, and ignored orders at once. |
| **Packing Slip Preview** | Fetch and render a ShipStation packing slip for any order. Print-optimised. |

## Manage

| Page | What it does |
| --- | --- |
| **Ignored** | View and manage all ignored orders. Bulk-unignore with checkboxes. Import via CSV. |
| **Push Log** | Full history of every order pushed to ShipStation from the dashboard. |
| **Run History** | Recent audit and scan executions with status, duration, scanned count, issue count, and errors. |
| **Job Queue** | File-backed pending/running/done background audit jobs processed by `worker.php`. |
| **Action Log** | Operator audit trail for ignore/unignore, ShipStation pushes, queued jobs, store switches, and settings changes. |
| **Settings** | Test API connectivity, view current `.env` config, manage banned IPs. |
| **API Health** | Live Shopify/ShipStation health checks, Shopify API version, and required Shopify scopes. |
| **Config Check** | Validate `order_types.json`, `tag_policy.json`, and `stores.json`. |
| **Slack Rules** | Configure thresholds for Slack audit and scan notifications. |
