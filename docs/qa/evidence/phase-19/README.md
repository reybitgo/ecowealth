# Evidence — Phase 19 (Royalty bonus)

One file per check, named exactly as the check's **Evidence** line. A check that produces only counts,
SQL output or a CLI exit code is captured as a **text transcript** (`.txt`); a check that shows a
browser screen would be a screenshot (`.png`). Phase 19 has no new route yet, so every file here is a
transcript; sections A and B assert the rendered markup through the application itself.

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
| `QA19B-01.txt` | QA19B-01 | The Royalty sub-block on `/admin/settings#settings-commissions`: two switches, thirteen inputs, the five-row saved ladder with the reference defaults, no baseline input, and the sixteen stored settings |
| `QA19B-02.txt` | QA19B-02 | Supervisor downline Leaders 10 → 3: the save, the re-rendered card and ladder, exactly one `settings.update` with `from`/`to`, no period/award/ledger row, and the restore to 10 |
| `QA19B-03.txt` | QA19B-03 | A Manager quota below the Supervisor quota: the field error, the stored value unchanged, nothing written |
| `QA19B-04.txt` | QA19B-04 | A 110% group + personal ladder: the “cannot exceed 100%” field errors and the stored 3.000% surviving |
| `QA19B-05.txt` | QA19B-05 | The reference preset restored (10/10/10/10, 500k/1M/5M, 3/5 · 5/10 · 10/15), own purchase 8/15/25%, earning switch off |
| `QA19B-06.txt` | QA19B-06 | `/admin/members`: the Rank column between Status and Wallet, one badge per row (— when unranked) and the Rank distribution strip |
| `QA19B-07.txt` | QA19B-07 | The demo accounts' badges follow the 10-direct rule on this copy (juan is a Leader from accumulated QA members; maria/peter/sarah/david are Members), and at `leader_directs=1` juan/maria/peter/sarah become Leader; threshold restored |
| `QA19B-08.txt` | QA19B-08 | `/admin/commissions`, the repeat tab, `/admin/wallets`, the member statement, `/earnings` and `/dashboard` show no Royalty row; **0 / 0 / 0** for periods, awards and the royalty ledger; wallet totals unchanged |
| `QA19B-09.txt` | QA19B-09 | The Royalty stream **46 / 46**, the full suite **1,054 / 1,054**, the smoke walk **232 / 232** (including the three P19B checks), `wallet_reconcile` exit 0 and the bundle check exit 0 |

Notes for the reader:

- `mysql` in the transcripts is `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` run as the
  application account; `php` is Laragon's PHP 8.3 CLI. Every command ran from `system/`.
- The development copy (`ecowealth_sneat`) is **migration-built**: it carries no `install_*` metadata
  rows, so it reads **54 settings** before 023 and **70** after. A fresh install reads **57** and
  **73** respectively — the bundle's footer adds the three install metadata rows.
- `QA19A-07` deliberately inserted one header and one award before the reset; the fixture SQL is
  repeated inside that transcript.
- The development copy also carries members created by earlier manual QA (24 member accounts at the
  19B gate, not the five-seed shape). `QA19B-07` derives each demo account's expected badge from its
  real direct count for that reason; the pristine tree is covered by `RoyaltyAdminMemberViewTest`.
- Section B moves no money: every check either reads a page or sends a refused save, and the wallet
  aggregate is compared before and after the pass.
