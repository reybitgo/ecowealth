# Plan — Product Binary Bonus (placement tree over repeat-purchase points)

**Phase 22** of the EcoWealth member system · **Status: released** — code written through migration `026`; `APP_PHASE = 22`; released at `p22i-release` (Gate R6, 2026-11-21); the plan is the gate record.
**Delivery model:** **nine gated phases, 22A → 22I.** Each phase is self-contained, shippable and
testable on its own; each builds on the one before it. Two phases (22G, the d3 tree) and (22I, the
release) are deliberately separated from the phases that make them possible, because each is a work
package of its own.
**Date:** 15 November 2026 · **Target:** `http://localhost/ecowealth/system/`
**Branch:** `feature/product-binary`, cut from `feature/general-harvest` at `p21g-release` · **Tag at
start:** `p22-start`

| | |
|---|---|
| **Reference specification** | **This document §14 is normative.** It extends [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §2.1 (the sponsor tree), §2.2 (money and rounding), §2.3 (persistent state), §2.4 (the month clock), §2.5 (derived vs. ledger money), §2.6 (persistence and reset) and §7.4–7.5 (the upline walk and Active status), and reuses the Phase 17/18 unilevel engine's wallet, escrow and reconcile conventions. There is no prior simulator for this stream; the reference d3 tree is [`dmp/cart-system/views/member/genealogy.php`](cart-system/views/member/genealogy.php) and its `apiBinaryTree()` in [`dmp/cart-system/controllers/MemberController.php`](cart-system/controllers/MemberController.php#L331-L389). |
| **Related plans** | [`dmp/plan-ecowealth-unilevel-repeat-purchase.md`](plan-ecowealth-unilevel-repeat-purchase.md) (Phase 17 — the repeat-purchase money, the 1–10 ladder and the earnings presentation this plan mirrors), [`dmp/plan-ecowealth-unilevel-bonus-escrow.md`](plan-ecowealth-unilevel-bonus-escrow.md) (Phase 18 — the `pending → credited` escrow contract reused verbatim), [`dmp/plan-ecowealth-royalty.md`](plan-ecowealth-royalty.md) (Phase 19 — the ledger/settings/report conventions), [`dmp/plan-ecowealth-team-harvest.md`](plan-ecowealth-team-harvest.md) and [`dmp/plan-ecowealth-general-harvest.md`](plan-ecowealth-general-harvest.md) (Phases 20–21 — the phase protocol, QA pack and gate-record conventions), [`dmp/plan-ecowealth-cart-system.md`](plan-ecowealth-cart-system.md) (the 15 phases this one extends), [`dmp/plan-ecowealth-install-reset.md`](plan-ecowealth-install-reset.md) (install/reset parity) |
| **Depends on** | **Phase 17** (`products.pv`, `shop_order_items.unit_base/base_total`, `unilevel_awards`, `UnilevelCycle`, the Active snapshot), **Phase 18** (escrow: `pending` awards, `unilevel_release.php`, `hold_days`), **Phase 19** (the ledger-tab and report-card conventions). Phase 20/21 (Harvest) are **not** dependencies: this stream reads no harvest table (D-15). |
| **Baseline at `p22-start`** | At `p21g-release` (the Phase 21 release gate): **1,484 / 1,484** PHP cases · **≈ 278 / 278** smoke checks · `build_install_sql --check` exit 0 · `wallet_reconcile.php` exit 0 · **39 tables · 79 settings** · migrations **001–025** · `APP_PHASE` **21**. *Today (15 November 2026) Phases 20–21 are still planned: the live copy is 1,162 cases, 248 smoke checks, 33 tables, 73 settings, migration 023, `APP_PHASE` 19. §2.6 says what to do if the released Phase 21 counts differ.* |
| **Ships** | 3 new tables, 4 altered tables, 1 extended ENUM, 10 settings, one placement route, the self and pair engines, an escrowed award ledger, a points ledger, the **Left/Right team** member presentation, a **d3 binary genealogy tree with in-node registration**, admin and member visibility, reports, reconciliation, two CLI tools and one extended release job |
| **Does not ship** | no second tree (sponsor tree untouched), no package/activation input, no change to the unilevel ladder or its money, no monthly close of its own, no compression, no flush, no backfill of pre-go-live orders, no partial refunds, no member-facing placement moves, no reservation of a free slot, no per-product pair rate |

**Revision note.** This is the first plan for the Product Binary Bonus. It does **not** replace
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md); it adds a stream the reference document does
not describe, and it records every decision the reference does not make. Three adaptations are stated up
front, because they shape every phase below:

1. **V3.0 deliberately has no binary tree.** Phase 9 shipped a sponsor-only system and four tests
   *assert the absence of binary columns* (`MembershipTest` P9-T15/P9-T17) and of binary copy
   (`MembershipViewTest`). This plan adds a real placement tree, so it **retires those guards on
   purpose** and replaces them with stronger ones: `users` still carries no binary column (the tree is a
   separate table), the sponsor tree stays the only chain for package referral, unilevel, royalty and
   Harvest, and the binary vocabulary is confined to a named file list (D-25, §3.7, §15.6).
2. **The buyer earns on their own purchase.** The unilevel stream deliberately pays the buyer nothing
   (Phase 17 D-09). This stream's whole point is the opposite: a **Self Bonus** and an own-node pair
   (D-07, D-08). The two streams therefore have opposite level-0 rules, and the copy must never be
   shared between them (§14.12, Appendix C).
3. **Points are a second currency.** The unilevel pays percentages of a *money base*; this stream
   matches **integer points** left against right. The plan keeps points and money in separate ledgers
   (D-23), so a points bug can never silently move a peso and a money bug can never rewrite a point
   balance.

§14.13 lists every deliberate divergence from the reference tree and every place this plan is stricter
than the reference implementation.

---

## Contents

**Part I — Orientation**

1. [Executive summary](#1-executive-summary)
2. [The phase protocol](#2-the-phase-protocol)
3. [Current state and the gap](#3-current-state-and-the-gap)
4. [Decisions](#4-decisions)

**Part II — The phases** (each one is a complete work package)

5. [Phase 22A — Foundation](#5-phase-22a--foundation)
6. [Phase 22B — Configuration and readiness](#6-phase-22b--configuration-and-readiness)
7. [Phase 22C — Placement, the tree and registration](#7-phase-22c--placement-the-tree-and-registration)
8. [Phase 22D — Money engine](#8-phase-22d--money-engine)
9. [Phase 22E — Admin visibility](#9-phase-22e--admin-visibility)
10. [Phase 22F — Member visibility](#10-phase-22f--member-visibility)
11. [Phase 22G — The binary tree](#11-phase-22g--the-binary-tree)
12. [Phase 22H — Operations](#12-phase-22h--operations)
13. [Phase 22I — Release](#13-phase-22i--release)

**Part III — The shared contract** (read once; every phase cites it)

14. [Specification (normative)](#14-specification-normative)
15. [Data model](#15-data-model)
16. [The money path](#16-the-money-path)
17. [Security, audit and abuse control](#17-security-audit-and-abuse-control)
18. [Testing harness, fixtures and QA protocol](#18-testing-harness-fixtures-and-qa-protocol)
19. [Risks and mitigations](#19-risks-and-mitigations)
20. [Open questions](#20-open-questions)

**Part IV — Appendices**

- [Appendix A — The reference configuration and the default preset](#appendix-a--the-reference-configuration-and-the-default-preset)
- [Appendix B — Worked scenarios and the acceptance run](#appendix-b--worked-scenarios-and-the-acceptance-run)
- [Appendix C — Copy deck](#appendix-c--copy-deck)
- [Appendix D — SQL quick reference](#appendix-d--sql-quick-reference)
- [Appendix E — Glossary](#appendix-e--glossary)
- [Appendix F — Formula card](#appendix-f--formula-card)
- [Appendix G — Traceability to the reference tree and the existing streams](#appendix-g--traceability-to-the-reference-tree-and-the-existing-streams)
- [Appendix H — Phase gate record](#appendix-h--phase-gate-record)

---

# Part I — Orientation

## 1. Executive summary

### 1.1 What ships

A **product binary bonus on repeat purchases**, paid from the points a member's own purchases and their
binary team's purchases place on the two sides of a placement tree:

- **One placement tree, separate from the sponsor tree.** Every member has exactly one **node**:
  a binary **upline** and a **side** (`left` or `right`). The tree lives in `binary_nodes`, keyed by
  user; `users.sponsor_id` is untouched and remains the only chain for package referral, the unilevel
  repeat-purchase ladder, Royalty and Harvest (D-01). A node has at most one left child and one right
  child, enforced by a unique key on `(upline_id, side)`.
- **Placement is required at registration.** The registration form asks for a **binary upline** and a
  **position**, and the server validates that the slot is free and that the placement is legal (inside
  the registrar's/sponsor's subtree, never a cycle). Placement is **immutable** once the subtree has
  volume or awards; an empty-subtree move is an audited admin action (D-03, D-04).
- **Every repeat purchase is placed left or right.** At checkout the buyer chooses one side for the
  order. The choice is required when the cart carries points and is snapshotted on the order and its
  lines (D-06). The points are `P.V. × quantity`, rounded to whole points — the **same P.V. the
  unilevel stream already uses**, so one product has one point value (D-05).
- **The buyer earns on their own purchase.** Two ways, both new to this application:
  1. a **Self Bonus** of `binary_self_pct` (5% by default) of the order's points, paid once per order
     with no Active gate (D-07);
  2. the purchase lands on the buyer's **own node** on the chosen side, so it can **pair** immediately
     against the other side's unmatched points (D-08).
- **The buyer also earns from their binary team.** The same purchase points roll up to **every binary
  upline**, on the side of the tree the buyer sits under. At each node, points are matched:
  `binary_pair_points` (1,000 by default) on the left against the same on the right is one **pair**, and
  a pair pays `binary_pair_pct` (10% by default) of the matched point value — ₱100.00 per 1,000-point
  pair at the default preset. Matched points are consumed, the remainder **carries forever** (D-09).
  A **daily pair cap** (20 by default) bounds one member's day; excess volume carries (D-14).
- **A real money ledger, escrowed exactly like the unilevel stream.** Every award is written `pending` at
  payment and credited to the withdrawable e-wallet only after the order is **delivered + `hold_days`**
  (7 by default), by the **same release job** that already releases repeat-purchase awards (D-11). A
  cancellation cancels pending awards and reverses credited money best-effort (§16.9).
- **Points are auditable on their own.** A separate, append-only `binary_volume` ledger records every
  +/− point movement (own purchase, team roll-up, pair consumption, reversal, adjustment). The node's
  cached balances are a materialisation of that ledger and `bin/binary_audit.php` proves they agree
  (D-23). No pair can be paid from a cached number that the ledger cannot explain.
- **A member page that speaks only downward.** The earnings page gains a **Product binary** tab whose
  **Left team** and **Right team** cards use the same card language as the unilevel
  `?ledger=repeat` presentation — member roster, points, progress, per-card pagination — and it
  **never names an upline**. It is the member's own aid, not a view of somebody else's income (D-22).
- **A binary genealogy tree that tops the reference.** `/genealogy?view=binary` renders a responsive
  d3 v7 tree with server-rendered first paint, lazy subtree loading, keyboard and touch support, a no-JS
  list fallback, live refresh, search, fit/zoom/collapse, breadcrumb, node cards carrying points and
  pairs, **free-slot “+” buttons that open an in-node registration modal**, and copy-invite links. The
  reference implementation in `dmp/cart-system` is the floor, not the ceiling (§11.2, D-19).
- **Visibility on existing pages only — with one named exception.** The ledger tab
  (`/admin/commissions?ledger=binary`), the order card, the member panel, the reports, the settings
  card, the product card, the checkout card, the member order card, the earnings tab and the tree are
  all query parameters on pages that already exist. Exactly **one** new route ships:
  `POST /admin/members/{id}/placement`, the audited empty-subtree move (D-18).
- **Off by default, twice.** `binary_enabled = 0` and `products.binary_enabled = 0` on every existing
  product. Deploying 22A–22C changes no money; turning the stream on is a deliberate operator act
  (D-16).

### 1.2 The rules in one page

```
SETUP (once, Phase 22C)
  root            = the company account (primary admin)                     never paid
  node(user)      = (upline_id, side, depth, path)                          one per member
  slot(upline,side) is unique                                                at most one child per side
  placement       REQUIRED at registration; immutable once the subtree has volume or awards

PURCHASE (checkout)
  points(line)    = ROUND(product.pv) × quantity                             integer, snapshotted at placement
  points(order)   = Σ points(line) over lines with binary on and P.V. > 0
  side            = the buyer's choice: 'left' | 'right'                     required when points(order) > 0
  no node / no eligible line / stream off → the order is accepted with binary_position NULL

ON ORDER → paid   (e-wallet: inside checkout; manual: admin verifies the proof)
│
├─ self         = round(points(order) × binary_self_pct / 100, 2)           → buyer, pending award
│
├─ volume       for the buyer's node:      +points on the chosen side       kind 'own'
│               for every binary upline:   +points on the side the buyer sits under   kind 'team'
│               the company root receives volume and never pairs, never earns
│
├─ pairing      for node = buyer's node, then each upline up to the root's children:
│                 left  = max(0, node.left_points)
│                 right = max(0, node.right_points)
│                 due   = min(floor(left / pair_points), floor(right / pair_points))
│                 n     = min(due, daily cap remaining for the recipient)
│                 if the recipient is not eligible (account, membership, Active, not company)
│                     → pay nothing, consume nothing: the points wait (D-13)
│                 if n > 0 → consume n × pair_points from each side (two −point 'pair' rows)
│                             amount = round(pair_points × binary_pair_pct / 100, 2) × n
│                             one pending 'pair' award for (order, node)
│
└─ stamp shop_orders.binary_processed_at, binary_points, binary_self_total, binary_pair_total, binary_total
      (the idempotency anchor: a replay changes nothing)

RELEASE   after delivery + hold_days, by bin/unilevel_release.php --stream=binary
  pending → credited, withdrawable e-wallet, ref_type 'binary', ref_id = award.id

CANCEL    pending awards cancelled (no money moved); credited awards voided and clawed back best effort
          the purchase's volume is reversed at every node; pair consumption is returned; no re-pairing
```

**At the default preset** a 1,000-point order pays the buyer **₱50.00** self and can pay one own-node
pair (**₱100.00**) plus one pair at each eligible upline whose other side is filled. The whole payout of
an order is bounded by `points × self% + (points / pair_points) × pair% × depth` — a number the plan can
state, test and report (§14.11, Appendix F).

### 1.3 What is deliberately not built

- **No change to the sponsor tree, and no change to any existing stream.** `users.sponsor_id`, the
  package referral ladder, the unilevel ladder, Royalty and Harvest are read-only for this plan. A
  member's binary upline is not their sponsor (D-01).
- **No package/activation input.** Product binary is a **repeat-purchase** stream. Package purchases,
  activations and registration-code redemptions never place points and never earn binary (D-15).
- **No per-product pair rate.** A pair can match points from different products, so the pair size and
  rate must be global; a product controls only its switch and its P.V. (D-09, D-11).
- **No flush, no expiry, no carry reset.** Points carry forever. The alternative (periodic flush) is
  listed as an open question (Q6), not silently implemented.
- **No compression.** An ineligible node keeps its volume; the next upline is evaluated on its own
  points, never on the skipped one's (D-13).
- **No retroactive money.** Orders paid before the switch are never awarded; `binary_ledger_since`
  marks the instant the stream may pay (D-15, 22D).
- **No partial refunds and no order editing.** A paid order is reversed only by cancellation (§16.9).
- **No member-facing placement changes and no free-slot reservation.** A member can move nobody, and an
  invite link is a hint: the first valid registration wins the slot (D-26).
- **No second month-close job, no second period table, no second Active rule.** The stream reuses
  `unilevel_activity`, `unilevel_periods` and `bin/unilevel_month_close.php` (D-12).
- **No new routes except one.** Everything else is a query parameter on an existing page (D-18).
- **No tax withholding, no cost-of-goods model and no company-economics report beyond the existing
  cards.** Awards are gross, like every other stream.

### 1.4 Delivery: nine gated phases

The feature is one coherent stream of money, but it is **not** delivered as one drop. It is split into
nine phases, each ending in a gate that must be passed — automated suite plus a manual script — before
the next phase starts. The split follows the lines where a phase can be **proven on its own**.

| Phase | One-line purpose | What the gate proves | Adds money? | Adds UI? |
|---|---|---|---|---|
| **22A** | Add the three tables, the four altered tables, the enum values and the ten settings; teach install, migration and reset about them | A migrated database equals a fresh install; nothing in the app reads the new objects yet | no | no |
| **22B** | Let an operator switch the stream on, set the five rules and flag products for the binary; add the readiness card | Configuration saves, validates, audits and renders — and a paid order still writes nothing | no | admin |
| **22C** | Build the tree: the node model, registration placement, the admin placement console, the backfill tool and the move rule | Every placement rule is enforced; the tree is a single rooted tree; no money exists | no | member + admin |
| **22D** | Pay the self bonus and the pairs on a paid order, keep the points ledger, escrow the awards, reverse on cancellation, reconcile | Every money rule in §14 and §16: points, self, pairs, caps, carry, eligibility, idempotency, reversal | **yes** | none |
| **22E** | Show the money to administrators: binary ledger tab, order card, member panel, five report cards, readiness cards, CSV | Finance can audit every point and every award from the UI | no | admin |
| **22F** | Show the offer and the outcome to members: Product binary earnings tab with Left/Right team cards, checkout position card, order card, dashboard tile, product panel | The copy rules hold; the member page names no upline | no | member |
| **22G** | The binary genealogy tree: d3 explorer, in-node registration, invite links, admin explorer | The tree is responsive, accessible, usable without JS, and registers members in a node | no | member + admin |
| **22H** | Operations: the release job's second stream, the points audit, the doctor, cron and runbook | The escrow and the audit operate unattended, idempotently, with logs | no | none |
| **22I** | Documentation, QA pack, phase badge, acceptance run, sign-off | The release is signed and everything above is reproducible | no | no |

**Why this split:**

- **The database goes first** because every later phase reads it, and because a schema change is the
  only part of this plan that is hard to reverse. Proving install/reset parity in isolation (§5) means
  no later bug can be blamed on the schema.
- **Configuration second**, because it is the only phase a non-developer can verify by eye, and because
  an inert switch is a safe thing to leave in production for a week.
- **Placement third**, before any money, because the tree is the input to every payment and because a
  wrong tree is a structural defect that money would only hide. Placement is fully observable with an
  empty ledger.
- **Money fourth**, as one indivisible phase. Splitting the engine (for example "self only, pairs
  later") would leave the stream in a state that is *wrong* rather than merely *incomplete*; a phase
  may be small, but it may not be incorrect.
- **Admin before member**, because the ledger is the tool the operator uses to explain and audit the
  money; the member page is marketing of an already-proven stream.
- **The tree after the member page**, because the tree is a navigation surface over data 22C and 22F
  already make correct, and because it is the largest single UI work package in the plan.
- **Operations after the money** and **release last**, because `APP_PHASE`, the QA pack and the sign-off
  describe the finished feature.

### 1.5 Scope at a glance

| Area | Deliverable | Phase |
|---|---|---|
| Database | 3 tables, 4 altered tables, 1 ENUM, 10 settings, migration `026_product_binary.sql` | 22A |
| Configuration | `BinaryNode` constants, product binary card, settings block, readiness panel, badges and filters | 22B |
| Placement | `BinaryPlacementService`, registration fields + validation, admin console, `bin/binary_place_tree.php`, the move route and rule, demo-tree seed | 22C |
| Money engine | `BinaryService`, `BinaryAward`, points ledger, two payment hooks, escrow, reversal, reconcile | 22D |
| Admin | `?ledger=binary` tab, order card, member panel, five report cards, readiness cards, CSV | 22E |
| Member | Product binary earnings tab (Left/Right team cards), checkout card, order card, dashboard tile, product panel | 22F |
| The tree | Vendored d3, `/genealogy?view=binary`, lazy JSON, in-node registration, invite links, no-JS list, admin explorer | 22G |
| Operations | Release job's second stream, `bin/binary_audit.php`, doctor, cron/runbook docs | 22H |
| Quality | ~250 new cases across the nine phases, one QA pack with ~80 checks, documentation | 22A–22I |

---

## 2. The phase protocol

### 2.1 What "self-contained" means in this document

"Self-contained" is a property of **delivery**, not of the schema. The phases share one data model and
one specification on purpose — duplicating them nine times would guarantee drift. A phase is
self-contained when all five of the following hold:

1. **It ships alone.** Merging only this phase leaves production correct, the suite green and every
   existing page unchanged in behaviour (except where the phase is explicitly a UI phase).
2. **It tests alone.** Its gate can be executed end-to-end on a database that contains only the phases
   up to and including itself. No later file, route or table is needed to observe the result.
3. **It explains alone.** Its section below contains everything needed to implement it: scope, files,
   rules, test cases, manual script, exit gate, rollback and hand-off. A developer who has never read
   this plan can start from §2, §14 and one phase section.
4. **It survives abandonment.** If every later phase were cancelled, this phase is still correct and
   still worth having. Nothing in it depends on a promise made by a phase that does not exist yet.
5. **It rolls back alone.** Reverting phase N leaves phases 1…N−1 intact and working.

The named anti-patterns this forbids:

- **The forward stub.** A file, column or switch that exists only so a later phase can fill it in. (A
  `TODO` in a comment is fine; a half-wired hook is not.)
- **The invisible phase.** A phase whose gate is "the code compiles". Every phase here has an
  observation a tester can make: a page, a count, a log line, a tree node or a wallet balance.
- **The invalid phase.** A phase that leaves the stream in a state that is *wrong* while it waits for
  the next phase. Money is either fully implemented (22D) or not implemented at all (22A–22C).
- **The shared contract.** A value one phase writes and a later phase changes (for example: shipping
  with a 10% pair rate in 22D and correcting it to 20% in 22F). Rates, ref types, column meanings and
  setting names are frozen at the phase that introduces them.

### 2.2 Phase map

| Phase | Name | Builds on | Effort | Cumulative suite |
|---|---|---|---:|---|
| **22A** | Foundation: schema, vocabulary, settings storage, install parity | — | 1.5 d | 1,484 → ≥ 1,506 |
| **22B** | Configuration and readiness: settings card, product card, badges | 22A | 1.5 d | ≥ 1,530 |
| **22C** | Placement: node model, registration, console, backfill, move | 22A, 22B | 2.5 d | ≥ 1,576 |
| **22D** | Money engine: points ledger, self, pairs, escrow, reversal, reconcile | 22C | 3.5 d | ≥ 1,650 |
| **22E** | Admin visibility: ledger tab, order card, member panel, reports | 22D | 1.5 d | ≥ 1,668 |
| **22F** | Member visibility: earnings tab, checkout, order, dashboard, product | 22D | 2.0 d | ≥ 1,694 |
| **22G** | The binary tree: d3 explorer and in-node registration | 22C, 22E, 22F | 3.0 d | ≥ 1,722 |
| **22H** | Operations: release stream, points audit, doctor, cron | 22D | 1.5 d | ≥ 1,734 |
| **22I** | Release: docs, QA pack, phase badge, acceptance | all | 1.5 d | 1,734 |

**Total: ~18.5 ideal days**, plus roughly one day of gate overhead spread across the nine phases. The
suite column is a floor, not a target: it counts the cases the phase's gate requires; the baseline is
today's **1,484 / 1,484** at `p21g-release`.

**Dependency graph**

```
22A ──▶ 22B ──▶ 22C ──▶ 22D ──┬──▶ 22E ──┐
                              ├──▶ 22F ──┼──▶ 22G ──▶ 22I
                              └──▶ 22H ──┘
```

22E, 22F and 22H are **siblings**: each depends only on 22D, none reads another, and they may be
delivered in any order or concurrently by different developers. 22G depends on 22C (the tree data), 22E
(the admin explorer's ledger links) and 22F (the member page it is linked from). The critical path is
22A → 22B → 22C → 22D → 22F → 22G → 22I ≈ **13.5 ideal days** of sequential work.

**Branches and tags.** One branch, `feature/product-binary`, cut from `feature/general-harvest`. One tag
per gate: `p22a-foundation`, `p22b-configuration`, `p22c-placement`, `p22d-money`, `p22e-admin`,
`p22f-member`, `p22g-tree`, `p22h-operations`, `p22i-release`. A phase starts from the previous tag and
never from an uncommitted tree.

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
| **Test plan** | `P22X-Tn` cases with exact expectations |
| **Manual gate** | numbered browser/CLI steps and the `QA22X-nn` identifiers in the QA pack |
| **Exit gate** | the checklist that closes the phase |
| **Rollback** | how to undo this phase alone |
| **Hand-off** | what now exists, which the next phase is allowed to assume |
| **Risks** | what can go wrong *in this phase* |

### 2.4 Definition of done — the universal gate

Every phase's exit gate contains these ten items, and its own phase-specific list. Copy the block into
`docs/phase-log.md` when the phase closes, and fill Appendix H.

```
[ ] Schema state matches the plan: MigrationTest green (22A applies migration 026; later phases change no schema)
[ ] `php bin/build_install_sql.php` run and `--check` exits 0
[ ] `php tests/run.php` — full suite green, exit 0, case count at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0 (money untouched or money correct)
[ ] `powershell -File tests/smoke.ps1` — all checks pass, exit 0
[ ] Every P22X-Tn case for this phase exists, is named for the phase, and passes
[ ] The manual script for this phase is executed by someone who did not write it
[ ] No new PHP warning, notice or deprecation in `storage/logs/` after the manual pass
[ ] The QA pack section for this phase is filled, with evidence under `docs/qa/evidence/phase-22/`
[ ] `docs/phase-log.md` records the phase, tag, counts and deviations; Appendix H is filled
```

### 2.5 What a phase may never do

- **Never leave the suite red.** A phase that cannot get to green is not finished; it is not "mostly
  finished with the tests to follow". The suite is the gate.
- **Never edit a later phase's files.** If 22D needs a change in a view, that view belongs to 22E or
  22F and the change waits. The only exceptions are the shared files each phase is assigned in its
  manifest (for example `DatabaseResetService` in 22A and again in 22H, for different reasons).
- **Never invent a contract.** If an implementation must diverge from §14–§16, the plan is amended
  first (a new decision or a corrected table), then the code. A divergence discovered mid-phase is
  recorded as a deviation in the phase log.
- **Never pay money in a configuration or placement phase** and never configure money in a payment
  phase. 22D assumes its settings and its tree already exist; it does not ship a hidden admin form "to
  make testing easier".
- **Never add a route beyond D-18's single placement route.** Every other surface is a query parameter
  on an existing page.
- **Never touch the sponsor tree.** `users.sponsor_id` is written only by registration and the admin
  member tools, exactly as today. A binary placement is not a sponsor link and never becomes one.

### 2.6 Before you begin (once, before 22A)

1. Cut `feature/product-binary` from `feature/general-harvest`; tag `p22-start`.
2. Back up the database (`mysqldump`) and the working tree. The project's standing rule: a backup
   outside the repository before any migration.
3. Record the baseline: `php tests\run.php` → **1,484 / 1,484**; `tests\smoke.ps1` → **≈ 278 / 278**;
   `php bin\build_install_sql.php --check` → exit 0; `php bin\wallet_reconcile.php` → exit 0. If the
   baseline is not green, stop — a gate measured from a red baseline proves nothing.
4. If the released Phase 21 counts differ from the baseline in the header, record the real numbers in
   `docs/phase-log.md` and correct this plan's counts before 22A. The counts appear in §15.8 and
   Appendix H; a stale count is a documentation defect, not a code defect.
5. Read §14 and §16 once, in full. They are the contract every phase is written against. Then read only
   the phase you are building; the other phases are not your scope.

---

## 3. Current state and the gap

### 3.1 The sponsor tree is the only tree

Every compensation stream in the application walks `users.sponsor_id`:

| Layer | File | What it does |
|---|---|---|
| Upline walk | [`SponsorService::chain()`](../system/app/Services/SponsorService.php#L153-L230) | cycle-guarded walk up the sponsor chain, capped at 10 |
| Downline | [`SponsorService::downline()`](../system/app/Services/SponsorService.php) | breadth-first team by level, capped at 10 |
| Unilevel money | [`UnilevelService::onOrderPaid()`](../system/app/Services/UnilevelService.php#L93-L306) | walks the sponsor chain, prices the product ladder, writes pending awards |
| Royalty | [`RoyaltyService`](../system/app/Services/RoyaltyService.php) | group sales and rank structure from the sponsor tree |
| Genealogy page | [`GenealogyController`](../system/app/Controllers/GenealogyController.php) | sponsor chain only, ten levels, no placement |

Phase 9 made this a **normative absence**, not an accident:

- [`MembershipTest`](../system/tests/Cases/MembershipTest.php#L906-L936) —
  `P9-T17 no binary column is referenced anywhere in the app` and `P9-T17 the schema carries no binary
  columns` (scans every `app/**/*.php` for `binary_parent_id`, `binary_position`, `binary_upline`,
  `binary_tree`, `pairs_paid`, `left_count`, `right_count`, and every `users` column for `binary_` /
  `pairs_`).
- [`MembershipTest`](../system/tests/Cases/MembershipTest.php#L788-L825) — `P9-T15` asserts
  `POST /register/check-upline`, `POST /register/auto-select-upline` and `GET /api/binary-tree` do not
  exist.
- [`MembershipViewTest`](../system/tests/Cases/MembershipViewTest.php) — the register form has no
  placement field, and the genealogy/earnings HTML contains the word "binary" nowhere.
- [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §2.1 — *"one sponsor per member"*.

**What this plan copies:** the cycle guard, the bounded walk, the company-root check, the "recipient must
be enabled and Active" gate, the wallet credit and the statement conventions.

**What this plan adds:** a second, independent parent link (`binary_nodes.upline_id` + `side`) with its
own uniqueness, depth and materialised path — **and the retirement of those four guards** (D-25, §3.7).

### 3.2 The repeat-purchase stream today

Phase 17 (unilevel) and Phase 18 (escrow) built most of the money machinery this stream reuses:

| Layer | File | What it does |
|---|---|---|
| Ladder | [`ProductUnilevel`](../system/app/Models/ProductUnilevel.php) | per-product levels 1–10, percentages, `baseFor()` |
| Engine | [`UnilevelService`](../system/app/Services/UnilevelService.php) | `onOrderPaid()`, `quoteCart()`, `releaseForOrder()`, `releaseDue()`, `reverseForOrder()`, `reconcile()` |
| Pending awards | [`UnilevelAward`](../system/app/Models/UnilevelAward.php) | `pending/credited/skipped/voided/cancelled`, `hold_days`, `credited_at`, CSV, filters |
| Active clock | [`UnilevelCycle`](../system/app/Models/UnilevelCycle.php) | `periodOf()`, `previous()`, `activeMap()`, `close()`; `unilevel_activity`, `unilevel_periods` |
| Release job | [`bin/unilevel_release.php`](../system/bin/unilevel_release.php) | releases due pending awards after delivery + hold |
| Wallet | [`WalletService`](../system/app/Services/WalletService.php) | `REF_TYPES` (13 values at `p22-start`), idempotent credit, reconcile |
| Member page | [`earnings.php?ledger=repeat`](../system/app/views/member/earnings.php#L343-L444) | the per-level team cards this plan mirrors for Left/Right |

**What this plan copies:** the escrow contract, the payment hooks, the reversal semantics, the
reconcile shape, the `?ledger=` tab pattern and the card language.

**What this plan deliberately does not copy:** the buyer-exclusion rule (D-07 pays the buyer), the
percentage-of-money arithmetic (this stream matches integer points) and the per-level sponsor walk
(this stream walks a different tree).

### 3.3 Points already exist but nothing matches them

`products.pv` exists since Phase 2 and became editable in Phase 17; `shop_order_items` snapshots a money
base (`unit_base`, `base_total`) but **no points column**, and no table matches volume. The one thing the
unilevel stream does with `pv` is convert it into the money base it pays percentages on. Nothing
accumulates it, nothing compares left against right, and no page shows a point balance.

So today a product has a P.V. and a repeat-purchase ladder; there is nowhere to say *"this purchase
placed 250 points on the left"* or *"your left team has 1,000 more points than your right"*.

### 3.4 Registration knows one parent

[`RegistrationService::register()`](../system/app/Services/RegistrationService.php) resolves the sponsor,
validates it, creates the user and (in `code`/`ewallet` modes) activates the package. Its input is
`mode, username, email, full_name, phone, password, password_confirm, sponsor, package_id, reg_code,
terms`. There is no second parent, no position and no placement validation.

The register form is a standalone page (`public/register.php` + `auth/register_form.php`, ~440 lines)
with live validation endpoints for username, sponsor and codes. Its four modes
(`standard`, `code`, `ewallet`, `pending`) all end in the same user insert. A signed-in member may
register someone else — which is exactly the door an **in-node registration modal** needs (D-18, 22G).

### 3.5 The genealogy page is a sponsor list

[`genealogy.php`](../system/app/views/member/genealogy.php) renders a team chain, a direct-recruits
table and a team-by-level grid. It has a live surface (`member.genealogy:{id}`), per-level pagination and
a referral block — but no placement tree, no node action and no registration entry point.

The reference implementation in `dmp/cart-system` is the pattern to beat:

| Reference feature | Where | What this plan does with it |
|---|---|---|
| d3 v7 tree from a JSON API | `views/member/genealogy.php#L443-L980` | re-implemented server-truthful, vendored, accessible and lazy (§11) |
| Lazy child loading | `apiBinaryTree()` + `loadChildren()` | same idea, one query per expanded branch, capped |
| Expand/collapse, zoom, reset, expand-all/collapse-all | tree controls | kept and extended (fit, depth, orientation, “my node”, search) |
| Empty-slot placeholders | `treeAccessor` | kept, plus a per-slot **+ register** action |
| In-node registration modal | `openRegisterModal()` + `regModal` | re-implemented on the existing registration service, with a no-JS link |
| Left/right counters on nodes | `left_count` / `right_count` | replaced by **points**, pairs and today's pairs |
| Live refresh | none | added (the app already has a Live revision system) |

What the reference does **not** have, and this plan's tree does: a server-rendered first paint, a no-JS
fallback, keyboard navigation, ARIA semantics, a breadcrumb, a search that respects privacy, deep-linking
(`?view=binary&root=&focus=`), a depth selector, a compact list mode for narrow screens, live refresh
that preserves pan/zoom, and a working “register here” flow that creates a real, activated member through
the application's own registration rules.

### 3.6 The gap, in one table

| Need | Today | This plan | Phase |
|---|---|---|---|
| A placement tree | none; four tests assert absence | `binary_nodes`, unique `(upline_id, side)`, depth + path | 22A / 22C |
| A required placement at registration | sponsor only | binary upline + side fields, server-side validation, atomic with the user insert | 22C |
| A points ledger | none | `binary_volume` (append-only, signed) + cached node balances + `bin/binary_audit.php` | 22A / 22D / 22H |
| A self bonus and pair awards | none | `binary_awards` (`self`/`pair`), escrowed like the unilevel stream | 22A / 22D |
| A checkout position | none | required radio when the cart has points; snapshotted on order + lines | 22C / 22F |
| Wallet money for the new stream | `REF_TYPES` has 13 values | `binary`, `binary_reversal` (15) | 22A |
| A release path | one job, one stream | the same job releases both streams (`--stream=`) | 22H |
| Member visibility | `/earnings` has package, repeat and royalty tabs | a `?ledger=binary` tab with Left/Right team cards that name no upline | 22F |
| Admin visibility | `/admin/commissions` has package/repeat/royalty tabs | a `?ledger=binary` tab, order card, member panel, reports | 22E |
| A genealogy tree | sponsor list only | d3 binary explorer with in-node registration | 22G |
| Placement correction | none | audited empty-subtree move (one route) + backfill tool | 22C |

### 3.7 The guards this plan retires (and what replaces them)

| Retired guard | Why it must go | Replacement (ships in 22C) |
|---|---|---|
| `MembershipTest P9-T17 no binary column is referenced anywhere in the app` | The binary module must reference `binary_nodes.upline_id`, `binary_position`, … | `BinaryFoundationTest`: the **only** files allowed to contain the binary vocabulary are the 14 named binary files (§15.6); every other `app/**/*.php` is scanned and must be clean **except** for the four hooks listed in the same manifest |
| `MembershipTest P9-T17 the schema carries no binary columns` | The tree is a separate table, so `users` can stay clean | Kept **unchanged** — `users` must still carry no `binary_*`, `pairs_*`, `left_count` or `right_count` column. The tree lives in `binary_nodes`, and that is now the architectural invariant |
| `MembershipTest P9-T15 binary routes are gone` | One placement route is added | Kept for `POST /register/check-upline`, `POST /register/auto-select-upline`, `GET /api/binary-tree` (still must not exist) and extended with an assertion that exactly one route contains `placement` |
| `MembershipViewTest the register form has no binary placement field` | The form now requires a placement | Replaced by two cases: with the placement feature on, the form renders `binary_upline` + `binary_position` and a required marker; with it off, the form renders neither (the off state is tested with a scratch setting) |
| `MembershipViewTest genealogy/earnings HTML contains no "binary"` | Both pages now carry binary content | Replaced by copy guards: the earnings binary tab contains no ancestor username, no "upline" and no "sponsor" (§14.12); the sponsor view of `/genealogy` is unchanged when `view=binary` is absent |

The replacements are **stronger**, not weaker: the old guards said "no binary anywhere"; the new ones say
"binary only where the plan says, and never in the sponsor tree's money".

---

## 4. Decisions

Each decision is a contract for the implementation and the tests. Alternatives are listed so a later
review can reopen a decision without re-deriving it. The **Ships in** column is the phase that
introduces the rule; no later phase may change it.

| ID | Decision | Rationale | Rejected alternative | Ships in |
|---|---|---|---|---|
| **D-01** | **One placement tree, separate from the sponsor tree.** `binary_nodes` is keyed by user and carries `upline_id` + `side`; `users.sponsor_id` is untouched and remains the only chain for package referral, unilevel, royalty and Harvest | A second parent on `users` would touch every stream's walk and every Phase 9–21 test; a separate table is additive and provably isolated | Add `binary_parent_id`/`binary_position` to `users` — the reference's shape, but it rewrites the sponsor tree's meaning and fails the one-tree expectation of every existing test | 22A/22C |
| **D-02** | **The company account is the root of the tree and is never paid.** The primary admin's node has no upline and no side; the pairing walk stops at its children; its own purchases place no points | The company cannot be its own distributor; the reference excludes it from unilevel too (Phase 17 T8) | Let the company pair and pay itself — untraceable money and a pointless liability | 22A/22C |
| **D-03** | **Placement (upline + side) is required at registration** and validated server-side: upline exists and is eligible, side is `left`/`right`, slot is free, no cycle, placement is inside the registrar's or sponsor's subtree (admins: anywhere) | The user's requirement; and a tree with holes cannot pair | Auto-placement only (first free slot) — loses the member's choice and makes the tree arbitrary | 22C |
| **D-04** | **Placement is immutable once the subtree has volume or awards.** An admin may move an **empty** subtree only; every move is audited with before/after | Moving a productive leg changes who earns, retroactively and invisibly | Free moves with volume carry — a money-changing edit with no ledger event | 22C |
| **D-05** | **Points = `ROUND(products.pv)` per unit × quantity**, the same P.V. the unilevel stream uses; one point value per product | The user asked to capitalise the existing point values; a second points column would let the two streams disagree about the same product | A separate `binary_points` column per product — two numbers to maintain, no benefit | 22B/22C |
| **D-06** | **One side per order**, chosen at checkout, required when the order has points, snapshotted on `shop_orders.binary_position` and on the lines (`unit_points`, `points_total`) | An order is one payment event; per-line sides would let one order sit on both sides and pair with itself | Per-line positions — more UI, self-pairing, harder to explain | 22C/22F |
| **D-07** | **The buyer earns a Self Bonus on their own purchase**: `round(order_points × binary_self_pct / 100, 2)`, once per order, no Active gate | The user's explicit uniqueness claim: *"you earn from your own purchase"*; a guaranteed rebate makes the promise unconditional | No self bonus, own-node pairing only — conditional on having an opposite side, so a new member earns nothing on a first purchase | 22D |
| **D-08** | **The buyer's purchase lands on the buyer's own node** (chosen side) and **rolls up to every binary upline** on the side the buyer sits under | The user's rule: the points you accumulate are the basis of your binary-upline's pair | Own node only (no upline pairing) — contradicts the requirement; uplines only (no own-node pairing) — contradicts "you earn from your own purchase" | 22D |
| **D-09** | **Pair matching is per node, greedy and exact**: one pair = `binary_pair_points` on each side; a pair pays `round(binary_pair_points × binary_pair_pct / 100, 2)`; matched points are consumed; the remainder carries forever; pair size and rate are **global** | Pairs compare points from different products, so a per-product pair rate is incoherent; carry-forever matches "points accumulated" | Per-product pair rates — cannot add up; periodic flush — a business decision not yet made (Q6) | 22D |
| **D-10** | **One self award per order and one pair-batch award per (order, node)**; `UNIQUE (order_id, user_id, kind)` | Exact idempotency anchors: a replay cannot write a second row, and a batch keeps the ledger small | One award row per pair — 20 rows for a 20-pair order, and no single number to reconcile | 22A/22D |
| **D-11** | **Awards are escrowed exactly like the unilevel stream**: `pending` at payment, `credited` after delivery + `binary_hold_days`, released by the **same job** (`--stream=binary`) | One escrow contract, one cron entry, one set of wallet semantics; a second releaser would drift | Immediate credit at payment — the pre-18 shape the project already replaced | 22D/22H |
| **D-12** | **Eligibility reuses the unilevel Active snapshot** (`unilevel_activity`, `UnilevelCycle::activeMap()`), the same month clock and the same close job; `binary_requires_active` = 1 | One month, one snapshot, one close; a second period table would drift from the first | A separate binary period/close — duplicate jobs, duplicate disputes | 22D |
| **D-13** | **An ineligible recipient keeps its volume**: no pair is paid, **no points are consumed**, no `skipped` row is written; the points pair when the recipient becomes eligible | Points are the member's volume; consuming them for a skipped payment would destroy team volume silently | Consume and write `skipped` (the unilevel shape) — wrong here, because the level is not consumed in a binary | 22D |
| **D-14** | **A daily pair cap per recipient** (`binary_daily_pair_cap`, 20 by default, 0 = unlimited), counted on the Asia/Manila calendar day of `paid_at`; excess volume carries | A single big order must not pay an unbounded day; the cap is the operator's brake | No cap — an unbounded daily liability; per-order cap — trivially bypassed by splitting orders | 22D |
| **D-15** | **No compression, no flush, no expiry, no rank/team-size requirement, no retroactive awards, no package input** | The stream's only inputs are paid repeat purchases and the tree; every extra requirement would need its own rule and test | Rank-gated pairs (the royalty shape) — not asked for; flush — see Q6 | all |
| **D-16** | **Off by default** at the global master and the product master; existing products start at 0 and a new product's binary switch is off | Deploying code must not start paying money | Enabled by default — a migration would start paying the reference preset | 22A/22B |
| **D-17** | **Wallet ref types `binary` and `binary_reversal`**; the release writes `binary` only | One idempotent movement per award, consistent with `commission`/`unilevel`/`royalty` | One ref type per kind (self/pair) — the award's `kind` already identifies it | 22A |
| **D-18** | **Exactly one new route**: `POST /admin/members/{id}/placement` (admin, CSRF, audited). Registration placement rides on the existing `register.php`; the tree JSON, the ledger tab, the earnings tab, the reports, the console and the explorer are query parameters on existing pages | A structural write needs a guarded endpoint; everything that only *reads* can stay on an existing URL, keeping `ParityTest` and the inventory honest | Zero new routes — would force the move into a CLI, unusable for support; many new routes — more surface and more tests | all |
| **D-19** | **The tree is a vendored d3 v7 explorer**: server-rendered first paint, lazy subtree JSON from the same URL, no CDN, no-JS list fallback, ARIA tree semantics, keyboard and touch, responsive by `viewBox` + container size, live refresh that preserves state | The reference depends on a CDN, ships no fallback, has no keyboard path and reloads on resize; this plan must at least match it and does better | A jQuery tree plugin — no layout control, no lazy geometry, not a genealogy tree | 22G |
| **D-20** | **Migration `026_product_binary.sql`**; at `p22i-release`: **42 tables · 89 settings** | Phase 21 ends at 39/79; this stream adds 3 tables and 10 settings | A later migration number — none is free before Phase 21 ships | 22A |
| **D-21** | **`APP_PHASE` becomes 22** | The build must report the phase that shipped the feature | Leave at 21 — the new UI would render with a stale phase badge | 22I |
| **D-22** | **The member's binary page names no upline.** The Left/Right team cards, the stats, the ledger and the copy speak only of the member's own team, points and pairs; the tree is the only surface that shows ancestors | The user's explicit requirement — the page is *"your team as aid to your own empowerment"*, not a view of your upline's income | Show the upline chain on the earnings tab (the unilevel page's "Team chain" shape) — leaks the upline's position and changes the page's purpose | 22F |
| **D-23** | **Points and money are separate ledgers**: `binary_volume` is the source of truth for every point; `binary_nodes` caches balances; `binary_awards` holds money. No pair is paid from a number the ledger cannot explain | A points bug must not move pesos; a money bug must not rewrite points; both must be independently reconcilable | One table carrying points and money — a single corruption is both | 22A/22D |
| **D-24** | **The demo seed carries a deterministic demo tree** (root + five demo members on fixed slots), so QA and demo installs have a tree without running the backfill | A demo install with no tree cannot exercise the UI; the seed is idempotent and is re-run by the reset | Leave demo installs tree-less — every QA run starts by running a CLI | 22C |
| **D-25** | **The Phase 9 no-binary guards are retired on purpose** and replaced by the ownership guards of §3.7 | A feature that must exist cannot be guarded against by a test that says it must not | Leave the guards and name columns to dodge the regex — dishonest and untestable | 22A/22C |
| **D-26** | **No free-slot reservation.** An invite link (`/register?ref=…&upline=…&side=…`) is a hint; the first valid registration wins the slot | Reservations need expiry, release and a dispute story; the slot's unique key is the only guarantee that matters | Reserve for N hours — a stale reservation can block a real registration, and two members can still race | 22G |

---

# Part II — The phases

## 5. Phase 22A — Foundation

**Goal.** A database that carries every object the feature will ever need, an install/reset/migration path
that knows about them, and a wallet vocabulary that can name the two new movement kinds — with **no code
path reading any of it**. After 22A the application behaves exactly as it did at `p22-start`.

**Builds on.** Nothing. This is the root of the graph.

**Ships alone because.** The three new tables have no reader, the new columns have no writer, and the two
new `ref_type` values cannot appear in a ledger row because nothing constructs them. The only observable
changes are two filter options in the wallet statement (Rule 6) and different numbers in `install`'s
verification output.

**Effort.** 1.5 ideal days. **Suite.** 1,484 → ≥ 1,506 cases, all green.

**Reference.** §15 in full (the model and the migration text), §15.2 (the altered tables), §14.3
(points), §14.4 (the settings).

**Deliverables**

**Database**

- `system/database/migrations/026_product_binary.sql` — the migration of §15.4, verbatim:
  three tables (`binary_nodes`, `binary_volume`, `binary_awards`), four altered tables (`products`,
  `shop_orders`, `shop_order_items`, `ewallet_ledger`), five new indexes, and the ten settings.
  Idempotent: every `ALTER` is guarded by an `information_schema` probe and a `PREPARE`/`EXECUTE` pair,
  every `CREATE TABLE` is `IF NOT EXISTS`, and the settings insert is
  `ON DUPLICATE KEY UPDATE key_name = key_name`.
- `system/database/schema.sql` — the fresh-install mirror of §15.5, block for block, in the same table
  order the file already uses (products, `binary_nodes` after `products`, `binary_volume` and
  `binary_awards` after `shop_order_items`/`unilevel_awards`), so `MigrationTest`'s migrated-vs-fresh
  comparison stays green.
- `system/database/seeds/001_settings.sql` — the ten keys, in the same order and with the same defaults
  as the migration. `binary_ledger_since` uses `DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')`, like
  `unilevel_ledger_since`.
- `system/database/install/footer.sql` — the expected-counts comment: **42 tables, 89 settings**.
- `system/database/install.sql` — regenerated by `php bin/build_install_sql.php`; never hand-edited.

**Code**

- `system/app/Services/DatabaseInstaller.php` — `EXPECTED`: `tables_created` **39 → 42**,
  `settings_rows` **79 → 89**; the settings failure message becomes "86 keys plus the three
  `install_*` metadata rows". `TRANSACTIONAL_TABLES` gains `binary_awards` and `binary_volume`, so a
  fresh install fails verification if either is non-empty. `binary_nodes` is **not** transactional: a
  production install may legitimately carry the root node, and a demo install carries six (seed 005,
  Phase 22C). `DEMO_TABLES` is unchanged.
- `system/app/Services/DatabaseResetService.php` — `ALL_TABLES` gains the three names (39 → **42**);
  `ZERO_TABLES` gains all three; `AUTO_INCREMENT_TABLES` gains `binary_volume` and `binary_awards`
  (`binary_nodes` has no auto-increment column); `DELETE_GROUPS` gains a **Product binary** group
  *before* the orders group and the members group, in FK order: **volume → awards → nodes** (volume
  references awards; nodes reference users and each other). FK checks are never disabled (D-IR-08).
  **The integrity map is not touched in this phase** — it names `BinaryService`, which does not exist
  until 22D.
- `system/app/Services/WalletService.php` — `REF_TYPES` gains `binary` and `binary_reversal`. This
  *must* ship with the enum: `WalletTest` P5-T8 asserts that `REF_TYPES` and the live enum agree **in
  both directions**, so the column and the constant cannot land in different phases.
- `system/app/helpers.php` — `wallet_ref_label()` and `wallet_ref_icon()` gain the two values
  (*"Product binary"*, *"Product binary reversal"*; `bx-git-branch` / `bx-undo`), because
  `partials/wallet_statement.php` renders one filter option per `REF_TYPES` entry.

**Docs**

- `docs/qa/phase-22-product-binary.md` — created with the header, the "what is already automated"
  block and **section A only** (checks `QA22A-01` … `QA22A-08`). Every later phase appends its own
  section; the file is never rewritten.
- `docs/qa/README.md` — one Phase 22 row in the contents table, marked *in progress (22A)*.
- `docs/qa/evidence/phase-22/README.md` — the evidence folder index.
- `docs/install-reset.md` — the new counts and the three tables' reset behaviour.

**Not yet**

- No `BinaryNode`, no `BinaryPlacementService`, no `BinaryService`, no `BinaryAward`, no controller, no
  view, no route, no job, no integrity-map entry. The tables are empty and unread. The product form has
  no binary card and the settings page has no binary block.

**Rules**

1. **The migration is additive and idempotent.** Re-running it on a migrated database is a no-op: no
   error, no duplicate settings, no lost rows. `MigrationTest` proves it by running the file twice.
2. **`schema.sql` and the migration must be equivalent.** `MigrationTest` builds one database from the
   migrations and one from `schema.sql`, then compares table structures. A column that exists in one and
   not the other fails the phase.
3. **The seed and the migration must agree.** `SeedParityTest` fails unless seed 001 carries every
   settings key the schema/migration introduces. The ten keys are added to both in this phase.
4. **Defaults are off.** `products.binary_enabled = 0`, `binary_enabled = '0'`. A fresh install and a
   migrated install both pay nothing.
5. **No backfill of money or points.** `binary_ledger_since` is set to the migration instant. Historical
   orders are not touched, and no `UPDATE … JOIN` runs over `shop_orders`.
6. **The one visible change is the wallet statement filter.** `partials/wallet_statement.php`
   enumerates `WalletService::REF_TYPES`, so the member and admin wallet filters gain *Product binary*
   and *Product binary reversal* options in this phase. They cannot match a row until 22D. This is
   accepted, documented in the QA pack, and is the price of keeping the enum and the constant in one
   phase (Rule 7).
7. **Enum and constant move together.** See `WalletService::REF_TYPES` above. Never leave the database
   accepting a value PHP rejects, or vice versa.
8. **The reset empties the tree and keeps the configuration.** After a business reset, `binary_nodes`,
   `binary_volume` and `binary_awards` are empty; the ten settings survive. A demo reset re-creates the
   demo tree from seed 005 (Phase 22C adds those rows; until then the demo seed simply has no nodes).
9. **The three tables' column order and types are frozen here.** 22B–22I may add no column. If a phase
   discovers it needs one, that is a plan amendment (a new decision), not a quiet `ALTER` in a later
   migration.
10. **No application file outside the manifest changes.** `git diff --stat` must touch only the files
    listed above. A change in a controller or a view means the phase has crept.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P22A-T1 | Apply 026 to a database at `p22-start` | 3 tables, 4 altered tables, the two `shop_orders` indexes and the three tables' keys, the extended enum and 10 settings exist; no error |
| P22A-T2 | Run 026 a second time | No error; object counts and settings identical to the first run |
| P22A-T3 | Migrated vs. fresh (`MigrationTest`) | Structures identical; the test's ordered migration list includes `026_product_binary.sql` |
| P22A-T4 | `SeedParityTest` | Seed 001 and the schema carry the same 89 keys, including the ten new ones |
| P22A-T5 | `php bin/build_install_sql.php --check` | Exit 0, no output: the bundle matches its sources |
| P22A-T6 | Fresh install (`InstallTest`) | Verification reports **42** tables, **89** settings, 2 staff, 0 members, 7 products, 2 packages, 18 levels, 10 contents |
| P22A-T7 | Insert one `binary_awards` row, then run install verification | The `transactional` check fails with the table named — proving Rule 8's guard is wired |
| P22A-T8 | Business reset (`DbResetTest`) | `binary_nodes`, `binary_volume`, `binary_awards` are 0; the ten settings survive; `ALL_TABLES` is 42 names |
| P22A-T9 | `WalletService::REF_TYPES` vs. the live enum (`WalletTest` P5-T8) | Exactly equal in both directions, 15 values |
| P22A-T10 | `wallet_ref_label('binary')` / `('binary_reversal')` | "Product binary" / "Product binary reversal"; icons resolve to a `bx-` class |
| P22A-T11 | Defaults on a migrated database | Every `products.binary_enabled = 0`; `binary_enabled = '0'`; `binary_self_pct = '5.000'`; `binary_pair_points = '1000'`; `binary_pair_pct = '10.000'`; `binary_daily_pair_cap = '20'`; `binary_requires_active = '1'`; `binary_hold_days = '7'`; `binary_release_enabled = '1'`; `binary_reversal_enabled = '1'`; `binary_ledger_since` is a valid timestamp |
| P22A-T12 | Delete a user that has a binary node | The node cascades (FK `ON DELETE CASCADE`); a node with children is refused (`upline_id` FK `RESTRICT`) |
| P22A-T13 | Insert two nodes under the same `(upline_id, side)` | `PDOException` 23000 — the slot unique key holds |
| P22A-T14 | Insert a node with `upline_id = NULL` and `side = 'left'` | Check constraint fails — both null or both set |
| P22A-T15 | Insert a node whose `upline_id = user_id` | Check constraint fails — no self-parent |
| P22A-T16 | Insert a `binary_volume` `pair` row with no `award_id`, or an `own` row with negative points | Check constraints refuse both malformed rows (§15.4) |
| P22A-T17 | Second `binary` award for the same `(order_id, user_id, kind)` | `PDOException` 23000 — the award anchor holds |
| P22A-T18 | Full suite | 1,484 + the phase's cases, all green, exit 0; `ParityTest` and `RouterTest` untouched and green |
| P22A-T19 | `docs/qa/phase-22-product-binary.md` | Exists with section A only; every `QA22A-nn` is present and unique |
| P22A-T20 | `php bin/wallet_reconcile.php` | Exit 0; six sections, no binary section yet (22H adds it) and no new mismatch |

**Manual gate — `QA22A-01` … `QA22A-08`**

Run on a **scratch** schema built from the previous tag, not on the development copy.

1. `QA22A-01` — `mysql < 026_product_binary.sql`, then `SHOW TABLES LIKE 'binary%'` → three tables.
   Re-run the file → no error.
2. `QA22A-02` — `SHOW COLUMNS FROM products` → `binary_enabled`;
   `shop_orders` → `binary_position`, `binary_processed_at`, `binary_points`, `binary_self_total`,
   `binary_pair_total`, `binary_total`; `shop_order_items` → `unit_points`, `points_total`;
   `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'` → the enum ends `'binary','binary_reversal'`.
3. `QA22A-03` — `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'binary%'` → 10.
4. `QA22A-04` — open `/admin/settings`: no product-binary block appears (it is 22B's). Open
   `/admin/products/{id}/edit`: no binary card.
5. `QA22A-05` — place and pay an e-wallet order as a member. No `binary_awards` row and no
   `binary_volume` row appear; the order page and the sponsor's wallet are unchanged.
6. `QA22A-06` — `/wallet` → the statement filter now lists *Product binary* and *Product binary
   reversal*; selecting either returns no rows.
7. `QA22A-07` — rebuild the bundle and re-install on an empty schema: the verification pack prints
   `tables_created = 42`, `settings_rows = 89`; `php bin/wallet_reconcile.php` exits 0.
8. `QA22A-08` — run the development reset console once: the three binary tables are empty, the ten
   settings survive, and the verification passes.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22A-T1 … P22A-T20 pass
[ ] MigrationTest proves a second run is a no-op and that migrated == fresh
[ ] InstallTest / InstallSqlTest / SeedParityTest / DbResetTest / WalletTest green at 42 / 89 / 15
[ ] git diff --stat touches only the 22A manifest
[ ] Tag p22a-foundation; docs/phase-log.md entry; Appendix H row
```

**Do not start 22B until every box is ticked.**

**Rollback**

Drop the three tables, drop the six `shop_orders` columns and the two `shop_order_items` columns, drop
`products.binary_enabled`, restore `ewallet_ledger.ref_type` to its previous enum, delete the ten
settings, and revert `REF_TYPES`/labels. No money exists to lose, so the only care needed is restoring
the enum *before* dropping the tables (a row cannot hold a value the enum no longer allows — there are
none, but do it in that order for consistency). `schema.sql`, the seed and `install.sql` return to their
previous content; `install.sql` is regenerated, never hand-repaired.

**Hand-off to 22B**

- A migrated database with every column, table and setting the feature needs.
- A fresh-install bundle that produces the same database, verified by `MigrationTest`.
- A reset engine that empties the three tables and preserves the ten settings.
- A wallet vocabulary that can name `binary` and `binary_reversal`.
- **Nothing that reads any of it.** 22B may assume the tables exist and are empty.

**Risks**

| Risk | Mitigation |
|---|---|
| `schema.sql` drifts from the migration | `MigrationTest` compares structures; the gate runs it, not just the author |
| `install.sql` is hand-edited | Regenerated only; `--check` in the gate |
| The enum/`REF_TYPES` pair is split across phases | Both in 22A, with a test that compares them in both directions |
| The reset leaves binary rows behind, or deletes nodes after members | `DbResetTest` asserts the three tables are zero and that the delete order is awards → volume → nodes → members |
| The migration's `binary_nodes.root_id` self-FK is too clever for MySQL | `root_id` references `users`, not `binary_nodes`; the only self-ish relation (`upline_id`) references `users` too, so no circular FK |
| A later phase needs a column | Rule 9: a plan amendment, never a quiet `ALTER` |

---

## 6. Phase 22B — Configuration and readiness

**Goal.** An administrator can switch the Product binary stream on, set its five money rules in one card
on `/admin/settings`, flag each product for the binary and see its points per unit — and the
**readiness** card tells them exactly what is still unconfigured. **No money moves.**

**Builds on.** 22A (the columns and the settings).

**Ships alone because.** Every value this phase writes is read by nothing until 22D: the switch is
inert, the pair rate is unused and the product flag is unread. The phase is the only one a non-developer
can verify entirely by eye, which makes it the right place to stop and look before any money code exists.

**Effort.** 1.5 ideal days. **Suite.** ≥ 1,530 cases.

**Reference.** §14.4 (the settings), §15.2 (the product column), §14.11 (rounding of points).

**Deliverables**

**Database.** None. 22A shipped every column this phase writes.

**Code**

- `system/app/Models/BinaryNode.php` — **constants only in this phase**: `KINDS`, `AWARD_KINDS`,
  `STATUSES`, `SIDES`, `MAX_DEPTH` (100), `POINTS_MAX` (1,000,000 per line), `STATUS_LABELS`,
  `STATUS_BADGES`, `SIDE_LABELS`. No query method yet; 22C adds them. The constants ship now because
  22B's badge and card copy need the labels and both later phases compare against them.
- `system/app/Models/Product.php` — `validate()` gains `binary_enabled` (bool) and the cross-field rule
  "`binary_enabled = 1` with `pv = 0` is refused with the reason *"Give the product a P.V. or turn its
  binary switch off."*"; `save()` writes the new column in the same transaction as the rest of the
  product; `rowsFor()` selects `binary_enabled, pv`; `referencesMap()` is unchanged (no binary rows can
  exist yet).
- `system/app/Controllers/AdminProductController.php` — `save()` reads `binary_enabled`, merges the new
  validation rule, audits `product.binary.update` (before/after) only when the switch or P.V. changed;
  `create()` pre-fills the switch **off**; `index()` accepts `?binary=on|off`.
- `system/app/Controllers/AdminSettingsController.php` — `BINARY_FIELDS` and `BINARY_TOGGLES` in the same
  `{key,label,rule,suffix,hint}` / `{label,hint}` shapes as `REPEAT_FIELDS`, merged into
  `sectionKeys()`, `sectionToggles()`, `fieldRules()`, `fieldLabels()` and `settingsContext()` (as
  `binary_fields` / `binary_toggles`). Section stays `commissions`. The visible keys are the nine of
  §14.4 that carry a rule; `binary_ledger_since` is never rendered.
- Views: `admin/products/_binary.php` (new partial), `admin/products/form.php` (include the card after
  *Unilevel repeat-purchase bonus*), `admin/products/_rows.php` (badge), `admin/products/index.php` (the
  `?binary=on|off` filter), `admin/settings/_commissions.php` (the sub-block and the readiness card).
- `system/app/Models/Product.php` — `binaryStatusMap(array $ids)` for the list: one query returning
  `binary_enabled`, `pv`, `points` per product (never one query per row).
- `system/public/assets/js/app-product-binary.js` (new) — the live readout: points per unit, the
  computed self bonus per unit and the pair value at the current global preset; a **Readiness** link.
  Progressive enhancement only.

**Readiness (this phase's shape)**

The settings card carries a small **Product binary readiness** panel with four counts and a link:

| Count | Source | Copy |
|---|---|---|
| Products with the binary on and P.V. 0 | `products` | *"N product(s) are flagged for the binary but earn 0 points. Set a P.V. or switch them off."* → `/admin/products?binary=on` |
| Products with the binary off | `products` | *"N product(s) do not place points."* → `/admin/products?binary=off` |
| Global switch | `settings` | *"The stream is off. Nothing is paid."* |
| Preset check | computed | *"1,000 points per pair pays ₱100.00 at 10%."* — a read-only sentence, never a button |

The **member** counts (unplaced members, floating nodes) arrive in 22C, when the tree exists.

**Docs.** `docs/qa/phase-22-product-binary.md` gains section B (`QA22B-01` … `QA22B-09`).
`system/README.md` gains the binary settings group and the product card. `docs/deploy.md` gains the nine
visible keys and the readiness panel. `docs/admin-guide.md` gains "Flagging a product for the binary".

**Not yet.** No `BinaryNode` query, no placement, no `BinaryService`, no award row, no member-facing page,
no tree. Paying an order after this phase still writes no `binary_awards` and no `binary_volume` row.

**Rules**

1. **The switch is the only gate that matters in this phase.** `binary_enabled = 0` renders the offer
   nowhere and pays nothing; a product flag alone does nothing.
2. **`binary_self_pct` is a percentage 0–100 with three decimals** (`0.000` disables the self bonus
   without touching the switch). The field's `rule` is
   `required|numeric|gte:0|lte:100|decimal:3`; the input carries `min="0" max="100" step="0.001"`.
3. **`binary_pair_points` is a whole number, 1–1,000,000.** 0 is refused: a pair of zero points would
   make every purchase a pair. The default is 1,000.
4. **`binary_pair_pct` is a percentage 0–100 with three decimals.** `0` is allowed (points still
   accumulate and consume; nothing is paid) and is surfaced by the readiness sentence.
5. **`binary_daily_pair_cap` is 0–1,000; 0 means unlimited.** The card's hint says so.
6. **`binary_hold_days` is 0–365.** The award snapshots it at payment; a change affects new awards only.
7. **The four toggles ship on except the master:** `binary_enabled` off,
   `binary_requires_active` on, `binary_release_enabled` on, `binary_reversal_enabled` on.
8. **A product's P.V. is the only points input.** The card shows `P.V. × 1 = N points per unit` and
   links to the *Pricing & stock* P.V. field; it never edits P.V. itself (the field has one home).
9. **The badge never lies.** `Binary 250 pts` when the switch is on and `pv > 0`; `Binary 0 pts` when
   the switch is on with `pv = 0` (a configuration error the operator can see); nothing when off.
10. **The card is one card, in the same place as the unilevel card**, after it in the left column. The
    P.V. field stays in *Pricing & stock*.
11. **No JavaScript-only validation.** With JS off the form still saves and still validates; only the
    live readout is missing.
12. **The readiness panel is read-only.** It links to the filtered product list; it never changes a
    product or a setting.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P22B-T1 | Save a product with `binary_enabled = 1`, `pv = 250` | Row saved; the form re-reads the switch on and `250` points per unit |
| P22B-T2 | Save `binary_enabled = 1` with `pv = 0` | Field error on `binary_enabled`; the product row is unchanged (one transaction) |
| P22B-T3 | Save `binary_enabled = 0` with `pv = 0` | Accepted |
| P22B-T4 | Save `binary_enabled = 1`, `pv = 99.6` | Accepted; the card reads **100 points** per unit (`ROUND`) |
| P22B-T5 | `pv` above 1,000,000 | Field error on `pv` (the Phase 17 rule still applies) |
| P22B-T6 | Settings save round-trip: self 5, pair points 1,000, pair 10, cap 20, hold 7 | Values persist; one `settings.update` audit row |
| P22B-T7 | Settings: `binary_pair_points = 0` | Refused on that field |
| P22B-T8 | Settings: `binary_self_pct = 100.001`, or four decimals | Refused on that field |
| P22B-T9 | Settings: `binary_daily_pair_cap = 0` | Accepted and rendered as *Unlimited* in the hint (the stored value stays `0`) |
| P22B-T10 | Settings: `binary_hold_days = 366` | Refused |
| P22B-T11 | Settings: `binary_enabled` off, all other values set | The settings page says the stream is off; a paid order still writes nothing |
| P22B-T12 | Product list badge | `Binary 250 pts` on, `Binary 0 pts` on-with-zero, nothing when off |
| P22B-T13 | Product list `?binary=on` / `off` | Correct filtered rows; the filter survives pagination and combines with `?status=` |
| P22B-T14 | `binaryStatusMap()` for 20 products | Exactly one query (no N+1); missing products read as off |
| P22B-T15 | Product form renders | The switch, the points readout, the P.V. link; **no** pair-size, pair-rate or self-rate input on the product form |
| P22B-T16 | Product form after a validation error | The posted values and the error messages survive; the JS readout does not run |
| P22B-T17 | `product.binary.update` audit | Written only when the switch or P.V. changed; before/after present |
| P22B-T18 | Settings card | Nine visible keys inside **Commissions**; the master switch first; `binary_ledger_since` is not rendered |
| P22B-T19 | Readiness panel | The four counts match the fixture products; the links filter correctly |
| P22B-T20 | Readiness copy at the preset | Says *"1,000 points per pair pays ₱100.00 at 10%."*; recomputed when the pair values change |
| P22B-T21 | Member pages | No binary copy appears on `/shop/{id}`, `/checkout`, `/orders/{id}`, `/earnings` or `/genealogy` (22F/G own those) |
| P22B-T22 | Pay an e-wallet order after configuring a product and the global switch **on** | No `binary_awards` row, no `binary_volume` row, no `binary` wallet movement; the order page is unchanged (the phase has no hook) |
| P22B-T23 | The full suite | Green; `InstallTest` still 42/89 (this phase adds no schema) |
| P22B-T24 | Settings page with the stream on | The card's hint warns that orders now place points and pay (the copy is deliberate: the operator must know the switch is live) |
| P22B-T25 | `git diff --stat` | Touches only the 22B manifest; `routes.php`, `routes_inventory.php` and `ParityTest` untouched |
| P22B-T26 | Product delete with the binary switch on and no binary rows | Deleted normally (no award reference exists yet) |

**Manual gate — `QA22B-01` … `QA22B-09`**

1. `QA22B-01` — open a product's edit page: the *Product binary* card shows the switch **off**, the
   points readout at the product's P.V. (or *0 points — set a P.V.*), and the self/pair sentence at the
   global preset.
2. `QA22B-02` — set P.V. ₱250, switch the binary on, save. Re-open: the switch is on and the card reads
   *250 points per unit*.
3. `QA22B-03` — set the P.V. to 0 with the switch on and save: the form comes back with the field error
   and the values as typed; the product still shows its old P.V.
4. `QA22B-04` — `/admin/products`: the configured product shows `Binary 250 pts`; the filter
   *Product binary — on* returns it and *off* does not.
5. `QA22B-05` — `/admin/settings` → **Commissions** → *Product binary*: the master switch, self 5%,
   pair size 1,000, pair rate 10%, cap 20, hold 7 days and the four toggles render. Save with pair size
   `0` → refused. Save `1000` → persisted, and the audit desk shows the change.
6. `QA22B-06` — the readiness panel: with two products flagged and one P.V. 0, it reports *1 product
   earns 0 points*; the link opens the filtered list.
7. `QA22B-07` — turn the stream on, set self 0% and pair 0%: the readiness sentence says pairs pay
   ₱0.00; a paid order still writes nothing (22D is not built).
8. `QA22B-08` — disable JS and save the product form: it still saves; only the readout is missing.
9. `QA22B-09` — as a member, open `/shop/{id}`, `/checkout`, `/earnings` and `/genealogy`: no binary
   content appears anywhere; `binary_awards` and `binary_volume` are still empty.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22B-T1 … P22B-T26 pass
[ ] The card, the badge, the readiness panel and the settings block render on desktop and mobile widths
[ ] No pair-size, pair-rate or self-rate control exists on the product form (the values are global)
[ ] Paying an order still writes zero binary rows (P22B-T22)
[ ] Tag p22b-configuration; phase log; Appendix H row
```

**Do not start 22C until every box is ticked.**

**Rollback**

Turn every product's binary switch off and `binary_enabled = 0` (belt and braces), then revert the code.
The settings and the product flags may stay: they are inert and a later attempt can reuse them. No money
exists to reverse, and no tree exists to unpick.

**Hand-off to 22C**

- `binary_enabled`, `binary_self_pct`, `binary_pair_points`, `binary_pair_pct`,
  `binary_daily_pair_cap`, `binary_requires_active`, `binary_hold_days`, `binary_release_enabled`,
  `binary_reversal_enabled` exist, are validated and are editable by an operator.
- `products.binary_enabled` exists, is validated and is visible with the product's points.
- `BinaryNode`'s vocabulary constants exist (`KINDS`, `STATUSES`, `SIDES`, `MAX_DEPTH`).
- The tables are still empty. 22C may assume configuration and must work when nothing is configured.

**Risks**

| Risk | Mitigation |
|---|---|
| An operator switches the stream on in 22B and expects money | The product card and the settings hint say the payment engine arrives with the feature's money phase; the QA pack says so |
| The product card grows a per-product rate | P22B-T15 asserts no such input exists in the HTML; D-09 is restated in the partial's header comment |
| The readiness panel becomes a button that changes settings | Rule 12 and P22B-T19: read-only, links only |
| `pv` is edited as a money field and misunderstood as points | The card shows `P.V. → points per unit` and the label is *Product binary points*, never *price* |
| A product with `pv = 0` is flagged and silently earns nothing | The readiness count and the badge make the misconfiguration visible at both ends |

---

## 7. Phase 22C — Placement, the tree and registration

**Goal.** Every member has a binary node — an upline and a side — created at registration and validated
by the server; the tree is one rooted tree; administrators can backfill an existing database, see and
correct an empty placement, and the demo seed carries a tree. **Still no money.**

**Builds on.** 22A (the table), 22B (the product flag; the placement does not depend on it being on).

**Ships alone because.** The tree is a structural fact with no money attached: nothing reads a point
balance until 22D, and the only observable effects are the register form's two new fields, the node rows
and the console. If every later phase were cancelled, the application would still know who is under whom
and could show it — which is exactly what a placement feature must do before it pays anyone.

**Effort.** 2.5 ideal days. **Suite.** ≥ 1,576 cases.

**Reference.** §14.2 (the tree), §14.5 (placement rules), §15.2–15.3 (the tables), §15.4 (the node
columns), §17.2 (placement abuse).

**Deliverables**

**Database**

- No schema change. 22A shipped `binary_nodes`; this phase writes it.
- `system/database/seeds/005_demo_tree.sql` — the demo tree rows, idempotent, after the demo members:
  `admin` (root), `juan` under `admin` left, `maria` under `juan` left, `peter` under `maria` left,
  `sarah` under `maria` right, `david` under `juan` right. `path` and `depth` are the literals of that
  shape (`L`, `LL`, `LLL`, `LLR`, `LR`; depths 0–3). The seed writes `state` (`root`/`placed`) and
  `placed_at`, and joins the members by username so a re-run is a no-op
  (`INSERT … SELECT … WHERE NOT EXISTS`).
- `system/database/seeds/001_settings.sql` — no change (22A shipped the keys).

**Code**

- `system/app/Models/BinaryNode.php` — the full model: `find()`, `forUser()`, `root()`, `children()`,
  `slotChild()`, `ancestors()`, `descendants()`, `subtreeIds()`, `sideOf()`, `isAncestor()`,
  `pathFor()`, `freeSlots()`, `place()`, `move()`, `recomputePath()`, `floating()`, `stats()`,
  `teamRoster()`, `countSide()`, `pointsSummary()`, `blockedPairs()`. Every write runs inside the
  caller's transaction; the model never opens one. `place()` computes `depth`/`path` from the upline
  and rejects a taken slot before touching anything.
- `system/app/Services/BinaryPlacementService.php` (new) — `validate()`, `placeNew()`, `move()`,
  `resolveUpline()`, `legalUpline()`, `slotsFor()`, `backfill()`, `ensureRoot()`, `demoPlacement()`.
  It is the **only** writer of `binary_nodes.upline_id`, `side`, `depth`, `path`, `state`, `placed_*`
  and `moved_*`.
- `system/app/Services/BinaryException.php` (new) — `notPlaced()`, `slotTaken()`, `immutable()`,
  `cycle()`, `state()`, in the same shape as `UnilevelException`.
- `system/app/Services/RegistrationService.php` — the input gains `binary_upline` and `binary_position`;
  `register()` resolves the upline (default: the sponsor's node, or the company root when the sponsor is
  staff), calls `BinaryPlacementService::validate()`, and places the new member **inside the same
  transaction as the user insert and the activation**, so a failed placement creates no user. A
  placement error is a field error (`binary_upline` / `binary_position`), never a generic failure.
- `system/public/register.php` — reads the two fields, passes them through, keeps them in `old_set()`
  on failure, and honours a `return` parameter (`genealogy` → the tree, otherwise the existing
  redirects).
- `system/app/views/auth/register_form.php` — the **Binary placement** block: a *Binary upline* text
  input (username; defaulted to the sponsor and labelled *"Your upline in the binary tree"*), a
  position radio pair with the two free-slot labels and disabled/unavailable states, and a hint that
  quotes the upline's free slots and the company root as the fallback. Required markers. Rendered only
  when `BinaryPlacementService::isEnabled()` — and that is **on** for every database that has the table
  (the placement is structural, not the money switch), so the "off" state exists only for a database
  that has not migrated or for the tests (Rule 9).
- `system/app/Controllers/RegistrationController.php` — `checkSponsor()` gains a `slots` array in its
  JSON (up to 20 free slots inside the sponsor's subtree, breadth-first, each `{upline_id, upline,
  side, depth}`) and a `suggested` object (the first slot), so the register page's JS can populate the
  position choices when the sponsor changes. No new route.
- `system/app/Controllers/AdminMemberController.php` — `index()` accepts
  `?placement=floating|no-node|all`, lists nodes with no upline (floating) and members with no node,
  with counts; `show()` loads the binary panel's data (`node` + `placement` facts);
  `placement()` (the **one new route**, `POST /admin/members/{id}/placement`) validates the new
  upline/side, refuses a non-empty subtree, calls `BinaryPlacementService::move()`, audits
  `binary.placement.move` with before/after and redirects back with a flash.
- `system/app/routes.php` — `['POST', '/admin/members/{id}/placement', [AdminMemberController::class,
  'placement'], 'admin']`. `system/app/routes_inventory.php` — one `extensions` row, phase **22**,
  method `POST`, path `/admin/members/{id}/placement`, note *"Audited empty-subtree placement move —
  extension, no reference page."*
- Views: `admin/members/_placement.php` (the panel and the move form), `admin/members/index.php` (the
  placement filter and the count strip), `auth/register_form.php` (above), and
  `member/genealogy/_tree_list.php` is **not** this phase (22G).
- `system/bin/binary_place_tree.php` (new) — the backfill: `--check` (default: report), `--apply`,
  `--dry-run`, `--limit=`, `--json`. Algorithm: root = `Member::primaryAdmin()`; every member with no
  node is placed, in ascending `id`, under the nearest already-placed sponsor ancestor's **first free
  slot in breadth-first order** (left before right, shallow before deep); a member whose sponsor chain
  has no placed ancestor is placed under the root's first free slot. Idempotent: it only touches
  `state = 'floating'` rows. Output: placed count, root count, max depth, floating after, and a
  per-level histogram. It never moves an already-placed node.
- `system/app/Controllers/GenealogyController.php` — this phase only loads `binary_nodes` facts into
  the page context (a count and the member's own node); the tree view itself is 22G. The sponsor view
  is unchanged.

**Tests that must be updated in this phase (D-25)**

- `tests/Cases/MembershipTest.php` — replace the source scan with the ownership guard
  (`BinaryFoundationTest::binaryVocabularyIsConfined()`), keep the `users`-column scan unchanged, keep
  `P9-T15`'s forbidden routes and add the one allowed placement route. The three cases keep their names
  where the rule is unchanged; the changed ones are renamed so a reader can see the retirement.
- `tests/Cases/MembershipViewTest.php` — the register-form case becomes two: placement on (fields and
  required markers present) and placement off (neither, with a scratch setting). The `binary`-free
  assertions on the *sponsor* genealogy view and on the *package* earnings tab stay; the binary pages
  get their own view tests in 22F/22G.
- `tests/Cases/BinaryFoundationTest.php` (new, 22A/22C) — holds the ownership guard, the schema
  invariants (`users` clean, the three tables present, the enum values), the constants and the
  vocabulary manifest.

**Docs.** `docs/qa/phase-22-product-binary.md` gains section C (`QA22C-01` … `QA22C-10`).
`docs/admin-guide.md` gains "Placing a member in the binary tree" and "Correcting an empty placement".
`docs/deploy.md` gains the backfill command and the upgrade order (migrate → backfill → configure →
switch on). `system/README.md` gains the placement module.

**Not yet.** No point balance, no self bonus, no pair, no award, no volume row, no member-facing binary
page, no tree. A placed tree with the stream on still pays nothing.

**Rules**

1. **One node per member, one child per side.** `binary_nodes.user_id` is the primary key;
   `UNIQUE (upline_id, side)` guarantees two children at most. A root (company) row has both
   `upline_id` and `side` NULL; a placed row has both set; the check constraint forbids the mixed state.
2. **`depth` and `path` are derived, never typed.** `depth = upline.depth + 1`,
   `path = upline.path . (side = 'left' ? 'L' : 'R')`. The root has `depth = 0`, `path = ''`. A move
   recomputes the whole moved subtree in one statement per depth level.
3. **The company root is the primary admin** (`Member::primaryAdmin()`). `ensureRoot()` inserts it
   idempotently on the first placement, the backfill and the console load. If no usable admin exists,
   placement fails with a named error (never a half-built tree).
4. **Placement is required at registration** — including `standard` and `pending` modes, because the
   tree is structural. The only exception is a database whose tree feature has not been enabled
   (`BinaryPlacementService::isEnabled()` false), where the fields do not render and no node is written.
5. **The placement must be legal.** The upline exists, `is_active = 1`, membership not `suspended`, is
   not the new member, is not a staff account (except the root), has a node, the slot is free, and the
   upline is inside the **registrar's** subtree (a signed-in member) or the **sponsor's** subtree (a
   guest self-registration). An administrator may place anywhere that is not inside the moved subtree.
   `MAX_DEPTH` (100) bounds the walk.
6. **A member cannot be placed under their own descendant.** `isAncestor()` is checked in the same
   transaction as the write, and the check constraint plus the path prefix make a stored cycle
   detectable by the audit.
7. **Placement is immutable once the subtree has volume or awards.** `move()` refuses when
   `binary_volume` or `binary_awards` has any row for any member of the moved subtree (`subtreeIds()`),
   or when the node's `personal_points > 0`. The refusal names the reason ("the subtree has 1,000
   points and 2 awards").
8. **The backfill is deterministic and idempotent.** Same database, same tree, every run. It never
   touches a placed node, never deletes a node, and reports what it did. Its dry run is the default.
9. **The register form degrades with the feature off.** With `BinaryPlacementService::isEnabled()`
   false, no placement field renders, no node is written, and a posted `binary_upline` is ignored — the
   two states are tested (Rule 9's reason is a migration boundary, not the money switch).
10. **No money-adjacent value is written here.** `left_points`, `right_points`, `pairs_paid`,
    `personal_points` and every `binary_volume`/`binary_awards` column stay 0. The model may not expose
    a points-writing method until 22D.
11. **The demo seed is a fixture, never production data.** It places only the five demo accounts and is
    guarded by `WHERE NOT EXISTS`; a production install has the root only (created lazily).
12. **The move route is the only new route.** Everything else is a query parameter (D-18).
13. **Every placement write is audited**: `binary.placement.create` (registration, with upline/side),
    `binary.placement.backfill` (one row per run with counts), `binary.placement.move`
    (before/after + reason).

**Test plan**

Fixtures (§18.2): the primary admin is the root; `boss`, `a`, `b`, `c`, `buyerA`, `buyerB`, `sideR`,
`d` placed as in the diagram of §18.2. The placement tests use their own smaller trees on top of it.

**The model and the tree**

| ID | Test | Expected |
|---|---|---|
| P22C-T1 | `ensureRoot()` on an empty tree | One row: the primary admin, `upline_id`/`side` NULL, `state = 'root'`, `depth = 0`, `path = ''`; a second call writes nothing |
| P22C-T2 | `placeNew(buyer, boss, 'left')` | Row with `depth = boss.depth + 1`, `path = boss.path . 'L'`, `state = 'placed'`, `placed_at`/`placed_by` set |
| P22C-T3 | Place a second member on `(boss, 'left')` | `BinaryException` `slotTaken`; no row written |
| P22C-T4 | Place on `(boss, 'right')` | Accepted; `boss` now has two children |
| P22C-T5 | Place under a member with no node | `notPlaced` |
| P22C-T6 | Place under a suspended member | Refused with the membership reason |
| P22C-T7 | Place under an inactive (`is_active = 0`) member | Refused with the account reason |
| P22C-T8 | Place a staff account | Refused (staff are not in the tree; the company root excepted) |
| P22C-T9 | Place a member under themselves | `cycle` |
| P22C-T10 | Place `boss` under `b` (boss is b's ancestor) | `cycle`; nothing written |
| P22C-T11 | `descendants()` of `a` | Exactly the subtree ids, in path order; no duplicates |
| P22C-T12 | `ancestors()` of `buyerA` | `c, b, a, boss, root` in that order; bounded |
| P22C-T13 | `freeSlots(root)` | Two (left/right) on an empty root; one after the first child |
| P22C-T14 | `sideOf(root, node)` | `left`/`right` for a direct child; the correct first letter for any depth |
| P22C-T15 | `pathFor()` on a 50-deep chain | Correct path, `depth = 50`, no recursion in SQL |
| P22C-T16 | A 100-deep chain | The 101st placement is refused (`MAX_DEPTH`) |
| P22C-T17 | `stats()` | nodes, roots, floating, max depth, placed this month — one query each at most |
| P22C-T18 | Delete a node row that has children (direct SQL) | The self-FK cascades: the subtree's nodes are removed and no orphan is left. The audit's INV-11 is clean afterwards |

**Registration**

| ID | Test | Expected |
|---|---|---|
| P22C-T19 | Register with `binary_upline = boss`, `binary_position = left` | User + node created; the node's upline/side are the typed ones; the sponsor tree is unchanged |
| P22C-T20 | Register with no `binary_upline` | Defaults to the sponsor's node; position still required |
| P22C-T21 | Register with no `binary_position` | Field error on `binary_position`; no user created |
| P22C-T22 | Register with a taken slot | Field error; no user created (atomic) |
| P22C-T23 | Register with an upline outside the registrar's subtree | Refused (`legalUpline`) |
| P22C-T24 | Guest register with an upline outside the sponsor's subtree | Refused |
| P22C-T25 | Admin register with any legal upline | Accepted |
| P22C-T26 | Register in `standard`, `pending`, `code` and `ewallet` modes | All four create a node; the activation's own money is untouched |
| P22C-T27 | Registration fails on the password | No node, no user |
| P22C-T28 | `checkSponsor()` JSON | Carries up to 20 free slots and one `suggested` slot for the sponsor; unchanged for an unknown sponsor |
| P22C-T29 | Register form HTML | `binary_upline`, `binary_position`, both `left`/`right` values, the required markers, the free-slot hint; **no** pair/rate/points input |
| P22C-T30 | Register form with placement disabled (scratch setting) | No placement field; a posted `binary_upline` is ignored; the member is created without a node |
| P22C-T31 | Register form after a field error | The typed placement survives in `old_set()` |

**The move and the backfill**

| ID | Test | Expected |
|---|---|---|
| P22C-T32 | Move an empty node to another free slot | Row updated; the subtree's paths/depths recomputed; `binary.placement.move` audit with before/after; `move_count` incremented |
| P22C-T33 | Move a node whose subtree has one volume row | Refused (`immutable`); nothing changes |
| P22C-T34 | Move a node whose subtree has a pair award | Refused |
| P22C-T35 | Move a node into its own subtree | Refused (`cycle`) |
| P22C-T36 | Move a node onto a taken slot | `slotTaken` |
| P22C-T37 | Move with the route, as a member (not admin) | 403; nothing changes |
| P22C-T38 | Move with the route and no CSRF | Refused (the router's CSRF guard); nothing changes |
| P22C-T39 | `bin/binary_place_tree.php --check` on an unplaced database | Reports the plan, writes nothing, exit 0 |
| P22C-T40 | `--apply` on the demo database | One root, zero floating, every member placed, deterministic order; a second `--apply` changes nothing |
| P22C-T41 | `--apply` twice → tree identical | Paths, depths and ids unchanged |
| P22C-T42 | Backfill with a member whose sponsor is staff | Placed under the nearest placed ancestor (the root) |
| P22C-T43 | Backfill with a corrupted sponsor cycle | Places it under the root and reports it; never loops |
| P22C-T44 | Backfill `--json` | Machine-readable counts; exit 0; no money/volume rows created |
| P22C-T45 | Demo seed re-run | Six demo nodes, ids stable, no duplicate slot |
| P22C-T46 | Demo seed on a database with a production tree | The five demo accounts are placed only if they exist and only if their slot is free; otherwise the seed reports and continues (no partial tree) |

**The guards and the suite**

| ID | Test | Expected |
|---|---|---|
| P22C-T47 | `users` columns | No `binary_*`, `pairs_*`, `left_count`, `right_count` column |
| P22C-T48 | Vocabulary manifest | The binary words appear only in the named files; every other `app/**/*.php` is clean |
| P22C-T49 | `routes.php` | Exactly one route contains `placement`; `POST /register/check-upline`, `POST /register/auto-select-upline`, `GET /api/binary-tree` still absent |
| P22C-T50 | `ParityTest` | The extension row is present, phase 22, and the route is registered; no orphan route |
| P22C-T51 | The sponsor tree is untouched by placement | Registering/moving does not change any `users.sponsor_id`; the unilevel chain of every member is identical before and after |
| P22C-T52 | No money written | `binary_volume`, `binary_awards` and `ewallet_ledger` gain no binary row in this phase's whole manual pass |
| P22C-T53 | The full suite | Green at or above the floor; the retired guard names are replaced, not deleted silently |

**Manual gate — `QA22C-01` … `QA22C-10`**

1. `QA22C-01` — open `/register`: the *Binary placement* block shows the sponsor as the default upline,
   a position radio pair, and the free-slot hint. Register a member with the left slot; the member
   page opens and the flash names the placement.
2. `QA22C-02` — register again with the same upline and side: the form returns with the slot-taken error
   and no member is created.
3. `QA22C-03` — as `juan`, register a member under a member outside juan's subtree (type the username):
   refused. Under juan's own left slot: accepted.
4. `QA22C-04` — `/admin/members?placement=floating`: on a migrated demo database before the backfill,
   every member is listed; after `--apply`, only the count of the root remains and the list is empty.
5. `QA22C-05` — run `php bin/binary_place_tree.php --check`: it prints the plan and writes nothing;
   `--apply` places the demo members; run it again: *0 placed*.
6. `QA22C-06` — open a member's admin page: the *Binary placement* panel shows the upline, side, depth,
   path, placed at/by and the move form.
7. `QA22C-07` — move an empty node to the other side of the same upline: the panel updates, the audit
   desk shows before/after. Add a volume row by hand (`INSERT INTO binary_volume …`) and try to move
   again: refused with the reason.
8. `QA22C-08` — open `/genealogy` (the sponsor view): it is byte-identical to `p22b-configuration`.
9. `QA22C-09` — check `binary_volume` and `binary_awards` are still empty after the whole pass; pay an
   order and confirm they stay empty.
10. `QA22C-10` — reset the development copy: the three binary tables are empty; run the demo seed and
    `--apply`; the demo tree is back (six nodes) and verification passes.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22C-T1 … P22C-T53 pass
[ ] The retire-and-replace of the P9 guards is in the same commit as the fields that need it
[ ] Every member in the demo database has exactly one node; one root; zero floating
[ ] `bin/binary_place_tree.php --check` exits 0 on a freshly migrated copy
[ ] The sponsor view of /genealogy and every sponsor-tree test are unchanged
[ ] No binary money row exists anywhere (binary_volume, binary_awards, ewallet_ledger)
[ ] Tag p22c-placement; phase log; Appendix H row
```

**Do not start 22D until every box is ticked.**

**Rollback**

Turn the placement fields off (the service's `isEnabled()` flag), then revert the code. The node rows may
stay: they are inert, they carry no money, and a later attempt can reuse them. Dropping the rows is safe
too (no FK reference exists outside the three binary tables), but do not drop the table — that is 22A's
rollback.

**Hand-off to 22D**

- `BinaryPlacementService` and `BinaryNode` are the only way the engine may read a node, a side or a
  subtree.
- Every member created from this tag on has a node; the backfill makes the same true for older members.
- `BinaryNode::sideOf()` / `path` give the engine the side at every ancestor without walking SQL per
  level.
- `move()` refuses a non-empty subtree, so 22D may assume placement is stable once money exists.
- The stream still pays nothing: `binary_self_pct`, `binary_pair_*` and the product flags are unread by
  the payment path.

**Risks**

| Risk | Mitigation |
|---|---|
| A registration creates a user but no node (partial write) | One transaction; P22C-T22/T27 |
| Two concurrent registrations race for the same slot | The unique key is the arbiter; the loser gets a field error, never a second child |
| The backfill builds an unbalanced or non-deterministic tree | Breadth-first first-free-slot, ascending id, P22C-T40/T41 pin the exact shape |
| A move silently re-parents a productive leg | The volume/award guard (P22C-T33/T34) and the audit |
| The retired guard is deleted without a replacement | The exit gate requires the replacement in the same commit; BinaryFoundationTest owns it |
| A staff account is placed and then earns | Staff are refused at placement; the company root is the only staff node and is never paid (D-02) |
| `path` outgrows `VARCHAR(255)` | `MAX_DEPTH = 100` bounds it at 100 characters; the audit checks the longest path |
| The demo seed collides with a real tree | The seed only fills empty slots for demo usernames and reports what it skipped |

---

## 8. Phase 22D — Money engine

**Goal.** A paid shop order places points on the buyer's node and on every binary upline, pays the buyer a
Self Bonus, pays a pair-batch award at every eligible node that matches, escrows every award, reverses
correctly on cancellation, and can prove the whole thing from two ledgers. This is the only phase that
moves money.

**Builds on.** 22A (schema), 22B (settings), 22C (the tree).

**Ships alone because.** Money is correct and complete at `p22d-money`: every award is visible in the
existing wallet statements with the ref type this phase credits (after release), `bin/wallet_reconcile.php`
proves the money and `bin/binary_audit.php` (22H) proves the points. No page is *required* for the phase
to be correct — the admin and member pages of 22E/22F only **describe** what this phase already does.

**Effort.** 3.5 ideal days — the largest phase by design. **Suite.** ≥ 1,650 cases.

**Reference.** §14.5–14.11, §15.4 (the two ledgers), §16 in full (the money path), §17.1–17.3 (the
threat model, placement abuse and self-dealing).

**Deliverables**

**Database.** None. (The two ledgers and the node balances use the columns shipped in 22A.)

**Code**

- `system/app/Models/BinaryVolume.php` (new) — the points ledger: `create()`, `forNode()`, `forOrder()`,
  `forAward()`, `sumBySide()`, `sumByKind()`, `pageForNode()`, `csvRows()`. It refuses a row whose
  `side`/`kind`/sign combination violates §15.4's table, and it is the **only** writer of
  `binary_volume`.
- `system/app/Models/BinaryAward.php` (new) — the money ledger: `create()`, `find()`, `forOrder()`,
  `forUser()`, `forEvent()`, `linkLedger()`, `markCredited()`, `cancel()`, `void()`,
  `recomputeOrderTotal()`, `normaliseFilters()`, `filterDefaults()`, `pageForUser()`, `adminList()`,
  `totalsForUser()`, `stats()`, `levelBreakdown()` → `sideBreakdown()`, `csvHeader()`, `csvRows()`,
  `pendingSummaryForUser()`.
- `system/app/Services/BinaryService.php` (new) — the engine:
  `onOrderPaid()`, `quoteCart()`, `quotePosition()`, `reverseForOrder()`, `releaseForOrder()`,
  `releaseDue()`, `dueOrderIds()`, `dueCandidates()`, `totalsFor()`, `reconcile()`, `audit()`,
  `auditFix()`, `enabled()`, `holdDays()`, `pairValue()`, `pairsToday()`, `blockedMap()`.
- `system/app/Services/BinaryException.php` (the 22C class) — **adds** `notPaid()` and
  `positionRequired()`, the two money-path reasons, in the same shape as `UnilevelException`.
- `system/app/Services/OrderService.php` — `lockProducts()` selects `binary_enabled`; `insertOrder()`
  writes `binary_position` (validated in `placeFromCart()`: required when the reserved lines carry
  points); `insertItems()` writes `unit_points` and `points_total` through
  `BinaryService::linePoints()`; the e-wallet branch calls `BinaryService::onOrderPaid()` **after**
  `UnilevelService::onOrderPaid()` and after `issueForPaidOrder()`.
- `system/app/Services/PaymentService.php` — `verify()` calls `BinaryService::onOrderPaid()` after the
  unilevel hook; `cancelByAdmin()` calls `BinaryService::reverseForOrder()` after the unilevel reversal
  and before the status update.
- `system/app/Controllers/CheckoutController.php` — `checkoutContext()` gains `binary` (the position
  card's data: enabled, placed, points per side, self, expected own-node pairs, the current node
  balances); `place()` passes `binary_position` to `OrderService` and surfaces its field error.
- `system/app/Services/DatabaseResetService.php` — the integrity map gains
  `'binary' => ['label' => 'Product binary points', 'service' => 'BinaryService']` (deferred from 22A on
  purpose: the class now exists).
- `system/bin/wallet_reconcile.php` — a **Product binary** section, included in the exit code.

**Docs.** `docs/qa/phase-22-product-binary.md` gains section D (`QA22D-01` … `QA22D-12`).
`docs/runbook.md` gains the points-audit and binary-reconcile sections. `system/README.md` gains the
engine, the two ledgers and the two ref types.

**Not yet.** No binary ledger tab, no order card, no member page, no tree, no release job change (the job
still releases only unilevel until 22H), no `APP_PHASE` change.

**Rules**

1. **One hook, two paths.** `onOrderPaid()` is called from the e-wallet branch of
   `OrderService::placeFromCart()` and from `PaymentService::verify()`. `paid` reached any other way (a
   data repair, a fixture) writes nothing.
2. **Runs in the caller's transaction.** The engine never opens its own. Awards, volume rows, node
   balances, the snapshot and the order stamp commit or roll back together (§16.5).
3. **Order first, then nodes, ascending.** The order row is locked first; then the buyer's node and every
   ancestor are locked in ascending `user_id` order before any balance changes (§16.6).
4. **The buyer is a recipient** (this is the stream's point). The company root is not: the walk stops at
   the root's children, and a root node never gets an award (§16.7).
5. **Points are integers, money is centavos.** `unit_points = ROUND(pv)`; `points_total = unit_points ×
   qty`; every point movement is a whole number; every money amount is `round(…, 2)` in integer
   centavos (§14.11).
6. **`points_total` is snapshotted at placement; eligibility is read at payment.** A price or P.V. change
   between placement and verification cannot rewrite the points. A product's binary switch or the global
   switch turned off before payment means the line earns nothing.
7. **Stamp even when nothing pays.** Global switch off, no node, no eligible line, no points → the order
   is stamped with `binary_points = 0` and `binary_total = 0`, so "decided, nothing due" is auditable
   and a later re-verification cannot pay it.
8. **Self is one award per order.** `round(orderPoints × self_pct / 100, 2)`, recipient = buyer, no
   Active gate, no node gate beyond "the buyer is placed". A self award of `0.00` is **not written**.
9. **Volume routing is exact.** The buyer's node gets `+points` on the chosen side (`own`); every
   ancestor gets `+points` on the side of the child on the buyer's path (`team`). The root receives
   volume (`team`) and is never paired.
10. **Pairing is greedy, per node, in a fixed order.** Buyer's node first, then uplines outward. At each
    node: `due = min(floor(max(0,L)/PP), floor(max(0,R)/PP))`; `n = min(due, capRemaining)`; a pair
    consumes `PP` from each side (two `pair` rows, both negative, tied to the award); the award is one
    row per `(order, node, kind='pair')`; `n = 0` writes nothing and consumes nothing.
11. **An ineligible node pays nothing and consumes nothing.** Company root, disabled account,
    non-active membership, not Active for the month → skip the node, keep the volume, continue to the
    next upline (D-13). No `skipped` row exists in this ledger.
12. **The daily cap counts the Asia/Manila day.** `pairsToday($userId, $date)` sums `pair_count` over
    this stream's `pair` awards in `pending` or `credited` state whose `paid_at` falls in that day; the
    engine passes an explicit half-open range, never `CURDATE()`.
13. **A zero amount is never written.** Self `0.00`, pair `0.00` (pair rate 0) → no row, but the pair
    still consumes its points (the points were matched; a 0% pair is a deliberate configuration).
14. **Idempotent at four layers** (§16.4): the order stamp, the per-order pre-check, the unique keys and
    the release job's status transition. A second call returns `replayed` and moves nothing.
15. **A failure propagates.** If a volume write, a balance update or a release credit throws, the caller
    rolls back; the engine never swallows an exception and never writes an adjusting row to hide it.
16. **Reversal is best effort and never pairs.** Pending awards are cancelled; credited awards are voided
    and clawed back up to the withdrawable balance, with any shortfall recorded. Volume is reversed row
    by row; pair consumption is returned; **no pairing runs during a reversal** (§16.9).
17. **No backfill** (D-15). The engine only runs on the transition; `binary_ledger_since` is the
    reconciliation baseline, not a hint to look backwards.

**Test plan**

Fixture tree (§18.2). Product `P1` Coffee, price ₱300, P.V. 250, binary on → **250 points/unit**; `P2`
price ₱500, P.V. 0, binary on → **0**; `P3` Spirulina, price ₱400, P.V. 300, binary **off** → 0; `P4`
price ₱1,000, P.V. 1,000, binary on → **1,000 points/unit**. Preset: self 5%, pair 1,000 points, pair
10%, cap 20, hold 7, requires Active **off** for the award cases (the Active cases turn it on).

**The first purchase and the roll-up**

| ID | Test | Expected |
|---|---|---|
| P22D-T1 | `buyerA` pays an e-wallet order, 4 × P1, position **left** | Self ₱50.00 to `buyerA`; volume rows: `buyerA` L 1000 (own), `c` L 1000, `b` L 1000, `a` L 1000, `boss` L 1000, root L 1000 (team); 0 pairs; order `binary_points = 1000`, `binary_self_total = 50.00`, `binary_pair_total = 0.00`, `binary_total = 50.00` |
| P22D-T2 | Then `buyerB` pays 4 × P1, position **right** | Self ₱50.00 to `buyerB`; `c` L 1000 R 1000 → **1 pair, ₱100.00 to `c`**; `b` L 2000, `a` L 2000, `boss` L 2000, root L 2000; `c`'s balance L 0 R 0 after consumption; 2 awards (self + pair) |
| P22D-T3 | A **manual** (GCash) order by `buyerA`, verified by an admin | Identical volume and awards to T1, created once at verification; nothing at placement |
| P22D-T4 | 1 × P4 (1,000 points), position left, ten-deep chain (the buyer plus ten uplines, one of them the root) with 1,000 right points pre-filled at every node | 10 pairs: the buyer's node and nine ancestors (root excluded) → ₱1,000.00, one `pair` award per node, each `pair_count = 1` |
| P22D-T5 | 2 × P4 (2,000 points) with 2,000 right points at one ancestor | 2 pairs in **one** award row (`pair_count = 2`), ₱200.00 |
| P22D-T6 | Mixed cart: 4 × P1 + 1 × P3 (binary off) + 1 × P2 (P.V. 0) | Order points 1,000 (P3/P2 contribute 0); self ₱50.00; the two zero lines write no volume rows and no self portion |
| P22D-T7 | 3 × P1 (750 points) | Self ₱37.50; no pair (750 < 1,000); `buyerA` L 750 |
| P22D-T8 | 4 × P1 with `pv = 250.4` | `unit_points = 250`; 1,000 points; self ₱50.00 (the per-unit rounding is pinned) |
| P22D-T9 | 4 × P1 with `pv = 249.6` | `unit_points = 250`; 1,000 points |
| P22D-T10 | Position **right**, and `buyerA` already has 1,000 left | The purchase lands right; `buyerA` pairs 1 immediately for ₱100.00 (own-node pair) **plus** the ₱50.00 self |
| P22D-T11 | The buyer's node is the root's direct child and the root has volume | The root receives a team row and **never** a pair award; the pairing list has no root entry |
| P22D-T12 | `buyerA` has no node (legacy, pre-backfill) | No self, no volume, the order is stamped `binary_points = 0`, `binary_total = 0`; no exception |
| P22D-T13 | The company account buys a product | No points, no self, no volume rows — its own purchases are not a purchase event for this stream |
| P22D-T14 | A staff account that is not the root buys (admin shopping) | Same as T13: staff are not in the tree, so no node and no award |

**Carry, caps and rounding**

| ID | Test | Expected |
|---|---|---|
| P22D-T15 | `buyerA` left 2,500, `buyerB` right 1,200 at `c` | 1 pair (₱100); `c` L 1,500 R 200 carry; a later right 800 pays the next pair |
| P22D-T16 | Left 999, right 1,000 | 0 pairs; 999 L / 1,000 R carry (never a partial pair) |
| P22D-T17 | `binary_daily_pair_cap = 2`, a purchase due 5 pairs at the buyer's node | 2 pairs paid (₱200), 3 pairs' worth of points stay; `pairsToday = 2`; a second order the same day pays 0 and carries |
| P22D-T18 | Cap `0` | Unlimited: 5 pairs paid |
| P22D-T19 | Cap reached at the buyer's node but not at the parent | The buyer's node pays 0, the parent still pays (each node has its own cap check) |
| P22D-T20 | The next day (Asia/Manila) after a capped day | The cap resets; the carried points pair |
| P22D-T21 | `binary_pair_pct = 0` | A pair is still consumed, `0.00` award is **not** written; the order's `binary_pair_total = 0.00` |
| P22D-T22 | `binary_self_pct = 0` | No self award; pairs still pay |
| P22D-T23 | `binary_pair_points = 333`, 1,000 points each side | 3 pairs (999 consumed), 1 point each side carried |
| P22D-T24 | Rounding: pair rate 7.5%, pair 1,000 | ₱75.00 per pair; `pair_count = 3` → ₱225.00 (per-pair rounding, then multiply) |
| P22D-T25 | Rounding: self 3.333%, 1,000 points | ₱33.33 (one rounding, half-up) |
| P22D-T26 | A 3-decimal pair rate (`10.125`), 1,000 points | ₱101.25 per pair |

**Eligibility and switches**

| ID | Test | Expected |
|---|---|---|
| P22D-T27 | `c` is inactive (`is_active = 0`) when `buyerB` pays | No pair at `c`; **no points consumed** (`c` L 1,000 R 1,000); no award; `b`…`root` still roll up; `c`'s blocked pair is reported by `blockedMap()` |
| P22D-T28 | `c` is `suspended` | Same as T27 with the membership reason |
| P22D-T29 | `c` is not Active for the month, `binary_requires_active = 1` | Same as T27 with the Active reason; `c` L 1,000 R 1,000 |
| P22D-T30 | …then `c` becomes eligible and `buyerB` pays 1 × P1 right | `c` L 1,000 R 1,250 → 1 pair paid to `c` (the carried points finally pair) |
| P22D-T31 | `binary_requires_active = 0`, `c` not Active | The pair pays immediately |
| P22D-T32 | Global `binary_enabled = 0` | No self, no volume, no pair; the order stamped with zeros |
| P22D-T33 | Product `binary_enabled = 0` | The line contributes no points; other lines still do |
| P22D-T34 | Product switch on, global on, product P.V. 0 | 0 points for that line; no award |
| P22D-T35 | Both masters on (control for T32/T33) | Awards as T1 |
| P22D-T36 | Global switch turned off **after** placement, before verification | The manual order pays nothing and is stamped 0 |
| P22D-T37 | **The buyer** is inactive | The self bonus is still paid (no Active gate); the own-node pair is **not** paid (the buyer is not an eligible recipient); the uplines roll up and may pair |
| P22D-T38 | The buyer is the company account | No volume, no self (T13) |

**Idempotency, locking and integrity**

| ID | Test | Expected |
|---|---|---|
| P22D-T39 | `onOrderPaid()` called twice | Second returns `replayed`; award, volume and balance rows identical |
| P22D-T40 | Duplicate `(order_id, user_id, kind)` insert | `PDOException` 23000 |
| P22D-T41 | `pending` / `payment_review` order | No awards, no volume, **not** stamped |
| P22D-T42 | Concurrent e-wallet and manual verification of the same order | One set of rows; no deadlock; the order row serialises |
| P22D-T43 | Two orders sharing an upline chain, processed concurrently | Node locks taken in ascending `user_id`; both commit; balances add correctly |
| P22D-T44 | `quoteCart()` before payment | Points, self and the per-side own-node pairs computed; **no writes** |
| P22D-T45 | `quotePosition()` for left and right | Two different own-node pair counts against the same balances; the self is identical |
| P22D-T46 | `totalsFor()` | Credited, pending, self, pair, points placed split by side |
| P22D-T47 | Reconcile after awards and releases | `ok = true`, every cached node balance equals the ledger sum |
| P22D-T48 | Reconcile with one `binary_volume` row deleted | Mismatch reported for that node, `ok = false` |
| P22D-T49 | `audit()` with a corrupted `binary_nodes.left_points` | Drift reported with the delta; `auditFix()` recomputes it from the ledger and re-runs clean |
| P22D-T50 | `audit()` never changes an award or a wallet row | The audit's summary is read-only; `auditFix()` touches only the caches |
| P22D-T51 | Delete a product that has awards | Refused (`referencesMap()` counts `binary_awards`); the message names the award |
| P22D-T52 | A legacy line (`points_total = 0`, pre-22A) | Contributes no points; no exception |

**Escrow and release**

| ID | Test | Expected |
|---|---|---|
| P22D-T53 | After payment, before delivery | Awards `pending`; **no** wallet movement; `pendingSummaryForUser()` shows them |
| P22D-T54 | `releaseForOrder()` before delivery | Refused (`not delivered`), nothing moves |
| P22D-T55 | Delivered, hold 7 days not elapsed | `not due`, nothing moves |
| P22D-T56 | Delivered + 7 days | `credited`; withdrawable credit of ₱150.00 at `c`; `binary` ledger row; `credited_at` set |
| P22D-T57 | `binary_release_enabled = 0` | The release is a no-op; awards stay `pending` |
| P22D-T58 | `binary_hold_days = 0` | The award is due at delivery |
| P22D-T59 | A pending award whose order is later cancelled | `cancelled`; no wallet movement; no reversal row |
| P22D-T60 | Release twice | Second returns `nothing pending`; one ledger row |

**Reversal**

| ID | Test | Expected |
|---|---|---|
| P22D-T61 | Admin cancels a paid, **undelivered** order | Self and pair awards `cancelled`; volume reversed at every node; `c`'s consumed pair points returned (L 1,000 R 1,000 again); cached balances back to pre-order values; `binary_total` recomputed to 0; no wallet movement |
| P22D-T62 | Admin cancels a **delivered, released** order | Awards `voided`; wallet debited by the full amount with `ref_type = 'binary_reversal'`; `reversed_amount = amount`; volume reversed; the points return |
| P22D-T63 | Cancel with a partly spent withdrawable balance | Partial clawback; shortfall = amount − recovered; the award is still `voided`; no negative balance |
| P22D-T64 | `binary_reversal_enabled = 0`, order released | Awards stay `credited`; no debit; the volume is still reversed (the points must not survive a cancelled purchase) |
| P22D-T65 | Member cancels an unpaid order | Nothing to cancel; no volume, no award, no reversal |
| P22D-T66 | A reversed purchase that had already paired at an ancestor | The ancestor's pair award is reversed best effort; its consumed points are returned; the ancestor's balance is exact |
| P22D-T67 | Reverse twice | Second call is a no-op; no duplicate reversal rows (the unique key) |
| P22D-T68 | Reversal never re-pairs | The returned points stay unmatched until the next paid order |

**Cross-stream isolation**

| ID | Test | Expected |
|---|---|---|
| P22D-T69 | A paid order with both streams on | The unilevel awards are identical to the pre-22 run; the binary rows are additive; neither stream's totals include the other |
| P22D-T70 | `wallet_reconcile.php` | Exit 0; the Product binary section reports the released total and no mismatch; a deleted binary ledger row makes it exit non-zero |
| P22D-T71 | Query budget: one order, three eligible lines, ten-deep chain | ≤ 30 statements; one node lock query and one volume batch, never a query per level |
| P22D-T72 | Query budget: `quoteCart()` with ten lines | ≤ 6 statements and zero writes |

**Manual gate — `QA22D-01` … `QA22D-12`**

No new page appears in this phase. Verify through the wallet statements, the order data and SQL.

1. `QA22D-01` — configure `P1` (P.V. ₱250, binary on), set the global switch on, place `buyerA` and
   `buyerB` in the fixture tree, and pay an e-wallet order of 4 × `P1` as `buyerA`, position left.
2. `QA22D-02` — `SELECT * FROM binary_volume WHERE order_id = …` → six rows (buyer + five ancestors),
   all positive, with the sides of the fixture; `SELECT * FROM binary_awards` → one `self` row, ₱50.00,
   `pending`.
3. `QA22D-03` — pay 4 × `P1` as `buyerB`, position right: `binary_awards` gains a `self` ₱50.00 and a
   `pair` ₱100.00 to `c`; `c`'s node reads L 0 R 0.
4. `QA22D-04` — deliver both orders and run `php bin/unilevel_release.php` **without** the binary flag:
   the unilevel awards release; the binary awards stay pending (22H adds the stream).
5. `QA22D-05` — call `BinaryService::releaseForOrder()` from a scratch script: the ₱150.00 lands in
   `c`'s withdrawable wallet with `ref_type = 'binary'`. `php bin/wallet_reconcile.php` exits 0.
6. `QA22D-06` — raise `binary_daily_pair_cap` to 1 and pay an order due 2 pairs: 1 pair paid, one
   carried; the node page (SQL) shows the leftover points.
7. `QA22D-07` — set `c` inactive and pay another `buyerB` order: `c` gets no award and **no points are
   consumed**; `blockedMap()` names the reason. Restore `c`.
8. `QA22D-08` — cancel one paid order as an admin: every volume row it wrote is reversed, its pending
   awards are `cancelled`, the node balances return, and no wallet balance moved.
9. `QA22D-09` — release a second order, spend part of `c`'s wallet by hand (a transfer), then cancel it:
   the clawback is partial, the shortfall is recorded and `reversed_amount` shows it; no negative balance.
10. `QA22D-10` — turn the global switch off and pay an order: no rows, and `binary_processed_at` is
    stamped with `binary_points = 0`. Turn it back on and re-verify the same order: nothing new is paid.
11. `QA22D-11` — run `php bin/wallet_reconcile.php`: exit 0, the Product binary section reports the
    released total; delete one `binary_awards` ledger row by hand and re-run: non-zero exit.
12. `QA22D-12` — run a scratch `BinaryService::audit()`: `ok = true`; corrupt
    `binary_nodes.left_points` by hand, run it again: the drift and delta are reported; `auditFix()`
    repairs it and the audit is clean.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22D-T1 … P22D-T72 pass
[ ] Points, self, pairs and pairing are pinned to the unit, including the 750/999/1,000 boundaries
[ ] No award row anywhere has the company root as the recipient (asserted in the suite)
[ ] `wallet_reconcile.php` exits 0 with a Product binary section, and non-zero when a ledger row is removed
[ ] `BinaryService::reconcile()` proves every cached node balance against `binary_volume`
[ ] Reversal leaves no negative withdrawable balance and never blocks a cancellation
[ ] The unilevel stream's results on the same orders are byte-identical to the pre-22 run
[ ] Tag p22d-money; phase log; Appendix H row
```

**Do not start 22E, 22F or 22H until every box is ticked.**

**Rollback**

1. Set `binary_enabled = 0` — new orders are stamped and place nothing, immediately.
2. Only then revert the code. Points and awards already written stay in the tables: the points are a
   member's earned volume and the awards are real money once released. A code rollback does not delete
   them, and `bin/binary_audit.php` (22H) reads the tables, not the code path.
3. A decision to *unpay* is a business decision (a reversal run), not a rollback (§16.9).

**Hand-off to 22E/22F/22H**

- Awards exist, with a stable shape (`BinaryAward::forOrder()`, `normaliseFilters()`, totals,
  `sideBreakdown()`, CSV rows) that 22E and 22F only render.
- Volume exists, with durable node summaries (`BinaryNode::pointsSummary()`) that 22F's cards use.
- `quoteCart()`/`quotePosition()` exist for 22F's checkout card and 22G's node cards.
- `releaseDue()` exists for 22H to wrap; `releaseForOrder()` is the single-order primitive.
- `binary_points`, `binary_self_total`, `binary_pair_total`, `binary_total` and `binary_processed_at`
  are maintained on the order, so 22E's order card can distinguish "not paid", "paid before the feature"
  and "paid and processed".
- The reconcile and audit entry points exist for 22H's CLI and the doctor.

**Risks**

| Risk | Mitigation |
|---|---|
| A double credit from a raced verification | Four idempotency layers (§16.4); the order row is locked first; P22D-T39/T42 |
| A double spend of the same points (two pairs from one balance) | Node rows are locked; every consumption is a ledger row; the balance audit catches drift |
| Float drift in points | Points are integers end to end; no float ever holds a point (§14.11) |
| A deadlock between two orders sharing uplines | Ascending node locking (§16.6); the retry note in §16.6 |
| A pair paid at an inactive node by accident | The eligibility gate is resolved once for the chain; P22D-T27–T31 |
| The cap is bypassed by splitting orders | The cap is per recipient per day across orders, not per order (P22D-T17) |
| The company root is paid | The walk stops at its children and the eligibility map marks it; P22D-T11/T38 |
| An exception is swallowed to "keep checkout working" | Rule 15: the exception propagates and the caller rolls back |
| Reversal double-returns points | The `(award_id, side)` and `(node, item, side, kind)` unique keys; P22D-T67 |
| The unilevel stream changes by accident | Cross-stream test (T69) pins the unilevel results on the same orders |

---

## 9. Phase 22E — Admin visibility

**Goal.** Finance can see, filter and export every binary point and award, open the order that produced
it, read the member's node, and read five report cards. **Read-only: this phase adds no money path.**

**Builds on.** 22D (the rows it renders) and 22C (the node facts).

**Ships alone because.** It is pure presentation over rows 22D already writes and `BinaryAward`/
`BinaryNode` already shape. If 22F, 22G and 22H never shipped, the operator still has a complete audit
tool — which is exactly what a controlled money feature needs first.

**Effort.** 1.5 ideal days. **Suite.** ≥ 1,668 cases.

**Reference.** §16.2 (the API these pages call), §15.6 (the invariants the page surfaces).

**Deliverables**

**Code**

- `system/app/Controllers/AdminCommissionController.php` — `ledger=binary` selects the new tab; filters
  pass through `BinaryAward::normaliseFilters()`; `export` writes the same columns for `ledger=binary` and
  audits `binary.export`.
- Views: `admin/commissions/_binary_header.php`, `_binary_stats.php`, `_binary_rows.php`,
  `_binary_filters.php` (new); `admin/commissions/index.php` gains the fourth nav pill; the `package`,
  `repeat` and `royalty` tabs keep today's markup byte-for-byte.
- `system/app/views/admin/orders/_binary.php` (new) and `admin/orders/show.php` (include the card below
  the repeat-purchase card).
- `system/app/views/admin/members/_binary.php` (new: node facts, balances, pairs today, blocked pairs,
  recent volume and awards, the *Open in tree* link) and `admin/members/show.php` (include it).
- `system/app/Models/Report.php` — `binarySummary()`, `binaryByDay()`, `binaryByKind()`,
  `binaryByProduct()`, `binaryTopNodes()`, `binaryReadiness()`.
- `system/app/Services/ReportService.php` — the five cards, `type=binary` in the range export, and the
  dashboard **Product binary (30 d)** KPI.
- `system/app/views/admin/reports/*` — `_binary.php`, `_binary_pairs.php`, `_binary_products.php`,
  `_binary_readiness.php`; `admin/reports/index.php` includes them.
- `system/app/views/admin/dashboard/_kpi.php` — the binary tile.

**Docs.** `docs/qa/phase-22-product-binary.md` gains section E (`QA22E-01` … `QA22E-09`).
`docs/admin-guide.md` gains "The Product binary ledger", "Reading a node" and "The readiness cards".
`docs/deploy.md` notes the new audit actions.

**Not yet.** No member-facing surface. The buyer cannot see the bonus and the uplines see only their
wallet credits (once released). No tree.

**Rules**

1. **Read-only.** No controller, model or view in this phase writes an award, a volume row, a node
   balance or a wallet row.
2. **The other three tabs are untouched.** Their markup, filters, counts and exports are byte-identical;
   `P22E-T1` is a regression test, not a formality.
3. **No new route.** `?ledger=binary` and `?ledger=binary&…` only (D-18). `routes.php` and
   `routes_inventory.php` are untouched; `ParityTest` stays green.
4. **Points and money are shown side by side and never added together.** The header separates
   *Points placed* (a volume count) from *Credited/Pending* (peso amounts). A single "total" column
   exists only within a kind.
5. **A blocked pair is not money.** The member panel shows pairs due but not paid, with the reason, as a
   count — it never enters a total and never looks like a skipped award (there are none in this ledger).
6. **Unrecovered is the shortfall.** `Σ (amount − reversed_amount)` over voided rows; never hidden.
7. **Filters fall back silently.** An unknown `kind`, `status`, `side`, `product_id`, `user_id` or date
   means "no filter", exactly like `UnilevelAward::normaliseFilters()`.
8. **Every row links to its order and its wallet movement.** A finance reviewer can walk from a number to
   the order and to the ledger row in one click each.
9. **Empty state teaches.** "No product binary has been paid. Turn it on in Settings and flag a product
   with a P.V." — and it names the two switches, because they are the two reasons it can be empty.
10. **The readiness cards are counts with links, never actions.** Same rule as 22B: read-only.
11. **The member panel never shows another member's upline chain.** It shows this member's node (upline,
    side) because that is the admin's job; it does not render the tree (22G does).

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P22E-T1 | `/admin/commissions` (package, repeat, royalty tabs) | Byte-identical markup and counts to `p22d-money`; regression |
| P22E-T2 | `/admin/commissions?ledger=binary` | Stats, filters, rows, empty state; columns: Date, Kind, Member, Buyer, Product, Side, Points, Rate, Amount, Status, Order |
| P22E-T3 | Stats tiles | Credited, pending, pairs, points placed L/R, self vs pair, blocked pairs — all match the fixture exactly |
| P22E-T4 | Filters: kind self/pair, status, side, product, member, from/to, q | Each filters correctly; two combined filters intersect; a bad value falls back to "no filter" |
| P22E-T5 | A pair row | Shows `pair_count`, the per-pair rate, the matched points and the total; a `pair_count = 2` row reads *2 pairs* |
| P22E-T6 | A voided row with a shortfall | Shows `voided · ₱X recovered of ₱Y`; the header's *Unrecovered* equals the sum of shortfalls |
| P22E-T7 | CSV export | Header row and one row per filtered award; columns match the screen; `binary.export` audit row with the filters |
| P22E-T8 | `admin/orders/show` — paid and processed | Position, points, self, each pair (node, pair count, points, amount, status), totals equal the order's `binary_*` columns |
| P22E-T9 | `admin/orders/show` — unpaid order | "Paid orders place points. This order is `pending`." — no rows |
| P22E-T10 | `admin/orders/show` — paid before the feature | "This order was paid before the Product binary stream was switched on." |
| P22E-T11 | `admin/orders/show` — paid with the stream off | "No points were placed: the stream was off when this order was paid." |
| P22E-T12 | `admin/members/{id}` panel | Node facts (upline, side, depth, path, placed at/by), L/R available and lifetime, pairs, points matched, personal points, pairs today vs cap, blocked pairs and reasons |
| P22E-T13 | `admin/members/{id}` — a member with no node | "Not placed in the binary tree." with the console link |
| P22E-T14 | Reports page | The five cards render with the fixture numbers; the dashboard KPI reads the last 30 days |
| P22E-T15 | Report export `type=binary` | The range CSV includes the binary rows and the header names the stream |
| P22E-T16 | Live feed | An award bumps `admin.commissions`; an open binary tab refreshes; the other tabs are unaffected |
| P22E-T17 | A member opens `/admin/commissions?ledger=binary` | 403, same guard as the other tabs |
| P22E-T18 | `admin/members?placement=floating` | Lists the floating count and the members; links to the console panel |
| P22E-T19 | Reports readiness card | Products without P.V., products with the switch off, members without a node — counts match SQL |
| P22E-T20 | `git diff --stat` | Only the 22E manifest; no model's write path touched |

**Manual gate — `QA22E-01` … `QA22E-09`**

1. `QA22E-01` — with the awards of `QA22D-01..03` present, open `/admin/commissions?ledger=binary`: the
   header shows ₱150.00 credited (after release) or ₱150.00 pending (before), 3 awards, 2 pairs.
2. `QA22E-02` — filter kind `pair` → one row (`c`, ₱100.00, 1 pair); filter side `right` → the buyerB
   rows.
3. `QA22E-03` — cancel a paid order as an admin, then reload: the rows show `cancelled`/`voided` with the
   recovered amount, and *Unrecovered* shows any shortfall.
4. `QA22E-04` — click a row's order link: `/admin/orders/{id}` opens with the binary card and the same
   numbers; back on the ledger, click the wallet link → the movement.
5. `QA22E-05` — export the filtered CSV; open it; the columns and values match the screen.
6. `QA22E-06` — open an order that is `payment_review`: the card explains that nothing is placed yet.
   Open an order paid before 22D: the card says so.
7. `QA22E-07` — `/admin/reports`: the five binary cards show the same totals; export the range CSV and
   find the binary rows. The dashboard tile shows the 30-day total.
8. `QA22E-08` — `/admin/members/{id}`: the binary panel shows the node, the balances and the blocked-pair
   reason when a recipient is inactive; the *Open in tree* link lands on the tree once 22G ships (until
   then it is disabled with a tooltip).
9. `QA22E-09` — switch to the **Repeat-purchase bonus** and **Royalty** tabs: they look and behave
   exactly as before.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22E-T1 … P22E-T20 pass
[ ] The three existing tabs are byte-identical (diff or snapshot)
[ ] No new route; ParityTest and RouterTest green
[ ] Every number on the page is traceable: row → order, row → wallet movement, node → volume rows
[ ] Tag p22e-admin; phase log; Appendix H row
```

**Do not start 22F or 22G until every box is ticked.**

**Rollback**

Revert the views and the controller. The data is untouched (read-only phase), so no money or points
change. The report cards disappear; the ledger rows remain in SQL and in 22D's APIs.

**Hand-off to 22F/22G**

- `BinaryAward` and `BinaryNode` read APIs are proven by an admin page; 22F renders the same rows for
  the member and 22G uses the node summaries for the tree.
- The order card and the member panel define the vocabulary the member card and the tree will reuse.
- The report cards define the five numbers the release note will quote.

**Risks**

| Risk | Mitigation |
|---|---|
| A new tab changes an existing tab's markup | Byte-identical regression (P22E-T1) |
| Points and pesos are added together in a tile | Rule 4; P22E-T3 asserts separate values |
| A blocked pair looks like a skipped award | Rule 5; the panel labels it "not paid — waiting for eligibility" |
| The member panel leaks the tree | Rule 11; it renders one node's facts, never children |
| The readiness card becomes an action | Rule 10 |

---

## 10. Phase 22F — Member visibility

**Goal.** A member sees the Product binary offer at the product and at checkout, chooses a side when they
buy, and afterwards reads a **Product binary** tab whose **Left team** and **Right team** cards present
their team, points and progress in the same card language as the unilevel repeat tab — **without naming
any upline**. Plus an order card and a dashboard tile.

**Builds on.** 22D (the money and the points) and 22C (the tree).

**Ships alone because.** It is presentation and a checkout field over a proven engine. If 22G never
shipped, the member would still see and understand everything they earn and why; the tree is a navigation
surface, not a condition of correctness.

**Effort.** 2.0 ideal days. **Suite.** ≥ 1,694 cases.

**Reference.** §14.12 (the member copy rules), Appendix C (the copy deck), §16.2 (the read APIs),
the unilevel presentation in
[`earnings.php?ledger=repeat`](../system/app/views/member/earnings.php#L343-L444) and
[`_repeat_levels.php`](../system/app/views/member/earnings/_repeat_levels.php).

**Deliverables**

**Code**

- `system/app/Controllers/EarningsController.php` — `ledger=binary` selects the tab; loads totals, the
  two sides' summaries and rosters (separately paginated), the awards and the products filter; the CSV
  export includes the tab's rows.
- `system/app/Controllers/CheckoutController.php` — the position card data (already built in 22D);
  `place()` surfaces the `binary_position` field error.
- `system/app/Controllers/OrderController.php` — the order page's binary card data (position, points,
  self, own-node pairs for this buyer).
- `system/app/Controllers/DashboardController.php` — the tile's data.
- `system/app/Controllers/ShopController.php` — the product page's binary panel data (points per unit,
  self rate, the global switch state).
- Views:
  - `member/earnings/_binary_stats.php`, `_binary_progress.php`, `_binary_teams.php` (the Left/Right
    cards), `_binary_team_rows.php`, `_binary_self.php`, `_binary_rows.php`, `_binary_header.php`,
    `_binary_pagination.php` (new);
  - `member/earnings.php` — the fourth tab pill and the `binary` branch;
  - `shop/checkout/_binary.php` (new) and `shop/checkout.php` — the card above *Payment method*;
  - `shop/order/_binary.php` (new) and `shop/order.php` — below the repeat card;
  - `member/dashboard/_binary.php` (new) and `member/dashboard.php`;
  - `shop/product/_binary.php` (new) and `shop/product/_card.php`.
- `system/public/assets/css/commissions.css` — the team-card rules are already there (`.commission-team-*`);
  this phase adds only the two-column binary variant and the side badges.
- `system/public/assets/js/app-checkout.js` — the position card's client echo (points/self/pairs per
  side), progressive.

**Docs.** `docs/qa/phase-22-product-binary.md` gains section F (`QA22F-01` … `QA22F-10`).
`docs/admin-guide.md` gains the member page's explanation. Appendix C is the source of every string.

**Not yet.** No tree, no in-node registration, no release-job change, no `APP_PHASE` change.

**Rules**

1. **Membership copy is downward only (D-22).** The binary tab names no upline, no sponsor, no ancestor
   username, no "team chain" (that phrase belongs to the unilevel tab) and no level numbers. It says
   **your team**, **left team**, **right team**, **your points**, **your pairs**.
2. **The two team cards mirror the unilevel presentation.** Same `commission-team-card` structure, same
   member rows (username, status badge, package, points), same per-card pagination keys
   (`left_page`, `left_per_page`, `right_page`, `right_per_page`), same empty-state sentence pattern,
   same progress bar.
3. **A side is a card, not a table.** Left and Right sit side by side (`col-xl-6`) and stack on mobile.
   Each has: member count, available points, lifetime points, a progress bar to the next pair, the roster
   (own points and branch points), and its own pagination.
4. **Points are not pesos.** Every point number carries *pts*; every money number carries the currency
   symbol. They are never shown in the same column and never summed.
5. **The self bonus is stated plainly.** "You earn on your own purchase: 5% of its points." It is the one
   place in the application where the buyer is a recipient, and the copy says so without calling it a
   rebate, cashback or refund.
6. **The checkout position is a required, deliberate choice.** No default side; the order cannot be
   placed with points and no side; the card shows the points, the self and the expected own-node pairs
   for each side, and it explains that the choice is final.
7. **The unplaced state is honest.** A member with no node sees "You are not placed in the binary tree
   yet — ask your upline or an administrator", no position card, and an order that places no points. The
   page never blocks shopping.
8. **The stream-off state is absent, not disabled.** With `binary_enabled = 0`, the tab does not render,
   the checkout card does not render and the product panel does not render. No dead affords.
9. **No JavaScript-only behaviour.** The position radios submit with the form; the two side cards are
   server-rendered; the CSV is a plain link.
10. **Privacy.** A member's two cards list their own team only; a member outside their subtree is never
    named, counted or hinted at.
11. **The order card is the buyer's view.** It shows the side, the points, the self award and the
    buyer's own-node pairs; it does not list the uplines' awards (that is the upline's ledger, and the
    member page names no upline anyway).

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P22F-T1 | `/earnings?ledger=binary` as a placed member with volume | The four tabs render (package, repeat, royalty, binary); the binary tab is active; stats, progress, both team cards, the self card and the awards table |
| P22F-T2 | The Left team card | Member count, available and lifetime left points, next-pair progress, the roster (10 per page), the *Showing X–Y of Z* summary, the page links with `left_page` |
| P22F-T3 | The Right team card | The same, with `right_page`; the two pages move independently |
| P22F-T4 | A left member's row | Username, status badge, package, own points, branch points; the branch points equal that member's subtree personal points |
| P22F-T5 | An empty left side | "No members on your left team yet." plus the invite sentence; the card keeps its totals at 0 |
| P22F-T6 | Copy guard (D-22) | The rendered binary tab contains no ancestor username, no `upline`, no `sponsor`, no `Team chain`, no `level` label; it contains *Left team*, *Right team*, *your points*, *pairs* |
| P22F-T7 | A member with no node | The tab renders with the "not placed" panel, no team cards, no awards; `/checkout` shows the notice and no position card |
| P22F-T8 | Stream off | The tab pill is absent; `/earnings?ledger=binary` falls back to the package tab with no error |
| P22F-T9 | Checkout with eligible points | The position card renders: two radios, points, self, expected own-node pairs per side; submitting without a side is refused with the `binary_position` field error |
| P22F-T10 | Checkout with no eligible points | No position card; the order is placed with `binary_position = NULL` |
| P22F-T11 | Checkout card values | The points equal the cart's eligible points; the self equals `round(points × 5%)`; the pair counts match `quotePosition()` for the member's balances |
| P22F-T12 | Checkout card with the stream on and the cart mixing eligible and ineligible products | The points count only the eligible lines; the copy says which lines do not count |
| P22F-T13 | `/orders/{id}` after payment | The card shows the side, the points, the self (pending or credited) and the buyer's own-node pairs; the labels match the awards' statuses |
| P22F-T14 | `/orders/{id}` before payment | "This order places points when it is paid." |
| P22F-T15 | `/orders/{id}` for an order with no points | The card is absent |
| P22F-T16 | `/dashboard` | The binary tile shows left/right points, the next pair and the binary earned this month; the link opens the earnings tab |
| P22F-T17 | `/shop/{id}` for a binary product | The panel shows *N points per unit* and the self rate; for a product with the switch off or P.V. 0 the panel is absent |
| P22F-T18 | `/shop/{id}` with the stream off | No binary panel anywhere |
| P22F-T19 | The earnings CSV for `ledger=binary` | One row per award with the same columns as the screen; no upline column |
| P22F-T20 | Filters: kind, status, product, from/to | Each filters; the side cards and stats stay unfiltered (they are balances, not a filtered list) |
| P22F-T21 | The live surface | A new award on an open binary tab refreshes the stats and the table without losing the tab |
| P22F-T22 | Mobile width (375 px) | The two cards stack; no horizontal scroll; the roster remains readable; the checkout radios are full-width |
| P22F-T23 | A member opens another member's earnings URL | The page is session-scoped; the `user_id` query cannot change it |
| P22F-T24 | No new route | `routes.php` unchanged in this phase; `ParityTest` green |
| P22F-T25 | JS off | The position radios, the cards and the pagination all work |
| P22F-T26 | The unilevel repeat tab | Byte-identical to `p22d-money` except for the new pill (regression) |
| P22F-T27 | The royalty tab | Byte-identical (regression) |
| P22F-T28 | A member with pending and credited awards | The stats show both; the table's status labels are the member-facing labels (§Appendix C.8), never the admin's |

**Manual gate — `QA22F-01` … `QA22F-10`**

1. `QA22F-01` — as `buyerA` after `QA22D-01`, open `/earnings?ledger=binary`: the Self card shows
   ₱50.00 pending; the Left team card lists the members under `buyerA` (none yet) and the Right card is
   empty; the stats show 1,000 left points.
2. `QA22F-02` — as `c` (who earned the pair), open the tab: the Left team card lists the members under
   `c`'s left with their own points; the Right team card lists `buyerB`; the pair progress shows the
   matched points and the pair.
3. `QA22F-03` — page the left roster with `?ledger=binary&left_page=2&left_per_page=5`: only the left
   card moves; the right card and the awards table keep their page.
4. `QA22F-04` — screenshot the two cards at 1440×900 and at 390×844; the cards stack and every number is
   legible.
5. `QA22F-05` — read the rendered HTML: no ancestor username, no `upline`, no `sponsor`, no `Team chain`,
   no `Level 1` (D-22, P22F-T6).
6. `QA22F-06` — put 4 × P1 in the cart and open `/checkout`: the position card shows left and right with
   the same points and self; choose right and place the order with the e-wallet: the order page shows
   *Right · 1,000 pts*.
7. `QA22F-07` — try to place an order with eligible points and no side selected: refused with the field
   error; nothing is placed.
8. `QA22F-08` — open an unpaid order: the card says it places points when paid. Pay it: the card shows
   the points and the pending self.
9. `QA22F-09` — open `/dashboard` and `/shop/{id}`: the tile and the product panel read the same points
   as the earnings tab.
10. `QA22F-10` — switch the stream off in Settings and reload all four surfaces: every binary affordance
    disappears; the unilevel, royalty and package pages are unchanged.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22F-T1 … P22F-T28 pass
[ ] The copy guard is asserted in the suite (no upline, no sponsor, no "Team chain" on the binary tab)
[ ] The two team cards render on desktop and mobile widths with evidence screenshots
[ ] The other three earnings tabs are byte-identical (diff or snapshot)
[ ] A member with no node sees the honest state and can still shop
[ ] Tag p22f-member; phase log; Appendix H row
```

**Do not start 22G until every box is ticked.**

**Rollback**

Revert the views and the controller branches. The data is untouched. The checkout position field is
ignored by the engine when the view is gone, because a missing `binary_position` with eligible points
would refuse the order — so roll back the **view** first and the **requirement** second (set
`binary_enabled = 0`), or revert both in one commit. This is stated in the phase log.

**Hand-off to 22G**

- The member sees the offer, chooses a side and reads the outcome; the tree adds navigation, not new
  money.
- The card language (`.commission-team-card`, status badges, per-card pagination) is proven; the tree's
  node cards reuse the side badges and the points labels.
- The `?ledger=binary` tab is linked from the tree's header and from the dashboard tile.

**Risks**

| Risk | Mitigation |
|---|---|
| The member page names an upline | The copy guard test (P22F-T6) and the copy deck |
| Points and pesos are conflated | Rule 4; every number carries its unit |
| The checkout requirement is added without the view, blocking checkout | The rollback note; the order refuses only when the stream is on and the cart has points |
| The two cards behave differently | One partial renders both, with the side as data; the tests cover both pages |
| The new tab breaks the other three | Byte-identical regressions (P22F-T26/T27) |
| A big roster is slow | Per-card pagination, 10 rows default, one query per card page (§18.5) |

---

## 11. Phase 22G — The binary tree

**Goal.** `/genealogy?view=binary` is a responsive, accessible, live d3 genealogy tree of the placement
tree — server-rendered on first paint, lazy for depth, searchable, deep-linkable, keyboard- and
touch-operable, usable without JavaScript — and every free slot on it can **register a member into that
slot** through the application's own registration rules. Administrators get the same explorer with a root
selector and a move link.

**Builds on.** 22C (the tree and the placement rules), 22E (the admin vocabulary), 22F (the member page it
is linked from).

**Ships alone because.** The tree is a navigation surface: every number it shows is already computed,
every action it offers is a link to a page or a form that already exists, and every registration goes
through `RegistrationService`. If 22H and 22I never shipped, the tree is still a complete, correct
feature — it makes 22C's tree and 22D's points *visible*.

**Effort.** 3.0 ideal days. **Suite.** ≥ 1,722 cases.

**Reference.** §14.2, §14.5, §16.2; the reference implementation
[`dmp/cart-system/views/member/genealogy.php`](cart-system/views/member/genealogy.php#L443-L980) and
[`MemberController::apiBinaryTree()`](cart-system/controllers/MemberController.php#L331-L389); the
comparison in §11.2.

### 11.1 What ships

- **One page, two views.** `/genealogy` keeps the sponsor view (unchanged, default) and gains
  `?view=binary`. The same page serves members and admins (the controller already renders the admin
  layout for admins).
- **Server-rendered first paint.** The page embeds the initial subtree (default depth 3, capped at 500
  nodes) as JSON and renders the same subtree as a semantic list; the SVG replaces the list when d3 has
  loaded. There is **no spinner** on first paint and there is **no CDN**.
- **Lazy subtrees from the same URL.** `GET /genealogy?view=binary&format=json&root=<id>&depth=<n>`
  returns the subtree for one node. It is the same route and the same guard; a member may request only a
  root inside their own subtree (their own node included), an admin may request any node. ETag +
  `If-None-Match` gives a 304 for an unchanged subtree.
- **A node card** with: `@username` (name only for admins), status dot, side badge (`L`/`R`), depth,
  personal points, left/right available points, pairs, today's pairs vs cap, joined date, a **You**
  marker, and an actions menu (focus, expand, collapse, *Register here* on a free slot, *Copy invite
  link*, *View member* for admins, *Open earnings* for the viewer's own node).
- **In-node registration.** Every empty slot carries a `+`. Clicking it opens a modal whose placement is
  **locked** to that upline and side and whose form is the registration form (mode, username, email,
  name, phone, password, package or code, terms). It validates live (username, sponsor) and submits to
  `register.php` with `binary_upline`, `binary_position` and `return=genealogy`; the new member appears
  in the tree, focused, with a flash. Without JS the `+` is a link to the prefilled register page.
- **Invite links.** *Copy invite link* yields
  `/register?ref=<code>&upline=<username>&side=<left|right>`; the register page prefills the sponsor and
  the placement. It is a hint, not a reservation (D-26).
- **Controls.** Fit, zoom in/out, expand all, collapse all, depth selector (1–5), orientation toggle
  (vertical/horizontal), compact mode, *Back to me*, search, fullscreen, legend.
- **Live.** The tree's ETag scope includes `member.genealogy` (shape) and `binary` (points/awards); a
  revision change refreshes the focused subtree in place, preserving pan, zoom and expansion, with a
  quiet "Tree updated" note.
- **Accessibility.** `role="tree"`/`treeitem`, `aria-expanded`, `aria-level`, roving tabindex, arrow
  keys, Home/End, Enter/Space to expand, `+`/`-` to zoom, Esc to close the modal or the search, visible
  focus, a text alternative per node and the no-JS list as the screen-reader-first document.
- **Responsive.** `viewBox` + `ResizeObserver`; node geometry from the container's width; a compact list
  mode below 576 px; touch pan and pinch; no page-level horizontal scroll; `prefers-reduced-motion`
  disables transitions.

### 11.2 The comparison — topping the reference

| Capability | Reference (`dmp/cart-system`) | This plan |
|---|---|---|
| Data source | CDN d3 + one JSON API, spinner on load | vendored d3 v7 + server-rendered first paint, JSON for lazy branches |
| No-JS | unusable | a full semantic list with the same data and links |
| Keyboard | none | full ARIA tree + roving tabindex + shortcuts |
| Search | none | member search scoped to the viewer's subtree, deep-link `?focus=` |
| Deep link | none | `?view=binary&root=&depth=&focus=` is shareable and back-button safe |
| Node content | username, package, joined, `L:`/`R:` counters | username, status, side, depth, personal/left/right points, pairs, today's pairs, joined, You |
| Register in a node | a bespoke modal that duplicates the registration rules | the same modal, but it calls the application's own `RegistrationService` and its rules |
| Slot actions | `+` on any node with a free side | `+` on the exact empty slot, with the side locked |
| Invite | referral link only | slot-specific invite link, copy button |
| Live | none | revision-driven, state-preserving refresh |
| Resize | reloads the page when mobile/desktop changes | `ResizeObserver`, no reload |
| Depth | fixed 4 | depth selector 1–5 + expand-all with a node cap |
| Orientation | vertical only | vertical or horizontal |
| Large trees | unbounded recursion in PHP | capped nodes per request, lazy, `MAX_DEPTH` |
| Privacy | any member can request `?root=` any id | a member may request only their own subtree; the server refuses anything else |
| Admin | same tree, no extra powers | root selector, any-node search, node menu with *View member* and *Move placement* (link) |

### 11.3 Files

**Code**

- `system/app/Services/BinaryTreeService.php` (new) — `subtree(int $rootId, int $depth, int $viewerId,
  bool $isAdmin)`, `node(int $userId)`, `path(int $userId)`, `search(string $term, int $viewerId,
  bool $isAdmin)`, `listRows(int $rootId, int $depth, int $viewerId)`, `counts(int $rootId)`,
  `canRegister(int $viewerId, int $uplineId, string $side)`, `etagScope()`. It is the only place that
  shapes the tree JSON; the controller and the view never build it.
- `system/app/Controllers/GenealogyController.php` — `?view=binary` selects the binary context;
  `?format=json` returns the tree JSON (with `If-None-Match` handling); the sponsor branch stays
  byte-identical.
- `system/app/views/member/genealogy.php` — the `view` switch, the binary header, the explorer container,
  the embedded JSON (`<script type="application/json" id="binaryTreeSeed">`), the no-JS list, the
  controls, the legend, the modal and the invite dialog.
- `system/app/views/member/genealogy/_tree.php` (new) — the shell and the list.
- `system/app/views/member/genealogy/_tree_node.php` (new) — one node in the no-JS list (recursive
  include, bounded by the depth).
- `system/app/views/member/genealogy/_tree_actions.php` (new) — the per-node menu markup.
- `system/app/views/member/genealogy/_register_modal.php` (new) — the in-node registration form
  (progressive; `action="<?= url('register') ?>"`).
- `system/public/assets/js/app-binary-tree.js` (new) — the d3 explorer (≈700 lines): layout, zoom,
  lazy loading, search, state preservation, keyboard, ARIA mirroring, live refresh.
- `system/public/assets/js/app-binary-register.js` (new) — the modal's live validation and submit state
  (progressive; reuses the register page's field rules).
- `system/public/assets/css/binary-tree.css` (new) — nodes, links, badges, legend, controls, compact
  mode, print styles.
- `system/public/assets/vendor/libs/d3/d3.v7.min.js` (new, vendored v7.9.0) and
  `system/public/assets/vendor/libs/d3/LICENSE` — no CDN (D-19).
- `system/app/Services/Live.php` — `public const BINARY = 'binary';` and
  `binarySurface(int $userId)`; `BinaryService` touches it on every award/volume write and
  `BinaryPlacementService` touches it on every placement.

**Docs.** `docs/qa/phase-22-product-binary.md` gains section G (`QA22G-01` … `QA22G-12`).
`docs/live-updates.md` gains the `binary` surface. `docs/admin-guide.md` gains "The binary tree" and
"Registering into a slot". `docs/deploy.md` gains the vendored asset note.

**Not yet.** No release change (22H), no `APP_PHASE` change (22I), no member-facing export of the tree.

### 11.4 The JSON contract

Every payload answers with the same shape; the view and the JS consume it unchanged.

```json
{
  "ok": true,
  "root": 42,
  "viewer": 42,
  "is_admin": false,
  "depth": 3,
  "nodes": 118,
  "truncated": false,
  "can_register": true,
  "etag": "\"…\"",
  "tree": {
    "id": 42, "username": "c", "name": null, "side": "left", "depth": 4,
    "status": "active", "member_status": "active", "package": "Package A",
    "joined": "2026-10-02", "is_you": true,
    "points": {"left": 1000, "right": 0, "left_lifetime": 1000, "right_lifetime": 0,
               "personal": 0, "pairs": 1, "pairs_today": 0, "pair_points": 1000},
    "slots": {"left": {"free": true, "user": null}, "right": {"free": false, "user": 7}},
    "can_move": false, "has_more": true,
    "children": [
      {"id": 6, "username": "buyerA", "side": "left", "…": "…", "children": []},
      {"id": 7, "username": "buyerB", "side": "right", "…": "…", "children": []}
    ]
  }
}
```

Rules: `name` is `null` for a non-admin viewer; `slots` is present only when the viewer may register
there (a member sees their own subtree's slots only); `children` contains at most two entries; a missing
child is represented by its `slots` entry, never by a placeholder object (the JS draws the `+` from
`slots`); `has_more` is true when the node has children below the requested depth; `truncated` is true
when the 500-node cap cut the payload; `can_register` is the page-level permission.

### 11.5 Rules

1. **The server owns the data; the client only draws it.** The JS never computes points, pairs or
   permission. Every read comes from `BinaryTreeService`; every action is a link or a form post.
2. **First paint is server-rendered.** The embedded seed and the no-JS list are the same subtree; the SVG
   is an enhancement. If d3 fails to load, the list stays and a notice says so.
3. **Privacy is enforced server-side.** A member may pass `?root=` an id only inside their own subtree;
   anything else is a 403 (JSON) or a redirect to their own tree (page). Search is scoped the same way.
4. **The node cap and the depth cap are hard limits.** `depth ∈ [1,5]`, nodes ≤ 500 per payload; beyond
   that `truncated = true` and `has_more` drives the next request. A 100,000-node tree still renders a
   first paint in bounded time.
5. **Lazy loading is per branch.** Expanding a node fetches only that subtree (depth 2) and merges it;
   collapse/expand never re-fetches what is already loaded.
6. **In-node registration goes through the application's rules.** The modal posts to
   `public/register.php` with the placement fields; `RegistrationService` validates the slot, the cycle,
   the subtree and the account rules. The modal **cannot** create a member the register page would
   refuse, and it never writes directly.
7. **The slot is locked in the modal.** The upline and side are read-only text; the member cannot change
   them in the modal (they can on the full register page, where the same rules apply).
8. **A taken slot loses gracefully.** A race lost to another registration returns the register page with
   the slot-taken error, and the tree refreshes to show the new child.
9. **One new surface, no new route.** `?view=binary&format=json` is the same `/genealogy` route; D-18
   holds.
10. **The sponsor view is untouched.** With `view=binary` absent, the page's HTML is byte-identical to
    `p22f-member` (regression test).
11. **State survives refresh.** Pan, zoom, expanded branches and the focused node are kept across a live
    refresh and across a lazy load; the URL records `root`, `depth` and `focus`.
12. **No layout thrash.** A `ResizeObserver` re-lays out; the page never reloads on a breakpoint change.
13. **Motion is optional.** `prefers-reduced-motion: reduce` removes the transitions; the tree still
    works.
14. **Print is the list.** The print stylesheet hides the controls and the SVG, and prints the no-JS list
    with its points.
15. **Vendored, versioned, licensed.** d3 is committed with its version and licence; no CDN, no
    `integrity` from a remote host.

### 11.6 Test plan

| ID | Test | Expected |
|---|---|---|
| P22G-T1 | `/genealogy?view=binary` as a placed member | The binary header, the seed JSON, the no-JS list, the controls and the legend render; the sponsor view is absent |
| P22G-T2 | The seed JSON | `BinaryTreeService::subtree()` output; `depth = 3`; `nodes` equals the list rows; `truncated = false` on the fixture |
| P22G-T3 | The no-JS list | One `<li>` per node with username, side, points and the expand link; no JavaScript required |
| P22G-T4 | `?format=json` as the member for their own root | 200, `application/json`, the contract of §11.4, no HTML |
| P22G-T5 | `?format=json&root=<a member outside the viewer's subtree>` | 403 (JSON) for a member; 200 for an admin |
| P22G-T6 | `?format=json&root=<own id>&depth=9` | Depth clamped to 5; no error |
| P22G-T7 | A node with 600 descendants requested at depth 5 | `truncated = true`, 500 nodes, `has_more` set on the cutoff nodes |
| P22G-T8 | `If-None-Match` with the payload's ETag | 304, no body |
| P22G-T9 | A member's own `has_more` node expanded | One request for that branch; the JSON is that subtree only; the parent's data unchanged |
| P22G-T10 | `?focus=<username in subtree>` | The page centres that node and sets the breadcrumb; an unknown or out-of-scope name falls back to the root |
| P22G-T11 | Node card content | Username, status, side badge, depth, personal/left/right points, pairs, today's pairs, joined, the You marker |
| P22G-T12 | `name` visibility | `null` for a member; the full name for an admin |
| P22G-T13 | Free slots | Each empty side carries a `+`; a full node carries none; the slot's side is in the link and the modal |
| P22G-T14 | `canRegister()` | False for a suspended upline, a staff upline, the member's own node? (own node **is** allowed: self-placement of a downline) and a full slot; true for a legal slot |
| P22G-T15 | The registration modal renders | The locked upline/side, the mode choices, username/email/password/package fields, CSRF, `return=genealogy`; no placement controls that could change the slot |
| P22G-T16 | Post the modal for a free slot | A member is created, placed exactly there, and the page returns to the tree focused on the new node with a flash |
| P22G-T17 | Post the modal for a slot taken in the meantime | The register page returns with the slot-taken error; no member created; the tree shows the winner |
| P22G-T18 | Post the modal with a taken username | The register page returns with the username error; no member, no node |
| P22G-T19 | The invite link | `/register?ref=<code>&upline=<u>&side=left` prefills the sponsor and the placement on the register page |
| P22G-T20 | Search scoped to the viewer | Finds a member in the subtree; refuses a member outside it (no result, no existence leak) |
| P22G-T21 | Admin root selector | Lists *Company tree* and any member with a node; selecting one re-roots the payload and the URL |
| P22G-T22 | Admin node menu | *View member* → `/admin/members/{id}`; *Move placement* → the member page's placement panel (not a bare action) |
| P22G-T23 | Live refresh | A new award bumps `binary`; the open tree refreshes the focused subtree, keeps pan/zoom/expansion and shows the note |
| P22G-T24 | A placement change bumps the tree | A new child appears in the focused subtree without a reload |
| P22G-T25 | Resize | 1440 → 768 → 390 px: the tree re-lays out, no reload, no horizontal page scroll, the compact list below 576 px |
| P22G-T26 | Keyboard | Tab into the tree, arrows traverse, Enter expands, Home/End jump, `+`/`-` zoom, Esc closes the modal; focus is always visible |
| P22G-T27 | ARIA | `role="tree"`, every node `treeitem` with `aria-level`, `aria-expanded` on parents, a text alternative per node |
| P22G-T28 | d3 unavailable | The list stays usable, a notice appears, the server-rendered content is unchanged |
| P22G-T29 | JS off | The list, the search link, the pagination and the register link all work |
| P22G-T30 | Reduced motion | The transitions are off; the tree is still navigable |
| P22G-T31 | Sponsor view regression | `/genealogy` (no `view`) is byte-identical to `p22f-member` |
| P22G-T32 | Routes | No new route in this phase; `ParityTest` green; the page's guard is still `member` |
| P22G-T33 | A member requests another member's tree with `?root=` | The page redirects to their own tree (no error page, no leak) |
| P22G-T34 | Performance on a 2,000-node fixture | The first payload ≤ 500 nodes; the page renders within the smoke budget; one query per depth level, not per node |
| P22G-T35 | `binary-tree.js` syntax and size | Parses under `node --check`; no console error in the smoke run; the vendored d3 is present with its licence |

### 11.7 Manual gate — `QA22G-01` … `QA22G-12`

1. `QA22G-01` — as `c`, open `/genealogy?view=binary`: the tree centres `c`, shows its two children,
   their points and pairs, and the status dots. No spinner, no console error.
2. `QA22G-02` — click a child with `has_more`: the branch expands with its children; click again: it
   collapses without a request.
3. `QA22G-03` — use *Fit*, zoom in/out, the orientation toggle and the depth selector; the URL updates and
   a page reload restores the same view.
4. `QA22G-04` — search a member in the subtree: the tree centres them; search a member outside it: a
   "not in your tree" message, no name.
5. `QA22G-05` — click the `+` on an empty left slot: the modal opens with the upline and side locked;
   complete the form and submit; the member is created, the tree focuses the new node, the flash names
   the placement.
6. `QA22G-06` — open the same slot in a second tab, register there first, then submit the first tab:
   the slot-taken error appears on the register page and the tree shows the winner. No duplicate member.
7. `QA22G-07` — disable JavaScript and reload: the list renders with every node and its points; the
   `+` links open the register page with the placement prefilled.
8. `QA22G-08` — keyboard-only: Tab into the tree, traverse with the arrows, expand with Enter, open the
   modal with Enter on the `+`, complete it with the keyboard, Esc out. Focus is always visible.
9. `QA22G-09` — resize to 390 px: the compact list appears, no horizontal scroll; the controls remain
   reachable.
10. `QA22G-10` — pay an order for a member inside the open subtree in another tab: the tree updates the
    points and pairs in place, keeping the pan and the expanded branches.
11. `QA22G-11` — as an admin, open the tree: the root selector lists the company tree and the members;
    search finds any member; the node menu links to the member page and the placement panel.
12. `QA22G-12` — open `/genealogy` (the sponsor view): it is byte-identical to `p22f-member`, and the
    admin's own tree is reachable from the new tab.

### 11.8 Exit gate

```
[ ] The universal gate of §2.4
[ ] P22G-T1 … P22G-T35 pass
[ ] The tree renders first paint with JS off and on, and passes the keyboard script
[ ] The privacy cases (P22G-T5/T19/T33) are asserted server-side, not by hiding UI
[ ] In-node registration creates a member through RegistrationService and no other path
[ ] The sponsor view is byte-identical
[ ] d3 is vendored with version and licence; no remote script anywhere
[ ] Evidence screenshots at 1440×900 and 390×844 for QA22G-01, 05 and 09
[ ] Tag p22g-tree; phase log; Appendix H row
```

**Do not start 22H until every box is ticked.**

### 11.9 Rollback

Remove the nav/tab link and the `view=binary` branch; revert the controller and the views. The tree data
and the money are untouched. The vendored d3 and the JS assets may stay (unused, no request). A
half-rolled-back tree (view removed but JSON still served) is safe: the JSON is read-only and guarded.

### 11.10 Hand-off to 22H

- The `binary` live surface exists and is bumped by both the engine and placement.
- The tree is the support tool the runbook's "why did this member not pair?" section walks: node →
  blocked pairs → reason → volume rows.
- The admin can reach any member's placement panel from the tree, so the move action has a discoverable
  home.

### 11.11 Risks

| Risk | Mitigation |
|---|---|
| A huge tree freezes the browser or the server | Hard node/depth caps, lazy branches, bounded SQL, P22G-T7/T34 |
| A member reads another member's tree | Server-side scope checks on `root` and `search`; P22G-T5/T19/T33 |
| The modal bypasses registration rules | It posts to `register.php`; the service is the only writer; P22G-T16–T18 |
| A slot race creates two children | The unique key; P22G-T17 |
| d3 from a CDN breaks offline or leaks a request | Vendored, licensed, no remote script (D-19) |
| The sponsor view changes by accident | Byte-identical regression (P22G-T31) |
| The tree's numbers disagree with the earnings tab | Both read `BinaryNode::pointsSummary()` / `BinaryAward`; no second calculation |
| Keyboard or screen-reader users are locked out | ARIA + roving tabindex + the no-JS list; P22G-T26–T29 |
| A resize reloads the page (the reference's behaviour) | `ResizeObserver`; P22G-T25 |
| The seed JSON is stale after a live change | The seed is the first paint; the live refresh replaces it |

---

## 12. Phase 22H — Operations

**Goal.** The escrow releases unattended, the points can be audited and repaired from the CLI, the health
checks report the stream's state, and the cron/runbook documentation matches what runs. **No money rule
changes.**

**Builds on.** 22D (the engine and the award rows).

**Ships alone because.** It is the operational envelope of a stream that already pays. If 22I never
shipped, the operator still has a scheduled release, a provable ledger and a doctor — the difference
between a feature and a liability.

**Effort.** 1.5 ideal days. **Suite.** ≥ 1,734 cases.

**Reference.** §16.3 (the release job), §16.10 (reconcile and audit), §15.6 (invariants), §17.3
(self-dealing and the cap).

**Deliverables**

**Code**

- `system/bin/unilevel_release.php` — the release job gains `--stream=unilevel|binary|all` (default
  `all`), `--limit=`, `--dry-run`, `--json` and `--order=`. With `all`, it releases the unilevel awards
  and then the binary awards for the same due orders, each in its own transaction. The existing
  behaviour with no flag is **unchanged for unilevel** and additionally releases binary — the cron entry
  does not change. `--stream=unilevel` reproduces the pre-22 behaviour exactly (pinned by a test).
- `system/bin/unilevel_release.cmd` — unchanged (it passes its arguments through; a new `--stream` is
  documentation, not a new file).
- `system/bin/binary_audit.php` (new) — `--check` (default), `--fix`, `--node=<id>`, `--json`,
  `--limit=`. Reports: nodes whose cached balances disagree with `binary_volume`; volume rows whose
  `side`/`kind`/sign violate the contract; pair awards whose consumption rows are missing or duplicated;
  awards whose amount disagrees with `pair_count × pair value`; orders stamped but with no volume and a
  positive `binary_points`; nodes with a broken `path`/`depth`; duplicate slots (impossible, but checked
  against a hand-edited database); the longest path vs `MAX_DEPTH`; the company root's award count
  (must be 0). `--fix` recomputes only the cached columns; it never edits an award, a volume row or a
  wallet.
- `system/app/Services/HealthService.php` — four checks appended to the existing pack: **binary
  readiness** (switch state, flagged products with P.V. 0, members with no node), **binary points**
  (`BinaryService::audit()` summary), **binary escrow** (pending award count/amount and the oldest
  pending age), **binary release job** (last run, from `CronLog`). Each check has a `pass|warn|fail`
  state and a one-line remedy.
- `system/app/Services/BinaryService.php` — `audit()` and `auditFix()` (already used by 22D's tests) are
  finished here with the full check list of the CLI.
- `system/app/Controllers/AdminReportController.php` — the readiness card links to the health panel
  (read-only).

**Docs**

- `docs/cron.md` — the release job's new flag, the two streams, the recommended schedule (unchanged) and
  the dry run.
- `docs/runbook.md` — *"A pair is missing"*, *"A wallet credit is missing"*, *"The audit reports drift"*,
  *"A member has no node"*, *"The tree is wrong for a member"*, *"The stream must be stopped now"*.
- `docs/deploy.md` — the CLI surface and the health checks.
- `docs/qa/phase-22-product-binary.md` — section H (`QA22H-01` … `QA22H-07`).
- `docs/admin-guide.md` — "Running the release", "Reading the audit".

**Not yet.** No `APP_PHASE` change, no QA-pack sign-off, no release note.

**Rules**

1. **The release job is idempotent and per-order transactional.** Each order releases in its own
   transaction; a failure is logged and the batch continues; a second run releases nothing.
2. **`--stream=unilevel` is byte-identical to the pre-22 job.** The default (`all`) adds binary. The
   operator's existing cron keeps working and starts releasing the new stream.
3. **The release holds the order lock first**, then credits wallets in ascending recipient order
   (the unilevel contract). A cancelled order never releases.
4. **The audit is read-only by default and safe to run in production.** `--fix` touches only cached
   columns (`binary_nodes.left_points`, `right_points`, `left_credited`, `right_credited`,
   `points_matched`, `pairs_paid`, `personal_points`).
5. **The audit's exit code is the gate.** 0 when clean, 1 when drift is found (even if `--fix` would
   repair it), 2 on a runtime error. `--fix` exits 0 only after re-checking clean.
6. **Health checks never change data.** They read and report; the remedy is a link or a command in the
   text.
7. **No new cron entry.** The release schedule, the month close and the existing jobs are unchanged. A
   second cron entry is a decision for the operator, documented, not shipped.
8. **Logs are structured and bounded.** One line per released order in the job log (`CronLog`), one
   summary line per audit run, no per-node spam.
9. **The doctor is documented before it is trusted.** Each of the four checks has a runbook entry and a
   remedy; a check with no remedy is not shipped.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P22H-T1 | Release job, no flag, a due binary order | The binary awards release; the output counts them; the unilevel awards release as before |
| P22H-T2 | `--stream=unilevel` | The binary awards stay pending; the unilevel output is identical to the pre-22 run |
| P22H-T3 | `--stream=binary` | Only binary awards release; unilevel stays pending |
| P22H-T4 | `--dry-run` | Prints the due orders and awards; writes nothing; exit 0 |
| P22H-T5 | Release twice | The second run releases nothing; no second ledger row |
| P22H-T6 | Release with `binary_release_enabled = 0` | Binary awards stay pending; unilevel still releases |
| P22H-T7 | Release a cancelled order | `--order=` returns `cancelled`; nothing moves |
| P22H-T8 | `binary_audit.php --check` on a clean fixture | Exit 0; every node's balances equal the ledger; every pair's consumption rows present |
| P22H-T9 | `--check` with a corrupted `left_points` | Exit 1; the node and the delta are named |
| P22H-T10 | `--fix` | The cache is repaired; the re-check is clean; exit 0; awards/volume/wallet untouched |
| P22H-T11 | `--check` with a missing pair-consumption row | Exit 1; the award and the missing side are named |
| P22H-T12 | `--check` with an order stamped and no volume | Exit 1; the order is named |
| P22H-T13 | `--check` with a hand-deleted node's child (broken path) | Exit 1; the node, the stored path and the expected path are named |
| P22H-T14 | Health pack | The four checks report `pass` on a healthy fixture; `warn` for products with P.V. 0 and members with no node; `fail` for drift; the summary text names each remedy |
| P22H-T15 | Cron log | One line per released order, one summary per audit; no per-node lines |
| P22H-T16 | `--json` on both commands | Machine-readable, stable keys, exit codes as in Rules 5 |
| P22H-T17 | The full suite and smoke | Green; `CronTest` covers the new flag; `smoke.ps1` runs the dry run |
| P22H-T18 | No data changed by health or audit `--check` | Row counts and balances identical before and after |

**Manual gate — `QA22H-01` … `QA22H-07`**

1. `QA22H-01` — deliver a paid order and run `php bin/unilevel_release.php`: both streams' awards
   release; the e-wallet shows a `binary` credit with the award id.
2. `QA22H-02` — run it again: *0 released*; run with `--dry-run` before a delivery: *not due*.
3. `QA22H-03` — run `php bin/binary_audit.php`: exit 0 and a clean summary. Corrupt a cached balance by
   hand, run it: exit 1 and the node named; `--fix`: repaired; `--check`: clean.
4. `QA22H-04` — open the health panel: the four binary checks render with their states and remedies; the
   readiness links open the filtered lists.
5. `QA22H-05` — switch `binary_release_enabled` off, deliver an order, run the job: binary stays pending;
   turn it on, run again: released.
6. `QA22H-06` — read `docs/cron.md` and `docs/runbook.md` against the commands: every command in the docs
   runs as written (copy-paste check).
7. `QA22H-07` — run the audit on the demo database during business hours: no lock timeout, no long
   transaction, the site stays responsive.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22H-T1 … P22H-T18 pass
[ ] `--stream=unilevel` reproduces the pre-22 job exactly
[ ] The audit exits 1 on every seeded defect and 0 after `--fix`
[ ] The health pack's four checks are green on a clean demo database
[ ] Every command in cron.md/runbook.md is copy-paste verified
[ ] Tag p22h-operations; phase log; Appendix H row
```

**Do not start 22I until every box is ticked.**

**Rollback**

Set `binary_release_enabled = 0` (pending awards stop releasing), then revert the code. The audit and
the health checks are read-only and may be removed with no data effect. The release job's
`--stream=unilevel` default keeps the pre-22 behaviour available.

**Hand-off to 22I**

- The release, audit and health surfaces are proven and documented.
- The runbook's remedies are executable.
- The counts the release note will quote (awards, points, release amounts) have a command that produces
  them.

**Risks**

| Risk | Mitigation |
|---|---|
| The extended job changes unilevel behaviour | `--stream=unilevel` pinned by P22H-T2; the default adds a stream, it does not alter one |
| The audit locks tables in production | Read-only, bounded, indexed queries; `--fix` updates one row per drifted node; QA22H-07 |
| `--fix` hides a real corruption | It only rewrites caches from the ledger; the ledger and the awards are never touched; a drift that cannot be explained exits 2 |
| The health pack cries wolf | Four checks with remedies, tested on a clean fixture (P22H-T14) |
| A second cron entry is added by accident | Rule 7; the docs name the single job |

---

## 13. Phase 22I — Release

**Goal.** Documentation that matches the code, a QA pack a stranger can execute, `APP_PHASE 22`, a signed
acceptance run and a phase log. **No behaviour change in this phase.**

**Builds on.** All of 22A–22H.

**Ships alone because.** It ships nothing but the record — the release is the gate that proves the other
eight phases are reproducible.

**Effort.** 1.5 ideal days. **Suite.** 1,734 cases (no floor increase; three regression cases may be
added and the final count recorded).

**Reference.** §18.6 (the acceptance script), Appendix H (the gate record), §20 (the open questions the
release records but does not answer).

**Deliverables**

**Code**

- `system/app/config.php` — `APP_PHASE` **22** (D-21) and the phase comment naming this plan.
- `system/tests/Cases/HardeningTest.php` — asserts `APP_PHASE === 22`.
- `system/tests/Cases/BinaryReleaseTest.php` (new) — the phase, the documented counts (42 tables,
  89 settings), the routes inventory, the vocabulary manifest, the copy guards and the release job's
  default stream, as one release-level case file.
- No other code. A defect found during the acceptance run is fixed in the phase that owns it, with its
  case, **before** 22I is signed; 22I itself changes only the constant, the test and the docs.

**Docs**

- `docs/qa/phase-22-product-binary.md` — sections A–H completed, the **Appendix B acceptance run**
  recorded, the evidence index filled, `QA22I-01` … `QA22I-06` added.
- `docs/qa/README.md` — the Phase 22 row marked *released*; the check total.
- `docs/qa/sign-off-sheet.md` — the Phase 22 block signed.
- `docs/phase-log.md` — the nine gate entries, the deviations, the hand-off.
- `docs/admin-guide.md`, `docs/deploy.md`, `docs/cron.md`, `docs/runbook.md`, `docs/install-reset.md`,
  `docs/live-updates.md`, `system/README.md` — the final read-through against the code (every command,
  key, route and count).
- `dmp/plan-ecowealth-product-binary.md` — the status line becomes *implemented*, with the release tag
  and the final counts.
- Appendix H of this document — filled.

**Not yet.** Nothing. The plan is closed; a later change to the stream is a new plan.

**Rules**

1. **No behaviour change in this phase.** Only `APP_PHASE`, tests and documentation. A code fix
   discovered here is a defect in its owning phase and goes back there.
2. **The cold read is mandatory.** Someone who did not implement the feature reads the plan, the QA pack
   and the docs, and every stale number, dead link and wrong command is fixed.
3. **The acceptance run is executed on a scratch database**, from `p21g-release` + this branch, by a
   person who did not write the phase.
4. **The counts are re-measured, never copied.** 42 tables, 89 settings, the suite count, the smoke
   count, the QA check count, the case floors.
5. **Every retired guard is named in the release note** (§3.7) — a reader must be able to see what
   changed in the test contract and why.
6. **The open questions are recorded, not answered quietly.** If the business decides Q1–Q4 during the
   release, the decision is written into §20 and Appendix A, and the code follows in the owning phase.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P22I-T1 | `APP_PHASE` | 22 in `config.php`, in the suite header and in `HardeningTest` |
| P22I-T2 | `BinaryReleaseTest` | The nine tags' features are present; the counts are 42/89; the inventory has the one extension |
| P22I-T3 | Vocabulary manifest | The binary words are confined to the named files; no file outside the manifest contains a binary column |
| P22I-T4 | Copy guards | The member binary tab has no upline/sponsor/"Team chain"; the tree is the only ancestor surface |
| P22I-T5 | Release job default | `--stream` defaults to all; `--stream=unilevel` is the pre-22 behaviour |
| P22I-T6 | QA pack | Sections A–H present; every `QA22X-nn` unique; the evidence index matches the files |
| P22I-T7 | Docs cold read | Every command in the docs runs; every key named in the docs exists in `settings`; every route named exists |
| P22I-T8 | Install/migration/reset | The full §2.4 gate on a fresh scratch database |
| P22I-T9 | The acceptance run | Appendix B's 12 steps pass on the scratch database; the evidence is committed |
| P22I-T10 | The full suite and smoke | Green at the recorded counts; exit 0 |

**Manual gate — `QA22I-01` … `QA22I-06`**

1. `QA22I-01` — the QA pack: execute every check in sections A–H on the scratch database; record the
   result and the evidence path for each.
2. `QA22I-02` — the acceptance run (Appendix B): the twelve steps, including the tree and the in-node
   registration.
3. `QA22I-03` — the docs copy-paste: `bin/unilevel_release.php --dry-run`, `bin/binary_audit.php`,
   `bin/binary_place_tree.php --check`, `bin/wallet_reconcile.php`, `bin/build_install_sql.php --check`.
4. `QA22I-04` — the badges and phase: the footer/sidebar phase badge reads 22; `/admin/settings` and the
   reports render with the phase.
5. `QA22I-05` — the phase log: nine entries, the counts, the deviations and the hand-off.
6. `QA22I-06` — the sign-off sheet: every box ticked by the release owner.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P22I-T1 … P22I-T10 pass
[ ] The acceptance run is 12 / 12 on the scratch database
[ ] `APP_PHASE === 22`; HardeningTest green
[ ] The QA pack is signed; the evidence is committed
[ ] All nine tags exist; the branch is merged
[ ] The plan's status line and counts are final
[ ] Tag p22i-release (feature tag `phase-22`); phase log; Appendix H filled
```

**Rollback**

Documentation and `APP_PHASE`: revert the constants and the docs. Nothing behavioural changes, so this is
the only rollback that never needs a data decision.

**Hand-off**

The feature is **released**. The next compensation stream (if any) starts from `p22i-release` and must not
reuse `binary_nodes`, `binary_volume` or `binary_awards` for a different grain. A change to the pair
economics, the cap, the flush policy or the self bonus is a new plan, not a patch to Phase 22.

**Risks**

| Risk | Mitigation |
|---|---|
| Updating `APP_PHASE` without the HardeningTest change | They are one deliverable in one commit |
| Stale counts in the docs | Rule 4: re-measure, never copy |
| A defect is papered over in 22I | Rule 1: it goes back to its owning phase |
| The QA pack is written by the implementer only | Rule 3: the acceptance run is executed by someone else |
| A retired guard is forgotten in the release note | Rule 5 and P22I-T3 |

---

# Part III — The shared contract

## 14. Specification (normative)

This part is the contract every phase is written against. Where a phase section and this part disagree,
**this part wins**; a disagreement is a plan defect to fix before the code.

### 14.1 Terms

| Term | Meaning |
|---|---|
| **Placement tree** | The binary tree of `binary_nodes`: each member has at most one upline and one side. Separate from the sponsor tree. |
| **Node** | One member's position in the placement tree. A member has exactly one node once placed. |
| **Upline** | The member directly above a node in the placement tree (`binary_nodes.upline_id`). Not the sponsor. |
| **Side** | `left` or `right` — the child slot a node occupies. |
| **Slot** | `(upline, side)`. Unique: at most one member per slot. |
| **Root** | The company account's node: no upline, no side, never paid. |
| **Placed** | A node with a non-null upline and side. **Floating** = a node with neither (a legacy or orphan tree root). **Root** = the company node. |
| **Points** | Whole-number product volume: `ROUND(products.pv)` per unit. The unit of binary matching. Never money. |
| **Order points** | `Σ points(line)` over the order's eligible lines. |
| **Available points** | A node's unmatched points on one side: `Σ binary_volume.points` where `side = …`. Signed: a reversal may make it negative (a debt), and a negative side never pairs. |
| **Lifetime points** | A node's total ever credited on one side (positive rows only). Monotonic except for reversal. |
| **Personal points** | A node's own purchases' points (the `own` rows). |
| **Pair** | `binary_pair_points` available on the left matched with the same on the right. |
| **Pair value** | `round(binary_pair_points × binary_pair_pct / 100, 2)` — the money one pair pays. |
| **Self Bonus** | `round(order_points × binary_self_pct / 100, 2)` paid to the buyer. |
| **Eligible line** | A shop order line whose product has `binary_enabled = 1`, whose `points_total > 0`, and whose order is paid while `binary_enabled` (global) is on. |
| **Eligible recipient** | A node whose member is not the company root, has `is_active = 1`, has `member_status = 'active'`, and (when `binary_requires_active`) was Active for the previous month. |
| **Award** | One `binary_awards` row: `self` or `pair`. |
| **Volume row** | One `binary_volume` row: `own`, `team`, `pair`, `reversal` or `adjustment`. |
| **Active (month M)** | The unilevel meaning: personal purchases in month M−1 ≥ `unilevel_active_threshold`, from `unilevel_activity` (or the live aggregate before the close). §2.4 of the reference. |

### 14.2 The placement tree

1. **One node per member.** `binary_nodes.user_id` is the primary key. A user without a row is **not
   placed** and earns nothing from this stream.
2. **At most two children per node.** `UNIQUE (upline_id, side)`. A node may have one left child, one
   right child, both, or neither.
3. **One root.** The company account (`Member::primaryAdmin()`) has the root node (`upline_id` and `side`
   NULL, `state = 'root'`). Every other node must be reachable from it; the backfill and the placement
   rules make this true, and `bin/binary_audit.php` reports a violation.
4. **Depth and path are derived.** `depth = upline.depth + 1`; `path = upline.path . ('L'|'R')`; the root
   has `depth = 0`, `path = ''`. The path is the **side at every ancestor**: for a node at depth `d`, the
   character at index `d−1` is the side *it* occupies under its parent, and the character at index `k` is
   the side of the child of the depth-`k` ancestor that contains it. This is what makes §14.7 a string
   read instead of a walk.
5. **The tree is a DAG of one parent, never a cycle.** `upline_id ≠ user_id` (check constraint), and a
   placement under one's own descendant is refused (§14.5 rule 6).
6. **A move** re-parents a node and its subtree, only when the subtree is empty (§14.5 rule 9), and
   recomputes `depth`/`path` for every descendant in the same transaction.
7. **The root receives volume and is never paired.** The pairing walk of §14.9 stops at the root's
   children; the root's own `left_points`/`right_points` are recorded for the tree view and for the
   audit, and no award can ever name the root as a recipient (§17.7).

### 14.3 Points

1. **A product's point value is its `pv`.** `unit_points = ROUND(pv)` — half away from zero, to a whole
   number (`pv = 250.4` → 250, `pv = 249.6` → 250, `pv = 249.5` → 250). The value is a property of the
   product; the unilevel stream uses the same P.V. as a money base, so the two streams agree on what a
   product is worth (D-05).
2. **A line's points are `unit_points × quantity`.** Integer, never rounded again, never a float.
3. **Points are snapshotted at placement** on `shop_order_items.unit_points` and `points_total`, exactly
   like `unit_base`/`base_total`. A P.V. edit after placement does not change the line.
4. **A line is eligible only when** the product's `binary_enabled = 1` at payment, the global
   `binary_enabled = 1` at payment, `points_total > 0`, and the order is paid. A product with P.V. 0 or
   the switch off places **zero** points; its line is not an error and not a warning at checkout (the
   card says which lines do not count).
5. **Points are not money and are never converted to money by a ratio other than the pair rate.** The
   self bonus and the pair value are the only two places points become pesos.
6. **A purchase with a side but no points** (all lines ineligible) writes no volume and no self, and the
   order is stamped with zeros.

### 14.4 Settings

Ten keys, all in the `commissions` settings section except the hidden baseline. Defaults ship **off** for
the master switch; everything else ships at the reference preset.

| Key | Rule | Default | Visible | Meaning |
|---|---|---|---:|---|
| `binary_enabled` | `in:0,1` | `0` | yes | The master switch. Off: no points, no awards, no offer. |
| `binary_self_pct` | `required\|numeric\|gte:0\|lte:100\|decimal:3` | `5.000` | yes | Self Bonus percentage of the order's points. `0` disables it. |
| `binary_pair_points` | `required\|integer\|gte:1\|lte:1000000` | `1000` | yes | Points per side for one pair. |
| `binary_pair_pct` | `required\|numeric\|gte:0\|lte:100\|decimal:3` | `10.000` | yes | Pair value as a percentage of the matched point value. |
| `binary_daily_pair_cap` | `required\|integer\|gte:0\|lte:1000` | `20` | yes | Maximum pairs per recipient per Asia/Manila day. `0` = unlimited. |
| `binary_requires_active` | `in:0,1` | `1` | yes | Require the monthly Active rule for a pair recipient. |
| `binary_hold_days` | `required\|integer\|gte:0\|lte:365` | `7` | yes | Days after delivery before a pending award releases. |
| `binary_release_enabled` | `in:0,1` | `1` | yes | The release job's switch for this stream. |
| `binary_reversal_enabled` | `in:0,1` | `1` | yes | Claw back **credited** awards on cancellation. Pending awards are always cancelled. |
| `binary_ledger_since` | — | migration instant | **no** | The reconciliation baseline; awards and volume before it are legacy and never paid. |

Rules: a setting is read **at payment** for eligibility and at the award's creation for the rate, and the
rate/pair size are **snapshotted on the award**; a later change affects new awards only. The cap is read
at payment. `binary_pair_points` cannot be 0 (a zero pair size would pair every purchase). The pair and
self values are **global** (D-09); a product controls only its switch and its P.V.

### 14.5 Placement rules

1. **Required at registration** for every mode (`standard`, `pending`, `code`, `ewallet`) and for every
   registrar (guest, member, admin), once the tree feature is enabled for the database.
2. **The upline must exist and be usable:** a `users` row with a node, `is_active = 1`,
   `member_status ≠ 'suspended'`, and `role = 'member'` or the company root. Staff accounts are not
   placement uplines; the root is.
3. **The side must be `left` or `right`** and the slot must be free. The unique key is the final
   arbiter; the service checks first for the friendly error.
4. **No self-placement:** a member cannot be their own upline.
5. **Scope:** a guest self-registration must place inside the **sponsor's** subtree; a signed-in member
   registering someone else must place inside **their own** subtree (the registrar's, not the sponsor's);
   an administrator may place anywhere legal. "Inside a subtree" includes the subtree's own root as the
   upline.
6. **No cycle:** the upline must not be the new member or a descendant of the placed node. Because the
   scope rules already prevent it for a new member, the check protects the move and the backfill.
7. **Depth:** `MAX_DEPTH = 100`; a placement that would exceed it is refused with a named error.
8. **The default:** the binary upline defaults to the sponsor's node (or the company root when the
   sponsor has no node); the position has **no default** — the form requires a choice.
9. **Immutability:** once any member of the node's subtree has a `binary_volume` or `binary_awards` row,
   or the node's `personal_points > 0`, the node cannot be moved. A refusal names the volume/award count.
10. **The move is admin-only**, one route, CSRF-guarded, audited with before/after. An empty node may
    move to any legal free slot that is not inside its own subtree.
11. **A floating node** (a tree root other than the company root) is legal only as a legacy state; the
    backfill places every floating node, and the audit warns while one exists.

### 14.6 The purchase event and the position

1. **The position is per order**, chosen at checkout from `left`/`right`.
2. **It is required when the cart carries points.** If `points(order) > 0` and no valid position is
   posted, the order is refused with a `binary_position` field error and **no order is created**.
3. **It is optional otherwise.** A cart with no eligible points (stream off, all products ineligible,
   member not placed) is placed with `binary_position = NULL`.
4. **It is snapshotted** on `shop_orders.binary_position` at placement; the lines snapshot their points.
   The order's position never changes after placement.
5. **Eligibility is evaluated at payment**, not at placement: the streams' switches are read when the
   order reaches `paid`, exactly like the unilevel ladder. A position chosen for a product whose switch
   is turned off before payment places nothing, and the order is stamped with zeros.
6. **A member with no node** cannot choose a position; the checkout shows the honest notice and the order
   is placed with `binary_position = NULL` and no points.

### 14.7 Volume routing

For a paid order with `points = P > 0`, a buyer `B` with node `N_B`, and the chosen side `s`:

1. **Own row:** `binary_volume` `(node = B, side = s, kind = 'own', points = +P)`.
2. **Team rows:** for every ancestor `A` of `B` (walking `upline` from `N_B.upline` to the root):
   let `k` be `A.depth` (0 for the root); the side at `A` is the character of `N_B.path` at index `k`;
   write `(node = A, side = that side, kind = 'team', points = +P)`.
3. **Bounds:** the walk stops at the root (inclusive — the root records volume and never pairs) and is
   bounded by `MAX_DEPTH`. A cycle (impossible by §14.5) would be detected and the walk stopped.
4. **One row per node per order item.** The unique key `(node_user_id, order_item_id, side, kind)`
   guarantees a replay cannot double-count.
5. **The buyer's own row is included in the buyer's node volume**, so an own-node pair is possible at the
   buyer's node (§14.9 step 1). The buyer's team rows are **not** written above the root and do not
   include the buyer's node twice.
6. **The root's rows** are written with `kind = 'team'` and never produce an award.

### 14.8 The Self Bonus

1. **Recipient:** the buyer.
2. **Base:** the order's points `P` (the sum of the eligible lines).
3. **Amount:** `round(P × binary_self_pct / 100, 2)`, one rounding, half-up, integer centavos.
4. **One per order:** `UNIQUE (order_id, buyer_id, kind = 'self')`; a replay cannot write a second.
5. **No Active gate and no membership gate beyond "the buyer has a placed node".** The buyer just bought;
   requiring last month's activity would refuse a new member's first purchase (D-07).
6. **A zero amount is not written.**
7. **Escrow:** `pending` at payment, released by 22H after delivery + `hold_days`.
8. **Reversal:** cancelled if pending; voided and clawed back if credited.
9. **The copy** states it plainly (§14.12): *"You earn on your own purchase."*

### 14.9 The pair engine

Run once per paid order, after **all** of the order's volume rows are written.

**The node list.** In this order:
1. the buyer's own node (`N_B`), if placed;
2. each ancestor of `N_B`, from the parent upward, ending with the root.

**Per node `N`:**

```
L    = N.left_points      (signed; a negative value is a debt and never pairs)
R    = N.right_points
due  = min( floor( max(L, 0) / PP ), floor( max(R, 0) / PP ) )
cap  = binary_daily_pair_cap
used = pairsToday(N.user, manilaDate(now))       // pending + credited, this stream
room = cap === 0 ? due : max(0, cap - used)
n    = min(due, room)
if N is the root                → skip (never paid)
if N is not eligible            → skip, consume nothing (D-13)
if n === 0                      → no award, no consumption
else:
    consume n × PP from left and right:
        binary_volume (N, 'left',  'pair', -n×PP, award_id, order)
        binary_volume (N, 'right', 'pair', -n×PP, award_id, order)
    perPair = round(PP × binary_pair_pct / 100, 2)
    amount  = perPair × n
    if amount > 0 → one binary_awards row:
        kind='pair', order, buyer=B, user=N, pair_count=n,
        pair_points=PP, rate_pct=binary_pair_pct, amount, status='pending',
        hold_days=binary_hold_days, paid_at=now
    else → no award row, but the points stay consumed (a deliberate 0% configuration)
    update N.pairs_paid += n, N.points_matched += 2×n×PP
```

**Rules that the implementation must not change:**

1. **Each node is independent.** Pairing at the buyer's node does not reduce any ancestor's volume
   (the ancestor's rows were written separately). This is the standard binary roll-up and the reason one
   purchase can pair at every level.
2. **Greedy, not optimal.** The engine takes the maximum whole pairs up to the cap; it never saves points
   for a larger pair later.
3. **The cap counts the day, not the order** (D-14). `pairsToday` sums `pair_count` over this stream's
   `pair` awards in `pending` or `credited` state whose `paid_at` is inside the Asia/Manila day.
4. **A skipped node consumes nothing** (D-13). Its `due` stays in its balance and will pair on the next
   paid order once the node is eligible; the member panel and the audit report the blocked count.
5. **No compression:** the next upline is evaluated on its own balances, never on the skipped node's.
6. **One award per node per order:** a 5-pair node writes one row with `pair_count = 5`.
7. **The pair rate and size are snapshotted** on the award; a later setting change does not rewrite it.
8. **No pairing outside a payment:** the engine does not run on a reversal, a release, a placement, a
   settings save or a cron.

### 14.10 Eligibility, caps and carry

1. **Eligibility** is resolved once for the chain, in one batch, before the pairing loop: company root,
   `is_active`, `member_status = 'active'`, and the Active month (`UnilevelCycle::activeMap()` over the
   previous period) when `binary_requires_active = 1`. The rules are the unilevel's
   (`UnilevelService::eligibilityMap()`), with the binary vocabulary and **no** "package" requirement.
2. **A member with no node** cannot be a recipient; the walk cannot reach one.
3. **The cap** is per member per day, shared by every order in that day, and never exceeds
   `binary_daily_pair_cap`. The cap does not consume the carried points.
4. **Carry:** unmatched points carry forever, per side. There is no flush, no expiry and no reset. The
   member page shows the carry as *available points* and the next pair's progress.
5. **A negative side** (only reachable through a reversal of volume whose points were already consumed by
   another order's pair) is a debt: `max(L, 0)` treats it as zero, and future points must refill it before
   a pair can pay. The member page shows the adjustment, never a negative "points" fantasy.
6. **A pair is never paid twice for the same points:** every consumption is a `pair` row, and the
   available balance is the ledger sum (§15.4).

### 14.11 Money, points and rounding

| Quantity | Type | Rule |
|---|---|---|
| `products.pv` | `DECIMAL(12,2)` | the product's point value (also the unilevel money base) |
| `unit_points` | `INT UNSIGNED` | `ROUND(pv)` — half away from zero |
| `points_total` | `BIGINT UNSIGNED` | `unit_points × quantity`, exact |
| `binary_points` (order) | `BIGINT NOT NULL` | `Σ points_total` of eligible lines |
| `binary_volume.points` | `BIGINT` signed | `+` own/team, `−` pair/reversal, `±` adjustment |
| `binary_pair_points` | setting, integer | the pair size |
| pair value | `DECIMAL(12,2)` | `round(pair_points × pair_pct / 100, 2)`, then `× pair_count` |
| self bonus | `DECIMAL(12,2)` | `round(order_points × self_pct / 100, 2)` |
| `binary_total` | `DECIMAL(12,2)` | `self_total + pair_total` of that order's awards |

Rules:

1. **No float ever holds a point.** Points are integers from `ROUND(pv)` to the last ledger row; a PHP
   `int` on 64-bit is exact for the whole range (`BIGINT` and the product/user limits bound it).
2. **Money is computed in centavos.** `round(x, 2)` is the only rounding; no intermediate divide is
   re-rounded (the unilevel lesson of the ₱555.55 drift).
3. **Per-pair rounding, then multiply.** `perPair = round(PP × pct / 100, 2)`; `amount = perPair × n`.
   A 3-pair pair at 7.5% of 1,000 is `75.00 × 3 = 225.00`, never `round(3000 × 7.5%)` recomputed a
   different way.
4. **The self bonus rounds once on the order total**, not per line (one award per order).
5. **A zero amount is never written** — but a 0% pair still consumes its points (the configuration is a
   deliberate no-pay pair, and the points were matched).
6. **A negative or zero base pays nothing**: no points, no self, no pair, no error.

### 14.12 Member copy rules

These are contract, not style; 22F's tests assert them.

1. **Downward only (D-22).** The Product binary earnings tab names no upline, no sponsor, no ancestor
   username, no "Team chain", no level number and no "upline". It says *your team*, *left team*,
   *right team*, *your points*, *your pairs*, *your purchases*.
2. **The Self Bonus is stated plainly:** *"You earn on your own purchase: 5% of its points."* It is never
   called a rebate, cashback, refund or discount.
3. **Points carry a unit.** Every point number is *pts*; every money number carries the currency.
4. **The pair is explained by balance, not by promise:** *"1,000 points on each side make one pair."* No
   projected income, no "up to ₱X a month".
5. **The checkout position is final and framed as a choice:** *"You place this purchase on the left or
   the right. You cannot change it after the order is placed."*
6. **The unplaced state is honest:** *"You are not placed in the binary tree yet. Your purchases will not
   place points until you are."*
7. **The stream-off state is absent, not disabled.** No dead affordances.
8. **The tree is the only place a member sees an upline**, and it shows the placement, not the income of
   anyone above them.

Appendix C is the copy deck; a string that is not in it is not shipped.

### 14.13 Divergences and non-goals

**Divergences from the reference tree implementation** (`dmp/cart-system`):

| Reference | This plan | Why |
|---|---|---|
| JSON API at `?page=api_binary_tree`, any `root` | `?view=binary&format=json` on `/genealogy`, root scoped to the viewer | D-18 (no new routes) and §17.4 (privacy) |
| `binary_parent_id`/`binary_position` on `users` | a separate `binary_nodes` table | D-01 (the sponsor tree stays untouched) |
| placement optional / self-service at activation | placement **required at registration** | the user's requirement |
| left/right **counters** (`left_count`, `right_count`) | **points** and **pairs** | this stream matches points, not head counts |
| one tree view, depth 4, no search, no live | depth 1–5, search, deep link, live, no-JS, ARIA | D-19 / §11.2 |
| the tree is the whole product (no money) | the tree is a navigation surface over a settled money stream | this is a compensation plan, not a downline viewer |

**Divergences from the unilevel stream** (Phase 17/18):

| Unilevel | Product binary | Why |
|---|---|---|
| the buyer earns nothing (D-09 of that plan) | the buyer earns the Self Bonus and an own-node pair | this stream's premise |
| percentages of a money base | integer point matching | pairs compare volume across products |
| sponsor chain | placement chain | a different parent link |
| `skipped` rows for ineligible levels | no skipped rows; volume waits | D-13 |
| a level is consumed by a skip | points are never consumed by a non-payment | points are the member's volume |
| month close decides Active | the same month close, reused | D-12 |

**Non-goals** (explicitly out of scope, each with its reason):

1. **No flush, no carry expiry** — Q6.
2. **No rank gate, no team-size gate, no personal-purchase minimum for a pair** — the only qualification
   is the Active rule and the cap (D-15).
3. **No member-facing move, no reservation, no placement auction** — D-26.
4. **No package/activation input** — a package is not a repeat purchase.
5. **No partial refunds, no order editing, no manual award creation** — the ledger is written by the
   engine only; a manual correction is an `adjustment` volume row and an audited operation if the
   business ever needs one (not shipped).
6. **No tax, no cost of goods, no company P&L report beyond the existing cards.**
7. **No second tree** (a "spillover" or "forced matrix" variant) — one placement tree, two sides.

---

## 15. Data model

### 15.1 Overview

Three new tables, four altered tables, one extended enum, ten settings.

| Object | Kind | Purpose | Writer |
|---|---|---|---|
| `binary_nodes` | new table | the placement tree and the cached point balances | `BinaryPlacementService` (structure), `BinaryService` (balances) |
| `binary_volume` | new table | the append-only, signed **points** ledger | `BinaryService` only |
| `binary_awards` | new table | the **money** ledger: `self` and `pair` awards, escrowed | `BinaryService` only |
| `products.binary_enabled` | column | per-product switch | `AdminProductController` |
| `shop_orders.binary_position` | column | the side chosen at checkout | `OrderService` at placement |
| `shop_orders.binary_processed_at`, `binary_points`, `binary_self_total`, `binary_pair_total`, `binary_total` | columns | the idempotency stamp and the order's totals | `BinaryService` |
| `shop_order_items.unit_points`, `points_total` | columns | the points snapshot | `OrderService` at placement |
| `ewallet_ledger.ref_type` | enum | `binary`, `binary_reversal` | `WalletService` via the release/reversal |
| `settings` (10 keys) | rows | the stream's configuration | `AdminSettingsController` |

**Two ledgers, one source of truth each.** `binary_volume` answers *"where did this point come from
and where did it go?"*. `binary_awards` answers *"what money did this create and where is it?"*. The
node's `left_points`/`right_points` are a **cache** of the first ledger and are rebuilt by
`bin/binary_audit.php --fix`. No money is ever derived from a cache; no point is ever derived from an
award.

### 15.2 The altered tables

```sql
-- products: the per-product switch (P.V. is already there, Phase 2/17)
ALTER TABLE products
  ADD COLUMN binary_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER unilevel_base;

-- shop_orders: the position, the stamp and the four totals
ALTER TABLE shop_orders
  ADD COLUMN binary_position ENUM('left','right') NULL AFTER unilevel_total,
  ADD COLUMN binary_processed_at DATETIME NULL AFTER binary_position,
  ADD COLUMN binary_points BIGINT NOT NULL DEFAULT 0 AFTER binary_processed_at,
  ADD COLUMN binary_self_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_points,
  ADD COLUMN binary_pair_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_self_total,
  ADD COLUMN binary_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_pair_total,
  ADD KEY idx_orders_binary (binary_processed_at),
  ADD KEY idx_orders_binary_side (binary_position, paid_at);

-- shop_order_items: the points snapshot (mirrors unit_base/base_total of Phase 17)
ALTER TABLE shop_order_items
  ADD COLUMN unit_points INT UNSIGNED NOT NULL DEFAULT 0 AFTER base_total,
  ADD COLUMN points_total BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_points;

-- ewallet_ledger: two movement kinds (15 values in total)
ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
  ENUM('commission','payout','transfer','topup','registration',
       'shop_order','adjustment','refund','unilevel','unilevel_reversal',
       'royalty','team_harvest','general_harvest','binary','binary_reversal') NOT NULL;
```

> The enum above is written with Phase 19's two values included. Migration 026 must read the **live**
> enum and append, exactly as the Phase 17 and 19 migrations did (an `information_schema` probe on
> `COLUMN_TYPE LIKE '%binary%'` guards it), so a database that skipped Royalty still ends with a valid
> enum rather than a truncated one. The plan's §15.4 text is the canonical list; the migration's
> `MODIFY` is generated only when the probe says the values are missing.

**Why these columns and no others**

- No `binary_parent_id`/`binary_position` on `users` (D-01) — the tree is its own table.
- No `binary_points` on `products` (D-05) — the P.V. is the point value.
- No `pair_rate` on `shop_order_items` — points are volume, not an order line property; the pair value is
  snapshotted on the award that consumed them.
- No `binary_side` on the item — the order owns the position (D-06); the line owns the points.
- No `sponsor_id` copy on the node — the sponsor tree is read through `users` when needed.

### 15.3 `binary_nodes`

| Column | Type | Meaning |
|---|---|---|
| `user_id` | `INT UNSIGNED PK` | the member |
| `upline_id` | `INT UNSIGNED NULL` | the parent node's member; NULL only for a tree root |
| `side` | `ENUM('left','right') NULL` | the slot under the upline; NULL only for a tree root |
| `root_id` | `INT UNSIGNED NULL` | the member at the top of this node's tree (the company account for the main tree; a root's own id for a floating tree) |
| `depth` | `SMALLINT UNSIGNED` | derived; root 0 |
| `path` | `VARCHAR(255)` | derived `L`/`R` string; root `''` |
| `state` | `ENUM('root','placed','floating')` | `root` = company; `placed` = attached to a tree; `floating` = legacy root not yet attached |
| `left_points`, `right_points` | `BIGINT` signed | **available** points per side (the ledger sum) |
| `left_credited`, `right_credited` | `BIGINT` | lifetime credited per side (positive rows only) |
| `points_matched` | `BIGINT` | lifetime points consumed by paid pairs (both sides counted) |
| `pairs_paid` | `INT UNSIGNED` | lifetime pair count |
| `personal_points` | `BIGINT` | lifetime own-purchase points |
| `placed_at`, `placed_by` | `DATETIME`, `INT UNSIGNED` | who placed it and when |
| `moved_at`, `moved_by`, `move_count` | `DATETIME`, `INT UNSIGNED`, `SMALLINT UNSIGNED` | the move audit |
| `created_at`, `updated_at` | `TIMESTAMP` | standard |

**Constraints**

- `PRIMARY KEY (user_id)`, `UNIQUE KEY uq_binary_slot (upline_id, side)`,
  `KEY idx_binary_upline (upline_id)`, `KEY idx_binary_path (path(24))`,
  `KEY idx_binary_root (root_id)`, `KEY idx_binary_state (state)`.
- `fk_bn_user (user_id → users.id) ON DELETE CASCADE`.
- `fk_bn_upline (upline_id → binary_nodes.user_id) ON DELETE CASCADE` — a node delete removes its
  subtree (no orphans, and the reset empties the table with one statement). A member with a downline
  cannot be removed by deleting the *user*: the cascade would orphan the downline, so the app never
  deletes a member (status changes only).
- `fk_bn_root (root_id → users.id) ON DELETE SET NULL`.
- `CHECK ((upline_id IS NULL) = (side IS NULL))` — a node is either fully placed or fully root.
- `CHECK (upline_id IS NULL OR upline_id <> user_id)` — no self-parent.
- `CHECK (depth BETWEEN 0 AND 100)`.

### 15.4 The migration (canonical text)

`system/database/migrations/026_product_binary.sql`. The guards are the house style; the `DO 0` branches
make a second run a no-op.

```sql
-- =============================================================
--  Phase 22 migration — Product binary bonus
--
--  Applies to a Phase 21 database and adds:
--
--    binary_nodes     the placement tree and the cached point balances
--    binary_volume    the append-only, signed points ledger
--    binary_awards    the self/pair money ledger (escrowed)
--
--    products.binary_enabled
--    shop_orders.binary_position / binary_processed_at / binary_points
--                / binary_self_total / binary_pair_total / binary_total
--    shop_order_items.unit_points / points_total
--    ewallet_ledger.ref_type  + 'binary', 'binary_reversal'
--
--    ten settings keys (the master switch is off by default)
--
--  No backfill: orders paid before this migration are never awarded
--  and never place points. `binary_ledger_since` marks the instant the
--  stream may pay, so BinaryService::reconcile() can separate legacy
--  rows. Placement is backfilled by bin/binary_place_tree.php (Phase
--  22C), never by this file.
--
--      mysql -u root <db> < database/migrations/026_product_binary.sql
--
--  No `down` note: the tables and columns are additive. Dropping them
--  would erase the record of every point and every award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. products -----------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'binary_enabled');
SET @sql := IF(@has = 0,
  'ALTER TABLE products ADD COLUMN binary_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER unilevel_base',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. shop_order_items --------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'unit_points');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN unit_points INT UNSIGNED NOT NULL DEFAULT 0 AFTER base_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'points_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN points_total BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_points',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. shop_orders --------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_position');
SET @sql := IF(@has = 0,
  "ALTER TABLE shop_orders ADD COLUMN binary_position ENUM('left','right') NULL AFTER unilevel_total",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_processed_at');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_processed_at DATETIME NULL AFTER binary_position',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_points');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_points BIGINT NOT NULL DEFAULT 0 AFTER binary_processed_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_self_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_self_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_points',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_pair_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_pair_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_self_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_pair_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_binary');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_binary (binary_processed_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_binary_side');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_binary_side (binary_position, paid_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. binary_nodes ------------------------------------------------
CREATE TABLE IF NOT EXISTS binary_nodes (
  user_id        INT UNSIGNED     NOT NULL,
  upline_id      INT UNSIGNED     NULL,
  side           ENUM('left','right') NULL,
  root_id        INT UNSIGNED     NULL,
  depth          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  path           VARCHAR(255)     NOT NULL DEFAULT '',
  state          ENUM('root','placed','floating') NOT NULL DEFAULT 'floating',
  left_points    BIGINT           NOT NULL DEFAULT 0,
  right_points   BIGINT           NOT NULL DEFAULT 0,
  left_credited  BIGINT           NOT NULL DEFAULT 0,
  right_credited BIGINT           NOT NULL DEFAULT 0,
  points_matched BIGINT           NOT NULL DEFAULT 0,
  pairs_paid     INT UNSIGNED     NOT NULL DEFAULT 0,
  personal_points BIGINT          NOT NULL DEFAULT 0,
  placed_at      DATETIME         NULL,
  placed_by      INT UNSIGNED     NULL,
  moved_at       DATETIME         NULL,
  moved_by       INT UNSIGNED     NULL,
  move_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_binary_slot (upline_id, side),
  KEY idx_binary_upline (upline_id),
  KEY idx_binary_path (path(24)),
  KEY idx_binary_root (root_id),
  KEY idx_binary_state (state),
  CONSTRAINT fk_bn_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_bn_upline FOREIGN KEY (upline_id) REFERENCES binary_nodes (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_bn_root   FOREIGN KEY (root_id)   REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_bn_slot  CHECK ((upline_id IS NULL) = (side IS NULL)),
  CONSTRAINT chk_bn_self  CHECK (upline_id IS NULL OR upline_id <> user_id),
  CONSTRAINT chk_bn_depth CHECK (depth BETWEEN 0 AND 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. binary_volume -----------------------------------------------
CREATE TABLE IF NOT EXISTS binary_volume (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  node_user_id  INT UNSIGNED    NOT NULL,
  side          ENUM('left','right') NOT NULL,
  kind          ENUM('own','team','pair','pair_return','reversal','adjustment') NOT NULL,
  points        BIGINT          NOT NULL,
  order_id      INT UNSIGNED    NULL,
  order_item_id INT UNSIGNED    NULL,
  buyer_id      INT UNSIGNED    NULL,
  award_id      BIGINT UNSIGNED NULL,
  note          VARCHAR(160)    NULL,
  created_by    INT UNSIGNED    NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bv_line (node_user_id, order_item_id, side, kind),
  UNIQUE KEY uq_bv_award (award_id, side, kind),
  KEY idx_bv_node (node_user_id, side, created_at),
  KEY idx_bv_order (order_id),
  KEY idx_bv_buyer (buyer_id, created_at),
  KEY idx_bv_kind (kind, created_at),
  KEY idx_bv_award (award_id),
  CONSTRAINT fk_bv_node  FOREIGN KEY (node_user_id)  REFERENCES users (id)         ON DELETE CASCADE,
  CONSTRAINT fk_bv_buyer FOREIGN KEY (buyer_id)      REFERENCES users (id)         ON DELETE SET NULL,
  CONSTRAINT fk_bv_order FOREIGN KEY (order_id)      REFERENCES shop_orders (id)   ON DELETE CASCADE,
  CONSTRAINT fk_bv_item  FOREIGN KEY (order_item_id) REFERENCES shop_order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_bv_actor FOREIGN KEY (created_by)    REFERENCES users (id)         ON DELETE SET NULL,
  CONSTRAINT chk_bv_points CHECK (points <> 0),
  CONSTRAINT chk_bv_own    CHECK (kind <> 'own'    OR (points > 0 AND order_item_id IS NOT NULL AND award_id IS NULL)),
  CONSTRAINT chk_bv_team   CHECK (kind <> 'team'   OR (points > 0 AND order_item_id IS NOT NULL AND award_id IS NULL)),
  CONSTRAINT chk_bv_rev    CHECK (kind <> 'reversal' OR (points < 0 AND order_item_id IS NOT NULL)),
  CONSTRAINT chk_bv_pair   CHECK (kind <> 'pair'   OR (points < 0 AND award_id IS NOT NULL)),
  CONSTRAINT chk_bv_return CHECK (kind <> 'pair_return' OR (points > 0 AND award_id IS NOT NULL)),
  CONSTRAINT chk_bv_adj    CHECK (kind <> 'adjustment' OR points <> 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 6. binary_awards -----------------------------------------------
CREATE TABLE IF NOT EXISTS binary_awards (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind               ENUM('self','pair') NOT NULL,
  order_id           INT UNSIGNED    NOT NULL,
  buyer_id           INT UNSIGNED    NOT NULL,
  user_id            INT UNSIGNED    NOT NULL,
  side               ENUM('left','right') NULL,
  points             BIGINT          NOT NULL DEFAULT 0,
  pair_count         INT UNSIGNED    NOT NULL DEFAULT 0,
  pair_points        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rate_pct           DECIMAL(6,3)    NOT NULL DEFAULT 0.000,
  amount             DECIMAL(12,2)   NOT NULL,
  status             ENUM('pending','credited','voided','cancelled') NOT NULL DEFAULT 'pending',
  hold_days          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ledger_id          INT UNSIGNED    NULL,
  credited_at        DATETIME        NULL,
  reversal_ledger_id INT UNSIGNED    NULL,
  reversed_amount    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  paid_at            DATETIME        NOT NULL,
  voided_at          DATETIME        NULL,
  void_reason        VARCHAR(160)    NULL,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_binary_award (order_id, user_id, kind),
  KEY idx_ba_user (user_id, created_at),
  KEY idx_ba_buyer (buyer_id, created_at),
  KEY idx_ba_order (order_id),
  KEY idx_ba_status (status, created_at),
  KEY idx_ba_user_status (user_id, status),
  KEY idx_ba_paid (paid_at),
  KEY idx_ba_ledger (ledger_id),
  CONSTRAINT fk_ba_order  FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_buyer  FOREIGN KEY (buyer_id) REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_ba_user   FOREIGN KEY (user_id)  REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT chk_ba_amount CHECK (amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= amount),
  CONSTRAINT chk_ba_self   CHECK (kind <> 'self' OR (pair_count = 0 AND points > 0)),
  CONSTRAINT chk_ba_pair   CHECK (kind <> 'pair' OR (pair_count > 0 AND points = 2 * pair_count * pair_points))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 6b. the volume → award link (binary_awards now exists) ---------
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume'
    AND CONSTRAINT_NAME = 'fk_bv_award');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume
     ADD CONSTRAINT fk_bv_award FOREIGN KEY (award_id) REFERENCES binary_awards (id) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. ewallet_ledger.ref_type -------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type' AND COLUMN_TYPE LIKE '%binary%');
SET @sql := IF(@has = 0,
  'ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM(''commission'',''payout'',''transfer'',''topup'',''registration'',
          ''shop_order'',''adjustment'',''refund'',''unilevel'',''unilevel_reversal'',
          ''royalty'',''team_harvest'',''general_harvest'',''binary'',''binary_reversal'') NOT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. settings ----------------------------------------------------
INSERT INTO settings (key_name, value) VALUES
  ('binary_enabled',           '0'),
  ('binary_self_pct',          '5.000'),
  ('binary_pair_points',       '1000'),
  ('binary_pair_pct',          '10.000'),
  ('binary_daily_pair_cap',    '20'),
  ('binary_requires_active',   '1'),
  ('binary_hold_days',         '7'),
  ('binary_release_enabled',   '1'),
  ('binary_reversal_enabled',  '1'),
  ('binary_ledger_since',      DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE key_name = key_name;
```

> `binary_volume` is declared before `binary_awards` only for readability, so its `award_id` FK is added
> by the small `ALTER` of §6b after `binary_awards` exists. The shipped file therefore has a valid
> create order (`binary_nodes`, `binary_volume`, `binary_awards`, the FK, the enum, the settings) and
> `schema.sql` uses the same order plus the same constraint.

### 15.5 `schema.sql`, the seed and the install bundle

- **`schema.sql`** mirrors §15.4 block for block and in the same order (`binary_nodes`, `binary_awards`,
  `binary_volume` after `unilevel_awards`/`shop_order_items`; the product and order columns in their
  tables). `MigrationTest` compares the two structures; a column in one and not the other fails 22A.
- **`seeds/001_settings.sql`** carries the ten keys in the migration's order with the same defaults;
  `SeedParityTest` fails otherwise.
- **`install/footer.sql`**'s expected counts become **42 tables · 89 settings**.
- **`install.sql`** is regenerated, never hand-edited.
- **`seeds/005_demo_tree.sql`** (22C) adds the six demo nodes; it is idempotent and only fills empty
  slots for the demo usernames.

### 15.6 Invariants

These are what the tests and the audit check. They are stated once here and cited everywhere.

| # | Invariant | Checked by |
|---|---|---|
| **INV-1** | Every `binary_volume` row has a non-zero `points` and a kind whose sign is correct (§15.4's checks) | the schema, `binary_audit.php` |
| **INV-2** | For every node and side: `left_points = Σ binary_volume.points` where `side = 'left'` (and the same for right) | `BinaryService::audit()`, P22D-T49 |
| **INV-3** | For every node and side: `left_credited = Σ points` of the positive `own`/`team` rows (and the same for right) | the audit |
| **INV-4** | For every node: `points_matched = Σ (pair_count × pair_points × 2)` of its live (not cancelled/voided) pair awards | the audit, P22H-T11 |
| **INV-5** | For every pair award: a `pair` volume row exists on each side with `points = −pair_count × pair_points`; a cancelled/voided pair has its matching `pair_return` rows | the audit, P22H-T11 |
| **INV-6** | For every self award: `points = shop_orders.binary_points` and `amount = round(points × rate_pct / 100, 2)` | the audit |
| **INV-7** | For every pair award: `amount = round(pair_points × rate_pct / 100, 2) × pair_count` | the audit |
| **INV-8** | `shop_orders.binary_total = binary_self_total + binary_pair_total`, and each equals the sum of that order's awards of that kind (live awards) | the audit, the order card |
| **INV-9** | No award row has the company root as `user_id` | a test in every phase that writes awards, and the audit |
| **INV-10** | `UNIQUE (order_id, user_id, kind)` on awards; `UNIQUE (node, item, side, kind)` and `UNIQUE (award, side, kind)` on volume | the schema, P22A-T13/T17 |
| **INV-11** | `path` and `depth` match the parent's (`depth = parent + 1`, `path = parent.path . side-letter`) for every placed node | the audit, P22H-T13 |
| **INV-12** | Exactly one `state = 'root'` node and it is the company account; no `state = 'root'` anywhere else | the audit, the backfill `--check` |
| **INV-13** | `users` carries no `binary_*`/`pairs_*`/`left_count`/`right_count` column | `BinaryFoundationTest` (kept from P9-T17) |
| **INV-14** | The binary vocabulary appears only in the named files (the manifest below) | `BinaryFoundationTest` |
| **INV-15** | `binary_pair_points ≥ 1`, `0 ≤ binary_self_pct ≤ 100`, `0 ≤ binary_pair_pct ≤ 100` | the settings validator, P22B-T7/T8 |

**The vocabulary manifest** (INV-14). The scan looks for `binary_parent_id`, `binary_position`,
`binary_upline`, `binary_tree`, `pairs_paid`, `left_count`, `right_count`, `binary_nodes`,
`binary_volume`, `binary_awards`, `binary_enabled`, `binary_pair_`, `binary_self_`, `binary_hold_`,
`binary_release_`, `binary_reversal_`, `binary_processed_at`, `binary_points`, `binary_total`,
`binary_ledger_since`, `binary_daily_pair_cap`, `binary_requires_active`. The files allowed to contain
them are exactly:

```
app/Models/BinaryNode.php              app/Services/BinaryService.php
app/Models/BinaryVolume.php            app/Services/BinaryPlacementService.php
app/Models/BinaryAward.php             app/Services/BinaryTreeService.php
app/Services/BinaryException.php       app/Services/HealthService.php
app/Controllers/AdminCommissionController.php
app/Controllers/AdminMemberController.php
app/Controllers/AdminProductController.php
app/Controllers/AdminReportController.php
app/Controllers/AdminSettingsController.php
app/Controllers/CheckoutController.php
app/Controllers/DashboardController.php
app/Controllers/EarningsController.php
app/Controllers/GenealogyController.php
app/Controllers/OrderController.php
app/Controllers/ShopController.php
app/Services/DatabaseInstaller.php
app/Services/DatabaseResetService.php
app/Services/Live.php
app/Services/OrderService.php
app/Services/PackageBinaryException.php
app/Services/PackageBinaryService.php
app/Services/PaymentService.php
app/Services/RegistrationService.php
app/Services/ReportService.php
app/Models/Activation.php
app/Models/Product.php
app/Models/Package.php
app/Models/Report.php
app/Services/ActivationService.php
app/Controllers/AdminActivationController.php
app/Controllers/AdminPackageController.php
app/Controllers/AdminCommissionController.php
app/Controllers/AdminMemberController.php
app/Controllers/AdminProductController.php
app/Controllers/AdminReportController.php
app/Controllers/AdminSettingsController.php
app/Controllers/CheckoutController.php
app/Controllers/DashboardController.php
app/Controllers/EarningsController.php
app/Controllers/GenealogyController.php
app/Controllers/MembershipController.php
app/Controllers/OrderController.php
app/Controllers/ShopController.php
app/helpers.php                        (wallet labels)
app/layout/nav.php                     (the tree link, if added)
public/register.php
app/views/**/_binary*.php              (the binary partials only)
app/views/admin/activations/_binary*.php   (the activation binary card)
app/views/admin/packages/_binary*.php      (the package binary card)
app/views/member/membership/_placement*.php (the activation placement block)
app/views/member/genealogy/_tree*.php  (the tree and the modal)
app/routes_inventory.php               (the one extension row)
```

A file that needs a binary word and is not on this list is a **plan amendment**: add it to §15.6 first.
The point of the list is not secrecy; it is that a reviewer can see, in one screen, every place the
stream touches the application.

### 15.7 Reset and install behaviour

- **`DatabaseInstaller::EXPECTED`**: `tables_created` 42, `settings_rows` 89.
  `TRANSACTIONAL_TABLES` gains `binary_awards`, `binary_volume` (non-empty on a fresh install is a
  failure). `binary_nodes` is not transactional: the root is legitimate. `DEMO_TABLES` unchanged.
- **`DatabaseResetService::ALL_TABLES`**: 42 names, including the three new ones.
- **`ZERO_TABLES`**: `binary_volume`, `binary_awards`, `binary_nodes` (all emptied by the business reset).
- **`AUTO_INCREMENT_TABLES`**: `binary_volume`, `binary_awards` (no auto-increment on `binary_nodes`).
- **`DELETE_GROUPS`** gains, in this order and before the orders group and the members group:
  *Product binary points* (`binary_volume`), *Product binary awards* (`binary_awards`), *Product binary
  tree* (`binary_nodes`). Order matters: volume references awards, so it goes first; nodes go last. The
  reset never disables FK checks (D-IR-08).
- **The integrity map** gains `binary` → `BinaryService` in 22D (not 22A, because the class does not
  exist yet).
- **The demo reset** re-runs `005_demo_tree.sql`, which now places the demo members (22C). A production
  reset leaves `binary_nodes` empty; the root is created lazily by the next registration, placement or
  backfill.
- **A fresh production install** therefore reports 42 tables / 89 settings and zero binary rows; a demo
  install reports six nodes and zero volume/awards.
- **`bin/build_install_sql.php --check`** must exit 0 after 22A; the bundle size grows by the three
  tables and the ten settings, and the footer's counts change.

### 15.8 Counts

| At tag | Tables | Settings | Migrations | Cases | Smoke |
|---|---:|---:|---|---:|---:|
| `p21g-release` (baseline) | 39 | 79 | 001–025 | 1,484 | ≈278 |
| `p22a-foundation` | **42** | **89** | 001–**026** | ≥1,506 | ≈278 |
| `p22b-configuration` | 42 | 89 | 001–026 | ≥1,530 | ≈280 |
| `p22c-placement` | 42 | 89 | 001–026 | ≥1,576 | ≈284 |
| `p22d-money` | 42 | 89 | 001–026 | ≥1,650 | ≈288 |
| `p22e-admin` | 42 | 89 | 001–026 | ≥1,668 | ≈290 |
| `p22f-member` | 42 | 89 | 001–026 | ≥1,694 | ≈293 |
| `p22g-tree` | 42 | 89 | 001–026 | ≥1,722 | ≈298 |
| `p22h-operations` | 42 | 89 | 001–026 | ≥1,734 | ≈300 |
| `p22i-release` | 42 | 89 | 001–026 | 1,734 | ≈300 |

If the Phase 21 release publishes different baseline counts, §2.6 applies: record the real numbers and
correct this table before 22A.

---

## 16. The money path

### 16.1 The event map

| Event | Code | What this stream does |
|---|---|---|
| Member submits `/checkout` (e-wallet) | `OrderService::placeFromCart()` | snapshots points, requires the position, defers payment; when it pays in the same transaction, calls `BinaryService::onOrderPaid()` after `UnilevelService::onOrderPaid()` |
| Member submits `/checkout` (manual) | `OrderService::placeFromCart()` | snapshots points and position, order stays `pending`; no awards |
| Admin verifies a manual payment | `PaymentService::verify()` | calls `onOrderPaid()` after the unilevel hook |
| Admin cancels a paid order | `PaymentService::cancelByAdmin()` | calls `reverseForOrder()` after the unilevel reversal and before the status update |
| Member cancels an unpaid order | `OrderService::cancelByMember()` | nothing to reverse; the order was never stamped |
| Order delivered / completed | `FulfilmentService` | no money; it starts the release clock |
| Release job | `bin/unilevel_release.php` (22H) | `releaseForOrder()` per due order: pending → credited + wallet |
| Month close | `bin/unilevel_month_close.php` | no binary money; it decides Active for the next month |
| Placement / move / backfill | `BinaryPlacementService` | no money; bumps the tree surface |
| Settings save | `AdminSettingsController` | no money; audited |

**The order inside an event** (both payment paths): lock the order, verify the state, validate the
position (placement only), write the line snapshots (placement only), then at payment: stamp → self →
volume → pairs → totals, all in the caller's transaction.

### 16.2 The engine API

```php
final class BinaryService
{
    public function onOrderPaid(int $orderId, ?PDO $pdo = null): array;
    // → ['order_id','processed','replayed','points','self','pair','pair_count',
    //     'awards'=>list, 'nodes'=>int, 'reason'?]

    public function quoteCart(array $lines, ?PDO $pdo = null): array;
    // → ['eligible'=>bool, 'points'=>int, 'lines'=>list, 'self'=>float,
    //     'sides'=>['left'=>['pairs'=>int,'amount'=>float,'left'=>int,'right'=>int],
    //               'right'=>[...]]]

    public function quotePosition(array $lines, string $side, ?PDO $pdo = null): array;
    public function reverseForOrder(int $orderId, array $options = []): array;
    public function releaseForOrder(int $orderId, bool $force = false, ?int $actorId = null): array;
    public function releaseDue(int $limit = 200): array;
    public function dueOrderIds(int $limit = 200): array;
    public function totalsFor(int $userId): array;
    public function reconcile(): array;      // money: awards vs wallet
    public function audit(): array;          // points: caches vs ledger
    public function auditFix(): array;
    public static function enabled(): bool;
    public static function holdDays(): int;
    public static function pairValue(): float;
    public static function linePoints(array $product, int $quantity): array;
}
```

`quoteCart()` and `quotePosition()` are read-only and never write. `totalsFor()` is per member.
`reconcile()` and `audit()` are read-only.

### 16.3 The payment sequence (normative)

```
1. lock the order row (SELECT … FOR UPDATE) and check status = paid; if binary_processed_at is set → replay
2. collect the eligible lines: binary_enabled (product, global), points_total > 0, product still exists
3. if no eligible line or no position or no node:
       stamp binary_processed_at = NOW(), binary_points = 0, all totals 0
       return processed, reason = 'nothing to place'
4. lock the buyer's node and every ancestor, ascending user_id
5. lock the nodes that will be written (same set) — one SELECT … FOR UPDATE IN (…) ORDER BY user_id
6. write one 'own' row at the buyer's node (chosen side)
7. write one 'team' row at every ancestor (side from the path)
8. self = round(points × self_pct / 100, 2); if > 0 → insert the self award (pending, `side` = the
   order's position)
9. for each node in [buyer, ancestors…]:
       elig = eligibility(node)  // company root, is_active, member_status, Active month
       n = min(due, cap room); if n = 0 → continue
       insert the pair award (pending)
       insert the two 'pair' volume rows (negative, award_id)
       update the node cache
10. update shop_orders: binary_position (already), binary_points, binary_self_total, binary_pair_total,
    binary_total, binary_processed_at = NOW()
11. touch the member/earnings/reports live surfaces
```

**Point 3 is a decision, not a shortcut.** "Decided, nothing due" must be recorded; otherwise a later
re-verification could pay an order the operator saw as unbillable.

### 16.4 Idempotency layers

1. **The order stamp** (`binary_processed_at`): the first check; a non-null stamp is a replay.
2. **The order row lock**: two concurrent verifications serialise on the row, so the stamp is read once.
3. **The unique keys**: `binary_awards (order_id, user_id, kind)` and `binary_volume
   (node_user_id, order_item_id, side, kind)` / `(award_id, side, kind)` make a duplicate impossible even
   if the first two layers are bypassed by a repair script.
4. **The release transition**: `markCredited()` updates only `WHERE status = 'pending'`; a second
   release finds nothing to credit, and the wallet's own idempotency (`WalletService::credit()` with
   `ref_type`/`ref_id`) is the fourth backstop.

### 16.5 Transactions

- The engine **never** opens its own transaction. It runs inside the caller's
  (`OrderService::placeFromCart()`'s or `PaymentService::verify()`'s) and inside the release job's
  per-order transaction.
- Every write in §16.3 is one unit: a failure anywhere rolls back the whole order's binary work **and**
  the caller's payment transition. A partially paid order is never possible.
- `releaseForOrder()` and `reverseForOrder()` are each one transaction of their own (they are not inside
  a payment).
- A batch (`releaseDue`) is one transaction **per order**, so one bad order cannot hold back the batch.

### 16.6 Locking and deadlock avoidance

The canonical order is:

1. `shop_orders` row (the idempotency anchor),
2. the buyer's node and ancestors, **ascending `user_id`**,
3. any wallet rows the operation credits, **ascending `user_id`** (release/reversal only).

Two orders that share an upline lock the shared node in the same ascending order, so they queue rather
than deadlock. A deadlock is still possible in theory (a third operation taking the locks in another
order); the caller's transaction is small and the job retries once on SQLSTATE 40001 with a logged note.
The plan does **not** add retries to the payment path: a deadlock there surfaces as a failed checkout,
which is correct (the member retries and no money moved).

### 16.7 Eligibility and the company root

- Resolved once for the whole chain, in one batch, before the loop: `is_active = 1`,
  `member_status = 'active'`, not the company account, and (when `binary_requires_active`) the Active
  month from `UnilevelCycle::activeMap()` for the previous period.
- **The company root is never eligible.** The check is explicit and has its own reason string; the loop
  skips it before `due` is even considered.
- **The buyer's own node** is a recipient like any other: the buyer must be eligible (an inactive buyer
  still gets the self bonus — D-07 — but not an own-node pair).
- A node that is not eligible keeps its volume (§14.9 rule 4) and is reported in
  `blockedPairs()`/the member panel.

### 16.8 Release

1. `dueOrderIds()` selects orders with a pending binary award whose order is `delivered`/`completed`,
   not cancelled, and whose `delivered_at + hold_days <= NOW()`.
2. `releaseForOrder()` locks the order, re-checks the state, locks the pending awards, sorts them by
   ascending recipient then award id, and credits each with
   `WalletService::credit($user, $amount, 'binary', $awardId, $note, true)`.
3. `markCredited()` transitions `pending → credited` and stores `ledger_id`/`credited_at`.
4. A failure throws; the order's transaction rolls back, so a wallet credit and an award status can never
   disagree.
5. The hold is **per award** (snapshotted at payment). A settings change affects new awards only.
6. A cancelled order never releases; a release never resurrects a cancelled award.

### 16.9 Reversal

Called by `PaymentService::cancelByAdmin()` after the unilevel reversal. Order of operations, all in the
caller's transaction:

1. **Lock** the order and every node the order touched (ascending ids).
2. **Awards:**
   - `pending` → `cancelled`; if it is a `pair`, return its consumed points with `pair_return` rows
     (`+pair_count × pair_points` on each side, `award_id` set) and update the node caches.
   - `credited` and `binary_reversal_enabled = 1` → `voided` with a best-effort clawback
     (`WalletService::debit()` up to the withdrawable balance, `ref_type = 'binary_reversal'`),
     `reversed_amount` recorded, `pair_return` rows written.
   - `credited` and `binary_reversal_enabled = 0` → stays `credited`; **no clawback**; the `pair_return`
     rows are still written (the points must not survive a cancelled purchase).
3. **Volume:** for every `own`/`team` row of the order, write its `reversal` row
   (`−points`, same node/side/item, `kind = 'reversal'`); update the node caches (including
   `personal_points` for the buyer).
4. **Order:** recompute `binary_self_total`, `binary_pair_total`, `binary_total` from the live awards
   (`cancelled`/`voided` excluded) and store them.
5. **No pairing.** The returned points stay available; the next paid order pairs them.
6. A second call finds no live award and no unreversed row: a no-op, never a double return.

A negative side can result when the cancelled order's points had already been consumed by **another**
order's pair. It is stored as a debt (§14.10 rule 5), reported by the audit and shown on the member page
as an adjustment — never hidden and never paid.

### 16.10 Reconciliation and audit

Two different proofs, two different questions:

| Tool | Question | Reads | Writes |
|---|---|---|---|
| `bin/wallet_reconcile.php` (Product binary section) | Does every released award have exactly one wallet credit, and does the total match? | `binary_awards`, `ewallet_ledger` | nothing |
| `bin/binary_audit.php` | Does every cached balance equal the ledger, does every pair have its consumption rows, does every award's amount match its formula, is the tree well-formed? | `binary_nodes`, `binary_volume`, `binary_awards`, `shop_orders`, `shop_order_items` | `--fix` only rewrites `binary_nodes` caches |

`BinaryService::reconcile()` is the money proof (it wraps the wallet comparison for the CLI), and
`BinaryService::audit()` is the points proof. A release is **not** complete if either fails.

### 16.11 What the engine never does

- Never runs on an unpaid order, a placement, a settings save, a cron or a page render.
- Never credits a wallet at payment (escrow, D-11).
- Never pays the company root or a staff account.
- Never consumes points for a skipped/ineligible node.
- Never re-pairs during a reversal.
- Never reads a point balance from the request (the client's points are display only).
- Never trusts a client-supplied position without the enum and the rules check.
- Never writes a `0.00` award.
- Never deletes an award or a volume row (only status transitions and negative rows).

---

## 17. Security, audit and abuse control

### 17.1 The threat model in one page

The stream pays real money to members who control the tree, the purchases and the registrations. The
attacker's three levers are **the tree** (who is under whom), **the points** (what a purchase is worth)
and **the identity** (how many accounts exist). Every control below maps to one of those levers.

| Lever | Attack | Control | Phase |
|---|---|---|---|
| **The tree** | Place your own accounts under a victim to capture their volume | Placement is written only by `BinaryPlacementService`, only into your own/sponsor's subtree, with an audit row | 22C |
| | Re-parent a productive leg under yourself | Immutability once the subtree has volume/awards (D-04); the move route refuses | 22C |
| | Fill a slot to block a rival | No reservation; the unique key is the only claim; an admin can move an **empty** node | 22C/22G |
| | Cycle the tree to loop volume | Self-parent check, subtree-scope check, `isAncestor()` in the same transaction, the audit's INV-11 | 22C |
| **The points** | Inflate P.V. to inflate pairs | P.V. is an admin field with a 1,000,000 ceiling and an audit row per change; the readiness card shows P.V. 0 products | 22B |
| | Match your own points with your own account | The buyer's own node pairs only against **team** volume; a second account's volume is real money paid to the company | 22D |
| | Split orders to bypass the cap | The cap is per recipient per day, not per order | 22D |
| | Reuse points after a reversal | Reversal writes signed ledger rows; the balance is the sum; the audit proves it | 22D/22H |
| | Point the engine at a forged balance | The engine reads the locked node rows; the client never sends points | 22D |
| **The identity** | Register fake accounts to build volume | A purchase is real money; at 5% self and 10% per pair, a fake purchase costs more than it earns on its own — the exploit only pays if it also captures **other** members' volume, which the placement scope prevents | 22C/22D |
| | Register under a stranger to steal their downline | Scope + admin override + audit | 22C |
| **The money** | Double credit on a raced verification | Four idempotency layers (§16.4) | 22D |
| | Pay a cancelled order | The release re-checks the order state; the cancellation cancels pending awards | 22D/22H |
| | Negative balance | Every debit is bounded by the withdrawable balance; no negative is possible | 22D |
| **The surface** | A member reads another's tree | Server-side root/search scope | 22G |
| | CSRF on the move/register | The router's CSRF guard on every POST; the modal carries the token | 22C/22G |
| | XSS through a username in the tree | Every node string is escaped (`e()`) and the JSON is encoded with `JSON_HEX_TAG｜JSON_HEX_AMP` | 22G |

### 17.2 Placement abuse

- **The scope rule is the main defence** (§14.5 rule 5): you can only place inside your own (or your
  sponsor's) subtree. Cross-tree placement is an admin act, audited.
- **The immutability rule** is the second: once money exists, the tree cannot change. The window in which
  a member could re-shape the tree is exactly the window in which it pays nothing.
- **The backfill** is deterministic and only fills empty slots; it never moves a placed node.
- **The demo seed** only places demo usernames into empty slots and reports what it skipped.
- **Every placement write is audited**: create, backfill, move, with before/after.

### 17.3 Self-dealing

Self-dealing is **economically bounded**, not forbidden:

- A member buying their own product gets 5% of its points plus at most the pairs their own team's
  opposite side supports. The company keeps the sale's margin; the plan's self bonus is a marketing
  cost, not a loophole.
- A member cannot place a purchase on **both** sides: one side per order (D-06).
- A member's own node pairs only against **other members'** volume on the other side, never against the
  same order's volume.
- The daily cap bounds one account's day; the pair rate and the self rate are global settings that an
  operator can lower in one save.
- The ledger makes every peso traceable to a buyer, an order and a node — so a suspicious pattern is a
  query, not an investigation.

**Monitoring**: the reports page's *Top nodes* card, the *Pairs by day* card and the health pack's
pending-award count are the three screens an operator watches in the first month (§9, §12). The plan does
**not** ship automatic fraud detection; it ships the data to see it.

### 17.4 Privacy

- A member sees **their own** subtree only: the tree root, the JSON payload and the search are scoped
  server-side (P22G-T5/T19/T33). The earnings tab names no ancestor (D-22).
- A member sees a downline member's `@username`, status, package and points — the same fields the
  unilevel repeat tab already shows. No email, phone, address or full name.
- An admin sees the full name on the tree; the member payload has `name = null`.
- The CSV exports inherit the caller's scope (member export: own rows only).

### 17.5 Audit actions

| Action | When | Payload |
|---|---|---|
| `product.binary.update` | a product's binary switch or P.V. changes | before/after |
| `binary.placement.create` | a node is created at registration | upline, side, by |
| `binary.placement.backfill` | one per backfill run | counts, root, dry-run flag |
| `binary.placement.move` | an empty node moves | from/to, by, reason |
| `binary.export` | the ledger CSV is exported | the filters |
| `binary.release` | an order's awards release | order, awards, amount (the unilevel job's shape, stream `binary`) |
| `binary.reversal` | an order's awards are cancelled/voided | order, amounts, shortfall |
| `binary.audit.fix` | `--fix` repairs a cache | node, before/after |
| `settings.update` | the ten settings save | the existing settings audit covers them |

### 17.6 Hardening

- **CSRF**: every POST (checkout, register, the move) is guarded by the router's token; the modal's form
  carries `csrf_field()`.
- **Guards**: the tree and earnings are `member` (admins included by the app's guard); the ledger is
  `admin`; the move is `admin`; the JSON inherits the page's guard.
- **Escaping**: every string in the tree (username, package) goes through `e()`; the JSON is encoded
  with `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`; the SVG text is
  set with `textContent`/d3 `.text()`, never `.html()`.
- **No unsafe inline data**: the seed JSON is in a `<script type="application/json">` block, not an
  inline executable script; no `eval`, no `innerHTML` with server strings.
- **Rate limits**: registration already has the app's throttling; the tree JSON is GET-only, cached by
  ETag; no new write endpoint except the admin move.
- **Kill switches**: `binary_enabled` (stops new points/awards instantly), `binary_release_enabled`
  (stops releasing), `shop_cancel_enabled` (already exists, controls cancellation and therefore reversal).
- **Retired guards**: §3.7 — the four Phase 9 guards are replaced, not removed silently, and the release
  note names them (22I Rule 5).

### 17.7 The company root

- It is the only `state = 'root'` node, created lazily, and it is the **primary admin** (the same account
  the unilevel stream refuses to pay).
- The pairing walk stops before it; `BinaryService` has an explicit "company account is not paid" branch
  and `INV-9` asserts no award row names it.
- Its `left_points`/`right_points` are still recorded (they are the whole tree's volume) and are shown to
  admins in the tree — never as income.
- If the primary admin changes (a new account promoted), the root does not move automatically: the plan
  ships one root and an `ensureRoot()` that refuses a second one. Changing the company account is an
  operational decision, not a feature (Q9).

---

## 18. Testing harness, fixtures and QA protocol

### 18.1 How the suite runs

```bash
php tests/run.php                 # whole suite
php tests/run.php Binary          # every case whose file or label matches
php tests/run.php P22D-T27        # one case
powershell -File tests/smoke.ps1  # the HTTP smoke pass
```

- Every case runs in a **transaction that is rolled back** (`test_run_isolated`), so a case may create
  users, nodes, orders and awards freely.
- A case is a named closure in a `tests/Cases/*.php` file; the label is the ID (`P22D-T27 …`), which is
  what the gate counts.
- The suite prints its phase line (`schema <db> · phase 22`) and exits non-zero on any failure.
- **Fixtures are rebuilt inside the case**, never assumed from the demo data. The only shared helper is
  `BinaryTestFixtures::tree()` (§18.2), which creates its own users with unique usernames.

### 18.2 The fixture tree

Every binary case that needs a network builds this tree (usernames suffixed with the case's random
token, ids looked up, never hard-coded):

```
id 1  admin        state=root                 the company account (primary admin)
└─ L  2  boss                                  depth 1, path L
   ├─ L  3  a                                  depth 2, path LL
   │  ├─ L  4  b                               depth 3, path LLL
   │  │  └─ L  5  c                            depth 4, path LLLL
   │  │     ├─ L  6  buyerA                    depth 5, path LLLLL
   │  │     └─ R  7  buyerB                    depth 5, path LLLLR
   │  └─ R  8  sideR                           depth 3, path LLR
   └─ R  9  d                                  depth 2, path LR
```

Products:

| SKU | Price | P.V. | Binary switch | Points/unit |
|---|---:|---:|---|---:|
| `P1` Coffee | 300.00 | 250.00 | on | 250 |
| `P2` Immune | 500.00 | 0.00 | on | 0 (the misconfiguration) |
| `P3` Spirulina | 400.00 | 300.00 | **off** | 0 |
| `P4` Bundle | 1,000.00 | 1,000.00 | on | 1,000 |

Settings for the money cases: `binary_enabled=1`, self `5.000`, pair points `1000`, pair pct `10.000`,
cap `20`, requires Active `0` (the Active cases turn it on), hold `7`, release `1`, reversal `1`.

**Every expected number in §8 is computed from this fixture.** A case that needs a different shape
(a ten-deep chain, a capped day, an inactive node) builds it on top and states its own numbers.

### 18.3 Query budgets

The plan names the query shapes that must not become N+1, and the cases that pin them:

| Path | Budget | Case |
|---|---|---|
| Payment hook, 1 order × 3 lines × 10-deep chain | ≤ 30 queries, no per-level query | P22D-T71 |
| `quoteCart()` | ≤ 6 queries for 10 lines | P22D-T72 |
| Left/Right roster, one page | 1 query per card page | P22F-T2/T3 |
| Tree JSON, one branch | 1 query per depth level + 1 for the viewer scope | P22G-T34 |
| Ledger page | 1 count + 1 page + 1 stats batch | P22E-T2 |
| Audit | 5 set-based queries, no per-node loop | P22H-T8 |

The `test_query_count()` helper wraps a closure and returns the number of statements the PDO logged
(`PDO::ATTR_STATEMENT_CLASS` or the existing harness's counter, whichever the suite already provides);
where it does not exist, the case asserts the absence of a loop by timing and by the statement log in
`storage/logs/`.

### 18.4 The QA pack

`docs/qa/phase-22-product-binary.md` — sections A–H, one per phase, `QA22X-nn` checks, each with:

```
### QA22D-03 — a pair pays and escrows
**Precondition:** the fixture tree, P1 configured, the stream on.
**Steps:** 1. … 2. … 3. …
**Expected:** …
**Evidence:** docs/qa/evidence/phase-22/22d-03.png
**Result:** PASS  · **By:** <name> · **Date:** <date>
```

The pack's header carries the baseline counts, the scratch-database recipe (migrate → backfill → configure
→ seed the fixture) and the "what is already automated" table, so a QA reader does not repeat a test the
suite already runs. The pack is **append-only** per phase: 22A creates section A, 22B appends B, and no
later phase rewrites an earlier section.

### 18.5 The smoke pass

`tests/smoke.ps1` gains, in 22C–22H, the checks that need a real HTTP server:

- `P22C-*`: the register page renders the placement block; a registration through the page creates a
  node; the console lists the floating members.
- `P22F-*`: `/earnings?ledger=binary` renders; `/checkout` shows the position card; an order without a
  position is refused with the field error.
- `P22G-*`: `/genealogy?view=binary` returns the page and the seed JSON; `?format=json` returns JSON; a
  member's out-of-scope `root` is refused.
- `P22H-*`: the release job's dry run and the audit's `--check` exit 0.

The smoke additions are listed in each phase's manual gate and counted in §15.8.

### 18.6 The acceptance run

Appendix B is the acceptance script: **12 numbered steps**, each with the exact URL/command, the exact
expected observation and the evidence reference. It is executed on a scratch database by someone who did
not implement the feature (22I Rule 3). It is the last gate; a step that fails sends the defect back to
its owning phase.

---

## 19. Risks and mitigations

### 19.1 Product risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R-01 | The pair liability is larger than the margin at the default preset | medium | high | The preset is conservative (10% of matched points); the daily cap bounds a day; the reports and the health pack expose the totals; the rates are one save away; Q2/Q3 ask the business to confirm | 22D/22E |
| R-02 | No flush means points accumulate forever and the liability never expires | medium | medium | It is the intended "accumulated points" model; Q6 records the alternative; the audit reports the open carry per node and the reports show the total unmatched points | 22D/22B |
| R-03 | Members game the tree (slot squatting, re-parenting) | medium | high | Scope + immutability + audit; the backfill is deterministic; the console shows the floating count | 22C |
| R-04 | Duplicate/fake accounts to build volume | medium | medium | Real money per purchase; the cap; the self/pair rates are a cost; monitoring cards | 22D/22E |
| R-05 | A cached balance drifts from the ledger | low | high | The ledger is the source of truth; INV-2/3; `binary_audit.php --fix`; a nightly `--check` in the runbook | 22D/22H |
| R-06 | A deadlock or timeout on a busy checkout | low | medium | Ascending node locks; a small transaction; a bounded walk; the failure surfaces as a failed checkout and no money | 22D |
| R-07 | The tree is slow or unusable on a large network | medium | medium | Hard node/depth caps, lazy branches, indexed paths, server first paint, a 2,000-node performance case | 22G |
| R-08 | The member page leaks an upline | low | high | The copy guard test and the copy deck; D-22 | 22F |
| R-09 | The retired guards silently weaken the suite | medium | high | The replacement ships in the same commit; §3.7 names every retirement; the release note repeats it | 22C/22I |
| R-10 | An operator turns the stream on before 22D and expects money | medium | low | The settings card and the QA pack say so; the switch is inert until 22D | 22B |
| R-11 | The backfill builds a tree the business dislikes | medium | medium | Dry run first; the console allows empty-subtree moves; the tree is visible before any money | 22C |
| R-12 | The release job's new default changes unilevel behaviour | low | high | `--stream=unilevel` is pinned byte-identical; the default adds a stream, it does not alter one | 22H |
| R-13 | The d3 asset is missing or blocked in production | low | medium | Vendored with the licence; the no-JS list is the fallback; a smoke check asserts the file | 22G |
| R-14 | A partial rollback leaves checkout blocking orders | medium | high | The rollback note: the view goes before the requirement, or both in one commit; the order refuses only when the stream is on and points exist | 22F |

### 19.2 Plan risks

| # | Risk | Mitigation |
|---|---|---|
| P-01 | The Phase 21 baseline counts are wrong | §2.6: measure, record, correct before 22A |
| P-02 | A phase discovers it needs a column | §15.6 / 22A Rule 9: a plan amendment, never a quiet `ALTER` |
| P-03 | The nine phases are too many for the team's cadence | The phase map is explicit about siblings; 22E, 22F and 22H may run concurrently, and 22G may start when 22C lands if 22F slips |
| P-04 | The economics are decided during implementation | Q1–Q4 are the business's; the plan ships the preset and the switches, not a promise |
| P-05 | The plan is read as a replacement for the compensation plan | §1 header and §14: this plan implements a new stream; the business still owns the numbers |

---

## 20. Open questions

These are recorded, not answered. Each names the owner, the phase that would be affected, and what
happens if the answer is "keep the preset".

| # | Question | Owner | Affects | Default if unanswered |
|---|---|---|---|---|
| **Q1** | Is the Self Bonus a launch feature at **5%**, or should it be 0% until the business sets it? | Business | 22B/22D | Ship 5%; the switch and the rate are one save |
| **Q2** | Is **1,000 points per pair** the right size, and is **10%** the right pair rate? | Business | 22B/22D | Ship 1,000 / 10% (₱100 per pair) |
| **Q3** | Is the **daily pair cap of 20** the intended brake, or should it be lower/higher/unlimited? | Business | 22B/22D | Ship 20; `0` is one save |
| **Q4** | Should a pair require the recipient's **Active** month (default on) or pay every eligible member? | Business | 22B/22D | Ship on, mirroring the unilevel stream |
| **Q5** | Does the **buyer's own node** pair on their own purchase (the design), or should the self bonus be the *only* own-purchase income? | Business | 22D | Ship both; `binary_self_pct = 0` disables the self bonus, the own-node pair remains |
| **Q6** | Should unmatched points **flush** at a cycle (monthly/quarterly) instead of carrying forever? | Business | 22D/22H | Carry forever; a flush is a new plan (it needs a period, a job and a ledger event) |
| **Q7** | Who chooses the placement at registration — the registrar (the plan), the sponsor only, or an auto-spillover? | Business/Ops | 22C/22G | The registrar, with the sponsor as the default upline |
| **Q8** | Should a member be allowed to change placement after volume exists (with a money-carrying move)? | Business | 22C | No; an empty-subtree move only |
| **Q9** | What happens if the company account changes? | Ops | 22C/22H | The root does not move; a migration/support decision, not a feature |
| **Q10** | Should the binary points also be visible on the **package** pages (activation)? | Business | 22F | No — packages are not repeat purchases (D-15) |
| **Q11** | Should the member be able to export their tree? | Product | 22G | No in v1; the print stylesheet is the list |
| **Q12** | Is the **P.V.** the right point value for every product, or should some products be excluded by a per-product multiplier? | Business | 22B | One point value per product; a multiplier is a new plan |

---

# Part IV — Appendices

## Appendix A — The reference configuration and the default preset

### A.1 The nine visible controls

Nine keys are editable on `/admin/settings` → **Commissions** → *Product binary*; the tenth
(`binary_ledger_since`) is written by the migration and never rendered.

| Control | Key | Preset | Range | What an operator changes it for |
|---|---|---:|---|---|
| Product binary enabled | `binary_enabled` | **off** | on/off | the master switch; off stops new points and awards immediately |
| Own-purchase bonus | `binary_self_pct` | 5.000 | 0–100 | the buyer's rebate on their own points |
| Points per pair | `binary_pair_points` | 1,000 | 1–1,000,000 | the size of a pair |
| Pair rate | `binary_pair_pct` | 10.000 | 0–100 | the pair's value as a percentage of the matched points |
| Daily pair cap | `binary_daily_pair_cap` | 20 | 0–1,000 | the per-member day brake (0 = unlimited) |
| Require the monthly Active rule | `binary_requires_active` | on | on/off | whether a pair recipient must be Active |
| Release delay after delivery | `binary_hold_days` | 7 | 0–365 | the escrow window |
| Release product binary | `binary_release_enabled` | on | on/off | pauses the release job |
| Reverse on cancellation | `binary_reversal_enabled` | on | on/off | clawback of already-credited awards |

Plus the per-product switch (`products.binary_enabled`, off) and the shared point value
(`products.pv`, unchanged by this plan).

### A.2 What the preset pays

At the preset, with a product of P.V. 250 (250 points per unit):

| Purchase | Points | Self (5%) | Pairs possible at the buyer's node | Pair money (10% of 1,000) |
|---|---:|---:|---:|---:|
| 1 unit | 250 | ₱12.50 | 0 | — |
| 4 units | 1,000 | ₱50.00 | 1 if the other side has 1,000 | ₱100.00 |
| 8 units | 2,000 | ₱100.00 | 2 if the other side has 2,000 | ₱200.00 |
| 4 units × 10 orders | 10,000 | ₱500.00 | 10 | ₱1,000.00 |

**Per order, the buyer's own-node income is bounded by** `points × self% + floor(points / PP) × pair
value`. The ancestors' income is bounded by the same pair value at each eligible ancestor, capped per
recipient per day. Appendix F states the bound.

### A.3 The reference product set

A product is binary-ready when `products.binary_enabled = 1` **and** `products.pv > 0`. The plan ships
no product P.V. values (the P.V. list is the business's, Q12); the readiness card names the products that
are flagged without one, and the product card shows `P.V. → points per unit` so the mistake is visible at
the point of configuration.

### A.4 Restoring the preset

There is no "restore defaults" button (the royalty phase learned that lesson: the card must show what it
ships, and the operator restores by hand). The preset is the seeded value; to restore it, enter the nine
values of A.1 and save. The readiness sentence recomputes the pair value as the values change.

---

## Appendix B — Worked scenarios and the acceptance run

Every number below is the number the tests of §8 use. The fixture is §18.2.

### B.1 The first purchase

`buyerA` (path `LLLLL`: under `c` → `b` → `a` → `boss` → root) buys **4 × P1** (250 points/unit) =
**1,000 points**, position **left**, e-wallet.

| Effect | Amount |
|---|---|
| Self award to `buyerA` | `round(1000 × 5.000 / 100, 2)` = **₱50.00** |
| Volume at `buyerA` (own, left) | +1,000 |
| Volume at `c` (team, left) | +1,000 |
| Volume at `b`, `a`, `boss`, root (team, left) | +1,000 each |
| Pairs | **0** — every side above is left; no right volume exists |
| Order columns | `binary_points = 1000`, `binary_self_total = 50.00`, `binary_pair_total = 0.00`, `binary_total = 50.00` |
| Awards | 1 (`self`, pending) |

### B.2 The first pair

Then `buyerB` (path `LLLLR`: under `c`, right) buys **4 × P1**, position **right**.

| Effect | Amount |
|---|---|
| Self to `buyerB` | **₱50.00** |
| Volume at `buyerB` (own, right) | +1,000 |
| Volume at `c` (team, right) | +1,000 → `c` now has **left 1,000, right 1,000** |
| Pair at `c` | 1 pair → **₱100.00**, points consumed (left 0, right 0) |
| Volume at `b`, `a`, `boss`, root (team, left) | +1,000 each (the buyer sits under `b`'s left) |
| Awards | `buyerB` self ₱50.00 + `c` pair ₱100.00 = **₱150.00** |

The buyer's **own node** did not pair: `buyerB` had no left volume, `buyerA` had no right.

### B.3 A ten-deep order

A scratch chain of eleven members — the buyer plus ten uplines, the last of which is the root — each
node pre-filled with **2,000 right points**. The deepest member buys **2 × P4** (1,000 points/unit) =
**2,000 points**, left.

| Node | Due | Cap room | Pairs | Pair money |
|---|---:|---:|---:|---:|
| the buyer's own node | 2 | 20 | 2 | ₱200.00 |
| ancestor 1 (the parent) | 2 | 20 | 2 | ₱200.00 |
| ancestor 2 | 2 | 20 | 2 | ₱200.00 |
| ancestor 3 | 2 | 20 | 2 | ₱200.00 |
| ancestor 4 | 2 | 20 | 2 | ₱200.00 |
| ancestor 5 | 2 | 20 | 2 | ₱200.00 |
| ancestor 6 | 2 | 20 | 2 | ₱200.00 |
| ancestor 7 | 2 | 20 | 2 | ₱200.00 |
| ancestor 8 | 2 | 20 | 2 | ₱200.00 |
| ancestor 9 | 2 | 20 | 2 | ₱200.00 |
| the root | — | — | 0 (never paid) | — |
| **total** | | | **20** | **₱2,000.00** |

Plus the self bonus **₱100.00** (2,000 × 5%). The order's `binary_total = 2,100.00`. Each node has one
`pair` award with `pair_count = 2`; the cap (20) is not reached.

### B.4 The daily cap

`binary_daily_pair_cap = 1`. A purchase that is due 3 pairs at the buyer's node and 1 pair at the parent:

- buyer's node: 1 pair paid (cap reached), 2 pairs' worth of points stay;
- parent: 1 pair paid (its own cap is separate);
- the same member's next order the same day: 0 pairs at the buyer's node (cap used), the parent still
  pays if its own cap allows.

The carry is 2 × 1,000 points on the buyer's node, available tomorrow.

### B.5 The carry

`c` has **left 2,500, right 1,200**.

- 1 pair paid (1,000 each) → left 1,500, right 200.
- 200 right points do not pair (below 1,000).
- A later right purchase of 800 points → right 1,000 → 1 pair paid → left 500, right 0.
- The 500 left points stay.

### B.6 The ineligible node

`c` is inactive (`is_active = 0`). `buyerB` buys 4 × P1 right:

- `c` receives +1,000 right (volume is always recorded);
- `c` is **not eligible** → no award, **no consumption**: `c` stays left 1,000 / right 1,000;
- `b`, `a`, `boss`, root still receive their left volume;
- `blockedMap()` reports `c` with *"recipient account is disabled"*;
- when `c` is restored and an Active month is met, the next purchase pairs the waiting 1,000/1,000.

### B.7 The reversal

Cancel the order of B.1 (undelivered, awards pending):

| Effect | Amount |
|---|---|
| `self` award | `cancelled` (no money ever moved) |
| Volume rows of the order | one `reversal` row each, −1,000 at the same node/side |
| Node balances | back to their pre-order values |
| `buyerA.personal_points` | −1,000 |
| Order totals | recomputed to 0.00 |
| Wallet | untouched |

Cancel B.2's order **after release**:

| Effect | Amount |
|---|---|
| `c`'s pair award | `voided`, `reversed_amount = 100.00` |
| `c`'s wallet | debited 100.00 (up to the withdrawable balance), `ref_type = 'binary_reversal'` |
| `c`'s pair points | returned: left 1,000 / right 1,000 again (`pair_return` rows) |
| `buyerB`'s volume | reversed |
| A shortfall (if `c` spent part of it) | recorded, never a negative balance |

### B.8 A 30-day example

Four members (`buyerA`, `buyerB`, `c`, `boss`), the preset, the fixture:

| Day | Order | Points | Self | Pair at `c` | Notes |
|---|---:|---:|---:|---:|---|
| 1 | buyerA, 4 × P1, left | 1,000 | 50.00 | — | c left 1,000 |
| 3 | buyerB, 4 × P1, right | 1,000 | 50.00 | 100.00 | c 0/0 |
| 8 | buyerA, 4 × P1, left | 1,000 | 50.00 | — | c left 1,000 |
| 10 | buyerB, 4 × P1, right | 1,000 | 50.00 | 100.00 | c 0/0 |
| 15 | buyerA, 4 × P1, left | 1,000 | 50.00 | — | c left 1,000 |
| 18 | buyerB, 4 × P1, right | 1,000 | 50.00 | 100.00 | c 0/0 |
| **month** | **6 orders** | **6,000** | **₱300.00** | **₱300.00** | total **₱600.00** |

Delivered on day 20, hold 7 days: all awards release on day 27 (or the delivery date + 7 for the later
orders). The 30-day report shows ₱600.00 awarded, ₱600.00 released, 6 self awards, 3 pair awards, 6,000
points placed (3,000 left, 3,000 right), 0 blocked.

### B.9 The acceptance run (12 steps)

Executed on a scratch database built from `p21g-release` + the branch, by someone who did not implement
the feature.

1. **Migration and backfill.** `mysql < 026_product_binary.sql`; `php bin/binary_place_tree.php --check`
   → 0 written; `--apply` → every member placed; run again → 0. `SHOW TABLES LIKE 'binary%'` → 3.
2. **Install parity.** `php bin/build_install_sql.php --check` → 0; fresh install → 42 tables, 89
   settings; `php bin/wallet_reconcile.php` → 0.
3. **Configuration.** `/admin/products/{id}/edit`: P.V. 250, binary on, save; `/admin/settings`:
   the stream on at the preset, save; the readiness card is clean.
4. **Placement at registration.** `/register` with a sponsor and a left slot → the member exists with the
   node; the same slot again → refused, no member.
5. **Checkout position.** `/checkout` with 4 × P1: the card shows 1,000 points and ₱50.00 self; no
   position → refused; right → the order is placed with `binary_position = right`.
6. **Payment and escrow.** Pay with the e-wallet: `binary_awards` has a `self` ₱50.00 `pending`,
   `binary_volume` has the buyer + ancestor rows; the wallet has **no** binary credit yet.
7. **A pair.** A second member buys 4 × P1 right; `c`'s pair appears (if the fixture is used) — the
   ledger and the order card agree.
8. **The tree.** `/genealogy?view=binary`: the nodes, points and pairs; the `+` on an empty slot
   registers a member into it; with JS off the list works.
9. **Member page.** `/earnings?ledger=binary`: the Left and Right team cards, the self card, the awards
   table; the copy names no upline.
10. **Admin pages.** `/admin/commissions?ledger=binary`: the rows, the filters and the CSV;
    `/admin/orders/{id}` and `/admin/members/{id}`: the cards.
11. **Release.** Deliver, run `php bin/unilevel_release.php`: the binary award releases; the wallet shows
    `binary`; `php bin/binary_audit.php` → 0.
12. **Reversal and stop.** Cancel a paid order: the pending awards cancel, the volume reverses, the
    ledger balances; turn the master switch off: a new paid order writes zeros and is stamped.

---

## Appendix C — Copy deck

The strings below are the shipped copy. A surface may not invent its own.

### C.1 Product page (`/shop/{id}`)

> **Product binary points**
> This product places **{points} points** per unit in the binary tree.
> You earn **{self}%** of the points on your own purchase when you place it left or right.

(When the stream or the product is off, the panel is absent.)

### C.2 Checkout — the position card

> **Place this purchase**
> Choose the side of your binary tree this order is placed on. Your points count towards your next pair.
> ○ **Left** — {points} pts · you earn {self} on this order · {pairs} pair(s) now
> ○ **Right** — {points} pts · you earn {self} on this order · {pairs} pair(s) now
> *The position is final once the order is placed.*

Error: *"Choose the side for this purchase."*
Unplaced: *"You are not placed in the binary tree yet, so this order will not place points. Ask your
upline or an administrator to place you."*
Off: *(no card)*

### C.3 Member earnings — the tab (`/earnings?ledger=binary`)

Heading: **Product binary**
Intro: *"Every repeat purchase is placed on the left or the right of your binary tree. Points on both
sides make a pair. Your own purchases earn too."*

Stats: **Your points** · **Pairs paid** · **Product binary earned** · **Pairs today**
Progress: *"{n} more points on each side for your next pair." / "Your next pair pays {value}."*
Self card: **Your own purchases** — *"You earn {self}% of the points on every purchase you place."*
Left team card: **Left team** — *"{n} members · {points} pts · {lifetime} pts earned"*
Right team card: **Right team** — *"{n} members · {points} pts · {lifetime} pts earned"*
Empty side: *"No members on your left team yet."*
No pairs yet: *"Place your next repeat purchase left or right to start a pair."*
Not placed: *"You are not placed in the binary tree yet. Your purchases will not place points until you
are."*

### C.4 Member order page (`/orders/{id}`)

> **Product binary**
> This order was placed on the **{side}** with **{points} points**.
> You earn **{self}** on your own purchase. {pairs} pair(s) were paid to you.

Before payment: *"This order places points when it is paid."*
No points: *(no card)*

### C.5 Dashboard tile

> **Product binary** — {left} pts left · {right} pts right
> Next pair in {n} pts · {earned} earned this month

### C.6 Genealogy — the tree (`/genealogy?view=binary`)

Heading: **Binary tree**
Intro: *"Your placement tree. Every member is placed on a left or a right slot; points on both sides make
pairs."*
Controls: **Fit** · **Zoom in** · **Zoom out** · **Expand all** · **Collapse all** · **Depth** ·
**Vertical / Horizontal** · **My node** · **Search** · **Legend**
Legend: **You** · **Active** · **Inactive** · **Pending** · **Free slot** · **L / R points**
Node menu: **Centre** · **Expand** · **Collapse** · **Register here** · **Copy invite link** ·
**View member** (admin)
Free slot: *"Open slot — register here"*
Modal title: **Register a member here**
Modal subtitle: *"The placement is fixed: **{side}** of **@{upline}**. The member can be moved only while
this branch has no points."*
Invite copy: *"Send this link. The first registration that completes takes the slot."*
Search empty: *"No member in your tree matches that."*
Out of scope: *"You can only view your own tree."* (a redirect, not a message, on the page)

### C.7 Admin — the ledger tab (`/admin/commissions?ledger=binary`)

Heading: **Product binary**
Stats: **Credited** · **Pending** · **Pairs** · **Points placed (L/R)** · **Self / Pair** · **Blocked pairs**
Empty: *"No product binary has been paid. Turn it on in Settings and flag a product with a P.V."*
Columns: Date · Kind · Member · Buyer · Product · Side · Points · Rate · Amount · Status · Order

### C.8 Admin — order, member, reports

Order card: **Product binary** — *"{side} · {points} pts"*; *"{n} pair(s) at {n} nodes"*.
Not paid: *"Paid orders place points. This order is {status}."*
Paid before the feature: *"This order was paid before the Product binary stream was switched on."*
Stream off: *"No points were placed: the stream was off when this order was paid."*
Member panel: **Binary placement** · **Points** · **Pairs** · **Today** · **Blocked pairs** · **Open in tree**
Not placed: *"Not placed in the binary tree."*
Reports: **Product binary (30 d)** · **Pairs by day** · **Self vs pair** · **Top nodes** · **Readiness**

### C.9 Admin — settings and readiness

Settings card: **Product binary** — the nine controls of A.1.
Readiness: *"{n} product(s) are flagged for the binary but earn 0 points. Set a P.V. or switch them off."*
*"{n} product(s) do not place points."*
*"The stream is off. Nothing is paid."*
*"{pair_points} points per pair pays {value} at {pct}%."*

### C.10 Wallet statement

> **Product binary** — {product or pair note} — order {order_no}
> **Product binary reversal** — {order_no}

---

## Appendix D — SQL quick reference

```sql
-- The tree of one member (down), as the audit sees it
SELECT n.user_id, u.username, n.side, n.depth, n.path, n.state,
       n.left_points, n.right_points, n.left_credited, n.right_credited,
       n.pairs_paid, n.points_matched, n.personal_points
  FROM binary_nodes n JOIN users u ON u.id = n.user_id
 WHERE n.path LIKE CONCAT((SELECT path FROM binary_nodes WHERE user_id = ?), '%')
 ORDER BY n.path;

-- The ancestors of one member (up)
SELECT n.user_id, u.username, n.depth, n.path, n.left_points, n.right_points
  FROM binary_nodes n JOIN users u ON u.id = n.user_id
 WHERE n.depth < (SELECT depth FROM binary_nodes WHERE user_id = ?)
   AND (SELECT path FROM binary_nodes WHERE user_id = ?) LIKE CONCAT(n.path, '%')
 ORDER BY n.depth DESC;

-- A node's balance from the ledger (must equal the cache)
SELECT side, SUM(points) AS balance
  FROM binary_volume WHERE node_user_id = ? GROUP BY side;

-- A node's lifetime credits
SELECT side, SUM(points) FROM binary_volume
 WHERE node_user_id = ? AND kind IN ('own','team') AND points > 0 GROUP BY side;

-- One order's points
SELECT oi.id, oi.product_name, oi.quantity, oi.unit_points, oi.points_total
  FROM shop_order_items oi WHERE oi.order_id = ? ORDER BY oi.id;

-- One order's volume
SELECT node_user_id, side, kind, points, buyer_id, created_at
  FROM binary_volume WHERE order_id = ? ORDER BY id;

-- One order's awards
SELECT id, kind, user_id, side, points, pair_count, pair_points, rate_pct, amount, status
  FROM binary_awards WHERE order_id = ? ORDER BY kind, user_id;

-- Pairs paid today for one member (the cap)
SELECT COALESCE(SUM(pair_count), 0)
  FROM binary_awards
 WHERE user_id = ? AND kind = 'pair' AND status IN ('pending','credited')
   AND paid_at >= ? AND paid_at < ?;          -- the Asia/Manila day, half-open

-- Escrow: pending by age
SELECT COUNT(*) AS awards, SUM(amount) AS amount, MIN(paid_at) AS oldest
  FROM binary_awards WHERE status = 'pending';

-- Released total and unrecovered
SELECT SUM(amount) FROM binary_awards WHERE status = 'credited';
SELECT SUM(amount - reversed_amount) FROM binary_awards WHERE status = 'voided';

-- Nodes with a broken path (must be empty)
SELECT user_id, path, depth FROM binary_nodes WHERE state = 'placed' AND upline_id IS NULL;

-- The root (must be exactly one)
SELECT user_id FROM binary_nodes WHERE state = 'root';

-- Members with no node
SELECT u.id, u.username FROM users u
  LEFT JOIN binary_nodes n ON n.user_id = u.id
 WHERE n.user_id IS NULL AND u.role = 'member';

-- The settings
SELECT key_name, value FROM settings WHERE key_name LIKE 'binary%' ORDER BY key_name;
```

---

## Appendix E — Glossary

| Term | Meaning |
|---|---|
| **Active (month)** | The unilevel month clock: personal purchases in the previous calendar month ≥ `unilevel_active_threshold`. |
| **Adjustment** | A signed `binary_volume` row written by a support tool (not shipped in v1) with a note. |
| **Award** | One `binary_awards` row (`self` or `pair`) and, once released, one wallet credit. |
| **Binary upline** | The member's parent in the placement tree. Not the sponsor. |
| **Blocked pair** | A pair that is due at a node but not paid because the node is not eligible. No points are consumed. |
| **Cap** | `binary_daily_pair_cap`: the maximum pairs one member can be paid in one Asia/Manila day. |
| **Carry** | Unmatched points left on a side after pairing. They carry forever. |
| **Company root** | The primary admin's node: the tree's root, never a recipient. |
| **Consumption** | The `pair` rows that deduct matched points from both sides. |
| **Depth** | The node's distance from the root (root 0). |
| **Floating** | A node with no upline and no side that is not the company root (a legacy tree root). |
| **Node** | One member's position in the placement tree. |
| **Order points** | The sum of the eligible lines' `points_total`. |
| **Pair** | `binary_pair_points` available on each side, matched. |
| **Pair return** | The positive `pair_return` rows that give consumed points back when a pair award is cancelled or voided. |
| **Pair value** | `round(pair_points × pair_pct / 100, 2)`. |
| **Personal points** | A node's own purchases' points. |
| **Placement** | The act of giving a member a node. |
| **Points** | Whole-number volume from `ROUND(pv) × quantity`. |
| **Release** | The pending → credited transition after delivery + hold, which writes the wallet credit. |
| **Reversal** | The negative volume rows and the cancellation/void of an order's awards on cancellation. |
| **Self Bonus** | The buyer's own-purchase award (5% of the order points at the preset). |
| **Side** | `left` or `right`. |
| **Slot** | `(upline, side)`; unique. |
| **Sponsor tree** | `users.sponsor_id`: the only chain for package referral, unilevel, royalty and Harvest. |
| **Team points** | The `team` volume rows a purchase places on its uplines. |
| **Tree** | The placement tree of `binary_nodes`. |

---

## Appendix F — Formula card

```
LINE
  unit_points    = ROUND(pv)                        half away from zero, integer
  points_total   = unit_points × quantity
  eligible       = product.binary_enabled = 1 AND global.binary_enabled = 1 AND points_total > 0

ORDER
  points         = Σ points_total over eligible lines
  position       = left | right                     required iff points > 0
  self           = round(points × binary_self_pct / 100, 2)     if > 0, one award

VOLUME (per eligible line)
  own row        = (buyer, position,      +points)
  team row at A  = (A, side_at(A, path),  +points)  for every ancestor A, root included

PAIR (per node, in order: buyer, parent, …, root)
  L, R           = node.left_points, node.right_points       signed
  due            = min( floor(max(L,0) / PP), floor(max(R,0) / PP) )
  used           = Σ pair_count this member, this day, this stream, pending+credited
  room           = cap = 0 ? due : max(0, cap − used)
  n              = min(due, room)
  per_pair       = round(PP × binary_pair_pct / 100, 2)
  amount         = per_pair × n
  consumption    = −n × PP on each side (two 'pair' rows)
  award          = one row (order, node, 'pair', pair_count = n, amount)

BALANCE
  left_points    = Σ binary_volume.points where side = 'left'          (signed)
  left_credited  = Σ points where side = 'left' and points > 0 and kind in ('own','team')
  points_matched = Σ (2 × pair_count × PP) over live pair awards
  pairs_paid     = Σ pair_count over live pair awards

PENDING / RELEASE
  due_at         = order.delivered_at (or completed_at) + award.hold_days days
  release        = pending → credited + WalletService::credit(user, amount, 'binary', award.id)

REVERSAL
  pending        → cancelled                     no money moved
  credited       → voided + debit min(amount, withdrawable) + reversed_amount
  pair points    → pair_return rows (+n × PP per side)
  volume         → reversal rows (−points per own/team row)
  order totals   → recomputed from live awards

BOUNDS
  buyer per order ≤ points × self% + floor(points / PP) × per_pair
  per ancestor    ≤ floor(min(L,R) / PP) × per_pair, capped per member per day
  no award to the company root, ever
```

---

## Appendix G — Traceability to the reference tree and the existing streams

### G.1 The requirement, in the requester's words

| Requirement | Where it is specified | Where it is built | Where it is tested |
|---|---|---|---|
| "product binary is basically just a unilevel, but you place your repeat-purchase left or right" | §14.6–14.9 | 22C/22D | P22D-T1–T5 |
| "if you buy a product the system will ask you to select binary position" | §14.6 | 22D/22F | P22D-T1, P22F-T9–T12 |
| "this way you will earn from your own purchase" | §14.8, §14.9 step 1 | 22D | P22D-T1, T10 |
| "and also from the purchase of your subsequent left and right team" | §14.9 | 22D | P22D-T2–T5 |
| "the system will require binary upline and position upon registration" | §14.5 | 22C | P22C-T19–T28 |
| "the system also counts how many points you've accumulated, the basis for your unilevel" | §14.3 (P.V., the unilevel base) | 22B/22D | P22B-T4, P22D-T1 |
| "those points … the basis for your binary-upline's pair" | §14.7 (team rows) | 22D | P22D-T1, T2 |
| "if you were positioned with the left team, the upline earns when right matches" | §14.9 | 22D | P22D-T2 |
| "present Left team and Right team like the unilevel presentation, no uplines, just your team" | §14.12, Appendix C.3 | 22F | P22F-T1–T6 |
| "adopt the d3 genealogy tree from cart-system … responsive, registration within the nodes" | §11.1–11.5 | 22G | P22G-T1–T35 |
| "I want you to top that" | §11.2 | 22G | P22G-T4–T34 |

### G.2 The reference tree's features → this plan

| Reference feature (`dmp/cart-system`) | Section | Phase |
|---|---|---|
| d3 tree, zoom, pan, expand/collapse, reset | §11.1 | 22G |
| lazy child loading (`apiBinaryTree`) | §11.4 | 22G |
| empty-slot placeholders | §11.1 | 22G |
| node counts | §11.1 (points and pairs instead) | 22G |
| in-node registration modal | §11.1, §11.5 rule 6 | 22G |
| referral link in the tree page | §11.1 | 22G |
| status legend and tooltip | §11.1 | 22G |
| responsive SVG | §11.1 | 22G |
| the reference's `binary_parent_id`/`binary_position` columns | §15.1 (a separate table) | 22A |

### G.3 The existing streams' conventions reused

| Convention | Source | Reused as |
|---|---|---|
| Escrow (`pending → credited`, `hold_days`, release job) | Phase 18 | §16.8, 22D/22H |
| Payment hooks and the order stamp | Phase 17 | §16.1, §16.4 |
| Reversal best effort | Phase 17 | §16.9 |
| `?ledger=` tab strips | Phases 17–19 | 22E/22F |
| Escrowed award ledger shape | Phase 17/18 | §15.3 |
| The month clock and Active snapshot | Phase 17 | §14.10, D-12 |
| The settings card shape | Phases 17–19 | 22B |
| The report-card shape | Phases 15–19 | 22E |
| The phase protocol, QA pack and gate record | Phases 16–21 | §2, §18, Appendix H |
| The live revision surfaces | Phases 13–19 | 22G §11.1 |

### G.4 What this plan deliberately does **not** reuse

- The unilevel `skipped` row (D-13).
- The unilevel `level` vocabulary (there is no level in a binary).
- The unilevel "the buyer earns nothing" rule (D-07 is the opposite).
- The phase 9 "no binary" guard (retired, §3.7).
- The reference tree's `?page=` API and its `users` columns (D-01, D-18).

---

## Appendix H — Phase gate record

### H.1 The gate, per phase

| Phase | Tag | Schema | Suite floor | QA section | Evidence | Signed |
|---|---|---|---:|---|---|---|
| 22A Foundation | `p22a-foundation` | 026 (3 tables, 4 alters, enum, 10 settings) | 1,506 | A (`QA22A-01`–`08`) | `docs/qa/evidence/phase-22/22a-*` | ☐ |
| 22B Configuration | `p22b-configuration` | none | 1,530 | B (`QA22B-01`–`09`) | `22b-*` | ☐ |
| 22C Placement | `p22c-placement` | none (writes the tree) | 1,576 | C (`QA22C-01`–`10`) | `22c-*` | ☐ |
| 22D Money engine | `p22d-money` | none | 1,650 | D (`QA22D-01`–`12`) | `22d-*` | ☐ |
| 22E Admin | `p22e-admin` | none | 1,668 | E (`QA22E-01`–`09`) | `22e-*` | ☐ |
| 22F Member | `p22f-member` | none | 1,694 | F (`QA22F-01`–`10`) | `22f-*` | ☐ |
| 22G The tree | `p22g-tree` | none | 1,722 | G (`QA22G-01`–`12`) | `22g-*` | ☐ |
| 22H Operations | `p22h-operations` | none | 1,734 | H (`QA22H-01`–`07`) | `22h-*` | ☐ |
| 22I Release | `p22i-release` | none | 1,734 | I (`QA22I-01`–`06`) | `22i-*` | ☐ |

**Total QA checks: 8 + 9 + 10 + 12 + 9 + 10 + 12 + 7 + 6 = 83.**

### H.2 The universal gate, copied per phase

```
[ ] MigrationTest green; at 22A the ordered list includes 026_product_binary.sql
[ ] `php bin/build_install_sql.php --check` exits 0
[ ] `php tests/run.php` green at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0
[ ] `powershell -File tests/smoke.ps1` green
[ ] Every P22X-Tn case exists, is named for the phase, and passes
[ ] The manual script is executed by someone who did not write the phase
[ ] No new PHP warning/notice/deprecation in storage/logs/
[ ] The QA section is filled, with evidence committed
[ ] The phase log entry and the Appendix H row are filled
```

### H.3 The release sign-off

```
RELEASE: Product Binary Bonus — Phase 22
Branch: feature/product-binary   ·   Final tag: p22i-release (feature tag phase-22)
Baseline: p21g-release — 39 tables · 79 settings · 1,484 cases · ≈278 smoke · APP_PHASE 21
Release:  42 tables · 89 settings · 1,734 cases · ≈300 smoke · APP_PHASE 22
Migration: 026_product_binary.sql (additive; no backfill of money or points)
New route: 1 — POST /admin/members/{id}/placement (extension, phase 22)
Retired:   MembershipTest P9-T15/T17 (source + register form), MembershipViewTest binary-copy cases
           — replaced by the ownership guard (§3.7), not removed
Accepted open questions: Q1–Q12 (recorded in §20; defaults shipped)
Deviations: recorded in docs/phase-log.md
Sign-off:  ____________________  (release owner)     Date: __________
QA:        ____________________  (QA executor)       Date: __________
```

### H.4 What a later phase may not touch

- The ten setting names and their semantics.
- The two wallet ref types.
- The award and volume row shapes and their unique keys.
- The pair algorithm's order (buyer, then uplines) and its greedy/cap semantics.
- The company root's exclusion.
- The member copy rule (no uplines).
- The vocabulary manifest (§15.6) — a new file is an amendment.

A change to any of these is a new plan.
