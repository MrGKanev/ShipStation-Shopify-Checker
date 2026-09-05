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

The foundation milestone establishes a Laravel 13 application compatible with
PHP 8.5 and Guzzle 8, SQLite schema setup, database-backed queues/cache, the
framework health endpoint, integration configuration placeholders, tests, and
CI. Business workflows have not been moved yet.
