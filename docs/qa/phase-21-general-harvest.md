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

**Document status.** All seven sections are complete: section A by 21A (2026-11-16), section B by 21B
(2026-11-16), section C by 21C (2026-11-16), sections D–F by their phases and completed here at 21G,
and section G by 21G. **58 checks**: 8 in section A, 9 in section B, 8 in section C, 10 in section D,
8 in section E, 9 in section F and 6 in section G. Every check has an evidence file under
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

Expected final lines at `p21c-baseline`:

```text
Cases: 1392   Passed: 1392   Failed: 0
All good.
```

```text
Passed: 268   Failed: 0
```

The baseline was **1,320 / 1,320** PHP cases and **263 / 263** smoke checks at `p21-start` (the Phase 20
release gate); 21A adds **16** cases and **no** smoke check, because it adds no route and no page. 21B
adds **35** cases and **5** smoke checks, all of them on `/admin/settings` — the phase adds no route
either. 21C adds **21** cases and **no** smoke check: it ships one CLI job and no page, so the smoke
count is 21B's.

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

21C's share of the PHP suite (`GeneralHarvestBaselineTest`, 21 cases):

| Case | What it proves |
|---|---|
| P21C-T1 | Fixture B (40 accounts, `N = 13`): five baseline rows — level 1 nodes 0–3 at ₱2,000 and level 2 node 0 at ₱4,000 — **₱12,000.00**, board `baseline_n = settled_n = 13`, `armed_at` set, `armed_by` NULL |
| P21C-T2 | A second arm is refused `armed` ("already armed", "no --force"), writes no row and leaves the board untouched |
| P21C-T3 | The stream on: refused `enabled`, the message says to switch it off first, nothing is written |
| P21C-T4 | An empty seed marker: refused `unseeded`; an unparseable one is refused the same way; nothing is written |
| P21C-T5 | Level 9 at `0.00` (above a depth of 3): refused `invalid_ladder` and **names level 9**; level 1 at `0.00` names level 1 |
| P21C-T6 | An empty queue: 0 rows, the board armed at `baseline_n = settled_n = 0`, `excluded()` all zero |
| P21C-T7 | Fixture C (121 accounts, `N = 40`): 18 rows, **₱50,000.00**, per level 13 / 4 / 1, equal to `boardTotal(40)` |
| P21C-T8 | Two dry runs on fixture C are identical (`n`, `rows`, `excluded`, `per_level`, preview), print all 18 positions, and write nothing |
| P21C-T9 | The whole fixture, plus a registration after the arm (the queue reaches 41): **0** credited rows, **0** `general_harvest` wallet rows, every wallet balance unchanged |
| P21C-T10 | Every row: `baseline`, `ledger_id NULL`, `n_at_award = 13`, the closed-form threshold, the configured amount, `(seq, level)` taken |
| P21C-T11 | Per level the rows are exactly `0 … qFor`, contiguous, with no duplicate node |
| P21C-T12 | A queue with a hole: refused `not_gapless`, naming `COUNT(*) = 12` and `MAX(id) = 13` |
| P21C-T13 | The `general_harvest.arm` audit row carries `n`, `rows`, `excluded`, the per-level split and the actor, and `armed_by` names the actor |
| P21C-T14 | A second connection holds the advisory lock: the arm is refused, writes nothing, and the winner then writes exactly one baseline set |
| P21C-T15 | A second board row is refused; `baseline_n` without `armed_at` and `settled_n < baseline_n` are refused; a real arm sets the pair together |
| P21C-T16 | A fresh install's board is unarmed and the CLI refuses `unseeded` (exit 1), dry run included |
| P21C-T17 | A business reset empties the ledger, unarms the board and returns the seed marker to `''` (the arm refuses until the new network is seeded); the second arm uses the new `N` and writes a fresh baseline |
| P21C-T18 | `state()` reads `armed`, `baseline_n = settled_n = 13`, `backlog = N − N₀`, the closed-form backlog amount and the depth; a ladder change is refused (D-23) |
| P21C-T19 | Fixture `h = 4` (364 accounts, `N = 121`) at depth 3: 58 rows, **₱176,000.00**, and level 4 — above the depth — still has its baseline row |
| P21C-T20 | The CLI: the human dry run prints `N0 = 40`, 18 rows, ₱50,000.00, the first 18 positions and every level; `--json` emits exactly the six documented keys; a second run exits 1 `armed` |
| P21C-T21 | The phase's manifest exists, `excluded()` equals the closed form, `countByStatus()` is `0 / 18`, and the whole phase moves no money |

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

## 5. Section C — Phase 21C: The baseline (`QA21C-01` … `QA21C-08`)

Section C is the stream's **irreversible** decision. The arm records `N₀` — the queue length at that
instant — and one `baseline` row for every block that was already full, at **all ten levels**; from
that instant the ten amounts are frozen (D-23) and no pre-go-live block can ever be paid. It moves
**no money**: every row is `baseline` with `ledger_id NULL`, and the wallet is untouched.

**Every check runs on a scratch copy**, never on the development or production database. A second arm
is refused and there is no `--force`; undoing one takes a business reset. Build the copy once:

```powershell
# A scratch schema at the current schema + seeds (the harness's own recipe):
#   database/schema.sql, seeds/001_settings.sql … 005_demo_tree.sql
# on ecowealth_sneat_tool_b_test.

# The copy needs the shape of fixture C: 121 member accounts, 40 of them qualified
# (root -> 3 directs -> 9 -> 27 leaves), queued at positions 1 … 40.
# tests/fixtures/general_harvest.php builds exactly that; the transcript in
# QA21C-01.txt records the counts the copy was built with.

# Phase 20's queue must be live: team_harvest_ledger_since is a datetime. The real
# seed job writes it on a production copy; on the scratch copy it is set to any
# datetime, because the arm only checks that the marker parses.

# The reference ladder is the migration's own seed; leave it in place.
```

> `QA21C-02` … `QA21C-06` continue on that same armed copy, in order. `QA21C-08` runs the business
> reset, which destroys the copy's ledger — do it last.

### QA21C-01 — The dry run is the decision surface

| Why | §12.6 Rule 4: the excluded amount is printed before anything is written, and the dry run writes **nothing**. |
|---|---|
| Where | `php bin\general_harvest_arm.php --dry-run` on the scratch copy |
| Evidence | `QA21C-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php bin\general_harvest_arm.php --dry-run`.
2. Read `N0`, the row count, the excluded amount, the per-level table and the first positions.
3. `SELECT COUNT(*) FROM general_harvest_awards;` and `SELECT * FROM general_harvest_board;`.

Expected

- Step 1–2: `N0 = 40`, **18** rows, **₱50,000.00** excluded, levels 1–3 carrying 13 / 4 / 1 rows (and
  levels 4–10 named as *no complete block at this N0*, with their amounts still printed), then the
  first 18 positions with their member, level, threshold and amount.
- Step 3: the award table is still **empty** and the board is still `(1, NULL, NULL, NULL, 0)` —
  unarmed. The dry run wrote nothing at all.

### QA21C-02 — The real arm commits exactly those numbers

| Why | §12.6 Rules 1, 6 and 9: one transaction, one baseline set, `settled_n = N₀`. |
|---|---|
| Where | `php bin\general_harvest_arm.php` on the scratch copy |
| Evidence | `QA21C-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php bin\general_harvest_arm.php`.
2. `SELECT COUNT(*), SUM(amount) FROM general_harvest_awards;`
3. `SELECT * FROM general_harvest_board;`

Expected

- Step 1: the same `N0`, the same 18 rows and the same ₱50,000.00 as the dry run, then the board line
  (`armed <instant> · baseline_n = 40 · settled_n = 40`) and the audit id.
- Step 2: **18** and **50000.00**.
- Step 3: one row, `baseline_n = 40`, `armed_at` set, `armed_by` NULL (the CLI has no session),
  `settled_n = 40`.

### QA21C-03 — Every level is a contiguous prefix from node 0

| Why | I-03/I-04: the ledger is a prefix, so no later settlement can find a gap. |
|---|---|
| Where | SQL on the scratch copy |
| Evidence | `QA21C-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

```sql
SELECT level, COUNT(*), MIN(seq), MAX(seq)
  FROM general_harvest_awards
 GROUP BY level
 ORDER BY level;
```

Expected

- `1 / 13 / 0 / 12`, `2 / 4 / 0 / 3`, `3 / 1 / 0 / 0`, and no row for levels 4–10.
- `COUNT(*) = MAX(seq) + 1` at every level that has rows.

### QA21C-04 — A second arm is refused, and nothing moves

| Why | §12.6 Rule 1: arm is a one-time act; `armed_at` is the flag and there is no `--force`. |
|---|---|
| Where | `php bin\general_harvest_arm.php` again, on the armed copy |
| Evidence | `QA21C-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run the job again (with and without `--dry-run`).
2. `SELECT COUNT(*) FROM general_harvest_awards;` and `SELECT * FROM general_harvest_board;`

Expected

- Step 1: `REFUSED (armed)` and the message says the board was already armed on the recorded instant
  and that there is no `--force`; exit code **1**.
- Step 2: 18 rows and the same board row — a refused arm writes nothing, not even an audit entry.

### QA21C-05 — The arm refuses while the stream is on

| Why | §14.3 Rule 3: the only working order is configure → arm → enable. |
|---|---|
| Where | `/admin/settings` → Commissions, then the job, on a second **unarmed** copy of the same shape |
| Evidence | `QA21C-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

> The refusal is about the switch, so it needs an unarmed board: this check runs on its own copy
> (`ecowealth_sneat_tool_a_test`, built like the first), because `QA21C-02` already armed the section's
> main copy and a second arm would be refused for the other reason.

Steps

1. Set `general_harvest_enabled = 1` (the card, or `UPDATE settings SET value = '1' WHERE key_name =
   'general_harvest_enabled'`).
2. Run the arm job (`--dry-run` and the real run).
3. Read the exit code and the message; check the ledger and the board.

Expected

- Step 2–3: `REFUSED (enabled)` — *"The General Harvest stream is switched on … switch the stream off
  before arming"* — exit **1**, no row written, the board still unarmed.
- Turn the switch back off before the next check.

### QA21C-06 — Nothing is credited and nothing reaches the wallet

| Why | The phase's gate: there is not one credited row after 21C (P21C-T9). |
|---|---|
| Where | SQL on the scratch copy |
| Evidence | `QA21C-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

```sql
SELECT status, COUNT(*) FROM general_harvest_awards GROUP BY status;
SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest';
SELECT COUNT(*) FROM general_harvest_awards WHERE ledger_id IS NOT NULL;
```

Expected

- One status only: `baseline / 18`.
- **0** wallet movements and **0** rows with a ledger reference.
- `php bin\wallet_reconcile.php` exits **0**, exactly as it did before the arm.

### QA21C-07 — The member's wallet shows no General Harvest movement

| Why | The money does not exist yet; the statement must not imply it does (Rule 7). |
|---|---|
| Where | `/wallet` (member) on the scratch copy, as a member who holds a baseline row |
| Evidence | `QA21C-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as one of the first 13 members of the scratch copy's queue (any node whose baseline row
   exists at level 1).
2. Open `/wallet` and the earnings tab; filter the statement by **General Harvest bonus**.
3. `SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = <id> AND ref_type = 'general_harvest';`

Expected

- Step 2: no General Harvest movement appears under any filter; the filter returns **no rows**.
- Step 3: **0**.
- The baseline is visible only as the ledger's own record (`baseline`, no wallet reference) — not on
  the member's statement.

### QA21C-08 — A business reset unarms the board and a new network can be armed

| Why | §12.6 Rule 6 / I-12: the reset returns the board to unarmed, keeps the ladder, and a later arm starts a new history. |
|---|---|
| Where | `php bin\db_reset.php` on the scratch copy, then the seed job and the arm |
| Evidence | `QA21C-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run the business reset on the scratch copy.
2. `SELECT COUNT(*) FROM general_harvest_awards;` and `SELECT * FROM general_harvest_board;` and
   `SELECT COUNT(*) FROM general_harvest_levels;`
3. Run the arm job again without re-seeding Phase 20's queue: it must refuse `unseeded`.
4. Re-seed the new network's queue, then run the arm: it must succeed with the new `N`.

Expected

- Step 2: the ledger is empty, the board is `(1, NULL, NULL, NULL, 0)`, the ten ladder rows survive.
- Step 3: `REFUSED (unseeded)` — the go-live order starts from the queue, not the board.
- Step 4: a fresh baseline at the new `N`, and the new rows carry the ladder's amounts.

---

## 6. Evidence

Save each check's transcript under `docs/qa/evidence/phase-21/`, named exactly as its **Evidence**
line (`QA21A-01.txt` … `QA21A-08.txt`, `QA21B-01.txt` … `QA21B-09.txt`, `QA21C-01.txt` …
`QA21C-08.txt`). The folder's `README.md` is the index and stays current as each phase appends its
section.

## 7. Cleanup

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

Section C writes **only** on its scratch copy. Its last step resets that copy, so it ends the walk in
the state it started from (an unarmed board and an empty ledger); the ladder survives the reset. Do not
run any of section C on the development copy: an arm there is irreversible without a business reset, and
the lock it takes is real.

---

## 6. Section D — Phase 21D: Money engine (`QA21D-01` … `QA21D-10`)

Section D is the **only phase that moves money**. It settles a completed block into the node holder's
withdrawable e-wallet, once ever, honours the baseline (pre-go-live blocks recorded, never paid), and is
state-based so a missed registration self-heals on the next position or the catch-up run. **No page
appears in this phase** — verification is through the wallet statements, the tables and SQL.

**Prerequisites.** Section C complete (board armed on a scratch copy); the reference ladder saved; the
stream switch on for the money steps. Every money step runs on a scratch schema (`ecowealth_sneat_tool_b_test`
or `ecowealth_sneat_tool_a_test`), never on the development copy. Expected suite count at this gate:
**≥ 1,430** PHP cases (plan phase map); smoke unchanged at **268** (no new route). Build the copy once:

```powershell
# A scratch schema at migration 025 + seeds, with the reference ladder seeded and the board armed
# (section C's arm run on this same copy, N0 recorded). The switch is left off until QA21D-07 turns it on.
# Fixtures: h = 1 (13 accounts, N = 4) and h = 3 (121 accounts, N = 40), built through the plan's
# §16.2 fixture recipes and queued through RegistrationService.
```

### QA21D-01 — One block fills and pays its amount, once

| Why | §12.5 rule 3: a completed block pays its configured amount once, ever, as a wallet credit. |
|---|---|
| Where | `RegistrationService::register()` in BFS order on an armed scratch copy with the switch on |
| Evidence | `QA21D-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On an armed scratch copy with the reference ladder and `general_harvest_enabled = 1`, register the
   members of fixture `h = 1` (13 accounts, `N = 4`) in BFS order.
2. `SELECT id, seq, level, threshold_n, n_at_award, amount, status, ledger_id FROM general_harvest_awards WHERE seq = 0;`
3. `SELECT COUNT(*) FROM general_harvest_awards;`
4. Open the member who holds node 0 on `/wallet` and filter by **General Harvest bonus**.

Expected

- Step 2: one row — seq `0`, level `1`, `threshold_n = 4`, `n_at_award = 4`, amount `₱2,000.00`,
  `status = 'credited'`, `ledger_id` set.
- Step 3: **1** award total (node 0's block is the only one complete at `N = 4`).
- Step 4: one **General Harvest bonus** credit of **₱2,000.00**, withdrawable, `ref_id` = the award id.

### QA21D-02 — The member's wallet shows the General Harvest credit

| Why | §12.7: the credit raises the wallet and the withdrawable bucket, and the statement filter names it. |
|---|---|
| Where | `/wallet` as the node-0 holder, plus the statement filter |
| Evidence | `QA21D-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as the member who holds node 0 of fixture `h = 1`.
2. Open `/wallet` and apply the **General Harvest bonus** filter.
3. Read the credit row and the bucket totals.

Expected

- One movement: **General Harvest bonus**, ₱2,000.00, `ref_type = 'general_harvest'`, `ref_id` = the award id.
- The withdrawable bucket rose by ₱2,000.00; the statement total matches.
- No other movement kind is affected.

### QA21D-03 — The admin wallet monitor shows the same credit

| Why | Rule 6 of §12.7: every credited award has one ledger row the finance side can trace to the award. |
|---|---|
| Where | `/admin/wallets` (admin wallet monitor) |
| Evidence | `QA21D-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `admin`, open **Finance → Wallets**.
2. Find the member who holds node 0 of fixture `h = 1` and open their statement.
3. Filter by `ref_type = 'general_harvest'` (or locate the row by `ref_id` = award id).

Expected

- The same credit: **General Harvest bonus**, ₱2,000.00, `ref_type = 'general_harvest'`, `ref_id` = the
  `general_harvest_awards.id` from QA21D-01.
- The row links back to the award and to the member; clicking through reaches both.

### QA21D-04 — Reconciliation passes with the General Harvest section

| Why | Rule 14 of §14.5: `reconcile()` proves the ledger, the wallet and the closed form agree. |
|---|---|
| Where | `php bin\wallet_reconcile.php` on the armed scratch copy |
| Evidence | `QA21D-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php bin\wallet_reconcile.php`.
2. Read the **General Harvest** section and the final exit code.

Expected

- Exit **0**.
- The **General Harvest** section reports paid ₱2,000.00, 1 award, 1 recipient, baseline ₱0.00, no
  mismatch, and its totals equal the award ledger and the wallet movements.

### QA21D-05 — Fixture `h = 3` completes three levels and pays the reference board total

| Why | §12.8 example D: `N = 40` pays node 0 ₱14,000.00, nodes 1–3 ₱6,000.00 each, nodes 4–12 ₱2,000.00 each; board ₱50,000.00, 18 awards. |
|---|---|
| Where | `RegistrationService::register()` on an armed scratch copy with the switch on |
| Evidence | `QA21D-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build fixture `h = 3` (121 accounts, 40 qualified) on an armed scratch copy, switch on.
2. Register the last member so the queue reaches `N = 40` (level 3 completes).
3. `SELECT COUNT(*), SUM(amount) FROM general_harvest_awards;`
4. `SELECT user_id, SUM(amount) FROM general_harvest_awards GROUP BY user_id ORDER BY user_id LIMIT 4;` (node 0's wallet)
5. Read node 0's wallet movements on `/wallet`.

Expected

- Step 3: **18** awards, **50000.00**.
- Step 4: node 0 = **₱14,000.00** (L1–L3), nodes 1–3 = ₱6,000.00 each, nodes 4–12 = ₱2,000.00 each.
- Step 5: node 0's wallet holds exactly ₱14,000.00 across three movements — never ₱50,000.00 (the baseline
  rows are recorded, not paid).

### QA21D-06 — A registration that completes nothing writes no award

| Why | §12.5 rule 3 (all-or-nothing): a partial block pays nothing and is information only. |
|---|---|
| Where | Register one non-qualifying member after fixture `h = 3` is fully settled |
| Evidence | `QA21D-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. With fixture `h = 3` settled at `N = 40`, register one more member under any sponsor who does not
   qualify (fewer than 3 directs).
2. `SELECT COUNT(*) FROM general_harvest_awards;` before and after.
3. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest';` before and after.

Expected

- Steps 2 and 3: **no change** — no new award, no new credit, totals unchanged (still 18 / ₱50,000.00).
- The registration itself still succeeds (Phase 20's hook queues only qualifiers).

### QA21D-07 — Off then on: no money while off, the backlog pays once when on

| Why | D-10 / D-19: the switch gates money; state-based settlement self-heals a backlog on enablement. |
|---|---|
| Where | `/admin/settings` switch, then `php bin\general_harvest_catchup.php` on a scratch copy |
| Evidence | `QA21D-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set `general_harvest_enabled = 0` on an armed scratch copy with a backlog (queue grown past
   `settled_n`).
2. Register three members under a new sponsor; confirm the queue grows and the awards do not.
3. `php bin\general_harvest_catchup.php --dry-run` and note the missing awards / open liability.
4. Turn the switch on and run the catch-up for real; then run it a second time.

Expected

- Step 2: queue rows exist; awards **0**; wallets unchanged; `settle()` returns `off`.
- Step 3: the dry run writes nothing and lists exactly the missing awards and their amounts.
- Step 4: the real catch-up writes exactly the missing awards, once, in board order; a second run writes
  nothing (idempotent) and `settled_n` catches up to `N`.

### QA21D-08 — The baseline is honoured: pre-go-live blocks record, never pay

| Why | D-06 / §12.6: blocks full at `N₀` are `baseline` rows with no money; only post-baseline completions credit. |
|---|---|
| Where | Arm at `N = 13`, grow to `N = 40`, inspect `seq = 0` rows and the wallet |
| Evidence | `QA21D-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a fresh scratch copy, arm at `N = 13` (fixture B shape: 40 accounts, 13 qualified).
2. Grow the queue to `N = 40` (register to fixture C shape) with the switch on.
3. `SELECT level, status, amount FROM general_harvest_awards WHERE seq = 0 ORDER BY level;`
4. Read node 0's wallet on `/wallet`.

Expected

- Step 3: levels 1–2 show `baseline` (₱2,000.00 / ₱4,000.00, `ledger_id NULL`); level 3 shows
  `credited` ₱8,000.00 with `ledger_id` set.
- Step 4: the wallet holds **₱8,000.00 only** — never ₱14,000.00 (levels 1–2 were already full at go-live).

### QA21D-09 — The doctor reports a clean board

| Why | §14.11: `doctor()` reports clock, tables, settings, armed state, gapless, queue count, settled,
  backlog, per-level frontier, paid, baseline, open liability and the reconcile verdict. |
|---|---|
| Where | `php bin\general_harvest_catchup.php --doctor` on a clean armed build |
| Evidence | `QA21D-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Run `php bin\general_harvest_catchup.php --doctor` on an armed scratch copy with money settled.
2. Read each line and the exit code.

Expected

- Exit **0**; every line green: clock, tables present, settings valid, board armed, gapless OK,
  `N = settled_n` (no backlog), per-level frontier at the closed-form values, paid total equals
  `boardTotal(settled_n) − boardTotal(baseline_n)`, baseline excluded equals `boardTotal(baseline_n)`,
  open liability ₱0.00, reconcile verdict clean.

### QA21D-10 — Tampering with a wallet row breaks reconciliation

| Why | Rule 12 of §14.5 / I-06: every credited row has a ledger row; removing one makes reconcile fail and
  names the award. |
|---|---|
| Where | Hand-delete one `ewallet_ledger` row, then `php bin\wallet_reconcile.php`, then restore from backup |
| Evidence | `QA21D-10.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Note one credited award id and its `ledger_id` on an armed scratch copy.
2. Delete that `ewallet_ledger` row by hand (`DELETE FROM ewallet_ledger WHERE id = <ledger_id>`).
3. Run `php bin\wallet_reconcile.php` and read the General Harvest section and exit code.
4. Restore the deleted row from the backup taken in QA21A-01 / section C's setup.

Expected

- Step 3: the reconciler reports the affected award id, `ok = false`, and exits **non-zero**.
- Step 4: the row is restored and `wallet_reconcile.php` exits 0 again.

---

## 7. Section E — Phase 21E: Admin visibility (`QA21E-01` … `QA21E-08`)

Section E is **read-only**: it renders the rows 21D writes and the arithmetic 21B proves on the admin
pages an operator already uses — the fifth commissions tab, the board card and filter on the member
list, the member panel, and the four report cards. **No controller, model or view in this phase writes
money** (21E Rule 1).

**Prerequisites.** Section D complete; fixture `h = 3` paid (₱50,000.00 paid, 18 awards, 13 recipients)
and fixture `F` baseline-armed (₱12,000.00 excluded, 5 baseline rows) on a scratch copy; the app
running, signed in as `admin`. Expected suite count at this gate: **≥ 1,454** PHP cases; smoke
unchanged at **268**.

### QA21E-01 — The ledger tab shows paid awards, totals and recipients

| Why | Rule 4 of §12.5: *Paid* counts `status = 'credited'` only; baseline rows stay out of paid totals. |
|---|---|
| Where | `/admin/commissions?ledger=general_harvest` as `admin` |
| Evidence | `QA21E-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. With fixture `h = 3` paid, open `/admin/commissions` and select the **General Harvest bonus** tab
   (`?ledger=general_harvest`).
2. Read the stats tiles: paid, awards, recipients, baseline, open, unsettled positions.

Expected

- **₱50,000.00** paid, **18** awards, **13** recipients, baseline **₱0.00** (on the paid network),
  open **₱0.00**, unsettled positions **0**.
- The `Position`, `Block`, `Queue (N)` and `Status` columns exist; rows render with their member and
  wallet links.

### QA21E-02 — Filters narrow the rows; baseline rows are their own tile

| Why | Rule 10 of §12.5: filters fall back silently on unknown values; baseline is not credited money. |
|---|---|
| Where | The ledger tab, filtering by level and by status; fixture `F` for baseline |
| Evidence | `QA21E-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Filter level `3` on the paid network: expect one row (node 0, ₱8,000).
2. Switch to fixture `F` (armed at `N = 13`), filter status *Before go-live*: expect five rows, no wallet link.
3. Combine two filters (level + status) and confirm they intersect.
4. Type a garbage level value and confirm it falls back to "no filter" (no error).

Expected

- Each filter filters correctly; two combined filters intersect; a bad value means "no filter" and
  returns the full set rather than an error.
- Baseline rows appear in their own tile/column (₱12,000.00 before go-live) and **not** inside *Paid*.

### QA21E-03 — A row links to its member, panel and wallet movement

| Why | Rule 6 of §12.5: a finance reviewer walks award → member → ledger row in one click each. |
|---|---|
| Where | Click a row's member link on the ledger tab, then its wallet link |
| Evidence | `QA21E-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On `/admin/commissions?ledger=general_harvest`, click a credited row's member link.
2. Confirm `/admin/members/{id}` opens with the General Harvest panel showing the same node, position
   and money.
3. Back on the ledger, click the wallet link → the movement row.

Expected

- The member panel shows position, node, settled state, the ten-cell grid and the awards table,
  matching the ledger row.
- The wallet link opens the member's statement filtered to that `ref_id`.

### QA21E-04 — CSV export matches the screen and is audited

| Why | Rule 12 of §12.5: the export columns match the screen plus the wallet reference, with one
  `general_harvest.export` audit row. |
|---|---|
| Where | Export the filtered ledger CSV; then `/admin/audit` |
| Evidence | `QA21E-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Apply a filter (level 3, or status baseline on fixture `F`) on the ledger tab.
2. Download the CSV; compare columns and values with the screen.
3. Open **System → Audit log** and find the `general_harvest.export` row.

Expected

- Header row plus one row per filtered award; columns match the screen plus the wallet reference
  (`ref_type`, `ref_id`).
- Exactly one `general_harvest.export` audit row carrying the tab, the filters and the row count.
- Export with no rows yields a header-only file plus one audit row.

### QA21E-05 — The member list: GH column, board card, paid filter

| Why | Rule 5 of §12.5: nodes are read from the ledger and queue, never recomputed; the board card shows
  the frontier and the open liability. |
|---|---|
| Where | `/admin/members` and `?board=paid` / `?board=none` |
| Evidence | `QA21E-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/members` with fixture `h = 3` paid.
2. Read the `GH` column (`3 / 10`, `2 / 10`, `1 / 10` … for node holders; `—` otherwise).
3. Read the board card: `N = 40`, settled 40, frontier `13 / 4 / 1`, "level 1 next at N = 43".
4. Apply `?board=paid` and confirm it filters to the 13 recipients; `?board=none` filters to node
   holders with no credited level.

Expected

- The `GH` column shows credited levels out of ten for node holders; non-holders show `—`.
- The board card shows `N`, settled, the per-level frontier (rows written / next `seq` / completing
  `N` / amount) and the open liability `₱0.00` (`boardTotal(N) − boardTotal(settled_n)`).
- The paid filter survives pagination and intersects with the status filter.

### QA21E-06 — An unqualified member has no board panel

| Why | D-02: earning requires holding a node, which requires Phase 20 qualification (≥ 3 directs). |
|---|---|
| Where | `/admin/members/{id}` for a member with fewer than 3 directs |
| Evidence | `QA21E-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/members/{id}` for a member who has not qualified.
2. Read the gate line and confirm there is no board panel.

Expected

- *"Not qualified: 2 of 3 direct members"* (or the current count) and no General Harvest board panel,
  no `GH` value, no awards table.

### QA21E-07 — The reports cards match the ledger tab

| Why | Rule 13 of §12.5: the report cards and the ledger tab call the same model methods. |
|---|---|
| Where | `/admin/reports`, then export the range CSV |
| Evidence | `QA21E-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/reports` with fixture `h = 3` paid.
2. Read the four General Harvest cards: paid total, by-level split, bands.
3. Export the range CSV with `type=general_harvest` and find the General Harvest block.

Expected

- The paid total **₱50,000.00**, the by-level split (26,000 / 16,000 / 8,000 across levels 1–3) and
  the bands equal the ledger tab.
- The range CSV includes the award rows and the board summary block.

### QA21E-08 — The four existing tabs are unchanged

| Why | Rule 2 of §12.5: package, repeat-purchase, Royalty and Team Harvest markup stay byte-identical;
  `ParityTest` stays green. |
|---|---|
| Where | Switch among the Package, Repeat, Royalty and Team Harvest commissions tabs |
| Evidence | `QA21E-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On `/admin/commissions`, open each of the four legacy tabs in turn.
2. Confirm each looks and behaves exactly as before the General Harvest tab was added.

Expected

- All four legacy tabs are byte-identical to their pre-21E state; no new route; `ParityTest` and
  `RouterTest` green; the only addition is the fifth General Harvest pill.

---

## 8. Section F — Phase 21F: Member visibility (`QA21F-01` … `QA21F-09`)

Section F is **read-only member presentation**: the fifth earnings tab, the ten-block card, the
progress card, the dashboard tile, the genealogy badge and the member CSV. Every number comes from
`statusFor()` / `blocks()` (21D); the view formats, never computes. The copy rules are normative.

**Prerequisites.** Section D complete; fixture `h = 3` paid; the app running signed in as a member who
holds node 0 (position `#1`, `N = 40`). Expected suite count at this gate: **≥ 1,478** PHP cases;
smoke unchanged at **268**.

### QA21F-01 — The earnings tab shows position, blocks, progress and awards

| Why | Rule 10 of §12.5: every number on the member card comes from the service. |
|---|---|
| Where | `/earnings?ledger=general_harvest` as node 0 of fixture `h = 3` |
| Evidence | `QA21F-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As node 0 of fixture `h = 3`, open `/earnings?ledger=general_harvest`.
2. Read position `#1`, `N = 40`, the ten blocks, the progress card, the roster page and the award rows.

Expected

- Position `#1`, current `N = 40`, ten block rows (filled of slots, each with its amount), progress
  card, paginated roster and award rows — all rendering without error.
- L3 paid ₱8,000.00; L4 `0 of 81` at ₱12,000.00 needing `N = 121`.

### QA21F-02 — The block card states the network rule, the gap and the footnotes

| Why | Rules 2, 3, 4, 7 of §12.5: the network sentence, the position gap in numbers, and the
  purchases/rank/Active footnote are mandatory and singular. |
|---|---|
| Where | Read the block card on `/earnings?ledger=general_harvest` |
| Evidence | `QA21F-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Read the block card once: the network sentence, the "needs the queue to reach N" line per level,
   the purchases/rank/Active footnote, and the *Before go-live* label if any baseline row exists.

Expected

- The network sentence present verbatim and exactly once (*"A slot in your block is filled by a
  qualified member of the whole network — not necessarily anyone in your team…"*).
- Every level row reads *"needs the queue to reach N"* with an exact number; never "soon" or "almost".
- The purchases/rank/Active footnote present once (*"This bonus does not depend on any purchase, on
  your rank, or on the monthly Active status."*).
- If a baseline row exists, it is labelled *Before go-live* and excluded from *Earned*.

### QA21F-03 — An unqualified member sees the gate, not a board

| Why | D-02: a member without a node holds no block and earns nothing. |
|---|---|
| Where | `/earnings?ledger=general_harvest` as a member with fewer than 3 directs |
| Evidence | `QA21F-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As a member who has not qualified, open the tab.
2. Read the gate explanation, the current direct count, and confirm no board and no awards table.

Expected

- The tab explains the gate ("3 direct members"), shows the current count, shows no board and no
  awards table; no money figures appear.

### QA21F-04 — Filters, totals and the member CSV agree

| Why | Rule 12 of §12.5: every query is scoped to the session member; the CSV is model-scoped so
  `?user_id=` cannot widen it. |
|---|---|
| Where | Filter rows by level/status on the earnings tab; export the CSV; append `&user_id=` |
| Evidence | `QA21F-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Filter the award rows by level and by status; confirm totals match the rows.
2. Export the CSV and compare it with the screen.
3. Append `&user_id=<another id>` to the tab URL and to the export URL.

Expected

- Filtered totals match the filtered rows; the CSV columns match the screen.
- `&user_id=` is ignored: the page and the file are unchanged (only the session member's rows).

### QA21F-05 — The dashboard tile shows paid total, position and next block

| Why | Rule 11 of §12.5: the tile is one live surface (`member.dashboard:{id}`) with no action or form. |
|---|---|
| Where | `/dashboard` as node 0 of fixture `h = 3` |
| Evidence | `QA21F-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/dashboard` as node 0 of fixture `h = 3`.
2. Read the General Harvest tile: paid total, position, next block's progress.

Expected

- The tile shows the paid total (₱14,000.00), the position (`#1`) and the next block's progress
  (L4 `0 of 81`, needs `N = 121`); no action, no form.

### QA21F-06 — The genealogy badge shows positions only

| Why | D-20: a member sees a username only inside their own sponsor subtree; everybody else is
  "a member outside your team" with their position. |
|---|---|
| Where | `/genealogy` as the reader |
| Evidence | `QA21F-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/genealogy` as node 0 of fixture `h = 3`.
2. Confirm the badge shows the reader's own position and node, and each queued downline member's
   position; unqualified members show `—`.

Expected

- The reader's badge shows position `#1` / node 0; downline queued members show their positions;
  unqualified members show `—`; no stranger's username appears outside the reader's subtree.

### QA21F-07 — The other earnings tabs are unchanged; the tab refreshes live

| Why | Rule 11 of §12.5: package, repeat-purchase, Royalty and Team Harvest tabs keep their URLs and
  bytes; one live surface refreshes all five. |
|---|---|
| Where | Switch among the five earnings tabs; trigger an award and watch the General Harvest tab |
| Evidence | `QA21F-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As the same member, open the Package, Repeat, Royalty and Team Harvest tabs: unchanged.
2. Wait for an award (or trigger one with a registration) and watch the General Harvest tab refresh.

Expected

- The four legacy tabs look and behave exactly as before (regression).
- The General Harvest tab bumps on `member.earnings:{id}` and the open tab refreshes; the other four
  tabs are unaffected.

### QA21F-08 — `?user_id=` cannot widen the member's data

| Why | Rule 12 of §12.5: every query and the CSV export are scoped to the session member in the model. |
|---|---|
| Where | Append `&user_id=<another id>` to the tab and to the export |
| Evidence | `QA21F-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/earnings?ledger=general_harvest&user_id=<another member id>`.
2. Export the same URL with `&user_id=` and compare the file.

Expected

- The page and the file are unchanged: only the caller's rows, **0** audit rows on the export.

### QA21F-09 — The card and tab hold at mobile width

| Why | Rule 18 of §12.5 / layout risk: the ten-block table scrolls inside its card; no page-level
  horizontal scroll. |
|---|---|
| Where | `/earnings?ledger=general_harvest` and the block card at 390 px |
| Evidence | `QA21F-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Repeat the block card and the tab at 390 px width (mobile emulation).
2. Confirm the ten-block table scrolls inside its card and the page never scrolls horizontally.

Expected

- Readable at 390 px; no page-level horizontal scroll; the card stays ten levels.

---

## 9. Section G — Phase 21G: Release (`QA21G-01` … `QA21G-06`)

Section G is the release: documentation that matches the code, `APP_PHASE 21`, a green acceptance run,
and the signed gate record. **It changes no behaviour** (21G Rule 1).

**Prerequisites.** Tag `p21f-member` (or the `p21g-release` tree) on `feature/general-harvest`; sections
A–F complete and reviewed; the app running for `QA21G-04`; a **scratch schema** for `QA21G-05`.
Expected suite count at the gate: **1,484** PHP cases; smoke **268** checks.

> **Notation.** As in sections A–F: `mysql` is the Laragon client, `php` is Laragon's PHP 8.3 CLI, and
> every command runs from `system/`. A command that must hit a different schema sets `ECO_DB_NAME` first
> (`$env:ECO_DB_NAME='ecowealth_sneat_tool_b_test'`).

**What section G changes in the app.** `APP_PHASE` **19 → 21** in `system/app/config.php`, the
`HardeningTest` assertion, and the documents. **No route, no schema, no setting, no money rule:** the
phase is the label, the proof and the paperwork.

The automated pass, run before these checks:

```text
php bin\build_install_sql.php --check     → exit 0, no output
php tests\run.php                         → Cases: 1,484   Passed: 1,484   Failed: 0   All good.
powershell -File tests\smoke.ps1          → Passed: 268   Failed: 0
php bin\wallet_reconcile.php              → exit 0, General Harvest section clean
php bin\general_harvest_catchup.php --doctor → exit 0
```

### QA21G-01 — The automated release gate

| Why | §2.4 and §11 Rule 2: no phase is signed from a red or unmeasured baseline. |
|---|---|
| Where | PowerShell, from `system/` |
| Evidence | `QA21G-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\build_install_sql.php --check` — exit 0 and no output.
2. `php tests\run.php` — the full suite; copy the last three lines.
3. `powershell -NoProfile -File tests\smoke.ps1` — the HTTP walk; copy the summary line.
4. `php bin\wallet_reconcile.php` — exit 0; copy the General Harvest section.
5. `php bin\general_harvest_catchup.php --doctor` — exit 0 (on a seeded copy; if the development copy
   is unseeded, run it against the scratch schema `QA21G-05` builds).

Expected

- `Cases: 1,484   Passed: 1,484   Failed: 0` followed by `All good.`
- `Passed: 268   Failed: 0`
- The bundle check prints nothing and exits 0; the reconciler reports every section clean, including
  **General Harvest**; the doctor's lines are green.
- If it fails: **stop**. A red suite or smoke run is not a documentation issue; fix it in the phase that
  owns the behaviour, re-run that phase's gate and record the deviation (Rule 1). Do not sign from a
  partial run.

### QA21G-02 — The cold read

| Why | §11 Rule 3 and §17 risk 16: the author cannot be the tester who proves the pack is readable. |
|---|---|
| Where | this document, the plan and the app |
| Evidence | `QA21G-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Hand sections A–F to a tester who has not seen `dmp/plan-ecowealth-general-harvest.md` and ask them
   to execute them. Record every question they have to ask.
2. Separately, read plan §12 and this pack against the build and check every number, URL, action name
   and exit code the documents state.
3. Fix every finding in the document that owns it; a finding that needs a code change is a defect in the
   phase that owns the behaviour (Rule 1).

Expected

- **Zero questions** from the cold tester; every check names its exact URL or command, the exact
  expected number, and what to do when it fails.
- The author's sweep finds only documentation drift, fixed here; the residual list is empty.
- If it fails: the question or the drift is a documentation defect, not a tester error. Fix the document
  and re-run the affected check; do not change the code to match a document (the code wins).

### QA21G-03 — The release acceptance table

| Why | §11 Rule 5: every acceptance criterion is ticked against a named phase and a named case. |
|---|---|
| Where | plan §11 *Release acceptance table*; the suite and the evidence folder |
| Evidence | `QA21G-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Walk the plan's release acceptance table row by row. For each row, name the phase, the passing
   `P21X-Tn` case(s) or the evidence file that proves it.
2. Record the completed table in the evidence file, with no row left as `—`.

Expected

- **Every row ticked**; no acceptance criterion rests on an unexecuted check.
- `wallet_reconcile.php` and the doctor are named on their own rows; the regression claims
  (`P21E-T1`, `P21F-T9`) are named where the table asks.
- If it fails: an unticked row keeps the release unsigned. Find the missing proof or record the
  exception in the sign-off sheet; never tick a row on intention.

### QA21G-04 — The build reports 21

| Why | D-16 and the exit gate: one constant, asserted in code and visible in the suite header. |
|---|---|
| Where | `system/app/config.php`, `php tests\run.php`, `HardeningTest`, browser as `admin` |
| Evidence | `QA21G-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php tests\run.php Hardening` → the `APP_PHASE 21` case passes, and the suite header reads `phase 21`.
2. Read `system/app/config.php`: `defined('APP_PHASE') || define('APP_PHASE', 21);`, with the comment
   naming Phase 21 and the plan.
3. As an admin, open `/dashboard` → **200**, then every shipped menu item → **200**, no new item, no 500.
4. As a member, open `/dashboard` → **200** and the General Harvest tile hidden while the stream is off.

Expected

- The runtime constant, the suite header, the `HardeningTest` case and the pages all report **21**.
- No menu item appears that 21F did not ship; the constant only labels the build.
- If it fails: a page that 500s is a regression, not a badge problem; `APP_PHASE` landing without its
  `HardeningTest` assertion is a release defect (they are one deliverable in one commit).

### QA21G-05 — The scratch install and the go-live order

| Why | §11 Rules 2 and 6: the documented commands are executed, and the go-live order is the runbook's one
  owner. |
|---|---|
| Where | a **scratch schema** (`ecowealth_sneat_tool_b_test`), the CLI and `docs/runbook.md` |
| Evidence | `QA21G-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Follow [`docs/deploy.md`](../../deploy.md) on the scratch schema: build the bundle, install,
   confirm **39 tables / 79 settings / 10 ladder rows** and the stream **off** and unarmed.
2. Follow the one go-live order in [`docs/runbook.md`](../../runbook.md) §*General Harvest go-live order*:
   deploy → configure the ladder → arm dry run → arm → enable → catch up → reconcile → doctor. Record
   the exit code of every step.
3. Register the 13 accounts of fixture `h = 1` through the real registration path; see one
   ₱2,000.00 credit.
4. `php bin\wallet_reconcile.php` → exit 0; `php bin\general_harvest_catchup.php --doctor` → exit 0.

Expected

- The arm dry run writes nothing and prints `N₀`, the row count and the excluded amount; the real arm
  writes contiguous baseline rows and `settled_n = N₀`; enabling pays nothing by itself.
- The qualifying registration writes **one** award and **one** withdrawable credit (₱2,000.00), and a
  second settlement writes none.
- Every command in `deploy.md` and the runbook executed here is copy-pasteable as written; the go-live
  order exists in one place and every other document links to it.
- If it fails: do not hand-edit an award or a position. The failing step names the owner (install →
  deploy.md/21A; arm → 21C; award → 21D); fix that phase and re-run its gate.

### QA21G-06 — The evidence folder and the gate records

| Why | §11 Rule 4 and the exit gate: a factual record, not a claim. |
|---|---|
| Where | `docs/qa/evidence/phase-21/`, this sheet, `docs/phase-log.md`, plan Appendix H |
| Evidence | `QA21G-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. List `docs/qa/evidence/phase-21/` and match every `QA21X-nn` check in this document to a file.
2. Read `docs/qa/sign-off-sheet.md`: the Phase 21 section (21A–21G) and the seven gate rows.
3. Read `docs/phase-log.md`: seven Phase 21 entries plus the release entry, with tag, counts and
   deviations.
4. Read plan Appendix H: the seven gate rows, the deviation log and the acceptance sign-off.

Expected

- **One file per check** (58 files: A 8 · B 9 · C 8 · D 10 · E 8 · F 9 · G 6), named exactly as the
  check's *Evidence* line; the folder's `README.md` indexes them.
- The sign-off sheet carries the seven Phase 21 gate records (21A … 21G), each with a decision and the
  tester line; the phase log has the seven Phase 21 entries (21A–21G); Appendix H is filled.
- If it fails: a missing file means that check was not executed — do not sign it. Record the exception
  in the sign-off sheet's defect log with its phase and re-open that phase's gate.

---

## 10. Evidence — sections D–G

Save each check's transcript under `docs/qa/evidence/phase-21/`, named exactly as its **Evidence**
line (`QA21D-01.txt` … `QA21D-10.txt`, `QA21E-01.txt` … `QA21E-08.txt`, `QA21F-01.txt` …
`QA21F-09.txt`, `QA21G-01.txt` … `QA21G-06.txt`). The folder's `README.md` is the index and stays
current as each section is appended. Section G's evidence captures the suite and smoke summary lines,
the completed acceptance table, the deploy/go-live commands with their exit codes and the one award
observed, and the file inventory plus the gate-record check.

## 11. Cleanup — sections D–G

Section D writes **only** on its scratch copy; its money steps move test money that stays on the
scratch schema (dropped or reset afterward). Section D's only development-copy touch is none — it adds
no page and writes no row there. Section E and section F write nothing at all (read-only pages and
scoped exports); the export's single audit row is on the scratch/development copy and is restored by
the smoke walk's own cleanup. Section G's `QA21G-05` writes on a **scratch schema**: drop it
(`DROP DATABASE ecowealth_sneat_tool_b_test;`) or reset it (`php bin\db_reset.php --force
--confirm=ecowealth_sneat_tool_b_test`), which keeps the ladder and empties the queue and awards. The
automation writes nothing to the development copy except the smoke run's own restored setting and
audit rows; `QA21G-01` and `QA21G-04` are read-only. If the scratch schema is kept for a demonstration,
leave the stream **off** and the queue as the seed left it; it is not a production copy.

## 12. Sign-off line

**Phase 21 QA pack — sections A–G complete.** 58 checks (A 8 · B 9 · C 8 · D 10 · E 8 · F 9 · G 6),
each with an evidence file under `docs/qa/evidence/phase-21/`, indexed by that folder's `README.md`.
The automated gate is green at **1,484 / 1,484** PHP cases and **268 / 268** smoke checks; the bundle
check and the reconciler exit 0; the doctor exits 0; the release acceptance table is complete; the sign-off
sheet carries the seven gate records; `APP_PHASE = 21` is asserted by `HardeningTest`.

