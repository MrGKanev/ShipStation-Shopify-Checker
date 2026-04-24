# ShipStation ↔ Shopify Order Audit

Compares every paid Shopify order against ShipStation and surfaces the ones that are missing. Runs as a daily cron job and exposes a password-protected web dashboard to browse historical reports.

---

## How it works

1. Fetches all Shopify orders in the configured date range (cursor-paginated, up to 250/page)
2. Fetches all ShipStation orders in the same range (paginated, up to 500/page)
3. Filters out Shopify orders that should never be in ShipStation (see [What gets skipped](#what-gets-skipped))
4. For orders not found in ShipStation, checks whether each is on hold in Shopify (separate Fulfillment Orders API call, cached per order ID)
5. Diffs the two sets and flags any Shopify orders genuinely missing from ShipStation
6. Saves a CSV + TXT report under `reports/`
7. The web dashboard reads those reports and displays them

---

## Requirements

- PHP 8.1+ with the `curl` extension
- A web server (Apache / Nginx / Caddy) or `php -S` for local use
- ShipStation API credentials
- Shopify Admin API access token (requires `read_orders` and `read_fulfillments` scopes)

---

## Setup

### 1. Clone & configure

```bash
git clone https://github.com/mrgkanev/ShipStation-Shopify-Checker.git
cd ShipStation-Shopify-Checker
cp .env.example .env
```

Edit `.env` and fill in:

| Variable | Where to get it |
|---|---|
| `SS_API_KEY` | ShipStation → Settings → API |
| `SS_API_SECRET` | same page |
| `SHOPIFY_STORE` | the subdomain part of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | Shopify → Apps → Develop apps → your app → Admin API access token |
| `WEB_PASSWORD` | choose any password for the dashboard |

### 2. Run the audit manually

```bash
php audit.php
```

Spot-check specific order numbers:

```bash
php audit.php --spot-check 164777,164789
```

Override the date window (default: last 90 days):

```bash
AUDIT_START_DATE=2025-01-01 AUDIT_END_DATE=2025-03-31 php audit.php
```

### 3. Schedule via cron

Run once a day at 06:00 and append output to a log file:

```cron
0 6 * * * cd /var/www/ShipStation-Shopify-Checker && php audit.php >> logs/audit.log 2>&1
```

Exit codes: `0` = all clear, `1` = missing orders found (useful for cron alerting), `2` = script error.

### 4. Web dashboard

Point your web server document root at the repo directory. Then visit `https://yourdomain.com/` — you will be prompted for the `WEB_PASSWORD` you set in `.env`.

For a quick local preview:

```bash
php -S localhost:8080
# open http://localhost:8080
```

---

## What gets skipped

The following Shopify orders are intentionally excluded from the comparison. Each skipped order is recorded with a `_skip_reason` for transparency in the reports.

| Skip reason | Condition | Why |
|---|---|---|
| `cancelled` | `cancelled_at` is set | Order was cancelled — never goes to ShipStation |
| `financial` | `financial_status` is `pending`, `voided`, or `refunded` | Unpaid, reversed, or refunded — not actionable |
| `fulfilled` | `fulfillment_status` is `fulfilled` | All items have been shipped; order is complete |
| `restocked` | `fulfillment_status` is `restocked` | Items were returned and restocked after shipment (Shopify sets this on return/void post-dispatch) |
| `on_hold` | Any fulfillment order has `status: on_hold` | Fulfillment was deliberately paused by the merchant — not a missing order |
| `zero_value` | `total_price == 0` | Digital downloads, gift cards, fully-discounted orders |
| `no_shipping` | `shipping_lines` is empty | No physical shipment needed (digital delivery, local pickup) |
| `ignored` | Manually dismissed via the dashboard | One-off exceptions added by the team |

### Note on `on_hold`

Shopify does not expose hold status on the order object itself — it lives on the **Fulfillment Order** level and requires a separate API call (`/orders/{id}/fulfillment_orders.json`). To keep the request count low, this check runs only against orders already flagged as missing (typically 5–10 per day). Results are cached per order ID, so historical re-runs (months back) do not repeat calls already made.

---

## File structure

```
├── audit.php           — CLI entry point
├── index.php           — Web dashboard
├── .env                — Credentials and config (not committed)
├── src/
│   ├── Shopify.php     — Shopify Admin REST API client
│   ├── ShipStation.php — ShipStation API client
│   ├── Comparator.php  — Filtering and comparison logic (no I/O)
│   ├── Reporter.php    — Output formatting (CSV, TXT, console)
│   └── Cache.php       — File-based JSON cache
├── cache/              — Cached API responses (TTL controlled by CACHE_TTL)
├── reports/            — Generated CSV and TXT reports
└── data/ignored.json   — Manually ignored order numbers
```

---

## Caching

API responses are cached under `cache/` as JSON files. The default TTL is 4 hours (`CACHE_TTL=14400` in `.env`). To force a fresh fetch, delete the relevant cache files or set `CACHE_TTL=0`.

On-hold status is cached per Shopify order ID indefinitely within the same TTL window — once an order's fulfillment orders are fetched, that result is reused across runs.
