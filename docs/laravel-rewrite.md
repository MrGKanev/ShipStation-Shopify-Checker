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
integration boundaries, единичното търсене, сравнението между две поръчки и
order timeline workflow-ът са пренесени. Следващият read-only workflow се избира
от останалите Search & Lookup инструменти. Всеки следващ workflow се пренася
заедно със съответните legacy tests, traceability записи и допълнителните edge
cases от test стратегията по-горе.
