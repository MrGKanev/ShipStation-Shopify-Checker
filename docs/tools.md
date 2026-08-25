# All Tools

## Audit

### Core Audit

| Page | What it does |
| --- | --- |
| **Reports** | Browse historical ShipStation sync reports. Click any date in the sidebar to load that report. Download as CSV. |
| **Run Audit** | Compare every paid Shopify order against ShipStation for any date range. Flags genuinely missing orders and surfaces potential duplicate purchases. |
| **Trends** | Aggregated stats across all reports: average missing count, worst day, repeat offenders. Includes a full history chart and bulk-ignore table. |

### Order Issues

| Page | What it does |
| --- | --- |
| **Duplicate Detector** | Scan for potential duplicate purchases (same email + amount within 10 minutes). |
| **Refunds Tracker** | Track refunded orders across a date range. |
| **Repeat Refunds** | Identify customers with multiple refunded orders. |
| **Return / RMA Tracker** | Refunded orders with item-level return details and a per-SKU return-rate summary. |
| **Returned Items Report** | Itemized quantity totals for refunded line items in a date range, for spotting which SKUs come back most. |
| **Orphan Detector** | Find ShipStation orders with no matching Shopify order. |
| **Active SS Conflicts** | Find refunded/cancelled Shopify orders still active in ShipStation queues. |
| **SS Shipped / Shopify Unfulfilled** | ShipStation shipped orders that Shopify still shows as unfulfilled - a sign of sync failure. |
| **Order Edit History** | Post-placement edits to line items, discounts, notes, or custom attributes. |
| **Note Flags** | Paid, unfulfilled orders with flagged keywords (e.g. "cancel") in the order note. |

### Address & Contact

| Page | What it does |
| --- | --- |
| **Address Scanner** | Flag orders with potentially undeliverable or mismatched shipping addresses. |
| **Email Checker** | Scan for orders with missing or invalid customer emails. |
| **High-Value No Phone** | Surface high-value unfulfilled orders missing a shipping phone number. |
| **Address Changes** | Orders where the shipping address was edited after placement. |
| **Post-Ship Address Change** | Address edited *after* the order was already fulfilled - the package is already in transit. |
| **Duplicate Shipping Addresses** | Different customer emails shipping to the exact same address - multi-account/reseller signal. |

### Fulfillment

| Page | What it does |
| --- | --- |
| **Voided Shipments** | Orders where a shipment was voided/cancelled after creation. |
| **Fulfillment SLA Breaches** | Flag orders whose time-to-first-fulfillment exceeds a configurable SLA by shipping method and region. |
| **Bundle Check** | Validate that bundle orders contain all expected companion items. |
| **Partial Fulfillment Stalls** | Open orders partially shipped with unfulfilled items stalled for N+ days. |
| **On-Hold Stall** | Orders sitting in on-hold fulfillment status, sorted by how long they've been waiting. |
| **Fulfilled Without Tracking** | Fulfilled orders missing a tracking number after a configurable grace period. |
| **Shipment Aging** | Flag ShipStation awaiting-shipment orders older than a configurable threshold, grouped by SKU/order type. |
| **Shipped Item Mismatch** | ShipStation shipped items that don't match what was ordered in Shopify - catches picking errors, especially missing bundle accessories. |
| **Fulfilled Items Report** | Itemized quantity totals for orders fulfilled in a date range. |

### Carrier Analytics

| Page | What it does |
| --- | --- |
| **Carrier Performance** | Average delivery time and late-delivery rate grouped by carrier for a date range. |
| **Shipping Margin Erosion** | Orders where the ShipStation label cost exceeds what the customer was charged for shipping - flags orders shipped at a loss. |

### Products & Inventory

| Page | What it does |
| --- | --- |
| **Product Completeness** | Flag active products missing images, descriptions, or variant SKUs. |
| **SKU Duplicates** | Detect variants sharing the same SKU across the catalogue. |
| **Inventory Oversell Risk** | Surface variants where ShipStation awaiting qty exceeds available Shopify stock. |
| **Inventory Aging** | Find active tracked zero-stock variants that still sold recently. |
| **Inventory Forecast** | Days until zero stock per SKU, based on 30-day sell-through rate. |
| **Zombie Products** | Active products that can never be purchased - no variants, or every tracked variant permanently out of stock. |
| **Catalog Quality** | Active products not published to Online Store, missing SEO fields, or not in any collection. |

### Gift Cards

| Page | What it does |
| --- | --- |
| **Gift Cards** | Unused or soon-to-expire gift card balances. |

### Fraud & Compliance

| Page | What it does |
| --- | --- |
| **Billing ≠ Shipping Country** | Flag orders where billing and shipping countries differ - a documented fraud signal. |
| **Discount Abuse** | Group discount-code use by shipping address and flag clusters across multiple customer emails. |
| **Tag Policy Audit** | Validate required and forbidden tag combinations from `tag_policy.json`. |
| **Tax Audit** | Paid orders above a minimum amount with $0 tax charged to a non-exempt customer. |
| **Marketing Consent Audit** | Orders from customers without active email marketing consent - a compliance risk if targeted for campaigns. |
| **Fraud Risk Report** | Paid orders scored by combined fraud signals - disposable email, country mismatch, HIGH risk level, and more. |
| **Same IP, Different Emails** | Client IP addresses used by two or more distinct customer emails - a fraud ring signal. |

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
