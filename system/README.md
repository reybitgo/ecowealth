# EcoWealth — application (`system/`)

Membership, shop and e-wallet for **EcoWealth**, built as plain PHP 8 + MySQL with no
framework and no Composer. This folder is the backend application; the public marketing
front door is the repo-root `index.html` (decision D23 in `dmp/plan-ecowealth-cart-system.md`).

**Current status: Phase 3 — packages & referral ladder.** Phase 1 delivered the skeleton
(router, layouts, roles, settings, test harness); Phase 2 added the product catalogue and
storefront; Phase 3 adds associate packages — price, direct bonus, the 2–10 unilevel
matrix, package contents, the admin editor and the member packages page. Cart, checkout,
wallet, referrals and payouts follow in later phases; their menu entries render as
disabled "Soon" items while `APP_DEBUG` is on and are hidden in production.

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
│   ├── routes.php             the live route table (Phase 1 + 2 + 3 routes)
│   ├── routes_inventory.php   all 98 reference pages → route / page / CLI / drop / defer
│   ├── Core/                  Router, Request, View, Validator, Paginator, Upload, MaintenanceGate
│   ├── Controllers/           Home, Shop, Package, AdminDashboard, AdminProduct, AdminPackage, Profile
│   ├── Models/                Product, Package
│   ├── Services/              StockService, UploadService, PackageService
│   ├── views/                 errors/, partials/, shop/, admin/, profile/
│   └── layout/                app_header.php, app_footer.php, sidebar.php, nav.php, auth_*
├── public/                    docroot
│   ├── index.php              front controller
│   ├── router.php             PHP built-in server parity
│   ├── login.php register.php logout.php dashboard.php   (standalone this build)
│   ├── uploads/               products/, packages/, proofs/ — PHP execution disabled
│   └── assets/                EcoWealth theme (Sneat 3.x) + product photos
├── database/
│   ├── schema.sql             fresh-install DDL (no database name hard-coded)
│   ├── migrations/            001_settings_roles, 002_products, 003_packages
│   └── seeds/                 settings, accounts, demo catalogue, demo packages
└── tests/
    ├── bootstrap.php          rebuilds ecowealth_sneat_test, assertions, fixtures
    ├── run.php                php tests/run.php [filter]
    ├── smoke.ps1              HTTP smoke test (P1-T1 … P3-T8)
    ├── Cases/                 Skeleton, Router, Parity, Settings, View, Upload, Migration, Product, ProductView, Package, PackageView
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
| GET | `/shop` | member | Storefront catalogue (Phase 2) |
| GET | `/shop/{id}` | member | Product detail (404 for inactive products) |
| GET | `/admin` | admin | Back-office dashboard |
| GET | `/profile/password` | member | Change password form |
| POST | `/profile/password` | member | Change password |

### Phase 2 URLs — product catalogue

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/admin/products` | admin | Catalogue table with search, status filter, pagination |
| GET | `/admin/products/new` | admin | New product form |
| GET | `/admin/products/{id}/edit` | admin | Edit product form |
| POST | `/admin/products/save` | admin | Create / update (image upload, remove image) |
| POST | `/admin/products/{id}/status` | admin | Enable / disable (JSON for XHR) |
| POST | `/admin/products/{id}/delete` | admin | Delete unless referenced by an order or cart (JSON for XHR) |

### Phase 3 URLs — packages

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/packages` | member | Package cards: price, direct bonus, 10-level ladder, contents |
| GET | `/admin/packages` | admin | Package table with search, status filter, pagination |
| GET | `/admin/packages/new` | admin | New package form (ladder matrix + contents repeater) |
| GET | `/admin/packages/{id}/edit` | admin | Edit package form |
| POST | `/admin/packages/save` | admin | Create / update package, ladder and contents in one transaction |
| POST | `/admin/packages/{id}/status` | admin | Enable / disable (JSON for XHR; packages are never deleted) |

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

## Catalogue (Phase 2)

- `products.stock` is the **physical** count on hand. `available` is derived by
  `StockService`: `stock − reserved`, where reserved comes from non-terminal order lines
  whose `reservation_state` is `reserved` or `committed`. The order tables arrive in
  Phase 6, so until then availability equals the physical count — the service starts using
  them automatically once they exist.
- The storefront lists **active** products, including sold-out ones (badge logic:
  `In stock`, `N left` at 5 or fewer, `Sold out`). Deactivating a product hides it from
  the shop and its detail URL returns 404; existing orders are never edited.
- Prices are display-only here; checkout re-reads them in Phase 6.
- Product images are stored under `public/uploads/products/` with random names. Seeded
  demo products reference photos under `public/assets/img/products/`; two rows
  deliberately have no image so the placeholder path is exercised.
- Deleting a product is refused while an order line or cart line references it; the admin
  list disables the button and names the reason (order history vs. a member's cart).

---

## Packages (Phase 3)

- A package stores its entry `price`, display `product_value` and `direct_referral_bonus`.
  **Level 1 of the referral ladder *is* the direct bonus** — it is saved once on the
  package row and never written to `package_levels`, so the two can't drift apart. The
  admin form shows it read-only, bound to the direct-bonus field.
- `package_levels` holds levels **2–10** only (9 rows per package, zero-filled, CHECK
  constrained to 2–10). `package_products` holds the contents (product + quantity); only
  active products can be added, an already-linked product that later went inactive may
  stay, and duplicate rows are merged by adding their quantities.
- Saving a package rewrites the package row, its ladder and its contents inside a single
  transaction — a failure leaves nothing half-written.
- Packages are **never deleted**: the status toggle (active/inactive) is the supported
  path, so history is preserved. `GET /packages` lists active packages only.
- The public ladder table shows all ten levels, marks level 1 as "stored on the package"
  and displays only the amounts configured — nothing is invented in the view.

---

## Tests

```bash
php tests/run.php                 # full suite (140 cases in Phase 3)
php tests/run.php Router           # cases whose group/label matches "Router"
php tests/run.php --probe-failure  # proves a failing assertion exits non-zero (P1-T7)

powershell -NoProfile -File tests/smoke.ps1    # HTTP checks against the running app
```

- `tests/bootstrap.php` drops and recreates `ecowealth_sneat_test` from `schema.sql` and
  the seeds, so a run never touches the development database (the name must end in `_test`).
- Every case runs inside a transaction that is rolled back. Cases that need later-phase
  tables (cart/order references) or their own transaction (package rollback) handle that
  explicitly, because DDL commits.
- Uploads are written to a scratch folder (`UPLOAD_ROOT`) under the system temp directory.
- The migration test builds `ecowealth_sneat_migration_test` from
  `tests/fixtures/legacy_phase0_schema.sql`, applies migrations 001–003 in order and
  compares the result with `schema.sql`.

The smoke script needs the app running and MySQL access for temporary changes (a throw-away
member account, products with SKU prefix `SMOKE-`, packages named `Smoke Package…`, and
`maintenance_mode`; all restored):

```powershell
powershell -NoProfile -File tests/smoke.ps1 -DbUser root -DbPass ''
```

---

## Migrations

- Fresh install: `database/schema.sql` (no database name hard-coded), then `database/seeds/*.sql`.
- Existing install: apply `database/migrations/NNN_*.sql` in order, then the phase seeds.
- Before every phase gate: `mysqldump` the database to a backup outside the repo.

| Migration | Adds |
|---|---|
| `001_settings_roles.sql` | `settings`, `audit_log`, `users.role` → `member/admin/superadmin`, `idx_users_role` |
| `002_products.sql` | `products` (+ unique `sku` / `slug`, status index) |
| `003_packages.sql` | `packages`, `package_levels` (levels 2–10, CHECK 2–10), `package_products` |

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
