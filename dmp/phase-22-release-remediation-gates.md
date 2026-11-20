# Phase 22 — Release remediation and Phase 23 clearance gates

**Subject:** Product binary bonus (Phase 22A → 22I)
**Plan of record:** `dmp/plan-ecowealth-product-binary-sliced.md` (§2.4 universal gate, §2.7 execution slices, §13 Phase 22I)
**Repository:** `C:\laragon\www\ecowealth` · branch `feature/product-binary` · HEAD `924878c`
**Audit date:** 2026-11-20
**Verdict at audit:** Phase 22 is **substantially coded but not closed**. Phase 23 must not start.

---

## Contents

| § | Section | Gate |
|---|---|---|
| 0 | How this document is used | — |
| 1 | Audit summary — what is actually true today | — |
| 2 | Gate dependency graph | — |
| 3 | Freeze, back up, and measure the truth | R0 |
| 4 | Make the tested tree the committed tree | R1 |
| 5 | Restore the placement contract | R2 |
| 6 | Complete the named-test inventory and release tests | R3 |
| 7 | Green full suite, clean reconciler, executable smoke | R4 |
| 8 | Repair the QA pack and produce real evidence | R5 |
| 9 | Documentation truth pass and count reconciliation | R6 |
| 10 | Tags, merge, and release certification | R7 |
| 11 | Phase 23 entry certificate | — |
| A–J | Appendices: dirty files, missing IDs, QA defects, tags, claims, commands, risks, commits, evidence, release record | — |

---

## 0. How this document is used

This is a **sequential gate plan**, not a checklist of independent chores. Each gate `R0 … R7` is a phase with:

- **Entry criteria** — what must already be true before work starts.
- **Work items** — the exact fixes, files and decisions.
- **Verification** — commands and expected output.
- **Exit criteria** — the boxes that must be ticked before the next gate may begin.
- **Evidence** — what is committed so the next reviewer can reproduce the gate.

**Rules of the pipeline**

1. **One gate at a time.** A later gate's work does not begin while an earlier gate has an open box. This mirrors the plan's own slice discipline (§2.7).
2. **No gate is closed from a dirty tree.** Every gate's verification runs on a committed state, and the commit hash is recorded in that gate's record.
3. **No test is weakened to pass a gate.** A failing assertion is fixed at its cause, or the case is corrected because its premise was wrong — never deleted, skipped or loosened to hide a defect. Any deliberate case change is recorded as a deviation with its reason.
4. **No money-carrying verification runs on the development or production copy.** Use the scratch schemas `ecowealth_sneat_tool_a_test` and `ecowealth_sneat_tool_b_test`, as the QA pack already requires.
5. **A phase log entry is written only from measured output.** Copying a previous count, or writing "exit 0" without the command's actual exit status, is itself a gate failure.
6. **Phase 23 starts only when §11's entry certificate is signed.** Nothing in Phase 22 is assumed by Phase 23 until then.

---

## 1. Audit summary — what is actually true today

| Item | Audited state | Evidence |
|---|---|---|
| Branch / HEAD | `feature/product-binary` at `924878c` ("Phase 22I-S2: cold read, acceptance evidence and signed release") | `git log -1` |
| Working tree | **Dirty** — 11 modified files, 57 insertions / 19 deletions | `git diff --stat` |
| Divergence from `main` | **196 commits ahead**; HEAD is contained only by `feature/product-binary` | `git rev-list --count main..HEAD`, `git branch --contains HEAD` |
| Phase tags present | `p22a-foundation`, `p22f-member` **only** | `git tag --list 'p22*'` |
| Phase tags absent | `p22-start`, `p22b-configuration`, `p22c-placement`, `p22d-money`, `p22e-admin`, `p22g-tree`, `p22h-operations`, `p22i-release`, `phase-22` | `git rev-parse --verify` each |
| Binary-specific suite | 236 / 236 pass, but **only with the uncommitted changes** | `php tests/run.php Binary` |
| Full suite | **Red** — reported `1,732 / 1,735`, exit 1; observed `FAIL` in `DbTargetTest` and deadlock failures in wallet live tests during this audit | `.suite-phase22.log` (partial run) |
| `build_install_sql.php --check` | Exit 0 | `php bin/build_install_sql.php --check` |
| `wallet_reconcile.php` | **Exit 1** — `[queue_gapless] COUNT(*) = 1, MAX(id) = 17` in General Harvest | `php bin/wallet_reconcile.php` |
| Schema / settings | 42 tables; **86 settings measured**, while plan/QA/bundle header state **89** | harness query; `install.sql` header |
| QA pack | 83 check headings, **0 results marked**; Section E heading missing; duplicate `## 6. Cleanup`; Section I placed before E/F/H | `docs/qa/phase-22-product-binary.md` |
| QA evidence | `docs/qa/evidence/phase-22/` contains **only `README.md`**; no evidence file exists in git history | folder listing |
| Sign-off | Phase 22 countersigned? **No** — project-lead row blank | `docs/qa/sign-off-sheet.md` |
| Named P22 test IDs | Plan defines **282**; test files reference **233**; **49 absent** | coverage scan (Appendix B) |
| `BinaryReleaseTest` | Only **T1, T2, T3, T5** exist; T4 and T6–T10 absent | `system/tests/Cases/BinaryReleaseTest.php` |
| Registration placement | **Silent fallback** to other side, then root, then any free slot | `RegistrationService::placeBinaryNode()` |
| `P22G-T17` | Calls `BinaryPlacementService::validate()` directly; never exercises the registration path | `BinaryTreeServiceTest.php:854` |
| `docs/runbook.md` | Exists | folder listing |

### The one-sentence statement for the record

> The release commit is not the state that passes the tests; the full gate is red; the QA pack and its evidence are placeholders; the release tags and merge do not exist; and registration violates the placement contract the phase itself specifies.

---

## 2. Gate dependency graph

```
R0  Freeze, back up, and measure the baseline
 └─▶ R1  Commit the release-state fixes (dirty tree → clean HEAD)
      └─▶ R2  Restore the placement contract (behaviour + tests)
           └─▶ R3  Complete the named-test inventory and release tests
                └─▶ R4  Green full suite + wallet_reconcile + smoke
                     └─▶ R5  Repair the QA pack and produce the evidence
                          └─▶ R6  Documentation truth pass and count reconciliation
                               └─▶ R7  Tags, merge, and release certification
                                    └─▶ Phase 23 may start
```

R2 does not strictly depend on R3, but R3's inventory must include the cases R2 adds. R4 must be re-run after R2 and R3; R5 consumes R4's logs as evidence. R6 must not be written until R5's numbers are real. R7 is last and mechanical.

---

## 3. Gate R0 — Freeze, back up, and measure the truth

**Why.** Every later gate compares against a baseline. Today the release record and the working tree disagree, and the "passing" binary suite depends on uncommitted code. Without a frozen, measured starting point, no claim made later can be trusted.

**Entry.** None. This is the first action after the audit.

### Work items

1. **Preserve the current state before touching anything.** A tag points at a commit, so it cannot capture the uncommitted tree. Bookmark the commit under audit, save the dirty state as a patch, and copy the source:
   ```powershell
   cd C:\laragon\www\ecowealth
   git tag -a p22-rc-audit-924878c -m "Commit under Phase 22 audit (HEAD 924878c; dirty tree preserved as R0-dirty.patch)"
   git diff > docs\qa\evidence\phase-22\remediation\R0-dirty.patch
   ```
   Then copy the whole `system\` folder — including `.git` — to `C:\laragon\www\ecowealth-backups\phase-22\source-<stamp>\`. The standing project rule is a dump outside the repository before any migration; treat the source tree the same way. Do **not** stash: R1 commits these exact changes, and a stash pop against a moved tree can silently drop one.

2. **Back up the development database** (outside the repository):
   ```powershell
   mysqldump -u ecowealth -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-22\ecowealth_sneat-pre-remediation-<stamp>.sql
   ```

3. **Capture the audit evidence** into `docs/qa/evidence/phase-22/remediation/`:
   - `R0-git-status.txt` — `git status --porcelain`, `git log -1 --format=fuller`
   - `R0-tags.txt` — `git tag --list 'p2*'`, `git tag --list 'phase-*'`, `git branch --contains HEAD`
   - `R0-build-check.txt` — `php bin/build_install_sql.php --check; echo "exit=$LASTEXITCODE"`
   - `R0-wallet-reconcile.txt` — full output **and** exit code
   - `R0-suite-binary.txt` — `php tests/run.php Binary` output and exit code
   - `R0-suite-full.txt` — full suite output and exit code (run it to completion with output redirected)
   - `R0-counts.txt` — tables, settings, migrations, `APP_PHASE`
   - `R0-qa-grid.txt` — count of unmarked `Result` rows and evidence files present
   Use `2>&1 | Tee-Object -FilePath <file>` so the exit status is captured separately, never swallowed by a pipe.

4. **Open the defect register** (`docs/qa/evidence/phase-22/remediation/defects.md`) with one row per item in §1: ID, gate, severity (blocker / major / minor), owner phase, status. Seed it from Appendices A–E.

### Verification

```powershell
git status --porcelain          # expected: exactly the 11 modified files; the only untracked paths are this document and docs/qa/evidence/phase-22/remediation/
php bin\wallet_reconcile.php; echo "exit=$LASTEXITCODE"   # expected today: exit=1
```

Git's repository root is `C:\laragon\www\ecowealth`, so from `system\` every status path is prefixed `system/`. That prefix is normal; it is not an extra change.

### Exit criteria

- [ ] Backup of source and database exists outside the repository and is non-empty.
- [ ] `R0-*.txt` evidence files exist under `docs/qa/evidence/phase-22/remediation/`.
- [ ] The defect register lists every item from §1 with a severity and an owning gate.
- [ ] The audit tag `p22-rc-audit-924878c` exists.
- [ ] No source file has been changed in this gate.

---

## 4. Gate R1 — Make the tested tree the committed tree

**Why.** `HEAD` is not the state that produced the 236/236 binary result. Three of the eleven uncommitted files fix hard failures at `HEAD`: an undefined variable in the genealogy controller, an incomplete ownership manifest that would flag legitimate files, and an `export()` call that would `exit` inside the test runner. Committing them is not cosmetic bookkeeping — it is the difference between a release that can be tested and one that cannot.

**Entry.** R0 closed.

### Work items — the 11 pending files

| # | File | Change required | Why it must be committed |
|---|---|---|---|
| 1 | `system/app/Controllers/GenealogyController.php` | `'display_name' => $display_name` → `'display_name' => $displayName` | `HEAD` reads an undefined variable; PHP 8.3 warning and a blank/missing display name on the binary genealogy facts |
| 2 | `system/tests/Cases/BinaryFoundationTest.php` | Extend `VOCABULARY_FILES` with the 14 files that legitimately use binary vocabulary (`Models/Report.php`, `Services/BinaryTreeService.php`, `Services/OrderService.php`, `Controllers/AdminOrderController.php`, `Controllers/CheckoutController.php`, admin/member/shop binary views, checkout views) | At `HEAD` the ownership guard flags the phase's own files, so the guard cannot pass on the release commit |
| 3 | `system/app/Controllers/AdminCommissionController.php` | `exportBinary(bool $terminate = true)`; route passes `no_exit`; return instead of `exit` when false | At `HEAD` `exportBinary()` calls `exit`, terminating the in-process test runner |
| 4 | `system/tests/Cases/AdminCommissionLiveTest.php` | `export(['no_exit' => true])`; array assertions use `assert_true(in_array(...))`; remove unused `$clean` capture | Pairs with #3; otherwise `P22E-T7` cannot execute |
| 5 | `system/app/Services/GeneralHarvestService.php` | `mismatches` entries become `['kind' => key, 'message' => detail]` | The shared reconciler/health contract expects `kind`/`message`; without it the General Harvest section misreports |
| 6 | `system/app/views/admin/commissions/_binary_rows.php` | `e((int) $award['points'])` → `e((string) (int) $award['points'])` | `e()` is typed for strings; the cast order matters under strict types |
| 7 | `system/app/views/member/dashboard.php` | Remove the stray `<!-- Product binary (Phase 22F) -->` duplicate comment above the include | View hygiene; the comment sits above an already-labelled include |
| 8 | `system/tests/Cases/MemberOrderLiveTest.php` | Add `member-order-binary` to the expected fragment list | The order page now renders that fragment; the test otherwise asserts a stale surface |
| 9 | `system/tests/Cases/ProductTest.php` | Expected reference map includes `'binary' => 0` | `Product::references()` now counts binary references; the test asserts a stale shape |
| 10 | `system/tests/Cases/ReportViewTest.php` | Add `report-binary`, `report-binary-pairs`, `report-binary-products`, `report-binary-readiness` | The reports page renders the four new fragments |
| 11 | `system/tests/Cases/TeamHarvestAdminViewTest.php` | Commission tab count 5 → 6 and comment updated to Phase 22 | The Product binary tab is the sixth pill |

### Work items — commit discipline

1. Review the diff **file by file**; confirm each change above is present and nothing unrelated is bundled.
2. Commit in **three logical commits** (or one release commit if the repository prefers it), each with a message naming the gate:
   - `fix(22): geneology display name + commission export no_exit path`
   - `test(22): reconcile phase-22 vocabulary manifest and fragment/tab expectations`
   - `fix(22): general harvest mismatch shape + binary row escaping`
3. **Do not tag anything yet.** Tags belong to R7, after the gates pass.
4. Re-run the binary suite on the now-clean tree:
   ```powershell
   php tests\run.php Binary 2>&1 | Tee-Object -FilePath ..\docs\qa\evidence\phase-22\remediation\R1-suite-binary.txt
   ```
   Expected: same 236 / 236 (or more, once R2/R3 add cases), exit 0.

### Verification

```powershell
git status --porcelain                 # expected: empty
git diff HEAD --stat                   # expected: empty
git log --oneline -3                   # the three commits above
php tests\run.php Binary; echo "exit=$LASTEXITCODE"
```

### Exit criteria

- [ ] Working tree clean; no uncommitted or untracked source files.
- [ ] The eleven fixes above are in the commit history with their reasons.
- [ ] Binary suite passes on the clean commit; output and exit code archived.
- [ ] No tag was created in this gate.
- [ ] Defect register updated: the three `HEAD`-broken files are marked fixed with the commit hash.

---

## 5. Gate R2 — Restore the placement contract

**Why.** This is the phase's only **functional** deviation, and it contradicts four separate normative statements:

- `P22C-T22` — taken slot → field error, **no user created**.
- `QA22C-01` step 3 — same slot → **refusal**.
- `P22G-T17` — slot race → **field error**, no member created.
- `D-03` — placement requires a **free slot**.

`RegistrationService::placeBinaryNode()` currently tries the chosen side, then the other side, then the company root, then breadth-first searches the whole tree for any free slot. A member who chose `peter/left` can therefore be placed at `upline=5, side=right`, or anywhere else, with no error. That is a silent data-integrity defect in a money-adjacent tree.

**Entry.** R1 closed (clean HEAD, binary suite green).

### Work items — code

1. **Delete the fallback entirely** in `system/app/Services/RegistrationService.php::placeBinaryNode()`:
   - Remove the `$sides` loop that appends the opposite side.
   - Remove the root-fallback block.
   - Remove the breadth-first "any free slot" block.
   - Keep: upline resolution (typed upline → sponsor node → company root when the sponsor is staff), side requirement, `validate()`, `placeNew()`, and the surrounding transaction.
2. **Validate exactly once, place exactly once:**
   ```php
   $errors = $this->placement->validate($uplineId, $binaryPosition, [
       'registrar' => $registrar,
       'sponsor'   => $sponsor,
   ]);

   if ($errors !== []) {
       throw RegistrationException::validation($errors);
   }

   try {
       $this->placement->placeNew($userId, $uplineId, $binaryPosition, [...]);
   } catch (BinaryException $e) {
       throw RegistrationException::validation([
           'binary_position' => $e->getMessage(),
       ]);
   }
   ```
3. **Map failure reasons to stable field errors.** `slot_taken` must land on `binary_position` (the QA/registration form reads that key); `cycle`, `self_parent`, `outside_subtree`, `ineligible_upline`, `depth_limit` must land on `binary_upline` or `binary_position` consistently. Do not emit a generic exception that the form cannot render.
4. **Preserve atomicity.** The user insert, node insert and all later hooks remain in the existing transaction. A refused placement must roll back the user insert — no orphan user, no node without a user. Confirm the `PDOException` 23000 path still converts a raced slot to a field error, never a 500.
5. **Do not change the admin move path.** It already refuses taken slots correctly; this gate is about registration placement only.

### Work items — tests

Add the missing registration/placement block (R3 tracks the full inventory; these are the contract-critical ones):

| ID | Case to add | Assertion |
|---|---|---|
| `P22C-T19` | Register with `binary_upline=boss`, `binary_position=left` | User + node created; node upline/side are the **typed** values; `users.sponsor_id` unchanged |
| `P22C-T20` | Registration form renders `binary_upline` + `binary_position` when placement is enabled | Both fields present, position required |
| `P22C-T21` | Live validation endpoint for upline/side/slot | Slot-taken response is a field error, not success |
| `P22C-T22` | **Register into a taken slot** | Field error; **no user row created**; slot count unchanged |
| `P22C-T23` | Admin move: authorization | Member session refused, admin allowed |
| `P22C-T24` | Admin move: CSRF | Missing/invalid token refused, nothing written |
| `P22C-T25` | Admin move: non-empty subtree | Refused, node unchanged |
| `P22C-T26` | Admin move: cyclic destination | Refused, node unchanged |
| `P22C-T27` | Route inventory | Exactly one placement route (`POST /admin/members/{id}/placement`) |
| `P22C-T28` | Backfill `--check` | `0 written`, exit 0 |
| `P22C-T29` | Backfill `--apply` twice | Second run `0 written` |
| `P22C-T30` | Demo tree shape | Root + five demo members on fixed slots |
| `P22C-T31` | Register form after a field error | Typed placement survives in `old_set()` |

**Rewrite `P22G-T17`** (`system/tests/Cases/BinaryTreeServiceTest.php:854`) so it exercises the **registration path**, not `validate()` in isolation:

1. Register a member into `peter/left`; assert success.
2. Capture `users` count and `binary_nodes` count.
3. Attempt a second registration into `peter/left` through `RegistrationService::register()`.
4. Assert: a field error is raised; `users` count unchanged; `binary_nodes` count unchanged; the second username does not exist; the first member still owns `peter/left`.
5. Optionally assert `peter`'s other slot is **still free** — the fallback must not have consumed it.

> This is the single most important test change in the whole remediation: it is the only case that can fail if the fallback returns.

### Verification

```powershell
php tests\run.php BinaryPlacement
php tests\run.php BinaryTree
php tests\run.php Registration     # if a registration case file matches
php tests\run.php Binary           # full binary set
```

Manual replay of the reported defect, on a scratch schema:

1. Register into `peter/left` → success, node `peter/left`.
2. Register a second member explicitly into `peter/left`.
3. Expected: field error naming the position/upline; **no** new user row; **no** new node row; no node at `peter/right`, at the root, or anywhere else.
4. `SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM binary_nodes;` before and after — both unchanged.

### Exit criteria

- [ ] No fallback code remains in `placeBinaryNode()` (grep for `array_shift`, the `$sides` loop, `ensureRoot` fallback inside placement).
- [ ] A taken slot returns a field error and creates neither a user nor a node.
- [ ] The second registration is not silently placed at another side, under the root, or in a BFS slot.
- [ ] `P22C-T19`–`T31` exist and pass.
- [ ] `P22G-T17` drives `RegistrationService::register()` and asserts the full refusal (error + no user + no node).
- [ ] Defect register: the placement deviation is closed with the commit hash and test names.

---

## 6. Gate R3 — Complete the named-test inventory and release tests

**Why.** The plan defines 282 `P22X-Tn` IDs. The phase log marks almost all of them PASS, but **49 IDs have no reference anywhere in `tests/Cases/`**. The largest holes are exactly the registration/placement block and the release-test file itself. A phase whose gate is "every named case exists and passes" cannot close while a sixth of its IDs do not exist.

**Entry.** R2 closed (the placement cases added there are counted here).

### Measured coverage

| Phase | Planned | Missing | Missing IDs |
|---|---:|---:|---|
| 22A | 20 | 11 | `T1, T2, T4, T5, T6, T8, T9, T10, T18, T19, T20` |
| 22B | 26 | 2 | `T23, T25` |
| 22C | 53 | 20 | `T19–T31` (the registration block), `T47–T53` |
| 22D | 72 | 6 | `T3, T4, T5, T13, T14, T59` |
| 22E | 20 | 1 | `T20` |
| 22F | 28 | 2 | `T22, T24` |
| 22G | 35 | 0 | — |
| 22H | 18 | 1 | `T17` |
| 22I | 10 | 6 | `T4, T6, T7, T8, T9, T10` |
| **Total** | **282** | **49** | (T19–T31 are the 22C registration block added in R2) |

Per-phase planned counts are the distinct IDs the plan names for that phase; 22A=20, 22B=26, 22C=53, 22D=72, 22E=20, 22F=28, 22G=35, 22H=18, 22I=10.

### Work items

1. **Decide each missing ID's fate**, one of:
   - **Add the case** — the behaviour exists and needs its named case (preferred).
   - **Reclassify** — the ID belongs to another phase's surface; move it in the plan and the phase log with a note.
   - **Retire with a written reason** — only if the plan's expectation is superseded by a later decision (D-number). Record in the deviation log; never silently drop.
2. **Prioritise the contract-bearing holes:**
   - `P22D-T3`, `T4`, `T5` — pair/points boundaries.
   - `P22D-T13`, `T14` — eligibility/cap behaviour.
   - `P22D-T59` — pending award cancelled by order cancellation (money semantics).
   - `P22I-T4` — copy guard (member tab names no upline/sponsor).
   - `P22I-T6`–`T10` — QA pack integrity, docs cold read, install/migration/reset gate, acceptance run, full suite + smoke.
3. **Add a traceability meta-test** (new or inside `BinaryReleaseTest`) that:
   - extracts every `P22[A-I]-T\d+` from the plan,
   - scans `tests/Cases/` for executed case names,
   - fails listing any planned ID with no matching case.
   This makes the 49-hole class of defect impossible to reintroduce. If a plan ID is intentionally retired, keep an allowlist in the test with the decision number.
4. **Correct the phase log's test-result tables.** Several rows are mislabelled, not merely missing:
   - 22C `P22C-T22` is recorded as "Admin move: authorization", but the plan defines it as **taken slot → field error**.
   - 22C `P22C-T31` is recorded as "Genealogy controller binary facts render", but the plan defines it as **typed placement survives in `old_set()`**.
   - 22C `P22C-T47` is recorded as "Invalid placement rolls back registration"; the plan defines T47 as **`users` has no binary/pairs/count columns**. The rollback expectation belongs to 22C's own case set.
   - 22D `P22D-T59` is recorded PASS although no such case exists.
   - 22G `P22G-T17` is recorded PASS although the case never enters the registration path (R2 fixes this).
   Re-map each row to the plan's actual text; a PASS row for a non-existent or differently-purposed case is a false record.
5. **Complete `BinaryReleaseTest`**: T4 (copy guard), T6 (QA pack/evidence integrity), T7 (docs commands/keys/routes), T8 (fresh-scratch install/migration/reset), T9 (12-step acceptance), T10 (full suite + smoke counts). T10 may be a documented environment gate rather than an automated assertion, but then its row must say *blocked* with the reason — never PASS.

### Verification

```powershell
php -r '...coverage scan from Appendix B...'      # expected missing = 0 (or allowlisted with reasons)
php tests\run.php BinaryRelease
php tests\run.php Binary
```

### Exit criteria

- [ ] Coverage scan reports **0 unaccounted IDs**; every remaining absent ID is allowlisted with a written reason.
- [ ] `BinaryReleaseTest` contains T1–T10, each with a real assertion or an explicit blocked record.
- [ ] Phase log test tables match the plan's ID definitions (T22, T31, T47, T59, T17 corrected).
- [ ] Traceability meta-test fails when an ID is removed — prove it once, then restore.
- [ ] Defect register: test-inventory gap closed.

---

## 7. Gate R4 — Green full suite, clean reconciler, executable smoke

**Why.** The plan's universal gate is unambiguous: full suite green with exit 0, `wallet_reconcile.php` exit 0, smoke green, no warnings, and "never leave the suite red." Today the full suite exits 1 and the reconciler exits 1. The phase log's assertions to the contrary are false records.

**Entry.** R3 closed (all named cases exist, so a green run now means something).

### Work item 4.1 — `DbTargetTest` (deterministic failure)

**Observed:** `create maps an existing schema and a refused name to hints` — `expected 1044, got 1007`.

**Diagnosis.** MySQL error **1007 = "database exists"**. The case expects **1044 = "access denied for CREATE DATABASE"** for the name `ecowealth_sneat_tool_not_granted_test`. That schema now exists on this machine (left behind by an earlier attempt), and the configured account has enough privilege to create databases, so the "no grant" premise no longer holds. The assertion is not wrong about the contract; its setup is not isolated.

**Fix (choose one, in this order):**

1. **Clean the leftover and make the case self-contained.** At the start of the case, ensure the target schema does not exist (`DROP DATABASE IF EXISTS ecowealth_sneat_tool_not_granted_test` through a connection that may do so), then assert the refusal. Add a `finally` that removes anything the case created. If the account can create the schema, the case will now fail for the right reason — the premise is environment-dependent.
2. **Make the premise real.** Create a dedicated restricted MySQL user for this case (no `CREATE` on the target pattern), connect as that user, assert 1044 and the hint, then drop the user. This is the robust form and keeps the assertion exact.
3. **Declare it environment-inapplicable — only with an explicit guard.** Inspect `SHOW GRANTS`; if the account has global `CREATE`, skip with a recorded reason and cover the 1044 → hint mapping with a unit test that injects driver code 1044. A skipped case is reported as skipped, never as passed.

Do **not** change `1044` to `1007` and do not delete the case.

**Verify:** `php tests\run.php DbTarget` → 12/12.

### Work item 4.2 — Full-suite concurrency failures

**Observed:** deadlocks during the audit run (`SQLSTATE[40001] 1213 Deadlock found` at `WalletService.php:297`) in `AdminWalletLiveTest` ("every wallet movement bumps the monitor…", "the monitor feed renders the top-ups tab", "the wallet detail feed renders every card…", `P18-T44`), plus the previously reported flaky `HardeningTest` transfer throttle and `LiveTest` live publish.

**Fix at the cause, not in the test:**

1. **Lock ordering.** Audit `WalletService` and the live-surface writers for two transactions acquiring the same rows in different orders. Establish one ascending-ID lock order for wallet/ledger rows and document it in the service. This is a production correctness fix, not only a test fix.
2. **Bounded deadlock retry.** Add a single, explicit retry for `1213`/`40001` around the affected transaction boundary (2 attempts, no sleep loops, no retry of a partially committed unit). The plan already anticipates deadlocks between orders sharing uplines (§16.6); the wallet path needs the same treatment.
3. **Test isolation.** Confirm each live/fixture case starts from a reset harness state (truncate + `AUTO_INCREMENT` reset, not `DELETE`), and that no case leaves a shared row another case assumes. Run the suite **sequentially**; do not add sleeps or retry the whole suite to hide the race.
4. **Throttle/publish flake.** `HardeningTest`'s transfer throttle depends on windowed counters; reset the window in setup. `LiveTest`'s publish depends on a revision/ETag; create the surface's fixture deterministically and assert the revision delta, not a wall-clock ordering.

**Verify:** three consecutive full runs, all exit 0; record all three logs. If a run still fails, the fix is incomplete.

### Work item 4.3 — `wallet_reconcile.php`

**Observed:** exit 1, General Harvest `[queue_gapless] COUNT(*) = 1, MAX(id) = 17`.

**Diagnosis.** `GeneralHarvestBoard::queueN()` defines gapless as `COUNT(*) = MAX(id)`. The development copy's board holds one position whose id is 17, because earlier rows (ids 1–16) were inserted and deleted, leaving `AUTO_INCREMENT` high. The Product binary section is clean; the failure is General Harvest (Phase 21) data plus the invariant's sensitivity to hard deletes.

**Fix:**

1. **Repair the data** on the development copy — reseed/reset the board so ids are contiguous (`1..N`) with `AUTO_INCREMENT = N+1`, or run the business reset that rebuilds it. Record which was used and why. Do not hand-edit money-adjacent rows without a backup (R0).
2. **Prevent recurrence in fixtures.** Any test or seeder that clears the board must `TRUNCATE` (or `DELETE` + `ALTER TABLE … AUTO_INCREMENT = 1`) so the next run starts at 1. Search for `DELETE FROM general_harvest_board` and fix each site.
3. **Fix the report text.** The message prints `(expected —, actual —)` because the two placeholders are empty. Print `expected = MAX(id)` and `actual = COUNT(*)`, or invert the labels to match the sentence. A diagnostic that cannot name its numbers is not evidence.
4. **Decide whether the invariant is right.** `COUNT = MAX(id)` is a valid invariant only if no row is ever hard-deleted. If deletion is a supported operation, define gapless over the live prefix (`MIN(id) = 1 AND COUNT = MAX(id) - MIN(id) + 1`) or over `seq`; amend the plan first, then the code. Either way, the current red state must be resolved by a written decision, not by ignoring the section.

**Verify:**

```powershell
php bin\wallet_reconcile.php; echo "exit=$LASTEXITCODE"   # expected: exit=0, all four sections OK
```

Then delete a binary ledger row on a scratch schema and confirm the Product binary section turns red (the reconciler must still detect a real mismatch).

### Work item 4.4 — Smoke

`powershell -NoProfile -File tests\smoke.ps1` against the running app (Laragon → Start All). Expected: all checks pass, exit 0, count ≥ the phase's floor (≈300). The phase log records "environment-blocked"; that is acceptable only as a **blocked** gate item, never as a pass. Run it, or record the blocker and keep the gate open.

### Work item 4.5 — Warnings

After the suite and smoke pass, inspect `storage/logs/` for new `PHP Warning`, `Notice`, `Deprecated`, or `Fatal error` entries produced during the run. A clean log is a gate item; an undefined-variable warning here is exactly what R1 fix #1 prevents.

### Exit criteria

- [ ] `php tests\run.php` — green, exit 0, **three consecutive runs**, count ≥ 1,735, logs archived.
- [ ] `php tests\run.php DbTarget` — 12/12.
- [ ] `php bin\wallet_reconcile.php` — exit 0, all four sections OK.
- [ ] `php bin\build_install_sql.php --check` — exit 0.
- [ ] `powershell -NoProfile -File tests\smoke.ps1` — green, exit 0 (or explicitly blocked with the reason; gate stays open).
- [ ] No new PHP warning/notice/deprecation in `storage/logs/`.
- [ ] No test was skipped, deleted, weakened, or retried at the suite level to hide a race.
- [ ] Defect register: DbTarget, deadlock, reconciler, and flake items closed with root causes.

---

## 8. Gate R5 — Repair the QA pack and produce real evidence

**Why.** The QA pack is the human gate. Today all **83 `Result` rows are unmarked**, the folder holds **only `README.md`**, the document's structure is broken (Section E has no heading, Section I sits before E/F/H, `## 6. Cleanup` appears twice), and the sign-off sheet nevertheless claims 83/83 with an evidence file for every check. That is a false attestation, and it is the most serious documentation defect in the phase.

**Entry.** R4 closed. QA must run against the **clean, green commit** with the measured counts from R4.

### Work item 5.1 — Structural repair (do this before executing anything)

`docs/qa/phase-22-product-binary.md` currently orders sections:

```
A (90) → B (294) → C (490) → D (687) → G (916) → I (1138) → "## 6. Cleanup" (1255)
→ E checks 01–09 (1261, no heading) → F (1450) → H (1666) → "## 6. Cleanup" (1820)
```

Required order and numbering:

1. Front matter (§1 what is automated, §2 glossary, §3 setup, §4 how to read a check).
2. **Section A** — 22A (8 checks)
3. **Section B** — 22B (9)
4. **Section C** — 22C (10)
5. **Section D** — 22D (12)
6. **Section E** — 22E (9) ← add the missing `## N. Section E — Phase 22E: Admin visibility` heading immediately before `QA22E-01`
7. **Section F** — 22F (10)
8. **Section G** — 22G (12)
9. **Section H** — 22H (7)
10. **Section I** — 22I (6)
11. **Cleanup** — one section only, after I.

Delete the duplicate `## 6. Cleanup`; renumber headings sequentially; keep the "never rewrite a section, append" convention for future phases (this repair is a one-time correction recorded in the phase log). Verify every `QA22X-nn` is unique and sequential, and that each section's expected suite count is labelled **tag-scoped** (e.g. "expected at `p22c-placement`: 1,576") rather than presented as the current count.

### Work item 5.2 — Execute all 83 checks and mark the results

The file contains **84** `☐ Pass` occurrences: the 83 real checks plus the one illustrative row in §4 ("How to read a check"). Do not mark the illustrative row; if a counter reports 84 unmarked boxes, one of them is the example.

For each check:

1. Run it exactly as written (scratch schema for anything that moves money; never the development copy for sections D/H).
2. Replace `☐ Pass  ☐ Fail  ☐ Blocked` with exactly one marked box.
3. Fill the **Evidence** row with the actual file name produced.
4. If anything differs from Expected: mark **Blocked**, save the output, and report — do not continue past a blocked check in a money-carrying section.

**Section execution matrix**

| Section | Checks | Environment | Money? | Evidence types |
|---|---:|---|---|---|
| A | 8 | scratch A/B + development reads | No | SQL/text transcripts |
| B | 9 | development + browser | No | text + screenshots |
| C | 10 | development + scratch | No | text + SQL |
| D | 12 | **scratch only** | **Yes** | SQL/text transcripts |
| E | 9 | development + browser | No (read-only) | text + CSV + screenshots |
| F | 10 | development + browser, mobile | No | text + screenshots |
| G | 12 | development + browser, JS off/on | No | text + screenshots |
| H | 7 | scratch + CLI | No | CLI transcripts |
| I | 6 | release record + scratch | No | text |

### Work item 5.3 — Produce the evidence files

Naming convention (one file per check, minimum):

```
docs/qa/evidence/phase-22/QA22A-01-backup-and-counts.txt
docs/qa/evidence/phase-22/QA22D-03-pair-match.txt
docs/qa/evidence/phase-22/QA22F-04-desktop.png
```

- **Text checks** — paste the command, its full output, and its exit status. Include the SQL and the rows, not a summary sentence.
- **Browser checks** — screenshot at 1440×900 and, where the check says mobile, 390×844. G's first-paint check must show JS disabled.
- **Money checks** — include the before/after row counts and the ledger rows, on the scratch schema name visible in the transcript.
- **CSV checks** — save the CSV next to the transcript and reference both.

Then rewrite `docs/qa/evidence/phase-22/README.md` as a **real index**: one row per check with `ID | File | Date | Tester | Result`. The index must match the folder one-for-one; `QA22I-01` is exactly the check that proves this.

### Work item 5.4 — Independent execution and sign-off

The plan requires the manual script to be executed by **someone who did not write it**. Record that person's name and date. Then:

- Fill the Phase 22 block in `docs/qa/sign-off-sheet.md` from the real results (not 83/83 by assertion).
- The project lead signs the `Project lead | Name | Signature | Date` row. Until that row is filled, the sheet is not signed, and `QA22I-06` is **Blocked**.
- Correct the sheet's closing paragraph: remove "every `QA22X-nn` check has an evidence file" until that is literally true.

### Exit criteria

- [ ] Section order is A→B→C→D→E→F→G→H→I→Cleanup; exactly one Cleanup; all headings numbered consistently.
- [ ] All 83 checks have exactly one marked result (Pass/Fail/Blocked) and an evidence file name.
- [ ] Every named evidence file exists, is non-empty, and shows the command/output or the screenshot it claims.
- [ ] Section E exists as a section with its own heading and its 9 checks.
- [ ] `docs/qa/evidence/phase-22/README.md` indexes every file and matches the folder exactly.
- [ ] The manual pass was executed by someone other than the author; name and date recorded.
- [ ] Project-lead countersignature present.
- [ ] No check is marked Pass on the strength of the automated suite alone unless the check explicitly allows an `automated:` citation.

---

## 9. Gate R6 — Documentation truth pass and count reconciliation

**Why.** The phase log and the plan currently assert things that are false. A future reader — or the Phase 23 implementer — will trust them. Correcting code but leaving the record lying means the next phase inherits a fiction.

**Entry.** R5 closed (the real results and evidence exist).

### Work item 6.1 — Statements that must be corrected

| Location | Current claim | Correct action |
|---|---|---|
| `docs/phase-log.md` 22A–22H headers | `Status: complete` | Keep only if that gate's own exit boxes are ticked after remediation; otherwise `Status: release candidate — gate open` |
| 22B/22C/22D/… gate checklists | `[x] php tests/run.php full suite green` | Uncheck and re-tick only from R4's three green logs |
| 22C/22D gate checklists | `[x] wallet_reconcile exits 0` | Re-tick only from R4's exit-0 output; the current run exits 1 |
| 22I gate checklist | `[x] All nine tags exist` | **False** — only two exist. Uncheck until R7 |
| 22I gate checklist | `[x] Tag p22i-release … tag cut` | Uncheck; the tag does not exist |
| 22I gate checklist | `[x] QA pack sections A–I filled; evidence committed` | **False** — 0/83 marked, folder has only README. Uncheck until R5 |
| 22I hand-off | "The Product binary bonus is **released**." | Replace with "release candidate; gated by §R7" until the tags and merge exist |
| 22C/22D test tables | PASS rows for `T22`, `T31`, `T47`, `T59`, `T17` | Re-map to the plan's definitions; a PASS for a non-existent case is removed |
| 22I "Measured release counts" | `Settings: 86` vs plan `89`; suite `1,735` | Keep measured values, but reconcile every other document to the same number (6.2) |
| Plan status line (line 3–4) | "Status: implemented — all nine phases complete; release tag cut" | `Status: implemented — release gates open (remediation in progress)` until R7 |
| Plan header (line 12) | 1,735 cases "floor 1,734" | Keep only if R4 reproduces it; record the three-run evidence |
| `docs/qa/README.md` | Phase 22 **released**; 83 checks; line 89 "sections A–G complete" | `release candidate`; fix the contradictory A–G line; keep 83 only once all are marked |
| `docs/qa/sign-off-sheet.md` | "83 / 83 … every check has an evidence file" | Rewrite from actual results after R5 |
| `dmp/slices-phase-22-product-binary.md` | Any "complete/signed" claim | Align with R7's actual tag list |

### Work item 6.2 — The 86 vs 89 settings discrepancy

Three authoritative-looking places say **42 tables / 89 settings**: the plan (D-20, header, QA22A-02/07), the `install.sql` header comment, and the QA pack prerequisites. The **measured** harness has **86**. `BinaryReleaseTest` T2 asserts 86 and the phase log calls this a "pre-existing seed deviation".

Pick one source of truth and make every artifact agree:

- **Option A — 86 is correct (recommended if the seed genuinely has 86 keys).** Update the plan (D-20 and every count line), the `install.sql` header, all QA expected blocks, the sign-off sheet and the phase log to **86**. The `--check` gate still passes (it compares bundle to sources, not to a count). Add a note naming the three base settings that were projected but never seeded, so the divergence is explained rather than merely relabelled.
- **Option B — 89 is correct.** Add the three missing base settings to `database/seeds/001_settings.sql` **only if they are genuinely required**; rebuild the bundle; re-run install on a scratch schema; update `BinaryReleaseTest` and every count. Do not invent settings to satisfy a plan number.

Either way: `SELECT COUNT(*) FROM settings` on a **fresh** install and on the migrated copy must be the same number, and that number must appear everywhere. Verify `--check` exit 0 after the change.

Also reconcile the **table** count (42) and the **case** count (1,735) the same way; the case count must come from R4's green run, not from a projection.

### Work item 6.3 — Cold read

Read the release documents against the code, not against each other:

- `docs/deploy.md`, `docs/cron.md`, `docs/runbook.md`, `docs/admin-guide.md`, `docs/install-reset.md`, `docs/live-updates.md`, `system/README.md`.
- Every command copy-pasted and run (or explicitly marked environment-blocked).
- Every setting key named in the docs exists in the seed.
- Every route named in the docs exists in `routes_inventory.php`.
- Every count in the docs matches 6.2.
- No document claims a tag, merge, evidence file or signature that does not exist.

Record the result in `QA22I-03` with the commands run and their outputs.

### Exit criteria

- [ ] Every false claim in 6.1 is corrected, with the commit hash recorded.
- [ ] One settings number (86 or 89) and one table number (42) appear consistently in plan, QA pack, phase log, sign-off, `install.sql` header and `BinaryReleaseTest`.
- [ ] The suite count everywhere equals R4's measured green count.
- [ ] Plan status is not "released" until R7.
- [ ] Cold read completed; every command run or explicitly blocked.
- [ ] No document references a nonexistent evidence file, tag or signature.

---

## 10. Gate R7 — Tags, merge, and release certification

**Why.** This is the plan's 22I-S2 stop condition: the eight earlier gate tags point at commits that passed their gate, the branch is merged, and only then are `p22i-release` and its `phase-22` alias cut on the certified commit. Tags are the durable claim that a specific commit passed a specific gate; creating them before the gates pass — or not creating them at all while the log says they exist — destroys that claim.

**Entry.** R6 closed. The tree is clean, the suite is green three times, the reconciler is green, QA is signed, and the docs are true.

### Work item 7.1 — Create the missing tags, in order

The plan (§2.2) defines **nine gate tags**, one per delivery gate, plus two supporting tags: `p22-start` (the baseline the branch was cut from) and `phase-22` (the annotated alias of `p22i-release`).

**Gate tags** — present: `p22a-foundation`, `p22f-member`. Missing (7): `p22b-configuration`, `p22c-placement`, `p22d-money`, `p22e-admin`, `p22g-tree`, `p22h-operations`, `p22i-release`.
**Supporting tags** — missing (2): `p22-start`, `phase-22`.

| Tag | Kind | Points at |
|---|---|---|
| `p22-start` | supporting | the commit tagged as the Phase 21 release baseline (`p21g-release`) — verify it is the true base before re-cutting |
| `p22b-configuration` | gate | the commit that closed 22B (use the phase log's date/commit; locate it with `git log --before=<date> --oneline`) |
| `p22c-placement` | gate | the commit that closed 22C |
| `p22d-money` | gate | the commit that closed 22D |
| `p22e-admin` | gate | the commit that closed 22E |
| `p22g-tree` | gate | the commit that closed 22G |
| `p22h-operations` | gate | the commit that closed 22H |
| `p22i-release` | gate | the final certified release commit |
| `phase-22` | supporting | the same commit as `p22i-release` (annotated alias) |

Rules:

- A tag must point at a commit whose gate actually passed. If the historical phase-boundary commit cannot pass its gate without the remediation, tag the **remediated** commit and record the deviation ("gate tags consolidated onto the release commit because the intervening commits did not pass the full gate") — never create a tag that implies an untested state passed.
- Use annotated tags with a message naming the gate and the verified counts.
- Do **not** move `p22a-foundation` or `p22f-member` unless they point at a commit that fails the gate; if so, record why and re-cut.
- Verify: `git tag --list 'p22*'` shows the nine gate tags plus `p22-start` (and the `p22-rc-audit-924878c` bookmark from R0, if kept); `git tag --list 'phase-22'` resolves; `git rev-parse p22i-release^{commit}` equals `git rev-parse phase-22^{commit}`.

### Work item 7.2 — Merge

1. Merge `feature/product-binary` into the release branch (the plan says merge the branch before tagging the release). Decide and record the target (`main` or the project's release branch); the audit found HEAD is not an ancestor of `main`.
2. Resolve conflicts by preserving the phase's intent; rerun the **full** R4 gate after the merge — a merge commit is a new state and inherits no test result.
3. Verify ancestry:
   ```powershell
   git merge-base --is-ancestor <release-commit> main; echo "ancestor=$LASTEXITCODE"   # 0
   git rev-list --count main..HEAD                                                      # 0
   ```
4. Push only when the user authorizes it. Creating the merge commit locally is part of the gate; publishing is a separate, explicit decision.

### Work item 7.3 — Final certification run (on the merged, tagged commit)

Run, in this order, and archive every output under `docs/qa/evidence/phase-22/release/`:

```powershell
php bin\build_install_sql.php --check                 # exit 0, no output
php tests\run.php                                     # green, exit 0, ≥ 1,735
php tests\run.php Binary                              # green, exit 0
php bin\wallet_reconcile.php                          # exit 0, four sections OK
powershell -NoProfile -File tests\smoke.ps1           # green, exit 0 (or blocked + reason)
```

Also verify on the release commit:

- `APP_PHASE === 22` (`app/config.php`, `HardeningTest`).
- 42 tables; the agreed settings count; migrations end at 026.
- Exactly one placement route; one release-job cron entry.
- `docs/qa/evidence/phase-22/` contains every file the index names.
- The sign-off sheet's project-lead row is signed.
- `git status --porcelain` is empty; `git describe --tags` resolves to `p22i-release`.

### Exit criteria

- [ ] All nine phase tags exist and point at commits that passed their gate (or a recorded consolidation deviation).
- [ ] `phase-22` is an annotated alias of `p22i-release`.
- [ ] The branch is merged; the release commit is an ancestor of the target branch; divergence is 0.
- [ ] Final certification run is green on the merged commit, with logs archived.
- [ ] QA evidence folder matches its index, and the sheet is countersigned.
- [ ] `docs/phase-log.md` 22I is rewritten from the certification output; every box that is still open is marked open with a reason.
- [ ] Plan status line, QA README and sign-off sheet say **released**, and only now.
- [ ] A release record exists: commit hash, tag, date, measured counts, signatories.

---

## 11. Phase 23 entry certificate

Phase 23 may start only when **all** of the following are true and signed in the release record:

| # | Requirement | Gate |
|---|---|---|
| 1 | Clean tree; the tested commit is the tagged commit | R1, R7 |
| 2 | Registration refuses a taken slot with a field error and creates no user/node; `P22G-T17` proves it through `register()` | R2 |
| 3 | All 282 plan IDs accounted for (case, reclassification or written retirement) | R3 |
| 4 | Full suite green, exit 0, three consecutive runs; `DbTarget` green | R4 |
| 5 | `wallet_reconcile.php` exit 0; `build_install_sql --check` exit 0; smoke green or explicitly blocked and accepted | R4 |
| 6 | No new PHP warning/notice/deprecation in `storage/logs/` | R4 |
| 7 | QA pack structurally correct; 83/83 checks executed and marked; evidence files exist and are indexed | R5 |
| 8 | Manual pass executed by someone other than the author; project lead countersigned | R5 |
| 9 | One settings/table/case count across plan, QA, log, sign-off, bundle header and release test | R6 |
| 10 | No document claims a tag, merge, evidence file or signature that does not exist | R6 |
| 11 | All nine phase tags exist; `phase-22` alias cut; branch merged; ancestry verified | R7 |
| 12 | Final certification run green on the merged commit, logs archived | R7 |

**If any row is unchecked, Phase 22 remains open and Phase 23 does not start.** A phase that begins on an unclosed release gate inherits its defects and repeats them.

### What Phase 23 may assume once this certificate is signed

- `binary_nodes`, `binary_volume`, `binary_awards` are at migration 026 with 42 tables and the agreed settings count.
- Registration placement is strict: one chosen upline, one chosen side, refusal on a taken slot, atomic rollback.
- Every named 22 test exists and passes; the full suite is green at the recorded count.
- The reconciler and release job agree with the ledgers.
- The evidence folder is the reproducible record of the manual gate.

It may **not** assume: that new binary vocabulary is free to add outside the manifest, that a second placement route is acceptable, or that a green binary subset substitutes for the full suite.

---

## Appendix A — Dirty files and their disposition

| File | Type | Required before commit? | Gate |
|---|---|---|---|
| `app/Controllers/AdminCommissionController.php` | app fix | Yes — `exit` in test runner | R1 |
| `app/Controllers/GenealogyController.php` | app fix | Yes — undefined variable | R1 |
| `app/Services/GeneralHarvestService.php` | app fix | Yes — mismatch shape contract | R1 |
| `app/views/admin/commissions/_binary_rows.php` | view fix | Yes — `e()` string cast | R1 |
| `app/views/member/dashboard.php` | view cleanup | Yes — duplicate comment | R1 |
| `tests/Cases/AdminCommissionLiveTest.php` | test fix | Yes — no_exit + array assertions | R1 |
| `tests/Cases/BinaryFoundationTest.php` | test fix | Yes — vocabulary manifest | R1 |
| `tests/Cases/MemberOrderLiveTest.php` | test fix | Yes — binary fragment | R1 |
| `tests/Cases/ProductTest.php` | test fix | Yes — reference map | R1 |
| `tests/Cases/ReportViewTest.php` | test fix | Yes — report fragments | R1 |
| `tests/Cases/TeamHarvestAdminViewTest.php` | test fix | Yes — six tabs | R1 |

## Appendix B — Missing test IDs (measured 49 / 282)

```
P22A (11): T1 T2 T4 T5 T6 T8 T9 T10 T18 T19 T20
P22B (2):  T23 T25
P22C (20): T19 T20 T21 T22 T23 T24 T25 T26 T27 T28 T29 T30 T31 T47 T48 T49 T50 T51 T52 T53
P22D (6):  T3 T4 T5 T13 T14 T59
P22E (1):  T20
P22F (2):  T22 T24
P22H (1):  T17
P22I (6):  T4 T6 T7 T8 T9 T10
```

Coverage scan (re-runnable):

```powershell
cd system
php -r '
$plan = file_get_contents("../dmp/plan-ecowealth-product-binary-sliced.md");
preg_match_all("/P22[A-I]-T[0-9]+/", $plan, $m);
$planned = array_values(array_unique($m[0]));
$found = [];
foreach (glob("tests/Cases/*.php") as $f) {
  if (preg_match_all("/P22[A-I]-T[0-9]+/", file_get_contents($f), $mm))
    foreach ($mm[0] as $id) $found[$id] = true;
}
$missing = array_values(array_filter($planned, fn($id) => !isset($found[$id])));
echo "planned=".count($planned)." present=".count(array_intersect($planned, array_keys($found)))." missing=".count($missing)."\n";
echo implode(", ", $missing), "\n";
'
```

The count may legitimately drop as R2 adds cases; the gate is **0 unaccounted**, not necessarily 0 absent if a retirement is allowlisted.

## Appendix C — QA pack structural defects (exact)

| Defect | Location | Repair |
|---|---|---|
| No `## Section E` heading; 9 checks begin under a stray `## 6. Cleanup` | line 1255 → 1261 | Insert Section E heading; delete the stray Cleanup there |
| Section I placed before E, F and H | line 1138 | Move Section I after H |
| Duplicate `## 6. Cleanup` | lines 1255 and 1820 | Keep one, after Section I |
| Section numbering out of order — observed `5` (A), `7` (B), `10` (C), `11` (D), `12` (G), `13` (I), `6` (first Cleanup), then the unnumbered E block, `9` (F), `8` (H), `6` (second Cleanup); `14` never used | lines 90, 294, 490, 687, 916, 1138, 1255, 1261, 1450, 1666, 1820 | Renumber sequentially: front matter 1–4, A–I = 5–13, one Cleanup = 14 |
| 83 unmarked results | all `Result` rows | Mark each after execution |
| Evidence folder has only README | `docs/qa/evidence/phase-22/` | Produce one file per check; index it |

## Appendix D — Tag and merge plan

```
p22-start ──▶ p22a-foundation ──▶ p22b-configuration ──▶ p22c-placement
   ──▶ p22d-money ──▶ p22e-admin ──▶ p22f-member ──▶ p22g-tree
   ──▶ p22h-operations ──▶ p22i-release (== phase-22) ──▶ merged release branch
```

Currently present: `p22a-foundation`, `p22f-member` — 2 of the plan's nine gate tags. To create: the seven remaining gate tags (`p22b-configuration`, `p22c-placement`, `p22d-money`, `p22e-admin`, `p22g-tree`, `p22h-operations`, `p22i-release`) plus the two supporting tags `p22-start` and `phase-22` — eleven tags in total, nine of them the plan's gate tags.

Verification:

```powershell
git tag --list 'p22*'                             # 10 = nine gate tags + p22-start (+ p22-rc-audit-924878c if kept)
git tag --list 'phase-*'                          # includes phase-22 once cut
git rev-parse p22i-release^{commit}
git rev-parse phase-22^{commit}                   # same hash
git merge-base --is-ancestor p22i-release main; echo "ancestor=$LASTEXITCODE"
```

## Appendix E — Documentation claims to retract now

1. "All nine tags exist" — false: 2 of the plan's 9 gate tags (`p22a-foundation`, `p22f-member`).
2. "`p22i-release` / `phase-22` cut" — false (both absent).
3. "QA pack sections A–I filled; evidence committed" — false (0 of 83 results marked; the evidence folder holds only `README.md`).
4. "`wallet_reconcile.php` exits 0" — false in the current database (exit 1).
5. "Project binary is released" — false until R7.
6. "Every `QA22X-nn` check has an evidence file" — false.
7. "83 / 83 checks" — unexecuted; cannot be claimed.
8. "Full suite green" — false (exit 1).
9. Test rows `P22C-T22/T31/T47`, `P22D-T59`, `P22G-T17` PASS — mismapped or unexecuted.
10. "89 settings" where the measured count is 86 — inconsistent across plan, QA, bundle header and release test.

## Appendix F — Command reference (run from `system/`)

```powershell
# release integrity
git status --porcelain
git log --oneline -10
git tag --list 'p22*'
git branch --contains HEAD
git rev-list --count main..HEAD

# build / schema
php bin\build_install_sql.php --check
php tests\run.php Migration

# tests
php tests\run.php
php tests\run.php Binary
php tests\run.php BinaryPlacement
php tests\run.php BinaryTree
php tests\run.php DbTarget

# money reconciliation
php bin\wallet_reconcile.php; echo "exit=$LASTEXITCODE"
php bin\binary_audit.php --check

# smoke (requires Laragon running)
powershell -NoProfile -File tests\smoke.ps1

# counts (DB_NAME is the harness schema, ecowealth_sneat_test)
php -r 'require "tests/bootstrap.php"; $p=test_pdo(["db"=>DB_NAME]);
echo "tables=".$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn()."\n";
echo "settings=".$p->query("SELECT COUNT(*) FROM settings")->fetchColumn()."\n";'
```

Capture exit codes with `echo "exit=$LASTEXITCODE"` (PowerShell) or `${PIPESTATUS[0]}` (bash). Never let `| tail` or `| head` stand in for the command's status.

## Appendix G — Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| Committing the 11 fixes without review bundles a regression | Release breaks in a new place | File-by-file review in R1; binary + full suite after commit |
| Removing the fallback exposes tests/fixtures that relied on it | Several registration cases fail | Fix fixtures to choose free slots explicitly; never restore fallback for test convenience |
| `wallet_reconcile` "fixed" by deleting the General Harvest data | Loss of real position history | Backup first; repair ids or reseed; record the method |
| Deadlocks hidden with sleeps/retries at suite level | Production race remains | Lock ordering + bounded transaction retry; three consecutive green runs |
| QA passes marked from automated suite without execution | False gate, repeats today's defect | Evidence must show the command or screen; `QA22I-06` verifies signatures |
| Tags created on untested commits | Durable false claim | Tag only commits that passed their gate; record a consolidation deviation if needed |
| Counts "fixed" by inventing settings | Schema drift from the plan's intent | Choose 86 or 89 on evidence; explain the three projected keys |
| Merge conflict resolution silently drops a fix | Regression after a green pre-merge run | Re-run the full gate on the merge commit |

## Appendix H — Suggested commit sequence

1. `fix(22): genealogy display name + commission export no_exit path` — R1 items 1, 3.
2. `test(22): phase-22 vocabulary manifest and fragment/tab expectations` — R1 items 2, 4, 8, 9, 10, 11.
3. `fix(22): general harvest mismatch shape + binary row escaping` — R1 items 5, 6, 7.
4. `fix(22): registration placement refuses a taken slot (remove fallback)` — R2 code.
5. `test(22): complete the P22C registration block and P22G-T17 registration path` — R2 tests.
6. `test(22): complete the P22 named-case inventory and release tests` — R3.
7. `fix(test): isolate DbTarget grant case and wallet deadlock lock order` — R4.
8. `fix(ops): repair General Harvest queue ids and reconcile diagnostics` — R4.
9. `docs(qa): repair Phase 22 pack structure and record 83 executed checks` — R5.
10. `docs(22): correct phase log, counts and release status` — R6.
11. `chore(22): tag the nine gates, merge, certify the release` — R7 (tags and merge are not part of the commit message's promises until verified).

## Appendix I — Evidence naming matrix

| Section | Checks | Required files |
|---|---:|---|
| A | 8 | `QA22A-01…08-*.txt` |
| B | 9 | `QA22B-01…09-*.txt` (+ screenshots where the check says browser) |
| C | 10 | `QA22C-01…10-*.txt` (+ `QA22C-02-audit.txt`) |
| D | 12 | `QA22D-01…12-*.txt` |
| E | 9 | `QA22E-01…09-*.txt` (+ CSV for E-05) |
| F | 10 | `QA22F-01…10-*.txt` (+ `QA22F-04-desktop.png`, `QA22F-04-mobile.png`) |
| G | 12 | `QA22G-01…12-*.txt` (+ `QA22G-02.png`, no-JS capture) |
| H | 7 | `QA22H-01…07-*.txt` |
| I | 6 | `QA22I-01…06-*.txt` |
| Release | — | `release/` final gate logs, tags, ancestry, certificate |

## Appendix J — Release record template

```markdown
# Phase 22 release record

- Commit: <hash>
- Tags: p22i-release / phase-22 (annotated, same commit)
- Merged into: <branch> (ancestry verified: yes/no)
- Date: <date>

## Gates
| Gate | Result | Evidence |
|---|---|---|
| R0 baseline | | |
| R1 release-state commits | | |
| R2 placement contract | | |
| R3 test inventory | | |
| R4 suite / reconcile / smoke | | |
| R5 QA pack + evidence | | |
| R6 documentation truth | | |
| R7 tags + merge + certification | | |

## Measured counts
- Tables: __
- Settings: __
- Migrations: 001–026
- PHP cases: __ (three consecutive green runs)
- Smoke checks: __
- APP_PHASE: 22
- Placement routes: 1

## Signatures
- QA tester (independent): name / date
- Project lead: name / signature / date
- Engineering owner: name / date

## Known deviations carried into Phase 23
1.
```

---

**End of document.** Phase 22 stays open until Gate R7's certificate is signed; Phase 23 starts from that commit and from nothing earlier.
