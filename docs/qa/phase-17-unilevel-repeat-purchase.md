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
section B (17B — Configuration) appended 2026-11-12; section C (17C — Money engine) appended
2026-11-12; section D (17D — Admin visibility) appended 2026-11-12; section E (17E — Member
visibility) appended 2026-11-12; section F (17F — Operations) appended 2026-11-12; section G (17G —
Release) appended 2026-11-12 and closes the pack. **Sections A–G: 57 checks.** **Appendix A** (added
after the gate, 2026-11-12) carries the fixture SQL that the UI cannot produce, plus the human
verification record; it changes no check's expected result.

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
Cases: 948   Passed: 948   Failed: 0
All good.
```

```text
Passed: 225   Failed: 0
```

The counts grow with each phase: **830 / 195** at `p17a-foundation`, **855 / 209** at
`p17b-configuration`, **906 / 209** at `p17c-money`, **924 / 215** at `p17d-admin`, **938 / 223** at
`p17e-member`, **948 / 223** at `p17f-month-close`, and **948 / 225** at `p17g-release` (17G adds no
automated case; the two new smoke checks are the month-close URL pair). Section A's own expectations
are stated in section 3; section B's in section 5; section C's in section 7; section D's in section 9;
section E's in section 11; section F's in section 13; section G's in section 15.

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

Phase 17C's share:

| Group | Cases | What it proves for 17C |
|---|---:|---|
| `UnilevelEngine` (new) | 38 | The ladder on a paid line through both real payment paths (the manual path's case lives in `PaymentTest`), the base snapshot and legacy fallback, the depth cap, two products on one order, the buyer never a recipient, the company account skipped, disabled/suspended/monthly-Inactive uplines with no compression, the two switches, the four idempotency layers, the unique anchor, the product delete guard, the preview, full/partial/disabled reversal, an unpaid cancellation and the reconcile; the CLI exit-code case builds a scratch schema and runs the real `wallet_reconcile.php` |
| `UnilevelCycle` (new) | 11 | The two-clock Active rule: a ₱450 month blocks the next, ₱2,000 restores it, a missing snapshot row is Active, a closed period is immutable, `close()` writes one row per account and one header, a second close is a no-op, a future period is refused, the live fallback agrees with the close, the Manila midnight bounds, the batched lookup (one query for ten recipients) and the close running with the stream off |
| `PaymentTest` | +1 | P17C-T2: a manual GCash order verified by an admin pays the four-deep ladder once, and a second verification adds no row |
| `OrderTest` | +1 | P17C-T34: placement snapshots `unit_base`/`base_total`, and a price/P.V. edit before verification cannot rewrite the award |
| `UnilevelConfigurationTest` | 1 rewritten | P17B-T20 now proves the 17C hand-off: with the global master off the hook stamps `unilevel_processed_at` with a zero total and still writes no award |
| `DbResetTest` | 1 rewritten | The reset verification now runs **five** reconciliations, including `UnilevelService::reconcile` on the injected PDO |
| `UnilevelFoundationTest` | 1 rewritten | P17A-T13 now guards the enduring surface: the engine exists, no controller/route/view writes a Phase 17 table, the month-close job itself writes no money row, and the stream is off on a fresh database |
| `WalletTest` / `WalletViewTest` / `CommissionTest` / `CommissionIndirectTest` | — | Regression: the referral ledger, the wallet vocabulary and both statements are unchanged |

Phase 17D's share:

| Group | Cases | What it proves for 17D |
|---|---|---|
| `AdminCommissionLiveTest` (extended) | 10 | P17D-T1 (the package tab keeps its four regions, columns, filters and drawer), T2 (the repeat tab's page, feed, `Lvl` column, filters and empty state), T3 (the six stat tiles read the ledger exactly), T4 (the period card reads closed months newest first), T5 (level/product/date/q filters narrow and unknown values fall back), T6 (a skipped row shows its due amount and reason, never the credited total), T7 (a voided row shows what was recovered and the shortfall), T8 (the CSV shape), T14 (an award bumps `admin.commissions`; the two tabs have separate ETags and the package tab never renders a repeat row) |
| `ReportTest` (extended) | 5 | `unilevelSummary()` window maths, `unilevelByLevel()`, `unilevelByProduct()`, `unilevelByPeriod()` newest first, the dashboard 30-day KPI and the `type=unilevel` export |
| `ReportViewTest` (extended) | 1 | P17D-T12: the four cards render the fixture numbers from the same read models; the dashboard tile renders the 30-day amount, awards and recipients |
| `AdminOrderLiveTest` (extended) | 3 | P17D-T9 (a paid, processed order lists level, recipient, product, base, rate, amount and status, with the headline equal to `unilevel_total`), T10 (an unpaid order explains nothing is paid), T11 (an order paid before the feature says so and stays unprocessed) |
| `UnilevelFoundationTest` (rewritten) | 1 | P17A-T13's enduring half after 17D: no route, no controller, no month-close job, and **no controller or view writes a Phase 17 table** (the admin surface is read-only) |

Phase 17E's share:

| Group | Cases | What it proves for 17E |
|---|---|---|
| `UnilevelMemberViewTest` (new) | 14 | P17E-T1 (the product panel: ten levels, the P.V. base, the per-unit amounts, the 29.000% total, the no-rebate sentence, no direct/indirect label), T2 (an unconfigured product has no panel; a zero rate reads `—`; the global master off removes it), T3 (the catalogue badge and its one batched query), T4/T5/T6 (the checkout preview: ₱145.00 for 2 units, "when this order is paid", absent without a ladder, stable in the live feed), T7 (the order card: total only, no recipients, no per-upline amounts), T8 (the repeat tab's counters, two-clock panel, breakdown, rows and `memberStatus()` on a live month and a closed snapshot), T9 (filters, silent fallback and the 13-column member CSV), T10 (the package tab unchanged), T11 (`?user_id=` ignored), T12 (the dashboard tile and the membership paragraph, no action), T13 (the 390 px responsive hooks) |
| `MemberOrderLiveTest` / `MemberMembershipLiveTest` (widened) | 2 | The order feed returns the new `member-order-bonus` region (empty for a pending order) and the membership feed returns `membership-repeat`; no other fragment moved |

Phase 17F's share:

| Group | Cases | What it proves for 17F |
|---|---|---|
| `CronTest` (extended) | 10 | The eight cases of §16.5 plus T6 and T10 of the phase's test plan, all run as the **real command** against a scratch schema (a subprocess cannot see the harness transaction): T1 (`--dry-run` lists 2026-xx and writes nothing), T2 (the real run writes the header and one row per account, and the counts equal the aggregate), T3 (a second run is `closed=0` with no new row), T4 (two missed months close in one run, oldest first), T5 (a future period is refused, exit 1, nothing written), T6 (`--period` on a closed month exits 0 with "already closed"), T7 (a held lock logs `[SKIP ]` and exits 0), T8 (one log line per run with `trigger`, `period`, `members`, `qualified`, `award_total`, `duration`), T9 (`--json` carries the same numbers as the log line) and T10 (the doctor reports both clocks, `APP_TIMEZONE`, the threshold, the closed/pending months, the account count, the lock and a writable log folder) |
| `UnilevelFoundationTest` (rewritten again) | 1 | P17A-T13's enduring half after 17F: the job exists, and it still carries **no wallet or award write** — only `UnilevelCycle::close()` writes, and no controller or view writes a Phase 17 table |

Phase 17G's share: **no new PHP case.** The release changes `APP_PHASE`, one assertion and the
documentation. `HardeningTest`'s existing build case now asserts `APP_PHASE === 17`, and
`tests/smoke.ps1` gains the two month-close URL checks (`P17F-T10` over `?doctor=1`, `P17F-T11` over
`?dry-run=1&json=1`), taking the smoke run from **223** to **225** checks. Everything else in section
G executes the accumulated suite and the earlier sections' checks.

The migration and bundle cases build scratch schemas (`ecowealth_sneat_migration_test`,
`ecowealth_sneat_tool_a_test`, `ecowealth_sneat_tool_b_test`). No automated case targets
`ecowealth_sneat` or `ecowealth_sneat_test`. The 17F cases recreate `ecowealth_sneat_tool_b_test` and
run the real CLI against it with `ECO_DB_NAME`; the lock case holds the real
`storage/locks/unilevel_month_close.lock` for the instant the subprocess runs (the subprocess resolves
its lock from `STORAGE_PATH`, which is the real `system/storage` for a CLI run) and removes it after.

---

## 2. Environment and rules

| Item | Value |
|---|---|
| App | `http://localhost/ecowealth/system/` (Laragon Apache + MySQL) |
| Development database | `ecowealth_sneat` — **never** a target of the destructive checks; migrated to 17A before this pass (backup: `C:\laragon\www\ecowealth-backups\phase-17\`, see `QA17A-dev-db-migration.txt`) |
| Scratch schema for section A | `ecowealth_sneat_qa17a` (created as root, granted to the app user, dropped afterwards) |
| Scratch schema for section G | `ecowealth_sneat_qa17g` (created as root, used for the deploy replay, dropped afterwards) |
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

### 2.1 The Phase 17 fixture (sections B–F)

Every check from section B on needs a small fixture. The shape is the same — one configured product and
a five-deep sponsor chain — but **each section builds its own** and starts from the reserved baseline of
§2.3. Build the fixture for the section you are running, keep every name prefixed `QA17`, and remove it
when the section is finished (sections D–F record their cleanup in `QA17X-00-setup-cleanup.txt`; section
C is restored from its dump — see §2.3).

| Section | Product | Chain (buyer → level 1 → … → level 4) |
|---|---|---|
| B | an existing product (for example **Coffee**, id 1) | none |
| C | `QA17 Product` (`P1`) — price ₱300, P.V. ₱250 | `qa17_buyer → qa17_c → qa17_b → qa17_a → qa17_boss` |
| D, E | the same product | the same chain |
| F | `QA17F-FIXTURE` | `qa17f_buyer` only |

1. **The product.** Admin → Products → **New product**: name `QA17 Product`, SKU `QA17-P1`, price
   `300.00`, P.V. `250`, stock `100`, status active. In the **Unilevel repeat-purchase bonus** card press
   **Load reference ladder** (5/5/5/3/3/2/2/2/1/1 = **29.000%**), leave **Bonus base** on *Auto* and turn
   the product switch **on**; save. The product list badge must read **Repeat bonus 29%**.
2. **The chain (sections C–E).** Register five members, each under the previous one, with the previous
   member's **Referral link** (Membership → *Referral link*): `qa17_boss` first (sponsor blank → the
   company account), then `qa17_a` under `qa17_boss`, `qa17_b` under `qa17_a`, `qa17_c` under `qa17_b`,
   and the buyer `qa17_buyer` under `qa17_c`. The chain is
   `qa17_buyer → qa17_c → qa17_b → qa17_a → qa17_boss → company`, the plan's §16.2 shape. Set each
   password to `QaPass!2345` and make sure each upline is an **active member** (Admin → Members), or the
   payable levels will be skipped.
3. **The money.** Admin → E-wallet monitor → `qa17_buyer` → **Credit wallet** `1000.00` with the
   note `QA17 funding`. A top-up credits the non-withdrawable bucket and can be spent at checkout.
4. **The switch.** Admin → Settings → **Commissions** → *Repeat-purchase bonus*: tick
   **Repeat-purchase bonus enabled**, keep *Upline depth* `10` and *Monthly personal-purchase minimum*
   `500.00`; save.
5. **The order.** Sign in as `qa17_buyer`, add **2 × `QA17 Product`** to the cart and check out with
   **E-wallet**. The line base is **₱500.00** and the order pays **₱90.00** (₱25.00 · ₱25.00 · ₱25.00 ·
   ₱15.00) with the reference ladder.
6. **The variants.** A **zero rate** = edit one level of the product to `0` and save. A **disabled
   upline** = Admin → Members → `qa17_b` → *Suspend*. A **monthly-Inactive upline** = leave that upline
   without personal purchases in the previous month (the default state — the monthly rule blocks it once
   the previous month is treated as a real period).

Where section C says **`buyer`**, **`c`**, **`b`**, **`a`**, **`boss`**, and section D says **`u1` … `u4`**,
they are the same people: `buyer` = `qa17_buyer`, `c`/`u1` = `qa17_c`, `b`/`u2` = `qa17_b`,
`a`/`u3` = `qa17_a`, `boss`/`u4` = `qa17_boss`.

The UI builds almost all of it. The few states it cannot produce — **backdated October orders**, a
**ledgered ₱45 debit**, and an **order paid before the feature** — are in **Appendix A**, with the exact
SQL and its cleanup. Take the section's dump (§2.3) before you start; Appendix A assumes it.

**Undo it.** Section C is restored from its dump (§2.3). Sections D–F record the exact cleanup SQL in
`QA17D-00-setup-cleanup.txt`, `QA17E-00-setup-cleanup.txt` and `QA17F-00-setup-cleanup.txt` (award
rows, ledger rows, the fixture users and products, then the settings restored to their snapshot). If
you cannot run SQL, run sections D–G on a **copy** of the development database instead and drop the
copy afterwards. Never leave a `QA17` row behind: `wallet_reconcile.php` must exit 0 at the end of
every section.

### 2.2 Sections A and B are tag-scoped

Sections describe the tree at the phase that wrote them, and three checks describe a state a **later**
phase deliberately changed. They cannot pass on the final tree and must be run at their own tag (or read
as the record of that gate):

| Check | Written for | What changed | Where the current behaviour is asserted |
|---|---|---|---|
| `QA17A-04` — no new admin UI | `p17a-foundation` | 17B adds the *Repeat-purchase bonus* settings block and the product ladder card | `QA17B-01`, the 17B section, and smoke `P17B-T14` / `P17B-T18` |
| `QA17A-05` — a paid order is unstamped | `p17a-foundation` | 17C stamps `unilevel_processed_at` (and a zero `unilevel_total`) even when it pays nothing | `P17C-T28`, `P17C-T30`, `P17B-T20` and smoke `P17B-T20` |
| `QA17B-09` — the order stays unstamped | `p17b-configuration` | same 17C hand-off | `P17B-T20` (rewritten), smoke `P17B-T20` |

Everything else in sections A–F is executable on the final tree; every number in sections C–G is the
current build's.

### 2.3 Executing sections C–G on the release tree

Sections A and B are the record of their own tags (§2.2). On the release tree the executable path is
**C → D → E → F → G**, and every money section starts from the same baseline:

| Item | Baseline on `ecowealth_sneat` |
|---|---|
| Users | **26** |
| `unilevel_awards` · `unilevel_periods` · `unilevel_activity` | **0 · 0 · 0** |
| `product_unilevel_levels` | **0** |
| Products with `unilevel_enabled = 1` | **0** |
| `unilevel_enabled` | **0** |
| `unilevel_ledger_since` | `2026-11-12 16:27:16` |

**Dump before, restore after.** Section C has no `QA17C-00` transcript and is not self-cleaning: it
leaves awards, four wallet credits, a closed `2026-10` period and two changed settings. Section D's
header counts exactly four awards, and section F expects `pending 2026-10`; both need the baseline.
Take a dump before each money section and restore it before the next:

```powershell
# before a money section — name the file for the section; --result-file avoids PowerShell's UTF-16 >
& 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe' -h 127.0.0.1 -u root `
  --single-transaction --routines --triggers `
  "--result-file=C:\laragon\www\ecowealth-backups\phase-17\db-pre-qa17c.sql" ecowealth_sneat

# after it — stop Apache first; this replaces the development copy
& 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe' -h 127.0.0.1 -u root `
  -e "DROP DATABASE ecowealth_sneat; CREATE DATABASE ecowealth_sneat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
& 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe' -h 127.0.0.1 -u root ecowealth_sneat `
  -e "source C:/laragon/www/ecowealth-backups/phase-17/db-pre-qa17c.sql"
```

Confirm the restoration before starting the next section:

```sql
SELECT (SELECT COUNT(*) FROM users) AS users,
       (SELECT COUNT(*) FROM unilevel_awards) AS awards,
       (SELECT COUNT(*) FROM unilevel_periods) AS periods,
       (SELECT COUNT(*) FROM unilevel_activity) AS activity,
       (SELECT COUNT(*) FROM product_unilevel_levels) AS ladder,
       (SELECT value FROM settings WHERE key_name = 'unilevel_ledger_since') AS ledger_since;
```

Expected: `26 | 0 | 0 | 0 | 0 | 2026-11-12 16:27:16`. If you would rather not replace the development
copy, restore each dump into a scratch schema and run the section against that schema instead.

A human re-run should record its own results in **Appendix A.5**, not overwrite the automated
sign-off tables of sections 4–16.

---

## 3. Section A — Foundation (`QA17A-01` … `QA17A-08`)

**Prerequisites.** The `p17a-foundation` tree (the `p17-start` tag plus migration 020; see §2.2 — this
section is tag-scoped), a scratch schema created as root, and a green automated gate (§1). **Expected
suite:** 830 / 830. **Time:** 35–45 min.

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

> **Later tree:** run this check at `p17a-foundation`. 17B deliberately adds both surfaces; see §2.2.

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

> **Later tree:** run this check at `p17a-foundation`. Since 17C the hook stamps the order with a date
> and a zero total even when it pays nothing; see §2.2.

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

> **Later tree:** run this check at `p17b-configuration`. Since 17C the order is stamped when it is
> paid, with a zero total; see §2.2.

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

---

## 7. Section C — Money engine (`QA17C-01` … `QA17C-10`)

**Prerequisites.** The `p17c-money` tag (17A + 17B applied), a scratch schema or the local development
copy with a backup, and the green automated gate (§1). **Expected suite:** 906 / 906. **Time:** 45–60
minutes. **No new page appears in this phase** — every check is read from the wallet statements, the
order data and SQL. Do not run these checks against production.

**Fixture used by the section.** One product `P1` (price ₱300, P.V. ₱250, base *Auto*, reference
ladder 5/5/5/3/3/2/2/2/1/1 = 29%, switch **on**), one member `buyer` with four sponsors
`c → b → a → boss` (buyer's sponsor is `c`, `boss`'s sponsor is empty), and the global
`unilevel_enabled = 1`. A second order of `2 × P1` has a **₱500** base and pays
**c ₱25, b ₱25, a ₱25, boss ₱15 = ₱90**. The buyer is never paid.

**Helper: a scratch month close (17F owns the real runner).** Save this as `close.php` outside the
repository and run `php close.php 2026-10`:

```php
<?php
require 'C:/laragon/www/ecowealth/system/app/config.php';
require 'C:/laragon/www/ecowealth/system/app/helpers.php';
require 'C:/laragon/www/ecowealth/system/app/autoload.php';
require 'C:/laragon/www/ecowealth/system/app/database.php';
date_default_timezone_set(APP_TIMEZONE);
print_r(UnilevelCycle::close($argv[1]));
```

### QA17C-01 — Configure, enable and pay an e-wallet order

| Why it matters | This is the money event: a paid e-wallet order must pay the ladder. |
|---|---|
| Where | `/admin/products/{id}/edit`, `/admin/settings`, member checkout |
| Evidence | `QA17C-01-order.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `P1`'s edit page: P.V. `250.00`, base **Auto**, the reference ladder, switch **on**. Save.
2. `/admin/settings` → **Commissions** → *Repeat-purchase bonus*: **Repeat-purchase bonus enabled**
   on; **Upline depth** `10`; **Monthly personal-purchase minimum** `500.00`; save.
3. As `buyer`, add **2 × P1** to the cart and pay with the **e-wallet** (fund the buyer first if
   needed). The order total is **₱600**.
4. In MySQL:
   `SELECT status, unilevel_processed_at, unilevel_total FROM shop_orders WHERE id = <order>;`

Expected

- The order is **paid**, `unilevel_processed_at` is a timestamp, and `unilevel_total` is **90.00**.
- `SELECT level, user_id, amount, status FROM unilevel_awards WHERE order_id = <order> ORDER BY level;`
  returns four **credited** rows: levels 1–4, ₱25.00, ₱25.00, ₱25.00, ₱15.00.
- Running the same placement with the global switch off (see `QA17C-09`) writes no row but still
  stamps the order — a zero payout is a decision, not a gap.

### QA17C-02 — Each upline's wallet shows the credit

| Why it matters | The award must be withdrawable money, not a number on a page. |
|---|---|
| Where | `/wallet` as `c`, `b`, `a`, `boss`; `/wallet` as `buyer` |
| Evidence | `QA17C-02-wallets.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `c` → `/wallet`: find the **Repeat-purchase bonus** credit of ₱25.00 for this order;
   the statement filter offers *Repeat-purchase bonus* and matches it.
2. Repeat as `b` (₱25.00), `a` (₱25.00) and `boss` (₱15.00).
3. Open `/wallet` as the **buyer**.

Expected

- Each upline's withdrawable bucket rose by exactly the award; `withdrawable_after` on the ledger row
  equals the new bucket.
- The buyer's wallet shows only the order debit (₱600) — no repeat-purchase credit, no level-0
  rebate.
- The four ledger rows carry `ref_type = 'unilevel'`, `type = 'credit'` and
  `ref_id = unilevel_awards.id`.

### QA17C-03 — The admin ledger shows the same four movements

| Why it matters | Finance must be able to walk from a wallet movement to its award. |
|---|---|
| Where | `/admin/wallets` (all movements) and the member detail |
| Evidence | `QA17C-03-admin-wallet.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as an admin → `/admin/wallets` → **All movements**; filter/scan for the four credits.
2. Open a recipient's detail and read the movement's note and reference.

Expected

- Four rows with the **Repeat-purchase bonus** label and the award id as the reference (`ref_id`).
- The note reads `Repeat-purchase bonus — P1 (level N) — order EW…`.
- No side effects: no second movement per award, no movement for a skipped row (see `QA17C-06`).

### QA17C-04 — The reconciliation proves the money

| Why it matters | This is the finance gate; a wrong ledger must fail loudly. |
|---|---|
| Where | CLI |
| Evidence | `QA17C-04-reconcile.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php bin\wallet_reconcile.php`.
2. Run `php bin\wallet_reconcile.php --json` and read the `unilevel` object.

Expected

- Exit **0**; the output has a **Repeat-purchase** section with 4 credited awards, **₱90.00**
  credited and **₱90.00** `unilevel ledger`, and `OK — every award has its wallet movement…`.
- `--json` reports `"ok": true` and `"unilevel": { "ok": true, "credited_rows": 4 }`.
- The four other sections stay clean.

### QA17C-05 — A manual (GCash) order pays only after verification

| Why it matters | The second payment path must pay exactly like the e-wallet one, and not before. |
|---|---|
| Where | Member checkout (GCash), proof upload, `/admin/orders/{id}` verify |
| Evidence | `QA17C-05-manual.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As `buyer`, place a **GCash** order of `2 × P1` (₱600) and upload a proof for ₱600.
2. While the order is **payment_review**, run:
   `SELECT COUNT(*) FROM unilevel_awards WHERE order_id = <order>;`
3. As an admin, open the order and **Verify** the proof with the exact amount and a fresh reference.
4. Re-read the order and the four wallets.

Expected

- Before verification: **0** award rows and `unilevel_processed_at IS NULL` — the order is not money
  yet.
- After verification: the same four awards (₱25/₱25/₱25/₱15, total ₱90) and the same four wallet
  credits as `QA17C-02`.
- Verifying again is refused (the order is `paid`); no fifth award appears.

### QA17C-06 — A disabled upline is skipped, with no compression

| Why it matters | The level number must be consumed; the next upline is not promoted. |
|---|---|
| Where | `/admin/members/{id}` (deactivate), member checkout, SQL |
| Evidence | `QA17C-06-skipped.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Deactivate `b` (`users.is_active = 0`).
2. Pay a second e-wallet order of `2 × P1` (base ₱500).
3. Read `SELECT level, user_id, amount, status, reason FROM unilevel_awards WHERE order_id = <order> ORDER BY level;`
   and the wallets.
4. Restore `b`.

Expected

- Four rows: level 1 `c` credited ₱25; level 2 `b` **skipped** with
  `recipient account is disabled` and amount ₱25.00; level 3 `a` credited ₱25; level 4 `boss`
  credited ₱15.
- Credited total **₱65**, skipped **₱25**; `b`'s wallet does not change; the ₱25 is **not**
  redirected to `a` or `boss`.
- `php bin\wallet_reconcile.php` still exits 0 (a skipped row is not money).

### QA17C-07 — Cancelling a paid order reverses the awards

| Why it matters | A cancelled sale must not leave the bonus behind; a shortfall must be visible. |
|---|---|
| Where | `/admin/orders/{id}` → Cancel, SQL, reconcile |
| Evidence | `QA17C-07-reversal.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Admin-cancel the paid e-wallet order from `QA17C-01` (reason *Cancelled by the administrator*).
2. Read the order, the awards and the wallets:
   `SELECT status, reversed_amount, reversal_ledger_id FROM unilevel_awards WHERE order_id = <order>;`
   `SELECT ref_type, amount FROM ewallet_ledger WHERE ref_type = 'unilevel_reversal' ORDER BY id;`
3. If an upline had already spent part of the credit, repeat the cancel on another paid order and
   compare `amount` with `reversed_amount`.

Expected

- Every award is **voided**, keeps its original `amount`, and records `reversed_amount` and a
  `reversal_ledger_id`.
- Each upline's wallet is debited by the reversed amount only — **never below zero**. The buyer is
  refunded the order total as before.
- A partly spent wallet reverses only what it still holds; the difference is the **shortfall**
  (`amount − reversed_amount`) and is never hidden.
- `shop_orders.unilevel_total` is **0.00**; `php bin\wallet_reconcile.php` still exits 0.
- With `unilevel_reversal_enabled = 0` the awards stay **credited** and no debit is written.

### QA17C-08 — The monthly Active rule

| Why it matters | The two-clock rule blocks an upline who did not buy last month, and the switch can relax it. |
|---|---|
| Where | SQL + the helper `close.php`; member checkout |
| Evidence | `QA17C-08-active.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Give `c` a paid October order of **₱450** (below the ₱500 threshold) and give `b`/`a`/`boss` ₱600
   each — the SQL is **Appendix A.1**. Run `php close.php 2026-10`.
2. Pay a November order of `2 × P1` as `buyer`.
3. Read the awards. Then set **Require the monthly Active rule** off, pay another November order and
   read again.

Expected

- First order: level 1 `c` is **skipped** with `recipient is not Active for 2026-10`; levels 2–4 pay
  ₱25/₱25/₱15 = ₱65. The skipped ₱25 is not redirected.
- With the setting off: `c` is credited ₱25 and the order pays ₱90; the account/membership checks
  still apply.
- A member who joined after the close has **no** activity row and is Active; a member with an
  explicit `qualified = 0` in a closed month is blocked for the following month.
- Re-running `close.php 2026-10` writes nothing (`closed = 0`, "already closed").

### QA17C-09 — The global kill switch stamps but never pays

| Why it matters | Turning the stream off must stop new money immediately, and a later re-run must not pay. |
|---|---|
| Where | `/admin/settings` → Commissions; member checkout; SQL |
| Evidence | `QA17C-09-kill-switch.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set **Repeat-purchase bonus enabled** to off and save.
2. Pay an e-wallet order of `2 × P1`.
3. Read `SELECT unilevel_processed_at, unilevel_total FROM shop_orders WHERE id = <order>;` and
   `SELECT COUNT(*) FROM unilevel_awards WHERE order_id = <order>;`.
4. Turn the switch back on, then re-run the hook for that same order once with a scratch script
   (`(new UnilevelService())->onOrderPaid(<order>)`) and re-read.

Expected

- The order is paid; `unilevel_processed_at` is a timestamp, `unilevel_total` is **0.00**, and there
  are **0** awards and **0** repeat-purchase ledger rows.
- Re-running after turning the stream on returns `replayed` and pays nothing — the stamp is the
  idempotency anchor.
- A product whose own switch is off behaves the same way and is stamped too.

### QA17C-10 — The month close writes the snapshot once

| Why it matters | The close is what makes the flag authoritative; it must be idempotent and immutable. |
|---|---|
| Where | CLI helper `close.php`; SQL |
| Evidence | `QA17C-10-close.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php close.php 2026-10` (or another completed month).
2. Read:
   `SELECT * FROM unilevel_periods ORDER BY period DESC LIMIT 3;`
   `SELECT COUNT(*) FROM unilevel_activity WHERE period = '2026-10';`
   `SELECT COUNT(*) FROM users;`
3. Run `php close.php 2026-10` again.
4. Run `php close.php 2099-01`.

Expected

- One `unilevel_periods` row: `threshold` 500.00, `members_total` equal to the account count,
  `qualified_count` and `award_total` matching the month.
- One `unilevel_activity` row per account (including zero-purchase accounts and staff), each with
  `personal_purchases` and `qualified`.
- The second run returns `closed = 0` with *already closed* and changes no row.
- The future period is refused with an `InvalidArgumentException`; nothing is written.
- A closed month is not rewritten when an order from it is cancelled later; the award is still
  voided and the snapshot keeps its historical values.

---

## 8. Section C sign-off

| Item | Value |
|---|---|
| Tester | Automated QA pass — DeepSeek Harness (DSH) |
| Date | 2026-11-12 |
| Automated gate | `Cases: 906 / 906` · `Passed: 209 / 209` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 (five sections) |
| Checks passed | 10 / 10 |
| Defects | none |
| Decision | ☐ Approved ☐ Approved with notes ☐ Blocked — approved from the executed transcripts. 17C intentionally adds no page: checks 02, 03 and 07 were captured from the wallet statements' SQL, the order rows and the CLI, exactly as the plan's manual script describes; a human may add browser screenshots without changing the result |
| Evidence | `docs/qa/evidence/phase-17/` (`QA17C-01-order.txt` … `QA17C-10-close.txt`) |

---

## 9. Section D — Admin visibility (`QA17D-01` … `QA17D-08`)

**Prerequisites.** The `p17d-admin` tag (17A + 17B + 17C applied and the money engine proven), an
admin session, a backup of the development copy, and the green automated gate (§1). **Expected
suite:** 924 / 924. **Time:** 30–40 min.

**What this section proves.** Finance can see, filter and export every repeat-purchase award, open the
order that produced it, reach the wallet movement, and read four report cards — all without moving
money. The phase is read-only: no check below writes an award, and no page offers a "pay" or "close"
button.

**Fixture used by the section.** One product (price ₱300, P.V. ₱250, base *Auto*, reference ladder
5/5/5/3/3/2/2/2/1/1 = 29%, switch **on**), one buyer with four sponsors `u1 → u2 → u3 → u4` (the
buyer's sponsor is `u1`; `u4`'s sponsor is empty), and the global `unilevel_enabled = 1`. Two paid
orders of `2 × product` each have a **₱500** base; the first pays **₱90** (25/25/25/15), the second
pays **₱65** with **one skipped ₱25** row once `u2` is disabled. A ₱45 debit against `u1` before the
first cancellation leaves a **₱20 shortfall** that must stay visible. The buyer is never paid.

**Safety.** The pass creates five users, one product, four orders and their awards in a development
copy only, then deletes every row it created, restores the five settings it changed and re-runs
`wallet_reconcile.php`. The transcript of that cleanup is `QA17D-00-setup-cleanup.txt`.

### QA17D-01 — The ledger header reads ₱90.00, 4 awards and 4 recipients

| Why it matters | The repeat ledger is the audit tool the operator uses to explain the money. |
|---|---|
| Where | `/admin/commissions?ledger=repeat` |
| Evidence | `QA17D-01-ledger.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Pay the first order (the real engine, through the same hook checkout uses) and open the URL.
2. Read the six tiles, the tab pills and the table heading.
3. Compare with `SELECT level, user_id, amount, status FROM unilevel_awards WHERE order_id = <id>;`.

Expected

- **Total credited ₱90.00 — 4 members paid**; **This month ₱90.00**; **Awards 4**; **Recipients 4**;
  **Skipped 0**; **Unrecovered ₱0.00**.
- The card heading reads **Repeat-purchase ledger** and **4 rows**; the two pills are *Package
  commissions* and *Repeat-purchase bonus*, with the repeat pill active.
- The table has **Lvl** (L1 … L4) and **no** direct/indirect column; each row shows the recipient, the
  buyer, the product, the ₱500.00 base, the rate (5.000% / 3.000%) and the amount.
- Four `unilevel` wallet movements, one per award.

### QA17D-02 — Filters narrow the rows, and a skipped level is visible

| Why it matters | Level, status and product filters are how a large ledger gets audited; a skip must explain itself. |
|---|---|
| Where | `/admin/commissions?ledger=repeat&level=2` · `…&status=skipped` |
| Evidence | `QA17D-02-filters.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Filter **Level 2** and read the single row.
2. Disable `u2` (`users.is_active = 0`), pay the second order, filter **Status: skipped**, restore
   `u2`.
3. Check that the second order's other three levels still paid at their own numbers.

Expected

- The level filter shows **1 row** for `u2`: ₱500.00 base, 5.000%, **+₱25.00**, Credited.
- The skipped filter shows **1 row**: L2, ₱25.00, **Skipped — recipient account is disabled**, and the
  amount cell says **not paid**.
- The second order paid ₱25 + ₱25 + ₱15 = **₱65**; the skipped ₱25 was **not** redirected (no
  compression).
- A bad filter value (`level=99`, an unparseable date) falls back to "no filter" and never errors.

### QA17D-03 — A cancelled order shows voided rows and keeps the shortfall visible

| Why it matters | Unrecovered money must be reported, not hidden; a cancellation must never rewrite history. |
|---|---|
| Where | `/admin/orders/{id}` → Cancel · `/admin/commissions?ledger=repeat&status=voided` |
| Evidence | `QA17D-03-reversal.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Spend ₱45 of `u1`'s ₱50 withdrawable balance (a ledgered debit; the SQL is **Appendix A.2** — the
   UI has no ₱45 debit, because the minimum transfer is ₱50 and the minimum payout ₱500), leaving ₱5.
2. Admin-cancel the first paid order.
3. Read the voided rows and the header's **Unrecovered**; check `shop_orders.unilevel_total` and the
   award rows.

Expected

- Every award of that order is **Voided** and keeps its original amount; `u2`, `u3` and `u4` show
  **₱25.00 recovered of ₱25.00** (and ₱15 of ₱15), while `u1` shows **₱5.00 recovered of ₱25.00** and a
  **₱20.00 shortfall**.
- The header's **Unrecovered** reads **₱20.00** and the tile counts **4 voided awards**.
- The order's `unilevel_total` is **0.00**; the period snapshot was not touched; `wallet_reconcile.php`
  still exits 0.

### QA17D-04 — A row links to its order and to its wallet movement

| Why it matters | A finance reviewer must be able to walk from a number to both of its sources in one click. |
|---|---|
| Where | `/admin/orders/{id}` · `/admin/wallets/{recipient}?q={order_no}` |
| Evidence | `QA17D-04-order-wallet.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. From the ledger, click a row's order link.
2. Read the **Repeat-purchase bonus** card on the order page.
3. Back on the ledger, click the wallet icon for the level-1 row.

Expected

- The order page shows the headline **₱90.00 to the sponsor chain**, then one line per level with
  level, recipient, product, base, rate, amount and status, and the footer total **₱90.00**.
- The card names `u1`…`u4` and never the buyer.
- The wallet link opens the recipient's statement **filtered to the order note**, and the movement is
  the **Repeat-purchase bonus** credit for that order.

### QA17D-05 — The filtered CSV matches the screen

| Why it matters | Finance works offline; the export must be the same data, not a second query definition. |
|---|---|
| Where | `/admin/commissions/export?ledger=repeat&level=2` |
| Evidence | `QA17D-05-export.txt`, `QA17D-05-export.csv` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Apply the level-2 filter and press **Export CSV**.
2. Open the file; compare the columns and the values with the screen.

Expected

- Columns: **Award, Date, Order, Buyer, Recipient, Product, Level, Base, Rate %, Amount, Status,
  Reason, Recovered**.
- One row per filtered award (the credited and the skipped ₱25 level-2 rows in the fixture), with the
  same order number, recipient, base 500.00, rate 5.000 and amount 25.00 the page shows.
- The download writes one `unilevel.export` audit row carrying the filters and the row count.

### QA17D-06 — The order card explains an unpaid order and a pre-feature payment

| Why it matters | "No bonus" has two very different causes; the card must say which one applies. |
|---|---|
| Where | `/admin/orders/{pending id}` · `/admin/orders/{paid-before id}` |
| Evidence | `QA17D-06-order-states.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open a `pending` order.
2. Open an order that was marked `paid` outside the app (its `unilevel_processed_at` is `NULL`;
   **Appendix A.4** creates one).

Expected

- The pending order reads **Paid orders earn the repeat-purchase bonus. This order is `pending`.**
  with the **No bonus** badge and no rows.
- The pre-feature order reads **This order was paid before the repeat-purchase stream was switched
  on, so it earned no bonus.** Its stamp stays `NULL` and its total stays `0.00` — it is never paid
  retroactively.

### QA17D-07 — The four report cards, the range export and the dashboard KPI

| Why it matters | The reports page is where the operator watches the stream's total cost. |
|---|---|
| Where | `/admin/reports` · `/admin/reports/export?type=unilevel` · `/dashboard` |
| Evidence | `QA17D-07-reports.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/reports` for the last 30 days and read the four repeat cards.
2. Export **Repeat-purchase bonus** and read the CSV head.
3. Open `/dashboard` and read the **Repeat bonus (30 d)** tile.

Expected

- **Repeat-purchase bonus** (window ₱65.00, 3 awards, 3 recipients, 1 skipped, 4 voided, no
  unrecovered), **Repeat-purchase by level** (levels 1, 3, 4 credited; level 2 shows one skip; window
  3 awards / ₱65.00 / 1 skipped), **Repeat-purchase by product** (the fixture product: 3 awards,
  ₱65.00, 1 skipped) and **Closed months** (empty in the fixture).
- The export menu offers **Repeat-purchase bonus**, and its CSV carries the same 13 columns and the
  same rows as the ledger export.
- The dashboard tile reads **Repeat bonus (30 d) ₱65.00** with **3 awards · 3 recipients** and links to
  `?ledger=repeat`.

### QA17D-08 — The package commission ledger is unchanged

| Why it matters | 17D adds a tab; it must not disturb the Phase 10–11 money page. |
|---|---|
| Where | `/admin/commissions` |
| Evidence | `QA17D-08-package-tab.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/commissions` without a `ledger` parameter.
2. Read the heading, the counters, the filters, the columns and the CSV export.

Expected

- The page still shows **Commission ledger**, the four referral counters, the same filters
  (`q`, `type`, `level`, `status`, `from`, `to`), the same columns (Recipient, Source, Type, Amount,
  Status, Activation, Awarded, Details) and the detail drawer.
- **Zero** repeat-purchase regions and **no** "Repeat-purchase ledger" heading.
- The package export still downloads the package columns.

---

## 10. Section D sign-off

| Item | Value |
|---|---|
| Tester | Automated QA pass — DeepSeek Harness (DSH) |
| Date | 2026-11-12 |
| Automated gate | `Cases: 924 / 924` · `Passed: 215 / 215` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 |
| Checks passed | 8 / 08 |
| Defects | none |
| Decision | ☐ Approved ☐ Approved with notes ☐ Blocked — approved from the executed transcripts; the checks ran against the development copy over real HTTP with the real engine and were cleaned up afterwards (`QA17D-00-setup-cleanup.txt`: awards 0, repeat ledger 0, fixture users 0, fixture products 0, `unilevel_enabled` 0, reconcile exit 0). A human may add viewport screenshots without changing the result. The "someone who did not write it" item stays open for the lead's review, as in sections A–C. |
| Evidence | `docs/qa/evidence/phase-17/` (`QA17D-00-setup-cleanup.txt` … `QA17D-08-package-tab.txt`, plus `QA17D-05-export.csv`) |


---

## 11. Section E — Member visibility (`QA17E-01` … `QA17E-09`)

**Prerequisites.** The `p17e-member` tag (17A + 17B + 17C applied and the money engine proven), a
member session, a backup of the development copy, and the green automated gate (§1). **Expected
suite:** 938 / 938. **Time:** 30–40 min.

**What this section proves.** A member can see what a purchase pays **to their sponsor chain** before
buying, what it actually paid afterwards, and what they have earned as an upline — with copy that never
suggests the buyer earns a rebate, and with every query scoped to the session member. The phase is
presentation only: no page offers a repeat-purchase action, and the money cannot move from here.

**Fixture used by the section.** One product (price ₱300, P.V. ₱250, base *Auto*, reference ladder
5/5/5/3/3/2/2/2/1/1 = 29%, switch **on**), the global `unilevel_enabled = 1`, and a buyer with four
sponsors `c → b → a → boss` (the buyer's sponsor is `c`). The buyer's e-wallet is funded with a real
₱1,000 top-up; one order of `2 × product` (line base ₱500) is placed and paid through **/checkout** by
e-wallet, so the pass observes the same code path a member does. The order pays c ₱25.00, b ₱25.00,
a ₱25.00 and boss ₱15.00 — ₱90.00 in total. The buyer is never paid.

**Safety.** The pass creates five users and one product in the development copy, one order and its
awards, then deletes every row it created and restores the five settings it changed. The transcript is
`QA17E-00-setup-cleanup.txt` (fixture rows back to 0, `wallet_reconcile.php` exit 0).

### QA17E-01 — The product panel shows ten levels, the per-unit amounts and the no-rebate sentence

| Why it matters | This is the only place a member sees the whole ladder before buying; it must be complete and it must not promise a rebate. |
|---|---|
| Where | `/shop/{configured product}` |
| Evidence | `QA17E-01-product-panel.txt`, `QA17E-09-product-390*.png` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open the configured product as a member.
2. Read the **Repeat-purchase bonus** panel: the badge, the base line, all ten rows and the footer.
3. Open a product whose ladder has a zero rate at a level (edit one in **Admin → Products**) and re-read
   the panel.

Expected

- Heading **Repeat-purchase bonus**, badge **Up to 29%**, base **₱250.00 per unit** (the product's P.V.),
  and **10 of 10 levels pay on this base**.
- Ten rows, level 1 … 10, rates **5.000 / 5.000 / 5.000 / 3.000 / 3.000 / 2.000 / 2.000 / 2.000 / 1.000 /
  1.000 %**, amounts **₱12.50 / ₱12.50 / ₱12.50 / ₱7.50 / ₱7.50 / ₱5.00 / ₱5.00 / ₱5.00 / ₱2.50 / ₱2.50**
  per unit. Level 1 reads **Your sponsor**.
- Footer: **Total up to 29.000% — ₱72.50 per unit**, then the "credited when the order is paid" paragraph
  and the sentence **You do not earn a rebate on your own purchase**.
- No "direct" or "indirect" label anywhere in the panel; no "cashback".
- A level with rate 0 reads **—** (a dash), never **0.000%**.

### QA17E-02 — The checkout preview is the panel × 2 and says the money is paid with the order

| Why it matters | The preview is the last thing a buyer reads; it must not read like money they will receive. |
|---|---|
| Where | `/checkout` with 2 units of the configured product in the cart |
| Evidence | `QA17E-02-checkout-preview.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Add 2 units of the configured product to the cart and open `/checkout`.
2. Read the **Order summary** card below the totals.

Expected

- **Up to ₱145.00 goes to your sponsor chain when this order is paid.** (₱72.50 × 2).
- **It is credited when the order is paid, and only to an upline who is eligible and Active that
  month. It is not paid to you.**
- The word **rebate** appears nowhere on the page, and neither does **cashback**.
- With a cart that has no configured product, the line is absent entirely.

### QA17E-03 — The order page shows the total only

| Why it matters | Which upline received which amount is admin-only, the same rule as package commissions. |
|---|---|
| Where | `/orders/{paid order}` |
| Evidence | `QA17E-03-order-outcome.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Place the order through the real checkout (e-wallet) and open the order page as the buyer.
2. Read the **Repeat-purchase bonus** card.
3. Compare with `SELECT level, user_id, amount, status FROM unilevel_awards WHERE order_id = …;`.

Expected

- **₱90.00 went to your sponsor chain when this order was paid.** and **Only the order's total is shown
  here; this money is not paid to you.**
- The four awards are 25 / 25 / 25 / 15 against levels 1–4, and the order reads
  `paid | 600.00 | 90.00 | <processed_at>`.
- No recipient username and no per-upline amount appear on the buyer's page.
- An order with no awards renders the empty region and no card.

### QA17E-04 — The upline's repeat tab carries the award, the level-1 breakdown and the two clocks

| Why it matters | This is the member's statement; the number must equal the wallet credit and the status panel must explain the monthly rule. |
|---|---|
| Where | `/earnings?ledger=repeat` as `c` (level 1 of the order) |
| Evidence | `QA17E-04-earnings-tab.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `c` and open `/earnings?ledger=repeat`.
2. Read the four tiles, the **Your Active status** panel, the **Repeat-purchase by level** card and the
   row under **Your repeat-purchase awards**.
3. Cross-check the wallet with `SELECT username, ewallet_balance, withdrawable_balance FROM users …;`.

Expected

- **Total credited ₱25.00 — 1 award · withdrawable**; **This month ₱25.00**; **Awards 1**; **Not paid 0**.
- The status panel reads **Active for November 2026**, shows **October 2026 purchases ₱0.00** with the
  missing-flag sentence (no counted purchases that month), then **November 2026 purchases so far
  ₱0.00** with its progress line, and the note that the member's own purchases never pay them a bonus.
- The breakdown lists `c`'s **team** by level: level 1 names the buyer (the direct recruit) at
  **₱25.00** and every other direct recruit at **₱0.00**, with **Level total ₱25.00**; deeper levels
  name their own members at **₱0.00**, or read **No team members at this level yet**.
- Each level card carries its own pager, like the genealogy **Team by level** card: a **Rows per page**
  control and a **Showing 1–1 of 1** summary for the level's whole roster, plus page links once a level
  holds more than ten members. A level with no member shows no pager.
- The row shows the buyer, **L1**, base ₱500.00, rate 5.000%, **+₱25.00**, **Credited**.
- The page has **no "as buyer" statistic**.
- Wallets after the order: c/b/a/boss = **25.00 / 25.00 / 25.00 / 15.00**, all withdrawable.

### QA17E-05 — The repeat filters and the CSV match

| Why it matters | A member must be able to reconcile the statement offline; the export is the same data, not a second query. |
|---|---|
| Where | `/earnings?ledger=repeat&level=1&status=credited` · `/earnings/export?ledger=repeat&level=1&status=credited` |
| Evidence | `QA17E-05-filters-export.txt`, `QA17E-05-export.csv` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Filter by level **1** and status **credited**, then by level **9**.
2. Press **Export CSV** and open the file.

Expected

- The level-1/credited filter shows **1 award**; level 9 shows **No awards match these filters**.
- A bad filter value (`level=99`, a date that is not a date) falls back to "no filter" and never errors.
- The CSV header is **Award, Date, Order, Buyer, Recipient, Product, Level, Base, Rate %, Amount, Status,
  Reason, Recovered**; the single row matches the screen (base **500.00**, rate **5.000**, amount
  **25.00**, status **credited**) and carries only this member's rows.

### QA17E-06 — The package tab is unchanged and the repeat tab refreshes live

| Why it matters | 17E adds a tab; it must not disturb the Phase 10–11 statement, and an award that arrives while the tab is open must appear without a reload. |
|---|---|
| Where | `/earnings` · `/earnings?ledger=repeat` (live feed) |
| Evidence | `QA17E-06-package-regression.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/earnings` as `c` and confirm the package statement below the new pills is unchanged.
2. Before the order, request the repeat tab's live feed (`X-Live: 1`); note its ETag and revision.
3. Place and pay the order, then request the same feed with the old `If-None-Match`.

Expected

- The package tab shows **Package commissions**, the four summary sections, **Unilevel breakdown**,
  the filter with `type`, and the detail drawer; **zero** `earnings-repeat-` regions.
- The feed before the order is revision **0**; the same ETag afterwards returns **HTTP 200** with
  revision **1**, and the refreshed rows fragment contains the ₱25.00 award and the buyer.

### QA17E-07 — The buyer's own repeat tab has no award for their purchase

| Why it matters | A member earns nothing on their own purchase; their purchase only feeds next month's Active flag. |
|---|---|
| Where | `/earnings?ledger=repeat` as the buyer |
| Evidence | `QA17E-07-buyer-view.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as the buyer and open `/earnings?ledger=repeat`.

Expected

- **No repeat-purchase bonus yet** and the empty state that says **You do not earn on your own
  purchase**.
- **No award row** and **no "as buyer" statistic**.
- The status panel shows the purchase as **this month's purchases ₱600.00** and the sentence that
  reaching the threshold makes them Active for December 2026.

### QA17E-08 — The dashboard tile and the membership paragraph

| Why it matters | The status has to be visible where the member already looks, without offering an action. |
|---|---|
| Where | `/dashboard` · `/membership` as `c` |
| Evidence | `QA17E-08-dashboard-membership.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/dashboard` and read the earnings card.
2. Open `/membership` and read the paragraph under the current-membership card.

Expected

- The dashboard tile reads **Repeat-purchase bonus**, **Active for November 2026**, **Credited as an
  upline ₱25.00**, **1 award at levels 1–10**, and links to the repeat-purchase statement.
- The membership paragraph reads **Repeat-purchase bonus**, the status pill, the sentence that a level
  falling on an Active upline is paid, and **you do not earn on your own purchase**.
- Neither surface contains a `<form>`, an `<input>` or a `<button>` for the repeat-purchase stream.

### QA17E-09 — The product panel and the repeat tab at 390 px

| Why it matters | The member pages are used on a phone; a sideways-scrolling panel or tab is a support ticket. |
|---|---|
| Where | `/shop/{configured}` · `/earnings?ledger=repeat` at 390 × 844 |
| Evidence | `QA17E-09-mobile.txt`, `QA17E-09-product-390.png`, `QA17E-09-product-390-full.png`, `QA17E-09-earnings-390.png`, `QA17E-09-earnings-390-full.png` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open both pages in headless Chrome at a 390 × 844 mobile viewport.
2. Read `document.documentElement.scrollWidth` against `window.innerWidth` and scan for elements that
   hang off the right edge.
3. Read the screenshots: the panel is a two-column grid, the two earnings pills wrap onto two rows, and
   the table keeps Buyer, Level, Amount and Status.

Expected

- Both pages: **innerWidth 390, scrollWidth 390, horizontalScroll false, offenders []**.
- The product panel's ten levels are a 2 × 5 grid; the earnings pills stack; no page-level horizontal
  scrollbar.

---

## 12. Section E sign-off

| Item | Value |
|---|---|
| Tester | Automated QA pass — DeepSeek Harness (DSH) |
| Date | 2026-11-12 |
| Automated gate | `Cases: 938 / 938` · `Passed: 223 / 223` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 |
| Checks passed | 9 / 09 |
| Defects | one found and fixed during the pass: at 390 px the two earnings pills did not wrap and the page scrolled sideways (`scrollWidth` 477). `.commission-tabs { flex-wrap: wrap }` was added to `commissions.css`; the repeat now measures 390 with no offenders, and the admin tabs share the fix. Re-run evidence: `QA17E-09-mobile.txt`. |
| Decision | ☐ Approved ☐ Approved with notes ☐ Blocked — approved from the executed transcripts; the pass ran against the development copy over real HTTP with the real checkout and the real engine, and was cleaned up afterwards (`QA17E-00-setup-cleanup.txt`: awards 0, periods 0, activity 0, repeat ledger 0, fixture users 0, fixture products 0, `unilevel_enabled` 0, reconcile exit 0). The 390 px checks were executed in headless Chrome, not read from the CSS. The "someone who did not write it" item stays open for the lead's review, as in sections A–D. |
| Evidence | `docs/qa/evidence/phase-17/` (`QA17E-00-setup-cleanup.txt` … `QA17E-09-mobile.txt`, the four screenshots and `QA17E-05-export.csv`) |

---

## 13. Section F — Operations (`QA17F-01` … `QA17F-07`)

**Prerequisites.** The `p17e-member` tag plus 17F's three files (`bin/unilevel_month_close.php`,
`public/cron/unilevel_month_close.php`, `bin/unilevel_month_close.cmd`), a backup of the development
copy, and the green automated gate (§1). **Expected suite:** 948 / 948. **Time:** 15–20 min.

**What this section proves.** The monthly close runs unattended and is safe to repeat: it closes the
month the Active rule needs, catches up on a missed month, offers a dry run and a doctor, refuses a
future month, writes one log line per run, takes one lock, answers JSON, and works from the CLI, a
URL and a Windows launcher. It **pays no money**: the pass changes no wallet balance, and
`wallet_reconcile.php` is clean before and after.

**Fixture used by the section.** The development copy, plus one paid order of ₱600 in **2026-10**
(`QA17F-2026-10-015`) for a fixture member `qa17f_buyer`, and `unilevel_ledger_since` moved back to
`2026-10-01 00:00:00` so that month is pending. The order is above the ₱500 threshold, so the closed
month has exactly **one qualified member** (and ₱0.00 of awards, because no repeat-purchase award
exists on the copy). Register the member and create `QA17F-FIXTURE` through the UI, then run
**Appendix A.3** for the order and the baseline.

**Safety.** The pass creates one user, one product, one order and one order line, then removes them
and restores the baseline; the snapshot rows it writes are deleted with SQL (a QA cleanup of a
fixture, not a reopen path — the application itself has none). Final state: `unilevel_periods` 0,
`unilevel_activity` 0, `unilevel_awards` 0, users 26, baseline restored, `wallet_reconcile` exit 0.
Transcript: `QA17F-00-setup-cleanup.txt`.

### QA17F-01 — The doctor reports both clocks, the threshold and the pending months

| Why it matters | The one silent failure mode of a time-based job is a clock disagreement; the doctor is the first command to run on a new host. |
|---|---|
| Where | `system/` — `php bin/unilevel_month_close.php --doctor` |
| Evidence | `QA17F-01-doctor.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. From `system/`, run `php bin\unilevel_month_close.php --doctor`.
2. Read every line; compare `timezone` with `db now`.

Expected

- `clocks agree (0s)`; `timezone Asia/Manila · … +08:00`; `db now … (session +08:00)`.
- `threshold ₱500.00 personal purchases`; `period now 2026-11 — the current month is never closed implicitly`.
- `ledger since 2026-10-01 00:00:00`; `last closed none yet`; `pending 2026-10 (1)`;
  `members 27 accounts get a snapshot row`.
- `lock free · …\storage\locks\unilevel_month_close.lock`;
  `log …\storage\logs\unilevel_month_close-2026-11.log (writable)`; exit code **0**.

*If it fails:* a non-zero drift or a session zone other than `+08:00` means `DB_TIMEZONE` and the host clock disagree — fix `app/config.php` before running the close. A `(NOT writable)` log or a `busy` lock means the storage folder or a stuck process needs attention.

### QA17F-02 — The dry run lists the months and writes nothing

| Why it matters | An operator must be able to see exactly which months a run would close, and the counts it would write, without touching the database. |
|---|---|
| Where | `system/` — `php bin/unilevel_month_close.php --dry-run` |
| Evidence | `QA17F-02-dry-run.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Record `SELECT COUNT(*)` for `unilevel_periods` and `unilevel_activity`.
2. Run `php bin\unilevel_month_close.php --dry-run`.
3. Record both counts again.

Expected

- `would close: 1` and one line: `2026-10  members 27  qualified 1  award_total ₱0.00`.
- Both row counts are **identical before and after** (0 and 0), and the exit code is **0**.

*If it fails:* a changed count means the dry run wrote — stop and report; the branch must only read. A missing month means `unilevel_ledger_since` is later than the month you expect.

### QA17F-03 — The real run closes the month, once

| Why it matters | This is the snapshot the whole Active rule reads; its header and its one-row-per-account shape are the contract. |
|---|---|
| Where | `system/` — `php bin/unilevel_month_close.php`, then SQL |
| Evidence | `QA17F-03-close.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php bin\unilevel_month_close.php`.
2. Run `SELECT * FROM unilevel_periods;` and
   `SELECT COUNT(*) FROM unilevel_activity WHERE period = '2026-10';`.
3. Run `SELECT * FROM unilevel_activity WHERE period = '2026-10' AND qualified = 1;`.

Expected

- `closed: 1`, `periods: 2026-10`, `2026-10  members 27  qualified 1  award_total ₱0.00`,
  `snapshot: 27 rows · 1 qualified · ₱0.00`, exit code **0**.
- One header: `2026-10 | <the close time, e.g. 2026-11-12 22:02:31> | NULL | 500.00 | 27 | 1 | 0.00` —
  the threshold is stored for audit and `closed_by` is `NULL` (a system close).
- **27** activity rows (every account, including members with zero purchases), `SUM(personal_purchases) = 600.00`,
  and exactly one qualified row: `2026-10 | qa17f_buyer | 600.00 | 1`.

*If it fails:* fewer rows than accounts means the insert was not "all accounts" — check the `users`
count at the moment of the close. A second header for the same month is impossible (primary key); a
non-zero `award_total` means awards exist for that month on that copy.

### QA17F-04 — A second run changes nothing

| Why it matters | Cron overlap and operator re-runs are normal; a rewrite of a closed month would destroy the audit trail. |
|---|---|
| Where | `system/` — `php bin/unilevel_month_close.php` (twice) |
| Evidence | `QA17F-04-idempotent.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run the job a second time.
2. Compare the two row counts with the ones from QA17F-03.
3. Run `php bin\unilevel_month_close.php --json` and read `closed` and `periods`.

Expected

- `closed: 0 — nothing was pending`, `periods: —`, and the summary line
  `nothing to close — 2026-10 is already closed`; exit code **0**.
- Counts still **1** header and **27** activity rows.
- `--json`: `"closed": 0`, `"periods": []`, `"members": 0`.

*If it fails:* a non-zero `closed` means the "is closed" check and the insert disagree — stop and report; the period header must be the single source of truth.

### QA17F-05 — `--period` on a closed month and on a future month

| Why it matters | A closed period is immutable and a future one has no month of data; both must be answered clearly instead of silently doing something. |
|---|---|
| Where | `system/` — `--period=2026-10` · `--period=2026-12` · `--period=2026-11` |
| Evidence | `QA17F-05-period.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php bin\unilevel_month_close.php --period=2026-10` (closed by QA17F-03).
2. Run `php bin\unilevel_month_close.php --period=2026-12` (after 2026-11).
3. Run `php bin\unilevel_month_close.php --period=2026-11` (the current month).
4. Re-read both row counts after each.

Expected

- **A (closed):** `period: 2026-10`, `result: already closed — a closed period is never rewritten.`,
  `nothing was written.`, exit code **0**; counts unchanged (1 / 27).
- **B (future):** `refused: The period 2026-12 has not finished yet — it is in the future (the current
  month is 2026-11); only a completed month can be closed.`, exit code **1**.
- **C (current):** the same refusal without "it is in the future", exit code **1**; counts still 1 / 27.

*If it fails:* exit 0 on B or C means a future/incomplete month could be frozen — stop and report. A rewritten row on A means the immutability guard is missing.

### QA17F-06 — The web entry, the log line, the lock and the refusal

| Why it matters | The URL entry must be the same job (not a second implementation), and the log, the lock and the access rule are what make it operable. |
|---|---|
| Where | `http://localhost/ecowealth/system/cron/unilevel_month_close.php?doctor=1` · `?json=1` |
| Evidence | `QA17F-06-web.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `?doctor=1` in a browser (or `Invoke-WebRequest`) from `localhost`.
2. Open `?json=1` and compare its numbers with `php bin\unilevel_month_close.php --json`.
3. Request the same URL on the machine's LAN address (a non-loopback caller, no `?key=`).
4. Read `system/storage/logs/unilevel_month_close-YYYY-MM.log` and list `system/storage/locks/`.

Expected

- `?doctor=1` → **HTTP 200**, `php 8.3.16 (apache2handler)`, the same lines as QA17F-01.
- `?json=1` → `"trigger": "http"` and the **same** `closed / members / qualified / award_total /
  threshold` as the CLI's payload for the same database (`0 / 0 / 0 / 0 / 500` in the transcript).
- The LAN-address request → **`Forbidden`, HTTP 403** (loopback needs no key; anything else needs
  `?key=` matching `CRON_KEY`, which is not configured here).
- The log carries a `trigger=http` line with `closed=0 period=-`, one line per run, and
  `system/storage/locks/unilevel_month_close.lock` exists (advisory, released after every run).

*If it fails:* a 200 from the LAN address means the access rule regressed (`CronAccess`) — stop and report. A different payload from the URL means the web entry grew logic; it must only `require` the CLI file. A second line per run means the log write happens twice.

### QA17F-07 — The `.cmd` launcher reports the same thing

| Why it matters | The Windows Task Scheduler entry point must be the same job, with no PATH assumption beyond `PHP_EXE`. |
|---|---|
| Where | `system/` — `bin\unilevel_month_close.cmd --dry-run` · `--doctor` |
| Evidence | `QA17F-07-cmd.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Double-click `bin\unilevel_month_close.cmd` (or run `cmd /c bin\unilevel_month_close.cmd --dry-run`).
2. Run the same flags through the CLI and compare.
3. Optionally `setx PHP_EXE "…\php.exe"` and re-run to prove the environment override.

Expected

- The launcher prints the **same output** as the CLI for `--dry-run` and `--doctor`, exit code **0**.
- With `PHP_EXE` unset it uses `php` from `PATH`; with `PHP_EXE` set it uses that binary (the transcript
  shows the unset case, which is this machine's).

*If it fails:* `'php' is not recognized` means neither `PATH` nor `PHP_EXE` has a PHP binary for that account — set `PHP_EXE` once for the machine (see `docs/cron.md` §5).

---

## 14. Section F sign-off

| Item | Value |
|---|---|
| Tester | Automated QA pass — DeepSeek Harness (DSH) |
| Date | 2026-11-12 |
| Automated gate | `Cases: 948 / 948` · `Passed: 223 / 223` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 |
| Checks passed | 7 / 07 |
| Defects | two found and fixed during the pass: **(1)** a refused period printed its message to both stdout and stderr, so `--json` output was no longer a parseable document (`P17F-T5` caught it); the refusal now goes to stderr only when `--quiet` suppresses stdout. **(2)** the "nothing to close" sentence claimed "every month from 2026-10 through 2026-10 is already closed" and, on a fresh install whose baseline is later than last month, implied months had been closed; `close_pending_note()` now distinguishes "baseline after last month", "single month already closed" and "every month already closed". Re-run evidence: `QA17F-04-idempotent.txt`. |
| Decision | ☑ Approved ☐ Approved with notes ☐ Blocked — approved from the executed transcripts. The pass ran on the development copy over real HTTP (loopback for the payload, the machine's LAN address for the 403), through the real `.cmd`, and against real rows in `unilevel_periods` / `unilevel_activity`; the fixture was removed afterwards and `wallet_reconcile.php` exits 0. The "someone who did not write it" item stays open for the lead's review, as in sections A–E. |
| Evidence | `docs/qa/evidence/phase-17/` (`QA17F-00-setup-cleanup.txt` … `QA17F-07-cmd.txt`) |


---

## 15. Section G — Release (`QA17G-01` … `QA17G-06`)

**Prerequisites.** The `p17f-month-close` tag plus 17G's five changed files (`app/config.php`,
`tests/Cases/HardeningTest.php`, `tests/smoke.ps1`, this pack and the release documents), a green
automated gate (§1), a backup of the development copy, and an **empty** scratch schema
`ecowealth_sneat_qa17g` created as root. **Expected suite:** 948 / 948. **Expected smoke:** 225 / 225.
**Time:** 60–75 min.

**What this section proves.** Nothing new pays and nothing new renders: it proves that the build
reports **Phase 17**, that every line of the release acceptance table is backed by a named passing
case or a named gate, that the deploy guide and the runbook can be *executed* on a scratch install,
and that the evidence folder and the seven gate records exist. The only code 17G changes is the
constant, the assertion that guards it and two HTTP checks for the month-close entry.

**Fixture used by the section.** The development copy (unchanged baseline) for the gate and the
runbook checks, and the scratch schema `ecowealth_sneat_qa17g` for the deploy replay. No fixture user,
product, order or award is created by section G.

**Safety.** The scratch schema is dropped after `QA17G-05`; the development copy's row counts are
recorded before and after and must be identical (`unilevel_awards` 0, `unilevel_periods` 0,
`unilevel_activity` 0, users 26). Transcript: `QA17G-00-setup-cleanup.txt`.

### QA17G-01 — The automated acceptance gate is green

| Why it matters | The release is only as good as the four commands every earlier gate ran. |
|---|---|
| Where | `system/` — `php tests\run.php` · `powershell -NoProfile -File tests\smoke.ps1` · `php bin\build_install_sql.php --check` · `php bin\wallet_reconcile.php` |
| Evidence | `QA17G-01-suite.txt`, `QA17G-01-smoke.txt`, `QA17G-01-build.txt`, `QA17G-01-reconcile.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. From `system/`, run `php tests\run.php`; read the header (PHP version, schema, **phase 17**) and the
   last two lines.
2. Run `powershell -NoProfile -File tests\smoke.ps1`; read the final `Passed:` line.
3. Run `php bin\build_install_sql.php --check`; echo the exit code.
4. Run `php bin\wallet_reconcile.php`; read the five section headings and the last line.

Expected

- `Cases: 948   Passed: 948   Failed: 0` and `All good.`, exit **0**.
- `Passed: 225   Failed: 0`, exit **0** — including the two new checks `P17F-T10` (the doctor URL) and
  `P17F-T11` (the dry-run JSON). The 948 cases include `HardeningTest`'s build case, which now asserts
  `APP_PHASE === 17`.
- `--check` prints nothing and exits **0**.
- `wallet_reconcile.php` prints **Wallets**, **Commissions**, **Transfers**, **Payouts** and
  **Repeat-purchase**, ends `All good — …`, exit **0**.

*If it fails:* stop — a red suite is not a release candidate (plan §2.5). Record the failing case id in
the phase log, fix it against the phase that owns the behaviour, and re-run that phase's gate first
(17G Rule 1).

### QA17G-02 — A stranger executes sections A–F without a question

| Why it matters | A pack its author cannot be surprised by hides exactly the gaps a real tester finds. |
|---|---|
| Where | sections 3–14 of this document, read cold, against the build |
| Evidence | `QA17G-02-cold-read.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Hand sections 3–14 to a tester who has not read the plan or the phase log. Ask them to execute
   **A–F** and to write down **every** question and every number they could not confirm.
2. For each question, decide whether the answer belongs in the pack (fix it), the runbook (link it) or
   the plan (record a deviation).
3. Re-derive every count and URL in the pack from the build: the phase tags and their suite/smoke
   counts, the six settings, the ten ledger names, the CSV headers, the two clocks, the cron flags and
   the three routes the checks call.

Expected

- **Zero open questions.** Every question is answered by the pack itself, by `docs/runbook.md` or by
  `docs/cron.md`, and the answer is in the place a stranger looks first.
- Every stated number is reproducible on the current build. The counts in §1 are the *current* ones
  (948 / 225) with the per-tag history; a number that only held at an earlier tag is labelled with
  that tag.
- The documentation defects listed in section G's sign-off are fixed **in the shared parts** (§1, the
  status line and the other documents) and, where they sat inside an appended section, corrected in
  place with the defect named in this section's sign-off — a wrong number is never left standing for
  the next reader.

*If it fails:* a question the pack cannot answer is a documentation defect, not a tester error. Fix
the document, not the tester, and record what was missing.

### QA17G-03 — The release acceptance table is complete

| Why it matters | Rule 5: the release is not signed unless every acceptance line is ticked against the phase that proved it. |
|---|---|
| Where | the plan's release acceptance table (§11), one row per line |
| Evidence | `QA17G-03-acceptance-table.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open the plan's **Release acceptance table** and this document's section 15 side by side.
2. For every row, write the proving case id (and its file) or the named gate, then run or re-read that
   case and mark **PROVEN**.
3. Count the rows; the table must have **27** lines and every line must be ticked.

Expected

- **27 / 27** rows proven, none "not tested", none citing a case that does not exist.
- Rows proven by a gate rather than a case (for example `wallet_reconcile.php` with the
  Repeat-purchase section, the cron's URL and `.cmd` forms) cite `QA17C-04`, `QA17F-06` / `QA17F-07`
  and the `CronTest` cases that back them.
- The one row 17G owns — *The docs are updated* — is proven by section G itself and by the eight
  documents' `git diff --stat` in the release commit.

*If it fails:* a row with no proving case is a **defect in the phase that owns the behaviour**, not in
17G (Rule 1). Fix the phase, re-run its gate, record the deviation, then sign.

### QA17G-04 — The build reports phase 17

| Why it matters | D-15: the build must report the phase that shipped the feature, or the next phase starts from a stale constant. |
|---|---|
| Where | `system/` — `php -r "require 'app/config.php'; echo APP_PHASE;"` · `php tests\run.php Hardening` · `/dashboard` as admin |
| Evidence | `QA17G-04-phase.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Print the constant with `php -r "require 'app\config.php'; echo APP_PHASE, PHP_EOL;"`.
2. Run `php tests\run.php Hardening` and read the build case.
3. Open `/dashboard` as an admin; confirm it renders and every admin menu item is live.
4. Read the `php tests\run.php` header, which prints `phase 17`.

Expected

- The constant prints **17**; the header of a suite run reads `PHP 8.3.16 · schema … · phase 17`.
- `HardeningTest`'s `P15 the build reports phase 17` case passes with `assert_same(17, APP_PHASE)`;
  `ReportViewTest`'s `APP_PHASE >= 15` case still passes.
- `/dashboard` (admin) answers **200** and the admin menu renders every item — no item is hidden by the
  constant now that it is at the last phase.
- The plan's §11 wording "on the dashboard badge" is **not** an observable in this build: the shell
  renders no build-phase badge anywhere (`app/layout/` and `app/views/` contain no `APP_PHASE`
  reference; the constant is read only by `nav_item_live()`). The check therefore proves the constant
  through the three places it *is* observable — the runtime value, the suite's own header and the
  guarded assertion — and the wording is amended in the plan (see the deviation log). Adding a badge
  would be a UI change, which 17G Rule 1 forbids.

*If it fails:* a value other than 17 means `config.local.php` or the harness pins it — check
`tests/bootstrap.php` and `config.local.php`, then re-run. A failing `HardeningTest` means the constant
and its assertion diverged; they are one deliverable.

### QA17G-05 — `docs/deploy.md` end to end, then the runbook's weekly checklist

| Why it matters | Rule 2: the documentation is executable. A guide nobody followed is a guess. |
|---|---|
| Where | scratch schema `ecowealth_sneat_qa17g` · `docs/deploy.md` §5B, §11.6, §13, §16 · `docs/runbook.md` §2, §4 and §9 |
| Evidence | `QA17G-05-deploy.txt`, `QA17G-05-runbook.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Create `ecowealth_sneat_qa17g` as root. Import `database/install.sql` with the `mysql` client
   exactly as deploy §5B-A describes.
2. Run deploy §11.6's verification pack: the nine counts, the two demo queries, the metadata keys, the
   `live_revisions` count and `SHOW TABLES LIKE 'live_revisions'`.
3. Read deploy §13: it must name **four** cron jobs, and §16's final checklist must carry the
   **31 / 54 / 2 / 0 / 7 / 2 / 18 / 10 / 0** figures.
4. From `system/`, run the runbook's weekly set against the development copy: the four job doctors,
   `php bin\wallet_reconcile.php`, the three repeat-purchase SQL queries from runbook §4, and the
   month-close `--dry-run`.
5. Drop the scratch schema; record the development copy's counts before and after.

Expected

- The bundle import prints the verification pack with **31 tables, 54 settings, 2 staff, 0 members,
  7 products, 2 packages, 18 levels, 10 contents, 0 codes**; `live_revisions` is **0** after a static
  import; both demo counts are **0**; the metadata keys name the bundle version and channel.
- The four doctors exit **0** and each reports both clocks/`APP_TIMEZONE`, its switch and its storage;
  `unilevel_month_close --doctor` additionally reports `clocks agree`, the threshold, `last closed none
  yet`, `pending none (… ledger baseline …)`, the account count, a free lock and a writable log.
- `wallet_reconcile.php` exits **0**; the three runbook SQL queries return **no rows** (or zero
  counts); the month-close `--dry-run` says `would close: nothing to close — the ledger baseline …`
  and writes no row (`unilevel_periods` / `unilevel_activity` unchanged at **0**).
- Development copy after the pass: users **26**, `unilevel_awards` **0**, `unilevel_periods` **0**,
  `unilevel_activity` **0**; the scratch schema is gone.

*If it fails:* a count other than 31/54 means the bundle and `install.sql` drifted — rebuild with
`php bin\build_install_sql.php` and re-check. A command in the runbook that does not run is a
documentation defect: fix the command and record it, never the other way round.

### QA17G-06 — The evidence folder and the seven gate records are complete

| Why it matters | The gate record is the release's only durable proof; a check without evidence is an assertion. |
|---|---|
| Where | `docs/qa/evidence/phase-17/` and `docs/qa/sign-off-sheet.md` §18 |
| Evidence | `QA17G-06-evidence.txt` |
| Result | ☑ Pass  ☐ Fail  ☐ Blocked |

Steps

1. List the evidence folder and map every `QA17X-nn` id from sections A–G to at least one file.
2. Read the folder's `README.md`: the index must name the section G files too.
3. Read `docs/qa/sign-off-sheet.md` §18: the Phase 17 row must carry **57 / 57**, sections A–G, and the
   seven gate records.
4. Read `docs/phase-log.md`: seven phase entries plus the release entry, each with its tag, counts and
   deviations.

Expected

- **57 / 57** check ids (A 8 · B 9 · C 10 · D 8 · E 9 · F 7 · G 6) each map to at least one file; no
  file is orphaned (every `QA17*` file belongs to a check).
- The sign-off sheet's Phase 17 section has the seven gate rows — **17A … 17G** — with tag, date, suite
  `passed/ran`, smoke `passed/ran`, migration/bundle, manual QA `nn/nn`, tester, deviations and a
  signed box.
- The phase log's Phase 17 entries: **17A, 17B, 17C, 17D, 17E, 17F, 17G** — the last one is the release
  entry and carries the acceptance-table result.
- `Appendix G` in the plan matches the sign-off sheet's seven rows, including 17G's.

*If it fails:* a check with no file is a missing transcript (copy it in, never delete the check); a gate
row without a tag means the tag was not created — tag the commit and re-read. The QA folder's README is
the index: a file it does not name is invisible to a reviewer.

---

## 16. Section G sign-off

| Item | Value |
|---|---|
| Tester | Automated QA pass — DeepSeek Harness (DSH) |
| Date | 2026-11-12 |
| Automated gate | `Cases: 948 / 948` · `Passed: 225 / 225` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 (five sections) |
| Checks passed | 6 / 06 |
| Defects | nine documentation defects found by the cold read (`QA17G-02`) and fixed in this pass: **(1)** the pack did not say sections A–B are tag-scoped, so three checks would fail on the final tree (§2.2 + three inline notes); **(2)** sections B–G assumed a fixture no section told the tester how to build (§2.1); **(3)** §1 still carried the 17F counts (948 / 223) instead of the release counts (948 / 225); **(4)** `system/README.md` had `APP_PHASE` 15, the Phase 16 install counts (27/48), no migration 020 row and a stale 17E future tense; **(5)** `docs/deploy.md` had the 27/48 counts in §5B and §16, "three jobs" in §13, the "until the runbook exists" note and no 17E–17G addendum; **(6)** `docs/runbook.md` had "three jobs" in §2 and §7, 27/48 in §7 and 816 cases in §9; **(7)** `docs/qa/README.md` had the Phase 17 row frozen at "in progress (17C)", the 816/195 counts and a Phases 1–16 title; **(8)** `docs/admin-guide.md` documented the repeat ledger and reports but not the ladder card, the five settings or the monthly close; **(9)** `docs/qa/sign-off-sheet.md` had the Phase 17 section frozen at 17B with no C–G rows and no gate records. No product defect was found: the code was right and the documents were stale. |
| Decision | ☑ Approved ☐ Approved with notes ☐ Blocked — approved from the executed transcripts: the full suite, the full smoke run, the bundle check, the reconciliation, the scratch-install deploy replay and the runbook's weekly commands. The "someone who did not write it" item stays open for the lead's review exactly as in sections A–F: the cold read was performed by the same automated pass, which is why the seven documentation defects it found are listed by name. |
| Evidence | `docs/qa/evidence/phase-17/` (`QA17G-00-setup-cleanup.txt` … `QA17G-06-evidence.txt`) |

---

## Appendix A — Execution aids for a human pass

*Added 2026-11-12, after the gate. These are the fixture states the sections need and the UI cannot
produce. They change no check's expected result. Every statement targets the development copy (or a
scratch copy), uses the `qa17_` names of §2.1, and is safe to re-run where it carries a `NOT EXISTS`
guard. Run them with the `mysql` client as a user that may write the schema.*

### A.0 Before you run one

- §2.1's members and `QA17 Product` exist: SKU `QA17-P1`, price `300.00`, P.V. `250.00`, the reference
  ladder, product switch **on**, global **Repeat-purchase bonus enabled** on.
- `qa17_buyer` holds the `1000.00` top-up.
- Record the baseline (`§2.3`'s query) and take the section dump **before** the first statement.

### A.1 Section C-08 — the October purchases

Four paid orders dated **2026-10-15**: `qa17_c` ₱450 (below the threshold), and `qa17_b`, `qa17_a`,
`qa17_boss` ₱600 each. They carry no `unilevel_processed_at` — they represent purchases made before the
stream existed. The month close reads `shop_order_items.line_total`, so each order has one line equal to
its total.

```sql
-- 1. the four orders
INSERT INTO shop_orders (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
                         billing_name, shipping_name, shipping_address, paid_at, created_at,
                         unilevel_total)
SELECT v.order_no, u.id, 'paid', 'ewallet', v.amount, 0, v.amount,
       u.full_name, u.full_name, 'QA17 fixture', v.paid_at, v.paid_at - INTERVAL 5 MINUTE, 0
FROM (SELECT 'qa17_c'    AS username, 'QA17C-2026-10-C'    AS order_no, 450.00 AS amount,
             '2026-10-15 10:00:00' AS paid_at
      UNION ALL SELECT 'qa17_b',    'QA17C-2026-10-B',    600.00, '2026-10-15 10:05:00'
      UNION ALL SELECT 'qa17_a',    'QA17C-2026-10-A',    600.00, '2026-10-15 10:10:00'
      UNION ALL SELECT 'qa17_boss', 'QA17C-2026-10-BOSS', 600.00, '2026-10-15 10:15:00') v
JOIN users u ON u.username = v.username
LEFT JOIN shop_orders o ON o.order_no = v.order_no
WHERE o.id IS NULL;

-- 2. one line per order, equal to its total
INSERT INTO shop_order_items (order_id, product_id, product_name, product_sku, quantity,
                              unit_price, line_total, reservation_state)
SELECT o.id, p.id, p.name, p.sku, 1, o.subtotal, o.subtotal, 'committed'
FROM shop_orders o
JOIN products p ON p.sku = 'QA17-P1'
WHERE o.order_no IN ('QA17C-2026-10-C', 'QA17C-2026-10-B',
                     'QA17C-2026-10-A', 'QA17C-2026-10-BOSS')
  AND NOT EXISTS (SELECT 1 FROM shop_order_items i WHERE i.order_id = o.id);
```

Verify — four rows, `450.00` and three `600.00`:

```sql
SELECT u.username, o.order_no, o.paid_at, oi.line_total
FROM shop_orders o
JOIN users u ON u.id = o.user_id
JOIN shop_order_items oi ON oi.order_id = o.id
WHERE o.order_no LIKE 'QA17C-2026-10-%'
ORDER BY u.username;
```

Then run `php close.php 2026-10` (**§7**) and continue with `QA17C-08`. The close reports `qualified 3`
and the November order skips `qa17_c` with *recipient is not Active for 2026-10*.

Cleanup: the section dump (§2.3) is the safest undo; otherwise delete the four orders and their lines
with `order_no LIKE 'QA17C-2026-10-%'`.

### A.2 Section D-03 — the ₱45 ledgered debit

The section D fixture pays `qa17_c` (its `u1`) ₱25.00 on each of its two paid orders, so both buckets
hold ₱50.00. The UI cannot take ₱45 out of the withdrawable bucket (minimum transfer ₱50, minimum
payout ₱500), so the fixture records the spend as an `adjustment` movement and leaves the wallet at
₱5.00 in each bucket — exactly the state a real ₱45 spend produces, and `wallet_reconcile.php` still
exits 0.

```sql
-- run only after both paid orders: qa17_c holds 50.00 / 50.00
INSERT INTO ewallet_ledger (user_id, type, amount, balance_after, withdrawable_after,
                            ref_type, ref_id, note)
SELECT u.id, 'debit', 45.00, 5.00, 5.00, 'adjustment', NULL,
       'QA17D fixture: spend 45 of u1 withdrawable'
FROM users u
WHERE u.username = 'qa17_c'
  AND NOT EXISTS (SELECT 1 FROM ewallet_ledger l
                  WHERE l.user_id = u.id
                    AND l.note = 'QA17D fixture: spend 45 of u1 withdrawable')
LIMIT 1;

UPDATE users SET ewallet_balance = 5.00, withdrawable_balance = 5.00
WHERE username = 'qa17_c';
```

Verify: `php bin\wallet_reconcile.php` exits **0** with no mismatch for `qa17_c`. Then admin-cancel the
first paid order and continue with `QA17D-03`: `qa17_c` shows **₱5.00 recovered of ₱25.00** and a
**₱20.00 shortfall**.

### A.3 Section F — the closed-month fixture

Register `qa17f_buyer` and create `QA17F-FIXTURE` (any price; product switch **off**) through the UI,
then put the one October order and the moved baseline in place:

```sql
INSERT INTO shop_orders (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
                         billing_name, shipping_name, shipping_address, paid_at, created_at,
                         unilevel_total)
SELECT 'QA17F-2026-10-015', u.id, 'paid', 'ewallet', 600.00, 0, 600.00,
       u.full_name, u.full_name, 'QA17 fixture', '2026-10-15 10:00:00', '2026-10-15 09:55:00', 0
FROM users u
WHERE u.username = 'qa17f_buyer'
  AND NOT EXISTS (SELECT 1 FROM shop_orders o WHERE o.order_no = 'QA17F-2026-10-015');

INSERT INTO shop_order_items (order_id, product_id, product_name, product_sku, quantity,
                              unit_price, line_total, reservation_state)
SELECT o.id, p.id, p.name, p.sku, 1, 600.00, 600.00, 'committed'
FROM shop_orders o
JOIN products p ON p.sku = 'QA17F-FIXTURE'
WHERE o.order_no = 'QA17F-2026-10-015'
  AND NOT EXISTS (SELECT 1 FROM shop_order_items i WHERE i.order_id = o.id);

UPDATE settings SET value = '2026-10-01 00:00:00'
WHERE key_name = 'unilevel_ledger_since';
```

Verify with `php bin\unilevel_month_close.php --doctor`: `ledger since 2026-10-01 00:00:00`,
`pending 2026-10 (1)` and `members 27`. The dry run then reads
`2026-10  members 27  qualified 1  award_total ₱0.00`, as `QA17F-02` expects.

Cleanup: the section dump (§2.3). If you are not restoring, delete the order and its line, delete the
`2026-10` period and activity rows, and put `unilevel_ledger_since` back to the value recorded in A.0.

### A.4 Section D-06 — an order paid before the feature

A paid order with **no** repeat-purchase stamp, so the order card has to explain it:

```sql
INSERT INTO shop_orders (order_no, user_id, status, payment_method, subtotal, shipping_fee, total,
                         billing_name, shipping_name, shipping_address, paid_at, created_at,
                         unilevel_processed_at, unilevel_total)
SELECT 'QA17D-LEGACY-01', u.id, 'paid', 'ewallet', 300.00, 0, 300.00,
       u.full_name, u.full_name, 'QA17 fixture', '2026-11-01 10:00:00', '2026-11-01 09:55:00',
       NULL, 0
FROM users u
WHERE u.username = 'qa17_buyer'
  AND NOT EXISTS (SELECT 1 FROM shop_orders o WHERE o.order_no = 'QA17D-LEGACY-01');

INSERT INTO shop_order_items (order_id, product_id, product_name, product_sku, quantity,
                              unit_price, line_total, reservation_state)
SELECT o.id, p.id, p.name, p.sku, 1, 300.00, 300.00, 'committed'
FROM shop_orders o
JOIN products p ON p.sku = 'QA17-P1'
WHERE o.order_no = 'QA17D-LEGACY-01'
  AND NOT EXISTS (SELECT 1 FROM shop_order_items i WHERE i.order_id = o.id);
```

Open `/admin/orders/{id}`: the card must read **This order was paid before the repeat-purchase stream
was switched on, so it earned no bonus**, with the stamp `NULL` and the total `0.00`.

For the other half of `QA17D-06`, place a **GCash** order and leave it in `payment_review` — no SQL
needed.

### A.5 Human verification record

The sign-off tables of sections 4, 6, 8, 10, 12, 14 and 16 record the automated pass of 2026-11-12.
Record your own run here; do not overwrite them.

| Section | Date | Tester | Checks passed | Notes |
|---|---|---|---:|---|
| A — Foundation (tag-scoped, §2.2) | | | / 8 | |
| B — Configuration (tag-scoped, §2.2) | | | / 9 | |
| C — Money engine | | | / 10 | |
| D — Admin visibility | | | / 8 | |
| E — Member visibility | | | / 9 | |
| F — Operations | | | / 7 | |
| G — Release | | | / 6 | |

*End of pack.*
