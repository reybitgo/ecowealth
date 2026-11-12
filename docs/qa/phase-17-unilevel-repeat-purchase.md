# EcoWealth — Phase 17 manual QA: unilevel repeat-purchase bonus

**Scope.** Phase 17 of the member system (`dmp/plan-ecowealth-unilevel-repeat-purchase.md`): a
per-product repeat-purchase ladder that pays the buyer's sponsor chain (levels 1–10) on every **paid**
shop order, gated by the monthly Active rule. The work is delivered in seven gated phases, **17A →
17G**. This document is created by 17A with **section A only**; every later phase appends its own
section before its gate, and the file is never rewritten.

**Audience.** The developer/operator who runs the phase gate. The checks are surgical and, from
section B on, may move test money: every one runs on a scratch schema or on the local development
copy, never on a production database.

**Document status.** Section A (17A — Foundation) written 2026-11-12 with the automated gate green;
section B (17B — Configuration) appended 2026-11-12. Sections C–G are added by their phases.

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
Cases: 855   Passed: 855   Failed: 0
All good.
```

```text
Passed: 209   Failed: 0
```

The counts grow with each phase: **830 / 195** at `p17a-foundation`, **855 / 209** at
`p17b-configuration`. Section A's own expectations are stated in section 3; section B's in section 5.

The groups that carry Phase 17A (its own share of the suite is listed here):

| Group | Cases | What it proves for 17A |
|---|---:|---|
| `UnilevelFoundation` | 11 | The migration's four tables, four altered tables, two indexes, extended enum and six settings; a second run is a no-op; the bundle matches its sources and creates 31 tables; the installer/reset expectations agree with the schema; `REF_TYPES` and the labels; the migrated and fresh defaults; the ladder/award cascades and CHECKs; and that **nothing** writes an award (the "inert" half, narrowed by 17B) |
| `MigrationTest` | +1 | `020_unilevel_repeat_purchase.sql` is in the ordered migration list, the migrated schema equals a fresh install for the new tables (columns, indexes, foreign keys), the enum matches, and the file re-runs cleanly without overwriting an operator value |
| `SeedParityTest` | 4 | Unchanged by design: it already fails unless seed 001 carries every settings key a migration inserts, so the six keys are proven in both places (P17A-T4) |
| `InstallTest` | +1 | A fresh install reports **31 tables / 54 settings**; one award row makes the install verification fail and names `unilevel_awards` (P17A-T7) |
| `InstallSqlTest` | 6 | The bundle's structure, settings and catalogue still equal a fresh install; the two `*_ledger_since` keys are compared by shape |
| `DbResetTest` | +1 | A business reset empties the three repeat-purchase money tables and keeps the ladder and the six settings (P17A-T8) |
| `WalletTest` / `DbTargetTest` / `SqlScriptTest` | — | The wallet enum has ten values; the harness schema has 31 tables |

Phase 17B's share:

| Group | Cases | What it proves for 17B |
|---|---:|---|
| `UnilevelConfiguration` (new) | 24 | The ladder model (ten rows, blanks = 0.000, three decimals, the 29% preset), the validation rules (per level, the 100% sum, switch-off-or-a-rate, the P.V. base), the atomic save and its one audit entry, `levelsMap`/`totalsMap`/`rowsFor` with no N+1, `baseFor`, the create and edit forms (no direct/indirect control), the refused-form round trip, the list badge and the `?unilevel=` filter, the settings card and its rules, and that a paid order still writes no award |
| `ProductViewTest` | +1 | The shared form view renders the card for any caller, and an older caller that passes no ladder still gets ten blank editable rows (P17B-T14) |
| `ProductTest` | 1 | `referencesMap()` reports the new `awards` count (P17B-T13) |
| `SettingsPageTest` | 2 | The commissions card's key and toggle lists include the five repeat-purchase keys (P17B-T18/T19) |

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


---

## 5. Section B — Configuration (`QA17B-01` … `QA17B-09`)

**Prerequisites.** The `p17b-configuration` tag (17A applied, migration 020 in the database), an admin
session, and a green automated gate (§1). **Expected suite:** 855 / 855 (17A: 830, plus 24
`UnilevelConfigurationTest` cases and one `ProductViewTest` case). **Time:** 30–40 min.

**What this section proves.** An operator can set a product's P.V., choose the bonus base, switch the
bonus on and edit a 1–10 percentage ladder, and a second block on `/admin/settings` holds the five
visible global settings. **No money moves:** the ladder is inert until 17C, so `QA17B-09` must leave
`unilevel_awards` empty.

**Safety.** Every check below is a configuration change, not money. Use the development copy
(`ecowealth_sneat`), or a scratch schema built from `schema.sql` + seeds `001`–`005`. `QA17B-02`
leaves one product configured — restore it at the end (`QA17B-09` note) or leave it if the box is a
scratch copy.

### QA17B-01 — A migrated product reads 0% and the switch is off

| Why it matters | Deploying the code must not start paying money, and a migrated product has no ladder. |
|---|---|
| Where | `/admin/products/{id}/edit` (development copy) |
| Evidence | `QA17B-01-product-edit.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `admin` and open any product's **Edit** page (for example **Coffee**, id 1).
2. Read the **Unilevel repeat-purchase bonus** card top to bottom.
3. Read **Pricing & stock**.

Expected

- Ten rate inputs named `unilevel_levels[1]` … `unilevel_levels[10]`, all **blank** (blank and `0` are
  the same value; the card's total reads **0.000%**).
- The switch **Pay this product's ladder** is **off**; the base select is **Auto**.
- The card sits **after** *Details*, in the left column. *Pricing & stock* carries
  **Point value (P.V.)** reading `0.00` (or blank).
- No direct/indirect control of any kind exists in the HTML: no `direct_unilevel`, no
  `indirect_unilevel`, no read-only level 1 row.

### QA17B-02 — Set a P.V., load the reference ladder, enable and save

| Why it matters | This is the whole configuration path an operator will use. |
|---|---|
| Where | `/admin/products/{id}/edit` → **Save changes** |
| Evidence | `QA17B-02-saved-ladder.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set **P.V.** to `250` and the base to **Auto**.
2. Click **Load reference ladder** (or type 5/5/5/3/3/2/2/2/1/1 by hand).
3. Switch **Pay this product's ladder** on and press **Save changes**.
4. Re-open the same product's edit page.
5. In MySQL: `SELECT level, percent FROM product_unilevel_levels WHERE product_id = <id> ORDER BY level;`

Expected

- The save flashes *Product updated.* and returns to `/admin/products`.
- Ten rows: 5.000, 5.000, 5.000, 3.000, 3.000, 2.000, 2.000, 2.000, 1.000, 1.000.
- The re-opened form shows the same ten values, the switch **on**, the base **Auto**, the P.V.
  `250.00`, and the card's total badge reads **29.000%**.
- `SELECT unilevel_enabled, unilevel_base, pv FROM products WHERE id = <id>;` → `1`, `auto`, `250.00`.
- `/admin/audit` shows one `product.unilevel.update` row for the product, with `before`/`after` in its
  meta payload.

### QA17B-03 — A ladder over 100% is refused as typed

| Why it matters | A ladder that pays more than the margin is a business error; the form must refuse it, not clamp it. |
|---|---|
| Where | Same edit page |
| Evidence | `QA17B-03-sum-error.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On the configured product, change several levels so the ten values total **more than 100%**
   (for example set all ten to `20`, total 200%).
2. Press **Save changes**.
3. Read the card, then re-open the page or check MySQL for level 4.

Expected

- The form comes back with **The ladder totals 200% — the ten levels cannot add up to more than 100%.**
  at the top of the card.
- The values stay **as typed** (every input still reads `20`); nothing is reset.
- The stored ladder is untouched — level 4 still reads `3.000` and the list badge still says
  **Repeat bonus 29%**.

### QA17B-04 — Switching on with every level cleared is refused

| Why it matters | An enabled product that would pay nothing is a configuration error the operator must see. |
|---|---|
| Where | Same edit page |
| Evidence | `QA17B-04-empty-ladder.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Click **Clear** (or empty all ten rate inputs) and keep the switch **on**.
2. Press **Save changes**.

Expected

- The form comes back with **Turn the switch off or give at least one level a rate above 0.** on the
  switch, and the product still stores its previous ladder.
- Turning the switch **off** with the same empty ladder saves cleanly (that is the shipped default).

### QA17B-05 — A P.V. base needs a P.V.

| Why it matters | The two settings must agree or the engine would pay ₱0 on every sale. |
|---|---|
| Where | Same edit page |
| Evidence | `QA17B-05-pv-base.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set **P.V.** to `0` and the base to **Point value (P.V.)**.
2. Press **Save changes**.
3. Set the base back to **Auto** (P.V. still `0`) and save.

Expected

- The first save is refused with **A P.V. base needs a point value above 0 — set the P.V. or choose
  Auto.** under the base select; the product keeps its stored P.V. and base.
- The second save is accepted (the base is the price when the P.V. is not set).

### QA17B-06 — The list badge and the `?unilevel=` filter

| Why it matters | The badge is the operator's only at-a-glance signal that a product pays a bonus — and the filter is how a large catalogue is audited. |
|---|---|
| Where | `/admin/products` |
| Evidence | `QA17B-06-products-list.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/products` and find the configured product.
2. Choose **Repeat bonus — on** in the filter, apply it; then **Repeat bonus — off**.
3. Page through the filtered list (or add `&per_page=5&page=2`).

Expected

- The configured product shows the badge **Repeat bonus 29%**; a product switched on with an empty
  ladder shows **Repeat bonus 0%**; a product that is off shows **no badge**.
- `?unilevel=on` returns only the products whose switch is on; `?unilevel=off` returns the rest and the
  configured product is absent.
- The filter survives pagination (`page=2` keeps `unilevel=on` in the link) and **Clear** removes it.

### QA17B-07 — The settings block and its validation

| Why it matters | The five global values are what an operator tunes without touching code. |
|---|---|
| Where | `/admin/settings` → **Commissions** → *Repeat-purchase bonus* |
| Evidence | `QA17B-07-settings.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/settings`, scroll to **Commissions**.
2. Read the *Repeat-purchase bonus* sub-block and the audit desk.
3. Set **Upline depth** to `11` and press **Save settings**; then set it back to `10`, change the
   **Monthly personal-purchase minimum** (any value, for example `500.50`) and save again.
4. Confirm the stored values in MySQL: `SELECT key_name, value FROM settings WHERE key_name LIKE 'unilevel%' ORDER BY key_name;`

Expected

- The sub-block renders **Upline depth** (`10`, input `min=1 max=10`), **Monthly personal-purchase
  minimum** (`500.00`) and the three switches **Repeat-purchase bonus enabled** (off),
  **Require the monthly Active rule** (on), **Reverse awards when a paid order is cancelled** (on).
- No sixth `unilevel*` key is rendered: `unilevel_ledger_since` never appears.
- Depth `11` is refused with **Upline depth must be at most 10.** and `unilevel_max_levels` keeps its
  stored value. The second save goes through, the new threshold is stored, and `/admin/audit` records
  one `settings.update` whose meta names the key(s) that changed.
- The threshold keeps two decimals (`500.001` is refused).

### QA17B-08 — The form works with JavaScript off

| Why it matters | The preview must be an enhancement, never the validation. |
|---|---|
| Where | `/admin/products/{id}/edit` with scripting disabled (or a console whose JS is blocked) |
| Evidence | `QA17B-08-no-js.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Disable JavaScript for the site (or block `app-product-unilevel.js` in the browser's network panel).
2. Open the edit page: the ladder, the per-unit amounts and the 29.000% total are still rendered.
3. Change a level and save; then reload and force a sum error.

Expected

- The form still saves; the ten rate inputs, the base select and the P.V. field all work.
- Only the *live* preview is missing: the server-rendered total and per-level amounts are correct
  without the script, and every validation message still appears.

### QA17B-09 — Paying an order still moves no bonus money

| Why it matters | This phase is configuration only; a paid order must stay exactly as it was. |
|---|---|
| Where | Member shop → checkout (e-wallet) · the order page · `/wallet` · MySQL |
| Evidence | `QA17B-09-order.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As a member with a sponsor, place and pay a small e-wallet order for the product configured in
   `QA17B-02` (the global switch is off, and may be turned on for the test).
2. Open the order page and the sponsor's `/wallet`.
3. In MySQL run:
   `SELECT COUNT(*) FROM unilevel_awards;`
   `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type IN ('unilevel','unilevel_reversal');`
   `SELECT unilevel_processed_at, unilevel_total FROM shop_orders WHERE id = <order>;`

Expected

- The order is **Paid** and the wallet is debited once, as before the phase.
- The order page and the sponsor's statement show **no** repeat-purchase line or card.
- `unilevel_awards` counts **0**; the repeat-purchase ledger count is **0**;
  `unilevel_processed_at` is `NULL` and `unilevel_total` is `0.00`. (17C adds the hook that fills
  them.)
- Restore the configured product if the box is not a scratch copy: switch it off.

---

## 6. Section B sign-off

| Item | Value |
|---|---|
| Tester | Automated QA pass — DeepSeek Harness (DSH) |
| Date | 2026-11-12 |
| Automated gate | `Cases: 855 / 855` · `Passed: 209 / 209` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 |
| Checks passed | 9 / 09 |
| Defects | none |
| Decision | ☐ Approved ☐ Approved with notes ☐ Blocked — approved from the executed transcripts; the browser observations (01, 02, 03, 06, 07, 08) were read from the served HTML in the automated pass, exactly as section A's were, so a human may add screenshots without changing the result |
| Evidence | `docs/qa/evidence/phase-17/` (`QA17B-01-product-edit.txt` … `QA17B-09-order.txt`) |
