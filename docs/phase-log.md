# EcoWealth — Phase Log

Running record of decisions, migrations, backups and test results. One section per phase; append, never rewrite history.

---

## Phase 0 — Setup, baseline and decisions

**Date:** 2026-11-03 · **Branch:** `feature/shop` · **Baseline tag:** `phase-0-start` (on `b96b745` "updated phased plan for ecowealth cart system") · **Status:** complete except a partial visual baseline (see *Deviations*).

### Environment

| Item | Value |
|---|---|
| PHP | 8.3.16 (Laragon `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64`) |
| MySQL | 8.4.3 (Laragon) — listening on `127.0.0.1:3306` |
| Web server | Apache on `http://localhost` (ports 80/443) |
| App URL | `http://localhost/ecowealth/system/` |
| Front door | `http://localhost/ecowealth/` → repo-root `index.html` (D23) |
| PHP extensions | `pdo_mysql` present |

### Repository

- Branch `feature/shop` created from `main`; annotated tag `phase-0-start` on the pre-migration commit.
- `.gitignore` added: `config.local.php`, `system/public/uploads/*` (keeps `.gitkeep`), `*.log`, `backup_*.sql`, `backups/`, `tests/tmp/`, `system/tests/tmp/`.
- Verified ignored: `system/app/config.local.php` (`.gitignore:7`), `backups/x.sql` (`backups/`), `tests/tmp/a.txt`.

### Backups (outside the repo)

| File | Size | Contents |
|---|---|---|
| `C:\laragon\www\ecowealth-backups\phase-0\app-baseline-20261103.zip` | 33.5 MB | `system/`, `index.html`, `assets/` |
| `C:\laragon\www\ecowealth-backups\phase-0\db-if0_42820471_ew1_db-20261103.sql` | 4 KB | `mysqldump --databases if0_42820471_ew1_db` |
| `login.png` | 51 KB | 1440×900 baseline screenshot (login page) |
| `register.png` | 47 KB | 1440×900 baseline screenshot (register page) |

### Database

- **Created** `ecowealth_sneat` (`utf8mb4` / `utf8mb4_unicode_ci`) — intentionally empty; the target schema arrives in Phase 1.
- **Dedicated account** `ecowealth`@`localhost` and `ecowealth`@`127.0.0.1`; password lives only in `system/app/config.local.php`. Grants: `ALL` on `ecowealth_sneat.*` and on the pre-existing app DB `if0_42820471_ew1_db.*` (so the baseline keeps working until Phase 1 migrates).
- **Retained** the existing app DB `if0_42820471_ew1_db` (1 user: `admin`, role `admin`, active).
- Root (local, empty password) was used only for provisioning — never by the app.

### Configuration

- `system/app/config.php` — now **defaults only**: loads `config.local.php` first (when present), then declares every constant with `defined() || define()` so local settings and the Phase 1 test harness can override them. Branding: `APP_NAME = EcoWealth`, session cookie `ecowealth_sid`, remember cookie `ecowealth_remember`.
- `system/app/config.local.php` — created and git-ignored: local DB host/name/user/password. `DB_NAME` stays `if0_42820471_ew1_db` for the baseline; **Phase 1 flips it to `ecowealth_sneat`** when the target schema is imported.
- No credentials remain in a tracked file.

### Decisions signed off (plan §12)

| # | Decision | Recorded choice |
|---|---|---|
| D1 | App/brand name | **EcoWealth** (configurable `APP_NAME`) |
| D2 | Guests browse the shop? | **No** — members only |
| D3 | Sponsor required at registration? | **Yes** (seeded root accounts excepted) |
| D4 | Package required to shop? | **No** — shopping open to logged-in members |
| D8 | Shipping fees | **None** for now (`shipping_fee = 0`, field reserved) |
| D9 | Manual payment channels | **GCash / Maya / bank**, set in settings |
| D15 | Registration codes | **In scope** — Phase 9 |
| D17 | Payout fund handling | **Hold at request**, refund on reject |
| D18 | USDT payout rates | **Server-side** rate + gas fee from settings |
| D19 | Super-login transport | **URL-token**, 8 h TTL, IP/UA bound, hashed nonce |
| D22 | Transfer password confirmation | **Required** |
| D23 | Public front door | **`index.html` now**; EcoWealth frontend rebuild later |
| **D10** | **Official product price list & PV** | **OPEN** — placeholder seeds approved for development; the official list is still required before the Phase 2 seed freeze |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P0-T1 | `php -v`, `mysql --version` | **PASS** | PHP 8.3.16; MySQL 8.4.3 |
| P0-T2 | DB connection as the app account | **PASS** | PDO OK (8.4.3) as `ecowealth`; DB `if0_42820471_ew1_db`; 1 user row; `admin` hash verifies with `Admin@123` |
| P0-T3 | Baseline auth flow | **PASS** | `GET /` → 302 `/login`; `GET /login` → 200 (CSRF 64 hex); `POST /login` (admin) → 302 `/dashboard`; `GET /dashboard` → 200 (66,591 bytes, shows `admin`); `GET /register` while signed in → 302 `/dashboard`; `POST /logout` → 302 `/login`. Registration: `POST /register` for `phase0_smoke` → 302 `/login`, row created (`user`, active), login → 302 `/dashboard`, dashboard 200, logout → 302; smoke row deleted afterwards (DB back to 1 user) |
| P0-T4 | Branch/tag + backup | **PASS** | `feature/shop` + `phase-0-start`; backups exist outside the repo; working tree contains only Phase 0 changes |
| P0-T5 | Decisions log populated | **PASS** | This document |

### Deviations and notes

- **Visual baseline.** `login.png` and `register.png` captured at 1440×900. The authenticated dashboard is verified by HTTP only (P0-T3) — headless Chromium cannot hold a signed-in session from the command line. Capturing the browser also required wider one-off access because Chromium needs named pipes, which the sandbox blocks.
- `mysqldump` for the baseline ran as MySQL `root` (local, empty password); the application itself always runs as the dedicated `ecowealth` account.
- The plan's note about InfinityFree credentials is now historical: the repo copy of `config.php` had them, `config.local.php` replaces them with the local account.
- **Plan §2.5 reconciliation:** done in plan v2.1 — 98/98 reference pages mapped, dropped or deferred; re-checked in v2.2 against the completed reference. No further action.

### Gate checklist (plan §7 Phase 0)

- [x] Environment green (PHP / MySQL / Apache; app responds)
- [x] Baseline auth flow captured and passing (P0-T3)
- [x] Decisions recorded (D10 remains open, tracked above)
- [x] Phase log started
- [x] Branch + baseline tag + backups in place
- [x] Visual baseline: login + register captured; dashboard covered by the HTTP smoke test (P0-T3)

### Next

Phase 1 — core skeleton: front controller, route table seeded from §2.5, layouts/nav, role guards, settings, error pages, test harness. First migration step: point `DB_NAME` at `ecowealth_sneat` and import the extended schema.

---

## Phase 1 — Core skeleton: router, layouts, roles, settings, test harness

**Date:** 2026-11-03 · **Branch:** `feature/shop` · **Gate tag:** `phase-1` · **Status:** complete (visual sign-off noted under *Deviations*).

### Delivered

| Area | What shipped |
|---|---|
| **Database** | `settings` + `audit_log`; `users.role` → `enum('member','admin','superadmin')`; fresh-install `database/schema.sql`; `database/migrations/001_settings_roles.sql`; `database/seeds/001_settings.sql` (41 keys) + `002_accounts.sql` (admin + superadmin) |
| **Config** | `APP_ENV` (development/production/test), `APP_DEBUG`, `APP_PHASE`, `CURRENCY_SYMBOL`, `UPLOAD_MAX_BYTES`, `SHOP_ENABLED`/`COMMISSIONS_ENABLED`; every constant is `defined() || define()`; `config.local.php` is credentials-only and git-ignored |
| **Core** | `Router` (route table, `{id}` params, guards, central CSRF), `Request`, `View` (layouts + branded errors), `Validator`, `Paginator`, `Upload` (MIME sniff, whitelist, size cap, random names), `MaintenanceGate` |
| **Routing** | `public/index.php` front controller (base URL → maintenance → match → guard → CSRF → dispatch → 404/500), `public/router.php` dev-server parity, `public/.htaccess` catch-all; `app/routes.php` (5 live routes) + `app/routes_inventory.php` (all 98 reference pages) |
| **Layouts** | Data-driven sidebar from `app/layout/nav.php`; shared `app_header.php`/`app_footer.php` with `member`, `admin` and `plain` (guest/error) layouts; later-phase items render disabled with a "Soon" pill while `APP_DEBUG` is on and are hidden in production |
| **Controllers / views** | `HomeController` (`/` redirect only), `ShopController` (guarded placeholder, Phase 2 catalogue), `AdminDashboardController` (live account counts), `ProfileController` (change password); `errors/{403,404,500,503}.php`, `partials/{flash,pagination,empty_state}.php` |
| **Helpers** | `money()`, `status_badge()`, `setting()/setting_bool/int/float/set/all`, `errors_*`/`has_error`/`invalid_class`, `json_response()`, `redirect_back()`, nav helpers, `detect_app_base_url()`, `base_url_reset()` |
| **Auth** | `is_admin()`, `is_superadmin()`, `is_member()`, `require_member()`, `require_admin()`, `require_superadmin()`; `auth_register()` now writes `role='member'` |
| **Uploads** | `public/uploads/{products,packages,proofs}` with a deny-PHP `.htaccess` |
| **Tests** | `tests/bootstrap.php` (rebuilds `ecowealth_sneat_test`), `tests/run.php` (77 cases), `tests/Cases/{Skeleton,Router,Parity,Settings,View,Upload,Migration}Test.php`, `tests/smoke.ps1` (21 HTTP checks), `tests/fixtures/legacy_phase0_schema.sql` |

### Database changes applied

| Step | Result |
|---|---|
| Backup | `C:\laragon\www\ecowealth-backups\phase-1\db-pre-phase-1.sql` (4 KB, `mysqldump --databases if0_42820471_ew1_db`) |
| Fresh schema | `database/schema.sql` imported into `ecowealth_sneat` (4 tables: `users`, `remember_tokens`, `settings`, `audit_log`) |
| Accounts migrated | the existing `admin` row (id 1, hash preserved) copied from `if0_42820471_ew1_db`; `superadmin` (id 2) seeded; `role` values valid under the new enum |
| Seeds | 41 settings rows; demo passwords `Admin@123` / `Super@123` |
| Privileges | `ecowealth`@`localhost` and `@127.0.0.1` granted ALL on `ecowealth_sneat`, `ecowealth_sneat_test` and `ecowealth_sneat_migration_test` |
| Migration path | verified by `MigrationTest`: legacy fixture → `001_settings_roles.sql` produces the same table list and `users` columns as a fresh `schema.sql` import |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P1-T1 | `GET /shop` as guest | **PASS** | 302 → `/ecowealth/system/login` (smoke) |
| P1-T2 | `GET /admin` as member | **PASS** | 403 + "Access denied" page in the EcoWealth layout (smoke, `ViewTest`) |
| P1-T3 | `GET /admin` as admin | **PASS** | 200, admin menu incl. "Registration codes" (smoke, `ViewTest`) |
| P1-T4 | `GET /no-such-page` | **PASS** | 404 + branded page (smoke, `ViewTest`) |
| P1-T5 | Asset URLs from a deep URL | **PASS** | `/admin/products/9/edit` 404 page carries `/ecowealth/system/assets/…`; all 20 asset URLs on `/admin` return 200; `detect_app_base_url`/`url()`/`pathFrom` unit cases |
| P1-T6 | `POST /logout` with a wrong CSRF token | **PASS** | 302, session stays signed in (`/admin` still 200); routed POST → 302 redirect-back; XHR → 422 JSON `code:"csrf"` |
| P1-T7 | `tests/run.php` | **PASS** | 77/77 cases, exit 0; `--probe-failure` exits 1 |
| P1-T8 | Menu on member vs admin | **PASS** | member pages contain no back-office item; admin menu hides superadmin-only S-Login for an `admin` |
| P1-T9 | Baseline auth still works | **PASS** | login → dashboard → logout; register a new member; change-password page renders with CSRF |
| P1-T10 | `GET /` in the app | **PASS** | guest → 302 `/login`; signed in → 302 `/dashboard`; no landing page inside the app |
| P1-T11 | `maintenance_mode=1` guest vs admin | **PASS** | guest 503 + `Retry-After: 3600`; admin 200 (setting restored afterwards) |
| P1-T12 | Route inventory parity | **PASS** | 98 pages (69 port · 6 defer · 23 drop), unique, every due port target registered, no early route (`ParityTest`) |

### Deviations and decisions

- **Roles include `superadmin`.** The Phase 1 bullet says `enum('member','admin')`; Appendix B's consolidated DDL ends at `enum('member','admin','superadmin')` and §3.4 defines the `superadmin` guard, so the three-value enum was applied (same three-step migration). Existing rows: `user` → `member`.
- **CSRF failure status is 422, not 419.** Laragon's Apache/mod_php rewrites unrecognised status codes (`418`, `419`) to `500` while 422/429/409 pass through — verified with a throw-away probe. The JSON body still carries `"code":"csrf"`, so clients key off the body.
- **Guest hitting an admin URL is redirected to `/login`; a signed-in non-admin gets 403.** The plan's "otherwise 403" is enforced for authenticated members; guests get the same login redirect as every other protected page. No redirect loop exists (login is `guest`-only).
- **`/profile/password` ships in Phase 1.** Appendix A lists it as Phase 1 (`admin_change_password`); the Phase 1 deliverable list omitted it. It gives the member portal a real form and exercises the central CSRF check.
- **`shop` route is a Phase 1 placeholder.** `GET /shop` exists and is guarded (P1-T1) but renders `views/shop/placeholder.php`; the inventory records the page as phase 1 with a note, and Phase 2 replaces the controller body.
- **`admin_settings` / `admin_save_settings` stay Phase 15.** Appendix A mentions settings keys in Phase 7 too; the settings *table*, helpers and seed keys are in place, the admin page arrives with the hardening phase.
- **`public/404.php` removed.** The front controller renders `app/views/errors/404.php` in the layout; the standalone file was dead code. `public/router.php` now delegates non-static traffic to `index.php`.
- **`config.local.php` no longer defines `DB_NAME`.** `APP_ENV=test` selects `ecowealth_sneat_test`; `ECO_DB_NAME` overrides for one-off runs.
- **Standalone pages honour maintenance mode.** `dashboard.php` and `register.php` call `MaintenanceGate::handle()` after their guard; `login`/`logout` deliberately stay open so an admin can sign in during maintenance.
- **Visual sign-off outstanding.** Every page was verified over HTTP (status, markup, asset resolution) but no browser screenshot was captured this phase; the sandbox blocked headless Chromium in Phase 0 as well. The layouts reuse the existing EcoWealth/Sneat theme unchanged apart from the sidebar and navbar refactor.

### Notes for the next phases

- `tests/bootstrap.php` writes nothing into the repository: the schema is rebuilt over MySQL and uploads go to a temp `UPLOAD_ROOT`. Run `php tests/run.php` from `system/`.
- Smoke script: `powershell -NoProfile -File tests/smoke.ps1` (PowerShell 5.1-compatible, ASCII-only source).
- New routes must be added to **both** `app/routes.php` and `app/routes_inventory.php` (or to its `extensions` list) — `ParityTest` fails otherwise.
- Navigation for a new page: add an item to `app/layout/nav.php` and drop its `phase` to `APP_PHASE`.

### Gate checklist (plan §7 Phase 1)

- [x] All P1 tests pass (77 PHP cases + 21 smoke checks)
- [x] `php -l` clean (52 files)
- [x] No 404 asset on any page (all 20 asset URLs on `/admin` resolve)
- [x] Migration applied to the dev DB; `schema.sql` regenerated for fresh installs
- [x] Seeds load on a fresh install (the test harness proves it on every run)
- [x] Baseline auth unaffected (login / register / dashboard / logout)
- [x] No new PHP warnings/notices in the Apache error log
- [ ] Visual sign-off (see *Deviations*) — recommended before the Phase 2 catalogue work
- [x] Backup taken; tag `phase-1`

### Next

Phase 2 — products and storefront catalogue: `products` table (migration 002), admin product CRUD with image upload, `/shop` catalogue + `/shop/{id}`, stock availability (`StockService`), product seeds. The placeholder controller and inventory notes are the starting point.

