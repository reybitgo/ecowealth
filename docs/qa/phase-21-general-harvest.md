# EcoWealth — Phase 21 manual QA: General Harvest bonus

**Scope.** Phase 21 of the member system (`dmp/plan-ecowealth-general-harvest.md`): one **shared 3-ary
board** over the Harvest queue Phase 20 built. Every Harvest-qualified member holds exactly one node
(`seq = harvest_queue.id − 1`); when the whole-network count `N` reaches a block's last position the
node's holder is paid a flat amount for that level, once ever, as a withdrawable e-wallet credit. The
work is delivered in seven gated phases, **21A → 21G**. This document is created by 21A with
**section A only**; every later phase appends its own section before its gate, and the file is never
rewritten.

**Audience.** The developer/operator who runs the phase gate. Section A is surgical and moves **no
money**: it applies a migration, reads counts, opens two pages that must *not* have changed, and runs
the install/reset tools on scratch schemas. From section C on the checks may arm a board and move test
money, and every one of those runs on a scratch schema — never on a production database.

**Document status.** Section A was written by 21A (2026-11-16) with the automated gate green. Sections
B–G are appended by their phases. **8 checks** in section A. Every check has an evidence file under
`docs/qa/evidence/phase-21/`, indexed by that folder's `README.md`; a check whose only output is a
count, a SQL row or a rendered fragment is a text transcript, and the browser-only steps are recorded
there as harness transcripts at the markup level (the note in the evidence index says which ones a real
browser still has to confirm).

---

## 1. What is already automated

Run these first, from `system/`. If any fails, stop and report before starting the manual pass.

```powershell
php bin\build_install_sql.php --check     # exit 0, no output: the bundle matches its sources
php tests\run.php                         # full suite
powershell -NoProfile -File tests\smoke.ps1
php bin\wallet_reconcile.php              # exit 0: this phase moves no money
```

Expected final lines at `p21a-foundation`:

```text
Cases: 1336   Passed: 1336   Failed: 0
All good.
```

```text
Passed: 263   Failed: 0
```

The baseline was **1,320 / 1,320** PHP cases and **263 / 263** smoke checks at `p21-start` (the Phase 20
release gate); 21A adds **16** cases and **no** smoke check, because it adds no route and no page.

21A's share of the PHP suite (`GeneralHarvestFoundationTest`, 15 cases, plus one case in
`MigrationTest`):

| Case | What it proves |
|---|---|
| P21A-T1 | Migration `025` creates the three tables, their anchors, foreign keys and CHECKs, the extended enum, the two settings, the ten reference amounts and one unarmed board row; the column lists are exactly the manual gate's lists; no backfill |
| P21A-T2 | A second run is a no-op, keeps an operator's amount and setting, recreates a lost table and **never re-arms a board** |
| P21A-T3 | (`MigrationTest`) the migrated schema equals a fresh install for the three tables and the ENUM, `025` is in the ordered migration list, and a re-run disturbs neither an edited amount nor an armed board |
| P21A-T4 | Seed 001 carries the two keys, the ten ladder rows and the unarmed board row, exactly like the migration |
| P21A-T5 | `install.sql` matches its sources byte for byte; header and footer say **39 tables / 79 settings** with 10 ladder rows and 1 board row; the bundle creates 39 tables |
| P21A-T6 | A fresh install reports **39 / 79 / 2 / 0 / 7 / 2 / 18 / 10 / 0 / 10 / 1**, with the stream off and the board unarmed |
| P21A-T7 | The transactional guard fails with `general_harvest_awards=1` named, and an **armed** board fails its own check; the ladder and an unarmed board stay non-transactional |
| P21A-T8 | A business reset empties the award ledger, returns the board to `(NULL, NULL, NULL, 0)`, keeps the ten ladder rows and the two settings, and restarts the counter |
| P21A-T9 | `WalletService::REF_TYPES` and the live ENUM agree in both directions — **13** values, `general_harvest` last; an unknown value is refused before any write |
| P21A-T10 | `general_harvest` labels as **General Harvest bonus** with `bx-grid-alt`; every movement kind has a label and a `bx-` icon; no ledger row carries the value yet |
| P21A-T11 | The two defaults on a fresh and a migrated database; the stream is off and the board unarmed; the migration never stamps "now" and never reads `users` |
| P21A-T12 | The ten reference amounts, level by level, total **₱8,381,000.00**, and an operator's edit survives a re-run |
| P21A-T13 | A second board row is refused by `chk_ghb_singleton` **and** by the primary key; the armed/settled invariants hold; deleting the arming staff member clears `armed_by` but leaves the board armed |
| P21A-T14 | Deleting a member who holds an award cascades the ledger; the `(seq, level)` anchor still accepts a new holder |
| P21A-T15 | Only the four manifest files (`WalletService`, `helpers.php`, `DatabaseInstaller`, `DatabaseResetService`) name the new objects; no model, service, controller, view or job exists |
| P21A-T16 | The phase adds no route and no page: the route files and every view are free of the feature, the stream is off, and the schema/reset/bundle counts all agree at 39 |

No automated case in this phase writes an award row, a queue row or a `general_harvest` wallet row on
the development database. The HTTP walk uses the development copy and restores every setting it
touches.

---

## 2. How to read a check

Every check has the same shape:

| Field | Meaning |
|---|---|
| **Why** | the phase rule or contract the check proves |
| **Where** | the exact shell, URL or SQL client |
| **Evidence** | the file name under `docs/qa/evidence/phase-21/` |
| **Result** | `☐ Pass  ☐ Fail  ☐ Blocked` — replace the box |

A check that produces only counts is captured as a **text transcript**, pasted into its evidence file;
a check that shows a browser screen is captured as a screenshot. Section A produces only text.

---

## 3. Section A — Phase 21A: Foundation (`QA21A-01` … `QA21A-08`)

**Prerequisites.** Tag `p21-start` on `feature/general-harvest` (the Phase 20 release, `p20g-release`);
the development copy at migration **024** (**36 tables, 74 settings** — it is migration-built, so it
carries no `install_*` metadata rows; a fresh install at `p21-start` holds 77 settings); the app running
(Laragon → Start All) for the page checks; the scratch schemas `ecowealth_sneat_tool_a_test` and
`ecowealth_sneat_tool_b_test` provisioned (they are recreated by the tools themselves). Expected suite
count for this section: **1,336**.

> **Notation.** `mysql` below means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the
> app account; `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`.
>
> **Where each check runs.** `QA21A-01` takes the backup. `QA21A-02`, `03`, `07` and `08` run on
> scratch schemas. `QA21A-04`, `05` and `06` need the running application, which reads the development
> copy: apply migration `025` to `ecowealth_sneat` once, then run them. The backup from `QA21A-01` is
> the rollback. Nothing the pages do writes a General Harvest row, and `QA21A-05` is run on a scratch
> schema so the development copy gains no QA member.

### QA21A-01 — Back up the development copy and confirm it is at migration 024

| Why | 21A is the first schema change of Phase 21; the standing rule is a dump outside the repository before any migration. |
|---|---|
| Where | PowerShell + `mysqldump` |
| Evidence | `QA21A-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysqldump -u ecowealth -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-21\ecowealth_sneat-pre-21a-<stamp>.sql`
2. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM ecowealth_sneat.settings"`
4. `mysql -u ecowealth -p -e "SHOW TABLES LIKE 'general%'" ecowealth_sneat`

Expected

- The dump exists, is non-empty and lives **outside** the repository.
- **36** tables and **74** settings (the development copy was built by migrations `001`–`024`; a fresh
  install at `p21-start` holds **77** settings).
- `SHOW TABLES` returns **no row**: the feature does not exist yet.

### QA21A-02 — Apply migration 025 twice to a p21-start schema

| Why | Rule 1: the migration is additive and idempotent. A second run must be a no-op that never re-arms a board. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_b_test` |
| Evidence | `QA21A-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build a **p21-start** scratch schema from the previous tag's bundle, not the current one:
   `git show p20g-release:system/database/install.sql | mysql -u ecowealth -p ecowealth_sneat_tool_b_test`
2. Confirm it is the previous shape: `SHOW TABLES LIKE 'general%'` → no row; **36 tables, 77 settings**.
3. `mysql -u ecowealth -p ecowealth_sneat_tool_b_test < database/migrations/025_general_harvest.sql`
4. `mysql -u ecowealth -p -e "SHOW TABLES LIKE 'general%'" ecowealth_sneat_tool_b_test`
5. Run step 3 again (the second run must also exit 0).
6. `SHOW COLUMNS FROM general_harvest_board;`
7. `SHOW COLUMNS FROM general_harvest_awards;`
8. `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type';`

Expected

- Step 2: **no** `general%` table, 36 tables, 77 settings.
- Step 4: exactly three tables — `general_harvest_levels`, `general_harvest_board`,
  `general_harvest_awards`.
- Step 5: exit **0**, and the counts after both runs are unchanged: **39 tables, 79 settings**,
  `general_harvest_levels` **10**, `general_harvest_board` **1**, the two settings **2**.
- Step 6: `id, baseline_n, armed_at, armed_by, settled_n, updated_at`.
- Step 7: `id, seq, user_id, level, threshold_n, n_at_award, amount, status, ledger_id, credited_at,
  created_at`.
- Step 8: the enum ends `…,'team_harvest','general_harvest')`.
- The board row after both runs is still `(1, NULL, NULL, NULL, 0)`.

### QA21A-03 — The two settings, the ten amounts and the single unarmed board row

| Why | Rules 4 and 9: the stream ships off, the board ships unarmed, and the ladder is seeded once at the reference amounts. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_b_test` (or the migrated development copy) |
| Evidence | `QA21A-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT key_name, value FROM settings WHERE key_name LIKE 'general\_harvest\_%' ORDER BY key_name;`
2. `SELECT COUNT(*), SUM(amount) FROM general_harvest_levels;`
3. `SELECT level, amount FROM general_harvest_levels ORDER BY level;`
4. `SELECT * FROM general_harvest_board;`

Expected

- Step 1: exactly two rows — `general_harvest_enabled = 0`, `general_harvest_max_levels = 10`.
- Step 2: **10** rows, total **8381000.00** (₱8,381,000.00 per node).
- Step 3: `2000, 4000, 8000, 12000, 25000, 100000, 250000, 480000, 2500000, 5000000`.
- Step 4: **one** row, `id = 1`, `baseline_n` NULL, `armed_at` NULL, `armed_by` NULL, `settled_n` 0.

### QA21A-04 — The pages that must *not* have changed

| Why | Rule 10 and D-15: 21A ships data only. The settings card is 21B's and the board panel is 21E's; this check proves the phase did not creep into a view. |
|---|---|
| Where | the running app as `admin` — `/admin/settings`, `/admin/members/{id}` |
| Evidence | `QA21A-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `admin`; open **System → Settings** (`/admin/settings`).
2. Scroll to the commissions section and look for any **General Harvest** block, switch or amount.
3. Open **Members**, then any member's detail page (`/admin/members/{id}`).
4. Look for a General Harvest board panel, a position, or a **GH** column in the member list.
5. Open `/admin/commissions` and look for a General Harvest tab or filter.

Expected

- No General Harvest block, field, switch or amount on the settings page.
- No board panel and no `GH` column anywhere on the member pages.
- No General Harvest tab, filter or figure on the commissions page.
- Nothing on those pages errors, and every other card renders exactly as it did at `p21-start`.

### QA21A-05 — Three registrations write no award

| Why | Rule 10 / D-15: nothing in the application reads the ledger. Phase 20's hook may still queue the sponsor, but no General Harvest writer exists until 21D. |
|---|---|
| Where | a scratch schema with the migrated code (run through `RegistrationService`, not the browser) |
| Evidence | `QA21A-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a scratch schema (never the development copy), sign in as a member with no queue position.
2. Register three members under that sponsor — through `/register` or the harness transcript's
   `RegistrationService::register()` calls.
3. `SELECT COUNT(*) FROM harvest_queue;`
4. `SELECT COUNT(*) FROM general_harvest_awards;`
5. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest';`

Expected

- Step 3: **1** once the third direct crosses Phase 20's gate (the sponsor is queued by the 20C hook);
  register a fourth and it stays **1**.
- Step 4: **0** — no board writer exists yet.
- Step 5: **0** — no General Harvest money exists.

### QA21A-06 — The wallet statement filter names General Harvest

| Why | Rule 6 / Rule 7: the only visible change of this phase. The filter enumerates `WalletService::REF_TYPES`, so it must list the new movement — and it cannot match a row until 21D. |
|---|---|
| Where | the member wallet statement (`/wallet`) and the admin wallet monitor |
| Evidence | `QA21A-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as a member (for example `juan`), open the wallet page (`/wallet`).
2. Open the statement filter's movement-type list and look for **General Harvest bonus**.
3. Choose it and apply the filter.
4. Sign in as `admin`, open **Finance → Wallets** and repeat steps 2–3 on the statement tab.

Expected

- **General Harvest bonus** appears in both filter lists (the member statement and the admin monitor),
  with its grid icon.
- Selecting it returns **no rows** and the total reads ₱0.00: no row can carry the value until 21D.
- The other movement kinds still filter exactly as they did, and no page error appears.

### QA21A-07 — Rebuild the bundle and re-install on an empty schema

| Why | Rule 2 / P21A-T5–T6: the committed bundle must equal its sources, and a fresh install reports the new counts. |
|---|---|
| Where | PowerShell on `system/` |
| Evidence | `QA21A-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\build_install_sql.php` (rebuild) and `php bin\build_install_sql.php --check` (must exit 0).
2. `php bin\db_install.php --check --database=ecowealth_sneat_tool_a_test`
3. `php bin\db_install.php --database=ecowealth_sneat_tool_a_test --force --admin-password=QaAdmin!23456 --superadmin-password=QaSuper!23456`
4. `php bin\wallet_reconcile.php`
5. On the fresh schema: `SELECT * FROM general_harvest_board;` and
   `SELECT COUNT(*), SUM(amount) FROM general_harvest_levels;`

Expected

- `--check` exits **0** with no output; the header reads **39 tables · 79 settings · 10 Team Harvest
  level amounts · 10 General Harvest level amounts · 1 unarmed General Harvest board row**.
- The install verifies and prints `tables_created = 39`, `settings_rows = 79`,
  `general_harvest_levels_rows = 10`, `general_harvest_board_rows = 1` (and 2 staff, 0 members,
  7 products, 2 packages, 18 levels, 10 contents, 0 codes).
- The verification's **General Harvest board** check passes and reads *unarmed*.
- `wallet_reconcile.php` exits **0**; the General Harvest section is not part of the reconciler until
  21D, and no `general_harvest` ledger row exists.
- Step 5: one row `(1, NULL, NULL, NULL, 0)`; **10** ladder rows totalling **8381000.00**.

### QA21A-08 — A business reset empties the ledger and returns the board to unarmed

| Why | Rule 8 / §13.8 I-12: after a reset the award ledger is empty, the board reads unarmed, and the ladder and the two settings survive. |
|---|---|
| Where | the reset console (`bin/db_reset.php`) on a scratch schema |
| Evidence | `QA21A-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a scratch schema, insert two awards for one member and arm the board by hand
   (`UPDATE general_harvest_board SET baseline_n = 40, armed_at = NOW(), settled_n = 121 WHERE id = 1`),
   and edit one ladder amount.
2. `php bin\db_reset.php --dry-run` — the plan must carry a **general_harvest** step and a
   **general_harvest_board** step.
3. `php bin\db_reset.php --force --confirm=<scratch schema>`
4. `SELECT COUNT(*) FROM general_harvest_awards;`
5. `SELECT * FROM general_harvest_board;`
6. `SELECT COUNT(*), SUM(amount) FROM general_harvest_levels;`
7. `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'general\_harvest\_%';`

Expected

- The dry run writes nothing and plans both steps (`general_harvest` = 1 statement,
  `general_harvest_board` = 1 statement).
- The reset reports `ok` and a clean verification (the zero pack, the seven finance reconciliations,
  the counters at 1).
- Step 4: **0** awards.
- Step 5: the row still exists and reads `(1, NULL, NULL, NULL, 0)` — it is **reset, not deleted**.
- Step 6: **10** rows totalling the edited amount (an operator amount is never overwritten by a reset).
- Step 7: **2** settings survive.

---

## 4. Evidence

Save each check's transcript under `docs/qa/evidence/phase-21/`, named exactly as its **Evidence**
line (`QA21A-01.txt` … `QA21A-08.txt`). The folder's `README.md` is the index and stays current as
each phase appends its section.

## 5. Cleanup

Section A creates **no** General Harvest row on the development copy and no QA member: `QA21A-05` runs
on a scratch schema. The only lasting traces of the section are (a) migration `025` on the development
copy — additive, and the state every later section expects — and (b) whatever the reset in `QA21A-08`
left on its scratch schema, which the next test run recreates. If `QA21A-02`/`03` edited an amount on
`ecowealth_sneat_tool_b_test`, no cleanup is needed: the suite and the installer recreate that schema.
The dump from `QA21A-01` is the rollback if migration `025` ever has to be undone (see the plan's
**Rollback** note: restore the enum before dropping the tables).
