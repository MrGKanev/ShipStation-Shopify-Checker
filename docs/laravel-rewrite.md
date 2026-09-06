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

Последно обновяване: **2026-09-06**, след Product Completeness report slice-а.

Легенда: **Done** = feature parity за основния workflow; **Partial** = използваем,
но по-тесен от legacy; **Todo** = няма завършен Laravel workflow;
**Replaced** = съзнателно заменен UX, който не трябва да се пренася едно към едно.

### Обобщение на feature progress

| Статус | Страници/инструменти | Дял от 72 |
|---|---:|---:|
| Done | 10 | 13.9% |
| Partial | 2 | 2.8% |
| Todo | 58 | 80.6% |
| Replaced | 2 | 2.8% |
| **Общо** | **72** | **100%** |

Завършените foundation, authentication, store context, administration и API
boundary задачи са реален migration progress, но не надуват броя на legacy
feature страниците. Те се следят отделно:

- [x] Laravel application foundation, health endpoint и CI
- [x] Session authentication и login throttling
- [x] Viewer/operator/admin роли и authorization
- [x] Stores, encrypted credentials и active-store isolation
- [x] First-install command и administration за users/stores
- [x] Shopify GraphQL client, normalization и pagination foundation
- [x] ShipStation client, normalization, retries и store credentials
- [x] Един дългосрочен Draft PR за целия rewrite
- [ ] Production observability, metrics и operational runbooks
- [ ] Background jobs, idempotency, retry и recovery foundation
- [ ] Final parity review, UAT, deployment rehearsal и необратим cutover

### Cross-cutting и non-page legacy capabilities

- [x] Password/session login в Laravel
- [x] Multi-store membership и active-store switching
- [x] Encrypted integration credentials
- [ ] Google OAuth login и callback flow
- [ ] Main audit CLI/web orchestration (`audit.php`)
- [ ] Queue worker и scheduled execution (`worker.php`)
- [ ] Daily email digest (`email_digest.php`)
- [ ] Slack notifications и per-tool rules
- [ ] Email notifications, recipients и per-tool rules
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
| `refunds` | Refunds Tracker | Todo | Shopify refunds ↔ ShipStation status. |
| `repeatrefunds` | Repeat Refunds | Todo | Повторни refunds по клиент. |
| `returns` | Return / RMA Tracker | Todo | Item-level returns и SKU rates. |
| `returneditems` | Returned Items Report | Todo | Itemized returned quantities. |
| `orphans` | Orphan Detector | Todo | ShipStation orders без Shopify order. |
| `activess` | Active SS Conflicts | Todo | Cancelled/refunded Shopify, но active в ShipStation. |
| `ssshipped` | SS Shipped / Shopify Unfulfilled | Todo | Cross-platform fulfillment sync failures. |
| `orderedits` | Order Edit History | Todo | Post-placement changes. |
| `noteflags` | Note Flags | Todo | Flagged keywords в order notes. |
| `addrcheck` | Address Scanner | Todo | Непълни/невалидни адреси. |
| `emailcheck` | Email Checker | Todo | Invalid/disposable/suspicious emails. |
| `hvorders` | High-Value No Phone | Done | Operator/admin report с currency-aware праг, cancelled exclusion, deterministic sorting и visible truncation. |
| `addrchanges` | Address Changes | Todo | Shipping address edits. |
| `postshipaddr` | Post-Ship Address Change | Todo | Address edit след fulfillment. |
| `addrdupes` | Duplicate Shipping Addresses | Todo | Различни emails към еднакъв адрес. |
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
| `skudupes` | SKU Duplicates | Todo | Shared SKU между variants. |
| `inventoryoversell` | Inventory Oversell Risk | Todo | Awaiting quantity над Shopify stock. |
| `inventoryaging` | Inventory Aging | Todo | Zero-stock variants със скорошни продажби. |
| `inventoryforecast` | Inventory Forecast | Todo | Days-to-zero по sell-through. |
| `zombieproducts` | Zombie Products | Todo | Active products без продаваем inventory. |
| `catalogquality` | Catalog Quality | Todo | Publication, SEO и collection checks. |
| `giftcards` | Gift Cards | Todo | Unused/expiring balances. |
| `countrymismatch` | Billing ≠ Shipping Country | Done | ISO-only comparison, missing-country count, currency display, stable sorting и visible truncation. |
| `discountabuse` | Discount Abuse | Todo | Discount clusters по адрес/email. |
| `tagpolicy` | Tag Policy Audit | Todo | Required/forbidden tag combinations. |
| `taxaudit` | Tax Audit | Todo | Paid non-exempt orders с нулев tax. |
| `consentaudit` | Marketing Consent Audit | Todo | Customer marketing consent. |
| `riskreport` | Fraud Risk Report | Todo | Single-order scorer съществува; bulk report липсва. |
| `sameip` | Same IP, Different Emails | Todo | Fraud-ring signal по client IP. |
| `disputes` | Chargebacks / Disputes | Todo | Open disputes и response deadlines. |

Audit subtotal: **Done 3 · Partial 1 · Todo 43 · Replaced 1**.

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
| `actionlog` | Action Log | Todo |
| `printqueue` | Print Queue | Todo |

Manage subtotal: **Done 0 · Partial 0 · Todo 6 · Replaced 0**.

### Settings — 6

| ID | Legacy feature | Статус | Бележка |
|---|---|---|---|
| `settings` | Settings | Partial | Users/stores/credentials са готови; connection tests, banned IP и notification overview липсват. |
| `slackrules` | Slack Rules | Todo | Per-tool notification thresholds и recipients. |
| `emailrules` | Email Rules | Todo | Per-tool email rules и digest settings. |
| `apihealth` | API Health | Todo | `/up` не заменя integration diagnostics и scope checks. |
| `configcheck` | Config Check | Todo | Policy/config validation трябва да бъде заменено с Laravel config contracts. |
| `webhookhealth` | Webhook Health | Todo | Webhook delivery/recency diagnostics. |

Settings subtotal: **Done 0 · Partial 1 · Todo 5 · Replaced 0**.

### Test migration tracker

Броят Laravel тестове не е директен процент от legacy suite-а: новите feature
тестове често заменят няколко стари unit теста и добавят validation, escaping,
pagination, malformed payload и tenant-isolation случаи. Release gate остава
поведенческа traceability, не механично достигане на еднакъв брой тестове.

| Suite | Test files | Executed tests | Assertions |
|---|---:|---:|---:|
| Stable plain PHP | 115 | 1,528 | 3,659 |
| Laravel rewrite | 42 | 264 | 1,040 |

Текущ file-level disposition на всичките **115 legacy test файла**:

| Статус | Файлове | Дял |
|---|---:|---:|
| Fully mapped | 3 | 2.6% |
| Partial / parity verification | 23 | 20.0% |
| Pending | 89 | 77.4% |
| **Общо** | **115** | **100%** |

#### Fully mapped legacy test files

- [x] `PackingSlipPageLoaderTest.php` — всичките 6 legacy paths са нанесени в Laravel feature/domain tests; добавени са exact-match ambiguity, malformed nested data, XSS и invalid-date cases
- [x] `TrackingFeedTest.php` — всичките 7 builder contracts са нанесени; добавени са всички carriers, истински split shipments, unshipped fallback, malformed data, tenant и atomic-error cases
- [x] `GraphQL/OrderTagInsightsTest.php` — tag search и tag statistics contracts са нанесени; добавени са bounded pagination, date variables, malformed payload, duplicate-per-order и XSS cases

#### Partial или чакащи method-level parity проверка

- [ ] `AllViewsSmokeTest.php` — новите views имат feature rendering tests, но всички legacy views не са пренесени
- [ ] `AuthPermissionSnapshotTest.php` — Laravel roles/policies са покрити; пълната legacy permission matrix остава
- [ ] `AuthTest.php` — session auth е пренесен; legacy Google/banned-IP/permission branches остават
- [ ] `AuthViewsTest.php` — login е покрит; всички auth view contracts остават
- [ ] `GraphQL/EventNormalizerTest.php` — event normalization работи; всички 28 legacy test methods чакат mapping
- [ ] `GraphQL/IdsTest.php` — order/event ID paths са покрити; общият legacy ID contract остава
- [ ] `GraphQL/OrderComponentNormalizerTest.php` — address/items/fulfillment subset е пренесен
- [ ] `GraphQL/OrderDirectLookupTest.php` — direct order lookup работи, но legacy full field set остава
- [ ] `FraudComplianceChecksTest.php` — High-Value No Phone matrix е пренесена; country mismatch и останалите fraud checks остават
- [ ] `GraphQL/OrderEventLookupTest.php` — pagination contract е пренесен; method-level mapping остава
- [ ] `GraphQL/OrderNormalizerTest.php` — timeline/risk/order subset е пренесен; всички останали fields остават
- [ ] `HttpAuthEndpointTest.php` — login/logout са покрити; целият legacy endpoint contract остава
- [ ] `OrderInsightPageLoaderTest.php` — compare/timeline subset е пренесен; останалите insights остават
- [ ] `OrderTimelineTest.php` — workflow е пренесен и разширен; 26 legacy methods чакат explicit mapping
- [ ] `ProductCatalogueChecksTest.php` — Product Completeness matrix е пренесена и разширена; SKU duplicates и inventory aging остават
- [ ] `ProductInventoryPageLoaderTest.php` — Product Completeness wiring/error/success paths са пренесени; останалите catalogue workflows остават
- [ ] `RiskScorerTest.php` — осемте сигнала са пренесени; custom weights и 33-method mapping остават
- [ ] `SearchLookupPageLoaderTest.php` — single lookup/compare/timeline subset е пренесен
- [ ] `SecurityTest.php` — escaping, validation и tenant isolation са разширени; целият checklist остава
- [ ] `ShipStationClientTest.php` — lookup/shipments/pagination/retries subset е пренесен
- [ ] `ShopifyClientTest.php` — GraphQL HTTP boundary subset е пренесен
- [ ] `StoresTest.php` — Laravel stores са нов DB модел; legacy multi-store behavior се сверява
- [ ] `ViewSmokeTest.php` — мигрираните screens се render-ват; останалите screens липсват

#### Pending legacy test files

- [ ] `ActionsTest.php`
- [ ] `ActiveSsConflictsTest.php`
- [ ] `AddressChangesTest.php`
- [ ] `AddressCheckTest.php`
- [ ] `AddressScannerPageTest.php`
- [ ] `ApiHealthTest.php`
- [ ] `AtomicFileTest.php`
- [ ] `AuditSnapshotTest.php`
- [ ] `AuditTest.php`
- [ ] `AutoloadCoverageTest.php`
- [ ] `BundleCheckPageTest.php`
- [ ] `CacheTest.php`
- [ ] `CarrierPerfTest.php`
- [ ] `CatalogQualityTest.php`
- [ ] `ComparatorTest.php`
- [ ] `ConfigValidatorTest.php`
- [ ] `ConsentAuditTest.php`
- [ ] `CustomerLTVPageLoaderTest.php`
- [ ] `DateRangeTest.php`
- [ ] `DiscordNotifierTest.php`
- [ ] `DisputesPageLoaderTest.php`
- [ ] `DocsGeneratorTest.php`
- [ ] `EmailDigestTest.php`
- [ ] `EmailNotifierTest.php`
- [ ] `EmailRulesTest.php`
- [ ] `FraudRiskReportTest.php`
- [ ] `FulfillmentIssuePageLoaderTest.php`
- [ ] `FulfillmentLogisticsChecksTest.php`
- [ ] `GiftCardPageLoaderTest.php`
- [ ] `GiftCardsTest.php`
- [ ] `GoogleAuthFlowTest.php`
- [ ] `GoogleAuthTest.php`
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
- [ ] `InventoryForecastTest.php`
- [ ] `ItemizedFulfillmentReportTest.php`
- [ ] `JobQueueTest.php`
- [ ] `JsonFileLockTest.php`
- [ ] `LoggerTest.php`
- [ ] `ManageSettingsPageLoaderTest.php`
- [ ] `MetricsEndpointTest.php`
- [ ] `OnHoldStallTest.php`
- [ ] `OrderAnomalyPageLoaderTest.php`
- [ ] `OrderPolicyChecksTest.php`
- [ ] `OrderPolicyPageLoaderTest.php`
- [ ] `OrphanDetectorTest.php`
- [ ] `PageLoaderTest.php`
- [ ] `PartialFulfillStallsTest.php`
- [ ] `PostShipAddrChangeTest.php`
- [ ] `PrintQueueTest.php`
- [ ] `PushLogTest.php`
- [ ] `RefundsTrackerTest.php`
- [ ] `RepeatRefundsTest.php`
- [ ] `ReportRegistryTest.php`
- [ ] `ReporterTest.php`
- [ ] `ReturnRmaTrackerTest.php`
- [ ] `ReturnedItemsReportTest.php`
- [ ] `RunLogTest.php`
- [ ] `SameIpTest.php`
- [ ] `ScanRunnerTest.php`
- [ ] `ShopifyFlowHealthTest.php`
- [ ] `SidebarSettingsTest.php`
- [ ] `SimpleScanPageLoaderTest.php`
- [ ] `SlackNotifierTest.php`
- [ ] `SlackRulesTest.php`
- [ ] `SsShippedUnfulfilledTest.php`
- [ ] `TaxAuditTest.php`
- [ ] `ToolRegistryTest.php`
- [ ] `UserActionLogTest.php`
- [ ] `ViewHelpersTest.php`
- [ ] `VoidedShipmentsTest.php`
- [ ] `WorkerTest.php`
- [ ] `ZombieProductsTest.php`

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
