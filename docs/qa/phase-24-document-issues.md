# Phase 24 QA walkthrough — potential issues found

**Document reviewed:** [`phase-24-package-commissions.md`](phase-24-package-commissions.md) (v3.1)
**Review date:** 2026-11-28
**Execution:** the walkthrough was driven over HTTP against `ecowealth_sneat` after a database reset; the automated run recorded **30 of 30 assertions passing**. The database was returned to an unarmed/empty state afterwards.
**Evidence:** [`evidence/phase-24/run/run-20261128/`](evidence/phase-24/run/run-20261128/) — `qa24-run.log`, `qa24-run-results.json`, per-check CSVs. Runner: [`evidence/phase-24/run/qa24_http_run_20261128.php`](evidence/phase-24/run/qa24_http_run_20261128.php).
**Pre-run backup:** `backups/20261128/pre-phase-24.sql` (1,191,385 bytes).

The list separates **confirmed** findings (reproduced in the run or verified in the application code) from **potential** issues (the step is ambiguous, environment-dependent or contradicts another part of the document).

## Summary

| ID | Location | Severity | Status | Issue |
|---|---|---|---|---|
| DOC-01 | QA24-29 | High | Confirmed | Cleanup deletes the General Harvest awards but leaves the board armed and settled, so `wallet_reconcile.php` exits 1. |
| DOC-02 | QA24-11 | Medium | Confirmed | The final bullet says the skipped level-4 amount is ₱50; the level-4 amount is ₱40. |
| DOC-03 | QA24-03 | Medium | Confirmed | The expired-code sub-check cannot be performed on a clean copy: no expired codes exist and the issue form refuses a past expiry. |
| DOC-04 | QA24-18 | Medium | Confirmed | The tester is told to approve `QA24_Zero1`–`Zero3` proofs, but never told to submit those proofs. |
| DOC-05 | QA24-05 | Medium | Confirmed | “Try rejecting again with the reason field empty” is unreachable: after the rejection the row is no longer `pending_review` and has no reject form. |
| DOC-06 | QA24-04 | Low–Medium | Confirmed | “Approving the same request a second time” is not reachable through the UI; the approve form disappears after approval. |
| DOC-07 | Header / §3.1 | High (fresh schema) | Confirmed by prior run | `admin` / `Admin@123` is not valid on a schema installed with `bin/db_install.php`, which refuses published defaults. |
| DOC-08 | QA24-19 | Medium | Potential | The failure recovery says to seed the queue after the tree exists; that records already-complete levels as `baseline`, contradicting the document's own Part 1 rule. |
| DOC-09 | QA24-29 | Medium | Potential | The registration-code cleanup deletes every code issued by `admin` on/after the run start, which can delete non-QA codes. |
| DOC-10 | QA24-18 | Low | Potential | “Confirm no `binary_awards` or `binary_volume` rows” has no query or filter; the pair already wrote volume rows. |
| DOC-11 | §3.4 | Low | Potential | The backup command assumes `mysqldump` is on `PATH`, the target directory exists, and PowerShell redirection preserves the dump encoding. |
| DOC-12 | §5 | Low | Potential | The URL column omits the `/ecowealth/system` prefix used by the app URL in the header. |
| DOC-13 | QA24-29 | Low | Confirmed wording | “Normal demo accounts still there” is not true on a fresh QA schema, which has only the staff accounts. |
| DOC-14 | QA24-29 | Low | Confirmed | The cleanup leaves the Team Harvest seed marker (`team_harvest_ledger_since`) set, so the copy is not in its documented pre-test state. |

---

## DOC-01 — QA24-29 cleanup leaves the General Harvest board inconsistent

**Where:** QA24-29, steps 3–5 and “You should see” (around lines 1423–1465).

**What the document says:** after deleting the `QA24…` users and running the code cleanup, “Reconcile exits **0**.”

**What was observed:** the user delete cascades the General Harvest awards away, but `general_harvest_board` remains armed with `baseline_n = 0` and `settled_n = 8` (the eight queue positions from the run). The next reconciliation exits **1**:

```text
General Harvest
  credited awards:   0 (0.00)
  baseline awards:   0 (0.00)
  general_harvest ledger: 0.00
  2 problem(s):
  - [ledger_vs_closed_form] credited awards total ₱0.00 but the closed form expects ₱4,000.00
  - [board_bounds] board bounds broken: baseline_n = 0, settled_n = 8, N = 0
```

This is a real inconsistency, not a warning: the next `php bin/wallet_reconcile.php` exits 1 and the document's QA24-29 result cannot be Pass as written. The automated runner's own QA24-29 assertion only checked that the `QA24…` user count reached zero; the document's separate “reconcile exits 0” expectation is what fails.

**Why:** `general_harvest_board` is a singleton state row. It is not a child of `users`, so the member delete cannot cascade it. The document's delete list clears the awards and the queue but never returns the board to its pre-test (unarmed) state. The same cleanup also leaves `team_harvest_ledger_since` set (DOC-14), so the “pre-test state” is not fully restored even after the users are gone.

**Suggested correction:**

- Add an explicit final step to QA24-29 that returns the board to unarmed after the QA users are removed, using the supported reset/restore path; do not hand-edit the board. The runbook's rollback is `baseline_n = NULL, armed_at = NULL, armed_by = NULL, settled_n = 0` plus truncating `general_harvest_awards` when no money exists. A `bin/db_reset.php` or restore of the pre-run backup also finishes the job.
- Alternatively, state that QA24-29's expected result is “restore the pre-run backup” rather than “reconcile exits 0 after the deletes.”
- The header already notes (item 8) that the replacement cleanup “was not itself executed end to end”; this run confirms the consequence.

**Resolution (walkthrough v3.2):** QA24-29 step 5 now runs the supported business reset
(`php bin\db_reset.php --force --confirm=<database>`) after the deletes, with the pre-run backup
restore as the alternative for a copy whose demo members must survive. The reset returns the board to
`(NULL, NULL, NULL, 0)`, empties `general_harvest_awards` and clears `team_harvest_ledger_since`
(DOC-14); `wallet_reconcile.php` then exits 0. QA24-29's expected result now includes the unarmed
board, and `QA24-29-reset.txt` records the reset output.

The step was re-run on a throwaway schema (`ecowealth_sneat_doc01_verify`, installed from
`bin/db_install.php` and dropped afterwards) in the exact post-cleanup state: reconcile exited 1 with
the two problems above, the reset returned the board to `(NULL, NULL, NULL, 0)`, cleared the seed
marker and emptied the awards, and the next reconcile exited 0. The run also exposed a bootstrap gap:
`bin/db_reset.php` did not load `app/helpers.php` or `app/database.php`, so its General Harvest
verification died on `setting_int()`/`db()` and the reset exited 1 even though the data was reset. The
CLI now loads both like the other `bin` jobs; the reset verifies every check (including
`GeneralHarvestService::reconcile clean`) and exits 0.

---

## DOC-02 — QA24-11 quotes the wrong level-4 skipped amount

**Where:** QA24-11, final bullet (line 869).

**What the document says:** “The skipped **₱50** is not redirected to anyone — that is the level-4 company-root skip…”

**What the document's own table says:** the four rows for source `QA24_Member_C` are:

- level 1 `QA24_Member_B` ₱500 credited
- level 2 `QA24_Member_A` ₱100 credited
- level 3 `QA24_Sponsor` ₱50 credited
- level 4 `admin` **₱40** skipped

QA24-12 also says “The level-4 amount is **₱40**, not ₱50.” The run's commission evidence confirms the level-4 skipped row is `admin`, level 4, **40.00** (`qa24-09-commissions.csv`, row 10).

**Impact:** a tester comparing the ledger with the prose can record a false failure at the level-4 row, or look for a ₱50 skip that does not exist.

**Suggested correction:** change the final bullet to **₱40** (and, preferably, “level-4 company-root skip”), matching the table and QA24-12.

**Resolution (walkthrough v3.3):** QA24-11's final bullet now says **₱40**, matching the table's
level-4 row and QA24-12; the run's commission evidence (`qa24-09-commissions.csv`, row 10) has
`admin`, level 4, `40.00`, skipped. The walkthrough's version row and correction list record the
change.

---

## DOC-03 — QA24-03's expired-code case cannot be run on a clean copy

**Where:** QA24-03, step 4 (line 745).

**What the document says:** “Find an expired code (Commerce → Codes, filter **Expired**) and try the same.”

**What was observed / verified:**

- A fresh install and `bin/db_reset.php` both leave **0** registration codes (`db_reset` reports `codes 0`), so there is no expired code to find.
- The Codes issue form's expiry input has `min="<?= date('Y-m-d', strtotime('+1 day')) ?>"` (`system/app/views/admin/codes/index.php`, line 149). The UI cannot issue a code with a past expiry, so a tester cannot create the fixture during the run.
- The automated runner covers the invalid-code path; the used-code and expired-code branches were not executed.

**Impact:** QA24-03 cannot be completed as written on the clean copy the document requires; the expired-code branch is effectively untested.

**Suggested correction:** add a documented fixture step that inserts a known expired code (for example through a SQL statement with `status = 'expired'`, or an explicit CLI/seed step), or remove the expired case from the manual check and list it as a separate, environment-specific test. State which code is the “used” one in step 3, since QA24-02 issues three.

**Resolution (walkthrough v3.4):** QA24-03 no longer hunts for a code that a clean copy does not
have. A new step 4 issues one spare Package A code and marks it expired the documented way:

```sql
UPDATE reg_codes
   SET status = 'expired',
       expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
 WHERE code = '<the spare code>';
```

The check then validates and submits that code like the other two, and records
`QA24-03-code-expired.png` (the Codes list filtered to **Expired**). Step 3 now names the used code —
the third code from QA24-02, the one that registered `QA24_Member_B` — and §3.5 lists this `UPDATE`
as the only data-changing statement outside QA24-29. The version row and correction list (item 12)
record the change.

The fixture mirrors the expired-code case the suite already runs: `MembershipTest` P9-T3 issues a
fresh code, backdates `expires_at` with the same `DATE_SUB`, and asserts both the refusal and the
`expired` flip. Writing `status` as well only makes the row visible under the Codes **Expired**
filter before its first validation; `CodeService::validate()` refuses a row whose status is
`expired` with *That registration code has expired.* The form's `min = tomorrow` guard is unchanged —
the screen is meant to issue only future-dated codes, and the walkthrough now says so instead of
leaving the tester to discover it.

---

## DOC-04 — QA24-18 never tells the tester to submit the three proofs

**Where:** QA24-18, steps 0–1 (lines 1299–1310).

**What the document says:** step 0 registers `QA24_Zero1`, `QA24_Zero2` and `QA24_Zero3` as **pending** members. Step 1 immediately says “Approve `QA24_Zero1`'s proof.”

**What is missing:** the tester is never told to open each member's Membership page, choose **Payment proof**, attach a receipt and submit it — the flow that QA24-04 documents for `QA24_Member_C`. A pending member has no proof in the admin queue until that happens, so “Approve” has nothing to approve.

**Impact:** a manual tester following the steps in order cannot perform QA24-18 without inferring the missing QA24-04 flow. The automated run had to submit the proofs explicitly before each approval.

**Suggested correction:** add a step between step 0 and step 1: for each of the three members, sign in as the member, open Membership, submit a manual proof for Package A, then return to the admin queue and approve it. Alternatively say “repeat QA24-04 steps 2–3 for each zero-point member.”

**Resolution (walkthrough v3.5):** QA24-18 now submits the three proofs before it approves them. A
new step 1 signs in as `QA24_Zero1`, `QA24_Zero2` and `QA24_Zero3` in turn (Window B, after the
registration in step 0) and walks the QA24-04 steps 2–3 flow — **Membership** → **Payment proof** →
GCash → Package A → reference → receipt → **Submit for review** — until each page shows **Activation
in progress**; Commerce → **Activations** → *Awaiting review* then lists all three. The approvals
moved down one step, and the check's `Where` row now opens with `/membership`. The automated run
already submitted a proof for every member (`submit_proof()` before each `admin_approve()` in
`qa24_http_run_20261128.php`), so this only writes down what the manual check was silently assuming.
The walkthrough's version row and correction list (item 13) record the change.

---

## DOC-05 — QA24-05's empty-reason rejection is unreachable

**Where:** QA24-05, step 3 (line 1207) and “You should see.”

**What the document says:** after rejecting D's proof, “Try rejecting again with the reason field empty” and expect “The empty-reason rejection is refused.”

**What the application does:** the admin activation row only renders the reject form when the activation status is `pending_review` (`system/app/views/admin/activations/_rows.php`, `$isReview = status === 'pending_review'`). After step 2 the row is `rejected`; it shows no Approve or Reject controls. The service does validate the reason — `ActivationService::reject()` requires at least 3 characters — but the manual step cannot reach it on the rejected row.

**Impact:** the check cannot be performed as written; a tester may mark it Blocked or, worse, assume the UI accepted an empty reason.

**Suggested correction:** move the empty-reason attempt **before** the valid rejection, while D is still `pending_review`, or state that the empty-reason validation is a service-level test and provide a fresh pending activation for it. The expected message should name the minimum length (currently “at least 3 characters”), not simply “refused.”

**Resolution (walkthrough v3.6):** QA24-05 now makes both refusals **before** the valid rejection,
while D is still `pending_review`. Step 2 presses **Reject** with the reason box empty, and the
confirmation box refuses to post (*Enter rejection reason (at most 160 characters).*); step 3 types
a two-character reason (`no`), which the box accepts and the server refuses with *Enter a short
reason (at least 3 characters) so the member knows what to fix.* Step 4 then rejects with a real
reason. D stays **Awaiting review** through both refusals: `ActivationService::reject()` validates
the reason before it opens the transaction, so nothing is written, and the row keeps its controls
because its status is still `pending_review` (`system/app/views/admin/activations/_rows.php`). The
check's expected result now records both refusals and names the minimum length; the walkthrough's
version row and correction list (item 14) record the change.

---

## DOC-06 — QA24-04's “approve a second time” is not a UI step

**Where:** QA24-04, “You should see” (lines 783–784).

**What the document says:** “Approving the same request a second time is refused with *This activation has already been completed. Nothing was changed.*”

**What the application does:** after the first approval the activation is `approved`; the row is no longer `$isReview`, so the Approve button is not rendered. The message exists in `ActivationException::processed()`, but there is no visible control to trigger it in the manual walkthrough.

**Impact:** the expected result is true at the service level but not reachable from the described UI steps. A tester may mark it Blocked or think the guard is missing.

**Suggested correction:** either remove the second-approval expectation from the manual flow or label it as an API/service check (for example, an automated POST or the existing test suite). If it stays manual, add a deliberate second POST with a CSRF token and state that it is not a normal UI action.

**Resolution (walkthrough v3.7):** QA24-04 no longer presents the second approval as a UI step. Its
expected result now says the approved row has no **Approve** or **Reject** control — the request has
left *Awaiting review* — and attributes the *This activation has already been completed. Nothing was
changed.* guard to the automated checks that perform the crafted repeat POST: the suite's `P9-T12`
(`system/tests/Cases/MembershipTest.php`) calls `ActivationService::approve()` twice and asserts the
second call is refused without re-running the commission hook or moving the wallet, and
`system/tests/smoke.ps1` posts to `/admin/activations/{id}/approve` a second time with the CSRF token
and asserts one `approved` row and one commission stamp. `ActivationService::approve()` is unchanged;
the guard exists and stays covered, it simply has no button once the row is approved. The
walkthrough's version row and correction list (item 15) record the change.

---

## DOC-07 — The documented admin password conflicts with a fresh schema

**Where:** header line 18 and §3.1 line 118: `admin` / `Admin@123`.

**What was found:** the v3.1 header says the walkthrough was reproduced against a fresh schema (`ecowealth_sneat_qa24`). The Phase 24 run report records that the installer refuses published defaults, so that run used `admin` / `Qa24Run!Pass2026` on the fresh schema (§6 of `phase-24-run-report.md`). A tester who creates a fresh QA schema with `bin/db_install.php` cannot sign in with `Admin@123`; §3.1 stops immediately. The development `ecowealth_sneat` copy used for this review does accept `Admin@123`.

**Impact:** environment-dependent blocker for the fresh-schema path the document's own header describes.

**Suggested correction:** replace the fixed login row with “the admin account of this copy” and state that a fresh schema must be installed with known staff credentials. Include the exact install command/password source in §3.1, or use the development copy the document is written against.

---

## DOC-08 — QA24-19's late-seed recovery contradicts the baseline rule

**Where:** QA24-19, “If it fails — no rows at all” (lines 974–976).

**What the document says:** if the queue is empty, run `php bin\harvest_queue_seed.php`, “re-register nothing, and re-run this query; if rows then appear, mark this check **Pass with note**.”

**Why this is a problem:** the document's Part 1 (“Before QA24-08”) correctly says both harvest baselines must be prepared **before anyone registers**, because anything already complete at the seed/arm is written as `baseline` and is never paid (D-06/D-07). If the tester follows QA24-19's recovery after the tree is built, the seed inserts the already-qualified members with a later baseline; the Team Harvest and General Harvest awards the check later expects cannot exist. Marking Pass with a note hides that.

**Impact:** a tester who hits the empty-queue failure is led into a state that will fail QA24-20, QA24-23 and QA24-24, with an instruction to mark the first one Pass.

**Suggested correction:** mark QA24-19 **Blocked**, stop the run, restore the pre-run backup (or reset), and start Part 1 again. Do not seed after registrations. The “Pass with note” fallback should be removed for this specific ordering failure.

---

## DOC-09 — QA24-29's code cleanup can delete non-QA codes

**Where:** QA24-29, step 3 (lines 1435–1441).

**What the document says:** delete registration codes by date and issuer:

```sql
DELETE FROM reg_codes
 WHERE created_by = (SELECT id FROM users WHERE username = 'admin')
   AND created_at >= '<the date you started this run>';
```

**Why this is a problem:** codes are random, not prefixed `QA24`, so the statement deletes **every** code issued by `admin` on or after the run start. On a clean QA schema that is the QA batch; on a development copy with existing admin-issued codes (the pre-reset copy had 45 codes), it can delete codes that are not part of the QA run. The same check's failure note says “never delete rows that do not start with `QA24`”, which the date/issuer filter cannot guarantee for codes.

**Impact:** potential loss of unrelated registration codes during cleanup. It did not occur in this run because the runner deleted the exact code values it issued, but the document's SQL remains broad.

**Suggested correction:** delete the exact codes the run issued (the document can record them in a note, or the issue-batch response can be copied), or add a dedicated batch/run identifier to the cleanup. If date/issuer is retained, state the precondition that no other codes were issued by `admin` on that date, and take a backup first.

---

## DOC-10 — QA24-18's “no volume or award rows” has no query

**Where:** QA24-18, step 5 (line 1316).

**What the document says:** “Confirm no `binary_awards` or `binary_volume` rows were written by any of the three.”

**Why this is a problem:** the run already has `binary_volume` rows from the legitimate pair and the three zero-point activations have no obvious identifier in the table. A blanket `SELECT COUNT(*) FROM binary_volume` is misleading (16 rows existed in the run), and the document gives no activation IDs or filter. A tester cannot distinguish “the three wrote none” from “the pair wrote some.”

**Suggested correction:** give a query keyed to the three activation IDs (for example, select the three latest `activations.id` for the zero members, then count `binary_volume WHERE activation_id IN (...)` and `binary_awards WHERE activation_id IN (...)`), or state that the expected count is unchanged from before the three approvals and give the before/after numbers.

---

## DOC-11 — §3.4 backup command is fragile on this Windows setup

**Where:** §3.4, lines 162–167.

**What the document says:**

```powershell
cd C:\laragon\www\ecowealth
mysqldump -u root -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-24\pre-phase-24.sql
```

**Potential problems:**

- `mysqldump` is not on `PATH` on this machine; the binary is at `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe` (the same Laragon install the document tells the tester to use for PHP in §3.6). The bare command can fail with “not recognized.”
- The target folder `C:\laragon\www\ecowealth-backups\phase-24\` is not created by the document. If it does not exist, the redirection fails before the dump starts.
- In Windows PowerShell, `>` is a text redirection and can write UTF-16; a MySQL dump redirected this way may not import cleanly. The runbook uses the same form, but the QA document's backup is the safety net for a destructive cleanup.

**Suggested correction:** give the full `mysqldump.exe` path, create the folder first (`New-Item -ItemType Directory -Force`), and either run the command through `cmd /c` with `-r`/`--result-file`, or use `Out-File -Encoding utf8`. Verify the file starts with `-- MySQL dump` and is not 0 bytes before proceeding.

---

## DOC-12 — §5 URLs omit the application's subfolder

**Where:** §5 “Where everything lives”, URL column (for example lines 247–253).

**What the document says:** the app URL is `http://localhost/ecowealth/system/`, but the table's URLs are `/admin/codes`, `/admin/settings#settings-commissions`, `/earnings`, etc.

**Why this is a problem:** a tester who copies `/admin/settings` into the browser at `localhost` gets a 404; the live path is `/ecowealth/system/admin/settings`. The menu paths are correct, so this is a navigation/usability issue rather than a functional one.

**Suggested correction:** prefix the URL column with `/ecowealth/system/`, or add a sentence that every URL in the table is relative to the app URL in the header.

---

## DOC-13 — QA24-29's “normal demo accounts” may not exist

**Where:** QA24-29, “You should see” (line 1459).

**What the document says:** “Zero `QA24…` users and codes; the seeded `admin` and normal demo accounts still there.”

**Why this is a problem:** a fresh QA schema created by `bin/db_install.php` has the staff accounts but no demo members. If the tester uses the clean schema the header describes, there are no “normal demo accounts” to confirm. On a development copy with demo accounts, the wording is fine. The check is environment-dependent.

**Suggested correction:** say “the staff accounts (and any pre-existing demo accounts)” or name the actual accounts the copy starts with. Do not make the result depend on demo data that the clean path does not create.

---

## DOC-14 — QA24-29 leaves the Team Harvest seed marker set

**Where:** QA24-29, steps 1–5.

**What the document says:** QA24-29 returns the system to its **pre-test** state.

**What was observed:** the cleanup removes the `QA24…` users and their `harvest_queue` rows, but it does not clear `settings.team_harvest_ledger_since`. That hidden setting was written by `bin/harvest_queue_seed.php` in Part 1 and is not one of the nine controls recorded in QA24-07, so the settings-restore step does not touch it. The restart after this run was what cleared it (a reset); the document's own cleanup alone would leave the queue marked as live.

**Impact:** low on a disposable QA copy; it means a repeat run is not starting from the documented pre-test state and the seed step can re-baseline unexpectedly. The General Harvest board (DOC-01) is the more serious half of the same cleanup gap.

**Suggested correction:** include `team_harvest_ledger_since` in the cleanup restore (clear it with the supported seed/reset path), or state that the reliable finish is a reset/restore of the pre-run backup.

---

## Not verified by this execution

The following are manual or UI-specific expectations in the document. They were not exercised by the automated HTTP runner, and no screenshots were taken; they are not counted as defects above, but they remain residual risk for a manual sign-off.

| Area | Why it is unverified |
|---|---|
| QA24-07 settings save, confirmation banner, “No changes to save” | The runner wrote the nine settings by SQL; the settings card's save/validation path was not exercised. |
| QA24-08 package edit screen, badge, two switches after reload | The runner set Package A by SQL. |
| QA24-02 code-issuing screen and “Used” list | The runner issued codes through `CodeService`, not the `/admin/codes` form. |
| QA24-04 proof-image access (member 403, guest redirect) | Not exercised; the v3.1 run report already lists this as manual. |
| QA24-13 member CSV export | The runner checked `/earnings` only, not the member export file. |
| QA24-14 audit `commission.export` row, 403/guest guard | The runner exported the admin CSV but did not inspect the audit row or the 403 redirect. |
| QA24-15 placement cards | The runner checked `binary_nodes` rows, not the member cards. |
| QA24-17 binary ledger tiles, source/points/rate columns | The page was not parsed; only the pair award and wallet row were checked. |
| QA24-21 member Team Harvest matrix and CSV | The runner checked HTTP 200 and the amount text, not the full matrix. |
| QA24-22 admin Team Harvest export/audit | Not exercised beyond the page render. |
| QA24-23 header badges and Level distribution table | The runner checked the board row and awards in SQL; the UI table was not parsed. |
| QA24-24 wallet label and withdrawable movement | The runner checked the award/ledger link, not the wallet screen label. |
| QA24-25 member General Harvest tab and next-block card | Not exercised. |
| QA24-26 General Harvest export/audit and tiles | Not exercised beyond page render. |
| All screenshot evidence files | No screenshots were produced by the automated run. |

## Run result summary

- Automated assertions: **30 / 30 passed** (`qa24-run-results.json`).
- Key totals observed: commissions credited **₱4,040**, Team Harvest **₱1,000**, General Harvest **₱4,000** (two awards: position #1 Sponsor ₱2,000, position #2 `QA24_Member_A` ₱2,000), package pair **₱100**; skipped company-root rows **₱690**.
- The run's own Part 7 reconciliation exited **0**; the failure in DOC-01 appears only **after** the QA24-29 cleanup.
- After the finding in DOC-01, `bin/db_reset.php` was run again to return the development database to an unarmed, empty state; the final `wallet_reconcile.php` exits **0** and the board is `baseline_n = NULL`, `armed_at = NULL`, `settled_n = 0`.
