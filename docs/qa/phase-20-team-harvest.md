# EcoWealth — Phase 20 manual QA: Team Harvest bonus

**Scope.** Phase 20 of the member system (`dmp/plan-ecowealth-team-harvest.md`): a private 3×10
qualification matrix that pays a flat amount each time a member completes a level. The work is
delivered in seven gated phases, **20A → 20G**. This document is created by 20A with **section A
only**; every later phase appends its own section before its gate, and the file is never rewritten.

**Audience.** The developer/operator who runs the phase gate. Section A is surgical and moves **no
money**: it applies a migration, reads counts, opens two pages that must *not* have changed, and
runs the install/reset tools on scratch schemas. From section C on the checks may move test money,
and every one of those runs on a scratch schema — never on a production database.

**Document status.** Section A (20A — Foundation) written 2026-11-15 with the automated gate green;
section B (20B — Configuration) appended 2026-11-15 with the automated gate green. Section B moves no
money and writes no position. Section C (20C — The Harvest queue) appended 2026-11-15 with the
automated gate green: it stores positions, writes no award and moves no money.

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
| P20A-T16 | The phase is inert: empty tables, no producer and no money reader outside the reset engine (at 20A; 20B's settings card is now an allowed counter reader) |
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

---

# Section B — Phase 20B: Configuration (`QA20B-01` … `QA20B-09`)

**Prerequisites.** Tag `p20a-foundation` (or later) on `feature/team-harvest`; the development copy
migrated through `024` (**36 tables**, the four `team_harvest_*` settings, the ten ladder rows); the app
running (Laragon → Start All) for the page checks; a signed-in **admin** session; the audit desk
available at `/admin/audit`. Expected suite count for this section: **1,204**.

> **Notation.** As in section A: `mysql` is
> `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the app account, `php` is Laragon's PHP
> 8.3 CLI, and every command runs from `system/`.
>
> **Money.** Section B moves **no money**: the card writes the ten ladder amounts and three settings and
> nothing else. There is no queue row, no award and no wallet movement to clean up — but `QA20B-02` and
> `QA20B-04` do change the stored ladder, so both restore the reference amounts at the end (the card's
> own **Load reference ladder** button, then **Save settings**). A copy of the pre-check values is in
> `QA20B-01`'s transcript for a manual restore.

The automated pass, run before these checks, is:

```text
php tests\run.php                     → Cases: 1204   Passed: 1204   Failed: 0
php tests\run.php TeamHarvestConfiguration → Cases: 24   Passed: 24   Failed: 0
powershell -File tests/smoke.ps1      → Passed: 251   Failed: 0
```

### QA20B-01 — The card on a migrated database

| Why | Rule 1 (the reference ladder), Rule 5 (gate 3, depth 10), Rule 6 (the strip never claims the stream is live) |
|---|---|
| Where | `/admin/settings` → **Commissions**, and `mysql` |
| Evidence | `QA20B-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `admin`, open **System → Settings** (`/admin/settings`), scroll to **Commissions**, then
   below the Royalty block to **Team Harvest bonus**.
2. Read the switch, the two numbers and the ten amount inputs.
3. Read the status strip and the sentence under the ladder.
4. `SELECT key_name, value FROM settings WHERE key_name LIKE 'team\_harvest\_%' ORDER BY key_name;`
5. `SELECT level, amount FROM team_harvest_levels ORDER BY level;`
6. `SELECT COUNT(*) FROM harvest_queue;`

Expected

- The **Enable the Team Harvest bonus** switch is **off**; **Direct members needed to qualify** reads
  `3`; **Levels that pay** reads `10`.
- The ten inputs show `1000.00, 2000.00, 4000.00, 6000.00, 12000.00, 50000.00, 120000.00, 200000.00,
  1200000.00, 3500000.00`; the ladder table's cumulative column ends at **₱5,095,000.00**.
- The strip says **Not seeded — no position has been assigned** and names
  `bin/harvest_queue_seed.php`; the sentence says the full ladder pays **₱5,095,000.00 to one member**
  who gathers **88,572** qualified teammates.
- The four settings read `team_harvest_enabled = 0`, `team_harvest_qualify_directs = 3`,
  `team_harvest_max_levels = 10`, `team_harvest_ledger_since = ''`.
- Ten ladder rows with the reference amounts; `harvest_queue` is **0**.
- If it fails: check that migration `024` ran and that the page has no console error; a 500 here
  usually means the `team_harvest_levels` table is missing.

### QA20B-02 — A real ladder change is saved and audited

| Why | Rule 2 (amounts are money), Rule 9 (the card shows the new total), Rule 10 (history is not rewritten) |
|---|---|
| Where | `/admin/settings` → **Commissions**, `/admin/audit`, and `mysql` |
| Evidence | `QA20B-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Change **Level 1** to `1500` and **Level 10** to `4000000`.
2. Press **Save settings** on the Commissions card.
3. Re-open `/admin/settings#settings-commissions` and read the two inputs and the full-ladder total.
4. Open `/admin/audit`, filter the action `team_harvest.ladder.update`, open the newest row.
5. `SELECT level, amount, updated_by FROM team_harvest_levels WHERE level IN (1, 10);`
6. Restore: click **Load reference ladder**, then **Save settings**, and confirm the total returns to
   ₱5,095,000.00.

Expected

- The save is accepted; the inputs persist `1500.00` and `4000000.00`.
- The full-ladder total reads **₱5,595,500.00** and the ladder's cumulative column ends at it.
- The audit desk shows one `team_harvest.ladder.update` row whose payload holds the ten amounts and the
  three knobs, **before and after**, and names only `levels.1` and `levels.10` in `changes`.
- `updated_by` is the admin's id on all ten rows.
- Step 6 returns the total to **₱5,095,000.00**.
- If it fails: a missing audit row usually means the save changed nothing (compare the typed values
  with the stored ones); a total that does not move means the ten rows were not rewritten.

### QA20B-03 — The total ceiling is refused

| Why | Rule 3: the ten amounts may not total more than ₱25,000,000.00, refused as a `sum` error and never clamped |
|---|---|
| Where | `/admin/settings` → **Commissions**, and `mysql` |
| Evidence | `QA20B-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Note the stored ladder (`SELECT SUM(amount) FROM team_harvest_levels;`).
2. Set **Level 8**, **Level 9** and **Level 10** each to `10000000.00` (three levels at the per-level
   ceiling, **₱30,195,000.00** in total).
3. Press **Save settings**.
4. `SELECT SUM(amount) FROM team_harvest_levels;`

Expected

- The card shows one error: *“The ten amounts total ₱30,195,000.00 — the full ladder cannot exceed
  ₱25,000,000.00…”*, and the typed values come back in the inputs.
- The stored ladder is **unchanged** (step 4 equals step 1): a refused save writes neither the settings
  nor the ten rows.
- **Note for the tester:** the plan's original step (*“set level 10 to ₱20,000,000”*) is refused by the
  **per-level** ceiling first (Rule 2), because no single amount may exceed ₱10,000,000.00. The total
  ceiling is exercised with three levels, as above; the deviation is recorded in `docs/phase-log.md`.
- If it fails: if the save is accepted, the `sum` cross-field rule is not wired; if the stored ladder
  moved, the save is not atomic.

### QA20B-04 — Load reference ladder

| Why | Rule 9: the preset is offered, never applied by itself |
|---|---|
| Where | `/admin/settings` → **Commissions** |
| Evidence | `QA20B-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Clear three or four amounts by hand and press **Save settings** (the card accepts it).
2. Press **Load reference ladder**.
3. Read the ten inputs and the full-ladder total (do **not** save yet — confirm nothing was stored).
4. Press **Save settings**, then reload the page.

Expected

- Step 2 fills the ten inputs with `1000.00 … 3500000.00` and the live total reads **₱5,095,000.00**.
- Step 3 changes the form only; `SELECT SUM(amount) FROM team_harvest_levels;` still shows the cleared
  ladder until step 4 saves.
- Step 4 persists the reference ladder, and a reload shows the same ten values and total.
- If it fails: a button that does nothing usually means `app-team-harvest.js` is not loaded on the
  settings page; the server-side form still works, so this is a JavaScript-only failure.

### QA20B-05 — Enabling an all-zero ladder is refused

| Why | Rule 4: a stream that would pay nothing cannot be switched on |
|---|---|
| Where | `/admin/settings` → **Commissions**, and `mysql` |
| Evidence | `QA20B-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Press **Clear**, then **Save settings** with the switch **off** — the cleared ladder is accepted.
2. Tick **Enable the Team Harvest bonus** and press **Save settings**.
3. Read the error under the switch.
4. `SELECT value FROM settings WHERE key_name = 'team_harvest_enabled';`
5. Untick the switch and press **Save settings**; then restore the reference ladder and leave the
   switch off.

Expected

- Step 1 stores ten rows at `0.00` (blank = 0) and the card still saves.
- Step 2 is refused with the exact message **“Turn the switch off or give at least one level an amount
  above zero.”** on the switch, and step 4 still reads `0`.
- Nothing else moved: the ten rows are still `0.00` after the refusal.
- If it fails: an accepted all-zero enabled save is a Rule 4 defect — stop and report it.

### QA20B-06 — The gate and the depth

| Why | Rule 5: the gate and the depth are whole numbers 1–10; the depth marks the unpaid levels |
|---|---|
| Where | `/admin/settings` → **Commissions** |
| Evidence | `QA20B-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set **Direct members needed to qualify** to `11` and save: refused.
2. Set it back to `3`.
3. Set **Levels that pay** to `3` and save.
4. Read the ladder table's Depth column and each input's badge.
5. Set **Levels that pay** back to `10` and save.

Expected

- Step 1 is a field error on the gate (*“must be at most 10”*), and the stored value stays `3`.
- Step 3 is accepted; the saved-ladder table marks levels **4–10** *Not paid* and the inputs carry a
  **Not paid at this depth** badge, while levels 1–3 are **Paid**.
- The ten amounts are **kept**: level 10 still reads ₱3,500,000.00 even while it is not paid.
- Step 5 removes the markings.
- If it fails: an accepted `11`, or a depth that deletes amounts instead of marking them, is a Rule 5
  defect.

### QA20B-07 — Three registrations write no queue row

| Why | Rule 6 / P20B-T15: 20B ships no hook; registering members after configuring still writes nothing |
|---|---|
| Where | `/register` (or **Members**), and `mysql` |
| Evidence | `QA20B-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. With the reference ladder and the switch **on**, register three members under one sponsor through
   `/register` (the pending mode needs no package or code on the development copy).
2. `SELECT COUNT(*) FROM harvest_queue;`
3. `SELECT COUNT(*) FROM team_harvest_awards;`
4. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'team_harvest';`
5. Open the sponsor's member page and search it for **Harvest**.

Expected

- All three registrations succeed and appear under the sponsor.
- `harvest_queue` is **0**: no position was assigned (the hook is 20C).
- `team_harvest_awards` is **0** and no `team_harvest` wallet row exists.
- The member page shows **no** harvest panel, position or award line yet (20E).
- If it fails: a queue row here means the 20C hook leaked into 20B — the phase is not inert.

### QA20B-08 — The card saves with JavaScript disabled

| Why | Rule 12: no JavaScript-only validation |
|---|---|
| Where | `/admin/settings` → **Commissions**, with JavaScript disabled in the browser |
| Evidence | `QA20B-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Disable JavaScript for the site (or open the page in a browser profile with JS off).
2. Change **Level 2** to `2500` and press **Save settings**.
3. Re-open the page: the value persists and the cumulative column follows.
4. Enter `1000.1234` in a level and save: refused with the field error.
5. Restore level 2 to `2000.00` and save.

Expected

- The form posts and saves without JavaScript; no console error is needed for the save to work.
- Only the **live** cumulative/total re-read is missing — the server-rendered numbers are still shown.
- The validation is identical with JS off: the decimal error appears under the input.
- If it fails: a save that requires the script is a Rule 12 defect.

### QA20B-09 — The card at 390 px and 1440 px

| Why | Rule 11 / P20B-T18: readable on a phone without a page-level horizontal scroll |
|---|---|
| Where | `/admin/settings#settings-commissions` at a 390 px viewport and a 1440 px viewport |
| Evidence | `QA20B-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open the page at 1440 px wide: the two knobs and the ten amounts sit in two columns.
2. Narrow the window (or the device emulator) to 390 px.
3. Scroll the card: the ten inputs stack one per row; the six-column **Saved ladder** table scrolls
   inside its own card, not the page.
4. Check the page's own scrollbar: no horizontal scroll appears at page level.

Expected

- At 390 px every amount input is full-width in its own row; the saved-ladder table has its own
  horizontal scrollbar inside the card.
- No page-level horizontal scrollbar appears at either width.
- The status strip, the buttons and the sentence wrap without overflow.
- If it fails: note the offending element and its width in the evidence file; the automated case
  (`P20B-T18`) pins the grid and table markup, so this is usually a CSS/copy overflow.

---

## Evidence — section B

Save each check's transcript under `docs/qa/evidence/phase-20/`, named exactly as its **Evidence**
line (`QA20B-01.txt` … `QA20B-09.txt`). A page check that shows a screen is captured as a screenshot
only when the tester has a browser; the automated pass records the rendered markup and the counts as a
transcript, as section A did. The folder's `README.md` indexes the new rows.

## Cleanup — section B

Section B writes configuration and nothing else. `QA20B-02`/`04` restore the reference ladder; if a
manual run stopped midway, press **Load reference ladder** and **Save settings**, or run
`php bin/build_install_sql.php --check` and restore the ladder with the SQL in `QA20B-01`'s transcript.
`QA20B-07` creates three QA members; leave them (20B writes nothing about them) or delete the
`QA20B_*` accounts from **Members**. Do not delete anything whose name does not start with `QA`.

---

# Section C — Phase 20C: The Harvest queue (`QA20C-01` … `QA20C-08`)

**Prerequisites.** Tag `p20b-configuration` (or later) on `feature/team-harvest`; the development copy
migrated through `024` (**36 tables**, the four `team_harvest_*` settings, the ten ladder rows); a
**scratch schema** for every check that writes (`ecowealth_sneat_tool_a_test` / `_tool_b_test` are the
provisioned slots); the app running (Laragon → Start All) only for the page steps. Expected suite count
for this section: **1,234**.

> **Notation.** As in sections A and B: `mysql` is
> `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the app account, `php` is Laragon's PHP
> 8.3 CLI, and every command runs from `system/`. A command that must hit a different schema sets
> `ECO_DB_NAME` first (`$env:ECO_DB_NAME='ecowealth_sneat_tool_b_test'`), which outranks
> `config.local.php` and keeps the development copy untouched.
>
> **Money.** Section C moves **no money**. It writes positions, counters and the baseline, and
> `QA20C-07` proves that `team_harvest_awards` and the wallet are exactly where they were. There is
> nothing to clean up: `QA20C-08`'s business reset removes the test tree.
>
> **What section C changed in the app.** One line in `RegistrationService::register()` (the hook),
> `app/Models/HarvestQueue.php` and `bin/harvest_queue_seed.php`. No route, no page and no schema —
> `routes.php`, `routes_inventory.php` and `ParityTest` are untouched, and `APP_PHASE` is still 19.

The automated pass, run before these checks, is:

```text
php tests\run.php                          → Cases: 1234   Passed: 1234   Failed: 0
php tests\run.php HarvestQueue             → Cases: 30   Passed: 30   Failed: 0
php bin\harvest_queue_seed.php --help      → exit 0
powershell -File tests/smoke.ps1           → Passed: 251   Failed: 0
php bin\wallet_reconcile.php               → exit 0
php bin\build_install_sql.php --check      → exit 0
```

`QA20C-01` to `QA20C-08` were executed by the automated pass on `ecowealth_sneat_tool_b_test` and
captured as transcripts; the browser steps a human adds are named in each check's **Where** line. 20C
adds **no smoke check**, because it adds no route and no page.

### QA20C-01 — Three registrations write no queue row (the hook is inert)

| Why | Rule 2 / D-04: the hook returns `null` while `team_harvest_ledger_since` is empty, so a deploy can neither queue nor pay |
|---|---|
| Where | `/register` (or a scripted `RegistrationService::register()`), and `mysql` |
| Evidence | `QA20C-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build a scratch copy (or take the development copy) at `p20b-configuration` + this phase's code,
   **before** any seed. `team_harvest_ledger_since` must still be `''`.
2. Create the QA tree: `QA20C_Top` (three member directs: `QA20C_Mid`, `QA20C_T1`, `QA20C_T2`) and
   `QA20C_Mid` (three directs: `QA20C_Low`, `QA20C_M1`, `QA20C_M2`). `QA20C_Low` holds none.
3. Register three members under `QA20C_Low` through `/register` (standard mode needs no package).
4. `SELECT COUNT(*) FROM harvest_queue;` and `SELECT COUNT(*) FROM team_harvest_awards;`
5. `SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since';`

Expected

- All three registrations succeed, and `harvest_queue` is **0**: `QA20C_Low` now holds three directs and
  is **not** queued, because the queue is unseeded.
- `team_harvest_awards` is **0** and `team_harvest_ledger_since` is still `''` — nothing in the phase
  pretends the stream has started.
- The three members are ordinary `member` rows with their sponsor set; no page shows a position.
- If it fails: a queue row here means the inert guard is missing — a deploy could queue (and later pay)
  history before the baseline exists. Stop and report it.

### QA20C-02 — The seed dry run writes nothing

| Why | Rule 8 / D-07: the reconstruction is deterministic and previewable, and the baseline's cost is quantified before anyone decides |
|---|---|
| Where | PowerShell on `system/`, and `mysql` |
| Evidence | `QA20C-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\harvest_queue_seed.php --dry-run`
2. `SELECT COUNT(*) FROM harvest_queue;` and `SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since';`
3. `SELECT COUNT(*) FROM audit_log WHERE action = 'harvest.seed';`

Expected

- The summary names every member who holds the gate (on this fixture **3**: `QA20C_Low` crossed the gate
  during `QA20C-01` while the hook was inert, and the reconstruction catches it up — exactly the D-04
  behaviour), prints positions 1…N in qualification order, the first 20 rows, the baseline instant and
  the **excluded money** line (levels already complete at the baseline, with their total).
- `harvest_queue` is still **0**, `team_harvest_ledger_since` is still `''`, and there is **no**
  `harvest.seed` audit row: a dry run writes nothing at all.
- Exit code **0**.
- If it fails: a written row means the dry run is not dry. A summary that lists positions out of
  `(created_at, id)` order means the reconstruction rule drifted.

### QA20C-03 — The seed for real: positions, counters and the baseline

| Why | Rules 1, 8, 9, 12: `id` is the position, `baseline_n = teammates`, and `qualified_at` is the gate-th direct's instant |
|---|---|
| Where | PowerShell on `system/`, and `mysql` |
| Evidence | `QA20C-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\harvest_queue_seed.php` → exit **0**.
2. `SELECT id, user_id, teammates, baseline_n, directs_at_qualification, source, qualified_at FROM harvest_queue ORDER BY id LIMIT 20;`
3. `SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since';`
4. `SELECT COUNT(*) FROM team_harvest_awards;`

Expected

- A contiguous `1 … N` with no gaps and no renumbering; on this fixture `QA20C_Top` is **#1**
  (`teammates 2, baseline_n 2`), `QA20C_Mid` **#2** (`1, 1`), `QA20C_Low` **#3** (`0, 0`).
- `baseline_n = teammates` on **every** row the seed wrote, `source = 'seed'`, and
  `directs_at_qualification` is the gate (**3**) — the count at the qualifying instant, not today's
  larger count.
- `qualified_at` is the gate-th direct's `created_at` (`2026-11-01 09:02:00` for Top, `2026-11-01
  09:04:00` for Mid), not the seed instant.
- `team_harvest_ledger_since` is now a `DATETIME`; `team_harvest_awards` is still **0**.
- If it fails: a non-contiguous sequence or a `baseline_n` that differs from `teammates` is a Rule 1 /
  Rule 9 defect. A `team_harvest_awards` row means 20C paid something — it must not.

### QA20C-04 — A second seed writes nothing

| Why | Rule 8 / §14.10 step 5: a real run on a non-empty queue is refused, so a go-live script cannot re-seed history |
|---|---|
| Where | PowerShell on `system/`, and `mysql` |
| Evidence | `QA20C-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\harvest_queue_seed.php` again → exit **1**.
2. Read the refusal message.
3. `SELECT id, teammates, baseline_n FROM harvest_queue ORDER BY id;`
4. `SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since';`
5. `SELECT COUNT(*) FROM audit_log WHERE action = 'harvest.seed';`

Expected

- The message names the instant and the stored count: *“The queue is already seeded
  (team_harvest_ledger_since = …) and holds 3 position(s); use --dry-run to preview or --force to
  append the 0 member(s) that are missing.”*
- Zero rows written, every position and baseline unchanged, **one** audit row (not two).
- Exit **1** is correct and deliberate: a non-zero exit is how a go-live script learns that the seed did
  not run again.
- If it fails: a second audit row or a moved position means the idempotency guard is missing.

### QA20C-05 — A live qualification appends at the end and moves every queued ancestor

| Why | Rules 3, 7: the hook runs inside the registration, appends after the existing positions and increments every queued ancestor at any depth |
|---|---|
| Where | `/register` (or a scripted registration), and `mysql` |
| Evidence | `QA20C-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Note the queue (`SELECT id, user_id, teammates FROM harvest_queue ORDER BY id;`).
2. Register three members under `QA20C_New_1` — a member with no directs who sits below the queued
   `QA20C_Low`.
3. `SELECT id, user_id, teammates, baseline_n, source FROM harvest_queue ORDER BY id;`
4. Compare each member's `teammates` with step 1.
5. `php bin\harvest_queue_seed.php --rebuild --dry-run`

Expected

- `QA20C_New_1` appears at **the end** (position 4), `teammates 0`, `baseline_n 0`,
  `source = 'registration'`, `qualified_at` = the third registration's instant.
- Every queued ancestor moved by **exactly one**: `QA20C_Low 0 → 1`, `QA20C_Mid 1 → 2`,
  `QA20C_Top 2 → 3`. Nobody else moved.
- Step 5 reports **0 drift**: the increment is the recount.
- If it fails: a newcomer inserted before an existing position breaks D-03 (positions are facts); an
  ancestor that moved by two means the walk counted the same event twice; no movement at all means the
  hook did not run inside the registration.

### QA20C-06 — The rebuild drill: report the drift, repair only with `--apply`

| Why | Rule 10 / §14.4: the counter is maintained, so it needs a proof — the recount is the authority and a repair never renumbers |
|---|---|
| Where | PowerShell on `system/`, and `mysql` |
| Evidence | `QA20C-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\harvest_queue_seed.php --rebuild --dry-run` → **0 drift**, exit **0**.
2. Edit one counter by hand:
   `UPDATE harvest_queue SET teammates = teammates + 5 WHERE user_id = <QA20C_Mid>;`
3. `php bin\harvest_queue_seed.php --rebuild` → report only.
4. `php bin\harvest_queue_seed.php --rebuild --apply`
5. `SELECT id, teammates FROM harvest_queue ORDER BY id;`
6. `SELECT action, meta_json FROM audit_log WHERE action = 'harvest.queue.repair';`

Expected

- Step 1 (`--dry-run`) reports `drift: 0 counter(s)` and `missing: 0 member(s)`.
- Step 3 names the member (`#2 @QA20C_Mid stored 7  recount 2`), writes **nothing**, exits **1**.
- Step 4 reports `repaired: 1 counter(s)`, exits **0**; the counter is back to `2` and **every position
  is unchanged** (1…4).
- Step 6 holds exactly one `harvest.queue.repair` row with `{"repaired":[{"before":7,"after":2,…}]}` and
  `"appended":[]`.
- If it fails: a repair that renumbers a position is a Rule 1 violation; a repair applied without
  `--apply` is a Rule 10 violation.

### QA20C-07 — The whole phase moves no money

| Why | Rule 11 / P20C-T22: 20C writes no award, no wallet row and no balance; the money is 20D |
|---|---|
| Where | `mysql` (or the reconciler) |
| Evidence | `QA20C-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT COUNT(*) FROM team_harvest_awards;`
2. `SELECT ref_type, COUNT(*), COALESCE(SUM(amount),0) FROM ewallet_ledger GROUP BY ref_type;`
3. `SELECT COALESCE(SUM(ewallet_balance),0), COALESCE(SUM(withdrawable_balance),0) FROM users;`
4. `SELECT COUNT(*) FROM team_harvest_levels; SELECT SUM(amount) FROM team_harvest_levels;`
5. `php bin\wallet_reconcile.php`

Expected

- `team_harvest_awards` is **0** and no `ewallet_ledger` row has `ref_type = 'team_harvest'`.
- Both wallet-bucket totals are exactly what they were before the section ran (on a fresh scratch copy:
  `0.00`), and `wallet_reconcile.php` exits **0**.
- The ladder still holds its **10** rows totalling **₱5,095,000.00**, and `team_harvest_enabled` is
  still `0`: the phase never touched the configuration either.
- If it fails: any `team_harvest` ledger row or a changed balance is a phase-boundary violation — 20C
  must not pay.

### QA20C-08 — A business reset empties the queue, clears the baseline and keeps the ladder

| Why | 20A Rule 8 / §13.8 I-09: a reset removes the positions and the baseline instant, keeps the catalogue, and restarts the counter at 1 |
|---|---|
| Where | the reset console or `bin\db_reset.php` on a scratch schema |
| Evidence | `QA20C-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Note the queue and the settings (`SELECT id, teammates, baseline_n FROM harvest_queue;`).
2. `php bin\db_reset.php --dry-run` → the plan carries a **harvest** step.
3. `php bin\db_reset.php --force --confirm=<database>`
4. `SELECT COUNT(*) FROM harvest_queue; SELECT COUNT(*) FROM team_harvest_awards; SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since'; SELECT COUNT(*) FROM team_harvest_levels;`
5. Re-create a two-level QA tree and run `php bin\harvest_queue_seed.php` again.
6. `SELECT id, teammates, baseline_n FROM harvest_queue ORDER BY id;`

Expected

- The dry run lists `harvest  Team Harvest queue positions and level awards` and writes nothing.
- After the reset `harvest_queue` and `team_harvest_awards` are **0**, the baseline is `''`, the ten
  ladder rows survive with their amounts, and the four settings keep their values (the switch keeps
  whatever the operator set).
- The next seed's first position is **#1** again, with `baseline_n = teammates` — the reset restarted
  the counter, and no position from the old network survives.
- If it fails: a surviving queue row means the reset's harvest group is missing; a baseline that is
  still a date would let a fresh network pay against a dead baseline.

---

## Evidence — section C

Save each check's transcript under `docs/qa/evidence/phase-20/`, named exactly as its **Evidence** line
(`QA20C-01.txt` … `QA20C-08.txt`). Every check is a CLI/mysql check, so all eight are text transcripts;
the browser steps a human adds (`/register` in 01 and 05) reuse the same commands. The folder's
`README.md` indexes the new rows.

## Cleanup — section C

`QA20C-01` creates three members and `QA20C-05` three more, all named `QA20C_*`; `QA20C-08`'s business
reset removes them with every other member. If the reset is skipped, drop the scratch schema
(`DROP DATABASE ecowealth_sneat_tool_b_test;` and rebuild it with `php bin\db_install.php`) — never
leave a seeded queue on a database whose members you keep. Do not delete anything whose name does not
start with `QA`.


---

# Section D — Phase 20D: Money engine (`QA20D-01` … `QA20D-10`)

**Prerequisites.** Tag `p20c-queue` (or later) on `feature/team-harvest`; a **scratch schema** for every
check that writes (`ecowealth_sneat_tool_a_test` / `_tool_b_test` are the provisioned slots); the
development copy migrated through `024` and **never** to be used for this section; the app running
(Laragon → Start All) for the two wallet-screen checks. Expected suite count for this section:
**1,275**.

> **Notation.** As in sections A–C: `mysql` is the Laragon client with the app account, `php` is
> Laragon's PHP 8.3 CLI, and every command runs from `system/`. A command that must hit a different
> schema sets `ECO_DB_NAME` first (`$env:ECO_DB_NAME='ecowealth_sneat_tool_b_test'`).
>
> **Money.** This is the **only** section that moves money. Every check runs on a scratch schema; the
> last check restores a deleted ledger row from a dump. `team_harvest_enabled` ships **off** and this
> section never enables it on the development copy.
>
> **What section D changed in the app.** `app/Models/TeamHarvestAward.php`,
> `app/Services/TeamHarvestService.php`, `app/Services/TeamHarvestException.php`, two methods in
> `app/Services/Live.php`, the one-line registration hook in `RegistrationService`, the integrity map in
> `DatabaseResetService`, `bin/team_harvest_catchup.php` (+ `.cmd`) and the seventh section of
> `bin/wallet_reconcile.php`. No route, no page and no schema: `routes.php`, `routes_inventory.php`
> and `ParityTest` are untouched, `APP_PHASE` is still 19, and the smoke count stays 251.

The automated pass, run before these checks, is:

```text
php tests\run.php                          → Cases: 1275   Passed: 1275   Failed: 0
php tests\run.php TeamHarvestEngine        → Cases: 28   Passed: 28   Failed: 0
php tests\run.php TeamHarvestCatchup       → Cases:  6   Passed:  6   Failed: 0
php tests\run.php TeamHarvestReconcile     → Cases:  5   Passed:  5   Failed: 0
php bin\team_harvest_catchup.php --help    → exit 0
php bin\wallet_reconcile.php               → exit 0, Team Harvest section clean
php bin\build_install_sql.php --check      → exit 0
powershell -File tests/smoke.ps1           → Passed: 251   Failed: 0
```

`QA20D-01`, `04`, `05`, `06`, `07`, `08`, `09` and `10` were executed by the automated pass on
`ecowealth_sneat_tool_b_test` and captured as transcripts under `docs/qa/evidence/phase-20/`;
`QA20D-02` and `QA20D-03` are wallet screenshots and need the application in a browser (their
**Where** line says which screen). 20D adds **no smoke check**, because it adds no route and no page (20G accumulates the Phase 20
ones).

## The checks in one table

| Check | What it proves | Where |
|---|---|---|
| `QA20D-01` | A completed level pays its amount once, into the wallet, with an immutable row | scratch schema + `mysql` |
| `QA20D-02` | The member sees the credit in `/wallet` and the statement filter matches it | browser + `mysql` |
| `QA20D-03` | The admin wallet monitor shows the same movement and its award id | browser + `mysql` |
| `QA20D-04` | The reconciler reports the Team Harvest section and exits 0 | `php bin\wallet_reconcile.php` |
| `QA20D-05` | The plan's ladder is paid exactly: ₱7,000 root, ₱3,000 children, ₱1,000 grandchildren, ₱25,000 network, ₱13,000 fixture-B root | suite transcript + `mysql` |
| `QA20D-06` | A replay moves no money: a fourth direct writes no award and no credit | scratch schema + `mysql` |
| `QA20D-07` | The switch off means no money, a growing queue, and one catch-up that pays the backlog once | scratch schema + the job |
| `QA20D-08` | A level complete at go-live is recorded as `baseline` and never paid; only the later level credits | scratch schema + `mysql` |
| `QA20D-09` | The doctor is green on a clean build and names every check | `php bin\team_harvest_catchup.php --doctor` |
| `QA20D-10` | A hand-deleted movement is caught with its award and fails the build | scratch schema + the reconciler |

## The 13-account live fixture (used by 01, 02, 06, 07)

A tree that pays exactly one level, small enough to build by hand, and the shape the rest of this
section reuses. `QA20D_Top` is queued by the **seed** while it holds three directs (`n = 0`); its three
directs then each receive three members **through the real registration hook**, so they qualify after
go-live and `QA20D_Top` reaches `n = 3` — level 1 completes after the baseline and pays ₱1,000.00.

```sql
-- On the scratch schema, before the seed: the top and its three directs.
INSERT INTO users (username, email, password_hash, role, member_status, sponsor_id, referral_code,
                   is_active, created_at)
VALUES ('QA20D_Top', 'qa20d_top@qa.test', 'x', 'member', 'active', NULL,
        'QA20D0001', 1, '2026-11-01 08:00:00');

INSERT INTO users (username, email, password_hash, role, member_status, sponsor_id, referral_code,
                   is_active, created_at)
SELECT CONCAT('QA20D_D', n), CONCAT('qa20d_d', n, '@qa.test'), 'x', 'member', 'active', id,
       CONCAT('QA20D001', n), 1, TIMESTAMPADD(MINUTE, n, '2026-11-01 09:00:00')
  FROM users JOIN (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3) AS d
 WHERE username = 'QA20D_Top';

-- The seed queues QA20D_Top with n = 0 and baseline 0; the directs are not qualified yet.
```

Then each direct receives three accounts through `/register` (or a scripted
`RegistrationService::register()`), which is what makes them qualify. The 13 accounts are: the top, its
three directs and nine grandchildren.

### QA20D-01 — A completed level pays its amount once

| Why | Rules 1–5, D-06/D-11: `n ≥ threshold(L)` is the whole test, and the credit is withdrawable immediately |
|---|---|
| Where | scratch schema, `mysql`, and `php bin\team_harvest_catchup.php` |
| Evidence | `QA20D-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build the 13-account fixture above; seed (`php bin\harvest_queue_seed.php`), then turn the stream on:
   `UPDATE settings SET value = '1' WHERE key_name = 'team_harvest_enabled';`
2. Register the nine grandchildren through `/register` (three under each direct).
3. `SELECT level, threshold_n, n_at_award, amount, status, ledger_id FROM team_harvest_awards WHERE user_id = <QA20D_Top>;`
4. `SELECT ewallet_balance, withdrawable_balance FROM users WHERE username = 'QA20D_Top';`
5. `SELECT type, amount, ref_type, ref_id, note FROM ewallet_ledger WHERE user_id = <QA20D_Top>;`

Expected

- Step 3: **one** row — `level 1`, `threshold_n 3`, `n_at_award 3`, `amount 1000.00`, `status credited`,
  `ledger_id` set. No level-2 row: the block is `0 of 9` full.
- Step 4: `1000.00 / 1000.00` — the award is withdrawable earnings, not a hold.
- Step 5: one `credit` of `1000.00` with `ref_type = team_harvest`, `ref_id` = the award id, and a note
  naming level 1.
- If it fails: a second row for level 1 means the unique anchor or the pre-check is missing (D-06); a
  `earned` figure with no wallet movement means the pair is not atomic (§14.7).

### QA20D-02 — The member sees the credit in `/wallet`

| Why | §12.7 Rule 6: the money is in the e-wallet the second the transaction commits |
|---|---|
| Where | browser as `QA20D_Top`, and `mysql` |
| Evidence | `QA20D-02.png` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `QA20D_Top` (set a password on the scratch copy first, or use the S-Login console).
2. Open `/wallet`. The statement lists one **Team Harvest bonus** credit of **₱1,000.00** dated today.
3. Use the statement's movement filter: choose **Team Harvest bonus** and apply.
4. `SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = <QA20D_Top> AND ref_type = 'team_harvest';`

Expected

- One row, **₱1,000.00**, note *Team Harvest level 1 bonus*; the filter returns exactly it and the
  running balance agrees with the header.
- If it fails: a missing option means the filter was not regenerated from `WalletService::REF_TYPES`
  (20A Rule 6); a row with no note or the wrong `ref_type` means the engine did not use the wallet API.

### QA20D-03 — The admin wallet monitor shows the same movement

| Why | §14.13: every number on a page has a ledger row behind it |
|---|---|
| Where | browser as `admin`, and `mysql` |
| Evidence | `QA20D-03.png` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As `admin`, open `/admin/wallets` and search `QA20D_Top`.
2. Open the member's wallet detail. The movement list shows the **Team Harvest bonus** credit.
3. Hover/read the reference: `ref_type = team_harvest`, `ref_id` = the award id from `QA20D-01`.

Expected

- The same amount and instant as `QA20D-02`; the member's two buckets match `/wallet`.
- If it fails: an admin page that omits the movement means the statement renders its own ref-type list
  instead of the wallet model.

### QA20D-04 — The reconciler reports the Team Harvest section

| Why | §14.13 and D-19: the money ships with its proof, not with a promise |
|---|---|
| Where | PowerShell on `system/` |
| Evidence | `QA20D-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\wallet_reconcile.php`
2. `php bin\wallet_reconcile.php --json` and read the `team_harvest` block.

Expected

- Exit **0** and a **Team Harvest** section: `queued positions`, `credited awards` (₱1,000.00 on the
  13-account fixture), `baseline awards 0`, `counter drift 0 row(s) · 0 below the baseline`, and the
  line *every credited award has its wallet movement, every baseline row is unpaid, and the counters
  agree with a recount*.
- The `--json` block carries `ok: true`, `credited_total: 1000.00`, `ledger_total: 1000.00`, `drift: 0`.
- If it fails: a mismatch names the award and the two amounts — that is a books error; restore the
  backup and report it rather than editing a row.

### QA20D-05 — The plan's ladder is paid exactly

| Why | §16.3: every amount is pinned to the centavo; a "close enough" payout is a defect |
|---|---|
| Where | the suite transcript, and `mysql` on the scratch copy |
| Evidence | `QA20D-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php tests\run.php TeamHarvestEngine` → **28 / 28**.
2. Read the PASS lines for **P20D-T2** (fixture A's root: 1,000 + 2,000 + 4,000 = 7,000), **P20D-T3**
   (each child: 3,000), **P20D-T4** (each grandchild: 1,000), **P20D-T6** (fixture B root: 13,000) and
   **P20D-T7** (the network: 18 rows, ₱25,000.00, 13 members).
3. On the scratch copy, compare the level distribution with the plan's Appendix A:
   `SELECT level, COUNT(*) AS awards, SUM(amount) AS total FROM team_harvest_awards WHERE status = 'credited' GROUP BY level ORDER BY level;`

Expected

- The suite's 28 PASS lines include the five cases above, each named with its amount.
- The distribution on the scratch copy is the 13-account fixture's single level: `1 | 1 | 1000.00`
  (fixture A's `13 / 4 / 1` shape is asserted by P20D-T7; the 121-account tree is not buildable by
  hand and is evidence by case name, exactly as 20C treated its 40-position fixture).
- If it fails: an amount one peso out means a rounding or a snapshot bug — the ladder is flat and there
  is nothing to round (§12.9), so any difference is a defect.

### QA20D-06 — A replay moves no money

| Why | Rule 5, §14.6: a level pays once, ever, and a replay is not an event |
|---|---|
| Where | scratch schema, `mysql`, and/or the suite |
| Evidence | `QA20D-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On the 13-account fixture (after `QA20D-01`), register a **fourth** direct under `QA20D_Top`, then
   three members under one of the directs so another of its directs qualifies.
2. `SELECT COUNT(*) FROM team_harvest_awards;`
3. `SELECT COALESCE(SUM(amount),0) FROM ewallet_ledger WHERE ref_type = 'team_harvest';`
4. `php bin\team_harvest_catchup.php --json` (a real run) and read `result.paid`.

Expected

- The award count and the wallet total are exactly what `QA20D-01` left; the catch-up reports
  `paid: 0` and writes no new row.
- The fourth direct moved `QA20D_Top`'s `n` to 4 — the block is still `4 of 9`, so nothing is complete.
- If it fails: a second row for level 1, or a second movement with the same `ref_id`, is a double pay.

### QA20D-07 — The switch off, then one catch-up pays the backlog once

| Why | D-09 and §14.14: the queue grows while the money is off, and the backlog is paid deliberately |
|---|---|
| Where | scratch schema, `mysql`, and the job |
| Evidence | `QA20D-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a fresh scratch copy, seed the queue with the stream **off** (`team_harvest_enabled = '0'`).
2. Register the nine grandchildren (as in `QA20D-01`). `SELECT COUNT(*) FROM harvest_queue;` and
   `SELECT COUNT(*) FROM team_harvest_awards;`
3. Turn the switch on: `UPDATE settings SET value = '1' WHERE key_name = 'team_harvest_enabled';`
4. `php bin\team_harvest_catchup.php --dry-run` — read the liability.
5. `php bin\team_harvest_catchup.php` — pay it.
6. Run step 5 again.

Expected

- Step 2: the queue holds **4** positions (the top and its three directs; the nine grandchildren are
  member accounts who have not qualified) and `team_harvest_awards` is **0** — the structure recorded,
  the money did not move.
- Step 4: **1 level, 1 member, ₱1,000.00**, nothing written.
- Step 5: `paid: 1`, ₱1,000.00, and one `team_harvest.catchup` audit row.
- Step 6: `paid: 0` — the same levels evaluate to nothing, and the wallet does not move again.
- If it fails: money while the switch is off breaks D-09; a second payment on step 6 breaks D-19.

### QA20D-08 — A level complete at go-live is recorded, never paid

| Why | D-07: the company never agreed to pay for a period before the stream existed |
|---|---|
| Where | scratch schema, `mysql`, and the job |
| Evidence | `QA20D-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a fresh scratch copy build a tree where `QA20D_B_Top` already holds **12 qualified teammates**
   at the seed: the top, three directs, nine grandchildren, and three leaf directs under each
   grandchild (so the grandchildren qualify). Run the seed.
2. `SELECT teammates, baseline_n FROM harvest_queue WHERE user_id = <QA20D_B_Top>;` → `12 | 12`.
3. Turn the switch on, then grow the same tree past the baseline: under each of the nine grandchildren
   add three members that each hold three directs (27 new qualifying members) — through `/register`.
4. `SELECT level, threshold_n, n_at_award, amount, status, ledger_id FROM team_harvest_awards WHERE user_id = <QA20D_B_Top> ORDER BY level;`
5. `SELECT withdrawable_balance FROM users WHERE username = 'QA20D_B_Top';`

Expected

- Step 2: `teammates 12`, `baseline_n 12`.
- Step 4: three rows — level 1 and level 2 `status baseline` with their amounts (1,000.00 / 2,000.00)
  and `ledger_id NULL`; level 3 `status credited`, amount **4,000.00**, `n_at_award 39`.
- Step 5: **4,000.00**, never 7,000.00.
- If it fails: a `baseline` row with a ledger reference is pre-go-live money (D-07) — stop and report it.

### QA20D-09 — The doctor is green

| Why | §14.11: a state-based engine needs a health report an operator can run before trusting it |
|---|---|
| Where | PowerShell on `system/` |
| Evidence | `QA20D-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On the seeded scratch copy: `php bin\team_harvest_catchup.php --doctor`
2. `php bin\team_harvest_catchup.php --doctor --json`
3. Hand-edit one counter (`UPDATE harvest_queue SET teammates = teammates + 5 WHERE user_id = <id>;`)
   and run the doctor again; then put it back.

Expected

- Exit **0** and nine green lines: `clock`, `tables`, `settings`, `seed`, `baseline`, `queue`, `awards`,
  `drift`, `reconcile`, with the queue count, the credited total and *The doctor is green*.
- After the edit: exit **1**, the `drift` line reports *1 counter(s) disagree with a recount*, and the
  reconcile line reports the mismatch; **nothing is repaired** (the doctor is read-only). Putting the
  counter back returns exit 0.
- If it fails: a red doctor on a clean build is a defect in the phase; a doctor that repairs something
  is a design violation (repair is `harvest_queue_seed.php --rebuild --apply`).

### QA20D-10 — A deleted movement is caught and fails the build

| Why | Rule 14 / §15.2: the reconciler is part of the money, not an add-on |
|---|---|
| Where | scratch schema and the reconciler |
| Evidence | `QA20D-10.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Dump the scratch schema (`mysqldump`).
2. Delete one movement: `DELETE FROM ewallet_ledger WHERE id = <the credit's id>;`
3. `php bin\wallet_reconcile.php`
4. `php bin\wallet_reconcile.php --json` and read the `team_harvest` block.
5. Restore from the dump and re-run step 3.

Expected

- Step 3: exit **1**, with a line naming the award, the member and the amount
  (`[missing_ledger] a credited Team Harvest award has no wallet movement`).
- Step 4: `ok: false`, `mismatches` carries `missing_ledger` for that award and a `total` mismatch.
- Step 5: exit **0** again. **No repair was attempted** by the tool: a deleted movement is restored from
  a backup, never invented.
- If it fails: a reconciler that still exits 0 has lost its Team Harvest section from the exit code.

---

## Evidence — section D

Save each check's transcript (or the two screenshots, `QA20D-02.png` / `QA20D-03.png`) under
`docs/qa/evidence/phase-20/`, named exactly as its **Evidence** line. The index in that folder's
`README.md` gains the ten rows.

## Cleanup — section D

This section writes **real money** into a scratch schema. Either drop the schema
(`DROP DATABASE ecowealth_sneat_tool_b_test;` and rebuild it with `php bin\db_install.php`) or run the
business reset (`php bin\db_reset.php --force --confirm=ecowealth_sneat_tool_b_test`), which empties the
queue and the awards, clears the baseline, keeps the ten ladder rows and leaves
`team_harvest_enabled` as the operator set it. Do **not** run any step of this section against
`ecowealth_sneat`: an award there is real money in a real wallet.

If `QA20D-10` deleted a row and the dump was lost, the award keeps its `ledger_id` pointing at nothing —
the reconciler will report it for ever. Restore the pre-check backup instead of hand-inserting a row.
