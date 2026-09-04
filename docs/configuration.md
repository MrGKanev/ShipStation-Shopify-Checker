# Configuration

## Environment variables

| Variable | Required | Notes |
|---|---|---|
| `SHOPIFY_STORE` | ✅ | Subdomain of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | ✅ | Shopify Admin API access token |
| `SS_API_KEY` | - | ShipStation → Settings → API (required for audit/push features) |
| `SS_API_SECRET` | - | Same page |
| `WEB_PASSWORD` | Conditional | Dashboard login password. Not required when Google sign-in is fully configured. Plain text is supported for compatibility; a PHP `password_hash()` value is also accepted. |
| `WEB_USERNAME` | - | Login username (default: `admin`) |
| `GOOGLE_CLIENT_ID` | - | OAuth 2.0 Web application client ID from Google Cloud. Required to enable Google sign-in. |
| `GOOGLE_CLIENT_SECRET` | - | OAuth client secret. Required to enable Google sign-in. |
| `GOOGLE_REDIRECT_URI` | - | Exact callback URL registered in Google Cloud, for example `https://ops.example.com/?auth=google_callback`. |
| `GOOGLE_ALLOWED_DOMAINS` | - | Comma-separated Google Workspace domains allowed to sign in, for example `example.com,subsidiary.com`. |
| `GOOGLE_DEFAULT_ROLE` | - | RBAC role assigned to Google users: `viewer` (default), `operator`, or `admin`. |
| `GOOGLE_LOGIN_ONLY` | - | Set to `1` to hide and disable username/password login outside the localhost quick-login path. |
| `TRUSTED_PROXIES` | - | Comma-separated proxy IPs/CIDRs whose forwarded HTTPS and client-IP headers may be trusted. Leave empty when not behind a proxy. |
| `SESSION_IDLE_TIMEOUT` | - | Authenticated-session idle timeout in seconds (default: `1800`). |
| `SESSION_ABSOLUTE_TIMEOUT` | - | Maximum authenticated-session lifetime in seconds (default: `43200`). |
| `STATE_STORAGE` | - | `sqlite` (default) for jobs/operator audit state, or `json` for rollback. |
| `CACHE_TTL` | - | Cache duration in seconds (default: `82800` = 23 h). Set to `0` to disable. |
| `APP_TITLE` | - | Label shown in browser tab and sidebar as `{APP_TITLE} - Shopify OPS` (default: `Shopify OPS`) |
| `APP_LOGO` | - | URL to an image that replaces the brand text |
| `APP_STORE_NUMBER` | - | Store number - shown as subtitle on login and in the browser tab |
| `SLACK_WEBHOOK_URL` | - | Slack Incoming Webhook URL. When set, completed audits send a concise summary to Slack. |
| `SMTP_HOST` | - | SMTP server host. Required (with `ALERT_EMAIL`) for any email notification/report feature. |
| `SMTP_PORT` | - | SMTP port (default: `587`) |
| `SMTP_USER` / `SMTP_PASS` | - | SMTP auth credentials |
| `SMTP_FROM` | - | From address (defaults to `SMTP_USER`) |
| `SMTP_SECURE` | - | `tls` or `ssl` (default: `tls`) |
| `ALERT_EMAIL` | - | Default recipient for audit/scan/digest emails. Per-tool rules can override this in **Settings → Email Rules**. |
| `METRICS_TOKEN` | - | Bearer/query token for `metrics.php`. If empty, the endpoint is disabled by default. |
| `METRICS_ALLOW_PUBLIC` | - | Set to `1` only for local/dev public metrics without `METRICS_TOKEN`. |

---

## Caching

API responses are cached under `cache/` as JSON files keyed by platform and request shape. `CACHE_TTL` is the maximum cache duration (default: 23 hours / `82800` seconds). Heavy full-order audits use that maximum; operational data has shorter caps so it does not become stale:

- order scan results, product catalog and shipment date-range reports: 15 minutes;
- Shopify event scans: 5 minutes;
- active ShipStation queues and targeted order lookups: 60 seconds;
- Shopify metafield definitions and report-history summaries: 1 hour.

Repeated runs inside those windows reuse cached data automatically. A cache miss is locked per key, so concurrent requests wait for one fetch instead of each starting the same paginated API sync.

To force a fresh fetch: **Clear all cache** in the Run Audit page, or set `CACHE_TTL=0` in `.env`.

## Background jobs

The Job Queue and operator action log use `data/state.sqlite` (or `data/<store>/state.sqlite` in multi-store mode). On first use, existing `jobs.json` and `user_action_log.json` rows are imported automatically. The JSON files are retained unchanged as rollback copies; set `STATE_STORAGE=json` to switch back. Queue an audit from **Run Audit** or **Job Queue**, then process one pending job:

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

## Email rules

Set `SMTP_HOST` and `ALERT_EMAIL` in `.env`, then configure each check individually in **Settings → Email Rules**. Unlike Slack (one shared toggle for "audit" and one for "all scans"), every audit/scan check gets its own row:

- **Off** (default) - never emails.
- **Immediate** - emails right after that check's own run, once its row/missing count clears the threshold.
- **Digest** - held for a once-daily rollup email instead of firing per-run. Requires scheduling `email_digest.php` via cron (see [cron scheduling](../README.md#5-schedule-via-cron)); without that cron entry, digest-mode checks are recorded but never actually emailed.

Each check can also override the recipient - leave its email field blank to fall back to `ALERT_EMAIL`.

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

- Google sign-in uses the authorization-code flow with PKCE and a one-time, 10-minute `state` value.
- Google OAuth starts are rate-limited to 10 attempts per 10-minute session window.
- Domain access is checked server-side against Google's verified Workspace `hd` claim. The email suffix and the account-picker hint are not trusted for authorization.
- Authenticated sessions expire after 30 idle minutes or 12 hours total by default.
- Responses set CSP, clickjacking, MIME-sniffing, referrer and browser-permission protections; HTTPS responses also set HSTS.
- Forwarded protocol/client-IP headers are used only when the direct peer matches `TRUSTED_PROXIES`.
- Username/password authentication remains available unless `GOOGLE_LOGIN_ONLY=1`; set a non-placeholder `WEB_PASSWORD` when using it.
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

## Google sign-in

1. In Google Cloud Console, create an **OAuth client ID** with application type **Web application**.
2. Add the exact `GOOGLE_REDIRECT_URI` to **Authorized redirect URIs**. The scheme, host, path, and query string must match the deployed value.
3. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, and `GOOGLE_ALLOWED_DOMAINS` in `.env`.
4. Optionally set `GOOGLE_DEFAULT_ROLE=operator` (or `admin`) and `GOOGLE_LOGIN_ONLY=1`.

Example:

```dotenv
GOOGLE_CLIENT_ID=123456789.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxxxxxxxxxx
GOOGLE_REDIRECT_URI=https://ops.example.com/?auth=google_callback
GOOGLE_ALLOWED_DOMAINS=example.com,subsidiary.com
GOOGLE_DEFAULT_ROLE=viewer
GOOGLE_LOGIN_ONLY=1
```

Accounts authenticated by Google but outside the allowlist are redirected to a dedicated access-denied page. Multiple domains are supported; separate them with commas.

---

## Creating a Shopify access token

1. Shopify Admin → **Settings → Apps and sales channels → Develop apps**
2. **Create an app**, then **Configuration → Admin API integration → Edit**
3. Enable scopes: `read_orders`, `read_fulfillments`, `read_metaobjects`
4. **Save** → **API credentials → Install app**
5. Copy the **Admin API access token** - shown only once
