# EcoWealth — application (`system/`)

Membership, shop and e-wallet for **EcoWealth**, built as plain PHP 8 + MySQL with no
framework and no Composer. This folder is the backend application; the public marketing
front door is the repo-root `index.html` (decision D23 in `dmp/plan-ecowealth-cart-system.md`).

**Current status: Phase 14 — super-login.**
Phase 1 delivered the skeleton
(router, layouts, roles, settings, test harness); Phase 2 added the product catalogue and storefront;
Phase 3 adds associate packages — price, direct bonus, the 2–10 unilevel matrix, package contents,
the admin editor and the member packages page; Phase 4 adds the persistent cart, the drawer and the
stock rules; Phase 5 adds the dual-bucket e-wallet, the immutable ledger, admin top-ups, the member
statement and the back-office monitor; Phase 6 adds the address book, checkout, order placement with
stock reservation and e-wallet payment, the member order history and the admin order desk; Phase 7
adds the manual payment loop (proof upload, admin verify/reject, deadlines, correction windows, stock
release/re-reserve), admin cancellation with a wallet refund, the expiry command and the admin
settings page; Phase 8 adds the paid side of the order machine — pack, ready to ship, ship with
courier + tracking (stock deducted at that point), deliver, member receipt confirmation, the
auto-complete job, and the immutable printable invoice issued when an order is paid; Phase 9 adds the
four registration modes (standard / registration code / e-wallet / pending) with an optional sponsor
and no binary placement, registration codes (generate, validate, redeem, expire, CSV export),
membership activation by code / wallet / manual proof with the commission hook wired once per
activation, the member dashboard, genealogy (sponsor chain only), the member directory and the
activation review queue; Phase 10 pays the **direct referral** bonus (₱500 on Package A, ₱600 on
Package B) to the sponsor when an activation is approved — exactly once, audited in `commissions`,
credited to the withdrawable wallet — and adds the member **Earnings** statement and the admin
**Commissions** ledger.
Phase 11 adds the **indirect referral** ladder: levels 2–10 are priced by the source package's
`package_levels`, skip ineligible uplines without compressing the levels, honour the package/global
switches and `commission_indirect_max_levels`, and credit each eligible level in the same activation
transaction. The member earnings page gains the per-level breakdown and a CSV export; the admin ledger
gains type/level filters and its own CSV export.
Phase 12 adds **member-to-member wallet transfers**: `/wallet/transfer` sends money with the sender's
password as confirmation, an optional note, a configurable minimum and fee (zero for staff), a
non-withdrawable credit for the recipient and a non-withdrawable fee credit for the primary admin —
one transfer row and up to three ledger rows in one transaction. Daily and weekly limits are tracked
on the sender's row and checked under the same lock as the balance; `bin/reset_transfer_limits.php`
zeroes the counters every midnight (weekly on Monday). The admin wallet monitor gains the **Transfers**
tab and the float/locked/transfer/top-up cards, and `bin/wallet_reconcile.php` now reconciles transfer
legs too.
Phase 13 adds **payouts**: a member cashes out the withdrawable bucket at `/payouts` through GCash,
Maya or USDT (TRC20/BEP20), with a server-side service-fee percentage and — for USDT — a server-side
₱/USDT rate and network fee. The funds are **held at request time** (one `payout` debit), refunded on
rejection and settled on completion without a second debit; one open request per member. The admin
queue at `/admin/payouts` approves, rejects (reason required) and completes requests, with status
tabs, totals and the current rate/fee facts, and `bin/wallet_reconcile.php` now reconciles payout
holds and refunds as well.
Phase 14 adds **super-login**: a superadmin opens any active member's portal from `/slogin` through a
cookie-less, 8-hour, IP/user-agent-bound **URL-token session** (`?imp=<64-hex nonce>`), with a persistent
banner and an Exit action in the member layout. Only the nonce's SHA-256 is stored in
`impersonation_log`; every start, refusal and terminal status is audited, and `/admin/impersonations`
renders the trail. The superadmin's own cookie session is never modified.
The expiry job runs from the CLI **and** from a guarded URL
(`/system/cron/shop_expire.php`); the auto-complete and transfer-limit jobs do the same at
`/system/cron/order_autocomplete.php` and `/system/cron/reset_transfer_limits.php`. All three log to
`system/storage/`; the first two have kill switches on the settings page.

| | |
|---|---|
| App URL (Laragon) | `http://localhost/ecowealth/system/` |
| Front door | `http://localhost/ecowealth/` → repo-root `index.html` |
| Deployment | [`docs/deploy.md`](../docs/deploy.md) — beginner guide for Hostinger / shared hosting |
| Scheduled jobs | `docs/cron.md` (expiry + auto-complete: CLI, URL, Windows launcher, Hostinger) |
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
   mysql -u root ecowealth_sneat < database/seeds/003_products.sql
   mysql -u root ecowealth_sneat < database/seeds/004_packages.sql
   mysql -u root ecowealth_sneat < database/seeds/005_demo_tree.sql
   ```

   (`005_demo_tree.sql` is demo data — the sponsor tree `admin → juan → maria → peter/sarah/david`,
   their wallets and three sample registration codes. Drop it from a production install.)

4. **Open** `http://localhost/ecowealth/system/` and sign in.

### Demo accounts

| Username | Password | Role |
|---|---|---|
| `admin` | `Admin@123` | `admin` |
| `superadmin` | `Super@123` | `superadmin` (S-Login console, Phase 14) |
| `juan` · `maria` · `peter` · `sarah` · `david` | `Demo@1234` | `member` (Phase 9 demo tree: active associates plus one pending member) |

These are development credentials. Plan §9.6 requires both staff passwords to be changed,
the demo members removed and `seeds/005_demo_tree.sql` kept out of production.

### Built-in PHP server (no Apache)

```bash
php -S 127.0.0.1:8080 -t public public/router.php
# open http://127.0.0.1:8080
```

`router.php` mirrors `public/.htaccess`: static files stream through, the three standalone
pages (login, register, logout) keep their scripts, everything else goes to the front controller.

---

## Configuration

| File | Purpose |
|---|---|
| `app/config.php` | Defaults and flags, every constant declared with `defined() || define()` |
| `app/config.local.php` | **Git-ignored.** Local DB credentials; optional `APP_ENV` |

| Constant | Default | Meaning |
|---|---|---|
| `APP_ENV` | `production` (local: `development`) | `development` shows debug detail; later-phase nav items stay hidden until their phase ships |
| `APP_DEBUG` | derived from `APP_ENV` | Debug detail on error pages |
| `APP_PHASE` | `10` | Build phase, drives later-phase nav visibility |
| `APP_BASE_URL` | auto-detected | Set by `public/index.php` before bootstrapping |
| `APP_URL` | empty | Canonical origin for copyable links, e.g. `http://localhost` or `https://www.ecowealth.com`; empty → derived from the request. The install prefix comes from `APP_BASE_URL` |
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
│   ├── routes.php             the live route table (Phase 1 … 10 routes)
│   ├── routes_inventory.php   all 98 reference pages → route / page / CLI / drop / defer
│   ├── Core/                  Router, Request, View, Validator, Paginator, Upload, MaintenanceGate,
│   │                          CronAccess, CronLock, CronLog, InvoicePage
│   ├── Controllers/           Home, Shop, Package, Cart, Wallet, Checkout, Order, AdminDashboard,
│   │                          AdminProduct, AdminPackage, AdminWallet, AdminOrder, AdminSettings,
│   │                          Profile
│   ├── Models/                Product, Package, Cart, Wallet, AuditLog, Address, Order, OrderEvent,
│   │                          PaymentProof, Shipment, Invoice
│   ├── Services/              StockService, UploadService, PackageService, CartService, WalletService,
│   │                          OrderService, OrderNumber, AddressService, PaymentDetailsService,
│   │                          PaymentService, FulfilmentService, FulfilmentException, InvoiceService,
│   │                          InvoiceNumber
│   ├── views/                 errors/, partials/, shop/, member/, admin/, profile/
│   └── layout/                app_header.php, app_footer.php, sidebar.php, nav.php, auth_*
├── bin/                       shop_expire.php + .cmd, order_autocomplete.php + .cmd, wallet_reconcile.php
├── storage/                   cron logs/ and locks/ (outside public/, ignored, deny-all .htaccess)
├── public/                    docroot
│   ├── index.php              front controller
│   ├── router.php             PHP built-in server parity
│   ├── cron/shop_expire.php   guarded web entry for the expiry job (local/URL cron)
│   ├── cron/order_autocomplete.php  guarded web entry for the auto-complete job
│   ├── login.php register.php logout.php   (standalone this build)
│   ├── dashboard.php          legacy redirect shim → the routed /dashboard
│   ├── uploads/               products/, packages/, proofs/ — PHP execution disabled
│   └── assets/                EcoWealth theme (Sneat 3.x) + product photos
├── database/
│   ├── schema.sql             fresh-install DDL (no database name hard-coded)
│   ├── migrations/            001_settings_roles … 013_live_revisions
│   └── seeds/                 settings, accounts, demo catalogue, demo packages, demo tree
└── tests/
    ├── bootstrap.php          rebuilds ecowealth_sneat_test, assertions, fixtures
    ├── run.php                php tests/run.php [filter]
    ├── smoke.ps1              HTTP smoke test (P1-T1 … P9-T17)
    ├── Cases/                 Skeleton, Router, Parity, Settings, SettingsPage, View, Upload,
    │                          Migration, Product, ProductView, Package, PackageView, Cart,
    │                          CartView, Wallet, WalletView, Order, OrderView, Payment,
    │                          PaymentView, PaymentDetails, Fulfilment, FulfilmentView, Invoice,
    │                          Cron, Membership, MembershipView, Live
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
| `any` | Public: `/`, the register validation endpoints and logout |
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

### Phase 4 URLs — cart

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/cart` | member | Cart page: lines, stock problems, summary |
| GET | `/cart/count` | member | JSON badge count + the refreshed drawer body |
| POST | `/cart/add` | member | Add units (JSON for XHR, flash + redirect otherwise) |
| POST | `/cart/update` | member | Set quantity, step `direction=up/down`, or remove at 0 |
| POST | `/cart/remove` | member | Remove one line |

### Phase 5 URLs — e-wallet

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/wallet` | member | Member statement: both buckets, filters, running balance |
| GET | `/admin/wallets` | admin | Monitor: float, buckets, member directory, top-ups, all movements |
| GET | `/admin/wallets/{id}` | admin | One member's wallet, statement and top-up form |
| POST | `/admin/wallets/{id}/credit` | admin | Audited top-up (non-withdrawable) |

### Phase 6 URLs — checkout and orders

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/profile` | member | Profile + address book |
| POST | `/profile` | member | Save the full name |
| POST | `/profile/address` | member | Create / update an address |
| POST | `/profile/address/{id}/delete` | member | Delete an address |
| POST | `/profile/address/{id}/default` | member | Make an address the default |
| GET | `/checkout` | member | Address picker, payment, terms, idempotency key |
| POST | `/checkout` | member | Place the order (one transaction) |
| GET | `/orders` | member | Order history with status/payment/date/search filters |
| GET | `/orders/{id}` | member | Order page: status, items, delivery, payment, history |
| POST | `/orders/{id}/cancel` | member | Cancel an unpaid order and release its reservation |
| GET | `/admin/orders` | admin | Order desk: stats, filters, every order |
| GET | `/admin/orders/{id}` | admin | One order: member, payment review, items, delivery, history |

### Phase 7 URLs — manual payments

| Method | Path | Guard | Purpose |
|---|---|---|---|
| POST | `/orders/{id}/proof` | member | Upload a payment proof (JPG/PNG/WebP/PDF ≤ 5 MB) |
| GET | `/admin/proofs/{id}` | admin | Stream one stored proof (never a public URL) |
| POST | `/admin/orders/{id}/transition` | admin | Verify (`paid`), reject (`payment_failed`) or cancel; Phase 8 also pack (`packing`) and ready (`ready_to_ship`) |
| GET | `/admin/settings` | admin | Payment accounts, payout methods, payment/correction windows, seller block, expiry/auto-complete switches |
| POST | `/admin/settings` | admin | Validated, audited save (`settings.update`) |

### Phase 8 URLs — fulfilment and invoices

| Method | Path | Guard | Purpose |
|---|---|---|---|
| POST | `/admin/orders/{id}/ship` | admin | Courier + tracking required; deducts the stock and writes the shipment |
| POST | `/admin/orders/{id}/deliver` | admin | Shipped → delivered, optional proof-of-delivery note |
| POST | `/orders/{id}/confirm` | member | Delivered → completed (own order only) |
| GET | `/orders/{id}/invoice` | member | Printable A4 snapshot; own paid orders only |
| GET | `/admin/orders/{id}/invoice` | admin | The same snapshot from the back office |

### Phase 9 URLs — registration, codes and membership

| Method | Path | Guard | Purpose |
|---|---|---|---|
| POST | `/register/validate-code` | any | JSON: is this registration code usable? (flips an expired row) |
| POST | `/register/check-username` | any | JSON: format + availability |
| POST | `/register/check-sponsor` | any | JSON: resolve a sponsor by username **or** referral code |
| GET | `/register/packages` | any | JSON: the active packages the e-wallet mode can buy |
| GET | `/dashboard` | member | Member dashboard: membership, wallet, referral link, orders, commissions |
| GET | `/membership` | member | Status, package, activation paths (code / wallet / proof) and history |
| POST | `/membership/activate` | member | Request an activation (one open request per member) |
| GET | `/genealogy` | member | Team chain (level 1 direct, level 2 their recruits, …) + direct recruits + team by level (sponsor chain only) |
| GET | `/admin/members` | admin | Member directory with search/status/package filters and stats |
| GET | `/admin/members/{id}` | admin | Account, membership, wallet, orders, team, activation history, audit |
| POST | `/admin/members/{id}/status` | admin | Suspend (`is_active=0`) or restore, audited |
| POST | `/admin/members/{id}/toggle` | admin | Reference `admin_toggle_user`: flip suspended ↔ restored |
| GET | `/admin/codes` | admin | Stats, issue form, filters and the code list |
| POST | `/admin/codes/generate` | admin | Transactional batch of 1–500 codes, audited |
| GET | `/admin/codes/export` | admin | CSV of the filtered codes |
| GET | `/admin/activations` | admin | Activation review queue (tabs, filters, stats) |
| POST | `/admin/activations/{id}/approve` | admin | Member active + package + commission hook, exactly once |
| POST | `/admin/activations/{id}/reject` | admin | Reason required; member returns to pending |
| GET | `/admin/activations/{id}/proof` | admin | Stream a manual activation proof (never a public URL) |

### Phase 10–11 URLs — commissions

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/earnings` | member | The member's own awards: totals, filters, level breakdown, award table, detail drawer, e-wallet link |
| GET | `/earnings/export` | member | CSV "Unilevel statement" of the signed-in member's filtered awards |
| GET | `/admin/commissions` | admin | Company ledger: credited and skipped awards, filters (member/source/type/level/status/date) and totals |
| GET | `/admin/commissions/export` | admin | Filtered CSV of the ledger (audited) |

### Phase 12 URLs — wallet transfers

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/wallet/transfer` | member | Send-funds page: recipient, amount, note, password, fee preview, limit meters, recent transfers |
| POST | `/wallet/transfer` | member | Confirm with the sender's password; one transfer row + up to three ledger rows |
| GET | `/admin/wallets?tab=transfers` | admin | The monitor's transfer log (the rest of the monitor is Phase 5) |

### Phase 13 URLs — payouts

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/payouts` | member | Withdrawable balance, the request form (four channels, prefilled accounts), fee/USDT preview, the open request, and the withdrawal history |
| POST | `/payouts/request` | member | Create the request and **hold** the amount (one `payout` debit, one pending row) |
| GET | `/admin/payouts` | admin | The queue: status tabs, totals, rate/fee facts, search + date filters, per-row actions |
| POST | `/admin/payouts/{id}/approve` | admin | `pending → approved` (no wallet movement — the money is already held) |
| POST | `/admin/payouts/{id}/reject` | admin | `pending/approved → rejected`, refunding the hold; a reason is required |
| POST | `/admin/payouts/{id}/complete` | admin | `approved → completed` after the funds were sent off-platform (no second debit) |

### Phase 14 URLs — super-login

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/slogin` | superadmin | The console: member picker, active sessions, recent sessions, audit link |
| POST | `/slogin` | superadmin | Validate the target, rate-limit, create the audit row and open the URL-token session |
| POST | `/slogin/exit` | member (imp) | End the impersonation from the banner / sign-out; marks `logged_out` |
| GET | `/admin/impersonations` | admin | The impersonation audit trail with status tabs, filters and pagination |

An impersonated request carries `?imp=<64-hex nonce>`, which is the PHP session id of a cookie-less
session under the `ecowealth_imp` name; `app/auth.php` opens it before `session_start()` and
`auth_validate_imp_session()` re-validates TTL, IP, user-agent, the hashed nonce and the target before
any route runs. `url()` appends the token to every same-app link/form/redirect while the session is live
(static assets, uploads and `absolute_url()` links never carry it). The raw token is never stored: the
audit row keeps only its SHA-256.

Standalone pages (outside the router this build, all routed through Apache rewrites):
`/login`, `/register`, `/logout`. `/dashboard` became a routed page in Phase 9; the old
`public/dashboard.php` is now a redirect shim only. The three job entries
(`/cron/shop_expire.php`, `/cron/order_autocomplete.php`, `/cron/reset_transfer_limits.php` →
`public/cron/`) are standalone, guarded files too; see [Cron](#cron-jobs).

Every route is mirrored in `app/routes_inventory.php`, which maps all 98 reference pages.
`tests/Cases/ParityTest.php` (P1-T12) fails when a page marked *Port* has no matching
route or a later-phase route is registered early.

---

## Settings

`settings` is a key/value table read through `setting()` / `setting_bool()` /
`setting_int()` / `setting_float()` and written with `setting_set()`. The Phase 1 seeds
cover payment accounts, shop windows, commission toggles, payout fees, USDT rates and
e-wallet transfer limits; later phases read them without further migrations. Since Phase 7 the
payment accounts and the three payment windows (`shop_payment_deadline_hours`,
`shop_correction_window_hours`, `shop_max_proof_attempts`) are editable at `/admin/settings`; since
Phase 8 the same page edits the invoice seller block (`seller_name/address/tin/phone`) and the
auto-complete window/switch (`shop_autocomplete_days`, `shop_autocomplete_enabled`). The Phase 13
follow-up adds **Payout methods**: the floor (`min_payout`), each channel's fee and switch
(`service_fee_<method>`, `payout_<method>_enabled`) and the USDT rail (`usdt_rate`,
`usdt_trc20_gas_fee`, `usdt_bep20_gas_fee`). The page is five independent cards — **Shop**,
**Receiving accounts**, **Payout methods**, **Payment windows**, **Fulfilment & invoices** — each
with its own form and **Save settings** button; the controller only validates, writes and audits
the submitted card's keys, so one save can never overwrite another card. Money values keep their
column precision (`decimal:2` for the floor and fees, `decimal:4` for the rate and gas) and a
percentage is capped at 100 so the service never has to clamp a saved value. The transfer settings
(`ewallet_transfer_fee`, `ewallet_min_transfer`, `ewallet_transfer_daily_limit`,
`ewallet_transfer_weekly_limit`) are still seeded and read by their service but not editable in
the UI yet — an operator changes them with SQL until Phase 15 expands this page. The full
settings page expands in Phase 15.

Maintenance mode is `maintenance_mode=1`: guests see a branded **503** with
`Retry-After: 3600`, admin and superadmin sessions pass straight through.

`free_registration_enabled` (default `1`) gates the free standard/pending registration modes
and the referral-link signup. It is a seeded setting; the full settings page that will expose
it arrives in Phase 15.

---

## Catalogue (Phase 2)

- `products.stock` is the **physical** count on hand. `available` is derived by
  `StockService`: `stock − reserved`, where reserved comes from non-terminal order lines
  whose `reservation_state` is `reserved` or `committed`. Placement reserves, payment
  commits, shipping deducts and cancellation releases (Phase 6/8).
- The storefront lists **active** products, including sold-out ones (badge logic:
  `In stock`, `N left` at 5 or fewer, `Sold out`). Deactivating a product hides it from
  the shop and its detail URL returns 404; existing orders are never edited.
- Prices are display-only here; checkout re-reads them from the catalogue.
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

## Cart (Phase 4)

- One **active** cart per member, created on the first add. `CartService` locks the account row
  before the lookup/insert, so two concurrent first-adds cannot create two carts; the cart becomes
  `converted` when checkout turns it into an order.
- Every mutation is scoped by the caller's cart id — a posted `item_id` alone never authorises a
  write (the reference IDOR defect C3). Quantity is capped by `StockService::available()`
  (`stock − reserved`), not by client input.
- Totals always join the live `products.price`; `cart_items.unit_price` is only a snapshot refreshed
  when the page is read. A price change in the back office reaches the cart on its next load.
- Inactive products stay visible but quarantined: they block checkout, quantity increases are refused
  and removal/zero still works. The drawer is re-rendered server-side by `partials/cart_offcanvas_body.php`,
  so the badge, drawer and cart page can never disagree.

---

## E-wallet (Phase 5)

- **Two buckets.** `users.ewallet_balance` is the total spendable money; `users.withdrawable_balance` is
  the part that may be paid out. Commission earnings raise both; admin top-ups raise the total only;
  internal purchases/registrations/fees spend the non-withdrawable part first, so earnings stay
  withdrawable while wallet-funded spending still works.
- **One ledger.** Every movement is one immutable `ewallet_ledger` row written by `WalletService`,
  carrying `balance_after` and `withdrawable_after`. There is no update/delete path: corrections are new
  `refund`/`adjustment` rows.
- **Safety.** Each mutation runs in a transaction that locks the account row, enforces
  `ewallet_balance >= withdrawable_balance >= 0` and refuses rather than overdrawing. System movements
  are idempotent by `(user_id, ref_type, ref_id, type)`, so a retried checkout or cron cannot move money
  twice. When a caller already owns a transaction the service uses a savepoint.
- **Top-ups.** `adminTopUp()` writes the top-up row, the ledger row and an `audit_log` entry
  (`action = wallet.topup`) in one transaction. Top-ups are non-withdrawable by design.
- **Reconciliation.** `php bin/wallet_reconcile.php` recomputes both buckets from the ledger and reports
  mismatches (exit 0 clean / 1 mismatched / 2 cannot run, `--json` for tooling). It never repairs.
- **Presentation.** `/wallet` is the member statement with type/source/date/note filters and page totals;
  `/admin/wallets` is the monitor (float, withdrawable vs locked, directory, transfer, top-up and
  all-movement tabs) and `/admin/wallets/{id}` is one member's wallet with the top-up form and history.

---

## Wallet transfers (Phase 12)

- **Send money, password-confirmed.** `/wallet/transfer` takes a recipient username, the amount, an
  optional note and the sender's **own password**. A wrong password refuses before any row is locked.
- **The recipient rules.** The account must exist, be enabled and not be suspended, and cannot be the
  sender. An admin account may still receive (staff accounts default to `member_status = pending`).
- **The money.** One transaction: insert the transfer row, debit the sender `amount + fee`
  (non-withdrawable money first), credit the recipient the amount (non-withdrawable), credit the fee to
  the **primary admin** (non-withdrawable), increment the sender's counters, write the audit row. A
  refused transfer writes nothing at all.
- **The fee.** `settings.ewallet_transfer_fee` for members; admin/superadmin pay nothing and are exempt
  from the limits. When the primary admin is also the recipient the fee leg is written with
  `ref_id = NULL` and the transfer id in its note — two credits for one account under one
  `(user_id, ref_type, ref_id, type)` key would collide.
- **The limits.** `settings.ewallet_min_transfer` (floor), `ewallet_transfer_daily_limit` and
  `ewallet_transfer_weekly_limit` (0 = unlimited). Usage lives on the sender row
  (`ewallet_sent_today` / `ewallet_sent_this_week`) and is read and incremented under the same lock as
  the balance, so concurrent transfers cannot both fit a limit they only fit once.
- **The reset job.** `bin/reset_transfer_limits.php` (also `/system/cron/reset_transfer_limits.php` and
  `bin/reset_transfer_limits.cmd`) zeroes the daily counters every run and the weekly counters on Monday;
  idempotent, with `--dry-run`, `--doctor`, `--week`, `--daily-only` and `--json`.
- **Superadmin attribution.** A superadmin's transfer books against the primary admin's wallet and ledger
  (`sender_id`), while `audit_log` keeps the superadmin as the actor.
- **The monitor.** `/admin/wallets` now carries the **Transfers** tab (sender/recipient links, amount, fee
  and note, search by member/note and a date range) plus the **Transfers today** card with lifetime volume
  and fees; the float cards show the withdrawable/locked percentages.
- **Reconciliation.** `bin/wallet_reconcile.php` gained a **Transfers** section: every completed transfer
  must have exactly one sender debit of `amount + fee`, one recipient credit of `amount` and its fee
  credit. Orphan legs (a transfer row removed by an account deletion) are reported as a count, never as a
  money error.

---

## Payouts (Phase 13)

- **Held at request time (D17).** `PayoutService::request()` takes the account row lock, checks the
  **withdrawable** bucket (not the total balance), the one-open-request rule and the amount floor, then
  inserts the request and debits the wallet (`ref_type = 'payout'`, both buckets) in one transaction. The
  member cannot spend money already promised to the payout.
- **The maths is server-side only.** `service_fee_<method>` percent is deducted to produce the net; for the
  two USDT channels the net is converted at `usdt_rate` after subtracting `usdt_*_gas_fee`:
  `net_usdt = (net − gas × usdt_rate) / usdt_rate`. The rate and the gas fee are stored on the request as a
  snapshot, so a later settings change cannot rewrite a queued request. Without a rate the USDT channels
  refuse (they are shown as unavailable on the page) — the browser never supplies a rate (plan §2.3).
- **One open request.** `pending` and `approved` both hold the money, so both refuse a new request; the
  check runs under the account lock, so two concurrent submits serialise.
- **The queue.** `/admin/payouts` defaults to the **Pending** tab, with tabs, counts, gross/fee/net totals
  and the current minimum, rate and gas facts. Approve records the staff account and moment; reject requires
  a reason and refunds the hold (`ref_type = 'refund'`, both buckets restored to the split the hold took);
  complete records that the funds were sent off-platform and moves no money. A status change is a conditional
  `UPDATE … WHERE status IN (…)` whose row count must be 1, so a double-clicked approve cannot approve twice,
  and nobody can process their own request.
- **The withdrawal rules are editable.** `/admin/settings` → **Payout methods** writes
  `min_payout`, `service_fee_<method>`, `payout_<method>_enabled`, `usdt_rate` and
  `usdt_*_gas_fee`, validated (money precision, a percentage capped at 100, a rate of 0 meaning
  "USDT closed") and audited as `settings.update`. A rate left at 0 keeps both USDT channels
  unavailable and tells the member why; the card warns the admin while it is unset.
- **Audit and live surfaces.** `payout.request`, `payout.approve`, `payout.reject` and `payout.complete`
  entries carry the before/after status, the amount, the method and the note. The member page
  (`member.payouts:{id}`) and the queue (`admin.payouts`) refresh in place; the request card is held while it
  has unsaved input. The member page's ETag also mixes the wallet revision (a commission refreshes the
  withdrawable card), the profile revision (the form prefills the saved payout accounts) and
  `PayoutService::settingsFingerprint()`, a hash of the effective withdrawal rules — so a settings save, a
  migration or a direct SQL edit reaches an open tab on its next poll. Both surfaces declare
  `data-live-config="config"`, so the settings save's broadcast refreshes them at once in the same browser,
  and `app-payout.js` re-binds the fee/USDT preview when the request card is replaced.
- **Refund id collision.** `refund` is shared with Phase 7's order refunds and the two id sequences overlap,
  so a payout refund whose `ref_id` is already taken for that member is written with `ref_id = NULL` and
  named in its note (the same fallback the transfer fee uses). The ledger's idempotency key can therefore
  never mistake an order refund for a payout refund and skip it.
- **Reconciliation.** `bin/wallet_reconcile.php` gained a **Payouts** section: every request must have one
  `payout` debit of its amount, and a rejected request exactly one refund credit; orphan legs are reported as
  a count, never as a money error.

---

## Super-login (Phase 14)

- **No cookie, no password.** `POST /slogin` validates that the caller is a `superadmin` and the target is an
  active member (staff and suspended/deactivated accounts are refused), draws a 32-byte nonce, records the
  audit row and redirects to `/dashboard?imp=<nonce>`. `app/auth.php` opens a cookie-less PHP session whose id
  **is** the nonce, under its own `ecowealth_imp` name, so the superadmin's `ecowealth_sid` session is never
  read or replaced.
- **The nonce is stored only as a hash.** `impersonation_log.nonce_hash` is `hash('sha256', $nonce)`;
  the raw token never reaches the database, the console or the audit page. A lost link cannot be resumed.
- **Validated on every request.** `auth_validate_imp_session()` runs before routing and refuses a missing,
  tampered, expired, IP-changed or user-agent-changed session, or a target that has been suspended or deleted.
  A refusal destroys the session, marks the row `expired` and bounces the tab to `/slogin?expired=1`.
- **The token follows the tab.** While the session is live, `url()` appends `?imp=<nonce>` to every same-app
  link, form action and redirect (including the live-refresh feed, which uses the current URL). Static
  assets, uploaded files and `absolute_url()` referral links never carry it.
- **The banner is always visible.** The member layout renders `partials/impersonation_banner.php` on every
  page: the member being viewed, the superadmin who opened it, the TTL, and a CSRF-protected **Exit member
  session** button that marks the row `logged_out` and returns to the console. Signing out from the member
  sidebar ends the impersonation the same way.
- **Audited.** Opening (`slogin.start`), every refusal (`slogin.denied`, with the reason), expiry
  (`slogin.expire`) and exit (`slogin.stop`) write `audit_log` entries. `/admin/impersonations` lists every
  session with superadmin, member, IP, device, start, expiry and status; the console shows the live and recent
  sessions. `Impersonation::expireOverdue()` sweeps rows whose TTL passed without another request.
- **Rate limit.** Five failed attempts for one target in 15 minutes are refused with
  `rate_limited`; the counter lives in the superadmin's own session (the reference behaviour), and each
  refusal is audited.

---

## Checkout and orders (Phase 6)

- **Checkout is one transaction.** `OrderService::placeFromCart()` locks the member (serialising their own
  double submits), the cart and every product row in ascending id order, re-prices from `products.price`,
  re-checks `stock − reserved`, snapshots each line, writes the order and its events, debits the e-wallet
  inside the same unit and converts the cart. Any failure rolls the whole attempt back — no order, no
  reservation, no debit, cart intact.
- **The checkout page** pre-selects the e-wallet only when its balance covers the total and always shows that
  balance inside the e-wallet option. The terms are ticked by default; unticking them disables **Place order**
  (`app-checkout.js`), and the server still validates the checkbox.
- **The server owns the money.** Posted prices and totals are never read; the order total is the catalogue
  subtotal plus the stored shipping fee (`0.00` for now — decision D8 keeps shipping free in this build).
- **Idempotent placement.** The checkout form carries a hidden key; `shop_orders.idempotency_key` is UNIQUE
  and the replay lookup runs again under the member lock, so a double submit returns the same order.
- **Payments.** E-wallet pays immediately (`paid`, items `committed`) and debits the wallet
  (`debitInternal`, so non-withdrawable funds are spent first). GCash/Maya/bank leave the order `pending`
  with `shop_payment_deadline_hours`; the proof upload and review loop is Phase 7 (below).
- **Stock lifecycle.** Placement reserves (`reserved`), payment commits (`committed`), shipping deducts
  (`deducted`, Phase 8) and cancellation releases. The cart, `StockService` and checkout all read the same
  `stock − reserved` number.
- **Addresses.** The address book keeps one default per member (a service rule, not an index) and every
  order stores an immutable address snapshot — editing the book never rewrites an order.
- **Member pages.** `/orders` lists the member's orders with filters; `/orders/{id}` shows the status panel,
  line snapshots, delivery, payment, the event timeline and a cancel form for unpaid orders only.
- **Profile & payout accounts.** `/profile` holds the full name, the address book and the member's payout
  accounts (GCash, Maya, USDT TRC20/BEP20). `/payouts` prefills its channel boxes from those accounts and
  pays withdrawals to them (Phase 13); the GCash/Maya/bank details printed on an order are the **company's**
  receiving accounts (settings), not the member's.
- **Order desk.** `/admin/orders` shows the stats, filters and every order; `/admin/orders/{id}` shows the
  member's wallet link, items, delivery snapshot, history and the Phase 7 payment-review pane.
- **Order numbers** are `EW` + `yymmdd` + the 5-digit id; the id is what makes them unique.

---

## Manual payments and the order lifecycle (Phase 7)

- **The loop.** `pending` → (member uploads a proof) → `payment_review` → (admin verifies) → `paid`, or
  → (admin rejects) → `payment_failed` → (member re-uploads within the correction window) → …
  `PaymentService` owns every transition; nothing writes `shop_orders.status` outside the services.
- **One transaction per action.** Each call locks the order row, re-reads the status, the applicable
  deadline and the attempt count, and writes the proof, the status, the reservation state and the event
  together. A proof uploaded at the same moment as the expiry sweep has exactly one winner.
- **The proof window.** `pending` reads `payment_deadline`; `payment_failed` reads `correction_deadline`
  (both from settings). The attempt cap (`shop_max_proof_attempts`, default 3) is enforced in the service,
  never by hiding the form. `payment_review` is exempt from expiry: submitted evidence is never timed out.
- **Evidence.** `shop_payment_proofs` keeps one row per attempt (file path, MIME, size, amount claimed,
  reference, transfer date, decision). Files are JPG/PNG/WebP/PDF ≤ 5 MB, stored under
  `public/uploads/proofs/` with random names and served **only** through the admin-guarded
  `/admin/proofs/{id}` stream — never linked directly.
- **Verification.** The amount must equal the order total exactly and the reference must be unused on any
  other order (paid reference or a non-rejected proof). Verification commits the reservation
  (`reserved` → `committed`; the stock is deducted when the order ships — Phase 8).
- **Rejection.** Marks the proof rejected, releases the hold, sets `payment_failed` and opens the
  correction window. A re-upload re-reserves the stock — and is refused if it sold out meanwhile, so the
  `stock − reserved` invariant can never go negative.
- **Expiry.** The expiry job cancels `pending`/`payment_failed` orders past their applicable deadline,
  releases the stock and logs `payment_expired` (actor `system`). It is idempotent. It runs from the
  CLI (`php bin/shop_expire.php`), a guarded URL (`/system/cron/shop_expire.php`, handy locally and for
  URL-only hosts) or `bin/shop_expire.cmd` on Windows; see [Cron](#cron-jobs) below and
  `docs/cron.md`.
- **Admin cancellation & refunds.** An administrator may cancel any order before it ships. A wallet-paid
  order is refunded through `WalletService::refund()`, which restores the withdrawable share the purchase
  actually spent (derived from the ledger snapshots) so a top-up cannot be washed into withdrawable money.
  A proof-paid order has no wallet movement, so nothing is refunded; a pending proof is rejected so
  nothing stays “awaiting review”.
- **Segregation of duties.** An administrator cannot verify, reject or cancel an order they placed
  themselves — the service refuses (`self_review`) and the page says so before any button is offered.
- **Settings.** `/admin/settings` edits the receiving GCash/Maya/bank accounts, the three timing rules and
  the **Expire unpaid orders automatically** kill switch; every save is validated and audited
  (`settings.update` with the before/after values). The full settings page expands in Phase 15.

---

## Fulfilment, completion and invoices (Phase 8)

- **The paid half of the order machine.** `paid → packing → ready_to_ship → shipped → delivered
  → completed`. `FulfilmentService` owns every step: each call locks the order row, re-checks the
  status and writes the status, timestamps, shipment facts and event in one transaction. Pack and
  ready-to-ship reuse the Phase 7 `POST /admin/orders/{id}/transition` endpoint (`to=packing` /
  `to=ready_to_ship`); ship and deliver have their own routes.
- **Shipping needs courier + tracking.** The courier is a real `<select>` (J&T Express, LBC Express,
  Ninja Van, Lalamove, Grab Express, 2GO, DHL) with an **Others (type below)** option and a free-text
  field beside it, so a local courier can be recorded without a code change. The field is rendered
  visible by default (works with JavaScript disabled) and `app-ship.js` hides it while a listed
  courier is chosen; the server resolves the pair and ignores the text unless **Others** is picked.
  A missing or malformed value is a field error and nothing is written. Saving a shipment **deducts
  `products.stock`** and marks the lines `deducted` in the same transaction, so `stock − reserved` is
  continuous across the shipment (`FulfilmentService` locks the products in ascending id order, the
  same order checkout uses). If the physical count was corrected below the order quantity, the ship is
  refused with a clear message instead of overselling.
- **Delivery and confirmation.** An admin marks a shipped order delivered (optional POD note, appended
  to the shipment notes). The member then confirms receipt from the order page; the order becomes
  `completed` with `completed_at` and a `receipt_confirmed` event. Confirming is scoped to the buyer.
- **No cancellation after shipment.** `PaymentService::cancelByAdmin` and the order page both refuse
  it; a returns/RTS flow is deferred.
- **Invoices are immutable snapshots.** `InvoiceService` writes one `shop_invoices` row plus its
  `shop_invoice_items` inside the transaction that marks an order paid (e-wallet at placement, manual
  payment at verification). Numbering is `INV-yymmdd-#####`. Seller identity
  (`seller_name/address/tin/phone`), buyer, delivery address, lines and totals are frozen; editing
  the settings or the catalogue later never rewrites an issued invoice. Re-printing reads the same
  row, so the number and content never drift.
- **Voiding.** Cancelling a paid order marks its invoice `voided_at` / `void_reason`; the document is
  kept and printed with a **VOID** stamp (content unchanged). Nothing is deleted.
- **Backfill.** A paid order from before this phase has no snapshot; opening its invoice page issues
  one once, under the order row lock (two tabs produce one invoice).
- **Auto-complete.** `FulfilmentService::autoComplete()` completes `delivered` orders older than
  `shop_autocomplete_days` (default 7) with an `auto_completed` event (actor `system`). Idempotent;
  `shop_autocomplete_enabled=0` skips every run.
- **Settings.** `/admin/settings` gains **Fulfilment & invoices**: the seller block printed on every
  invoice and the auto-complete days + switch. Saves are validated and audited.

---

## Registration and membership (Phase 9)

- **Sponsor chain only.** `users.sponsor_id` is the one upline link. There is no binary tree, no
  left/right leg and no placement mode anywhere in the schema, routes or views (P9-T17 asserts it).
  The sponsor field is **optional** (decision D3, amended after Phase 9): a blank field joins the
  member under the company root (the primary admin), while a typed sponsor must exist and be active
  and may be entered as a **username or the member's referral code**.
- **Four modes.** *Standard* (free, active, no package, shopping only) and *pending* (free referral
  signup, activates later) follow `free_registration_enabled`; *code* redeems a registration code and
  lands active with the code's package; *e-wallet* is a **signed-in** action where the registrar pays
  `packages.price`, the primary admin is credited as revenue and the new member is active
  immediately. A guest choosing e-wallet is sent to `/login` (P9-T5). A guest who registers is signed
  in on success; a registrar's session is never disturbed (P9-T4).
- **Referral codes.** Every account gets a unique 8-character code (unambiguous alphabet), used by
  `?ref=CODE` links. Existing accounts are backfilled by migration 011; `?sponsor=username` still
  works.
- **Registration codes.** `ABCD-EFGH-JKLM` (12 characters, three groups, unambiguous set), stored
  uppercase and compared case-insensitively; an admin issues 1–500 per batch bound to one package,
  price and optional expiry (admins and superadmins, D21 — no member self-purchase yet). Validation
  flips a past-dated unused code to `expired`; **single use is a conditional UPDATE
  (`WHERE status='unused'`, row count must be 1)**, so two parallel redemptions cannot both win
  (P9-T13). Used codes keep `used_by` / `used_at`; the CSV export follows the list filters.
- **Activation.** A pending/inactive member activates with a code, the e-wallet or a manual proof
  (GCash / Maya / bank). Only one request may be open at a time; the amount is `packages.price`.
  Code and wallet activations complete immediately; a manual proof waits in
  `/admin/activations` for **approve** (member active + package + commission hook) or **reject**
  (reason required; the member stays pending). `activations.commission_processed_at` is stamped
  inside the same transaction, so a replayed approval can never run the hook twice (P9-T12).
  Registration paid by code/wallet writes an already-approved activation, so Phases 10–11 have one
  anchor for every award.
- **Commission hook.** `ActivationService::complete()` calls `CommissionService::onActivation()`
  exactly once, inside the activation transaction. Phase 10 pays the direct award; Phase 11 adds the
  unilevel ladder (levels 2–10).
- **Member status.** `pending → active` on activation; an admin may suspend (which also clears
  `is_active`, so the session is dropped and sign-in is refused) and restore. Suspending a pending
  member restores them to `pending`, not `active`. Every change is audited (`member.status`).
  A standard member is active without a package and cannot self-activate in this build (package
  upgrades are a later phase).
- **Genealogy and dashboard.** `/genealogy` focuses on the member's **team chain**: the self row first,
  then level 1 (direct recruits), level 2 (their recruits), and so on up to ten levels, plus a paginated
  direct-recruits table and a detailed team-by-level card. Each team-by-level row reads
  `@member → @sponsor` — the upline that recruited them — so the chain is visible member by member, and
  every level card paginates on its own (ten rows by default, with the usual 5/10/15… rows control) under
  its own `?levelN_page` / `?levelN_per_page` keys, so one crowded level never moves the others. It is
  cycle-safe and never renders uplines on the member page (the admin member view still shows the upline).
  `/dashboard` is the routed member landing page: membership status, both wallet buckets, referral link,
  recent orders and commission totals, with a link to **Earnings**. Admins keep the back-office dashboard
  at the same URL.
- **Demo data.** `database/seeds/005_demo_tree.sql` creates `admin → juan → maria → peter/sarah/david`
  (david is pending), their mixed wallet balances with matching ledger rows, and three sample codes
  (`DEMO-PKGA-2A7K`, `DEMO-PKGB-3B8L` unused; `DEMO-EXPD-4C9M` expired). Every demo member signs in
  with `Demo@1234`. Remove the file from a production install.

---

## Commissions (Phases 10–11)

The compensation plan's first two pillars: a **direct referral** bonus for the sponsor, and the
**indirect referral** unilevel ladder (levels 2–10) up the same sponsor chain, both paid once, as
withdrawable wallet money.

- **One trigger.** Only a completed package activation pays — `ActivationService::complete()` calls
  `CommissionService::onActivation()` inside the activation transaction, then stamps
  `activations.commission_processed_at`. Standard registrations, product orders and rejected
  activations pay nothing.
- **Direct (level 1).** `packages.direct_referral_bonus` of the **source** package (₱500 on Package A,
  ₱600 on Package B). A package with a zero bonus awards nothing (no row, no error).
- **Indirect (levels 2–10).** The service walks `users.sponsor_id` from the sponsor's sponsor upward,
  reading the **source package's** `package_levels` matrix. Package A pays 100/50/40/30/20/10/5/5/5;
  Package B pays **20% more at every level** (120/60/48/36/24/12/6/6/6), so its ₱6,998 price carries a
  stronger referral incentive. `packages.indirect_enabled = 0` or
  `settings.commission_indirect_enabled = 0` pays the direct leg only;
  `settings.commission_indirect_max_levels` (2–10) caps the depth. An ineligible upline **consumes its
  level** (no compression, `commission_compression` reserved at 0) and deeper levels keep their own
  amounts; a zero amount writes no row; a cycle or self-sponsor stops the walk.
- **Eligibility.** The recipient must be active (`is_active = 1`), `member_status = 'active'` **and**
  have a package. Otherwise the level is written with `status = 'skipped'` and a reason — it never
  moves money. A member with no sponsor writes no row at all; the company root (no package) is never
  paid by a blank-sponsor signup.
- **Exactly once.** `commissions` is unique on `(type, source_user_id, user_id, level)`, the service
  checks `forEvent()` before writing, and the activation stamp blocks a replayed approval. A credited
  row always has `ledger_id` pointing at its immutable `ewallet_ledger` movement
  (`ref_type='commission'`, `ref_id` = award id); both are written in one transaction.
- **Withdrawable.** Every wallet credit is earnings, so it raises both buckets. `totalsFor()` and the
  statements count only `credited` rows as money.
- **Reporting.** `/earnings` (member, own rows only) shows total/month/direct/indirect, the
  **Unilevel breakdown** (levels 1–10 with counts, amounts and share bars), filters, the award drawer
  and a filtered **CSV export**. `/admin/commissions` shows the whole ledger with
  member/source/type/level/status/date filters, the totals finance needs and its own filtered CSV
  export (audited as `commission.export`). Both reward rows carry a JSON record that
  `app-commissions.js` renders into one shared offcanvas — the member drawer hides the recipient row.
- **Reconciliation.** `php bin/wallet_reconcile.php` reports **Wallets** and **Commissions** and exits
  non-zero if either fails: credited sum vs `ref_type='commission'` ledger sum, missing/orphan ledger
  rows and amount drift. `settings.commission_ledger_since` (written by migration 012) marks where the
  award history begins; commission credits that predate it are reported as **legacy** (the development
  database has two from earlier QA work) and do not fail the check.
- **Immutable.** There is no update or delete path for an award or a ledger row. A correction is a new
  `adjustment` movement, never an edit.

```bash
php bin/wallet_reconcile.php          # wallets + commissions, exit 0 when clean
php bin/wallet_reconcile.php --json
```

---

## Cron jobs

Three scheduled jobs, each with a CLI runner, a guarded URL entry and a Windows launcher.

| Job | CLI | Web | Windows |
|---|---|---|---|
| Shop expiry (cancel unpaid orders) | `bin/shop_expire.php` | `/system/cron/shop_expire.php` | `bin/shop_expire.cmd` |
| Order auto-complete (close old deliveries) | `bin/order_autocomplete.php` | `/system/cron/order_autocomplete.php` | `bin/order_autocomplete.cmd` |
| Transfer-limit reset (daily/weekly counters) | `bin/reset_transfer_limits.php` | `/system/cron/reset_transfer_limits.php` | `bin/reset_transfer_limits.cmd` |

- Flags (CLI `--x=…`, URL `?x=…`): `limit`, `dry-run`, `json`, `doctor`, `log-dir` (CLI only),
  `quiet` (CLI only), `help`; the expiry job also has `from-settings`, the reset job `week` and
  `daily-only`.
- `--from-settings` recomputes expiry deadlines from the **current** settings instead of the stored
  stamps (`pending`: `created_at` + payment window; `payment_failed`: last rejection + correction
  window). The cron itself uses the stamps, so changing a window never retroactively cancels old
  orders.
- `--week` forces the transfer-limit weekly reset on any weekday (repairing a missed Monday);
  `--daily-only` never touches the weekly counter.
- The web entries are trusted on loopback (local testing needs no setup); any other caller needs
  `?key=` matching `CRON_KEY` in `app/config.local.php`. The CLI is always allowed.
- One run at a time (`CronLock`); monthly logs at `storage/logs/shop_expire-YYYY-MM.log`,
  `storage/logs/order_autocomplete-YYYY-MM.log` and `storage/logs/reset_transfer_limits-YYYY-MM.log`;
  `--doctor` reports PHP/DB time, settings, candidates, lock and log state.
- Kill switches: `shop_expiry_enabled` and `shop_autocomplete_enabled` (settings page). Each makes
  every run of its job exit 0 without changing anything. The reset job has nothing to switch off — a
  run only zeroes counters a transfer would have incremented.

```bash
php bin/shop_expire.php --doctor
php bin/shop_expire.php --dry-run
php bin/shop_expire.php

php bin/order_autocomplete.php --doctor
php bin/order_autocomplete.php --dry-run
php bin/order_autocomplete.php

php bin/reset_transfer_limits.php --doctor
php bin/reset_transfer_limits.php --dry-run
php bin/reset_transfer_limits.php
```

Full operations guide, Hostinger cron lines and troubleshooting: **`docs/cron.md`**.

---

## Tests

```bash
php tests/run.php                 # full suite (669 cases in Phase 14)
php tests/run.php Router           # cases whose group/label matches "Router"
php tests/run.php --probe-failure  # proves a failing assertion exits non-zero (P1-T7)

powershell -NoProfile -File tests/smoke.ps1    # HTTP checks against the running app
```

- `tests/bootstrap.php` drops and recreates `ecowealth_sneat_test` from `schema.sql` and
  the seeds, so a run never touches the development database (the name must end in `_test`).
- Every case runs inside a transaction that is rolled back. Cases that need later-phase
  tables (cart/order references) or their own transaction (package rollback) handle that
  explicitly, because DDL commits.
- Uploads are written to a scratch folder (`UPLOAD_ROOT`) and cron logs/locks to another
  (`STORAGE_PATH`) under the system temp directory.
- The migration test builds `ecowealth_sneat_migration_test` from
  `tests/fixtures/legacy_phase0_schema.sql`, applies migrations 001–018 in order and
  compares the result with `schema.sql`.

The smoke script needs the app running and MySQL access for temporary changes (a throw-away
member account with its wallet, orders, addresses, proofs, shipments and invoices, products with SKU
prefix `SMOKE-`, packages named `Smoke Package…`, a member-to-member transfer with its ledger legs and
the limit counters it moves, the payment/fulfilment/transfer settings it edits, the super-login sessions
and audit rows it creates, and `maintenance_mode`; all restored):

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
| `004_carts.sql` | `carts`, `cart_items` |
| `005_wallets.sql` | `users.ewallet_balance` + `withdrawable_balance`, `ewallet_ledger`, `ewallet_admin_topups` |
| `006_orders.sql` | `addresses`, `shop_orders`, `shop_order_items`, `shop_order_events` |
| `007_payout_accounts.sql` | `users.gcash_number`, `maya_number`, `usdt_trc20_address`, `usdt_bep20_address` |
| `008_payment_proofs.sql` | `shop_payment_proofs` (attempts, decision, reviewer, reference index) |
| `009_shop_expiry.sql` | `settings.shop_expiry_enabled` (the expiry kill switch) |
| `010_fulfilment.sql` | `shop_shipments`, `shop_invoices`, `shop_invoice_items`, `settings.shop_autocomplete_enabled` |
| `011_membership.sql` | membership columns on `users` (`phone`, `member_status`, `package_id`, `sponsor_id`, `referral_code`, `reg_payment_method`, `reg_code_id`, `reg_paid_by`, `activated_at`), the referral-code backfill, `reg_codes`, `activations` |
| `012_commissions.sql` | `commissions` (the award ledger and its `(type, source_user_id, user_id, level)` idempotency key) |
| `013_live_revisions.sql` | `live_revisions` (one monotonic revision per live page surface; see [live updates](../docs/live-updates.md)) |
| `014_indirect_commissions.sql` | the three settings that steer the indirect ladder (`commission_indirect_enabled`, `commission_indirect_max_levels`, `commission_compression`) |
| `015_package_b_premium_ladder.sql` | Package B's 20%-higher referral ladder (guarded: only the old defaults are replaced) |
| `016_transfers.sql` | `users.ewallet_sent_today` + `ewallet_sent_this_week`, `ewallet_transfers`, and the four transfer settings when missing |
| `017_payouts.sql` | `payout_requests` (the withdrawal queue and its hold/refund trail), the four `users` payout accounts (no-op when 007 already ran) and the eleven payout settings when missing |
| `018_impersonation.sql` | `impersonation_log` (the S-Login sessions: superadmin/member snapshots, `nonce_hash` unique, IP/user-agent, active/expired/logged_out, TTL) |

The wallet tables are read and written only by `WalletService`; `bin/wallet_reconcile.php` is the
supported way to prove the buckets still match the ledger after a migration or a data import.
Transfers are written only by `TransferService` (one transaction per transfer); the limit counters are
zeroed only by `bin/reset_transfer_limits.php`, and `bin/wallet_reconcile.php` also proves every
transfer's three ledger legs are present.
Payout requests are written and moved through their status machine only by `PayoutService` (one
transaction per request and per decision); the hold and the refund are the only wallet movements a
payout produces, and `bin/wallet_reconcile.php` also proves every request has its hold and every
rejection its refund.
Super-login sessions are written and validated only by `ImpersonationService`; `impersonation_log` holds
a SHA-256 of the URL token (never the token itself), and `auth_validate_imp_session()` is the only path
that ends an expired or mismatched session.
Order stock lives in `shop_order_items.reservation_state`; `StockService` is the only reader of the
`stock − reserved` number, and `OrderService` (placement, member cancel), `PaymentService`
(review, admin cancel, expiry) and `FulfilmentService` (ship deducts, completion has no stock effect)
are its only writers. Invoices and shipments are written only by `InvoiceService` and
`FulfilmentService`; their rows are append-only (`shop_invoices` gains only `voided_at`/`void_reason`).
`bin/shop_expire.php` / `/system/cron/shop_expire.php` is the supported way to expire unpaid orders; it
never touches an order whose proof is under review. `bin/order_autocomplete.php` /
`/system/cron/order_autocomplete.php` completes delivered orders after the configured window; neither
job touches a cancelled or completed order (see `docs/cron.md`).

---

## Conventions

- `declare(strict_types=1)`, 4 spaces, classes in `app/Core|Models|Services|Controllers`.
- Views escape with `e()`; no SQL and no business rules in views.
- All POSTs are CSRF protected (central check in the router for routed POSTs).
- Forms redirect after POST; validation errors go through `errors_set()` / `error_for()` and are shown
  **once**: a neutral prompt (or a `_form` message) above the form and each reason under its input.
  Do not also queue a danger flash for the same failure — that duplicated every registration error.
- Money is `DECIMAL` in MySQL and formatted with `money()`; no floats in SQL.
- `users.member_status` is the member's status (pending / active / inactive / suspended);
  `users.is_active` only says whether sign-in is allowed. Status badges and status counts
  read `member_status` — suspended also clears `is_active`, but a pending member can sign in.
- Every wallet movement is a `WalletService` call inside a transaction — never a direct
  `UPDATE users SET ewallet_balance` from a controller, model or view.
- Every table paginates server-side through `Paginator::fromTableRequest()` and
  `app/views/partials/pagination.php`: 10 rows by default, with a rows-per-page control
  offering 5, 10, 15 … 100 (`?per_page=N`) that keeps the active filters. A filter form
  carries `<?= per_page_hidden() ?>` so changing a filter never resets the chosen size.
  The footer's change/link handlers remember where the table card sat in the viewport and
  put it back after the reload, so changing the size never jumps the page to the top.
  Fixed-size lists (the catalogue card grid, dashboard snapshots) use `fromRequest()` /
  `new Paginator()` and pass `'show_per_page' => false` to the partial.
- Copy controls use the shared `[data-copy]` handler and are rendered as a bare icon button
  (`btn btn-icon btn-text-secondary`, as on the code rows and referral cards): `data-copy` holds the
  value, `data-copy-tooltip` the hover text and `data-bs-toggle="tooltip"` the Bootstrap tooltip. A
  successful copy shows a green check plus a "Copied!" tooltip for ~1.8 s, then reverts; a labelled
  control can mark its text with `data-copy-text`, though none remain in the UI.

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
