# Workspace Control Center

A multi-organisation portal for managing a shared Google Workspace customer: each organisation manages only its own
email accounts, domains, invoices and payments, while an internal Super Admin team keeps full control. Billing is a
fixed monthly price per mailbox, calculated here and invoiced through Zoho Books.

**Status:** Phases A–G complete (foundation, organisations, Google Workspace and domains, billing, Zoho Books, notifications/audit/monitoring — integrations in mock mode until connected). See [docs/DEVELOPMENT_PROGRESS.md](docs/DEVELOPMENT_PROGRESS.md) for what
is built, what is tested and what is still to come. Google Workspace and Zoho Books are **not connected yet** (see the go-live checklists in the docs).

## Requirements

- PHP 8.2+ (8.3 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `curl`, `zip`
- Composer 2, Node.js 20+
- MySQL 8 (production). SQLite works for local UI work and is used by the test suite.
- Redis (production queues, cache, sessions). The `database` drivers work locally.

## Installation (server)

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

Then open `/install` in the browser: requirements → database (and optional Redis) → Super Admin → SMTP.
The installer writes a production `.env` and switches itself off. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Local setup (by hand)

```bash
composer install
npm install
cp .env.example .env            # then set APP_ENV=local, APP_DEBUG=true, SESSION_SECURE_COOKIE=false
php artisan key:generate
```

For a quick local start without MySQL/Redis, set in `.env`:

```
DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

then:

```bash
touch database/database.sqlite
php artisan migrate --seed
php artisan wcc:create-super-admin
npm run build
php artisan serve
```

`migrate --seed` creates roles and permissions only. No users or passwords are seeded.
Super Admins must set up two-factor authentication at first sign-in (`WCC_ENFORCE_2FA`).

Optional fictional demo data (local environment only): set `WCC_DEV_PASSWORD` in `.env`, then
`php artisan db:seed --class=LocalDevelopmentSeeder`.

## Tests

```bash
php artisan test
```

## Documentation

| Document | Contents |
| --- | --- |
| [CLAUDE.md](CLAUDE.md) | Permanent engineering rules for this repository |
| [docs/PRODUCT_REQUIREMENTS.md](docs/PRODUCT_REQUIREMENTS.md) | Product specification |
| [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) | Modules, layers, request flow |
| [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) | Tables, constraints, deletion policy |
| [docs/ROLE_PERMISSION_MATRIX.md](docs/ROLE_PERMISSION_MATRIX.md) | Roles, abilities, scoping rules |
| [docs/GOOGLE_WORKSPACE_INTEGRATION.md](docs/GOOGLE_WORKSPACE_INTEGRATION.md) | Provider design (Phase C) |
| [docs/DOMAIN_WORKFLOW.md](docs/DOMAIN_WORKFLOW.md) | Domain request and verification (Phase D) |
| [docs/BILLING_ENGINE.md](docs/BILLING_ENGINE.md) | Pricing, proration, invoices (Phase E) |
| [docs/ZOHO_INTEGRATION.md](docs/ZOHO_INTEGRATION.md) | Zoho Books (Phase F) |
| [docs/API_REFERENCE.md](docs/API_REFERENCE.md) | `/api/v1` endpoints |
| [docs/SECURITY.md](docs/SECURITY.md) | Security controls |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production deployment |
| [docs/DEVELOPMENT_PROGRESS.md](docs/DEVELOPMENT_PROGRESS.md) | Phase-by-phase status |
| [docs/KNOWN_LIMITATIONS.md](docs/KNOWN_LIMITATIONS.md) | Limitations and open decisions |
