# EcoWealth — Phase 17 manual QA: unilevel repeat-purchase bonus

**Scope.** Phase 17 of the member system (`dmp/plan-ecowealth-unilevel-repeat-purchase.md`): a
per-product repeat-purchase ladder that pays the buyer's sponsor chain (levels 1–10) on every **paid**
shop order, gated by the monthly Active rule. The work is delivered in seven gated phases, **17A →
17G**. This document is created by 17A with **section A only**; every later phase appends its own
section before its gate, and the file is never rewritten.

**Audience.** The developer/operator who runs the phase gate. The checks are surgical and, from
section B on, may move test money: every one runs on a scratch schema or on the local development
copy, never on a production database.

**Document status.** Section A (17A — Foundation) written 2026-11-12 with the automated gate green.
Sections B–G are added by their phases.

---

## 1. What is already automated

Run these first, from `system/`. If any fails, stop and report before starting the manual pass.

```powershell
php bin\build_install_sql.php --check     # exit 0, no output: the bundle matches its sources
php tests\run.php                         # full suite
powershell -NoProfile -File tests\smoke.ps1
php bin\wallet_reconcile.php              # exit 0: the money is untouched by this phase
```

Expected final lines:

```text
Cases: 830   Passed: 830   Failed: 0
All good.
```

```text
Passed: 195   Failed: 0
```

The groups that carry Phase 17A (its own share of the suite is listed here):

| Group | Cases | What it proves for 17A |
|---|---:|---|
| `UnilevelFoundation` | 11 | The migration's four tables, four altered tables, two indexes, extended enum and six settings; a second run is a no-op; the bundle matches its sources and creates 31 tables; the installer/reset expectations agree with the schema; `REF_TYPES` and the labels; the migrated and fresh defaults; the ladder/award cascades and CHECKs; and that **nothing** reads the new objects yet |
| `MigrationTest` | +1 | `020_unilevel_repeat_purchase.sql` is in the ordered migration list, the migrated schema equals a fresh install for the new tables (columns, indexes, foreign keys), the enum matches, and the file re-runs cleanly without overwriting an operator value |
| `SeedParityTest` | 4 | Unchanged by design: it already fails unless seed 001 carries every settings key a migration inserts, so the six keys are proven in both places (P17A-T4) |
| `InstallTest` | +1 | A fresh install reports **31 tables / 54 settings**; one award row makes the install verification fail and names `unilevel_awards` (P17A-T7) |
| `InstallSqlTest` | 6 | The bundle's structure, settings and catalogue still equal a fresh install; the two `*_ledger_since` keys are compared by shape |
| `DbResetTest` | +1 | A business reset empties the three repeat-purchase money tables and keeps the ladder and the six settings (P17A-T8) |
| `WalletTest` / `DbTargetTest` / `SqlScriptTest` | — | The wallet enum has ten values; the harness schema has 31 tables |

The migration and bundle cases build scratch schemas (`ecowealth_sneat_migration_test`,
`ecowealth_sneat_tool_a_test`, `ecowealth_sneat_tool_b_test`). No automated case targets
`ecowealth_sneat` or `ecowealth_sneat_test`.

---

## 2. Environment and rules

| Item | Value |
|---|---|
| App | `http://localhost/ecowealth/system/` (Laragon Apache + MySQL) |
| Development database | `ecowealth_sneat` — **never** a target of the destructive checks; migrated to 17A before this pass (backup: `C:\laragon\www\ecowealth-backups\phase-17\`, see `QA17A-dev-db-migration.txt`) |
| Scratch schema for section A | `ecowealth_sneat_qa17a` (created as root, granted to the app user, dropped afterwards) |
| App selection without editing config | `$env:ECO_DB_NAME = '<scratch>'` (PowerShell) or `export ECO_DB_NAME=<scratch>` (bash) |
| PHP / MySQL | 8.3.16 · 8.4.3 |
| Evidence | `docs/qa/evidence/phase-17/` (see that folder's `README.md`) |

Rules that protect the project:

1. **Create every scratch schema as root** and grant it to the app user; the app user deliberately has
   no `CREATE DATABASE` privilege.
2. **Never run migration 020 or a reset against `ecowealth_sneat`** during this pass. 17A changes no
   money, but the checks are still schema surgery.
3. **Back up before anything destructive** (`docs/runbook.md` §3). The migration is additive and
   idempotent, but a reset has no undo.
4. **Do not leave a scratch schema, a test order or an award row behind.** Drop the scratch schema when
   the evidence is filed; the development copy is only touched by QA17A-04 … QA17A-06, which write
   nothing.
5. **Never put a password, key, cookie or DSN in the evidence folder.**

---

## 3. Section A — Foundation (`QA17A-01` … `QA17A-08`)

**Prerequisites.** The `p17-start` tag (or any tree that includes migration 019), a scratch schema
created as root, and a green automated gate (§1). **Expected suite:** 830 / 830. **Time:** 35–45 min.

### QA17A-01 — Migration 020 builds the four tables and is re-runnable

| Why it matters | Every later phase reads these tables; an unrepeatable migration is a deployment risk. |
|---|---|
| Where | Scratch schema `ecowealth_sneat_qa17a` |
| Evidence | `QA17A-01-tables.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As root: `DROP DATABASE IF EXISTS ecowealth_sneat_qa17a; CREATE DATABASE ecowealth_sneat_qa17a CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` and grant it to the app user.
2. Build a `p17-start` state in it: import `database/schema.sql` and seeds `001`–`004`, **or** (to prove the upgrade path) import `tests/fixtures/legacy_phase0_schema.sql` and then migrations `001` … `019` in order.
3. Apply `database/migrations/020_unilevel_repeat_purchase.sql`.
4. Run the same file a second time.
5. Run `SHOW TABLES LIKE '%unilevel%';`.

Expected

- Both runs finish without an error; the second prints no `ERROR` line.
- Exactly four rows: `product_unilevel_levels`, `unilevel_activity`, `unilevel_awards`,
  `unilevel_periods`.
- The total table count is **31** (`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()`).

If it fails: capture the exact statement and the MySQL error; do not hand-patch the schema.

### QA17A-02 — The four altered tables and the extended wallet enum

| Why it matters | A missing column silently breaks 17B/17C; the enum must accept the two new movement kinds. |
|---|---|
| Where | Same scratch schema |
| Evidence | `QA17A-02-columns.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SHOW COLUMNS FROM products;`
2. `SHOW COLUMNS FROM shop_order_items;`
3. `SHOW COLUMNS FROM shop_orders;`
4. `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type';`

Expected

- `products` carries `unilevel_enabled tinyint(1) NOT NULL DEFAULT '0'` and
  `unilevel_base enum('auto','price','pv') NOT NULL DEFAULT 'auto'`, both directly after `pv`.
- `shop_order_items` carries `unit_base` and `base_total` (`decimal(12,2)`, `YES`), both directly
  after `line_total`.
- `shop_orders` carries `unilevel_processed_at` (`datetime`, `YES`) and `unilevel_total`
  (`decimal(12,2)`, `NO`, default `0.00`), both directly after `paid_at`; `SHOW INDEX FROM shop_orders`
  also lists `idx_orders_unilevel` and `idx_orders_user_paid`.
- The `ref_type` type ends `…,'refund','unilevel','unilevel_reversal'`.

### QA17A-03 — The six settings, all off by default

| Why it matters | Deploying the code must not start paying money (D-11). |
|---|---|
| Where | Same scratch schema |
| Evidence | `QA17A-03-settings.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT key_name, value FROM settings WHERE key_name LIKE 'unilevel%' ORDER BY key_name;`

Expected

- Exactly **6** rows: `unilevel_active_threshold = 500`, `unilevel_enabled = 0`,
  `unilevel_ledger_since = <a valid YYYY-MM-DD HH:MM:SS timestamp>`, `unilevel_max_levels = 10`,
  `unilevel_requires_active_upline = 1`, `unilevel_reversal_enabled = 1`.
- `SELECT COUNT(*) FROM products WHERE unilevel_enabled <> 0;` → **0**.

### QA17A-04 — No new admin UI exists yet

| Why it matters | 17A ships no reader; a ladder card or settings block here means the phase has crept. |
|---|---|
| Where | `/admin/settings` · `/admin/products/{id}/edit` (development database) |
| Evidence | `QA17A-04-admin-settings.png`, `QA17A-04-product-edit.png` (+ the HTML transcripts) |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `admin`, open **System → Settings**.
2. Read the **Commissions** card from top to bottom.
3. Open any product's **Edit** page; check *Details*, *Pricing & stock* and *Image*.

Expected

- The Commissions card shows the Phase 9–11 fields only: no **Repeat-purchase bonus** sub-block, no
  *Upline depth*, no *Monthly personal-purchase minimum*, no *Reverse awards* switch.
- The product form shows no P.V. field and no ladder card; *Pricing & stock* is exactly as it was
  before this phase.
- Every existing page renders with **no** new JavaScript error in the console.

### QA17A-05 — A paid order pays nothing yet

| Why it matters | The tables are empty and unread; the wallet and the order must be untouched. |
|---|---|
| Where | Member shop → checkout (e-wallet) · the order page · the sponsor's `/wallet` |
| Evidence | `QA17A-05-order.txt`, `QA17A-05-awards-count.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As a member who has a sponsor (for example `maria`, sponsored by `juan`), place a small e-wallet
   order and pay it.
2. Open the order page and confirm it is **Paid**.
3. Sign in as the sponsor, open `/wallet`, and read the statement.
4. In the scratch/development database run `SELECT COUNT(*) FROM unilevel_awards;`.

Expected

- The order page is unchanged: no repeat-purchase card, no award line, no mention of the ladder.
- The sponsor's statement has **no** *Repeat-purchase bonus* row and the balance is unchanged from
  before the order (other than any unrelated movement).
- `unilevel_awards` counts **0**; `shop_orders.unilevel_processed_at` is `NULL` for the new order and
  `unilevel_total` is `0.00`.

### QA17A-06 — The wallet statement filter lists the two new sources

| Why it matters | This is the one visible change of 17A (plan §5 Rule 6); the options must exist and match nothing. |
|---|---|
| Where | `/wallet` — the **Source** filter of the statement |
| Evidence | `QA17A-06-wallet-filter.png`, `QA17A-06-wallet-reversal.png` (+ the HTML transcript) |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/wallet` as any member.
2. Open the **Source** drop-down and read every option.
3. Select **Repeat-purchase bonus**, apply the filter, then repeat with **Repeat-purchase reversal**.

Expected

- The list ends with **Repeat-purchase bonus** and **Repeat-purchase reversal**, spelled exactly like
  that.
- Either selection returns the filtered empty state (no rows), because no such movement exists yet.
- Choosing **All sources** restores the normal statement.

### QA17A-07 — A fresh install reports 31 tables and 54 settings

| Why it matters | The bundle is the production install path; it must match the migration exactly. |
|---|---|
| Where | Scratch schema `ecowealth_sneat_qa17a_install` |
| Evidence | `QA17A-07-verification.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. From `system/`: `php bin\build_install_sql.php` then `php bin\build_install_sql.php --check` (the
   check must exit 0 and print nothing).
2. As root, create an empty scratch schema and grant it to the app user.
3. `mysql -u root <scratch> < database/install.sql` (or import it in phpMyAdmin).
4. Read the footer verification `SELECT`.

Expected

- `tables_created = 31`, `settings_rows = 54`, `staff_rows = 2`, `members_rows = 0`,
  `products_rows = 7`, `packages_rows = 2`, `levels_rows = 18`, `contents_rows = 10`,
  `codes_rows = 0`.
- `php bin\wallet_reconcile.php` exits **0** (against the development schema; the scratch install has no
  wallet rows to reconcile).
- No `CREATE DATABASE` or `USE` executes; the bundle is imported into the schema the caller selected.

### QA17A-08 — A development reset keeps the ladder and empties the money

| Why it matters | The reset engine must know the four tables, or a QA box cannot return to a clean state. |
|---|---|
| Where | Scratch schema `ecowealth_sneat_qa17a_reset` · `public/reset.php` or `bin/db_reset.php` |
| Evidence | `QA17A-08-reset.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build the scratch schema from `schema.sql` + seeds `001`–`005`.
2. Insert one ladder row (`INSERT INTO product_unilevel_levels (product_id, level, percent) SELECT id, 1, 5.000 FROM products LIMIT 1;`) and one row in each of `unilevel_awards`, `unilevel_activity` and `unilevel_periods` (the parents must exist: an order, its item and two users).
3. Run the reset once (`php bin\db_reset.php --database=<scratch> --confirm=<scratch> --json`, or the web console as `admin`).
4. Read the verification block and count the four tables and the settings again.

Expected

- The reset reports **ok**, with every check green.
- `unilevel_awards`, `unilevel_activity` and `unilevel_periods` count **0**.
- `product_unilevel_levels` still holds the ladder row; the six `unilevel%` settings are unchanged.
- The step log carries a **Repeat-purchase bonus awards and month snapshots** step.

---

## 4. Section A sign-off

| Item | Value |
|---|---|
| Tester | Automated QA pass — DeepSeek Harness (DSH) |
| Date | 2026-11-12 |
| Automated gate | `Cases: 830 / 830` · `Passed: 195 / 195` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 |
| Checks passed | 8 / 08 |
| Defects | none |
| Decision | ☑ Approved ☐ Approved with notes ☐ Blocked — approved from the executed transcripts; QA17A-04 and QA17A-06 were read from the served HTML (no browser in the automated pass), so a human may add the screenshots without changing the result |
| Evidence | `docs/qa/evidence/phase-17/` (`QA17A-01-tables.txt` … `QA17A-08-reset.txt`, plus `QA17A-dev-db-migration.txt`) |

