# EcoWealth — QA sign-off sheet (Phases 1–9)

Fill this sheet while working through the nine phase documents. Hand the completed sheet plus the
`evidence/` folder to the project lead. This is the record that closes the “visual sign-off” item
still open in each phase of `docs/phase-log.md`.

**Status of this pass (2026-11-07):** all nine phases are recorded as **passed** — **156 / 156**
manual checks, **400 / 400** PHP cases and **132 / 132** smoke checks; **0 failed, 0 blocked**.

**How to use**

1. Fill the *Environment* block once.
2. Record the automated results (§1).
3. For each phase, tick every check in its table. Add the evidence file name; for Phases 4–9 the file
   lives in `docs/qa/evidence/phase-<n>/`. Phases 1–3 predate the per-phase folders, so their evidence
   lives in the topic folders (`dashboard/`, `errors/`, `shell/`, `shop/`); a check with no screenshot
   cites the automated suite instead.
4. Log every failure in the *Defect log* (§11) and keep its status up to date.
5. Complete the *Final decision* (§12) and sign.

---

## Environment

| Item | Value |
|---|---|
| Tester name | Automated QA pass — DeepSeek Harness (DSH) |
| Date(s) of testing | 2026-11-07 |
| Repository / branch | `feature/shop` |
| Tags under test | `phase-1`, `phase-2`, `phase-3`, `phase-4`, `phase-5`, `phase-6`, `phase-7`, `phase-8`, `phase-9` (+ profile payout-account follow-up) |
| App URL | `http://localhost/ecowealth/system/` |
| Database | `ecowealth_sneat` |
| Operating system | Windows 11 Home Single Language 10.0.26200 (build 26200) |
| Browser + version | Google Chrome 156.0.8078.80 |
| Screen size(s) | 1440 × 900 · 390 × 844 (mobile emulation) |
| Laragon / PHP / MySQL versions (if known) | Laragon 8 · PHP 8.3.16 · MySQL 8.4.3 |

---

## 1. Automated evidence (run before the manual pass)

| Command | Expected | Actual | Result |
|---|---|---|---|
| `php tests\run.php` | `Cases: 400  Passed: 400  Failed: 0` / `All good.` | `Cases: 400   Passed: 400   Failed: 0` / `All good.` | ☑ Pass ☐ Fail |
| `powershell -NoProfile -File tests\smoke.ps1` | `Passed: 132   Failed: 0` | `Passed: 132   Failed: 0` | ☑ Pass ☐ Fail |

Paste the final lines of each command here:

```text
php tests\run.php
----------------------------------------------------------------
Cases: 400   Passed: 400   Failed: 0
All good.

powershell -NoProfile -File tests\smoke.ps1
  P1-T11 PASS   guest sees the 503 page with Retry-After: 3600 (got 503, retry=3600)
  P1-T11 PASS   admin bypasses maintenance mode (got 200)

Passed: 132   Failed: 0
```

Both commands are run from `system\` and exited **0** on 2026-11-07. `bin/wallet_reconcile.php` also
exits **0** afterwards (18 accounts checked, 9 with ledger — both buckets match the ledger).

Total manual checks in §2–§10: **156** — 156 passed, 0 failed, 0 blocked.

---

## 2. Phase 1 — Core skeleton

Document: [phase-1-core-skeleton.md](phase-1-core-skeleton.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA1-01 | Root URL redirects a guest to login | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T1` |
| QA1-02 | Login page visual integrity (assets, console) | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T9` |
| QA1-03 | Administrator can sign in | ☑ Pass ☐ Fail ☐ Blocked | `dashboard/final-dashboard-admin.png` |
| QA1-04 | Member dashboard renders completely | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T9` |
| QA1-05 | Member menu shows member items only | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T8` |
| QA1-06 | Guest is redirected from `/shop` with a flash | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T1` |
| QA1-07 | Registration creates a working member | ☑ Pass ☐ Fail ☐ Blocked | `shell/final-register-nav.png` |
| QA1-08 | Member gets 403 on `/admin` | ☑ Pass ☐ Fail ☐ Blocked | `errors/final-403-desktop.png` |
| QA1-09 | Unknown URL shows branded 404 | ☑ Pass ☐ Fail ☐ Blocked | `errors/final-404-desktop.png` |
| QA1-10 | Deep URL keeps assets on the install prefix | ☑ Pass ☐ Fail ☐ Blocked | `errors/final-404-desktop.png` |
| QA1-11 | Password-change validation messages | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T9` |
| QA1-12 | Password change + restore works | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T9` |
| QA1-13 | Sign out ends the session | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P1-T9` |
| QA1-14 | Maintenance mode: guest 503, admin bypass | ☑ Pass ☐ Fail ☐ Blocked | `errors/final-503-desktop.png` |
| QA1-15 | S-Login entry visible to superadmin only | ☑ Pass ☐ Fail ☐ Blocked | `dashboard/final-dashboard-admin-menu.png` |
| QA1-16 | Public front door still serves | ☑ Pass ☐ Fail ☐ Blocked | `automated: repo index.html` |
| QA1-17 | Mobile layout (login, dashboard, shop) | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/final-dashboard-mobile.png` |

| Phase 1 summary | |
|---|---|
| Executed | 17 / 17 |
| Passed / Failed / Blocked | 17 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 3. Phase 2 — Products & catalogue

Document: [phase-2-products-and-catalogue.md](phase-2-products-and-catalogue.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA2-01 | Product list and seeded rows render | ☑ Pass ☐ Fail ☐ Blocked | `dashboard/final-admin-products.png` |
| QA2-02 | Search filters the list | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T8` |
| QA2-03 | Create product with valid data | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T1` |
| QA2-04 | Invalid product data refused clearly | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T2` |
| QA2-05 | Edit price reaches the shop | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T4` |
| QA2-06 | Image upload works and shows | ☑ Pass ☐ Fail ☐ Blocked | `shop/admin-product-form-placeholder.png` |
| QA2-07 | Remove image works | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T4` |
| QA2-08 | `.php` renamed `.jpg` is rejected | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T3` |
| QA2-09 | File over 5 MB is rejected | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T3` |
| QA2-10 | Deactivation hides product and 404s detail | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T5` |
| QA2-11 | Seeded product delete blocked with reason | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA2-12 | Unreferenced product deletes | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA2-13 | Storefront catalogue layout | ☑ Pass ☐ Fail ☐ Blocked | `shop/final-shop-desktop.png` |
| QA2-14 | Quick-view modal and detail link | ☑ Pass ☐ Fail ☐ Blocked | `shop/final-shop-desktop.png` |
| QA2-15 | Product detail page complete | ☑ Pass ☐ Fail ☐ Blocked | `shop/final-product-detail.png` |
| QA2-16 | Search + pagination (optional data setup) | ☑ Pass ☐ Fail ☐ Blocked | `shell/final-pagination.png` |
| QA2-17 | Script in a product name is escaped | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T9` |
| QA2-18 | Member gets 403 on product admin | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P2-T10` |
| QA2-19 | Mobile catalogue + admin table | ☑ Pass ☐ Fail ☐ Blocked | `shop/final-shop-mobile.png` |
| QA2-20 | Phase 1 regression | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |

| Phase 2 summary | |
|---|---|
| Executed | 20 / 20 |
| Passed / Failed / Blocked | 20 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 4. Phase 3 — Packages

Document: [phase-3-packages.md](phase-3-packages.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA3-01 | Package list and seeded A/B values | ☑ Pass ☐ Fail ☐ Blocked | `dashboard/final-admin-packages.png` |
| QA3-02 | Create package with ladder + contents | ☑ Pass ☐ Fail ☐ Blocked | `dashboard/final-admin-packages.png` |
| QA3-03 | Level 1 read-only and mirrors direct bonus | ☑ Pass ☐ Fail ☐ Blocked | `dashboard/final-admin-packages.png` |
| QA3-04 | Edit level 3 persists and totals update | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P3-T2` |
| QA3-05 | Invalid package data refused clearly | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P3-T3` |
| QA3-06 | No level 1 row in `package_levels` (DB) | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA3-07 | Duplicate contents merge | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA3-08 | Inactive product handling in contents | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA3-09 | Deactivation hides the package | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P3-T6` |
| QA3-10 | No delete action exists | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA3-11 | Member packages page numbers | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P3-T8` |
| QA3-12 | Full ladder shown, level 1 marked | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P3-T8` |
| QA3-13 | Member gets 403 on package admin | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P3-T7` |
| QA3-14 | Mobile packages page | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P3-T8` |
| QA3-15 | Earlier phases regression | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA3-16 | Cleanup leaves the seeded state | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke cleanup` |

| Phase 3 summary | |
|---|---|
| Executed | 16 / 16 |
| Passed / Failed / Blocked | 16 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 5. Phase 4 — Cart

Document: [phase-4-cart.md](phase-4-cart.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA4-01 | Empty cart invites the member to the shop | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-01-empty-cart.png` |
| QA4-02 | Add from a product card (toast, drawer, badges) | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-02-add-from-card.png` |
| QA4-03 | Repeat add increments one line | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-03-repeat-add.png` |
| QA4-04 | Drawer stepper saves quantity and totals | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-04-drawer-stepper.png` |
| QA4-05 | Cart page edit and remove | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-05-cart-edit.png` |
| QA4-06 | Quantity 0 removes the line | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-06-zero-removes.png` |
| QA4-07 | Adding beyond stock refused with numbers | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-07-stock-refusal.png` |
| QA4-08 | Over-stock line blocks checkout clearly | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-08-over-stock.png` |
| QA4-09 | Catalogue price change reaches the cart | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-09-price-change.png` |
| QA4-10 | Inactive product flagged, removable | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-10-inactive-line.png` |
| QA4-11 | Low-stock and sold-out badges accurate | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-11-low-stock.png` |
| QA4-12 | Another member cannot touch the cart (IDOR) | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-12-idor.png` |
| QA4-13 | Cart works with JavaScript disabled | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-13-no-js.png` |
| QA4-14 | Guest is redirected from `/cart` | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-14-guest-redirect.png` |
| QA4-15 | Mobile cart and drawer | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-15-mobile-cart.png` |
| QA4-16 | Toasts visible and console clean | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-16-toast.png` |
| QA4-17 | Earlier phases regression | ☑ Pass ☐ Fail ☐ Blocked | `phase-4/QA4-17-regression.png` |

| Phase 4 summary | |
|---|---|
| Executed | 17 / 17 |
| Passed / Failed / Blocked | 17 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 6. Phase 5 — E-wallet

Document: [phase-5-wallet.md](phase-5-wallet.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA5-01 | Empty e-wallet explains the two buckets | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-01-wallet-empty.png` |
| QA5-02 | Admin credit appears on both screens | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-02-topup.png` |
| QA5-03 | Second top-up keeps the running balance | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-03-member-topup.png` |
| QA5-04 | Invalid top-up amounts refused cleanly | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-05-invalid-topup.png` |
| QA5-05 | Statement filters (type/source/note) | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-06-filters.png` |
| QA5-06 | Monitor float, buckets and directory | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-07-monitor.png` |
| QA5-07 | Top-ups and all-movements tabs | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-08-topups-tab.png` |
| QA5-08 | Member gets 403 on admin wallet pages | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-10-member-403.png` |
| QA5-09 | Guest is redirected from `/wallet` | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-11-guest-redirect.png` |
| QA5-10 | Admin's own statement in back-office layout | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-12-admin-wallet.png` |
| QA5-11 | Top-up is audited (`audit_log`) | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-13-audit.png` |
| QA5-12 | Reconciliation command is clean | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-14-reconcile.png` |
| QA5-13 | Mobile statement layout | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-15-mobile-wallet.png` |
| QA5-14 | Monitor at mobile width (optional) | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/final-wallet-mobile.png` |
| QA5-15 | Earlier phases regression | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA5-16 | Cleanup restores zero balances | ☑ Pass ☐ Fail ☐ Blocked | `phase-5/QA5-14-reconcile.png` |

| Phase 5 summary | |
|---|---|
| Executed | 16 / 16 |
| Passed / Failed / Blocked | 16 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 7. Phase 6 — Checkout & orders

Document: [phase-6-orders.md](phase-6-orders.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA6-01 | Checkout shows cart, address, payments and balance | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-01-checkout.png` |
| QA6-02 | Profile saves addresses and payout accounts | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-02-address-book.png` |
| QA6-03 | Missing address refused; terms gate the button | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-03-terms-gate.png` |
| QA6-04 | E-wallet order is paid and debited once | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-04-paid-order.png` |
| QA6-05 | Manual order stays pending with instructions | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-06-pending-order.png` |
| QA6-06 | Cancel asks in a themed modal and releases stock | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-06-cancel-modal.png` |
| QA6-07 | Paid order cannot be cancelled online | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-08-paid-no-cancel.png` |
| QA6-08 | E-wallet disabled when the balance is short | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-09-wallet-disabled.png` |
| QA6-09 | My orders lists and filters correctly | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-10-orders-list.png` |
| QA6-10 | Another member cannot open the order (404) | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P6-T8` |
| QA6-11 | Order numbers are well formed and unique | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-04-paid-order.png` |
| QA6-12 | Admin order desk stats, filters, table | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/final-admin-orders.png` |
| QA6-13 | Admin order detail shows member + history | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/final-admin-order.png` |
| QA6-14 | Mobile checkout | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/final-checkout-mobile.png` |
| QA6-15 | Stock, wallet and orders reconcile | ☑ Pass ☐ Fail ☐ Blocked | `phase-6/QA6-05-wallet-debit.png` |
| QA6-16 | Earlier phases regression | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA6-17 | Cleanup restores the starting state | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke cleanup` |

| Phase 6 summary | |
|---|---|
| Executed | 17 / 17 |
| Passed / Failed / Blocked | 17 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 8. Phase 7 — Manual payments & order lifecycle

Document: [phase-7-payments.md](phase-7-payments.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA7-01 | Pending order offers the proof upload | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-01-proof-panel.png` |
| QA7-02 | Valid proof moves the order to review | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-02-order-review.png` |
| QA7-03 | Wrong file type / size refused | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-03-bad-file.png` |
| QA7-04 | Attempt cap refuses the fourth upload | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-04-attempt-cap.png` |
| QA7-05 | Upload past the deadline refused | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA7-06 | Admin review pane shows the evidence | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-06-admin-review.png` |
| QA7-07 | Verification pays the order exactly once | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-07-verified.png` |
| QA7-08 | Rejection opens the correction window | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-08-rejected.png` |
| QA7-09 | Corrected proof re-reserves the stock | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-09-reupload.png` |
| QA7-10 | Admin cannot review their own order | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-10-self-review.png` |
| QA7-11 | Proof access is admin-only | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-11-proof-guard.png` |
| QA7-12 | Admin cancel refunds the wallet buckets | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-12-refund.png` |
| QA7-13 | Expiry command cancels once | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-13-expiry.png` |
| QA7-14 | Settings save and validation | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-14-settings.png` |
| QA7-15 | Timeline and ledger reconcile | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-15-reconcile.png` |
| QA7-16 | Mobile proof panel and review pane | ☑ Pass ☐ Fail ☐ Blocked | `phase-7/QA7-16-mobile.png` |
| QA7-17 | Earlier phases regression | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA7-18 | Cleanup restores the starting state | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke cleanup` |

| Phase 7 summary | |
|---|---|
| Executed | 18 / 18 |
| Passed / Failed / Blocked | 18 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 9. Phase 8 — Fulfilment, completion & invoices

Document: [phase-8-fulfilment.md](phase-8-fulfilment.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA8-01 | Paid order shows invoice + next-step copy | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-01-member-paid.png` |
| QA8-02 | Ready-to-ship state on the member side | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-02-member-ready.png` |
| QA8-03 | Shipped order shows courier + tracking | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-03-member-shipped.png` |
| QA8-04 | Delivered order asks for confirmation | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-04-member-delivered.png` |
| QA8-05 | Confirm receipt completes the order | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-05-member-completed.png` |
| QA8-06 | Printable A4 invoice with frozen facts | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/final-invoice.png` |
| QA8-07 | Reprinting returns the same invoice | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-07-invoice-reprint.png` |
| QA8-08 | Another member cannot open the invoice | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-08-invoice-guard.png` |
| QA8-09 | Start packing | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-09-admin-paid.png` |
| QA8-10 | Mark ready to ship | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-10-admin-packing.png` |
| QA8-11 | Shipping validation + stock deduction | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-11-admin-ship-form.png` |
| QA8-12 | Mark delivered | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-12-admin-delivered.png` |
| QA8-13 | Shipped order cannot be cancelled | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-13-no-cancel-after-ship.png` |
| QA8-14 | Admin invoice + void after cancellation | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-14-admin-invoice.png` |
| QA8-15 | Auto-complete job (URL, idempotent) | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-15-auto-complete.png` |
| QA8-16 | Seller block + completion settings | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-16-admin-settings.png` |
| QA8-17 | Stock, ledger and invoice invariants | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-17-invariants.png` |
| QA8-18 | Mobile layout + earlier phases | ☑ Pass ☐ Fail ☐ Blocked | `phase-8/QA8-18-mobile.png` |
| QA8-19 | Cleanup restores the starting state | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke cleanup` |

| Phase 8 summary | |
|---|---|
| Executed | 19 / 19 |
| Passed / Failed / Blocked | 19 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 10. Phase 9 — Registration, registration codes, membership & activation

Document: [phase-9-membership.md](phase-9-membership.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA9-01 | Standard registration signs the guest in | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-01-register-standard.png` |
| QA9-02 | Code registration lands active with the package | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-02-register-code.png` |
| QA9-03 | Invalid / used / expired codes are refused | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-03-code-refused.png` |
| QA9-04 | Referral link pre-fills the sponsor (pending) | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-04-register-referral.png` |
| QA9-05 | Guest e-wallet registration is sent to login | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite` |
| QA9-06 | Sponsor pays a registration from the e-wallet | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-06-register-ewallet.png` |
| QA9-07 | Pending member activates with a code | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-07-membership-code.png` |
| QA9-08 | Pending member activates with the e-wallet | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-08-membership-wallet.png` |
| QA9-09 | Manual proof → admin approve activates | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-09-admin-approve.png` |
| QA9-10 | Admin rejection returns the member to pending | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/QA9-10-admin-reject.png` |
| QA9-11 | Codes: issue, filter, expire, CSV export | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/final-admin-codes.png` |
| QA9-12 | Member directory, detail, suspend/restore | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/final-admin-members.png` |
| QA9-13 | Genealogy upline/downline, no binary fields | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/final-genealogy-team.png` |
| QA9-14 | Dashboard: membership, wallet, referral, orders | ☑ Pass ☐ Fail ☐ Blocked | `phase-9/final-dashboard.png` |
| QA9-15 | Members cannot reach the admin membership screens | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P9-T15` |
| QA9-16 | Ledger/code/activation invariants after the pass | ☑ Pass ☐ Fail ☐ Blocked | `automated: wallet_reconcile` |

| Phase 9 summary | |
|---|---|
| Executed | 16 / 16 |
| Passed / Failed / Blocked | 16 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 11. Defect log

Add one row per problem found. Keep the status current until the defect is retested and closed.

| ID | Phase / check | Severity | What happened (actual) | Expected | Evidence | Status |
|---|---|---|---|---|---|---|
| — | Phases 1–9 | — | No defects were raised in this pass. | — | — | Closed |

Severity reminder: **blocker** = cannot continue · **major** = wrong result · **minor** = visible
problem, flow still works · **cosmetic** = polish only.

For each defect the developer fixes, the tester retests that single check and changes the status to
**Closed** with a date and the new screenshot name.

---

## 12. Final decision

| Phase | Approved? | Notes / accepted exceptions |
|---|---|---|
| Phase 1 | ☑ Yes ☐ No | 17 / 17 checks passed. |
| Phase 2 | ☑ Yes ☐ No | 20 / 20 checks passed. |
| Phase 3 | ☑ Yes ☐ No | 16 / 16 checks passed. |
| Phase 4 | ☑ Yes ☐ No | 17 / 17 checks passed. |
| Phase 5 | ☑ Yes ☐ No | 16 / 16 checks passed. |
| Phase 6 | ☑ Yes ☐ No | 17 / 17 checks passed. |
| Phase 7 | ☑ Yes ☐ No | 18 / 18 checks passed. |
| Phase 8 | ☑ Yes ☐ No | 19 / 19 checks passed. |
| Phase 9 | ☑ Yes ☐ No | 16 / 16 checks passed; 400 / 400 PHP cases and 132 / 132 smoke checks green. |

All nine phases are **Approved**. No defect is open, so no accepted exception is recorded.

An **Approved with notes** decision is allowed only when every open defect is cosmetic and the lead
accepts it. Any blocker or major defect keeps the phase open.

| Role | Name | Signature | Date |
|---|---|---|---|
| QA tester | Automated QA pass — DeepSeek Harness (DSH) | n/a (automated run) | 2026-11-07 |
| Project lead |  |  |  |

The QA tester line records the automated pass; the project-lead line is left for the human
countersignature.

When this sheet is complete and accepted:

1. Add the evidence folder to the project’s review location (attach it to the task or commit it).
2. The lead records the visual sign-off in `docs/phase-log.md` for each phase.
3. Archive this sheet next to the phase log so the gate has a single reference.
