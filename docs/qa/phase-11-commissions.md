# Phase 11 QA — Indirect referral commissions (levels 2–10)

| | |
|---|---|
| **Phase** | 11 — the unilevel ladder, the earnings level breakdown and the commission CSV exports |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §6.2–6.6, §6.8, §7 Phase 11 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `juan`, `maria`, `peter`, `sarah`, `david` / `Demo@1234` |
| **Checks** | 10 (QA11-01 … QA11-10) |
| **Time** | 35–45 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-11/` |

## What this phase is meant to prove

When an associate activates a package, the **whole sponsor chain** is paid: the direct sponsor at level 1
(₱500 in the seeded packages), then every eligible upline at levels 2–10 using the **source package's**
ladder (₱100, ₱50, ₱40, ₱30, ₱20, ₱10, ₱5, ₱5, ₱5). Every award is one row, one wallet credit and one
ledger row, written in the same transaction as the activation.

Six rules matter most:

1. **Source package prices the ladder.** Package B pays exactly the same amounts as Package A.
2. **No compression.** An inactive or package-less upline is *skipped* and its level is consumed; deeper
   uplines continue and keep their own level amounts.
3. **Switches.** `packages.indirect_enabled = 0` or `settings.commission_indirect_enabled = 0` pays the
   direct leg only; `commission_indirect_max_levels` caps the walk (2–10).
4. **Exactly once.** The activation stamp and the `(type, source, recipient, level)` unique key make a
   replay impossible; a skipped award is never paid later.
5. **The whole ladder is visible.** The member's Earnings page shows a level breakdown (levels 1–10) and a
   filtered "Unilevel statement" CSV; the admin ledger filters by type/level and exports its own CSV.
6. **Money still reconciles.** `php bin\wallet_reconcile.php` exits 0 after the suite.

**Already proven automatically:**

- P11-T1 ten-deep chain: levels 1–10 paid exactly per matrix (₱765 total, ten ledger rows) ·
  P11-T2 an eleventh ancestor earns nothing · P11-T3 an ineligible level 2 is skipped, levels 3–4 still
  pay · P11-T4 Package B shares the matrix · P11-T5 package/global/depth switches · P11-T6 replay pays
  nothing · P11-T7 a zero level writes no row · P11-T8 wallet credits equal the award rows ·
  P11-T9 the page renders the level breakdown and live region · P11-T10 admin type/level filters and
  export · P11-T11 the 10-hop walk stays within 20 queries · P11-T12 the workflow example (Juan ₱900,
  Peter ₱1,000, Maria ₱1,500 directs + ₱200 level-2 = ₱1,700).
- `CommissionIndirectTest` (14 cases), the updated `CommissionTest`/`CommissionViewTest`/
  `MemberEarningsLiveTest`, `MigrationTest`, `RouterTest` and `SettingsTest` — **558 cases in total**.
- The HTTP smoke script walks a real code registration under `peter`: peter ₱500, maria ₱100, juan ₱50,
  the company root skipped at level 4; it also checks the level breakdown, both CSV exports and the admin
  filter — **143 checks**.
- The headless browser pass captured the screens in `docs/qa/evidence/phase-11/`.

> **Before you start:** Phases 1–10 should be signed off, the demo tree must be seeded
> (`database/seeds/005_demo_tree.sql`) and migration `014_indirect_commissions.sql` applied. The automated
> suites must be green: `php tests\run.php` → **558 cases**;
> `powershell -NoProfile -File tests\smoke.ps1` → **143 checks**. Note juan/maria/peter's wallet balances
> before you start — the checks below move real demo money, so the last check restores them.

---

## A. The ladder

### QA11-01 — A code registration under `peter` pays three levels and skips the root

| | |
|---|---|
| **Steps** | 1. Sign in as `admin`, open **Codes**, issue one Package A registration code (₱4,498). 2. Open the register page in a private window, choose **Registration code**, sponsor `peter`, paste the code, complete the form. 3. Open **Commissions** as admin and filter **Type = Indirect referral**. 4. Check the four awards for the new member. |
| **Expected** | Four rows: level 1 peter **credited ₱500**; level 2 maria **credited ₱100**; level 3 juan **credited ₱50**; level 4 admin **skipped ₱40** (company root has no package). Peter +500, maria +100, juan +50; admin unchanged. |
| **Evidence** | `phase-11/admin-ledger.png` |

### QA11-02 — The member sees the level breakdown

| | |
|---|---|
| **Steps** | 1. Open `/earnings` as `juan`. 2. Read the **Unilevel breakdown** card. 3. Click **Export CSV**. |
| **Expected** | Level 1 shows his directs, Level 3 shows the new ₱50 award and the card totals update. The CSV downloads with one row per award (level, type, source, package, amount, status, activation, ledger). |
| **Evidence** | `phase-11/member-earnings.png` |

## B. The rules

### QA11-03 — Package B pays the same ladder as Package A

| | |
|---|---|
| **Steps** | 1. Activate a new member under a three-deep chain using Package B. 2. Compare the amounts with QA11-01. |
| **Expected** | Level 1 ₱500, level 2 ₱100, level 3 ₱50 — the package price difference buys product value, not compensation. |

### QA11-04 — An inactive upline consumes its level

| | |
|---|---|
| **Steps** | 1. Suspend (or leave pending with no package) the level-2 member of a four-deep chain. 2. Activate the bottom member with Package A. 3. Open the ledger filtered to **Skipped**. |
| **Expected** | Level 2 has a `skipped` row with its ₱100 and the reason; levels 3 (₱50) and 4 (₱40) are still credited. No money moves for the skipped level. |

### QA11-05 — Switches and depth

| | |
|---|---|
| **Steps** | 1. Deactivate Package A's **Indirect enabled** toggle, activate another member, check the awards. 2. Re-enable it, set `commission_indirect_max_levels` to 3 (SQL or the Phase 15 settings page when available), activate a five-deep chain. |
| **Expected** | Package off: direct only. Depth 3: levels 1–3 only; levels 4–5 get no row and no money. |

### QA11-06 — A replay pays nothing twice

| | |
|---|---|
| **Steps** | 1. Approve an activation once. 2. Return to the activation queue and try to approve it again (or replay the completion in the console). |
| **Expected** | The second attempt is refused; the award rows, ledger rows and wallet balances are unchanged. |

### QA11-07 — A zero level writes no row

| | |
|---|---|
| **Steps** | 1. Edit Package A so level 3 is ₱0. 2. Activate a four-deep chain. |
| **Expected** | No level-3 row; levels 1, 2 and 4 still pay their own amounts. |

## C. Reporting and safety

### QA11-08 — Admin filters and CSV export

| | |
|---|---|
| **Steps** | 1. Open **Commissions**, filter **Type = Indirect referral**, then **Level = 3**. 2. Click **Export CSV**. 3. Open the file. |
| **Expected** | The table shows only the matching rows; the CSV has the same rows with recipient, source, package, amount, status and ledger columns. A member opening the admin export gets **403**. |

### QA11-09 — A member cannot read or export another member's awards

| | |
|---|---|
| **Steps** | 1. Sign in as `maria` and open `/earnings`. 2. Try `/admin/commissions` and `/admin/commissions/export`. 3. Try `/earnings/export` as a guest. |
| **Expected** | Maria sees only her own awards and her own CSV; the admin pages return 403; the guest is redirected to login. |

### QA11-10 — Finance reconciliation and cleanup

| | |
|---|---|
| **Steps** | 1. Run `php bin\wallet_reconcile.php`. 2. Check the wallet balances against the original notes. 3. If you used the smoke/fixture scripts, run their cleanup. |
| **Expected** | Exit 0: wallets and commissions reconcile. Demo balances are back where they started. |

---

## Sign-off

| Check | Result | Evidence file |
|---|---|---|
| QA11-01 | ☐ Pass ☐ Fail ☐ Blocked | `phase-11/admin-ledger.png` |
| QA11-02 | ☐ Pass ☐ Fail ☐ Blocked | `phase-11/member-earnings.png` |
| QA11-03 | ☐ Pass ☐ Fail ☐ Blocked | `automated: P11-T4` |
| QA11-04 | ☐ Pass ☐ Fail ☐ Blocked | `phase-11/admin-ledger-skipped.png` |
| QA11-05 | ☐ Pass ☐ Fail ☐ Blocked | `automated: P11-T5` |
| QA11-06 | ☐ Pass ☐ Fail ☐ Blocked | `automated: P11-T6` |
| QA11-07 | ☐ Pass ☐ Fail ☐ Blocked | `automated: P11-T7` |
| QA11-08 | ☐ Pass ☐ Fail ☐ Blocked | `phase-11/admin-ledger-level3.png` |
| QA11-09 | ☐ Pass ☐ Fail ☐ Blocked | `automated: smoke P11-T9/T10` |
| QA11-10 | ☐ Pass ☐ Fail ☐ Blocked | `automated: wallet_reconcile` |

Tester: ____________________  Date: ____________  Signature: ____________________
