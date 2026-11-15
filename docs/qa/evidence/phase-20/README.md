# Evidence — Phase 20 (Team Harvest bonus)

One file per check, named exactly as the check's **Evidence** line. A check that produces only counts,
SQL output or a CLI exit code is captured as a **text transcript** (`.txt`); a check that shows a
browser screen is captured as a screenshot (`.png`). Phase 20 ships no new route, so the sections are
transcripts that assert counts, tables and rendered markup through the application itself.

This folder is created by **20A** and indexed here. Every later phase appends its own rows; the file is
never rewritten.

Index:

| File | Check | What it shows | Status |
|---|---|---|---|
| `QA20A-01.txt` | QA20A-01 | The pre-migration dump outside the repository, and the development copy at **33 tables / 70 settings** with no `harvest%` table | pending (manual gate) |
| `QA20A-02.txt` | QA20A-02 | A **p20-start** scratch schema (from `git show p20-start:…install.sql`), `SHOW TABLES LIKE '%harvest%'` empty before and three rows after, migration `024` applied **twice** (both exit 0), the three column lists and the ledger enum ending `'team_harvest'` | captured |
| `QA20A-03.txt` | QA20A-03 | The four `team_harvest_*` settings with their defaults (`0`, `3`, `10`, `''`), `COUNT(*) = 10` / `SUM(amount) = 5095000.00`, and the ten amounts level by level | captured |
| `QA20A-04.txt` | QA20A-04 | `/admin/settings` with **no** Team Harvest block and a member page with **no** harvest panel | pending (needs the running app) |
| `QA20A-05.txt` | QA20A-05 | Three direct registrations leave `harvest_queue` at **0** and the sponsor's page unchanged | pending (needs the running app) |
| `QA20A-06.txt` | QA20A-06 | The member and admin wallet statement filters list **Team Harvest bonus** and select **no rows** | pending (needs the running app) |
| `QA20A-07.txt` | QA20A-07 | `build_install_sql.php` (64 statements) and `--check` exit 0; a fresh CLI install on `ecowealth_sneat_tool_a_test` reporting **36 / 77 / 10** (plus 2 / 0 / 7 / 2 / 18 / 10 / 0); `wallet_reconcile` exit 0 | captured |
| `QA20A-08.txt` | QA20A-08 | A dry run planning the **harvest** step, the real reset on a scratch schema emptying the queue and awards, the baseline back to `''`, the ten ladder rows and four settings kept, the counters at 1, and a clean verification | captured |
| `QA20B-01.txt` | QA20B-01 | The Team Harvest card on the migrated development copy: ten reference amounts, switch **off**, gate `3`, depth `10`, the strip **Not seeded** naming `bin/harvest_queue_seed.php`, the ₱5,095,000.00 per-member maximum, the four settings and the ten ladder rows, and an empty queue | captured (automated pass) |
| `QA20B-02.txt` | QA20B-02 | Level 1 → ₱1,500 and level 10 → ₱4,000,000 saved; total **₱5,595,500.00**; `team_harvest.ladder.update` with before/after naming `levels.1` and `levels.10`; `updated_by` on all ten rows; restored to the reference ladder | captured (automated pass) |
| `QA20B-03.txt` | QA20B-03 | Three levels at ₱10,000,000 (₱30,195,000.00) refused with the `sum` error; the stored ladder unchanged; the plan's ₱20,000,000 level-10 step is refused by the **per-level** ceiling first (deviation recorded) | captured (automated pass) |
| `QA20B-04.txt` | QA20B-04 | The preset rides on each input's `data-reference` (`1000.00 … 3500000.00`); saving the preset restores ₱5,095,000.00 (the **Load reference ladder** button itself is JavaScript and needs the browser) | captured (server-side equivalent) |
| `QA20B-05.txt` | QA20B-05 | Clearing the ten and enabling the stream is refused on the switch with the exact message; the stored switch stays `0` and the ladder is untouched | captured (automated pass) |
| `QA20B-06.txt` | QA20B-06 | Gate `11` refused (stored `3` survives); depth `3` accepted and levels 4–10 marked **Not paid** with their amounts kept; depth restored to `10` | captured (automated pass) |
| `QA20B-07.txt` | QA20B-07 | Three registrations under one sponsor leave `harvest_queue` at **0**, no award and no `team_harvest` wallet row | pending (needs a signed-in browser session on `/register`) |
| `QA20B-08.txt` | QA20B-08 | The card saves and validates with JavaScript disabled; only the live totals are missing | pending (needs a browser with JS off) |
| `QA20B-09.txt` | QA20B-09 | 390 px: the ten inputs stack, the saved table scrolls inside the card, no page-level horizontal scroll; 1440 px: two columns | pending (needs a browser at both widths; `P20B-T18` pins the markup) |

Notes for the reader:

- `mysql` in the transcripts is `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` run as the
  application account through a temporary option file (never on the command line); `php` is Laragon's
  PHP 8.3 CLI. Every command ran from `system/`.
- `QA20A-02` and `QA20A-03` run on `ecowealth_sneat_tool_a_test` built from the **`p20-start`
  bundle** (`git show p20-start:system/database/install.sql`), so they prove the migration upgrades a
  real Phase 19 database, not a synthetic one.
- `QA20A-07` and `QA20A-08` use the pre-provisioned scratch slots (`…_tool_a_test`,
  `…_tool_b_test`); the development copy (`ecowealth_sneat`) is not touched by them.
- The development copy is **migration-built**: it carries no `install_*` metadata rows, so it reads
  **70 settings** before migration `024` and **74** after it. A fresh install reads **73** and **77**
  respectively — the bundle's footer adds the three install metadata rows.
- `QA20A-01`, `04`, `05` and `06` are the human part of the gate: the backup, the two **absence**
  checks on the running app, the three-registration proof that no hook exists, and the wallet filter.
  They are recorded when the project lead runs the manual pass; the automated pass captured the other
  four on scratch schemas.
- Section B's transcripts `QA20B-01` … `QA20B-06` were captured by the automated pass **on the
  development copy** (`ecowealth_sneat`, migrated to `024`): the card is rendered through
  `AdminSettingsController::settingsContext()` + `View::page(...)` with the seeded `admin` signed in,
  and saves/refusals go through the controller's real `applySection()` path. `QA20B-04` is the
  **server-side equivalent** of the JavaScript button — the preset's `data-reference` values and a
  save round-trip; the click itself needs a browser. `QA20B-07` (a signed-in `/register` session),
  `QA20B-08` (JavaScript off) and `QA20B-09` (390/1440 px) are the human part and were left for the
  lead, exactly as section A's page checks were.
- The automated pass restored the reference ladder, gate `3`, depth `10` and the switch `0` on the
  development copy before it exited; its only lasting trace is the `team_harvest.ladder.update` and
  `settings.update` rows it wrote (the audit trail the checks describe).
