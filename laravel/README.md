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

The rewrite now has its first application layer: session authentication with
login throttling, viewer/operator/admin roles, encrypted store credentials,
user-to-store access, and deterministic active-store selection. The dashboard
is intentionally a status shell; Shopify and ShipStation workflows have not
been moved yet.
