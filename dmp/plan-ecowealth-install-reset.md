# EcoWealth — Install & Database Reset Plan

**Scope of this plan: a production-grade `install.sql` for fresh local and online installations, a guided installer (CLI + web), a guarded database reset engine (CLI + web console), demo-fixture parity, and the documentation/hardening needed to ship and retire these tools safely.**

| | |
|---|---|
| **Version** | 1.0 — implementation-ready |
| **Date** | 2026-11-11 |
| **Target application** | `system/` — EcoWealth (application Phase 15; 27 tables; 19 migrations; 722 automated cases) |
| **Reference implementation** | `dmp/cart-system/reset.php` (897 lines) and `dmp/cart-system/install.sql` (764 lines) |
| **Companion plan** | `dmp/plan-ecowealth-cart-system.md` — application Phases 1–15. This plan is the follow-on workstream (IR phases below) |
| **Related documents** | `system/README.md`, `docs/deploy.md`, `docs/runbook.md`, `docs/phase-log.md`, `docs/qa/` |
| **Baseline captured at** | commit `0ced89e` · PHP 8.3.16 · MySQL 8.4.3 · schema `ecowealth_sneat` · 27 tables |

> **Status: implemented and signed off (2026-11-12).** IR-1 … IR-8 landed in order against the gates below; the plan keeps its original scope, decisions and phase text, and the IR-8 sign-off at the end of this document records the final evidence, the test counts and the go-live sign-off. Writing the plan changed no application code; the reference files remain untouched.

> **How to use this document.** The work is split into nine **IR phases** (IR-0 … IR-8). Each phase is a vertical, testable slice: it adds files, tests and a gate, and the *next* phase starts only when the current gate is green. `IR-` is used instead of a bare "Phase" to avoid colliding with the application's Phase 1–15 numbering. Every phase lists the exact files it delivers, the commands that prove it, and what to do if it has to be backed out.
>
> **The gate rule.** A phase is done only when **all four** are true:
> 1. its new automated tests pass;
> 2. the previous suite still passes (`php tests/run.php`, no regression);
> 3. the manual acceptance steps in that phase's gate have been run and their output/screenshot filed under `docs/qa/evidence/install-reset/`;
> 4. the phase-log entry is written and the commit is tagged.

---

## Contents

1. [Executive summary](#1-executive-summary)
2. [Current state — reference and target](#2-current-state--reference-and-target)
3. [Decisions](#3-decisions)
4. [What changes versus the reference](#4-what-changes-versus-the-reference)
5. [Architecture](#5-architecture)
6. [Installation specification (`install.sql`)](#6-installation-specification-installsql)
7. [Reset specification](#7-reset-specification)
8. [Phase plan (IR-0 … IR-8)](#8-phase-plan-ir-0--ir-8)
9. [Test strategy](#9-test-strategy)
10. [Operations runbooks](#10-operations-runbooks)
11. [Documentation and go-live](#11-documentation-and-go-live)
12. [Risks, assumptions and mitigations](#12-risks-assumptions-and-mitigations)
13. [Appendices](#13-appendices)

---

## 1. Executive summary

### 1.1 What this work adds

EcoWealth currently has no supported way to install a fresh database with one artefact, and no way to return a development database to a clean state. The reference system has both (`dmp/cart-system/install.sql`, `dmp/cart-system/reset.php`), but its implementations assume a different schema, run without authentication or a transaction, and disable foreign-key checks. Porting them literally would import the reference's data model and its weaknesses.

This plan delivers the EcoWealth equivalents, rebuilt around the current service layer and test harness:

| Deliverable | Path | Purpose |
|---|---|---|
| Production install bundle | `system/database/install.sql` | One self-contained SQL file: 27 tables + 48 settings + 2 staff accounts + starter catalogue/packages. No `CREATE DATABASE`, no `USE`, no demo data. |
| Service installer | `system/app/Services/DatabaseInstaller.php` | Shared install engine: preflight, empty-DB guard, bundle import, credential setup, verification, install lock. |
| CLI installer | `system/bin/db_install.php` | `php bin/db_install.php --check` and a guided non-interactive/interactive install. |
| Web installer | `system/public/install.php` | Shared-hosting path when there is no SSH: preflight page, key-gated, empty-DB only, locks itself after success. |
| Reset engine | `system/app/Services/DatabaseResetService.php` | The one implementation of "return to clean install state": FK-safe deletes, staff reset, optional catalogue reset, optional code generation, demo fixtures, verification. |
| CLI reset | `system/bin/db_reset.php` | Primary reset entry point: preview, dry-run, typed confirmation, JSON report. |
| Web reset console | `system/public/reset.php` | Reference-style dark console (stats, what-will-be-cleared, typed RESET, logs, fresh codes) with staff auth and production gating. |
| Shared plumbing | `system/app/Core/DbTarget.php`, `system/app/Core/SqlScript.php` | Connection/database probing and a quote-aware SQL statement runner. |
| Tests | `system/tests/Cases/SqlScriptTest.php`, `DbTargetTest.php`, `InstallSqlTest.php`, `SeedParityTest.php`, `InstallTest.php`, `DbResetTest.php`, `DbToolAccessTest.php` | Every new path is exercised against pre-provisioned `*_test` schemas. |
| Docs | `system/README.md`, `docs/deploy.md`, `docs/runbook.md`, `docs/phase-log.md`, `docs/qa/phase-16-install-reset.md` | Local/online install, reset, and tool-retirement procedures. |

### 1.2 Why one engine and two thin front-ends

`reset.php` in the reference is a single 897-line file that mixes SQL, HTML and JavaScript. That is acceptable for one developer's test box; it is not acceptable for a system that stores money. Every destructive rule must be testable without a browser, and the CLI must behave exactly like the web console.

The design is therefore:

```
                    ┌─────────────────────────────┐
  CLI (no SSH?) ───▶│ bin/db_install.php          │──┐
  Web (Hostinger) ─▶│ public/install.php          │──┼──▶ DatabaseInstaller ──▶ DbTarget + SqlScript ──▶ MySQL
                    └─────────────────────────────┘  │
                    ┌─────────────────────────────┐  │
  CLI (dev/QA)  ───▶│ bin/db_reset.php            │──┤
  Web (dev/QA)  ───▶│ public/reset.php            │──┘──▶ DatabaseResetService ─▶ Wallet/Commission/Transfer/Payout::reconcile()
                    └─────────────────────────────┘
```

* The **engine** owns all SQL, ordering, transaction boundaries and verification.
* The **CLI** owns flags, progress, exit codes and JSON.
* The **web** front-ends own authentication, CSRF, HTML/CSS and session flash/report rendering.
* `DbTarget` and `SqlScript` are shared by install, reset, tests and the bundle builder.

### 1.3 Definition of done

1. A fresh **local** install is one command or one SQL import, and the app signs in at `/system/`.
2. A fresh **online** install works on shared hosting with no SSH, either through phpMyAdmin + `install.sql` or through the key-gated web installer.
3. A reset returns the database to a state that **matches a fresh install** on every natural key (staff, settings, catalogue, packages) and has **zero** business/transactional rows (audit and live-revision rows excepted by design), with all four finance reconciliations clean.
4. `install.sql` is generated from `schema.sql` + seeds, and a drift test fails the suite if either side changes without rebuilding it.
5. Both web tools are impossible to use after install/lock, and the go-live checklist removes them.
6. The full suite (the existing 722 cases plus the new groups) and the smoke script pass.

### 1.4 Out of scope

| Item | Why |
|---|---|
| Backup/restore service (`mysqldump` wrapper, off-site copies) | The reference has none, and the hosting path differs. This plan requires a backup before destructive work and documents the manual restore; a backup service is a separate workstream. |
| Upgrading an existing database with `install.sql` | `install.sql` is fresh-install only. Existing installs use `database/migrations/NNN_*.sql` in order. |
| Automatic schema migrations from the web UI | Out of scope; migrations stay a CLI/deploy operation. |
| Multi-tenant or multi-schema app instances | One app, one schema. |
| Seeding real (non-demo) catalogue content | Operators replace the starter catalogue; the plan only guarantees a working default. |
| Force-password-change on first login | Not needed for the tools; the go-live checklist and installer policy cover staff credentials. Could be a later phase. |

---

## 2. Current state — reference and target

### 2.1 The reference reset (`dmp/cart-system/reset.php`)

The reference file is a standalone, unauthenticated web page. A POST with `action=reset`:

1. Deletes every `member` user.
2. Deletes commissions, e-wallet ledger, payout requests.
3. Deletes the v2 cap/DFI/transfer/top-up/CD tables.
4. Deletes uploaded reactivation-proof images.
5. Deletes all registration codes.
6. Resets the admin row's binary-tree/cap/DFI/CD counters, balances and last login.
7. Resets `AUTO_INCREMENT` on every cleared table.
8. Optionally deletes packages and re-seeds a hard-coded Starter package.
9. Re-generates 1–50 registration codes with `generate_code()`.
10. Clears `settings.last_reset`.

It is wrapped in `try/catch` and always restores `FOREIGN_KEY_CHECKS = 1` in the catch, but it has **no transaction**, executes each `DELETE` as an independent statement, trusts `$_POST`, has **no CSRF token**, no authentication, no confirmation of the target database, and no post-reset verification. Its login hint is hard-coded (`admin` / `Admin@1234`).

**What is worth keeping:** the operator experience (current-state stats, a red "what will be cleared" list, typed `RESET`, package/code options, per-step log, copyable fresh codes), the "keep packages vs re-seed" idea, and the reset-to-clean-install intent.

**What must not be ported:** the missing auth, missing transaction, `FOREIGN_KEY_CHECKS = 0`, hard-coded credentials, unconditional code generation, and the binary-MLM column list (EcoWealth has no binary/cap/DFI/CD columns).

**How the reference steps map to EcoWealth**

| Reference step | EcoWealth counterpart | Difference |
|---|---|---|
| Delete `users WHERE role='member'` | Same, after every child table is cleared | Orders/top-ups/codes are deleted first (FK `RESTRICT`) |
| Delete commissions, ledger, payouts | Delete commissions + activations, ledger, transfers, top-ups, payouts | Activations are new; transfers/top-ups moved here from the v2 block |
| Delete cap/DFI/CD/reactivation tables | Not applicable | Those tables do not exist in EcoWealth |
| Delete reactivation proof images | Delete `uploads/proofs/*` | Payment and activation proofs share the folder |
| Delete all registration codes | Same | Optional re-generation is explicit (`--codes`) |
| Reset admin binary/cap/DFI/CD fields | Staff reset: package, sponsor, registration, referral, wallet, transfer counters, login | Two staff roles; no binary/cap columns |
| `ALTER TABLE … AUTO_INCREMENT = 1` | Same, but only empty tables, after commit, best effort | `users` is never touched (staff rows survive) |
| Keep packages / re-seed Starter | Keep catalogue (default) / re-seed the real `003`+`004` seeds | No second hard-coded copy of package values |
| Generate 1–50 codes | `--codes=0…50` (default 0) | Codes are inventory; opt-in |
| `settings.last_reset` | `last_reset_at` / `last_reset_by` + `system.db_reset` audit row | The reset is recorded, not just timestamped |

### 2.2 The reference install (`dmp/cart-system/install.sql`)

764 lines, self-contained, 28 tables plus indexes and seed data. Its header documents an important defect and its fix: an earlier revision hard-coded `CREATE DATABASE`/`USE`, so the same file installed into the wrong schema on any machine that did not share the name. The current file deliberately names **no** database; the caller selects it. `tests/run.php` relies on that property.

It seeds an `admin` + `sadmin` account with bcrypt hashes, one Starter package, settings, and one demo code (`DEMO-STAR-TKIT`). It is not guarded against re-import and is not idempotent (plain `INSERT`s; `ALTER TABLE ... ADD INDEX` at the end).

**What is worth keeping:** one file, no database name, self-contained schema + seed data, documented usage, no `db-export.sql` dependency.

**What must change:** no hard-coded schema name (keep the lesson), no demo code in a production bundle, no plain `INSERT` that turns a re-import into a half-applied database, and a visible guard/verification footer.

### 2.3 The EcoWealth target (`system/`)

| Area | Fact |
|---|---|
| Runtime | PHP 8.1+ (8.3.16 locally), MySQL 8.x (8.4.3 locally), Apache + `mod_rewrite` or `php -S` with `public/router.php` |
| Structure | No framework; `app/Core`, `app/Models`, `app/Services`, `app/Controllers`, `app/views`, `app/layout`; custom autoloader by class name |
| Data | 27 tables, 19 migrations; `database/schema.sql` is the authoritative fresh DDL; seeds live in `database/seeds/001…005` |
| Roles | `member` · `admin` · `superadmin`; staff are rows in `users` |
| Money | Dual wallet (`ewallet_balance` + `withdrawable_balance`), immutable `ewallet_ledger`; services are the only writers |
| Live UI | `live_revisions` holds one monotonic revision per page surface; the browser polls/ETags against it |
| Audit | `audit_log` (append-only; no update/delete path in the app) |
| Uploads | `public/uploads/{products,packages,proofs}/`; proofs are the only transactional uploads |
| Tests | `tests/run.php` + `tests/smoke.ps1`; 722 cases in Phase 15; the harness drops and rebuilds `ecowealth_sneat_test` from `schema.sql` + all five seeds |
| Existing install docs | `system/README.md` quick start imports six files; `docs/deploy.md` §5B lists five of them; neither has a single bundle |

### 2.4 Table inventory and reset disposition

The reset engine must dispose of every table deliberately. `KEEP` means data survives a default reset; `CLEAR` means all rows are deleted; `RESET` means the row survives but its state returns to install defaults; `DELETE` means rows are deleted, and the table is re-seeded only when the optional catalogue reset is requested.

| # | Table | What it holds | Default action | Notes that drive the delete order |
|---:|---|---|---|---|
| 1 | `users` | Members + staff | **CLEAR members / RESET staff** | `shop_orders.user_id` is `RESTRICT`; orders must go first. `users.sponsor_id` is a self-FK `SET NULL`. Never touch `AUTO_INCREMENT` while staff rows exist. |
| 2 | `remember_tokens` | Remember-me sessions | **CLEAR (all)** | A destructive reset invalidates every remember-me cookie, staff included; the current console session stays valid. FK `CASCADE` would remove member rows anyway. |
| 3 | `settings` | Configuration | **KEEP** | Reset only upserts `last_reset_at` / `last_reset_by`; install adds `install_*` metadata. |
| 4 | `audit_log` | Admin/money audit | **KEEP** | No FK. A `system.db_reset` row is appended. Optional `--clear-audit` deletes first, then writes the reset row. |
| 5 | `products` | Catalogue | **KEEP / DELETE** | `shop_order_items.product_id` and `package_products.product_id` are `RESTRICT`; items must go first. |
| 6 | `packages` | Packages | **KEEP / DELETE** | `reg_codes`, `activations`, `commissions` reference it `RESTRICT`. |
| 7 | `package_levels` | Ladder levels 2–10 | **KEEP / DELETE** | `CASCADE` from `packages`; delete explicitly when resetting catalogue. |
| 8 | `package_products` | Package contents | **KEEP / DELETE** | Product side is `RESTRICT`; delete before products. |
| 9 | `carts` | Active carts | **CLEAR** | `CASCADE` to items. |
| 10 | `cart_items` | Cart lines | **CLEAR** | `product_id` is `RESTRICT`; delete before products. |
| 11 | `ewallet_ledger` | Wallet movements | **CLEAR** | Immutable ledger; reset removes the rows that justify the cleared balances. |
| 12 | `ewallet_admin_topups` | Admin credits | **CLEAR** | `admin_id` is `RESTRICT`; delete before any `users` work. |
| 13 | `ewallet_transfers` | Member transfers | **CLEAR** | FKs `CASCADE`. |
| 14 | `payout_requests` | Withdrawal queue | **CLEAR** | `user_id` `CASCADE`, `processed_by` `SET NULL`. |
| 15 | `addresses` | Address book | **CLEAR** | `CASCADE`. |
| 16 | `shop_orders` | Orders | **CLEAR** | `user_id` is `RESTRICT`; `CASCADE` to items/events/shipments/invoices/proofs. |
| 17 | `shop_order_items` | Order lines | **CLEAR** | `product_id` `RESTRICT`; delete before products. |
| 18 | `shop_order_events` | Status trail | **CLEAR** | `CASCADE`. |
| 19 | `shop_payment_proofs` | Payment evidence | **CLEAR** | `reviewed_by` `SET NULL`; files cleared from `uploads/proofs/`. |
| 20 | `shop_shipments` | Courier/tracking | **CLEAR** | `CASCADE`. |
| 21 | `shop_invoices` | Frozen invoices | **CLEAR** | `CASCADE` to items. |
| 22 | `shop_invoice_items` | Invoice lines | **CLEAR** | `CASCADE`. |
| 23 | `reg_codes` | Registration codes | **CLEAR** | `created_by` `RESTRICT` (staff kept), `package_id` `RESTRICT`. |
| 24 | `activations` | Package purchases | **CLEAR** | `package_id` `RESTRICT`; commissions `CASCADE`. |
| 25 | `commissions` | Direct/indirect awards | **CLEAR** | `package_id` `RESTRICT`; `activation_id` `CASCADE`. |
| 26 | `impersonation_log` | S-Login sessions | **CLEAR** | All sessions end after a reset. |
| 27 | `live_revisions` | Live page revisions | **BUMP** | Increment every existing surface (`revision + 1`) and insert any missing standard surface at revision 1, so every open page sees a change and a rollback keeps the old revisions. |

**File-system disposition**

| Path | Default | Notes |
|---|---|---|
| `system/public/uploads/proofs/*` | **Delete files** | Payment + activation proofs are all orphaned once the rows are gone. Keep `.gitkeep` and `.htaccess`. |
| `system/public/uploads/products/*`, `.../packages/*` | **Keep**; delete only with catalogue reset | Seeded catalogue images live under `public/assets/`, so a catalogue reset does not need the asset files. |
| `system/storage/logs/*`, `system/storage/locks/*` | **Keep** | Operational cron logs/locks; not business data. Optional manual cleanup only. |

### 2.5 Existing machinery and its gaps

| Existing piece | Good | Gap this plan closes |
|---|---|---|
| `database/schema.sql` | Authoritative, name-free, current through migration 019 | No seed data; a fresh install needs five more files |
| `database/seeds/001…005` | Idempotent, well documented | 005 is demo-only; no production bundle; no parity test that seeds and migrations agree |
| `tests/bootstrap.php` | Rebuilds `*_test` schema from schema + seeds | Only the harness's own schema is provisioned; no granted scratch slots for install/reset tool tests |
| `tests/Cases/MigrationTest.php` | Proves migrations ≡ fresh schema (tables/columns/indexes) | Does **not** compare settings/seed **data**; the `commission_ledger_since` drift below is invisible to it |
| `system/README.md` quick start | Accurate | Six imports; no single artefact; no reset story |
| `docs/deploy.md` §5B | Accurate for phpMyAdmin | Five imports; no install lock; no web installer; cleanup list does not mention install/reset tools |
| `bin/wallet_reconcile.php` + `HealthService` | Strong post-write verification | Not wired into any install/reset path |

### 2.6 Findings this plan fixes

| ID | Finding | Evidence | Fixed in |
|---|---|---|---|
| **F-01** | `settings.commission_ledger_since` is inserted by migration 012 only; a fresh install (`schema.sql` + seeds) does not have it, so a fresh database and an upgraded one diverge in data. | `database/migrations/012_commissions.sql:92` vs `database/seeds/001_settings.sql`; dev DB has 45 settings keys, seed has 44. | IR-2 |
| **F-02** | A fresh install needs six manual imports; no single bundle, no install guard, no verification. | `system/README.md:106-115`, `docs/deploy.md:153-166`. | IR-2/IR-3 |
| **F-03** | There is no reset path at all; QA currently drops/recreates the schema by hand or re-imports seeds. | No `reset.php` / `db_reset` anywhere under `system/`. | IR-5/IR-6 |
| **F-04** | There is no online (shared-hosting, no-SSH) install path beyond phpMyAdmin. | `docs/deploy.md` step 5B. | IR-4 |
| **F-05** | No install/reset path runs the finance reconciliations afterwards. | `HealthService` exists but is only read by dashboards. | IR-3/IR-5 |
| **F-06** | No data-parity test between migrations and seeds (schema parity exists). | `MigrationTest.php` compares tables/columns/indexes only. | IR-1/IR-2 |
| **F-07** | Demo data (`005_demo_tree.sql`) is one import away from production, and nothing detects it after the fact. | `docs/deploy.md:163-166`. | IR-2/IR-7 |
| **F-08** | A fix to the seed key set must not break the 722 existing cases; there is no explicit "fresh install" seed-count assertion beyond `LaunchTest` P15-T10. | `tests/Cases/LaunchTest.php:58`. | IR-2 |
| **F-09** | Seed 002 leaves staff `member_status` at the schema default `pending`; a reset sets staff to `active`, so reset and fresh install differ on a staff field. | `database/seeds/002_accounts.sql:17` omits the column; `schema.sql:67` defaults to `pending`. | IR-2 |
| **F-10** | The README still documents `APP_PHASE` as `10` while `config.php` defines `15`. | `system/README.md:156` vs `app/config.php:34`. | IR-8 |

---

## 3. Decisions

Each decision is binding for the phases below. Changing one requires a new entry and a re-run of the affected gate.

| ID | Decision | Rationale / consequence |
|---|---|---|
| **D-IR-01** | `database/install.sql` is a **generated** file; `database/schema.sql` + `database/seeds/001…004` remain the editable sources. | One bundle for operators, one source of truth for developers. A drift test fails the suite if the committed bundle is stale. |
| **D-IR-02** | `install.sql` contains **no** `CREATE DATABASE`, `USE`, `DELIMITER`, `SOURCE` or database name. | Carries forward the reference's documented fix (installing into the wrong schema). The caller selects the schema. |
| **D-IR-03** | The production bundle seeds settings, staff, starter products and packages — **never** demo members, demo codes or demo wallets. | Demo data must be an explicit act, not the default production state. |
| **D-IR-04** | `install.sql` is **fresh-install only**; upgrades remain `database/migrations/NNN_*.sql`. | Prevents a bundle re-import from silently mixing schema versions. |
| **D-IR-05** | Staff accounts are **kept** by a reset; their financial, package, sponsor, registration, referral and login fields are reset. Both `admin` and `superadmin` are preserved. | They are the only way back into the back office. |
| **D-IR-06** | Reset is **global**, not per-member: every order, ledger row, transfer, payout and code is removed, including test data created by staff accounts. | A half-reset that keeps staff test orders is not a clean install state. |
| **D-IR-07** | Reset **keeps** `settings` and (by default) `audit_log`; it appends one `system.db_reset` audit row. `--clear-audit` is explicit. | Configuration is not test data; the audit trail should record the destructive act. |
| **D-IR-08** | Reset never disables foreign-key checks; the delete order is explicit and covered by a test. | `FOREIGN_KEY_CHECKS = 0` hides ordering bugs instead of preventing them. |
| **D-IR-09** | Catalogue reset (delete + re-seed products/packages/levels/contents) is **opt-in**; the default keeps the catalogue. | Mirrors the reference's "keep packages" default and protects an operator's real catalogue. |
| **D-IR-10** | Registration-code generation is **opt-in** with count `0…50`; the reference's implicit 5 is replaced by an explicit field. | Codes are sellable inventory; a reset should not create them silently. |
| **D-IR-11** | The **CLI** is the primary reset path; the web console is a thin, authenticated front-end. The web console is disabled in production unless `DB_RESET_ENABLED` is explicitly true, and then requires a superadmin. | Destructive tooling must be hard to reach by accident. |
| **D-IR-12** | The web installer is **empty-database only**, refuses when `storage/install.lock` exists, and in production requires `INSTALL_KEY` (a random string in `config.local.php`). It never writes `config.local.php`. | Closes the "whoever reaches the URL first becomes admin" race on shared hosting. Credentials stay in one file the operator controls. |
| **D-IR-13** | Demo fixtures (`005_demo_tree.sql`) are available only through an explicit `--demo` / checkbox, and only when `APP_ENV !== 'production'`. | Keeps demo data a QA action. |
| **D-IR-14** | All DML in a reset runs in **one transaction**; `ALTER TABLE … AUTO_INCREMENT` runs **after** commit (MySQL DDL implicitly commits). A failed DML phase rolls back completely. | The reference's independent statements can leave a half-cleared database. |
| **D-IR-15** | Auto-increment values are reset only on tables that end up **empty**. `users` is never reset because staff rows survive. Catalogue re-seeded rows therefore may keep the previous id sequence; tests compare natural keys, not ids. | Referential atomicity beats cosmetic numbering. |
| **D-IR-16** | Tests may only create/drop databases whose name ends in `_test`; the production and development schemas are never in the test path. | Same rule as the existing harness (`test_recreate_database()`). |
| **D-IR-17** | Both tools are **standalone files**, not routes: no entry in `app/routes.php`, no navigation link. | Keeps the 98-page route inventory and `RouterTest`/`ParityTest` untouched; deleting the tools is a file deletion, not a route edit. |
| **D-IR-18** | The install/reset UI shares one stylesheet, `public/assets/css/db-tools.css`, and no application layout. | Tools look intentional without coupling to the app shell; removing them is one PHP file plus (optionally) the CSS. |

---

## 4. What changes versus the reference

| Aspect | Reference | EcoWealth | Why |
|---|---|---|---|
| **Reset access** | Unauthenticated web page | CLI (`--force` + typed DB name) and web console (staff login; superadmin in production; typed `RESET` / DB name) | A destructive endpoint cannot be anonymous. |
| **Reset transaction** | None; each `DELETE` stands alone | One DML transaction; DDL afterwards; exception ⇒ rollback | A failure must leave the pre-reset database intact. |
| **Foreign keys** | `SET FOREIGN_KEY_CHECKS = 0` | Checks stay on; explicit child-first order | Fail loudly on an ordering bug instead of orphaning rows. |
| **Tables covered** | 12 binary-MLM tables | 20 transactional tables of the shop/wallet/membership build | Matches the actual schema; no binary/cap/DFI/CD tables exist. |
| **Staff reset** | Admin counters, binary placement, caps, DFI, CD | Package/sponsor/registration/referral/wallet/transfer counters/login | EcoWealth has no binary/cap/DFI columns. |
| **Codes** | Always regenerates 5 | Count `0…50`, package chosen, default `0` | Codes are revenue inventory. |
| **Packages** | "Keep packages" checkbox; re-seeds a hard-coded Starter row | "Keep catalogue" default; reset re-runs the actual seed files (products 003, packages 004) | No second, divergent copy of default values. |
| **Audit** | No audit table | Kept by default + `system.db_reset` row | Records who wiped what. |
| **Live UI** | No live layer | `live_revisions` bumped for 18 standard surfaces | Open dashboards must not show pre-reset data. |
| **Verification** | None | Four service reconciliations + zero-count/invariant assertions; CLI exit code reflects the result | A reset that "succeeded" but left money inconsistent is a failure. |
| **Install file** | Hand-maintained, 764 lines, 28 tables, plain inserts, demo code | Generated from schema+seeds, name-free, guarded, starter catalogue, **no** demo code, metadata footer | One source of truth; a re-import fails visibly instead of half-applying. |
| **Install access** | `mysql < install.sql` | CLI installer, phpMyAdmin import, or key-gated web installer | Local and shared-hosting paths are both first-class. |
| **Install credentials** | Static `admin` / `Admin@1234` | Static defaults in `install.sql` (must change) or operator-chosen 12+ character passwords through the installers | Shared hosting often has no SSH; the installer removes default credentials from the guided path. |
| **Reset UI** | 897-line file with inline CSS/JS | `reset.php` (~standalone) + shared `db-tools.css`, server-side validation, CSRF, PRG report | Keeps the reference's operator experience without its security debt. |

---

## 5. Architecture

### 5.1 Components and responsibilities

| Component | Type | Responsibility | Must not |
|---|---|---|---|
| `DbTarget` | `app/Core` class | Build PDO connections (server-level and database-level), validate identifiers, test existence, create a database when privileged, list tables. | Render HTML, read `$_POST`, run business SQL. |
| `SqlScript` | `app/Core` class | Parse a `.sql` file into statements with line numbers, validate it (no `DELIMITER`), execute it with per-statement error context, checksum it. | Decide *which* files to run or in what order. |
| `DatabaseInstaller` | `app/Services` class | Preflight, empty-DB guard, install bundle import, staff credentials, settings metadata, demo option, lock file, verification, report. | Authenticate a user, emit HTML, read CLI flags. |
| `DatabaseResetService` | `app/Services` class | Preview, dry-run, FK-safe transactional reset, staff reset, catalogue reset, code generation, upload cleanup, audit, live revisions, verification, report. | Authenticate a user, emit HTML, read CLI flags. |
| `DemoDataService` | `app/Services` class | Preconditions for demo data, import `005_demo_tree.sql`, verify the tree/wallets/codes. | Run in production, or write demo rows into a non-empty database. |
| `DatabaseToolException` | `app/Services` class | Typed failures (`refused`, `preflight`, `locked`, `not_empty`, `sql`, `verify`) with a context array. | Leak credentials in messages. |
| `DbToolAccess` | `app/Core` class | The shared visibility/actor/key/confirmation gates used by both web tools. | Query business data or render HTML. |
| `bin/build_install_sql.php` | CLI | Deterministically build `database/install.sql` from header + schema + seeds 001–004 + footer; `--check` for CI. | Connect to MySQL. |
| `bin/db_install.php` | CLI | Flags, prompts, progress, exit codes, JSON. | Contain install SQL. |
| `bin/db_reset.php` | CLI | Preview/confirm/dry-run/progress/exit codes/JSON. | Contain delete SQL. |
| `public/install.php` | Web | Standalone preflight → form → run → report; session, CSRF, `INSTALL_KEY`, lock display. | Use the app's session name, layout, or auth (no users exist yet). |
| `public/reset.php` | Web | Authenticated console: stats, options, typed confirmation, CSRF, PRG report, codes grid. | Run when `APP_ENV=production` unless `DB_RESET_ENABLED`; ever run for a member/impersonated session. |
| `public/assets/css/db-tools.css` | Static | Shared dark tool styling for both web pages. | Be loaded by the application layout. |

### 5.2 File map

**New files**

| Path | Phase |
|---|---|
| `system/app/Core/DbTarget.php` | IR-1 |
| `system/app/Core/SqlScript.php` | IR-1 |
| `system/app/Services/DatabaseToolException.php` | IR-1 |
| `system/database/install/header.sql` | IR-2 |
| `system/database/install/footer.sql` | IR-2 |
| `system/database/install.sql` (generated, committed) | IR-2 |
| `system/bin/build_install_sql.php` | IR-2 |
| `system/app/Services/DatabaseInstaller.php` | IR-3 |
| `system/bin/db_install.php` | IR-3 |
| `system/public/install.php` | IR-4 |
| `system/app/Services/DatabaseResetService.php` | IR-5 |
| `system/bin/db_reset.php` | IR-5 |
| `system/public/reset.php` | IR-6 |
| `system/app/Core/DbToolAccess.php` | IR-4 (extended in IR-6) |
| `system/public/assets/css/db-tools.css` | IR-4 (shared with IR-6) |
| `system/app/Services/DemoDataService.php` | IR-7 |
| `system/tests/Cases/SqlScriptTest.php` | IR-1 |
| `system/tests/Cases/DbTargetTest.php` | IR-1 |
| `system/tests/Cases/InstallSqlTest.php` | IR-2 |
| `system/tests/Cases/SeedParityTest.php` | IR-2 |
| `system/tests/Cases/InstallTest.php` | IR-3 |
| `system/tests/Cases/DbResetTest.php` | IR-5 |
| `system/tests/Cases/DbToolAccessTest.php` | IR-4 (extended in IR-6) |
| `docs/qa/phase-16-install-reset.md` | IR-8 |
| `docs/qa/evidence/install-reset/` (screenshots/logs) | IR-4, IR-6, IR-8 |

**Modified files**

| Path | Change | Phase |
|---|---|---|
| `system/tests/bootstrap.php` | Add `test_scratch_database()` and schema-introspection helpers (`test_table_list`, `test_column_list`, `test_index_list`, `test_foreign_key_list`). | IR-1 |
| `system/database/seeds/001_settings.sql` | Add `commission_ledger_since` (F-01). | IR-2 |
| `system/database/seeds/002_accounts.sql` | Set staff `member_status = 'active'` explicitly (F-09). | IR-2 |
| `.gitattributes` (new) | Pin `*.sql text eol=lf` so the generated bundle is byte-stable across Windows checkouts. | IR-2 |
| `system/app/config.php` | Add `INSTALL_KEY` (env fallback) in IR-3 and `DB_RESET_ENABLED` (default `false`) in IR-6. | IR-3/IR-6 |
| `system/.gitignore` / root `.gitignore` | Ignore `system/storage/install.lock`. | IR-3 |
| `system/README.md` | Quick start, directory layout, config table, migrations, tests, install/reset section. | IR-2…IR-8 |
| `docs/deploy.md` | §5B single bundle + web installer, §7 `INSTALL_KEY`, §12 cleanup list. | IR-2/IR-4/IR-8 |
| `docs/runbook.md` | Install/reset/restore procedures. | IR-8 |
| `docs/phase-log.md` | Phase 16.x entries. | IR-8 |
| `docs/qa/README.md`, `docs/qa/sign-off-sheet.md` | New phase rows. | IR-8 |
| `system/app/Services/CommissionService.php` | Read `commission_ledger_since` through its own PDO so reconcile works on an injected target. | IR-5 |

### 5.3 Install flow

```
Local (5 min)                         Online (Hostinger, no SSH)
────────────────                      ──────────────────────────
create DB (Laragon/MySQL)             create DB (hPanel) + user
        │                                     │
        ├─ A: mysql < install.sql             ├─ A: phpMyAdmin Import < install.sql
        ├─ B: php bin/db_install.php          └─ B: upload config.local.php (INSTALL_KEY)
        └─ C: /system/install.php                  → open https://…/system/install.php
                                                       → leave demo off, set staff passwords
                                                       → INSTALL → lock written
        │                                     │
        └──────────────┬──────────────────────┘
                       ▼
        verify: 27 tables · 48 settings · 2 staff · 7 products · 2 packages
                       ▼
        sign in → change passwords → configure settings → delete tools
```

The **static SQL path (A)** and the **installer paths (B/C)** must produce the same schema. They differ only in staff credentials and install metadata:

* static path: seed 002 defaults (`Admin@123` / `Super@123`), `install_channel = 'install.sql'`;
* CLI: operator passwords, `install_channel = 'cli'`;
* web: operator passwords, `install_channel = 'web'`.

Both installer paths write `storage/install.lock`, so the other path refuses afterwards.

### 5.4 Reset flow

```
preview (counts, staff, packages, warnings)
        │
        ▼  confirmation: typed database name (CLI) / RESET (web dev) / DB name (web prod)
acquire MySQL advisory lock
        │
        ▼
BEGIN
  impersonation_log → shop_payment_proofs → shop_invoice_items → shop_invoices
  → shop_shipments → shop_order_events → shop_order_items → shop_orders
  → cart_items → carts → addresses
  → ewallet_ledger → ewallet_transfers → ewallet_admin_topups
  → payout_requests → commissions → activations → reg_codes
  → remember_tokens (all) → users (members)
  → UPDATE staff to install defaults
  → [optional] delete catalogue
  → [optional] re-seed catalogue (003 + 004)
  → [optional] clear audit_log
  → upsert last_reset_at / last_reset_by; bump live_revisions
  → audit row system.db_reset
COMMIT
        │
        ▼
ALTER TABLE … AUTO_INCREMENT = 1 for empty tables (best effort, logged)
        │
        ▼
[optional] generate codes · [web] publish the bumped live surfaces
        │
        ▼
verify: zero counts, staff state, catalogue counts, four reconciliations
        │
        ▼
report: steps + counts before/after + codes + verification + warnings
```

If the DML block throws, the transaction rolls back and the report says **nothing was deleted**; no `AUTO_INCREMENT` or code step runs. If a post-commit step fails, the report distinguishes "reset succeeded; step X needs attention" from "reset failed".

### 5.5 Shared plumbing

#### `DbTarget`

```php
final class DbTarget
{
    public function __construct(
        private string $host = DB_HOST,
        private int $port = DB_PORT,
        private string $user = DB_USER,
        private string $pass = DB_PASS,
        private string $charset = DB_CHARSET,
        private string $timezone = DB_TIMEZONE
    ) {}

    public static function fromConfig(): self;

    /** PDO bound to one database (or the server when $database is null). */
    public function connect(?string $database = null): PDO;

    public function exists(string $database): bool;
    public function create(string $database): void;          // utf8mb4 / utf8mb4_unicode_ci
    public function tables(string $database): array;         // sorted list<string>
    public function tableCount(string $database): int;

    /** Reject anything outside /^[A-Za-z0-9_]{1,64}$/ before it reaches a DSN or DDL. */
    public function assertSafeDatabaseName(string $database): string;
}
```

Rules:

* PDO options mirror `app/database.php` exactly: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false`, `ATTR_TIMEOUT = 5`.
* After connecting: `SET NAMES utf8mb4` and `SET time_zone = <quoted offset>`.
* Errors are mapped to **operator hints**, not raw DSNs: 1045 → "the user/password was refused", 1049 → "the database does not exist — create it first or pass `--create-database`", 2002/1130 → "MySQL is not reachable at host:port". The raw message goes to `error_log()` only.
* `create()` is called only by the installers, only when the operator asks. Shared hosting usually grants `ALL` on a prefixed database but **not** `CREATE DATABASE`; the failure message says exactly that.
* `assertSafeDatabaseName()` is the only place a database name becomes an identifier; it is called before `CREATE DATABASE` and before `USE`-equivalent connection selection.

#### `SqlScript`

```php
final class SqlScript
{
    public static function fromFile(string $path): self;
    public static function fromString(string $sql): self;

    /** @return list<array{sql:string, line:int}> */
    public function statements(): array;

    /**
     * @param callable(int,string,int):void|null $onStatement ($index, $sql, $line)
     * @return array{statements:int, elapsed_ms:int}
     * @throws DatabaseToolException with statement index, line and a 200-char SQL snippet
     */
    public function run(PDO $pdo, ?callable $onStatement = null): array;

    public function checksum(): string;       // sha256 of normalised content
    public function containsDatabaseSwitch(): bool; // true for CREATE DATABASE / USE
}
```

Parser rules (each gets a test):

| Rule | Why |
|---|---|
| Track single quotes, double quotes and backticks; honour doubled quotes (`''`) and backslash escapes; `;` inside them never splits. | Seed text contains apostrophes (`plan''s`) and package perks with `\n`. |
| `--` comment only when followed by whitespace/EOL; `#` at line start; `/* … */` blocks (including `/*!… */`) are comments. | Migration headers and schema comments are prose; MySQL version comments must not execute as fragments. |
| Preserve line numbers for error context. | A 1 000-line bundle needs a findable failure. |
| Reject `DELIMITER`. | The bundle contains no triggers/procedures; supporting it would invite fragile parsing. |
| Reject `CREATE DATABASE` / `USE` for the installer path (`containsDatabaseSwitch()`). | D-IR-02. |
| Trailing `;` removed, empty statements dropped, BOM stripped. | PDO `exec()` accepts one statement at a time. |

Execution stops at the first failing statement and throws with `statement #`, `line #`, driver code and a snippet; the installer then reports the section it was in. There is no silent "continue on error" mode.

### 5.6 Service contracts

#### `DatabaseInstaller`

```php
final class DatabaseInstaller
{
    public function __construct(?DbTarget $target = null, ?string $appRoot = null, string $env = APP_ENV);

    /** @return array{ok:bool, checks:list<array{key:string,label:string,ok:bool,detail:string,hint:string}>} */
    public function preflight(string $database, bool $requireEmpty = true): array;

    /**
     * @param array{
     *   database?:string, create_database?:bool,
     *   admin?:array{username:string,email:string,password:string},
     *   superadmin?:array{username:string,email:string,password:string},
     *   site_name?:string, with_demo?:bool, install_sql?:string
     * } $options
     * @return array<string,mixed> report
     */
    public function install(array $options): array;

    /** @return array{ok:bool, checks:list<array{key:string,label:string,ok:bool,detail:string}>} */
    public function verify(PDO $pdo, array $options = []): array;

    public static function lockPath(?string $appRoot = null): string;   // <STORAGE_PATH>/install.lock (fallback <app>/storage/install.lock)
    public static function isLocked(?string $appRoot = null): bool;
    public static function readLock(?string $appRoot = null): ?array;
}
```

`install()` sequence: acquire `GET_LOCK('ecowealth:install:<hash>', 10)` → refuse when locked → optionally `create()` → empty check → `SqlScript::run(install.sql)` → optional `005_demo_tree.sql` → update the two staff rows → upsert `site_name`/`install_*` settings → verify → write lock → release lock. Any exception before the lock write leaves no lock (the DB may be partially built; the recovery is "drop the schema and retry", documented).

#### `DatabaseResetService`

```php
final class DatabaseResetService
{
    /** @var null|Closure(int,array):void */
    private ?Closure $onStep;

    public function __construct(
        private PDO $pdo,
        private string $database,
        private array $options = [],     // see 7.2; 'env' defaults to APP_ENV
        ?callable $onStep = null         // progress hook / test fault injection
    ) {
        $this->onStep = $onStep === null ? null : Closure::fromCallable($onStep);
    }

    public function preview(): array;   // counts + staff + packages + warnings, no writes
    public function run(): array;       // the report in 7.11
    public function dryRun(): array;    // preview + planned statement count, no writes
}
```

A `callable` cannot be a typed property in PHP, hence the explicit `?Closure` property rather than a promoted `callable` parameter.

The service never calls `db()`: every query uses the injected PDO, including audit and live revisions. This is what lets the tests run the exact production code against `ecowealth_sneat_tool_b_test` without touching the application database. `CommissionService::reconcile()` is the one existing service that reads `setting()` through the global `db()`; IR-5 changes it to read the value from its own PDO (behaviour identical when the PDO is the app connection).

### 5.7 CLI surface

Both commands are CLI-only (`PHP_SAPI !== 'cli'` ⇒ 404) and print to STDOUT, with warnings/errors to STDERR.

| Command | Key flags | Exit codes |
|---|---|---|
| `php bin/build_install_sql.php` | `--check`, `--stdout` | 0 built/matches, 1 stale/bad source, 2 usage |
| `php bin/db_install.php` | `--check`, `--database=`, `--create-database`, `--admin-user=`, `--admin-email=`, `--admin-password=`, `--admin-password-stdin`, `--superadmin-user=`, `--superadmin-email=`, `--superadmin-password=`, `--site-name=`, `--with-demo`, `--key=`, `--force`, `--json` | 0 ok, 1 preflight/verify failed, 2 usage, 3 refused/locked |
| `php bin/db_reset.php` | `--dry-run`, `--force`, `--keep-catalogue` (default), `--reset-catalogue`, `--codes=N`, `--code-package=ID`, `--demo`, `--keep-audit` (default), `--clear-audit`, `--keep-uploads`, `--allow-production`, `--confirm=NAME`, `--json` | 0 ok+verified, 1 verification warning, 2 usage/preflight, 3 aborted/refused |

`--json` writes exactly one JSON document to STDOUT (`{"tool":"db_reset","version":1,…}`); progress text is suppressed or sent to STDERR. This is the contract for CI.

### 5.8 Web surface

| Page | Session | Auth | CSRF | Gates |
|---|---|---|---|---|
| `public/install.php` | dedicated name `ecowealth_install` | none (no accounts exist yet) | yes, per-session token | `storage/install.lock` absent · database empty · in production `INSTALL_KEY` required and rate-limited (5 attempts / 15 min) |
| `public/reset.php` | application session via `bootstrap.php` | `require_admin()`; production `require_superadmin()`; impersonation sessions refused | `csrf_token()` / `csrf_verify()` | `APP_ENV !== 'production'` **or** `DB_RESET_ENABLED === true`; typed confirmation; `backup_ack` checkbox |

`install.php` is deliberately standalone: it must work when `users` does not exist, so it cannot start the application session or call `require_*`. It loads only `app/config.php`, `app/helpers.php`, `app/autoload.php` and the two services, starts its own session, and renders its own page. It sends the same hardening headers as `SecurityHeaders` (nosniff, frame-options, no-store, referrer-policy) via a small local helper.

`reset.php` needs the app session only for authentication and CSRF; it does **not** render inside the app layout.

The reference's app-root `reset.php` cannot be served the same way here: EcoWealth's document root is `public/`, and `system/.htaccess` forwards `/system/x` to `public/x`. The console therefore lives at `system/public/reset.php` and is reached as `/system/reset.php`; the installer follows the same rule.

### 5.9 Concurrency, locking and idempotency

| Concern | Control |
|---|---|
| Two installs at once | `SELECT GET_LOCK('ecowealth:install:<sha1(database)[0..15]>', 10)`; failure ⇒ "another install is running". |
| Install re-run | `storage/install.lock` + non-empty DB check. Both must be absent/empty. |
| Two resets at once | `SELECT GET_LOCK('ecowealth:reset:<sha1(database)[0..15]>', 5)`; failure ⇒ abort before any change. |
| Reset re-run | Designed to be idempotent: second run deletes 0 rows, resets staff again, verifies clean. |
| Lock release | `finally { SELECT RELEASE_LOCK(?) }`; the connection closing also releases it. |
| Installer refresh (web) | The lock file and DB state are re-read on every request; the form POST is single-use because the second run sees a non-empty DB. |
| Reset refresh (web) | PRG: the report is stored in `$_SESSION['db_reset_report']` and rendered on `?done=1`; refreshing does not re-submit. |
| Stale live clients | Every existing `live_revisions` row is incremented in T1 (missing standard surfaces inserted at 1); the web front-end calls `Live::publish()` when a session exists. |

### 5.10 Security model

| Threat | Mitigation |
|---|---|
| Anonymous reset on a LAN/dev box | Staff login + CSRF + typed confirmation. |
| Reset left on production | `DB_RESET_ENABLED` default `false` ⇒ 404 in production; go-live deletes the file; production also requires superadmin and the database name as the confirmation phrase. |
| First visitor installs the site and owns the admin account | Empty-DB-only + `INSTALL_KEY` required in production + attempt throttle + lock file after success. |
| CSRF on the tools | Session token on every POST; constant-time compare via `csrf_verify()`. |
| SQL injection through database/table names | `DbTarget::assertSafeDatabaseName()` + no user input in DDL beyond a validated database name. |
| Secrets in error output | `DatabaseToolException` carries a hint, never a DSN or password; raw errors go to `error_log()`. |
| Password exposure | CLI uses `--admin-password-stdin` or an echoed prompt with a warning; web uses `type=password`; neither tool prints a password or the install key in the report. |
| Stale tool files after go-live | Go-live checklist + 404 verification of `/system/install.php` and `/system/reset.php`. |
| Privilege escalation from the reset page | `require_admin()` / `require_superadmin()` and an explicit `auth_is_imp()` refusal. |

### 5.11 Transactions, DDL and failure boundaries

MySQL commits implicitly on `ALTER TABLE`, so the reset engine uses three boundaries:

| Boundary | Contents | On failure |
|---|---|---|
| **T1 — transactional** | Every `DELETE`, the staff `UPDATE`, optional catalogue delete + re-seed, optional audit clear + reset audit row, settings upserts, `live_revisions` bump | `ROLLBACK`; report "no changes committed"; exit 1. |
| **D — DDL, after commit** | `ALTER TABLE … AUTO_INCREMENT = 1` for tables that are now empty | Logged as a warning, counted, but not fatal. |
| **T2 — post-steps** | Demo install (IR-7) and registration-code batch | Reported as "reset succeeded; step failed"; verification still runs; exit 1 if verification cannot pass. |

The installer has no transaction around the bundle (DDL commits anyway); its safety comes from the empty-DB guard, the lock and the documented drop-and-retry recovery.

### 5.12 Caches and live updates after install/reset

* `setting_cache_clear()` after any settings write in the same request (CLI/web).
* `live_revisions` is bumped inside T1: every existing row gets `revision + 1`, and any missing standard surface is inserted at revision `1`. A rollback restores the old revisions; an open page always sees a different value after a committed reset. The standard surface list is:
  `admin.dashboard`, `admin.products`, `admin.packages`, `admin.orders`, `admin.members`, `admin.activations`, `admin.codes`, `admin.commissions`, `admin.wallets`, `admin.payouts`, `admin.reports`, `admin.settings`, `admin.audit`, `admin.slogin`, `shop.catalog`, `shop.packages`, `member.genealogy`, `config`.
* The web reset front-end queues `Live::publish($surfaces)` after a successful reset so other open tabs refresh on their next page load.
* The installers touch `config` and `shop.catalog`/`shop.packages`/`admin.packages` after installation so any page opened during the install revalidates.

---

## 6. Installation specification (`install.sql`)

### 6.1 Contract

| Property | Requirement |
|---|---|
| **Input** | An **empty** MySQL schema selected by the caller, plus the file `system/database/install.sql`. |
| **Output** | 27 tables, their indexes/foreign keys, 48 settings, 2 staff accounts, 7 starter products, 2 packages, 18 ladder levels, 10 package contents. Zero members, codes, orders, ledger rows. |
| **Database name** | None in the file. No executable `CREATE DATABASE`, `USE`, `DELIMITER`, `SOURCE`, `\.` or shell escape (the header may mention them in prose). |
| **Character set** | `utf8mb4` / `utf8mb4_unicode_ci`; `SET NAMES utf8mb4` and `SET time_zone = '+08:00'` at the top. |
| **Server support** | MySQL 8.0.16+ or MariaDB 10.4+ (the schema uses `CHECK` constraints and `JSON`). |
| **Idempotency** | **Not idempotent by design**: a non-empty database is refused by a guard at the top. Re-running is a drop-and-retry, never a partial apply. |
| **External tooling** | None. Usable through `mysql`, phpMyAdmin, Adminer, or the bundled installers. |
| **Size** | Under 100 KB (currently ≈ 57 KB), under phpMyAdmin's default 2 MB import limit. |
| **Determinism** | The same sources must produce byte-identical output (no timestamps, LF endings, one trailing newline). |

Unlike the reference's app-root `dmp/cart-system/install.sql`, EcoWealth keeps its bundle at `system/database/install.sql`, next to `schema.sql`, `migrations/` and `seeds/`. That is where the tests, the deploy docs and the installers already look for database assets; a second copy at the app root would be a drift trap.

### 6.2 Contents and expected totals

| Section | Source | Rows/objects | Notes |
|---|---|---|---|
| Schema | `database/schema.sql` | 27 tables + indexes + FKs + the users→packages ALTER | Verbatim from the authoritative file |
| Settings | `database/seeds/001_settings.sql` | 45 keys | Includes `commission_ledger_since` after F-01 |
| Staff | `database/seeds/002_accounts.sql` | 2 users | `admin` + `superadmin`; `member_status = 'active'` after F-09 |
| Starter products | `database/seeds/003_products.sql` | 6 products | Demo copy is marked "replace before launch" |
| Packages | `database/seeds/004_packages.sql` | 1 service product + 2 packages + 18 levels + 10 contents | Package B keeps the premium ladder from migration 015 |
| Install metadata | `database/install/footer.sql` | 3 settings | `install_version`, `install_channel`, `install_completed_at` |
| **Total settings** | | **48** | 44 seed + 1 parity fix + 3 metadata |

Expected verification output on a correct import:

```
tables_created = 27
settings_rows  = 48
staff_rows     = 2
products_rows  = 7
packages_rows  = 2
levels_rows    = 18
contents_rows  = 10
members_rows   = 0
codes_rows     = 0
```

`007` products, not 6: seed 004 inserts the bundled `EW-COLONICS` service product, which belongs to Package B's contents. With the explicit demo option, `members_rows` becomes 5 and the three demo codes/ledger rows appear; the production path in this table has none.

### 6.3 Generation pipeline

```
database/schema.sql ─┐
seeds/001_settings.sql ├─▶ bin/build_install_sql.php ─▶ database/install.sql
seeds/002_accounts.sql │        (+ install/header.sql, install/footer.sql)
seeds/003_products.sql │
seeds/004_packages.sql ┘
```

`bin/build_install_sql.php` rules:

1. Read the five sources plus `database/install/header.sql` and `database/install/footer.sql`.
2. Normalise every source to LF, strip BOM, ensure exactly one trailing newline.
3. Emit, in order: header → schema → settings → accounts → products → packages → footer.
4. Insert a banner before each section (`-- ─── SCHEMA ───`, `-- ─── SETTINGS ───`, …) and a provenance comment with the SHA-256 of each source. **No build timestamp** (it would break `--check`).
5. Normal mode writes `database/install.sql`; `--check` compares bytes and exits 1 with a diff summary.
6. Fail when a source is missing, when a source contains an executable `CREATE DATABASE`/`USE` statement (checked through `SqlScript::containsDatabaseSwitch()`, so prose and comments are ignored), or when a required seed section is empty.

The header (hand-written, included verbatim) contains:

* the generated-file warning and the rebuild command;
* the "empty database only" statement and the reason;
* local and phpMyAdmin usage examples;
* the default staff credentials and a "change immediately" warning;
* the server-support matrix;
* the guard block in 6.4.

The footer contains the verification `SELECT` and the three `INSERT IGNORE` metadata rows. `INSERT IGNORE` is used rather than `ON DUPLICATE KEY UPDATE … VALUES()` because the latter is deprecated in MySQL 8.0.20+ and unsupported by older MariaDB.

**When sources change**, the workflow is: edit `schema.sql` or a seed → run `php bin/build_install_sql.php` → commit both the source and the regenerated bundle. The drift test (IR-2) makes forgetting the second step a suite failure.

### 6.4 Empty-database guard

The header starts with a guard that fails loudly on a non-empty schema:

```sql
-- The bundle is fresh-install only. If the schema contains any table
-- at all, this statement fails with "table doesn't exist" and stops a
-- `mysql < install.sql` run before it can half-apply anything.
SET @ew_present := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE()
);
SET @ew_guard := IF(@ew_present = 0,
  'DO 0',
  'SELECT * FROM `ecowealth_install_requires_an_empty_database`'
);
PREPARE ew_guard FROM @ew_guard;
EXECUTE ew_guard;
DEALLOCATE PREPARE ew_guard;
```

Why a deliberately missing table: MySQL cannot `SIGNAL` outside a stored program, and a `SIGNAL` would require `DELIMITER`/a procedure (rejected by D-IR-02). The error message names the remedy. The guard is belt-and-braces: `schema.sql` already fails on a second import at `ALTER TABLE users ADD CONSTRAINT …`. The guard makes the failure immediate and understandable.

Verified on MySQL 8.4.3: on an empty schema the block prints `guard passed` and continues; with one unrelated table it stops with `ERROR 1146 (42S02)` naming `ecowealth_install_requires_an_empty_database`, and `mysql` exits `1`.

**phpMyAdmin caveat:** phpMyAdmin may continue after the guard error unless "Stop on error" is selected. The subsequent `CREATE TABLE IF NOT EXISTS` statements are no-ops, but the seed `INSERT`s then fail on duplicates and the `ALTER TABLE` fails again. The result is a partially touched database that must be dropped and recreated. The header says so explicitly. The installer paths never rely on this: they check emptiness before running a single statement.

### 6.5 Verification pack

Run after any import (also appended to the footer, so a `mysql` import prints it):

```sql
USE <database>;

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE())                       AS tables_created,
  (SELECT COUNT(*) FROM settings)                          AS settings_rows,
  (SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')) AS staff_rows,
  (SELECT COUNT(*) FROM users WHERE role = 'member')       AS members_rows,
  (SELECT COUNT(*) FROM products)                          AS products_rows,
  (SELECT COUNT(*) FROM packages)                          AS packages_rows,
  (SELECT COUNT(*) FROM package_levels)                    AS levels_rows,
  (SELECT COUNT(*) FROM package_products)                  AS contents_rows,
  (SELECT COUNT(*) FROM reg_codes)                         AS codes_rows;

SELECT key_name, value FROM settings
 WHERE key_name IN ('site_name','install_version','install_channel','install_completed_at')
 ORDER BY key_name;

SELECT username, role, member_status, is_active
  FROM users WHERE role IN ('admin','superadmin') ORDER BY id;
```

The full acceptance query pack (including zero-row checks for every transactional table) is Appendix E.

### 6.6 Local installation

**Path A — MySQL CLI (fastest, exact):**

```bash
# 1. create the schema (once)
mysql -u root -e "CREATE DATABASE ecowealth_sneat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. import the bundle
mysql -u root ecowealth_sneat < database/install.sql

# 3. verify
mysql -u root ecowealth_sneat -e "SHOW TABLES; SELECT COUNT(*) FROM settings;"
```

Then open `http://localhost/ecowealth/system/` and sign in with the seeded staff account.

**Path B — phpMyAdmin:** select the schema → **Import** → choose `database/install.sql` → **Go** → read the footer output → confirm 27 tables.

**Path C — CLI installer (sets real credentials, writes the lock):**

```bash
php bin/db_install.php --check
php bin/db_install.php --database=ecowealth_sneat \
  --admin-user=admin --admin-email=owner@example.com --admin-password-stdin \
  --superadmin-user=superadmin --superadmin-email=owner+super@example.com --superadmin-password-stdin \
  --site-name="EcoWealth" --force
```

(Local config uses `DB_USER=ecowealth`; the installer reads `app/config.local.php`, so run it from the app folder and keep that file present.)

**Expected local smoke:** login succeeds; dashboard loads; `/admin/products` shows 7 products; `/admin/packages` shows 2; `/admin/members` shows 0 members; `/admin/codes` shows 0 codes. With no demo tree, the first member registration uses the primary admin as the default sponsor (the sponsor field may be left blank).

### 6.7 Online installation (shared hosting)

**Path A — phpMyAdmin import (no SSH):**

1. hPanel → **Databases → MySQL Databases** → create the database and user (Hostinger prefixes both, e.g. `u123456_ecowealth`).
2. hPanel → **Databases → phpMyAdmin** → select the new schema.
3. **Import** → upload `database/install.sql` (≈57 KB, well under the 2 MB default; gzip only if the host lowered the limit).
4. Confirm no `CREATE DATABASE`/`USE` error; the footer prints the counts from 6.5.
5. Upload `app/config.local.php` with `APP_ENV=production`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`, and an `INSTALL_KEY` if the web installer will be used.
6. Open the app, sign in, change both staff passwords, delete `install.sql` from the server if it was uploaded there, and follow the go-live checklist.

**Path B — web installer (no SSH, guided credentials):**

1. Upload the application **without** `app/config.local.php` from the local machine; create the production one from `config.example`/deploy.md §7, including a 32+ character `INSTALL_KEY`.
2. Create the empty database and user in hPanel; the web installer imports into an existing schema and never tries to create one (shared hosts rarely grant `CREATE DATABASE`).
3. Open `https://example.com/ecowealth/system/install.php`.
4. Preflight shows each check; the install form asks for the site name and both staff credentials.
5. Submit with the typed `INSTALL` confirmation and the `INSTALL_KEY`.
6. The success page lists the created accounts **without passwords**, the verification counts, and instructs: delete `install.php` now, sign in, change settings.
7. `storage/install.lock` now exists; reopening `install.php` shows "already installed" and refuses.

Hosting limits and fallbacks:

| Limit | Fallback |
|---|---|
| `max_execution_time` < 30 s | Installer calls `set_time_limit(120)`; if the host ignores it, use phpMyAdmin instead. |
| phpMyAdmin import limit < 57 KB | Import as `.sql.gz` or split at the section banners (schema, settings, accounts, products, packages, footer). |
| No `CREATE DATABASE` privilege | Create the schema in hPanel first; the CLI's `--create-database` stays off and the web installer never offers it. |
| WAF blocks `install.php` | Use phpMyAdmin + Path A; delete `install.php`. |
| No `fileinfo`/`mbstring` | Preflight fails with the exact extension name; the app requires both. |

### 6.8 Staff credentials and first login

| Entry path | Usernames | Passwords | Follow-up |
|---|---|---|---|
| Static `install.sql` | `admin`, `superadmin` | Seed defaults `Admin@123`, `Super@123` | **Change both immediately** via Profile → Change password; the header and deploy checklist repeat this. |
| CLI installer | Operator-chosen (defaults `admin`/`superadmin`) | 12–72 chars, must not be a known default, read from stdin/flags | No default credentials ever exist. |
| Web installer | Operator-chosen | Same policy, typed twice | No default credentials ever exist. |

Rules enforced by both installers:

* username: `/^[A-Za-z0-9_.-]{3,50}$/` (same as registration);
* email: `filter_var(..., FILTER_VALIDATE_EMAIL)` and unique against the other staff row;
* password: 12–72 bytes, not in `['Admin@123','Super@123','password','Password123','admin123']`, `password_hash($p, PASSWORD_DEFAULT)`;
* the two usernames/emails must differ.

The login URL after install is `url('login')` (e.g. `/ecowealth/system/login` or `/login` at a document root). There is no forced password-change gate in this build; the go-live checklist is the control.

### 6.9 Upgrade path (explicitly not `install.sql`)

| Situation | Correct action |
|---|---|
| New empty database | `install.sql` or an installer |
| Existing Phase ≤ 15 database, new migration added | `mysql <db> < database/migrations/NNN_*.sql` in numeric order, then run the phase's seed if any |
| Existing database, `install.sql` imported by mistake | The guard fails; if phpMyAdmin continued, restore the pre-import backup or drop/recreate and re-import the correct path |
| App version changed, schema unchanged | No database action; update files and clear caches |

`storage/install.lock` records the bundle checksum and version at install time. It does **not** gate migrations and is not read by the application.

### 6.10 Rollback

`install.sql` has no undo. The supported rollback is:

1. **Before** any import: export/snapshot the empty target or confirm it is disposable.
2. If the import fails midway: `DROP DATABASE <name>; CREATE DATABASE <name> …;` and retry, or restore the snapshot.
3. On shared hosting: hPanel → MySQL Databases → drop the database (after exporting anything you need), recreate it, re-import.
4. Never try to "repair" a half-imported schema by re-running the bundle; the guard exists to stop exactly that.

---

## 7. Reset specification

### 7.1 Semantics

**A reset returns the database to the state a fresh `install.sql` install would have, while keeping the operator's configuration and staff identities.**

| Category | Behaviour |
|---|---|
| Staff (`admin`, `superadmin`) | Kept: id, username, email, password hash, full name, phone, payout accounts, `created_at`, role. Reset: package, sponsor, registration fields, referral code, membership status, wallet buckets, transfer counters, last login. |
| Settings | Kept verbatim, plus `last_reset_at` / `last_reset_by`; install metadata untouched. |
| Audit | Kept by default, plus one `system.db_reset` row; `--clear-audit` removes prior rows first. |
| Catalogue | Kept by default; `--reset-catalogue` deletes and re-seeds products/packages/levels/contents. |
| Members and all transactional data | Deleted: users, carts, addresses, orders (all child tables), proofs, ledger, top-ups, transfers, payouts, commissions, activations, codes, impersonation sessions. |
| Uploads | Proof files deleted; catalogue images only with a catalogue reset. |
| Live revisions | Existing surfaces incremented; missing standard surfaces inserted at revision 1. |

A reset is **not**: an uninstall (schema and settings remain), a schema rollback (migrations are never undone), a backup, or a member-level cleanup.

### 7.2 Options

| Option | CLI | Web field | Default | Effect |
|---|---|---|---|---|
| Keep catalogue | `--keep-catalogue` (default) | "Keep the existing catalogue" checked | **on** | Products/packages/levels/contents survive. |
| Reset catalogue | `--reset-catalogue` | uncheck the above | off | Delete catalogue, re-seed `003_products.sql` + `004_packages.sql`. |
| Generate codes | `--codes=N` (0–50) | number input 0–50 | **0** | Insert N unused registration codes for the chosen package. |
| Code package | `--code-package=ID` | package select | first active package | Package bound to the generated codes; price copied from the package. |
| Demo fixtures | `--demo` | "Seed the demo tree" (hidden in production) | off | Run `005_demo_tree.sql` after the reset (requires the two default packages). |
| Keep audit | `--keep-audit` (default) | "Keep the audit log" checked | **on** | Existing audit rows survive; reset row appended. |
| Clear audit | `--clear-audit` | uncheck the above | off | `DELETE FROM audit_log` before appending the reset row. |
| Keep uploads | `--keep-uploads` | "Keep uploaded files" | off | No upload file is deleted (proofs, or catalogue images when the catalogue is reset); the rows are still deleted. |
| Allow production | `--allow-production` | `DB_RESET_ENABLED` constant | off | Permits a reset when `APP_ENV=production` (still superadmin-only on web). |
| Confirmation | `--confirm=NAME` / typed prompt | typed `RESET` (prod: DB name) | — | Must match the database name (CLI/prod web) or `RESET` (dev web). |
| Dry run | `--dry-run` | — | off | Preview + statement plan; zero writes. |
| Verify | always | always | on | Four reconciliations + invariant checks. There is no `--no-verify`; a tool that cannot prove its result must not report success. |
| Environment *(internal)* | — | — | `APP_ENV` | `options['env']` lets the tests exercise the production guard without redefining a constant; no CLI/web flag exposes it. |

### 7.3 Algorithm

| Step | Action | Count/log key |
|---:|---|---|
| 0 | Acquire advisory lock; refuse if another reset holds it | `lock` |
| 1 | Preflight: 27 required tables present, ≥1 staff account, target name safe | `preflight` |
| 2 | Snapshot counts for the report | `counts_before` |
| 3 | `BEGIN` | — |
| 4 | Delete proof/invoice/shipment/event/item children, then orders | `orders` |
| 5 | Delete cart items, carts | `carts` |
| 6 | Delete addresses | `addresses` |
| 7 | Delete ledger, transfers, top-ups | `wallet` |
| 8 | Delete payout requests | `payouts` |
| 9 | Delete commissions, activations | `commissions` |
| 10 | Delete registration codes | `codes` |
| 11 | Delete remember-me tokens (all sessions) | `sessions` |
| 12 | Delete impersonation sessions | `sessions` |
| 13 | Delete member users (staff survive) | `members` |
| 14 | Reset staff rows to install defaults | `staff` |
| 15 | Optional: delete catalogue, re-seed products/packages | `catalogue` |
| 16 | Optional: clear audit log | `audit` |
| 17 | Append `system.db_reset` audit row | `audit` |
| 18 | Upsert `last_reset_at` / `last_reset_by` | `settings` |
| 19 | Bump live revisions: `UPDATE … revision + 1`, insert missing standard surfaces at 1 | `live` |
| 20 | `COMMIT` | — |
| 21 | `ALTER TABLE … AUTO_INCREMENT = 1` on empty tables (best effort) | `auto_increment` |
| 22 | Optional: demo tree (`--demo`, never production) | `demo` |
| 23 | Optional: insert N registration codes | `codes` |
| 24 | Verify zero counts, staff state, catalogue counts, four reconciliations | `verify` |
| 25 | Release lock; return report | `lock` |

The exact transactional block (copy for implementation) is Appendix A. The critical invariants:

* No `SET FOREIGN_KEY_CHECKS = 0` anywhere.
* Every child table is deleted before its parent, in the order above.
* The only `UPDATE` on `users` is the staff reset; members are deleted, never updated.
* `live_revisions` is bumped inside the transaction so a failed reset leaves the old revisions untouched and a committed reset always changes every open page's value.
* `AUTO_INCREMENT` runs after `COMMIT` because MySQL commits implicitly on DDL (D-IR-14).

### 7.4 Staff reset fields

```sql
UPDATE users SET
  package_id           = NULL,
  sponsor_id           = NULL,
  reg_code_id          = NULL,
  reg_paid_by          = NULL,
  reg_payment_method   = 'standard',
  activated_at         = NULL,
  referral_code        = NULL,
  member_status        = 'active',
  is_active            = 1,
  ewallet_balance      = 0.00,
  withdrawable_balance = 0.00,
  ewallet_sent_today   = 0.00,
  ewallet_sent_this_week = 0.00,
  last_login_at        = NULL
WHERE role IN ('admin', 'superadmin');
```

| Column | Reset to | Why |
|---|---|---|
| `package_id`, `sponsor_id` | `NULL` | Staff return to the company root; no package purchase, no upline. |
| `reg_code_id`, `reg_paid_by`, `reg_payment_method`, `activated_at` | `NULL` / `'standard'` | No registration history survives. |
| `referral_code` | `NULL` | Old referral links should not resolve to a reset staff account. The seed accounts start with `NULL`; a new code can be generated from the profile if needed. |
| `member_status`, `is_active` | `active`, `1` | Staff must be able to sign in; this matches the corrected seed 002 (F-09). |
| `ewallet_balance`, `withdrawable_balance` | `0.00` | No ledger rows exist to justify a balance. |
| `ewallet_sent_today`, `ewallet_sent_this_week` | `0.00` | Transfer counters reset with the transfer tables. |
| `last_login_at` | `NULL` | Clean install state. |
| `full_name`, `email`, `phone`, `password_hash`, payout accounts, `created_at`, `role` | **unchanged** | Identity and access survive; the operator does not lose their way back in. |

The reset aborts **before** deleting anything when no staff row exists, because a database with no way to sign in is not a clean state.

### 7.5 Catalogue reset and re-seed

When `--reset-catalogue` is set, inside the same transaction, after `reg_codes`/`activations`/`commissions` are gone:

```sql
DELETE FROM package_products;
DELETE FROM package_levels;
DELETE FROM packages;
DELETE FROM products;
```

Then the service re-runs the two seed files with `SqlScript`:

1. `database/seeds/003_products.sql` (6 products)
2. `database/seeds/004_packages.sql` (the `EW-COLONICS` service product, 2 packages, 18 levels, 10 contents)

Notes:

* `package_products` must be deleted before `products` (`fk_pkgprod_prod` is `RESTRICT`), and order/cart items are already gone by then.
* The seeds are idempotent (`ON DUPLICATE KEY UPDATE name = name`) and use natural keys (`sku`, `slug`), so they also work after a partial manual cleanup. If the project's seed files have been customized and no longer create `package-a`/`package-b`, the reset still succeeds with that catalogue and the report notes the missing defaults.
* Ids continue from the previous `AUTO_INCREMENT` (D-IR-15); every reference uses the new ids, and the parity test compares by `slug`/`sku`, not by id.
* `--demo` requires both default packages. When the catalogue is kept, the preflight checks them and refuses before deleting anything; when `--reset-catalogue` is set, the standard seeds provide them, and a customized seed set that omits them is reported after the re-seed (the demo step re-checks and refuses before writing any demo row).

### 7.6 Registration-code generation

`--codes=N` (web numeric field, 0–50, default 0):

1. Resolve the package: `--code-package` / form value, else the lowest-sorted active package. No active package ⇒ warning, generate none, reset still succeeds.
2. For each code: `CodeService::randomCode()` (12 characters, 3 groups, unambiguous alphabet), then a uniqueness check and retry (40 attempts, same as `CodeService`), then:
   ```sql
   INSERT INTO reg_codes (code, package_id, price, code_type, status, created_by, expires_at)
   VALUES (?, ?, ?, 'registration', 'unused', ?, NULL)
   ```
   with `price` copied from `packages.price` (formatted to 2 decimals) and `created_by` = the primary admin id.
3. One `code.generate` audit row records the batch, price, count and first/last code.
4. The report returns the codes; the web console stores them in the session report and renders copy-on-click chips.

The service does **not** call `CodeService::generate()`: that method writes an audit row and calls `Live::touch()` through the global `db()`, which would escape the injected target in tests and split the batch across transactions. It reuses only the pure `randomCode()` helper, so the format cannot drift.

### 7.7 Upload cleanup

| Directory | Default | Rules |
|---|---|---|
| `public/uploads/proofs/` | Delete files | Everything except `.gitkeep` and `.htaccess`; `is_file()` only, never follow a directory or symlink; count deleted/failed. |
| `public/uploads/products/` | Keep | Cleared only with `--reset-catalogue` (files referenced by deleted products become orphans). |
| `public/uploads/packages/` | Keep | Same. |
| `public/assets/` | Never touched | Seeded catalogue images live here and are not user uploads. |
| `storage/logs/`, `storage/locks/` | Keep | Operational, not business data. |

`--keep-uploads` skips every upload deletion (proofs and, when the catalogue is reset, product/package images). A failed `unlink()` is a warning (file-permission issue), not a reset failure; the report lists the path.

The `UPLOAD_ROOT` constant is honoured when defined (the test harness points it at scratch space), so the tests can create fake proof files and assert their removal without touching `public/uploads/`.

### 7.8 Audit and reset metadata

Default (`keep_audit = true`):

```sql
INSERT INTO audit_log (actor_id, actor_role, action, subject_type, subject_id, meta_json, ip)
VALUES (?, ?, 'system.db_reset', 'database', NULL, ?, ?);
```

* `actor_id`/`actor_role`: the signed-in staff member on web; `NULL`/`'system'` on CLI.
* `meta_json`: tool (`cli`/`web`), database name, options, counts before/after, catalogue/code/demo flags, duration, and whether the audit was cleared.
* `ip`: `Request::ip()` on web, `127.0.0.1` on CLI (the column is `VARCHAR(45)`; do not pass `'cli'` into an IP-shaped column without documenting it — `127.0.0.1` keeps the shape valid).

With `--clear-audit`: `DELETE FROM audit_log` runs before the insert, so the trail starts with the reset row itself. The report shows `audit_rows_before` and `audit_cleared`.

Settings written/updated (one statement per key, with two positional placeholders — native PDO prepares cannot bind the same named parameter twice):

```sql
INSERT INTO settings (key_name, value) VALUES ('last_reset_at', ?)
  ON DUPLICATE KEY UPDATE value = ?;
INSERT INTO settings (key_name, value) VALUES ('last_reset_by', ?)
  ON DUPLICATE KEY UPDATE value = ?;
```

Each is executed with `[value, value]`. This avoids `VALUES()` (deprecated in MySQL 8.0.20+) and the named-parameter reuse problem in one go. `last_reset_by` is the staff username on web and `'cli'` on the command line. The CLI/web front-ends call `setting_cache_clear()` after `run()` (the service itself avoids the global helpers so it stays testable on any PDO).

Install metadata key `install_version`/`install_channel`/`install_completed_at` is **not** modified by a reset: the application is still the same installation.

### 7.9 Live revisions

Inside T1 (both statements are transactional, so a rollback restores the previous revisions):

```sql
-- 1. Every surface that already exists moves forward.
UPDATE live_revisions SET revision = revision + 1;

-- 2. Any standard surface that has never been written starts at 1.
INSERT IGNORE INTO live_revisions (surface, revision) VALUES
  ('admin.dashboard', 1), ('admin.products', 1), ('admin.packages', 1),
  ('admin.orders', 1), ('admin.members', 1), ('admin.activations', 1),
  ('admin.codes', 1), ('admin.commissions', 1), ('admin.wallets', 1),
  ('admin.payouts', 1), ('admin.reports', 1), ('admin.settings', 1),
  ('admin.audit', 1), ('admin.slogin', 1), ('shop.catalog', 1),
  ('shop.packages', 1), ('member.genealogy', 1), ('config', 1);
```

An existing surface therefore always changes (for example 1 814 → 1 815), so a client holding the old ETag revalidates. On a fresh database with no revisions, all 18 rows are inserted at 1. After a successful reset the web front-end calls `Live::publish($surfaces)`; on CLI it is a no-op because there is no session.

### 7.10 Auto-increment handling

After commit, for each table that is now empty and whose rows the reset deleted:

```
shop_orders, shop_order_items, shop_order_events, shop_payment_proofs,
shop_shipments, shop_invoices, shop_invoice_items, carts, cart_items,
addresses, ewallet_ledger, ewallet_admin_topups, ewallet_transfers,
payout_requests, commissions, activations, reg_codes, impersonation_log,
remember_tokens
```

Each `ALTER TABLE <t> AUTO_INCREMENT = 1` is wrapped individually: a failure is logged with the table name and does not abort the reset. `users` is never altered (staff rows keep their ids). Catalogue tables are not reset because they may have just been re-seeded (D-IR-15). The report includes `auto_increment: {reset: n, failed: n}`.

Verification reads `information_schema.tables.auto_increment` and asserts that the next id is `1` for the tables in the list; a `NULL` means MySQL considers the table empty with no counter, which also passes.

### 7.11 Report and verification

```php
[
  'tool'      => 'db_reset',
  'version'   => 1,
  'ok'        => true,
  'database'  => 'ecowealth_sneat',
  'started_at'=> '2026-11-11 17:20:00',
  'duration_ms' => 1840,
  'options'   => [ 'keep_catalogue' => true, 'codes' => 5, … ],
  'steps'     => [
    ['key' => 'orders', 'label' => 'Shop orders', 'status' => 'ok', 'deleted' => 16, 'ms' => 41],
    // … one entry per step in 7.3
  ],
  'counts_before' => ['members' => 24, 'orders' => 16, …],
  'counts_after'  => ['members' => 0, 'orders' => 0, …],
  'staff'     => ['reset' => 2, 'usernames' => ['admin','superadmin']],
  'audit'     => ['kept' => true, 'rows_before' => 1814, 'cleared' => false],
  'auto_increment' => ['reset' => 19, 'failed' => 0],
  'codes'     => ['generated' => 5, 'package' => 'Package A', 'list' => ['ABCD-EFGH-JKLM', …]],
  'verification' => [
    'ok' => true,
    'checks' => [
      ['key'=>'zero_transactional','ok'=>true,'detail'=>'0 rows across 20 tables'],
      ['key'=>'staff','ok'=>true,'detail'=>'2 staff, balances zero, package/sponsor null'],
      ['key'=>'catalogue','ok'=>true,'detail'=>'7 products, 2 packages, 18 levels, 10 contents'],
      ['key'=>'wallets','ok'=>true,'detail'=>'WalletService::reconcile clean'],
      ['key'=>'commissions','ok'=>true,'detail'=>'CommissionService::reconcile clean'],
      ['key'=>'transfers','ok'=>true,'detail'=>'TransferService::reconcile clean'],
      ['key'=>'payouts','ok'=>true,'detail'=>'PayoutService::reconcile clean'],
      ['key'=>'live','ok'=>true,'detail'=>'18 standard surfaces (existing incremented, missing at 1)'],
      ['key'=>'auto_increment','ok'=>true,'detail'=>'19 counters at 1 (+ audit_log when cleared)'],
    ],
  ],
  'warnings' => [],
]
```

Verification is mandatory and runs on the same PDO. A failing check does not "undo" the reset (it already committed); it makes `ok = false`, prints the failing check and exits `1`, so CI and an operator both see it. The correct response is to read the detail, restore the backup if the data is wrong, or re-run once if the failure is a transient post-step (for example a code-generation collision).

**Verification checklist**

| Check | Assertion |
|---|---|
| Members | `SELECT COUNT(*) FROM users WHERE role='member'` = 0 (5 with `--demo`) |
| Every transactional table | 0 rows, except `audit_log` (kept, grows by 1), `live_revisions` (18 bumped surfaces) and `reg_codes` (the generated count); with `--demo`, the five demo users, three demo codes and three adjustment ledger rows are also expected. Full list in Appendix E |
| Staff | ≥1 row; every staff row has `package_id IS NULL`, `sponsor_id IS NULL`, both balances `0.00`, `member_status='active'`, `is_active=1` |
| Catalogue | kept: counts unchanged from `counts_before`; reset: 7 products, 2 packages, 18 levels, 10 contents |
| Codes | generated count (plus 3 with `--demo`); every generated code is `unused` and package-bound |
| Audit | when kept: `>= before`; always: exactly one new `system.db_reset` row |
| Wallets | `WalletService::reconcile()['ok'] === true` |
| Commissions | `CommissionService::reconcile()['ok'] === true` |
| Transfers | `TransferService::reconcile()['ok'] === true` |
| Payouts | `PayoutService::reconcile()['ok'] === true` |
| Live revisions | every standard surface exists; pre-existing revisions were incremented; missing ones are 1 |
| Auto-increment | listed tables report next id 1 (or `NULL`) |

### 7.12 Failure and recovery

| Failure point | State | Recovery |
|---|---|---|
| Lock not acquired | Nothing changed | Wait for the other reset/install; re-run. |
| Preflight refuses (tables missing/no staff) | Nothing changed | Repair the schema (install/migrations) or restore staff, then retry. |
| Any step inside T1 | Rolled back; nothing changed | Read the error, fix the cause, re-run. The report says "no changes committed". |
| After commit, `AUTO_INCREMENT` fails | Data reset; counters may be high | Non-fatal; run the `ALTER` manually or accept the gap. |
| After commit, code generation fails | Data reset; no/partial codes | The insert batch is its own transaction; re-run `--codes=N` is **not** a reset (there is no partial-code mode) — run a full reset again or generate codes from `/admin/codes`. |
| Verification fails | Data reset and committed | Read the failing check; run `php bin/wallet_reconcile.php`; restore the pre-reset backup if money is wrong. Never patch `users` balances by hand. |
| Web request times out mid-request | Depends on the point: T1 rolls back when the connection drops; post-commit steps may be incomplete | Open the console, read `last_reset_at`, run the preview; if the DB is clean but codes/live are missing, finish those from the UI (codes page) or re-run. |

### 7.13 Preview and dry run

`preview()` is read-only and returns: database/server version, row counts for every table in 2.4, staff accounts (id, username, role), packages (id, name, price, status), `last_reset_at`, and warnings (no staff, no active package, production target, demo unavailable, uploads not writable).

`dryRun()` adds: the ordered statement plan (step key + statement count), the estimated deletes, and `"writes": 0`. It still connects and reads counts; it never starts a transaction.

Example CLI preview:

```
EcoWealth database reset
  target : ecowealth_sneat @ 127.0.0.1:3306 (MySQL 8.4.3)
  members: 24    staff: 2    orders: 16    ledger: 48    codes: 19
  catalogue: 10 products / 4 packages (kept)
  keep audit: yes (1814 rows)   generate codes: 0   demo: no
  last reset: 2026-11-10 09:12:44 by admin
Type "ecowealth_sneat" to continue, or Ctrl+C to abort:
```

### 7.14 Web console UX

**States**

| State | Trigger | Rendering |
|---|---|---|
| Hidden | `APP_ENV=production` and `DB_RESET_ENABLED !== true` | `View::error(404)` (looks like any unknown URL) |
| Sign-in redirect | Not signed in | `require_admin()` → login |
| Forbidden | Signed in as member, or an impersonation session | `View::error(403)`; impersonation explicitly refused before the guard |
| Ready | GET, allowed, unlocked | Stats + what-will-be-cleared + options + typed confirmation |
| Validation error | POST with bad CSRF/confirmation/quantity/backup ack | Same page with field errors, no execution |
| Success | POST ran and `ok=true` | Green banner, step log, verification checklist, code chips, "sign in as admin", "Reset again" |
| Partial/failure | POST ran and `ok=false` / exception | Red banner, failing step, rollback notice when T1 failed, verification detail, "Back to the console" |

**Layout** (mirrors the reference's operator experience, not its markup):

```
┌──────────────────────────────────────────────────────────────┐
│  ⚠ DATABASE TOOL                                            │
│  Database Reset                                              │
│  EcoWealth · ecowealth_sneat                                 │
├──────────────────────────────────────────────────────────────┤
│  📊 Current state                                            │
│  [ 24 members ] [ 19 unused codes ] [ 16 orders ] [ 48 ledger]│
│  ⚠ Development/testing tool. Permanently deletes member data. │
├──────────────────────────────────────────────────────────────┤
│  🗑 What will be cleared                                     │
│  • all member accounts            • all orders & invoices    │
│  • all commissions & activations  • all payouts & holds      │
│  • all wallet ledger rows         • all registration codes   │
│  • all payment proof images       • all S-Login sessions     │
│  • staff balances/counters reset  • settings kept            │
├──────────────────────────────────────────────────────────────┤
│  ⚙ Options                                                   │
│  Type RESET to confirm: [__________]                         │
│  [x] Keep the existing catalogue                             │
│  Generate codes: [0]  for package: [Package A — ₱4,498.00]   │
│  [x] Keep the audit log                                      │
│  [ ] Keep uploaded files                                      │
│  [ ] Seed the demo tree (development only)                   │
│  [ ] I have a backup or this database is disposable           │
│  [ 🗑 Reset database ]  (disabled until RESET + backup ack)   │
└──────────────────────────────────────────────────────────────┘
```

Production variant: the confirmation phrase is the database name (not `RESET`), the demo checkbox is absent, and the page header carries a red "PRODUCTION" band.

**POST/redirect/get:** the POST stores the report (including generated codes) in `$_SESSION['db_reset_report']` and redirects to `reset.php?done=1`; refresh re-renders the same report, never re-runs. The report is cleared when a new reset starts or when the operator clicks "Done".

### 7.15 Safety gates

| Target | Required |
|---|---|
| Local CLI | typed database name at the prompt, **or** `--confirm=<db>` (with or without `--force`); advisory lock |
| Production CLI | all of the above **plus** `--allow-production`; database name confirmation is mandatory |
| Local web (`APP_ENV=development`) | signed-in `admin` (not impersonated) · CSRF · typed `RESET` · backup acknowledgement |
| Production web | `DB_RESET_ENABLED=true` · signed-in `superadmin` · CSRF · typed database name · backup acknowledgement |
| Any target with no staff row | refused before any delete |
| Any target missing required tables | refused before any delete |
| Concurrent tool run | refused by the advisory lock |

---

## 8. Phase plan (IR-0 … IR-8)

### 8.0 Summary

| Phase | Title | Produces | Depends on | Estimate |
|---|---|---|---|---:|
| **IR-0** | Contract, baseline and safety rails | Baseline record, backup, agreed names/flags | — | 0.5 d |
| **IR-1** | Shared database plumbing and test harness | `DbTarget`, `SqlScript`, exception, scratch-DB helpers, tests | IR-0 | 1.5 d |
| **IR-2** | Production install bundle | `database/install.sql`, builder, seed fixes, drift/parity tests, local/online import docs | IR-1 | 2 d |
| **IR-3** | CLI installer | `DatabaseInstaller`, `bin/db_install.php`, install lock, tests | IR-2 | 1.5 d |
| **IR-4** | Web installer | `public/install.php`, `INSTALL_KEY`, shared CSS, online install path | IR-3 | 1.5 d |
| **IR-5** | Reset engine and CLI | `DatabaseResetService`, `bin/db_reset.php`, verification, tests | IR-1, IR-2 | 3 d |
| **IR-6** | Reset web console | `public/reset.php`, `DB_RESET_ENABLED`, browser evidence | IR-5 | 2 d |
| **IR-7** | Demo fixtures and parity | `--demo` on both installers and reset, reset≡fresh-install tests | IR-3, IR-5 | 1.5 d |
| **IR-8** | Hardening, documentation, go-live | Docs, QA evidence, security review, sign-off | all | 2 d |
| | | | **Total** | **15.5 d** |

AI-assisted implementation typically compresses this by 30–50%; the gates do not compress.

---

### IR-0 — Contract, baseline and safety rails

**Goal.** Record the exact starting point, protect the developer database, and freeze the names/flags that every later phase depends on. No product code.

**Builds on.** Nothing. This is the reference point for every rollback.

**Deliverables**

| Artefact | Location |
|---|---|
| Baseline record (versions, table list/counts, settings count, suite result, commit) | `docs/qa/evidence/install-reset/baseline.txt` |
| A pre-work database dump | `backups/pre-install-reset-<date>.sql` (gitignored) |
| Agreed file/flag contract | Section 5.2 and Appendices C–D of this document (reviewed) |
| Evidence folder + naming convention | `docs/qa/evidence/install-reset/README.md` |

**Tasks**

* **IR-0-T1** Capture the baseline:

  ```bash
  php -v
  mysql --version
  git rev-parse --short HEAD
  mysql -u <user> -p -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='<db>'"
  php tests/run.php
  ```

  Record the suite's `Cases / Passed / Failed` line verbatim. The expected baseline is PHP 8.3.x, MySQL 8.4.x, 27 tables, 722 cases, 0 failures.
* **IR-0-T2** Dump the development database to `backups/` (not tracked). `mysqldump --single-transaction --routines --triggers <db> > backups/pre-install-reset-YYYYMMDD.sql`. Verify the file is non-empty and restores into a scratch schema.
* **IR-0-T3** Review decisions D-IR-01…18 and the destination file names (`database/install.sql`, `bin/db_install.php`, `public/install.php`, `DatabaseResetService.php`, `bin/db_reset.php`, `public/reset.php`). Once IR-2 starts, a rename ripples through tests, docs and the go-live checklist.
* **IR-0-T4** Create `docs/qa/evidence/install-reset/` with a README that fixes the screenshot names used in IR-4/IR-6/IR-8 (`install-preflight.png`, `install-success.png`, `reset-form.png`, `reset-success.png`, `reset-production-404.png`, …).
* **IR-0-T5** Add the tool lock file to `.gitignore` now (the file is created in IR-3): `system/storage/install.lock`.

**Test gate**

```bash
php tests/run.php | tail -n 3
# Expected: Cases: 722   Passed: 722   Failed: 0
```

Manual: the dump restores into `ecowealth_sneat_restore_test` and `SHOW TABLES` returns 27.

**Exit criteria**

- [ ] Baseline file committed; suite green; dump verified.
- [ ] Decisions and names signed off (comment on this document).
- [ ] `system/storage/install.lock` ignored.
- [ ] No application file changed yet.

**Rollback.** Delete the baseline/evidence files; nothing else changed.

**Estimate.** 0.5 day.

---

### IR-1 — Shared database plumbing and test harness

**Goal.** Give install, reset and their tests one safe way to connect to a chosen schema and execute a SQL file. This phase changes **no** application behaviour; it only adds classes and tests.

**Builds on.** IR-0's baseline. The classes are used by every later phase.

**Deliverables**

| File | Content |
|---|---|
| `app/Core/DbTarget.php` | Connections, database existence/create, table listing, identifier validation, error hints |
| `app/Core/SqlScript.php` | Quote/comment-aware statement parser, executor, checksum |
| `app/Services/DatabaseToolException.php` | Typed exception with `kind`, `context`, `hint` |
| `tests/Cases/DbTargetTest.php` | Class tests against `*_test` schemas |
| `tests/Cases/SqlScriptTest.php` | Parser/executor tests |
| `tests/bootstrap.php` | `test_scratch_database()`, `test_table_list()`, `test_column_list()`, `test_index_list()`, `test_foreign_key_list()` |

**Tasks**

* **IR-1-T1 — `DbTarget`.** Implement the API in 5.5. Details that matter:
  * `connect(null)` uses `mysql:host=…;port=…;charset=utf8mb4` (no `dbname`); `connect($db)` adds `dbname`.
  * PDO options exactly match `app/database.php`; add `PDO::ATTR_TIMEOUT => 5`.
  * `SET NAMES utf8mb4`, `SET time_zone = <quoted DB_TIMEZONE>` after connect.
  * Error map by SQLSTATE/driver code: `1045`, `1049`, `2002`, `1130`, and a generic fallback. The exception's `hint` is operator-facing; the raw message goes to `error_log('[ecowealth] db target: ' . $e->getMessage())`.
  * `assertSafeDatabaseName()` regex `/^[A-Za-z0-9_]{1,64}$/`; throw `DatabaseToolException::invalidName()` otherwise.
  * `create()` executes `CREATE DATABASE \`<validated>\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`.
  * `tables()` reads `information_schema.tables` for the given schema, sorted.
* **IR-1-T2 — `SqlScript`.** Implement the parser state machine described in 5.5. Add a hard cap (for example 20 000 statements) so a malformed file cannot loop. `run()` wraps `PDO::exec()` per statement and rethrows with `statement`, `line`, `sql` (truncated to 200 chars) and the driver code. `containsDatabaseSwitch()` detects `CREATE DATABASE`/`USE` case-insensitively outside comments.
* **IR-1-T3 — `DatabaseToolException`.** Static constructors: `usage()`, `preflight()`, `refused()`, `locked()`, `notEmpty()`, `sql()`, `verify()`, `invalidName()`. Each carries a `kind` string and a context array; `getMessage()` never includes a password or DSN.
* **IR-1-T4 — test helpers.** The developer's MySQL user (`DB_USER`, `ecowealth` locally) has `ALL` on individual schemas only — it cannot create arbitrary databases. Provision **two** scratch schemas once, then let the helper recreate them:

  ```sql
  -- once, as root (Laragon) or an admin MySQL account.
  -- Adjust the user/host to app/config.local.php.
  CREATE DATABASE IF NOT EXISTS ecowealth_sneat_tool_a_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE IF NOT EXISTS ecowealth_sneat_tool_b_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON ecowealth_sneat_tool_a_test.* TO 'ecowealth'@'127.0.0.1';
  GRANT ALL PRIVILEGES ON ecowealth_sneat_tool_b_test.* TO 'ecowealth'@'127.0.0.1';
  FLUSH PRIVILEGES;
  ```

  ```php
  /** Recreate one of the pre-provisioned scratch schemas. */
  function test_scratch_database(string $slot = 'tool_a'): array
  {
      if (!in_array($slot, ['tool_a', 'tool_b'], true)) {
          fail('Unknown scratch slot: ' . $slot);
      }

      $name = 'ecowealth_sneat_' . $slot . '_test';   // ends in _test
      $pdo  = test_pdo();

      try {
          test_recreate_database($pdo, $name);         // DROP + CREATE + USE
      } catch (PDOException $e) {
          fail(
              'Cannot recreate ' . $name . ': ' . $e->getMessage() . "\n"
              . 'Provision it once (see IR-1-T4) and grant ALL to ' . DB_USER . '.'
          );
      }

      return [$pdo, $name];
  }
  ```

  The schemas are **not dropped at shutdown**: dropping them would remove the grants and break the next run. Each call recreates them, so a failed case never leaks into the next one. `test_table_list()`, `test_column_list()`, `test_index_list()` and `test_foreign_key_list()` return sorted, stable arrays via `information_schema` (not `SHOW`, so the output is identical across MySQL/MariaDB minor versions).

  Test files pair up: `tool_a` for `SqlScriptTest`, `DbTargetTest` and `InstallSqlTest`'s import; `tool_b` for `DbResetTest` and the parity builds. `InstallSqlTest` compares against the harness's fresh `DB_NAME` (read-only) instead of building a third schema.
* **IR-1-T5 — tests.** At minimum:

  | Case | Assertion |
  |---|---|
  | split plain statements | 3 statements, correct line numbers |
  | semicolon inside a single-quoted string | not split |
  | `--` inside a string vs a comment | only the comment is stripped |
  | `#` comment and `/* … */` block | stripped |
  | backtick identifier containing `;` | not split |
  | `DELIMITER` | rejected with a clear message |
  | `CREATE DATABASE` / `USE` detection | `containsDatabaseSwitch()` true |
  | failing statement context | exception names statement index, line, driver code |
  | run `schema.sql` into a scratch DB | 27 tables, no exception |
  | checksum stability | same content ⇒ same hash; different ⇒ different |
  | `DbTarget` exists/create/tables | pre-provisioned schema recreated, listed, emptied |
  | unsafe database name | rejected before any SQL |
  | unknown database hint | contains "create it first", not the password |

* **IR-1-T6 — regression.** Run the full suite; no existing case may change behaviour. The new classes are inert until IR-2/IR-3 call them.

**Test gate**

```bash
php tests/run.php SqlScript
php tests/run.php DbTarget
php tests/run.php
# Expected: the two new groups pass; the full suite is still 722 + new cases, 0 failures.
```

Manual: after the run, `SHOW DATABASES LIKE 'ecowealth_sneat_tool_%_test'` still shows both provisioned schemas (they are recreated, not removed).

**Exit criteria**

- [ ] Parser rules each have a test, including the failure-context case.
- [ ] No `db()` call inside `DbTarget`/`SqlScript`.
- [ ] No application file modified.
- [ ] `Schema.sql` imports through `SqlScript` in a scratch schema.

**Rollback.** Delete the three classes, the two test files and the bootstrap helpers. No app path references them yet.

**Estimate.** 1.5 days.

---

### IR-2 — Production install bundle, seed fixes and parity tests

**Goal.** Produce the single production installation artefact and prove that it matches the application's own fresh schema and seed data. This is the first phase with an operator-visible deliverable.

**Builds on.** IR-1's runner and scratch schemas; IR-0's baseline.

**Deliverables**

| File | Change |
|---|---|
| `database/seeds/001_settings.sql` | Add `commission_ledger_since` (F-01) |
| `database/seeds/002_accounts.sql` | Add `member_status = 'active'` to both staff rows (F-09) |
| `database/install/header.sql` | New: warnings, usage, guard |
| `database/install/footer.sql` | New: verification SELECT + install metadata |
| `bin/build_install_sql.php` | New: deterministic builder, `--check`, `--stdout` |
| `database/install.sql` | New: generated, committed |
| `.gitattributes` | New: `*.sql text eol=lf` so the generated bundle is byte-stable |
| `tests/Cases/SeedParityTest.php` | New |
| `tests/Cases/InstallSqlTest.php` | New |
| `system/README.md` | Quick start path A/B, counts, demo note |
| `docs/deploy.md` | §5B becomes "import install.sql" |

**Tasks**

* **IR-2-T1 — seed fixes.**
  * `001_settings.sql`: add `('commission_ledger_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))` inside the existing settings `INSERT`. The `ON DUPLICATE KEY UPDATE key_name = key_name` clause keeps an existing value untouched.
  * `002_accounts.sql`: add `member_status` to the column list and `'active'` to both rows. This makes a fresh install match what a reset writes (7.4).
  * Re-run the suite and confirm no case depends on the old 44-key count or pending staff status. Adjust only tests that assert those values, with a comment pointing at this phase. One stale comment is known: `tests/Cases/TransferTest.php:786` says "member_status defaults to pending"; the fixture actually inserts `active`, and seed 002 will too — update the comment.
* **IR-2-T2 — header.** Write `database/install/header.sql` with the banner, usage, support matrix, guard from 6.4 and the default-credential warning.
* **IR-2-T3 — footer.** Write `database/install/footer.sql` with the verification `SELECT` and:
  ```sql
  INSERT IGNORE INTO settings (key_name, value) VALUES
    ('install_version', '1.0.0'),
    ('install_channel', 'install.sql'),
    ('install_completed_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));
  ```
* **IR-2-T4 — builder.** `bin/build_install_sql.php`:
  * CLI only; `--check`, `--stdout`, `--help`.
  * Reads the five sources + header + footer from paths relative to `__DIR__/..`.
  * Normalises LF/BOM/trailing newline; emits section banners and a `-- source: path sha256=…` provenance block.
  * Refuses when a source contains an executable `CREATE DATABASE`/`USE` statement (via `SqlScript::containsDatabaseSwitch()`, not a raw substring search — the headers contain the word "usage") or when `schema.sql` has no `CREATE TABLE`.
  * `--check` prints the first differing line and exits 1.
  * Adds/pins `.gitattributes` with `*.sql text eol=lf` so a Windows checkout cannot make the committed bundle look stale.
* **IR-2-T5 — generate and commit** `database/install.sql`. Confirm the header/footer are present and the file is < 100 KB.
* **IR-2-T6 — `SeedParityTest`.** Static, fast, no database:
  * parse every `database/migrations/*.sql` (comments stripped) for settings keys inserted (`INSERT INTO settings … SELECT 'key'` and `VALUES ('key', …)` forms) and assert each exists in `seeds/001_settings.sql`;
  * assert seed 001 has no duplicate keys and no duplicate `key_name` in the multi-row insert;
  * assert every seed-001 key appears in `database/install.sql`;
  * assert `SqlScript::fromFile('database/install.sql')->containsDatabaseSwitch()` is false and the file parses without `DELIMITER`;
  * write this test first and run it against the current seed: it must fail on `commission_ledger_since` (F-01), then pass after IR-2-T1.
* **IR-2-T7 — `InstallSqlTest`.** Database-backed, scratch schema:
  1. `build_install_sql.php --check` returns 0.
  2. Import `database/install.sql` into `ecowealth_sneat_tool_a_test` through `SqlScript` (recreated by `test_scratch_database('tool_a')`).
  3. Compare against the harness's fresh `DB_NAME`: table list, every table's columns, every table's indexes, every foreign key (name, columns, referenced table/columns, delete rule). Reuse the bootstrap introspection helpers rather than `SHOW`.
  4. Compare settings: every key present in the harness fresh `DB_NAME` exists in the import with an equal value, except the dynamic `commission_ledger_since`; the import may additionally carry the three `install_*` metadata keys. This direction matters: the harness DB has no install metadata, so an equality assertion would be wrong.
  5. Compare catalogue by natural key: the seven seeded SKUs (`EW-COFFEE`, `EW-IMMUNE`, `EW-SPIRULINA`, `EW-SERPENTINA`, `EW-KOJIC`, `EW-STONE`, `EW-COLONICS`) with price/stock/status, the two package slugs (`package-a`, `package-b`) with price/bonus, and their 18 levels / 10 contents. Compare natural keys, not raw table counts, so a committed test fixture in the harness DB cannot make the assertion brittle.
  6. The imported database specifically: 2 staff rows with roles `admin`/`superadmin`, `member_status='active'`, and 0 member rows. (Check this on the import, not on the harness `DB_NAME`, which may carry a committed fixture.)
  7. Re-importing the bundle into the now non-empty schema throws `DatabaseToolException` (guard), and the schema still counts 27 tables.
* **IR-2-T8 — docs.** `system/README.md` quick start: replace the six imports with the one bundle, keep a "want demo data?" line for `005_demo_tree.sql`, and add the expected counts. `docs/deploy.md` §5B: one import; note that `install.sql` creates no demo data.
* **IR-2-T9 — manual acceptance.** On a scratch local schema (`ecowealth_sneat_install_manual`, created as root): import through `mysql`, then through phpMyAdmin, boot the app with `ECO_DB_NAME=… php -S …`, sign in, confirm 7 products/2 packages/0 members. File `install-mysql.png`/`install-phpmyadmin.png` and the terminal output.

**Test gate**

```bash
php bin/build_install_sql.php --check
php tests/run.php InstallSql
php tests/run.php Seed
php tests/run.php
# Expected: --check silent/0; both groups pass; full suite 0 failures.
```

Manual verification on the scratch schema:

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE(); -- 27
SELECT COUNT(*) FROM settings;                                               -- 48
SELECT COUNT(*) FROM users;                                                  -- 2
SELECT COUNT(*) FROM products;                                               -- 7
SELECT COUNT(*) FROM packages;                                               -- 2
SELECT COUNT(*) FROM package_levels;                                         -- 18
SELECT COUNT(*) FROM package_products;                                       -- 10
SELECT COUNT(*) FROM reg_codes;                                              -- 0
```

**Exit criteria**

- [ ] `--check` passes in CI and on a fresh clone.
- [ ] The bundle imports into an empty schema and is refused by a non-empty one.
- [ ] Data parity is proven against the harness fresh DB, not just schema parity.
- [ ] Seed fixes are covered by `SeedParityTest`.
- [ ] README/deploy describe the one-file path and the counts.
- [ ] No application runtime file changed.

**Rollback.** Revert the two seed edits, delete the bundle/header/footer/builder/tests, restore the README/deploy snippets. The app did not depend on the bundle yet.

**Estimate.** 2 days.

---

### IR-3 — CLI installer

**Goal.** One command installs a fresh database with operator-chosen credentials, a verified result and a lock that prevents accidental re-installs.

**Builds on.** IR-2's bundle and IR-1's plumbing.

**Deliverables**

| File | Content |
|---|---|
| `app/config.php` | `INSTALL_KEY` constant (env `ECO_INSTALL_KEY`, default empty); already needed by the CLI's production gate |
| `app/Services/DatabaseInstaller.php` | Preflight, install, verify, lock |
| `bin/db_install.php` | CLI front-end |
| `system/storage/install.lock` (runtime) | Written on success; gitignored |
| `tests/Cases/InstallTest.php` | Service tests |
| `system/README.md` | Path C documented |
| `docs/deploy.md` | Optional CLI path for hosts with SSH |

**Tasks**

* **IR-3-T1 — preflight.** Return a check table covering: PHP version, extensions (`pdo_mysql`, `fileinfo`, `mbstring`, `json`), `app/config.local.php` readable, server connect, database exists, database empty, install.sql readable/parseable, `storage/` writable, lock absent, `install.lock` parent writable, and — when `APP_ENV=production` — a non-empty `INSTALL_KEY` plus a matching `--key`. Each check: `key`, `label`, `ok`, `detail`, `hint`. `ok` is the conjunction.
* **IR-3-T2 — install.** Implement 5.6. Advisory lock name `ecowealth:install:` + `substr(sha1($database), 0, 16)`. Refuse when `isLocked()` or `tableCount($db) > 0`. Optional `create_database` only when `$options['create_database']` is true. Run the bundle, then optional demo (IR-7; until then, return "not implemented" if requested), then staff updates, settings, verify, lock. If the bundle's guard fires anyway (a table appeared between the check and the import), translate the `ecowealth_install_requires_an_empty_database` driver error into a friendly `notEmpty` message; never surface a raw SQLSTATE to the operator.
* **IR-3-T3 — credentials and key.** Define the constant in `app/config.php` (env fallback, empty default):
  ```php
  defined('INSTALL_KEY') || define('INSTALL_KEY', (string) (getenv('ECO_INSTALL_KEY') ?: ''));
  ```
  Validate credentials per 6.8. `UPDATE users SET username=?, email=?, password_hash=?, member_status='active', is_active=1 WHERE role=?` for each staff role; require exactly one row per role (the bundle seeds exactly one). `password_hash($password, PASSWORD_DEFAULT)`. In production, a non-empty `INSTALL_KEY` is required and `--key`/`ECO_INSTALL_KEY` must match it with `hash_equals()`.
* **IR-3-T4 — settings and lock.** Upsert `site_name`, `install_version` (from the footer/constant), `install_channel='cli'`, `install_completed_at=NOW()`. Lock JSON:
  ```json
  {"installed_at":"2026-11-11T17:30:00+08:00","database":"ecowealth_sneat","app_version":"1.0.0","install_sql_sha256":"…","staff":["admin","superadmin"],"channel":"cli"}
  ```
  Write atomically (`file_put_contents` to a temp file + `rename`), `chmod 0600` where the platform allows. The path honours `STORAGE_PATH` when defined (the test harness points it at scratch space) and otherwise uses `<app>/storage/install.lock`. If the lock cannot be written (a permissions problem), the install still reports the database as installed but marks the report `ok=false` with the exact JSON to create manually — never leave a working install that silently looks uninstalled.
* **IR-3-T5 — verify and report.** Table in 6.5 plus `password_verify()` for both chosen passwords, 27 tables, 48 settings, 7/2/18/10 catalogue, zero transactional rows **unless `with_demo` was requested** (then expect the 5 demo users, 3 codes, 3 ledger rows and the seeded wallets), and a readable lock. Return the report (same shape as reset: `ok`, `checks`, `counts`, `warnings`). Exit 0/1/2/3 per 5.7.
* **IR-3-T6 — CLI.** Argument parsing with a small hand-written parser (the project has no Symfony Console). `--help` prints the flags and examples. `--check` prints the preflight table and exits 0 only when all checks pass. Interactive mode: confirm database name, prompt for missing credentials (echo warning for passwords); `--force` skips only the confirmation, never the preflight. `--json` suppresses human output.
* **IR-3-T7 — ignore the lock.** Add `system/storage/install.lock` to the root `.gitignore` (IR-0 already added it; verify).
* **IR-3-T8 — tests (`InstallTest`).**
  * install into `ecowealth_sneat_tool_a_test` (scratch): report `ok`, 27/48/2/7/2/18/10, lock file exists, lock JSON valid;
  * `password_verify` works for both staff;
  * second run refuses (`kind=locked` or `notEmpty`);
  * non-empty database without a lock refuses (`notEmpty`);
  * password policy: 8 chars, default password, mismatch, duplicate username/email;
  * `--check` on a missing database reports `database_exists=false` with a hint and does not create it;
  * production env + missing key is refused (the `env` constructor argument makes this testable without changing `APP_ENV`);
  * JSON report has the documented keys.
  * Tests use `STORAGE_PATH` (already a temp folder in the harness), so the lock never touches the repository. Every case removes `<STORAGE_PATH>/install.lock` before it runs (and the scratch schema is recreated), so the suite does not depend on case order.
* **IR-3-T9 — docs.** README quick start Path C and a `db_install.php --help` transcript; deploy.md "if your host has SSH".

**Test gate**

```bash
php bin/db_install.php --check
php tests/run.php Install
php tests/run.php
```

Manual end-to-end:

```bash
php bin/db_install.php --database=ecowealth_sneat_manualinstall \
  --admin-user=admin --admin-email=owner@example.com --admin-password-stdin \
  --superadmin-user=superadmin --superadmin-email=owner+super@example.com \
  --superadmin-password-stdin --site-name="EcoWealth" --force
# then point the app at it and sign in with the chosen admin credentials:
#   PowerShell: $env:ECO_DB_NAME = 'ecowealth_sneat_manualinstall'
#   bash:       export ECO_DB_NAME=ecowealth_sneat_manualinstall
# confirm 0 members / 7 products / 2 packages
```

**Exit criteria**

- [ ] A single command produces a verified, locked install.
- [ ] Re-running and non-empty targets are refused with distinct messages.
- [ ] No default password exists on the installer path.
- [ ] Tests never touch `ecowealth_sneat` or `ecowealth_sneat_test` as a target.
- [ ] `--check` works before the database exists.

**Rollback.** Delete the service/CLI/test; drop any scratch schema; remove `install.lock`. The app still installs through IR-2's bundle.

**Estimate.** 1.5 days.

---

### IR-4 — Web installer (online shared hosting)

**Goal.** Install without SSH: preflight in the browser, key-gated, empty-DB-only, lock-on-success, no default credentials.

**Builds on.** IR-3's `DatabaseInstaller`; IR-2's bundle.

**Deliverables**

| File | Content |
|---|---|
| `app/config.php` | No change — the `INSTALL_KEY` constant landed in IR-3 |
| `app/Core/DbToolAccess.php` | Install visibility/key/phrase gates (reset gates are added in IR-6) |
| `public/install.php` | Standalone installer page |
| `public/assets/css/db-tools.css` | Shared tool styling (also used by IR-6) |
| `tests/Cases/InstallTest.php` | Installer gate/validation cases (extended) |
| `tests/Cases/DbToolAccessTest.php` | Installer gate matrix (extended in IR-6) |
| `tests/smoke.ps1` | HTTP checks for the installer's refusal states |
| `docs/deploy.md` | §5B web-installer path + `INSTALL_KEY` in §7 |
| `system/README.md` | Installer URL + key note |

**Tasks**

* **IR-4-T1 — key documentation and wiring.** The constant itself lands in IR-3:
  ```php
  /* Installer key (production only). Empty means an installer refuses in
   * production; set a long random string in config.local.php. */
  defined('INSTALL_KEY') || define('INSTALL_KEY', (string) (getenv('ECO_INSTALL_KEY') ?: ''));
  ```
  IR-4 reads it in the page, documents it in the README config table and deploy.md §7, and makes sure `install.php` never renders the key back to the browser.
* **IR-4-T2 — standalone shell.** `install.php`:
  * must not require `app/bootstrap.php` (no DB yet, no session);
  * requires `app/config.php`, `app/helpers.php`, `app/autoload.php`;
  * `session_name('ecowealth_install')`, `session_start()` with `httponly`, `samesite=Lax`, `secure` when HTTPS;
  * sends `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Cache-Control: no-store`;
  * catches `Throwable`, logs the detail, renders a generic error with the exception's hint;
  * never calls `setting()`, `auth_*()` or any helper that assumes the application schema exists — only `e()`, `url()`/`base_url()` and `csrf_*()` are safe before the tables are created.
* **IR-4-T3 — preflight and form.** Render the preflight checks from IR-3-T1. Refuse/disable the form when a required check fails. Form fields: site name, admin username/email/password/confirm, superadmin username/email/password/confirm, `INSTALL_KEY` (production), demo checkbox (non-production only), typed `INSTALL`, CSRF hidden field. Client-side hints are cosmetic; the server revalidates everything.
* **IR-4-T4 — execution and states.** POST → validate → `DatabaseInstaller::install()` → success report (accounts without passwords, counts, lock status, "delete install.php") or failure (failing check/statement, drop-and-retry guidance). If the lock exists or the DB is non-empty, render "Already installed" with a login link and **no** form.
* **IR-4-T5 — key and throttle.** Put the gate logic in `DbToolAccess` (`installKeyRequired()`, `installKeyMatches()`); in production (`APP_ENV === 'production'`), `INSTALL_KEY` must be non-empty and `hash_equals()` must match. `installKeyMatches()` must return `false` when the configured key is empty, even if the submitted value is also empty. Failures are counted in the session (5 / 15 min) and then the page refuses with a generic message; the counter is session-scoped, so the key itself is the real control. Non-production allows an empty key (local convenience) but still validates everything else.
* **IR-4-T6 — CSS.** One dark tool stylesheet: variables, cards, tables, badges, buttons, alerts, form controls, responsive breakpoints. No app layout, no external fonts. The install page and the reset console share it.
* **IR-4-T7 — tests and smoke.**
  * Extend `InstallTest` for the service methods used by the page: `validateStaffInput()`, `isLocked()`, the empty-DB refusal; add `DbToolAccessTest` for `installKeyRequired()`/`installKeyMatches()` (the reset matrix joins it in IR-6).
  * `tests/smoke.ps1`: `GET /install.php` against the **test** app returns the "already installed"/locked page (non-empty test DB), contains no password/DSN, and does not create a lock. Add the URL to the existing smoke summary.
* **IR-4-T8 — manual online simulation.** Boot `php -S` with `ECO_DB_NAME=ecowealth_sneat_install_web_test`, create the empty schema, open `/install.php`:
  1. preflight screenshot;
  2. submit with a key and real credentials → success screenshot;
  3. reopen → "already installed";
  4. remove the lock and point at a non-empty DB → refusal;
  5. drop the DB, leave `INSTALL_KEY` empty with `APP_ENV=production` → refusal with the key hint.
  File the screenshots under `docs/qa/evidence/install-reset/`.

**Test gate**

```bash
php tests/run.php Install
php tests/run.php DbToolAccess
powershell -NoProfile -File tests/smoke.ps1 -DbUser root -DbPass ''
# Expected: installer checks pass; gate matrix passes; smoke's install.php probes pass; no lock created in the repo.
```

Manual: the five browser states above, including the production/key refusal.

**Exit criteria**

- [ ] The page works with no `app/config.local.php` DB name beyond what config.php defines; it never writes that file.
- [ ] A production install without `INSTALL_KEY` is refused with an actionable hint.
- [ ] The success page prints no password and no DSN.
- [ ] The lock and non-empty guards both refuse independently.
- [ ] The app shell, routes and tests are untouched.

**Rollback.** Delete `install.php`, the CSS (if IR-6 has not landed), the `INSTALL_KEY` block and the docs changes. No application path references them.

**Estimate.** 1.5 days.

---

### IR-5 — Reset engine and CLI

**Goal.** Implement the one reset engine described in Section 7 and expose it through a safe CLI. This is the phase with the highest data-loss risk, so its tests use only scratch schemas and every failure path is exercised.

**Builds on.** IR-1's `DbTarget`/`SqlScript`; IR-2's seed files (catalogue re-seed); IR-3's lock/report conventions.

**Deliverables**

| File | Content |
|---|---|
| `app/Services/DatabaseResetService.php` | Preview, dry-run, run, verification, report |
| `bin/db_reset.php` | CLI flags, confirmation, progress, JSON, exit codes |
| `app/Services/CommissionService.php` | `reconcile()` reads `commission_ledger_since` through its own PDO |
| `tests/Cases/DbResetTest.php` | The reset matrix |
| `system/README.md` | "Reset a development database" section |
| `docs/runbook.md` | First reset runbook entry (expanded in IR-8) |

**Tasks**

* **IR-5-T1 — preview and dry run.** Implement `preview()`/`dryRun()` exactly as 7.13. Counts come from one query per table (cheap at this scale) or a single `UNION ALL`; use prepared identifiers only from a hard-coded allow-list, never from input. `dryRun()` must not call `beginTransaction()`.
* **IR-5-T2 — transactional reset.** Implement Appendix A literally: lock → preflight → `BEGIN` → deletes in 7.3 order → staff update → optional catalogue → optional audit clear → audit row → settings → bump live revisions → `COMMIT`. Catch `Throwable`, `ROLLBACK` if in a transaction, rethrow as `DatabaseToolException::sql()` with the failing step. Never call `SET FOREIGN_KEY_CHECKS`.
* **IR-5-T3 — staff reset.** Use the SQL in 7.4. Before the delete phase, assert at least one `admin` or `superadmin` row exists; after the update, assert every staff row has both balances `0.00` and `package_id IS NULL`.
* **IR-5-T4 — catalogue reset.** Implement 7.5: delete `package_products`, `package_levels`, `packages`, `products` (inside T1), then after the delete phase re-run `003_products.sql` and `004_packages.sql` through `SqlScript` **inside the same transaction**. If a seed fails, the whole reset rolls back, including the catalogue delete. Record the row counts before/after.
* **IR-5-T5 — codes.** Implement 7.6. The batch is its own transaction after the main commit. Reuse `CodeService::randomCode()` only. Validate `0 <= N <= 50`; a negative/over-limit value is a validation error before any delete.
* **IR-5-T6 — uploads.** Implement 7.7 with `UPLOAD_ROOT` support. Use `FilesystemIterator` with `SKIP_DOTS`, `is_file()` only, skip `.gitkeep`/`.htaccess`. Count deleted/failed; never delete a directory.
* **IR-5-T7 — audit, settings, live.** Implement 7.8 and 7.9. The audit insert and the live-revision bump both happen **inside T1**, so a rollback removes both. After commit, the web front-end may `Live::publish()` the surfaces; the service itself does not depend on the session.
* **IR-5-T8 — auto-increment.** Implement 7.10. Query `information_schema.tables` to confirm the table is empty before each `ALTER`; skip when the table is not empty. Catch and log per table.
* **IR-5-T9 — verification and report.** Implement 7.11 with the four services on the injected PDO. `verify()` is public so the web console and tests can call it independently. Report `ok=false` (not an exception) when verification fails, so the CLI can print the details and exit 1.
* **IR-5-T10 — `CommissionService` fix.**
  ```php
  // before
  $since = trim((string) setting('commission_ledger_since', ''));
  // after
  $since = '';
  try {
      $sinceStmt = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
      $sinceStmt->execute(['commission_ledger_since']);
      $since = trim((string) ($sinceStmt->fetchColumn() ?: ''));
  } catch (Throwable $e) {
      // A pre-migration database has no settings table; keep the old default.
      error_log('[ecowealth] commission ledger baseline unavailable: ' . $e->getMessage());
  }
  ```
  Behaviour is unchanged for the app connection; the injected-PDO case now reads the target's setting, and a missing table falls back to the old empty baseline. Add a case: a scratch DB with `commission_ledger_since` in the future excludes its commission ledger rows from the expected total.
* **IR-5-T11 — CLI.** Implement 5.7:
  * `--dry-run` prints the preview and exits 0 without writes;
  * the confirmation is always required: without `--force`, prompt `Type "<database>" to continue` (EOF/anything else exits 3); with `--force`, `--confirm=<database>` must be supplied (a non-interactive run without it exits 2, never 0);
  * production: `APP_ENV === 'production'` requires `--allow-production`; otherwise exit 3 with a clear message;
  * `--json` prints one document; progress goes to STDERR;
  * human output: preview table, one line per step (`✓ orders 16 deleted · 41 ms`), verification checklist, warnings, exit code.
* **IR-5-T12 — tests (`DbResetTest`).** Each case recreates `ecowealth_sneat_tool_b_test` (via `test_scratch_database('tool_b')`) from `schema.sql` + seeds 001–005, so the service starts from a realistic QA database.

  | # | Case | Assertion |
  |---:|---|---|
  | 1 | preview | counts match the seeded fixture; no row changed |
  | 2 | dry run | `writes=0`; every table count unchanged; no transaction left open |
  | 3 | default reset | `ok`; every deleted table 0 (audit kept, live 18, codes as generated); staff 2; catalogue/settings unchanged |
  | 4 | staff defaults | both staff: package/sponsor/reg fields NULL, balances `0.00`, `member_status=active`, `is_active=1`, password hash unchanged |
  | 5 | audit kept | row count grows by 1; new row `action='system.db_reset'`, meta has options/counts |
  | 6 | audit cleared | prior rows gone; exactly 1 row (the reset) |
  | 7 | catalogue reset | the seven seeded SKUs, two package slugs, 18 levels and 10 contents exist with the seed 003/004 values; an old custom product is gone |
  | 8 | codes | N rows, all `unused`, package bound, price equals package price, all unique |
  | 9 | invalid options | `code_qty=-1` and `code_qty=51`, and a nonexistent `code_package`, are refused before any delete; DB unchanged (demo preconditions are covered in IR-7) |
  | 10 | idempotent | second run `ok`; 0 deleted in every delete step; counts still clean |
  | 11 | rollback | `$onStep` throws at `members`; every count equals the pre-reset snapshot; no audit row |
  | 12 | uploads | fake proof files in `UPLOAD_ROOT/proofs` deleted; `.gitkeep`/`.htaccess` kept; `--keep-uploads` keeps them |
  | 13 | live revisions | seed one revision row first: after reset it is incremented and the other standard surfaces are 1 (18 total) |
  | 14 | auto-increment | `shop_orders`, `ewallet_ledger`, `reg_codes` next id 1; `users` next id unchanged |
  | 15 | verification | four reconciliations `ok`; `verify()['ok']` true |
  | 16 | wrong confirmation | `DatabaseToolException::refused`; no change |
  | 17 | no staff | staff rows removed first; refused; no change |
  | 18 | missing table | drop `payout_requests`; refused; no change |
  | 19 | production guard | `env='production'` without `allow_production`; refused; no change |
  | 20 | report shape | required keys present; counts_before/after; codes list; warnings array |
  | 21 | `CommissionService` injected PDO | honours the scratch DB's `commission_ledger_since` |

  Every case recreates the scratch schema at its start, so it cannot leak into the next case; do not drop the pre-provisioned schemas at shutdown (that would remove their grants). No case may target `DB_NAME` or `ecowealth_sneat`.
* **IR-5-T13 — manual acceptance.** On a **scratch** schema (`ecowealth_sneat_reset_manual`, created as root; `ECO_DB_NAME=ecowealth_sneat_reset_manual`), run the full CLI path, then:
  ```bash
  php bin/wallet_reconcile.php
  ```
  and confirm exit 0 and all four sections clean. Open the app with `ECO_DB_NAME=…`, sign in, check `/admin`, `/admin/members` (0), `/admin/orders` (0), `/admin/codes`, `/shop` (catalogue intact), `/dashboard`. File the terminal output under evidence.

**Test gate**

```bash
$env:ECO_DB_NAME = 'ecowealth_sneat_reset_manual'   # PowerShell; bash: export ECO_DB_NAME=…
php bin/db_reset.php --dry-run
php bin/db_reset.php --force --confirm=$env:ECO_DB_NAME --codes=5 --code-package=1
php bin/wallet_reconcile.php
php tests/run.php DbReset
php tests/run.php Commission
php tests/run.php
# Expected: dry-run writes nothing; reset exits 0 with verification all green;
# wallet_reconcile exits 0; new + existing groups pass.
```

**Exit criteria**

- [ ] The default reset leaves zero transactional rows and a fully reconciled staff-only database.
- [ ] Catalogue kept by default; catalogue reset reproduces the seed state by natural key.
- [ ] A forced failure inside T1 rolls back every row.
- [ ] Dirty FK order cannot happen silently: the test suite covers the delete order, and FK checks stay on.
- [ ] CLI refuses production, wrong confirmations, missing staff and missing tables.
- [ ] `CommissionService::reconcile()` works on an injected PDO.

**Rollback.** Delete the service/CLI/test, revert the `CommissionService` hunk, drop the scratch schemas. No web path exists yet.

**Estimate.** 3 days.

---

### IR-6 — Reset web console

**Goal.** Give the reset engine the reference's operator experience: a dark, self-contained console with current-state stats, the "what will be cleared" list, typed confirmation, options, a per-step log and copyable fresh codes — hardened with authentication, CSRF and production gating.

**Builds on.** IR-5's engine; IR-4's `db-tools.css` and standalone-shell conventions.

**Deliverables**

| File | Content |
|---|---|
| `app/config.php` | `DB_RESET_ENABLED` (default `false`) |
| `app/Core/DbToolAccess.php` | Extend the IR-4 installer gates with the reset visibility/actor/phrase gates |
| `public/reset.php` | The console |
| `public/assets/css/db-tools.css` | Reset-specific card/console styles |
| `tests/Cases/DbToolAccessTest.php` | Gate unit tests |
| `tests/smoke.ps1` | HTTP probes for reset visibility/refusal |
| `system/README.md`, `docs/deploy.md` | Reset console docs and production warning |

**Tasks**

* **IR-6-T1 — config constant.**
  ```php
  /* Web reset console. Deliberately false: the reset tool is a
   * development/QA tool. A production operator must opt in explicitly
   * and then delete the file after use. */
  defined('DB_RESET_ENABLED') || define('DB_RESET_ENABLED', false);
  ```
* **IR-6-T2 — `DbToolAccess`.** Extend the class introduced in IR-4 with the reset gates (the installer methods stay where they are, so `install.php` needs no change beyond the shared calls):
  ```php
  final class DbToolAccess
  {
      public static function installKeyRequired(string $env): bool;          // production
      public static function installKeyMatches(string $expected, string $given): bool; // false when $expected === ''
      public static function resetVisible(string $env, bool $enabled): bool; // !production || enabled
      public static function resetActorAllowed(?array $user, string $env): bool;
      public static function confirmationPhrase(string $env, string $database): string; // RESET | database
      public static function isImpersonating(): bool;
  }
  ```
  `resetActorAllowed()`: `admin` in development, `superadmin` in production; never a `member`; never when `auth_is_imp()`. Refactor `install.php` to call the shared methods (no behaviour change) so the gates have one tested source.
* **IR-6-T3 — page shell and guards.** `reset.php`:
  1. Pre-check the connection with `DbTarget` **before** `require app/bootstrap.php`; on failure render a standalone "database unavailable" page (the app's `db()` exits on failure and cannot be caught).
  2. `require app/bootstrap.php`; `SecurityHeaders` is then applied by the bootstrap.
  3. If `!DbToolAccess::resetVisible(APP_ENV, DB_RESET_ENABLED)` → `View::error(404)`.
  4. If `auth_is_imp()` → `View::error(403)`.
  5. `require_admin()`; in production also `require_superadmin()`.
  6. Read the staff actor + `Request::ip()` for the audit row.
* **IR-6-T4 — preview UI.** Query the counts listed in 7.13, staff and packages through a service `preview()` (reuse it; do not duplicate SQL). Render the header (tool badge, app name, database name), the stats row, the warning box, and the "what will be cleared" list with red/yellow/green dots.
* **IR-6-T5 — form and validation.** Fields from 7.2 plus:
  * typed confirmation (`RESET` in development, database name in production);
  * backup acknowledgement checkbox (required);
  * CSRF hidden field;
  * server-side validation of `code_qty` (0–50), `code_package` (exists), catalogue/audit checkboxes, demo only when allowed.
  On validation failure: re-render with inline errors, no execution.
* **IR-6-T6 — execution and report (PRG).** POST → `DatabaseResetService::run()` → store the report in `$_SESSION['db_reset_report']` → `redirect('reset.php?done=1')`. The `done` view renders: green/red banner, per-step log with counts and timings, verification checklist, code chips (click to copy with a toast), staff login hint, links "Back to dashboard", "Reset again", "Done". Refresh re-renders, never re-runs.
* **IR-6-T7 — failure states.** Exception → store a failure report (message + kind + rollback notice when T1 failed) → same PRG view. A partial post-commit failure shows which step needs attention and links to the relevant admin page. No stack traces or DSNs in the HTML; details go to `error_log()`.
* **IR-6-T8 — styling.** Extend `db-tools.css` with the console cards, stats, dots, code chips and toast. Mirror the reference's visual hierarchy (danger badge, dark background, monospace logs) using EcoWealth's palette. Responsive down to 360 px.
* **IR-6-T9 — tests and smoke.**
  * `DbToolAccessTest`: visibility matrix (`development`/`production` × enabled/disabled), actor matrix (guest/member/admin/superadmin), confirmation phrase per env, install-key matching. The impersonation refusal is auth-session-dependent and is covered by the manual browser matrix (state 4), not by the pure unit test.
  * `tests/smoke.ps1`: in the test environment (development), `GET /reset.php` without a session redirects to login (302) and does not delete anything; with a member session returns 403; the production-disabled case is covered by the unit test (the smoke app runs development).
  * Manual browser matrix (evidence):

    | # | Environment | Actor | Expected |
    |---:|---|---|---|
    | 1 | development | guest | redirect to login |
    | 2 | development | member | 403 |
    | 3 | development | admin | form |
    | 4 | development | impersonated member | 403 |
    | 5 | production, disabled | superadmin | 404 |
    | 6 | production, enabled | admin | 403 |
    | 7 | production, enabled | superadmin | form; phrase is DB name |
    | 8 | development | admin, wrong phrase | form + error, no change |
    | 9 | development | admin, success | report, codes, verification |
    | 10 | development | success then refresh | report again, no second run |
* **IR-6-T10 — docs.** README "Reset a development database" with the URL, the gates and the "never in production" warning; deploy.md §12 adds deleting the file; runbook gains the pre-reset backup step.

**Test gate**

```bash
php tests/run.php DbToolAccess
php tests/run.php
powershell -NoProfile -File tests/smoke.ps1 -DbUser root -DbPass ''
# Expected: gate matrix passes; smoke redirects/403 pass; full suite green.
```

Manual: the ten browser states above, filed as `reset-form.png`, `reset-validation.png`, `reset-success.png`, `reset-production-404.png`, `reset-member-403.png`, `reset-impersonation-403.png`.

**Exit criteria**

- [ ] A member or impersonated session can never see the form.
- [ ] Production without the explicit constant renders the branded 404.
- [ ] Wrong confirmation, missing CSRF and missing backup ack run nothing.
- [ ] Refresh after success does not reset twice.
- [ ] Codes are visible, copyable and stored only in the session report.
- [ ] No SQL in the page; all work goes through the service.

**Rollback.** Delete `reset.php`, the `DB_RESET_ENABLED` block, the reset-specific CSS and tests; keep `DbToolAccess` for the installer. The engine and CLI remain usable.

**Estimate.** 2 days.

---

### IR-7 — Demo fixtures and reset/install parity

**Goal.** Make demo data a deliberate, tested option on every path, and prove that a reset produces the same state as a fresh install.

**Builds on.** IR-3 (installer), IR-5 (reset engine).

**Deliverables**

| File | Content |
|---|---|
| `app/Services/DemoDataService.php` | Preconditions, `005_demo_tree.sql` import, verification |
| `DatabaseInstaller`, `DatabaseResetService` | `--demo` / checkbox wiring |
| `tests/Cases/DbResetTest.php` | Parity cases |
| `tests/Cases/InstallTest.php` | Install-with-demo case |
| `system/README.md`, `docs/deploy.md` | Demo commands and the production warning |

**Tasks**

* **IR-7-T1 — `DemoDataService`.** `available(PDO $pdo, bool $requireEmpty = true, bool $catalogueWillReset = false, ?string $env = null): array{ok:bool, reason:string}` checks: `($env ?? APP_ENV) !== 'production'`; the two default packages exist **unless** `$catalogueWillReset` (the re-seed will provide them); and — when `$requireEmpty` — no demo usernames exist yet and every transactional table is empty except `audit_log` and `live_revisions` (a reset leaves its own audit row and bumps surfaces). The installer passes `true, false, $env`; the reset preflight passes `false, $options['reset_catalogue'], $options['env']`. `install(PDO $pdo): array` re-checks the packages, then runs `database/seeds/005_demo_tree.sql` through `SqlScript` and verifies: 5 users (`juan`, `maria`, `peter`, `sarah`, `david`), 3 `DEMO-*` codes, 3 adjustment ledger rows, wallet balances 2000/1000/500 with 1500/800/400 withdrawable, `david` pending.
* **IR-7-T2 — wiring.** `DatabaseInstaller::install(['with_demo' => true])` validates the demo preconditions **before** running the bundle (`available($pdo, true, false)`). `DatabaseResetService` calls `available($pdo, false, $options['reset_catalogue'])` in its preflight, **before any delete**, and only then runs the demo after the main commit and before code generation, so generated codes and demo codes coexist. In both, production or missing packages ⇒ `DatabaseToolException::refused()` with a precise reason and no data change. The one residual case is `--reset-catalogue --demo` with customized seed files that omit the default packages: the preflight trusts the re-seed, the reset commits, and the demo step reports a warning (the standard seeds always satisfy it).
* **IR-7-T3 — report.** Demo results appear under `demo` in the report (`installed`, `members`, `codes`, `wallets`, `credentials: 'Demo@1234'`). The password is the documented demo password, never a generated secret. The web console shows a blue demo banner and the credentials line, matching the reference's login hint.
* **IR-7-T4 — parity tests.** Two comparisons, by natural key only (D-IR-15). Build `A` in `test_scratch_database('tool_a')` and `B` in `test_scratch_database('tool_b')`; both slots are recreated per case, so the comparison never touches `DB_NAME`. `audit_log` and `live_revisions` are **excluded** from the row comparison: a reset intentionally appends an audit row and bumps 18 live surfaces, while a fresh install has neither. `last_reset_*` settings are excluded for the same reason.
  1. **Reset ≡ fresh production install.** Build `A` = `schema.sql` + seeds 001–004; build `B` = same + service reset (keep catalogue, no demo). Compare: staff rows, settings (minus `last_reset_*`), products by sku/price/stock/status, packages by slug/price/bonus, levels by (slug, level, amount), contents by (slug, sku, quantity), and zero rows in every transactional table except the excluded `audit_log`/`live_revisions`.
  2. **Reset(catalogue+demo) ≡ fresh QA install.** Build `A` = `schema.sql` + seeds 001–005; build `B` = seeds 001–004 + reset `--reset-catalogue --demo`. Compare the same lists plus the five demo users (username/role/status/package slug/sponsor username), their wallet balances, the three demo codes and the three ledger rows — again excluding `audit_log`, `live_revisions` and `last_reset_*`.
  * Differences are printed as the assertion message (expected vs actual maps), not just "arrays differ".
* **IR-7-T5 — idempotency and isolation.** Reset→demo→reset leaves no demo rows; a second `--demo` on a non-empty DB is refused, not merged.
* **IR-7-T6 — docs.** README: "Local QA: add demo data" with the CLI and web checkbox; deploy.md: demo is never installed online; the go-live checklist checks for `juan`/`DEMO-` rows.

**Test gate**

```bash
php tests/run.php DbReset
php tests/run.php Install
php tests/run.php
# Expected: parity maps equal; demo cases pass; full suite green.
```

Manual: reset a scratch schema with `--reset-catalogue --demo`, sign in as `juan` / `Demo@1234`, open `/genealogy` and `/earnings`, confirm the tree; file a screenshot.

**Exit criteria**

- [ ] Demo data appears only through `--demo`/checkbox and only outside production.
- [ ] A reset without demo has no member/code/ledger rows.
- [ ] Both parity comparisons pass by natural key.
- [ ] `DemoDataService` refuses an inconsistent catalogue instead of writing active members with a NULL package.

**Rollback.** Remove the service and the `with_demo` wiring; the reset/install base paths still work.

**Estimate.** 1.5 days.

---

### IR-8 — Hardening, documentation, go-live and sign-off

**Goal.** Make the tools safe to ship, document every operator path, and retire them before launch. No new features beyond what the gates require.

**Builds on.** All previous phases.

**Deliverables**

| File | Change |
|---|---|
| `system/README.md` | Install (one bundle + installers), reset, config constants, directory layout, tests, migrations note, fix F-10 |
| `docs/deploy.md` | §5B single bundle, §6 upload contents, §7 `INSTALL_KEY`, §12 cleanup, §16 checklist |
| `docs/runbook.md` | Install, reset, restore, tool-retirement procedures |
| `docs/phase-log.md` | Phase 16.1–16.7 entries (one per IR phase that changed code) |
| `docs/qa/phase-16-install-reset.md` | QA scenarios, expected results, evidence index |
| `docs/qa/README.md`, `docs/qa/sign-off-sheet.md` | Phase 16 row and sign-off columns |
| `docs/qa/evidence/install-reset/` | Final screenshots + terminal transcripts |
| `dmp/plan-ecowealth-cart-system.md` | One-line cross-reference to this plan for install/reset (optional, no content rewrite) |

**Tasks**

* **IR-8-T1 — security review.** Checklist:
  * `install.php` and `reset.php` send no-store/frame-deny/nosniff headers;
  * no password, DSN or raw SQL error in HTML or JSON;
  * `storage/install.lock` is outside `public/` and `0600` where possible;
  * `DB_RESET_ENABLED` is absent/false in the production `config.local.php`;
  * `INSTALL_KEY` is at least 32 random characters and removed/rotated after install;
  * the tools are not linked in any navigation, sitemap or `routes.php`;
  * uploads/storage `.htaccess` guards still apply;
  * session names and cookies are hardened (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS).
* **IR-8-T2 — documentation pass.** Update every row in the table above. Include exact commands (PowerShell and bash where they differ), expected counts, and screenshots. The README must answer: "How do I install locally?", "How do I install online?", "How do I reset?", "How do I remove the tools?".
* **IR-8-T3 — go-live checklist additions** (deploy.md §12):
  - [ ] `public/install.php` deleted; `https://…/system/install.php` returns 404.
  - [ ] `public/reset.php` deleted; `https://…/system/reset.php` returns 404.
  - [ ] `storage/install.lock` exists and is not web-readable.
  - [ ] `INSTALL_KEY` removed from `config.local.php` (or rotated).
  - [ ] `DB_RESET_ENABLED` absent or `false`.
  - [ ] Both staff passwords changed from any default; no `Admin@123`/`Super@123`.
  - [ ] No demo users (`juan`, `maria`, `peter`, `sarah`, `david`) and no `DEMO-%` codes.
  - [ ] Verification pack (Appendix E) returns the expected counts.
  - [ ] Post-launch database backup taken.
* **IR-8-T4 — final verification.** Run, in order:
  1. `php tests/run.php` (full suite, 0 failures);
  2. `php tests/smoke.ps1 -DbUser root -DbPass ''` (HTTP checks, 0 failures);
  3. the manual UAT scripts in 9.3 on a scratch schema;
  4. a phpMyAdmin import of `install.sql` on a fresh online-like schema;
  5. a web-installer run on another fresh schema;
  6. a reset on the demo QA schema followed by `wallet_reconcile` (exit 0).
* **IR-8-T5 — sign-off.** Fill the acceptance sheet (Appendix G), tag the commit (`ir-8-install-reset-complete`), and write the phase-log summary with the test counts and evidence paths.

**Test gate**

```bash
php tests/run.php
powershell -NoProfile -File tests/smoke.ps1 -DbUser root -DbPass ''
# Expected: full suite green; smoke green; evidence folder complete; sign-off filled.
```

Manual review: Appendix G all checked; deploy.md §12 items all checked on a staging copy.

**Exit criteria**

- [ ] All nine phase gates are green in order.
- [ ] The two tools are absent (or 404) on the production checklist copy.
- [ ] Docs answer the four operator questions without reference to this plan.
- [ ] No default credential remains in any production path.
- [ ] A fresh install and a reset both verify clean.

**Rollback.** Documentation-only rollback; the code is already guarded by lock/env/file removal.

**Estimate.** 2 days.

---

## 9. Test strategy

### 9.1 Test levels and databases

| Level | Harness | Database | What it proves | Runs in every gate |
|---|---|---|---|---|
| **Unit** | `tests/run.php` (no DB writes) | none | Parser rules, gate matrices, password policy, confirmation phrases | Yes |
| **Service integration** | `tests/run.php` with `test_scratch_database()` | `ecowealth_sneat_<purpose>_test` | Install bundle, installer, reset engine, parity | Yes |
| **Application regression** | existing 722 cases | `ecowealth_sneat_test` (rebuilt by `tests/bootstrap.php`) | Nothing in the app changed behaviour | Yes |
| **HTTP smoke** | `tests/smoke.ps1` against a running app | development/test app DB | Tool visibility, redirects/403/404, no side effects on GET | Phase gate + final |
| **Manual browser** | Chrome/Edge | scratch schema + `ECO_DB_NAME` | Visual states, CSRF, PRG, production gates | IR-4/IR-6/IR-8 |
| **Manual online** | Hostinger-like phpMyAdmin + subfolder | fresh schema | Real shared-hosting path | IR-2/IR-4/IR-8 |

Rules:

* A test may create/drop only a database whose name ends in `_test`, and only one of the two **pre-provisioned** tool schemas (`ecowealth_sneat_tool_a_test`, `ecowealth_sneat_tool_b_test`). The application user does not have global `CREATE DATABASE`; the one-time grant is in IR-1-T4. The development schema `ecowealth_sneat` is never a target.
* The tool schemas are recreated at the start of each case, not dropped at shutdown (dropping would remove the grants). Each case therefore starts from a known empty schema.
* Reset/install tests never call `exec()`/`shell_exec()` for the CLI (sandboxed runners and hardened PHP disable it). They test the **service**; the CLI wrapper is covered manually and by a `--help`-free unit test of the argument parser if it is extracted.
* Uploads go to `UPLOAD_ROOT` (temp); cron locks/logs go to `STORAGE_PATH` (temp). Both are already defined by the harness.
* `CommissionService::reconcile()` reads the setting from its own PDO after IR-5, so the parity/reset tests do not depend on the app connection.

### 9.2 New test files and cases

| File | Group | Cases | Highlights |
|---|---|---:|---|
| `SqlScriptTest.php` | `SqlScript` | ~12 | quotes/comments/backticks, line numbers, DELIMITER, failure context, schema import, checksum |
| `DbTargetTest.php` | `DbTarget` | ~8 | connect/exists/create/tables, unsafe names, error hints, no DSN leak |
| `SeedParityTest.php` | `SeedParity` | ~4 | migration-inserted keys ⊆ seed 001; no duplicates; every key in install.sql; no DB switch |
| `InstallSqlTest.php` | `InstallSql` | ~8 | `--check`, import, schema/index/FK parity, settings/catalogue parity, guard on re-import |
| `InstallTest.php` | `Install` | ~10 | preflight, install+lock, re-run refusal, non-empty refusal, password policy, verify, JSON shape, key gate |
| `DbResetTest.php` | `DbReset` | ~21 | preview/dry-run, full reset, staff, audit, catalogue, codes, rollback, uploads, live, auto-increment, guards, parity |
| `DbToolAccessTest.php` | `DbToolAccess` | ~10 | visibility/actor matrices, phrase, impersonation, key matching |

That is roughly **73 new cases**, taking the suite from ~722 to ~795. The exact count is recorded in the IR-8 phase-log entry, not hard-coded here.

### 9.3 Manual acceptance scripts

Manual scratch schemas (`*_manual`, `*_a`, `*_web_test`) are created as **root** (Laragon) or through the hosting panel, then granted to the app user. They are not part of the automated suite and are dropped after the evidence is filed. The pre-provisioned `ecowealth_sneat_tool_a_test`/`tool_b_test` schemas are for the automated cases only.

**A. Local install through `mysql`**
1. Drop/create `ecowealth_sneat_install_a` (`utf8mb4`).
2. `mysql -u root ecowealth_sneat_install_a < database/install.sql`.
3. Confirm the footer prints 27/48/2/7/2/18/10 and 0 members/codes.
4. Point the app at it (`ECO_DB_NAME`) and sign in as `admin` / `Admin@123`.
5. Change the password; reload.
*Pass:* login works, catalogue/packages render, `/admin/members` is empty.

**B. Local install through the CLI**
1. Create a scratch schema.
2. Run `php bin/db_install.php … --admin-password-stdin --superadmin-password-stdin --force`.
3. Confirm exit 0, the verification table, and `storage/install.lock`.
4. Re-run: exit 3, "already installed".
*Pass:* chosen credentials sign in; default credentials are refused.

**C. Online install through phpMyAdmin**
1. On a staging host/folder, create the database and user in the panel.
2. Import `install.sql`; confirm the footer counts.
3. Upload `config.local.php` (`APP_ENV=production`, real host/name/user/pass, `APP_URL`).
4. Sign in, change both passwords, configure settings.
*Pass:* app works over HTTPS at the documented URL; `install.sql` has no executable `CREATE DATABASE`/`USE` statement (its comments may mention them).

**D. Online install through `install.php`**
1. Fresh schema, production config with a 32+ char `INSTALL_KEY`.
2. Open `/system/install.php`; preflight all green.
3. Submit real credentials + key + typed `INSTALL`.
4. Confirm the success page (no passwords), the lock, and the "already installed" state on reload.
5. Remove the key from config; confirm a second install attempt is locked out (not just key-refused).
*Pass:* no default credentials exist; the page cannot install twice.

**E. Reset through the CLI**
1. On the demo QA schema, run `--dry-run`, then the real reset (`--codes=5`).
2. Confirm exit 0, the step log, the verification checklist and five codes.
3. Run `php bin/wallet_reconcile.php` → exit 0.
4. Open `/admin` and `/shop`; member/order counts are zero, catalogue intact.
5. Reset again with no options: exit 0, zero deleted rows in every delete step.
*Pass:* idempotent clean state; reconciliations green.

**F. Reset through the web console**
1. Development, signed in as admin: form renders with correct counts.
2. Wrong phrase / missing CSRF / missing backup ack: no change.
3. Correct: report, codes, verification; refresh does not re-run.
4. Member session: 403. Impersonated session: 403.
*Pass:* the ten-state matrix in IR-6-T9.

**G. Production gates**
1. Temporarily set `APP_ENV=production` in `config.local.php` (and restore it afterwards); leave `DB_RESET_ENABLED` absent.
2. `/system/reset.php` → branded 404.
3. Set `DB_RESET_ENABLED=true`: admin → 403; superadmin → form with the database name as the phrase.
4. `INSTALL_KEY` empty: `/system/install.php` on an empty DB → refusal with the exact hint.
*Pass:* no destructive action is reachable with the wrong role/config; restore `APP_ENV` after the test.

**H. Parity**
1. Run the IR-7-T4 comparisons (automated).
2. Manually compare the staff/settings/catalogue screens between a fresh install and a post-reset install.
*Pass:* the only differences are `last_reset_*`, `install_*` metadata and row ids.

### 9.4 CI sequence

```bash
# 0. one-time / CI setup: provision the two tool schemas (IR-1-T4)
#    ecowealth_sneat_tool_a_test, ecowealth_sneat_tool_b_test

# 1. bundle must be current
php bin/build_install_sql.php --check

# 2. every automated group, including the existing 722
php tests/run.php

# 3. HTTP checks against a running app (Windows dev box / CI job)
powershell -NoProfile -File tests/smoke.ps1 -DbUser root -DbPass ''
```

Exit criteria for CI: all three exit 0; the suite prints `Failed: 0`; the smoke script prints its final `ALL CHECKS PASSED` (or the current equivalent) line.

### 9.5 Test-data rules

* A reset test starts from `schema.sql` + seeds 001–005, never from a hand-built partial schema.
* A demo/parity test compares **natural keys** (`username`, `slug`, `sku`, `level`) and explicit money strings; never ids or timestamps.
* A test that needs a missing table (preflight refusal) drops it in its **own** scratch schema after the import, and never touches the shared harness DB.
* No test writes to `public/uploads`, `system/storage`, `backups/` or `docs/qa/evidence/`.
* No test depends on the order of cases in another file; the harness runs each case in a rolled-back transaction, but DDL/tool tests manage their own schema.

---

## 10. Operations runbooks

### 10.1 Local fresh install (Laragon)

1. Start Laragon (Apache + MySQL).
2. Create the schema and user (once); put the credentials in `app/config.local.php` (README quick-start SQL).
3. Choose one:
   * `mysql -u root ecowealth_sneat < database/install.sql`, or
   * `php bin/db_install.php --database=ecowealth_sneat --admin-… --force`.
4. Confirm the verification counts (6.5).
5. Open `http://localhost/ecowealth/system/`, sign in, change both staff passwords.
6. Optional QA: `mysql … ecowealth_sneat < database/seeds/005_demo_tree.sql` or use the installer's demo option (IR-7).
7. Keep `install.php`/`reset.php` locally; they are development tools.

### 10.2 Local reset before a QA round

1. **Back up first** (D-IR requirement):
   ```powershell
   mysqldump --single-transaction --routines --triggers ecowealth_sneat > backups\pre-reset-$(Get-Date -Format yyyyMMdd-HHmm).sql
   ```
2. Preview: `php bin/db_reset.php --dry-run`.
3. Decide options: keep catalogue (default), codes (0–5 typical), demo (only if the QA script needs the tree).
4. Run: `php bin/db_reset.php --force --confirm=ecowealth_sneat --codes=5 --code-package=1`.
5. Verify: the CLI checklist; `php bin/wallet_reconcile.php`; open `/admin` → health widget (all green).
6. Sign in as `admin`; generate any extra codes from `/admin/codes`; continue the QA script.
7. If a reset goes wrong: stop, restore the backup (10.6), do not patch balances.

### 10.3 Online first install through the web installer

1. Panel: create the database + user; note host/name/user/password.
2. Upload the app **without** the local `config.local.php`; confirm the four `.htaccess` files.
3. Create `app/config.local.php` with `APP_ENV=production`, DB settings, `APP_URL`, and a 32+ character `INSTALL_KEY`.
4. Open `https://…/system/install.php`; resolve every red preflight item.
5. Fill the site name and both staff credentials; paste the key; type `INSTALL`.
6. Read the success report; **delete `install.php` immediately**; remove `INSTALL_KEY` from config.
7. Sign in; change passwords again if there is any doubt; configure settings; run the post-install checklist (10.5).

### 10.4 Online first install through phpMyAdmin

1. Panel: create the database + user.
2. phpMyAdmin → select the database → Import → `install.sql` → Go.
3. Confirm the footer counts; if the guard fired, drop the database and recreate it (it was not empty).
4. Upload `config.local.php` (no `INSTALL_KEY` needed unless the web installer is kept).
5. Sign in, change both staff passwords, configure settings.
6. Do **not** keep `install.sql` in the web root after the import; it is not needed at runtime.

### 10.5 Post-install / post-reset smoke checklist

- [ ] `/` or `/login` loads; staff sign-in works; the dashboard shows zero members/orders after a reset.
- [ ] `/admin/products` shows the expected catalogue (7 after a fresh install; unchanged after a keep-catalogue reset).
- [ ] `/admin/packages` shows 2 packages with their ladders/contents.
- [ ] `/admin/members` shows 0 after a reset.
- [ ] `/admin/codes` shows the generated codes (or 0).
- [ ] `/shop` renders; a product page opens.
- [ ] `/admin` health widget: all checks green.
- [ ] `php bin/wallet_reconcile.php` exits 0 (when CLI is available).
- [ ] No `DEMO-` code or demo user exists unless demo was explicitly requested.
- [ ] `/system/install.php` and `/system/reset.php` return 404 on production.

### 10.6 Restore after a failed install or reset

1. Put the site in maintenance if it is live (`settings.maintenance_mode = 1` via the admin, or a temporary `.htaccess`).
2. Drop the damaged schema and recreate it (do not try to patch it):
   ```sql
   DROP DATABASE <name>;
   CREATE DATABASE <name> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import the most recent verified dump:
   ```powershell
   # PowerShell
   Get-Content .\backups\verified.sql -Raw | mysql -u <user> -p <name>
   ```
   ```bash
   # bash
   mysql -u <user> -p <name> < backups/verified.sql
   ```
4. Run `php bin/wallet_reconcile.php` (expect exit 0) and `php bin/db_reset.php --dry-run` (read-only) to confirm the counts.
5. Turn maintenance off; sign in; spot-check members/orders/ledger.
6. Record the incident in `docs/phase-log.md` and add a regression case if the cause was a code defect.

If the dump predates a migration, apply the missing `database/migrations/NNN_*.sql` files in order **after** the import, then re-run the reconciliations.

### 10.7 When to use reset, restore or migrations

| Need | Action |
|---|---|
| Clear QA/test data and return to a clean install state | **Reset** (`bin/db_reset.php` or `/reset.php`) |
| Undo a bad reset/import, or recover lost production data | **Restore** from the last verified dump (10.6) |
| Add a new table/column/index for an existing database | **Migration** (`database/migrations/NNN_*.sql`), never `install.sql` |
| Create a brand-new database | **install.sql** or an installer |
| Keep the catalogue but clear transactions | Reset with the default (keep catalogue) |
| Replace the catalogue with the starter one | Reset with `--reset-catalogue` |
| Reload demo members/codes | Reset with `--demo` (or install with demo) |

---

## 11. Documentation and go-live

### 11.1 Documentation updates

| Document | Sections | Content |
|---|---|---|
| `system/README.md` | Requirements, Quick start, Configuration, Directory layout, Tests, Migrations | One-file install; installer paths; expected counts; `INSTALL_KEY`/`DB_RESET_ENABLED`; new files in the layout; test groups; reset section; fix F-10 (`APP_PHASE` 15) |
| `docs/deploy.md` | §4–§7, §11.6, §12, §16 | Database creation unchanged; §5B becomes `install.sql`/web installer; §7 adds `INSTALL_KEY`; §11.6 verification pack; §12 tool removal + demo checks; final checklist |
| `docs/runbook.md` | New "Install & reset" section | The procedures in Section 10, with exact commands and expected output |
| `docs/phase-log.md` | New Phase 16 entries | 16.1 = IR-1/IR-2, 16.2 = IR-3, 16.3 = IR-4, 16.4 = IR-5, 16.5 = IR-6, 16.6 = IR-7, 16.7 = IR-8; each with files, tests, evidence, decisions |
| `docs/qa/phase-16-install-reset.md` | New | Scenarios A–H from 9.3, expected results, evidence index, sign-off |
| `docs/qa/README.md`, `docs/qa/sign-off-sheet.md` | Phase table | Phase 16 row + columns for install/reset gates |
| `dmp/plan-ecowealth-cart-system.md` | Install/reset references | One line pointing to this plan; no rewrite of the Phase 1–15 content |
| `.gitignore` | Runtime | `system/storage/install.lock` |

### 11.2 Go-live checklist additions (append to `docs/deploy.md` §12)

- [ ] Delete `public/install.php`; confirm `/system/install.php` → 404.
- [ ] Delete `public/reset.php`; confirm `/system/reset.php` → 404.
- [ ] If an installer was used, confirm `storage/install.lock` exists and is not web-readable; a static `install.sql` import has no lock file (the populated schema and the deleted tools are the guard).
- [ ] Remove `INSTALL_KEY` from `config.local.php`.
- [ ] Confirm `DB_RESET_ENABLED` is absent or `false`.
- [ ] Confirm neither staff password is a documented default.
- [ ] Confirm no demo users (`juan`, `maria`, `peter`, `sarah`, `david`) and no `DEMO-%` codes exist.
- [ ] Run the verification pack (Appendix E) and file its output.
- [ ] Take a post-launch backup.
- [ ] Re-run `php bin/wallet_reconcile.php` if CLI is available.

### 11.3 Phase-log entries

| Entry | Covers | Evidence |
|---|---|---|
| Phase 16.1 | IR-1 + IR-2: plumbing, bundle, seed fixes, parity | test output, `install.sql` checksum, local import transcript |
| Phase 16.2 | IR-3: CLI installer | `--check`/install transcript, lock JSON |
| Phase 16.3 | IR-4: web installer | preflight/success/refusal screenshots |
| Phase 16.4 | IR-5: reset engine + CLI | reset transcript, `wallet_reconcile` output, `DbReset` results |
| Phase 16.5 | IR-6: reset console | ten-state screenshots, gate test results |
| Phase 16.6 | IR-7: demo + parity | parity assertion output, demo screenshots |
| Phase 16.7 | IR-8: hardening/docs/go-live | full suite + smoke output, sign-off sheet |

### 11.4 Tool-retirement matrix

| Environment | `install.php` | `reset.php` | `storage/install.lock` | `INSTALL_KEY` | `DB_RESET_ENABLED` |
|---|---|---|---|---|---|
| Local development | Keep | Keep | Present after an installer run; absent after a static import | Not needed | `false` (unused) |
| Staging/QA | Keep until the QA round ends | Keep | Present when an installer was used | Optional (if web installer used) | `false` or explicit |
| Production | **Delete after install** | **Delete** | Present if an installer was used; absent for a static import | **Remove after install** | Absent/`false` |
| Disaster-recovery copy | Absent | Absent | Same as the install method | Absent | Absent |

A production site that must be re-installed after a disaster drops the DB, re-uploads `install.php` + `install.sql` temporarily, installs, and deletes them again; this is documented in the runbook and treated as a change window.

---

## 12. Risks, assumptions and mitigations

### 12.1 Risks

| # | Risk | Likelihood | Impact | Mitigation |
|---:|---|---|---|---|
| R-1 | A reset is run against the wrong database (dev/prod) | Medium | Critical | Typed database-name confirmation (CLI/prod web); preview shows host/db; advisory lock; `--allow-production`; tool deleted at go-live |
| R-2 | A reset fails halfway and leaves mixed data | Low | High | One DML transaction; FK checks on; rollback test; post-commit steps reported separately |
| R-3 | `install.sql` drifts from `schema.sql`/seeds | Medium | High | Generated file + `--check` drift test + data-parity tests; rebuild is part of the definition of done |
| R-4 | A migration adds a setting/seed that fresh installs miss (F-01 class) | Medium | Medium | `SeedParityTest` parses migrations; future migrations must add their seed key or fail the suite |
| R-5 | Shared-hosting import limit/timeout breaks a web install | Medium | Medium | 57 KB bundle; `set_time_limit`; phpMyAdmin/gzip fallback; preflight shows limits |
| R-6 | The web installer is reachable before the owner | Medium | Critical | Empty-DB-only + `INSTALL_KEY` in production + throttle + immediate lock; delete after use |
| R-7 | The reset console is left online | Medium | Critical | `DB_RESET_ENABLED` default false; 404 in production; go-live deletion + verification |
| R-8 | A reset wipes the operator's real catalogue unintentionally | Low | High | Keep-catalogue default; catalogue reset is an explicit checkbox/flag; preview shows counts; backup requirement |
| R-9 | Audit rows reference deleted members | High (by design) | Low | Documented; audit is append-only; `--clear-audit` for a pristine trail |
| R-10 | Auto-increment gaps after a reset confuse support | Medium | Low | Documented D-IR-15; only empty tables reset; ids are not business keys |
| R-11 | Demo data reaches production | Low | High | `--demo` explicit, production refusal, go-live query for `juan`/`DEMO-%` |
| R-12 | Tests damage the development DB | Low | Critical | `_test` suffix enforcement; scratch schemas; harness rebuilds `ecowealth_sneat_test`; no test targets `ecowealth_sneat` |
| R-13 | `db()`'s `exit()` on connection failure breaks the reset page | Medium | Medium | `DbTarget` pre-check before `bootstrap.php`; standalone failure page |
| R-14 | Password handling leaks via shell history | Medium | Medium | `--admin-password-stdin`, prompt warning, web `type=password`; never printed in reports |
| R-15 | Future MySQL/MariaDB differences (`VALUES()`, CHECK, JSON) | Low | Medium | Support matrix; `INSERT IGNORE` in the bundle; avoid dialect-specific syntax in install.sql; tests run on the project's MySQL 8.4 |
| R-16 | Tool removal breaks a link someone bookmarked | Low | Low | 404 is the correct production response; docs state the tools are temporary |

### 12.2 Assumptions

1. The target server is MySQL 8.0.16+ or MariaDB 10.4+, with `utf8mb4` and `CHECK` support.
2. The deploying user can create a database **or** has one pre-created with full privileges on it.
3. The app remains at application Phase 15 / migration 019 when IR-2 lands; a new migration during the workstream requires a bundle rebuild and a `SeedParityTest` update in the same change.
4. Shared hosting permits `.htaccess` rewrites (Hostinger does) and PHP 8.1+ with `pdo_mysql`, `fileinfo`, `mbstring`.
5. The operator can take a database dump before destructive work (phpMyAdmin export or `mysqldump`).
6. The reset tool is for development/staging; production use is an explicit, documented exception.
7. `config.local.php` is the only place deployment credentials live, and it is never committed.
8. The reference's operator experience is the UX target, not its implementation; changes are deliberate and listed in Section 4.

---

## 13. Appendices

### Appendix A — Complete reset SQL (transactional block)

This is the block `DatabaseResetService::run()` executes between `BEGIN` and `COMMIT`. It is written out in full so implementation and review use the same text. Placeholders are PDO named parameters. **No** `SET FOREIGN_KEY_CHECKS` appears anywhere.

```sql
-- ============================================================
-- 1. S-Login sessions
-- ============================================================
DELETE FROM impersonation_log;

-- ============================================================
-- 2. Shop: children before orders
-- ============================================================
DELETE FROM shop_payment_proofs;
DELETE FROM shop_invoice_items;
DELETE FROM shop_invoices;
DELETE FROM shop_shipments;
DELETE FROM shop_order_events;
DELETE FROM shop_order_items;
DELETE FROM shop_orders;

-- ============================================================
-- 3. Carts
-- ============================================================
DELETE FROM cart_items;
DELETE FROM carts;

-- ============================================================
-- 4. Address book
-- ============================================================
DELETE FROM addresses;

-- ============================================================
-- 5. E-wallet (ledger first, then the logs that reference users)
-- ============================================================
DELETE FROM ewallet_ledger;
DELETE FROM ewallet_transfers;
DELETE FROM ewallet_admin_topups;

-- ============================================================
-- 6. Payout queue
-- ============================================================
DELETE FROM payout_requests;

-- ============================================================
-- 7. Commissions before their activations
-- ============================================================
DELETE FROM commissions;
DELETE FROM activations;

-- ============================================================
-- 8. Registration codes (before members and packages)
-- ============================================================
DELETE FROM reg_codes;

-- ============================================================
-- 9. Session cookies, then member accounts
--    (all remember-me tokens are invalidated, staff included)
-- ============================================================
DELETE FROM remember_tokens;

DELETE FROM users WHERE role = 'member';

-- ============================================================
-- 10. Staff back to the install state
--     (identity, password, profile and payout accounts kept)
-- ============================================================
UPDATE users SET
  package_id             = NULL,
  sponsor_id             = NULL,
  reg_code_id            = NULL,
  reg_paid_by            = NULL,
  reg_payment_method     = 'standard',
  activated_at           = NULL,
  referral_code          = NULL,
  member_status          = 'active',
  is_active              = 1,
  ewallet_balance        = 0.00,
  withdrawable_balance   = 0.00,
  ewallet_sent_today     = 0.00,
  ewallet_sent_this_week = 0.00,
  last_login_at          = NULL
WHERE role IN ('admin', 'superadmin');

-- ============================================================
-- 11. OPTIONAL — catalogue reset (--reset-catalogue only)
--     Products/packages are otherwise kept.
-- ============================================================
DELETE FROM package_products;
DELETE FROM package_levels;
DELETE FROM packages;
DELETE FROM products;

-- ============================================================
-- 12. OPTIONAL — audit clear (--clear-audit only)
-- ============================================================
DELETE FROM audit_log;

-- ============================================================
-- 13. Reset audit row (always)
--     actor_id/actor_role: web staff, or NULL/'system' on CLI.
-- ============================================================
INSERT INTO audit_log
  (actor_id, actor_role, action, subject_type, subject_id, meta_json, ip)
VALUES
  (:actor_id, :actor_role, 'system.db_reset', 'database', NULL, :meta_json, :ip);

-- ============================================================
-- 14. Reset metadata (executed once per key, two placeholders)
-- ============================================================
INSERT INTO settings (key_name, value) VALUES ('last_reset_at', ?)
  ON DUPLICATE KEY UPDATE value = ?;
INSERT INTO settings (key_name, value) VALUES ('last_reset_by', ?)
  ON DUPLICATE KEY UPDATE value = ?;

-- ============================================================
-- 15. Live surfaces move forward (inside T1)
--     Existing rows increment; a missing surface starts at 1.
-- ============================================================
UPDATE live_revisions SET revision = revision + 1;

INSERT IGNORE INTO live_revisions (surface, revision) VALUES
  ('admin.dashboard', 1), ('admin.products', 1), ('admin.packages', 1),
  ('admin.orders', 1), ('admin.members', 1), ('admin.activations', 1),
  ('admin.codes', 1), ('admin.commissions', 1), ('admin.wallets', 1),
  ('admin.payouts', 1), ('admin.reports', 1), ('admin.settings', 1),
  ('admin.audit', 1), ('admin.slogin', 1), ('shop.catalog', 1),
  ('shop.packages', 1), ('member.genealogy', 1), ('config', 1);
```

After `COMMIT`, the service re-seeds the catalogue (if requested), installs the demo (if requested), inserts codes, and verifies. The catalogue re-seed uses the real seed files, not a second hard-coded copy:

```php
// inside T1, after the DELETE block, when $options['reset_catalogue']
SqlScript::fromFile($appRoot . '/database/seeds/003_products.sql')->run($pdo);
SqlScript::fromFile($appRoot . '/database/seeds/004_packages.sql')->run($pdo);
```

### Appendix B — `install.sql` header and footer skeleton

**Header (`database/install/header.sql`)**

```sql
-- ============================================================
--  EcoWealth — production install bundle
--
--  GENERATED FILE — do not edit. Rebuild with:
--      php bin/build_install_sql.php
--
--  Use on an EMPTY database. This file contains no CREATE DATABASE
--  and no USE statement: the target schema is selected by the caller.
--
--  Local:
--      mysql -u root -e "CREATE DATABASE ecowealth_sneat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      mysql -u root ecowealth_sneat < database/install.sql
--  Online (phpMyAdmin):
--      Databases -> select the schema -> Import -> install.sql
--
--  It creates: 27 tables · 48 settings · 2 staff accounts ·
--  7 starter products · 2 packages · 18 ladder levels · 10 contents.
--  It creates NO demo members, NO demo codes and NO demo wallets.
--
--  Default staff accounts (CHANGE BOTH PASSWORDS IMMEDIATELY):
--      admin      / Admin@123
--      superadmin / Super@123
--  The guided installers (bin/db_install.php, /install.php) set
--  operator-chosen passwords instead.
--
--  Requires MySQL 8.0.16+ or MariaDB 10.4+.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ------------------------------------------------------------
--  Empty-database guard. A non-empty schema fails here with a
--  deliberate "table doesn't exist" error before any data is
--  touched. Do not replace this with a conditional that hides
--  the failure: a partial re-import is worse than a stop.
-- ------------------------------------------------------------
SET @ew_present := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE()
);
SET @ew_guard := IF(@ew_present = 0,
  'DO 0',
  'SELECT * FROM `ecowealth_install_requires_an_empty_database`'
);
PREPARE ew_guard FROM @ew_guard;
EXECUTE ew_guard;
DEALLOCATE PREPARE ew_guard;

-- ─── SCHEMA ─────────────────────────────────────────────────
-- source: database/schema.sql sha256=<hash>
```

**Footer (`database/install/footer.sql`)**

```sql
-- ─── INSTALL METADATA ───────────────────────────────────────
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('install_version',      '1.0.0'),
  ('install_channel',      'install.sql'),
  ('install_completed_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));

-- ─── VERIFICATION ───────────────────────────────────────────
SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE())                                 AS tables_created,
  (SELECT COUNT(*) FROM settings)                                    AS settings_rows,
  (SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin'))  AS staff_rows,
  (SELECT COUNT(*) FROM users WHERE role = 'member')                 AS members_rows,
  (SELECT COUNT(*) FROM products)                                    AS products_rows,
  (SELECT COUNT(*) FROM packages)                                    AS packages_rows,
  (SELECT COUNT(*) FROM package_levels)                              AS levels_rows,
  (SELECT COUNT(*) FROM package_products)                            AS contents_rows,
  (SELECT COUNT(*) FROM reg_codes)                                   AS codes_rows;
```

Section banners the builder inserts (in order): `SCHEMA`, `SETTINGS`, `STAFF ACCOUNTS`, `STARTER PRODUCTS`, `PACKAGES`, `INSTALL METADATA`, `VERIFICATION`. Each includes a `-- source: <path> sha256=<hash>` line for provenance.

### Appendix C — CLI reference

#### `php bin/build_install_sql.php`

| Flag | Effect |
|---|---|
| *(none)* | Write `database/install.sql` |
| `--check` | Compare bytes; exit 1 with the first difference when stale |
| `--stdout` | Print the bundle instead of writing |
| `--help` | Usage |

Exit: `0` built/matches, `1` stale/bad source, `2` usage.

#### `php bin/db_install.php`

| Flag | Default | Notes |
|---|---|---|
| `--check` | off | Preflight only; no writes, no database creation |
| `--database=NAME` | `DB_NAME` | Must match `/^[A-Za-z0-9_]{1,64}$/` |
| `--create-database` | off | Requires `CREATE` privilege; otherwise a hint to create it in the panel |
| `--admin-user=` / `--admin-email=` | `admin` / seed email | Validated per 6.8 |
| `--admin-password=` | — | Visible in history; prefer `--admin-password-stdin` |
| `--admin-password-stdin` | — | Read one line from STDIN |
| `--superadmin-user=` / `--superadmin-email=` | `superadmin` / seed email | |
| `--superadmin-password=` / `--superadmin-password-stdin` | — | Same policy |
| `--site-name=` | `EcoWealth` | 1–80 chars |
| `--with-demo` | off | Refused in production; requires the default packages |
| `--key=` | `INSTALL_KEY`/env | Required in production |
| `--force` | off | Skips the interactive confirmation only |
| `--json` | off | One JSON report on STDOUT |

Exit: `0` installed+verified, `1` preflight/verify failed, `2` usage, `3` refused/locked.

Examples:

```bash
# preflight only
php bin/db_install.php --check --database=ecowealth_sneat

# fresh local install, passwords from stdin
printf '%s\n%s\n' "$ADMIN_PW" "$SUPER_PW" | \
  php bin/db_install.php --database=ecowealth_sneat \
    --admin-user=admin --admin-email=owner@example.com --admin-password-stdin \
    --superadmin-user=superadmin --superadmin-email=owner+super@example.com \
    --superadmin-password-stdin --site-name="EcoWealth" --force
```

```powershell
# PowerShell equivalent (development)
$env:ECO_DB_NAME = 'ecowealth_sneat_install_manual'
php bin/db_install.php --check
php bin/db_install.php --database=$env:ECO_DB_NAME --admin-user=admin `
  --admin-email=owner@example.com --admin-password-stdin `
  --superadmin-user=superadmin --superadmin-email=owner+super@example.com `
  --superadmin-password-stdin --site-name="EcoWealth" --force
```

#### `php bin/db_reset.php`

| Flag | Default | Notes |
|---|---|---|
| `--dry-run` | off | Preview + plan; no writes |
| `--force` | off | Non-interactive mode; requires `--confirm=<database>` |
| `--confirm=NAME` | — | The database name; the only accepted confirmation |
| `--keep-catalogue` | **on** | Keep products/packages |
| `--reset-catalogue` | off | Delete and re-seed the starter catalogue |
| `--codes=N` | `0` | 0–50 unused registration codes |
| `--code-package=ID` | first active package | Package bound to the codes |
| `--demo` | off | Demo tree after the reset (not production) |
| `--keep-audit` | **on** | Keep the audit log |
| `--clear-audit` | off | Delete the audit log before the reset row |
| `--keep-uploads` | off | Keep proof images on disk |
| `--allow-production` | off | Required when `APP_ENV=production` |
| `--json` | off | One JSON report on STDOUT |
| `--help` | — | Usage |

Exit: `0` reset+verified, `1` reset committed but verification failed, `2` usage/preflight, `3` aborted/refused.

Examples:

```bash
php bin/db_reset.php --dry-run
php bin/db_reset.php --force --confirm=ecowealth_sneat --codes=5 --code-package=1
php bin/db_reset.php --force --confirm=ecowealth_sneat --reset-catalogue --demo
php bin/db_reset.php --force --confirm=ecowealth_sneat --clear-audit --json
```

Production (only during an approved change window):

```bash
php bin/db_reset.php --allow-production --confirm=u123456_ecowealth
```

### Appendix D — Report and session shapes

**CLI JSON (both tools)** — top-level keys:

| Key | Type | Meaning |
|---|---|---|
| `tool` | string | `db_install` / `db_reset` |
| `version` | int | Report schema version (`1`) |
| `ok` | bool | Overall success (verification included) |
| `database` | string | Target schema |
| `started_at`, `duration_ms` | string, int | Timing |
| `options` | object | Effective options |
| `steps` | array | `key`, `label`, `status` (`ok`/`skip`/`fail`), `deleted`/`rows`, `ms`, optional `error` |
| `counts_before`, `counts_after` | object | Per-table counts (reset) |
| `staff` | object | `reset`, `usernames` |
| `audit` | object | `kept`, `rows_before`, `cleared` |
| `auto_increment` | object | `reset`, `failed` (counts of tables) |
| `codes` | object | `generated`, `package`, `list` |
| `demo` | object | `installed`, `members`, `codes`, `credentials` |
| `verification` | object | `ok` + `checks[]` (`key`, `ok`, `detail`) |
| `warnings` | array | Non-fatal issues |

**Web reset session** — `$_SESSION['db_reset_report']` holds the same array plus `rendered_at`; it is replaced on the next reset and removed by the "Done" action. The page never re-executes from a session read.

**Install lock** (`storage/install.lock`) — JSON: `installed_at`, `database`, `app_version`, `install_sql_sha256`, `staff` (usernames only), `channel`. No secret, no password hash.

**Audit meta** (`audit_log.meta_json` for `system.db_reset`) — `tool`, `database`, `options`, `counts_before`, `counts_after`, `catalogue_reset`, `codes`, `demo`, `audit_cleared`, `duration_ms`.

### Appendix E — Verification query pack

Run after an install or a reset (and before signing off a deployment).

```sql
-- 1. Table count: 27
SELECT COUNT(*) AS tables_created
  FROM information_schema.tables
 WHERE table_schema = DATABASE();

-- 2. Row counts. After a reset: every table below is 0 except
--    audit_log (kept; +1 reset row), live_revisions (18 bumped
--    surfaces) and reg_codes (the generated count).
--    After a fresh install: all are 0, including audit_log and
--    live_revisions (nothing has written yet).
SELECT 'addresses' AS t, COUNT(*) AS n FROM addresses
UNION ALL SELECT 'activations',              COUNT(*) FROM activations
UNION ALL SELECT 'audit_log',                COUNT(*) FROM audit_log
UNION ALL SELECT 'cart_items',               COUNT(*) FROM cart_items
UNION ALL SELECT 'carts',                    COUNT(*) FROM carts
UNION ALL SELECT 'commissions',              COUNT(*) FROM commissions
UNION ALL SELECT 'ewallet_admin_topups',     COUNT(*) FROM ewallet_admin_topups
UNION ALL SELECT 'ewallet_ledger',           COUNT(*) FROM ewallet_ledger
UNION ALL SELECT 'ewallet_transfers',        COUNT(*) FROM ewallet_transfers
UNION ALL SELECT 'impersonation_log',        COUNT(*) FROM impersonation_log
UNION ALL SELECT 'live_revisions',           COUNT(*) FROM live_revisions
UNION ALL SELECT 'payout_requests',          COUNT(*) FROM payout_requests
UNION ALL SELECT 'reg_codes',                COUNT(*) FROM reg_codes
UNION ALL SELECT 'remember_tokens',          COUNT(*) FROM remember_tokens
UNION ALL SELECT 'shop_invoice_items',       COUNT(*) FROM shop_invoice_items
UNION ALL SELECT 'shop_invoices',            COUNT(*) FROM shop_invoices
UNION ALL SELECT 'shop_order_events',        COUNT(*) FROM shop_order_events
UNION ALL SELECT 'shop_order_items',         COUNT(*) FROM shop_order_items
UNION ALL SELECT 'shop_orders',              COUNT(*) FROM shop_orders
UNION ALL SELECT 'shop_payment_proofs',      COUNT(*) FROM shop_payment_proofs
UNION ALL SELECT 'shop_shipments',           COUNT(*) FROM shop_shipments;

-- 3. Staff: 2 rows, zeroed, active
SELECT id, username, role, member_status, is_active,
       package_id, sponsor_id, ewallet_balance, withdrawable_balance,
       ewallet_sent_today, ewallet_sent_this_week, last_login_at
  FROM users
 WHERE role IN ('admin','superadmin')
 ORDER BY id;

-- 4. Members: 0 after a reset, 0 on a fresh production install,
--    5 only when demo data was explicitly installed
SELECT COUNT(*) AS members FROM users WHERE role = 'member';

-- 5. Catalogue: 7 / 2 / 18 / 10
SELECT (SELECT COUNT(*) FROM products)         AS products,
       (SELECT COUNT(*) FROM packages)         AS packages,
       (SELECT COUNT(*) FROM package_levels)   AS levels,
       (SELECT COUNT(*) FROM package_products) AS contents;

-- 6. Demo leakage: must be 0 on production
SELECT COUNT(*) AS demo_users
  FROM users
 WHERE username IN ('juan','maria','peter','sarah','david');
SELECT COUNT(*) AS demo_codes FROM reg_codes WHERE code LIKE 'DEMO-%';

-- 7. Live revisions: 0 after a static install.sql import, the touched
--    surfaces after an installer run, 18 standard surfaces after a
--    reset (pre-existing rows incremented, missing rows at 1)
SELECT COUNT(*) AS surfaces FROM live_revisions;

-- 8. Auto-increment: empty tables should report 1 or NULL
SELECT table_name, auto_increment
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN ('shop_orders','ewallet_ledger','reg_codes','activations',
                      'commissions','payout_requests','impersonation_log')
 ORDER BY table_name;

-- 9. Install/reset metadata
SELECT key_name, value
  FROM settings
 WHERE key_name IN ('install_version','install_channel','install_completed_at',
                    'last_reset_at','last_reset_by')
 ORDER BY key_name;
```

Expected after a **fresh production install**: 27 tables; 48 settings; 2 staff; 0 members; 7 products; 2 packages; 18 levels; 10 contents; 0 codes; 0 demo rows; `live_revisions` 0 after a static `install.sql` import (an installer may bump the surfaces it touches); install metadata present; no `last_reset_*` yet.
Expected after a **default reset**: 27 tables; settings kept plus `last_reset_at`/`last_reset_by`; 2 staff zeroed and active; 0 members; the catalogue counts unchanged (7/2/18/10 for the starter catalogue); `reg_codes` equal to the generated count; 18 standard live surfaces (pre-existing revisions incremented, missing rows at 1); `audit_log` kept plus one `system.db_reset` row. With `--reset-catalogue --demo`, the catalogue returns to 7/2/18/10, members become 5, and demo codes become 3.

### Appendix F — Deployment artefact manifest

| Artefact | Local dev | Staging | Production |
|---|---|---|---|
| `system/database/install.sql` | Keep | Keep for a fresh install | Optional; delete after import |
| `system/bin/db_install.php` | Keep | Keep | Remove (no SSH on most shared hosts) |
| `system/public/install.php` | Keep | Keep until installed | **Delete after install** |
| `system/bin/db_reset.php` | Keep | Keep | Remove |
| `system/public/reset.php` | Keep | Keep for QA | **Delete** |
| `system/app/Services/DatabaseInstaller.php`, `DatabaseResetService.php`, `DemoDataService.php` | Keep | Keep | Keep (unreachable without the front-ends; harmless) |
| `system/public/assets/css/db-tools.css` | Keep | Keep | Delete with the pages, or keep (unused static file) |
| `system/storage/install.lock` | Runtime (installer paths) | Runtime | **Keep when present** (proof of a guided install; not web-readable). Absent after a static `install.sql` import. |
| `system/database/seeds/005_demo_tree.sql` | Keep | Keep (manual use) | Never run |
| `system/tests/`, `dmp/`, `docs/`, `backups/` | Keep | Optional | Do not upload |

### Appendix G — Acceptance sign-off sheet

| Phase | Gate evidence | Result | Initials | Date |
|---|---|---|---|---|
| IR-0 | `baseline.txt`, restored dump, green suite | ☑ | DSH | 2026-11-11 |
| IR-1 | `SqlScript`/`DbTarget` groups + full suite | ☑ | DSH | 2026-11-11 |
| IR-2 | `build_install_sql --check`, `InstallSql`/`SeedParity`, local mysql + phpMyAdmin imports | ☑ | DSH | 2026-11-11 |
| IR-3 | `db_install --check`, fresh CLI install, lock JSON, refusal cases | ☑ | DSH | 2026-11-11 |
| IR-4 | Web installer preflight/success/refusal screenshots + smoke | ☑ | DSH | 2026-11-11 |
| IR-5 | Reset CLI transcript, `wallet_reconcile` exit 0, `DbReset` group | ☑ | DSH | 2026-11-11 |
| IR-6 | Ten-state browser matrix screenshots + `DbToolAccess` group | ☑ | DSH | 2026-11-11 |
| IR-7 | Demo install/reset screenshots + both parity cases | ☑ | DSH | 2026-11-12 |
| IR-8 | Full suite + smoke + online import + go-live checklist + docs review | ☑ | DSH | 2026-11-12 |

Final sign-off:

| Role | Name | Decision | Date |
|---|---|---|---|
| Implementation lead | DSH (automated implementation pass) | Accepted | 2026-11-12 |
| QA | DSH (automated QA pass, `docs/qa/sign-off-sheet.md`) | Accepted | 2026-11-12 |
| Operations/owner | | Accepted / rejected | |

### Appendix H — Glossary

| Term | Meaning |
|---|---|
| **Bundle** | `database/install.sql`: schema + production seeds in one generated file |
| **Clean install state** | The state a fresh install produces: staff + settings + starter catalogue, zero business rows (audit/live-revision rows excepted by design) |
| **Scratch schema** | A pre-granted database whose name ends in `_test`; the tests recreate it per case and never drop the grant |
| **Advisory lock** | `GET_LOCK()`/`RELEASE_LOCK()` used to serialise installs and resets |
| **Install lock** | `storage/install.lock`, a JSON marker written after a successful install; makes re-installs refuse |
| **PRG** | Post/Redirect/Get: the reset web console stores the report in the session and redirects, so refresh cannot re-run |
| **Natural key** | A business identifier (`username`, `slug`, `sku`, `level`) used for comparisons instead of ids |
| **Seed parity** | The guarantee that settings/seed data inserted by migrations also exist for a fresh install |
| **Tool retirement** | Deleting `install.php`/`reset.php` (and the key) before going live |
| **IR phase** | One phase of this workstream (IR-0…IR-8), distinct from the application's Phase 1–15 |

---

## IR-0 sign-off — contract freeze (2026-11-11)

**Status:** IR-0 complete — baseline captured, backup verified, names/flags frozen, no application code changed.

| Item | Record |
|---|---|
| Baseline | `docs/qa/evidence/install-reset/baseline.txt` — commit `0ced89e`, PHP 8.3.16, MySQL 8.4.3, 27 tables / 4,287 rows, 45 settings, suite **724 / 724 / 0** |
| Backup | `backups/pre-install-reset-20261111.sql` — 588,383 bytes, sha256 `8d89eab7…`, git-ignored; restored into `ecowealth_sneat_restore_test` as 27/27 tables and 4,287/4,287 rows |
| Decisions | D-IR-01 … D-IR-18 reviewed; accepted unchanged |
| Names and flags | §5.2 file map, §5.7 CLI surface, Appendix C flags/exit codes and Appendix D report/lock shapes frozen |
| Evidence convention | `docs/qa/evidence/install-reset/README.md` |
| Ignore rule | `system/storage/install.lock` added to `.gitignore` |
| Application code | No `system/app`, `system/bin`, `system/public`, `system/database` or `system/tests` file changed in this phase |

**Baseline correction (IR-0-F1).** The suite reports **724** cases, not the 722 written in §1.3/§2.3/IR-0: commit `0ced89e` added two `SettingsPageTest` cases after the plan's count was taken (`docs/phase-log.md`, "Suite 722 → 724"). Later phases treat **724** as the existing-case count. The stale `722` references in `system/README.md` and `docs/qa/README.md` are IR-8 documentation work; historical Phase 15 evidence is not rewritten.

**Contract sign-off.** The file names and the CLI/report shapes above are accepted as the implementation contract. A rename or flag change after IR-1 starts requires a new decision entry in this document and a re-run of the IR-0 gate.

---

## IR-8 sign-off — install/reset workstream complete (2026-11-12)

**Status:** IR-8 complete and the workstream closed — security review, full documentation pass, go-live checklist and sign-off. No application runtime file changed in this phase: the edits are documentation, the plan itself and the evidence.

| Item | Record |
|---|---|
| **Security review** | `docs/qa/evidence/install-reset/ir8-security-review.txt`: shared hardening headers and `no-store` on both tool pages; no password, DSN, bcrypt hash or install key in the HTML or JSON; `storage/install.lock` outside `public/` and 0600 where the platform allows (NTFS inherits the folder ACL — documented); no route, navigation or `routes_inventory.php` entry; uploads/storage `.htaccess` guards; HttpOnly/SameSite=Lax/Secure-on-TLS sessions; `DB_RESET_ENABLED` false and `INSTALL_KEY` empty in the development config. No code change was required. |
| **Documentation** | `system/README.md` (F-10 fixed: `APP_PHASE` 15; 48 settings; 816 cases; 195 smoke checks; the seven tool test groups; one-bundle install + upgrade; the "Retire the tools (go-live)" section). `docs/deploy.md` (§5B, §6, §7, §11.6 verification pack, §12, §16, Appendix A/C). `docs/runbook.md` (§3 install/reset/restore, §5 upgrade rule, §7 go-live + retire-the-tools, §8 checks, §9 release gates). `docs/qa/phase-16-install-reset.md` (new, 16 checks A–H). `docs/qa/README.md` and `docs/qa/sign-off-sheet.md` (Phase 16 row, 216 checks, current counts, final decision). `docs/qa/evidence/install-reset/README.md`, `dmp/plan-ecowealth-cart-system.md` (one-line cross-reference). |
| **Go-live** | `go-live-checklist.txt` and `go-live-tools-404.png` from a scratch production-like install: both tool URLs 404 after deletion; lock present and not web-readable (404 via the PHP docroot, 403 via Apache); `INSTALL_KEY` never persisted; `DB_RESET_ENABLED` absent/false; neither staff hash matches `Admin@123`/`Super@123`; 0 demo users and 0 `DEMO-%` codes; the Appendix E counts; a post-launch dump (101,086 bytes, sha256 `322fc850…`). |
| **Final verification** | `final-verification.txt`: bundle `--check` exit 0 (sha256 `3e94fd28…`); `mysql` import + demo tree; the CLI reset with five codes (12/12 verification) and `wallet_reconcile` exit 0; the second reset idempotent; the browser installer in a production environment with a 35-character key (success, lock `channel: web`, operator passwords, 27/48/2/0/7/2/18/10/0); the tool-retirement capture. The phpMyAdmin import is the IR-2 capture for the same bundle bytes. |
| **Automated gates** | `php bin/build_install_sql.php --check` → exit 0 · `php tests/run.php` → **816 / 816 / 0**, exit 0 · `tests/smoke.ps1` → **195 / 195**, exit 0. Evidence: `ir8-suite.log`, `ir8-smoke.log`. |
| **QA sign-off** | `docs/qa/phase-16-install-reset.md` → **16 / 16** checks passed; `docs/qa/sign-off-sheet.md` §17 approves Phase 16 and §19 approves all sixteen phases. |
| **Sign-off sheet** | Appendix G above: every IR phase ticked; Implementation lead and QA accepted 2026-11-12; the Operations/owner line is left for the human countersignature. |
| **Tags** | `ir-8` and `ir-8-install-reset-complete` on the completing commit. |
| **Release artefact** | `system/database/install.sql` — committed, generated, byte-stable, sha256 `3e94fd28554294a051e43d88d8837c95a5d8aafb210121ae3caaf949764897bb` (54 statements, 63,695 bytes). |

**Exit criteria (IR-8).** All nine phase gates are green in order; `install.php`/`reset.php` are absent (404) on the production checklist copy; the documentation answers "How do I install locally?", "How do I install online?", "How do I reset?" and "How do I remove the tools?"; no default credential remains on a production path; a fresh install and a reset both verify clean.

**Deviations accepted and documented.**

1. The Windows lock file inherits the NTFS folder ACL instead of POSIX `0600`; it holds no secret and the folder is outside `public/` and deny-all.
2. The phpMyAdmin install is evidenced by the IR-2 capture; IR-8 proves the bundle is byte-identical to the imported file.
3. The manual reset-idempotency transcript deletes the five codes generated by the preceding reset (registration codes are inventory); every business-data step reports 0, matching the automated case.
4. The reset/install tools live in `system/public/` (served as `/system/…`), not at the app root like the reference, because the document root is `public/`.

**Workstream closure.** IR-0 … IR-8 are complete: the production install bundle and its generator, `DbTarget`/`SqlScript`/`DatabaseToolException`, the CLI and browser installers with the install lock and `INSTALL_KEY`, the reset engine with the CLI and the authenticated console, the demo fixtures with reset ≡ fresh-install parity, and the hardening/documentation/go-live sign-off. The application remains Phase 15; Phase 16 added no route or page, and the tools are retired by deleting two files and removing two constants.

