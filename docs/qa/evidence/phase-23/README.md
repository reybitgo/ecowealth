# Phase 23A evidence index

Section A transcripts (`QA23A-01` … `QA23A-06`), captured as terminal transcripts (`.txt`) from
`system/`.

| Check | Evidence | What it proves |
|---|---|---|
| QA23A-01 | `QA23A-01.txt` | Development copy backed up outside the repository; at migration 026 (42 tables, 89 settings) |
| QA23A-02 | `QA23A-02.txt` | Migration `027_package_binary.sql` applied once and twice; idempotent, no new table/setting/column on the second run |
| QA23A-03 | `QA23A-03.txt` | `build_install_sql --check` exits 0; fresh install reports `42 / 92 / 2`; `wallet_reconcile.php` exits 0 |
| QA23A-04 | `QA23A-04.txt` | Every package reports `binary_enabled = 0`, `binary_points = 0`; master switch off |
| QA23A-05 | `QA23A-05.txt` | Legacy activations report `binary_processed_at IS NULL`, `binary_points = 0`, `binary_total = 0.00`; no activation-sourced awards |
| QA23A-06 | `QA23A-06.txt` | `chk_ba_source` / `chk_bv_source` accept order and activation rows and reject mixed/NULL sources |

Keep each transcript named exactly as the *Evidence* line says, so the lead can match it against
`docs/qa/phase-23-package-binary.md`.
