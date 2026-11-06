# Phase 8 QA — Fulfilment, completion and invoices

| | |
|---|---|
| **Phase** | 8 — packing, shipping with tracking, delivery, receipt confirmation, auto-complete and printable invoices |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §4.4, §5.5, §5.7, §7 Phase 8 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `QA_Fulfil_01 / QaFulfil!2345` (evidence fixture — see below) · `QA_Member_01 / QaPass!2345` (from Phase 1) |
| **Checks** | 19 (QA8-01 … QA8-19) |
| **Time** | 60–75 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-8/` |

## What this phase is meant to prove

Phase 7 ends when an order is **Paid**. Phase 8 takes it the rest of the way: the back office packs
it, ships it with a courier and a tracking number, marks it delivered, and the member confirms
receipt (or the timer does it for them). The member gets a **printable invoice** that never changes
after it is issued.

Four rules matter most:

1. **The status walk is strict.** `paid → packing → ready_to_ship → shipped → delivered → completed`.
   A step cannot be skipped, repeated or reversed from the screen, and a shipped order cannot be
   cancelled.
2. **Shipping is where stock becomes physical.** The reservation turns into a real deduction in the
   same transaction as the courier/tracking record, so "available" never jumps.
3. **The invoice is frozen.** It is written when the payment is confirmed, numbered
   `INV-yymmdd-#####`, and re-printing returns the exact same document. Only a payment reversal
   (an admin cancelling a paid order) marks it **VOID** — the content stays.
4. **The member closes the loop.** They confirm receipt, or the auto-complete job completes the
   order after `shop_autocomplete_days` (default 7).

**Already proven automatically:** P8-T1 the sequential walk and its refusals · P8-T2 courier +
tracking validation · P8-T3 stock deducted exactly once and the short-stock refusal · P8-T4
delivery, member confirmation and ownership · P8-T5 the invoice issued at e-wallet payment and at
manual verification, with a frozen seller/buyer/line snapshot · P8-T6 reprint identity · P8-T7
order/invoice scoping · P8-T8 the no-cancel-after-ship rule · P8-T9 the auto-complete sweep, its
kill switch and idempotency. `FulfilmentTest`, `InvoiceTest` and `FulfilmentViewTest` cover these
(43 new cases; 338 in total). The HTTP smoke script walks the whole flow — pack, a refused ship
without tracking, ship with tracking + the stock change, a refused cancel after ship, deliver, member
confirm, the invoice guards and the auto-complete job from its URL — 115 checks in total.

**Already captured headlessly** (reference screenshots — still run the checks; refresh them during
your pass):

| File | Shows |
|---|---|
| `final-member-paid.png` | a paid order with **Payment received** and the invoice button |
| `final-member-ready.png` | the **Ready to ship** state on the member side |
| `final-member-shipped.png` | **On the way** with courier, tracking and the shipment card |
| `final-member-delivered.png` | **Delivered** with the confirm-receipt panel and the auto-complete note |
| `final-member-completed.png` | the closed order with **Order complete** and the invoice link |
| `final-member-shipped-mobile.png` | the shipped page at 390 × 844 |
| `final-invoice.png` | the printable A4 invoice with seller block, buyer, lines and totals |
| `final-invoice-void.png` | the same invoice after a cancellation: **VOID** stamp and reason |
| `final-invoice-print.png` | the invoice with print media emulated: toolbar hidden and the sheet laid out for A4 |
| `final-admin-paid.png` | the fulfilment panel offering **Start packing** |
| `final-admin-ready.png` | the shipping form (courier dropdown, tracking, Other courier, note) on a ready-to-ship order |
| `final-admin-ready-others.png` | the same form with **Others** selected, revealing the typed courier field |
| `final-admin-shipped.png` | the delivery form and the shipment summary |
| `final-admin-delivered.png` | the waiting-for-confirmation state and the auto-complete copy |
| `final-admin-completed.png` | a completed order with the frozen invoice |
| `final-admin-orders.png` | the order desk with the mixed fulfilment statuses |
| `final-admin-settings.png` | the settings page: one independent card and Save settings button per section |
| `final-admin-settings-mobile.png` | the same page at 390 × 844, each card's button under its content |

> The reference screenshots were produced from the evidence fixture account **`QA_Fulfil_01 /
> QaFulfil!2345`**, which owns one order in each state: paid, ready to ship, shipped, delivered,
> completed and cancelled-after-paid. You can sign in as that account to inspect them, or delete the
> fixture with the QA8-19 SQL and run the checks with your own data.

> **Before you start:** Phases 1–7 should be signed off, `QA_Member_01` should have a paid e-wallet
> order to walk through (QA6-04 leaves one), and the automated suites must be green
> (`php tests\run.php` → **332 cases**; `powershell -NoProfile -File tests\smoke.ps1` → **113 checks**).
> Create `docs\qa\evidence\phase-8\` for your own screenshots.

---

## A. The member side

### QA8-01 — A paid order shows the invoice and says what happens next

| | |
|---|---|
| **Why it matters** | The moment the money is received the member should see both the receipt and the promise of shipping. |
| **Where** | `/orders/{id}` as `QA_Fulfil_01` on the **paid** fixture order |
| **Evidence** | `QA8-01-member-paid.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `QA_Fulfil_01 / QaFulfil!2345`.
2. Open **My orders → the paid order** (status **Paid**).

**Expected**

- Status badge **Paid**, heading **Payment received**, total on the right.
- A **View / print invoice** button beside the status and again in the **Payment** card.
- Copy: *"The courier and the tracking number appear here as soon as the order ships."*
- No proof panel, no **Cancel order** (the order is paid).

### QA8-02 — A ready-to-ship order tells the member it is waiting for the courier

| | |
|---|---|
| **Why it matters** | Packing is invisible work; the member needs a status that explains the pause. |
| **Where** | The **ready** fixture order |
| **Evidence** | `QA8-02-member-ready.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open the fixture order whose status is **Ready to ship**.

**Expected**

- Status badge **Ready to ship**, heading **Ready to ship**.
- Copy: *"Your order is packed and waiting for the courier to collect it."*
- The history lists **Packing started** and **Packed and ready for the courier**.

### QA8-03 — A shipped order shows the courier and the tracking number

| | |
|---|---|
| **Why it matters** | This is the information the buyer needs to receive the parcel. |
| **Where** | The **shipped** fixture order; also the **mobile** check |
| **Evidence** | `QA8-03-member-shipped.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open the order whose status is **Shipped**.

**Expected**

- Status badge **Shipped**, heading **On the way**.
- *"Your order has been handed to **J&T Express** on <date>."*
- **Tracking number: JT8827419056** in bold; the number is selectable.
- The **Delivery** card gains a **Shipment** block with **Courier**, **Tracking**, **Shipped**.
- The history ends with **Shipped via J&T Express · tracking …**.

### QA8-04 — A delivered order asks the member to confirm receipt

| | |
|---|---|
| **Why it matters** | Confirmation is the member's half of the closing handshake. |
| **Where** | The **delivered** fixture order |
| **Evidence** | `QA8-04-member-delivered.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open the order whose status is **Delivered**.
2. Read the status card and the **Confirm your receipt** panel.
3. Do **not** submit yet.

**Expected**

- Status badge **Delivered**, heading **Delivered**, delivery date shown.
- Copy says the order *"completes automatically 7 days after delivery"* if the member does nothing.
- **Confirm your receipt** panel with an optional **Note** field and a **Confirm receipt** button.
- The button opens the themed modal: **Confirm you received this order?** with **Confirm receipt** /
  **Not yet** (no browser `confirm()` box).

### QA8-05 — Confirming receipt completes the order

| | |
|---|---|
| **Why it matters** | The close of the lifecycle; it must happen exactly once. |
| **Where** | Delivered order → **Confirm receipt** |
| **Evidence** | `QA8-05-member-completed.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the QA8-04 order, type a note such as `Received in good order`.
2. Click **Confirm receipt**, then confirm in the modal.

**Expected**

- A green flash: *"Receipt confirmed — thank you! This order is now complete."*
- Status **Completed**, heading **Order complete**, completion date shown.
- The confirm panel disappears; the invoice buttons remain.
- The history gains **Receipt confirmed by the member — Received in good order** (actor
  `Member · @QA_Fulfil_01`).
- Reloading the page keeps **Completed**; the form cannot be submitted again.

### QA8-06 — The printable invoice fits A4 and carries the frozen facts

| | |
|---|---|
| **Why it matters** | The document is what the buyer keeps; wrong totals or a missing seller block are real defects. |
| **Where** | Any paid order → **View / print invoice** (opens in a new tab) |
| **Evidence** | `QA8-06-invoice.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. From an order, click **View / print invoice**.
2. Inspect the page, then use the browser's **Print preview** (Ctrl+P) and cancel it.
3. Compare the lines and totals with the order page.

**Expected**

- A standalone white sheet with **no app menu, navbar or footer**.
- Top-left: the company identity from **Admin → Settings → Fulfilment & invoices** (name, address,
  TIN, phone). Top-right: **SALES INVOICE**, an invoice number `INV-yymmdd-#####`, the order number,
  issued date and paid date.
- **Billed to** (name, username, phone) and **Ship to** (recipient and the full delivery address).
- The item table lists description, SKU, quantity, unit price and amount; **Subtotal**, **Shipping**
  and **Total** are right-aligned and add up.
- A footer says the invoice is a frozen snapshot and that it is not a VAT official receipt.
- The print preview is **A4**, hides the **Back to order** / **Print** toolbar, and the sheet fits
  the page without horizontal scrolling.

### QA8-07 — Reprinting an invoice returns the same document

| | |
|---|---|
| **Why it matters** | "Immutable snapshot" is the whole point of the invoice. |
| **Where** | The same order → the invoice, twice |
| **Evidence** | `QA8-07-invoice-reprint.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Note the invoice number and total on the QA8-06 invoice.
2. Close the tab, open the invoice again from the order page.
3. Reload once more.

**Expected**

- Same invoice number, same seller block, same buyer, same lines and totals every time.
- Admin → Settings changes (for example the TIN) do **not** change an invoice that was already
  issued; only new invoices use the new values.

### QA8-08 — Another member cannot open your order or your invoice

| | |
|---|---|
| **Why it matters** | Invoices carry names, addresses and money. |
| **Where** | A second member session (e.g. `QA_Member_01`) |
| **Evidence** | `QA8-08-invoice-guard.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Copy the invoice URL (`/orders/{id}/invoice`) from the QA8-06 order.
2. Sign out, sign in as `QA_Member_01`, paste the URL.
3. Sign out and open the URL as a guest.

**Expected**

- The other member gets the branded **404 Page not found** page — no invoice content, no order
  details.
- A guest is redirected to **Login**.
- The admin can open the same invoice from **Admin → Orders → the order → View / print invoice**.

---

## B. The admin side

### QA8-09 — Starting packing

| | |
|---|---|
| **Why it matters** | First fulfilment step; it must be available only on a paid order. |
| **Where** | Admin → Orders → a **Paid** order (e.g. `QA_Member_01`'s paid e-wallet order) |
| **Evidence** | `QA8-09-admin-paid.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin / Admin@123`, open **Orders**, open the paid order.
2. In the **Fulfilment** card click **Start packing**, add a note such as `Picked from the shelf`,
   and confirm.

**Expected**

- The card offers **Start packing** with an optional note and the modal **Start packing this
  order?**.
- After confirming: green flash *"Packing started. The order is now Packing."*, status **Packing**,
  and the card now offers **Mark ready to ship**.
- The history shows **Packing started — Picked from the shelf** (actor `Admin · @admin`).
- The member's order page no longer shows **Payment received**; it shows **Packing your order**.

### QA8-10 — Marking ready to ship

| | |
|---|---|
| **Why it matters** | Separates packing from the courier handoff, which is where stock leaves the shelf. |
| **Where** | The same order, now **Packing** |
| **Evidence** | `QA8-10-admin-packing.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click **Mark ready to ship** and confirm.

**Expected**

- Flash *"The order is packed and ready to ship."*, status **Ready to ship**.
- The card switches to the shipping form.
- The history gains **Packed and ready for the courier**.

### QA8-11 — Shipping needs a courier and a tracking number, and deducts the stock

| | |
|---|---|
| **Why it matters** | Tracking is the buyer's proof of dispatch, and the stock movement must be exact. |
| **Where** | The ready-to-ship order → **Ship order** |
| **Evidence** | `QA8-11-admin-ship-form.png`, `QA8-11-member-shipped.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the ready-to-ship order, leave **Tracking number** empty, choose a courier from the
   **Courier** dropdown and click **Ship order**. Confirm the modal.
2. Note the error and the unchanged status.
3. Choose **Others (type below)** in the dropdown and leave **Other courier** empty; click
   **Ship order**.
4. Choose **J&T Express**, enter **Tracking number** `JT1234567890`, an optional note, and click
   **Ship order**.
5. Open **Products**, find the ordered product, and compare its stock with the value you noted
   before step 1 (or with the member's order quantity).
6. Optional: repeat on another order choosing **Others (type below)** and typing a courier that is
   not in the list (e.g. `Flash Express`).

**Expected**

- The **Courier** field is a real dropdown starting on **Choose a courier…**, offering J&T Express,
  LBC Express, Ninja Van, Lalamove, Grab Express, 2GO, DHL and **Others (type below)**. The
  **Other courier** field is visible beside it, with the note *Only needed when “Others” is selected
  above.* (With JavaScript on, the field hides while a listed courier is chosen and reappears for
  **Others**; with JavaScript off it simply stays visible.)
- Step 1 is refused: flash **"Tracking number is required."**, the field is highlighted, the order
  stays **Ready to ship**, and no shipment appears.
- Step 3 is refused: flash **"Enter the courier name."**, **Other courier** is highlighted, the
  dropdown stays on **Others**, and the order stays **Ready to ship**.
- Step 4 succeeds: flash with the courier and tracking, status **Shipped**, shipment date shown.
- Step 6 stores the typed name as the courier (the shipment card and the member page show it).
- The **Fulfilment** card becomes the delivery form, and the right column shows the **Shipment**
  card with the courier, tracking and shipment note.
- The product stock is **lower by exactly the ordered quantity**; the order line is no longer held.
- The member sees **On the way** with the tracking number.

### QA8-12 — Marking the parcel delivered

| | |
|---|---|
| **Why it matters** | Delivery starts the member's confirmation window and the auto-complete clock. |
| **Where** | The shipped order → **Mark delivered** |
| **Evidence** | `QA8-12-admin-delivered.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click **Mark delivered**, add a proof-of-delivery note such as `Received by the guard`, confirm.

**Expected**

- Flash *"Order marked delivered. The member can now confirm receipt."*, status **Delivered**.
- The **Shipment** card shows **Delivered** with the date; its notes keep the shipping note and add
  `POD: Received by the guard`.
- The fulfilment card becomes *"Waiting for the member to confirm receipt; the auto-complete job
  closes it 7 days after delivery."*
- The member's page shows the **Confirm your receipt** panel.

### QA8-13 — A shipped order cannot be cancelled

| | |
|---|---|
| **Why it matters** | Money and goods are already moving; cancellation needs the returns/RTS flow that is deferred. |
| **Where** | A shipped order → **Cancel order** card |
| **Evidence** | `QA8-13-no-cancel-after-ship.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open a **Shipped** order (the QA8-11/12 order, or a fresh one you ship).
2. Look for a **Cancel order** card.
3. If you have the URL from an earlier phase, POST the cancellation form with the order id (advanced
   SQL/form check — optional).

**Expected**

- No **Cancel order** card is shown once the order is shipped (or delivered/completed).
- A forced cancellation is refused with *"This order is “Shipped” now, so it cannot be cancelled."*
  and the status does not change.

### QA8-14 — The admin invoice, and the voided invoice after a cancellation

| | |
|---|---|
| **Why it matters** | Finance needs the same document the member sees, and a cancelled sale must not look live. |
| **Where** | Admin order page → **View / print invoice**; a paid order cancelled by an admin |
| **Evidence** | `QA8-14-admin-invoice.png`, `QA8-14-invoice-void.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On a **Paid** order (before packing), click **View / print invoice** in the Fulfilment card.
2. Back on the order, use **Cancel order** with the reason *"The member asked to cancel"* and a note.
3. Open the invoice again.

**Expected**

- Step 1 shows the same frozen document the member sees; the footer says **Invoice issued**.
- Step 2 cancels the order, refunds a wallet-paid order to the e-wallet (Phase 7 behaviour) and
  leaves the invoice row in place.
- Step 3 shows the invoice with a red **VOID** stamp, a red **Voided** badge and the reason
  *"Order cancelled — The member asked to cancel — …"*; the seller, buyer, lines and totals are
  unchanged, and the footer says the document is kept for the record only.

---

## C. Operations

### QA8-15 — The auto-complete job (URL)

| | |
|---|---|
| **Why it matters** | Orders must not stay open forever when a member never confirms. |
| **Where** | A delivered order older than the window; `/cron/order_autocomplete.php` |
| **Evidence** | `QA8-15-auto-complete.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Find a **Delivered** order. Backdate its delivery with SQL (Laragon → phpMyAdmin → the
   `ecowealth_sneat` database, **SQL** tab):

   ```sql
   UPDATE shop_orders
      SET delivered_at = DATE_SUB(NOW(), INTERVAL 9 DAY)
    WHERE id = <your delivered order id>;

   UPDATE shop_shipments
      SET delivered_at = DATE_SUB(NOW(), INTERVAL 9 DAY)
    WHERE order_id = <your delivered order id>;
   ```

2. Open `http://localhost/ecowealth/system/cron/order_autocomplete.php?doctor=1`.
3. Open `…/order_autocomplete.php?dry-run=1`.
4. Open `…/order_autocomplete.php` (the real run), then reload once more.
5. Open the order.

**Expected**

- The doctor reports PHP and MySQL time, **days 7**, **enabled yes**, the candidate count (at least
  your order), a free lock and a writable log.
- The dry run lists your order id and says **nothing is changed**; the order is still **Delivered**
  afterwards.
- The real run reports `completed: 1` and lists your order; the second run reports `completed: 0`,
  `candidates: 0`.
- The order is **Completed**; its history shows **Completed automatically 7 days after delivery**
  (actor `System`); `completed_at` is stamped.
- The log `system/storage/logs/order_autocomplete-YYYY-MM.log` has one line per run with `days`,
  `candidates`, `completed` and `ids`.

### QA8-16 — The settings page saves the seller block and the completion rules

| | |
|---|---|
| **Why it matters** | The invoice identity and the auto-complete window are configuration, not code. |
| **Where** | Admin → Settings → **Fulfilment & invoices** |
| **Evidence** | `QA8-16-admin-settings.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open **Admin → Settings** and scroll to **Fulfilment & invoices**.
2. Change the **Registered business name**, **TIN**, **Contact number** and **Business address**.
3. Change **Auto-complete delivered orders after (days)** to `10` and click the card's
   **Save settings** button (not any other card's).
4. Reopen the page and check the values persisted.
5. Switch to **Receiving accounts** and **Payment windows** and confirm their values were untouched.
6. Try to save **0 days** or an empty business name on this card.

**Expected**

- The page is a stack of independent cards — **Shop**, **Receiving accounts**, **Payment windows** and
  **Fulfilment & invoices** — and **each card has its own Save settings button**; the button is not
  stuck in one card.
- The **Fulfilment & invoices** card carries **Seller identity** (business name, TIN, contact number,
  address) and **Completion** (days + **Complete delivered orders automatically** switch).
- Saving shows a section-scoped confirmation such as *"Fulfilment & invoices: 5 settings saved."*,
  returns to this card, and the values persist after a reload.
- The accounts and windows values are unchanged by this save (and vice versa when those cards are
  saved).
- Every save is recorded in the audit log (`settings.update`) with the section and the before/after
  values.
- Zero days or an empty business name is refused with an inline message on this card, and the
  previously saved values stay untouched.
- A member opening `/admin/settings` gets the branded **403** page.

---

## D. Invariants, mobile and cleanup

### QA8-17 — Stock, the ledger, invoices and the timeline still reconcile

| | |
|---|---|
| **Why it matters** | Fulfilment touches stock and money-adjacent documents; the earlier invariants must survive. |
| **Where** | The order pages, `/wallet`, `bin/wallet_reconcile.php` |
| **Evidence** | `QA8-17-invariants.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. For the order you shipped, compare the item quantities with the product's stock before/after
   (QA8-11).
2. Open the member's **E-wallet** statement and confirm the order debit (and any refund) is still
   there exactly once.
3. Run the reconciliation command:

   ```powershell
   cd C:\laragon\www\ecowealth\system
   php bin\wallet_reconcile.php
   ```

4. Open the order's **Status history** and read it top to bottom.

**Expected**

- Stock is lower by exactly the shipped quantity; it is **not** deducted again when the order moves
  to delivered/completed.
- The wallet debit/refund is one ledger row each and matches the order.
- `wallet_reconcile` exits **0** with *"Every account matches the ledger"* (no mismatch).
- The history is ordered and each row has an actor: member (placed, confirmed), admin (packed,
  shipped, delivered) and system (auto-completed, when that ran).
- Every issued invoice has a number, an order and a total; no invoice exists for an unpaid order.

### QA8-18 — Mobile layout and earlier phases still work

| | |
|---|---|
| **Why it matters** | The shipped/delivered pages are read on a phone; the new routes must not disturb Phases 1–7. |
| **Where** | 390 × 844 browser emulation; the member and admin menus |
| **Evidence** | `QA8-18-mobile.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. At 390 × 844, open a **Shipped** order and the **Delivered** one.
2. Use the toast/modal once; check no horizontal scrolling.
3. Visit **Shop → Cart → Checkout** and **Wallet** briefly.
4. As the admin, open **Products**, **Orders** and **Settings**.

**Expected**

- The status card, tracking block, confirm panel and invoice button stack cleanly; text is readable
  and nothing overlaps the navbar or the menu.
- The confirm modal fits the screen and its buttons are reachable.
- Cart, checkout and wallet still load and behave as in their phases.
- The admin pages open normally; no **Fulfilment** link was added to the menu (it lives inside an
  order), and the menu still hides later-phase items.

### QA8-19 — Cleanup restores the starting state

| | |
|---|---|
| **Why it matters** | Leave the development database ready for the next phase. |
| **Where** | phpMyAdmin → `ecowealth_sneat` → **SQL** |
| **Evidence** | `QA8-19-cleanup.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. If you created your own test orders, delete them (shipments and invoices cascade).
2. If you do not want the evidence fixture, remove it and its data:

   ```sql
   -- Remove the Phase 8 evidence fixture's orders (shipments/invoices/events cascade)
   DELETE FROM shop_orders
    WHERE user_id = (SELECT id FROM users WHERE username = 'QA_Fulfil_01');

   DELETE FROM ewallet_ledger
    WHERE user_id = (SELECT id FROM users WHERE username = 'QA_Fulfil_01');

   -- Remove the fixture's QA products (only if no order/package references them)
   DELETE FROM products WHERE sku IN ('QA8-COFFEE', 'QA8-SPICE');

   DELETE FROM users WHERE username = 'QA_Fulfil_01';
   ```

3. Restore any settings you changed (seller block, auto-complete days/switch, order windows).
4. Reload **Shop**, **Orders** and **Admin → Settings**.

**Expected**

- No leftover test orders, shipments or invoices remain; no error pages.
- The shop, the order list and the admin desk still load; the settings show the values you left.
- The dashboard is clean and the next tester starts from a known state.

---

## Cleanup checklist

| Done | Item |
|---|---|
| ☐ | Every check above is ticked or has a defect written up |
| ☐ | Screenshots saved under `docs/qa/evidence/phase-8/` with the names in the tables |
| ☐ | The fixture account `QA_Fulfil_01` and its orders are removed (or left intentionally and noted) |
| ☐ | Seller block / auto-complete settings are restored or the new values are recorded |
| ☐ | `docs/qa/sign-off-sheet.md` has the Phase 8 section filled in |

## Known and accepted behaviour (not bugs)

- **Invoices are not a VAT official receipt.** The footer says so; BIR fields and VAT are later
  (decision D12).
- **Shipping is one way.** There is no reship, RTS or refund flow in this build; a shipped order
  cannot be cancelled here (a returns flow is deferred).
- **An admin may ship their own order.** Segregation of duties guards money decisions (verify,
  reject, cancel) — not the physical fulfilment steps.
- **Invoices from before this phase are issued on first view.** If a paid order had no invoice when
  the phase shipped, opening its invoice page writes the snapshot once. That is the backfill path,
  not a missing document.
- **The invoice button on a cancelled paid order opens the voided document.** That is deliberate:
  the frozen record is kept.
- **The auto-complete job closes orders after days, not hours.** It is safe to run it hourly; a
  second run in the same hour completes nothing.
- **The reference screenshots use demo settings values** (a sample seller address, TIN and phone).
  Replace them with the real company details before go-live.
