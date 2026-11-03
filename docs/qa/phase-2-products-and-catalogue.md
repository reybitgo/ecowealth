# Phase 2 QA — Products & storefront catalogue (manual browser test)

| | |
|---|---|
| **Phase** | 2 — product CRUD, image uploads, member storefront |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §7 Phase 2 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `QA_Member_01 / QaPass!2345` (from Phase 1) |
| **Checks** | 20 (QA2-01 … QA2-20) |
| **Time** | 60–75 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-2/` |

## What this phase is meant to prove

Phase 2 lets an administrator create, edit, deactivate and delete products (with images), and lets
members browse them in the shop with search, pagination, a quick-view modal and a detail page.
This pass verifies the screens look right, the validation messages are understandable, and the
safety rules (file types, file size, delete guard, role guard) really hold.

**Already proven automatically:** P2-T1 create · P2-T2 validation · P2-T3 upload rejection ·
P2-T4 edit · P2-T5 deactivate · P2-T6 delete · P2-T7 delete guard · P2-T8 search/pagination ·
P2-T9 escaping · P2-T10 member 403.

> **Before you start:** Phase 1 should be signed off and `QA_Member_01` must exist. If it does not,
> create it as described in QA1-07. Have the browser at 1440×900, zoom 100%.

---

## Before you begin

1. Laragon → **Start All**; open `http://localhost/ecowealth/system/login` and sign in as `admin`.
2. Create the evidence folder `docs\qa\evidence\phase-2\`.
3. You will create test rows named **`QA …`**. Everything you create in this phase is deleted in the
   cleanup section at the end.
4. Have one ordinary picture ready for the upload check (a screenshot is fine). Save it on your
   Desktop as `qa-photo.png`.
5. For the invalid-file checks you will create two throw-away files with PowerShell — the commands
   are in those checks.

---

## A. The back-office product list

### QA2-01 — The product list shows the seeded catalogue

| | |
|---|---|
| **Why it matters** | This is the administrator’s main catalogue screen; every row and metric must be readable. |
| **Where** | `/admin/products` as `admin` |
| **Evidence** | `QA2-01-products-list.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `http://localhost/ecowealth/system/admin/products`.

**Expected**

- Heading **“Products”** and the line “Manage the catalogue members see in the shop.”
- A **New product** button at the top right.
- Four small cards: **Products (7)**, **Active (7)**, **Inactive (0)**, **Out of stock (0)**.
- A table with columns **Product · Price · Stock · Status · Updated · Actions**.
- Seven seeded rows: Coffee, Immune Booster, Spirulina, Serpentina, Kojic Soap, Stone Buster,
  Colonics Session.
- Prices formatted like **₱4,498.00** style — e.g. Coffee shows **₱300.00**; stock shows two lines
  (“50” and “50 available”).
- Dates look like “Nov 3, 2026 4:12 PM”, not `0000-00-00`.
- Every row has three action buttons: pencil (edit), pause (disable) and trash (delete).

### QA2-02 — Search narrows the list

| | |
|---|---|
| **Why it matters** | With a large catalogue, filtering is the only practical way to find a product. |
| **Where** | `/admin/products` filter bar |
| **Evidence** | `QA2-02-search.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In the search box type `spirulina` and click **Filter**.
2. Clear the search and press **Filter** again (or click **Clear**).

**Expected**

- After the search: **only Spirulina** remains; the URL contains `q=spirulina`.
- The **Clear** button appears while a filter is active; clicking it restores all seven rows.

### QA2-03 — Creating a product with valid data

| | |
|---|---|
| **Why it matters** | Product creation is the core catalogue action. |
| **Where** | `/admin/products/new` |
| **Evidence** | `QA2-03-product-created.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click **New product**.
2. Fill in:
   - **Product name:** `QA Sample Product`
   - **SKU:** `QA-SAMPLE-01`
   - **Price:** `199.50`
   - **Stock on hand:** `5`
   - **Short description:** `QA test product — safe to delete.`
   - **Description:** `Created during the Phase 2 QA run.`
   - **Status:** `Active — visible in the shop`
3. Click **Create product**.

**Expected**

- You return to the product list with a **green** message **“Product created.”**
- The new row appears; its price shows **₱199.50**, stock **5**, status **Active**.
- Its slug is generated (you can see it on the edit page): `qa-sample-product`.

### QA2-04 — Invalid product data is refused with clear messages

| | |
|---|---|
| **Why it matters** | Bad data must never reach the database, and the user must know what to fix. |
| **Where** | `/admin/products/new` |
| **Evidence** | `QA2-04-validation.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click **New product**.
2. Enter **Product name** `Q`, **Price** `0`, **Stock on hand** `-1`, leave everything else empty.
3. Click **Create product**.

**Expected**

- You stay on the product form (it reopens).
- A red box reads **“Please fix the highlighted field(s) and try again.”**
- Field messages:
  - **“Product name must be at least 2 characters.”**
  - **“Price must be greater than 0.”**
  - **“Stock must be at least 0.”**
- The values you typed are still there (`Q`, `0`, `-1`).
- No new “Q” product exists in the list.
- Click **Cancel** to leave the form.

### QA2-05 — Editing a product updates the shop

| | |
|---|---|
| **Why it matters** | The catalogue shown to members must reflect edits immediately. |
| **Where** | product edit form + storefront |
| **Evidence** | `QA2-05-price-updated.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In the product list, click the **pencil** on **QA Sample Product**.
2. Change **Price** to `149.00` and click **Save changes**.
3. Note the product’s id from the edit URL (`/admin/products/<id>/edit`).
4. Open `http://localhost/ecowealth/system/shop/<id>`.

**Expected**

- Saving returns to the list with the green message **“Product updated.”**
- The list row shows **₱149.00**.
- The storefront detail page also shows **₱149.00**.

### QA2-06 — Uploading a product image

| | |
|---|---|
| **Why it matters** | Images are what make the storefront usable; the upload path must work end to end. |
| **Where** | product edit form |
| **Evidence** | `QA2-06-image-uploaded.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Edit **QA Sample Product**.
2. Under **Image**, click the file field and choose your `qa-photo.png`.
3. Observe the preview appear inside the form (before saving).
4. Click **Save changes**.
5. Return to the list, then open the product’s storefront detail page.

**Expected**

- The preview shows the chosen picture immediately.
- After saving, the list row shows a small **thumbnail** instead of the package icon.
- The storefront card and detail page show the picture.

### QA2-07 — Removing a product image

| | |
|---|---|
| **Why it matters** | Admins must be able to clean up an image without recreating the product. |
| **Where** | product edit form |
| **Evidence** | `QA2-07-image-removed.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Edit **QA Sample Product**.
2. Tick **“Remove the current image”** and click **Save changes**.
3. Look at the list and the storefront detail page.

**Expected**

- Ticking the box makes the small preview area show “No image yet” (the current picture stays until
  you save).
- After saving: the list shows the grey package icon again and the storefront shows the placeholder
  block instead of a picture.

### QA2-08 — A script renamed to `.jpg` is rejected

| | |
|---|---|
| **Why it matters** | This is the main upload attack: a PHP script disguised as an image. |
| **Where** | product edit form |
| **Evidence** | `QA2-08-fake-jpg-rejected.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In PowerShell, create the fake file:

   ```powershell
   Set-Content -Path "$env:USERPROFILE\Desktop\qa-fake.jpg" -Value '<?php echo "test";'
   ```
2. Edit **QA Sample Product**.
3. In the image field choose `qa-fake.jpg` (if the file picker hides it, change the filter to
   “All files”).
4. Click **Save changes**.

**Expected**

- The form reopens with a red error under **Upload image**:
  **“That file type is not allowed. Use JPG, PNG, WEBP.”**
- The product’s previous state is unchanged (no broken image, no server error page).

### QA2-09 — A file over 5 MB is rejected

| | |
|---|---|
| **Why it matters** | Very large uploads must not be stored or crash the page. |
| **Where** | product edit form |
| **Evidence** | `QA2-09-oversize-rejected.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In PowerShell, create a 6 MB file:

   ```powershell
   [System.IO.File]::WriteAllBytes("$env:USERPROFILE\Desktop\qa-big.png", (New-Object byte[] (6MB)))
   ```
2. Edit **QA Sample Product**, choose `qa-big.png`, click **Save changes**.

**Expected**

- A red error under **Upload image**: **“The file is too large. The limit is 5 MB.”**
- The page still works normally; nothing is saved.

### QA2-10 — Deactivating a product hides it from the shop

| | |
|---|---|
| **Why it matters** | Disabling is the reversible alternative to deleting — the stock must leave the storefront immediately. |
| **Where** | product list + storefront |
| **Evidence** | `QA2-10-product-hidden.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In the product list, click the **pause** icon on **QA Sample Product**.
2. Search the storefront for it: open `/shop?q=QA Sample Product`.
3. Open its direct storefront URL `/shop/<id>` (the id from the edit URL).
4. Back in the list, click the **play** icon on the same row.

**Expected**

- Step 1: a green message says the product **is now disabled — it is hidden from the shop**, and its
  status badge changes to **Inactive**.
- Step 2: no result — the empty state reads **“No products match your search”**.
- Step 3: a **404** page (“Page not found”).
- Step 4: it is enabled again and reappears in the shop.

### QA2-11 — Seeded products cannot be deleted (they are in packages)

| | |
|---|---|
| **Why it matters** | Products used by a package must never vanish; the app must explain why instead of failing with a server error. |
| **Where** | product list |
| **Evidence** | `QA2-11-delete-blocked.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In the product list, click the **trash** icon on **Coffee** (a seeded product).
2. Read the dialog. Close it with its OK button.
3. Confirm Coffee is still in the list.

**Expected**

- A dialog titled **“Cannot delete”** with the text
  **“This product appears in a package.”** (Coffee is in Package A and Package B.)
- Nothing is deleted; no 500 page appears.
- Hovering the trash icon also shows the reason as a tooltip.

### QA2-12 — A product with no references can be deleted

| | |
|---|---|
| **Why it matters** | Cleanup must be possible for products that were never sold or bundled. |
| **Where** | product list |
| **Evidence** | `QA2-12-product-deleted.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click the **trash** icon on **QA Sample Product**.
2. In the confirmation dialog click **Delete**.

**Expected**

- The dialog asks **“Delete product?”** and names the product.
- After confirming: a green message **“Product deleted.”**, the row disappears, and the
  **Products** counter drops by one.

---

## B. The member storefront

### QA2-13 — The catalogue page looks right

| | |
|---|---|
| **Why it matters** | This is what members see first; layout, prices and stock badges must all be correct. |
| **Where** | `/shop` as `QA_Member_01` |
| **Evidence** | `QA2-13-storefront.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign out and sign in as `QA_Member_01` / `QaPass!2345`.
2. Open `http://localhost/ecowealth/system/shop`.

**Expected**

- Heading **“Shop”** with the line “Browse the EcoWealth catalogue. Checkout arrives with the cart in
  a later release.”
- A search box with a **Search** button.
- Seven product cards in a grid (Coffee, Immune Booster, Spirulina, Serpentina, Kojic Soap,
  Stone Buster, Colonics Session).
- Four cards show photos; the other three show the grey package placeholder.
- Every card shows the name, a short line, the price (e.g. **₱300.00**) and a green **In stock**
  badge.
- Each card has a **Details** button and an **eye** (quick view) button.

### QA2-14 — Quick view opens and links to the detail page

| | |
|---|---|
| **Why it matters** | The modal is the fast path to product information without leaving the catalogue. |
| **Where** | `/shop` |
| **Evidence** | `QA2-14-quick-view.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the Coffee card, click the **eye** button.
2. Read the modal, then click **View details**.

**Expected**

- A modal opens with the product name, price, stock badge and the product description.
- **Close** dismisses it; **View details** opens `/shop/<id>` for Coffee.

### QA2-15 — The product detail page is complete

| | |
|---|---|
| **Why it matters** | The detail page is the reference for Phase 4’s add-to-cart button. |
| **Where** | `/shop/1` (Coffee) |
| **Evidence** | `QA2-15-product-detail.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `http://localhost/ecowealth/system/shop/1`.
2. Scroll the page.

**Expected**

- Breadcrumb “Shop / Coffee”.
- Large product photo, name **Coffee**, badge **In stock**, price **₱300.00**, “50 available”.
- A **disabled** button **“Add to cart”** (hovering explains it arrives in Phase 4) and a
  **Back to shop** button.
- An information box: “Checkout is being built. The cart opens in Phase 4 and payments in Phase 6 —
  this page is the Phase 2 catalogue detail.”
- **About this product** text, then **SKU `EW-COFFEE`** and **Availability**.

### QA2-16 — Search and pagination (optional, adds 14 temporary rows)

| | |
|---|---|
| **Why it matters** | The catalogue must page through large results and keep the search term in the links. |
| **Where** | `/shop` |
| **Evidence** | `QA2-16-pagination.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In phpMyAdmin → `ecowealth_sneat` → **SQL**, run:

   ```sql
   INSERT INTO products (sku, name, slug, price, stock, status, sort_order) VALUES
   ('QA-PAGE-01','QA Page 01','qa-page-01',10.00,5,'active',900),
   ('QA-PAGE-02','QA Page 02','qa-page-02',10.00,5,'active',900),
   ('QA-PAGE-03','QA Page 03','qa-page-03',10.00,5,'active',900),
   ('QA-PAGE-04','QA Page 04','qa-page-04',10.00,5,'active',900),
   ('QA-PAGE-05','QA Page 05','qa-page-05',10.00,5,'active',900),
   ('QA-PAGE-06','QA Page 06','qa-page-06',10.00,5,'active',900),
   ('QA-PAGE-07','QA Page 07','qa-page-07',10.00,5,'active',900),
   ('QA-PAGE-08','QA Page 08','qa-page-08',10.00,5,'active',900),
   ('QA-PAGE-09','QA Page 09','qa-page-09',10.00,5,'active',900),
   ('QA-PAGE-10','QA Page 10','qa-page-10',10.00,5,'active',900),
   ('QA-PAGE-11','QA Page 11','qa-page-11',10.00,5,'active',900),
   ('QA-PAGE-12','QA Page 12','qa-page-12',10.00,5,'active',900),
   ('QA-PAGE-13','QA Page 13','qa-page-13',10.00,5,'active',900),
   ('QA-PAGE-14','QA Page 14','qa-page-14',10.00,5,'active',900);
   ```
2. Open `/shop` (no search). Count the cards on the first page.
3. Scroll to the bottom of the list and click **2** in the pagination.
4. Open `/shop?q=QA Page` and check the pagination links.

**Expected**

- Page 1 shows **12 cards**; a pagination row appears with the summary “Showing 1–12 of 21” (or 22 if
  a QA product still exists).
- Page 2 shows the remaining cards.
- On the search page, the page links keep the search: their URLs contain `page=2` **and**
  `q=QA+Page` (or `q=QA%20Page`).
- If you skip this check, the rest of Phase 2 still works — mark it **Blocked** with the reason
  “optional data setup skipped”.

### QA2-17 — Product text is escaped (no script runs)

| | |
|---|---|
| **Why it matters** | A product name is user input; it must never execute in another member’s browser. |
| **Where** | admin product form + storefront |
| **Evidence** | `QA2-17-xss-escaped.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, create a product:
   - **Name:** `<script>alert(1)</script> QA XSS`
   - **Price:** `1.00`
   - **Stock:** `1`
2. Open `/shop?q=QA XSS`.

**Expected**

- The card shows the text **`<script>alert(1)</script> QA XSS` literally**.
- **No pop-up alert appears.**
- If you view the page source (`Ctrl+U`), the name appears as
  `&lt;script&gt;alert(1)&lt;/script&gt;`, not as real `<script>` tags.
- Delete this product afterwards (it has no packages, so deletion succeeds).

### QA2-18 — A member cannot open the product admin pages

| | |
|---|---|
| **Why it matters** | The product editor is admin-only, and the guard must work on the URL, not just in the menu. |
| **Where** | `/admin/products` as `QA_Member_01` |
| **Evidence** | `QA2-18-member-403.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. While signed in as `QA_Member_01`, open `http://localhost/ecowealth/system/admin/products`.
2. Try `.../admin/products/new` as well.

**Expected**

- Both URLs show the **403 “Access denied”** page (not the product list).
- Nothing is created.

### QA2-19 — Catalogue and admin table on a phone-sized screen

| | |
|---|---|
| **Why it matters** | The storefront is the most likely page to be opened on a phone. |
| **Where** | `/shop` and `/admin/products` at 390 × 844 |
| **Evidence** | `QA2-19-mobile-shop.png`, `QA2-19-mobile-admin-products.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin`.
2. DevTools → `Ctrl+Shift+M` → **iPhone 12 Pro** (390 × 844).
3. Open `/shop` and scroll.
4. Open `/admin/products` and scroll horizontally inside the table.

**Expected**

- Shop cards stack one per row; images fit; prices and buttons readable; no sideways page scroll.
- The admin product table scrolls **inside its card** (a horizontal scrollbar under the table); the
  page header and buttons stay usable.

---

## C. Regression

### QA2-20 — Earlier phases still behave

| | |
|---|---|
| **Why it matters** | Phase 2 touched the router, menu and storefront; Phase 1 behaviour must remain intact. |
| **Where** | guest `/shop`, `/packages`, admin menu |
| **Evidence** | `QA2-20-regression.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In an Incognito window open `/shop` → expect the login redirect with “Please sign in to continue.”
2. As `QA_Member_01` open `/packages` → expect the Associate packages page to load.
3. As `admin` open `/admin` → the sidebar shows **Products** as a normal link (no “Soon” pill).

**Expected**

- All three behave as described; nothing regressed.

---

## Phase 2 cleanup

1. Delete the QA sample product if it still exists (trash icon → **Delete**).
2. Remove the temporary pagination rows in phpMyAdmin:

   ```sql
   DELETE FROM products WHERE sku LIKE 'QA-PAGE-%';
   ```

3. Delete the two files you made on the Desktop (`qa-fake.jpg`, `qa-big.png`, `qa-photo.png`).
4. Sign out (or stay signed in as `QA_Member_01` if you continue to Phase 3).
5. Confirm the catalogue is back to its seeded state:

   ```sql
   SELECT COUNT(*) FROM products;   -- expected: 7
   ```

6. Fill the **Phase 2** block of `docs/qa/sign-off-sheet.md`.

---

## Phase 2 result summary

| Item | Value |
|---|---|
| Checks executed | ____ / 20 |
| Passed | ____ |
| Failed | ____ |
| Blocked | ____ |
| Defects raised | ____ (see sign-off sheet) |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester / date | __________________________ |

**Notes for the developer**

```text

```
