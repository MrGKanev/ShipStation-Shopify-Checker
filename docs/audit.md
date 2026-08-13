# Audit Engine

## How the audit works

1. Fetches all Shopify orders in the configured date range (cursor-paginated, up to 250/page)
2. Fetches all ShipStation orders for the same range **plus a 7-day trailing buffer** (catches sub-orders entered a few days after the Shopify order)
3. Filters out orders that should never appear in ShipStation (see [What gets skipped](#what-gets-skipped))
4. For any order not found in ShipStation, checks whether it is on hold in Shopify in GraphQL batches (50 orders per request), then caches each resolved order ID
5. Diffs the two sets and flags genuinely missing orders
6. Saves a CSV report under `reports/`
7. Scans the fetched Shopify orders for potential duplicates (same email + amount within 24 h)

---

## What gets skipped

| Skip reason | Condition |
|---|---|
| `cancelled` | `cancelled_at` is set |
| `financial` | Status is `pending`, `voided`, `refunded`, or `partially_refunded` |
| `fulfilled` | `fulfillment_status` is `fulfilled` or `restocked` |
| `on_hold` | Any fulfillment order has `status: on_hold` |
| `zero_value` | `total_price == 0` |
| `no_shipping` | `shipping_lines` is empty |
| `ignored` | Manually dismissed via the dashboard |

> **`on_hold`** is not exposed on the order object - it lives on the Fulfillment Order level. The queued audit resolves it in GraphQL batches and caches each order's result; an order with more than 20 fulfillment orders falls back to a fully paginated individual lookup.

---

## Duplicate detection

After each audit, Shopify orders are scanned for potential duplicates: same customer email + same rounded total, placed within 24 hours of each other. Results appear as a collapsible section below the missing-orders table. Useful for catching double-checkouts or accidental repeat purchases before they ship.

---

## Matching fallback

In addition to order-number matching, a secondary ShipStation index is keyed by `email + rounded amount`. If an order number lookup fails but a ShipStation order exists with the same customer email and a total within 1% of the Shopify total, it is treated as a match. Catches manually-entered ShipStation orders where the order number was typed differently.

---

## ShipStation fetch buffer

ShipStation orders are fetched for `startDate → endDate + 7 days`. Sub-orders (Addon, variant suffixes, etc.) are often entered into ShipStation a few days after the original Shopify order - the buffer prevents false "missing" results. Shopify orders are still fetched for the exact window, so comparison logic is unaffected.

The ShipStation order number index extracts every contiguous digit-run separately, so compound formats like `100042-B2` or `Addon-100031` resolve to their Shopify counterpart correctly.
