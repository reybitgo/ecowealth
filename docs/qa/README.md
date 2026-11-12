# EcoWealth — Manual QA pack (Phases 1–16)

This pack is for the **manual browser sign-off** that is still outstanding for Phases 1, 2, 3, 4, 5, 6,
7, 8, 9, 10, 11, 12, 13, 14, 15 and 16. The automated suites are already green; these documents let a tester who has never seen the
project walk through every screen, compare it with the expected result, capture evidence and
sign the phase off.

> **Phase 16 is the install/reset workstream** (`dmp/plan-ecowealth-install-reset.md`, IR-1 … IR-8),
> not an application phase. Its checks run on scratch schemas and its evidence lives in
> `evidence/install-reset/`, not `evidence/phase-16/`.

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
| [phase-8-fulfilment.md](phase-8-fulfilment.md) | 8 — packing, shipping + tracking, delivery, confirmation, invoices, auto-complete | 19 | 60–75 min |
| [phase-9-membership.md](phase-9-membership.md) | 9 — registration modes, registration codes, activation, member directory, genealogy | 16 | 60–75 min |
| [phase-10-commissions.md](phase-10-commissions.md) | 10 — direct referral award, earnings statement, commission ledger | 10 | 35–45 min |
| [phase-11-commissions.md](phase-11-commissions.md) | 11 — indirect referral ladder, level breakdown, CSV exports | 10 | 35–45 min |
| [phase-12-transfers.md](phase-12-transfers.md) | 12 — member transfers, fees, limits, reset cron, admin monitor | 8 | 25–35 min |
| [phase-13-payouts.md](phase-13-payouts.md) | 13 — payout requests, the hold, fees, USDT conversion, admin queue | 8 | 25–35 min |
| [phase-14-super-login.md](phase-14-super-login.md) | 14 — super-login console, URL-token impersonation, exit/TTL, audit trail | 8 | 25–35 min |
| [phase-15-launch.md](phase-15-launch.md) | 15 — dashboards, reports/exports, the eight settings cards, audit desk, hardening, UAT and launch | 12 | 40–55 min |
| [phase-16-install-reset.md](phase-16-install-reset.md) | 16 — install bundle, CLI/web installers, CLI/web reset, demo + parity, production gates, go-live | 16 | 90–120 min |
| [phase-17-unilevel-repeat-purchase.md](phase-17-unilevel-repeat-purchase.md) | 17 — unilevel repeat-purchase bonus, sections A–G. **In progress (17C): sections A–C** | A: 8 · B: 9 · C: 10 (D–G follow) | 35–45 min for A; 45–60 min for C |
| [sign-off-sheet.md](sign-off-sheet.md) | all seventeen | — | 10 min to complete |

Keep `evidence/` for screenshots (create `evidence/phase-1` … `phase-15` inside it). Phases 4–12 also
ship reference screenshots produced headlessly in a real browser — `evidence/phase-4/final-*.png`,
`evidence/phase-5/final-*.png`, `evidence/phase-6/final-*.png`, `evidence/phase-7/final-*.png`,
`evidence/phase-8/final-*.png`, `evidence/phase-9/final-*.png`, `evidence/phase-10/*.png`,
`evidence/phase-11/*.png` and (once the browser passes are captured) `evidence/phase-12/*.png`,
`evidence/phase-13/*.png`, `evidence/phase-14/*.png` and `evidence/phase-15/*.png`. Phase 16 keeps
its screenshots and terminal transcripts in `evidence/install-reset/`, indexed by that folder's
`README.md`.

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
Cases: 816   Passed: 816   Failed: 0
All good.
```

Then, with the app running (Laragon → Start All), run the HTTP smoke test:

```powershell
powershell -NoProfile -File tests\smoke.ps1
```

Expected last lines:

```
Passed: 195   Failed: 0
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
- Phase 8: the strict `paid → packing → ready_to_ship → shipped → delivered → completed` walk and its
  refusals, courier/tracking validation, the stock deducted exactly once at ship (including the
  short-stock refusal), delivery + member confirmation and ownership, the no-cancel-after-ship rule,
  the invoice snapshot written at e-wallet payment and at manual verification, its frozen seller/buyer/
  line/totals content, reprint identity, void-on-refund, order/invoice scoping, the seller + completion
  settings (save + validation), and the auto-complete sweep with its kill switch and idempotency. The
  smoke script walks the whole flow over HTTP (pack, a refused ship, a real ship + stock change, a
  refused cancel after ship, deliver, member confirm, the invoice guards, and the auto-complete job
  from its URL). The courier picker is covered too: a real `<select>` with a typed **Others** path,
  server-side resolution, and a no-JS baseline that keeps the free-text field visible. The settings
  page is now four independent cards, each with its own form and Save settings button; the tests and
  smoke checks prove a save in one card leaves the other cards' values untouched.
- Phase 9: all four registration modes (including the free-registration gate and the guest e-wallet
  refusal), sponsor resolution by username or referral code, self/unknown/suspended sponsor refusals,
  duplicate username/email, code generation/validation/expiry/CSV export, single-use redemption under
  a two-connection race, wallet registration (payer debited once, admin credited, registrar session
  kept), wallet and code activation (hook invoked exactly once), manual proof → approve/reject with
  the reason and the member returning to pending, double activation/approval refusals, the member
  detail + suspend/restore + audit, the team chain (level 1 direct, level 2 their recruits) with cycle
  safety, referral-code uniqueness, and
  the "no binary column anywhere" source/schema scan. The smoke script walks the registration, code,
  pending, manual-activation, approve/reject, suspend/restore, codes and genealogy paths over HTTP;
  wallet-funded registration/activation are covered by the unit suite (they move real money) — see the
  Phase 9 QA document.
- Phase 10: the direct referral award (₱500 on Package A, ₱600 on Package B) paid by a code registration, a manual
  approval and the shared completion step — wallet credit, ledger row and award row together, the
  credit withdrawable; ineligible sponsors (pending, package-less, inactive) skipped with a reason and
  no money; a zero direct bonus awarding nothing; replay and double-approval paying nothing twice
  (service guard plus the `uq_commission_event` unique key); two recruits as two awards; wallet and
  commission reconciliation clean, and a tampered award caught; member totals, filters and pagination;
  admin ledger filters by member/source/type/level/status/date; member isolation; the award → activation
  → wallet trace; and the migration guards (unique key, closed enums, package/activation cascades).
  The smoke script pays a sponsor over HTTP, skips an ineligible one, opens the earnings statement and
  the admin ledger, and runs the finance report — then restores every demo wallet it moved. A headless
  Chrome pass covers the ledger, both drawers, the mobile layouts and 0 JavaScript errors.
- Phase 11: the indirect referral ladder (levels 2–10) priced by the **source package's** matrix, paid
  through a real ten-deep chain — one commission row, wallet credit and ledger row per level; the
  eleventh level earns nothing; an inactive upline is skipped but consumes its level while deeper levels
  keep paying; Package B pays a 20% premium ladder over Package A; the package switch, the global switch and
  `commission_indirect_max_levels` cap the walk; a zero level writes no row; a replay pays nothing twice;
  wallet credits equal the award rows; the member earnings level breakdown and its live region; the admin
  type/level filters; and both CSV exports (`/earnings/export` member-scoped, `/admin/commissions/export`
  admin-only, audited). The smoke script registers a real code under `peter`, proves peter ₱500 / maria
  ₱100 / juan ₱50 with the company root skipped, then checks the level breakdown, both exports and the
  finance report. A headless Chrome pass captures the ledger, the breakdown and the mobile layouts.
- Phase 12: a fee-free transfer (transfer row + two ledger rows, counters moved), a fee transfer (third
  leg credited to the primary admin, counters counting only the amount), the refusals (minimum, balance,
  self, wrong/missing password, unknown/blank/suspended recipient, daily limit, weekly limit) with
  nothing written, the daily and weekly limit boundaries, the reset job (daily always, weekly on
  request, idempotent), the non-withdrawable-first spend and non-withdrawable credit, staff fee/limit
  exemption with superadmin attribution to the primary admin, the fee-row fallback when the admin is
  also the recipient, the two-connection lock/overdraw test, the reconciliation (legs match, a tampered
  amount is caught, orphan legs are reported not failed), the send page + monitor transfers tab
  rendering, and the two live surfaces. The smoke script sets a ₱8 fee, walks the wrong-password
  refusal and a real ₱200 transfer over HTTP, opens the monitor tab, runs the reset cron twice from its
  URL entry and re-runs the finance report.
- Phase 13: a payout request that holds the amount (one `payout` debit, a pending row) and closes the
  form, the refund-free refusals (below the minimum, above the **withdrawable** bucket, a second request
  while one is open, an unknown/disabled channel, a malformed account), the service-fee arithmetic, the
  USDT conversion with the stored rate/gas snapshot (and the closed channels while the rate is unset),
  approve without a wallet movement, complete without a second debit, rejection refunding the hold once
  and recording the reason, the two-connection lock test, “no member processes a payout and nobody
  processes their own”, the out-of-order/double-decision guards, the queue filters/counts/totals, the
  payout reconciliation (a removed refund leg is caught, orphan legs are reported not failed), and the
  two live surfaces. The smoke script sets a 10% fee, walks three refusals and the USDT-closed refusal,
  then a real ₱500 request over HTTP (approve → complete) and a ₱600 request rejected and refunded, and
  re-runs the finance report.
- Phase 14: the S-Login service and audit (a 32-byte nonce, only its SHA-256 stored, the active row, the
  start/stop audit entries), the target rules (member only; pending members allowed; admins and suspended
  accounts refused), the per-target five-attempt/15-minute rate limit with every refusal audited, the
  validation rules (missing/tampered token, TTL, IP and user-agent binding, deleted audit row, target
  suspended after opening), the exit transition (first terminal status wins), the URL helper (every app
  URL keeps `?imp=`, assets/uploads/shared referral links never do), the member-layout banner and its
  CSRF-protected Exit form, the superadmin-only menu entry and the audit screen with filters, status
  counts and no hashed nonce in its HTML. The HTTP smoke script opens a real URL-token session over HTTP,
  follows a token-bearing link, exits, proves the token dead, then exercises TTL expiry, user-agent
  expiry, the guards, an admin target, six rate-limited attempts, a tampered token and the audit page.
- Phase 15: the report read model (paid-orders-only revenue, zero-filled day series, status order, paid-line
  top products, level/leg commission grouping, both wallet buckets' float, payout status/method splits, code
  revenue, and the five CSV shapes) and its window maths (default, inclusive, swapped, capped); the reports page
  and its live fragments; the health widget's seven invariants (clean on the fixture, and a broken order total is
  caught); the audit desk's filters/vocabulary/stats and the absence of any update/delete path; the shared
  security headers, the throttles, uploads/ hardening, production error display and the phase-15 config; the
  launch scenarios (a condensed UAT money path, IDOR/role/CSRF probes, 1,000-order report timing, fresh install,
  effective settings and a complete S-Login audit). The HTTP smoke script adds the headers, an uploads PHP probe,
  the reports/audit guards, a branded 404, all eight settings cards with a refusal and an effective brand change,
  the reports desk and window, the CSV downloads with their audit rows, the audit filters and the transfer
  throttle.
- Phase 16 (install/reset workstream): the SQL statement parser (quotes/comments, line numbers, `DELIMITER` and
  database-switch rejection, failure context, checksum), `DbTarget` connection/probing and identifier safety,
  seed/migration parity and the generated bundle's drift check, importing the bundle into an empty scratch schema
  and refusing a non-empty one, the CLI installer (preflight, install + lock, re-run/non-empty refusals, password
  policy, JSON, production key), the same gates for the web installer (lock, non-empty, key + throttle, CSRF,
  no secrets), the reset engine (preview/dry-run, one transaction, staff defaults, audit kept/cleared, catalogue
  reset, codes, rollback, uploads, live revisions, AUTO_INCREMENT, the full refusal matrix), the reset console
  (visibility/actor/phrase matrix, PRG, refusals), and the demo fixtures plus both reset ≡ fresh-install parity
  comparisons by natural key. The HTTP smoke script adds the installer and console probes (`IR4-T1…T5`,
  `IR6-T1…T5`).
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
| `superadmin` | `Super@123` | The S-Login console (Phase 14) and the superadmin-only menu check |
| `QA_Member_01` | `QaPass!2345` | You create this in Phase 1; reuse it in Phases 2–9 |
| `QA_Fulfil_01` | `QaFulfil!2345` | Phase 8 evidence fixture: one order in every fulfilment state (optional — see the Phase 8 doc) |
| `juan` · `maria` · `peter` · `sarah` · `david` | `Demo@1234` | Phase 9 demo tree (seeded): use them as sponsors, recruits and the active/pending examples |
| `QA_Member_09` · `QA_Member_10` | `QaMember!2345` | Phase 9 evidence fixtures (optional — see the Phase 9 doc) |
| `QA_Assoc_09` | `QaAssoc!2345` | Phase 9 evidence fixture: an active associate with a mixed wallet |

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
  cancelled online; an administrator cancels a paid order. Shipping is free for now (decision D8); the
  fulfilment phase adds no courier pricing.
- **Manual payments are live** (Phase 7). A pending order shows the proof upload (3 attempts, JPG/PNG/WebP/
  PDF ≤ 5 MB); an admin verifies or rejects it; a rejection releases the stock and opens the correction
  window, and a corrected proof takes the hold again. An administrator cannot review an order they placed
  themselves. `php bin\shop_expire.php` cancels overdue unpaid orders; a proof already under review never
  expires.
- **Fulfilment and invoices are live** (Phase 8). Once paid, an order is packed and shipped with a courier
  and a tracking number (the stock is deducted at that moment), marked delivered, then confirmed by the
  member or completed automatically after `shop_autocomplete_days`. A paid order can still be cancelled by
  an admin until it ships; after that a returns/RTS flow is needed (deferred). Every paid order has a
  printable, immutable `INV-yymmdd-#####` invoice; cancelling a paid order marks it **VOID** but keeps it.
  The admin settings page owns the seller block and the completion rules.
- **Registration and membership are live** (Phase 9). `/register` offers standard, code, e-wallet (signed-in
  sponsors) and pending (activate later) modes. The sponsor field is optional — a blank field joins the
  member under the company admin — and every account gets an 8-character referral code (`?ref=CODE`
  pre-fills the sponsor). Pending members activate from **Membership** with a code, the e-wallet or a
  payment proof; a proof waits in **Admin → Activations**. Suspending a member blocks their sign-in. The
  demo tree (`juan` … `david`, `Demo@1234`) and three `DEMO-*` codes are seed data.
- **Referral commissions are live** (Phases 10–11). When a package activation is approved, the member's
  sponsor is paid the package's **direct referral bonus** (₱500 on the seeded packages), and the sponsor
  chain is paid the **indirect ladder** for levels 2–10 (₱100, ₱50, ₱40, ₱30, ₱20, ₱10, ₱5, ₱5, ₱5) using
  the source package's matrix. Every credit lands in the e-wallet as **withdrawable** earnings, exactly
  once; an inactive or package-less upline is **skipped** (level consumed, no money). The member sees
  every award and the level breakdown on **Membership → Earnings**, with a filtered CSV; the company sees
  them all on **Finance → Commissions**, with type/level filters and its own CSV. Awards are immutable:
  there is no edit or delete control, and `php bin\wallet_reconcile.php` checks the awards against the
  ledger too.
- **Commissions are money history.** Do not delete a `commissions` row or its ledger row by hand on a
  live database — a correction is a new `adjustment` movement, never an edit.
- **The admin settings page** (System → Settings) edits the receiving accounts and the payment/correction
  windows, the proof-attempt cap, the invoice seller block and the auto-complete switch/days. Changes are
  audited.
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
- The packages page button says **“Activation opens in Phase 9”** on a Phase 3 screenshot; it is now a
  live **Membership** link in the app. The packages page is the catalogue; activation happens at
  `/membership`.
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
2. Work through the phase documents in order (Phase 1 → 16). Each phase assumes the
   previous one is done. Phase 16 runs on scratch schemas and can be done without touching the
   development database.
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
├── phase-8-fulfilment.md              ← manual checks for Phase 8
├── phase-9-membership.md              ← manual checks for Phase 9
├── phase-10-commissions.md            ← manual checks for Phase 10
├── phase-11-commissions.md            ← manual checks for Phase 11
├── phase-12-transfers.md              ← manual checks for Phase 12
├── phase-13-payouts.md                ← manual checks for Phase 13
├── phase-14-super-login.md            ← manual checks for Phase 14
├── phase-15-launch.md                 ← manual checks for Phase 15
├── phase-16-install-reset.md          ← manual checks for Phase 16 (install/reset tools)
├── sign-off-sheet.md                  ← fill this in and hand it back
├── evidence/                          ← your screenshots (phase-1/ … phase-15/ + install-reset/)
```
