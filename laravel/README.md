# Shopify Ops — Laravel rewrite

This directory contains the in-progress Laravel replacement for the stable
plain-PHP application in the repository root.

The two applications are intentionally isolated during the rewrite. The
plain-PHP application remains the production version until the Laravel feature
parity checklist is complete. No legacy runtime data will be imported: the
Laravel application starts with a new database and fresh operational state.

See the [rewrite plan](../docs/laravel-rewrite.md) for scope, architecture,
delivery phases, and cutover rules.

## Requirements

- PHP 8.5+
- Composer
- Node.js 24+
- pnpm 11.15.1
- SQLite with the `pdo_sqlite` PHP extension

## Local setup

From this directory:

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan ops:install
```

Install and build frontend dependencies from the repository root:

```bash
pnpm install
pnpm --filter shopify-ops-laravel build
```

Start the application:

```bash
php artisan serve
```

The framework health endpoint is available at `/up`.

## Checks

```bash
composer test
vendor/bin/pint --format agent
composer audit
```

## Current milestone

The rewrite now has session authentication, viewer/operator/admin roles,
encrypted store credentials, deterministic active-store selection, a guarded
first-install command, and administration screens for users, roles, store
access, stores, and integration credentials. Store-scoped Shopify and
ShipStation clients back the first migrated read-only workflow: single-order
lookup across both systems with detailed address, status, fulfillment, and
SKU/quantity comparison. The dashboard remains a migration status shell.
