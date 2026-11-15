# Evidence — Phase 19 (Royalty bonus)

One file per check, named exactly as the check's **Evidence** line. A check that produces only counts,
SQL output or a CLI exit code is captured as a **text transcript** (`.txt`); a check that shows a
browser screen would be a screenshot (`.png`). Phase 19 has no browser surface yet (19A adds no
route), so every file here is a transcript.

Index:

| File | Check | What it shows |
|---|---|---|
| `QA19A-01.txt` | QA19A-01 | The pre-migration dump outside the repository, and the development copy at **31 tables / 54 settings** with no `royalty%` table |
| `QA19A-02.txt` | QA19A-02 | Migration `023` applied twice (both exit 0), ending at **33 tables / 70 settings** on the development copy |
| `QA19A-03.txt` | QA19A-03 | `SHOW TABLES LIKE 'royalty%'` (two rows), the ledger `ref_type` enum ending in `royalty`, and both `SHOW CREATE TABLE` outputs |
| `QA19A-04.txt` | QA19A-04 | The sixteen `royalty_*` settings with the reference defaults |
| `QA19A-05.txt` | QA19A-05 | `build_install_sql.php` (60 statements), `--check` exit 0, and the header/footer counts **33 / 73** |
| `QA19A-06.txt` | QA19A-06 | A fresh CLI install on `ecowealth_sneat_tool_a_test` reporting `33 / 73 / 2 / 0 / 7 / 2 / 18 / 10 / 0` and two empty royalty tables |
| `QA19A-07.txt` | QA19A-07 | The reset preview/dry run naming both tables, the real reset emptying them, the award counter back at 1, the settings kept, and a clean verification |
| `QA19A-08.txt` | QA19A-08 | `wallet_reconcile` exit 0, the full suite **1026 / 1026**, the smoke walk **229 / 229**, and the bundle check exit 0 |

Notes for the reader:

- `mysql` in the transcripts is `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` run as the
  application account; `php` is Laragon's PHP 8.3 CLI. Every command ran from `system/`.
- The development copy (`ecowealth_sneat`) is **migration-built**: it carries no `install_*` metadata
  rows, so it reads **54 settings** before 023 and **70** after. A fresh install reads **57** and
  **73** respectively — the bundle's footer adds the three install metadata rows.
- `QA19A-07` deliberately inserted one header and one award before the reset; the fixture SQL is
  repeated inside that transcript.
