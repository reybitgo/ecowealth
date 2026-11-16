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
