# Phase 7 QA — Manual payments and the order lifecycle

| | |
|---|---|
| **Phase** | 7 — proof upload, admin verification, rejection/correction, expiry, admin cancellation & refund, settings |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §4.4, §5.4, §7 Phase 7 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `QA_Member_01 / QaPass!2345` (from Phase 1) · `QA_Pay_01 / QaPay!2345` (evidence fixture — see below) |
| **Checks** | 18 (QA7-01 … QA7-18) |
| **Time** | 60–75 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-7/` |

## What this phase is meant to prove

Phase 6 made an order; Phase 7 makes the **manual payment** complete. A member who chooses GCash,
Maya or bank transfer uploads evidence, the company reviews it, and the order either becomes **Paid**
(the reservation is committed) or **Payment failed** (the stock is released and the member can correct
the proof inside a window). An unpaid order is cancelled automatically when its window closes. An
administrator can also cancel an order before it ships — a wallet-paid order is refunded to the two
wallet buckets it was spent from.

Three rules matter most:

1. **One proof per attempt, capped.** The form hides itself when the cap is reached, and the server
   refuses a fourth upload even if a form is forged.
2. **Money moves only on an exact match.** Verification needs the exact order total and a reference no
   other order uses; a mismatch changes nothing.
3. **Stock follows the payment.** Rejection releases the hold, a corrected proof takes it again, and a
   cancellation releases it — availability never goes negative.

**Already proven automatically:** P7-T1 valid proof · P7-T1 PDF facts · P7-T2 file type / size / claim
validation · P7-T3 attempt cap · P7-T4 verification (and its amount/reference/status refusals) ·
P7-T5 rejection · P7-T6 re-upload re-reservation · P7-T7 expiry (payment + correction deadlines,
idempotent) · P7-T8 closed window · P7-T9 ownership · P7-T10 self-review · P7-T11 timeline ·
P7-T12 admin cancellation, wallet refund bucket restore, proof-paid no-refund, no double cancel,
shipped refusal. The expiry job itself is additionally covered by `CronTest`: the web entry's access
decision (loopback vs. key), the one-run-at-a-time lock, the monthly log, the kill switch and the
`--from-settings` recomputation. The HTTP smoke script repeats the upload, guards, streaming, verify,
reject, re-upload, cancel, expiry (CLI **and** URL), doctor, dry-run, kill switch and settings paths
(102 checks in total).

**Already captured headlessly** (reference screenshots — still run the checks; refresh them during
your pass):

| File | Shows |
|---|---|
| `final-order-pending-proof.png` | pending GCash order with the proof upload form |
| `final-order-review.png` | payment under review with the attempts table |
| `final-order-rejected.png` | payment failed with the reason and the retry form |
| `final-order-manual-paid.png` | a verified manual order on the member side |
| `final-order-proof-mobile.png` | the proof panel at 390 × 844 |
| `final-admin-order-review.png` | admin review pane: attempts, verify and reject panels |
| `final-admin-order-rejected.png` | the same order after a rejection |
| `final-admin-order-self.png` | the self-review banner on the admin's own order |
| `final-admin-refund.png` | the refunding cancel card on a paid wallet order |
| `final-admin-orders-review.png` | the order desk with the awaiting-review link and rows |
| `final-admin-settings.png` | payment accounts and windows page |
| `final-proof-stream.png` | the proof opened through the guarded stream |
| `qa-proof-source.png` | the sample receipt the evidence uploads use |

> The reference screenshots were produced from the evidence fixture account **`QA_Pay_01 / QaPay!2345`**
> with six orders (pending, under review, rejected, verified manual, paid wallet, and one placed by
> `admin` for the self-review screen). You can sign in as that account to inspect them, or delete the
> fixture with the QA7-18 SQL and run the checks with your own data.

> **Before you start:** Phases 1–6 should be signed off. The automated suite must be green
> (`php tests\run.php` → 286 cases; `powershell -NoProfile -File tests\smoke.ps1` → 100 checks).
> Create `docs\qa\evidence\phase-7\` for your own screenshots.

---

## A. The member side

### QA7-01 — A pending manual order offers the proof upload

| | |
|---|---|
| **Why it matters** | The upload form is the only way a manual payment becomes verifiable. |
| **Where** | `/checkout` → `/orders/{id}` as `QA_Member_01` |
| **Evidence** | `QA7-01-proof-panel.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add **Coffee ×1** to the cart and open `/checkout`.
2. Choose **GCash**, tick the terms and **Place order**.
3. Read the order page.

**Expected**

- Status **Pending**, heading **Payment pending**, with the company's GCash number and account name.
- **Pay by <date>** about 24 hours after placing the order.
- A **Upload your payment proof** panel with fields **Amount sent** (prefilled `300.00`),
  **Reference number**, **Transfer date** (optional, cannot be in the future) and a **Proof file**
  input that accepts JPG, PNG, WebP or PDF; the copy says the file may be up to 5 MB and that
  **Attempt 1 of 3** is allowed.
- **Cancel order** is still available.

### QA7-02 — A valid proof moves the order to review

| | |
|---|---|
| **Why it matters** | The happy path: evidence stored, order under review, stock still held. |
| **Where** | Pending order page → submit → `/orders/{id}` |
| **Evidence** | `QA7-02-order-review.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the QA7-01 order, fill **Amount sent** `300.00`, **Reference number** `QA7-REF-001`, attach a
   screenshot (any PNG/JPG from your desktop) and click **Submit payment proof**.
2. Read the page that comes back.

**Expected**

- Green flash **“Payment proof submitted (attempt 1). We will verify it shortly.”**
- Status **Payment review**, heading **We are verifying your payment**, with **Attempt 1 of 3** and the
  amount/reference you entered.
- The upload form is **gone**; **Cancel order** is **gone** too (an order under review cannot be
  cancelled online).
- A **Payment attempts** card lists attempt **1** with the submitted date, `₱300.00`, `QA7-REF-001` and
  **Awaiting review**.
- The product's available stock has **not** changed (the reservation is still held).

### QA7-03 — A wrong file type is refused

| | |
|---|---|
| **Why it matters** | Evidence must be an image or a PDF, not an executable renamed. |
| **Where** | A fresh pending order's proof panel |
| **Evidence** | `QA7-03-bad-file.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Place a second GCash order (**Immune Booster ×1**).
2. Create a text file called `proof.png` containing `hello` (Notepad → Save as → all files).
3. Fill the claim and attach that file; **Submit payment proof**.

**Expected**

- Red flash **“That file type is not allowed. Use JPG, PNG, WEBP, PDF.”**
- The order is still **Pending**; the panel keeps your typed amount/reference with a red
  **Proof file** field; **no** attempt is added to the table.
- A file over 5 MB is refused the same way with a “too large” message.

### QA7-04 — The attempt cap refuses the fourth upload

| | |
|---|---|
| **Why it matters** | Unlimited attempts would let a member flood the review queue and bypass the deadline. |
| **Where** | An order you reject three times (needs `admin` too) |
| **Evidence** | `QA7-04-attempt-cap.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Upload a proof on a fresh order; as `admin`, reject it (see QA7-07) with **The proof is unclear or
   unreadable**.
2. Repeat twice: upload again, reject again. After the third rejection the order is **Payment failed**.
3. Look at the order page; try to upload once more by submitting the (hidden) form from a saved copy if
   you are curious — the server also refuses.

**Expected**

- After the third rejection the order page says **Payment needs correction**, **“You have used all 3
  payment-proof attempts for this order. Please contact support.”** and **“No proof attempts left.”**
- The upload form is **not** rendered; the attempts card shows **3 of 3** with every rejection reason.
- `Cancel order` is still available so the member is never stuck.

### QA7-05 — Uploading past the deadline is refused

| | |
|---|---|
| **Why it matters** | The payment window is what the expiry job enforces; the form must agree with it. |
| **Where** | A pending order whose deadline has passed (SQL below) + `/orders/{id}` |
| **Evidence** | `QA7-05-window-closed.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Place a GCash order and note its id (the URL `/orders/12`).
2. In phpMyAdmin run:

   ```sql
   UPDATE shop_orders SET payment_deadline = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = 12;
   ```

3. Reload the order page. (Do not submit the form; the panel is already gone.)

**Expected**

- **“The payment window closed on <date>.”** in red, with **“A payment proof cannot be uploaded for
  this order right now”** and the suggestion to cancel and re-order.
- No upload form; **Cancel order** still works.

---

## B. The admin side

### QA7-06 — The admin review pane shows the evidence

| | |
|---|---|
| **Why it matters** | Verification without the evidence on screen is guesswork. |
| **Where** | `/admin/orders/{id}` for the QA7-02 order as `admin` |
| **Evidence** | `QA7-06-admin-review.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/admin/orders` and click **Open** on the reviewed order (or go straight to the id).
2. Read the **Payment review** card.

**Expected**

- Header **Payment review** with an **Awaiting review** badge and an attempts table: attempt **1**,
  submitted date, **Claimed ₱300.00**, reference `QA7-REF-001`, transfer date, status, and a **View**
  button.
- **View** opens the proof in a new tab through the guarded route
  (`/admin/proofs/{id}`) — never a path under `uploads/`.
- **Verify payment** panel: **Amount received** prefilled with the order total, **Payment reference**
  prefilled with the claim, an optional note and a green **Verify and mark paid** button.
- **Reject proof** panel: a reason select (unclear proof, wrong amount, reference mismatch, not
  received, duplicate reference, other) and a red **Reject proof** button.
- Both buttons open a themed confirmation modal, not the browser dialog.

### QA7-07 — Verification pays the order exactly once

| | |
|---|---|
| **Why it matters** | This is where manual money becomes a paid order; the amount must match. |
| **Where** | `/admin/orders/{id}` → Verify |
| **Evidence** | `QA7-07-verified.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the review pane, change **Amount received** to one peso less than the total and click **Verify and
   mark paid**; confirm in the modal.
2. Read the red flash.
3. Enter the exact total again and verify.
4. Open `/orders/{id}` as the member (or look at the admin page).

**Expected**

- The wrong amount is refused: **“The amount received (₱…) does not match the order total (₱…).”**, the
  order stays **Payment review**.
- With the exact total the order becomes **Paid**, **Paid <date>** appears, the attempts table shows
  **Verified** with the admin username and time, and **Status history** gains **Payment review → Paid**
  (“Received ₱… · ref …”).
- The order's lines are now committed (available stock still counts them as held until shipment).
- The member sees **Payment received** and the verified reference on **Payment**; no wallet movement
  appears in `/wallet` (this was a manual payment).

### QA7-08 — A rejection opens the correction window

| | |
|---|---|
| **Why it matters** | A rejected proof must not silently kill the order; the member gets one clear retry. |
| **Where** | A reviewed order → Reject ⇒ member page |
| **Evidence** | `QA7-08-rejected.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Upload a proof on a fresh GCash order (member).
2. As `admin`, open the order, choose **The amount received does not match the order total**, type a note
   `Short by ₱50` and reject; confirm.
3. Open the order page as the member.

**Expected**

- Admin: green flash **“Proof rejected. The order is now payment failed and the member can re-upload
  within the correction window.”**; the attempts table shows **Rejected** with your reason and the
  admin name; the **Payment review** card explains **“The member can re-upload until <date> (2 attempts
  left)”**.
- Member: status **Payment failed**, heading **Payment needs correction**, **Reason:** your note,
  **Correct and re-upload by <date>**, and the upload form is back with **Attempt 2 of 3**.
- The product's available stock went back up by the reserved quantity (check `/shop` or the admin
  products list).

### QA7-09 — A corrected proof re-reserves the stock

| | |
|---|---|
| **Why it matters** | The order must hold its units again or the member could pay for a sold-out item. |
| **Where** | Rejected order → re-upload ⇒ admin review |
| **Evidence** | `QA7-09-reupload.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the QA7-08 order, upload a new proof with a new reference `QA7-REF-002` and the correct amount.
2. As `admin`, open the order.
3. Check the product's available quantity in `/admin/products`.

**Expected**

- The order is **Payment review** again; attempts shows **2 of 3** with attempt 1 **Rejected** and
  attempt 2 **Awaiting review**.
- **Payment** card shows no correction deadline any more.
- The admin verify panel is available again with attempt 2's reference prefilled.
- The product's available quantity is one lower again (the hold is re-taken).

### QA7-10 — An admin cannot review their own order

| | |
|---|---|
| **Why it matters** | Self-approval is how a staff account pays itself. |
| **Where** | `admin` places an order and opens it in the back office |
| **Evidence** | `QA7-10-self-review.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, add a product to the cart and place a GCash order (admins may shop).
2. Upload a proof on that order.
3. Open the order in the back office (`/admin/orders/{id}`).
4. If you try to submit a verify form from a saved copy, expect a refusal.

**Expected**

- A yellow banner: **“You placed this order. Payment review, rejection and cancellation are disabled for
  your own order — another administrator has to act on it.”**
- No **Verify**, **Reject** or **Cancel** forms on the page.
- The order stays **Payment review** until another administrator acts.

### QA7-11 — Proof access is admin-only

| | |
|---|---|
| **Why it matters** | A payment screenshot can contain account numbers. |
| **Where** | `/admin/proofs/{id}` as admin / member / guest |
| **Evidence** | `QA7-11-proof-guard.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, open a proof's **View** link and note the `/admin/proofs/{id}` URL and the file it shows.
2. Copy that URL; open it in a private window signed in as `QA_Member_01`.
3. Open it in a private window with no session.
4. Search the member order page source (DevTools → Elements) for `uploads/proofs`.

**Expected**

- Admin: the image or PDF opens in the tab.
- Member: branded **403 — Access denied**.
- Guest: redirected to `/login`.
- The stored path never appears in any page source; the stream is the only way to read it.

### QA7-12 — An admin cancels a wallet-paid order and the refund restores the buckets

| | |
|---|---|
| **Why it matters** | A cancellation must return exactly the money the purchase took — no more, no less. |
| **Where** | `/checkout` (e-wallet) → `/admin/orders/{id}` → Cancel ⇒ `/wallet` |
| **Evidence** | `QA7-12-refund.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, top up `QA_Member_01` by **₱1,000.00** (E-wallet monitor). Note `/wallet`:
   total ₱…, withdrawable ₱….
2. As `QA_Member_01`, buy **Coffee ×2** with the **e-wallet**.
3. Note `/wallet` again after the purchase (a **Shop order** debit row).
4. As `admin`, open that order, scroll to **Cancel order**, choose **The member asked to cancel**, type a
   note, click **Cancel order** and confirm the modal.
5. Read `/wallet` as the member.

**Expected**

- The modal reads **Cancel this order?** and says the wallet refund cannot be undone; **Keep order**
  closes it without cancelling.
- After confirming: green flash **“Order cancelled. ₱… refunded to the member e-wallet.”**
- Order status **Cancelled** with the reason; **Status history** shows the transition and the refund.
- `/wallet` shows a **Refund** credit for exactly the order total and the total balance returns to what it
  was before step 2. **Withdrawable** returns to its previous value too (a top-up stays
  non-withdrawable; it is not laundered into withdrawable money).
- The stock is available again.
- A manual (GCash) order cancelled the same way shows **no** refund row and **₱0.00 refunded**.

---

## C. Operations

### QA7-13 — The expiry job (URL + CLI) cancels an overdue order once

| | |
|---|---|
| **Why it matters** | Unpaid orders must not hold stock forever, and the job must be safe to run repeatedly — from a browser or from cron. |
| **Where** | `http://localhost/ecowealth/system/cron/shop_expire.php` · `bin\shop_expire.php` |
| **Evidence** | `QA7-13-expiry-url.png`, `QA7-13-expiry-cli.png`, `QA7-13-cron-log.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Place a GCash order and note its id (the URL `/orders/12`).
2. In phpMyAdmin push its deadline into the past:

   ```sql
   UPDATE shop_orders SET payment_deadline = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = 12;
   ```

3. Open the job's environment report in the browser:

   ```
   http://localhost/ecowealth/system/cron/shop_expire.php?doctor=1
   ```

4. Open the preview and the live-setting preview:

   ```
   …/cron/shop_expire.php?dry-run=1
   …/cron/shop_expire.php?from-settings=1&dry-run=1
   ```

5. Run it for real, then run it a second time:

   ```
   …/cron/shop_expire.php
   …/cron/shop_expire.php
   ```

6. In PowerShell, run the same job from the CLI (create another overdue order first if you want it to
   expire something):

   ```powershell
   cd C:\laragon\www\ecowealth\system
   php bin\shop_expire.php --doctor
   php bin\shop_expire.php --dry-run
   php bin\shop_expire.php
   ```

7. Open `system\storage\logs\shop_expire-<YYYY-MM>.log`.

8. Look at the order, its history and the product's available quantity.

9. **Kill switch:** open **Settings**, untick **Expire unpaid orders automatically** and save. Force
   another order overdue (step 2), reload `…/cron/shop_expire.php`, then tick the switch back on and
   save.

**Expected**

- **Doctor:** PHP version and `apache2handler`, `timezone Asia/Manila`, `db_now` equal to the PHP time
  (session `+08:00`), the three windows (`24 / 24 / 3`), `expiry enabled yes`, `candidates 1`, the lock
  `free`, the log `(writable)`.
- **Dry run:** `candidates: 1 → 12`, and the order is untouched. The response says *nothing is changed*.
- **From-settings dry run:** `mode: settings`; a just-placed order is usually **0 candidates** because
  the current 24-hour window has not elapsed — that is the difference between the stored stamp and the
  live setting, not a bug.
- **First real run:** `candidates: 1`, `expired: 1`, `ids: 12`, a duration and the log path.
- **Second run:** `candidates: 0`, `expired: 0`, `ids: —` — the job is idempotent.
- **Order:** **Cancelled**, reason **Payment window expired**; history **System → Cancelled**
  (“Cancelled automatically: the payment window closed on …”); the stock is available again.
- **Log:** one line per run, e.g.
  `[2026-11-06 11:20:00 +08:00] [OK   ] mode=stamps trigger=http limit=200 candidates=1 expired=1 duration=45ms ids=12`
  (the CLI run logs `trigger=cli`; dry runs log `[INFO ] dry-run …`).
- **CLI:** the same block with `trigger: cli`; `--doctor` and `--dry-run` exit `0`.
- **Kill switch:** the run prints **“skipped: expiry is disabled in the admin settings
  (shop_expiry_enabled = 0).”**, the overdue order stays **Pending**, and a `[WARN ] skipped: disabled
  by settings` line is logged. After switching it back on, the next run cancels it.
- An order whose proof is **under review** is **never** cancelled by this job, even with an old deadline.
- A second caller while a run is in progress gets **“skipped: another run is already in progress.”**
  (exit 0), never a duplicate cancellation.

### QA7-14 — The settings page saves and validates

| | |
|---|---|
| **Why it matters** | These values are where buyers send money and how long they have to do it. |
| **Where** | `/admin/settings` |
| **Evidence** | `QA7-14-settings.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open **Settings** in the back-office sidebar (System → Settings).
2. Change **Payment window** to `30`, **Correction window** to `12`, **Proof attempts** to `4`, change the
   GCash number to `0917 123 4567`, and **Save settings**.
3. Reload the page, then change **Payment window** to `0` and save.
4. Before leaving, restore the original values (24 / 24 / 3 and the original GCash number).

**Expected**

- The page has the **Shop** switch, **Receiving accounts** (GCash, Maya, bank), **Payment windows**
  (three numbers with hints), the **Expire unpaid orders automatically** switch and **Save settings**.
- Saving shows **“4 settings saved.”** and the changed values survive a reload; a `settings.update` row is
  written to `audit_log`.
- `0` is refused: red flash **“Please fix the highlighted settings.”**, **“Payment window (hours) must be
  at least 1.”** at the field, and the stored value stays `30`.
- A member opening `/admin/settings` gets the branded **403** page.

---

## D. Invariants, mobile and cleanup

### QA7-15 — The event trail and the ledger still reconcile

| | |
|---|---|
| **Why it matters** | Every payment action must be explainable and every peso accounted for. |
| **Where** | Order page history, `/admin/orders/{id}`, terminal |
| **Evidence** | `QA7-15-reconcile.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open the order pages you created and read **Status history**.
2. Run:

   ```powershell
   cd C:\laragon\www\ecowealth\system
   php bin\wallet_reconcile.php
   ```

3. Run this SQL and confirm it returns no rows:

   ```sql
   SELECT p.id, p.name, p.stock,
          COALESCE(SUM(CASE WHEN oi.reservation_state IN ('reserved','committed')
                             AND o.status NOT IN ('cancelled','completed')
                            THEN oi.quantity ELSE 0 END), 0) AS held
   FROM products p
   LEFT JOIN shop_order_items oi ON oi.product_id = p.id
   LEFT JOIN shop_orders o ON o.id = oi.order_id
   GROUP BY p.id, p.name, p.stock
   HAVING held > p.stock;
   ```

**Expected**

- Every order has one history row per action with its actor (`Member · @…`, `Admin · @admin`, `System`)
  and a timestamp; the reasons match what you did.
- **OK — every wallet matches its ledger on both buckets.**
- The stock query returns **zero rows** (nothing is over-reserved).

### QA7-16 — The proof panel and review pane work on a phone

| | |
|---|---|
| **Why it matters** | Members photograph a receipt on their phone; the form must fit. |
| **Where** | DevTools device toolbar, iPhone 12 Pro (390 × 844) |
| **Evidence** | `QA7-16-mobile.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open a pending GCash order as the member at 390 × 844.
2. Open a reviewed order as `admin` at the same width.

**Expected**

- The upload form stacks (amount/reference, then date/file) and **Submit payment proof** is reachable
  without horizontal scrolling.
- The attempts table scrolls horizontally rather than breaking the card.
- The admin verify/reject panels stack under the attempts table, and both buttons remain tappable.

### QA7-17 — Earlier phases still work

| | |
|---|---|
| **Why it matters** | Phase 7 touched the order page, the layout and the schema. |
| **Where** | `/shop`, `/cart`, `/checkout`, `/wallet`, `/admin/products` |
| **Evidence** | `QA7-17-regression.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add and remove a cart line; place an e-wallet order.
2. Open `/wallet`, `/admin/products` and `/admin/orders`.
3. In DevTools → Console confirm there are no red errors on the pages you visited.

**Expected**

- Cart, e-wallet, catalogue and order desk behave as before.
- The back-office menu now has **Settings** under *System*; every link works.
- No JavaScript exceptions.

### QA7-18 — Cleanup restores the starting state

| | |
|---|---|
| **Why it matters** | The dev database should be left as found. |
| **Where** | phpMyAdmin → SQL |
| **Evidence** | `QA7-18-cleanup.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Run:

   ```sql
   SET @qa = (SELECT id FROM users WHERE username = 'QA_Member_01');
   DELETE FROM shop_orders        WHERE user_id = @qa;
   DELETE FROM addresses          WHERE user_id = @qa;
   DELETE FROM ewallet_admin_topups WHERE recipient_id = @qa;
   DELETE FROM ewallet_ledger     WHERE user_id = @qa;
   DELETE FROM audit_log          WHERE subject_id = @qa OR (action = 'settings.update');
   UPDATE users SET ewallet_balance = 0.00, withdrawable_balance = 0.00 WHERE id = @qa;
   ```

2. Confirm the settings are back to 24 / 24 / 3 and the original GCash number.
3. Check `system/public/uploads/proofs/` contains no leftover files (they are ignored by git but keep the
   folder tidy).
4. Run `php bin\wallet_reconcile.php` once more.

**Expected**

- `/orders` is back to **No orders yet**; `/wallet` is empty with zero balances.
- The admin order desk has no QA order left, and `/admin/settings` shows the restored values.
- Reconciliation is **OK**.

---

## Cleanup checklist

- [ ] QA orders, addresses, proofs and wallet rows for `QA_Member_01` removed (QA7-18 SQL)
- [ ] The `admin` self-review order from QA7-10 removed
- [ ] Settings restored (24 / 24 / 3, original accounts)
- [ ] No orphan files under `system/public/uploads/proofs/`
- [ ] Maintenance mode off
- [ ] Evidence screenshots saved in `docs/qa/evidence/phase-7/`
- [ ] Results filled into [sign-off-sheet.md](sign-off-sheet.md)

## Known and accepted behaviour (not bugs)

- **The proof is never deleted.** A rejected attempt stays in the table as evidence; it is not a draft.
- **`payment_review` never expires.** A submitted proof keeps the order open for the admin; that is
  deliberate, so a slow review cannot cancel a paid order.
- **The cap blocks the fourth upload, not the third.** Three attempts are allowed; after the third
  rejection the member must contact support or cancel.
- **A refund restores the original buckets.** Withdrawable money returns as withdrawable, top-up money
  returns as spendable-only. A refund is not a commission.
- **A proof-paid order refunds nothing.** The company received the money outside the wallet; the refund
  is handled offline (refund notes arrive with a later phase).
- **An admin cannot review their own order.** Use a second administrator account; this is the reference
  segregation rule.
- **`bin/shop_expire.php` is not a web page.** It is a CLI job; run it from PowerShell or cron.
- **Settings are a subset.** Only payment accounts and the three windows are editable in Phase 7; the full
  settings page is Phase 15.
