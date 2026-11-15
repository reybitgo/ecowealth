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
| `QA19C-01.txt` | QA19C-01 | The 31-account section C fixture on `ecowealth_sneat_tool_a_test`: the exact rank distribution at 2/2/2/2, the period bounds, the five paid orders and the expected 7 awards / ₱1,152,000.00 |
| `QA19C-02.txt` | QA19C-02 | `--dry-run --json`: 7 awards, ₱1,152,000.00, the per-member drafts and rank counts, and **0** period / award / ledger / audit rows afterwards |
| `QA19C-03.txt` | QA19C-03 | `--evaluate-only`: the 7 `pending` rows with GS/PS/quota/rates/amount, the `evaluated` header (31/8/4/2/1, award_total 1,152,000.00, paid_total 0.00) and **0** ledger rows |
| `QA19C-04.txt` | QA19C-04 | `--pay-only`: the 7 credits in ascending member order with their notes, the `paid` header (paid_count 7, paid_total 1,152,000.00) and the 1,152,000.00 withdrawable total |
| `QA19C-05.txt` | QA19C-05 | A second run on the paid month: exit 0, “already final”, ledger 7 and the wallet total unchanged |
| `QA19C-06.txt` | QA19C-06 | `wallet_reconcile.php` on the closed scratch month: the Royalty section, the 7 paid awards, the ledger total and exit 0 |
| `QA19C-07.txt` | QA19C-07 | A ₱200,000 order cancelled before the close: GS/PS ₱1,225,000.00 and the ₱98,000.00 award, with the cancelled volume absent |
| `QA19C-08.txt` | QA19C-08 | The Director award before and after a post-close cancellation: ₱515,000.00 unchanged, `preview()` still the stored header |
| `QA19C-09.txt` | QA19C-09 | The pause: 1 pending / 0 ledger / 0.00 withdrawable while `royalty_pay_enabled = 0`, then ₱40,000.00 paid once after re-enabling and `--pay-only` |
| `QA19C-10.txt` | QA19C-10 | `royalty_enabled = 0`: the `skipped` header with `royalty stream off`, every count 0 and zero awards/ledger rows |
| `QA19C-11.txt` | QA19C-11 | The two-month catch-up: oldest-first periods, each month's own `as_of`, ₱40,000.00 + ₱80,000.00 paid, and a second run that pays nothing |
| `QA19C-12.txt` | QA19C-12 | `php tests/run.php Royalty` **98 / 98**, the bundle check exit 0 and the development-copy `wallet_reconcile` exit 0 with the Royalty section |
| `QA19D-01.txt` | QA19D-01 | The fixture month closed by the real service (**7 awards, ₱1,152,000.00**) and the rendered ledger: the five `commission-royalty-*` regions, the counters (paid / awaiting / period total / recipients / average / top rank / all time), the seven rows with their stored GS/PS/quota/rates/amount, and the labelled preview |
| `QA19D-02.txt` | QA19D-02 | The period filter on the **skipped** month (`2026-09`: 7 rows, all `group sales below quota`, ₱0.00 paid), the Manager rank filter (2 rows), the `status=paid` filter and the `q=qa19d_sup_a` search (2 Supervisors) |
| `QA19D-03.txt` | QA19D-03 | The ledger CSV: the fifteen Appendix C.5 columns, the seven rows with two printed samples, the ₱1,152,000.00 checked total, and exactly **one** `royalty.export` audit row with its meta |
| `QA19D-04.txt` | QA19D-04 | The reports desk over 2026-09-01 … 2026-10-31: paid / pending / recipients / skipped / months, **royalty paid ÷ paid sales = 23.0%** (₱1,152,000.00 of ₱5,001,000.00), the three rank rows (including the prior month's 7 skipped), both month rows, the three card regions, and the `type=royalty` CSV (5 rows, Period then Rank) |
| `QA19D-05.txt` | QA19D-05 | The member page for the fixture Director: rank, quota progress, this month's PS/GS/quota, downline counts, next rank, all-time paid, last paid and the last awards, plus the `member-royalty` region |
| `QA19D-06.txt` | QA19D-06 | The dashboard tile: all-time **₱1,152,000.00**, the same figure this month, ₱0.00 awaiting, 2 closed months and the ledger link — never the preview |
| `QA19D-07.txt` | QA19D-07 | The package tab keeps its four fragments and the repeat tab its five; the repeat CSV still carries its fifteen escrow columns; neither renders a `commission-royalty-*` region |
| `QA19D-08.txt` | QA19D-08 | The audit log rows behind `/admin/audit`: the `royalty.export` row (tab, period, rows) and the two `settings.update` rows from the commissions card |
| `QA19E-01.txt` | QA19E-01 | The member statement from three closed months: rank **Supervisor** (2 directs, 2 Leaders), next rank **Manager 0 / 2**, GS/PS **₱1,225,000.00** against the **₱500,000.00** quota, **if this month closed now ₱98,000.00**, counters (paid **₱196,000.00**, awaiting **₱98,000.00**, last paid `2026-10`), the three rows and the five `earnings-royalty-*` regions |
| `QA19E-02.txt` | QA19E-02 | The projection equals the close: the open month's **₱98,000.00** against the paid rows of the same volume, with the one-rounding arithmetic printed |
| `QA19E-03.txt` | QA19E-03 | A Leader: **Leader is a rank, not a royalty rank**, the first rank that pays is Supervisor, **0 / 2** toward Supervisor, quota **₱0.00**, and the projection/quota lines absent |
| `QA19E-04.txt` | QA19E-04 | A Member with no directs: **Reach 2 direct members to become a Leader**, the **No Royalty awards yet** empty state, and the dashboard tile with the same copy |
| `QA19E-05.txt` | QA19E-05 | The cancellation month: stored GS **₱1,225,000.00** with the cancelled **₱200,000.00** absent, and a below-quota row's stored reason rendered as **Group sales below the ₱500,000.00 quota** |
| `QA19E-06.txt` | QA19E-06 | `royalty_enabled = 0`: the history stays (3 rows, **₱196,000.00** paid), the statement and the tile say **Royalty is currently off**, and no projection renders |
| `QA19E-07.txt` | QA19E-07 | `royalty_pay_enabled = 0`: the pending `2026-09` row reads **Awaiting payment** and **Payment is paused; the award stays pending.**, with no Paid badge on it and **₱196,000.00** paid overall |
| `QA19E-08.txt` | QA19E-08 | The member CSV: the eleven Appendix C.6 columns, three rows all belonging to the caller (`user_id` ignored), the member status words, and **0** audit rows |
| `QA19E-09.txt` | QA19E-09 | The dashboard **Royalty** tile (rank, group vs quota, projection, paid all-time, last paid, link), the three wrapping pills and the phone rule hiding `.commission-col-gs/-ps/-quota/-rates` below 768 px |

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
- **Section C moves test money on a scratch schema.** Every QA19C check runs with
  `ECO_DB_NAME=ecowealth_sneat_tool_a_test`; the schema is dropped and rebuilt per scenario, so the
  amounts are disposable. The transcripts record the real command output and the real `SELECT` results
  of that scratch database — no number in them is typed by hand. The full suite and the bundle check
  in `QA19C-12` run normally (the test harness re-creates its own `ecowealth_sneat_test`).
- **Section D reads the finished ledger.** 19D adds no route and no write path, so its eight checks
  run against the application itself (the same in-process rendering sections A and B used): a
  transient generator rebuilds the test schema (`ecowealth_sneat_test`), builds the plan §16.2 A tree
  at 2/2/2/2, runs the **real** `RoyaltyService::close()` for a prior month (all seven rows skipped)
  and the fixture month (**7 awards, ₱1,152,000.00**), then renders each surface and records what the
  screen shows. The development copy is never touched and nothing is typed by hand; every file ends
  with its `RESULT: PASS`. The generator lives in the git-ignored `system/tests/tmp/`, so the
  transcripts — not the script — are the committed evidence.
- **Section E reads the member's own statement.** The same technique with a different frame: the
  transient generator rebuilds the test schema, builds the §16.2 A tree at 2/2/2/2 plus one member with
  no tree, orders **₱1,225,000** for `qa19e_sup_ax` in three completed months and the open month, adds a
  **₱200,000** order to the oldest month and cancels it before that close, then runs the **real**
  `close()` / `evaluate()` / `pay()`. The middle month is evaluated with `royalty_pay_enabled = 0` and
  left pending, so QA19E-07 shows the paused state rather than a rebuilt row. QA19E-05 prints one extra
  display-only skipped row (removed again inside the check) to show the below-quota copy. Nine
  transcripts, every one ending in `RESULT: PASS`; the development copy is never touched.
