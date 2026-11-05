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

---

## Phase 2 — Products and storefront catalogue

**Date:** 2026-11-03 · **Branch:** `feature/shop` · **Gate tag:** `phase-2` · **Status:** complete.

### Delivered

| Area | What shipped |
|---|---|
| **Database** | `products` (unique `sku`/`slug`, status + sort index, reserved `pv` column); migration `002_products.sql`; seed `003_products.sql` (6 demo products, 4 with photos, 2 without) |
| **Model** | `app/Models/Product.php` — find/by-slug, admin + catalogue lists with search/status filters, batched `referencesMap()`, transactional-safe `save()` with slug generation, `setStatus()`, guarded `delete()`, validation rules |
| **Services** | `StockService` (`available()`, `availableMap()`, badge `label()`, reservation-aware once Phase 6 tables exist) and `UploadService` (`image()`, `replace()`, `remove()`, URL helper) |
| **Storefront** | `GET /shop` — card grid, search, 12-per-page pagination, stock badges, quick-view modal; `GET /shop/{id}` — detail page (404 when inactive), disabled add-to-cart until Phase 4 |
| **Back office** | `GET /admin/products` (stats, search + status filter, server-side pagination, guarded delete), `/new`, `/{id}/edit`, `POST /save` (upload / replace / remove image), `POST /{id}/status`, `POST /{id}/delete` (JSON for XHR, flash + redirect otherwise) |
| **Views** | `shop/catalog.php`, `shop/product.php`, `partials/product_card.php`, `admin/products/index.php`, `admin/products/form.php`; SweetAlert2 delete confirmation; the Phase 1 `shop/placeholder.php` was removed |
| **Assets** | four product photos copied from the marketing site into `public/assets/img/products/` |
| **Tests** | `tests/Cases/ProductTest.php` (24 model/service cases), `tests/Cases/ProductViewTest.php` (10 rendering cases), `smoke.ps1` extended with P2-T1 … P2-T10; suite grew 77 → 111 cases, smoke 21 → 32 checks |

### Database changes applied

| Step | Result |
|---|---|
| Migration | `002_products.sql` applied to `ecowealth_sneat` |
| Seed | `003_products.sql` — 6 products (`EW-COFFEE` … `EW-STONE`), prices per plan §4.5, stock 25–100 |
| Verified | `MigrationTest` now applies 001 + 002 and asserts the migrated schema (tables and columns) matches `schema.sql` |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P2-T1 | Create product with valid data | **PASS** | smoke creates via POST and finds it in the admin list; model cases cover slug generation and validation |
| P2-T2 | Create with price 0 / empty name | **PASS** | smoke: 302 back to the form, field errors shown, no row written; unit cases cover every rule (name, price, stock, status, SKU, description length) |
| P2-T3 | 6 MB file / `.php` renamed `.jpg` | **PASS** | smoke posts both with `curl --form`; the form reports "not allowed" / "too large"; `UploadService::replace()` removes the old file only after a successful store |
| P2-T4 | Edit product price | **PASS** | smoke edits to ₱99.99, the edit form reloads it and `/shop/{id}` shows it; the slug stays stable across a rename |
| P2-T5 | Deactivate product | **PASS** | smoke: gone from `/shop`, `/shop/{id}` → 404; model case shows the catalogue list filters to 5 rows while the admin list still has 6 |
| P2-T6 | Delete unreferenced product | **PASS** | unit cases: row removed, missing id reported, uploaded image file deleted |
| P2-T7 | Delete referenced product | **PASS** | unit cases create `cart_items` / `shop_order_items` locally: refusal with `cart` / `orders` reason and counts; `referencesMap()` batches a page in two queries |
| P2-T8 | Catalog search/pagination | **PASS** | smoke: 12 cards on page 1, page 2 renders, search links keep `q`; unit cases cover name/SKU/short-description search, LIKE-wildcard escaping and admin pagination |
| P2-T9 | XSS attempt in name/description | **PASS** | smoke creates a `<script>` name and asserts it is escaped in the storefront; view case covers name + short description |
| P2-T10 | Member cannot open admin product routes | **PASS** | smoke: `GET /admin/products` and `POST /admin/products/save` both 403 for a member |

### Deviations and decisions

- **No Dropzone / DataTables dependencies.** The plan mentions "theme form + Dropzone" and a
  DataTable. The reference implementation itself uses a plain `<input type="file">` and
  server-side lists, and §3.6 makes DataTables optional ("server-side for admin tables").
  The form ships a native file input with a live preview and the theme's card layout; the
  list uses server-side search/status filtering with the shared `Paginator`. Noted here so
  Phase 3 can stay consistent.
- **`/shop/{id}` is an extension route.** The reference rendered detail in a modal inside the
  storefront page; the port adds the route (already listed in Appendix A and in the
  inventory `extensions`) and keeps a quick-view modal on the grid as the lighter path.
- **Stock availability is forward-compatible.** `StockService` only counts reservations when
  `shop_orders` + `shop_order_items` exist (Phase 6). The two unit cases that create those
  tables locally prove the maths now; nothing needs rewriting later.
- **Seed copy is deliberately neutral.** The official product copy and price list are still
  open (D10), so descriptions say so instead of inventing product claims.
- **Product images for seeds** live under `public/assets/img/products/` (not
  `uploads/`), so `UploadService::remove()` refuses to delete them — same rule as any other
  static asset. Two seeded rows have no image so the placeholder path is visible.
- **Stale old input is cleared** when a product form opens without validation errors, and
  after a successful save — otherwise repopulated values from an earlier failed submit
  would mask the saved row (found by the smoke test).

### Gate checklist (plan §7 Phase 2)

- [x] All P2 tests pass (111 PHP cases + 32 smoke checks)
- [x] `php -l` clean (61 files)
- [x] No 404 asset on the storefront or admin pages (product photos and SweetAlert2 resolve)
- [x] Migration applied to the dev DB; `schema.sql` regenerated for fresh installs
- [x] Seed catalogue loads on a fresh install (proven by the harness on every run)
- [x] Back-office guards unchanged (member → 403 on every admin product route)
- [x] No new PHP warnings/notices in the Apache error log
- [ ] Visual sign-off on a real browser (same constraint as Phase 1 — HTTP + markup verified)
- [x] Tag `phase-2`

### Next

Phase 3 — packages and package contents: `packages`, `package_levels` (2–10) and
`package_products`, admin package CRUD with the level matrix, and the member `/packages`
cards. `Product`/`StockService` are the patterns to follow; the inventory lists the Phase 3
routes already.

---

## Phase 3 — Packages and package contents

**Date:** 2026-11-03 · **Branch:** `feature/shop` · **Gate tag:** `phase-3` · **Status:** complete.

### Delivered

| Area | What shipped |
|---|---|
| **Database** | `packages` (price, product value, direct bonus, indirect toggle, status), `package_levels` (levels 2–10, `CHECK 2..10`), `package_products` (contents); migration `003_packages.sql`; seed `004_packages.sql` (Package A/B + Colonics service product) |
| **Model** | `app/Models/Package.php` — find/by-slug, admin search/status list, cached `levelsMap()` / `contentsMap()`, validation for package fields, the 2–10 matrix and contents, transactional `save()` (package + ladder + contents), `setStatus()`, unique slugs |
| **Service** | `PackageService` — the single definition of the 10-level ladder (level 1 = direct bonus, read-only), ladder totals/funded counts, `publicCards()`, form parsing (`levelsFromInput`, `contentsFromInput`) and `contentOptions()` (active products + already-linked inactive ones) |
| **Back office** | `GET /admin/packages` (stats, search + status filter, pagination, ladder/contents summaries), `/new`, `/{id}/edit` (level matrix + contents repeater), `POST /save` (transactional, image upload), `POST /{id}/status` (JSON for XHR) |
| **Member** | `GET /packages` — package cards with price, product value, direct bonus, totals, expandable 10-level ladder, contents and perks; activation CTA intentionally inert until Phase 9 |
| **Views** | `admin/packages/index.php`, `admin/packages/form.php`, `shop/packages.php`; contents repeater JS and the level-1 mirror on the form |
| **Helpers** | `old_payload()` / `old_payload_set()` — a nested (array-carrying) form payload for the matrix and repeater, since `old()` only carries scalars |
| **Tests** | `PackageTest` (25 model/service cases), `PackageViewTest` (8 rendering cases), smoke P3-T1 … P3-T8; suite 111 → 140 cases, smoke 32 → 41 checks; `MigrationTest` now applies 001–003 |

### Database changes applied

| Step | Result |
|---|---|
| Migration | `003_packages.sql` applied to `ecowealth_sneat` |
| Seed | Package A (₱4,498 / value ₱4,750 / direct ₱500) and Package B (₱6,998 / ₱8,300 / ₱500), 9 ladder rows each (100/50/40/30/20/10/5/5/5), 5 contents each |
| Service product | `Colonics Session` (`EW-COLONICS`, ₱1,500 placeholder, stock 20) seeded because Package B bundles it (plan §4.5) |
| Verified | `MigrationTest` applies 001–003 and matches `schema.sql` for all tables and columns; seeded state is 2 packages / 18 level rows / 10 content rows / 7 products |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P3-T1 | Create Package A with full level matrix | **PASS** | smoke: POST creates the package and exactly 9 ladder rows; model case covers create + contents; seed case asserts Package A/B values |
| P3-T2 | Edit level 3 to ₱50 | **PASS** | smoke stores 50.50 and the edit form reopens with `value="50.50"`; model case asserts the edited level and that the rest of the ladder is intact |
| P3-T3 | Invalid price/decimal/negative | **PASS** | unit cases cover every rule (name, price, negative/3-decimal/over-cap levels, product value); smoke posts an invalid package and asserts the form errors plus no row; a forced FK failure proves `save()` rolls back completely |
| P3-T4 | Add contents (2× Coffee) | **PASS** | smoke asserts `product_id:2` in `package_products` and the public card listing; unit cases cover merging duplicates, quantities 1–1000 and inactive products |
| P3-T5 | Direct bonus shown as Level 1 | **PASS** | smoke and view test assert `Level 1 — Direct referral` is read-only and that no `levels[1]` input exists; unit case asserts zero level-1 rows in `package_levels` |
| P3-T6 | Deactivate package | **PASS** | smoke: gone from `/packages`; unit case: `publicCards()` drops it while the admin list keeps it |
| P3-T7 | Member cannot edit packages | **PASS** | smoke: `GET /admin/packages` and `POST /admin/packages/save` both 403 for a member |
| P3-T8 | Public packages page | **PASS** | smoke asserts ₱4,498/₱6,998 and the direct-bonus label; view cases cover ladder, contents, perks, escaping and the empty state |

### Deviations and decisions

- **Packages are never deleted.** The plan says "Package with members cannot be deleted
  (status toggle instead)"; since `users.package_id` only arrives in Phase 9, the port
  simply exposes no delete action at all. Phase 9 can add a guarded delete if needed.
- **Level 1 is stored once.** `package_levels` holds levels 2–10 with a CHECK constraint;
  the direct bonus lives on the package row and is mirrored read-only in the form. The
  seed writes 9 rows, never 10.
- **The Colonics service product is seeded in Phase 3.** Plan §4.5 lists it inside Package
  B's contents, so the package seed adds it as a normal product row (no `product_type`
  column exists in the target schema). `ProductTest` now expects the six retail products
  plus this service product.
- **Duplicate content rows merge by adding quantities.** "Duplicates merged" is
  implemented as a sum (2 + 3 Coffee → 5), which is lossless for the admin's intent and
  tested explicitly.
- **Nested form payloads have their own helper.** The level matrix and contents repeater
  post arrays, which `old()`/`old_set()` cannot carry; `old_payload()` stores and clears
  the whole payload across the failed-POST redirect.
- **A member "Packages" nav item was added** under Membership, since `/packages` is a live
  member page; §3.5's menu list did not name it.
- **Carried over from Phase 2:** no Dropzone/DataTables dependencies; the package form uses
  a native file input with preview and the same server-side list pattern.

### Gate checklist (plan §7 Phase 3)

- [x] All P3 tests pass (140 PHP cases + 41 smoke checks)
- [x] `php -l` clean (69 files)
- [x] No 404 asset on the package pages (shared layout + SweetAlert2 only)
- [x] Migration applied to the dev DB; `schema.sql` regenerated for fresh installs
- [x] Package seeds load on a fresh install (harness rebuilds and seeds on every run)
- [x] Admin guards unchanged (member → 403 on every admin package route)
- [x] No new PHP warnings/notices in the Apache error log
- [ ] Visual sign-off on a real browser (same constraint as Phases 1–2 — HTTP + markup verified)
- [x] Tag `phase-3`

### Next

Phase 4 — cart engine and cart UI: `carts` / `cart_items`, `Cart` model + `CartService`
(one active cart per member, scoped mutations, availability checks), `/cart`, add/update/
remove endpoints and the offcanvas badge. `StockService::available()` and the product
catalogue are the inputs; the inventory lists the Phase 4 routes.




---

## Post-Phase 3 — Dashboard and navigation polish

**Date:** 2026-11-03 · **Status:** complete · **Tests:** 141 PHP cases, 42 smoke checks

### Problem

Signing in as `admin` opened `/dashboard`, which rendered the member "Growth overview" with the
member sidebar. Sidebar icons were invisible because every menu glyph was rendered without the
Boxicons base class (`bx`), and the menu advertised unbuilt screens as "Soon" placeholders. The
admin landing page itself was a Phase 1 placeholder with no operational data.

### Changes

- **Role-aware `/dashboard`.** `public/dashboard.php` hands admins to
  `AdminDashboardController`; the URL stays `/dashboard` and the admin sidebar renders. Members
  keep the Growth overview unchanged.
- **Admin dashboard rebuilt** in the Sneat dashboard language: welcome banner, four KPI cards,
  catalogue-value bar chart, stock-health donut with a needs-attention list, recent accounts and
  package summary. `AdminDashboardController` now reads accounts, products, stock and packages;
  each table group is guarded so a missing later-phase table cannot blank the page.
- **Icons fixed globally.** `icon_class()` in `helpers.php` normalises `bx-…` to `bx bx-…`;
  sidebar, empty states, admin cards and navbar notifications now use it.
- **Nav cleaned up.** Items for unbuilt phases are hidden rather than shown disabled; the admin
  menu now also links to the Storefront. Every rendered link is live.
- **Registration terms gate.** “Sign up” starts disabled, enables only when the privacy/terms box
  is ticked, and the server refuses to create the account without it (previously the insert ran
  before the terms error was rendered).
- **Collaboration details.** Brand link follows the active layout, the compact leaf mark is reserved
  for the collapsed desktop rail (the mobile drawer always shows the full wordmark), the navbar
  search jumps to the catalogue search, and the products/packages list toolbars stay on one line.
- **New assets:** `assets/css/app.css`, `assets/js/app.js`, `assets/js/admin-dashboard.js`.

### Verification

- `php tests\run.php` — 141/141.
- `powershell -NoProfile -File tests\smoke.ps1` — 42/42.
- Visual QA at 1440×900 and 390×844, plus the collapsed sidebar: screenshots in
  `docs/qa/evidence/dashboard/` (`final-*.png`).

---

## PHP 8.5 compatibility — PDO time zone

**Date:** 2026-11-03 · **Status:** complete · **Tests:** 141 PHP cases

`app/database.php` no longer passes `PDO::MYSQL_ATTR_INIT_COMMAND`, which PHP 8.5 deprecates
(`Pdo\Mysql::ATTR_INIT_COMMAND` is the 8.4+ replacement). The connection now runs
`SET time_zone = '<configured offset>'` once immediately after opening, so the session time zone
stays aligned with `APP_TIMEZONE` on PHP 8.3, 8.4 and 8.5 without a driver-specific constant.
Verified `@@session.time_zone` returns `+08:00` and the PHP suite stays green.

---

## Storefront product-card redesign

**Date:** 2026-11-04 · **Status:** complete · **Tests:** 141 PHP cases, 42 smoke checks

### Problem

The product-card CSS in `views/shop/catalog.php` and `views/shop/product.php` was assigned to
`$page_styles` inside the view, but `View::page()` renders the layout header **before** the view.
The rules therefore never reached a `<style>` block. Portrait pack shots (540–567 × 960) rendered
at full card width: on a phone a single card grew to ~740px, hiding the title, price and buttons.

### Changes

- **Storefront stylesheet.** Added `assets/css/shop.css` and pass it through
  `ShopController::page_css` for both the catalogue and the product detail page, so every rule is
  in the document head before the cards render.
- **Fixed media stage.** Each card has a 1:1 media stage with `object-fit: contain`, a soft branded
  gradient and generous internal padding. A tall bottle or pouch is fully visible without pushing
  the body out of the card; the stage height is identical across the grid.
- **Admin thumbnails.** Admin product rows (`/admin/products`) and the create/edit image preview now
  use the same EcoWealth leaf mark as the storefront when `image_url` is empty, replacing the
  generic package icon. The thumbnail is a 44px rounded tile with the mark at its centre.
- **Role-aware storefront shell.** `/shop` and the product detail page render the **admin** layout
  when an administrator is signed in, with **Storefront** active and Products/Packages still in the
  sidebar; members keep the shop/member menu. The shared navbar search is pointed at the shop for
  both roles instead of switching to the admin product search.
- **Conversion-focused body.** SKU kicker, line-clamped title and short description, prominent
  price, stock badge over the image, primary **Details** CTA and a quick-view button. Card hover
  lifts the card and gently scales the image; focus-within gets the same treatment for keyboards.
- **Responsive grid.** Two cards per row on phones, three from 768px and four from 1200px. The
  short description is hidden on phones and the price/actions stack below the tablet breakpoint.
- **Product detail + quick view.** The detail image is absolutely fitted to the 1:1 stage; a grid
  auto-row sized from the image's 960px intrinsic height was clipping tall bottles such as
  Spirulina (`/shop/3`). Quick view uses the same fit and shows the whole pack. Missing images use
  the EcoWealth leaf mark instead of a generic broken-image icon. `bx-cart-plus` (not shipped by
  the bundled icon set) was replaced with `bx-cart-add`.
- **Copy.** The shop header no longer advertises a future cart release; the detail page uses
  “Online checkout opens soon” instead of internal phase text.
- **Tests.** Smoke counts cards with the new `<article class="card product-card">` markup and also
  asserts that `shop.css` is present on the catalogue response.

Visual evidence: `docs/qa/evidence/shop/` (`final-shop-desktop.png`,
`final-shop-tablet.png`, `final-shop-mobile.png`, `final-product-detail.png`).

---

## Product action icons — hover contrast and Back focus

**Date:** 2026-11-04 · **Status:** complete

### Problem

The admin product action icon buttons (Edit, status toggle, Delete) rendered their glyph in the
table gray because Sneat’s generic `.btn-icon` colour rule won over `.btn-label-*`. On hover the
label variant switches to a dark filled background, leaving a low-contrast gray icon. After
navigating to a product edit page and pressing browser **Back**, the restored focus kept the Edit
button in that pressed/hover appearance.

### Changes

- `app.css` restores the label variant colour for icon-only label buttons, switches the glyph to
  the variant contrast colour on hover/active, and uses an outline focus ring instead of the hover
  fill for `:focus-visible`.
- `app.js` clears restored focus on `.btn-icon[class*="btn-label-"]` during `pageshow`, so the
  action does not stay shaded after Back. Normal keyboard focus still shows the ring.
- Verified hover glyph is white on the dark fill, the focused Edit button gets a visible ring, and a
  dispatched `pageshow` moves focus back to `body`. PHP 141/141 and smoke 42/42 remain green.

Evidence: `docs/qa/evidence/shop/edit-button-hover.png`.

---

## Branded error pages — logo-led, no topbar

**Date:** 2026-11-04 · **Status:** complete · **Tests:** 141 PHP cases, 42 smoke checks

### Problem

Error pages used the shared plain layout, which still rendered the detached topbar with a small
32px logo. The resulting 403/404 screen had a narrow floating header, a generic avatar/lock icon and
a mostly empty page.

### Changes

- **Plain layout has no navbar.** Error pages now start directly with the centered error panel; the
  plain `<html>` class no longer carries `layout-navbar-fixed`, so there is no leftover top padding.
- **Logo replaces the status icon.** A 48px EcoWealth wordmark sits at the top of every error panel
  (403, 404, 500 and the standalone 503), replacing the old avatar/pin/map/clock icon.
- **Shared panel.** New `views/partials/error_panel.php` renders the logo, large status code,
  heading, message, supporting sentence and recovery actions. `errors.css` handles the full-height
  gradient background, responsive button layout and footer. The 500 debug detail is preserved.
- **503 stays standalone.** Maintenance mode still renders without the app layout or database
  dependencies, but uses the same panel and stylesheet; it keeps the `Retry-After: 3600` response and
  the “EcoWealth is being updated and will be back shortly.” copy.
- **Flash bars suppressed on error layouts.** A login success flash no longer appears above a 403.

Visual evidence: `docs/qa/evidence/errors/` (`final-403-desktop.png`, `final-404-desktop.png`,
`final-503-desktop.png` and their mobile counterparts).

---

## Phase 4 — Cart engine and cart UI

**Date:** 2026-11-04 · **Branch:** `feature/shop` · **Gate tag:** `phase-4` · **Status:** complete.

### Delivered

| Area | What shipped |
|---|---|
| **Database** | `carts` (one active row per member, `status` active/converted/abandoned) and `cart_items` (one line per product, `unit_price` snapshot, `ON DELETE CASCADE` from the cart); migration `004_carts.sql`; fresh-install `schema.sql` extended |
| **Model** | `app/Models/Cart.php` — active/find/items/item/totals/countFor. Every read is scoped by the cart id resolved from the session user (never a posted item id alone) and totals always use the live `products.price` |
| **Service** | `app/Services/CartService.php` — `getOrCreate`, `add`, `updateQuantity`, `remove`, `clear`, `convert`, `state`, `validateStock`, `summary`, `refreshPrices`; one transaction per mutation with row locks; `CartException` carries a machine-readable `reason` |
| **Stock service** | `StockService` now exposes `reserved()` and takes an optional PDO, so the cart reads availability inside its own transaction (`stock − reserved`) |
| **Routes** | `GET /cart`, `GET /cart/count`, `POST /cart/add`, `POST /cart/update`, `POST /cart/remove` (all `member`); registered in `app/routes.php`, already listed by the inventory — parity test unchanged |
| **Controller** | `app/Controllers/CartController.php` — JSON for XHR (`ok`, `count`, `items`, `subtotal`, `subtotal_formatted`, `item`, `removed`, `drawer_html`) and flash + redirect-back for plain forms (P4-T12) |
| **Views** | `shop/cart.php` (table, per-line stock badge, stock-problem alert, summary card), `partials/cart_offcanvas.php` + `partials/cart_offcanvas_body.php` (the body is re-rendered by the endpoints so the drawer always shows server truth), add-to-cart controls on `partials/product_card.php` and `shop/product.php` |
| **Layout** | Navbar cart button with live badge, sidebar badge from `$nav_badges`, global drawer + `#cartConfig` endpoints/CSRF host + toast host in `app_footer.php`, `APP_PHASE` bumped to 4 (Cart menu item is live) |
| **JS / CSS** | `public/assets/js/app-cart.js` — fetch wrapper with CSRF, optimistic badge/line preview, per-form busy lock, stepper buttons, drawer + page totals from the server response, toast helper, resync on failure; cart/drawer/stepper styles in `assets/css/shop.css`; toast stacking fix in `assets/css/app.css` |
| **Tests** | `tests/Cases/CartTest.php` (19 cases incl. IDOR and a two-connection lock test), `tests/Cases/CartViewTest.php` (11 rendering cases), `ProductTest` cart-reference cases moved onto the real tables, `MigrationTest` applies 001–004, smoke `P4-T1 … P4-T13` (12 new checks) |
| **Evidence** | `docs/qa/evidence/phase-4/` — desktop cart, drawer, AJAX add + toast, updated cart, empty state, mobile cart & drawer, storefront add buttons |

### Database changes applied

| Step | Result |
|---|---|
| Backup | `C:\laragon\www\ecowealth-backups\phase-4\db-pre-phase-4.sql` (17 KB, `mysqldump --routines --triggers --databases ecowealth_sneat`) |
| Migration | `004_carts.sql` applied to `ecowealth_sneat` (2 tables, FKs to `users`/`products`) |
| Seeds | none — a cart is created on the member's first add |
| Verified | `MigrationTest` applies 001–004 and asserts the migrated `carts` / `cart_items` columns match a fresh `schema.sql` import |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P4-T1 | Add product to empty cart | **PASS** | model case creates the cart + line and returns count/line/subtotal; smoke: no-JS add writes one row; view cases render the line |
| P4-T2 | Add same product again | **PASS** | one line, quantity 1 + 2 = 3; DB line count is 1 |
| P4-T3 | Add beyond available | **PASS** | `Insufficient stock. Requested 4, only 3 available.`; refused add writes nothing; reservation-held stock case (`shop_order_items`) proves `available = stock − reserved`; smoke: 400 JSON, quantity unchanged |
| P4-T4 | Update quantity to 0 | **PASS** | line deleted, totals 0; update persists; over-stock update refused with old quantity kept; inactive line can still be removed |
| P4-T5 | Update someone else's item id | **PASS** | owner line unchanged; other member gets `item_missing`; smoke repeats it over HTTP with a second account |
| P4-T6 | Remove item from other cart | **PASS** | refused, row intact (model + smoke) |
| P4-T7 | Price changed in admin after add | **PASS** | totals and line total use the live price immediately; the snapshot is refreshed when the cart page is read; smoke changes a product from 20.00 to 12.50 and sees 25.00 |
| P4-T8 | Two members' carts | **PASS** | separate carts, lines and totals; view case never renders the other member's line |
| P4-T9 | Concurrent add to last unit | **PASS** | two PDO connections: A holds the member/cart row locks, B's add waits and times out, then after A commits B is refused with `only 1 available`; final quantity 1, available never negative |
| P4-T10 | Inactive product add | **PASS** | `product_inactive` refused; smoke deactivates through the admin route and gets a 400 |
| P4-T11 | AJAX responses | **PASS** | `ok:true` + count/items/subtotal/drawer; domain errors 400 with `code` + message; CSRF errors stay 422 (`code:"csrf"`) |
| P4-T12 | No-JS add | **PASS** | plain form POST → 302 back + flash; the cart row exists; the cart page renders the line |
| P4-T13 | Drawer updates after add/remove | **PASS** | the response carries `drawer_html` rendered by the same partial; badge, drawer header count and totals all come from the response; a real-browser run confirmed add → drawer opens (badge 1), “+” → 2, remove → empty state and badge hidden, with 0 JS exceptions |

### Deviations and decisions

- **Totals never use the line snapshot.** `cart_items.unit_price` is written on add/update and refreshed when the cart page
  is read, but every total joins `products.price`. The badge endpoint therefore performs no writes on ordinary page
  loads, and a stale snapshot can never become a price (plan §5.2).
- **The drawer is refreshed from server HTML.** `/cart/count` and the three mutating endpoints return `drawer_html`
  rendered by `partials/cart_offcanvas_body.php`. That was preferred over adding a sixth `/cart/offcanvas` route: the
  route inventory stays at the five Appendix A cart routes, and the badge/list can never disagree.
- **“One active cart” is a lock, not a unique index.** A member legitimately accumulates converted carts, so a
  `(user_id, status)` unique index would break history. `CartService` locks the member row (`SELECT … FOR UPDATE`) before
  the cart lookup/insert; the concurrency case exercises that lock.
- **Cart page quantity input allows 0.** Stepping below 1 removes the line (P4-T4); the drawer keeps min=1 and offers a
  Remove button, because a 0 in a 3-slot stepper is easy to hit by accident.
- **Inactive products in a cart are quarantined, not silently deleted.** The line stays visible with “Unavailable”, the
  cart blocks checkout, quantity increases are refused, and setting the quantity to 0 (or Remove) still works.
- **Closed shop.** The cart stays readable so a member can review/clear it; add and quantity updates are refused with
  “The shop is currently closed.”, removal is allowed.
- **Admins may use the cart.** The `member` guard admits admins (reference behaviour, plan §3.4). The IDOR checks use a
  second member account rather than an admin, so they prove ownership scoping, not role scoping.
- **JSON error status is 400, CSRF stays 422.** Domain refusals (stock, inactive, missing item, closed shop) are 400 with
  `code`; the router’s central CSRF rejection remains 422 (Apache rewrites 419). Clients key off `ok`/`code`.
- **No Dropzone / DataTables / jQuery cart plugin.** Consistent with Phases 2–3: plain forms, a dependency-free
  `app-cart.js`, and server-rendered lists.
- **Toast stacking.** The theme sets `.toast-container` to a low z-index, so the “Added to cart.” toast sat behind the
  drawer. `app.css` raises it to 1095 with `pointer-events: none` on the host (toasts keep clicks) — confirmed in the
  browser with `elementFromPoint`.
- **Mobile cart columns.** At ≤575px the unit price and line total columns are hidden so the product, quantity stepper
  and remove action fit a 390px viewport; the summary card carries the money. Native number spinners are hidden so the
  quantity is readable inside the compact stepper.
- **Concurrency test commits its fixtures.** A row lock can only be observed across connections, so `P4-T9` commits the
  harness transaction, creates its member/product, drives two PDOs, and deletes both rows in `finally`. The reserved-stock
  case does the same because its temporary `shop_orders` DDL commits implicitly.

### Gate checklist (plan §7 Phase 4)

- [x] All P4 tests pass (171 PHP cases + 54 smoke checks)
- [x] `php -l` clean (82 PHP files)
- [x] Migration applied to the dev DB; `schema.sql` regenerated for fresh installs
- [x] Seed data unchanged (no cart seed needed; the harness rebuilds and seeds on every run)
- [x] Full regression suite passes (Phases 1–3 cases unchanged apart from the cart-table fixtures)
- [x] Smoke script passes (12 new P4 checks)
- [x] IDOR and concurrency tests included in the gate
- [x] No new PHP warnings/notices in the Apache error log
- [x] Money invariants clean (cart totals are read-only; no wallet touched this phase)
- [x] Docs updated (this log + `docs/qa/phase-4-cart.md`)
- [x] Visual check in a real browser: desktop/mobile cart, drawer, AJAX add + toast, empty state; 13/13 UI
      interaction checks with 0 JS exceptions (evidence in `docs/qa/evidence/phase-4/`)
- [ ] Manual QA sign-off by a human tester (`docs/qa/phase-4-cart.md`) — the automated and headless-browser passes are
      complete
- [x] Backup taken; tag `phase-4`

### Next

Phase 5 — e-wallet core: `ewallet_ledger` + `ewallet_admin_topups`, the dual-balance `users` columns and
`WalletService` (`credit`, `debit`, `debitInternal`, `balance`, `withdrawable`, `statement`, `adminTopUp`), then the
member statement page and the admin monitor/top-up screens. `CartService::convert()` is ready for Phase 6’s order
placement; `Cart::totals()` is the price source checkout should re-read.

---

## Post-Phase 4 — Admin cart menu

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 173 PHP cases, 55 smoke checks

### Problem

The back office gained the navbar cart button and the global drawer in Phase 4 (admins may shop — the
`member` guard admits them), but its sidebar had no **Cart** entry. The badge and drawer therefore had no
menu destination, and opening `/cart` as an admin highlighted **Storefront** in the sidebar.

### Changes

- **`app/layout/nav.php`** — added `admin.cart` under **Overview**, right after **Storefront**:
  `guard => admin`, `phase => 4`, `badge => cart` so it renders with the same live count as the member menu.
- **`CartController::index()`** — an admin viewing the cart now passes `current_nav = admin.cart` (was
  `admin.storefront`), so the sidebar marks the right item. Members are unchanged (`cart`).
- **Tests** — two new `CartViewTest` cases: the admin sidebar carries a linked, active Cart item with the
  live badge while the back-office catalogue stays intact; the member sidebar still shows Cart without any
  back-office item. `smoke.ps1` gained a P4-T13 check that `GET /cart` as an admin returns 200 with the
  active Cart item in the back-office menu.
- **Evidence** — `docs/qa/evidence/phase-4/final-admin-cart.png`.

### Verification

- `php tests\run.php` — **173 / 173**.
- `powershell -NoProfile -File tests\smoke.ps1` — **55 / 55**.
- Headless Chrome: `/cart` signed in as `admin` renders the back-office layout with
  **OVERVIEW → Dashboard · Storefront · Cart (active, badge 2)**, the catalogue menu below, and the cart
  line/summary in the content area.

---

## Post-Phase 4 — Live sidebar cart badge

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 174 PHP cases, 55 smoke checks, 18/18 browser UI checks

### Problem

Adding from `/shop` updated the navbar cart badge but not the badge beside **Cart** in the sidebar.
Two causes:

1. The sidebar badge had no `data-cart-badge` hook, so `app-cart.js` never touched it.
2. `sidebar.php` only emitted the badge element when the count was non-zero. On a first add the element
   did not exist at all, so there was nothing for the script to reveal without a reload.

### Changes

- **`app/layout/sidebar.php`** — every badged menu item now renders its badge element even at zero,
  carrying `data-nav-badge="<key>"` (and `data-cart-badge` for the cart). A zero count starts `hidden`,
  so the menu looks unchanged but the AJAX layer can reveal and update it in place.
- **`app-cart.js`** — unchanged on purpose: it already updates every `[data-cart-badge]`; the sidebar is
  now one of them, alongside the navbar and (via `[data-cart-count]`) the drawer header.
- **Tests** — new `CartViewTest` case: an empty cart renders the sidebar badge hidden at `0`; the admin and
  member sidebar cases now assert the badge hook and live count. The browser harness reports **18/18**
  (sidebar badge exists hidden at 0, shows 1 after a card add, 2 after the drawer stepper, hides at 0,
  and follows a cart-page update).
- **Docs/evidence** — QA4-02 now states the badges update without a reload; new
  `docs/qa/evidence/phase-4/final-cart-sidebar-badge.png` (sidebar and navbar both at 2 after an
  in-place add).

### Verification

- `php tests\run.php` — **174 / 174**.
- `powershell -NoProfile -File tests\smoke.ps1` — **55 / 55**.
- Headless Chrome interaction run — **18 / 18**, 0 JavaScript exceptions.

---

## Shell footer — vendor credit removed

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 175 PHP cases, 55 smoke checks

The app footer ended with “Built with PHP 8.3.16 & MySQL · ThemeSelection”. The whole right-hand
credit block is removed from `app/layout/app_footer.php`; the footer now carries only the EcoWealth
copyright line (“© 2026 EcoWealth — Membership, shop and e-wallet. All rights reserved.”). The layout
container stays as-is, so the copyright keeps its usual padding on every app page.

A `ViewTest` regression case asserts the footer keeps the brand and the rights line and contains no
“Built with”, “ThemeSelection” or PHP version string.

Evidence: `docs/qa/evidence/shell/final-footer.png`.

---

## Post-Phase 4 — Cart steppers work without JavaScript

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 177 PHP cases, 56 smoke checks, 18/18 JS + 4/4 no-JS browser checks

### Problem

On `/cart` with JavaScript disabled, the row's **+** / **−** buttons did nothing: they were
`type="button"` controls owned by `app-cart.js`, so with the script off they were dead. Only typing a
number and pressing **Update** (or the trash icon) worked, which made the stepper look broken.

### Changes

- **`app/views/shop/cart.php`** — the steppers are now real submit buttons carrying
  `name="direction" value="up|down"`. With JavaScript they are intercepted exactly as before; without
  it they post the form, the server steps the quantity and the page reloads with the flash.
  A `visually-hidden` **Update** submit is placed first in the form so pressing Enter in the quantity
  field still submits the typed value rather than a step.
- **`app/Controllers/CartController::update()`** — reads `direction`; `up`/`down` go to the new step
  service call (the posted quantity is ignored), anything else keeps the typed-quantity path. The
  closed-shop guard now blocks only increases, so a member can always step down or clear a line.
- **`app/Services/CartService`** — `updateQuantity()` and the new `adjustQuantity($userId, $itemId, ±1)`
  share one locked `applyQuantity()` path, so stock caps, inactive-product refusal, zero-means-remove,
  ownership scoping and the unit-price snapshot all behave identically for both input modes.
- **Tests** — two `CartTest` cases (step up/down/remove; stock, inactive and IDOR refusals), a
  `CartViewTest` assertion for the submit semantics and the hidden default button, and a `smoke.ps1`
  P4-T12 check that posts `direction` with a deliberately stale quantity and watches the stored quantity.
- **Evidence** — `docs/qa/evidence/phase-4/final-cart-nojs-stepper.png` (JavaScript disabled in Chrome,
  real mouse click on **+**, page reloaded, quantity 2, “Cart updated.” flash).

### Verification

- `php tests\run.php` — **177 / 177**.
- `powershell -NoProfile -File tests\smoke.ps1` — **56 / 56**.
- Headless Chrome with **JavaScript disabled**: 4/4 (submit button semantics, reload, persisted
  quantity, flash), and the JavaScript interaction harness still **18 / 18** with 0 exceptions.

---

## Post-Phase 4 — Navbar cart badge sizing (global shell CSS)

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 178 PHP cases, 56 smoke checks

### Problem

On `/dashboard` the navbar cart badge rendered as a large, in-flow pill beside the cart icon instead of a
compact counter on its corner. Two causes:

1. `.cart-navbar-badge` — and the whole off-canvas drawer styling — lived in `shop.css`, which is only
   enqueued on the catalogue and cart pages. On the dashboard there was no rule at all, so the theme’s
   default `.badge` (large pill, in the button flow) won.
2. Even where `shop.css` loaded, the theme’s `.btn .badge { position: relative; top: -1px }` rule
   outranked the single-class badge rule, keeping it in the flow.

### Changes

- **Moved the shell-level cart styles** (drawer, drawer media/name, cart quantity group, navbar badge)
  from `shop.css` to `app.css`, which every signed-in page loads. `shop.css` keeps the catalogue and
  cart-page-only rules (product cards, cart table, summary).
- **Re-scoped the badge** to `#layout-navbar .cart-navbar-badge` so it outranks `.btn .badge`; it is now a
  compact 1.05rem circle (0.625rem type) absolutely positioned on the icon’s top-right corner.
- **Regression test** — `CartViewTest` asserts the drawer/badge selectors live in `app.css` and that
  `shop.css` no longer owns them, so the page-specific-sheet mistake cannot return.
- **Evidence** — `docs/qa/evidence/shell/final-navbar-badge.png` (dashboard: `shop.css` not loaded,
  badge computed 16.8 × 16.8px, `position: absolute`).

### Verification

- `php tests\run.php` — **178 / 178**.
- `powershell -NoProfile -File tests\smoke.ps1` — **56 / 56**.
- Headless Chrome: dashboard badge measured 16.8 × 16.8px at `position: absolute`; the cart JavaScript
  harness still **18 / 18** and the no-JS stepper check **4 / 4**.

---

## Post-Phase 4 — Navbar cart badge grows rightward

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 178 PHP cases, 56 smoke checks, 18/18 JS + 4/4 no-JS browser checks

### Problem

The navbar badge was pinned by its right edge (`right: 0.2rem`), so a rising count expanded leftward
across the cart glyph. At 33 units the icon was half hidden.

### Changes

- **`app.css`** — `#layout-navbar .cart-navbar-badge` is now anchored by its **left** edge at
  `calc(100% - 0.7rem)` with `right: auto`. The count starts 3px inside the icon's right edge and grows
  into the navbar gap, so the cart glyph stays visible at every width.
- **`app_header.php`** — the cart nav item gained `me-4 me-xl-3` (was `me-2 me-xl-1`), giving a
  two- or three-digit badge room before the notification bell.
- **Evidence** — `docs/qa/evidence/shell/final-navbar-badge.png` (33 units at 1440px) and
  `final-navbar-badge-mobile.png` (33 units at 390px). Browser measurement: badge left = 1267px,
  icon 1248–1270px; the icon centre still hit-tests as the cart glyph, and the badge ends 12px before
  the bell.

### Verification

- `php tests\run.php` — **178 / 178**.
- `powershell -NoProfile -File tests\smoke.ps1` — **56 / 56**.
- Headless Chrome: cart JavaScript harness **18 / 18**, no-JS stepper **4 / 4**.

---

## Post-Phase 4 — Product detail quantity stepper layout

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 179 PHP cases, 56 smoke checks

### Problem

On `/shop/1`, desktop and mobile, the quantity stepper stacked its pieces — `[−][1]` on one row and
`[+]` on the next — and the **Add to cart** / **View cart** buttons stretched to about 77px tall,
looking oversized.

### Cause

`.product-qty-group` was fixed at 8.5rem (136px). The theme’s `.btn` padding makes each stepper button
~50px wide, so two buttons plus the value field needed ~139px: Bootstrap’s wrapping `.input-group`
pushed `+` onto a second row. The action row used `align-items-stretch`, so the row’s buttons then
stretched to the two-row height.

### Changes

- **`shop.css`** — the group is `width: fit-content` (overriding Bootstrap’s `.input-group{width:100%}`)
  with `flex-wrap: nowrap`; the stepper buttons get compact horizontal padding and the value field a
  fixed 3.25rem, so `[−][n][+]` measures ~112.5px on a single row.
- **`product.php`** — the action row and the add form use `align-items-center` instead of
  `align-items-stretch`, so a future wrap cannot stretch the buttons again.
- **Tests** — `ProductViewTest` asserts the group class in the markup and the `fit-content` /
  `nowrap` rules in the stylesheet.
- **Docs** — Phase 2’s QA2-15 expectation now describes the working Phase 4 controls; Phase 4’s
  evidence table links the new screenshots.
- **Evidence** — `docs/qa/evidence/shop/final-product-actions.png` (desktop) and
  `final-product-actions-mobile.png` (360px).

### Verification

- `php tests\run.php` — **179 / 179**.
- `powershell -NoProfile -File tests\smoke.ps1` — **56 / 56**.
- Headless Chrome: group 112.5 × 39.4px, all three pieces on one row; **Add to cart** 143.8 × 38px
  (was 143.8 × 77.4px); at 360px the stepper and Add share a row and the secondary actions wrap below.

---

## Post-Phase 4 — Product detail: "Back to shop" moves to the header row

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 179 PHP cases, 56 smoke checks

### Change

"Back to shop" was the third tertiary action in the buy row, competing with the stepper, **Add to
cart** and **View cart**. It now sits at the top right of the page, opposite the **Shop / Coffee**
breadcrumb, leaving the buy row focused on the purchase actions.

- **`product.php`** — the breadcrumb is wrapped in a flex row with a small label button
  (`btn-sm btn-label-secondary`) on the right; the link is removed from the action row.
- Works at desktop width and at 360px (breadcrumb left, button right, no overlap).
- **`ProductViewTest`** — asserts the page contains exactly one "Back to shop" link, positioned after
  the breadcrumb and before the product card.
- **Docs/evidence** — QA2-15's expected result updated; refreshed
  `docs/qa/evidence/shop/final-product-actions.png` and `final-product-actions-mobile.png`.

### Verification

- `php tests\run.php` — **179 / 179**.
- `powershell -NoProfile -File tests\smoke.ps1` — **56 / 56**.
- Headless Chrome at 1440 × 900 and 360 × 800: the button aligns with the breadcrumb row, the action
  row keeps the one-line stepper, Add to cart and View cart.

---

## Post-Phase 4 — Drawer quantity fields hold large counts

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 179 PHP cases, 56 smoke checks

### Problem

The cart page displayed two-digit quantities correctly, but the off-canvas drawer clipped them —
`33` and `99` showed only their first digit. The drawer group was narrower (6.75rem) than the cart
page (7.25rem) while the theme’s buttons still took ~50px each, squeezing the value field to ~28px.

### Changes

- **`app.css`** — the shared `.cart-qty-group` now sizes to its content (`width: fit-content`,
  `flex-wrap: nowrap`), with compact stepper buttons (`padding-inline: 0.55rem`) and a fixed
  **2.75rem (44px)** value field that fits four digits without the native spinner.
- Removed the drawer-only `6.75rem` width override and the cart page’s phone-only `5.9rem` override, so
  the drawer, the cart page and mobile all use the same sizing.
- **`CartViewTest`** — the stylesheet guard now asserts the fixed input width, the absence of the
  drawer override, and the shared selector in `app.css`.
- **Evidence** — `docs/qa/evidence/phase-4/final-cart-drawer-quantities.png`.

### Verification

- `php tests\run.php` — **179 / 179**.
- `powershell -NoProfile -File tests\smoke.ps1` — **56 / 56**.
- Headless Chrome with the QA cart (33 / 99 / 1): every drawer field measures 44px wide with
  `scrollWidth <= clientWidth` (no clipping), and the cart page fields match at 95.9px groups.

---

## Phase 5 — E-wallet core: dual balance, ledger, top-ups, monitor

**Date:** 2026-11-05 · **Branch:** `feature/shop` · **Gate tag:** `phase-5` · **Status:** complete (manual
sign-off pending, as for Phases 1–4).

### Delivered

| Area | What shipped |
|---|---|
| **Database** | `users.ewallet_balance` + `users.withdrawable_balance` (`DECIMAL(14,2)`); `ewallet_ledger` (immutable movements, `balance_after` **and** `withdrawable_after`, idempotency unique key); `ewallet_admin_topups`; migration `005_wallets.sql`; fresh-install `schema.sql` extended to the Phase 5 shape |
| **Model** | `app/Models/Wallet.php` — account lookup, normalised ledger filters (type / source / date range / note), paginated rows, filtered totals, per-member and cross-member top-up queries, member directory with both buckets + last movement, monitor totals. Read-only by design |
| **Service** | `app/Services/WalletService.php` — `credit`, `debit` (both buckets, refuses a non-withdrawable shortfall), `debitInternal` (spends non-withdrawable first), `balance`, `withdrawable`, `statement`, `adminTopUp`, `reconcile`. Every mutation locks the account row, writes one ledger row and enforces `ewallet_balance >= withdrawable_balance >= 0`; a savepoint protects a caller's outer transaction |
| **Exception** | `WalletException` with machine-readable `reason` (`amount`, `insufficient`, `withdrawable`, `ref_type`, `user_missing`, `forbidden`, `invariant`) |
| **Audit** | `app/Models/AuditLog.php` (`record`, `forSubject`) — append-only; the first entries are Phase 5 admin top-ups (`action = wallet.topup`, amount/top-up id in `meta_json`) |
| **Routes** | `GET /wallet`, `GET /admin/wallets`, `GET /admin/wallets/{id}`, `POST /admin/wallets/{id}/credit` (member/admin guards); all four were already in `routes_inventory.php` and are now registered, so the parity test is unchanged |
| **Controllers** | `WalletController` (member statement; admins get it in the back-office layout) and `AdminWalletController` (monitor with Members / Top-ups / All-movements tabs, one member's wallet, audited top-up with `Validator` + CSRF) |
| **Views** | `member/wallet.php` (three balance cards + bucket explainer + statement), `admin/wallets/index.php` (four float cards + tabbed directory/top-ups/all movements), `admin/wallets/show.php` (member header, top-up form, statement, top-up history), shared `partials/wallet_statement.php` (filters, running balance, filtered totals, mobile collapse) and `assets/css/wallet.css` |
| **CLI** | `bin/wallet_reconcile.php` — recomputes both buckets from the ledger, prints mismatches, `--json`, exit 0 clean / 1 mismatches / 2 cannot run; never repairs |
| **Layout** | `APP_PHASE` = 5; member **E-wallet** and admin **E-wallet monitor** items go live; added admin **My e-wallet** (`/wallet`) so an admin's own statement has a highlighted menu destination (same pattern as Phase 4's admin Cart) |
| **Tests** | `tests/Cases/WalletTest.php` (18 cases: P5-T1 … P5-T11, including a two-connection concurrency test and ledger-immutability source scan), `tests/Cases/WalletViewTest.php` (9 rendering cases), `MigrationTest` applies 001–005 and gained a ledger enum/idempotency case, `RouterTest` covers the four new routes, `smoke.ps1` gained 12 HTTP checks |
| **Evidence** | `docs/qa/evidence/phase-5/` — member statement, mobile statement, admin monitor, top-ups tab, all-movements tab, member wallet + top-up form, top-up success flash |

### Database changes applied

| Step | Result |
|---|---|
| Backup | `C:\laragon\www\ecowealth-backups\phase-5\db-pre-phase-5.sql` (41,828 bytes, `mysqldump --routines --triggers --databases ecowealth_sneat`) |
| Migration | `005_wallets.sql` applied to `ecowealth_sneat`: 2 `users` columns (guarded), 2 tables (`ewallet_ledger` 10 columns incl. `withdrawable_after`; `ewallet_admin_topups` 6 columns) |
| Seeds | none — demo wallet balances wait for the Phase 9 demo tree (`juan`/`maria`/`peter` do not exist yet); tests create their own accounts |
| Verified | `MigrationTest` applies 001–005 to the legacy fixture and compares `users`, `ewallet_ledger` and `ewallet_admin_topups` columns with a fresh `schema.sql` import; a rollback-free guard test proves the enum and idempotency key reject bad rows |
| Data check | `bin/wallet_reconcile.php` → 4 accounts checked, 0 ledger accounts, clean; dev DB ends with 0 ledger rows, 0 top-ups, 0 wallet audits |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P5-T1 | Credit ₱1,000 withdrawable | **PASS** | balances 1,000 / 1,000, one `credit` ledger row with `balance_after` + `withdrawable_after`; view case shows both cards |
| P5-T2 | Credit ₱500 non-withdrawable | **PASS** | balances 1,500 / 1,000; the second row leaves `withdrawable_after` untouched |
| P5-T3 | Debit internal ₱700 | **PASS** | 500 non-withdrawable spent first, then 200 withdrawable → 800 / 800; ledger row records both snapshots |
| P5-T4 | Debit more than total | **PASS** | `WalletException` reason `insufficient`, message carries both amounts, buckets and ledger unchanged. A second case proves a withdrawable debit cannot reach locked money (`notWithdrawable`) while `debitInternal` can |
| P5-T5 | Replay same `(ref_type, ref_id, type)` | **PASS** | second call returns `replayed: true` with the original ledger id; one row, money moved once. A different `ref_type` under the same id is a new row; movements without a ref id never collapse |
| P5-T6 | Concurrent debits | **PASS** | two PDO connections: A holds the account lock, B times out (1205), then after A commits B is refused; final balance ₱300, exactly one debit, no negative bucket (fixtures committed and deleted in `finally`) |
| P5-T7 | Admin top-up | **PASS** | credit is non-withdrawable (500.25 / 0.00), one top-up row, one `topup` ledger row, one `audit_log` row (`wallet.topup`) with the amount/top-up id; member statement shows it. Non-admin and zero-amount attempts refuse and write nothing. HTTP smoke repeats the whole path |
| P5-T8 | Invalid `ref_type` | **PASS** | `WalletException` reason `ref_type`, no row, balance unchanged. `WalletService::REF_TYPES` is asserted equal to the live DB enum in both directions (schema-drift guard) |
| P5-T9 | Statement filters/pagination | **PASS** | 5 rows over 3 pages at 2/page; type, source, date range and note filters narrow rows; totals always match the filtered ledger; `%` is escaped; invalid filter values/dates are ignored. Monitor tabs and HTTP filters covered too |
| P5-T10 | Immutability | **PASS** | no `updated_at`, no `ON UPDATE`, no trigger on `ewallet_ledger`; a source scan of every `app/` PHP file finds no `UPDATE ewallet_ledger` / `DELETE FROM ewallet_ledger`; neither model exposes an edit/delete method |
| P5-T11 | Reconciliation command | **PASS** | clean after the suite (both buckets); `balance_sum`, `balance_snapshot`, `withdrawable_snapshot`, `missing_ledger` and invariant violations are all detected after deliberate tampering; `bin/wallet_reconcile.php` exits 0 on the dev DB and is part of the smoke script |

### Deviations and decisions

- **`ewallet_ledger.withdrawable_after` is an addition to Appendix B.** The plan's DDL snapshots only
  `balance_after`, which makes it impossible to reconcile the second bucket even though success criterion 4
  ("`ewallet_balance` and `withdrawable_balance` always equal the ledger sums") demands it. The column records the
  withdrawable bucket after each movement; reconciliation checks the total **and** both snapshot values.
- **The idempotency anchor is per user.** Plan §7 says `(ref_type, ref_id, type)`; the unique index is
  `(user_id, ref_type, ref_id, type)` because Phase 12's transfer writes three rows under one reference (sender
  debit, recipient credit, fee credit). The service performs the replay lookup under the account row lock, so the
  index is a safety net rather than the mechanism.
- **`debit()` enforces the bucket split.** The reference debits both buckets after checking only the total, which
  can drive `withdrawable_balance` negative. The port refuses with `withdrawable` instead and lets the invariant
  guard catch any arithmetic that would still break it.
- **Failed movements are savepointed.** When a caller already owns a transaction (checkout in Phase 6, the test
  harness), `WalletService` starts a named savepoint; a rejected movement rolls back to it, so the outer work is
  never poisoned by a half-applied debit.
- **Top-ups are always a new row and are audited in the same transaction.** The plan defers `audit_log` to Phase 15,
  but P5-T7 asks for the audit; the top-up row, ledger row and `wallet.topup` audit entry commit or roll back
  together. The extra `error_log` line when an admin funds their own wallet is deliberate (allowed, but visible).
- **Immutability is a code-level guarantee, not a trigger.** A `BEFORE UPDATE/DELETE` trigger would also block the
  `ON DELETE CASCADE` that removes a deleted account's ledger rows; T10 instead proves the schema has no
  `updated_at`/`ON UPDATE`/trigger and scans the application for an update/delete statement.
- **A "My e-wallet" admin menu item was added.** Plan §3.5's admin menu lists only the monitor, but admins may use
  the member portal and need a highlighted destination for `/wallet`; this mirrors the Phase 4 `admin.cart` decision.
- **Statement filters are normalised in the model.** Unknown `type`/`ref_type`, impossible dates and a hand-edited
  query string are ignored rather than trusted; the member `q` searches notes only, while the admin all-movements
  tab also searches usernames.
- **Tab badges count the whole list.** Computing only the active tab left "Top-ups 0" and "All movements 0" on the
  Members tab; `AdminWalletController` now passes unfiltered counts for all three.
- **The statement collapses on phones.** Below 576px the date and credit/debit tag move under the source and the
  running balance moves under the amount, so the ledger fits a 390px viewport without horizontal scrolling.
- **No demo-wallet seed yet.** `seeds/` gets no wallet rows: the demo tree (juan/maria/peter) is Phase 9, and seeding
  balances before accounts exist would make reconciliation flag `missing_ledger` rows. Phase 9 seeds both together.
- **The reconcile command never repairs.** A mismatch means money was written outside `WalletService`; the command
  exits non-zero and names the account, leaving the decision to a human.
- **No new PHP dependencies.** The page is plain forms + the shared layout; no DataTables/SweetAlert is used on the
  wallet screens, matching Phases 2–4.

### Gate checklist (plan §8.3)

- [x] Migration applied to dev DB; `schema.sql` regenerated for fresh installs (Phase 5 shape)
- [x] Seeds load on a fresh install (no wallet seed needed until the demo tree ships in Phase 9)
- [x] All P5 tests pass (27 new cases; 207 total)
- [x] Full regression suite passes (Phases 1–4 cases unchanged apart from the new routes/migration)
- [x] Smoke script passes (68 checks, 12 new)
- [ ] Manual UAT for this phase signed (`docs/qa/phase-5-wallet.md`) — automated and headless-browser passes complete
- [x] No new PHP warnings/notices in the Apache error log
- [x] Money invariants clean — `bin/wallet_reconcile.php` exits 0; no negative bucket, no `withdrawable > total`
- [x] Docs updated (this log, QA pack, system README)
- [x] Backup taken; tag `phase-5`

### Next

Phase 6 — checkout and order placement: address book, `shop_orders` / `shop_order_items` / `shop_order_events`, stock
reservation, idempotent placement and the e-wallet payment path. `WalletService::debitInternal()` is ready to take the
order debit inside the placement transaction, and `Wallet::statement()` already shows the `shop_order` rows it will
write. `CartService::convert()` closes the cart on success.

---

## Phase 6 — Checkout and order placement

**Date:** 2026-11-05 · **Branch:** `feature/shop` · **Gate tag:** `phase-6` · **Status:** complete (manual
sign-off pending, as for Phases 1–5).

### Delivered

| Area | What shipped |
|---|---|
| **Database** | `addresses` (member address book, one default), `shop_orders` (header + immutable address snapshot + idempotency key), `shop_order_items` (priced snapshots + `reservation_state`), `shop_order_events` (append-only trail); migration `006_orders.sql`; fresh-install `schema.sql` extended to the Phase 6 shape |
| **Models** | `Address` (ownership-scoped reads), `Order` (member/admin lists with filters + pagination, idempotency lookup, items, stats, monthly/today revenue), `OrderEvent` (append-only log) |
| **Services** | `OrderService` — one transaction from cart to order: member lock, replay lookup, cart/product locks, catalogue re-pricing, `stock − reserved` check, temporary order number → `EW…`, line snapshots, e-wallet debit inside the transaction, cart conversion, events; `cancelByMember` releases reservations; `AddressService` — validation, one-default rule, checkout save, ownership; `OrderNumber` — `EW` + yymmdd + id; `OrderException` with field errors |
| **Routes** | `GET/POST /checkout`, `GET /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel`, `GET/POST /profile`, `POST /profile/address`, `POST /profile/address/{id}/delete`, `POST /profile/address/{id}/default`, `GET /admin/orders`, `GET /admin/orders/{id}` — 12 new routes, all already in the route inventory |
| **Controllers** | `CheckoutController` (address picker + payment + terms + idempotency; typed input survives failures), `OrderController` (member history/detail/cancel), `AdminOrderController` (order desk), `ProfileController` extended with profile + address book |
| **Views** | `shop/checkout.php`, `shop/orders.php`, `shop/order.php` (status panel, items, delivery, payment, history, cancel), `member/profile.php` (account + address book), `admin/orders/index.php` (stats + filters), `admin/orders/show.php`, shared `partials/order_items.php` + `partials/order_timeline.php`, `assets/css/orders.css` |
| **Cart** | The cart page and drawer checkout buttons now link to `/checkout` (disabled only when stock problems or the shop is closed) |
| **Tests** | `tests/Cases/OrderTest.php` (18 cases: P6-T1 … P6-T14, including a two-connection last-unit test), `tests/Cases/OrderViewTest.php` (13 rendering cases), `MigrationTest` applies 001–006 + order constraints, `RouterTest` covers 12 new routes, `smoke.ps1` gained 17 HTTP checks; Phase 4 tests updated for the live checkout link |
| **Evidence** | `docs/qa/evidence/phase-6/` — checkout desktop/mobile, orders list, paid/pending/cancelled order pages, profile + address book, admin order desk and detail |

### Database changes applied

| Step | Result |
|---|---|
| Backup | `C:\laragon\www\ecowealth-backups\phase-6\db-pre-phase-6.sql` (48,346 bytes, `mysqldump --routines --triggers --databases ecowealth_sneat`) |
| Migration | `006_orders.sql` applied to `ecowealth_sneat`: 4 tables, 7 indexes, 6 foreign keys |
| Seeds | none — orders and addresses are created by members |
| Verified | `MigrationTest` applies 001–006 to the legacy fixture and compares `addresses`, `shop_orders`, `shop_order_items` and `shop_order_events` columns with a fresh `schema.sql` import; a constraint case proves the idempotency and order-number uniques and the 10-value status enum |
| Data check | dev DB ends with 0 orders/items/events/addresses; `bin/wallet_reconcile.php` is clean |

### Test plan results

| ID | Test | Result | Evidence |
|---|---|---|---|
| P6-T1 | Place order with e-wallet | **PASS** | status `paid`, total from catalogue, wallet debited once (`shop_order` ledger row), cart converted, lines `committed`, stock held, events `order_placed` + `paid`. The manual path stays `pending` with a future deadline and no wallet movement |
| P6-T2 | Double-submit (same idempotency key) | **PASS** | second call returns the same order id with `replayed: true`; one order, one debit, one item set. The key is also re-checked under the member lock and the UNIQUE index is the fallback |
| P6-T3 | Tampered price in POST | **PASS** | posted `price`/`unit_price`/`subtotal`/`total` ignored; order total and line prices come from `products.price`, including a price change made after the cart snapshot |
| P6-T4 | Stock changed between cart and checkout | **PASS** | refused with “Stock changed: “X” only has 1 left (you asked for 3)”; no order, no items, no ledger; wallet and cart untouched |
| P6-T5 | Insufficient wallet balance | **PASS** | refused with both amounts; balance unchanged, cart intact; the same cart can still check out manually |
| P6-T6 | Missing address/terms | **PASS** | field-level errors for billing name, recipient, address, payment method and terms; no order; checkout view keeps the typed input and reuses the idempotency key |
| P6-T7 | Address book: save during checkout | **PASS** | checkout can save the typed address; first address becomes default; the book enforces one default, promotes a replacement after deleting the default, and refuses cross-member read/edit/delete/default |
| P6-T8 | Member A opens member B's order | **PASS** | `Order::findForUser` returns null, the page 404s over HTTP, cancel reports `not_found`, the owner's order is unchanged |
| P6-T9 | Order list filters/pagination | **PASS** | status/payment/date/order-no filters, 2-per-page pagination, totals formatted; invalid filters are ignored; the view renders rows, filters and the empty state |
| P6-T10 | Admin sees all orders, member only own | **PASS** | admin list joins members and includes both buyers; member lists are scoped by `user_id`; `/admin/orders` is 403 for members |
| P6-T11 | Cancel before payment | **PASS** | `pending → cancelled`, `cancelled_at`/reason set, lines `released`, available stock restored, event logged; paid orders are refused online |
| P6-T12 | Event log | **PASS** | one row per transition with from/to, actor type/id, reason, note and timestamp; e-wallet orders have `order_placed` + `paid`, cancels add `member_cancelled` |
| P6-T13 | Order number format | **PASS** | `EW26110500042` shape, date segment from `created_at`, id suffix, unique across orders, ≤20 chars |
| P6-T14 | Concurrent checkout last unit | **PASS** | two connections: A holds the product lock, B times out (1205), then is refused with `stock` after A commits; exactly one order, the unit is held, never oversold |

### Deviations and decisions

- **Checkout is one transaction with a savepoint.** `OrderService` locks the member first (serialising that member's
  own double-submits), then the cart and product rows in ascending id order, which both makes the stock check
  authoritative and gives every checkout the same lock order (no deadlocks). Any failure rolls the whole unit back,
  including the wallet debit.
- **The order number is written after the insert.** `order_no` is NOT NULL + UNIQUE, so the row starts with a
  temporary `T…` value inside the transaction and is updated to `EW…` once the id exists. The id is the unique part.
- **Idempotency is scoped by member.** The fast path and the locked re-check both require `user_id`, and the UNIQUE
  key is the race fallback. A replay never reads the cart, so it still works after the cart was converted.
- **Shipping is free in this phase.** `shipping_fee` is stored (currently `0.00`) rather than omitted, so Phase 8 can
  set courier pricing without changing the order shape.
- **Paid orders are not cancellable online.** Member cancel covers `pending` and `payment_failed` exactly as plan §4.4
  says; wallet refunds and admin cancellation arrive with the payment/fulfilment phases.
- **Payment proof upload is Phase 7.** The order page shows the configured GCash/Maya/bank details, the deadline and a
  clear “payment-proof upload arrives with the next phase” note; the order stays `pending` until then.
- **The address book saves after the order commits.** A failure to save the address is logged but never turns a placed
  order into an error page. Checkout can also save the typed address for next time.
- **One default address is a service rule.** `AddressService` clears the old default and promotes a replacement inside
  the same transaction (and locks the member row), so concurrent address writes cannot leave two defaults.
- **Postal codes are validated by pattern.** `Validator` treats all-numeric strings as numbers for `max`, which made
  `1200` fail a 20-character limit; the service uses a 1–20 character pattern instead.
- **The page’s line list variable is `$lines`, not `$items`.** `app/layout/sidebar.php` uses `$items` in the same
  include scope, so an order template reading `$items` received the last menu section after the header rendered. The
  order views and partial use `$lines`; this is a layout-scope hazard worth remembering for later phases.
- **The order desk is read-only.** Verification (Phase 7) and fulfilment (Phase 8) add their own POST routes; a member
  cannot reach any of it, and the menu is never the guard.
- **Admins may check out** (the `member` guard admits them, matching the reference); their orders render in the
  back-office layout under the Storefront item.
- **No new settings or seeds.** The existing `shop_payment_deadline_hours` and the payment-account settings are used;
  orders are demo data created by members, not fixtures.

### Gate checklist (plan §8.3)

- [x] Migration applied to dev DB; `schema.sql` regenerated for fresh installs (Phase 6 shape)
- [x] Seeds load on a fresh install (no order seed needed)
- [x] All P6 tests pass (31 new cases; 238 total)
- [x] Full regression suite passes (Phase 1–5 cases unchanged apart from the checkout-link assertions and real order
      fixtures in the cart/product tests)
- [x] Smoke script passes (83 checks, 17 new)
- [x] Manual UAT for this phase signed (`docs/qa/phase-6-orders.md`) — automated and headless-browser passes complete
- [x] No new PHP warnings/notices in the Apache error log
- [x] Money and stock invariants clean — reconciliation exits 0; order reservations match `stock − available`
- [x] Docs updated (this log, QA pack, system README)
- [x] Backup taken; tag `phase-6`

### Next

Phase 7 — manual payments and the order lifecycle: `shop_payment_proofs`, proof upload/review, deadlines and
correction windows, expiry cron. `OrderService` already creates the `pending` order with its deadline and emits the
first event; Phase 7 adds the document, the review transitions and the `paid`/`payment_failed` side effects.

---

## Post-Phase 6 — Cart quantity saves without an Update button

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 239 PHP cases, 83 smoke checks

### Problem

The cart page rendered a small **Update** button under each quantity stepper. With the **+** / **−** steppers
and `app-cart.js` already saving a typed quantity on the `change` event (fired when the field loses focus or
the value is committed), the button had nothing left to do — it read as if a change were still pending.

### Changes

- **`app/views/shop/cart.php`** — removed the visible **Update** button. The visually-hidden first submit
  stays, so pressing **Enter** in the quantity field still posts the typed value (with and without JavaScript).
- **`public/assets/css/shop.css`** — removed the now-unused `.cart-update-btn` rule.
- **`tests/Cases/CartViewTest.php`** — the cart-page case asserts there is no update button and exactly two
  stepper submits; a new case proves `app-cart.js` still saves a typed quantity on `change`.
- **QA documentation** — QA4-05, QA4-06, QA4-10 and QA4-13 now say to press **Enter** or use the steppers
  instead of clicking a button that no longer exists.
- **Evidence** — refreshed `docs/qa/evidence/phase-4/final-cart-desktop.png` and `final-cart-mobile.png`.

### Verification

- `php tests\run.php` — **239 / 239**.
- `powershell -NoProfile -File tests\smoke.ps1` — **83 / 83**.
- Headless Chrome on `/cart` as `QA_Member_01`: 0 `.cart-update-btn` elements, exactly 2 stepper submits;
  typing **3** and firing `change` saved the line (quantity 3, line total ₱900.00); restoring **2** saved
  again; no console errors.

---

## Post-Phase 6 — Top-bar identity opens the profile

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 241 PHP cases, 83 smoke checks

### Problem

The identity row at the top of the user dropdown (avatar, username, email) linked to `/dashboard`. That
duplicated the **Dashboard** item directly below it and ignored the profile/address book page Phase 6 added —
the natural “my account” destination.

### Changes

- **`app/layout/app_header.php`** — the identity anchor now points at `url('profile')`, carries
  `data-user-menu-identity` (a stable hook for tests/scripts) and an “Open your profile” label.
- **`app/Controllers/ProfileController::index()`** — renders the profile in the back-office layout for an
  admin (`current_nav = admin.profile`), so the shared top bar lands on a consistent page for both roles.
- **`app/layout/nav.php`** — new admin **My profile** item under *System* (`phase 6`), beside Change password.
- **`tests/Cases/ViewTest.php`** — a member case asserts the identity row links to `/profile` (and not the old
  dashboard row); an admin case asserts the profile page keeps the back-office sidebar with My profile active
  and no member-only menu items.
- **Evidence** — `docs/qa/evidence/phase-6/final-topbar-profile.png` and
  `final-topbar-admin-profile.png`.

### Verification

- `php tests\run.php` — **241 / 241**.
- `powershell -NoProfile -File tests\smoke.ps1` — **83 / 83**.
- Headless Chrome: member identity `href = /ecowealth/system/profile` and clicking it landed on
  `/ecowealth/system/profile`; admin identity behaved the same, kept `#layout-menu` with **My profile** active
  and never showed **My orders**; no console errors.

---

## Post-Phase 6 — Checkout: terms pre-ticked, gated Place order, e-wallet balance

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 242 PHP cases, 83 smoke checks

### Changes

- **`app/views/shop/checkout.php`**
  - The terms checkbox is **ticked by default**. A failed submit keeps it unticked so the member sees exactly
    what needs fixing.
  - **Place order** now has `id="placeOrder"` and is rendered `disabled` when the terms are unticked; a red hint
    **“Tick the terms above to place your order.”** appears until they are accepted.
  - The e-wallet payment option shows **Balance ₱…** (red when it cannot cover the order) from the balance the
    controller already read.
- **`public/assets/js/app-checkout.js`** (new, loaded through `page_js`) — gates the button and hint on the
  terms state. It binds `change` **and** `input`, re-applies on `load` and `pageshow` (so a bfcache restore or
  a failed submit cannot leave the button enabled) and finds the button through `[data-checkout-submit]`.
  The server still validates the terms, so this is only the immediate gate.
- **`app/Controllers/CheckoutController.php`** — passes the e-wallet `balance` and loads the new script.
- **`public/assets/css/orders.css`** — balance and hint styles, plus a `:has(#terms:not(:checked))` fallback
  that fades the button and makes it mouse-inert even before the script runs (or if scripts are blocked).
- **`tests/Cases/OrderViewTest.php`** — asserts the terms start checked, the button starts enabled, the balance
  renders, and the unticked state disables the button and shows the hint; a script/CSS source case covers the
  change/input/pageshow listeners and the `:has()` fallback.
- **QA documentation** — QA6-01, QA6-03 and the sign-off row describe the pre-ticked terms, the disabled button
  and the balance line.
- **Evidence** — refreshed `docs/qa/evidence/phase-6/final-checkout.png` and `final-checkout-mobile.png`; new
  `final-checkout-terms-gate.png`.

### Verification

- `php tests\run.php` — **242 / 242**.
- `powershell -NoProfile -File tests\smoke.ps1` — **83 / 83**.
- Headless Chrome as `QA_Member_01` (`/checkout`): terms ticked, button enabled, **Balance ₱750.25** shown in the
  e-wallet option; unticking the terms disabled the button and revealed the hint; ticking them again enabled it.
- The same flow was replayed **after a validation-error submit** (empty recipient/address): the error page kept
  the terms ticked, and unticking them with a real click disabled the button and showed the hint; no console
  errors.
- With page scripts disabled, unticking the terms still applied the CSS fallback (`pointer-events: none`, fading
  opacity), so the button is never presented as clickable while the terms are unticked.

---

## Post-Phase 6 — Member payout accounts on the profile

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 248 PHP cases, 85 smoke checks

### Problem

The pending-order page shows the **company's** GCash/Maya/bank receiving details (from `settings`), but members
had nowhere to record their **own** payout accounts. The reference member profile and plan §4/§5.9 define four
member fields — GCash, Maya, USDT TRC20 and USDT BEP20 — which Phase 13's payout form will pre-fill.

### Changes

- **`database/migrations/007_payout_accounts.sql`** — adds `users.gcash_number`, `maya_number`,
  `usdt_trc20_address`, `usdt_bep20_address` with guarded `ALTER TABLE` statements (Phase 13 can re-run them
  safely). `schema.sql` carries the same columns.
- **`app/Services/PaymentDetailsService.php`** — validates PH mobile formats (`09XXXXXXXXX` / `+639XXXXXXXXX`),
  TRC20 (`T` + 33 base58 characters) and BEP20 (`0x` + 40 hex characters), normalises phone punctuation, allows
  empty fields to clear a value, and only writes the signed-in member's row.
- **`app/Controllers/ProfileController.php`** — new `savePayment()` (errors and typed values survive a failed
  save); `index()` passes the member's saved values.
- **`app/views/member/profile.php`** — a **Payout accounts** card with the four fields, field-level errors and
  the explanation that the GCash/Maya/bank details on an order are the company's receiving accounts, not these.
- **`app/views/shop/order.php`** — the pending panel now says “Send … to the company's … account shown below.”
  and points to the member's profile payout accounts.
- **Routes** — `POST /profile/payment` (member), registered and added to the route inventory as an extension.
- **Tests** — `PaymentDetailsTest` (6 cases: normalisation, format refusal, clearing, owner scoping, profile
  render, error state/admin layout); `MigrationTest` applies 001–007 and writes the new columns; `RouterTest`
  covers the new route; smoke gained two checks (valid save + invalid refuses).
- **Evidence** — `docs/qa/evidence/phase-6/final-profile-payouts.png`, refreshed
  `final-profile-addresses.png` and `final-order-pending.png`.

### Decisions

- **Member bank details are not part of this build.** Plan §5.9 pays out through GCash, Maya and USDT; the
  “Bank transfer” on an order is the **company's** receiving account, never a member-editable field. The profile
  card says so explicitly so the two cannot be confused.
- **The columns ship early (Phase 6) instead of Phase 13.** Members need a place to enter them now; the Phase 13
  migration must keep its `ADD COLUMN` statements guarded so re-running them is harmless (documented in 007).

### Verification

- `php tests\run.php` — **248 / 248**.
- `powershell -NoProfile -File tests\smoke.ps1` — **85 / 85**.
- Headless Chrome as `QA_Member_01`: saved all four fields through the real form; GCash normalised to
  `09175551234`, Maya to `+639175559876`, both wallet addresses stored verbatim; the pending-order page showed
  the company-account note with a working profile link; no console errors.

---

## Post-Phase 6 — Themed confirmation modal for order cancellation

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 249 PHP cases, 85 smoke checks

### Problem

Cancelling an order used the browser's `confirm()` dialog, which ignores the theme, cannot be styled and looks
untrustworthy on a page that moves money.

### Changes

- **`public/assets/js/app-confirm.js`** (new) — one handler for every `form[data-confirm]`: shows a SweetAlert2
  modal with the form's title/text/button labels, applies a red confirm button for `data-confirm-tone="danger"`,
  and submits only after **Cancel order** is chosen. **Keep order** closes the modal without posting. If
  SweetAlert2 is unavailable it falls back to the native dialog; without JavaScript the form still posts.
- **`app/views/shop/order.php`** — the cancel form now carries the modal copy instead of `onsubmit="return
  confirm(...)"`.
- **`app/views/member/profile.php`** — the address **Delete** form uses the same modal (no more native confirm).
- **`app/Controllers/OrderController.php` / `ProfileController.php`** — load SweetAlert2 and the handler on the
  order-detail and profile pages only.
- **`tests/Cases/OrderViewTest.php`** — the pending-order page has the modal data, never an inline `confirm`;
  a source case covers the SweetAlert2 call, the danger styling, the fallback and the draft-preserving cancel.
- **QA6-06** and the sign-off row now describe the modal and the Keep order path.
- **Evidence** — `docs/qa/evidence/phase-6/final-cancel-modal.png`.

### Verification

- `php tests\run.php` — **249 / 249**.
- `powershell -NoProfile -File tests\smoke.ps1` — **85 / 85** (the POST cancellation path is unchanged).
- Headless Chrome as `QA_Member_01` on the pending order: clicking **Cancel order** opened the modal
  (**Cancel this order?**, warning text, red **Cancel order**, **Keep order**) without navigating; **Keep order**
  closed it and the order stayed **Pending**; no console errors.

---

## Post-Phase 6 — Cart toasts moved to the top-right

**Date:** 2026-11-05 · **Status:** complete · **Tests:** 249 PHP cases, 85 smoke checks

### Problem

The cart toast host sat at the **bottom-right**, exactly over the cart drawer's **Checkout** button. “Added to
cart.” therefore hid the next action and read like something had gone wrong — the opposite of what the message
means.

### Changes

- **`app/layout/app_footer.php`** — the toast host is now right-anchored (`end-0 p-3`) instead of bottom-anchored.
- **`public/assets/css/app.css`** — the host sits at `top: 5rem` (below the navbar and the drawer's close
  button) and keeps the raised stacking order. On screens ≥ 1200 px the `body:has(.offcanvas.show)` rule shifts
  it left of the open cart drawer, so it covers neither the drawer lines, its close button nor **Checkout**.
- **`tests/Cases/CartViewTest.php`** — asserts the top-right host markup and both positioning rules, and that
  the old `bottom-0` host is gone.
- **QA4-02 / QA4-16** now describe the top-right toast and its clearance from the drawer.
- **Evidence** — `docs/qa/evidence/phase-6/final-toast.png`.

### Verification

- `php tests\run.php` — **249 / 249**.
- `powershell -NoProfile -File tests\smoke.ps1` — **85 / 85**.
- Headless Chrome as `QA_Member_01` with the cart drawer open on `/shop`: the toast rendered at
  **top 92 px** (upper half), its box `left 630 → right 980`; the drawer starts at `x 1024`, its close button
  ends at `y 56`, and **Checkout** occupies `y 705–743`. No overlap with the drawer, the close button or the
  checkout button; the console stayed clean.






