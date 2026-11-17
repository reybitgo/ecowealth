# Plan — General Harvest Bonus (the shared 3-ary Harvest board)

**Phase 21** of the EcoWealth member system · **Status: in progress** — 21A (Foundation) landed
2026-11-16, 21B (Configuration and the board arithmetic) landed 2026-11-16, 21C (The baseline) landed
2026-11-16; 21D–21G planned.
**Delivery model:** **seven gated phases, 21A → 21G.** Each phase is self-contained, shippable and
testable on its own; each builds on the one before it.
**Date:** 15 November 2026 · **Target:** `http://localhost/ecowealth/system/`
**Branch:** `feature/general-harvest`, cut from `feature/team-harvest` at `p20g-release` · **Tag at
start:** `p21-start`

| | |
|---|---|
| **Reference specification** | [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §4 (General Harvest Bonus), with §2.1 (the sponsor tree), §2.5 (derived vs. ledger money), §3 (Harvest qualification and the append-only queue), §4.2 (the fill rule), §4.3 (the ladder), §4.4–4.5 (the thresholds), §4.6 (the whole-board credit), §4.7 (the 20-member example), §4.9 (UI presentation), §9.1 (`BONUS_G`), §9.2 (the per-member ceiling), §11.1 (Harvest edge cases) and Appendix B (formula card) |
| **Related plans** | [`dmp/plan-ecowealth-team-harvest.md`](plan-ecowealth-team-harvest.md) (Phase 20 — the **shared queue** this stream consumes, D-16, and the sibling this plan's phase structure and conventions mirror), [`dmp/plan-ecowealth-royalty.md`](plan-ecowealth-royalty.md) (the monthly stream whose ledger and settings conventions are reused), [`dmp/plan-ecowealth-unilevel-repeat-purchase.md`](plan-ecowealth-unilevel-repeat-purchase.md) (the wallet-credit conventions), [`dmp/plan-ecowealth-install-reset.md`](plan-ecowealth-install-reset.md) (install/reset parity), [`dmp/plan-ecowealth-cart-system.md`](plan-ecowealth-cart-system.md) (the 15 phases this one extends) |
| **Depends on** | **Phase 20 released** (`p20g-release`): `harvest_queue`, its seed and its append-only position rule. This plan adds **no queue, no gate and no position concept** (D-01) |
| **Baseline at `p21-start`** | At `p20g-release` (the Phase 20 release gate): **1,318 / 1,318** PHP cases · **≈ 263 / 263** smoke checks · `build_install_sql --check` exit 0 · `wallet_reconcile.php` exit 0 · **36 tables · 77 settings** · migrations **001–024** · `APP_PHASE` **20**. *Today (15 Nov 2026) Phase 20 is still planned: the live copy is 1,162 cases, 248 smoke checks, 33 tables, 73 settings, migration 023, `APP_PHASE` 19. §2.6 says what to do if the released Phase 20 counts differ.* |
| **Ships** | 3 new tables, 1 extended ENUM, 2 settings, 10 ladder rows, 1 board row, a **global baseline**, a prefix settlement engine, admin and member visibility, reports, reconciliation and a one-time arm job |
| **Does not ship** | no new queue, no new route, no monthly close, no period table, no purchase input, no rank input, no cap, no reversal path, no retroactive go-live money, no configurable thresholds, no per-rank multipliers, no Team Harvest change |

**Revision note.** This is the first plan for the General Harvest Bonus. It does **not** replace
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md); it implements §4 of it against the queue
Phase 20 built, and records every place the app must adapt the simulator's mechanics to a stored,
auditable, real-money system. Two adaptations are stated up front:

1. **The simulator derives the whole board on every render and never stores a payment** (reference
   §2.5). This build stores one ledger row per `(node, level)` with a wallet credit, because a bonus
   that cannot be audited cannot be paid.
2. **The money event is a global count, not a member's own subtree.** Team Harvest is paid when *your*
   `n` grows; General Harvest is paid when **`N`, the number of qualified members in the whole
   network**, reaches a block's last position. A single registration can therefore pay members
   anywhere on the board, including members who are not in the newcomer's upline and not in the
   earner's downline (reference §4.1, §11.1 #1). This is the first stream in this application whose
   trigger is a network-wide scalar, and every phase below is shaped by that fact.

§12.11 lists every deliberate divergence from the simulator.

---

## Contents

**Part I — Orientation**

1. [Executive summary](#1-executive-summary)
2. [The phase protocol](#2-the-phase-protocol)
3. [Current state and the gap](#3-current-state-and-the-gap)
4. [Decisions](#4-decisions)

**Part II — The phases** (each one is a complete work package)

5. [Phase 21A — Foundation](#5-phase-21a--foundation)
6. [Phase 21B — Configuration and the board arithmetic](#6-phase-21b--configuration-and-the-board-arithmetic)
7. [Phase 21C — The baseline](#7-phase-21c--the-baseline)
8. [Phase 21D — Money engine](#8-phase-21d--money-engine)
9. [Phase 21E — Admin visibility](#9-phase-21e--admin-visibility)
10. [Phase 21F — Member visibility](#10-phase-21f--member-visibility)
11. [Phase 21G — Release](#11-phase-21g--release)

**Part III — The shared contract** (read once; every phase cites it)

12. [Specification (normative)](#12-specification-normative)
13. [Data model](#13-data-model)
14. [The money path](#14-the-money-path)
15. [Security, audit and abuse control](#15-security-audit-and-abuse-control)
16. [Testing harness, fixtures and QA protocol](#16-testing-harness-fixtures-and-qa-protocol)
17. [Risks and mitigations](#17-risks-and-mitigations)
18. [Open questions](#18-open-questions)

**Part IV — Appendices**

- [Appendix A — The board ladder and the default preset](#appendix-a--the-board-ladder-and-the-default-preset)
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

A **shared board plus a per-level award**, exactly the mechanics of
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §4, implemented over the Harvest queue that
Phase 20 built:

- **One board for the whole network.** Every Harvest-qualified member holds exactly one **node**. The
  node index is the member's **queue position minus one** (`seq = harvest_queue.id − 1`), so node 0 is
  position #1, the first member ever to qualify. The board is a 3-ary heap over that order: the
  children of node `q` are nodes `3q+1`, `3q+2`, `3q+3`, and a node's level-`L` block is the
  `3^L` consecutive positions `start+1 … start+3^L`, where `start = 3^L·q + (3^L − 1)/2` (0-based).
- **A block fills with qualified members, not with a team.** Level 1 of node 0 is positions #2–#4,
  level 2 is #5–#13, level 3 is #14–#40, and so on. A slot can be filled by **any** qualified member
  in the network — direct, indirect, or a complete stranger's downline (reference §4.1 and §11.1 #1).
  The board is global by design.
- **Levels are all-or-nothing.** Level `L` completes when the **queue length `N`** reaches
  `3^L·q + (3^(L+1) − 1)/2`. For the apex that is 4, 13, 40, 121, 364, 1,093, 3,280, 9,841, 29,524 and
  88,573. A block one slot short pays nothing, and a completed level pays **once, ever**.
- **The reference ladder**: ₱2,000 / ₱4,000 / ₱8,000 / ₱12,000 / ₱25,000 / ₱100,000 / ₱250,000 /
  ₱480,000 / ₱2,500,000 / ₱5,000,000; full ladder **₱8,381,000** per node.
- **Real money, in the e-wallet.** Each completed level is a **withdrawable wallet credit** with a
  ledger row (`ref_type = 'general_harvest'`) and an immutable award row. There is **no monthly close**
  — the stream has no month, no period and no purchase input (D-08).
- **A global baseline, decided once.** Arming the board records `N₀`, the queue length at go-live, and
  writes a `baseline` row for every block already full at that instant. Those rows record their
  amounts and are **never credited** (D-06). The dry run prints exactly what that is worth before
  anyone decides.
- **One switch.** `general_harvest_enabled` ships **off**. The queue keeps growing and arming is a
  separate, deliberate act; no award is written while the switch is off (D-10).
- **A board whose entire liability is a closed-form function of `N`.** The total this stream owes at
  queue size `N` is
  `Σ_L amount(L) × floor((N − (3^L − 1)/2) / 3^L)` — ₱2,000 at `N = 4`, ₱176,000 at `N = 121`,
  ₱190,208,000 at `N = 88,573`. Every report, every reconcile check and every test compares an
  observed ledger with that number (D-21). No other stream in this application can state its entire
  liability without reading a row.
- **Visibility on existing pages only** — no new routes:
  `/admin/settings` (the ladder card), `/admin/commissions?ledger=general_harvest` (the ledger),
  `/admin/members` (`?board=paid|none`, a `GH` column, the board card), `/admin/members/{id}` (the
  member's board panel), `/admin/reports` (the board cards and CSV), `/earnings?ledger=general_harvest`
  (the member's block and statement), `/genealogy` (a badge), `/dashboard` (a tile).
- **Reconciliation, reset parity and audit**, in the shape the last three streams established:
  `wallet_reconcile.php` gains a General Harvest section, the business reset empties the ledger and
  returns the board to unarmed while keeping the ladder, the arm and every ladder change are audited,
  and the catch-up job's doctor reports the board's health without paying anything.

### 1.2 The rules in one page

```
PHASE 20 (already shipped) — the queue
  qualification   ⇔ ≥ 3 direct member accounts, checked at registration (configurable 1–10)
  position        = harvest_queue.id, 1-based, assigned once, never renumbered
  seq             = position − 1                    (0-based board index)
  N               = COUNT(*)                        (gapless: N = MAX(id))

PHASE 21 — the board
  slots(L)        = 3^L                             3, 9, 27, … 59,049
  start(seq,L)    = 3^L·seq + (3^L − 1)/2            0-based first slot of the block
  block(seq,L)    = positions [start+1 … start+3^L]  the queue positions that fill it
  complete(seq,L) ⇔ N ≥ 3^L·seq + (3^(L+1) − 1)/2    the block's last position has been reached

ARM (once, at go-live, before the switch)
  N₀              = N at that instant
  for L = 1 … 10: write a 'baseline' row for every node 0 … qFor(L, N₀)   (amount recorded, no money)
  board.baseline_n = N₀ ; board.settled_n = N₀ ; board.armed_at = now

SETTLE (after any queue append, and by bin/general_harvest_catchup.php)
  target          = min(N, settled_n + maxNewPositions)      (1 inline, 500 per job transaction)
  for L = 1 … 10:
      Q           = qFor(L, target)                          the highest complete node, −1 if none
                    = floor((target − (3^L − 1)/2) / 3^L) − 1
      have        = COUNT(rows at level L)                   rows are exactly 0 … have−1
      for q = have … Q:
          node    = harvest_queue row id q+1
          if q ≤ qFor(L, baseline_n): write 'baseline'  → no money, ever
          elif L > max_levels:        skip              → written when the depth is raised
          elif amount(L) = 0:         skip              → reported, never paid
          else:                       write 'credited' + withdrawable wallet credit
  board.settled_n = target

MONEY
  award.amount    = the configured amount at that instant (snapshot)
  credit          = withdrawable e-wallet, ref_type 'general_harvest', ref_id = award.id
  once ever       = UNIQUE (seq, level)   (the node, the level — not the member)
```

**There is no month.** General Harvest has no period, no quota, no Active flag and no cron entry. Its
only inputs are the **queue order** and the **queue length**; the only thing an operator schedules is
the one-time arm.

### 1.3 What is deliberately not built

- **No new queue, no new gate, no new position rule.** Phase 20 owns qualification (D-01, D-16 of that
  plan). This plan reads `harvest_queue` and writes nothing to it.
- **No purchase, order or product input.** Not one line of this stream reads `shop_orders`,
  `products`, `commissions`, `unilevel_awards`, `royalty_awards` or `team_harvest_awards`. A member who
  never buys anything earns General Harvest exactly like one who buys daily (reference §4.8).
- **No rank, no Active flag, no quota.** Leader/Supervisor/Manager/Director and the Unilevel monthly
  minimum are irrelevant here.
- **No monthly cycle, no period table, no cron job.** There is nothing to aggregate: a level completes
  when the queue reaches a position, and the amount is fixed (D-08).
- **No team-line restriction.** The board pays across team lines on purpose (reference §4.1). A plan
  that only paid a member's own downline would be Team Harvest, not this stream.
- **No spillover, no compression, no placement, no "leg" model.** Nodes are positions. Nothing is
  moved, placed or substituted.
- **No retroactive go-live money.** Blocks full at the baseline are recorded, not paid (D-06). The arm
  job's dry run quantifies them so the business can decide separately (§18 Q1).
- **No reversal path.** There is no cancellation event in this stream; an award is final (§18 Q3).
- **No configurable thresholds.** The 3, 9, 27 … staircase is the board; only the amounts and the
  maximum depth are configurable (D-18).
- **No per-rank multipliers and no position-weighted amounts.** One ladder for every node (D-18).
- **No cap.** The board's cost is a function of `N`, published by the reports; a cap would be a new
  decision (§18 Q5), not a hidden clamp.
- **No new routes.** Everything renders on existing pages through query parameters, so `routes.php`,
  `routes_inventory.php` and `ParityTest` stay untouched (D-15).
- **No tax withholding and no cost-of-goods model.** Awards are gross, like every other bonus.
- **No change to Team Harvest.** Its tables, settings, ledger, pages and jobs are untouched (D-22).

### 1.4 Delivery: seven gated phases

The feature is one coherent stream of money, but it is **not** delivered as one drop. It is split into
seven parent phases, each ending in a gate that must be passed — automated suite plus a manual script —
before the next dependent phase starts. The split follows the lines where a phase can be **proven on its
own**. For AI-assisted implementation, each parent phase is further divided into small, sequential
**execution slices** (§2.7). A slice is a focused implementation task, not an eighth kind of release
gate: the seven parent-phase gates, tags and release contracts remain unchanged.

| Phase | One-line purpose | What the gate proves | Adds money? | Adds UI? |
|---|---|---|---|---|
| **21A** | Add the three tables, the wallet movement, the two settings and the ten ladder rows; teach install, migration and reset about them | A migrated database equals a fresh install, and nothing in the app reads the new objects yet | no | no |
| **21B** | Let an operator edit the ten amounts and the two knobs; render the ladder and the board arithmetic | Configuration saves, validates, audits and renders; the pure board model reproduces the reference's thresholds and totals to the peso; a registration still writes nothing | no | admin |
| **21C** | Record the baseline: the arm job, `baseline_n`, the baseline rows | The excluded money is computed once, deterministically, and can never be paid — and no credited row exists yet | records amounts, moves no money | none |
| **21D** | Pay a block when it fills: the settlement engine, wallet credits, the baseline rule, catch-up, reconcile | Every money rule in the spec: prefix, once-ever, baseline, depth, zero amount, idempotency, closed-form totals | **yes** | none |
| **21E** | Show the money and the board to administrators: ledger tab, board card, member panel, reports, CSV | Finance can audit every award and read the whole board from the UI | no | admin |
| **21F** | Show the block, the progress and the money to members: earnings tab, board card, tile, genealogy | The copy rules hold; the member never reads a promise the engine will not pay | no | member |
| **21G** | Documentation, QA pack, phase badge, acceptance run, sign-off | The release is signed and everything above is reproducible | no | no |

**Why this split:**

- **The database goes first** because every later phase reads it, and because a schema change is the
  only part of this plan that is hard to reverse. Proving install/reset parity in isolation (§5) means
  no later bug can be blamed on the schema.
- **Configuration second**, because it is inert, verifiable by eye, and it is the phase that tells an
  operator what the plan costs before any of it can pay. It is also the phase that proves the
  **arithmetic** — the thresholds, block ranges and board totals — against the reference document's own
  published numbers, before a single row of money exists.
- **The baseline third, and on its own**, because the baseline is this stream's **irreversible**
  decision: it fixes for ever which blocks count as pre-go-live and can never be paid. A phase that
  writes the exclusion and pays nothing is the only safe way to prove that rule before money depends
  on it.
- **Money fourth, as one indivisible release phase.** The engine must be complete and correct before
  the `p21d-money` gate closes or the stream is enabled in production. This does **not** require one AI
  task or one implementation pass: §2.7 divides 21D into sequential execution slices. Those slices may
  stage code on the feature branch, but none may expose, arm, enable or release an incomplete money
  path. A slice may be small; the integrated phase may never be incorrect.
- **Admin before member**, because the ledger is the tool the operator uses to explain and audit the
  money; the member pages are marketing of an already-proven stream.
- **Member pages and the operator pages are siblings** (both depend only on 21D), so they can be built
  in either order or in parallel.
- **Release last** because `APP_PHASE`, the QA pack and the sign-off describe the finished feature —
  they are meaningless before the feature is on.

**Why there is no separate operations phase.** Phases 17F and 19F existed because those streams run a
**scheduled monthly close**. General Harvest has no schedule: its only jobs are the one-time arm (21C)
and an on-demand catch-up/doctor (21D). They ship with the phase that owns their rules, and 21G's
runbook documents the go-live order. A phase whose entire content would be "wrap an existing command in
a cron entry" is not worth a gate.

### 1.5 Scope at a glance

| Area | Deliverable | Phase |
|---|---|---|
| Database | 3 new tables, 1 extended ENUM, 2 settings, 10 ladder rows, 1 board row, migration `025_general_harvest.sql` | 21A |
| Configuration | `GeneralHarvestLevel` model, the settings card with the ten amounts and two knobs, the ladder arithmetic, the pure board model, JS preview | 21B |
| Baseline | `GeneralHarvestBoard` state, `bin/general_harvest_arm.php`, baseline rows, the excluded amount | 21C |
| Money engine | `GeneralHarvestAward`, `GeneralHarvestService` (settle), wallet credits, `bin/general_harvest_catchup.php`, reconcile | 21D |
| Admin | `?ledger=general_harvest` tab, `?board=paid` or `?board=none` filter + `GH` column, board card, member panel, four report cards, CSV | 21E |
| Member | `?ledger=general_harvest` earnings tab, board card, progress card, dashboard tile, genealogy badge, CSV | 21F |
| Quality | 155 new cases across the seven phases, one QA pack (58 checks), documentation | 21A–21G |

---

## 2. The phase protocol

### 2.1 What "self-contained" means in this document

"Self-contained" is a property of **parent-phase delivery**, not of the schema or of the size of an
AI task. The phases share one data model and one specification on purpose — duplicating them seven
times would guarantee drift. Execution slices (§2.7) are smaller implementation checkpoints inside a
parent phase; they do not need separate deployment or abandonment guarantees. A parent phase is
self-contained when all five of the following hold:

1. **It ships alone.** Merging only this phase leaves production correct, the suite green and every
   existing page unchanged in behaviour (except where the phase is explicitly a UI phase).
2. **It tests alone.** Its gate can be executed end-to-end on a database that contains only the phases
   up to and including itself. No later file or table is needed to observe the result.
3. **It explains alone.** Its section below contains everything needed to implement it: scope, files,
   rules, test cases, manual script, exit gate, rollback and hand-off. A developer who has never read
   this plan can start from §2, §12 and one phase section.
4. **It survives abandonment.** If every later phase were cancelled, this phase is still correct and
   still worth having. Nothing in it depends on a promise made by a phase that does not exist yet.
5. **It rolls back alone.** Reverting phase N leaves phases 1…N−1 intact and working.

The named anti-patterns this forbids:

- **The forward stub.** A file, column or switch that exists only so a later phase can fill it in.
  (A `TODO` in a comment is fine; a half-wired hook is not.) 21A ships tables with no writer on
  purpose — that is a foundation, not a stub — but 21D may not ship a `settle()` that returns
  "not implemented".
- **The invisible phase.** A phase whose gate is "the code compiles". Every phase here has an
  observation a tester can make: a page, a count, a log line, a wallet balance or a ledger row.
- **The invalid phase.** A phase that leaves the stream in a state that is *wrong* while it waits for
  the next phase. Money is either fully implemented (21D) or not implemented at all (21A–21C).
- **The shared contract.** A value one phase writes and a later phase changes (for example: shipping
  with the reference amounts in 21B and correcting them in 21F). Settings keys, table names, node
  semantics, the block formula and the baseline rule are frozen at the phase that introduces them.

### 2.2 Phase map

| Phase | Name | Builds on | Effort | Cumulative suite |
|---|---|---|---|---|
| **21A** | Foundation: schema, wallet movement, settings, install parity | — | 1.5 d | 1,318 → ≥ 1,336 |
| **21B** | Configuration and the board arithmetic | 21A | 2.0 d | ≥ 1,362 |
| **21C** | The baseline: arm job, baseline rows, the excluded amount | 21A, 21B | 2.5 d | ≥ 1,384 |
| **21D** | Money engine: settlement, wallet, catch-up, reconcile | 21C | 3.5 d | ≥ 1,430 |
| **21E** | Admin visibility: ledger tab, board card, member panel, reports | 21D | 1.5 d | ≥ 1,454 |
| **21F** | Member visibility: earnings tab, block card, tile, genealogy | 21D | 1.5 d | ≥ 1,478 |
| **21G** | Release: docs, QA pack, phase badge, acceptance | all | 1.5 d | 1,484 |

**Total: ~14.0 ideal days**, plus roughly one day of gate overhead spread across the seven phases. The
suite column is a floor, not a target: it counts the cases the phase's gate requires; the baseline is
the Phase 20 release count of **1,318**.

**Dependency graph**

```
21A ──▶ 21B ──▶ 21C ──▶ 21D ──┬──▶ 21E ──┐
                               └──▶ 21F ──┴──▶ 21G
```

21E and 21F are **siblings**: each depends only on 21D, neither reads the other, and they may be
delivered in any order or concurrently by different developers. The critical path is
21A → 21B → 21C → 21D → (21E or 21F) → 21G ≈ **12 ideal days** of sequential work.

**Branches and tags.** One branch, `feature/general-harvest`, cut from `feature/team-harvest` at
`p20g-release`. One tag per gate: `p21a-foundation`, `p21b-configuration`, `p21c-baseline`,
`p21d-money`, `p21e-admin`, `p21f-member`, `p21g-release`, plus the feature tag `phase-21`. A phase
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
| **Test plan** | `P21X-Tn` cases with exact expectations |
| **Manual gate** | numbered browser/CLI steps and the `QA21X-nn` identifiers in the QA pack |
| **Exit gate** | the checklist that closes the phase |
| **Rollback** | how to undo this phase alone |
| **Hand-off** | what now exists, which the next phase is allowed to assume |
| **Risks** | what can go wrong *in this phase* |

### 2.4 Definition of done — the universal gate

Every phase's exit gate contains these ten items, and its own phase-specific list. Copy the block
into `docs/phase-log.md` when the phase closes, and fill Appendix H.

```
[ ] Schema state matches the plan: MigrationTest green (21A applies migration 025; later phases change no schema)
[ ] `php bin/build_install_sql.php` run and `--check` exits 0
[ ] `php tests/run.php` — full suite green, exit 0, case count at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0 (money untouched or money correct)
[ ] `powershell -File tests/smoke.ps1` — all checks pass, exit 0
[ ] Every P21X-Tn case for this phase exists, is named for the phase, and passes
[ ] The manual script for this phase is executed by someone who did not write it
[ ] No new PHP warning, notice or deprecation in `storage/logs/` after the manual pass
[ ] The QA pack section for this phase is filled, with evidence under `docs/qa/evidence/phase-21/`
[ ] `docs/phase-log.md` records the phase, tag, counts and deviations; Appendix H is filled
```

### 2.5 What a phase may never do

- **Never leave the suite red.** A phase that cannot get to green is not finished; it is not "mostly
  finished with the tests to follow". The suite is the gate.
- **Never edit a later phase's files.** If 21D needs a change in a view, that view belongs to 21E or
  21F and the change waits. The only exceptions are the shared files each phase is assigned in its
  manifest (`RegistrationService` in 21D, for one line).
- **Never invent a contract.** If an implementation must diverge from §12–§14, the plan is amended
  first (a new decision or a corrected table), then the code. A divergence discovered mid-phase is
  recorded as a deviation in the phase log.
- **Never pay money in a structural phase** and never change the structure in a money phase. 21D
  assumes the queue and the baseline are correct; it does not ship a "re-arm the board" convenience.
- **Never move a node.** Not in an arm, not in a repair, not in a test. A position is a fact; a node
  index is that fact minus one.
- **Never add a route.** D-15 is absolute for every phase: query parameters on existing pages only.
- **Never touch Team Harvest's money.** No read of `team_harvest_awards` outside the reports that
  already exist, no write anywhere, no shared setting (D-22).

### 2.6 Before you begin (once, before 21A)

1. Confirm **Phase 20 is released**: tag `p20g-release` exists, migration `024` is applied, and
   `SELECT COUNT(*) FROM harvest_queue` answers (0 is fine; the table must exist and be seeded before
   arming, which is 21C's problem).
2. Cut `feature/general-harvest` from `feature/team-harvest` at `p20g-release`; tag `p21-start`.
3. Back up the database (`mysqldump`) and the working tree, outside the repository. The project's
   standing rule: a backup before any migration.
4. Record the baseline: `php tests\run.php` → the Phase 20 release count (expected **1,318 / 1,318**);
   `tests\smoke.ps1` → **≈ 263 / 263**; `php bin\build_install_sql.php --check` → exit 0;
   `php bin\wallet_reconcile.php` → exit 0; `SELECT COUNT(*) FROM information_schema.TABLES WHERE
   TABLE_SCHEMA = DATABASE()` → **36**; `SELECT COUNT(*) FROM settings` → **77**. If a count differs
   from this plan, record the actual number in the phase log and use it as the floor from then on —
   the plan's floors are minimums, not contracts. If the baseline is not **green**, stop: a gate
   measured from a red baseline proves nothing.
5. Read the selected parent phase and its execution-slice entry in §2.7. For an AI task, load only
   the normative subsections and files named by that slice, plus the relevant current implementation
   files. Do **not** inject or reread all of §12 and §14 for every slice. The complete specification
   remains authoritative; selective loading is a context-management rule, not permission to ignore it.
6. Read §12.2 and §12.3 carefully before implementing board arithmetic. For later slices, reuse the
   recorded contract/checkpoint and reopen only the exact normative subsection needed to resolve a
   question. The block formula and the "N is the only input" rule remain the whole stream; every later
   number is a consequence of them.

---

### 2.7 AI execution slices — required for implementation work

The seven phases in §1.4 and §2.2 are **delivery gates**. They answer whether a coherent part of the
feature can be released safely. They are deliberately larger than one coding-agent task. An AI agent
must not be asked to implement an entire parent phase in one pass merely because the phase is described
as a complete work package.

For implementation, use the smaller **execution slices** below. A slice is the unit of one agent request
and one focused checkpoint. It is not a separately releasable product phase, does not receive a phase
tag, and does not change the parent phase's dependency graph, scope, or exit gate.

#### 2.7.1 Rules for every execution slice

1. **One slice per request.** Name one slice ID, such as `21D-S1`, and ask the agent to implement only
   that slice. It must stop after reporting the slice result; it must not continue automatically into
   the next slice or attempt to finish the parent phase.
2. **The parent phase remains the contract.** The slice may narrow the work, but it may not weaken,
   bypass or reinterpret the parent phase's deliverables, Rules, test plan, manual gate, rollback or
   hand-off. If the slice map and the parent phase disagree, the parent phase and Parts III/IV win; amend
   this plan before changing code if the contract itself needs to change.
3. **No temporary business-rule stubs.** A slice may add an unused but complete model method or a
   complete internal component that a later slice wires in. It may not add a fake `settle()`, a no-op
   wallet path, an always-successful reconcile, a placeholder authorization check, or any other stub
   that makes an incomplete feature look functional.
4. **No premature release.** Do not tag, merge as a completed parent phase, set `APP_PHASE`, arm the
   live board, enable General Harvest in production, or run the go-live procedure at a slice boundary.
   Parent-phase release happens only after the full parent exit gate passes.
5. **Keep each slice coherent.** Each slice owns one small deliverable cluster and its directly related
   tests. Prefer one primary concern and a narrow set of files. Do not split a single transaction,
   security boundary, immutable ledger operation or money invariant into pieces that would be incorrect
   when exercised together.
6. **Test at the slice boundary.** Run the targeted existing and new tests for that slice, plus syntax,
   static checks or smoke checks directly affected by it. Run the full suite when practical. A slice is
   not complete while its targeted tests fail. The full parent test plan and manual gate are still run
   at the parent phase boundary.
7. **Preserve the working baseline.** Before a slice, record the current branch, commit and test baseline.
   After it, report the changed files, tests and results, any deviations, and the exact next slice. A
   slice must not silently undo another completed slice.
8. **Use a compact hand-off.** Maintain a short execution log/checkpoint, for example
   `dmp/phase-21-general-harvest-execution-log.md`, with: parent phase, completed slice IDs, commit or
   working-tree checkpoint, changed files, tests run and results, decisions/deviations, unresolved
   issues, and the next slice. This is an implementation aid; it does not replace `docs/phase-log.md`
   or Appendix H, which record completed parent gates.
9. **Do not reread the entire plan on every task.** Give the agent this protocol, the selected slice, the
   relevant parent-phase section, only the normative references named for that slice, and the current
   checkpoint. Do not pass the 4,000-line plan as undifferentiated task context. The agent must inspect
   relevant existing code before editing and must not infer unseen repository details from this plan.
10. **Escalate contract conflicts.** If implementation exposes a missing rule, ambiguity or conflict,
    stop that slice, state the exact conflict and proposed options, and amend the plan before coding the
    changed behaviour. Do not let an agent silently invent a new contract.

#### 2.7.2 Slice completion record

At the end of every slice, the agent reports this compact record:

```text
Slice: 21X-Sn — <name>
Status: complete | blocked
Branch / checkpoint: <branch and commit, or working-tree checkpoint>
Files changed: <paths>
Tests run: <commands and case IDs>
Results: <pass/fail counts and exit codes>
Contract changes: none | <exact plan amendment required>
Known risks / follow-up: <only unresolved items>
Next allowed slice: <one slice ID>
```

A completed slice may be committed as a normal working commit if its targeted tests pass. Do not create
`p21*-release` tags for slices. The existing `p21a` … `p21g` tags remain parent-phase release tags.

#### 2.7.3 Recommended slice map

The map below is a task decomposition of the existing manifests, not additional scope. Test IDs listed
under a slice are its main proof targets; adjacent tests may also be required by shared code. Tests are
added alongside the code they prove, not postponed to a final testing-only task. Phases 21A–21C are
already marked landed in this plan: their slice rows are a retrospective decomposition and must not be
re-executed unless a defect is found. Start current work at `21D-S1`.

**Phase 21A — Foundation** *(historical; already landed)*

| Slice | Focus | Main proof targets |
|---|---|---|
| `21A-S1` | Migration `025_general_harvest.sql` and the block-for-block fresh-install mirror in `schema.sql`; keep table, enum and constraint definitions equivalent | P21A-T1–T3 |
| `21A-S2` | Seed parity, install footer/bundle generation and installer expected counts; never hand-edit generated `install.sql` | P21A-T4–T6, T11–T13 |
| `21A-S3` | Reset ordering/integrity and wallet `ref_type` vocabulary, labels and icon; keep enum and PHP constant together | P21A-T7–T10, T14–T15 |
| `21A-S4` | QA pack, evidence index, regression suite and the complete 21A manual/exit gate | P21A-T16; QA21A-01–08 |

**Phase 21B — Configuration and board arithmetic** *(historical; already landed)*

| Slice | Focus | Main proof targets |
|---|---|---|
| `21B-S1` | `GeneralHarvestLevel`: ten-row ladder persistence, validation, money ceilings and pure board/threshold formulas | P21B-T3–T20 |
| `21B-S2` | `GeneralHarvestBoard` read-only state, queue count/gapless check, frontier, blocks and range queries | P21B-T13–T14, T18–T22 |
| `21B-S3` | Admin settings controller integration: field definitions, save transaction, validation and audit event | P21B-T2–T10 |
| `21B-S4` | Settings partial, generated ladder/position tables, calculator JavaScript, no-JS behaviour, responsive checks and phase docs | P21B-T1, T22–T24; QA21B-01–09 |
| `21B-S5` | Parent-phase regression, full suite and 21B exit gate/tag | P21B-T25 and all 21B exit-gate items |

**Phase 21C — Baseline** *(historical; already landed)*

| Slice | Focus | Main proof targets |
|---|---|---|
| `21C-S1` | Baseline ledger model and General Harvest service state/armability rules; no wallet writer | P21C-T1, T7, T9–T13, T15, T18–T19 |
| `21C-S2` | One-time arm transaction, dry-run calculation, advisory lock, JSON/CLI contract and Windows launcher | P21C-T2–T8, T14, T16, T20 |
| `21C-S3` | Reset-and-rearm proof, docs, QA evidence, full suite and parent 21C gate | P21C-T17, T21; QA21C-01–08 |

**Phase 21D — Money engine** *(next active phase; keep the stream disabled outside controlled tests)*

| Slice | Focus | Main proof targets |
|---|---|---|
| `21D-S1` | Extend `GeneralHarvestAward` with immutable award creation/write primitives and the atomic wallet-link operation. Prove the award/wallet row relationship without wiring registration yet | P21D-T8–T9, T28 |
| `21D-S2` | Implement the service's core state-based settlement rules: board thresholds, award snapshots, baseline exclusion, depth, zero amount, off/unarmed states and `statusFor()`/liability results | P21D-T1–T7, T11–T25 |
| `21D-S3` | Wire the single `RegistrationService` hook; prove idempotency, replay, lock ordering, gap detection and all-or-nothing rollback with the registration transaction | P21D-T26–T27, T29–T34 |
| `21D-S4` | Implement bounded catch-up batches and the CLI/Windows launcher: dry run, limit, member filter, doctor output, locks and exit codes | P21D-T19, T41–T44 |
| `21D-S5` | Complete reconciliation, `wallet_reconcile.php`, reset integrity integration, runbook/README/cron notes and the complete money-phase verification | P21D-T35–T40; QA21D-01–10; all 21D exit-gate items |

The 21D slices are implementation checkpoints only. They do **not** authorize a partial settlement engine
in production. The phase remains incomplete until the unique-award rule, atomic wallet movement,
registration rollback, catch-up, doctor and independent reconciliation all pass together.

**Phase 21E — Admin visibility**

| Slice | Focus | Main proof targets |
|---|---|---|
| `21E-S1` | Admin commission ledger tab: filter normalization, rows, totals, pagination, wallet/member links and CSV export | P21E-T1–T6, T12, T17, T21–T24 |
| `21E-S2` | Admin member list and member detail: GH column, paid/none filter, board card and node-holder panel | P21E-T7–T11, T18–T19 |
| `21E-S3` | Report model/service/cards/exports, live refresh, responsive behaviour, documentation and complete admin gate | P21E-T13–T16, T20, T25; QA21E-01–08 |

**Phase 21F — Member visibility**

| Slice | Focus | Main proof targets |
|---|---|---|
| `21F-S1` | Earnings tab, member-scoped award rows, pagination, wallet links and member CSV export | P21F-T1, T6–T9, T15–T16 |
| `21F-S2` | Ten-block card, progress/range/roster presentation, mandatory copy, baseline wording and subtree-only username privacy | P21F-T2–T5, T12–T14, T19–T20, T22–T23 |
| `21F-S3` | Dashboard tile, genealogy badge, live refresh, mobile behaviour, query budget and complete member gate | P21F-T10–T11, T17–T18, T21, T24; QA21F-01–09 |

**Phase 21G — Release**

| Slice | Focus | Main proof targets |
|---|---|---|
| `21G-S1` | Synchronize product documentation: system README, deploy guide, runbook, admin guide and cron explanation; do not change feature behaviour | Documentation claims traced to already-passing phase tests |
| `21G-S2` | Final QA pack/evidence index, smoke-check inventory, sign-off sheet and release acceptance traceability | QA21G-01–03, QA21G-06; every QA21X check has evidence |
| `21G-S3` | Set `APP_PHASE = 21`, run the scratch-install and full acceptance sequence, record deviations, sign off and create `p21g-release` | QA21G-04–05 and all 21G exit-gate items |

#### 2.7.4 Agent request template

Use one request per slice. Replace the bracketed items; do not append the next slice to the same request.

```text
Implement only execution slice [21X-Sn — exact slice name] from
[dmp/plan-ecowealth-general-harvest.md §2.7.3].

Current checkpoint: [branch/tag/commit and completed slice IDs].
Read: §2.7; the parent phase section §[N]; and only these normative references:
[exact subsection numbers / named files required by this slice].
Inspect the current implementation and tests before editing.

Scope for this request:
[copy only the selected slice row and any phase-specific detail needed].

Rules:
- Do not implement any other slice or continue automatically.
- Do not change the parent phase's contract, add unrelated cleanup, or edit later-phase deliverables.
- Add/update the tests that prove this slice; preserve all existing passing tests.
- Do not create a release tag, set APP_PHASE, enable production money, or claim the parent phase is done.
- If the written contract is insufficient or conflicting, stop and report the exact issue before inventing behaviour.

At completion, run the targeted tests and return the §2.7.2 slice completion record.
Stop after that report.
```

#### 2.7.5 Context and cache discipline

The plan is the source of truth, but it is not intended to be pasted into every coding request. The
working context for a slice should normally contain:

- this §2.7 protocol and the one selected slice row;
- the selected parent phase's relevant paragraphs (not every other phase);
- only the normative specification subsections that govern the slice;
- the exact existing source files, tests and a short checkpoint from the prior slice.

Do not repeatedly ask the agent to summarize or reread the complete plan, the entire specification, or
all seven phase manifests. Do not ask it to "implement Phase 21D" when the intended work is `21D-S1`.
The agent must use repository search to inspect neighbouring patterns and should quote exact file paths
and test IDs in its checkpoint instead of carrying a full transcript forward.

Selective context does **not** mean skipping verification. The full normative specification remains
binding; whenever code touches a rule not included in the current slice brief, reopen that exact rule
before implementing it. At the end of all slices in a parent phase, run the complete parent test plan,
manual gate and universal gate in §2.4, then create the parent tag and phase-log entry.

---

## 3. Current state and the gap

### 3.1 The queue exists, and it is the only structure this stream needs

| Fact | Evidence |
|---|---|
| Phase 20 stores one row per qualified member; `harvest_queue.id` **is** the position, append-only, never renumbered | [`plan-ecowealth-team-harvest.md`](plan-ecowealth-team-harvest.md) D-01/D-03, §13.2 |
| The queue is seeded once for the pre-existing tree, and the live hook is inert until it is | same, D-04, `bin/harvest_queue_seed.php` |
| Qualification is three direct **member** accounts; staff and the company account never count and never qualify | same, D-02 |
| A queued member can never leave, and no code path deletes a member (the business reset is the only `DELETE FROM users`) | same, §3.1, §13.2 |
| So `N`, the queue length, is a monotone integer that only grows, and `seq = id − 1` is stable for ever | consequence of the four rows above |

That is the entire structural input of General Harvest. The board adds **no table, no column and no
write** to the queue (D-01); it reads `id` and `COUNT(*)`.

### 3.2 Nothing in the application derives a 3-ary board

No file in `system/` contains the board arithmetic (`3^L`, `(3^L − 1)/2`, "block", "slot", "node").
The only implementations of it are the reference document and the simulator behind it. The arithmetic
is small but it is **money arithmetic**, so it lives in one place (`GeneralHarvestLevel` and
`GeneralHarvestBoard`, both 21B) and every view, job and report reads it; no view computes a threshold
of its own.

### 3.3 Every existing money stream hangs off an event with an amount — this one hangs off a count

| Stream | The event | The amount | The earner |
|---|---|---|---|
| Package referral | activation (a package purchase) | the package's per-level peso amount | the buyer's uplines |
| Indirect referral | registration | a flat per-level amount | the new member's uplines |
| Unilevel repeat purchase | an order reaching `paid` | a percentage of the line base | the buyer + ten uplines |
| Royalty | the monthly close | a percentage of the month's volume | rank holders |
| Team Harvest | a downline qualification | a flat, pre-configured level amount | the qualified ancestor |
| **General Harvest** | **the queue reaching a position** | **a flat, pre-configured level amount** | **the member holding that node — who need not be related to anybody in the event** |

The last row is the architectural novelty, and it has four consequences, each a decision in §4:

1. **The trigger is a network-wide scalar.** Nothing about the member's own team matters; the engine
   must settle **positions**, not people. A registration at position #9,001 can complete level 1 for
   node #3,000 and pay a stranger (D-05).
2. **The liability is computable without the ledger.** `boardTotal(N)` is a closed form (D-21), so
   every report and every reconcile check has an independent, exact expectation.
3. **The baseline is global, not per member.** One number — `N` at go-live — decides every exclusion
   (D-06). There is no per-member `baseline_n`.
4. **The frontier is a prefix per level.** Completion is monotone in `seq` for a fixed level, so the
   ledger's rows at each level are exactly `0 … count−1`, and "how far has this level paid" is a
   `COUNT(*)` (D-05). That single property is what makes the engine O(new positions) instead of
   O(nodes), and what makes the reconcile an arithmetic identity rather than a sample.

### 3.4 None of the existing ledgers can hold a board award

| Ledger | Grain | Why it cannot hold this stream |
|---|---|---|
| [`commissions`](../system/database/schema.sql) | `(type, source_user_id, user_id, level)`, with `activation_id` and `package_id` FKs **NOT NULL** | Requires an activation and a package; there is neither |
| `unilevel_awards` | one order line × recipient × level 1–10 | Every row needs an order, an item, a product, a base and a rate |
| `royalty_awards` | one member × one closed month | One row per member per month; General Harvest pays per **node and level**, on no schedule, and a member may be paid for a level at most once |
| `team_harvest_awards` | one member × one level, unique on `(user_id, level)` | The same member can hold exactly one node, but the money is attached to the **node** (position), not the account; and reusing the table would merge two streams' switches, baselines and reconciliations (D-22) |

A separate ledger is therefore mandatory, and it is the simplest of the five: **no type column, no
period, no product, no rate, no percentage** — a node, a level, and an amount
([`general_harvest_awards`](#134-general_harvest_awards--the-money-ledger)).

### 3.5 The two Harvest streams share one queue and nothing else

| | Team Harvest (Phase 20) | General Harvest (this plan) |
|---|---|---|
| Uses the queue | yes — teammate counts come from positions | yes — the **board is** the queue |
| Structural input | the member's qualified-after descendants | the whole queue's length |
| Board shape | a private 3×10 matrix per member | one shared 3-ary board for everyone |
| Placement | qualification order inside the member's own matrix | the queue index itself (`seq`) |
| Gate to earn | being qualified + qualified-after teammates | holding a node whose block the queue has reached |
| Can pay across team lines | **no** — sponsor subtree only | **yes** — whoever occupies the position |
| Trigger | a qualification inside the member's subtree | any qualification anywhere, once `N` crosses a position |
| Baseline | per member (`baseline_n` on the queue row) | global (`N₀` on one board row) |
| Ladder | ₱1,000 … ₱3,500,000 (₱5,095,000) | ₱2,000 … ₱5,000,000 (₱8,381,000) |
| Cost at `N = 88,573` | ₱95,002,000 for a perfect 265,720-member tree | ₱190,208,000 at that queue size |

The two streams are independent money: separate switches, separate ladders, separate ledgers,
separate baselines, separate reconciles. A member may earn both; the only shared fact is the order in
which they qualified.

### 3.6 The simulator is the authority for the mechanics, not for the storage

| What the simulator does | What it cannot do | What this plan does |
|---|---|---|
| `levelsG(p)` derives the ten blocks from the queue array index `p` and `S.mx.length` on every render (reference §4.2) | Nothing is persisted but a `localStorage` blob; the queue has no ids, no timestamps and no audit | A `general_harvest_awards` row per `(seq, level)`, with the threshold, the `N` observed, the amount and the wallet reference |
| General and Team money are "derived" — they exist by construction and have no payment event (reference §2.5) | There is no cash, no wallet and no way to say when a block paid | A level pays once, into the e-wallet, with a ledger row (D-04) |
| The board's whole history counts from the first render; there is no go-live | Every member who ever qualified would be paid the moment the feature is deployed | `baseline_n` + `baseline` rows: pre-go-live blocks are recorded, never paid (D-06) |
| `mx` is an array; positions are array indices | The queue can be reordered by editing the blob | Positions are immutable rows (Phase 20); the board index is `id − 1` (D-01) |
| The board is unbounded above ten levels | — | The board is unbounded, but only ten levels pay, per node (D-18) |

The reference's own numbers are the contract: the ladder (₱2,000 … ₱5,000,000), the thresholds
(4, 13, 40 … 88,573), the closed form, the all-or-nothing rule, the "pays across team lines" property
and the "no purchases, no rank, no month" list. Every one of them is reproduced exactly and is pinned
by a test in §16.

### 3.7 The gap, in one table

| Need | Today | This plan | Phase |
|---|---|---|---|
| The board's arithmetic (blocks, thresholds, board totals) | none | `GeneralHarvestLevel` + `GeneralHarvestBoard` | 21B |
| A ladder an operator can configure and see | none | the settings card + the generated table | 21B |
| A go-live baseline for a queue that already has members | none | `bin/general_harvest_arm.php` + `baseline_n` + baseline rows | 21C |
| A ledger that can hold one row per node per level | five ledgers, none with the right grain | `general_harvest_awards`, unique on `(seq, level)` | 21A / 21D |
| A settlement engine for a network-wide trigger | none | `GeneralHarvestService::settle()` | 21D |
| Wallet money + reconciliation | `WalletService` / `wallet_reconcile.php` (seven sections) | `ref_type = 'general_harvest'` and an eighth section | 21A / 21D |
| An award rule that cannot pay twice | none | prefix frontier + unique anchor + state-based settle (§14.5) | 21D |
| Member visibility | `/earnings` shows four streams | a fifth tab, a block card and a tile | 21F |
| Admin visibility | four commission tabs | a fifth tab, a board card and four report cards | 21E |

### 3.8 What Phase 20 already froze, which this plan must not reopen

| Frozen contract | Why it matters here |
|---|---|
| `position = harvest_queue.id`, append-only, never renumbered | the board index is `id − 1`; a renumbering would rewrite every block |
| Staff and the company account never qualify | nobody outside `role = 'member'` holds a node |
| The hook is inert until the queue is seeded | General's arm requires the queue to be live (D-07) |
| `team_harvest_ledger_since` is the queue's go-live marker | the arm job checks it; General never writes it |
| The queue is shared on purpose (D-16 of Phase 20) | there is no second queue and no second order |

This plan defines the **gapless** property (§12.3, D-03) that Phase 20 did not need: Phase 20 tolerates
gaps (a deleted member's position is a meaningless hole), while General Harvest's arithmetic is defined
over consecutive indices and cannot. The property holds today because no code path deletes a member
except the business reset; the plan asserts it rather than changing the queue.

---

## 4. Decisions

Each decision is a contract for the implementation and the tests. Alternatives are listed so a later
review can reopen a decision without re-deriving it. The **Ships in** column is the phase that
introduces the rule; no later phase may change it.

| ID | Decision | Rationale | Rejected alternative | Ships in |
|---|---|---|---|---|
| **D-01** | The board is **derived from `harvest_queue`**: one node per queued member, node index `seq = harvest_queue.id − 1`. This plan adds **no queue, no column and no writer** to that table | Phase 20's D-16 froze the queue for this stream; position is the board's only structural input | A second queue, or a `board_seq` column — guaranteed to diverge from the position it copies | 21B |
| **D-02** | **No new gate.** A member earns from the board because they hold a node, which exists because they qualified under Phase 20's rule (≥ 3 direct member accounts, configurable 1–10) | One qualification rule, one order, one meaning of "qualified" | A General-specific gate — two definitions of qualified in one application | 21B |
| **D-03** | **The queue must be gapless**: `COUNT(*) = MAX(id)`, so `N = COUNT(*)` and node `q` is the row with `id = q + 1`. A hole is an out-of-band corruption: settlement **fails closed**, the reconcile reports it, and the repair is restore-from-backup | The board's blocks are defined over consecutive indices; a hole would silently shift every later node and pay the wrong people. No application path deletes a member, so the invariant holds by construction | Compute over ordinals (`ROW_NUMBER`) — a deletion would renumber every later node and rewrite history | 21B/21D |
| **D-04** | Money is a **ledger** (`general_harvest_awards`), one row per `(seq, level)`, written when the settlement first observes the block full; a level pays **once, ever**; a row is never edited or deleted | The reference derives this money, which cannot be audited or paid; the node — not the member — is what the money is attached to | Key the ledger on `(user_id, level)` — a deleted and re-created member would inherit a paid level at a different position | 21A/21D |
| **D-05** | The **frontier is a prefix per level**: rows at level `L` are exactly `seq = 0 … count−1`, read from `COUNT(*)`, never stored in a cursor table | Completion is monotone in `seq`, so the paid set is a prefix; one aggregate per level is the whole state, and the reconcile is an arithmetic identity | A per-level cursor table — a second source of truth for the same number | 21B/21D |
| **D-06** | **The baseline is global**: `board.baseline_n` is `N` at arm; every block full at that instant gets a `baseline` row (amount recorded, `ledger_id NULL`) and is **never credited** | Phase 17's D-16 stance: the company never agreed to pay for a period before the stream existed. One number decides every exclusion, for ever | Per-member `baseline_n` — General Harvest's exclusion is about the queue length, not about a member | 21C |
| **D-07** | **Arm is a one-time CLI job**, refusing when the queue is unseeded, when the stream is enabled, when the board is already armed, or when the ladder is invalid | The baseline is irreversible; it must be a deliberate, dry-runnable, locked act, not a settings save | Arm on first settlement — the first registration after deploy would decide the baseline silently | 21C |
| **D-08** | **No month, no period table, no close job.** The award happens when the queue reaches a position; the audit is the ledger | Reference §4.8 has no monthly cycle for this stream; inventing one would delay money that is already earned | Evaluate monthly like Royalty — a period table, a close job and up to a month of delay for an event with no volume in it | 21A |
| **D-09** | **Settlement is capped by positions**: inline it is exactly **one** position per registration; the catch-up job settles in batches (default 500 positions per transaction) | A backlog must be paid in bounded transactions, not inside a member's registration; one position inline is at most ten award rows | Settle the whole backlog inside the registration — an unbounded transaction on the app's most critical path | 21D |
| **D-10** | **One switch**: `general_harvest_enabled` (off by default), plus `general_harvest_max_levels` (1–10). Arming is separate from enabling | The structure and the baseline must be complete before money depends on them, and turning money off must never corrupt the board | A second "pay" switch — it would need a pending state, a pay job and a resumable batch for a stream whose award *is* the payment | 21A/21B |
| **D-11** | The ten amounts live in **`general_harvest_levels`** (ten rows), not in settings; they are edited on the settings card, validated as money, and kept by a business reset | Ten money rows are a table, not ten `settings` strings; the same shape as `package_levels`, `product_unilevel_levels` and `team_harvest_levels` | Ten `general_harvest_level_N` keys — unreadable in `SELECT * FROM settings`, unvalidatable as a set | 21A/21B |
| **D-12** | Award and wallet credit are **one transaction**; `ref_type = 'general_harvest'`; the credit is **withdrawable**; no hold and no escrow | Phase 18's hold exists because a product bonus can be reversed by a delivery failure; a structural board award has no delivery to wait for | Reuse the unilevel escrow hold — would delay money for a feature the hold does not protect | 21A/21D |
| **D-13** | **No reversal and no void** in v1: an award is final, and a wrong award is a forward-only business decision (§18 Q3) | There is no cancellation event in this stream. A manual void tool is a money-editing surface that deserves its own plan | Admin "void award" action — permanent irreversible money edits, with no event to justify them | 21D |
| **D-14** | **No status gate on earning.** A suspended or deactivated node holder is still credited; the existing payout rules decide whether they can withdraw | The reference has no status concept, and a status gate would create an unpaid backlog whose timing nobody can explain | Skip ineligible members and pay them later — an invisible IOU with no ledger | 21D |
| **D-15** | **No new routes**: `/admin/settings`, `/admin/commissions?ledger=general_harvest`, `/admin/members` (`?board=paid` or `?board=none`), `/admin/members/{id}`, `/admin/reports`, `/earnings?ledger=general_harvest`, `/genealogy`, `/dashboard` | `APP_PHASE`/`ParityTest` gating stays untouched; one page per concept | Separate pages/routes — more surface, more inventory entries, more tests | all |
| **D-16** | `APP_PHASE` becomes **21** | The build should report the phase that shipped the feature | Leave it at 20 — the new UI would render with a stale phase badge | 21G |
| **D-17** | **A payable level must have a positive amount.** Levels `1 … max_levels` must each be `> 0` to enable the stream or to raise the depth; a zero-amount level is never paid, is reported as *unpriced*, and is skipped without a row | A shared board's frontier is a prefix; a zero-amount level that wrote no row would leave a permanent gap in the prefix. Team Harvest can allow zero because its frontier is a per-member counter, not a prefix | Writing a `0.00` award row — a lie about money that the wallet refuses anyway | 21B/21D |
| **D-18** | Levels are **structural**: `3^L` slots and threshold `3^L·seq + (3^(L+1) − 1)/2`, fixed. Only the ten amounts and the maximum depth (1–10) are configurable | A configurable threshold would invalidate every stored award and make two members' boards incomparable | Configurable thresholds "for tuning" — the plan's arithmetic becomes unexplainable and unauditable | 21B |
| **D-19** | Settlement is **state-based**: it compares the queue length with the rows, so a missed event **self-heals** on the next position or the catch-up run. Configuration states (off, unarmed, unpriced, beyond depth) never throw and never block a registration; wallet and database failures do | A registration must not fail because the board is misconfigured, and money must never be decided and not written | Event-count-based awards (increment per event) — one lost event silently underpays for ever | 21D |
| **D-20** | **Privacy on the block roster**: a position's username is shown to a viewer only when that member is inside the viewer's own sponsor subtree; everybody else is "a member outside your team" (position only). Admins see everyone | The board is global; showing a stranger's username would disclose a member to people outside their team, which the genealogy never does (reference §4.9 shows every name because a simulator has no privacy) | Show every username on every block — a new disclosure to the whole network | 21F |
| **D-21** | **The board's liability is a closed form**: `boardTotal(N) = Σ_L amount(L) · max(0, floor((N − (3^L − 1)/2)/3^L))`. Every report, every reconcile check and every test compares the observed ledger with it | It is the only independent expectation in the whole feature; a ledger bug cannot hide from an identity that does not read the ledger | Trust the ledger's own totals — a duplicated or missing row would reconcile with itself | 21D |
| **D-22** | **Team Harvest is untouched**: no change to its tables, settings, ledger, pages, jobs or ladder; General Harvest reads `harvest_queue` only | Two streams, two baselines, two reconciles; merging them would make the switches and the exclusions unexplainable | A shared "harvest" ledger with a type column — the reconciliation would have to guess which stream a row belongs to | all |
| **D-23** | **The ten amounts are frozen at the arm.** The settings save refuses an amount change while the board is armed; `max_levels` may still move (up or down), and the amounts it exposes are the frozen ones. Changing an amount after arming requires a business reset first — safe only while no `credited` row exists | The board's whole liability is a closed form over **one** ladder (D-21). A mid-stream amount change would make two nodes receive different amounts for the same level, break `Σ credited = boardTotal(settled_n) − boardTotal(baseline_n)`, and make the reconcile a sample instead of an identity. Team Harvest can allow edits because its frontier is per member; a shared board cannot | Allow edits and weaken the identities — every report and reconcile would then need a per-row amount history, which is exactly what the ledger already is; the closed form would stop being an independent expectation | 21B/21C |

---

# Part II — The phases

## 5. Phase 21A — Foundation

**Goal.** A database that carries every object the feature will ever need, a wallet vocabulary that can
name the new movement, and an install/reset/migration path that knows about all of it — with **no code
path reading any of it**. After 21A the application behaves exactly as it did at `p21-start`.

**Builds on.** Nothing. This is the root of the graph (Phase 20 is a released prerequisite, not a phase
of this plan).

**Ships alone because.** The three new tables have no reader, the two new settings have no reader (the
settings page does not render them until 21B), and the new `ref_type` value cannot appear in a ledger
row because nothing constructs it. The only observable changes are one filter option in the wallet
statement (Rule 6) and different numbers in `install`'s verification output.

**Effort.** 1.5 ideal days. **Suite.** 1,318 → ≥ 1,336 cases, all green.

**Reference.** §13 in full (the model and the migration text), §12.2–§12.3 (the board and its index),
§12.7 (money).

**Deliverables**

**Database**

- `system/database/migrations/025_general_harvest.sql` — the migration of §13.6, verbatim: three tables
  (`general_harvest_levels`, `general_harvest_awards`, `general_harvest_board`), the
  `ewallet_ledger.ref_type` extension, two settings, the ten ladder rows and the one board row.
  Idempotent: every `CREATE TABLE` is `IF NOT EXISTS`, the `ENUM` change is guarded by an
  `information_schema` probe and an `ALTER`/`DO 0` pair, the settings insert is
  `ON DUPLICATE KEY UPDATE key_name = key_name`, the ladder insert is
  `ON DUPLICATE KEY UPDATE level = level`, and the board insert is `ON DUPLICATE KEY UPDATE id = id`
  (a re-run must never overwrite an operator's amounts or an armed board).
- `system/database/schema.sql` — the fresh-install mirror of §13.7, block for block, so
  `MigrationTest`'s migrated-vs-fresh comparison stays green. The `harvest_queue` comment gains one
  line naming the board, and the `ewallet_ledger` comment gains `general_harvest`.
- `system/database/seeds/001_settings.sql` — the two keys **and** the ten ladder rows **and** the one
  board row, in the same order and with the same defaults as the migration. 20A set the precedent of
  seeding the Team Harvest ladder from this file; 21A follows it so the install bundle's section list
  (and therefore `build_install_sql.php`) does not change.
- `system/database/install/footer.sql` — the expected-counts comment: **39 tables, 79 settings**, ten
  ladder rows and one board row.
- `system/database/install.sql` — regenerated by `php bin/build_install_sql.php`; never hand-edited.

**Code**

- `system/app/Services/DatabaseInstaller.php` — `EXPECTED`: `tables_created` 36 → **39**,
  `settings_rows` 77 → **79**, plus `general_harvest_levels_rows` → **10** and
  `general_harvest_board_rows` → **1**; the settings failure message becomes "76 keys plus the three
  `install_*` metadata rows"; `TRANSACTIONAL_TABLES` gains `general_harvest_awards`, so a fresh install
  fails verification if the money ledger is non-empty. `general_harvest_levels` and
  `general_harvest_board` are **catalogue/state**, like `package_levels` and `team_harvest_levels`, and
  are not in that list; the verification additionally asserts that the board row is **unarmed**
  (`baseline_n IS NULL`). `DEMO_TABLES` is unchanged (the demo seed writes no board rows).
- `system/app/Services/DatabaseResetService.php` — `ALL_TABLES` gains the three names (36 → **39**);
  `ZERO_TABLES` gains `general_harvest_awards`; `AUTO_INCREMENT_TABLES` gains `general_harvest_awards`
  (it is the only new table with a counter); `DELETE_GROUPS` gains a **General Harvest** group *before*
  the members group (the awards reference `users`, so they must go first). The board row is **reset,
  not deleted**: `UPDATE general_harvest_board SET baseline_n = NULL, armed_at = NULL, armed_by = NULL,
  settled_n = 0`. A business reset also keeps the ten ladder rows and the two settings, and the
  integrity map is not touched in this phase — it names `GeneralHarvestService`, which does not exist
  until 21D.
- `system/app/Services/WalletService.php` — `REF_TYPES` gains `general_harvest`. This *must* ship with
  the enum: [`WalletTest`](../system/tests/Cases/WalletTest.php) P5-T8 asserts that `REF_TYPES` and the
  live enum agree **in both directions** (now thirteen values), so the column and the constant cannot
  land in different phases.
- `system/app/helpers.php` — `wallet_ref_label()` gains *"General Harvest bonus"* and
  `wallet_ref_icon()` gains `bx-grid-alt`, because `partials/wallet_statement.php` renders one filter
  option per `REF_TYPES` entry.

**Docs**

- `docs/qa/phase-21-general-harvest.md` — created with the header, the "what is already automated"
  block and **section A only** (checks `QA21A-01` … `QA21A-08`). Every later phase appends its own
  section; the file is never rewritten.
- `docs/qa/README.md` — one Phase 21 row in the contents table, marked *in progress (21A)*.
- `docs/qa/evidence/phase-21/README.md` — the evidence folder index.

**Not yet**

- No `GeneralHarvestLevel`, no `GeneralHarvestBoard`, no `GeneralHarvestAward`, no
  `GeneralHarvestService`, no arm job, no controller, no view, no hook. The tables are empty (except
  the ten ladder rows and the single unarmed board row) and unread; the stream cannot pay because
  nothing constructs `general_harvest` as a ledger type.

**Rules**

1. **The migration is additive and idempotent.** Re-running it on a migrated database is a no-op: no
   error, no duplicate settings, no overwritten ladder amount, no disturbed board row. `MigrationTest`
   proves it by running the file twice.
2. **`schema.sql` and the migration must be equivalent.** `MigrationTest` builds one database from the
   migrations and one from `schema.sql`, then compares table structures. A column that exists in one
   and not the other fails the phase.
3. **The seed and the migration must agree.** `SeedParityTest` fails unless seed 001 carries every
   settings key the schema/migration introduces (the two new keys). The ten ladder rows and the board
   row are added to both in this phase and asserted by `InstallTest`.
4. **Defaults are off and unarmed.** `general_harvest_enabled = '0'`, `general_harvest_max_levels =
   '10'`, and the board row is `(baseline_n NULL, armed_at NULL, armed_by NULL, settled_n 0)`. A fresh
   install and a migrated install both pay nothing and neither pretends to have a baseline.
5. **No backfill.** No `UPDATE … JOIN` runs over `users`; the baseline is recorded by 21C's arm job, on
   demand, with a dry run, not by a migration.
6. **The one visible change is the wallet statement filter.** `partials/wallet_statement.php`
   enumerates `WalletService::REF_TYPES`, so the member and admin wallet filters gain a *General
   Harvest bonus* option in this phase. It cannot match a row until 21D. This is accepted, documented
   in the QA pack, and is the price of keeping the enum and the constant in one phase (Rule 7).
7. **Enum and constant move together.** See `WalletService::REF_TYPES` above. Never leave the database
   accepting a value PHP rejects, or vice versa.
8. **The reset empties the ledger and returns the board to unarmed.** After a business reset,
   `general_harvest_awards` is empty, `general_harvest_board` is `(NULL, NULL, NULL, 0)`,
   `general_harvest_levels` keeps its ten rows and the two settings keep their values (§13.8 I-09).
9. **The ladder is seeded exactly once.** The migration inserts the ten reference amounts, and a re-run
   changes nothing. An operator's edit is never overwritten by a deploy.
10. **No application file outside the manifest changes.** `git diff --stat` must touch only the files
    listed above. A change in a controller or a view means the phase has crept.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P21A-T1 | Apply 025 to a database at `p20g-release` | 3 tables, the extended enum, 2 settings, 10 ladder rows and 1 unarmed board row exist; no error |
| P21A-T2 | Run 025 a second time | No error; object counts, settings, ladder amounts and the board row identical to the first run |
| P21A-T3 | Migrated vs. fresh (`MigrationTest`) | Structures identical; the test's ordered migration list includes `025_general_harvest.sql` |
| P21A-T4 | `SeedParityTest` | Seed 001 and the schema carry the same 79 keys, including the two new ones |
| P21A-T5 | `php bin/build_install_sql.php --check` | Exit 0, no output: the bundle matches its sources |
| P21A-T6 | Fresh install (`InstallTest`) | Verification reports **39** tables, **79** settings, 10 ladder rows, 1 board row, board unarmed, 2 staff, 0 members, 7 products, 2 packages, 18 levels, 10 contents |
| P21A-T7 | Insert one `general_harvest_awards` row, then run install verification | The `transactional` check fails with the table named — proving Rule 4's guard is wired |
| P21A-T8 | Business reset (`DbResetTest`) | `general_harvest_awards` is 0 rows; the board row is `(NULL, NULL, NULL, 0)`; the ten ladder rows and the two settings survive; `ALL_TABLES` is 39 names |
| P21A-T9 | `WalletService::REF_TYPES` vs. the live enum (`WalletTest` P5-T8) | Exactly equal in both directions, **13** values |
| P21A-T10 | `wallet_ref_label('general_harvest')` | "General Harvest bonus"; the icon resolves to `bx-grid-alt` |
| P21A-T11 | Defaults on a migrated database | `general_harvest_enabled = '0'`; `general_harvest_max_levels = '10'`; board `baseline_n IS NULL`, `armed_at IS NULL`, `settled_n = 0` |
| P21A-T12 | The ten seeded amounts | `2000, 4000, 8000, 12000, 25000, 100000, 250000, 480000, 2500000, 5000000`; total **8,381,000.00** |
| P21A-T13 | Insert a second board row (`id = 2`) | Refused by the primary key and by `chk_ghb_singleton` (`id = 1`) |
| P21A-T14 | Delete a member who holds an award | The award cascades on `users`; no orphan row, no FK error |
| P21A-T15 | Any application file outside the manifest | `grep -r -e general_harvest system/app` returns only `WalletService`, `helpers.php`, `DatabaseInstaller`, `DatabaseResetService` |
| P21A-T16 | The full suite | 1,318 + the phase's cases, all green, exit 0; `ParityTest` and `RouterTest` untouched and green |

**Manual gate — `QA21A-01` … `QA21A-08`**

Run on a **scratch** schema built from the previous tag, not on the development copy.

1. `QA21A-01` — `mysql < 025_general_harvest.sql`, then `SHOW TABLES LIKE 'general%'` → three tables.
   Re-run the file → no error.
2. `QA21A-02` — `SHOW COLUMNS FROM general_harvest_board` → `id, baseline_n, armed_at, armed_by,
   settled_n, updated_at`; `SHOW COLUMNS FROM general_harvest_awards` → `id, seq, user_id, level,
   threshold_n, n_at_award, amount, status, ledger_id, credited_at, created_at`;
   `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'` → the enum ends `'team_harvest','general_harvest'`.
3. `QA21A-03` — `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'general_harvest%'` → 2;
   `SELECT COUNT(*), SUM(amount) FROM general_harvest_levels` → 10, 8381000.00;
   `SELECT * FROM general_harvest_board` → exactly one row, `baseline_n` NULL, `settled_n` 0.
4. `QA21A-04` — open `/admin/settings`: no General Harvest block appears (it is 21B's). Open
   `/admin/members/{id}`: no board panel and no `GH` column.
5. `QA21A-05` — register three members under one sponsor. `SELECT COUNT(*) FROM harvest_queue` → 1
   (Phase 20's hook); `SELECT COUNT(*) FROM general_harvest_awards` → 0: no board writer exists yet.
6. `QA21A-06` — `/wallet` → the statement filter now lists *General Harvest bonus*; selecting it
   returns no rows.
7. `QA21A-07` — rebuild the bundle and re-install on an empty schema: the verification pack prints
   `tables_created = 39`, `settings_rows = 79`, `general_harvest_levels_rows = 10`,
   `general_harvest_board_rows = 1`; `php bin/wallet_reconcile.php` exits 0.
8. `QA21A-08` — run the development reset console once: the ledger is empty, the board reads unarmed,
   the ten ladder rows and the two settings survive, and verification passes.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P21A-T1 … P21A-T16 pass
[ ] MigrationTest proves a second run is a no-op and that migrated == fresh
[ ] InstallTest / InstallSqlTest / SeedParityTest / DbResetTest / WalletTest green at 39 / 79 / 10 / 1 / 13
[ ] git diff --stat touches only the 21A manifest
[ ] Tag p21a-foundation; docs/phase-log.md entry; Appendix H row
```

**Do not start 21B until every box is ticked.**

**Rollback**

Drop the three tables, restore `ewallet_ledger.ref_type` to its previous twelve-value enum, delete the
two settings and revert `REF_TYPES`/labels. No money exists to lose, so the only care needed is
restoring the enum *before* dropping the tables (there are no rows holding the value, but do it in that
order for consistency). `schema.sql`, the seed and `install.sql` return to their previous content;
`install.sql` is regenerated, never hand-repaired.

**Hand-off to 21B**

- A migrated database with every column, table and setting the feature needs, ten ladder rows at the
  reference amounts, and one unarmed board row.
- A fresh-install bundle that produces the same database, verified by `MigrationTest`.
- A reset engine that empties the ledger, returns the board to unarmed, and preserves the ladder.
- A wallet vocabulary that can name `general_harvest`.
- **Nothing that reads any of it.** 21B may assume the objects exist, are empty (ten ladder rows and one
  board row excepted), and are inert.

**Risks**

| Risk | Mitigation |
|---|---|
| `schema.sql` drifts from the migration | `MigrationTest` compares structures; the gate runs it, not just the author |
| `install.sql` is hand-edited | Regenerated only; `--check` in the gate |
| The enum/`REF_TYPES` pair is split across phases | Both in 21A, with a test that compares them in both directions |
| A migration overwrites an operator's ladder on re-deploy | The ladder insert is `ON DUPLICATE KEY UPDATE level = level` (Rule 9); P21A-T2 pins it |
| A migration re-arms a board | The board insert is `ON DUPLICATE KEY UPDATE id = id`; the arm job is 21C's |
| The reset deletes the board row and the app divides by a missing row | The reset **updates** the singleton instead of deleting it (Rule 8, P21A-T8) |

---

## 6. Phase 21B — Configuration and the board arithmetic

**Goal.** An administrator can set the ten level amounts and the two knobs, see exactly what the ladder
pays and at which queue size, and the application can compute — from the queue alone — the board's
blocks, thresholds, per-level completion and whole-board totals. **No money moves and no ledger row
exists.**

**Builds on.** 21A (the tables, the settings, the ten seeded amounts, the board row).

**Ships alone because.** The arithmetic is pure and inert: every number this phase prints is a function
of `N` and the ladder, tested against the reference document's own published tables. Nothing writes the
ledger. If every later phase were cancelled, the operator would still have a validated, audited,
readable statement of what the plan pays — and the app would still be correct.

**Effort.** 2.0 ideal days. **Suite.** ≥ 1,362 cases.

**Reference.** §12.2–§12.4 (the board, the blocks and the ladder), §12.9 (validation and ceilings),
§13.3 and §13.5 (the ladder table and the settings), Appendix A (the preset), Appendix B.1 (the first
positions).

**Deliverables**

**Database.** None. 21A shipped every table, column, setting and ladder row this phase writes.

**Code**

- `system/app/Models/GeneralHarvestLevel.php` (new) — the ladder and **all** the board arithmetic:
  `rows()`, `map()`, `amounts()`, `amount(int $level)`, `save(array $amounts, ?array $actor)`,
  `validate(array $amounts, int $maxLevels, bool $enabled)`, `referencePreset()`, `forForm()`,
  `total()`, `cumulative()`, `slots(int $level)`, `blockStart(int $seq, int $level)`,
  `blockEnd(int $seq, int $level)`, `blockFrom(int $seq, int $level)` / `blockTo(...)` (1-based display),
  `threshold(int $seq, int $level)`, `apexThreshold(int $level)`, `complete(int $seq, int $level, int $n)`,
  `filled(int $seq, int $level, int $n)`, `levelsFor(int $seq, int $n)`, `earnedBy(int $seq, int $n)`,
  `qFor(int $level, int $n)` (highest complete `seq`, or −1), `awardCount(int $n)`, `boardTotal(int $n)`,
  `nextN(int $seq, int $level)`, `nextCompletion(int $n)`, `maxLevels()`, `enabled()`.
  Zero-filled on read, exactly like [`ProductUnilevel`](../system/app/Models/ProductUnilevel.php) and
  `TeamHarvestLevel`: a missing row reads `0.00`, and the model writes all ten rows on save
  (delete-then-insert inside the caller's transaction) so a partial ladder can never exist.
  **Every formula in §12.2–§12.6 lives here and nowhere else.**
- `system/app/Models/GeneralHarvestBoard.php` (new) — the board's state, read-only in this phase:
  `state()`, `queueN()`, `gapless()`, `frontier()`, `backlog()`, `status()`, `positionOf(int $userId)`,
  `ownerOf(int $seq)`, `blocks(int $seq, int $n)`, `blockRange(int $level, int $offset, int $limit)`.
  `state()` reads the singleton; `queueN()` returns `{n, max_id, gapless}`; `frontier()` returns, per
  level, `{rows, complete, missing, next_n, amount}` using one grouped aggregate and the closed forms.
- `system/app/Controllers/AdminSettingsController.php` — `GENERAL_HARVEST_FIELDS`,
  `GENERAL_HARVEST_TOGGLES` and `GENERAL_HARVEST_AMOUNTS` constants in the same
  `{key,label,rule,suffix,hint}` / `{label,hint}` shapes as the existing ones, merged into
  `sectionKeys()`, `sectionToggles()`, `fieldRules()` and `fieldLabels()`; `settingsContext()` passes
  `general_harvest_fields`, `general_harvest_toggles`, `general_harvest_amounts`,
  `general_harvest_ladder` (the read-only table), `general_harvest_positions` (the position table),
  `general_harvest_board` (the state strip and the calculator's defaults) and
  `general_harvest_preset`. Section stays `commissions`. The save path writes the two knobs and the ten
  amounts in one transaction and audits `general_harvest.ladder.update` (before/after) only when
  something changed.
- Views: `admin/settings/_general_harvest.php` (new partial), `admin/settings/_commissions.php`
  (include the sub-block after the Team Harvest card).
- `system/public/assets/js/app-general-harvest.js` (new) — the live cumulative, full-ladder total,
  per-position threshold, and the "at this queue size" calculator. Progressive enhancement only: with
  JS off the form still saves, still validates, and the server-rendered numbers are correct.

**Docs.** `docs/qa/phase-21-general-harvest.md` gains section B (`QA21B-01` … `QA21B-09`).
`system/README.md` gains the settings block and the ladder. `docs/admin-guide.md` gains the card
section. `docs/deploy.md` gains the two keys and the ten ladder rows.

**Not yet.** No arm job, no baseline, no `GeneralHarvestService`, no settlement, no award row, no
wallet movement, no member-facing page. Registering members after this phase still writes nothing.

**Rules**

1. **Ten rows, always.** The ladder is exactly levels 1–10; blank and `0` are equivalent; a save writes
   ten rows or the ladder is cleared, never nine (D-11).
2. **Amounts are money.** `nullable|numeric|gte:0|lte:10000000|decimal:2`. The inputs carry
   `min="0" max="10000000" step="0.01"`; the server does not trust them.
3. **The total has a ceiling.** The ten amounts must total no more than **₱25,000,000** — a typo guard
   (the reference ladder totals ₱8,381,000), refused as a `sum` field error, never clamped.
4. **A payable level must be positive** (D-17). Enabling the stream or raising `max_levels` is refused
   unless every level `1 … max_levels` is `> 0`; the error names the level. This is the rule that keeps
   the ledger's per-level prefix gapless.
5. **The gate and the depth are small integers.** `general_harvest_max_levels` 1–10. Zero, eleven or a
   decimal is a field error. Lowering the depth never removes an award and never rewrites a row; levels
   above it stop paying and are marked *not paying at this depth*.
6. **The card tells the truth about the state.** It renders the four-state strip of Rule 9; it never
   claims the board is armed or paying when it is not.
7. **The ladder table is generated, never typed.** Slots `3^L`, the apex threshold
   `(3^(L+1) − 1)/2`, the amount from the saved row and the cumulative sum all come from
   `GeneralHarvestLevel`; the view computes none of them.
8. **The position table is generated too.** Positions #1–#6 × levels 1–4, each cell
   `3^L·(p−1) + (3^(L+1) − 1)/2`, straight from `threshold()` — the reference's §4.5 table, on screen.
9. **The state strip has exactly four states**, in this order: *unarmed* ("no baseline has been
   recorded; the arm job must run before anything can pay"), *armed and off*, *armed and on*, and
   *armed, on, with unsettled positions* (`settled_n < N`). Each names the single next action.
10. **The card shows the full-ladder total and the position dependence in the same sentence.** "Node #1
    pays ₱8,381,000 over ten levels and needs 88,573 qualified members; node #2 needs 147,622." An
    operator who types ₱5,000,000 at level 10 reads what it commits to.
11. **The calculator is an illustration, not a liability.** It is labelled *"If the queue reaches N"*,
    defaults to the current queue size, and never claims money is owed — the ledger does not exist yet
    and the card says so in one line.
12. **No JavaScript-only validation.** With JS off the form still saves and still validates; only the
    live totals are missing.
13. **Arming is not reachable from the settings page.** There is no button and no field for it; 21C owns
    the act.
14. **The amounts freeze at the arm** (D-23). While the board is armed, a save that changes any of the
    ten amounts is refused with an `armed` error naming the board's `armed_at`; the inputs are
    rendered disabled and the card says *"the board is armed: the amounts are frozen. A business reset
    is the only way to change them, and it is safe only while nothing has been paid."* The depth may
    still change, and the amounts it exposes are the frozen ones. While the board is unarmed, edits are
    free and nothing has been paid.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P21B-T1 | Open the settings card on a fresh database | Ten inputs show the reference amounts; the switch is off; the depth reads 10; the strip says *unarmed* |
| P21B-T2 | Save the reference ladder and the two knobs | Two settings persisted, ten rows written, `general_harvest.ladder.update` audit row with before/after |
| P21B-T3 | Save three blank amounts | Ten rows written; blanks stored as `0.00`; the form shows blanks for zero |
| P21B-T4 | Ladder totalling 30,000,000 | `general_harvest_amounts.sum` error; nothing written (transaction) |
| P21B-T5 | One amount `10000000.01` | Field error on that level |
| P21B-T6 | One amount with four decimals (`1000.1234`) | Field error (`decimal:2`) |
| P21B-T7 | Switch on with all ten at 0 | Error on `general_harvest_enabled` |
| P21B-T8 | Switch on with level 4 at 0 and `max_levels = 4` | Error naming level 4 (D-17) |
| P21B-T9 | `max_levels = 0` / `11` / `2.5` | Field error each time; the previous value survives |
| P21B-T10 | `max_levels = 3` with levels 4–10 at 0 | Saved; the table marks levels 4–10 *not paying at this depth* |
| P21B-T11 | `threshold(0, L)` for L = 1…10 | `4, 13, 40, 121, 364, 1,093, 3,280, 9,841, 29,524, 88,573` |
| P21B-T12 | `threshold(1, L)` and `threshold(3, L)` | `7, 22, 67, 202, …` and `13, 40, 121, 364, …` — the reference's §4.5 table exactly |
| P21B-T13 | `blockFrom/blockTo(0, L)` for L = 1…10 | `2–4, 5–13, 14–40, 41–121, 122–364, …`, each block `3^L` positions wide |
| P21B-T14 | `qFor(L, N)` at `N = 4, 13, 20, 22, 40, 121` | `N = 4` → L1 `0`; `N = 13` → L1 `3`, L2 `0`; `N = 20` → L1 `5`, L2 `0`; `N = 22` → L1 `6`, L2 `1`; `N = 40` → L1 `12`, L2 `3`, L3 `0`; `N = 121` → L1 `39`, L2 `12`, L3 `3`, L4 `0`; every level above → `−1` |
| P21B-T15 | `boardTotal(N)` for `N = 4, 13, 20, 22, 40, 121, 364, 1,093, 3,280` | `2,000 / 12,000 / 16,000 / 22,000 / 50,000 / 176,000 / 579,000 / 1,888,000 / 6,065,000` |
| P21B-T16 | `boardTotal(88,572)` and `boardTotal(88,573)` | `181,827,000` and `190,208,000`; the increment is exactly ₱8,381,000 |
| P21B-T17 | `awardCount(N)` for the same N values and `N = 88,573` | `1 / 5 / 7 / 9 / 18 / 58 / 179 / 543 / 1,636 / 44,281` |
| P21B-T18 | `earnedBy(0, 40)`, `earnedBy(1, 40)`, `earnedBy(13, 40)` | `14,000`, `6,000`, `0` |
| P21B-T19 | `nextN(0, 10)` and `nextCompletion(1,204)` | `88,573`; level 1 next needs `1,207`, level 2 `1,210`, level 3 `1,228` |
| P21B-T20 | `nextCompletion(N)` for every `N = 4…1,210` (a sweep) | For each N, the level that completes is exactly the one whose threshold equals N; no N completes more than one block per level |
| P21B-T21 | Register three members under one sponsor after configuring | No board row; no award; the wallet is unchanged (no writer yet) |
| P21B-T22 | The card's generated tables | Equal Appendix A line for line; the position table equals §12.3's table |
| P21B-T23 | Settings page with JS disabled | Saves and validates; only the live totals are missing; no PHP error |
| P21B-T24 | The card at 390 px | The ten inputs stack, both tables scroll inside the card, no page-level horizontal scroll |
| P21B-T25 | The full suite | Green; `InstallTest` still 39 / 79 / 10 / 1 (this phase adds no schema) |

**Manual gate — `QA21B-01` … `QA21B-09`**

1. `QA21B-01` — `/admin/settings` → **Commissions**: the *General Harvest bonus* card shows the ten
   reference amounts, the switch off, the depth `10`, and the strip *not armed*.
2. `QA21B-02` — change level 1 to ₱2,500 and level 10 to ₱4,000,000, save. Re-open: the values persist,
   the total reads `8,882,500.00`, and the audit desk shows `general_harvest.ladder.update`.
3. `QA21B-03` — set level 10 to ₱20,000,000 and save: the sum error appears, the values come back as
   typed and the stored ladder is unchanged.
4. `QA21B-04` — click **Load reference ladder**: the ten inputs return to the preset and the total to
   `8,381,000.00`; save.
5. `QA21B-05` — turn the switch on with all ten cleared: the error asks for an amount or the switch off.
6. `QA21B-06` — set the depth to `3` after clearing levels 4–10: accepted, and levels 4–10 are marked
   *not paying at this depth*. Set it to `11`: refused.
7. `QA21B-07` — register three members under one sponsor on `/admin/members` (or by the public form):
   `SELECT COUNT(*) FROM general_harvest_awards` → 0, and the three registrations succeed.
8. `QA21B-08` — type `1000` into the calculator: it reads **₱1,601,000.00** and **493** awards, and the
   caption calls it an illustration. With JS disabled, save the card: it saves; only the live totals are
   missing.
9. `QA21B-09` — open the card at 390 px and at 1440 px: readable, no horizontal page scroll.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P21B-T1 … P21B-T25 pass
[ ] The generated ladder table equals Appendix A, line for line
[ ] The position table equals the reference's §4.5 table, line for line
[ ] Every board total in P21B-T15/T16 is pinned by a case, to the peso
[ ] Registering three members writes no board row and no award (P21B-T21)
[ ] Tag p21b-configuration; phase log; Appendix H row
```

**Do not start 21C until every box is ticked.**

**Rollback**

Turn the switch off and revert the code. The ladder rows and the two settings may stay: they are inert
and a later attempt can reuse them. No money exists to reverse.

**Hand-off to 21C**

- `GeneralHarvestLevel` is the only way any later phase may read an amount, a threshold, a block range
  or a board total.
- `GeneralHarvestBoard::state()`, `queueN()`, `frontier()` and `backlog()` are the only way to read the
  board's condition.
- The ladder is validated, audited and rendered; 21C may assume a configured ladder and an unarmed
  board, and must work when `N = 0`.

**Risks**

| Risk | Mitigation |
|---|---|
| An operator types a ten-fold amount and commits the company | The per-level ceiling, the ₱25M total ceiling, the full-ladder total in the same sentence, and the audit row (Rules 2, 3, 10) |
| The generated table drifts from the model | The view calls `GeneralHarvestLevel` for every cell; P21B-T22 compares it with Appendix A and §12.3 |
| A refused save half-writes the settings | P21B-T3/T14 assert both stores are unchanged; one transaction |
| The arithmetic is right on the apex and wrong on position #4 | P21B-T12/T13 pin positions #2–#4; P21B-T20 sweeps every N to 1,210 |
| An operator enables the stream and expects money | The strip says *not armed* and names the arm job; nothing pays until 21C and 21D exist (P21B-T21) |

---

## 7. Phase 21C — The baseline

**Goal.** The board has a **go-live baseline**: one recorded queue length `N₀`, one `baseline` row for
every block that was already full at that instant, and a board that can never pay a pre-go-live block.
**No money moves: there is not one credited row after this phase.**

**Builds on.** 21A (the tables), 21B (the configured ladder and the arithmetic).

**Ships alone because.** The baseline is not a payment: every row it writes is `status = 'baseline'`
with `ledger_id NULL`, and the phase's gate asserts that the wallet is untouched. It is this stream's
**irreversible** decision — which blocks count as pre-go-live can never be revisited — so it must be
provable before any award depends on it. If 21D were cancelled, the board would still hold a correct,
auditable record of exactly what the network had already earned before the stream existed.

**Effort.** 2.5 ideal days. **Suite.** ≥ 1,384 cases.

**Reference.** §12.6 (the baseline rule), §14.3 and §14.10 (the arm procedure and the job), §13.2 (the
board row), §13.8 (invariants I-04, I-06, I-09).

**Deliverables**

**Database.** None. 21A shipped every column this phase writes.

**Code**

- `system/app/Models/GeneralHarvestAward.php` (new) — the ledger model, read and baseline only in this
  phase: `has(int $seq, int $level)`, `writeBaseline(int $seq, array $snapshot)`, `forMember(int $userId)`,
  `countByLevel()`, `minMaxByLevel()`, `select(array $filters, Paginator $pager)`, `totals(array $filters)`,
  `byLevel()`, `byMonth()`, `recent()`, `exportRows(array $filters)`. No `type` column: the node and the
  level are the whole story. The model never opens a transaction of its own; it runs inside its caller's.
- `system/app/Services/GeneralHarvestService.php` (new, **shared file**: 21C owns arm and state; 21D adds
  settlement) — `enabled()`, `armed()`, `baseline()`, `arm(bool $dryRun, ?array $actor)`,
  `excluded()`, `state()`, `assertArmable()`.
- `system/app/Services/GeneralHarvestException.php` (new) — `enabled()`, `armed()`, `unseeded()`,
  `notGapless()`, `invalidLadder()`, `state()`, the same shape as `TeamHarvestException` /
  `RoyaltyException`.
- `system/bin/general_harvest_arm.php` (new) — the one-time job: flags `--dry-run`, `--json`,
  `--quiet`, `--help`; a `GET_LOCK('general_harvest_arm', 5)` guard; one transaction; the baseline rows
  and the board row.
- `system/bin/general_harvest_arm.cmd` (new) — the Windows launcher, like the other `.cmd` files.
- `system/app/Models/AuditLog.php` — no change: the job writes the shared `general_harvest.arm` action
  through the existing helper.

**Docs.** `docs/qa/phase-21-general-harvest.md` gains section C (`QA21C-01` … `QA21C-08`).
`docs/runbook.md` gains the **go-live order, part 1** (deploy → configure → arm dry run → arm → enable)
and the "arm refused" drill. `system/README.md` gains the board row and the job.

**Not yet.** No settlement, no wallet movement, no credited row, no member page, no admin page. The
board is armed and inert.

**Rules**

1. **Arm is a one-time act.** `board.armed_at` non-null means the board has a baseline; a second arm is
   refused, with no `--force`. From that instant the ten amounts are **frozen** (D-23): a ladder save is
   refused until a business reset unarms the board. A business reset returns the board to unarmed and a
   new arm starts a new network's history (Rule 8).
2. **Arm is CLI only.** `PHP_SAPI === 'cli'`; no web entry and no settings-page button ships for it, so
   there is no URL to authenticate and no way to arm from a stale form.
3. **Arm refuses while the stream is enabled.** The interlock is `enabled() = false`; the message says
   "switch the stream off before arming". This makes the order `configure → arm → enable` the only one
   that works.
4. **Arm refuses unless the queue is live.** `team_harvest_ledger_since` (Phase 20's seed marker) must
   be a valid datetime; otherwise the queue has no history and `N₀` would be meaningless.
5. **Arm refuses an invalid ladder.** **All ten** levels must be `> 0` and the total `> 0`. The
   ten-level requirement is deliberate: the amounts freeze at this instant (D-23), so a level priced at
   zero could never be paid even if the depth were raised later. The dry run prints the amount each
   level would carry, so the operator sees the whole commitment before writing it.
6. **Arm writes a row for every block already full, at all ten levels** — including levels above
   `max_levels` — so raising the depth later can never pay a pre-go-live block. The row set is exactly
   `seq = 0 … qFor(L, N₀)` for each level `L`.
7. **Arm writes no credited row and no wallet movement.** The phase's gate runs the whole fixture and
   asserts `COUNT(status = 'credited') = 0`, `COUNT(ewallet_ledger WHERE ref_type = 'general_harvest') =
   0`, and the sum of every wallet balance unchanged.
8. **The baseline is deterministic.** Two dry runs on the same queue print the same `N₀`, the same row
   count and the same excluded amount; the rows are a pure function of `N₀` and the queue order.
9. **`settled_n` is set to `N₀`.** The board claims to be settled up to the baseline because every block
   up to `N₀` is recorded — as `baseline`, not as paid. This is what makes `settled_n` a position count
   and not a money count.
10. **The excluded amount is printed before anything is written** and equals `boardTotal(N₀)` to the
    peso. The dry run is the decision surface; the real run is one transaction and one audit row.
11. **The queue must be gapless** (D-03). The job refuses with the anomaly named if `COUNT(*) <> MAX(id)`.
12. **Arm never touches the queue.** It reads `id` and `COUNT(*)`; it writes only the ledger and the
    board row.

**Test plan**

Fixtures: the ternary trees of §16.2 (`h` = height), the baseline fixture (F), the cycle and staff
fixtures.

| ID | Test | Expected |
|---|---|---|
| P21C-T1 | Arm fixture B (40 accounts, `N = 13`) | 5 baseline rows: level 1 seqs 0–3 (₱2,000 each) and level 2 seq 0 (₱4,000); total **₱12,000.00**; board `baseline_n = 13`, `settled_n = 13`, `armed_at` set |
| P21C-T2 | Arm again | Refused (`armed`), no row written, the board unchanged |
| P21C-T3 | Arm with `general_harvest_enabled = 1` | Refused (`enabled`); nothing written |
| P21C-T4 | Arm with `team_harvest_ledger_since = ''` | Refused (`unseeded`); nothing written |
| P21C-T5 | Arm with level 9 at `0.00` (a level above `max_levels = 3`) | Refused (`invalidLadder`), the level named — the whole ladder must be priced before the freeze (D-23) |
| P21C-T6 | Arm on an empty queue (`N₀ = 0`) | 0 rows; board armed with `baseline_n = 0`, `settled_n = 0` |
| P21C-T7 | Arm fixture C (121 accounts, `N = 40`) | 18 baseline rows; total **₱50,000.00**; per level: 13 / 4 / 1 rows |
| P21C-T8 | `--dry-run` on fixture C | Prints `N₀ = 40`, 18 rows, ₱50,000.00, the first 20 rows and the per-level frontier; writes **nothing** (row count and board unchanged) |
| P21C-T9 | The whole phase fixture | `credited` rows **0**; `ewallet_ledger` `general_harvest` rows **0**; every wallet balance unchanged |
| P21C-T10 | Any baseline row | `status = 'baseline'`, `ledger_id IS NULL`, `n_at_award = N₀`, `threshold_n = 3^L·seq + (3^(L+1) − 1)/2`, `amount` = the configured amount at arm |
| P21C-T11 | Rows per level | Exactly `0 … qFor(L, N₀)`; `MIN(seq) = 0`, `MAX(seq) = qFor`, `COUNT = qFor + 1` for every level with completions |
| P21C-T12 | Arm on a queue with a hole (delete one row directly) | Refused (`notGapless`) naming `N` and `MAX(id)`; nothing written |
| P21C-T13 | The audit row | `general_harvest.arm` with `N₀`, the row count, the excluded amount and the actor |
| P21C-T14 | Two concurrent arm runs | One succeeds, the other is refused by the advisory lock; total rows = one baseline set |
| P21C-T15 | The board's singleton and its CHECKs | A second row is refused; `baseline_n`/`armed_at` are both NULL or both set |
| P21C-T16 | A fresh install (no seed, no queue) | The board is unarmed; the arm job refuses (`unseeded`), which is the correct go-live order |
| P21C-T17 | A business reset, then arm again | The board is unarmed after the reset; the second arm uses the new `N` and writes a fresh baseline |
| P21C-T18 | `state()` after arming | `armed = true`, `baseline_n = N₀`, `settled_n = N₀`, `backlog = N − N₀`, `enabled = false`; and a settings save that changes an amount is refused (`armed`, D-23) |
| P21C-T19 | Arm after the ladder was raised from 3 to 10 levels | Baseline rows exist for all ten levels' complete blocks at `N₀` |
| P21C-T20 | `--json` | Documented keys only: `armed`, `n`, `rows`, `excluded`, `per_level`, `dry_run` |
| P21C-T21 | The full phase suite | The wallet is untouched (asserted once at the end, like P21C-T9) |

**Manual gate — `QA21C-01` … `QA21C-08`**

1. `QA21C-01` — on a scratch copy with Phase 20 seeded and the reference ladder saved, run
   `php bin/general_harvest_arm.php --dry-run`: it prints `N₀`, the row count, the excluded amount and
   the first positions; `SELECT COUNT(*) FROM general_harvest_awards` is still 0.
2. `QA21C-02` — run `php bin/general_harvest_arm.php`: it prints the same numbers and commits.
   `SELECT COUNT(*), SUM(amount) FROM general_harvest_awards` → the row count and the excluded amount;
   `SELECT * FROM general_harvest_board` → armed, `baseline_n = settled_n = N₀`.
3. `QA21C-03` — `SELECT level, COUNT(*), MIN(seq), MAX(seq) FROM general_harvest_awards GROUP BY level`:
   each level is a contiguous prefix starting at 0.
4. `QA21C-04` — run it again: "already armed", 0 rows written, the board unchanged.
5. `QA21C-05` — turn the switch on and re-run: refused, "switch the stream off before arming".
6. `QA21C-06` — `SELECT status, COUNT(*) FROM general_harvest_awards GROUP BY status` → one row,
   `baseline`, and `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest'` → 0.
7. `QA21C-07` — open `/wallet` as a member who holds a baseline row: no General Harvest movement appears
   (the money does not exist yet).
8. `QA21C-08` — run the business reset: the ledger is empty, the board is unarmed, the ladder survives,
   and the arm job can run again on the new network.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P21C-T1 … P21C-T21 pass
[ ] The arm is deterministic (two dry runs identical) and one-time (a second real run writes nothing)
[ ] The baseline row count and the excluded amount equal the closed forms, level by level
[ ] No credited row and no wallet movement exists anywhere in the phase's tests (P21C-T9)
[ ] Tag p21c-baseline; phase log; Appendix H row
```

**Do not start 21D until every box is ticked.**

**Rollback**

Turn the money switch off (it is already off) and revert the code. The baseline rows may stay: they are
a correct record of what was already earned at go-live, and a later attempt can reuse them. If the
decision is to abandon the stream entirely, `UPDATE general_harvest_board SET baseline_n = NULL,
armed_at = NULL, armed_by = NULL, settled_n = 0` and `TRUNCATE general_harvest_awards` — no money exists
to reverse.

**Hand-off to 21D**

- `armed()`, `baseline()` and `state()` are the only way the settlement may learn the board's standing;
  the settlement never reads `harvest_queue`'s size without comparing it with `settled_n`.
- Every block full at `N₀` has a `baseline` row, for all ten levels, with its amount and no ledger id.
- The board row's `settled_n = N₀`, so the settlement's target is always `settled_n + k`.
- **Nothing pays.** 21D may assume the board is armed and must work with `N = 0`.

**Risks**

| Risk | Mitigation |
|---|---|
| The baseline is taken twice and the second silently shifts the exclusion | `armed_at` is the flag; the job refuses; the lock serialises; P21C-T2/T14 |
| The baseline is taken at the wrong `N` (after a deploy that queued members) | The dry run prints `N₀` and every row before writing; the runbook's order puts the arm before the switch and before any announcement |
| An operator arms with a zero ladder and the excluded amount reads ₱0 | Rule 5 refuses an invalid ladder; the dry run's amount is the decision surface |
| Arm runs while registrations are arriving | The advisory lock plus one transaction; registrations either commit before the arm (and are counted in `N₀`) or after (and are settled later) |
| A baseline row is later credited | The settlement checks `q ≤ qFor(L, baseline_n)` **and** finds the row already present; the `baseline` status is also a schema CHECK on `ledger_id` (§13.4) |
| A reset leaves a stale baseline | Rule 8 and the reset service's UPDATE; P21C-T17 |

---

## 8. Phase 21D — Money engine

**Goal.** A block that fills pays its configured amount, once, into the node holder's withdrawable
e-wallet, with an immutable ledger row — settled on every queue append (one position inline) and by an
on-demand catch-up, never paying a pre-go-live block. **This is the only phase that moves money.**

**Builds on.** 21C (the armed board, the baseline rows).

**Ships alone because.** Money is complete and correct at `p21d-money`: every award is visible in the
existing wallet statements with the `general_harvest` ref type this phase credits, and
`bin/wallet_reconcile.php` proves every total against the closed form. No page is *required* for the
phase to be correct — the admin and member pages of 21E/21F only **describe** what this phase already
does.

**Effort.** 3.5 ideal days — the largest phase by design. **Suite.** ≥ 1,430 cases.

**Reference.** §12.5–§12.8, §13.8 (invariants), §14 in full, §16.5 (the pure-function table).

**Deliverables**

**Database.** None. (Awards use the columns shipped in 21A.)

**Code**

- `system/app/Models/GeneralHarvestAward.php` — extended: `create(int $seq, int $level, array $snapshot)`,
  `writeCredited(...)`, `linkLedger(int $awardId, int $ledgerId)`, `paidLevels(int $userId)`,
  `levelMap(int $userId)`, `gridFor(int $userId)` (the ten-cell board card), plus the filter/total API
  already shipped in 21C. No row is ever updated except `ledger_id`, once, in the same transaction that
  wrote it.
- `system/app/Services/GeneralHarvestService.php` — extended: `settle(int $maxNewPositions = 1, ?array
  $actor = null)`, `settleAll(int $batch = 500)`, `onQueueChanged()`, `statusFor(int $userId)`,
  `openLiability()`, `reconcile()`, `doctor()`, `settledN()`, `backlog()`.
- `system/app/Services/GeneralHarvestException.php` — `state()` gains the anomaly codes of §14.12.
- `system/app/Services/RegistrationService.php` — **one added line**, inside the existing registration
  transaction and **after** Phase 20's call:
  `(new GeneralHarvestService($this->pdo))->onQueueChanged();`
  This is the only change to a registration path in the whole plan, and it is the phase's riskiest line
  (§17 risk 1).
- `system/app/Services/Live.php` — `touchGeneralHarvestAdmin()` and `touchGeneralHarvestMember(int $userId)`:
  the admin surfaces (`admin.commissions`, `admin.dashboard`, `admin.reports`, and the awarded member's
  `admin.members:{id}`) and the member surfaces (`member.earnings:{id}`, `member.dashboard:{id}`,
  `member.wallet:{id}`, `member.genealogy`), bumped **after** the commit, never inside the wallet
  transaction.
- `system/app/Services/DatabaseResetService.php` — the integrity map gains
  `'general_harvest' => ['label' => 'General Harvest reconciliation', 'service' => 'GeneralHarvestService']`
  (deferred from 21A on purpose: the class now exists).
- `system/bin/general_harvest_catchup.php` (new) — the on-demand settlement and doctor: flags
  `--dry-run`, `--member=ID`, `--limit=N`, `--doctor`, `--json`, `--quiet`, `--help`; the shared lock and
  log conventions; exit 0 (clean), 1 (refused or drift), 2 (unreachable database).
- `system/bin/general_harvest_catchup.cmd` (new) — the Windows launcher.
- `system/bin/wallet_reconcile.php` — a **General Harvest** section, included in the exit code.

**Docs.** `docs/qa/phase-21-general-harvest.md` gains section D (`QA21D-01` … `QA21D-10`).
`docs/runbook.md` gains the settlement/reconcile drill and the go-live order's part 2 (enable → catch up
→ reconcile → doctor). `system/README.md` gains the engine, the ref type and the catch-up job.
`docs/cron.md` states, once, that this stream adds **no** cron entry and why.

**Not yet.** No ledger page, no board card, no member page, no report card, no settings change (the card
is 21B's), no `APP_PHASE` change.

**Rules**

1. **One hook, one transaction.** `onQueueChanged()` runs inside the registration transaction, after
   Phase 20's qualification call. When the catch-up calls `settle()` directly, the service opens its own
   transaction per batch and commits it whole (§14.7).
2. **Award and wallet credit commit together.** The engine never writes an award with no wallet row, or
   a wallet row with no award. A failure propagates; the caller rolls back (§14.7).
3. **Idempotent at three layers** (§14.6): the `UNIQUE (seq, level)` anchor, the prefix frontier read
   and the wallet's own `(user_id, ref_type, ref_id, type)` anchor. A second settlement moves nothing.
4. **State-based, not event-based** (D-19). The settlement compares `N` and the board's `settled_n` with
   the ledger, so a missed registration, a rolled-back transaction or a late enablement converges on
   the next position or the catch-up.
5. **A level pays once, ever.** No row is ever updated except `ledger_id`, once, and no row is ever
   deleted. `status` is `credited` or `baseline`, and both consume the `(seq, level)` key.
6. **The baseline is honoured exactly.** A block whose `seq ≤ qFor(L, baseline_n)` is written `baseline`
   with its amount and `ledger_id NULL`, and is **never** credited — not at enablement, not by a later
   settlement, not by the catch-up (D-06).
7. **Only levels up to `max_levels` are credited.** A complete block above the configured depth is
   reported by `statusFor()` as *not paying at this depth* and is written when the depth is raised, once.
8. **A zero-amount level is never paid.** It is skipped without a row, reported as *unpriced* by
   `statusFor()` and the doctor, and paid the moment the amount is set (D-17).
9. **The amount is snapshotted, and the ladder is frozen while armed** (D-23). `amount`, `threshold_n`
   and `n_at_award` are written on the row from the ladder and the queue at that instant; because the
   ten amounts cannot change after the arm, every row for a level carries the same amount — the
   snapshot is the audit record and the defence against a hand-edited ladder row, not a routine
   occurrence.
10. **No money while the switch is off or the board is unarmed.** `enabled() = false` → no row, no
    wallet, the settlement returns `off`; `armed() = false` → `unarmed`. The queue still grows (D-10).
11. **Inline settlement is exactly one position** (D-09); `settle()` never processes more than
    `maxNewPositions` positions, and the hook passes `1`. The catch-up passes its batch size.
12. **Recipients are ordered before any wallet lock.** The batch is collected, sorted by
    `(user_id, level, seq)` and only then written, so two batches that share a member cannot deadlock
    (§14.8).
13. **The company account and staff are never recipients**, because they are never queued (Phase 20's
    rule); the engine asserts it anyway and throws rather than pay a `role <> 'member'` row.
14. **Reconcile is part of the phase**, not a later add-on: `reconcile()` proves the ledger, the wallet
    and the closed form agree, and `wallet_reconcile.php` fails the build when they do not (§14.13).

**Test plan**

Fixtures: the ternary trees of §16.2, the baseline fixture (F), the 20-member reference tree (G), and the
edge family (H: off, unarmed, hole, cycle, staff). All money cases use the reference ladder, the stream
on, the board armed at `N₀ = 0` unless the case names fixture F.

**Awards**

| ID | Test | Expected |
|---|---|---|
| P21D-T1 | Fixture `h = 1` (13 accounts, `N = 4`) | One award: node 0, level 1, ₱2,000.00, `threshold_n = 4`, `n_at_award = 4` |
| P21D-T2 | Fixture `h = 2` (40 accounts, `N = 13`) | 5 awards, **₱12,000.00**: node 0 L1 2,000 + L2 4,000; nodes 1–3 L1 2,000 each |
| P21D-T3 | Fixture `h = 3` (121 accounts, `N = 40`) | 18 awards, **₱50,000.00**; node 0 = ₱14,000.00 (L1–L3); nodes 1–3 = ₱6,000.00; nodes 4–12 = ₱2,000.00; nodes 13+ = ₱0 |
| P21D-T4 | Fixture `h = 4` (364 accounts, `N = 121`) | 58 awards, **₱176,000.00**; node 0 = ₱26,000.00; nodes 1–3 = ₱14,000.00; nodes 4–12 = ₱6,000.00; nodes 13–39 = ₱2,000.00 |
| P21D-T5 | Fixture `h = 5` (1,093 accounts, `N = 364`) | 179 awards, **₱579,000.00** |
| P21D-T6 | The 20-member stopped tree of §16.2 | 7 awards, **₱16,000.00**: node 0 ₱6,000.00, nodes 1–5 ₱2,000.00, node 6 ₱0 (needs `N = 22`) — the reference's §4.7 table exactly |
| P21D-T7 | `earnedBy(seq, n)` and `boardTotal(n)` for the pure table | Every row of §16.5 to the peso, including `88,572 → 181,827,000` and `88,573 → 190,208,000` |
| P21D-T8 | An award's wallet row | One *General Harvest bonus* credit, `ref_type = 'general_harvest'`, `ref_id` = the award id, withdrawable bucket up by the amount |
| P21D-T9 | An award row | `status = 'credited'`, `ledger_id` set, `credited_at` set, `created_at` set, amount = the ladder amount |
| P21D-T10 | The same tree, the other stream | Team Harvest pays member 1 ₱1,000.00 on the reference 7-member tree while General pays ₱4,000.00 across nodes 0–1 — the two streams disagree on the same tree, by design |

**Baseline, depth and the switch**

| ID | Test | Expected |
|---|---|---|
| P21D-T11 | Fixture F: arm at `N = 13`, grow to `N = 40`, settle | 13 credited rows, **₱38,000.00**; node 0 receives only L3 ₱8,000.00 (L1–L2 are baseline); nodes 1–3 receive L2 ₱4,000.00; nodes 4–12 receive L1 ₱2,000.00 |
| P21D-T12 | The same fixture, node 0's wallet | Exactly ₱8,000.00, never ₱14,000.00 |
| P21D-T13 | `baseline_n = 40` and a settlement runs | Nothing is credited; levels 1–3 are baseline rows; `statusFor()` says *before go-live* |
| P21D-T14 | A node queued after the arm (`seq > qFor(L, N₀)` for every level) | All its completed levels credit normally |
| P21D-T15 | `max_levels = 2` at `N = 40` | 17 rows, **₱42,000.00**; level 3 (node 0) is complete but unwritten; `statusFor()` marks it *not paying at this depth* |
| P21D-T16 | Raise the depth to 3, then settle | Exactly one new award, ₱8,000.00; levels 1–2 do not re-pay |
| P21D-T17 | Level 5 hand-edited to `0.00` while the board is armed, then a settlement at `N = 364` | Level 5 writes no row and no money; the settlement reports `unpriced = [5]`; restore the row directly to ₱25,000.00 and settle → one row, ₱25,000.00 |
| P21D-T18 | `general_harvest_enabled = 0`, grow the queue to `N = 40` | Queue rows exist; awards **0**; wallets unchanged; `settle()` returns `off` |
| P21D-T19 | Turn it on, then run the catch-up | The backlog pays exactly once in board order; a second run pays nothing |
| P21D-T20 | Unarmed board (`baseline_n IS NULL`) | `settle()` returns `unarmed`; no row, no exception, no wallet movement |
| P21D-T21 | A suspended node holder completes a block (D-14) | Award credited; the payout request path still refuses them (existing rule) |
| P21D-T22 | A staff account as the node holder | Impossible to queue (Phase 20); a direct `settle()` for it writes nothing and reports *not a member* |
| P21D-T23 | `openLiability()` before settling a backlog | Equals `boardTotal(N) − boardTotal(settled_n)`, to the peso, and equals the catch-up's dry run |
| P21D-T24 | `statusFor()` for a node with 0, 1, 3 and 4 completed levels | Next level, block range, filled/slots, remaining, percent, amount, baseline state and paid total, all exact |

**Idempotency, depth changes and history**

| ID | Test | Expected |
|---|---|---|
| P21D-T25 | Attempt to change level 3 from ₱8,000 to ₱10,000 after the board is armed | Refused (`armed`, D-23); the ladder, the board and the ledger are unchanged. A *directly hand-edited* ladder row is a different matter: it affects only rows written afterwards, and every existing row keeps its own amount (the snapshot rule) |
| P21D-T26 | `settle()` twice on the same `N` | Second call writes nothing (`written = 0`), rows and balances unchanged |
| P21D-T27 | `onQueueChanged()` called twice for one registration | One set of rows; the second returns `replayed` |
| P21D-T28 | Duplicate `(seq, level)` insert | `PDOException` 23000 — the unique anchor holds |
| P21D-T29 | `settle(1)` repeated 27 times from `settled_n = 13` to `N = 40` | The final ledger is byte-identical to one `settle(27)`; every intermediate state is a valid prefix |
| P21D-T30 | Three sequential settlements of the same node in one transaction each | One set of rows; the losers write nothing and throw nothing |
| P21D-T31 | A queue with a hole, then `settle()` | Throws `state('not gapless')`; nothing written; the doctor reports it |
| P21D-T32 | A hand-edited `settled_n` above the ledger's rows | Throws `state('ledger behind settled_n')`; nothing written; reconcile fails |
| P21D-T33 | `settle()` inside the registration transaction (fixture `h = 1`) | The user row, the queue row and the award exist; a forced wallet failure rolls **all three** back |
| P21D-T34 | `settle()` called when `N < settled_n` (a deleted queue row) | Throws `state('queue shrank')`; the registration itself still succeeds when the hook catches the anomaly (Rule 9 of §12.7) |

**Integrity and reconciliation**

| ID | Test | Expected |
|---|---|---|
| P21D-T35 | `reconcile()` on a clean fixture-A run | `ok = true`; `Σ credited = ₱50,000.00`; `Σ baseline = ₱0`; `Σ wallet = ₱50,000.00`; per-level rows equal `qFor(L, settled_n)+1`; zero drift |
| P21D-T36 | Delete one wallet ledger row, then reconcile | Mismatch reported with the award id; `ok = false`; `wallet_reconcile.php` exits non-zero |
| P21D-T37 | Delete one award row, then reconcile | Prefix check fails (`COUNT <> MAX(seq)+1`); the level and the missing `seq` are named |
| P21D-T38 | Hand-edit one `amount` in the ledger | The per-level total no longer equals `amount(L) × rows`; reconcile fails |
| P21D-T39 | `wallet_reconcile.php` on a clean build | Exit 0 with a **General Harvest** section reporting paid, baseline, rows and no mismatch |
| P21D-T40 | The reset's integrity pack | Runs `GeneralHarvestService::reconcile()` and passes after a business reset (0 rows, unarmed board) |
| P21D-T41 | `bin/general_harvest_catchup.php --dry-run` on a backlog | Prints `N`, `settled_n`, the per-level missing rows, the open liability; writes nothing |
| P21D-T42 | `bin/general_harvest_catchup.php --doctor` on a clean build | Clock, tables, settings, armed state, gapless, queue count, settled, backlog, per-level frontier, paid, baseline, open and the reconcile verdict; exit 0 |
| P21D-T43 | `bin/general_harvest_catchup.php --limit=1` | Settles one position per transaction; the summary reports batches and the final `settled_n` |
| P21D-T44 | The catch-up against an unarmed board | Exit 1, message naming the arm job; nothing written |

**Manual gate — `QA21D-01` … `QA21D-10`**

No new page appears in this phase. Verify through the wallet statements, the tables and SQL.

1. `QA21D-01` — on an armed scratch copy with the reference ladder and the switch **on**, register the
   members of fixture `h = 1` in BFS order. `SELECT * FROM general_harvest_awards WHERE seq = 0` →
   level 1, ₱2,000.00, `status = credited`.
2. `QA21D-02` — as that member, open `/wallet`: one **General Harvest bonus** credit of ₱2,000.00, and
   the statement filter's new option matches it.
3. `QA21D-03` — `/admin/wallets` → the same credit with `ref_type = general_harvest` and the award id as
   `ref_id`.
4. `QA21D-04` — `php bin/wallet_reconcile.php` → exit 0 and a **General Harvest** section reporting the
   total and no mismatch.
5. `QA21D-05` — build fixture `h = 3` (121 accounts) and register the last member: `N = 40`,
   `SELECT COUNT(*), SUM(amount) FROM general_harvest_awards` → 18, 50,000.00; node 0's wallet has
   ₱14,000.00 across three movements.
6. `QA21D-06` — register one more member who does not qualify anybody: no new award, no new credit, the
   totals unchanged.
7. `QA21D-07` — set `general_harvest_enabled = 0`, register three members under a new sponsor: the queue
   grows, the awards do not. Turn it on and run `php bin/general_harvest_catchup.php --dry-run` (the
   amount listed) then for real: exactly the missing awards, once.
8. `QA21D-08` — arm at `N = 13`, then grow to `N = 40`: `SELECT level, status, amount FROM
   general_harvest_awards WHERE seq = 0` shows levels 1–2 `baseline` and level 3 `credited` 8,000.00;
   the wallet holds ₱8,000.00 only.
9. `QA21D-09` — `php bin/general_harvest_catchup.php --doctor` → exit 0 with every line green.
10. `QA21D-10` — hand-delete one `ewallet_ledger` row and re-run the reconciler: it reports the award and
    exits non-zero. Restore from the backup.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P21D-T1 … P21D-T44 pass
[ ] Every amount in §16.3 is pinned by a case, to the peso
[ ] No credited row exists for a seq ≤ qFor(L, baseline_n) (asserted in the suite)
[ ] A level never pays twice: the unique anchor, the replay test and the catch-up test all green
[ ] `wallet_reconcile.php` exits 0 with a General Harvest section, and non-zero when a ledger row is removed
[ ] `php bin/general_harvest_catchup.php --doctor` exits 0 on a clean build
[ ] Tag p21d-money; phase log; Appendix H row
```

**Do not start 21E or 21F until every box is ticked.**

**Rollback**

1. Set `general_harvest_enabled = 0` — from that second no new award is written, and the queue keeps
   recording positions for when the stream is fixed.
2. Only then revert the code. Any awards already credited stay in the wallets and in
   `general_harvest_awards`: they are real money, and a code rollback does not and must not delete them.
   The ledger remains reconcilable because `wallet_reconcile.php` reads the tables, not the code path.
3. A decision to *unpay* is a business decision with a forward-only path (§18 Q3), not a rollback.

**Hand-off to 21E/21F**

- Awards exist with a stable row shape (`forMember()`, `select()`, `totals()`, `byLevel()`, `byMonth()`,
  `exportRows()`) that 21E and 21F only render.
- `statusFor()` returns everything a member card or an admin panel needs: position, node, settled state,
  the ten levels with block ranges and fill, paid levels, next level, remaining, baseline state, paid
  total and the open amount for that node.
- `openLiability()` and `backlog()` exist for the settings strip and the admin board card.
- `reconcile()` and the doctor exist, so every number a page shows has a proof behind it.

**Risks**

| Risk | Mitigation |
|---|---|
| The hook breaks registration — the application's most critical path | One line inside an existing transaction, guarded by `enabled()`/`armed()`; the phase's first tests include the rollback case (P21D-T33); the smoke suite registers members |
| A double credit from a raced registration | Three idempotency layers (§14.6); the unique anchor; P21D-T26/T28 |
| A deadlock between two registrations sharing a node | Ascending recipient order, the board row lock, one transaction per batch (§14.8) |
| The baseline is bypassed and a pre-go-live block pays | The rule is expressed twice (`seq ≤ qFor(L, baseline_n)` and the `baseline` row) and pinned by P21D-T11–T13 |
| The hook's exception is swallowed "to keep registration working" | Rule 2: money failures propagate and the caller rolls back; configuration anomalies never throw (§12.7) |
| A ladder edit rewrites a past award | The ladder is frozen from the arm (D-23); a hand-edited row affects only future rows (Rule 9); P21D-T25 |
| The ledger and the closed form disagree on a large N | `boardTotal()` is the independent expectation; P21D-T7/T35; the doctor prints both |
| A big backlog is settled inside a registration | Rule 11 caps the inline settlement at one position; the catch-up batches |

---

## 9. Phase 21E — Admin visibility

**Goal.** Finance can see, filter and export every General Harvest award, open the node holder who
earned it, and read the board itself — how far each level has filled, what the block frontier is, what
has been paid, what was excluded at go-live, and what the network still owes. **Read-only: this phase
adds no money path.**

**Builds on.** 21D (the awards and the status API it renders).

**Ships alone because.** It is pure presentation over rows 21D already writes and over the arithmetic
21B already proves. If 21F never shipped, the operator still has a complete audit tool — which is
exactly what a controlled money feature needs first.

**Effort.** 1.5 ideal days. **Suite.** ≥ 1,454 cases.

**Reference.** §14.2 (the API these pages call), §13.8 (the invariants the pages surface), §12.12 (the
board's economics the report cards show), Appendix C.1–C.2 and C.5 (the copy and the CSV columns).

**Deliverables**

**Code**

- `system/app/Controllers/AdminCommissionController.php` — `ledger=general_harvest` selects the fifth
  tab; filters pass through `GeneralHarvestAward::normaliseFilters()`; `export` writes the same columns
  for the tab and audits `general_harvest.export`.
- `system/app/Controllers/AdminMemberController.php` — the board summary for the list page, the
  `?board=paid|none` filter, and the member's General Harvest panel data for the show page.
- `system/app/Models/Member.php` — `normaliseFilters()` gains `board` (`paid|none`, anything else means
  "no filter"), `whereFor()` gains the matching `EXISTS`/`NOT EXISTS` on `general_harvest_awards`, and
  `adminList()` selects the member's node index and credited-level count in the same query (no N+1).
- `system/app/Models/Report.php` — `generalHarvestSummary()`, `generalHarvestByLevel()`,
  `generalHarvestByMonth()`, `generalHarvestBands()`, `generalHarvestBoard()`.
- `system/app/Services/ReportService.php` — the four cards, `type=general_harvest` in the range export,
  and the dashboard **General Harvest (30 d)** KPI.
- Views (new): `admin/commissions/_general_harvest_header.php`, `_general_harvest_stats.php`,
  `_general_harvest_levels.php`, `_general_harvest_rows.php`, `_general_harvest_pagination.php`;
  `admin/members/_general_harvest.php`; `admin/reports/_general_harvest.php`.
- Views (modified): `admin/commissions/index.php` (the fifth pill), `admin/members/index.php` (the board
  card and the filter), `admin/members/_rows.php` (the `GH` column), `admin/members/show.php` (the
  panel), `admin/reports/index.php` (the cards).

**Docs.** `docs/qa/phase-21-general-harvest.md` gains section E (`QA21E-01` … `QA21E-08`).
`docs/admin-guide.md` gains the ledger, the board card and the member panel. `docs/deploy.md` notes the
new audit actions.

**Not yet.** No member-facing surface at all. A member sees only their wallet credit (21D).

**Rules**

1. **Read-only.** No controller, model or view in this phase writes an award, a wallet row or the board
   row.
2. **The four existing tabs are untouched.** Package, repeat-purchase, Royalty and Team Harvest markup,
   filters, counts and exports stay byte-identical; `P21E-T1` is a regression test, not a formality.
3. **No new route.** `?ledger=general_harvest`, `?board=paid|none` only (D-15). `routes.php` and
   `routes_inventory.php` are untouched; `ParityTest` stays green.
4. **Baseline is not credited money.** Every *Paid* figure counts `status = 'credited'` only; baseline
   rows appear in their own tile and their own column, never inside a paid total (Rule 4 of §12.5).
5. **Nodes are displayed, never derived.** The `GH` column and the panel read `seq`/`position` from the
   ledger and the queue; the page never recomputes a position from a count.
6. **Every row links to its member and its wallet movement.** A finance reviewer can walk from a number
   to the member and to the ledger row in one click each.
7. **The board card explains its empty states.** Four of them, each naming the next action: *not armed*
   (run the arm job), *armed but nobody has a completed block* (the first completes at `N = 4`), *armed
   with a backlog* (`settled_n < N`; run the catch-up), *armed and settled* (the frontier and the open
   liability).
8. **The open liability is a number, not a warning.** The card shows `openLiability()`, the closed form
   `boardTotal(N) − boardTotal(settled_n)`, labelled *not yet paid*.
9. **The board card shows the frontier, per level**: rows written, the next `seq` and the `N` at which
   it completes, and the amount. It is the operator's map of what is about to pay.
10. **Filters fall back silently.** An unknown `level`, `status`, date or search means "no filter",
    exactly like [`Commission::normaliseFilters()`](../system/app/Models/Commission.php#L140-L172).
11. **Admin copy may use the plan's vocabulary** — node, seq, position, block, frontier, baseline,
    board. The member-facing restrictions of 21F do not apply here, and the admin pages are where the
    exact terms belong.
12. **The level distribution is always the ten levels**, including levels with no awards and levels
    above `max_levels` (marked *not paying at this depth*), so a reader sees the shape of the plan and
    not just the paid part.
13. **The report cards reconcile with the ledger tab.** Both call the same model methods;
    `P21E-T13` compares them.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P21E-T1 | `/admin/commissions` (package, repeat, Royalty and Team Harvest tabs) | Byte-identical markup and counts to `p21d-money`; regression |
| P21E-T2 | `/admin/commissions?ledger=general_harvest` | Stats, filters, rows and empty state render; the `Position`, `Block`, `Queue (N)` and `Status` columns exist |
| P21E-T3 | Stats tiles on fixture `h = 3` | Paid ₱50,000.00, 18 awards, 13 recipients, baseline ₱0, open ₱0, unsettled positions 0 |
| P21E-T4 | Baseline rows in the fixture F ledger | Counted in the *Before go-live* tile (₱12,000.00) and **not** in *Paid* |
| P21E-T5 | Filters: level 3, status baseline, from/to, q | Each filters correctly; two combined filters intersect; a bad value falls back to "no filter" |
| P21E-T6 | The level distribution card | Ten rows, levels 1–10; amounts from `general_harvest_levels`; counts 13 / 4 / 1 / 0 …; a level above `max_levels` is marked *not paying at this depth* |
| P21E-T7 | `/admin/members` | The `GH` column shows `3 / 10`-style credited levels for node holders and `—` otherwise; the board card shows `N = 40`, settled 40, frontier 13 / 4 / 1 |
| P21E-T8 | `/admin/members?board=paid` / `none` | Correct rows; the filter survives pagination and combines with the status filter |
| P21E-T9 | `/admin/members/{id}` for a node holder | Position, node, qualified at, settled state, the ten-cell grid (paid / before go-live / in progress / not paying), the next level with its `N`, the awards table |
| P21E-T10 | `/admin/members/{id}` for an unqualified member | "Not qualified: 2 of 3 direct members" and no board panel |
| P21E-T11 | `/admin/members/{id}` for a baseline holder | Levels 1–2 marked *Before go-live* with their amounts and no wallet link |
| P21E-T12 | CSV export of the ledger | Header row and one row per filtered award; columns match the screen plus the wallet reference; `general_harvest.export` audit row with the filters |
| P21E-T13 | `/admin/reports` | The four cards render with the fixture numbers; the paid total, the by-level split (26,000 / 16,000 / 8,000) and the bands (14,000 / 18,000 / 18,000) equal the ledger tab |
| P21E-T14 | Report export `type=general_harvest` | The range CSV includes the award rows and the board summary block |
| P21E-T15 | The next-completion line on the board card | On fixture C: level 1 at `N = 43`, level 2 at `N = 49`, level 3 at `N = 67`, level 4 at `N = 121` |
| P21E-T16 | Live feed | An award bumps `admin.commissions` and `admin.dashboard`; an open General Harvest tab refreshes; the other four tabs are unaffected |
| P21E-T17 | A member opens `/admin/commissions?ledger=general_harvest` | 403, same guard as the other tabs |
| P21E-T18 | A staff account (non-admin) opens `/admin/members?board=paid` | Refused by the existing guard |
| P21E-T19 | Query budget | The ledger page is ≤ 8 queries for 25 rows; the members page ≤ 6 (no N+1 for the `GH` column) |
| P21E-T20 | The board card at 390 px | Readable; the frontier table scrolls horizontally; no page-level scroll |
| P21E-T21 | Unarmed database | The card says *not armed* and names the job; the ledger empty state says the board has never paid |
| P21E-T22 | An armed database with zero awards | The card shows `N`, the frontier (all zero) and the next completion (`N = 4`); the ledger shows "no block has completed yet" |
| P21E-T23 | An armed database with `settled_n < N` | The card shows the backlog and names the catch-up command; the ledger shows only the settled rows |
| P21E-T24 | Export with no rows | A file with the header only and one audit row |
| P21E-T25 | The full suite | Green; no new route; `ParityTest` and `RouterTest` untouched |

**Manual gate — `QA21E-01` … `QA21E-08`**

1. `QA21E-01` — with fixture `h = 3` paid, open `/admin/commissions?ledger=general_harvest`: the header
   shows **₱50,000.00** paid, **18** awards, **13** recipients.
2. `QA21E-02` — filter level `3`: **one** row (node 0, ₱8,000); filter status `Before go-live` on the
   fixture F network: five rows, no wallet link.
3. `QA21E-03` — click a row's member link: `/admin/members/{id}` opens with the panel showing the same
   node, position and money; back on the ledger, click the wallet link → the movement.
4. `QA21E-04` — export the filtered CSV; the columns and values match the screen; the audit desk shows
   `general_harvest.export`.
5. `QA21E-05` — `/admin/members`: the `GH` column reads `3 / 10`, `2 / 10`, `1 / 10`…; the board card
   shows `N = 40`, settled 40, frontier 13 / 4 / 1, and "level 1 next at N = 43". `?board=paid` filters
   to the 13 recipients.
6. `QA21E-06` — `/admin/members/{id}` for a member who has not qualified: the "2 of 3 direct members"
   line, no board panel.
7. `QA21E-07` — `/admin/reports`: the four cards show the same totals as the ledger; export the range
   CSV and find the General Harvest block.
8. `QA21E-08` — switch to the Package, Repeat, Royalty and Team Harvest tabs: they look and behave
   exactly as before.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P21E-T1 … P21E-T25 pass
[ ] The four existing tabs are byte-identical (diff or snapshot)
[ ] No new route; ParityTest and RouterTest green
[ ] Every number on the page is traceable: row → member, row → wallet movement, tile → model method
[ ] Tag p21e-admin; phase log; Appendix H row
```

**Rollback**

Revert the views and the controllers. The tab disappears; the data is untouched. Nothing in 21D reads
anything this phase writes.

**Hand-off to 21F**

- The admin ledger proves the numbers 21F will show members; any disagreement is a bug in 21F.
- `GeneralHarvestAward`'s filter/select/total API and `GeneralHarvestBoard::blocks()` are proven by a
  page, so 21F can reuse them without inventing a second implementation.

**Risks**

| Risk | Mitigation |
|---|---|
| The other tabs regress while adding a fifth pill | T1 is a snapshot/byte comparison |
| A baseline row leaks into a paid total | Rule 4 and P21E-T4 pin the two figures |
| The board card shows a liability that is not the closed form | Rule 8 and P21E-T15 call the service, not a view expression |
| The queue card is empty and unexplained | Rule 7 names the four states and the action for each |
| Reports disagree with the ledger | Both call the same model methods; T3/T13 compare them |

---

## 10. Phase 21F — Member visibility

**Goal.** A member can see their board position, the exact queue positions that fill each of their ten
blocks, how many are filled, what each level pays and how far the queue still has to grow — with copy
that states the two hard facts (a slot is filled by the whole network, and a block pays only when the
queue reaches its last position) and never promises money the engine will not pay.

**Builds on.** 21D (`statusFor()`, `blocks()`, the award queries).

**Ships alone because.** It is presentation. Every number it shows already exists and is already proven
by 21D's tests; if 21E never shipped, members still see their own money and admins still see the wallet
statements.

**Effort.** 1.5 ideal days. **Suite.** ≥ 1,478 cases.

**Reference.** §12.2–§12.5 (the board the card renders), §12.8 (the examples it can be checked
against), Appendix C.3–C.4, C.6 and C.9 (the copy and the member CSV).

**Deliverables**

**Code**

- `system/app/Controllers/EarningsController.php` — `ledger=general_harvest` selects the fifth tab;
  `export` writes `general-harvest-statement_YYYY-MM-DD.csv`; both scoped to the session member.
- `system/app/Controllers/DashboardController.php` — the tile's status payload.
- `system/app/Controllers/GenealogyController.php` — the board badge on the downline rows.
- Views (new): `member/earnings/_general_header.php`, `_general_stats.php`, `_general_blocks.php`,
  `_general_progress.php`, `_general_rows.php`, `_general_pagination.php`;
  `member/dashboard/_general_harvest.php`.
- Views (modified): `member/earnings.php` (the fifth tab), `member/dashboard/_earnings.php` (the tile),
  `member/genealogy/_rows.php` (the badge).

**Docs.** `docs/qa/phase-21-general-harvest.md` gains section F (`QA21F-01` … `QA21F-09`). The copy rules
below are the acceptance criteria for the whole section.

**Not yet.** No release work: 21G owns the documentation, the phase badge, the acceptance pass and the
sign-off. Nothing in 21F may touch `APP_PHASE` or the QA pack's earlier sections.

**Rules — the copy rules are normative**

1. **Say "General Harvest bonus", "Harvest board", "your block", "position #N", "qualified members".**
   Never "matrix scheme", "spillover", "passive income", "unlimited earning", "guaranteed", "binary" or
   "cycle".
2. **Always state that the board is the whole network's.** On the block card, once, verbatim: *"A slot
   in your block is filled by a qualified member of the whole network — not necessarily anyone in your
   team. Your block fills in the order members qualified."*
3. **Always state the position gap, in numbers.** Every level row reads *"needs the queue to reach
   N"*, and the card header reads the current `N`. Never "soon", never "almost".
4. **If nothing can complete at the current queue size, say so plainly.** A member at position #1,204
   in a 1,204 member network reads: *"No level can complete at the current queue size. Level 1 needs the
   queue to reach 3,613."* That sentence is mandatory, not optional (D-20's honesty rule).
5. **Never promise a date.** Say *"level 3 completes when the queue reaches 67 and pays ₱8,000.00"*;
   never *"you will be paid by…"*. The arithmetic is fixed; the timing is not knowable.
6. **Show the whole board**, filled and empty blocks alike, and never hide a level the member has not
   reached. An empty level reads *0 of 27* with its amount, because the amount is the offer.
7. **Say that purchases, rank and Active status are irrelevant**, once, in the card's footnote: *"This
   bonus does not depend on any purchase, on your rank, or on the monthly Active status."*
8. **The member's own money is the only money shown.** No other member's amount, wallet or rank ever
   appears. Usernames in the block roster follow D-20: **only inside the viewer's own sponsor subtree**;
   everybody else is "a member outside your team" with their position.
9. **A baseline row is labelled "Before go-live"** and is excluded from every *Earned* figure; the card
   says once, in a footnote, that the board started paying on a date and blocks completed before it are
   recorded but not paid.
10. **Every number comes from the service.** `statusFor()` supplies the position, blocks, amounts and
    progress; the view formats, never computes. The roster's relation labels come from the service, not
    from the view's own ancestry walk.
11. **The other four tabs stay live and unchanged.** Package, repeat-purchase, Royalty and Team Harvest
    keep their URLs and bytes; the fifth tab is `?ledger=general_harvest`; one live surface
    (`member.earnings:{id}`) refreshes all five.
12. **Every query is scoped to the session member** (`WHERE user_id = ?`), including the CSV export,
    which is scoped in the **model** so a hand-edited URL cannot widen it.
13. **Show the relation only where it is honest.** A block roster row may say *Direct* (the viewer's own
    direct), *In your team* (a deeper descendant) or *Outside your team* (everyone else) — the reference's
    §4.9 vocabulary, with the username restricted as Rule 8 says.

**Test plan**

| ID | Test | Expected |
|---|---|---|
| P21F-T1 | `/earnings?ledger=general_harvest` for a node holder | Position, node, `N`, ten blocks, progress card, roster page and award rows render |
| P21F-T2 | The block card for node 0 at `N = 40` | L1 `3/3` paid ₱2,000; L2 `9/9` paid ₱4,000; L3 `27/27` paid ₱8,000; L4 `0/81` at ₱12,000 needing `N = 121`; earned ₱14,000.00 |
| P21F-T3 | The block card for node 12 at `N = 40` | L1 `1/3` paid ₱2,000 (block #14–#16, filled 1 of 3); L2 `0/9` needing `N = 49`; L3 `0/27` needing `N = 94`; the "no further level at this queue size" line is absent because L1 already paid |
| P21F-T4 | A node whose blocks are all empty at `N` | The mandatory sentence of Rule 4 with the exact `N` of level 1 |
| P21F-T5 | The block range display | Level `L` shows `#from–#to`, the slot count `3^L`, and the filled count; for node 0 these are `#2–#4`, `#5–#13`, `#14–#40`, `#41–#121` |
| P21F-T6 | A member who has not qualified | The tab explains the gate ("3 direct members"), shows the current count and no board; no awards table |
| P21F-T7 | The baseline member | Levels 1–2 marked *Before go-live*; *Earned* shows only the credited level (₱8,000.00); the footnote names the start date |
| P21F-T8 | The member CSV | The documented columns, only the caller's rows, **0** audit rows, and `?user_id=` cannot widen it |
| P21F-T9 | `/earnings` (the other four tabs) | Unchanged — regression |
| P21F-T10 | `/dashboard` | The tile shows the paid total, the position and the next block's progress; no action, no form |
| P21F-T11 | `/genealogy` | The badge shows the reader's own position and node, and each queued downline member's position; unqualified members show `—` |
| P21F-T12 | Word search of every new member view | No "spillover", "passive", "guaranteed", "unlimited", "matrix scheme", "binary", "cycle" |
| P21F-T13 | The network sentence | Present on the block card, verbatim, exactly once (not repeated on the tile) |
| P21F-T14 | The purchases/rank/Active footnote | Present once, on the block card |
| P21F-T15 | A member with a wallet credit | The row links to the wallet statement's movement by `ref_id` |
| P21F-T16 | `/earnings?ledger=general_harvest&user_id={other}` | Ignored: only the session member's rows |
| P21F-T17 | Live refresh | An award bumps `member.earnings:{id}` and the open tab refreshes; the other four tabs are unaffected |
| P21F-T18 | Mobile width (390 px) | The ten-block table scrolls inside its card; the page does not scroll horizontally |
| P21F-T19 | A member with a large block | The roster paginates (25 per page); the card stays ten levels; the page is ≤ 8 queries |
| P21F-T20 | Every amount on the card | Equals `general_harvest_levels.amount` for paying levels; a level above `max_levels` reads *not paying at this depth* |
| P21F-T21 | The tab with the stream off | The card shows position and progress and says the bonus is not currently paying; no award rows |
| P21F-T22 | A block roster containing a stranger | The position is shown, the username is not; the row reads "a member outside your team" (D-20) |
| P21F-T23 | A block roster containing the viewer's own direct and a deeper descendant | "Direct" and "In your team" with usernames; no other member's money |
| P21F-T24 | The full suite | Green; the other four tabs' tests unchanged |

**Manual gate — `QA21F-01` … `QA21F-09`**

1. `QA21F-01` — as node 0 of fixture `h = 3`, open `/earnings?ledger=general_harvest`: position `#1`,
   `N = 40`, L3 paid ₱8,000.00, L4 `0 of 81` at ₱12,000.00 needing `N = 121`.
2. `QA21F-02` — read the block card: the network sentence, the purchases/rank footnote and the *Before
   go-live* label if any.
3. `QA21F-03` — as a member who has not qualified, open the tab: the gate, the current count, no board,
   no awards.
4. `QA21F-04` — filter the rows by level and status; the totals match the rows; export the CSV and
   compare it with the screen.
5. `QA21F-05` — `/dashboard`: the tile shows the paid total, the position and the next block's progress.
6. `QA21F-06` — `/genealogy`: the reader's position and each downline member's position; unqualified
   members show `—`.
7. `QA21F-07` — as the same member, open the package, repeat, Royalty and Team Harvest tabs: unchanged.
   Wait for an award (or trigger one with a registration) and watch the General Harvest tab refresh.
8. `QA21F-08` — append `&user_id=<another id>` to the tab and to the export: the page and the file are
   unchanged.
9. `QA21F-09` — repeat the block card and the tab at 390 px: no page-level horizontal scroll.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] P21F-T1 … P21F-T24 pass
[ ] A word search of every new view for "spillover"/"passive"/"guaranteed"/"unlimited" finds nothing
[ ] The network sentence, the purchases footnote and the baseline footnote are present and singular
[ ] The "no level can complete" sentence is present for a node beyond the current N (Rule 4)
[ ] A block roster never shows a username outside the viewer's subtree (D-20)
[ ] The other four earnings tabs are unchanged (regression)
[ ] No new route; ParityTest green
[ ] Tag p21f-member; phase log; Appendix H row
```

**Rollback**

Revert the controllers and the views. The panels disappear; the money and the admin ledger are
untouched.

**Hand-off to 21G**

- Nothing. 21G depends on all six phases, and 21F's numbers must agree with 21E's ledger.

**Risks**

| Risk | Mitigation |
|---|---|
| Copy implies guaranteed, passive or unlimited income | The four "never" rules, the word search in the exit gate and P21F-T12 |
| A member reads "1 of 3 filled" as a promise of a third of the money | The `filled of slots` label plus the all-or-nothing sentence in the card copy |
| A member expects payment from their own team | Rule 2's sentence is mandatory and singular (P21F-T13) |
| A member at a high position reads a cheerful card and no money | Rule 4's mandatory sentence with the exact `N`; the tile shows the gap, not a promise |
| A stranger's username leaks across team lines | D-20 and P21F-T22/T23 |
| An N+1 in the block roster | `statusFor()` paginates the roster and issues one query per page; P21F-T19 counts |
| A member reads another's money | Session-scoped queries and the model-scoped CSV; P21F-T16 |
| The baseline footnote is missed and a member disputes a missing payment | The row label *Before go-live* plus the footnote; §18 Q1 records the business question |

---

## 11. Phase 21G — Release

**Goal.** Documentation that matches the code, a QA pack a stranger can execute, `APP_PHASE 21`, a green
acceptance run, and a signed gate record.

**Builds on.** All six phases.

**Ships alone because.** It ships last by definition; what it must *not* do is change behaviour. If a
documentation claim and the code disagree during this phase, the code wins and the plan is amended.

**Effort.** 1.5 ideal days. **Suite.** 1,484 cases (no new cases unless a documentation claim exposes a
missing test).

**Reference.** §2.4 (the universal gate), §17 (risks), Appendix H.

**Deliverables**

**Code**

- `system/app/config.php` — `APP_PHASE` **21** (D-16), and `HardeningTest` updated to assert it.
- `system/tests/smoke.ps1` — the new URL checks accumulated by 21B–21F (the settings card, the members
  board card and filter, the ledger tab, the member tab, the genealogy badge), plus the
  "register three members → the queue row appears" check Phase 20 added and a General Harvest empty-state
  check on the unarmed board.

**Docs**

- `docs/qa/phase-21-general-harvest.md` — sections A–G complete, each with its `QA21X-nn` checks,
  evidence references and a sign-off line.
- `docs/qa/README.md` — the Phase 21 row finalised (check count, time needed, "signed").
- `docs/qa/sign-off-sheet.md` — the Phase 21 row and the seven gate records.
- `docs/phase-log.md` — the seven phase entries (each written at its own gate) and the release entry.
- `system/README.md` — the migration row, the tables/settings/case/smoke counts, the settings block, the
  board, the two jobs, the `APP_PHASE` line.
- `docs/deploy.md` — the migration, the two settings, the ten ladder rows, the board row, the go-live
  order, the verification counts.
- `docs/runbook.md` — the General Harvest section: the go-live order (deploy → configure → arm dry run →
  arm → enable → catch up → reconcile → doctor), what to check weekly, how to read the board card, what to
  do when the reconcile fails, how to repair a backlog, and the rule that a node is never moved.
- `docs/admin-guide.md` — the settings card, the board card, the ledger, the member panel, the reports.
- `docs/cron.md` — verify only: this stream adds **no** cron entry, and the page must say why.

**Not yet.** Nothing. The phase closes the workstream.

**Rules**

1. **No behaviour change in this phase.** Only `APP_PHASE`, tests and documentation. A code fix discovered
   here is a defect against the phase that should have caught it: fix it, run that phase's tests, and
   record the deviation.
2. **The documentation is executable.** Every command in the QA pack, the runbook and `docs/deploy.md` is
   run during the acceptance pass, on a scratch schema.
3. **The QA pack is written for a stranger.** It assumes no prior knowledge of this plan, names the exact
   URL and the exact expected number, and states what to do when a check fails.
4. **The gate record is factual.** Counts, tags, deviations and evidence filenames — no adjectives.
5. **The release acceptance table below is complete.** Every line is ticked against a named phase and a
   named case, or the release is not signed.
6. **The go-live order is documented in one place and repeated nowhere.** `docs/runbook.md` §General
   Harvest owns it; every other document links to it.
7. **The two Harvest streams' documentation stays separate.** The runbook's Team Harvest section is not
   edited; a one-line cross-reference is added instead (D-22).

**Release acceptance table**

| Acceptance criterion | Proved by |
|---|---|
| A queued member holds exactly one node, and the node index is the position minus one | 21B — P21B-T13; 21C — P21C-T1 |
| The board finishes only when the queue reaches the block's last position, and a partial block pays nothing | 21B — P21B-T14/T15; 21D — P21D-T1/T6 |
| A completed block pays its configured amount once, ever | 21D — P21D-T1–T9 |
| The ladder is the reference ladder, to the peso | 21D — P21D-T3/T7; 21B — P21B-T11 |
| A block already full at the baseline is recorded, never paid | 21C — P21C-T1/T10; 21D — P21D-T11–T13 |
| A pre-go-live block cannot be paid by raising the depth later | 21C — P21C-T19; 21D — P21D-T15/T16 |
| Only levels up to `max_levels` are paid | 21D — P21D-T15/T16 |
| A zero-amount level pays nothing and pays later when priced | 21D — P21D-T17 |
| A ladder edit never rewrites a past award | 21D — P21D-T25; the amounts are frozen at the arm (D-23) |
| The stream off means no money and a growing queue | 21D — P21D-T18/T19 |
| An unarmed board pays nothing and throws nothing | 21D — P21D-T20 |
| A replay, a duplicate and a repeated one-step settlement move no money | 21D — P21D-T26–T30 |
| Every settlement batch is a valid prefix and `settled_n` is exact | 21D — P21D-T29/T32 |
| The board's total equals the closed form at every tested N | 21B — P21B-T15/T16; 21D — P21D-T7/T35 |
| `wallet_reconcile.php` exits 0 with a General Harvest section | 21D — P21D-T35/T39 |
| The reset empties the ledger, disarms the board and keeps the ladder | 21A — P21A-T8; 21C — P21C-T17 |
| A fresh install reports 39 tables, 79 settings, 10 ladder rows, 1 board row | 21A — P21A-T6 |
| The migration is idempotent; migrated equals fresh | 21A — P21A-T2/T3 |
| The five admin surfaces and the four member surfaces show the fixture numbers | 21E — T2–T15; 21F — T1–T7 |
| The four existing commission tabs and four earnings tabs are unchanged | 21E — T1; 21F — T9 |
| The member copy states the network rule, the position gap and the purchases footnote | 21F — T12–T14 |
| No username outside the viewer's subtree appears on a member page | 21F — T22/T23 |
| No new route, no new cron entry, no new permission, no Team Harvest change | 21E — T25; 21F — T24; 21G — the smoke walk |
| The docs are updated | 21G — this phase |

**Test plan**

No new automated cases: this phase changes no behaviour, and a documentation claim that no case covers is
a defect in the phase that owns the behaviour (Rule 1). Verification is the accumulated suite plus the
acceptance table, executed in this order:

1. `php tests/run.php` — the documented case count, 0 failed, exit 0.
2. `powershell -File tests/smoke.ps1` — the documented check count, 0 failed, exit 0.
3. `php bin/build_install_sql.php --check` — exit 0 on the committed bundle.
4. `php bin/wallet_reconcile.php` — exit 0 with the General Harvest section.
5. `php bin/general_harvest_catchup.php --doctor` — exit 0.
6. The release acceptance table — every row ticked against a named phase and a named case.
7. `APP_PHASE` — 21 in `config.php`, reported by the suite header and asserted by `HardeningTest`.

**Manual gate — `QA21G-01` … `QA21G-06`**

1. `QA21G-01` — `php tests/run.php` → the documented count, 0 failed; `tests/smoke.ps1` → 0 failed;
   `build_install_sql --check` → exit 0.
2. `QA21G-02` — hand the QA pack to a tester who has not seen this plan; they execute sections A–F
   without asking a question. Any question is a documentation defect.
3. `QA21G-03` — walk the release acceptance table row by row; every row cites a passing case or an
   evidence file.
4. `QA21G-04` — the build reports **21**: the runtime constant, the suite header and `HardeningTest`;
   `/dashboard` answers 200 as an admin and every shipped menu item renders.
5. `QA21G-05` — on a scratch install, follow `docs/deploy.md` and then the runbook's go-live order end to
   end: deploy, configure the ladder, arm dry run, arm, enable, register the 13 accounts of fixture
   `h = 1`, see one ₱2,000.00 credit, reconcile, doctor.
6. `QA21G-06` — confirm the evidence folder contains one file per `QA21X-nn` check, and the sign-off
   sheet carries the seven gate records.

**Exit gate**

```
[ ] The universal gate of §2.4
[ ] `APP_PHASE === 21`; HardeningTest green
[ ] The release acceptance table is complete and every row is ticked
[ ] The QA pack is signed; the sign-off sheet carries Phase 21
[ ] The seven gate records in Appendix H are filled
[ ] The phase log has seven entries plus the release entry
[ ] Tag p21g-release (and `phase-21` as the feature tag); the plan's status line is updated
```

**Rollback**

Documentation and `APP_PHASE`: revert the constants and the docs. Behaviour is unchanged, so this is the
safest phase to revert.

**Hand-off**

The workstream is closed. The next plan may build the deferred items in §18 — a retroactive baseline
decision, a forward-only correction tool, a member-deletion policy, or a cap — **without reopening any of
these seven gates**, because each is a new decision with its own economics.

**Risks**

| Risk | Mitigation |
|---|---|
| The docs describe an intention the code does not implement | Every claim is executed in the acceptance pass; the code wins |
| The QA pack is written by the author and hides a gap | Rule 3, and `QA21G-02` uses a different tester |
| `APP_PHASE` lands without `HardeningTest` | They are one deliverable in one commit |
| A defect is found during the acceptance pass and quietly patched | Rule 1: fix it, re-run that phase's gate, record the deviation |
| The go-live order is documented in five places and drifts | Rule 6: one owner, links everywhere else |
| The Team Harvest docs are edited "while we are here" | Rule 7: a cross-reference only (D-22) |

---

# Part III — The shared contract

## 12. Specification (normative)

This section is the contract. Where it diverges from the simulator, the divergence is stated and
justified; the simulator remains the authority for the **mechanics** (the 3-ary board, the block
formula, the all-or-nothing levels, the flat ladder and the "pays across team lines" property), not for
the app's data model. §12.11 is the complete list of divergences.

Each subsection names the phase that implements it. A phase may not implement a rule from a later
subsection.

### 12.1 Vocabulary and symbols

| Term | Meaning | Phase |
|---|---|---|
| **Harvest queue** / **queue** | The ordered list of qualified members in `harvest_queue`. Order = `id`. Phase 20 owns it. | 21B |
| **position** | A member's place in the queue, 1-based, equal to `harvest_queue.id`. Fixed for ever. Displayed `#N`. | 21B |
| **seq** | `position − 1`, the 0-based board index. Displayed `node q`. | 21B |
| **N** | The number of queued members, `COUNT(*)`. Also `MAX(id)` under the gapless rule (§12.3 R1). The board's **only** variable. | 21B |
| **node** | One member's place on the shared board; node `q` is the member at position `q + 1`. | 21B |
| **block** / **level block** | The `3^L` consecutive queue positions that fill node `q`'s level `L`: positions `start+1 … start+3^L`. | 21B |
| **slot** | One position inside a block. `3^L` slots per level. | 21B |
| **filled(q,L)** | `clamp(N − start(q,L), 0, 3^L)` — how many of the block's positions are occupied. | 21B |
| **complete(q,L)** | The block is full: `N ≥ 3^L·q + (3^(L+1) − 1)/2`. | 21B |
| **frontier** | Per level, the number of rows written (`have`), which is one more than the last paid `seq`. | 21D |
| **award** | One `general_harvest_awards` row: one `(seq, level)` decision, `credited` or `baseline`. | 21C/D |
| **baseline** / **N₀** | The queue length at the arm instant (`general_harvest_board.baseline_n`). Decides every exclusion, for ever. | 21C |
| **baseline row** | An award row for a block already full at `N₀`: amount recorded, no money. | 21C |
| **settled_n** | The queue length up to which the board has been settled by `settle()`. Always `≥ baseline_n` and `≤ N`. | 21D |
| **backlog** | `N − settled_n`: positions the queue has gained that no settlement has processed yet. | 21D |
| **credit** | A withdrawable e-wallet movement, `ref_type = 'general_harvest'`, `ref_id` = the award id. | 21D |
| **the switch** | `general_harvest_enabled`, default off. | 21B |
| **max depth** | `general_harvest_max_levels`, default 10: the deepest level the build pays. | 21B |
| **the preset** | The reference ladder of Appendix A: ₱2,000 … ₱5,000,000. | 21A/B |
| **board total** | `boardTotal(N)`: the sum of every completed block's amount at queue size `N` (the closed form of §12.7). | 21B |
| **open liability** | `boardTotal(N) − boardTotal(settled_n)`: what a settlement now would pay. | 21D |

### 12.2 The board

> **One board for the whole network. Every qualified member holds exactly one node. Node `q` is the
> member at queue position `q + 1`. A node's level-`L` block is the `3^L` consecutive positions that
> start at `3^L·q + (3^L − 1)/2 + 1` (1-based). A block fills with whoever occupies those positions —
> no team relationship is required or consulted.**

```
node(q)            = harvest_queue row with id = q + 1
children(q)        = 3q + 1, 3q + 2, 3q + 3                 the 3-ary heap
parent(q)          = floor((q − 1) / 3)                     for q ≥ 1
slots(L)           = 3^L
start(q,L)         = 3^L·q + (3^L − 1)/2                    0-based first slot
block(q,L)         = positions [start+1 … start+3^L]        1-based, for display
filled(q,L)        = clamp(N − start(q,L), 0, 3^L)
complete(q,L)      ⇔ N ≥ start(q,L) + 3^L = 3^L·q + (3^(L+1) − 1)/2
```

Rules:

1. **The node is a position, not a member.** If a member could move, the board would move with them; no
   member can (Phase 20 D-03), so the node is a fact.
2. **The board is a heap because the plan is a heap.** The children formula is the arithmetic form of
   "three legs under each node", and it is also the reason a node's block is exactly the last `3^L`
   positions of its parent's level-`(L+1)` block (§12.3 R4).
3. **A block always lies after its node.** For every `L ≥ 1` and `q ≥ 0`, `start(q,L) ≥ q + 1` (check
   `L = 1`: `start = 3q + 1`), so every slot in a node's block belongs to a member who qualified
   **after** the node holder. General Harvest never pays for people who were already there.
4. **A slot may belong to a complete stranger.** The positions are the queue order, not the sponsor
   tree; the reference is explicit that this is intended (reference §4.1, §11.1 #1). The member copy
   states it (21F Rule 2) and the admin roster labels it (Direct / In your team / Outside your team).
5. **The board is unbounded, but only ten levels pay per node.** A node with 100,000 positions behind
   it has slot ranges past level 10; levels 11+ are not part of this plan (reference §4.1).
6. **`N` is the only input.** No purchase, rank, status, package, team size or month enters any formula
   in this subsection.

**Phase.** 21B.

### 12.3 Completion, thresholds and the queue-size staircase

The threshold is the queue length at which a block's last position is reached:

```
N_min(q, L) = 3^L·q + (3^(L+1) − 1)/2
```

For the apex (`q = 0`) the ten thresholds are the reference's staircase. For positions #2–#6 they move
right by `3^L·(q)`:

| Position | `q` | L1 | L2 | L3 | L4 | L5 | L6 | L7 | L8 | L9 | L10 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| #1 | 0 | 4 | 13 | 40 | 121 | 364 | 1,093 | 3,280 | 9,841 | 29,524 | 88,573 |
| #2 | 1 | 7 | 22 | 67 | 202 | 607 | 1,822 | 5,467 | 16,402 | 49,207 | 147,622 |
| #3 | 2 | 10 | 31 | 94 | 283 | 850 | 2,551 | 7,654 | 22,963 | 68,890 | 206,671 |
| #4 | 3 | 13 | 40 | 121 | 364 | 1,093 | 3,280 | 9,841 | 29,524 | 88,573 | 265,720 |
| #5 | 4 | 16 | 49 | 148 | 445 | 1,336 | 4,009 | 12,028 | 36,085 | 108,256 | 324,769 |
| #6 | 5 | 19 | 58 | 175 | 526 | 1,579 | 4,738 | 14,215 | 42,646 | 127,939 | 383,818 |

The **whole-board totals** are the reference's §4.6 closed form: the number of complete blocks at level
`L` is `paid(L,N) = max(0, floor((N − (3^L − 1)/2) / 3^L))`, and they are the nodes `0 … paid−1`:

```
paid(L,N)   = max(0, floor((N − (3^L − 1)/2) / 3^L))
qFor(L,N)   = paid(L,N) − 1                                 the highest complete node, −1 if none
boardTotal(N) = Σ_{L=1..10} amount(L) · paid(L,N)
awardCount(N) = Σ_{L=1..10} paid(L,N)
```

| `N` | board total | awards | `N` | board total | awards |
|---:|---:|---:|---:|---:|---:|
| 4 | ₱2,000 | 1 | 364 | ₱579,000 | 179 |
| 13 | ₱12,000 | 5 | 1,093 | ₱1,888,000 | 543 |
| 20 | ₱16,000 | 7 | 3,280 | ₱6,065,000 | 1,636 |
| 40 | ₱50,000 | 18 | 88,572 | ₱181,827,000 | 44,271 |
| 121 | ₱176,000 | 58 | 88,573 | ₱190,208,000 | 44,281 |

Rules:

1. **The queue must be gapless** (D-03): `COUNT(*) = MAX(id) = N`. The block arithmetic is defined over
   consecutive indices; a hole would shift every later node. Settlement fails closed on a hole, the
   reconcile reports it, and the repair is restore-from-backup. No application path deletes a member, so
   the invariant holds by construction.
2. **Completion is monotone in `N` and in `q`.** Once complete, always complete; and for a fixed level,
   the complete nodes form a **prefix** `0 … qFor(L,N)`. That prefix property is what makes the paid set
   a contiguous range and the frontier a `COUNT(*)` (§12.5 R3).
3. **All-or-nothing.** `filled < 3^L` pays nothing and is information only; there is no partial or
   pro-rata payment (reference §4.2).
4. **Position #4 is the apex shifted one level.** Node 3's level-`L` threshold equals the apex's
   level-`(L+1)` threshold, because node 3's block is the last `3^L` positions of the apex's next block
   — visible in the table (`#4`'s L1 = 13 = `#1`'s L2). The same nesting repeats at every scale.
5. **`N` counts qualified members only.** Members who never reach three directs hold no node and fill no
   slot (reference §3, §4.8).
6. **The board is the same for everybody.** Two members with the same position see the same blocks; the
   board is not private and cannot be reordered.

**Phase.** 21B.

### 12.4 The ladder

Each level pays a **flat amount**, independent of the member's position, team, rank, purchases or the
number of people in the block (reference §4.3):

| Level | Slots `3^L` | Apex completes at `N ≥` | Reference amount | Cumulative |
|---:|---:|---:|---:|---:|
| 1 | 3 | 4 | ₱2,000 | ₱2,000 |
| 2 | 9 | 13 | ₱4,000 | ₱6,000 |
| 3 | 27 | 40 | ₱8,000 | ₱14,000 |
| 4 | 81 | 121 | ₱12,000 | ₱26,000 |
| 5 | 243 | 364 | ₱25,000 | ₱51,000 |
| 6 | 729 | 1,093 | ₱100,000 | ₱151,000 |
| 7 | 2,187 | 3,280 | ₱250,000 | ₱401,000 |
| 8 | 6,561 | 9,841 | ₱480,000 | ₱881,000 |
| 9 | 19,683 | 29,524 | ₱2,500,000 | ₱3,381,000 |
| 10 | 59,049 | 88,573 | ₱5,000,000 | ₱8,381,000 |
| **Total** | **88,572** | | **₱8,381,000** | |

Rules:

1. **Amounts are configured, thresholds are not.** The ten thresholds are the board; only the ten
   amounts and the maximum depth are settings (D-18).
2. **The full ladder is ₱8,381,000 per node**, reached by node 0 at `N = 88,573`; node `q` reaches it
   only at `N = 88,573 + 59,049·q` (position #2 at 147,622, #3 at 206,671, #4 at 265,720). It is a
   per-node ceiling, never a forecast: at `N = 88,573` the last 59,049 nodes earn nothing at all.
3. **A payable level must be positive** (D-17). The settings save refuses a zero amount for any level
   `1 … max_levels`, so a zero can only exist above the current depth or in a hand-edited row. A zero
   level is never paid: the settlement skips it without a row, `statusFor()` reports *unpriced* and the
   doctor names it. Because the amounts freeze at the arm (D-23), a zero below the depth is a data
   defect, not a supported switch.
4. **`max_levels` caps the payable depth.** Levels above it complete on the board and are marked *not
   paying at this depth*; their amounts are kept, and raising the depth pays them from the current
   queue (the engine is state-based, so no history is lost).
5. **The reference's amounts are the seeded preset** (Appendix A). An operator may change them **while
   the board is unarmed**; the card shows the new total and the audit row records it (21B). From the arm
   the ten amounts are frozen (D-23).
6. **No per-rank multiplier, no position weighting.** One ladder for every node (D-18, §18 Q4).

**Phase.** 21B (configuration), 21D (payment).

### 12.5 The award rule

For an armed board and a target queue length `T`, at settlement time:

```
settle(T):
    for L = 1 .. 10:
        Q   = qFor(L, T)                          # highest complete node at this level, −1 if none
        if Q < 0: continue
        have = COUNT(rows at level L)             # rows are exactly 0 … have−1
        if have > Q + 1: raise state('ledger ahead of the queue')
        for q = have … Q:
            if q ≤ qFor(L, baseline_n):   write 'baseline' (amount snapshot, ledger_id NULL)
            elif L > max_levels:          skip this level (written when the depth is raised)
            elif amount(L) ≤ 0:           skip this level (unpriced; reported)
            else:                         write 'credited' + withdrawable wallet credit
    board.settled_n = T
```

Rules:

1. **State-based.** The comparison is the queue length and the ledger's frontier, not an event counter.
   A missed settlement, a rolled-back transaction, a late enablement or a repaired backlog all converge
   on the same answer on the next call (D-19).
2. **The frontier is read, never assumed.** `have` comes from `COUNT(*)` on the level, so a lost or
   deleted row is detected by the prefix check rather than silently skipped or re-paid.
3. **A level pays once, ever.** The row is the lock: `UNIQUE (seq, level)` plus the frontier read. A
   replay writes nothing.
4. **The baseline is decided once, per board.** `q ≤ qFor(L, baseline_n)` is a `baseline` row, never a
   credit — not at enablement, not later (D-06).
5. **The amount is snapshotted, and the ladder is frozen while armed.** `amount`, `threshold_n` and
   `n_at_award` are written on the row; because the ten amounts cannot change after the arm (D-23),
   every row for a level carries the same amount. A hand-edited ladder row changes only rows written
   after it, never a past award.
6. **The money is withdrawable and immediate.** A wallet credit, not a hold, not a pending award, not a
   monthly accrual (D-12).
7. **The stream must be on and the board armed.** `enabled = 0` writes nothing and leaves the queue
   intact; `armed = 0` writes nothing and is not an error (D-10, D-19).
8. **The recipient must be a member.** The engine throws rather than pay a `role <> 'member'` row; the
   queue already makes it impossible.
9. **Configuration never blocks a registration.** Off, unarmed, unpriced, beyond depth and a backlog
   are *states*, returned in the result and shown on the card; they are not exceptions. A wallet or
   database failure **is** an exception and rolls the caller's transaction back (money integrity beats
   sign-up completion).
10. **The award is atomic with its credit.** Both or neither; a failure rolls the whole settlement back
    and the next one retries the same blocks.
11. **A `baseline` row and a `credited` row can never both exist** for one `(seq, level)`: the unique key
    and the frontier both forbid it.
12. **No row is ever deleted or updated**, except `ledger_id` once, in the transaction that wrote it.

**Phase.** 21D.

### 12.6 The baseline

> **At arm time the board records `N₀`, the queue length, and writes a `baseline` row for every block
> that was already full: for each level `L`, the nodes `0 … qFor(L, N₀)`, at all ten levels. Those rows
> record their amounts and are never credited.**

```
baseline_n = N₀ = COUNT(*) at arm
baseline row (q, L)  ⇔  q ≤ qFor(L, N₀)
payable (q, L)       ⇔  complete(q,L) ∧ q > qFor(L, baseline_n) ∧ no row for (seq, level)
excluded amount      = boardTotal(N₀)
```

Rules:

1. **One number for the whole board.** Unlike Team Harvest's per-member `baseline_n`, General Harvest's
   exclusion is a property of the queue length, so it is a single scalar on the board row (D-06).
2. **All ten levels are written at arm**, even above `max_levels`, so raising the depth later can never
   pay a pre-go-live block (21C Rule 6).
3. **The rows are written once, by the arm job**, not lazily by the first settlement, because nodes below
   the frontier may never be visited by a settlement and would otherwise never be recorded.
4. **The excluded amount is printed before the write.** The dry run is the decision surface; the real run
   is one transaction and one audit row.
5. **Arm refuses** when the board is already armed, the stream is enabled, the queue is not live, the
   ladder is invalid (all ten amounts must be positive, D-23), or the queue is not gapless (D-07).
6. **A business reset disarms the board** and empties the ledger; a later arm starts a new network's
   history from zero.
7. **The baseline never moves.** No re-arm, no repair and no catch-up rewrites `baseline_n` or converts a
   baseline row into a credit.

**Phase.** 21C.

### 12.7 Money, rounding and the closed-form identities

- **There is nothing to round.** A level's amount is a configured `DECIMAL(12,2)` value; the award is
  that value. No percentage is applied and no multiplication is performed (contrast Royalty and
  Unilevel).
- **Money is integer centavos inside the engine** (`(int) round($amount * 100)`) and `DECIMAL(12,2)` in
  the database, formatted with `money()` in views. No float arithmetic touches a stored amount.
- **No tax, no fee, no cost of goods.** Awards are gross, like every other bonus in this build.
- **Withdrawable on credit** (D-12): the movement raises both the wallet and the withdrawable bucket,
  subject to the existing payout rules when the member requests a payout.
- **A zero amount is never credited.** The settlement skips the level without a row (§12.5 rule 8), and
  the wallet refuses a `0.00` credit anyway.
- **One ledger row per award.** `ref_id` is the award id, and the wallet's
  `UNIQUE (user_id, ref_type, ref_id, type)` anchor makes a second credit impossible even if the award
  insert were replayed.

**The identities.** With every level `1 … max_levels` priced and paying, `B = baseline_n`, and the
ladder frozen from the arm (D-23):

```
rows_L(settled_n)     = qFor(L, settled_n) + 1            per level, a contiguous prefix 0 … qFor
baselineRows_L        = qFor(L, B) + 1
creditedRows_L        = max(0, qFor(L, settled_n) − qFor(L, B))
creditedAmount_L      = creditedRows_L × amount(L)
paid to date          = boardTotal(settled_n) − boardTotal(B)
baseline excluded     = boardTotal(B)
open liability        = boardTotal(N) − boardTotal(settled_n)
total ledger amount   = boardTotal(settled_n)
```

With `max_levels < 10` the identities hold for levels `≤ max_levels`; levels above it hold their
baseline rows only. These eight lines are what `reconcile()` checks (§14.13) and what every report card
prints — and they are the reason a ledger defect cannot hide behind its own totals (D-21).

**Phase.** 21D.

### 12.8 Worked examples

All examples use the reference ladder and are verified by the suite (§16.3).

**A. The reference's seven-member tree.** Tree `1 → 2`, `2 → {4,5}`, `1 → 3`, `3 → 6`, `6 → 7`;
qualification order `3, 1, 2, 4, 5, 6, 7`, so `N = 7`. Level 1 pays nodes 0 and 1 (`paid = floor(6/3) =
2`): member 3 (position #1) ₱2,000 and member 1 (position #2) ₱2,000; the other five earn nothing.
Board **₱4,000.00**. On the same tree Team Harvest pays ₱1,000 — the two streams disagree by design.

**B. The reference's 20-member example** (reference §4.7). `N = 20`:

| Position | `q` | L1 filled/paid | L2 filled/paid | L3 | Total |
|---|---:|---|---|---|---:|
| #1 | 0 | 3/3 ₱2,000 | 9/9 ₱4,000 | 7/27 | **₱6,000** |
| #2 | 1 | 3/3 ₱2,000 | 7/9 — | — | **₱2,000** |
| #3 | 2 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #4 | 3 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #5 | 4 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #6 | 5 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #7 | 6 | 1/3 — | 0/9 — | — | ₱0 |
| **Board** | | 6 nodes | 1 node | 0 | **₱16,000** |

The numbers are the reference's own table; the fixture of §16.2 reproduces the same positions.

**C. Fixture `h = 2` — 40 accounts, 13 qualified.** `N = 13`: node 0 gets L1 + L2 (**₱6,000.00**);
nodes 1–3 get L1 (₱2,000.00); nodes 4–12 get nothing. Board **₱12,000.00**, 5 awards.

**D. Fixture `h = 3` — 121 accounts, 40 qualified.** `N = 40`: node 0 gets L1–L3 (**₱14,000.00**);
nodes 1–3 get L1–L2 (₱6,000.00 each); nodes 4–12 get L1 (₱2,000.00 each); nodes 13–39 nothing. Board
**₱50,000.00**, 18 awards, 13 recipients.

**E. Fixture `h = 4` — 364 accounts, 121 qualified.** `N = 121`: node 0 gets L1–L4 (**₱26,000.00**);
nodes 1–3 get L1–L3 (₱14,000.00 each); nodes 4–12 get L1–L2 (₱6,000.00 each); nodes 13–39 get L1
(₱2,000.00 each). Board **₱176,000.00**, 58 awards.

**F. The baseline.** Arm at `N = 13`, grow to `N = 40`: the five blocks already full at 13 are recorded
`baseline` (₱12,000.00 excluded); the settlement credits the remaining 13 blocks — node 0's L3
(₱8,000.00), nodes 1–3's L2 (₱4,000.00 each), nodes 4–12's L1 (₱2,000.00 each) — **₱38,000.00**. The
wallet holds ₱38,000.00, never ₱50,000.00; node 0's wallet holds ₱8,000.00, never ₱14,000.00.

**G. The per-node ceiling, by pure function.** `earnedBy(0, 88,573)` = **₱8,381,000.00**;
`earnedBy(0, 88,572)` = ₱3,381,000.00 (level 10 is one position short); `earnedBy(1, 147,622)` =
**₱8,381,000.00**; `earnedBy(1, 147,621)` = ₱3,381,000.00. These are unit cases: no tree of 88,573
members is built in the suite (§16.5).

**H. `max_levels = 2` at `N = 40`.** Levels 1–2 are credited (₱42,000.00, 17 awards); node 0's completed
level 3 is *not paying at this depth*; raising the depth to 3 credits ₱8,000.00 once.

**Phase.** Worked in 21B's and 21D's tests; shown in 21E/21F's pages.

### 12.9 Ceilings, validation and guards

| Guard | Rule | Where | Phase |
|---|---|---|---|
| Switch | `general_harvest_enabled` `in:0,1` | settings card | 21B |
| Max depth | `general_harvest_max_levels` 1–10 | settings card | 21B |
| Level amount | 0.00 – 10,000,000.00, two decimals | `GeneralHarvestLevel::validate()` | 21B |
| Ladder total | ≤ 25,000,000.00 (typo guard) | `GeneralHarvestLevel::validate()` (`sum` error) | 21B |
| Payable level | every level `1 … max_levels` `> 0` to enable or raise the depth | `GeneralHarvestLevel::validate()` | 21B |
| Ladder freeze | an amount change is refused while `armed_at` is set; only `max_levels` moves | `GeneralHarvestLevel::save()` + `GeneralHarvestBoard::state()` | 21B/21C |
| One node per member | `UNIQUE (user_id)` on the queue (Phase 20) | schema | 21B |
| One award | `UNIQUE (seq, level)` on the ledger | schema | 21A/21D |
| Ledger | `UNIQUE (user_id, ref_type, ref_id, type)` | existing wallet | 21D |
| Amount | `amount >= 0`; `level BETWEEN 1 AND 10`; `status IN ('credited','baseline')`; a baseline row has no `ledger_id` | schema CHECKs | 21A |
| Recipient | `role = 'member'`, never the company account | engine guard | 21D |
| Sequence | `COUNT(*) = MAX(id)`; nodes never moved; settlement fails closed on a hole | `GeneralHarvestBoard::gapless()`, `settle()` | 21D |
| Baseline | `baseline_n` written once; `settled_n ≥ baseline_n`; no re-arm without a reset | schema CHECKs, `arm()` | 21C |
| Depth | no award above `max_levels` at write time | `settle()` | 21D |

There is **no per-purchase, per-month or per-network cap** — the reference has none, and the ₱25M ladder
ceiling is a configuration guard, not a payout cap. If the business wants a cap, it belongs in a new
decision (§18 Q5), not in a hidden clamp.

### 12.10 What does not affect General Harvest

- **Purchases, orders, products, P.V., discounts and shipping.** Nothing in this stream reads the shop.
- **The member's rank** (Leader/Supervisor/Manager/Director) and the Royalty ladder's thresholds.
- **The monthly Active flag** and the Unilevel monthly minimum — this stream has no month.
- **The member's own direct count after qualification.** What matters is the queue's length, not the
  member's team.
- **The member's sponsor, uplines or subtree.** The board is global; a node's block is filled by
  positions, not by descendants (reference §4.8).
- **The directs' status, package, activity or spending.** A slot is filled the day the member qualifies.
- **The month, the day or the hour.** A qualification on the 1st and one on the 31st behave identically.
- **The member's own wallet balance, payout history or transfer limits.** Money is credited whether or
  not the member can withdraw it today (D-14).
- **Team Harvest's switch, ladder, baseline or ledger.** The two streams are independent (D-22).
- **The number of registrations.** Every qualification is checked; there is no batch cap.

### 12.11 Deliberate divergences from the simulator

The simulator is the authority for the mechanics. These are the places this build must differ, and why.
Each is a decision or a rule above; none is an accident.

| # | Simulator | This build | Why |
|---:|---|---|---|
| 1 | The queue is an array `mx` in `localStorage`; positions are array indices | Rows in `harvest_queue`; `seq = id − 1` | Money must survive a browser, a backup and an audit (Phase 20) |
| 2 | The board's history counts from the first render; there is no go-live | `baseline_n` + `baseline` rows: pre-go-live blocks are recorded, never paid | Phase 17's D-16: the company never agreed to pay for a period before the stream existed |
| 3 | General and Team money are **derived**, never ledgered (reference §2.5) | A ledger row per `(seq, level)` and a wallet credit | A derived payout cannot be paid, reconciled or explained |
| 4 | `levelsG(p)` shows a progress bar for a partial block | Same display, but a partial block writes nothing at all | All-or-nothing is the plan; a progress bar is information |
| 5 | No `max_levels`; ten levels always | `general_harvest_max_levels` (default 10) caps the payable depth | An operator must be able to stop at a level without editing the ladder |
| 6 | No zero-amount concept | A payable level must be positive; an unpriced level writes nothing and is reported | The frontier is a prefix; a silently skipped level would break it (D-17) |
| 7 | The board table names every member in every block (reference §4.9) | A member sees a username only inside their own sponsor subtree (D-20) | The board is global; a stranger's username is a new disclosure the genealogy never makes |
| 8 | The company is a synthetic member (id 0) that never enters the queue | The company is a staff account; the queue only holds `role = 'member'` | Same outcome, without a fake user row |
| 9 | No baseline, no arm job, no catch-up: the view is the truth | A one-time arm, a state-based settle, a catch-up and a doctor | A real network needs a go-live instant and a way to repair a missed event |
| 10 | The board can grow to any size, unbounded above ten levels | The board is unbounded; ten levels pay, per node | The plan is ten levels (reference §4.1) |
| 11 | No audit, no accounts, no roles | An append-only ledger, audit rows for the ladder/arm/export, staff-only admin | The app has real members |
| 12 | Bulk growth can add 20,000 members in one click | No bulk member creation exists; a future import must settle at the end of its batch (§18 Q8) | There is nothing to adapt until a bulk import ships |

### 12.12 The board's economics

These are properties of the model, not tuning advice.

**Cost per qualified member.** The board's total liability divided by the queue size:

| `N` | Board total | Per qualified member | `N` | Board total | Per qualified member |
|---:|---:|---:|---:|---:|---:|
| 121 | ₱176,000 | ₱1,454.55 | 9,841 | ₱19,076,000 | ₱1,938.42 |
| 364 | ₱579,000 | ₱1,590.66 | 29,524 | ₱60,609,000 | ₱2,052.87 |
| 1,204 | ₱2,054,000 | ₱1,705.98 | 88,573 | ₱190,208,000 | ₱2,147.47 |

The average is stable between ≈ ₱1,450 and ≈ ₱2,150: **the board costs roughly ₱2,000 per qualified
member at scale**, and that is a structural consequence of the ladder, not of behaviour.

**Coverage.** Only the first `paid(1,N) = floor((N − 1)/3)` nodes complete level 1; the first
`floor((N − 4)/9)` complete level 2; and so on. At any `N` roughly **one third** of the queue has any
completion at all:

| `N` | Nodes with L1 | Share | Nodes with L2 | Nodes with L3 |
|---:|---:|---:|---:|---:|
| 121 | 40 | 33.1% | 13 | 4 |
| 1,204 | 401 | 33.3% | 133 | 44 |
| 88,573 | 29,524 | 33.3% | 9,841 | 3,280 |

The last 59,049 nodes of an 88,573-member queue earn **₱0**. The member page says so plainly (21F
Rule 4); the reports show the coverage line.

**Concentration.** At `N = 88,573` the per-level split is:

| Level | Rows | Amount | Share |
|---:|---:|---:|---:|
| 1 | 29,524 | ₱59,048,000 | 31.0% |
| 2 | 9,841 | ₱39,364,000 | 20.7% |
| 3 | 3,280 | ₱26,240,000 | 13.8% |
| 4 | 1,093 | ₱13,116,000 | 6.9% |
| 5 | 364 | ₱9,100,000 | 4.8% |
| 6 | 121 | ₱12,100,000 | 6.4% |
| 7 | 40 | ₱10,000,000 | 5.3% |
| 8 | 13 | ₱6,240,000 | 3.3% |
| 9 | 4 | ₱10,000,000 | 5.3% |
| 10 | 1 | ₱5,000,000 | 2.6% |
| **Total** | **44,281** | **₱190,208,000** | **100%** |

The first four nodes (positions #1–#4) earn ₱8,381,000 + 3 × ₱3,381,000 = **₱18,524,000**, 9.7% of the
board; node 0 alone takes 4.4%.

**Against registration revenue at the reference price.** A *perfect* network in which every qualified
member holds three directs and every leaf holds three non-qualifying directs needs
`(3^(h+2) − 1)/2` accounts for `(3^(h+1) − 1)/2` qualified members — 265,720 accounts for 88,573
qualified. At the reference ₱5,000 registration price (`BASIC_ACCOUNT_PRICE` in the simulator):

- revenue **₱1,328,600,000**; board **₱190,208,000** → **14.3%** of the whole tree's registration fees;
- revenue from the 88,573 qualified members alone **₱442,865,000**; board **43.0%** of it.

Both numbers are true; the first is the company's, the second is the queue's. The reports show the cost
per qualified member, not a revenue percentage, because the application's registration price is the
operator's configured value and this plan does not model revenue (§18 Q5).

---

## 13. Data model

### 13.1 Entity relationship

```
users ──1:1── harvest_queue              (Phase 20; id = position, append-only)
  │                 │
  │                 └── seq = id − 1      (the board index; derived, never stored)
  │
  ├──1:n── general_harvest_awards        (user_id = the node holder; UNIQUE (seq, level))
  │                 │
  │                 └── ledger_id ──▶ ewallet_ledger.id   (ref_type = 'general_harvest')
  │
  └──1:n── users (sponsor_id)            (the tree Phase 20 and Royalty use; NOT read here)

general_harvest_levels ── 10 rows, levels 1…10, the configured amounts (catalogue)
general_harvest_board  ── 1 row: baseline_n, armed_at, armed_by, settled_n (the board's state)
settings               ── general_harvest_enabled / general_harvest_max_levels
```

There is **no board table per node**: a node is a queue row, its index is `id − 1`, and its state is a
function of `N`, `settled_n` and the ladder. Three tables are enough because the board's entire content
is derivable except the two things that must be remembered — the baseline and what has been paid.

### 13.2 `general_harvest_board` — the singleton state row

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `TINYINT UNSIGNED` | no | — | Always `1`; the singleton |
| `baseline_n` | `INT UNSIGNED` | yes | `NULL` | **`N₀`** — the queue length at arm; `NULL` = unarmed |
| `armed_at` | `DATETIME` | yes | `NULL` | When the baseline was recorded |
| `armed_by` | `INT UNSIGNED` | yes | `NULL` | The staff member who armed it; `NULL` when armed by the CLI without a session |
| `settled_n` | `INT UNSIGNED` | no | `0` | **The settled queue length**; always `≥ baseline_n` and `≤ N` |
| `updated_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Last change |

Keys: `PRIMARY KEY (id)`, `CONSTRAINT fk_ghb_armed_by FOREIGN KEY (armed_by) REFERENCES users (id) ON
DELETE SET NULL`.

Checks: `chk_ghb_singleton CHECK (id = 1)`,
`chk_ghb_armed CHECK ((baseline_n IS NULL AND armed_at IS NULL) OR (baseline_n IS NOT NULL AND armed_at
IS NOT NULL))`, `chk_ghb_settled CHECK (baseline_n IS NULL OR settled_n >= baseline_n)`.

The row is created by the migration and the install seed, **reset** (not deleted) by the business reset,
and locked `FOR UPDATE` by every settlement — it is the stream's serialisation point and its two
memorable numbers.

### 13.3 `general_harvest_levels` — the ten amounts

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `level` | `TINYINT UNSIGNED` | no | — | 1–10, the primary key |
| `amount` | `DECIMAL(12,2)` | no | `0.00` | The flat bonus for completing this level |
| `updated_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Last edit |
| `updated_by` | `INT UNSIGNED` | yes | `NULL` | The staff member who last saved the ladder |

Keys: `PRIMARY KEY (level)`. Checks: `chk_ghl_level CHECK (level BETWEEN 1 AND 10)`,
`chk_ghl_amount CHECK (amount >= 0)`.

Catalogue, not money: **kept** by a business reset, like `package_levels`, `product_unilevel_levels` and
`team_harvest_levels`. Ten rows always exist after a fresh install or a migration (21A Rule 9).

### 13.4 `general_harvest_awards` — the money ledger

One row per `(node, level)` decision. There is **no type column, no period, no order, no product, no
rate** — the node and the level are the whole story.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `INT UNSIGNED` | no | auto | PK; also the wallet `ref_id` (the ledger's `ref_id` is `INT UNSIGNED`, so this must not be `BIGINT`) |
| `seq` | `INT UNSIGNED` | no | — | **The node index** (`position − 1`); the money is attached to the node, not the account |
| `user_id` | `INT UNSIGNED` | no | — | The node holder; FK → `users.id`, `ON DELETE CASCADE` |
| `level` | `TINYINT UNSIGNED` | no | — | 1–10 |
| `threshold_n` | `INT UNSIGNED` | no | — | `N_min(seq, level)` in force when the row was written (snapshot) |
| `n_at_award` | `INT UNSIGNED` | no | — | The queue length at that moment (snapshot) |
| `amount` | `DECIMAL(12,2)` | no | — | The configured amount at that moment (snapshot) |
| `status` | `ENUM('credited','baseline')` | no | `credited` | `baseline` = full before go-live, recorded, never paid |
| `ledger_id` | `INT UNSIGNED` | yes | `NULL` | The `ewallet_ledger.id` credited; `NULL` for every `baseline` row |
| `credited_at` | `DATETIME` | no | — | When the block was decided (the arm instant for `baseline` rows) |
| `created_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP` | Row write time |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY uq_gha_seq_level (seq, level)` — **the money anchor** —
`KEY idx_gha_user (user_id, level)`, `KEY idx_gha_level (level, seq)` (the frontier read),
`KEY idx_gha_credited (credited_at)`, `KEY idx_gha_status (status)`, `KEY idx_gha_ledger (ledger_id)`.

Checks: `chk_gha_level CHECK (level BETWEEN 1 AND 10)`, `chk_gha_amount CHECK (amount >= 0)`,
`chk_gha_n CHECK (n_at_award >= threshold_n)`,
`chk_gha_baseline CHECK (status <> 'baseline' OR ledger_id IS NULL)`.

**There is no `voided` status** in v1 (D-13). A row is written once; the only field ever updated is
`ledger_id`, in the transaction that wrote the row.

### 13.5 The wallet ENUM and the settings

**`ewallet_ledger.ref_type`** gains `general_harvest`, appended **last** so MySQL can use the in-place
`ENUM` path (the same technique as 19A's `royalty` and 20A's `team_harvest`):

```
ENUM('commission','payout','transfer','topup','registration','shop_order',
     'adjustment','refund','unilevel','unilevel_reversal','royalty','team_harvest','general_harvest')
```

**`WalletService::REF_TYPES`** gains `'general_harvest'` in the same commit (21A Rule 7), and
`wallet_ref_label('general_harvest')` returns *"General Harvest bonus"* with the icon `bx-grid-alt`.

**The two settings**

| Key | Default | Rule | Exposed as |
|---|---|---|---|
| `general_harvest_enabled` | `'0'` | `in:0,1` | *Enable the General Harvest bonus* toggle |
| `general_harvest_max_levels` | `'10'` | required, integer, 1–10 | *Levels that pay* (number) |

There is deliberately **no baseline key**: the baseline is money-critical state that must be read and
written under the same row lock as `settled_n`, so it lives on the board row (§13.2), not in a key-value
store that any settings save could overwrite.

### 13.6 Migration `025_general_harvest.sql`

Idempotent and re-runnable, in the style of [`023_royalty_bonus.sql`](../system/database/migrations/023_royalty_bonus.sql)
and `024_team_harvest.sql`: every `CREATE TABLE` is `IF NOT EXISTS`, the `ENUM` change is guarded by an
`information_schema` probe, the settings insert never overwrites a value, the ladder insert never
overwrites an amount, and the board insert never disturbs an armed board.

```sql
-- =============================================================
--  Phase 21 migration — General Harvest bonus
--
--  Applies to a Phase 20 database and adds:
--
--    general_harvest_levels   the ten configured amounts (catalogue)
--    general_harvest_board    the singleton: baseline_n, armed_at, settled_n
--    general_harvest_awards   the General Harvest money ledger
--
--    ewallet_ledger.ref_type + 'general_harvest'
--    two settings (the stream is off by default)
--    the ten reference ladder rows and the unarmed board row
--
--  No backfill: the baseline is recorded by
--  bin/general_harvest_arm.php, on demand, with a dry run (plan D-07).
--  Nothing in the application reads the ledger until 21D; 21A ships
--  it empty and inert.
--
--      mysql -u root <db> < database/migrations/025_general_harvest.sql
--
--  Idempotent: every CREATE TABLE is IF NOT EXISTS; the ENUM change
--  is guarded; the settings insert is ON DUPLICATE KEY UPDATE
--  key_name = key_name; the ladder insert is ON DUPLICATE KEY UPDATE
--  level = level; the board insert is ON DUPLICATE KEY UPDATE id = id.
--  Running the file twice changes nothing and never overwrites an
--  operator's amount or an armed board.
--
--  No `down` note: the tables are additive. Dropping them would erase
--  the record of every node and every award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. ewallet_ledger.ref_type — append 'general_harvest' -----------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type'
    AND COLUMN_TYPE LIKE '%general_harvest%');
SET @sql := IF(@has = 0,
  "ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM('commission','payout','transfer','topup','registration','shop_order',
          'adjustment','refund','unilevel','unilevel_reversal','royalty',
          'team_harvest','general_harvest')
     NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. general_harvest_levels — the ten amounts (catalogue) ---------
CREATE TABLE IF NOT EXISTS general_harvest_levels (
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED     NULL,
  PRIMARY KEY (level),
  CONSTRAINT chk_ghl_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_ghl_amount CHECK (amount >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3. general_harvest_board — the singleton state -------------------
CREATE TABLE IF NOT EXISTS general_harvest_board (
  id          TINYINT UNSIGNED NOT NULL,
  baseline_n  INT UNSIGNED     NULL,
  armed_at    DATETIME         NULL,
  armed_by    INT UNSIGNED     NULL,
  settled_n   INT UNSIGNED     NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ghb_armed_by FOREIGN KEY (armed_by) REFERENCES users (id)
    ON DELETE SET NULL,
  CONSTRAINT chk_ghb_singleton CHECK (id = 1),
  CONSTRAINT chk_ghb_armed CHECK (
    (baseline_n IS NULL AND armed_at IS NULL)
    OR (baseline_n IS NOT NULL AND armed_at IS NOT NULL)),
  CONSTRAINT chk_ghb_settled CHECK (baseline_n IS NULL OR settled_n >= baseline_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 4. general_harvest_awards — the money ledger ---------------------
CREATE TABLE IF NOT EXISTS general_harvest_awards (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  seq          INT UNSIGNED     NOT NULL,
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
  UNIQUE KEY uq_gha_seq_level (seq, level),
  KEY idx_gha_user (user_id, level),
  KEY idx_gha_level (level, seq),
  KEY idx_gha_credited (credited_at),
  KEY idx_gha_status (status),
  KEY idx_gha_ledger (ledger_id),
  CONSTRAINT fk_gha_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_gha_level    CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_gha_amount   CHECK (amount >= 0),
  CONSTRAINT chk_gha_n        CHECK (n_at_award >= threshold_n),
  CONSTRAINT chk_gha_baseline CHECK (status <> 'baseline' OR ledger_id IS NULL)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. the two settings ---------------------------------------------
INSERT INTO settings (key_name, value) VALUES
  ('general_harvest_enabled',    '0'),
  ('general_harvest_max_levels', '10')
ON DUPLICATE KEY UPDATE key_name = key_name;

-- 6. the reference ladder (never overwrites an operator's amount) ---
INSERT INTO general_harvest_levels (level, amount) VALUES
  (1,   2000.00),
  (2,   4000.00),
  (3,   8000.00),
  (4,  12000.00),
  (5,  25000.00),
  (6, 100000.00),
  (7, 250000.00),
  (8, 480000.00),
  (9, 2500000.00),
  (10, 5000000.00)
ON DUPLICATE KEY UPDATE level = level;

-- 7. the board singleton (never disturbs an armed board) -----------
INSERT INTO general_harvest_board (id, baseline_n, armed_at, armed_by, settled_n)
VALUES (1, NULL, NULL, NULL, 0)
ON DUPLICATE KEY UPDATE id = id;
```

**Why the board row is seeded rather than created lazily:** a fresh install must already have the row,
because the installer verifies it (`general_harvest_board_rows = 1`) and because every read
(`state()`, `settle()`) must find it without a write on a read path. A lazy `INSERT IGNORE` on first read
would also race; the migration and the seed are the only creators.

### 13.7 Fresh-install mirror (`database/schema.sql`)

The four blocks above are copied into `schema.sql` **in this order**, after the Team Harvest tables and
before `impersonation_log`, with the same names, columns, keys, checks and charset, so
`MigrationTest`'s migrated-vs-fresh comparison stays green:

1. `general_harvest_levels` (after `team_harvest_awards`, before `impersonation_log`);
2. `general_harvest_board`;
3. `general_harvest_awards`;
4. the `ewallet_ledger.ref_type` ENUM with `general_harvest` last, and the comment line naming it.

`seeds/001_settings.sql` gains the two keys, the ten ladder rows and the board row, in the same order as
the migration, so the install bundle produces the same database. `install/footer.sql` prints **39
tables, 79 settings**, and the installer's `EXPECTED` gains `general_harvest_levels_rows => 10` and
`general_harvest_board_rows => 1` (21A).

### 13.8 Invariants

These are the statements the suite asserts and the reconciler re-checks. A phase that breaks one is a
defect, not a variant.

| # | Invariant | Asserted by |
|---|---|---|
| I-01 | The board is exactly one row, `id = 1`, with `baseline_n`/`armed_at` both NULL or both set | P21A-T6/T13, schema CHECKs |
| I-02 | The queue is gapless: `COUNT(*) = MAX(id)` | P21C-T12, P21D-T31/T42 |
| I-03 | Per level, the ledger is a contiguous prefix: `MIN(seq) = 0`, `MAX(seq) = COUNT − 1`, `COUNT(DISTINCT seq) = COUNT` | P21C-T11, P21D-T37 |
| I-04 | Baseline rows are exactly the nodes `0 … qFor(L, baseline_n)`, at all ten levels, and no credited row has `seq ≤ qFor(L, baseline_n)` | P21C-T10/T11/T19, P21D-T11–T13 |
| I-05 | For a priced level `≤ max_levels`, the rows are `0 … qFor(L, settled_n)`; for a level above the depth, only baseline rows (plus history) | P21D-T15/T16/T35, P21D-T42 |
| I-06 | Every `credited` row has a `ledger_id` that exists as a credit for the same member and amount; every `baseline` row has `ledger_id IS NULL` | P21D-T9/T11/T35 |
| I-07 | `Σ credited = boardTotal(settled_n) − boardTotal(baseline_n)`, and `Σ all rows = boardTotal(settled_n)` when every level pays | P21D-T3–T7/T35 |
| I-08 | `baseline_n ≤ settled_n ≤ N` | P21C-T18, P21D-T32, schema CHECK |
| I-09 | Every award's `seq + 1` is a live queue row whose `user_id` equals the award's `user_id`; no orphan | P21D-T35/T42 |
| I-10 | No settlement writes a level above the current `max_levels` | P21D-T15/T16/T22 |
| I-11 | No `(seq, level)` has two rows; a baseline row and a credited row can never both exist | P21D-T28 |
| I-12 | After a business reset the ledger is empty, the board is unarmed, the ladder and the two settings survive | P21A-T8, P21C-T17, P21D-T40 |
| I-13 | The company account and staff are never queued and therefore never awarded | P21D-T22 |
| I-14 | No member file reads a money table directly; the service is the only producer and the views call it | P21A-T15 and the 21F review |
| I-15 | The ladder has not changed since the arm: `MAX(general_harvest_levels.updated_at) ≤ general_harvest_board.armed_at`, so every row for a level carries the same amount (D-23) | P21C-T18, P21D-T25/T35, the doctor |

### 13.9 Volume and retention

| Row | Growth | Retention |
|---|---|---|
| `general_harvest_board` | exactly 1 | permanent |
| `general_harvest_levels` | exactly 10 | permanent |
| `general_harvest_awards` | ≤ 10 per node, ever; exactly `awardCount(settled_n)` rows for a settled board | permanent (it is the payout record) |
| `ewallet_ledger` (`general_harvest`) | one per credited award | permanent, like every wallet movement |

The ledger is bounded by the board, not by the members: 121 qualified members produce at most 58 rows,
364 produce 179, 1,093 produce 543 and 88,573 produce 44,281 — smaller than the Royalty stream's one row
per member per month and two orders of magnitude smaller than a busy `unilevel_awards`. There is no
pruning, no archive and no summary table, and none is needed at this application's scale.

---

## 14. The money path

### 14.1 Components

| File | Kind | Responsibility |
|---|---|---|
| `GeneralHarvestLevel` | model | the ten amounts and the structural arithmetic (blocks, thresholds, totals) |
| `GeneralHarvestBoard` | model | the singleton state, `N`, the frontier read, the backlog, the blocks |
| `GeneralHarvestAward` | model | the ledger: create, baseline, read, filters, totals, breakdowns, CSV rows |
| `GeneralHarvestService` | service | the decisions: arm, settle, statusFor, openLiability, reconcile, doctor |
| `GeneralHarvestException` | exception | `enabled`, `armed`, `unseeded`, `notGapless`, `invalidLadder`, `state`, `notMember` |
| `RegistrationService` | existing service | one added line: `onQueueChanged()` inside the registration transaction |
| `bin/general_harvest_arm.php` | CLI | the one-time baseline (§14.10) |
| `bin/general_harvest_catchup.php` | CLI | on-demand settlement, dry run, doctor (§14.11) |
| `WalletService` | existing service | the credit (`ref_type = 'general_harvest'`) and its idempotency anchor |
| `Live` | existing helper | the surface bumps after a commit |

### 14.2 Public API

```php
// GeneralHarvestLevel (21B)
amounts(): array                                  // [1 => '2000.00', …]
amount(int $level): string
slots(int $level): int                            // 3^L
blockStart(int $seq, int $level): int             // 0-based first slot
blockEnd(int $seq, int $level): int               // 0-based last slot
blockFrom(int $seq, int $level): int              // 1-based display, start+1
blockTo(int $seq, int $level): int                // 1-based display, start+3^L
threshold(int $seq, int $level): int              // N_min
apexThreshold(int $level): int                    // threshold(0, $level)
complete(int $seq, int $level, int $n): bool
filled(int $seq, int $level, int $n): int
levelsFor(int $seq, int $n): array                // per-level {filled, complete}
earnedBy(int $seq, int $n): string                // Σ amounts of completed levels
qFor(int $level, int $n): int                     // highest complete seq, −1 if none
paidCount(int $level, int $n): int
awardCount(int $n): int
boardTotal(int $n): string
nextN(int $seq, int $level): int
nextCompletion(int $n): array                     // {level, seq, position, needs_n, amount} or null
cumulative(): array
total(): string
maxLevels(): int
enabled(): bool
validate(array $amounts, int $maxLevels, bool $enabled): array
save(array $amounts, ?array $actor): void
referencePreset(): array

// GeneralHarvestBoard (21B)
state(): array                                    // {armed, baseline_n, armed_at, settled_n, enabled, max_levels}
queueN(): array                                   // {n, max_id, gapless}
gapless(): bool
frontier(): array                                 // per level {rows, complete, missing, next_n, amount}
backlog(): array                                  // {positions, pairs, amount} — derived, never stored
status(): array                                   // the settings strip's payload
positionOf(int $userId): ?int                     // queue id, null when not queued
ownerOf(int $seq): ?int                           // user id at that node
blocks(int $seq, int $n): array                   // the ten block rows for a node
blockRange(int $level, int $offset, int $limit): array   // positions + roster, privacy-filtered

// GeneralHarvestAward (21C/21D)
writeBaseline(int $seq, array $snapshot): int
create(int $seq, int $level, array $snapshot): int
linkLedger(int $awardId, int $ledgerId): void
has(int $seq, int $level): bool
forMember(int $userId): array
paidLevels(int $userId): array
levelMap(int $userId): array
gridFor(int $userId): array                       // the ten-cell board card
select(array $filters, Paginator $pager): array
normaliseFilters(array $filters): array
totals(array $filters): array
countByLevel(): array
minMaxByLevel(): array
byLevel(): array
byMonth(): array
recent(int $limit = 20): array
exportRows(array $filters): array

// GeneralHarvestService (21C/21D)
enabled(): bool
armed(): bool
baseline(): ?int
state(): array                                     // delegates to the board's singleton
excluded(): array                                  // the baseline's rows and amount
assertArmable(): void
arm(bool $dryRun = false, ?array $actor = null): array
settledN(): int
backlog(): array                                   // {positions, pairs, amount}
settle(int $maxNewPositions = 1, ?array $actor = null): array
settleAll(int $batch = 500): array
onQueueChanged(): array                            // the registration hook
statusFor(int $userId): array                      // the card's whole payload (§14.12)
openLiability(): array                             // {positions, pairs, amount}
reconcile(): array                                 // §14.13
doctor(): array                                    // §14.11
```

### 14.3 The arm algorithm

```
arm(dryRun, actor):
    CLI only; GET_LOCK('general_harvest_arm', 5) or refuse
    if enabled():                       throw enabled()
    if armed():                         throw armed()
    if team_harvest_ledger_since = '':  throw unseeded()
    validate the ladder (levels 1 … max_levels > 0, total > 0)
    q = queueN();  if not q.gapless:    throw notGapless(q)
    N₀ = q.n
    perLevel[L] = qFor(L, N₀) + 1                    # rows per level, 0 when none
    excluded    = boardTotal(N₀)                     # == Σ perLevel[L] × amount(L)
    if dryRun: print N₀, perLevel, Σ rows, excluded; return
    BEGIN
      insert baseline rows: for L, for seq 0 … qFor(L, N₀), user = ownerOf(seq),
        threshold_n = threshold(seq, L), n_at_award = N₀, amount = amount(L),
        status = 'baseline', ledger_id = NULL, credited_at = NOW()
      UPDATE general_harvest_board
         SET baseline_n = N₀, armed_at = NOW(), armed_by = actor, settled_n = N₀
      audit('general_harvest.arm', {n: N₀, rows, excluded, per_level})
    COMMIT
    release lock; print the summary
```

The rows are inserted in **one multi-row `INSERT` per level** (at most ten statements), which keeps the
arm fast even for a 100,000-member queue — although at that size the arm is a one-time event measured in
seconds and the ladder is the only thing that matters.

### 14.4 The settlement algorithm

```
settle(maxNewPositions = 1): array
    if not enabled():                 return {state: 'off'}
    if not armed():                   return {state: 'unarmed'}
    maxNewPositions = max(1, maxNewPositions)
    open a transaction, or join the caller's
    board = SELECT … FROM general_harvest_board WHERE id = 1 FOR UPDATE
    q     = queueN()                                    # COUNT(*), MAX(id)
    if not q.gapless:                 throw state('not gapless')
    if q.n < board.settled_n:         throw state('queue shrank')
    target = min(q.n, board.settled_n + maxNewPositions)
    if target === board.settled_n:    return {state: 'replayed'}
    counts = SELECT level, COUNT(*) FROM general_harvest_awards GROUP BY level
    rows = []
    for L = 1 … 10:
        Q = qFor(L, target)
        if Q < 0: continue
        have = counts[L] ?? 0
        if have > Q + 1:              throw state('ledger ahead of the queue')
        if L > maxLevels(): continue                        # baseline rows already cover the prefix
        amount = amount(L)
        for qq = have … Q:
            if qq <= qFor(L, baseline_n):                    # defensive: the arm wrote these
                rows[] = {seq: qq, level: L, status: 'baseline', amount, ledger_id: null}
                continue
            if amount <= 0:                                  # unpriced: no row, reported
                unpriced[] = L; break
            rows[] = {seq: qq, level: L, status: 'credited',
                      threshold_n: threshold(qq, L), n_at_award: target, amount}
    sort rows by (user_id, level, seq)                       # lock order (§14.8)
    for row in rows:
        awardId = INSERT award (row.status, row.amount, …)
        if row.status = 'credited':
            ledgerId = wallet.credit(user, row.amount, 'general_harvest', awardId,
                                     "General Harvest level {L}")
            UPDATE award SET ledger_id = ledgerId
            result.written++; result.amount += row.amount
    UPDATE general_harvest_board SET settled_n = target
    commit (if we opened it)
    return {state: 'settled', target, written, amount, unpriced, per_level}
```

Notes:

- **The prefix read (`counts`) is the truth.** The engine never derives `have` from
  `settled_n` — it reads the ledger, so a missing row is noticed (I-03) instead of being skipped or
  re-paid.
- **`target` is a position count, not a money count.** One position can complete one block per level, so
  one inline settlement writes at most ten rows; a batch of 500 writes at most ≈ 250.
- **The baseline branch is defence in depth.** The arm wrote a `baseline` row for every
  `q ≤ qFor(L, baseline_n)`, so `have` is already past it in every healthy board; the branch exists so a
  missing baseline row is recorded as `baseline` rather than credited, and I-04 asserts it.
- **`break`, not `continue`, for an unpriced level.** Levels are independent; the loop breaks only that
  level's extension, so other levels still pay.
- **Sorting before writing** guarantees that two settlements that share a node holder take their wallet
  locks in the same order (§14.8).

### 14.5 The frontier and its proof

The frontier is **read from the ledger and cross-checked against the queue**:

| Check | How | What a failure means |
|---|---|---|
| Contiguity | `MIN(seq) = 0 AND MAX(seq) = COUNT − 1 AND COUNT(DISTINCT seq) = COUNT` | a row was deleted or inserted out of order |
| Completeness | `COUNT = qFor(L, settled_n) + 1` for `L ≤ max_levels` | a settlement was interrupted (impossible inside a transaction) or a row was deleted |
| Bounds | `COUNT ≤ qFor(L, N) + 1` | a row exists for a block the queue has not filled |
| Baseline | every `seq ≤ qFor(L, baseline_n)` is `baseline`; no `seq > qFor(L, baseline_n)` is `baseline` | the baseline moved, or an arm was re-run |

There is **no cursor table**. `settled_n` is the only stored position; `COUNT(*)` per level is the only
stored frontier. The two are related by the closed form, which is why the reconcile can be exact
(D-05, D-21).

### 14.6 Idempotency

| Layer | Mechanism | What it stops |
|---|---|---|
| 1. Position gate | `target === settled_n` → `replayed` | the common replay (a second call with no queue growth) |
| 2. Frontier read | `have = COUNT(*)` per level, and rows are inserted from `have` upward | re-writing a row that exists |
| 3. Unique anchor | `UNIQUE (seq, level)` | a raced duplicate insert: the loser gets `PDOException` 23000 and the transaction rolls back |
| 4. Wallet anchor | `UNIQUE (user_id, ref_type, ref_id, type)` | a replayed credit for the same award id |

A fifth, structural guarantee exists: **`N` and `settled_n` are monotone**, so a second settlement of
the same position has nothing new to write. Together they make the engine safe to run at any time, in
any order, any number of times — which is what makes the state-based design (D-19) usable.

### 14.7 Transaction boundary and failure policy

| Caller | Transaction | On failure |
|---|---|---|
| `RegistrationService::register()` | the registration's own transaction, which now includes the board settlement | the registration rolls back: no user, no queue row, no award, no credit. The form shows a retryable error |
| `bin/general_harvest_catchup.php` | one transaction per batch (default 500 positions) | the batch rolls back; the job reports the failure and exits non-zero; the next run retries the same positions |
| `settleAll()` in the suite | one transaction per call | as above |

Rules:

1. **The engine never opens its own transaction when called from a registration.** It detects an active
   transaction (`PDO::inTransaction()`) and joins it; otherwise it opens one per batch.
2. **Nothing is swallowed** where money is involved. A wallet failure, a missing user, a CHECK violation
   — all propagate. A caught-and-logged exception would create a "decided but unpaid" state with no
   record.
3. **The award is written before the credit**, and the credit's `ref_id` is the award id, so a
   half-committed pair is impossible: the transaction either has both or neither.
4. **Configuration states do not throw.** `off`, `unarmed`, `replayed`, `unpriced` and `beyond depth` are
   returned, not raised, so a misconfigured ladder can never stop a sign-up (D-19).
5. **A structural anomaly stops the money, not the registration.** `not gapless`, `queue shrank` and
   `ledger ahead` are raised by the catch-up and the doctor; the registration hook catches them, logs
   `general_harvest.anomaly` and returns `state`, so the sign-up succeeds and no money moves.
6. **A crash between batches is safe** (layer 1 above), and the job's summary says exactly how far it
   got.

### 14.8 Concurrency and lock order

- **The board row is the mutex.** Every settlement takes `SELECT … FOR UPDATE` on
  `general_harvest_board` (id = 1) before reading `N`, so two settlements cannot compute overlapping
  targets: the second waits and then sees the first's `settled_n`. This is what makes `settled_n`
  exact without a cursor table.
- **Wallet credits lock the member's `users` row** (`WalletService` reads it `FOR UPDATE`). A batch that
  credits two nodes takes two locks; sorting the batch ascending by `user_id` means two batches acquire
  them in the same order and cannot deadlock.
- **Awards are inserted before any credit**, and both happen while the board row is locked, so a
  settlement holding the board lock never waits on a wallet lock held by a batch that is waiting on the
  board.
- **The registration holds the queue row it inserts** (auto-increment) and then the board row; no other
  code path takes those two locks in the opposite order.
- **The arm job takes an exclusive advisory lock** (`GET_LOCK('general_harvest_arm', 5)`) and runs in one
  transaction, so a registration during an arm waits or fails cleanly rather than appending inside a
  half-built baseline.
- **Batch size bounds the hold time**: the catch-up settles 500 positions per transaction (≈ 250 rows,
  ≈ 250 wallet credits) and releases the lock between batches, so registrations are never blocked for
  the length of a backlog.

### 14.9 Performance budget

| Surface | Budget | How |
|---|---|---|
| Registration with a settlement | ≤ 5 extra queries and ≤ 10 award writes | one `COUNT`/`MAX` on the queue, one board `FOR UPDATE`, one grouped frontier `COUNT`, one insert block, one credit per award |
| A settlement batch of 500 positions | ≤ 4 queries + 1 per award | the grouped frontier, the queue count, the board row, the award inserts (batched per level), one credit per award |
| `statusFor()` | ≤ 6 queries | queue row, board row, ladder (cached settings), awards for the member, block ranges, roster page |
| The member block card | ≤ 8 queries for 25 roster rows | one roster page query, one awards query, one ladder read, one relation pass |
| The admin ledger page | ≤ 8 queries for 25 rows | one paginated select, one count, one totals, one level breakdown, one range |
| `openLiability()` / `backlog()` | ≤ 2 queries | the board row and the queue count; the rest is the closed form |

The full-board pass (`doctor()`, `reconcile()`) is O(rows) with one grouped aggregate and one
`GROUP BY level` — the same order of cost as `wallet_reconcile.php`'s existing sections.

### 14.10 The arm job (`bin/general_harvest_arm.php`)

**Purpose.** Record the go-live baseline for the queue that already exists, once, before the stream is
switched on. It is a **one-time, on-demand** job — not a cron entry.

| Flag | Effect |
|---|---|
| `--dry-run` | compute and print everything, write nothing |
| `--json` | machine-readable summary (`armed`, `n`, `rows`, `excluded`, `per_level`, `dry_run`) |
| `--quiet` | only the exit code and the one-line summary |
| `--help` | usage |

**Output (human):** `N₀`, the number of baseline rows, the excluded amount (`boardTotal(N₀)`), the
per-level row counts and the first 20 positions with their members, levels and amounts; the source of
each row (`baseline`); and, after a real run, the audit id.

**Exit codes:** 0 success (or a clean dry run); 1 refused (already armed, enabled, unseeded, invalid
ladder, not gapless); 2 unreachable database or missing table.

**Order of operations (one transaction):** lock, validate, compute, print (dry run stops here), insert,
update the board, audit, commit, print.

### 14.11 The catch-up job and the doctor (`bin/general_harvest_catchup.php`)

| Flag | Effect |
|---|---|
| `--dry-run` | settle nothing; report `N`, `settled_n`, the per-level missing rows, the open liability and the amount |
| `--member=ID` | settle the position range that affects one node only — a single position, for a dispute drill |
| `--limit=N` | positions per transaction (default 500) |
| `--doctor` | report the stream's health without settling: clock, tables, settings, armed state, gapless, queue count, settled, backlog, per-level frontier, paid, baseline, open and the reconcile verdict |
| `--json` | machine-readable payload |
| `--quiet` | summary only |

The job exists because a state-based engine must be **runnable on demand**: after enabling the stream,
after a repair, after a counter drift alarm, or after a deploy that fixed a bug. It is not a cron entry
and `docs/cron.md` says so (D-08).

**Exit codes:** 0 clean; 1 refused (unarmed, disabled) or a reconcile/anomaly failure; 2 unreachable
database.

### 14.12 Preview, status and anomaly codes

`GeneralHarvestService::statusFor($userId)` is the single payload every 21E/21F surface renders:

```
{
  queued: bool, position: ?int, seq: ?int, queue_n: int,
  armed: bool, enabled: bool, baseline_n: ?int, settled_n: int, backlog: int,
  max_levels: int, total: string, earned: string, baseline_earned: string,
  levels: [ { level, slots, from, to, filled, complete, status, amount, threshold_n,
              next_n, progress, paid_at: ?string } ],
  next: { level, from, to, filled, slots, remaining, percent, amount, needs_n } | null,
  reachable: bool,             // false when no level can complete at queue_n
  paid_levels: int, baseline_levels: int, credited_amount: string,
  roster: { level, page, total, rows: [ {position, username: ?string, relation} ] },
  open: string                 // what the engine would pay for this node if settled now
}
```

`status` is one of `paid`, `baseline`, `complete-beyond-depth`, `unpriced`, `progress`, and `waiting`
(the block is not full — the normal state). `relation` is `self`, `direct`, `in-team` or `outside`, and
the username is `null` for `outside` (D-20).

`openLiability()` is the same computation across the board: `boardTotal(N) − boardTotal(settled_n)`,
with the per-level pair counts. It is **derived on read**; it is never stored, because it changes with
every qualification.

**Anomaly codes** raised by `GeneralHarvestException::state()`:

| Code | Raised when | Who sees it |
|---|---|---|
| `not gapless` | `COUNT(*) <> MAX(id)` on the queue | arm, catch-up, doctor; the registration hook logs and continues |
| `queue shrank` | `N < settled_n` | catch-up, doctor |
| `ledger ahead of the queue` | a level's rows exceed `qFor(L, target) + 1` | catch-up, doctor |
| `ledger behind settled_n` | a level's rows are fewer than `qFor(L, settled_n) + 1` for a priced level | the doctor (the settlement repairs it by writing the missing rows) |
| `not a member` | a settlement is asked to pay a `role <> 'member'` node | the engine (impossible via the queue) |

### 14.13 Reconciliation

`GeneralHarvestService::reconcile()` returns `ok` plus the checks it ran. `bin/wallet_reconcile.php`
calls it and folds the verdict into its exit code.

| Check | Failure means |
|---|---|
| `Σ credited` awards = `Σ general_harvest` ledger credits | a credit was lost, duplicated or mis-typed |
| `Σ credited` = `boardTotal(settled_n) − boardTotal(baseline_n)` | the ledger and the closed form disagree (a missing/extra row, or a hand edit) |
| `Σ baseline` = `boardTotal(baseline_n)` and every baseline row has `ledger_id IS NULL` | a pre-go-live block was paid, or the baseline moved |
| Every `credited` award has a `ledger_id` that exists and is a credit for the same user | a dangling or cross-member ledger reference |
| Every `general_harvest` ledger row has an award (`ref_id`) | an orphan credit (a deleted award) |
| Per level, rows are a contiguous prefix and, for a priced level `≤ max_levels`, equal `qFor(L, settled_n) + 1` | a deleted or duplicated row, or an interrupted settlement |
| `COUNT(*) = MAX(id)` on the queue | a queue hole (D-03) |
| Every award's `seq + 1` is a live queue row with the same `user_id` | a stale or orphan award |
| `baseline_n ≤ settled_n ≤ N` | a corrupted board row |
| The ladder has ten rows, each ≥ 0, and every level `≤ max_levels` is > 0 | a truncated or corrupted configuration |
| The ladder has not changed since the arm: `MAX(general_harvest_levels.updated_at) ≤ general_harvest_board.armed_at` (D-23) | an amount was edited behind the freeze — the closed-form amount check cannot be trusted |

A failed check prints the level, the node, the member and both numbers. The reconciler never repairs:
the repair is the catch-up's settlement for missing rows, and a forward-only business decision for
money (§18 Q3).

### 14.14 Enablement and rollout

The go-live order is **fixed** and documented in one place (`docs/runbook.md` §General Harvest):

1. Deploy 21A–21D with `general_harvest_enabled = '0'`. Nothing can pay: no ladder is configured beyond
   the seeded preset, the board is unarmed and no settlement runs.
2. Confirm Phase 20's queue is seeded (`team_harvest_ledger_since` is a datetime). If it is not, stop:
   the arm will refuse and the board has no `N` worth recording.
3. Set the ladder and the depth on `/admin/settings`; confirm the full-ladder total on the card. **Price
   all ten levels** — the arm refuses a zero amount at any level, because the amounts freeze at that
   instant and a zero could never be paid afterwards (D-23).
4. Run `general_harvest_arm.php --dry-run`. Read `N₀`, the baseline rows and the excluded amount.
5. Decide: proceed, or wait. Nothing is written yet.
6. Run `general_harvest_arm.php` for real. The baseline is now history; the board is armed and off.
7. Turn `general_harvest_enabled` on. From this second, every queue append settles one position.
8. Run `general_harvest_catchup.php` once to settle the backlog created between the arm and the switch.
9. Run `php bin/wallet_reconcile.php` and `general_harvest_catchup.php --doctor`.

Steps 4–8 are the only window in which a block can be full but not yet paid, and it exists on purpose:
it lets the operator see the excluded amount and the open liability before accepting them. A quiet first
day makes the backlog zero and the window irrelevant.

### 14.15 There is no monthly close

The stream has no period, no cut-off, no `--evaluate-only`/`--pay-only` split, no resumable payment batch
and no cron entry. The reasons, stated once so nobody adds one later:

1. **There is nothing to aggregate.** A block completes when the queue reaches a position; the amount is
   fixed; there is no volume to sum for a month and no rate to apply at a cut-off.
2. **There is nothing to wait for.** Royalty is paid after a month because the month is its unit. General
   Harvest's unit is a position.
3. **A close would be a second place for the same money to be wrong.** The settlement is already
   idempotent and re-runnable; a monthly close would add a state machine around an award that is already
   atomic.
4. **The audit trail is better.** Every award carries `credited_at`, `threshold_n` and `n_at_award`, so a
   finance reviewer sees exactly when and on what evidence a block paid — which a monthly header would
   only obscure.

The one thing a close would give — a scheduled moment when everything is definitely settled — is
provided instead by the catch-up job and its doctor, run on demand.

---

## 15. Security, audit and abuse control

### 15.1 Guards and input handling

| Surface | Guard |
|---|---|
| The settings card | admin/superadmin only (the existing `AdminSettingsController` guard); CSRF token on the form; every value re-validated on the server (21B Rules 2–5); the tenant-wide `settings.update` audit plus `general_harvest.ladder.update` with before/after |
| The ledger tab, the board card, the member panel | the existing `/admin/commissions` and `/admin/members` guards; a member is refused with 403 (P21E-T17) |
| The member tab and its CSV | session-scoped at the **model** (`WHERE user_id = ?`); `?user_id=` is ignored (21F Rule 12) |
| The block roster | a username is rendered only when the member is in the viewer's own sponsor subtree (D-20); the relation is computed server-side |
| The export endpoints | same guard as the page; the filter set is normalised; the audit row records the filters used |
| The two CLI jobs | CLI only (`PHP_SAPI === 'cli'`); no web entry ships for either, so there is no URL to authenticate; the arm takes a database advisory lock |

No new permission, no new role and no new route is created (D-15). The two settings and the ladder are
the only writable configuration.

### 15.2 Money integrity

The stream's money is protected by five independent mechanisms, and every one has a test:

1. **The award ledger is append-only.** No code path updates or deletes a `general_harvest_awards` row
   (D-13). A correction is a forward-only decision (§18 Q3).
2. **The unique anchor.** `UNIQUE (seq, level)` makes a second payment for the same node and level
   impossible at the database level (§14.6).
3. **The prefix frontier.** The paid set must be `0 … count−1`, so a deleted row is detected instead of
   silently re-paid (I-03).
4. **The wallet's own anchor.** `UNIQUE (user_id, ref_type, ref_id, type)` makes a second credit for the
   same award impossible even if the award insert were replayed.
5. **The reconciler and the closed form.** `wallet_reconcile.php` compares the ledger with the wallet
   **and** with `boardTotal()`, and fails the build on any disagreement (§14.13). It is part of 21D's
   gate, not a later add-on.

A manual `UPDATE general_harvest_awards SET amount = …` is detected by the closed form (the per-level
total no longer equals `amount(L) × rows`) and by the phase log's rule that the tables are never edited
by hand. There is no admin UI that can edit an award.

### 15.3 Abuse and economic controls

The reference says the gates are "cheap by design" (reference §8.5). This plan keeps them cheap and
names the exposure rather than hiding it.

**What a self-dealing ring would have to buy.** To be paid the first level (₱2,000) the ring must place
**13 accounts** — the node holder plus three qualified members, each of which needs three direct members:
`1 + 3 × 4 = 13`. To fill level 2 (₱6,000 cumulative) it needs `N = 13`, i.e. **40 accounts**. At the
reference ₱5,000 registration price:

| Board milestone | `N` | Accounts needed | Spend at ₱5,000 | Board paid | Return on spend |
|---|---:|---:|---:|---:|---:|
| Node 0 level 1 | 4 | 13 | ₱65,000 | ₱2,000 | 3.1% |
| Node 0 levels 1–2 | 13 | 40 | ₱200,000 | ₱12,000 | 6.0% |
| Node 0 levels 1–3 | 40 | 121 | ₱605,000 | ₱50,000 | 8.3% |
| Node 0 levels 1–4 | 121 | 364 | ₱1,820,000 | ₱176,000 | 9.7% |
| The perfect network's whole board | 88,573 | 265,720 | ₱1,328,600,000 | ₱190,208,000 | 14.3% |

The ring's total income also includes the referral ladders (Indirect ₱765 per registration over ten
levels and the package levels), so the table is not the ring's net; but at reference prices the board is
a **single-digit percentage** of what the ring spends, and it gets worse for the ring at every larger
milestone. At a materially lower registration price it would not, and that is the operator's variable,
not this plan's (reference §8.5).

| Exposure | Control |
|---|---|
| Grinding the early board with fresh accounts | 13 accounts per ₱2,000 at the default gate; the board card and the reports publish paid ÷ qualified members and the coverage line |
| One person operating many accounts | The existing registration/activation requirements (package or code) and the existing duplicate checks; this stream adds no bypass |
| A deep, wide legitimate network paying more than the company funds | The ladder is the plan; the settings card states the full-ladder total, the reports show the board's cost per qualified member, and the stream ships off (D-10) |
| A corrupted queue inflating `N` | `N` is `COUNT(*)` and `MAX(id)`; the gapless check and the reconcile fail closed (D-03) |
| A reparenting or deletion changing a node | Neither exists in the application; the queue is append-only and the reset clears it (Phase 20) |
| An operator lowering the gate to 1 to "test" | The gate is 1–10 (Phase 20's setting); the audit row records who changed it; positions are unaffected |
| An operator arming twice | `armed_at` refuses the second run; a reset is the only way to a new baseline (D-07) |

### 15.4 Audit trail

| Action | Written by | Payload |
|---|---|---|
| `general_harvest.ladder.update` | the settings save (21B) | the ten amounts and the two knobs, before and after |
| `general_harvest.arm` | the arm job (21C) | `N₀`, rows written, excluded amount, per-level counts, dry run or not |
| `general_harvest.anomaly` | the registration hook (21D) | the code, `N`, `settled_n`, the level and the node |
| `general_harvest.catchup` | the catch-up job (21D) | batches, positions, levels paid, amount, dry run or not |
| `general_harvest.export` | both CSV exports (21E/21F) | which tab, which filters |

Not audited, on purpose: **each individual award**. The award row *is* the record — it carries the node,
the level, the threshold, the `N`, the amount, the instant and the wallet reference. Duplicating that
into `audit_log` would double the write volume of the busiest path in this stream for no information.

### 15.5 Privacy and data minimisation

- The block roster shows a teammate's **position** to everyone, and a **username only inside the
  viewer's own sponsor subtree** (D-20). The genealogy page already shows usernames to their uplines;
  this stream adds no disclosure to a wider audience and, unlike the simulator, no disclosure across
  team lines.
- The member statement shows the member's own rows only; the CSV is scoped in the model.
- The admin pages show what an administrator already sees on `/admin/members`.
- The two CLI jobs print usernames in their human output (operator-only surfaces) and ids in `--json`.
- The board card and the member card show **aggregates** (`N`, the frontier, the open liability) — no
  member's identity is needed to read the board's state.

---

## 16. Testing harness, fixtures and QA protocol

### 16.1 Harness

The project runs a hand-rolled suite: `php system/tests/run.php` executes every file in `tests/Cases`
and prints `Cases / Passed / Failed`; `tests/bootstrap.php` provides `assert_*`, `test_pdo()`,
`test_reset_app_database()`, `test_run_sql_file()`, `test_run_isolated()`, `test_user()`, `test_order()`,
`test_signed_in_as()` and the other helpers. Each case gets a clean database (or a transaction that is
rolled back). The HTTP layer is exercised through `View::capture()` and the controllers' public context
methods; `tests/smoke.ps1` covers the real URLs.

**Baseline at `p21-start`** (the Phase 20 release gate): **1,318** cases, **≈ 263** smoke checks,
`build_install_sql --check` exit 0, `wallet_reconcile.php` exit 0, 36 tables, 77 settings, migration 024,
`APP_PHASE` 20.

**Test ID convention.** `P21X-Tn`, where `X` is the phase letter. A phase's cases live in files named for
the phase's subject, and every case name starts with its ID:

| File | Phase | Subject |
|---|---|---|
| `GeneralHarvestFoundationTest.php` | 21A | migration, enum, settings, ladder seed, board seed, install/reset parity |
| `GeneralHarvestConfigurationTest.php` | 21B | settings card, validation, audit, the generated tables |
| `GeneralHarvestBoardTest.php` | 21B | the pure arithmetic: thresholds, blocks, totals, next completions |
| `GeneralHarvestBaselineTest.php` | 21C | the arm job, baseline rows, refusals, the excluded amount |
| `GeneralHarvestEngineTest.php` | 21D | settle, depth, baseline, unpriced, idempotency, money |
| `GeneralHarvestCatchupTest.php` | 21D | the CLI: dry run, limit, doctor, exit codes |
| `GeneralHarvestReconcileTest.php` | 21D | reconcile, `wallet_reconcile` section, reset integrity pack |
| `GeneralHarvestAdminViewTest.php` | 21E | ledger tab, board card, member panel, filters, CSV |
| `GeneralHarvestReportTest.php` | 21E | the four report cards and the `general_harvest` export |
| `GeneralHarvestMemberViewTest.php` | 21F | earnings tab, block card, tile, genealogy badge, copy, privacy |
| `GeneralHarvestReleaseTest.php` | 21G | `APP_PHASE`, the documented counts (or the existing `HardeningTest`) |

`php tests/run.php GeneralHarvest` selects the whole stream; `php tests/run.php Harvest` selects the
Phase 20 queue cases too; `php tests/run.php` runs everything.

### 16.2 Fixture trees

All money fixtures use the **reference ladder**, `general_harvest_enabled = 1`, and a board the fixture
arms itself. A fixture helper (`test_general_tree(int $height)`) inserts the accounts with `test_user()`
in BFS order, runs `HarvestQueue::qualifySponsor()` for each account as it is created (the real hook
path), and returns the id map — so the fixture exercises production code, not a shortcut. The tree is a
perfect ternary board: root, three qualified children, nine qualified grandchildren, and so on, with
every leaf qualified member holding three non-qualifying directs.

| Fixture | Height | Accounts | Qualified (`N`) | Node 0's levels there | Used for |
|---|---|---:|---:|---|---|
| **A** | 1 | 13 | 4 | 1 | the first level; `boardTotal = ₱2,000` |
| **B** | 2 | 40 | 13 | 1–2 | levels 1–2; ₱12,000; the arm fixture |
| **C** | 3 | 121 | 40 | 1–3 | levels 1–3; ₱50,000; the standard fixture |
| **D** | 4 | 364 | 121 | 1–4 | levels 1–4; ₱176,000 |
| **E** | 5 | 1,093 | 364 | 1–5 | level 5; ₱579,000 |
| **F** | — | 121 | 13 → 40 | 1–3 | the baseline: C armed at B's `N`, then grown |
| **G** | — | 41 | 20 | 1–2 | the reference's §4.7 table, stopped at `N = 20` |
| **H** | — | — | — | — | the edge family: off, unarmed, hole, cycle, staff, non-qualified |

The account count is `(3^(h+2) − 1)/2` and the qualified count is `(3^(h+1) − 1)/2`; both grow by 3× per
height, which is why the deepest structural fixture is `h = 5` and levels 6–10 are proved on the pure
function (§16.5).

**Fixture F** is built by growing C's tree in two stages: the first `N = 13` members are inserted, the
board is armed at that instant (the real `arm()`), and only then are the remaining 81 accounts inserted
and settled. **Fixture G** is C's BFS order stopped at `N = 20` (root + 3 + 9 + 7 more qualified
members, each with three leaf directs = 41 accounts). **Fixture H** contains: a database with the stream
off; a database with the stream on but the board unarmed; a queue with one row deleted directly; a
sponsor cycle and a self-sponsor; an admin account with four member directs; and a member with two
directs. Each case names the fixture it uses.

The fixtures never depend on the wall clock. `credited_at` values are asserted as ranges or set
explicitly with a direct `UPDATE` for the ordering cases.

### 16.3 Money fixture table

Reference ladder, all verified against `GeneralHarvestLevel::boardTotal()` / `earnedBy()` and the tree
simulation:

| Fixture | Node | `N` | Levels | Computation | Amount |
|---|---|---:|---|---|---:|
| A | 0 | 4 | 1 | 2,000 | **2,000.00** |
| A | 1–3 | 4 | — | below 7 | **0.00** |
| B | 0 | 13 | 1–2 | 2,000 + 4,000 | **6,000.00** |
| B | 1–3 | 13 | 1 | 2,000 | **2,000.00** |
| B | network | 13 | | 6,000 + 3 × 2,000 | **12,000.00** |
| C | 0 | 40 | 1–3 | 2,000 + 4,000 + 8,000 | **14,000.00** |
| C | 1–3 | 40 | 1–2 | 2,000 + 4,000 | **6,000.00** |
| C | 4–12 | 40 | 1 | 2,000 | **2,000.00** |
| C | network | 40 | | 14,000 + 3 × 6,000 + 9 × 2,000 | **50,000.00** |
| D | 0 | 121 | 1–4 | + 12,000 | **26,000.00** |
| D | 1–3 | 121 | 1–3 | 14,000 | **14,000.00** |
| D | 4–12 | 121 | 1–2 | 6,000 | **6,000.00** |
| D | 13–39 | 121 | 1 | 2,000 | **2,000.00** |
| D | network | 121 | | 26,000 + 3 × 14,000 + 9 × 6,000 + 27 × 2,000 | **176,000.00** |
| E | network | 364 | | the closed form | **579,000.00** |
| G | 0 | 20 | 1–2 | 2,000 + 4,000 | **6,000.00** |
| G | 1–5 | 20 | 1 | 2,000 | **2,000.00** |
| G | 6 | 20 | — | needs `N = 22` | **0.00** |
| F | 0 | 13 → 40 | 3 | baseline L1–L2, credit L3 | **8,000.00** |
| F | 1–3 | 13 → 40 | 2 | baseline L1, credit L2 | **4,000.00** |
| F | 4–12 | 13 → 40 | 1 | credit L1 | **2,000.00** |
| F | network | 13 → 40 | | 8,000 + 3 × 4,000 + 9 × 2,000 | **38,000.00** |
| `max_levels = 2` | 0 | 40 | 1–2 | 2,000 + 4,000 | **6,000.00** |
| `max_levels = 2` | network | 40 | | 42,000 total, 17 rows | **42,000.00** |

The per-level amounts and the cumulative column are Appendix A.

### 16.4 Existing cases to update, by phase

| Phase | File | Why |
|---|---|---|
| 21A | `MigrationTest.php` | add `025_general_harvest.sql` to the ordered list; a new P21A-T3 case; migrated-vs-fresh stays green; an idempotent re-run and an ENUM assertion |
| 21A | `InstallTest.php` | `tables_created 39`, `settings_rows 79`, `general_harvest_levels_rows 10`, `general_harvest_board_rows 1`, board unarmed |
| 21A | `InstallSqlTest.php` | the header/footer count and any count assertion |
| 21A | `SeedParityTest.php` | no edit expected: it fails unless seed 001 carries both keys — a useful guard |
| 21A | `DbResetTest.php` | the 36-name helper becomes 39; the zero list gains the ledger; the auto-increment list gains it; the board is unarmed after a reset |
| 21A | `DbTargetTest.php` / `SqlScriptTest.php` | the hard-coded table count 36 → 39 |
| 21A | `WalletTest.php` | `REF_TYPES` contains `general_harvest` and the ENUM matches (P5-T8) |
| 21B | `SettingsPageTest.php` | the new fields, toggles and ten amounts; the default ladder; a save round-trip; validation |
| 21B | `AdminSettingsLiveTest.php` | the `settings-commissions` fragment contains the General Harvest sub-block |
| 21C | `HardeningTest.php` | no change; the registration path's existing cases must stay green with the arm in place |
| 21D | `WalletTest.php` | a `general_harvest` credit is accepted and labelled |
| 21D | `AuditLogTest.php` | the new actions are recorded |
| 21D | `DbResetTest.php` | the reset's integrity pack now runs the General Harvest reconcile |
| 21D | `LaunchTest.php` / registration views | regression only: the registration form behaves identically whether or not a settlement pays somebody |
| 21E | `AdminCommissionLiveTest.php` | the fifth tab, its fragments and its ETag |
| 21E | `AdminMemberLiveTest.php` | the `GH` column, the board card and the member panel |
| 21E | `ReportTest.php` / `ReportViewTest.php` | the four cards and the `general_harvest` export type |
| 21E | `AdminDashboardLiveTest.php` | the General Harvest KPI |
| 21F | `MemberEarningsLiveTest.php` | the fifth tab, the block card and its live revision |
| 21F | `MemberDashboardLiveTest.php` | the General Harvest tile |
| 21F | `MemberGenealogyLiveTest.php` | the board badge |
| 21G | `HardeningTest.php` | `APP_PHASE === 21` |
| 21G | `tests/smoke.ps1` | the Phase 21 URL checks accumulated by 21B–21F (≈ 15 checks), including the unarmed-board empty state and the three-member registration Phase 20 added |

### 16.5 The pure-function cases for the deep levels

Levels 6–10 need 1,093 to 88,573 qualified members. Building such a tree in the suite would cost
millions of rows and minutes of runtime, so the deep levels are proved on the **pure function** and the
tree walk is proved up to level 5 (fixture E, `N = 364`):

| `N` | Board total | Awards | Node 0 earned | Node 1 earned | Node 12 earned |
|---:|---:|---:|---:|---:|---:|
| 3 | 0 | 0 | 0.00 | 0.00 | 0.00 |
| 4 | 2,000 | 1 | 2,000.00 | 0.00 | 0.00 |
| 7 | 4,000 | 2 | 2,000.00 | 2,000.00 | 0.00 |
| 13 | 12,000 | 5 | 6,000.00 | 2,000.00 | 0.00 |
| 22 | 22,000 | 9 | 6,000.00 | 6,000.00 | 0.00 |
| 40 | 50,000 | 18 | 14,000.00 | 6,000.00 | 2,000.00 |
| 121 | 176,000 | 58 | 26,000.00 | 14,000.00 | 6,000.00 |
| 364 | 579,000 | 179 | 51,000.00 | 26,000.00 | 14,000.00 |
| 1,093 | 1,888,000 | 543 | 151,000.00 | 51,000.00 | 26,000.00 |
| 3,280 | 6,065,000 | 1,636 | 401,000.00 | 151,000.00 | 51,000.00 |
| 9,841 | 19,076,000 | 4,916 | 881,000.00 | 401,000.00 | 151,000.00 |
| 29,523 | 57,228,000 | 14,748 | 881,000.00 | 881,000.00 | 151,000.00 |
| 29,524 | 60,609,000 | 14,757 | 3,381,000.00 | 881,000.00 | 401,000.00 |
| 88,572 | 181,827,000 | 44,271 | 3,381,000.00 | 3,381,000.00 | 401,000.00 |
| 88,573 | 190,208,000 | 44,281 | 8,381,000.00 | 3,381,000.00 | 881,000.00 |
| 147,621 | — | — | 8,381,000.00 | 3,381,000.00 | 881,000.00 |
| 147,622 | — | — | 8,381,000.00 | 8,381,000.00 | 881,000.00 |

These rows are one table-driven case in `GeneralHarvestBoardTest` and again in
`GeneralHarvestEngineTest` (the second compares a settlement's ledger with `earnedBy()`). The `N = 364`
tree is the deepest *structural* proof; the arithmetic above it is not a tree property.

### 16.6 The QA pack

`docs/qa/phase-21-general-harvest.md` is created by 21A with **section A only**, and each later phase
appends its section before its gate. It is never rewritten, only appended. Each section:

- states the phase's prerequisites (the previous tag, the fixtures) and its expected suite count;
- lists every check as `QA21X-nn` with the exact URL or command, the exact expected number, and one line
  on what to do when it fails;
- ends with a sign-off line (tester, date, evidence file names).

**58 checks** in total: A 8, B 9, C 8, D 10, E 8, F 9, G 6 — seven sections, the same shape as the
Phase 17, 19 and 20 packs. Evidence lives in `docs/qa/evidence/phase-21/`, one file per check
(`QA21A-01.png`, `QA21D-04.txt`, …), indexed by that folder's `README.md`. A check that produces only a
count is captured as a text transcript rather than a screenshot.

`docs/qa/README.md` and `docs/qa/sign-off-sheet.md` gain one Phase 21 row when 21A lands, and the row is
finalised (check count, time needed, signed) by 21G.

### 16.7 Regression policy

The suite is cumulative. After each phase, every case from every earlier phase still runs, and the
phase's gate requires the **whole** suite green — not just its own file. Five regressions matter most and
are asserted by name:

- `P21E-T1` — the package, repeat-purchase, Royalty and Team Harvest commission tabs are unchanged.
- `P21F-T9` — the package, repeat-purchase, Royalty and Team Harvest earnings tabs are unchanged.
- `P21D-T33` — the registration transaction still commits as one unit with the settlement hook in place.
- `P21A-T15` — no application file outside the manifest names the new tables (until the phase that owns
  it).
- `P21D-T26` — a settlement replay moves no money.

The referral, unilevel, Royalty and Team Harvest ledgers, the wallet statement, the payout path and the
registration form must be unchanged in behaviour; the suite's own counts and the named regression cases
enforce it.

### 16.8 Acceptance matrix

| Scenario | Expected | Test |
|---|---|---|
| First level ever (`N = 4`) | node 0 ₱2,000 | `P21D-T1` / Appendix B.1 |
| Reference 20-member example | board ₱16,000 | `P21D-T6` / Appendix B.2 |
| Fixture C, network | ₱50,000 | `P21D-T3` |
| Fixture D, network | ₱176,000 | `P21D-T4` |
| Partial block (`N = 39` at node 0, L3) | nothing for L3 | `P21D-T3` boundary |
| Level 10 threshold | ₱8,381,000 at `N = 88,573` | `P21D-T7` |
| One below the threshold | ₱3,381,000 at `N = 88,572` | `P21D-T7` |
| Baseline | recorded, never paid | `P21C-T1`, `P21D-T11–T13` |
| Stream off | queue grows, no money | `P21D-T18` |
| Unarmed | no money, no exception | `P21D-T20` |
| Replay | no double pay | `P21D-T26–T28` |
| `max_levels = 2` | ₱42,000, level 3 unwritten | `P21D-T15` |
| Ladder changed after the arm | refused (D-23); history unchanged | `P21D-T25` |
| Ledger drift | reported, repairable | `P21D-T37/T38`, `P21D-T42` |
| Reconcile | clean on a clean build | `P21D-T35/T39` |
| Reset | zero rows, board unarmed, ladder kept | `P21A-T8`, `P21C-T17` |
| Install parity | 39 tables / 79 settings / 10 levels / 1 board row | `P21A-T6` |

---

## 17. Risks and mitigations

| # | Risk | Likelihood | Impact | Mitigation | Detected by |
|---:|---|---|---|---|---|
| 1 | **The registration hook breaks sign-ups** — the application's most critical path | low | high | One line inside an existing transaction; guarded by `enabled()`/`armed()`; configuration states never throw; the phase's first tests include the rollback case; the smoke suite registers members | 21D gate, smoke, `P21D-T33` |
| 2 | **The board pays more than the company funded** — the ladder is fixed, the network is not | medium | high | The stream ships off and unarmed; the settings card states the full-ladder total; the reports publish the cost per qualified member and the coverage; the perfect-network ceiling is published (§12.12) | 21B card, 21E reports, weekly runbook |
| 3 | A ring grinds the early board with fresh accounts | medium | medium | 13 accounts per ₱2,000 at the default gate (3.1% of spend at the reference price); registration has a price; the board card and the coverage line expose a burst (§15.3) | 21E reports |
| 4 | **A queue hole shifts every later node** and the board pays the wrong members | low | high | The gapless invariant (D-03) is checked at arm and at every settlement; a hole fails closed and is reported; the repair is restore-from-backup; no application path deletes a member | `P21C-T12`, `P21D-T31/T42` |
| 5 | **The baseline is taken twice, or at the wrong `N`** | low | high | `armed_at` refuses a second arm; an advisory lock serialises; the stream must be off; the dry run prints `N₀` and every row before writing | `P21C-T2/T8/T14`, runbook order |
| 6 | **A pre-go-live block pays** | low | high | The rule is expressed twice (`seq ≤ qFor(L, baseline_n)` and the `baseline` row); baseline rows are written for all ten levels at arm; three cases pin it | `P21C-T19`, `P21D-T11–T13` |
| 7 | **A double credit** across a replay, a backlog or a re-enable | low | high | Four idempotency layers (§14.6), the prefix frontier, the unique anchor, the monotone `settled_n`, and five cases | `P21D-T26–T30` |
| 8 | A ladder fat-finger commits millions | medium | high | Per-level and total ceilings, the full-ladder sentence on the card, the audit row, and the freeze at the arm (D-23) so every row for a level carries one amount | 21B gate, `P21B-T4/T5`, `P21D-T25` |
| 9 | **The frontier stalls on an unpriced level** and money silently stops | medium | medium | A payable level must be positive (D-17); the settlement reports `unpriced`; the doctor and the board card name the level; restoring the amount pays the backlog in one run | `P21D-T17/T42` |
| 10 | A deadlock between two settlements that share a node holder | low | medium | The board row is the mutex; ascending `user_id` order; awards before credits; one transaction per batch | `P21D-T30` |
| 11 | A wallet credit fails and the award is left alone | low | high | The award and the credit are one transaction; nothing is swallowed; a failed batch rolls back and retries | `P21D-T33/T35`, the catch-up's exit code |
| 12 | The reset leaves a stale baseline or a stale ledger | medium | medium | The reset updates the singleton to unarmed and empties the ledger; `P21A-T8`, `P21C-T17`, `P21D-T40` | 21A/21C gates |
| 13 | An operator enables the stream and the backlog surprises them | medium | medium | The board card shows the backlog and its value; `openLiability()`; the catch-up dry run; the nine-step go-live order | 21B card, `QA21D-07/08` |
| 14 | A member reads the board as a team bonus | medium | low | Copy Rule 2's sentence is mandatory and singular; the tile does not repeat it; the word search in the exit gate | 21F gate, `P21F-T13` |
| 15 | **A high-position member expects money that cannot come** | medium | medium | Copy Rule 4's mandatory sentence with the exact `N`; the block card shows `0 of N` with the gap; the dashboard tile shows the gap, not a promise | 21F gate, `P21F-T4/T22` |
| 16 | A stranger's username leaks across team lines | low | medium | D-20: the roster renders a username only inside the viewer's subtree; the relation is computed server-side | `P21F-T22/T23` |
| 17 | The admin pages disagree with the member page | low | medium | Both read `statusFor()` and the award model; the 21E ledger is the proof 21F must match | `P21E-T3/T13`, `P21F-T20` |
| 18 | The board card's open liability disagrees with the ledger | low | high | Both are the closed form (`boardTotal`), not a view expression; the reconcile compares them | `P21D-T23/T35`, `P21E-T15` |
| 19 | A phase is declared done on partial evidence | medium | high | The universal gate (§2.4) requires the full suite, smoke, a tester who did not write the code, and a filled Appendix H row | every gate |
| 20 | Someone "fixes" a node by renumbering the queue | low | high | Rule 1 of §2.5, D-01/D-03, the append-only contract of Phase 20, and the runbook's explicit prohibition | review of every diff touching `harvest_queue` |

---

## 18. Open questions

Each question has an owner decision, not a plan change. A decision is recorded in `docs/phase-log.md`
and, if it changes a rule, the plan is amended before the code.

| # | Question | Why it is open | Default if nobody decides |
|---:|---|---|---|
| Q1 | **Should the pre-go-live blocks be paid?** The arm's dry run quantifies them; D-06 pays none | It is a cash decision the company makes, not a mechanic; paying it would be a one-time run with its own budget | Not paid. The arm records the amount and the report keeps showing it |
| Q2 | **Should the board count from `N = 0` instead of from `N₀`?** Arming at zero would pay every member's block from the first four qualifications, including members who qualified years earlier | It is the same cash question as Q1, expressed differently; the plan's answer is the baseline | Baseline at the arm instant (`N₀`) |
| Q3 | **What is the correction path for a wrong award?** D-13 ships none | A void/reversal tool is a money-editing surface with no event to justify it; it needs its own plan | No correction in v1; a wrong award is a forward-only business decision recorded in the phase log |
| Q4 | **Should the plan pay per rank or weight the early positions?** (a Director earning more per level, or node 0 earning a multiple) | The reference has none, and the app now has a rank ladder, so the temptation exists | No. One flat ladder for every node (D-18) |
| Q5 | **Is there a network-level cap or budget?** | The stream has no cap; the perfect-network ceiling is ₱190.2M and the cost per qualified member is ≈ ₱2,150 at scale | No cap. If one is wanted, it is a new decision with a new column, and the reports already publish the exposure |
| Q6 | **Should a member be warned at qualification that their position may never pay?** | At `N = 1,204` only the first 401 positions have any completion; the card says so, but the registration flow does not | The card states it; no registration-time message is built. A future notification needs its own copy review |
| Q7 | **Should the block roster show more than a position for members outside the team?** D-20 says no | It is a privacy decision that could change if the business wants a fully transparent board | Position only; the username stays inside the team |
| Q8 | **Does a future bulk member import need to settle?** No bulk creation exists today | A bulk import adds many positions in one transaction; the inline settlement is capped at one position | The import must call `settleAll()` (or the catch-up) at the end of its batch, and its plan must say so |
| Q9 | **Should the ladder be editable by a non-superadmin?** | Today it follows the settings guard; a plan ladder is a company-wide commitment | Superadmin-only, like every other money setting |
| Q10 | **Should `settled_n` and the backlog be visible to members?** | It is an operational fact about the engine, not about their money | Admin-only. Members see `N` and their own blocks |
| Q11 | **What happens if member deletion ever ships?** D-03 depends on a gapless queue | No deletion exists; a future feature would move every later node's index | A deletion feature needs its own plan and an explicit board migration; this plan forbids it |

---

# Part IV — Appendices

## Appendix A — The board ladder and the default preset

The ten amounts seeded by 21A and shown on the settings card. The thresholds and slot counts are
**structural** and are never configurable (D-18).

| Level | Slots | Apex completes at `N ≥` | Amount | Cumulative | Node `q` completes at `N ≥` |
|---:|---:|---:|---:|---:|---:|
| 1 | 3 | 4 | ₱2,000.00 | ₱2,000.00 | `3q + 4` |
| 2 | 9 | 13 | ₱4,000.00 | ₱6,000.00 | `9q + 13` |
| 3 | 27 | 40 | ₱8,000.00 | ₱14,000.00 | `27q + 40` |
| 4 | 81 | 121 | ₱12,000.00 | ₱26,000.00 | `81q + 121` |
| 5 | 243 | 364 | ₱25,000.00 | ₱51,000.00 | `243q + 364` |
| 6 | 729 | 1,093 | ₱100,000.00 | ₱151,000.00 | `729q + 1,093` |
| 7 | 2,187 | 3,280 | ₱250,000.00 | ₱401,000.00 | `2,187q + 3,280` |
| 8 | 6,561 | 9,841 | ₱480,000.00 | ₱881,000.00 | `6,561q + 9,841` |
| 9 | 19,683 | 29,524 | ₱2,500,000.00 | ₱3,381,000.00 | `19,683q + 29,524` |
| 10 | 59,049 | 88,573 | ₱5,000,000.00 | ₱8,381,000.00 | `59,049q + 88,573` |
| **Total** | **88,572** | | **₱8,381,000.00** | | |

**The preset as stored** (`general_harvest_levels`, seeded by 025 and by `seeds/001_settings.sql`):

```
1 =>  2,000.00      6 =>   100,000.00
2 =>  4,000.00      7 =>   250,000.00
3 =>  8,000.00      8 =>   480,000.00
4 => 12,000.00      9 => 2,500,000.00
5 => 25,000.00     10 => 5,000,000.00
```

**The two settings as stored**

```
general_harvest_enabled    = '0'    (off; the stream must be switched on deliberately)
general_harvest_max_levels = '10'   (every level pays)
```

**The board row as installed**

```
id = 1 · baseline_n = NULL · armed_at = NULL · armed_by = NULL · settled_n = 0    (unarmed)
```

**The full ladder by position.** A node reaches the whole ₱8,381,000 only when every one of its ten
blocks is full, which happens at `N = 88,573 + 59,049·q`:

| Position | `q` | `N` for the full ladder | Full ladder |
|---|---:|---:|---:|
| #1 | 0 | 88,573 | ₱8,381,000.00 |
| #2 | 1 | 147,622 | ₱8,381,000.00 |
| #3 | 2 | 206,671 | ₱8,381,000.00 |
| #4 | 3 | 265,720 | ₱8,381,000.00 |
| #5 | 4 | 324,769 | ₱8,381,000.00 |
| `#p` | `p−1` | `59,049(p−1) + 88,573` | ₱8,381,000.00 |

**Editing rules.** The ten amounts are editable on the settings card **while the board is unarmed**
(21B, D-23); the thresholds are not. A save rewrites all ten rows in one transaction and audits the
before/after. Once the board is armed the amounts are frozen — a save that changes one is refused, and
the only way back is a business reset, which is safe only before anything has been paid. The card shows
the full-ladder total and the position dependence in the same sentence, because ₱5,000,000 at level 10 is
a commitment.

**What the member sees.** The ten levels with their amounts, their block ranges `#from–#to`, `filled of
slots`, and one of five states: *In progress*, *Paid*, *Before go-live*, *Not paying at this depth* or
*No amount configured*. The member never sees a threshold the plan does not use, never sees another
member's amounts, and — beyond their own team — never sees another member's username.

---

## Appendix B — Worked scenarios

Every number below is asserted by a case in §16.3 or reproduced from §12.8.

### B.1 The first four positions

The board pays its first money at `N = 4`:

| `N` | What completed | Node 0 | Node 1 | Node 2 | Node 3 | Board |
|---:|---|---:|---:|---:|---:|---:|
| 4 | node 0 L1 | ₱2,000 | — | — | — | **₱2,000** |
| 7 | node 1 L1 | ₱2,000 | ₱2,000 | — | — | **₱4,000** |
| 10 | node 2 L1 | ₱2,000 | ₱2,000 | ₱2,000 | — | **₱6,000** |
| 13 | node 3 L1, node 0 L2 | ₱6,000 | ₱2,000 | ₱2,000 | ₱2,000 | **₱12,000** |

Node 3's level 1 and node 0's level 2 complete at the same `N` — the nesting of §12.3 R4, visible on
the smallest possible board.

### B.2 The reference's 20-member board

`N = 20`, no sponsorship needed for the arithmetic (reference §4.7):

| Position | `q` | L1 | L2 | L3 | Earned |
|---|---:|---|---|---|---:|
| #1 | 0 | 3/3 ₱2,000 | 9/9 ₱4,000 | 7/27 — | **₱6,000** |
| #2 | 1 | 3/3 ₱2,000 | 7/9 — | — | **₱2,000** |
| #3 | 2 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #4 | 3 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #5 | 4 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #6 | 5 | 3/3 ₱2,000 | 0/9 — | — | **₱2,000** |
| #7 | 6 | 1/3 — | 0/9 — | — | ₱0 |
| **Board** | | 6 nodes | 1 node | 0 | **₱16,000** |

Position #7 needs `N = 22` for its first level — the gap the member card must show (21F Rule 4).

### B.3 Fixture C — the 40-qualified tree

| Node | Position | `N` | Levels | Earned |
|---|---:|---:|---|---:|
| 0 | #1 | 40 | 1–3 | **₱14,000.00** |
| 1–3 | #2–#4 | 40 | 1–2 | **₱6,000.00** each |
| 4–12 | #5–#13 | 40 | 1 | **₱2,000.00** each |
| 13–39 | #14–#40 | 40 | — | ₱0 |
| **Board** | | | 18 awards | **₱50,000.00** |

### B.4 Fixture D — the 121-qualified tree

| Node | Position | `N` | Levels | Earned |
|---|---:|---:|---|---:|
| 0 | #1 | 121 | 1–4 | **₱26,000.00** |
| 1–3 | #2–#4 | 121 | 1–3 | **₱14,000.00** each |
| 4–12 | #5–#13 | 121 | 1–2 | **₱6,000.00** each |
| 13–39 | #14–#40 | 121 | 1 | **₱2,000.00** each |
| 40–120 | #41–#121 | 121 | — | ₱0 |
| **Board** | | | 58 awards | **₱176,000.00** |

### B.5 A partial block

Node 0 at `N = 39`: levels 1–2 paid (₱6,000), level 3 filled `26 of 27` and **unpaid**. The card reads
`26 of 27` and 96.3%; the wallet received nothing for it. At `N = 40` the block pays ₱8,000.00 in one
credit.

### B.6 The baseline

A board armed at `N = 13` and grown to `N = 40`:

| Node | Level | At the baseline | After the growth | Paid |
|---|---:|---|---|---:|
| 0 | 1 | complete | complete | ₱0 — `baseline` row of ₱2,000.00 |
| 0 | 2 | complete | complete | ₱0 — `baseline` row of ₱4,000.00 |
| 0 | 3 | not complete | complete | **₱8,000.00** |
| 1–3 | 2 | not complete | complete | **₱4,000.00** each |
| 4–12 | 1 | not complete | complete | **₱2,000.00** each |

The wallets hold ₱38,000.00 in total, never ₱50,000.00; node 0 holds ₱8,000.00, never ₱14,000.00. Node
0's card marks levels 1–2 *Before go-live*.

### B.7 Stream off, then enabled

`general_harvest_enabled = '0'`: the queue grows from 13 to 40 and **nothing** is written to the ledger
or the wallet; the board's `settled_n` stays 13 and the backlog reads 27. Set it to `'1'` and run the
catch-up: the 13 missing blocks are written and ₱38,000.00 is credited in board order, in batches. A
second run writes nothing.

### B.8 A replay

`settle()` on a board whose `settled_n = N`: `written = 0`, the ledger unchanged, the wallet unchanged,
the return value `replayed`. A duplicate insert of `(seq, level)` raises `PDOException` 23000.

### B.9 `max_levels = 2`

Fixture C completes levels 1–3; only levels 1–2 are written (₱42,000.00, 17 awards). Node 0's level 3 is
*not paying at this depth* on the card. Raising the depth to 3 pays ₱8,000.00 at the next settlement,
once.

### B.10 An unpriced level, then priced

Level 5 hand-edited to `₱0.00` on an armed board: at `N = 364` the settlement writes no level-5 row and
credits nothing for it; the result reports `unpriced = [5]` and the doctor names the level. Restoring the
row to ₱25,000.00 and settling again pays ₱25,000.00 once, because the level has no row. (The settings
card cannot make this edit — D-23 freezes the ladder at the arm — which is why the scenario is a
hand-edit and the doctor reports it.)

### B.11 The ladder is frozen after the arm

Level 3 paid at ₱8,000.00. The operator tries to raise it to ₱10,000.00 on `/admin/settings`: the save is
refused with *"the board is armed — the amounts are frozen"*, and the ladder, the board and the ledger are
unchanged. The only way to change an amount is a business reset, which empties the ledger — acceptable
before anything has been paid, unacceptable after. This is the rule that keeps
`Σ credited = boardTotal(settled_n) − boardTotal(baseline_n)` exact (D-21, D-23).

### B.12 The board across team lines

The reference's seven-member tree, qualification order `3, 1, 2, 4, 5, 6, 7`:

- position #1 = member 3, #2 = member 1, #3 = member 2, #4 = member 4, #5 = member 5, #6 = member 6,
  #7 = member 7;
- node 2 (member 2, position #3) has level 1 = positions #4–#6 = members 4, 5 and **6**;
- members 4 and 5 are member 2's directs; **member 6 is not in member 2's subtree** (it is member 3's
  direct);
- at `N = 10` node 2's level 1 completes and member 2 is paid **₱2,000.00** — a block two-thirds filled
  by their own directs and one-third by a stranger. That is the shared board, not a bug (reference
  §4.1, §11.1 #1).

### B.13 The perfect-network economics

A perfect network in which every qualified member holds three directs and every leaf holds three
non-qualifying directs has `265,720` accounts for `88,573` qualified members. At the reference ₱5,000
registration price it raises **₱1,328,600,000** and the board pays **₱190,208,000.00** — **14.3%** of the
tree's fees, or **43.0%** of the ₱442,865,000 paid by the 88,573 qualified members themselves. The
average is ₱2,147.47 per qualified member, and 59,049 of the 88,573 earn **nothing**. The ₱8,381,000
ceiling is a maximum, never a forecast.

---

## Appendix C — Copy deck

### C.1 Settings card (`/admin/settings` → Commissions → *General Harvest bonus*)

> **General Harvest bonus**
> One board for the whole network. Every qualified member holds a node — their position in the Harvest
> queue minus one. Node `q`'s level 1 is the 3 positions after it, level 2 the next 9, level 3 the next
> 27, and so on; a level pays its amount once, when its block is full. This bonus never depends on
> purchases, rank or the month — only on how far the queue has grown.
>
> [ ] **Enable the General Harvest bonus** — off means no award is written. The queue is still recorded,
> so nothing is lost while it is off.
> **Levels that pay** [10]
>
> | Level | Slots | Apex needs `N` | Amount (₱) | Cumulative |
> |---:|---:|---:|---:|---:|
> | 1 | 3 | 4 | 2,000.00 | 2,000.00 |
> | … | | | | |
> | 10 | 59,049 | 88,573 | 5,000,000.00 | 8,381,000.00 |
>
> **The full ladder pays ₱8,381,000.00 to node #1** when the queue reaches 88,573 qualified members;
> node #2 needs 147,622, node #3 206,671. The amounts are frozen once the board is armed; before that,
> a change affects only levels that have not been written.
> **State:** *Not armed — no baseline has been recorded. Run `bin/general_harvest_arm.php` before
> enabling; nothing can pay until then.* · *Armed 2026-11-20 09:14 — 1,204 positions, switch off.* ·
> *Armed, switch on, 27 positions waiting to be settled (₱19,000.00).*

### C.2 Admin ledger (`/admin/commissions?ledger=general_harvest`)

> **General Harvest bonus**
> One row per block a node completed. Rows marked **Before go-live** were already full when the board
> was armed: they are recorded, not paid.
>
> Tiles: *Paid* · *This month* · *Awards* · *Node holders* · *Before go-live* · *Open liability* · *Unsettled positions*
>
> Filters: member · level 1–10 · status (Paid / Before go-live) · from · to · search
>
> Columns: Date · Member · Position · Node · Level · Block · Queue (N) · Threshold · Amount · Status · Wallet

### C.3 Member earnings (`/earnings?ledger=general_harvest`)

> **General Harvest bonus** — position **#217** (node 216) · the Harvest queue is at **1,204** qualified members
> **Level 1** — block **#652–#654** · **3 of 3** · **paid ₱2,000.00**
> **Level 2** — block **#655–#663** · **9 of 9** · **paid ₱4,000.00**
> **Level 3** — block **#664–#690** · **5 of 27** · **₱8,000.00** when the block is full — needs the queue to reach **1,228**
> **Level 4** — block **#691–#771** · **0 of 81** · **₱12,000.00** — needs **2,053**
>
> *A slot in your block is filled by a qualified member of the whole network — not necessarily anyone in
> your team. Your block fills in the order members qualified.*
> *This bonus does not depend on any purchase, on your rank, or on the monthly Active status.*
>
> **Your blocks** — level, positions, filled, amount, status, queue needed.
>
> **The members in your current block** — position, member, relation.
>
> **Your levels** — date, level, queue at award, amount, status.

### C.4 Dashboard tile (`/dashboard`)

> **General Harvest** — ₱6,000.00 earned · position #217 · level 3: 5 of 27 · ₱8,000.00 when full
> [View your board]

### C.5 Admin CSV columns (`general-harvest-ledger_YYYY-MM-DD.csv`)

`Award ID, Credited at, Member, Position, Node (seq), Level, Block, Queue (N), Threshold, Amount, Status, Wallet reference`

### C.6 Member CSV columns (`general-harvest-statement_YYYY-MM-DD.csv`)

`Date, Level, Block, Queue at award, Threshold, Amount, Status`

### C.7 Status labels (stable strings)

| Stored / computed | Admin label | Member label |
|---|---|---|
| `credited` | Paid | Paid |
| `baseline` | Before go-live | Before go-live |
| complete, above `max_levels` | Not paying at this depth | Not paying at this depth |
| complete, amount 0 | Unpriced — no amount configured | No amount configured |
| not complete | In progress | In progress |
| block empty | Waiting for the queue | Waiting for the queue |

### C.8 Empty states

| Surface | When | Copy |
|---|---|---|
| Settings strip | unarmed | *Not armed. Run `bin/general_harvest_arm.php` — nothing can pay until the board has a baseline.* |
| Settings strip | armed, off | *Armed and switched off. Positions are still being recorded.* |
| Admin board card | armed, no awards | *No block has completed yet. The first block completes when the queue reaches 4 qualified members.* |
| Admin board card | backlog | *The queue is at N = 1,231; the board is settled to 1,204. Run `bin/general_harvest_catchup.php` to settle 27 positions (₱19,000.00).* |
| Admin ledger | no awards | *No block has completed yet. A block's first level needs the queue to reach 4.* |
| Member tab | not qualified | *You are not qualified yet: **2 of 3** direct members. When your third direct member registers, you get a node and your blocks start filling with the members who qualify after you.* |
| Member tab | qualified, nothing complete | *You are position #217. Your first block is #652–#654 and needs the queue to reach 652; the queue is at 1,204 — **level 1 is paid**. Level 2 needs 1,228.* |
| Member tab | qualified, no level reachable | *No level can complete at the current queue size. Level 1 needs the queue to reach 3,613; the queue is at 1,204.* |
| Member tab | stream off | *The General Harvest bonus is not currently paying. Your position and blocks are recorded and will count when it is switched on.* |

### C.9 Member FAQ (rendered as a short block on the earnings tab)

> **How do I get a node?** Three members register with you as their sponsor. That is all — you take the
> next position on the board.
> **Whose members fill my block?** The whole network's. A slot is filled by whoever took that queue
> position, whether or not they are in your team.
> **Does buying anything help?** No. Nothing in this bonus depends on purchases, rank or the monthly
> Active status.
> **When am I paid?** The moment a block's last position is filled — the money is in your e-wallet
> immediately.
> **Why is my block only 5 of 27?** The queue has not reached the block's last position yet. The card
> shows the exact number it needs.
> **Why did my level not pay?** Either the block is not full (see `filled of slots`), the amount for that
> level is zero, or the block was already full before the plan started paying (marked **Before
> go-live**).
> **Can I see who is in my block?** Their positions, yes. Their usernames only if they are in your own
> team; everybody else is shown as "a member outside your team".

---

## Appendix D — SQL quick reference

```sql
-- The board's state
SELECT id, baseline_n, armed_at, armed_by, settled_n FROM general_harvest_board;

-- The queue as the board sees it: position, node, and whether the node is still "ahead"
SELECT q.id AS position, q.id - 1 AS node, u.username, q.qualified_at, q.teammates
FROM harvest_queue q JOIN users u ON u.id = q.user_id
ORDER BY q.id;

-- Gapless check (must return 1 row with n = max_id)
SELECT COUNT(*) AS n, MAX(id) AS max_id FROM harvest_queue;

-- The frontier per level: rows written, the prefix check, and the closed form's expectation
SELECT a.level, COUNT(*) AS rows_written, MIN(a.seq) AS min_seq, MAX(a.seq) AS max_seq,
       COUNT(DISTINCT a.seq) AS distinct_seq,
       SUM(a.status = 'baseline') AS baseline_rows,
       SUM(a.status = 'credited') AS credited_rows,
       SUM(a.amount) AS amount
FROM general_harvest_awards a
GROUP BY a.level ORDER BY a.level;

-- One node's awards
SELECT level, seq, threshold_n, n_at_award, amount, status, ledger_id, credited_at
FROM general_harvest_awards WHERE seq = :node ORDER BY level;

-- One member's node and awards
SELECT q.id - 1 AS node, a.level, a.threshold_n, a.n_at_award, a.amount, a.status, a.ledger_id
FROM harvest_queue q
LEFT JOIN general_harvest_awards a ON a.seq = q.id - 1 AND a.user_id = q.user_id
WHERE q.user_id = :id ORDER BY a.level;

-- Money by status
SELECT status, COUNT(*) AS rows_count, SUM(amount) AS total
FROM general_harvest_awards GROUP BY status;

-- Money by level
SELECT level, status, COUNT(*) AS rows_count, SUM(amount) AS total
FROM general_harvest_awards GROUP BY level, status ORDER BY level, status;

-- The wallet side of one award
SELECT l.* FROM ewallet_ledger l
WHERE l.ref_type = 'general_harvest' AND l.ref_id = :awardId;

-- Orphan check: a credited award with no wallet row
SELECT a.id, a.seq, a.level, a.amount
FROM general_harvest_awards a
LEFT JOIN ewallet_ledger l
  ON l.ref_type = 'general_harvest' AND l.ref_id = a.id AND l.type = 'credit'
WHERE a.status = 'credited' AND l.id IS NULL;

-- Orphan check: a wallet row with no award
SELECT l.id, l.user_id, l.amount, l.ref_id
FROM ewallet_ledger l
LEFT JOIN general_harvest_awards a ON a.id = l.ref_id
WHERE l.ref_type = 'general_harvest' AND a.id IS NULL;

-- Orphan check: an award whose node is gone or belongs to somebody else
SELECT a.id, a.seq, a.user_id
FROM general_harvest_awards a
LEFT JOIN harvest_queue q ON q.id = a.seq + 1
WHERE q.id IS NULL OR q.user_id <> a.user_id;

-- The closed form, level by level, against the ledger (I-07)
--   expected rows at level L = FLOOR((settled_n - (3^L - 1)/2) / 3^L)  for L <= max_levels
--   expected credited amount = rows x the level's amount

-- The ladder and the settings
SELECT level, amount FROM general_harvest_levels ORDER BY level;
SELECT key_name, value FROM settings WHERE key_name LIKE 'general_harvest%' ORDER BY key_name;

-- What the board would pay if it were settled now (the open liability)
--   open = boardTotal(N) - boardTotal(settled_n)     (computed by GeneralHarvestService)

-- Positions that can earn anything at the current queue size
SELECT COUNT(*) AS positions_with_level1
FROM harvest_queue q
WHERE q.id - 1 <= FLOOR(((:n) - 1) / 3);
```

---

## Appendix E — Glossary

| Term | Meaning in this document |
|---|---|
| **award** | One `general_harvest_awards` row: one node, one level, one amount, `credited` or `baseline` |
| **backlog** | `N − settled_n`: positions the queue has gained that no settlement has processed |
| **baseline** | The queue length at arm (`baseline_n`); and per row, a block already full at that instant, recorded and never paid |
| **baseline row** | An award row with `status = 'baseline'` and no `ledger_id` |
| **block** | The `3^L` consecutive queue positions that fill a node's level `L` |
| **board** | The shared 3-ary board of §12.2; the whole stream |
| **board total** | `boardTotal(N)` — the sum of every completed block's amount at queue size `N` |
| **complete** | A block whose last position the queue has reached: `N ≥ 3^L·q + (3^(L+1) − 1)/2` |
| **coverage** | The share of nodes with at least one completed level; ≈ 1/3 at any `N` |
| **creditable / payable** | Complete, above the baseline, at or below `max_levels`, and priced |
| **frontier** | Per level, the number of rows written; the paid set is `0 … count−1` |
| **gapless** | `COUNT(*) = MAX(id)` on `harvest_queue`; the board's arithmetic requires it |
| **go-live** | The sequence deploy → configure → arm → enable → catch up → reconcile (§14.14) |
| **max depth** | `general_harvest_max_levels`: the deepest level that pays |
| **node** | One queued member's place on the board; node `q` = position `q + 1` |
| **N** | The number of qualified members (the queue length); the board's only variable |
| **N₀** | `baseline_n`: `N` at the arm instant |
| **open liability** | What a settlement now would pay: `boardTotal(N) − boardTotal(settled_n)` |
| **preset** | The reference ladder of Appendix A |
| **seq** | `position − 1`, the 0-based node index |
| **settle** | The act of writing the rows for the positions between `settled_n` and `N` |
| **settled_n** | The queue length the board has settled up to |
| **slot** | One position inside a block; `3^L` per level |
| **staircase** | The apex thresholds 4, 13, 40 … 88,573 |
| **stream** | The General Harvest bonus as a whole |
| **unpriced** | A payable level whose configured amount is zero. The card refuses it and the arm requires all ten levels priced, so it is only reachable through a hand-edited ladder row (D-17, D-23): it writes no row and pays nothing |

---

## Appendix F — Formula card

```
POSITIONS AND NODES
  position(i)       = harvest_queue.id                            1-based, fixed for ever
  seq(i)            = position(i) − 1                             0-based board index
  node(q)           = the queue row with id = q + 1
  N                 = COUNT(*) = MAX(id)                          gapless (D-03)

BOARD
  slots(L)          = 3^L                                          3, 9, 27 … 59,049
  children(q)       = 3q + 1, 3q + 2, 3q + 3
  parent(q)         = floor((q − 1) / 3)
  start(q,L)        = 3^L·q + (3^L − 1)/2                          0-based first slot
  block(q,L)        = positions [start+1 … start+3^L]
  filled(q,L)       = clamp(N − start(q,L), 0, 3^L)
  complete(q,L)     ⇔ N ≥ 3^L·q + (3^(L+1) − 1)/2 = threshold(q,L)
  earnedBy(q,N)     = Σ_{L : complete} amount(L)
  paid(L,N)         = max(0, floor((N − (3^L − 1)/2) / 3^L))        nodes 0 … paid−1
  qFor(L,N)         = paid(L,N) − 1
  boardTotal(N)     = Σ_{L=1..10} amount(L) · paid(L,N)
  awardCount(N)     = Σ_{L=1..10} paid(L,N)

LADDER (reference preset)
  amount(L)         = 2,000 / 4,000 / 8,000 / 12,000 / 25,000 /
                      100,000 / 250,000 / 480,000 / 2,500,000 / 5,000,000
  full ladder       = ₱8,381,000 per node, at N = 88,573 + 59,049·q

ARM (once, before the switch)
  N₀                = N at that instant
  baseline row(q,L) ⇔ q ≤ qFor(L, N₀)                              all ten levels
  excluded          = boardTotal(N₀)
  board             = (baseline_n = N₀, armed_at = now, settled_n = N₀)

SETTLE (after every queue append, one position inline; batches by the job)
  if not enabled  → 'off'          if not armed → 'unarmed'
  target            = min(N, settled_n + maxNewPositions)
  for L = 1 … 10:
      Q             = qFor(L, target)
      have          = COUNT(rows at level L)                     the prefix 0 … have−1
      for q = have … Q:
          if q ≤ qFor(L, baseline_n)   → 'baseline' (no money)
          elif L > max_levels          → skip
          elif amount(L) = 0           → skip (unpriced)
          else                         → 'credited' + wallet credit
  board.settled_n   = target

MONEY
  award.amount      = the configured amount at that instant (snapshot)
  credit            = withdrawable wallet, ref_type 'general_harvest', ref_id = award.id
  once ever         = UNIQUE (seq, level)

IDENTITIES (all ten levels priced)
  Σ credited        = boardTotal(settled_n) − boardTotal(baseline_n)
  Σ baseline        = boardTotal(baseline_n)
  Σ all rows        = boardTotal(settled_n)
  open              = boardTotal(N) − boardTotal(settled_n)
```

---

## Appendix G — Traceability to the reference document

### G.1 `docs/comp-plan_addons.md` §4 → this plan

| Reference | Rule | This plan |
|---|---|---|
| §4.1 | One shared 3-ary 10-level board over the Harvest queue; node index = queue index; children `3q+1…`; level-`L` block = `3^L` positions from `3^L·q + (3^L−1)/2`; slots are positions, not descendants; pays across team lines | §12.2, D-01, D-20 |
| §4.2 | `filled = clamp(N − s, 0, t)`; paid only when `filled = t`; the threshold is `N ≥ s + t`; a level pays all-or-nothing and once | §12.3, §12.5 R3, P21B-T14, P21D-T1 |
| §4.3 | The ladder ₱2,000 … ₱5,000,000; total ₱8,381,000 | §12.4, Appendix A |
| §4.4 | The apex's queue-size thresholds 4, 13, 40 … 88,573 and the cumulative earnings | §12.3, §12.4, P21B-T11 |
| §4.5 | `N_min(q,L) = 3^L·q + (3^(L+1) − 1)/2`; positions #1–#4; #4 is the apex shifted one level | §12.3 (the table and R4), P21B-T12 |
| §4.6 | `GeneralBoardTotal(N) = Σ BONUS_G[L]·max(0, floor((N − (3^L−1)/2)/3^L))`; the verified totals | §12.3, §12.7, P21B-T15/T16 |
| §4.7 | The 20-member example (6 level-1 payments and 1 level-2 payment = ₱16,000) | §12.8 B, §16.3, Appendix B.2 |
| §4.8 | No purchase, sales, Active flag, rank, monthly cycle or sponsor input | §12.10 |
| §4.9 | The board table, the "by position" table with Direct/Indirect/Outside your team, the apex panel | §12.2 R4, 21E Rules 9–11, 21F Rules 6–8 |
| §2.5 | General Harvest is "derived" and never ledgered | Replaced by a ledger (D-04) and a wallet credit; the derived side is kept for display (D-21) |
| §3 | Qualification: ≥ 3 directs, checked at registration, append-only queue | Phase 20; consumed here (D-02) |
| §3.1 | The queue is not the sponsor tree | §12.2 R4, D-01 |
| §9.1 | `BONUS_G` | Appendix A |
| §9.2 | The per-member ceiling ₱8,381,000 | §12.4 R2, Appendix A (the position table) |
| §11.1 #1 | General Harvest pays across team lines — a design property, not a bug | §12.2 R4, 21F Rule 2, Appendix B.12 |
| §11.1 #2 | Harvest credits are derived, never ledgered | D-04 (ledger added), D-21 (closed form kept) |
| §11.1 #6 | All-or-nothing levels | §12.3 R3, §12.5 R3 |
| §11.1 #7 | The deepest-level tile is optimistic | Not shipped: the card shows completed levels, not "deepest filled" |
| Appendix B | The formula card | Appendix F |

### G.2 What this plan reuses from the existing implementation

| Asset | Where it came from | How it is reused |
|---|---|---|
| The append-only queue, its hook, its seed and its position rule | Phase 20 — `HarvestQueue`, `RegistrationService` | read-only: `id`, `COUNT(*)`, `ownerOf()` |
| The wallet credit + ref type + idempotency anchor | [`WalletService`](../system/app/Services/WalletService.php) | one `credit()` per award, `ref_type = 'general_harvest'` |
| The ledger shape (statuses, snapshots, CSV, filters) | `TeamHarvestAward` / `RoyaltyAward` / `UnilevelAward` | `GeneralHarvestAward` |
| The settings card mechanics (fields, toggles, validation, audit) | `AdminSettingsController` (the Royalty and Team Harvest cards) | the General Harvest sub-block |
| The tab mechanics (query parameter, fragments, ETag, live region) | `AdminCommissionController` / `EarningsController` (four tabs) | the fifth tab on each |
| The job mechanics (flags, lock, log, exit codes, `--doctor`) | [`bin/royalty_month_close.php`](../system/bin/royalty_month_close.php), `bin/team_harvest_catchup.php` | `bin/general_harvest_catchup.php` (without the scheduling) |
| The forest pass (load once, children map, iterative walk, cycle guard) | [`RoyaltyRank::map()`](../system/app/Models/RoyaltyRank.php#L280-L447) | the block roster's relation labels (21F) |
| The reset and integrity-pack wiring | [`DatabaseResetService`](../system/app/Services/DatabaseResetService.php) | the ledger, the board row and the reconcile hook |

### G.3 Older documents deliberately not followed

| Document | Proposal | Why it is not this build |
|---|---|---|
| [`dmp/workflow.md`](workflow.md) | A "QA / retail-customer" gate before earning, "balanced" leg requirements | Not in the reference specification for this stream; they belong to a different plan generation and are not the source of truth |
| [`dmp/ecowealth2.md`](ecowealth2.md), [`dmp/ecowealth3.md`](ecowealth3.md) | An extra Chairman rank, matrix "spillover" language, a "binary" board | Rank is Royalty's concern (Phase 19); spillover and binary placement are explicitly not this plan (D-18, §12.10) |
| [`README.md`](../README.md) | "Final compensation terms must come from verified official material" | Still true: this plan implements the *prototype's* mechanics, and the amounts remain the operator's configuration |
| Phase 20's Team Harvest plan | A per-member matrix and a per-member `baseline_n` | General Harvest is a global board with a global baseline; the queue is shared, the money is not (D-22, §3.5) |

---

## Appendix H — Phase gate record

Fill one row per gate and copy the block into `docs/phase-log.md` with the phase's entry. A phase
without a filled row is not closed, whatever its code says.

| Phase | Tag | Date | Suite passed/ran | Smoke passed/ran | Migration/bundle | Manual QA `QA21X-nn` | Tester | Deviations | Signed |
|---|---|---|---|---:|---:|---|---|---|---|
| 21A Foundation | `p21a-foundation` | 2026-11-16 | **1,336 / 1,336** | **263 / 263** | migration `025` applied twice, idempotent; fresh = migrated; bundle `--check` exit 0 | A: **8/8** | Automated QA pass — DSH | 1 (`install/header.sql` also updated; see the phase log) | ☑ |
| 21B Configuration | `p21b-configuration` | 2026-11-16 | **1,371 / 1,371** | **268 / 268** | no schema change; bundle `--check` exit 0 (byte-identical) | B: **9/9** | Automated QA pass — DSH | 15 (the `index.php` include, QA21B-02's arithmetic, the manifest guards, the two Royalty payloads, the smoke checks and their ASCII currency patterns, the explicit queue ids, the case-count correction, the absent-field fix and the shared release documents; see the phase log) | ☑ |
| 21C Baseline | `p21c-baseline` | 2026-11-16 | **1,392 / 1,392** | 268 / 268 (not re-run — the phase adds no page) | no schema change; the arm dry run and the real run both exit 0; `wallet_reconcile` exit 0 | C: **8/8** (automated scratch-copy pass) | Automated scratch-copy pass | 8 (the bulk baseline writer, the service's own setting read, no cron wrapper, the reset's seed marker, the 21A/21B manifest guards, the two Phase 20 manifest guards, the refusal JSON shape and the explicit queue ids; see the phase log) | ☑ |
| 21D Money engine | `p21d-money` | — | — / — | — / — | no schema change; `wallet_reconcile` exit 0 with the General Harvest section; catch-up doctor exit 0 | D: —/10 | — | — | ☐ |
| 21E Admin visibility | `p21e-admin` | — | — / — | — / — | no schema change; bundle `--check` exit 0 | E: —/8 | — | — | ☐ |
| 21F Member visibility | `p21f-member` | — | — / — | — / — | no schema change; `wallet_reconcile` exit 0 (moves no money) | F: —/9 | — | — | ☐ |
| 21G Release | `p21g-release` | — | — / — | — / — | no schema change; bundle `--check` exit 0; `wallet_reconcile` exit 0; doctor exit 0; **`APP_PHASE = 21`** | G: —/6 | — | — | ☐ |

**Deviation log.** Any difference between this plan and what was built, with the reason and the phase
that owns it. An empty deviation log at the 21G gate means the plan and the build agreed.

| Phase | Deviation | Reason | Approved by |
|---|---|---|---|
| 21A | `database/install/header.sql` is updated although the §5 manifest names `footer.sql` only | The header prints the same fresh-install counts (39 tables · 79 settings · the two ladders · the unarmed board row) and `RoyaltyFoundationTest`, `TeamHarvestFoundationTest` and `P21A-T5` assert them; a bundle whose first page contradicts its footer is a worse defect than one extra documentation line. The 20A gate made the same call | the 21A gate (recorded in `docs/phase-log.md`) |
| 21B | `admin/settings/index.php` is updated although the §6 manifest names `_general_harvest.php` and `_commissions.php` only | `index.php` passes every card's variables to its partial explicitly; without the seven `general_harvest_*` keys the sub-block renders empty. One include and one header line, no behaviour outside the card | the 21B gate (recorded in `docs/phase-log.md`) |
| 21B | `QA21B-02`'s illustrative total is **₱7,381,500.00**, not the plan's ₱8,882,500.00 | The manual step is arithmetically wrong: ₱8,381,000.00 − ₱2,000.00 + ₱2,500.00 − ₱5,000,000.00 + ₱4,000,000.00 = ₱7,381,500.00. The card, `P21B-T2` and the QA pack state the correct number; the plan's own case and the model agree with it | the 21B gate |
| 21B | `P21A-T15` and `P21A-T16` are rewritten to describe the 21A/21B boundary | They are the 21A manifest guards and 21B legitimately extends the manifest and adds the card; they still prove that no award model, service, route or job exists. Same churn as 20B's manifest in Phase 20 | the 21B gate |
| 21B | `P20A-T15`/`P20A-T16`, `P19B-T11` and `P19B-T25` are updated | The Phase 20 manifest guards must admit `GeneralHarvestBoard` (its whole arithmetic starts from `harvest_queue`), and the two Royalty payloads must post the ten new amounts or a Royalty edit clears — and audits — the General Harvest ladder. Both found by the full-suite gate, not by 21B's own group | the 21B gate |
| 21B | Five smoke checks are added although the phase adds no route | D-15 holds — `/admin/settings` already existed — but the card, its two generated tables, its two refusal rules and its inertness are exactly what a smoke walk covers; the checks only read the page or send a refused save | the 21B gate |
| 21C | `GeneralHarvestAward` ships `writeBaselineLevel()` beside `writeBaseline()` | §14.3 requires one multi-row `INSERT` per level (at most ten statements) while §14.2 lists only the single-row writer; the bulk method is the arm's and the unit method stays public for the later phases and the tests | the 21C phase log |
| 21C | `GeneralHarvestService` reads the switch and the seed marker through its own PDO | A service handed a connection must read that database's settings, not the request's global cache; `GeneralHarvestLevel::enabled()` stays the view's read and both normalise identically | the 21C phase log |
| 21C | The arm job takes no `CronLock`/`CronLog` | §14.10 specifies `GET_LOCK` and one transaction, not the cron wrappers; the job follows `harvest_queue_seed.php`, the other one-time go-live job, and a second run refuses (`armed`) rather than reporting "busy" | the 21C phase log |
| 21C | `P21A-T15` and `P21B-T25` are rewritten to describe the 21C boundary | They are the 21A/21B manifest guards; 21C extends the manifest and ships the job, and the guards still prove that the catch-up and every page above the card do not exist | the 21C phase log |
| 21C | A business reset returns `team_harvest_ledger_since` to `''` | The reset restores the shipped settings seed, so after a reset the arm refuses `unseeded` until Phase 20's queue is seeded again on the new network — the correct go-live order, and what `P21C-T17` proves (the plan's case only says the second arm uses the new `N`) | the 21C phase log |
| 21C | `--json` refusals use the shared `{ok:false, error, reason}` document | The six documented keys (`armed`, `n`, `rows`, `excluded`, `per_level`, `dry_run`) are the summary; a refusal is not a summary, and every other job in this build reports its refusals the same way. `P21C-T20` asserts the six-key document exactly on the success path | the 21C phase log |
| 21C | `P20A-T15` and `P20A-T16` now admit the 21C files | They are Phase 20's manifest guards; 21C's service reads the queue for the baseline and its exception names `team_harvest_ledger_since`, so both lists grow by one or two entries. Found by the full-suite gate, not by 21C's own group | the 21C phase log |
| 21C | The baseline test fixture writes the queue with explicit ids | A rolled-back `INSERT` still advances `AUTO_INCREMENT`, and the board is defined over `1 … N` with `COUNT(*) = MAX(id)` as its gapless check; the fixture writes the ids the case means, inside the case's transaction, exactly as 20C's and 21B's fixtures do | the 21C phase log |

**Acceptance sign-off.**

| Item | Value |
|---|---|
| Reference settings used | depth 10 · the Appendix A ladder for all unit cases; the same ladder for the QA evidence |
| Fixtures | A (13 accounts / `N = 4`), B (40 / 13), C (121 / 40), D (364 / 121), E (1,093 / 364), F (the baseline tree), G (the 20-member reference tree), H (edge family) |
| First money observed | **₱2,000.00** at `N = 4` (node 0, level 1) |
| B.3 / B.4 / B.13 observed | **₱50,000.00** / **₱176,000.00** / **₱190,208,000.00** |
| Level-10 threshold | **₱8,381,000.00** at `N = 88,573` (`earnedBy`) |
| Baseline | recorded, never credited; the wallets hold only the post-baseline blocks (₱38,000.00 in fixture F) |
| `wallet_reconcile.php` | exit **0**, General Harvest section clean |
| `general_harvest_catchup.php --doctor` | exit **0**: clock, tables, settings, armed, gapless, queue, settled, backlog, frontier, paid, baseline, open, drift, reconcile |
| Release gate (21G) | suite **1,484 / 1,484**, smoke **≈ 278 / 278**, bundle `--check` exit 0, `APP_PHASE = 21` |
| Tester / date | — |
| Evidence folder | `docs/qa/evidence/phase-21/` (sections A–G) |

---

*End of plan.*

