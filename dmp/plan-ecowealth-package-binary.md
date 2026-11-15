# Plan — Package Binary Bonus (one-time pairing on package activation)

**Phase 23** of the EcoWealth member system · **Status: planned** — no code written; the plan is the gate
record until 23A opens.
**Delivery model:** **seven gated phases, 23A → 23G.** Each phase is self-contained, shippable and
testable on its own; each builds on the one before it.
**Date:** 15 November 2026 · **Target:** `http://localhost/ecowealth/system/`
**Branch:** `feature/package-binary`, cut from `feature/product-binary` at `p22i-release` · **Tag at
start:** `p23-start`

| | |
|---|---|
| **Reference specification** | **This document §12 is normative for the package input, and the Phase 22 product-binary plan** [`dmp/plan-ecowealth-product-binary.md`](plan-ecowealth-product-binary.md) **§14–§16 is normative for everything it does not change.** This plan is an **amendment**, not a second engine: one tree, one points ledger, one money ledger, one pair algorithm. It also adapts the reference implementation in [`dmp/cart-system/core/Commission.php`](cart-system/core/Commission.php#L45-L210) (`processBinaryPlacement`, volume-based pairing on placement), [`dmp/cart-system/models/Package.php`](cart-system/models/Package.php) (`pairing_enabled`, `pairing_bonus`, `daily_pair_cap`) and [`dmp/cart-system/models/User.php`](cart-system/models/User.php#L238-L250) (pairing fires at activation, not at registration). |
| **Related plans** | [`dmp/plan-ecowealth-product-binary.md`](plan-ecowealth-product-binary.md) (Phase 22 — the tree, the ledgers, the pair engine, the escrow, the Left/Right presentation and the d3 tree this plan amends), [`dmp/plan-ecowealth-unilevel-repeat-purchase.md`](plan-ecowealth-unilevel-repeat-purchase.md) (Phase 17 — the per-product ladder that already prices a package's repeat purchases), the Phase 10–11 commission plans (`CommissionService::onActivation()`, the package referral ladder), [`dmp/plan-ecowealth-cart-system.md`](plan-ecowealth-cart-system.md) and [`dmp/plan-ecowealth-install-reset.md`](plan-ecowealth-install-reset.md) |
| **Depends on** | **Phase 22 released** (`p22i-release`): `binary_nodes`, `binary_volume`, `binary_awards`, `BinaryService`, `BinaryPlacementService`, `BinaryTreeService`, the `?ledger=binary` admin and member surfaces and the d3 tree. This plan adds **no tree, no placement rule, no pair algorithm and no route**. |
| **Baseline at `p23-start`** | At `p22i-release`: **1,734 / 1,734** PHP cases · **≈ 300 / 300** smoke checks · `build_install_sql --check` exit 0 · `wallet_reconcile.php` exit 0 · `binary_audit.php` exit 0 · **42 tables · 89 settings** · migrations **001–026** · `APP_PHASE` **22**. *Today (15 November 2026) Phase 22 is still planned: the live copy is 1,162 cases, 248 smoke checks, 33 tables, 73 settings, migration 023, `APP_PHASE` 19. §2.6 says what to do if the released Phase 22 counts differ.* |
| **Ships** | one migration (ledger amendment + per-package points + activation stamps + 3 settings), the package-activation hook, team-only volume, immediate wallet credit, the package-qualification gate, the source split on the binary ledger, the member's package card, the reversal CLI, audit/doctor extensions and the release |
| **Does not ship** | no new table, no new tree, no new route, no self bonus, no escrow, no monthly close, no upgrade delta, no repeat input, no per-package pair rate, no flush, no change to the product stream's money |

**Revision note.** This is the first plan for the Package Binary Bonus. It exists because Phase 22 made
the binary machine, and the machine has exactly one missing input: **the package sale**. The plan is
therefore written as a **delta**: §12 states the package input, §13 states the schema amendment, §14
states the money-path delta, and every other rule is the product plan's and is cited, not repeated. Two
adaptations are stated up front:

1. **The reference's package binary is volume-based and per-package.** A paid member contributes their
   package's `pairing_bonus` as **pair volume**; every ancestor settles `min(left, right) − processed` in
   pesos, capped per day by `daily_pair_cap × own pairing_bonus`, and **flushes** the excess
   ([`Commission.php`](cart-system/core/Commission.php#L33-L40)). This plan keeps the volume idea and the
   per-package switch, but **reuses Phase 22's point model and one global pair value** so package and
   product volume can live in one tree and one balance (§12.5, D-06).
2. **The package sale is one-time, so the stream is one-time.** Phase 22's unique claim was recurring
   income; this stream is the "usual" package binary the product plan contrasted itself with. Its event
   is an **approved activation**, its volume is contributed once, and its money is credited **immediately**
   (no delivery to wait for) — the same shape as the Phase 10–11 package referral commissions that already
   run inside `ActivationService::complete()`.

§12.12 lists every deliberate divergence from the reference's package binary.

---

## Contents

**Part I — Orientation**

1. [Executive summary](#1-executive-summary)
2. [The phase protocol](#2-the-phase-protocol)
3. [Current state and the gap](#3-current-state-and-the-gap)
4. [Decisions](#4-decisions)

**Part II — The phases** (each one is a complete work package)

5. [Phase 23A — Foundation](#5-phase-23a--foundation)
6. [Phase 23B — Configuration and readiness](#6-phase-23b--configuration-and-readiness)
7. [Phase 23C — Money engine](#7-phase-23c--money-engine)
8. [Phase 23D — Admin visibility](#8-phase-23d--admin-visibility)
9. [Phase 23E — Member visibility](#9-phase-23e--member-visibility)
10. [Phase 23F — Operations](#10-phase-23f--operations)
11. [Phase 23G — Release](#11-phase-23g--release)

**Part III — The shared contract** (the delta only; Phase 22 is the base)

12. [Specification (normative delta)](#12-specification-normative-delta)
13. [Data model amendment](#13-data-model-amendment)
14. [The money path (delta)](#14-the-money-path-delta)
15. [Security, audit and abuse control](#15-security-audit-and-abuse-control)
16. [Testing harness, fixtures and QA protocol](#16-testing-harness-fixtures-and-qa-protocol)
17. [Risks and mitigations](#17-risks-and-mitigations)
18. [Open questions](#18-open-questions)

**Part IV — Appendices**

- [Appendix A — The package preset and the point values](#appendix-a--the-package-preset-and-the-point-values)
- [Appendix B — Worked scenarios and the acceptance run](#appendix-b--worked-scenarios-and-the-acceptance-run)
- [Appendix C — Copy deck](#appendix-c--copy-deck)
- [Appendix D — SQL quick reference](#appendix-d--sql-quick-reference)
- [Appendix E — Glossary](#appendix-e--glossary)
- [Appendix F — Formula card](#appendix-f--formula-card)
- [Appendix G — Traceability to the reference package binary](#appendix-g--traceability-to-the-reference-package-binary)
- [Appendix H — Phase gate record](#appendix-h--phase-gate-record)

---

# Part I — Orientation

## 1. Executive summary

### 1.1 What ships

A **package binary bonus**: when a member's package purchase is **approved**, the package's binary points
are contributed **once** to the binary tree, every eligible binary upline settles the pairs those points
complete, and the money is credited to their withdrawable e-wallet **immediately**.

- **Same tree, same ledgers, same engine.** The package stream writes to the `binary_nodes`,
  `binary_volume` and `binary_awards` tables Phase 22 built, through the same `BinaryService`, with the
  same pair size, pair rate, daily cap, carry and audit. There is **no second tree** and **no second
  pair algorithm** (D-01).
- **The event is an approved activation.** `ActivationService::complete()` already runs the Phase 10–11
  package referral commissions inside one transaction and stamps `activations.commission_processed_at`.
  The package hook runs there too — after the referral ladder — so **every** activation path (registration
  with a code or the e-wallet, a member's proof activation, an admin approval, a wallet purchase) fires it
  once (D-02).
- **The volume is the package's points, per package.** `packages.binary_enabled` is the per-package switch
  and `packages.binary_points` is the volume one activation contributes (D-03). The form pre-fills the
  suggested point value from the package's product value, but the value is the operator's (Appendix A).
- **The buyer earns nothing on their own package.** The contribution is **team-only**: the activating
  member's own node does not receive an `own` row and no Self Bonus is paid. Their package purchase is the
  entry event that pays their uplines; their own income starts when their team activates and buys (D-04).
  This is the mirror image of the product stream's D-07 and it is deliberate.
- **The position is the member's existing node.** A member is placed at registration (Phase 22); their
  activation volume flows up the same path, on the same sides, with no checkout-style choice. A legacy
  member who is still unplaced when they activate gets the placement block on the activation page and
  cannot complete a binary-enabled activation until they are placed (D-05).
- **The payout is the shared pair value.** Only the points differ per package. A per-package pair rate
  would make one pair formed from mixed package and product volume incoherent, so the rate stays global
  and the package's economics are tuned with its points (D-06).
- **Immediate money, not escrow.** A package activation has no delivery and no return window; the award is
  `credited` at creation with a `binary` wallet movement inside the activation's transaction, exactly like
  the package referral commissions (D-07). The Phase 22 release job ignores these awards because they are
  never pending.
- **Only package holders earn package pairs.** When `package_binary_requires_package = 1` (the default), a
  recipient must hold a package whose own `binary_enabled = 1`; the monthly Active rule does **not** apply
  to package pairs (D-08). Volume always flows — a non-qualified ancestor still passes it up.
- **The source is visible everywhere.** Both ledgers gain `source` (`order`/`activation`) and an
  `activation_id`; the admin ledger, the member statement and the reports filter and split by source, and
  an activation's page shows the points it placed and the pairs it paid (D-10).
- **One member tab, two sources.** `?ledger=binary` keeps one URL; in 23E its title becomes **Binary
  bonus** with a **Package activations** card beside the repeat-purchase cards, and the 22F byte-identical
  regression is amended on purpose (D-12).
- **Off by default, twice.** `package_binary_enabled = 0` and every package's `binary_enabled = 0` with
  `binary_points = 0`. Deploying 23A–23C changes no money (D-13).
- **No new route, no new table, no new cron.** The reversal is a support CLI; the activation placement
  block reuses `BinaryPlacementService`; the migration is `027` (D-14, D-15).

### 1.2 The rules in one page

```
SETUP (once, Phase 23B)
  packages.binary_enabled   per-package switch                          off by default
  packages.binary_points    integer points one activation contributes   0 by default
  settings: package_binary_enabled (0), package_binary_requires_package (1), package_binary_ledger_since
  SHARED from Phase 22: binary_pair_points (1,000), binary_pair_pct (10%), binary_daily_pair_cap (20)

ON ACTIVATION → approved   (ActivationService::complete(), one transaction)
│
├─ the member must hold a placed node (else the activation asks for the placement first)
├─ package must have binary_enabled = 1 and binary_points > 0, and the global switch must be on
├─ points = packages.binary_points                                     INTEGER, snapshotted on the activation
│
├─ volume (TEAM ONLY — no own row, no self bonus):
│     for every ancestor A of the activating member:
│         +points on the side the member sits under A                   kind 'team', source 'activation'
│
├─ pairing at every ancestor A, from the parent upward to the root:
│     exactly Phase 22 §14.9, with the shared PP / pair_pct / cap / carry
│     recipient must be eligible (account, membership, not company root)
│       AND (package_binary_requires_package = 0 OR recipient's package.binary_enabled = 1)
│       AND the monthly Active rule does NOT apply
│     a pair pays round(PP × pair_pct / 100, 2) × n
│
└─ stamp activations.binary_processed_at, binary_points, binary_total

MONEY
  status = 'credited' at creation (no escrow), hold_days = 0
  wallet credit inside the activation transaction, ref_type 'binary', ref_id = award.id
  a replay cannot pay twice: activations.commission_processed_at, the activation stamp, the unique keys,
  and the wallet's own (user_id, ref_type, ref_id, type) anchor

REVERSAL (support only — the app has no post-approval cancel today)
  bin/package_binary_reverse.php --activation=<id> [--apply]
  void the awards, claw back best effort, reverse the volume, return any consumed pair points
```

**At the default preset**, a Package A activation worth **1,000 points** completes one pair per ancestor
whose opposite side already has 1,000 points, paying **₱100.00** per pair; it never pays the activating
member and never pays the company root.

### 1.3 What is deliberately not built

- **No second engine, tree, ledger, pair algorithm, cap or carry.** Anything not in §12/§13/§14 is
  Phase 22's and is cited (D-01).
- **No self bonus and no own-node volume for a package** (D-04). The reference pays uplines only; the
  product stream already covers "earn on your own purchase".
- **No escrow, no hold, no release job change** (D-07). Package money is final when the activation is
  approved.
- **No upgrade delta.** An upgrade does not exist in this application (a member activates once; an
  activation is refused while the member is active with a package). A **re-activation** after being set
  inactive is a new activation and contributes again — that is the only repeat case (D-19).
- **No per-package pair rate, pair size, cap or flush** (D-06, D-20). The reference's
  `daily_pair_cap × pairing_bonus` cap and its over-cap flush are not copied; the shared cap and carry
  apply. §12.12 records the divergence.
- **No new route** (D-14). The activation page's placement block is rendered by the existing page; the
  reversal is a CLI.
- **No new table and no change to the product stream's money** (D-15). The amendment widens the two
  ledgers and adds columns; product awards and their rules are untouched.
- **No member-facing package placement choice** (D-05). The placement is the registration's.
- **No retroactive money.** `package_binary_ledger_since` marks the instant the stream may pay; a package
  activation approved before the switch is never awarded.

### 1.4 Delivery: seven gated phases

| Phase | One-line purpose | What the gate proves | Adds money? | Adds UI? |
|---|---|---|---|---|
| **23A** | Amend the two ledgers with the source, add the package and activation columns and the three settings; teach install, migration and reset | Migrated == fresh; a product order still pays exactly as at `p22i-release`; nothing reads the new columns yet | no | no |
| **23B** | The package binary card on the package form, the settings card, the readiness panel, the badge | Configuration saves, validates, audits and renders — and an activation still pays only the referral ladder | no | admin |
| **23C** | The activation hook: team-only volume, pairs, immediate credit, the qualification gate, the reversal CLI, reconcile | Every package money rule: event, points, volume, pairs, qualification, cap, idempotency, wallet credit, reversal | **yes** | none |
| **23D** | Admin visibility: the ledger's source filter and column, the activation card, the member panel split, the report cards, the CSV | Finance can audit every package point and every package award from the UI | no | admin |
| **23E** | Member visibility: the **Binary bonus** tab's package card and source split, the dashboard tile, the tree chip | The member sees their package qualification and their package pairs; the copy stays downward-only | no | member |
| **23F** | Operations: the audit's activation checks, the doctor, the reversal runbook, the health cards | The stream is provable and correctable unattended | no | none |
| **23G** | Documentation, QA pack, phase badge, acceptance run, sign-off | The release is signed and reproducible | no | no |

**Why this split:**

- **The schema amendment first**, because the product stream is live and must keep paying identically
  while the ledgers widen. Proving "product unchanged" in isolation is the most valuable gate in the plan.
- **Configuration second**, because a package is where the operator decides the money; the card is the
  only place the economics are entered, and it is verifiable by eye.
- **Money third**, as one indivisible phase: the event, the volume, the pairs, the credit and the
  qualification together. A half-implemented package payment is wrong, not incomplete.
- **Admin before member**, because the ledger explains the money and the member page markets it.
- **Operations and release last**, exactly as Phase 22.

### 1.5 Scope at a glance

| Area | Deliverable | Phase |
|---|---|---|
| Database | migration `027`: 3 new settings, `source` + `activation_id` on both ledgers, order columns nullable, `packages.binary_enabled`/`binary_points`, `activations.binary_*` → **42 tables · 92 settings** | 23A |
| Configuration | package binary card, settings card, readiness, badge, filter | 23B |
| Money | `PackageBinaryService::onActivation()`, activation hook, team-only volume, immediate credit, qualification, `bin/package_binary_reverse.php`, reconcile extension | 23C |
| Admin | source filter/column on `?ledger=binary`, activation card, member-panel split, three report cards, CSV | 23D |
| Member | Binary bonus tab (package card + source split), dashboard tile, tree chip | 23E |
| Operations | audit activation checks, doctor, runbook, health | 23F |
| Quality | ~120 new cases, one QA pack with 42 checks, documentation | 23A–23G |

---

## 2. The phase protocol

### 2.1 What "self-contained" means here

The Phase 22 plan's definition (§2.1) applies unchanged. The one addition is **the amendment rule**:

> A phase in this plan may change a file that Phase 22 owns **only** when §13 or §14 names that change
> and the phase's manifest lists it. Every other Phase 22 file is read-only.

The anti-pattern this forbids is the quiet fork: a second pair engine, a second ledger or a second member
tab that "works for packages" and drifts from the product stream. If a package rule cannot be expressed as
a delta on Phase 22, the plan is amended first.

### 2.2 Phase map

| Phase | Name | Builds on | Effort | Cumulative suite |
|---|---|---|---:|---|
| **23A** | Foundation: ledger amendment, package/activation columns, settings, install parity | — | 1.0 d | 1,734 → ≥ 1,752 |
| **23B** | Configuration: package card, settings card, readiness | 23A | 1.0 d | ≥ 1,770 |
| **23C** | Money engine: hook, volume, pairs, immediate credit, qualification, reversal CLI | 23A, 23B | 2.5 d | ≥ 1,820 |
| **23D** | Admin visibility: source split, activation card, member panel, reports | 23C | 1.0 d | ≥ 1,840 |
| **23E** | Member visibility: Binary bonus tab, package card, dashboard, tree chip | 23C, 23D | 1.0 d | ≥ 1,860 |
| **23F** | Operations: audit checks, doctor, runbook | 23C | 1.0 d | ≥ 1,872 |
| **23G** | Release: docs, QA pack, phase badge, acceptance | all | 1.0 d | 1,872 |

**Total: ~8.5 ideal days**, plus roughly half a day of gate overhead across the seven phases.

**Dependency graph**

```
23A ──▶ 23B ──▶ 23C ──┬──▶ 23D ──▶ 23E ──┐
                      └──▶ 23F ──────────┼──▶ 23G
```

**Branches and tags.** One branch, `feature/package-binary`. One tag per gate: `p23a-foundation`,
`p23b-configuration`, `p23c-money`, `p23d-admin`, `p23e-member`, `p23f-operations`, `p23g-release`. A
phase starts from the previous tag and never from an uncommitted tree.

### 2.3 The format of every phase section

Identical to Phase 22 §2.3: **Goal, Builds on, Ships alone because, Effort/suite, Reference, Deliverables
(Database/Code/Docs/Not yet), Rules, Test plan, Manual gate, Exit gate, Rollback, Hand-off, Risks.**

### 2.4 Definition of done — the universal gate

```
[ ] Schema state matches the plan: MigrationTest green (23A applies migration 027; later phases change no schema)
[ ] `php bin/build_install_sql.php` run and `--check` exits 0
[ ] `php tests/run.php` — full suite green, exit 0, case count at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0
[ ] `php bin/binary_audit.php --check` exits 0
[ ] `powershell -File tests/smoke.ps1` — all checks pass, exit 0
[ ] Every P23X-Tn case for this phase exists, is named for the phase, and passes
[ ] The manual script for this phase is executed by someone who did not write it
[ ] No new PHP warning, notice or deprecation in `storage/logs/` after the manual pass
[ ] The QA pack section for this phase is filled, with evidence under `docs/qa/evidence/phase-23/`
[ ] `docs/phase-log.md` records the phase, tag, counts and deviations; Appendix H is filled
```

The two new lines are the **points audit** and the **money reconcile**; a phase that moves money cannot
pass without both.

### 2.5 What a phase may never do

Phase 22 §2.5 applies, plus:

- **Never change a product-stream rule to make a package case pass.** The product stream's tests are the
  regression; a package change that alters them is a plan defect.
- **Never credit a wallet outside the activation transaction.** The package money is written where the
  activation is written; there is no job, no sweep and no second chance.
- **Never add a per-package rate.** The pair value is one number (§12.5).
- **Never resurrect the escrow.** Package awards are never `pending`; a `pending` package award is a bug
  the audit reports.

### 2.6 Before you begin (once, before 23A)

1. Cut `feature/package-binary` from `feature/product-binary` at `p22i-release`; tag `p23-start`.
2. Back up the database and the working tree. The standing rule: a backup outside the repository before
   any migration.
3. Record the baseline: `php tests\run.php` → **1,734 / 1,734**; `tests\smoke.ps1` → **≈ 300 / 300**;
   `build_install_sql --check` → 0; `wallet_reconcile.php` → 0; `binary_audit.php --check` → 0. If the
   baseline is not green, stop.
4. If the released Phase 22 counts differ, record the real numbers in `docs/phase-log.md` and correct §13.6
   and Appendix H before 23A.
5. Read Phase 22 §14, §15 and §16 once, then this plan's §12–§14. The base is the contract; this plan is
   the amendment.

---

## 3. Current state and the gap

### 3.1 What Phase 22 built

| Layer | File | What the package stream reuses |
|---|---|---|
| Tree | `binary_nodes` | the node, side, depth, path and the cached point balances |
| Points ledger | `binary_volume` (`own`, `team`, `pair`, `pair_return`, `reversal`, `adjustment`) | the `team` and `pair` kinds; the signed append-only model |
| Money ledger | `binary_awards` (`self`, `pair`; `pending`/`credited`/`voided`/`cancelled`) | the `pair` kind, the unique anchor, the wallet link |
| Engine | `BinaryService` | `onOrderPaid()`, the pair algorithm, `reverseForOrder()`, `audit()`, `reconcile()` |
| Placement | `BinaryPlacementService` | `validate()`, `placeNew()`, the activation placement block |
| Tree UI | `BinaryTreeService` + `/genealogy?view=binary` | the node cards, the points, the in-node registration |
| Member | `?ledger=binary` | the Left/Right team cards, the awards table, the CSV |
| Admin | `?ledger=binary`, the order card, the member panel, the reports | the filters, the cards, the CSV |

**What this plan adds:** the second input. That is the whole architectural idea.

### 3.2 The package sale today

| Fact | Evidence |
|---|---|
| A package has a price, a product value, a direct referral bonus and a levels 2–10 ladder | `packages` (schema) and `package_levels`; seeded Package A 4,498 / 4,750 / 500 and Package B 6,998 / 8,300 / 600 |
| An activation is a first-class event with a status machine | `activations` (`pending_payment`, `pending_review`, `approved`, `rejected`, `cancelled`), `commission_processed_at` |
| The package referral commissions fire in one place, inside the activation transaction | [`ActivationService::complete()`](../system/app/Services/ActivationService.php#L369-L445) → `CommissionService::onActivation()` |
| Only a `pending`/`inactive` member can activate; an active member with a package is refused | `ActivationService::assertCanActivate()` |
| There is **no** upgrade path and **no** post-approval cancellation | `MembershipController` and `AdminActivationController` (approve/reject only) |
| A member is placed at registration (Phase 22) | `BinaryPlacementService`, the register form's placement block |

So today a package sale pays the **sponsor chain** (direct + indirect levels 2–10) and nothing else. There
is no pairing, no leg volume and no use for the package's point value. The reference has all three and
this plan adds the missing half.

### 3.3 The reference package binary, in one table

| Reference behaviour | Where | This plan |
|---|---|---|
| Pairing fires **when the member is placed and paid**, not at registration | `User::create` / `User::activate` ([`User.php`](cart-system/models/User.php#L238-L250)) | fires in `ActivationService::complete()`, once per approved activation |
| Volume from a new paid member = their package's `pairing_bonus` | [`Commission.php`](cart-system/core/Commission.php#L64-L76) | `packages.binary_points`, snapshotted on the activation |
| Every ancestor accumulates left/right volume; no own-node volume | `processBinaryPlacement` | same: **team-only** rows, no own row |
| Settlement = `min(left, right) − (paid + flushed)`, paid **1:1 in pesos** | [`Commission.php`](cart-system/core/Commission.php#L145-L190) | Phase 22's pair algorithm: pairs of `PP` points at `pair_pct`, shared cap and carry |
| Per-package `daily_pair_cap × own pairing_bonus` daily cap; excess **flushed** | same | one shared cap, **carry** (no flush) — §12.12 |
| Only binary packages earn (`pairing_enabled`, `pairing_bonus > 0`) | `Package::hasPairing` | `packages.binary_enabled` + `binary_points > 0`, for the source **and** (optionally) the recipient |
| Capped/perminact/standard ancestors are skipped but volume still accumulates | `Commission.php` | shared eligibility; a skipped node consumes nothing and the volume carries (Phase 22 D-13) |
| Money credited immediately | `creditPairing` → `Ewallet::credit` | same: `credited` at creation, `binary` wallet movement inside the transaction |
| No upgrade volume | `User::upgrade` only fires when first joining the binary | no upgrade path exists in this app; a re-activation is a new activation |
| Placement required for a pairing package at activation | `User::activate` comment + `MemberController` | the activation page's placement block; an unplaced member cannot complete a binary-enabled activation |

### 3.4 The gap, in one table

| Need | Today | This plan | Phase |
|---|---|---|---|
| A per-package binary switch and points | none | `packages.binary_enabled`, `packages.binary_points` | 23A |
| A ledger that can tell an activation from an order | `binary_awards.order_id NOT NULL`, no source | `source` + `activation_id`, nullable order columns, activation unique keys | 23A |
| An activation's binary stamp and totals | none | `activations.binary_processed_at`, `binary_points`, `binary_total` | 23A |
| A hook on the approved activation | the referral ladder only | `PackageBinaryService::onActivation()` inside `complete()` | 23C |
| Team-only volume | the engine always writes an own row | a `teamOnly` mode on the shared engine | 23C |
| Immediate credit for a package award | the engine writes `pending` | a `creditImmediately` mode; the release job ignores it | 23C |
| A package-qualification gate for recipients | eligibility is package-agnostic | `package_binary_requires_package` + the recipient's package flag | 23C |
| A source split in the UI | one undifferentiated tab | source column/filter and a package card | 23D/23E |
| A correction path | none | `bin/package_binary_reverse.php` | 23C/23F |

---

## 4. Decisions

| ID | Decision | Rationale | Rejected alternative | Ships in |
|---|---|---|---|---|
| **D-01** | **One tree, one points ledger, one money ledger, one pair engine.** The package stream is a second **input** into Phase 22's machine, never a fork | A second engine would drift from the first and make one node's balance unexplainable; the tree is the network, not the stream | A separate package tree/ledger — doubles every rule, test and page | 23A/23C |
| **D-02** | **The event is an approved activation**, fired inside `ActivationService::complete()` after the Phase 10–11 ladder, before `commission_processed_at` is stamped | The activation is where the money is final and where the app already pays the package chain; one transaction, one anchor | Fire at registration — a `pending` member could never activate and a rejected activation would have paid | 23C |
| **D-03** | **Points are per package**: `packages.binary_points` (integer), with `packages.binary_enabled` as the switch. The form pre-fills `ROUND(product_value)` but ships **0/off** | The package is the product here; the operator sets what one activation is worth | A global package point value — every package would contribute the same volume regardless of price | 23A/23B |
| **D-04** | **Team-only volume, no Self Bonus.** The activating member's own node receives no `own` row and no self award | The package purchase is the entry event that pays the uplines; the member's own earning starts with their team. The product stream already owns "earn on your own purchase" | A self bonus on the package — pays a welcome rebate on the entry fee and double-dips with the referral ladder | 23C |
| **D-05** | **The position is the member's existing node side**, not a purchase choice. An unplaced member must be placed before a binary-enabled activation completes | Placement is chosen at registration (Phase 22 D-03); asking again at activation would let one member occupy two sides of the same purchase | A checkout-style left/right choice for the package — a second placement concept for the same sale | 23C |
| **D-06** | **The pair value stays global.** Only the points vary per package | A pair can match package and product volume in one leg; a per-package rate would make the same pair worth different money to different recipients | Per-package `pairing_bonus` as the payout (the reference) — coherent only in a package-only tree | 23A/23C |
| **D-07** | **Immediate withdrawable credit.** Status `credited` at creation, `hold_days = 0`, the wallet movement inside the activation transaction. No escrow, no release job | A package has no delivery and no return window; the Phase 10–11 package commissions already credit here; the reference does the same | Escrow like the product stream — delays money for a risk (delivery) that does not exist | 23C |
| **D-08** | **Package qualification**: when `package_binary_requires_package = 1`, a pair recipient must hold a package with `binary_enabled = 1`. The **monthly Active rule does not apply** to package pairs | The package plan qualifies by package, not by last month's shopping; a package holder is already a paying associate | The shared Active gate — would suppress package income for an upline who bought no products that month | 23C |
| **D-09** | **Skips consume nothing, volume carries, the cap is shared** across both sources | Phase 22 D-13/D-14 are invariants of the tree, not of the stream; one balance cannot have two cap rules | A package-only skip row or cap — two ledgers in one balance | 23C |
| **D-10** | **`source` is explicit** in `binary_volume` and `binary_awards`, with `activation_id`; order columns become nullable and each source gets its own unique anchor | One ledger, two events: the source is what makes the row explainable and the UI filterable | A nullable "polymorphic" id without a source — unqueryable and unindexable | 23A |
| **D-11** | **No in-app reversal; a support CLI** (`bin/package_binary_reverse.php`) voids, claws back best effort and reverses the volume, audited | The app has no post-approval cancellation today; support still needs a correction path | A cancel route — new surface for an action the business has not defined | 23C/23F |
| **D-12** | **One member tab, two sources.** `?ledger=binary` is renamed **Binary bonus** in 23E, with a package card and a source split; the 22F byte-identical case is amended on purpose | One tree, one balance: two tabs would show the same points twice | A second `?ledger=package_binary` tab — duplicate stats, duplicate cards, duplicate confusion | 23E |
| **D-13** | **Off by default** at the global and per-package levels; every existing package starts at `binary_points = 0` | Deploying code must not start paying money | Enabled by default — a migration would start paying the reference preset | 23A/23B |
| **D-14** | **No new route, no new cron.** The placement block is rendered by the activation page; the reversal is a CLI; there is no job because credit is immediate | `APP_PHASE`/`ParityTest` stay untouched; one page per concept | A cancellation route and a release job — new surface for no new money rule | all |
| **D-15** | **Migration `027_package_binary.sql`**; at `p23g-release`: **42 tables · 92 settings** (no new table, three settings) | The machine already has its tables; only the input columns are missing | A new `package_binary_*` table family — a fork by data model | 23A |
| **D-16** | **The demo seed leaves the packages binary-off with 0 points** | Demo data must never pay money on install | Seeding demo points — a demo install would start pairing | 23B |
| **D-17** | **`APP_PHASE` becomes 23** | The build reports the phase that shipped the feature | Stale badge at 22 | 23G |
| **D-18** | **Points are integers, money in centavos**, exactly Phase 22 §14.11 | One rounding rule for one ledger | Float points — matching drift | 23C |
| **D-19** | **One volume per approved activation.** No upgrade delta (the app has no upgrade); a re-activation after being set inactive is a new activation and contributes again | The activation row is the event; each row is one purchase | A per-member once-forever flag — a reactivation would be unpaid work | 23C |
| **D-20** | **The reference's per-package cap, 1:1 payout and flush are not copied** | They exist to make a package-only tree solvent; this plan has one mixed balance and a shared cap/carry, and the point value is the tuning knob | Copy the flush — points would vanish from a balance the product stream also owns | 23A/23C |

---

# Part II — The phases

## 5. Phase 23A — Foundation

**Goal.** The ledgers can name a package activation as their source, a package can carry its binary switch
and points, an activation can carry its binary stamp and totals, and the three settings exist — with
**no code path reading any of it**. The product stream pays exactly as it did at `p22i-release`.

**Builds on.** Nothing new. This is the root of the graph; it amends Phase 22's schema.

**Ships alone because.** The new columns have no writer and the new settings are read by nothing. The only
observable changes are three settings rows, a wider table, and no behavioural difference at all: a paid
shop order writes the same award and volume rows as before, and an approved activation still pays only the
referral ladder.

**Effort.** 1.0 ideal day. **Suite.** 1,734 → ≥ 1,752 cases, all green.

**Reference.** §13 in full (the amendment), §12.3 (points), §12.4 (settings).

**Deliverables**

**Database**

- `system/database/migrations/027_package_binary.sql` — the amendment of §13.4, verbatim:
  - `packages.binary_enabled`, `packages.binary_points`;
  - `activations.binary_processed_at`, `activations.binary_points`, `activations.binary_total` and one index;
  - `binary_awards`: `order_id` nullable, `source`, `activation_id`, the activation unique key, the source
    check, the activation FK;
  - `binary_volume`: `order_id`/`order_item_id` nullable, `source`, `activation_id`, the activation unique
    key, the source check, the activation FK, and the **re-created** `chk_bv_own`/`chk_bv_team` checks that
    now accept an activation row;
  - the three settings.
  Idempotent: every `ALTER` is guarded by an `information_schema` probe; every `DROP CHECK` is guarded by
  `information_schema.CHECK_CONSTRAINTS`; the settings use `ON DUPLICATE KEY UPDATE key_name = key_name`.
- `system/database/schema.sql` — the same blocks, so `MigrationTest`'s migrated-vs-fresh comparison stays
  green.
- `system/database/seeds/001_settings.sql` — the three keys, same order and defaults as the migration.
- `system/database/install/footer.sql` — **42 tables, 92 settings**.
- `system/database/install.sql` — regenerated.

**Code**

- `system/app/Services/DatabaseInstaller.php` — `settings_rows` **89 → 92**; the failure message becomes
  "89 keys plus the three `install_*` metadata rows". `EXPECTED['tables_created']` stays **42**.
  `TRANSACTIONAL_TABLES` unchanged; `DEMO_TABLES` unchanged.
- `system/app/Services/DatabaseResetService.php` — no table name changes; the **Product binary** group's
  comment gains "awards and volume for **both** sources", and the group stays before the orders group and
  before the activations group (the FK from volume/awards to activations is `ON DELETE CASCADE`, but the
  reset never relies on a cascade to hide an ordering mistake).
- `system/app/Models/BinaryAward.php` and `system/app/Models/BinaryVolume.php` — **constants only**:
  `SOURCES = ['order','activation']`, `sourceFromActivation()`, `sourceFromOrder()`. No query change yet.
- `system/app/Models/Package.php` — `binaryEnabled()`, `binaryPoints()` static helpers (constants only in
  this phase; they read the columns, which are still zero).
- `docs/plan-ecowealth-product-binary.md` §15.6 — the vocabulary manifest gains the files this plan will
  add (`PackageBinaryService`, `PackageBinaryException`, `ActivationService`, `AdminActivationController`,
  `AdminPackageController`, `MembershipController`, `Package`, `Activation`, the package/activation
  views). This is a documentation amendment to the Phase 22 plan and is the only Phase 22 file 23A
  touches.

**Docs**

- `docs/qa/phase-23-package-binary.md` — header, "what is already automated", **section A only**
  (`QA23A-01` … `QA23A-06`).
- `docs/qa/README.md` — the Phase 23 row, *in progress (23A)*.
- `docs/qa/evidence/phase-23/README.md`.
- `docs/install-reset.md` — the counts and the amendment's reset behaviour.

**Not yet.** No `PackageBinaryService`, no hook, no volume, no award, no UI. A package with
`binary_enabled = 1` and 5,000 points still pays only the referral ladder.

**Rules**

1. **The amendment is additive and reversible.** `order_id` becomes nullable; no row is rewritten, no FK
   dropped. A product award still resolves its order; an activation award has `order_id = NULL`.
2. **`source` is explicit and consistent.** `source = 'order'` ⇒ `order_id` set and `activation_id` NULL;
   `source = 'activation'` ⇒ the reverse. The check constraint enforces it; the default is `'order'` so
   every existing row is valid without a backfill.
3. **The unique anchors are source-specific.** `uq_binary_award (order_id, user_id, kind)` still anchors
   product awards; `uq_binary_award_activation (activation_id, user_id, kind)` anchors package awards.
   The same for volume: `uq_bv_line` and `uq_bv_activation`.
4. **The volume checks are re-created, not weakened.** `chk_bv_own` and `chk_bv_team` now accept either a
   line reference (order source) or an activation reference (activation source); they still require a
   positive sign and still forbid an award on an `own`/`team` row.
5. **Defaults are off.** `package_binary_enabled = '0'`, every package `binary_enabled = 0`,
   `binary_points = 0`. A fresh and a migrated database both pay nothing.
6. **No backfill of money or points.** `package_binary_ledger_since` is the migration instant; approved
   activations before it are never awarded.
7. **The product stream is byte-identical.** The same order, the same awards, the same wallet rows, the
   same totals. `P23A-T14` is the regression, not a formality.
8. **The demo seed changes nothing.** Package A and B get `binary_enabled = 0`, `binary_points = 0`; the
   demo activation flow and its commissions are untouched.
9. **Schema freeze after 23A.** No later phase adds a column. A discovered need is a plan amendment.
10. **No application file outside the manifest changes.** `git diff --stat` is the check.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P23A-T1 | Apply 027 to a database at `p22i-release` | The 3 package columns, the 3 activation columns, the 2 sources, the 4 keys, the 4 checks and the 3 settings exist; no error |
| P23A-T2 | Run 027 a second time | No error; object counts and settings identical |
| P23A-T3 | Migrated vs fresh (`MigrationTest`) | Structures identical; the migration list includes `027_package_binary.sql` |
| P23A-T4 | `SeedParityTest` | 92 keys, including the three new ones |
| P23A-T5 | `php bin/build_install_sql.php --check` | Exit 0 |
| P23A-T6 | Fresh install (`InstallTest`) | **42** tables, **92** settings, the same product/package/member counts as `p22i-release` |
| P23A-T7 | Insert an activation award without a source value | Defaults to `source = 'order'` and fails the source check (no `order_id`) — the guard is wired |
| P23A-T8 | Insert an activation award with `source = 'activation'`, an `activation_id`, no `order_id` | Accepted; the activation unique key holds on a duplicate |
| P23A-T9 | Insert a `binary_volume` activation `team` row | Accepted; a duplicate is refused by `uq_bv_activation` |
| P23A-T10 | Insert an `own` activation row | Refused by `chk_bv_own` (an activation has no own row) |
| P23A-T11 | Insert a `pair` row with a positive amount, or a `team` row with points ≤ 0 | Refused by the re-created checks |
| P23A-T12 | Delete an activation that has an award | Cascades; no orphan in either binary table |
| P23A-T13 | Business reset (`DbResetTest`) | The three binary tables and `activations` are empty; the settings survive |
| P23A-T14 | **Product regression**: a paid e-wallet order after 027 | Identical `binary_awards`, `binary_volume`, wallet rows and order totals to `p22i-release`; `source = 'order'` on every row |
| P23A-T15 | `binary_audit.php --check` on a migrated database | Exit 0; the source of every row is consistent |
| P23A-T16 | `wallet_reconcile.php` | Exit 0; seven sections (the six plus the product binary) and no mismatch |
| P23A-T17 | Defaults | `package_binary_enabled = '0'`; `package_binary_requires_package = '1'`; `package_binary_ledger_since` is a timestamp; every package `binary_points = 0` |
| P23A-T18 | `packages` columns | `binary_enabled` after `product_value`; `binary_points` after it; both default 0 |
| P23A-T19 | The vocabulary manifest in the Phase 22 plan | Lists the 23A–23G files; `BinaryFoundationTest` accepts the amended list |
| P23A-T20 | `git diff --stat` | Only the 23A manifest |

**Manual gate — `QA23A-01` … `QA23A-06`**

1. `QA23A-01` — `mysql < 027_package_binary.sql`; re-run → no error. `SHOW COLUMNS FROM packages` →
   `binary_enabled`, `binary_points`; `activations` → `binary_processed_at`, `binary_points`,
   `binary_total`; `binary_awards`/`binary_volume` → `source`, `activation_id`, `order_id` nullable.
2. `QA23A-02` — `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'package_binary%'` → 3;
   `SELECT key_name, value FROM settings ORDER BY key_name` still has the ten `binary_*` keys.
3. `QA23A-03` — open `/admin/packages/{id}/edit`: no binary card appears (23B's). Open
   `/admin/settings` → Commissions: no package block yet.
4. `QA23A-04` — pay an e-wallet order as a member: the same product binary rows and wallet movement as
   before 027; `source = 'order'` on every row.
5. `QA23A-05` — approve an activation with the packages left binary-off: only the referral commissions
   move; `binary_awards` gains no row; `activations.binary_processed_at` is NULL.
6. `QA23A-06` — rebuild the bundle, re-install on an empty schema: 42 tables, 92 settings;
   `wallet_reconcile` and `binary_audit --check` exit 0; run the development reset: the binary tables and
   activations are empty, the settings survive.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P23A-T1 … P23A-T20 pass
[ ] Migrated == fresh; a second run is a no-op
[ ] The product stream regression (T14) is byte-identical
[ ] InstallTest / SeedParityTest / DbResetTest / WalletTest green at 42 / 92 / 15
[ ] The Phase 22 plan's vocabulary manifest is amended in the same commit
[ ] Tag p23a-foundation; phase log; Appendix H row
```

**Do not start 23B until every box is ticked.**

**Rollback**

Drop the three settings, the six new columns and the four new keys, restore the four checks to their
Phase 22 text, drop the activation FKs, and make `order_id`/`order_item_id` `NOT NULL` again — **only
after** confirming no activation row exists (a `NOT NULL` restore would fail on one). `schema.sql`, the
seed and `install.sql` return to their Phase 22 content; `install.sql` is regenerated.

**Hand-off to 23B**

- The ledgers can hold an activation row; nothing writes one.
- A package has a binary switch and a points column, both zero.
- Three settings exist, unread.
- The product stream is provably unchanged.

**Risks**

| Risk | Mitigation |
|---|---|
| Making `order_id` nullable breaks a query that assumes it | Every Phase 22 read path is covered by the product regression (T14); the new reads filter by `source` |
| A check constraint is silently dropped | The re-created checks are tested (T10/T11) and `MigrationTest` compares them |
| A cascade from an activation delete removes money rows | That is the intended support behaviour; the audit and the reversal CLI are the visible paths |
| The manifest grows without review | T19 fails when the Phase 22 plan is not amended |
| A developer "fixes" the product stream while widening the ledger | T14 and the Phase 22 suite are the gate |

---

## 6. Phase 23B — Configuration and readiness

**Goal.** An administrator can switch the package stream on globally, flag each package for the binary, set
the points one activation contributes and see what a pair is worth — and the readiness card names every
package that is flagged without points. **No money moves.**

**Builds on.** 23A (the columns and the settings).

**Ships alone because.** Every value this phase writes is read by nothing until 23C. The package form is
verifiable by eye, and an activation after this phase still pays only the referral ladder.

**Effort.** 1.0 ideal day. **Suite.** ≥ 1,770 cases.

**Reference.** §12.4 (the settings), §13.2 (the package columns), Appendix A (the point values).

**Deliverables**

**Code**

- `system/app/Models/Package.php` — `validate()` gains `binary_enabled` (`in:0,1`) and `binary_points`
  (`required|integer|gte:0|lte:1000000`) with the cross-field rule "`binary_enabled = 1` with
  `binary_points = 0` is refused"; `save()` writes both; `rowsFor()`/`all()`/`publicCards()` select them;
  `binaryStatusMap(array $ids)` for the list (one query).
- `system/app/Controllers/AdminPackageController.php` — `save()` reads the two fields, merges the rules,
  audits `package.binary.update` (before/after) only when something changed; `create()` pre-fills the
  switch **off** and the points `ROUND(product_value)` as a *suggestion* (the posted value wins);
  `index()` accepts `?binary=on|off`.
- `system/app/Controllers/AdminSettingsController.php` — `PACKAGE_BINARY_FIELDS` /
  `PACKAGE_BINARY_TOGGLES`, merged into `sectionKeys()`, `sectionToggles()`, `fieldRules()`,
  `fieldLabels()` and `settingsContext()` (`package_binary_fields` / `package_binary_toggles`). Section
  stays `commissions`, under the product-binary block.
- Views: `admin/packages/_binary.php` (new), `admin/packages/form.php` (include after the referral
  ladder), `admin/packages/_rows.php` (badge), `admin/packages/index.php` (the filter),
  `admin/settings/_commissions.php` (the package sub-block and its readiness panel).
- `system/public/assets/js/app-package-binary.js` (new) — the live readout: points per activation, the
  pair equivalent at the current preset (`floor(points / PP)`) and the money it can complete
  (`× pair value`), plus the two readiness links. Progressive only.

**Readiness panel**

| Count | Source | Copy |
|---|---|---|
| Packages flagged with 0 points | `packages` | *"N package(s) are flagged for the binary but contribute 0 points. Set points or switch them off."* → `/admin/packages?binary=on` |
| Packages with the switch off | `packages` | *"N package(s) do not contribute to the binary."* → `/admin/packages?binary=off` |
| Global switch | `settings` | *"The package stream is off. No activation places points."* |
| Preset sentence | computed | *"One pair is 1,000 points and pays ₱100.00 at 10%. A 1,000-point activation completes one pair."* |

**Docs.** QA pack section B (`QA23B-01` … `QA23B-06`). `system/README.md` gains the package card.
`docs/deploy.md` gains the two keys and the readiness panel. `docs/admin-guide.md` gains "Flagging a
package for the binary".

**Not yet.** No hook, no volume, no award. An activation of a binary-flagged package pays only the
referral ladder.

**Rules**

1. **Two fields, one card.** The switch and the points; the pair size, rate, cap and carry are the shared
   ones and are **not** editable on a package.
2. **The points suggestion never saves itself.** The create form shows `ROUND(product_value)` as a
   placeholder/prefill, but the switch is off and the operator saves what they mean (D-13).
3. **Points 0 with the switch on is refused** with a field error on `binary_points` (it is a
   configuration error, not a valid state).
4. **Points are whole numbers**, 0–1,000,000; a decimal is refused.
5. **The two global keys**: `package_binary_enabled` (master, off) and `package_binary_requires_package`
   (on). `package_binary_ledger_since` is never rendered.
6. **The badge never lies.** `Binary 1,000 pts` on; `Binary 0 pts` on-with-zero (visible error); nothing
   when off.
7. **The readiness panel is read-only.** Links only, no actions.
8. **The form's save is atomic**; a refused package leaves the row untouched.
9. **No package rate fields** anywhere in the HTML (`P23B-T12`).
10. **The admin audit** records only real changes.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P23B-T1 | Save Package A with `binary_enabled = 1`, `binary_points = 1000` | Persisted; the form re-reads both |
| P23B-T2 | Save `binary_enabled = 1`, `binary_points = 0` | Field error on `binary_points`; row unchanged |
| P23B-T3 | Save `binary_enabled = 0`, `binary_points = 0` | Accepted |
| P23B-T4 | `binary_points = 1000.5` | Refused (integer) |
| P23B-T5 | `binary_points = 1000001` | Refused |
| P23B-T6 | Create form default | Switch off; the points field suggests `ROUND(product_value)` but does not save until submitted |
| P23B-T7 | `package.binary.update` audit | Written only on change, with before/after |
| P23B-T8 | List badge | `Binary 1,000 pts`, `Binary 0 pts`, nothing |
| P23B-T9 | `?binary=on\|off` filter | Correct rows; survives pagination; combines with `?status=` |
| P23B-T10 | `binaryStatusMap()` for 10 packages | One query |
| P23B-T11 | Settings card | The master switch and the qualification toggle under the product-binary card; `package_binary_ledger_since` not rendered |
| P23B-T12 | Package form HTML | The switch and points; **no** pair-size, pair-rate, cap or hold input |
| P23B-T13 | Readiness panel | The four counts match SQL; the links filter correctly |
| P23B-T14 | Readiness preset sentence | Recomputes when the shared pair values change |
| P23B-T15 | Approve an activation of a binary-flagged package after this phase | Only the referral commissions move; no `binary_awards`/`binary_volume` row; `binary_points` on the activation stays 0 |
| P23B-T16 | The full suite | Green; `InstallTest` still 42/92 |
| P23B-T17 | `git diff --stat` | Only the 23B manifest; `routes.php` untouched |
| P23B-T18 | A package edit that only changes its name | No `package.binary.update` row |

**Manual gate — `QA23B-01` … `QA23B-06`**

1. `QA23B-01` — open Package A's edit page: the *Package binary* card shows the switch off, the points
   field empty/0, and the readout at the shared preset.
2. `QA23B-02` — set points 1,000 and the switch on; save. Re-open: both persist; the badge reads
   `Binary 1,000 pts`.
3. `QA23B-03` — switch on with points 0 and save: the form returns the field error; the package keeps its
   old values.
4. `QA23B-04` — `/admin/packages?binary=on`: only the flagged package; the readiness panel counts match.
5. `QA23B-05` — `/admin/settings` → Commissions: the package block sits under the product block; the
   master is off; save with the master on and the qualification toggle off; the audit desk shows it.
6. `QA23B-06` — approve an activation: no binary row appears anywhere; the referral ladder pays as before.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P23B-T1 … P23B-T18 pass
[ ] The card, the badge and the readiness panel render on desktop and mobile
[ ] No pair/rate/cap input exists on the package form
[ ] An activation still writes no binary row (P23B-T15)
[ ] Tag p23b-configuration; phase log; Appendix H row
```

**Rollback**

Switch every package off and `package_binary_enabled = 0`, then revert the code. The points and the flags
may stay: inert, reusable. No money exists to reverse.

**Hand-off to 23C**

- `packages.binary_enabled` / `binary_points` exist, validated and visible.
- `package_binary_enabled` / `package_binary_requires_package` exist and are editable.
- The constants and helpers the engine needs (`Package::binaryEnabled()`, `binaryPoints()`,
  `BinaryAward::SOURCES`) are in place.
- Nothing writes a package row yet.

**Risks**

| Risk | Mitigation |
|---|---|
| An operator sets huge points and starts a large liability | The readiness sentence shows the pair equivalent and the money; the points are off by default |
| A per-package rate creeps in | P23B-T12 asserts the HTML |
| The suggestion is saved without consent | The create form ships the switch off; T6 |
| The readiness panel becomes a button | Rule 7, T13 |

---

## 7. Phase 23C — Money engine

**Goal.** An approved activation of a binary-enabled package contributes its points **once**, on the
activating member's side, to every binary upline; the pairs those points complete are paid **immediately**
to the withdrawable e-wallet; a pair recipient must hold a binary package; and a support CLI can reverse a
mistaken activation. This is the only phase that moves package money.

**Builds on.** 23A (the columns), 23B (the configuration) and Phase 22's `BinaryService` (the pair engine,
the ledger, the wallet and the audit).

**Ships alone because.** Money is correct and complete at `p23c-money`: every award is a real wallet credit
with a `binary` ledger row, the points audit proves every balance, and the product stream is untouched. The
pages of 23D/23E only describe it.

**Effort.** 2.5 ideal days. **Suite.** ≥ 1,820 cases.

**Reference.** §12 in full, §13.4–13.5 (the rows), §14 in full (the delta), and Phase 22 §14.9 (the pair
algorithm), §16.4–16.7 (idempotency, transactions, locking, eligibility).

**Deliverables**

**Database.** None. 23A shipped every column.

**Code**

- `system/app/Services/PackageBinaryService.php` (new) — the package rules:
  `onActivation(int $activationId, ?PDO $pdo = null): array`,
  `quoteActivation(int $packageId, int $memberId): array`,
  `reverseActivation(int $activationId, array $options = []): array`,
  `qualifies(int $userId, ?PDO $pdo = null): bool`, `totalsFor(int $activationId): array`,
  `blockedMap(int $userId): array`, `enabled(): bool`, `requiresPackage(): bool`.
  It owns the event, the points and the qualification; it calls `BinaryService` for the volume, the pair
  settlement, the wallet and the caches.
- `system/app/Services/PackageBinaryException.php` (new) — `notPlaced()`, `notApproved()`,
  `packageDisabled()`, `alreadyReversed()`, same shape as `UnilevelException`.
- `system/app/Services/BinaryService.php` — **the four amendment points**, each behind a `source`
  parameter so `source = 'order'` is unchanged:
  1. `applyVolume()` accepts an activation reference (no order/item) and writes `kind = 'team'` rows only
     when the package path calls it with `teamOnly = true`;
  2. `settleNode()` gains `bool $creditImmediately` — the pair award is written `credited`, `hold_days = 0`,
     and the wallet is credited in the same transaction;
  3. `eligibilityMap()` gains `string $stream` — for `'package'` it adds the package qualification and
     skips the monthly Active check;
  4. `audit()` gains the activation-source checks (23F finishes the report; 23C ships the invariants the
     tests need).
- `system/app/Services/ActivationService.php` — in `complete()`, after `CommissionService::onActivation()`
  and before `Activation::markCommissionProcessed()`:
  `$binary = (new PackageBinaryService($this->pdo))->onActivation($activationId, $this->pdo);`
  and the result is added to the returned summary and to the `member.activate` audit meta. A thrown
  exception rolls the whole activation back.
- `system/app/Models/Activation.php` — `markBinaryProcessed(int $id, int $points, float $total, ?PDO)`,
  `binarySummary(int $id)`, and the three columns in the default select.
- `system/app/Models/Package.php` — `binaryEnabled(int $packageId)`, `binaryPoints(int $packageId)` (the
  helpers 23B declared now query).
- `system/app/Models/BinaryAward.php` / `BinaryVolume.php` — `createForActivation()`, `forActivation()`,
  `sumBySource()`; `normaliseFilters()` gains `source` (23D renders it).
- `system/app/Controllers/MembershipController.php` — `membershipContext()` passes the member's node and,
  when it is missing and the package is binary-enabled, the placement block's data; `activate()` passes
  the posted placement to `BinaryPlacementService::placeNew()` **inside the activation's transaction,
  before `complete()`**. A missing/invalid placement is a field error, not a generic failure.
- `system/app/views/member/membership/_placement.php` (new) — the placement block on the activation page
  (upline + side, the same fields and rules as the register form). No new route.
- `system/bin/package_binary_reverse.php` (new) — `--activation=<id>`, `--apply` (default dry run),
  `--reason=`, `--json`. The only correction path (D-11).
- `system/bin/wallet_reconcile.php` — the Product binary section's queries become source-aware and include
  activation awards. The section's display name is 23D's.

**Docs.** QA pack section C (`QA23C-01` … `QA23C-08`). `docs/runbook.md` gains the reversal and the
"why did this activation pay nothing?" walk. `system/README.md` gains the engine and the source.

**Not yet.** No source column in the admin or member ledger (23D/23E), no package card, no dashboard tile,
no tree chip, no `APP_PHASE` change.

**Rules**

1. **One event, one transaction.** `onActivation()` runs inside `ActivationService::complete()`; it never
   opens its own transaction and never credits outside it.
2. **The anchor is the activation stamp.** `activations.binary_processed_at` is written in the same
   transaction; a second call returns `replayed`. `commission_processed_at` independently bails the whole
   completion, so the two anchors agree.
3. **Only an approved activation fires.** `pending_payment`, `pending_review`, `rejected` and `cancelled`
   write nothing; a `complete()` re-run on an approved activation is a replay.
4. **Eligibility is read at approval**, not at placement: the package's switch and points, the global
   switch and the qualification setting at that instant. A later change affects later activations.
5. **The package must be eligible**: `binary_enabled = 1`, `binary_points > 0`, the global switch on.
   Otherwise the activation is stamped with zeros and places nothing.
6. **The member must be placed.** No node → the activation is refused with a placement field error when
   the package is binary-enabled (the page collects it); a direct service call without a node stamps
   zeros with `reason = 'not placed'` and writes no volume.
7. **Team-only volume.** One `team` row at every ancestor (`source = 'activation'`), **no `own` row**, **no
   self award** (D-04). The buyer's `personal_points` and their node's balances are unchanged by their own
   activation.
8. **The side is the node's path.** For each ancestor, the side is the character of the member's `path` at
   that depth — no choice, no default, no request value.
9. **The pairs are Phase 22's** (D-01/D-09): the same `PP`, `pair_pct`, daily cap, carry, greedy
   settlement, order (parent → root), company-root exclusion and no-consumption-on-skip.
10. **The qualification gate.** With `package_binary_requires_package = 1` a recipient must hold a package
    with `binary_enabled = 1`; the monthly Active rule is **not** consulted for package pairs (D-08).
11. **Immediate credit.** A package pair award is `credited` at creation, `hold_days = 0`, with one
    `binary` wallet movement inside the transaction. The release job must never see it as pending.
12. **A pair is one award per node per activation**, `UNIQUE (activation_id, user_id, kind)`, exactly like
    a product order's batch.
13. **Points are integers and money is centavos** (Phase 22 §14.11); a zero award is not written; zero
    points place nothing.
14. **The product stream is byte-identical.** `source = 'order'` rows and every product award are unchanged
    (§14.7).
15. **A failure propagates.** A wallet failure rolls back the activation, the volume and the stamp.
16. **The reversal CLI is the only correction path**, dry-run by default, audited, idempotent.
17. **No upgrade, no delta, no repeat.** One volume per activation row (D-19).

**Test plan**

Fixture: Phase 22 §18.2's tree (`admin` root, `boss`, `a`, `b`, `c`, `buyerA`, `buyerB`, `sideR`, `d`) and
three packages: `PKA` (1,000 points, enabled), `PKB` (0 points, enabled), `PKC` (1,000 points, **disabled**).
Members hold `PKA` unless a case says otherwise. Preset: PP 1,000, pair 10% (₱100), cap 20, qualification
on, product stream as it ships.

**The event and its paths**

| ID | Test | Expected |
|---|---|---|
| P23C-T1 | `buyerA` activates PKA (1,000 points) | Five `team` rows: `c`, `b`, `a`, `boss`, root on the left; **no own row** at `buyerA`; 0 awards; `activations.binary_points = 1000`, `binary_total = 0.00`, `binary_processed_at` set |
| P23C-T2 | Then `buyerB` activates PKA | `c` L 1,000 / R 1,000 → **1 pair, ₱100.00 to `c`**, `credited`, wallet +100.00, one `binary` ledger row; `c`'s pair rows consume 1,000 each side |
| P23C-T3 | A manual (GCash) activation approved by an admin | Identical to the e-wallet path, created once at approval |
| P23C-T4 | Registration with a `code` for PKA | The hook fires inside the registration transaction; same rows |
| P23C-T5 | Registration with the e-wallet for PKA | Same |
| P23C-T6 | Wallet purchase for a pending member (`walletPurchase`) | Same, once |
| P23C-T7 | `PKB` (0 points) | No volume, no award, stamped with zeros; the referral ladder pays |
| P23C-T8 | `PKC` (switch off) | Same |
| P23C-T9 | Global `package_binary_enabled = 0` | Same |
| P23C-T10 | A rejected activation | Nothing; no stamp |
| P23C-T11 | A `pending_review` activation | Nothing; no stamp |
| P23C-T12 | A replay of `complete()` on an approved activation | `replayed`; no second row, no second credit |
| P23C-T13 | A staff account activating | No node → no volume; the referral ladder unchanged |

**The routing and the team-only rule**

| ID | Test | Expected |
|---|---|---|
| P23C-T14 | `buyerB` (path `LLLLR`) activates | Right at `c`; left at `b`, `a`, `boss` and the root — one row per ancestor, sides from the path |
| P23C-T15 | Ten-deep chain, 1,000 points, 1,000 right pre-filled at every node | 10 pairs (the parent and nine ancestors; the root excluded), ₱1,000.00, one award per node |
| P23C-T16 | `buyerA.personal_points` after their own activation | Unchanged (0) |
| P23C-T17 | `buyerA`'s own balances | Unchanged (0/0) |
| P23C-T18 | A member with no node (direct service call) | No volume; stamped with zeros; `reason = 'not placed'` |
| P23C-T19 | The company root's balances | Volume recorded; **no award, ever** |
| P23C-T20 | The root's award count in `binary_awards` | 0 after any number of activations |
| P23C-T21 | An activation whose package is switched off between approval and completion | The flags are read inside the transaction; nothing is placed and the stamp records zeros |

**The qualification gate**

| ID | Test | Expected |
|---|---|---|
| P23C-T22 | `c` holds PKC (disabled) | No pair at `c`, **no points consumed**, the volume stays 1,000/1,000; no award; `blockedMap()` names "package not binary-enabled" |
| P23C-T23 | `c` holds PKB (0 points, enabled) | `c` qualifies (the gate is the switch); the pair pays |
| P23C-T24 | `package_binary_requires_package = 0`, `c` on PKC | The pair pays |
| P23C-T25 | `c` is `is_active = 0` | No pair, no consumption, the shared account reason |
| P23C-T26 | `c` is `suspended` | Same with the membership reason |
| P23C-T27 | `c` is not Active this month while `binary_requires_active = 1` | The pair **pays** (the Active rule is a product-stream rule) |
| P23C-T28 | `c` qualifies later and a new activation occurs | The carried 1,000/1,000 pairs then |
| P23C-T29 | `c`'s package is switched off after an earlier paid pair | The old award is untouched; a new pair at `c` is blocked |

**The money, the cap and the wallet**

| ID | Test | Expected |
|---|---|---|
| P23C-T30 | The pair award row | `kind = 'pair'`, `status = 'credited'`, `hold_days = 0`, `credited_at`/`ledger_id` set, `source = 'activation'`, `activation_id` set, `order_id = NULL` |
| P23C-T31 | The wallet movement | Withdrawable +100.00, `ref_type = 'binary'`, `ref_id = award.id`, exactly one row |
| P23C-T32 | `unilevel_release --stream=all` | Ignores the credited package award; no second movement |
| P23C-T33 | Cap 1, two pairs due | 1 paid, the second's points carry; the next day's activation pays it |
| P23C-T34 | The cap is shared with the product stream | A product pair already paid today consumes the same day's room |
| P23C-T35 | `pair_pct = 0` | The pair consumes its points, no award row, `binary_total = 0.00` |
| P23C-T36 | Rounding: 3 pairs at 7.5% of 1,000 | ₱225.00 (`75.00 × 3`) |
| P23C-T37 | `quoteActivation()` | Points, the pairs the current balances would complete and the money; no writes |

**Idempotency, transaction and the product regression**

| ID | Test | Expected |
|---|---|---|
| P23C-T38 | `onActivation()` twice | `replayed`; identical rows |
| P23C-T39 | Duplicate `(activation_id, user_id, kind)` | `PDOException` 23000 |
| P23C-T40 | A wallet failure during the credit | The activation rolls back: no status change, no award, no volume, no wallet row |
| P23C-T41 | Two activations racing on the same ancestor | Node locks ascending; both commit; balances exact |
| P23C-T42 | **Product regression**: a paid order with both streams on | The product rows and amounts are identical to `p22i-release`, all `source = 'order'`; the package rows are additive |
| P23C-T43 | `wallet_reconcile.php` | Exit 0; the binary section totals both sources; deleting a package award's ledger row makes it non-zero |
| P23C-T44 | `binary_audit.php --check` | Exit 0; every activation award has its activation and its consumption rows |

**The reversal CLI**

| ID | Test | Expected |
|---|---|---|
| P23C-T45 | `--activation=<id>` (dry run) | Prints the awards, points and wallet effect; writes nothing; exit 0 |
| P23C-T46 | `--apply` | Awards `voided`, wallet debited up to the withdrawable balance, `reversed_amount` recorded, `binary_reversal` movement, volume reversed at every node, `reversal` rows written, the activation's totals recomputed |
| P23C-T47 | Reversal with a spent wallet | Partial clawback; the shortfall visible; no negative balance |
| P23C-T48 | Reversal twice | The second is a no-op; no duplicate rows |
| P23C-T49 | Reversal of an activation whose points were consumed by a **later** activation's pair | Pair-return rows restore the consumed points; the audit is clean |
| P23C-T50 | Reversal of a never-approved activation | Refused: nothing was paid |

**Manual gate — `QA23C-01` … `QA23C-08`**

1. `QA23C-01` — configure PKA (1,000 points, on), the global switch on, and approve `buyerA`'s activation:
   `SELECT * FROM binary_volume WHERE source='activation'` shows five `team` rows and no `own` row;
   `binary_awards` has none.
2. `QA23C-02` — approve `buyerB`'s activation: one `pair` award at `c`, ₱100.00, `credited`; `c`'s
   withdrawable wallet is up ₱100.00 with `ref_type = 'binary'`; `binary_audit --check` exits 0.
3. `QA23C-03` — run `unilevel_release.php --stream=all`: nothing new is released; the credited package
   award is untouched.
4. `QA23C-04` — put `c` on a non-binary package and create two activations: no pair at `c`, no points
   consumed; the audit's blocked pair names the package reason.
5. `QA23C-05` — set the cap to 1 and create two pairs in a day: one pays, one carries; the next day a new
   activation pays the carry.
6. `QA23C-06` — pay a product order too: the product rows have `source = 'order'`; the totals match the
   pre-23C behaviour plus the package credit only.
7. `QA23C-07` — `php bin/package_binary_reverse.php --activation=<id>` (dry run), then `--apply`: the
   award is voided, the wallet debited (or the shortfall recorded), the volume reversed; the audit is
   clean; run it again: no change.
8. `QA23C-08` — approve an activation of a 0-point package and of a disabled package: both stamp zeros;
   the referral ladder is unaffected.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P23C-T1 … P23C-T50 pass
[ ] `source = 'order'` rows and every product award are byte-identical to p22i-release (T42)
[ ] No package award is ever `pending` (T30); the release job ignores them (T32)
[ ] No own row and no self award exists for any activation (T16/T17)
[ ] The company root has no package award (T19/T20)
[ ] `wallet_reconcile` and `binary_audit --check` exit 0 and fail on a seeded defect
[ ] Tag p23c-money; phase log; Appendix H row
```

**Do not start 23D, 23E or 23F until every box is ticked.**

**Rollback**

Set `package_binary_enabled = 0` — new activations stamp zeros and place nothing immediately. Only then
revert the code. Awards already credited stay in the wallets and the ledger: they are real money, and a
code rollback does not delete them. A decision to unpay is a business decision executed by the reversal
CLI, not by a rollback.

**Hand-off to 23D/23E/23F**

- Every activation award is a row with a source, a wallet link and a `credited` status, ready to render.
- `activations.binary_points`/`binary_total` give an activation's page its headline.
- `PackageBinaryService::qualifies()` and `blockedMap()` explain a non-payment without a skipped row.
- `quoteActivation()` gives the package form and the activation page their preview.
- The reversal CLI and the audit are the operational envelope for 23F.

**Risks**

| Risk | Mitigation |
|---|---|
| A double credit from a replayed completion | The activation stamp + `commission_processed_at` + the unique key + the wallet anchor (T12/T38) |
| A product regression hidden by the amendment | T42 and the Phase 22 suite |
| A package award left `pending` by the shared engine | The immediate flag is mandatory on the package path; T30/T32 |
| The qualification gate skipped | One eligibility map with the `stream` argument; T22–T29 |
| A wallet failure half-completes the activation | One transaction; T40 |
| A reversal leaks points back into a paid pair | Pair-return rows; T49 and the audit |
| An operator changes the points mid-day | Read inside the approving transaction; the award snapshots its own rate |
---

## 8. Phase 23D — Admin visibility

**Goal.** Finance can see, filter and export every package point and package award beside the product ones,
open the activation that produced them, read the member's binary panel split by source, and read three
report cards. **Read-only.**

**Builds on.** 23C (the rows) and 22E (the pages).

**Ships alone because.** It is presentation over rows 23C already writes; if 23E/23F never shipped, the
operator still has a complete audit tool.

**Effort.** 1.0 ideal day. **Suite.** ≥ 1,840 cases.

**Reference.** Phase 22 §9 (the ledger/card conventions), 23C's read APIs.

**Deliverables**

- `system/app/Controllers/AdminCommissionController.php` — `?ledger=binary&source=activation|order`; the
  export carries the source column and audits `binary.export` unchanged.
- `system/app/Models/BinaryAward.php` — `normaliseFilters()` gains `source`; `adminList()`, `stats()`,
  `csvRows()` split by source.
- Views: `admin/commissions/_binary_header.php` (the source split in the stats),
  `_binary_rows.php` (a Source column), `_binary_filters.php` (the source select), new
  `admin/activations/_binary.php` (the activation's points, pairs, wallet links) and
  `admin/activations/index.php`/`proof` include it; `admin/members/_binary.php` gains the source split
  (product vs package points, pairs and earnings, plus the qualification state).
- `system/app/Models/Report.php` — `packageBinarySummary()`, `packageBinaryByPackage()`,
  `packageBinaryByActivation()`.
- `system/app/Services/ReportService.php` — the three cards and the `source` column in the range export.
- Views `admin/reports/_binary_packages.php`, `_binary_activations.php`; `admin/reports/index.php` includes
  them.

**Docs.** QA pack section D (`QA23D-01` … `QA23D-07`). `docs/admin-guide.md` gains the source filter and
the activation card.

**Not yet.** No member-facing change; the member tab still shows the product-only shape until 23E.

**Rules**

1. Read-only; no award, volume, wallet or activation is written.
2. The product rows and the product cards are **byte-identical** to `p22i-release` except the added Source
   column/filter (P23D-T1 pins the package/product split, not the whole page).
3. The Source column is never hidden when the tab has both sources.
4. Points and money are shown side by side and never added (Phase 22 D-… §9 Rule 4).
5. A blocked package pair is a count with a reason, never a money row.
6. Filters fall back silently; an unknown `source` means all.
7. Every row links to its activation (or its order) and its wallet movement.
8. The activation card shows the **outcome**: points placed, pairs paid, the money, and the reason when
   nothing was placed.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P23D-T1 | The product rows/filters/export | Identical to `p22i-release` plus the source column |
| P23D-T2 | `?source=activation` / `order` | Correct rows; `?source=` all; an unknown value falls back |
| P23D-T3 | Stats | Credited/pending split by source; package pairs; package points |
| P23D-T4 | CSV | A Source column; the package rows exported with the activation number |
| P23D-T5 | The activation card | Points, pairs, money, the status, the wallet links; a 0-point package explains why |
| P23D-T6 | The member panel split | Product vs package points/pairs/earnings; the qualification state (binary package or not) |
| P23D-T7 | The three report cards | Totals match SQL; the range export includes both sources |
| P23D-T8 | A member opening the admin tab | 403 |
| P23D-T9 | `binary_audit` and `wallet_reconcile` | Unchanged exit codes; the reports never write |
| P23D-T10 | The empty state | Names the package switch, the activation requirement and the points |

**Manual gate — `QA23D-01` … `QA23D-07`**

1. `QA23D-01` — `/admin/commissions?ledger=binary`: the Source column shows *Repeat purchase* and
   *Package activation*; the stats split both.
2. `QA23D-02` — filter `source=activation`: only the package rows; filter `kind=pair`: both sources' pairs.
3. `QA23D-03` — open an activation: the card shows 1,000 points placed, the pair paid, the wallet link.
4. `QA23D-04` — open the member panel: the split reads package 1,000 points, product 0; the qualification
   says *binary package*.
5. `QA23D-05` — export the filtered CSV: the source column and the activation number are present.
6. `QA23D-06` — `/admin/reports`: the three package cards match the ledger; the range CSV includes both.
7. `QA23D-07` — switch to the package/repeat/royalty tabs: unchanged.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P23D-T1 … P23D-T10 pass
[ ] The product rows and the other tabs are unchanged except the source column
[ ] No new route; ParityTest green
[ ] Tag p23d-admin; phase log; Appendix H row
```

**Rollback**

Revert the views and the filter. The data is untouched.

**Hand-off to 23E/23F**

- The source vocabulary, the activation card and the member split define what the member page reuses.
- The report cards define the three numbers the release note quotes.

**Risks**

| Risk | Mitigation |
|---|---|
| The source column breaks an old export consumer | The header is additive; the docs note it |
| The package split is summed into a product total | T3; the two are separate columns |
| The activation card implies a payment that did not happen | Rule 8; T5 |

---

## 9. Phase 23E — Member visibility

**Goal.** The member's binary page becomes **Binary bonus** with a **Package activations** card beside the
repeat-purchase cards, a source split in the stats and the awards, and a qualification line; the dashboard
tile and the tree chip show the package state. **No uplines are named (Phase 22 D-22 holds).**

**Builds on.** 23C (the rows) and 22F (the page).

**Effort.** 1.0 ideal day. **Suite.** ≥ 1,860 cases.

**Reference.** Phase 22 §10, §14.12 (copy rules), Appendix C of both plans.

**Deliverables**

- `system/app/Controllers/EarningsController.php` — `?ledger=binary&source=…`; the package totals and the
  package awards.
- Views `member/earnings/_binary_package.php` (new: qualification, the package's points, the package
  pairs and the last activation), `_binary_stats.php` (source split), `_binary_rows.php` (Source column),
  `member/earnings.php` (the tab label and the includes), `member/dashboard/_binary.php` (the package
  line), `member/genealogy/_tree.php`/node partials (the package chip).
- `system/app/Controllers/DashboardController.php`, `GenealogyController.php`/`BinaryTreeService` — the
  package state in the payload.

**Docs.** QA pack section E (`QA23E-01` … `QA23E-06`). Appendix C strings.

**Rules**

1. **Downward-only copy** (Phase 22 D-22): the package card says *your activation placed N points in your
   tree*, never who earned them.
2. The tab is renamed **Binary bonus**; the URL is unchanged.
3. The package card is absent when the member has no binary-enabled package and no package activity.
4. Points carry *pts*; money carries the currency.
5. The unplaced and stream-off states stay honest (Phase 22 §14.12).
6. The product cards and the Left/Right team cards keep their Phase 22 structure; the source split is
   additive.
7. No JavaScript-only behaviour; the source filter is a normal GET.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P23E-T1 | `/earnings?ledger=binary` | The tab reads **Binary bonus**; the package card, the source split and the awards with a Source column |
| P23E-T2 | The package card | The package name, `binary_points`, the pairs and money earned from package activations, the last activation's date |
| P23E-T3 | Copy guard | No upline/sponsor/"Team chain"/"Level" on the tab; the package card says "in your tree" |
| P23E-T4 | A member with no binary package | The card is absent; the page still works |
| P23E-T5 | `?source=activation` | Only package rows; the stats stay whole (balances are not filtered) |
| P23E-T6 | The dashboard tile and the tree chip | The package state and the package pairs appear; the tree node shows the package name |
| P23E-T7 | Stream off | No package card, no chip; the tab falls back to the package tab |
| P23E-T8 | The product-only member | The page is the Phase 22 page plus the split (which is 0/0) |
| P23E-T9 | JS off and mobile | The filter, the cards and the pagination work |

**Manual gate — `QA23E-01` … `QA23E-06`**

1. `QA23E-01` — as `c` after two activations, open `/earnings?ledger=binary`: the package card shows
   Package A, 1,000 points, 1 pair, ₱100.00; the stats split product 0 / package 100.
2. `QA23E-02` — read the HTML: no upline, no sponsor, no "Team chain", no "Level".
3. `QA23E-03` — filter `source=activation`: only the package pair; the Left/Right cards are unchanged.
4. `QA23E-04` — open `/dashboard` and `/genealogy?view=binary`: the tile and the node chip show the
   package.
5. `QA23E-05` — a member on a non-binary package: no package card; the product page still works.
6. `QA23E-06` — 390 px: the cards stack; no horizontal scroll.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P23E-T1 … P23E-T9 pass
[ ] The copy guard passes on the rendered page
[ ] The product cards and the team cards keep their structure
[ ] Tag p23e-member; phase log; Appendix H row
```

**Rollback**

Revert the views; the URL and the data are untouched.

**Hand-off to 23F/23G**

- The member sees the package outcome; the operations phase is the last code change.

**Risks**

| Risk | Mitigation |
|---|---|
| The package card names the uplines | T3 |
| The split confuses one balance | The stats label the total and the two sources; the Left/Right cards remain the balance |
| The rename breaks a 22F snapshot | The rename is the amendment; the case is updated in 23E |

---

## 10. Phase 23F — Operations

**Goal.** The points audit checks the activation rows, the doctor reports the package stream's state, and the
runbook documents the reversal and the no-payment reasons. **No money rule changes.**

**Builds on.** 23C.

**Effort.** 1.0 ideal day. **Suite.** ≥ 1,872 cases.

**Deliverables**

- `system/bin/binary_audit.php` — the activation-source checks: an activation award with no activation; an
  activation `binary_processed_at` with no rows and points > 0; an activation's stored points vs its volume
  rows; a package award `pending` (impossible); a package pair with missing consumption; the activation
  total vs its live awards; a package point row on a disabled package. `--fix` still touches only caches.
- `system/app/Services/HealthService.php` — three checks: package readiness (switch, flagged packages with
  0 points), package escrow (must be zero pending), package totals (credits vs the ledger).
- `docs/cron.md` — no new job; a note that the package stream has no release.
- `docs/runbook.md` — "a package activation paid nothing", "reverse a package activation", "the audit
  reports a package drift".
- QA pack section F (`QA23F-01` … `QA23F-05`).

**Rules**

1. The audit is read-only by default; `--fix` only rewrites `binary_nodes` caches.
2. Every check has a runbook remedy.
3. A `pending` package award is a **failure**, not a warning.
4. The reversal CLI's dry run is documented as the first step of every correction.
5. No new cron entry.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P23F-T1 | Audit on a clean package fixture | Exit 0 |
| P23F-T2 | A package award with a deleted activation | Exit 1; the award is named |
| P23F-T3 | A stamped activation with points but no rows | Exit 1 |
| P23F-T4 | A `pending` package award | Exit 1; the award is named |
| P23F-T5 | Health pack | The three checks `pass` clean, `warn` on 0-point packages, `fail` on drift |
| P23F-T6 | Runbook commands | Every command runs as written |
| P23F-T7 | `--fix` | Repairs a cache; re-check clean; awards untouched |

**Manual gate — `QA23F-01` … `QA23F-05`**

1. `QA23F-01` — `php bin/binary_audit.php --check` on the demo: 0.
2. `QA23F-02` — delete an activation award's activation by hand (or set `activation_id` NULL via a scratch
   copy): the audit names it; the site still works.
3. `QA23F-03` — open the health panel: the three package checks and their remedies.
4. `QA23F-04` — the runbook's reversal steps: dry run, apply, audit.
5. `QA23F-05` — run the audit during business hours: no lock timeout.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P23F-T1 … P23F-T7 pass
[ ] Every new check has a remedy and a case
[ ] Tag p23f-operations; phase log; Appendix H row
```

**Rollback**

Revert the CLI checks and the health card; they are read-only.

**Hand-off to 23G**

- The stream is provable and correctable; the release can be signed.

**Risks**

| Risk | Mitigation |
|---|---|
| A package drift is invisible | The audit's activation checks + the health pack |
| A reversal is run without a dry run | The runbook and the CLI default |

---

## 11. Phase 23G — Release

**Goal.** Documentation that matches the code, a QA pack a stranger can execute, `APP_PHASE 23`, a signed
acceptance run and a phase log. **No behaviour change in this phase.**

**Builds on.** All of 23A–23F.

**Effort.** 1.0 ideal day. **Suite.** 1,872 cases.

**Deliverables**

- `system/app/config.php` — `APP_PHASE` **23** (D-17) and the phase comment.
- `system/tests/Cases/HardeningTest.php` — asserts 23.
- `system/tests/Cases/PackageBinaryReleaseTest.php` (new) — the phase, the counts (42/92), the source
  columns, the manifest, the copy guard and the immediate-credit invariant.
- `docs/qa/phase-23-package-binary.md` — A–F complete, the acceptance run recorded, `QA23G-01` … `04`.
- `docs/qa/README.md`, `docs/qa/sign-off-sheet.md`, `docs/phase-log.md` (seven entries), the docs
  read-through, the two plan status lines, Appendix H.

**Rules**

1. No behaviour change; a defect discovered here goes back to its owning phase.
2. The cold read is mandatory.
3. The acceptance run is executed by someone who did not implement the package phase.
4. Counts are re-measured, not copied.
5. The amendment to the Phase 22 plan is named in the release note.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P23G-T1 | `APP_PHASE` | 23 in `config.php`, the suite header and `HardeningTest` |
| P23G-T2 | `PackageBinaryReleaseTest` | The seven tags' features; 42/92; the source columns; the one-extension inventory unchanged |
| P23G-T3 | The docs cold read | Every command runs; every key exists; every route exists |
| P23G-T4 | The acceptance run | Appendix B's 10 steps pass on the scratch database |
| P23G-T5 | The full suite and smoke | Green at the recorded counts |
| P23G-T6 | The QA pack | A–F present, unique IDs, evidence indexed |

**Manual gate — `QA23G-01` … `QA23G-04`**

1. `QA23G-01` — execute the QA pack on the scratch database.
2. `QA23G-02` — the acceptance run (Appendix B).
3. `QA23G-03` — the docs copy-paste (`binary_audit`, `wallet_reconcile`, the reversal dry run, the
   install check).
4. `QA23G-04` — the phase log, the sign-off sheet, the badges.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P23G-T1 … P23G-T6 pass
[ ] The acceptance run is 10 / 10
[ ] APP_PHASE === 23
[ ] All seven tags exist; the branch is merged
[ ] Tag p23g-release (feature tag phase-23); phase log; Appendix H
```

**Rollback**

Revert the constant and the docs; nothing behavioural changes.

**Hand-off**

The feature is **released**. The next stream starts from `p23g-release`. A change to the package points,
the qualification, the immediate-credit rule or the reversal is a new plan.

**Risks**

| Risk | Mitigation |
|---|---|
| `APP_PHASE` without `HardeningTest` | One commit |
| Stale counts | Re-measure |
| The Phase 22 plan amendment is forgotten | The release note names it; T2 |

---

# Part III — The shared contract

## 12. Specification (normative delta)

**Scope.** This section states only what the package stream adds or changes. Everything else — the tree,
the placement rules, the ledgers' shape and kinds, the pair algorithm, the cap and carry, the company-root
exclusion, rounding, the member copy rules and the data model — is **[Phase 22 §14–§16](plan-ecowealth-product-binary.md#14-specification-normative)**
and is cited, not repeated. A conflict between the two is a plan defect.

### 12.1 The delta in one table

| Area | Phase 22 (product stream) | This plan (package stream) |
|---|---|---|
| Money event | a paid shop order | an **approved activation** |
| Points source | `products.pv` × quantity | **`packages.binary_points`** per activation |
| Per-source switch | `products.binary_enabled` | **`packages.binary_enabled`** |
| Position | chosen at checkout, per order | **the member's existing node side** |
| Own row | yes (`own` at the buyer's node) | **no** (team-only) |
| Self bonus | yes (`binary_self_pct`) | **no** |
| Recipient qualification | shared eligibility | shared eligibility **+ the recipient's binary package** (when the setting is on) |
| Monthly Active rule | applies | **does not apply** |
| Money timing | escrow (`pending` → release job) | **immediate credit** (`credited`, no hold) |
| Reversal | order cancellation (a shipped action) | a **support CLI** (the app has no post-approval cancel) |
| Ledger source | `source = 'order'` | `source = 'activation'` + `activation_id` |
| Points rounding, cap, carry, pair value | Phase 22 §14.11, §14.10 | **identical** |

### 12.2 The event

1. **The event is an approved activation.** The package binary is written by
   `ActivationService::complete()`, inside the same database transaction that marks the member active,
   writes the activation's commissions and stamps `commission_processed_at` (D-02).
2. **One volume per activation row.** Each `activations` row contributes at most once; the
   `binary_processed_at` stamp makes a replay a no-op (D-19).
3. **Only a paid, approved activation pays.** `pending_payment`, `pending_review`, `rejected` and
   `cancelled` place nothing. `complete()` is the only path that approves.
4. **All four activation paths fire it** because they all go through `complete()`: registration with a
   code, registration with the e-wallet, a member's proof activation, an admin approval, and
   `walletPurchase()` (which reuses `complete()`).
5. **The flags are read inside the transaction.** The package's `binary_enabled`/`binary_points` and the
   global `package_binary_enabled` are read at approval; a change in another tab after approval cannot
   rewrite the activation, and a change before it applies.
6. **A rejection after approval does not exist in the app.** If a future action cancels an approved
   activation, it must call `PackageBinaryService::reverseActivation()` (D-11).

### 12.3 Points

1. **`points = packages.binary_points`** — a whole number, 0–1,000,000 (D-03).
2. **It is snapshotted** on `activations.binary_points` and on every volume row it produces.
3. **Zero points place nothing** and write no volume, no award and no wallet movement; the activation is
   still stamped (it was decided).
4. **The value is the operator's.** The create form suggests `ROUND(product_value)`; the seed ships 0 and
   the switch off (D-13, D-16, Appendix A).
5. **Points are not money** and are never converted except by the shared pair value (Phase 22 §14.3
   rules 3–6 apply).

### 12.4 Settings

Three new keys; the ten Phase 22 keys are unchanged and shared.

| Key | Rule | Default | Visible | Meaning |
|---|---|---|---:|---|
| `package_binary_enabled` | `in:0,1` | `0` | yes | the package stream's master switch |
| `package_binary_requires_package` | `in:0,1` | `1` | yes | a pair recipient must hold a package with `binary_enabled = 1` |
| `package_binary_ledger_since` | — | migration instant | **no** | the reconciliation baseline; activations before it are never awarded |

**Shared, not duplicated:** `binary_pair_points`, `binary_pair_pct`, `binary_daily_pair_cap`,
`binary_reversal_enabled` and the ten Phase 22 keys. `binary_requires_active` does **not** apply to
package pairs (D-08); `binary_hold_days` does **not** apply because there is no escrow (D-07).

### 12.5 The pair

1. **One pair size, one pair value, one cap, one carry.** The package stream adds no rate of its own
   (D-06): a pair formed from a package activation pays exactly what a pair formed from a product order
   pays, to the same recipient, at the same node.
2. **Only the points differ per package.** The package's economics are tuned by its `binary_points`
   (Appendix A's sensitivity table).
3. **The qualification gate** (D-08): when `package_binary_requires_package = 1`, a recipient must hold a
   package whose `binary_enabled = 1` **at the moment of the activation**. The gate is the switch, not the
   points: a member on a 0-point binary package still qualifies to earn.
4. **The monthly Active rule is not consulted** for a package pair. The recipient checks are: not the
   company root, `is_active = 1`, `member_status = 'active'`, and the package gate.
5. **Everything else is Phase 22 §14.9–§14.10**: greedy settlement, the parent-to-root order, the
   company-root exclusion, no consumption on a skip, the daily cap shared across sources, and carry
   forever.

### 12.6 Volume routing (team-only)

For an approved activation of points `P > 0` by member `B` with node `N_B`:

1. **No own row.** `B`'s own node receives nothing from their own activation (D-04).
2. **A team row at every ancestor** `A` of `B`: `(node = A, side = the side of the child on B's path at
   A.depth, kind = 'team', points = +P, source = 'activation', activation_id)`.
3. **The root receives volume and is never paired** (Phase 22 §14.2 rule 7, §14.9).
4. **One row per node per activation**; the unique key `(node_user_id, activation_id, side, kind)` makes a
   replay impossible.
5. **The buyer's `personal_points` is unchanged** — personal points are own purchases, and a package is
   not one for this purpose.
6. **The positions are not chosen here.** The side at every ancestor is a string read of `N_B.path`; the
   placement was chosen at registration (Phase 22 D-03) and is immutable once the subtree has volume.

### 12.7 The recipient

1. **Eligibility** is resolved once for the ancestor chain, in one batch, with
   `BinaryService::eligibilityMap(..., 'package')`: the company root is excluded; `is_active = 1`;
   `member_status = 'active'`; the package gate of §12.5 rule 3.
2. **No Active-month check.** `binary_requires_active` is a product-stream rule.
3. **A non-qualified node is skipped, not consumed.** Its volume stays and pairs later if it qualifies;
   `blockedMap()` reports it with a reason; the admin panel and the member card show it (Phase 22 D-13).
4. **The package's `binary_points` does not qualify a recipient** — only the recipient's own package
   switch does.
5. **A member with no node cannot activate a binary-enabled package**: the activation page collects the
   placement (Rule 6 of 23C), and a direct service call without a node stamps zeros with a reason.

### 12.8 Money

1. **Immediate credit** (D-07): the `binary_awards` row is written `credited` with `hold_days = 0` and
   `credited_at = NOW()`, and one `binary` wallet movement is written inside the activation's transaction.
2. **No escrow, no release job, no `pending` package award.** A pending package award is an audit failure
   (23F).
3. **The wallet anchor is the award id** (`ref_type = 'binary'`, `ref_id = award.id`), so a replay cannot
   credit twice even if the activation checks were bypassed.
4. **Withdrawable, not just spendable** — the money is paid like the Phase 10–11 package commissions.
5. **The company root is never a recipient**; the activating member is never a recipient of their own
   activation.
6. **A zero award is never written.**

### 12.9 Reversal

1. **Not an in-app action** (D-11); a future cancel action must call the service method.
2. **`bin/package_binary_reverse.php`**: dry run by default; `--apply` voids the activation's awards,
   claws back up to the withdrawable balance (`binary_reversal`), records the shortfall, returns consumed
   pair points (`pair_return` rows), writes `reversal` volume rows for every team row, reparses the node
   caches and recomputes the activation's `binary_*`.
3. **Idempotent**: a second run finds the awards voided and the reversal rows present; it changes nothing.
4. **Never pairs**: the returned points wait for the next activation (Phase 22 §16.9 rule 5).
5. **Audited** (`package_binary.reverse`) with the activation, the amount and the shortfall.

### 12.10 Rounding

Identical to Phase 22 §14.11: `unit` points are integers; `perPair = round(PP × pair_pct / 100, 2)`;
`amount = perPair × n`; self does not exist here; money is centavos. No float ever holds a point.

### 12.11 Member copy rules

Phase 22 D-22 and §14.12 apply. In addition:

1. **The tab is "Binary bonus"** (D-12), one page for one tree.
2. **The package card is about the member's own package and their own package pairs**, never about who
   earned from their activation:
   *"Your package: Package A · 1,000 points per activation"*,
   *"Package pairs earned: 2 · ₱200.00"*,
   *"Your activation placed 1,000 points in your tree."*
3. **No upline, sponsor, "Team chain" or level words** on the tab; the Placement is the tree's business.
4. **Points carry *pts*; money carries the currency.**
5. **The qualification line is honest:** *"Package pairs are paid to members with a binary package."*
6. **The stream-off state is absent, not disabled.**

### 12.12 Divergences from the reference package binary

| Reference (`dmp/cart-system`) | This plan | Why |
|---|---|---|
| volume and payout are the same number (`pairing_bonus`, 1:1 pesos) | volume is points; payout is the shared pair value | one balance cannot have two payout formulas (D-06) |
| per-package `daily_pair_cap × pairing_bonus` | one shared daily cap | the cap is a property of the member's day, not of the package that happened to fire |
| over-cap volume is **flushed** (money lost) | **carries** | points are a shared balance; flushing them would destroy product-stream volume too (D-20) |
| pairing fires at placement/activation and re-fires on some upgrade paths | fires once per approved activation; no upgrade path exists in this app | one event, one volume (D-02, D-19) |
| non-binary members cannot be in the tree | the tree is universal (Phase 22); only **earning** is package-gated | the tree is the network, the package is the qualification (D-08) |
| cached counters (`left_count`, `pairs_paid_today`) | the ledger is the source of truth; caches are derived | Phase 22 D-23 and the audit |
| `users.binary_parent_id`/`binary_position` | `binary_nodes` | Phase 22 D-01 |
| no audit trail for a flush | no flush; every movement is a ledger row | Phase 22 §15.6 |

### 12.13 Non-goals

1. **No upgrade delta** — the app has no upgrade; a re-activation is a new activation (D-19).
2. **No self bonus, no own row** (D-04).
3. **No per-package rate, size, cap or flush** (D-06, D-20).
4. **No new tree, route, table or cron** (D-01, D-14, D-15).
5. **No retroactive money** — `package_binary_ledger_since` is the baseline.
6. **No change to the package referral ladder** — direct and levels 2–10 are untouched.
7. **No member-facing reversal or package change.**

---

## 13. Data model amendment

### 13.1 Overview

**No new table.** Six columns, two source columns, four keys, four re-created checks, three settings.

| Object | Change | Writer |
|---|---|---|
| `packages.binary_enabled` | new `TINYINT(1)` default 0 | the package form |
| `packages.binary_points` | new `INT UNSIGNED` default 0 | the package form |
| `activations.binary_processed_at` | new `DATETIME NULL` | `PackageBinaryService` (the anchor) |
| `activations.binary_points` | new `BIGINT` default 0 | `PackageBinaryService` |
| `activations.binary_total` | new `DECIMAL(12,2)` default 0.00 | `PackageBinaryService` |
| `binary_awards.source` | new enum `('order','activation')` default `'order'` | both engines |
| `binary_awards.activation_id` | new `INT UNSIGNED NULL` + FK | the package engine |
| `binary_awards.order_id` | becomes **nullable** | — |
| `binary_volume.source` | new enum, default `'order'` | both engines |
| `binary_volume.activation_id` | new `INT UNSIGNED NULL` + FK | the package engine |
| `binary_volume.order_id`, `order_item_id` | become **nullable** | — |
| `settings` (3 keys) | rows | the settings page |

### 13.2 `packages`

```sql
ALTER TABLE packages
  ADD COLUMN binary_enabled TINYINT(1)   NOT NULL DEFAULT 0 AFTER product_value,
  ADD COLUMN binary_points  INT UNSIGNED NOT NULL DEFAULT 0 AFTER binary_enabled;
```

`binary_points` is the volume one activation contributes (D-03). It is **not** the package's price and
**not** its product value; the form suggests `ROUND(product_value)` as a starting point, and Appendix A
explains the economics.

### 13.3 `activations`

```sql
ALTER TABLE activations
  ADD COLUMN binary_processed_at DATETIME NULL AFTER commission_processed_at,
  ADD COLUMN binary_points       BIGINT   NOT NULL DEFAULT 0 AFTER binary_processed_at,
  ADD COLUMN binary_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_points,
  ADD KEY idx_activations_binary (binary_processed_at);
```

`binary_processed_at` is the package stream's idempotency anchor; `binary_points` is what the activation
placed; `binary_total` is what it paid (to its uplines, never to the buyer).

### 13.4 `binary_awards` amendment

```sql
-- order_id must accept NULL (an activation award has no order)
ALTER TABLE binary_awards MODIFY COLUMN order_id INT UNSIGNED NULL;

ALTER TABLE binary_awards
  ADD COLUMN source        ENUM('order','activation') NOT NULL DEFAULT 'order' AFTER kind,
  ADD COLUMN activation_id INT UNSIGNED NULL AFTER order_id,
  ADD UNIQUE KEY uq_binary_award_activation (activation_id, user_id, kind),
  ADD KEY idx_ba_activation (activation_id),
  ADD CONSTRAINT fk_ba_activation FOREIGN KEY (activation_id)
      REFERENCES activations (id) ON DELETE CASCADE,
  ADD CONSTRAINT chk_ba_source CHECK (
      (source = 'order'      AND order_id IS NOT NULL AND activation_id IS NULL) OR
      (source = 'activation' AND activation_id IS NOT NULL AND order_id IS NULL)
  );
```

The existing `uq_binary_award (order_id, user_id, kind)` still anchors product awards. MySQL treats
`NULL`s as distinct, so it does not constrain activation rows — the new key does.

### 13.5 `binary_volume` amendment

```sql
ALTER TABLE binary_volume
  MODIFY COLUMN order_id      INT UNSIGNED NULL,
  MODIFY COLUMN order_item_id INT UNSIGNED NULL;

ALTER TABLE binary_volume
  ADD COLUMN source        ENUM('order','activation') NOT NULL DEFAULT 'order' AFTER kind,
  ADD COLUMN activation_id INT UNSIGNED NULL AFTER order_item_id,
  ADD UNIQUE KEY uq_bv_activation (node_user_id, activation_id, side, kind),
  ADD KEY idx_bv_activation (activation_id),
  ADD CONSTRAINT fk_bv_activation FOREIGN KEY (activation_id)
      REFERENCES activations (id) ON DELETE CASCADE,
  ADD CONSTRAINT chk_bv_source CHECK (
      (source = 'order'      AND (order_id IS NOT NULL OR order_item_id IS NOT NULL) AND activation_id IS NULL) OR
      (source = 'activation' AND activation_id IS NOT NULL AND order_id IS NULL AND order_item_id IS NULL)
  );
```

The two Phase 22 checks that require a line reference must be **dropped and re-created** so an activation
row is valid:

```sql
-- idempotent: guarded by information_schema.CHECK_CONSTRAINTS
ALTER TABLE binary_volume DROP CHECK chk_bv_own;
ALTER TABLE binary_volume DROP CHECK chk_bv_team;

ALTER TABLE binary_volume
  ADD CONSTRAINT chk_bv_own CHECK (
      kind <> 'own' OR (points > 0 AND award_id IS NULL AND
        ((source = 'order' AND order_item_id IS NOT NULL) OR (source = 'activation' AND activation_id IS NOT NULL))
      )
  ),
  ADD CONSTRAINT chk_bv_team CHECK (
      kind <> 'team' OR (points > 0 AND award_id IS NULL AND
        ((source = 'order' AND order_item_id IS NOT NULL) OR (source = 'activation' AND activation_id IS NOT NULL))
      )
  );
```

**The other checks are unchanged**: `chk_bv_points`, `chk_bv_rev`, `chk_bv_pair`, `chk_bv_return`,
`chk_bv_adj`, `chk_ba_amount`, `chk_ba_self`, `chk_ba_pair`.

**Every row a package activation writes carries `source = 'activation'` and its `activation_id`** — the
`team`, `pair`, `pair_return` and `reversal` rows alike — so the source filter is exact and the consumption
of a package pair is attributable to the activation that triggered it. `chk_bv_pair` needs no change (it
checks only the sign and the award id), and `uq_bv_award (award_id, side, kind)` still anchors the pair's
two rows.

### 13.6 Counts and parity

| At tag | Tables | Settings | Migrations | Cases | Smoke |
|---|---:|---:|---|---:|---:|
| `p22i-release` (baseline) | 42 | 89 | 001–026 | 1,734 | ≈300 |
| `p23a-foundation` | **42** | **92** | 001–**027** | ≥1,752 | ≈300 |
| `p23b-configuration` | 42 | 92 | 001–027 | ≥1,770 | ≈302 |
| `p23c-money` | 42 | 92 | 001–027 | ≥1,820 | ≈306 |
| `p23d-admin` | 42 | 92 | 001–027 | ≥1,840 | ≈308 |
| `p23e-member` | 42 | 92 | 001–027 | ≥1,860 | ≈310 |
| `p23f-operations` | 42 | 92 | 001–027 | ≥1,872 | ≈312 |
| `p23g-release` | 42 | 92 | 001–027 | 1,872 | ≈312 |

`DatabaseInstaller::EXPECTED`: tables **42** (unchanged), settings **92**. `SeedParityTest`: 92 keys.
`install/footer.sql`: **42 tables · 92 settings**. The reset's table list is unchanged; the Product binary
group's comment covers both sources.

### 13.7 Invariants (delta)

Phase 22 §15.6's INV-1…INV-15 apply. The package stream adds:

| # | Invariant | Checked by |
|---|---|---|
| **INV-P1** | Every `binary_awards` and `binary_volume` row has a `source` consistent with its id columns | the checks, the audit |
| **INV-P2** | No package award is `pending` | the audit (23F) |
| **INV-P3** | `activations.binary_points` equals the points of that activation's `team` rows on any one side | the audit |
| **INV-P4** | `activations.binary_total` equals the sum of that activation's live `pair` awards | the audit |
| **INV-P5** | A stamp with `binary_points > 0` has its volume rows, and vice versa | the audit |
| **INV-P6** | No activation award names the activating member or the company root | the tests, the audit |
| **INV-P7** | No activation writes an `own` row | the schema check, the audit |
| **INV-P8** | The product stream's rows are unchanged by this plan | the regression case (P23C-T42) |

---

## 14. The money path (delta)

### 14.1 The event map

| Event | Code | What the package stream does |
|---|---|---|
| Registration with a code / e-wallet | `RegistrationService` → `ActivationService::complete()` | points placed, pairs paid, wallet credited, all in the registration transaction |
| Member proof activation approved | `AdminActivationController::approve()` → `complete()` | same |
| Admin approval | same | same |
| Wallet purchase (registrar pays) | `ActivationService::walletPurchase()` → `complete()` | same |
| A rejected activation | `reject()` | nothing (no `complete()`) |
| A re-run of `complete()` | — | `binary_processed_at` → `replayed`; nothing moves |
| Reversal (support) | `bin/package_binary_reverse.php` | void, claw back, reverse the volume |
| Release job | `bin/unilevel_release.php` | **ignores** package awards (never pending) |
| Month close | `bin/unilevel_month_close.php` | no effect on package pairs (no Active rule) |

### 14.2 The API

```php
final class PackageBinaryService
{
    public function onActivation(int $activationId, ?PDO $pdo = null): array;
    // → ['activation_id','processed','replayed','points','pairs','amount',
    //     'awards'=>list, 'nodes'=>int, 'reason'?]

    public function quoteActivation(int $packageId, int $memberId): array;
    // → ['eligible'=>bool,'points'=>int,'pairs'=>int,'amount'=>float,
    //     'balances'=>['left'=>int,'right'=>int],'reason'?]

    public function reverseActivation(int $activationId, array $options = []): array;
    public function qualifies(int $userId, ?PDO $pdo = null): bool;
    public function totalsFor(int $activationId): array;
    public function blockedMap(int $userId): array;
    public static function enabled(): bool;
    public static function requiresPackage(): bool;
}
```

`quoteActivation()` is read-only and drives the package form and the activation page.

### 14.3 The activation sequence (normative)

```
1. lock the activation row; if binary_processed_at is set → replayed
2. if status <> 'approved' → nothing (the caller only calls after approving)
3. read the package: binary_enabled, binary_points; read package_binary_enabled
   if either is off or points = 0 → stamp zeros, return 'nothing to place'
4. lock the activating member's node and its ancestors, ascending user_id
   if the member has no node → stamp zeros + reason 'not placed' (the page prevents this)
5. write one 'team' row at every ancestor: side from the member's path; source 'activation'
   (no own row — D-04)
6. resolve eligibility once for the ancestors with stream = 'package'
   (company root excluded; is_active; membership; recipient package binary_enabled when required;
    no Active-month check)
7. for each ancestor from the parent upward:
       due = min(floor(L/PP), floor(R/PP)); room = cap - pairsToday; n = min(due, room)
       if n = 0 → continue
       insert the pair award (credited, hold_days = 0, source 'activation', activation_id)
       insert the two 'pair' rows (negative, source 'activation', activation_id, award_id)
       collect the wallet credit (recipient, amount, award.id) — do not credit inside the loop
       update the node caches
7b. credit the collected wallets in ASCENDING recipient order, store ledger_id/credited_at
8. stamp activations: binary_points, binary_total, binary_processed_at = NOW()
9. audit package_binary.award; touch the live surfaces
```

### 14.4 Idempotency layers

1. **`activations.binary_processed_at`** — the package stream's own stamp.
2. **`activations.commission_processed_at`** — `complete()` bails before the hook on a replay.
3. **The unique keys** — `(activation_id, user_id, kind)` on awards; `(node_user_id, activation_id, side,
   kind)` on volume.
4. **The wallet anchor** — `(user_id, ref_type, ref_id, type)`.

### 14.5 Transactions and locking

- The hook runs inside `ActivationService::complete()`'s transaction (the activation row and member are
  already locked there). Node locks are taken **ascending `user_id`**, exactly Phase 22 §16.6, so two
  activations sharing an ancestor queue.
- Wallet writes happen under the same transaction. The settlement loop runs parent-to-root while the
  wallet contract requires **ascending recipient** order, so the engine collects the credits during the
  loop and writes them, ascending, before the stamp (§14.3 step 7b). Two activations that share recipients
  therefore take the wallet locks in the same order and cannot deadlock. A test pins the order.
- A failure anywhere rolls the activation back: no activation status change, no volume, no award, no
  wallet movement.

### 14.6 Eligibility

- `BinaryService::eligibilityMap($chain, $period, 'package')`: company root excluded, `is_active = 1`,
  membership `active`, **plus** the package gate; **no** Active-month lookup.
- The package gate reads the recipient's `package_id` in the same batch (one join), never per node.
- A skipped node consumes nothing (Phase 22 D-13).

### 14.7 The product stream is unchanged

- The amendment adds a `source` default of `'order'` and nullable order columns; every Phase 22 query is
  covered by the regression case, which compares the product rows of an order before and after the
  package release.
- The product eligibility path passes `stream = 'product'` and keeps the Active rule.
- The release job's product stream is untouched.

### 14.8 Reversal

The CLI, in one transaction per activation: lock the activation and the nodes; for each award — `credited`
→ debit `min(amount, withdrawable)`, `voided`, `reversed_amount`, `binary_reversal` movement; write
`pair_return` rows for the consumed points; then write `reversal` rows for every `team` row; update the
caches; recompute `activations.binary_total`; audit. Dry run prints without writing. No pairing runs.

### 14.9 Reconciliation and audit

| Tool | Question | Change |
|---|---|---|
| `wallet_reconcile.php` | every credited award has one wallet credit | source-aware; includes activation awards |
| `binary_audit.php` | caches equal the ledger, every pair has its rows, every award its formula | activation-source checks (23F) |
| `PackageBinaryService::reconcile()/audit()` | the package slice | new |

### 14.10 What the package engine never does

- Never runs at registration, at placement, on a rejected activation or on a replay.
- Never writes an `own` row, a self award or a `pending` award.
- Never credits outside the activation transaction.
- Never pays the activating member or the company root.
- Never reads a point balance or a package flag from the request.
- Never re-pairs during a reversal.

---

## 15. Security, audit and abuse control

### 15.1 The delta's threat model

Phase 22 §17 covers the tree, the points and the ledgers. This stream adds one lever: **the package sale**
and the qualification it buys. Every control below maps to it.

| Lever | Attack | Control |
|---|---|---|
| **The sale** | Approve a fake activation to mint pairs | The existing activation proof/review flow; the approval is admin-guarded and audited; the release freeze is a rejected activation |
| | Replay an approval to mint a second volume | `binary_processed_at` + `commission_processed_at` + the unique keys (P23C-T12/T38) |
| | Activate at a low price, then flip packages to re-fire volume | No upgrade/flip path exists; a re-activation requires the member to be `pending`/`inactive` (P23C-T19 policy) |
| | Refund the package and keep the pairs | No refund path exists; the reversal CLI is the only correction and it claws back |
| **The points** | Inflate `binary_points` to inflate pairs | The field is admin-only, audited (`package.binary.update`), ceilinged at 1,000,000 and shown with its pair equivalent in the readiness sentence (23B) |
| | Point the engine at a forged package | The value is read from the locked activation's package inside the transaction; the client sends nothing |
| **The qualification** | Buy a non-binary package but still earn | The recipient gate reads the recipient's own package switch (P23C-T22/T24) |
| | Switch a package off to keep a paid pair | The past award stands; the switch stops future pairs (P23C-T29) |
| **The money** | Double credit on a raced completion | Four anchors (§14.4), ascending wallet order (§14.5) |
| | Pay a pending package award via the release job | Package awards are `credited` at creation; the audit treats `pending` as a failure (P23C-T30/T32, INV-P2) |
| | Reverse twice | The CLI's idempotent status checks (P23C-T48) |

### 15.2 Activation integrity

- **Only `complete()` fires the stream.** The four entry points (code, e-wallet, proof, admin approval,
  wallet purchase) all funnel through it; there is no second writer.
- **The approval is the gate.** A `pending_payment`/`pending_review` activation is inert; a rejection is
  final and writes nothing.
- **The member must be placed.** The activation page collects the placement for an unplaced member; a
  direct call without a node stamps zeros and writes no volume, so no money is created for a member the
  tree cannot pay.
- **The member must not be active with a package** (`assertCanActivate`), so one member cannot mint volume
  by activating repeatedly while already active; the re-activation path after being set inactive is a new
  purchase and contributes again (D-19).

### 15.3 Points inflation and readiness

- The package form refuses `binary_enabled = 1` with `binary_points = 0`, and shows the pair equivalent
  and the money per activation as it is typed.
- The readiness panel names every flagged package without points and every package that contributes
  nothing.
- The audit re-checks INV-P3 (the stamp vs the volume) and INV-P4 (the total vs the awards) on every run.
- The reference's `daily_pair_cap` safety valve is replaced by the **shared** daily cap, which already
  bounds any one recipient's day regardless of how many packages fire.

### 15.4 Qualification gaming

- A member cannot downgrade or switch packages, so a "qualify then leave" path does not exist.
- The gate is read at each activation, so a member who loses qualification stops earning new pairs but
  keeps the paid ones.
- A blocked node's volume is visible (`blockedMap()`), so an operator can see a leg whose upline is not
  qualified — which is the intended "buy a package to earn package pairs" incentive, not a defect.

### 15.5 Audit actions

| Action | When | Payload |
|---|---|---|
| `package.binary.update` | a package's switch or points changes | before/after |
| `package_binary.award` | an approved activation fires the stream | activation, package, points, awards, amount, reason when nothing was placed |
| `package_binary.reverse` | the CLI applies a reversal | activation, awards, amount, shortfall, reason |
| `binary.export` | the ledger CSV (unchanged action) | filters, now including `source` |

The existing `member.activate` audit gains the package binary outcome in its meta.

### 15.6 Hardening

- The activation POST keeps its CSRF guard and `admin`/`member` guards; the placement block is part of the
  same form, so it inherits both.
- Every package and activation string is escaped (`e()`); the forms' numeric fields are validated
  server-side.
- **Kill switches**: `package_binary_enabled` (stops new package volume and pairs instantly),
  `binary_enabled` (the shared master), `binary_reversal_enabled` (the clawback switch), the package's own
  `binary_enabled`.
- The vocabulary manifest (Phase 22 §15.6, amended in 23A) lists the package files; a file outside it is a
  plan amendment.

### 15.7 Privacy

- The activation page shows the member their own package and their own outcome; the admin card is
  admin-only.
- The member's Binary bonus tab names no ancestor (Phase 22 D-22).
- The ledger's source filter does not widen a member's scope; a member still sees only their own rows.

---

## 16. Testing harness, fixtures and QA protocol

### 16.1 Harness

Phase 22 §18.1 applies: every case runs in a rolled-back transaction, the label is the ID, the runner
filters by name, and the smoke pass is separate.

### 16.2 The fixture packages

On top of Phase 22 §18.2's tree:

| Package | Price | Product value | `binary_enabled` | `binary_points` | Referral ladder |
|---|---:|---:|---|---:|---|
| `PKA` | 4,498.00 | 4,750.00 | on | **1,000** | the demo Package A ladder |
| `PKB` | 6,998.00 | 8,300.00 | on | **0** (the misconfiguration) | the demo Package B ladder |
| `PKC` | 4,498.00 | 4,750.00 | **off** | 1,000 | the demo Package A ladder |

Membership: every fixture member can activate any package; the tests set the package explicitly.
Settings: `package_binary_enabled = 1`, `package_binary_requires_package = 1`, shared PP 1,000, pair 10%,
cap 20; the product stream at its shift.

Every expected number in §7's test plan is computed from this fixture.

### 16.3 Query budgets

| Path | Budget | Case |
|---|---|---|
| `onActivation()`, 1,000 points, ten-deep chain | ≤ 25 statements; one node lock, one volume batch, one eligibility batch | P23C-T15 |
| `quoteActivation()` | ≤ 5 statements, no writes | P23C-T37 |
| The ledger with the source filter | 1 count + 1 page + 1 stats batch | P23D-T2 |
| The activation card | 2 statements | P23D-T5 |

### 16.4 The QA pack

`docs/qa/phase-23-package-binary.md`, sections A–F, `QA23X-nn`, the same shape as Phase 22's (§18.4).
Header: the baseline, the scratch-database recipe (027 → configure a package → place the fixture → approve).
**42 checks in total** (A 6 · B 6 · C 8 · D 7 · E 6 · F 5 · G 4).

### 16.5 Smoke

`tests/smoke.ps1` gains: the package form's binary card, a flagged package's activation through the
member page, the admin ledger's source filter, the member tab's package card, the audit and the reversal
dry run.

### 16.6 The acceptance run

Appendix B.9: **10 steps** on a scratch database, executed by someone who did not implement the phase.

---

## 17. Risks and mitigations

### 17.1 Product risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R-01 | The package points are set too high and one activation pays a large chain | medium | high | Off by default; the readiness sentence shows the pair equivalent and money; shared cap; the audit; Q1 | 23B/23C |
| R-02 | A package activation is approved by mistake | low | high | The reversal CLI is dry-run first, audited, clawback best effort | 23C/23F |
| R-03 | Two streams' volume in one balance confuses the member | medium | medium | The Binary bonus tab splits by source and labels the total; the Left/Right cards remain the balance | 23E |
| R-04 | A non-qualified upline blocks a leg's income invisibly | medium | medium | `blockedMap()`, the admin panel, the member qualification line, the audit | 23C/23D/23E |
| R-05 | The amendment breaks the product stream | low | high | The regression case (P23C-T42) and the Phase 22 suite at every gate | 23A/23C |
| R-06 | A package award ends up `pending` | low | medium | The immediate flag is mandatory; INV-P2; the audit fails on it | 23C/23F |
| R-07 | Immediate credit is abused by a refunded package | low | high | No refund path exists; the reversal claws back | 23C |
| R-08 | A reactivated member re-mints volume | low | medium | A re-activation is a real new purchase (D-19); the audit shows the history; Q4 | 23C |
| R-09 | The activation page's placement block confuses a legacy member | medium | low | The same fields and copy as the register form; the block only appears when the member has no node | 23C |
| R-10 | The reference's flush/cap expectations are missed | medium | medium | §12.12 documents the divergence; the readiness and the reports show the real behaviour | 23A/23D |

### 17.2 Plan risks

| # | Risk | Mitigation |
|---|---|---|
| P-01 | Phase 22 counts differ at `p23-start` | §2.6: measure, record, correct |
| P-02 | A phase needs a column | §13 freeze: a new amendment, never a quiet `ALTER` |
| P-03 | The plan is read as a replacement for the package referral ladder | §1 and §12.13: the ladder is untouched |
| P-04 | The economics are decided during implementation | Q1–Q4 are the business's; the plan ships off and a preset |
| P-05 | The amendment's manifest grows without review | The 23A test checks the Phase 22 plan is amended |

---

## 18. Open questions

| # | Question | Owner | Affects | Default if unanswered |
|---|---|---|---|---|
| **Q1** | What is each package's `binary_points`? Appendix A suggests A = 1,000, B = 2,000 | Business | 23B | Ship 0/off; the operator sets them |
| **Q2** | Must a pair recipient hold a binary package (`package_binary_requires_package = 1`), or may every eligible member earn package pairs? | Business | 23B/23C | Ship on |
| **Q3** | Should package awards be immediate (the plan) or held like the product escrow? | Business | 23C | Ship immediate; a hold would need a delivery-free release rule |
| **Q4** | Should a re-activation after being set inactive contribute again, or once per member forever? | Business/Ops | 23C | Ship per activation (D-19) |
| **Q5** | Should a package activation also place an `own` row for the member (the product stream's rule)? | Business | 23C | No (D-04); the alternative would add a self bonus decision too |
| **Q6** | Should the package stream have a per-package daily cap like the reference? | Business | 23B/23C | No; the shared cap bounds the day (D-06, D-20) |
| **Q7** | Should package pairs require the monthly Active rule? | Business | 23C | No (D-08); the package is the qualification |
| **Q8** | Is a package upgrade path planned? | Business | future | No; a new plan would add the delta rule |
| **Q9** | Should the member see their own activation's points on the tab? | Product | 23E | Yes, as "placed in your tree", never naming who earned |
| **Q10** | Does the company want a package-binary cap per package tier instead of the points knob? | Business | future | No; points are the single knob (D-06) |

---

# Part IV — Appendices

## Appendix A — The package preset and the point values

### A.1 The two new controls per package

| Control | Column | Preset | Range | What it does |
|---|---|---|---:|---|
| Package binary enabled | `packages.binary_enabled` | **off** | on/off | whether an activation of this package places points |
| Points per activation | `packages.binary_points` | **0** | 0–1,000,000 | how many points one activation contributes |

The three visible global keys are §12.4's; the ten Phase 22 keys are shared and unchanged.

### A.2 Suggesting points from the package's value

The package form pre-fills `ROUND(product_value)` as a **suggestion**, never as a saved default (D-13).
The operator decides; the readiness sentence shows what the number means:

> *"1,000 points per activation. One pair is 1,000 points; at 10% it pays ₱100.00. Two activations (one on
> each side) complete one pair."*

The reference's `pairing_bonus` played the same role as a per-package volume number; here the volume is
points and the payout is the shared pair value (§12.12).

### A.3 A worked starting point

With the demo packages (A = 4,498, product value 4,750; B = 6,998, product value 8,300) and the shared
preset (PP 1,000, pair 10% = ₱100):

| Candidate setting | Two A activations (one each side) | Two B activations | One A + one B |
|---|---|---|---|
| **A = 1,000, B = 2,000** | 1 pair → **₱100** (1.1% of the two prices) | 2 pairs → **₱200** (1.4%) | 1 pair → ₱100, 1,000 carried on B's side |
| A = 2,000, B = 4,000 | 2 pairs → ₱200 (2.2%) | 4 pairs → ₱400 (2.9%) | 2 pairs → ₱200, 2,000 carried |
| A = 500, B = 1,000 | 0 pairs (500/500 carried) | 1 pair → ₱100 | 0 pairs; 500/1,000 carried |

The plan ships **off, 0 points** for every package; Appendix A.3 is a starting point, not a decision
(Q1). The operator can read the same numbers on the form before saving.

### A.4 What the points do not change

- The package referral ladder (direct + levels 2–10) is untouched; the binary is a second, separate
  stream on the same sale.
- The package price and the product value are untouched; `binary_points` is a new, independent number.
- The member's own purchase earns them nothing from the binary (D-04); their package referral income is
  the sponsor's, as today.

---

## Appendix B — Worked scenarios and the acceptance run

Fixture: §16.2. Preset: PP 1,000, pair 10% = ₱100, cap 20, qualification on.

### B.1 The first activation

`buyerA` (path `LLLLL`) activates **PKA = 1,000 points**, e-wallet.

| Effect | Amount |
|---|---|
| Team rows at `c`, `b`, `a`, `boss`, root (left) | +1,000 each |
| Own row at `buyerA` | **none** |
| Awards | **0** — every side above is left |
| `activations.binary_points` / `binary_total` | 1,000 / 0.00 |

### B.2 The first package pair

`buyerB` (path `LLLLR`) activates **PKA**:

| Effect | Amount |
|---|---|
| Team rows at `buyerB`'s ancestors (right at `c`, left at `b`, `a`, `boss`, root) | +1,000 each |
| `c` before / after | left 1,000 / right 0 → left 1,000 / right 1,000 |
| Pair at `c` | **1 pair → ₱100.00**, `credited`, wallet +100.00 |
| `c`'s balances after | left 0 / right 0 |
| `activations.binary_total` (buyerB) | 100.00 |

### B.3 A ten-deep activation

A chain of eleven members, each node pre-filled with **1,000 right points**; the deepest activates PKA
(1,000 points).

| Node | Due | Pairs | Money |
|---|---:|---:|---:|
| the parent | 1 | 1 | ₱100.00 |
| ancestors 2–9 (eight nodes) | 1 each | 8 | ₱800.00 |
| the root | — | 0 (never paid) | — |
| **total** | | **9** | **₱900.00** |

`activations.binary_total = 900.00`; nine awards of `pair_count = 1`.

### B.4 The qualification block

`c` holds `PKC` (binary switch off). `buyerB` activates PKA:

- `c` receives +1,000 right (volume always flows);
- `c` is not qualified → **no award, no consumption**: `c` stays left 1,000 / right 1,000;
- `blockedMap()` reports `c` with *"recipient package is not binary-enabled"*;
- when `c` moves to a binary package, the next activation pairs the waiting 1,000/1,000.

### B.5 The cap

`package_binary_requires_package = 1`, `binary_daily_pair_cap = 1`. Two activations in one day that would
complete two pairs at `c`: one pays ₱100, the second's points carry; the next day's activation pays it.

### B.6 Mixed sources in one balance

`c` has left 1,000 from a **product** order and right 1,000 from a **package** activation: the pair pays
once, ₱100.00, with one row of each source in the volume ledger and one pair award. The source split on
the member page shows product 1,000 / package 1,000 and the pair counted once.

### B.7 The reversal

`php bin/package_binary_reverse.php --activation=42` (dry run) prints the ₱100.00 at `c` and the 1,000
points it placed. `--apply`:

| Effect | Amount |
|---|---|
| `c`'s pair award | `voided`, `reversed_amount = 100.00` |
| `c`'s wallet | debited 100.00 (up to the withdrawable balance), `binary_reversal` |
| `c`'s pair points | returned: left 1,000 / right 1,000 again |
| The activation's volume | reversed at every node |
| `activations.binary_total` | 0.00 |
| A second run | no change |

### B.8 A 30-day example

Five PKA activations: three on the left of `c` (buyerA and two more on the same leg) and two on the right
(buyerB and one more).

| Day | Activation | Side at `c` | Points | Pair at `c` | Money |
|---|---|---|---:|---:|---:|
| 2 | buyerA | left | 1,000 | — | — |
| 5 | buyerB | right | 1,000 | 1 | 100.00 |
| 9 | buyerC | left | 1,000 | — | — |
| 12 | buyerD | right | 1,000 | 1 | 100.00 |
| 20 | buyerE | left | 1,000 | — | — |
| **month** | **5 activations** | | **5,000** | **2** | **₱200.00** |

The 30-day report shows 5,000 package points placed, 2 package pairs, ₱200.00 credited (no pending),
5 activations, 0 blocked.

### B.9 The acceptance run (10 steps)

1. **Migration.** `mysql < 027_package_binary.sql`; re-run → no error. `SHOW COLUMNS FROM binary_awards`
   → `source`, `activation_id`, nullable `order_id`. `SELECT COUNT(*) FROM settings WHERE key_name LIKE
   'package_binary%'` → 3.
2. **Install parity.** `build_install_sql --check` → 0; a fresh install reports 42 tables / 92 settings;
   `wallet_reconcile` and `binary_audit --check` → 0.
3. **Configuration.** `/admin/packages/{A}/edit`: switch on, points 1,000, save; `/admin/settings`: the
   package master on; the readiness card clean.
4. **A first activation.** Approve `buyerA`'s activation: five team rows, no own row, no award, the
   activation stamped 1,000/0.00.
5. **A first pair.** Approve `buyerB`'s: ₱100.00 credited at `c`, the wallet credited, the audit clean.
6. **The qualification.** Put `c` on a non-binary package and approve two activations: no pair, no
   consumption; the admin panel names the reason.
7. **The member page.** `/earnings?ledger=binary`: the tab reads **Binary bonus**, the package card and
   the source split; the copy names no upline.
8. **The admin pages.** `/admin/commissions?ledger=binary&source=activation`: the package rows; the
   activation card; the member panel split.
9. **The product stream.** Pay a shop order: the product rows are identical to before 23C and
   `source = 'order'`.
10. **Reversal and stop.** Run the reversal dry run and apply on one activation: voided, clawed back,
    volume reversed, audit clean; then switch `package_binary_enabled` off: a new activation stamps zeros.

---

## Appendix C — Copy deck

### C.1 The package form card

> **Package binary**
> This package places **{points} points** in the binary tree when a member's activation is approved.
> One pair is **{pair_points} points** and pays **{pair_value}**. A {points}-point activation completes
> **{pairs} pair(s)** if the other side has the same points.
> ○ Package binary enabled ○ Points per activation

Errors: *"Set points above 0 or turn the package's binary switch off."* /
*"Points must be a whole number."*

### C.2 The settings card

> **Package binary** — the master switch and *Require a binary package to earn package pairs*.
> *"The package stream is off. No activation places points."*
> Readiness: *"{n} package(s) are flagged for the binary but contribute 0 points. Set points or switch them
> off."* / *"{n} package(s) do not contribute to the binary."*

### C.3 The activation page (member)

When the member is unplaced and the package is binary-enabled:
> **Binary placement**
> Choose the member who places you in the binary tree and the side. This cannot be changed once you have
> activated.
> *Binary upline* ___ *○ Left ○ Right*

When the activation completes:
> *"Your activation placed {points} points in your tree."*

### C.4 The member tab (`/earnings?ledger=binary`)

Tab: **Binary bonus**
Package card:
> **Package activations**
> Your package: **{package}** · **{points} points** per activation.
> Package pairs earned: **{n}** · **{money}**.
> *Package pairs are paid to members with a binary package.*

Stats: **Repeat purchases** {product money} · **Package activations** {package money} · **Your points**
{left/right} · **Pairs today**
Awards: the Source column reads **Repeat purchase** / **Package activation**.
Qualification line: *"You hold a binary package: your activations place points and your team's
activations can pay you package pairs."* / *"Your package is not in the binary: your activations place no
points and package activations do not pay you package pairs."*

### C.5 Admin — the ledger and the activation

Tab: **Binary bonus** (admin), Source filter: *All sources · Repeat purchase · Package activation*.
Activation card:
> **Package binary** — *Package A · 1,000 points placed · 1 pair · ₱100.00 credited.*
> Nothing placed: *"No points were placed: the package is not binary-enabled / the stream is off / the
> member is not placed."*
> *"Paid immediately to the recipient's e-wallet."*

Member panel: **Binary placement** · **Package** · **Product points** · **Package points** · **Pairs** ·
**Package qualification**.

### C.6 Reports

**Package binary (30 d)** · **By package** · **By activation** · **Readiness**

---

## Appendix D — SQL quick reference

```sql
-- The package settings and flags
SELECT key_name, value FROM settings WHERE key_name LIKE 'package_binary%' ORDER BY key_name;
SELECT id, name, binary_enabled, binary_points FROM packages ORDER BY sort_order;

-- One activation's binary outcome
SELECT id, user_id, package_id, status, binary_points, binary_total, binary_processed_at
  FROM activations WHERE id = ?;

-- The points one activation placed, per node
SELECT node_user_id, side, kind, points, created_at
  FROM binary_volume WHERE activation_id = ? ORDER BY node_user_id, side;

-- The awards one activation paid
SELECT id, kind, user_id, pair_count, pair_points, rate_pct, amount, status, ledger_id
  FROM binary_awards WHERE activation_id = ? ORDER BY user_id;

-- Package vs product totals
SELECT source, COUNT(*) AS awards, SUM(amount) AS amount
  FROM binary_awards WHERE status = 'credited' GROUP BY source;

-- Package points per member
SELECT source, side, SUM(points)
  FROM binary_volume WHERE node_user_id = ? AND kind IN ('own','team') AND points > 0
 GROUP BY source, side;

-- Any package award that is still pending (must be zero)
SELECT COUNT(*) FROM binary_awards WHERE source = 'activation' AND status = 'pending';

-- A stamped activation with no volume (must be zero unless points were 0 / not placed)
SELECT a.id FROM activations a
  LEFT JOIN binary_volume v ON v.activation_id = a.id
 WHERE a.binary_processed_at IS NOT NULL AND a.binary_points > 0
 GROUP BY a.id HAVING COUNT(v.id) = 0;

-- The package readiness
SELECT COUNT(*) FROM packages WHERE binary_enabled = 1 AND binary_points = 0;

-- A recipient's qualification
SELECT p.binary_enabled, p.binary_points
  FROM users u JOIN packages p ON p.id = u.package_id WHERE u.id = ?;
```

---

## Appendix E — Glossary (delta)

| Term | Meaning |
|---|---|
| **Activation** | A package purchase event (`activations` row) that makes a member an associate. |
| **Package binary** | The stream that places points when an activation is approved and pays pairs to the uplines. |
| **Package points** | `packages.binary_points`: the volume one activation contributes. |
| **Package pair** | A pair completed from package-sourced volume; the award's `source = 'activation'`. |
| **Qualification** | The recipient's own package must be `binary_enabled` when `package_binary_requires_package = 1`. |
| **Immediate credit** | The `credited`-at-creation rule: package money is paid in the activation transaction. |
| **Source** | `order` or `activation`: which event wrote a ledger row. |

Phase 22 Appendix E remains the base glossary.

---

## Appendix F — Formula card

```
ACTIVATION
  eligible   ⇔ activations.status = 'approved'
               AND packages.binary_enabled = 1
               AND packages.binary_points > 0
               AND package_binary_enabled = 1
               AND the member has a node
  points     = packages.binary_points                    INTEGER, snapshotted

VOLUME (team only)
  for every ancestor A of the member:
      (A, side_from_path(A.depth), +points, kind 'team', source 'activation')
  no own row

PAIR (per ancestor, parent → root)
  L, R    = A.left_points, A.right_points                signed, shared with the product stream
  due     = min(floor(max(L,0)/PP), floor(max(R,0)/PP))
  room    = cap = 0 ? due : max(0, cap − pairsToday(A.user))
  n       = min(due, room)
  eligible ⇔ not company root AND is_active AND membership active
              AND (requires_package = 0 OR A's package.binary_enabled = 1)
  perPair = round(PP × pair_pct / 100, 2)
  amount  = perPair × n
  award   = one row (activation, A, 'pair', pair_count = n, status credited, hold 0)
  consumption = −n × PP each side, source 'activation', activation_id

MONEY
  wallet credit: (A.user, amount, 'binary', award.id), withdrawable, inside the activation transaction

REVERSAL (CLI)
  awards → voided + clawback min(amount, withdrawable) + reversed_amount
  pair points → pair_return rows
  volume → reversal rows
  activations.binary_total → recomputed

BOUNDS
  per ancestor per activation ≤ floor(min(L,R)/PP) × perPair, capped per member per day
  no award to the activating member or the company root
```

---

## Appendix G — Traceability to the reference package binary

### G.1 The reference behaviour, mapped

| Reference (`dmp/cart-system`) | This plan | Where |
|---|---|---|
| `packages.pairing_bonus` (volume) | `packages.binary_points` | §12.3, 23A/23B |
| `packages.pairing_enabled` | `packages.binary_enabled` | §12.3 |
| `packages.daily_pair_cap` | the shared `binary_daily_pair_cap` | §12.5, §12.12 |
| `commission_binary_enabled` | `package_binary_enabled` (+ the shared `binary_enabled`) | §12.4 |
| `processBinaryPlacement()` volume walk | `PackageBinaryService::onActivation()` → `BinaryService::applyVolume()` | §12.6, §14.3 |
| `min(left, right) − (paid + flushed)` settlement | Phase 22's pair algorithm with carry | §12.5, Phase 22 §14.9 |
| 1:1 peso payout | the shared pair value | §12.5, §12.12 |
| flush over the cap | carry (no flush) | §12.12, D-20 |
| pairing fires at activation, not registration | the `complete()` hook | §12.2, D-02 |
| per-package cap and `daily_cap_bypass` | one shared cap | §12.12 |
| `creditPairing` immediate wallet credit | immediate credit | §12.8, D-07 |
| `users.left_pair_volume` etc. | `binary_nodes.left_points` + the ledger | Phase 22 §15 |
| no upgrade volume | no upgrade path; re-activation is a new activation | §12.2, D-19 |

### G.2 The user's requirement, mapped

| Requirement | Where |
|---|---|
| "the usual package binary which only earn you one time" (the product plan's contrast) | §1.1, §12.2, D-19 |
| one tree for both streams | D-01, §12.6 |
| use the cart-system package binary as the reference | §12.12, Appendix G.1 |
| the member's own package earns them nothing (entry purchase) | D-04, §12.6 |
| immediate money for a final sale | D-07, §12.8 |
| the same member presentation as Phase 22 | §12.11, D-12 |

### G.3 What this plan does not restate

The tree, the placement rules, the pair algorithm, the cap/carry, the company-root exclusion, the rounding,
the member copy rules, the d3 tree, the phase protocol and the QA conventions are Phase 22's. A reader of
this plan must have Phase 22 §14–§16 open.

---

## Appendix H — Phase gate record

### H.1 The gate, per phase

| Phase | Tag | Schema | Suite floor | QA section | Evidence | Signed |
|---|---|---|---:|---|---|---|
| 23A Foundation | `p23a-foundation` | 027 (ledger amendment, package/activation columns, 3 settings) | 1,752 | A (`QA23A-01`–`06`) | `docs/qa/evidence/phase-23/23a-*` | ☐ |
| 23B Configuration | `p23b-configuration` | none | 1,770 | B (`QA23B-01`–`06`) | `23b-*` | ☐ |
| 23C Money engine | `p23c-money` | none | 1,820 | C (`QA23C-01`–`08`) | `23c-*` | ☐ |
| 23D Admin | `p23d-admin` | none | 1,840 | D (`QA23D-01`–`07`) | `23d-*` | ☐ |
| 23E Member | `p23e-member` | none | 1,860 | E (`QA23E-01`–`06`) | `23e-*` | ☐ |
| 23F Operations | `p23f-operations` | none | 1,872 | F (`QA23F-01`–`05`) | `23f-*` | ☐ |
| 23G Release | `p23g-release` | none | 1,872 | G (`QA23G-01`–`04`) | `23g-*` | ☐ |

**Total QA checks: 6 + 6 + 8 + 7 + 6 + 5 + 4 = 42.**

### H.2 The universal gate, copied per phase

```
[ ] MigrationTest green; at 23A the ordered list includes 027_package_binary.sql
[ ] `php bin/build_install_sql.php --check` exits 0
[ ] `php tests/run.php` green at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0
[ ] `php bin/binary_audit.php --check` exits 0
[ ] `powershell -File tests/smoke.ps1` green
[ ] Every P23X-Tn case exists, is named for the phase, and passes
[ ] The manual script is executed by someone who did not write the phase
[ ] No new PHP warning/notice/deprecation in storage/logs/
[ ] The QA section is filled, with evidence committed
[ ] The phase log entry and the Appendix H row are filled
```

### H.3 The release sign-off

```
RELEASE: Package Binary Bonus — Phase 23
Branch: feature/package-binary   ·   Final tag: p23g-release (feature tag phase-23)
Baseline: p22i-release — 42 tables · 89 settings · 1,734 cases · ≈300 smoke · APP_PHASE 22
Release:  42 tables · 92 settings · 1,872 cases · ≈312 smoke · APP_PHASE 23
Migration: 027_package_binary.sql (additive amendment; no backfill of money or points)
New tables: 0   ·   New routes: 0   ·   New crons: 0
Amends:    dmp/plan-ecowealth-product-binary.md §15 (the ledger amendment and the vocabulary manifest)
Retires:   nothing (the Phase 22 guards stand)
Accepted open questions: Q1–Q10 (recorded in §18; defaults shipped)
Deviations: recorded in docs/phase-log.md
Sign-off:  ____________________  (release owner)     Date: __________
QA:        ____________________  (QA executor)       Date: __________
```

### H.4 What a later phase may not touch

- The three setting names and their semantics.
- The `source` vocabulary and the activation id columns.
- The team-only, no-self, immediate-credit and qualification rules.
- The shared pair value, cap and carry.
- The member copy rule (no uplines).
- The Phase 22 engine's product path.

A change to any of these is a new plan.
