# Phase 1 QA — Core skeleton (manual browser test)

| | |
|---|---|
| **Phase** | 1 — router, layouts, roles, settings, error pages, test harness |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §7 Phase 1 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `superadmin / Super@123` · `QA_Member_01 / QaPass!2345` (you create it) |
| **Checks** | 17 (QA1-01 … QA1-17) |
| **Time** | 45–60 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-1/` |

## What this phase is meant to prove

Phase 1 built the frame every later phase plugs into: one front door that routes clean URLs, a
member layout and a back-office layout, role guards, a settings store, maintenance mode and the
branded error pages. This manual pass verifies that the frame **looks and behaves correctly in a
real browser** — the visual sign-off that automation cannot give.

**Already proven automatically:** P1-T1 guest redirect · P1-T2 member 403 · P1-T3 admin dashboard ·
P1-T4 404 · P1-T5 deep-URL assets · P1-T6 CSRF logout · P1-T7 harness · P1-T8 menu visibility ·
P1-T9 baseline auth · P1-T10 `/` redirect · P1-T11 maintenance · P1-T12 route parity.

The checks below go further: layout quality, real clicks, screenshots, and a mobile pass.

---

## Before you begin (5 minutes)

1. **Start the stack:** Laragon → **Start All** (Apache + MySQL green).
2. **Confirm the app answers:** open `http://localhost/ecowealth/system/` in a normal window. You
   should land on the login page. If not, fix that first (see the README’s troubleshooting).
3. **Create your evidence folder:** `docs\qa\evidence\phase-1\` (create it if missing).
4. **Have the database tool ready:** Laragon → MySQL → phpMyAdmin, database `ecowealth_sneat`.
5. **Set the browser to 1440×900** (or maximise a 1080p screen) and zoom to **100%**.

> Phase 1 creates the `QA_Member_01` account. Keep it — Phases 2 and 3 reuse it.

---

## A. Opening the app and signing in

### QA1-01 — The app opens and sends guests to the login page

| | |
|---|---|
| **Why it matters** | The root address is the app’s front door; a guest must land on a usable login page. |
| **Where** | `http://localhost/ecowealth/system/` |
| **Evidence** | `QA1-01-login-redirect.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open a **new Incognito window** (`Ctrl+Shift+N`).
2. Type `http://localhost/ecowealth/system/` and press Enter.

**Expected**

- The address bar now ends with `/login`.
- The page shows **“Welcome to Eco-wealth! 👋”** and “Please sign-in to your account and start the
  adventure”.
- A **Demo account** box is visible with `admin` / `Admin@123`.
- Email/Username, Password, Remember Me and a **Login** button are present.
- The footer says “New on our platform? **Create an account**”.

### QA1-02 — The login page looks complete (visual check)

| | |
|---|---|
| **Why it matters** | A broken theme (missing CSS, fonts or logo) would undermine every later screen. |
| **Where** | Login page (Incognito) |
| **Evidence** | `QA1-02-login-page.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the login page, press `F12` → **Network**, tick **Preserve log**, reload the page (`Ctrl+R`).
2. Look at the **Status** column and scroll the page.
3. Open the **Console** tab.

**Expected**

- The EcoWealth logo renders at the top of the card; text uses the custom font (not plain Times).
- The login card is centred, with a soft page background — no unstyled/stacked layout.
- The browser tab title is **“Login | EcoWealth”**.
- The Network list has **no red 404 entries** for files under `/assets/`.
- The Console has **no red error lines** (yellow warnings are acceptable).

### QA1-03 — Administrator signs in

| | |
|---|---|
| **Why it matters** | This is the path every back-office user takes. |
| **Where** | Login page |
| **Evidence** | `QA1-03-dashboard-after-login.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Type `admin` in Email/Username, `Admin@123` in Password.
2. Click **Login**.

**Expected**

- The address bar becomes `.../dashboard`.
- A **green** message appears at the top: **“Welcome back, admin! You are signed in.”**
- The page heading is **“Growth overview”**.
- The top-right avatar shows the letter **A**.

---

## B. Layouts and navigation

### QA1-04 — The member dashboard renders completely

| | |
|---|---|
| **Why it matters** | The dashboard is the member’s home; every section must render, including interactive ones. |
| **Where** | `/dashboard` as admin |
| **Evidence** | `QA1-04-dashboard-sections.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Scroll slowly from top to bottom.
2. Find these section headings and confirm the content beneath each one is laid out in cards:
   “Your membership”, “Community growth”, “Leadership path”, “Five dimensions of progress”,
   “The journey”, “Community members”, “Recent participation”, “A few things to know”.
3. In the FAQ area (“A few things to know”), click one question to expand it.

**Expected**

- Every heading above exists and has content (charts, cards, tables or lists) under it.
- No grey placeholders where an image should be; nothing overlaps or spills outside its card.
- The FAQ question expands and collapses when clicked again.

### QA1-05 — The member menu shows only member items

| | |
|---|---|
| **Why it matters** | A menu must never advertise pages the current role cannot use. |
| **Where** | Left sidebar at `/dashboard` |
| **Evidence** | `QA1-05-member-menu.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Read the sidebar from top to bottom.
2. Hover over an item marked **Soon**.

**Expected**

- Sections and items: **Shopping** → Dashboard, Shop, Cart*, My orders* · **Membership** → Packages,
  Membership*, Genealogy*, Earnings* · **Money** → E-wallet*, Payouts* · **Account** → Profile*,
  Change password, Sign out. (* = greyed item with a **Soon** pill.)
- **Dashboard, Shop, Packages, Change password** are clickable links.
- “Soon” items cannot be clicked.
- The menu contains **no** “Products”, “Registration codes”, “E-wallet monitor”, “Commissions” or
  “S-Login console”.

### QA1-06 — Guests cannot open the shop

| | |
|---|---|
| **Why it matters** | The shop is members-only (decision D2); a guest must be sent to login with a clear message. |
| **Where** | `/shop` in an Incognito window |
| **Evidence** | `QA1-06-guest-shop-redirect.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open a **new Incognito window**.
2. Go to `http://localhost/ecowealth/system/shop`.

**Expected**

- You are redirected to `.../login`.
- A **yellow** warning is shown: **“Please sign in to continue.”**
- No product cards or prices are visible.

---

## C. Roles and guards

### QA1-07 — A new member account can be registered

| | |
|---|---|
| **Why it matters** | Registration is the entry point for every future member and is reused by Phases 2–3. |
| **Where** | `/register` |
| **Evidence** | `QA1-07-registration.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. In a new Incognito window, open `http://localhost/ecowealth/system/register`.
2. Fill in:
   - **Username:** `QA_Member_01`
   - **Email:** `qa.member.01@example.com`
   - **Password:** `QaPass!2345`
   - **Confirm Password:** `QaPass!2345`
   - Tick **“I agree to privacy policy & terms”**.
3. Click **Sign up**.
4. On the login page, sign in as `QA_Member_01` / `QaPass!2345`.

**Expected**

- After sign-up you land on `/login` with a **green** message: **“Account created successfully! You
  can now sign in.”**
- The new account can sign in and reaches “Growth overview”.
- If you skip the terms checkbox, sign-up is refused with a clear message (optional extra check).

### QA1-08 — A member cannot open the back office

| | |
|---|---|
| **Why it matters** | This is the main security boundary between members and administrators. |
| **Where** | `/admin` while signed in as `QA_Member_01` |
| **Evidence** | `QA1-08-member-403.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. While signed in as `QA_Member_01`, open `http://localhost/ecowealth/system/admin`.

**Expected**

- A **403** page: large “403”, heading **“Access denied”**, text “You do not have permission to open
  this page.”
- Buttons **“Back to dashboard”** and **“Home”**.
- The URL stays `/admin` (no redirect loop, no login page).
- The page keeps the normal header/footer styling.

### QA1-09 — Unknown addresses show the branded 404 page

| | |
|---|---|
| **Why it matters** | A missed URL must fail cleanly, not with a blank page or a stack trace. |
| **Where** | `/no-such-page` |
| **Evidence** | `QA1-09-404.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin` if needed.
2. Open `http://localhost/ecowealth/system/no-such-page`.
3. Open DevTools → Network and check the request’s **Status**.

**Expected**

- The page shows “404”, **“Page not found”** and “The page you were looking for could not be found.”
- The status code is **404**.
- The EcoWealth header and footer are present and styled.

### QA1-10 — Deep addresses still load styles and images from the right place

| | |
|---|---|
| **Why it matters** | The most common routing bug after a rewrite is broken asset URLs on nested pages. |
| **Where** | `/admin/products/987654/edit` (a product that does not exist) |
| **Evidence** | `QA1-10-deep-url-404-styled.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, open `http://localhost/ecowealth/system/admin/products/987654/edit`.
2. In DevTools → Network, reload and look at the URLs under `/assets/`.

**Expected**

- The page is the **404 page**, fully styled — styled sidebar, fonts and logo.
- Asset URLs look like `http://localhost/ecowealth/system/assets/...` and return **200**.
- No asset URL contains `/admin/products/` before `/assets/`.

---

## D. Account settings, session and maintenance

### QA1-11 — Changing your password catches mistakes

| | |
|---|---|
| **Why it matters** | Password rules are the member’s own security control; errors must be understandable. |
| **Where** | `/profile/password` as `QA_Member_01` |
| **Evidence** | `QA1-11-password-errors.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `QA_Member_01`.
2. Open `http://localhost/ecowealth/system/profile/password`.
3. Enter Current password `definitely-wrong`, New password `NewPass!2345`, Confirm `NewPass!2345`.
4. Click **Update password**.
5. Now enter Current password `QaPass!2345`, New password `NewPass!2345`, Confirm `Different!234`.
6. Click **Update password** again.

**Expected**

- Step 4: a red message under **Current password** says **“Your current password is not correct.”**
  Nothing changes and no green success message appears.
- Step 6: the error is **“The new password and confirmation do not match.”**

### QA1-12 — Changing the password works, then restore it

| | |
|---|---|
| **Why it matters** | The change must persist through a real sign-out/sign-in cycle. |
| **Where** | `/profile/password` and the login page |
| **Evidence** | `QA1-12-password-changed.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `QA_Member_01`, on **Change password**, enter Current `QaPass!2345`, New `QaTemp!2345`,
   Confirm `QaTemp!2345` → **Update password**.
2. Sign out, then sign in with `QA_Member_01` / `QaTemp!2345`.
3. Go back to **Change password** and set it back: Current `QaTemp!2345`, New `QaPass!2345`,
   Confirm `QaPass!2345`.
4. Sign out and sign in once more with `QaPass!2345` to confirm the restore.

**Expected**

- Each save shows the green message **“Your password has been updated. Use it the next time you sign
  in.”**
- Only the new password works after each change; the old one is rejected.
- At the end the password is back to **`QaPass!2345`**.

> **Do not run this check on the `admin` account.**

### QA1-13 — Signing out really ends the session

| | |
|---|---|
| **Why it matters** | A logout that leaves the back office reachable is a serious defect. |
| **Where** | Any signed-in page |
| **Evidence** | `QA1-13-logout.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin`.
2. Click **Sign out** in the sidebar (or in the avatar menu).
3. Press the browser **Back** button, then open `http://localhost/ecowealth/system/admin` directly.

**Expected**

- After signing out you land on `/login` with the green message **“You have been signed out
  successfully.”**
- After Back / direct URL you are sent to `/login` again — the admin page does **not** open.

### QA1-14 — Maintenance mode blocks visitors but not administrators

| | |
|---|---|
| **Why it matters** | Maintenance mode must protect the site from visitors while keeping administrators able to work — and it must be reversible. |
| **Where** | `/shop` (guest) and `/admin` (admin) |
| **Evidence** | `QA1-14-maintenance.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. phpMyAdmin → database `ecowealth_sneat` → **SQL** tab → run:
   ```sql
   UPDATE settings SET value = '1' WHERE key_name = 'maintenance_mode';
   ```
2. Open a **new Incognito window** and go to `http://localhost/ecowealth/system/shop`.
3. In the same Incognito window, open `http://localhost/ecowealth/system/login`.
4. In your normal window (already signed in as `admin`), open `http://localhost/ecowealth/system/admin`.
5. Restore the setting:
   ```sql
   UPDATE settings SET value = '0' WHERE key_name = 'maintenance_mode';
   ```
6. Reload `/shop` as a guest.

**Expected**

- Step 2: the **503** page with “Down for maintenance”, “EcoWealth is being updated and will be back
  shortly.” and “Please try again in 1 hour.”
- In DevTools → Network the response status is **503** and its headers include `Retry-After: 3600`.
- Step 3: the **login page still loads** — administration remains possible.
- Step 4: the admin dashboard opens normally (200) — administrators bypass maintenance.
- Step 6: back to the normal “Please sign in to continue.” redirect.

> If you must stop here, always run the SQL in step 5 first.

### QA1-15 — Only a superadmin sees the S-Login entry

| | |
|---|---|
| **Why it matters** | Menu visibility must follow the role, not just be hidden by hand. |
| **Where** | `/admin` as `admin` and as `superadmin` |
| **Evidence** | `QA1-15-admin-vs-superadmin-menu.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, open `/admin` and read the **System** section of the sidebar.
2. Note what you see there.
3. Sign out and sign in as `superadmin` / `Super@123`.
4. Open `/admin` and read the **System** section again.

**Expected**

- As `admin`: Settings*, Change password, Sign out — **no “S-Login console”**.
- As `superadmin`: the same plus **“S-Login console”** with a **Soon** pill (Phase 14 feature).
- Both accounts see the same member-style pages otherwise.

---

## E. Front door and mobile

### QA1-16 — The public front door still serves the marketing page

| | |
|---|---|
| **Why it matters** | Visitors arrive at the landing page before the app; it must not be affected by the app work. |
| **Where** | `http://localhost/ecowealth/` |
| **Evidence** | `QA1-16-front-door.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open a new Incognito window.
2. Go to `http://localhost/ecowealth/`.

**Expected**

- The EcoWealth marketing page loads (hero, sections, product imagery).
- It is **not** the login page.
- There is no link into the app yet — this is expected (decision D23); type the app URL yourself.

### QA1-17 — The app is usable on a phone-sized screen

| | |
|---|---|
| **Why it matters** | Many members will use phones; the layout must collapse, not overflow. |
| **Where** | `/login`, `/dashboard`, `/shop`, `/packages` at 390 × 844 |
| **Evidence** | `QA1-17-mobile-login.png`, `QA1-17-mobile-dashboard.png`, `QA1-17-mobile-shop.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin`.
2. Press `F12` → `Ctrl+Shift+M` → choose **iPhone 12 Pro** (390 × 844).
3. Visit `/dashboard`, `/shop`, `/packages`; then open `/login` in an Incognito window (signed-out view).
4. On the dashboard, tap the **hamburger** icon (top-left) to open the menu.

**Expected**

- The sidebar is hidden behind a hamburger icon on every page and opens/closes when tapped.
- Content is readable and fits the width — you do **not** have to scroll sideways to read cards.
- Cards stack in a single column; images keep their aspect ratio.
- Buttons are big enough to tap; no text is cut off.

---

## Phase 1 cleanup

1. Delete nothing — `QA_Member_01` is kept for Phases 2 and 3.
2. Sign out (`admin` or superadmin) and close extra Incognito windows.
3. Confirm maintenance is off:

   ```sql
   SELECT value FROM settings WHERE key_name = 'maintenance_mode';
   -- expected: 0
   ```

4. Fill the **Phase 1** block of `docs/qa/sign-off-sheet.md`.

---

## Phase 1 result summary

| Item | Value |
|---|---|
| Checks executed | ____ / 17 |
| Passed | ____ |
| Failed | ____ |
| Blocked | ____ |
| Automated suite (`php tests\run.php`) | ☐ 141/141 pass |
| Automated smoke (`tests\smoke.ps1`) | ☐ 41/41 pass |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester / date | __________________________ |

**Notes for the developer** (anything that felt wrong even if it passed):

```text

```
