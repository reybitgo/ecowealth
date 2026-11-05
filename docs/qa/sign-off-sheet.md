# EcoWealth — QA sign-off sheet (Phases 1–6)

Fill this sheet while working through the six phase documents. Hand the completed sheet plus the
`evidence/` folder to the project lead. This is the record that closes the “visual sign-off” item
still open in each phase of `docs/phase-log.md`.

**How to use**

1. Fill the *Environment* block once.
2. Record the automated results (§1).
3. For each phase, tick every check in its table. Add the evidence file name; the file lives in
   `docs/qa/evidence/phase-<n>/`.
4. Log every failure in the *Defect log* (§8) and keep its status up to date.
5. Complete the *Final decision* (§9) and sign.

---

## Environment

| Item | Value |
|---|---|
| Tester name | |
| Date(s) of testing | |
| Repository / branch | `feature/shop` |
| Tags under test | `phase-1`, `phase-2`, `phase-3`, `phase-4`, `phase-5`, `phase-6` |
| App URL | `http://localhost/ecowealth/system/` |
| Database | `ecowealth_sneat` |
| Operating system | |
| Browser + version | |
| Screen size(s) | 1440 × 900 · 390 × 844 (mobile emulation) |
| Laragon / PHP / MySQL versions (if known) | |

---

## 1. Automated evidence (run before the manual pass)

| Command | Expected | Actual | Result |
|---|---|---|---|
| `php tests\run.php` | `Cases: 238  Passed: 238  Failed: 0` / `All good.` | | ☐ Pass ☐ Fail |
| `powershell -NoProfile -File tests\smoke.ps1` | `Passed: 83  Failed: 0` | | ☐ Pass ☐ Fail |

Paste the final lines of each command here:

```text

```

---

## 2. Phase 1 — Core skeleton

Document: [phase-1-core-skeleton.md](phase-1-core-skeleton.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA1-01 | Root URL redirects a guest to login | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-02 | Login page visual integrity (assets, console) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-03 | Administrator can sign in | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-04 | Member dashboard renders completely | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-05 | Member menu shows member items only | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-06 | Guest is redirected from `/shop` with a flash | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-07 | Registration creates a working member | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-08 | Member gets 403 on `/admin` | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-09 | Unknown URL shows branded 404 | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-10 | Deep URL keeps assets on the install prefix | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-11 | Password-change validation messages | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-12 | Password change + restore works | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-13 | Sign out ends the session | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-14 | Maintenance mode: guest 503, admin bypass | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-15 | S-Login entry visible to superadmin only | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-16 | Public front door still serves | ☐ Pass ☐ Fail ☐ Blocked | |
| QA1-17 | Mobile layout (login, dashboard, shop) | ☐ Pass ☐ Fail ☐ Blocked | |

| Phase 1 summary | |
|---|---|
| Executed | ____ / 17 |
| Passed / Failed / Blocked | ____ / ____ / ____ |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | |

---

## 3. Phase 2 — Products & catalogue

Document: [phase-2-products-and-catalogue.md](phase-2-products-and-catalogue.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA2-01 | Product list and seeded rows render | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-02 | Search filters the list | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-03 | Create product with valid data | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-04 | Invalid product data refused clearly | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-05 | Edit price reaches the shop | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-06 | Image upload works and shows | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-07 | Remove image works | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-08 | `.php` renamed `.jpg` is rejected | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-09 | File over 5 MB is rejected | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-10 | Deactivation hides product and 404s detail | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-11 | Seeded product delete blocked with reason | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-12 | Unreferenced product deletes | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-13 | Storefront catalogue layout | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-14 | Quick-view modal and detail link | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-15 | Product detail page complete | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-16 | Search + pagination (optional data setup) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-17 | Script in a product name is escaped | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-18 | Member gets 403 on product admin | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-19 | Mobile catalogue + admin table | ☐ Pass ☐ Fail ☐ Blocked | |
| QA2-20 | Phase 1 regression | ☐ Pass ☐ Fail ☐ Blocked | |

| Phase 2 summary | |
|---|---|
| Executed | ____ / 20 |
| Passed / Failed / Blocked | ____ / ____ / ____ |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | |

---

## 4. Phase 3 — Packages

Document: [phase-3-packages.md](phase-3-packages.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA3-01 | Package list and seeded A/B values | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-02 | Create package with ladder + contents | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-03 | Level 1 read-only and mirrors direct bonus | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-04 | Edit level 3 persists and totals update | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-05 | Invalid package data refused clearly | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-06 | No level 1 row in `package_levels` (DB) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-07 | Duplicate contents merge | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-08 | Inactive product handling in contents | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-09 | Deactivation hides the package | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-10 | No delete action exists | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-11 | Member packages page numbers | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-12 | Full ladder shown, level 1 marked | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-13 | Member gets 403 on package admin | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-14 | Mobile packages page | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-15 | Earlier phases regression | ☐ Pass ☐ Fail ☐ Blocked | |
| QA3-16 | Cleanup leaves the seeded state | ☐ Pass ☐ Fail ☐ Blocked | |

| Phase 3 summary | |
|---|---|
| Executed | ____ / 16 |
| Passed / Failed / Blocked | ____ / ____ / ____ |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | |

---

## 5. Phase 4 — Cart

Document: [phase-4-cart.md](phase-4-cart.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA4-01 | Empty cart invites the member to the shop | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-02 | Add from a product card (toast, drawer, badges) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-03 | Repeat add increments one line | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-04 | Drawer stepper saves quantity and totals | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-05 | Cart page edit and remove | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-06 | Quantity 0 removes the line | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-07 | Adding beyond stock refused with numbers | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-08 | Over-stock line blocks checkout clearly | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-09 | Catalogue price change reaches the cart | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-10 | Inactive product flagged, removable | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-11 | Low-stock and sold-out badges accurate | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-12 | Another member cannot touch the cart (IDOR) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-13 | Cart works with JavaScript disabled | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-14 | Guest is redirected from `/cart` | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-15 | Mobile cart and drawer | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-16 | Toasts visible and console clean | ☐ Pass ☐ Fail ☐ Blocked | |
| QA4-17 | Earlier phases regression | ☐ Pass ☐ Fail ☐ Blocked | |

| Phase 4 summary | |
|---|---|
| Executed | ____ / 17 |
| Passed / Failed / Blocked | ____ / ____ / ____ |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | |

---

## 6. Phase 5 — E-wallet

Document: [phase-5-wallet.md](phase-5-wallet.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA5-01 | Empty e-wallet explains the two buckets | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-02 | Admin credit appears on both screens | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-03 | Second top-up keeps the running balance | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-04 | Invalid top-up amounts refused cleanly | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-05 | Statement filters (type/source/note) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-06 | Monitor float, buckets and directory | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-07 | Top-ups and all-movements tabs | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-08 | Member gets 403 on admin wallet pages | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-09 | Guest is redirected from `/wallet` | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-10 | Admin's own statement in back-office layout | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-11 | Top-up is audited (`audit_log`) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-12 | Reconciliation command is clean | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-13 | Mobile statement layout | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-14 | Monitor at mobile width (optional) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-15 | Earlier phases regression | ☐ Pass ☐ Fail ☐ Blocked | |
| QA5-16 | Cleanup restores zero balances | ☐ Pass ☐ Fail ☐ Blocked | |

| Phase 5 summary | |
|---|---|
| Executed | ____ / 16 |
| Passed / Failed / Blocked | ____ / ____ / ____ |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | |

---

## 7. Phase 6 — Checkout & orders

Document: [phase-6-orders.md](phase-6-orders.md)

| Check | What it verifies | Result | Evidence file |
|---|---|---|---|
| QA6-01 | Checkout shows cart, address and payments | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-02 | Address book saves and prefills checkout | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-03 | Missing term/address refused, input kept | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-04 | E-wallet order is paid and debited once | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-05 | Manual order stays pending with instructions | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-06 | Cancelling an unpaid order releases stock | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-07 | Paid order cannot be cancelled online | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-08 | E-wallet disabled when the balance is short | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-09 | My orders lists and filters correctly | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-10 | Another member cannot open the order (404) | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-11 | Order numbers are well formed and unique | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-12 | Admin order desk stats, filters, table | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-13 | Admin order detail shows member + history | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-14 | Mobile checkout | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-15 | Stock, wallet and orders reconcile | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-16 | Earlier phases regression | ☐ Pass ☐ Fail ☐ Blocked | |
| QA6-17 | Cleanup restores the starting state | ☐ Pass ☐ Fail ☐ Blocked | |

| Phase 6 summary | |
|---|---|
| Executed | ____ / 17 |
| Passed / Failed / Blocked | ____ / ____ / ____ |
| Decision | ☐ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | |

---

## 8. Defect log

Add one row per problem found. Keep the status current until the defect is retested and closed.

| ID | Phase / check | Severity | What happened (actual) | Expected | Evidence | Status |
|---|---|---|---|---|---|---|
| QA-D01 | | blocker / major / minor / cosmetic | | | | Open |
| QA-D02 | | | | | | |
| QA-D03 | | | | | | |
| QA-D04 | | | | | | |
| QA-D05 | | | | | | |

Severity reminder: **blocker** = cannot continue · **major** = wrong result · **minor** = visible
problem, flow still works · **cosmetic** = polish only.

For each defect the developer fixes, the tester retests that single check and changes the status to
**Closed** with a date and the new screenshot name.

---

## 9. Final decision

| Phase | Approved? | Notes / accepted exceptions |
|---|---|---|
| Phase 1 | ☐ Yes ☐ No | |
| Phase 2 | ☐ Yes ☐ No | |
| Phase 3 | ☐ Yes ☐ No | |
| Phase 4 | ☐ Yes ☐ No | |
| Phase 5 | ☐ Yes ☐ No | |
| Phase 6 | ☐ Yes ☐ No | |

An **Approved with notes** decision is allowed only when every open defect is cosmetic and the lead
accepts it. Any blocker or major defect keeps the phase open.

| Role | Name | Signature | Date |
|---|---|---|---|
| QA tester | | | |
| Project lead | | | |

When this sheet is complete and accepted:

1. Add the evidence folder to the project’s review location (attach it to the task or commit it).
2. The lead records the visual sign-off in `docs/phase-log.md` for each phase.
3. Archive this sheet next to the phase log so the gate has a single reference.
