# Phase 24 — executed walkthrough: run report

**Date:** 23 November 2026
**Document under test:** [`phase-24-package-commissions.md`](phase-24-package-commissions.md) (v2 at the time of the run; corrected to v3 by it)
**Result:** **29 of 30 assertions passed.** One step failed — the cleanup (`QA24-29`) — because deleting test members is blocked by a real foreign key. The walkthrough itself was wrong in six places; all are fixed in v3 and listed in §7 below.

---

## 1. What was executed

The run drove the real application over HTTP (`php -S`, the app's own router), against a **fresh, isolated schema**, in the document's order. Nothing was written to the development database (`ecowealth_sneat`).

| Driven through the UI (HTTP) | Changed directly (documented substitute) |
|---|---|
| Admin sign-in, member sign-ins | The eight settings values (the settings card's POST path was not exercised) |
| 16 registrations (code / standard / pending) | Package A's binary switch and points |
| Manual proof uploads (real multipart PNG) | — |
| Admin approvals and the rejection with a reason | — |
| `/earnings`, `/earnings?ledger=team_harvest`, `/admin/commissions`, exports | — |
| `bin/harvest_queue_seed.php`, `bin/general_harvest_arm.php`, `bin/wallet_reconcile.php` (real CLIs) | — |

**Not executed:** the settings card's save button, the package-edit screen, the code-issuing screen, proof-image download as a member/guest, and any screenshot-based checks. These remain human checks in the document.

**Environment**

| | |
|---|---|
| Schema | `ecowealth_sneat_qa24` (created by `bin/db_install.php`, 42 tables, 92 settings, verified) |
| Admin credential | `admin` / `Qa24Run!Pass2026` — the installer **refuses published defaults**, so `Admin@123` from the document cannot be used on a freshly installed schema |
| HTTP server | `php -S 127.0.0.1:8899 -t public public/router.php` with `ECO_DB_NAME=ecowealth_sneat_qa24` |
| Runner | `docs/qa/evidence/phase-24/run/qa24_http_run.php` |
| Raw log | `docs/qa/evidence/phase-24/run/qa24-run.log` and `run-stdout.txt` |
| Machine-readable results | `docs/qa/evidence/phase-24/run/qa24-run-results.json` |

---

## 2. Timeline and real messages

Every line below is the actual response text the app produced.

| Step | Action | Message / result |
|---|---|---|
| Install | `db_install.php --database=ecowealth_sneat_qa24` | `Installed and verified.` — 42 tables, 92 settings, 2 staff, 0 members |
| Part 1 | Settings written (8 keys) | depth 4, team gate 1, team depth 1, general depth 1 |
| Part 1 | `harvest_queue_seed.php` | `inserted: 0 position(s)`, `baseline: … (team_harvest_ledger_since)`, `The registration hook is now live` |
| Part 1 | `general_harvest_arm.php` (stream off) | exit 0, `N0 = 0`, no rows — nothing is complete on an empty queue |
| Part 1 | General Harvest switched on | baseline `baseline_n = 0`, `armed_at` stamped |
| Part 1 | Package A: `binary_enabled = 1`, `binary_points = 1000`, master switch on | `enabled=1 points=1000 master=1` |
| Part 2 | Register 3 members with codes | `Welcome aboard! Your membership is active with Package A.` |
| Part 2 | Pair settles | `binary_awards#1` — Sponsor, pair, 2000 pts, `1` pair, `10.000%`, **₱100.00**, credited, ledger #4 |
| Part 2 | Activation points | `binary_points 1000`, `binary_total 0.00` |
| Part 3 | 6 standard members | `Welcome! Your standard account is ready — you can start shopping.` |
| Part 3 | Team Harvest, live | 1 award: Sponsor, L1, `threshold_n 3`, `n_at_award 3`, **₱1,000.00** |
| Part 3 | Invalid code | `Please fix the highlighted field and try again.` — detail: `Enter a complete registration code (XXXX-XXXX-XXXX).` |
| Part 3 | Member C pending | `Welcome! Your account is pending. Activate it from Membership when you are ready.` |
| Part 3 | C proof | `Your payment proof is with the review team. You will become active as soon as it is approved.` |
| Part 3 | C approved | `Activation #4 approved — @QA24_Member_C is now active with Package A (₱4,498.00).` |
| Part 4 | Commission rows (C) | B ₱500 L1 credited; A ₱100 L2 credited; Sponsor ₱50 L3 credited; **admin ₱50 L4 skipped** |
| Part 4 | `/earnings` as Sponsor | HTTP 200, total **650.00** present |
| Part 4 | `/admin/commissions` + export | HTTP 200; CSV 2,624 bytes |
| Part 5 | Queue | 6 positions, `n = max_id = 6` |
| Part 5 | Board | `baseline_n 0`, `settled_n 6` |
| Part 5 | General Harvest | 1 award: seq 0, Sponsor, L1, **₱2,000.00** |
| Part 5 | Team tab | `/earnings?ledger=team_harvest` HTTP 200, **1,000** present |
| Part 6 | D proof rejected | `Activation #5 rejected. The member is back to pending and can try again.` |
| Part 6 | D proof approved | `Activation #6 approved — @QA24_Member_D is now active with Package A (₱4,498.00).` |
| Part 6 | D's ladder | C ₱500, B ₱100, A ₱50, **Sponsor ₱40** — four credited, **no skip** |
| Part 6 | No-package sponsor | NoPkg_Sponsor ₱500 **skipped**; Sponsor ₱100 L2 credited; admin ₱50 L3 skipped |
| Part 6 | Zero-point controls | all three activations `binary_points 0`, `binary_total 0.00` |
| Part 7 | `wallet_reconcile.php` | exit 0 — `All good — wallets, commissions, transfers, payouts, repeat-purchase, royalty, team-harvest, general-harvest and product-binary awards reconcile.` |
| Part 7 | Sums | commissions credited **4040.00** = commission ledger **4040.00**; 0 orphan awards |
| Part 7 | Cleanup | **FAILED** — see §5 |

---

## 3. Final state (after Part 6, before cleanup)

| Member | Status | Wallet | Of which |
|---|---|---|---|
| `QA24_Sponsor` | active, Package A | **₱5,390.00** | commissions 2,290 + pair 100 + team 1,000 + general 2,000 |
| `QA24_Member_A` | active, Package A | **₱2,650.00** | commissions 650 + general 2,000 (board position #2) |
| `QA24_Member_B` | active, Package A | ₱600.00 | B's direct + indirect |
| `QA24_Member_C` | active, Package A | ₱500.00 | direct from D |
| `QA24_Member_D` | active, Package A | ₱0.00 | — |
| `QA24_NoPkg_Sponsor` | active, no package | ₱0.00 | ₱500 was skipped |
| `QA24_Zero1–3` | active, Package A | ₱0.00 | — |
| Team accounts / `…a` | active, no package | ₱0.00 | — |

Stream totals: commissions **₱4,040**, team harvest **₱1,000**, general harvest **₱4,000** (positions #1 and #2), package pairs **₱100**.

The Part 5 sign-off totals in the document (Sponsor ₱3,750, A ₱600, B ₱500, C ₱0, total ₱4,850) **matched the run exactly**.

---

## 4. Check results

29 assertions passed. The suite also asserted the two pre-flight steps (seed before registrations, arm at N₀ = 0) and the CSV export.

| Check | Result | Observed |
|---|---|---|
| QA24-02 … QA24-11, QA24-13 … QA24-28 | Pass | values in §2 |
| QA24-12 | Pass (after correction) | 4 credited rows, no level-4 skip |
| QA24-18 | Pass (after correction) | zeros stamped on all three |
| QA24-20 | Pass (after correction) | one award, Sponsor ₱1,000 |
| QA24-23 / QA24-24 | Pass (after correction) | armed at 0; one award at Part 5, second at Part 6 |
| **QA24-29** | **Fail** | see §5 |

---

## 5. The failure: QA24-29 cannot delete the test members

```
SQLSTATE[23000]: Integrity constraint violation: 1451
Cannot delete or update a parent row: a foreign key constraint fails
(`ecowealth_sneat_qa24`.`binary_volume`, CONSTRAINT `fk_bv_award`
FOREIGN KEY (`award_id`) REFERENCES `binary_awards` (`id`) ON DELETE RESTRICT)
```

16 QA24 members remained after the delete. The document told the tester to run a plain
`DELETE FROM users WHERE username LIKE 'QA24_%'`, and its note claimed the delete "cascades to …
binary nodes/volume/awards". It does not: `binary_volume` deliberately restricts deletion of an
award that has volume attached.

**Fixed in v3:** delete `binary_volume` rows for the QA24 awards first, then the users, and delete
codes by issuer + date (registration codes are random strings, never prefixed `QA24`). A restore
from the pre-run backup is documented as an acceptable finish. The constraint was **not** disabled
during the run.

**Product note:** this is consistent with "awards are never deleted" but it means there is no clean,
supported account-removal path once package points exist. Only the business reset removes them. Worth
a product decision; recorded here rather than filed as a defect because no document promised one.

---

## 6. Passwords and sessions noticed during the run

- Signing a new member up **signs that browser in as the new member**. Registering the next account
  from the same window therefore runs as the previous member, and binary placement is refused with
  `That upline is outside your subtree.` The walkthrough must register each account from a fresh
  Incognito window (v3 already implies this; the run confirms it is essential).
- Registering while signed in as **admin** hits the same rule for the same reason.
- The installer refuses `Admin@123`, so every fresh QA schema needs its own admin password.

---

## 7. What the run changed in the walkthrough (v3)

| # | Was | Is (verified) |
|---|---|---|
| 1 | Seed the queue in Part 5 | Seed in **Part 1**, before any registration; otherwise every completed level is `baseline` and never pays |
| 2 | Enable General Harvest in Part 1, arm at N = 6 | Arm in **Part 1 at N₀ = 0** with the stream **off**, then enable; arming at 6 freezes position #1 as baseline |
| 3 | Team Harvest pays Sponsor **and A** (₱2,000) | Pays **Sponsor only** (₱1,000) — the count is sponsor-lineage |
| 4 | C's activation: "three rows" | **Four** rows: 3 credited + `admin` L4 ₱50 skipped |
| 5 | D's activation: level 4 = `admin` skipped ₱50 | **Four credited** rows: … Sponsor ₱40 L4; no skip (admin is level 5, beyond depth) |
| 6 | Delete QA24 users directly | `binary_volume` must be cleared first (FK 1451); codes are not `QA24-` prefixed |
| 7 | Zero-point members placed with blank upline | Must use free slots (`Team_1a/2a/3a`); otherwise `The left slot under member #3 is already taken.` |
| 8 | Team Harvest tab at `/earnings` | Tab URL is `/earnings?ledger=team_harvest` |
| 9 | §16 totals: A ₱1,600, team ₱2,000, total ₱5,850 | A **₱600**, team **₱1,000**, total **₱4,850** |

---

## 8. Evidence files

`docs/qa/evidence/phase-24/run/`:

- `qa24-run.log`, `run-stdout.txt`, `qa24-run-results.json` — full log, console, results
- `qa24_http_run.php` — the runner (re-runnable; it resets nothing, so run it on a fresh schema)
- `qa24-07-settings-before.csv`, `qa24-08-package-before.csv` — pre-run values
- `qa24-19-queue.csv`, `qa24-20-team-awards.csv`, `qa24-24-general-awards.csv` — harvest state
- `qa24-09-commissions.csv`, `qa24-29-commissions-before-cleanup.csv` — commission ledger
- `qa24-12-from-d.csv`, `qa24-06-skipped.csv`, `qa24-18-zero-points.csv` — late-activation proofs
- `qa24-29-final-wallets.csv`, `final-state.txt` — final balances and grouped rows
- `server.log`, `server.pid` — the HTTP server used
- `install.lock.original` — restored after the QA install

---

## 9. Teardown and limitations

- The QA schema was **dropped** after the snapshot; the development database was never written to.
- Settings/package values were changed by SQL, not through the settings card; the card's validation,
  badges and "No changes to save" message are **not** covered by this run.
- No screenshots were taken: the report proves rendering only by HTTP 200 plus expected amount text.
- Proof-image access control (member 403 / guest redirect) was not exercised.
- The cleanup failure is reported, not worked around inside the app.

---

## 10. Verdict

The package-commission pipeline behaves as the corrected document describes: points stamp at
activation, the pair pays once per 1,000/1,000, the referral ladder walks to depth 4 and skips the
company root, Team Harvest pays the sponsor once, General Harvest settles positions one at a time,
zero-point activations stamp zeros, and every credited award has exactly one wallet line
(reconcile exit 0). The only step that does not complete as written is the data cleanup, which needs
the `binary_volume` rows removed first.
