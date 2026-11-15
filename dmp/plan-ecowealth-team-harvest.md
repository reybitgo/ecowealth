# Plan — Team Harvest Bonus (private 3×10 qualification matrix)

**Phase 20** of the EcoWealth member system · **Status: planned** — no code written; the plan is the
gate record until 20A opens.
**Delivery model:** **seven gated phases, 20A → 20G.** Each phase is self-contained, shippable and
testable on its own; each builds on the one before it.
**Date:** 15 November 2026 · **Target:** `http://localhost/ecowealth/system/`
**Branch:** `feature/team-harvest`, cut from `feature/royalty` at `p19g-release` · **Tag at start:**
`p20-start`

| | |
|---|---|
| **Reference specification** | [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §5 (Team Harvest Bonus), with §2.1 (the sponsor tree), §2.4 (the month clock — which this stream ignores), §2.5 (derived vs. ledger money), §3 (Harvest qualification), §5.4 (what does not count), §5.6 (the worked example), §9.1 (`BONUS_T`), §9.2 (the per-member ceiling), §11.1 (Harvest edge cases) and Appendix B (formula card) |
| **Related plans** | [`dmp/plan-ecowealth-royalty.md`](plan-ecowealth-royalty.md) (the monthly sibling this plan's phase structure and conventions mirror), [`dmp/plan-ecowealth-unilevel-repeat-purchase.md`](plan-ecowealth-unilevel-repeat-purchase.md) (the team-chain stream whose ledger and wallet conventions are reused), [`dmp/plan-ecowealth-install-reset.md`](plan-ecowealth-install-reset.md) (install/reset parity), [`dmp/plan-ecowealth-cart-system.md`](plan-ecowealth-cart-system.md) (the 15 phases this one extends) |
| **Baseline at `p20-start`** | **1,162 / 1,162** PHP cases · **248 / 248** smoke checks · `build_install_sql --check` exit 0 · `wallet_reconcile.php` exit 0 · **33 tables · 73 settings** · migrations **001–023** · `APP_PHASE` **19** |
| **Ships** | 3 new tables, 1 extended ENUM, 4 settings, a stored qualification queue, a derived 3×10 matrix, a level-completion award engine, admin and member visibility, reports, reconciliation and a one-time go-live seed |
| **Does not ship** | no new routes, no monthly close, no period table, no purchase input, no rank input, no retroactive go-live money, no reversal path, no General Harvest board, no configurable level thresholds |

**Revision note.** This is the first plan for the Team Harvest Bonus. It does **not** replace
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md); it implements §5 of it in the PHP
application and records every place the app must adapt the simulator's mechanics to a stored,
auditable, real-money system. The largest adaptation is stated up front: **the simulator derives
Team Harvest and never stores it** (reference §2.5). This build stores it — a queue row for every
qualified member, a counter for every member's teammates, and a ledger row for every level paid —
because a bonus that cannot be audited cannot be paid. §12.12 lists every deliberate divergence.

---

## Contents

**Part I — Orientation**

1. [Executive summary](#1-executive-summary)
2. [The phase protocol](#2-the-phase-protocol)
3. [Current state and the gap](#3-current-state-and-the-gap)
4. [Decisions](#4-decisions)

**Part II — The phases** (each one is a complete work package)

5. [Phase 20A — Foundation](#5-phase-20a--foundation)
6. [Phase 20B — Configuration](#6-phase-20b--configuration)
7. [Phase 20C — The Harvest queue](#7-phase-20c--the-harvest-queue)
8. [Phase 20D — Money engine](#8-phase-20d--money-engine)
9. [Phase 20E — Admin visibility](#9-phase-20e--admin-visibility)
10. [Phase 20F — Member visibility](#10-phase-20f--member-visibility)
11. [Phase 20G — Release](#11-phase-20g--release)

**Part III — The shared contract** (read once; every phase cites it)

12. [Specification (normative)](#12-specification-normative)
13. [Data model](#13-data-model)
14. [The money path](#14-the-money-path)
15. [Security, audit and abuse control](#15-security-audit-and-abuse-control)
16. [Testing harness, fixtures and QA protocol](#16-testing-harness-fixtures-and-qa-protocol)
17. [Risks and mitigations](#17-risks-and-mitigations)
18. [Open questions](#18-open-questions)

**Part IV — Appendices**

- [Appendix A — The reference ladder and the default preset](#appendix-a--the-reference-ladder-and-the-default-preset)
- [Appendix B — Worked scenarios](#appendix-b--worked-scenarios)
- [Appendix C — Copy deck](#appendix-c--copy-deck)
- [Appendix D — SQL quick reference](#appendix-d--sql-quick-reference)
- [Appendix E — Glossary](#appendix-e--glossary)
- [Appendix F — Formula card](#appendix-f--formula-card)
- [Appendix G — Traceability to the reference document](#appendix-g--traceability-to-the-reference-document)
- [Appendix H — Phase gate record](#appendix-h--phase-gate-record)

---

# Part I — Orientation

## 1. Executive summary

### 1.1 What ships

A **qualification board plus a per-level award**, exactly the mechanics of
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §5, implemented against the application's
real sponsor tree and real member accounts:

- **Qualification is stored, not derived.** A member enters the **Harvest queue** the moment they
  hold **three direct member sign-ups** (configurable 1–10). The queue is append-only, and a
  member's **position** is fixed for ever at the instant they qualify. Position #1 is the first
  member ever to reach the gate.
- **A private 3×10 matrix per qualified member.** Level 1 holds 3 teammates, level 2 holds 9, level
  3 holds 27, and so on to level 10's 59,049. A **teammate** is a qualified member anywhere below the
  member in the sponsor tree **who qualified after them** — any depth, no ten-level cap, and nobody
  outside their sponsor subtree.
- **Levels are all-or-nothing.** A level completes when the teammate count reaches
  `(3^(L+1) − 3)/2` — 3, 12, 39, 120, 363, 1,092, 3,279, 9,840, 29,523, 88,572 — and pays its flat
  amount the first time it completes. Partial blocks pay nothing, and a level pays **once, ever**.
- **The reference ladder**: ₱1,000 / ₱2,000 / ₱4,000 / ₱6,000 / ₱12,000 / ₱50,000 / ₱120,000 /
  ₱200,000 / ₱1,200,000 / ₱3,500,000; full ladder **₱5,095,000** per member.
- **Real money, in the e-wallet.** Each completed level is a **withdrawable wallet credit** with a
  ledger row (`ref_type = 'team_harvest'`) and an immutable award row. There is **no monthly close**
  — the stream has no month, no period and no purchase input (D-08).
- **A one-time go-live baseline.** Deploying the code pays nothing. A seed job reconstructs the
  historical queue from the tree (the third direct's registration is the qualification instant),
  records how far every member had already got (`baseline_n`), and only then does the live hook
  start appending. Levels already complete at that instant are recorded **`baseline`** and are never
  paid (D-07); the seed's dry run reports exactly what that is worth before anyone decides.
- **One switch.** `team_harvest_enabled` ships **off**. Qualification accounting runs regardless —
  the structure must be right before the money is — and no award is written while the switch is off.
- **Visibility on existing pages only** — no new routes:
  `/admin/settings` (the ladder card), `/admin/members` (position column, queue card, filter),
  `/admin/members/{id}` (a member's harvest panel), `/admin/commissions?ledger=team_harvest` (the
  ledger), `/admin/reports` (queue and payout cards, CSV), `/earnings?ledger=team_harvest` (the
  member's matrix and statement), `/genealogy` (position badge), `/dashboard` (a tile).
- **Reconciliation, reset parity and audit**, in the shape the last two streams established:
  `wallet_reconcile.php` gains a Team Harvest section, the business reset empties the queue and the
  awards while keeping the configured ladder, every ladder change and every seed/catch-up run is
  audited, and a rebuild job recomputes the teammate counters and reports drift.

### 1.2 The rules in one page

```
REGISTRATION  ──► new user row (sponsor S)
                  if S now holds ≥ 3 member directs and is not queued:
                     position(S) = next queue id                 (once; never renumbered)
                     teammates(A) += 1 for every queued ancestor A of S (any depth)
                  if TEAM_HARVEST is on and the queue is seeded:
                     evaluate every A → pay any level that just completed

SEED (once, at go-live, before the switch is turned on)
                ├─► reconstruct positions from the 3rd direct's (created_at, id)
                ├─► baseline_n(A) = teammates(A) at that instant
                └─► team_harvest_ledger_since = NOW()   → the live hook goes active

AWARD  ──► for a queued member A, for each level L = 1 … min(10, max_levels):
              complete  ⇔  teammates(A) ≥ (3^(L+1) − 3)/2
              payable   ⇔  complete
                           AND threshold(L) > baseline_n(A)      (not already complete at go-live)
                           AND no award row for (A, L)            (a level pays once, ever)
              money     ⇔  amount(L) → withdrawable e-wallet, ref_type = 'team_harvest'
```

**There is no month.** Two of the four reference streams are monthly (Royalty) or purchase-driven
(Unilevel); Team Harvest is neither (reference §5.4: "no purchases, rank or Active status"). Nothing
about the schedule, a quota, an Active flag or an order changes this bonus. The only two inputs are
the **tree** and the **order in which members qualified**.

### 1.3 What is deliberately not built

- **No purchase, order or product input.** Not one line of this stream reads `shop_orders`,
  `products`, `commissions`, `unilevel_awards` or `royalty_awards`. A member who never buys anything
  earns Team Harvest exactly like one who buys daily (reference §5.4).
- **No rank, no Active flag, no quota.** Leader/Supervisor/Manager/Director are irrelevant here, and
  so is the Unilevel monthly minimum. Those streams have their own gates.
- **No monthly cycle, no period table, no cron job.** There is nothing to close and nothing to
  evaluate on a schedule (D-08). The award happens at the qualification event that completes it.
- **No spillover, no compression, no "leg" model.** A teammate is a qualified descendant, at any
  depth; a downline member who qualified **before** the member is permanently excluded and is never
  substituted by somebody else (reference §5.4 and §11.1 #4).
- **No direct-only variant and no matrix placement.** The sponsor tree decides *membership* of the
  matrix; the qualification order decides *placement* in it. Where a teammate sits in the sponsor
  tree is displayed, never used.
- **No retroactive go-live money.** Levels complete at the baseline instant are recorded, not paid
  (D-07). The seed's dry run quantifies them so the business can decide separately (§18 Q1).
- **No reversal path.** Qualification cannot be undone — there is no user deletion and no
  re-parenting — so there is no cancellation event to reverse (§18 Q3).
- **No General Harvest board.** That is the sibling stream and a separate plan. This plan builds the
  **queue it will share** and freezes the contract (D-16).
- **No configurable level thresholds.** The 3, 12, 39… staircase is the matrix; only the amounts and
  the maximum depth are configurable (D-18).
- **No new routes.** Everything renders on existing pages through query parameters, so `routes.php`,
  `routes_inventory.php` and `ParityTest` stay untouched (D-14).
- **No tax withholding and no cost-of-goods model.** Awards are gross, like every other bonus.
- **No QA / retail-customer gate and no Chairman rank.** Older marketing documents propose both;
  they are not this build's source of truth (§12.12).

### 1.4 Delivery: seven gated phases

The feature is one coherent stream of money, but it is **not** delivered as one drop. It is split
into seven phases, each ending in a gate that must be passed — automated suite plus a manual script
— before the next phase starts. The split is drawn along the lines where a phase can be **proven on
its own**.

| Phase | One-line purpose | What the gate proves | Adds money? | Adds UI? |
|---|---|---|---|---|
| **20A** | Add the three tables, the wallet movement and the four settings; teach install, migration and reset about them | A migrated database equals a fresh install, and nothing in the app reads the new objects yet | no | no |
| **20B** | Let an operator edit the ten level amounts and the three knobs; render the ladder arithmetic | Configuration saves, validates, audits and renders — and a registration still writes no queue row | no | admin |
| **20C** | Store qualification: the hook, the append-only queue, the teammate counters and the one-time seed | Positions are fixed at qualification, reconstructed history matches the live hook, and the counters agree with a recount | no | none |
| **20D** | Pay a level when it completes: the award engine, wallet credits, the baseline rule, reconciliation | Every money rule in the spec: thresholds, once-ever, baseline, max depth, idempotency, reconcile | **yes** | none |
| **20E** | Show the money and the queue to administrators: ledger tab, queue card, member panel, reports, CSV | Finance can audit every award and every position from the UI | no | admin |
| **20F** | Show the matrix, the progress and the money to members: earnings tab, matrix card, tile, genealogy | The copy rules hold; the member never reads a promise the engine will not pay | no | member |
| **20G** | Documentation, QA pack, phase badge, acceptance run, sign-off | The release is signed and everything above is reproducible | no | no |

**Why this split:**

- **The database goes first** because every later phase reads it, and because a schema change is the
  only part of this plan that is hard to reverse. Proving install/reset parity in isolation (§5)
  means no later bug can be blamed on the schema.
- **Configuration second**, because it is inert, verifiable by eye, and it is the phase that tells an
  operator what the plan costs before any of it can pay.
- **The queue third, and on its own**, because qualification is the one part of this stream that is
  **irreversible**: a position, once assigned, can never be renumbered, and every later award is
  computed from it. A phase that stores positions but pays nothing is the only safe way to prove
  that rule before money depends on it.
- **Money fourth, as one indivisible phase.** Splitting the engine (for example "award only, no
  baseline") would leave money in a state that is *wrong* rather than merely *incomplete*; a phase
  may be small but it may not be incorrect.
- **Admin before member**, because the ledger is the tool the operator uses to explain and audit the
  money; the member pages are marketing of an already-proven stream.
- **Member pages and the operator pages are siblings** (both depend only on 20D), so they can be
  built in either order or in parallel.
- **Release last** because `APP_PHASE`, the QA pack and the sign-off describe the finished feature —
  they are meaningless before the feature is on.

**Why there is no separate operations phase.** Phases 17F and 19F existed because those streams run
a **scheduled monthly close**. Team Harvest has no schedule: its only jobs are the one-time seed
(20C) and an on-demand catch-up/doctor (20D). They ship with the phase that owns their rules, and
20G's runbook documents the go-live order. A phase whose entire content would be "wrap an existing
command in a cron entry" is not worth a gate.

### 1.5 Scope at a glance

| Area | Deliverable | Phase |
|---|---|---|
| Database | 3 new tables, 1 extended ENUM, 4 settings, 10 ladder rows, migration `024_team_harvest.sql` | 20A |
| Configuration | `TeamHarvestLevel` model, settings card with the ten amounts and three knobs, ladder arithmetic, JS preview | 20B |
| Structure | `HarvestQueue` model, registration hook, append-only positions, teammate counters, `bin/harvest_queue_seed.php` | 20C |
| Money engine | `TeamHarvestAward`, `TeamHarvestService`, the baseline rule, wallet credits, `bin/team_harvest_catchup.php`, reconcile | 20D |
| Admin | `?ledger=team_harvest` tab, members queue card + column + filter, member panel, four report cards, CSV | 20E |
| Member | `?ledger=team_harvest` earnings tab, 3×10 matrix card, progress card, dashboard tile, genealogy badge, CSV | 20F |
| Quality | ~150 new cases across the seven phases, one QA pack (58 checks), documentation | 20A–20G |

---

## 2. The phase protocol

### 2.1 What "self-contained" means in this document

"Self-contained" is a property of **delivery**, not of the schema. The phases share one data model
and one specification on purpose — duplicating them seven times would guarantee drift. A phase is
self-contained when all five of the following hold:

1. **It ships alone.** Merging only this phase leaves production correct, the suite green and every
   existing page unchanged in behaviour (except where the phase is explicitly a UI phase).
2. **It tests alone.** Its gate can be executed end-to-end on a database that contains only the
   phases up to and including itself. No later file or table is needed to observe the result.
3. **It explains alone.** Its section below contains everything needed to implement it: scope, files,
   rules, test cases, manual script, exit gate, rollback and hand-off. A developer who has never read
   this plan can start from §2, §12 and one phase section.
4. **It survives abandonment.** If every later phase were cancelled, this phase is still correct and
   still worth having. Nothing in it depends on a promise made by a phase that does not exist yet.
5. **It rolls back alone.** Reverting phase N leaves phases 1…N−1 intact and working.

The named anti-patterns this forbids:

- **The forward stub.** A file, column or switch that exists only so a later phase can fill it in.
  (A `TODO` in a comment is fine; a half-wired hook is not.) 20A ships tables with no writer on
  purpose — that is a foundation, not a stub — but 20C may not ship an `evaluate()` that returns
  "not implemented".
- **The invisible phase.** A phase whose gate is "the code compiles". Every phase here has an
  observation a tester can make: a page, a count, a log line or a wallet balance.
- **The invalid phase.** A phase that leaves the stream in a state that is *wrong* while it waits for
  the next phase. Money is either fully implemented (20D) or not implemented at all (20A–20C).
- **The shared contract.** A value one phase writes and a later phase changes (for example: shipping
  with the reference amounts in 20B and correcting them in 20F). Settings keys, table names, level
  semantics and the position rule are frozen at the phase that introduces them.

### 2.2 Phase map

| Phase | Name | Builds on | Effort | Cumulative suite |
|---|---|---|---:|---|
| **20A** | Foundation: schema, wallet movement, settings, install parity | — | 1.5 d | 1,162 → ≥ 1,180 |
| **20B** | Configuration: the ten amounts, the three knobs, the ladder card | 20A | 2.0 d | ≥ 1,202 |
| **20C** | The Harvest queue: hook, positions, counters, seed job | 20A, 20B | 3.0 d | ≥ 1,232 |
| **20D** | Money engine: level awards, baseline, wallet, reconcile, catch-up | 20C | 3.5 d | ≥ 1,268 |
| **20E** | Admin visibility: ledger tab, queue card, member panel, reports | 20D | 1.5 d | ≥ 1,292 |
| **20F** | Member visibility: earnings tab, matrix card, tile, genealogy | 20D | 1.5 d | ≥ 1,314 |
| **20G** | Release: docs, QA pack, phase badge, acceptance | all | 1.5 d | 1,318 |

**Total: ~14.5 ideal days**, plus roughly one day of gate overhead spread across the seven phases.
The suite column is a floor, not a target: it counts the cases the phase's gate requires; the
baseline is today's **1,162 / 1,162**.

**Dependency graph**

```
20A ──▶ 20B ──▶ 20C ──▶ 20D ──┬──▶ 20E ──┐
                               └──▶ 20F ──┴──▶ 20G
```

20E and 20F are **siblings**: each depends only on 20D, neither reads the other, and they may be
delivered in any order or concurrently by different developers. The critical path is
20A → 20B → 20C → 20D → (20E or 20F) → 20G ≈ **12.5 ideal days** of sequential work.

**Branches and tags.** One branch, `feature/team-harvest`, cut from `feature/royalty` at
`p19g-release`. One tag per gate: `p20a-foundation`, `p20b-configuration`, `p20c-queue`,
`p20d-money`, `p20e-admin`, `p20f-member`, `p20g-release`, plus the feature tag `phase-20`. A phase
starts from the previous tag and never from an uncommitted tree.

### 2.3 The format of every phase section

Each phase in Part II answers the same questions in the same order:

| Block | What it must contain |
|---|---|
| **Goal** | one sentence: what is true after this phase that was not true before |
| **Builds on** | its only dependency (or "Nothing"), and the tag it starts from |
| **Ships alone because** | the argument for clause 4 of §2.1 — why the phase is correct even if every later phase is cancelled |
| **Effort, suite** | ideal days; the suite floor |
| **Reference** | the exact subsections of Part III this phase implements |
| **Deliverables** | `Database`, `Code`, `Views`, `Docs`, and an explicit **Not yet** list |
| **Rules** | the normative constraints a reviewer checks the diff against |
| **Test plan** | `P20X-Tn` cases with exact expectations |
| **Manual gate** | numbered browser/CLI steps and the `QA20X-nn` identifiers in the QA pack |
| **Exit gate** | the checklist that closes the phase |
| **Rollback** | how to undo this phase alone |
| **Hand-off** | what now exists, which the next phase is allowed to assume |
| **Risks** | what can go wrong *in this phase* |

### 2.4 Definition of done — the universal gate

Every phase's exit gate contains these ten items, and its own phase-specific list. Copy the block
into `docs/phase-log.md` when the phase closes, and fill Appendix H.

```
[ ] Schema state matches the plan: MigrationTest green (20A applies migration 024; later phases change no schema)
[ ] `php bin/build_install_sql.php` run and `--check` exits 0
[ ] `php tests/run.php` — full suite green, exit 0, case count at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0 (money untouched or money correct)
[ ] `powershell -File tests/smoke.ps1` — all checks pass, exit 0
[ ] Every P20X-Tn case for this phase exists, is named for the phase, and passes
[ ] The manual script for this phase is executed by someone who did not write it
[ ] No new PHP warning, notice or deprecation in `storage/logs/` after the manual pass
[ ] The QA pack section for this phase is filled, with evidence under `docs/qa/evidence/phase-20/`
[ ] `docs/phase-log.md` records the phase, tag, counts and deviations; Appendix H is filled
```

### 2.5 What a phase may never do

- **Never leave the suite red.** A phase that cannot get to green is not finished; it is not "mostly
  finished with the tests to follow". The suite is the gate.
- **Never edit a later phase's files.** If 20D needs a change in a view, that view belongs to 20E or
  20F and the change waits. The only exceptions are the shared files each phase is assigned in its
  manifest (`RegistrationService` in 20C and again in 20D, for different reasons).
- **Never invent a contract.** If an implementation must diverge from §12–§14, the plan is amended
  first (a new decision or a corrected table), then the code. A divergence discovered mid-phase is
  recorded as a deviation in the phase log.
- **Never pay money in a structural phase** and never change the structure in a money phase. 20D
  assumes the queue is correct; it does not ship a "re-queue the member" convenience.
- **Never renumber a position.** Not in a seed, not in a repair, not in a test. Positions are facts.
- **Never add a route.** D-14 is absolute for every phase: query parameters on existing pages only.

### 2.6 Before you begin (once, before 20A)

1. Cut `feature/team-harvest` from `feature/royalty` at `p19g-release`; tag `p20-start`.
2. Back up the database (`mysqldump`) and the working tree, outside the repository. The project's
   standing rule: a backup before any migration.
3. Record the baseline: `php tests\run.php` → **1,162 / 1,162**; `tests\smoke.ps1` → **248 / 248**;
   `php bin\build_install_sql.php --check` → exit 0; `php bin\wallet_reconcile.php` → exit 0. If the
   baseline is not green, stop — a gate measured from a red baseline proves nothing.
4. Read §12 and §14 once, in full. They are the contract every phase is written against. Then read
   only the phase you are building; the other phases are not your scope.
5. Read §12.2 and §12.3 twice. Qualification order and position are the only irreversible facts this
   plan creates, and every later number is a consequence of them.

---

## 3. Current state and the gap

### 3.1 The sponsor tree is the only structure, and it is immutable

| Fact | Evidence |
|---|---|
| Every member has one sponsor: `users.sponsor_id`, nullable, indexed | [`schema.sql`](../system/database/schema.sql#L70-L111) — `KEY idx_users_sponsor (sponsor_id)` |
| A sign-up without a sponsor is placed under the **primary admin** (the company account) | [`SponsorService::defaultSponsor()`](../system/app/Services/SponsorService.php#L105-L144) |
| The tree only grows: there is no re-parenting and no member delete anywhere in the admin surface | `Member` exposes `setStatus()` / `toggleStatus()` only; the single `DELETE FROM users` is the business reset ([`DatabaseResetService`](../system/app/Services/DatabaseResetService.php#L1028)) |
| The existing downline helper is **depth-capped at ten** | [`SponsorService::MAX_LEVELS = 10`](../system/app/Services/SponsorService.php) caps `chain()` and `downline()` |

The last row matters: **Team Harvest has no depth cap** (reference §5.4 — "your sponsor, your uplines,
or anyone outside your sponsor subtree" is the only boundary). `SponsorService::downline()` cannot be
reused for it; the correct idiom already exists in
[`RoyaltyRank::map()`](../system/app/Models/RoyaltyRank.php#L316-L407): load the member forest once,
build a children map, walk it iteratively, never trust recursion.

### 3.2 There is no qualification concept

Nothing in the application knows what "qualified" means for a Harvest stream:

- there is no queue, no position, no qualification timestamp and no stored order of anything;
- the nearest existing gate is Royalty's **Leader** rank — ten directs — which is *derived on every
  call* and deliberately stores nothing ([`RoyaltyRank`](../system/app/Models/RoyaltyRank.php));
- the reference's Harvest gate is **three directs**, checked once, at registration, and appended to
  an array that is never re-sorted (reference §3, `mx`).

A derived rank is the right shape for a monthly ladder. It is the **wrong** shape here: a position
must be fixed when it happens, because every Team Harvest award is computed from the difference
between two positions. This plan therefore stores qualification, which is the single largest
difference between the simulator and this build (§12.12).

### 3.3 Nothing derives a matrix

No file in `system/` contains the 3-ary arithmetic (`3^L`, `(3^(L+1) − 3)/2`, "slots", "level block").
The only implementations of it are the reference document and the simulator behind it. The new
arithmetic is small but it is **money arithmetic**, so it lives in one place
([`TeamHarvestLevel`](../dmp/plan-ecowealth-team-harvest.md#13-data-model) in 20B and
`HarvestQueue` in 20C) and every view reads it; no view computes a threshold of its own.

### 3.4 Every existing money stream hangs off an event with an amount

| Stream | The event | The amount | Ledger |
|---|---|---|---|
| Package referral | activation (a package purchase) | the package's per-level peso amount | `commissions` |
| Indirect referral | registration | a flat per-level amount | `commissions` (`type = indirect_referral`) |
| Unilevel repeat purchase | an order reaching `paid` | a percentage of the line base | `unilevel_awards` |
| Royalty | the monthly close | a percentage of the month's volume | `royalty_awards` |

Team Harvest's event is **a registration that completes a count**, and its amount is a **flat,
pre-configured peso amount**. It is the first stream in this application whose money does not depend
on what anybody bought. That has three consequences, and each is a decision in §4:

1. There is no amount to prorate, round or split — the award is the configured amount, to the peso
   (D-10, §12.9).
2. There is no order or month to hang the award on, so the ledger needs its own grain and the
   uniqueness anchor must be `(member, level)` (D-06).
3. The only thing that can go wrong is **the structure** — a wrong position, a missing counter
   increment, a duplicated qualification — so the structural phase (20C) is the one that gets the
   hardest tests, and the money phase (20D) is deliberately thin on top of it.

### 3.5 None of the three ledgers can hold a level award

| Ledger | Grain | Why it cannot hold this stream |
|---|---|---|
| [`commissions`](../system/database/migrations/012_commissions.sql) | `(type, source_user_id, user_id, level)`, with `activation_id` and `package_id` FKs **NOT NULL** | Requires an activation and a package; there is neither. Its unique key would also forbid a second award to the same member at the same level after a repair |
| `unilevel_awards` | one order line × recipient × level 1–10 | Every row needs an order, an item, a product, a base and a rate. A Team Harvest level has none of those |
| `royalty_awards` | one member × one closed month | One row per member per month (D-08 of Phase 19); Team Harvest pays per level, on no schedule |

A separate ledger is therefore mandatory, and it is the simplest of the four: **no type column, no
period, no product, no rate, no percentage** — a member, a level, and an amount
([`team_harvest_awards`](#134-team_harvest_awards--the-money-ledger)).

### 3.6 Wallet, visibility and reports today

| Layer | Today | What this plan adds |
|---|---|---|
| Wallet | dual-bucket (`ewallet_balance` / `withdrawable_balance`), eleven `ref_type` values, one idempotent ledger row per movement ([`WalletService`](../system/app/Services/WalletService.php)) | a twelfth value, `team_harvest`, credited withdrawable (D-11) |
| Member pages | `/earnings` has three tabs (`package`, `repeat`, `royalty`), `/dashboard` a tile per stream, `/genealogy` the sponsor tree | a fourth earnings tab, a fourth tile, a position badge on the genealogy row |
| Admin pages | `/admin/commissions` has three tabs, `/admin/members` a list and a member page, `/admin/reports` a card grid, `/admin/settings` eight cards | a fourth tab, a queue card and a `Harvest` column, a member panel, four report cards, one settings sub-block |
| Jobs | six cron entries (shop expiry, auto-complete, transfer limits, unilevel close, unilevel release, royalty close) | **no new cron entry** — one on-demand seed and one on-demand catch-up (§14.10, §14.11) |
| Reconciler | six sections, exit code 0/1 | a seventh section |

### 3.7 The simulator is the authority for the mechanics, not for the storage

| What the simulator does | What it cannot do | What this plan does |
|---|---|---|
| `mx` is an array of qualified ids in qualification order; a member is appended the moment their third direct registers ([`reg()`](../simulator.html#L126-L137)) | Nothing is persisted but a `localStorage` blob; clearing site data erases the whole network | A row in `harvest_queue`, one per member, `id` = position, append-only |
| `counts()` walks every qualified member's sponsor chain and increments each queued ancestor (reference §5.1) | Recomputed from scratch on every render; no history, no audit | The same walk at qualification time, maintained as a counter on the queue row; a recount verifies it (§14.4) |
| `levelsT(n)` derives the ten blocks from `n` alone (reference §5.2) | A partial block shows a progress bar but pays nothing; a completed level is never recorded as paid | Derived identically for display; **paid levels are ledger rows** (§12.7) |
| Team Harvest money is "derived" — it exists by construction and has no payment event (reference §2.5) | There is no cash, no wallet and no way to say when a level paid | A level pays once, into the e-wallet, with a ledger row (D-06) |

The reference's own numbers are the contract: the ladder (₱1,000 … ₱3,500,000), the thresholds
(3, 12, 39 … 88,572), the all-or-nothing rule, the "qualified after me" exclusion and the
"no purchases, no rank" list. Every one of them is reproduced exactly and is pinned by a test in
§16.

### 3.8 The gap, in one table

| Need | Today | This plan | Phase |
|---|---|---|---|
| A stored, ordered qualification record | none | `harvest_queue` (position = `id`, append-only) | 20A / 20C |
| A qualification hook on registration | none | `HarvestQueue::qualifySponsor()` inside `RegistrationService::register()`'s transaction | 20C |
| A reconstructed history for the existing tree | none | `bin/harvest_queue_seed.php` (deterministic, idempotent, reports the baseline) | 20C |
| The 3-ary arithmetic (slots, thresholds, placement) | none | `TeamHarvestLevel` + `HarvestQueue::matrix()` | 20B / 20C |
| A ledger that can hold one row per member per level | three ledgers, none with the right grain | `team_harvest_awards`, unique on `(user_id, level)` | 20A / 20D |
| Wallet money + reconciliation | `WalletService` / `wallet_reconcile.php` (six sections) | `ref_type = 'team_harvest'` and a seventh section | 20A / 20D |
| An award rule that cannot pay twice | none | state-based evaluation against the award rows (§14.5) | 20D |
| Member visibility | `/earnings` shows three streams | a fourth tab, a matrix card and a tile | 20F |
| Admin visibility | three commission tabs | a fourth tab, a queue card and four report cards | 20E |

### 3.9 Relationship to the General Harvest stream

The reference has two Harvest streams. They share **one** queue and nothing else:

| | Team Harvest (this plan) | General Harvest (a later plan) |
|---|---|---|
| Uses the queue | yes — teammate counts come from positions | yes — the board *is* the queue |
| Board shape | private 3×10, filled by teammates | one shared 3-ary board over all positions |
| Placement | qualification order inside the member's own matrix | the queue index itself (`q`) |
| Can pay across team lines | **no** — sponsor subtree only | **yes** — queue positions, whoever they are |
| Ladder | ₱1,000 … ₱3,500,000 (₱5,095,000) | ₱2,000 … ₱5,000,000 (₱8,381,000) |

`harvest_queue` is therefore named for the **stream family**, not for this bonus, and its contract
(position = `id`, append-only, staff excluded) is frozen by D-16 so the General Harvest plan can
consume it without a migration. This plan pays nothing from the shared board and must not grow a
General Harvest column "while we are here".

---

## 4. Decisions

Each decision is a contract for the implementation and the tests. Alternatives are listed so a later
review can reopen a decision without re-deriving it. The **Ships in** column is the phase that
introduces the rule; no later phase may change it.

| ID | Decision | Rationale | Rejected alternative | Ships in |
|---|---|---|---|---|
| **D-01** | Qualification is a **stored, append-only record** (`harvest_queue`), not a derived property | A position must be fixed when it happens: every award is computed from the difference between two positions, and General Harvest will need the same order | Derive `directs >= 3` at read time — positions would shift as the tree changes, with no history and no way to compute past awards | 20A/20C |
| **D-02** | The gate is **≥ 3 direct member accounts** (configurable 1–10 via `team_harvest_qualify_directs`), counting **every** `role='member'` account regardless of `member_status`; staff and the company account never count and never qualify | Mirrors reference §3 ("any age or status"); making the gate depend on status would make qualification reversible, which append-only forbids | Count only active/suspended-excluded directs — a status change would then have to un-qualify a member and renumber the queue | 20A/20C |
| **D-03** | The queue order is the **insert order**, identified by `harvest_queue.id` (1-based position); never renumbered; gaps are allowed and meaningless | Auto-increment is concurrency-safe (no `MAX()+1` race) and monotone by construction | A `position = MAX(position)+1` column — needs a lock and can collide under two simultaneous registrations | 20A/20C |
| **D-04** | The queue is **seeded once at go-live**, by reconstructing the qualification order from the third direct's `(created_at, id)`; the live hook is **inert until the seed has run** | The only honest reconstruction of a history nobody recorded; gating the hook on the seed removes the "history appends after new members" hazard entirely | Backfill after the hook is live — new qualifications would take earlier positions than historical ones | 20C |
| **D-05** | `n` (teammates) is a **maintained counter** on the queue row (`harvest_queue.teammates`), incremented at each qualification of a descendant, recomputed by a rebuild, and verified by the reconciler | One query per page and one definition of `n`; the increment is the same ancestor walk the reference performs | Compute `n` per page by subtree walk — O(subtree) per render, and a second definition of the same number | 20C |
| **D-06** | Money is a **ledger** (`team_harvest_awards`), one row per `(member, level)`, written when the evaluation first observes the level complete; a level pays **once, ever**, and the row is never edited | The reference derives this money, which cannot be audited or paid; a bonus the business cannot explain is not shippable | Derive and display only (as the simulator does) — no cash, no audit, no reconciliation | 20A/20D |
| **D-07** | **Baseline**: each queue row records `baseline_n` (the member's `n` at the seed instant). Levels with `threshold(L) ≤ baseline_n` are recorded `status = 'baseline'` with their amount and **never credited**; no retroactive go-live money | Phase 17's D-16 stance: the company never agreed to pay for a period before the stream existed. The seed's dry run quantifies exactly what is excluded | Pay the backlog at enablement — an unbudgeted payout decided by a deploy | 20C/20D |
| **D-08** | **No month, no period table, no close job.** The award happens on the qualification event; the audit is the ledger | Reference §5 has no monthly cycle for this stream; inventing one would delay money that is already earned | Evaluate monthly like Royalty — a period table, a close job and up to a month of delay for an event with no volume in it | 20A |
| **D-09** | **One switch**: `team_harvest_enabled` (off by default). Qualification accounting always runs; no award is written while it is off | The structure must be complete and correct before money depends on it, and turning the money off must never corrupt positions | A second "pay" switch — it would need a pending state, a pay job and a resumable batch, for a stream whose award *is* the payment | 20A/20B |
| **D-10** | The ten amounts live in **`team_harvest_levels`** (ten rows), not in settings; they are edited on the settings card, validated as money, and kept by a business reset | Ten money rows are a table, not ten `settings` strings; the same shape as `package_levels` and `product_unilevel_levels` | Ten `team_harvest_level_N` keys — unreadable in `SELECT * FROM settings`, unvalidatable as a set | 20A/20B |
| **D-11** | Award and wallet credit are **one transaction**; `ref_type = 'team_harvest'`; the credit is **withdrawable**; no hold and no escrow | Phase 18's hold exists because a product bonus can be reversed by a delivery failure; a structural plan bonus has no delivery to wait for | Reuse the unilevel escrow hold — would delay money for a feature the hold does not protect | 20A/20D |
| **D-12** | **No reversal and no void** in v1: qualification is irreversible, and a wrong award is a forward-only business decision (§18 Q3) | There is no cancellation event in this stream. A manual void tool is a money-editing surface that deserves its own plan, not a convenience in a first release | Admin "void award" action — permanent irreversible money edits, with no event to justify them | 20D |
| **D-13** | **No status gate on earning.** A suspended or deactivated member still earns; the existing payout rules decide whether they can withdraw | The reference has no status concept, and a status gate would create an unpaid backlog whose timing nobody can explain | Skip ineligible members and pay them later — an invisible IOU with no ledger | 20D |
| **D-14** | **No new routes**: `/admin/settings`, `/admin/members` (`?harvest=`), `/admin/members/{id}`, `/admin/commissions?ledger=team_harvest`, `/admin/reports`, `/earnings?ledger=team_harvest`, `/genealogy`, `/dashboard` | `APP_PHASE`/`ParityTest` gating stays untouched; one page per concept | Separate pages/routes — more surface, more inventory entries, more tests | all |
| **D-15** | `APP_PHASE` becomes **20** | The build should report the phase that shipped the feature | Leave it at 19 — the new UI would render with a stale phase badge | 20G |
| **D-16** | `harvest_queue` is **shared infrastructure**: its meaning (position = `id`, append-only, staff excluded) is frozen here for the General Harvest plan | Two Harvest streams on one order is the reference's design; two orders would eventually disagree | A private queue per stream — guaranteed to diverge, and impossible to explain to a member | 20A/20C |
| **D-17** | **No compression, no spillover, no substitution.** A downline member who qualified earlier never counts, and no later member takes their place | Reference §5.4 and §11.1 #4 are explicit; the exclusion is the plan's traffic rule | Fill the matrix with "the next qualified member anywhere" — a different plan with different economics | 20C/20D |
| **D-18** | Levels are **structural**: 3^L slots and threshold `(3^(L+1) − 3)/2`, fixed. Only the amounts and the maximum depth (1–10) are configurable | A configurable threshold would invalidate every stored `n` and every historical baseline, and would make two members' matrices incomparable | Configurable thresholds "for tuning" — the plan's arithmetic becomes unexplainable and unauditable | 20B |
| **D-19** | The evaluation is **state-based**: it compares the current counter with the award rows, so a missed event **self-heals** on the next evaluation (or the catch-up run) | The registration transaction is the most critical path in the application; a swallowed exception must not be the difference between paid and unpaid | Event-count-based awards (increment per event) — a single lost event silently under-pays for ever | 20D |

---

# Part II — The phases

## 5. Phase 20A — Foundation

**Goal.** A database that carries every object the feature will ever need, a wallet vocabulary that
can name the new movement, and an install/reset/migration path that knows about all of it — with **no
code path reading any of it**. After 20A the application behaves exactly as it did at `p20-start`.

**Builds on.** Nothing. This is the root of the graph.

**Ships alone because.** The three new tables have no reader, the new settings have no reader (the
settings page does not render them until 20B), and the new `ref_type` value cannot appear in a ledger
row because nothing constructs it. The only observable changes are two filter options in the wallet
statement (Rule 6) and different numbers in `install`'s verification output.

**Effort.** 1.5 ideal days. **Suite.** 1,162 → ≥ 1,180 cases, all green.

**Reference.** §13 in full (the model and the migration text), §12.3 (the queue), §12.9 (money).

**Deliverables**

**Database**

- `system/database/migrations/024_team_harvest.sql` — the migration of §13.6, verbatim: three tables
  (`harvest_queue`, `team_harvest_levels`, `team_harvest_awards`), the `ewallet_ledger.ref_type`
  extension, four settings and the ten ladder rows. Idempotent: every `CREATE TABLE` is
  `IF NOT EXISTS`, the `ENUM` change is guarded by an `information_schema` probe and an
  `ALTER`/`DO 0` pair, the settings insert is `ON DUPLICATE KEY UPDATE key_name = key_name`, and the
  ladder insert is `ON DUPLICATE KEY UPDATE level = level` (a re-run must never overwrite an
  operator's amounts).
- `system/database/schema.sql` — the fresh-install mirror of §13.7, block for block, so
  `MigrationTest`'s migrated-vs-fresh comparison stays green. The `users.sponsor_id` comment gains
  one line naming the queue, and the `ewallet_ledger` comment gains `team_harvest`.
- `system/database/seeds/001_settings.sql` — the four keys **and** the ten ladder rows, in the same
  order and with the same defaults as the migration. `team_harvest_ledger_since` seeds as the empty
  string: the stream is not merely off, it is **unseeded** (D-04).
- `system/database/install/footer.sql` — the expected-counts comment: **36 tables, 77 settings**.
- `system/database/install.sql` — regenerated by `php bin/build_install_sql.php`; never hand-edited.

**Code**

- `system/app/Services/DatabaseInstaller.php` — `EXPECTED`: `tables_created` 33 → **36**,
  `settings_rows` 73 → **77**, plus `team_harvest_levels_rows` → **10**; the settings failure message
  becomes "74 keys plus the three `install_*` metadata rows"; `TRANSACTIONAL_TABLES` gains
  `harvest_queue` and `team_harvest_awards`, so a fresh install fails verification if either is
  non-empty. `team_harvest_levels` is **catalogue**, like `package_levels`, and is not in that list.
  `DEMO_TABLES` is unchanged (the demo seed writes no queue rows).
- `system/app/Services/DatabaseResetService.php` — `ALL_TABLES` gains the three names (33 → **36**);
  `ZERO_TABLES` gains `harvest_queue` and `team_harvest_awards`; `AUTO_INCREMENT_TABLES` gains both
  (they are the only two with a counter); `DELETE_GROUPS` gains a **Team Harvest** group *before* the
  members group (both tables reference `users`, so they must go first). A business reset also clears
  `team_harvest_ledger_since` — the baseline instant describes a queue that no longer exists, and
  leaving it behind would let a fresh network pay against a dead baseline (§13.8 I-09).
  **The integrity map is not touched in this phase** — it names `TeamHarvestService`, which does not
  exist until 20D.
- `system/app/Services/WalletService.php` — `REF_TYPES` gains `team_harvest`. This *must* ship with
  the enum: [`WalletTest`](../system/tests/Cases/WalletTest.php) P5-T8 asserts that `REF_TYPES` and
  the live enum agree **in both directions**, so the column and the constant cannot land in different
  phases.
- `system/app/helpers.php` — `wallet_ref_label()` gains *"Team Harvest bonus"* and
  `wallet_ref_icon()` gains `bx-layer`, because `partials/wallet_statement.php` renders one filter
  option per `REF_TYPES` entry.

**Docs**

- `docs/qa/phase-20-team-harvest.md` — created with the header, the "what is already automated"
  block and **section A only** (checks `QA20A-01` … `QA20A-08`). Every later phase appends its own
  section; the file is never rewritten.
- `docs/qa/README.md` — one Phase 20 row in the contents table, marked *in progress (20A)*.
- `docs/qa/evidence/phase-20/README.md` — the evidence folder index.

**Not yet**

- No `HarvestQueue`, no `TeamHarvestLevel`, no `TeamHarvestAward`, no `TeamHarvestService`, no
  controller, no view, no hook, no job, no integrity-map entry. The tables are empty and unread.

**Rules**

1. **The migration is additive and idempotent.** Re-running it on a migrated database is a no-op: no
   error, no duplicate settings, no overwritten ladder amount, no lost rows. `MigrationTest` proves it
   by running the file twice.
2. **`schema.sql` and the migration must be equivalent.** `MigrationTest` builds one database from the
   migrations and one from `schema.sql`, then compares table structures. A column that exists in one
   and not the other fails the phase.
3. **The seed and the migration must agree.** `SeedParityTest` fails unless seed 001 carries every
   settings key the schema/migration introduces. The four keys and the ten ladder rows are added to
   both in this phase.
4. **Defaults are off, and the baseline is empty.** `team_harvest_enabled = '0'` and
   `team_harvest_ledger_since = ''`. A fresh install and a migrated install both pay nothing, and
   neither pretends to be seeded.
5. **No backfill.** No `UPDATE … JOIN` runs over `users`; the history is reconstructed by 20C's seed
   job, on demand, with a dry run, not by a migration.
6. **The one visible change is the wallet statement filter.** `partials/wallet_statement.php`
   enumerates `WalletService::REF_TYPES`, so the member and admin wallet filters gain a *Team Harvest
   bonus* option in this phase. It cannot match a row until 20D. This is accepted, documented in the
   QA pack, and is the price of keeping the enum and the constant in one phase (Rule 7).
7. **Enum and constant move together.** See `WalletService::REF_TYPES` above. Never leave the database
   accepting a value PHP rejects, or vice versa.
8. **The reset empties the structure and keeps the ladder.** After a business reset,
   `harvest_queue` and `team_harvest_awards` are empty, `team_harvest_ledger_since` is `''`,
   `team_harvest_levels` keeps its ten rows and the four settings keep their values.
9. **The ladder is seeded exactly once.** The migration inserts the ten reference amounts, and a
   re-run changes nothing. An operator's edit is never overwritten by a deploy.
10. **No application file outside the manifest changes.** `git diff --stat` must touch only the files
    listed above. A change in a controller or a view means the phase has crept.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P20A-T1 | Apply 024 to a database at `p20-start` | 3 tables, the extended enum, 4 settings and 10 ladder rows exist; no error |
| P20A-T2 | Run 024 a second time | No error; object counts, settings and ladder amounts identical to the first run |
| P20A-T3 | Migrated vs. fresh (`MigrationTest`) | Structures identical; the test's ordered migration list includes `024_team_harvest.sql` |
| P20A-T4 | `SeedParityTest` | Seed 001 and the schema carry the same 77 keys, including the four new ones |
| P20A-T5 | `php bin/build_install_sql.php --check` | Exit 0, no output: the bundle matches its sources |
| P20A-T6 | Fresh install (`InstallTest`) | Verification reports **36** tables, **77** settings, 10 ladder rows, 2 staff, 0 members, 7 products, 2 packages, 18 levels, 10 contents |
| P20A-T7 | Insert one `harvest_queue` row, then run install verification | The `transactional` check fails with the table named — proving Rule 4's guard is wired |
| P20A-T8 | Business reset (`DbResetTest`) | `harvest_queue` and `team_harvest_awards` are 0; `team_harvest_ledger_since` is `''`; the ten ladder rows and the four settings survive; `ALL_TABLES` is 36 names |
| P20A-T9 | `WalletService::REF_TYPES` vs. the live enum (`WalletTest` P5-T8) | Exactly equal in both directions, **12** values |
| P20A-T10 | `wallet_ref_label('team_harvest')` | "Team Harvest bonus"; the icon resolves to `bx-layer` |
| P20A-T11 | Defaults on a migrated database | `team_harvest_enabled = '0'`; `team_harvest_qualify_directs = '3'`; `team_harvest_max_levels = '10'`; `team_harvest_ledger_since = ''` |
| P20A-T12 | The ten seeded amounts | `1000, 2000, 4000, 6000, 12000, 50000, 120000, 200000, 1200000, 3500000`; total 5,095,000.00 |
| P20A-T13 | Delete a member who is not queued | No error; no queue row to cascade |
| P20A-T14 | Delete a member (no UI does this; the reset is the only path) | The queue row, the awards and the wallet ledger rows all cascade on `users`; no orphan, no FK error, and the reconciler stays clean |
| P20A-T15 | Any application file outside the manifest | `grep -r -e harvest_queue -e team_harvest system/app` returns only `WalletService`, `helpers.php`, `DatabaseInstaller`, `DatabaseResetService` |
| P20A-T16 | The full suite | 1,162 + the phase's cases, all green, exit 0; `ParityTest` and `RouterTest` untouched and green |

**Manual gate — `QA20A-01` … `QA20A-08`**

Run on a **scratch** schema built from the previous tag, not on the development copy.

1. `QA20A-01` — `mysql < 024_team_harvest.sql`, then `SHOW TABLES LIKE '%harvest%'` → three tables.
   Re-run the file → no error.
2. `QA20A-02` — `SHOW COLUMNS FROM harvest_queue` → `id, user_id, qualified_at,
   directs_at_qualification, teammates, baseline_n, source, created_at`;
   `SHOW COLUMNS FROM team_harvest_awards` → `id, user_id, level, threshold_n, n_at_award, amount,
   status, ledger_id, credited_at, created_at`; `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'` →
   the enum ends `'royalty','team_harvest'`.
3. `QA20A-03` — `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'team_harvest%'` → 4;
   `SELECT COUNT(*), SUM(amount) FROM team_harvest_levels` → 10, 5095000.00.
4. `QA20A-04` — open `/admin/settings`: no Team Harvest block appears (it is 20B's). Open
   `/admin/members/{id}`: no harvest panel.
5. `QA20A-05` — register three members under one sponsor. `SELECT COUNT(*) FROM harvest_queue` → 0:
   no hook exists yet. The sponsor's page is unchanged.
6. `QA20A-06` — `/wallet` → the statement filter now lists *Team Harvest bonus*; selecting it returns
   no rows.
7. `QA20A-07` — rebuild the bundle and re-install on an empty schema: the verification pack prints
   `tables_created = 36`, `settings_rows = 77`, `team_harvest_levels_rows = 10`;
   `php bin/wallet_reconcile.php` exits 0.
8. `QA20A-08` — run the development reset console once: the two transactional tables are empty, the
   baseline key reads `''`, the ten ladder rows and four settings survive, and verification passes.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P20A-T1 … P20A-T16 pass
[ ] MigrationTest proves a second run is a no-op and that migrated == fresh
[ ] InstallTest / InstallSqlTest / SeedParityTest / DbResetTest / WalletTest green at 36 / 77 / 10 / 12
[ ] git diff --stat touches only the 20A manifest
[ ] Tag p20a-foundation; docs/phase-log.md entry; Appendix H row
```

**Do not start 20B until every box is ticked.**

**Rollback**

Drop the three tables, restore `ewallet_ledger.ref_type` to its previous enum, delete the four
settings and revert `REF_TYPES`/labels. No money exists to lose, so the only care needed is restoring
the enum *before* dropping the tables (a row cannot hold a value the enum no longer allows — there are
none, but do it in that order for consistency). `schema.sql`, the seed and `install.sql` return to
their previous content; `install.sql` is regenerated, never hand-repaired.

**Hand-off to 20B**

- A migrated database with every column, table and setting the feature needs, and ten ladder rows at
  the reference amounts.
- A fresh-install bundle that produces the same database, verified by `MigrationTest`.
- A reset engine that empties the queue and the awards, clears the baseline, and preserves the ladder.
- A wallet vocabulary that can name `team_harvest`.
- **Nothing that reads any of it.** 20B may assume the tables exist, are empty, and are inert.

**Risks**

| Risk | Mitigation |
|---|---|
| `schema.sql` drifts from the migration | `MigrationTest` compares structures; the gate runs it, not just the author |
| `install.sql` is hand-edited | Regenerated only; `--check` in the gate |
| The enum/`REF_TYPES` pair is split across phases | Both in 20A, with a test that compares them in both directions |
| A migration overwrites an operator's ladder on re-deploy | The ladder insert is `ON DUPLICATE KEY UPDATE level = level` (Rule 9); P20A-T2 pins it |
| The reset leaves a stale baseline behind | Rule 8 and P20A-T8 assert the key is empty after a business reset |

---

## 6. Phase 20B — Configuration

**Goal.** An administrator can set the ten level amounts, the qualification gate and the maximum
depth, see exactly what the ladder pays (slots, teammates needed, cumulative, full-ladder total), and
know whether the stream is seeded — all on one card. **No money moves and no position is written.**

**Builds on.** 20A (the tables, the settings, the ten seeded amounts).

**Ships alone because.** The ladder is inert without an engine and without a queue: every value this
phase writes is read by nothing until 20D. The phase is also the only one a non-developer can verify
entirely by eye, which makes it the right place to stop and look before any money code exists.

**Effort.** 2.0 ideal days. **Suite.** ≥ 1,202 cases.

**Reference.** §12.6 (the ladder and the thresholds), §12.10 (validation and ceilings), §13.3 (the
ladder table), §13.5 (the settings), Appendix A (the preset).

**Deliverables**

**Database.** None. 20A shipped every table, column, setting and ladder row this phase writes.

**Code**

- `system/app/Models/TeamHarvestLevel.php` (new) — the ladder, read-only except through `save()`:
  `rows()`, `map()`, `amounts()`, `amount(int $level)`, `save(array $amounts, ?array $actor)`,
  `validate(array $amounts)`, `referencePreset()`, `forForm()`, `total()`, `cumulative()`,
  `threshold(int $level)`, `slots(int $level)`, `maxLevels()`, `qualifyDirects()`, `enabled()`.
  Zero-filled on read, exactly like [`ProductUnilevel`](../system/app/Models/ProductUnilevel.php):
  a missing row reads `0.00`, and the model writes all ten rows on save (delete-then-insert inside
  the caller's transaction) so a partial ladder can never exist.
- `system/app/Controllers/AdminSettingsController.php` — `TEAM_HARVEST_FIELDS`,
  `TEAM_HARVEST_TOGGLES` and `TEAM_HARVEST_AMOUNTS` constants in the same
  `{key,label,rule,suffix,hint}` / `{label,hint}` shapes as the existing ones, merged into
  `sectionKeys()`, `sectionToggles()`, `fieldRules()` and `fieldLabels()`; `settingsContext()` passes
  `team_harvest_fields`, `team_harvest_toggles`, `team_harvest_amounts`, `team_harvest_ladder`
  (the read-only table), `team_harvest_status` (seeded?, queue size, open liability) and
  `team_harvest_preset`. Section stays `commissions`. The save path writes the switches and the ten
  amounts in one transaction and audits `team_harvest.ladder.update` (before/after) only when
  something changed.
- Views: `admin/settings/_team_harvest.php` (new partial), `admin/settings/_commissions.php` (include
  the sub-block after the Royalty card).
- `system/public/assets/js/app-team-harvest.js` (new) — the live cumulative and full-ladder total, the
  per-member maximum, plus the **Load reference ladder** and **Clear** buttons. Progressive
  enhancement only: with JS off the form still saves and still validates.

**Docs.** `docs/qa/phase-20-team-harvest.md` gains section B (`QA20B-01` … `QA20B-09`).
`system/README.md` gains the settings block and the ladder. `docs/admin-guide.md` gains the card
section. `docs/deploy.md` gains the four keys.

**Not yet.** No `HarvestQueue`, no `TeamHarvestService`, no hook, no award, no queue row, no
member-facing page, no seed job. Registering members after this phase still writes nothing.

**Rules**

1. **Ten rows, always.** The ladder is exactly levels 1–10; blank and `0` are equivalent; a save
   writes ten rows or the ladder is cleared, never nine (D-10).
2. **Amounts are money.** `nullable|numeric|gte:0|lte:10000000|decimal:2`. The inputs carry
   `min="0" max="10000000" step="0.01"`; the server does not trust them.
3. **The total has a ceiling.** The ten amounts must total no more than **₱25,000,000** — a typo
   guard (the reference ladder totals ₱5,095,000), refused as a `sum` field error, never clamped.
4. **Enabling with a zero ladder is refused.** `team_harvest_enabled = 1` and a zero total → "Turn the
   switch off or give at least one level an amount above zero."
5. **The gate and the depth are small integers.** `team_harvest_qualify_directs` 1–10,
   `team_harvest_max_levels` 1–10. Zero, eleven or a decimal is a field error. Changing the gate never
   moves an existing position; after a **lowering**, run `harvest_queue_seed.php --rebuild` to append
   the members who now hold it (their qualification has no new direct to trigger it).
6. **The card tells the truth about the state.** It renders the four-state strip of Rule 7; it never
   claims the stream is live when it is unseeded or off.
7. **The status strip has exactly four states**, in this order: *unseeded* ("no position has been
   assigned; the seed job must run before anything can pay"), *seeded and off*, *seeded and on*, and
   *seeded, on, with a pending backlog* (levels complete since the baseline that no evaluation has
   paid yet). Each names the single next action.
8. **The ladder table is generated, never typed.** Slots `3^L`, teammates needed `(3^(L+1) − 3)/2`,
   the amount from the saved row and the cumulative sum all come from `TeamHarvestLevel`; the view
   computes none of them.
9. **The card shows the full-ladder total and the per-member maximum in the same sentence**, so an
   operator who types ₱3,500,000 at level 10 reads what it commits to. The reference preset's total
   (₱5,095,000) is the seeded value; the card never restores it by itself.
10. **Saving the ladder never rewrites history.** Awards snapshot their amount (§13.4); changing a
    level affects only levels not yet paid. The card says so in one line.
11. **The card is one card, in the same place as the Royalty card** — inside the *Commissions*
    settings section, after the Royalty sub-block. The two switches, the two numbers, the ten
    amounts and the ladder table are one form with one save button.
12. **No JavaScript-only validation.** With JS off the form still saves and still validates; only the
    live totals are missing.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P20B-T1 | Open the settings card on a fresh database | Ten inputs show the reference amounts; the switch is off; the gate reads 3; the depth reads 10; the strip says *unseeded* |
| P20B-T2 | Save the reference ladder and the three knobs | Four settings persisted, ten rows written, `team_harvest.ladder.update` audit row with before/after |
| P20B-T3 | Save three blank amounts | Ten rows written; blanks stored as `0.00`; the form shows blanks for zero |
| P20B-T4 | Ladder totalling 30,000,000 | `team_harvest_amounts.sum` error; nothing written (transaction) |
| P20B-T5 | One amount `10000000.01` | Field error on that level |
| P20B-T6 | One amount with four decimals (`1000.1234`) | Field error (`decimal:2`) |
| P20B-T7 | Switch on with all ten at 0 | Error on `team_harvest_enabled` |
| P20B-T8 | `qualify_directs = 0` / `11` / `2.5` | Field error each time; the previous value survives |
| P20B-T9 | `max_levels = 0` / `11` | Field error each time |
| P20B-T10 | `max_levels = 3` | Saved; the ladder table marks levels 4–10 as **not paid** (the amounts are kept) |
| P20B-T11 | The card's generated table | `3, 9, 27 …` slots; `3, 12, 39 …` thresholds; cumulative `1,000 … 5,095,000`; totals equal Appendix A |
| P20B-T12 | `TeamHarvestLevel::amount(11)` / `threshold(0)` | `0.00` / refused with `InvalidArgumentException` (out of range) |
| P20B-T13 | `amounts()` and `map()` on a clean database | One query each; ten keys `1..10`; a missing row reads `0.00` |
| P20B-T14 | A refused save | The settings and the ladder rows are unchanged; the posted values and errors survive the redirect |
| P20B-T15 | Register three members under one sponsor after configuring | No `harvest_queue` row; no award; the wallet is unchanged (no hook yet) |
| P20B-T16 | Open the card with `team_harvest_enabled = 1` and an empty baseline | The strip says *seeded? no* and names the seed job; saving still works |
| P20B-T17 | Change level 3 from 4,000 to 5,000 with awards existing | The saved value changes; every existing award keeps its snapshotted amount (P20D fixture) |
| P20B-T18 | The card at 390 px | The ten inputs stack, the table scrolls horizontally, no page-level horizontal scroll |
| P20B-T19 | Settings page with JS disabled | Saves and validates; only the live totals are missing; no PHP error |
| P20B-T20 | The full suite | Green; `InstallTest` still 36 / 77 / 10 (this phase adds no schema) |

**Manual gate — `QA20B-01` … `QA20B-09`**

1. `QA20B-01` — `/admin/settings` → **Commissions**: the *Team Harvest bonus* card shows the ten
   reference amounts, the switch off, the gate `3`, the depth `10`, and the strip *not seeded*.
2. `QA20B-02` — change level 1 to ₱1,500 and level 10 to ₱4,000,000, save. Re-open: the values
   persist, the total reads `5,595,500.00`, and the audit desk shows `team_harvest.ladder.update`.
3. `QA20B-03` — set levels 8, 9 and 10 to ₱10,000,000 each (₱30,195,000.00 in total) and save: the
   sum error appears, the values come back as typed and the stored ladder is unchanged. *(The step
   was drafted as “set level 10 to ₱20,000,000”; Rule 2's per-level ceiling refuses that value first.
   See the deviation log.)*
4. `QA20B-04` — click **Load reference ladder**: the ten inputs return to the preset and the total to
   `5,095,000.00`; save.
5. `QA20B-05` — turn the switch on with all ten cleared: the error asks for an amount or the switch
   off.
6. `QA20B-06` — set the gate to `11`: refused. Set the depth to `3`: accepted, and levels 4–10 are
   marked *not paid* in the table.
7. `QA20B-07` — register three members under one sponsor on `/admin/members` (or by the public form):
   `SELECT COUNT(*) FROM harvest_queue` → 0, and the three registrations succeed.
8. `QA20B-08` — with JS disabled in the browser, save the card: it saves; only the live totals are
   missing.
9. `QA20B-09` — open the card at 390 px and at 1440 px: readable, no horizontal page scroll.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P20B-T1 … P20B-T20 pass
[ ] The card renders on desktop and mobile widths
[ ] The generated ladder table equals Appendix A, line for line
[ ] Registering three members writes no queue row and no award (P20B-T15)
[ ] Tag p20b-configuration; phase log; Appendix H row
```

**Do not start 20C until every box is ticked.**

**Rollback**

Turn the switch off and revert the code. The ladder rows and the four settings may stay: they are
inert and a later attempt can reuse them. No money exists to reverse.

**Hand-off to 20C**

- `TeamHarvestLevel::amounts()` and `threshold()` are the only way any later phase may read a level
  amount or a level threshold.
- `qualifyDirects()`, `maxLevels()` and `enabled()` are the only way to read the three knobs.
- The ladder is validated, audited and rendered; 20C may assume a configured ladder and must work
  when the ladder is all zeros (it then simply pays nothing).

**Risks**

| Risk | Mitigation |
|---|---|
| An operator types a ten-fold amount and commits the company | The per-level ceiling, the ₱25M total ceiling, the full-ladder total in the same sentence, and the audit row (Rules 2, 3, 9) |
| The generated table drifts from the model | The view calls `TeamHarvestLevel` for every cell; P20B-T11 compares it with Appendix A |
| A refused save half-writes the settings | P20B-T14 asserts both stores are unchanged; one transaction |
| An operator enables the stream and expects money | The strip names the seed job; nothing pays until 20C and 20D exist (P20B-T15) |

---

## 7. Phase 20C — The Harvest queue

**Goal.** Qualification becomes a stored fact: a member who reaches the gate gets one permanent
position, every queued ancestor's teammate counter moves by one, the historical tree is reconstructed
once by a deterministic seed job, and a rebuild can prove every counter. **No money moves.**

**Builds on.** 20A (the tables, the settings), 20B (the gate and the ladder are configured).

**Ships alone because.** The queue is not money: `team_harvest_awards` stays empty and the wallet is
untouched. But the queue is the feature's **irreversible** part — a position can never be renumbered —
so it must be provable before any award depends on it. If 20D were cancelled, the queue would still
be a correct, auditable record of qualification order (and the General Harvest plan's input, D-16).

**Effort.** 3.0 ideal days. **Suite.** ≥ 1,232 cases.

**Reference.** §12.2 (qualification), §12.3 (the queue), §12.4 (teammates), §12.5 (the matrix),
§14.3–14.4 (the algorithms), §14.10 (the seed).

**Deliverables**

**Database.** None. 20A shipped every column this phase writes.

**Code**

- `system/app/Models/HarvestQueue.php` (new) — the queue and the matrix:
  `positionOf()`, `isQueued()`, `directs()`, `qualifySponsor()`, `insert()`, `ancestorsOf()`,
  `incrementAncestors()`, `teammatesOf()`, `recount()`, `teamRoster()`, `matrix()`, `map()`,
  `stats()`, `seed()`, `rebuild()`, `verify()`, `seeded()`, `baseline()`. Every write runs inside the
  caller's transaction or opens one; the class never echoes and never reads a request.
- `system/app/Services/RegistrationService.php` — one added call, inside the existing registration
  transaction and **after** the user row exists:
  `(new HarvestQueue($this->pdo))->qualifySponsor((int) $sponsor['id']);`
  This is the only change to a registration path in the whole plan, and it is the phase's riskiest
  line (§17 risk 1).
- `system/bin/harvest_queue_seed.php` (new) — the one-time go-live job: flags `--dry-run`, `--by-id`,
  `--force`, `--json`, `--quiet`, `--help`; a `GET_LOCK` guard against a concurrent run; one
  transaction; the reconstructed positions, the `baseline_n` of every member and the baseline marker.
- `system/bin/harvest_queue_seed.cmd` (new) — the Windows launcher, like the other `.cmd` files.
- `system/app/Models/AuditLog.php` — no change: the job writes the shared `harvest.seed` action
  through the existing helper.

**Docs.** `docs/qa/phase-20-team-harvest.md` gains section C (`QA20C-01` … `QA20C-08`).
`docs/runbook.md` gains the **go-live order** (deploy → seed dry run → seed → enable) and the
rebuild/repair drill. `system/README.md` gains the queue and the job.

**Not yet.** No award, no wallet movement, no `TeamHarvestService`, no member page, no admin page, no
report card. `team_harvest_awards` is still empty after this phase.

**Rules**

1. **Append-only, one row per member.** `harvest_queue.id` **is** the position; it is assigned by
   auto-increment, never computed, never renumbered, never reused. A deleted member's position is a
   permanent gap and nothing may close it (D-03).
2. **The hook is inert until the queue is seeded.** `qualifySponsor()` returns `null` while
   `setting('team_harvest_ledger_since')` is empty (D-04). This is what makes the go-live order safe:
   deploy first, seed second, live third.
3. **The hook runs inside the registration transaction.** If it throws, the registration rolls back —
   no user row, no queue row. A registration that cannot record its qualification does not happen
   (P20C-T23).
4. **Only `role = 'member'` accounts count.** A staff account's sponsorship does not help anybody
   qualify, and a staff account is never queued. The company account is a staff account.
5. **Status does not matter.** A `pending`, `inactive` or `suspended` direct counts toward the gate,
   and a `pending`/`suspended` member can qualify exactly like an active one (D-02). Status is not
   read anywhere in this phase.
6. **Cycles and self-sponsorship terminate.** `ancestorsOf()` carries a visited set, exactly like
   [`CommissionService::sponsorChain()`](../system/app/Services/CommissionService.php#L309-L350); a
   corrupt chain stops the walk, is reported by `verify()` and never double-counts.
7. **Every queued ancestor moves by one, at any depth.** The increment loop is the reference's
   `counts()` walk with no level cap (reference §5.1). A non-queued ancestor is skipped, and a member
   who qualified **after** the newcomer is never touched (their teammates are the ones below them).
8. **The seed is deterministic and idempotent.** Positions come from the third direct's
   `(created_at, id)` — or from the qualifying member's `id` with `--by-id` — and a second run writes
   nothing (P20C-T13). The seed refuses a non-empty queue unless `--force`, which appends only the
   missing members after the existing ones and reports the anomaly.
9. **`baseline_n` is written once.** The seed sets it to each member's `teammates` at that instant;
   a rebuild never rewrites it (P20C-T21). `team_harvest_ledger_since` is set to the seed's timestamp
   in the same transaction.
10. **The counters are derived from the tree and are verifiable.** `recount($userId)` walks the
    member's subtree and counts queued descendants with a greater position; `rebuild()` compares every
    counter with a recount, reports the drift, and repairs it only with `--apply`. A counter the
    recount disagrees with is a defect, not a variant (P20C-T19/T20). `--rebuild` also **appends** a
    member who holds the gate but has no row (`source = 'repair'`) — after the existing positions,
    never before — and never removes or renumbers one.
11. **No money.** The phase writes no `team_harvest_awards` row, no `ewallet_ledger` row and no
    `wallet` balance (P20C-T22 asserts the wallet is untouched after the whole fixture).
12. **`qualified_at` is the qualification instant, not the seed instant.** For a live qualification it
    is the new member's `created_at`; for a reconstructed one it is the third direct's `created_at`.
    The two agree on a live path by construction and may differ on a seeded one by seconds.

**Test plan**

Fixtures: fixture A, fixture B and the spine tree of §16.2, plus the cycle, staff and unseeded
fixtures where a case names them. Every position assertion is exact.

| ID | Test | Expected |
|---|---|---|
| P20C-T1 | Register three directs under one member (seeded) | One queue row; position 1; `directs_at_qualification = 3`; `teammates = 0`; `source = 'registration'` |
| P20C-T2 | Register a fourth direct | No second row; position unchanged; `directs_at_qualification` still 3 |
| P20C-T3 | Two members qualify in order | Positions 1 and 2; the second's `qualified_at` is its third direct's `created_at` |
| P20C-T4 | A downline member qualifies before its sponsor | The downline member is position 1 and the sponsor position 2 — position is qualification order, not tree order |
| P20C-T5 | A sponsor with two member directs and one **staff** direct | Not queued: the staff account does not count |
| P20C-T6 | A member whose sponsor is the company account | Never queued for the company; the company never appears in any position |
| P20C-T7 | A suspended and a pending direct | Both count; the sponsor qualifies at the third |
| P20C-T8 | `team_harvest_qualify_directs = 2` | The second direct qualifies the sponsor; a member already queued keeps their position |
| P20C-T9 | The hook before the seed (`ledger_since = ''`) | No row, no error; after the seed, the next third direct writes a row |
| P20C-T10 | Seed fixture A (40 qualified, 121 accounts), `--dry-run` | Nothing written; the summary lists 40 members, positions 1–40 and the first 20 positions |
| P20C-T11 | Seed for real | 40 rows; positions 1–40 in qualification order; `ledger_since` set; `baseline_n = teammates` |
| P20C-T12 | Seed with `--by-id` | Same members, order by id; the diff against T11 is reported by the dry run |
| P20C-T13 | Seed twice | Second run writes nothing (`seeded=already`), `ledger_since` and `baseline_n` unchanged |
| P20C-T14 | Seed on a non-empty queue | Refused with a message naming the count; `--force` appends only the missing members |
| P20C-T15 | Seed on a database with no member holding the gate | 0 rows, `ledger_since` still set (the queue is now live and empty) |
| P20C-T16 | A qualification at the bottom of the spine tree (12 deep) | All **eleven** queued ancestors increment; a non-queued ancestor does not; an implementation capped at ten levels fails this case |
| P20C-T17 | A qualification under a sibling subtree | The other subtree's counters do not move |
| P20C-T18 | `teammatesOf()` for a member qualified after the newcomer | Unchanged |
| P20C-T19 | `recount()` vs `teammates` for all 40 members | Equal for every member |
| P20C-T20 | `rebuild()` after a manual counter edit | The drift is reported (`before`/`recount`), nothing written without `--apply`; with `--apply` the counter is repaired and the drift is gone |
| P20C-T21 | `rebuild()` on a seeded queue | `baseline_n` is unchanged for every member (it is history, not a counter) |
| P20C-T22 | The whole fixture A | `team_harvest_awards` 0 rows; the wallet balance of every member unchanged |
| P20C-T23 | The registration transaction | Begin a transaction, call `register()`, assert the user row and the queue row exist, roll back, assert neither exists |
| P20C-T24 | A sponsor cycle (A→B→A) and a self-sponsor | `ancestorsOf()` terminates; no duplicate increment; `verify()` reports the corrupt row |
| P20C-T25 | `matrix()` for a member with `n = 12` | 12 rows; slots 0–11; levels `1,1,1,2,2,2,2,2,2,2,2,2`; `placed_under` = the member for slots 0–2 and teammates 0/1/2 for the three level-2 triples |
| P20C-T26 | `stats()` on fixture B (121 qualified, 364 accounts) | 121 queued, 121 positions, the deepest teammate count 120, 0 awards |

**Manual gate — `QA20C-01` … `QA20C-08`**

1. `QA20C-01` — on a scratch copy, deploy the phase (no seed yet) and register three members under
   one sponsor: `SELECT COUNT(*) FROM harvest_queue` → **0**, and all three registrations succeed.
2. `QA20C-02` — `php bin/harvest_queue_seed.php --dry-run`: the summary lists the members that would
   be queued, positions 1…N and the first 20 lines; no row count changes.
3. `QA20C-03` — `php bin/harvest_queue_seed.php`: the output names the position count and the baseline
   instant; `SELECT id, user_id, teammates, baseline_n FROM harvest_queue ORDER BY id LIMIT 20` shows
   a contiguous 1…20 and `baseline_n = teammates`.
4. `QA20C-04` — run it again: "already seeded", 0 rows written, `ledger_since` unchanged.
5. `QA20C-05` — register three members under a member who had none: the sponsor appears at the **end**
   of the queue with the next position, and every queued ancestor's `teammates` moved by one.
6. `QA20C-06` — `php bin/harvest_queue_seed.php --rebuild --dry-run`: every counter agrees with the
   recount (0 drift). Edit one `teammates` value by hand (+5), re-run: the drift is reported with the
   member named; `--apply` repairs it.
7. `QA20C-07` — `SELECT COUNT(*) FROM team_harvest_awards` → 0 and `SELECT SUM(amount) FROM
   ewallet_ledger` unchanged after all of the above.
8. `QA20C-08` — run the business reset: `harvest_queue` is empty, `team_harvest_ledger_since` is `''`,
   the ladder survives; the next seed starts at position 1 again.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P20C-T1 … P20C-T26 pass
[ ] The seed is deterministic (two dry runs produce the same positions) and idempotent (two real runs write once)
[ ] The 40-qualified fixture's counters equal a from-scratch recount, member by member
[ ] No award row and no wallet movement exists anywhere in the phase's tests (P20C-T22)
[ ] Tag p20c-queue; phase log; Appendix H row
```

**Do not start 20D until every box is ticked.**

**Rollback**

Turn the money switch off (it is already off) and revert the code. The queue rows may stay: they are
a correct record and a later attempt can reuse them. If the decision is to abandon the stream
entirely, truncate `harvest_queue`, empty `team_harvest_ledger_since` and drop the code — no money
exists to reverse.

**Hand-off to 20D**

- `HarvestQueue::positionOf()`, `teammatesOf()`, `ancestorsOf()` and `baseline()` are the only way the
  engine may learn a member's standing; the engine never recounts a counter.
- Every queued member has a fixed position, a monotonically growing `teammates` and an immutable
  `baseline_n`; the seed has run and `team_harvest_ledger_since` is a valid datetime.
- The rebuild and the recount exist, so a counter defect is detectable and repairable.
- **Nothing pays.** 20D may assume the queue is correct, and must work with an empty queue.

**Risks**

| Risk | Mitigation |
|---|---|
| The hook breaks registration — the application's most critical path | One line, inside an existing transaction, covered by the phase's first tests and by a smoke check that registers three members; the structural work is inert until the seed, so a deploy cannot pay and cannot queue |
| A historical position is disputed | The reconstruction rule is deterministic, printed by the dry run, and `qualified_at` is the third direct's timestamp; §18 Q7 records the policy |
| A counter drifts and under- or over-pays | `recount()` and `rebuild()` exist from this phase; the reconciler (20D) reports drift and refuses a clean verdict |
| The seed runs twice or concurrently | The lock, the "already seeded" path, the `--force` guard and P20C-T13 |
| Positions are renumbered by a "helpful" repair | Rule 1 and §2.5; the rebuild's contract is append-and-recount, never renumber |
| The seed's dry run is skipped and the baseline surprises someone | The job prints the baseline before writing; the settings strip (20B) names the state; the runbook's go-live order starts with the dry run |

---

## 8. Phase 20D — Money engine

**Goal.** A completed level pays its configured amount, once, into the member's withdrawable e-wallet,
with an immutable ledger row — evaluated on every qualification, self-healing on any later run, and
never paying a level that was already complete at the baseline. **This is the only phase that moves
money.**

**Builds on.** 20C (the queue, the counters, the baseline).

**Ships alone because.** Money is complete and correct at `p20d-money`: every award is visible in the
existing wallet statements with the `team_harvest` ref type this phase credits, and
`bin/wallet_reconcile.php` proves the ledger balances. No page is *required* for the phase to be
correct — the admin and member pages of 20E/20F only **describe** what this phase already does.

**Effort.** 3.5 ideal days — the largest phase by design. **Suite.** ≥ 1,268 cases.

**Reference.** §12.6–12.10, §13.8 (invariants), §14 in full.

**Deliverables**

**Database.** None. (Awards use the columns shipped in 20A.)

**Code**

- `system/app/Models/TeamHarvestAward.php` (new) — `create()`, `has()`, `forMember()`, `levelMap()`,
  `paidLevels()`, `select()`, `normaliseFilters()`, `totals()`, `byLevel()`, `byMonth()`,
  `countByStatus()`, `recent()`, `exportRows()`. No `type` column: the level is the whole story.
- `system/app/Services/TeamHarvestService.php` (new) — `enabled()`, `seeded()`, `baseline()`,
  `onQualified()`, `evaluate()`, `evaluateAll()`, `statusFor()`, `openLiability()`, `reconcile()`.
- `system/app/Services/TeamHarvestException.php` (new) — `unseeded()`, `disabled()`, `state()`, the
  same shape as `RoyaltyException`.
- `system/app/Services/RegistrationService.php` — the 20C line becomes
  `(new TeamHarvestService($this->pdo))->onQualified((int) $sponsor['id']);`
  which qualifies **and** evaluates, in the same transaction.
- `system/app/Services/Live.php` — `touchTeamHarvestAdmin()` and `touchTeamHarvestMember(int $userId)`:
  the admin surfaces (`admin.commissions`, `admin.dashboard`, `admin.reports`, and the awarded member's
  `admin.members:{id}`) and the member surfaces (`member.earnings:{id}`, `member.dashboard:{id}`,
  `member.wallet:{id}`, `member.genealogy`), bumped **after** the commit, never inside the wallet
  transaction.
- `system/app/Services/DatabaseResetService.php` — the integrity map gains
  `'team_harvest' => ['label' => 'Team Harvest reconciliation', 'service' => 'TeamHarvestService']`
  (deferred from 20A on purpose: the class now exists).
- `system/bin/team_harvest_catchup.php` (new) — the on-demand evaluation and doctor: flags
  `--dry-run`, `--member=ID`, `--doctor`, `--json`, `--limit`, `--quiet`, `--help`; the shared lock
  and log conventions; exit 0 (clean), 1 (refused or drift), 2 (unreachable database).
- `system/bin/team_harvest_catchup.cmd` (new) — the Windows launcher.
- `system/bin/wallet_reconcile.php` — a **Team Harvest** section, included in the exit code.

**Docs.** `docs/qa/phase-20-team-harvest.md` gains section D (`QA20D-01` … `QA20D-10`).
`docs/runbook.md` gains the award/reconcile drill. `system/README.md` gains the engine, the ref type
and the catch-up job.

**Not yet.** No ledger page, no member page, no report card, no settings change (the card is 20B's),
no `APP_PHASE` change.

**Rules**

1. **One hook, one transaction.** `onQualified()` runs inside the registration transaction. When the
   catch-up or the doctor calls `evaluate()` directly, the service opens its own transaction per
   evaluation batch and commits it whole (§14.7).
2. **Award and wallet credit commit together.** The engine never writes an award with no wallet row,
   or a wallet row with no award. A failure propagates; the caller rolls back (§14.7).
3. **Idempotent at three layers** (§14.6): the `UNIQUE (user_id, level)` anchor, the pre-check read
   and the wallet's own `(user_id, ref_type, ref_id, type)` anchor. A second evaluation moves nothing.
4. **State-based, not event-based** (D-19). The engine compares `teammates` with the award rows; a
   missed event self-heals on the next qualification or the catch-up run.
5. **A level pays once, ever.** No row is ever updated; no row is ever deleted. `status` is
   `credited` or `baseline` and both consume the `(member, level)` key.
6. **The baseline is honoured exactly.** A level with `threshold(L) ≤ baseline_n` is written
   `status = 'baseline'`, `amount` = the configured amount, `ledger_id = NULL`, and is **never**
   credited — not at enablement, not by a later evaluation, not by the catch-up (D-07).
7. **Only levels up to `max_levels` are written.** A complete level above the configured depth is
   reported by `statusFor()` as *beyond the configured depth* and never paid.
8. **The amount is snapshotted.** `amount`, `threshold_n` and `n_at_award` are written on the row from
   the ladder and the counter at that instant; a later ladder edit never rewrites history (P20D-T24).
9. **No money while the switch is off.** `enabled() = false` → no row, no wallet, and the evaluation
   returns `off`; the queue still grows (D-09).
10. **Unseeded is a hard refusal.** `seeded() = false` → `TeamHarvestException::unseeded()`, exit 1
    from the job, and the settings strip already names the seed job (20B).
11. **Recipients are sorted before any wallet lock.** The award loop orders by `(user_id, level)`, so a
    batch that shares no member cannot deadlock and a batch that shares one cannot interleave
    (§14.8).
12. **The company account and staff are never recipients**, because they are never queued (20C
    Rule 4); the engine asserts it anyway and throws rather than pay a `role <> 'member'` row.
13. **No retroactive money** (D-07). The catch-up evaluates from the counter, which is always
    post-baseline; it never looks at orders, months or history.
14. **Reconcile is part of the phase**, not a later add-on: `reconcile()` proves the awards, the
    wallet rows and the counters agree, and `wallet_reconcile.php` fails the build when they do not
    (§14.13).

**Test plan**

Fixtures: fixture A (40 qualified, 121 accounts), fixture B (121 qualified, 364 accounts) and the
spine tree of §16.2, with the reference ladder, the stream on and the queue seeded. Expected amounts
are exact and verified against the reference's arithmetic (§16.3).

**Awards**

| ID | Test | Expected |
|---|---|---|
| P20D-T1 | Fixture A: a depth-2 member whose three children qualify | Level 1 only: one award, ₱1,000.00, `threshold_n = 3`, `n_at_award = 3` |
| P20D-T2 | Fixture A complete (40 qualified, 121 accounts; root `n = 39`; the awards land as the fixture grows, at `n = 3`, 12 and 39) | Root earns levels 1–3: ₱1,000 + ₱2,000 + ₱4,000 = **₱7,000.00**, three rows, one wallet credit each |
| P20D-T3 | The same fixture, the three depth-1 members | Each earns levels 1–2 (`n = 12`): **₱3,000.00**, two rows each |
| P20D-T4 | The same fixture, a depth-2 member | Level 1 only (`n = 3`): **₱1,000.00** |
| P20D-T5 | The same fixture, a depth-3 qualified member | `n = 0`: no row, no money, no error |
| P20D-T6 | Fixture B (121 qualified, 364 accounts), root | Levels 1–4: ₱1,000 + ₱2,000 + ₱4,000 + ₱6,000 = **₱13,000.00** |
| P20D-T7 | Fixture A's whole network | Network total **₱25,000.00** across the 13 paying members (1 × 7,000 + 3 × 3,000 + 9 × 1,000); **18** award rows; 27 qualified members earn nothing |
| P20D-T8 | `earnedFor(88,572)` and `earnedFor(88,571)` on the pure function | **₱5,095,000.00** and **₱1,595,000.00** |
| P20D-T9 | A wallet statement for an awarded member | One *Team Harvest bonus* credit per award, `ref_type = 'team_harvest'`, `ref_id` = the award id, withdrawable bucket up by the amount |
| P20D-T10 | An award row | `status = 'credited'`, `ledger_id` set, `credited_at` set, `created_at` set |

**Idempotency and the baseline**

| ID | Test | Expected |
|---|---|---|
| P20D-T11 | `evaluate()` twice on the same member | Second call writes nothing (`paid = 0`), rows and balances unchanged |
| P20D-T12 | `onQualified()` called twice for the same qualification | One queue row (20C), one award set; the second returns `replayed` |
| P20D-T13 | Duplicate `(user_id, level)` insert | `PDOException` 23000 — the unique anchor holds |
| P20D-T14 | Seed with the root at `n = 12`, then qualify to `n = 39` | At first evaluation: levels 1–2 written `baseline` (amounts ₱1,000/₱2,000, `ledger_id NULL`, no wallet); at `n = 39` level 3 credits **₱4,000.00** |
| P20D-T15 | The baseline member's wallet | Exactly ₱4,000.00, never ₱7,000.00 |
| P20D-T16 | `baseline_n = 39` and the engine runs | Levels 1–3 are baseline; no credit; `statusFor()` says *already complete at go-live* |
| P20D-T17 | A member qualified **after** the seed (`baseline_n = 0`) | All completed levels credit normally |
| P20D-T18 | `openLiability()` on a seeded, unfunded network | Equals the sum of every payable-but-unwritten level, to the peso; matches the catch-up dry run |

**Switches, depth and eligibility**

| ID | Test | Expected |
|---|---|---|
| P20D-T19 | `team_harvest_enabled = 0`, three direct registrations | Queue rows yes; awards 0; wallet unchanged; the evaluation returns `off` |
| P20D-T20 | Turn it on, then run the catch-up | The backlog above the baseline pays exactly once; a second run pays nothing |
| P20D-T21 | Unseeded (`ledger_since = ''`) | `TeamHarvestException::unseeded()`; the job exits 1; nothing written |
| P20D-T22 | `max_levels = 2` on fixture A's root | Levels 1–2 credit **₱3,000.00**; level 3 is complete but **not** written; `statusFor()` marks it beyond the depth |
| P20D-T23 | `max_levels = 3`, then raise it to 4 after level 3 paid | Level 4 pays when it completes; levels 1–3 do not re-pay |
| P20D-T24 | Edit level 3's amount from 4,000 to 5,000 after an award exists | The existing award keeps ₱4,000.00; a new member's level 3 pays ₱5,000.00 |
| P20D-T25 | A suspended member completes a level (D-13) | Award credited into the wallet; the payout request path still refuses them (existing rule) |
| P20D-T26 | A staff account as the earner | Impossible to queue (20C); a direct `evaluate()` call for it writes nothing and reports `not a member` |

**Integrity and reconciliation**

| ID | Test | Expected |
|---|---|---|
| P20D-T27 | `reconcile()` after the fixture A run | `ok = true`; awards total = wallet total = ₱25,000.00; zero drift |
| P20D-T28 | Delete one wallet ledger row, then reconcile | Mismatch reported with the award id; `ok = false`; `wallet_reconcile.php` exits non-zero |
| P20D-T29 | Hand-edit one `teammates` counter, then reconcile | Counter drift reported by the queue's recount and included in the reconcile verdict |
| P20D-T30 | `wallet_reconcile.php` on a clean build | Exit 0 and a Team Harvest section reporting the total, the award count and no mismatch |
| P20D-T31 | The reset's integrity pack | Runs `TeamHarvestService::reconcile()` and passes after a business reset (0 awards, 0 queue rows) |
| P20D-T32 | `statusFor()` for a member with 0, 3, 12 and 39 teammates | Next level, teammates needed, remaining, progress percent, paid total and baseline state, all exact |
| P20D-T33 | `TeamHarvestAward::select()` with filters and a page | Filters intersect; an unknown value falls back to "no filter"; no N+1 |
| P20D-T34 | Three concurrent evaluations of the same member (sequential in one transaction each) | One set of rows; the losers write nothing and throw nothing |

**Manual gate — `QA20D-01` … `QA20D-10`**

No new page appears in this phase. Verify through the wallet statements, the tables and SQL.

1. `QA20D-01` — on a seeded scratch copy with the reference ladder and the switch **on**, register the
   members of fixture A in BFS order. Check `SELECT * FROM team_harvest_awards WHERE
   user_id = <root>` → level 1, ₱1,000.00, `status = credited`.
2. `QA20D-02` — as that member, open `/wallet`: one **Team Harvest bonus** credit of ₱1,000.00, and
   the statement filter's new option matches it.
3. `QA20D-03` — `/admin/wallets` → the same credit with `ref_type = team_harvest` and the award id as
   `ref_id`.
4. `QA20D-04` — `php bin/wallet_reconcile.php` → exit 0 and a **Team Harvest** section reporting the
   total and no mismatch.
5. `QA20D-05` — build fixture A (40 qualified, 121 accounts) and qualify the root: three awards
   (₱1,000, ₱2,000, ₱4,000) and **₱7,000.00** in the wallet.
6. `QA20D-06` — re-run the registration of an already-queued member's fourth direct: no new award, no
   new credit, the totals unchanged.
7. `QA20D-07` — set `team_harvest_enabled = 0`, register three members under a new sponsor: the queue
   row appears, the awards do not. Turn it on and run `php bin/team_harvest_catchup.php --dry-run`
   (the amount listed) then for real: exactly one new award.
8. `QA20D-08` — seed a network where the root already has 12 qualified teammates, then qualify the
   13th: `SELECT status, amount FROM team_harvest_awards WHERE user_id = <root>` shows levels 1–2
   `baseline` and level 3 `credited` ₱4,000.00; the wallet holds ₱4,000.00 only.
9. `QA20D-09` — `php bin/team_harvest_catchup.php --doctor` → clock, tables, settings, seed state,
   baseline, queue count, awards total, drift, exit 0.
10. `QA20D-10` — hand-delete one `ewallet_ledger` row and re-run the reconciler: it reports the award
    and exits non-zero. Restore from the backup.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P20D-T1 … P20D-T34 pass
[ ] Every amount in §16.3 is pinned by a case, to the centavo
[ ] No award row exists with a ledger_id for a baseline row (asserted in the suite)
[ ] A level never pays twice: the unique anchor, the replay test and the catch-up test all green
[ ] `wallet_reconcile.php` exits 0 with a Team Harvest section, and non-zero when a ledger row is removed
[ ] `php bin/team_harvest_catchup.php --doctor` exits 0 on a clean build
[ ] Tag p20d-money; phase log; Appendix H row
```

**Do not start 20E or 20F until every box is ticked.**

**Rollback**

1. Set `team_harvest_enabled = 0` — from that second no new award is written, and the queue keeps
   recording positions for when the stream is fixed.
2. Only then revert the code. Any awards already credited stay in the wallets and in
   `team_harvest_awards`: they are real money, and a code rollback does not and must not delete them.
   The ledger remains reconcilable because `wallet_reconcile.php` reads the tables, not the code path.
3. A decision to *unpay* is a business decision with a forward-only path (§18 Q3), not a rollback.

**Hand-off to 20E/20F**

- Awards exist with a stable row shape (`TeamHarvestAward::forMember()`, `select()`, `totals()`,
  `byLevel()`, `byMonth()`, `exportRows()`) that 20E and 20F only render.
- `statusFor()` returns everything a member card or an admin panel needs: position, teammates, paid
  levels, next level, remaining, progress, baseline state, paid total.
- `openLiability()` exists for the settings strip and the admin queue card.
- `reconcile()` and the doctor exist, so every number a page shows has a proof behind it.

**Risks**

| Risk | Mitigation |
|---|---|
| A double credit from a raced registration | Three idempotency layers (§14.6); the unique anchor; P20D-T11/T13 |
| A deadlock between two registrations sharing ancestors | Ascending recipient order and one transaction per evaluation (§14.8) |
| The baseline is bypassed and a pre-go-live level pays | The rule is expressed twice (`threshold > baseline_n` and the `baseline` row) and pinned by P20D-T14–T16 |
| The hook's exception is swallowed "to keep registration working" | Rule 2: the exception propagates and the caller rolls back; the phase's suite includes the rollback case (P20C-T23 is extended here) |
| A ladder edit rewrites a past award | Amounts are snapshotted (Rule 8); P20D-T24 |
| A counter defect pays the wrong amount | The reconcile reports counter drift and fails the build (P20D-T29) |
| The catch-up pays a stale backlog twice | State-based evaluation; a second run writes nothing (P20D-T20) |

---

## 9. Phase 20E — Admin visibility

**Goal.** Finance can see, filter and export every Team Harvest award, open the member who earned it,
and read the queue itself — who is qualified, in what order, how far each member is, and what the
network still owes. **Read-only: this phase adds no money path.**

**Builds on.** 20D (the awards and the status API it renders).

**Ships alone because.** It is pure presentation over rows 20D already writes and
`TeamHarvestAward`/`HarvestQueue` already shape. If 20F never shipped, the operator still has a
complete audit tool — which is exactly what a controlled money feature needs first.

**Effort.** 1.5 ideal days. **Suite.** ≥ 1,292 cases.

**Reference.** §14.2 (the API these pages call), §13.8 (the invariants the pages surface),
Appendix C.1–C.2 and C.5 (copy and CSV columns).

**Deliverables**

**Code**

- `system/app/Controllers/AdminCommissionController.php` — `ledger=team_harvest` selects the fourth
  tab; filters pass through `TeamHarvestAward::normaliseFilters()`; `export` writes the same columns
  for the tab and audits `team_harvest.export`.
- `system/app/Controllers/AdminMemberController.php` — the queue summary for the list page, the
  `?harvest=yes|no` filter, and the member's harvest panel data for the show page.
- `system/app/Models/Member.php` — `normaliseFilters()` gains `harvest` (`yes|no`, anything else means
  "no filter"), `whereFor()` gains the matching `EXISTS (SELECT 1 FROM harvest_queue …)`, and
  `adminList()` selects the member's position in the same query (no N+1).
- `system/app/Models/Report.php` — `teamHarvestSummary()`, `teamHarvestByLevel()`,
  `teamHarvestByMonth()`, `teamHarvestQueue()`.
- `system/app/Services/ReportService.php` — the four cards, `type=team_harvest` in the range export,
  and the dashboard **Team Harvest (30 d)** KPI.
- Views (new): `admin/commissions/_team_harvest_header.php`, `_team_harvest_stats.php`,
  `_team_harvest_levels.php`, `_team_harvest_rows.php`, `_team_harvest_pagination.php`;
  `admin/members/_harvest.php`, `admin/members/_team_harvest.php`;
  `admin/reports/_team_harvest.php`.
- Views (modified): `admin/commissions/index.php` (the fourth pill), `admin/members/index.php`
  (the queue card and the filter), `admin/members/_rows.php` (the `Harvest` column),
  `admin/members/show.php` (the panel), `admin/reports/index.php` (the cards).

**Docs.** `docs/qa/phase-20-team-harvest.md` gains section E (`QA20E-01` … `QA20E-08`).
`docs/admin-guide.md` gains the ledger, the queue card and the member panel. `docs/deploy.md` notes the
new audit actions.

**Not yet.** No member-facing surface at all. A member sees only their wallet credit (20D).

**Rules**

1. **Read-only.** No controller, model or view in this phase writes an award, a wallet row or a queue
   row.
2. **The three existing tabs are untouched.** Package, repeat-purchase and Royalty markup, filters,
   counts and exports stay byte-identical; `P20E-T1` is a regression test, not a formality.
3. **No new route.** `?ledger=team_harvest`, `?harvest=yes|no` only (D-14). `routes.php` and
   `routes_inventory.php` are untouched; `ParityTest` stays green.
4. **Baseline is not credited money.** Every *Credited* figure counts `status = 'credited'` only;
   baseline rows appear in their own tile and their own column, never inside a paid total (Rule 4 of
   §12.7).
5. **Positions are displayed, never derived.** The `Harvest` column reads `harvest_queue.id`; the page
   never recomputes a position from a count.
6. **Every row links to its member and its wallet movement.** A finance reviewer can walk from a
   number to the member and to the ledger row in one click each.
7. **The queue card explains its empty states.** Three of them, each naming the next action: *not
   seeded* (run the seed job), *seeded but nobody qualified* (nobody holds the gate yet), *seeded and
   qualified* (the positions and the counters).
8. **The open liability is a number, not a warning.** The card shows the sum the engine would pay if
   every outstanding level were evaluated now (`openLiability()`), and labels it *not yet paid*.
9. **Filters fall back silently.** An unknown `level`, `status`, date or search means "no filter",
   exactly like [`Commission::normaliseFilters()`](../system/app/Models/Commission.php#L140-L172).
10. **Admin copy may use the plan's vocabulary** — queue, position, teammates, level block, matrix,
    baseline. The member-facing restrictions of 20F do not apply here, and the admin pages are where
    the exact terms belong.
11. **The level distribution is the ladder's ten rows**, always, including levels with no awards, so a
    reader sees the shape of the plan and not just the paid part.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P20E-T1 | `/admin/commissions` (package, repeat and Royalty tabs) | Byte-identical markup and counts to `p20d-money`; regression |
| P20E-T2 | `/admin/commissions?ledger=team_harvest` | Stats, filters, rows and empty state render; the `Level`, `Teammates` and `Status` columns exist |
| P20E-T3 | Stats tiles on fixture A | Credited ₱25,000.00, 18 awards, 13 recipients, baseline ₱0, open liability ₱0 |
| P20E-T4 | Baseline rows in the fixture | Counted in the *Baseline* tile (₱3,000.00 in the P20D-T14 fixture) and **not** in *Credited* |
| P20E-T5 | Filters: level 3, status baseline, from/to, q | Each filters correctly; two combined filters intersect; a bad value falls back to "no filter" |
| P20E-T6 | The level distribution card | Ten rows, levels 1–10; amounts from `team_harvest_levels`; counts from the awards; a level beyond `max_levels` is marked *not paid* |
| P20E-T7 | `/admin/members` | The `Harvest` column shows `#N` for queued members and `—` otherwise; the queue card shows queued count, next position and open liability |
| P20E-T8 | `/admin/members?harvest=yes` / `no` | Correct rows; the filter survives pagination and combines with the status filter |
| P20E-T9 | `/admin/members/{id}` for a queued member | Position, qualified at, teammates, paid levels, next level, remaining, baseline state, awards table |
| P20E-T10 | `/admin/members/{id}` for an unqualified member | "Not qualified: 2 of 3 direct members" and no award table |
| P20E-T11 | `/admin/members/{id}` for a baseline member | Levels 1–2 marked *Before go-live* with their amounts and no wallet link |
| P20E-T12 | CSV export of the ledger | Header row and one row per filtered award; columns match the screen plus the wallet reference; `team_harvest.export` audit row with the filters |
| P20E-T13 | `/admin/reports` | The four cards render with the fixture numbers; the dashboard KPI reads the last 30 days |
| P20E-T14 | Report export `type=team_harvest` | The range CSV includes the award rows and the queue summary block |
| P20E-T15 | Live feed | An award bumps `admin.commissions` and `admin.dashboard`; an open Team Harvest tab refreshes; the other three tabs are unaffected |
| P20E-T16 | A member opens `/admin/commissions?ledger=team_harvest` | 403, same guard as the other tabs |
| P20E-T17 | A staff account (non-admin) opens `/admin/members?harvest=yes` | Refused by the existing guard |
| P20E-T18 | Query budget | The ledger page is ≤ 8 queries for 25 rows; the members page ≤ 6 (no N+1 for positions) |
| P20E-T19 | The queue card at 390 px | Readable; the position table scrolls horizontally; no page-level scroll |
| P20E-T20 | Unseeded database | The card says *not seeded* and names the job; the ledger empty state says the stream has never paid |
| P20E-T21 | A seeded database with zero awards | The card shows positions and the open liability; the ledger shows "nothing has completed a level yet" |
| P20E-T22 | The unruled rows: a member with a position and no awards | Shown in the queue card and in the members list, absent from the ledger |
| P20E-T23 | Export with no rows | A file with the header only and one audit row |
| P20E-T24 | The full suite | Green; no new route; `ParityTest` and `RouterTest` untouched |

**Manual gate — `QA20E-01` … `QA20E-08`**

1. `QA20E-01` — with fixture A paid, open `/admin/commissions?ledger=team_harvest`: the header shows
   **₱25,000.00** credited, **18** awards, **13** recipients.
2. `QA20E-02` — filter level `3`: **one** row (the root's ₱4,000); filter status `baseline` on the
   P20D-T14 network: two rows, no wallet link.
3. `QA20E-03` — click a row's member link: `/admin/members/{id}` opens with the panel showing the same
   position, teammates and money; back on the ledger, click the wallet link → the movement.
4. `QA20E-04` — export the filtered CSV; the columns and values match the screen; the audit desk shows
   `team_harvest.export`.
5. `QA20E-05` — `/admin/members`: the `Harvest` column reads `#1`, `#2`…; the card shows the queued
   count, the next position and the open liability. `?harvest=yes` filters to them.
6. `QA20E-06` — `/admin/members/{id}` for a member who has not qualified: the "2 of 3 direct members"
   line, no award table.
7. `QA20E-07` — `/admin/reports`: the four cards show the same totals as the ledger; export the range
   CSV and find the Team Harvest block.
8. `QA20E-08` — switch to the Package, Repeat and Royalty tabs: they look and behave exactly as
   before.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P20E-T1 … P20E-T24 pass
[ ] The three existing tabs are byte-identical (diff or snapshot)
[ ] No new route; ParityTest and RouterTest green
[ ] Every number on the page is traceable: row → member, row → wallet movement, tile → model method
[ ] Tag p20e-admin; phase log; Appendix H row
```

**Rollback**

Revert the views and the controllers. The tab disappears; the data is untouched. Nothing in 20D reads
anything this phase writes.

**Hand-off to 20F**

- The admin ledger proves the numbers 20F will show members; any disagreement is a bug in 20F.
- `TeamHarvestAward`'s filter/select/total API and `HarvestQueue::matrix()` are proven by a page, so
  20F can reuse them without inventing a second implementation.

**Risks**

| Risk | Mitigation |
|---|---|
| The other tabs regress while adding a fourth pill | T1 is a snapshot/byte comparison |
| A baseline row leaks into a paid total | Rule 4 and P20E-T4 pin the two figures |
| The position column is computed instead of read | Rule 5 and P20E-T7 |
| The queue card is empty and unexplained | Rule 7 names the three states and the action for each |
| Reports disagree with the ledger | Both call the same model methods; T3/T13 compare them |

---

## 10. Phase 20F — Member visibility

**Goal.** A member can see their own position, their qualification progress, their 3×10 matrix, how
many qualified teammates they have, what level comes next and what it pays, and every level they have
been paid — with copy that states the gate and never promises money the engine will not pay.

**Builds on.** 20D (`statusFor()`, `matrix()`, the award queries).

**Ships alone because.** It is presentation. Every number it shows already exists and is already proven
by 20D's tests; if 20E never shipped, members still see their own money and admins still see the wallet
statements.

**Effort.** 1.5 ideal days. **Suite.** ≥ 1,314 cases.

**Reference.** §12.5 (the matrix the card renders), §12.6 (the ladder), Appendix C.3–C.4, C.6 and C.9
(the copy and the member CSV).

**Deliverables**

**Code**

- `system/app/Controllers/EarningsController.php` — `ledger=team_harvest` selects the fourth tab;
  `export` writes `team-harvest-statement_YYYY-MM-DD.csv`; both scoped to the session member.
- `system/app/Controllers/DashboardController.php` — the tile's status payload.
- `system/app/Controllers/GenealogyController.php` — the position badge on the downline rows.
- Views (new): `member/earnings/_team_header.php`, `_team_stats.php`, `_team_progress.php`,
  `_team_matrix.php`, `_team_rows.php`, `_team_pagination.php`; `member/dashboard/_team_harvest.php`.
- Views (modified): `member/earnings.php` (the fourth tab), `member/dashboard/_earnings.php` (the
  tile), `member/genealogy/_rows.php` (the badge).

**Docs.** `docs/qa/phase-20-team-harvest.md` gains section F (`QA20F-01` … `QA20F-09`). The copy rules
below are the acceptance criteria for the whole section.

**Not yet.** No release work: 20G owns the documentation, the phase badge, the acceptance pass and the
sign-off. Nothing in 20F may touch `APP_PHASE` or the QA pack's earlier sections.

**Rules — the copy rules are normative**

1. **Say "Team Harvest bonus" and "Harvest position".** Never "matrix scheme", "spillover", "passive
   income", "unlimited earning" or "guaranteed". The plan's own term for the private board is the
   **Team matrix**, and it is explained, not advertised.
2. **Always state the gate in the same sentence as the position.** "You qualified for position #12
   when you registered your third direct member." A member who does not remember why they are
   qualified should not have to ask.
3. **Always state the two exclusions**, on the matrix card and nowhere else: members who qualified
   **before** you never count, and direct members who are not themselves qualified never count.
4. **Never promise a date.** Say "when your teammates reach 12, level 2 completes and pays ₱2,000.00";
   never "you will be paid by…". The amount is fixed by the plan; the timing is not knowable.
5. **Show the whole 3×10 matrix**, filled and empty slots alike, and never hide a level the member has
   not reached. An empty level reads *0 of 9* with its amount, because the amount is the offer.
6. **Say that purchases are irrelevant**, once, in the card's footnote: "This bonus does not depend on
   any purchase — yours or your teammates'." It is the question every member will ask.
7. **The member's own money is the only money shown.** Usernames may appear in the matrix roster (the
   genealogy already shows them); no other member's amount, wallet or rank ever does.
8. **A baseline row is labelled "Before go-live"** and is excluded from every *Earned* figure; the
   card says once, in a footnote, that the plan started paying on a date and levels completed before
   it are recorded but not paid.
9. **Every number comes from the service.** `statusFor()` and `matrix()` supply the position,
   teammates, levels, amounts and progress; the view formats, never computes.
10. **The other three tabs stay live and unchanged.** Package, repeat-purchase and Royalty keep their
    URLs and bytes; the fourth tab is `?ledger=team_harvest`; one live surface
    (`member.earnings:{id}`) refreshes all four.
11. **Every query is scoped to the session member** (`WHERE user_id = ?`), including the CSV export,
    which is scoped in the **model** so a hand-edited URL cannot widen it.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P20F-T1 | `/earnings?ledger=team_harvest` for a queued member | Position, qualified at, teammates, progress card, matrix, rows and filters render |
| P20F-T2 | The progress card for `n = 1, 5, 20, 50` | Next level 1/2/3/4; needed 3/12/39/120; filled `1/3`, `2/9`, `8/27`, `11/81`; percent `33.3 / 22.2 / 29.6 / 13.6`; remaining `2 / 7 / 19 / 70` |
| P20F-T3 | The matrix card for `n = 12` | Level 1 shows 3 of 3 filled; level 2 shows 9 of 9 filled; levels 3–10 show `0 of 27 … 0 of 59,049`; the amounts are the configured ones |
| P20F-T4 | The matrix card for `n = 39` | Levels 1–3 complete and marked *Paid*; level 4 `0 of 81` with ₱6,000.00 |
| P20F-T5 | The roster under the matrix | Teammates in qualification order with their position and slot; a paginated member sees the first page only, with the total |
| P20F-T6 | A member who has not qualified | The tab explains the gate ("3 direct members"), shows the current count and no matrix; no awards table |
| P20F-T7 | The baseline member | Levels 1–2 marked *Before go-live*; *Earned* shows only the credited levels; the footnote names the start date |
| P20F-T8 | The member CSV | The documented columns, only the caller's rows, **0** audit rows, and `?user_id=` cannot widen it |
| P20F-T9 | `/earnings` (the other three tabs) | Unchanged — regression |
| P20F-T10 | `/dashboard` | The tile shows the paid total and the next level's progress; no action, no form |
| P20F-T11 | `/genealogy` | The badge shows the reader's own position and each queued downline member's position; unqualified members show `—` |
| P20F-T12 | Word search of every new member view | No "spillover", "passive", "guaranteed", "unlimited", "matrix scheme"; "sponsor chain" appears nowhere |
| P20F-T13 | The exclusions sentence | Present on the matrix card, verbatim, in one place (not repeated on the tile) |
| P20F-T14 | The purchases footnote | Present once, on the matrix card |
| P20F-T15 | A member with a wallet credit | The row links to the wallet statement's movement by `ref_id` |
| P20F-T16 | `/earnings?ledger=team_harvest&user_id={other}` | Ignored: only the session member's rows |
| P20F-T17 | Live refresh | An award bumps `member.earnings:{id}` and the open tab refreshes; the other three tabs are unaffected |
| P20F-T18 | Mobile width (390 px) | The matrix scrolls inside its card; the page does not scroll horizontally |
| P20F-T19 | A member with 500 teammates | The roster paginates; the matrix stays 10 levels; the page is ≤ 8 queries |
| P20F-T20 | Every amount on the card | Equals `team_harvest_levels.amount` for the configured levels; a level beyond `max_levels` reads *not paid* |
| P20F-T21 | The tab with the stream off | The card still shows position and progress, and says the bonus is not currently paying; no award rows |
| P20F-T22 | The full suite | Green; the other three tabs' tests unchanged |

**Manual gate — `QA20F-01` … `QA20F-09`**

1. `QA20F-01` — as the root of fixture A, open `/earnings?ledger=team_harvest`: position
   `#1`, 39 teammates, level 3 paid ₱4,000.00, level 4 `0 of 81` at ₱6,000.00.
2. `QA20F-02` — read the matrix card: the gate sentence, the two exclusions, the purchases footnote and
   the *Before go-live* label if any.
3. `QA20F-03` — as a member who has not qualified, open the tab: the gate, the current count, no
   matrix, no awards.
4. `QA20F-04` — filter the rows by level and status; the totals match the rows; export the CSV and
   compare it with the screen.
5. `QA20F-05` — `/dashboard`: the tile shows the paid total and the next-level progress.
6. `QA20F-06` — `/genealogy`: the reader's position and each downline member's position; unqualified
   members show `—`.
7. `QA20F-07` — as the same member, open the package, repeat and Royalty tabs: unchanged. Wait for an
   award (or trigger one with a registration) and watch the Team Harvest tab refresh.
8. `QA20F-08` — append `&user_id=<another id>` to the tab and to the export: the page and the file are
   unchanged.
9. `QA20F-09` — repeat the matrix and the tab at 390 px: no page-level horizontal scroll.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P20F-T1 … P20F-T22 pass
[ ] A word search of every new view for "spillover"/"passive"/"guaranteed" finds nothing
[ ] A word search of every new member view for "sponsor chain" finds nothing
[ ] The gate, the two exclusions and the purchases footnote are present and singular
[ ] The other three earnings tabs are unchanged (regression)
[ ] No new route; ParityTest green
[ ] Tag p20f-member; phase log; Appendix H row
```

**Rollback**

Revert the controllers and the views. The panels disappear; the money and the admin ledger are
untouched.

**Hand-off to 20G**

- Nothing. 20G depends on all six phases, and 20F's numbers must agree with 20E's ledger.

**Risks**

| Risk | Mitigation |
|---|---|
| Copy implies guaranteed or unlimited income | The five "never" rules, the word search in the exit gate and P20F-T12 |
| A member reads the matrix as a promise of money for a partial level | The `0 of N` labels and the "all or nothing" sentence in Rule 5's card copy |
| An N+1 in the matrix roster | `matrix()` is paginated and one query per page; P20F-T19 counts |
| A member reads another's money | Session-scoped queries and the model-scoped CSV; P20F-T8/T16 |
| The baseline footnote is missed and a member disputes a missing payment | The row label *Before go-live* plus the footnote; §18 Q1 records the business question |

---

## 11. Phase 20G — Release

**Goal.** Documentation that matches the code, a QA pack a stranger can execute, `APP_PHASE 20`, a
green acceptance run, and a signed gate record.

**Builds on.** All six phases.

**Ships alone because.** It ships last by definition; what it must *not* do is change behaviour. If a
documentation claim and the code disagree during this phase, the code wins and the plan is amended.

**Effort.** 1.5 ideal days. **Suite.** 1,318 cases (no new cases unless a documentation claim exposes a
missing test).

**Reference.** §2.4 (the universal gate), §17 (risks), Appendix H.

**Deliverables**

**Code**

- `system/app/config.php` — `APP_PHASE` **20** (D-15), and `HardeningTest` updated to assert it.
- `system/tests/smoke.ps1` — the new URL checks accumulated by 20B–20F (the settings card, the members
  queue and filter, the ledger tab, the member tab, the genealogy badge), plus the registration
  three-member check that proves the hook.

**Docs**

- `docs/qa/phase-20-team-harvest.md` — sections A–G complete, each with its `QA20X-nn` checks, evidence
  references and a sign-off line.
- `docs/qa/README.md` — the Phase 20 row finalised (check count, time needed, "signed").
- `docs/qa/sign-off-sheet.md` — the Phase 20 row and the seven gate records.
- `docs/phase-log.md` — the seven phase entries (each written at its own gate) and the release entry.
- `system/README.md` — the migration row, the tables/settings/case/smoke counts, the settings block, the
  queue and the two jobs, the `APP_PHASE` line.
- `docs/deploy.md` — the migration, the four settings, the ten ladder rows, the go-live order, the
  verification counts.
- `docs/runbook.md` — the Team Harvest section: the go-live order (deploy → seed dry run → seed →
  enable), what to check weekly, how to read the ledger, what to do when the reconcile fails, how to
  repair a counter, and the rule that a position is never renumbered.
- `docs/admin-guide.md` — the settings card, the queue card, the ledger, the member panel, the reports.
- `docs/cron.md` — verify only: this stream adds **no** cron entry, and the page must say why.

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
5. **The release acceptance table below is complete.** Every line is ticked against a named phase and a
   named case, or the release is not signed.
6. **The go-live order is documented in one place and repeated nowhere.** `docs/runbook.md` §Team
   Harvest owns it; every other document links to it.

**Release acceptance table**

| Acceptance criterion | Proved by |
|---|---|
| A member with three direct members enters the queue at the next position, once | 20C — P20C-T1, T2 |
| A position is never renumbered, in any later registration or repair | 20C — P20C-T4, T20, T21 |
| Staff and the company account never qualify and never count | 20C — P20C-T5, T6 |
| A direct's status does not change qualification | 20C — P20C-T7 |
| The reconstructed history is deterministic and idempotent | 20C — P20C-T10–T13 |
| Every queued ancestor's counter moves by one, at any depth | 20C — P20C-T16, T17 |
| Every counter equals a from-scratch recount | 20C — P20C-T19; 20D — P20D-T29 |
| The seed is inert until the baseline exists | 20C — P20C-T9; 20D — P20D-T21 |
| A completed level pays its configured amount once | 20D — P20D-T1–T8 |
| Partial blocks pay nothing | 20D — P20D-T1, T4, T5 |
| The ladder is the reference ladder, to the peso | 20D — P20D-T2, T6, T8; 20B — P20B-T11 |
| A level already complete at the baseline is recorded, never paid | 20D — P20D-T14–T16 |
| Only levels up to `max_levels` are paid | 20D — P20D-T22, T23 |
| A ladder edit never rewrites a past award | 20D — P20D-T24 |
| The stream off means no money and a growing queue | 20D — P20D-T19, T20 |
| A replay and a duplicate insert move no money | 20D — P20D-T11–T13 |
| The buyer — there is none — is not a concept here: no purchase input anywhere | 20F — P20F-T14; 20D — the whole suite runs with no orders |
| `wallet_reconcile.php` exits 0 with a Team Harvest section | 20D — P20D-T27, T30 |
| The reset empties the queue and the awards and keeps the ladder | 20A — P20A-T8; 20D — P20D-T31 |
| A fresh install reports 36 tables, 77 settings, 10 ladder rows | 20A — P20A-T6 |
| The migration is idempotent; migrated equals fresh | 20A — P20A-T2, T3 |
| The four admin surfaces and the three member surfaces show the fixture numbers | 20E — T2–T13; 20F — T1–T7 |
| The three existing commission tabs and three earnings tabs are unchanged | 20E — T1; 20F — T9 |
| The member copy states the gate, the exclusions and the purchases footnote | 20F — T12–T14 |
| No new route, no new cron entry, no new permission | 20E — T24; 20F — T22; 20G — the smoke walk |
| The docs are updated | 20G — this phase |

**Test plan**

No new automated cases: this phase changes no behaviour, and a documentation claim that no case covers
is a defect in the phase that owns the behaviour (Rule 1). Verification is the accumulated suite plus
the acceptance table, executed in this order:

1. `php tests/run.php` — the documented case count, 0 failed, exit 0.
2. `powershell -File tests/smoke.ps1` — the documented check count, 0 failed, exit 0.
3. `php bin/build_install_sql.php --check` — exit 0 on the committed bundle.
4. `php bin/wallet_reconcile.php` — exit 0 with the Team Harvest section.
5. `php bin/team_harvest_catchup.php --doctor` — exit 0.
6. The release acceptance table — every row ticked against a named phase and a named case.
7. `APP_PHASE` — 20 in `config.php`, reported by the suite header and asserted by `HardeningTest`.

**Manual gate — `QA20G-01` … `QA20G-06`**

1. `QA20G-01` — `php tests/run.php` → the documented count, 0 failed; `tests/smoke.ps1` → 0 failed;
   `build_install_sql --check` → exit 0.
2. `QA20G-02` — hand the QA pack to a tester who has not seen this plan; they execute sections A–F
   without asking a question. Any question is a documentation defect.
3. `QA20G-03` — walk the release acceptance table row by row; every row cites a passing case or an
   evidence file.
4. `QA20G-04` — the build reports **20**: the runtime constant, the suite header and `HardeningTest`;
   `/dashboard` answers 200 as an admin and every shipped menu item renders.
5. `QA20G-05` — on a scratch install, follow `docs/deploy.md` and then the runbook's go-live order end
   to end: deploy, seed dry run, seed, enable, register three members, see one award.
6. `QA20G-06` — confirm the evidence folder contains one file per `QA20X-nn` check, and the sign-off
   sheet carries the seven gate records.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] `APP_PHASE === 20`; HardeningTest green
[ ] The release acceptance table is complete and every row is ticked
[ ] The QA pack is signed; the sign-off sheet carries Phase 20
[ ] The seven gate records in Appendix H are filled
[ ] The phase log has seven entries plus the release entry
[ ] Tag p20g-release (and `phase-20` as the feature tag); the plan's status line is updated
```

**Rollback**

Documentation and `APP_PHASE`: revert the constants and the docs. Behaviour is unchanged, so this is
the safest phase to revert.

**Hand-off**

The workstream is closed. The next plan may build the deferred items in §18 — the General Harvest
board on the shared queue, a retroactive baseline decision, or a forward-only correction tool — **without
reopening any of these seven gates**, because each is a new stream or a new decision with its own
economics.

**Risks**

| Risk | Mitigation |
|---|---|
| The docs describe an intention the code does not implement | Every claim is executed in the acceptance pass; the code wins |
| The QA pack is written by the author and hides a gap | Rule 3, and `QA20G-02` uses a different tester |
| `APP_PHASE` lands without `HardeningTest` | They are one deliverable in one commit |
| A defect is found during the acceptance pass and quietly patched | Rule 1: fix it, re-run that phase's gate, record the deviation |
| The go-live order is documented in five places and drifts | Rule 6: one owner, links everywhere else |

---

# Part III — The shared contract

## 12. Specification (normative)

This section is the contract. Where it diverges from the simulator, the divergence is stated and
justified; the simulator remains the authority for the **mechanics** (the three-direct gate, the
all-or-nothing levels, the "qualified after me" rule, the 3-ary staircase), not for the app's data
model. §12.12 is the complete list of divergences.

Each subsection names the phase that implements it. A phase may not implement a rule from a later
subsection.

### 12.1 Vocabulary and symbols

| Term | Meaning | Phase |
|---|---|---|
| **Harvest queue** / **queue** | The ordered list of qualified members in `harvest_queue`. Order = `id`. | 20C |
| **position** | A member's place in the queue, 1-based, equal to `harvest_queue.id`. Fixed for ever. Displayed `#N`. | 20C |
| **seq** | `position − 1`, the 0-based index the arithmetic uses. | 20C |
| **qualified** | In the queue: the member held at least `team_harvest_qualify_directs` direct member accounts at the moment they were checked (§12.2). | 20C |
| **direct** | A `role = 'member'` account whose `users.sponsor_id` is the member. Staff accounts and the company account are never directs for this count. | 20C |
| **teammate** | A qualified member anywhere below the member in the sponsor tree whose position is **greater** than the member's. | 20C |
| **n** / **teammates** | The number of a member's teammates; `harvest_queue.teammates`. | 20C |
| **Team matrix** / **matrix** | A member's private 3×10 board: level `L` holds `3^L` slots, filled by teammates in position order. | 20C/20F |
| **slot** | One place in the matrix, 0-based (`r`). Slots 0–2 are level 1, 3–11 level 2, 12–38 level 3, and so on. | 20C |
| **level block** | The `3^L` consecutive slots of level `L`, starting at `(3^L − 3)/2`. | 20C |
| **complete(d, L)** | The member's level-`L` block is full: `n ≥ (3^(L+1) − 3)/2`. | 20D |
| **award** | One `team_harvest_awards` row: one (member, level) decision, `credited` or `baseline`. | 20D |
| **baseline** | The instant the queue was seeded (`team_harvest_ledger_since`), and per member `harvest_queue.baseline_n`, the `n` they held at that instant. | 20C |
| **baseline row** | An award row for a level already complete at the baseline: amount recorded, no money. | 20D |
| **credit** | A withdrawable e-wallet movement, `ref_type = 'team_harvest'`, `ref_id` = the award id. | 20D |
| **the gate** | `team_harvest_qualify_directs`, default 3. | 20B |
| **max depth** | `team_harvest_max_levels`, default 10: the deepest level the build pays. | 20B |
| **the preset** | The reference ladder of Appendix A: ₱1,000 … ₱3,500,000. | 20A/20B |
| **N** | Reserved in this document for the network's qualified-member count (used only in the economics notes, §12.8 F). A member's own count is always `n`. | — |

### 12.2 Qualification

> **A member is qualified the moment they hold at least `team_harvest_qualify_directs` direct member
> accounts. The check runs when a member account is created — never on a schedule, a purchase or a
> promotion — and it can only ever add the member to the queue.**

```
qualified(i)  ⇔  directs(i) ≥ team_harvest_qualify_directs      (default 3)
directs(i)    =  |{ u : u.role = 'member' ∧ u.sponsor_id = i }|
```

Rules:

1. **Checked at registration, in the registration's transaction.** When a new member row is written,
   their sponsor is checked (`HarvestQueue::qualifySponsor($sponsorId)`). If the sponsor now holds the
   gate and is not queued, they are appended. The check is not repeated for anybody else, and no other
   event in the application triggers it (reference §3).
2. **The gate is a count, not a production measure.** The three directs may be brand new, may never
   buy, may be suspended, and may themselves never qualify. Nothing about their activity, package,
   rank or status is read (reference §3 and §11.2 #13).
3. **The company account never qualifies.** It is a `role = 'admin'` account, so it is not a member
   and never appears in the queue (reference §3: the check requires a sponsor id greater than 0).
4. **Staff accounts never qualify and never count.** A `role = 'admin'`/`'superadmin'` account is not a
   direct for the count and is never queued, even if it has ten member directs.
5. **Status does not gate qualification.** A `pending`, `inactive` or `suspended` account counts as a
   direct, and can itself qualify. The queue is a structural record; status is a withdrawal concern
   (D-02, D-13).
6. **The gate is configurable, and a change is not retroactive.** Raising it never removes a queued
   member (positions are facts); lowering it takes effect at the next registration, and the seed uses
   the value in force when it runs.
7. **Qualification is not a rank.** It unlocks no feature other than this stream, it is invisible to
   Royalty's rank ladder, and a Royalty Director can be unqualified here while an ordinary Member with
   three directs is qualified. The reference makes the same distinction (reference §1.1).
8. **A missed qualification can be repaired, never invented.** `harvest_queue_seed.php --rebuild`
   appends a member who holds the gate but has no row, at the next position. It never removes a row
   and never renumbers one.

**Phase.** 20C.

### 12.3 The queue

The queue is **one table, one row per qualified member, ordered by `id`**:

| Property | Rule | Why |
|---|---|---|
| Order key | `harvest_queue.id`, assigned by auto-increment | Concurrency-safe (no `MAX()+1` race) and monotone |
| Position (display) | `id` (1-based); a member says "position #7" | The reference's "position #1 = queue index 0" becomes `#1` for the apex |
| seq (arithmetic) | `id − 1` | The reference's 0-based index, kept for every formula in §12.5 |
| Append-only | A row is never updated except its `teammates` counter; never deleted, never renumbered | An award is the difference between two positions; renumbering would rewrite history |
| Gaps | Allowed and meaningless; a deleted member leaves a gap | The alternative — compacting — is renumbering |
| One row per member | `UNIQUE KEY uq_queue_user (user_id)` | A member qualifies once |
| No re-qualification | A member who leaves the queue cannot exist (no deletion in the app), and a re-run of the seed never duplicates a row | History is written once |
| Order changes | Never. Only three things write a row, all in 20C: a registration, the seed and a repair | Nothing else in the application may append or reorder |

**The go-live seed.** For the tree that already exists when the feature ships, the queue is
reconstructed:

```
for each member i with directs(i) ≥ gate:
    t(i) = the created_at of i's Nth direct, where N = the gate and
           the directs are ordered by (created_at, id)          -- N = 3 by default
    qualified_at(i) = t(i)
order members by (qualified_at, id)                            -- or by id with --by-id
insert them in that order                                      -- ids 1..Q
```

The seed writes `baseline_n = teammates` for every member, sets `team_harvest_ledger_since` to the
run's timestamp, and refuses a non-empty queue without `--force` (§14.10). `--by-id` is the fallback
for data whose timestamps were imported or backdated: it orders by the member's own id, which in this
application is registration order.

**Phase.** 20C.

### 12.4 Teammates and `n`

> A **teammate** is a qualified member **anywhere below the member** in the sponsor tree whose
> position is **greater** than the member's. `n` is how many there are.

```
teammate(d, i)  ⇔  i is a proper descendant of d in the sponsor tree
                   ∧ i is queued
                   ∧ position(i) > position(d)
n(d)            =  |{ i : teammate(d, i) }|
```

Rules:

1. **Any depth.** There is no ten-level cap and no `SponsorService::MAX_LEVELS` cap. A teammate eight
   sponsor hops down counts exactly like a direct (reference §5.1; contrast Unilevel's ten-level
   cap).
2. **Only qualified members count.** An unqualified descendant is invisible until they qualify; the
   day they do, they become a teammate of every queued ancestor (reference §5.4 and §5.1).
3. **Only later qualifications count.** A descendant who qualified **before** the member is
   permanently excluded, no matter how deep or how productive (reference §5.1 and §11.1 #4). This is
   the plan's traffic rule, and it is not a bug: it is what makes early qualification worth something.
4. **Only the sponsor subtree counts.** A member's sponsor, their uplines, and every member outside
   their subtree never count (reference §5.4).
5. **The member's own directs do not matter, only their qualification.** A member with thirty directs
   and no qualified-before-them descendants has `n = 0` (reference §5.4).
6. **`n` never decreases.** The only event that changes it is a descendant's qualification. No
   purchase, refund, cancellation, status change, package change or rank change can lower it.
7. **The queue stores `n` as a counter** (`harvest_queue.teammates`), incremented once per qualifying
   descendant per queued ancestor (D-05). `recount()` recomputes it from the tree and is the authority
   when the two disagree (§14.4).
8. **`n` is monotone, so a level completion is a one-way door.** That is why the ledger can be
   state-based and still be exact (D-19).

**Worked reference example** (reference §5.6, verified): tree `1 → 2`, `2 → {4,5}`, `1 → 3`,
`3 → 6`, `6 → 7`, with the qualification order `3, 1, 2, 4, 5, 6, 7`:

| Member | Position | Teammates | `n` | Levels | Earned |
|---|---:|---|---:|---|---:|
| 1 | #2 | 2, 4, 5, 6, 7 | 5 | 1 | ₱1,000 |
| 2 | #3 | 4, 5 | 2 | — | ₱0 |
| 3 | #1 | 6, 7 | 2 | — | ₱0 |
| 6 | #6 | 7 | 1 | — | ₱0 |
| 4, 5, 7 | #4, #5, #7 | — | 0 | — | ₱0 |

Member 1's teammate `3` is in its subtree but qualified **first**, so it is not counted; its five are
`2, 4, 5, 6, 7`. Member 2's sibling `3` is not below it (reference §5.6).

**Phase.** 20C (the counter), 20D (the award).

### 12.5 The matrix and the level blocks

The private matrix is a **3-ary heap over the teammate list**, filled in position order:

```
slot r (0-based)          r = 0, 1, 2, …
level of slot r           L(r) = floor(log3(2r + 3))
first slot of level L     start(L) = (3^L − 3)/2         → 0, 3, 12, 39, 120, …
slots in level L          3^L                            → 3, 9, 27, 81, 243, …
filled(n, L)              clamp(n − start(L), 0, 3^L)
complete(n, L)            n ≥ start(L) + 3^L = (3^(L+1) − 3)/2
placed_under(r)           r < 3 ? "you" : teammate[r/3 − 1]      (integer division)
progress %(n, L)          round(100 · filled(n, L) / 3^L, 1)
```

The layout, level by level:

| Level | Slots | Slot range `r` | Placed under | Completes at `n ≥` |
|---:|---:|---|---|---:|
| 1 | 3 | 0–2 | you | 3 |
| 2 | 9 | 3–11 | teammates 0–2, three each | 12 |
| 3 | 27 | 12–38 | teammates 3–11, three each | 39 |
| 4 | 81 | 39–119 | teammates 12–38 | 120 |
| 5 | 243 | 120–362 | teammates 39–119 | 363 |
| 6 | 729 | 363–1,091 | teammates 120–362 | 1,092 |
| 7 | 2,187 | 1,092–3,278 | teammates 363–1,091 | 3,279 |
| 8 | 6,561 | 3,279–9,839 | teammates 1,092–3,278 | 9,840 |
| 9 | 19,683 | 9,840–29,522 | teammates 3,279–9,839 | 29,523 |
| 10 | 59,049 | 29,523–88,571 | teammates 9,840–29,522 | 88,572 |

Rules:

1. **The placement is arithmetic, not sponsorship.** `placed_under` is a display of `r`, exactly like
   the reference's *Placed under* column (reference §5.4). Where a teammate actually sits in the
   sponsor tree is shown in the roster and never affects the matrix.
2. **Teammates fill in position order**, oldest qualification first — the same order every member
   sees, which is why two members' matrices are comparable (reference §5.2).
3. **The matrix is unbounded but only ten levels pay.** A member with 100,000 teammates has more than
   ten blocks' worth of slots; the eleventh block is not a level of this plan (reference §4.1 makes
   the same point for General Harvest).
4. **A level is all-or-nothing.** `filled < 3^L` pays nothing; the progress bar is information, not a
   pro-rata promise (reference §5.2).
5. **The matrix is derived, never stored.** Slots and placements are computed from `n` and the
   ordered teammate list on every render; the only stored facts are the positions and the awards
   (reference §2.5's "derived" column, kept for the view and replaced by the ledger for the money).

**Phase.** 20C (the arithmetic and the roster), 20F (the card).

### 12.6 The bonus ladder

Each level pays a **flat amount**, independent of the member's position, team size, rank, purchases or
the number of people in the block (reference §5.3):

| Level | Slots `3^L` | Completes at `n ≥` | Reference amount | Cumulative |
|---:|---:|---:|---:|---:|
| 1 | 3 | 3 | ₱1,000 | ₱1,000 |
| 2 | 9 | 12 | ₱2,000 | ₱3,000 |
| 3 | 27 | 39 | ₱4,000 | ₱7,000 |
| 4 | 81 | 120 | ₱6,000 | ₱13,000 |
| 5 | 243 | 363 | ₱12,000 | ₱25,000 |
| 6 | 729 | 1,092 | ₱50,000 | ₱75,000 |
| 7 | 2,187 | 3,279 | ₱120,000 | ₱195,000 |
| 8 | 6,561 | 9,840 | ₱200,000 | ₱395,000 |
| 9 | 19,683 | 29,523 | ₱1,200,000 | ₱1,595,000 |
| 10 | 59,049 | 88,572 | ₱3,500,000 | ₱5,095,000 |
| **Total** | **88,572** | | **₱5,095,000** | |

Rules:

1. **Amounts are configured, thresholds are not.** The ten thresholds are the matrix; only the ten
   amounts and the maximum depth are settings (D-18).
2. **A zero amount is a legitimate configuration** and means "this level completes and pays nothing".
   While the amount is zero **no credited row is written** — a `0.00` credit would be a lie about
   money, and the wallet refuses a zero credit anyway — but the level is still shown as complete on the
   member's matrix. If the amount is later raised, the level has no credited row and therefore pays at
   the next evaluation. **A baseline row is still written at `0.00`** when the level was already
   complete at the seed, so raising the amount can never pay a pre-go-live level. This is the one place
   where a configuration change can make a completed level payable, and it is deliberate: a zero amount
   is a switch, not a mistake.
3. **`max_levels` caps the payable depth.** Levels above it complete on the board and are marked
   *not paid*; the amounts are kept, and raising the depth pays them from the current `n` (the engine
   is state-based, so no history is lost).
4. **The full ladder is ₱5,095,000 per member**, reached at `n = 88,572` (reference §9.2). It is the
   per-member ceiling of this stream, not the network's cost (§12.8 F).
5. **The reference's amounts are the seeded preset** (Appendix A). An operator may change them; the
   card shows the new total and the audit row records it (20B).

**Phase.** 20B (configuration), 20D (payment).

### 12.7 The award rule

For a queued member `d` at evaluation time:

```
for L = 1 .. team_harvest_max_levels:
    if n(d) < threshold(L):                     break         # thresholds ascend: nothing deeper is complete
    if award exists for (d, L):                 continue      # paid or baselined already
    if threshold(L) <= baseline_n(d):           write baseline row; continue   # complete before go-live
    if amount(L) = 0:                           continue      # a zero level is a switch, no row
    → insert credited award (amount, threshold_n, n_at_award)
    → credit the withdrawable wallet, ref_type 'team_harvest', ref_id = award.id
```

Rules:

1. **State-based.** The comparison is `n` against the ledger, not an event counter. A missed
   evaluation, a rolled-back transaction, a repaired counter or a late enablement all converge to the
   same answer on the next evaluation (D-19).
2. **Complete means complete.** `n ≥ threshold(L)` is the whole test; there is no partial award, no
   proration and no "qualifying volume" (reference §5.2).
3. **A level pays once, ever.** The row is the lock: `UNIQUE (user_id, level)` plus the pre-check.
   Replaying an evaluation moves nothing.
4. **The baseline is decided once, per member, at the seed.** `baseline_n` never changes. A level
   whose threshold is at or below it is written `baseline` (amount recorded, `ledger_id NULL`) and is
   never credited — not at enablement, not later (D-07).
5. **The amount is snapshotted.** `amount`, `threshold_n` and `n_at_award` are written on the row. A
   later ladder edit changes only future awards (D-10, §12.9).
6. **The money is withdrawable and immediate.** It is a wallet credit, not a hold, not a pending
   award, not a monthly accrual (D-11). It appears in `/wallet` the second the transaction commits.
7. **The stream must be on.** `team_harvest_enabled = 0` writes nothing, credits nothing, and leaves
   the queue intact (D-09).
8. **The queue must be seeded.** An empty `team_harvest_ledger_since` is a hard refusal
   (`TeamHarvestException::unseeded()`), because without a baseline nobody can tell a pre-go-live
   level from a payable one.
9. **The recipient must be a member.** The engine throws rather than pay a `role <> 'member'` row; the
   queue already makes it impossible (20C Rule 4).
10. **The award is atomic with its credit.** Both or neither; a failure rolls the whole evaluation back
    and the next one retries the same level (Rule 1).

**Phase.** 20D.

### 12.8 Worked examples

All examples use the reference ladder and are verified by the suite (§16.3).

**A. The reference's seven-member tree** (§12.4 table). Member 1 earns level 1 only: **₱1,000.00**.
Every other member is below the three-teammate gate.

**B. Fixture A — 40 qualified accounts (121 accounts in total)** — a perfect 3-ary tree (1 root + 3 + 9
+ 27 qualified, each of the 27 with three non-qualifying directs):

| Depth | Qualified | `n` | Levels | Each | Subtotal |
|---:|---:|---:|---|---:|---:|
| 0 | 1 | 39 | 1–3 | ₱7,000 | ₱7,000 |
| 1 | 3 | 12 | 1–2 | ₱3,000 | ₱9,000 |
| 2 | 9 | 3 | 1 | ₱1,000 | ₱9,000 |
| 3 | 27 | 0 | — | ₱0 | ₱0 |
| | **13 paying** | | | | **₱25,000** |

**C. Fixture A's root** (`n = 39`): levels 1–3, **₱7,000.00**. The same tree where the root already
held `n = 12` at the baseline: levels 1–2 are baseline, and only level 3 credits **₱4,000.00**.

**D. Fixture B's root** (121 qualified accounts, 364 accounts in total; `n = 120`): levels 1–4,
**₱13,000.00**.

**E. The per-member ceiling, by pure function.** `earnedFor(88,572)` = **₱5,095,000.00**;
`earnedFor(88,571)` = **₱1,595,000.00** (level 10 needs the last teammate); `earnedFor(29,523)` =
**₱1,595,000.00** (level 9); `earnedFor(3)` = **₱1,000.00**; `earnedFor(2)` = **₱0.00**.
These are unit cases: no tree of 88,573 members is built in the suite (§16.5).

**F. What a whole network costs.** A *perfect* network where every qualified member holds three
directs and the leaf qualified members hold three non-qualifying directs has this shape at
`n = 88,572`:

| Qualified depth | Members | `n` each | Earns each | Subtotal |
|---:|---:|---:|---:|---:|
| 0 | 1 | 88,572 | ₱5,095,000 | ₱5,095,000 |
| 1 | 3 | 29,523 | ₱1,595,000 | ₱4,785,000 |
| 2 | 9 | 9,840 | ₱395,000 | ₱3,555,000 |
| 3 | 27 | 3,279 | ₱195,000 | ₱5,265,000 |
| 4 | 81 | 1,092 | ₱75,000 | ₱6,075,000 |
| 5 | 243 | 363 | ₱25,000 | ₱6,075,000 |
| 6 | 729 | 120 | ₱13,000 | ₱9,477,000 |
| 7 | 2,187 | 39 | ₱7,000 | ₱15,309,000 |
| 8 | 6,561 | 12 | ₱3,000 | ₱19,683,000 |
| 9 | 19,683 | 3 | ₱1,000 | ₱19,683,000 |
| 10 | 59,049 | 0 | ₱0 | ₱0 |
| | **88,573 qualified** (265,720 members) | | | **₱95,002,000** |

At the reference registration price (₱5,000, `BASIC_ACCOUNT_PRICE` in the reference model) that network
would raise ₱1.33 billion against a **₱95.0 million** maximum Team Harvest payout — about **7.2%** of
registration revenue, with the top member taking ₱5.1 million of it. The ₱5,095,000 figure is a
*ceiling per member*, never a forecast: 59,049 of the 88,573 qualified members earn nothing at all.

**G. `max_levels = 2`.** Fixture A's root completes levels 1–3 but is paid levels 1–2 only:
**₱3,000.00**, two rows. Level 3 is displayed as *not paid*; raising the depth to 3 pays it at the next
evaluation, once.

**Phase.** Worked in 20D's tests; shown in 20E/20F's pages.

### 12.9 Money and rounding

- **There is nothing to round.** A level's amount is a configured `DECIMAL(12,2)` value; the award is
  that value. No percentage is applied and no multiplication is performed (contrast §12.8 of Phase 17
  and §12.8 of Phase 19).
- **Money is integer centavos inside the engine** (`(int) round($amount * 100)`) and `DECIMAL(12,2)`
  in the database, formatted with `money()` in views. No float arithmetic touches a stored amount.
- **No tax, no fee, no cost of goods.** Awards are gross, like every other bonus in this build.
- **Withdrawable on credit** (D-11): the movement raises both the wallet and the withdrawable bucket,
  subject to the existing payout rules when the member requests a payout.
- **A zero amount is never credited.** The ladder refuses a zero amount at write time by skipping the
  row (§12.6 Rule 2); the wallet refuses a `0.00` credit anyway, so the two agree.
- **One ledger row per award.** `ref_id` is the award id, and the wallet's
  `UNIQUE (user_id, ref_type, ref_id, type)` anchor makes a second credit impossible even if the award
  insert were replayed.

**Phase.** 20D.

### 12.10 Ceilings, validation and guards

| Guard | Rule | Where | Phase |
|---|---|---|---|
| Gate | `team_harvest_qualify_directs` 1–10 | settings card | 20B |
| Max depth | `team_harvest_max_levels` 1–10 | settings card | 20B |
| Level amount | 0.00 – 10,000,000.00, two decimals | `TeamHarvestLevel::validate()` | 20B |
| Ladder total | ≤ 25,000,000.00 (typo guard) | `TeamHarvestLevel::validate()` (`sum` error) | 20B |
| Enabling | a zero-total ladder cannot be enabled | `TeamHarvestLevel::validate()` | 20B |
| One position | `UNIQUE (user_id)` on the queue | schema | 20A/20C |
| One award | `UNIQUE (user_id, level)` on the awards | schema | 20A/20D |
| Ledger | `UNIQUE (user_id, ref_type, ref_id, type)` | existing wallet | 20D |
| Amount | `amount >= 0`; `level BETWEEN 1 AND 10`; `status IN ('credited','baseline')` | schema CHECKs | 20A |
| Recipient | `role = 'member'`, never the company account | engine guard | 20D |
| Sequence | positions never renumbered; a rebuild appends only | `HarvestQueue::rebuild()` | 20C |
| Baseline | `baseline_n` written once; a rebuild never rewrites it | `HarvestQueue::seed()` | 20C |

There is **no per-purchase, per-month or per-network cap** — the reference has none, and the ₱25M
ladder ceiling is a configuration guard, not a payout cap. If the business wants a cap, it belongs in a
new decision (§18 Q5), not in a hidden clamp.

### 12.11 What does not affect Team Harvest

- **Purchases, orders, products, P.V., discounts and shipping.** Nothing in this stream reads the shop.
- **The member's rank** (Leader/Supervisor/Manager/Director) and the Royalty ladder's thresholds.
- **The monthly Active flag** and the Unilevel monthly minimum — this stream has no month.
- **The member's own direct count after qualification.** What matters is how many qualified members
  appear below them *after* them (reference §5.4).
- **The directs' status, package, activity or spending.** They count the day they register.
- **The month, the day or the hour.** A qualification on the 1st and one on the 31st behave
  identically (reference §5.4).
- **The member's own wallet balance, payout history or transfer limits.** Money is credited whether or
  not the member can withdraw it today (D-13).
- **The sponsor-tree placement of a teammate.** It decides whether they are a teammate at all, never
  *where* in the matrix they sit.
- **The number of registrations.** Every qualification is checked; there is no batch cap (a reset or a
  bulk import simply appends).

### 12.12 Deliberate divergences from the simulator

The simulator is the authority for the mechanics. These are the places this build must differ, and
why. Each is a decision or a rule above; none is an accident.

| # | Simulator | This build | Why |
|---:|---|---|---|
| 1 | The queue is an array `mx` in `localStorage` | A table with one row per member, `id` = position | Money must survive a browser, a backup and an audit |
| 2 | Qualification is checked in `reg()` and appended; nothing else can change it | Same rule, in the registration transaction, plus a seed for the pre-existing tree | The app has a real history that one deploy cannot ignore |
| 3 | Team Harvest money is **derived**, never ledgered (reference §2.5) | A ledger row per level and a wallet credit | A derived payout cannot be paid, reconciled or explained |
| 4 | No baseline: the board's whole history counts from the first render | `baseline_n` + `baseline` rows: pre-go-live levels are recorded, never paid | Phase 17's D-16: the company never agreed to pay for a period before the stream existed |
| 5 | `levelsT(n)` shows a progress bar for partial levels | Same display, but a partial level writes nothing at all | All-or-nothing is the plan; a progress bar is information |
| 6 | No `max_levels`; ten levels always | `team_harvest_max_levels` (default 10) caps the payable depth | An operator must be able to stop at a level without editing the ladder |
| 7 | The company is a synthetic member that never enters the queue | The company is a staff account; the queue only holds `role = 'member'` | Same outcome, without a fake user row |
| 8 | Ten levels and no deeper arithmetic | Same; the matrix is unbounded but only ten levels pay | The plan is ten levels |
| 9 | Everything is one browser's state; no accounts, no audit | Real accounts, an append-only queue, an award ledger and audit rows | The app has real members |
| 10 | Bulk growth can add 20,000 members in one click | No bulk member creation exists in the app; the seed is the only bulk path, and it is a one-time job | There is nothing to adapt until a bulk import ships (§18 Q8) |
| 11 | The plan document's "QA / retail-customer" gate and any extra rank are not modelled | Not modelled here either | They are not in the reference specification, and older marketing documents are not the source of truth (Appendix G.3) |

---

## 13. Data model

### 13.1 Entity relationship

```
users ──1:1── harvest_queue          (one row per qualified member; id = position)
  │                 │
  │                 └── teammates / baseline_n   (the two counters)
  │
  ├──1:n── team_harvest_awards       (user_id = the earner; UNIQUE (user_id, level))
  │                 │
  │                 └── ledger_id ──▶ ewallet_ledger.id  (ref_type = 'team_harvest')
  │
  └──1:n── users (sponsor_id)        (the tree the queue and the teammates are derived from)

team_harvest_levels ── 10 rows, levels 1…10, the configured amounts (catalogue)
settings            ── team_harvest_enabled / _qualify_directs / _max_levels / _ledger_since
```

### 13.2 `harvest_queue` — one row per qualified member, `id` is the position

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `INT UNSIGNED` | no | auto | **The position** (1-based). Never renumbered, never reused. |
| `user_id` | `INT UNSIGNED` | no | — | The qualified member. `UNIQUE`; FK → `users.id`, `ON DELETE CASCADE` |
| `qualified_at` | `DATETIME` | no | — | The qualification instant: the Nth direct's `created_at` (seed) or the new member's `created_at` (live) |
| `directs_at_qualification` | `SMALLINT UNSIGNED` | no | — | The direct count that opened the gate; an audit fact, never re-read |
| `teammates` | `INT UNSIGNED` | no | `0` | **`n`** — the counter, maintained by 20C (§14.4) |
| `baseline_n` | `INT UNSIGNED` | no | `0` | `n` at the baseline instant; written once by the seed; `0` for anyone queued later |
| `source` | `ENUM('registration','seed','repair')` | no | `registration` | How the row was created; `repair` only from `--rebuild --apply` |
| `created_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP` | Row write time (≈ `qualified_at`, but not the same fact) |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY uq_queue_user (user_id)`, `KEY idx_queue_qualified (qualified_at)`,
`KEY idx_queue_teammates (teammates)`.

Checks: `chk_hq_directs CHECK (directs_at_qualification >= 1)`,
`chk_hq_baseline CHECK (teammates >= baseline_n)`.

The second check is a **data-integrity assertion**, not a business rule: `teammates` only grows after
the baseline. A rebuild whose recount would fall below `baseline_n` refuses to apply and reports the
violation, because that can only mean a descendant was deleted from the tree (§14.4, §14.13).

**No auto-increment reset trick.** `id` is the sequence; a business reset empties the table and resets
the counter, and the baseline key is cleared in the same reset (20A Rule 8) so a new network starts at
position 1 with no baseline.

### 13.3 `team_harvest_levels` — the ten amounts

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `level` | `TINYINT UNSIGNED` | no | — | 1–10, the primary key |
| `amount` | `DECIMAL(12,2)` | no | `0.00` | The flat bonus for completing this level |
| `updated_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Last edit |
| `updated_by` | `INT UNSIGNED` | yes | `NULL` | The staff member who last saved the ladder |

Keys: `PRIMARY KEY (level)`. Checks: `chk_thl_level CHECK (level BETWEEN 1 AND 10)`,
`chk_thl_amount CHECK (amount >= 0)`.

Catalogue, not money: **kept** by a business reset, like `package_levels` and
`product_unilevel_levels`. Ten rows always exist after a fresh install or a migration (20A Rule 9).

### 13.4 `team_harvest_awards` — the money ledger

One row per `(member, level)` decision. There is **no type column, no period, no order, no product, no
rate** — the level is the whole story.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `INT UNSIGNED` | no | auto | PK; also the wallet `ref_id` (the ledger's `ref_id` is `INT UNSIGNED`, so this must not be `BIGINT`) |
| `user_id` | `INT UNSIGNED` | no | — | The earner; FK → `users.id`, `ON DELETE CASCADE` |
| `level` | `TINYINT UNSIGNED` | no | — | 1–10 |
| `threshold_n` | `INT UNSIGNED` | no | — | The threshold in force when the row was written (snapshot) |
| `n_at_award` | `INT UNSIGNED` | no | — | The member's `n` at that moment (snapshot) |
| `amount` | `DECIMAL(12,2)` | no | — | The configured amount at that moment (snapshot); never `0.00` |
| `status` | `ENUM('credited','baseline')` | no | `credited` | `baseline` = complete before go-live, recorded, never paid |
| `ledger_id` | `INT UNSIGNED` | yes | `NULL` | The `ewallet_ledger.id` credited; `NULL` for every `baseline` row |
| `credited_at` | `DATETIME` | no | — | When the level was decided (the baseline instant for `baseline` rows) |
| `created_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP` | Row write time |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY uq_tha_member_level (user_id, level)` — **the money anchor** —
`KEY idx_tha_level (level, credited_at)`, `KEY idx_tha_credited (credited_at)`, `KEY idx_tha_status
(status)`, `KEY idx_tha_ledger (ledger_id)`.

Checks: `chk_tha_level CHECK (level BETWEEN 1 AND 10)`, `chk_tha_amount CHECK (amount >= 0)`,
`chk_tha_n CHECK (n_at_award >= threshold_n)`.

**There is no `voided` status** in v1 (D-12). A row is written once and never updated; the only mutable
things in this stream are a queue row's `teammates` and the ladder's amounts.

### 13.5 The wallet ENUM and the settings

**`ewallet_ledger.ref_type`** gains `team_harvest`, appended **last** so MySQL can use the in-place
`ENUM` path (the same technique as 19A's `royalty`):

```
ENUM('commission','payout','transfer','topup','registration','shop_order',
     'adjustment','refund','unilevel','unilevel_reversal','royalty','team_harvest')
```

**`WalletService::REF_TYPES`** gains `'team_harvest'` in the same commit (20A Rule 7), and
`wallet_ref_label('team_harvest')` returns *"Team Harvest bonus"* with the icon `bx-layer`.

**The four settings**

| Key | Default | Rule | Exposed as |
|---|---|---|---|
| `team_harvest_enabled` | `'0'` | `in:0,1` | *Enable the Team Harvest bonus* toggle |
| `team_harvest_qualify_directs` | `'3'` | required, integer, 1–10 | *Direct members needed to qualify* (number) |
| `team_harvest_max_levels` | `'10'` | required, integer, 1–10 | *Levels that pay* (number) |
| `team_harvest_ledger_since` | `''` | not editable, not rendered | the baseline instant; empty until the seed writes it |

`team_harvest_ledger_since` is deliberately **empty** at install (unlike `royalty_ledger_since`): it is
not a first-payable date, it is the proof that the queue was seeded (D-04). The settings card renders
its state, never its value as an editable field, and the seed job owns the write.

### 13.6 Migration `024_team_harvest.sql`

Idempotent and re-runnable, in the style of [`023_royalty_bonus.sql`](../system/database/migrations/023_royalty_bonus.sql):
every `CREATE TABLE` is `IF NOT EXISTS`, the `ENUM` change is guarded by an `information_schema` probe,
the settings insert never overwrites a value, and the ladder insert never overwrites an amount.

```sql
-- =============================================================
--  Phase 20 migration — Team Harvest bonus
--
--  Applies to a Phase 19 database and adds:
--
--    harvest_queue          one row per qualified member; id = position
--    team_harvest_levels    the ten configured amounts (catalogue)
--    team_harvest_awards    the Team Harvest money ledger
--
--    ewallet_ledger.ref_type + 'team_harvest'
--    four settings (the stream is off and unseeded by default)
--    the ten reference ladder rows
--
--  No backfill: the queue is reconstructed by
--  bin/harvest_queue_seed.php, on demand, with a dry run (plan D-04).
--  Nothing in the application reads either money table until 20D;
--  20A ships them empty and inert.
--
--      mysql -u root <db> < database/migrations/024_team_harvest.sql
--
--  Idempotent: every CREATE TABLE is IF NOT EXISTS; the ENUM change
--  is guarded; the settings insert is ON DUPLICATE KEY UPDATE
--  key_name = key_name; the ladder insert is ON DUPLICATE KEY UPDATE
--  level = level. Running the file twice changes nothing and never
--  overwrites an operator's value.
--
--  No `down` note: the tables are additive. Dropping them would erase
--  the record of every position and every award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. ewallet_ledger.ref_type — append 'team_harvest' ---------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type'
    AND COLUMN_TYPE LIKE '%team_harvest%');
SET @sql := IF(@has = 0,
  "ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM('commission','payout','transfer','topup','registration','shop_order',
          'adjustment','refund','unilevel','unilevel_reversal','royalty','team_harvest')
     NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. harvest_queue — positions, counters, baseline -----------------
CREATE TABLE IF NOT EXISTS harvest_queue (
  id                      INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id                 INT UNSIGNED     NOT NULL,
  qualified_at            DATETIME         NOT NULL,
  directs_at_qualification SMALLINT UNSIGNED NOT NULL,
  teammates               INT UNSIGNED     NOT NULL DEFAULT 0,
  baseline_n              INT UNSIGNED     NOT NULL DEFAULT 0,
  source                  ENUM('registration','seed','repair') NOT NULL DEFAULT 'registration',
  created_at              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_user (user_id),
  KEY idx_queue_qualified (qualified_at),
  KEY idx_queue_teammates (teammates),
  CONSTRAINT fk_hq_user     FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_hq_directs CHECK (directs_at_qualification >= 1),
  CONSTRAINT chk_hq_baseline CHECK (teammates >= baseline_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3. team_harvest_levels — the ten amounts (catalogue) -------------
CREATE TABLE IF NOT EXISTS team_harvest_levels (
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED     NULL,
  PRIMARY KEY (level),
  CONSTRAINT chk_thl_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_thl_amount CHECK (amount >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 4. team_harvest_awards — the money ledger ------------------------
CREATE TABLE IF NOT EXISTS team_harvest_awards (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED     NOT NULL,
  level        TINYINT UNSIGNED NOT NULL,
  threshold_n  INT UNSIGNED     NOT NULL,
  n_at_award   INT UNSIGNED     NOT NULL,
  amount       DECIMAL(12,2)    NOT NULL,
  status       ENUM('credited','baseline') NOT NULL DEFAULT 'credited',
  ledger_id    INT UNSIGNED     NULL,
  credited_at  DATETIME         NOT NULL,
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tha_member_level (user_id, level),
  KEY idx_tha_level (level, credited_at),
  KEY idx_tha_credited (credited_at),
  KEY idx_tha_status (status),
  KEY idx_tha_ledger (ledger_id),
  CONSTRAINT fk_tha_user   FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_tha_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_tha_amount CHECK (amount >= 0),
  CONSTRAINT chk_tha_n      CHECK (n_at_award >= threshold_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. the four settings --------------------------------------------
INSERT INTO settings (key_name, value) VALUES
  ('team_harvest_enabled',         '0'),
  ('team_harvest_qualify_directs', '3'),
  ('team_harvest_max_levels',      '10'),
  ('team_harvest_ledger_since',    '')
ON DUPLICATE KEY UPDATE key_name = key_name;

-- 6. the reference ladder (never overwrites an operator's amount) --
INSERT INTO team_harvest_levels (level, amount) VALUES
  (1,   1000.00),
  (2,   2000.00),
  (3,   4000.00),
  (4,   6000.00),
  (5,  12000.00),
  (6,  50000.00),
  (7, 120000.00),
  (8, 200000.00),
  (9, 1200000.00),
  (10, 3500000.00)
ON DUPLICATE KEY UPDATE level = level;
```

**Why the empty default for `team_harvest_ledger_since`** is safe in a `settings` row: the column is a
string, `setting('team_harvest_ledger_since', '')` returns `''`, and `seeded()` is `baseline() !== ''`.
No `DATE_FORMAT(NOW())` appears in this migration on purpose — a migration that stamped "now" would
pretend the queue had been seeded when it had not.

### 13.7 Fresh-install mirror (`database/schema.sql`)

The four blocks above are copied into `schema.sql` **in this order**, between the Royalty tables and
`impersonation_log`, with the same names, columns, keys, checks and charset, so
`MigrationTest`'s migrated-vs-fresh comparison stays green:

1. `harvest_queue` (after `royalty_awards`, before `impersonation_log`);
2. `team_harvest_levels`;
3. `team_harvest_awards`;
4. the `ewallet_ledger.ref_type` ENUM with `team_harvest` last, and the comment line naming it.

`seeds/001_settings.sql` gains the four keys **and** the ten ladder rows, in the same order as the
migration. `install/footer.sql` prints **36 tables, 77 settings**, and the installer's `EXPECTED` gains
`team_harvest_levels_rows => 10` (20A).

### 13.8 Invariants

These are the statements the suite asserts and the reconciler re-checks. A phase that breaks one is a
defect, not a variant.

| # | Invariant | Asserted by |
|---|---|---|
| I-01 | Every queue row has a unique `user_id` and a unique `id`; positions are contiguous from 1 only in a freshly seeded queue and are **never** renumbered afterwards | P20A-T2, P20C-T3, T11 |
| I-02 | A queued member is a `role = 'member'` account; the company account and staff are never queued | P20C-T5, T6 |
| I-03 | `teammates` equals the number of queued descendants with a greater position, for every member | P20C-T19, P20D-T29 |
| I-04 | `baseline_n ≤ teammates` for every row, and `baseline_n` never changes after the seed | P20C-T21, schema CHECK |
| I-05 | A member has at most one award per level; a baseline row and a credited row can never both exist for the same `(user_id, level)` | P20A-T11, P20D-T13 |
| I-06 | Every `credited` award has a `ledger_id`; every `baseline` award has `ledger_id IS NULL` | P20D-T10, T14 |
| I-07 | Σ credited awards per member = Σ `team_harvest` ledger credits per member, to the centavo | P20D-T27 |
| I-08 | No award exists for a level whose `threshold_n > n_at_award`, or above `max_levels` | P20D-T22, schema CHECK |
| I-09 | After a business reset the queue and the awards are empty and the baseline is `''`; the ladder survives | P20A-T8, P20D-T31 |
| I-10 | A level is never paid twice, including across a replay, a catch-up, a rebuild and a re-enable | P20D-T11, T12, T20 |
| I-11 | The stream off writes no award and no ledger row; the queue still grows | P20D-T19 |
| I-12 | No member file reads a money table directly; the engine is the only producer, and the views call the service | P20A-T15 and the 20F review |

### 13.9 Volume and retention

| Row | Growth | Retention |
|---|---|---|
| `harvest_queue` | one per qualified member, ever | permanent (a position is history) |
| `team_harvest_levels` | exactly 10 | permanent |
| `team_harvest_awards` | ≤ 10 per member, ever; bounded by `10 × members` | permanent (it is the payout record) |
| `ewallet_ledger` (`team_harvest`) | one per credited award | permanent, like every wallet movement |

A 10,000-member network with 3,000 qualified members is at most 30,000 award rows — smaller than the
Royalty stream's one row per member per month, and two orders of magnitude smaller than a busy
`unilevel_awards`. There is no pruning, no archive and no summary table, and none is needed at this
application's scale.

---

## 14. The money path

### 14.1 Components

| File | Kind | Responsibility |
|---|---|---|
| `HarvestQueue` | model | positions, qualification, ancestors, counters, matrix, seed, rebuild, verify |
| `TeamHarvestLevel` | model | the ten amounts and the structural arithmetic (slots, thresholds, cumulative) |
| `TeamHarvestAward` | model | the ledger: create, read, filters, totals, breakdowns, CSV rows |
| `TeamHarvestService` | service | the award decision: enable state, baseline, evaluate, statusFor, openLiability, reconcile |
| `TeamHarvestException` | exception | `unseeded`, `disabled`, `state`, `notMember` |
| `RegistrationService` | existing service | one added call: `onQualified($sponsorId)` inside the registration transaction |
| `bin/harvest_queue_seed.php` | CLI | the one-time reconstruction (§14.10) |
| `bin/team_harvest_catchup.php` | CLI | on-demand evaluation, dry run, doctor (§14.11) |
| `WalletService` | existing service | the credit (`ref_type = 'team_harvest'`) and its idempotency anchor |
| `Live` | existing helper | the surface bumps after a commit |

### 14.2 Public API

```php
// HarvestQueue (20C)
positionOf(int $userId): ?int                     // null when not queued
isQueued(int $userId): bool
directs(int $userId): int                         // role='member' only
qualifySponsor(int $sponsorId): ?int              // null when the gate is not met or unseeded
ancestorsOf(int $userId): array                   // queued ancestors, nearest first, cycle-safe
teammatesOf(int $userId): int                     // the stored counter
recount(int $userId): int                         // the authoritative subtree count
matrix(int $userId, int $offset = 0, int $limit = 100): array
teamRoster(int $userId): array                    // teammates in position order
stats(): array                                    // queued, positions, next, deepest n
seed(bool $dryRun = false, bool $byId = false, bool $force = false): array
rebuild(bool $dryRun = true, bool $apply = false): array
verify(): array
baseline(): ?string                               // team_harvest_ledger_since or null
seeded(): bool

// TeamHarvestLevel (20B)
amounts(): array                                  // [1 => '1000.00', …]
amount(int $level): string
threshold(int $level): int                        // (3^(L+1) − 3) / 2
slots(int $level): int                            // 3^L
cumulative(): array
total(): string
maxLevels(): int
qualifyDirects(): int
enabled(): bool
earnedFor(int $n): string                         // the pure function over the ladder
statusForNext(int $n): array                      // next level, needed, filled, remaining, percent

// TeamHarvestService (20D)
onQualified(int $sponsorId): array                // qualify + evaluate, one transaction
evaluate(array $userIds): array                   // {paid, baseline, skipped, off, amount}
evaluateAll(int $limit = 500): array
statusFor(int $userId): array                     // the card's whole payload (§14.12)
openLiability(): array                            // {levels, members, amount}
reconcile(): array                                // §14.13

// TeamHarvestAward (20D)
create(int $userId, int $level, array $snapshot): int
has(int $userId, int $level): bool
forMember(int $userId): array
paidLevels(int $userId): array
select(array $filters, Paginator $pager): array
totals(array $filters): array
byLevel(): array
byMonth(): array
exportRows(array $filters): array
```

### 14.3 Qualification algorithm

```
qualifySponsor(S):
    if baseline() = null: return null              # unseeded: inert (20C Rule 2)
    S = users[S];  if S is missing or S.role <> 'member': return null
    if queue has a row for S: return null          # already qualified
    d = count(users where sponsor_id = S and role = 'member')
    if d < qualify_directs: return null
    position = INSERT harvest_queue
                 (user_id = S, qualified_at = NOW(), directs_at_qualification = d,
                  teammates = 0, baseline_n = 0, source = 'registration')
    incrementAncestors(S, position)
    return position
```

`incrementAncestors()` is the reference's `counts()` walk, one member at a time:

```
incrementAncestors(S, position):
    cur = users[S].sponsor_id; visited = {}
    while cur is not null and cur > 0 and cur not in visited:
        visited[] = cur
        if queue has a row for cur and queue_row(cur).id < position:
            UPDATE harvest_queue SET teammates = teammates + 1 WHERE user_id = cur
        cur = users[cur].sponsor_id
    return the queued ancestors touched
```

The `id < position` guard is the "qualified after" rule expressed in SQL: an ancestor who qualified
after the newcomer is not an ancestor in the plan's sense for this event (their teammate count is
computed from their own descendants, which the newcomer may later join).

**Complexity.** O(depth) per qualification. The sponsor chain in this application is shallow (the
referral walk is capped at ten and real chains are shorter); even a 100-deep corrupt chain terminates
on the visited set.

### 14.4 The counter and its proof

The counter is **maintained, not computed** (D-05), so it needs a proof. Three operations define it:

| Operation | What it does |
|---|---|
| Increment | `teammates = teammates + 1` for every queued ancestor at each qualification (a positive delta only) |
| `recount($userId)` | walks the member's subtree (children map from `users.sponsor_id`, iterative, cycle-safe) and counts queued descendants with a greater position — the definition, computed |
| `rebuild()` | recounts every queued member, compares with the stored counter, reports the drift, and repairs it only with `--apply` |

Rules:

1. **The recount is the authority.** A disagreement is a defect (a lost increment, a corrupt tree, a
   manual edit) — never "close enough".
2. **No negative deltas, ever.** There is no event in this application that removes a descendant, so
   the increment path never subtracts. A rebuild that would *lower* a counter below `baseline_n`
   refuses to apply and reports the schema CHECK it would violate (§13.2).
3. **A repair never changes a position.** It changes a counter only, and it audits
   `harvest.queue.repair` with the before/after of every repaired row.
4. **The matrix is derived from the roster, not from the counter.** `matrix()` uses `n` for the level
   arithmetic and the ordered roster for the names; the two are cross-checked by the page tests
   (`count(teamRoster) === n`).

### 14.5 Evaluation algorithm

```
evaluate(userIds):
    if not enabled():  return {off: true}                       # D-09
    if not seeded():   throw TeamHarvestException::unseeded()   # D-04
    result = {paid: 0, baseline: 0, written: 0}
    users = load the member rows for userIds (role, status)
    SORT userIds ASCENDING                                      # lock order (§14.8)
    for u in userIds:
        if users[u].role <> 'member': throw notMember(u)
        if not queued(u): continue
        n = teammatesOf(u); b = baseline_n(u); pos = positionOf(u)
        for L = 1 .. maxLevels():
            t = threshold(L)
            if n < t: break                    # thresholds ascend: no deeper level can be complete
            if t <= b:
                if not has(u, L): writeBaseline(u, L, t, n)     # no wallet, ever
                continue
            if has(u, L): continue             # paid or baselined already
            amt = amount(L)
            if amt is 0: continue              # a zero level writes no row (§12.6 rule 2)
            awardId = writeCredited(u, L, t, n, amt)            # UNIQUE (user_id, level)
            wallet.credit(u, amt, 'team_harvest', awardId, "Team Harvest level L")
            linkLedger(awardId, ledgerId)
            result.paid++; result.amount += amt
    return result
```

Notes:

- **`break`, not `continue`, when `n < t`.** The thresholds are strictly increasing, so an incomplete
  level ends the loop. A `continue` would compute ten comparisons for every member for no reason.
- **Baseline rows are written lazily**, by the first evaluation, not by the seed. The seed only records
  `baseline_n`; the rows are the ledger's business (20D), which keeps 20C free of money semantics.
- **`has()` is the pre-check; the unique key is the guarantee.** The pre-check keeps the wallet from
  being touched in the common replay; the unique key makes a race safe.
- **A zero amount leaves no row**, so raising the ladder later pays the level then (§12.6 rule 2).

### 14.6 Idempotency

| Layer | Mechanism | What it stops |
|---|---|---|
| 1. Award pre-check | `TeamHarvestAward::has($userId, $level)` | the common replay (second evaluation, catch-up after a registration) |
| 2. Unique anchor | `UNIQUE (user_id, level)` | a raced duplicate insert: the loser gets `PDOException` 23000 and the transaction rolls back |
| 3. Wallet anchor | `UNIQUE (user_id, ref_type, ref_id, type)` | a replayed credit for the same award id |

A fourth, structural guarantee exists: **`teammates` is monotone**, so a second evaluation of the same
`n` has nothing new to award. Together the four make the engine safe to run at any time, in any order,
any number of times — which is what makes the state-based design (D-19) usable.

### 14.7 Transaction boundary and failure policy

| Caller | Transaction | On failure |
|---|---|---|
| `RegistrationService::register()` | the registration's own transaction, which now includes the qualification and the award | the registration rolls back: no user, no queue row, no award, no credit. The form shows a retryable error |
| `bin/team_harvest_catchup.php` | one transaction per batch (default 500 members) | the batch rolls back; the job reports the failure and exits non-zero; the next run retries the same levels |
| `evaluateAll()` in the suite | one transaction per call | as above |

Rules:

1. **The engine never opens its own transaction when called from a registration.** It detects an active
   transaction (`PDO::inTransaction()`) and joins it; otherwise it opens one per batch.
2. **Nothing is swallowed.** A wallet failure, a missing user, a CHECK violation — all propagate. A
   caught-and-logged exception would create a "decided but unpaid" state with no record.
3. **The award is written before the credit**, and the credit's `ref_id` is the award id, so a
   half-committed pair is impossible: the transaction either has both or neither.
4. **A crash between batches is safe** (layer 1 above), and the job's summary says exactly how far it
   got.

### 14.8 Concurrency and lock order

- **Wallet credits lock the member's `users` row** (`WalletService` reads it `FOR UPDATE`). A batch that
  credits two members takes two locks; sorting the recipients ascending by `user_id` means two
  concurrent batches acquire them in the same order and cannot deadlock.
- **The queue rows are updated before any credit** (positions and counters first, then money), so a
  registration holding a queue lock never waits on a wallet lock held by a batch that is waiting on
  the same queue row.
- **A qualification and an evaluation of the same member** are serialised by the queue row's own
  `UPDATE … teammates = teammates + 1` — InnoDB takes the row lock for the duration of the
  registration transaction.
- **The seed takes an exclusive advisory lock** (`GET_LOCK('harvest_queue_seed', 5)`) and runs in one
  transaction, so a registration during a seed waits or fails cleanly rather than appending inside a
  half-built queue.

### 14.9 Performance budget

| Surface | Budget | How |
|---|---|---|
| Registration with a qualification | ≤ 6 extra queries and ≤ 10 ancestor walks | one `INSERT`, one `UPDATE … IN`, one award pre-check per ancestor, one credit per completed level |
| An evaluation batch of 500 members | ≤ 3 queries per member + 1 per award | `teammatesOf`, `baseline_n`, `has()` are pre-loaded for the batch (one `IN (…)` query each) |
| `statusFor()` | ≤ 5 queries | queue row, ladder (cached settings), awards for the member, roster count, next position |
| The member matrix card | ≤ 8 queries for 25 roster rows | one roster page query, one awards query, one ladder read |
| The admin ledger page | ≤ 8 queries for 25 rows | one paginated select, one count, one totals, one level breakdown, one range |
| `recount()` for one member | 1 member query + 1 subtree query | children map built once from a single `SELECT id, sponsor_id` |

The full-network pass (`rebuild()`, `reconcile()`) is O(members) with one query for the forest and one
for the queue — the same idiom as `RoyaltyRank::map()`, which already runs on every report page.

### 14.10 The seed job (`bin/harvest_queue_seed.php`)

**Purpose.** Reconstruct the queue for the tree that existed before the feature, record the baseline,
and switch the live hook on. It is a **one-time, on-demand** job — not a cron entry.

| Flag | Effect |
|---|---|
| `--dry-run` | compute and print everything, write nothing; the default when the queue is non-empty |
| `--by-id` | order by the member's own `id` instead of the third direct's `(created_at, id)` |
| `--force` | required to run on a non-empty queue; appends only the missing members, after the existing ones, and reports the anomaly |
| `--json` | machine-readable summary |
| `--quiet` | only the exit code and the one-line summary |
| `--help` | usage |

**Output (human):** the members that will be queued, the first 20 positions with `username`,
`qualified_at` and `directs_at_qualification`; the count; the baseline instant; the number of levels
already complete at the baseline (the excluded money) and their total; the source of each position
(`seed` or `already queued`).

**Exit codes:** 0 success; 1 refused (already seeded, non-empty without `--force`, no database); 2
unreachable database or missing table.

**Order of operations (one transaction):**

1. `GET_LOCK('harvest_queue_seed', 5)`; refuse if not acquired.
2. Read the gate from the settings. Refuse if it is not 1–10.
3. Load `id, sponsor_id, created_at, role` for every account.
4. Compute `directs` per member (role `member` only), the qualifying direct's timestamp, and the order.
5. If the queue is non-empty and `--force` is absent: refuse, print the count and exit 1.
6. Insert the missing rows in order (one multi-row `INSERT` with `source = 'seed'`).
7. Recount every member's `n`, write `teammates`, and set `baseline_n = teammates` (one `UPDATE` per
   member, or a batched `CASE`/`JOIN`; the implementation chooses, the result must equal a full
   `recount()`).
8. Set `team_harvest_ledger_since = NOW()` (only if it is empty, or `--force`).
9. Write the audit row `harvest.seed` with the counts and the instant.
10. Commit, release the lock, print the summary.

The job is **idempotent**: step 5 refuses a second run, and `--force` appends nothing if nothing is
missing.

### 14.11 The catch-up job and the doctor (`bin/team_harvest_catchup.php`)

| Flag | Effect |
|---|---|
| `--dry-run` | evaluate nothing; report the open liability (levels, members, amount) and the per-level counts |
| `--member=ID` | evaluate one member only |
| `--doctor` | report the stream's health without evaluating: clocks, tables, settings, seed state, baseline, queue counts, counter drift, award totals, reconcile verdict |
| `--json` | machine-readable payload |
| `--limit=N` | the batch size (default 500) |
| `--quiet` | summary only |

The job exists because a state-based engine must be **runnable on demand**: after enabling the stream,
after a repair, after a counter drift alarm, or after a deploy that fixed a bug. It is not a cron entry
and `docs/cron.md` says so (D-08).

### 14.12 Preview and status APIs

`TeamHarvestService::statusFor($userId)` is the single payload every 20E/20F surface renders:

```
{
  queued: bool, position: ?int, qualified_at: ?string, directs_at_qualification: ?int,
  teammates: int, baseline_n: int, seeded: bool, enabled: bool, max_levels: int,
  levels: [ { level, slots, threshold, amount, filled, complete, paid, baseline, status, progress } ],
  next: { level, threshold, needed, filled, remaining, percent, amount } | null,
  paid_total: string, credited_levels: int, baseline_levels: int, baseline_total: string,
  matrix: { positions: int, roster_page: [...] },
  open: string            // what the engine would pay if evaluated now, for this member
}
```

`openLiability()` is the same computation across the network — the number the settings strip and the
admin queue card show, and the number the catch-up dry run prints. It is **derived on read**; it is
never stored, because it changes with every qualification.

### 14.13 Reconciliation

`TeamHarvestService::reconcile()` returns `ok` plus the checks it ran. `bin/wallet_reconcile.php` calls
it and folds the verdict into its exit code.

| Check | Failure means |
|---|---|
| Σ `credited` awards = Σ `team_harvest` ledger credits | a credit was lost, duplicated or mis-typed |
| Every `credited` award has a `ledger_id` that exists | a dangling ledger reference |
| Every `baseline` award has `ledger_id IS NULL` | a baseline row was paid |
| Every `team_harvest` ledger row has an award (`ref_id`) | an orphan credit (a deleted award) |
| No member has two awards for one level | impossible by the unique key; checked anyway |
| `teammates` equals `recount()` for every queued member | counter drift (a lost increment, a manual edit) |
| `baseline_n ≤ teammates` | a deleted descendant or a corrupt repair |
| No award for a level above `max_levels` | a depth change that bypassed the engine |
| The ladder has ten rows, each ≥ 0 | a truncated or corrupted configuration |

A failed check prints the member, level and both numbers. The reconciler never repairs: repair is
`--rebuild --apply` for counters and a forward-only business decision for money (§18 Q3).

### 14.14 Enablement and rollout

The go-live order is **fixed** and documented in one place (`docs/runbook.md` §Team Harvest):

1. Deploy 20A–20D with `team_harvest_enabled = '0'`. The hook is inert (unseeded) and nothing can pay.
2. Run `harvest_queue_seed.php --dry-run`. Read the positions, the baseline count and the excluded
   money.
3. Decide: proceed, or wait. Nothing is written yet.
4. Run `harvest_queue_seed.php` for real. The queue is live from this instant; registrations append.
5. Hold. **Nothing pays yet** — the switch is still off, but the queue is now recording.
6. Set the ladder and the depth. Run `team_harvest_catchup.php --dry-run`. Read the open liability.
7. Turn `team_harvest_enabled` on. From this second, every qualification evaluates and pays.
8. Run `team_harvest_catchup.php` once to settle the backlog created between steps 4 and 7.
9. Run `php bin/wallet_reconcile.php` and `team_harvest_catchup.php --doctor`.

Steps 5–8 are the only window in which money can be earned but not yet credited, and it exists on
purpose: it lets the operator see the liability before accepting it. A quiet first day (no
registrations) makes the backlog zero and the window irrelevant.

### 14.15 There is no monthly close

The stream has no period, no cut-off, no `--evaluate-only`/`--pay-only` split, no resumable payment
batch and no cron entry. The reasons, stated once so nobody adds one later:

1. **There is nothing to aggregate.** A level completes on a registration; the amount is fixed; there
   is no volume to sum for a month and no rate to apply at a cut-off.
2. **There is nothing to wait for.** Royalty is paid after a month because the month is its unit.
   Team Harvest's unit is a level.
3. **A close would be a second place for the same money to be wrong.** The engine's state-based
   evaluation is already idempotent and re-runnable; a monthly close would add a state machine
   (evaluated/paid/resumed) around an award that is already atomic.
4. **The audit trail is better.** Every award carries `credited_at`, `threshold_n` and `n_at_award`, so
   a finance reviewer sees exactly when and on what evidence a level paid — which a monthly header
   would only obscure.

The one thing a close would give — a scheduled moment when everything is definitely settled — is
provided instead by the catch-up job and its doctor, run on demand.

---

## 15. Security, audit and abuse control

### 15.1 Guards and input handling

| Surface | Guard |
|---|---|
| The settings card | admin/superadmin only (the existing `AdminSettingsController` guard); CSRF token on the form; every value re-validated on the server (20B Rules 2–5); the tenant-wide `settings.update` audit plus `team_harvest.ladder.update` with before/after |
| The ledger tab, the queue card, the member panel | the existing `/admin/commissions` and `/admin/members` guards; a member is refused with 403 (20E Rule 16 test) |
| The member tab and its CSV | session-scoped at the **model** (`WHERE user_id = ?`); `?user_id=` is ignored (20F Rule 11) |
| The export endpoints | same guard as the page; the filter set is normalised; the audit row records the filters used |
| The two CLI jobs | CLI only (`PHP_SAPI === 'cli'`); no web entry ships for either, so there is no URL to authenticate; the seed takes a database advisory lock |
| The rebuild's `--apply` | refuses unless a dry run has been executed in the same invocation (`--apply` implies a recount), and audits every repaired row |

No new permission, no new role and no new route is created (D-14). The four settings and the ladder
are the only writable configuration.

### 15.2 Money integrity

The stream's money is protected by four independent mechanisms, and every one has a test:

1. **The award ledger is append-only.** No code path updates or deletes a `team_harvest_awards` row
   (D-12). A correction is a forward-only decision (§18 Q3).
2. **The unique anchor.** `UNIQUE (user_id, level)` makes a second payment for the same level
   impossible at the database level, not just in the engine (§14.6).
3. **The wallet's own anchor.** `UNIQUE (user_id, ref_type, ref_id, type)` makes a second credit for
   the same award impossible even if the award insert were replayed.
4. **The reconciler.** `wallet_reconcile.php` compares the ledger with the wallet and fails the build
   on any disagreement (§14.13). It is part of 20D's gate, not a later add-on.

A manual `UPDATE team_harvest_awards SET amount = …` is detected by the reconciler (the award total no
longer matches the wallet) and by the phase log's rule that the tables are never edited by hand. There
is no admin UI that can edit an award.

### 15.3 Abuse and economic controls

The reference says the gates are "cheap by design" (reference §8.5). This plan keeps them cheap — the
product's shape is the operator's decision — and names the exposure rather than hiding it.

**What a self-dealing ring would have to buy.** To be paid the first level (₱1,000) a ring must place
**13 accounts**: the earner, three members who each qualify (three direct members each), i.e.
`1 + 3 × 4 = 13` at the reference gate. The ring's income is the package referral ladder and this
stream's ₱1,000. At the reference registration price (₱5,000) the ring spends ₱65,000 to earn ₱1,000
plus whatever the referral ladder returns (₱765 of indirect per registration over ten levels, plus the
package's direct ladder). **At reference prices the ring loses money**; at a materially lower
registration price it would not, and that is the operator's variable, not this plan's.

| Exposure | Control |
|---|---|
| Grinding level 1 with fresh accounts | 13 accounts per ₱1,000 at the default gate; the queue-growth report and the "accounts per qualified member" ratio make a burst visible |
| One person operating many accounts | The existing registration/activation requirements (package or code) and the existing duplicate checks; this stream adds no bypass |
| A deep, wide legitimate network paying more than the company funds | The £-denominated ladder is the plan; the settings card states the full-ladder total, the reports show paid ÷ registration revenue, and the stream ships off (D-09) |
| A corrupted tree inflating `n` | The recount is the authority; the reconciler reports drift; a violation of `teammates >= baseline_n` is reported, never repaired silently |
| A reparenting or deletion changing a position | Neither exists in the application; the queue is append-only and the reset clears it (§13.2) |
| An operator lowering the gate to 1 to "test" | The gate is 1–10 and a change is not retroactive; the audit row records who changed it; positions are unaffected |

### 15.4 Audit trail

| Action | Written by | Payload |
|---|---|---|
| `team_harvest.ladder.update` | the settings save (20B) | the ten amounts and the three knobs, before and after |
| `harvest.seed` | the seed job (20C) | positions written, members scanned, baseline instant, excluded levels and amount |
| `harvest.queue.repair` | `--rebuild --apply` (20C) | every repaired member with before/after |
| `team_harvest.catchup` | the catch-up job (20D) | members evaluated, levels paid, amount, dry run or not |
| `team_harvest.export` | both CSV exports (20E/20F) | which tab, which filters |

Not audited, on purpose: **each individual award**. The award row *is* the record — it carries the
member, the level, the threshold, the `n`, the amount, the instant and the wallet reference. Duplicating
that into `audit_log` would double the write volume of the busiest path in this stream for no
information.

### 15.5 Privacy and data minimisation

- The matrix roster shows a teammate's **username and position** only — no email, phone, package, rank
  or amount. The genealogy page already shows usernames to their uplines; this stream adds no new
  disclosure to a wider audience.
- The member statement shows the member's own rows only; the CSV is scoped in the model.
- The admin pages show what an administrator already sees on `/admin/members`.
- The two CLI jobs print usernames in their human output (operator-only surfaces) and ids in `--json`.

---

## 16. Testing harness, fixtures and QA protocol

### 16.1 Harness

The project runs a hand-rolled suite: `php system/tests/run.php` executes every file in
`tests/Cases` and prints `Cases / Passed / Failed`; `tests/bootstrap.php` provides `assert_*`,
`test_pdo()`, `test_reset_app_database()`, `test_run_sql_file()`, `test_run_isolated()`,
`test_user()`, `test_order()`, `test_signed_in_as()` and the other helpers. Each case gets a clean
database (or a transaction that is rolled back). The HTTP layer is exercised through `View::capture()`
and the controllers' public context methods; `tests/smoke.ps1` covers the real URLs.

**Baseline at `p20-start`:** **1,162** cases, **248** smoke checks, `build_install_sql --check` exit
0, `wallet_reconcile.php` exit 0, 33 tables, 73 settings, migration 023, `APP_PHASE` 19.

**Test ID convention.** `P20X-Tn`, where `X` is the phase letter. A phase's cases live in files named
for the phase's subject, and every case name starts with its ID:

| File | Phase | Subject |
|---|---|---|
| `TeamHarvestFoundationTest.php` | 20A | migration, enum, settings, ladder seed, install/reset parity |
| `TeamHarvestConfigurationTest.php` | 20B | settings card, validation, audit, the generated ladder table |
| `HarvestQueueTest.php` | 20C | qualification, positions, ancestors, counters, matrix, seed, rebuild |
| `TeamHarvestEngineTest.php` | 20D | evaluate, baseline, thresholds, depth, idempotency, money |
| `TeamHarvestCatchupTest.php` | 20D | the CLI: dry run, member scope, doctor, exit codes |
| `TeamHarvestReconcileTest.php` | 20D | reconcile, `wallet_reconcile` section, reset integrity pack |
| `TeamHarvestAdminViewTest.php` | 20E | ledger tab, queue card, member panel, filters, CSV |
| `TeamHarvestReportTest.php` | 20E | the four report cards and the `team_harvest` export |
| `TeamHarvestMemberViewTest.php` | 20F | earnings tab, matrix card, tile, genealogy badge, copy |
| `TeamHarvestReleaseTest.php` | 20G | `APP_PHASE`, the documented counts (or the existing `HardeningTest`) |

`php tests/run.php TeamHarvest` selects the whole stream; `php tests/run.php Harvest` selects the
queue cases too; `php tests/run.php` runs everything.

### 16.2 Fixture trees

All money fixtures use the **reference ladder**, `team_harvest_enabled = 1`, and a queue that the
fixture builds itself. A fixture helper (`test_harvest_tree(int $height)`) inserts the accounts with
`test_user()` in BFS order, runs `HarvestQueue::qualifySponsor()` for each account as it is created
(the real hook path), and returns the id map — so the fixture exercises production code, not a
shortcut.

**A. The 40-qualified tree (fixture A)** (`height 3`). One root, three children, nine grandchildren,
27 great-grandchildren — 40 qualified accounts — plus three non-qualifying directs for each of the 27
leaves (`81`). **121 accounts.** The root's `n = 39`; the three children's `n = 12`; the nine
grandchildren's `n = 3`; everybody else `n = 0`. This is the level-1/2/3 fixture.

**B. The 121-qualified tree (fixture B)** (`height 4`). The same shape with one more level — 121
qualified accounts plus 243 leaf directs = **364 accounts**. The root's `n = 120`; the level-4
fixture.

**C. The spine tree (the depth test).** Twelve members in a single sponsor chain; each spine member
holds three directs — two extra leaf accounts plus the next spine member — so every spine member
qualifies. **37 accounts** in total (12 spine + 2 × 11 + 3). A qualification at the bottom increments
**eleven** queued ancestors: one more than `SponsorService::MAX_LEVELS`, so an implementation that
reused the ten-level downline helper would fail the case.

**D. The cycle and self fixtures.** `A.sponsor = B`, `B.sponsor = A`; and `C.sponsor = C`. Both must
terminate, queue nothing that cannot be reached from a root, and be reported by `verify()`.

**E. The staff fixture.** An `admin` account with four member directs; a member whose `sponsor_id` is
that admin. The admin is never queued; the member is a tree root and can qualify on its own directs.

**F. The baseline fixture.** A seeded queue where the root holds `n = 12` at the seed (built by seeding
the tree at exactly 12 qualified descendants and only then inserting the remaining members). Used by
P20D-T14–T16 and `QA20D-08`.

**G. The unseeded fixture.** A database with the ladder configured and `team_harvest_ledger_since = ''`.
Every registration must leave the queue empty (20C Rule 2).

The fixtures never depend on the wall clock. `qualified_at` values are asserted as ranges or set
explicitly with a direct `UPDATE users SET created_at = …` for the seed-order cases.

### 16.3 Money fixture table

Reference ladder, all verified against `earnedFor()` and the tree simulation:

| Fixture | Member | `n` | Levels | Computation | Amount |
|---|---|---:|---|---|---:|
| Reference 7 (A) | member 1 | 5 | 1 | 1,000 | **1,000.00** |
| Reference 7 (A) | members 2, 3, 6 | 2, 2, 1 | — | below 3 | **0.00** |
| Fixture A (40 qualified) | root | 39 | 1–3 | 1,000 + 2,000 + 4,000 | **7,000.00** |
| Fixture A (40 qualified) | each child (×3) | 12 | 1–2 | 1,000 + 2,000 | **3,000.00** |
| Fixture A (40 qualified) | each grandchild (×9) | 3 | 1 | 1,000 | **1,000.00** |
| Fixture A (40 qualified) | network | | | 7,000 + 3×3,000 + 9×1,000 | **25,000.00** |
| Fixture B (121 qualified) | root | 120 | 1–4 | 1,000 + 2,000 + 4,000 + 6,000 | **13,000.00** |
| Fixture B (121 qualified) | each child (×3) | 39 | 1–3 | 7,000 | **7,000.00** |
| Fixture B (121 qualified) | each grandchild (×9) | 12 | 1–2 | 3,000 | **3,000.00** |
| Fixture B (121 qualified) | network | | | 13,000 + 3×7,000 + 9×3,000 + 27×1,000 | **88,000.00** |
| pure | any | 2 | — | below 3 | **0.00** |
| pure | any | 3 | 1 | | **1,000.00** |
| pure | any | 12 | 1–2 | | **3,000.00** |
| pure | any | 39 | 1–3 | | **7,000.00** |
| pure | any | 120 | 1–4 | | **13,000.00** |
| pure | any | 363 | 1–5 | + 12,000 | **25,000.00** |
| pure | any | 1,092 | 1–6 | + 50,000 | **75,000.00** |
| pure | any | 3,279 | 1–7 | + 120,000 | **195,000.00** |
| pure | any | 9,840 | 1–8 | + 200,000 | **395,000.00** |
| pure | any | 29,523 | 1–9 | + 1,200,000 | **1,595,000.00** |
| pure | any | 88,571 | 1–9 | level 10 not complete | **1,595,000.00** |
| pure | any | 88,572 | 1–10 | + 3,500,000 | **5,095,000.00** |
| baseline (F) | root seeded at 12 | 12 | 1–2 | baseline, no money | **0.00 paid** |
| baseline (F) | root at `n = 39` | 39 | 3 | 4,000 | **4,000.00** |
| `max_levels = 2` | root of (A) | 39 | 1–2 | 1,000 + 2,000 | **3,000.00** |

The per-level amounts and the cumulative column are Appendix A.

### 16.4 Existing cases to update, by phase

| Phase | File | Why |
|---|---|---|
| 20A | `MigrationTest.php` | add `024_team_harvest.sql` to the ordered list; a new P20A-T3 case; migrated-vs-fresh stays green; an idempotent re-run and an ENUM assertion |
| 20A | `InstallTest.php` | `tables_created 36`, `settings_rows 77`, `team_harvest_levels_rows 10` |
| 20A | `InstallSqlTest.php` | the header/footer count and any count assertion |
| 20A | `SeedParityTest.php` | no edit expected: it fails unless seed 001 carries all four keys — a useful guard |
| 20A | `DbResetTest.php` | the 33-name helper becomes 36; the zero list gains the two tables; the auto-increment list gains both; the baseline key is `''` after a reset |
| 20A | `DbTargetTest.php` / `SqlScriptTest.php` | the hard-coded table count 33 → 36 |
| 20A | `WalletTest.php` | `REF_TYPES` contains `team_harvest` and the ENUM matches (P5-T8) |
| 20B | `SettingsPageTest.php` | the new fields, toggles and ten amounts; the default ladder; a save round-trip; validation |
| 20B | `AdminSettingsLiveTest.php` | the `settings-commissions` fragment contains the Team Harvest sub-block |
| 20C | `HardeningTest.php` | no change; the registration path's existing cases must stay green with the hook in place |
| 20C | `LaunchTest.php` / registration views | regression only: the registration form behaves identically for a member who does and does not open the gate |
| 20D | `WalletTest.php` | a `team_harvest` credit is accepted and labelled |
| 20D | `AuditLogTest.php` | the new actions are recorded |
| 20D | `DbResetTest.php` | the reset's integrity pack now runs the Team Harvest reconcile |
| 20E | `AdminCommissionLiveTest.php` | the fourth tab, its fragments and its ETag |
| 20E | `AdminMemberLiveTest.php` | the `Harvest` column, the queue card and the member panel |
| 20E | `ReportTest.php` / `ReportViewTest.php` | the four cards and the `team_harvest` export type |
| 20E | `AdminDashboardLiveTest.php` | the Team Harvest KPI |
| 20F | `MemberEarningsLiveTest.php` | the fourth tab, the matrix card and its live revision |
| 20F | `MemberDashboardLiveTest.php` | the Team Harvest tile |
| 20F | `MemberGenealogyLiveTest.php` | the position badge |
| 20G | `HardeningTest.php` | `APP_PHASE === 20` |
| 20G | `tests/smoke.ps1` | the Phase 20 URL checks accumulated by 20B–20F (≈ 15 checks), including the three-member registration that proves the hook |

### 16.5 The pure-function cases for the deep levels

Levels 5–10 need 363 to 88,572 teammates. Building such a tree in the suite would cost hundreds of
thousands of rows and minutes of runtime, so the deep levels are proved on the **pure function** and
the tree walk is proved up to level 4 (fixture B, `n = 120`):

| `n` | Levels paid | `earnedFor(n)` |
|---:|---|---:|
| 362 | 1–4 | 13,000.00 |
| 363 | 1–5 | 25,000.00 |
| 1,091 | 1–5 | 25,000.00 |
| 1,092 | 1–6 | 75,000.00 |
| 3,278 | 1–6 | 75,000.00 |
| 3,279 | 1–7 | 195,000.00 |
| 9,839 | 1–7 | 195,000.00 |
| 9,840 | 1–8 | 395,000.00 |
| 29,522 | 1–8 | 395,000.00 |
| 29,523 | 1–9 | 1,595,000.00 |
| 88,571 | 1–9 | 1,595,000.00 |
| 88,572 | 1–10 | 5,095,000.00 |

These twelve rows are one table-driven case in `TeamHarvestEngineTest`. The `n = 121` tree is the
deepest *structural* proof; the arithmetic above it is not a tree property.

### 16.6 The QA pack

`docs/qa/phase-20-team-harvest.md` is created by 20A with **section A only**, and each later phase
appends its section before its gate. It is never rewritten, only appended. Each section:

- states the phase's prerequisites (the previous tag, the fixtures) and its expected suite count;
- lists every check as `QA20X-nn` with the exact URL or command, the exact expected number, and one
  line on what to do when it fails;
- ends with a sign-off line (tester, date, evidence file names).

**58 checks** in total: A 8, B 9, C 8, D 10, E 8, F 9, G 6 — seven sections, the same shape as the
Phase 17 and Phase 19 packs.
Evidence lives in `docs/qa/evidence/phase-20/`, one file per check (`QA20A-01.png`,
`QA20D-04.txt`, …), indexed by that folder's `README.md`. A check that produces only a count is
captured as a text transcript rather than a screenshot.

`docs/qa/README.md` and `docs/qa/sign-off-sheet.md` gain one Phase 20 row when 20A lands, and the row
is finalised (check count, time needed, signed) by 20G.

### 16.7 Regression policy

The suite is cumulative. After each phase, every case from every earlier phase still runs, and the
phase's gate requires the **whole** suite green — not just its own file. Five regressions matter most
and are asserted by name:

- `P20E-T1` — the package, repeat-purchase and Royalty commission tabs are unchanged.
- `P20F-T9` — the package, repeat-purchase and Royalty earnings tabs are unchanged.
- `P20C-T23` — the registration transaction still commits as one unit with the hook in place.
- `P20A-T15` — no application file outside the manifest names the new tables (until the phase that
  owns it).
- `P20D-T11` — an evaluation replay moves no money.

The referral, repeat-purchase and Royalty ledgers, the wallet statement, the payout path and the
registration form must be unchanged in behaviour; the suite's own counts and the named regression
cases enforce it.

### 16.8 Acceptance matrix

| Scenario | Expected | Test |
|---|---|---|
| Reference 7-member tree | member 1 ₱1,000 | `P20D-T1` / Appendix B.1 |
| Fixture A, root | ₱7,000 | `P20D-T2` |
| Fixture A, network | ₱25,000 | `P20D-T7` |
| Fixture B, root | ₱13,000 | `P20D-T6` |
| Partial block (`n = 2`) | ₱0 | `P20D-T5` |
| Level 10 threshold | ₱5,095,000 at 88,572 | `P20D-T8` |
| One below the threshold | ₱1,595,000 at 88,571 | `P20D-T8` |
| Baseline | recorded, never paid | `P20D-T14–T16` |
| Stream off | queue grows, no money | `P20D-T19` |
| Replay | no double pay | `P20D-T11–T13` |
| `max_levels = 2` | ₱3,000, level 3 unwritten | `P20D-T22` |
| Ladder edit after an award | history unchanged | `P20D-T24` |
| Counter drift | reported, repairable | `P20C-T20`, `P20D-T29` |
| Reconcile | clean on a clean build | `P20D-T27/T30` |
| Reset | zero rows, ladder kept, baseline cleared | `P20A-T8`, `P20D-T31` |
| Install parity | 36 tables / 77 settings / 10 levels | `P20A-T6` |

---

## 17. Risks and mitigations

| # | Risk | Likelihood | Impact | Mitigation | Detected by |
|---:|---|---|---|---|---|
| 1 | **The registration hook breaks sign-ups** — the application's most critical path | low | high | One line inside an existing transaction; the hook is inert until the seed; the phase's first tests and a smoke check register three members; a bug rolls the registration back rather than half-writing it | 20C gate, smoke, `P20C-T23` |
| 2 | **The queue pays more than the company funded** — the ladder is fixed, the network is not | medium | high | The stream ships off; the settings card states the full-ladder total; the reports compare paid money with registration revenue; the perfect-network ceiling is published (§12.8 F) | 20B card, 20E reports, weekly runbook |
| 3 | A ring grinds level 1 with fresh accounts | medium | medium | 13 accounts per ₱1,000 at the default gate; registration has a price; the queue-growth and accounts-per-qualified reports expose a burst (§15.3) | 20E reports |
| 4 | **A historical position is disputed** ("I qualified before them") | medium | medium | The reconstruction rule is deterministic and printed by the dry run; `qualified_at` is the third direct's timestamp; the policy is in §18 Q7 | 20C dry run, `QA20C-02` |
| 5 | The seed runs twice, or concurrently with registrations | low | high | The advisory lock, the "already seeded" refusal, `--force`'s append-only contract, and `P20C-T13/T14` | 20C gate |
| 6 | A counter drifts and under- or over-pays | medium | high | `recount()` is the authority, `rebuild()` reports and repairs, the schema CHECK bounds the impossible, and the reconciler fails the build on drift | `P20C-T19/T20`, `P20D-T29` |
| 7 | A pre-go-live level pays | low | high | The rule is expressed twice (`threshold > baseline_n` and the `baseline` row), and three cases pin it | `P20D-T14–T16` |
| 8 | A ladder fat-finger commits millions | medium | high | Per-level and total ceilings, the full-ladder sentence on the card, the audit row, and the fact that a level's amount is snapshotted | 20B gate, `P20B-T4/T5` |
| 9 | The engine pays twice across a replay, a catch-up or a re-enable | low | high | Three idempotency layers, the monotone counter, and four cases | `P20D-T11–T13/T20` |
| 10 | A deadlock between two registrations that share ancestors | low | medium | Ascending recipient order; queue writes before wallet writes; one transaction per evaluation | `P20D-T34` |
| 11 | A wallet credit fails and the award is left alone | low | high | The award and the credit are one transaction; nothing is swallowed; a failed batch rolls back and retries | `P20D-T27`, the catch-up's exit code |
| 12 | The reset leaves a stale baseline or stale queue rows | medium | medium | Rule 8 of 20A, the CHECK, and `P20A-T8` | 20A gate |
| 13 | An operator enables the stream and the backlog surprises them | medium | medium | The status strip, `openLiability()`, the catch-up dry run, and the nine-step go-live order | 20B card, `QA20D-07/08` |
| 14 | A member misreads the matrix as a guarantee | medium | low | The copy rules, the `0 of N` labels, the "all or nothing" sentence, and the word search | 20F gate, `P20F-T12` |
| 15 | A member disputes a missing payment for a pre-go-live level | medium | low | The *Before go-live* label and the footnote; §18 Q1 records the business decision | 20F gate |
| 16 | The admin pages disagree with the member page | low | medium | Both read `statusFor()` and the award model; the 20E ledger is the proof 20F must match | `P20E-T3/T13`, `P20F-T20` |
| 17 | The full-network pass is slow on a large tree | low | medium | One forest query + one queue query, the same idiom as `RoyaltyRank::map()`; a 50k-member budget in §14.9; the pages are live-cached | 20G acceptance |
| 18 | A phase is declared done on partial evidence | medium | high | The universal gate (§2.4) requires the full suite, smoke, a tester who did not write the code, and a filled Appendix H row | every gate |
| 19 | A stale count in the docs (tables/settings/cases/smoke) | medium | low | `P20G-T2`, the cold read, and the phase-log counts | 20G |
| 20 | Someone "fixes" a position by renumbering the queue | low | high | Rule 1, §2.5, the append-only rebuild contract, and the runbook's explicit prohibition | review of every diff touching `harvest_queue` |

---

## 18. Open questions

Each question has an owner decision, not a plan change. A decision is recorded in `docs/phase-log.md`
and, if it changes a rule, the plan is amended before the code.

| # | Question | Why it is open | Default if nobody decides |
|---:|---|---|---|
| Q1 | **Should the pre-go-live levels be paid?** The seed's dry run quantifies them; D-07 pays none | It is a cash decision the company makes, not a mechanic; paying it would be a one-time run with its own budget | Not paid. The seed records the amount and the report keeps showing it |
| Q2 | **Should suspended members' directs count toward the gate?** D-02 says yes | Excluding them would make qualification reversible, which append-only forbids; the business may prefer the reversibility | Counted. Status affects withdrawal, not structure |
| Q3 | **What is the correction path for a wrong award?** D-12 ships none | A void/reversal tool is a money-editing surface with no event to justify it; it needs its own plan | No correction in v1; a wrong award is a forward-only business decision recorded in the phase log |
| Q4 | **Should the plan have per-rank multipliers** (a Supervisor earning more per level)? | The reference has none; the app now has a rank ladder, so the temptation exists | No. One ladder for everyone (D-18) |
| Q5 | **Is there a network-level cap or budget?** | The stream has no cap, and the perfect-network ceiling is ₱95M | No cap. If one is wanted, it is a new decision with a new ledger field |
| Q6 | **When does General Harvest ship?** | It shares the queue and the runner-up stream; the business has not scheduled it | Unscheduled. The queue is ready and frozen (D-16) |
| Q7 | **Is `qualified_at` shown to members?** | It is a reconstructed timestamp and could invite disputes about seconds | Admin-only in v1; the member sees the position and the date of the seed |
| Q8 | **Does a future bulk member import need the hook?** | No bulk creation exists today | The seed's append path is the model: a bulk import must call `qualifySponsor()` per row inside one transaction |
| Q9 | **Should the ladder be editable by a non-superadmin?** | Today it follows the settings guard; a plan ladder is a company-wide commitment | Superadmin-only, like every other money setting |
| Q10 | **Should the award be visible in the payout screen as a separate source?** | It is a wallet balance like any other; the payout path does not know sources | No. The wallet statement is the record |

---

# Part IV — Appendices

## Appendix A — The reference ladder and the default preset

The ten amounts seeded by 20A and shown on the settings card. The thresholds and slot counts are
**structural** and are never configurable (D-18).

| Level | Slots | Teammates needed | Amount | Cumulative | Paired with |
|---:|---:|---:|---:|---:|---|
| 1 | 3 | 3 | ₱1,000.00 | ₱1,000.00 | 3 qualified teammates |
| 2 | 9 | 12 | ₱2,000.00 | ₱3,000.00 | 9 more |
| 3 | 27 | 39 | ₱4,000.00 | ₱7,000.00 | 27 more |
| 4 | 81 | 120 | ₱6,000.00 | ₱13,000.00 | 81 more |
| 5 | 243 | 363 | ₱12,000.00 | ₱25,000.00 | 243 more |
| 6 | 729 | 1,092 | ₱50,000.00 | ₱75,000.00 | 729 more |
| 7 | 2,187 | 3,279 | ₱120,000.00 | ₱195,000.00 | 2,187 more |
| 8 | 6,561 | 9,840 | ₱200,000.00 | ₱395,000.00 | 6,561 more |
| 9 | 19,683 | 29,523 | ₱1,200,000.00 | ₱1,595,000.00 | 19,683 more |
| 10 | 59,049 | 88,572 | ₱3,500,000.00 | ₱5,095,000.00 | 59,049 more |
| **Total** | **88,572** | | **₱5,095,000.00** | | |

**The preset as stored** (`team_harvest_levels`, seeded by 024 and by `seeds/001_settings.sql`):

```
1 =>  1,000.00      6 =>    50,000.00
2 =>  2,000.00      7 =>   120,000.00
3 =>  4,000.00      8 =>   200,000.00
4 =>  6,000.00      9 => 1,200,000.00
5 => 12,000.00     10 => 3,500,000.00
```

**The four settings as stored**

```
team_harvest_enabled          = '0'    (off; the stream must be switched on deliberately)
team_harvest_qualify_directs  = '3'    (the reference gate)
team_harvest_max_levels       = '10'   (every level pays)
team_harvest_ledger_since     = ''     (unseeded until the job runs)
```

**Editing rules.** The ten amounts are editable on the settings card (20B); the thresholds are not.
A save rewrites all ten rows in one transaction and audits the before/after. Changing an amount never
rewrites an award (amounts are snapshotted). The card shows the full-ladder total and the per-member
maximum in the same sentence, because ₱3,500,000 at level 10 is a commitment.

**What the member sees.** The ten levels with their amounts, their slot counts, `filled of slots`, and
one of four states: *In progress*, *Complete — paid*, *Complete — not paid* (beyond the configured
depth), or *Before go-live* (a baseline row). The member never sees a threshold the plan does not use,
and never sees another member's amounts.

---

## Appendix B — Worked scenarios

Every number below is asserted by a case in §16.3 or reproduced from §12.8.

### B.1 The reference's seven-member tree

Tree `1 → 2`, `2 → {4,5}`, `1 → 3`, `3 → 6`, `6 → 7`; qualification order `3, 1, 2, 4, 5, 6, 7`.

| Member | Position | Teammates | `n` | Paid |
|---|---:|---|---:|---:|
| 1 | #2 | 2, 4, 5, 6, 7 | 5 | **₱1,000.00** |
| 2 | #3 | 4, 5 | 2 | ₱0.00 |
| 3 | #1 | 6, 7 | 2 | ₱0.00 |
| 4 | #4 | — | 0 | ₱0.00 |
| 5 | #5 | — | 0 | ₱0.00 |
| 6 | #6 | 7 | 1 | ₱0.00 |
| 7 | #7 | — | 0 | ₱0.00 |

Member 3 qualified first and is therefore invisible to member 1's matrix even though it is in
member 1's subtree. This is the plan's traffic rule, not a defect.

### B.2 Fixture A — the 40-qualified tree

Root + 3 + 9 + 27 qualified accounts, each of the 27 with three non-qualifying directs (81 more).

| Member | `n` | Level 1 | Level 2 | Level 3 | Earned |
|---|---:|---|---|---|---:|
| Root | 39 | 3/3 | 9/9 | 27/27 | **₱7,000.00** |
| Each of the 3 children | 12 | 3/3 | 9/9 | 0/27 | **₱3,000.00** |
| Each of the 9 grandchildren | 3 | 3/3 | 0/9 | 0/27 | **₱1,000.00** |
| Each of the 27 great-grandchildren | 0 | 0/3 | — | — | ₱0.00 |
| **Network** | | | | | **₱25,000.00** |

### B.3 Fixture B — the 121-qualified tree

One more level: 121 qualified accounts, 243 leaf directs (364 accounts in total).

| Member | `n` | Earned |
|---|---:|---:|
| Root | 120 | **₱13,000.00** (levels 1–4) |
| 3 children | 39 | **₱7,000.00** each |
| 9 grandchildren | 12 | **₱3,000.00** each |
| 27 great-grandchildren | 3 | **₱1,000.00** each |
| 81 great-great-grandchildren | 0 | ₱0.00 |
| **Network** | | **₱88,000.00** |

### B.4 A partial block

`n = 119` on fixture B's root: levels 1–3 paid (₱7,000), level 4 filled `80 of 81` and
**unpaid**. The progress bar reads 98.8%; the wallet received nothing for it. At `n = 120` the level
pays ₱6,000.00 in one credit.

### B.5 The baseline

A tree seeded when the root held `n = 12`, grown afterwards to `n = 39`:

| Level | Threshold | At the baseline | After the growth | Paid |
|---:|---:|---|---|---:|
| 1 | 3 | complete | complete | ₱0.00 — `baseline` row of ₱1,000.00 |
| 2 | 12 | complete | complete | ₱0.00 — `baseline` row of ₱2,000.00 |
| 3 | 39 | not complete | complete | **₱4,000.00** |
| 4 | 120 | not complete | not complete | ₱0.00 |

The wallet holds ₱4,000.00, not ₱7,000.00. The member's card marks levels 1–2 *Before go-live*.

### B.6 Stream off, then enabled

`team_harvest_enabled = '0'`: a member qualifies, teammates grow to 12, and **nothing** is written to
the awards or the wallet. Set it to `'1'`: the next registration (or the catch-up job) writes levels
1–2 and credits ₱3,000.00 in one transaction. A second run writes nothing.

### B.7 A replay

`evaluate()` on a member whose levels 1–3 are paid: `paid = 0`, `written = 0`, the wallet unchanged,
the rows unchanged, the return value `replayed`. A duplicate insert of `(user_id, level)` raises
`PDOException` 23000.

### B.8 `max_levels = 2`

Fixture A's root completes levels 1–3; only levels 1–2 are written (₱3,000.00). Level 3 is
*Complete — not paid* on the card. Raising the depth to 3 pays level 3 at the next evaluation, once.

### B.9 A zero amount, then raised

Level 5 configured at ₱0.00: a member reaches `n = 363`, level 5 completes, **no row** is written and
nothing is credited. The card shows *Complete — no amount configured*. Setting level 5 to ₱12,000.00
pays ₱12,000.00 at the next evaluation, once, because the level has no row.

### B.10 The ladder edit after an award

Level 3 paid at ₱4,000.00. The operator raises it to ₱5,000.00. The existing award keeps ₱4,000.00 and
its wallet credit; a member who completes level 3 afterwards receives ₱5,000.00.

### B.11 The earlier qualification exclusion

A downline member who qualified before their sponsor is never in the sponsor's matrix, however deep or
productive. On the reference tree of B.1, member 3 is in member 1's subtree and contributes **nothing**
to member 1's `n`. There is no substitution: member 1's five teammates are `2, 4, 5, 6, 7`.

### B.12 The perfect-network economics

The 265,720-member perfect network of §12.8 F pays **₱95,002,000.00** in total, of which the apex takes
₱5,095,000.00. 59,049 of the 88,573 qualified members earn nothing. The average is ₱357.53 per member
against a ₱5,000 registration — **7.2%** of registration revenue at the reference price. The ₱5,095,000
ceiling is a maximum, never a forecast.

---

## Appendix C — Copy deck

### C.1 Settings card (`/admin/settings` → Commissions → *Team Harvest bonus*)

> **Team Harvest bonus**
> Every qualified member holds a private 3×10 matrix. Level 1 fills with 3 qualified teammates, level
> 2 with 9 more, level 3 with 27 more, and so on; a level pays its amount once, when its block is full.
> This bonus never depends on purchases, rank or the month — only on who qualified, and when.
>
> [ ] **Enable the Team Harvest bonus** — off means no award is written. Qualifications are still
> recorded, so nothing is lost while it is off.
> **Direct members needed to qualify** [3] · **Levels that pay** [10]
>
> | Level | Teammates needed | Amount (₱) | Cumulative |
> |---:|---:|---:|---:|
> | 1 | 3 | 1,000.00 | 1,000.00 |
> | … | | | |
> | 10 | 88,572 | 3,500,000.00 | 5,095,000.00 |
>
> **The full ladder pays ₱5,095,000.00 to one member** who gathers 88,572 qualified teammates. Changing
> an amount affects levels that have not paid yet; every award keeps the amount it was paid at.
> **State:** *Not seeded — no position has been assigned. Run `bin/harvest_queue_seed.php` before
> enabling; nothing can pay until then.* · *Seeded 2026-11-20 09:14 — 1,204 positions, switch off.* ·
> *Seeded, switch on, 3 levels waiting to be evaluated (₱19,000.00).*

### C.2 Admin ledger (`/admin/commissions?ledger=team_harvest`)

> **Team Harvest bonus**
> One row per level a member completed. Rows marked **Before go-live** were already complete when the
> plan started: they are recorded, not paid.
>
> Tiles: *Credited* · *This month* · *Awards* · *Recipients* · *Before go-live* · *Open liability*
>
> Filters: member · level 1–10 · status (Paid / Before go-live) · from · to · search
>
> Columns: Date · Member · Position · Level · Teammates · Threshold · Amount · Status · Wallet

### C.3 Member earnings (`/earnings?ledger=team_harvest`)

> **Team Harvest bonus** — position **#217** · qualified **12 March 2026**
> **39 teammates** · level 4: **0 of 81** slots · **₱6,000.00** when the block is full (120 teammates)
>
> **Your Team matrix.** Level 1: 3 of 3 · complete, paid ₱1,000.00. Level 2: 9 of 9 · complete, paid
> ₱2,000.00. Level 3: 27 of 27 · complete, paid ₱4,000.00. Level 4: 0 of 81 · in progress.
> *Only members who qualify **after** you count. Direct members who are not qualified do not count.*
> *This bonus does not depend on any purchase — yours or your teammates'.*
>
> **Teammates, in qualification order** — position, member, slot, level.
>
> **Your levels** — date, level, teammates, amount, status.

### C.4 Dashboard tile (`/dashboard`)

> **Team Harvest** — ₱7,000.00 earned · position #217 · level 4: 0 of 81 slots · ₱6,000.00
> [View your matrix]

### C.5 Admin CSV columns (`team-harvest-ledger_YYYY-MM-DD.csv`)

`Award ID, Credited at, Member, Position, Level, Teammates (n), Threshold, Amount, Status, Wallet reference`

### C.6 Member CSV columns (`team-harvest-statement_YYYY-MM-DD.csv`)

`Date, Level, Teammates at award, Threshold, Amount, Status`

### C.7 Status labels (stable strings)

| Stored | Admin label | Member label |
|---|---|---|
| `credited` | Paid | Paid |
| `baseline` | Before go-live | Before go-live |
| level complete, above `max_levels` | Complete — beyond the configured depth | Complete — not paying at this depth |
| level complete, amount 0 | Complete — no amount configured | Complete — no amount configured |
| level in progress | In progress | In progress |

### C.8 Empty states

| Surface | When | Copy |
|---|---|---|
| Settings strip | unseeded | *Not seeded. Run `bin/harvest_queue_seed.php` — nothing can pay until the queue exists.* |
| Settings strip | seeded, off | *Seeded and switched off. Positions are still being recorded.* |
| Admin ledger | no awards | *No level has completed yet. A member's first level needs 3 qualified teammates.* |
| Admin queue card | nobody qualified | *Nobody holds the gate yet. The first member to reach 3 direct members takes position #1.* |
| Member tab | not qualified | *You are not qualified yet: **2 of 3** direct members. When your third direct member registers, you enter the queue and your matrix starts filling with members who qualify after you.* |
| Member tab | qualified, 0 teammates | *You are position #5. Your first level needs 3 qualified teammates; you have 0.* |
| Member tab | stream off | *The Team Harvest bonus is not currently paying. Your position and progress are recorded and will count when it is switched on.* |

### C.9 Member FAQ (rendered as a short block on the earnings tab)

> **How do I qualify?** Three members register with you as their sponsor. That is all.
> **Who counts as my teammate?** A member below you, in any generation, who qualified **after** you.
> **Does buying anything help?** No. Nothing in this bonus depends on purchases.
> **When am I paid?** The moment a level fills — the money is in your e-wallet immediately.
> **Why is somebody in my team not in my matrix?** They qualified before you did.
> **Why did my level not pay?** Either the level is not full (see the count), the amount for it is
> zero, or it was already complete before the plan started paying (marked **Before go-live**).

---

## Appendix D — SQL quick reference

```sql
-- The queue, in order, with each member's standing
SELECT q.id AS position, u.username, u.member_status, q.qualified_at,
       q.directs_at_qualification, q.teammates, q.baseline_n
FROM harvest_queue q JOIN users u ON u.id = q.user_id
ORDER BY q.id;

-- One member's standing
SELECT q.id AS position, q.teammates, q.baseline_n, q.qualified_at
FROM harvest_queue q WHERE q.user_id = :id;

-- A member's awards
SELECT level, threshold_n, n_at_award, amount, status, ledger_id, credited_at
FROM team_harvest_awards WHERE user_id = :id ORDER BY level;

-- The network's paid money
SELECT status, COUNT(*) AS rows_count, SUM(amount) AS total
FROM team_harvest_awards GROUP BY status;

-- Money by level
SELECT level, COUNT(*) AS awards, SUM(amount) AS total
FROM team_harvest_awards WHERE status = 'credited' GROUP BY level ORDER BY level;

-- The wallet side of one award
SELECT l.* FROM ewallet_ledger l
WHERE l.ref_type = 'team_harvest' AND l.ref_id = :awardId;

-- Orphan check: a credited award with no wallet row
SELECT a.id, a.user_id, a.level, a.amount
FROM team_harvest_awards a
LEFT JOIN ewallet_ledger l
  ON l.ref_type = 'team_harvest' AND l.ref_id = a.id AND l.type = 'credit'
WHERE a.status = 'credited' AND l.id IS NULL;

-- Orphan check: a wallet row with no award
SELECT l.id, l.user_id, l.amount, l.ref_id
FROM ewallet_ledger l
LEFT JOIN team_harvest_awards a ON a.id = l.ref_id
WHERE l.ref_type = 'team_harvest' AND a.id IS NULL;

-- Where the queue stands
SELECT COUNT(*) AS queued, MAX(id) AS next_position,
       SUM(teammates) AS teammate_links,
       SUM(teammates >= 3) AS level1_ready
FROM harvest_queue;

-- The ladder
SELECT level, amount FROM team_harvest_levels ORDER BY level;

-- The settings
SELECT key_name, value FROM settings WHERE key_name LIKE 'team_harvest%' ORDER BY key_name;
```

---

## Appendix E — Glossary

| Term | Meaning in this document |
|---|---|
| **award** | One `team_harvest_awards` row: one member, one level, one amount, `credited` or `baseline` |
| **baseline** | The instant the queue was seeded, and per member `baseline_n`, the `n` they held then |
| **baseline row** | An award row for a level already complete at the baseline: recorded, never paid |
| **complete** | A level whose block is full: `n ≥ (3^(L+1) − 3)/2` |
| **counterparty / teammate** | A qualified member below the member in the sponsor tree whose position is greater |
| **gate** | The direct-member count that qualifies a member (`team_harvest_qualify_directs`, default 3) |
| **go-live** | The sequence deploy → seed → enable (§14.14) |
| **Harvest queue** | The append-only ordered list of qualified members; `harvest_queue` |
| **level** | One of the ten rows of the matrix ladder; level `L` has `3^L` slots |
| **matrix** | A member's private 3×10 board, filled by teammates in position order |
| **max depth** | `team_harvest_max_levels`: the deepest level that pays |
| **`n` / teammates** | The number of a member's teammates, stored as `harvest_queue.teammates` |
| **open liability** | What the engine would pay if every outstanding level were evaluated now |
| **position** | A member's place in the queue, 1-based, equal to `harvest_queue.id` |
| **preset** | The reference ladder of Appendix A |
| **recount** | The authoritative subtree count that verifies a counter |
| **seed** | The one-time reconstruction of the queue for the pre-existing tree |
| **slot** | One place in a matrix, 0-based (`r`); the level of `r` is `floor(log3(2r + 3))` |
| **staircase** | The thresholds 3, 12, 39, 120 … — the cumulative slot counts |
| **stream** | The Team Harvest bonus as a whole |

---

## Appendix F — Formula card

```
QUALIFICATION
  directs(i)        = |{ u : u.role='member' ∧ u.sponsor_id=i }|
  qualified(i)      ⇔ directs(i) ≥ team_harvest_qualify_directs        (default 3)
  position(i)       = harvest_queue.id                                (1-based, append-only)
  seq(i)            = position(i) − 1

TEAMMATES
  teammate(d,i)     ⇔ i is a proper descendant of d ∧ i queued ∧ seq(i) > seq(d)
  n(d)              = |{ teammates }|            (stored; recount() is the authority)

MATRIX
  slots(L)          = 3^L
  start(L)          = (3^L − 3)/2                  0, 3, 12, 39, 120, …
  level(r)          = floor(log3(2r + 3))
  placed_under(r)   = r < 3 ? "you" : teammate[floor(r/3) − 1]
  filled(n,L)       = clamp(n − start(L), 0, 3^L)
  complete(n,L)     ⇔ n ≥ (3^(L+1) − 3)/2
  progress(n,L)     = round(100 · filled(n,L) / 3^L, 1)

AWARD
  for L = 1 … min(10, max_levels):
      skip if n < threshold(L)
      baseline row if threshold(L) ≤ baseline_n
      skip if an award exists for (member, L)
      skip if amount(L) = 0
      otherwise credit amount(L), once

  earnedFor(n)      = Σ_{L : n ≥ threshold(L)} amount(L)
  full ladder       = ₱5,095,000 at n = 88,572

BASELINE
  baseline_n(d)     = n(d) at the seed instant; never changes
  payable(d,L)      ⇔ complete ∧ threshold(L) > baseline_n(d) ∧ no award row

MONEY
  award.amount      = the configured amount at that instant (snapshot)
  credit            = withdrawable wallet, ref_type 'team_harvest', ref_id = award.id
```

---

## Appendix G — Traceability to the reference document

### G.1 `docs/comp-plan_addons.md` §5 → this plan

| Reference | Rule | This plan |
|---|---|---|
| §5.1 | A private 3×10 matrix per qualified member, filled by qualified downline who qualified after | §12.4, §12.5, D-17 |
| §5.1 | `n` computed by walking every qualified member's sponsor chain and incrementing queued ancestors | §14.3, §14.4 |
| §5.2 | `filled = clamp(n − (3^L − 3)/2, 0, 3^L)`; all-or-nothing | §12.5, §12.7 |
| §5.3 | The ladder ₱1,000 … ₱3,500,000 | §12.6, Appendix A |
| §5.4 | Earlier-qualified downline excluded; unqualified excluded; outside the subtree excluded; purchases/rank/Active irrelevant | §12.4, §12.11 |
| §5.5 | Qualification is mandatory: an unqualified member earns nothing | §12.2 |
| §5.6 | The worked example | §12.4, Appendix B.1 |
| §3 | Qualification: ≥ 3 direct sponsees, checked at registration, append-only queue | §12.2, §12.3 |
| §2.4, §2.5 | The month clock and derived-vs-ledger money | Ignored for the mechanics (D-08); the derived side is kept for the view, the ledger added for the money (D-06) |
| §9.1 | `BONUS_T` | Appendix A |
| §9.2 | The per-member ceiling ₱5,095,000 | §12.6, §12.8 |
| §11.1 | Harvest edge cases (irreversibility, all-or-nothing, derived credits) | §12.3, §12.7, §12.12 |
| Appendix B | The formula card | Appendix F |

### G.2 What this plan reuses from the existing implementation

| Asset | Where it came from | How it is reused |
|---|---|---|
| The sponsor-tree pass (load once, children map, iterative walk, cycle guard) | [`RoyaltyRank::map()`](../system/app/Models/RoyaltyRank.php#L316-L407) | `HarvestQueue::recount()` and the seed's tree pass |
| The wallet credit + ref type + idempotency anchor | [`WalletService`](../system/app/Services/WalletService.php) | one `credit()` per award, `ref_type = 'team_harvest'` |
| The ledger shape (statuses, snapshots, CSV, filters) | [`RoyaltyAward`](../system/app/Models/RoyaltyAward.php) / [`UnilevelAward`](../system/app/Models/UnilevelAward.php) | `TeamHarvestAward` |
| The settings card mechanics (fields, toggles, validation, audit) | [`AdminSettingsController`](../system/app/Controllers/AdminSettingsController.php) (the Royalty card) | the Team Harvest sub-block |
| The tab mechanics (query parameter, fragments, ETag, live region) | `AdminCommissionController` / `EarningsController` (three tabs) | the fourth tab on each |
| The job mechanics (flags, lock, log, exit codes, `--doctor`) | `bin/royalty_month_close.php` | `bin/team_harvest_catchup.php` (without the scheduling) |
| The reset and integrity-pack wiring | `DatabaseResetService` | the two transactional tables and the reconcile hook |

### G.3 Older documents deliberately not followed

| Document | Proposal | Why it is not this build |
|---|---|---|
| [`dmp/workflow.md`](workflow.md) | A "QA / retail-customer" gate before earning, "balanced" leg requirements | Not in the reference specification for this stream; they belong to a different plan generation and are not the source of truth |
| [`dmp/ecowealth2.md`](ecowealth2.md), [`dmp/ecowealth3.md`](ecowealth3.md) | An extra Chairman rank, matrix "spillover" language | Rank is Royalty's concern (Phase 19); spillover is explicitly not this plan (D-17) |
| [`README.md`](../README.md) | "Final compensation terms must come from verified official material" | Still true: this plan implements the *prototype's* mechanics, and the amounts remain the operator's configuration |

---

## Appendix H — Phase gate record

Fill one row per gate and copy the block into `docs/phase-log.md` with the phase's entry. A phase
without a filled row is not closed, whatever its code says.

| Phase | Tag | Date | Suite passed/ran | Smoke passed/ran | Migration/bundle | Manual QA `QA20X-nn` | Tester | Deviations | Signed |
|---|---|---|---:|---:|---|---|---|---|---|
| 20A Foundation | `p20a-foundation` | 2026-11-15 | 1,180 / 1,180 | 248 / 248 | migration `024` applied twice, idempotent; fresh = migrated; bundle `--check` exit 0 (64 statements) | A: 4/8 automated (04–06 need the running app) | — (pending lead) | see §20A deviations in `docs/phase-log.md` | ☐ |
| 20B Configuration | `p20b-configuration` | 2026-11-15 | 1,204 / 1,204 | 251 / 251 | no schema change; bundle `--check` exit 0 (64 statements) | B: 6/9 automated (01–06; 07–09 need a browser) | — (pending lead) | see §20B deviations in `docs/phase-log.md` | ☐ |
| 20C The Harvest queue | `p20c-queue` | 2026-11-15 | 1,234 / 1,234 | 251 / 251 | no schema change; the seed's dry run and rebuild both exit 0; a refused second seed exits 1 | C: 8/8 automated on a scratch schema (01/05's browser session pending the lead) | — (pending lead) | see §20C deviations in `docs/phase-log.md` | ☐ |
| 20D Money engine | `p20d-money` | 2026-11-16 | 1,275 / 1,275 | 251 / 251 | no schema change; `wallet_reconcile` exit 0 with the Team Harvest section; catch-up doctor exit 0 | D: 10/10 automated on a scratch schema (02/03's wallet screenshots pending the lead) | — (pending lead) | see §20D deviations in `docs/phase-log.md` | ☐ |
| 20E Admin visibility | `p20e-admin` | — | — / — | — / — | no schema change; bundle `--check` exit 0 | E: —/8 | — | — | ☐ |
| 20F Member visibility | `p20f-member` | — | — / — | — / — | no schema change; `wallet_reconcile` exit 0 (moves no money) | F: —/9 | — | — | ☐ |
| 20G Release | `p20g-release` | — | — / — | — / — | no schema change; bundle `--check` exit 0; `wallet_reconcile` exit 0; doctor exit 0; **`APP_PHASE = 20`** | G: —/6 | — | — | ☐ |

**Deviation log.** Any difference between this plan and what was built, with the reason and the phase
that owns it. An empty deviation log at the 20G gate means the plan and the build agreed.

| Phase | Deviation | Reason | Approved by |
|---|---|---|---|
| 20B | QA20B-03's example (“level 10 at ₱20,000,000”) is refused by Rule 2's per-level ceiling before the total rule; the check now uses three levels at ₱10,000,000 (₱30,195,000.00) | The two rules are both correct; only the drafted example contradicted Rule 2. §6 QA20B-03 amended. | — (recorded in `docs/phase-log.md`) |
| 20B | Four extra cases, P20B-T21 … T24, beyond the plan's T1–T20 | The floor is ≥ 1,202 cases (1,180 + 22); twenty cases would have left 1,200. T21 pins the ladder audit payload, T22 the card's scope, T23 the history copy, T24 the missing-amount-fields guard. No contract changed. | — (recorded in `docs/phase-log.md`) |
| 20C | Four extra cases, P20C-T27 … T30, beyond the plan's T1–T26 | The floor is ≥ 1,232 cases (1,204 + 28); twenty-six cases would have left 1,230. T27 proves the CLI as a process, T28 the two `directs_at_qualification` paths, T29 the slot arithmetic against `TeamHarvestLevel`, T30 the invalid-gate refusal. No contract changed. | — (recorded in `docs/phase-log.md`) |
| 20C | `seed()` reports the positions the table assigned, not its prediction; the dry run still predicts from `MAX(id)+1` | A position is a fact (D-03). On a fresh table both are 1…N; the test harness's rolled-back inserts advance `AUTO_INCREMENT`, so the cases assert positions relatively and prove order/contiguity, while the CLI case proves `MIN(id) = 1` on a real table. | — (recorded in `docs/phase-log.md`) |
| 20D | Five extra cases, P20D-T35 … T39, beyond the plan's T1–T34 | The floor is ≥ 1,268 (1,234 + 34); thirty-four cases plus the two updated-file cases would have left 1,270. T35 pins the zero-amount switch (§12.6 Rule 2 / B.9), T36 the exit-gate invariants sweep (§13.8), T37–T39 the job as a process (`--help`/exit 2, `--member` scope, a red `--doctor` on drift). No contract changed. | — (recorded in `docs/phase-log.md`) |
| 20D | `earnedFor()` and `statusForNext()` ship in 20D although §14.2 lists them under `TeamHarvestLevel` (20B) | 20B did not need them; 20D's P20D-T8 (the deep-level table) and the card's next-level payload (P20F-T2) pin them. Both are additive pure functions over the existing ladder; no stored contract moved. | — (recorded in `docs/phase-log.md`) |
| 20D | `statusFor()` reports a sixth per-level state, `complete` (complete and payable, but no award row yet — the window between enabling the switch and the catch-up), and gains optional roster parameters | Appendix C.7's five states keep their exact labels; the sixth names a state the plan creates in §14.14 steps 5–8 but does not label. The roster defaults to off so the §14.9 ≤ 5-query budget holds for the default call. | — (recorded in `docs/phase-log.md`) |
| 20D | `evaluate()`/`onQualified()` return extra keys (`off`, `written`, `members`, `skipped`, `levels`, `qualified`, `position`, `replayed`) | §14.2 names `{paid, baseline, skipped, off, amount}`; `replayed` is required by P20D-T12 and the rest are the job's and the tests' read-only needs. Additive; nothing was renamed. | — (recorded in `docs/phase-log.md`) |
| 20D | The catch-up refuses an **unseeded** queue before it looks at the switch, for a dry run too | §14.11 says unseeded is a hard refusal (exit 1); without a baseline a liability cannot be computed, and a dry run that reported one would be a forecast of money the engine will never pay. A run on a seeded-but-off queue still exits 0 and reports `off`. | — (recorded in `docs/phase-log.md`) |
| 20D | `openLiability()` also returns `by_level` | §14.11's `--dry-run` prints "the per-level counts" and §14.12 only names `{levels, members, amount}`. Additive, and the same read feeds 20E's queue card. | — (recorded in `docs/phase-log.md`) |

**Acceptance sign-off.**

| Item | Value |
|---|---|
| Reference settings used | gate 3 · depth 10 · the Appendix A ladder for all unit cases; the same ladder for the QA evidence |
| Fixtures | Fixture A (40 qualified / 121 accounts), fixture B (121 / 364), the reference 7-member tree, the baseline tree, the unseeded tree |
| Reference example B.1 observed | **1,000.00** for member 1 on the seven-member tree |
| B.2 / B.3 / B.12 observed | **25,000.00** / **88,000.00** / **95,002,000.00** |
| Level-10 threshold | **5,095,000.00** at `n = 88,572` (`earnedFor`) |
| Baseline | recorded, never credited; the wallet holds only the post-baseline levels |
| `wallet_reconcile.php` | exit **0**, Team Harvest section clean |
| `team_harvest_catchup.php --doctor` | exit **0**: clocks, tables, settings, seed state, baseline, queue, drift, reconcile |
| Release gate (20G) | suite **1,318 / 1,318**, smoke **≈ 263 / 263**, bundle `--check` exit 0, `APP_PHASE = 20` |
| Tester / date | — |
| Evidence folder | `docs/qa/evidence/phase-20/` (sections A–G) |

---

*End of plan.*
| 20D | The reconciler reports an award above the *configured depth* as a **warning** (`above_depth`), not a mismatch; only an award above the ten-level ladder is a mismatch | §14.13 lists "no award for a level above max_levels" as a failure, but a row paid while a deeper ladder was configured stays a fact after an operator lowers the depth (the depth caps what may be written, never what was written). P20D-T23 pins the warning. | — (recorded in `docs/phase-log.md`) |
