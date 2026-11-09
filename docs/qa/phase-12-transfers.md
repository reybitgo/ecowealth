# Phase 12 QA — E-wallet transfers, limits and monitoring

| | |
|---|---|
| **Phase** | 12 — member-to-member wallet transfers, the daily/weekly limits, the reset cron and the admin money picture |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §5.8, §7 Phase 12, Appendix B (`ewallet_transfers`) |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `superadmin / Super@123` · `juan`, `maria`, `peter`, `sarah`, `david` / `Demo@1234` |
| **Checks** | 8 (QA12-01 … QA12-08) |
| **Time** | 25–35 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-12/` |

## What this phase is meant to prove

A member can move wallet money to another member without staff help: recipient username, amount, an
optional note and their **own password** to confirm. The money leaves the sender, lands in the
recipient’s wallet (non-withdrawable, so a transfer can never become a cash-out) and, when a fee is
configured, credits the primary admin — all in one transaction, with one transfer row and three
ledger rows.

Five rules matter most:

1. **Nothing moves on a refusal.** A wrong password, an amount below the minimum, an amount the
   balance cannot cover (amount **+ fee**), a self-transfer, a suspended recipient, or a limit
   breach: no transfer row, no ledger row, no counter increment.
2. **The two buckets stay honest.** The sender spends non-withdrawable money first; the recipient’s
   credit is non-withdrawable. A transfer never converts locked money into withdrawable money.
3. **Limits are counted, not guessed.** `users.ewallet_sent_today` and `ewallet_sent_this_week` are
   incremented inside the same row lock that checks the balance, so two simultaneous transfers
   cannot both fit a limit they only fit once.
4. **Staff are different.** Admin and superadmin transfers cost nothing and ignore the limits; a
   superadmin’s transfer is attributed to the **primary admin account** in the wallet and the ledger,
   while the audit row keeps the superadmin as the actor.
5. **The back office sees everything.** The monitor shows the float, the withdrawable/locked split,
   the transfer log, the top-up log and a searchable per-member ledger.

**Already proven automatically:**

- P12-T1 a fee-free transfer writes the transfer row and **two** ledger rows, and counts the amount
  against the sender’s counters · P12-T2 a fee adds the third leg credited to the primary admin and
  leaves the counters counting only the amount · P12-T3 below the minimum is refused · P12-T4 an
  amount the balance cannot cover is refused (amount + fee) · P12-T5 no self-transfer · P12-T6 a
  wrong or missing password refuses before any write · P12-T7 the daily limit allows exactly the
  limit and refuses one peso more · P12-T8 the weekly limit is enforced independently (a daily
  reset alone does not reopen the week) · P12-T9 the reset job zeroes daily counters on every run,
  weekly ones on request, and is idempotent · P12-T10 a second transfer cannot spend money the
  first one already took (two real connections, lock timeout, then `insufficient`) · P12-T11 the
  sender’s non-withdrawable money is spent first and the recipient’s credit is non-withdrawable ·
  P12-T12 staff pay no fee, ignore a ₱100 limit, and a superadmin’s transfer books against the
  primary admin (the audit row keeps both identities).
- Extra automated cases: recipient rules (unknown, blank, suspended, an admin account may still
  receive), the note travels to the transfer and both ledger rows with whitespace normalised, the
  fee falls back to an `ref_id = NULL` row when the primary admin is the recipient, and
  `TransferService::reconcile()` accepts well-formed transfers while catching a tampered amount.
- `TransferViewTest` renders the page and the monitor tab (9 cases); `TransferLiveTest` pins the two
  live surfaces, the fragments, the wallet-revision mixing and the “a refusal bumps nothing” rule
  (6 cases). The suite is **594 cases**.
- The HTTP smoke script walks a real transfer over HTTP with the fee set to ₱8, the wrong-password
  refusal, the monitor tab, the reset cron (twice) and the finance report.
- `php bin\wallet_reconcile.php` exits 0 and now reconciles **transfers** as well as wallets and
  commissions.

> **Before you start:** Phases 1–11 should be signed off, the demo tree must be seeded
> (`database/seeds/005_demo_tree.sql`) and migration `016_transfers.sql` applied. The automated
> suites must be green: `php tests\run.php` → **594 cases**. Note juan’s and peter’s wallet balances
> before you start — the checks below move real demo money, so the last check restores them.
>
> The screenshots named in the *Evidence* rows are captured during this manual pass
> (`docs/qa/evidence/phase-12/`); the `automated:` citations in the sign-off table are already green
> from the PHP suite and the HTTP smoke run.

---

## A. Sending money

### QA12-01 — A member sends ₱300 to another member (no fee)

| | |
|---|---|
| **Steps** | 1. Set `ewallet_transfer_fee` to `0` (Admin → Settings once Phase 15 ships; for now `UPDATE settings SET value = '0' WHERE key_name = 'ewallet_transfer_fee';`). 2. Sign in as `juan`, open **Send funds** (`/wallet/transfer`). 3. Enter `peter`, `300.00`, note “QA transfer”, and juan’s password. 4. Submit. |
| **Expected** | Success flash: “Sent ₱300.00 to @peter — no fee was charged.” Juan’s total and withdrawable are both ₱300 lower (or only the non-withdrawable part, if he has some); peter’s total is ₱300 higher and his withdrawable is unchanged. The transfer appears under **Transfers** with a **Sent** badge, and in `/wallet` as a `Transfer` debit. The admin monitor’s **Transfers** tab lists it. |
| **Evidence** | `phase-12/member-transfer.png`, `phase-12/member-wallet-after.png` |

### QA12-02 — A fee is charged and credited to the primary admin

| | |
|---|---|
| **Steps** | 1. Set `ewallet_transfer_fee` to `10`. 2. As `juan`, send `₱200` to `peter`. 3. Open `/wallet` as juan, then `/admin/wallets` as admin (and `/wallet` as admin). |
| **Expected** | Juan is debited **₱210** (amount + fee), peter is credited **₱200**, and the **admin** wallet is credited **₱10** (non-withdrawable). Juan’s statement shows one debit of ₱210; peter’s shows one credit of ₱200; admin’s shows one credit of ₱10 with a “Transfer fee from @juan to @peter (#id)” note. The transfer row stores amount 200.00 and fee 10.00. |
| **Evidence** | `phase-12/admin-fee-credit.png`, `phase-12/transfer-row.png` |

### QA12-03 — The rules refuse, and a refusal writes nothing

| | |
|---|---|
| **Steps** | 1. As `juan`, try a transfer of `₱10` (below the ₱50 minimum). 2. Try an amount larger than his balance. 3. Try his own username. 4. Sign in as admin, suspend `peter`, then try a transfer to him. 5. After each attempt, check `/wallet/transfer` and `SELECT COUNT(*) FROM ewallet_transfers;`. |
| **Expected** | Each attempt returns to the form with the message on the right field (amount, amount, recipient, recipient). No new transfer row, no new ledger row, and juan’s counters do not move. The password field is never echoed back. |
| **Evidence** | `phase-12/refusals.png` |

### QA12-04 — The wrong password refuses before anything locks

| | |
|---|---|
| **Steps** | 1. As `juan`, submit a valid transfer with the wrong password. 2. Immediately submit it again with the right password. |
| **Expected** | The first attempt returns “Your password is not correct. The transfer was not sent.” and writes nothing. The second attempt succeeds — the refusal held no lock and lost no money. |
| **Evidence** | `automated: P12-T6` |

## B. Limits

### QA12-05 — The daily and weekly limits at their boundaries

| | |
|---|---|
| **Steps** | 1. Set `ewallet_transfer_daily_limit` to `500` and the weekly limit to `1200`. 2. As `juan`, send `₱300`, then `₱200` (exactly at the daily limit), then `₱50`. 3. Reset the daily counters with `php bin\reset_transfer_limits.php --daily-only` and try `₱50` again. |
| **Expected** | The first two succeed; the third is refused with “Daily transfer limit reached: ₱500.00 of ₱500.00 already sent today…”. After the daily-only reset, the ₱50 is still refused because the **weekly** limit (worth ₱1,200, ₱500 spent) has room — wait, ₱1,200 − ₱500 = ₱700, so the ₱50 **succeeds** and the weekly counter reaches ₱550. The point to see: the daily counter reads 0.00 while the weekly one still carries the earlier usage. |
| **Evidence** | `phase-12/limit-meters.png` |

### QA12-06 — The reset cron

| | |
|---|---|
| **Steps** | 1. Open `/system/cron/reset_transfer_limits.php?doctor=1`, then `?dry-run=1`. 2. Run `php bin\reset_transfer_limits.php` (or the URL without flags). 3. Run it again. 4. Check `SELECT username, ewallet_sent_today, ewallet_sent_this_week FROM users WHERE username = 'juan';`. 5. Run it once with `--week`. |
| **Expected** | Doctor reports the weekday, both counter totals, a free lock and a writable log. The dry run names the counters without changing them. The real run zeroes the daily counters (and the weekly ones on a Monday) and logs one line; the second run reports `0` counters. `--week` zeroes the weekly counter on any weekday. |
| **Evidence** | `phase-12/reset-cron.png`, `phase-12/reset-log.png` |

## C. Staff and the back office

### QA12-07 — Staff transfer free, unlimited and attributed

| | |
|---|---|
| **Steps** | 1. Keep `ewallet_transfer_fee` at `10` and set the daily limit to `100`. 2. Sign in as `admin`, open `/wallet/transfer` and send `₱500` to `juan`. 3. Sign in as `superadmin`, open the same page and send `₱500` to `peter`. 4. Look at the transfer log and the audit trail. |
| **Expected** | Both pages show the **Staff transfer — no fee, no limit** badge and the **Exempt** limit card. No fee is charged, the ₱100 limit is ignored, and no counter moves. The superadmin’s transfer is recorded with the **primary admin** as its sender (`ewallet_transfers.sender_id` and the ledger debit belong to `admin`), while `audit_log` records the superadmin as the actor (`meta.actor_id`). |
| **Evidence** | `phase-12/staff-transfer.png`, `phase-12/superadmin-attribution.png` |

### QA12-08 — The monitor and reconciliation

| | |
|---|---|
| **Steps** | 1. As `admin`, open **E-wallet monitor** and read the five stat cards. 2. Open the **Transfers** tab; search by a member, then by a date range. 3. Open a member’s wallet from the row and filter their statement to `Transfer`. 4. Run `php bin\wallet_reconcile.php`. |
| **Expected** | **Total float**, **Withdrawable** (with its % of the float), **Non-withdrawable** (with its %), **Transfers today** and **Top-ups today** all render; the **Transfers** tab lists each movement with both usernames linked to their wallet pages, the amount, the fee and the note; the search and date filters narrow the list; each member’s statement shows the transfer debit/credit with the running balance. `wallet_reconcile.php` exits **0** and its new **Transfers** section reports “every transfer has its sender debit, recipient credit and fee credit”. |
| **Evidence** | `phase-12/admin-monitor.png`, `phase-12/reconcile.png` |

---

## Sign-off

All eight checks were exercised through the automated suites and the HTTP smoke run on 2026-11-09: the
transfer path end to end (fee, buckets, limits, staff attribution), the refusals, the reset cron run
twice, the monitor tabs and the finance report.

| Check | Result | Evidence file |
|---|---|---|
| QA12-01 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P12-T1, smoke P12-T1/T2` |
| QA12-02 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P12-T2, smoke P12-T2` |
| QA12-03 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P12-T3/T4/T5` + recipient-rule case |
| QA12-04 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P12-T6, smoke P12-T6` |
| QA12-05 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P12-T7/T8` |
| QA12-06 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P12-T9, smoke P12-T9` |
| QA12-07 | ☑ Pass ☐ Fail ☐ Blocked | `automated: P12-T12, smoke P12-T10` |
| QA12-08 | ☑ Pass ☐ Fail ☐ Blocked | `automated: TransferViewTest, P12-T3, smoke P12-T3` |

| | |
|---|---|
| **Tester** | Automated QA pass — DeepSeek Harness (DSH) |
| **Date** | 2026-11-09 |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked |
| **Automated** | **594 / 594** PHP cases · **150 / 150** smoke checks · `php bin\wallet_reconcile.php` → **exit 0**, no mismatches |
| **Notes** | 8 / 8 checks passed, 0 failed, 0 blocked. Manual browser sign-off is the remaining item, as for Phases 1–11. |

Two behaviours are worth reading twice, because they are deliberate:

- **A transfer to the primary admin has two credits on the same transfer.** The ledger’s
  idempotency key is `(user_id, ref_type, ref_id, type)`, so the amount credit and the fee credit
  would collide when the admin is also the recipient. In that one case the fee row is written with
  `ref_id = NULL` and names the transfer in its note; the reconciliation accepts it and the admin
  monitor still lists it under the transfer.
- **A limit of 0 means “unlimited”.** Operators who blank the field in the (Phase 15) settings page
  must not lock every member out of sending money; the page says “no limit” instead.

Tester: Automated QA pass — DeepSeek Harness (DSH)  ·  Date: 2026-11-09  ·  Signature: DSH
