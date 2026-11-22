# Phase 23 evidence index

Section transcripts (`QA23A-01` … `QA23G-04`), captured as terminal transcripts (`.txt`) from
`system/` and, where a check is browser-based, as browser evidence referenced in the QA pack.

| Check | Evidence | What it proves |
|---|---|---|
| QA23A-01 | `QA23A-01.txt` | Development copy backed up outside the repository; at migration 026 (42 tables, 89 settings) |
| QA23A-02 | `QA23A-02.txt` | Migration `027_package_binary.sql` applied once and twice; idempotent, no new table/setting/column on the second run |
| QA23A-03 | `QA23A-03.txt` | `build_install_sql --check` exits 0; fresh install reports `42 / 92 / 2`; `wallet_reconcile.php` exits 0 |
| QA23A-04 | `QA23A-04.txt` | Every package reports `binary_enabled = 0`, `binary_points = 0`; master switch off |
| QA23A-05 | `QA23A-05.txt` | Legacy activations report `binary_processed_at IS NULL`, `binary_points = 0`, `binary_total = 0.00`; no activation-sourced awards |
| QA23A-06 | `QA23A-06.txt` | `chk_ba_source` / `chk_bv_source` accept order and activation rows and reject mixed/NULL sources |
| QA23B-01 | `QA23B-01.txt` | Package create/edit validates and persists `binary_enabled` / `binary_points` |
| QA23B-02 | `QA23B-02.txt` | Switch on with zero points is refused |
| QA23B-03 | `QA23B-03.txt` | Switch off with zero points is accepted |
| QA23B-04 | `QA23B-04.txt` | Decimal points are refused |
| QA23B-05 | `QA23B-05.txt` | Points over one million and under zero are refused |
| QA23B-06 | `QA23B-06.txt` | Create form defaults: switch off, points suggest ROUND(product_value); no pair/rate/cap/hold inputs |
| QA23B-07 | `QA23B-07.txt` | Audit records before/after when binary changes |
| QA23B-08 | `QA23B-08.txt` | List badge shows on/off/zero correctly |
| QA23B-09 | `QA23B-09.txt` | `?binary=on|off` filter narrows the admin list |
| QA23B-10 | `QA23B-10.txt` | `binaryStatusMap` returns one row per package |
| QA23B-11 | `QA23B-11.txt` | Settings card: master switch and qualification toggle under product binary |
| QA23B-12 | `QA23B-12.txt` | Form HTML has switch and points, no pair/rate/cap/hold inputs |
| QA23B-13 | `QA23B-13.txt` | Readiness panel shows the four counts and links to the filtered list |
| QA23B-14 | `QA23B-14.txt` | Readiness preset sentence recomputes when the shared pair values change |
| QA23B-15 | `QA23B-15.txt` | Approving a binary-flagged package activation writes no binary row while the stream is off |
| QA23B-18 | `QA23B-18.txt` | (reserved) no pair/rate/cap/hold field exists |
| QA23C-01 | `QA23C-01.txt` | BinaryAward::createForActivation writes an activation-sourced pair award |
| QA23C-02 | `QA23C-02.txt` | BinaryVolume::createForActivation writes team rows with source activation |
| QA23C-03 | `QA23C-03.txt` | BinaryAward::normaliseFilters accepts source and whereFor filters by it |
| QA23C-04 | `QA23C-04.txt` | BinaryVolume::normaliseFilters accepts source |
| QA23C-05 | `QA23C-05.txt` | BinaryAward::sumBySource splits order vs activation totals |
| QA23C-06 | `QA23C-06.txt` | BinaryAward::create rejects activation award without activation_id |
| QA23C-07 | `QA23C-07.txt` | BinaryVolume::create rejects activation row without activation_id |
| QA23C-08 | `QA23C-08.txt` | (reserved) activation-source model/API |
| QA23D-01 | `QA23D-01.txt` | The product rows/filters/export are identical to p22i-release plus the source column |
| QA23D-02 | `QA23D-02.txt` | `?source=activation` / `order` returns correct rows; unknown value falls back |
| QA23D-03 | `QA23D-03.txt` | Stats: credited/pending split by source; package pairs; package points |
| QA23D-04 | `QA23D-04.txt` | CSV: a Source column; package rows exported with the activation number |
| QA23D-05 | `QA23D-05.txt` | The activation card: points, pairs, money, status, wallet links |
| QA23D-06 | `QA23D-06.txt` | The member panel split: product vs package points/pairs/earnings; qualification state |
| QA23D-07 | `QA23D-07.txt` | The three report cards; totals match SQL; range export includes both sources |
| QA23E-01 | `QA23E-01.txt` | `/earnings?ledger=binary` reads Binary bonus; package card, source split, Source column |
| QA23E-02 | `QA23E-02.txt` | The package card: package name, points, pairs, money, last activation |
| QA23E-03 | `QA23E-03.txt` | Copy guard: no upline/sponsor/"Team chain"/"Level" on the tab |
| QA23E-04 | `QA23E-04.txt` | A member with no binary package: the card is absent; the page still works |
| QA23E-05 | `QA23E-05.txt` | `?source=activation` shows only package rows; stats stay whole |
| QA23E-06 | `QA23E-06.txt` | JS off and mobile: cards stack, pagination works |
| QA23F-01 | `QA23F-01.txt` | The audit is clean on the demo database |
| QA23F-02 | `QA23F-02.txt` | The audit names an orphaned activation award |
| QA23F-03 | `QA23F-03.txt` | The audit names a stamped activation with no volume |
| QA23F-04 | `QA23F-04.txt` | A pending package audit is a failure |
| QA23F-05 | `QA23F-05.txt` | The health panel shows the three package checks |
| QA23G-01 | `QA23G-01.txt` | The QA pack sections A–G are complete; every ID unique; evidence indexed |
| QA23G-02 | `QA23G-02.txt` | The Appendix B acceptance run passes 10 / 10 on the scratch database |
| QA23G-03 | `QA23G-03.txt` | The docs copy-paste: every command, key and route exists and runs |
| QA23G-04 | `QA23G-04.txt` | The phase log, the sign-off sheet and the badges record the release |

Keep each transcript named exactly as the *Evidence* line says, so the lead can match it against
`docs/qa/phase-23-package-binary.md`.
