# EcoWealth — Phase 20 manual QA: Team Harvest bonus

**Scope.** Phase 20 of the member system (`dmp/plan-ecowealth-team-harvest.md`): a private 3×10
qualification matrix that pays a flat amount each time a member completes a level. The work is
delivered in seven gated phases, **20A → 20G**. This document is created by 20A with **section A
only**; every later phase appends its own section before its gate, and the file is never rewritten.

**Audience.** The developer/operator who runs the phase gate. Section A is surgical and moves **no
money**: it applies a migration, reads counts, opens two pages that must *not* have changed, and
runs the install/reset tools on scratch schemas. From section C on the checks may move test money,
and every one of those runs on a scratch schema — never on a production database.

**Document status.** Section A (20A — Foundation) written 2026-11-15 with the automated gate green.

---

## 1. What is already automated

Run these first, from `system/`. If any fails, stop and report before starting the manual pass.

```powershell
php bin\build_install_sql.php --check     # exit 0, no output: the bundle matches its sources
php tests\run.php                         # full suite
powershell -NoProfile -File tests\smoke.ps1
php bin\wallet_reconcile.php              # exit 0: this phase moves no money
```

Expected final lines at `p20a-foundation`:

```text
Cases: 1180   Passed: 1180   Failed: 0
All good.
```

```text
Passed: 248   Failed: 0
```

The baseline was **1,162 / 1,162** PHP cases and **248 / 248** smoke checks at `p20-start`; 20A adds
**18** cases and **no** smoke check, because it adds no route and no page.

20A's share of the PHP suite (`TeamHarvestFoundationTest`, 18 cases):

| Case | What it proves |
|---|---|
| P20A-T1 | Migration `024` creates the three tables, their anchors, foreign keys and CHECKs; the queue column list is exactly the manual gate's list; no backfill |
| P20A-T2 | A second run is a no-op, keeps an operator's amount and setting, and recreates a lost table |
| P20A-T3 | The migrated schema equals a fresh install (tables, columns, indexes, foreign keys, CHECKs, ENUM) and `024` is the last migration in the ordered list |
| P20A-T4 | Seed 001 carries the four keys and the ten ladder rows with the reference amounts |
| P20A-T5 | `install.sql` matches its sources byte for byte; header and footer say **36 tables / 77 settings**; the bundle creates 36 tables and seeds the ladder |
| P20A-T6 | A fresh install reports **36 / 77 / 2 / 0 / 7 / 2 / 18 / 10 / 0 / 10**, with the stream off and unseeded |
| P20A-T7 | The transactional guard fails with `harvest_queue=1` named; the ladder stays catalogue |
| P20A-T8 | A business reset empties the queue and the awards, clears the baseline, keeps the ten ladder rows and the four settings, and restarts both counters |
| P20A-T9 | `WalletService::REF_TYPES` and the live ENUM agree in both directions — **12** values, `team_harvest` last |
| P20A-T10 | `team_harvest` labels as **Team Harvest bonus** with `bx-layer`; every movement kind has a label and a `bx-` icon |
| P20A-T11 | The four defaults on a fresh and a migrated database; the stream is off and the queue unseeded; the migration never stamps "now" |
| P20A-T12 | The ten reference amounts, level by level, total **₱5,095,000.00**, and an operator's edit survives a re-run |
| P20A-T13 | Deleting a member who is not queued is harmless |
| P20A-T14 | Deleting a queued member cascades the queue, the awards and the wallet ledger, and the reconciler stays clean |
| P20A-T15 | Only the four manifest files (`WalletService`, `helpers.php`, `DatabaseInstaller`, `DatabaseResetService`) name the new objects; no model, service, controller or route exists |
| P20A-T16 | The phase is inert: empty tables, no producer, no reader outside the reset engine |
| P20A-T17 | The schema, the bundle and the reset tooling all report 36 tables; the queue and awards are transactional, the ladder is catalogue |
| P20A-T18 | The three tables refuse an impossible row (directs, baseline, level, amount, threshold, status, duplicate level) |

No automated case in this phase writes a queue row, an award row or a `team_harvest` wallet row on
the development database. The HTTP walk uses the development copy and restores every setting it
touches.

---

## 2. How to read a check

Every check has the same shape:

| Field | Meaning |
|---|---|
| **Why** | the phase rule or contract the check proves |
| **Where** | the exact shell, URL or SQL client |
| **Evidence** | the file name under `docs/qa/evidence/phase-20/` |
| **Result** | `☐ Pass  ☐ Fail  ☐ Blocked` — replace the box |

A check that produces only counts is captured as a **text transcript**, pasted into its evidence file;
a check that shows a browser screen is captured as a screenshot. Section A produces only text.

---

## 3. Section A — Phase 20A: Foundation (`QA20A-01` … `QA20A-08`)

**Prerequisites.** Tag `p20-start` on `feature/team-harvest` (the baseline before 20A); the
development copy at migration **023** (33 tables, 70 settings); the app running (Laragon → Start All)
for the page checks; the scratch schemas `ecowealth_sneat_tool_a_test` and
`ecowealth_sneat_tool_b_test` provisioned (they are recreated by the tools themselves). Expected
suite count for this section: **1,180**.

> **Notation.** `mysql` below means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the
> app account; `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`.
>
> **Where each check runs.** `QA20A-01` takes the backup, and `QA20A-02`, `03`, `07` and `08` run on
> scratch schemas. `QA20A-04`, `05` and `06` need the running application, which reads the
> development copy: apply migration `024` to `ecowealth_sneat` once, then run them. The backup from
> `QA20A-01` is the rollback. Nothing the pages do writes a Team Harvest row.

### QA20A-01 — Back up the development copy and confirm it is at migration 023

| Why | 20A is the first schema change of Phase 20; the standing rule is a dump outside the repository before any migration. |
|---|---|
| Where | PowerShell + `mysqldump` |
| Evidence | `QA20A-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysqldump -u ecowealth -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-20\ecowealth_sneat-pre-20a-<stamp>.sql`
2. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM ecowealth_sneat.settings"`
4. `mysql -u ecowealth -p -e "SHOW TABLES LIKE '%harvest%'" ecowealth_sneat`

Expected

- The dump exists, is non-empty and lives **outside** the repository.
- **33** tables and **70** settings (the development copy was built by migrations `001`–`023`, so it
  carries no `install_*` metadata rows; a fresh install at `p20-start` holds **73** settings).
- The `SHOW TABLES` command returns **no row**: the feature does not exist yet.

### QA20A-02 — Apply migration 024 twice to a p20-start schema

| Why | Rule 1: the migration is additive and idempotent. A second run must be a no-op. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_a_test` |
| Evidence | `QA20A-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build a **p20-start** scratch schema from the previous tag's bundle, not the current one:
   `git show p20-start:system/database/install.sql | mysql -u ecowealth -p ecowealth_sneat_tool_a_test`
2. Confirm it is the previous shape: `SHOW TABLES LIKE '%harvest%'` → no row.
3. `mysql -u ecowealth -p ecowealth_sneat_tool_a_test < database/migrations/024_team_harvest.sql`
4. `mysql -u ecowealth -p -e "SHOW TABLES LIKE '%harvest%'" ecowealth_sneat_tool_a_test`
5. Run the same file a **second** time.
6. `SHOW COLUMNS FROM harvest_queue;`, `SHOW COLUMNS FROM team_harvest_awards;`,
   `SHOW COLUMNS FROM team_harvest_levels;`
7. `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type';`

Expected

- Step 2 returns no row; step 4 returns exactly **three** rows: `harvest_queue`,
  `team_harvest_levels`, `team_harvest_awards`.
- The second run exits **0** with no error and changes nothing. Re-running steps 4 and 6 shows the
  same objects.
- `harvest_queue` columns are `id, user_id, qualified_at, directs_at_qualification, teammates,
  baseline_n, source, created_at`.
- `team_harvest_awards` columns are `id, user_id, level, threshold_n, n_at_award, amount, status,
  ledger_id, credited_at, created_at`.
- `team_harvest_levels` columns are `level, amount, updated_at, updated_by`.
- The `ref_type` ENUM ends `'royalty','team_harvest'`.

### QA20A-03 — The settings and the ten ladder amounts

| Why | Rule 4 (off and unseeded) and Rule 9 (the reference ladder is seeded once). |
|---|---|
| Where | `mysql` on the same scratch schema |
| Evidence | `QA20A-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT key_name, value FROM settings WHERE key_name LIKE 'team\_harvest\_%' ORDER BY key_name;`
2. `SELECT COUNT(*), SUM(amount) FROM team_harvest_levels;`
3. `SELECT level, amount FROM team_harvest_levels ORDER BY level;`

Expected

- Exactly **four** settings: `team_harvest_enabled = 0`, `team_harvest_qualify_directs = 3`,
  `team_harvest_max_levels = 10`, `team_harvest_ledger_since = ''` (empty, not a date).
- `COUNT(*) = 10` and `SUM(amount) = 5095000.00`.
- The ten amounts are `1000.00, 2000.00, 4000.00, 6000.00, 12000.00, 50000.00, 120000.00,
  200000.00, 1200000.00, 3500000.00`.

### QA20A-04 — The admin UI has not changed

| Why | 20A ships no settings card and no member panel; both belong to 20B/20E. |
|---|---|
| Where | `/admin/settings` and `/admin/members/{id}` (development copy after `024`) |
| Evidence | `QA20A-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `admin`, open **System → Settings** (`/admin/settings`).
2. Search the page for **Team Harvest** (browser find, `Ctrl+F`).
3. Open any member from **Members → (a member)** and search that page for **Harvest**.

Expected

- The settings page has **no** Team Harvest block, card, field or amount — only the cards that
  existed at `p20-start`.
- The member page has **no** harvest panel, matrix, position or award line.
- No PHP warning, notice, 404 or 500 appears.

### QA20A-05 — Three direct registrations do not create a queue row

| Why | 20A ships no qualification hook (20C owns it): structure must exist before structure is written. |
|---|---|
| Where | `/register` and `phpMyAdmin` / `mysql` |
| Evidence | `QA20A-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As `admin`, create a QA sponsor (`QA20A_Sponsor`, password `QaHarvest!2345`) and note their
   username. This copy still has free registration, so no package is needed.
2. Register three members under that sponsor (`QA20A_M1`, `QA20A_M2`, `QA20A_M3`) through
   `/register`, using the sponsor's username in the sponsor field each time.
3. `SELECT COUNT(*) FROM harvest_queue;`
4. Open the sponsor's member page.

Expected

- `COUNT(*) = 0`: the sponsor holds three directs and is **not** queued, because no hook exists yet.
- The sponsor's page is exactly the page 20A shipped — no harvest panel, no position, no badge.
- Nothing else changed: the three members are ordinary `member` rows.

### QA20A-06 — The wallet statement filter names Team Harvest

| Why | Rule 6: the only visible change of this phase. The filter enumerates `WalletService::REF_TYPES`, so it must list the new movement — and it cannot match a row until 20D. |
|---|---|
| Where | the member wallet statement (`/wallet`) and the admin wallet monitor |
| Evidence | `QA20A-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as a member (for example `juan`), open the wallet page (`/wallet`).
2. Open the statement filter's movement-type list and look for **Team Harvest bonus**.
3. Choose it and apply the filter.
4. Sign in as `admin`, open **Finance → Wallets** and repeat steps 2–3 on the statement tab.

Expected

- **Team Harvest bonus** appears in both filter lists (the member statement and the admin monitor),
  with its layer icon.
- Selecting it returns **no rows** and the total reads ₱0.00: no row can carry the value until 20D.
- The other movement kinds still filter exactly as they did, and no page error appears.

### QA20A-07 — Rebuild the bundle and re-install on an empty schema

| Why | Rule 5 / P20A-T5-T6: the committed bundle must equal its sources, and a fresh install reports the new counts. |
|---|---|
| Where | PowerShell on `system/` |
| Evidence | `QA20A-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\build_install_sql.php` (rebuild) and `php bin\build_install_sql.php --check` (must exit 0).
2. `php bin\db_install.php --check --database=ecowealth_sneat_tool_a_test`
3. `php bin\db_install.php --database=ecowealth_sneat_tool_a_test --force --admin-password=QaAdmin!23456 --superadmin-password=QaSuper!23456`
4. `php bin\wallet_reconcile.php`

Expected

- `--check` exits **0** with no output; the header reads **36 tables · 77 settings · 10 Team Harvest
  level amounts**.
- The install preflight reports **64 statements** in the bundle and every check OK.
- The install verifies and prints `tables_created = 36`, `settings_rows = 77`,
  `team_harvest_levels_rows = 10` (and 2 staff, 0 members, 7 products, 2 packages, 18 levels,
  10 contents, 0 codes).
- `wallet_reconcile.php` exits **0**; the Team Harvest section is not part of the reconciler until
  20D, and no `team_harvest` row exists.

### QA20A-08 — A business reset empties the structure and keeps the ladder

| Why | Rule 8 / §13.8 I-09: after a reset the queue and the awards are empty, the baseline is `''`, the ladder and the settings survive. |
|---|---|
| Where | the reset console on the development copy (or a scratch schema) |
| Evidence | `QA20A-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Optionally insert one queue row and one award for a member (the reset deletes members), and set
   `team_harvest_ledger_since` to a date.
2. `php bin\db_reset.php --dry-run` — the plan must carry a **harvest** step deleting the awards and
   the queue.
3. `php bin\db_reset.php --force --confirm=ecowealth_sneat`
4. `SELECT COUNT(*) FROM harvest_queue; SELECT COUNT(*) FROM team_harvest_awards;`
5. `SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since';`
6. `SELECT COUNT(*) FROM team_harvest_levels;`
7. `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'team\_harvest\_%';`

Expected

- The dry run writes nothing and plans the harvest step.
- The reset reports `ok` and a clean verification (all six reconciliations, the zero pack, the
  counters at 1).
- `harvest_queue` and `team_harvest_awards` are **0**; the baseline is `''`.
- `team_harvest_levels` still holds **10** rows and the four settings still exist with their values
  (`enabled` keeps whatever the operator set).
- Neither counter is left behind: the next position and the next award both start at 1.

---

## 4. Evidence

Save each check's transcript under `docs/qa/evidence/phase-20/`, named exactly as its **Evidence**
line (`QA20A-01.txt` … `QA20A-08.txt`). The folder's `README.md` is the index and stays current as
each phase appends its section.

## 5. Cleanup

`QA20A-05` creates three QA members and a QA sponsor. The reset in `QA20A-08` removes them with every
other member; if the reset is skipped, delete the four `QA20A_*` accounts from **Members**. Do not
delete anything whose name does not start with `QA`.
