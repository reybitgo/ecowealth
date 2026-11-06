# EcoWealth — Manual QA pack (Phases 1–7)

This pack is for the **manual browser sign-off** that is still outstanding for Phases 1, 2, 3, 4, 5, 6
and 7. The automated suites are already green; these documents let a tester who has never seen the
project walk through every screen, compare it with the expected result, capture evidence and
sign the phase off.

> **Your job as tester: verify, record, report.** Do not change code, do not edit the database
> except where a step explicitly tells you to, and never test on anything other than the local
> development copy described below.

---

## Contents

| Document | Phase | Checks | Time needed |
|---|---|---|---|
| [phase-1-core-skeleton.md](phase-1-core-skeleton.md) | 1 — router, layouts, roles, settings, error pages | 17 | 45–60 min |
| [phase-2-products-and-catalogue.md](phase-2-products-and-catalogue.md) | 2 — product CRUD + storefront | 20 | 60–75 min |
| [phase-3-packages.md](phase-3-packages.md) | 3 — packages, ladder, contents | 16 | 50–65 min |
| [phase-4-cart.md](phase-4-cart.md) | 4 — cart page, drawer, stock rules, IDOR | 17 | 55–70 min |
| [phase-5-wallet.md](phase-5-wallet.md) | 5 — dual balance, ledger, admin top-ups, monitor | 16 | 50–65 min |
| [phase-6-orders.md](phase-6-orders.md) | 6 — checkout, address book, orders, admin order desk | 17 | 55–70 min |
| [phase-7-payments.md](phase-7-payments.md) | 7 — proof upload, verification, rejection, expiry, refunds, settings | 18 | 60–75 min |
| [sign-off-sheet.md](sign-off-sheet.md) | all seven | — | 10 min to complete |

Keep `evidence/` for screenshots (create `evidence/phase-1` … `phase-7` inside it). Phases 4, 5, 6 and 7 also
ship reference screenshots produced headlessly in a real browser — `evidence/phase-4/final-*.png`,
`evidence/phase-5/final-*.png`, `evidence/phase-6/final-*.png` and `evidence/phase-7/final-*.png`.

---

## 1. What is already proven automatically

These commands exercise the same behaviour as the manual checks, without a browser. Run them
first: if they fail, stop and report before starting manual testing.

Open **PowerShell**, then:

```powershell
cd C:\laragon\www\ecowealth\system
php tests\run.php
```

Expected last lines:

```
Cases: 295   Passed: 295   Failed: 0
All good.
```

Then, with the app running (Laragon → Start All), run the HTTP smoke test:

```powershell
powershell -NoProfile -File tests\smoke.ps1
```

Expected last lines:

```
Passed: 102   Failed: 0
```

> If `php` is not recognised, use Laragon's PHP explicitly:
> `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe tests\run.php`
>
> The PHP suite rebuilds a **separate** test database (`ecowealth_sneat_test`); it never touches
> the application database (`ecowealth_sneat`). The smoke script changes and restores a handful of
> rows by itself.

**What the automated tests cover** (so you know what the manual pass is adding):

- Phase 1: route matching, guards, CSRF, settings, layouts/menus, error pages, maintenance mode, route-inventory parity.
- Phase 2: product validation, slug uniqueness, delete guards, stock maths, upload validation, catalogue rendering, escaping.
- Phase 3: package validation, the 2–10 ladder, transactional save/rollback, contents merging, public cards.
- Phase 4: cart service rules (add/repeat/stock refusal/update/remove), ownership scoping (IDOR), live pricing,
  member isolation, a two-connection concurrency lock test, the JSON contract, the no-JS fallback, drawer rendering,
  and a headless-browser pass over the add/stepper/remove interactions (0 JavaScript exceptions).
- Phase 5: dual-bucket credits and debits, non-withdrawable-first spending, refusals (total and bucket), idempotent
  replay, a two-connection concurrency test, audited admin top-ups, invalid `ref_type`, filter/pagination/total
  maths, ledger immutability (schema + source scan), reconciliation (clean and deliberately tampered), plus HTTP
  checks for the member/admin guards, top-up path, tabs and the reconcile command.
- Phase 6: checkout validation, catalogue re-pricing, stock reservation, e-wallet payment inside the order
  transaction, idempotent replay, a two-connection last-unit race, address-book rules and ownership, member payout
  accounts (format validation + normalisation), member/admin order scoping and filters, cancellation with stock
  release, the event trail and the order-number format, plus HTTP checks for the whole placement, replay, guard,
  cancellation, address-book and payout-account paths.
- Phase 7: proof upload (valid PNG/PDF, MIME and size refusals, claim validation, no orphan files), the attempt cap,
  verification (exact amount, unique reference, committed reservation, event), rejection (stock release, correction
  window, reason), a corrected re-upload re-reserving the stock, the expiry sweep (payment and correction deadlines,
  idempotent, review exempt), the closed window, ownership and self-review refusals, the event timeline, admin
  cancellation with a bucket-accurate wallet refund, no refund for a proof-paid order, and the settings page
  (save + validation + audit). The expiry cron has its own coverage (`CronTest`): the web entry's access
  decision, the one-run lock, the monthly log, the kill switch and the `--from-settings` recomputation; the
  smoke script runs it from both the CLI and the URL. The HTTP smoke script also repeats the upload, guards,
  proof streaming, verify, reject, re-upload, cancel, settings and kill-switch paths.
The manual pass adds what automation cannot: **does the page actually look right in a real browser,
and does the flow feel right when clicked by a human** — the visual sign-off.

---

## 2. Environment you will use

| Item | Value |
|---|---|
| App (the thing you are testing) | `http://localhost/ecowealth/system/` |
| Marketing front door | `http://localhost/ecowealth/` |
| Web server + database | Laragon (Apache + MySQL) — use **Start All** |
| Database | `ecowealth_sneat` |
| Database admin tool | Laragon → **MySQL → phpMyAdmin** (`http://localhost/phpmyadmin`) |
| Browser | Current Chrome or Edge, at 100% zoom |

### Accounts

| Account | Password | Use it for |
|---|---|---|
| `admin` | `Admin@123` | Every back-office check |
| `superadmin` | `Super@123` | One Phase 1 check (hidden menu item) |
| `QA_Member_01` | `QaPass!2345` | You create this in Phase 1; reuse it in Phases 2–3 |

> **Do not change the `admin` or `superadmin` passwords.** Password-change checks use the QA member
> account and change it back at the end.

### Seeded data you must protect

The demo catalogue is seeded and referenced by tests and by packages:

| Data | Expected state |
|---|---|
| 7 products | Coffee, Immune Booster, Spirulina, Serpentina, Kojic Soap, Stone Buster, Colonics Session |
| 2 packages | Package A (₱4,498), Package B (₱6,998) |

Never rename, deactivate or delete these permanently. Create your own rows with the `QA` prefix and
remove those instead. If you change a seeded row during a check, the step tells you how to put it back.

---

## 3. How to read a check

Every check has the same shape:

```
### QA1-05 — Short title
| Why it matters | plain-language reason |
| Where | the URL or screen |
| Evidence | screenshot file name |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps
1. exactly what to click / type / open
2. ...

Expected
- what you must see (and must not see)
```

- **Pass** — everything in *Expected* happened.
- **Fail** — something differed. Capture a screenshot and a defect note (template in §8).
- **Blocked** — you could not run the check (for example the app would not start). Say why.

Mark the `Result` line by replacing the box, e.g. `☑ Pass`. Add a note under the check if anything
was unclear.

---

## 4. Rules that protect the project

1. **Work on the local copy only.** URL must start with `http://localhost/`.
2. **Prefix everything you create with `QA`** — `QA Product…`, `QA Package…`, `QA_Member_01`.
3. **Never change the `admin` or `superadmin` password.** Only change `QA_Member_01` and restore it.
4. **Never delete seeded products, packages or accounts.** Delete only `QA…` rows.
5. **Always switch maintenance mode back off** after QA1-14 (SQL is repeated in that check).
6. **Guest checks need a clean session** — use Incognito/Private windows (`Ctrl+Shift+N`).
7. **Do not leave test data behind.** Each phase ends with a cleanup section.
8. If you see a **500 page** (“Something went wrong”), stop that check, capture it, mark **Fail** and
   continue with the other checks — do not try to fix it.

---

## 5. Evidence: screenshots and browser tools

### Screenshots

1. Press **Win + Shift + S**, drag over the browser window, then paste into Paint and save.
2. Save as `docs\qa\evidence\phase-<n>\QA<x>-<yy>-<short-name>.png`, e.g.
   `docs\qa\evidence\phase-1\QA1-05-member-menu.png`.
3. Name the file exactly as the check’s *Evidence* line says, so the lead can match it.

### Browser developer tools (used by a few checks)

| What | How |
|---|---|
| Open DevTools | `F12` (or `Ctrl+Shift+I`) |
| See HTTP status codes | DevTools → **Network**, tick **Preserve log**, reload the page, read the **Status** column |
| See JavaScript errors | DevTools → **Console** — red lines are errors |
| Mobile preview | DevTools → `Ctrl+Shift+M`, choose **iPhone 12 Pro** (390 × 844) |
| Hard refresh | `Ctrl+F5` |

You do **not** need to understand the code. You only need to read status codes (`200` = OK,
`302` = redirect, `403` = not allowed, `404` = not found, `503` = maintenance) and see red errors.

### phpMyAdmin (only where a check asks)

1. Laragon → **MySQL → phpMyAdmin**.
2. Left side: click database **`ecowealth_sneat`**.
3. Click the **SQL** tab, paste the query from the check, press **Go**.
4. Never run `DROP`, `DELETE` or `UPDATE` statements that are not written in this pack.

---

## 6. Known and accepted behaviour (not bugs)

These are deliberate for the current build — do not report them as defects:

- Menu entries for later phases are hidden until their phase ships — every visible link is live.
  The build roadmap is shown on the admin dashboard instead of as “Soon” placeholders.
- `/dashboard` is role-aware: administrators see the back-office dashboard and sidebar, members see
  the member “Growth overview”.
- The shop’s **Add to cart** buttons work and the cart drawer is on every signed-in page (Phase 4).
  **Checkout is live** (Phase 6): the cart’s checkout button opens `/checkout`.
- The **e-wallet** is live (Phase 5). A top-up raises only the **non-withdrawable** bucket, so a wallet with no
  commissions yet shows **Withdrawable ₱0.00** — that is the reference rule, not a bug. The statement is
  append-only: there is no edit or delete button anywhere, and corrections are new rows.
- **Checkout and orders are live** (Phase 6). E-wallet orders become **Paid** immediately; GCash/Maya/bank
  orders stay **Pending** until a proof is uploaded and verified (Phase 7). Only unpaid orders can be
  cancelled online; an administrator cancels a paid order. Shipping is free until the fulfilment phase adds
  courier pricing.
- **Manual payments are live** (Phase 7). A pending order shows the proof upload (3 attempts, JPG/PNG/WebP/
  PDF ≤ 5 MB); an admin verifies or rejects it; a rejection releases the stock and opens the correction
  window, and a corrected proof takes the hold again. An administrator cannot review an order they placed
  themselves. `php bin\shop_expire.php` cancels overdue unpaid orders; a proof already under review never
  expires.
- **The admin settings page** (System → Settings) edits the receiving accounts and the payment/correction
  windows and the proof-attempt cap. Changes are audited.
- **The cart is converted into an order**, so the cart badge resets after a successful checkout. A failed
  checkout keeps the cart exactly as it was.
- **Order numbers** look like `EW26110500042` (`EW` + yymmdd + the order id).
- Administrators may open `/cart` too (the member guard admits admins, matching the reference). Their
  back-office sidebar lists **Cart** under *Overview* with the same live badge, and the cart page keeps
  the back-office menu with Cart highlighted.
- Cart totals always come from the current catalogue price; changing a price in the back office changes
  the cart on its next load. This is intended, not a stale-value bug.
- Removing the last line empties the cart; a cart row itself is kept server-side until it becomes an
  order (Phase 6), which is why the database has a `carts` row with no `cart_items` after the cleanup query.
- The packages page button says **“Activation opens in Phase 9”** and is disabled.
- **Packages cannot be deleted** — only enabled/disabled. This is by design.
- Deleting a **seeded** product is refused with “This product appears in a package.” That is the
  guard working; delete your own `QA` product to test deletion.
- The marketing front door (`http://localhost/ecowealth/`) **does not link into the app yet**
  (decision D23). Type the app URL directly.
- Product copy and prices are placeholders until the official price list is provided (decision D10).
- The member dashboard is a presentation-style “Growth overview”, not a live data console yet.

If something looks different from this list, it may be a real defect — report it.

---

## 7. What to do when a check fails

1. Take a screenshot of the full browser window (with the URL visible).
2. Copy the browser console’s red lines if there are any.
3. Note the exact URL, what you clicked, and the time.
4. Add a row to the defect log in [sign-off-sheet.md](sign-off-sheet.md):

```
| ID | Phase/check | Severity | What happened | Expected | Evidence | Status |
| QA-P2-D1 | QA2-09 | major | ... | ... | evidence/phase-2/QA2-09-... | Open |
```

Severity guide:

| Severity | Meaning |
|---|---|
| **Blocker** | You cannot continue (app down, login broken, 500 on a core page) |
| **Major** | A function gives the wrong result (a save does not persist, a guard lets the wrong role in) |
| **Minor** | A visible problem that does not stop the flow (wrong text, misaligned element) |
| **Cosmetic** | Purely visual polish |

Do **not** fix anything yourself. The tester reports; the developer fixes; the tester retests the
failed check afterwards and updates the row to **Closed**.

---

## 8. Sign-off flow

1. Run the automated commands in §1 and copy their final summary lines into the sign-off sheet.
2. Work through the seven phase documents in order (Phase 1 → 2 → 3 → 4 → 5 → 6 → 7). Each phase assumes the
   previous one is done.
3. Fill in one block of [sign-off-sheet.md](sign-off-sheet.md) per phase: pass/fail counts, defects,
   decision.
4. Hand the sheet plus the `evidence/` folder to the project lead.
5. The lead compares evidence with the phase log and, when satisfied, records the visual sign-off in
   `docs/phase-log.md`. That is what closes the last open gate item for each phase.

A phase may be signed **Approved with notes** when every failure is *cosmetic* and accepted by the
lead; any blocker or major defect keeps the phase open.

---

## 9. Troubleshooting

| Symptom | What it means | What to do |
|---|---|---|
| “This site can’t be reached” | Apache is not running | Laragon → **Start All**, retry |
| “Database connection failed” | MySQL is not running or credentials are wrong | Start MySQL; if it persists, report it (do not edit config) |
| Login says “Too many failed attempts” | The 15-minute login lockout after 5 bad passwords | Wait 15 minutes, or ask the lead to clear the session; never keep retrying |
| Page shows **503 — Down for maintenance** | Maintenance mode was left on | Run `UPDATE settings SET value='0' WHERE key_name='maintenance_mode';` in phpMyAdmin, then reload |
| Pages look unstyled / logo missing | Assets did not load | Hard refresh (`Ctrl+F5`); in DevTools → Network look for red `404`s and report them |
| Raw PHP code appears in the browser | Apache is not executing PHP | Stop and report; do not continue |
| Your QA row is missing after saving | The save failed | Re-read the form: validation errors appear in a red box; screenshot it and report |
| Automated test command not found | PHP is not on your PATH | Use the full Laragon PHP path shown in §1 |

---

## 10. Glossary

| Term | Plain meaning |
|---|---|
| **App / back office** | The management side at `/admin` — products, packages, members |
| **Member portal** | The signed-in member side — dashboard, shop, packages, profile |
| **Route** | A URL the app knows how to handle, e.g. `/admin/products` |
| **Guard** | The rule that decides who may open a page (member, admin) |
| **403 / 404 / 500 / 503** | Not allowed / not found / server error / temporarily unavailable |
| **Flash message** | The green/red notification shown once after an action |
| **CSRF token** | A hidden security field in every form; prevents forged submissions |
| **Seed data** | Demo rows loaded on install (products, packages, accounts) |
| **Maintenance mode** | A settings switch that shows visitors a “back shortly” page |
| **Migration** | A numbered SQL file that upgrades the database for a phase |

---

## 11. File map

```
docs/qa/
├── README.md                          ← this file
├── phase-1-core-skeleton.md           ← manual checks for Phase 1
├── phase-2-products-and-catalogue.md  ← manual checks for Phase 2
├── phase-3-packages.md                ← manual checks for Phase 3
├── phase-4-cart.md                    ← manual checks for Phase 4
├── phase-5-wallet.md                  ← manual checks for Phase 5
├── phase-6-orders.md                  ← manual checks for Phase 6
├── phase-7-payments.md                ← manual checks for Phase 7
├── sign-off-sheet.md                  ← fill this in and hand it back
└── evidence/                          ← your screenshots (phase-1/ … phase-7/)
```
