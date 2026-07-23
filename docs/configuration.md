# Configuration

## Environment variables

| Variable | Required | Notes |
|---|---|---|
| `SHOPIFY_STORE` | ✅ | Subdomain of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | ✅ | Shopify Admin API access token |
| `SS_API_KEY` | - | ShipStation → Settings → API (required for audit/push features) |
| `SS_API_SECRET` | - | Same page |
| `WEB_PASSWORD` | ✅ | Dashboard login password. Plain text is supported for compatibility; a PHP `password_hash()` value is also accepted. |
| `WEB_USERNAME` | - | Login username (default: `admin`) |
| `CACHE_TTL` | - | Cache duration in seconds (default: `82800` = 23 h). Set to `0` to disable. |
| `APP_TITLE` | - | Label shown in browser tab and sidebar as `{APP_TITLE} - Shopify OPS` (default: `Shopify OPS`) |
| `APP_LOGO` | - | URL to an image that replaces the brand text |
| `APP_STORE_NUMBER` | - | Store number - shown as subtitle on login and in the browser tab |
| `SLACK_WEBHOOK_URL` | - | Slack Incoming Webhook URL. When set, completed audits send a concise summary to Slack. |
| `METRICS_TOKEN` | - | Bearer/query token for `metrics.php`. If empty, the endpoint is disabled by default. |
| `METRICS_ALLOW_PUBLIC` | - | Set to `1` only for local/dev public metrics without `METRICS_TOKEN`. |

---

## Caching

API responses are cached under `cache/` as JSON files keyed by platform and date range. Default TTL: 23 hours (`CACHE_TTL=82800`). Repeated runs within the same day reuse the cache automatically.

To force a fresh fetch: **Clear all cache** in the Run Audit page, or set `CACHE_TTL=0` in `.env`.

## Background jobs

The Job Queue stores pending audit jobs under `data/jobs.json` (or `data/<store>/jobs.json` in multi-store mode). Queue an audit from **Run Audit** or **Job Queue**, then process one pending job:

```bash
php worker.php --once
```

For multi-store mode, process a specific store queue:

```bash
php worker.php --store store_id --once
```

Schedule that command from cron when you want queued audits to run automatically.

## Slack rules

Set `SLACK_WEBHOOK_URL` in `.env`, then configure thresholds in **Settings → Slack Rules**.

- Audit notifications can require a minimum missing-order count.
- All-clear audit notifications can be disabled.
- Scan notifications are optional and default to off to avoid noisy channels.

## Tag policy rules

`Tag Policy Audit` is enabled by creating `tag_policy.json` from `tag_policy.example.json`.

```json
{
  "required": [
    { "name": "Express orders need priority review", "when": ["express"], "must_have": ["priority-review"] }
  ],
  "forbidden": [
    { "name": "Wholesale cannot be fraud review", "tags": ["wholesale", "fraud-review"] }
  ]
}
```

---

## Security

- Username/password authentication stored in `.env`; set a non-placeholder `WEB_PASSWORD`
- 3 failed login attempts per IP triggers a 1-week lockout (manageable from Settings)
- `metrics.php` requires `METRICS_TOKEN` by default; use `METRICS_ALLOW_PUBLIC=1` only for local/dev deployments.
- All user-supplied values escaped with `htmlspecialchars`
- Protect runtime directories from direct web access:

```apache
<DirectoryMatch "^/var/www/shopify-ops/(reports|cache|data|logs)/">
    Require all denied
</DirectoryMatch>
```

If `data/users.json` does not exist, the legacy `.env` login is disabled outside
localhost when `WEB_PASSWORD` is missing or still set to `changeme` /
`change_me_now`.

---

## Creating a Shopify access token

1. Shopify Admin → **Settings → Apps and sales channels → Develop apps**
2. **Create an app**, then **Configuration → Admin API integration → Edit**
3. Enable scopes: `read_orders`, `read_fulfillments`, `read_metaobjects`
4. **Save** → **API credentials → Install app**
5. Copy the **Admin API access token** - shown only once
