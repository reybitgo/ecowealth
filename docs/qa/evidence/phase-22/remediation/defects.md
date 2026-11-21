# Phase 22 — Defect register (Gate R0)

Source: `dmp/phase-22-release-remediation-gates.md` §1 (audit summary) and Appendices A–E.
Audit date: 2026-11-20 · HEAD `924878c` · branch `feature/product-binary`
Measured baseline: 42 tables · 86 settings · APP_PHASE 22 · 236/236 binary cases (uncommitted tree) ·
full suite red (exit 1) · `wallet_reconcile.php` exit 1 · `build_install_sql --check` exit 0

Severity: blocker = prevents the next gate · major = must be fixed before release · minor = polish/docs.

| ID | Gate | Item (from §1) | Severity | Owning gate | Status |
|----|------|----------------|----------|-------------|--------|
| D-01 | R1 | Working tree dirty: 11 modified files + untracked gates doc; HEAD does not equal the tested state | blocker | R1 | fixed @ fa032b9 |
| D-02 | R1 | `GenealogyController.php` reads undefined `$display_name` (PHP 8.3 warning, blank display name) | blocker | R1 | fixed @ fa032b9 |
| D-03 | R1 | `AdminCommissionController::exportBinary()` calls `exit`, terminating the in-process test runner | blocker | R1 | fixed @ fa032b9 |
| D-04 | R1 | Vocabulary manifest incomplete; ownership guard flags Phase 22's own files | blocker | R1 | fixed @ fa032b9 |
| D-05 | R1 | `GeneralHarvestService::mismatches` entries lack `kind`/`message` shape | blocker | R1 | fixed @ fa032b9 |
| D-06 | R1 | `_binary_rows.php` cast order `e((int)...)` breaks under strict types | blocker | R1 | fixed @ fa032b9 |
| D-07 | R1 | Stray duplicate comment in `member/dashboard.php` | minor | R1 | fixed @ fa032b9 |
| D-08 | R1 | Test expectations stale: `MemberOrderLiveTest`, `ProductTest`, `ReportViewTest`, `TeamHarvestAdminViewTest`, `AdminCommissionLiveTest` | blocker | R1 | fixed @ fa032b9 |
| D-09 | R2 | `RegistrationService::placeBinaryNode()` falls back: opposite side → company root → BFS any free slot; taken slot silently placed elsewhere | blocker | R2 | open |
| D-10 | R2 | `P22G-T17` exercises `validate()` directly, never `RegistrationService::register()` | blocker | R2 | open |
| D-11 | R3 | 49 named P22 test IDs absent from `tests/Cases/` (22A: 11, 22B: 2, 22C: 20, 22D: 6, 22E: 1, 22F: 2, 22H: 1, 22I: 6) | blocker | R3 | open |
| D-12 | R3 | `BinaryReleaseTest` missing T4, T6–T10 (only T1–T3, T5 exist) | blocker | R3 | open |
| D-13 | R3 | Phase log PASS rows mis-mapped: 22C T22/T31/T47, 22D T59, 22G T17 | blocker | R3 | open |
| D-14 | R3 | No traceability meta-test enforcing plan-ID → case coverage | major | R3 | open |
| D-15 | R4 | Full suite red: `DbTargetTest` expects 1044 got 1007 (leftover `ecowealth_sneat_tool_not_granted_test` schema) | blocker | R4 | open |
| D-16 | R4 | Full suite deadlocks in `AdminWalletLiveTest` / `HardeningTest` / `LiveTest` (wallet/ledger lock ordering) | blocker | R4 | open |
| D-17 | R4 | `wallet_reconcile.php` exit 1: General Harvest `[queue_gapless] COUNT(*) = 1, MAX(id) = 17` | blocker | R4 | open |
| D-18 | R4 | Smoke check environment-blocked (requires running app); not yet executed | blocker | R4 | open |
| D-19 | R4 | New PHP warnings in `storage/logs/` (e.g. undefined `referral_code` in BinaryTreeServiceTest) | minor | R4 | open |
| D-20 | R5 | QA pack: 83 `Result` rows all unmarked; 0/83 executed | blocker | R5 | open |
| D-21 | R5 | QA pack structure broken: Section E has no heading, Section I before E/F/H, duplicate `## 6. Cleanup` | blocker | R5 | open |
| D-22 | R5 | QA evidence folder contains only `README.md`; no per-check evidence files | blocker | R5 | open |
| D-23 | R5 | Sign-off sheet not countersigned; project-lead row blank | blocker | R5 | open |
| D-24 | R6 | Settings count discrepancy: measured 86 vs plan/QA/install.sql 89 | major | R6 | open |
| D-25 | R6 | Case count 1,735 is a projection, not R4's measured green count | major | R6 | open |
| D-26 | R6 | Multiple documents claim "released", tags exist, evidence complete — all false | blocker | R6 | open |
| D-27 | R7 | Seven gate tags missing: p22b-configuration, p22c-placement, p22d-money, p22e-admin, p22g-tree, p22h-operations, p22i-release | blocker | R7 | open |
| D-28 | R7 | Two supporting tags missing: p22-start, phase-22 | blocker | R7 | open |
| D-29 | R7 | Branch not merged to release target; HEAD not an ancestor of main (196 commits ahead) | blocker | R7 | open |
| D-30 | R0/docs | `docs/runbook.md` exists (verified) | ok | R0 | verified |

## Notes
- D-01–D-08 are committed in R1 (the 11 pending files).
- D-09/D-10 are the placement contract; fixed in R2.
- D-11–D-14 close the named-test inventory in R3.
- D-15–D-19 close the green-suite/reconciler/smoke gate in R4.
- D-20–D-23 close the QA pack in R5.
- D-24–D-26 close the documentation truth pass in R6.
- D-27–D-29 close tags/merge/certification in R7.
