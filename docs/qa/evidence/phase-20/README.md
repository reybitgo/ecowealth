# Evidence — Phase 20 (Team Harvest bonus)

One file per check, named exactly as the check's **Evidence** line. A check that produces only counts,
SQL output or a CLI exit code is captured as a **text transcript** (`.txt`); a check that shows a
browser screen is captured as a screenshot (`.png`). Phase 20 ships no new route, so the sections are
transcripts that assert counts, tables and rendered markup through the application itself.

This folder is created by **20A** and indexed here. Every later phase appends its own rows; the file is
never rewritten.

Index:

| File | Check | What it shows | Status at 20A |
|---|---|---|---|
| `QA20A-01.txt` | QA20A-01 | The pre-migration dump outside the repository, and the development copy at **33 tables / 70 settings** with no `harvest%` table | pending (manual gate) |
| `QA20A-02.txt` | QA20A-02 | A **p20-start** scratch schema (from `git show p20-start:…install.sql`), `SHOW TABLES LIKE '%harvest%'` empty before and three rows after, migration `024` applied **twice** (both exit 0), the three column lists and the ledger enum ending `'team_harvest'` | captured |
| `QA20A-03.txt` | QA20A-03 | The four `team_harvest_*` settings with their defaults (`0`, `3`, `10`, `''`), `COUNT(*) = 10` / `SUM(amount) = 5095000.00`, and the ten amounts level by level | captured |
| `QA20A-04.txt` | QA20A-04 | `/admin/settings` with **no** Team Harvest block and a member page with **no** harvest panel | pending (needs the running app) |
| `QA20A-05.txt` | QA20A-05 | Three direct registrations leave `harvest_queue` at **0** and the sponsor's page unchanged | pending (needs the running app) |
| `QA20A-06.txt` | QA20A-06 | The member and admin wallet statement filters list **Team Harvest bonus** and select **no rows** | pending (needs the running app) |
| `QA20A-07.txt` | QA20A-07 | `build_install_sql.php` (64 statements) and `--check` exit 0; a fresh CLI install on `ecowealth_sneat_tool_a_test` reporting **36 / 77 / 10** (plus 2 / 0 / 7 / 2 / 18 / 10 / 0); `wallet_reconcile` exit 0 | captured |
| `QA20A-08.txt` | QA20A-08 | A dry run planning the **harvest** step, the real reset on a scratch schema emptying the queue and awards, the baseline back to `''`, the ten ladder rows and four settings kept, the counters at 1, and a clean verification | captured |

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
