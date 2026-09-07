# Препоръчани пакети за Laravel rewrite

Последно обновяване: **2026-09-07**. Документът е съобразен с текущия проект
(`PHP ^8.5`, `laravel/framework ^13.17`), 72-та legacy инструмента и отделния
platform audit. Това е план за зависимости, а не списък с пакети за автоматично
инсталиране. Всеки пакет се добавя в commit-а на функцията, която реално го
използва.

## Решение накратко

| Приоритет | Пакет | За какво го използваме | Решение |
|---|---|---|---|
| P0 | `laravel/socialite` | Google OAuth login | Добавяме с Google login slice-а |
| P0 | `larastan/larastan` | Static analysis в CI | **Инсталиран:** v3.11, level 5 за Application/Domain/Integrations, без baseline |
| P0 | `league/csv` | Общ CSV export/import contract | Добавяме с първия общ export service |
| P1 | `spatie/laravel-activitylog` | Action log за mutations и администрация | Добавяме преди push/note/ignore/print-queue actions |
| P1 | `spatie/laravel-health` | Worker, scheduler, disk, DB и backup health | Добавяме след queue/scheduler foundation |
| P1 | `spatie/laravel-backup` | DB и artifact backups, retention и monitoring | Добавяме в deployment/operations фазата |
| P1 | `sentry/sentry-laravel` | Production exceptions и failed jobs | Добавяме при избор на Sentry за production |
| P2 | `laravel/horizon` | Redis queue dashboard и balancing | Само ако production queue стане Redis |
| P2 | `laravel/pulse` | Slow requests/jobs и usage dashboard | След production DB и worker foundation |
| P2 | `spatie/laravel-csp` | Управлявана Content Security Policy | При security hardening, след asset/OAuth inventory |
| P2 | `laravel/slack-notification-channel` | Laravel Slack notifications | При notification rules, ако webhook adapterът стане Notification channel |

## P0 — пакети с пряка стойност за оставащия rewrite

### 1. `laravel/socialite`

Legacy приложението има Google login, а Laravel rewrite-ът още няма provider,
redirect и callback. Socialite е first-party решението и поддържа Google,
OAuth state flow и fake provider за тестове. Това премахва нуждата да поддържаме
собствен OIDC HTTP клиент.

Предложена употреба:

- Google redirect и callback routes;
- връзване само към съществуващ или разрешен потребител;
- проверка на verified email и разрешен hosted domain;
- session regeneration след успешно влизане;
- rate limit и безопасни callback грешки;
- тестове чрез `Socialite::fake()`, без реална заявка към Google.

Инсталация при започване на feature-а:

```bash
composer require laravel/socialite
```

Източник: [Laravel 13 Socialite documentation](https://laravel.com/framework/docs/socialite).

### 2. `larastan/larastan`

**Статус: внедрен.** `composer analyse` е задължителен Laravel CI gate, а
конфигурацията е в `laravel/phpstan.neon`. Началният scope минава без baseline и
без потиснати грешки. Следващото разширяване е към `app/Http` и `app/Models`,
след което analysis level може да се повишава поетапно.

Проектът вече има значителен integration слой, нормализатори и масивни report
DTO структури. PHPUnit проверява поведението, но не намира всички грешни array
shapes, nullable стойности и несъвместими return types. Larastan boot-ва Laravel
container-а и добавя PHPStan правила за framework magic. Текущият пакет декларира
поддръжка за Laravel 13.

Предложен rollout:

1. Инсталиране като dev dependency.
2. Анализ първо на `app/Application`, `app/Domain` и `app/Integrations`.
3. Ниско начално ниво без огромен baseline.
4. Отделен CI gate, който не допуска нови грешки.
5. Постепенно повишаване на нивото след rewrite parity.

```bash
composer require --dev larastan/larastan
```

Източници: [Larastan repository](https://github.com/larastan/larastan),
[Laravel 13 compatibility on Packagist](https://packagist.org/packages/larastan/larastan).

### 3. `league/csv`

Почти всички legacy audit таблици имат CSV export, има CSV attachments и import
на ignored orders. Един общ export service трябва да осигури streaming, UTF-8,
стабилни headers, безопасни filenames и защита от spreadsheet formula injection.
League CSV работи със streams и има `EscapeFormula`; това пасва по-добре от тежък
Excel package, защото настоящият contract е CSV, а не XLSX.

Пакетът не отменя нашите правила. Export service-ът пак трябва да решава кои
полета се разрешават, да нормализира encoding, да добавя download authorization
и да тества стойности, започващи с `=`, `+`, `-`, `@`, tab и carriage return.

```bash
composer require league/csv
```

Източници: [League CSV documentation](https://csv.thephpleague.com/),
[formula-injection formatter](https://csv.thephpleague.com/9.0/interoperability/escape-formula-injection/).

## P1 — пакети за platform extras и production readiness

### 4. `spatie/laravel-activitylog`

Подходящ е за `Action Log`, защото пази actor, subject и properties в DB и може
да записва Eloquent model events. Трябва да го използваме изрично за действия с
ефект: Shopify → ShipStation push, Shopify note update, ignore/unignore, print
queue, store/user changes и retry/cancel на jobs.

Не трябва автоматично да логваме encrypted credentials, access tokens, пълни
API payloads или customer PII. Ще използваме allowlist от свойства и отделен
`store_id`, `action`, `outcome`, `target_type`, `target_id` contract.

```bash
composer require spatie/laravel-activitylog
```

Източник: [Spatie Laravel Activitylog documentation](https://spatie.be/docs/laravel-activitylog/v5/introduction).

### 5. `spatie/laravel-health`

Сегашните `/up`, `/ready` и API Health имат различни цели. Пакетът е подходящ
за периодичните operational checks и историята им: database, cache, queue,
scheduler freshness, disk, backup age и custom Shopify/ShipStation checks.
Custom checks връщат `ok`, `warning` или `failed` и могат да пазят summary/meta.

Не бих заменил евтиния `/up`. `/ready` също трябва да остане бърз и да не чака
външни API-та. По-тежките проверки се изпълняват от scheduler и се показват от
записан последен резултат.

```bash
composer require spatie/laravel-health
```

Източници: [Laravel Health overview](https://spatie.be/index.php/docs/laravel-health/v1/introduction),
[custom checks](https://spatie.be/docs/laravel-health/v1/basic-usage/creating-custom-checks).

### 6. `spatie/laravel-backup`

Покрива липсващите scheduled database/artifact backups, retention cleanup,
backup health и failure notifications. Може да пише към Laravel filesystem
disks, включително отделен remote disk. Подходящ е след окончателния избор на
production DB и artifact storage.

Пакетът не е restore стратегия сам по себе си. Definition of done включва
encrypted/off-host backup, документиран restore, реална restore репетиция,
retention, ownership и известяване при стар или неуспешен backup.

```bash
composer require spatie/laravel-backup
```

Източници: [Laravel Backup overview](https://spatie.be/docs/laravel-backup/v10/introduction),
[installation and scheduling](https://spatie.be/docs/laravel-backup/v10/installation-and-setup).

### 7. `sentry/sentry-laravel`

Това е препоръчаният hosted error tracker, ако няма вече избрана фирмена
платформа. Полезен е за exception grouping, release correlation, request errors
и failed jobs. Добавяме го едва когато са определени production environment,
DSN secret, sampling, retention и PII scrubbing.

Sentry не замества нашия run history, action log или business metrics. Shopify
tokens, ShipStation credentials, email/address payloadи и CSV съдържание трябва
да бъдат изрязани преди изпращане.

```bash
composer require sentry/sentry-laravel
```

Източник: [Sentry Laravel SDK documentation](https://docs.sentry.io/platforms/php/guides/laravel/).

## P2 — условни пакети

### 8. `laravel/horizon`

Horizon дава first-party dashboard, worker configuration, balancing и queue
visibility, но работи само с Redis queues. Затова първо изграждаме audit jobs,
idempotency и retry contracts върху Laravel Queue. Ако production остане на
database queue, използваме `queue:work`, failed jobs и `queue:monitor`, без
Horizon. Ако изберем Redis, Horizon става силна препоръка.

```bash
composer require laravel/horizon
```

Източник: [Laravel Horizon documentation](https://laravel.com/framework/docs/12.x/horizon).

### 9. `laravel/pulse`

Pulse е полезен за slow endpoints, slow jobs, exceptions и usage overview.
Има смисъл след background audit pipeline-а, когато вече има какво да се
наблюдава. First-party storage изисква MySQL, MariaDB или PostgreSQL; при SQLite
ще е нужна отделна поддържана база. Production dashboard-ът трябва да е само за
admin и данните да се trim-ват.

```bash
composer require laravel/pulse
```

Източник: [Laravel 13 Pulse documentation](https://laravel.com/framework/docs/pulse).

### 10. `spatie/laravel-csp`

Подходящ е при security hardening за централизирана CSP policy и nonce support.
Инсталираме го след инвентар на Vite assets, Google OAuth endpoints и всички
външни изображения/скриптове. CSP започва в report-only режим, след което става
enforced с feature/browser smoke tests.

```bash
composer require spatie/laravel-csp
```

Източник: [Spatie package catalogue](https://spatie.be/index.php/open-source/packages).

### 11. `laravel/slack-notification-channel`

Laravel Notifications вече покрива queueing, mail и database delivery. Ако
Slack rules трябва да използват същия notification pipeline, first-party Slack
channel е разумен избор. Ако изискването остане само един Incoming Webhook с
кратък payload, малък adapter върху Laravel HTTP client е по-прост.

Discord няма достатъчна причина за отделен package: webhook payloadът е малък,
а custom Laravel notification channel/HTTP adapter ще ни даде точен контрол над
лимити, escaping, retries и duplicate protection.

Източник: [Laravel notification documentation](https://laravel.com/docs/13.x/notifications).

## Пакети, които засега не препоръчвам

| Пакет/категория | Причина |
|---|---|
| `spatie/laravel-permission` | Имаме само три стабилни роли и ясни Gates. DB-driven permissions ще добавят schema, cache и admin сложност без текуща полза. |
| `maatwebsite/excel` | Legacy contract-ът е CSV. XLSX styling, imports и PhpSpreadsheet dependency tree не са нужни сега. |
| `laravel/sanctum` / Passport | Приложението няма public/token API. Session auth е правилният contract за текущия UI. |
| Socialite provider plugins | Google е в core Socialite; допълнителен provider package не е нужен. |
| Shopify PHP SDK | Текущият GraphQL client има точни query, pagination, normalization и failure contracts. SDK migration ще отвори голяма parity повърхност без ясна печалба. |
| ShipStation SDK wrappers | Няма официална зависимост, която да замени надеждно текущия тесен client contract. |
| Livewire / Inertia / SPA framework | Настоящите Blade screens и малко JavaScript са достатъчни. UI framework migration ще забави feature parity. |
| Laravel Scout | Global search първо може да работи с индексирани DB колони. Scout се разглежда само при измерен проблем и избран външен engine. |
| PDF package | Packing slip е print-optimized HTML. PDF dependency се добавя само при изрично server-side PDF изискване. |
| Laravel Dusk | Официалната Laravel 13 документация насочва новите проекти към Pest browser testing. Проектът вече е на PHPUnit, затова browser runner избираме отделно при UAT фазата, без да инсталираме Dusk предварително. |
| Telescope в production | Полезен е локално за подробна диагностика, но добавя storage и sensitive-data риск. `laravel/pail` вече е наличен за logs; Telescope може да бъде временно dev-only средство при конкретен debugging проблем. |

## Предложен ред за внедряване

1. **Larastan quality slice** — dependency, config, Composer script и CI gate.
2. **Google login slice** — Socialite, domain policy и fake-based feature tests.
3. **Common CSV slice** — League CSV, streamed downloads, formula escaping и
   първите готови report exports.
4. **Audit run persistence и queue foundation** — първо native Laravel Queue,
   jobs, locks, retries и run history.
5. **Action log slice** — Activitylog преди първата външна mutation.
6. **Notification slice** — native Mail/Notifications; Slack package само ако
   общият channel contract го оправдава; Discord custom channel.
7. **Operations slice** — Health и Backup, scheduler, worker freshness и restore
   rehearsal.
8. **Production observability** — Sentry; Pulse след production DB решение;
   Horizon само след Redis решение.
9. **Security hardening** — CSP report-only, после enforced policy.

## Критерий преди добавяне на всеки пакет

- Има започнат feature, който го използва веднага.
- Последната stable версия позволява PHP 8.5 и Laravel 13 според Composer.
- License, release activity и security advisories са прегледани.
- Config и migrations са минимални и са включени в review diff-а.
- Package routes/dashboards имат изрична admin authorization.
- Telemetry, logs и persisted payloads не съдържат secrets или ненужна PII.
- Има тест за нашия contract; не тестваме вътрешната реализация на пакета.
- Има описан upgrade/removal path и dependency audit в CI.
