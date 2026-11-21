# Plan — Repeat-purchase Bonus Escrow (A++)

**Escrow + combined “total funds” presentation + payout-page escrow reminder**

| | |
|---|---|
| **Status** | **Implemented** — see `docs/phase-log.md` §Phase 18 and `docs/qa-unilevel-product.md` v2 |
| **Date** | 14 November 2026 |
| **Builds on** | Phase 17 repeat-purchase bonus (released; `p17g-release`) |
| **Related QA** | [`docs/qa-unilevel-product.md`](../docs/qa-unilevel-product.md), especially E4 |
| **Related plan** | [`dmp/plan-ecowealth-unilevel-repeat-purchase.md`](plan-ecowealth-unilevel-repeat-purchase.md) |
| **Target** | `http://localhost/ecowealth/system/` |
| **Delivery** | One feature, three layers; five work packages: (1) escrow foundation, (2) release + cancellation, (3) A++ member presentation, (4) admin/reporting, (5) docs/QA |

---

## Contents

**Part I — Orientation**

1. [Executive summary](#1-executive-summary)
2. [The problem being fixed](#2-the-problem-being-fixed)
3. [The decision: A++](#3-the-decision-a)
4. [Goals and non-goals](#4-goals-and-non-goals)
5. [Vocabulary and states](#5-vocabulary-and-states)

**Part II — Specification**

6. [The award lifecycle](#6-the-award-lifecycle)
7. [The release rule](#7-the-release-rule)
8. [The cancellation rule](#8-the-cancellation-rule)
9. [Settings](#9-settings)
10. [A++ member presentation](#10-a-member-presentation)
11. [The payout-page escrow reminder](#11-the-payout-page-escrow-reminder)
12. [Grandfathering existing awards](#12-grandfathering-existing-awards)
13. [Money and ledger invariants](#13-money-and-ledger-invariants)

**Part III — Implementation**

14. [Schema and migration `021`](#14-schema-and-migration-021)
15. [Model API — `UnilevelAward`](#15-model-api--unilevelaward)
16. [Service API — `UnilevelService`](#16-service-api--unilevelservice)
17. [The release job](#17-the-release-job)
18. [Member controllers and views](#18-member-controllers-and-views)
19. [Admin controllers and views](#19-admin-controllers-and-views)
20. [Reporting and reconciliation](#20-reporting-and-reconciliation)
21. [Live updates and cache surfaces](#21-live-updates-and-cache-surfaces)
22. [File change map](#22-file-change-map)
23. [Test plan](#23-test-plan)
24. [Documentation and QA plan](#24-documentation-and-qa-plan)
25. [Rollout and rollback](#25-rollout-and-rollback)
26. [Risks, edge cases and mitigations](#26-risks-edge-cases-and-mitigations)
27. [Acceptance criteria](#27-acceptance-criteria)
28. [Work breakdown and estimate](#28-work-breakdown-and-estimate)
29. [Open decisions](#29-open-decisions)

**Appendices**

- [Appendix A — Migration SQL sketch](#appendix-a--migration-sql-sketch)
- [Appendix B — Release algorithm](#appendix-b--release-algorithm)
- [Appendix C — Exact copy strings](#appendix-c--exact-copy-strings)
- [Appendix D — State matrix](#appendix-d--state-matrix)
- [Appendix E — Test case list](#appendix-e--test-case-list)
- [Appendix F — QA scenarios](#appendix-f--qa-scenarios)
- [Appendix G — File map](#appendix-g--file-map)
- [Appendix H — Glossary](#appendix-h--glossary)
- [Part V — Bounded execution slices and Phase 17 integration](#part-v--bounded-execution-slices-and-phase-17-integration)
- [Appendix I — Cross-plan contract and hand-off](#appendix-i--cross-plan-contract-and-hand-off)

---

# Part I — Orientation

## 1. Executive summary

The Phase 17 repeat-purchase bonus is credited to the upline's **withdrawable** wallet at the moment the order is paid. That creates a hole in the cancellation path:

1. The upline requests a payout. The funds are debited from the wallet **at request time**, before approval.
2. The buyer cancels the order (or later returns it).
3. The reversal can only take what is still in the withdrawable bucket. If the payout took it, the award is voided with a **shortfall**.
4. If the payout is subsequently rejected, the hold is refunded to the upline — so the upline ends up with the money while the award is voided and the company records an unrecovered shortfall.

The fix is **not** to make the bonus non-withdrawable inside the existing two-bucket wallet. In this build, non-withdrawable money is **spendable** (shop, transfers, registrations), and a later “release” would have no way to distinguish the bonus from top-up money. A true hold needs the bonus to be outside the spendable wallet until it is final.

**A++ ships three things together:**

1. **Escrow** — the award is created as `pending` at payment. No `ewallet_ledger` row and no wallet movement exist yet. A release job credits the wallet, as withdrawable, only after the order is delivered and the return window has closed.
2. **Combined “total funds” presentation** — the upline sees `Wallet balance`, `Withdrawable`, `Pending release`, and a combined `Total funds` figure on the wallet, dashboard and payout pages. The pending amount is visible and motivating, not hidden; it is simply not spendable or withdrawable yet.
3. **Payout-page escrow reminder** — before, during and after a payout request, the member is told exactly how much is pending release, why it is not included, and when it will become available. The over-limit refusal also names the pending amount, so the gap is explained at the moment it matters.

The award is still **earned at payment** (the monthly close, the Active snapshot and the order total stay in the payment month). Only the **cash event** moves to release. This keeps the accounting month correct and removes the payout race for new awards.

The change does **not** add a third wallet bucket. `ewallet_balance` remains the spendable total; the pending amount lives in `unilevel_awards` and is projected into the UI. That keeps checkout, transfers and payouts honest without a wallet-wide refactor.

---

## 2. The problem being fixed

### 2.1 Current mechanics

| Step | Code | Effect |
|---|---|---|
| Order paid | `UnilevelService::onOrderPaid()` | Award created as `credited`; `WalletService::credit(..., withdrawable: true)` |
| Payout requested | `PayoutService::request()` | `wallet->debit(..., 'payout', ...)` removes the amount from both buckets immediately |
| Order cancelled | `PaymentService::cancelByAdmin()` → `UnilevelService::reverseForOrder()` | Award voided; debit limited to `min(award.amount, withdrawable)` |
| Payout rejected later | `PayoutService::decide()` | `wallet->refund(..., withdrawablePortion: amount)` restores the full amount to withdrawable |

### 2.2 The double-loss case

Take a ₱12.50 award:

- Upline wallet: +₱12.50 withdrawable.
- Upline requests a payout that includes it: wallet −₱12.50; withdrawable 0.
- Order cancelled: reversal takes 0; award `voided`; `reversed_amount = 0`; `Unrecovered = ₱12.50`.
- Payout rejected: refund +₱12.50 to withdrawable. The upline now has the cash, the award is voided, and the company has a shortfall.
- Payout completed instead: the upline has the cash off-platform; same shortfall.

The existing documentation calls the shortfall “documented behaviour” (`D-13`). It is honest, but it is still a business loss. The goal of this plan is to make that loss impossible for new awards, not to document it better.

### 2.3 Why the cancellation window matters

Administrators can cancel only through `ready_to_ship` (`Order::ADMIN_CANCELLABLE`). After shipping there is no cancellation route. Returns and refunds are explicitly deferred. So the current dangerous window is **paid → shipped**, and a payout can be requested inside it.

A future returns/refunds module would extend the dangerous window beyond delivery. The escrow model handles that too: as long as the award is still `pending`, a return cancels it without a wallet debit.

### 2.4 Why “credit as non-withdrawable” is not enough

In this wallet, `non-withdrawable` means **spendable only**:

- `WalletService::debitInternal()` spends non-withdrawable money first, then withdrawable.
- A member can spend the bonus in the shop, on a transfer, on a registration or on an activation fee before the order is cancelled.
- If the order is then cancelled, the reversal still cannot recover what was spent; the shortfall remains.
- If the bonus were later released, the two buckets are fungible: the wallet cannot tell whether the remaining non-withdrawable money is the bonus or an admin top-up. Releasing from the non-withdrawable bucket could convert top-up money into withdrawable cash.

A true hold therefore has to make the bonus **non-spendable and non-withdrawable** until release. That is what escrow does.

---

## 3. The decision: A++

### 3.1 The three layers

| Layer | What it does | Why |
|---|---|---|
| **A — Escrow** | `pending` award at payment; no wallet credit; release job credits withdrawable after delivery + hold | Removes the payout/spend race and the shortfall |
| **A+ — Combined total** | `Total funds = wallet balance + pending release`; pending shown on wallet, dashboard, earnings and order pages | Keeps the upline motivated; the earnings are visible |
| **A++ — Payout reminder** | Payout page shows pending, explains it is not included, and names it in the over-limit refusal | Answers “where is the rest of my money?” at the exact moment it is asked |

### 3.2 Decision record

| ID | Decision | Rationale |
|---|---|---|
| **ESC-01** | Escrow outside the wallet; **no third wallet bucket** | Keeps `ewallet_balance` spendable and honest; avoids a wallet-wide refactor |
| **ESC-02** | The award is created at **payment**; the wallet credit happens at **release** | The earning month, Active snapshot and order total stay correct |
| **ESC-03** | Release trigger: order `delivered` or `completed`, and `COALESCE(delivered_at, completed_at) + hold_days <= NOW()` | The return window starts at delivery, not at payment or shipment |
| **ESC-04** | `hold_days` is snapshotted on each award at payment | A later setting change cannot move an award’s promised date |
| **ESC-05** | `unilevel_hold_days` default **7**, range 0–365 | A one-week return window; 0 is allowed for no-hold businesses |
| **ESC-06** | Pending awards are **always** cancelled on order cancellation, even if `unilevel_reversal_enabled` is off | A cancelled order must never release a bonus later |
| **ESC-07** | `unilevel_reversal_enabled` only governs awards already **credited** | The switch cannot be allowed to leave a cancelled order payable |
| **ESC-08** | `shop_orders.unilevel_total` remains the **awarded** total (pending + credited), not the released total | Preserves the order card’s “this order earned ₱X” meaning and the existing reconcile shape |
| **ESC-09** | Pending is **not spendable** and **not withdrawable** | Otherwise the shortfall returns |
| **ESC-10** | The payout reminder is **informational**, not a blocker; it does not require a checkbox or modal | It explains, but the only limit is the withdrawable balance |
| **ESC-11** | Existing `credited` awards are **grandfathered**; no retroactive lock | Avoids clawing back money that is already withdrawable |
| **ESC-12** | The release job has its own switch, `unilevel_release_enabled`; the global master does not strand earned pending awards | Operators can pause releases without losing the obligation |
| **ESC-13** | The release job runs **hourly** (or every 15 minutes), one transaction per order | Day-granular windows; cheap and idempotent |

### 3.3 Rejected alternatives

| Alternative | Why rejected |
|---|---|
| **Third wallet bucket** (`pending_balance` inside `ewallet_balance`) | The product goal can be met with a combined total and a reminder without changing the wallet invariant, checkout, transfers, payouts, reports and every wallet test. B remains a separate wallet-platform option if the business later requires pending inside the main wallet total. |
| **Literal non-withdrawable credit** | Leaves the spend path open; the shortfall remains; release cannot distinguish bonus from top-up money. |
| **Payout-only lock** (reduce payout max by unvested bonus) | `withdrawable_balance` would no longer mean what every page says; spending can still consume the bonus; more confusing than escrow. |
| **Block cancellation when the bonus was withdrawn** | Blocks an operational necessity and still leaves completed payouts unrecoverable. |
| **Refuse payouts while any bonus is pending** | Punishes unrelated earnings and creates support friction. |

---

## 4. Goals and non-goals

### 4.1 Goals

1. A cancelled order can never pay a repeat-purchase bonus that was not already final.
2. A payout request can never include an unvested repeat-purchase bonus.
3. The upline can see the pending amount, the reason it is held, and the expected release date.
4. The wallet total remains spendable; checkout, transfers and payouts keep their current meaning.
5. The order’s awarded total, the monthly close and the agent’s Active snapshot remain in the payment month.
6. The change is auditable from the award row, the admin ledger and the member statement.
7. The implementation is safe to roll out in two stages and can be rolled back without stranding pending awards.

### 4.2 Non-goals

- A third wallet bucket or a general “held balance”.
- A returns/refunds module. The escrow model prepares for it; it does not build it.
- Retroactive locking of awards already credited.
- Letting pending money be spent before release.
- Changing the commission, transfer, activation or registration money rules.
- Making the payout reminder a blocking confirmation.
- A manual “release now” admin button in the first pass (optional follow-up; see §26).

---

## 5. Vocabulary and states

| Term | Meaning |
|---|---|
| **Award** | One `unilevel_awards` row: one order line × one recipient × one level |
| **Pending release** | The award is earned and recorded, but no wallet credit exists yet |
| **Released / credited** | The wallet has been credited and the award is final |
| **Cancelled** | The order was cancelled before release; the award will never pay |
| **Voided** | The award was released and later reversed (legacy or a future return) |
| **Skipped** | The award was due but the recipient was ineligible; never money |
| **Hold days** | Days after delivery before a pending award becomes releasable |
| **Release** | The act of crediting a pending award to the wallet as withdrawable |
| **Total funds** | `ewallet_balance + pending repeat-purchase total` — a presentation figure, not a wallet column |

### 5.1 State machine

```text
                       order paid
                           │
                           ▼
                       ┌───────┐
                       │pending│  (no wallet ledger row)
                       └───┬───┘
             cancelled      │      delivered/completed
        before release      │      + hold_days elapsed
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
        ┌───────────┐              ┌──────────┐
        │ cancelled │              │ credited │  (wallet credit, withdrawable)
        └───────────┘              └────┬─────┘
                                        │ post-release return
                                        ▼
                                   ┌────────┐
                                   │ voided │  (reversal debit; possible shortfall)
                                   └────────┘

        skipped: due but ineligible; never enters the money path
```

### 5.2 Status ownership

| Status | Created by | Wallet ledger row | Releasable | Counts as awarded | Counts as pending | Counts as released | Shortfall possible |
|---|---|---:|---:|---:|---:|---:|---:|
| `pending` | `onOrderPaid` | No | Yes | Yes | Yes | No | No |
| `credited` | release job | `unilevel` credit | No | Yes | No | Yes | No |
| `cancelled` | cancellation | No | No | No | No | No | No |
| `voided` | post-release reversal | `unilevel_reversal` debit | No | No | No | No | Yes |
| `skipped` | `onOrderPaid` | No | No | No | No | No | No |

“Awarded” means `pending + credited`; this is what `shop_orders.unilevel_total` tracks.

---

# Part II — Specification

## 6. The award lifecycle

### 6.1 At payment

`UnilevelService::onOrderPaid()` keeps its current eligibility, chain walk, base, rate and rounding rules. The only changes are:

1. Every eligible draft is inserted with `status = 'pending'`.
2. The draft carries `hold_days = setting_int('unilevel_hold_days', 7)`.
3. **No** `WalletService::credit()` call and **no** `UnilevelAward::linkLedger()` call.
4. The order is stamped with `unilevel_processed_at = NOW()` and `unilevel_total = sum(pending amounts)`.
5. Skipped rows are unchanged.
6. The order is still idempotent: the stamp, the per-item pre-check, the unique key and the existing row all prevent a second set of awards.
7. Live surfaces that show pending totals are touched: earnings, order, admin commissions; additionally dashboard and membership because their pending figures change.

The award row’s `paid_at` remains the order’s `paid_at`. `created_at` remains the row creation time. `credited_at` stays `NULL`.

### 6.2 At release

The release job (or a targeted service call) moves a `pending` award to `credited`:

1. Lock the order.
2. Re-check order status is `delivered` or `completed` and `cancelled_at IS NULL`.
3. Resolve `base = delivered_at ?? completed_at`. If both are null, skip and log.
4. Lock the order’s `pending` awards.
5. For each award whose `base + hold_days <= NOW()` (or all awards when `force`):
   - `WalletService::credit(user_id, amount, 'unilevel', award_id, note, withdrawable: true)`
   - `UnilevelAward::markCredited(award_id, ledger_id)`
6. Write one `unilevel.release` audit row for the order.
7. Touch the earnings, wallet, order, admin commissions and dashboard surfaces.

The credit is the same wallet movement the current code writes at payment; it simply happens later. The wallet idempotency key `(user_id, ref_type, ref_id, type)` is the final guard against a double credit.

### 6.3 At cancellation before release

`UnilevelService::reverseForOrder()`:

1. Always select the order’s `pending` awards `FOR UPDATE`.
2. Set each to `status = 'cancelled'`, `voided_at = NOW()`, `void_reason = ...`, `reversed_amount = 0`.
3. **No** wallet debit and **no** `unilevel_reversal` row.
4. Then, only if `unilevel_reversal_enabled`, select `credited` awards and run the existing best-effort reversal.
5. Recompute `shop_orders.unilevel_total` as `sum(pending + credited)` — after the cancellation this is normally 0.
6. Audit: `unilevel.reversal` with `pending_cancelled`, `credited_voided`, `recovered`, `shortfall`.
7. Touch earnings, order, admin commissions/dashboard, dashboard and membership for every affected recipient.

The early return on `unilevel_reversal_enabled` must be removed from the pending path. A disabled reversal switch must never leave a cancelled order’s pending awards releasable.

### 6.4 At cancellation after release

The existing reversal path remains:

- Select `credited` awards; take `min(amount, withdrawable)`; debit with `unilevel_reversal`; void the award; record `reversed_amount` and shortfall.
- This path should be unreachable for new orders in the current lifecycle (cancellation stops at `ready_to_ship`; release starts at `delivered`), but it remains for legacy awards and future returns.

### 6.5 At release after cancellation

The release service re-checks the order status and the award status under the order lock. A cancelled order has no `pending` awards (they were cancelled), and a cancelled award cannot be marked credited. If a race occurs:

- Cancellation locks the order first; release waits, then sees `cancelled`, writes nothing.
- Release locks the order first; it credits the awards and marks them `credited`; cancellation then takes the credited reversal path.
- Both paths lock the order first, so the two cannot interleave.

---

## 7. The release rule

### 7.1 Trigger

An award is releasable when **all** of the following hold:

1. `unilevel_awards.status = 'pending'`.
2. The order is `delivered` or `completed`.
3. The order is not cancelled (`cancelled_at IS NULL`).
4. `COALESCE(shop_orders.delivered_at, shop_orders.completed_at) IS NOT NULL`.
5. `DATE_ADD(COALESCE(delivered_at, completed_at), INTERVAL award.hold_days DAY) <= NOW()`.

Why delivery, not payment:

- A return window normally starts when the buyer receives the product.
- A payment-based window could expire before the parcel arrives.
- A completion-based window would wait for the member to confirm receipt (or the auto-complete job), which is not the return policy.
- Delivery is already stamped by `FulfilmentService` and is reliable for every shipped order.

Why `hold_days` is snapshotted:

- The award is a promise; a later setting change should not silently move its date.
- The system already snapshots payment deadlines on orders (“windows are snapshots”).
- Changing the global value affects new awards only.

### 7.2 Defaults

| Setting | Default | Range | Meaning |
|---|---:|---:|---|
| `unilevel_hold_days` | 7 | 0–365 | Days after delivery before release. 0 releases at delivery. |
| `unilevel_release_enabled` | 1 | 0/1 | Off pauses the release job. Pending stays pending. |

### 7.3 What release does not do

- It does not re-check the recipient’s eligibility. Eligibility was decided at payment; the award is an obligation.
- It does not re-check the product ladder. The rate was frozen at payment.
- It does not change `shop_orders.unilevel_total`. The order was already stamped with the awarded total.
- It does not create a new `unilevel_awards` row. It changes `pending` to `credited`.
- It does not move money for `skipped`, `cancelled` or `voided` rows.

### 7.4 Stuck and undelivered orders

If an order is never delivered, its pending awards stay pending forever. That is the conservative, correct behaviour under a delivery-based return policy. The release job’s `--doctor` and the admin repeat ledger must make this visible:

- pending count and amount;
- oldest pending award age;
- pending awards on `shipped` orders older than a threshold;
- pending awards due but not released (should be zero after a successful run).

No automatic release for undelivered orders. A manual release / support path is a follow-up decision (§29).

---

## 8. The cancellation rule

### 8.1 The hard rule

> **A cancelled order must never have a `pending` award left behind.**

This is enforced by cancelling pending rows **before** any reversal-switch check. The switch is only about recovering money that was already paid out.

### 8.2 Why the switch must not gate pending cancellation

The current code returns early when `unilevel_reversal_enabled = 0`. If that early return remains, a cancelled order’s pending awards would stay `pending` and the release job would later pay them. That is a direct money bug. The new order of operations is:

```php
// 1. Always cancel pending awards. No money has moved.
$this->cancelPendingForOrder($orderId, $reason);

// 2. Only reverse credited awards when the operator allows it.
if (!setting_bool('unilevel_reversal_enabled', true)) {
    return $result + ['disabled' => true];
}

$this->reverseCreditedForOrder($orderId, $reason);
```

### 8.3 What the member sees

- No wallet statement row is created by the cancellation (there was no wallet credit).
- The earnings page row changes to **Cancelled** with the reason “Order cancelled before release — no money was paid.”
- The admin ledger row changes to **Cancelled** with no recovered/shortfall figures.
- The admin order card keeps the rows visible for audit, even though the order is no longer paid.

### 8.4 What the buyer sees

The buyer’s order page shows `Cancelled` and the normal refund result. The buyer never sees the upline’s pending bonus; that is admin-only.

---

## 9. Settings

### 9.1 New keys

| Key | Default | Validation | Operator label | Hint |
|---|---:|---|---|---|
| `unilevel_hold_days` | `7` | `required|integer|gte:0|lte:365` | **Release delay after delivery** | “Days after delivery before a repeat-purchase bonus becomes withdrawable. 0 releases at delivery. Applies to new awards only; each award keeps the value it was given at payment.” |
| `unilevel_release_enabled` | `1` | `required|in:0,1` | **Release repeat-purchase bonuses** | “Off pauses the release job; pending bonuses stay pending. Cancelling an order still cancels its pending awards.” |

### 9.2 Changed hints

- `unilevel_enabled` (global master): “Off hides the offer and stops new awards. Bonuses already pending still release unless **Release repeat-purchase bonuses** is also off.”
- `unilevel_reversal_enabled`: “On: cancelling an order after its bonus was released claws the bonus back. Off: a released bonus stays. Pending bonuses are always cancelled.”

### 9.3 Where they appear

- Admin → Settings → Commissions → Repeat-purchase bonus.
- `AdminSettingsController::REPEAT_FIELDS` gains the hold-days field.
- `AdminSettingsController::REPEAT_TOGGLES` gains the release toggle.
- The `commissions` section validation, save and audit path includes both.
- The settings page’s live fingerprint/config revision must change when either value changes; a `Live::touchSettings()` already covers the card.

### 9.4 Counts

- 54 → **56** settings.
- 31 tables (no new table).
- Four → **five** cron jobs.

---

## 10. A++ member presentation

### 10.1 The wallet page

Add **Pending release** and **Total funds** to the wallet header/cards:

```text
Wallet balance        ₱1,232.50     (spendable; ewallet_balance)
Withdrawable          ₱1,032.50     (cashable)
Pending release          ₱12.50     (repeat-purchase escrow)
Total funds           ₱1,245.00     (wallet balance + pending release)
```

Rules:

- `Wallet balance` stays `ewallet_balance`.
- `Withdrawable` stays `withdrawable_balance`.
- `Pending release` is `SUM(unilevel_awards.amount) WHERE status='pending'`.
- `Total funds` is a **presentation-only** sum; it is not a wallet column and is not spendable.
- If pending is 0, hide the pending and total-funds lines (do not add noise).
- The pending line links to `/earnings?ledger=repeat`.

### 10.2 The dashboard

The earnings card gains a repeat-purchase line:

```text
Repeat-purchase earnings
  Credited        ₱25.00
  Pending release ₱12.50
  Total           ₱37.50
```

The wallet KPI/card shows `Withdrawable` and a smaller `₱12.50 pending release` line. Do not replace the existing balance/withdrawable figures.

### 10.3 The earnings → repeat-purchase tab

- Add a **Pending release** tile (amount + count).
- Add a **Next release** line when a date exists.
- Rows show:
  - `pending` → badge **Pending release**; note “available after <date>” or “after delivery + N days”;
  - `credited` → badge **Credited**; note the release date;
  - `cancelled` → badge **Cancelled**; note “order cancelled before release — no money was paid”;
  - `voided` → existing recovered/shortfall note;
  - `skipped` → existing reason.
- The “Total credited” tile remains released money. The “Awards” count may show released + pending; label it clearly if changed.
- The level breakdown card adds a pending column/line per level.

### 10.4 The order pages

- Buyer order card: “₱25.00 is reserved for the members above you. It is released to their e-wallets after the return window (estimated <date>).” If already released: “was released”.
- Admin order card:
  - paid + pending: badge **₱25.00 pending release**; rows `Pending release`; no wallet link; due date.
  - paid + released: badge **₱25.00 released**; rows `Credited`; wallet link.
  - cancelled before release: badge **No bonus released**; rows `Cancelled before release`; no wallet link; note “No upline wallet was credited.”
  - paid + mixed (legacy): show both totals.
- The card footer keeps the processed date and adds the release date(s).

### 10.5 The membership page

The repeat-purchase paragraph shows `Credited + Pending release` as “earned from your Team chain”, with a short line: “Pending amounts become withdrawable after the return window.” Do not change the two-clock Active explanation.

### 10.6 Product and checkout copy

Replace “credited to the upline’s e-wallet when the order is paid” with:

> “Earned by the members above you when this order is paid. It is released to their e-wallets after the return window.”

The checkout preview may print the hold days if configured; otherwise say “after the return window.” Do not promise an exact date before delivery.

---

## 11. The payout-page escrow reminder

This is the A++ layer that makes the escrow understandable at the exact moment the member expects money.

### 11.1 The balance card

Add a line when pending > 0:

```text
Withdrawable        ₱1,032.50
Pending release       ₱12.50     not withdrawable yet
Wallet balance      ₱1,232.50
Non-withdrawable      ₱200.00
```

If `max_amount` is 0 because everything is pending, say:

> “You have ₱12.50 pending release, but none is withdrawable yet. It becomes available after the return window.”

### 11.2 The request form

Above the amount field, when pending > 0:

> “**₱12.50** of repeat-purchase bonus is **pending release** and is **not included** in this request. It will be released after the return window (next: **21 Nov 2026**).”

Variants:

- No delivery date yet: “…after delivery + **7 days**.”
- Release paused: “…release is currently paused by the administrator.”
- Some pending already due: “…being finalized; it will be added on the next release run.”

Do not use a modal and do not require a checkbox. The member can request up to the withdrawable balance.

### 11.3 The over-limit refusal

When the amount is greater than `withdrawable`, the refusal must say:

> “You can withdraw up to **₱1,032.50**. Your **₱12.50 repeat-purchase bonus** is pending release and is not yet withdrawable.”

Implementation:

- `PayoutService::request()` already holds the account lock and has `$locked['withdrawable_balance']`.
- When `$amount > $withdrawable`, fetch the pending summary and call a new `PayoutException::aboveWithdrawable($amount, $withdrawable, $pendingTotal, $nextRelease)`.
- Keep the `reason` code `withdrawable`; add the pending figures to the message only.

### 11.4 The success flash

After a successful request, `PayoutController::successMessage()` appends:

> “Your **₱12.50** pending release was not part of this request; it will be released after the return window.”

`PayoutService::request()` can return the pending summary it already needs, so the controller does not re-query.

### 11.5 The open-request card and history

- Open request card: “Your open request holds **₱A**. **₱B** pending release is separate.”
- History footer/header: “Pending release is never included in a payout request; it is added to your withdrawable balance only after release.”

### 11.6 The admin queue (optional, recommended)

In the admin payout detail/drawer, show:

> “Member has **₱B** pending release (not part of this request).”

This is a support aid, not a decision input. It can be a subquery for the opened member only; do not add an N+1 query to the whole queue.

---

## 12. Grandfathering existing awards

- Existing `credited` awards remain `credited` and withdrawable.
- Existing `voided` awards remain `voided`.
- Existing `skipped` awards remain `skipped`.
- The migration backfills `credited_at = created_at` for `credited` and `voided` rows so the UI has a release date.
- `hold_days = 0` for existing rows.
- No pending rows are created for orders paid before the migration.
- The order’s `unilevel_total` for existing orders already equals the credited total; the new reconcile (`pending + credited`) agrees because pending is 0.
- The monthly close for past months already used credited; those months stay immutable. Future closes include pending + credited.

This is the same “no backfill” policy as Phase 17 (`D-16`): the feature pays only events after the change.

---

## 13. Money and ledger invariants

### 13.1 The wallet is unchanged

A++ does **not** change:

- `users.ewallet_balance`
- `users.withdrawable_balance`
- `ewallet_ledger` columns, types or ref types
- `WalletService` credit/debit/refund methods
- the invariant `ewallet_balance >= withdrawable_balance >= 0`
- checkout, transfers, payout rules and wallet reconciliation

The only wallet movement added by this plan is the existing `unilevel` credit, written at release instead of at payment.

### 13.2 The award ledger is the escrow ledger

`unilevel_awards` is the source of truth for pending money:

- `status='pending'` → earned, not in wallet.
- `status='credited'` → wallet credited, `ledger_id` set, `credited_at` set.
- `status='cancelled'` → never paid, no ledger row.
- `status='voided'` → was paid, then reversed; `reversal_ledger_id` and `reversed_amount` set.
- `status='skipped'` → ineligible, no money.

`SUM(amount) WHERE status='pending'` is the pending figure. `SUM(amount) WHERE status='credited'` is the released figure. `SUM(amount) WHERE status IN ('pending','credited')` is the awarded figure used by `shop_orders.unilevel_total`.

### 13.3 Reconciliation equation

For the repeat-purchase stream:

```text
ever_awarded    = SUM(amount) for status IN ('pending','credited','cancelled','voided')
ever_credited   = SUM(amount) for status IN ('credited','voided')       // these have a wallet credit
ever_released   = SUM(amount) for status = 'credited'                   // still paid
ever_reversed   = SUM(reversed_amount) for status IN ('cancelled','voided')
wallet_credits  = SUM(ewallet_ledger.amount) WHERE ref_type='unilevel' AND type='credit'
wallet_reversals= SUM(ewallet_ledger.amount) WHERE ref_type='unilevel_reversal' AND type='debit'
```

Checks:

```text
wallet_credits   == ever_credited
wallet_reversals == ever_reversed
order.unilevel_total == SUM(award.amount WHERE status IN ('pending','credited'))
```

`cancelled` rows have no wallet credit and no reversal, so they must not appear in either ledger total.

### 13.4 The wallet statement

A pending award creates **no** wallet statement row. The member sees it on the earnings page and the pending card. At release, the wallet statement shows the normal:

> **Repeat-purchase bonus** — Repeat-purchase bonus — QA Repeat Product (level 1) — order EW… — **+₱12.50**

There is no “release” row type and no new wallet ref type. This is the main difference from a third-bucket design, and the reason the implementation is smaller.

---

# Part III — Implementation

## 14. Schema and migration `021`

### 14.1 Migration

New file: `system/database/migrations/021_unilevel_bonus_escrow.sql`.

Additions to `unilevel_awards`:

| Column | Type | Null | Default | Purpose |
|---|---|---|---:|---|
| `hold_days` | `SMALLINT UNSIGNED` | No | `0` | Days after delivery for this award |
| `credited_at` | `DATETIME` | Yes | `NULL` | When the wallet was credited |

Alter the status enum:

```sql
ENUM('pending','credited','skipped','voided','cancelled') NOT NULL DEFAULT 'pending'
```

Indexes:

```sql
KEY idx_unilevel_user_status  (user_id, status)
KEY idx_unilevel_order_status (order_id, status)
```

Backfill:

```sql
UPDATE unilevel_awards
   SET credited_at = created_at
 WHERE credited_at IS NULL
   AND status IN ('credited','voided');
```

Settings:

```sql
INSERT INTO settings (key_name, value) VALUES
  ('unilevel_hold_days',       '7'),
  ('unilevel_release_enabled', '1')
ON DUPLICATE KEY UPDATE key_name = key_name;
```

No new table. No change to `ewallet_ledger`, `users`, `shop_orders`, `payout_requests` or any other table.

### 14.2 Idempotence

Follow `020_unilevel_repeat_purchase.sql`: each `ADD COLUMN`, index and enum change is guarded by `information_schema` checks, so a second run is a no-op. The migration must be safe to run on a database that is already at `020`.

### 14.3 Fresh-install mirror

Update `system/database/schema.sql` with the same status enum, columns, indexes and settings. Regenerate `system/database/install.sql` with:

```powershell
php system/bin/build_install_sql.php
php system/bin/build_install_sql.php --check
```

Update:

- `DatabaseInstaller::EXPECTED['settings_rows']` 54 → **56**.
- Any test that asserts 31 tables / 54 settings → **31 / 56**.
- `docs/deploy.md`, `docs/runbook.md`, `docs/install-reset.md` counts.

### 14.4 Existing rows

- `status='credited'` → unchanged; `credited_at` backfilled.
- `status='voided'` → unchanged; `credited_at` backfilled (it was paid before reversal).
- `status='skipped'` → unchanged; `credited_at` null.
- `hold_days=0` for all existing rows.
- No new pending rows.

---

## 15. Model API — `UnilevelAward`

File: `system/app/Models/UnilevelAward.php`.

### 15.1 Constants and labels

```php
public const STATUSES = ['pending', 'credited', 'skipped', 'voided', 'cancelled'];

public const STATUS_LABELS = [
    'pending'   => 'Pending release',
    'credited'  => 'Credited',
    'skipped'   => 'Skipped',
    'voided'    => 'Voided',
    'cancelled' => 'Cancelled',
];
```

`normaliseFilters()` already validates against `STATUSES`, so `?status=pending` and `?status=cancelled` work without further changes.

### 15.2 `create()`

- Default `status` becomes `'pending'`.
- Accept `hold_days` (0–365, clamp).
- Insert `hold_days`.
- Do **not** set `credited_at`; it stays null.
- The existing validation for positive amount, level, base and rate is unchanged.

### 15.3 New write methods

```php
/** Point a pending award at the wallet movement that released it. */
public static function markCredited(int $awardId, int $ledgerId, ?PDO $pdo = null): bool
```

```sql
UPDATE unilevel_awards
   SET status = 'credited', ledger_id = ?, credited_at = NOW()
 WHERE id = ? AND status = 'pending'
```

Returns `rowCount() === 1`. The caller owns the transaction.

```php
/** Cancel a pending award before any money moved. */
public static function cancel(int $awardId, string $reason, ?PDO $pdo = null): bool
```

```sql
UPDATE unilevel_awards
   SET status = 'cancelled', voided_at = NOW(), void_reason = ?
 WHERE id = ? AND status = 'pending'
```

`reversed_amount` stays `0`; `reversal_ledger_id` stays null.

`void()` remains for `credited → voided` only. Its guard is already `WHERE id = ? AND status = 'credited'`.

### 15.4 `recomputeOrderTotal()`

Change the sum from `status='credited'` to `status IN ('pending','credited')`:

```sql
SELECT COALESCE(SUM(amount), 0)
  FROM unilevel_awards
 WHERE order_id = ? AND status IN ('pending','credited')
```

The result is the **awarded** total, matching `shop_orders.unilevel_total`. After a full cancellation it becomes 0.

### 15.5 `select()`

Add the fields the A++ views need:

```sql
a.hold_days,
a.credited_at,
o.delivered_at,
o.completed_at
```

`a.*` already includes `hold_days` and `credited_at`; the order dates are the new part.

### 15.6 `totalsForUser()`

Add:

| Key | Meaning |
|---|---|
| `pending` | Sum of pending amounts |
| `pending_count` | Count of pending rows |
| `cancelled` | Count of cancelled rows (optional; no money) |
| `next_release_at` | Earliest release date for pending awards on delivered orders; null if none/undelivered |
| `total_awarded` | `credited + pending` (presentation figure) |

Keep `credited`, `count`, `this_month`, `skipped`, `last_at` for existing callers. The “This month” figure remains **credited this month** (released money); if the UI needs “earned this month”, add `earned_this_month` based on `created_at`/`paid_at` for pending + credited. Do not change the existing meaning silently.

### 15.7 `stats()`

Add:

| Key | Meaning |
|---|---|
| `pending_total` | Sum of pending |
| `pending` | Count of pending |
| `cancelled` | Count of cancelled |
| `total_awarded` | `credited_total + pending_total` |
| `next_release_at` | Earliest pending release date |

Change `unrecovered` to sum only `voided`:

```sql
COALESCE(SUM(CASE WHEN status = 'voided' THEN amount - reversed_amount ELSE 0 END), 0)
```

`cancelled` rows are **not** unrecovered; they were never paid. This is why the separate `cancelled` status is used instead of overloading `voided`.

`recipients` and `average` remain based on `credited` money; add a separate `pending_recipients` if the UI wants it. Do not count pending in “Total credited”.

### 15.8 `levelBreakdown()`

Add `pending_amount` and `pending_count` per level while keeping `amount`/`count` as credited. The member and admin views can show both.

### 15.9 `creditedByBuyer()`

Add `pending_amount` and `pending_count` to each `(level, buyer)` tuple, or add a parallel `pendingByBuyer()`. The member team card can show “earned” and “pending release” separately. If scope is tight, keep this method credited-only and rely on the repeat statement for pending; document the choice.

### 15.10 CSV

`csvRows()` / `csvHeader()`:

- Add `Released at` (the `credited_at` value).
- Add `Hold days`.
- Keep `Status`, `Reason`, `Recovered`.
- A pending row has an empty `Released at` and a hold value.
- A cancelled row has an empty `Released at`, `Recovered = 0.00`, and the reason.

The CSV shape change is breaking for tests and for anyone consuming the export; update `docs/admin-guide.md` and the QA CSV checks.

### 15.11 Pending summary

Add a read method dedicated to the A++ UI:

```php
/**
 * @return array{
 *   total: float, count: int,
 *   next_release_at: ?string,
 *   undelivered: int, overdue: int,
 *   release_enabled: bool
 * }
 */
public static function pendingSummaryForUser(int $userId, ?PDO $pdo = null): array
```

Query outline:

```sql
SELECT COUNT(*) AS pending_count,
       COALESCE(SUM(a.amount), 0) AS pending_total,
       MIN(CASE
             WHEN o.status IN ('delivered','completed')
              AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
             THEN DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                           INTERVAL a.hold_days DAY)
           END) AS next_release_at,
       SUM(CASE
             WHEN o.status NOT IN ('delivered','completed')
               OR COALESCE(o.delivered_at, o.completed_at) IS NULL
             THEN 1 ELSE 0
           END) AS undelivered,
       SUM(CASE
             WHEN o.status IN ('delivered','completed')
              AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                           INTERVAL a.hold_days DAY) < NOW()
             THEN 1 ELSE 0
           END) AS overdue
  FROM unilevel_awards a
  JOIN shop_orders o ON o.id = a.order_id
 WHERE a.user_id = ? AND a.status = 'pending'
```

Guard with a table-exists check (the pattern used by `UnilevelService::reconcile()`), so old test schemas without `unilevel_awards` return zeros instead of throwing.

`release_enabled` comes from `setting_bool('unilevel_release_enabled', true)`.

---

## 16. Service API — `UnilevelService`

File: `system/app/Services/UnilevelService.php`.

### 16.1 `onOrderPaid()`

Changes:

- Read `$holdDays = max(0, min(365, setting_int('unilevel_hold_days', 7)))` once.
- Add `'hold_days' => $holdDays` and `'status' => 'pending'` to every eligible draft.
- Remove the `WalletService::credit()` call and `UnilevelAward::linkLedger()` call.
- The `$awardId` is no longer needed for a wallet link at this stage; keep the row id only for the result.
- Stamp the order with the sum of pending amounts.
- Keep the wallet-lock ordering? There are no wallet locks now. Keep the draft sort for deterministic row inserts and for the release phase.
- Update the result docblock: `credited` remains an alias for the awarded total; add `awarded` and `pending`.
- Update the class docblock: money moves at release, not at payment; reversal is still best effort for credited awards.
- Touch `Live::touchMemberDashboard()` and `Live::touchMembership()` because the pending totals change (the existing `touch()` only touches earnings/order/admin commissions).

### 16.2 `releaseForOrder()`

```php
/**
 * Release one order's due pending awards. Idempotent.
 *
 * @return array{
 *   order_id:int, released:int, awards:list<int>,
 *   amount:float, skipped:int, reason?:string
 * }
 */
public function releaseForOrder(
    int $orderId,
    bool $force = false,
    ?int $actorId = null,
    string $reason = '',
    ?PDO $pdo = null
): array
```

Algorithm:

1. Bind the optional PDO.
2. Add a private `withTransaction()` to `UnilevelService` using the same savepoint/own-transaction pattern as `WalletService`, so the job can call it per order and tests can call it inside a transaction. The existing money methods assume the caller owns the transaction; the release method is the first one that may be called on its own.
3. Lock the order `FOR UPDATE`.
4. If the order is missing: return `reason='not found'`.
5. If `cancelled_at IS NOT NULL`: return `reason='cancelled'`.
6. If status not in `delivered/completed`: return `reason='not delivered'`.
7. `$base = delivered_at ?? completed_at`; if null: return `reason='missing delivery date'`.
8. Select pending awards `FOR UPDATE` ordered by `user_id, id`, joined to the product and order so the wallet note can still say “Repeat-purchase bonus — <product> (level N) — order <no>”:
   ```sql
   SELECT a.*, p.name AS product_name, o.order_no
     FROM unilevel_awards a
     JOIN products p ON p.id = a.product_id
     JOIN shop_orders o ON o.id = a.order_id
    WHERE a.order_id = ? AND a.status = 'pending'
    ORDER BY a.user_id ASC, a.id ASC
    FOR UPDATE
   ```
9. Filter to due awards unless `$force`:
   - `strtotime($base) + hold_days * 86400 <= time()`.
   - If the award has `hold_days=0` and `$base` is today, it is due.
10. Sort by `user_id` before taking wallet locks.
11. For each:
    - `$movement = $this->wallet->credit($userId, $amount, 'unilevel', $awardId, $this->note(...), true);`
    - `UnilevelAward::markCredited($awardId, (int) $movement['ledger_id'], $this->pdo);`
12. Audit `unilevel.release` with order, award ids, amount, force, actor, reason.
13. Touch earnings for every recipient, wallet, order, admin commissions/dashboard, dashboard/membership.
14. Return counts.

`$force` is for a future support tool; the normal job never uses it.

### 16.3 `releaseDue()`

```php
/**
 * @return array{
 *   released_orders:int, released_awards:int, amount:float,
 *   skipped:int, candidates:int, errors:int, ids:list<int>
 * }
 */
public function releaseDue(int $limit = 200, ?PDO $pdo = null): array
```

- Find candidate order ids (query below), `LIMIT`.
- For each order, call `releaseForOrder($orderId, false, null, '', $pdo)`.
- Catch per-order `Throwable`, log, count `errors`, continue.
- Do not wrap all orders in one transaction; one transaction per order.
- `CronLock` prevents a second job; the order lock and status guards handle a manual overlap.

Candidate query:

```sql
SELECT a.order_id
  FROM unilevel_awards a
  JOIN shop_orders o ON o.id = a.order_id
 WHERE a.status = 'pending'
   AND o.status IN ('delivered','completed')
   AND o.cancelled_at IS NULL
   AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
   AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                INTERVAL a.hold_days DAY) <= NOW()
 GROUP BY a.order_id
 ORDER BY MIN(a.id) ASC
 LIMIT ?
```

The `GROUP BY` makes one release transaction per order. The service re-checks each award’s due date under the lock.

### 16.4 `reverseForOrder()`

Refactor into two private helpers:

- `cancelPendingForOrder(int $orderId, string $reason): array`
  - `SELECT * FROM unilevel_awards WHERE order_id=? AND status='pending' ORDER BY user_id, id FOR UPDATE`
  - `UnilevelAward::cancel()` each.
  - Return `['count' => int, 'recipients' => list<int>]`.
  - Touch earnings/dashboard/membership for recipients (and the member wallet surface if the wallet page shows the pending note).
- `reverseCreditedForOrder(int $orderId, string $reason): array`
  - The existing loop, unchanged except it only selects `status='credited'`.
  - Returns `voided`, `recovered`, `shortfall`, recipients.

Public `reverseForOrder()`:

```php
$result = ['order_id' => $orderId, 'disabled' => false,
           'pending_cancelled' => 0, 'credited_voided' => 0,
           'voided' => 0, 'recovered' => 0.0, 'shortfall' => 0.0];

/* 1. Pending first: no money moved, so this is always safe. */
$pending = $this->cancelPendingForOrder($orderId, $reason);
$result['pending_cancelled'] = $pending['count'];

/* 2. Credited reversal only when the operator allows it. */
$credited = ['voided' => 0, 'recovered' => 0.0, 'shortfall' => 0.0, 'recipients' => []];

if (setting_bool('unilevel_reversal_enabled', true)) {
    $credited = $this->reverseCreditedForOrder($orderId, $reason);
} else {
    $result['disabled'] = true;
}

$result['voided']          = $credited['voided'] ?? 0;
$result['credited_voided'] = $result['voided'];
$result['recovered']       = $credited['recovered'] ?? 0.0;
$result['shortfall']       = $credited['shortfall'] ?? 0.0;

/* 3. The order total reflects what is still pending + credited. */
UnilevelAward::recomputeOrderTotal($orderId, $this->pdo);

/* 4. Audit and touch on every path, including the switch-off path. */
$this->auditReversal($orderId, $result, $reason);
$this->touchReversal($orderId, $result, $pending['recipients'], $credited['recipients'] ?? []);

return $result;
```

Keep the `order_id`, `disabled`, `voided`, `recovered`, `shortfall` keys for existing callers/tests; add `pending_cancelled` and `credited_voided`.

Important: the audit and `recomputeOrderTotal()` must run even when the reversal switch is off and only pending rows were cancelled. Otherwise the order total stays at the awarded amount. `auditReversal()` and `touchReversal()` are private helpers so the normal and disabled paths cannot drift.

### 16.5 `pendingSummary()`

Expose the model method for controllers:

```php
public function pendingSummary(int $userId): array
{
    return UnilevelAward::pendingSummaryForUser($userId, $this->pdo);
}
```

Or let controllers call the model directly. Prefer the service if a table-exists guard is needed in one place.

### 16.6 `reconcile()`

Update the result shape:

| Key | Change |
|---|---|
| `pending_rows` | New |
| `pending_total` | New |
| `cancelled_rows` | New |
| `cancelled_total` | New |
| `credited_rows` / `credited_total` | Now released only |
| `ever_credited_total` | `credited + voided` (both have ledger) |
| `reversal_total` | Unchanged; must equal `cancelled` (0) + `voided.reversed_amount` |
| `mismatches` | Add the new checks |

New checks:

1. `ever_credited_total` equals the `unilevel` ledger credit total.
2. `reversal_total` equals the `unilevel_reversal` debit total.
3. Every `credited` and `voided` row has a `ledger_id`; no `pending` or `cancelled` row has one.
4. No `pending` row belongs to a cancelled order.
5. `shop_orders.unilevel_total` equals `SUM(pending + credited)` for that order.
6. A `cancelled` row has `reversed_amount = 0` and no `reversal_ledger_id`.
7. A `voided` row has `reversal_ledger_id` and `reversed_amount <= amount`.
8. Optional warning: pending awards that have been due for more than 48 hours (stuck release).

`ok` is false only for real mismatches. Stuck pending is a warning that the CLI/doctor prints; it should not make `wallet_reconcile` exit 1 during a normal delay, but it should be visible.

### 16.7 Monthly close

`UnilevelCycle::close()` currently sums `status='credited'` for `award_total`. Change to `status IN ('pending','credited')` so a closed month includes bonuses earned in that month but not yet released. The period is immutable; a later cancellation does not rewrite it. Document this in `docs/runbook.md` and the plan.

---

## 17. The release job

### 17.1 Files

- `system/bin/unilevel_release.php`
- `system/bin/unilevel_release.cmd`
- `system/public/cron/unilevel_release.php`
- New section in `docs/cron.md`

Mirror the structure of `system/bin/order_autocomplete.php`:

- CLI, web and Windows entry points.
- `CronLock` with a job-specific lock name.
- `CronAccess` for the web entry (loopback trusted; otherwise `?key=`).
- One log line per run.
- `--limit`, `--dry-run`, `--json`, `--doctor`, `--quiet`, `--log-dir`, `--help`.
- Optional `--order=ID` for a targeted diagnostic (respects the normal due rule; no force in the first pass).

### 17.2 Schedule

Hourly at `:20` (or every 15 minutes):

```cron
20 * * * * /usr/bin/php /path/system/bin/unilevel_release.php --limit=200 >> /path/system/storage/logs/cron.out 2>&1
```

The release window is day-granular, so hourly is enough. The job is cheap and idempotent.

### 17.3 Log

`storage/logs/unilevel_release-YYYY-MM.log`, one line per run:

```text
[2026-11-21 09:20:01] trigger=cron released_orders=3 released_awards=4 amount=50.00 skipped=0 candidates=3 errors=0 duration=0.12s
```

### 17.4 `--doctor`

Report:

- PHP/MySQL clocks and APP_TIMEZONE.
- `unilevel_release_enabled`, `unilevel_hold_days`.
- pending count/amount.
- oldest pending award age.
- delivered/completed orders with due pending awards (should be 0 after a run).
- shipped orders older than 30 days with pending awards (stuck).
- due pending on cancelled orders (must be 0).
- lock state, log folder writable.

### 17.5 `--dry-run`

List the order ids, award ids, recipients and amounts that would be released; change nothing. Assert no wallet movement and no status change.

### 17.6 `--json`

Return the same numbers as the log line plus `ids` and `skipped_reasons`. The web entry returns this payload.

### 17.7 Web entry

`system/public/cron/unilevel_release.php` is one `require` of the CLI, exactly like the other jobs. Add it to the route/inventory docs if the project lists cron entries. It is not a routed page.

### 17.8 Access

- CLI always allowed.
- Web: loopback trusted; otherwise `CronAccess` with `CRON_KEY`.
- The job runs even when the shop is closed or the site is in maintenance mode.

---

## 18. Member controllers and views

### 18.1 `WalletController`

`walletContext()` add:

```php
'pending_repeat' => UnilevelAward::pendingSummaryForUser($userId),
```

Pass it to `member/wallet.php` and the fragments (`member-wallet-buckets`, `member-wallet-payout`). The live feed must re-render the same fragments. The wallet surface revision must be touched when pending changes (see §21).

### 18.2 `DashboardController`

- `dashboardContext()` already passes `unilevel` totals; add a `pending` key to the wallet data or let the view read `$unilevel['totals']['pending']`.
- Add `'pending_repeat' => UnilevelAward::pendingSummaryForUser($userId)` if the wallet card needs the summary.
- `feedPayload()` passes the new key to `dashboard-kpis`, `dashboard-wallet` and `dashboard-earnings`.
- `dashboardScope()` does not need a new revision for pending if `Live::touchMemberDashboard()` is called on award/cancel/release. The existing scope mixes `GENEALOGY_TEAM`/`CONFIG`; pending changes are per-member and should bump the member dashboard surface.

### 18.3 `MembershipController`

`unilevelSummary()` already returns `totals`; `totalsForUser()` gains pending. The view uses it. No new controller key required unless the page needs `next_release_at`; add `pending_repeat` if so.

### 18.4 `EarningsController`

`repeatContext()`:

- `totals` gains pending/pending_count/next_release_at.
- `levels` (`repeatLevels()`) gains pending from `levelBreakdown()`.
- Rows include `hold_days`, `credited_at`, order delivery dates (via `select()`).

`repeatLevels()` and the team card: if `creditedByBuyer()` gains pending, pass it through; otherwise leave the team card credited-only and document.

### 18.5 `PayoutController`

- `payoutContext()` uses `PayoutService::optionsFor()`, which now includes `pending_repeat`.
- `request()` unchanged except the service may return a `pending_repeat` snapshot for the success message.
- `successMessage()` appends the pending note when `pending_repeat['total'] > 0`.
- `pageScope()` already mixes the wallet revision; pending changes must touch the member wallet surface so the payout card refreshes.
- The live feed (`feedPayload()`) must pass `pending_repeat` to the balance and request fragments.

### 18.6 Views

| View | Change |
|---|---|
| `member/wallet.php` | Pass `pending_repeat`; header “Total funds” |
| `member/wallet/_buckets.php` | Add `Pending release` and `Total funds`; hide when zero |
| `member/wallet/_payout_card.php` | Add pending line and “not withdrawable yet” |
| `member/wallet/_statement.php` | No pending rows; optional footer note |
| `member/payouts.php` | Pass pending; history footer note |
| `member/payouts/_balance.php` | Add pending line; no-withdrawable copy |
| `member/payouts/_form.php` | Add the inline escrow alert above the amount |
| `member/payouts/_request.php` | Open request note |
| `member/payouts/_history.php` (if separate) | Footer/header note |
| `member/dashboard/_kpis.php` | Pending line near withdrawable |
| `member/dashboard/_wallet_card.php` | Pending line |
| `member/dashboard/_earnings.php` | Repeat tile: credited + pending = total |
| `member/membership/_repeat.php` | Credited + pending |
| `member/earnings/_repeat_stats.php` | Pending tile |
| `member/earnings/_repeat_rows.php` | Pending/cancelled badges, release date |
| `member/earnings/_repeat_levels.php` | Pending per level |
| `member/earnings/_repeat_header.php` | Copy |
| `shop/order/_bonus.php` | “Reserved / released” wording |
| `shop/product/_bonus.php` | “Earned when paid; released after return window” |
| `shop/checkout/_bonus.php` | Same |

### 18.7 Status badge overrides

`status_badge()` already maps `pending` to “Pending” and `cancelled` to “Cancelled”. In the repeat views, pass an override so the award context says:

```php
status_badge($status, [
    'pending'   => ['Pending release', 'warning'],
    'cancelled' => ['Cancelled before release', 'secondary'],
])
```

Do not change the global map, because order statuses also use `pending`/`cancelled`.

---

## 19. Admin controllers and views

### 19.1 `AdminOrderController`

`orderContext()` `unilevel` gains:

- `released_total = SUM(amount) WHERE status='credited'`
- `pending_total = SUM(amount) WHERE status='pending'`
- `cancelled_total = SUM(amount) WHERE status='cancelled'`
- `next_release_at` (earliest pending due date)
- `is_cancelled` (order status)
- `release_enabled`

The card can compute these from `awards` in PHP; no extra query if the awards are already loaded. Add the totals to the context for the feed.

### 19.2 `admin/orders/_unilevel.php`

New branches:

- `!$isPaid && $awards !== [] && $status === 'cancelled'`: show the rows with `Cancelled before release`, no wallet links, badge **No bonus released**, note “This order was cancelled before any repeat-purchase bonus was released.”
- `$isPaid && $pending_total > 0`: badge **₱X pending release**; rows `Pending release`; due date; no wallet link.
- `$isPaid && $released_total > 0`: badge **₱X released**; rows `Credited`; wallet link.
- Mixed pending + credited: show both totals and both row states.
- Footer: “Awarded ₱X · Released ₱Y · Pending ₱Z”.

The card must not hide cancelled rows just because the order is no longer paid. The audit trail is the point.

### 19.3 `AdminCommissionController` and views

- `UnilevelAward::stats()` gains pending/cancelled; the view adds tiles:
  - **Total credited** (released)
  - **Pending release**
  - **This month** (credited this month)
  - **Awards** (credited count; optionally pending count in the hint)
  - **Recipients**
  - **Skipped**
  - **Cancelled before release**
  - **Unrecovered** (voided only)
- Keep the layout usable; if eight tiles are too many, combine **Cancelled** and **Skipped** into one “Not paid” tile with a breakdown.
- `_repeat_rows.php`:
  - `pending` → badge **Pending release**; amount normal; note “due <date>” or “after delivery + N days”; wallet link “—”.
  - `cancelled` → badge **Cancelled before release**; amount normal; note “no money moved”; wallet link “—”.
  - `credited`/`voided` unchanged.
- `_repeat_header.php` copy: “credited, pending, skipped or cancelled”.
- Filters: `status` already accepts the new values via `STATUSES`; the dropdown should include them (it likely loops `STATUSES`). Check the view.
- CSV: new columns, statuses.

### 19.4 `AdminWalletController` and views

- The admin member wallet page shows **Pending release** as a line outside the wallet buckets (because it is not in `ewallet_balance`). Label it clearly: “Repeat-purchase pending release (not in wallet balance)”.
- The admin wallet monitor’s global totals can add a **Pending repeat bonuses** liability tile from `UnilevelAward::stats()['pending_total']`. This is a separate liability, not part of the float.
- Do not add pending to `Wallet::totals()['locked']`; that would change the wallet monitor’s meaning.

### 19.5 `AdminMemberController`

The member page’s wallet summary (`admin/members/_wallet.php`) adds a pending line. The context gains `pending_repeat` from `UnilevelAward::pendingSummaryForUser($userId)`.

### 19.6 `AdminReportController` / `Report`

- `Report::unilevelSummary()` adds `pending_amount`, `pending_count`, `cancelled_count`.
- `Report::unilevelByLevel()` adds `pending_count`, `pending_amount`.
- `Report::unilevelByProduct()` adds the same.
- The report view adds a **Pending release** card/column. The “Repeat bonus (30d)” KPI remains credited money; add a **Pending liability** KPI.
- `ReportService` CSV export adds the new columns.

### 19.7 Admin settings

- `AdminSettingsController::REPEAT_FIELDS` / `REPEAT_TOGGLES` add the two keys.
- `admin/settings/_commissions.php` loops the fields/toggles; ensure the new names render correctly and the hints fit.
- `settings.update` audit includes the new values.
- `Live::touchSettings()` after save.

### 19.8 Admin payout queue (optional)

- Add the member’s pending total to the detail/drawer only.
- Do not add a per-row subquery to the queue; it would be N+1.
- The member-facing reminder is the required piece; the admin note is support sugar.

---

## 20. Reporting and reconciliation

### 20.1 `bin/wallet_reconcile.php`

The Repeat-purchase section adds:

- pending count/amount;
- cancelled count/amount;
- credited count/amount (released);
- ever credited;
- reversal total;
- warnings (stuck pending).

The wallet section is unchanged. The exit code includes only real unilevel mismatches; stuck pending is printed as a warning.

### 20.2 `DatabaseResetService`

- The integrity pack calls `UnilevelService::reconcile()`; the new keys are read from the same `ok`/`mismatches` shape, so no structural change is required.
- If the reset detail prints the repeat section’s counts, update the labels.
- The reset deletion order is unchanged (no new table).
- The reset’s settings restore does not need a new list; settings are preserved.

### 20.3 `HealthService`

No change required. It does not currently check unilevel; the release job’s doctor covers the operational side.

### 20.4 Monthly close

- `UnilevelCycle::close()` `award_total` becomes `SUM(pending + credited)` for the period.
- The closed month is immutable; a later cancellation does not rewrite it.
- Document: “Award total is the month’s earned repeat-purchase money, including amounts still pending release at close.”
- The member’s Active snapshot is unaffected (it uses paid orders, not awards).

---

## 21. Live updates and cache surfaces

| Event | Surfaces to touch |
|---|---|
| Award created as pending | `Live::touchEarnings($recipient)`, `Live::touchOrder($orderId, $buyer)`, `Live::touch('admin.commissions')`, `Live::touchMemberDashboard($recipient)`, `Live::touchMembership($recipient)` |
| Award released | WalletService credit touches wallet/member wallet/dashboard/membership/checkout/member; add `Live::touchEarnings($recipient)`, `Live::touchOrder`, `Live::touch('admin.commissions', 'admin.dashboard')` |
| Pending cancelled | `Live::touchEarnings($recipient)`, `Live::touchOrder`, `Live::touch('admin.commissions', 'admin.dashboard')`, `Live::touchMemberDashboard`, `Live::touchMembership`; also `Live::touchMemberWallet` because the pending note on the wallet/payout page changes |
| Settings save | Existing `Live::touchSettings()` plus config revision; the product/checkout copy that prints hold days must revalidate |
| Release job run | Per order: wallet/earnings/order/admin; one `admin.commissions` touch per batch is enough, but per-order is simpler and safe |

The payout page’s `pageScope()` already mixes the member wallet revision, so a pending change that touches `Live::memberWalletSurface($userId)` refreshes the payout card. Add `Live::touchMemberWallet()` to the pending-created/cancelled paths if the pending line is on the wallet page; if not, the dashboard/membership/earnings touches are enough.

---

## 22. File change map

### 22.1 New files

| File | Purpose |
|---|---|
| `system/database/migrations/021_unilevel_bonus_escrow.sql` | Schema/settings migration |
| `system/bin/unilevel_release.php` | Release CLI |
| `system/bin/unilevel_release.cmd` | Windows launcher |
| `system/public/cron/unilevel_release.php` | Web entry |
| `system/tests/...` (new test file) | `UnilevelEscrowTest` / `UnilevelReleaseTest` |
| `docs/qa/evidence/unilevel-escrow/` | New QA evidence folder (created during QA, not code) |

### 22.2 Changed files — backend

| File | Change |
|---|---|
| `system/database/schema.sql` | Status enum, columns, indexes, settings |
| `system/database/install.sql` | Regenerated |
| `system/app/Models/UnilevelAward.php` | Statuses, create, markCredited, cancel, totals, stats, breakdown, CSV, pending summary |
| `system/app/Services/UnilevelService.php` | Pending creation, release methods, cancellation split, reconcile, monthly close |
| `system/app/Models/UnilevelCycle.php` | Close `award_total` includes pending |
| `system/app/Services/PayoutService.php` | `optionsFor` pending, `request` pending snapshot, over-limit message |
| `system/app/Services/PayoutException.php` | `aboveWithdrawable` optional pending |
| `system/app/Controllers/PayoutController.php` | Pass pending, success message, feed |
| `system/app/Controllers/WalletController.php` | Pending summary in wallet context/feed |
| `system/app/Controllers/DashboardController.php` | Pending in dashboard context/feed |
| `system/app/Controllers/MembershipController.php` | Pending in totals (via model) |
| `system/app/Controllers/EarningsController.php` | Pending in repeat context/levels |
| `system/app/Controllers/AdminCommissionController.php` | Stats/filters/rows/CSV |
| `system/app/Controllers/AdminOrderController.php` | Order card totals/states |
| `system/app/Controllers/AdminWalletController.php` | Member/admin pending line |
| `system/app/Controllers/AdminMemberController.php` | Member pending line |
| `system/app/Controllers/AdminReportController.php` | Pending report fields |
| `system/app/Models/Report.php` | Pending summary/level/product |
| `system/app/Services/ReportService.php` | CSV pending/released columns |
| `system/app/Services/DatabaseInstaller.php` | Expected settings 56 |
| `system/app/Services/Live.php` | No new surface; use existing methods |
| `system/bin/wallet_reconcile.php` | Pending/cancelled output |
| `system/bin/build_install_sql.php` | No code change; regenerate/verify |

### 22.3 Changed files — member views

`system/app/views/member/wallet.php`,
`system/app/views/member/wallet/_buckets.php`,
`system/app/views/member/wallet/_payout_card.php`,
`system/app/views/member/wallet/_statement.php`,
`system/app/views/member/payouts.php`,
`system/app/views/member/payouts/_balance.php`,
`system/app/views/member/payouts/_form.php`,
`system/app/views/member/payouts/_request.php`,
`system/app/views/member/dashboard/_kpis.php`,
`system/app/views/member/dashboard/_wallet_card.php`,
`system/app/views/member/dashboard/_earnings.php`,
`system/app/views/member/membership/_repeat.php`,
`system/app/views/member/earnings/_repeat_stats.php`,
`system/app/views/member/earnings/_repeat_rows.php`,
`system/app/views/member/earnings/_repeat_levels.php`,
`system/app/views/member/earnings/_repeat_header.php`,
`system/app/views/shop/order/_bonus.php`,
`system/app/views/shop/product/_bonus.php`,
`system/app/views/shop/checkout/_bonus.php`.

### 22.4 Changed files — admin views

`system/app/views/admin/orders/_unilevel.php`,
`system/app/views/admin/commissions/_repeat_stats.php`,
`system/app/views/admin/commissions/_repeat_rows.php`,
`system/app/views/admin/commissions/_repeat_header.php`,
`system/app/views/admin/commissions/index.php`,
`system/app/views/admin/wallets/_buckets.php`,
`system/app/views/admin/wallets/_stats.php`,
`system/app/views/admin/wallets/_members.php`,
`system/app/views/admin/members/_wallet.php`,
`system/app/views/admin/reports/_unilevel.php`,
`system/app/views/admin/reports/_unilevel_levels.php`,
`system/app/views/admin/reports/_unilevel_products.php`,
`system/app/views/admin/settings/_commissions.php`.

### 22.5 Docs

`docs/cron.md`, `docs/deploy.md`, `docs/runbook.md`, `docs/admin-guide.md`, `docs/qa-unilevel-product.md`, `docs/phase-log.md`, `docs/install-reset.md`, `docs/qa/README.md`, `docs/qa/sign-off-sheet.md`, `system/README.md`, and this plan.

The existing Phase 17 plan’s `D-04`/Q5 should be updated or annotated to point at this plan: the award is still earned at `paid`, but the wallet credit is released after delivery + hold.

---

## 23. Test plan

### 23.1 New test file

`UnilevelEscrowTest` (or `UnilevelReleaseTest`) covering the escrow lifecycle. The exact harness follows `UnilevelEngineTest`: a scratch schema, fixture users/order/product/ladder, and the service inside a transaction that rolls back.

### 23.2 Core cases

| ID | Case | Expected |
|---|---|---|
| ESC-T1 | Paid order creates awards | Status `pending`; no `ewallet_ledger` row; wallet unchanged |
| ESC-T2 | Order stamp | `unilevel_total` = pending total; `unilevel_processed_at` set |
| ESC-T3 | Replay `onOrderPaid` | No second set of rows; no wallet movement |
| ESC-T4 | Release before delivery | No credit; reason `not delivered` |
| ESC-T5 | Release before hold days | No credit; pending remains |
| ESC-T6 | Release at delivery + 0 days | Credit exactly once; status `credited`; `ledger_id`/`credited_at` set |
| ESC-T7 | Release at delivery + 7 days | Not released before day 7; released at day 7 |
| ESC-T8 | Release twice | Second run `released=0`; no second credit |
| ESC-T9 | Release one of two orders | Only the due order is credited |
| ESC-T10 | Release wallet failure | Whole order transaction rolls back; awards stay pending |
| ESC-T11 | Cancellation before release | `cancelled`; no ledger row; no shortfall; `unilevel_total=0` |
| ESC-T12 | Cancellation with reversal switch off | Pending still `cancelled`; credited untouched |
| ESC-T13 | Cancellation after release | `voided`; reversal row; shortfall recorded if spent |
| ESC-T14 | Release after cancellation | No credit; no status change |
| ESC-T15 | Release a cancelled order | `reason='not delivered'`/`cancelled`; no credit |
| ESC-T16 | Hold-days snapshot | Changing the setting does not move an existing award |
| ESC-T17 | Pending summary | Correct total/count/next date/undelivered |
| ESC-T18 | Payout options | `pending_repeat` populated; `max_amount` excludes pending |
| ESC-T19 | Over-limit exception | Message names pending; reason `withdrawable` |
| ESC-T20 | Payout success message | Pending note appended when pending > 0 |
| ESC-T21 | Reconcile after cancellation | `ok=true`; pending 0; no mismatch |
| ESC-T22 | Reconcile after release | `ok=true`; credited total matches ledger |
| ESC-T23 | Reconcile pending on cancelled order | Mismatch reported |
| ESC-T24 | Monthly close | `award_total` includes pending + credited |
| ESC-T25 | Migration | Columns/enum/indexes/settings; second run no-op |
| ESC-T26 | Fresh install | 31 tables / 56 settings |
| ESC-T27 | CSV | New columns; pending/cancelled rows |
| ESC-T28 | Admin ledger filters | `status=pending`/`cancelled` work |
| ESC-T29 | Cron dry-run | No wallet movement/status change |
| ESC-T30 | Cron doctor | Reports pending/undelivered/stuck/lock/log |

### 23.3 Existing cases to update

- `UnilevelEngineTest`: award creation expectations, wallet balances, reversal, reconcile.
- `PaymentTest` / `OrderTest`: pending awards, order total.
- `UnilevelConfigurationTest`: new settings/validation.
- `PayoutTest`: options/exception/result.
- `PayoutLiveTest`: page copy, reminder, over-limit.
- `WalletTest` / `WalletLiveTest`: pending/total funds presentation.
- `DashboardTest` / `MembershipTest`: pending lines.
- `EarningsTest`: pending stats/rows/levels/CSV.
- `AdminCommissionLiveTest`: pending/cancelled stats/rows/filters/CSV.
- `AdminOrderLiveTest`: order card states.
- `AdminWalletLiveTest` / `AdminMemberLiveTest`: pending line.
- `ReportTest`: pending fields.
- `CronTest`: new job.
- `MigrationTest` / `InstallTest`: 021, 56 settings.
- `DbResetTest`: reconcile keys.
- `Smoke`: new pages/copy/cron.

### 23.4 Regression policy

Run the full PHP suite and smoke after each work package. The award timing change is cross-cutting; do not merge until:

- `php tests/run.php` green;
- `powershell -File tests/smoke.ps1` green;
- `php bin/wallet_reconcile.php` exit 0;
- `php bin/build_install_sql.php --check` exit 0;
- a manual E4 cancellation leaves no wallet movement and no shortfall;
- a manual release credits exactly once.

---

## 24. Documentation and QA plan

### 24.1 `docs/qa-unilevel-product.md`

The current E4 expects a debit and a statement reversal. That is no longer true for a new order. The document needs a **v2** (or a clearly marked addendum), not a sentence patch.

Changes:

- **B4 / order outcome** — the order total is “awarded/reserved”, not “paid”.
- **C1** — at payment, the wallet does **not** rise; the award is `pending`. Add a new C1b after delivery + release where the wallet rises by ₱12.50.
- **C2** — the repeat statement shows `Pending release` and the release date; after release, `Credited`.
- **C3/C4/C5** — update the figures and the two-clock explanation where it says “credited”.
- **D1/D2** — admin ledger rows are `Pending release`; “Total credited” is 0 until release; add a **Pending** tile; filters include `pending`/`cancelled`.
- **E4** — cancel a paid-but-not-delivered order:
  - order `Cancelled`;
  - admin card rows `Cancelled before release`, no wallet link;
  - Maria/Juan wallet unchanged; earnings row `Cancelled`; no statement reversal;
  - admin ledger `Unrecovered ₱0.00`, not ₱25.00;
  - no balance negative, no row deleted.
- **E5 (new)** — payout reminder:
  - `/payouts` shows `Pending release ₱12.50`;
  - the form says it is not included;
  - trying to request more than withdrawable shows the pending explanation;
  - no block on requesting the withdrawable amount.
- **E6 (new, optional)** — `unilevel_hold_days=7`: deliver the order, run the release job, confirm the bonus is still pending; then use a `hold_days=0` order to prove release. Do not wait 7 days.
- **Fulfilment step** — the main walkthrough needs pack → ready → ship → deliver. Use `hold_days=0` for the fast path.
- **Cleanup** — E4 on a pending order moves no money; the release test leaves bonus money in the upline wallets. Use a disposable copy or restore the pre-test backup. The old “E4 returns the test money” statement is no longer true for the release path.
- **Glossary** — add `Pending release`, `Released`, `Cancelled before release`; update `Credited`.

### 24.2 `docs/admin-guide.md`

- Explain the escrow: award at payment, wallet credit at release.
- The two settings and their hints.
- The admin ledger states and the “Unrecovered” meaning.
- The payout reminder and the member’s “Total funds”.
- The release job and the doctor.
- The no-retroactive-lock policy.

### 24.3 `docs/cron.md`

- Add the fifth job: schedule, flags, dry run, doctor, log, lock, kill switch, web/.cmd entry.
- Note the release is day-granular and hourly is enough.
- Explain what happens when the release switch is off.

### 24.4 `docs/deploy.md`

- Migration `021`.
- 31 tables / **56** settings.
- Five cron jobs.
- `APP_PHASE` if the release is numbered as Phase 18.
- Verification commands and expected counts.

### 24.5 `docs/runbook.md`

- Pending/stuck queries:
  - pending total/count;
  - oldest pending;
  - pending on shipped orders;
  - pending due but not released;
  - cancelled order with pending (must be 0).
- How to run the release job and doctor.
- How to pause releases.
- Rollback/roll-forward note.
- “Do not hand-edit award rows.”

### 24.6 Other docs

- `docs/install-reset.md` — counts and reset verification.
- `docs/qa/README.md` / `docs/qa/sign-off-sheet.md` — new phase rows.
- `docs/phase-log.md` — new phase entry with gate.
- `system/README.md` — cron list.
- `dmp/plan-ecowealth-unilevel-repeat-purchase.md` — annotate D-04/Q5 to point here.

---

## 25. Rollout and rollback

### 25.1 Two-stage deploy

**Stage 1 — Infrastructure (no behaviour change)**

- Migration `021`.
- Settings keys.
- Model statuses/columns/methods.
- Release job (does nothing while all awards are `credited`).
- Reconcile/CSV/tests that do not depend on pending rows.
- Deploy to staging/dev; run migration; run the release job dry-run; confirm 0 candidates.

**Stage 2 — Behaviour and A++**

- `onOrderPaid()` creates `pending`.
- Cancellation cancels pending.
- A++ UI and payout reminder.
- New tests/docs/QA.
- Enable the release cron.
- Run the E4/E5 QA.

Why two stages:

- The migration and job are additive and backward-compatible.
- A bug in the release job can be found before any award is pending.
- A rollback of Stage 2 can keep the release job and the pending-cancellation path running.

### 25.2 Deploy checklist

- [ ] Back up the database.
- [ ] `php system/bin/db_install.php --check` / migration status.
- [ ] Apply `021`.
- [ ] `php system/bin/build_install_sql.php --check` exit 0.
- [ ] `php tests/run.php` green.
- [ ] `powershell -File tests/smoke.ps1` green.
- [ ] `php system/bin/wallet_reconcile.php` exit 0.
- [ ] Add the cron entry; run `--doctor` and `--dry-run`.
- [ ] Set `unilevel_hold_days` to the business value (default 7).
- [ ] Ensure `unilevel_release_enabled=1`.
- [ ] Run the E4/E5 QA on a disposable copy.
- [ ] Verify the first live pending award and its release.

### 25.3 Rollback

- Schema is additive; no down migration.
- If Stage 2 must be rolled back:
  - keep the release job and the pending-cancellation code;
  - revert only `onOrderPaid()` to immediate credit if necessary;
  - pending awards already created continue to release after delivery;
  - do **not** revert `reverseForOrder()` to the old early-return version, or a cancelled order could release later.
- If the release job must be paused: `unilevel_release_enabled=0`; pending stays pending; no money moves.
- If a pending award must be released manually before the job is restored, use a future `--order=ID` support tool (not in the first pass) or run the job after fixing the cause.

---

## 26. Risks, edge cases and mitigations

| # | Risk / edge case | Mitigation |
|---:|---|---|
| 1 | Undelivered order never releases | `--doctor`/admin ledger show stuck pending; ops can cancel the order or handle it; no auto-release |
| 2 | Pending award on a cancelled order | Cancellation always cancels pending; reconcile mismatch if it ever happens |
| 3 | Reversal switch off leaves pending releasable | Pending cancellation runs before the switch check; test ESC-T12 |
| 4 | Release double-credits | Order lock + award status guard + wallet idempotency; test ESC-T8 |
| 5 | Release before delivery | Status/date re-check under the order lock; test ESC-T4 |
| 6 | Partial release / mixed hold days | Per-award due filter; one transaction per order rolls back on failure |
| 7 | Hold days changed after award | Snapshot on the award; test ESC-T16 |
| 8 | `unilevel_total` drift | Recompute uses pending + credited; reconcile check; tests |
| 9 | Monthly close understates | Include pending + credited; document immutable snapshot |
| 10 | Payout reminder becomes noise | Show only when pending > 0; compact inline alert; no modal |
| 11 | Payout page query cost | One indexed pending summary per page/feed; add `(user_id,status)` index |
| 12 | Old test schema lacks `unilevel_awards` | Table-exists guard in pending summary/reconcile |
| 13 | Global master off strands pending | Release switch independent; hint says so |
| 14 | Future returns module bypasses escrow | Document the contract: return must call `reverseForOrder()`/cancel pending; add a test when returns ship |
| 15 | Partial (line-level) return | Current cancellation is whole-order; a future returns phase needs per-item reversal; out of scope |
| 16 | Buyer refund and upline reversal interleave | Cancellation refunds the buyer first, then cancels pending; no shared wallet lock; both in one transaction |
| 17 | Admin cancels while release runs | Both lock the order first; one wins; the other re-checks |
| 18 | Timezone/clock skew | Use DB `NOW()`/`DATE_ADD`; doctor compares PHP/MySQL clocks |
| 19 | CSV shape change breaks consumers | Document; update tests/QA; add columns at the end |
| 20 | Existing credited awards locked accidentally | Migration does not change status; no backfill to pending |
| 21 | Payout reminder reveals pending to an admin impersonating a member | The impersonation session is the member’s view; no extra leak (it is their own data) |
| 22 | `unilevel_release_enabled` off during order cancellation | Pending still cancelled; only credited reversal is affected |
| 23 | Stuck release due to wallet failure | Per-order rollback; log; retry hourly; doctor shows due not released |
| 24 | Too many pending awards per order | Release per order, limit 200 orders/run; wallet locks sorted by user; fine for current volume |
| 25 | A product is deleted before release | FK `RESTRICT` prevents deletion while awards exist; release uses the stored product name/level |
| 26 | Member account disabled before release | The award was earned; wallet credit still allowed; no re-eligibility check |
| 27 | Member deleted before release | FK cascade removes the award; no release; acceptable |
| 28 | Payout request for an amount that includes pending | Refused by withdrawable; message names pending; test ESC-T19 |
| 29 | Payout request for other earnings while pending exists | Allowed; pending is not included; success note explains |
| 30 | QA takes too long waiting 7 days | Use `hold_days=0` for the fast path and a separate 7-day “still pending” check; do not wait |

### 26.1 Optional manual release

If operations need it for a stuck order, add a **superadmin-only** “Release pending bonus now” action on the admin order card, with a required reason and `unilevel.release_manual` audit. It should bypass the hold only for `delivered`/`completed` orders (not `shipped`), so it cannot be used to pay an undelivered order. This is a follow-up, not part of the first pass.

### 26.2 Future returns module contract

When returns/refunds ship:

- Before release: cancel the pending awards for the returned lines (no wallet movement).
- After release: call `UnilevelAward::void()` + `WalletService::debit(..., 'unilevel_reversal', ...)` for the returned lines, with the existing shortfall rules.
- Partial returns: reverse only the awards for the returned `order_item_id`s; do not reverse the whole order.
- Add a test that a partial return leaves the other lines’ awards intact.

---

## 27. Acceptance criteria

A build is accepted when all of the following are true:

1. A paid order creates `pending` awards and **zero** wallet movements.
2. `shop_orders.unilevel_total` equals the pending + credited awards.
3. A payout request cannot include a pending award; `max_amount` is the withdrawable only.
4. The payout page shows the pending amount and says it is not included.
5. An over-limit payout refusal names the pending amount.
6. A successful payout flash names the pending amount when it exists.
7. The wallet/dashboard show `Pending release` and `Total funds` when pending > 0.
8. The member earnings tab shows pending rows with a release date and cancelled rows with no money.
9. The admin order card shows pending/released/cancelled states and never a wallet link for pending.
10. The admin repeat ledger shows pending/cancelled states; `Unrecovered` counts only voided money.
11. Cancelling a paid-but-not-delivered order leaves all wallets unchanged and records `Unrecovered ₱0.00`.
12. Releasing a delivered order after the hold credits the wallet exactly once.
13. Running the release job twice does not double-credit.
14. `unilevel_reversal_enabled=0` still cancels pending awards.
15. Existing credited awards remain withdrawable; no retroactive lock.
16. `php bin/wallet_reconcile.php` exits 0 before and after a cancel/release cycle.
17. The full PHP suite and smoke suite are green.
18. `build_install_sql --check` exits 0; a fresh install reports 31 tables / 56 settings.
19. The release cron/CLI/doctor/dry-run are documented and tested.
20. The QA v2 E4/E5 checks pass.

---

## 28. Work breakdown and estimate

| Work package | Contents | Estimate |
|---|---|---:|
| **1. Escrow foundation** | Migration 021, schema/install, settings, `UnilevelAward` statuses/columns/methods, `onOrderPaid` pending, tests for creation/migration | 2–3 days |
| **2. Release and cancellation** | `releaseForOrder`/`releaseDue`, CLI/web/cmd, lock/log/doctor/dry-run, `reverseForOrder` split, reconcile, monthly close, tests | 3–4 days |
| **3. A++ member presentation** | Pending summary, wallet/dashboard/earnings/order views, payout reminder, over-limit/success copy, live touches, tests | 3–4 days |
| **4. Admin and reporting** | Admin order card, repeat ledger stats/rows/filters/CSV, member/wallet pages, reports, settings UI, tests | 2–3 days |
| **5. Docs and QA** | QA v2, admin guide, cron/deploy/runbook, phase log, QA evidence, full suite/smoke | 3–4 days |
| **Total** | | **13–18 working days** (one developer familiar with the code) |

The range is deliberately conservative: the existing suite is ~948 PHP cases and ~225 smoke checks, and award timing is asserted across several of them. The core safety change (packages 1–2) is ~5–7 days; the A++ UI and docs are the rest.

If the business later chooses **B** (pending inside the wallet), add a separate wallet-platform phase of roughly the same size again; the award/release/cancellation model from this plan is reusable.

---

## 29. Open decisions

1. **A++ vs B** — confirm that a combined “Total funds” figure plus the payout reminder is enough, and that the e-wallet `Total balance` card does not need to include pending.
2. **Hold start** — confirm the return window starts at **delivery**, not payment or completion.
3. **Default hold days** — confirm **7**; 0 is allowed.
4. **Grandfathering** — confirm existing credited awards are not retroactively locked.
5. **Release switch vs master** — confirm the release job runs even when `unilevel_enabled=0`, controlled by `unilevel_release_enabled`.
6. **Manual release** — decide whether the first pass needs a superadmin action, or whether the job/doctor + order cancellation are enough.
7. **Admin queue reminder** — optional; confirm whether the admin payout drawer should show the member’s pending amount.
8. **QA format** — v2 of the existing QA doc versus a separate addendum; the expected results differ too much for a sentence patch.
9. **Phase number** — whether this ships as Phase 18 (`APP_PHASE` 18, phase log/tag) or as a post-17 point release.
10. **`APP_PHASE`** — only needed if the reference-route inventory must expose new pages; the cron web entry is a direct file, not a routed page.

---

# Appendices

## Appendix A — Migration SQL sketch

```sql
-- 021_unilevel_bonus_escrow.sql
-- Adds the escrow columns, the pending/cancelled states and the two settings.
-- Idempotent: every change is guarded by information_schema.

SET NAMES utf8mb4;

-- 1. hold_days
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND COLUMN_NAME = 'hold_days');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards
     ADD COLUMN hold_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. credited_at
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND COLUMN_NAME = 'credited_at');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards
     ADD COLUMN credited_at DATETIME NULL AFTER ledger_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. status enum
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%pending%');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards
     MODIFY COLUMN status
       ENUM(''pending'',''credited'',''skipped'',''voided'',''cancelled'')
       NOT NULL DEFAULT ''pending''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. indexes
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND INDEX_NAME = 'idx_unilevel_user_status');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards ADD KEY idx_unilevel_user_status (user_id, status)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND INDEX_NAME = 'idx_unilevel_order_status');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards ADD KEY idx_unilevel_order_status (order_id, status)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. backfill release dates for rows that were paid under the old model
UPDATE unilevel_awards
   SET credited_at = created_at
 WHERE credited_at IS NULL
   AND status IN ('credited', 'voided');

-- 6. settings
INSERT INTO settings (key_name, value) VALUES
  ('unilevel_hold_days',       '7'),
  ('unilevel_release_enabled', '1')
ON DUPLICATE KEY UPDATE key_name = key_name;
```

`hold_days` is `SMALLINT UNSIGNED` because the setting allows up to 365, which exceeds `TINYINT`’s 255.

---

## Appendix B — Release algorithm

```text
releaseDue(limit):
    candidates = SELECT a.order_id
                   FROM unilevel_awards a JOIN shop_orders o ON o.id = a.order_id
                  WHERE a.status='pending'
                    AND o.status IN ('delivered','completed')
                    AND o.cancelled_at IS NULL
                    AND COALESCE(o.delivered_at,o.completed_at) IS NOT NULL
                    AND DATE_ADD(COALESCE(o.delivered_at,o.completed_at),
                                 INTERVAL a.hold_days DAY) <= NOW()
                  GROUP BY a.order_id
                  ORDER BY MIN(a.id)
                  LIMIT <clamped integer>

    for each order_id:
        try:
            releaseForOrder(order_id)
        catch Throwable:
            log; errors++

releaseForOrder(order_id):
    transaction (or savepoint):
        order = SELECT * FROM shop_orders WHERE id=? FOR UPDATE
        if order missing: return
        if order.cancelled_at != null: return
        if order.status not in ('delivered','completed'): return
        base = order.delivered_at ?? order.completed_at
        if base == null: return

        awards = SELECT a.*, p.name AS product_name, o.order_no
                   FROM unilevel_awards a
                   JOIN products p ON p.id = a.product_id
                   JOIN shop_orders o ON o.id = a.order_id
                  WHERE a.order_id=? AND a.status='pending'
                  ORDER BY a.user_id, a.id FOR UPDATE

        due = [a for a in awards if force or base + a.hold_days*86400 <= now]
        if due == []: return

        sort due by user_id, id
        for a in due:
            note = "Repeat-purchase bonus — <product_name> (level <level>) — order <order_no>"
            movement = WalletService.credit(a.user_id, a.amount, 'unilevel',
                                            a.id, note, withdrawable=true)
            UnilevelAward.markCredited(a.id, movement.ledger_id)

        audit('unilevel.release', order_id, ids, amount, force, actor, reason)
        touch surfaces
```

Build `LIMIT` as an inline integer after clamping (1–1000), matching the existing cron jobs; a bound `LIMIT ?` is fragile with emulated prepares.

SQL candidate query:

```sql
SELECT a.order_id
  FROM unilevel_awards a
  JOIN shop_orders o ON o.id = a.order_id
 WHERE a.status = 'pending'
   AND o.status IN ('delivered', 'completed')
   AND o.cancelled_at IS NULL
   AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
   AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                INTERVAL a.hold_days DAY) <= NOW()
 GROUP BY a.order_id
 ORDER BY MIN(a.id) ASC
 LIMIT <clamped integer>
```

Lock order:

```sql
SELECT * FROM shop_orders WHERE id = ? FOR UPDATE
```

Lock pending awards:

```sql
SELECT a.*, p.name AS product_name, o.order_no
  FROM unilevel_awards a
  JOIN products p ON p.id = a.product_id
  JOIN shop_orders o ON o.id = a.order_id
 WHERE a.order_id = ? AND a.status = 'pending'
 ORDER BY a.user_id ASC, a.id ASC
 FOR UPDATE
```

Mark credited:

```sql
UPDATE unilevel_awards
   SET status = 'credited', ledger_id = ?, credited_at = NOW()
 WHERE id = ? AND status = 'pending'
```

Cancel pending:

```sql
UPDATE unilevel_awards
   SET status = 'cancelled', voided_at = NOW(), void_reason = ?
 WHERE id = ? AND status = 'pending'
```

---

## Appendix C — Exact copy strings

### C.1 Member — wallet / total funds

- **Wallet balance** — “Money you can spend in the shop.”
- **Withdrawable** — “Money you can request by payout.”
- **Pending release** — “Repeat-purchase bonus that is not withdrawable yet.”
- **Total funds** — “Wallet balance + pending release. Pending release is not spendable or withdrawable yet.”

### C.2 Member — payout balance card

- “Pending release” / “not withdrawable yet”
- No-withdrawable state: “You have ₱X pending release, but none is withdrawable yet. It becomes available after the return window.”

### C.3 Member — payout form alert

With a known date:

> “**₱12.50** of repeat-purchase bonus is **pending release** and is **not included** in this request. It will be released after the return window (next: **21 Nov 2026**).”

Without a delivery date:

> “**₱12.50** of repeat-purchase bonus is **pending release** and is **not included** in this request. It will be released after delivery + **7 days**.”

Release paused:

> “… It will be released after the return window. Release is currently paused by the administrator.”

Some due:

> “… It is being finalized and will be added on the next release run.”

### C.4 Member — over-limit refusal

> “You can withdraw up to **₱1,032.50**. Your **₱12.50 repeat-purchase bonus** is pending release and is not yet withdrawable.”

### C.5 Member — success flash

> “₱500.00 is held for payout by GCash to 09… An administrator will process it shortly. Your **₱12.50** pending release was not part of this request; it will be released after the return window.”

### C.6 Member — open request

> “Your open request holds **₱500.00**. **₱12.50** pending release is separate and is not included.”

### C.7 Member — earnings row

- Pending: “Pending release — available after **21 Nov 2026**.”
- Pending, undelivered: “Pending release — available after delivery + **7 days**.”
- Pending, paused: “Pending release — release is currently paused.”
- Cancelled: “Order cancelled before release — no money was paid.”
- Credited: “Credited to your e-wallet on **21 Nov 2026**.”
- Voided: “₱X recovered of ₱Y” / “₱Z shortfall”.

### C.8 Member — order outcome card

> “**₱25.00** is reserved for the members above you. It is released to their e-wallets after the return window (estimated **21 Nov 2026**).”

Released variant:

> “**₱25.00** was released to the members above you when the return window closed.”

### C.9 Member — product / checkout

> “Earned by the members above you when this order is paid. It is released to their e-wallets after the return window.”

### C.10 Admin — order card

- Pending: “**₱25.00 pending release** — **₱25.00 awarded · ₱0.00 released**”
- Released: “**₱25.00 released** — **₱25.00 awarded · ₱25.00 released**”
- Cancelled: “**No bonus released** — this order was cancelled before any repeat-purchase bonus was released.”
- Mixed: “**₱25.00 awarded · ₱10.00 released · ₱15.00 pending**”
- Row pending: “Pending release — due <date>”
- Row cancelled: “Cancelled before release — no money moved”
- Row credited: “Credited — <date>”
- Row voided: “₱X recovered of ₱Y” + shortfall.

### C.11 Admin — ledger / settings

- Tile: **Pending release** — “Earned but not yet in a wallet.”
- Tile: **Cancelled before release** — “Never paid; no shortfall.”
- Unrecovered: “Voided awards only — shortfall after a post-release reversal.”
- Hold-days hint as in §9.1.
- Release toggle hint as in §9.1.
- Reversal toggle hint as in §9.2.

---

## Appendix D — State matrix

### D.1 Award status

| From | Event | To | Wallet movement | Order total |
|---|---|---|---|---:|
| — | paid order, eligible | `pending` | none | +amount |
| — | paid order, ineligible | `skipped` | none | 0 |
| `pending` | release due | `credited` | `unilevel` credit | unchanged |
| `pending` | order cancelled | `cancelled` | none | −amount |
| `pending` | release not due | `pending` | none | unchanged |
| `credited` | post-release reversal | `voided` | `unilevel_reversal` debit | −amount |
| `credited` | release replay | `credited` | none (idempotent) | unchanged |
| `cancelled` | release attempt | `cancelled` | none | unchanged |
| `skipped` | any | `skipped` | none | unchanged |

### D.2 Wallet/ledger

| Event | `ewallet_balance` | `withdrawable_balance` | `ewallet_ledger` |
|---|---:|---:|---|
| Pending award created | unchanged | unchanged | none |
| Pending award released | +amount | +amount | `unilevel` credit |
| Pending award cancelled | unchanged | unchanged | none |
| Credited award voided (full) | −amount | −amount | `unilevel_reversal` debit |
| Credited award voided (partial) | −recovered | −recovered | `unilevel_reversal` debit for recovered |

### D.3 Projections

| Figure | Formula |
|---|---|
| `pending_total` | `SUM(amount) WHERE status='pending'` |
| `credited_total` | `SUM(amount) WHERE status='credited'` |
| `awarded_total` | `pending_total + credited_total` |
| `unrecovered` | `SUM(amount - reversed_amount) WHERE status='voided'` |
| `total_funds` (UI) | `ewallet_balance + pending_total` |
| `next_release_at` (UI) | `MIN(delivered_at/completed_at + hold_days)` over pending rows |

---

## Appendix E — Test case list

The full list is in §23.2. The minimum gate before merge:

- ESC-T1, T2, T6, T8, T11, T12, T13, T17, T18, T19, T20, T21, T22, T25, T26, T29, T30.
- All updated existing cases green.
- Full suite and smoke green.
- Manual E4/E5 QA green.

Suggested test class split:

- `UnilevelEscrowTest` — creation, release, cancellation, summary, reconcile (T1–T24).
- `UnilevelReleaseJobTest` — CLI, dry-run, doctor, lock, JSON (T29–T30).
- `MigrationTest` / `InstallTest` — T25–T26.
- `PayoutReminderTest` — T18–T20.
- Existing classes — the rest.

---

## Appendix F — QA scenarios

### F.1 Setup

- Use a disposable development copy.
- Set `unilevel_hold_days = 0` for the fast path; set `unilevel_release_enabled = 1`.
- Prepare the same three-member chain as the Phase 17 QA: peter (buyer), maria (level 1), juan (level 2).
- Fund the buyer's e-wallet; make sure maria/juan have some withdrawable balance so the payout page is usable.
- Record before values: wallet balance, withdrawable, pending release (0), earnings total.

### F.2 C1a — The bonus is pending at payment

- Place a paid order for `QA Repeat Product` (₱300).
- **Expected:** order `Paid`; order card shows ₱25.00 awarded / ₱0.00 released / ₱25.00 pending.
- Maria's wallet: balance and withdrawable **unchanged**; pending release **₱12.50**; total funds **+₱12.50**.
- Maria's earnings repeat tab: one **Pending release** row with the expected release date (or “after delivery + 0 days”).
- No `Repeat-purchase bonus` wallet statement row.

### F.3 C1b — Release after delivery

- As admin: pack → ready to ship → ship → deliver.
- Run `php bin/unilevel_release.php` (or wait for the cron).
- **Expected:** Maria's wallet balance and withdrawable +₱12.50; pending release 0; wallet statement has the `Repeat-purchase bonus` credit; award row **Credited** with a release date; order card ₱25.00 released.
- Juan: same.

### F.4 E4 — Cancel before release

- Place a second paid order; do not deliver it.
- As admin: cancel the order.
- **Expected:**
  - order `Cancelled`;
  - buyer refunded ₱300;
  - Maria/Juan wallets **unchanged** and no statement reversal;
  - earnings row **Cancelled before release** with “no money was paid”;
  - admin order card rows **Cancelled before release**, no wallet links;
  - admin repeat ledger `Unrecovered ₱0.00`, not ₱25.00;
  - no negative balance, no row deleted.
- Run the release job: it must not pay the cancelled awards.

### F.5 E5 — Payout reminder

- With Maria's released bonus in the wallet (or a pending award and some other withdrawable balance), open `/payouts`.
- **Expected:**
  - balance card shows `Pending release` when pending > 0;
  - form alert says the pending amount is not included;
  - requesting more than withdrawable shows the pending explanation;
  - requesting the withdrawable amount succeeds and the success flash names the pending amount (if any);
  - no checkbox/modal blocks the request.
- Screenshot: `docs/qa/evidence/unilevel-escrow/10_e5_payout_reminder.jpeg`.

### F.6 E6 — The hold actually holds

- Set `unilevel_hold_days = 7`.
- Place a new paid order; deliver it; run the release job.
- **Expected:** the award is still **Pending release**; wallet unchanged; next release date is delivery + 7 days.
- Do not wait 7 days; this check proves the hold. The fast path in C1b uses a separate order created with `hold_days=0`.

### F.7 Clean-up

- E4 on a pending order moves no money.
- The release test leaves ₱12.50 in each upline wallet. On a disposable copy this is acceptable; if the copy must be pristine, restore the pre-test backup. Do not hand-edit wallet/award rows.
- Set `unilevel_hold_days` back to the production value; restore the master switch and the other settings.
- Keep the evidence folder.

---

## Appendix G — File map

### G.1 Backend

```text
system/database/migrations/021_unilevel_bonus_escrow.sql   new
system/database/schema.sql                                changed
system/database/install.sql                               regenerated
system/app/Models/UnilevelAward.php                       changed
system/app/Models/UnilevelCycle.php                       changed
system/app/Models/Report.php                              changed
system/app/Services/UnilevelService.php                   changed
system/app/Services/PayoutService.php                     changed
system/app/Services/PayoutException.php                   changed
system/app/Services/ReportService.php                     changed
system/app/Controllers/PayoutController.php               changed
system/app/Controllers/WalletController.php               changed
system/app/Controllers/DashboardController.php            changed
system/app/Controllers/MembershipController.php           changed
system/app/Controllers/EarningsController.php             changed
system/app/Controllers/AdminCommissionController.php      changed
system/app/Controllers/AdminOrderController.php           changed
system/app/Controllers/AdminWalletController.php          changed
system/app/Controllers/AdminMemberController.php          changed
system/app/Controllers/AdminReportController.php          changed
system/app/Services/DatabaseInstaller.php                 changed (counts)
system/bin/wallet_reconcile.php                           changed
system/bin/unilevel_release.php                           new
system/bin/unilevel_release.cmd                           new
system/public/cron/unilevel_release.php                   new
```

### G.2 Member views

```text
system/app/views/member/wallet.php
system/app/views/member/wallet/_buckets.php
system/app/views/member/wallet/_payout_card.php
system/app/views/member/wallet/_statement.php
system/app/views/member/payouts.php
system/app/views/member/payouts/_balance.php
system/app/views/member/payouts/_form.php
system/app/views/member/payouts/_request.php
system/app/views/member/dashboard/_kpis.php
system/app/views/member/dashboard/_wallet_card.php
system/app/views/member/dashboard/_earnings.php
system/app/views/member/membership/_repeat.php
system/app/views/member/earnings/_repeat_stats.php
system/app/views/member/earnings/_repeat_rows.php
system/app/views/member/earnings/_repeat_levels.php
system/app/views/member/earnings/_repeat_header.php
system/app/views/shop/order/_bonus.php
system/app/views/shop/product/_bonus.php
system/app/views/shop/checkout/_bonus.php
```

### G.3 Admin views

```text
system/app/views/admin/orders/_unilevel.php
system/app/views/admin/commissions/_repeat_stats.php
system/app/views/admin/commissions/_repeat_rows.php
system/app/views/admin/commissions/_repeat_header.php
system/app/views/admin/commissions/index.php
system/app/views/admin/wallets/_buckets.php
system/app/views/admin/wallets/_stats.php
system/app/views/admin/wallets/_members.php
system/app/views/admin/members/_wallet.php
system/app/views/admin/reports/_unilevel.php
system/app/views/admin/reports/_unilevel_levels.php
system/app/views/admin/reports/_unilevel_products.php
system/app/views/admin/settings/_commissions.php
```

### G.4 Docs / QA

```text
docs/qa-unilevel-product.md          v2 or addendum
docs/admin-guide.md                  escrow section
docs/cron.md                         fifth job
docs/deploy.md                       migration 021, 56 settings, five jobs
docs/runbook.md                      pending/stuck/rollback
docs/install-reset.md                counts
docs/phase-log.md                    new phase entry
docs/qa/README.md                    new phase row
docs/qa/sign-off-sheet.md            new phase rows
system/README.md                     cron list
dmp/plan-ecowealth-unilevel-repeat-purchase.md   annotate D-04/Q5
dmp/plan-ecowealth-unilevel-bonus-escrow.md      this plan
```

---

## Appendix H — Glossary

| Term | Meaning |
|---|---|
| **A++** | Escrow + combined “total funds” presentation + payout-page escrow reminder (this plan) |
| **Escrow** | The period after payment during which the award is recorded but not in the wallet |
| **Pending release** | Award status `pending`; earned, not yet withdrawable |
| **Released / credited** | Award status `credited`; wallet has the money |
| **Cancelled** | Award status `cancelled`; order cancelled before release; never paid |
| **Voided** | Award status `voided`; released then reversed; shortfall possible |
| **Hold days** | Days after delivery before release; snapshotted on the award |
| **Return window** | The business period after delivery during which a return/cancellation can still reverse the sale |
| **Total funds** | `ewallet_balance + pending repeat-purchase total`; presentation only |
| **Awarded total** | `pending + credited`; what `shop_orders.unilevel_total` stores |
| **Released total** | `credited` only; what the wallet has received |
| **Unrecovered** | `voided` amount minus `reversed_amount`; never includes `cancelled` |
| **Release job** | `bin/unilevel_release.php`; credits due pending awards |
| **D-13 / ESC-07** | The old best-effort reversal policy, now limited to credited awards |

---

*End of plan. Delivered as Phase 18 (post-17 point release); the implementation record, test results
and rollout notes are in [`docs/phase-log.md`](../docs/phase-log.md) and the QA v2 manual in
[`docs/qa-unilevel-product.md`](../docs/qa-unilevel-product.md).*

# Part V — Bounded execution slices and Phase 17 integration

> **Status and use.** This plan is marked implemented and the implementation record is referenced in `docs/phase-log.md` §Phase 18 and `docs/qa-unilevel-product.md` v2. The text below is a retrospective decomposition for audit, reproduction, onboarding or a specifically approved follow-up. It is **not** an instruction to rerun migration `021`, recreate the escrow engine, reset live awards, change the released Phase 17 gates, or move existing credited money back into escrow. Confirm current repository state and signed QA evidence before taking action.

## V.1 Relationship to the Phase 17 repeat-purchase plan

Phase 18 is not a second bonus stream. It modifies the lifecycle of the exact Phase 17 `unilevel_awards` rows. Phase 17 remains authoritative for product-level rates, placement-time base snapshot, payment-time qualification, sponsor-chain walk, Active month calculation, no-compression, buyer exclusion and amount calculation. Phase 18 adds the escrow state and release timing around those same awards.

**One governing distinction:** the award is **earned at `paid`**, but its wallet credit is **released after delivery + the hold**. The Phase 17 payment-month, monthly Active snapshot and order-awarded total do not move to the release month.

## V.2 Shared contracts with Phase 17

| Concern | Phase 17 source of truth | Phase 18 responsibility | Compatibility rule |
|---|---|---|---|
| Schema | `020_unilevel_repeat_purchase.sql`, `unilevel_awards`, `unilevel_activity`, `unilevel_periods`, `product_unilevel_levels`, 31 tables / 54 settings | `021_unilevel_bonus_escrow.sql` extends the existing award table and adds two settings | Migration 021 only follows 020; final install remains 31 tables / 56 settings; no new table |
| Award generation | `UnilevelService::onOrderPaid()` called from e-wallet `OrderService` and manual `PaymentService::verify()` | Keeps same hook and calculation, changes new award status to `pending` and snapshots `hold_days` | No new award engine and no second hook |
| Base/rate economics | Base is snapshotted at placement; rates read at payment; rates/base/amount/level/recipient are stored on each award | Leaves those values frozen while pending | Never calculate a release amount from live product data |
| Active/month clock | `UnilevelCycle` evaluates the previous calendar month's personal purchases; paid event is the earning month | Monthly award total becomes `pending + credited`; Active test remains unchanged | Release month never replaces earning month |
| Ledger and wallet | `WalletService` supports `unilevel` and `unilevel_reversal`; wallet balance invariants and payout path | Uses the existing `unilevel` credit only at release | No `pending` wallet ref type, third bucket or wallet balance alteration |
| Cancellation | Phase 17 best-effort reversal for credited awards | Always cancel `pending` first; use credited reversal only when switch permits | `unilevel_reversal_enabled=0` cannot leave a pending award on a cancelled order |
| Presentation | Product, checkout, member order, earnings, dashboard, admin ledger/report surfaces | Adds pending/released/cancelled states, pending liability and total-funds projections | Existing product/team-chain language remains; cash timing wording is updated |
| Reconciliation | `UnilevelService::reconcile()` and `wallet_reconcile.php` compare award ledger with wallet ledger | Adds pending/cancelled checks and order total equation | Pending and cancelled are not wallet credits or reversals |

### Combined dependency graph

```text
Phase 17 (released baseline)
17A ─▶ 17B ─▶ 17C ─┬─▶ 17D ─┐
                  ├─▶ 17E ─┼─▶ 17G / p17g-release
                  └─▶ 17F ─┘
                           │
                           ▼
Phase 18 (implemented follow-on)
18-WP1 Escrow foundation
       │
       ▼
18-WP2 Release + cancellation + reconcile/month-close + scheduled runner
       │
       ├────────────────┬────────────────┐
       ▼                ▼                │
18-WP3 Member A++   18-WP4 Admin/reporting
       └────────────────┴────────────────┘
                        │
                        ▼
                 18-WP5 Docs / QA / release record
```

WP3 and WP4 can be separate only after the Phase 18 data/query/service contract is stable. `UnilevelAward`, `UnilevelService`, `Live`, shared controllers and schema/install files are single-owner integration hotspots. Do not run two slices that edit one hotspot concurrently.

## V.3 Non-negotiable integrated invariants

1. **020 precedes 021.** Phase 18's `021_unilevel_bonus_escrow.sql` is additive and idempotent on a database already at migration 020. The final `schema.sql` and generated `install.sql` must match the migrated structure and settings.
2. **No third wallet bucket.** `ewallet_balance` remains spendable and `withdrawable_balance` remains cashable. Pending is stored only in `unilevel_awards` and projected to UI/reporting.
3. **Paid is the earning trigger.** The same two paid paths run `onOrderPaid()`. The product base is the placement snapshot; the ladder rate and recipient qualification are decided at payment.
4. **No money moves at pending creation.** A new pending award has no `ledger_id`, no `credited_at`, and no wallet credit. The member can see it as earned but cannot spend or withdraw it.
5. **Release predicate is strict.** `pending`; order `delivered` or `completed`; not cancelled; a delivery/completion timestamp exists; timestamp plus that award's snapshotted `hold_days` has elapsed. No automatic release for shipped-but-undelivered orders.
6. **Hold days are a per-award snapshot.** `unilevel_hold_days` defaults to 7, validates 0–365, and applies to new awards only. A later setting change cannot move a pending award's due date.
7. **The release toggle is independent.** `unilevel_release_enabled=0` pauses releases without losing the pending obligation. The global `unilevel_enabled` switch does not strand already earned awards.
8. **Cancellation always cancels pending first.** Do this before checking `unilevel_reversal_enabled`. Pending cancellation has no wallet debit/reversal; then the existing best-effort credited reversal can run if enabled.
9. **Lock order is order → awards → wallets.** Release and cancellation lock the order first, then pending awards. Sort recipients by ascending `user_id` before wallet locks. One transaction per order; re-check under lock.
10. **Awarded total and released total differ.** `shop_orders.unilevel_total` and `UnilevelCycle::close()` award total use pending + credited. Wallet/released totals use credited only. “Unrecovered” uses voided shortfall only; cancelled is never unrecovered.
11. **Legacy awards are grandfathered.** Existing credited/voided/skipped status is not changed; existing credited/voided rows receive `credited_at=created_at`, and existing hold is zero. No historical orders are converted to pending.
12. **Member “Total funds” is presentation only.** `Total funds = ewallet_balance + pending repeat-purchase total`. It is not a wallet column, available balance, payout cap or accounting bucket.
13. **Payout reminder remains informational.** Payout maximum still derives only from `withdrawable_balance`; unrelated eligible payouts remain allowed.
14. **No new route or payment rule.** Existing pages and route inventory remain; only existing surfaces and query parameters are extended as described in the source plan.
15. **The Phase 17 economics stay frozen.** No buyer level 0, no direct/indirect split, no compression, no change to per-level centavo rounding, no rate/base recalculation, no Active rule change.

## V.4 Five work packages, split into 20 bounded slices

Each slice is an assignment boundary, not a new phase or release gate. The official Phase 18 implementation and QA record remains the authority. For any reopened defect, preserve the source plan's five-work-package sequence and original acceptance criteria.

### Work package 1 — Escrow foundation (4 slices)

| Slice | Scope and output | Reads / verifies | Boundary |
|---|---|---|---|
| **18-WP1-S1 — Migration 021** | Add `hold_days`, nullable `credited_at`, the pending/credited/skipped/voided/cancelled status enum, supporting indexes, grandfathering backfill and two settings | §12–14.2; Appendix A; ESC-01–ESC-13; ESC-T25 | No new table; migration is additive/idempotent and assumes migration 020 already ran |
| **18-WP1-S2 — Fresh-install parity** | Mirror final award schema/settings in `schema.sql`, regenerate `install.sql`, update installer count 54→56 and migration/install parity tests/docs counts | §14.3–14.4; ESC-T26; MigrationTest, InstallTest, SeedParityTest, bundle `--check` | Keep 31 tables; do not hand-edit generated install bundle or duplicate settings |
| **18-WP1-S3 — Award model state contract** | `UnilevelAward` status constants/labels; pending default/hold snapshot; `markCredited()`, `cancel()`, pending/credited-aware order total; query totals/stats/breakdown/CSV/pending summary | §15.1–15.11; Appendix D; ESC-T1–T3, T11–T12, T17, T21–T28 | `void()` remains credited→voided; cancelled is distinct and never a reversal |
| **18-WP1-S4 — Payment creates escrowed awards** | Change `UnilevelService::onOrderPaid()` to create pending rows, snapshot hold days, omit wallet credit and ledger linking, keep paid-time calculation/order stamp/live touches | §6.1, §16.1, §12–14 of the Phase 17 plan; ESC-T1–T3 and grandfathering tests | Keep the same award economics and paid-month attribution; do not mark credited at payment |

### Work package 2 — Release, cancellation and operations (5 slices)

| Slice | Scope and output | Reads / verifies | Boundary |
|---|---|---|---|
| **18-WP2-S1 — Per-order release transaction** | `releaseForOrder()`; order-first lock; delivered/completed/date predicate; per-award due check; wallet credit and `markCredited()`; audit and surface touches | §6.2, §7, §16.2, Appendix B; ESC-T4–T10, T14–T16 | Existing `unilevel` credit only; no requalification/rate lookup; respect snapshotted hold |
| **18-WP2-S2 — Due-order batch selection** | `releaseDue()` candidate query, unique order grouping, limit, per-order transactions, per-order error isolation, idempotent retry | §16.3; §17.2–17.6; ESC-T8–T10, T29–T30 | Never wrap the entire batch in one transaction; service rechecks every candidate under lock |
| **18-WP2-S3 — Cancellation and race safety** | Split pending cancellation from credited reversal; update `reverseForOrder()` result/audit/touches; run pending path even when reversal toggle is off; verify order-first lock races | §6.3–6.5, §8, §16.4; ESC-T11–T15, T21 | `pending → cancelled` no debit/no reversal; `credited → voided` retains best-effort shortfall behavior |
| **18-WP2-S4 — Reconciliation and monthly close** | Extend `UnilevelService::reconcile()`, `wallet_reconcile.php`, `UnilevelCycle::close()`, reset/integrity output and tests | §13, §16.6–16.7, §20; Appendix D; ESC-T21–T24 | Wallet credit totals compare to credited/voided history; order and monthly award totals include pending+credited; periods remain immutable |
| **18-WP2-S5 — Release runner and operational gate** | CLI, Windows `.cmd`, web entry, CronLock/CronAccess, logs, schedule, limit/order/dry-run/doctor/JSON/quiet options, runbook and operations tests | §17.1–17.8, §24.3, §24.5; ESC-T29–T30 and QA release/doctor scenarios | Web entry delegates to CLI; no second business logic; no manual force release in the first pass |

### Work package 3 — Member A++ presentation (4 slices)

| Slice | Scope and output | Reads / verifies | Boundary |
|---|---|---|---|
| **18-WP3-S1 — Member pending projections** | Pending summary API and table-exists guard; pending count/amount/next release/undelivered/overdue/release-enabled; controller data contracts | §15.11, §18.1–18.5, §21; ESC-T17–T18 | Pending figures are read-only projections; no additional wallet column or N+1 query |
| **18-WP3-S2 — Wallet, dashboard, membership and earnings** | Wallet balance/pending/total-funds lines; dashboard credited/pending/total; membership copy; earnings pending tile, next release, status rows and level breakdown | §10.1–10.5, §18.1–18.4, §18.6; Appendix C; member display tests | Preserve meanings of wallet balance, withdrawable, credited and Active; hide pending/total when pending is zero as specified |
| **18-WP3-S3 — Payout reminder end-to-end** | Payout balance summary; informational form alert; over-limit exception text; success flash; open request/history; optional feed changes | §11, §18.5, §18.6; Appendix C.2–C.6; ESC-T18–T20 | `max_amount` remains withdrawable only; never block unrelated eligible payout or add a mandatory checkbox/modal |
| **18-WP3-S4 — Order/product copy, live refresh and responsive QA** | Buyer/admin-visible product/checkout/order timing copy where assigned to member UI, live touch verification, 390px regression and member QA evidence | §10.4, §10.6, §21, §23–24; Appendix C.7–C.9; QA E4/E5 | Product copy says earned at payment and released after return window; never promise a date before delivery |

### Work package 4 — Admin and reporting (4 slices)

| Slice | Scope and output | Reads / verifies | Boundary |
|---|---|---|---|
| **18-WP4-S1 — Admin repeat ledger and order state** | Admin commissions pending/cancelled/released filters and rows; status/date/hold details; order card states and wallet-link rules | §19.1–19.3, §22.2–22.4; Appendix C.10–C.11; ESC-T27–T28 and admin order tests | Pending/cancelled rows have no wallet link; credited and voided keep their historical meaning |
| **18-WP4-S2 — Admin wallet, member and reports** | Admin wallet/member pending lines; pending liability KPI; `Report` unilevel summary/level/product data; report CSV columns | §19.4–19.6, §20; §22; admin/report tests | Pending liability is separate from wallet float/locked; “Total credited” remains released money |
| **18-WP4-S3 — Settings UI and config audit** | Add hold days and release toggle to AdminSettingsController/Commissions card; update master/reversal hints, validation, audit and Live settings touch | §9, §19.7; §22.2–22.4; settings ESC tests | Two new settings only; 7 default/range and independence contract preserved; release toggle does not gate pending cancellation |
| **18-WP4-S4 — Optional queue detail and admin gate** | Optional pending total in payout detail/drawer without queue-wide N+1; admin regression, CSV open check and QA evidence | §11.6, §19.8, §23–24 | Optional support enhancement only; do not turn it into a payout decision or expose another member's data |

### Work package 5 — Documentation, QA and rollout (3 slices)

| Slice | Scope and output | Reads / verifies | Boundary |
|---|---|---|---|
| **18-WP5-S1 — Operator and developer documentation** | Deploy/install counts, cron schedule, runbook, admin guide, README, phase log, Phase 17 D-04 annotation and exact copy/glossary updates | §24.1–24.6, §25; Appendix C/H | Document the already implemented behavior; no new business logic in docs work |
| **18-WP5-S2 — QA v2 and integrated acceptance** | Migration/install, pending, delivery release, cancellation, payout, admin/member surfaces, reconcile, regression suite and evidence pack | §23, §27; Appendix E/F; Phase 17 gate evidence; QA `E4`/`E5` | Run tests on disposable data; use `hold_days=0` for fast release path and still prove seven-day pending behavior without waiting seven days |
| **18-WP5-S3 — Rollout/rollback and release record** | Two-stage deploy record; verify first pending and first release; rollback safety; phase-log/QA sign-off; reconcile current release/APP_PHASE evidence | §25, §27–29, final implementation record | Preserve live pending obligations on rollback; do not revert the pending-cancellation path or delete award history |

## V.5 Required end-to-end order of work

For a clean reproduction of the original build (not for re-execution on the already released system), follow this order:

1. Establish a known-good Phase 17 baseline at `p17g-release`; verify migrations 001–020, 31/54 before Phase 18, Phase 17 tests, and QA/sign-off evidence.
2. Apply WP1 as one additive schema/model/payment-state workstream. Validate migration 021 on scratch and existing 020 databases; ensure old credited rows remain credited.
3. Apply WP2 and prove release/cancellation/reconcile as one money-safety unit before building the presentation layers.
4. Freeze pending-summary, status, totals, release-date and Live touch contracts. Only then work on WP3 and WP4; serialize any edits to shared files.
5. Complete WP5 only after the member/admin surfaces, operations and money reconciliation all pass.
6. Do not treat the five work packages as extra official phase numbers. Preserve the actual Phase 18 phase-log and QA implementation record.

## V.6 Slice completion and hand-off contract

For each assigned slice:

1. Read the connected contract in this Part V and the corresponding Phase 17 Part V cross-plan contract. Then read the slice row, its parent subsection, the explicitly named normative sections and only the needed implementation files.
2. Inspect the current implementation, DB migration state, Phase 17 release tag, Phase 18 phase-log entry and QA v2 evidence before changing anything. The default action for an already-correct released slice is to report “implemented and verified,” not to rewrite it.
3. Change only files owned by this slice. `UnilevelAward`, `UnilevelService`, `schema.sql`, `install.sql`, settings controllers, shared views, `Live`, payout code and reconciliation scripts require one owner at a time.
4. Preserve the source's five work-package ordering and all money invariants. If an additional change is necessary, record it as a dependency or separately approved follow-up.
5. Run focused tests and report exact commands, case counts, exit codes and evidence. Run the full suite and complete QA gate only for an explicitly reopened implementation.
6. Return: slice ID; current state; files inspected/changed; database state; invariant checks; tests/results; deviations; risks; next permitted slice. Stop after the report.

### Reusable agent prompt

```text
You are assigned ONLY {SLICE_ID} in the Phase 18 Repeat-purchase Bonus Escrow plan.

This plan extends the already released Phase 17 Unilevel Repeat-purchase Bonus.
Read the cross-plan contract in Part V of BOTH sliced plans. Phase 18 is marked
implemented. First inspect the repository, migration state, Phase 17 release tag,
Phase 18 phase-log and QA v2 evidence. If the slice is already implemented and
correct, audit and report it; do not recreate it or re-run a live migration.

Read only:
- this slice row and its parent Phase 18 section;
- the exact specification/implementation sections listed for the slice;
- Phase 17 normative sections needed to preserve the original earning contract;
- the current implementation files owned by this slice.

Never change the Phase 17 award economics or qualification. An award is earned
at paid, but new Phase 18 awards are pending and have no wallet movement until
its delivery-based hold expires. Preserve the snapshotted base/rate/recipient,
payment-month Active clock, no-compression, buyer exclusion and centavo rounding.
Preserve the no-third-bucket wallet model, pending cancellation priority, order-
first lock order, idempotent release, grandfathered credited awards, order/month
award totals, reconciliation equations and payout limit semantics. Do not add
routes, ref types, tables, phase tags or APP_PHASE changes without an explicit
recorded decision.

Change only this slice's allowed files. Run focused tests and state exact commands,
counts and exit codes. If a shared file, earlier phase or out-of-scope contract
must change, stop and report the dependency. Return the slice completion report
and STOP.
```

---

# Appendix I — Cross-plan contract and hand-off

## I.1 State transition map

```text
Phase 17 paid event / Phase 18 onOrderPaid
                  │
                  ▼
              pending
             /       \
            /         \
   order cancelled    delivered/completed + hold elapsed
          │                         │
          ▼                         ▼
      cancelled                  credited
   (no wallet row)          (unilevel wallet credit)
                                     │
                                     │ later eligible reversal/return
                                     ▼
                                   voided
                              (unilevel_reversal debit;
                               best-effort shortfall)

skipped remains a non-money eligibility record.
```

## I.2 Status and aggregate semantics

| Concept | Formula / meaning | May be spent or paid out? |
|---|---|---|
| Pending release | Sum of `unilevel_awards.amount` where `status='pending'` | No |
| Released/credited | Sum where `status='credited'` | Yes, through existing wallet buckets/payout rules |
| Total awarded | Pending + credited | No, it is an award/projection total |
| Total funds (member presentation) | `ewallet_balance + pending release` | Not wholly spendable; pending portion is excluded |
| Unrecovered | Shortfall on `voided` awards only | Not a wallet balance |

The `shop_orders.unilevel_total` value is `SUM(pending + credited)` for the order. The closed month’s `award_total` is likewise the earned amount (pending + credited) for that month. The wallet statement contains only real wallet movements.

## I.3 Release and cancellation must be mutually safe

- Both operations lock the order first.
- Release confirms delivery/completion, timestamp, cancellation status, award status and snapshotted hold under lock.
- Cancellation marks every pending award cancelled before the reversal switch is evaluated.
- If release wins the order lock, cancellation sees credited rows and follows the credited reversal policy. If cancellation wins, release sees cancelled and writes nothing.
- Recipient wallet locks are taken in ascending recipient ID order; one order is one transaction.

## I.4 Current acceptance evidence to look for

The source plan identifies `docs/phase-log.md` §Phase 18 and `docs/qa-unilevel-product.md` v2, especially E4. The joint audit should additionally reconcile: migration 021 state and fresh-install parity (31/56); a newly pending paid order; no wallet movement while pending; due release after delivery; cancellation before release with the reversal switch on and off; payout max unaffected by pending; admin/member status visibility; monthly award total inclusion; replay/race idempotency; and grandfathering of already credited rows.

## I.5 Deferred work requiring separate approval

Manual early release, partial returns, per-item return reversals, a new held-balance wallet bucket, historical backfill, or any modification to rate/qualification economics is not part of Phase 18's five work packages. The future returns contract in §26.2 is a constraint for a later feature, not authorization to implement it here.
