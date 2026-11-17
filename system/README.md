# EcoWealth — application (`system/`)

Membership, shop and e-wallet for **EcoWealth**, built as plain PHP 8 + MySQL with no
framework and no Composer. This folder is the backend application; the public marketing
front door is the repo-root `index.html` (decision D23 in `dmp/plan-ecowealth-cart-system.md`).

**Current status: Phase 21 — the General Harvest bonus, in progress 21A → 21G
(`dmp/plan-ecowealth-general-harvest.md`). 21A (foundation), 21B (configuration and the board
arithmetic) and 21C (the baseline) have landed; `APP_PHASE` is still 20 until 21G.**
Phase 15 delivered the hardening, dashboards, reports and launch work; Phase 16 (the IR-1 … IR-8
install/reset workstream, `dmp/plan-ecowealth-install-reset.md`) added the production install bundle,
the CLI and browser installers and the guarded development reset, changing no application route or
page. Phase 17 (the unilevel repeat-purchase workstream,
`dmp/plan-ecowealth-unilevel-repeat-purchase.md`) is **released in seven gates**: 17A the four tables,
six settings and two wallet movement kinds; 17B the per-product configuration; 17C the money engine —
the paid-order hook on both payment paths, the monthly Active gate, best-effort reversal and the
repeat-purchase reconciliation; 17D and 17E the admin and member surfaces; 17F the unattended
month-close job (doctor, dry run, log, lock and catch-up); and 17G the release (documentation, QA pack,
`APP_PHASE` 17 and the acceptance pass). Phase 18
(`dmp/plan-ecowealth-unilevel-bonus-escrow.md`, a **post-17 point release**) moves the cash event from
payment to release: a paid order writes **`pending`** awards and moves no money, an hourly release job
credits the upline's withdrawable wallet after delivery + the award's snapshotted hold, cancellation
before release cancels the awards with no shortfall, and the member/admin surfaces show **Pending
release** and **Total funds**. The stream ships **off** and pays nothing until an operator configures
a product and turns the global master on. A Phase 18 follow-up adds the **order-cancellation kill
switch**: `shop_cancel_enabled` (**Settings → Shop → Enable Cancel of Order**) ships **off**, so
neither a member nor an administrator can cancel an order — and therefore no wallet refund or
repeat-purchase escrow reversal runs — until an operator opens it for a special case. Automatic
expiry keeps its own switch and still closes unpaid orders.

The third workstream — **Phase 19, the Royalty bonus**
(`dmp/plan-ecowealth-royalty.md`) — is **released**: a rank-gated monthly bonus that pays
Supervisor/Manager/Director one award per closed calendar month. 19A added the two money tables, the
`royalty` wallet movement and sixteen settings (**33 tables / 73 settings** at 19A; the Phase 20
migration later takes the build to 36 / 77); 19B the
configuration card and the derived five-rank ladder; 19C the money engine (`RoyaltyService`) and its
close job; 19D the admin ledger, report cards, member panel and dashboard tile; 19E the member
statement and tile; **19F** the scheduled close (CLI, guarded URL entry and Windows launcher), its
`--doctor` report, the read-only **Operations** panel on the Royalty tab, the health check and the
runbook drill; and **19G** the release: `APP_PHASE` **19**, the QA pack signed (`docs/qa/phase-19-royalty.md`,
sections A–G, 60 checks), the Appendix B acceptance run and the documentation sweep. The stream still
ships **off**, so a fresh install pays nothing until an operator turns it on.

The fourth workstream — **Phase 20, the Team Harvest bonus**
(`dmp/plan-ecowealth-team-harvest.md`) — is **released**. **20A** added the three tables
(`harvest_queue`, `team_harvest_levels`, `team_harvest_awards`), the `team_harvest` wallet movement,
four settings and the ten reference amounts (migration `024`, **36 tables / 77 settings**); **20B** the
configuration card — the switch, the qualification gate, the payable depth, the ten flat amounts, the
generated 3×10 ladder and a four-state status strip; **20C** qualification (the registration hook, the
append-only positions, the teammate counters, the one-time seed and the rebuild drill); **20D** the
**money engine** — a completed level pays its configured amount, once, into the withdrawable e-wallet,
with an immutable ledger row and a `team_harvest` movement, honouring the go-live baseline and proved
by `wallet_reconcile.php` and the on-demand catch-up/doctor; **20E** the admin visibility (the fourth
commissions tab, the directory's Harvest column and queue card, the member panel, the reports block
and the dashboard KPI); **20F** the member visibility (the fourth `/earnings` tab with the matrix, the
dashboard tile and the genealogy badge); and **20G** the release: `APP_PHASE` **20**, the QA pack
signed (`docs/qa/phase-20-team-harvest.md`, sections A–G, 58 checks), the smoke walk (**263** checks)
and the documentation sweep. The stream still ships **off and unseeded**, so a fresh install pays
nothing until an operator runs the seed and turns it on.

The fifth workstream — **Phase 21, the General Harvest bonus**
(`dmp/plan-ecowealth-general-harvest.md`) — is **in progress**. It is one **shared 3-ary board** over
the Harvest queue: every qualified member holds a node (`seq = harvest_queue.id − 1`), a node's
level-`L` block is the `3^L` queue positions after it, and a level pays its flat amount once when that
block is full — whoever filled it. The money therefore depends on the whole network's queue length,
never on the member's own team. **21A** added the three tables (`general_harvest_levels`,
`general_harvest_board`, `general_harvest_awards`), the `general_harvest` wallet movement, two settings
and the ten reference amounts (migration `025`, **39 tables / 79 settings**), with no reader;
**21B** added the configuration card and the whole board arithmetic — the switch, the payable depth,
the ten amounts, the generated ladder and position tables, the four-state strip, the "if the queue
reaches N" calculator and `GeneralHarvestLevel` / `GeneralHarvestBoard`, which are the only way any
later phase may read an amount, a threshold, a block or a board total; **21C** added the go-live arm —
the one-time CLI job and `GeneralHarvestService` record `N₀` and one `baseline` row for every block
already full at all ten levels, and `GeneralHarvestAward` is the ledger (read plus the baseline write
only), so no money moves. 21D pays the armed board, 21E/21F add the admin and member surfaces and 21G
releases it. The stream ships **off and unarmed**, so nothing pays until an operator configures, arms
and enables it.

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
Phase 15 makes the build operable and launchable: the admin dashboard gains the commerce/finance
roll-up (sales today and 30 days, orders by status, top products, commissions, wallet float, payout
hold); a **Reports** desk adds sales-by-day, commissions-by-level, the wallet statement export, the
payout register, registration-code revenue and a **Finance health** widget over the seven plan
invariants, with five CSV exports (two more joined later: the repeat-purchase and Royalty exports); the settings page becomes eight independently saved cards
(General, Shop, Receiving accounts, Payout methods, Payment windows, Fulfilment & invoices,
Commissions, Transfers); the append-only **audit log** gets its own filterable desk; and the
security baseline is finished — shared response headers, session-scoped throttles on checkout,
proof upload, transfers and payouts, uploads/ execution denial, production error display off, and
report indexes (`019`). Operations are documented in [`docs/runbook.md`](../docs/runbook.md) and
[`docs/admin-guide.md`](../docs/admin-guide.md); a backup → restore drill was executed and recorded.
The expiry job runs from the CLI **and** from a guarded URL
(`/system/cron/shop_expire.php`); the auto-complete and transfer-limit jobs do the same at
`/system/cron/order_autocomplete.php` and `/system/cron/reset_transfer_limits.php`, and so do the
Phase 17 month close at `/system/cron/unilevel_month_close.php`, the Phase 18 release at
`/system/cron/unilevel_release.php` and the Phase 19F Royalty close at
`/system/cron/royalty_month_close.php`. All six log to `system/storage/`; the first two, the release
and the Royalty close have kill switches on the settings page.

| | |
|---|---|
| App URL (Laragon) | `http://localhost/ecowealth/system/` |
| Front door | `http://localhost/ecowealth/` → repo-root `index.html` |
| Deployment | [`docs/deploy.md`](../docs/deploy.md) — beginner guide for Hostinger / shared hosting |
| Operations | [`docs/runbook.md`](../docs/runbook.md) — daily checks, backups/restore, incidents, go-live |
| Admin guide | [`docs/admin-guide.md`](../docs/admin-guide.md) — page-by-page quick guide for the back office |
| Scheduled jobs | `docs/cron.md` (expiry, auto-complete, transfer-limit reset, unilevel month close, unilevel release, royalty month close: CLI, URL, Windows launcher, Hostinger, Task Scheduler) |
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

3. **Import the production bundle** (one file, empty schema only)

   ```bash
   mysql -u root ecowealth_sneat < database/install.sql
   ```

   `database/install.sql` creates the 39 tables, the 79 settings, both staff accounts,
   the seven starter products, the two packages, the ten Team Harvest ladder rows, the ten General
   Harvest ladder rows and the unarmed General Harvest board row. It seeds **no** demo members, demo
   codes or demo wallets, and it refuses to run against a non-empty schema.

   Prefer phpMyAdmin? Select the schema → **Import** → choose `database/install.sql` →
   select **Stop on error** → **Go**. The footer prints the verification counts.

   Expected after a fresh install:

   | Tables | Settings | Staff | Members | Products | Packages | Levels | Contents | Codes |
   |---:|---:|---:|---:|---:|---:|---:|---:|---:|
   | 33 | 73 | 2 | 0 | 7 | 2 | 18 | 10 | 0 |

   The bundle is **generated** from `database/schema.sql` and seeds 001–004. Edit those
   sources, then rebuild and verify:

   ```bash
   php bin/build_install_sql.php          # regenerate database/install.sql
   php bin/build_install_sql.php --check  # CI/drift gate: exit 0 when current
   ```

   **Want demo data?** Pass `--with-demo` to the CLI installer or tick **Seed the demo
   tree** in the web installer (development/QA only — both refuse in production). They run
   the real `database/seeds/005_demo_tree.sql` after the bundle: the sponsor tree
   `admin → juan → maria → peter/sarah/david`, three funded wallets and three sample
   registration codes. A plain `mysql`/phpMyAdmin import can also import that seed by hand
   afterwards. It is never part of the production bundle — keep it out of production.

   > **Fresh install only.** Existing databases are upgraded with
   > `database/migrations/NNN_*.sql` in numeric order; never re-import the bundle over
   > an existing schema. A failed/partial import is recovered by dropping and recreating
   > the schema, not by running the bundle again.

   **Prefer operator-chosen staff passwords?** `bin/db_install.php` imports the same
   bundle, replaces both seeded passwords with values you choose, verifies the result and
   writes `storage/install.lock` so the database cannot be installed over afterwards:

   ```bash
   php bin/db_install.php --check     # preflight only: every check, no writes
   php bin/db_install.php --database=ecowealth_sneat --force \
     --admin-email=owner@example.com --admin-password-stdin \
     --superadmin-email=owner+super@example.com --superadmin-password-stdin
   ```

   It prints the preflight table, imports into an **empty** schema, records
   `install_channel = 'cli'` and ends with the verification counts. A second run, a
   non-empty schema or an existing lock is refused (exit code 3); delete
   `storage/install.lock` only when you intend to wipe the database and re-install.
   `--json` prints exactly one machine-readable report; `--help` lists every flag.
   Passwords are 12–72 characters and may not be a published default; each
   `--…-password-stdin` reads one line, admin first.

   **No SSH (shared hosting)?** Open
   `http://localhost/ecowealth/system/install.php` in the browser. The page runs the
   same preflight, imports the same bundle and writes the same lock, but asks for the
   site name and both staff passwords in a form. It refuses a non-empty schema and an
   existing lock, and it never creates the database: create the empty schema in the
   hosting panel first. On a **production** site the page additionally requires
   `INSTALL_KEY` to be set in `app/config.local.php` (or the `ECO_INSTALL_KEY`
   environment variable) and typed into the form; an empty key means the installer
   refuses to run. The key is never displayed, stored or written to the database.
   After a successful install, delete `public/install.php` and remove the key.

4. **Open** `http://localhost/ecowealth/system/` and sign in.

### Demo accounts

The bundle always seeds the two staff accounts. The five members appear only after an
explicit demo install (`--with-demo` / the installer's demo option) or a manual
`seeds/005_demo_tree.sql` import — never in the production bundle and never in production
(the tools refuse it):

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

## Reset a development database

`bin/db_reset.php` returns a **development or QA** database to the state a fresh install
produces, while keeping the operator's configuration and both staff identities. It is the
supported way to clear test orders, members, wallets and codes between runs. It is not an
uninstall (the schema and settings stay) and it never undoes a migration.

| Kept | Reset or deleted |
|---|---|
| `settings` (plus `last_reset_at` / `last_reset_by`) | every member account |
| both staff rows: username, email, password, profile, payout accounts | orders, payments, proofs, invoices, shipments |
| the catalogue (default) | carts, addresses |
| the audit log (default; one `system.db_reset` row is appended) | wallet ledger, top-ups, transfers, payouts |
| install metadata (`install_version`, `install_channel`, …) | commissions, activations, registration codes |
| | S-Login and remember-me sessions |
| | staff package/sponsor/registration/wallet/transfer counters |

```bash
# what would be deleted? (read-only; also prints the statement plan)
php bin/db_reset.php --dry-run

# the usual QA reset: type the database name at the prompt
php bin/db_reset.php

# non-interactive: --force only skips the prompt, never the confirmation
php bin/db_reset.php --force --confirm=ecowealth_sneat

# optionally: five fresh codes, or replace the catalogue with the starter seeds
php bin/db_reset.php --force --confirm=ecowealth_sneat --codes=5 --code-package=1
php bin/db_reset.php --force --confirm=ecowealth_sneat --reset-catalogue

# clear the audit log first, then append the reset row
php bin/db_reset.php --force --confirm=ecowealth_sneat --clear-audit

# one machine-readable report for CI
php bin/db_reset.php --force --confirm=ecowealth_sneat --codes=5 --json
```

The target is `DB_NAME` — `ecowealth_sneat` locally, or whatever `ECO_DB_NAME` selects:

```powershell
$env:ECO_DB_NAME = 'ecowealth_sneat'
php bin/db_reset.php --force --confirm=$env:ECO_DB_NAME
```

```bash
export ECO_DB_NAME=ecowealth_sneat
php bin/db_reset.php --force --confirm="$ECO_DB_NAME"
```

Safety rules, all enforced by the engine and covered by `tests/Cases/DbResetTest.php`:

- **Back up first.** A reset has no undo; it is for a database you can afford to lose.
  `docs/runbook.md` §3 has the `mysqldump` command.
- **The confirmation is always required** — `--confirm=<database>` or the typed name at
  the prompt. `--force` only makes a run non-interactive; it never skips the confirmation.
- **One transaction.** Every delete, the staff reset, the catalogue re-seed and the audit
  row run in one transaction; a failure rolls back completely and the report says nothing
  was deleted.
- **Foreign-key checks stay on.** Children are deleted before parents in an explicit
  order; there is no `SET FOREIGN_KEY_CHECKS = 0` anywhere.
- **Production is gated.** `APP_ENV=production` additionally requires `--allow-production`,
  and the confirmation is still the database name.
- **The target is proved, not assumed.** The connection must actually be using the
  database named on the command line; the engine refuses a mismatch.
- **Verification is mandatory.** Afterwards it runs the `WalletService`,
  `CommissionService`, `TransferService` and `PayoutService` reconciliations plus
  zero-count, staff, catalogue, live-revision and counter checks. A reset that cannot
  prove its result exits `1` instead of reporting success.

Exit codes: `0` reset + verified · `1` reset committed but verification failed ·
`2` usage/preflight · `3` aborted/refused. `--help` lists every flag.

The catalogue is kept by default; `--reset-catalogue` replaces it with the real
`seeds/003_products.sql` + `seeds/004_packages.sql` values, so a reset catalogue matches a
fresh install by natural key (`sku`/`slug`, never by id). Registration codes are
inventory: the default is `--codes=0`, the allowed range is `0–50`, and a generated batch
is audited with a `code.generate` row. `--demo` adds the QA demo tree after the reset; it
is refused in production and needs the two default packages.

#### Local QA: add demo data

Demo fixtures are opt-in on **every** path and only exist outside production
(`APP_ENV !== 'production'`, or the installer/reset is refused before writing a row):

| Path | How | Result |
|---|---|---|
| CLI install | `php bin/db_install.php … --with-demo` | the bundle + the demo tree in one run |
| Web install | tick **Seed the demo tree** on `/system/install.php` | same, with operator-chosen staff passwords |
| CLI reset | `php bin/db_reset.php --force --confirm=<db> --demo` | the reset, then a fresh demo tree |
| Web reset | tick **Seed the demo tree** on `/system/reset.php` (development only) | same, from the console |
| Manual | `mysql <db> < database/seeds/005_demo_tree.sql` | the seed on its own |

```bash
# a QA reset that keeps the catalogue and reloads the demo tree
php bin/db_reset.php --force --confirm=ecowealth_sneat --demo

# or: reset the catalogue too, then load the tree (a full "fresh QA install")
php bin/db_reset.php --force --confirm=ecowealth_sneat --reset-catalogue --demo
```

Expected after `--demo`: 5 members (`juan`, `maria`, `peter`, `sarah`, `david`), 3 demo
codes (`DEMO-*`), 3 adjustment ledger rows, wallets 2000/1500, 1000/800 and 500/400
(total/withdrawable), and `david` still `pending`. Every demo member signs in with
`Demo@1234`. The step is a single transaction: a failure leaves no demo row behind, and a
database that already holds members or business rows is refused instead of merged. A
catalogue without `package-a`/`package-b` is refused too — the seed would otherwise create
active members with no package.

**After a reset:** sign in as `admin` (the staff password is unchanged), confirm
`/admin/members` and `/admin/orders` are empty, and note the fresh codes if any were
generated. Never run a reset against data you have not backed up.

### Reset through the web console (development/QA)

No terminal? The same engine runs behind a standalone console at
`http://localhost/ecowealth/system/reset.php`:

- **Development/QA:** sign in as an `admin` (or `superadmin`) and the console is available.
  It shows the current counts, the "what will be cleared" list, the options, and a form.
  The typed confirmation is `RESET`; a backup-acknowledgement checkbox and the CSRF token
  are required. The Reset button stays disabled until both the phrase and the acknowledgement
  are present, but the server re-checks everything (and the engine re-checks again).
- **Production:** the page is a branded **404** unless `DB_RESET_ENABLED` is explicitly
  `true` in `config.local.php`; even then it requires a **superadmin** and the **database
  name** (not `RESET`) as the typed phrase. It is not a production feature: use the CLI in
  an approved change window, then delete `public/reset.php` and set the constant back to
  `false`.
- **Members and S-Login sessions** can never open it: a member gets 403 and an impersonated
  tab (even a superadmin's) gets 403 before the form is rendered.
- **Demo fixtures** are one checkbox in development ("Seed the demo tree"); production
  never renders it, and a crafted `demo=1` POST is a field error. It is the same
  `DemoDataService` the CLI uses, with the same production and catalogue refusals.
- **Refresh is safe:** the POST stores the report in the session and redirects to
  `reset.php?done=1`; refreshing re-renders the same report and never runs the reset twice.
  "Done" clears the report from the session.
- **The report** shows the step log, the mandatory verification checklist, warnings, the
  before/after counts and the fresh codes (click to copy). The code list exists only in
  that session and under `/admin/codes`.
- **Files:** delete `public/reset.php` (and optionally `db-tools.css` if the installer is
  gone too) when the QA round ends. `storage/install.lock` is unrelated to the reset tool.

The console is **not** a route: it is a standalone file, it is not in any navigation and it
does not appear in `routes.php`. All SQL still lives in `DatabaseResetService`.

### Retire the tools (go-live)

`install.php` and `reset.php` are development/QA tools. Before a production site goes live,
delete them and confirm the URLs no longer answer:

```bash
rm public/install.php public/reset.php
rm database/install.sql        # optional: the bundle is not read at runtime
```

```powershell
Remove-Item public\install.php, public\reset.php
Remove-Item database\install.sql -ErrorAction SilentlyContinue   # optional: not read at runtime
```

- `https://…/system/install.php` and `https://…/system/reset.php` must return **404**.
- Keep `storage/install.lock` when a guided installer created it: it proves the install and
  lives outside `public/`, under the deny-all `storage/.htaccess`.
- Remove `INSTALL_KEY` from `config.local.php` (or rotate it) and confirm
  `DB_RESET_ENABLED` is absent or `false`.
- The services (`DatabaseInstaller`, `DatabaseResetService`, `DemoDataService`) and
  `assets/css/db-tools.css` may stay: they are unreachable without the pages.

The full checklist, with the verification queries and the backup step, is in
[`docs/deploy.md` §12](../docs/deploy.md) and [`docs/runbook.md` §7](../docs/runbook.md).

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
| `APP_PHASE` | `20` | Build phase, drives later-phase nav visibility |
| `APP_BASE_URL` | auto-detected | Set by `public/index.php` before bootstrapping |
| `APP_URL` | empty | Canonical origin for copyable links, e.g. `http://localhost` or `https://www.ecowealth.com`; empty → derived from the request. The install prefix comes from `APP_BASE_URL` |
| `DB_NAME` | `ecowealth_sneat` (`ecowealth_sneat_test` when `APP_ENV=test`) | Override with the `ECO_DB_NAME` environment variable |
| `INSTALL_KEY` | empty | Key the guided installers require in production. Set a long random string in `config.local.php` (or `ECO_INSTALL_KEY`); an empty value makes `bin/db_install.php` and `public/install.php` refuse when `APP_ENV=production`. The web installer asks for the same value in its form. Never printed, stored in the database or written to `config.local.php` |
| `DB_RESET_ENABLED` | `false` | Makes the reset console reachable when `APP_ENV=production`. It is a development/QA tool: leave this `false` on a production site (the page is a 404) and delete `public/reset.php` after use. Development/QA sees the console without setting it |
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
│   ├── routes.php             the live route table (every phase's routes, 1–19)
│   ├── routes_inventory.php   all 98 reference pages → route / page / CLI / drop / defer
│   ├── Core/                  Router, Request, View, Validator, Paginator, Upload, MaintenanceGate,
│   │                          SecurityHeaders, Throttle, CronAccess, CronLock, CronLog, InvoicePage,
│   │                          DbTarget, SqlScript, DbToolAccess (install/reset gates)
│   ├── Controllers/           Home, Shop, Package, Cart, Wallet, Checkout, Order, Profile, Earnings,
│   │                          Membership, Registration, Genealogy, Payout, Transfer, Dashboard,
│   │                          SLogin and the admin desks (Dashboard, Product, Package, Wallet, Order,
│   │                          Settings, Member, Activation, Code, Commission, Payout, Report, Audit)
│   ├── Models/                Product, ProductUnilevel, Package, Cart, Wallet, AuditLog, Address, Order,
│   │                          OrderEvent, PaymentProof, Shipment, Invoice, Commission, Member,
│   │                          RegCode, Transfer, Payout, Activation, Impersonation, Report,
│   │                          UnilevelAward, UnilevelCycle, RoyaltyAward, RoyaltyPeriod,
│   │                          RoyaltyRank, RoyaltyOps
│   ├── Services/              StockService, UploadService, PackageService, CartService, WalletService,
│   │                          OrderService, OrderNumber, AddressService, PaymentDetailsService,
│   │                          PaymentService, FulfilmentService, FulfilmentException, InvoiceService,
│   │                          InvoiceNumber, CommissionService, RegistrationService, CodeService,
│   │                          SponsorService, TransferService, PayoutService,
│   │                          ImpersonationService, HealthService, ReportService, Live,
│   │                          UnilevelService, RoyaltyService and their exception types,
│   │                          DatabaseInstaller, DatabaseResetService, DemoDataService,
│   │                          DatabaseToolException
│   ├── views/                 errors/, partials/, shop/, member/, admin/, profile/
│   └── layout/                app_header.php, app_footer.php, sidebar.php, nav.php, auth_*
├── bin/                       shop_expire.php + .cmd, order_autocomplete.php + .cmd,
│                              reset_transfer_limits.php + .cmd, unilevel_month_close.php + .cmd,
│                              unilevel_release.php + .cmd, royalty_month_close.php + .cmd,
│                              wallet_reconcile.php, build_install_sql.php, db_install.php, db_reset.php
├── storage/                   cron logs/ and locks/ + install.lock (outside public/, ignored,
│                              deny-all .htaccess)
├── public/                    docroot
│   ├── index.php              front controller
│   ├── router.php             PHP built-in server parity
│   ├── cron/shop_expire.php   guarded web entry for the expiry job (local/URL cron)
│   ├── cron/order_autocomplete.php  guarded web entry for the auto-complete job
│   ├── cron/reset_transfer_limits.php  guarded web entry for the transfer-limit reset
│   ├── cron/unilevel_month_close.php   guarded web entry for the month close
│   ├── cron/unilevel_release.php       guarded web entry for the release job
│   ├── cron/royalty_month_close.php    guarded web entry for the Royalty close (19F)
│   ├── login.php register.php logout.php   (standalone this build)
│   ├── install.php            standalone web installer (IR-4; delete after use)
│   ├── reset.php              standalone reset console (IR-6; development/QA only)
│   ├── dashboard.php          legacy redirect shim → the routed /dashboard
│   ├── uploads/               products/, packages/, proofs/ — PHP execution disabled
│   └── assets/                EcoWealth theme (Sneat 3.x) + product photos + css/db-tools.css
├── database/
│   ├── install.sql            generated production bundle (schema + seeds 001–004 + footer)
│   ├── install/               header.sql + footer.sql used by bin/build_install_sql.php
│   ├── schema.sql             fresh-install DDL (no database name hard-coded)
│   ├── migrations/            001_settings_roles … 025_general_harvest
│   └── seeds/                 settings, accounts, demo catalogue, demo packages, demo tree
└── tests/
    ├── bootstrap.php          rebuilds ecowealth_sneat_test, assertions, fixtures, the
    │                          scratch-schema and information_schema helpers
    ├── run.php                php tests/run.php [filter]
    ├── smoke.ps1              HTTP smoke test (P1-T1 … P15-T12, IR4-T1 … IR6-T5, P17B-T1 … P17F-T11, P19B … P19F, P20B … P20G)
    ├── Cases/                 Skeleton, Router, Parity, Settings, SettingsPage, CancelSetting, View,
    │                          Upload, Migration, Product, ProductView, Package, PackageView, Cart,
    │                          CartView, Wallet, WalletView, Order, OrderView, Payment,
    │                          PaymentView, PaymentDetails, Fulfilment, FulfilmentView, Invoice,
    │                          Cron, Membership, MembershipView, Live, Commission, CommissionIndirect,
    │                          Transfer, TransferView, Payout, PayoutView, Impersonation,
    │                          ImpersonationView, Report, ReportView, AuditLog, Hardening, Launch,
    │                          the member/admin live suites, the Unilevel* group (Foundation,
    │                          Configuration, Engine, Cycle, Escrow, ReleaseJob, MemberView) and the
    │                          Royalty* group (Foundation, Configuration, Rank, Engine, Reconcile,
    │                          Report, AdminView, AdminMemberView, MemberView, CloseJob, Cron), the
    │                          TeamHarvest* group (Foundation, Configuration, Engine, Catchup,
    │                          Reconcile, AdminView, MemberView) plus HarvestQueue, and the
    │                          install/reset groups SqlScript, DbTarget, SeedParity, InstallSql,
    │                          Install, DbReset, DbToolAccess
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

### Phase 15 URLs — reports, audit and the full settings page

| Method | Path | Guard | Purpose |
|---|---|---|---|
| GET | `/admin/reports` | admin | Sales by day, commissions by level, payout register, code revenue, orders by status, top products and the Finance health widget |
| GET | `/admin/reports/export` | admin | One CSV for the selected window: `?type=sales\|commissions\|wallet\|payouts\|codes` (audited) |
| GET | `/admin/audit` | admin | The append-only audit desk, filterable by action, subject, actor/action text and date |
| GET/POST | `/admin/settings` | admin | Eight independently saved cards (see [Settings](#settings)) |

The report window is `?from=YYYY-MM-DD&to=YYYY-MM-DD`, inclusive, defaulting to the last 30 days and
capped at 366 days. `ReportService` validates it once for the page and every export, so a hand-typed
URL can never ask for an unbounded scan. `/admin/reports` is the `admin.reports` live surface; the audit
desk is deliberately not live (an audit trail is read like a document).

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
e-wallet transfer limits; later phases read them without further migrations. Since Phase 15 the
page is the complete settings surface — **eight independent cards**, each with its own form and
**Save settings** button, so one save can never overwrite another card's values:

| Card | Keys |
|---|---|
| **General** | `site_name` (rendered in the title and header), `site_tagline`, `maintenance_mode` |
| **Shop** | `shop_enabled`, `shop_cancel_enabled` (**Enable Cancel of Order**, off by default) |
| **Receiving accounts** | `payment_gcash_*`, `payment_maya_*`, `payment_bank_*` |
| **Payout methods** | `min_payout`, `usdt_rate`, `service_fee_<method>`, `usdt_*_gas_fee`, `payout_<method>_enabled` |
| **Payment windows** | `shop_payment_deadline_hours`, `shop_correction_window_hours`, `shop_max_proof_attempts`, `shop_expiry_enabled` |
| **Fulfilment & invoices** | `seller_*`, `shop_autocomplete_days`, `shop_autocomplete_enabled` |
| **Commissions** | `commission_direct_enabled`, `commission_indirect_enabled`, `commission_indirect_max_levels`, `commission_requires_active_sponsor`, `commission_compression` (reserved), `free_registration_enabled`; the **Repeat-purchase bonus** sub-block: `unilevel_enabled`, `unilevel_max_levels` (1–10), `unilevel_requires_active_upline`, `unilevel_active_threshold`, `unilevel_reversal_enabled`, `unilevel_hold_days`, `unilevel_release_enabled`; the **Royalty bonus** sub-block: `royalty_enabled`, `royalty_pay_enabled`, four rank thresholds, three quotas and six rates; the **Team Harvest bonus** sub-block: `team_harvest_enabled`, `team_harvest_qualify_directs`, `team_harvest_max_levels` and the ten `team_harvest_levels` amounts (table rows, not settings); the **General Harvest bonus** sub-block: `general_harvest_enabled`, `general_harvest_max_levels` and the ten `general_harvest_levels` amounts (table rows, not settings) |
| **Transfers** | `ewallet_transfer_fee`, `ewallet_min_transfer`, `ewallet_transfer_daily_limit`, `ewallet_transfer_weekly_limit` (0 = unlimited) |

The controller only validates, writes and audits the submitted card's keys (`settings.update`, with
the before/after value per key). Money values keep their column precision (`decimal:2` for the floor
and fees, `decimal:4` for the rate and gas) and a percentage is capped at 100 so a service never has
to clamp a saved value. Cross-field rules reject a weekly limit below the daily limit and a minimum
above the daily limit. A save bumps the settings page and the shared `config` live revision, so open
membership/order pages revalidate and the checkout page's repeat-purchase preview turns on or off
(it declares `data-live-config="config"`); the two payout pages also mix
`PayoutService::settingsFingerprint()`.

Maintenance mode is `maintenance_mode=1`: guests see a branded **503** with
`Retry-After: 3600`, admin and superadmin sessions pass straight through.

`free_registration_enabled` (default `1`) gates the free standard/pending registration modes
and the referral-link signup; it is editable on the **Commissions** card.

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
- Since Phase 17B the form also carries the **Point value (P.V.)** and the per-product
  **repeat-purchase ladder** (see [Repeat-purchase bonus](#repeat-purchase-bonus-phase-17b)).
  A plain catalogue save never rewrites a bonus configuration.
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
  session with superadmin, member, IP, device, start, expiry and status; the console shows the active and
  recent sessions. `Impersonation::expireOverdue()` sweeps rows whose TTL passed without another request.
- **The console is live.** `/slogin` is the `admin.slogin` surface: the active and recent cards revalidate
  from the same URL under `X-Live: 1`, and every `impersonation_log` write bumps the revision (a superseded
  mark does not). A superadmin who switches back to the tab sees the sessions that opened or ended meanwhile
  without a reload, and a poll that expires a session is answered with the fresh lists, not a 304. The audit
  list stays an ordinary page. See [live updates](../docs/live-updates.md).
- **Rate limit.** Five failed attempts for one target in 15 minutes are refused with
  `rate_limited`; the counter lives in the superadmin's own session (the reference behaviour), and each
  refusal is audited.

---

## Reports, health and the audit desk (Phase 15)

- **One reporting language.** `app/Models/Report.php` holds the aggregate SELECTs, `ReportService`
  owns the window (inclusive dates, validated once, capped at 366 days) and the CSV shapes, and the
  admin dashboard and reports page build from the same calls. A number on the dashboard cannot
  disagree with the report it links to.
- **What the reports page shows:** sales by day (paid orders only; cancelled orders are excluded
  from revenue), sales by method and the average order, the best sellers in the window, commissions
  by level (credited vs skipped), the payout register summary (held now, completed, fees, by method),
  registration-code revenue per package, orders by status and the **Finance health** widget.
- **Seven CSV exports** — `sales`, `commissions`, `unilevel`, `royalty`, `wallet`, `payouts`, `codes` — each for the selected
  window, each written through one CSV builder and each audited as `report.export` with its type,
  window and row count.
- **Finance health** is `HealthService`: the four service reconciliations (wallets, commissions,
  transfers, payouts) plus order-total, stock-availability and award-integrity checks, seven sections
  in all. It is read-only and independently guarded per section, so one failing query never hides the
  others; `bin/wallet_reconcile.php` covers the same four plus the repeat-purchase awards (17C).
- **The audit desk** (`/admin/audit`) reads the append-only `audit_log`: filter by action, subject,
  actor/action text and date, paginate, and see the actor, role, subject, compact detail and IP. Every
  audited write — settings changes, top-ups, exports, member status, activation and payout decisions,
  transfers, code generate/redeem, S-Login starts/refusals/stops — is already recorded by its service.
  There is no update or delete path by design.
- **Hardening in the same phase:** shared security headers on every PHP response
  (`app/Core/SecurityHeaders.php`, sent from the bootstrap), session-scoped throttles
  (`app/Core/Throttle.php`) on checkout (20/10 min), payment proof (10/15 min), transfers (10/15 min)
  and payout requests (10/h), uploads/ script execution denied, production PHP error display forced
  off, and migration `019` for the reporting indexes.

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
- **Typed details survive the detour.** `app-checkout.js` mirrors the delivery fields, the payment choice and the
  two checkboxes into `sessionStorage` (per member, per tab) while the member edits them, and fills them back in
  when the checkout is opened again. Pressing **Back to cart** and returning no longer means retyping; a notice
  confirms the restore and offers **Start over**. The draft is dropped on submit and when a saved address is
  picked, and the server-rendered posted values always win over it.
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

## Repeat-purchase bonus (Phases 17–18)

A **per-product unilevel ladder** that pays the buyer's sponsor chain on every **paid shop order**:
level 1 is the direct sponsor, level 10 the tenth upline. The work is split into phases: 17A ships the
schema and the wallet vocabulary, 17B the configuration, **17C the money engine**, 17D/17E the admin
and member surfaces, 17F the month-close job and 17G the release. Phase 18 (a **post-17 point
release**) makes the bonus an **escrow**: paying an order **earns** the awards but moves no money; the
release job credits the wallet after delivery + the hold. Paying an order awards the ladder only when
both masters are on — the global `unilevel_enabled` and the product's `products.unilevel_enabled` — so
deploying this code changes nothing until an operator configures a product **and** turns the global
switch on.

### The engine (17C)

- **One hook, two payment paths.** `UnilevelService::onOrderPaid()` runs inside the caller's
  transaction from the e-wallet branch of `OrderService::placeFromCart()` and from
  `PaymentService::verify()` for a verified manual payment. A placed-but-unpaid order pays nothing and
  is never stamped; the order is stamped `unilevel_processed_at` (and `unilevel_total`) even when the
  stream is off or there is nothing due, so a later re-verification cannot pay it.
- **The ladder.** For each order line, `base × rate / 100` is rounded per level in integer centavos.
  The **base is snapshotted at placement** (`shop_order_items.unit_base / base_total`) through
  `ProductUnilevel::baseFor()`; the **rates are read at payment** and copied onto the award
  (`rate_pct`). A line created before migration 020 falls back to the product and records
  `base_source = 'product'`.
- **The walk.** `CommissionService::sponsorChain()` supplies the buyer's sponsors (cycle-guarded, ten
  levels); level 1 is the direct sponsor. The **buyer is never a recipient** (no level-0 rebate).
  There is **no compression**: a disabled, suspended, monthly-Inactive or company upline writes a
  `skipped` row with the reason and keeps its level number, so the next upline is paid at its own
  rate. A zero rate writes no row and still consumes the level.
- **The monthly Active gate.** `UnilevelCycle` decides the flag from the previous calendar month
  (Asia/Manila): the close writes one `unilevel_activity` row per account and one `unilevel_periods`
  header, and a member with no row is Active. An unclosed previous month is computed live from the
  same order aggregate, so the midnight-to-cron window is correct with no snapshot. The close runs
  unattended through `bin/unilevel_month_close.php` (00:15 on the 1st; CLI, guarded URL or `.cmd`),
  which owns the schedule, the lock, the monthly log, `--dry-run`, `--doctor` and the catch-up —
  see [Cron jobs](#cron-jobs). An operator can relax the monthly rule with
  `unilevel_requires_active_upline = 0`; the account and membership checks always apply, and the
  company account is never paid.
- **Wallet and reconciliation.** A `pending` award has **no** wallet movement and no ledger row. At
  release the job writes exactly one withdrawable credit per award (`ref_type = 'unilevel'`,
  `ref_id = unilevel_awards.id`) and marks the award `credited` with `ledger_id` and `credited_at`.
  `bin/wallet_reconcile.php` gained a **Repeat-purchase** section: award credits and their ledger
  rows, reversal debits against `reversed_amount`, pending/cancelled rows (no ledger), the order
  headline totals (`pending + credited`) and the idempotency stamp. An award that was paid and then
  reversed keeps its amount and ledger id; only the reversal columns change.
- **The release (18).** `UnilevelService::releaseDue()` finds orders whose `pending` awards are due
  (`delivered`/`completed`, not cancelled, `COALESCE(delivered_at, completed_at) + hold_days <= NOW()`)
  and `releaseForOrder()` credits them in one transaction per order, with the order row locked first
  and the award status re-checked. `bin/unilevel_release.php` runs it hourly (`:20`; CLI, guarded URL
  or `.cmd`) with `--limit`, `--order`, `--dry-run`, `--json`, `--doctor`, `--log-dir`, `--quiet` and
  `--help`. **No force path**: an undelivered order never releases, and there is no “release now”
  button. `unilevel_hold_days` (0–365, default 7) is snapshotted on every award at payment;
  `unilevel_release_enabled = 0` pauses the job and leaves pending awards pending.
- **Cancellation.** `PaymentService::cancelByAdmin()` calls `UnilevelService::reverseForOrder()`
  after the buyer's refund. It **always** cancels the order's `pending` awards first (no debit, no
  reversal row, no shortfall), then — only when `unilevel_reversal_enabled` — voids the `credited`
  awards and debits what the wallet still holds as `ref_type = 'unilevel_reversal'`. The credited
  reversal is **best effort** (D-13): a partly spent wallet is debited only up to its withdrawable
  balance, the shortfall is recorded on the award, balances never go negative, and a cancellation is
  never blocked.
- **Idempotency.** Four layers: the order stamp, a per-(line, recipient, level) pre-check, the
  `uq_unilevel_award` unique key and the wallet's own `(user, ref_type, ref_id, type)` replay. A
  second `onOrderPaid()` returns `replayed` and moves nothing.
- **Previews.** `quoteLine()` / `quoteCart()` read the current ladder and base and write nothing; 17E's
  product panel and checkout line render them (see *The member surfaces* below).
- **Delete guard.** A product with ladder rows is deleted with them (`ON DELETE CASCADE`); a product
  that paid a repeat-purchase award cannot be deleted (`ON DELETE RESTRICT`, reported by the list).

### The configuration (17B)

- **One ladder, ten equal rows (D-17).** Levels 1–10 in `product_unilevel_levels`, each a percentage of
  the line's base. There is **no** direct/indirect split, no separate direct field, no read-only level 1
  and no `type` column — level 1 is simply the first row. `ProductUnilevel` is the only reader/writer.
- **The product form** (`/admin/products/new`, `/admin/products/{id}/edit`) carries:
  - *Point value (P.V.)* in **Pricing & stock** — money, `0` means "not set";
  - *Bonus base* in the ladder card — **Auto** (P.V. when set, else the price), **P.V.** or **Retail
    price**, stored in `products.unilevel_base`;
  - ten rate inputs `unilevel_levels[1…10]` (`min 0`, `max 100`, `step 0.001`) and the per-product switch
    `products.unilevel_enabled`;
  - the running total and each level's per-unit amount, recomputed live by
    `assets/js/app-product-unilevel.js` (progressive enhancement — the same numbers are rendered
    server-side, so the form saves and validates with JavaScript off);
  - **Load reference ladder** (5/5/5/3/3/2/2/2/1/1 = 29%) and **Clear**. A **new** product is pre-filled
    with the preset but its switch stays **off**; a migrated product reads 0% everywhere.
- **The rules.** Blank and `0` are the same value: ten rows are written on save, zero-filled. A rate is
  0–100 with at most three decimals. The ten levels may not add up to more than **100%** — a field error
  on the ladder, never a silent clamp. Switching a product on with every level at 0 is refused, and so
  is a P.V. base with no P.V. (`0`). One save writes the product row, the two new columns and the ladder
  in **one transaction**.
- **Audit.** A changed configuration writes one `product.unilevel.update` entry (before/after and the
  keys that moved) inside the same transaction. A save that changes nothing writes nothing.
- **The list** shows `Repeat bonus 29%` when the switch is on and the ladder has a total, `Repeat bonus
  0%` when it is on with an empty ladder (a configuration error the operator can see), and no badge when
  it is off. `?unilevel=on|off` filters the catalogue table and survives pagination.
- **The settings block** lives in the **Commissions** card, under *Repeat-purchase bonus*: `Upline depth`
  (1–10), `Monthly personal-purchase minimum` (₱), `Release delay after delivery` (0–365 days), and the
  four switches `unilevel_enabled`, `unilevel_requires_active_upline`, `unilevel_release_enabled`,
  `unilevel_reversal_enabled`. The eighth key, `unilevel_ledger_since`, is a reconciliation baseline and
  is never rendered. Saving the card validates
  and audits exactly like the Phase 15 cards (`settings.update`). The save also bumps the shared `config`
  live revision, and the checkout page follows it (`data-live-config="config"`), so toggling
  **Repeat-purchase bonus enabled** removes or restores an open checkout's preview line without a reload.

### The admin surfaces (17D)

Read-only: no admin page writes an award, a wallet row or a period.

- **The repeat ledger** is the second tab of the commission ledger,
  `/admin/commissions?ledger=repeat`: eight counters (total credited, **pending release**, this month,
  awards, recipients, skipped, **cancelled before release**, unrecovered), filters
  (recipient/buyer/product/order `q`, level, status, product, date), the closed-month card and the
  filtered CSV (`/admin/commissions/export?ledger=repeat`, audited as `unilevel.export`). The **Lvl**
  column is the whole story — there is no direct/indirect column. A `pending` row shows its due date and
  has no wallet movement; a `cancelled` row shows “no money moved” and is **not** a shortfall; a skipped
  row shows the amount that was due and its reason; a voided row shows what was recovered and the
  shortfall. The CSV is 15 columns, ending with **`Hold days`** and **`Released at`**.
- **The order card** on `/admin/orders/{id}` lists level, recipient, product, base, rate, amount and
  status, with the footer **Awarded … · Released … · Pending …** and explains the three "no bonus"
  states (unpaid, stream off, paid before the feature). A paid order shows **₱X pending release** (no
  wallet link) until its awards are released, then **₱X released** (wallet link); a cancelled order
  shows **No bonus released** with the **Cancelled before release** rows kept for audit. The buyer is
  never listed as a recipient.
- **The reports** are four cards on `/admin/reports` (window summary, by level, by product, closed
  months) plus a `type=unilevel` CSV, and a **Repeat bonus (30 d)** KPI on `/dashboard`'s commerce row.
- **The package commission ledger is unchanged** — the same page, its own tab (P17D-T1).

### The member surfaces (17E)

Read-only and scoped to the session member; no page offers a repeat-purchase action.

- **The product panel** (`/shop/{id}`) is **Team chain directed**: it renders all ten levels on the
  product's bonus base with the per-unit amounts, the **Up to 29%** total, the **₱500.00 monthly
  minimum** and the sentence that the member's own purchase does not pay them. Level 1 is the reader's
  direct recruit; levels 2–10 are their Team chain. It is absent while the product's ladder or the
  global master is off.
- **The catalogue badge** (`Up to 29% repeat bonus`) reads the batched `totalsMap()` — one query for the
  page, not one per card.
- **The checkout line** (`/checkout`) previews the cart's whole ladder — "up to ₱X is earned by the
  members above you when this order is paid. It is released to their e-wallets after the return
  window." — then pivots to the reader's Team chain income, and is
  absent when no cart product has a payable ladder. It is a live region that follows the shared `config`
  revision, so toggling the master switch on the settings page adds or removes it without a reload.
- **The order card** (`/orders/{id}`) shows the total that is **reserved** for the members above (or
  **was released** once every award is credited), never which member received what, then points the
  buyer at their own Team chain statement; it is a live region, so a verification or a release appears
  without a reload.
- **The repeat tab** (`/earnings?ledger=repeat`) carries five counters (including **Pending release**),
  a **Next release** line,
  the **two-clock Active panel**
  (this month's status, the previous month it is computed from, the threshold and the missing-flag
  sentence), the 1–10 **Team chain** breakdown — each level card lists its members and paginates on its
  own, ten rows by default with the usual 5/10/15… control under its own `?levelN_page` /
  `?levelN_per_page` keys, so one crowded level never moves the others — the rows (pending rows say
  when they release, cancelled rows say no money was paid, credited rows carry the release date),
  filters and a **15-column** CSV.
- **The dashboard tile** and the **membership paragraph** read the same `UnilevelCycle::memberStatus()`
  and say **Team chain**, never "sponsor chain"; the tile splits **Credited / Pending release / Total
  earned**, and the wallet page adds **Pending release** and **Total funds** (wallet + pending, a
  presentation figure only) when something is pending.
- The package earnings tab is unchanged (P17E-T10); `?user_id=` is ignored (P17E-T11).

```bash
php tests/run.php Unilevel                # every Phase 17 case: foundation, configuration, engine, cycle, member view
php tests/run.php UnilevelConfiguration   # 24 configuration cases (17B)
php tests/run.php UnilevelEngine          # 38 engine cases (17C)
php tests/run.php UnilevelCycle           # 11 monthly-clock cases (17C)
php tests/run.php UnilevelMemberView      # 17 member-surface cases (17E)
php tests/run.php UnilevelEscrow          # the Phase 18 escrow suite: pending, release, cancellation
php tests/run.php Cron                    # 18 job cases, 10 of them the month close (17F)
php bin/wallet_reconcile.php              # six sections, exit 0 when clean
```

---

## Team Harvest bonus (Phases 20A–20F)

A **flat amount per completed level** of a member's private **3×10 matrix**, paid into the withdrawable
e-wallet when the level's block fills. It has no month, no purchase input and no rank: a member enters
the **Harvest queue** at the configured number of direct member accounts, and every qualified member
who joins their team **after** them fills one slot.

| Level | Slots (3^L) | Completes at n ≥ (3^(L+1)−3)/2 | Reference amount | Cumulative |
|---:|---:|---:|---:|---:|
| 1 | 3 | 3 | ₱1,000.00 | ₱1,000.00 |
| 2 | 9 | 12 | ₱2,000.00 | ₱3,000.00 |
| 3 | 27 | 39 | ₱4,000.00 | ₱7,000.00 |
| 4 | 81 | 120 | ₱6,000.00 | ₱13,000.00 |
| 5 | 243 | 363 | ₱12,000.00 | ₱25,000.00 |
| 6 | 729 | 1,092 | ₱50,000.00 | ₱75,000.00 |
| 7 | 2,187 | 3,279 | ₱120,000.00 | ₱195,000.00 |
| 8 | 6,561 | 9,840 | ₱200,000.00 | ₱395,000.00 |
| 9 | 19,683 | 29,523 | ₱1,200,000.00 | ₱1,595,000.00 |
| 10 | 59,049 | 88,572 | ₱3,500,000.00 | **₱5,095,000.00** |

**The schema (20A, migration `024`).** `harvest_queue` (one row per qualified member; `id` **is** the
position, `UNIQUE (user_id)`), `team_harvest_levels` (the ten amounts, natural key) and
`team_harvest_awards` (the money ledger, `UNIQUE (user_id, level)`), plus `team_harvest` on
`ewallet_ledger.ref_type` and four settings. **The configuration (20B)** edits the ladder; **the queue
(20C)** stores qualification; **the engine (20D)** pays it — `team_harvest_awards` stays empty until a
level completes after the baseline.

| Setting | Default | Meaning |
|---|---|---|
| `team_harvest_enabled` | `0` | The one switch. Off writes no award; the queue still grows. |
| `team_harvest_qualify_directs` | `3` | Direct **member** accounts needed to enter the queue (1–10); staff never count. |
| `team_harvest_max_levels` | `10` | The deepest level that may pay (1–10); amounts above it are kept and marked *not paid*. |
| `team_harvest_ledger_since` | `''` | The seed job's timestamp (D-04). Empty = unseeded; never an operator input. |

### `TeamHarvestLevel` (20B)

One model, one write. `rows()`, `map()`, `amounts()`, `amount(int $level)`, `total()`,
`cumulative()`, `threshold(int $level)` and `slots(int $level)` are read-only; `save()` is the only
write and it rewrites **all ten rows** (delete-then-insert) inside the caller's transaction, stamping
`updated_by`, so a partial ladder cannot exist. The matrix arithmetic lives here and nowhere else:

```
slots(L)     = 3^L
threshold(L) = (3^(L+1) − 3) / 2        3, 12, 39, 120 … 88,572
```

`amount()` reads ₱0.00 outside 1–10; `threshold()`/`slots()` refuse a level outside 1–10 with an
`InvalidArgumentException`. `validate()` owns the two cross-field rules — a full ladder over
**₱25,000,000.00** (a typo guard) and an enabled stream on an all-zero ladder — and both the card and
the model read the same `AMOUNT_RULE` (`nullable|numeric|gte:0|lte:10000000|decimal:2`), so the two can
never disagree. `qualifyDirects()`, `maxLevels()` and `enabled()` are the only way to read the three
knobs; 20C/20D must not read the settings directly.

### The settings card (20B)

**Settings → Commissions → Team Harvest bonus** (below the Royalty block; one card, one form, one save
button). It holds:

- the **Enable the Team Harvest bonus** switch, the **Direct members needed to qualify** and **Levels
  that pay** inputs (both whole numbers 1–10);
- the **ten amount inputs**, one grid cell each (`col-12 col-md-6`, so they stack on a phone), with the
  level's slot count, its *pays at this depth / not paid at this depth* badge, its threshold and its
  cumulative under each input;
- the **generated ladder table** — Level, Slots, Teammates needed, Stored amount, Cumulative, Depth —
  every cell computed by `TeamHarvestLevel`, inside `.table-responsive` so the six columns scroll
  inside the card at 390 px;
- the **four-state status strip**: *Not seeded* (names `bin/harvest_queue_seed.php`), *Seeded and
  switched off*, *Seeded, switch on*, *Seeded, switch on, waiting* (the pending level count and the
  amount owed, naming `bin/team_harvest_catchup.php`);
- the **full-ladder total and the per-member maximum in one sentence**, the ₱25,000,000.00 ceiling,
  and **Load reference ladder** / **Clear** buttons.

`app-team-harvest.js` is progressive enhancement only: it re-reads the cumulative column, the full
total and the ceiling tone as the operator types and implements the two buttons from each input's
`data-reference`. With JavaScript off the form still saves, still validates and still shows the stored
numbers. A save is **atomic** — the switch, the two knobs and the ten rows commit together — and a
refused save leaves both stores unchanged. Every change is audited once as
`team_harvest.ladder.update` (the ten amounts and the three knobs, before and after) plus the standard
`settings.update` row for each changed setting key.

A posted card that does **not** carry the amount fields at all (an older form, a script that only
flips the switch) keeps the stored ladder; an explicit empty array clears it. The card writes no
position, no award and no wallet row.

### `HarvestQueue` (20C)

The queue is the **irreversible** part of the bonus: `harvest_queue.id` is a member's position, it is
assigned by auto-increment, never computed and never renumbered, and every later award is derived from
it. Three writers, and no others:

| Writer | When | `source` |
|---|---|---|
| `qualifySponsor($sponsorId)` | inside `RegistrationService::register()`'s transaction, after the new user row exists | `registration` |
| `seed()` | once, at go-live, from `bin/harvest_queue_seed.php` | `seed` |
| `rebuild(apply: true)` | the repair drill: appends a gate holder with no row (never before an existing position) | `repair` |

```php
$queue = new HarvestQueue();
$queue->qualifySponsor($sponsorId);   // ?int — the new position, or null
$queue->positionOf($userId);          // ?int
$queue->teammatesOf($userId);         // int  — the stored counter n
$queue->recount($userId);             // int  — the authoritative count (the proof)
$queue->ancestorsOf($userId);         // queued ancestors, nearest first, any depth, cycle-safe
$queue->teamRoster($userId, 25);      // teammates in position order
$queue->matrix($userId, 0, 25);       // a page of the private 3×10 board
$queue->stats();                      // queued · positions · next · deepest · awards
$queue->verify();                     // corrupt chains, non-member rows, counter drift
$queue->seed($dryRun, $byId, $force);
$queue->rebuild($dryRun, $apply);
```

The rules it owns:

- **The gate** is `team_harvest_qualify_directs` (default 3) **direct `role = 'member'` accounts**.
  Staff accounts never count, never qualify and are never queued — the company account included.
  **Status is not read**: a pending, inactive or suspended direct counts exactly like an active one,
  and a suspended member qualifies like any other.
- **The hook is inert until the queue is seeded.** An empty `team_harvest_ledger_since` makes
  `qualifySponsor()` return `null` before it reads anything: deploy → seed → live (D-04).
- **`qualified_at` is the qualification instant**: the gate-th direct's `created_at`, on the live path
  and in the reconstruction alike, so the two can never disagree.
- **The counter is maintained and proved.** Each qualification moves every queued ancestor by one
  (`teammates = teammates + 1`, one statement), at any depth and with no ten-level cap;
  `recount()` computes the definition from the tree and `rebuild()` reports any disagreement. A
  counter the recount disagrees with is a defect, never a variant.
- **"Qualified after me" is the traffic rule.** A descendant who qualified *before* the member is
  permanently excluded and is never replaced. That is why a level completion is a one-way door.
- **The matrix is derived, never stored.** `matrix()` fills the 3×10 board from the ordered roster:
  level `L` holds `3^L` slots and starts at `(3^L − 3)/2`; `placed_under(r)` is `you` for the first
  three slots and `teammate[r/3 − 1]` after that.

`qualifySponsor()` runs inside the registration's transaction: a registration that cannot record its
qualification rolls back rather than leaving an unrecorded position behind.

### The seed job (20C)

**One-time, on-demand — not a cron entry.** It reconstructs the queue for the tree that already
exists, records every member's baseline (`baseline_n`, the `n` they hold at that instant) and only
then switches the hook on by writing `team_harvest_ledger_since`.

```bash
php bin/harvest_queue_seed.php --dry-run     # preview: positions, order, baseline, excluded money
php bin/harvest_queue_seed.php               # reconstruct (refused on a non-empty queue)
php bin/harvest_queue_seed.php --by-id       # order by member id (imported/backdated data)
php bin/harvest_queue_seed.php --force       # append only the members that are missing
php bin/harvest_queue_seed.php --rebuild     # report counter drift (writes nothing)
php bin/harvest_queue_seed.php --rebuild --apply   # repair the counters
```

Windows: `bin\harvest_queue_seed.cmd` takes the same flags. Exit codes: **0** success / nothing to do,
**1** refused (already seeded, a non-empty queue without `--force`, an invalid gate, drift found by a
rebuild dry run), **2** environment (database unreachable, tables missing).

The order is deterministic — the gate-th direct's `(created_at, id)`, or the member's own id with
`--by-id` — and every run is audited (`harvest.seed`, `harvest.queue.repair`). The dry run prints the
order and **what the baseline excludes**: the levels already complete at that instant, with their
amount, because those levels are recorded as `baseline` rows and are never credited (D-07).

**Go-live order**: [`docs/runbook.md`](docs/runbook.md) §*Team Harvest go-live order* is the one place
the sequence is written; this section only describes the job it uses. The seed moves no money.

### `TeamHarvestService` — the money engine (20D)

**The only writer of Team Harvest money**, and a completed level is the only event that moves it. The
rule, exactly the plan's §12.7:

```
for L = 1 .. team_harvest_max_levels:
    n < threshold(L)                  → break      (nothing deeper is complete)
    threshold(L) <= baseline_n        → write a `baseline` row, never pay (D-07)
    an award row exists for (m, L)    → skip       (a level pays once, ever)
    amount(L) = 0                     → skip       (a zero level is a switch, §12.6)
    otherwise                         → insert `credited`, credit the withdrawable
                                        wallet, link the movement — one transaction
```

```php
$engine = new TeamHarvestService();
$engine->onQualified($sponsorId);     // the registration hook: qualify, then evaluate every moved member
$engine->evaluate([$userId]);         // {off, paid, baseline, written, amount, members, skipped, levels}
$engine->evaluateAll(500);            // every queued member, 500 per batch/transaction
$engine->statusFor($userId);          // the card payload: position, ten levels, next, paid/baseline, open
$engine->openLiability();             // {levels, members, amount, by_level} — derived on read
$engine->reconcile();                 // the finance check (below)
```

- **State-based, not event-based** (D-19). The engine compares `teammates` with the ledger, so a missed
  event, a rolled-back transaction, a repaired counter or a late enablement all converge on the next
  run. A second evaluation writes nothing.
- **Idempotent at three layers** (§14.6): the `has()` pre-check, `UNIQUE (user_id, level)` and the
  wallet's own `(user_id, ref_type, ref_id, type)` anchor.
- **Atomic with its credit** (§14.7): the award row is written first (its id is the wallet `ref_id`),
  the credit follows, and the link is set in the same transaction. A failure propagates — the engine
  never swallows an error to keep a registration going.
- **The baseline is honoured twice** (`threshold > baseline_n` and the stored `baseline` row). A level
  complete at go-live is recorded with its amount and `ledger_id NULL`, and is **never** credited.
- **The amount is snapshotted** (`amount`, `threshold_n`, `n_at_award`); editing the ladder prices only
  future awards.
- **The stream off writes nothing** and the queue keeps growing (D-09); an **unseeded** queue is a hard
  refusal (`TeamHarvestException::unseeded()`).
- **Recipients are sorted ascending** before any wallet lock, and the counter has already moved, so two
  registrations sharing ancestors cannot deadlock (§14.8).
- **No purchase, rank, status or month is read.** A suspended member still earns (withdrawal is the
  existing payout rule); a staff account is never queued and never paid.

### `TeamHarvestAward` — the ledger (20D)

One row per `(member, level)` decision; the row is never edited except for the `ledger_id` of the
credit that was written with it.

```php
TeamHarvestAward::forMember($userId);       // level order, each with position + username
TeamHarvestAward::paidLevels($userId);      // the credited levels
TeamHarvestAward::has($userId, $level);     // the engine's pre-check
TeamHarvestAward::levelMap($userId);        // level => status, one query
TeamHarvestAward::recent(10);
TeamHarvestAward::countByStatus();
TeamHarvestAward::totals($filters);         // rows, credited_rows/total, baseline_rows/total, recipients
TeamHarvestAward::byLevel();                // always ten rows, with the configured amounts
TeamHarvestAward::byMonth();
TeamHarvestAward::normaliseFilters($raw);   // an unknown value means "no filter", never an error
TeamHarvestAward::select($filters, $pager); // the admin ledger / member statement page
TeamHarvestAward::exportRows($filters, null, $userId);   // CSV, model-scoped when $userId is given
```

`status = 'baseline'` is **not** a payment: the admin and member surfaces show those rows in their own
tile and label (*Before go-live*), never inside a paid total.

### The catch-up and doctor job (20D)

**On-demand, not a cron entry** (D-08): the stream has no month, no close and no schedule. The job
exists for the four moments a state-based engine needs a human: after enabling the switch (settle the
backlog), after a repair, after a counter-drift alarm, and after a deploy that fixed a bug.

```bash
php bin/team_harvest_catchup.php --dry-run      # the open liability, level by level — writes nothing
php bin/team_harvest_catchup.php                # evaluate and pay the backlog (batches of 500)
php bin/team_harvest_catchup.php --member=42    # one member only
php bin/team_harvest_catchup.php --doctor       # nine health checks, read-only
php bin/team_harvest_catchup.php --json --limit=1000
```

Windows: `bin\team_harvest_catchup.cmd` takes the same flags. Exit codes: **0** clean / nothing to do /
another run holds the lock, **1** refused (unseeded) or a problem found (drift, reconcile mismatch, a red
doctor), **2** environment (database unreachable, tables missing). Every real run audits
`team_harvest.catchup` with what it evaluated and what it paid; a dry run and the doctor write nothing.

The doctor reports nine checks: `clock` (PHP vs MySQL), `tables`, `settings` (the four keys, the gate
1–10, the depth 1–10), `seed`, `baseline`, `queue`, `awards`, `drift` (the counters against a
from-scratch recount) and `reconcile`. It **never repairs**: a counter is repaired by
`harvest_queue_seed.php --rebuild --apply`, and money is never repaired at all (D-12).

### Reconciliation (20D)

`wallet_reconcile.php` gains a **seventh** section, folded into its exit code:

| Check | A failure means |
|---|---|
| Σ credited awards = Σ `team_harvest` credits | a credit was lost, duplicated or mis-typed |
| Every `credited` award has a live `ledger_id` | a dangling wallet reference |
| Every `baseline` award has `ledger_id IS NULL` | a pre-go-live level was paid |
| Every `team_harvest` movement belongs to an award | an orphan credit |
| The award and its movement agree to the centavo | a hand-edited amount |
| `UNIQUE (user_id, level)` (checked anyway) | a double award |
| No award above the ten-level ladder | a damaged table |
| `teammates` equals a from-scratch recount | counter drift |
| `baseline_n <= teammates` | a deleted descendant or a corrupt repair |
| The ladder holds ten non-negative rows | a truncated configuration |

An award above the *configured depth* (`team_harvest_max_levels`) is a **warning**, not a failure: the
depth caps what the engine may write, never what was already written, so lowering it must not turn the
books red. The stream being off or the queue being unseeded are warnings too. The reconciler never
repairs: a mismatch means a human looks at it, and a wrong award is a forward-only business decision
(§18 Q3).

### The admin surfaces (20E)

Five read-only surfaces on pages that already existed — no route, no permission and no writer:

| Surface | What it shows |
|---|---|
| `/admin/commissions?ledger=team_harvest` | the fourth tab: six tiles from `Report::teamHarvestTotals()` (credited, this month, awards, recipients, before go-live, open liability), the always-ten-row level card, filters (member, level, status, dates, search), the rows with their wallet links, pagination and the Appendix C.5 CSV (`team-harvest-ledger_YYYY-MM-DD.csv`, one `team_harvest.export` audit row per download) |
| `/admin/members` | the **Harvest** column (`#N` from the joined `harvest_queue.id`, `—` when not queued), the **Team Harvest queue** card (queued, next position, deepest team, open liability, three explained empty states) and the `?harvest=yes\|no` filter |
| `/admin/members/{id}` | the member's panel: position, qualified at, teammates, levels paid, the next level, the ten-level list, every award row, and the gate for a member who has not qualified |
| `/admin/reports` | the **Team Harvest bonus** block for the window (credited, awards, recipients, before go-live, the queue, the ten-level ladder, by month) and the `type=team_harvest` range CSV with its `Scope` column (`Award` rows plus one `Queue` row) |
| `/admin/dashboard` | the **Team Harvest (30 d)** KPI with the open liability it implies |

Every figure is a read; the only write is the export audit row. The Package, Repeat and Royalty tabs are
byte-identical (`P20E-T1`), and the pages stay inside their statement budgets (the ledger's whole
context ≤ 8 selects, the directory position joined into its one page query).

### The member surfaces (20F)

Three read-only surfaces, scoped to the session member:

| Surface | What it shows |
|---|---|
| `/earnings?ledger=team_harvest` | the fourth tab: position and gate sentence, five counters, the whole 3×10 matrix with each level's state and amount, the progress card, the paginated roster, the level rows with their wallet links, the two exclusions, the purchases footnote, the FAQ and the Appendix C.6 CSV (`team-harvest-statement_YYYY-MM-DD.csv`, no audit row) |
| `/dashboard` | the **Team Harvest** tile: position (or the gate), the next level's progress, what reached the wallet, the recorded-before-go-live count, and a warning when the stream is not paying |
| `/genealogy` | the reader's **Harvest #N** badge, a **Harvest** column on the direct recruits and the same badge in the team-by-level card; `—` for a member who has not qualified |

`&user_id=` changes neither the page nor the file, and the copy rules are the plan's: the gate with the
position, the two exclusions and the purchases footnote once, no promise of a date or a guaranteed
income, and *Complete — not paying at this depth* above the configured depth. The tab's cost is
constant (10 selects on page 1 and page 2 of a 500-teammate member).

```bash
php tests/run.php TeamHarvestEngine           # 28 engine cases (20D)
php tests/run.php TeamHarvestCatchup          #  6 job cases (20D)
php tests/run.php TeamHarvestReconcile        #  5 reconcile/reset cases (20D)
php tests/run.php HarvestQueue                # 30 queue cases (20C)
php tests/run.php TeamHarvestConfiguration    # 24 configuration cases (20B)
php tests/run.php TeamHarvestAdminView         # 23 admin-visibility cases (20E)
php tests/run.php TeamHarvestMemberView        # 19 member-visibility cases (20F)
php tests/run.php TeamHarvest                  # the whole stream (20A–20F)
```

---

## General Harvest bonus (Phases 21A–21C)

One **shared 3-ary board** over the Harvest queue Phase 20 built
(`dmp/plan-ecowealth-general-harvest.md`, reference `docs/comp-plan_addons.md` §4). Every
Harvest-qualified member holds exactly one **node**: their queue position minus one
(`seq = harvest_queue.id − 1`). The node's level-`L` block is the `3^L` positions that follow it —

```
slots(L)     = 3^L                          3, 9, 27, 81 … 59,049
start(q,L)   = 3^L·q + (3^L − 1)/2          0-based first slot
block(q,L)   = positions [start+1 … start+3^L]
filled(q,L)  = clamp(N − start, 0, 3^L)
complete     ⇔ N ≥ 3^L·q + (3^(L+1) − 1)/2   the threshold N_min(q, L)
```

— and a level pays its flat amount **once, when the block is full**, all or nothing. The block is
filled by whoever took those queue positions, so the money crosses team lines: a registration at
position #9,001 can complete a stranger's block. `N` (the queue length) is the only input; no
purchase, rank, month, package or sponsor enters any formula.

| Level | Slots | Apex needs `N` | Reference amount | Cumulative |
|---:|---:|---:|---:|---:|
| 1 | 3 | 4 | ₱2,000.00 | ₱2,000.00 |
| 2 | 9 | 13 | ₱4,000.00 | ₱6,000.00 |
| 3 | 27 | 40 | ₱8,000.00 | ₱14,000.00 |
| 4 | 81 | 121 | ₱12,000.00 | ₱26,000.00 |
| 5 | 243 | 364 | ₱25,000.00 | ₱51,000.00 |
| 6 | 729 | 1,093 | ₱100,000.00 | ₱151,000.00 |
| 7 | 2,187 | 3,280 | ₱250,000.00 | ₱401,000.00 |
| 8 | 6,561 | 9,841 | ₱480,000.00 | ₱881,000.00 |
| 9 | 19,683 | 29,524 | ₱2,500,000.00 | ₱3,381,000.00 |
| 10 | 59,049 | 88,573 | ₱5,000,000.00 | ₱8,381,000.00 |
| **Total** | **88,572** | | **₱8,381,000.00** | |

Node `q` needs `3^L·q` more than the apex at every level (position #2 reaches the full ladder at
147,622, #3 at 206,671, #4 at 265,720). The whole board's liability at queue size `N` is a closed
form — **₱2,000 at N = 4, ₱176,000 at N = 121, ₱190,208,000 at N = 88,573** — which is what every
report, reconcile check and test compares an observed ledger with.

**The schema (21A, migration `025`).** `general_harvest_levels` (the ten amounts, natural key),
`general_harvest_board` (the singleton: `baseline_n`, `armed_at`, `armed_by`, `settled_n`) and
`general_harvest_awards` (the money ledger, `UNIQUE (seq, level)`), plus `general_harvest` on
`ewallet_ledger.ref_type`. The three tables exist from 21A; **21B** reads the ladder and the queue,
**21C** records the baseline and **21D** pays.

| Setting | Default | Meaning |
|---|---|---|
| `general_harvest_enabled` | `0` | The one switch. Off writes no award; the queue keeps growing. |
| `general_harvest_max_levels` | `10` | The deepest level that may pay (1–10). Levels above it complete on the board and are marked *not paying at this depth*; their amounts are kept. |

There is deliberately **no baseline key**: the go-live queue length is money-critical state and lives
on the board row, under the same lock as `settled_n` (§13.2).

### `GeneralHarvestLevel` (21B)

The one place that turns the ladder into money and the one place that derives the board's structure.
**Every formula in §12.2–§12.6 lives here**, so a view, a job or a report never types a threshold:

| Method | What it answers |
|---|---|
| `rows()`, `map()`, `amounts()`, `amount($level)` | the configured ladder, zero-filled (a missing row is `0.00`) |
| `total()`, `cumulative()`, `referencePreset()`, `forForm()` | the full ladder, the running total, the Appendix A preset |
| `slots($level)`, `blockStart()`, `blockEnd()`, `blockFrom()`, `blockTo()`, `threshold()`, `apexThreshold()` | the block arithmetic (`2–4`, `5–13`, … and `4, 13, 40 … 88,573`) |
| `filled()`, `complete()`, `levelsFor()`, `earnedBy()` | one node at queue length `N` |
| `paid($level, $n)`, `qFor()`, `boardTotal()`, `awardCount()` | the whole board's closed form |
| `nextN()`, `nextCompletion()` | the queue length each level's next block needs |
| `maxLevels()`, `enabled()` | the two knobs |
| `validate()`, `save()` | the rules and the one write (ten rows, delete-then-insert, in one transaction) |

The rules: amounts are money (`nullable\|numeric\|gte:0\|lte:10000000\|decimal:2`), the ten may total
at most **₱25,000,000.00** (a typo guard, refused as a `sum` error and never clamped), **every level
inside the payable depth must be positive** to enable the stream or raise the depth (D-17), and the ten
amounts are **frozen while the board is armed** (D-23) — a change is refused with an `armed` error
naming the arm instant, because a shared board's whole liability is a closed form over one ladder.

### `GeneralHarvestBoard` (21B)

The board's state and the queue-side reads — read-only in this phase:

| Method | What it answers |
|---|---|
| `state()` | the singleton row (a missing row reads as unarmed) |
| `queueN()`, `gapless()` | `{n, max_id, gapless}` — `COUNT(*) = MAX(id)`, the D-03 invariant |
| `frontier()` | per level `{rows, complete, missing, next_n, amount}` from one grouped aggregate and the closed forms |
| `backlog()` | `N − settled_n` and the open liability it is worth |
| `status()` | one of the four strip states: `unarmed`, `armed_off`, `armed_on`, `unsettled` |
| `positionOf()`, `ownerOf()` | the member ↔ node bridge |
| `blocks($seq, $n)` | a node's ten blocks: slots, `#from–#to`, threshold, filled, percent, complete, amount, earned, paying |
| `blockRange($level, $offset, $limit)` | a page of the nodes whose block at that level is full |

### The settings card (21B)

**Settings → Commissions → General Harvest bonus** (below the Team Harvest block; one card, one form,
one save — the hidden `section=commissions` field). It renders:

- the switch, the payable depth and the ten amount inputs, each with its live cumulative and its
  *Pays at this depth* / *Not paying at this depth* badge;
- the **generated ladder table** — slots `3^L`, the apex threshold, the stored amount, the cumulative
  sum and the depth marking, every cell from `GeneralHarvestLevel` (the view computes none of them);
- the **generated position table** — positions #1–#6 × levels 1–4, the reference's §4.5 table on
  screen;
- the **four-state strip**, which names the single next action (*not armed* → run
  `bin/general_harvest_arm.php`; *armed and off*; *armed and on*; *armed with N − settled_n positions
  waiting* → run `bin/general_harvest_catchup.php`) and never claims money is owed;
- the **"If the queue reaches N" calculator** (`1000` → **₱1,601,000.00** in **493** awards), labelled
  as an illustration;
- the full-ladder total and the position dependence in one sentence (node #1 ₱8,381,000.00 at 88,573;
  node #2 at 147,622; node #3 at 206,671).

The save writes the switch, the depth and the ten rows **in one transaction** and audits
`general_harvest.ladder.update` (before/after) only when something changed; the tenant-wide
`settings.update` row still records the keys that moved. `app-general-harvest.js` re-renders the
cumulative column, the totals and the calculator as the operator types — progressive enhancement only:
with JavaScript off the form saves, validates and prints the same server-rendered numbers. **Arming is
not reachable from the page**: there is no field and no button for it, and while the board is armed the
ten inputs render disabled with the reason.

### `GeneralHarvestAward` (21C)

The ledger: one row per `(node, level)` decision, `UNIQUE (seq, level)` as the money anchor. 21C ships
the read API and the **baseline write only** — `writeBaseline()` and `writeBaselineLevel()` always
store `status = 'baseline'` and `ledger_id = NULL`, so the phase cannot write money even by mistake.
The credited path (`create()` / `linkLedger()`) is 21D's.

| Method | What it answers |
|---|---|
| `has($seq, $level)` | has this block been decided (credited or baselined)? |
| `find()`, `forMember()`, `recent()` | one row, one member's rows, the newest decisions |
| `countByLevel()`, `countByStatus()`, `minMaxByLevel()` | the frontier evidence: rows, `MIN`/`MAX` seq per level, both statuses |
| `select()`, `count()`, `totals()`, `byLevel()`, `byMonth()`, `exportRows()` | the filters, pages, totals, ten-row distribution, monthly split and CSV rows 21E/21F render |
| `writeBaseline()`, `writeBaselineLevel()` | the arm's only writes (one row, or one level's prefix in one statement) |

The model never opens a transaction: it runs inside its caller's, so the ledger and the board row
commit together or not at all.

### `GeneralHarvestService` and the arm (21C)

The go-live baseline. 21C owns **arm** and **state**; 21D adds the settlement.

| Method | What it answers |
|---|---|
| `enabled()`, `armed()`, `baseline()` | the switch, whether a baseline exists, and `N₀` |
| `state()` | the board's condition — `GeneralHarvestBoard::status()` plus the backlog's money value and the depth |
| `excluded()` | the baseline's rows and amount, level by level, beside `boardTotal(baseline_n)` |
| `assertArmable()`, `arm($dryRun)` | the refusals, and the one-time write |

The arm refuses (`GeneralHarvestException`) when the stream is on, the board is already armed, Phase
20's queue is unseeded, a level is priced at zero, or the queue is not gapless — and it refuses
**before** the transaction opens, so a refused arm writes nothing. A dry run prints the same `N₀`, row
count and excluded amount and writes nothing: it is the decision surface. `GET_LOCK`
(`general_harvest_arm`) serialises two runs; `armed_at` is the flag that makes the act one-time, with no
`--force`. It writes only `baseline` rows: `settled_n` is set to `N₀` because every block up to the
baseline is **recorded**, not paid.

```bash
php bin/general_harvest_arm.php --dry-run    # N0, rows, excluded amount, first positions
php bin/general_harvest_arm.php              # record the baseline (one transaction, one audit row)
php bin/general_harvest_arm.cmd --dry-run    # the Windows launcher
```

Exit codes: `0` success (or a clean dry run), `1` refused, `2` database unreachable or a table
missing. `--json` prints exactly `armed`, `n`, `rows`, `excluded`, `per_level` and `dry_run`. There is
no web entry and no settings-page button: arming is a CLI act, so there is no URL to authenticate. The
order — and every refusal — is in `docs/runbook.md` → *General Harvest go-live order, part 1*.

```bash
php tests/run.php GeneralHarvestConfiguration  # 35 configuration + arithmetic cases (21B)
php tests/run.php GeneralHarvestBaseline       # 21 baseline + arm cases (21C)
php tests/run.php GeneralHarvest              # the whole stream (21A–21C)
```

---

## Cron jobs

Six scheduled jobs, each with a CLI runner, a guarded URL entry and a Windows launcher.

| Job | CLI | Web | Windows |
|---|---|---|---|
| Shop expiry (cancel unpaid orders) | `bin/shop_expire.php` | `/system/cron/shop_expire.php` | `bin/shop_expire.cmd` |
| Order auto-complete (close old deliveries) | `bin/order_autocomplete.php` | `/system/cron/order_autocomplete.php` | `bin/order_autocomplete.cmd` |
| Transfer-limit reset (daily/weekly counters) | `bin/reset_transfer_limits.php` | `/system/cron/reset_transfer_limits.php` | `bin/reset_transfer_limits.cmd` |
| Unilevel month close (monthly Active snapshot) | `bin/unilevel_month_close.php` | `/system/cron/unilevel_month_close.php` | `bin/unilevel_month_close.cmd` |
| Unilevel release (credits due pending bonus) | `bin/unilevel_release.php` | `/system/cron/unilevel_release.php` | `bin/unilevel_release.cmd` |
| Royalty month close (monthly rank-gated bonus) | `bin/royalty_month_close.php` | `/system/cron/royalty_month_close.php` | `bin/royalty_month_close.cmd` |

- Flags (CLI `--x=…`, URL `?x=…`): `limit`, `dry-run`, `json`, `doctor`, `log-dir` (CLI only),
  `quiet` (CLI only), `help`; the expiry job also has `from-settings`, the reset job `week` and
  `daily-only`, both month closes `period`, the release job `order`, and the Royalty close also
  `evaluate-only`, `pay-only` and `max-batches`.
- `--from-settings` recomputes expiry deadlines from the **current** settings instead of the stored
  stamps (`pending`: `created_at` + payment window; `payment_failed`: last rejection + correction
  window). The cron itself uses the stamps, so changing a window never retroactively cancels old
  orders.
- `--week` forces the transfer-limit weekly reset on any weekday (repairing a missed Monday);
  `--daily-only` never touches the weekly counter.
- `--period=YYYY-MM` closes exactly one completed month. Without it the month close catches up: every
  open month from `unilevel_ledger_since` through last month, oldest first.
- `--order=ID` runs the release for one order under the **normal** due rule (a diagnostic, never a
  force). `--limit` caps the due orders one release run may credit (1–1000, default 200).
- The web entries are trusted on loopback (local testing needs no setup); any other caller needs
  `?key=` matching `CRON_KEY` in `app/config.local.php`. The CLI is always allowed.
- One run at a time (`CronLock`); monthly logs at `storage/logs/shop_expire-YYYY-MM.log`,
  `storage/logs/order_autocomplete-YYYY-MM.log`, `storage/logs/reset_transfer_limits-YYYY-MM.log`,
  `storage/logs/unilevel_month_close-YYYY-MM.log`, `storage/logs/unilevel_release-YYYY-MM.log` and
  `storage/logs/royalty_month_close-YYYY-MM.log`;
  `--doctor` reports PHP/DB time, settings, candidates, lock and log state. The release doctor also
  reports pending count/amount, the oldest pending award, what is due now, undelivered and stuck
  `shipped` awards, and `cancelled_due` (must be `0`). The Royalty doctor reports the PHP/MySQL clocks
  (±2 s), the time zone, both tables, the sixteen settings and the stored ladder, the ledger baseline,
  both switches, the last paid and last final month, pending and evaluated months, the paid-with-pending
  check, the lock, the wallet reconciliation and storage state; exit 1 on a hard failure, a `WARN` line
  (exit 0) on a paused payment switch or a month left `evaluated` for more than 48 hours.
- Kill switches: `shop_expiry_enabled`, `shop_autocomplete_enabled`, `unilevel_release_enabled`,
  `royalty_enabled` and `royalty_pay_enabled`
  (settings page). Each makes every run of its job exit 0 without changing anything. The release
  switch pauses the credits but **still cancels pending awards when an order is cancelled**. The reset
  job has nothing to switch off — a run only zeroes counters a transfer would have incremented. The
  **unilevel month close has no switch on purpose**: it pays no money, so there is nothing to pause, and
  it must snapshot a period even while `unilevel_enabled = 0`. The Royalty close's **earning** switch
  closes the month as `skipped` (final, never paid later); its **payment** switch evaluates the month and
  leaves every award `pending`, so a later `--pay-only` pays it.
- What drives the month close: `unilevel_active_threshold` (the monthly personal-purchase minimum,
  ₱500 by default, snapshotted onto every closed period) and `unilevel_ledger_since` (the baseline
  the catch-up starts from; the install month, never rendered on a page). Its `award_total` is the
  month's **earned** repeat-purchase money, `pending + credited`.
- What drives the release: `unilevel_hold_days` (0–365, default 7; snapshotted on each award) and the
  order's delivery stamp. An undelivered order never releases.
- What drives the Royalty close: the four rank thresholds, the three monthly group quotas and the six
  group/personal rates (settings page; snapshotted onto every closed month), and
  `royalty_ledger_since` — the first payable month. A month is evaluated at its own period-end cutoff
  and paid once; a **final month is never recalculated**, so an order cancelled after the close does
  not claw the award back (plan D-13).

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

php bin/unilevel_month_close.php --doctor
php bin/unilevel_month_close.php --dry-run
php bin/unilevel_month_close.php
php bin/unilevel_month_close.php --period=2026-10 --json

php bin/unilevel_release.php --doctor
php bin/unilevel_release.php --dry-run
php bin/unilevel_release.php
php bin/unilevel_release.php --order=93 --json

php bin/royalty_month_close.php --doctor
php bin/royalty_month_close.php --dry-run
php bin/royalty_month_close.php --max-batches=20
php bin/royalty_month_close.php --period=2026-10 --evaluate-only
php bin/royalty_month_close.php --period=2026-10 --pay-only --json
```

**Team Harvest adds no cron entry.** It has no month, no period and no close (D-08), so there is nothing
to schedule: its award happens at the qualification event and the two Team Harvest jobs are **on
demand** — `bin/harvest_queue_seed.php` (once, at go-live) and `bin/team_harvest_catchup.php` (after
enabling, after a repair, after an alarm). Neither job has a guarded URL entry, because a browser cannot
be trusted with a money run; both are CLI/`.cmd` only and both take their own lock.

Full operations guide, Hostinger cron lines and troubleshooting: **`docs/cron.md`**.

---

## Tests

```bash
php tests/run.php                 # full suite (1,392 cases; Phase 21 is in progress through 21C)
php tests/run.php Router           # cases whose group/label matches "Router"
php tests/run.php --probe-failure  # proves a failing assertion exits non-zero (P1-T7)

powershell -NoProfile -File tests/smoke.ps1    # 268 HTTP checks against the running app
```

- `tests/bootstrap.php` drops and recreates `ecowealth_sneat_test` from `schema.sql` and
  the seeds, so a run never touches the development database (the name must end in `_test`).
- Every case runs inside a transaction that is rolled back. Cases that need later-phase
  tables (cart/order references) or their own transaction (package rollback) handle that
  explicitly, because DDL commits.
- Uploads are written to a scratch folder (`UPLOAD_ROOT`) and cron logs/locks to another
  (`STORAGE_PATH`) under the system temp directory.
- The migration test builds `ecowealth_sneat_migration_test` from
  `tests/fixtures/legacy_phase0_schema.sql`, applies migrations 001–025 in order and
  compares the result with `schema.sql`.

The smoke script needs the app running and MySQL access for temporary changes (a throw-away
member account with its wallet, orders, addresses, proofs, shipments and invoices, products with SKU
prefix `SMOKE-`, packages named `Smoke Package…`, a member-to-member transfer with its ledger legs and
the limit counters it moves, the payment/fulfilment/transfer settings it edits, the super-login sessions
and audit rows it creates, the reports exports and settings saves it audits, `maintenance_mode`; all
restored). Phase 15 adds 11 HTTP checks: security headers, uploads/ PHP denial, the reports/audit
guards, the branded 404, the eight settings cards and their validation, the reports desk and its
window, the reports performance budget, the CSV exports and their audit rows, the audit filters and the
transfer throttle. The install/reset workstream adds ten more — `IR4-T1…T5` for the web installer's
refusal states, hardening headers, GET/POST side effects and shared stylesheet, and `IR6-T1…T5` for the
reset console's guest redirect, member 403, admin form with no secret, three refusals that change
nothing, and its stylesheet. Phase 17 adds **30** more: the product ladder card, the settings block and its validation, a paid order under the configured product, the repeat ledger and its filtered CSV, the report cards, the member product panel / checkout line / order card / earnings tab / dashboard tile, and the month-close URL pair (P17F-T10 doctor, P17F-T11 dry-run JSON). Phase 18 adds **4** (the escrow's pending/release rows), Phase 19 adds **19** (the commissions settings sub-block and the member Rank column, the Royalty ledger and its filters/CSV, the member statement and tile, and the Royalty close's URL entry and doctor), and Phase 20 adds **15**: three on the Team Harvest settings ladder (P20B), then the release walk (P20G) — the settings card, the fourth ledger tab and the three unchanged tabs, the directory and its filter, the reports block and CSV, the unqualified and queued member statements, the dashboard tile, the genealogy badge, and the registration hook. Phase 21B adds **5** more: the General Harvest card and its ten inputs, the generated ladder and position table, the ₱25,000,000.00 sum refusal, the depth-11 refusal and the card's inertness — **268** HTTP checks in all.

The install/reset groups run without a browser and without the application connection:

| Group | File | What it proves |
|---|---|---|
| `SqlScript` | `Cases/SqlScriptTest.php` | Statement splitting (quotes, comments, backticks), line numbers, `DELIMITER`/database-switch rejection, failure context, checksum, `schema.sql` import |
| `DbTarget` | `Cases/DbTargetTest.php` | Connection/database probing, identifier validation, `information_schema` table listing, operator hints without a DSN |
| `SeedParity` | `Cases/SeedParityTest.php` | Every setting a migration inserts exists in seed 001 and in `install.sql`; no duplicate keys; no executable database switch |
| `InstallSql` | `Cases/InstallSqlTest.php` | The bundle is current (`--check`), imports into an empty scratch schema, matches the fresh schema/seed data by natural key, and is refused by a non-empty one |
| `Install` | `Cases/InstallTest.php` | Installer preflight, install + lock, re-run/non-empty refusals, password policy, `verify()`, JSON shape, install-key gate |
| `DbReset` | `Cases/DbResetTest.php` | Preview/dry-run, the transactional reset, staff defaults, audit kept/cleared, catalogue reset, codes, rollback, uploads, live revisions, AUTO_INCREMENT, the refusal matrix, demo/parity |
| `DbToolAccess` | `Cases/DbToolAccessTest.php` | Install-key and reset visibility/actor/phrase matrices, S-Login refusal |

They share two pre-provisioned scratch schemas (`ecowealth_sneat_tool_a_test`,
`ecowealth_sneat_tool_b_test`) that each case recreates; no tool test targets `ecowealth_sneat`.
The one-time grant is in `docs/qa/evidence/install-reset/ir1-plumbing.txt`.

```powershell
powershell -NoProfile -File tests/smoke.ps1 -DbUser root -DbPass ''
```

---

## Migrations

- Fresh install: import `database/install.sql` (the generated bundle — schema + seeds 001–004 +
  install metadata; no database name, fresh-install only), or use `bin/db_install.php` /
  `public/install.php` to choose the staff passwords and write `storage/install.lock`.
  `database/schema.sql` and `database/seeds/*.sql` remain the editable sources — rebuild the bundle
  with `php bin/build_install_sql.php` after changing them.
- Existing install: apply `database/migrations/NNN_*.sql` in order, then the phase's seed. Never
  import the bundle over an existing schema; the guard refuses it and a phpMyAdmin run that
  continues past the error must be recovered by dropping and recreating the schema.
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
| `019_audit_and_reporting.sql` | the Phase 15 report indexes (`audit_log.action`, `audit_log.created_at`, `shop_orders.created_at/paid_at`, `ewallet_ledger.created_at`, `commissions.level`, `reg_codes.created_at`); re-creates `audit_log` when an old install skipped it; every step is idempotent |
| `020_unilevel_repeat_purchase.sql` | `product_unilevel_levels`, `unilevel_awards`, `unilevel_periods`, `unilevel_activity`; `products.pv` + `unilevel_base` + `unilevel_enabled`; `shop_order_items.unit_base` + `base_total`; `shop_orders.unilevel_processed_at` + `unilevel_total`; two order indexes; `ewallet_ledger.ref_type` gains `unilevel` and `unilevel_reversal`; the six `unilevel%` settings (all off by default); additive and re-runnable |
| `021_unilevel_bonus_escrow.sql` | `unilevel_awards.hold_days` (`SMALLINT UNSIGNED`, default 0) + `credited_at`; the status enum gains `pending` (the new default) and `cancelled`; `idx_unilevel_user_status` + `idx_unilevel_order_status`; `credited_at` backfilled from `created_at` for existing `credited`/`voided` rows; the two settings `unilevel_hold_days` (7) and `unilevel_release_enabled` (1); additive and re-runnable, **no new table and no retroactive lock** |
| `022_shop_cancel_toggle.sql` | The setting `shop_cancel_enabled` (**Enable Cancel of Order**, **0** = closed) only; additive and re-runnable, **no table, no column**, so an upgraded database gets the safe default |
| `023_royalty_bonus.sql` | `royalty_periods` (one header per month, natural `period` key) and `royalty_awards` (one row per member per month, `UNIQUE (period, user_id)`, the money CHECKs); `ewallet_ledger.ref_type` gains `royalty`; the sixteen `royalty_*` settings (the stream off by default); additive and re-runnable |
| `024_team_harvest.sql` | `harvest_queue` (one row per qualified member; `id` **is** the position, `UNIQUE (user_id)`), `team_harvest_levels` (the ten amounts, natural key) and `team_harvest_awards` (the money ledger, `UNIQUE (user_id, level)`, the money CHECKs); `ewallet_ledger.ref_type` gains `team_harvest`; the four `team_harvest_*` settings (off and unseeded by default) and the ten reference ladder rows; additive and re-runnable |
| `025_general_harvest.sql` | `general_harvest_levels` (the ten amounts, natural key), `general_harvest_board` (the singleton `id = 1`: `baseline_n`, `armed_at`, `armed_by`, `settled_n`) and `general_harvest_awards` (the money ledger, `UNIQUE (seq, level)`, the money CHECKs; `ledger_id` is deliberately not an FK); `ewallet_ledger.ref_type` gains `general_harvest`; the two `general_harvest_*` settings (off and unarmed by default), the ten reference ladder rows and the unarmed board row; additive and re-runnable, and a re-run never overwrites an operator's amount or re-arms a board |

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
