# EcoWealth — Phased Implementation Plan

**Scope of this plan: Shop (add-to-cart, checkout, orders, payments, fulfilment) · Product management · Package management · Registration (standard / registration-code / e-wallet / pending, no binary placement) · Registration codes · Complete e-wallet (dual balance, member transfers with fees and limits, admin top-ups, monitoring) · Payouts · Super-login · Direct Referral · Indirect Referral.**

| | |
|---|---|
| **Version** | 2.2 — backend-first; `index.html` stands in as the public front door, the EcoWealth frontend follows later |
| **Date** | 2026-11-03 |
| **Target application** | `system/` — the new EcoWealth app, served at `http://localhost/ecowealth/system/` |
| **Reference implementation** | `dmp/cart-system/` — complete copy of the Altas Farm MLM + shop system: `index.php` front controller, 3 controllers, 16 models, 51 view templates, 4 cron scripts, public landing page |
| **Business rules** | `dmp/ecowealth3.md` (Compensation Plan V3.0), `dmp/workflow.md` (end-to-end scenarios) |
| **Template source** | `dmp/sneat/` — raw 3.x HTML pages used as UI reference |
| **Route inventory source** | `dmp/cart-system/index.php` — the 98-page `?page=` table with guards, gates and impersonation bootstrap (§2.5) |
| **Landing page** | The repo-root `index.html` serves as the EcoWealth front door for now; `dmp/cart-system/frontend/index.php` (+ `og-image.svg`, `og-image.png`) is its reference for the later frontend rebuild (§3.5, D23) |
| **Document supersedes** | the `plan §…` references inside `dmp/cart-system` comments; ported code should be re-pointed to this document's section numbers |

> **How to use this document.** Work top to bottom. Each phase has a *Gate* — a list of automatable and manual checks. Do not start the next phase until the current gate is green (all tests pass, no regression in earlier phases, demo data seeded, commit tagged). Estimates assume one experienced PHP developer; AI-assisted implementation typically compresses them 30–50%.

> **Install, reset and go-live tooling.** The production `install.sql` bundle, the CLI and browser installers, the guarded database reset and the tool-retirement checklist are specified in the companion plan [`plan-ecowealth-install-reset.md`](plan-ecowealth-install-reset.md) (IR-0 … IR-8). They are not phases of this document.

---

## 1. Executive summary

### 1.1 What we are building

We are porting the **commerce, wallet and membership core** of the reference system (`dmp/cart-system`) onto the **new EcoWealth application** (`system/`), and replacing the reference's binary-MLM compensation engine with the **V3.0 unilevel plan**. The build covers:

1. **Direct Referral Bonus** — paid to a sponsor when a personally-sponsored associate's package purchase is confirmed.
2. **Indirect Referral (Unilevel) Bonus** — paid up to 10 levels up the sponsor chain, per package purchase.
3. **Registration** — standard (free, shopping only), registration-code, e-wallet and pending/referral modes, with sponsor capture. **No binary placement**: no binary upline, no left/right position, no binary tree.
4. **Registration codes** — admin-issued, package-bound, priced, expiring, case-insensitive one-time codes (generate, validate, redeem, export).
5. **Complete e-wallet** — dual balance (`ewallet_balance` + `withdrawable_balance`), immutable ledger, member-to-member transfers with fee and daily/weekly limits, admin top-ups, admin monitor and statements.
6. **Payouts** — withdrawal requests with minimum amount, service fees and USDT rails, through an admin approve → complete/reject queue.
7. **Super-login** — superadmin impersonation of a member through an audited, expiring URL-token session.

Binary pairing, caps, DFI, reactivation, commission-deduct, seat limits and the later V3.0 pillars (QA/retail gate, harvest milestones, residual PV, royalty, partnership) are **out of scope for this build**, but the schema, services and events are designed so they can be added without rewriting what we ship now. The build is **backend-first**: the frontend already exists as the repo-root `index.html`, which fills the role the reference landing page had and is sufficient for now; the fuller EcoWealth frontend is dealt with later and is not one of the phases below.

### 1.2 Why phases

The reference system grew complex: 14 order statuses, payment-proof windows, refunds, shipments, wallet debits, caps and a binary tree. Porting it in one pass would make defects hard to isolate and money bugs hard to audit. Each phase below is a **vertically testable slice**: schema + service + routes + UI + tests, all green before the next layer is added.

### 1.3 Scope — what is in, what is out

| Status | Item |
|---|---|
| **IN (this build)** | The repo-root `index.html` as the public front door (it fills the role the reference landing page had); product catalog CRUD (admin) + storefront listing; package CRUD (admin) + package display; package contents; DB-backed cart (add / update / remove / totals); stock availability and reservation; checkout with address book; order creation with snapshots + idempotency; e-wallet payment; manual payment (GCash / Maya / bank) with proof upload and admin review; order lifecycle up to completion; shipment tracking fields; printable invoice; member order history; admin order desk; **registration (standard / code / e-wallet / pending) with sponsor but no binary placement**; **registration codes (generate, validate, redeem, expire, export)**; **package activation**; **complete e-wallet** (dual balance, ledger, statements, admin top-ups, admin monitor); **member-to-member transfers with fee, minimum, daily/weekly limits and reset cron**; **payouts (request, approve, complete, reject, service fees, USDT rails)**; **super-login (superadmin impersonation with URL token, expiry and audit)**; **direct + indirect referral commissions**; member earnings page; admin commission ledger; settings. |
| **LATER (designed for, not built)** | EcoWealth frontend rebuild (`index.html` → a full marketing site wired into the app, using `frontend/index.php` as the model); QA / retail-customer gate; harvest milestone ladder; residual PV unilevel; royalty ranks; partnership bonus; package upgrades; refunds and credit notes; multi-attempt reshipment / RTS; stockist fulfilment fees; email/SMS notifications; guest checkout; product categories; multi-currency; API; seat limits. |
| **DROPPED (do not port)** | Binary tree (`binary_parent_id`, left/right legs, pair counters, pairing bonus); binary upline/position at registration and activation; lifetime cap engine; daily fixed income; reactivation; commission-deduct (CD); `product_pv`/`pv_value` inert columns from the reference. |

### 1.4 Success criteria

1. A visitor can register with a sponsor username, activate a package, and see the sponsor's direct bonus credited to the sponsor's wallet — **exactly once**.
2. A member can add products to a cart, adjust quantities against live stock, check out, pay by e-wallet or upload a proof, and track the order through delivery.
3. An admin can create/edit/deactivate products and packages (including the 2–10 level referral matrix), verify payments, and move orders through fulfilment.
4. Every peso is traceable: `ewallet_balance` and `withdrawable_balance` always equal the ledger sums; every commission row points at its source activation and wallet credit.
5. A member can transfer wallet funds to another member (fee + daily/weekly limits enforced), request a payout, and see the request move through approve → complete without ever double-spending the held amount.
6. An admin can issue registration codes for a package, a recruit can redeem one and land active in one step, and a superadmin can impersonate a member through an audited, expiring session.
7. Every wallet movement (commission, top-up, transfer, payout, purchase) is one immutable ledger row; `ewallet_balance` and `withdrawable_balance` always reconcile.
8. All phase tests pass, and the end-to-end UAT script (§8.5) runs clean.

---

## 2. Source system analysis — `dmp/cart-system`

### 2.1 What the reference is

Plain PHP 8 + MySQL, organised as `controllers/ models/ core/ views/ config/ cron/`, Bootstrap 5 admin and member portals, plus a standalone public landing page in `frontend/`. Every request enters through `index.php`, which dispatches a `?page=` value through a **98-page route table** to `[Controller, method, guard]`; that table is the system's complete page inventory. It implements a full binary-MLM business: packages with pairing bonuses, registration codes, e-wallet, payouts, caps, DFI, reactivation, a shopping module with its own order state machine, and a heavily commented security history.

Key facts to carry into the port:

- `install.sql` (764 lines) is the authoritative schema; it is self-contained and never hard-codes a database name.
- **Front controller** (`index.php`, 428 lines) is in the copy and is the authoritative route inventory:
  - 98 routes — `AuthController` 12, `MemberController` 39, `AdminController` 47; guards `guest` 2 · `any` 9 · `member` 40 · `admin` 45 · `super` 2.
  - Every controller method the table names exists in the copy (verified), so each page's behaviour can be read end-to-end before porting.
  - The guard runs before dispatch; `any` means **unguarded at the router** and is checked inside the controller. `ewallet_transfer` and `do_ewallet_transfer` are `any` — the port must guard them as `member`.
  - `update_usdt_gas` and `update_usdt_bep20_gas` call `AdminController` methods but are guarded `member`, because the reference's payout page pushes a live gas fee into the global setting: any signed-in member can rewrite it. The port drops those routes and keeps rates and gas fees admin-only (§5.9).
  - A denied guard redirects to `/?page=login` with an "Access denied" flash — never a 403 page; the port deliberately differs (§2.3).
  - Two gates run before routing: **maintenance mode** (503 + `Retry-After`, admin and superadmin bypass) and the **seat limit** (403 "Registration Closed" for `register`, `do_register`, `validate_code`). Both are inline HTML `die()` blocks inside the router.
  - The S-Login nonce is read from `?imp=` (64 hex characters) and installed with `session_name()` + `session_id()` **before** `session_start()`; `Auth::validateImpSession()` runs immediately after and destroys the tab on expiry, IP/UA change or a non-member target.
  - `spl_autoload_register` covers `models/` and `controllers/`; helpers, `Auth`, `Commission`, `CapEngine`, `DailyFixedIncome`, `Reactivation` load eagerly; the CSRF token is seeded once per request.
  - Unknown pages fall back to `login`, or to `admin` / `dashboard` for signed-in users. Ajax endpoints (`validate_code`, `check_username`, `check_upline`, `auto_select_upline`, `get_packages`) are ordinary `?page=` routes, not a separate API.
- **Public landing page** (`frontend/index.php`, 7 092 lines) is in the copy: a self-contained marketing page — inline CSS, inline SVG hero scenes, six script blocks, no external images — with an Organization / WebSite / WebPage / FAQPage JSON-LD graph, `og-image.svg` + `og-image.png`, and CTAs to `?page=login` / `?page=register`. It loads only `config/db.php` and `core/helpers.php` (for `APP_URL` and `e()`) and never renders inside the Bootstrap layout. **It is not ported now:** the repo-root `index.html` fills the same role until the EcoWealth frontend is scheduled (§3.5, D23).
- Security fixes are documented inline (cart-item IDOR, checkout idempotency, stock reservation, admin self-review guard). **Keep these behaviours** — they are the value of the reference.
- Views call controller methods through `?page=` routes and use `link_to()`, `csrf_field()`, `fmt_money()`, `render_flash()`.
- Static assets live outside the app under `assets/`; the landing page's artwork is inline.
- `db-export.sql` (2.5 MB) is production data — **never import**; use `install.sql` + our seeds.
- The copy is complete except for `tests/`, `uploads/`, and the two landing-page images the maintenance page references (`frontend/hero-bg.jpg`, `frontend/logo.png`). Where a file is missing, the plan reconstructs behaviour from models and controllers.

### 2.2 Feature inventory and disposition

| Reference module | Reference files | Disposition |
|---|---|---|
| Front controller / routing | `index.php` — 98-route `?page=` table, guards, maintenance + seat-limit gates | **Port as explicit routes** (Phase 1, §2.5) |
| Public landing page | `frontend/index.php`, `frontend/og-image.{svg,png}` | **Defer** — the repo-root `index.html` stands in for now (D23) |
| Products CRUD + image upload | `models/Product.php`, `AdminController::shopProducts/saveShopProduct/productStatus/deleteShopProduct`, `views/admin/shop_products.php` | **Port** (Phase 2) |
| Storefront catalog | `views/member/shop.php`, `MemberController::shop` | **Port** (Phase 2) |
| Packages + indirect levels | `models/Package.php`, `AdminController::packages/savePackage`, `views/admin/packages.php`, `package_indirect_levels` | **Port & simplify** (Phase 3) |
| Cart | `models/Cart.php`, `MemberController::cart/addToCart/updateCartItem/removeCartItem`, `views/member/cart.php`, `views/partials/cart_offcanvas.php` | **Port** (Phase 4) |
| Checkout / order creation | `MemberController::checkout/placeOrder`, `ShopOrder::createFromCart` | **Port & simplify** (Phase 6) |
| Order state machine | `ShopOrder` (1 341 lines, 14 statuses) | **Port in stages** (Phases 6–8) |
| Payment proofs | `ShopPaymentProof`, `submitProof/rejectProof/markPaid` | **Port** (Phase 7) |
| Shipments | `ShopShipment`, `ship/markDelivered` | **Partial** (Phase 8) |
| Invoices | `ShopInvoice`, `views/shop/invoice.php` | **Port** (Phase 8) |
| Refunds / credit notes | `ShopRefund`, `views/shop/refund_receipt.php` | **Defer** |
| E-wallet core | `models/Ewallet.php` (`credit`, `debit`, `debitInternal`, `balance`, `ledger`) | **Port fully** (Phase 5) |
| E-wallet transfers + limits | `Ewallet::transfer`, `cron/fund_transfer_limit_reset.php`, `views/member/ewallet_transfer.php` | **Port fully** (Phase 12) |
| Admin top-ups + monitor | `Ewallet::adminTopUp`, `AdminController::ewalletTopUp/ewalletMonitor`, `views/admin/ewallet_*.php` | **Port fully** (Phases 5 + 12) |
| Payouts | `models/Payout.php`, `MemberController::payout/requestPayout`, `AdminController::payouts/payoutAction`, `views/member/payout.php`, `views/admin/payouts.php` | **Port fully** (Phase 13) |
| Registration codes | `models/Code.php`, `AdminController::codes/generateCodes/exportCodes`, `views/admin/codes.php`, `reg_codes` | **Port fully** (Phase 9) |
| Registration (all modes) | `AuthController::showRegister/doRegister`, `User::register`, `views/auth/register.php` | **Port, minus binary** (Phase 9) |
| Super-login | `Auth::startImpSession/validateImpSession`, `AuthController::showSlogin/doSlogin`, `models/ImpLog.php`, `impersonation_log` | **Port fully** (Phase 14) |
| Direct referral | `Commission::processDirectReferral` | **Port & re-spec** (Phase 10) |
| Indirect referral | `Commission::processIndirectReferral` | **Port & re-spec** (Phase 11) |
| Binary pairing | `Commission::processBinaryPlacement`, `users.left/right_*`, binary registration fields | **Drop** |
| Caps / DFI / CD / reactivation / seat limits | `CapEngine`, `DailyFixedIncome`, `CdStatus`, `Reactivation`, `seatsRemaining()` | **Drop for now** (schema hooks only where cheap) |
| Settings | `settings` table + `setting()` | **Port** (Phase 1) |
| Cron jobs | `cron/shop_expiry.php`, `cron/fund_transfer_limit_reset.php`, `midnight_reset.php` | **Port shop expiry (Phase 7) + transfer-limit reset (Phase 12)**; midnight reset is binary-only |

### 2.3 What we deliberately change in the port

| Decision | Reference behaviour | New behaviour | Reason |
|---|---|---|---|
| Network shape | Binary tree + sponsor chain | **Sponsor chain only** (unilevel) | V3.0 plan |
| Package fields | pairing bonus, daily cap, cap multiplier, DFI, reactivation | price, product value, direct bonus, levels 2–10, contents, status | Matches V3.0 pillars 1–2 and 4 |
| Level 1 commission | Direct bonus **and** indirect level-1 both paid | **Direct only** at level 1; indirect starts at level 2 | V3.0 table lists L1 = ₱500 "same as Direct Referral" — it is the same money, not additional |
| Order statuses | 14 | 10 now (`pending, payment_review, payment_failed, paid, packing, ready_to_ship, shipped, delivered, completed, cancelled`); extension documented | Keep the build shippable; matrix is extensible |
| Wallet shape | `ewallet_balance` + `withdrawable_balance` | **Same dual bucket**: commissions credit both (withdrawable), purchases/transfers spend non-withdrawable first | Reference semantics keep earnings withdrawable while wallet-funded spending works |
| Payout timing | Balance debited only when the admin marks the payout **completed** | **Debit (hold) at request**, refund on reject, settle on complete | Prevents spending money already promised to a payout |
| USDT rates | Browser fetches CoinGecko/TronGrid; the member submits the rate | **Server-side rate + gas fee from settings**, updated by an admin | No external API dependency and no client-controlled rate |
| Super-login transport | URL-token session (no cookie), IP/UA bound, 8 h TTL, hashed nonce in audit log | **Keep the design** | Proven reference approach; never disturbs the superadmin's own session |
| Registration codes | 12-character grouped codes from an unambiguous charset | **Keep the format**, add an atomic single-use update | Prevents double-redemption under concurrency |
| Registration | Sponsor + package + binary upline/position + placement mode | **Sponsor + package only**; modes: standard, code, e-wallet, pending | Binary is not implemented |
| Product PV | inert columns | `pv` column reserved, never read | No residual income yet |
| URL generation | hard-coded `APP_URL` | `base_url()` + `url()` (already in `app/helpers.php`) | Works in sub-folder (`/ecowealth/system/`) and vhost |
| Front controller | `?page=` table, 98 pages, guards plus inline HTML gates | Explicit route table seeded from the reference inventory (§3.3, §2.5) | Clean URLs, one guard layer, no HTML emitted by the router |
| Guard denial | Redirect to `/?page=login` with an "Access denied" flash | 403 page rendered in the layout | Stable status codes, no redirect loops, easier to test |
| Unguarded routes | `any` = no check (register, wallet transfer) | Every route declares a guard: wallet transfer is `member`, login is `guest`, logout is `member` | Defence in depth |
| USDT-rate routes | `update_usdt_gas` / `update_usdt_bep20_gas` call `AdminController` under the `member` guard | Routes removed; USDT rate and gas fees are admin-only settings | No member-writable money configuration |
| Maintenance / seat gates | Inline `die()` HTML inside the router; the seat limit closes registration | Maintenance mode is a setting handled before routing; seat limit **dropped** | Router stays thin; seat limits are out of scope |
| Public landing page | Standalone `frontend/index.php` linking `?page=login` and `?page=register` | Keep the repo-root `index.html` as the front door; the app's `/` redirects to login/dashboard; the EcoWealth rebuild comes later (§3.5, D23) | Backend first — one front door, no landing page to maintain inside the app yet |
| Tests | `tests/run.php` referenced, not provided | Our own harness (§8) | Every phase must be verifiable |

### 2.4 Current state of the target app (`system/`)

| Area | Status |
|---|---|
| Auth | Working: login (email or username), register, logout, remember-me, idle timeout, CSRF, session hardening |
| Layout | EcoWealth vertical template (`app/layout/app_header.php`, `app_footer.php`, auth layout) |
| Dashboard | 832-line member "growth overview" with live community stats |
| Routing | `system/.htaccess` → `public/`; `public/.htaccess` maps clean names; `public/router.php` for `php -S` |
| Public front door | `index.html` at the repo root is the EcoWealth landing page for now — it fills the role the reference `frontend/index.php` had; the app itself (`system/public/index.php`) redirects to dashboard or login |
| DB | PDO, but `app/config.php` points at **InfinityFree credentials** — must move to local MySQL in Phase 0 |
| Roles | `users.role` is `enum('user','admin')`; no admin UI exists yet |
| Tests | None |

### 2.5 Route inventory and dispositions

The provided front controller turns the reference into a checkable page list. Every one of the **98 pages** is either mapped to a target route or explicitly dropped/deferred:

| Reference page(s) | Count | Target | Disposition |
|---|---:|---|---|
| `login`, `do_login`, `logout` | 3 | `login.php`, `logout.php` (kept standalone) | Keep as-is |
| `register`, `do_register`, `validate_code`, `check_username`, `check_upline`, `auto_select_upline`, `get_packages` | 7 | `/register` plus JSON endpoints `/register/validate-code`, `/register/check-username`, `/register/check-sponsor`, `/register/packages` | Port; binary-upline ajax dropped (Phase 9) |
| `slogin`, `do_slogin` | 2 | `/slogin` | Port (Phase 14) |
| `dashboard`, `profile`, `save_profile` | 3 | `/dashboard`, `/profile` | Port (Phases 6, 9) |
| `shop`, `cart`, `add_to_cart`, `update_cart_item`, `remove_cart_item`, `checkout`, `place_order`, `shop_orders`, `shop_order`, `shop_invoice`, `shop_submit_proof`, `shop_cancel_order`, `shop_confirm_receipt`, `shop_address_save`, `shop_address_delete`, `shop_address_default` | 16 | `/shop`, `/cart…`, `/checkout`, `/orders…`, `/profile/address…` (Appendix A) | Port (Phases 2–8) |
| `activate`, `do_activate` | 2 | `/membership/activate` | Port (Phase 9) |
| `earnings` | 1 | `/earnings` | Port (Phase 10) |
| `genealogy` | 1 | `/genealogy` | Port (Phase 9) — sponsor chain only |
| `ewallet_transfer`, `do_ewallet_transfer` | 2 | `/wallet/transfer` | Port (Phase 12); guard becomes `member` |
| `payout`, `request_payout` | 2 | `/payouts…` | Port (Phase 13) |
| `update_usdt_gas`, `update_usdt_bep20_gas` | 2 | — | **Drop** — USDT rate and gas fees move to admin settings |
| `cap_status`, `dfi_history`, `reactivate`, `do_reactivate`, `api_cap_status`, `api_dfi_status` | 6 | — | **Drop** — caps, DFI, reactivation |
| `upgrade`, `do_upgrade`, `api_validate_upgrade_code` | 3 | — | **Defer** — package upgrades are LATER |
| `api_binary_tree`, `api_binary_uplines` | 2 | — | **Drop** — binary tree |
| `api_cd_status` | 1 | — | **Drop** — commission-deduct |
| `admin` | 1 | `/admin` | Port (Phase 1) |
| `admin_users`, `admin_user_view`, `admin_toggle_user`, `admin_deactivate_user` | 4 | `/admin/members…` | Port (Phase 9) |
| `admin_packages`, `admin_save_package` | 2 | `/admin/packages…` | Port & simplify (Phase 3) |
| `admin_codes`, `admin_gen_codes`, `admin_export_codes` | 3 | `/admin/codes…` | Port (Phase 9) |
| `admin_payouts`, `admin_payout_action` | 2 | `/admin/payouts…` | Port (Phase 13) |
| `admin_settings`, `admin_save_settings`, `admin_change_password` | 3 | `/admin/settings`, `/profile/password` | Port (settings keys Phases 1/7, full page Phase 15) |
| `admin_manual_reset` | 1 | — | **Drop** — midnight reset is binary-only |
| `admin_cap_monitor`, `admin_dfi`, `admin_reactivations`, `admin_reactivation_action`, `admin_toggle_vip`, `admin_toggle_daily_cap` | 6 | — | **Drop** — caps, DFI, reactivation, VIP bypass |
| `admin_assign_cd`, `admin_complete_cd`, `admin_cancel_cd`, `admin_edit_cd_target` | 4 | — | **Drop** — commission-deduct |
| `admin_ewallet_topup`, `do_admin_ewallet_topup`, `admin_ewallet_monitor` | 3 | `/admin/wallets…` | Port (Phases 5, 12) |
| `admin_shop`, `admin_product_status`, `admin_save_product`, `admin_delete_product`, `admin_shop_orders`, `admin_shop_order`, `admin_order_transition`, `admin_order_hold`, `admin_order_resume`, `admin_order_proof`, `admin_order_shipment`, `admin_order_invoice` | 12 | `/admin/products…`, `/admin/orders…`, `/admin/proofs/{id}` (Appendix A) | Port (Phases 2, 6–8); `hold` / `resume` added to Appendix A |
| `admin_order_reship`, `admin_order_refund`, `admin_order_refund_receipt`, `admin_shop_expire` | 4 | `bin/shop_expire.php` (CLI only) | **Defer** returns/refunds; ship-expiry becomes the Phase 7 cron |
| **Total** | **98** | | |

- **Orphaned views:** `views/admin/repeat_purchases.php` and `views/member/repeat_purchases.php` have no route and no controller reference; leave them out unless repeat purchases are scoped in.
- **Unrouted addition:** `views/auth/register_closed.php` is a second seat-limit page (used by `AuthController::showRegister`) on top of the router's inline block; both are dropped with the seat limit.
- The landing page (`frontend/index.php`) sits outside this table and is **not ported now**: the repo-root `index.html` serves that role until the EcoWealth frontend is scheduled (§3.5, D23).

---

## 3. Target architecture

### 3.1 Stack and environment

| Layer | Choice |
|---|---|
| Runtime | PHP 8.1+ (Laragon ships 8.3.16), MySQL 8, Apache 2.4 + `mod_rewrite` |
| App style | Plain PHP, no framework, no Composer for the app (matches current `system/`) |
| Persistence | PDO, prepared statements, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false` |
| UI | EcoWealth theme (Bootstrap 5-based) already under `public/assets`; DataTables, SweetAlert2, Dropzone, Flatpickr, Select2 available |
| Routing | Front controller `public/index.php` + route table `app/routes.php` |
| Views | Plain PHP templates in `app/views/`, rendered inside `app/layout/app_header.php` / `app_footer.php` |
| Money | `DECIMAL(12,2)` (14,2 for balances), PHP `number_format`, helper `money()` |
| Time | `Asia/Manila` (already in `config.php`) |

### 3.2 Directory layout (target)

```
system/
├── app/
│   ├── bootstrap.php           # config → helpers → database → auth → autoload
│   ├── config.php              # constants (DB, app, flags)
│   ├── config.local.php        # optional local overrides, git-ignored
│   ├── database.php            # db() singleton (exists)
│   ├── auth.php                # session/auth (exists) + require_admin(), member helpers
│   ├── helpers.php             # url(), e(), csrf, flash, money(), status badges, pagination
│   ├── routes.php              # NEW — route table
│   ├── Core/                   # NEW — Router, Request, View, Validator, Paginator, Upload
│   ├── Models/                 # NEW — Product, Package, Cart, Order, WalletLedger, Commission…
│   ├── Services/               # NEW — CartService, OrderService, PaymentService, WalletService,
│   │                           #        CommissionService, ActivationService, InvoiceService
│   ├── Controllers/            # NEW — Shop, Cart, Checkout, Order, Membership, Earnings,
│   │                           #        Profile + Admin* controllers
│   ├── views/                  # NEW — shop/, member/, admin/, errors/, partials/
│   └── layout/                 # exists — header/footer/auth layouts (refactored for nav config)
├── public/
│   ├── index.php               # front controller (replaces the current redirect shim)
│   ├── login.php register.php logout.php   # kept as-is this build
│   ├── assets/                 # EcoWealth theme assets (exists)
│   ├── uploads/                # NEW — products/, packages/, proofs/ (writable, no PHP execution)
│   └── .htaccess               # explicit auth routes + catch-all → index.php
├── database/
│   ├── schema.sql              # exists — extended in Phase 1
│   ├── migrations/             # NEW — 001_…sql per phase
│   └── seeds/                  # NEW — demo.sql (products, packages, settings, sample tree)
├── tests/                      # NEW — bootstrap.php, run.php, Cases/, fixtures
└── .htaccess                   # exists — routes /system/ → public/
```

### 3.3 Request lifecycle and routing

**Entry.** `system/.htaccess` already sends every `/ecowealth/system/*` request into `public/`. `public/.htaccess` keeps explicit rules for auth pages and adds a catch-all:

```apache
RewriteEngine On
RewriteRule ^login/?$    login.php    [L,QSA]
RewriteRule ^register/?$ register.php [L,QSA]
RewriteRule ^logout/?$   logout.php   [L,QSA]

# Everything that is not a real file/dir → front controller
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
```

**Before routing.** If `maintenance_mode` is on and the caller is not an admin/superadmin, respond `503` with a standalone branded page (`Retry-After: 3600`). The reference `die()`s this HTML from inside the router; the port moves it into a small `MaintenanceGate` helper called at the top of the front controller, before any route work.

**Route inventory parity.** `app/routes.php` is seeded from the reference table (§2.5): each of the 98 pages is either mapped or marked dropped/deferred there. The parity test (P1-T12) reads that inventory and fails when a page marked *Port* has no matching route.

**Front controller sequence** (`public/index.php`):

1. `define('APP_BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/')` — **critical**: fixes `base_url()` for deep URLs such as `/shop/12` (otherwise assets resolve against `/shop/`). `helpers.php` already honours a pre-defined `APP_BASE_URL`.
2. `require app/bootstrap.php` (config → helpers → db → auth → autoload).
3. Resolve `path` = request URI minus `APP_BASE_URL`, minus query string; normalise trailing slash.
4. Match against `app/routes.php`:
   ```php
   return [
     ['GET',  '/shop',        [ShopController::class,     'index'],   'member'],
     ['GET',  '/orders/{id}', [OrderController::class,    'show'],    'member'],
     ['POST', '/cart/add',    [CartController::class,     'add'],     'member'],
     ['GET',  '/admin/products', [AdminProductController::class, 'index'], 'admin'],
     // …
   ];
   ```
   `{id}` compiles to `([0-9]+)`; parameters are passed as an associative array.
5. Enforce guard: `guest` · `member` · `admin` · `superadmin` · `any` (§3.4).
6. For `POST`: verify CSRF centrally (return 419 JSON for XHR, flash + redirect back for forms).
7. Dispatch; controllers may return a rendered view or a `json_response()`.
8. Catch `Throwable` → log, render `errors/500.php` (production) or a debug page (`APP_ENV=development`).
9. No match → `errors/404.php` with HTTP 404, inside the EcoWealth layout.

**Dev server parity.** Update `public/router.php` so `php -S 127.0.0.1:8080 -t public public/router.php` serves the same routes (delegate everything non-static to `index.php`).

**Rules:** routes are the only place URLs are declared; views must use `url('shop')`, never hard-coded paths.

### 3.4 Auth, roles and guards

- Migrate `users.role` → `enum('member','admin','superadmin')` (3-step migration: add `member`/`superadmin`, update `user` rows to `member`, drop `user`). Existing accounts become `member`; seed one `admin` and one `superadmin`.
- New columns on `users` (detailed in §4 and Appendix B): `sponsor_id`, `package_id`, `member_status`, `referral_code`, `phone`, `activated_at`, `ewallet_balance`, `withdrawable_balance`, `ewallet_sent_today`, `ewallet_sent_this_week`, `gcash_number`, `maya_number`, `usdt_trc20_address`, `usdt_bep20_address`, `reg_payment_method`.
- Guards:
  - `member` — logged in, `is_active = 1`, role member or admin (admin may use the member portal; the reference allows it and guards self-review separately).
  - `admin` — logged in plus `role IN ('admin','superadmin')`; otherwise render `errors/403.php` (HTTP 403), never a redirect loop.
  - `superadmin` — `role = 'superadmin'`; gates code generation limits, payout overrides and the S-Login console.
  - `guest` — only for login/register (existing behaviour); signed-in callers are redirected to their dashboard.
  - `any` — public by design: the app's `/` redirect, the register form, the register validation endpoints and logout. Unlike the reference, `any` never covers a money route.
- `auth_user()` continues to load the current row per request; add `auth_member()` returning the full member row (package, wallet, sponsor) and `is_admin()` / `is_superadmin()`.
- **Superadmin acting-as:** finance attribution (`actingAdminId()`, `actingUser()`) resolves to the primary admin account (first `role='admin'`), so a superadmin's own wallet is never debited and records point at the admin — reference behaviour, keep it.
- **Impersonation:** the super-login session replaces the cookie session with a URL-token session (`?imp=<nonce>`); the member layout shows a banner with an "exit" action, and every link/form must carry the token. Requests with an invalid, expired, IP-mismatched or non-member token are destroyed and bounce to `/slogin` (Phase 14).
- Admin menu is rendered only for admins; admin URLs are additionally guarded server-side (never by menu visibility alone).
- Login/registration continue to use the existing pages; Phase 9 replaces the registration page with the full four-mode flow and adds the S-Login page in Phase 14.

### 3.5 UI system and theme mapping

Two app layouts, plus a front door that lives outside the app:

| Surface | Menu | Used by |
|---|---|---|
| Front door (outside the app) | the repo-root `index.html` page and its own nav | `/ecowealth/` — visitors land here first; its only app-facing piece is a link into `/ecowealth/system/` |
| Member (`layout=member`) | Dashboard, Shop, Cart, My Orders, Membership, Earnings, Wallet, Payouts, Profile | member pages (plus an impersonation banner during S-Login) |
| Admin (`layout=admin`) | Dashboard, Products, Packages, Orders, Activations, Codes, Members, Commissions, E-Wallet Monitor, Payouts, Settings, S-Login console | `/admin/*`, `/slogin` |

Both app layouts are the existing EcoWealth vertical theme; the sidebar becomes data-driven from `app/layout/nav.php` (sections → items → route, icon, badge), with `$current_nav` derived from the active route. The current dashboard's anchor-based menu is replaced by this config in Phase 1. The impersonation banner in the member layout is added in Phase 14.

**Public front door.** The guest-facing landing page is the repo-root `index.html`, which stands in for the role `frontend/index.php` had in the reference: it is the marketing page visitors see before they enter the app, while the app itself stays login-first (`GET /` in the app redirects to dashboard or login). Decision D23 records this. The reference landing page — inline CSS, inline SVG hero scenes, Organization / WebSite / WebPage / FAQPage JSON-LD, OG images at 1200×630, CTAs to login/register — is kept as the model for the **EcoWealth frontend rebuild, which is out of scope for this build**: the backend is built first and the frontend is dealt with later. While it is deferred, `index.html` only needs one thing to do its job — a visible link into `/ecowealth/system/` (login/register). It does not carry that link yet, so add one when the app is ready to show; no other frontend work belongs to this build.

Component sources (copy structure from `dmp/sneat/`):

| Need | Theme page |
|---|---|
| Product list table | `app-ecommerce-product-list.html` (`.datatables-products`, DataTables) |
| Product add/edit form | `app-ecommerce-product-add.html` (Dropzone upload, form rows) |
| Order list | `app-ecommerce-order-list.html` |
| Order detail | `app-ecommerce-order-details.html` |
| Member list | `app-user-list.html` |
| Invoice | `app-invoice-preview.html`, `app-invoice-print.html` |
| Package cards (public) | `pages-pricing.html` |
| Forms / validation | `form-validation.html`, `forms-basic-inputs.html` |
| Alerts / toasts | `ui-alerts.html`, `ui-toasts.html`, `extended-ui-sweetalert2.html` |
| Tabs / timeline | `ui-tabs-pills.html`, `extended-ui-timeline-basic.html` |

### 3.6 Conventions

| Topic | Rule |
|---|---|
| PHP style | `declare(strict_types=1)`, 4 spaces, braces on new line, return types |
| Classes | Global (no namespaces) for now, autoloaded from `app/Models`, `app/Services`, `app/Controllers`, `app/Core` by class name |
| DB | `snake_case`; tables singular or domain-prefixed (`shop_orders`); every table has `created_at`/`updated_at` unless immutable |
| Money | Never `float` in SQL; PHP casts to `float` only for display; all arithmetic on `DECIMAL` strings cast to float at one boundary or via integer cents if needed |
| Views | Escape everything with `e()`; no SQL in views; no business rules in views |
| Forms | POST + CSRF; redirect-after-post; flash + old input on validation errors |
| AJAX | `X-Requested-With: XMLHttpRequest` → `json_response(['ok'=>bool, …], code)`; forms must still work without JS |
| Errors | User-facing text is friendly; technical detail only to `error_log` |
| Dates | Stored UTC+8 (`SET time_zone`) and displayed `Asia/Manila` |
| Money format | `money(1234.5)` → `₱1,234.50`; `money(…, true)` → `+₱…` |
| Order number | `EW` + `yymmdd` + 5-digit id (e.g. `EW26110100042`), generated after insert and unique by id |
| Pagination | 10 rows default, `?page=N` plus a rows-per-page control (5, 10, 15 … 100 in steps of five, `?per_page=N`), server-side for every table, DataTables not used |

### 3.7 Security baseline (applies to every phase)

- CSRF on **all** POST (central check in the router, per-form token).
- Ownership scoping on every member query: `WHERE user_id = ?` / `WHERE id = ? AND user_id = ?`; cart item mutations always scoped to the caller's cart (reference defect C3).
- Admin guard server-side; never trust hidden fields.
- Idempotency keys on order placement and activation (unique index + replay returns the original).
- Stock checked **inside** the order transaction (`SELECT … FOR UPDATE`), not only in the UI.
- Uploads: MIME sniff (`finfo`), extension whitelist, 5 MB cap, random filename, stored under `public/uploads/<area>/`, directory `.htaccess` disables PHP execution.
- Output escaping; `htmlspecialchars` via `e()`.
- Session: existing hardening (HttpOnly, SameSite=Lax, regeneration on login, idle timeout).
- Secrets: `config.local.php` git-ignored; no credentials in the repo.
- Error display off in production; `error_log` retains detail.
- Rate-limit login (exists), checkout/proof submissions, transfers and payout requests (session or IP throttle).
- **Money mutations**: every wallet change is a `WalletService` call inside a transaction with `SELECT … FOR UPDATE`; no direct `UPDATE users SET ewallet_balance` from controllers or views.
- **Transfers**: require the member's password re-entry, block self-transfers, enforce min/fee/daily/weekly limits server-side, credit the fee to the primary admin only.
- **Payouts**: amount checked against `withdrawable_balance` under lock; one pending request per member; the amount is held at request time and refunded on rejection.
- **Super-login**: superadmin only; target must be an active member; the raw nonce is never stored (SHA-256 only); IP + user-agent bound; 8-hour TTL; every session logged with an exit/expiry status; a banner marks the session on every page.
- **Registration codes**: single-use enforced by a conditional `UPDATE … WHERE status='unused'` (rowCount must be 1); expired codes are flipped to `expired` on validation.

### 3.8 Uploads and media

```
public/uploads/
├── products/    # product images
├── packages/    # package images
└── proofs/      # payment proofs (private-ish: random names, not linked publicly)
```

- Served statically for products/packages; proof files are streamed through a guarded admin/member route (`/admin/proofs/{id}`) rather than a public URL.
- `.htaccess` in `uploads/`: `php_flag engine off` (or `RemoveHandler`) + deny `.php`.

---

## 4. Data model

### 4.1 Entity groups

```
Auth          users (extended), remember_tokens, impersonation_log
Catalog       products, packages, package_levels, package_products
Commerce      carts, cart_items, addresses,
              shop_orders, shop_order_items, shop_order_events,
              shop_payment_proofs, shop_shipments,
              shop_invoices, shop_invoice_items
Money         ewallet_ledger, ewallet_transfers, ewallet_admin_topups,
              payout_requests, users.ewallet_balance, users.withdrawable_balance,
              users.ewallet_sent_today, users.ewallet_sent_this_week
Membership    users.sponsor_id/package_id/member_status/referral_code/reg_payment_method,
              activations, reg_codes
Compensation  commissions
System        settings, audit_log
```

### 4.2 Table catalogue

| Table | Purpose | Phase |
|---|---|---|
| `settings` | key/value configuration | 1 |
| `products` | sellable catalog items | 2 |
| `packages` | associate packages | 3 |
| `package_levels` | indirect referral bonus per level (2–10) per package | 3 |
| `package_products` | package contents (product + qty) | 3 |
| `carts` / `cart_items` | persistent cart | 4 |
| `ewallet_ledger` | immutable wallet movements (all money in/out) | 5 |
| `ewallet_admin_topups` | admin-created wallet credits, auditable | 5 |
| `addresses` | member delivery/billing address book | 6 |
| `shop_orders` / `shop_order_items` | orders + line snapshots + reservation state | 6 |
| `shop_order_events` | status audit trail | 6 |
| `shop_payment_proofs` | manual payment evidence | 7 |
| `shop_shipments` | courier/tracking | 8 |
| `shop_invoices` / `shop_invoice_items` | immutable invoice snapshots | 8 |
| `reg_codes` | registration codes (package-bound, priced, expiring) | 9 |
| `activations` | package purchase / activation requests | 9 |
| `commissions` | direct & indirect awards | 10–11 |
| `ewallet_transfers` | member-to-member transfers + fee + note | 12 |
| `payout_requests` | withdrawal requests and processing trail | 13 |
| `impersonation_log` | super-login sessions (hashed nonce, IP/UA, status) | 14 |
| `audit_log` | admin actions, settings changes | 15 |

Full DDL: **Appendix B**.

### 4.3 Key relationships and constraints

- `users.sponsor_id → users.id` (`ON DELETE SET NULL`); self-reference prevented in the service layer; a member's sponsor is immutable after registration (admin correction is an audited operation).
- `users.package_id → packages.id` (`ON DELETE SET NULL`); packages referenced by members cannot be deleted.
- `cart_items` unique on `(cart_id, product_id)`; `carts` has at most one `active` row per user (enforced in `CartService`, re-checked with a row lock).
- `shop_order_items.reservation_state ∈ reserved, committed, deducted, released` — the reservation contract used for available-stock maths.
- `commissions` unique on `(type, source_user_id, user_id, level)` — the idempotency anchor for referral payouts.
- `shop_orders.idempotency_key` unique — a double-submitted checkout resolves to the same order.
- Money columns `DECIMAL(12,2)`; balances `DECIMAL(14,2)`; never negative (enforced by `WalletService`).
- `users.ewallet_balance ≥ users.withdrawable_balance ≥ 0` always; a transfer, commission or payout must never break the two-bucket invariant.
- `ewallet_ledger` rows are immutable and always carry `balance_after`; `ref_type` is a fixed enum mirrored by a PHP constant (reference defects C2/C7 — one list, one migration).
- `reg_codes.code` unique; single-use is enforced by `UPDATE … WHERE status='unused'` plus a row-count check, not by read-then-write.
- At most one `pending` or `approved` payout per member (service check + index strategy); the amount is held at request time.
- `ewallet_transfers` writes one sender debit, one recipient credit and one admin fee credit in the same transaction.
- `impersonation_log.nonce_hash` unique; status `active | expired | logged_out`; raw nonces are never persisted.

### 4.4 Status machines

**Member** (`users.member_status`)

```
pending ──(first package approved)──▶ active
active  ──(admin suspend)──────────▶ suspended
active  ──(future: lapse)──────────▶ inactive
suspended/inactive ──(admin restore)──▶ active
```

**Activation** (`activations.status`)

```
pending_payment ──(wallet debit)──────────────▶ approved
pending_payment ──(proof uploaded)──▶ pending_review ──(admin approve)──▶ approved
pending_review  ──(admin reject)────▶ rejected
pending_*       ──(expire / cancel)─▶ cancelled
```

**Order** (`shop_orders.status`) — current scope:

```
pending ──(proof upload)──▶ payment_review ──(admin verify)──▶ paid ──▶ packing ──▶ ready_to_ship ──▶ shipped ──▶ delivered ──▶ completed
pending ──(e-wallet pay)──────────────────────────────────────▶ paid
payment_review ──(admin reject)──▶ payment_failed ──(re-upload)──▶ payment_review
pending / payment_failed ──(member cancel | expiry)──▶ cancelled
paid / packing / ready_to_ship ──(admin cancel)──▶ cancelled (stock released)
```

| Transition | Actor | Side effects |
|---|---|---|
| `pending → paid` | system (wallet) | debit wallet, commit reservation (`paid_at`); stock is decremented at ship per §5.1 |
| `payment_review → paid` | admin | verify proof, commit stock, set `paid_at` |
| `payment_review → payment_failed` | admin | release reserved stock, correction window opens |
| `* → cancelled` | member (pre-paid) / admin | release stock, optional refund note |
| `paid → … → delivered` | admin | events logged, tracking recorded |
| `delivered → completed` | member / cron | completion timestamp |

`shop_order_events` records every transition with `from`, `to`, `actor_type`, `actor_id`, `note`.

**Payout** (`payout_requests.status`)

```
pending ──(admin approve)──▶ approved ──(admin complete)──▶ completed
pending ──(admin reject)───▶ rejected      (held amount refunded)
approved ──(admin reject)──▶ rejected      (held amount refunded)
```

**Registration code** (`reg_codes.status`)

```
unused ──(redeemed at registration or activation)──▶ used
unused ──(expiry date passed / validation)─────────▶ expired
```

**Super-login** (`impersonation_log.status`)

```
active ──(exit / TTL expiry / IP-UA mismatch / member suspended)──▶ logged_out | expired
```

**Wallet movement** (`ewallet_ledger.type` + `ref_type`)

| ref_type | Direction | Withdrawable effect |
|---|---|---|
| `commission` | credit | adds to both buckets |
| `shop_order`, `registration`, `activation` | debit (internal) | spends non-withdrawable first |
| `transfer` | debit (sender) / credit (recipient) / credit (admin fee) | sender spends non-withdrawable first; recipient and fee are non-withdrawable |
| `topup` | credit | non-withdrawable |
| `payout` | debit (hold at request) | reduces both; refund on reject |
| `refund`, `adjustment` | either | admin-controlled, audited |

### 4.5 Seed data

| Seed | Contents |
|---|---|
| Settings | `site_name=EcoWealth`, currency `PHP`, `shop_enabled=1`, payment accounts (placeholders), `shop_payment_deadline_hours=24`, `shop_correction_window_hours=24`, `shop_max_proof_attempts=3`, `shop_autocomplete_days=7`, `commission_direct_enabled=1`, `commission_indirect_enabled=1`, `commission_indirect_max_levels=10`, `commission_requires_active_sponsor=1`, `free_registration_enabled=1`, `min_payout=500`, `service_fee_gcash=0`, `service_fee_maya=0`, `service_fee_usdt_trc20=5`, `service_fee_usdt_bep20=5`, `usdt_rate=0` (admin sets), `usdt_trc20_gas_fee=2.50`, `usdt_bep20_gas_fee=0.05`, `ewallet_transfer_fee=0`, `ewallet_min_transfer=50`, `ewallet_transfer_daily_limit=5000`, `ewallet_transfer_weekly_limit=20000`, `payout_gcash_enabled=1`, `payout_maya_enabled=1`, `payout_usdt_trc20_enabled=1`, `payout_usdt_bep20_enabled=1` |
| Packages | **A — ₱4,498** (direct ₱500; L2 ₱100, L3 ₱50, L4 ₱40, L5 ₱30, L6 ₱20, L7 ₱10, L8 ₱5, L9 ₱5, L10 ₱5; product value ₱4,750) · **B — ₱6,998** (same ladder; product value ₱8,300) |
| Products | Coffee ₱300, Immune Booster ₱500, Spirulina ₱400, Serpentina ₱400, Kojic Soap ₱100, Stone Buster ₱600 — **placeholder prices**, to be replaced by the official price list |
| Package contents | A: 1 Coffee, 1 Immune Booster, 2 Spirulina, 1 Serpentina, 2 Kojic Soap · B: 1 Coffee, 1 Immune Booster, 1 Spirulina, 1 Stone Buster, 1 Colonics session (seeded as a service product whose stock is tracked manually) |
| Demo tree | `admin` (company/root account, no package) → `juan` (root associate, Package A, active) → `maria` → `peter`, `sarah`; plus `david` under maria — used by tests and UAT |
| Demo codes | One unused Package A code and one unused Package B code, printed in the seed log; one expired code for negative tests |
| Wallets | `juan`, `maria`, `peter` seeded with a mixed balance (e.g. ₱2,000 total / ₱1,500 withdrawable) to exercise transfers and payouts |

> Prices come from `dmp/workflow.md` examples; they are illustrative. Confirm the official price list before Phase 2 seeds are frozen.

### 4.6 Migration strategy

- **Fresh install** uses the extended `database/schema.sql` (regenerated in Phase 1, kept current each phase).
- **Existing databases** apply `database/migrations/NNN_*.sql` in order; each phase ships exactly one migration (plus optional seed).
- Before every phase gate: `mysqldump` backup named `backup_phase-N.sql` (never committed).
- No destructive migrations without a `down` note in the phase log.

---

## 5. Commerce flows

### 5.1 Catalog and stock model

- `products.stock` is the **physical** count on hand.
- `reserved` = sum of quantities in order items with `reservation_state IN ('reserved','committed')` belonging to non-terminal orders.
- `available = max(0, stock − reserved)` — the only number shown to members and the only number the cart enforces.
- Reservation lifecycle: **reserve** when an order is created; **commit** when it is paid; **deduct** (decrement `products.stock`) when it ships; **release** on cancellation or payment failure.
- Deactivating a product hides it from the shop and blocks new cart adds; it never edits historical orders.

### 5.2 Cart

| Rule | Detail |
|---|---|
| One cart | At most one `active` cart per member, created on first add |
| Add | Product must exist + `active`; qty ≥ 1; resulting qty ≤ available; unit price snapshot refreshed from catalog on every read |
| Update | Qty 0 removes the line; qty capped by available; line must belong to the caller's cart |
| Remove | Scoped by `(item_id, cart_id)` |
| Totals | Computed from current catalog price × qty, never from client input |
| Empty | Checkout and cart summary show an empty state; checkout is blocked |
| Conversion | On order placement the cart becomes `converted`; a new cart is created on the next add |
| Offcanvas | Rendered server-side, refreshed via AJAX; badge count from the same service |

### 5.3 Checkout and order creation

1. Member must be logged in and have a non-empty, in-stock cart.
2. Choose a saved address or enter a new one (recipient, phone, address line, barangay, city, province, postal; billing name/phone; notes).
3. Choose payment: **e-wallet** (shown only when the balance covers the total) or **manual** (GCash / Maya / bank — configured in settings).
4. Accept terms (checkbox, versioned `terms_version`, timestamp).
5. Submit with a hidden idempotency key (`uniqid` per render or a server-side session token).
6. Server: transaction → re-price from catalog → re-check stock `FOR UPDATE` → insert order + snapshot line items + reserve → convert cart → (if e-wallet) debit wallet → mark paid → commit. Any failure rolls back **everything**.
7. Order number is generated from the inserted id; the event log records `order_placed`.
8. Redirect to the order page with a success flash.

### 5.4 Payments

- **E-wallet** — atomic debit inside the order transaction (internal debit: non-withdrawable first); insufficient balance aborts the order. Every movement writes an `ewallet_ledger` row with `balance_after`.
- **Manual** — the member uploads a proof (image/PDF, ≤ 5 MB) with amount and reference; the order moves to `payment_review`. An admin verifies (→ `paid`) or rejects with a reason (→ `payment_failed`, a correction window reopens, attempts capped).
- **Deadlines** — `shop_payment_deadline_hours` (default 24) from order creation; a cron/CLI job cancels overdue `pending` orders and releases stock. Rejection grants `shop_correction_window_hours`.
- **No gateways** in this scope; the settings page stores the receiving GCash/Maya/bank details shown at checkout.

### 5.5 Fulfilment

| Action | Who | Effect |
|---|---|---|
| Pack | admin | `paid → packing` |
| Ready to ship | admin | `packing → ready_to_ship` |
| Ship | admin | courier + tracking required; `ready_to_ship → shipped`; stock deducted |
| Deliver | admin | `shipped → delivered`, POD note optional |
| Confirm receipt | member | `delivered → completed` |
| Auto-complete | cron | after `shop_autocomplete_days` (default 7) |
| Cancel | member (pending/failed) / admin (pre-ship) | release stock; refund note if paid |

### 5.6 Cancellation, returns, refunds

- Implement **cancellation** (stock release, wallet refund when paid by wallet — Phase 7/8).
- **Refunds/returns** (partial, RTS, reshipment) are deferred; the schema keeps `notes_admin` and the event log so a later phase can add `shop_refunds` without changing orders.

### 5.7 Invoices

- Generated once when the order first reaches `paid`; immutable snapshot (seller identity from settings, buyer, address, line items, totals, payment method, order no).
- Numbering `INV-yymmdd-00042`; voided only if payment is reverted (documented later).
- Printable page (`/orders/{id}/invoice`) with print CSS, no app chrome.

### 5.8 E-wallet transfers, limits and monitoring

- **Two buckets.** `ewallet_balance` is the total spendable amount; `withdrawable_balance` is the subset that may be paid out. Commissions credit both; purchases, transfers, registrations and activations spend **non-withdrawable first**, so earnings stay withdrawable while wallet-funded spending still works.
- **Member transfer form.** Recipient username, amount, note, and the member's **password re-entered** to confirm. Recipient must exist, be active, and not be the sender.
- **Validation.** Amount > 0; ≥ `ewallet_min_transfer`; sender balance ≥ amount + fee; fee = `ewallet_transfer_fee` for members (zero for staff); `sent_today + amount ≤ daily_limit`; `sent_this_week + amount ≤ weekly_limit`.
- **Atomicity.** One transaction: lock sender → debit sender (non-withdrawable first) → credit recipient (non-withdrawable) → credit the fee to the primary admin (non-withdrawable) → insert `ewallet_transfers` → three `ewallet_ledger` rows (sender debit, recipient credit, admin fee).
- **Limit tracking.** `users.ewallet_sent_today` and `users.ewallet_sent_this_week` increment per successful transfer; the reset cron zeroes daily every midnight and weekly on Monday. The transfer page shows usage against both limits.
- **Admin monitor.** Totals (float, withdrawable vs locked), all transfers, all top-ups, and a per-member ledger search; a member's wallet statement is paginated and filterable by type/date.
- **Staff behaviour.** Admin/superadmin transfers are free and exempt from limits; a superadmin's transfer is attributed to the primary admin account (reference `actingAdminId()`).

### 5.9 Payouts

- **Eligibility.** Amount ≥ `min_payout`; amount ≤ `withdrawable_balance`; no other `pending`/`approved` request; the member's payout account is prefilled from the profile (GCash, Maya, USDT TRC20/BEP20 address).
- **Fees and conversion.** `service_fee_<method>` percent is deducted to produce the net amount. USDT methods convert at `usdt_rate` from settings and subtract `usdt_*_gas_fee`; the resulting USDT amount is stored on the request.
- **Funds are held at request time** (debit `payout` in the ledger), so the member cannot spend money already promised to a payout. Rejection refunds the amount; completion only marks the request processed (money was already sent off-platform).
- **Admin queue.** Tabs for pending / approved / completed / rejected; actions approve, reject (reason required), complete (note); `processed_by`/`processed_at` recorded; pending and paid totals shown.
- **Member view.** Withdrawable balance, one open request at a time, history with status and net/USDT amounts.
- **Settings.** `min_payout`, per-method service fees, channel toggles, USDT rate and gas fees.

### 5.10 Registration codes and registration modes

**Codes**

- **Format.** Three groups of four characters from an unambiguous 32-character set (e.g. `ABCD-EFGH-JKLM`), compared case-insensitively (stored uppercase). Unique by index.
- **Issuance (admin).** Package, quantity (1–500), price, expiry date, type = `registration` (upgrade type reserved), creator recorded; CSV export; stats (total / unused / used / expired / revenue from used codes).
- **Presentation.** Codes are sold for cash by the company/sponsor offline; the code row is the auditable record of price and issuance.
- **Validation.** Exists → unused → not expired (expired rows are flipped to `expired`); package must be active.
- **Redemption.** At registration or at activation of a pending member. The package comes from the code; redemption is a conditional update (`WHERE status='unused'`) whose row count must be 1; the member becomes active and commissions fire.
- **States.** `unused → used | expired`.

**Registration modes** (all without binary fields)

| Mode | Who | Payment | Result |
|---|---|---|---|
| Standard | Guest or logged-in registrar | none (free) | Active, no package, shop-only access; no commissions |
| Registration code | Guest or logged-in registrar | one code | Active with the code's package; commissions fire immediately |
| E-wallet | **Logged-in** registrar only (sponsor/admin pays) | wallet debit, credited to admin revenue | Active with the chosen package; commissions fire immediately |
| Pending / referral | Guest via referral link, free registration enabled | none at signup | `pending` account; activation later via code, e-wallet or manual proof |

- Common validation: username format/uniqueness, password ≥ 8 + confirmation, sponsor username exists when entered (and is not self), package active, terms accepted.
- Sponsor is captured in every mode; **no** binary upline, binary position or placement mode is collected.
- When a logged-in member registers someone else, the registrar's session is restored after the call; guests are logged in as the new member (reference behaviour).
- The pending member's activation page offers code / e-wallet / manual-proof paths; admin approval of a manual proof completes the same activation service.

---

## 6. Compensation plan (current scope)

### 6.1 Parameters (V3.0 subset)

| Parameter | Value | Source |
|---|---|---|
| Direct referral (Level 1) | ₱500 per package **paid** | `packages.direct_referral_bonus` |
| Indirect Level 2 | ₱100 | `package_levels` |
| Indirect Level 3 | ₱50 | `package_levels` |
| Indirect Level 4 | ₱40 | `package_levels` |
| Indirect Level 5 | ₱30 | `package_levels` |
| Indirect Level 6 | ₱20 | `package_levels` |
| Indirect Level 7 | ₱10 | `package_levels` |
| Indirect Level 8 | ₱5 | `package_levels` |
| Indirect Level 9 | ₱5 | `package_levels` |
| Indirect Level 10 | ₱5 | `package_levels` |
| Max levels | 10 | `settings.commission_indirect_max_levels` |
| QA gate | OFF for now | `settings.commission_indirect_requires_qa` (future) |

Both packages pay the **same** amounts — the package price difference buys product value, not compensation (V3.0).

### 6.2 Trigger event

The **only** trigger in this build is a **completed package activation** — one service, `ActivationService::complete()`, called from every path that turns a member into a paying associate:

| Path | When commissions fire |
|---|---|
| Registration with a **registration code** | Immediately, inside the registration transaction |
| Registration paid by **e-wallet** | Immediately, inside the registration transaction |
| **Pending** member activating later (code / e-wallet / manual proof approved by admin) | When the activation is approved |
| Standard (free) registration | Never — no package, no award |

Product orders do **not** generate commissions yet (residual/PV is a later pillar).

Order of operations, inside one DB transaction that also sets the member active:

1. Lock the activation row; bail if already processed (`commission_processed_at` not null).
2. Set `users.package_id`, `member_status = 'active'`, `activated_at`.
3. Award **direct** to `sponsor_id` (level 1).
4. Walk the sponsor chain and award **indirect** levels 2..10.
5. Mark the activation processed; commit.

Registration-time activations create a synthetic `activations` row (`payment_method` = `code` or `ewallet`, status `approved`) so every commission is traceable to one activation record — the same anchor the later pending flow uses.

### 6.3 Direct referral rules

- Recipient: the new member's `sponsor_id`.
- Amount: source package's `direct_referral_bonus` (≥ 0; a zero row simply awards nothing).
- Eligibility: recipient is a member, `is_active = 1`, `member_status = 'active'`, has a package. If not → **skip and log** (`commissions.status = 'skipped'`, reason), no wallet movement.
- Never paid to self (registration forbids self-sponsorship).
- Exactly once per activation (unique key).

### 6.4 Indirect referral rules

- Walk **up** the sponsor chain starting at `sponsor_id.sponsor_id` (level 2), using `sponsor_id` only — never the binary tree (which does not exist here).
- For level *L* (2…10), read the **source package's** `package_levels.amount` for *L*; if > 0 and the recipient is eligible → award + credit wallet.
- **No compression:** an ineligible upline consumes its level (matches the reference's behaviour and keeps the ladder predictable). Config flag `commission_compression` reserved for later.
- Stop at 10 hops, or when the chain ends, or when a cycle is detected (defensive `visited` set).
- If the source package has `indirect_enabled = 0` → no indirect awards at all.
- Each award is a separate `commissions` row with `level = 2…10`.

### 6.5 Ledger and idempotency

- One `commissions` row per (activation × recipient × level); unique key `(type, source_user_id, user_id, level)` guarantees no duplicate payouts even if the activation is re-approved or a job retries.
- Each credited commission is a **`WalletService::credit()` call with `withdrawable = true`** (`ref_type='commission'`, `ref_id=commission.id`) inside the same transaction — the recipient can withdraw it.
- Wallet updates always take a row lock (`SELECT … FOR UPDATE`) and write `balance_after`.
- Skipped awards are recorded for audit but never touch money.

### 6.6 Worked examples

**Example A — single chain.** Sponsor chain `A ← B ← C ← D ← E` (A is the top). E activates Package A:

| Recipient | Relationship to E | Level | Amount |
|---|---|---|---|
| D | direct sponsor | 1 | ₱500 |
| C | sponsor's sponsor | 2 | ₱100 |
| B | 3 up | 3 | ₱50 |
| A | 4 up | 4 | ₱40 |
| **Total** | | | **₱690** |

**Example B — the workflow's Juan.** Juan sponsors Maria. Maria sponsors Peter, Sarah, David. Peter sponsors Linda and Miguel.

| Event | Recipient | Award |
|---|---|---|
| Maria activates (A) | Juan (L1 direct) | ₱500 |
| Peter activates (A) | Juan (L2) | ₱100 |
| Sarah activates (B) | Juan (L2) | ₱100 |
| David activates (B) | Juan (L2) | ₱100 |
| Linda activates (A) | Juan (L3) | ₱50 |
| Miguel activates (A) | Juan (L3) | ₱50 |

Totals for the scenario: **Juan ₱900 · Maria ₱1,500** (directs Peter/Sarah/David) **· Peter ₱1,000** (directs Linda/Miguel).

**Example C — inactive upline (no compression).** Chain `A ← B(inactive) ← C ← D`. D activates:

| Level | Recipient | Result |
|---|---|---|
| 1 | C | ₱500 |
| 2 | B | skipped (not active) — level consumed |
| 3 | A | ₱50 |

**Example D — package toggle.** Package B with `indirect_enabled = 0`: only the direct ₱500 is paid.

### 6.7 Deferred pillars — how they plug in later

| Pillar | Hook already in place |
|---|---|
| QA / retail gate | `settings.commission_indirect_requires_qa` + eligibility check inside `CommissionService`; retail orders/customers table later |
| Harvest milestones | `activations` are the countable "new packages"; a `milestones` table and a listener on activation |
| Residual PV | `products.pv` reserved; order-paid event already exists for a listener |
| Royalty ranks | monthly aggregation over `commissions`/sales; rank table |
| Partnership bonus | listener on commission credit events |
| Package upgrades | an upgrade is a second `activations` row with `payment_method` and a target package; the same commission service runs |

### 6.8 Reporting and reconciliation invariants

Checked by tests and by an admin "health" widget in Phase 15:

1. `users.ewallet_balance = SUM(credits) − SUM(debits)` over `ewallet_ledger`.
2. `users.withdrawable_balance ≤ users.ewallet_balance` and both ≥ 0.
3. `SUM(commissions.amount WHERE status='credited') = SUM(ewallet_ledger.amount WHERE ref_type='commission')`.
4. `SUM(shop_orders.total) = SUM(shop_order_items.line_total)` per order.
5. `available_stock ≥ 0` for every product.
6. No commission row without a matching activation, and no activation processed twice.
7. Every `completed` payout has a matching `payout` debit in the ledger, and no rejected payout keeps a hold.

---

## 7. Phase plan

### 7.0 Phase map

| Phase | Name | Depends on | Effort (dev-days) |
|---|---|---|---|
| 0 | Setup, baseline & decisions | — | 1 |
| 1 | Core skeleton: router, layouts, roles, settings, test harness | 0 | 5 |
| 2 | Products & storefront catalog | 1 | 4 |
| 3 | Packages & package contents | 1, 2 | 4 |
| 4 | Cart engine & cart UI | 2 | 5 |
| 5 | E-wallet core: dual balance, ledger, top-ups, monitor | 1 | 5 |
| 6 | Checkout & order placement | 4, 5 | 5 |
| 7 | Manual payments & order lifecycle | 6 | 5 |
| 8 | Fulfilment, completion & invoices | 6, 7 | 4 |
| 9 | Registration, registration codes, membership & activation | 3, 5, 7 | 8 |
| 10 | Direct referral commissions | 9 | 4 |
| 11 | Indirect referral commissions | 10 | 5 |
| 12 | E-wallet transfers, limits & monitoring | 5, 9 | 4 |
| 13 | Payouts (withdrawable balance, fees, USDT) | 5, 10 | 5 |
| 14 | Super-login (superadmin impersonation) | 1, 9 | 3 |
| 15 | Hardening, dashboards, UAT & launch | all | 5 |

**Dependency graph**

```
0 → 1
1 → 2, 5, 14
2 → 3, 4
3 → 9
4 → 6
5 → 6, 9, 12, 13
6 → 7, 8
7 → 8, 9
9 → 10, 14
10 → 11, 13
11, 12, 13, 14, 8 → 15
```

Phases 2 and 5 can run in parallel after 1; 3 starts once 2's product seeds exist; 9 needs 3 + 5 + 7; 12 and 14 run independently once 5/9 land; 13 starts once 10 lands (it needs withdrawable commissions).

---

### Phase 0 — Setup, baseline and decisions

**Goal.** A reproducible environment, a safety net, and no ambiguity about scope before code changes.

**Depends on.** Nothing.

**Effort.** 1 dev-day.

**Tasks**

1. Create git branch `feature/shop` from `main`; tag `phase-0-start`.
2. Back up the current app and database (`mysqldump`) before any migration.
3. Create the local database (recommended `ecowealth_sneat`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`) and a dedicated MySQL user.
4. Move credentials out of the repo: `app/config.local.php` (git-ignored) overrides `config.php`.
5. Add `.gitignore` entries: `config.local.php`, `public/uploads/*` (keep `.gitkeep`), `*.log`, `backup_*.sql`, `tests/tmp/`.
6. Confirm the current app still works end-to-end at `http://localhost/ecowealth/system/` (login, register, dashboard, logout).
7. Resolve the **open decisions** in §12 that block Phase 1–3 (branding, shop visibility, sponsor requirement, price list).
8. Create `docs/phase-log.md` (running log: decisions, migrations, test results, backups).
9. Read the completed reference (`index.php`, `frontend/index.php`) and reconcile §2.5: confirm each of the 98 pages is mapped or dropped, and log any page that needs its own decision.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P0-T1 | `php -v`, `mysql --version` | PHP ≥ 8.1; MySQL 8 |
| P0-T2 | Test DB connection script | Connects to the new local DB |
| P0-T3 | Baseline auth flow | Register → login → dashboard → logout all work |
| P0-T4 | `git status` after branch + backup | Clean; backup file exists outside the repo |
| P0-T5 | Decisions log populated | Every blocking decision has a recorded choice |

**Gate.** Environment green, baseline screenshots captured, decisions signed off, phase log started.

---

### Phase 1 — Core skeleton: router, layouts, roles, settings, test harness

**Goal.** A foundation every later phase plugs into: front controller, route table, member/admin layouts with real navigation, role guards, settings storage, error pages, and an automated test harness.

**Depends on.** Phase 0.

**Effort.** 5 dev-days.

**Reference.** `index.php` (98-route table, guard dispatch, maintenance gate, impersonation bootstrap), `core/helpers.php` (flash/CSRF/redirect/pagination/upload), `controllers/*` structure, `views/partials/{head,sidebar_*,topbar}.php`. Deferred: `frontend/index.php` (landing page) is not ported in this build — the repo-root `index.html` covers that role (D23).

**Deliverables**

- **DB:** `settings` (+ seeds); `audit_log` may be created now or in Phase 15.
- **Code:**
  - `app/routes.php` — initial table seeded from the §2.5 inventory (`/` redirect, `/shop` placeholder, `/admin` placeholder), with a commented name for every dropped or deferred page so the gap is visible in code.
  - `app/Core/Router.php`, `Request.php`, `View.php`, `Validator.php`, `Paginator.php`, `Upload.php`, `MaintenanceGate.php`.
  - `app/Controllers/HomeController.php` (redirects `/` to dashboard or login), `AdminDashboardController.php` (placeholder).
  - `app/views/errors/{403,404,500}.php`, `app/views/partials/{flash,pagination,empty_state}.php`.
  - `app/layout/nav.php` (menu config), refactored `app_header.php`/`app_footer.php`.
  - `public/index.php` — front controller (maintenance gate first, then routing); `public/router.php` — dev-server parity; `public/.htaccess` — catch-all.
  - `app/config.php` reworked to `defined(...) || define(...)` so `config.local.php` and the test harness can override constants; `app/config.local.php` is git-ignored.
  - `app/helpers.php` additions: `money()`, `setting()`, `status_badge()`, `old()/errors` helpers, `json_response()`.
  - `tests/bootstrap.php`, `tests/run.php`, `tests/Cases/SkeletonTest.php`.
- **Roles:** migrate `users.role` to `enum('member','admin')`; add `require_admin()`; 403 page.
- **Seed:** settings defaults; keep `admin` account.
- **Not yet:** `public/dashboard.php` stays a standalone page this phase; it is re-routed in Phase 9 when the member dashboard gains wallet/commission data. The public front door stays the repo-root `index.html` — no landing page is built in this phase (D23).

**Rules**

- `APP_BASE_URL` is defined by the front controller before bootstrap; `url()` must work from `/admin/products/9/edit`.
- Guard enforcement happens in the router, not inside views.
- CSRF is checked centrally for POST before dispatch.
- Views render inside the correct layout; the menu shows only permitted items.
- The app's `/` is a redirect only; the guest-facing landing page lives outside the app (`index.html`), so no landing-page view ships this phase.
- No business logic in `index.php` beyond dispatch.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P1-T1 | `GET /shop` as guest | 302 → `/login`, warning flash |
| P1-T2 | `GET /admin` as member | 403 page in EcoWealth layout |
| P1-T3 | `GET /admin` as admin | 200 placeholder page, admin menu visible |
| P1-T4 | `GET /no-such-page` | 404 page, correct status code |
| P1-T5 | Asset URLs from `/admin/products/9/edit` | Resolve to `/ecowealth/system/assets/...` (no 404s) |
| P1-T6 | `POST /logout` with a wrong CSRF token | Rejected; session stays signed in |
| P1-T7 | `tests/run.php` | Runs, passes, exits 0; a failing assertion exits non-zero |
| P1-T8 | Menu on member vs admin | Member never sees admin items |
| P1-T9 | Baseline auth still works | Login/register/logout unaffected |
| P1-T10 | `GET /` in the app as guest and as member | Redirects to `/login` when signed out and `/dashboard` when signed in; no landing page renders inside the app |
| P1-T11 | `maintenance_mode=1` as guest vs admin | Guest gets the branded 503 page with `Retry-After`; admin sees the normal page |
| P1-T12 | Route inventory parity (§2.5) | Every page marked *Port* has a route; the test fails on a missing or duplicated page |

**Gate.** All P1 tests pass; `php -l` clean; no 404 asset on any page; tag `phase-1`.

**Risks.** Base-path maths (mitigated by tests from deep URLs); scope creep into auth rewrite (auth pages stay untouched this phase).

---

### Phase 2 — Products and storefront catalog

**Goal.** Admin can manage the product catalog; members can browse it.

**Depends on.** Phase 1.

**Effort.** 4 dev-days.

**Reference.** `models/Product.php`, `AdminController.php:1041-1196`, `views/admin/shop_products.php`, `views/member/shop.php`.

**Deliverables**

- **DB:** `products` (Appendix B).
- **Admin routes:** `GET /admin/products`, `GET /admin/products/new`, `GET /admin/products/{id}/edit`, `POST /admin/products/save`, `POST /admin/products/{id}/status`, `POST /admin/products/{id}/delete`.
- **Member routes:** `GET /shop` (catalog), `GET /shop/{id}` (detail; modal fallback).
- **Services/models:** `Product` model; `UploadService::image()`; `StockService::available()`/`availableMap()`.
- **Views:** `admin/products/index.php` (DataTable, filters: status, search), `admin/products/form.php` (theme form + Dropzone), `shop/catalog.php` (card grid), `shop/product.php`, partial `product_card.php`.
- **Seed:** 6 demo products with images placeholders.

**Rules**

- Validation: name 2–160, price > 0, stock ≥ 0, SKU optional-unique, image JPG/PNG/WebP ≤ 5 MB, description ≤ 5 000 chars.
- Status toggle hides from shop; delete is refused when the product is referenced by order items or carts, with a message naming the reference (reference behaviour).
- Catalog shows only `active` products with `available > 0` badge logic (`In stock`, `N left` ≤ 5, `Sold out`).
- Prices are display-only here; checkout re-reads them (Phase 6).

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P2-T1 | Create product with valid data | Row created, appears in list |
| P2-T2 | Create with price 0 / empty name | Rejected with field errors, input retained |
| P2-T3 | Upload a 6 MB file / `.php` renamed `.jpg` | Rejected with friendly message |
| P2-T4 | Edit product price | Old orders unaffected (no orders yet); shop shows new price |
| P2-T5 | Deactivate product | Disappears from `/shop`; direct URL 404 |
| P2-T6 | Delete unreferenced product | Deleted, image file removed |
| P2-T7 | Delete referenced product | Refused with reason |
| P2-T8 | Catalog search/pagination | Correct filtered results, page links preserve query |
| P2-T9 | XSS attempt in name/description | Escaped output |
| P2-T10 | Member cannot open admin product routes | 403 |

**Gate.** All P2 tests pass; catalog renders correctly on mobile; tag `phase-2`.

---

### Phase 3 — Packages and package contents

**Goal.** Admin can define associate packages (price, direct bonus, 2–10 level matrix, contents); members can view them.

**Depends on.** Phases 1–2 (products for contents).

**Effort.** 4 dev-days.

**Reference.** `models/Package.php`, `AdminController::packages/savePackage`, `views/admin/packages.php` (drop binary fields).

**Deliverables**

- **DB:** `packages`, `package_levels` (levels **2–10**), `package_products`.
- **Admin routes:** `GET /admin/packages`, `GET /admin/packages/new`, `GET /admin/packages/{id}/edit`, `POST /admin/packages/save`, `POST /admin/packages/{id}/status`.
- **Member routes:** `GET /packages` (marketing cards).
- **Model/service:** `Package` (with `levels()`, `contents()`, `save()` transactional), `PackageService`.
- **Views:** `admin/packages/index.php`, `admin/packages/form.php` (fields + 9-row level matrix + contents repeater), `shop/packages.php`.

**Rules**

- Fields: name, slug, price (`entry_fee`) > 0, product value (display), direct bonus ≥ 0, levels 2–10 (numeric ≥ 0), description, perks, image, status.
- Level 1 is displayed as **"Level 1 — Direct Referral (₱X)"**, read-only, bound to `direct_referral_bonus` — no double entry.
- Contents: zero or more products with quantity ≥ 1; only active products; duplicates merged.
- Save is transactional (package + levels + contents).
- Package with members cannot be deleted (status toggle instead).
- Validation refuses a level amount with more than 2 decimals, negative, or > ₱1,000,000.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P3-T1 | Create Package A with full level matrix | Rows in `packages` + 9 rows in `package_levels` |
| P3-T2 | Edit level 3 to ₱50 | Persisted; edit form reopens with values |
| P3-T3 | Invalid price/decimal/negative | Rejected, no partial writes |
| P3-T4 | Add contents (2× Coffee) | `package_products` row; public card lists contents |
| P3-T5 | Direct bonus shown as Level 1 | Read-only; not duplicated in levels table |
| P3-T6 | Deactivate package | Hidden from `/packages`, blocked for activation |
| P3-T7 | Member cannot edit packages | 403 |
| P3-T8 | Public packages page | Prices formatted ₱, bonuses displayed |
| P3-T9 | Transaction rollback on bad contents id | No package row created |

**Gate.** All P3 tests pass; seeded Package A/B match Appendix C; tag `phase-3`.

---

### Phase 4 — Cart engine and cart UI

**Goal.** A complete, stock-aware, DB-backed cart with AJAX and no-JS fallbacks.

**Depends on.** Phase 2.

**Effort.** 5 dev-days.

**Reference.** `models/Cart.php`, `MemberController.php:1295-1404`, `views/member/cart.php`, `views/partials/cart_offcanvas.php`, `assets/js/app.js`.

**Deliverables**

- **DB:** `carts`, `cart_items`.
- **Routes:** `GET /cart`, `POST /cart/add`, `POST /cart/update`, `POST /cart/remove`, `GET /cart/count` (JSON).
- **Services:** `CartService` (getOrCreate, add, updateQty, remove, totals, validateStock, clear, convert), `StockService` (available, availableMap, reserved).
- **Views/partials:** `shop/cart.php`, `partials/cart_offcanvas.php`, cart badge in the navbar, toast helper.
- **JS:** `public/assets/js/app-cart.js` (fetch wrapper with CSRF, optimistic UI, error toasts, badge sync).

**Rules**

- Add: qty default 1, cap at available, refuse inactive/unknown products, refuse qty > available with the exact numbers.
- Update: qty 0 removes; cap at available; item must belong to caller's cart.
- Prices always re-read from `products`; `unit_price` on the line is a snapshot refreshed on view and at checkout.
- `available = stock − reserved` (reserved includes `pending`, `payment_review`, `payment_failed`, `paid`, `packing`, `ready_to_ship`).
- Cart page shows per-line stock and disables checkout when any line is over stock.
- Offcanvas is present on all member pages and updates after every mutation.
- No-JS: forms POST and redirect back with flash.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P4-T1 | Add product to empty cart | Cart created, line added, badge 1 |
| P4-T2 | Add same product again | Quantity increments, no duplicate line |
| P4-T3 | Add beyond available | Refused: "only N available" |
| P4-T4 | Update quantity to 0 | Line removed |
| P4-T5 | Update someone else's item id | Refused (no write) — IDOR |
| P4-T6 | Remove item from other cart | Refused |
| P4-T7 | Price changed in admin after add | Cart shows new price on reload |
| P4-T8 | Two members' carts | Fully isolated |
| P4-T9 | Concurrent add to last unit | Only one succeeds; available never negative |
| P4-T10 | Inactive product add | Refused |
| P4-T11 | AJAX responses | `ok:true` + count/totals; errors 400 with message |
| P4-T12 | No-JS add | Redirect + flash, cart correct |
| P4-T13 | Offcanvas updates after add/remove | Badge and list match server |

**Gate.** All P4 tests pass, including the IDOR and concurrency tests; tag `phase-4`.

---

### Phase 5 — E-wallet core: dual balance, ledger, top-ups, monitor

**Goal.** One auditable money store for the whole system: spendable + withdrawable buckets, an immutable ledger, admin-created credits, and the statements both roles need.

**Depends on.** Phase 1.

**Effort.** 5 dev-days.

**Reference.** `models/Ewallet.php` (`credit`, `debit`, `debitInternal`, `balance`, `ledger`), `ewallet_ledger`, `ewallet_admin_topups`, `AdminController::ewalletTopUp/ewalletMonitor`, `views/admin/ewallet_*.php`.

**Deliverables**

- **DB:** `ewallet_ledger`, `ewallet_admin_topups`; `users.ewallet_balance`, `users.withdrawable_balance`.
- **Service:** `WalletService` with the reference semantics:
  - `credit($userId, $amount, $refType, $refId, $note, bool $withdrawable = true)` — adds to `ewallet_balance`, and to `withdrawable_balance` when withdrawable.
  - `debit($userId, $amount, $refType, $refId, $note, bool $withdrawable = true)` — refuses when the total is insufficient; decrements both buckets when withdrawable.
  - `debitInternal(...)` — spends non-withdrawable first, then withdrawable (purchases, registrations, transfers).
  - `balance()`, `withdrawable()`, `statement($userId, $filters, $page)`, `adminTopUp($adminId, $userId, $amount, $note)`.
  - `refType` validated against a single constant mirrored by the DB enum (`commission`, `payout`, `transfer`, `topup`, `registration`, `shop_order`, `adjustment`, `refund`).
- **Routes:** member `GET /wallet` (statement with filters + running balance); admin `GET /admin/wallets` (monitor: float, withdrawable vs locked, per-member ledger search), `GET /admin/wallets/{id}`, `POST /admin/wallets/{id}/credit` (top-up).
- **Views:** `member/wallet.php`, `admin/wallets/index.php`, `admin/wallets/show.php`.

**Rules**

- Both buckets are never negative; a failed debit writes nothing.
- Every mutation runs in a transaction that locks the user row (`SELECT … FOR UPDATE`) and writes `balance_after`.
- Idempotent by `(ref_type, ref_id, type)` for system-driven movements; admin top-ups are always a new row (audit by design).
- Top-ups credit **non-withdrawable** funds (reference behaviour) so they can be spent in the shop but not cashed out.
- Ledger rows are immutable — no UPDATE/DELETE path exists.
- `users.ewallet_balance ≥ users.withdrawable_balance` is re-checked after every mutation.
- A reconciliation command recomputes balances from the ledger and reports mismatches (used by tests and the Phase 15 health widget).

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P5-T1 | Credit ₱1,000 withdrawable | Balance 1,000; withdrawable 1,000; ledger row |
| P5-T2 | Credit ₱500 non-withdrawable | Balance 1,500; withdrawable 1,000 |
| P5-T3 | Debit internal ₱700 | Non-withdrawable 500 spent first, then 200 withdrawable → 800 / 800 |
| P5-T4 | Debit more than total | Refused; buckets unchanged |
| P5-T5 | Replay same `(ref_type, ref_id, type)` | One row only |
| P5-T6 | Concurrent debits | No negative bucket; sum matches ledger |
| P5-T7 | Admin top-up | Credit (non-withdrawable), top-up row, audit, member sees it |
| P5-T8 | Invalid `ref_type` | Exception, no write (schema-drift guard) |
| P5-T9 | Statement filters/pagination | Totals match the ledger |
| P5-T10 | Immutability | No UPDATE/DELETE path exists |
| P5-T11 | Reconciliation command | Zero mismatches after the suite |

**Gate.** All P5 tests pass; reconciliation clean; tag `phase-5`.

---

### Phase 6 — Checkout and order placement

**Goal.** Turn a cart into a real order with immutable snapshots, stock reservation, and a working payment (e-wallet) — the heart of the add-to-cart system.

**Depends on.** Phases 4, 5.

**Effort.** 5 dev-days.

**Reference.** `MemberController::checkout/placeOrder` (line 1406-1568), `ShopOrder::createFromCart` (687-816), `addresses` schema.

**Deliverables**

- **DB:** `addresses`, `shop_orders`, `shop_order_items`, `shop_order_events`.
- **Routes:** `GET /checkout`, `POST /checkout`, `GET /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel`; address book `GET /profile`, `POST /profile/address`, `POST /profile/address/{id}/delete`; admin `GET /admin/orders`, `GET /admin/orders/{id}`.
- **Services:** `OrderService::placeFromCart()` (transaction, idempotency, pricing, reservation, cart conversion, wallet payment), `OrderNumber`, `AddressService`.
- **Views:** `shop/checkout.php` (address picker + form, payment method, terms), `shop/orders.php`, `shop/order.php` (timeline + items), `admin/orders/index.php`, `admin/orders/show.php`.

**Rules**

- Server-side totals only; client prices ignored.
- Required: billing name, shipping name, complete address, payment method, terms.
- Payment options: e-wallet (only if balance ≥ total) and manual (checkout allowed, order stays `pending` until proof — Phase 7).
- Idempotency: hidden key per checkout render; replay returns the same order, never a duplicate.
- Stock re-check inside the transaction; failure rolls back wallet debit too.
- Order items snapshot `product_name`, `sku`, `unit_price`, `quantity`; `reservation_state='reserved'`.
- Events logged: `order_placed`, `paid`, `cancelled`.
- Member isolation on every read (`AND user_id = ?`).

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P6-T1 | Place order with e-wallet | Order `paid`, wallet debited once, cart converted, stock reserved |
| P6-T2 | Double-submit (same idempotency key) | One order; second returns the same order |
| P6-T3 | Tampered price in POST | Ignored; totals from DB |
| P6-T4 | Stock changed between cart and checkout | Order refused with clear message; no partial writes |
| P6-T5 | Insufficient wallet balance | Refused; balance unchanged; cart intact |
| P6-T6 | Missing address/terms | Field-level errors; input retained |
| P6-T7 | Address book: save during checkout | Address appears for next order |
| P6-T8 | Member A opens member B's order | 404/403, no data leak |
| P6-T9 | Order list filters/pagination | Correct, totals formatted |
| P6-T10 | Admin sees all orders, member only own | Guarded |
| P6-T11 | Cancel before payment | Stock released, status `cancelled` |
| P6-T12 | Event log | One row per transition with actor and timestamp |
| P6-T13 | Order number format | `EW` + date + 5 digits, unique |
| P6-T14 | Concurrent checkout last unit | At most one order created |

**Gate.** All P6 tests pass; ledger and stock invariants hold after the suite; tag `phase-6`.

---

### Phase 7 — Manual payments and order lifecycle

**Goal.** Complete the payment path for GCash/Maya/bank and the pending → review → paid/failed loop with deadlines and correction windows.

**Depends on.** Phase 6.

**Effort.** 5 dev-days.

**Reference.** `ShopOrder::submitProof/rejectProof/markPaid`, `AdminController::orderTransition/orderProofView`, `shop_payment_proofs`, `cron/shop_expiry.php`.

**Deliverables**

- **DB:** `shop_payment_proofs`; order columns `payment_deadline`, `payment_reference`, `correction_deadline`; settings: accounts, deadlines, attempts.
- **Routes:** `POST /orders/{id}/proof`, `GET /admin/proofs/{id}` (guarded stream), admin `POST /admin/orders/{id}/transition` (verify / reject / cancel), admin `GET /admin/settings` + `POST /admin/settings` (payment/deadline keys used so far).
- **Service:** `PaymentService` (deadline math, attempt cap, proof validation, verify/reject, stock release, expiry), `Cron/ExpireOrders` CLI (`bin/shop_expire.php`).
- **Views/partials:** proof upload panel on the order page, status timeline, admin order actions with SweetAlert2 confirmations, admin proof review pane, admin settings page (payment accounts + deadlines; expanded in Phase 15).

**Rules**

- One proof per attempt; max `shop_max_proof_attempts` (3).
- Accepted files: JPG/PNG/PDF ≤ 5 MB; stored with random names; served only through a guarded route.
- Verify sets `paid`, commits reservation, issues invoice (Phase 8), timestamps `paid_at`.
- Reject sets `payment_failed`, releases stock, opens `correction_deadline`; re-upload re-reserves.
- Expiry job cancels `pending` orders past deadline and releases stock; idempotent, safe to run repeatedly.
- Admin cannot review an order they placed themselves (reference guard) — log and refuse.
- Every action is an `shop_order_events` row with reason code and note.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P7-T1 | Upload valid proof | `payment_review`; admin sees it |
| P7-T2 | Wrong MIME / oversized file | Rejected, order unchanged |
| P7-T3 | Fourth attempt | Refused with attempt cap message |
| P7-T4 | Admin verifies | `paid`, stock committed, invoice issued, event logged |
| P7-T5 | Admin rejects with reason | `payment_failed`, stock released, window shown |
| P7-T6 | Re-upload after reject | Back to `payment_review`, stock re-reserved |
| P7-T7 | Deadline passes | Cron cancels, stock released, idempotent on second run |
| P7-T8 | Upload past deadline | Refused with clear copy |
| P7-T9 | Proof access by another member | 403/404 |
| P7-T10 | Admin reviews own order | Refused |
| P7-T11 | Timeline | Ordered events with correct labels |
| P7-T12 | Manual cancel after paid (admin) | Cancelled, wallet refund if wallet-paid, stock released |

**Gate.** All P7 tests pass; cron run twice changes nothing the second time; tag `phase-7`.

---

### Phase 8 — Fulfilment, completion and invoices

**Goal.** Ship, deliver and close orders; give members a printable invoice.

**Depends on.** Phases 6, 7.

**Effort.** 4 dev-days.

**Reference.** `ShopOrder::startPacking/finishPacking/ship/markDelivered/confirmReceipt`, `ShopShipment`, `ShopInvoice`, `views/shop/invoice.php`.

**Deliverables**

- **DB:** `shop_shipments`, `shop_invoices`, `shop_invoice_items`.
- **Routes:** admin `POST /admin/orders/{id}/ship`, `POST /admin/orders/{id}/deliver`; member `POST /orders/{id}/confirm`, `GET /orders/{id}/invoice`.
- **Services:** `FulfilmentService`, `InvoiceService::issueForPaidOrder()` (idempotent snapshot).
- **Views:** admin shipping panel, member timeline, `shop/invoice.php` (print CSS).

**Rules**

- Ship requires courier + tracking; deducts stock at that point and writes a `shop_shipments` row.
- Delivered → `delivered`; member confirm → `completed`; optional cron auto-complete after N days.
- Invoice is created once per paid order; immutable; voided only on payment reversal (future).
- Invoice numbering `INV-yymmdd-#####`; seller identity from settings.
- Printing hides nav/chrome and fits A4.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P8-T1 | Pack → ready → ship | Statuses sequential; tracking saved |
| P8-T2 | Ship without tracking | Refused |
| P8-T3 | Stock deduction on ship | `products.stock` reduced once; available correct |
| P8-T4 | Deliver + member confirm | `completed`, timestamps set |
| P8-T5 | Invoice on paid order | One immutable snapshot with correct totals |
| P8-T6 | Invoice reprint | Same number/content |
| P8-T7 | Member invoice access control | Only own invoices |
| P8-T8 | Cancel after ship | Refused (needs RTS flow later) |
| P8-T9 | Auto-complete cron (if enabled) | Idempotent |
| P8-T10 | Print layout | A4, no chrome, totals aligned |

**Gate.** All P8 tests pass; a complete order can be walked from cart to completed in UAT; tag `phase-8`.

---

### Phase 9 — Registration, registration codes, membership and activation

**Goal.** Every member joins through a real registration flow (standard / code / e-wallet / pending), gets a sponsor and a referral code, and becomes an active associate the moment their package is paid — without any binary placement.

**Depends on.** Phases 3, 5, 7.

**Effort.** 8 dev-days.

**Reference.** `AuthController::showRegister/doRegister`, `User::register/activate`, `models/Code.php`, `AdminController::codes/generateCodes/exportCodes`, `views/admin/codes.php`, `views/auth/register.php`, `views/member/activate.php`.

**Deliverables**

- **DB:** `users` new columns (`sponsor_id`, `package_id`, `member_status`, `referral_code`, `phone`, `activated_at`, `reg_payment_method`, `reg_code_id`, `reg_paid_by`); `reg_codes`; `activations`. (Payout account fields — GCash/Maya/USDT — arrive in Phase 13.)
- **Routes:**
  - Public/auth: `/register` (rewritten: modes, sponsor, referral link `?ref=<code>` and `?sponsor=<username>`), its live-validation endpoints (`/register/validate-code`, `/register/check-username`, `/register/check-sponsor`, `/register/packages`), `/membership`, `POST /membership/activate`.
  - Admin: `/admin/codes` (list + filters + stats + CSV export), `POST /admin/codes/generate`, `/admin/activations` + approve/reject, `/admin/members`, `/admin/members/{id}`.
  - Member: `/genealogy` (upline chain + direct downline).
- **Services:**
  - `RegistrationService` — validates sponsor/username/password/package/code; creates the user; handles the four modes; restores the registrar session when a logged-in member registers someone.
  - `CodeService` — generate (transactional, unique), validate, `redeem()` (conditional update, row count 1), expire sweep, stats, CSV export.
  - `ActivationService` — `request()` (code / wallet / manual proof), `approve()` (atomic: active + package + commission hook), `reject()`; synthetic approved activation for registration-time payment.
  - `SponsorService` — resolve by username or referral code, self/cycle checks, upline path, downline listing.
- **Views:** rewritten `auth/register.php` (mode selector, sponsor field, package cards, code field, e-wallet option for logged-in registrars), `member/membership.php`, `member/genealogy.php`, `admin/codes/index.php`, `admin/activations/index.php`, `admin/members/index.php|show.php`.
- **Dashboard:** replace `public/dashboard.php` with a routed `/dashboard` page showing package status, wallet (both buckets), referral link, recent orders and commission totals.
- **Commission hook:** `ActivationService::approve()` calls `CommissionService::onActivation()` through a stable interface. Phases 10–11 implement the awards; in Phase 9 the hook is wired and asserted as "invoked once" (no money moves yet).

**Rules**

- **No binary fields anywhere** — no `binary_parent_id`, no `binary_position`, no upline username, no placement mode. Registration collects a sponsor only.
- **Modes:**
  - *Standard* — free, active, no package (shopping-only). Gated by `free_registration_enabled`.
  - *Code* — the code determines the package; redemption and account creation happen in one transaction; member active immediately.
  - *E-wallet* — only a logged-in registrar (sponsor/admin); debit `debitInternal`, credit the primary admin as revenue, member active immediately.
  - *Pending* — free signup (referral flow), `member_status='pending'`; activation later by code, wallet or approved manual proof.
- Sponsor is captured in every mode and is **optional**: a blank field joins the member under the company root (the primary admin). A typed sponsor can be a username or a referral code; self-sponsorship and cycles are refused.
- Referral code: 8 random alphanumeric characters, unique, generated at registration, used by `?ref=`.
- **Codes:** 12 characters in three groups (`ABCD-EFGH-JKLM`) from an unambiguous set; stored uppercase, compared case-insensitively; unique; admin sets package, quantity (1–500), price, expiry; single-use enforced by `UPDATE … WHERE status='unused'` + row-count check; expired codes flip to `expired` on validation; CSV export and stats.
- **Activation:** one package at a time; only from `pending`/`inactive`; amount from `packages.price`; wallet path debits immediately; manual path stores a proof and waits for admin approval; approval runs the commission hook (Phase 10/11) inside the same transaction.
- Rejection returns the member to `pending` with a reason; double activation and double approval are refused.
- Admin can suspend/restore members (audited); a suspended member cannot log in.
- Genealogy shows the upline chain (max 10) and direct downline — no binary legs.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P9-T1 | Standard registration | Active member, no package, can shop |
| P9-T2 | Register with a valid code | Active with the code's package; code marked used with `used_by`/`used_at` |
| P9-T3 | Register with an invalid/used/expired code | Refused with a specific message; account not created |
| P9-T4 | E-wallet registration by a logged-in sponsor | Payer debited once, admin credited, member active, registrar session restored |
| P9-T5 | Guest tries e-wallet registration | Redirected to login, no write |
| P9-T6 | Pending registration via `?ref=CODE` | Pending, sponsor prefilled/resolved, referral code created |
| P9-T7 | Unknown sponsor / self-sponsor | Field errors; no account |
| P9-T8 | Duplicate username/email | Existing validation still works |
| P9-T9 | Activation by wallet | Debit once, `active`, package set; the commission hook is invoked once (awards verified in P10–P11) |
| P9-T10 | Activation by manual proof | `pending_payment`; admin queue shows it; approval activates |
| P9-T11 | Admin rejects activation | Back to `pending`, reason shown, no debit |
| P9-T12 | Double activation / double approval | Refused; no second package, hook invoked once |
| P9-T13 | Code double-redemption race (two parallel registrations) | Exactly one succeeds; the other is refused |
| P9-T14 | Admin generates 10 codes, expires one | List/stats/CSV reflect statuses |
| P9-T15 | Codes page access control | Non-admin 403 |
| P9-T16 | Member detail page | Sponsor, package, wallet buckets, commission totals correct |
| P9-T17 | Genealogy up/down | Matches fixtures; no cycle; no binary columns referenced |
| P9-T18 | `php -l` + schema check | No `binary_*` column referenced anywhere in the new code |

**Gate.** All P9 tests pass; a fresh member can be walked from each registration mode to active; tag `phase-9`.

---

### Phase 10 — Direct referral commissions

**Goal.** Pay the sponsor ₱500 (per package config) when their direct recruit's activation is approved — exactly once, auditable.

**Depends on.** Phase 9.

**Effort.** 4 dev-days.

**Reference.** `Commission::processDirectReferral` (drop CD/cap/binary), `commissions` schema, `views/member/earnings.php`.

**Deliverables**

- **DB:** `commissions` (+ indexes, unique key).
- **Service:** `CommissionService::onActivation($activationId)` → direct leg; `award()` helper (insert, credit wallet, ledger, link); `skip()` (audit).
- **Routes:** `GET /earnings` (member), `GET /admin/commissions` (admin, filters by member/type/date).
- **Views:** member earnings (summary cards, table, wallet link), admin commission ledger, commission detail drawer.

**Rules**

- Recipient must be active with a package; otherwise `skipped` with reason — no money moves.
- Amount from the source package's `direct_referral_bonus`.
- Idempotent via unique key; approval replay does nothing.
- Wallet credit inside the same transaction as the commission insert; both roll back together.
- `status='credited'` only when wallet credit succeeded.
- Member sees only their own commissions.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P10-T1 | Activation with active sponsor | Sponsor +₱500, wallet +500, ledger row |
| P10-T2 | Sponsor pending/inactive | `skipped` row, no wallet change |
| P10-T3 | Package with direct bonus 0 | No commission, no error |
| P10-T4 | Replay approval | No duplicate |
| P10-T5 | Two recruits | ₱1,000 total, two rows |
| P10-T6 | Wallet invariant after suite | Balances match ledger |
| P10-T7 | Member earnings page | Correct totals and pagination |
| P10-T8 | Admin ledger filters | Correct subsets and sums |
| P10-T9 | Member A cannot see B's commissions | Guarded |
| P10-T10 | Commission detail | Links to activation, source member, wallet entry |

**Gate.** All P10 tests pass; finance invariant report clean; tag `phase-10`.

---

### Phase 11 — Indirect referral commissions (levels 2–10)

**Goal.** Pay the 10-level unilevel bonus per package activation exactly as specified, with the package's own level matrix.

**Depends on.** Phase 10.

**Effort.** 5 dev-days.

**Reference.** `Commission::processIndirectReferral` (walk `sponsor_id`, skip inactive, level amounts from package), `package_indirect_levels`, `views/member/earnings.php`.

**Deliverables**

- **Extension:** `CommissionService::onActivation()` adds the indirect walk (levels 2–10); admin package editor already exposes the matrix (Phase 3).
- **Routes/views:** extend earnings with a level breakdown; admin commission ledger gains `type`/`level` filters; add "Unilevel statement" export (CSV) if cheap.
- **Settings:** `commission_indirect_max_levels` (10), `commission_indirect_enabled` (1), `commission_compression` (0).

**Rules**

- Start at the sponsor's sponsor (level 2); level 1 is direct only.
- Amount per level from the **source package** (`package_levels`), not the recipient's package.
- Skip ineligible uplines (inactive, no package) but consume their level; if the **source package** has `indirect_enabled = 0`, award no indirect commission at all.
- Stop at level 10, chain end, or cycle.
- Every award is its own commission row with `level`; idempotent.
- A single activation can legitimately produce up to 10 commission rows in one transaction.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P11-T1 | Chain 10 deep, activate at the bottom | Levels 1–10 paid exactly per matrix |
| P11-T2 | Chain 11 deep | Level 11 gets nothing |
| P11-T3 | Inactive upline mid-chain | Skipped; its level is consumed, deeper levels continue |
| P11-T4 | Package B matrix | Same amounts as A (per V3.0) |
| P11-T5 | Package `indirect_enabled=0` | Only direct paid |
| P11-T6 | Replay approval | No duplicates |
| P11-T7 | Zero-amount level | No row for that level |
| P11-T8 | Wallet credits | Sum equals sum of commission rows |
| P11-T9 | Earnings breakdown | Per-level totals correct |
| P11-T10 | Admin filter by type/level | Correct rows/sums |
| P11-T11 | Performance | 10-hop walk ≤ 20 queries |
| P11-T12 | Worked Example B (§6.6) | Juan ₱900, Maria ₱1,500, Peter ₱1,000 |

**Gate.** All P11 tests pass, including the workflow examples; finance invariants clean; tag `phase-11`.

---

### Phase 12 — E-wallet transfers, limits and monitoring

**Goal.** Let members move wallet funds to each other under the reference's fee/limit rules, with an admin monitor that shows the whole money picture.

**Depends on.** Phases 5, 9.

**Effort.** 4 dev-days.

**Reference.** `Ewallet::transfer()`, `cron/fund_transfer_limit_reset.php`, `MemberController::ewalletTransfer/doEwalletTransfer`, `views/member/ewallet_transfer.php`, `AdminController::ewalletMonitor`, `views/admin/ewallet_monitor.php`.

**Deliverables**

- **DB:** `ewallet_transfers`; `users.ewallet_sent_today`, `users.ewallet_sent_this_week`.
- **Routes:** member `GET /wallet/transfer`, `POST /wallet/transfer`; admin monitor tabs on `GET /admin/wallets` (transfers, top-ups, per-member ledger search).
- **Service:** `TransferService::send($senderId, $recipientUsername, $amount, $note, $password)`; limit helpers; `Cron/ResetTransferLimits` CLI (`bin/reset_transfer_limits.php`).
- **Views:** `member/transfer.php` (recipient, amount, note, password, fee preview, limit usage), admin monitor tables (transfers, top-ups, ledger).

**Rules**

- Recipient must exist, be active, and not be the sender.
- Amount > 0 and ≥ `ewallet_min_transfer`; total debit = amount + fee; must fit the balance.
- Fee: `ewallet_transfer_fee` for members; **zero for admin/superadmin**, who are also exempt from limits.
- Daily and weekly limits checked against the tracked counters and the settings; refused transfers write nothing and do not increment counters.
- The member re-enters their password; wrong password refuses the transfer before any lock.
- One transaction: lock sender → debit (non-withdrawable first) → credit recipient (non-withdrawable) → credit fee to the primary admin (non-withdrawable) → insert transfer + three ledger rows.
- Superadmin transfers are attributed to the primary admin account (wallet and ledger).
- The reset cron zeroes daily counters every day and weekly counters on Monday; it is idempotent and logs a summary.
- Admin monitor shows total float, withdrawable vs locked percentages, transfer log, top-up log, and a searchable per-member ledger.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P12-T1 | Transfer ₱500 with zero fee | Sender −500, recipient +500, transfer row, 2 ledger rows |
| P12-T2 | Transfer with a fee | Sender −(amount + fee); admin credited the fee; 3 ledger rows |
| P12-T3 | Below `ewallet_min_transfer` | Refused |
| P12-T4 | Above balance | Refused; counters unchanged |
| P12-T5 | Self-transfer | Refused |
| P12-T6 | Wrong password | Refused; no lock, no write |
| P12-T7 | Daily limit boundary | At limit allowed, above refused |
| P12-T8 | Weekly limit boundary | Same, weekly counter |
| P12-T9 | Reset cron | Daily zeroed; weekly zeroed only on Monday; idempotent |
| P12-T10 | Concurrent transfers | No negative balance; totals reconcile |
| P12-T11 | Withdrawable preservation | Recipient credit is non-withdrawable; sender's withdrawable reduced only after non-withdrawable |
| P12-T12 | Admin/superadmin transfer | No fee, no limits; attributed to primary admin |

**Gate.** All P12 tests pass; reconciliation clean; cron verified twice; tag `phase-12`.

---

### Phase 13 — Payouts

**Goal.** Members cash out withdrawable earnings; staff process requests; every peso is held, released or settled correctly.

**Depends on.** Phases 5, 10.

**Effort.** 5 dev-days.

**Reference.** `models/Payout.php`, `MemberController::payout/requestPayout`, `AdminController::payouts/payoutAction`, `views/member/payout.php`, `views/admin/payouts.php`.

**Deliverables**

- **DB:** `payout_requests`; `users.gcash_number`, `users.maya_number`, `users.usdt_trc20_address`, `users.usdt_bep20_address`.
- **Routes:** member `GET /payouts`, `POST /payouts/request`; admin `GET /admin/payouts` (status tabs), `POST /admin/payouts/{id}/approve`, `/reject`, `/complete`.
- **Service:** `PayoutService::request()` (validation, fee/net/USDT math, hold), `approve()`, `reject()` (refund), `complete()`, totals.
- **Views:** `member/payouts.php` (withdrawable, request form, history), `admin/payouts/index.php` (tabs, totals, actions with confirmations), profile payment details.

**Rules**

- Amount ≥ `min_payout`; ≤ `withdrawable_balance`; no other `pending`/`approved` request.
- Methods: GCash, Maya, USDT TRC20, USDT BEP20 (each toggleable); account prefilled from the profile.
- Service fee = `service_fee_<method>` percent of amount; net = amount − fee.
- USDT: `net_usdt = (net − gas_fee_usdt × usdt_rate) / usdt_rate`, computed **server-side** from settings; the rate and gas fee are stored on the request for audit.
- **Hold at request:** debit `payout` (both buckets) in the same transaction as the insert; the member can no longer spend it.
- Reject (pending or approved) refunds the held amount with a `refund` ledger row (`ref_id` = payout id) and records the reason.
- Complete: admin confirms funds were sent off-platform; request becomes `completed`, no further wallet movement.
- Only admins/superadmins process requests; a member cannot approve their own; all actions audited.
- Member history shows amount, fee, net, USDT amount (when applicable), status and processed date.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P13-T1 | Valid request | Held from withdrawable; request `pending`; ledger debit |
| P13-T2 | Below min payout | Refused |
| P13-T3 | Above withdrawable | Refused (even if total balance is enough) |
| P13-T4 | Second request while one is open | Refused |
| P13-T5 | Service fee calculation | Net = amount − fee; stored correctly |
| P13-T6 | USDT request | Correct rate/gas/USDT amount from settings; no client-supplied rate |
| P13-T7 | Approve | `approved`, processed_by/at set |
| P13-T8 | Complete | `completed`; no second debit |
| P13-T9 | Reject | Held amount refunded exactly once; reason stored |
| P13-T10 | Member cannot process own payout | Refused |
| P13-T11 | Double approve/complete | Refused (status guard) |
| P13-T12 | Reconciliation | Completed payouts match ledger debits; rejected have no hold |
| P13-T13 | Admin queue filters/totals | Pending/paid totals correct |

**Gate.** All P13 tests pass; payout reconciliation clean; tag `phase-13`.

---

### Phase 14 — Super-login (superadmin impersonation)

**Goal.** A superadmin can open a member's portal exactly as that member sees it, through an audited, expiring, non-cookie session — without disturbing their own login.

**Depends on.** Phases 1, 9.

**Effort.** 3 dev-days.

**Reference.** `Auth::startImpSession/isImpSession/validateImpSession/endImpSession`, `AuthController::showSlogin/doSlogin` (+ logout/exit handling), `models/ImpLog.php`, `impersonation_log`, `views/auth/slogin.php`.

**Deliverables**

- **DB:** `impersonation_log` (superadmin, target, `nonce_hash`, IP, UA, status, expires).
- **Routes:** `GET /slogin` (superadmin console + member username form), `POST /slogin`, `POST /slogin/exit`, admin `GET /admin/impersonations` (audit list).
- **Session layer:** URL-token session (`?imp=<nonce>`) opened with `session.use_cookies=0` + `session_id($nonce)`; 8-hour TTL; IP + UA binding; `Auth::validateImpSession()` runs in the front controller before dispatch.
- **Views/layout:** `auth/slogin.php`, member-layout impersonation banner ("Viewing as @user — Exit"), audit table.

**Rules**

- Only `role = 'superadmin'` may open `/slogin`; the target must be an active **member** (never another admin).
- Login by member username; rate-limited (5 attempts / 15 min per target); failures logged.
- The nonce is random 32 bytes; only its SHA-256 is stored; every request re-validates session + expiry + IP/UA; invalid sessions are destroyed and bounce to `/slogin`.
- No cookie is issued: the superadmin's own session stays intact; closing the tab ends access at TTL.
- Every member link/form/JS URL must carry the `imp` token (helper `imp_query()`), otherwise the next request falls out of impersonation.
- The banner is always visible; "Exit" marks the log `logged_out` and returns to the console.
- While impersonating, the session is a member session: no admin routes; sensitive actions are performed as the member and audited (reference behaviour).
- Expiry/TTL, suspension of the target, or IP/UA change ends the session and marks the log `expired`.
- Audit list shows superadmin, target, IP, UA, created, expires, status; raw nonce never shown.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P14-T1 | Superadmin opens S-Login for a member | URL-token session, dashboard as that member, banner visible |
| P14-T2 | Links inside impersonation keep the token | Navigation stays impersonated |
| P14-T3 | Exit | Back to console, log `logged_out`, token dead |
| P14-T4 | TTL expiry (simulated) | Session destroyed, log `expired`, redirect `/slogin` |
| P14-T5 | IP/UA mismatch | Session destroyed |
| P14-T6 | Admin (non-superadmin) opens `/slogin` | 403 |
| P14-T7 | Target is an admin/suspended member | Refused |
| P14-T8 | Six attempts in 15 minutes | Rate-limited |
| P14-T9 | Nonce tampering | Rejected; audit row unchanged |
| P14-T10 | Raw nonce in DB | Absent (hash only) |
| P14-T11 | Superadmin's own session | Still valid after impersonation |

**Gate.** All P14 tests pass; audit rows verified; tag `phase-14`.

---

### Phase 15 — Hardening, dashboards, UAT and launch

**Goal.** Make it safe and operable; prove it end-to-end; hand over.

**Depends on.** All previous phases.

**Effort.** 5 dev-days.

**Deliverables**

- **Dashboards:** member (wallet both buckets, package, referral link, commission totals, recent orders, cart badge) and admin (sales today/30d, orders by status, top products, members, commissions paid, wallet float, pending payouts).
- **Reports:** sales by day, commissions by level, wallet statement export (CSV), payout register, registration-code revenue, reconciliation/health widget (§6.8).
- **Settings page:** shop, payment accounts, commission toggles, transfer fees/limits, payout methods/fees/USDT rate, all keys from all phases, validated and audited.
- **Audit log:** admin actions, settings changes, status transitions, impersonation exits (already partly in order events / `impersonation_log`).
- **Hardening:** security checklist (§3.7) reviewed; rate limits; headers; upload dir hardening; error display off; indexes reviewed with `EXPLAIN` on hot queries.
- **Operations:** cron setup (order expiry, auto-complete, transfer-limit reset; Windows Task Scheduler commands documented), backup/restore drill, runbook.
- **Docs:** README update, phase log completed, admin quick guide, UAT script executed and signed.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P15-T1 | Full UAT script (§8.5) | All steps pass |
| P15-T2 | Security probes: CSRF, IDOR, role escalation, upload, transfer/payout abuse, impersonation | All refused/logged |
| P15-T3 | Performance: shop 200 products, 1 000 orders, 500 ledger rows | Pages < 1 s locally; no N+1 storms |
| P15-T4 | Reconciliation | All invariants zero |
| P15-T5 | Backup → restore → verify | Data intact, app works |
| P15-T6 | Cron expiry + auto-complete + limit reset | Idempotent, correct |
| P15-T7 | Settings changes | Validated, audited, effective |
| P15-T8 | Error pages | 404/403/500 branded, no stack traces in prod mode |
| P15-T9 | Mobile responsiveness | Shop, cart, checkout, wallet, payouts usable |
| P15-T10 | Fresh install from `schema.sql` + seeds | Reproducible environment |
| P15-T11 | Money invariants under a mixed scenario | Comms + transfers + payouts + purchases all reconcile |
| P15-T12 | Impersonation audit report | Sessions and exits complete |

**Gate.** UAT signed off; security checklist complete; backup verified; release tag `v1.0-complete`.

---

## 8. Test strategy

### 8.1 Test levels

| Level | What | Tooling | When |
|---|---|---|---|
| Unit | Services: cart maths, wallet, commission walk, pricing, state guards | `tests/run.php` against a test schema | every phase |
| Integration | DB constraints, transactions, idempotency, concurrency (two connections) | PHP CLI tests | every phase |
| HTTP smoke | Status codes, redirects, auth guards, JSON contract | `tests/smoke.ps1` (curl) | every phase |
| Manual UAT | Real browser flows on `localhost` | checklist per phase + §8.5 | phase gate |
| Regression | All previous phases' tests | full suite | every phase gate |

### 8.2 Harness and fixtures

- `tests/bootstrap.php`: creates/recreates `ecowealth_sneat_test`, loads `schema.sql` + migrations, seeds fixtures, returns a PDO. `app/config.php` uses `defined() || define()` so tests can point constants at the test schema.
- `tests/run.php`: scans `tests/Cases/*.php`, runs each case in a transaction that is rolled back, prints `PASS/FAIL`, exits non-zero on failure.
- `tests/fixtures.php`: builds the sponsor tree `juan → maria → {peter, sarah}` + `david` under maria, a 10-deep chain for unilevel tests, products, packages A/B, unused/used/expired registration codes, wallet balances with both buckets, a pending payout and a completed transfer.
- `tests/smoke.ps1`: hits `/` (expects the redirect into the app), `/login`, `/shop`, `/cart`, `/checkout`, `/orders/1`, `/wallet`, `/payouts`, `/slogin`, `/admin/products`, `/admin/codes`, `/admin/payouts` with and without sessions, asserts codes/Locations.
- Naming: `P{phase}-T{n}` maps 1:1 to the tables in §7.

### 8.3 Phase gate checklist (copy into `docs/phase-log.md`)

```
[ ] Migration applied to dev DB, schema.sql regenerated
[ ] Seed data loads on a fresh install
[ ] All P{N} tests pass
[ ] Full regression suite passes
[ ] Smoke script passes
[ ] Manual UAT for this phase signed
[ ] No new PHP warnings/notices in logs
[ ] Money invariants clean (if money touched)
[ ] Docs updated (README, phase log)
[ ] Backup taken; tag phase-{N} created
```

### 8.4 Regression suite

Every phase keeps prior tests runnable. The suite is cumulative: by Phase 15 it covers auth guards, catalog, cart, wallet (both buckets), orders, payments, fulfilment, registration modes, codes, activation, both commission types, transfers/limits, payouts and impersonation.

### 8.5 End-to-end UAT script

1. Admin signs in, creates a product (price ₱300, stock 50), publishes it; edits Package A (₱4,498, direct ₱500, levels 2–10) and adds contents.
2. Admin generates 3 registration codes for Package A (₱4,498) and exports the CSV.
3. Superadmin opens `/slogin`, impersonates `juan` (seeded, active, Package A), sees the member dashboard with the impersonation banner, then exits; the audit entry shows `logged_out`.
4. Juan copies his referral link, and admin tops up Juan's wallet by ₱5,000 (audited).
5. Visitor opens the repo-root `index.html` front door (`/ecowealth/`), enters the app through its link into `/ecowealth/system/`, and registers as `maria` through the referral link (sponsor prefilled) in pending mode; Maria signs in and sees "pending".
6. Maria activates Package A using a **registration code**: code is marked used, Maria becomes active.
7. Juan's wallet increases by ₱500 (withdrawable); his earnings page shows the direct referral with source `maria` and the ledger entry.
8. Maria adds 2× Coffee + 1 Spirulina to the cart, edits quantity, removes one, re-adds; checks out with a saved address, pays by e-wallet; order is `paid`, stock reserved, invoice issued.
9. Admin packs, ships with tracking; Maria sees `shipped`, admin marks delivered, Maria confirms → `completed`.
10. Juan transfers ₱300 to `peter` from the wallet (fee per settings, password confirmed); Peter's balance increases; the transfer appears in Juan's statement and the admin monitor.
11. Maria requests a payout of ₱1,000 (above `min_payout`); the amount is held; admin approves then marks it complete; Maria's withdrawable balance reflects the hold and the completion exactly once.
12. Admin rejects a second payout request; the held amount returns to Maria's wallet.
13. Maria sponsors `peter` and registers him with a **second code** → Juan earns ₱100 at level 2, Maria earns ₱500 direct.
14. Admin verifies reconciliation: orders, wallets (both buckets), commissions, transfers, payouts and stock all reconcile; the audit lists the impersonation session.
15. Admin exports the commission ledger and the payout register; numbers match the member statements.

---

## 9. Environment, deployment and operations

### 9.1 Local setup

1. Laragon → Start All (Apache + MySQL).
2. Create DB `ecowealth_sneat` and a user; put credentials in `app/config.local.php`.
3. Import: `mysql -u root ecowealth_sneat < database/schema.sql` then `database/seeds/demo.sql`.
4. Browse `http://localhost/ecowealth/system/`.
5. Run tests: `php tests/run.php`; smoke: `pwsh tests/smoke.ps1`.

### 9.2 Configuration and secrets

- `app/config.php` — defaults and feature flags; `defined() || define()` so tests and `config.local.php` can override.
- `app/config.local.php` — DB credentials, payment account numbers; git-ignored.
- Flags: `APP_ENV`, `APP_DEBUG`, `SHOP_ENABLED`, `COMMISSIONS_ENABLED`.

### 9.3 Migrations and seeds

- One migration per phase, numbered `001_…` to `015_…`; apply in order; log every application.
- `schema.sql` is rebuilt at each gate to represent a fresh install (kept in sync with migrations).
- Seeds are idempotent (`INSERT … ON DUPLICATE KEY UPDATE`).

### 9.4 Backups

- Before every phase and before any destructive change: `mysqldump --routines --triggers` to `backups/backup_phase-N_YYYYMMDD.sql` (git-ignored).
- Restore drill in Phase 15.

### 9.5 Cron

- `php bin/shop_expire.php` — cancels overdue pending orders (Phase 7).
- `php bin/order_autocomplete.php` — completes delivered orders after N days (Phase 8, optional).
- `php bin/reset_transfer_limits.php` — zeroes daily counters every midnight and weekly counters on Monday (Phase 12).
- Windows: Task Scheduler running Laragon's PHP CLI every 15 minutes (order jobs) and at 00:00 (limit reset); document the exact command.
- Each job logs a run summary and is safe to run twice.

### 9.6 Go-live checklist

```
[ ] Production DB created, migrations applied, seed admin + superadmin only
[ ] config.local.php set, APP_DEBUG=0, error display off
[ ] uploads/ writable, .htaccess in uploads active
[ ] cron scheduled and verified (order expiry, auto-complete, transfer limits)
[ ] backups scheduled + one restore tested
[ ] admin/superadmin passwords changed, demo accounts removed
[ ] payment accounts, transfer fees/limits, payout fees and USDT rate verified
[ ] Front door check: `index.html` serves the landing page and links into `/ecowealth/system/`
[ ] UAT signed
[ ] HTTPS enabled (Laragon SSL or host)
```

---

## 10. Schedule and effort

| Phase | Days | Cumulative | Milestone |
|---|---:|---:|---|
| 0 | 1 | 1 | Environment + decisions |
| 1 | 5 | 6 | Skeleton: routes, layouts, roles, tests |
| 2 | 4 | 10 | Products + catalog |
| 3 | 4 | 14 | Packages + level matrix |
| 4 | 5 | 19 | Cart |
| 5 | 5 | 24 | E-wallet core (dual balance + monitor) |
| 6 | 5 | 29 | Checkout + orders |
| 7 | 5 | 34 | Manual payments + lifecycle |
| 8 | 4 | 38 | Fulfilment + invoices |
| 9 | 8 | 46 | Registration + codes + activation |
| 10 | 4 | 50 | Direct referral |
| 11 | 5 | 55 | Indirect referral |
| 12 | 4 | 59 | Transfers + limits + monitor |
| 13 | 5 | 64 | Payouts |
| 14 | 3 | 67 | Super-login |
| 15 | 5 | 72 | Hardening + UAT |

**~72 dev-days** (≈14–15 weeks for one developer; ≈7–8 weeks full-time with AI assistance). Critical path: 1 → 5 → 6 → 7 → 9 → 10 → 11 → 13 → 15; 12 and 14 can run in parallel with 10/11. The EcoWealth frontend rebuild is not in this schedule — `index.html` covers the front door until it is scheduled (D23).

---

## 11. Risks and mitigations

| # | Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|---|
| R1 | Money bugs (double credit, wrong amount, lost debit) | Critical | Medium | Ledger + unique keys + transactional services + invariant tests each phase |
| R2 | Overselling stock under concurrency | High | Medium | Reservation states + `FOR UPDATE` + concurrency tests |
| R3 | Scope creep into QA/harvest/residual | High | High | Explicit out-of-scope list; plugin hooks; phase gates |
| R4 | Routing/base-path regressions after the front controller | Medium | Medium | `APP_BASE_URL` + deep-URL smoke tests from Phase 1 |
| R5 | IDOR / privilege escalation | High | Medium | Ownership scoping + admin guard tests + review |
| R6 | Upload abuse (PHP in images, huge files) | High | Medium | MIME sniff, whitelist, size caps, no-exec uploads dir, guarded proof streaming |
| R7 | Commission disputes / audit gaps | High | Medium | Every award linked to activation + wallet row; skipped rows logged; member statement |
| R8 | Data loss during migrations | High | Low | Backup before each phase + restore drill |
| R9 | Reference copy is still incomplete (no `tests/`, no `uploads/`, two landing images missing) | Medium | Low | Front controller, controllers, all 51 views and the landing page are now present; only the test suite and uploads are reconstructed, and this plan defines behaviour, not the copy |
| R10 | Timeline slip | Medium | Medium | Phase order protects the shop deliverable first; commissions, wallet extras and super-login follow |
| R11 | Payout fraud / double-spend of requested funds | High | Medium | Hold-at-request + status guards + reconciliation tests |
| R12 | Impersonation abuse or session leakage | High | Low | Superadmin-only, hashed nonce, IP/UA binding, TTL, full audit, no cookies |
| R13 | Registration-code leakage or double redemption | Medium | Medium | Atomic conditional update, expiry, admin-only issuance, CSV control |
| R14 | Transfer-limit bypass (timezone / cron gaps) | Medium | Low | Server-side checks use counters, not clock; cron idempotent; tests at boundaries |

---

## 12. Open decisions

| # | Decision | Options | Recommendation | Needed by |
|---|---|---|---|---|
| D1 | App/brand name | EcoWealth / other | **EcoWealth** (configurable `APP_NAME`) | Phase 1 |
| D2 | Can guests browse the shop? | Yes / members-only | **Members-only** (matches reference) | Phase 2 |
| D3 | Is a sponsor required at registration? | Required / optional | **Optional** — a blank sponsor joins under the company root (the primary admin); a typed sponsor must exist and be active. (Amended after Phase 9, 2026-11-07.) | Phase 9 |
| D4 | Must a member have a package to shop? | Yes / no | **No** — shopping open to logged-in members; commissions only when active | Phase 6 |
| D5 | Level 1 double pay? | Direct + indirect L1 / direct only | **Direct only** | Phase 11 |
| D6 | Upline compression when inactive? | Compress / no compression | **No compression** (config flag reserved) | Phase 11 |
| D7 | QA/retail gate now? | Yes / later | **Later** (setting off) | Phase 9 |
| D8 | Shipping fees | None / flat / by area | **None now** (`shipping_fee = 0`), field reserved | Phase 6 |
| D9 | Manual payment channels | GCash / Maya / bank / all | **All three**, accounts set in settings | Phase 7 |
| D10 | Official product price list & PV values | — | Replace placeholder seeds before Phase 2 freeze | Phase 2 |
| D11 | Package contents fulfilment | Ship contents with activation / no fulfilment | **Ship contents** via the package contents list (later order type) or note as not shipped for now — confirm | Phase 9 |
| D12 | Invoice/BIR fields (TIN, VAT) | Needed / not yet | **Store seller fields now**, print simple invoice; VAT later | Phase 8 |
| D13 | Refund policy | Wallet refund / manual | **Wallet refund on admin cancel**, others manual | Phase 7 |
| D14 | Admin may buy | Yes / no | **Yes**, with self-review guard | Phase 7 |
| D15 | Registration codes | Now / later | **Now** (Phase 9) — admin-issued, reference format | Phase 9 |
| D16 | Email/SMS notifications | Now / later | **Later** (flash messages only) | post-v1 |
| D17 | Payout fund handling | Debit at completion (reference) / hold at request | **Hold at request**, refund on reject | Phase 13 |
| D18 | USDT payout rates | Client-side CoinGecko/TronGrid fetch (reference) / server settings | **Server-side rate + gas fee from settings** | Phase 13 |
| D19 | Super-login transport | Cookie session / URL-token session | **URL-token**, 8 h TTL, IP/UA bound, hashed nonce | Phase 14 |
| D20 | Code format | Reference `ABCD-EFGH-JKLM` / workflow's 8-char A/B prefix | **Reference format** (package comes from the DB row) | Phase 9 |
| D21 | Who may issue codes | Admin only / admin + superadmin / members | **Admin + superadmin**; member self-purchase deferred | Phase 9 |
| D22 | Transfer password confirmation | Required / optional | **Required** (reference behaviour) | Phase 12 |
| D23 | Public front door | Repo-root `index.html` now and the EcoWealth frontend later / port `frontend/index.php` into the app now | **`index.html` now** — it plays the role the reference landing page had; the backend is built first and the EcoWealth frontend is dealt with later, outside this build's scope | Now (Phase 0) |

---

## 13. Appendices

### Appendix A — Route table (target)

**Public / member**

| Method | Path | Controller | Guard | Phase |
|---|---|---|---|---|
| GET | `/` | HomeController::index (redirect: dashboard or login) | any | 1 |
| GET | `/shop` | ShopController::index | member | 2 |
| GET | `/shop/{id}` | ShopController::show | member | 2 |
| GET | `/packages` | PackageController::index | member | 3 |
| GET | `/cart` | CartController::index | member | 4 |
| POST | `/cart/add` | CartController::add | member | 4 |
| POST | `/cart/update` | CartController::update | member | 4 |
| POST | `/cart/remove` | CartController::remove | member | 4 |
| GET | `/cart/count` | CartController::count | member | 4 |
| GET | `/wallet` | WalletController::index | member | 5 |
| GET | `/wallet/transfer` · POST | TransferController::index / send | member | 12 |
| GET | `/checkout` | CheckoutController::index | member | 6 |
| POST | `/checkout` | CheckoutController::place | member | 6 |
| GET | `/orders` | OrderController::index | member | 6 |
| GET | `/orders/{id}` | OrderController::show | member | 6 |
| POST | `/orders/{id}/cancel` | OrderController::cancel | member | 6 |
| POST | `/orders/{id}/proof` | OrderController::proof | member | 7 |
| POST | `/orders/{id}/confirm` | OrderController::confirm | member | 8 |
| GET | `/orders/{id}/invoice` | OrderController::invoice | member | 8 |
| GET | `/membership` | MembershipController::index | member | 9 |
| POST | `/membership/activate` | MembershipController::activate | member | 9 |
| POST | `/register/validate-code` | RegistrationController::validateCode | any | 9 |
| POST | `/register/check-username` | RegistrationController::checkUsername | any | 9 |
| POST | `/register/check-sponsor` | RegistrationController::checkSponsor | any | 9 |
| GET | `/register/packages` | RegistrationController::packages | any | 9 |
| GET | `/dashboard` | DashboardController::index | member | 9 |
| GET | `/genealogy` | GenealogyController::index | member | 9 |
| GET | `/earnings` | EarningsController::index | member | 10 |
| GET | `/payouts` | PayoutController::index | member | 13 |
| POST | `/payouts/request` | PayoutController::request | member | 13 |
| GET | `/profile` | ProfileController::index | member | 6 |
| POST | `/profile/password` | ProfileController::password | member | 1 |
| POST | `/profile/address` | ProfileController::saveAddress | member | 6 |
| POST | `/profile/address/{id}/delete` | ProfileController::deleteAddress | member | 6 |
| GET | `/slogin` | SLoginController::index | superadmin | 14 |
| POST | `/slogin` | SLoginController::start | superadmin | 14 |
| POST | `/slogin/exit` | SLoginController::stop | member (imp) | 14 |

**Admin**

| Method | Path | Phase |
|---|---|---|
| GET | `/admin` | 1 |
| GET | `/admin/products` · `/new` · `/{id}/edit` | 2 |
| POST | `/admin/products/save` · `/{id}/status` · `/{id}/delete` | 2 |
| GET | `/admin/packages` · `/new` · `/{id}/edit` | 3 |
| POST | `/admin/packages/save` · `/{id}/status` | 3 |
| GET | `/admin/orders` · `/{id}` | 6 |
| GET | `/admin/wallets` · `/{id}` · POST `/{id}/credit` | 5 |
| POST | `/admin/orders/{id}/transition` | 7 |
| POST | `/admin/orders/{id}/hold` · `/resume` | 7 |
| GET | `/admin/proofs/{id}` | 7 |
| GET | `/admin/settings` · POST `/admin/settings` | 7 (payment keys), 15 (all) |
| POST | `/admin/orders/{id}/ship` · `/deliver` | 8 |
| GET | `/admin/codes` · POST `/admin/codes/generate` | 9 |
| GET | `/admin/codes/export` | 9 |
| GET | `/admin/activations` · POST `/{id}/approve` · `/{id}/reject` | 9 |
| GET | `/admin/members` · `/{id}` | 9 |
| POST | `/admin/members/{id}/status` (suspend / restore) | 9 |
| GET | `/admin/commissions` | 10 |
| GET | `/admin/wallets` transfers/top-ups tabs (monitor) | 12 |
| GET | `/admin/payouts` · POST `/{id}/approve` · `/reject` · `/complete` | 13 |
| GET | `/admin/impersonations` | 14 |
| GET | `/admin/reports` | 15 |

> Auth pages (`/login`, `/register`, `/logout`) remain standalone files outside the router in this build; the register form's live-validation endpoints are new routed paths (`/register/*`, Phase 9) that did not exist in the reference. `/dashboard` is a standalone file until Phase 9, then becomes a routed page. `/slogin` is routed because it needs the superadmin guard and the impersonation console. No reference `?page=` name survives in the target — §2.5 maps every one of them.

### Appendix B — Consolidated DDL (final state for this scope)

> Migrations are split per phase; this is the target schema a fresh install must reach. All tables `InnoDB`, `utf8mb4_unicode_ci`.

```sql
-- ── Phase 1: settings + roles ────────────────────────────────────────
CREATE TABLE settings (
  key_name   VARCHAR(80)  NOT NULL PRIMARY KEY,
  value      TEXT         NOT NULL,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- users.role: 'user' → 'member'; add superadmin (three steps, existing rows keep working)
ALTER TABLE users MODIFY COLUMN role ENUM('user','member','admin','superadmin') NOT NULL DEFAULT 'user';
UPDATE users SET role = 'member' WHERE role = 'user';
ALTER TABLE users MODIFY COLUMN role ENUM('member','admin','superadmin') NOT NULL DEFAULT 'member';

-- ── Phase 2: products ────────────────────────────────────────────────
CREATE TABLE products (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku               VARCHAR(64)  NULL,
  name              VARCHAR(160) NOT NULL,
  slug              VARCHAR(180) NOT NULL,
  price             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pv                DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- reserved: residual income (unused)
  stock             INT UNSIGNED  NOT NULL DEFAULT 0,
  image_url         VARCHAR(255)  NULL,
  short_description VARCHAR(255)  NULL,
  description       TEXT          NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order        INT           NOT NULL DEFAULT 0,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_sku  (sku),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_status (status, sort_order)
) ENGINE=InnoDB;

-- ── Phase 3: packages ────────────────────────────────────────────────
CREATE TABLE packages (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(80)  NOT NULL,
  slug                  VARCHAR(100) NOT NULL,
  image                 VARCHAR(255) NULL,
  price                 DECIMAL(12,2) NOT NULL,             -- entry fee
  product_value         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  direct_referral_bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  indirect_enabled      TINYINT(1)   NOT NULL DEFAULT 1,
  description           TEXT         NULL,
  perks                 TEXT         NULL,
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order            INT          NOT NULL DEFAULT 0,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_packages_slug (slug),
  KEY idx_packages_status (status, sort_order)
) ENGINE=InnoDB;

CREATE TABLE package_levels (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id INT UNSIGNED NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,                     -- 2..10 (level 1 = direct bonus)
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  UNIQUE KEY uq_pkg_level (package_id, level),
  CONSTRAINT fk_pkg_levels_pkg FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE package_products (
  package_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (package_id, product_id),
  CONSTRAINT fk_pkgprod_pkg  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_pkgprod_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Phase 4: cart ────────────────────────────────────────────────────
CREATE TABLE carts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  status     ENUM('active','converted','abandoned') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_carts_user_status (user_id, status),
  CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cart_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,           -- snapshot, refreshed from catalog
  added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_product (cart_id, product_id),
  KEY idx_cart_items_cart (cart_id),
  CONSTRAINT fk_cart_items_cart    FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Phase 5: wallet (dual bucket + top-ups) ──────────────────────────
ALTER TABLE users
  ADD COLUMN ewallet_balance       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN withdrawable_balance  DECIMAL(14,2) NOT NULL DEFAULT 0.00;

CREATE TABLE ewallet_ledger (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  type          ENUM('credit','debit') NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  balance_after DECIMAL(14,2) NOT NULL,
  ref_type      ENUM('commission','payout','transfer','topup','registration',
                     'shop_order','adjustment','refund') NOT NULL,
  ref_id        INT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ewallet_user (user_id, created_at),
  KEY idx_ewallet_ref  (ref_type, ref_id),
  CONSTRAINT fk_ewallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ewallet_admin_topups (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id     INT UNSIGNED NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  note         VARCHAR(255) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_topups_admin (admin_id, created_at),
  KEY idx_topups_recipient (recipient_id, created_at),
  CONSTRAINT fk_topups_admin     FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_topups_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Phase 6: addresses + orders ──────────────────────────────────────
CREATE TABLE addresses (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  label          VARCHAR(40) NULL,
  recipient_name VARCHAR(120) NOT NULL,
  phone          VARCHAR(40) NULL,
  address_line   VARCHAR(255) NOT NULL,
  barangay       VARCHAR(120) NULL,
  city           VARCHAR(80) NULL,
  province       VARCHAR(80) NULL,
  postal         VARCHAR(20) NULL,
  country        CHAR(2) NOT NULL DEFAULT 'PH',
  billing_name   VARCHAR(120) NULL,
  billing_phone  VARCHAR(40) NULL,
  notes          VARCHAR(500) NULL,
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_addr_user (user_id, is_default),
  CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE shop_orders (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no            VARCHAR(20) NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  status              ENUM('pending','payment_review','payment_failed','paid','packing',
                           'ready_to_ship','shipped','delivered','completed','cancelled')
                      NOT NULL DEFAULT 'pending',
  payment_method      ENUM('ewallet','gcash','maya','bank') NOT NULL DEFAULT 'ewallet',
  payment_reference   VARCHAR(40) NULL,
  idempotency_key     VARCHAR(64) NULL,
  subtotal            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  billing_name        VARCHAR(120) NOT NULL,
  billing_phone       VARCHAR(40) NULL,
  shipping_name       VARCHAR(120) NOT NULL,
  shipping_phone      VARCHAR(40) NULL,
  shipping_address    VARCHAR(255) NOT NULL,
  shipping_barangay   VARCHAR(120) NULL,
  shipping_city       VARCHAR(80) NULL,
  shipping_province   VARCHAR(80) NULL,
  shipping_postal     VARCHAR(20) NULL,
  shipping_country    CHAR(2) NOT NULL DEFAULT 'PH',
  notes_member        VARCHAR(500) NULL,
  notes_admin         VARCHAR(500) NULL,
  terms_version       VARCHAR(16) NOT NULL DEFAULT 'v1',
  terms_accepted_at   DATETIME NULL,
  payment_deadline    DATETIME NULL,
  correction_deadline DATETIME NULL,
  paid_at             DATETIME NULL,
  packed_at           DATETIME NULL,
  shipped_at          DATETIME NULL,
  delivered_at        DATETIME NULL,
  completed_at        DATETIME NULL,
  cancelled_at        DATETIME NULL,
  cancelled_reason    VARCHAR(160) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shop_orders_no (order_no),
  UNIQUE KEY uq_shop_orders_idem (idempotency_key),
  KEY idx_orders_user_status (user_id, status, created_at),
  KEY idx_orders_status (status, payment_deadline),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE shop_order_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  product_name      VARCHAR(160) NOT NULL,
  product_sku       VARCHAR(64) NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reservation_state ENUM('reserved','committed','deducted','released') NOT NULL DEFAULT 'reserved',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id, reservation_state),
  CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE shop_order_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status   VARCHAR(20) NOT NULL,
  actor_type  ENUM('member','admin','system') NOT NULL,
  actor_id    INT UNSIGNED NULL,
  reason_code VARCHAR(40) NULL,
  note        VARCHAR(500) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_events_order (order_id, created_at),
  CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Phase 7: payment proofs ──────────────────────────────────────────
CREATE TABLE shop_payment_proofs (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       INT UNSIGNED NOT NULL,
  attempt_no     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  file_path      VARCHAR(255) NOT NULL,
  mime           VARCHAR(40) NULL,
  size_bytes     INT UNSIGNED NULL,
  amount_claimed DECIMAL(12,2) NULL,
  reference_no   VARCHAR(40) NULL,
  transfer_date  DATE NULL,
  status         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  reject_reason  VARCHAR(160) NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_proofs_order (order_id, attempt_no),
  CONSTRAINT fk_proofs_order FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Phase 8: shipments + invoices ────────────────────────────────────
CREATE TABLE shop_shipments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  courier         VARCHAR(40) NULL,
  tracking_number VARCHAR(80) NULL,
  delivered_at    DATETIME NULL,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_shipments_order (order_id),
  CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE shop_invoices (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id         INT UNSIGNED NOT NULL,
  invoice_no       VARCHAR(32) NOT NULL,
  issued_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_at_issue  VARCHAR(20) NOT NULL,
  seller_name      VARCHAR(160) NOT NULL,
  seller_address   VARCHAR(500) NULL,
  seller_tin       VARCHAR(40) NULL,
  seller_phone     VARCHAR(40) NULL,
  buyer_username   VARCHAR(60) NULL,
  billing_name     VARCHAR(120) NOT NULL,
  billing_phone    VARCHAR(40) NULL,
  shipping_name    VARCHAR(120) NOT NULL,
  shipping_address VARCHAR(255) NOT NULL,
  payment_method   VARCHAR(20) NOT NULL,
  paid_at          DATETIME NULL,
  subtotal         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  voided_at        DATETIME NULL,
  void_reason      VARCHAR(80) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoices_no (invoice_no),
  KEY idx_invoices_order (order_id),
  CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE shop_invoice_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id  INT UNSIGNED NOT NULL,
  line_no     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  description VARCHAR(160) NOT NULL,
  sku         VARCHAR(64) NULL,
  quantity    INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  KEY idx_invoice_items_invoice (invoice_id),
  CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES shop_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Phase 9: registration + codes + membership + activations ─────────
ALTER TABLE users
  ADD COLUMN phone              VARCHAR(30) NULL AFTER full_name,
  ADD COLUMN member_status      ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'pending' AFTER role,
  ADD COLUMN package_id         INT UNSIGNED NULL AFTER member_status,
  ADD COLUMN sponsor_id         INT UNSIGNED NULL AFTER package_id,
  ADD COLUMN referral_code      VARCHAR(16) NULL AFTER sponsor_id,
  ADD COLUMN reg_payment_method ENUM('standard','code','ewallet','pending') NOT NULL DEFAULT 'standard' AFTER referral_code,
  ADD COLUMN reg_code_id        INT UNSIGNED NULL AFTER reg_payment_method,
  ADD COLUMN reg_paid_by        INT UNSIGNED NULL AFTER reg_code_id,
  ADD COLUMN activated_at       DATETIME NULL,
  ADD UNIQUE KEY uq_users_referral (referral_code),
  ADD KEY idx_users_sponsor (sponsor_id),
  ADD KEY idx_users_package (package_id),
  ADD CONSTRAINT fk_users_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_sponsor FOREIGN KEY (sponsor_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE reg_codes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(24) NOT NULL,
  package_id INT UNSIGNED NOT NULL,
  price      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  code_type  ENUM('registration','upgrade') NOT NULL DEFAULT 'registration',
  status     ENUM('unused','used','expired') NOT NULL DEFAULT 'unused',
  created_by INT UNSIGNED NOT NULL,
  used_by    INT UNSIGNED NULL,
  used_at    DATETIME NULL,
  expires_at DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reg_codes_code (code),
  KEY idx_reg_codes_status (status),
  KEY idx_reg_codes_package (package_id),
  CONSTRAINT fk_reg_codes_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_codes_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_codes_user    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activations (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NOT NULL,
  package_id          INT UNSIGNED NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  payment_method      ENUM('ewallet','code','gcash','maya','bank') NOT NULL,
  status              ENUM('pending_payment','pending_review','approved','rejected','cancelled')
                      NOT NULL DEFAULT 'pending_payment',
  reg_code_id         INT UNSIGNED NULL,
  reference_no        VARCHAR(40) NULL,
  proof_path          VARCHAR(255) NULL,
  reject_reason       VARCHAR(160) NULL,
  reviewed_by         INT UNSIGNED NULL,
  reviewed_at         DATETIME NULL,
  commission_processed_at DATETIME NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_activations_user (user_id, status),
  KEY idx_activations_status (status, created_at),
  CONSTRAINT fk_activations_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_activations_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Phases 10–11: commissions ────────────────────────────────────────
CREATE TABLE commissions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,                     -- recipient
  type           ENUM('direct_referral','indirect_referral') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  level          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  source_user_id INT UNSIGNED NOT NULL,                     -- activated member
  package_id     INT UNSIGNED NOT NULL,
  activation_id  INT UNSIGNED NOT NULL,
  status         ENUM('credited','skipped','voided') NOT NULL DEFAULT 'credited',
  reason         VARCHAR(120) NULL,
  ledger_id      INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commission_event (type, source_user_id, user_id, level),
  KEY idx_commissions_user (user_id, created_at),
  KEY idx_commissions_activation (activation_id),
  CONSTRAINT fk_comm_user       FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_comm_source     FOREIGN KEY (source_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_comm_package    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT,
  CONSTRAINT fk_comm_activation FOREIGN KEY (activation_id) REFERENCES activations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Phase 12: e-wallet transfers + limits ────────────────────────────
ALTER TABLE users
  ADD COLUMN ewallet_sent_today     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN ewallet_sent_this_week DECIMAL(12,2) NOT NULL DEFAULT 0.00;

CREATE TABLE ewallet_transfers (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id    INT UNSIGNED NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  fee          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_amount   DECIMAL(12,2) NOT NULL,                 -- amount received by the recipient
  note         VARCHAR(255) NULL,
  status       ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_transfers_sender (sender_id, created_at),
  KEY idx_transfers_recipient (recipient_id, created_at),
  CONSTRAINT fk_transfers_sender    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_transfers_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Phase 13: payouts ────────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN gcash_number        VARCHAR(20)  NULL,
  ADD COLUMN maya_number         VARCHAR(20)  NULL,
  ADD COLUMN usdt_trc20_address  VARCHAR(100) NULL,
  ADD COLUMN usdt_bep20_address  VARCHAR(100) NULL;

CREATE TABLE payout_requests (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  payout_method       ENUM('gcash','maya','usdt_trc20','usdt_bep20') NOT NULL DEFAULT 'gcash',
  payout_account      VARCHAR(120) NOT NULL DEFAULT '',
  service_fee_pct     DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  service_fee_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  usdt_rate           DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  usdt_gas_fee        DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  usdt_amount         DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  status              ENUM('pending','approved','completed','rejected') NOT NULL DEFAULT 'pending',
  admin_note          VARCHAR(500) NULL,
  processed_by        INT UNSIGNED NULL,
  requested_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at        DATETIME NULL,
  KEY idx_payouts_user_status (user_id, status),
  KEY idx_payouts_status (status, requested_at),
  CONSTRAINT fk_payouts_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payouts_admin   FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Phase 14: super-login audit ──────────────────────────────────────
CREATE TABLE impersonation_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  superadmin_id   INT UNSIGNED NOT NULL,
  superadmin_name VARCHAR(40)  NOT NULL,
  target_user_id  INT UNSIGNED NOT NULL,
  target_username VARCHAR(40)  NOT NULL,
  nonce_hash      CHAR(64)     NOT NULL,
  ip              VARCHAR(45)  NOT NULL,
  user_agent      VARCHAR(255) NOT NULL,
  status          ENUM('active','expired','logged_out') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME NOT NULL,
  UNIQUE KEY uq_imp_nonce (nonce_hash),
  KEY idx_imp_super (superadmin_id, created_at),
  KEY idx_imp_target (target_user_id, created_at),
  CONSTRAINT fk_imp_super  FOREIGN KEY (superadmin_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_imp_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Phase 15: audit ──────────────────────────────────────────────────
CREATE TABLE audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id     INT UNSIGNED NULL,
  actor_role   VARCHAR(20) NULL,
  action       VARCHAR(80) NOT NULL,
  subject_type VARCHAR(40) NULL,
  subject_id   INT UNSIGNED NULL,
  meta_json    JSON NULL,
  ip           VARCHAR(45) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor (actor_id, created_at),
  KEY idx_audit_subject (subject_type, subject_id)
) ENGINE=InnoDB;
```

### Appendix C — Commission worked example (seeded scenario)

Chain: `juan` (Package A) ← `maria` (A) ← `peter` (A), `sarah` (B), `david` (B); `peter` ← `linda` (A), `miguel` (A).

| Activation | Juan | Maria | Peter |
|---|---|---|---|
| maria (A) | +₱500 direct | — | — |
| peter (A) | +₱100 L2 | +₱500 direct | — |
| sarah (B) | +₱100 L2 | +₱500 direct | — |
| david (B) | +₱100 L2 | +₱500 direct | — |
| linda (A) | +₱50 L3 | — | +₱500 direct |
| miguel (A) | +₱50 L3 | — | +₱500 direct |
| **Totals** | **₱900** | **₱1,500** | **₱1,000** |

### Appendix D — Theme component/page map

| Screen | Base template | Notes |
|---|---|---|
| Public landing page (deferred) | repo-root `index.html` now; `frontend/index.php` is the model for the later rebuild | not a theme page; the EcoWealth frontend is dealt with later (D23) |
| Member dashboard | `dashboards-analytics.html` | cards + ApexCharts, reuse existing dashboard styling |
| Shop catalog | `app-ecommerce-product-list.html` card grid variant | card grid, stock badges |
| Cart | `app-ecommerce-checkout.html` (wizard-ex-checkout) | table + order summary |
| Checkout | `wizard-ex-checkout.html` | address + payment + review |
| Orders list | `app-ecommerce-order-list.html` | DataTable, status tabs |
| Order detail | `app-ecommerce-order-details.html` | timeline + totals |
| Invoice | `app-invoice-preview.html` / `app-invoice-print.html` | print CSS |
| Membership | `pages-pricing.html` | package cards |
| Earnings | `app-ecommerce-dashboard.html` | KPI cards + table |
| Wallet | `pages-account-settings-billing.html` | balance cards + statement table |
| Transfer | `app-invoice-add.html` | recipient + amount + fee preview + password confirm |
| Payouts | `pages-account-settings-billing.html` | method cards + history |
| Registration codes (admin) | `app-ecommerce-order-list.html` | table + generate form + export |
| Super-login console | `auth-login-basic.html` | superadmin-only form + audit table |
| Admin dashboard | `dashboards-crm.html` | KPI cards + charts |
| Admin products | `app-ecommerce-product-list.html` | server-side DataTable |
| Admin product form | `app-ecommerce-product-add.html` | Dropzone upload |
| Admin packages | `pages-pricing.html` + custom form | level matrix table |
| Admin orders | `app-ecommerce-order-list.html` | status tabs |
| Admin members | `app-user-list.html` | DataTable |
| Settings | `pages-account-settings-account.html` | tabs |

### Appendix E — Reference → target mapping (port checklist)

| Reference | Target | Phase | Parity |
|---|---|---|---|
| `index.php` (98-route `?page=` table, guards, gates) | `public/index.php`, `app/routes.php`, `app/Core/Router.php`, `MaintenanceGate` | 1 | Re-specified: clean URLs, 403 page instead of redirect, maintenance gate outside the router |
| `frontend/index.php` (public landing page) | repo-root `index.html` stands in now; later rebuilt (planned `app/views/home/landing.php`) | — (deferred) | Reference only; the EcoWealth frontend rebuild is LATER (D23) |
| `models/Product.php` | `app/Models/Product.php`, `ProductService` | 2 | Full + slug |
| `AdminController::shopProducts/saveShopProduct/productStatus/deleteShopProduct` | `AdminProductController` | 2 | Full |
| `views/admin/shop_products.php` | `app/views/admin/products/*` | 2 | Theme |
| `views/member/shop.php` | `app/views/shop/catalog.php` | 2 | Theme |
| `models/Package.php` | `app/Models/Package.php`, `PackageService` | 3 | Simplified (no binary) |
| `AdminController::packages/savePackage` | `AdminPackageController` | 3 | Simplified |
| `views/admin/packages.php` | `app/views/admin/packages/*` | 3 | Theme |
| `models/Cart.php` | `app/Models/Cart.php`, `CartService` | 4 | Full |
| `MemberController::cart/addToCart/updateCartItem/removeCartItem` | `CartController` | 4 | Full |
| `views/member/cart.php`, `partials/cart_offcanvas.php` | `app/views/shop/cart.php`, `partials/cart_offcanvas.php` | 4 | Full |
| `MemberController::checkout/placeOrder` | `CheckoutController`, `OrderService` | 6 | Full |
| `ShopOrder::createFromCart` | `OrderService::placeFromCart` | 6 | Full |
| `ShopOrder` transitions (subset) | `OrderService` + matrix | 6–8 | 10 of 14 statuses |
| `ShopPaymentProof` | `PaymentService`, `shop_payment_proofs` | 7 | Full |
| `ShopShipment` | `FulfilmentService`, `shop_shipments` | 8 | Basic |
| `ShopInvoice` | `InvoiceService`, `shop_invoices` | 8 | Full snapshot |
| `ShopRefund` | — | later | Deferred |
| `models/Ewallet.php` (`credit`/`debit`/`debitInternal`/`balance`/`ledger`) | `WalletService` | 5 | Full (dual bucket) |
| `Ewallet::adminTopUp`, admin top-up/monitor views | `WalletService::adminTopUp`, `AdminWalletController` | 5 / 12 | Full |
| `Ewallet::transfer`, `views/member/ewallet_transfer.php` | `TransferService`, `TransferController` | 12 | Full (fees, limits, password confirm) |
| `cron/fund_transfer_limit_reset.php` | `bin/reset_transfer_limits.php` | 12 | Rewritten |
| `models/Payout.php`, payout views/actions | `PayoutService`, `PayoutController`, `AdminPayoutController` | 13 | Full (hold-at-request improvement) |
| `models/Code.php`, codes view/actions | `CodeService`, `AdminCodeController` | 9 | Full (+ atomic redemption) |
| `AuthController::showRegister/doRegister`, `views/auth/register.php` | `RegistrationService`, rewritten register page | 9 | Full minus binary |
| `User::register/activate` (binary parts removed) | `RegistrationService` + `ActivationService` | 9 | Re-specified |
| `Auth` impersonation + `AuthController::showSlogin/doSlogin` + `ImpLog` | `SLoginController`, `ImpersonationService`, `impersonation_log` | 14 | Full |
| `Commission::processDirectReferral` | `CommissionService::direct()` | 10 | Re-specified |
| `Commission::processIndirectReferral` | `CommissionService::indirect()` | 11 | Re-specified (L2–10) |
| `install.sql` | `database/migrations/*` | all | Subset |
| `cron/shop_expiry.php` | `bin/shop_expire.php` | 7 | Rewritten |

### Appendix F — Glossary

| Term | Meaning |
|---|---|
| **Front door (landing page)** | The public marketing page visitors hit before the app; served by the repo-root `index.html` for now, modelled on `frontend/index.php` and rebuilt later |
| **Route inventory** | The reference's 98-page `?page=` table in `dmp/cart-system/index.php`; the port's coverage checklist (§2.5) |
| **Associate / Member** | Registered user; earns only after package activation |
| **Sponsor / Upline** | The member who recruited another member; commissions walk up this chain |
| **Downline** | Everyone below a member in the sponsor chain |
| **Package** | Associate entry product bundle (A ₱4,498 / B ₱6,998) with bonuses |
| **Activation** | Purchase/approval of a package; sets member active and triggers commissions |
| **Direct referral** | Bonus to the immediate sponsor (level 1) |
| **Indirect referral** | Unilevel bonus to levels 2–10 |
| **Level** | Distance up the sponsor chain (1 = direct) |
| **Reservation** | Stock held by an unpaid order |
| **Available stock** | `stock − reserved` |
| **Idempotency key** | Token that makes a repeated submission resolve to the original record |
| **Ledger** | Append-only record of wallet movements |
| **QA** | Qualified Associate — V3.0 retail gate, deferred |
| **PV** | Point value for residual income, deferred |
| **E-wallet balance** | Total spendable wallet amount (`users.ewallet_balance`) |
| **Withdrawable balance** | Subset of the wallet that may be paid out (`users.withdrawable_balance`) |
| **Internal debit** | Wallet debit that spends non-withdrawable funds first (purchases, registrations, transfers) |
| **Transfer** | Member-to-member wallet movement with an optional fee and daily/weekly limits |
| **Payout** | Withdrawal request from the withdrawable balance; held, then approved/completed/rejected |
| **Registration code** | Package-bound, priced, expiring single-use code that activates a new member |
| **Standard account** | Free account with no package (shopping only, no commissions) |
| **Pending account** | Registered but not yet activated; earns nothing until activation |
| **Super-login (S-Login)** | Superadmin impersonation of a member through an audited URL-token session |
| **Impersonation token** | The `imp` nonce in the URL that carries an S-Login session; only its hash is stored |
