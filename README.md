# ShipStation ↔ Shopify Order Audit

Compares every paid Shopify order against ShipStation and surfaces the ones that are missing. Runs as a daily cron job and exposes a password-protected web dashboard to browse reports, run on-demand audits, spot-check individual orders, and bulk-manage exceptions.

---

## How it works

1. Fetches all Shopify orders in the configured date range (cursor-paginated, up to 250/page)
2. Fetches all ShipStation orders in the same range (paginated, up to 500/page)
3. Filters out orders that should never appear in ShipStation (see [What gets skipped](#what-gets-skipped))
4. For any order not found in ShipStation, checks whether it is on hold in Shopify (separate Fulfillment Orders API call, cached per order ID)
5. Diffs the two sets and flags any Shopify orders genuinely missing from ShipStation
6. Saves a CSV report under `reports/`
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

Edit `.env` and fill in all required values:

| Variable | Required | Where to get it |
|---|---|---|
| `SS_API_KEY` | ✅ | ShipStation → Settings → API |
| `SS_API_SECRET` | ✅ | Same page |
| `SHOPIFY_STORE` | ✅ | The subdomain part of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | ✅ | Shopify → Apps → Develop apps → your app → Admin API access token |
| `WEB_USERNAME` | — | Dashboard login username (default: `admin`) |
| `WEB_PASSWORD` | ✅ | Choose any password for the dashboard |
| `CACHE_TTL` | — | Cache duration in seconds (default: `82800` = 23 hours). Set to `0` to disable. |
| `APP_TITLE` | — | Browser tab title (default: `SS ↔ Shopify Audit`) |
| `APP_BRAND` | — | Sidebar and login logo text (default: `SS ↔ Shopify`) |

#### Creating a Shopify access token

1. In Shopify Admin go to **Settings → Apps and sales channels → Develop apps**
2. Click **Create an app** and give it a name (e.g. "ShipStation Audit")
3. Go to **Configuration → Admin API integration → Edit**
4. Enable scopes: `read_orders`, `read_fulfillments`
5. Click **Save**, then **API credentials → Install app**
6. Copy the **Admin API access token** — it is shown only once

### 2. Point your web server at the repo

The `index.php` at the root is the dashboard entry point. Set your document root to the repo directory, or run locally:

```bash
php -S localhost:8080
# open http://localhost:8080
```

Log in with the username and password you set in `.env`. On first visit with no reports yet, click **Run first audit** to generate the initial report.

### 3. Run the audit manually (CLI)

```bash
php audit.php
```

Override the date window (default: last 90 days):

```bash
AUDIT_START_DATE=2025-01-01 AUDIT_END_DATE=2025-03-31 php audit.php
```

Spot-check specific order numbers without running a full audit:

```bash
php audit.php --spot-check 164777,164789
```

Exit codes: `0` = all clear, `1` = missing orders found, `2` = script error.

### 4. Schedule via cron

Run once a day at 06:00 and append output to a log file:

```cron
0 6 * * * cd /var/www/ShipStation-Shopify-Checker && php audit.php >> logs/audit.log 2>&1
```

---

## Dashboard pages

| Page | What it does |
|---|---|
| **Reports** | Browse historical CSV reports. Click any date in the sidebar to load that report. Download as CSV. Re-audit any past date with one click. |
| **Run Audit** | Run a live audit for any date range directly from the browser. Shows a progress indicator while fetching. Results are saved as a new report. |
| **Trends** | Aggregated stats across all reports: average missing count, worst day, repeat offenders. Includes a full history bar chart and a bulk-ignore table for chronic missing orders. |
| **Spot-check** | Look up one or more specific order numbers in ShipStation in real time. Found orders link directly to ShipStation. |
| **Ignored** | View and manage all ignored orders. Bulk-unignore with checkboxes. Import a list of order numbers to ignore via CSV upload. |
| **Settings** | Test API connectivity for both platforms, view current `.env` configuration. |

### Missing order actions

Each missing order row has:
- A **Shopify admin link** to the order
- A **Search SS** link that opens ShipStation filtered to that order number
- A **Seen** badge showing how many reports the order has appeared in (`2×` = yellow, `3×+` = red)
- An **Ignore** button to permanently exclude it from future reports (with optional reason)
- A **checkbox** for selecting multiple orders to bulk-ignore in one action

---

## What gets skipped

The following Shopify orders are intentionally excluded from the comparison.

| Skip reason | Condition | Why |
|---|---|---|
| `cancelled` | `cancelled_at` is set | Order was cancelled — never goes to ShipStation |
| `financial` | `financial_status` is `pending`, `voided`, or `refunded` | Unpaid, reversed, or refunded — not actionable |
| `fulfilled` | `fulfillment_status` is `fulfilled` | All items have been shipped; order is complete |
| `restocked` | `fulfillment_status` is `restocked` | Items returned and restocked after shipment |
| `on_hold` | Any fulfillment order has `status: on_hold` | Fulfillment deliberately paused — not a missing order |
| `zero_value` | `total_price == 0` | Digital downloads, gift cards, fully-discounted orders |
| `no_shipping` | `shipping_lines` is empty | No physical shipment needed |
| `ignored` | Manually dismissed via the dashboard | One-off exceptions added by the team |

> **Note on `on_hold`:** Shopify does not expose hold status on the order object itself — it lives on the Fulfillment Order level and requires a separate API call (`/orders/{id}/fulfillment_orders.json`). This check runs only for orders already flagged as missing. Results are cached per order ID to avoid redundant calls on re-runs.

---

## Caching

API responses are cached under `cache/` as JSON files keyed by platform and date range. The default TTL is 23 hours (`CACHE_TTL=82800`) — aligned with the daily cron schedule so each run fetches fresh data. Repeated runs within the same day reuse the cache automatically.

To force a fresh fetch: use **Clear all cache** in the Run Audit page, or set `CACHE_TTL=0` in `.env`.
