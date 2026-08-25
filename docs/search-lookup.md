# Search & Lookup

Tools for live inspection of orders, customers, and catalogue data. Most of these pages hit the API directly and bypass the cache (marked **live**); a few use cached data where noted.

---

## Orders

### Spot-check
Live lookup of 1–50 order numbers across ShipStation and/or Shopify simultaneously. Never cached.

- Three modes: both platforms (default), ShipStation only, Shopify only
- Shows found/not-found per platform with order status and total
- Direct links to each order in the respective system
- Useful for verifying orders after customer enquiries without running a full audit

### Order Timeline
Merges all events from Shopify and ShipStation into a single reverse-chronological view for one order.

- Shopify events: placement, payment, fulfillments, refunds, cancellations, full audit trail
- ShipStation events: order status and shipment history (if credentials are configured)
- Calculates time-to-ship (days from placement to first shipment); color-coded red > 7 days, yellow > 3 days
- Flags risk signals: cancelled-but-shipped, refunded-but-still-active-in-SS
- "Copy as text" button exports the timeline to clipboard for pasting into support tickets

### Order Compare
Side-by-side comparison of two Shopify orders. Highlights fields that differ.

- Compares: line items, shipping address, financial status, fulfillment status, order total, tags, notes
- Shows ShipStation status alongside each order if credentials are configured
- Useful for investigating suspected duplicates or re-orders

### Tracking Feed
Looks up 1–30 order numbers in ShipStation and returns shipment details.

- Shows: carrier, tracking number, ship date, with direct links to carrier tracking pages
- Supported carriers: USPS, FedEx, UPS, DHL, OnTrac, LaserShip
- Displays all shipments if an order has multiple (split fulfillment)
- Orders not yet shipped show current ShipStation status instead

### Packing Slip Preview
Fetches and renders a ShipStation packing slip for any order. Live call, not cached. Print-optimised (sidebar and topbar hidden in print mode).

- Displays: warehouse address, ship-to address, order metadata, line items with options
- Detects and decodes a known ShipStation bug where item options are stored as a JSON string
- Shows internal notes, customer notes, and custom fields 1–3
- Configure `SS_WAREHOUSE_ADDR` in `.env` to populate the warehouse corner block

### Global Search
Searches a single query string across three local data sources: audit reports, push log, and ignored orders.

- Audit report matches: times seen, first and last report date
- Push log matches: Shopify ID, ShipStation Order ID, push timestamp
- Ignored matches: reason, ignored date
- Offers a "Live lookup in Spot-check" button to fetch fresh data for the same order number

---

## Customers

### Customer Lookup
Shows the complete order history for a customer, looked up by email address.

- Summary card: order count, lifetime spend, paid order count, cancelled count
- Tag cloud aggregates all tags across all orders, sorted by frequency
- Expandable order rows: line items, shipping address, shipping method, discounts, financial summary
- Each order links to Spot-check for ShipStation cross-reference
- Export to CSV (`customer-[email].csv`)
- Truncation warning shown for stores with 250+ orders per customer

### Customer LTV
Groups all non-cancelled orders in the selected date range by customer email.

- **Top Customers**: top 100 by total revenue in the period, with order count and average order value
- **Monthly Cohort**: customers grouped by the month of their first order in the period, showing repeat rate and average orders per customer
- Cancelled orders are excluded from all revenue figures

---

## Catalogue

### Metafields
Three modes on one page:

1. **Definitions** - lists all order metafield definitions from the store via GraphQL
2. **Search by value** - paginate through orders in a date range filtered by namespace + key, optionally by value. Leave value empty to find all orders that have a given metafield set at all. Scans up to 2,500 orders.
3. **Lookup by order number** - fetch all metafields on one or more specific orders, filterable by namespace.key or value substring

Live API calls, not cached.

### Tag Search
Finds all Shopify orders carrying a specific tag using Shopify's native tag index (`tag:"value"` in the GraphQL query string). No full table scan - fast regardless of store size.

- Exact, case-insensitive match (partial matching not supported by the index)
- Optional date range; omit to search all orders
- Results include financial status, fulfillment status, order total, and full tag list

### Tag Audit
Paginates through all orders in a date range and builds a complete tag inventory.

- Shows: tag name, usage count, last-seen date, last order number carrying the tag
- Identifies "orphan" tags: used only once and more than 90 days ago
- Sorted by frequency descending
- Each tag is a link to Tag Search for instant drill-down
