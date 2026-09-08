# Shopify Ops

A self-hosted Shopify operations toolkit. Audits and surfaces Shopify order issues, provides search and lookup tools, and optionally syncs with ShipStation for order matching and push. Runs on plain PHP - no framework, no build step.

> **Laravel rewrite:** This plain-PHP application is the stable production line.
> Its replacement is being built separately in [`laravel/`](laravel/). See the
> [rewrite plan](docs/laravel-rewrite.md) for scope and progress.

> **Shopify is the only required integration.** Most pages work with a Shopify access token alone. ShipStation credentials are optional - needed only for the audit engine, push log, and order matching features.

---

## Tools

- **Audit** - Run Audit, Reports, Trends, duplicate/refund/address/email/fraud/product/inventory checks → [full list](docs/tools.md#audit)
- **Search & Lookup** - Spot-check, Order Timeline, Metafields, Tag Search, Customer Lookup, Tracking, Packing Slip → [full list](docs/tools.md#search--lookup)
- **Manage** - Ignored Orders, Push Log, Settings → [full list](docs/tools.md#manage)

---

## Requirements

- PHP 8.5+ with the `curl` and `pdo_sqlite` extensions
- A web server (Apache / Nginx / Caddy) or `php -S` for local use
- Shopify Admin API access token (`read_orders`, `read_fulfillments`, `read_metaobjects` scopes)
- ShipStation API credentials _(optional - audit and push features only)_

---

## Setup

### 1. Clone & configure

```bash
git clone https://github.com/MrGKanev/Shopify-ops.git
cd Shopify-ops/
cp .env.example .env
```

Edit `.env` with your credentials. See [docs/configuration.md](docs/configuration.md) for all available variables.

### 2. Run locally

```bash
php -S localhost:8080
# open http://localhost:8080
```

### 3. Run the audit via CLI

```bash
php audit.php
```

Override the date window (default: last 90 days):

```bash
AUDIT_START_DATE=2025-01-01 AUDIT_END_DATE=2025-03-31 php audit.php
```

Exit codes: `0` = all clear, `1` = missing orders found, `2` = script error.

### 4. Process queued background jobs

Audits can be queued from the dashboard and processed outside the web request:

```bash
php worker.php --once
```

For multi-store installs:

```bash
php worker.php --store store_id --once
```

### 5. Schedule via cron

```cron
0 6 * * * cd /var/www/shopify-ops && php audit.php >> logs/audit.log 2>&1
*/5 * * * * cd /var/www/shopify-ops && php worker.php --once >> logs/worker.log 2>&1
0 9 * * * cd /var/www/shopify-ops && php email_digest.php >> logs/digest.log 2>&1
```

---

## Further reading

- [Audit engine - how it works, skip rules, duplicate detection](docs/audit.md)
- [Audit checks - address, email, fraud, product, inventory checks](docs/audit-checks.md)
- [Search & Lookup - spot-check, timeline, metafields, tags, customer](docs/search-lookup.md)
- [Order type classification - rules, JSON config, required items](docs/order-types.md)
- [Configuration - all ENV vars, caching, security](docs/configuration.md)
- [Laravel rewrite plan](docs/laravel-rewrite.md)

Added or edited an audit page? Run `composer docs` to regenerate the Audit table in [docs/tools.md](docs/tools.md) from `ToolRegistry` - `composer test` fails if it's out of sync.
