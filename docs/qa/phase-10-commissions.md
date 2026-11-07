# Phase 10 QA — Direct referral commissions

| | |
|---|---|
| **Phase** | 10 — the direct referral award, the member earnings statement and the company commission ledger |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §6.2–6.5, §6.8, §7 Phase 10 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `juan`, `maria`, `peter`, `sarah`, `david` / `Demo@1234` |
| **Checks** | 10 (QA10-01 … QA10-10) |
| **Time** | 35–45 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-10/` |

## What this phase is meant to prove

When a package activation is approved, the associate who sponsored the new member is paid the
package's **direct referral bonus** (₱500 in the seeded packages) **exactly once**, the money lands
in their e-wallet as withdrawable earnings, and both sides can see the award: the member on
**Earnings**, the company on **Commissions**.

Five rules matter most:

1. **One trigger.** Only a completed package activation pays. A free (standard) registration, a
   product order and a rejected activation pay nothing.
2. **Exactly once.** The activation is stamped when the hook runs and the `commissions` row is unique
   per (type, source member, recipient, level). Re-approving, retrying or double-clicking cannot pay
   twice.
3. **Eligibility.** The recipient must be active **with a package**. A pending, package-less or
   suspended sponsor is *skipped*: the award row records the reason and **no money moves**.
4. **One transaction.** The award row and the wallet credit are written together; a credited row
   always has its `ewallet_ledger` movement (`ref_type = commission`) and vice versa.
5. **Money still reconciles.** `php bin\wallet_reconcile.php` now checks commissions as well as the
   two wallet buckets, and exits 0.

**Already proven automatically:** P10-T1 an active sponsor is paid ₱500 through a code registration,
a manual approval and the shared completion step (wallet + ledger + award, withdrawable) · P10-T2 a
pending, package-less or inactive sponsor is skipped with a reason and no wallet movement; a member
with no sponsor writes no row · P10-T3 a package with a zero direct bonus awards nothing and does not
error · P10-T4 replaying the hook or completing the activation again pays nothing; the unique key
refuses a duplicate · P10-T5 two recruits are two awards (₱1,000) · P10-T6 wallet and commission
reconciliation are clean, and a tampered award is caught · P10-T7 member totals, filters and
pagination follow the rows · P10-T8 the admin ledger filters by member/source/type/level/status/date ·
P10-T9 a member query never returns another member's awards · P10-T10 the award links to its
activation, source member and wallet entry.

`CommissionTest` (17 cases), `CommissionViewTest` (9 cases), the extended `MigrationTest` and
`RouterTest` cover the above (**427 cases in total**). The HTTP smoke script walks a real code
registration and a manual approval that pay the sponsor, an ineligible sponsor that is skipped, the
earnings statement, the admin ledger and the finance report — **139 checks in total**.

**Already captured headlessly** (reference screenshots — still run the checks; refresh them during
your pass):

| File | Shows |
|---|---|
| `admin-ledger.png` | the commission ledger: four stat cards, filters and the awarded/skipped rows |
| `admin-ledger-skipped.png` | filtered to **Skipped**: the reason, the "not paid" amount |
| `admin-drawer.png` | the award drawer over the ledger, with activation and wallet links |
| `admin-ledger-mobile.png` | the ledger at 390 px |
| `member-earnings.png` | juan's earnings: total, this month, direct, indirect and the award table |
| `member-drawer.png` | the member drawer (no recipient row) |
| `member-earnings-mobile.png` | the statement at 390 px |

> The reference screenshots come from `tests/tmp/phase10-fixture.php` (one credited award for juan and
> one skipped award under the company root), captured by
> `node tests/tmp/verify-commissions.mjs` and removed again with
> `php tests/tmp/phase10-cleanup.php`. The cleanup restores juan's wallet exactly.

> **Before you start:** Phases 1–9 should be signed off, the demo tree must be seeded
> (`database/seeds/005_demo_tree.sql`) and the automated suites must be green:
> `php tests\run.php` → **427 cases**; `powershell -NoProfile -File tests\smoke.ps1` → **139 checks**.
> Note juan's wallet balance before you start — the checks below move real demo money, so the last
> check puts it back (or you can leave the commissions as demo data).

---

## A. Making an award

### QA10-01 — A code registration pays the sponsor immediately

| | |
|---|---|
| **Why it matters** | This is the money moment: a code is redeemed, the recruit becomes active, the sponsor is paid — inside one transaction. |
| **Where** | `/admin/codes` (issue) → `/register` (redeem) → juan's **Earnings** |
| **Evidence** | `member-earnings.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin`, open **Commerce → Codes**, issue one **Package A** code.
2. Open juan's earnings first and note **Total credited** (write it down).
3. Sign out and open `/register`; choose **Registration code**; paste the code with **sponsor `juan`**,
   fill the account fields and submit.
4. Sign in as `juan` (`Demo@1234`) and open **Membership → Earnings**.

**Expected**

- The new account is **Active** with Package A (as in QA9-02).
- juan's **Total credited** rose by **₱500.00**; **Direct referral** equals that total.
- The award row names the new member and Package A, level **Level 1 · Direct**, status **Credited**,
  amount **+₱500.00**.
- juan's **E-wallet** shows a **Commission** credit of ₱500 and its **Withdrawable** bucket rose by the
  same amount.

### QA10-02 — Approving a manual activation pays the sponsor once

| | |
|---|---|
| **Why it matters** | Approval is the admin decision that releases money; it must run the award exactly once. |
| **Where** | `/membership` (proof) → `/admin/activations` (approve) |
| **Evidence** | — |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Register a **pending** member with sponsor `juan` (`/register`, **Activate later**).
2. As that member, open **Membership** and submit a GCash proof for Package A.
3. As `admin`, open **Commerce → Activations**, press **Approve** and confirm.
4. Sign in as juan and open **Earnings**.
5. Go back to **Activations** and press **Approve** on the same (now approved) request again — reload
   and submit again if the button is gone.

**Expected**

- juan's **Total credited** rose by another **₱500.00**, with a second award row.
- The e-wallet shows one more **Commission** credit (not two).
- The second approval is refused ("already processed"/"not awaiting review") and juan's balance does
  **not** change; the activations list still shows exactly one approved row for that member.

### QA10-03 — A package with no direct bonus pays nothing

| | |
|---|---|
| **Why it matters** | The amount comes from the package, not from a hard-coded ₱500. |
| **Where** | `/admin/packages` + an activation |
| **Evidence** | — |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As admin, create **QA Package — No Bonus**: price ₱100, **Direct referral bonus ₱0**, active.
2. Register a pending member with sponsor `juan`, then activate them with that package
   (code, wallet or proof — whichever is quickest for you).
3. Check juan's **Earnings** and **Commissions** ledger.

**Expected**

- The member becomes **Active** with the QA package.
- **No** award row exists for that activation — juan's total does not change.
- The page does not show an error; the activation completes normally.

---

## B. Eligibility and the audit trail

### QA10-04 — A sponsor without a package is skipped, not paid

| | |
|---|---|
| **Why it matters** | "Active with a package" is the rule. A skipped award must be visible and must never
release money, or the plan leaks. |
| **Where** | `/register` (new standard sponsor) → `/admin/commissions` |
| **Evidence** | `admin-ledger-skipped.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign out and register a **standard** (free) account with sponsor `juan`: username
   `QA_Sponsor_10`. It is active but has **no package** — the exact ineligible case.
2. As admin, issue one Package A code.
3. Sign out and register with that code, using **`QA_Sponsor_10`** as the sponsor.
4. As `admin`, open **Finance → Commissions** and filter **Status → Skipped**.
5. Sign in as `QA_Sponsor_10` and open **Earnings**.

**Expected**

- The new member is **Active** with Package A.
- The ledger shows one **Skipped** row: recipient `@QA_Sponsor_10`, amount ₱500.00 marked **not paid**,
  the reason *"recipient has no package"*, and **no** wallet link in the drawer.
- The sponsor's **E-wallet** balance is unchanged; their **Earnings** page shows the skipped award with
  the "skipped" note and **Total credited ₱0.00**.

### QA10-05 — The company root as sponsor pays nothing

| | |
|---|---|
| **Why it matters** | The company account has no package; a blank-sponsor signup must not create company
earnings. |
| **Where** | `/register` with a blank sponsor → `/admin/commissions` |
| **Evidence** | — |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Issue another Package A code.
2. Register with it and **leave the sponsor blank** (the account joins under `admin`).
3. Open **Commissions** and filter **Skipped**; check the admin's **E-wallet** statement.

**Expected**

- The member is **Active** with Package A and sponsored by `@admin`.
- The award is **Skipped** with the reason *"recipient has no package"*.
- The admin's wallet did not receive a commission credit.

### QA10-06 — The award drawer explains the money trail

| | |
|---|---|
| **Why it matters** | Finance must be able to trace one award to its activation and to the wallet row that
paid it. |
| **Where** | `/admin/commissions` |
| **Evidence** | `admin-drawer.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open **Commissions** and press the eye button on a **Credited** row.
2. Follow **Activation** and then **Wallet entry**.

**Expected**

- The drawer shows the award id, recipient, source member, package, type, level, amount, status,
  awarded date and the reason line (hidden for a credited row).
- **Activation** opens the activation queue filtered to that member; **Wallet entry** opens that
  member's wallet page, where a **Commission** credit for the same amount is listed.
- The drawer is read-only: there is no edit or delete control anywhere.

---

## C. The two statements

### QA10-07 — The member earnings statement

| | |
|---|---|
| **Why it matters** | The member must see what they earned, from whom, and that it is withdrawable. |
| **Where** | `/earnings` as `juan` |
| **Evidence** | `member-earnings.png`, `member-earnings-mobile.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `juan`, open **Membership → Earnings**.
2. Filter by **Type → Indirect referral**, then **Status → Skipped**, then clear the filters.
3. Press the eye button on one of your awards.
4. Resize the window to a phone width (DevTools device mode, 390 px).

**Expected**

- Four cards: **Total credited**, **This month**, **Direct referral**, **Indirect referral** (₱0.00
  until Phase 11) — with **Open e-wallet** and **Genealogy** buttons.
- The table lists date, source member, package, type/level, amount, status and a details button;
  pagination with the rows-per-page control.
- The indirect filter shows the filtered empty state; **Skipped** shows only skipped rows; clearing
  restores the list.
- The drawer shows the award (no recipient row — that is admin-only) and links to the e-wallet.
- At 390 px the table keeps source, amount, status and details — no sideways scrolling of the page.

### QA10-08 — The admin commission ledger

| | |
|---|---|
| **Why it matters** | This is the company's compensation record: every award, filterable, with the totals
that matter. |
| **Where** | `/admin/commissions` |
| **Evidence** | `admin-ledger.png`, `admin-ledger-skipped.png`, `admin-ledger-mobile.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, open **Finance → Commissions**.
2. Filter by a member (`juan`), then by **Type**, **Level**, **Status** and a date range.
3. Press the eye button on a row.

**Expected**

- Four cards: **Total credited** (with the number of paid members), **This month**, **Awards** (with the
  average), **Skipped** (with the "no money moved" note).
- The table lists recipient, source (with the package), type/level, amount, status, activation,
  awarded date and the details button; credited amounts are green, skipped amounts are muted and say
  **not paid**.
- Filters narrow the list, keep their values and offer **Clear**; a filter that matches nothing shows
  the empty state.
- Pagination works with the rows-per-page control.

### QA10-09 — Members cannot see each other's awards

| | |
|---|---|
| **Why it matters** | Earnings are private; the guard is server-side, not just the menu. |
| **Where** | `/earnings`, `/admin/commissions` as a member |
| **Evidence** | — |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `maria`. Open **Earnings** — does she see juan's awards?
2. Open `/admin/commissions` as `maria` (type the URL).
3. Sign out and open `/earnings` as a guest.

**Expected**

- maria sees only her own awards (possibly none), never juan's rows or totals.
- `/admin/commissions` returns **403 Access denied** in the EcoWealth layout (no data leaks).
- A guest is redirected to `/login`.

---

## D. Invariants

### QA10-10 — The finance report is clean, and the cleanup restores the demo

| | |
|---|---|
| **Why it matters** | Every credit is a ledger row; the reconciliation must prove it, and testing must not
inflate the demo wallets. |
| **Where** | CLI from `system/`, phpMyAdmin |
| **Evidence** | `admin-ledger.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Run `php bin\wallet_reconcile.php` (and `--json` if you like).
2. Check the credited awards against their ledger movements:

   ```sql
   SELECT c.id, c.user_id, c.amount, c.status, c.ledger_id, l.amount AS ledger_amount, l.ref_type
     FROM commissions c
     LEFT JOIN ewallet_ledger l ON l.id = c.ledger_id
    ORDER BY c.id;
   ```

3. Check the two totals agree:

   ```sql
   SELECT (SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status = 'credited') AS awards,
          (SELECT COALESCE(SUM(amount),0) FROM ewallet_ledger WHERE ref_type = 'commission') AS ledger;
   ```

4. Leave the awards as demo data, or remove the ones you created with the SQL in *Cleanup* below and
   restore the balance you noted before QA10-01.

**Expected**

- The command prints **Wallets OK**, **Commissions OK** and exits **0** (the two pre-Phase-10 QA
  credits are reported as `legacy credits`, not as failures).
- Every **credited** award has a `ledger_id`, a matching ledger amount and `ref_type = commission`.
  **Skipped** rows have **no** `ledger_id`.
- The two sums are equal.
- If you cleaned up, run the command once more: still 0.

---

## Cleanup (optional)

The demo tree is **seed data** and should stay in development. The awards created by these checks are
real demo data too — keep them and let juan carry the commission, or undo them exactly:

```sql
-- Note juan's balance first, then remove the awards this pass created.
DELETE c FROM commissions c JOIN users u ON u.id = c.source_user_id WHERE u.username LIKE 'QA%';
-- (or target the recruit usernames you created)

-- The ledger rows follow only if the award was credited; delete them by ref_id, then
-- put juan's ewallet_balance / withdrawable_balance back with WalletService or a matching
-- adjustment. The safest reset is the QA fixture pair:
--   php tests/tmp/phase10-fixture.php    (creates one credited + one skipped award)
--   php tests/tmp/phase10-cleanup.php    (removes them and restores juan's wallet exactly)
```

The seed's own demo wallets (`juan` ₱2,000 / ₱1,500, `maria` ₱1,000 / ₱800, `peter` ₱500 / ₱400) are
part of `database/seeds/005_demo_tree.sql`; remove that file from a production install instead of
deleting rows by hand.

## Sign-off

| | |
|---|---|
| **Tester** | Automated QA pass — DeepSeek Harness (DSH) |
| **Date** | 2026-11-07 |
| **Result** | ☑ Pass ☐ Fail |
| **Notes** | All 10 checks passed; 427 / 427 PHP cases, 139 / 139 smoke checks and 20 / 20 browser checks green. |
