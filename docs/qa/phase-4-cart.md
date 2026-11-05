# Phase 4 QA — Cart engine & cart UI (manual browser test)

| | |
|---|---|
| **Phase** | 4 — persistent cart, stock-aware add/update/remove, off-canvas drawer |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §5.2, §7 Phase 4 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `QA_Member_01 / QaPass!2345` (from Phase 1) |
| **Checks** | 17 (QA4-01 … QA4-17) |
| **Time** | 55–70 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-4/` |

## What this phase is meant to prove

Phase 4 turns the storefront into a working shop: a member can put products in a cart, change quantities,
remove lines, and see the same numbers in three places — the cart page, the off-canvas drawer and the
navbar badge. Stock is enforced against `stock − reservations`, prices always come from the catalogue,
and one member can never touch another member's line.

Two rules matter most:

1. **The cart never oversells.** Adding beyond the available count is refused with the exact numbers,
   and the refused attempt changes nothing.
2. **A posted line id is not proof of ownership.** Every update and remove is scoped to the signed-in
   member's own cart.

**Already proven automatically:** P4-T1 add · P4-T2 repeat add · P4-T3 stock refusal · P4-T4 update/remove ·
P4-T5/T6 IDOR · P4-T7 live price · P4-T8 isolation · P4-T9 concurrency · P4-T10 inactive product ·
P4-T11 JSON contract · P4-T12 no-JS add · P4-T13 drawer refresh.

**Already captured headlessly** (you can reuse these as reference screenshots, but still run the checks):

| File | Shows |
|---|---|
| `final-cart-desktop.png` | cart page with two lines (Coffee ×2, Spirulina ×1) |
| `final-cart-drawer.png` | drawer open over the shop, badge 3 |
| `final-cart-ajax-add.png` | “Added to cart.” toast over the drawer |
| `final-cart-updated.png` | quantity changed to 3, totals ₱900.00 |
| `final-cart-empty.png` | empty-cart state |
| `final-cart-mobile.png` | cart at 390 × 844 |
| `final-cart-drawer-mobile.png` | drawer at 390 × 844 |
| `final-shop-add-to-cart.png` | product cards with the new cart button |
| `final-cart-sidebar-badge.png` | sidebar + navbar badges both at 2 after an in-place add |
| `final-cart-nojs-stepper.png` | cart page after clicking **+** with JavaScript disabled |
| `final-admin-cart.png` | the admin sidebar's Cart item (active, badge 2) |

> **Before you start:** Phases 1–3 should be signed off. The automated suite must be green
> (`php tests\run.php` → 171 cases; `powershell -NoProfile -File tests\smoke.ps1` → 54 checks). Create
> `docs\qa\evidence\phase-4\` for your own screenshots.

---

## A. Cart basics

### QA4-01 — An empty cart invites the member to the shop

| | |
|---|---|
| **Why it matters** | The blank state is the first thing a new member sees; it must explain what to do. |
| **Where** | `/cart` as `QA_Member_01` with nothing added |
| **Evidence** | `QA4-01-empty-cart.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `QA_Member_01`.
2. Open `http://localhost/ecowealth/system/cart`.

**Expected**

- Heading **“Your cart”** and the line “Prices and stock are read from the catalogue every time you open this page.”
- A card with a cart icon, **“Your cart is empty”**, the explanation, and a **Browse the shop** button.
- No table, no **Proceed to checkout** button.
- Sidebar **Cart** has no number badge; the navbar cart icon has no badge either.

### QA4-02 — Adding from a product card

| | |
|---|---|
| **Why it matters** | This is the main add path from the catalogue. |
| **Where** | `/shop` |
| **Evidence** | `QA4-02-add-from-card.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/shop`.
2. On **Coffee**, click the small cart icon button between the eye (quick view) and **Details**.
3. Watch the bottom-right corner and the right edge of the page.

**Expected**

- A green toast **“Added to cart.”** appears.
- The cart drawer slides in from the right, headed **Cart** with badge **1**, listing Coffee with
  **₱300.00 each** and line total **₱300.00**.
- The navbar cart badge and the sidebar **Cart** badge both show **1** immediately — without a page reload,
  even when the cart started empty.
- Close the drawer with the **×**.

### QA4-03 — Adding the same product again increments one line

| | |
|---|---|
| **Why it matters** | A repeated add must not create a duplicate line (one line per product). |
| **Where** | `/shop` → drawer |
| **Evidence** | `QA4-03-repeat-add.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. With Coffee already in the cart, click the same cart icon on **Coffee** again.
2. Open `/cart`.

**Expected**

- The drawer still shows **one** Coffee line, quantity **2**, line total **₱600.00**.
- The cart page has one Coffee row, quantity **2**, line total **₱600.00**.
- Badges show **2**.

### QA4-04 — The drawer stepper updates quantity and totals

| | |
|---|---|
| **Why it matters** | The drawer is the fast path; its +/− must save, not just repaint. |
| **Where** | drawer, on any member page |
| **Evidence** | `QA4-04-drawer-stepper.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/shop` and click the navbar cart icon to open the drawer.
2. On the Coffee line click **+** once, then **−** once.
3. Reload the page (`F5`) and reopen the drawer.

**Expected**

- Each click updates the quantity field, the line total, **Items**, **Subtotal**, **Total** and both badges
  without a full page reload.
- After **+** the quantity is **3** and the line total **₱900.00**.
- After **−** it is back to **2** / **₱600.00**.
- After the reload the values are still the saved ones (the change reached the server).

### QA4-05 — Editing and removing on the cart page

| | |
|---|---|
| **Why it matters** | The full cart page must offer the same control as the drawer, plus a clear remove action. |
| **Where** | `/cart` |
| **Evidence** | `QA4-05-cart-edit.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add **Coffee ×1** and **Spirulina ×1** (QA4-02 shows how).
2. Open `/cart`.
3. Change the Coffee quantity to **3** and press **Update** (or press Enter).
4. Click the red **trash** icon on Spirulina.

**Expected**

- After step 3 the row’s **Line total** and the **Order summary** (Items, Subtotal, Total) show **₱900.00**
  for Coffee; the sidebar/navbar badge follows.
- After step 4 Spirulina’s row disappears immediately and the totals drop to Coffee only.
- Reload: the cart still matches the screen.

### QA4-06 — Quantity 0 removes a line

| | |
|---|---|
| **Why it matters** | The “type 0 to remove” gesture is documented; it must not write an empty line. |
| **Where** | `/cart` |
| **Evidence** | `QA4-06-zero-removes.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. With one line in the cart, type **0** in its quantity box and press **Update**.

**Expected**

- The line disappears and the cart becomes empty (empty state returns).
- Both badges disappear.
- Reloading `/cart` still shows the empty state.

---

## B. Stock and price rules

### QA4-07 — Adding beyond the available stock is refused with the numbers

| | |
|---|---|
| **Why it matters** | The cart is the first line of defence against overselling. |
| **Where** | `/admin/products` + `/cart` |
| **Evidence** | `QA4-07-stock-refusal.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin` open `/admin/products`, edit **Coffee** and set **Stock** to **2**; save.
2. As `QA_Member_01`, put Coffee **1** in the cart.
3. On `/shop` click Coffee’s cart icon twice more (each add asks for 1).

**Expected**

- The first extra add succeeds (quantity **2** = stock 2).
- The second shows an error toast **“Insufficient stock. Requested 3, only 2 available.”**
- The quantity stays **2** and the cart is otherwise unchanged.
- Set Coffee’s stock back to **50** in `/admin/products` when done.

### QA4-08 — An over-stock line blocks checkout and explains why

| | |
|---|---|
| **Why it matters** | Stock can shrink while an item sits in a cart; the page must warn and stop checkout. |
| **Where** | `/cart` |
| **Evidence** | `QA4-08-over-stock.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add **Coffee ×3** to the cart.
2. As `admin`, set Coffee’s stock to **1**; save.
3. Reload `/cart` as the member.

**Expected**

- A yellow alert **“Some items need attention before checkout”** lists
  **“Coffee: requested 3, only 1 available.”**
- The Coffee row shows a red **Only 1 left** badge and the row is tinted.
- The summary hint changes to **“Fix the quantities marked above to continue.”** and the checkout button
  stays disabled.
- Lower the quantity to **1** and the alert disappears.
- Restore Coffee stock to **50**.

### QA4-09 — A catalogue price change reaches the cart

| | |
|---|---|
| **Why it matters** | The cart must never charge an old snapshot price. |
| **Where** | `/admin/products` + `/cart` |
| **Evidence** | `QA4-09-price-change.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add **Kojic Soap** (₱100.00) ×2 to the cart; note **₱200.00**.
2. As `admin`, change Kojic Soap’s price to **₱120.00**; save.
3. Reload `/cart` as the member.

**Expected**

- The unit price shows **₱120.00**, the line total **₱240.00**, and the summary total **₱240.00**.
- The values are already correct on the first reload — no “refresh again” step.
- Restore Kojic Soap to **₱100.00** afterwards.

### QA4-10 — An inactive product is flagged, not silently lost

| | |
|---|---|
| **Why it matters** | Admins retire products; a cart that contains one must tell the member why. |
| **Where** | `/admin/products` + `/cart` |
| **Evidence** | `QA4-10-inactive-line.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Add **Serpentina** ×1 to the cart.
2. As `admin`, disable Serpentina (pause icon).
3. Reload `/cart` as the member.
4. Try **+** on its quantity, then set the quantity to **0** and press **Update**.

**Expected**

- The line stays visible with a grey **Unavailable** badge and the alert
  **“Serpentina” is no longer available. Remove it before checking out.**
- Clicking **+** shows an error toast and the quantity does not change.
- Quantity **0** removes the line.
- Re-enable Serpentina afterwards.

### QA4-11 — Low-stock and sold-out badges stay accurate

| | |
|---|---|
| **Why it matters** | The catalogue badge and the cart enforce the same `available` number. |
| **Where** | `/shop`, `/admin/products` |
| **Evidence** | `QA4-11-low-stock.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, set **Stone Buster** stock to **4** and **Immune Booster** stock to **0**; save both.
2. Open `/shop` as the member.

**Expected**

- Stone Buster shows **4 left** (warning tone), Immune Booster shows **Sold out** with no add button.
- Immune Booster’s detail page replaces the add form with a disabled **Sold out** button.
- Restore Stone Buster to **25** and Immune Booster to **40** afterwards.

---

## C. Security and no-JS fallback

### QA4-12 — Another member cannot touch this cart (IDOR)

| | |
|---|---|
| **Why it matters** | Cart line ids are sequential; they must never authorise a write on their own. |
| **Where** | two browser profiles or one normal + one private window |
| **Evidence** | `QA4-12-idor.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In a normal window as `QA_Member_01`, add Coffee ×1 and note the item id (DevTools → **Network** →
   the `/cart/add` response, or the `data-item-id` attribute on the row).
2. In a private window, register a throw-away member (`QA_Cart_Other`, `QaPass!2345`), sign in and add
   any product of their own.
3. With the private window’s session, run this in the browser console on `/cart`:

   ```js
   const cfg = document.getElementById('cartConfig').dataset;
   fetch(cfg.updateUrl, {
     method: 'POST',
     headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf },
     body: new URLSearchParams({ _csrf: cfg.csrf, item_id: '<ITEM_ID_FROM_STEP_1>', quantity: '9' })
   }).then(r => r.json()).then(console.log);
   ```

4. Reload the first member’s cart.

**Expected**

- The console prints `{ ok: false, code: "item_missing", message: "That item is no longer in your cart." }`.
- The first member’s line is unchanged (still quantity 1).
- The private member’s own cart is unaffected too.

### QA4-13 — The cart still works with JavaScript disabled

| | |
|---|---|
| **Why it matters** | Every control is a real form; the shop must not depend on the script. |
| **Where** | `/shop` with JS off |
| **Evidence** | `QA4-13-no-js.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. DevTools → **Settings** (gear) → **Debugger** → tick **Disable JavaScript**. Reload `/shop`.
2. Open a product (e.g. `/shop/2`), set quantity **2**, click **Add to cart**.
3. Open `/cart` and change a quantity with **Update**.
4. Click the row's **+** button twice, then its **−** button once.
5. Press Enter while the quantity field is focused (type a new number first).
6. Remove a line with the trash button.

**Expected**

- Each action performs a normal page submit, the page reloads and shows the green flash
  **“Added to cart.”** / **“Cart updated.”** / **“Item removed from your cart.”**
- The **+/−** buttons act as submit buttons: the quantity and all totals change after the reload
  (step 4 ends on quantity 2), so no control is dead without JavaScript.
- Pressing Enter in the quantity field submits the typed number (not a step).
- No dead buttons and no console errors about missing scripts.
- Re-enable JavaScript afterwards.

### QA4-14 — The cart is member-only

| | |
|---|---|
| **Why it matters** | Cart data is personal; a guest must be sent to sign-in. |
| **Where** | private window |
| **Evidence** | `QA4-14-guest-redirect.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In a private window open `http://localhost/ecowealth/system/cart`.

**Expected**

- You land on the login page with the flash **“Please sign in to continue.”**
- `http://localhost/ecowealth/system/cart/count` also redirects to login (it is a JSON endpoint, not a
  public one).

---

## D. Mobile and regression

### QA4-15 — The cart works on a phone-sized screen

| | |
|---|---|
| **Why it matters** | Shopping is mobile-first; the cart must not force sideways scrolling. |
| **Where** | `/cart` and the drawer at 390 × 844 |
| **Evidence** | `QA4-15-mobile-cart.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. DevTools → `Ctrl+Shift+M` → **iPhone 12 Pro** (390 × 844).
2. Add two products, open `/cart`, then open the drawer from the navbar cart icon.

**Expected**

- On `/cart` each row shows the product, the **quantity stepper with its number visible**, and the red
  trash button — no horizontal scrolling and no cut-off control. The unit price and line total columns are
  hidden on this width; the **Order summary** card carries the money.
- The drawer fills the screen width, lists both lines with readable quantities, and its totals/footer stay
  visible.
- Cards on `/shop` show only the cart icon and **Details** (quick view is hidden on phones).

### QA4-16 — Toasts are visible and the console is clean

| | |
|---|---|
| **Why it matters** | The cart reports success and refusal through toasts; a hidden toast is a silent failure. |
| **Where** | `/shop`, `/cart`, drawer |
| **Evidence** | `QA4-16-toast.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. DevTools → **Console** (keep it open).
2. Add a product, click **+** and **−**, remove a line, and trigger one stock refusal (QA4-07).
3. Watch the bottom-right corner each time.

**Expected**

- Every success and every refusal shows a toast **above the drawer** (green for success, red for
  refusals). Toasts fade on their own after a few seconds.
- The console has **no red errors**.
- Clicking a toast’s **×** dismisses it.

### QA4-17 — Earlier phases still behave

| | |
|---|---|
| **Why it matters** | Phase 4 changed the shared footer, navbar, product cards and product page. |
| **Where** | `/shop`, `/shop/{id}`, `/admin/products`, `/admin`, `/packages` |
| **Evidence** | `QA4-17-regression.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As the member open `/shop`, `/shop/1` and `/packages`.
2. As `admin` open `/admin`, `/admin/products` and `/admin/packages`.
3. As `admin` open `/cart` (admins may shop too — the back-office sidebar must stay).

**Expected**

- The catalogue, detail page and packages page render exactly as in Phase 3, plus the cart button on the
  detail page.
- The back office pages are unchanged. The admin sidebar now lists **Cart** under **Overview**, right
  after **Storefront**, carrying the same live badge as the navbar icon.
- Step 3 renders the cart inside the **back-office** layout: Dashboard / Storefront / **Cart** stay in the
  sidebar, Cart is highlighted, and its badge matches the number of units in the admin's own cart (the
  admin's cart is separate from any member's).
- No 404s in DevTools → **Network** for CSS/JS (hard refresh with `Ctrl+F5` first).

---

## Phase 4 cleanup

1. Remove the QA member and their cart:

   ```sql
   DELETE FROM users WHERE username LIKE 'QA_Cart_%';
   ```

   Carts and cart lines are removed automatically by the cascade.

2. Confirm the seeded stock and prices are back:

   ```sql
   SELECT sku, price, stock, status FROM products WHERE sku LIKE 'EW-%' ORDER BY id;
   ```

   Expected (from the Phase 2/3 seeds): Coffee 300/50, Immune Booster 500/40, Spirulina 400/60,
   Serpentina 400/35, Kojic Soap 100/100, Stone Buster 600/25, Colonics Session 1500/20 — all `active`.

3. Confirm the test member’s cart is empty:

   ```sql
   SELECT COUNT(*) FROM cart_items ci
   JOIN carts c ON c.id = ci.cart_id
   JOIN users u ON u.id = c.user_id
   WHERE u.username = 'QA_Member_01';       -- expected: 0
   ```

4. Sign out.
5. Fill the **Phase 4** block of `docs/qa/sign-off-sheet.md`.

---

## Phase 4 result summary

| Item | Value |
|---|---|
| Checks executed | ____ / 17 |
| Passed | ____ |
| Failed | ____ |
| Blocked | ____ |
| Defects raised | ____ (see sign-off sheet) |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester / date | __________________________ |

**Notes for the developer**

```text

```
