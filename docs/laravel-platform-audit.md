# Laravel rewrite — platform and extras audit

Последно обновяване: **2026-09-07**.

Този checklist покрива всичко извън 72-та видими tools: authentication,
notifications, health, persistence, jobs, exports, observability, deployment и
security. Feature matrix-ът остава в [Laravel rewrite плана](laravel-rewrite.md),
а legacy test mapping-ът е в [test audit-а](laravel-test-audit.md).

Статуси: **Done** означава работещ production contract с тестове; **Foundation**
означава наличен Laravel scaffold, но липсва крайният workflow; **Todo** означава
че работата още не е започната. Framework config файл сам по себе си не прави
capability-то готово.

## Identity и достъп

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Password login/logout | Done | Session auth, credential validation, session regeneration и login throttle | Финална production proxy/TLS проверка се следи в hardening |
| Roles и authorization | Done | Viewer/operator/admin gates, route protection и admin authorization tests | Пълната legacy action-permission method mapping остава в test audit-а |
| Multi-store access | Done | Membership, active-store middleware и store-scoped credentials | Всички бъдещи routes/jobs задължително получават isolation тест |
| First administrator | Done | Fresh-install Artisan command с atomic validation | Deployment runbook трябва да включи изпълнението му |
| Google OAuth | Todo | Няма provider, routes или callback | Socialite/provider setup; redirect и callback; state validation; allowed-domain policy; existing/new-user policy; disabled/incomplete config UX; session rotation; rate limit; safe OAuth errors; feature tests без реална мрежа |
| Lockout и banned IP management | Todo | Laravel login throttle покрива краткото ограничаване | Решение дали persistent ban/unban parity е нужна; admin UX, trusted-proxy rules, audit log и tests при запазване |
| Security headers/cookies/proxy | Partial | Laravel session/CSRF defaults и application middleware | CSP/frame/referrer/HSTS policy, secure cookie settings и trusted proxies, проверени зад production TLS proxy |

## Email, SMTP и notifications

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| SMTP transport | Partial | Admin diagnostic показва mailer/from status и изпраща валидирано test писмо през SMTP; 10-second timeout, rate limit, safe failure log и fake tests | Production secrets/deployment configuration и реален staging delivery smoke test |
| Audit email notifications | Todo | Няма mailables/notifications | HTML + text templates, recipients, subject/count wording, escaping, retry policy и duplicate-delivery protection |
| CSV email attachments | Todo | Няма export attachment flow | Safe filename, CSV injection protection, encoding, MIME/size limits и memory-safe generation |
| Per-tool email rules | Todo | Няма schema/UX | Mode off/immediate/digest, threshold, include-zero, recipient override, defaults, authorization и persistence |
| Daily email digest | Todo | Няма scheduled digest job | Latest-run selection, grouping by recipient, threshold rules, timezone/day boundary, idempotency и scheduler test |
| Slack notifications | Todo | Няма channel adapter | Webhook config, per-tool rules, mentions, payload limits/escaping, retry/safe failure и duplicate protection |
| Discord notifications | Todo | Няма channel adapter | Webhook config, embeds/payload limits, escaping, retry/safe failure и duplicate protection |
| Notification delivery log | Todo | Няма persistence | Provider, recipient, report/run ID, attempt/status/error category; без secrets или чувствителен payload |

## Health, metrics и observability

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Liveness endpoint | Done | Laravel `/up` и feature test | Да остане евтин, без външни API calls |
| Readiness endpoint | Partial | `/ready` проверява database connection и queue configuration и връща 200/503 без secrets | Worker freshness и cache readiness след изграждането на worker/production cache foundation |
| API Health page | Partial | Admin-only live checks за Shopify shop/scopes и ShipStation auth, per-store isolation, latency, safe errors и rate limit | Shopify returned-version header и flow monitor върху persisted run history |
| Webhook Health page | Todo | Няма webhook adapter/state | Shopify webhook discovery, required topics, target URL, delivery/recency state, per-store results и remediation text |
| Metrics endpoint | Todo | Няма endpoint | Authentication, stable metric names, request/job/API/error/notification counters, no PII и scrape test |
| Structured application logs | Partial | Laravel logging и безопасни warnings в текущите reports | Общ context contract: request/run/store/tool IDs, error category/status, redaction tests и production channel/retention |
| Run history | Todo | Няма DB модел/екран | Status, counts, duration, range, store/tool, error category, newest-first retention и authorization |
| Action audit log | Todo | Няма DB workflow | Actor, store, action, target, timestamp, outcome; mutation coverage и admin view |
| Operational alerts | Todo | Няма правила | Failed jobs, queue latency, repeated API failures, scheduler absence и notification-delivery failures |

## Jobs, scheduler и recovery

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Queue storage | Foundation | Laravel jobs/failed_jobs migrations и queue config | Избран production connection, worker config и health visibility |
| Audit jobs | Todo | Reports се изпълняват синхронно | `RunAudit` use case, store/tool/range payload, timeouts, backoff, progress и terminal states |
| Idempotency/concurrency | Todo | Няма persisted keys | Unique key по store/tool/range, overlap protection, atomic claim и safe retry tests |
| Failed-job recovery | Todo | Framework таблица е налична | Retry/cancel UX или CLI policy, failure classification, no duplicate side effects и runbook |
| Scheduler | Todo | Няма application schedules | Scheduled audits, digest, pruning/housekeeping, timezone policy и `withoutOverlapping`/single-server решение |
| Worker deployment | Todo | Няма production service definition | Start/restart/stop, deploy restart, graceful timeout, process supervision и rollback-independent fix-forward runbook |

## Persistence, settings и state

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Users/stores/credentials | Done | DB models/migrations, encrypted integration credentials и admin CRUD | Backup/restore и production secret rotation instructions |
| Notification settings | Todo | Няма schema | Email/Slack rules, recipients, defaults и validation migrations |
| Sidebar preferences | Todo | Няма schema/UI | Per-user/store visibility defaults and persistence |
| Ignore/unignore orders | Todo | Няма schema/UI | Normalization, single/bulk mutation, optional expiry, authorization и audit trail |
| Audit snapshots | Todo | Няма schema/repository | Per-store/tool/date uniqueness, history ordering, retention и result metadata |
| Report persistence | Todo | Няма persisted artifacts | Ownership/store scope, immutable metadata, retention, cleanup и failed-write behavior |
| Cache policy | Foundation | Laravel cache config | Key namespacing by store/query, TTL matrix, locks, invalidation, corruption/failure strategy и tests |
| Legacy runtime state | Done decision | Изрично няма import на legacy users/jobs/logs/cache/reports/settings | Cutover checklist да потвърди чиста база и липса на runtime dependency |

## Exports и mutable workflows

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| CSV/report downloads | Todo | Няма общ export service | Authorization/store ownership, formula injection protection, UTF-8, safe names/headers, large dataset streaming и expiry |
| Shopify → ShipStation push | Todo | Няма create-order workflow | Preview, exact Shopify match, payload mapping, idempotency key, duplicate prevention, partial failure и action log |
| Shopify order note update | Todo | Няма mutation workflow | Valid order ID/store, GraphQL user errors, CSRF/authorization, audit log и safe retry decision |
| Print queue | Todo | Само packing-slip preview е готов | Persisted enqueue/order/remove, duplicate policy, recovery, authorization и printable artifact linkage |

## Configuration, CI и deployment

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Application install | Done | Composer/NPM Laravel app и install command | Production runbook с migrations, assets, storage link и initial admin/store setup |
| Configuration validation | Partial | Laravel config и request-level credential guards | Startup/deploy validation за app URL/key, DB, queue, mail, OAuth, proxy и notification settings |
| CI checks | Partial | Текущият project има работещ test/Pint/build baseline | Задължителни PHPUnit, Pint, static analysis, frontend build и dependency/security audit gates |
| Backup and restore | Todo | Няма runbook | DB/artifact backup, restore rehearsal, retention and ownership |
| Deployment runbook | Todo | Няма production procedure | Maintenance/write freeze, migrate, build, cache config/routes, worker restart, scheduler, smoke checks и fix-forward |
| Production observability | Todo | Няма готов operational stack | Log destination/retention, metrics scrape, dashboards, alert ownership и escalation |
| UAT и cutover rehearsal | Todo | Планът изисква две репетиции | Golden fixtures, production-sized run, sign-off evidence и irreversible cutover checklist |

## Общ release gate за extras

- [ ] Всеки ред е `Done` или има изрично прието отклонение.
- [ ] Няма реални Shopify, ShipStation, SMTP, Slack, Discord или Google заявки в tests.
- [ ] Secrets и PII не присъстват в responses, logs, metrics, jobs или export filenames.
- [ ] Всеки background side effect е idempotent и има retry/recovery test.
- [ ] Health и metrics работят при деградирала външна система и не разкриват credentials.
- [ ] Fresh install и production-like deployment са повторени по runbook.
- [ ] Test audit-ът е затворен за свързаните legacy files.
