# Phase 6 QA — Checkout and orders (manual browser test)

| | |
|---|---|
| **Phase** | 6 — checkout, address book, order placement, member history, admin order desk |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §5.3, §7 Phase 6 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `QA_Member_01 / QaPass!2345` (from Phase 1) |
| **Checks** | 17 (QA6-01 … QA6-17) |
| **Time** | 55–70 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-6/` |

## What this phase is meant to prove

Phase 6 turns the cart into an order. A member reviews the cart, picks a saved address or types a new one,
chooses e-wallet or a manual method, accepts the terms and places the order. The server re-prices every
line, reserves stock, converts the cart and — for e-wallet — debits the wallet in the same transaction.
A double submit returns the original order, never a second one. The member can follow the order, cancel
an unpaid one, and the admin can see every order.

Three rules matter most:

1. **The server owns the money.** Posted prices are ignored; the total always comes from the catalogue.
2. **One submit is one order.** The hidden idempotency key makes a double-click or a retry return the same
   order; the wallet is debited once.
3. **A failed checkout writes nothing.** If stock changed or the wallet is short, the whole attempt rolls
   back — no order, no reservation, no debit, and the cart is still there.

**Already proven automatically:** P6-T1 e-wallet + manual placement · P6-T2 idempotent replay ·
P6-T3 tampered prices · P6-T4 stock refusal · P6-T5 wallet refusal · P6-T6 field validation ·
P6-T7 address book (default, ownership, checkout save) · P6-T8 member isolation · P6-T9 list filters ·
P6-T10 admin scoping · P6-T11 cancellation · P6-T12 event log · P6-T13 order number · P6-T14
two-connection last-unit race. The HTTP smoke script repeats the placement, replay, guards, filters,
address book, missing-terms and cancellation paths.

**Already captured headlessly** (reference screenshots — still run the checks):

| File | Shows |
|---|---|
| `final-checkout.png` | checkout with two saved addresses, delivery form, payments and order summary |
| `final-checkout-mobile.png` | the same page at 390 × 844 |
| `final-orders-list.png` | My orders with paid, pending and cancelled rows |
| `final-order-paid.png` | paid order: payment received, items, delivery, payment, history |
| `final-order-pending.png` | pending GCash order with account details, deadline and cancel form |
| `final-order-cancelled.png` | cancelled order with its reason |
| `final-profile-addresses.png` | profile, address book (default) and the address form |
| `final-admin-orders.png` | admin order desk: stats, filters and every order |
| `final-admin-order.png` | admin order detail with member wallet link |

> **Before you start:** Phases 1–5 should be signed off. The automated suite must be green
> (`php tests\run.php` → 238 cases; `powershell -NoProfile -File tests\smoke.ps1` → 83 checks). Create
> `docs\qa\evidence\phase-6\` for your own screenshots.

---

## A. Checkout

### QA6-01 — Checkout shows the cart, the address and the payment choices

| | |
|---|---|
| **Why it matters** | The checkout page is the last place a member can see what they are about to pay. |
| **Where** | `/checkout` as `QA_Member_01` with at least one product in the cart |
| **Evidence** | `QA6-01-checkout.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `QA_Member_01` and add **Coffee ×2** from `/shop`.
2. Open `/cart` and click **Proceed to checkout**.
3. Read the whole page.

**Expected**

- Heading **Checkout**, with **Back to cart** at the top right and **Cart** highlighted in the sidebar.
- **Order summary**: Coffee ×2 with its line total, items count, subtotal, **Shipping Free**, and a bold total
  equal to the subtotal. No shipping or service fee is added.
- **Delivery details** prefilled from the member's default address (or from the profile name on a first order).
- **Payment method**: **E-wallet** shows **Balance ₱…** and is selected when the balance covers the total;
  GCash, Maya and Bank transfer are available too.
- **Terms** checkbox is **ticked by default** and **Place order** is enabled. Unticking the terms greys the
  button out, shows **“Tick the terms above to place your order.”**, and ticking it again re-enables it.
- The page source (DevTools → Elements) contains **`name="idempotency_key"`** with a 32-character value.

### QA6-02 — The profile saves addresses and payout accounts

| | |
|---|---|
| **Why it matters** | Retyping a delivery address or a payout account on every order is the quickest way to lose a buyer. |
| **Where** | `/profile` → address form / payout form → `/checkout` |
| **Evidence** | `QA6-02-address-book.png`, `QA6-02-payout-accounts.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/profile`.
2. Fill **Add an address**: label `Home`, recipient `QA Tester`, phone `0917 111 2222`,
   street `12 Mabini Street`, barangay `Poblacion`, city `Makati`, province `Metro Manila`, postal `1200`,
   tick **Use this as my default address**, then **Save address**.
3. Reload `/profile`.
4. In **Payout accounts**, enter GCash `0917 555 1234`, Maya `+63 917 555 9876`, a USDT TRC20 address
   (`T` + 33 characters) and a USDT BEP20 address (`0x` + 40 hex characters), then **Save payout details**.
5. Put a product in the cart and open `/checkout`.

**Expected**

- Profile shows the address card with a **Default** badge and **Edit / Delete** buttons.
- The form resets after saving; **Add an address** is ready for the next one.
- The **Payout accounts** card saves the four fields and explains that the GCash/Maya/bank details on an
  order are the **company's** receiving accounts, not these.
- After reloading, the GCash/Maya numbers are stored without spaces or dashes (`09175551234`,
  `+639175559876`) and the wallet addresses are unchanged.
- An invalid number shows a field error and does **not** overwrite the saved value.
- Checkout **Saved addresses** lists the address with **Default** and **Selected**, and the delivery
  fields contain `QA Tester`, `12 Mabini Street`, `Poblacion`, `Makati`, `Metro Manila`, `1200`.
- Clicking the other address card (if you saved a second one) reloads checkout with that address selected.

### QA6-03 — A missing address is refused and the terms gate the button

| | |
|---|---|
| **Why it matters** | Validation must be field-level, the typed address must survive the round trip, and an unticked terms box must not look submittable. |
| **Where** | `/checkout` |
| **Evidence** | `QA6-03-validation.png`, `QA6-03-terms-gate.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On checkout, clear **Recipient name** and **Street address** (leave the **Terms** ticked).
2. Click **Place order**.
3. Look at the form that comes back.
4. Untick **Terms**, then tick it again.

**Expected**

- A red flash: **“Please fix the highlighted checkout fields.”**
- The page returns to checkout with **Recipient name is required.** and **Delivery address is required.**
  shown at the fields; the typed values (for example the billing name) and the idempotency key are kept.
- No order appears in `/orders`; the cart still has its lines.
- Unticking the terms disables **Place order** and shows **“Tick the terms above to place your order.”**;
  ticking it again re-enables the button. (The server also refuses a missing-terms submit; the automated
  suite covers that rule.)

---

## B. Placing orders

### QA6-04 — Pay by e-wallet: paid immediately, wallet debited once

| | |
|---|---|
| **Why it matters** | The happy path: one transaction, one debit, one order, cart emptied. |
| **Where** | `/checkout` → `/orders/{id}` → `/wallet` |
| **Evidence** | `QA6-04-paid-order.png`, `QA6-05-wallet-debit.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, open **E-wallet monitor → QA_Member_01 → Credit this wallet** and top up **1,000.00**
   (note: `QA order funds`). This money is spendable but non-withdrawable, which is correct for a top-up.
2. As `QA_Member_01`, open `/wallet` and note the **Total balance**.
3. Add **Coffee ×2** to the cart and open `/checkout`.
4. Choose **E-wallet**, tick the terms and click **Place order**.
5. Watch the redirect and the order page.
6. Open `/wallet` again.

**Expected**

- Redirect to the order page with a green **“Order placed and paid. Thank you!”** flash.
- Order heading **Order EW…**, status **Paid**, **Payment received**, and the amount you paid.
- **Items** lists Coffee ×2 with unit price and line total; the totals match the checkout summary.
- **Delivery** shows the saved address; **Payment** shows E-wallet, subtotal, shipping Free, total and
  **Paid <date>**.
- **History** has two rows: **Pending** (“Order placed via E-wallet”) and **Pending → Paid**
  (“Paid from the e-wallet”).
- No **Cancel order** button.
- The cart icon badge is gone (the cart was converted).
- `/wallet` shows the total reduced by exactly the order total and one **Shop order** debit row in the
  statement (plus the admin top-up from step 1).
- `/orders` lists the order as **Paid**; clicking a status filter narrows the list.

> **Double-click test:** repeat the same checkout on a fresh order by double-clicking **Place order**.
> Only one order is created and the wallet is debited once.

### QA6-05 — Manual payment: the order stays pending with instructions

| | |
|---|---|
| **Why it matters** | GCash/Maya/bank are verified by the company; the order must wait in `pending`. |
| **Where** | `/checkout` → order page |
| **Evidence** | `QA6-06-pending-order.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add a product to the cart and open `/checkout`.
2. Choose **GCash**, tick the terms and **Place order**.
3. Open the order page.

**Expected**

- Green flash **“Order placed. Your payment is pending — the payment window is shown on the order page.”**
- Status **Pending**, heading **Payment pending**, and “Send ₱… to the company's GCash account shown below.”
- **GCash number** and **Account name** from the settings (`0917 000 0000` / `EcoWealth Trading` until the
  owner changes them), followed by the note **“These are the company's receiving details. Your own payout
  accounts are in your profile.”** with a working profile link.
- **Pay by <date>** roughly 24 hours after placing the order.
- A note that payment-proof upload arrives in the next phase.
- **Cancel order** with an optional **Reason** field at the bottom of the status card.
- The wallet balance did **not** change (check `/wallet`).

### QA6-06 — Cancelling an unpaid order releases the stock

| | |
|---|---|
| **Why it matters** | A member who changes their mind must not leave stock held forever. |
| **Where** | Pending order page → cancel |
| **Evidence** | `QA6-06-cancel-modal.png`, `QA6-07-cancelled-order.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open the pending order from QA6-05.
2. Type `Ordered by mistake` in **Reason** and click **Cancel order**.
3. Read the **Cancel this order?** modal and click **Keep order**.
4. Click **Cancel order** again and confirm with the red **Cancel order** button.
5. Look at the page and `/orders`.

**Expected**

- A themed modal (not the browser confirm) titled **Cancel this order?**, explaining “Any reserved stock will be
  released. This cannot be undone.”, with a red **Cancel order** and a **Keep order** button.
- **Keep order** closes the modal and leaves the order **Pending** with the reason still typed.
- Confirming shows the success flash **“Order cancelled. Any reserved stock has been released.”**
- Status **Cancelled**, heading **Order cancelled**, with the cancellation date and reason.
- No **Cancel order** form remains.
- **History** gains a **Pending → Cancelled** row (“Cancelled by the member: Ordered by mistake”).
- `/orders` shows the order as **Cancelled**; the status filter finds it.
- The product is purchasable again at its full available quantity (the reservation is gone).

### QA6-07 — A paid order cannot be cancelled online

| | |
|---|---|
| **Why it matters** | Money has been taken; cancellation needs a refund flow, which is a later phase. |
| **Where** | Paid order page (QA6-04) |
| **Evidence** | `QA6-08-paid-no-cancel.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open the paid order from QA6-04.

**Expected**

- No **Cancel order** button or reason field.
- The page clearly says **Payment received** and that tracking/invoice arrive with the fulfilment phase.

### QA6-08 — The e-wallet is disabled when it cannot cover the total

| | |
|---|---|
| **Why it matters** | The option must not be offered when it would always fail server-side. |
| **Where** | `/checkout` as a member with a small wallet balance |
| **Evidence** | `QA6-09-wallet-disabled.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Create or use a second member with a wallet balance of ₱0 (for example the `QA_Cart_Other` account, or
   register a new `QA_Order_Low` account).
2. Add a product to the cart and open `/checkout`.

**Expected**

- **E-wallet** is greyed out/disabled, shows **Balance ₱0.00** in red, with the hint
  **“Not enough for this order — choose another method or top up.”**
- A manual method (GCash/Maya/Bank) is preselected.
- Placing the order manually works and leaves the wallet at ₱0.00.

---

## C. Order history and security

### QA6-09 — My orders lists and filters correctly

| | |
|---|---|
| **Why it matters** | The history is the member's record; filters must narrow, not leak. |
| **Where** | `/orders` |
| **Evidence** | `QA6-10-orders-list.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/orders`.
2. Filter **Status = Paid**; then **Status = Cancelled**; then type part of an order number in **Order number**.
3. Clear the filters.

**Expected**

- Rows show order number, placed date, item count, total, payment method, status badge and **View**.
- Paid filter shows only paid orders; Cancelled shows only cancelled ones; the order-number search finds
  the matching order.
- **Clear** returns the full list; the pager summary reflects the number of orders.

### QA6-10 — Another member cannot open the order

| | |
|---|---|
| **Why it matters** | Order ids are sequential; URL guessing must not leak an order. |
| **Where** | `/orders/{id}` as a different member; `/admin/orders` as a member |
| **Evidence** | `QA6-11-isolation.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Copy the URL of one of `QA_Member_01`'s orders.
2. In a private window, sign in as another member (for example `QA_Cart_Other`) and open that URL.
3. While signed in as the other member, open `/admin/orders`.

**Expected**

- The order URL shows the branded **404 — Page not found** page; no order data appears.
- `/admin/orders` shows the branded **403 — Access denied** page.
- The guest case is the same: a private window without signing in redirects `/orders/…` to `/login`.

### QA6-11 — Order numbers look right and are unique

| | |
|---|---|
| **Why it matters** | The order number is what support and the member quote to each other. |
| **Where** | `/orders`, order page, admin order desk |
| **Evidence** | `QA6-12-order-numbers.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Look at three orders in the list.

**Expected**

- Every number matches **`EW` + six date digits + five-digit id**, e.g. `EW26110500042`.
- Numbers never repeat, and the same number appears in the list, the detail page and the admin desk.

---

## D. Admin

### QA6-12 — The admin order desk shows every order and accurate totals

| | |
|---|---|
| **Why it matters** | Operations needs one screen for the day's orders. |
| **Where** | `/admin/orders` as `admin` |
| **Evidence** | `QA6-13-admin-orders.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/admin/orders`.
2. Read the four cards and the table.
3. Filter by **Status = Pending**, then by **Payment = GCash**, then search a member's username.
4. Clear the filters.
5. Click **Open** on an order.

**Expected**

- Cards: **Orders** (with today's count), **Awaiting payment**, **In progress** (with completed count) and
  **Paid revenue** (with today's paid total).
- Table: order number, member (username + full name), placed date, items, total, payment method, status
  and **Open**.
- The **Paid revenue** card equals the sum of paid orders, not pending or cancelled ones.
- Filters narrow correctly and **Clear** restores the list.
- The member's username appears on every row they own.

### QA6-13 — The admin order detail shows the member, items and history

| | |
|---|---|
| **Why it matters** | Support needs the delivery snapshot, the member's wallet and the status trail in one place. |
| **Where** | `/admin/orders/{id}` |
| **Evidence** | `QA6-14-admin-order.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open the paid order from the admin desk.

**Expected**

- Header: order number, placed date, payment method, status badge, total and **Back to orders**.
- **Items** and totals as on the member page.
- **Status history** with one row per transition and the actor (`Member · @username`, `System`).
- **Member** card with username, email/full name, e-wallet balance and **Open wallet**.
- **Delivery** shows the snapshot address and member notes; **Payment** shows the method and paid date.
- No edit/transition buttons yet — payment verification and fulfilment arrive with the next phases.

### QA6-14 — Mobile checkout

| | |
|---|---|
| **Why it matters** | Checkout is the page most likely to be completed on a phone. |
| **Where** | DevTools device toolbar, iPhone 12 Pro (390 × 844) |
| **Evidence** | `QA6-15-checkout-mobile.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add a product to the cart.
2. Open `/checkout`, press `Ctrl+Shift+M`, choose **iPhone 12 Pro**.

**Expected**

- The summary stacks under the form (no sticky overlap) and its total is readable.
- Delivery fields are one or two per row; **Place order** is reachable without horizontal scrolling.
- Payment options stack and remain tappable; the terms checkbox is visible.

---

## E. Invariants and regression

### QA6-15 — Stock, wallet and orders still reconcile

| | |
|---|---|
| **Why it matters** | Phase 6 moves money and holds stock; both must stay exact. |
| **Where** | Terminal + phpMyAdmin |
| **Evidence** | `QA6-16-reconcile.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Run the wallet reconciliation (QA5-12 has the Git Bash and PowerShell forms).
2. Run the SQL below.

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

3. Check that every paid order has exactly one `shop_order` debit in `ewallet_ledger`.

**Expected**

- The reconciliation command prints **OK — every wallet matches its ledger on both buckets.** and exits 0.
- The stock query returns **zero rows** (no product is over-reserved).
- The ledger count for paid orders equals the number of paid orders; pending/cancelled orders have no debit.

### QA6-16 — Earlier phases still work

| | |
|---|---|
| **Why it matters** | Phase 6 changed the cart buttons, the layout menu and the schema. |
| **Where** | `/shop`, `/cart`, `/packages`, `/admin/products`, `/wallet` |
| **Evidence** | `QA6-17-regression.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add and remove a cart line from `/shop` and `/cart`.
2. Open `/packages` and `/admin/products`.
3. Open `/wallet` and, as admin, `/admin/wallets`.
4. In DevTools → Console, confirm there are no red errors.

**Expected**

- The storefront, cart, packages, wallet and admin catalogue behave as before.
- The sidebar now shows **My orders** under Shopping and **Profile** under Account; **Checkout** is not a
  menu item (it is reached from the cart).
- No JavaScript exceptions on the pages you visited.

### QA6-17 — Cleanup restores the starting state

| | |
|---|---|
| **Why it matters** | The dev database should be left as found. |
| **Where** | phpMyAdmin → SQL |
| **Evidence** | `QA6-18-cleanup.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Run:

   ```sql
   SET @qa = (SELECT id FROM users WHERE username = 'QA_Member_01');
   DELETE FROM shop_orders        WHERE user_id = @qa;
   DELETE FROM addresses          WHERE user_id = @qa;
   DELETE FROM ewallet_admin_topups WHERE recipient_id = @qa;
   DELETE FROM ewallet_ledger     WHERE user_id = @qa;
   DELETE FROM audit_log          WHERE action = 'wallet.topup' AND subject_id = @qa;
   UPDATE users SET ewallet_balance = 0.00, withdrawable_balance = 0.00 WHERE id = @qa;
   ```

2. Reload `/orders` and `/wallet` as the member.

**Expected**

- `/orders` is back to **No orders yet**; `/wallet` is empty with zero balances.
- The admin order desk has no QA order left.
- The reconcile command is still **OK**.

---

## Cleanup checklist

- [ ] QA orders, addresses and wallet rows for `QA_Member_01` removed (QA6-17 SQL)
- [ ] Any `QA_Order_Low` / extra QA account removed
- [ ] No `SMOKE-` products or `Smoke Package` rows left (the smoke script removes its own)
- [ ] Maintenance mode off
- [ ] Evidence screenshots saved in `docs/qa/evidence/phase-6/`
- [ ] Results filled into [sign-off-sheet.md](sign-off-sheet.md)

## Known and accepted behaviour (not bugs)

- **Manual orders stay pending.** GCash/Maya/bank payments are verified by the company; the payment-proof
  upload is Phase 7. Nothing is debited until the order is verified.
- **E-wallet credit from an admin top-up is spendable but not withdrawable.** That is the Phase 5 rule; it
  still works at checkout.
- **Only unpaid orders can be cancelled online.** A paid order needs the refund flow of a later phase.
- **Shipping is free in this build.** The order stores a `shipping_fee` of 0.00 until courier pricing ships
  with fulfilment.
- **A failed checkout keeps the cart.** That is deliberate — the member can fix the quantity or top up and
  try again.
- **The order number’s date comes from placement.** All orders placed the same day share the date segment;
  the id keeps them unique.
