# Phase 3 QA — Packages & referral ladder (manual browser test)

| | |
|---|---|
| **Phase** | 3 — associate packages, the 2–10 level matrix and package contents |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §7 Phase 3 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `QA_Member_01 / QaPass!2345` (from Phase 1) |
| **Checks** | 16 (QA3-01 … QA3-16) |
| **Time** | 50–65 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-3/` |

## What this phase is meant to prove

Phase 3 lets an administrator define associate packages: the price, the product value, the **direct
referral bonus** (level 1) and the **unilevel ladder for levels 2–10**, plus what the package
contains. Members get a packages page that presents the same numbers.

The most important rule in this phase: **level 1 is the direct bonus and is stored only once**. The
form must show it read-only, and the database must never contain a separate “level 1” row.

**Already proven automatically:** P3-T1 create · P3-T2 level edit · P3-T3 validation and rollback ·
P3-T4 contents · P3-T5 level-1 rule · P3-T6 deactivation · P3-T7 member 403 · P3-T8 public page.

> **Before you start:** Phases 1–2 should be signed off. Sign in as `admin`, open
> `http://localhost/ecowealth/system/admin/packages`, and create the evidence folder
> `docs\qa\evidence\phase-3\`.

---

## A. The back-office package list

### QA3-01 — The package list shows Package A and Package B

| | |
|---|---|
| **Why it matters** | This table is the administrator’s summary of every package and its money rules. |
| **Where** | `/admin/packages` |
| **Evidence** | `QA3-01-packages-list.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `http://localhost/ecowealth/system/admin/packages`.

**Expected**

- Heading **“Packages”**, line “Associate packages, their referral ladder and their contents.”
- A **New package** button.
- Three cards: **Packages (2)**, **Active (2)**, **Inactive (0)**.
- Table columns: **Package · Price · Product value · Level 1 (direct) · Levels 2–10 · Contents ·
  Status · Actions**.
- **Package A** row: price **₱4,498.00**, product value **₱4,750.00**, level 1 **₱500.00**,
  levels 2–10 **₱265.00** with “9 paying levels”, contents **5**, status **Active**.
- **Package B** row: price **₱6,998.00**, product value **₱8,300.00**, level 1 **₱500.00**,
  levels 2–10 **₱265.00**, contents **5**.
- Each row has only two actions: pencil (edit) and pause (disable) — **there is no delete button**
  (packages are deactivated instead of deleted, by design).

### QA3-02 — Creating a package with a full ladder and contents

| | |
|---|---|
| **Why it matters** | This is the main admin workflow of the phase: one save writes the package, the ladder and the contents together. |
| **Where** | `/admin/packages/new` |
| **Evidence** | `QA3-02-package-created.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click **New package**.
2. In **Package details** enter:
   - **Package name:** `QA Test Package`
   - **Description:** `Created during the Phase 3 QA run.`
   - **Perks:** two lines — `QA perk one` and `QA perk two`
3. In **Pricing & bonuses** enter:
   - **Price:** `999.00`
   - **Product value:** `1100.00`
   - **Direct referral bonus (level 1):** `50.00`
4. In **Referral ladder**, fill levels 2–10 with `10`, `9`, `8`, `7`, `6`, `5`, `4`, `3`, `2`.
5. In **Package contents**, choose **Coffee** with quantity `2`; click **Add product** and choose
   **Spirulina** with quantity `1`.
6. Click **Create package**.

**Expected**

- You return to the list with a green message **“Package created.”**
- The new row shows: price **₱999.00**, product value **₱1,100.00**, level 1 **₱50.00**,
  levels 2–10 **₱54.00** (“9 paying levels”), contents **2**.
- Re-opening it (pencil) shows every value you entered.

### QA3-03 — Level 1 is read-only and mirrors the direct bonus

| | |
|---|---|
| **Why it matters** | Entering level 1 twice (once as a level, once as the direct bonus) is the classic way to double-pay a sponsor. |
| **Where** | package form |
| **Evidence** | `QA3-03-level-1-readonly.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open **QA Test Package** for editing (pencil).
2. Scroll to **Referral ladder**. Find the **Level 1 — Direct referral** row.
3. Read the amount shown there.
4. In **Pricing & bonuses**, change **Direct referral bonus (level 1)** to `75.00`.

**Expected**

- The Level 1 row is **text, not an input** — you cannot type in it.
- It shows the same amount as the direct-bonus field (`₱50.00` when the form opens).
- Typing `75.00` in the direct-bonus field updates the Level 1 row to **₱75.00** immediately.
- Rows for Levels 2–10 are normal inputs; there is **no input named “level 1”** anywhere.
- Undo your change: set the field back to `50.00` before leaving the page (or reload without saving).

### QA3-04 — Editing one level persists

| | |
|---|---|
| **Why it matters** | The matrix is the package’s compensation offer; an edit must survive a reload. |
| **Where** | package edit form |
| **Evidence** | `QA3-04-level-edited.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Edit **QA Test Package**.
2. Change **Level 3** from `9` to `50` and click **Save changes**.
3. Re-open the package (pencil) and look at Level 3.
4. Look at the **Levels 2–10** column in the list.

**Expected**

- Saving shows the green message **“Package updated.”**
- Level 3 reopens as **`50.00`**.
- The other levels are unchanged (`10`, `8`, `7`, `6`, `5`, `4`, `3`, `2`).
- The list now shows levels 2–10 as **₱95.00** (10 + 50 + 8 + 7 + 6 + 5 + 4 + 3 + 2).

### QA3-05 — Invalid package data is refused with clear messages

| | |
|---|---|
| **Why it matters** | A negative or over-precise bonus must never reach the database. |
| **Where** | `/admin/packages/new` |
| **Evidence** | `QA3-05-validation.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click **New package**.
2. Enter: **Package name** empty, **Price** `0`, **Direct referral bonus** `-5`.
3. Enter **Level 2** `-1`, **Level 3** `10.555`, **Level 4** `1000001`.
4. Click **Create package**.

**Expected**

- Remains on the form with the red box **“Please fix the highlighted field(s) and try again.”**
- Field messages:
  - **“Package name is required.”**
  - **“Price must be greater than 0.”**
  - **“Direct referral bonus must be at least 0.”**
  - **“Level 2 must be at least 0.”**
  - **“Level 3 must be a number with at most 2 decimal places.”**
  - **“Level 4 must be at most 1000000.”**
- The values you typed are still shown (`-1`, `10.555`, `1000001`).
- No package named “” (empty) appears in the list; click **Cancel**.

### QA3-06 — Level 1 is not stored as a level row (database check)

| | |
|---|---|
| **Why it matters** | The read-only form is only half the guarantee — the table itself must contain levels 2–10 only. |
| **Where** | phpMyAdmin |
| **Evidence** | `QA3-06-levels-table.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Find the QA package id in the edit URL (`/admin/packages/<id>/edit`). Assume it is `3`.
2. In phpMyAdmin → `ecowealth_sneat` → **SQL**, run (replace `3` with your id):

   ```sql
   SELECT level, amount FROM package_levels WHERE package_id = 3 ORDER BY level;
   ```

3. Run the same query for Package A:

   ```sql
   SELECT level, amount FROM package_levels WHERE package_id = 1 ORDER BY level;
   ```

**Expected**

- The first query returns **9 rows: levels 2, 3, 4, 5, 6, 7, 8, 9, 10** — there is **no level 1 row**.
- Package A returns 9 rows too, with amounts 100.00, 50.00, 40.00, 30.00, 20.00, 10.00, 5.00, 5.00,
  5.00.
- The direct bonus (50.00 for the QA package, 500.00 for Package A) lives on the `packages` table,
  not here.

### QA3-07 — Duplicate content rows merge

| | |
|---|---|
| **Why it matters** | The admin must not accidentally end up with two separate lines for the same product. |
| **Where** | package edit form |
| **Evidence** | `QA3-07-contents-merged.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Edit **QA Test Package**.
2. In **Package contents** click **Add product** twice so there are two extra blank rows.
3. Choose **Coffee** with quantity `1` in one row and **Coffee** with quantity `2` in another.
4. Click **Save changes**.
5. Re-open the package and look at the contents.

**Expected**

- After saving there is **one Coffee row only**, quantity **3** (1 + 2 merged).
- The other content (Spirulina ×1) is unchanged.

### QA3-08 — Contents only accept active products (advanced)

| | |
|---|---|
| **Why it matters** | Retired products must not be added to new bundles, but an existing package must keep working when a product is temporarily disabled. |
| **Where** | product list + package forms |
| **Evidence** | `QA3-08-inactive-content.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In `/admin/products`, click **pause** on **Serpentina** (status becomes Inactive).
2. Open `/admin/packages/new` and open one of the product dropdowns in **Package contents**.
3. Open `/admin/packages/1/edit` (Package A) and look at its Serpentina row.
4. Click **Save changes** on Package A without changing anything.
5. Go back to `/admin/products` and click **play** on Serpentina.

**Expected**

- Step 2: the dropdown does **not** list Serpentina.
- Step 3: Package A still lists **Serpentina (inactive)** in its contents, so history is preserved.
- Step 4: saving succeeds with the green message **“Package updated.”** — an already-linked inactive
  product is allowed to stay.
- Step 5: Serpentina is Active again. **Always restore it.**

---

## B. Deactivation and the member page

### QA3-09 — Deactivating a package hides it from members

| | |
|---|---|
| **Why it matters** | Disabling is the supported alternative to deleting; it must take effect immediately. |
| **Where** | `/admin/packages` + `/packages` |
| **Evidence** | `QA3-09-package-hidden.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, click **pause** on **Package B**.
2. Sign out, sign in as `QA_Member_01`, open `/packages`.
3. Sign out, sign in as `admin`, click **play** on **Package B**.

**Expected**

- Step 1: green message that Package B **is now disabled — it is hidden from the packages page**;
  its badge turns **Inactive**.
- Step 2: Package B is gone; **Package A** and any other active package (for example the
  **QA Test Package** you created) remain visible.
- Step 3: both packages are visible again.

### QA3-10 — Packages have no delete action (by design)

| | |
|---|---|
| **Why it matters** | Confirms the “deactivate instead of delete” rule is what the build actually ships. |
| **Where** | `/admin/packages` and the package form |
| **Evidence** | `QA3-10-no-delete.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On `/admin/packages`, inspect the Actions column of both rows.
2. Open a package with the pencil and read the form’s action buttons at the bottom.

**Expected**

- The list shows only **pencil** and **pause/play**; no trash icon.
- The form has **Save changes** and **Cancel** only; no delete button.

### QA3-11 — The member packages page presents the packages correctly

| | |
|---|---|
| **Why it matters** | This is the page members judge the offer by; every number must match the admin data. |
| **Where** | `/packages` as `QA_Member_01` |
| **Evidence** | `QA3-11-packages-page.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `QA_Member_01`.
2. Open `http://localhost/ecowealth/system/packages`.
3. Look at the Package A and Package B cards.

**Expected**

- Heading **“Associate packages”** with the explanation about entry point, direct referral and
  unilevel amounts.
- An information box saying activation opens with the membership module (Phase 9).
- **Package A** card: price **₱4,498.00**, “Product value ₱4,750.00”, **Direct referral (level 1)
  ₱500.00**, **Levels 2–10 ₱265.00**, **“10 of 10 levels pay”**, a **Show all levels** control,
  a **Package contents** list including “2 × Spirulina”, a **What you get** list from the perks, and a
  **disabled** button **“Activation opens in Phase 9”**.
- **Package B** card: price **₱6,998.00**, “Product value ₱8,300.00”, level 1 **₱500.00**, and its
  contents include **“1 × Colonics Session”**.
- A third card for **QA Test Package** appears because you created it as active: price
  **₱999.00**, level 1 **₱50.00**, levels 2–10 **₱95.00** (after the QA3-04 edit), and its contents
  show Coffee and Spirulina.
- No broken images, overlapping text or cut-off prices.

### QA3-12 — The full ladder opens on demand

| | |
|---|---|
| **Why it matters** | The details must show ten levels with level 1 clearly identified as the direct bonus. |
| **Where** | `/packages` |
| **Evidence** | `QA3-12-ladder.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On Package A, click **Show all levels**.
2. Read the table.

**Expected**

- Ten rows: **Direct referral** (marked with a small “stored on the package” badge) and **Level 2**
  through **Level 10**.
- Amounts: ₱500.00, ₱100.00, ₱50.00, ₱40.00, ₱30.00, ₱20.00, ₱10.00, ₱5.00, ₱5.00, ₱5.00.
- Clicking again collapses it.

### QA3-13 — A member cannot open the package admin pages

| | |
|---|---|
| **Why it matters** | The package editor controls money rules; it must be admin-only. |
| **Where** | `/admin/packages` as `QA_Member_01` |
| **Evidence** | `QA3-13-member-403.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. While signed in as `QA_Member_01`, open `http://localhost/ecowealth/system/admin/packages`.
2. Also try the edit URL of Package A (`/admin/packages/1/edit`).

**Expected**

- Both show the **403 “Access denied”** page.
- The member menu never showed a “Packages” admin link (only the member Packages page).

---

## C. Mobile and regression

### QA3-14 — The packages page works on a phone-sized screen

| | |
|---|---|
| **Why it matters** | Package comparisons are likely to be read on a phone. |
| **Where** | `/packages` at 390 × 844 |
| **Evidence** | `QA3-14-mobile-packages.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `QA_Member_01`.
2. DevTools → `Ctrl+Shift+M` → **iPhone 12 Pro** (390 × 844).
3. Open `/packages`; expand **Show all levels**.

**Expected**

- Cards stack one per row.
- The price and the direct-bonus box remain readable side by side or stack neatly.
- The ladder table fits or scrolls inside its card — the page itself does not scroll sideways.
- The disabled activation button is not cut off.

### QA3-15 — Earlier phases still behave

| | |
|---|---|
| **Why it matters** | Phase 3 touched shared navigation and the product delete guard. |
| **Where** | `/shop`, `/admin/products`, `/admin` |
| **Evidence** | `QA3-15-regression.png` |
| **Result** | ☑ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `QA_Member_01` open `/shop` — the catalogue still renders.
2. As `admin` open `/admin/products` — the list still renders and the delete button on Coffee is
   blocked with “This product appears in a package.”
3. As `admin` open `/admin` — the sidebar shows **Packages** as a normal link (no “Soon” pill).

**Expected**

- All three behave as described.

---

## Phase 3 cleanup

1. Remove the QA package (remember: packages have no delete button). In phpMyAdmin:

   ```sql
   DELETE FROM packages WHERE name LIKE 'QA %';
   ```

   This also removes its ladder and content rows automatically.

2. If you created the QA member only for this QA cycle and all three phases are signed, remove it:

   ```sql
   DELETE FROM users WHERE username LIKE 'QA%';
   ```

   Otherwise keep `QA_Member_01` for re-tests.

3. Confirm the seeded state is back:

   ```sql
   SELECT COUNT(*) FROM packages;         -- expected: 2
   SELECT COUNT(*) FROM package_levels;   -- expected: 18
   SELECT COUNT(*) FROM package_products; -- expected: 10
   SELECT COUNT(*) FROM products;         -- expected: 7
   ```

4. Sign out.
5. Fill the **Phase 3** block of `docs/qa/sign-off-sheet.md`.

---

## Phase 3 result summary

| Item | Value |
|---|---|
| Checks executed | 16 / 16 |
| Passed | 16 |
| Failed | 0 |
| Blocked | 0 |
| Defects raised | 0 (see sign-off sheet) |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester / date | DSH · 2026-11-07 |

**Notes for the developer**

```text

```
