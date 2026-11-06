# EcoWealth — application (`system/`)

Membership, shop and e-wallet for **EcoWealth**, built as plain PHP 8 + MySQL with no
framework and no Composer. This folder is the backend application; the public marketing
front door is the repo-root `index.html` (decision D23 in `dmp/plan-ecowealth-cart-system.md`).

**Current status: Phase 8 — fulfilment, completion and invoices.** Phase 1 delivered the skeleton
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
auto-complete job, and the immutable printable invoice issued when an order is paid. The expiry job
runs from the CLI **and** from a guarded URL (`/system/cron/shop_expire.php`); the auto-complete job
does the same at `/system/cron/order_autocomplete.php`. Both have kill switches on the settings page
and log to `system/storage/`. Registration and commissions follow in later phases; their menu entries
stay hidden until their phase lands.

| | |
|---|---|
| App URL (Laragon) | `http://localhost/ecowealth/system/` |
| Front door | `http://localhost/ecowealth/` → repo-root `index.html` |
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
   ```

   (The Phase 5 wallet tables need no seed: demo balances arrive with the Phase 9 demo tree.)

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
| `APP_ENV` | `production` (local: `development`) | `development` shows debug detail; later-phase nav items stay hidden until their phase ships |
| `APP_DEBUG` | derived from `APP_ENV` | Debug detail on error pages |
| `APP_PHASE` | `8` | Build phase, drives later-phase nav visibility |
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
│   ├── routes.php             the live route table (Phase 1 … 8 routes)
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
│   ├── login.php register.php logout.php dashboard.php   (standalone this build)
│   ├── uploads/               products/, packages/, proofs/ — PHP execution disabled
│   └── assets/                EcoWealth theme (Sneat 3.x) + product photos
├── database/
│   ├── schema.sql             fresh-install DDL (no database name hard-coded)
│   ├── migrations/            001_settings_roles … 010_fulfilment
│   └── seeds/                 settings, accounts, demo catalogue, demo packages
└── tests/
    ├── bootstrap.php          rebuilds ecowealth_sneat_test, assertions, fixtures
    ├── run.php                php tests/run.php [filter]
    ├── smoke.ps1              HTTP smoke test (P1-T1 … P8-T10)
    ├── Cases/                 Skeleton, Router, Parity, Settings, View, Upload, Migration,
    │                          Product, ProductView, Package, PackageView, Cart, CartView,
    │                          Wallet, WalletView, Order, OrderView, Payment, PaymentView,
    │                          Fulfilment, FulfilmentView, Invoice, Cron
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
| GET | `/admin/settings` | admin | Payment accounts, payment/correction windows, seller block, expiry/auto-complete switches |
| POST | `/admin/settings` | admin | Validated, audited save (`settings.update`) |

### Phase 8 URLs — fulfilment and invoices

| Method | Path | Guard | Purpose |
|---|---|---|---|
| POST | `/admin/orders/{id}/ship` | admin | Courier + tracking required; deducts the stock and writes the shipment |
| POST | `/admin/orders/{id}/deliver` | admin | Shipped → delivered, optional proof-of-delivery note |
| POST | `/orders/{id}/confirm` | member | Delivered → completed (own order only) |
| GET | `/orders/{id}/invoice` | member | Printable A4 snapshot; own paid orders only |
| GET | `/admin/orders/{id}/invoice` | admin | The same snapshot from the back office |

Standalone pages (outside the router this build, all routed through Apache rewrites):
`/login`, `/register`, `/logout`, `/dashboard`. The two job entries
(`/cron/shop_expire.php`, `/cron/order_autocomplete.php` → `public/cron/`) are standalone, guarded
files too; see [Cron](#cron-jobs).

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
auto-complete window/switch (`shop_autocomplete_days`, `shop_autocomplete_enabled`). The full
settings page expands in Phase 15.

Maintenance mode is `maintenance_mode=1`: guests see a branded **503** with
`Retry-After: 3600`, admin and superadmin sessions pass straight through.

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
  `/admin/wallets` is the monitor (float, withdrawable vs locked, directory, top-up and all-movement tabs)
  and `/admin/wallets/{id}` is one member's wallet with the top-up form and history.

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
  subtotal (plus the stored shipping fee, currently 0.00 until Phase 8).
- **Idempotent placement.** The checkout form carries a hidden key; `shop_orders.idempotency_key` is UNIQUE
  and the replay lookup runs again under the member lock, so a double submit returns the same order.
- **Payments.** E-wallet pays immediately (`paid`, items `committed`) and debits the wallet
  (`debitInternal`, so non-withdrawable funds are spent first). GCash/Maya/bank leave the order `pending`
  with `shop_payment_deadline_hours`; the proof upload and review loop is Phase 7 (below).
- **Stock lifecycle.** Placement reserves (`reserved`), payment commits (`committed`), fulfilment will deduct
  at ship (Phase 8), and cancellation releases. The cart, `StockService` and checkout all read the same
  `stock − reserved` number.
- **Addresses.** The address book keeps one default per member (a service rule, not an index) and every
  order stores an immutable address snapshot — editing the book never rewrites an order.
- **Member pages.** `/orders` lists the member's orders with filters; `/orders/{id}` shows the status panel,
  line snapshots, delivery, payment, the event timeline and a cancel form for unpaid orders only.
- **Profile & payout accounts.** `/profile` holds the full name, the address book and the member's payout
  accounts (GCash, Maya, USDT TRC20/BEP20). The company pays withdrawals to those accounts in Phase 13; the
  GCash/Maya/bank details printed on an order are the **company's** receiving accounts (settings), not the
  member's.
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
  (`reserved` → `committed`; stock is deducted when the order ships in Phase 8).
- **Rejection.** Marks the proof rejected, releases the hold, sets `payment_failed` and opens the
  correction window. A re-upload re-reserves the stock — and is refused if it sold out meanwhile, so the
  `stock − reserved` invariant can never go negative.
- **Expiry.** The expiry job cancels `pending`/`payment_failed` orders past their applicable deadline,
  releases the stock and logs `payment_expired` (actor `system`). It is idempotent. It runs from the
  CLI (`php bin/shop_expire.php`), a guarded URL (`/system/cron/shop_expire.php`, handy locally and for
  URL-only hosts) or `bin/shop_expire.cmd` on Windows; see [Cron](#cron-expiry-job) below and
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
- **Shipping needs courier + tracking.** A missing or malformed value is a field error and nothing is
  written. Saving a shipment **deducts `products.stock`** and marks the lines `deducted` in the same
  transaction, so `stock − reserved` is continuous across the shipment (`FulfilmentService` locks the
  products in ascending id order, the same order checkout uses). If the physical count was corrected
  below the order quantity, the ship is refused with a clear message instead of overselling.
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

## Cron jobs

Two scheduled jobs, each with a CLI runner, a guarded URL entry and a Windows launcher.

| Job | CLI | Web | Windows |
|---|---|---|---|
| Shop expiry (cancel unpaid orders) | `bin/shop_expire.php` | `/system/cron/shop_expire.php` | `bin/shop_expire.cmd` |
| Order auto-complete (close old deliveries) | `bin/order_autocomplete.php` | `/system/cron/order_autocomplete.php` | `bin/order_autocomplete.cmd` |

- Flags (CLI `--x=…`, URL `?x=…`): `limit`, `dry-run`, `json`, `doctor`, `log-dir` (CLI only),
  `quiet` (CLI only), `help`; the expiry job also has `from-settings`.
- `--from-settings` recomputes expiry deadlines from the **current** settings instead of the stored
  stamps (`pending`: `created_at` + payment window; `payment_failed`: last rejection + correction
  window). The cron itself uses the stamps, so changing a window never retroactively cancels old
  orders.
- The web entries are trusted on loopback (local testing needs no setup); any other caller needs
  `?key=` matching `CRON_KEY` in `app/config.local.php`. The CLI is always allowed.
- One run at a time (`CronLock`); monthly logs at `storage/logs/shop_expire-YYYY-MM.log` and
  `storage/logs/order_autocomplete-YYYY-MM.log`; `--doctor` reports PHP/DB time, settings,
  candidates, lock and log state.
- Kill switches: `shop_expiry_enabled` and `shop_autocomplete_enabled` (settings page). Each makes
  every run of its job exit 0 without changing anything.

```bash
php bin/shop_expire.php --doctor
php bin/shop_expire.php --dry-run
php bin/shop_expire.php

php bin/order_autocomplete.php --doctor
php bin/order_autocomplete.php --dry-run
php bin/order_autocomplete.php
```

Full operations guide, Hostinger cron lines and troubleshooting: **`docs/cron.md`**.

---

## Tests

```bash
php tests/run.php                 # full suite (329 cases in Phase 8)
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
  `tests/fixtures/legacy_phase0_schema.sql`, applies migrations 001–010 in order and
  compares the result with `schema.sql`.

The smoke script needs the app running and MySQL access for temporary changes (a throw-away
member account with its wallet, orders, addresses, proofs, shipments and invoices, products with SKU
prefix `SMOKE-`, packages named `Smoke Package…`, the payment/fulfilment settings it edits, and
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
| `004_carts.sql` | `carts`, `cart_items` |
| `005_wallets.sql` | `users.ewallet_balance` + `withdrawable_balance`, `ewallet_ledger`, `ewallet_admin_topups` |
| `006_orders.sql` | `addresses`, `shop_orders`, `shop_order_items`, `shop_order_events` |
| `007_payout_accounts.sql` | `users.gcash_number`, `maya_number`, `usdt_trc20_address`, `usdt_bep20_address` |
| `008_payment_proofs.sql` | `shop_payment_proofs` (attempts, decision, reviewer, reference index) |
| `009_shop_expiry.sql` | `settings.shop_expiry_enabled` (the expiry kill switch) |
| `010_fulfilment.sql` | `shop_shipments`, `shop_invoices`, `shop_invoice_items`, `settings.shop_autocomplete_enabled` |

The wallet tables are read and written only by `WalletService`; `bin/wallet_reconcile.php` is the
supported way to prove the buckets still match the ledger after a migration or a data import.
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
- Forms redirect after POST; validation errors go through `errors_set()` / `error_for()`.
- Money is `DECIMAL` in MySQL and formatted with `money()`; no floats in SQL.
- Every wallet movement is a `WalletService` call inside a transaction — never a direct
  `UPDATE users SET ewallet_balance` from a controller, model or view.

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
