# Phase 13 QA — Payouts

| | |
|---|---|
| **Phase** | 13 — withdrawal requests (GCash, Maya, USDT TRC20/BEP20), the hold, the service fee, the USDT conversion and the admin queue |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §5.9, §7 Phase 13, Appendix B (`payout_requests`) |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `superadmin / Super@123` · `juan`, `maria`, `peter`, `sarah`, `david` / `Demo@1234` |
| **Checks** | 8 (QA13-01 … QA13-08) |
| **Time** | 25–35 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-13/` |

## What this phase is meant to prove

A member can cash out the **withdrawable** part of their e-wallet without staff help: pick a channel, give
the account, enter an amount and submit. The amount leaves the wallet immediately (a **hold**), waits in the
admin queue, and ends either paid, or refunded when staff reject it. The fee and — for USDT — the conversion
are computed from settings on the server, never from the browser.

Five rules matter most:

1. **The hold is the debit.** Requesting debits the wallet once (`ref_type = 'payout'`); completing moves no
   money (it was already taken), and rejecting refunds exactly the amount held. There is never a second
   debit.
2. **Only the withdrawable bucket is payable.** A member with ₱1,500 total and ₱600 withdrawable can ask for
   ₱600, not ₱1,000 — the refusal says “You can withdraw up to ₱600.00 — the withdrawable part of your
   balance.”
3. **One request at a time.** While a request is `pending` or `approved` the form is closed; a second
   request is refused (and two concurrent submits cannot both pass the check).
4. **The maths is auditable.** The fee percentage, the ₱/USDT rate and the gas fee are stored **on the
   request**, so a later settings change cannot rewrite a queued request. A USDT payout is refused while
   `usdt_rate` is 0 — the browser cannot supply a rate.
5. **Two pairs of eyes.** Only an admin/superadmin can approve, reject or complete, and nobody can process
   their own request. Every decision is a conditional status change (so a double click is refused) and an
   audit row.

**Already proven automatically:**

- P13-T1 a valid request holds the amount, writes one `payout` debit and a pending row · P13-T2 below
  `min_payout` is refused and writes nothing · P13-T3 above the **withdrawable** bucket is refused even when
  the total balance covers it · P13-T4 a second request while one is open (pending **or** approved) is
  refused, and opens again after the first is settled · P13-T5 the service-fee percentage is taken from the
  amount, stored, and rounded to centavos · P13-T6 a USDT request converts with the server-side rate and
  stores the rate/gas snapshot (and is refused while the rate is unset) · P13-T7 approve records the staff
  account and moment with no wallet movement · P13-T8 complete settles without a second debit · P13-T9 a
  rejection refunds the hold exactly once, restores both buckets, records the reason and refuses a second
  rejection · P13-T10 a member cannot process any payout and an admin cannot process their own · P13-T11
  double approve, complete-before-approve and decisions on a settled request are refused · P13-T12
  reconciliation matches every request to its hold and every rejection to its refund · P13-T13 the queue
  filters, status counts, lifetime totals and per-member figures agree.
- Extra automated cases: the channel must exist and be enabled; the payout account is validated with the
  same rules as the profile form (and a typed phone number is normalised); a note is trimmed/collapsed/
  capped; a fee that swallows the net is refused; `can_request` closes at the floor and while a request is
  open; **two real connections** prove a second request cannot hold money the first one took; and a payout
  refund still lands when an **order refund already uses the same id**.
- `PayoutViewTest` renders the member page and the queue (12 cases); `PayoutLiveTest` pins the two live
  surfaces, the four fragments, the wallet-revision mixing, the held request region and the “a refusal bumps
  nothing” rule (6 cases). The suite is **637 cases**.
- The HTTP smoke script walks a real GCash payout over HTTP (fee 10%, ₱1,000 held, approve, complete, then a
  ₱600 request rejected and refunded), the three refusals, the USDT-closed refusal, the member/queue guards
  and the finance report. **161 checks**.
- `php bin\wallet_reconcile.php` exits 0 and now reconciles **payouts** as well as wallets, commissions and
  transfers.

> **Before you start:** Phases 1–12 signed off, the demo tree seeded (`database/seeds/005_demo_tree.sql`) and
> migration `017_payouts.sql` applied. The automated suites must be green: `php tests\run.php` → **637
> cases**, `powershell -NoProfile -File tests\smoke.ps1` → **161 checks**. Note juan's wallet balances before
> you start — the checks move real demo money.
>
> The payout settings are seeded but **not yet editable in the UI** (Phase 15 owns the full settings page);
> the checks below set them with SQL, exactly as an operator would today.
>
> The screenshots named in the *Evidence* rows are captured during the manual pass
> (`docs/qa/evidence/phase-13/`); the `automated:` citations are already green from the PHP suite and the
> HTTP smoke run.

---

## A. Requesting a payout

### QA13-01 — A member withdraws ₱1,000 by GCash with a 2% fee

| | |
|---|---|
| **Steps** | 1. `UPDATE settings SET value = '500' WHERE key_name = 'min_payout';` and `UPDATE settings SET value = '2' WHERE key_name = 'service_fee_gcash';`. 2. Sign in as `juan`, open **Payouts** (`/payouts`). 3. Choose **GCash**, confirm the prefilled number, enter `1000.00`, submit. 4. Open `/wallet`. |
| **Expected** | Success flash: “₱1,000.00 is held for payout by GCash to 0917…. The fee is ₱20.00, so ₱980.00 will be sent. An administrator will process it shortly.” The withdrawable card drops by ₱1,000 (both buckets), the **Withdrawal in progress** card replaces the form and shows the held amount, the 2% fee and the ₱980 net, and one `payout` debit appears in the statement. `SELECT * FROM payout_requests ORDER BY id DESC LIMIT 1;` → `pending`, `service_fee_pct` 2.00, `service_fee_amount` 20.00, account stored without spaces. |
| **Evidence** | `phase-13/member-payouts.png`, `phase-13/payout-open.png` |

### QA13-02 — The refusals write nothing

| | |
|---|---|
| **Steps** | 1. As `juan` (withdrawable ₱600, minimum ₱500), try `100.00`. 2. Try `999999.00`. 3. Try a second request while the first is open. 4. Choose **GCash** and type `12345` as the account. 5. As admin, switch the GCash channel off (`payout_gcash_enabled = 0`) and try again. 6. After each attempt: `SELECT COUNT(*) FROM payout_requests;` and juan's buckets. |
| **Expected** | Each attempt returns to the page with a red banner and the message on the field at fault: the minimum, the withdrawable ceiling, “You already have a pending payout request…”, the account format, and “GCash payouts are not available right now.” (the channel is shown as unavailable on the form too). No new request row, no ledger row, and the buckets do not move. |
| **Evidence** | `phase-13/refusals.png` |

### QA13-03 — USDT converts on the server

| | |
|---|---|
| **Steps** | 1. With `usdt_rate = 0`, look at the form: the two USDT channels are disabled and read “Unavailable until the ₱ per USDT rate is set.”; try to post one anyway. 2. Set `usdt_rate = 56`, `service_fee_usdt_trc20 = 5`, `usdt_trc20_gas_fee = 2.50`. 3. Request `1000.00` by USDT TRC20 to a valid `T…` address. 4. Change `usdt_rate` to `60` and reload the page. |
| **Expected** | The unset-rate attempt is refused without a row. The real request stores `usdt_rate = 56.0000`, `usdt_gas_fee = 2.5000`, `usdt_amount = 14.4643` — that is `(1000 − 50 fee − 2.5 × 56) ÷ 56`. The member history and the admin queue show **14.4643 USDT**. Changing the setting afterwards does **not** change the queued row. |
| **Evidence** | `phase-13/usdt-request.png`, `phase-13/usdt-closed.png` |

## B. Processing the queue

### QA13-04 — Approve records the decision, not the money

| | |
|---|---|
| **Steps** | 1. Signed in as `admin`, open **Payouts** (`/admin/payouts`). 2. Find juan's pending row; read the stat cards and tabs. 3. Click **Approve** and confirm. 4. Reload, then open the **Approved** tab. |
| **Expected** | The pending tab lists the request with juan's link, the method/account, the gross ₱1,000, the fee and the net, and the actions. Confirm shows what will be released. After approving: `status = 'approved'`, `processed_by` = the admin, `processed_at` set; **juan's wallet does not move** (the hold was the debit) and there is still exactly one `payout` ledger row. The request moves to the **Approved** tab, whose cards read **Approved — sending**. |
| **Evidence** | `phase-13/admin-queue.png`, `phase-13/approved.png` |

### QA13-05 — Complete settles it without a second debit

| | |
|---|---|
| **Steps** | 1. Still in the **Approved** tab, click **Mark complete** (after “sending” the funds in your head — the app does not move real money). 2. Open the **Completed** tab, then juan's `/wallet` statement. |
| **Expected** | Confirm names the amount that should have been sent (USDT for a crypto payout, the net for a peso one). The row becomes `completed`, keeps `processed_by/at`, offers no further action, and juan's wallet is unchanged since the hold: `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'payout' AND ref_id = <id>;` → **1**. |
| **Evidence** | `phase-13/completed.png`, `phase-13/statement-hold.png` |

### QA13-06 — Reject refunds exactly once

| | |
|---|---|
| **Steps** | 1. As `juan`, make a second request (the form is open again) for ₱600. 2. As admin, type a reason in the row's **Rejection reason** box and click **Reject**. 3. Look at `/payouts` as juan and at his `/wallet`. 4. Try to reject the same request again. |
| **Expected** | Juan's withdrawable balance returns to exactly what it was before the request (the refund restores both buckets to the split the hold took). The history row reads **Rejected** with the reason under it, and the statement shows a `Refund` credit named “Payout request #<id> rejected — …”. A second rejection is refused by the status guard and writes nothing (the ledger's idempotency key would refuse a second refund as well). |
| **Evidence** | `phase-13/rejected.png`, `phase-13/refund-credit.png` |

## C. Safety, reporting and reconciliation

### QA13-07 — Nobody processes their own money

| | |
|---|---|
| **Steps** | 1. As `juan`, open `/admin/payouts` → 403. 2. Sign in as `admin`, make a payout request for yourself (the `member` guard admits staff), then try to approve it from the queue. 3. Sign in as `superadmin` and complete it. |
| **Expected** | A member gets the branded 403 page. The admin’s own row refuses with “You cannot process your own payout request.” A different staff account can process it; every decision is an `audit_log` row (`payout.request`, `payout.approve`, `payout.reject`, `payout.complete`) carrying the actor, the before/after status and the amount. |
| **Evidence** | `phase-13/own-payout-refused.png`, `phase-13/audit-rows.png` |

### QA13-08 — The queue totals and the finance report

| | |
|---|---|
| **Steps** | 1. As admin, visit each tab (**Pending**, **Approved**, **Completed**, **Rejected**, **All requests**) and note the counts, the gross/fee/net totals and the four stat cards. 2. Search by a member, then by an account, then pick a date range that matches nothing. 3. Read the facts line (minimum, USDT rate, TRC20/BEP20 fee and gas). 4. Run `php bin\wallet_reconcile.php` and `php bin\wallet_reconcile.php --json`. |
| **Expected** | Each tab narrows the rows and the totals line under the header; the stat cards read **Held in open requests**, **Waiting for approval**, **Approved — sending** and **Paid out** (with the net and the fees earned). A filter that matches nothing shows the branded empty state; an unknown status falls back to **All requests**. The facts line matches the settings (and says “not set — USDT payouts are closed” when the rate is 0). The report exits **0** and its **Payouts** section says “every request has its hold, and every rejection its refund”, with the held total and any orphan legs as a count. |
| **Evidence** | `phase-13/queue-tabs.png`, `phase-13/reconcile.png` |

---

## Sign-off

All eight checks were exercised through the automated suites and the HTTP smoke run on 2026-11-10: a real
GCash payout end to end (hold → approve → complete), a second request rejected and refunded, the three
refusals, the USDT-closed refusal, the two guards, the queue tabs and the finance report.

| Check | Result | Evidence file |
|---|---|---|
| QA13-01 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T1/T5, smoke P13-T1, PayoutViewTest` |
| QA13-02 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T2/T3/T4 + channel/account cases, smoke P13-T2/T3/T4` |
| QA13-03 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T6 (+ unset-rate case), smoke P13-T6` |
| QA13-04 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T7, smoke P13-T7` |
| QA13-05 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T8, smoke P13-T8` |
| QA13-06 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T9 (+ id-collision case), smoke P13-T9` |
| QA13-07 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T10/T11, smoke P13-T10` |
| QA13-08 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T12/T13, PayoutLiveTest, smoke P13-T12` |

| | |
|---|---|
| **Tester** | Automated QA pass — DeepSeek Harness (DSH) |
| **Date** | 2026-11-10 |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked |
| **Automated** | **637 / 637** PHP cases · **161 / 161** smoke checks · `php bin\wallet_reconcile.php` → **exit 0**, no mismatches |
| **Notes** | 8 / 8 checks passed, 0 failed, 0 blocked. Manual browser sign-off is the remaining item, as for Phases 1–12. |

Two behaviours are worth reading twice, because they are deliberate:

- **The hold is taken at request time (D17).** The reference debited the wallet when an admin marked the
  payout completed; that let a member spend money already promised to a payout. Here the debit happens when
  the member asks, so `complete` moves nothing — it only records that the funds were sent off-platform. That
  is why the queue's **Paid out** card and the ledger agree without touching wallets at completion.
- **A payout refund is identified by its note, not only by `ref_id`.** `refund` is shared with Phase 7's
  order refunds and the two id sequences overlap for a member. When the numeric id is already taken, the
  payout refund is written with `ref_id = NULL` and names the request in its note; otherwise the ledger's
  `(user_id, ref_type, ref_id, type)` idempotency key could mistake an order refund for the payout refund and
  skip it, leaving the member's money held. `PayoutService::reconcile()` matches on the note for that reason.

Tester: Automated QA pass — DeepSeek Harness (DSH)  ·  Date: 2026-11-10  ·  Signature: DSH
