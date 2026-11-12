# Plan — Unilevel Repeat-purchase Bonus (per-product levels 1–10)

**Phase 17** of the EcoWealth member system · **Status:** proposed for approval — not implemented
**Delivery model:** **seven gated phases, 17A → 17G.** Each phase is self-contained, shippable and
testable on its own; each builds on the one before it.
**Date:** 12 November 2026 · **Target page:** `http://localhost/ecowealth/system/checkout`
**Revision note:** this document replaces the single-delivery draft of the same date. The
specification, decisions, data model, money path and appendices are unchanged in substance; what
changed is the **delivery structure** — the work is now split into seven phases with a hard test gate
between each pair, so an earlier phase can be proven in production before the next one is written.

**Reference specification:** [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §7 (the
Unilevel Repeat-Purchase Bonus), with §2.4 (the month clock), §2.5 (derived vs. ledger money),
§7.4–7.5 (the upline walk and Active status) and §11.3 (Unilevel edge cases). §7.3 (the buyer's
level-0 rebate) is deliberately not implemented.
**Related plans:** [`dmp/plan-ecowealth-cart-system.md`](plan-ecowealth-cart-system.md) (the 15 phases
this one extends), [`dmp/plan-ecowealth-install-reset.md`](plan-ecowealth-install-reset.md)
(Phase 16 install/reset workstream this plan also touches), and
[`dmp/sim-unilevel.md`](sim-unilevel.md) (the end-to-end walkthrough of the reference simulator).

---

## Contents

**Part I — Orientation**

1. [Executive summary](#1-executive-summary)
2. [The phase protocol](#2-the-phase-protocol)
3. [Current state and the gap](#3-current-state-and-the-gap)
4. [Decisions](#4-decisions)

**Part II — The phases** (each one is a complete work package)

5. [Phase 17A — Foundation](#5-phase-17a--foundation)
6. [Phase 17B — Configuration](#6-phase-17b--configuration)
7. [Phase 17C — Money engine](#7-phase-17c--money-engine)
8. [Phase 17D — Admin visibility](#8-phase-17d--admin-visibility)
9. [Phase 17E — Member visibility](#9-phase-17e--member-visibility)
10. [Phase 17F — Operations](#10-phase-17f--operations)
11. [Phase 17G — Release](#11-phase-17g--release)

**Part III — The shared contract** (read once; every phase cites it)

12. [Specification (normative)](#12-specification-normative)
13. [Data model](#13-data-model)
14. [The money path](#14-the-money-path)
15. [Security, audit and abuse control](#15-security-audit-and-abuse-control)
16. [Testing harness, fixtures and QA protocol](#16-testing-harness-fixtures-and-qa-protocol)
17. [Risks and mitigations](#17-risks-and-mitigations)
18. [Open questions](#18-open-questions)

**Part IV — Appendices**

- [Appendix A — The reference ladder and the preset](#appendix-a--the-reference-ladder-and-the-preset)
- [Appendix B — Worked scenarios](#appendix-b--worked-scenarios)
- [Appendix C — SQL quick reference](#appendix-c--sql-quick-reference)
- [Appendix D — Glossary](#appendix-d--glossary)
- [Appendix E — Formula card](#appendix-e--formula-card)
- [Appendix F — Traceability to the reference document](#appendix-f--traceability-to-the-reference-document)
- [Appendix G — Phase gate record](#appendix-g--phase-gate-record)

---

# Part I — Orientation

## 1. Executive summary

### 1.1 What ships

A **per-product unilevel ladder** that pays a repeat-purchase bonus on every **paid** shop order:

- **Levels 1–10, one ladder.** The buyer's direct sponsor is **level 1**, the sponsor's sponsor is
  **level 2**, and so on to **level 10**. Each level has its own percentage, stored in
  `product_unilevel_levels` and edited in one card on the product form — the same shape as the
  package `Referral ladder`, but a single 1–10 table with no read-only row and no direct/indirect
  split.
- **No `direct_unilevel` / `indirect_unilevel` distinction.** Level 1 is simply the first level. There
  is no separate direct percentage field, no `type` column in the ledger, no per-leg switches, and no
  direct/indirect columns on any page. The product has **one** ladder and every level is treated the
  same way.
- **The buyer earns nothing.** A member who buys a product does not receive a self-rebate. This
  mirrors activation: a newly joined member earns no commission on their own package purchase; the
  sponsor chain is paid instead. The ladder therefore starts at **level 1**, not level 0.
- **An upline is paid only when they are Active**: their own personal purchases in the calendar month
  that just ended reached the configured threshold (₱500 by default, matching
  [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §7.5).
- **No compression.** An inactive or unusable upline consumes its level and is skipped; the next
  upline keeps its own level number.
- **Withdrawable money.** Every credited award is an e-wallet credit with a dedicated ledger row and a
  reconciliation check, exactly like the Phase 10–11 referral commissions. It flows through the
  existing payout path (`/payouts`, `min_payout`, the channel fees) with no change to that service.

The feature is **off by default** at the global master and the per-product master; existing products
also start with all-zero rates. Deploying the code changes nothing until an operator configures a
product and turns the stream on.

### 1.2 The rules in one page

```
ON ORDER → paid   (e-wallet: inside checkout; manual: admin verifies the proof)
│
├─ per order line, per product:
│    base      = (product's bonus base: P.V. or price) × quantity      snapshot at placement
│    rates[L]  = product_unilevel_levels[L] for L = 1…10               (reference 5/5/5/3/3/2/2/2/1/1)
│
│    for level L = 1…N, N = min(10, unilevel_max_levels):
│        L1 = the buyer's direct sponsor, L2 = their sponsor, … L10
│        product master off / global master off → no row
│        rate 0                  → no row, level still consumed
│        upline not Active       → skipped row, no money, no compression
│        upline not usable       → skipped row, no money
│        otherwise               → credited row, withdrawable wallet credit
│
└─ stamp shop_orders.unilevel_processed_at and .unilevel_total   (idempotency anchor)

ACTIVE (for month M)  ⇔  personal purchases in month M−1 ≥ unilevel_active_threshold
                         (snapshotted by the month-close job; no row = Active)
```

**The reference ladder pays 29% in total** across levels 1–10 (5/5/5/3/3/2/2/2/1/1). The reference
simulator's extra 10% level-0 buyer rebate is deliberately **not implemented** (D-09); the buyer is
not a recipient.

### 1.3 What is deliberately not built

- **No level-0 buyer rebate.** The buyer earns nothing on their own purchase; the sponsor chain is
  paid. This is the single biggest difference from the reference simulator (D-09). It is stated again
  in §12.1, §12.6 and Appendix A.
- **No direct/indirect split.** There is no `direct_unilevel` / `indirect_unilevel` type, no separate
  direct field, no read-only level 1, no per-leg switches and no "leg" column (D-17). This is the
  operator's explicit simplification.
- **No retroactive payouts.** Orders paid before the feature is switched on are never awarded
  (D-16; see 17C). There is no backfill command in v1.
- **No rank requirement, no Harvest requirement, no team-size requirement.** The reference model has
  none for this stream (reference §7.8), and the app has no rank ladder for repeat purchases.
- **No compression, no carry-over, no monthly payout step.** The money is paid at the payment event;
  the month close only decides who is *Active*.
- **No partial refunds.** Order cancellation after payment voids the awards and makes a best-effort
  wallet reversal (§14.9); it never edits history.
- **No tax withholding and no cost-of-goods model.** Awards are gross, like the existing referral
  commissions; the company's own accounting is out of scope.
- **No new routes.** Everything renders on existing pages through query parameters, so `routes.php`,
  `routes_inventory.php` and `ParityTest` stay untouched (D-14).

### 1.4 Delivery: seven gated phases

The feature is one coherent stream of money, but it is **not** delivered as one drop. It is split into
seven phases, each ending in a gate that must be passed — automated suite plus a manual script — before
the next phase starts. The split is drawn along the lines where a phase can be **proven on its own**.

| Phase | One-line purpose | What the gate proves | Adds money? | Adds UI? |
|---|---|---|---|:--:|
| **17A** | Add the four tables, the four altered tables and the six settings; teach install, migration and reset about them | A migrated database equals a fresh install, and nothing in the app reads the new objects yet | no | no |
| **17B** | Let an operator configure a product's P.V., base, switch and 1–10 ladder; add the five visible settings | Configuration saves, validates, audits and renders — and a paid order still pays nothing | no | admin |
| **17C** | Pay the ladder on a paid order, reverse it on cancellation, reconcile it | Every money rule in the spec: awards, wallet credits, idempotency, no compression, the Active gate, reversal, reconciliation | **yes** | none |
| **17D** | Show the money to administrators: repeat ledger tab, order panel, report cards, CSV | Finance can audit every award from the UI | no | admin |
| **17E** | Show the offer and the outcome to members: product panel, checkout preview, order card, earnings tab, dashboard | The copy rules hold; the buyer never sees a rebate | no | member |
| **17F** | Run the monthly close from cron, URL and Windows | The two-clock rule operates unattended, idempotently, with logs and a doctor | no | none |
| **17G** | Documentation, QA pack, phase badge, acceptance run, sign-off | The release is signed and everything above is reproducible | no | no |

**Why this split:**

- **The database goes first** because every later phase reads it, and because a schema change is the
  only part of this plan that is hard to reverse. Proving install/reset parity in isolation (§5) means
  no later bug can be blamed on the schema.
- **Configuration second**, because it is the only phase that a non-developer can verify by eye, and
  because an inert ladder is a safe thing to leave in production for a week.
- **Money third**, as one indivisible phase. Splitting the engine (for example "credit only, no
  reversal") would leave money in a state that is *wrong* rather than merely *incomplete*; a phase may
  be small but it may not be incorrect.
- **Admin before member**, because the ledger is the tool the operator uses to explain and audit the
  money; the member pages are marketing of an already-proven stream.
- **Member pages and the close job are siblings** (both depend only on 17C), so they can be built in
  either order or in parallel.
- **Release last** because `APP_PHASE`, the QA pack and the sign-off describe the finished feature —
  they are meaningless before the feature is on.

### 1.5 Scope at a glance

| Area | Deliverable | Phase |
|---|---|---|
| Database | 4 new tables, 4 altered tables, 6 settings, migration `020_unilevel_repeat_purchase.sql` | 17A |
| Configuration | `ProductUnilevel` model, product form ladder + P.V. + base, list badge/filter, settings card, JS preview | 17B |
| Money engine | `UnilevelAward`, `UnilevelCycle`, `UnilevelService`, two payment-path hooks, best-effort reversal, reconciliation | 17C |
| Admin | `Repeat-purchase` ledger tab, order card, four report cards, CSV, audits | 17D |
| Member | Product-page ladder panel, checkout preview, order-page statement, earnings tab, dashboard tile | 17E |
| Operations | `bin/unilevel_month_close.php` (+ web entry + `.cmd`), reconcile section, cron docs | 17F |
| Quality | ~125 new cases across the seven phases (install/engine/config/view/cron), 1 QA pack with 57 checks, docs | 17A–17G |

---

## 2. The phase protocol

### 2.1 What "self-contained" means in this document

"Self-contained" is a property of **delivery**, not of the schema. The phases share one data model
and one specification on purpose — duplicating them seven times would guarantee drift. A phase is
self-contained when all five of the following hold:

1. **It ships alone.** Merging only this phase leaves production correct, the suite green and every
   existing page unchanged in behaviour (except where the phase is explicitly a UI phase).
2. **It tests alone.** Its gate can be executed end-to-end on a database that contains only the
   phases up to and including itself. No later file, route or table is needed to observe the result.
3. **It explains alone.** Its section below contains everything needed to implement it: scope, files,
   rules, test cases, manual script, exit gate, rollback and hand-off. A developer who has never read
   this plan can start from §2, §12 and one phase section.
4. **It survives abandonment.** If every later phase were cancelled, this phase is still correct and
   still worth having. Nothing in it depends on a promise made by a phase that does not exist yet.
5. **It rolls back alone.** Reverting phase N leaves phases 1…N−1 intact and working.

The named anti-patterns this forbids:

- **The forward stub.** A file, column or switch that exists only so a later phase can fill it in.
  (A `TODO` in a comment is fine; a half-wired hook is not.)
- **The invisible phase.** A phase whose gate is "the code compiles". Every phase here has an
  observation a tester can make: a page, a count, a log line or a wallet balance.
- **The invalid phase.** A phase that leaves the stream in a state that is *wrong* while it waits for
  the next phase. Money is either fully implemented (17C) or not implemented at all (17A/17B).
- **The shared contract.** A value one phase writes and a later phase changes (for example: shipping
  with a 20% ladder in 17C and correcting it to 29% in 17E). Rates, ref types, column meanings and
  setting names are frozen at the phase that introduces them.

### 2.2 Phase map

| Phase | Name | Builds on | Old task IDs | Effort | Cumulative suite |
|---|---|---|---|---:|---|
| **17A** | Foundation: schema, vocabulary, settings storage, install parity | — | 17.1 | 1.5 d | 816 → ≥ 826 |
| **17B** | Configuration: ladder model, product form, settings card | 17A | 17.2 (ladder), 17.4 | 2.0 d | ≥ 846 |
| **17C** | Money engine: ledger, engine, hooks, reversal, reconciliation | 17A, 17B | 17.2 (ledgers), 17.3 | 4.0 d | ≥ 900 |
| **17D** | Admin visibility: ledger tab, order panel, reports | 17C | 17.5 | 1.5 d | ≥ 910 |
| **17E** | Member visibility: product, checkout, order, earnings, dashboard | 17C | 17.6 | 1.5 d | ≥ 921 |
| **17F** | Operations: the monthly close job | 17C | 17.7 | 1.0 d | ≥ 930 |
| **17G** | Release: docs, QA pack, phase badge, acceptance | all | 17.8 (remnant), 17.9 | 1.5 d | 930 |

**Total: ~13 ideal days**, plus roughly one day of gate overhead spread across the seven phases
(the ungated draft estimated 11.5; the difference is seven verification passes and the small amounts
of integration work each split exposes). The suite column is a floor, not a target: it counts the
cases the phase's gate requires; the baseline is today's **816 / 816**.

**Dependency graph**

```
17A ──▶ 17B ──▶ 17C ──┬──▶ 17D ──┐
                      ├──▶ 17E ──┼──▶ 17G
                      └──▶ 17F ──┘
```

17D, 17E and 17F are **siblings**: each depends only on 17C, none reads another, and they may be
delivered in any order or concurrently by different developers. The critical path is
17A → 17B → 17C → (17D or 17E or 17F) → 17G ≈ **10.5 ideal days** of sequential work.

**Branches and tags.** One branch, `feature/unilevel`, cut from `feature/shop`. One tag per gate:
`p17a-foundation`, `p17b-configuration`, `p17c-money`, `p17d-admin`, `p17e-member`, `p17f-month-close`,
`p17g-release`. A phase starts from the previous tag and never from an uncommitted tree.

### 2.3 The format of every phase section

Each phase in Part II answers the same questions in the same order:

| Block | What it must contain |
|---|---|
| **Goal** | one sentence: what is true after this phase that was not true before |
| **Builds on** | its only dependency (or "Nothing"), and the tag it starts from |
| **Ships alone because** | the argument for clause 4 of §2.1 — why the phase is correct even if every later phase is cancelled |
| **Effort, suite** | ideal days; the suite floor |
| **Reference** | the exact subsections of Part III this phase implements |
| **Deliverables** | `Database`, `Code`, `Docs`, and an explicit **Not yet** list |
| **Rules** | the normative constraints a reviewer checks the diff against |
| **Test plan** | `P17X-Tn` cases with exact expectations |
| **Manual gate** | numbered browser/CLI steps and the `QA17X-nn` identifiers in the QA pack |
| **Exit gate** | the checklist that closes the phase |
| **Rollback** | how to undo this phase alone |
| **Hand-off** | what now exists, which the next phase is allowed to assume |
| **Risks** | what can go wrong *in this phase* |

### 2.4 Definition of done — the universal gate

Every phase's exit gate contains these ten items, and its own phase-specific list. Copy the block into
`docs/phase-log.md` when the phase closes, and fill Appendix G.

```
[ ] Schema state matches the plan: MigrationTest green (17A applies migration 020; later phases change no schema)
[ ] `php bin/build_install_sql.php` run and `--check` exits 0
[ ] `php tests/run.php` — full suite green, exit 0, case count at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0 (money untouched or money correct)
[ ] `powershell -File tests/smoke.ps1` — all checks pass, exit 0
[ ] Every P17X-Tn case for this phase exists, is named for the phase, and passes
[ ] The manual script for this phase is executed by someone who did not write it
[ ] No new PHP warning, notice or deprecation in `storage/logs/` after the manual pass
[ ] The QA pack section for this phase is filled, with evidence under `docs/qa/evidence/phase-17/`
[ ] `docs/phase-log.md` records the phase, tag, counts and deviations; Appendix G is filled
```

### 2.5 What a phase may never do

- **Never leave the suite red.** A phase that cannot get to green is not finished; it is not "mostly
  finished with the tests to follow". The suite is the gate.
- **Never edit a later phase's files.** If 17C needs a change in a view, that view belongs to 17D or
  17E and the change waits. The only exceptions are the shared files each phase is assigned in its
  manifest (for example `DatabaseResetService` in 17A and again in 17C, for different reasons).
- **Never invent a contract.** If an implementation must diverge from §12–§14, the plan is amended
  first (a new decision or a corrected table), then the code. A divergence discovered mid-phase is
  recorded as a deviation in the phase log.
- **Never pay money in a configuration phase** and never configure money in a payment phase. 17C
  assumes its ladders already exist; it does not ship a hidden admin form "to make testing easier".
- **Never add a route.** D-14 is absolute for every phase: query parameters on existing pages only.

### 2.6 Before you begin (once, before 17A)

1. Cut `feature/unilevel` from `feature/shop`; tag `p17-start`.
2. Back up the database (`mysqldump`) and the working tree. The project's standing rule: a backup
   outside the repository before any migration.
3. Record the baseline: `php tests\run.php` → **816 / 816**; `tests\smoke.ps1` → **195 / 195**;
   `php bin\build_install_sql.php --check` → exit 0. If the baseline is not green, stop — a gate
   measured from a red baseline proves nothing.
4. Read §12 and §14 once, in full. They are the contract every phase is written against. Then read
   only the phase you are building; the other phases are not your scope.

---

## 3. Current state and the gap

### 3.1 The package referral ladder — the starting point, not the shape

Packages already have the per-level money machinery this plan reuses:

| Layer | File | What it does |
|---|---|---|
| Admin form | [`system/app/views/admin/packages/form.php`](../system/app/views/admin/packages/form.php#L135-L192) | **Referral ladder** card: level 1 read-only (the package's `direct_referral_bonus`), levels 2–10 editable peso amounts |
| Validation | [`Package::validateLevels()`](../system/app/Models/Package.php#L294-L308) | `nullable\|numeric\|gte:0\|lte:1000000\|decimal:2` per level |
| Persistence | [`Package::save()`](../system/app/Models/Package.php#L402-L500) | writes the package row and rewrites `package_levels` in one transaction |
| Storefront | [`shop/packages/_cards.php`](../system/app/views/shop/packages/_cards.php#L63-L104) | "Direct referral (level 1)" tile, "Levels 2–10" tile, "N of 10 levels pay" badge |
| Engine | [`CommissionService::onActivation()`](../system/app/Services/CommissionService.php#L107-L282) | walks the sponsor chain, prices each level from the package, credits the wallet |
| Ledger | [`Commission`](../system/app/Models/Commission.php#L36-L57) | one row per award, `type = direct_referral \| indirect_referral`, level 1–10 |

**What this plan copies:** the admin card layout, the child-table persistence, the sponsor-chain walk,
the wallet credit, the ledger filters and the CSV export.

**What this plan deliberately does not copy:** the package's **two-leg split**. The package stores
level 1 on the package row and levels 2–10 in `package_levels`, and types the awards
`direct_referral` / `indirect_referral`. This plan uses **one** `product_unilevel_levels` table with
`level = 1…10` and no award type — every level is just a level (D-17), which keeps the product form and
the ledger simpler.

**The one unit change:** the package stores peso amounts; this plan stores **percentages**, because the
reference stream is percentage-based and margin scales automatically with price/P.V.

### 3.2 The product catalogue today

| Fact | Evidence |
|---|---|
| `products` has an unused `pv` column | [`schema.sql`](../system/database/schema.sql#L160-L179) — comment: *"reserved for residual PV (V3.0 later pillars) — never read or written by the current build"* |
| The admin product form has **no** P.V. field and **no** bonus section | [`admin/products/form.php`](../system/app/views/admin/products/form.php#L45-L260): *Details*, *Pricing & stock*, *Image* only |
| `Product::save()` never writes `pv` | [`Product.php`](../system/app/Models/Product.php#L231-L286) |
| `Product::validate()` has no `pv` rule | [`Product.php`](../system/app/Models/Product.php#L181-L217) |
| The product page shows no earning information | [`shop/product/_card.php`](../system/app/views/shop/product/_card.php) |

So today a product is a price, a stock count and a description. There is nowhere to say *"this
product pays 5% to the first three sponsors, 3% to the next two, 2% to the next three and 1% to the
last two"*.

### 3.3 The checkout and payment path

| Event | Code | Order status afterwards |
|---|---|---|
| Member submits `/system/checkout` | [`CheckoutController::place()`](../system/app/Controllers/CheckoutController.php#L260-L348) → [`OrderService::placeFromCart()`](../system/app/Services/OrderService.php#L106-L333) | `pending`, or **`paid`** when the method is e-wallet (one transaction: debit, status, committed reservation, invoice) |
| Admin verifies a manual payment | [`PaymentService::verify()`](../system/app/Services/PaymentService.php#L370-L454) | **`paid`** (`paid_at = NOW()`), reservation `committed`, invoice issued |
| Admin cancels | [`PaymentService::cancelByAdmin()`](../system/app/Services/PaymentService.php#L544-L623) | `cancelled`, stock released, wallet refunded, invoice voided |

Two facts drive the whole design:

1. **A placed order is not money yet.** Manual methods (GCash, Maya, bank transfer) stay `pending`
   until an administrator verifies the proof. Paying sponsors at checkout would pay on orders that
   can expire or be cancelled (the reference model has no such gap because its "purchase" *is* the
   payment event).
2. **`paid` is reached from two places** — the e-wallet branch of `OrderService::placeFromCart()` and
   `PaymentService::verify()`. Both already run inside a transaction and both already call
   `InvoiceService::issueForPaidOrder()`. The bonus must hook both, or a method-dependent gap appears.

`shop_order_items` snapshots `unit_price` and `line_total` at placement
([`006_orders.sql`](../system/database/migrations/006_orders.sql#L115-L131)). It does **not** snapshot
P.V., which matters because the ladder base should not change if an administrator edits the product
between placement and verification (§12.3).

### 3.4 The commission ledger today

[`commissions`](../system/database/migrations/012_commissions.sql#L52-L76) is the referral ledger:

- `activation_id INT UNSIGNED NOT NULL` and `package_id INT UNSIGNED NOT NULL`, both foreign keys to
  activation/package rows;
- `UNIQUE KEY uq_commission_event (type, source_user_id, user_id, level)`.

That unique key cannot express repeat purchases: the same buyer can order the same product twice and
the same upline would be paid at the same level twice, which the key forbids. Reusing the table would
mean relaxing a money anchor that Phase 10–11 tests rely on
([`CommissionTest`](../system/tests/Cases/CommissionTest.php),
[`CommissionIndirectTest`](../system/tests/Cases/CommissionIndirectTest.php)), making
`activation_id`/`package_id` nullable, and rewriting every
[`Commission::select()`](../system/app/Models/Commission.php#L549-L570) join. **A separate ledger is
safer** (D-05).

The new ledger is deliberately simpler than `commissions`: it has **no `type` column** and no
direct/indirect split. The level (1–10) is the whole story.

### 3.5 There is no month clock

The reference model's Active flag is decided by a **month close** (reference §2.4). The application
has:

- no `month`/`period` table and no month-close job — the three existing cron jobs are shop expiry,
  order auto-complete and transfer-limit reset ([`docs/cron.md`](../docs/cron.md));
- `users.is_active` (may sign in) and `users.member_status` (membership), neither of which is a
  monthly purchase qualification;
- `shop_orders.paid_at`, which is the only reliable record of *when* money was received.

The monthly requirement therefore needs a new period table, a new close job and a documented fallback
for the window between midnight and the job run (§12.7).

### 3.6 The gap, in one table

| Need | Today | This plan | Phase |
|---|---|---|---|
| A place to configure per-product levels 1–10 | none | `product_unilevel_levels` + `products.unilevel_enabled` + admin ladder card | 17A / 17B |
| A trigger on a *paid* shop order | no commission hook for orders | hooks in `OrderService` (e-wallet) and `PaymentService::verify` (manual) | 17C |
| A ledger that can hold many awards per buyer/level | `commissions` unique key forbids it | new `unilevel_awards` table, unique on `(order_item_id, user_id, level)` | 17A / 17C |
| Wallet money + reconciliation | `WalletService` / `wallet_reconcile.php` | two new `ref_type` values and a `UnilevelService::reconcile()` block | 17A / 17C |
| A monthly "Active" rule | none | `unilevel_periods` + `unilevel_activity` + `bin/unilevel_month_close.php` | 17A / 17C / 17F |
| Member visibility | `/earnings` shows package referral only | a repeat-purchase tab + dashboard tile + product badge | 17E |
| Admin visibility | `/admin/commissions` shows package referral only | a repeat-purchase tab, filters, stats and CSV | 17D |

---

## 4. Decisions

Each decision is a contract for the implementation and the tests. Alternatives are listed so a later
review can reopen a decision without re-deriving it. The **Ships in** column is the phase that
introduces the rule; no later phase may change it.

| ID | Decision | Rationale | Rejected alternative | Ships in |
|---|---|---|---|---|
| **D-01** | The ladder is **per product**, with ten percentage rows, level 1–10 | The operator asked for per-product settings; products differ in margin | One global ladder on `/admin/settings` — simpler, but the requirement says per product | 17A/17B |
| **D-02** | Rates are **percentages** (0–100), not peso amounts | The reference model is percentage-based; margin scales automatically with price/P.V. | Fixed peso amounts per level (package style) — would need editing on every price change | 17B |
| **D-03** | The base is **P.V. when set, else price**, and is snapshotted on the line | Matches the reference's "P.V." wording; the column already exists; a price change between placement and verification cannot rewrite the payout | Always `line_total` — ignores P.V.; always `pv` — breaks products without a P.V. | 17B/17C |
| **D-04** | Award on the transition to **`paid`**, not at placement | Unpaid orders can expire; the wallet ledger must match real money | Award at placement — pays on unpaid orders, then needs clawbacks on expiry | 17C |
| **D-05** | A **separate `unilevel_awards` ledger**, not `commissions` | `commissions` is unique on `(type, source, recipient, level)`, which cannot hold two orders from the same buyer; its FKs require an activation/package | Extend `commissions` — breaks the Phase 10–11 money anchor and every join | 17A/17C |
| **D-06** | The **base** is frozen at placement; the **rates** are read at payment | The line snapshot already exists; rates are the company's current published terms | Freeze rates at placement too — needs a 10-rate snapshot per line; deferred | 17B/17C |
| **D-07** | **No compression**; a blocked level writes a `skipped` row | The reference model is explicit; `skipped` rows explain missing money | Compress to the next eligible upline — changes the plan's economics silently | 17C |
| **D-08** | Recipients need an active account and membership but **not a package** | A repeat-purchase bonus follows products, not the package bought at signup | Reuse `CommissionService::ineligibility()` — would block shopping-only members | 17C |
| **D-09** | **No level 0.** The buyer earns nothing on their own purchase; level 1 is the direct sponsor, levels 2–10 the uplines | Mirrors activation: the newly joined member earns no commission; the sponsor chain is paid. The reference simulator's 10% self-rebate is deliberately not copied | Keep a 10% level-0 buyer rebate — would make this a different stream and change the economics of every product | 17C/17E |
| **D-10** | A **monthly period snapshot** table plus a **close job**; unclosed periods fall back to a live aggregate | Mirrors the two-clock model, keeps a history, and cannot be wrong between midnight and the job run | Pure on-the-fly calculation — no snapshot and no "last month" history for the member page | 17C/17F |
| **D-11** | **Off by default** at the global master and the product master; existing products also start at 0% | Deploying code must not start paying money; a new product may pre-fill the reference rates but its master switch is off | Enabled by default — a migration would immediately pay the reference 29% | 17A/17B |
| **D-12** | The wallet ledger gets **`unilevel`** and **`unilevel_reversal`** ref types | One idempotent movement per award, consistent with `commission` | A separate ref type per level — pointless; the award's `level` already identifies it | 17A |
| **D-13** | Reversal is **best effort**: void the awards, debit what the wallet holds, record the shortfall | No negative balances are possible in this build; the shortfall is visible, not hidden | Refuse cancellation when a wallet is short — blocks an operational necessity | 17C |
| **D-14** | **No new routes**; new views are query parameters on `/admin/commissions`, `/earnings`, `/admin/products` and `/admin/settings` | `APP_PHASE`/`ParityTest` gating stays untouched; one page per concept | Separate pages/routes — more surface, more inventory entries, more tests | all |
| **D-15** | `APP_PHASE` becomes **17** | The build should report the phase that shipped the feature | Leave at 15 — the new UI would render with a stale phase badge | 17G |
| **D-16** | No retroactive awards for orders paid before the switch is on | The company never agreed to pay them; a backfill would be a large, risky payout | Backfill from `shop_orders` — out of scope, see §18 Q13 | 17C |
| **D-17** | **One ladder, levels 1–10.** No `direct_unilevel`/`indirect_unilevel` type, no separate direct field, no read-only level 1, no per-leg switches and no "leg" column | The operator's explicit simplification: level 1 is just the first level, level 2 the second, and so on; one table and one loop are easier to configure and audit | The package's two-leg shape (direct at level 1 + indirect at 2–10) — rejected: it adds a second field, a second switch and a type column for no business difference | 17B/17C |
| **D-18** | The **sum of levels 1–10** is capped at **100%** of the base | Percentages, unlike package peso amounts, can sum past the purchase; a 100% ceiling keeps the payout bounded | Only cap each level individually — allows a 200% ladder | 17B |

---

# Part II — The phases

## 5. Phase 17A — Foundation

**Goal.** A database that carries every object the feature will ever need, an install/reset/migration
path that knows about them, and a wallet vocabulary that can name the two new movement kinds — with
**no code path reading any of it**. After 17A the application behaves exactly as it did at `p17-start`.

**Builds on.** Nothing. This is the root of the graph.

**Ships alone because.** The new tables have no reader, the new columns have no writer, and the two
new `ref_type` values cannot appear in a ledger row because nothing constructs them. The only
observable change is two filter options in the wallet statement (see Rules 6) and a different number
in `install`'s verification output.

**Effort.** 1.5 ideal days. **Suite.** 816 → ≥ 826 cases, all green.

**Reference.** §13 in full (the model and the migration text), §12.3 (the base columns).

**Deliverables**

**Database**

- `system/database/migrations/020_unilevel_repeat_purchase.sql` — the migration of §13.4, verbatim:
  four tables (`product_unilevel_levels`, `unilevel_awards`, `unilevel_activity`, `unilevel_periods`),
  four altered tables (`products`, `shop_orders`, `shop_order_items`, `ewallet_ledger`), two new
  `shop_orders` indexes, and the six settings. Idempotent: every `ALTER` is guarded by an
  `information_schema` probe and a `PREPARE`/`EXECUTE` pair, every `CREATE TABLE` is
  `IF NOT EXISTS`, and the settings insert is `ON DUPLICATE KEY UPDATE key_name = key_name`.
- `system/database/schema.sql` — the fresh-install mirror of §13.6, block for block, so
  `MigrationTest`'s migrated-vs-fresh comparison stays green. The `pv` comment changes from
  *"reserved … never read or written"* to the bonus base.
- `system/database/seeds/001_settings.sql` — the six keys, in the same order and with the same
  defaults as the migration (`unilevel_ledger_since` uses `DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')`,
  like `commission_ledger_since`).
- `system/database/install/footer.sql` — the expected-counts comment: **31 tables, 54 settings**.
- `system/database/install.sql` — regenerated by `php bin/build_install_sql.php`; never hand-edited.

**Code**

- `system/app/Services/DatabaseInstaller.php` — `EXPECTED`: `tables_created` 27 → **31**,
  `settings_rows` 48 → **54**; the settings failure message becomes "51 keys plus the three
  `install_*` metadata rows"; `TRANSACTIONAL_TABLES` gains `unilevel_awards`, `unilevel_activity`,
  `unilevel_periods`, so a fresh install fails verification if any of them is non-empty. `DEMO_TABLES`
  is unchanged (the demo seed writes no bonus rows).
- `system/app/Services/DatabaseResetService.php` — `ALL_TABLES` gains the four names (27 → **31**);
  `ZERO_TABLES` gains the three transactional names; `AUTO_INCREMENT_TABLES` gains
  **`unilevel_awards` only** (§13.7 explains why the other two have no auto-increment column);
  `DELETE_GROUPS` gains a **Repeat-purchase bonus** group *before* the orders group (awards reference
  order items, so they must go first). `product_unilevel_levels` is catalogue, like `package_levels`:
  it is emptied by the catalogue group, not by the business reset. **The integrity map is not touched
  in this phase** — it names `UnilevelService`, which does not exist until 17C.
- `system/app/Services/WalletService.php` — `REF_TYPES` gains `unilevel` and `unilevel_reversal`.
  This *must* ship with the enum: [`WalletTest`](../system/tests/Cases/WalletTest.php) P5-T8 asserts
  that `REF_TYPES` and the live enum agree **in both directions**, so the column and the constant
  cannot land in different phases.
- `system/app/helpers.php` — `wallet_ref_label()` and `wallet_ref_icon()` gain the two values
  (*"Repeat-purchase bonus"*, *"Repeat-purchase reversal"*; `bx-gift` / `bx-undo`), because
  `partials/wallet_statement.php` renders one filter option per `REF_TYPES` entry.

**Docs**

- `docs/qa/phase-17-unilevel-repeat-purchase.md` — created with the header, the "what is already
  automated" block and **section A only** (checks `QA17A-01` … `QA17A-08`). Every later phase appends
  its own section; the file is never rewritten.
- `docs/qa/README.md` — one Phase 17 row in the contents table, marked *in progress (17A)*.
- `docs/qa/evidence/phase-17/README.md` — the evidence folder index.

**Not yet**

- No `ProductUnilevel`, no `UnilevelAward`, no `UnilevelCycle`, no `UnilevelService`, no controller,
  no view, no route, no job, no integrity-map entry. The tables are empty and unread.

**Rules**

1. **The migration is additive and idempotent.** Re-running it on a migrated database is a no-op:
   no error, no duplicate settings, no lost rows. `MigrationTest` proves it by running the file twice.
2. **`schema.sql` and the migration must be equivalent.** `MigrationTest` builds one database from the
   migrations and one from `schema.sql`, then compares table structures. A column that exists in one
   and not the other fails the phase.
3. **The seed and the migration must agree.** `SeedParityTest` fails unless seed 001 carries every
   settings key the schema/migration introduces. The six keys are added to both in this phase.
4. **Defaults are off.** `products.unilevel_enabled = 0`, `unilevel_enabled = '0'`. A fresh install
   and a migrated install both pay nothing.
5. **No backfill.** `unilevel_ledger_since` is set to the migration instant. Historical orders are not
   touched, and no `UPDATE … JOIN` runs over `shop_orders`.
6. **The one visible change is the wallet statement filter.** `partials/wallet_statement.php`
   enumerates `WalletService::REF_TYPES`, so the member and admin wallet filters gain *Repeat-purchase
   bonus* and *Repeat-purchase reversal* options in this phase. They cannot match a row until 17C.
   This is accepted, documented in the QA pack, and is the price of keeping the enum and the constant
   in one phase (Rule 7). If the team prefers zero visible change, the alternative is to move the
   enum, `REF_TYPES` and the labels to 17C — but then the column and the constant still ship together.
7. **Enum and constant move together.** See `WalletService::REF_TYPES` above. Never leave the database
   accepting a value PHP rejects, or vice versa.
8. **The reset keeps the ladder and empties the money.** After a business reset, `unilevel_awards`,
   `unilevel_activity` and `unilevel_periods` are empty; `product_unilevel_levels` and the six settings
   survive.
9. **No application file outside the manifest changes.** `git diff --stat` must touch only the files
   listed above. A change in a controller or a view means the phase has crept.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P17A-T1 | Apply 020 to a database at `p17-start` | 4 tables, 4 altered tables, 2 indexes, the extended enum and 6 settings exist; no error |
| P17A-T2 | Run 020 a second time | No error; object counts and settings identical to the first run |
| P17A-T3 | Migrated vs. fresh (`MigrationTest`) | Structures identical; the test's ordered migration list includes `020_unilevel_repeat_purchase.sql` |
| P17A-T4 | `SeedParityTest` | Seed 001 and the schema carry the same 54 keys, including the six new ones |
| P17A-T5 | `php bin/build_install_sql.php --check` | Exit 0, no output: the bundle matches its sources |
| P17A-T6 | Fresh install (`InstallTest`) | Verification reports **31** tables, **54** settings, 2 staff, 0 members, 7 products, 2 packages, 18 levels, 10 contents |
| P17A-T7 | Insert one `unilevel_awards` row, then run install verification | The `transactional` check fails with the table named — proving Rule 4's guard is wired |
| P17A-T8 | Business reset (`DbResetTest`) | `unilevel_awards`, `unilevel_activity`, `unilevel_periods` are 0; `product_unilevel_levels` and the six settings survive; `ALL_TABLES` is 31 names |
| P17A-T9 | `WalletService::REF_TYPES` vs. the live enum (`WalletTest` P5-T8) | Exactly equal in both directions, 10 values |
| P17A-T10 | `wallet_ref_label('unilevel')` / `('unilevel_reversal')` | "Repeat-purchase bonus" / "Repeat-purchase reversal"; icons resolve to a `bx-` class |
| P17A-T11 | Defaults on a migrated database | Every `products.unilevel_enabled = 0`; `unilevel_enabled = '0'`; `unilevel_max_levels = '10'`; `unilevel_active_threshold = '500'`; `unilevel_ledger_since` is a valid timestamp |
| P17A-T12 | Delete a product that has ladder rows | The rows cascade (13.2); no orphan, no FK error |
| P17A-T13 | The full suite | 816 + the phase's cases, all green, exit 0; `ParityTest` and `RouterTest` untouched and green |

**Manual gate — `QA17A-01` … `QA17A-08`**

Run on a **scratch** schema built from the previous tag, not on the development copy.

1. `QA17A-01` — `mysql < 020_unilevel_repeat_purchase.sql`, then `SHOW TABLES LIKE '%unilevel%'` →
   four tables. Re-run the file → no error.
2. `QA17A-02` — `SHOW COLUMNS FROM products` → `unilevel_enabled`, `unilevel_base`;
   `shop_order_items` → `unit_base`, `base_total`; `shop_orders` → `unilevel_processed_at`,
   `unilevel_total`; `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'` → the enum ends
   `'unilevel','unilevel_reversal'`.
3. `QA17A-03` — `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'unilevel%'` → 6.
4. `QA17A-04` — open `/admin/settings`: no repeat-purchase block appears (it is 17B's). Open
   `/admin/products/{id}/edit`: no ladder card, no P.V. field.
5. `QA17A-05` — place and pay an e-wallet order as a member with a sponsor. No award row appears; the
   order page and the sponsor's wallet are unchanged; `SELECT COUNT(*) FROM unilevel_awards` → 0.
6. `QA17A-06` — `/wallet` → the statement filter now lists *Repeat-purchase bonus* and
   *Repeat-purchase reversal*; selecting either returns no rows.
7. `QA17A-07` — rebuild the bundle and re-install on an empty schema: the verification pack prints
   `tables_created = 31`, `settings_rows = 54`; `php bin/wallet_reconcile.php` exits 0.
8. `QA17A-08` — run the development reset console once: the three transactional tables are empty,
   the six settings and any ladder rows survive, and the verification passes.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P17A-T1 … P17A-T13 pass
[ ] MigrationTest proves a second run is a no-op and that migrated == fresh
[ ] InstallTest / InstallSqlTest / SeedParityTest / DbResetTest / WalletTest green at 31 / 54 / 10
[ ] git diff --stat touches only the 17A manifest
[ ] Tag p17a-foundation; docs/phase-log.md entry; Appendix G row
```

**Do not start 17B until every box is ticked.**

**Rollback**

Drop the four tables and the six columns, restore `ewallet_ledger.ref_type` to its previous enum,
delete the six settings, and revert `REF_TYPES`/labels. No money exists to lose, so the only care
needed is restoring the enum *before* dropping the tables (a row cannot hold a value the enum no longer
allows — there are none, but do it in that order for consistency). `schema.sql`, the seed and
`install.sql` return to their previous content; `install.sql` is regenerated, never hand-repaired.

**Hand-off to 17B**

- A migrated database with every column, table and setting the feature needs.
- A fresh-install bundle that produces the same database, verified by `MigrationTest`.
- A reset engine that empties the money tables and preserves the ladder.
- A wallet vocabulary that can name `unilevel` and `unilevel_reversal`.
- **Nothing that reads any of it.** 17B may assume the tables exist and are empty.

**Risks**

| Risk | Mitigation |
|---|---|
| `schema.sql` drifts from the migration | `MigrationTest` compares structures; the gate runs it, not just the author |
| `install.sql` is hand-edited | Regenerated only; `--check` in the gate |
| The enum/`REF_TYPES` pair is split across phases | Both in 17A, with a test that compares them in both directions |
| The reset leaves bonus rows behind | `DbResetTest` asserts the three tables are zero after a business reset |

---

## 6. Phase 17B — Configuration

**Goal.** An administrator can set a product's P.V., choose the bonus base, switch the bonus on and
edit a **1–10 percentage ladder**; a second card on `/admin/settings` holds the five visible global
settings. **No money moves.**

**Builds on.** 17A (the tables, the columns, the settings).

**Ships alone because.** The ladder is inert without an engine. Every value this phase writes is read
by nothing until 17C. The phase is also the only one a non-developer can verify entirely by eye, which
makes it the right place to stop and look before any money code exists.

**Effort.** 2.0 ideal days. **Suite.** ≥ 846 cases.

**Reference.** §12.3–12.4, §12.9 (validation), §13.2 (the ladder table), §13.3 (the product columns),
§13.5 (the settings).

**Deliverables**

**Database.** None. 17A shipped every column this phase writes.

**Code**

- `system/app/Models/ProductUnilevel.php` (new) — the ladder:
  `levels()`, `levelsMap()`, `save()`, `validateLevels()`, `validate()`, `referencePreset()`,
  `forForm()`, `totalsMap()`, `baseFor()`. Zero-filled on read, exactly like
  [`Package::levels()`](../system/app/Models/Package.php#L149-L170). Delete-then-insert on save, inside
  the caller's transaction.
- `system/app/Models/Product.php` — `validate()` gains `pv` (`nullable|numeric|gte:0|lte:1000000|decimal:2`)
  and the cross-field rule "`unilevel_base = 'pv'` requires `pv > 0`"; `save()` gains an optional third
  argument `?array $unilevel = null` and writes the product row, the two new columns and the ten ladder
  rows in **one transaction**; a new `rowsFor(array $ids)` reads
  `id, name, price, pv, unilevel_enabled, unilevel_base` in one query; `referencesMap()` gains an
  `awards` count (it queries `unilevel_awards`, which exists and is empty).
- `system/app/Controllers/AdminProductController.php` — `create()` pre-fills the reference ladder with
  the switch off; `edit()` loads the stored ladder; `save()` reads `pv`, `unilevel_enabled`,
  `unilevel_base`, `unilevel_levels[1..10]`, merges `Product::validate()` with
  `ProductUnilevel::validate()`, persists atomically and audits `product.unilevel.update` (before/after)
  only when something changed.
- `system/app/Controllers/AdminSettingsController.php` — `REPEAT_FIELDS` and `REPEAT_TOGGLES` constants
  in the same `{key,label,rule,suffix,hint}` / `{label,hint}` shapes as the existing ones, merged into
  `sectionKeys()`, `sectionToggles()`, `fieldRules()` and `fieldLabels()`; `settingsContext()` passes
  `repeat_fields` and `repeat_toggles` so the view can render its own grid. Section stays `commissions`.
- Views: `admin/products/_unilevel.php` (new partial), `admin/products/form.php` (include the card; add
  the P.V. field to *Pricing & stock*), `admin/products/_rows.php` (badge), `admin/products/index.php`
  (the `?unilevel=on|off` filter), `admin/settings/_commissions.php` (the sub-block).
- `system/public/assets/js/app-product-unilevel.js` (new) — the live per-unit amount and total-percent
  preview, plus the **Load reference ladder** and **Clear** buttons. Progressive enhancement only.

**Docs.** `docs/qa/phase-17-unilevel-repeat-purchase.md` gains section B (`QA17B-01` … `QA17B-09`).
`system/README.md` gains the two settings groups and the product-form section. `docs/deploy.md` gains
the five visible settings keys.

**Not yet.** No `UnilevelService`, no `UnilevelAward`, no hook, no award row, no member-facing page, no
month close. Paying an order after this phase still moves no bonus money.

**Rules**

1. **One ladder, ten equal rows.** No read-only level 1, no separate direct field, no
   `direct_unilevel`/`indirect_unilevel` switch, no "leg" column (D-17). Level 1 is edited and rendered
   exactly like level 10.
2. **Zero means "no row".** Blank and `0` are equivalent; the model zero-fills. Ten rows are written on
   save (or the ladder is cleared) — never nine.
3. **The sum is capped at 100%.** `ProductUnilevel::validate()` returns a `sum` error when the ten
   values total more than 100.000. It is a field error, never a silent clamp (D-18).
4. **A level is 0–100 with three decimals.** `nullable|numeric|gte:0|lte:100|decimal:3`.
   The inputs carry `min="0" max="100" step="0.001"`; the server does not trust them.
5. **Enabling with no rate is refused.** `unilevel_enabled = 1` and a zero sum → "Turn the switch off
   or give at least one level a rate above 0."
6. **`pv` is money.** `nullable|numeric|gte:0|lte:1000000|decimal:2`; `0` means "not set", and
   `unilevel_base = 'pv'` with `pv = 0` is a field error on `unilevel_base`.
7. **The save is atomic.** A refused ladder leaves the product row untouched and vice versa
   (mirroring [`Package::save()`](../system/app/Models/Package.php#L402-L500)).
8. **Nothing is enabled by default.** A new product is pre-filled with 5/5/5/3/3/2/2/2/1/1 but its
   switch is **off** (D-11). A migrated product reads 0% in all ten rows.
9. **The tier badge never lies.** `Repeat bonus 29%` when the switch is on and the total is above zero;
   `Repeat bonus 0%` when it is on with a zero ladder (a configuration error the operator can see);
   nothing when it is off.
10. **The card is one card, in the same place as the package ladder** — after *Details* in the left
    column. The P.V. field lives in *Pricing & stock*, not in the bonus card.
11. **No JavaScript-only validation.** With JS off the form still saves and still validates; only the
    live preview is missing.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P17B-T1 | Create a product with `pv = 250`, base `auto`, the reference ladder, switch off | Row saved; ten ladder rows; `unilevel_enabled = 0`; the form re-reads 5/5/5/3/3/2/2/2/1/1 |
| P17B-T2 | Save a ladder with three blanks | Ten rows written; blanks stored as 0.000; the form shows blanks for 0 |
| P17B-T3 | Ladder summing to 120% | `unilevel_levels.sum` error; the product row is unchanged (transaction) |
| P17B-T4 | Level 1 = 100% and level 2 = 1% | `sum` error (101%) |
| P17B-T5 | Level = 100.001, or four decimals | Field error on that level |
| P17B-T6 | Switch on with all ten at 0 | Error on `unilevel_enabled` |
| P17B-T7 | Base `pv` with `pv = 0` | Error on `unilevel_base`; the product is not saved |
| P17B-T8 | Base `price` with `pv = 0` | Accepted (the base is the price) |
| P17B-T9 | Edit a ladder on an existing product | `product.unilevel.update` audit row with before/after; no other audit action |
| P17B-T10 | `baseFor()` for auto/price/pv | `auto` → pv when > 0 else price; `price` → price; `pv` → pv; × quantity, 2 decimals |
| P17B-T11 | `referencePreset()` | `[1=>5.0, 5.0, 5.0, 3.0, 3.0, 2.0, 2.0, 2.0, 1.0, 1.0]`, sum 29.0 |
| P17B-T12 | `levelsMap()` / `totalsMap()` for 5 products | One query each (no N+1); missing products read as zero ladders |
| P17B-T13 | Deleting a product with ladder rows and no awards | Deleted; the ten rows cascade |
| P17B-T14 | Product form renders | Ten number inputs named `unilevel_levels[1..10]`, the switch, the base select, the P.V. field; **no** direct/indirect control anywhere in the HTML |
| P17B-T15 | Product form after a validation error | The posted values and the error messages survive; the JS preview does not run |
| P17B-T16 | Product list badge | `Repeat bonus 29%` on, `Repeat bonus 0%` on-with-zero, nothing when off |
| P17B-T17 | Product list `?unilevel=on` / `off` | Correct filtered rows; the filter survives pagination |
| P17B-T18 | Settings card | Three toggles and two numbers inside the **Commissions** card; the depth field is 1–10; no sixth key rendered |
| P17B-T19 | Settings save round-trip | Values persist; `settings.update` audit row; an out-of-range depth (0 or 11) is refused |
| P17B-T20 | Place and pay an e-wallet order after configuring a product | No `unilevel_awards` row; no wallet movement of `ref_type = 'unilevel'`; the order page is unchanged (the phase has no hook) |
| P17B-T21 | The full suite | Green; `InstallTest` still 31/54 (this phase adds no schema) |

**Manual gate — `QA17B-01` … `QA17B-09`**

1. `QA17B-01` — open a product's edit page: the *Unilevel repeat-purchase bonus* card reads 0.000 in
   all ten rows, the switch is off, the base is *Auto*, and the P.V. field is empty or `0.00`.
2. `QA17B-02` — set P.V. ₱250, click **Load reference ladder**, enable the switch, save. Re-open: the
   ten values persist, the switch is on, and the card's total reads *29.000%*.
3. `QA17B-03` — change level 4 to 20% so the total exceeds 100% and save: the form comes back with the
   sum error and the values as typed; the product still shows the old 3%.
4. `QA17B-04` — switch the product on with all ten rows cleared: the error asks for a rate or the
   switch off.
5. `QA17B-05` — set the base to *P.V.* with the P.V. at 0: refused on the base field. Set the base back
   to *Auto*: accepted.
6. `QA17B-06` — `/admin/products`: the configured product shows `Repeat bonus 29%`; the filter
   *Repeat bonus — on* returns it and *off* does not.
7. `QA17B-07` — `/admin/settings` → **Commissions**: *Upline depth* (10), *Monthly personal-purchase
   minimum* (₱500.00), and the three toggles render. Save with depth `11` → refused. Save `10` →
   persisted, and the audit desk shows the change.
8. `QA17B-08` — turn the product's JS off (or use a browser with JS disabled) and save the form: it
   still saves; only the per-unit preview is missing.
9. `QA17B-09` — as a member, place and pay an e-wallet order for the configured product. Nothing on the
   order page, the sponsor's wallet or the admin ledger changes; `unilevel_awards` is still empty.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P17B-T1 … P17B-T21 pass
[ ] The card, the badge and the settings block render on desktop and mobile widths
[ ] No direct/indirect control exists anywhere in the product form HTML
[ ] Paying an order still writes zero award rows (P17B-T20)
[ ] Tag p17b-configuration; phase log; Appendix G row
```

**Do not start 17C until every box is ticked.**

**Rollback**

Turn every product switch off and `unilevel_enabled = 0` (belt and braces), then revert the code. The
ladder rows and the settings may stay: they are inert and a later attempt can reuse them. No money
exists to reverse.

**Hand-off to 17C**

- `ProductUnilevel::levelsMap()` and `baseFor()` are the only way the engine may read a ladder or a base.
- `Product::rowsFor()` returns the engine's product columns in one query.
- `unilevel_enabled`, `unilevel_base`, `unilevel_max_levels`, `unilevel_requires_active_upline`,
  `unilevel_active_threshold` and `unilevel_reversal_enabled` exist, are validated, and are editable
  by an operator.
- Ladders are inert: 17C may assume a configured product, and must work when none is configured.

**Risks**

| Risk | Mitigation |
|---|---|
| The ladder card drifts into a direct/indirect shape | P17B-T14 asserts no such control in the HTML; D-17 is restated in the partial's header comment |
| A refused save half-writes the product | P17B-T3/T7 assert the product row is unchanged; one transaction |
| The badge total disagrees with the saved rows | Both read `ProductUnilevel::totalsMap()`; no second summation |
| An operator enables the stream before 17C and expects money | The product-level switch does nothing yet; the QA pack says so; the global switch has no effect until 17C |

---

## 7. Phase 17C — Money engine

**Goal.** A paid shop order pays the product's ladder to the buyer's sponsor chain, once, with
withdrawable wallet credits, best-effort reversal on cancellation, and a reconciliation that proves it.
This is the only phase that moves money.

**Builds on.** 17A (schema), 17B (configurable ladders).

**Ships alone because.** Money is correct and complete at `p17c-money`: every award is visible in the
existing wallet statements (member `/wallet` and admin `/admin/wallets`) with the ref type this phase
credits, and `bin/wallet_reconcile.php` proves the ledger balances. No page is *required* for the phase
to be correct — the admin and member pages of 17D/17E only **describe** what this phase already does.

**Effort.** 4.0 ideal days — the largest phase by design. **Suite.** ≥ 900 cases.

**Reference.** §12.2–12.11, §13.7 (invariants), §14 in full.

**Deliverables**

**Database.** None. (Awards and snapshots use the columns shipped in 17A.)

**Code**

- `system/app/Models/UnilevelAward.php` (new) — `create()`, `linkLedger()`, `void()`, `forOrder()`,
  `forItem()`, `normaliseFilters()`, the paginated select, totals, per-level breakdown, and the CSV
  rows. No `type` column: the level is the whole story (D-17).
- `system/app/Models/UnilevelCycle.php` (new) — `periodOf()`, `previous()`, `bounds()`, `isClosed()`,
  `isActive()`, `activeMap()`, `personalPurchases()`, `close()`, `pendingPeriods()`, `lastClosed()`,
  `periods()`, `activityFor()`. The `close()` method is complete here; 17F only adds the operational
  entry points.
- `system/app/Services/UnilevelService.php` (new) — `onOrderPaid()`, `quoteLine()`, `quoteCart()`,
  `reverseForOrder()`, `totalsFor()`, `reconcile()`, `enabled()`.
- `system/app/Services/UnilevelException.php` (new) — `notFound()`, `notPaid()`, `state()`, same shape
  as `OrderException`.
- `system/app/Services/OrderService.php` — `lockProducts()` selects `pv, unilevel_enabled,
  unilevel_base`; `insertItems()` writes `unit_base` and `base_total` through
  `ProductUnilevel::baseFor()`; the e-wallet branch calls `UnilevelService::onOrderPaid()` after
  `issueForPaidOrder()`.
- `system/app/Services/PaymentService.php` — `verify()` calls `onOrderPaid()` after
  `issueForPaidOrder()`; `cancelByAdmin()` calls `reverseForOrder()` after the wallet refund and before
  the status update.
- `system/app/Services/DatabaseResetService.php` — the integrity map gains
  `'unilevel' => ['label' => 'Repeat-purchase reconciliation', 'service' => 'UnilevelService']`
  (deferred from 17A on purpose: the class now exists).
- `system/bin/wallet_reconcile.php` — a **Repeat-purchase** section, included in the exit code.
- No controller and no view changes. `CommissionService::sponsorChain()` is already public and is
  reused rather than reimplemented.

**Docs.** QA pack section C (`QA17C-01` … `QA17C-10`). `docs/runbook.md` gains the reconcile section.
`system/README.md` gains the engine and the two ref types.

**Not yet.** No repeat ledger page, no order-page card, no earnings tab, no product-page panel, no
month-close job, no `APP_PHASE` change.

**Rules**

1. **One hook, two paths.** `onOrderPaid()` is called from the e-wallet branch of
   `OrderService::placeFromCart()` and from `PaymentService::verify()`. `paid` reached any other way
   (a data repair, a fixture) writes no award.
2. **Runs in the caller's transaction.** The engine never opens its own. Awards, wallet credits, the
   snapshot and the order stamp commit or roll back together (§14.5).
3. **Idempotent at four layers** (§14.4): the order stamp, the per-item pre-check, the unique key and
   the wallet's own idempotency. A second call returns `replayed` and moves nothing.
4. **Stamp even when nothing pays.** Global switch off, product switch off, no items or no chain → the
   order is stamped with `unilevel_total = 0`, so "decided, nothing due" is auditable and a later
   re-verification cannot pay it.
5. **The buyer is never a recipient** (D-09). No row may have `user_id = buyer_id`; the engine does not
   even look up the buyer's eligibility.
6. **One chain per order, one ladder query per product.** The walk uses
   `CommissionService::sponsorChain()` (cycle guard, company root, ten-level cap). Active/eligibility is
   resolved once for the whole chain (§14.7).
7. **Wallet locks in ascending recipient order.** The drafts are sorted by
   `(user_id, order_item_id, level)` before any credit, so two concurrent orders that share uplines
   cannot deadlock (§14.6).
8. **No compression.** A skipped level keeps its number; the next upline pays at its own rate. A zero
   rate writes no row and consumes the level (D-07).
9. **Rates at payment, base at placement.** `rate_pct` is snapshotted on the award; the base comes from
   the line snapshot, or from the product when the line predates 17A (`base_source = 'product'`).
10. **Rounding per level, per line** in integer centavos: `round(base × percent / 100, 2)`. The sum is
    never re-rounded (§12.8, P17C-T5).
11. **A zero amount is never written.** A zero rate or a zero base produces no row, not a `0.00` row.
12. **A failure propagates.** If a wallet credit throws, the checkout rolls back or the verification
    fails; the engine does not swallow it and does not write a `skipped` row to hide it.
13. **Reversal is best effort.** Void the award, debit only the withdrawable balance, record the
    shortfall. Never a negative balance, never a blocked cancellation (§14.9).
14. **No backfill** (D-16). The engine only runs on the transition; `unilevel_ledger_since` is the
    reconciliation baseline, not a hint to look backwards.

**Test plan**

Fixture tree (per §16.2): id 1 is the primary admin (the company account, outside the chain);
`boss(2)`, `a(3)`, `b(4)`, `c(5)`, `buyer(6)`; `buyer → c → b → a → boss → NULL`, four hops. Product
`P1` price ₱300 / P.V. ₱250 / base `auto` / 5·5·5·3·3·2·2·2·1·1; product `P2` price ₱500 / P.V. 0 /
level 1 = 10%, levels 2–10 = 0%. Every expected value is exact.

**Awards**

| ID | Test | Expected |
|---|---|---|
| P17C-T1 | E-wallet order, `P1 × 2`, four-deep chain | L1 c ₱25; L2 b ₱25; L3 a ₱25; L4 boss ₱15; total ₱90; 4 credited, 0 skipped; one `unilevel` ledger row each |
| P17C-T2 | Manual (GCash) order verified by an admin | Identical awards to T1, created once |
| P17C-T3 | Ten-deep chain, `P1 × 2` | 10 rows; ₱145 (29% of ₱500) |
| P17C-T4 | Base ₱555, ten-deep | 27.75 × 3 + 16.65 × 2 + 11.10 × 3 + 5.55 × 2 = **₱160.95** |
| P17C-T5 | Base ₱555.55, ten-deep | Per-level rounding → **₱161.13** (the nominal 29% is ₱161.11); pinned |
| P17C-T6 | `unilevel_base = 'price'`, `P1 × 2`, four-deep | Base ₱600; ₱30 + ₱30 + ₱30 + ₱18 = **₱108** |
| P17C-T7 | Legacy line (`base_total` NULL) | Base from the product; `base_source = 'product'`; still ₱90 |
| P17C-T8 | Buyer's sponsor is the company account | L1 `skipped` "company account is not paid"; 0 money; the order is stamped 0 |
| P17C-T9 | Buyer's `sponsor_id` is NULL | No chain, no rows, stamped 0 |
| P17C-T10 | `unilevel_max_levels = 3` | ₱25 × 3 = ₱75, 3 rows |
| P17C-T11 | `unilevel_max_levels = 2` | ₱50, 2 rows |
| P17C-T12 | `unilevel_max_levels = 1` | ₱25, 1 row |
| P17C-T13 | Eleven-deep chain | Levels 1–10 only; the eleventh sponsor gets no row; 10 rows, ₱145 |
| P17C-T14 | Two lines (`P1 × 2`, `P2 × 1`) | ₱90 + ₱50 = ₱140; c gets ₱25 + ₱50 = ₱75; 5 rows |
| P17C-T15 | Three lines, ten-deep chain | 30 rows; query count proves no N+1 |
| P17C-T16 | Any award row | No row has `user_id = buyer_id`; the buyer's wallet is unchanged |

**Eligibility and switches**

| ID | Test | Expected |
|---|---|---|
| P17C-T17 | L2 inactive, four-deep, base ₱500 | L1 ₱25, L2 `skipped` ₱25, L3 ₱25, L4 ₱15; credited ₱65, skipped 1; no compression |
| P17C-T18 | L1 `is_active = 0` | L1 `skipped` "recipient account is disabled"; L2–L4 still pay ₱65 |
| P17C-T19 | L1 `member_status = 'suspended'` | L1 skipped with the membership reason; L2–L4 still pay |
| P17C-T20 | `unilevel_requires_active_upline = 0` | An otherwise-inactive L1 is paid ₱25 |
| P17C-T21 | A staff member (not the company account) as an upline | Paid when the other checks pass |
| P17C-T22 | Self-sponsor row (a cycle) | The walk stops; no duplicate recipient; no crash |
| P17C-T23 | Rate 0 at L1 | No L1 row; L2–L4 pay ₱25 + ₱25 + ₱15 = ₱65; 3 rows |
| P17C-T24 | Rate 0 at L2 | No L2 row; 3 rows; ₱65 |
| P17C-T25 | Global master off | No awards; the order is stamped `unilevel_total = 0` |
| P17C-T26 | Product master off | No awards; the order is stamped |
| P17C-T27 | Both masters on | Awards as in T1 (control for T25/T26) |

**Integrity**

| ID | Test | Expected |
|---|---|---|
| P17C-T28 | `onOrderPaid()` called twice | Second returns `replayed`; row count and balances unchanged |
| P17C-T29 | Duplicate `(order_item_id, user_id, level)` insert | `PDOException` 23000 — the unique anchor holds |
| P17C-T30 | `pending` / `payment_review` order | No awards, **not** stamped |
| P17C-T31 | Reconcile after awards | `ok = true` |
| P17C-T32 | Reconcile with a deleted ledger row | Mismatch reported, `ok = false`, non-zero exit from `wallet_reconcile.php` |
| P17C-T33 | Delete a product that has awards | Refused; `referencesMap()['awards'] > 0`; the message names the award |
| P17C-T34 | Placement snapshot | A new order line carries `unit_base` and `base_total`; an edit to the product's price or P.V. before verification does not change the award |
| P17C-T35 | `quoteLine(P1, 1)` | Ten configured levels, total ₱145 on the ₱500 base; no writes |

**Reversal**

| ID | Test | Expected |
|---|---|---|
| P17C-T36 | Admin cancels a paid order | All awards `voided`; wallet debited by the full amount; ledger `ref_type = 'unilevel_reversal'`; `reversed_amount = amount`; `unilevel_total` recomputed to 0 |
| P17C-T37 | Cancel with a partly spent withdrawable balance | Partial reversal; shortfall = amount − recovered; the award is still `voided`; no negative balance |
| P17C-T38 | `unilevel_reversal_enabled = 0` | Awards stay `credited`; no debit |
| P17C-T39 | Member cancels an unpaid order | No awards, no reversal, nothing to void |

**The monthly gate** (the close method is tested here; 17F wraps it)

| ID | Test | Expected |
|---|---|---|
| P17C-T40 | October ₱450, closed; November receipts (c is L1) | L1 `skipped` "recipient is not Active for 2026-10"; L2–L4 pay when b/a/boss are Active |
| P17C-T41 | November ₱2,000, closed; December receipts | December resumes paying |
| P17C-T42 | No activity row for the previous month | Treated as Active; L1 paid ₱25 |
| P17C-T43 | Closed period, then a cancellation in that month | The snapshot is unchanged; the award is still voided |
| P17C-T44 | `UnilevelCycle::close('2026-10')` | One row per account; header counts; `award_total` snapshot; one transaction |
| P17C-T45 | `close()` twice | Second returns `closed = 0`; nothing written |
| P17C-T46 | `close()` on a future period | `InvalidArgumentException`; nothing written |
| P17C-T47 | Open period with order activity (the live fallback) | `isActive()` computes from orders and agrees with a later close |
| P17C-T48 | `periodOf()` / `bounds()` around midnight in Asia/Manila | `2026-10-31 23:59:59` → `2026-10`; `2026-11-01 00:00:00` → `2026-11`; the bounds are half-open |
| P17C-T49 | `eligibilityMap()` for ten recipients | Exactly one previous-period query (no N+1) |
| P17C-T50 | Switch off, then a paid order, then a manual `close()` | The order is stamped 0; the close still writes snapshots |
| P17C-T51 | A staff account as the **buyer** | The buyer earns nothing; the chain above them is paid normally |

**Manual gate — `QA17C-01` … `QA17C-10`**

No new page appears in this phase. Verify through the wallet statements, the order data and SQL.

1. `QA17C-01` — configure `P1` (P.V. ₱250, reference ladder, switch **on**) and turn
   `unilevel_enabled` on in Settings. Pay an e-wallet order of 2 × `P1` as `buyer`. The order is `paid`.
2. `QA17C-02` — as `c`, open `/wallet`: a **Repeat-purchase bonus** credit of ₱25.00 for the order, and
   the statement filter's new option now matches it. As `b`, ₱25.00; `a`, ₱25.00; `boss` (four hops),
   ₱15.00. The buyer's wallet is unchanged except for the order debit.
3. `QA17C-03` — `/admin/wallets` → the ledger shows the same four credits with `ref_type = unilevel`
   and the award id as `ref_id`.
4. `QA17C-04` — `php bin/wallet_reconcile.php` → exit 0 and a **Repeat-purchase** section reporting
   ₱90.00 and no mismatch.
5. `QA17C-05` — place a **GCash** order: no award while it is `payment_review`; verify the proof as an
   admin → the four credits appear, identical to step 2.
6. `QA17C-06` — deactivate `b` (`is_active = 0`) and pay another order of 2 × `P1`: `c` ₱25, `b` no
   money, `a` ₱25, `boss` ₱15. The skipped ₱25 is not redirected. Restore `b`.
7. `QA17C-07` — admin-cancel a paid order: the four awards are voided, the wallets are debited, and the
   reconcile still exits 0. If a wallet was partly spent, the shortfall is recorded and visible in SQL.
8. `QA17C-08` — `UPDATE users SET is_active = 0` for `c`; pay an order: `c` skipped with
   *"recipient is not Active for …"* only if `unilevel_requires_active_upline = 1`; set that setting to
   `0` and pay again: `c` is paid.
9. `QA17C-09` — turn `unilevel_enabled` off and pay an order: no rows, and
   `shop_orders.unilevel_processed_at` is stamped with total 0. Turn it back on and re-verify the same
   order: nothing new is paid.
10. `QA17C-10` — run `UnilevelCycle::close()` for last month from the PHP CLI (a scratch script, not a
    shipped file), then check `unilevel_periods` and `unilevel_activity`; run it again → no second row.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P17C-T1 … P17C-T51 pass
[ ] Rounding is pinned to the peso/centavo, including the ₱555.55 drift (T5)
[ ] No award row anywhere has the buyer as the recipient (T16, asserted in the suite)
[ ] `wallet_reconcile.php` exits 0 with a Repeat-purchase section, and non-zero when a ledger row is removed
[ ] Reversal leaves no negative balance and never blocks a cancellation
[ ] The full suite is green with the two payment paths, the reversal and the close included
[ ] Tag p17c-money; phase log; Appendix G row
```

**Do not start 17D, 17E or 17F until every box is ticked.**

**Rollback**

1. Set `unilevel_enabled = 0` — new orders are stamped and pay nothing, immediately.
2. Only then revert the code. Any awards already credited stay in the wallets and in
   `unilevel_awards`: they are real money, and a code rollback does not and must not delete them.
   The ledger remains reconcilable because `wallet_reconcile.php` reads the tables, not the code path.
3. A decision to *unpay* is a business decision (a reversal run), not a rollback (§14.9).

**Hand-off to 17D/17E/17F**

- Awards exist, with a stable row shape (`UnilevelAward::forOrder()`, `normaliseFilters()`, totals,
  breakdowns, CSV rows) that 17D and 17E only render.
- Periods and activity exist, with `UnilevelCycle::periods()`/`activityFor()` for the cards and
  `close()` ready to be wrapped by 17F.
- `quoteLine()`/`quoteCart()` exist for 17E's previews; `totalsFor()` for its stats.
- `unilevel_total` and `unilevel_processed_at` are maintained on the order, so 17D's order card can
  distinguish "not paid", "paid before the feature" and "paid and processed".

**Risks**

| Risk | Mitigation |
|---|---|
| A double credit from a raced verification | Four idempotency layers (§14.4); the order row is locked first; P17C-T28/T29 |
| Float drift in the percentages | Integer centavos; `DECIMAL` columns; the ₱555.55 case is pinned |
| A deadlock between two orders sharing uplines | Ascending-recipient locking (§14.6) |
| The reversal is skipped on cancel | The hook is inside `cancelByAdmin()`'s transaction; T36 and the reconcile invariant |
| An exception is swallowed to "keep checkout working" | Rule 12: the exception propagates and the caller rolls back |
| The company account is paid by accident | Explicit company check with its own reason string; T8 |
| A legacy line (pre-17A) pays the wrong base | `base_total` is nullable for ever; the fallback is tested (T7) |

---

## 8. Phase 17D — Admin visibility

**Goal.** Finance can see, filter and export every repeat-purchase award, open the order that produced
it, and read four report cards. **Read-only: this phase adds no money path.**

**Builds on.** 17C (the awards it renders).

**Ships alone because.** It is pure presentation over rows 17C already writes and
`UnilevelAward` already shapes. If 17E and 17F never shipped, the operator still has a complete audit
tool — which is exactly what a controlled money feature needs first.

**Effort.** 1.5 ideal days. **Suite.** ≥ 910 cases.

**Reference.** §14.2 (the API these pages call), §13.7 (the invariants the page surfaces).

**Deliverables**

**Code**

- `system/app/Controllers/AdminCommissionController.php` — `ledger=repeat` selects the new tab;
  filters are passed through `UnilevelAward::normaliseFilters()`; `export` writes the same columns for
  `ledger=repeat` and audits `unilevel.export`.
- Views: `admin/commissions/_repeat_header.php`, `_repeat_stats.php`, `_repeat_periods.php`,
  `_repeat_rows.php` (new); `admin/commissions/index.php` gains the two nav pills; the `package` tab
  keeps today's markup byte-for-byte.
- `system/app/views/admin/orders/_unilevel.php` (new) and `admin/orders/show.php` (include the card
  below the payment panel).
- `system/app/Models/Report.php` — `unilevelSummary()`, `unilevelByLevel()`, `unilevelByProduct()`,
  `unilevelByPeriod()`.
- `system/app/Services/ReportService.php` — the four cards, `type=unilevel` in the range export, and the
  dashboard **Repeat bonus (30 d)** KPI.
- `system/app/views/admin/reports/*` — the four cards; `admin/dashboard/_kpi.php` (or its equivalent)
  — the optional KPI tile.

**Docs.** QA pack section D (`QA17D-01` … `QA17D-08`). `docs/admin-guide.md` gains the repeat ledger
and the order card. `docs/deploy.md` notes the new audit actions.

**Not yet.** No member-facing surface at all. The buyer cannot see the bonus; the uplines see only their
wallet credits (17C).

**Rules**

1. **Read-only.** No controller, model or view in this phase writes an award, a wallet row or a period.
2. **The package tab is untouched.** Its markup, filters, counts and export are byte-identical;
   `P17D-T1` is a regression test, not a formality.
3. **No new route.** `?ledger=repeat` and `?ledger=repeat&…` only (D-14). `routes.php` and
   `routes_inventory.php` are untouched; `ParityTest` stays green.
4. **No direct/indirect column.** The table shows `Lvl` (1–10) and nothing that implies two legs (D-17).
5. **Skipped is not money.** A `skipped` row shows the amount that was due and its reason, and never
   enters a total. The header's *Credited* figure counts `credited` rows only.
6. **Unrecovered is the shortfall.** `Σ (amount − reversed_amount)` over voided rows; never hidden.
7. **Filters fall back silently.** An unknown `level`, `status`, `product_id` or date means "no filter",
   exactly like [`Commission::normaliseFilters()`](../system/app/Models/Commission.php#L140-L172).
8. **Every row links to its order and its wallet movement.** A finance reviewer can walk from a number
   to the order and to the ledger row in one click each.
9. **Empty state teaches.** "No repeat-purchase bonus has been paid. Turn it on in Settings and
   configure a product's ladder." — and it names the two switches, because they are the two reasons it
   can be empty.
10. **The period card is a history, not a button.** It shows closed periods; closing is 17F's job, and
    the page may *hint* at the command but must not offer an action (D-14).

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P17D-T1 | `/admin/commissions` (package tab) | Byte-identical markup and counts to `p17c-money`; regression |
| P17D-T2 | `/admin/commissions?ledger=repeat` | Stats, filters, rows, empty state; the `Lvl` column; no direct/indirect column |
| P17D-T3 | Stats tiles | Credited, this month, awards, recipients, skipped, unrecovered match the fixture rows exactly |
| P17D-T4 | Periods card | Reads `unilevel_periods` newest first; shows members, qualified and award total per closed month |
| P17D-T5 | Filters: level 3, status skipped, product, from/to, q | Each filters correctly; two combined filters intersect; a bad value falls back to "no filter" |
| P17D-T6 | A skipped row | Shows the due amount and the reason; the row-count stat includes it, the credited total does not |
| P17D-T7 | A voided row with a shortfall | Shows `voided · ₱X recovered of ₱Y`; the header's *Unrecovered* equals the sum of shortfalls |
| P17D-T8 | CSV export | Header row and one row per filtered award; columns match the screen; `unilevel.export` audit row written with the filters |
| P17D-T9 | `admin/orders/show` — paid and processed | The card lists the awards with level, recipient, product, base, rate, amount, status; the headline total equals `unilevel_total` |
| P17D-T10 | `admin/orders/show` — unpaid order | "Paid orders earn the repeat-purchase bonus. This order is `pending`." — no rows |
| P17D-T11 | `admin/orders/show` — paid before the feature | "This order was paid before the repeat-purchase stream was switched on." |
| P17D-T12 | Reports page | The four cards render with the fixture numbers; the dashboard KPI reads the last 30 days |
| P17D-T13 | Report export `type=unilevel` | The range CSV includes the repeat rows |
| P17D-T14 | Live feed | An award bumps `admin.commissions`; an open repeat tab refreshes; the package tab is unaffected |
| P17D-T15 | A member opens `/admin/commissions?ledger=repeat` | 403, same guard as the package tab |

**Manual gate — `QA17D-01` … `QA17D-08`**

1. `QA17D-01` — with the four awards of `QA17C-02` present, open
   `/admin/commissions?ledger=repeat`: the header shows ₱90.00 credited, 4 awards, 4 recipients.
2. `QA17D-02` — filter level `2` → one row (`b`, ₱25.00). Filter status `skipped` with `b` deactivated
   and a new order → the skipped row shows the reason.
3. `QA17D-03` — cancel a paid order as an admin, then reload: the rows show `voided` with the recovered
   amount, and *Unrecovered* shows any shortfall.
4. `QA17D-04` — click a row's order link: `/admin/orders/{id}` opens with the repeat card and the same
   numbers; back on the ledger, click the wallet link → `/admin/wallets/…` at the movement.
5. `QA17D-05` — export the filtered CSV; open it; the columns and values match the screen.
6. `QA17D-06` — open an order that is `payment_review`: the card explains that nothing is paid yet.
   Open an order paid **before** 17C: the card says so.
7. `QA17D-07` — `/admin/reports`: the four repeat cards show the same totals; export the range CSV and
   find the `unilevel` rows.
8. `QA17D-08` — switch to the **Package commissions** tab: it looks and behaves exactly as before.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P17D-T1 … P17D-T15 pass
[ ] The package tab is byte-identical (diff or snapshot)
[ ] No new route; ParityTest and RouterTest green
[ ] Every number on the page is traceable: row → order, row → wallet movement
[ ] Tag p17d-admin; phase log; Appendix G row
```

**Rollback**

Revert the views and the controller. The tab disappears; the data is untouched. Nothing in 17C reads
anything this phase writes.

**Hand-off to 17E**

- The admin ledger proves the numbers 17E will show members; any disagreement is a bug in 17E.
- `UnilevelAward`'s filter/total/breakdown API is proven by a page, so 17E can reuse it for
  `/earnings?ledger=repeat` without inventing a second implementation.

**Risks**

| Risk | Mitigation |
|---|---|
| The package tab regresses while adding pills | T1 is a snapshot/byte comparison |
| A stat double-counts skipped rows | Every total is computed by `UnilevelAward`, not in the view; T3/T6 |
| The empty state is vague and generates support tickets | Rule 9 names both switches |
| Reports disagree with the ledger | Both call the same model methods; T7/T8 compare them |

---

## 9. Phase 17E — Member visibility

**Goal.** A member can see what a purchase pays **to their sponsor chain** before buying, what it
actually paid afterwards, and what they themselves have earned as an upline — with copy that never
suggests the buyer earns a rebate.

**Builds on.** 17C (`quoteLine()`, `quoteCart()`, `totalsFor()`, `UnilevelAward`'s member queries).

**Ships alone because.** It is presentation. Every number it shows already exists and is already
proven by 17C's tests; if 17D never shipped, members still see their own money and admins still see the
wallet statements.

**Effort.** 1.5 ideal days. **Suite.** ≥ 921 cases.

**Reference.** §12.9–12.10 (the eligibility and rounding the copy describes), §14.10 (the quote API).

**Deliverables**

**Code**

- `system/app/Controllers/ShopController.php` — the product page context gains
  `UnilevelService::quoteLine($id, 1)`; the catalogue context gains `ProductUnilevel::totalsMap()` for
  the badge; both are also added to the live feed payload.
- `system/app/Controllers/CheckoutController.php` — `bonus_preview` from one batched
  `quoteCart()` call, in the page context and in `feedPayload()`.
- `system/app/Controllers/EarningsController.php` — `ledger=repeat` selects the new tab;
  `export` writes `repeat-purchase-statement_YYYY-MM-DD.csv`; both scoped to the session member.
- Views (new): `shop/product/_bonus.php`, `shop/checkout/_bonus.php`,
  `member/earnings/_repeat_header.php`, `_repeat_stats.php`, `_repeat_status.php`, `_repeat_levels.php`,
  `_repeat_rows.php`.
- Views (modified): `shop/product/_card.php` (include the panel), `partials/product_card.php`
  (the *Up to 29% repeat bonus* badge), `shop/checkout/_summary.php` (the preview line),
  `shop/order.php` (the outcome card), `member/earnings.php` (the tabs),
  `member/dashboard/_earnings.php` (the tile), `member/membership.php` (the paragraph).

**Docs.** QA pack section E (`QA17E-01` … `QA17E-09`). `docs/admin-guide.md` — the member view. The
copy rules below are the acceptance criteria for the whole section.

**Not yet.** No operational job and no release work: 17F adds the close job, 17G adds the documentation
and the phase badge, and neither changes member-facing behaviour.

**Rules — the copy rules are normative**

1. **Say "repeat-purchase bonus", never "unilevel" alone**, in member-facing copy. "Unilevel" stays in
   admin labels and ledger names because it is the plan's own term.
2. **Say "up to X%", never "you will earn X%".** The chain length and the Active rule decide the actual
   amount.
3. **Always say the money is credited when the order is paid**, not when it is placed.
4. **Never call it a rebate or cashback.** The buyer is not a recipient. Say "your sponsor chain earns",
   and on the product panel say "you do not earn a rebate on your own purchase".
5. **Never describe level 1 as a "direct bonus"** or the rest as an "indirect ladder". It is one ladder;
   the level number is the label (D-17).
6. **The product panel shows all ten levels**, even the zero ones, with the per-unit amount at the
   current base. A level with no rate reads `—`, not `0.000%`.
7. **The checkout line is informational**, never a promise, never styled as money the buyer will
   receive, and absent when no cart product has a ladder.
8. **The order page shows the order's total only.** Which upline received which amount is admin-only,
   the same rule as package commissions.
9. **The earnings tab has no "as buyer" stat.** A member earns nothing on their own purchase; their own
   purchases appear only in the two-clock status panel, because they feed next month's Active flag.
10. **Both tabs stay live.** The package tab keeps its URL and behaviour; the repeat tab is
    `?ledger=repeat`; one live surface (`member.earnings:{id}`) refreshes both.
11. **Every query is scoped to the session member** (`WHERE user_id = ?`). No member can read another's
    rows by changing a query parameter.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P17E-T1 | `shop/product` for a configured product | The panel lists levels 1–10 with rates and per-unit amounts; the base and *Total 29.000%* are right; the "you do not earn a rebate" note is present |
| P17E-T2 | `shop/product` for an unconfigured product | No panel at all |
| P17E-T3 | Catalogue card | *Up to 29% repeat bonus* when the ladder is on; nothing when off; one batched query for the page |
| P17E-T4 | Checkout summary with a configured cart | "Up to ₱145.00 goes to your sponsor chain…" with the "paid when the order is paid" note; the word *rebate* appears nowhere |
| P17E-T5 | Checkout summary with no configured product | No line |
| P17E-T6 | Checkout live feed | `bonus_preview` is present in the refreshed summary fragment and matches the first render |
| P17E-T7 | `member/order.php` for a paid order with awards | The total paid to the sponsor chain; no per-upline amounts; nothing for an order without awards |
| P17E-T8 | `/earnings?ledger=repeat` | Stats, the two-clock status panel, the 1–10 breakdown, the rows and the filters; **no** "as buyer" stat |
| P17E-T9 | `/earnings?ledger=repeat` filters + export | Filters work; the CSV opens with the documented columns and the member's own rows only |
| P17E-T10 | `/earnings` (package tab) | Unchanged — regression |
| P17E-T11 | `/earnings?ledger=repeat&user_id={other}` | Ignored: the page still shows only the session member's rows |
| P17E-T12 | `/dashboard` and `/membership` | The tile and the paragraph render with the current Active status; no action, no form |
| P17E-T13 | Mobile width (390 px) | The product panel, the checkout line and the earnings tab render without horizontal scroll |

**Manual gate — `QA17E-01` … `QA17E-09`**

1. `QA17E-01` — open a configured product as a member: the panel shows ten levels, the per-unit
   amounts on the shown base, the total, and the sentence about the buyer not earning a rebate.
2. `QA17E-02` — add 2 to the cart and open `/checkout`: the "up to" line matches the panel × 2 and says
   the money is paid when the order is paid.
3. `QA17E-03` — place and pay the order. Open `/orders/{id}`: the card shows the total paid to the
   sponsor chain and **no** individual upline amounts.
4. `QA17E-04` — as `c` (level 1 of that order), open `/earnings?ledger=repeat`: the ₱25.00 award is in
   the rows and in the level-1 breakdown; the monthly status panel shows last month's purchases and
   this month's progress.
5. `QA17E-05` — filter the repeat tab by level `1` and by status `credited`; export the CSV and compare
   the values on screen.
6. `QA17E-06` — as `c`, open `/earnings` (package tab): unchanged. Open `/earnings?ledger=repeat` while
   an award arrives → the tab refreshes live.
7. `QA17E-07` — as the **buyer** of the order: their repeat tab has no row for their own purchase and no
   "as buyer" stat; their own purchase appears only in the status panel.
8. `QA17E-08` — `/dashboard` shows the repeat tile with the current Active status; `/membership` shows
   the paragraph. Neither offers an action.
9. `QA17E-09` — repeat the product panel and the earnings tab at 390 px: no horizontal scroll.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P17E-T1 … P17E-T13 pass
[ ] A word search of every new view for "rebate"/"cashback" finds only the deliberate negation
[ ] The package earnings tab is unchanged (regression)
[ ] No new route; ParityTest green
[ ] Tag p17e-member; phase log; Appendix G row
```

**Rollback**

Revert the controllers and the views. The panels disappear; the money and the admin ledger are
untouched.

**Hand-off to 17F**

- Nothing. 17F depends on 17C only, and 17E's numbers must agree with the admin ledger of 17D.

**Risks**

| Risk | Mitigation |
|---|---|
| Copy implies a buyer rebate and creates a support queue | The four "never" rules + T4/T8 word assertions + the product panel's own sentence |
| The preview is read as a promise | "Up to", the explained Active rule, and the checkout note |
| An N+1 in the catalogue badge | `totalsMap()` is batched and the test counts queries |
| A member reads another's rows | Session-scoped queries; T11 |

---

## 10. Phase 17F — Operations

**Goal.** The monthly close runs unattended — from cron, from a URL and from Windows — with a dry run,
a doctor, a log, a lock, JSON output and idempotency.

**Builds on.** 17C (`UnilevelCycle::close()` and the Active resolution are already complete and tested).

**Ships alone because.** It is an operational wrapper. Nothing in it changes a payout decision: the
engine's live fallback (§12.7) already produces the correct answer without any close at all. The job
makes the snapshot explicit and auditable; it does not make the money correct (17C did that).

**Effort.** 1.0 ideal day. **Suite.** ≥ 930 cases.

**Reference.** §14.8 (`close()`), §12.7 (the two clocks and the fallback).

**Deliverables**

**Code**

- `system/bin/unilevel_month_close.php` (new) — the CLI job: flags `--period`, `--dry-run`,
  `--doctor`, `--json`, `--log-dir`, `--quiet`, `--help`; the shared `CronAccess` rules; the lock; the
  monthly log; the one-line summary.
- `system/public/cron/unilevel_month_close.php` (new) — the two-line web adapter, shaped like
  [`public/cron/shop_expire.php`](../system/public/cron/shop_expire.php): it requires the CLI file,
  which owns every rule.
- `system/bin/unilevel_month_close.cmd` (new) — the Windows launcher, like the other `.cmd` files.
- `system/tests/Cases/CronTest.php` — the eight new cases of §16.5.

**Docs.** `docs/cron.md` gains the fourth job (schedule, flags, log, lock, failure modes).
`system/README.md` gains the cron section and the settings that drive it. QA pack section F
(`QA17F-01` … `QA17F-07`).

**Not yet.** No release work: 17G owns the documentation, the phase badge, the acceptance pass and the
sign-off. Nothing in 17F may touch `APP_PHASE` or the QA pack's earlier sections.

**Rules**

1. **The schedule is 00:15 on the first of the month, Asia/Manila.** Fifteen minutes is enough for the
   last payments of the month to commit; the live fallback covers a late or missed run.
2. **Close every pending month from `unilevel_ledger_since`'s month through last month**, oldest first.
   A fresh install does not close years of empty months.
3. **One transaction per period.** Activity rows, then the header. A crash rolls the period back and the
   next run redoes it.
4. **No `ON DUPLICATE KEY UPDATE`.** A period closes at most once; a racing second run hits the primary
   key and rolls back. One winner, no rewrite.
5. **Idempotent.** A second run finds nothing pending and exits 0 with `closed=0`.
6. **Independent of the feature switch.** Periods are closed even when `unilevel_enabled = 0` — the
   snapshots are what make the stream correct the moment an operator turns it on. The close pays no
   money, so it has no kill switch.
7. **A closed period is immutable.** `--period` on a closed month is a no-op with a clear message.
   There is no reopen.
8. **A future period is refused** (exit 1, message, nothing written).
9. **`paid_at` decides the month**, not the order date. An order verified on 2 December belongs to
   December, for the award and for the buyer's qualification alike.
10. **All accounts get a row**, including members with zero purchases, so after the first close every
    account has an explicit `qualified = 0/1` and the only "missing flag = Active" case is a member who
    joined later.
11. **One log file per month, one line per run**, in the same format as the other jobs; a lock file
    under `storage/locks/`; `--doctor` reports the PHP/MySQL clock agreement, the timezone, the
    threshold, the closed and pending periods, the member count and the log folder's writability.
12. **The web entry adds no logic.** Access rules, lock, log and flags all live in the CLI file.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P17F-T1 | `--dry-run` with unpaid and paid activity | Nothing written; output lists the periods it would close and the projected qualified counts |
| P17F-T2 | Real run | `unilevel_periods` + `unilevel_activity` rows for last month; counts agree with the aggregate |
| P17F-T3 | Second run | `closed=0`, exit 0, no second row |
| P17F-T4 | Two missed months | Both closed in one run, oldest first |
| P17F-T5 | `--period` in the future | Exit 1 with a message; nothing written |
| P17F-T6 | `--period` on a closed month | Exit 0, "already closed", nothing written |
| P17F-T7 | Lock held by another process | `[SKIP ]`, exit 0, no work |
| P17F-T8 | Log line | One line with `trigger`, `period`, `members`, `qualified`, `award_total`, `duration`; the file is `unilevel_month_close-YYYY-MM.log` |
| P17F-T9 | `--json` | Machine-readable payload with the same numbers as the log line |
| P17F-T10 | `--doctor` | PHP and MySQL times, `APP_TIMEZONE`, threshold, closed and pending periods, member count, lock state, log folder writable |
| P17F-T11 | Web entry | Same payload as the CLI for the same database; a non-loopback request without `CRON_KEY` is refused |
| P17F-T12 | `.cmd` launcher on Windows | Same output; the PHP path is taken from the environment |

**Manual gate — `QA17F-01` … `QA17F-07`**

1. `QA17F-01` — `php bin/unilevel_month_close.php --doctor` on the development database: the clocks
   agree, the threshold is ₱500, and the pending/closed lists are as expected.
2. `QA17F-02` — `--dry-run`: the periods it would close are listed; no row count changes in
   `unilevel_periods` or `unilevel_activity`.
3. `QA17F-03` — run it for real: the output names the period, members, qualified and award total;
   `SELECT * FROM unilevel_periods` and `SELECT COUNT(*) FROM unilevel_activity WHERE period = …` show
   the rows. (If 17D has shipped, its period card lists the month too — do not depend on it.)
4. `QA17F-04` — run it again: `closed=0`, nothing written.
5. `QA17F-05` — `--period` with last month (closed) → "already closed"; with next month → refused,
   exit 1.
6. `QA17F-06` — open the web entry in a browser from `localhost`: same payload. Check
   `storage/logs/unilevel_month_close-YYYY-MM.log` and `storage/locks/`.
7. `QA17F-07` — double-click `bin/unilevel_month_close.cmd`: it runs and reports the same as the CLI.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P17F-T1 … P17F-T12 pass; the eight CronTest cases exist and pass
[ ] Dry run and doctor change nothing (asserted, not eyeballed)
[ ] Two consecutive runs are idempotent; two missed months catch up in order
[ ] Tag p17f-month-close; phase log; Appendix G row
```

**Rollback**

Remove the cron entry and revert the three files. The stored periods and activity remain valid — they
are snapshots of real orders — and the engine's live fallback continues to work without the job.

**Hand-off to 17G**

- The operational story is complete: the schedule, the flags, the log, the lock, the failure modes.
- 17G's runbook and QA pack can cite a job that exists and has a doctor.

**Risks**

| Risk | Mitigation |
|---|---|
| A late run and a live payment race | Fifteen-minute offset; the live fallback; `--dry-run` first |
| Timezone drift between PHP and MySQL | `--doctor` compares them; both are `+08:00`; the threshold is stored for audit |
| An operator closes a month twice expecting a correction | Immutable periods, a clear message, and no reopen path in the docs |
| The web entry becomes a second implementation | Rule 12: it requires the CLI file and owns no logic |

---

## 11. Phase 17G — Release

**Goal.** Documentation that matches the code, a QA pack a stranger can execute, `APP_PHASE 17`, a
green acceptance run, and a signed gate record.

**Builds on.** All six phases.

**Ships alone because.** It ships last by definition; what it must *not* do is change behaviour. If a
documentation claim and the code disagree during this phase, the code wins and the plan is amended.

**Effort.** 1.5 ideal days. **Suite.** 930 cases (no new cases unless a documentation claim exposes a
missing test).

**Reference.** §2.4 (the universal gate), §17 (risks), Appendix G.

**Deliverables**

**Code**

- `system/app/config.php` — `APP_PHASE` **17** (D-15), and `HardeningTest` updated to assert it.
- `system/tests/smoke.ps1` — the new URL checks accumulated by 17B–17F, plus the cron doctor URL.

**Docs**

- `docs/qa/phase-17-unilevel-repeat-purchase.md` — sections A–G complete, each with its `QA17X-nn`
  checks, evidence references and a sign-off line.
- `docs/qa/README.md` — the Phase 17 row finalised (check count, time needed, "signed").
- `docs/qa/sign-off-sheet.md` — the Phase 17 row and the seven gate records.
- `docs/phase-log.md` — the seven phase entries (each written at its own gate) and the release entry.
- `system/README.md` — routes/settings/migrations/commissions/cron sections, the case and smoke counts,
  the `APP_PHASE` line.
- `docs/deploy.md` — the new settings, the migration, the bundle, the verification counts.
- `docs/runbook.md` — the repeat-purchase section: what to check weekly, how to read the ledger, what to
  do when the reconcile fails, how to reverse a payout.
- `docs/admin-guide.md` — the ladder card, the repeat ledger, the reports.
- `docs/cron.md` — already done in 17F; verify only.

**Not yet.** Nothing. The phase closes the workstream.

**Rules**

1. **No behaviour change in this phase.** Only `APP_PHASE`, tests and documentation. A code fix
   discovered here is a defect against the phase that should have caught it: fix it, run that phase's
   tests, and record the deviation.
2. **The documentation is executable.** Every command in the QA pack, the runbook and `docs/deploy.md`
   is run during the acceptance pass, on a scratch schema.
3. **The QA pack is written for a stranger.** It assumes no prior knowledge of this plan, names the
   exact URL and the exact expected number, and states what to do when a check fails.
4. **The gate record is factual.** Counts, tags, deviations and evidence filenames — no adjectives.
5. **The release acceptance table is complete.** Every line of the release acceptance table below must
   be ticked against the phase that proved it, or the release is not signed.

**Test plan**

No new automated cases: this phase changes no behaviour, and a documentation claim that no case covers
is a defect in the phase that owns the behaviour (Rule 1). Verification is the accumulated suite plus the
acceptance table below, executed in this order:

1. `php tests/run.php` — the documented case count, 0 failed, exit 0.
2. `powershell -File tests/smoke.ps1` — the documented check count, 0 failed, exit 0.
3. `php bin/build_install_sql.php --check` — exit 0 on the committed bundle.
4. The release acceptance table — every row ticked against a named phase and a named case.
5. `APP_PHASE` — 17 in `config.php`, on the dashboard badge, and asserted by `HardeningTest`.

**Release acceptance table**

The single acceptance list of the earlier draft, distributed to the phase that proves each line.

| Acceptance criterion | Proved by |
|---|---|
| A paid e-wallet order and a verified manual order produce identical awards | 17C — P17C-T1/T2 |
| An unpaid order produces none, and no later transition produces another | 17C — T30, T28 |
| Each award is a withdrawable wallet credit with `ref_type='unilevel'` and one ledger row | 17C — T1, T31 |
| `wallet_reconcile.php` exits 0 with a Repeat-purchase section | 17C — T31/T32; 17F gate |
| The buyer is never a recipient | 17C — T16 |
| Level 1 is the direct sponsor; levels 2–10 the next sponsors, each at its own rate | 17C — T1, T3, T10–T13 |
| A zero level consumes its position; no compression | 17C — T17, T23, T24 |
| Per-level rounding matches the fixture table exactly | 17C — T4, T5 |
| A replayed `onOrderPaid()` moves no money | 17C — T28 |
| Each product has its own ten-row ladder, switch and base | 17B — T1, T2 |
| The five visible settings render on `/admin/settings` and are audited | 17B — T18, T19 |
| Enabling with no rate is refused; a ladder over 100% is refused | 17B — T3, T6 |
| Changing a ladder never rewrites an existing award | 17C — T9 (rates snapshotted), T34 |
| The product form is one 1–10 table with no direct/indirect split | 17B — T14 |
| The close writes one snapshot row per account and one header | 17C — T44; 17F — T2 |
| A member below the threshold is blocked for the following month | 17C — T40 |
| A member with no snapshot is Active | 17C — T42 |
| A closed period is never rewritten; the job is idempotent and catches up | 17C — T45; 17F — T3, T4 |
| Every surface shows the numbers the fixture predicts | 17D — T2–T13; 17E — T1–T12 |
| The product page and checkout say "your sponsor chain earns", never "you earn" | 17E — T1, T4 |
| The package-commission pages are unchanged | 17D — T1; 17E — T10 |
| Both CSV exports open with the documented columns | 17D — T8; 17E — T9 |
| The migration is idempotent; migrated equals fresh | 17A — T2, T3 |
| A fresh install reports 31 tables and 54 settings | 17A — T6 |
| The reset empties the three transactional tables and keeps the ladder | 17A — T8 |
| The cron runs from CLI, URL and `.cmd`, logs one line, refuses a future period | 17F — T5, T8, T11, T12 |
| The docs are updated | 17G — this phase |

**Manual gate — `QA17G-01` … `QA17G-06`**

1. `QA17G-01` — `php tests/run.php` → the documented case count, 0 failed; `tests/smoke.ps1` → 0
   failed; `build_install_sql --check` → exit 0.
2. `QA17G-02` — hand the QA pack to a tester who has not seen this plan; they execute sections A–F
   without asking a question. Any question is a documentation defect.
3. `QA17G-03` — walk the release acceptance table row by row; every row cites a passing case or a
   screenshot.
4. `QA17G-04` — open `/dashboard` as an admin: the phase badge reads **17**.
5. `QA17G-05` — on a scratch install, follow `docs/deploy.md` end to end; then the runbook's weekly
   checklist.
6. `QA17G-06` — confirm the evidence folder contains one file per `QA17X-nn` check, and the sign-off
   sheet carries the seven gate records.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] `APP_PHASE === 17`; HardeningTest green
[ ] The release acceptance table is complete and every row is ticked
[ ] The QA pack is signed; the sign-off sheet carries Phase 17
[ ] The seven gate records in Appendix G are filled
[ ] The phase log has seven entries plus the release entry
[ ] Tag p17g-release (and `phase-17` as the feature tag); the plan's status line is updated
```

**Rollback**

Documentation and `APP_PHASE`: revert the constants and the docs. Behaviour is unchanged, so this is
the safest phase to revert.

**Hand-off**

The workstream is closed. The next plan may build the deferred items in §18 (a level-0 rebate, a
backfill, a per-order cap) **without reopening any of these seven gates**, because each deferred item is
a new stream with its own economics.

**Risks**

| Risk | Mitigation |
|---|---|
| The docs describe an intention that the code does not implement | Every claim is executed in the acceptance pass; the code wins |
| The QA pack is written by the author and hides a gap | Rule 3, and `QA17G-02` uses a different tester |
| `APP_PHASE` lands without `HardeningTest` | They are one deliverable in one commit |
| A defect is found during the acceptance pass and quietly patched | Rule 1: fix it, re-run that phase's gate, record the deviation |

---

# Part III — The shared contract

## 12. Specification (normative)

This section is the contract. Where it diverges from the simulator, the divergence is stated and
justified; the simulator remains the authority for the **mechanics** (rate shape, the upline walk, no
compression, the Active rule), not for the app's data model. The one deliberate divergence is the
**missing level 0**: the simulator pays the buyer 10%, the package model pays the buyer nothing, and
this plan mirrors the package model (D-09). There is **no** direct/indirect split: one ladder, levels
1–10 (D-17).

Each subsection names the phase that implements it. A phase may not implement a rule from a later
subsection.

### 12.1 Vocabulary and symbols

| Term | Meaning | Phase |
|---|---|---|
| **order** | One `shop_orders` row plus its `shop_order_items` lines | 17C |
| **paid** | The order's status entered `paid`; `paid_at` is stamped. The statuses `paid`, `packing`, `ready_to_ship`, `shipped`, `delivered`, `completed` are all "paid" for reporting (`Order::PAID_STATUSES`), but the **award happens exactly once, on the transition** | 17C |
| **line** | One `shop_order_items` row: `quantity × unit_price = line_total` | 17C |
| **base** | The amount the percentages apply to: the line's P.V. (or price) × quantity (§12.3) | 17B/17C |
| **ladder** | A product's ten percentage rows, level 1 … 10 (§12.4) | 17B |
| **buyer** | `shop_orders.user_id` — the member who checked out; **never a recipient** in this stream | 17C |
| **recipient** | The member credited at one level: the buyer's direct sponsor at level 1, an upline at levels 2–10 | 17C |
| **sponsor chain** | `users.sponsor_id` links, walked upward from the buyer. A blank-sponsor signup is placed under the **company account** (the primary admin, `Member::primaryAdmin()`), which is a real row; the walk ends when `sponsor_id` is `NULL` | 17C |
| **company account** | The primary admin — the default sponsor and the account that receives company revenue. It is **never paid** a repeat-purchase bonus (§12.6) | 17C |
| **direct sponsor** | `users.sponsor_id(buyer)` — chain hop 1, the level-1 recipient | 17C |
| **period** | A calendar month in `Asia/Manila`, written `YYYY-MM` (e.g. `2026-11`) | 17C |
| **Active** | The monthly qualification for a period: personal purchases in the previous period ≥ threshold (§12.7) | 17C |
| **award** | One `unilevel_awards` row: one (order line, recipient, level) decision, credited, skipped or voided | 17C |
| **N** | `min(10, settings.unilevel_max_levels)` — the deepest sponsor level this build pays | 17C |

### 12.2 What "successful checkout" means here

The user-facing phrase is *"upon successful checkout"*. In this application a checkout can succeed in
two very different ways, so the plan defines **one money event**:

> **The bonus is awarded when the order becomes `paid` — never when it is merely placed.**

| Path | When `paid` is set | Where the hook lives |
|---|---|---|
| E-wallet | Inside the checkout transaction, after the wallet debit | [`OrderService::placeFromCart()`](../system/app/Services/OrderService.php#L256-L296), after `issueForPaidOrder()` |
| GCash / Maya / bank | When an administrator verifies the uploaded proof | [`PaymentService::verify()`](../system/app/Services/PaymentService.php#L416-L447), after `issueForPaidOrder()` |

| Order status | Award? | Why |
|---|---|---|
| `pending`, `payment_review`, `payment_failed` | **no** | No money has been received; the order can expire or be rejected |
| `paid` (entry) | **yes, once** | This is the money event |
| `packing`, `ready_to_ship`, `shipped`, `delivered`, `completed` | no further award | The order was already processed; later transitions never touch the ledger |
| `cancelled` before payment | **no** | No award exists; nothing to reverse |
| `cancelled` after payment | **void + best-effort reversal** | §14.9 |

**The trade-off, stated plainly:** paying at placement would credit sponsors days before the company has
the money and would pay on orders that later expire (the expiry job cancels `pending` orders after the
payment window). Paying at `paid` keeps the company's books consistent with the wallet ledger, at the
cost of the buyer's sponsor seeing the bonus only after verification. This is D-04.

**Phase.** 17C. 17B may configure a product but no order can pay anything.

### 12.3 The bonus base

Percentages apply to a **bonus base**, not to the order total. Shipping is free in this build and is
excluded regardless.

For each order line:

```
unit_base = CASE products.unilevel_base
             WHEN 'price' THEN products.price
             WHEN 'pv'    THEN products.pv
             ELSE              products.pv > 0 ? products.pv : products.price   -- 'auto'
           END

base = unit_base × quantity
```

- `products.pv` is the existing, currently unused point-value column. The admin form gains a
  **Point value (P.V.)** field; `0` means "not set".
- `unilevel_base` is a new per-product enum (`auto` default, `price`, `pv`) so an operator who uses P.V.
  only for reporting can still price the bonus on the retail price.
- The base is **snapshotted on the order line at placement** (`shop_order_items.unit_base`,
  `base_total`), so a price or P.V. edit between placement and verification cannot change what the order
  pays. Lines created before migration 020 have `NULL` snapshots and fall back to the product's current
  value; the award records which source was used (`base_source`).
- Both values are money: `DECIMAL(12,2)`, no negative bases, no discounts (the build has none).

**Worked base:** a line of `2 × Coffee` with `price = ₱300`, `pv = ₱250`, base `auto` → `250 × 2 = ₱500`.
A line of `1 × Immune Booster` with `price = ₱500`, `pv = 0` and base `auto` → `500 × 1 = ₱500`.

**Phase.** The rule and its validation are 17B; the snapshot and its use are 17C.

### 12.4 The per-product ladder, levels 1–10

Ten rows per product, level 1 … 10, each a **percentage**, in `product_unilevel_levels`.

| Level | Recipient | Meaning | Range |
|---:|---|---|---:|
| 1 | the buyer's direct sponsor | first upline | 0.000 – 100.000 % |
| 2–10 | the next nine sponsors | each one level further up | 0.000 – 100.000 % |

Rules:

1. **Per product.** Product A's ladder never applies to Product B. A cart with three products produces
   up to three independent sets of awards, one per line.
2. **Percent of the line base** (§12.3), rounded per level per line (§12.8).
3. **One ladder, no direct/indirect split.** Level 1 is the direct sponsor and is simply the first row;
   it is not a separate "direct leg", has no separate field and no read-only treatment (D-17).
4. **Zero means "no row, level consumed".** A 0% level writes no award, but the level is still counted —
   the next upline remains at its own level number (identical to the package engine's handling of a zero
   package level).
5. **Rows are optional.** No row for a product = 0% at every level. `0` and missing are equivalent; the
   model zero-fills the ladder exactly like [`Package::levels()`](../system/app/Models/Package.php#L149-L170).
6. **The sum must not exceed 100%.** The ten percentages are validated so `SUM(percent)` ≤ 100.000. A
   ladder over 100% is rejected with a field error, not clamped silently (D-18).
7. **Two switches have to be on.** `settings.unilevel_enabled = 1` **and**
   `products.unilevel_enabled = 1`. Either one off → no row is written for that product (the order is
   still stamped as processed).
8. **The ladder is read at the payment event**, not at placement. An edit between placement and
   verification applies to the later payment; the admin UI says so. (The base is the placement snapshot
   — only the rates float. See D-06.)

**Reference shape.** 5/5/5/3/3/2/2/2/1/1 across levels 1–10; total 29%. The reference simulator's 10%
level-0 row is **not** part of this ladder (D-09).

**Phase.** 17B.

### 12.5 The sponsor-chain walk

Given the buyer `B` and `N = min(10, unilevel_max_levels)`:

```
chain = sponsorChain(B, N)        # level 1 = direct sponsor, level L = L hops up

for level = 1 .. min(N, len(chain)):
    rate = ladder[level]
    if rate <= 0:
        continue                  # no row, level consumed
    reason = recipientBlockReason(chain[level], period)
    if reason == '':
        credit(chain[level], level, rate)
    else:
        skip(chain[level], level, rate, reason)
```

- **Sponsor chain only** — `users.sponsor_id`, never the downline tree, never placement.
- **Ten steps maximum** (`N` ≤ 10). An eleventh sponsor is never paid.
- **No compression.** A skipped level keeps its number; the next upline still receives the rate for its
  own level. The reference model is explicit about this (reference §7.4 and §11.3 #18).
- **Level 1 is not special-cased.** It is the first iteration of the loop. If the direct sponsor is
  ineligible, a `skipped` row at level 1 records why; level 2 still pays at its own rate. No one is
  promoted into level 1 or level 2.
- **The company account is a row, but it is never paid.** A blank-sponsor signup is sponsored by the
  primary admin ([`SponsorService::defaultSponsor()`](../system/app/Services/SponsorService.php#L105-L144)),
  so the walk reaches it; the level is consumed and recorded as skipped (`company account is not paid`).
- **The walk ends at `sponsor_id = NULL`** (a deleted sponsor or a data repair), exactly like the end of
  a chain.
- **Cycles stop the walk.** A visited-id set guards against corrupted `sponsor_id` chains, the same
  technique as [`CommissionService::sponsorChain()`](../system/app/Services/CommissionService.php#L309-L350).
- **The chain is resolved once per order**, not once per line: every line has the same buyer, so one
  walk serves the whole order (performance, §14.7).

**Phase.** 17C.

### 12.6 Recipient eligibility

The buyer is **not** a recipient. Every level 1–10 uses the **same** checks:

| Check | Rule | Reason written when it fails |
|---|---|---|
| Account exists | `users` row found | walk stops (no row to record) |
| Not the company | `id <> Member::primaryAdmin()['id']` | `company account is not paid` |
| Sign-in allowed | `users.is_active = 1` | `recipient account is disabled` |
| Membership | `users.member_status = 'active'` | `recipient membership is pending/inactive/suspended` |
| Monthly Active | §12.7 | `recipient is not Active for 2026-11` (the period used) |
| Global switch | `settings.unilevel_requires_active_upline = 1` | when `0`, the monthly check is skipped (all other checks still apply) |

Deliberate differences from the referral leg:

- **No package requirement.** The referral leg skips a member without `package_id`
  ([`CommissionService::ineligibility()`](../system/app/Services/CommissionService.php#L752-L780)); a
  repeat-purchase bonus is product-driven and should not depend on a package the member bought months
  ago. D-08.
- **The company account is skipped explicitly** rather than implicitly through the missing package. The
  primary admin has no package in a normal install, so the referral leg already pays it nothing; the
  repeat leg must say so on purpose, because it does not require a package.
- **No "active sponsor" setting reuse.** `commission_requires_active_sponsor` belongs to the referral
  leg; the repeat leg has its own `unilevel_requires_active_upline`, so an operator can relax one without
  touching the other.
- **Other staff accounts are ordinary recipients.** A staff member who is a genuine upline and meets the
  eligibility checks is paid; only the company account is excluded (§18 Q4).

A blocked level writes a `skipped` row with the amount that *would* have been paid. Skipped rows never
move money and are visible in the admin ledger with the reason. The rule is identical at every level,
including level 1: if the direct sponsor is Inactive, level 1 is skipped, and the walk still pays levels
2–10 at their own rates.

**Phase.** 17C.

### 12.7 The monthly requirement (Active)

The reference model's two-clock rule (reference §2.4 and §7.5):

> The flag used during month **M** is decided from the personal purchases of month **M−1**, at the close
> of M−1. A member with no flag yet is **Active**.

The app implements it with an explicit period snapshot:

```
period(P)          = the calendar month of shop_orders.paid_at, Asia/Manila → 'YYYY-MM'
previous(P)        = the calendar month before P
personal(P, M)     = Σ shop_order_items.line_total
                     over orders of M with paid_at in P and status <> 'cancelled'
Active(P, M)       = personal(previous(P), M) >= settings.unilevel_active_threshold   (default 500)
```

Resolution order at award time (`UnilevelCycle::isActive()`):

| Situation | Result | Why |
|---|---|---|
| `unilevel_periods[previous]` exists **and** `unilevel_activity[previous, M]` has a row | that row's `qualified` | The snapshot is authoritative and immutable for members who were evaluated |
| The period is closed but this member has no row (joined later) | **Active** | A missing flag means Active (reference §7.5 and §11.3 #21) |
| The period was **not** closed, and M has a row | `qualified` as evaluated at close | Defensive; should not happen |
| The period was **not** closed (fresh install, cron late, first month) and M has order activity | compute `personal(previous, M)` live from orders | Makes the 00:00–00:15 window and a missed cron correct, with no drift |
| No order activity for M and no close row | **Active** | A missing flag means Active |

Additional rules:

- **The threshold is exclusive of shipping and of the bonus itself.** It measures what the member
  *bought*, not what they *earned*. The buyer's purchase counts toward their own next-month Active flag
  even though the buyer earns no bonus on it.
- **A member who buys ₱2,000 on the 2nd of the month does not become Active that month.** They become
  Active for the *next* month, because the flag for M is fixed from M−1. This is the two-clock design,
  and the member page explains both lines (§9).
- **A cancelled order does not count.** The close query excludes `status = 'cancelled'`; a cancellation
  before the close therefore lowers the month's total.
- **A closed period is never rewritten**, even when an order from that month is cancelled later (§14.9).
  Reopening a month is not supported.
- **Brand-new members are Active** until their first close (no row → Active), matching the simulator.
- **The rule applies to levels 1–10.** There is no level-0 exception because there is no level 0. An
  operator who wants to pay inactive uplines can set `unilevel_requires_active_upline = 0`; that relaxes
  the monthly gate, not the account/membership checks.

**Phase.** The resolution and `close()` are 17C; the scheduled job is 17F.

### 12.8 Rounding and money rules

- Every percentage is applied **per level, per line**, then rounded to centavos:
  `amount = round(base × percent / 100, 2)` with PHP's half-away-from-zero rounding.
- The implementation works in integer centavos (`(int) round($base * 100)`) to avoid float drift, then
  divides once at the end. The rate is stored as `DECIMAL(6,3)` (thousandths of a percent).
- **There is no rounding on the sum.** Ten rounded amounts can differ by a few centavos from
  `total_percent × base`; the reference document shows the same behaviour at peso granularity (§7.2). The
  app's centavo example is in §12.10 B2.
- Money is `DECIMAL(12,2)` in MySQL, formatted with `money()` in views. No floats in SQL, ever.
- An award amount of `0.00` is never written: a rate of 0 or a base of 0 produces no row.

**Phase.** 17C.

### 12.9 Ceilings and validation

| Guard | Rule | Where | Phase |
|---|---|---|---|
| Per-level rate | levels 1–10: each 0.000 – 100.000 % | `ProductUnilevel::validateLevels()` | 17B |
| Ladder sum | levels 1–10 total ≤ 100.000 % | `ProductUnilevel::validate()` (`sum` error) | 17B |
| Rate precision | at most 3 decimals | `decimal:3` rule | 17B |
| Enabled + empty | enabling a product whose ten rows are all 0 is an error ("this product would pay nothing") | `ProductUnilevel::validate()` | 17B |
| P.V. base | `unilevel_base = 'pv'` requires `pv > 0` | `Product::validate()` | 17B |
| Per-award amount | ≤ `base` × 1.00 by construction; no explicit cap needed | engine | 17C |
| Order total | `shop_orders.unilevel_total` ≤ order subtotal × (sum of the highest ladders) — reported, not enforced | reconcile | 17C |
| Depth | `unilevel_max_levels` 1–10 | settings card | 17B |

### 12.10 Worked examples

**A. The reference ladder, one line, full ten-level chain.** Line base ₱10,000; levels 1–10
5/5/5/3/3/2/2/2/1/1:

| Level | Recipient | Rate | Amount |
|---:|---|---:|---:|
| 1 | sponsor | 5% | ₱500.00 |
| 2 | upline 2 | 5% | ₱500.00 |
| 3 | upline 3 | 5% | ₱500.00 |
| 4 | upline 4 | 3% | ₱300.00 |
| 5 | upline 5 | 3% | ₱300.00 |
| 6 | upline 6 | 2% | ₱200.00 |
| 7 | upline 7 | 2% | ₱200.00 |
| 8 | upline 8 | 2% | ₱200.00 |
| 9 | upline 9 | 1% | ₱100.00 |
| 10 | upline 10 | 1% | ₱100.00 |
| | **total** | **29%** | **₱2,900.00** |

**B. Base ₱555.** Levels 1–10: 27.75 + 27.75 + 27.75 + 16.65 + 16.65 + 11.10 + 11.10 + 11.10 + 5.55 +
5.55 = **₱160.95**. At the app's centavo precision the result is the nominal 29% because every amount
lands on a centavo. The reference document's ₱219 is the same purchase **including its 10% level-0
rebate** (₱55.50 → ₱56 at whole-peso rounding) plus whole-peso rounding of the upline cuts; it is not the
app's number.

**B2. Base ₱555.55 — per-level rounding.** Level 1 5% = ₱27.7775 → ₱27.78; the ten levels total
**₱161.13**, while the nominal 29% is ₱161.1095 → ₱161.11. The two-centavo difference is per-level
rounding and is pinned by a test.

**C. Four sponsors, level 2 inactive.** Base ₱500, reference rates, chain of four (`c → b → a → boss`),
`b` Inactive:

| Level | Recipient | Rate | Decision |
|---:|---|---:|---|
| 1 | c | 5% | credited ₱25.00 |
| 2 | b | 5% | **skipped** (₱25.00 not paid, level consumed) |
| 3 | a | 5% | credited ₱25.00 |
| 4 | boss | 3% | credited ₱15.00 |
| 5–10 | — | — | no chain left |

Credited ₱65.00, skipped ₱25.00. If upline `a` had rate 0, it would still be level 3 (no row), and `boss`
would still be paid at level 4's 3% — no compression.

**D. Two products in one order.** Coffee `2 × (price ₱300, P.V. ₱250)` → base ₱500; Immune Booster
`1 × (price ₱500, P.V. 0)` → base ₱500. Coffee's reference ladder pays ₱90 on the four-deep chain (₱25 +
₱25 + ₱25 + ₱15); Immune Booster pays a 10% level-1 rate only (₱50). Each line is awarded separately from
its own ladder; the same upline can receive two awards (one per line) from the same order.

**E. The monthly gate.** Threshold ₱500.

| Month | Member buys | Qualified for next month? | Pays received |
|---|---:|---|---|
| Oct | ₱450 | **no** | November purchases pay them nothing |
| Nov 2 | ₱2,000 | yes, for December | November receipts stay blocked; December receipts resume |
| Dec | ₱0 | no, for January | — |

Before the first close of a period, a member with no activity row is treated as Active (the "missing
flag" rule), so a fresh install does not silently block everyone.

**F. Switches and depth.** Four-deep chain, base ₱500, reference rates:
- Global master off, or product master off → **no rows**, but the order is still stamped as processed.
- `unilevel_max_levels = 1` → only level 1, **₱25.00**.
- `unilevel_max_levels = 3` → levels 1–3, **₱75.00**.
- Level 1 rate 0 → no level-1 row; levels 2–4 still pay ₱25 + ₱25 + ₱15 = **₱65.00** (no compression).

**G. Ten sponsors, base ₱500.** Levels 1–10 pay 25 + 25 + 25 + 15 + 15 + 10 + 10 + 10 + 5 + 5 = **₱145.00**
(29%), ten rows.

**Phase.** Worked in 17C's tests; shown in 17D/17E's pages.

### 12.11 What does not affect the bonus

- The buyer's rank, Harvest qualification, team size, direct count or sponsor.
- The buyer's Active flag, membership status or package: **the buyer is not a recipient**. Their own
  purchase still counts toward their next-month Active flag.
- The month boundary at payment time — the rate does not change with the month; only the Active gate
  references the previous month.
- The payment method, the delivery address, the shipping fee, the order's later fulfilment status.
- The product's category, stock level or `sort_order`; an inactive product still pays for an order placed
  while it was active (the line is frozen).
- The number of orders: every paid order pays again (reference §7.8: "no cap on purchases").

---

## 13. Data model

### 13.1 Entity relationship

```
users ──1:n── shop_orders ──1:n── shop_order_items ──1:n── unilevel_awards
  │                │                    │                        │
  │                │                    └── products ──1:n── product_unilevel_levels
  │                │                                             (levels 1…10, percent)
  │                └── unilevel_processed_at / unilevel_total
  │
  ├──1:n── unilevel_awards (user_id = recipient, buyer_id = source)
  ├──1:n── unilevel_activity (period, personal_purchases, qualified)
  └──1:n── unilevel_periods  (period, closed_at, counts)   [closed_by → users]

ewallet_ledger ── ref_type IN ('unilevel','unilevel_reversal'), ref_id = unilevel_awards.id
```

### 13.2 New tables

#### `product_unilevel_levels`

The per-product ladder, levels 1–10. Missing rows are zero (the model zero-fills), exactly like
`package_levels`.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `INT UNSIGNED` | no | auto | PK |
| `product_id` | `INT UNSIGNED` | no | — | FK → `products.id`, `ON DELETE CASCADE` |
| `level` | `TINYINT UNSIGNED` | no | — | 1–10 |
| `percent` | `DECIMAL(6,3)` | no | `0.000` | 0.000 – 100.000 |

Keys: `UNIQUE (product_id, level)`, `CHECK (level BETWEEN 1 AND 10)`,
`CHECK (percent BETWEEN 0 AND 100)`.

#### `unilevel_awards`

One row per `(order line, recipient, level)` decision — the money ledger for this stream. There is no
`type` column: the level is the whole story (D-17).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | no | auto | PK; also the wallet `ref_id` |
| `order_id` | `INT UNSIGNED` | no | — | FK → `shop_orders.id`, `ON DELETE CASCADE` |
| `order_item_id` | `INT UNSIGNED` | no | — | FK → `shop_order_items.id`, `ON DELETE CASCADE` |
| `product_id` | `INT UNSIGNED` | no | — | FK → `products.id`, `ON DELETE RESTRICT` |
| `buyer_id` | `INT UNSIGNED` | no | — | the order's member (source of the purchase) |
| `user_id` | `INT UNSIGNED` | no | — | the recipient |
| `level` | `TINYINT UNSIGNED` | no | — | 1–10 |
| `base_amount` | `DECIMAL(12,2)` | no | — | the line base the rate was applied to |
| `base_source` | `ENUM('line','product')` | no | `line` | whether the base came from the line snapshot or the live product |
| `rate_pct` | `DECIMAL(6,3)` | no | — | the ladder rate **at award time** (snapshot) |
| `amount` | `DECIMAL(12,2)` | no | — | the computed amount; a zero amount is never inserted, so every row (credited or skipped) is > 0 |
| `status` | `ENUM('credited','skipped','voided')` | no | `credited` | `skipped` = due but not paid; `voided` = paid then reversed |
| `reason` | `VARCHAR(120)` | yes | `NULL` | why a level was skipped |
| `ledger_id` | `INT UNSIGNED` | yes | `NULL` | the `ewallet_ledger.id` credited (never a FK) |
| `reversal_ledger_id` | `INT UNSIGNED` | yes | `NULL` | the reversal debit, when one happened |
| `reversed_amount` | `DECIMAL(12,2)` | no | `0.00` | how much of `amount` was actually clawed back |
| `paid_at` | `DATETIME` | no | — | the order's `paid_at` (the period key) |
| `voided_at` | `DATETIME` | yes | `NULL` | when the award was voided |
| `void_reason` | `VARCHAR(160)` | yes | `NULL` | the cancellation reason |
| `created_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP` | row write time |

Keys: `UNIQUE (order_item_id, user_id, level)` — the money anchor; `KEY (user_id, created_at)`,
`(order_id)`, `(buyer_id, created_at)`, `(product_id, created_at)`, `(status, created_at)`, `(paid_at)`,
`(ledger_id)`.

Checks: `level BETWEEN 1 AND 10`, `amount >= 0`, `0 <= reversed_amount <= amount`.

#### `unilevel_activity`

The per-member, per-period **snapshot** written by the month close — never by a purchase.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `period` | `CHAR(7)` | no | — | `YYYY-MM`, Asia/Manila |
| `user_id` | `INT UNSIGNED` | no | — | FK → `users.id`, `ON DELETE CASCADE` |
| `personal_purchases` | `DECIMAL(12,2)` | no | `0.00` | Σ paid order line totals in that period |
| `qualified` | `TINYINT(1)` | no | `0` | 1 when `personal_purchases >= threshold` |
| `evaluated_at` | `DATETIME` | no | — | when the close wrote the row |

Primary key `(period, user_id)`; `KEY (user_id, period)`;
`CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')`. **No auto-increment column.**

#### `unilevel_periods`

One row per **closed** month — the header that makes a period authoritative and immutable.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `period` | `CHAR(7)` | no | — | PK, `YYYY-MM` |
| `closed_at` | `DATETIME` | no | — | when the job/admin closed it |
| `closed_by` | `INT UNSIGNED` | yes | `NULL` | staff id, `NULL` = system/cron |
| `threshold` | `DECIMAL(12,2)` | no | — | the threshold in force at close (audit) |
| `members_total` | `INT UNSIGNED` | no | `0` | rows written |
| `qualified_count` | `INT UNSIGNED` | no | `0` | members that cleared the threshold |
| `award_total` | `DECIMAL(12,2)` | no | `0.00` | credited repeat-purchase money for that period |

**No auto-increment column.**

### 13.3 Altered columns

| Table | Change | Why |
|---|---|---|
| `products` | `+ unilevel_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pv` | per-product master switch |
| `products` | `+ unilevel_base ENUM('auto','price','pv') NOT NULL DEFAULT 'auto' AFTER unilevel_enabled` | explicit base choice |
| `shop_orders` | `+ unilevel_processed_at DATETIME NULL AFTER paid_at` | idempotency stamp |
| `shop_orders` | `+ unilevel_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unilevel_processed_at` | order-level award total for the admin UI/reports |
| `shop_orders` | `+ KEY idx_orders_unilevel (unilevel_processed_at)`, `+ KEY idx_orders_user_paid (user_id, paid_at)` | close/report scans, period aggregates |
| `shop_order_items` | `+ unit_base DECIMAL(12,2) NULL AFTER line_total` | placement snapshot of the bonus base per unit |
| `shop_order_items` | `+ base_total DECIMAL(12,2) NULL AFTER unit_base` | placement snapshot of the line base |
| `ewallet_ledger` | `ref_type` ENUM extended with `unilevel`, `unilevel_reversal` | wallet attribution and reconciliation |

`unit_base`/`base_total` stay `NULL` for lines created before the migration; the engine then derives the
base from the product and records `base_source = 'product'`. The columns stay nullable for ever so a
historical line never needs a rewrite.

### 13.4 Migration `020_unilevel_repeat_purchase.sql`

Idempotent and re-runnable, in the style of
[`011_membership.sql`](../system/database/migrations/011_membership.sql): every `ALTER` is guarded by an
`information_schema` probe and a `PREPARE`/`EXECUTE` pair, so a re-run is a no-op. The file ends with the
settings insert and a baseline marker for reconciliation.

```sql
-- =============================================================
--  Phase 17 migration — unilevel repeat-purchase bonus
--
--  Applies to a Phase 16 database and adds:
--
--    product_unilevel_levels   per-product ladder (levels 1…10)
--    unilevel_awards           the repeat-purchase money ledger
--    unilevel_activity         the per-period Active snapshot
--    unilevel_periods          the closed-month header
--
--    products.unilevel_enabled / unilevel_base
--    shop_orders.unilevel_processed_at / unilevel_total
--    shop_order_items.unit_base / base_total
--    ewallet_ledger.ref_type  + 'unilevel', 'unilevel_reversal'
--
--    six settings keys (the master switch is off by default)
--
--  No backfill: orders paid before this migration are never awarded
--  (plan D-16). `unilevel_ledger_since` marks the instant the stream
--  may pay, so UnilevelService::reconcile() can separate legacy rows.
--
--      mysql -u root <db> < database/migrations/020_unilevel_repeat_purchase.sql
--
--  No `down` note: the tables and columns are additive. Dropping them
--  would erase the record of every repeat-purchase award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. products — the per-product switch and base ----------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'unilevel_enabled');
SET @sql := IF(@has = 0,
  'ALTER TABLE products ADD COLUMN unilevel_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pv',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'unilevel_base');
SET @sql := IF(@has = 0,
  "ALTER TABLE products ADD COLUMN unilevel_base ENUM('auto','price','pv') NOT NULL DEFAULT 'auto' AFTER unilevel_enabled",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. shop_order_items — the placement snapshot ----------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'unit_base');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN unit_base DECIMAL(12,2) NULL AFTER line_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'base_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN base_total DECIMAL(12,2) NULL AFTER unit_base',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. shop_orders — the idempotency stamp and the order total ----
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'unilevel_processed_at');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN unilevel_processed_at DATETIME NULL AFTER paid_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'unilevel_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN unilevel_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unilevel_processed_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_unilevel');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_unilevel (unilevel_processed_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_user_paid');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_user_paid (user_id, paid_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. product_unilevel_levels — the ladder (1…10) ---------------
CREATE TABLE IF NOT EXISTS product_unilevel_levels (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED     NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,
  percent    DECIMAL(6,3)     NOT NULL DEFAULT 0.000,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_unilevel (product_id, level),
  CONSTRAINT fk_pul_product  FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT chk_pul_level   CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_pul_percent CHECK (percent >= 0 AND percent <= 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. unilevel_awards — the money ledger -------------------------
CREATE TABLE IF NOT EXISTS unilevel_awards (
  id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  order_id           INT UNSIGNED     NOT NULL,
  order_item_id      INT UNSIGNED     NOT NULL,
  product_id         INT UNSIGNED     NOT NULL,
  buyer_id           INT UNSIGNED     NOT NULL,
  user_id            INT UNSIGNED     NOT NULL,
  level              TINYINT UNSIGNED NOT NULL,
  base_amount        DECIMAL(12,2)    NOT NULL,
  base_source        ENUM('line','product') NOT NULL DEFAULT 'line',
  rate_pct           DECIMAL(6,3)     NOT NULL,
  amount             DECIMAL(12,2)    NOT NULL,
  status             ENUM('credited','skipped','voided') NOT NULL DEFAULT 'credited',
  reason             VARCHAR(120)     NULL,
  ledger_id          INT UNSIGNED     NULL,
  reversal_ledger_id INT UNSIGNED     NULL,
  reversed_amount    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  paid_at            DATETIME         NOT NULL,
  voided_at          DATETIME         NULL,
  void_reason        VARCHAR(160)     NULL,
  created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_unilevel_award (order_item_id, user_id, level),
  KEY idx_unilevel_user (user_id, created_at),
  KEY idx_unilevel_order (order_id),
  KEY idx_unilevel_buyer (buyer_id, created_at),
  KEY idx_unilevel_product (product_id, created_at),
  KEY idx_unilevel_status (status, created_at),
  KEY idx_unilevel_paid (paid_at),
  KEY idx_unilevel_ledger (ledger_id),
  CONSTRAINT fk_ua_order     FOREIGN KEY (order_id)      REFERENCES shop_orders (id)      ON DELETE CASCADE,
  CONSTRAINT fk_ua_item      FOREIGN KEY (order_item_id) REFERENCES shop_order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_ua_product   FOREIGN KEY (product_id)    REFERENCES products (id)         ON DELETE RESTRICT,
  CONSTRAINT fk_ua_buyer     FOREIGN KEY (buyer_id)      REFERENCES users (id)            ON DELETE CASCADE,
  CONSTRAINT fk_ua_recipient FOREIGN KEY (user_id)       REFERENCES users (id)            ON DELETE CASCADE,
  CONSTRAINT chk_ua_level    CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_ua_amount   CHECK (amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= amount)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 6. unilevel_activity — the close snapshot ---------------------
CREATE TABLE IF NOT EXISTS unilevel_activity (
  period             CHAR(7)       NOT NULL,
  user_id            INT UNSIGNED  NOT NULL,
  personal_purchases DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  qualified          TINYINT(1)    NOT NULL DEFAULT 0,
  evaluated_at       DATETIME      NOT NULL,
  PRIMARY KEY (period, user_id),
  KEY idx_unilevel_activity_user (user_id, period),
  CONSTRAINT fk_ua_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_ua_period CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 7. unilevel_periods — the closed-month header -----------------
CREATE TABLE IF NOT EXISTS unilevel_periods (
  period          CHAR(7)       NOT NULL,
  closed_at       DATETIME      NOT NULL,
  closed_by       INT UNSIGNED  NULL,
  threshold       DECIMAL(12,2) NOT NULL,
  members_total   INT UNSIGNED  NOT NULL DEFAULT 0,
  qualified_count INT UNSIGNED  NOT NULL DEFAULT 0,
  award_total     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (period),
  CONSTRAINT fk_up_closer FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_up_period CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 8. ewallet_ledger.ref_type — the two new movement kinds -------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type' AND COLUMN_TYPE LIKE '%unilevel%');
SET @sql := IF(@has = 0,
  'ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM(''commission'',''payout'',''transfer'',''topup'',''registration'',
          ''shop_order'',''adjustment'',''refund'',''unilevel'',''unilevel_reversal'') NOT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. settings — every stream off until an operator turns it on ---
INSERT INTO settings (key_name, value) VALUES
  ('unilevel_enabled',                 '0'),
  ('unilevel_max_levels',              '10'),
  ('unilevel_requires_active_upline',  '1'),
  ('unilevel_active_threshold',        '500'),
  ('unilevel_reversal_enabled',        '1'),
  ('unilevel_ledger_since',            DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE key_name = key_name;
```

### 13.5 Settings

| Key | Default | Exposed as | Behaviour | Phase |
|---|---|---|---|---|
| `unilevel_enabled` | `0` | switch — **Repeat-purchase bonus enabled** | global master; `0` writes no award but still stamps the order as processed | 17A/17B |
| `unilevel_max_levels` | `10` | number — **Upline depth** (1–10) | caps the walk (`N` in §12.5) | 17A/17B |
| `unilevel_requires_active_upline` | `1` | switch — **Require the monthly Active rule** | when `0`, uplines are paid without the monthly check (account/membership checks still apply) | 17A/17B |
| `unilevel_active_threshold` | `500` | number — **Monthly personal-purchase minimum** (₱) | the Active gate of §12.7 | 17A/17B |
| `unilevel_reversal_enabled` | `1` | switch — **Reverse awards when a paid order is cancelled** | §14.9 | 17A/17B |
| `unilevel_ledger_since` | now | not shown | baseline for `reconcile()` (mirrors `commission_ledger_since`) | 17A |

They live in the existing **Commissions** card of `/admin/settings`, in a "Repeat-purchase bonus"
sub-block, so the compensation switches stay on one page and the section key remains `commissions` (no
new settings section, no new route).

### 13.6 Fresh-install mirror (`database/schema.sql`)

`database/schema.sql` is the fresh-install source of truth; the migration and the schema must stay
byte-for-byte equivalent in effect (the `MigrationTest` compares a migrated database with a fresh one).
Edits:

| Location in `schema.sql` | Edit |
|---|---|
| `products` block (L160–L179) | add `unilevel_enabled`, `unilevel_base`; rewrite the `pv` comment (no longer reserved) |
| after the `products` block (before `packages`, L181) | add `product_unilevel_levels` (levels 1–10) |
| `shop_order_items` block (L513–L529) | add `unit_base`, `base_total` |
| `shop_orders` block (L461–L507) | add `unilevel_processed_at`, `unilevel_total`, `idx_orders_unilevel`, `idx_orders_user_paid` |
| after `shop_order_items` (L529) | add `unilevel_awards` (it references `shop_orders` and `shop_order_items`) |
| after `commissions` (L734–L759, before `impersonation_log`) | add `unilevel_activity`, `unilevel_periods` |
| `ewallet_ledger.ref_type` (L306) | extend the ENUM; update the comment above it |
| `seeds/001_settings.sql` | add the six keys |
| `install/footer.sql` (L28–L35) | the expected-counts comment: `31 tables, 54 settings` |
| rebuild | `php bin/build_install_sql.php` then `php bin/build_install_sql.php --check` |

### 13.7 Invariants

These are asserted by `UnilevelService::reconcile()`, the reset integrity pack and the tests.

1. `SUM(unilevel_awards.amount WHERE status='credited')` = `SUM(ewallet_ledger.amount WHERE ref_type='unilevel')`
   (from `unilevel_ledger_since` onwards).
2. Every `credited` award has a non-null `ledger_id` pointing at an existing ledger row; every
   `ref_type='unilevel'` ledger row points at an existing award.
3. No award's ledger amount differs from `unilevel_awards.amount`.
4. `shop_orders.unilevel_total` = `SUM(amount)` of that order's `credited` awards.
5. Every order whose `paid_at >= unilevel_ledger_since`, whose status is in `Order::PAID_STATUSES` and
   which carries a `shop_order_events` row with `to_status = 'paid'` (i.e. it was paid through the app,
   not created by a fixture) has `unilevel_processed_at IS NOT NULL` (nothing was missed).
6. When `unilevel_reversal_enabled = 1`, every credited award of a `cancelled` order is `voided` (a
   credited award on a cancelled order means a reversal was missed).
7. Every award's `level` is 1–10; a `level = 1` row's recipient is the buyer's current direct sponsor;
   **no row's recipient is the buyer**.
8. `unilevel_activity` rows exist only for periods present in `unilevel_periods` (the close writes both
   in one transaction).
9. `rate_pct` never exceeds 100.000, and a product's ladder sum (levels 1–10) never exceeds 100.000.

**Auto-increment note.** `unilevel_awards` has an auto-increment `id`; `unilevel_activity` and
`unilevel_periods` do **not** (their primary keys are natural). `DatabaseResetService::AUTO_INCREMENT_TABLES`
therefore gains `unilevel_awards` only — an earlier draft listed all three, which would reset a sequence
that does not exist.

### 13.8 Volume and retention

| Object | Growth | Notes |
|---|---|---|
| `unilevel_awards` | ≤ 10 rows per paid line; typically 0–30 rows per three-line order | ~30k rows/month at 1,000 three-line orders with a 10-deep chain; BIGINT PK, indexed by recipient, buyer, product, order |
| `unilevel_activity` | members × 1 per month | 5,000 members → 60k rows/year |
| `unilevel_periods` | 1 row/month | negligible |
| `product_unilevel_levels` | 10 rows per configured product | rewrite-on-save, like `package_levels` |

No pruning is needed in v1. Awards are money history and are never deleted; a cancelled order voids them
instead (the rows stay).

---

## 14. The money path

### 14.1 Components

| Class | File | Responsibility | Phase |
|---|---|---|---|
| `ProductUnilevel` | `system/app/Models/ProductUnilevel.php` | ladder read/write, zero-fill, validation, reference preset | 17B |
| `UnilevelAward` | `system/app/Models/UnilevelAward.php` | award rows: create, link, void, filters, pages, totals, breakdowns, export | 17C |
| `UnilevelCycle` | `system/app/Models/UnilevelCycle.php` | periods: derive, close, activity snapshot, Active resolution | 17C |
| `UnilevelService` | `system/app/Services/UnilevelService.php` | the engine: `onOrderPaid`, `quoteLine`, `reverseForOrder`, `reconcile` | 17C |
| `UnilevelException` | `system/app/Services/UnilevelException.php` | typed failures (`not_found`, `not_paid`, `state`) — same shape as `OrderException` | 17C |

No controller is added. The existing controllers compose the new models (D-14).

### 14.2 Public API

```php
final class UnilevelService
{
    public function __construct(?PDO $pdo = null);

    /** The one hook. Runs inside the caller's transaction. Idempotent. */
    public function onOrderPaid(int $orderId, ?PDO $pdo = null): array;

    /** Preview for a product page / checkout (no writes). */
    public function quoteLine(int $productId, int $quantity): array;
    public function quoteCart(array $lines): array;   // batched: one ladder query for the whole cart

    /** Best-effort clawback for a paid order that is being cancelled. */
    public function reverseForOrder(int $orderId, string $reason, ?PDO $pdo = null): array;

    /** Member totals for /earnings. */
    public function totalsFor(int $userId, ?PDO $pdo = null): array;

    /** Finance reconciliation, same contract as CommissionService::reconcile(). */
    public function reconcile(): array;

    public static function enabled(): bool;   // settings.unilevel_enabled
}
```

`onOrderPaid()` returns:

```php
[
  'order_id'  => 41,
  'processed' => true,          // false when disabled / already done / not paid
  'replayed'  => false,
  'awards'    => [ /* rows */ ],
  'credited'  => 90.00,
  'skipped'   => 0,
  'levels'    => 10,
]
```

`ProductUnilevel`:

```php
public static function levels(int $productId): array;              // [1..10 => float] zero-filled
public static function levelsMap(array $productIds): array;        // batched, no N+1
public static function save(int $productId, array $levels, bool $enabled, string $base): void;
public static function validateLevels(array $levels): array;       // field => message ("level_1"…"level_10")
public static function validate(array $data, array $levels, ?int $productId = null): array;
public static function referencePreset(): array;                   // 5,5,5,3,3,2,2,2,1,1
public static function forForm(int $productId): array;             // strings, blanks for 0
public static function totalsMap(array $productIds): array;        // [id => total percent]
public static function baseFor(array $product, int $quantity): float;
```

`UnilevelCycle`:

```php
public static function periodOf(?string $datetime = null): string;        // 'YYYY-MM' (Asia/Manila)
public static function previous(string $period): string;
public static function bounds(string $period): array;                     // [$start, $end) as 'Y-m-d H:i:s'
public static function isClosed(string $period): bool;
public static function isActive(int $userId, string $period): bool;       // §12.7 resolution
public static function activeMap(array $userIds, string $period): array;  // [id => bool], batched
public static function personalPurchases(int $userId, string $period): float;
public static function close(string $period, ?int $closedBy = null): array;
public static function pendingPeriods(): array;
public static function lastClosed(): ?string;
public static function periods(int $limit = 6): array;              // newest first, for the admin card
public static function activityFor(int $userId, int $months = 3): array;
```

### 14.3 The award algorithm

```php
// $this->pdo, $this->wallet (WalletService) and $this->commissions
// (CommissionService) all share the caller's connection; $this->companyId
// is Member::primaryAdmin()?->id resolved once per call.
public function onOrderPaid(int $orderId, ?PDO $pdo = null): array
{
    // The caller owns a transaction (e-wallet placement or payment verification).
    // Re-lock the order: two concurrent verifications must have one winner.
    $order = SELECT * FROM shop_orders WHERE id = ? FOR UPDATE;
    if ($order === null)              throw UnilevelException::notFound();
    if (!Order::isPaid($order))       return ['processed' => false, 'reason' => 'not paid'];
    if ($order['unilevel_processed_at'] !== null) {
        return ['processed' => false, 'replayed' => true];
    }

    $items = SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id;

    // The kill switch still stamps the order: "decided, nothing due".
    if (!self::enabled() || $items === []) {
        stamp($orderId, 0.00);
        return ['processed' => true, 'credited' => 0.0, 'awards' => []];
    }

    $period  = UnilevelCycle::periodOf($order['paid_at']);
    $buyerId = (int) $order['user_id'];

    // One chain for the whole order; the buyer is the same for every line.
    $maxLevels = max(1, min(10, setting_int('unilevel_max_levels', 10)));
    $chain     = $this->commissions->sponsorChain($buyerId, $maxLevels, $this->pdo);

    $productIds = array_unique(column($items, 'product_id'));
    $products   = Product::rowsFor($productIds);           // one query (name, pv, price, enabled, base)
    $ladders    = ProductUnilevel::levelsMap($productIds); // one query (levels 1..10)

    // Batched Active/eligibility resolution for every possible recipient.
    $chainIds = array_map(static fn (array $hop): int => (int) $hop['member']['id'], $chain);
    $eligible = $this->eligibilityMap($chainIds, $chain, $period);

    $drafts = [];

    foreach ($items as $item) {
        $p = $products[(int) $item['product_id']] ?? null;
        if ($p === null || (int) $p['unilevel_enabled'] !== 1) {
            continue;
        }

        $base = $item['base_total'] !== null
            ? (float) $item['base_total']
            : ProductUnilevel::baseFor($p, (int) $item['quantity']);  // legacy line
        $baseSource = $item['base_total'] !== null ? 'line' : 'product';

        if ($base <= 0) {
            continue;
        }

        $ladder = $ladders[(int) $p['id']];

        for ($level = 1; $level <= count($chain); $level++) {
            $rate = (float) ($ladder[$level] ?? 0);
            if ($rate <= 0) {
                continue;                          // no row, level consumed
            }

            $hop = $chain[$level - 1];             // sponsorChain() is 1-based and contiguous
            $drafts[] = draft(
                $item, (int) $hop['member']['id'], $level,
                $rate, $base, $baseSource,
                $eligible[(int) $hop['member']['id']] ?? 'recipient not found',
                (string) $p['name']
            );
        }
    }

    // Deterministic wallet lock order: ascending recipient, then item, then level.
    usort($drafts, fn ($a, $b) => [$a['user_id'], $a['order_item_id'], $a['level']]
                               <=> [$b['user_id'], $b['order_item_id'], $b['level']]);

    $credited = 0.0;

    foreach ($drafts as $d) {
        $amount = round(($d['base'] * $d['rate']) / 100, 2);

        if ($amount <= 0) {
            continue;
        }

        if ($d['reason'] !== '') {
            UnilevelAward::create($d + ['amount' => $amount, 'status' => 'skipped', 'reason' => $d['reason']]);
            continue;
        }

        $awardId  = UnilevelAward::create($d + ['amount' => $amount, 'status' => 'credited']);
        $movement = $this->wallet->credit(
            $d['user_id'], $amount, 'unilevel', $awardId,
            $this->note($d, $order), true
        );
        UnilevelAward::linkLedger($awardId, (int) $movement['ledger_id']);
        $credited += $amount;
    }

    stamp($orderId, $credited);   // unilevel_processed_at = NOW(), unilevel_total = $credited

    Live::touch('admin.commissions');
    foreach (array_unique(array_column($drafts, 'user_id')) as $id) {
        Live::touchEarnings($id);
    }
    Live::touchOrder($orderId, $buyerId);

    return [
        'processed' => true,
        'credited'  => round($credited, 2),
        'skipped'   => count(array_filter($drafts, static fn (array $d): bool => $d['reason'] !== '')),
        'awards'    => ...,
    ];
}
```

Implementation notes:

- `base_total` is stored on the line at placement, so the same query/rounding works for e-wallet and
  manual payments.
- A draft carries `order_item_id`, `product_id`, `product_name`, `buyer_id`, `user_id`, `level`, `base`,
  `rate`, `base_source`, `paid_at` (the order's `paid_at`, which is also the period key) and `reason`;
  `UnilevelAward::create()` accepts exactly that shape.
- `eligibilityMap()` batches the previous-period lookup and applies `recipientBlockReason()` (company
  account, `is_active`, `member_status`) for all ten possible recipients in one pass, so the per-level
  loop performs no queries. The buyer is **not** in the map.
- `$this->commissions` is a `CommissionService` built on the same connection, so the chain walk is the
  already-tested one (cycle guard, company root, ten-level cap) rather than a second implementation.
- The loop is the same for every level: there is no separate direct leg to draft first (D-17). Level 1 is
  simply `$chain[0]`.
- `ProductUnilevel::baseFor()` is the one place that implements the `auto|price|pv` rule, so the placement
  snapshot and the legacy fallback cannot disagree.
- `OrderService::lockProducts()` must add `pv, unilevel_enabled, unilevel_base` to its `SELECT`, and
  `insertItems()` must write `unit_base` + `base_total` through `ProductUnilevel::baseFor()`, so every
  post-migration line carries the snapshot the engine reads.
- `stamp()` runs last: if any insert or wallet credit throws, the caller's rollback removes the awards
  **and** the stamp, leaving the order retryable.

### 14.4 Idempotency

| Layer | Anchor | Guarantees |
|---|---|---|
| Order stamp | `shop_orders.unilevel_processed_at` | a second `onOrderPaid()` returns `replayed` and writes nothing |
| Pre-check | `UnilevelAward::forItem($orderItemId, $userId, $level)` | a retried in-transaction write returns the existing row |
| Database | `UNIQUE (order_item_id, user_id, level)` | even a racing duplicate cannot insert twice |
| Wallet | `UNIQUE (user_id, ref_type, ref_id, type)` in `ewallet_ledger`; `ref_id = award.id` | a replayed credit returns the original movement (the idempotency check is [`WalletService::apply()`](../system/app/Services/WalletService.php#L496-L513)) |

The e-wallet path cannot double-pay because the order insert and the hook share one transaction; the manual
path cannot because `PaymentService::verify()` refuses a status that is not `payment_review` and the order
row is locked first.

### 14.5 Transaction boundary and failure policy

- The hook **never opens its own transaction** when one exists. `OrderService` and `PaymentService` already
  own one; `UnilevelService` uses it so the awards, the wallet credits, the stamp and the order status
  commit or roll back together.
- If a wallet credit fails (database error, invariant violation), the exception propagates: the checkout
  rolls back (no order) or the verification fails and the admin can retry. This is the same contract as
  `CommissionService`. Silently swallowing the error and writing a `skipped` row would lose money the plan
  owes.
- `Live::touch*()` calls run after the writes; they are already tolerant of a missing revision row.
- The hook logs one `error_log` line when it skips for a structural reason (no items, disabled stream,
  missing product row) so a support engineer can explain a zero payout.

### 14.6 Concurrency and lock order

| Resource | Lock | Order |
|---|---|---|
| `shop_orders` row | `SELECT … FOR UPDATE` | first |
| `shop_order_items` | read after the order lock | — |
| `products` | read only (placement already held their locks) | — |
| `product_unilevel_levels` | read only | — |
| `users` (wallet) | `SELECT … FOR UPDATE` inside `WalletService::credit()` | **ascending `user_id`** across the whole order |

Crediting in ascending recipient order is what prevents two concurrent orders that share uplines from
deadlocking (the same technique `OrderService::lockProducts()` uses for products). The sort is explicit,
not incidental.

### 14.7 Performance budget

| Step | Queries | Notes |
|---|---|---|
| Order lock + items | 2 | primary key / `idx_order_items_order` |
| Sponsor chain | ≤ 10 | existing `sponsorChain()`, one member read per hop |
| Products + ladders | 2 | batched `IN (…)` |
| Active map | 1–2 | closed period → one snapshot query; open period → one aggregate over `idx_orders_user_paid` |
| Award inserts | ≤ 10 × lines | one prepared statement, executed per row |
| Wallet credits | 1 per credited row | unavoidable: one ledger row each |
| Stamp + live | 2 | — |

Worst case for a three-line order with a full chain: ~6 read queries + 30 inserts/credits. That is
comparable to the existing activation commission path (11 awards per activation) and runs once per paid
order, not per page view. The product page's `quoteLine()` is two indexed reads.

### 14.8 The month close

```php
public static function close(string $period, ?int $closedBy = null): array
{
    // 1. Validate + guard: a closed period is immutable.
    if (self::isClosed($period)) return ['skipped' => true, 'reason' => 'already closed'];
    if ($period > self::periodOf()) throw new InvalidArgumentException('period is in the future');

    [$start, $end] = self::bounds($period);
    $threshold     = round(setting_float('unilevel_active_threshold', 500.0), 2);

    // 2. One statement writes one row per account (buyers and non-buyers):
    //    the aggregate is authoritative, the accounts give the flag.
    INSERT INTO unilevel_activity (period, user_id, personal_purchases, qualified, evaluated_at)
    SELECT ?, u.id, COALESCE(t.spent, 0.00), (COALESCE(t.spent, 0.00) >= ?), NOW()
      FROM users u
      LEFT JOIN (
            SELECT o.user_id, SUM(oi.line_total) AS spent
              FROM shop_orders o
              JOIN shop_order_items oi ON oi.order_id = o.id
             WHERE o.paid_at >= ? AND o.paid_at < ?
               AND o.status <> 'cancelled'
             GROUP BY o.user_id
      ) t ON t.user_id = u.id
     ORDER BY u.id;

    // 3. The header, last: the period becomes "closed" only when its rows exist.
    INSERT INTO unilevel_periods
        (period, closed_at, closed_by, threshold, members_total, qualified_count, award_total)
    SELECT ?, NOW(), ?, ?, COUNT(*),
           SUM(qualified),
           (SELECT COALESCE(SUM(amount), 0) FROM unilevel_awards
             WHERE status = 'credited' AND paid_at >= ? AND paid_at < ?)
      FROM unilevel_activity WHERE period = ?;

    return ['closed' => true, ...];
}
```

- **All accounts get a row**, including members with zero purchases, so after the first close every account
  has an explicit `qualified = 0/1` (this is what makes a brand-new *later* member the only "missing flag =
  Active" case).
- **No `ON DUPLICATE KEY UPDATE`.** A period is closed at most once; a racing second run hits the
  `unilevel_activity` primary key and rolls back, which is exactly the desired "one winner".
- The whole close is one transaction; a crash rolls it back and the next run redoes it.
- `award_total` is a reporting snapshot (it never drives a payout).
- `members_total`/`qualified_count` are the numbers on the admin period card and in the job log.

### 14.9 Reversal on cancellation

`PaymentService::cancelByAdmin()` calls `UnilevelService::reverseForOrder($orderId, $reasonText)` inside its
transaction, after the wallet refund and before the status update.

```
if !setting_bool('unilevel_reversal_enabled', true): return
rows = SELECT * FROM unilevel_awards
        WHERE order_id = ? AND status = 'credited' ORDER BY user_id, id FOR UPDATE

foreach row:
    take = min(row.amount, wallet.withdrawable(row.user_id))   // read under the debit's lock below
    if take > 0:
        movement = wallet.debit(row.user_id, take, 'unilevel_reversal', row.id,
                                'Repeat-purchase reversal — order ' . orderNo, true)
        voided.reversal_ledger_id = movement.ledger_id; voided.reversed_amount = take
    else:
        voided.reversed_amount = 0
    UPDATE unilevel_awards
       SET status = 'voided', voided_at = NOW(), void_reason = ?,
           reversal_ledger_id = ?, reversed_amount = ?
     WHERE id = ? AND status = 'credited'

-- keep the order's headline figure in step with the ledger (§13.7 #4)
UPDATE shop_orders
   SET unilevel_total = (SELECT COALESCE(SUM(amount), 0) FROM unilevel_awards
                          WHERE order_id = ? AND status = 'credited')
 WHERE id = ?
```

Rules:

- The award row keeps its `amount`, `level`, `rate_pct` and `ledger_id` (history is immutable); `status`,
  `voided_at`, `reversed_amount` and `reversal_ledger_id` record the reversal.
- `shop_orders.unilevel_total` is recomputed from the still-credited rows, so the order page and the
  reconcile invariant stay truthful after a reversal.
- **No negative balances.** Only the withdrawable balance can be clawed back. A shortfall is recorded
  (amount − reversed) and surfaced in the admin ledger as **unrecovered**. It is never silently forgiven.
- A `skipped` row is not touched (it never paid).
- The buyer was never paid, so there is no buyer rebate to reverse.
- The period snapshot is **not** rewritten. If the order's month is already closed, the member's
  historical qualification stands (the cancellation affects only future months). If the month is still
  open, the order drops out of the close aggregate naturally (`status <> 'cancelled'`).
- An exception from the debit (a concurrent payout took the money) is caught, the shortfall is recorded,
  and the cancellation proceeds — an operational cancellation must not be blocked by a wallet race (D-13).

### 14.10 Preview API

`quoteLine(int $productId, int $quantity)` returns the ladder as it **could** pay on the current rates,
with no writes; `quoteCart(array $lines)` does the same for a whole cart with one ladder query:

```php
[
  'product_id'  => 3,
  'quantity'    => 2,
  'base'        => 500.00,
  'base_source' => 'product',
  'levels'      => [
      ['level' => 1, 'rate' => 5.0, 'amount' => 25.00, 'recipient' => 'Your sponsor'],
      ['level' => 2, 'rate' => 5.0, 'amount' => 25.00, 'recipient' => 'Upline 2'],
      ['level' => 3, 'rate' => 5.0, 'amount' => 25.00, 'recipient' => 'Upline 3'],
      ['level' => 4, 'rate' => 3.0, 'amount' => 15.00, 'recipient' => 'Upline 4'],
      // … levels 5–10 as configured
  ],
  'total'       => 145.00,   // the full configured ladder on this base
]
```

Used by the product page panel and the checkout preview (17E). It deliberately does **not** evaluate the
chain length or the recipients' Active flags (that would require reading the sponsor chain for a page
view); the copy says "up to", not "your sponsor will receive". The actual payment (the chain walk in
§14.3) is always ≤ this preview.

### 14.11 Reconciliation

`UnilevelService::reconcile()` mirrors `CommissionService::reconcile()`
([`L567-L712`](../system/app/Services/CommissionService.php#L567-L712)):

| Check | Query |
|---|---|
| Total | `SUM(amount) WHERE status='credited'` vs `SUM(amount) FROM ewallet_ledger WHERE ref_type='unilevel'` (from `unilevel_ledger_since`) |
| Missing ledger | credited award with `ledger_id IS NULL` or a missing ledger row |
| Orphan ledger | `ref_type='unilevel'` row whose `ref_id` is not an award |
| Drift | `ewallet_ledger.amount <> unilevel_awards.amount` |
| Order total | `shop_orders.unilevel_total <> SUM(credited awards)` |
| Stamp | a paid order after the baseline that carries a `paid`/`payment_verified` event and has `unilevel_processed_at IS NULL` (a fixture order without an event is ignored) |
| Legacy | `ref_type='unilevel'` rows before `unilevel_ledger_since` (reported, not failed) |

`bin/wallet_reconcile.php` gains a **Repeat-purchase** section and includes it in its exit code.
`DatabaseResetService`'s integrity map gains the same service so a reset verifies it too.

---

## 15. Security, audit and abuse control

### 15.1 Guards and input handling

| Surface | Guard | Protection | Phase |
|---|---|---|---|
| `/admin/products/*` | `admin` (router) | CSRF on every POST by the router; upload rules unchanged | 17B |
| `/admin/settings` | `admin` | CSRF; section allow-list; validated fields | 17B |
| `/admin/commissions` (+export) | `admin` | read-only; export audited | 17D |
| `/earnings` (+export) | `member` | `WHERE user_id = ?` on every query; a member can never read another's rows | 17E |
| `/checkout`, `/orders/{id}` | `member` | the preview reads the caller's cart only; the order page is scoped to the buyer | 17E |
| `/system/cron/unilevel_month_close.php` | loopback or `CRON_KEY` | shared `CronAccess` rules, no session | 17F |

- No new routes means no new guard to forget (D-14).
- All SQL is prepared; table and column names are constants. The only dynamic identifiers in the new code
  are `IN (?, ?, …)` placeholder lists built from counted ids.
- Every view escapes with `e()`; the reason strings are truncated to 120 characters before storage and
  rendered as text.
- The product form's numbers are validated server-side (`decimal:3`, ranges, the ladder sum) *and*
  constrained by the input attributes; the server never trusts the client's computed total.

### 15.2 Money integrity

1. **One transaction.** Award rows + wallet credits + order stamp + order status share the caller's
   transaction.
2. **Immutable history.** `unilevel_awards` has no update path except the reversal columns (`status`,
   `voided_at`, `void_reason`, `reversal_ledger_id`, `reversed_amount`); the money columns, `level` and
   `ledger_id` never change.
3. **Idempotency at four layers** (§14.4).
4. **Reconciliation.** `bin/wallet_reconcile.php` fails non-zero if the repeat ledger, its wallet movements
   and the order totals disagree.
5. **Segregation of duties.** The existing rule that an admin cannot verify or cancel their own order
   already covers the bonus: the reviewer who triggers the payment cannot be the buyer.

### 15.3 Abuse and economic controls

| Pattern | Control |
|---|---|
| A member buys with money earned from bonuses to farm more bonuses | Intended by design; monitor the bonus-to-revenue ratio in the reports and keep the ladders within margin (`unilevel_max_levels`, per-product rates) |
| Fake or unpaid orders | Only `paid` awards; a `pending` order pays nothing |
| Collusive chains (A sponsors B, B sponsors A) | Impossible without re-parenting (the app has none), and `sponsorChain()` has a visited-set guard |
| A member editing their own ladder | Admin-only pages, CSRF, audit |
| Silent over-payment after a rate edit | Rates are snapshotted on the award (`rate_pct`) and the base on the line |
| A product configured above 100% | The ladder-sum validation refuses it; each level is also capped at 100 |
| Repeated cancellation/re-payment cycles | An order can be paid once, cancelled once; awards are voided and never re-created for the same order (`unilevel_processed_at` stays stamped) |
| Wallet farming via cancellation | The reversal debits only what the wallet still holds; the shortfall is visible, and repeated cancellations are visible on `/admin/audit` and the ledger |
| Cron abuse (closing a future month) | The job refuses a future period; a closed period is immutable |

### 15.4 Audit trail

| Event | Action | Meta | Phase |
|---|---|---|---|
| Product ladder/switch/base change | `product.unilevel.update` | product id, before/after rates, switch, base | 17B |
| Settings change | `settings.update` (existing) | section `commissions`, changed keys | 17B |
| Ledger export | `unilevel.export` | filters, rows | 17D |
| Month close | `unilevel.period.close` | period, members, qualified, award total, actor | 17F |
| Award reversal | `unilevel.reversal` | order id, awards, credited, recovered, shortfall | 17C |
| Order cancellation | `order.cancel` (existing) | unchanged | 17C |

The audit rows are append-only and shown on `/admin/audit` with their action vocabulary, so a finance
review can reconstruct who changed a percentage and when.

---

## 16. Testing harness, fixtures and QA protocol

### 16.1 Harness

The project runs a hand-rolled suite: `php system/tests/run.php` executes every file in `tests/Cases` and
prints `Cases / Passed / Failed`; `tests/bootstrap.php` provides `assert_*`, `test_pdo()`,
`test_reset_app_database()`, `test_run_sql_file()`, `test_run_isolated()`. Each case gets a clean
database. The HTTP layer is exercised through `View::capture()` and controller context methods (the view
tests), not through a browser; `tests/smoke.ps1` covers the real URLs.

**Baseline at `p17-start`:** 816 cases, 195 smoke checks, `build_install_sql --check` exit 0.

**Test ID convention.** `P17X-Tn`, where `X` is the phase letter. A phase's cases live in files named for
the phase's subject, and every case name starts with its ID, so
`php tests/run.php Unilevel` selects the engine cases and `php tests/run.php` runs everything.

### 16.2 Fixture tree

id 1 is the **primary admin** (the company account, kept out of the default chain); `boss(2)`, `a(3)`,
`b(4)`, `c(5)`, `buyer(6)`, with `buyer → c → b → a → boss → NULL` (exactly four hops). Product `P1` price
₱300 / P.V. ₱250 / `base = auto` / levels **5/5/5/3/3/2/2/2/1/1**; product `P2` price ₱500 / P.V. 0 / level 1
**10%**, levels 2–10 **0%**. All expected values are exact.

The ten-deep and eleven-deep variants are built by extending the chain upward from `boss` with `u5 … u12`;
the ladder stays `P1`'s.

### 16.3 Money fixture table

Used by 17C's awards, the admin ledger view tests and the member statements (all values in pesos). **L1 is
the direct sponsor; L2–L10 are the next nine levels.**

| Base | L1 5% | L2 5% | L3 5% | L4 3% | L5 3% | L6 2% | L7 2% | L8 2% | L9 1% | L10 1% | Total |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 100.00 | 5.00 | 5.00 | 5.00 | 3.00 | 3.00 | 2.00 | 2.00 | 2.00 | 1.00 | 1.00 | 29.00 |
| 500.00 | 25.00 | 25.00 | 25.00 | 15.00 | 15.00 | 10.00 | 10.00 | 10.00 | 5.00 | 5.00 | 145.00 |
| 1,050.00 | 52.50 | 52.50 | 52.50 | 31.50 | 31.50 | 21.00 | 21.00 | 21.00 | 10.50 | 10.50 | 304.50 |
| 10,000.00 | 500.00 | 500.00 | 500.00 | 300.00 | 300.00 | 200.00 | 200.00 | 200.00 | 100.00 | 100.00 | 2,900.00 |
| 555.00 | 27.75 | 27.75 | 27.75 | 16.65 | 16.65 | 11.10 | 11.10 | 11.10 | 5.55 | 5.55 | 160.95 |
| 555.55 | 27.78 | 27.78 | 27.78 | 16.67 | 16.67 | 11.11 | 11.11 | 11.11 | 5.56 | 5.56 | 161.13 |

The last row is the per-level-rounding case: the nominal 29% of ₱555.55 is ₱161.1095 → ₱161.11, but the ten
rounded levels total ₱161.13. The tests pin the rounded total.

### 16.4 Existing cases to update, by phase

| Phase | File | Why |
|---|---|---|
| 17A | `MigrationTest.php` | add `020_unilevel_repeat_purchase.sql` to the ordered list; migrated-vs-fresh stays green; add an idempotent re-run and an ENUM assertion |
| 17A | `InstallTest.php` | `tables_created 31`, `settings_rows 54` |
| 17A | `InstallSqlTest.php` | the comment and any count assertion |
| 17A | `SeedParityTest.php` | no edit expected: it fails unless seed 001 carries all six keys — a useful guard |
| 17A | `DbResetTest.php` | the 27-name helper becomes 31; the zero list gains the three transactional tables |
| 17A | `WalletTest.php` | `REF_TYPES` contains the two new values and the enum matches (P5-T8) |
| 17B | `ProductTest.php` / `ProductViewTest.php` | `pv` validation, the 1–10 ladder save, the card, the badge, the filter |
| 17B | `SettingsPageTest.php` | the new fields/toggles and a save round-trip |
| 17C | `OrderTest.php` / `PaymentTest.php` | the snapshot columns and the two hooks |
| 17C | `CommissionTest.php` / `CommissionIndirectTest.php` | regression only: the referral leg must be unchanged |
| 17D | `ReportTest.php` / `ReportViewTest.php` | the four repeat cards and the export type |
| 17D | `AdminCommissionLiveTest.php` | the tab switch and the repeat fragments |
| 17E | `MemberEarningsLiveTest.php` | the new tab's fragments and live revision |
| 17E | `ShopLiveTest.php` / `CartViewTest.php` | the product panel and the checkout line in the feed payload |
| 17F | `CronTest.php` | the eight new cases |
| 17G | `HardeningTest.php` | `APP_PHASE === 17` |
| 17G | `tests/smoke.ps1` | ~10 new URL checks accumulated across the phases |

### 16.5 Cron cases (`CronTest.php`, phase 17F)

| # | Case | Expected |
|---|---|---|
| 1 | `--dry-run` on a database with unpaid activity | nothing written, output lists the periods |
| 2 | real run closes the previous month | `unilevel_periods` + `unilevel_activity` rows |
| 3 | second run | `closed=0`, exit 0 |
| 4 | catch-up: two missed months | both closed in one run, oldest first |
| 5 | `--period` future | exit 1, message |
| 6 | lock held | `[SKIP ]`, exit 0 |
| 7 | log line | one line with period/members/qualified counters |
| 8 | `--json` | machine-readable payload with the same numbers |

### 16.6 The QA pack

`docs/qa/phase-17-unilevel-repeat-purchase.md` is created by 17A with **section A only**, and each later
phase appends its section before its gate. It is never rewritten, only appended. Each section:

- states the phase's prerequisites (the previous tag, the fixtures) and its expected suite count;
- lists every check as `QA17X-nn` with the exact URL or command, the exact expected number and one line on
  what to do when it fails;
- ends with a sign-off line (tester, date, evidence file names).

Evidence lives in `docs/qa/evidence/phase-17/`, one file per check (`QA17A-01.png`,
`QA17C-04.txt`, …), indexed by that folder's `README.md`. A check that produces only a count is captured as
a text transcript rather than a screenshot.

`docs/qa/README.md` and `docs/qa/sign-off-sheet.md` gain one Phase 17 row when 17A lands, and the row is
finalised (check count, time needed, signed) by 17G.

### 16.7 Regression policy

The suite is cumulative. After each phase, every case from every earlier phase still runs, and the phase's
gate requires the **whole** suite green — not just its own file. Two regressions matter most and are
asserted by name:

- `P17D-T1` — the package commission tab is unchanged.
- `P17E-T10` — the package earnings tab is unchanged.

A third is implicit and checked by the suite's own counts: the referral ledger and the wallet ledger must
be unchanged in behaviour, because this feature adds a second stream to them.

---

## 17. Risks and mitigations

| # | Risk | Likelihood | Impact | Mitigation | Detected by |
|---:|---|---|---|---|---|
| 1 | **The plan pays more than the margin allows** (29% of every repeat purchase, on top of referral commissions, royalties and the Harvest streams) | medium | high | The stream ships off; the admin sees the total percentage while editing; monitor `/admin/reports` weekly; the depth and per-product rates are the tuning knobs | 17B gate, 17D reports |
| 2 | A ladder is set too high (level 1 at 100%, or ten levels at 20% each) | low | high | Validation (sum ≤ 100), the live total while editing, the audit row, a distinct product-list badge | 17B — T3–T5 |
| 3 | The month close is missed | medium | low | The live fallback in `UnilevelCycle::isActive()` makes payouts correct without it; `--dry-run`/`--doctor`; the period card shows the last close | 17C — T42/T47; 17F — T1 |
| 4 | Wallet shortfall on reversal | medium | medium | Recorded, visible, never negative; `unilevel_reversal_enabled` can be turned off if the business prefers to absorb it | 17C — T37 |
| 5 | Two ledgers confuse finance | medium | medium | `/admin/reports` aggregates both; `wallet_reconcile` covers both; the naming is explicit (`commissions` vs `unilevel_awards`) | 17D — T3/T12 |
| 6 | Deadlock between concurrent orders sharing uplines | low | medium | Ascending-recipient wallet locking; the order lock first; retry at the caller (existing behaviour) | 17C — T15, §14.6 |
| 7 | The migration locks a large `shop_orders` table | low | medium | Additive columns and indexes; run in a maintenance window; the table is small (one row per order) | 17A gate |
| 8 | A price/P.V. edit between placement and payment changes the base | low | low | The base is snapshotted on the line; rates are documented as payment-time | 17C — T34 |
| 9 | Test fixtures drift from the reference numbers | low | medium | The fixture table in §16.3 is the single source; both service and view tests use it | 17C/17D/17E gates |
| 10 | The business asks for a retroactive launch payout | medium | medium | Explicitly out of scope; a separate plan with its own approval and reconciliation | §18 Q13 |
| 11 | Cron timezone drift (`paid_at` vs `Asia/Manila`) | low | high | `APP_TIMEZONE` and `DB_TIMEZONE` are both `+08:00`; `--doctor` compares PHP and MySQL time; the close stores `threshold` for audit | 17C — T48; 17F — T10 |
| 12 | A product with awards cannot be deleted and the admin does not understand why | low | low | `Product::referencesMap()` gains an `awards` count and the delete message names it | 17C — T33 |
| 13 | An operator expects the reference simulator's 10% buyer rebate and reports a missing payout | medium | medium | D-09, §12.1, §9 and the product panel state it explicitly; the label is "sponsor chain", never "buyer rebate" | 17E — T1/T4 |
| 14 | A reviewer expects the package's direct/indirect two-leg shape and looks for `direct_unilevel` | medium | low | D-17 and §3.1 say the plan is deliberately one 1–10 ladder; the product form, ledger and service have no type column or leg switch | 17B — T14; 17D — T2 |
| 15 | **A phase is declared done on partial evidence** (the new risk this restructure introduces) | medium | high | The universal gate (§2.4) requires the full suite, the smoke run, a tester who did not write the code, and a filled Appendix G row; "do not start the next phase" is stated at every gate | 17A–17G gates |
| 16 | A forward dependency hides in a phase (a stub only a later phase fills) | medium | medium | §2.1's five tests, the explicit **Not yet** list in every phase, and the hand-off section naming exactly what the next phase may assume | review of each diff |

---

## 18. Open questions

These need a business decision before the phase named in **Blocks**; the plan's defaults are in brackets.

| # | Question | Plan default | Blocks |
|---:|---|---|---|
| 1 | Should the buyer receive a level-0 self-rebate like the reference simulator? | **No** — the buyer earns nothing; the sponsor chain is paid (D-09). This diverges from `docs/comp-plan_addons.md` §7.3 on purpose. A level-0 extension would be a separate change | 17C |
| 2 | Should the bonus base be P.V. or the retail price? | **P.V. when set, else price** (D-03) | 17B |
| 3 | Is the monthly Active threshold ₱500 in personal purchases, or should it count a member's *downline* volume? | **₱500 personal** (the reference's exact rule) | 17C |
| 4 | Should staff accounts be eligible as recipients? | **Yes, except the company account** (the primary admin): any other staff member who is a genuine upline and meets the checks is paid (§12.6) | 17C |
| 5 | Should awards wait for `completed` (delivered) instead of `paid`, to protect against returns? | **`paid`** (D-04); `completed` would delay the money by days | 17C |
| 6 | Should package activations also feed the repeat-purchase ladder? | **No** — the referral ladder already pays activations; double-paying them would change Phase 10–11 economics | 17C |
| 7 | Should a cancelled order's awards be clawed back? | **Yes, best effort** (D-13) | 17C |
| 8 | Should the close run monthly or twice a month (1st and 16th)? | **Monthly on the 1st** | 17F |
| 9 | Is a per-order or per-member cap needed? | **No cap in v1** (the reference has none); the depth and rates are the controls | 17C |
| 10 | Should the company absorb the shortfall when a wallet is empty? | **Yes, recorded as unrecovered** | 17C |
| 11 | Should the level-1 sponsor be exempt from the monthly Active rule? | **No** — levels 1–10 share the rule; `unilevel_requires_active_upline = 0` relaxes all of them | 17C |
| 12 | Should the depth setting be 1–10 or 2–10? | **1–10** — level 1 is a normal level, so an operator can run a level-1-only product | 17B |
| 13 | Should a launch period be paid retroactively? | **No** (D-16); a separate plan with its own approval, backfill script and reconciliation | 17G |

---

# Part IV — Appendices

## Appendix A — The reference ladder and the preset

`docs/comp-plan_addons.md` §7.2 (`UNILEVEL_PCT`) and §7.1, with the level-0 row removed (D-09):

| Level | Recipient | Rate |
|---:|---|---:|
| 1 | direct sponsor | 5% |
| 2 | upline 2 | 5% |
| 3 | upline 3 | 5% |
| 4 | upline 4 | 3% |
| 5 | upline 5 | 3% |
| 6 | upline 6 | 2% |
| 7 | upline 7 | 2% |
| 8 | upline 8 | 2% |
| 9 | upline 9 | 1% |
| 10 | upline 10 | 1% |
| | **total** | **29%** |

The **Load reference ladder** button posts exactly these numbers into the ten inputs (client-side only
until the form is saved). It is also the pre-fill for a new product (17B).

Mapping notes:

- **Level 0 is intentionally absent.** The reference pays the buyer 10% of their own purchase. This plan
  mirrors the package model instead: the newly activated member earns nothing, the sponsor chain is paid.
  The 29% total is levels 1–10; the reference's headline 39% includes its 10% buyer row.
- **One ladder.** The package's two-leg shape is not copied (D-17): level 1 is simply the first level of
  the same ladder as level 10.
- The reference applies the rate to the purchase's **Point Value**; the app's `unilevel_base = auto`
  reproduces that when a P.V. is set.
- The reference's "Active" flag is set at the month close from the month just ended; the app's
  `unilevel_activity`/`unilevel_periods` and the close job reproduce it (§12.7, 17F).
- The reference's level-0 rebate is not gated by Active; in this plan there is no level-0 rebate. Levels
  1–10 are all gated by the Active rule (unless `unilevel_requires_active_upline = 0`).

## Appendix B — Worked scenarios

### B.1 Full chain, one line, app values

Buyer `d`, chain `c → b → a → boss` (four sponsors; `boss`'s sponsor is `NULL`, so the chain ends there),
product `P1` (price ₱300, P.V. ₱250, base auto, levels 5/5/5/3/3/2/2/2/1/1), order `2 × P1` (base ₱500).

| Level | Recipient | Rate | Base | Amount |
|---:|---|---:|---:|---:|
| 1 | c | 5% | 500.00 | 25.00 |
| 2 | b | 5% | 500.00 | 25.00 |
| 3 | a | 5% | 500.00 | 25.00 |
| 4 | boss | 3% | 500.00 | 15.00 |
| | | | | **90.00** |

The ladder continues to level 10, but the chain ends at `boss`; no rows are written for levels 5–10. The
buyer `d` receives nothing.

### B.2 The same order with `b` Inactive

| Level | Recipient | Decision |
|---:|---|---|
| 1 | c | credited ₱25.00 |
| 2 | b | **skipped** — `not Active for 2026-10` (₱25.00 not paid) |
| 3 | a | credited ₱25.00 (still level 3's 5%) |
| 4 | boss | credited ₱15.00 (still level 4's 3%) |

Credited ₱65.00; skipped ₱25.00. The skipped ₱25.00 is not redirected. The buyer still receives nothing.

### B.3 Two products, one order

| Line | Base | L1 | L2 | L3 | L4 | Line total |
|---|---:|---:|---:|---:|---:|---:|
| P1 × 2 | 500.00 | 25.00 | 25.00 | 25.00 | 15.00 | 90.00 |
| P2 × 1 | 500.00 | 50.00 (10%) | — (0%) | — | — | 50.00 |
| **Order** | | | | | | **140.00** |

### B.4 The monthly gate across three months

| Month | Purchases | Snapshot for that month | Effect on the next month |
|---|---:|---|---|
| Oct | ₱450 | `qualified = 0` | November receipts blocked |
| Nov | ₱2,000 | `qualified = 1` | December receipts paid |
| Dec | ₱0 | `qualified = 0` | January receipts blocked |

The buyer receives no rebate in any month; the table is about the buyer as a **recipient** of their
downline's purchases.

### B.5 Ten sponsors, one line

| Level | Recipient | Rate | Amount |
|---:|---|---:|---:|
| 1 | sponsor | 5% | 25.00 |
| 2 | upline 2 | 5% | 25.00 |
| 3 | upline 3 | 5% | 25.00 |
| 4 | upline 4 | 3% | 15.00 |
| 5 | upline 5 | 3% | 15.00 |
| 6 | upline 6 | 2% | 10.00 |
| 7 | upline 7 | 2% | 10.00 |
| 8 | upline 8 | 2% | 10.00 |
| 9 | upline 9 | 1% | 5.00 |
| 10 | upline 10 | 1% | 5.00 |
| | **total** | **29%** | **145.00** |

Base ₱500. The buyer receives nothing.

## Appendix C — SQL quick reference

**Active snapshot for a closed period**

```sql
SELECT qualified FROM unilevel_activity WHERE period = ? AND user_id = ?;
```

**Live purchases in a period (open period fallback, and the close aggregate)**

```sql
SELECT o.user_id, COALESCE(SUM(oi.line_total), 0) AS spent
  FROM shop_orders o
  JOIN shop_order_items oi ON oi.order_id = o.id
 WHERE o.user_id IN (/* ids */)
   AND o.paid_at >= ? AND o.paid_at < ?
   AND o.status <> 'cancelled'
 GROUP BY o.user_id;
```

**Awards for an order**

```sql
SELECT a.*, u.username AS recipient, b.username AS buyer, p.name AS product
  FROM unilevel_awards a
  JOIN users u    ON u.id = a.user_id
  JOIN users b    ON b.id = a.buyer_id
  JOIN products p ON p.id = a.product_id
 WHERE a.order_id = ?
 ORDER BY a.order_item_id, a.level;
```

**Credited totals for a member**

```sql
SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END)                          AS count,
       COALESCE(SUM(CASE WHEN status = 'credited' THEN amount END), 0)          AS credited,
       COALESCE(SUM(CASE WHEN status = 'credited' AND paid_at >= ? THEN amount END), 0) AS this_month,
       COUNT(CASE WHEN status = 'skipped' THEN 1 END)                           AS skipped
  FROM unilevel_awards
 WHERE user_id = ?;
```

**Reconciliation totals**

```sql
SELECT COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total
  FROM unilevel_awards;

SELECT COALESCE(SUM(amount), 0) AS ledger_total
  FROM ewallet_ledger
 WHERE ref_type = 'unilevel' AND created_at >= ?;
```

**The first period to consider closing**

```sql
SELECT value FROM settings WHERE key_name = 'unilevel_ledger_since';
-- start from that month; walk forward, skip the months in unilevel_periods,
-- and stop at the month before the current one
```

## Appendix D — Glossary

| Term | Meaning in this plan |
|---|---|
| **award** | one `unilevel_awards` row: a credited, skipped or voided decision for one (line, recipient, level) |
| **base** | the money the percentage applies to: the line's P.V. (or price) × quantity |
| **buyer** | the member who placed and paid the order; **never a recipient** in this stream |
| **chain** | the buyer's sponsor chain, walked upward from `users.sponsor_id` |
| **close** | the monthly job that snapshots personal purchases and writes `qualified` |
| **credited** | an award that moved money into the recipient's wallet |
| **gate** | the checklist that closes a phase: suite, smoke, manual script, evidence, log entry |
| **level** | one rung of the ladder: 1 = direct sponsor, 2 = their sponsor, … 10 |
| **no compression** | a skipped level keeps its number; the next upline is not promoted |
| **period** | a calendar month, `YYYY-MM`, Asia/Manila |
| **phase** | one of the seven delivery units 17A…17G; each ships and tests alone |
| **P.V.** | `products.pv`, the point value used as the bonus base when set |
| **skipped** | an award that was due but not paid (inactive or unusable recipient) |
| **unrecovered** | the part of a voided award the wallet could not return |
| **voided** | an award that was paid and later reversed by a cancellation |

## Appendix E — Formula card

```
period(P)        = month(paid_at) in Asia/Manila, 'YYYY-MM'
previous(P)      = P − 1 month

unit_base        = base='pv'    ? pv
                 : base='price' ? price
                 :                (pv > 0 ? pv : price)
base(line)       = unit_base × quantity
rate[L]          = product_unilevel_levels[L].percent        L = 1…10

amount(level)    = round(base × rate[level] / 100, 2)        per line, per level
earned(order)    = Σ over lines Σ over credited levels amount
order_total      = Σ credited amounts for the order

N                = min(10, unilevel_max_levels)
chain            = sponsor_id(buyer), sponsor_id(sponsor), … (≤ N, cycle-guarded)

Active(P, M)     = personal(previous(P), M) ≥ unilevel_active_threshold
personal(P, M)   = Σ line_total of M's orders with paid_at ∈ P and status ≠ 'cancelled'

level L          = paid when unilevel_enabled
                   and products.unilevel_enabled
                   and rate[L] > 0
                   and chain[L] exists
                   and L ≤ N
                   and recipient eligible
                   else no row (switch/rate) or skipped (eligibility); level consumed

eligible(recipient) = recipient exists
                      and recipient ≠ company account
                      and users.is_active = 1
                      and users.member_status = 'active'
                      and (unilevel_requires_active_upline = 0 or Active(P, recipient))

reversal         = min(award.amount, withdrawable_balance) debited as 'unilevel_reversal'
                   shortfall = award.amount − reversal
```

## Appendix F — Traceability to the reference document

| `docs/comp-plan_addons.md` | Rule | This plan |
|---|---|---|
| §7.1 | Real-time override on every recorded purchase, sponsor chain, no rank/Harvest requirement | §12.2, §12.5 |
| §7.2 | 10/5/5/5/3/3/2/2/2/1/1 ladder, per-level rounding | §12.4, §12.8, Appendix A (level 0 removed; levels 1–10 = 29%) |
| §7.3 | Level 0 always paid to the buyer | **Not implemented** — D-09; the buyer earns nothing and the sponsor chain is paid, mirroring the package model |
| §7.4 | Ten-level walk, company skipped, no compression, inactive consumes the level | §12.5, D-07 |
| §7.5 | Active ⇔ personal purchases ≥ ₱500 in the month just ended; missing flag = Active; overwritten each close | §12.7, 17F |
| §7.6 | Worked examples (₱10,000, ₱555, ₱1,000 with a skip) | §12.10, §16.3 (recomputed without level 0) |
| §7.7 | Auto-purchase pays in real time | The app's equivalent is a paid order (the shop has no auto-purchase) |
| §7.8 | No rank, team size, buyer Active, month boundary, or product/SKU rules | §12.11 |
| §2.4 | The two-clock month | §12.7, 17E |
| §2.5 | Ledgered money vs derived money | §13.7, §14.11 (rewards are ledgered) |
| §11.3 | Unilevel quirks 17–24 | §12.5, §12.7, §14.9, §18 |
| Appendix B | Formula card | Appendix E |

**Package implementation mapped to this plan**

| Package artefact | Repeat-purchase equivalent |
|---|---|
| `package_levels` (levels 2–10) | `product_unilevel_levels` (**levels 1–10**) |
| `packages.direct_referral_bonus` | **not copied** — level 1 is a normal row in the same table (D-17) |
| `packages.indirect_enabled` | **not copied** — the product master switch covers the whole ladder |
| `settings.commission_direct_enabled` / `commission_indirect_enabled` | **not copied** — one `settings.unilevel_enabled` master |
| `settings.commission_indirect_max_levels` | `settings.unilevel_max_levels` (1–10) |
| `commissions.type = direct_referral` / `indirect_referral` | **not copied** — `unilevel_awards` has no `type`; the `level` is the whole story |
| `CommissionService::onActivation()` | `UnilevelService::onOrderPaid()` — one loop over levels 1–N |

## Appendix G — Phase gate record

Fill one row per gate and copy the block into `docs/phase-log.md` with the phase's entry. A phase without a
filled row is not closed, whatever its code says.

| Phase | Tag | Date | Suite passed/ran | Smoke passed/ran | Migration/bundle | Manual QA `QA17X-nn` | Tester | Deviations | Signed |
|---|---|---|---|---:|---:|---|---|---|---|
| 17A Foundation | `p17a-foundation` | 2026-11-12 | 830 / 830 | 195 / 195 | `--check` exit 0 (sha256 `a5467d25…`); migration applied twice, idempotent | A: 08/08 | DSH | 4 (see below) | ☑ |
| 17B Configuration | `p17b-configuration` | 2026-11-12 | 855 / 855 | 209 / 209 | no schema change (`--check` exit 0; `wallet_reconcile` exit 0) | B: 09/09 | DSH | 7 (see below) | ☑ |
| 17C Money engine | `p17c-money` | | __ / __ | __ / __ | `wallet_reconcile` exit 0 | C: __/10 | | | |
| 17D Admin visibility | `p17d-admin` | | __ / __ | __ / __ | no schema change | D: __/08 | | | |
| 17E Member visibility | `p17e-member` | | __ / __ | __ / __ | no schema change | E: __/09 | | | |
| 17F Operations | `p17f-month-close` | | __ / __ | __ / __ | cron doctor | F: __/07 | | | |
| 17G Release | `p17g-release` | | __ / __ | __ / __ | `APP_PHASE = 17` | G: __/06 | | | |

**Deviation log.** Any difference between this plan and what was built, with the reason and the phase that
owns it. An empty deviation log at the 17G gate means the plan and the build agreed.

| Phase | Deviation | Reason | Approved by |
|---|---|---|---|
| 17A | `database/install/header.sql`'s expected-counts comment updated to 31 tables / 51 settings | §13.6 lists only `footer.sql`, but the header is a bundle source; a stale count would ship inside `install.sql` | 17A gate |
| 17A | The development copy (`ecowealth_sneat`) was migrated at the gate, after a `mysqldump` backup outside the repo | QA17A-05's award count and the "migrated database" hand-off require it; the scratch schemas still carry QA17A-01/02/03/07/08 | 17A gate |
| 17A | `DbTargetTest.php` and `SqlScriptTest.php` also moved from 27 to 31 tables | §16.4 named five test files; these two hard-code the table count as well | 17A gate |
| 17A | The manual section-A checks were executed by the automated pass (real HTTP + headless Chrome) rather than an independent tester | No second tester is available in this session; the "someone who did not write it" item stays open for the lead's review | pending |
| 17B | `admin/settings/index.php` also changed | §6 lists only `admin/settings/_commissions.php`, but the index is the wrapper that passes the card's data; without `repeat_fields`/`repeat_toggles` the sub-block renders empty | 17B gate |
| 17B | The `product.unilevel.update` entry is written by `Product::save()` inside the same transaction, not by the controller | Keeps "an audit entry never exists without the change" true by construction and makes it testable in-process (a controller POST ends in `redirect()`); the controller passes the actor and `ProductUnilevel::audit()` builds the payload | 17B gate |
| 17B | `Product::save()` gained an optional fourth argument (`?array $actor`) | The audit row names the operator; every existing caller passes three or fewer arguments and is unaffected | 17B gate |
| 17B | 17B's 24 cases live in `UnilevelConfigurationTest`, and `ProductViewTest` gained one | §16.1's convention ("a phase's cases live in files named for the phase's subject"); §16.4 names the existing files that change, and `ProductViewTest` does | 17B gate |
| 17B | `tests/smoke.ps1`'s `P15-T7` posts the commissions card with the five new keys | The widened card validates them (`required`), so the old partial POST was refused; the check still proves an unticked commission switch is stored as 0 | 17B gate |
| 17B | `docs/deploy.md`'s install counts corrected from 27/48 to 31/54 | Stale since 17A added migration 020; the file was being edited for the Phase 17 settings addendum anyway | 17B gate |
| 17B | The manual section-B checks were executed by the automated pass (real HTTP + MySQL transcripts) rather than an independent tester | Same constraint as 17A's row above; the "someone who did not write it" item stays open for the lead's review | pending |

---

*End of plan.*
