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

**Document status.** Section A was written by 21A (2026-11-16) with the automated gate green, and
section B by 21B (2026-11-16) with its own. Sections C–G are appended by their phases. **17 checks**:
8 in section A and 9 in section B. Every check has an evidence file under
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

Expected final lines at `p21b-configuration`:

```text
Cases: 1371   Passed: 1371   Failed: 0
All good.
```

```text
Passed: 268   Failed: 0
```

The baseline was **1,320 / 1,320** PHP cases and **263 / 263** smoke checks at `p21-start` (the Phase 20
release gate); 21A adds **16** cases and **no** smoke check, because it adds no route and no page. 21B
adds **35** cases and **5** smoke checks, all of them on `/admin/settings` — the phase adds no route
either.

21B's share of the PHP suite (`GeneralHarvestConfigurationTest`, 35 cases):

| Case | What it proves |
|---|---|
| P21B-T1 | The card on a fresh database: ten reference amounts, the switch off, the depth 10, the unarmed strip naming the arm job, both generated tables, the calculator, the full-ladder sentence (88,573 / 147,622 / 206,671) and no arm control |
| P21B-T2 | A real `applySection()` round-trip: the ten rows, the actor stamp, one `general_harvest.ladder.update` audit row with the switch/depth/amounts before and after, and an unchanged save writing nothing |
| P21B-T3 | Three blank amounts are stored as `0.00`, ten rows always exist, and a zero renders as a blank input again |
| P21B-T4 | A ladder totalling ₱30,401,000.00 is a `sum` field error; nothing is written and no audit row appears |
| P21B-T5 | ₱10,000,000.01 is refused on its own level; exactly ₱10,000,000.00 and a negative value behave per the ceiling |
| P21B-T6 | Four decimals, a thousands separator and text are refused; two decimals, one decimal and blank are accepted |
| P21B-T7 | Enabling an all-zero ladder is refused on the switch and names level 1; one priced level is not enough (D-17); the reference ladder may be enabled |
| P21B-T8 | Enabling with level 4 blank at a depth of 4 names level 4, a priced level 4 saves, and raising the depth to a blank level 5 is refused on the depth |
| P21B-T9 | The depth is a whole number 1–10; `0`, `11`, `2.5`, `-1`, text and blank are field errors and the previous value survives |
| P21B-T10 | A depth of 3 with levels 4–10 cleared saves; the generated table marks levels 4–10 *not paying at this depth* and keeps their rows |
| P21B-T11 | `threshold(0, L)` and `apexThreshold(L)` are the reference staircase 4 … 88,573, with `slots(L) = 3^L` |
| P21B-T12 | `threshold(1, L)` = 7, 22, 67 … and `threshold(3, L)` = 13, 40, 121 …; position #4 is the apex shifted one level |
| P21B-T13 | `blockFrom/blockTo(0, L)` = 2–4, 5–13, 14–40 …, each block `3^L` positions wide and always after its node |
| P21B-T14 | `qFor(L, N)` at `N = 4, 13, 20, 22, 40, 121` (and −1 above), the prefix property, `filled()`'s clamp and `levelsFor()` |
| P21B-T15 | `boardTotal(N)` = 2,000 / 12,000 / 16,000 / 22,000 / 50,000 / 176,000 / 579,000 / 1,888,000 / 6,065,000, equal to the node-by-node sum |
| P21B-T16 | `boardTotal(88,572)` = 181,827,000.00 and `boardTotal(88,573)` = 190,208,000.00; the difference is exactly one full ladder, and the per-node ceiling holds at 88,573 and 147,622 |
| P21B-T17 | `awardCount(N)` = 1 / 5 / 7 / 9 / 18 / 58 / 179 / 543 / 1,636 / 44,271 / 44,281 |
| P21B-T18 | `earnedBy(0, 40)` = 14,000, `earnedBy(1, 40)` = 6,000, `earnedBy(13, 40)` = 0; the depth caps the payment; the 20-member board is ₱16,000 |
| P21B-T19 | `nextN(0, 10)` = 88,573; `nextCompletion(1,204)` = 1,207 / 1,210 / 1,228 with their nodes; at `N = 0` every level waits for the apex |
| P21B-T20 | A sweep of every `N = 0 … 1,210`: at most one block per level completes per position, the next completion is never behind the queue, and a completion is exactly "the previous next_n" |
| P21B-T21 | Three registrations after configuring write no award, no General Harvest wallet row, no board state and no wallet movement; Phase 20's hook still queues the sponsor |
| P21B-T22 | The generated ladder equals Appendix A line for line, the position table equals the reference's §4.5 table, and the view carries no board literal |
| P21B-T23 | With JavaScript off the card is complete: every derived number is server-rendered, the calculator starts from the queue size, and the form is a plain POST |
| P21B-T24 | The ten inputs stack (`col-12 col-md-6`) and both tables scroll inside the card at 390 px; nothing forces a page-level width |
| P21B-T25 | The phase adds no schema: 39 tables, 76 seed settings, ten ladder rows, one board row, `APP_PHASE` still 20, the bundle untouched, no route, no arm job |
| P21B-T26 | An armed board refuses an amount change with the `armed` error naming the arm instant, renders the inputs disabled and the preset absent, still allows a depth change, and refuses a direct `save()`; a disarm restores editing |
| P21B-T27 | The strip renders exactly one of the four states (unarmed → armed and off → armed and on → armed with positions waiting), names the next action, and the state list is a contract |
| P21B-T28 | A card that omits a ladder keeps it (each ladder independently); an explicit empty array clears it and still leaves ten rows; the other ladder is untouched |
| P21B-T29 | A refused save changes neither store, keeps the typed amounts and depth and the errors across the redirect, and writes no audit row |
| P21B-T30 | The board reads: `N`, gaplessness, `positionOf`/`ownerOf`, the frontier (rows/complete/missing/next_n/amount, moving with the rows written), `blocks()` for one node, `blockRange()` paged, and the unarmed backlog is zero |
| P21B-T31 | An armed board at `baseline_n 13`, `settled_n 13`, `N 40` reports 27 positions and ₱38,000.00 of backlog, reports *unsettled*, and writes no award |
| P21B-T32 | The model's ranges (a level outside 1–10 reads ₱0.00, `threshold(-1, 1)` throws), zero-filled reads for a missing row and an empty table, and one statement per bulk read |
| P21B-T33 | Only the Commissions card owns a General Harvest key: one depth + ten amounts + one switch, no baseline key, the amount rule shared with the model, and the stable action/state strings |
| P21B-T34 | A post that omits the depth keeps it and only the switch moves; a depth that is present but empty is still refused and the previous depth survives |
| P21B-T35 | A card carrying null placeholders for the absent ladder keys keeps the ten rows and writes no audit row: a null is the absence of a field, not a blank amount |

No automated case in this phase writes an award row, a queue row or a `general_harvest` wallet row on
the development database. The HTTP walk uses the development copy and restores every setting, ladder
row and board field it touches.

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
| P21A-T15 | The 21A/21B manifest — `WalletService`, `helpers.php`, the two install/reset tools, the two board models, the settings controller and the three settings views — names the objects and nothing else does; no award model, no service, no route, no job |
| P21A-T16 | The phase adds no route and no page of its own: the route files are free of the feature, only the settings card's three files render it, the stream is off, and the schema/reset/bundle counts all agree at 39 |

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

## 4. Section B — Phase 21B: Configuration and the board arithmetic (`QA21B-01` … `QA21B-09`)

**Prerequisites.** Section A complete (migration `025` on the development copy: **39 tables**, the ten
ladder rows, the unarmed board row); the app running (Laragon → **Start All**) signed in as `admin`;
the browser at 100% zoom. Expected suite count for this section: **1,371** PHP cases and **268** smoke
checks.

> **No money moves in this section.** 21B arms nothing and writes no award: there is no
> `bin/general_harvest_arm.php` until 21C and no settlement until 21D, so the wallet cannot move. The
> only rows a step writes are the ten `general_harvest_levels` amounts and the two settings; `QA21B-02`
> and `04` restore the reference ladder at the end, and every refused save writes nothing at all.

### QA21B-01 — The card on a fresh database

| Why | Rule 1, Rule 6, Rule 9, Rule 10: the operator can see the ladder, the knobs and the truth about the state before anything is armed. |
|---|---|
| Where | `/admin/settings` → **Commissions**, as `admin` |
| Evidence | `QA21B-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/settings` and scroll to **Commissions → General Harvest bonus**.
2. Read the heading, the badge and the paragraph.
3. Read the ten amount inputs, their badges and their "Apex needs N" hints.
4. Read the **Saved ladder** table (Slots / Apex needs N / Stored amount / Cumulative / Depth).
5. Read the **Queue size each position needs** table.
6. Read the state strip and the calculator.

Expected

- The card is titled **General Harvest bonus** with the badge *One board · levels 1–10*, and it
  explains the node, the block sizes (3 / 9 / 27 …), the all-or-nothing rule and the "no purchases,
  rank or month" property.
- **Enable the General Harvest bonus** is **off**; **Levels that pay** reads **10**.
- The ten inputs show `2000.00, 4000.00, 8000.00, 12000.00, 25000.00, 100000.00, 250000.00, 480000.00,
  2500000.00, 5000000.00`; each carries a *Pays at this depth* badge.
- The ladder table gives slots `3, 9, 27 … 59,049`, apex thresholds `4, 13, 40 … 88,573`, the stored
  amounts, the cumulative column ending **₱8,381,000.00**, and every level *Paid*.
- The position table gives #1–#6 × levels 1–4: `4 13 40 121`, `7 22 67 202`, `10 31 94 283`,
  `13 40 121 364`, `16 49 148 445`, `19 58 175 526`.
- The strip reads **Not armed — no baseline has been recorded.** and names
  `bin/general_harvest_arm.php`; it also prints the current queue size.
- The sentence under the total reads **₱8,381,000.00 to node #1 over ten levels … 88,573 qualified
  members; node #2 needs 147,622 and node #3 needs 206,671**, and the depth total is **₱8,381,000.00**.
- The calculator is titled **If the queue reaches N**, starts from the current queue size and calls
  itself an illustration.
- There is **no** button or field that arms the board.

### QA21B-02 — Save a changed ladder and read the audit trail

| Why | Rule 14 (the audit row), §15.4: a ladder edit is one audited event with its before/after payload. |
|---|---|
| Where | `/admin/settings` → Commissions, and `/admin/audit` |
| Evidence | `QA21B-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Change **level 1** to `2500.00` and **level 10** to `4000000.00`; press **Save settings**.
2. Re-open the card: the two values persist and the total badge reads **₱7,381,500.00**.
3. Open **System → Audit log** and filter for `general_harvest.ladder.update`.
4. Undo the edit: type `2000.00` and `5000000.00`, save, and confirm the total is **₱8,381,000.00**
   again.

Expected

- Step 1: a success flash (*Commissions: 1 setting and the General Harvest ladder saved.* — the count
  is the number of knobs that moved, the ladder is always named).
- Step 2: the two inputs persist, the stored-amount column and the cumulative column follow them, and
  the badge is **₱7,381,500.00** (₱8,381,000.00 − ₱2,000.00 + ₱2,500.00 − ₱5,000,000.00 +
  ₱4,000,000.00).
- Step 3: exactly one row, actor `admin`, `subject_type = settings`, with `before`/`after` carrying the
  switch, the depth and all ten levels, and `changes` naming `levels.1` and `levels.10`.
- Saving the same values a second time reports *No changes to save.* and writes no audit row.
- Step 4: the total returns to **₱8,381,000.00**.

### QA21B-03 — The full-ladder ceiling refuses a slipped zero

| Why | Rule 3: the ₱25,000,000.00 ceiling is a typo guard, refused as a `sum` field error and never clamped. |
|---|---|
| Where | `/admin/settings` → Commissions |
| Evidence | `QA21B-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set levels 8, 9 and 10 to `10000000.00` each and press **Save settings**.
2. Read the error and the values that come back.
3. Reload `/admin/settings` and re-read the stored ladder.

Expected

- The save is refused with a red alert naming the typed total: *The ten amounts total ₱30,401,000.00 —
  the full ladder cannot exceed ₱25,000,000.00.*
- The three typed values come back as typed (the form keeps them), the flash says *Please fix the
  highlighted settings.*, and there is no success message.
- Step 3: the stored ladder is unchanged — levels 8, 9 and 10 still read ₱480,000.00, ₱2,500,000.00 and
  ₱5,000,000.00 — and no `general_harvest.ladder.update` row was written.

### QA21B-04 — Load the reference ladder

| Why | Rule 1 and Appendix A: the preset is offered behind a button and never applied by itself. |
|---|---|
| Where | `/admin/settings` → Commissions |
| Evidence | `QA21B-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Clear two amounts (level 3 and level 7) and press **Load reference ladder**.
2. Confirm the ten inputs and the total, then press **Save settings**.
3. Re-open the card.

Expected

- Step 1: the ten inputs return to the reference preset and the total badge reads **₱8,381,000.00**
  before saving; nothing is stored yet (the *Saved ladder* table still shows the old values until the
  save).
- Step 2: the save succeeds.
- Step 3: the stored ladder is the reference preset again, the cumulative column ends
  **₱8,381,000.00**, and the depth is still **10**.

### QA21B-05 — The switch refuses an unpriced ladder

| Why | Rule 4 / D-17: a payable level must be positive, or the ledger's per-level prefix could not stay gapless. |
|---|---|
| Where | `/admin/settings` → Commissions |
| Evidence | `QA21B-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Press **Clear** (every amount blank) and turn **Enable the General Harvest bonus** on.
2. Press **Save settings** and read the error.
3. Restore the reference ladder (**Load reference ladder**), save, and leave the switch **off**.

Expected

- Step 2: refused with an error on the switch: *Level 1 has no amount. The bonus can only be on while
  every level 1–10 pays something: give level 1 an amount, or turn the switch off.*
- The stored ladder is unchanged (the ten cleared inputs came back as typed, nothing was written) and
  the switch stays **off** in the database.
- Step 3: the ladder is back to the reference amounts and the switch is off.

### QA21B-06 — The depth marks the levels above it

| Why | Rule 5 and §12.4 Rule 4: the depth caps payment, never the board; levels above it are marked and their amounts are kept. |
|---|---|
| Where | `/admin/settings` → Commissions |
| Evidence | `QA21B-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set **Levels that pay** to `3`, clear levels 4–10, and save.
2. Re-open the card and read the badges and the saved ladder.
3. Set the depth to `5` with level 5 still blank and save.
4. Set the depth to `11` and save.
5. Restore the depth to `10` and the reference ladder; save.

Expected

- Step 1: accepted — a level above the depth may be blank.
- Step 2: levels 1–3 carry *Pays at this depth*; levels 4–10 carry *Not paying at this depth* in the
  inputs and *Not paying at this depth* in the ladder table, with their `0.00` stored amounts still
  shown.
- Step 3: refused on **Levels that pay**: *Level 5 has no amount, so the depth cannot rise to 5 …*; the
  stored depth stays **3**.
- Step 4: refused — *Levels that pay must be at most 10.*
- Step 5: the depth is `10`, the ladder is the reference preset and nothing above the depth is marked
  any more.

### QA21B-07 — Registering members writes no award

| Why | P21B-T21 / D-15: 21B has no writer; Phase 20's hook may still queue a sponsor, and nothing else happens. |
|---|---|
| Where | `/admin/members` (or the public `/register` form) and MySQL |
| Evidence | `QA21B-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On the development copy, register three members under one sponsor (the sponsor must be a `member`
   account whose queue is seeded — `QA21A-05` shows the same state on a scratch schema).
2. `SELECT COUNT(*) FROM general_harvest_awards;`
3. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest';`
4. `SELECT COUNT(*) FROM harvest_queue;` and `SELECT * FROM general_harvest_board;`
5. Delete the three QA members (and their queue row, if the sponsor was queued).

Expected

- Steps 2 and 3: **0** each — no award and no money.
- Step 4: the queue holds the sponsor (Phase 20's hook) or not, but the **board row is still
  `(1, NULL, NULL, NULL, 0)`**: unarmed, `settled_n` 0, `created_at` unchanged.
- Step 5: back to the pre-check counts.

> Record the member ids you create in the evidence file so cleanup is checkable. Never run this step on
> a production database.

### QA21B-08 — The calculator, and the card with JavaScript off

| Why | Rule 11 (an illustration, not a liability) and Rule 12 (no JavaScript-only validation). |
|---|---|
| Where | `/admin/settings` → Commissions, plus one save with JavaScript disabled |
| Evidence | `QA21B-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Type `1000` in the **If the queue reaches N** input.
2. Read the two figures and the caption.
3. Disable JavaScript in the browser (DevTools → Settings → Debugger → *Disable JavaScript*), reload
   the card and read the numbers again.
4. With JavaScript still off, save the card unchanged.

Expected

- Step 1–2: the calculator reads **₱1,601,000.00** in **493** awards, and the caption calls it an
  illustration — the queue is at N today, no award has been written, and nothing can pay until the
  board is armed (21C) and settled (21D).
- Step 3: the server-rendered numbers are all present (the ladder, both tables, the sentence and the
  calculator's default for the current queue size); only the *live* updating is missing.
- Step 4: the save succeeds and reports *No changes to save.* — the form validates on the server, with
  no PHP warning in `storage/logs/`.

### QA21B-09 — The card at 390 px and at 1440 px

| Why | Rule 2's input hints, Rule 12 and the section's layout risk: the card must be readable on a phone without moving the page sideways. |
|---|---|
| Where | `/admin/settings` → Commissions, at 390 px and 1440 px |
| Evidence | `QA21B-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open the card at 390 px width.
2. Scroll to the amount inputs and check they stack one per row.
3. Scroll both generated tables sideways inside the card.
4. Repeat at 1440 px.

Expected

- At 390 px the ten inputs stack, the badges wrap under their labels, and the page itself never scrolls
  sideways — both tables scroll inside their own `.table-responsive` wrapper.
- At 1440 px the inputs form two columns and both tables fit without inner scrolling.
- No overlap, no clipped text, no horizontal page scroll at either width.

---

## 5. Evidence

Save each check's transcript under `docs/qa/evidence/phase-21/`, named exactly as its **Evidence**
line (`QA21A-01.txt` … `QA21A-08.txt`, `QA21B-01.txt` … `QA21B-09.txt`). The folder's `README.md` is the
index and stays current as each phase appends its section.

## 6. Cleanup

Section A creates **no** General Harvest row on the development copy and no QA member: `QA21A-05` runs
on a scratch schema. The only lasting traces of the section are (a) migration `025` on the development
copy — additive, and the state every later section expects — and (b) whatever the reset in `QA21A-08`
left on its scratch schema, which the next test run recreates. If `QA21A-02`/`03` edited an amount on
`ecowealth_sneat_tool_b_test`, no cleanup is needed: the suite and the installer recreate that schema.
The dump from `QA21A-01` is the rollback if migration `025` ever has to be undone (see the plan's
**Rollback** note: restore the enum before dropping the tables).

Section B writes the ten ladder amounts and the two settings on the development copy, so its steps
restore the reference ladder (`Load reference ladder` → save) and leave the switch **off**. The board
row is never written: no step arms it. `QA21B-07` is the only step that creates member accounts, and it
deletes them; if the walk is abandoned halfway, delete the members it lists in its evidence file and
restore the ladder from `QA21B-01`'s transcript.
