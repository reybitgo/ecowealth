# EcoWealth — QA sign-off sheet (Phases 1–16)

Fill this sheet while working through the sixteen phase documents. Hand the completed sheet plus the
`evidence/` folder to the project lead. This is the record that closes the “visual sign-off” item
still open in each phase of `docs/phase-log.md`.

**Status of this pass (2026-11-12):** Phases 1–14 are recorded as **passed** — **200 / 200**
manual checks, **0 failed, 0 blocked** — with green automated gates (**560 → 669** PHP cases and
**143 → 173** smoke checks as Phases 11–14 landed; the current full suite is **816 / 816** PHP
cases and **195 / 195** smoke checks after Phase 16). The Phase 13 queue was rechecked after the
approval / rejection confirmation-modal change: both decisions ask first, and a rejection reason is
typed in the modal. Phase 14 adds the super-login contract: a URL-token member session, its banner,
Exit/TTL and the impersonation audit. Phase 15 adds the reports desk, the eight settings cards, the
audit desk and the hardening pass. Phase 16 is the install/reset workstream (IR-1 … IR-8): the
production bundle, the CLI and browser installers, the guarded reset and the go-live tool
retirement, signed from the automated suite, the smoke run and the `evidence/install-reset/`
folder.

**How to use**

1. Fill the *Environment* block once.
2. Record the automated results (§1).
3. For each phase, tick every check in its table. Add the evidence file name; for Phases 4–14 the file
   lives in `docs/qa/evidence/phase-<n>/`. Phases 1–3 predate the per-phase folders, so their evidence
   lives in the topic folders (`dashboard/`, `errors/`, `shell/`, `shop/`); a check with no screenshot
   cites the automated suite instead.
4. Log every failure in the *Defect log* (§16) and keep its status up to date.
5. Complete the *Final decision* (§17) and sign.

---

## Environment

| Item | Value |
|---|---|
| Tester name | Automated QA pass — DeepSeek Harness (DSH) |
| Date(s) of testing | 2026-11-07 … 2026-11-12 |
| Repository / branch | `feature/shop` |
| Tags under test | `phase-1` … `phase-9` (+ profile payout-account follow-up), `phase-10`, `phase-11`, `phase-12`, `phase-13`, `phase-14`, `phase-15`, `ir-0` … `ir-8` |
| App URL | `http://localhost/ecowealth/system/` |
| Database | `ecowealth_sneat` (Phases 1–15 and 17) · scratch `*_test` / `*_manual` schemas (Phase 16) · scratch `ecowealth_sneat_qa17a` / `_qa17g` schemas (Phase 17) |
| Operating system | Windows 11 Home Single Language 10.0.26200 (build 26200) |
| Browser + version | Google Chrome 156.0.8078.80 |
| Screen size(s) | 1440 × 900 · 390 × 844 (mobile emulation) |
| Laragon / PHP / MySQL versions (if known) | Laragon 8 · PHP 8.3.16 · MySQL 8.4.3 |

---

## 1. Automated evidence (run before the manual pass)

| Command | Expected | Actual | Result |
|---|---|---|---|
| `php bin\build_install_sql.php --check` | exit 0, no output | exit 0, no output | ☑ Pass ☐ Fail |
| `php tests\run.php` | `Cases: 948   Passed: 948   Failed: 0` / `All good.` | `Cases: 948   Passed: 948   Failed: 0` / `All good.` | ☑ Pass ☐ Fail |
| `powershell -NoProfile -File tests\smoke.ps1` | `Passed: 225   Failed: 0` | `Passed: 225   Failed: 0` | ☑ Pass ☐ Fail |

Paste the final lines of each command here:

```text
php bin\build_install_sql.php --check
(no output; exit 0)

php tests\run.php
----------------------------------------------------------------
Cases: 948   Passed: 948   Failed: 0
All good.

powershell -NoProfile -File tests\smoke.ps1
  P1-T11 PASS   guest sees the 503 page with Retry-After: 3600 (got 503, retry=3600)
  P1-T11 PASS   admin bypasses maintenance mode (got 200)

Passed: 225   Failed: 0
```

All three commands were run from `system\` and exited **0** on 2026-11-12. `bin/wallet_reconcile.php` also
exits **0** afterwards (both wallet buckets match the ledger, every credited commission has its ledger
movement, every transfer has its sender debit, recipient credit and fee credit, and every payout request
has its hold with every rejection refunded).

Total manual checks in §2–§18 (all eighteen phases): **273** — **273 recorded as passed**, 0 failed,
0 blocked.

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

## 11. Phase 10 — Direct referral commissions

Document: [phase-10-commissions.md](phase-10-commissions.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA10-01 | A code registration pays the sponsor immediately | ☑ Pass ☐ Fail ☐ Blocked | `phase-10/member-earnings.png` |
| QA10-02 | Manual approval pays once; a second approval pays nothing | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P10-T4` |
| QA10-03 | A package with no direct bonus pays nothing | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P10-T3` |
| QA10-04 | A package-less sponsor is skipped, not paid | ☑ Pass ☐ Fail ☐ Blocked | `phase-10/admin-ledger-skipped.png` |
| QA10-05 | The company root as sponsor pays nothing | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P10-T2` |
| QA10-06 | The award drawer traces activation → wallet | ☑ Pass ☐ Fail ☐ Blocked | `phase-10/admin-drawer.png` |
| QA10-07 | Member earnings statement, filters, drawer, mobile | ☑ Pass ☐ Fail ☐ Blocked | `phase-10/member-earnings.png`, `phase-10/member-earnings-mobile.png` |
| QA10-08 | Admin commission ledger, filters, totals, mobile | ☑ Pass ☐ Fail ☐ Blocked | `phase-10/admin-ledger.png`, `phase-10/admin-ledger-mobile.png` |
| QA10-09 | Members cannot see each other's awards | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P10-T8` |
| QA10-10 | Finance report clean; demo wallets restored | ☑ Pass ☐ Fail ☐ Blocked | `automated: wallet_reconcile` |

| Phase 10 summary | |
|---|---|
| Executed | 10 / 10 |
| Passed / Failed / Blocked | 10 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-07 |

---

## 12. Phase 11 — Indirect referral commissions

Document: [phase-11-commissions.md](phase-11-commissions.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA11-01 | A code registration pays the chain; the indirect filter correctly omits the level-1 sponsor | ☑ Pass ☐ Fail ☐ Blocked | `phase-11/admin-ledger.png`, `phase-11/admin-ledger-indirect.png` |
| QA11-02 | Member earnings level breakdown and CSV | ☑ Pass ☐ Fail ☐ Blocked | `phase-11/member-earnings.png` |
| QA11-03 | Package B pays a 20% premium ladder over Package A | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P11-T4` |
| QA11-04 | An inactive upline consumes its level | ☑ Pass ☐ Fail ☐ Blocked | `phase-11/admin-ledger-skipped.png` |
| QA11-05 | Package/global switches and the depth cap | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P11-T5` |
| QA11-06 | A replay pays nothing twice | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P11-T6` |
| QA11-07 | A zero level writes no row | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P11-T7` |
| QA11-08 | Admin type/level filter and CSV export | ☑ Pass ☐ Fail ☐ Blocked | `phase-11/admin-ledger-level3.png` |
| QA11-09 | Members cannot read or export each other's awards | ☑ Pass ☐ Fail ☐ Blocked | `automated: smoke P11-T9/T10` |
| QA11-10 | Finance report clean; demo wallets restored | ☑ Pass ☐ Fail ☐ Blocked | `automated: wallet_reconcile` |

| Phase 11 summary | |
|---|---|
| Executed | 10 / 10 |
| Passed / Failed / Blocked | 10 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-09 |

---

## 13. Phase 12 — E-wallet transfers, limits and monitoring

Document: [phase-12-transfers.md](phase-12-transfers.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA12-01 | A fee-free transfer moves the money, writes two ledger rows and updates the counters | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P12-T1, smoke P12-T1/T2` |
| QA12-02 | A fee is charged and credited to the primary admin (third ledger leg) | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P12-T2, smoke P12-T2` |
| QA12-03 | The refusals (minimum, balance, self, suspended) write nothing | ☑ Pass ☐ Fail ☐ Blocked | `phase-12/refusals.png`, `automated: php suite P12-T3/T4/T5` |
| QA12-04 | A wrong password refuses before anything locks or writes | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P12-T6, smoke P12-T6` |
| QA12-05 | The daily and weekly limits at their exact boundaries | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P12-T7/T8` |
| QA12-06 | The reset cron (doctor, dry run, real run, second run, `--week`) | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P12-T9, smoke P12-T9` |
| QA12-07 | Staff transfer free and unlimited; a superadmin is attributed to the primary admin | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P12-T12, smoke P12-T10` |
| QA12-08 | The monitor stat cards and transfers tab; the finance report reconciles transfers | ☑ Pass ☐ Fail ☐ Blocked | `automated: php suite P12-T3, smoke P12-T3, TransferViewTest` |

| Phase 12 summary | |
|---|---|
| Executed | 8 / 8 |
| Passed / Failed / Blocked | 8 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-09 |

Phase 12 was signed from the automated suite and the HTTP smoke run. The browser screenshots named in
the phase document's *Evidence* rows were not produced for this pass, so the rows above cite the
automated checks that cover the same behaviour, plus `phase-12/refusals.png` — the one refusal screen
captured for this pass.

---

## 14. Phase 13 — Payouts

Document: [phase-13-payouts.md](phase-13-payouts.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA13-01 | A request holds the amount, stores the fee and closes the form | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T1/T5, smoke P13-T1, PayoutViewTest` |
| QA13-02 | The refusals (minimum, withdrawable, second request, bad account, closed channel) write nothing | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T2/T3/T4 + channel/account cases, smoke P13-T2/T3/T4` |
| QA13-03 | USDT converts on the server, stores the rate/gas snapshot and is closed while the rate is unset | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T6 (+ unset-rate case), smoke P13-T6` |
| QA13-04 | Approve records the decision and moves no money | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T7, smoke P13-T7; browser: approve modal` |
| QA13-05 | Complete settles it without a second debit | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T8, smoke P13-T8` |
| QA13-06 | Reject refunds the hold exactly once and records the reason | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T9 (+ id-collision case), smoke P13-T9; browser: reject modal with required reason` |
| QA13-07 | A member cannot process; nobody processes their own request | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T10/T11, smoke P13-T10` |
| QA13-08 | Queue tabs, totals and filters; the finance report reconciles payouts | ☑ Pass ☐ Fail ☐ Blocked | `automated: P13-T12/T13, PayoutLiveTest, smoke P13-T12` |

| Phase 13 summary | |
|---|---|
| Executed | 8 / 8 |
| Passed / Failed / Blocked | 8 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-11 |

Phase 13 was signed from the automated suite, the HTTP smoke run and a headless-Chrome pass over the
queue's confirmation modals. The screenshots named in the phase document's *Evidence* rows were not
produced for this pass, so the rows above cite the automated checks that cover the same behaviour plus
the browser checks for the new approve/reject modals. The browser pass confirmed the approve modal
posts no note, the reject modal requires the reason (500-character cap) and writes it into the POST
body, and cancelling either modal posts nothing. The two smoke checks that had drifted behind the
Phase 13 UI — the five settings cards and Juan's remaining demo balance after the first hold — were
corrected in `tests/smoke.ps1` and the full run is green at **161 / 161**.

---

## 15. Phase 14 — Super-login

Document: [phase-14-super-login.md](phase-14-super-login.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA14-01 | The console opens `juan` through a URL token, the member banner shows, and only the hash is stored | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T1/T10, smoke P14-T1` |
| QA14-02 | Every link/form/refresh inside the tab keeps the token and stays impersonated | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T2, smoke P14-T2` |
| QA14-03 | Exit returns to the console, marks `logged_out`, kills the token and leaves the superadmin signed in | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T3/T11, smoke P14-T3/T11` |
| QA14-04 | A session past its 8-hour TTL bounces to the console, is marked `expired` and stays dead | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T4, smoke P14-T4` |
| QA14-05 | A changed user-agent (or IP) ends the session and marks it `expired` | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T5, smoke P14-T5` |
| QA14-06 | Shared/static URLs never carry the token; the audit page never shows the hash | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T2, ImpersonationViewTest P14-T5` |
| QA14-07 | Admin/guest guards and the target rules (no staff, no suspended member) | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T6/T7, smoke P14-T6/T7` |
| QA14-08 | Six failures are rate-limited; the audit lists every session and refusal | ☑ Pass ☐ Fail ☐ Blocked | `automated: P14-T8/T12, ImpersonationViewTest P14-T5/T6, smoke P14-T8/T12` |

| Phase 14 summary | |
|---|---|
| Executed | 8 / 8 |
| Passed / Failed / Blocked | 8 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-11 |

Phase 14 was signed from the automated suite and the HTTP smoke run. The browser screenshots named in
the phase document's *Evidence* rows were not produced for this pass; the rows above cite the automated
checks that cover the same behaviour. The smoke run opened a real URL-token session over HTTP, followed a
token-bearing member link, exited, replayed the dead token, exercised TTL and user-agent expiry, checked
the admin/member/guest guards, refused an admin target, rate-limited six attempts and opened the audit
page — **173 / 173** checks green, and the run cleaned up its sessions and audit rows afterwards.

---

## 16. Phase 15 — Hardening, dashboards, reports and launch

Document: [phase-15-launch.md](phase-15-launch.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA15-01 | The admin dashboard shows sales today/30d, orders by status, top products, commissions, wallet float and the payout hold, and refreshes from the same URL | ☑ Pass ☐ Fail ☐ Blocked | `automated: AdminDashboardLiveTest, LaunchTest P15-T4` |
| QA15-02 | The reports desk renders sales by day, commissions by level, the payout register, code revenue, top products, orders by status and the health widget for an inclusive window | ☑ Pass ☐ Fail ☐ Blocked | `automated: ReportTest, ReportViewTest; smoke P15-T1` |
| QA15-03 | Each of the five CSV exports downloads for the selected window and writes one `report.export` audit row | ☑ Pass ☐ Fail ☐ Blocked | `automated: ReportTest exports; smoke P15-T1` |
| QA15-04 | The eight settings cards save independently; an invalid pair is refused; the brand and the transfer rules take effect; every change is audited with before/after | ☑ Pass ☐ Fail ☐ Blocked | `automated: SettingsPageTest, AdminSettingsLiveTest, LaunchTest P15-T7; smoke P15-T7` |
| QA15-05 | The audit desk lists and filters actions, subjects, actors and dates; it has no edit/delete path | ☑ Pass ☐ Fail ☐ Blocked | `automated: AuditLogTest; smoke P15-T12` |
| QA15-06 | Shared security headers on every page; a PHP probe under `uploads/` is refused; error pages are branded with no trace | ☑ Pass ☐ Fail ☐ Blocked | `automated: HardeningTest; smoke P15-T2/T8` |
| QA15-07 | The throttle refuses the 11th transfer in the window and writes nothing; checkout/proof/payout limits are wired | ☑ Pass ☐ Fail ☐ Blocked | `automated: HardeningTest; smoke P15-T2` |
| QA15-08 | The UAT money path reconciles end to end (code -> commission -> purchase -> fulfilment -> transfer -> payout -> health) | ☑ Pass ☐ Fail ☐ Blocked | `automated: LaunchTest P15-T1/T4/T11` |
| QA15-09 | Launch scale: 1,000 orders / 200 products / 500 ledger rows keep every report under 1 s and the page under 2 s | ☑ Pass ☐ Fail ☐ Blocked | `automated: LaunchTest P15-T3; smoke P15-T3` |
| QA15-10 | A fresh install reaches the final schema and seeds (migration 019 + fresh-install index comparison) | ☑ Pass ☐ Fail ☐ Blocked | `automated: MigrationTest, LaunchTest P15-T10` |
| QA15-11 | A backup restores into a scratch schema with identical counts, a clean reconciliation and a clean health card | ☑ Pass ☐ Fail ☐ Blocked | `manual: runbook §3 drill, 2026-11-11` |
| QA15-12 | The final invariants are zero after the mixed scenario and the S-Login audit has both ends | ☑ Pass ☐ Fail ☐ Blocked | `automated: LaunchTest P15-T4/T12, HealthService` |

| Phase 15 summary | |
|---|---|
| Executed | 12 / 12 |
| Passed / Failed / Blocked | 12 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-11 |

Phase 15 was signed from the automated suite, the HTTP smoke run and the restore drill. The suite is
**722 / 722** and the smoke script **185 / 185**; `/admin/reports` answered in 0.103 s and 0.171 s on
the two measured runs; the restore drill reproduced 26 users, 16 orders, 48 ledger rows, 12
commissions, 2 payout requests and 1,781 audit rows with a clean reconciliation and a 7/7 health
card. No browser screenshots were produced for this pass; the checks above cite the automated and
HTTP evidence that covers the same behaviour.

---

## 17. Phase 16 — Install, reset and go-live tools

Document: [phase-16-install-reset.md](phase-16-install-reset.md)

Phase 16 is the install/reset workstream (IR-1 … IR-8). Every check below runs on a scratch schema
(`*_test` / `*_manual`), never on `ecowealth_sneat`; the evidence is in `evidence/install-reset/`.

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA16-01 | `mysql < install.sql` on an empty schema reaches 27/48/2/0/7/2/18/10/0 and the seeded admin signs in | ☑ Pass ☐ Fail ☐ Blocked | `install-sql-mysql.txt`, `ir2-suite.log` |
| QA16-02 | A non-empty schema is refused by the bundle guard (ERROR 1146 naming the empty-database table, exit 1) | ☑ Pass ☐ Fail ☐ Blocked | `install-sql-guard.txt`, `ir2-suite.log` |
| QA16-03 | The CLI installer's 15-check preflight, verified install, lock JSON, re-run/non-empty refusals and one-document `--json` | ☑ Pass ☐ Fail ☐ Blocked | `install-cli-check.txt`, `install-cli-success.txt`, `install-cli-success.json`, `install-cli-refused.txt`, `ir3-suite.log` |
| QA16-04 | phpMyAdmin imports the same 54-query bundle into an empty schema | ☑ Pass ☐ Fail ☐ Blocked | `install-sql-phpmyadmin.png`, `install-sql-phpmyadmin-import.png` |
| QA16-05 | The web installer's locked, non-empty, preflight, success and mobile states; GET/CSRF-less POST write nothing | ☑ Pass ☐ Fail ☐ Blocked | `install-preflight.png`, `install-preflight-details.png`, `install-success.png`, `install-locked.png`, `install-nonempty.png`, `install-mobile.png`, `install-web.txt`, `ir4-suite.log` |
| QA16-06 | `db_reset --dry-run` prints the target, counts and plan with `writes: 0` and changes nothing | ☑ Pass ☐ Fail ☐ Blocked | `reset-cli-preview.txt`, `ir5-suite.log` |
| QA16-07 | The CLI reset exits 0 with every step and all verification checks green, five codes, `wallet_reconcile` exit 0 and an app that signs in | ☑ Pass ☐ Fail ☐ Blocked | `reset-cli-success.txt`, `reset-cli-success.json`, `reset-app-smoke.txt`, `ir5-suite.log` |
| QA16-08 | A second reset is idempotent: every business-data step reports 0 deleted (the previous run's generated codes are inventory) and verification stays green | ☑ Pass ☐ Fail ☐ Blocked | `final-verification.txt`, `ir5-suite.log` |
| QA16-09 | Wrong phrase, missing backup acknowledgement and missing CSRF each re-render with an error and change nothing | ☑ Pass ☐ Fail ☐ Blocked | `reset-form.png`, `reset-validation.png`, `reset-web.txt`, `ir6-smoke.log` |
| QA16-10 | A valid console submit produces the report (steps, verification, before/after, codes) and refresh never re-runs | ☑ Pass ☐ Fail ☐ Blocked | `reset-success.png`, `reset-web.txt`, `ir6-suite.log` |
| QA16-11 | A member session and an S-Login (impersonated) tab both get 403; a guest is redirected to login | ☑ Pass ☐ Fail ☐ Blocked | `reset-member-403.png`, `reset-impersonation-403.png`, `ir6-suite.log` |
| QA16-12 | Production without `DB_RESET_ENABLED` renders the branded 404 for every role | ☑ Pass ☐ Fail ☐ Blocked | `reset-production-404.png` |
| QA16-13 | Production with `DB_RESET_ENABLED=true`: admin 403, superadmin form with the database name as the phrase and no demo option | ☑ Pass ☐ Fail ☐ Blocked | `reset-production-form.png`, `reset-production-admin-403.png` |
| QA16-14 | A production install with no `INSTALL_KEY` is refused with the exact hint; a wrong key is throttled; a crafted POST writes nothing | ☑ Pass ☐ Fail ☐ Blocked | `install-key-refused.png`, `install-key-refused-full.png`, `install-key-wrong.png`, `install-key-throttle.png`, `ir6-suite.log` |
| QA16-15 | Both parity comparisons match by natural key (reset ≡ fresh install; reset+catalogue+demo ≡ fresh QA install) and the demo tree renders | ☑ Pass ☐ Fail ☐ Blocked | `parity-reset-vs-install.txt`, `reset-demo.txt`, `reset-demo-genealogy.png`, `reset-demo-earnings.png`, `ir7-suite.log` |
| QA16-16 | Go-live: both tool URLs 404 after deletion, the verification pack prints the expected counts, no demo/default credential survives and the security review is green | ☑ Pass ☐ Fail ☐ Blocked | `go-live-tools-404.png`, `go-live-checklist.txt`, `ir8-security-review.txt`, `final-verification.txt`, `ir8-suite.log`, `ir8-smoke.log` |

| Phase 16 summary | |
|---|---|
| Executed | 16 / 16 |
| Passed / Failed / Blocked | 16 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-12 |

Phase 16 was signed from the automated suite (**816 / 816**), the HTTP smoke run (**195 / 195**),
the `build_install_sql --check` gate, the IR-2 … IR-7 evidence (the local `mysql` and phpMyAdmin
imports, the CLI and web installers, the CLI and web reset, the demo fixtures) and the IR-8 final
verification pass, whose tool-retirement capture proves both pages are gone on the production copy.
No defect was raised; the deviations are the documented ones in `docs/phase-log.md` (the
installer's pre-bundle demo/catalogue limitation, the Windows lock-file ACL and the
`--reset-catalogue` + customized-seed residual case).

---

## 18. Phase 17 — Unilevel repeat-purchase bonus (signed)

Document: [phase-17-unilevel-repeat-purchase.md](phase-17-unilevel-repeat-purchase.md)

Phase 17 is delivered in seven gated phases (**17A → 17G**) and is **signed at all seven gates**. The
pack's sections A–G hold **57 checks** (A 8 · B 9 · C 10 · D 8 · E 9 · F 7 · G 6); every check has at
least one file in `evidence/phase-17/`, indexed by that folder's `README.md`. Sections A and B are
tag-scoped (§2.2 of the pack): three checks describe the tree before 17B/17C deliberately changed it and
must be run at their own tag.

**The seven gate records** (copied into Appendix G of the plan):

| Phase | Tag | Date | Suite passed / ran | Smoke passed / ran | Migration / bundle | Manual QA | Tester | Deviations | Signed |
|---|---|---|---:|---:|---|---|---|---|---|
| 17A Foundation | `p17a-foundation` | 2026-11-12 | 830 / 830 | 195 / 195 | `--check` exit 0; migration 020 applied twice, idempotent | A: 8 / 8 | DSH | 4 | ☑ |
| 17B Configuration | `p17b-configuration` | 2026-11-12 | 855 / 855 | 209 / 209 | no schema change (`--check` exit 0; `wallet_reconcile` exit 0) | B: 9 / 9 | DSH | 7 | ☑ |
| 17C Money engine | `p17c-money` | 2026-11-12 | 906 / 906 | 209 / 209 | no schema change; `wallet_reconcile` exit 0 with five sections | C: 10 / 10 | DSH | 5 | ☑ |
| 17D Admin visibility | `p17d-admin` | 2026-11-12 | 924 / 924 | 215 / 215 | no schema change | D: 8 / 8 | DSH | 7 | ☑ |
| 17E Member visibility | `p17e-member` | 2026-11-12 | 938 / 938 | 223 / 223 | no schema change | E: 9 / 9 | DSH | 8 | ☑ |
| 17F Operations | `p17f-month-close` | 2026-11-12 | 948 / 948 | 223 / 223 | no schema change; the close moves no money | F: 7 / 7 | DSH | 8 | ☑ |
| 17G Release | `p17g-release` | 2026-11-12 | 948 / 948 | 225 / 225 | no schema change; `APP_PHASE = 17`; `--check` exit 0; `wallet_reconcile` exit 0 | G: 6 / 6 | DSH | 3 | ☑ |

The per-phase deviation lists, with the reason and the phase that owns each one, are in the plan's
Appendix G (the deviation log) and in `docs/phase-log.md`.

**The checks**

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA17A-01 | Migration 020 applies twice and builds exactly four `*unilevel*` tables (31 tables total), no error | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-01-tables.txt` |
| QA17A-02 | The four altered tables carry the documented columns; `ref_type` ends `'unilevel','unilevel_reversal'`; both order indexes exist | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-02-columns.txt` |
| QA17A-03 | Six `unilevel%` settings with the documented defaults; every product switches off | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-03-settings.txt` |
| QA17A-04 | No repeat-purchase UI at 17A (tag-scoped; 17B adds it) | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-04-admin-settings.png`, `QA17A-04-product-edit.png` |
| QA17A-05 | A paid order at 17A pays and stamps nothing (tag-scoped; 17C stamps it) | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-05-order.txt`, `QA17A-05-awards-count.txt` |
| QA17A-06 | The `/wallet` source filter lists *Repeat-purchase bonus* and *Repeat-purchase reversal*; both return no rows | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-06-wallet-filter.png`, `QA17A-06-wallet-reversal.png` |
| QA17A-07 | A rebuilt bundle installs to `31 / 54 / 2 / 0 / 7 / 2 / 18 / 10 / 0`; `wallet_reconcile` exits 0 | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-07-verification.txt` |
| QA17A-08 | A development reset empties the three money tables, keeps the ladder and the six settings | ☑ Pass ☐ Fail ☐ Blocked | `QA17A-08-reset.txt` |
| QA17B-01 | A migrated product reads ten blank rows, `0.000%`, switch off, P.V. `0.00`, base Auto, no direct/indirect control | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-01-product-edit.txt` |
| QA17B-02 | P.V. 250 + the reference ladder + the switch on saves ten rows totalling `29.000%`, one audit entry | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-02-saved-ladder.txt` |
| QA17B-03 | A 200% ladder is refused with the sum error and the stored values are untouched | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-03-sum-error.txt` |
| QA17B-04 | Switching on with every level cleared is refused, ladder unchanged | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-04-empty-ladder.txt` |
| QA17B-05 | A P.V. base with P.V. 0 is refused; Auto with P.V. 0 is accepted | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-05-pv-base.txt` |
| QA17B-06 | The list badge reads `Repeat bonus 29%`; `?unilevel=on` returns the product and `off` does not | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-06-products-list.txt` |
| QA17B-07 | The *Repeat-purchase bonus* block renders the five keys (never the sixth); depth 11 refused; a valid save persists and audits | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-07-settings.txt` |
| QA17B-08 | With JavaScript off the ladder, per-unit amounts and total are server-rendered and still save | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-08-no-js.txt` |
| QA17B-09 | A paid order at 17B writes no award and no ledger row (tag-scoped; 17C stamps it) | ☑ Pass ☐ Fail ☐ Blocked | `QA17B-09-order.txt` |
| QA17C-01 | Configure, enable and pay an e-wallet order: the four-deep chain is paid once | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-01-order.txt` |
| QA17C-02 | Each upline's wallet shows its withdrawable credit and its one `unilevel` ledger row | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-02-wallets.txt` |
| QA17C-03 | The admin wallet statement shows the same four movements | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-03-admin-wallet.txt` |
| QA17C-04 | `wallet_reconcile.php` exits 0 with a **Repeat-purchase** section | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-04-reconcile.txt` |
| QA17C-05 | A manual GCash order pays only after the admin verifies it, and pays once | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-05-manual.txt` |
| QA17C-06 | A disabled upline is skipped with its reason and no compression | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-06-skipped.txt` |
| QA17C-07 | Cancelling a paid order voids the awards, debits what the wallet holds and shows the shortfall | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-07-reversal.txt` |
| QA17C-08 | The monthly Active rule blocks a below-threshold upline for the following month | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-08-active.txt` |
| QA17C-09 | The global kill switch stamps the order with a zero total and never pays | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-09-kill-switch.txt` |
| QA17C-10 | The month close writes the snapshot once and a second run changes nothing | ☑ Pass ☐ Fail ☐ Blocked | `QA17C-10-close.txt` |
| QA17D-01 | The ledger header reads ₱90.00, 4 awards and 4 recipients, with the Lvl column | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-01-ledger.txt` |
| QA17D-02 | Filters narrow the rows and a skipped level shows what was due and why | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-02-filters.txt` |
| QA17D-03 | A cancelled order shows voided rows and keeps the shortfall visible | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-03-reversal.txt` |
| QA17D-04 | A row links to its order and to the recipient's wallet movement | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-04-order-wallet.txt` |
| QA17D-05 | The filtered CSV matches the screen, column for column | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-05-export.txt`, `QA17D-05-export.csv` |
| QA17D-06 | The order card explains an unpaid order and a pre-feature payment | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-06-order-states.txt` |
| QA17D-07 | The four report cards, the range export and the dashboard KPI read the same numbers | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-07-reports.txt` |
| QA17D-08 | The package commission ledger is unchanged | ☑ Pass ☐ Fail ☐ Blocked | `QA17D-08-package-tab.txt` |
| QA17E-01 | The product panel shows ten levels, the per-unit amounts and the no-rebate sentence | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-01-product-panel.txt` |
| QA17E-02 | The checkout preview is the panel × 2 and says the money is paid with the order | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-02-checkout-preview.txt` |
| QA17E-03 | The order page shows the total only, never the upline breakdown | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-03-order-outcome.txt` |
| QA17E-04 | The upline's repeat tab carries the award, the level-1 breakdown and the two clocks | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-04-earnings-tab.txt` |
| QA17E-05 | The repeat filters and the member CSV match | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-05-filters-export.txt`, `QA17E-05-export.csv` |
| QA17E-06 | The package tab is unchanged and the repeat tab refreshes live | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-06-package-regression.txt` |
| QA17E-07 | The buyer's own repeat tab has no award for their purchase | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-07-buyer-view.txt` |
| QA17E-08 | The dashboard tile and the membership paragraph read the Active status | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-08-dashboard-membership.txt` |
| QA17E-09 | The product panel and the repeat tab hold at 390 px with no sideways scroll | ☑ Pass ☐ Fail ☐ Blocked | `QA17E-09-mobile.txt`, `QA17E-09-*.png` |
| QA17F-01 | The doctor reports both clocks, the threshold, the ledger baseline and the pending months | ☑ Pass ☐ Fail ☐ Blocked | `QA17F-01-doctor.txt` |
| QA17F-02 | The dry run lists the months and writes no row | ☑ Pass ☐ Fail ☐ Blocked | `QA17F-02-dry-run.txt` |
| QA17F-03 | The real run closes the month: one header and one row per account | ☑ Pass ☐ Fail ☐ Blocked | `QA17F-03-close.txt` |
| QA17F-04 | A second run changes nothing (`closed=0`, exit 0) | ☑ Pass ☐ Fail ☐ Blocked | `QA17F-04-idempotent.txt` |
| QA17F-05 | `--period` on a closed month and on a future/current month | ☑ Pass ☐ Fail ☐ Blocked | `QA17F-05-period.txt` |
| QA17F-06 | The web entry serves the same job; the log, the lock and the 403 | ☑ Pass ☐ Fail ☐ Blocked | `QA17F-06-web.txt` |
| QA17F-07 | The `.cmd` launcher reports the same thing | ☑ Pass ☐ Fail ☐ Blocked | `QA17F-07-cmd.txt` |
| QA17G-01 | The automated gate: suite 948, smoke 225, `--check` exit 0, `wallet_reconcile` exit 0 | ☑ Pass ☐ Fail ☐ Blocked | `QA17G-01-suite.txt`, `QA17G-01-smoke.txt`, `QA17G-01-build.txt`, `QA17G-01-reconcile.txt` |
| QA17G-02 | A cold read of sections A–F: ten questions, nine documentation defects fixed, none open | ☑ Pass ☐ Fail ☐ Blocked | `QA17G-02-cold-read.txt` |
| QA17G-03 | The release acceptance table: 27 / 27 rows proven | ☑ Pass ☐ Fail ☐ Blocked | `QA17G-03-acceptance-table.txt` |
| QA17G-04 | The build reports phase 17 (runtime, suite header, `HardeningTest`, `/dashboard` 200) | ☑ Pass ☐ Fail ☐ Blocked | `QA17G-04-phase.txt` |
| QA17G-05 | `docs/deploy.md` end to end on a scratch install; the runbook's weekly checklist | ☑ Pass ☐ Fail ☐ Blocked | `QA17G-05-deploy.txt`, `QA17G-05-runbook.txt` |
| QA17G-06 | Every check has its evidence file; the seven gate records are on this sheet | ☑ Pass ☐ Fail ☐ Blocked | `QA17G-06-evidence.txt` |

| Phase 17 summary | |
|---|---|
| Executed | 57 / 57 (A 8 · B 9 · C 10 · D 8 · E 9 · F 7 · G 6) |
| Passed / Failed / Blocked | 57 / 0 / 0 |
| Automated gate at the release | `Cases: 948 / 948` · `Passed: 225 / 225` smoke · `build_install_sql --check` exit 0 · `wallet_reconcile` exit 0 (five sections) |
| Defects | nine documentation defects found by the section-G cold read and fixed (listed in the pack's section G sign-off and in `QA17G-02-cold-read.txt`); no product defect |
| Deviations | 3 at the 17G gate (no build-phase badge; the nine documentation corrections; the automated manual pass) — the seven gates carry 42 in all, in the plan's Appendix G |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected — approved from the executed transcripts: the full suite, the full smoke run, the bundle check, the reconciliation, the scratch-install deploy replay, the runbook's weekly commands, the cold read and the acceptance table |
| Tester initials / date | DSH · 2026-11-12 |

The "someone who did not write it" item stays open for the project lead at every gate, exactly as in
Phases 1–16: the passes were executed by the automated QA agent and the lead countersigns below.

---

## 19. Defect log

Add one row per problem found. Keep the status current until the defect is retested and closed.

| ID | Phase / check | Severity | What happened (actual) | Expected | Evidence | Status |
|---|---|---|---|---|---|---|
| — | Phases 1–16 | — | No defects were raised in this pass. | — | — | Closed |
| D-17G-01 | Phase 17 / QA17G-02 (cold read) | minor | Nine documents were stale against the released build: the pack's tag-scoping and fixture recipe were unstated, the pack said 948 / 223, `system/README.md` said `APP_PHASE` 15 and 27 / 48 with no migration 020 row, `docs/deploy.md` said 27 / 48 and "three jobs", `docs/runbook.md` said "three jobs" / 27 / 48 / 816 cases, `docs/qa/README.md` was frozen at "in progress (17C)", `docs/admin-guide.md` had no ladder-card section, and this sheet was frozen at 17B | every document matches the build | `QA17G-02-cold-read.txt` | Closed 2026-11-12 |

Severity reminder: **blocker** = cannot continue · **major** = wrong result · **minor** = visible
problem, flow still works · **cosmetic** = polish only.

For each defect the developer fixes, the tester retests that single check and changes the status to
**Closed** with a date and the new screenshot name.

---

## 20. Final decision

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
| Phase 9 | ☑ Yes ☐ No | 16 / 16 checks passed. |
| Phase 10 | ☑ Yes ☐ No | 10 / 10 checks passed; 427 / 427 PHP cases, 139 / 139 smoke checks and 20 / 20 browser checks green. |
| Phase 11 | ☑ Yes ☐ No | 10 / 10 checks passed; 560 / 560 PHP cases at the phase tag (561 / 561 today), 143 / 143 smoke checks and a headless browser pass green. |
| Phase 12 | ☑ Yes ☐ No | 8 / 8 checks passed; 594 / 594 PHP cases, 150 / 150 smoke checks and a clean transfer reconciliation green. |
| Phase 13 | ☑ Yes ☐ No | 8 / 8 checks passed; 645 / 645 PHP cases, 161 / 161 smoke checks, a clean payout reconciliation and the headless-Chrome modal pass green. |
| Phase 14 | ☑ Yes ☐ No | 8 / 8 checks passed; 669 / 669 PHP cases, 173 / 173 smoke checks and a clean audit trail (hashed nonces only) green. |
| Phase 15 | ☑ Yes ☐ No | 12 / 12 checks passed; 722 / 722 PHP cases, 185 / 185 smoke checks, a launch-scale report pass, a 7/7 health card and an executed restore drill green. |
| Phase 16 | ☑ Yes ☐ No | 16 / 16 checks passed; 816 / 816 PHP cases, 195 / 195 smoke checks, `build_install_sql --check` exit 0, both installs (mysql/phpMyAdmin/CLI/web), both resets (CLI/web), the demo parity cases and the go-live tool-retirement capture green. |
| Phase 17A | ☑ Yes ☐ No | 8 / 8 checks passed; 830 / 830 PHP cases, 195 / 195 smoke checks at the phase tag; migration 020 applied twice (idempotent), the bundle installs to 31 tables / 54 settings and a reset keeps the ladder. |
| Phase 17B | ☑ Yes ☐ No | 9 / 9 checks passed; 855 / 855 PHP cases, 209 / 209 smoke checks at the phase tag; the nine checks executed by the automated pass, awaiting the lead's independent review. |
| Phase 17C | ☑ Yes ☐ No | 10 / 10 checks passed; 906 / 906 PHP cases, 209 / 209 smoke checks; `wallet_reconcile` exit 0 with five sections; both payment paths paid the four-deep fixture and the reversal kept its shortfall visible. |
| Phase 17D | ☑ Yes ☐ No | 8 / 8 checks passed; 924 / 924 PHP cases, 215 / 215 smoke checks; the repeat ledger, order card, reports, KPI and the unchanged package tab green. |
| Phase 17E | ☑ Yes ☐ No | 9 / 9 checks passed; 938 / 938 PHP cases, 223 / 223 smoke checks; the member panel, preview, order card, repeat tab, tile and paragraph green, including the 390 px pass. |
| Phase 17F | ☑ Yes ☐ No | 7 / 7 checks passed; 948 / 948 PHP cases, 223 / 223 smoke checks; the doctor, dry run, real close, idempotency, period edges, URL entry and `.cmd` green. |
| Phase 17G | ☑ Yes ☐ No | 6 / 6 checks passed; 948 / 948 PHP cases, 225 / 225 smoke checks, `build_install_sql --check` exit 0, `wallet_reconcile` exit 0, `APP_PHASE = 17`; the release acceptance table 27 / 27 and the scratch-install deploy replay green; three deviations recorded (no build-phase badge, nine documentation corrections, the automated manual pass). |

Phases 1–17 are **Approved**: 57 / 57 Phase 17 checks passed across the seven gates, the release
acceptance table is 27 / 27, the full suite is 948 / 948 and the smoke run 225 / 225 on the release
build. One defect is recorded (D-17G-01, the nine stale documents) and it is **Closed**; the only open
item at every gate is the independent-tester review, which the project lead signs below — the automated
pass executed every section and left its transcripts in `evidence/phase-17/`.

An **Approved with notes** decision is allowed only when every open defect is cosmetic and the lead
accepts it. Any blocker or major defect keeps the phase open.

| Role | Name | Signature | Date |
|---|---|---|---|
| QA tester | Automated QA pass — DeepSeek Harness (DSH) | n/a (automated run) | 2026-11-12 |
| Project lead |  |  |  |

The QA tester line records the automated pass; the project-lead line is left for the human
countersignature.

When this sheet is complete and accepted:

1. Add the evidence folder to the project’s review location (attach it to the task or commit it).
2. The lead records the visual sign-off in `docs/phase-log.md` for each phase.
3. Archive this sheet next to the phase log so the gate has a single reference.
