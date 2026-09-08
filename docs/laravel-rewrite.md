# План за пренаписване към Laravel

## Решение

Текущото plain-PHP приложение остава последната стабилна версия на тази
архитектура. Новата версия се разработва като отделно Laravel приложение и не
заменя стабилната версия, докато не покрие договорения функционален обхват и
operational изискванията.

Смяната е еднопосочна. Laravel версията започва с нова база и чисто operational
състояние. Не се импортират legacy JSON/SQLite данни, jobs, logs, cache, reports
или потребителски настройки. След production cutover не се поддържа връщане към
plain-PHP системата.

Това е пренаписване с контролирано пренасяне на доказаните алгоритми, а не
механично преместване на текущите класове в controllers. Laravel поема HTTP
слоя, authentication, authorization, validation, persistence, queues,
scheduling, notifications и configuration. Shopify/ShipStation правилата,
сравненията и анализите остават отделени от framework кода.

## Цели

- Единен и предвидим application lifecycle вместо bootstrap логика в
  `index.php`.
- Ясни граници между HTTP, application, domain и infrastructure код.
- Typed request/response DTO обекти вместо споделени `$ctx` и неструктурирани
  масиви между слоевете.
- Dependency injection вместо static state и директно създаване на API clients.
- Database-backed operational state с Laravel migrations.
- Надеждни background jobs с retries, timeouts, failed-job tracking и
  idempotency.
- Feature parity със стабилната версия преди production cutover.
- Еднократно и окончателно преминаване към Laravel версията.

## Извън обхвата

- Редизайн на продукта по време на пренаписването.
- Добавяне на нови audit инструменти преди достигане на feature parity.
- Промяна на установените audit правила без отделно продуктово решение.
- Задължително преминаване към MySQL/PostgreSQL. Първата Laravel версия трябва
  да може да работи със SQLite; друга база се оценява отделно.
- Миграция на каквито и да е runtime данни от plain-PHP версията.
- Rollback или паралелна работа на двете системи след cutover.

## Git и release стратегия

Преди началото на rewrite-а:

1. Избираме final plain-PHP version номер след успешно release тестване.
2. Създаваме immutable Git tag за release-а.
3. Създаваме read-only `legacy/plain-php` branch от същия commit.
4. Plain-PHP линията не приема нови features. До cutover се допускат само
   критични поправки, които се включват и във feature-parity matrix-а.
5. Rewrite-ът се разработва в `rewrite/laravel` до първия release candidate.
6. При cutover Laravel кодът става единствената активна development и
   production линия. Legacy tag/branch се запазват само като архив.

Версиите трябва да имат различими application headers, логове и deployment
артефакти, за да не може legacy и Laravel инсталация да бъдат объркани.

## Целева архитектура

```text
app/
├── Domain/
│   ├── Audit/
│   ├── Orders/
│   ├── Inventory/
│   ├── Fulfillment/
│   └── Risk/
├── Application/
│   ├── Commands/
│   ├── Queries/
│   ├── DTO/
│   └── Services/
├── Infrastructure/
│   ├── Shopify/
│   ├── ShipStation/
│   ├── Persistence/
│   └── Notifications/
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
├── Jobs/
├── Models/
└── Policies/
```

Зависимостите сочат навътре:

```text
HTTP / CLI / Jobs -> Application -> Domain
Infrastructure --------^          ^
```

Domain кодът не трябва да използва Laravel facades, Eloquent models, HTTP
requests, sessions или globals. Controllers валидират входа, извикват един
application use case и връщат response/view. API clients се достъпват през
interfaces, инжектирани в use case-ите.

## Технологични решения за първата версия

- Laravel с Blade и съществуващата Tailwind визуална посока.
- Laravel HTTP routing, Form Requests, middleware, policies и gates.
- Eloquent/query builder за operational данните и migrations за schema.
- Laravel queue с database driver като минимална deployment конфигурация.
- Laravel scheduler за audit, digest и housekeeping задачи.
- Laravel cache contracts с file/database-compatible default driver.
- Laravel notifications/mail, плюс отделни Slack и Discord adapters.
- Структурирано логване с correlation ID за web request и background job.
- Google authentication чрез поддържан package само ако dependency stack-ът е
  съвместим; иначе чрез малък изолиран adapter със същите security свойства.

Точните Laravel и package версии се заключват след compatibility spike с
използваната PHP версия, Guzzle stack-а и deployment средата.

## Фаза 0: Freeze и спецификация на стабилната версия

### Работа

- Спиране на feature development в plain-PHP линията.
- Пълен inventory на routes, POST actions, CLI commands, scheduled tasks,
  permissions, configuration keys и mutable data.
- Feature-parity matrix за всеки инструмент от `ToolRegistry`.
- Описване на вход, изход, validation, permission и side effects за всеки use
  case.
- Golden-master fixtures за ключови Shopify и ShipStation payload-и.
- Snapshot очаквания за audit резултати, CSV exports и notification decisions.
- Baseline измервания за време, memory и брой API заявки на тежките операции.
- Документиране кои данни умишлено няма да съществуват в новата система.

### Exit criteria

- Стабилният release минава PHPUnit, PHPStan, frontend build и dependency
  audits.
- Всеки вид persistent data е описан като legacy-only и изключен от rewrite-а.
- Feature-parity matrix-ът покрива всички видими страници и background задачи.
- Release tag и legacy branch са създадени.

## Test-parity и разширено покритие

Laravel suite-ът трябва да е по-силен от legacy suite-а, а не само да достигне
същия брой тестове. Всеки legacy тест и всеки доказан production behavior се
записва в traceability matrix с едно от следните състояния:

- мигриран към еквивалентен Laravel unit, feature, integration или browser test;
- заменен от по-широк тест, който доказва същия contract и допълнителни случаи;
- умишлено отпаднал заедно с feature-а и подкрепен с одобрено отклонение.

Не се допуска legacy тест да изчезне без записана причина. Броят тестове не е
самостоятелна цел: мерим покрити behaviors, decisions, failure paths и security
boundaries. За всеки пренесен workflow се добавят приложимите нови случаи:

- празни, минимални, максимални, malformed и неочаквани входове;
- permission matrix за всички роли и cross-store/tenant isolation;
- CSRF, output escaping, mass assignment и липса на secrets в responses/logs;
- празни, частични, невалидни и променени upstream payload-и;
- pagination boundaries, duplicate records и нестабилен ред на резултатите;
- connection timeout, HTTP 429, `Retry-After`, 4xx, 5xx и изчерпани retries;
- timezone, DST, начална/крайна дата и други гранични стойности;
- job retry, idempotency, concurrency, crash recovery и duplicate delivery;
- CSV/export escaping, encoding, големи datasets и memory/request budgets;
- accessibility и основни browser journeys за критичните workflows.

Contract и integration тестовете никога не използват реална външна мрежа.
Shopify, ShipStation, email и notification заявките се изпълняват през точни
fakes, които отказват всяка неочаквана заявка. Анонимизирани production-derived
fixtures допълват synthetic edge cases и golden fixtures.

### Test exit criteria

- Всеки legacy test ID има Laravel test ID или одобрена причина за отпадане.
- Всеки feature-parity ред сочи success, validation, authorization, empty-state
  и приложимите failure-path тестове.
- Няма незаписани network calls, flaky зависимости от време или случайност и
  order-dependent тестове.
- Differential тестовете доказват еквивалентни нормализирани резултати за
  общите legacy/Laravel fixtures.
- Новооткрити edge cases се добавят първо като regression тест.
- Пълният Laravel suite, static analysis, build и security audits са
  задължителни CI checks преди cutover.

## Фаза 1: Laravel foundation

### Работа

- Създаване на чист Laravel application skeleton.
- Настройка на environments, secrets, config validation и CI.
- Общ layout, navigation, error pages и health endpoints.
- Базови conventions за namespaces, DTO, actions/use cases и repositories.
- Test layers: unit, feature, integration и contract tests.
- Fakes за Shopify, ShipStation, email, Slack и Discord.
- Решение за asset build и production artifact.

### Exit criteria

- Приложението се инсталира и стартира от чист checkout по документирана
  процедура.
- CI изпълнява tests, static analysis, formatting/build и dependency audits.
- Health endpoint проверява application boot, database и queue configuration.
- Няма production credentials или mutable runtime files в Git.

## Фаза 2: Integrations и domain core

### Работа

- Дефиниране на `ShopifyGateway` и `ShipStationGateway` interfaces.
- Пренасяне на GraphQL query/normalization логиката зад Shopify adapter.
- Пренасяне на ShipStation pagination, normalization и error handling.
- Общи policies за timeout, retry, rate limits и API error classification.
- Пренасяне на pure логиката: order normalization, comparison, risk scoring,
  date ranges и report calculations.
- Замяна на неструктурираните ключови масиви с DTO/value objects там, където
  намалява двусмислието.
- Contract tests с анонимизирани реални fixtures.

### Exit criteria

- Еднакви fixtures дават еднакви нормализирани резултати в legacy и Laravel.
- Domain тестовете не boot-ват Laravel container.
- Всяка външна API операция може да бъде симулирана без мрежова заявка.
- Retryable и permanent integration failures са различими в логовете и jobs.

## Фаза 3: Identity, stores и permissions

### Работа

- Нов User model; първият administrator се създава директно в Laravel
  инсталацията, без пренасяне на legacy потребители.
- Password login и Google login със session rotation и rate limiting.
- Роли и permissions чрез policies/gates.
- Multi-store модел и избор на active store без process-wide static state.
- Store-scoped credentials и services.
- CSRF, secure cookies, security headers и action audit log.
- Automated permission matrix tests, базирани на сегашното поведение.

### Exit criteria

- Всички auth success/failure сценарии имат feature tests.
- Нито един route или job не може да достъпи грешен store context.
- Permission parity е потвърден за всяка роля и action.
- Security настройките са валидирани зад реалния production proxy/TLS setup.

## Фаза 4: Persistence и чисто начално състояние

### Работа

- Laravel migrations за users, stores, ignored orders, jobs, run logs, action
  logs, notification rules, sidebar settings, print queue и audit snapshots.
- Команда за създаване на първия administrator и първоначалните stores.
- Fresh-install defaults за notification rules, sidebar settings и останалите
  application настройки.
- Ясен списък на legacy-only данните, които Laravel никога не чете: users,
  ignored orders, queues, logs, snapshots, reports, cache и settings.
- Новите jobs, logs и reports започват от момента на cutover.

### Exit criteria

- Чиста Laravel инсталация създава цялата schema само чрез framework migrations.
- Administrator и stores могат да бъдат конфигурирани без редактиране на
  database на ръка.
- Приложението не съдържа importer или runtime dependency към legacy файлове.
- Fresh-install defaults са покрити с automated tests.

## Фаза 5: Audit pipeline, queues и scheduler

### Работа

- `RunAudit` application use case, използваем от HTTP, CLI и queue job.
- Отделни jobs за fetch, comparison, report persistence и notifications, когато
  това подобрява retry/idempotency поведението.
- Unique/idempotency keys по store, tool и date range.
- Job timeouts, retry backoff, failed jobs и безопасно повторно изпълнение.
- Progress/status модел за dashboard-а.
- Scheduled audit, worker lifecycle, email digest и cleanup задачи.
- Защита срещу едновременно изпълнение на несъвместими jobs за един store.

### Exit criteria

- Един и същ audit fixture генерира еквивалентни missing/found/skipped/ignored
  резултати и CSV съдържание.
- Прекъснат job може да бъде повторен без дублирани side effects.
- Notification не се изпраща два пъти при retry.
- Queue restart и deployment по време на job са тествани.

## Фаза 6: Пренасяне на инструментите

Инструментите се пренасят на функционални групи. За всеки инструмент се
реализират route, Form Request, controller, application query/command, view,
authorization и tests.

Препоръчителен ред:

1. Search и read-only lookups: global search, spot check, timeline, metafields,
   tags, customer и tracking.
2. Основен Audit и report history.
3. Order anomaly инструменти: duplicates, refunds, addresses, orphans, failed
   shipments и order changes.
4. Fulfillment и ShipStation инструменти.
5. Product, inventory и forecasting инструменти.
6. Policy, fraud, risk, consent, tax и dispute инструменти.
7. Mutating workflows: ignore/unignore, push to ShipStation, notes, print queue
   и settings.
8. Exports, metrics, notifications и management pages.

### Exit criteria за всеки инструмент

- Резултатите съвпадат върху общите golden fixtures.
- Validation и permissions са покрити с feature tests.
- Empty, partial API failure, pagination и rate-limit сценарии са покрити.
- UI съдържа същата operational информация и действия като stable версията.
- Feature-parity matrix редът е одобрен и маркиран като завършен.

## Фаза 7: UI parity и accessibility

### Работа

- Пренасяне на Blade views и reusable components без промяна на основните
  workflows.
- Уеднаквяване на filters, date ranges, pagination, flash messages и errors.
- Responsive и keyboard проверка на основните workflows.
- Escape policy за външни Shopify/ShipStation стойности.
- Browser tests за login, store switch, audit, search, mutation и export flows.

### Exit criteria

- Няма блокиращи UI regression-и спрямо stable версията.
- Основните workflows могат да бъдат изпълнени само с клавиатура.
- Всички mutating действия имат видимо потвърждение и audit trail.

## Фаза 8: Hardening и release candidate

### Работа

- Security review на auth, OAuth, CSRF, SSRF, file downloads/uploads, secrets и
  authorization boundaries.
- Load/performance тестове с production-sized fixtures.
- Проверка на N+1 queries, memory usage и външни API request counts.
- Structured logs, metrics, queue alerts и failed-job runbook.
- Deployment, queue restart и scheduler runbooks.
- Две пълни fresh-install и cutover rehearsals в production-like среда.
- User acceptance testing по feature-parity matrix.

### Exit criteria

- Няма open critical/high security или data-integrity дефекти.
- Всички parity редове са одобрени или имат изрично прието отклонение.
- Performance е в договорения budget спрямо baseline-а.
- Clean cutover rehearsal е успешен.
- Laravel release candidate работи в production-like среда за договорения soak
  период.

## Фаза 9: Cutover

### Преди cutover

- Обявява се кратък write freeze.
- Спират се legacy cron и workers.
- Изчакват се изпълняващите се legacy jobs или те се прекратяват съзнателно.
- Конфигурират се administrator, stores и secrets в чистата Laravel инсталация.
- Проверява се, че Laravel database е нова и не съдържа импорт от legacy state.

### Пускане

- Laravel приложението се deploy-ва с workers и scheduler.
- Изпълняват се smoke tests за login, store context, read-only lookup, audit
  queueing, export и notification.
- Legacy приложението се изключва и не остава като активна fallback система.
- Наблюдават се errors, queue latency, failed jobs, API failures и audit result
  deviations.

Cutover се извършва само след release sign-off. След него дефектите се поправят
в Laravel версията; production не се връща към plain-PHP приложението.

## Фаза 10: След release

- Засилен monitoring през определения stabilization период.
- Legacy deployment-ът се премахва, а release tag/branch остават само като
  source archive.
- Secrets се ротират, ако са били копирани в нова среда.
- Едва след стабилизацията започват нови features и целеви UI подобрения.

## Feature-parity matrix

Matrix-ът е release control документ, а не само checklist. Минималните колони
са:

| Поле | Описание |
|---|---|
| Feature/tool | Route, action, CLI или scheduled задача |
| Legacy reference | Клас, метод, view и tests в stable версията |
| Legacy test IDs | Всеки стар тест, който доказва feature contract-а |
| Inputs | Полета, defaults и validation |
| Permissions | Допустими роли и store scope |
| Outputs | View данни, CSV, logs и notifications |
| Side effects | DB/file/API промени |
| Fixtures | Golden datasets за сравнение |
| Laravel tests | Unit, feature, integration и browser coverage |
| Added edge cases | Ново покритие над legacy suite-а |
| Status | Not started / In progress / Parity / Accepted deviation |
| Owner/sign-off | Техническо и продуктово одобрение |

## Live migration tracker

Тази секция се обновява във всеки migration slice. Source of truth за legacy
инструментите е `src/ToolRegistry.php`; Laravel статусът се определя само по
достъпен route/controller/view и покриващи тестове. Наличен domain helper без
завършен потребителски workflow не се брои за готов feature.

Последно обновяване: **2026-09-08**, след Refunds Tracker slice-а.

Легенда: **Done** = feature parity за основния workflow; **Partial** = използваем,
но по-тесен от legacy; **Todo** = няма завършен Laravel workflow;
**Replaced** = съзнателно заменен UX, който не трябва да се пренася едно към едно.

### Обобщение на feature progress

| Статус | Страници/инструменти | Дял от 72 |
|---|---:|---:|
| Done | 27 | 37.5% |
| Partial | 2 | 2.8% |
| Todo | 41 | 56.9% |
| Replaced | 2 | 2.8% |
| **Общо** | **72** | **100%** |

Завършените foundation, authentication, store context, administration и API
boundary задачи са реален migration progress, но не надуват броя на legacy
feature страниците. Те се следят отделно:

- [x] Laravel application foundation, health endpoint и CI
- [x] Persisted operational health за database, cache, disk, scheduler и queue worker с admin-only dashboard и scheduled pruning
- [x] Проверени database и artifact backups с отделен disk, retention cleanup, monitoring, health integration и опционално AES-256 архивиране
- [x] Sentry exception observability за web и queue failures с environment/release context, изключен tracing по подразбиране и application-level PII/credential scrub
- [x] Global Content Security Policy с Vite nonce, безопасен report-only rollout и environment switch за enforcing режим
- [x] Queue-ready Slack notification channel с trusted webhook validation и admin-only delivery diagnostic
- [x] Admin-only Laravel Pulse dashboard с dedicated DB connection option, bounded retention и privacy-minimized opt-in recorders
- [x] Redis queue runtime чрез Laravel Horizon с bounded supervisors, admin-only dashboard, queue health check и scheduled metrics snapshots
- [x] Session authentication и login throttling
- [x] Viewer/operator/admin роли и authorization
- [x] Stores, encrypted credentials и active-store isolation
- [x] First-install command и administration за users/stores
- [x] Shopify GraphQL client, normalization и pagination foundation
- [x] ShipStation client, normalization, retries и store credentials
- [x] Basic `/up` liveness и `/ready` database/queue configuration checks
- [ ] API Health е partial: Shopify scopes и ShipStation auth са готови; returned-version header и flow history остават
- [x] Един дългосрочен Draft PR за целия rewrite
- [ ] Production observability, metrics и operational runbooks
- [ ] Background jobs, idempotency, retry и recovery foundation
- [ ] Final parity review, UAT, deployment rehearsal и необратим cutover

### Cross-cutting и non-page legacy capabilities

Подробният статус и definition of done за платформените части е в
[platform and extras audit-а](laravel-platform-audit.md). Той отделя готовия
workflow от наличния framework scaffold.

- [x] Password/session login в Laravel
- [x] Multi-store membership и active-store switching
- [x] Encrypted integration credentials
- [x] Google OAuth login и callback flow чрез Socialite, verified Workspace `hd` allowlist, existing-user binding, session rotation и OAuth throttling
- [ ] Main audit CLI/web orchestration (`audit.php`)
- [ ] Queue worker и scheduled execution (`worker.php`)
- [ ] Daily email digest (`email_digest.php`)
- [ ] Slack channel и delivery diagnostic са готови; audit/scan notifications, mentions и per-tool rules остават
- [ ] Email notifications, recipients и per-tool rules
- [x] SMTP configuration diagnostic и admin-only test delivery
- [ ] Discord notifications
- [ ] Report persistence, downloads и CSV/export contracts
- [ ] Metrics endpoint и authentication (`metrics.php`)
- [ ] Structured application/run/action logging
- [ ] Cache behavior и invalidation
- [ ] Ignore/unignore mutations и bulk import
- [ ] Shopify → ShipStation push workflow и idempotency
- [ ] Print queue actions и recovery
- [ ] Webhook monitoring и health state
- [ ] Laravel scheduler/queue deployment и failed-job runbook

### Audit инструменти — 48

| ID | Legacy feature | Статус | Бележка |
|---|---|---|---|
| `dashboard` | Dashboard | Partial | Laravel показва store и migration status; legacy audit stats/actions липсват. |
| `hub-audit` | Audit hub | Replaced | Заменен от постоянна sidebar навигация и директни routes. |
| `reports` | Reports | Todo | Saved reports и downloads. |
| `run` | Run Audit | Todo | Shopify ↔ ShipStation audit по период. |
| `trends` | Trends | Todo | Aggregated audit report trends. |
| `dupes` | Duplicate Detector | Todo | Близки duplicate orders. |
| `refunds` | Refunds Tracker | Done | Refunded Shopify orders with line-item totals, optional ShipStation cross-check and active/missing risk priority. |
| `repeatrefunds` | Repeat Refunds | Done | Refunded/partially-refunded orders grouped by normalized email, successful transaction totals and configurable threshold. |
| `returns` | Return / RMA Tracker | Todo | Item-level returns и SKU rates. |
| `returneditems` | Returned Items Report | Todo | Itemized returned quantities. |
| `orphans` | Orphan Detector | Todo | ShipStation orders без Shopify order. |
| `activess` | Active SS Conflicts | Todo | Cancelled/refunded Shopify, но active в ShipStation. |
| `ssshipped` | SS Shipped / Shopify Unfulfilled | Todo | Cross-platform fulfillment sync failures. |
| `orderedits` | Order Edit History | Done | Paginated edit events, batch order hydration, grouped summaries and edit-delay calculation. |
| `noteflags` | Note Flags | Done | Configurable case-insensitive note keywords over paid unfulfilled orders, with safe pagination. |
| `addrcheck` | Address Scanner | Done | Required fields, short street, US/CA postal formats, province, express phone и PO Box/carrier checks с два legacy филтъра. |
| `emailcheck` | Email Checker | Done | Paid date-range scan с missing/invalid/disposable critical правила, suspicious warning евристики, severity sorting и visible truncation. |
| `hvorders` | High-Value No Phone | Done | Operator/admin report с currency-aware праг, cancelled exclusion, deterministic sorting и visible truncation. |
| `addrchanges` | Address Changes | Done | Paginated shipping-address events, latest change per order, batch hydration, current address, placement-to-change delay and formula-safe streamed CSV export. |
| `postshipaddr` | Post-Ship Address Change | Todo | Address edit след fulfillment. |
| `addrdupes` | Duplicate Shipping Addresses | Done | Paid orders grouped by normalized address, distinct-email threshold and deterministic risk sorting. |
| `failedship` | Voided Shipments | Todo | Voided ShipStation shipments. |
| `slabreaches` | Fulfillment SLA Breaches | Todo | Time-to-first-fulfillment SLA. |
| `bundlecheck` | Bundle Check | Todo | Липсващи required companion items. |
| `partialfulfill` | Partial Fulfillment Stalls | Todo | Stalled partial fulfillments. |
| `onholdstall` | On-Hold Stall | Todo | Fulfillment orders на hold. |
| `notracking` | Fulfilled Without Tracking | Todo | Fulfilled orders без tracking след grace period. |
| `shipmentaging` | Shipment Aging | Todo | Стари awaiting-shipment orders. |
| `itemmismatch` | Shipped Item Mismatch | Todo | Shopify ordered items ↔ ShipStation shipped items. |
| `fulfilleditems` | Fulfilled Items Report | Todo | Itemized fulfilled quantities. |
| `carrierperf` | Carrier Performance | Todo | Delivery time и late rate по carrier. |
| `shipmargin` | Shipping Margin Erosion | Todo | Label cost над customer shipping charge. |
| `productcheck` | Product Completeness | Done | True image check, meaningful description, strict complete variant scan, missing/no-variant classification и visible truncation. |
| `skudupes` | SKU Duplicates | Done | Всички product statuses, пълна variant pagination, case-sensitive SKU grouping, blank exclusion, count sorting и visible truncation. |
| `inventoryoversell` | Inventory Oversell Risk | Done | Active tracked deny-policy stock спрямо всички ShipStation awaiting-shipment quantities, duplicate SKU aggregation и visible catalogue truncation. |
| `inventoryaging` | Inventory Aging | Done | Active tracked deny-policy variants с stock ≤ 0, paid-order sales window, complete product/order/line-item pagination и visible truncation. |
| `inventoryforecast` | Inventory Forecast | Done | Фиксиран 30-дневен paid-sales прозорец, cancelled-order exclusion, daily sell-through, days-to-zero severity и visible truncation. |
| `zombieproducts` | Zombie Products | Done | No-variant и all-tracked-deny-zero-stock detection, full active catalogue pagination и visible truncation. |
| `catalogquality` | Catalog Quality | Done | Online Store publication, SEO title/description и collection membership върху пълния active catalogue с visible truncation. |
| `giftcards` | Gift Cards | Done | Enabled positive balances, configurable expiry window, expired/never-redeemed reasons, currency display и full pagination. |
| `countrymismatch` | Billing ≠ Shipping Country | Done | ISO-only comparison, missing-country count, currency display, stable sorting и visible truncation. |
| `discountabuse` | Discount Abuse | Done | Paid orders grouped by normalized discount code/address, distinct-email threshold, detailed orders and deterministic cluster sorting. |
| `tagpolicy` | Tag Policy Audit | Done | Config-native required/forbidden combinations, case-insensitive matching, paid-order scan and visible truncation. |
| `taxaudit` | Tax Audit | Done | Paid non-exempt zero-tax orders над configurable minimum, exact boundary и total-descending sorting. |
| `consentaudit` | Marketing Consent Audit | Done | Paid orders без subscribed email consent, informational SMS state, unknown defaults и newest-first sorting. |
| `riskreport` | Fraud Risk Report | Done | Paid date-range scan с осем legacy сигнала, medium/high filtering, score-descending sorting и visible truncation. |
| `sameip` | Same IP, Different Emails | Done | Paid orders grouped by exact client IP, case-insensitive distinct-email deduplication, detailed orders and deterministic risk sorting. |
| `disputes` | Chargebacks / Disputes | Done | Open actionable disputes, evidence deadlines, urgency sorting and bounded pagination. |

Audit subtotal: **Done 18 · Partial 1 · Todo 28 · Replaced 1**.

### Search & Lookup — 12

| ID | Legacy feature | Статус | Бележка |
|---|---|---|---|
| `hub-search` | Search & Lookup hub | Replaced | Заменен от sidebar links. |
| `globalsearch` | Global Search | Todo | Reports, push log и ignored-order търсене. |
| `spotcheck` | Spot-check | Done | 1–50 уникални номера, three-source mode, exact batch Shopify lookup, risk badges и safe atomic errors. |
| `compare` | Order Compare | Done | `/orders/compare`, safe errors, ambiguity и optional ShipStation status. |
| `timeline` | Order Timeline | Done | Shopify events/refunds/fulfillments + ShipStation + risk analysis. |
| `customer` | Customer Lookup | Todo | Order history, LTV summary и CSV. |
| `cohort` | Customer LTV | Todo | Top customers и cohort retention. |
| `tagsearch` | Tag Search | Done | Exact case-insensitive match, optional validated range, pagination/truncation и safe Shopify links. |
| `tagaudit` | Tag Audit | Done | Per-order frequency, last seen/order, 90-day orphan signal, drill-down и visible truncation. |
| `metafields` | Metafields | Todo | Definitions и order/value lookup. |
| `tracking` | Tracking Feed | Done | 1–30 уникални номера, real `/shipments`, unshipped fallback, carrier allowlist и atomic safe errors. |
| `packingslip` | Packing Slip Preview | Done | Exact-match ShipStation lookup, safe view-data builder, ambiguity state и print-friendly preview. |

Search subtotal: **Done 7 · Partial 0 · Todo 4 · Replaced 1**.

### Manage — 6

| ID | Legacy feature | Статус |
|---|---|---|
| `ignored` | Ignored Orders | Todo |
| `pushlog` | Push Log | Todo |
| `runlog` | Run History | Todo |
| `jobs` | Job Queue | Todo |
| `actionlog` | Action Log | Done | Admin-only newest-first activity history for allowlisted user/store changes, credential rotations and store-access updates, with scheduled retention cleanup. |
| `printqueue` | Print Queue | Todo |

Manage subtotal: **Done 1 · Partial 0 · Todo 5 · Replaced 0**.

### Settings — 6

| ID | Legacy feature | Статус | Бележка |
|---|---|---|---|
| `settings` | Settings | Partial | Users/stores/credentials са готови; connection tests, banned IP и notification overview липсват. |
| `slackrules` | Slack Rules | Todo | Per-tool notification thresholds и recipients. |
| `emailrules` | Email Rules | Todo | Per-tool email rules и digest settings. |
| `apihealth` | API Health | Partial | Admin-only Shopify shop/scopes и ShipStation auth checks са готови; returned-version header и flow history остават. |
| `configcheck` | Config Check | Todo | Policy/config validation трябва да бъде заменено с Laravel config contracts. |
| `webhookhealth` | Webhook Health | Todo | Webhook delivery/recency diagnostics. |

Settings subtotal: **Done 0 · Partial 2 · Todo 4 · Replaced 0**.

### Test migration tracker

Подробният operational checklist е в
[отделния legacy test audit](laravel-test-audit.md). Той е source of truth за
ролята, method-level статуса и оставащата работа по всеки test файл.

Броят Laravel тестове не е директен процент от legacy suite-а: новите feature
тестове често заменят няколко стари unit теста и добавят validation, escaping,
pagination, malformed payload и tenant-isolation случаи. Release gate остава
поведенческа traceability, не механично достигане на еднакъв брой тестове.

| Suite | Test files | Executed tests | Assertions |
|---|---:|---:|---:|
| Stable plain PHP | 115 | 1,528 | 3,659 |
| Laravel rewrite | 117 | 459 | 1,934 |

Текущ file-level disposition на всичките **115 legacy test файла**:

| Статус | Файлове | Дял |
|---|---:|---:|
| Fully mapped | 22 | 19.1% |
| Partial / parity verification | 26 | 22.6% |
| Pending | 67 | 58.3% |
| **Общо** | **115** | **100%** |

#### Fully mapped legacy test files

- [x] `PackingSlipPageLoaderTest.php` — всичките 6 legacy paths са нанесени в Laravel feature/domain tests; добавени са exact-match ambiguity, malformed nested data, XSS и invalid-date cases
- [x] `TrackingFeedTest.php` — всичките 7 builder contracts са нанесени; добавени са всички carriers, истински split shipments, unshipped fallback, malformed data, tenant и atomic-error cases
- [x] `GraphQL/OrderTagInsightsTest.php` — tag search и tag statistics contracts са нанесени; добавени са bounded pagination, date variables, malformed payload, duplicate-per-order и XSS cases
- [x] `ProductCatalogueChecksTest.php` — всичките 16 catalogue decisions са нанесени и разширени
- [x] `CatalogQualityTest.php` — всичките 6 quality decisions са нанесени и разширени
- [x] `GiftCardPageLoaderTest.php` — всичките 4 visible workflow/failure paths са нанесени и разширени
- [x] `GiftCardsTest.php` — всичките 8 gift-card decisions са нанесени и разширени
- [x] `InventoryForecastTest.php` — всичките 9 forecast decisions са нанесени и разширени
- [x] `ZombieProductsTest.php` — всичките 7 zombie-product decisions са нанесени и разширени
- [x] `TaxAuditTest.php` — всичките 6 tax decisions са нанесени и разширени
- [x] `ConsentAuditTest.php` — всичките 4 consent decisions са нанесени и разширени
- [x] `FraudRiskReportTest.php` — всичките 4 filtering, signal, Shopify-risk и sorting decisions са нанесени и разширени
- [x] `AddressCheckTest.php` — всичките 20 address validation и malformed-value decisions са нанесени
- [x] `AddressScannerPageTest.php` — всичките 4 filtering, clean-row и severity sorting decisions са нанесени
- [x] `SameIpTest.php` — всичките 5 grouping, exclusion, deduplication и sorting decisions са нанесени
- [x] `OrderPolicyChecksTest.php` — всичките 16 Discount Abuse и Tag Policy configuration, rule semantics и tag normalization decisions са нанесени
- [x] `RepeatRefundsTest.php` — всичките 8 threshold, transaction filtering, grouping and sorting decisions са нанесени
- [x] `RefundsTrackerTest.php` — всичките 10 refund amount, exact-order matching, ShipStation status и risk sorting decisions са нанесени; добавени са authorization, store isolation, pagination, XSS, optional ShipStation и safe upstream failure cases
- [x] `AddressChangesTest.php` — всичките 4 delay, missing/negative timestamp clamping и current-address output решения са нанесени и разширени с event pagination/filtering, batch hydration, authorization, validation, XSS, safe failure и truncation
- [x] `GoogleAuthFlowTest.php` — custom flow е заменен със Socialite stateful OAuth; redirect/callback, cancellation, provider failure, verified Workspace domain, existing-user binding, session rotation и Google-only режим са покрити
- [x] `GoogleAuthTest.php` — custom OIDC/PKCE клиента е заменен със Socialite; config guard, domain parsing, verified `hd` claim, stable Google subject binding и safe errors са покрити, а provider token-и не се пазят

#### Partial или чакащи method-level parity проверка

- [ ] `AllViewsSmokeTest.php` — новите views имат feature rendering tests, но всички legacy views не са пренесени
- [ ] `ApiHealthTest.php` — live credentials/scopes/connectivity paths са пренесени; Shopify returned-version header остава
- [ ] `AuthPermissionSnapshotTest.php` — Laravel roles/policies са покрити; пълната legacy permission matrix остава
- [ ] `AuthTest.php` — session auth е пренесен; legacy Google/banned-IP/permission branches остават
- [ ] `AuthViewsTest.php` — login е покрит; всички auth view contracts остават
- [ ] `GraphQL/EventNormalizerTest.php` — event normalization работи; всички 28 legacy test methods чакат mapping
- [ ] `GraphQL/IdsTest.php` — order/event ID paths са покрити; общият legacy ID contract остава
- [ ] `GraphQL/OrderComponentNormalizerTest.php` — address/items/fulfillment subset е пренесен
- [ ] `OrderPolicyPageLoaderTest.php` — Discount Abuse, Same IP, Tag Policy, Duplicate Shipping Addresses и Note Flags paths са пренесени; останалите policy report branches чакат method-level сверка
- [ ] `GraphQL/OrderDirectLookupTest.php` — direct order lookup работи, но legacy full field set остава
- [ ] `FraudComplianceChecksTest.php` — High-Value No Phone, Country Mismatch и Email Checker матриците са пренесени; останалите fraud/compliance checks остават
- [ ] `GraphQL/OrderEventLookupTest.php` — pagination contract е пренесен; method-level mapping остава
- [ ] `GraphQL/OrderNormalizerTest.php` — timeline/risk/order subset е пренесен; всички останали fields остават
- [ ] `HttpAuthEndpointTest.php` — login/logout са покрити; целият legacy endpoint contract остава
- [ ] `OrderInsightPageLoaderTest.php` — compare/timeline subset е пренесен; останалите insights остават
- [ ] `OrderTimelineTest.php` — workflow е пренесен и разширен; 26 legacy methods чакат explicit mapping
- [ ] `ProductInventoryPageLoaderTest.php` — Product Completeness, Inventory Oversell, Inventory Aging, Inventory Forecast, Zombie Products и Catalog Quality wiring/error/success paths са пренесени; останалите catalogue workflows остават
- [ ] `ReporterTest.php` — общият League CSV streaming contract, safe filenames и formula escaping са готови и Address Changes го използва; JSON, summaries, attachments и останалите report schemas остават
- [ ] `UserActionLogTest.php` — DB-backed admin Action Log, safe allowlisted changes, credential rotation metadata, store-access updates, authorization и scheduled retention са готови; legacy JSON import остава
- [ ] `RiskScorerTest.php` — осемте сигнала са пренесени; custom weights и 33-method mapping остават
- [ ] `SearchLookupPageLoaderTest.php` — single lookup/compare/timeline subset е пренесен
- [ ] `SecurityTest.php` — escaping, validation и tenant isolation са разширени; целият checklist остава
- [ ] `SlackNotifierTest.php` — queue-ready webhook delivery, trusted endpoint validation, safe admin diagnostic и credential-free payload са готови; audit/scan payloads, mentions и retry mapping остават
- [ ] `ShipStationClientTest.php` — lookup/shipments/pagination/retries subset е пренесен
- [ ] `ShopifyClientTest.php` — GraphQL HTTP boundary subset е пренесен
- [ ] `StoresTest.php` — Laravel stores са нов DB модел; legacy multi-store behavior се сверява
- [ ] `ViewSmokeTest.php` — мигрираните screens се render-ват; останалите screens липсват

SKU Duplicates traceability (`ProductCatalogueChecksTest.php` →
`laravel/tests/Unit/Domain/Reports/SkuDuplicatesAnalyzerTest.php`):

- `testDuplicateSkuAcrossProductsIsFlagged`, `testDraftAndArchivedOnlyDuplicateIsCaught`,
  `testSkuDupesSortedByCountDescending` → `test_groups_across_all_statuses_and_within_a_product_sorted_by_count`
  и integration `test_finds_duplicates_across_product_pages_including_drafts_and_archived`.
- `testUniqueSkuIsNotFlagged`, `testBlankSkusAreIgnored` →
  `test_ignores_blank_and_unique_skus_but_counts_all_variants`.
- Допълнително: numeric/trimmed/case-sensitive SKU, unsafe IDs, празен каталог,
  GET без API call, viewer denial, foreign store input, safe errors/XSS,
  truncation, product/variant pagination и malformed variant connection в
  `SkuDuplicatesControllerTest` и `ShopifySkuDuplicatesCandidatesTest`.

Inventory Aging traceability (`ProductCatalogueChecksTest.php` и
`ProductInventoryPageLoaderTest.php` →
`laravel/tests/Unit/Domain/Reports/InventoryAgingAnalyzerTest.php`):

- `testTrackedDenyZeroStockWithRecentSalesIsFlagged`,
  `testZeroStockWithNoRecentSalesIsExcludedFalsePositiveCheck`,
  `testPositiveStockWithSalesIsExcludedFalseNegativeCheck`,
  `testUntrackedVariantIsExcluded` и `testContinueSellingPolicyIsExcluded` →
  analyzer success/exclusion tests.
- Legacy initial range, credential validation и success wiring →
  `InventoryAgingControllerTest` и `ShopifyInventoryAgingCandidatesTest`.
- Допълнително: reversed/invalid dates, tenant-selected store, safe XSS/error
  rendering, negative stock, aggregate sales, latest-order selection, malformed
  upstream data, complete line-item pagination и отделни product/order
  truncation indicators.

Inventory Oversell traceability (`ProductInventoryPageLoaderTest.php` →
`laravel/tests/Unit/Domain/Reports/InventoryOversellAnalyzerTest.php`):

- Awaiting quantity над наличността, exact-stock exclusion, missing Shopify SKU,
  blank SKU, untracked variant и continue-policy exclusion са пренесени.
- Negative stock, multi-order quantity aggregation, duplicate Shopify SKU stock
  aggregation и shortfall-descending sorting са покрити в analyzer тестовете.
- Credential guards за двете системи, selected-store isolation, initial state,
  safe XSS/error rendering и visible product truncation са покрити в
  `InventoryOversellControllerTest`.
- ShipStation adapter тестът проверява пълна pagination с
  `awaiting_shipment` filter и 500 orders на страница.

Inventory Forecast traceability (`InventoryForecastTest.php` и
`ProductInventoryPageLoaderTest.php` →
`laravel/tests/Unit/Domain/Reports/InventoryForecastAnalyzerTest.php`):

- Rate, days-to-zero, no-sales/high-stock, no-sales/low-stock, zero-stock,
  cancelled-order, untracked, continue-policy, null-last sorting и total variant
  count са пренесени в analyzer тестовете.
- Initial state, credentials, fixed 30-day window, selected-store isolation,
  success и safe upstream failure са покрити в `InventoryForecastControllerTest`.
- Общият Shopify catalogue/order adapter покрива active/paid filters, complete
  product/variant/order/line-item pagination, cancelled timestamp и отделни
  truncation indicators.

Zombie Products traceability (`ZombieProductsTest.php` и
`ProductInventoryPageLoaderTest.php` →
`laravel/tests/Unit/Domain/Reports/ZombieProductsAnalyzerTest.php`):

- No-variant, all-zero/negative, singular/plural detail, mixed positive stock,
  untracked-only, continue-only и healthy product decisions са пренесени.
- Initial state, credential guard, selected-store isolation, success, empty
  state и safe upstream failure са покрити в `ZombieProductsControllerTest`.
- Допълнително са покрити malformed variants, unsafe product IDs, XSS escaping,
  complete variant pagination и visible catalogue truncation.

Catalog Quality traceability (`CatalogQualityTest.php` и
`ProductInventoryPageLoaderTest.php` →
`laravel/tests/Unit/Domain/Reports/CatalogQualityAnalyzerTest.php`):

- Healthy, unpublished, missing SEO title, missing SEO description, missing
  collection и combined-issues decisions са пренесени с непроменен ред на
  съобщенията.
- Initial state, credential guard, selected-store isolation, success, empty
  state и safe upstream failure са покрити в `CatalogQualityControllerTest`.
- Допълнително: malformed nested data, unsafe product IDs, XSS escaping,
  explicit GraphQL discovery fields, full variant pagination и visible
  catalogue truncation.

Gift Cards traceability (`GiftCardsTest.php` и `GiftCardPageLoaderTest.php` →
`laravel/tests/Unit/Domain/Reports/GiftCardsAnalyzerTest.php`):

- Disabled, zero balance, expiry inside/outside window, expired, never
  redeemed, combined reasons и balance-descending sorting са пренесени.
- Initial 30-day value, integer/minimum validation, credential guard,
  selected-store isolation, success и safe upstream failure са покрити в
  `GiftCardsControllerTest`.
- Допълнително: currency normalization, malformed scalar fields, XSS escaping,
  multi-page GraphQL results, stalled/malformed pagination contract и visible
  truncation.

Tax and Consent traceability (`TaxAuditTest.php`, `ConsentAuditTest.php`,
`SimpleScanPageLoaderTest.php` и `OrderPolicyPageLoaderTest.php` → Laravel
report analyzers/controllers и `ShopifyPolicyAuditCandidatesTest`):

- Всичките 6 tax decisions и 4 consent decisions са пренесени, включително
  exact minimum, tax exemption, unknown consent и stable business sorting.
- Paid/date GraphQL filters, tax/customer consent полетата, normalization и
  bounded pagination са покрити на integration границата.
- Initial dates, validation, roles, credential guards, active-store isolation,
  safe upstream failures, XSS и visible truncation са покрити във feature tests.

Fraud Risk traceability (`FraudRiskReportTest.php` → `FraudRiskAnalyzerTest.php`,
`FraudRiskControllerTest.php` и `ShopifyFraudRiskCandidatesTest.php`):

- Low-risk exclusion, medium signal breakdown, Shopify HIGH risk и
  score-descending sorting са пренесени.
- Paid/date GraphQL filter и всички scorer входове са проверени на integration
  границата; roles, validation, credentials, XSS, safe failure и truncation са
  покрити във feature tests.

Email Checker traceability (`FraudComplianceChecksTest.php` и
`SimpleScanPageLoaderTest.php` → `EmailCheckAnalyzerTest.php`,
`EmailCheckControllerTest.php` и `ShopifyEmailCheckCandidatesTest.php`):

- Missing, invalid и 28 disposable domains са critical; short local part,
  placeholder и пет повторени символа са warnings; legacy boundaries и
  critical-first sorting са пренесени.
- Paid/date query, initial range, authorization, validation, credential guard,
  store isolation, XSS, safe failure и visible truncation са покрити.

Address Scanner traceability (`AddressCheckTest.php` и
`AddressScannerPageTest.php` → `AddressCheckAnalyzerTest.php`,
`AddressCheckControllerTest.php` и `ShopifyAddressCheckCandidatesTest.php`):

- Всички required-field, ZIP/postal, province, short-address, PO Box/carrier и
  express-phone решения са пренесени, включително numeric ZIP стойности.
- Critical-first sorting, PO Box-only и unfulfilled-only filters, paid date
  query, authorization, credentials, XSS, safe failure и truncation са покрити.

Discount Abuse traceability (`OrderPolicyChecksTest.php` и
`OrderPolicyPageLoaderTest.php` → `DiscountAbuseAnalyzerTest.php`,
`DiscountAbuseControllerTest.php` и `ShopifyDiscountAbuseCandidatesTest.php`):

- Exact minimum, below-minimum exclusion, distinct-email deduplication,
  separate-address grouping, code/address case normalization и cluster sorting
  са покрити.
- Paid/date query, DiscountCodeApplication filtering, validated threshold,
  authorization, credentials, store isolation, XSS, safe failure и visible
  truncation са покрити.

Same IP traceability (`SameIpTest.php` и `OrderPolicyPageLoaderTest.php` →
`SameIpAnalyzerTest.php`, `SameIpControllerTest.php` и
`ShopifySameIpCandidatesTest.php`):

- Exact-IP grouping, case-insensitive distinct-email counting, blank IP/email
  and different-IP exclusions и email/order count sorting са пренесени.
- Paid/date query и clientIp normalization, authorization, validation,
  credentials, active-store isolation, XSS, safe failure и visible truncation
  са покрити.

Refunds Tracker traceability (`RefundsTrackerTest.php` и refund branches от
`OrderAnomalyPageLoaderTest.php` → `RefundTrackerAnalyzerTest.php`,
`RefundTrackerControllerTest.php` и `ShopifyRepeatRefundCandidatesTest.php`):

- Missing, awaiting shipment/payment, on-hold and completed ShipStation states,
  refund line-item subtotal, full-refund fallback и risk ordering са пренесени.
- Exact legacy number matching, inclusive Shopify date query, seven-day
  ShipStation tail, roles, validation, store isolation, optional ShipStation,
  XSS, safe failures and bounded pagination са покрити.

Tag Policy traceability (`OrderPolicyChecksTest.php` и
`OrderPolicyPageLoaderTest.php` → `TagPolicyAnalyzerTest.php`,
`TagPolicyControllerTest.php` и `ShopifyTagPolicyTest.php`):

- Празна конфигурация, required правила с all-trigger semantics, forbidden
  комбинации, case-insensitive сравнение и string/array tag normalization са
  пренесени; невалидните rule entries се пропускат безопасно.
- Правилата вече са Laravel config в `config/tag-policy.php`; празният config
  спира audit-а преди Shopify call. Paid/date query, authorization, validation,
  credentials, active-store isolation, XSS, safe failure и visible truncation
  са покрити.

#### Pending legacy test files

- [ ] `ActionsTest.php`
- [ ] `ActiveSsConflictsTest.php`
- [ ] `AtomicFileTest.php`
- [ ] `AuditSnapshotTest.php`
- [ ] `AuditTest.php`
- [ ] `AutoloadCoverageTest.php`
- [ ] `BundleCheckPageTest.php`
- [ ] `CacheTest.php`
- [ ] `CarrierPerfTest.php`
- [ ] `ComparatorTest.php`
- [ ] `ConfigValidatorTest.php`
- [ ] `CustomerLTVPageLoaderTest.php`
- [ ] `DateRangeTest.php`
- [ ] `DiscordNotifierTest.php`
- [ ] `DocsGeneratorTest.php`
- [ ] `EmailDigestTest.php`
- [ ] `EmailNotifierTest.php`
- [ ] `EmailRulesTest.php`
- [ ] `FulfillmentIssuePageLoaderTest.php`
- [ ] `FulfillmentLogisticsChecksTest.php`
- [ ] `GraphQL/AdminLookupsTest.php`
- [ ] `GraphQL/CatalogAndFulfillmentTest.php`
- [ ] `GraphQL/CustomDataLookupsTest.php`
- [ ] `GraphQL/CustomerOrderInsightsTest.php`
- [ ] `GraphQL/DisputeLookupTest.php`
- [ ] `GraphQL/DuplicateOrderInsightsTest.php`
- [ ] `GraphQL/MetafieldNormalizerTest.php`
- [ ] `GraphQL/OrderArchiveTest.php`
- [ ] `GraphQL/OrderAuditsTest.php`
- [ ] `GraphQL/OrderEventAuditsTest.php`
- [ ] `GraphQL/OrderFetcherTest.php`
- [ ] `GraphQL/OrderHoldLookupTest.php`
- [ ] `GraphQL/OrderInsightsTest.php`
- [ ] `GraphQL/OrderLookupTest.php`
- [ ] `GraphQL/OrderQueryAuditsTest.php`
- [ ] `GraphQL/ProductNormalizerTest.php`
- [ ] `GraphQL/QueryStringsTest.php`
- [ ] `IgnoreListTest.php`
- [ ] `ItemizedFulfillmentReportTest.php`
- [ ] `JobQueueTest.php`
- [ ] `JsonFileLockTest.php`
- [ ] `LoggerTest.php`
- [ ] `ManageSettingsPageLoaderTest.php`
- [ ] `MetricsEndpointTest.php`
- [ ] `OnHoldStallTest.php`
- [ ] `OrderAnomalyPageLoaderTest.php`
- [ ] `OrphanDetectorTest.php`
- [ ] `PageLoaderTest.php`
- [ ] `PartialFulfillStallsTest.php`
- [ ] `PostShipAddrChangeTest.php`
- [ ] `PrintQueueTest.php`
- [ ] `PushLogTest.php`
- [ ] `ReportRegistryTest.php`
- [ ] `ReturnRmaTrackerTest.php`
- [ ] `ReturnedItemsReportTest.php`
- [ ] `RunLogTest.php`
- [ ] `ScanRunnerTest.php`
- [ ] `ShopifyFlowHealthTest.php`
- [ ] `SidebarSettingsTest.php`
- [ ] `SimpleScanPageLoaderTest.php`
- [ ] `SlackRulesTest.php`
- [ ] `SsShippedUnfulfilledTest.php`
- [ ] `ToolRegistryTest.php`
- [ ] `ViewHelpersTest.php`
- [ ] `VoidedShipmentsTest.php`
- [ ] `WorkerTest.php`

При приключване на feature неговите legacy test файлове не се маркират
автоматично като готови. Първо се сверяват отделните test methods срещу Laravel
tests; липсващите edge cases се добавят, а заменените contracts получават кратка
причина в tracker-а.

## Definition of done за Laravel release

Rewrite-ът е готов за production само когато:

- всички договорени features имат parity или одобрено отклонение;
- production-like fresh install е повторяем;
- authentication, authorization и store isolation са проверени;
- audit и report резултатите съвпадат върху golden и production-derived data;
- всеки legacy тест е мигриран, заменен с по-силно покритие или има одобрена
  причина за отпадане;
- Laravel suite-ът покрива допълнителните validation, security, integration,
  pagination, concurrency и recovery edge cases от test стратегията;
- jobs са idempotent и recovery процедурите са тествани;
- CI, security review, performance budgets и operational runbooks са готови;
- cutover е изпълнен поне веднъж извън production;
- Laravel версията никога не чете или записва legacy runtime formats.

## Основни рискове и контрол

| Риск | Контрол |
|---|---|
| Скрито поведение в page loaders/views | Inventory, golden fixtures и parity matrix преди имплементация |
| Различни audit резултати | Differential tests между stable и Laravel върху едни и същи payload-и |
| Очакване за липсваща история след cutover | Предварително описване и приемане на всички legacy-only данни |
| Дублирани външни действия при retry | Idempotency keys и persisted delivery state |
| Cross-store data leakage | Explicit store context, scoped repositories и isolation tests |
| Rewrite-ът се разширява безкрайно | Feature freeze и изрично управление на accepted deviations |
| Dependency incompatibility | Compatibility spike преди заключване на stack-а |
| Критичен дефект след необратимия cutover | По-строг release sign-off, production-like rehearsal и бърз fix-forward процес |

## Текуща практическа задача

Laravel foundation, authentication, stores, administration, Shopify/ShipStation
integration boundaries, единичното търсене, batch Spot-check, сравнението между
две поръчки и order timeline workflow-ът са пренесени. Следващият read-only
workflow се избира от останалите Search & Lookup инструменти. Всеки следващ
workflow се пренася заедно със съответните legacy tests, traceability записи и
допълнителните edge cases от test стратегията по-горе.
