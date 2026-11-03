# EcoWealth — application (`system/`)

Membership, shop and e-wallet for **EcoWealth**, built as plain PHP 8 + MySQL with no
framework and no Composer. This folder is the backend application; the public marketing
front door is the repo-root `index.html` (decision D23 in `dmp/plan-ecowealth-cart-system.md`).

**Current status: Phase 1 — core skeleton** (router, layouts, roles, settings, test harness).
The shop/checkout modules from Phase 2 onwards are not in this folder yet; menu entries for
later phases render as disabled "Soon" items while `APP_DEBUG` is on and are hidden in production.

| | |
|---|---|
| App URL (Laragon) | `http://localhost/ecowealth/system/` |
| Front door | `http://localhost/ecowealth/` → repo-root `index.html` |
| Plan | `dmp/plan-ecowealth-cart-system.md` |
| Running log | `docs/phase-log.md` |

---

## Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | **8.1+** (runs on 8.3.16) | `pdo_mysql`, `fileinfo` |
| MySQL | 8.x (runs on 8.4.3) | `utf8mb4` / `utf8mb4_unicode_ci` |
| Web server | Apache 2.4 + `mod_rewrite` | or PHP's built-in server via `public/router.php` |

---

## Quick start (Laragon)

1. **Start All** in Laragon (Apache + MySQL).
2. **Create the database and account** (once), then put the credentials in
   `app/config.local.php` (git-ignored — copy the shape from `config.example` section below):

   ```sql
   CREATE DATABASE ecowealth_sneat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'ecowealth'@'localhost' IDENTIFIED BY '<password>';
   CREATE USER 'ecowealth'@'127.0.0.1' IDENTIFIED BY '<password>';
   GRANT ALL PRIVILEGES ON ecowealth_sneat.* TO 'ecowealth'@'localhost', 'ecowealth'@'127.0.0.1';
   ```

3. **Import the schema and seeds**

   ```bash
   mysql -u root ecowealth_sneat < database/schema.sql
   mysql -u root ecowealth_sneat < database/seeds/001_settings.sql
   mysql -u root ecowealth_sneat < database/seeds/002_accounts.sql
   ```

4. **Open** `http://localhost/ecowealth/system/` and sign in.

### Demo accounts

| Username | Password | Role |
|---|---|---|
| `admin` | `Admin@123` | `admin` |
| `superadmin` | `Super@123` | `superadmin` (S-Login console, Phase 14) |

These are development credentials. Plan §9.6 requires both to be changed and the demo
accounts removed before production.

### Built-in PHP server (no Apache)

```bash
php -S 127.0.0.1:8080 -t public public/router.php
# open http://127.0.0.1:8080
```

`router.php` mirrors `public/.htaccess`: static files stream through, the four standalone
pages keep their scripts, everything else goes to the front controller.

---

## Configuration

| File | Purpose |
|---|---|
| `app/config.php` | Defaults and flags, every constant declared with `defined() || define()` |
| `app/config.local.php` | **Git-ignored.** Local DB credentials; optional `APP_ENV` |

| Constant | Default | Meaning |
|---|---|---|
| `APP_ENV` | `production` (local: `development`) | `development` shows debug detail and "Soon" nav items |
| `APP_DEBUG` | derived from `APP_ENV` | Debug detail on error pages |
| `APP_PHASE` | `1` | Build phase, drives later-phase nav visibility |
| `APP_BASE_URL` | auto-detected | Set by `public/index.php` before bootstrapping |
| `DB_NAME` | `ecowealth_sneat` (`ecowealth_sneat_test` when `APP_ENV=test`) | Override with the `ECO_DB_NAME` environment variable |
| `CURRENCY_SYMBOL` | `₱` | Used by `money()` |
| `UPLOAD_MAX_BYTES` | 5 MB | Upload cap |

`config.local.php` must **not** define `DB_NAME` — `APP_ENV=test` selects the test schema.

---

## Directory layout

```
system/
├── app/
│   ├── bootstrap.php          config → helpers → autoload → database → auth
│   ├── config.php             defaults + flags
│   ├── config.local.php       local overrides (git-ignored)
│   ├── database.php           lazy PDO singleton, db()
│   ├── auth.php               login/register/session, guards, roles
│   ├── helpers.php            url(), e(), csrf, flash, money(), settings, nav
│   ├── autoload.php           Core/Models/Services/Controllers by class name
│   ├── routes.php             the live route table (Phase 1 routes only)
│   ├── routes_inventory.php   all 98 reference pages → route / page / CLI / drop / defer
│   ├── Core/                  Router, Request, View, Validator, Paginator, Upload, MaintenanceGate
│   ├── Controllers/           HomeController, ShopController, AdminDashboardController, ProfileController
│   ├── Models/                (Phase 2+)
│   ├── Services/              (Phase 5+)
│   ├── views/                 errors/, partials/, shop/, admin/, profile/
│   └── layout/                app_header.php, app_footer.php, sidebar.php, nav.php, auth_*
├── public/                    docroot
│   ├── index.php              front controller
│   ├── router.php             PHP built-in server parity
│   ├── login.php register.php logout.php dashboard.php   (standalone this build)
│   ├── uploads/               products/, packages/, proofs/ — PHP execution disabled
│   └── assets/                EcoWealth theme (Sneat 3.x)
├── database/
│   ├── schema.sql             fresh-install DDL (no database name hard-coded)
│   ├── migrations/            one file per phase (001_settings_roles.sql)
│   └── seeds/                 settings defaults, demo accounts
└── tests/
    ├── bootstrap.php          rebuilds ecowealth_sneat_test, assertions, fixtures
    ├── run.php                php tests/run.php [filter]
    ├── smoke.ps1              HTTP smoke test (P1-T1 … P1-T11)
    ├── Cases/                 Skeleton, Router, Parity, Settings, View, Upload, Migration
    └── fixtures/              legacy schema fixture for the migration test
```

---

## Routing and guards

Requests enter `public/index.php`, which pins `APP_BASE_URL`, boots the app, runs the
maintenance gate, matches `app/routes.php`, enforces the guard, verifies the CSRF token on
POST, and dispatches to a controller.

Route rows: `[METHOD, '/path/{id}', [Controller::class, 'method'], guard]` where `{id}`
compiles to `([0-9]+)`. Guards:

| Guard | Behaviour |
|---|---|
| `any` | Public (only `/` and the register validation endpoints in later phases) |
| `guest` | Signed-in visitors are sent to `/dashboard` |
| `member` | Guests are redirected to `/login` with a flash |
| `admin` | Guests → `/login`; signed-in non-admins → **403 page** (never a redirect loop) |
| `superadmin` | Like `admin`, plus `role = superadmin` |

Roles: `users.role ∈ {member, admin, superadmin}`.

### Phase 1 URLs

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/` | any | Redirect to `/dashboard` (signed in) or `/login` |
| GET | `/shop` | member | Storefront placeholder (catalogue in Phase 2) |
| GET | `/admin` | admin | Back-office dashboard |
| GET | `/profile/password` | member | Change password form |
| POST | `/profile/password` | member | Change password |

Standalone pages (outside the router this build, all routed through Apache rewrites):
`/login`, `/register`, `/logout`, `/dashboard`.

Every route is mirrored in `app/routes_inventory.php`, which maps all 98 reference pages.
`tests/Cases/ParityTest.php` (P1-T12) fails when a page marked *Port* has no matching
route or a later-phase route is registered early.

---

## Settings

`settings` is a key/value table read through `setting()` / `setting_bool()` /
`setting_int()` / `setting_float()` and written with `setting_set()`. The Phase 1 seeds
cover payment accounts, shop windows, commission toggles, payout fees, USDT rates and
e-wallet transfer limits; later phases read them without further migrations.

Maintenance mode is `maintenance_mode=1`: guests see a branded **503** with
`Retry-After: 3600`, admin and superadmin sessions pass straight through.

---

## Tests

```bash
php tests/run.php                 # full suite (77 cases in Phase 1)
php tests/run.php Router           # cases whose group/label matches "Router"
php tests/run.php --probe-failure  # proves a failing assertion exits non-zero (P1-T7)

powershell -NoProfile -File tests/smoke.ps1    # HTTP checks against the running app
```

- `tests/bootstrap.php` drops and recreates `ecowealth_sneat_test` from `schema.sql` and
  the seeds, so a run never touches the development database (the name must end in `_test`).
- Every case runs inside a transaction that is rolled back.
- Uploads are written to a scratch folder (`UPLOAD_ROOT`) under the system temp directory.
- The migration test builds `ecowealth_sneat_migration_test` from
  `tests/fixtures/legacy_phase0_schema.sql` and verifies migration 001 against `schema.sql`.

The smoke script needs the app running and MySQL access for two temporary changes
(a throw-away member account and `maintenance_mode`, both restored):

```powershell
powershell -NoProfile -File tests/smoke.ps1 -DbUser root -DbPass ''
```

---

## Migrations

- Fresh install: `database/schema.sql` (no database name hard-coded).
- Existing install: apply `database/migrations/NNN_*.sql` in order, then the phase seeds.
- Before every phase gate: `mysqldump` the database to a backup outside the repo.

Phase 1 migration: `001_settings_roles.sql` — creates `settings` + `audit_log`, renames the
`user` role to `member` in three steps, adds `idx_users_role`.

---

## Conventions

- `declare(strict_types=1)`, 4 spaces, classes in `app/Core|Models|Services|Controllers`.
- Views escape with `e()`; no SQL and no business rules in views.
- All POSTs are CSRF protected (central check in the router for routed POSTs).
- Forms redirect after POST; validation errors go through `errors_set()` / `error_for()`.
- Money is `DECIMAL` in MySQL and formatted with `money()`; no floats in SQL.
- Every wallet movement will be a `WalletService` call inside a transaction (Phase 5).

---

## Security baseline

- bcrypt password hashing, `password_verify()` on login, rehash on cost change.
- PDO prepared statements everywhere (`EMULATE_PREPARES = false`).
- CSRF token per session, `hash_equals()` comparison; central check for routed POSTs.
- Output escaping via `e()`.
- Session hardening: HttpOnly, SameSite=Lax, strict mode, ID regeneration, 30-minute idle.
- Remember-me tokens stored hashed and rotated; cleared when the password changes.
- Uploads: MIME sniffing with `finfo`, extension whitelist, size cap, random filename,
  PHP execution disabled under `public/uploads/`.
- Admin guards enforced server-side; menus only hide links, never replace the guard.
- Error pages never leak internals unless `APP_DEBUG` is on.

---

## Licence

- Application code in `app/`, `public/*.php`, `database/`, `tests/`: free to use and modify.
- Theme assets in `public/assets/` are the Sneat/Bootstrap template — review
  [ThemeSelection's licence](https://themeselection.com/sneat-bootstrap-html-admin-template/)
  before commercial use.
