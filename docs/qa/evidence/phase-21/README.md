# Evidence — Phase 21 (General Harvest bonus)

One file per check, named exactly as the check's **Evidence** line. A check that produces only counts,
SQL output or a CLI exit code is captured as a **text transcript** (`.txt`); a check that shows a
browser screen is captured as a screenshot (`.png`). Phase 21 ships no new route, so the sections are
transcripts that assert counts, tables and rendered markup through the application itself.

This folder is created by **21A** and indexed here. Every later phase appends its own rows; the file is
never rewritten.

Index:

| File | Check | What it shows | Status |
|---|---|---|---|
| `QA21A-01.txt` | QA21A-01 | The pre-migration dump outside the repository (`ecowealth_sneat-pre-21a-20261116-125034.sql`, 2,450,330 bytes, 36 `CREATE TABLE` and 29 `INSERT INTO` statements), the development copy's **36 tables / 74 settings**, and `SHOW TABLES LIKE 'general%'` empty while the Phase 20 `harvest%` tables are present | captured |
| `QA21A-02.txt` | QA21A-02 | A **p21-start** scratch schema (built from `git show p20g-release:system/database/install.sql`: 36 tables / 77 settings, no `general%` table), migration `025` applied **twice** (both exit 0), the three tables, **39 tables / 79 settings**, the two documented column lists and the ledger enum ending `'general_harvest'` | captured |
| `QA21A-03.txt` | QA21A-03 | The two `general_harvest_*` settings (`0`, `10`), `COUNT(*) = 10` / `SUM(amount) = 8381000.00`, the ten amounts level by level and the single board row `(1, NULL, NULL, NULL, 0)` | captured |
| `QA21A-04.txt` | QA21A-04 | The settings page (`AdminSettingsController::settingsContext()`), the member directory (`listContext()`) and a member page (`memberContext()`) rendered through `View::page()`: **0** occurrences of *General Harvest* on all three, with the Team Harvest panel still present as the positive control | captured (harness render) |
| `QA21A-05.txt` | QA21A-05 | Three `RegistrationService::register()` calls in two stages: unseeded (queue **0**, awards **0**, no money — the 20C hook is inert) and seeded (the sponsor reaches **position #3**, awards **0**, no money) | captured (harness) |
| `QA21A-06.txt` | QA21A-06 | The member statement and the admin wallet monitor: the filter lists **General Harvest bonus** with `value="general_harvest"`, `wallet_ref_label()`/`wallet_ref_icon()` resolve, and the filter selects **no rows** | captured (harness render) |
| `QA21A-07.txt` | QA21A-07 | `build_install_sql.php` rebuild (70 statements / 97,710 bytes) and `--check` exit 0; a CLI preflight on an empty scratch schema; a real install reporting **39 / 79 / 2 / 0 / 7 / 2 / 18 / 10 / 0 / 10 / 1** with the **General Harvest board … (unarmed)** check OK; `wallet_reconcile` exit 0; the fresh schema's unarmed board and ₱8,381,000.00 ladder | captured |
| `QA21A-08.txt` | QA21A-08 | The dry run planning **general_harvest** (1 statement) and **general_harvest_board** (1 statement); the real reset emptying the ledger, returning the board to `(NULL, NULL, NULL, 0)`, keeping the ten ladder rows (the operator's ₱2,500.00 intact, total ₱8,381,500.00), the two settings and 24 auto-increment counters, and verifying clean | captured |
| `QA21B-01.txt` | QA21B-01 | The **General Harvest bonus** card on `/admin/settings` → Commissions rendered through the real controller: the ten reference amounts and their `data-reference` presets, the switch **off**, the depth **10**, the generated ladder (`3 … 59,049` / `4 … 88,573` / cumulatives to **₱8,381,000.00**), the position table (`4 13 40 121` … `19 58 175 526`), the strip `unarmed` naming `bin/general_harvest_arm.php`, the node-#1/#2/#3 sentence, the calculator's defaults and **no** arm control | captured (harness render) |
| `QA21B-02.txt` | QA21B-02 | A real `applySection()` save of level 1 ₱2,500.00 and level 10 ₱4,000,000.00: the two amounts persist, the total reads **₱7,381,500.00**, one `general_harvest.ladder.update` audit row carries the switch, depth and ten amounts before/after plus `changes`, and an unchanged save writes no second row | captured (harness save) |
| `QA21B-03.txt` | QA21B-03 | Three levels at ₱10,000,000.00: the **sum** error names the typed ₱30,401,000.00, no setting or ladder row is written, the stored ladder is unchanged and no audit row appears | captured (harness save) |
| `QA21B-04.txt` | QA21B-04 | **Load reference ladder**: the preset the button fills, the `data-reference` on each input, the save that stores it, the card re-reading the ten values and the total **₱8,381,000.00** with the depth still 10 | captured (harness render + save) |
| `QA21B-05.txt` | QA21B-05 | The switch with every amount cleared: the refusal on `general_harvest_enabled` names **level 1**, the stored switch stays off and the stored ladder is unchanged | captured (harness save) |
| `QA21B-06.txt` | QA21B-06 | A depth of **3** with levels 4–10 cleared: accepted, the ten rows are kept and exactly **7** are marked *not paying at this depth*; raising the depth to a blank level 5 is refused on the depth; `11` is a field error; the stored depth survives every refusal | captured (harness save + render) |
| `QA21B-07.txt` | QA21B-07 | Four `RegistrationService::register()` calls with the queue seeded and the switch on: the sponsor reaches its position, **no** award, **no** `general_harvest` wallet row, **no** board change and no wallet movement; the QA members are deleted again | captured (harness) |
| `QA21B-08.txt` | QA21B-08 | The calculator's closed form at `N = 1000` — **₱1,601,000.00** in **493** awards — its server-rendered default at the current queue size, every number present without JavaScript, and an unchanged card still validating and saving | captured (harness render + save) |
| `QA21B-09.txt` | QA21B-09 | The layout facts: **10** stacking cells (`col-12 col-md-6`), **2** generated tables inside `.table-responsive`, no fixed `min-width` and no `overflow-x: hidden` in the block; the visual 390 px/1440 px confirmation is noted as the lead's item | captured (harness render) |
| `QA21C-01.txt` | QA21C-01 | An unarmed **40-position** copy (39 tables, 121 members, gapless queue, marker `2026-11-01 00:00:00`): `--dry-run` exits 0 and reports **N0 = 40**, **18 rows across 3 levels**, **₱50,000.00 excluded**, the ten levels with `no complete block at this N0` from level 4, the first 18 positions, **0** award rows and a board still `(NULL, NULL, NULL, 0)` | captured |
| `QA21C-02.txt` | QA21C-02 | The real arm: the same report, exit 0, then **18 rows / ₱50,000.00** in `general_harvest_awards`, the board `(40, 2026-11-17 01:29:30, NULL, 40)` (armed and settled at N0) and one `general_harvest.arm` audit row whose `meta_json` carries `n`, `rows`, `excluded`, `per_level`, `trigger: cli` | captured |
| `QA21C-03.txt` | QA21C-03 | The prefix invariant level by level: **13 / 4 / 1** rows, `MIN(seq) = 0`, `MAX(seq) = 12 / 3 / 0`, `COUNT(*) = COUNT(DISTINCT seq) = MAX(seq) + 1` | captured |
| `QA21C-04.txt` | QA21C-04 | A second arm and a second dry run both refused `armed` (exit 1, the message naming the first arm and the business reset), with the **18 / ₱50,000.00** rows and the board unchanged | captured |
| `QA21C-05.txt` | QA21C-05 | A **second unarmed copy** with `general_harvest_enabled = 1`: `--dry-run` and the real run both refused `enabled` (exit 1) with **0** awards and an unarmed board; switching the stream off arms it, and `--json` prints exactly `armed, n, rows, excluded, per_level, dry_run` (**40 / 18 / 50000 / 13,4,1,0…**) | captured |
| `QA21C-06.txt` | QA21C-06 | **18** rows, all `baseline`, **0** `general_harvest` wallet rows, **0** rows with a `ledger_id`, and `bin/wallet_reconcile.php` exit 0 with its Team Harvest section unchanged (credits 0, ledger 0.00) | captured |
| `QA21C-07.txt` | QA21C-07 | Member `qa21c_m1` (queue #1 = node 0): balances **0.00 / 0.00**, the three baseline rows it holds at `seq = 0`, and **0** statement rows in `ewallet_ledger` — the wallet lists no General Harvest movement | captured (SQL; the `/wallet` walk is the lead's item) |
| `QA21C-08.txt` | QA21C-08 | `bin/db_reset.php --force --confirm=…` on the armed copy: the reset's own steps delete **18** General Harvest rows and **40** queue positions, return the board to unarmed, keep the catalogue, and its verification returns **15 of 15 OK** (including Team Harvest, wallets and `live 18 of 18`); **0** awards, board back to `(NULL, NULL, NULL, 0)`, the **10-row / ₱8,381,000.00** ladder kept; the arm then refuses `unseeded`, and after the queue is seeded the same command arms a new network at **40 / 18 / ₱50,000.00** | captured |
| `QA21D-01.txt` | QA21D-01 | Fixture `h = 1` (13 accounts, `N = 4`) registered in BFS order on an armed copy with the switch on: one award — seq `0`, level `1`, `threshold_n = 4`, `n_at_award = 4`, ₱2,000.00, `status = credited`, `ledger_id` set; `COUNT(*) = 1`; node 0's wallet shows one **General Harvest bonus** credit of ₱2,000.00 | captured |
| `QA21D-02.txt` | QA21D-02 | Node 0's `/wallet` statement filtered by **General Harvest bonus**: one **General Harvest bonus** credit of ₱2,000.00, `ref_type = 'general_harvest'`, `ref_id` = the award id; withdrawable bucket up by ₱2,000.00 | captured |
| `QA21D-03.txt` | QA21D-03 | `/admin/wallets` for the node-0 holder: the same credit with `ref_type = general_harvest` and `ref_id` = the award id; the row links to the member and the movement | captured |
| `QA21D-04.txt` | QA21D-04 | `php bin\wallet_reconcile.php`: exit **0**; the **General Harvest** section reports paid ₱2,000.00, 1 award, 1 recipient, baseline ₱0.00, no mismatch | captured |
| `QA21D-05.txt` | QA21D-05 | Fixture `h = 3` (121 accounts, `N = 40`) settled: **18** awards / **50000.00**; node 0 ₱14,000.00, nodes 1–3 ₱6,000.00 each, nodes 4–12 ₱2,000.00 each; node 0's wallet holds exactly ₱14,000.00 across three movements | captured |
| `QA21D-06.txt` | QA21D-06 | Registering one non-qualifying member after fixture `h = 3` is settled: no change to `general_harvest_awards` (still **18**) and no `general_harvest` wallet row (still **0**) | captured |
| `QA21D-07.txt` | QA21D-07 | Switch off: three registrations grow the queue but write no award; switch on + `general_harvest_catchup.php`: exactly the missing awards once; a second run writes nothing; `settled_n` catches up to `N` | captured |
| `QA21D-08.txt` | QA21D-08 | Armed at `N = 13`, grown to `N = 40`: seq `0` shows levels 1–2 `baseline` (₱2,000.00 / ₱4,000.00, `ledger_id NULL`) and level 3 `credited` ₱8,000.00; node 0's wallet holds **₱8,000.00 only** | captured |
| `QA21D-09.txt` | QA21D-09 | `php bin\general_harvest_catchup.php --doctor`: exit **0**; clock, tables, settings, armed state, gapless, `N = settled_n`, per-level frontier, paid `= boardTotal(settled_n) − boardTotal(baseline_n)`, baseline excluded `= boardTotal(baseline_n)`, open liability ₱0.00, reconcile clean | captured |
| `QA21D-10.txt` | QA21D-10 | Hand-deleting one `ewallet_ledger` row: `wallet_reconcile.php` reports the affected award id, `ok = false`, exits non-zero; restoring the row from backup returns exit 0 | captured |
| `QA21E-01.txt` | QA21E-01 | `/admin/commissions?ledger=general_harvest` with fixture `h = 3` paid: **₱50,000.00** paid, **18** awards, **13** recipients, baseline ₱0.00, open ₱0.00, unsettled 0; `Position`/`Block`/`Queue (N)`/`Status` columns present | captured |
| `QA21E-02.txt` | QA21E-02 | Filter level `3`: one row (node 0, ₱8,000); filter status *Before go-live* on fixture `F`: five rows, no wallet link; combined filters intersect; a garbage value falls back to "no filter" | captured |
| `QA21E-03.txt` | QA21E-03 | Click a row's member link → `/admin/members/{id}` panel showing the same node, position and money; click the wallet link → the movement row | captured |
| `QA21E-04.txt` | QA21E-04 | Export the filtered CSV: header plus one row per filtered award, columns match the screen plus the wallet reference; one `general_harvest.export` audit row with tab/filters/row count | captured |
| `QA21E-05.txt` | QA21E-05 | `/admin/members`: `GH` column `3 / 10`, `2 / 10`, `1 / 10`… for node holders, `—` otherwise; board card `N = 40`, settled 40, frontier `13 / 4 / 1`, level 1 next at `N = 43`; `?board=paid` filters to 13 recipients | captured |
| `QA21E-06.txt` | QA21E-06 | `/admin/members/{id}` for an unqualified member: *"Not qualified: 2 of 3 direct members"*, no board panel | captured |
| `QA21E-07.txt` | QA21E-07 | `/admin/reports`: four General Harvest cards with paid ₱50,000.00, by-level split (26,000 / 16,000 / 8,000) and bands equal to the ledger tab; `type=general_harvest` range CSV includes the award rows and board block | captured |
| `QA21E-08.txt` | QA21E-08 | The Package, Repeat, Royalty and Team Harvest commissions tabs byte-identical to before; `ParityTest` and `RouterTest` green | captured |
| `QA21F-01.txt` | QA21F-01 | `/earnings?ledger=general_harvest` as node 0 of fixture `h = 3`: position `#1`, `N = 40`, L3 paid ₱8,000.00, L4 `0 of 81` at ₱12,000.00 needing `N = 121`; ten blocks, progress card, roster, award rows | captured |
| `QA21F-02.txt` | QA21F-02 | Block card: network sentence verbatim and exactly once; *"needs the queue to reach N"* per level; purchases/rank/Active footnote once; *Before go-live* label if any baseline row | captured |
| `QA21F-03.txt` | QA21F-03 | Unqualified member on the tab: gate explanation ("3 direct members"), current count, no board, no awards table | captured |
| `QA21F-04.txt` | QA21F-04 | Filtered totals match the rows; CSV matches the screen; `&user_id=<other>` ignored on both page and file | captured |
| `QA21F-05.txt` | QA21F-05 | `/dashboard` tile: paid total ₱14,000.00, position `#1`, next block L4 `0 of 81` needs `N = 121`; no action, no form | captured |
| `QA21F-06.txt` | QA21F-06 | `/genealogy`: reader's badge position `#1` / node 0; downline queued members show their positions; unqualified show `—`; no stranger's username outside the reader's subtree | captured |
| `QA21F-07.txt` | QA21F-07 | Package / Repeat / Royalty / Team Harvest tabs byte-identical; triggering an award bumps `member.earnings:{id}` and refreshes the General Harvest tab; other tabs unaffected | captured |
| `QA21F-08.txt` | QA21F-08 | `&user_id=<other>` on the tab and the export: page and file unchanged; only the session member's rows | captured |
| `QA21F-09.txt` | QA21F-09 | Block card and tab at 390 px: readable, ten-block table scrolls inside its card, no page-level horizontal scroll | captured |
| `QA21G-01.txt` | QA21G-01 | `php bin\build_install_sql.php --check` exit 0 no output; `php tests\run.php` → `Cases: 1,484   Passed: 1,484   Failed: 0` / `All good.`; `powershell -File tests\smoke.ps1` → `Passed: 268   Failed: 0`; `wallet_reconcile.php` exit 0 with General Harvest section; doctor exit 0 | captured |
| `QA21G-02.txt` | QA21G-02 | Cold read of sections A–F by a tester who had not seen the plan: zero questions; every check names its exact URL/command, expected number and failure action; documentation drift fixed, residual list empty | captured |
| `QA21G-03.txt` | QA21G-03 | Release acceptance table walked row by row: every row ticked against a named phase and a named `P21X-Tn` case or evidence file; no row left as `—` | captured |
| `QA21G-04.txt` | QA21G-04 | `APP_PHASE = 21`: `HardeningTest` passes, suite header reads `phase 21`, `config.php` defines it; `/dashboard` answers 200 as admin; every shipped menu item renders 200, no new item | captured |
| `QA21G-05.txt` | QA21G-05 | Scratch install per `docs/deploy.md`: **39 / 79 / 10 / 1**, stream off and unarmed; runbook go-live order end to end (deploy → configure → arm dry run → arm → enable → catch up → reconcile → doctor); registering fixture `h = 1` yields one ₱2,000.00 credit; reconcile and doctor exit 0 | captured |
| `QA21G-06.txt` | QA21G-06 | Evidence folder inventory: one file per `QA21X-nn` check (58 files), named exactly as the check's Evidence line; sign-off sheet carries the seven Phase 21 gate records; phase log has seven entries plus the release entry; Appendix H filled | captured |

Notes for the reader:

- `mysql` in the transcripts is `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` run as the
  application account through a temporary option file (never on the command line); `php` is Laragon's
  PHP 8.3 CLI. Every command ran from `system/`.
- `QA21A-01` is the gate's only development-copy write: it takes the dump, records the **36 / 74**
  pre-migration shape, and then `QA21A-04`/`QA21A-06` are run on the migrated copy (**39 / 76** — the
  development copy is migration-built, so it carries no `install_*` metadata rows; a fresh install
  reads **79**). The dump is the rollback.
- `QA21A-04`, `05` and `06` were captured by the automated pass on the **test harness schema**
  (`ecowealth_sneat_test`, dropped and rebuilt by every suite run) through the real controllers,
  `View::page()` and `RegistrationService::register()`. The browser-only click path (opening the three
  pages in a real session and looking at the movement filter) is still the project lead's item and is
  named in the sign-off sheet; the transcripts prove the markup and the numbers behind it.
- `QA21A-05`'s plan text expects `harvest_queue = 1` after three registrations. The shipped queue is
  **unseeded**, so the 20C hook queues nobody until `bin/harvest_queue_seed.php` has run (20C Rule 2);
  the transcript shows both stages, which is stronger than the single expectation and explains the
  difference. No code changed: `P21A-T16` asserts the same inertness.
- `QA21A-02`, `03` and `08` run on `ecowealth_sneat_tool_b_test` (recreated by the tools), `QA21A-07`
  on `ecowealth_sneat_tool_a_test`; the development copy (`ecowealth_sneat`) is not touched by them.
- `QA21A-08`'s "operator amount" edit (₱2,500.00 on level 1) is deliberately left in the scratch
  schema: the next test run drops and recreates it, and the transcript records that the reset kept it.
- The harness transcripts are generated by `tests/tmp/p21a_evidence.php` (scratch space, never
  committed), the same technique as `p20abd_evidence.php` and `p20e_evidence.php`.
- Section B's nine transcripts were generated by `tests/tmp/p21b_evidence.php` on the same
  throw-away schema. They drive the real save path (`AdminSettingsController::applySection()`) and
  render the real card (`settingsContext()` + `View::page()`); a refused save writes nothing, so the
  stored ladder is left exactly as the section found it. `QA21B-02`, `04`, `05` and `06` edit the
  ladder or the depth; `04` and `06` put the reference preset and the depth `10` back, so the section
  ends where it started.
- `QA21B-07` is the only section B check that creates accounts; the script deletes all four at the
  end of the check and records the queue count before and after the cleanup.
- `QA21B-08` and `QA21B-09` carry one browser-only item each (the real JavaScript-off save, and the
  390 px/1440 px screen). The transcripts prove the markup and the numbers behind them; the visual
  pass is the project lead's item and is named in the sign-off sheet.
- Section C's eight transcripts drive the real CLI (`bin/general_harvest_arm.php`) against the
  `ecowealth_sneat_tool_b_test` / `ecowealth_sneat_tool_a_test` scratch copies, selected with
  `ECO_DB_NAME`; the development copy is not touched. The `$` command lines are the real shell
  commands. The `mysql>` lines are the transcript's label for the SQL the temporary builders
  (`storage/p21c_qa.php` and `storage/p21c_queue.php`, deleted after the pass) ran through the
  application's own PDO connection — the same account and the same statements, printed with their
  result rows; the `mysql` client shell of section A was not used. `QA21C-08` uses the real
  `bin/db_reset.php` for the reset itself.
- `QA21C-08` builds its copy from the full seed set, because the reset refuses a schema with no staff
  account; the arm is unchanged and runs on the same **121 members / 40 positions** shape.
- `QA21C-07` is the section's one browser item: the transcript proves the data behind the statement
  (zero `ewallet_ledger` rows for the member holding three baseline rows), but the signed-in walk of
  `/wallet` and the earnings filter is still the project lead's item and is named in the sign-off
  sheet — the filter returns no rows because the ledger has none.
