# EcoWealth — Phase 19 manual QA: Royalty bonus

**Scope.** Phase 19 of the member system (`dmp/plan-ecowealth-royalty.md`): a derived five-rank ladder
(Member, Leader, Supervisor, Manager, Director) and a monthly group-sales override paid once per closed
calendar month. The work is delivered in seven gated phases, **19A → 19G**. This document is created
by 19A with **section A only**; every later phase appends its own section before its gate, and the
file is never rewritten.

**Audience.** The developer/operator who runs the phase gate. Section A is surgical and moves **no
money**: it applies a migration, reads counts and runs the install/reset tools on scratch schemas.
From section C on the checks may move test money, and every one of those runs on a scratch schema —
never on a production database.

**Document status.** Section A (19A — Foundation) written 2026-11-15 with the automated gate green.
**Section A: 8 checks.**

---

## 1. What is already automated

Run these first, from `system/`. If any fails, stop and report before starting the manual pass.

```powershell
php bin\build_install_sql.php --check     # exit 0, no output: the bundle matches its sources
php tests\run.php                         # full suite
powershell -NoProfile -File tests\smoke.ps1
php bin\wallet_reconcile.php              # exit 0: this phase moves no money
```

Expected final lines at `p19a-foundation`:

```text
Cases: 1026   Passed: 1026   Failed: 0
All good.
```

```text
Passed: 229   Failed: 0
```

The counts grow with each phase: **1,026 / 229** at `p19a-foundation`; the suite floor rises to
**1,050** at 19B and **1,140** at 19G, and the smoke walk reaches **248** at 19G. 19A adds **18** PHP
cases (`RoyaltyFoundationTest`, P19A-T1 … T18) and **no** smoke check — the phase adds no route, so the
HTTP walk is unchanged at 229.

Phase 19A's share of the suite:

| Group | Cases | What it proves for 19A |
|---|---:|---|
| `RoyaltyFoundation` (new) | 18 | The migration's two tables, their anchor, foreign keys and CHECKs; a second run is a no-op and recreates a lost table; the migrated schema equals a fresh install (columns, indexes, foreign keys, enum, CHECKs); the enum ↔ `REF_TYPES` match and the label/icon; the sixteen settings and their defaults on both sides; a re-run never overwrites an operator value; the earning switch is off and the payment switch on; the bundle's 33 tables / 73 settings; a real fresh install reporting `33 / 73 / 2 / 0 / 7 / 2 / 18 / 10 / 0`; a reset that empties both tables and resets only the award counter; the reset preview/dry-run; **no** reader, class, route or view; the guard CHECKs and cascades; the `royalty_ledger_since` baseline; seed parity; and the 33-table count everywhere |
| `MigrationTest` | 0 | `023_royalty_bonus.sql` joins the ordered migration list; the migrated-vs-fresh case now compares `royalty_periods`, `royalty_awards` and the extended enum (P19A-T3's migration half) |
| `InstallTest` / `InstallSqlTest` | 0 | The fresh install and the bundle now report **33 tables / 73 settings** and **60 statements** |
| `DbResetTest` | 0 | The 33-table helper, the zero list and **21** auto-increment counters |
| `DbTargetTest` | 0 | The harness schema has 33 tables |
| `WalletTest` | 0 | P5-T8 carries eleven `ref_type` values, `royalty` last |

The migration and install/reset cases build scratch schemas (`ecowealth_sneat_migration_test`,
`ecowealth_sneat_tool_a_test`, `ecowealth_sneat_tool_b_test`). No automated case targets
`ecowealth_sneat` or touches a production database.

---

## 2. How to read a check

Every check has the same shape:

| Field | Meaning |
|---|---|
| **Why** | the phase rule or contract the check proves |
| **Where** | the exact shell, URL or SQL client |
| **Evidence** | the file name under `docs/qa/evidence/phase-19/` |
| **Result** | `☐ Pass  ☐ Fail  ☐ Blocked` — replace the box |

A check that produces only counts is captured as a **text transcript**, pasted into its evidence file;
a check that shows a browser screen is captured as a screenshot. Section A produces only text.

---

## 3. Section A — Phase 19A: Foundation (`QA19A-01` … `QA19A-08`)

**Prerequisites.** Tag `p19-start` on `feature/royalty` (the baseline before 19A); the development copy
at migration **022** (31 tables); the app running (Laragon → Start All) for the smoke walk in
`QA19A-08`; the scratch schemas `ecowealth_sneat_tool_a_test` and `ecowealth_sneat_tool_b_test`
provisioned (they are recreated by the tools themselves). Expected suite count for this section:
**1,026**.

> **Notation.** `mysql` below means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the
> app account; `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`.

### QA19A-01 — Back up the development copy and confirm it is at migration 022

| Why | 19A is the first schema change of Phase 19; the standing rule is a dump outside the repository before any migration. |
|---|---|
| Where | PowerShell + `mysqldump` |
| Evidence | `QA19A-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysqldump -u ecowealth -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-19\ecowealth_sneat-pre-19a-<stamp>.sql`
2. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM ecowealth_sneat.settings"`
4. `SHOW TABLES LIKE 'royalty%'` on that schema.

Expected

- The dump exists, is non-empty and lives **outside** the repository.
- **31** tables and **54** settings (the development copy was built by migrations `001`–`022`, so it
  carries no `install_*` metadata rows; a fresh install at `p19-start` holds **57** settings).
- `SHOW TABLES LIKE 'royalty%'` returns **no rows** — 19A has not run yet.
- If the table count is not 31, stop: the copy is not at migration `022`.

### QA19A-02 — Apply migration 023, then apply it a second time

| Why | Plan §5 Rule 1: the migration is idempotent and safe to re-run. |
|---|---|
| Where | `mysql` |
| Evidence | `QA19A-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysql -u ecowealth -p ecowealth_sneat < database\migrations\023_royalty_bonus.sql`
2. Repeat step 1.
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
4. `... "SELECT COUNT(*) FROM ecowealth_sneat.settings"`

Expected

- Both runs exit **0**; the second prints no error and changes nothing.
- **33** tables and **70** settings after the first run (the sixteen new keys; no `install_*` rows).
- Re-running does not duplicate a setting and does not fail on the enum guard.

### QA19A-03 — The two tables exist and the wallet ENUM names royalty

| Why | Plan §13.2/§13.3/§13.4: the tables are the phase's deliverable and `royalty` is appended to the movement vocabulary. |
|---|---|
| Where | `mysql` |
| Evidence | `QA19A-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysql -u ecowealth -p ecowealth_sneat -e "SHOW TABLES LIKE 'royalty%'"`
2. `mysql -u ecowealth -p ecowealth_sneat -e "SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'"`
3. `mysql -u ecowealth -p ecowealth_sneat -e "SHOW CREATE TABLE royalty_awards\G"` and
   `... royalty_periods\G`

Expected

- Exactly **two** tables: `royalty_awards`, `royalty_periods`.
- The `Type` column ends with `'royalty')` and still contains `'unilevel_reversal'` before it; there is
  no `royalty_reversal`.
- `royalty_periods` has `PRIMARY KEY (period)` and **no** `AUTO_INCREMENT` column;
  `royalty_awards` has `AUTO_INCREMENT` id, `UNIQUE KEY uq_royalty_award (period, user_id)` and the
  `chk_ra_*` CHECKs.

### QA19A-04 — The sixteen settings exist with the reference defaults

| Why | Plan §13.4: an operator must see the reference preset and the stream must ship off. |
|---|---|
| Where | `mysql` |
| Evidence | `QA19A-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysql -u ecowealth -p ecowealth_sneat -e "SELECT key_name, value FROM settings WHERE key_name LIKE 'royalty\_%' ORDER BY key_name"`

Expected

- **16** rows.
- `royalty_enabled = 0`, `royalty_pay_enabled = 1`.
- The four thresholds are `10`; the quotas `500000`, `1000000`, `5000000`; the rates `3.000/5.000`,
  `5.000/10.000`, `10.000/15.000`.
- `royalty_ledger_since` is a `YYYY-MM-DD HH:MM:SS` datetime.

### QA19A-05 — Rebuild the install bundle and prove it matches its sources

| Why | Plan §13.6: `install.sql` is generated; a stale bundle is a failed gate. |
|---|---|
| Where | PowerShell |
| Evidence | `QA19A-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\build_install_sql.php`
2. `php bin\build_install_sql.php --check; echo "exit=$LASTEXITCODE"`
3. `Select-String -Path database\install\header.sql,database\install\footer.sql -Pattern '33|73'`

Expected

- The rebuild reports **60 statements** and a byte size (it differs from the `022` bundle).
- `--check` exits **0** with no output.
- `header.sql` says **33 tables · 73 settings**; `footer.sql` says `tables_created = 33` and
  `settings_rows = 73`.

### QA19A-06 — A fresh install on a scratch schema reports 33 / 73 / …

| Why | Plan §13.6: the fresh-install path and the migrated path must agree. |
|---|---|
| Where | PowerShell (scratch schema `ecowealth_sneat_tool_a_test`) |
| Evidence | `QA19A-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Recreate the scratch schema empty (phpMyAdmin or `DROP DATABASE`/`CREATE DATABASE` for the tool
   schema).
2. `php bin\db_install.php --database=ecowealth_sneat_tool_a_test --force --json --admin-password=AdminPassw0rd!23 --superadmin-password=SuperPassw0rd!23`
3. `mysql -u ecowealth -p ecowealth_sneat_tool_a_test -e "SHOW TABLES LIKE 'royalty%'"`
4. `... "SELECT COUNT(*) FROM settings"` and `... "SELECT COUNT(*) FROM royalty_awards"`

Expected

- Exit **0**; the JSON `counts` read `tables_created 33`, `settings_rows 73`, `staff_rows 2`,
  `members_rows 0`, `products_rows 7`, `packages_rows 2`, `levels_rows 18`, `contents_rows 10`,
  `codes_rows 0`.
- Both royalty tables exist and are empty.
- `storage/install.lock` is written (a guided install always locks). Delete it before any further
  install on a scratch schema; the next check does.

### QA19A-07 — The reset previews and empties a royalty award and period

| Why | Plan §13.7/§5 Rule 6: a reset leaves both tables empty, resets only the award counter and
keeps the settings. |
|---|---|
| Where | PowerShell (scratch schema `ecowealth_sneat_tool_b_test`) |
| Evidence | `QA19A-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Install the `QA19A-06` bundle into `ecowealth_sneat_tool_b_test`, or run
   `php bin\db_install.php --database=ecowealth_sneat_tool_b_test --force ...` as above.
2. Insert one header and one award (the fixture SQL is in the evidence file):
   `INSERT INTO royalty_periods (...) VALUES ('2026-10', ...)` and
   `INSERT INTO royalty_awards (...) VALUES ('2026-10', <member id>, ...)`.
3. `php bin\db_reset.php --database=ecowealth_sneat_tool_b_test --dry-run --json` (or `--force
   --confirm=... --json` for the real run).
4. `mysql ... -e "SELECT COUNT(*) FROM royalty_awards; SELECT COUNT(*) FROM royalty_periods; SELECT COUNT(*) FROM settings WHERE key_name LIKE 'royalty\_%'"`
5. `... "SELECT auto_increment FROM information_schema.tables WHERE table_schema='ecowealth_sneat_tool_b_test' AND table_name='royalty_awards'"`

Expected

- The dry run's `counts` name `royalty_periods` and `royalty_awards`, `missing_tables` is empty and the
  plan carries a `royalty` step with **2** statements.
- After the reset both tables are **0** and the sixteen settings survive.
- `royalty_awards`'s counter is back at **1** (or NULL); `royalty_periods` has no counter (NULL).
- The reset's verification is `ok` (including the wallet, commission, transfer, payout and
  repeat-purchase reconciles).

### QA19A-08 — The money is untouched and the full gate is green

| Why | The universal gate (§2.4): wallet reconcile, suite and smoke. |
|---|---|
| Where | PowerShell |
| Evidence | `QA19A-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\wallet_reconcile.php; echo "exit=$LASTEXITCODE"`
2. `php tests\run.php` (expect `Cases: 1026   Passed: 1026   Failed: 0`)
3. `powershell -NoProfile -File tests\smoke.ps1` (expect `Passed: 229   Failed: 0`)
4. `php bin\build_install_sql.php --check; echo "exit=$LASTEXITCODE"`

Expected

- `wallet_reconcile` exits **0** and prints no mismatch: 19A moved no money.
- The suite is **1,026 / 1,026**, exit 0.
- The smoke walk is **229 / 229**, exit 0 — the phase adds no route.
- The bundle check exits **0**.

---

## 4. Sign-off

| Section | Checks | Passed | Failed | Blocked | Tester | Date | Evidence |
|---|---:|---:|---:|---:|---|---|---|
| A — Foundation | 8 | 8 | 0 | 0 | Automated QA pass — DeepSeek Harness (DSH) | 2026-11-15 | `docs/qa/evidence/phase-19/` |

**Decision:** ☑ Pass  ☐ Pass with notes  ☐ Fail

Sections B–G are appended by 19B–19G before their gates; this section is final.
