# ShipStation ↔ Shopify Order Audit

Compares every paid Shopify order against ShipStation and surfaces the ones that are missing. Runs as a daily cron job and exposes a password-protected web dashboard to browse historical reports.

---

## How it works

1. Fetches all orders from Shopify (date range, cursor-paginated)
2. Fetches all orders from ShipStation (date range, paginated)
3. Skips orders that are cancelled or not yet paid — they should never be in ShipStation
4. Diffs the two sets and flags Shopify orders not found in ShipStation
5. Saves a CSV + TXT report under `reports/`
6. The web dashboard reads those reports and displays them

---

## Requirements

- PHP 8.1+ with the `curl` extension
- A web server (Apache / Nginx / Caddy) or `php -S` for local use
- ShipStation API credentials
- Shopify Admin API access token

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

The following Shopify orders are intentionally excluded from the comparison:

- **Cancelled** orders (`cancelled_at` is set)
- Orders with `financial_status` of `pending`, `voided`, or `refunded`

---