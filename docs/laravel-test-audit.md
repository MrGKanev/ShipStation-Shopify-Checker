# Laravel rewrite — legacy test audit

Последно обновяване: **2026-09-08** след Laravel Pulse slice-а.

Този документ е отделният checklist за тестова parity. Feature статусът се следи
в [Laravel rewrite плана](laravel-rewrite.md), а тук се затваря всеки legacy test
файл. Един файл се отбелязва като готов само след method-level сверка: всеки стар
contract има Laravel тест, по-силен еквивалент или записана причина за отпадане.

## Състояние

| Статус | Файлове | Дял от 115 |
|---|---:|---:|
| Готови | 21 | 18.3% |
| Частично покрити | 27 | 23.5% |
| Непочнати | 67 | 58.3% |
| **Оставащи за одит** | **94** | **81.7%** |

Legacy baseline: **115 файла · 1,528 теста · 3,659 assertions**. Laravel
baseline след последния slice: **452 теста · 1,889 assertions**. Броят assertions
е ориентир; критерият е поведенческо покритие.

За всеки checkbox проверяваме business decisions, boundary интеграцията,
authorization и store isolation, validation, escaping, pagination/truncation,
malformed payloads и atomic failure. Не копираме тест, който проверява изцяло
премахнат legacy implementation detail; записваме защо Laravel contract-ът го
заменя.

## Частично покрити — method-level одит остава

| Готово | Legacy файл | Тестове | Какво проверява | Какво остава |
|---|---|---:|---|---|
| [ ] | `AllViewsSmokeTest.php` | 1 | Всеки регистриран legacy екран се отваря | Добавяне на всеки оставащ Laravel екран към route/render smoke покритието |
| [ ] | `ApiHealthTest.php` | 8 | Shopify/ShipStation credentials, scopes и live HTTP checks | Returned Shopify API version header; след това 8/8 method mapping |
| [ ] | `AuthPermissionSnapshotTest.php` | 2 | Точната action → permission матрица и пълнотата ѝ | Сверка на всички actions срещу Laravel policies/routes |
| [ ] | `AuthTest.php` | 40 | Пароли, lockout/IP ban, users, CSRF и роли | Lockout/banned-IP и пълната permission матрица |
| [ ] | `AuthViewsTest.php` | 8 | Login режими, конфигурационни грешки, escaping и access denied | Branding и dedicated access-denied cases |
| [ ] | `GraphQL/EventNormalizerTest.php` | 28 | Нормализация на всички Shopify order event типове | Поле-по-поле сверка на останалите event variants |
| [ ] | `GraphQL/IdsTest.php` | 17 | Numeric/GID преобразуване и невалидни ID стойности | Общ reusable Laravel ID contract извън текущите order paths |
| [ ] | `GraphQL/OrderComponentNormalizerTest.php` | 27 | Address, item, shipping, fulfillment, refund и discount нормализация | Shipping/refund/discount полета и edge cases |
| [ ] | `GraphQL/OrderDirectLookupTest.php` | 8 | Single/batch lookup, cleaning, misses и cache | Full returned field set и cache-equivalent contract |
| [ ] | `FraudComplianceChecksTest.php` | 22 | Country mismatch, high value/no phone и email checker rules | Остава method-level финална сверка на общия файл след трите готови отчета |
| [ ] | `GraphQL/OrderEventLookupTest.php` | 3 | Event lookup, pagination и missing order | Exact normalized event mapping |
| [ ] | `GraphQL/OrderNormalizerTest.php` | 39 | Всички основни и optional order fields | Tax, refunds, discounts, attributes, journey/source и support fields |
| [ ] | `HttpAuthEndpointTest.php` | 1 | Endpoint auth contract | Пълна route/method/session еквивалентност |
| [ ] | `OrderInsightPageLoaderTest.php` | 12 | Compare, timeline и допълнителни order insights | Непренесените insight branches и failure states |
| [ ] | `OrderTimelineTest.php` | 26 | Timeline events, ordering, labels и risk signals | Explicit mapping на всички 26 метода |
| [ ] | `OrderPolicyPageLoaderTest.php` | 22 | Policy-report inputs, wiring, configuration и error states | Discount Abuse, Same IP, Tag Policy, Duplicate Shipping Addresses, Note Flags и Order Edit paths са покрити; останалите policy reports чакат method-level сверка |
| [ ] | `ProductInventoryPageLoaderTest.php` | 32 | Wiring за catalogue/inventory report страниците | Оставащите catalogue workflows и финална method-level сверка |
| [ ] | `ReporterTest.php` | 19 | CSV/JSON output, summaries и filenames | Общият streamed CSV writer, filename sanitation и formula escaping са готови; JSON, summaries, attachments и всички report schemas остават |
| [ ] | `UserActionLogTest.php` | 3 | Action history, pruning и legacy import | DB-backed admin Action Log, safe model changes, credential rotation metadata, authorization и scheduled retention са готови; legacy import остава |
| [ ] | `RiskScorerTest.php` | 33 | Fraud risk сигнали, weights и score bands | Custom weights и explicit mapping на всички methods |
| [ ] | `SearchLookupPageLoaderTest.php` | 19 | Lookup/compare/timeline dispatch, validation и errors | Останалите search tools и всички loader branches |
| [ ] | `SecurityTest.php` | 5 | Proxy trust, sessions, rolling rate limit и headers | Full security checklist срещу Laravel middleware/config |
| [ ] | `SlackNotifierTest.php` | 19 | Slack payloads, mentions, delivery и safe failure | Queue-ready webhook channel, admin-only delivery diagnostic, trusted endpoint validation и credential-free test payload са готови; audit/scan payloads, mentions и retry mapping остават |
| [ ] | `ShipStationClientTest.php` | 23 | Auth, lookup, retries, create, active/awaiting/shipment fetch и cache | Create order, active/voided/date fetch, cache/checkpoint semantics |
| [ ] | `ShopifyClientTest.php` | 58 | Shopify queries, mutations, retries, cache и всички report fetchers | Method-level mapping за непреместените APIs и update mutation |
| [ ] | `StoresTest.php` | 7 | Multi-store file config и session selection | Accepted replacement mapping към DB stores и active-store middleware |
| [ ] | `ViewSmokeTest.php` | 6 | Populated/empty report view states | Оставащите risk, same-IP и disputes views |

## Непочнати — application и infrastructure

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [ ] | `ActionsTest.php` | 30 | POST action parsing, user/date validation, connection checks, push preview/order note → Form Requests, controllers и services |
| [ ] | `AtomicFileTest.php` | 8 | Atomic write/JSON/permissions/failure cleanup → класифициране като replaced или persistence equivalent |
| [ ] | `AuditSnapshotTest.php` | 9 | Save/load/history/limits на audit snapshots → DB snapshot repository |
| [ ] | `AuditTest.php` | 3 | Success/error execution logging → report runner failure/audit logging |
| [ ] | `AutoloadCoverageTest.php` | 1 | Всеки source symbol се autoload-ва → Composer/Laravel discovery gate |
| [ ] | `CacheTest.php` | 47 | TTL, locking, corruption, pruning и namespaces → Laravel cache/lock policy и integration tests |
| [ ] | `ConfigValidatorTest.php` | 35 | Environment, stores, order types и tag policy validation → Laravel config/admin validation |
| [ ] | `DateRangeTest.php` | 10 | Input precedence, ISO validation и date arithmetic → shared Form Request/value object tests |
| [ ] | `DocsGeneratorTest.php` | 1 | Registry и tools документацията не се разминават → route/feature tracker consistency check |
| [ ] | `IgnoreListTest.php` | 16 | Ignore CRUD, normalization, expiry и persistence → ignore-list model/repository |
| [ ] | `JobQueueTest.php` | 7 | Enqueue, reserve, retry и completion → Laravel queue jobs |
| [ ] | `JsonFileLockTest.php` | 6 | Locking, timeout и release при failure → replaced от DB/cache locks или equivalent |
| [ ] | `LoggerTest.php` | 7 | Structured logging, redaction и rotation → Laravel logging config/tests |
| [ ] | `MetricsEndpointTest.php` | 4 | Metrics auth/content/counters → operational metrics endpoint |
| [ ] | `OrphanDetectorTest.php` | 7 | Orphaned runtime files и cleanup candidates → migration/cleanup decision |
| [ ] | `PrintQueueTest.php` | 7 | Queue persistence, ordering и removal → packing/print queue workflow |
| [ ] | `PushLogTest.php` | 3 | Push history append/order/limit → DB action log |
| [ ] | `ReportRegistryTest.php` | 7 | Report definitions, groups и defaults → Laravel report registry/navigation |
| [ ] | `RunLogTest.php` | 3 | Run history append/order/limit → DB run records |
| [ ] | `ScanRunnerTest.php` | 18 | Scan orchestration, notifications, snapshots и failures → queued report orchestration |
| [ ] | `ShopifyFlowHealthTest.php` | 7 | Per-tool run/error health aggregation → operational dashboard |
| [ ] | `SidebarSettingsTest.php` | 4 | Sidebar visibility defaults/persistence → user UI preferences |
| [ ] | `ToolRegistryTest.php` | 8 | Page titles/groups/full registry → route/navigation registry parity |
| [ ] | `ViewHelpersTest.php` | 83 | Formatting, badges, tables, forms, escaping и order links → Blade components/helpers |
| [ ] | `WorkerTest.php` | 11 | Store resolution, credentials и audit worker orchestration → tenant-aware Laravel jobs |

## Непочнати — authentication и notifications

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [ ] | `DiscordNotifierTest.php` | 17 | Configuration, payloads, escaping, delivery и safe failure → Discord notification channel |
| [ ] | `EmailDigestTest.php` | 10 | Daily selection, thresholds, grouping и latest run → scheduled digest job |
| [ ] | `EmailNotifierTest.php` | 30 | SMTP config, audit/scan/digest messages, escaping и attachments → Laravel mailables |
| [ ] | `EmailRulesTest.php` | 25 | Per-tool modes, thresholds, recipients и persistence → notification preference model |
| [ ] | `ManageSettingsPageLoaderTest.php` | 14 | Settings load/save, validation и authorization → admin settings workflow |
| [ ] | `SlackRulesTest.php` | 19 | Audit/scan thresholds, mentions, defaults и persistence → Slack preferences |

## Непочнати — order, fulfillment и logistics workflows

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [ ] | `ActiveSsConflictsTest.php` | 6 | Refunded/cancelled Shopify orders still active in ShipStation, dedupe и sorting |
| [ ] | `BundleCheckPageTest.php` | 9 | Missing bundle components и cancelled/refunded/pending/free/no-shipping exclusions |
| [ ] | `CarrierPerfTest.php` | 8 | Delivery averages, late boundary, bad/missing dates и carrier grouping |
| [ ] | `ComparatorTest.php` | 73 | Shopify↔ShipStation matching, exclusions, duplicates, bundles, shipped items, margin и hold behavior |
| [ ] | `CustomerLTVPageLoaderTest.php` | 28 | Revenue/customer cohorts, cancellation, identity normalization, retention and range wiring |
| [ ] | `FulfillmentIssuePageLoaderTest.php` | 36 | Loader contracts за fulfillment exceptions, filters, dates, credentials и failures |
| [ ] | `FulfillmentLogisticsChecksTest.php` | 17 | Partial fulfillment, stalls, tracking, carrier и shipping logistics decisions |
| [ ] | `ItemizedFulfillmentReportTest.php` | 21 | Fulfilled item quantities, dates, SKU/product grouping и filters |
| [ ] | `OnHoldStallTest.php` | 5 | Hold duration threshold, exclusions и sorting |
| [ ] | `OrderAnomalyPageLoaderTest.php` | 20 | Fraud/anomaly page dispatch, ranges, credentials, results и failures |
| [ ] | `PageLoaderTest.php` | 18 | Главен audit loader, compare results, ignore rules и notification behavior |
| [ ] | `PartialFulfillStallsTest.php` | 6 | Partial fulfillment age threshold, completed exclusions и sorting |
| [ ] | `PostShipAddrChangeTest.php` | 5 | Address edits after shipment, timing and sorting |
| [ ] | `RefundsTrackerTest.php` | 10 | Refund totals, status/date filters, item aggregation и sorting |
| [ ] | `ReturnRmaTrackerTest.php` | 7 | Return/RMA detection, age, statuses and sorting |
| [ ] | `ReturnedItemsReportTest.php` | 10 | Returned SKU quantities, refunds, dates and aggregation |
| [ ] | `SimpleScanPageLoaderTest.php` | 19 | Shared tag/tax/returns/email report loader, validation and notifications | Email wiring/credentials са покрити; returns и notification branches остават |
| [ ] | `SsShippedUnfulfilledTest.php` | 6 | ShipStation shipped while Shopify unfulfilled, exclusions and sorting |
| [ ] | `VoidedShipmentsTest.php` | 5 | Voided label rows, missing address tolerance and date sorting |

## Непочнати — Shopify GraphQL contracts

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [ ] | `GraphQL/AdminLookupsTest.php` | 3 | Facade delegation за order, metafield и customer lookups |
| [ ] | `GraphQL/CatalogAndFulfillmentTest.php` | 5 | Inclusive date filtering на catalogue/fulfillment data |
| [ ] | `GraphQL/CustomDataLookupsTest.php` | 6 | Metafield search, counts, samples, dedupe и query escaping |
| [ ] | `GraphQL/CustomerOrderInsightsTest.php` | 6 | Customer spend, identity selection, email normalization and defaults |
| [ ] | `GraphQL/DisputeLookupTest.php` | 4 | Dispute filters, normalization, pagination and missing order |
| [ ] | `GraphQL/DuplicateOrderInsightsTest.php` | 7 | Duplicate window boundary, amount/email matching and scanned count |
| [ ] | `GraphQL/MetafieldNormalizerTest.php` | 6 | Types, JSON, references and malformed metafield values |
| [ ] | `GraphQL/OrderArchiveTest.php` | 3 | Inclusive range query, pagination, normalization and cache |
| [ ] | `GraphQL/OrderAuditsTest.php` | 4 | Audit facade delegation към query/event fetchers |
| [ ] | `GraphQL/OrderEventAuditsTest.php` | 8 | Edited/address-change event selection, batching and ordering |
| [ ] | `GraphQL/OrderFetcherTest.php` | 6 | Generic pagination, normalization, cache and malformed responses |
| [ ] | `GraphQL/OrderHoldLookupTest.php` | 10 | Fulfillment hold detection, batching, pagination, IDs and cache |
| [ ] | `GraphQL/OrderInsightsTest.php` | 2 | Insight facade delegation for tags and duplicates |
| [ ] | `GraphQL/OrderLookupTest.php` | 4 | Lookup facade delegation for direct order, hold and events |
| [ ] | `GraphQL/OrderQueryAuditsTest.php` | 10 | Exact filters/fields за address, refund, fulfillment, fraud and cancellation fetches |
| [ ] | `GraphQL/ProductNormalizerTest.php` | 9 | Product/variant/image normalization and missing-field defaults |
| [ ] | `GraphQL/QueryStringsTest.php` | 2 | Exact partial-fulfillment GraphQL filters |

## Напълно сверени

- [x] `PackingSlipPageLoaderTest.php` — 6/6 legacy paths.
- [x] `TrackingFeedTest.php` — 7/7 builder contracts.
- [x] `GraphQL/OrderTagInsightsTest.php` — 8/8 tag search/statistics contracts.
- [x] `ProductCatalogueChecksTest.php` — 16/16 catalogue decisions.
- [x] `CatalogQualityTest.php` — 6/6 quality decisions.
- [x] `GiftCardPageLoaderTest.php` — 4/4 visible workflow/failure paths.
- [x] `GiftCardsTest.php` — 8/8 gift-card decisions.
- [x] `InventoryForecastTest.php` — 9/9 forecast decisions.
- [x] `ZombieProductsTest.php` — 7/7 zombie-product decisions.
- [x] `TaxAuditTest.php` — 6/6 zero-tax, exemption, minimum and sorting decisions.
- [x] `ConsentAuditTest.php` — 4/4 email/SMS consent, unknown and sorting decisions.
- [x] `FraudRiskReportTest.php` — 4/4 filtering, signal breakdown, Shopify risk and sorting decisions.
- [x] `AddressCheckTest.php` — 20/20 required fields, postal formats, province, PO Box, carrier and malformed-value decisions.
- [x] `AddressScannerPageTest.php` — 4/4 severity sorting, PO Box filter and clean-address decisions.
- [x] `SameIpTest.php` — 5/5 exact-IP grouping, distinct-email deduplication, exclusions and sorting decisions.
- [x] `OrderPolicyChecksTest.php` — 16/16 Discount Abuse и Tag Policy configuration, required/forbidden semantics и tag normalization decisions.
- [x] `DisputesPageLoaderTest.php` — 7/7 deadline computation, urgency sorting, initial/configuration and Shopify success paths.
- [x] `RepeatRefundsTest.php` — 8/8 threshold, successful-transaction totals, identity grouping and sorting decisions.
- [x] `AddressChangesTest.php` — 4/4 placement-to-change delay, negative/missing timestamp clamping and current-address output decisions.
- [x] `GoogleAuthFlowTest.php` — OAuth redirect/callback/state handling is delegated to Socialite; cancellation, provider failure, domain policy, existing-user linking, session rotation and throttled routes are covered.
- [x] `GoogleAuthTest.php` — the custom OIDC/PKCE HTTP client is replaced by Socialite; configuration, verified `hd` claims, domain parsing, identity binding and safe failures are covered without persisting provider tokens.

## Как се обновява

1. Преди нов workflow се отварят всички свързани редове и legacy test methods.
2. В PR/slice описанието се записва mapping: legacy method → Laravel test.
3. При частично покрит файл редът остава unchecked и „Какво остава“ се свива.
4. При пълна сверка редът се мести в „Напълно сверени“, а числата горе и в
   `laravel-rewrite.md` се обновяват.
5. След всеки slice се пуска целият Laravel suite; baseline-ът се актуализира
   само при успешно пълно изпълнение.
