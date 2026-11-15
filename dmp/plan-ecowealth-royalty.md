# Plan — Royalty Bonus (rank ladder + monthly group-sales override)

**Phase 19** of the EcoWealth member system · **Status:** planned — no code yet
**Delivery model:** **seven gated phases, 19A → 19G.** Each phase is self-contained, shippable and
testable on its own; each builds on the one before it.
**Date:** 15 November 2026 · **Target:** `http://localhost/ecowealth/system/`
**Branch:** `feature/royalty`, cut from `feature/unilevel` at `d8e626a` (“admin settings cancel order
toggle”, the Phase 18 follow-up) · **Tag at start:** `p19-start`

| | |
|---|---|
| **Reference specification** | [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §6 (Royalty Bonus), with §2.4 (the month clock), §2.5 (derived vs ledger money), §6.3 (rank derivation), §6.4 (minimum trees), §6.5 (GS/PS and the payout formula), §6.7 (worked examples), §9.1 (`RANK_QUOTA`, `GROUP_PCT`, `PERSONAL_PCT`), §9.3 (minimum structure), §11.2 (Royalty edge cases) and Appendix B (formula card) |
| **Related plans** | [`dmp/plan-ecowealth-unilevel-repeat-purchase.md`](plan-ecowealth-unilevel-repeat-purchase.md) (the team-chain sibling this plan deliberately mirrors), [`dmp/plan-ecowealth-unilevel-bonus-escrow.md`](plan-ecowealth-unilevel-bonus-escrow.md) (the award lifecycle vocabulary), [`dmp/plan-ecowealth-install-reset.md`](plan-ecowealth-install-reset.md) (install/reset parity), [`dmp/plan-ecowealth-cart-system.md`](plan-ecowealth-cart-system.md) (the 15 phases this one extends) |
| **Baseline at `p19-start`** | **1,008 / 1,008** PHP cases · **229 / 229** smoke checks · `build_install_sql --check` exit 0 · `wallet_reconcile.php` exit 0 · **31 tables · 57 settings** · migrations **001–022** · `APP_PHASE` **17** |
| **Ships** | 2 new tables, 1 extended ENUM, 16 settings, a rank engine, a monthly award ledger, a resumable close job, admin and member visibility, reports and reconciliation |
| **Does not ship** | no new routes, no per-order royalty, no rank history table, no retroactive payout, no clawback after a closed month, no QA/retail gate, no Chairman rank |

**Revision note.** This is the first plan for the Royalty Bonus. It does **not** replace
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md); it implements §6 of it in the PHP
application and records every place the app must adapt the simulator’s mechanics to real orders,
real accounts and a real month close. Older marketing documents
([`dmp/workflow.md`](workflow.md), [`dmp/ecowealth2.md`](ecowealth2.md), [`dmp/ecowealth3.md`](ecowealth3.md))
propose a “QA / retail-customer” gate, “balanced” requirements and an extra Chairman rank. They are
**not** the source of truth for this build; §12.11 lists the deliberate divergences.

---

## Contents

**Part I — Orientation**

1. [Executive summary](#1-executive-summary)
2. [The phase protocol](#2-the-phase-protocol)
3. [Current state and the gap](#3-current-state-and-the-gap)
4. [Decisions](#4-decisions)

**Part II — The phases** (each one is a complete work package)

5. [Phase 19A — Foundation](#5-phase-19a--foundation)
6. [Phase 19B — Configuration and the rank ladder](#6-phase-19b--configuration-and-the-rank-ladder)
7. [Phase 19C — Money engine](#7-phase-19c--money-engine)
8. [Phase 19D — Admin visibility](#8-phase-19d--admin-visibility)
9. [Phase 19E — Member visibility](#9-phase-19e--member-visibility)
10. [Phase 19F — Operations](#10-phase-19f--operations)
11. [Phase 19G — Release](#11-phase-19g--release)

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

A **rank ladder plus a monthly override**, exactly the mechanics of
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §6, implemented against the application’s
real sponsor tree and real paid orders:

- **Five ranks, derived from the tree, never stored.** Member (automatic), **Leader** (10 directs),
  **Supervisor** (Leader + 10 downline Leaders), **Manager** (Supervisor + 10 downline Supervisors),
  **Director** (Manager + 10 downline Managers). The ranks are self-prerequisite, so a Director
  also satisfies Leader, Supervisor and Manager. The thresholds are configurable and default to the
  reference’s 10/10/10/10.
- **Leader is not a paying rank.** Royalty is paid to **Supervisor, Manager and Director** only.
- **Group sales (GS)** = the member’s own paid purchases in the month **plus every member below
  them, at any depth**. **Personal sales (PS)** = the member’s own paid purchases. The member’s own
  purchases are inside GS and are paid a second time through PS — deliberately, as the reference
  does.
- **A monthly quota gate.** Supervisor ₱500,000, Manager ₱1,000,000, Director ₱5,000,000 of GS.
  Missing the quota by one centavo pays **nothing**, including the personal percentage.
- **The payout** is one rounding of `group% × GS + personal% × PS`:
  Supervisor 3% / 5%, Manager 5% / 10%, Director 10% / 15%.
- **Paid once per closed calendar month**, to the member’s **withdrawable e-wallet**, with a ledger
  row (`ref_type = 'royalty'`) and an immutable award row. There is **no per-order royalty**.
- **Two switches.** `royalty_enabled` (earn) is **off** by default; `royalty_pay_enabled` (pay)
  defaults on. A deployment never starts paying by accident, and an operator can pause payment
  without losing evaluated awards.
- **A resumable close job.** Evaluate a month (write awards, no wallet money), then pay in batches
  (wallet credits), resuming safely after a crash. A closed month is immutable and is never
  recalculated.
- **Visibility on existing pages only** — no new routes:
  `/admin/settings` (the ladder card), `/admin/members` (rank column), `/admin/commissions?ledger=royalty`
  (the ledger and period cards), `/admin/reports` (royalty cards and CSV), `/admin/members/{id}`
  (a member’s royalty panel), `/earnings?ledger=royalty` (the member’s statement), `/dashboard`
  (a royalty tile).
- **Reconciliation, reset parity, audit and operational tooling**, exactly like the Phase 17 repeat-
  purchase stream: `wallet_reconcile.php` gains a Royalty section, a reset verifies zero rows, every
  settings change and period action is audited, and `bin/royalty_month_close.php` has `--dry-run`,
  `--evaluate-only`, `--pay-only`, `--json` and `--doctor`.

### 1.2 The rules in one page

```
RANK  (derived live from users.sponsor_id; role='member' only; any depth)
  Leader      ⇔ directs ≥ leader_directs                         (default 10)
  Supervisor  ⇔ Leader      ∧ #downline Leader-or-above ≥ 10
  Manager     ⇔ Supervisor  ∧ #downline Supervisor-or-above ≥ 10
  Director    ⇔ Manager     ∧ #downline Manager-or-above ≥ 10
  rank        = Director ? 4 : Manager ? 3 : Supervisor ? 2 : Leader ? 1 : 0

MONTH  P = a calendar month in Asia/Manila, 'YYYY-MM'
  PS(m,P)     = Σ shop_order_items.line_total over m's orders with paid_at ∈ P
                 and status ≠ 'cancelled'          (no shipping, no activations, no top-ups)
  GS(m,P)     = PS(m,P) + Σ PS(d,P) over every descendant d of m, any depth

CLOSE  (once per completed month, after the month ends; never the current month)
  rank(m) is evaluated as of the period end (users.created_at < end), so a late run
  produces the same ranks as an on-time run
  ┌ switch off ............................. period 'skipped', no awards, no money
  ├ rank < Supervisor ..................... no award row (Leader is not paid)
  ├ account/membership not active ......... skipped row, reason, no money
  ├ GS < quota(rank) ...................... skipped row, reason, no money
  └ otherwise ............................ amount = round(g%·GS + p%·PS, 2)   one rounding
                                            award row 'pending' → wallet credit 'royalty' → 'paid'

PAYOUT (reference terms, defaults)
  Supervisor  3% GS + 5% PS     quota ₱500,000
  Manager     5% GS + 10% PS    quota ₱1,000,000
  Director   10% GS + 15% PS    quota ₱5,000,000
  Leader / Member: ₱0 — Leader is not a royalty rank
  A Director is paid Director rates only; lower-rank rates are not added.
  GS includes PS, so a member's own purchase earns group% + personal% (Director: 25%).
  The quota can be met with the member's own purchases — the reference calls this self-fundable.
```

### 1.3 What is deliberately not built

- **No per-order royalty.** A paid order feeds the month’s GS/PS; the money is calculated once, at
  the month close (§12.6). This is the reference’s `evaluateMonth()` behaviour and the opposite of
  the repeat-purchase stream’s per-order award.
- **No retroactive payout.** Volume before `royalty_ledger_since` (the migration instant, rounded up
  to the next full month) is never paid. Months closed while the stream is off are recorded as
  `skipped` and can never be paid later (§12.6, D-15).
- **No clawback after a closed month.** An order cancelled **before** the close is excluded from GS;
  an order cancelled **after** the close does not change the award. The reference has no cancellation
  model and the build has no partial refunds; a retroactive adjustment stream is out of scope
  (§12.11, D-13, Q2).
- **No rank history table.** The award rows record the rank, rates, GS and PS of every Supervisor+
  for every closed month. Leader/Member rank history is not stored; the current rank is derived
  (§13.2, D-18, Q12).
- **No stacking.** A Director does not receive the Supervisor and Manager rates as well. One member,
  one rank, one award per period (D-11).
- **No carry-over, proration or catch-up of a missed quota.** A month stands alone; unused GS is lost
  (reference §11.2 #16). A member who ranks up mid-month is evaluated at the month end at the new rank
  for the whole month (reference §6.5).
- **No caps.** There is no per-member, per-period or network cap. Quotas and configured rates are the
  controls (reference §9.2, Q9).
- **No per-product royalty switch or per-product rates.** Royalty is a company-wide rank override on
  all paid product lines. Only the actual paid line total counts; shipping is excluded.
- **No QA / retail-customer gate, no “balanced” requirements, no Chairman.** Those come from older
  marketing drafts, not from the reference specification (§12.11, Q15).
- **No interaction with Harvest.** Harvest is a simulator-only stream; the application does not
  implement it. Royalty does not require Harvest qualification, purchases in a prior month or an
  “Active” flag.
- **No new routes, no new menu entries.** Every surface is a tab/query parameter on an existing page
  (D-16). `routes.php`, `routes_inventory.php` and `ParityTest` stay untouched.
- **No tax withholding, cost of goods, fees, refunds or chargebacks.** Awards are gross, like the
  referral and repeat-purchase streams.

### 1.4 Delivery: seven gated phases

| Phase | One-line purpose | What the gate proves | Adds money? | Adds UI? |
|---|---|---|---|---|
| **19A** | Add the two tables, the extended wallet ENUM, the sixteen settings; teach install, migration and reset about them | A migrated database equals a fresh install, and no application code reads the new objects | no | no |
| **19B** | Let an operator configure the ladder; derive and display ranks | The reference rank arithmetic holds on real trees, and the ladder saves, validates and audits — with no money | no | admin |
| **19C** | Evaluate and pay a closed month, with reconciliation and a resumable CLI close | Every money rule in §12: rank gates, quotas, GS/PS, one rounding, one award per member, idempotency, resume, no retroactive pay | **yes** | CLI only |
| **19D** | Show the money to administrators: ledger tab, period cards, reports, member panel, CSV | Finance can audit every peso from the UI, and the package/repeat tabs are unchanged | no | admin |
| **19E** | Show the member their rank, progress, history and payment state | The copy rules hold, the buyer sees only their own rows, and nothing promises money the engine will not pay | no | member |
| **19F** | Run the close unattended from cron, URL and Windows, with a doctor and an operations panel | The close is schedulable, observable, resumable and documented | no (runs 19C’s engine) | admin |
| **19G** | Documentation, QA pack, `APP_PHASE`, acceptance run, sign-off | The release is signed and everything above is reproducible | no | no |

**Why this split:**

- **The database first** (19A) because every later phase reads it, and because a schema change is the
  only part of the plan that is hard to reverse. Proving install/reset parity before anything reads
  the tables means no later bug can be blamed on the schema.
- **Configuration and ranks second** (19B) because the rank ladder is the only part a non-developer
  can verify by eye, and because a correct, visible rank with no money is a safe thing to leave in
  production for a week. It also proves the hardest algorithmic piece — unbounded-depth subtree
  counts — before any money depends on it.
- **Money third** (19C) as one indivisible phase. Splitting evaluation from payment would leave a
  state that is *wrong* rather than merely incomplete; the phase may be small, but it may not be
  incorrect. The close job ships here because the close **is** the money event.
- **Admin before member** (19D before 19E) because the ledger is the tool an operator uses to explain
  and audit the money; member pages are the marketing of an already-proven stream.
- **Operations last among the feature phases** (19F) because scheduling, diagnostics and a doctor are
  only meaningful once the close is correct.
- **Release last** (19G) because `APP_PHASE`, the QA pack and the sign-off describe the finished
  feature — they are meaningless before the feature is on.

### 1.5 Scope at a glance

| Area | Deliverable | Phase |
|---|---|---|
| Database | 2 new tables (`royalty_periods`, `royalty_awards`), `ewallet_ledger.ref_type` + `royalty`, 16 settings, migration `023_royalty_bonus.sql` | 19A |
| Configuration | `RoyaltyRank` engine, settings card, read-only ladder preview, admin member rank column and rank distribution | 19B |
| Money engine | `RoyaltyPeriod`, `RoyaltyAward`, `RoyaltyService`, `RoyaltyException`, `bin/royalty_month_close.php` (evaluate/pay/resume), wallet credits, reconciliation | 19C |
| Admin | Royalty tab on `/admin/commissions`, period cards, stats, filters, CSV, report cards, member royalty panel, dashboard KPI | 19D |
| Member | Royalty tab on `/earnings`, rank/progress card, month-to-date preview, history, dashboard tile, CSV | 19E |
| Operations | `public/cron/royalty_month_close.php`, `.cmd`, `--doctor`, operations panel, `docs/cron.md`, runbook, scheduler | 19F |
| Quality | ~130 new PHP cases, ~19 new smoke checks, one QA pack (sections A–G), docs | 19A–19G |

---

## 2. The phase protocol

### 2.1 What “self-contained” means in this document

“Self-contained” is a property of **delivery**, not of the schema. The phases share one data model
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
- **The invisible phase.** A phase whose gate is “the code compiles”. Every phase here has an
  observation a tester can make: a page, a count, a log line, a wallet balance or a period row.
- **The invalid phase.** A phase that leaves the stream in a state that is *wrong* while it waits for
  the next phase. Money is either fully implemented (19C) or not implemented at all (19A/19B).
- **The shared contract.** A value one phase writes and a later phase changes (for example: closing
  with 3/5/10% and “correcting” it to the reference’s 3/5/10 in a later phase). Rates, thresholds,
  column meanings, setting names, reason strings and status names are frozen at the phase that
  introduces them.

### 2.2 Phase map

| Phase | Name | Builds on | Effort | Cumulative suite floor |
|---|---|---|---:|---:|
| **19A** | Foundation: schema, wallet ENUM, settings storage, install parity | — | 1.5 d | 1,008 → ≥ 1,026 |
| **19B** | Configuration: ladder card, rank engine, admin rank visibility | 19A | 2.0 d | ≥ 1,050 |
| **19C** | Money engine: ledgers, rank/volume calculation, evaluate/pay, close job, reconcile | 19B | 4.0 d | ≥ 1,090 |
| **19D** | Admin visibility: commissions tab, period cards, reports, member panel, CSV | 19C | 1.5 d | ≥ 1,106 |
| **19E** | Member visibility: earnings tab, rank card, dashboard tile, CSV | 19C | 1.5 d | ≥ 1,122 |
| **19F** | Operations: web/Windows cron, doctor, operations panel, runbook | 19C | 1.5 d | ≥ 1,140 |
| **19G** | Release: docs, QA pack, `APP_PHASE`, acceptance, sign-off | all | 1.5 d | 1,140 |

**Total: ~13.5 ideal days**, plus roughly one day of gate overhead spread across the seven phases.
The suite column is a floor, not a target: it counts the cases the phase’s gate requires; the
baseline is today’s **1,008 / 1,008**.

**Dependency graph**

```
19A ──▶ 19B ──▶ 19C ──┬──▶ 19D ──┐
                      ├──▶ 19E ──┼──▶ 19G
                      └──▶ 19F ──┘
```

19D, 19E and 19F are **siblings**: each depends only on 19C, none reads another, and they may be
delivered in any order or concurrently by different developers. The critical path is
19A → 19B → 19C → (19D or 19E or 19F) → 19G ≈ **10.5 ideal days** of sequential work.

**Branches and tags.** One branch, `feature/royalty`, cut from `feature/unilevel` at `d8e626a`. One
tag per gate: `p19a-foundation`, `p19b-configuration`, `p19c-money`, `p19d-admin`, `p19e-member`,
`p19f-operations`, `p19g-release`. A phase starts from the previous tag and never from an uncommitted
tree. Tag `p19-start` marks the baseline before 19A.

### 2.3 The format of every phase section

Each phase in Part II answers the same questions in the same order:

| Block | What it must contain |
|---|---|
| **Goal** | one sentence: what is true after this phase that was not true before |
| **Builds on** | its only dependency (or “Nothing”), and the tag it starts from |
| **Ships alone because** | the argument for clause 4 of §2.1 — why the phase is correct even if every later phase is cancelled |
| **Effort, suite** | ideal days; the suite floor |
| **Reference** | the exact subsections of Part III this phase implements |
| **Deliverables** | `Database`, `Code`, `Docs`, and an explicit **Not yet** list |
| **Rules** | the normative constraints a reviewer checks the diff against |
| **Test plan** | `P19X-Tn` cases with exact expectations |
| **Manual gate** | numbered browser/CLI steps and the `QA19X-nn` identifiers in the QA pack |
| **Exit gate** | the checklist that closes the phase |
| **Rollback** | how to undo this phase alone |
| **Hand-off** | what now exists, which the next phase is allowed to assume |
| **Risks** | what can go wrong *in this phase* |

### 2.4 Definition of done — the universal gate

Every phase’s exit gate contains these ten items, plus its own phase-specific list. Copy the block
into [`docs/phase-log.md`](../docs/phase-log.md) when the phase closes, and fill Appendix H.

```
[ ] Schema state matches the plan: MigrationTest green (19A applies migration 023; later phases change no schema)
[ ] `php bin/build_install_sql.php` run and `--check` exits 0
[ ] `php tests/run.php` — full suite green, exit 0, case count at or above the phase floor
[ ] `php bin/wallet_reconcile.php` exits 0 (money untouched or money correct)
[ ] `powershell -File tests/smoke.ps1` — all checks pass, exit 0
[ ] Every P19X-Tn case for this phase exists, is named for the phase, and passes
[ ] The manual script for this phase is executed by someone who did not write it
[ ] No new PHP warning, notice or deprecation in `storage/logs/` after the manual pass
[ ] The QA pack section for this phase is filled, with evidence under `docs/qa/evidence/phase-19/`
[ ] `docs/phase-log.md` records the phase, tag, counts and deviations; Appendix H is filled
```

### 2.5 What a phase may never do

- **Never leave the suite red.** A phase that cannot get to green is not finished; it is not “mostly
  finished with the tests to follow”. The suite is the gate.
- **Never edit a later phase’s files.** If 19C needs a change in a view, that view belongs to 19D or
  19E and the change waits. The only exceptions are the shared files each phase is assigned in its
  manifest (for example `DatabaseResetService` in 19A and again in 19C, for different reasons).
- **Never invent a contract.** If an implementation must diverge from §12–§14, the plan is amended
  first (a new decision or a corrected table), then the code. A divergence discovered mid-phase is
  recorded as a deviation in the phase log.
- **Never pay money in a configuration phase** and never configure money in a payment phase. 19C
  assumes its settings and rank engine already exist; it does not ship a hidden admin form “to make
  testing easier”.
- **Never add a route.** D-16 is absolute for every phase: query parameters on existing pages only.
- **Never reopen a closed period.** There is no `reopen`, no `recalculate`, no `void` in v1. A closed
  month is an immutable financial record.
- **Never rewrite a ledger row.** The only award update is `pending → paid` plus its ledger pointer
  and paid timestamp; the money columns, rank, GS, PS, quota and rates never change.

### 2.6 Before you begin (once, before 19A)

1. Cut `feature/royalty` from `feature/unilevel` at `d8e626a`; tag `p19-start`.
2. Back up the database (`mysqldump`) and the working tree. The project’s standing rule: a backup
   outside the repository before any migration.
3. Record the baseline: `php tests\run.php` → **1,008 / 1,008**; `tests\smoke.ps1` → **229 / 229**;
   `php bin\build_install_sql.php --check` → exit 0; `php bin\wallet_reconcile.php` → exit 0;
   `SELECT COUNT(*)` on `information_schema.tables` → **31**; `settings` → **57**. If the baseline is
   not green, stop — a gate measured from a red baseline proves nothing.
4. Read §12 and §14 once, in full. They are the contract every phase is written against. Then read
   only the phase you are building; the other phases are not your scope.
5. Confirm the development database is at migration **022** and that `docs/phase-log.md` ends at the
   Phase 18 follow-up. A database that skipped the escrow migration cannot be used for 19A’s parity
   test.

---

## 3. Current state and the gap

### 3.1 There is no rank ladder

`rg -i rank system/app` returns two unrelated matches: a sentence in the membership view and a CSS
class in the top-products report. There is no `ranks` table, no rank column on `users`, no rank
service and no rank UI. The package “level” ladder and the repeat-purchase “level” ladder are
**sponsor-chain levels**, not career ranks; neither can express “Supervisor = Leader plus ten Leaders
below”.

The only countable career-like predicate today is a direct-recruit count:

| Fact | Evidence |
|---|---|
| Direct recruits are `users.sponsor_id` children, counted with `role = 'member'` | [`SponsorService::countDirectRecruits()`](../system/app/Services/SponsorService.php#L212-L221) |
| The downline walk is capped at **10 levels** | [`SponsorService::MAX_LEVELS`](../system/app/Services/SponsorService.php#L26-L27), `downline()` at L235–L279 |
| There is no “any depth” subtree count for rank qualification | no method anywhere in `system/app/Models` |
| The company is the primary admin account, not a member | [`Member::primaryAdmin()`](../system/app/Models/Member.php#L154-L172) |

Royalty needs counts the existing helper cannot supply: “ten Leaders **anywhere below**” can sit
deeper than ten sponsor levels, and the count must be computed for every member in one pass. 19B adds
that engine and deliberately does **not** raise `SponsorService::MAX_LEVELS` (that constant belongs to
the referral chain and its tests).

### 3.2 The sponsor tree is a single link, and it is stable

- `users.sponsor_id` is the **only** upline link (schema comment, `users` block L61–L62). There is no
  binary tree and no re-parenting in the application. Registration writes the sponsor once
  ([`RegistrationService`](../system/app/Services/RegistrationService.php)); only tests edit the
  column.
- `users.created_at` is the registration instant. Because a sponsor always exists before the member,
  the pair `(sponsor_id, created_at)` is enough to reconstruct the tree **as of any past instant**.
  This is the property that makes a late close reproducible (§12.6, D-06).
- `users.role` is `member | admin | superadmin`; `users.member_status` is
  `pending | active | inactive | suspended`; `users.is_active` is the sign-in switch.
- `users.created_at` is a `TIMESTAMP`; the PDO connection sets `SET time_zone = DB_TIMEZONE`
  (`+08:00`), so a PHP `Asia/Manila` string compared with `created_at` is consistent.

### 3.3 Purchases are paid orders, and only paid orders are money

| Fact | Evidence |
|---|---|
| An order reaches `paid` from e-wallet checkout or manual verification | [`OrderService::placeFromCart()`](../system/app/Services/OrderService.php), [`PaymentService::verify()`](../system/app/Services/PaymentService.php) |
| `paid_at` is stamped once, in the transaction that makes the order paid | migration `006_orders.sql`, `PaymentService` |
| The paid statuses are `paid, packing, ready_to_ship, shipped, delivered, completed` | [`Order::PAID_STATUSES`](../system/app/Models/Order.php) |
| A cancelled order keeps its rows but is excluded from money | `status = 'cancelled'`, Phase 7/18 cancellation |
| Line money is `shop_order_items.line_total = quantity × unit_price` | schema `shop_order_items` block |
| Shipping is stored on the order, never on a line | `shop_orders.shipping_fee` |
| Package activations are a separate table and a separate money event | `activations`, `commissions` |
| There is no partial refund, return or chargeback model | no such tables/columns; cancellation is terminal |

Royalty volume is therefore well-defined: **sum the line totals of paid orders whose `paid_at` falls
inside the month, excluding cancelled orders**. No new column is needed on the order tables.

### 3.4 There is no group-sales aggregation

Reports aggregate **awards**, not sales by team:

- `Report::salesByDay/Method/AllTime` aggregate orders by day/method, not by member subtree;
- `Report::unilevelSummary` sums `unilevel_awards`;
- `Report::commissionSummary` sums `commissions`;
- no query walks a sponsor subtree and sums order lines.

The unilevel engine reads a buyer’s **sponsor chain** (upward, ≤ 10), never a member’s **downline**
subtree (downward, any depth). Royalty is the first stream that needs a downward rollup over the
whole tree.

### 3.5 The month clock exists, but it pays nothing

Phase 17F built a real monthly close for the repeat-purchase stream:

| Object | What it does | Why it cannot be reused as-is |
|---|---|---|
| `UnilevelCycle::close()` | writes one `unilevel_activity` row per account and one `unilevel_periods` header, in one transaction | it snapshots personal purchases for the **Active** flag; it writes no money and must keep that invariant |
| `unilevel_periods` | one row per closed month, `threshold`, `members_total`, `qualified_count`, `award_total` | its `award_total` is a reporting snapshot of awards earned **during** the month, not a payout header |
| `bin/unilevel_month_close.php` | closes every completed month since `unilevel_ledger_since`; `--dry-run`, `--doctor`, lock, log | the job is documented and tested as “pays no money”; adding a payout would break that contract and its tests |

Royalty needs its **own** period table and its **own** close job. Sharing `unilevel_periods` would
couple two different lifecycles (a snapshot vs a payout), make the unilevel close a money job and
break the Phase 17F invariant “the close never pays”. The two jobs will run on the same calendar
periods, in either order; §12.6 documents the recommended order.

### 3.6 Neither existing ledger can hold a monthly award

| Ledger | Grain | Key | Why it cannot hold royalty |
|---|---|---|---|
| `commissions` | one award per activation, recipient and level | `UNIQUE (type, source_user_id, user_id, level)`; FKs require `activation_id` and `package_id` | a member can be paid royalty in many months with no activation or package; the unique key forbids the second month |
| `unilevel_awards` | one award per order line, recipient and level | `UNIQUE (order_item_id, user_id, level)`; FK to an order item | royalty is per member per **month**, not per line; a month has no `order_item_id` |
| `ewallet_ledger` | one money movement | `UNIQUE (user_id, ref_type, ref_id, type)` | this is the wallet ledger, not the award ledger; it must stay a movement log |

Royalty therefore gets a **separate** `royalty_awards` table whose natural money anchor is
`UNIQUE (period, user_id)`, plus a `royalty_periods` header whose primary key is the period. This is
the same reasoning as repeat-purchase D-05, applied to a different grain (D-07).

### 3.7 Wallet, reports and member visibility today

- `WalletService::credit()` writes one `ewallet_ledger` row and raises the withdrawable bucket;
  `REF_TYPES` is the single source of truth for `ref_type` and is compared with the live ENUM by
  `P5-T8`. Royalty adds one value, `royalty`, and a label/icon in `wallet_ref_label()` /
  `wallet_ref_icon()`.
- `/admin/commissions` has two tabs: package (`?ledger=` default) and repeat (`?ledger=repeat`).
  A third tab is a query parameter, not a route.
- `/earnings` has two tabs: package and repeat. Same rule.
- `/admin/reports` has sales, commission, unilevel, wallet, payout and code cards, with CSV exports
  driven by `ReportService::EXPORTS`.
- `/admin/members` lists members with status/package/sponsor and a detail page of partials. There is
  no rank column and no rank distribution.
- `/dashboard` has wallet, orders, membership and repeat-purchase cards; there is no royalty card.

### 3.8 The gap, in one table

| Need | Today | This plan | Phase |
|---|---|---|---|
| A place to configure ranks, quotas and rates | none | 16 settings in the Commissions settings card + read-only ladder preview | 19A / 19B |
| A derived rank per member | none | `RoyaltyRank` full-network pass, any depth, cycle-safe, as-of cutoff | 19B |
| A monthly award ledger | `commissions` and `unilevel_awards` cannot hold it | `royalty_awards`, unique `(period, user_id)` | 19A / 19C |
| A payout period header | `unilevel_periods` is a snapshot and must stay money-free | `royalty_periods` with statuses `evaluated` / `paid` / `skipped` | 19A / 19C |
| A monthly trigger | the unilevel close pays nothing | `RoyaltyService::evaluate()` + `pay()`, `bin/royalty_month_close.php` | 19C |
| Group sales (self + all descendants) | no downward rollup | one volume query + one reverse tree pass | 19C |
| Wallet attribution | no `royalty` ref type | `ewallet_ledger.ref_type = 'royalty'` + label/icon | 19A |
| Reconciliation | wallet/commission/unilevel/payout sections | `RoyaltyService::reconcile()` + a `wallet_reconcile` section + reset integrity | 19C |
| Admin visibility | package + repeat tabs only | royalty tab, period cards, reports, member panel, KPI, CSV | 19D |
| Member visibility | package + repeat tabs only | royalty tab, rank/progress card, dashboard tile, CSV | 19E |
| Unattended close | no royalty job | web/CLI/`.cmd` job, doctor, operations panel, docs | 19C / 19F |

### 3.9 Relationship to the repeat-purchase (unilevel) stream

The two streams share the **sponsor tree** but nothing else:

| | Repeat-purchase bonus | Royalty bonus |
|---|---|---|
| Direction | **up** the buyer’s sponsor chain, ≤ 10 hops | **down** the member’s team, any depth, for volume; **up** the rank requirements |
| Trigger | every paid order line | one closed calendar month |
| Recipient | the buyer’s uplines (level 1–10) | Supervisor/Manager/Director members |
| Amount | a per-product percentage of the line base | a rank percentage of monthly GS and PS |
| Gate | recipient is Active (previous month’s personal purchases) | rank + monthly GS quota + active account |
| Ledger | `unilevel_awards` | `royalty_awards` |
| Wallet ref | `unilevel` / `unilevel_reversal` | `royalty` |
| Period table | `unilevel_periods` (snapshot only) | `royalty_periods` (payout header) |
| Reversal | best-effort on cancellation (Phase 18) | none after a closed month (D-13) |

A single paid order can pay repeat-purchase awards immediately **and** feed the month’s royalty
volume. That is intended and stacked: the plan’s economics are described in §15.3. Neither stream
reads the other’s ledger, and neither can block the other. The closes are independent; the runbook
recommends running the unilevel close first only so the monthly logs read in a predictable order.

---

## 4. Decisions

Each decision is a contract for the implementation and the tests. Alternatives are listed so a later
review can reopen a decision without re-deriving it. The **Ships in** column is the phase that
introduces the rule; no later phase may change it.

| ID | Decision | Rationale | Rejected alternative | Ships in |
|---|---|---|---|---|
| **D-01** | The rank ladder is exactly **Member, Leader, Supervisor, Manager, Director** (levels 0–4), self-prerequisite, with thresholds configurable and defaulting to **10 directs / 10 Leaders / 10 Supervisors / 10 Managers** | The reference’s five ranks and 10/10/10/10 gates; configurability lets an operator tune without a code change and lets tests build small trees | Add a Chairman rank or “balanced” requirements from the V2/V3 drafts — not the reference, and they change the economics | 19A/19B |
| **D-02** | Royalty is paid **once per closed calendar month**, never per order | The reference’s `evaluateMonth()`; a monthly group-sales quota cannot be judged per order | Award on each paid order and reconcile later — would need clawbacks and would pay members below quota | 19C |
| **D-03** | The volume base is the **paid order line total** (`Σ shop_order_items.line_total`), not P.V. | The reference defines GS as “personal purchases”; a sales quota is a peso quota. Shipping, activations and top-ups are not purchases | Reuse `shop_order_items.base_total` (the unilevel P.V. base) — would make a “sales” quota depend on a product’s P.V. configuration and diverge from §6.5 | 19C |
| **D-04** | A month is a **calendar month in Asia/Manila**, keyed by `shop_orders.paid_at` | Matches `UnilevelCycle` and the app’s `APP_TIMEZONE`; money is counted when it was received | Use `created_at` — a manual order verified next month would pay in the wrong month | 19C |
| **D-05** | Rank counts include **`role = 'member'` accounts only**; staff accounts are not ranked, not counted and their purchases do not feed any member’s GS | A member rank must not be obtainable by an admin account, and staff purchases are not member sales | Count every `users` row — lets staff inflate ranks and group sales | 19B |
| **D-06** | Rank is computed **as of the period end** using `users.created_at < period_end`; live previews use the current tree | A late or catch-up close must produce the same ranks an on-time close would have produced; registrations in the new month must not retroactively qualify last month | Use the tree at run time — a delayed cron changes history | 19B/19C |
| **D-07** | A **separate `royalty_awards` ledger** and a **separate `royalty_periods` header**, not `commissions` or `unilevel_awards` | Different grain (member × month), different key, different lifecycle; the existing ledgers’ unique keys cannot hold it | Extend `commissions` — breaks the Phase 10–11 money anchor and every join | 19A/19C |
| **D-08** | **One award per member per period**, anchored by `UNIQUE (period, user_id)` | A member has one rank and one payout per month; the unique key makes a retried close a no-op | One row per rank/leg — a Director would be paid three times | 19A/19C |
| **D-09** | The quota is **all-or-nothing**, no proration, no carry-over, no catch-up | The reference is explicit (§6.5, §11.2 #10, #16); a member below quota earns ₱0 including the personal percentage | Prorate or carry unused GS — a different plan | 19C |
| **D-10** | **One rounding** on the combined `g%·GS + p%·PS`, in integer centavos, half-up | The reference rounds once (§2.2); per-rate rounding would drift | Round each component separately — a few centavos of drift and a different ledger | 19C |
| **D-11** | **No stacking.** A Director is paid Director rates only; the Supervisor and Manager rates are not added | The reference’s `payout(rankName, …)` uses one rank; stacking would multiply the plan’s cost | Pay every satisfied rank — silently triples a Director’s award | 19C |
| **D-12** | Payment goes to the **withdrawable e-wallet at close**, in resumable batches; `royalty_pay_enabled` pauses payment without losing evaluated awards | Royalty is earned at a completed month, not at delivery; a pause switch is the operational equivalent of the escrow release switch | Escrow until delivery — has no meaning for a monthly override and would delay money by weeks | 19C |
| **D-13** | **No clawback after a closed month.** An order cancelled before the close is excluded; an order cancelled after it does not change the award | The closed period is an immutable financial record; the reference has no cancellations; the build has no partial refunds | Recompute every ancestor’s award on every cancellation — expensive, error-prone and would reopen closed months | 19C |
| **D-14** | **Off by default.** `royalty_enabled = 0`; `royalty_pay_enabled = 1` | A migration or a deployment must never start paying money; the payment pause is separate from the earning switch | Enabled by default — the first close after deploy would pay the reference rates | 19A |
| **D-15** | **No retroactive payout.** The first payable month is the first **full** month after `royalty_ledger_since`; months closed while the stream is off are recorded `skipped` and never pay later | The company never agreed to pay pre-install volume; a skipped period is visible instead of silently missing | Pay every unclosed month since install — a large, unapproved payout | 19C |
| **D-16** | **No new routes.** New surfaces are query parameters on `/admin/settings`, `/admin/members`, `/admin/commissions`, `/admin/reports`, `/earnings`, `/dashboard`; the cron file is under `public/cron`, not the router | `APP_PHASE`/`ParityTest` gating stays untouched; one page per concept; fewer guards to forget | A `/royalty` page — more surface, more inventory entries, more tests | all |
| **D-17** | Thresholds, quotas and rates live in **settings**, are validated as a ladder, and are **snapshotted** into every period header and award row | Operations can tune the plan without a deploy; the snapshot makes every historic payout reproducible | Hard-code the reference constants — a rate change needs a release and cannot be audited | 19A/19B |
| **D-18** | **No rank history table in v1.** The current rank is derived; award rows are the historical record for Supervisor+ | The reference has no rank history (§11.2 #15); the award rows already store rank/rates/GS/PS for every paid month | A `member_ranks` table — a second source of truth that can drift from the tree | 19B/19C |
| **D-19** | The wallet ENUM gains **`royalty`** only (no reversal type), with a label and icon; `P5-T8` compares the list with the live ENUM | One credit per award; there is no reversal in v1 | Add `royalty_reversal` “for later” — a forward stub with no producer | 19A |
| **D-20** | `APP_PHASE` becomes **19** at 19G, and `HardeningTest` asserts it | The build should report the phase that shipped the feature; Phase 18 was a point release and deliberately left the constant at 17 | Bump to 18 — collides with the escrow release and misreports the feature | 19G |

> **Note on older documents.** [`dmp/workflow.md`](workflow.md) §“Royalty Path Bonuses”,
> [`dmp/ecowealth2.md`](ecowealth2.md) §7.0 and [`dmp/ecowealth3.md`](ecowealth3.md) §8.0 describe a
> QA/retail-customer gate, “balanced” rank requirements and (in V3) a Chairman rank. They are
> marketing explorations, not the reference specification. This plan follows
> [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §6, which has no QA gate and four ranks
> above Member. If the business later adopts a QA gate, it is a new stream with its own settings,
> snapshots and tests — not a silent change to this one.

---

# Part II — The phases

## 5. Phase 19A — Foundation

**Goal.** A migrated database equals a fresh install, the wallet ENUM can name a Royalty movement,
and the sixteen settings exist with the reference defaults — while **no application code reads any
of it**.

**Builds on.** Nothing. Starts from tag `p19-start` (`d8e626a`).

**Ships alone because** every object is inert: the tables are empty, `royalty_enabled` is `0`, no
model or service references the tables, and no page or job changes. If every later phase were
cancelled, the schema would still be a correct, reversible, well-tested addition — and the install
counts would still be truthful.

**Effort, suite.** 1.5 ideal days · floor **1,026** cases (baseline 1,008 + 18).

**Reference.** §13.1–§13.8 (data model, migration, settings, fresh-install mirror, invariants,
retention); §15.1 (guards); §16.4 (existing tests to update).

### Deliverables

**Database**

- `system/database/migrations/023_royalty_bonus.sql` — idempotent, additive:
  - `royalty_periods` (header, natural primary key `period`);
  - `royalty_awards` (money ledger, auto-increment `id`, `UNIQUE (period, user_id)`);
  - `ewallet_ledger.ref_type` extended with `royalty` (appended **last** in the ENUM);
  - the sixteen `royalty_*` settings, `ON DUPLICATE KEY UPDATE key_name = key_name`;
  - no backfill, no data migration, no `ALTER` of any existing money column.
- `system/database/schema.sql` — the same two tables and the extended ENUM, in the fresh-install
  source of truth. The tables are placed after `unilevel_periods` and before `impersonation_log`;
  `royalty_awards` references `royalty_periods` and `users`.
- `system/database/seeds/001_settings.sql` — the sixteen keys with the reference defaults.
- `system/database/install.sql` — rebuilt by `php bin/build_install_sql.php`.
- `system/database/install/header.sql` and `install/footer.sql` — the expected counts become
  **33 tables · 73 settings** (the other counts are unchanged: 2 staff, 0 members, 7 products,
  2 packages, 18 levels, 10 contents, 0 codes).

**Code**

- `WalletService::REF_TYPES` gains `'royalty'` (single source of truth, order matters for `P5-T8`).
- `wallet_ref_label('royalty')` → **Royalty bonus**; `wallet_ref_icon('royalty')` → `bx-crown`
  (`system/app/helpers.php`).
- `DatabaseInstaller::EXPECTED` → `tables_created 33`, `settings_rows 73`.
- `DatabaseResetService`:
  - `ALL_TABLES` gains `royalty_awards`, `royalty_periods`;
  - `ZERO_TABLES` gains both (money tables);
  - `AUTO_INCREMENT_TABLES` gains **`royalty_awards` only** (`royalty_periods` has a natural key);
  - `DELETE_GROUPS` deletes `royalty_awards` before `royalty_periods`;
  - no new live surface is added (the existing pages carry the new tabs).
- No model, service, controller, view or job is added in this phase.

**Docs**

- `docs/phase-log.md` — Phase 19A entry, tag, counts, deviations.
- `docs/install-reset.md` — 33 tables / 73 settings / migration range 001–023.
- `docs/deploy.md` — migration `023` appears in the upgrade list; the Phase 19 addendum is written
  in 19G, not here.

**Not yet**

- No settings card, no rank engine, no `RoyaltyPeriod`/`RoyaltyAward`/`RoyaltyService`, no award or
  period row, no wallet credit, no UI, no job. 19A must be mergeable with every royalty page absent.

### Rules

1. **Idempotent migration.** Every `CREATE TABLE` is `IF NOT EXISTS`; the ENUM change is guarded by
   an `information_schema.COLUMNS` probe on `COLUMN_TYPE` containing `royalty`; the settings insert is
   `ON DUPLICATE KEY UPDATE key_name = key_name`. Running the file twice changes nothing.
2. **Additive only.** No existing column is altered, dropped or renamed except the wallet ENUM, and
   the new value is appended at the end so MySQL’s instant/in-place ENUM append applies.
3. **Off by default.** `royalty_enabled = '0'`. `royalty_pay_enabled = '1'` (there is nothing to pay
   yet, and the default must match the post-19C intent). All other settings carry the reference
   defaults exactly.
4. **No backfill.** No period or award rows are created by the migration. A database upgraded at any
   time starts with zero royalty history; the first payable month is derived from
   `royalty_ledger_since` by the job (§12.6).
5. **Fresh = migrated.** `MigrationTest` builds one database from `001…023` and one from
   `schema.sql + seeds`, and compares tables, columns, indexes, foreign keys and the ENUM. The two
   must be equivalent in effect.
6. **Reset parity.** A reset empties both tables, resets `royalty_awards`’s counter, keeps the
   settings, and leaves `wallet_reconcile.php` clean.
7. **No producer.** A test asserts no file under `system/app` (outside `helpers.php`’s label map and
   `WalletService::REF_TYPES`) references `Royalty` — the phase cannot accidentally ship a half-wired
   reader.
8. **The label is present before the money.** A `royalty` ledger row written by a later phase must
   already render as “Royalty bonus”, not as `ucfirst('royalty')`.

### Test plan

| ID | Case | Expected |
|---|---|---|
| `P19A-T1` | Migration `023` on a `022` database | both tables, their columns, keys, FKs and checks exist |
| `P19A-T2` | Migration runs twice | second run is a no-op; no error; object list unchanged |
| `P19A-T3` | Migrated schema vs fresh install | table/column/index/FK sets are equal |
| `P19A-T4` | `ewallet_ledger.ref_type` | live ENUM contains `royalty` exactly once; `WalletService::REF_TYPES` matches the ENUM both ways |
| `P19A-T5` | `wallet_ref_label` / `wallet_ref_icon` | `Royalty bonus` / `bx bx-crown` |
| `P19A-T6` | Sixteen settings exist | every key has the exact default in §13.4 |
| `P19A-T7` | Re-running the settings insert | an operator-changed value is **not** overwritten |
| `P19A-T8` | `royalty_enabled` default | `'0'`; `setting_bool('royalty_enabled', true)` is false |
| `P19A-T9` | Install bundle rebuild | `build_install_sql --check` exit 0; header/footer say 33 tables, 73 settings |
| `P19A-T10` | `DatabaseInstaller::EXPECTED` | `33` / `73`; a fresh install reports `33 / 73 / 2 / 0 / 7 / 2 / 18 / 10 / 0` |
| `P19A-T11` | Reset with one royalty award and one period | both tables empty; `royalty_awards` counter reset; period counter not touched |
| `P19A-T12` | Reset preview | names both tables; no missing-table warning |
| `P19A-T13` | No producer in 19A | no `Royalty` class/read outside the label map and REF_TYPES |
| `P19A-T14` | `royalty_awards` constraints | `rank_level` outside 2–4, negative amount, and `personal_volume > group_volume` are refused |
| `P19A-T15` | Foreign keys | deleting a period cascades its awards; deleting a user cascades their awards |
| `P19A-T16` | `royalty_ledger_since` | parses as a datetime and is not empty |
| `P19A-T17` | Seed parity | `SeedParityTest` sees all sixteen keys in `seeds/001_settings.sql` |
| `P19A-T18` | Table count | `DbTargetTest`/`SqlScriptTest` report 33 tables |

### Manual gate (`QA19A-01 … QA19A-08`)

1. Take a `mysqldump` backup outside the repo; confirm the database is at `022`.
2. Run `mysql -u root <db> < database/migrations/023_royalty_bonus.sql`; run it a second time.
3. `SHOW TABLES LIKE 'royalty%'` → two rows; `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'` →
   the ENUM ends with `royalty`.
4. `SELECT key_name, value FROM settings WHERE key_name LIKE 'royalty\_%'` → sixteen rows with the
   defaults.
5. `php bin/build_install_sql.php` then `--check`; confirm the header/footer counts.
6. `php bin/db_install.php` on a scratch schema; confirm `33 / 73 / 2 / 0 / 7 / 2 / 18 / 10 / 0`.
7. `php bin/db_reset.php --preview` and a real reset on the scratch schema; confirm both royalty
   tables are zero and no missing tables are reported.
8. `php bin/wallet_reconcile.php` → exit 0.

### Exit gate

- [ ] The universal gate (§2.4), with migration `023` and floor **1,026**.
- [ ] `P19A-T1 … T18` pass.
- [ ] A migrated database and a fresh install agree, including the ENUM and the sixteen defaults.
- [ ] A reset leaves both royalty tables empty and `wallet_reconcile` clean.
- [ ] No application code reads `royalty_periods` or `royalty_awards`.
- [ ] `docs/install-reset.md` and `install/header.sql` / `install/footer.sql` say 33 / 73.

### Rollback

- **Before any royalty row exists:** drop `royalty_awards`, drop `royalty_periods`, restore the
  `ewallet_ledger.ref_type` ENUM to its `022` value, delete the sixteen settings. The migration file
  is additive, so a code-only revert is also safe: the objects simply go unused.
- **After any row exists:** never drop the tables. Revert the code, leave the schema and the settings
  in place; the stream is off and no money can move.

### Hand-off

19B may assume: `royalty_periods` and `royalty_awards` exist and are empty; the `royalty` ref type,
the label and the icon exist; the sixteen settings are stored and readable through `setting_*`; the
resolve/install/reset counts are 33/73. It may **not** assume any rank engine, settings card, period
or award row.

### Risks

| Risk | Mitigation |
|---|---|
| The ENUM alter locks a large `ewallet_ledger` | Append the value last (instant in MySQL 8); run in a maintenance window; the table is a movement log, not huge in this build |
| The fresh-install mirror drifts from the migration | `MigrationTest` compares the two; the install bundle is rebuilt and `--check`ed |
| A later phase forgets the reset lists | `DbResetTest` fails on the row count; 19C adds the integrity map |
| A “helpful” developer adds a reader in 19A | `P19A-T13` asserts no producer; the settings card waits for 19B |

---

## 6. Phase 19B — Configuration and the rank ladder

**Goal.** An operator can see and edit the whole Royalty ladder on `/admin/settings`, and the
application can derive the correct rank for every member on a real tree — with **no money created**.

**Builds on.** 19A (tag `p19a-foundation`).

**Ships alone because** the settings are inert until 19C, the rank column is read-only, and the rank
engine has no money path. An operator could run this build indefinitely: they would see who is a
Supervisor, edit the ladder, and nothing would pay.

**Effort, suite.** 2.0 ideal days · floor **1,050** cases.

**Reference.** §12.2–§12.3 (the ladder and rank derivation), §12.9 (validation), §13.4 (settings),
§13.6 (fresh mirror), §14.3 (rank algorithm), Appendix A (defaults).

### Deliverables

**Code**

- `system/app/Models/RoyaltyRank.php`:
  - rank constants `MEMBER=0`, `LEADER=1`, `SUPERVISOR=2`, `MANAGER=3`, `DIRECTOR=4`;
  - `NAMES`, `SLUGS`, `PAYING` (2–4), `LABELS` for badges;
  - `thresholds()` — reads the four settings with defaults;
  - `ladder()` — the full configured ladder (thresholds, quotas, rates) for the settings card and the
    period snapshot;
  - `map(?string $asOf = null): array` — one query over `users` (`role = 'member'`, optional
    `created_at < $asOf`), one in-memory pass, one row per member:
    `id, username, member_status, is_active, parent_id, rank, rank_name, directs, downline_leaders,
    downline_supervisors, downline_managers, team_size, volume_cents, group_volume_cents` (the two
    volume fields are 0 until `withVolumes()`);
  - `forMember(int $userId, ?string $asOf = null): ?array`;
  - `counts(array $map): array` — exclusive counts per rank (Member, Leader, Supervisor, Manager,
    Director) and `ranked` (Supervisor+);
  - `requirements(array $row): array` — the next-rank requirement for a member row (for 19E’s
    progress card);
  - `withVolumes(array $map, array $volumesCents): array` — injects each member’s own volume in
    cents and rolls it up to `group_volume_cents`; returns the same map with both fields;
  - `assertReadable(array $map): void` or equivalent defensive checks for cycles and orphans.
- `system/app/Controllers/AdminSettingsController.php`:
  - `REPEAT_FIELDS` stays untouched; a new `ROYALTY_FIELDS` (13 numeric fields) and
    `ROYALTY_TOGGLES` (2 switches) are added; `royalty_ledger_since` is stored but not shown;
  - `settingsContext()` passes `royalty_fields` and `royalty_toggles`;
  - `sectionKeys('commissions')`, `sectionToggles('commissions')`, `sectionRules('commissions')`,
    `sectionLabels('commissions')` and `sectionCrossFieldErrors('commissions')` learn the new keys;
  - `settingsContext()` also passes `royalty_ladder` from `RoyaltyRank::ladder()` for the read-only
    saved-ladder table (the table shows what is stored; the inputs show what is about to be saved).
- `system/app/views/admin/settings/_commissions.php` — a **“Royalty bonus”** sub-block below the
  repeat-purchase block: the enable switch, the payment switch, the four requirements, the three
  quotas, the six rates, and the read-only ladder table. The existing card’s form still posts one
  `section=commissions` payload.
- `system/app/views/admin/settings/index.php` — passes the new arrays through (the wrapper already
  passes `repeat_fields`/`repeat_toggles`; add `royalty_fields`/`royalty_toggles`).
- `system/app/Controllers/AdminMemberController.php` — computes `RoyaltyRank::map()` once for the
  list page and attaches `rank_level`/`rank_name` to each row; computes the distribution for the
  stats area; the detail page passes the member’s rank row to the new panel (the panel itself is 19D;
  in 19B only the list column and the distribution are required).
- `system/app/views/admin/members/_rows.php` — a **Rank** column with a badge (`Member`, `Leader`,
  `Supervisor`, `Manager`, `Director`). Sorting stays the existing order; no new filter.
- `system/app/views/admin/members/_stats.php` — a compact rank distribution (Member/Leader/
  Supervisor/Manager/Director counts).
- `system/app/views/admin/settings/_commissions.php` and the members views use existing CSS classes;
  no new stylesheet is required.

**Docs**

- `docs/admin-guide.md` — a new **§16 Royalty bonus** section: what the ranks are, the default
  ladder, how to edit it, what the switches do, and why Leader is not paid.
- `docs/phase-log.md` — 19B entry.

**Not yet**

- No `RoyaltyPeriod`, no `RoyaltyAward`, no `RoyaltyService`, no award row, no wallet credit, no
  `/admin/commissions` royalty tab, no `/earnings` royalty tab, no job, no `royalty` wallet movement.
- The member-facing rank/progress card is 19E; the admin detail panel is 19D.

### Rules

1. **Defaults are the reference.** With no settings changed, the card renders Member/Leader/
   Supervisor/Manager/Director with 10/10/10/10, quotas 500,000 / 1,000,000 / 5,000,000 and rates
   3/5, 5/10, 10/15. The ladder table’s “own purchase” column is group% + personal% (8, 15, 25).
2. **Validation.** Each threshold is an integer with the range in §12.9; each quota is a decimal
   `0.00–999,999,999.99`; each rate is `0.000–100.000` with at most 3 decimals. Cross-field:
   quotas non-decreasing by rank; group rates non-decreasing; personal rates non-decreasing; each
   rank’s `group + personal ≤ 100`; enabling the stream requires at least one positive rate. Errors
   are field errors, not a flash-only message.
3. **Rank derivation is exact.** The four predicates of §12.2 are applied bottom-up in one pass:
   `Leader`, then `subtree(Leader)`, then `Supervisor`, then `subtree(Supervisor)`, then `Manager`,
   then `subtree(Manager)`, then `Director`. Counts exclude the member themselves (`−1`).
4. **Any depth.** The rank pass has **no 10-level cap**. A Leader ten levels below a candidate still
   counts toward the candidate’s Supervisor requirement. `SponsorService::MAX_LEVELS` is not changed.
5. **Role and status.** Only `role = 'member'` accounts are loaded. A suspended/pending member still
   counts as a person for their upline’s rank (reference §6.2: ranks count people, not production).
   The account’s own status is a payout gate in 19C, not a rank gate here. Staff rows in the admin
   member list render **—** for Rank, never “Member”: they are not in the royalty tree at all.
6. **Cutoff.** `map($asOf)` includes only accounts with `created_at < $asOf`. `map(null)` includes
   every current member account. Both are deterministic; the SQL is one query with the role and
   optional cutoff, ordered by `id`.
7. **Cycle safety.** An account whose `sponsor_id` is itself, missing, staff, or part of a cycle is a
   **root** for the pass (or excluded when it is in a cycle). The pass uses a visited set and never
   recurses blindly. A test builds a two-node cycle and asserts both accounts are unranked and the
   request terminates.
8. **No money.** The card save, the rank column and the distribution never write a period, an award,
   a wallet row or an audit row other than `settings.update`. `P19B-T25` asserts the wallet balances
   and the ledger are unchanged.
9. **Audit.** Saving the commissions card writes one `settings.update` row with the changed keys
   (the existing controller does this; the new keys must be included in the changed-key list).
10. **No settings section.** The card stays inside `section=commissions`; there is no new settings
    page and no new route.

### Test plan

| ID | Case | Expected |
|---|---|---|
| `P19B-T1` | Settings card renders | the Royalty sub-block, two switches and thirteen numeric inputs render; the ladder table shows the defaults |
| `P19B-T2` | Save round-trip | changed values persist; the page re-renders them |
| `P19B-T3` | Directs validation | `0`, `-1`, `abc`, `1001` refused; `1` and `1000` accepted |
| `P19B-T4` | Quota validation | negative, `1.234`, `1e9` refused; `0.00` and `999,999,999.99` accepted |
| `P19B-T5` | Rate validation | `-0.001`, `100.001`, `1.2345` refused; `0.000` and `100.000` accepted |
| `P19B-T6` | Non-decreasing quotas | Manager quota < Supervisor quota is refused |
| `P19B-T7` | Non-decreasing group rates | Director group 2% after Manager 5% is refused |
| `P19B-T8` | Non-decreasing personal rates | Director personal 4% after Manager 10% is refused |
| `P19B-T9` | Group + personal ≤ 100 | Supervisor 60/50 is refused |
| `P19B-T10` | Enable with all-zero rates | refused with a field error |
| `P19B-T11` | Settings audit | one `settings.update` row naming the new keys; no money movement |
| `P19B-T12` | Rank: Member | a member with 0 directs is rank 0 |
| `P19B-T13` | Rank: Leader | exactly `leader_directs` directs → rank 1; one fewer → rank 0 |
| `P19B-T14` | Rank: Supervisor | Leader + exactly `supervisor_leaders` downline Leaders → rank 2; one fewer → rank 1 (the 111-member tree proves the default 10) |
| `P19B-T15` | Rank: Manager | Supervisor + exactly `manager_supervisors` downline Supervisors → rank 3; one fewer → rank 2 |
| `P19B-T16` | Rank: Director | Manager + exactly `director_managers` downline Managers → rank 4; one fewer → rank 3 |
| `P19B-T17` | Counts exclude self | a Supervisor’s own Leader predicate is not counted in its own 10 |
| `P19B-T18` | Any depth | a Leader 11+ levels below a candidate counts toward the candidate’s Supervisor requirement |
| `P19B-T19` | Staff excluded | an admin with 10 directs is never ranked and its directs do not count for a member upline |
| `P19B-T20` | Suspended members count | a suspended Leader still counts in its upline’s Supervisor requirement |
| `P19B-T21` | Cutoff | a member created after `$asOf` is not loaded; its sponsor’s directs drop by one |
| `P19B-T22` | Cycle | a two-node `sponsor_id` cycle terminates, both accounts are unranked, no PHP warning |
| `P19B-T23` | Admin member rank column | the list shows the correct badge for each fixture rank |
| `P19B-T24` | Rank distribution | the stats card counts the fixture tree exactly |
| `P19B-T25` | No money | after saving the card and rendering the list, wallet balances, `ewallet_ledger`, `royalty_periods` and `royalty_awards` are unchanged |
| `P19B-T26` | Ladder preview | 3/5, 5/10, 10/15 render as references; own-purchase column 8/15/25 |

### Manual gate (`QA19B-01 … QA19B-09`)

1. Sign in as an administrator; open `/admin/settings#settings-commissions`; confirm the Royalty
   sub-block and the five-row ladder.
2. Change Supervisor directs to 3 and save; reload and confirm the value and one audit row.
3. Try a Manager quota below the Supervisor quota; confirm a field error and no save.
4. Try a group rate of 60 with a personal rate of 50; confirm the “group + personal ≤ 100” error.
5. Restore the defaults (10/10/10/10, 500k/1M/5M, 3/5, 5/10, 10/15).
6. Open `/admin/members`; confirm the Rank column and the distribution card.
7. On the seeded demo tree, confirm every rank badge matches the 10-direct/10-ranked rule.
8. Confirm the wallet pages and `/admin/commissions` show no royalty money.
9. `php tests/run.php Royalty` → green; `php bin/wallet_reconcile.php` → exit 0.

### Exit gate

- [ ] The universal gate (§2.4), floor **1,050**.
- [ ] `P19B-T1 … T26` pass.
- [ ] The settings card validates every rule in §12.9 and audits a save.
- [ ] Ranks on the demo tree and the 111-member fixture are correct.
- [ ] No wallet row, period row or award row is written by any 19B surface.
- [ ] The admin guide’s §16 exists and matches the card.

### Rollback

Remove the Royalty sub-block from `_commissions.php`, the rank column/distribution from the members
views and the `ROYALTY_FIELDS`/`ROYALTY_TOGGLES` constants. The sixteen settings and the two tables
stay (they are 19A’s, inert). No money exists to reverse.

### Hand-off

19C may assume: `RoyaltyRank::map($asOf)` and `withVolumes()` return the documented rows; the
settings ladder is validated and snapshottable; the rank distribution is available. It may **not**
assume any period/award/service, any close command, or any member/admin royalty page.

### Risks

| Risk | Mitigation |
|---|---|
| The rank pass is O(n) and a huge network slows the members list | one query, in-memory pass, no N+1; §14.9 sets a 50k-member budget; the list page is admin-only |
| A threshold edit silently demotes people | the card warns that ranks are derived and can change at the next close; the audit records the change |
| A cycle in corrupt data loops the pass | visited set + root handling; `P19B-T22` |
| `SponsorService::MAX_LEVELS` gets “fixed” to 10+ | the rank pass is a separate engine; 19B does not touch `SponsorService` |

---

## 7. Phase 19C — Money engine

**Goal.** A completed month can be evaluated and paid exactly once, with the reference’s rank gates,
quota, GS/PS arithmetic and one rounding — and the result reconciles against the wallet ledger.

**Builds on.** 19B (tag `p19b-configuration`).

**Ships alone because** it is the whole money path: if 19D and 19E were cancelled, an operator could
still run `bin/royalty_month_close.php` from the CLI and the money would be correct. If 19F were
cancelled, the close would still be runnable manually; only the scheduler and the doctor would be
missing.

**Effort, suite.** 4.0 ideal days · floor **1,090** cases.

**Reference.** §12.4–§12.10 (volume, formula, examples, rounding), §13.2–§13.8 (tables, invariants),
§14.1–§14.13 (API, algorithms, idempotency, concurrency, job, reconcile, cancellation), §15.2–§15.4.

### Deliverables

**Code**

- `system/app/Models/RoyaltyPeriod.php`:
  - `periodOf(?string $datetime = null)`, `previous()`, `next()`, `bounds()` (the same
    `Asia/Manila` month helpers as `UnilevelCycle`, duplicated deliberately so the two calendar
    implementations can be tested against each other);
  - `baseline()` — derives the first payable month from `royalty_ledger_since`; if the timestamp is
    not exactly the first instant of its month, the first payable month is the **next** month;
  - `exists()`, `status()`, `find()`, `periods(int $limit = 12)`, `pendingPeriods()`,
    `evaluatedPeriods()`, `duePeriods()`, `lastPaid()`;
  - no write method except the ones the service uses through SQL.
- `system/app/Models/RoyaltyAward.php`:
  - `create(array $draft): int` (one row, inside the caller’s transaction);
  - `pendingForPeriod(string $period, int $limit): array` (`FOR UPDATE`, ascending `user_id`);
  - `markPaid(int $id, int $ledgerId): bool` (the only update path);
  - `forMemberPeriod(int $userId, string $period): ?array`;
  - totals: `totalsForPeriod()`, `totalsForUser()`, `rankBreakdown()`;
  - filters/pages for 19D/19E (built now, rendered later): `filterDefaults()`,
    `normaliseFilters()`, `countForPeriod()`, `pageForPeriod()`, `countForUser()`,
    `pageForUser()`, `exportRows()`;
  - `STATUSES = ['pending','paid','skipped']`, `STATUS_LABELS`, `REASONS` (stable strings).
- `system/app/Services/RoyaltyService.php`:
  - `preview(string $period): array` — computes the period exactly as `evaluate()` would, writes
    nothing;
  - `evaluate(string $period, ?int $actorId = null): array` — writes award rows and the period header;
  - `pay(string $period, int $limit = 500, ?int $actorId = null): array` — credits pending awards in
    ascending member order, in batches, and finalises the header when none remain;
  - `close(string $period, int $limit = 500, ?int $actorId = null): array` — `evaluate()` then
    `pay()` until final; an already-`evaluated` period is resumed with `pay()` and never re-evaluated;
  - `statusFor(int $userId, ?string $period = null): array` — rank, MTD PS/GS, quota, rates,
    projected payout, history summary (for 19E);
  - `reconcile(): array` — the checks in §14.12;
  - `enabled()`, `paymentsEnabled()`, `ladder()` (settings snapshot).
- `system/app/Services/RoyaltyException.php` — typed reasons: `not_found`, `closed`, `future`,
  `current`, `state`, `paused`, `empty`.
- `system/bin/royalty_month_close.php` — the close runner:
  - flags `--period=YYYY-MM`, `--dry-run`, `--evaluate-only`, `--pay-only`, `--limit=N`,
    `--max-batches=N`, `--json`, `--quiet`, `--log-dir=`, `--help`;
  - default: resume every `evaluated` period, then close every pending completed month, oldest first,
  evaluate then pay;
  - `CronLock('royalty_month_close')`, `CronLog('royalty_month_close')`;
  - exit 0 = success/no-op/busy; 1 = processing error; 2 = environment/config error;
  - no web entry and no `.cmd` in this phase (19F).
- Wallet integration:
  - `WalletService::credit($userId, $amount, 'royalty', $awardId, $note, true)`;
  - `royalty_awards.ledger_id` stores the movement id; `credited_at` stores the time.
- Reconciliation integration:
  - `bin/wallet_reconcile.php` gains a **Royalty** section (total, paid count, pending, skipped,
    periods, mismatches);
  - `DatabaseResetService`’s integrity map gains `'royalty' => (new RoyaltyService($this->pdo))->reconcile()`.
- No controller or view change. 19C is headless by design; the job is the observation surface.

**Docs**

- `docs/phase-log.md` — 19C entry with the money rules, the close procedure and the test counts.
- `docs/runbook.md` — a **Royalty close** subsection: how to run `--dry-run`, `--evaluate-only`,
  `--pay-only`, how to read the log, what to do after a partial payment, and the “never edit a closed
  award” rule.

**Not yet**

- No `/admin/commissions` royalty tab, no `/earnings` royalty tab, no reports, no dashboard tile, no
  settings card changes, no web cron, no `.cmd`, no `--doctor`.

### Rules

1. **Evaluation writes no wallet money.** `evaluate()` may only insert `royalty_periods` and
   `royalty_awards` rows. `pay()` is the only method that calls `WalletService::credit()`. This
   separation is tested and is what makes `--evaluate-only` safe.
2. **One award per member per period.** `UNIQUE (period, user_id)`. A second evaluation of the same
   period is refused before any write (`exists()` pre-check + primary-key race).
3. **Only Supervisor, Manager and Director get rows.** A Leader or Member gets no row; the member page
   explains why (19E). A Supervisor+ who is ineligible or below quota gets a `skipped` row with a
   reason, so the ledger can explain missing money.
4. **The rank is the period-end rank.** `RoyaltyRank::map($periodEnd)`; no live tree at run time.
   Registrations after the period end do not change the award. The award stores `rank_level`,
   `directs`, `downline_rank_count` and `team_size`.
5. **GS and PS are period-scoped paid line totals.** The query in §14.4: `paid_at ∈ [start, end)`,
   `status <> 'cancelled'`, `SUM(shop_order_items.line_total)`. Shipping, activations, top-ups,
   transfers, fees and staff purchases are excluded.
6. **The quota gate is all-or-nothing.** Compare in cents: `GS_cents >= quota_cents`. Exactly at the
   quota pays. One cent below is a `skipped` row with reason `group sales below quota`.
7. **One rounding, in integer cents.** `amount_cents = round_half_up((g_milli·GS_cents +
   p_milli·PS_cents) / 100000)`. The award stores `DECIMAL(12,2)`. No float in the final stored
   amount.
8. **No stacking.** The rank selects exactly one `(quota, group_pct, personal_pct)` triple. The
   lower-rank triples are never applied.
9. **Idempotency at four layers** (§14.6): the period header PK, the award `UNIQUE (period,user_id)`,
   the `pending → paid` conditional update, and the wallet ledger’s
   `UNIQUE (user_id, ref_type, ref_id, type)`.
10. **Payment is resumable.** Batches of `--limit` (default 500, max 5,000), ascending `user_id`,
    each batch its own transaction. A crash mid-period leaves `status='evaluated'` with some awards
    `paid`; the next run resumes. The header’s `paid_count`/`paid_total` are updated per batch.
11. **The period becomes `paid` only when no `pending` award remains.** If there are no pending
    awards at evaluation time (all skipped, or no Supervisor+), it becomes `paid` immediately with
    `paid_total = 0`.
12. **The stream switch is read at evaluation.** `royalty_enabled = 0` → a `skipped` period header
    with `note = 'royalty stream off'`, no award rows, final. The payment switch is read at payment:
    `royalty_pay_enabled = 0` → evaluate still writes awards, `pay()` returns `paused` and leaves them
    pending.
13. **No retroactive pay.** `pendingPeriods()` starts at the first **full** month after
    `royalty_ledger_since`; the current month and future months are refused. A month that was closed
    while off stays skipped.
14. **No reopen.** A `paid` or `skipped` period is final; `--period` on it exits 0 with
    “already final”. A `evaluated` period can only be paid, never re-evaluated.
15. **A cancellation before the close is excluded; after the close it changes nothing.** The
    evaluation reads `status <> 'cancelled'`; the closed rows are immutable (D-13).
16. **Every write is audited.** `royalty.period.evaluate`, `royalty.period.pay` (per batch),
    `royalty.period.skip`; meta includes the period, counts, totals and the settings snapshot.
17. **The job never prints a secret and never trusts input.** `--period` is validated as `YYYY-MM`;
    `--limit` is clamped; the lock is released in `finally`; one log line per run.

### Test plan

| ID | Case | Expected |
|---|---|---|
| `P19C-T1` | Preview, empty period | 0 awards, 0 recipients, totals 0; no rows written |
| `P19C-T2` | Evaluate with the stream off | `royalty_periods` row `status='skipped'`, note; 0 awards; no ledger row |
| `P19C-T3` | Evaluate a Supervisor at quota | one `pending` row; GS/PS/quota/rates/amount exact; no wallet row |
| `P19C-T4` | Pay that period | one `royalty` ledger credit; award `paid`; `ledger_id` set; header `paid` |
| `P19C-T5` | Pay again | no second ledger row; exit 0 / “nothing pending” |
| `P19C-T6` | Evaluate a `paid` period | refused, no writes |
| `P19C-T7` | Evaluate a `skipped` period | refused, no writes |
| `P19C-T8` | Below quota (`499,999.99`) | `skipped`, reason `group sales below quota`, amount 0 |
| `P19C-T9` | Exactly at quota | `pending`/`paid`, amount = `quota × group%` (personal 0) |
| `P19C-T10` | Leader with ₱10M GS | no row; no money |
| `P19C-T11` | Member with volume | no row |
| `P19C-T12` | Manager and Director at quota | Manager → `50,000.00`; Director → `500,000.00`; each only its own rates, no Supervisor/Manager stacking |
| `P19C-T13` | Personal counted twice | Director GS 5,000,000 incl. PS 100,000 → `515,000.00` |
| `P19C-T14` | Quota self-funded | Supervisor buys 500,000 alone → `40,000.00` |
| `P19C-T15` | One-cent below | `0.00` |
| `P19C-T16` | Rounding | GS 500,000.55 / PS 60,000.55 at 3/5 → `18,000.04` |
| `P19C-T17` | 3-decimal rate | 3.125% on 10,000 → `312.50`; stored rate 3.125 |
| `P19C-T18` | GS all depths | a 12-level-deep descendant’s purchase is in the ancestor’s GS |
| `P19C-T19` | Cancelled order before close | excluded from GS/PS |
| `P19C-T20` | Shipping | excluded (line totals only) |
| `P19C-T21` | Package activation | excluded (no shop order line) |
| `P19C-T22` | Staff purchase | excluded from every member’s GS |
| `P19C-T23` | Rank cutoff | a member registered after period end does not lift its sponsor’s rank |
| `P19C-T24` | Inactive account | `skipped`, reason `account is not active` |
| `P19C-T25` | Pending membership | `skipped`, reason `membership is not active` |
| `P19C-T26` | All rates zero | `skipped`, reason `zero amount` |
| `P19C-T27` | Header totals | `award_count`, `paid_count`, `skipped_count`, `award_total`, `paid_total` match the rows |
| `P19C-T28` | Rank distribution | five exclusive counts sum to `members_count` |
| `P19C-T29` | Batch resume | pay 1 of 3, simulate failure, rerun → all 3 paid, no double |
| `P19C-T30` | `royalty_pay_enabled = 0` | evaluate writes pending; `pay()` returns paused; no ledger row |
| `P19C-T31` | Deterministic lock order | recipients are credited in ascending `user_id` |
| `P19C-T32` | Reconcile clean | `ok = true`, no mismatches |
| `P19C-T33` | Reconcile missing ledger | a paid award with `ledger_id = NULL` is a mismatch |
| `P19C-T34` | Reconcile orphan ledger | a `royalty` ledger row with no award is a mismatch |
| `P19C-T35` | Reconcile drift | ledger amount ≠ award amount is a mismatch |
| `P19C-T36` | Reconcile header drift | `paid_total` ≠ sum of paid awards is a mismatch |
| `P19C-T37` | Reconcile period state | a `pending` award in a `paid` period is a mismatch; an `evaluated` period with no pending is a warning, `ok = true`, exit 0 |
| `P19C-T38` | CLI `--dry-run` | prints the preview; writes no rows, no wallet |
| `P19C-T39` | CLI `--evaluate-only` | writes awards + header; no wallet |
| `P19C-T40` | CLI `--pay-only` | pays an evaluated period; refuses an absent/paid one |
| `P19C-T41` | CLI catch-up | two missed months close oldest first, each with its own cutoff |
| `P19C-T42` | CLI current month | exit 1, message; no writes |
| `P19C-T43` | CLI future month | exit 1, message; no writes |
| `P19C-T44` | CLI lock | second run `[SKIP ]`, exit 0, no double pay |
| `P19C-T45` | CLI `--json` + log | machine-readable payload; one log line with period/counts/totals |
| `P19C-T46` | Wallet reconcile section | `bin/wallet_reconcile.php` includes Royalty and exits 0 when clean |
| `P19C-T47` | Reset integrity | `DatabaseResetService` runs `RoyaltyService::reconcile()` and reports clean after a reset |
| `P19C-T48` | No routes/controllers | `routes.php` unchanged; no controller/view references a royalty award in 19C |

### Manual gate (`QA19C-01 … QA19C-12`)

1. Build the 15-member fixture at `docs/qa/phase-19-royalty.md` §C (Director, 2 Managers, 4
   Supervisors, 8 Leaders) with the low thresholds (2/2/2/2) and paid orders in the last completed
   month.
2. `php bin/royalty_month_close.php --period=<P> --dry-run --json`; record the expected awards.
3. `php bin/royalty_month_close.php --period=<P> --evaluate-only`; confirm award rows and **no**
   wallet movement.
4. `php bin/royalty_month_close.php --period=<P> --pay-only`; confirm wallet credits and `paid`.
5. Re-run `--period=<P>`; confirm “already final”, no double credit.
6. `php bin/wallet_reconcile.php`; confirm the Royalty section and exit 0.
7. Mark one order cancelled **in the same month before close** in a fresh fixture; confirm it is
   excluded.
8. Cancel an order **after** the close; confirm the award is unchanged.
9. Set `royalty_pay_enabled = 0`; evaluate a fresh month; confirm pending and no wallet; re-enable and
   `--pay-only`.
10. Set `royalty_enabled = 0`; close a fresh month; confirm a `skipped` header and no awards.
11. Run a two-month catch-up; confirm both periods, oldest first, and ranks using each period’s end.
12. `php tests/run.php Royalty` → green; `php bin/wallet_reconcile.php` → exit 0.

### Exit gate

- [ ] The universal gate (§2.4), floor **1,090**.
- [ ] `P19C-T1 … T48` pass (or the shipped subset recorded in Appendix H with a reason).
- [ ] Every reference example in §12.7 reproduces exactly.
- [ ] `evaluate()` writes no wallet row; `pay()` credits exactly once.
- [ ] A partial payment resumes without double-paying.
- [ ] `wallet_reconcile.php` has a Royalty section and exits 0.
- [ ] A reset runs the royalty reconcile and leaves both tables empty.
- [ ] No route, controller or view is added.

### Rollback

- Set `royalty_enabled = 0` and stop the job. The tables and any paid awards stay; no code path can
  create a new period without the job.
- Revert the code. Paid money is real ledger history and is **not** reversed automatically; a manual
  correction is a finance decision, not a code rollback.
- The job is not scheduled yet (19F), so there is no cron entry to remove.

### Hand-off

19D, 19E and 19F may assume: `RoyaltyPeriod`, `RoyaltyAward`, `RoyaltyService` and the CLI exist;
`reconcile()` is clean; periods can be `evaluated`, `paid` or `skipped`; award rows carry every
display field (rank, GS, PS, quota, rates, amount, status, reason, ledger, paid time); the wallet
ref type and label exist. They may **not** assume any admin or member surface, any web cron, or any
`--doctor`.

### Risks

| Risk | Mitigation |
|---|---|
| A large month pays thousands of wallets in one transaction | evaluate one transaction; pay in batches of 500, each its own transaction, resumable |
| A batch fails mid-period | header stays `evaluated`; rerun `--pay-only`; the conditional `pending → paid` and wallet unique key prevent double pay |
| A rank pass on a corrupt tree loops or double-counts | visited set, root handling, one row per account; `P19B-T22` and `P19C-T23` |
| Float drift in GS/PS or the award | SQL `SUM(DECIMAL)`; PHP integer cents for the final amount; `P19C-T16` |
| A cancellation races the close | the close reads a consistent snapshot and excludes `cancelled`; the window is short; D-13 documents the after-close rule |
| An operator re-runs `--pay-only` on a paid period | the header status guard and the conditional update make it a no-op |
| A wrong settings value is discovered after a close | the period snapshot preserves what was used; a correction is a new month’s setting, not a rewrite |

---

## 8. Phase 19D — Admin visibility

**Goal.** Finance can see, filter, export and audit every Royalty award and every closed period from
the admin UI, without touching SQL.

**Builds on.** 19C (tag `p19c-money`).

**Ships alone because** it is read-only. If 19E and 19F were cancelled, an administrator could still
reconcile the stream by hand from the ledger tab, the period cards and the CSV; the money engine
would keep working from the CLI.

**Effort, suite.** 1.5 ideal days · floor **1,106** cases.

**Reference.** §10 (where each number appears in the reference UI), §12.7 (examples to display),
§13.2–§13.3 (row shapes), §14.11 (preview), §15.4 (audit), §16.6 (QA).

### Deliverables

**Code**

- `system/app/Controllers/AdminCommissionController.php`:
  - `TAB_ROYALTY = 'royalty'`; `tab()` recognises `royalty` and falls back to the package tab for
    anything else;
  - `royaltyContext()` — period filter (default: the latest period, or the open month), rank filter,
    status filter, search, pagination, period cards, stats, rank breakdown, current-month preview;
  - `export()` gains `?ledger=royalty` (CSV of the filtered awards);
  - the live feed gains a `commission-royalty-*` fragment set and an ETag scope that includes the
    tab and period.
- `system/app/views/admin/commissions/index.php` — a third nav pill **Royalty**; the Royalty branch
  renders the new partials.
- New partials:
  - `admin/commissions/_royalty_header.php` — title and the “one closed month, not per order” copy;
  - `admin/commissions/_royalty_stats.php` — paid total, this period, recipients, skipped, pending,
    average, top rank;
  - `admin/commissions/_royalty_periods.php` — one card/row per period: period, status, award/paid/
    skipped counts, totals, evaluated/paid times, a link that filters the ledger to it;
  - `admin/commissions/_royalty_rows.php` — one row per member: member, rank, GS, PS, quota, rates,
    amount, status/reason, ledger id, paid time;
  - `admin/commissions/_royalty_pagination.php` — a unique live-region name for the new tab.
- `system/app/Models/RoyaltyAward.php` / `RoyaltyPeriod.php` — the read methods built in 19C are
  used; no new write path.
- `system/app/Models/Report.php`:
  - `royaltySummary(string $from, string $to): array` — paid, pending, recipients, skipped, average,
    periods, payout ratio vs paid sales;
  - `royaltyByRank(string $from, string $to): array` — rank, recipients, paid, average;
  - `royaltyByPeriod(int $limit = 6): array` — period, status, award_count, paid_count,
    skipped_count, award_total, paid_total;
  - `royaltyPayoutRatio(string $from, string $to): array` — royalty paid ÷ paid sales, one decimal.
- `system/app/Services/ReportService.php` — `page()` adds the four keys; `EXPORTS` adds
  `'royalty' => 'Royalty'`; `export('royalty')` builds the period/rank CSV; `filename()` works.
- New report partials: `admin/reports/_royalty.php`, `_royalty_ranks.php`, `_royalty_periods.php`.
- `system/app/Controllers/AdminMemberController.php` and
  `system/app/views/admin/members/_royalty.php` — a member detail panel: current rank, requirement
  progress, this month’s PS/GS/quota, last six awards, all-time paid. Read-only.
- `system/app/Controllers/AdminDashboardController.php` and
  `admin/dashboard/_commerce.php` — one **Royalty paid** tile with all-time and this-month figures;
  no grid-class change (reuse the existing tile width).
- `system/app/Services/Live.php` — no new surface constant: the tab rides `admin.commissions`; the
  member panel rides `admin.members:{id}`; the dashboard rides `admin.dashboard`. The royalty close
  touches these surfaces (19C already calls `Live::touch()`).
- `system/app/helpers.php` — no new helper; `money()` and the existing badge classes are reused.

**Docs**

- `docs/admin-guide.md` — §16 gains “Reading the Royalty ledger”, “Period states”, “Why a row is
  skipped” and the CSV column list.
- `docs/phase-log.md` — 19D entry.

**Not yet**

- No member royalty tab or dashboard card (19E).
- No web cron, `.cmd`, doctor or operations panel (19F).
- No write path from any admin page: the close is CLI-only in 19D.

### Rules

1. **Read-only.** No controller, view or feed in 19D writes `royalty_periods`, `royalty_awards`,
   `ewallet_ledger` or `audit_log` (except the export audit row).
2. **The package and repeat tabs are unchanged.** The page gains one nav pill and one branch; the
   package tab’s regions, filters, columns and export keep their bytes. `P19D-T2` asserts it, and the
   smoke run repeats it over HTTP.
3. **The period is the primary filter.** Defaults to the latest final period; an explicit
   `?period=YYYY-MM` selects another; “current month” selects the open, unclosed preview (read-only,
   computed by `RoyaltyService::preview()`).
4. **The current-month preview is clearly labelled.** It is not a period row and never appears in the
   ledger totals; it says “If this month closed now”.
5. **Statuses and reasons render verbatim.** `pending` → “Awaiting payment”; `paid` → “Paid”;
   `skipped` → the stored reason (e.g. “Group sales below quota”). No invented explanation.
6. **Every amount is a peso amount with two decimals** (`money()`); GS/PS can be large, so the
   column uses the existing tabular style.
7. **The CSV reuses the screen’s filters** and has the columns in Appendix C.5; the export writes one
   audit row, `royalty.export`.
8. **Privacy.** The ledger shows member username/full name to admins only; no member identifying
   data appears on a public page.
9. **No new route.** `/admin/commissions?ledger=royalty`, `/admin/reports`, `/admin/members/{id}`,
   `/admin/dashboard` and the existing exports only.
10. **The dashboard KPI is all-time + this month**, not a live preview, so it cannot disagree with the
    ledger after a close.

### Test plan

| ID | Case | Expected |
|---|---|---|
| `P19D-T1` | Royalty tab renders | the nav pill, header, stats, period cards and rows render for a seeded period |
| `P19D-T2` | Package tab unchanged | its four regions, filters, columns, drawer and export are byte-identical |
| `P19D-T3` | Repeat tab unchanged | its five regions and escrow states are unchanged |
| `P19D-T4` | Period cards | status, counts and totals match the header row |
| `P19D-T5` | Stats | paid, pending, skipped, recipients and average match the rows |
| `P19D-T6` | Rank filter | only the selected rank’s rows |
| `P19D-T7` | Status filter | only pending/paid/skipped as selected |
| `P19D-T8` | Search | username/full name match; no other member’s rows |
| `P19D-T9` | Pagination | 10/25/50/100; page clamp |
| `P19D-T10` | Ledger CSV | header and rows per Appendix C.5; totals match the screen |
| `P19D-T11` | Export audit | one `royalty.export` row with filters |
| `P19D-T12` | Report cards | royalty summary, rank table and period table render |
| `P19D-T13` | Report CSV | `type=royalty` downloads the period/rank rows |
| `P19D-T14` | Member panel | rank, progress, MTD, last six awards, all-time paid |
| `P19D-T15` | Dashboard KPI | all-time and this-month figures match the ledger |
| `P19D-T16` | Live feed | the royalty fragment set is returned and the ETag changes when a period closes |
| `P19D-T17` | No write path | no admin POST/PUT touches a royalty or wallet table |
| `P19D-T18` | Permission | a member cannot reach `/admin/commissions` or the export |

### Manual gate (`QA19D-01 … QA19D-08`)

1. Open `/admin/commissions?ledger=royalty`; confirm the header, stats and period cards from 19C’s
   fixture.
2. Filter by period, rank and status; confirm the rows and the counters.
3. Download the CSV; open it; confirm the columns and two sample rows.
4. Open `/admin/reports`; confirm the royalty card, rank table and period table; download the CSV.
5. Open `/admin/members/{id}` for the fixture Director; confirm the panel and progress.
6. Open `/admin/dashboard`; confirm the Royalty tile.
7. Re-open the package and repeat tabs; confirm they are unchanged.
8. Confirm `/admin/audit` shows the export row and the settings change from 19B.

### Exit gate

- [ ] The universal gate (§2.4), floor **1,106**.
- [ ] `P19D-T1 … T18` pass.
- [ ] The screen, the CSV and the header row agree to the centavo.
- [ ] The package and repeat tabs are unchanged (named regression cases).
- [ ] The dashboard and member panel are read-only.
- [ ] `docs/admin-guide.md` §16 covers the ledger and the period states.

### Rollback

Remove the nav pill, the Royalty branch, the new partials, the report keys/export type, the member
panel and the dashboard tile. No data changes to reverse; the ledger and periods remain queryable by
SQL.

### Hand-off

19E may assume: the award/period read models and their filters exist; the admin ledger is the
reference for field meanings; the current-month preview is safe to reuse for the member’s “if
evaluated now” card. 19F may assume: the period cards and the dashboard KPI exist, so the operations
panel can link to them.

### Risks

| Risk | Mitigation |
|---|---|
| A third tab makes the page heavy | the tab is a query parameter; only the selected branch builds its data; one live surface |
| A large ledger slows pagination | `idx_royalty_period_status` and `(user_id, period)`; default 10 rows; period filter first |
| An admin expects the current-month preview in the totals | it is labelled and never enters the period totals; `P19D-T4` |
| The package tab regresses | named byte-level regression cases + smoke |

---

## 9. Phase 19E — Member visibility

**Goal.** A member can see their rank, what the next rank needs, this month’s group and personal
sales against the quota, and every Royalty award they have earned or been denied — with copy that
never promises money the engine will not pay.

**Builds on.** 19C (tag `p19c-money`).

**Ships alone because** it is read-only over an already-correct ledger. If 19D or 19F were cancelled,
members would still see the truth; the only loss would be the admin console and the scheduler.

**Effort, suite.** 1.5 ideal days · floor **1,122** cases.

**Reference.** §12.7 examples, §12.10 (what does not affect royalty), Appendix C (copy deck), the
reference §6.6 workflow and the “Your Royalty account” panel in §10 of
[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md).

### Deliverables

**Code**

- `system/app/Controllers/EarningsController.php`:
  - `TAB_ROYALTY = 'royalty'`; `tab()` recognises `package`, `repeat`, `royalty`; an unknown value
    falls back to `package`;
  - `royaltyContext(int $userId)` — `RoyaltyService::statusFor()` (rank, progress, MTD PS/GS, quota,
    rates, preview), `RoyaltyAward::pageForUser()` with period/rank/status filters, totals, and the
    all-time figure;
  - `export()` gains `?ledger=royalty` (the member’s own rows only).
- `system/app/views/member/earnings.php` — a third nav pill **Royalty**, the royalty branch and the
  new partials.
- New partials:
  - `member/earnings/_royalty_header.php` — the title and the “paid once a month after the month
    closes” explanation;
  - `member/earnings/_royalty_rank.php` — the rank card: current rank, next-rank requirement and
    progress, directs, ranked team counts, this month’s PS/GS/quota, “if this month closed now”;
  - `member/earnings/_royalty_stats.php` — paid all-time, this period, pending, skipped, last paid;
  - `member/earnings/_royalty_rows.php` — one row per period: period, rank, GS, PS, quota, rates,
    amount, status/reason, paid date;
  - `member/earnings/_royalty_pagination.php` — a unique live-region name.
- `system/app/Controllers/DashboardController.php` and
  `member/dashboard/_royalty.php` — a Royalty tile: rank badge, MTD group sales vs quota, progress
  bar, paid all-time, link to `/earnings?ledger=royalty`. Hidden when the stream is off **and** the
  member has no history; otherwise it shows the history link and an “off” note.
- `system/app/views/member/earnings.php` and the dashboard feed — the new regions and feed payload.
- `system/app/views/admin/members/_royalty.php` was 19D; 19E does **not** touch it.
- No new route: `/earnings?ledger=royalty`, `/earnings/export?ledger=royalty`, `/dashboard`.

**Docs**

- `docs/admin-guide.md` §16 gains a “What members see” subsection (the copy rules and the Leader
  explanation).
- `docs/phase-log.md` — 19E entry.

**Not yet**

- No web cron, `.cmd`, doctor or operations panel (19F).
- No genealogy rank badges, no rank filter on `/admin/members`, no annual statements.

### Rules

1. **Own rows only.** Every query is scoped with `WHERE user_id = ?`; `?user_id=` is ignored. A
   member can never read another member’s award, GS or rank progress. `P19E-T6` asserts it.
2. **Leader is explained, not hidden.** A Leader sees “Leader is a rank, not a royalty rank; the first
   rank that pays is Supervisor” and the progress toward Supervisor. A Member sees the direct-count
   progress to Leader.
3. **The preview is labelled “if this month closed now”.** It uses `RoyaltyService::preview()` for the
   open month and the member’s current MTD figures; it is never presented as paid.
4. **Statuses are explicit.** `pending` → “Awaiting payment”; `paid` → “Paid”; `skipped` → the
   stored reason. A below-quota row says “Group sales below the ₱X quota” and shows the GS/PS it was
   judged on.
5. **The stream-off rule.** When `royalty_enabled = 0`: past history stays visible, the promise
   surfaces (the “if closed now” amount and the next-rank progress call to action) are hidden or
   explicitly marked “Royalty is currently off”, and the dashboard tile hides when there is no
   history.
6. **Payment pause.** When `royalty_pay_enabled = 0` and the member has a pending award, the row
   says “Payment is paused; the award stays pending” — never “paid”.
7. **Copy rules.** Member-facing text says **team** / **Team chain**, never “downline”, “sponsor
   chain” or “subtree”. It says **Royalty**, not “royalty commission”. It states the month close, not
   the purchase event. The exact strings are in Appendix C.
8. **No new route, no new live surface.** The tab uses `member.earnings:{id}`; the dashboard uses
   `member.dashboard:{id}`; both are already bumped by the close’s `Live::touch()` calls.
9. **Phone-safe.** The three pills wrap at 390 px (the same `.commission-tabs` fix as Phase 17E); the
   rank card stacks; no horizontal scroll. `P19E-T14` and the smoke walk at 390 px cover it.
10. **No money is written by any member surface.**

### Test plan

| ID | Case | Expected |
|---|---|---|
| `P19E-T1` | Royalty tab renders | header, rank card, stats, rows for the fixture member |
| `P19E-T2` | Package tab unchanged | byte-level regression |
| `P19E-T3` | Repeat tab unchanged | byte-level regression |
| `P19E-T4` | Rank card: Supervisor | rank, 10/10 progress, quota 500,000, preview exact |
| `P19E-T5` | Rank card: Leader | “not a paying rank”, progress to Supervisor, no quota preview |
| `P19E-T6` | Scoping | `?user_id=other` still returns only the session member’s rows |
| `P19E-T7` | History rows | period, rank, GS, PS, quota, rates, amount, status, paid date |
| `P19E-T8` | Skipped row | reason and the GS/PS it was judged on |
| `P19E-T9` | Pending row | “Awaiting payment”, no paid date |
| `P19E-T10` | Stream off | history visible; promise surfaces hidden/marked |
| `P19E-T11` | Payment paused | pending copy, not paid |
| `P19E-T12` | CSV | own rows, columns per Appendix C.6 |
| `P19E-T13` | Dashboard tile | rank, MTD/quota, paid all-time, link |
| `P19E-T14` | 390 px | pills wrap; no page horizontal scroll |
| `P19E-T15` | Live revision | a close bumps the member’s earnings/dashboard surfaces |
| `P19E-T16` | No write | rendering, filtering, export write no royalty/wallet row |

### Manual gate (`QA19E-01 … QA19E-09`)

1. Sign in as the fixture Supervisor; open `/earnings?ledger=royalty`; confirm the rank card and the
   rows.
2. Confirm the preview equals the amount 19C paid for the closed month (within the rounding rule).
3. Sign in as a Leader; confirm the “not a paying rank” copy and the progress to Supervisor.
4. Sign in as a Member with no directs; confirm the Leader progress copy.
5. Cancel an order before a fresh close and confirm the member’s GS drops; close and confirm the
   skipped/paid result.
6. Set `royalty_enabled = 0`; confirm history stays and the promise surfaces change.
7. Set `royalty_pay_enabled = 0`; evaluate a month; confirm “pending / payment paused”.
8. Download the member CSV; confirm only that member’s rows.
9. Check `/dashboard` at 390 px; confirm the tile and no horizontal scroll.

### Exit gate

- [ ] The universal gate (§2.4), floor **1,122**.
- [ ] `P19E-T1 … T16` pass.
- [ ] The member preview equals the engine’s result for the same month.
- [ ] No member surface can read another member’s row.
- [ ] The copy rules hold (team/Team chain; month close; Leader not paid).
- [ ] The package and repeat tabs are unchanged.

### Rollback

Remove the third pill, the royalty branch, the new partials, the dashboard tile and the export branch.
No data changes to reverse.

### Hand-off

19F may assume: member pages render the current and historical truth; the dashboard tile and earnings
tab are the places to link from the operations panel’s “what members see” note. 19G may assume the copy
deck is implemented verbatim.

### Risks

| Risk | Mitigation |
|---|---|
| The preview promises money the engine will not pay | the preview is the engine’s own `preview()`; the label says “if this month closed now”; the copy says the account must be active |
| A Leader thinks they were underpaid | explicit “Leader is not a paying rank” card and the Supervisor progress |
| A skipped row looks like a bug | the reason and the GS/PS are shown; the explanation links to the quota rule |
| The third tab breaks the phone layout | the Phase 17E wrap fix is reused; a 390 px smoke check |

---

## 10. Phase 19F — Operations

**Goal.** The close runs unattended on the first of every month from cron, URL and Windows Task
Scheduler, with a doctor, an operations panel, logs, locks and a runbook — and an operator can tell
in one screen whether the month is closed, evaluated, paid or stuck.

**Builds on.** 19C (tag `p19c-money`).

**Ships alone because** it adds no money rule. If 19G were cancelled, the stream would still be
operable: the job, the doctor, the panel and the docs are the operations surface.

**Effort, suite.** 1.5 ideal days · floor **1,140** cases.

**Reference.** §14.10–§14.12 (job, preview, reconcile), §14.13 (cancellation), §15.4 (audit), §16.5 (cron cases),
Appendix D (SQL), the Phase 17F pattern in
[`bin/unilevel_month_close.php`](../system/bin/unilevel_month_close.php).

### Deliverables

**Code**

- `system/public/cron/royalty_month_close.php` — the web entry:
  - `CronAccess::allowed()` (loopback trusted; remote needs `CRON_KEY`);
  - no session, no cookies; `Content-Type: text/plain; charset=utf-8`; `X-Robots-Tag: noindex`;
  - accepts `?period=`, `?dry-run=`, `?evaluate-only=`, `?pay-only=`, `?limit=`, `?json=`,
    `?doctor=`;
  - delegates to the same runner functions as the CLI (one implementation, three entry points).
- `system/bin/royalty_month_close.cmd` — Windows Task Scheduler wrapper, same flags, log under
  `system/storage/logs/`.
- `system/bin/royalty_month_close.php` — 19C’s runner gains:
  - `--doctor` — a report: PHP/MySQL clocks, timezone, tables, settings, `royalty_ledger_since`,
    `royalty_enabled`, `royalty_pay_enabled`, last paid period, pending periods, evaluated-unpaid
    periods, lock state, wallet reconcile, counts;
  - the `--json` payload grows the doctor/operations numbers (the flag itself shipped in 19C); the
    `--max-batches` flag from 19C is used in the documented schedule to bound a single run;
  - the log line is one line per run: `closed=<n> periods=<list> award=<n> paid=<n>
    amount=<sum> skipped=<n> status=<final|evaluated> paused=<0|1>`.
- `system/app/Controllers/AdminCommissionController.php` and
  `system/app/views/admin/commissions/_royalty_ops.php` — an **operations panel** on the Royalty tab:
  - open month (not closable yet), its preview totals;
  - last final period;
  - pending periods (should be none);
  - evaluated-but-unpaid periods and a link to the runbook command;
  - switches’ states and the last close time;
  - no “Run close” button (the close is a cron/CLI action; an HTTP button would need CSRF, a
    long-running request and a retry story).
- `system/app/Services/HealthService.php` — one check: `royalty` tables exist, settings present,
  no evaluated period older than 48 hours (warning, not failure).
- `system/app/Services/Live.php` — the close touches `admin.commissions`, `admin.dashboard`,
  `admin.reports` if it is a surface, and the paid members’ `member.earnings:{id}` /
  `member.dashboard:{id}`. Touch in batches after each committed payment batch, not inside the
  wallet transaction.

**Docs**

- `docs/cron.md` — a new **royalty_month_close** section: what it does, local and live commands,
  flags, logs, locks, exit codes, failure modes, QA.
- `docs/runbook.md` — the monthly close drill: run the unilevel close, run `--doctor`, run
  `--dry-run`, run the real job, check the panel, check `wallet_reconcile.php`, what to do after a
  partial payment, what never to do (no SQL edits to a closed award).
- `docs/admin-guide.md` — §16 gains the operations panel and the period states.
- `docs/deploy.md` — the two cron entries and the enable-at-month-boundary recommendation.
- `docs/phase-log.md` — 19F entry.
- `system/README.md` — the job list and the settings count.

**Not yet**

- No release sign-off, no `APP_PHASE` change (19G).
- No automatic retry daemon; the next scheduled run is the retry.

### Rules

1. **Access.** The web entry is loopback-only unless `CRON_KEY` is configured and supplied; the CLI
   is always allowed. The job never requires a session and never renders HTML.
2. **One run at a time.** `CronLock('royalty_month_close')`; a second run prints `[SKIP ] busy`,
   exits 0 and writes nothing.
3. **Default run.** Every `evaluated` period is resumed first (oldest first), then every pending
   completed month is closed (oldest first). Each period is evaluated and paid before the next; a
   failure stops the run and leaves the later months open. A partial payment from an earlier run is
   therefore healed by the next scheduled run without operator action. A catch-up produces the same
   awards as on-time runs because each period uses its own cutoff.
4. **Refusals.** The current month and any future month are refused (`exit 1`, no writes); a `paid`
   or `skipped` period is a no-op (`exit 0`); `--pay-only` on an absent period is an error; a
   `--period` that is not `YYYY-MM` is an error.
5. **No writes in `--dry-run`.** Preview only; the wallet, the two tables and the audit log are
   untouched. `P19F-T8` asserts it.
6. **The doctor is honest.** It exits 1 if a table is missing, a setting is absent/invalid, the
   clocks disagree by more than 2 seconds, the timezone is not Asia/Manila, or a `paid` period has a
   pending award. A paused payment switch is a warning, not a failure.
7. **Exit codes.** 0 = success, no-op or busy; 1 = processing/validation error; 2 = environment
   (DB down, storage not writable, missing tables). The web entry maps the same codes to HTTP 200/500
   for the body and keeps the exit code in the JSON.
8. **Logs.** One line per run under `system/storage/logs/royalty_month_close-YYYY-MM.log`; the log is
   outside `public/`. A log write failure warns on stderr but does not fail the run.
9. **The operations panel is read-only.** It shows state and commands; it never triggers a close.
10. **The schedule is documented, not hidden.** The recommended entries are:
    ```
    15 0 1 * *  php /path/system/bin/unilevel_month_close.php
    30 0 1 * *  php /path/system/bin/royalty_month_close.php --max-batches=20
    ```
    The 15-minute gap is operational tidiness; the two jobs are independent.

### Test plan

| ID | Case | Expected |
|---|---|---|
| `P19F-T1` | Web entry, remote without key | 403, no work |
| `P19F-T2` | Web entry, loopback | 200, the same numbers as the CLI |
| `P19F-T3` | Web entry with `CRON_KEY` | allowed; wrong key refused |
| `P19F-T4` | `.cmd` exists | the Windows wrapper runs the CLI with the same flags |
| `P19F-T5` | Doctor, clean | all lines, exit 0 |
| `P19F-T6` | Doctor, missing table | exit 1, names the table |
| `P19F-T7` | Doctor, clock skew | exit 1 when the difference exceeds 2 s |
| `P19F-T8` | `--dry-run` | no period, award, wallet or audit rows |
| `P19F-T9` | Default catch-up | two months, oldest first, one log line per run |
| `P19F-T10` | Lock | second run `[SKIP ]`, exit 0 |
| `P19F-T11` | `--max-batches` | a large period leaves `evaluated`, exits 0, resumes next run |
| `P19F-T12` | Log line | period list, counts, totals, paused flag |
| `P19F-T13` | Operations panel | open month, last period, pending, evaluated-unpaid, switches |
| `P19F-T14` | Health check | royalty tables/settings present; stale evaluated warning |
| `P19F-T15` | Exit codes | 0/1/2 as documented |
| `P19F-T16` | `docs/cron.md` | the job, flags, logs and exit codes are documented |

### Manual gate (`QA19F-01 … QA19F-08`)

1. Run `php bin/royalty_month_close.php --doctor`; confirm clocks, timezone, tables, settings, last
   paid, pending, lock, wallet.
2. Run the web entry from loopback with `?doctor=1`; confirm the same report.
3. Run the web entry from the LAN address without a key; confirm 403.
4. Run `--dry-run`; confirm no rows changed (record the counts before/after).
5. Run the real job for a fresh month; confirm the log line and the panel.
6. Run it again; confirm `[SKIP ]`/“nothing to close”, exit 0.
7. Hold the lock with a second process; confirm the first run is skipped cleanly.
8. Add both cron entries to the runbook and a test Task Scheduler task; confirm the `.cmd` runs.

### Exit gate

- [ ] The universal gate (§2.4), floor **1,140**.
- [ ] `P19F-T1 … T16` pass.
- [ ] The web entry, CLI and `.cmd` produce the same result.
- [ ] The doctor detects a missing table, a clock skew and a stale evaluated period.
- [ ] No write in `--dry-run`.
- [ ] `docs/cron.md` and `docs/runbook.md` describe the schedule and the failure drill.

### Rollback

Remove the web entry, the `.cmd`, the doctor branch and the operations panel; keep the CLI runner
from 19C. Remove the cron entry. The money and the tables are unaffected.

### Hand-off

19G may assume: the job is schedulable and observable, the doctor is green, the operations panel is
the first place an operator looks, and the runbook’s close drill is executable.

### Risks

| Risk | Mitigation |
|---|---|
| The web entry is reachable without a key | `CronAccess` loopback/CRON_KEY rules; `P19F-T1/T3` |
| A month is too large for one run | `--max-batches` leaves `evaluated`; the next run resumes |
| The doctor reports green when the close is stuck | the doctor checks evaluated-unpaid age and pending counts; the health check warns |
| Two cron entries overlap | the lock makes the second a no-op |
| A log write fails on a read-only host | the run continues; stderr warns |

---

## 11. Phase 19G — Release

**Goal.** The build reports Phase 19, the documentation is consistent, the QA pack is complete and
signed, and the acceptance run reproduces every reference example.

**Builds on.** all (tag `p19f-operations`).

**Ships alone because** it changes only `APP_PHASE`, tests and documentation. Reverting it leaves a
correct, scheduled feature; it is the label and the proof, not the money.

**Effort, suite.** 1.5 ideal days · floor **1,140** cases (no new money cases; the release case set).

**Reference.** all of Part III; §16.6 (QA pack); Appendix H (gate record).

### Deliverables

**Code**

- `system/app/config.php` — `APP_PHASE` becomes **19**; the comment names Phase 19 and the plan.
- `system/tests/Cases/HardeningTest.php` — `assert_same(19, APP_PHASE)`.
- No behaviour change anywhere else.

**Docs**

- `docs/phase-log.md` — 19G entry, the seven phase entries, tags, counts, deviations.
- `docs/qa/phase-19-royalty.md` — sections A–G filled, with the exact checks and expected numbers.
- `docs/qa/README.md` and `docs/qa/sign-off-sheet.md` — one Phase 19 row, finalised.
- `docs/admin-guide.md` — §16 complete.
- `docs/runbook.md` — the close drill and the incident playbook.
- `docs/cron.md` — the new job.
- `docs/deploy.md` — migration `023`, the two cron entries, the enable-at-month-boundary note.
- `docs/install-reset.md` — 33 / 73, migration 001–023.
- `system/README.md` — the feature list, counts, job list.
- `dmp/plan-ecowealth-royalty.md` — this plan; update the status line to **implemented** and fill
  Appendix H.

**Not yet / out of scope**

- No retroactive payout, no rank history, no Chairman, no QA gate, no reversal, no cap.

### Rules

1. **Only `APP_PHASE`, tests and documentation.** No money, schema, setting, job or UI change in
   19G. If a defect is found, it is fixed in the owning phase’s file and recorded as a deviation.
2. **The cold read.** A reviewer who did not write the feature reads §12 and the admin/member pages
   and lists every claim that does not match. Every finding is fixed in the shared docs (not only in
   the QA section) and named in the phase log.
3. **The acceptance run.** The Appendix B scenarios are executed on a scratch database with the
   reference settings and the observed amounts are recorded in the QA pack.
4. **No stale counts.** Every count in a document (tables, settings, cases, smoke checks, migration
   range, job list, `APP_PHASE`) is checked against the build.
5. **The suite and the smoke walk are green at the final counts**, and the QA sign-off names the
   tester, the date and the evidence files.

### Test plan

| ID | Case | Expected |
|---|---|---|
| `P19G-T1` | `APP_PHASE` | 19; the config comment names Phase 19 |
| `P19G-T2` | Docs counts | 33 tables, 73 settings, migrations 001–023, jobs list |
| `P19G-T3` | QA pack | sections A–G present; every `QA19X-nn` has an expected value and an evidence file |
| `P19G-T4` | No stale claim | the cold-read checklist is empty or the remaining items are recorded |
| `P19G-T5` | Routes untouched | `routes.php` diff is empty; `ParityTest` green |
| `P19G-T6` | Full suite | 1,140+ / 1,140+ |
| `P19G-T7` | Smoke | 248 / 248 (229 + 19) |
| `P19G-T8` | Acceptance | the Appendix B scenarios match to the centavo |

### Manual gate (`QA19G-01 … QA19G-06`)

1. `grep APP_PHASE system/app/config.php` → 19.
2. `php tests/run.php` → green at or above **1,140**.
3. `powershell -File tests/smoke.ps1` → **248 / 248**.
4. Run the Appendix B scenarios on a scratch database with reference settings; record the amounts.
5. Walk `/admin/settings`, `/admin/members`, `/admin/commissions?ledger=royalty`, `/admin/reports`,
   `/admin/audit`, `/earnings?ledger=royalty`, `/dashboard` as an admin and as a member; confirm no
   broken link, no “0” placeholder where a value should be, and no console error.
6. Cold-read §12 and the admin guide; write the findings file.

### Exit gate

- [ ] The universal gate (§2.4), final suite at or above **1,140**.
- [ ] Smoke **248 / 248**.
- [ ] `APP_PHASE = 19`.
- [ ] The QA pack is signed; evidence is under `docs/qa/evidence/phase-19/`.
- [ ] Appendix H is filled; the deviation log is current.
- [ ] Every stale count is fixed.

### Rollback

Set `APP_PHASE` back to 17 and revert the documentation commit. The feature stays live and scheduled;
the phase constant is the only behaviour change.

### Hand-off

The feature is released. The next compensation stream (Harvest or Indirect, if the business chooses)
starts from `p19g-release` and must not reuse `royalty_periods`/`royalty_awards` for a different
grain. A rank-history or QA-gate proposal is a new plan, not a patch to this one.

### Risks

| Risk | Mitigation |
|---|---|
| A doc count is stale | the cold read checks every number; `P19G-T2` |
| The QA pack is filled from memory | evidence files per check; the acceptance run is on a scratch database |
| `APP_PHASE` breaks a menu | no new routes/menu items; `HardeningTest` and the smoke walk |
| A late defect tempts a “quick” fix in 19G | 19G may not change money; the fix goes to the owning phase with a deviation row |

---

# Part III — The shared contract

## 12. Specification (normative)

This section is the contract. Where it diverges from the simulator, the divergence is stated and
justified; [`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §6 remains the authority for the
**mechanics** (the rank ladder, the quota gate, the GS/PS formula, one rounding, no stacking, no
carry-over), not for the app’s tables, periods or account model.

Each subsection names the phase that implements it. A phase may not implement a rule from a later
subsection.

### 12.1 Vocabulary and symbols

| Term | Meaning | Phase |
|---|---|---|
| **member account** | a `users` row with `role = 'member'`. Staff accounts (`admin`, `superadmin`) are not members, are never ranked, never counted and never paid | 19B |
| **direct** | a member account whose `sponsor_id` is this account | 19B |
| **team / downline** | every member account reachable through `sponsor_id` links below an account, at any depth, regardless of status | 19B |
| **rank** | one of Member (0), Leader (1), Supervisor (2), Manager (3), Director (4), derived from the tree | 19B |
| **paying rank** | Supervisor, Manager or Director — the only ranks Royalty pays | 19B |
| **PS** | personal sales: the member’s own paid order line totals in the period | 19C |
| **GS** | group sales: PS plus the PS of every member below the member, any depth | 19C |
| **quota** | the minimum GS for a rank to pay at all, in pesos | 19C |
| **period** | a calendar month in `Asia/Manila`, written `YYYY-MM` | 19C |
| **close** | the evaluate-then-pay job for one completed period | 19C |
| **cutoff** | the period end used to reconstruct the tree for rank evaluation (`users.created_at < end`) | 19B/19C |
| **award** | one `royalty_awards` row: one member, one period, one rank decision | 19C |
| **pending** | an award that is evaluated but not yet in the wallet | 19C |
| **paid** | an award whose wallet credit exists (`ledger_id` set) | 19C |
| **skipped** | an award row for a due member that paid nothing, with a stable reason | 19C |
| **evaluated** | a period whose award rows exist but whose payment is not complete | 19C |
| **final** | a period with `status = 'paid'` or `'skipped'`; immutable | 19C |
| **baseline** | `royalty_ledger_since`; the first payable month is the first full month after it | 19C |
| **preview** | a computation for a period (open or closed) that writes nothing | 19C |
| **paused** | `royalty_pay_enabled = 0`; evaluation continues, wallet credits wait | 19C |
| **stacking** | paying more than one rank’s rates to the same member; **never** happens (D-11) | 19C |

### 12.2 The rank ladder

Ranks are self-prerequisite: a Manager must already be a Supervisor, a Supervisor a Leader, a Leader
a member with directs. That makes the ladder cumulative — a Director satisfies “Leader or above”,
“Supervisor or above” and “Manager or above” — so counting rank holders is counting the **base
predicate**, not five independent tests.

| Rank | Level | Requirement (default in brackets) | Royalty-eligible? | Monthly group quota | Group % | Personal % |
|---|---|---:|---|---:|---:|---:|
| Member | 0 | automatic on registration | no | — | — | — |
| Leader | 1 | at least `royalty_leader_directs` direct members (10) | **no** | — | — | — |
| Supervisor | 2 | Leader **and** at least `royalty_supervisor_leaders` downline members who are Leader or above (10) | yes | ₱500,000 | 3% | 5% |
| Manager | 3 | Supervisor **and** at least `royalty_manager_supervisors` downline members who are Supervisor or above (10) | yes | ₱1,000,000 | 5% | 10% |
| Director | 4 | Manager **and** at least `royalty_director_managers` downline members who are Manager or above (10) | yes | ₱5,000,000 | 10% | 15% |

Rules:

1. **Any depth.** “Downline” means every descendant, at any sponsor depth. It is **not** limited to
   ten levels. A Leader twelve levels below a candidate counts toward that candidate’s Supervisor
   requirement.
2. **The counted members exclude the candidate.** The requirement is ten **other** rank holders. The
   derivation subtracts one from the subtree count that includes the candidate (`−1` in §12.3).
3. **Counted members need not be active, packaged, purchasing or Harvest-qualified.** A suspended
   Leader still counts for their upline’s Supervisor requirement. Ranks count people, not production
   (reference §6.2, §11.2 #13).
4. **The candidate’s own account status gates the payout, not the rank.** A pending member can hold a
   rank and see it; 19C writes a `skipped` award with `membership is not active` if they are not
   eligible at the close.
5. **The tree is the `users.sponsor_id` tree, not the Harvest queue.** There is no Harvest in the
   app.
6. **A member cannot lose a rank through normal activity** because the tree only grows. Raising a
   threshold, deleting a branch or corrupting a sponsor link can change a rank at the next
   evaluation; ranks are derived, not stored (D-18).
7. **The company account is never ranked.** It is an `admin`, not a member. A member directly under
   the company has no member upline for Royalty purposes; their volume rolls up to nobody.
8. **Other staff accounts are not ranked and are invisible to the rank pass.** If a staff account
   sits between two member accounts (an administrative correction), the lower member is a root for
   Royalty and its volume reaches no member upline.

### 12.3 How ranks are derived

Let `d(i)` be the number of direct members of `i`, and let `L(i)`, `S(i)`, `M(i)`, `D(i)` be the
booleans “i is at least a Leader/Supervisor/Manager/Director”. For every member account, in a
bottom-up pass over the tree:

```
A(i) = number of direct members of i                      # children in the member tree

L(i) = A(i) >= leader_directs
SL(i) = L(i) + Σ over direct children c of SL(c)          # Leader-or-above subtree count incl. i

S(i) = L(i) and (SL(i) − 1) >= supervisor_leaders
SS(i) = S(i) + Σ over direct children c of SS(c)          # Supervisor-or-above subtree count incl. i

M(i) = S(i) and (SS(i) − 1) >= manager_supervisors
SM(i) = M(i) + Σ over direct children c of SM(c)          # Manager-or-above subtree count incl. i

D(i) = M(i) and (SM(i) − 1) >= director_managers

rank(i) = D(i) ? 4 : M(i) ? 3 : S(i) ? 2 : L(i) ? 1 : 0
```

- The `− 1` removes the candidate themselves. Without it a Supervisor who is also a Leader would
  count their own Leader predicate toward their Supervisor requirement.
- `SL(i)` is a count of **Leader-or-above** members because every Supervisor, Manager and Director
  satisfies `L`. `SS(i)` counts Supervisor-or-above; `SM(i)` counts Manager-or-above. This is exactly
  the cumulative property of §12.2.
- The pass is **one query** (`users` where `role = 'member'`, optional `created_at < cutoff`,
  ordered by `id`) and **one in-memory pass**. The pass builds a children map and visits roots
  iteratively; a visited set makes a corrupt cycle terminate without double counting.
- A member whose `sponsor_id` is `NULL`, `0`, a staff id, or a missing id is a **root**. A
  self-sponsored member (`sponsor_id = id`) and a member in a cycle (A→B→A) are corrupt: they are
  excluded from the pass, are not ranked, and contribute no counts or volume. A cycle is unreachable
  from all roots; a self-sponsor is excluded explicitly. `RoyaltyService::reconcile()` reports the
  count of such accounts as a warning, not a money mismatch.
- `map(null)` loads every current member account (live preview). `map($asOf)` loads only accounts
  with `created_at < $asOf` (a close), so a registration after the period end cannot change a past
  rank.
- **Complexity:** O(n) time and memory, where n is the number of member accounts. There is no
  per-member query and no recursion depth limit.

**Minimum trees.** Assuming the most compact placement (each required rank holder directly below the
candidate, each with the minimum structure), the smallest subtree that can hold each rank is:

| Rank | Formula with the four thresholds | With 10/10/10/10 | Shape |
|---|---|---:|---|
| Leader | `1 + leader_directs` | 11 | self + 10 directs |
| Supervisor | `1 + supervisor_leaders × (1 + leader_directs)` | 111 | self + 10 Leaders × 11 |
| Manager | `1 + manager_supervisors × (1 + supervisor_leaders × (1 + leader_directs))` | 1,111 | self + 10 Supervisors × 111 |
| Director | `1 + director_managers × (1 + manager_supervisors × (1 + supervisor_leaders × (1 + leader_directs)))` | 11,111 | self + 10 Managers × 1,111 |

These are minima, not requirements: the ten rank holders may sit anywhere below. A tree that puts
ten Leaders under a Supervisor but spreads them across ten different branches is just as valid.

### 12.4 Sales volume: PS and GS

For a period `P` with half-open bounds `[start, end)` in `Asia/Manila`:

```
PS(m, P) = Σ shop_order_items.line_total
           over shop_orders o
             join shop_order_items oi on oi.order_id = o.id
           where o.user_id = m
             and o.paid_at >= start and o.paid_at < end
             and o.status <> 'cancelled'

GS(m, P) = PS(m, P) + Σ PS(d, P) for every member descendant d of m, any depth
```

Rules:

1. **The order line total is the unit.** `line_total = quantity × unit_price`, already snapshotted at
   placement. Shipping is on the order and is never included. There are no taxes, discounts, fees or
   partial refunds in this build.
2. **`paid_at` decides the month**, not `created_at`, not the delivery date. A manual GCash order
   placed on 30 September but verified on 2 October counts in **October**, because that is when the
   company received the money.
3. **Only paid orders count.** A `pending`, `payment_review`, `payment_failed` or unpaid `cancelled`
   order has no `paid_at` and contributes nothing.
4. **A cancelled order never counts.** The close query excludes `status = 'cancelled'`. A
   cancellation **before** the close therefore lowers GS/PS; a cancellation **after** the close does
   not rewrite the closed period (D-13).
5. **Package activations do not count.** `activations.amount` is a membership purchase, not a shop
   order line; the reference explicitly says registration does not add to `sales` (§2.4). A package
   upgrade, registration code, e-wallet top-up, transfer, payout fee or admin adjustment is not
   sales either.
6. **The member’s own purchases are inside GS and are paid twice.** PS is added to GS by definition,
   and the personal percentage is applied to PS again. A Director’s own purchase therefore earns
   `10% + 15% = 25%`; a downline purchase earns the group rate only (10%). This is deliberate
   (reference §6.5, §11.2 #12).
7. **The quota can be met with the member’s own purchases.** Because GS includes PS, a Supervisor who
   personally buys ₱500,000 in the month meets the quota and earns `3% × 500,000 + 5% × 500,000 =
   ₱40,000`. The reference calls the quota self-fundable; §15.3 discusses the abuse implications.
8. **Every member descendant’s volume counts**, regardless of that descendant’s rank, status, package
   or whether they are active. The volume is historical money, not a current qualification.
9. **Staff purchases are excluded.** A purchase by an `admin`/`superadmin` account is not member
   sales and is not in any member’s GS. This follows D-05 (only member accounts are in the rank
   tree).
10. **No carry-over.** GS/PS are period-scoped. Unused group volume disappears at the period
    boundary; the next period starts at zero (reference §11.2 #16).
11. **No cap.** There is no per-member or per-period maximum GS or payout (reference §9.2). The
    quotas and configured rates are the only controls.

### 12.5 The payout formula

For a member `m` at the close of period `P`:

```
rank      = rank(m) as of the period end
eligible  = royalty_enabled
            and rank >= 2
            and users.role = 'member'
            and users.is_active = 1
            and users.member_status = 'active'

if not eligible:                     amount = 0, no award or skipped with a reason
if GS(m,P) < quota(rank):            amount = 0, skipped "group sales below quota"
else:                                amount = round( group_pct(rank) × GS + personal_pct(rank) × PS , 2 )
```

- **All-or-nothing.** Missing the quota by one centavo zeroes the whole payout, including the personal
  percentage. A Supervisor with GS ₱499,999.99 earns ₱0, not ₱14,999.9997 (reference §6.5,
  §11.2 #10).
- **Exactly at the quota pays.** `GS = quota` is a pass; the comparison is `>=` in integer cents.
- **One rounding** on the combined amount. The group and personal components are **not** rounded
  separately (D-10). The arithmetic is done in integer centavos and rounded half-up once (§12.8).
- **One rank, one triple.** The rank selects exactly one `(quota, group_pct, personal_pct)`. A
  Director is not paid the Supervisor and Manager rates as well (D-11).
- **The payout uses the rank held at the period end**, not the rank at the moment the job happened to
  run. A member who ranks up on the last day of the month is evaluated at the new rank for the whole
  month. A member who ranks up on the first day of the next month is not (reference §6.5, §11.2 #14).
- **No proration.** A member who was a Supervisor for one day of the month is evaluated as a
  Supervisor for the whole month if they hold the rank at the end.
- **No carry-over or catch-up.** A month with no purchases pays ₱0 to every member; a missed quota is
  not recovered later.
- **Leader earns nothing** even with ₱10,000,000 of GS; Leader only unlocks the Supervisor path
  (reference §11.2 #9).
- **A zero-rate configuration** writes a `skipped` row with reason `zero amount` if the member is
  otherwise eligible and at quota. Validation discourages all-zero rates (§12.9), but a period
  evaluated after a settings edit must not pay ₱0 silently.
- **Amounts are never negative.** A negative GS/PS is impossible from the query; if corrupt data
  produces one, clamp to 0 and log.

### 12.6 The month close

#### 12.6.1 The period and the cutoff

- A period is a calendar month in `Asia/Manila`: `periodOf('2026-11-15 10:00:00') = '2026-11'`.
- Bounds are half-open: `['2026-11-01 00:00:00', '2026-12-01 00:00:00')` in `APP_TIMEZONE`.
- **Only a completed month can be closed.** The current month and any future month are refused.
- The **rank cutoff** is the period end. Ranks are computed from member accounts with
  `users.created_at < end` (D-06). This makes a close **reproducible**: running on the 1st or on the
  15th produces the same ranks for the same month.
- The **volume window** is `[start, end)` on `shop_orders.paid_at`. Orders paid after the period end
  are next month’s volume.
- **Eligibility uses the account state at the close**, not a historical membership state (there is no
  membership history table). A member suspended before the close is skipped; a member activated
  before the close is eligible. §12.11 records this as a known limitation.

#### 12.6.2 Period states

```
(none) ──evaluate (stream on)──▶ evaluated ──pay all pending──▶ paid
   │
   └────evaluate (stream off)───▶ skipped          (final, no awards)

evaluated ──pay (payments paused)──▶ evaluated       (pending rows remain)
evaluated ──pay (some batches)─────▶ evaluated       (partial payment, resumable)
evaluated ──pay (none pending)─────▶ paid            (money or zero)
```

| Status | Meaning | Money? | Mutable? |
|---|---|---|---|
| `evaluated` | award rows exist; at least one is `pending`, or the last batch is paid and finalisation is pending | some may already be paid | only payment fields/counters; never amounts/ranks/rates |
| `paid` | every award is `paid` or `skipped`; final | yes, `paid_total` | **no** |
| `skipped` | the stream was off at evaluation; no awards; final | no | **no** |

- A period with only `skipped` awards (all below quota, all ineligible, or no Supervisor+) is still
  `paid` with `paid_total = 0`; “paid” means final, and the UI shows the amount as ₱0.
- There is **no reopen**, no `void`, no `recalculate`. A wrong close is a finance correction outside
  the app, recorded in the runbook, never a silent second evaluation.

#### 12.6.3 Evaluation

`RoyaltyService::evaluate($period, $actorId)`:

1. Validate the period format; refuse the current/future month; refuse if a header already exists
   (a second evaluation is a no-op/refused).
2. Read the settings ladder **once** and snapshot it (thresholds, quotas, rates).
3. If `royalty_enabled = 0`: write the header with `status='skipped'`, `enabled=0`,
   `note='royalty stream off'`, zero counts, in one transaction; done.
4. Otherwise compute:
   - `$map = RoyaltyRank::map($periodEnd)`;
   - the period volumes `[user_id => cents]` with one query;
   - inject the volumes and roll up each member’s GS;
   - for every member with `rank >= 2`, decide eligible/below quota/amount;
   - build one draft per member, with the rank, directs, requirement count, team size, GS, PS,
     quota, rates, amount, status and reason.
5. In **one transaction**: insert the period header first (status `evaluated`, counts and totals
   computed from the drafts), then insert every award row. The header must exist before the rows
   because `royalty_awards.period` is a foreign key; the transaction makes the pair atomic, so a
   crash can never leave a “closed” period without its rows or a row without its header.
6. If there are no `pending` awards after the insert (all skipped or no rows), set the header
   `status='paid'`, `paid_at = NOW()`, `paid_by = actor`, and skip payment.
7. Audit `royalty.period.evaluate` (period, counts, totals, `enabled`, the ladder snapshot).
8. Return the same shape as `preview()` plus the persisted ids.

**Evaluation writes no wallet money.** It never calls `WalletService::credit()`. `--evaluate-only`
relies on this.

#### 12.6.4 Payment

`RoyaltyService::pay($period, $limit, $maxBatches, $actorId)`:

1. Lock the header (`SELECT … FOR UPDATE`); refuse an absent header, a `paid`/`skipped` header. If no
   `pending` award remains, finalise even when payments are paused (nothing left to move). Then, if
   `royalty_pay_enabled = 0`, return `paused`, write nothing.
2. Select `pending` awards for the period, ascending `user_id`, at most `$limit` (`FOR UPDATE`).
3. In one transaction per batch:
   - for each award, call `WalletService::credit($userId, $amount, 'royalty', $awardId, $note, true)`;
   - update the award `status='paid'`, `ledger_id`, `credited_at` with a conditional
     `WHERE id = ? AND status = 'pending'`; if the update affects 0 rows, throw and roll the batch
     back (the wallet and the ledger stay consistent);
   - update the header `paid_count`, `paid_total` from the persisted rows;
   - commit.
4. Repeat until no `pending` award remains or `--max-batches` is reached. If none remain: set
   `status='paid'`, `paid_at = NOW()`, `paid_by = actor`; audit `royalty.period.pay`.
5. If the batch limit is reached: leave `status='evaluated'`, audit the partial payment, return
   `resume_required = true`; the next run continues.
6. Touch the live surfaces **after** the commit, in batches (not inside the wallet transaction).

**Lock order:** period header → awards ascending `user_id` → each wallet row (inside the credit). A
concurrent payout holds one member’s wallet row; it cannot deadlock with the ascending batch because
the close never takes a member lock before the period header and never goes backwards.

#### 12.6.5 The stream switches

| Switch | Default | Read at | Effect |
|---|---|---:|---|
| `royalty_enabled` | `0` | evaluation | `0` → the period is `skipped`; no award rows; no money. `1` → evaluate normally |
| `royalty_pay_enabled` | `1` | payment | `0` → evaluation still writes pending awards; payment returns `paused`, no wallet row. `1` → pay normally |

- Turning `royalty_enabled` off **does not** reverse a paid period and **does not** cancel pending
  awards. It only makes the next close skip.
- Turning `royalty_pay_enabled` off is the operational pause: evaluate the month, inspect it, then
  pay. Pending awards remain a liability in the header and in the admin panel.
- A `skipped` period can never be paid later, even if the switch is turned on. That is D-15.

#### 12.6.6 Catch-up and the baseline

- `royalty_ledger_since` is set to the migration instant. The **first payable month** is the first
  month that **starts on or after** that timestamp’s month:
  - installed on 2026-11-15 → the first payable month is **2026-12**; November is never closed or
    paid;
  - installed on 2026-12-01 00:00:00 → the first payable month is **2026-12**.
- `pendingPeriods()` walks from the first payable month through **last month**, oldest first, and
  returns the months without a header. A fresh install returns `[]` until a full month has passed.
- **Catch-up is exact.** Each period is evaluated with its own cutoff and its own volume window, so
  closing October and November in one December run produces the same awards an October run and a
  November run would have produced (given the same settings). The only historical input the app
  cannot reconstruct is the member’s membership status (eligibility uses the current state).
- **No retroactive pay before the baseline.** Months before the first payable month are never in
  `pendingPeriods()` and have no header. They simply do not exist for Royalty.
- **A disabled month is skipped, not deferred.** If the job runs for November while
  `royalty_enabled = 0`, November is final `skipped`. Enabling on 1 December pays December, not
  November.

#### 12.6.7 Relationship to the unilevel close

- The two closes are independent: different tables, different rules, different jobs.
- Royalty does not read `unilevel_periods` or `unilevel_activity`; the repeat-purchase stream does not
  read `royalty_periods` or `royalty_awards`.
- The recommended schedule runs `unilevel_month_close.php` at 00:15 and
  `royalty_month_close.php` at 00:30 on the 1st. The order is for log readability only; either order
  is correct.
- A member can be “Active” for repeat purchases and below the Royalty quota in the same month; the
  two statuses are unrelated.

### 12.7 Worked examples

All examples use the reference settings (10/10/10/10, 500k/1M/5M, 3/5, 5/10, 10/15) and exact peso
amounts.

**A. Supervisor, quota met through self + downline.** GS ₱510,000, PS ₱60,000:
`0.03 × 510,000 + 0.05 × 60,000 = 15,300 + 3,000 =` **₱18,300.00**.

**B. Supervisor exactly at the quota, no personal purchases.** GS ₱500,000, PS ₱0:
`0.03 × 500,000 =` **₱15,000.00**.

**C. Supervisor below the quota.** GS ₱410,000, PS ₱60,000: **₱0.00** (skipped, not ₱12,300).

**D. Manager at the quota, no personal purchases.** GS ₱1,000,000: `0.05 × 1,000,000 =`
**₱50,000.00**.

**E. Director at the quota, no personal purchases.** GS ₱5,000,000: `0.10 × 5,000,000 =`
**₱500,000.00**.

**F. Leader with ₱10,000,000 of group sales.** **₱0.00** — Leader is not a royalty rank.

**G. Director with a personal purchase inside a ₱5,000,000 group.** GS ₱5,000,000, PS ₱100,000:
`0.10 × 5,000,000 + 0.15 × 100,000 = 500,000 + 15,000 =` **₱515,000.00**.

**H. Supervisor self-funds the quota.** PS ₱500,000, no downline volume: `0.03 × 500,000 + 0.05 ×
500,000 = 15,000 + 25,000 =` **₱40,000.00**.

**I. Rounding.** GS ₱500,000.55, PS ₱60,000.55: `0.03 × 500,000.55 = 15,000.0165`;
`0.05 × 60,000.55 = 3,000.0275`; sum `18,000.044` → **₱18,000.04**. A per-component rounding would
give `15,000.02 + 3,000.03 = 18,000.05`; the plan pays the single-rounding value.

**J. 3-decimal rate.** Supervisor group rate 3.125% on GS ₱10,000, PS ₱0: `312.50` →
**₱312.50**; the stored rate is `3.125`.

**K. A month with no purchases.** GS 0, PS 0: every member is below quota (or rank Leader); no money.

**L. The rank gate is not the volume gate.** A Leader with ₱10,000,000 GS earns ₱0; a Supervisor
with ₱500,000 GS earns ₱15,000. Rank first, then quota.

**M. The minimum trees.** With the reference thresholds:

| Rank | Smallest subtree | Example shape |
|---|---:|---|
| Leader | 11 | self + 10 directs |
| Supervisor | 111 | self + 10 Leaders, each with 10 directs |
| Manager | 1,111 | self + 10 Supervisors, each an 111-member shape |
| Director | 11,111 | self + 10 Managers, each a 1,111-member shape |

### 12.8 Rounding and money rules

- Money is `DECIMAL(12,2)` in MySQL (awards, quotas) and `DECIMAL(14,2)` for GS/PS and period totals.
  Rates are `DECIMAL(6,3)` (thousandths of a percent).
- The implementation converts GS/PS to **integer centavos** and each rate to **milli-percent**
  (`3.125% → 3125`) and computes:

  ```
  numerator   = group_milli × GS_cents + personal_milli × PS_cents
  denominator = 100 × 1000 = 100,000
  amount_cents = floor((numerator + denominator / 2) / denominator)      // half-up, non-negative
  amount       = amount_cents / 100
  ```

  The intermediate values fit comfortably in a signed 64-bit integer for the plan’s quotas: a
  ₱5,000,000 group is 500,000,000 cents, and × 100,000 (100% in milli-percent) is 5×10¹³, far below
  the 9.22×10¹⁸ limit. A pathological GS near ₱1 trillion would exceed it, which is why the settings
  cap quotas at ₱999,999,999.99 and the service rejects a non-positive/overflowing intermediate.
- **One rounding** on the combined amount. Do not round the group and personal components first.
- A zero amount is never credited. It is recorded as a `skipped` row with reason `zero amount` when
  the member was otherwise eligible and at quota.
- `money()` formats stored amounts with two decimals; the ledger stores the centavo value, never a
  display string.
- No float is used for a stored amount. The volume sums come from SQL `DECIMAL`; the conversion to
  cents uses the decimal string, not a binary float, in the service.
- Negative amounts, negative volumes and `personal_volume > group_volume` are refused by CHECK
  constraints and by the service.

### 12.9 Ceilings and validation

| Guard | Rule | Where | Phase |
|---|---|---|---|
| Directs threshold | integer 1–1,000 | settings card | 19B |
| Downline rank thresholds | integer 1–100,000 each | settings card | 19B |
| Quotas | decimal `0.00–999,999,999.99`, 2 decimals; non-decreasing by rank | settings card | 19B |
| Group rates | `0.000–100.000`, 3 decimals; non-decreasing by rank | settings card | 19B |
| Personal rates | `0.000–100.000`, 3 decimals; non-decreasing by rank | settings card | 19B |
| Per-rank total | `group + personal ≤ 100.000` | settings card (cross-field) | 19B |
| Enable with zero rates | refused (“this ladder would pay nothing”) | settings card | 19B |
| Award amount | `g%·GS + p%·PS` with `GS ≥ quota`; `group + personal ≤ 100` means `amount ≤ GS`; no explicit cap (reference §9.2) | service | 19C |
| GS/PS | `0 ≤ PS ≤ GS`; decimal 14,2 | engine + CHECK | 19C |
| Period | `YYYY-MM`; not current/future; not already closed | service + job | 19C |
| Batch size | 1–5,000, default 500 | job | 19C/19F |
| Exactly one award | `UNIQUE (period, user_id)` | database | 19A |
| Exactly one credit | wallet `UNIQUE (user_id, ref_type, ref_id, type)` | database | 19A |
| No rank history | no table; awards store the rank for paid months | design | 19C |

### 12.10 What does not affect Royalty

- **The member’s Harvest qualification** — the app has no Harvest queue; Royalty never reads one.
- **The member’s repeat-purchase Active flag** — a member can be Inactive for repeat purchases and
  still receive Royalty, and vice versa. The two streams have different gates.
- **The member’s package, registration code or registration payment method** — only the member
  account, the tree, the paid orders and the settings matter.
- **The member’s sponsor’s rank** — ranks count the whole subtree, not the sponsor chain.
- **The buyer’s rank in a purchase** — a purchase feeds the buyer’s own PS/GS and every ancestor’s
  GS, regardless of anyone’s rank; it does not pay a per-order commission.
- **The payment method, delivery address, shipping fee or fulfilment status** — only `paid_at` and
  the line totals matter.
- **The product’s category, stock, P.V. or per-product switches** — every paid product line counts at
  its `line_total`. There is no product-level Royalty switch.
- **The order count** — a member can buy many times in a month; the volume is summed once.
- **The month boundary at payment time** — an order verified in the next month pays in the next month
  (that is the rule, not an exception).
- **The member’s own purchases as a “buy-in”** — they count toward the quota, but they do not buy a
  rank; ranks count people (reference §11.2 #13).

### 12.11 Deliberate divergences from the simulator

| # | Simulator (`comp-plan_addons.md` §6) | This plan | Why |
|---|---|---|---|
| 1 | “Advance month” is a manual button; sales reset when clicked | A calendar month is closed by a job after it ends; sales never “reset”, they are period-scoped | The app has real dates and a scheduler; a manual month counter would let an operator rewrite history |
| 2 | A “purchase” is an arbitrary peso amount entered in the UI | A purchase is a **paid shop order line** (`line_total`, `paid_at`) | Real money has an order, a payment and a date; this is the app’s existing money event |
| 3 | Registration adds ₱5,000 revenue but **not** to `sales` | Package activations, registration codes and top-ups are excluded from PS/GS | Same rule, enforced by using only `shop_order_items` |
| 4 | Ranks count every synthetic member | Ranks count `role = 'member'` accounts; staff are excluded | Staff are not members; a rank must not be obtainable by an admin account |
| 5 | Ranks are derived from the tree at evaluation time | Ranks are derived **as of the period end** (`created_at` cutoff) | A delayed or catch-up close must not change history |
| 6 | No cancellation model | A cancelled order is excluded before the close; a cancellation after the close does not rewrite the award | Real orders can be cancelled; a closed period is an immutable financial record |
| 7 | Ranks/payouts are shown in the simulator’s `earnedAll` ledger only | The app credits the withdrawable e-wallet with a `royalty` ledger row and an immutable award row | Members must be able to withdraw the money and finance must reconcile it |
| 8 | The ladder is fixed 10/10/10/10 and 3/5/10, 5/10/15 | The same defaults, but configurable and snapshotted per period | Operations can tune the plan without a deploy; the snapshot preserves history |
| 9 | No “Active” gate for Royalty | No repeat-purchase Active gate; only an active member account and membership | The reference has no Active rule for Royalty; adding one would silently change the plan |
| 10 | No minimum or maximum purchase | Same: every paid line counts, no minimum | The reference has none; adding one is a different plan |
| 11 | Harvest and Royalty share the same page | The app has no Harvest; Royalty is independent | Out of scope |
| 12 | No rank history | No rank history table; awards record the rank for paid months | The reference has none; a separate proposal can add snapshots |
| 13 | No account/membership gate: every ranked member is paid | The recipient must be `is_active = 1` and `member_status = 'active'`; otherwise a `skipped` row is written | A suspended or pending account cannot receive withdrawable money in this build |
| 14 | Whole-peso rounding | Centavo precision (`DECIMAL(12,2)`), half-up once | The app’s money model is centavos; a peso-only award would not match the wallet ledger |

**What is deliberately kept identical:** the five ranks and their self-prerequisite structure; the
10-direct / 10-ranked-downline gates as defaults; Leader is not paid; GS includes self; PS is paid
twice; the quota is all-or-nothing; one rounding; the 3/5, 5/10, 10/15 rates and 500k/1M/5M quotas;
no stacking; no carry-over; rank is derived, not stored.

---

## 13. Data model

### 13.1 Entity relationship

```
users (role='member')
  │  sponsor_id ───────────────┐  (self-referencing tree, any depth)
  │                            │
  ├──1:n── royalty_awards ─────┼──n:1── royalty_periods
  │        (user_id)           │        (period)
  │                            │
  ├──1:n── shop_orders ──1:n── shop_order_items
  │        (user_id)              (line_total, paid_at on the order)
  │
  └──1:n── ewallet_ledger
           ref_type='royalty', ref_id = royalty_awards.id

royalty_periods (period PK, status, snapshot of the ladder, counts, totals)
royalty_awards  (id PK, UNIQUE (period, user_id), rank snapshot, GS/PS, amount, status)
```

- `royalty_awards.period` → `royalty_periods.period` (`ON DELETE CASCADE`).
- `royalty_awards.user_id` → `users.id` (`ON DELETE CASCADE`).
- `royalty_periods.evaluated_by` / `paid_by` → `users.id` (`ON DELETE SET NULL`); the actor is
  usually `NULL` (system/cron).
- `royalty_awards.ledger_id` is **not** a foreign key: the wallet ledger is append-only and must
  outlive a correction, exactly like `unilevel_awards.ledger_id`.

### 13.2 `royalty_periods` — one row per evaluated month

The header that makes a month authoritative. **No auto-increment column**: the primary key is the
period.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `period` | `CHAR(7)` | no | — | PK, `YYYY-MM` in Asia/Manila |
| `status` | `ENUM('evaluated','paid','skipped')` | no | `evaluated` | §12.6.2 |
| `enabled` | `TINYINT(1)` | no | `0` | was `royalty_enabled` on when evaluated? |
| `evaluated_at` | `DATETIME` | no | — | when the award rows were written |
| `evaluated_by` | `INT UNSIGNED` | yes | `NULL` | staff id, `NULL` = system/cron |
| `paid_at` | `DATETIME` | yes | `NULL` | when the period became final |
| `paid_by` | `INT UNSIGNED` | yes | `NULL` | staff id, `NULL` = system/cron |
| `as_of` | `DATETIME` | no | — | the period end used as the rank cutoff |
| `leader_directs` | `SMALLINT UNSIGNED` | no | — | snapshot of the threshold |
| `supervisor_leaders` | `INT UNSIGNED` | no | — | snapshot |
| `manager_supervisors` | `INT UNSIGNED` | no | — | snapshot |
| `director_managers` | `INT UNSIGNED` | no | — | snapshot |
| `quota_supervisor` | `DECIMAL(12,2)` | no | — | snapshot |
| `quota_manager` | `DECIMAL(12,2)` | no | — | snapshot |
| `quota_director` | `DECIMAL(12,2)` | no | — | snapshot |
| `group_pct_supervisor` | `DECIMAL(6,3)` | no | — | snapshot |
| `personal_pct_supervisor` | `DECIMAL(6,3)` | no | — | snapshot |
| `group_pct_manager` | `DECIMAL(6,3)` | no | — | snapshot |
| `personal_pct_manager` | `DECIMAL(6,3)` | no | — | snapshot |
| `group_pct_director` | `DECIMAL(6,3)` | no | — | snapshot |
| `personal_pct_director` | `DECIMAL(6,3)` | no | — | snapshot |
| `members_count` | `INT UNSIGNED` | no | `0` | member accounts evaluated at the cutoff |
| `leaders_count` | `INT UNSIGNED` | no | `0` | exclusive rank count (rank 1 only) |
| `supervisors_count` | `INT UNSIGNED` | no | `0` | exclusive rank count (rank 2 only) |
| `managers_count` | `INT UNSIGNED` | no | `0` | exclusive rank count (rank 3 only) |
| `directors_count` | `INT UNSIGNED` | no | `0` | exclusive rank count (rank 4 only) |
| `award_count` | `INT UNSIGNED` | no | `0` | award rows written (Supervisor+) |
| `paid_count` | `INT UNSIGNED` | no | `0` | rows with `status='paid'` |
| `skipped_count` | `INT UNSIGNED` | no | `0` | rows with `status='skipped'` |
| `award_total` | `DECIMAL(14,2)` | no | `0.00` | sum of `pending` + `paid` amounts |
| `paid_total` | `DECIMAL(14,2)` | no | `0.00` | sum of `paid` amounts |
| `note` | `VARCHAR(160)` | yes | `NULL` | e.g. `royalty stream off` |
| `created_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP` | row write time |

Keys: `PRIMARY KEY (period)`, `KEY (status, paid_at)`,
`CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')`,
`CHECK (paid_total >= 0 AND award_total >= 0 AND paid_total <= award_total)`,
`CHECK (members_count >= leaders_count + supervisors_count + managers_count + directors_count)`.

Notes:

- `members_count` counts **all** member accounts at the cutoff; the four exclusive rank counts plus
  `members_count − (leaders + supervisors + managers + directors)` (the Members) sum to it. The
  exclusive counts are the report’s rank distribution; the rank **predicates** used in §12.3 are
  cumulative and are not these columns.
- `award_count` is the number of rows; it equals `supervisors_count + managers_count +
  directors_count` for a normal evaluated period (every Supervisor+ gets a row, paid or skipped).
- `award_total − paid_total` is the pending liability at any instant.
- A `skipped` period has `enabled=0`, `award_count=0`, all totals 0, and a `note`.
- `paid` means **final**, not necessarily money: a period with all awards skipped has
  `paid_total=0` and `status='paid'`.

### 13.3 `royalty_awards` — the money ledger

One row per member per closed period. **The money anchor is `UNIQUE (period, user_id)`.** There is no
`type` column and no rank-name column: `rank_level` plus `RoyaltyRank::NAMES` is the whole story.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | no | auto | PK; also the wallet `ref_id` |
| `period` | `CHAR(7)` | no | — | FK → `royalty_periods.period`, `ON DELETE CASCADE` |
| `user_id` | `INT UNSIGNED` | no | — | FK → `users.id`, `ON DELETE CASCADE`; the recipient |
| `rank_level` | `TINYINT UNSIGNED` | no | — | 2 = Supervisor, 3 = Manager, 4 = Director |
| `directs` | `SMALLINT UNSIGNED` | no | `0` | direct count at the cutoff |
| `downline_rank_count` | `INT UNSIGNED` | no | `0` | the count that satisfied this rank: Leader+ (Supervisor), Supervisor+ (Manager), Manager+ (Director) |
| `team_size` | `INT UNSIGNED` | no | `0` | all member descendants at the cutoff |
| `group_volume` | `DECIMAL(14,2)` | no | `0.00` | GS in the period |
| `personal_volume` | `DECIMAL(14,2)` | no | `0.00` | PS in the period |
| `quota` | `DECIMAL(12,2)` | no | — | the rank’s quota at evaluation |
| `group_pct` | `DECIMAL(6,3)` | no | — | the rank’s group rate at evaluation |
| `personal_pct` | `DECIMAL(6,3)` | no | — | the rank’s personal rate at evaluation |
| `amount` | `DECIMAL(12,2)` | no | `0.00` | the award; 0 for a skipped row |
| `status` | `ENUM('pending','paid','skipped')` | no | `pending` | §12.6 |
| `reason` | `VARCHAR(120)` | yes | `NULL` | why a row is skipped |
| `ledger_id` | `INT UNSIGNED` | yes | `NULL` | the `ewallet_ledger.id` credited (never an FK) |
| `credited_at` | `DATETIME` | yes | `NULL` | when the wallet credit happened |
| `created_at` | `TIMESTAMP` | no | `CURRENT_TIMESTAMP` | evaluation write time |

Keys: `UNIQUE (period, user_id)` — the money anchor; `KEY (user_id, period)`,
`KEY (period, status)`, `KEY (status, created_at)`, `KEY (ledger_id)`.

Checks: `rank_level BETWEEN 2 AND 4`, `amount >= 0`, `group_volume >= 0`, `personal_volume >= 0`,
`personal_volume <= group_volume`, `quota >= 0`, `group_pct BETWEEN 0 AND 100`,
`personal_pct BETWEEN 0 AND 100`.

Notes:

- `amount` is the **payable** amount for `pending`/`paid`; a `skipped` row stores `0.00` and its
  computed “would have been” is derivable from the stored GS/PS/rates (the admin page shows it).
- `reason` is a stable string from `RoyaltyAward::REASONS`: `group sales below quota`,
  `account is not active`, `membership is not active`, `zero amount`.
- `rank_level` is 2–4 only. Members and Leaders have no row; a Leader’s absence is explained by the
  member page, not by a zero row.
- The only update ever applied is `pending → paid` plus `ledger_id`, `credited_at`. `amount`, rank,
  GS, PS, quota and rates are immutable after the insert.
- `downline_rank_count` is the requirement count for the **achieved** rank (for a Director it is the
  Manager+ count; for a Manager the Supervisor+ count; for a Supervisor the Leader+ count).
  `team_size` is the total descendant count, for context and reports.
- The row is `pending` at evaluation; `paid` after a wallet credit; `skipped` if it never pays.

### 13.4 The wallet ENUM and the settings

**`ewallet_ledger.ref_type`** gains **`royalty`**, appended last:

```
ENUM('commission','payout','transfer','topup','registration','shop_order',
     'adjustment','refund','unilevel','unilevel_reversal','royalty')
```

`WalletService::REF_TYPES` gains `'royalty'` in the same position; `P5-T8` compares the PHP list with
the live ENUM in both directions. `wallet_ref_label('royalty')` = **Royalty bonus**;
`wallet_ref_icon('royalty')` = `bx bx-crown`.

**The sixteen settings** (Commissions card, sub-block “Royalty bonus”):

| Key | Default | Exposed as | Behaviour | Phase |
|---|---|---|---|---|
| `royalty_enabled` | `0` | switch — **Enable the Royalty bonus** | global earning switch; `0` makes the next close a `skipped` period | 19A/19B |
| `royalty_pay_enabled` | `1` | switch — **Pay Royalty awards** | payment pause; `0` leaves evaluated awards pending | 19A/19B |
| `royalty_leader_directs` | `10` | number — **Leader: direct members** | Leader predicate | 19A/19B |
| `royalty_supervisor_leaders` | `10` | number — **Supervisor: downline Leaders** | Supervisor predicate | 19A/19B |
| `royalty_manager_supervisors` | `10` | number — **Manager: downline Supervisors** | Manager predicate | 19A/19B |
| `royalty_director_managers` | `10` | number — **Director: downline Managers** | Director predicate | 19A/19B |
| `royalty_supervisor_quota` | `500000` | number — **Supervisor: monthly group quota (₱)** | quota gate | 19A/19B |
| `royalty_manager_quota` | `1000000` | number — **Manager: monthly group quota (₱)** | quota gate | 19A/19B |
| `royalty_director_quota` | `5000000` | number — **Director: monthly group quota (₱)** | quota gate | 19A/19B |
| `royalty_supervisor_group_pct` | `3` | number — **Supervisor: group %** | group rate | 19A/19B |
| `royalty_supervisor_personal_pct` | `5` | number — **Supervisor: personal %** | personal rate | 19A/19B |
| `royalty_manager_group_pct` | `5` | number — **Manager: group %** | group rate | 19A/19B |
| `royalty_manager_personal_pct` | `10` | number — **Manager: personal %** | personal rate | 19A/19B |
| `royalty_director_group_pct` | `10` | number — **Director: group %** | group rate | 19A/19B |
| `royalty_director_personal_pct` | `15` | number — **Director: personal %** | personal rate | 19A/19B |
| `royalty_ledger_since` | now | not shown | baseline for the first payable month and reconciliation | 19A |

The card also renders a read-only **ladder table** (Member/Leader/Supervisor/Manager/Director) with
the requirement, quota, group %, personal % and “own purchase” (`group + personal`) so an operator
sees the effective load before saving.

### 13.5 Migration `023_royalty_bonus.sql`

Idempotent and re-runnable, in the style of
[`020_unilevel_repeat_purchase.sql`](../system/database/migrations/020_unilevel_repeat_purchase.sql)
and [`022_shop_cancel_toggle.sql`](../system/database/migrations/022_shop_cancel_toggle.sql): every
`ALTER` is guarded by an `information_schema` probe and a `PREPARE`/`EXECUTE` pair; the settings
insert is `ON DUPLICATE KEY UPDATE key_name = key_name`. The file contains no data backfill.

```sql
-- =============================================================
--  Phase 19 migration — Royalty bonus
--
--  Applies to a Phase 18 database and adds:
--
--    royalty_periods   one row per evaluated month (payout header)
--    royalty_awards    the Royalty money ledger (one row per member per month)
--
--    ewallet_ledger.ref_type + 'royalty'
--    sixteen settings (the stream is off by default)
--
--  No backfill: months before `royalty_ledger_since` are never paid
--  (plan D-15). A closed period is immutable (plan §12.6).
--
--      mysql -u root <db> < database/migrations/023_royalty_bonus.sql
--
--  No `down` note: the tables are additive. Dropping them would erase
--  the record of every Royalty award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. ewallet_ledger.ref_type — append 'royalty' -------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type'
    AND COLUMN_TYPE LIKE '%royalty%');
SET @sql := IF(@has = 0,
  "ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM('commission','payout','transfer','topup','registration','shop_order',
          'adjustment','refund','unilevel','unilevel_reversal','royalty')
     NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. royalty_periods — the monthly header -------------------------
CREATE TABLE IF NOT EXISTS royalty_periods (
  period                 CHAR(7)       NOT NULL,
  status                 ENUM('evaluated','paid','skipped') NOT NULL DEFAULT 'evaluated',
  enabled                TINYINT(1)    NOT NULL DEFAULT 0,
  evaluated_at           DATETIME      NOT NULL,
  evaluated_by           INT UNSIGNED  NULL,
  paid_at                DATETIME      NULL,
  paid_by                INT UNSIGNED  NULL,
  as_of                  DATETIME      NOT NULL,
  leader_directs         SMALLINT UNSIGNED NOT NULL,
  supervisor_leaders     INT UNSIGNED  NOT NULL,
  manager_supervisors    INT UNSIGNED  NOT NULL,
  director_managers      INT UNSIGNED  NOT NULL,
  quota_supervisor       DECIMAL(12,2) NOT NULL,
  quota_manager          DECIMAL(12,2) NOT NULL,
  quota_director         DECIMAL(12,2) NOT NULL,
  group_pct_supervisor   DECIMAL(6,3)  NOT NULL,
  personal_pct_supervisor DECIMAL(6,3) NOT NULL,
  group_pct_manager      DECIMAL(6,3)  NOT NULL,
  personal_pct_manager   DECIMAL(6,3)  NOT NULL,
  group_pct_director     DECIMAL(6,3)  NOT NULL,
  personal_pct_director  DECIMAL(6,3)  NOT NULL,
  members_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  leaders_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  supervisors_count      INT UNSIGNED  NOT NULL DEFAULT 0,
  managers_count         INT UNSIGNED  NOT NULL DEFAULT 0,
  directors_count        INT UNSIGNED  NOT NULL DEFAULT 0,
  award_count            INT UNSIGNED  NOT NULL DEFAULT 0,
  paid_count             INT UNSIGNED  NOT NULL DEFAULT 0,
  skipped_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  award_total            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  paid_total             DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  note                   VARCHAR(160)  NULL,
  created_at             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (period),
  KEY idx_royalty_periods_status (status, paid_at),
  CONSTRAINT fk_rp_evaluator FOREIGN KEY (evaluated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_rp_payer     FOREIGN KEY (paid_by)      REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_rp_period   CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
  CONSTRAINT chk_rp_totals   CHECK (award_total >= 0 AND paid_total >= 0 AND paid_total <= award_total)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3. royalty_awards — the money ledger ----------------------------
CREATE TABLE IF NOT EXISTS royalty_awards (
  id                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  period               CHAR(7)          NOT NULL,
  user_id              INT UNSIGNED     NOT NULL,
  rank_level           TINYINT UNSIGNED NOT NULL,
  directs              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  downline_rank_count  INT UNSIGNED     NOT NULL DEFAULT 0,
  team_size            INT UNSIGNED     NOT NULL DEFAULT 0,
  group_volume         DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  personal_volume      DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  quota                DECIMAL(12,2)    NOT NULL,
  group_pct            DECIMAL(6,3)     NOT NULL,
  personal_pct         DECIMAL(6,3)     NOT NULL,
  amount               DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  status               ENUM('pending','paid','skipped') NOT NULL DEFAULT 'pending',
  reason               VARCHAR(120)     NULL,
  ledger_id            INT UNSIGNED     NULL,
  credited_at          DATETIME         NULL,
  created_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_royalty_award (period, user_id),
  KEY idx_royalty_user (user_id, period),
  KEY idx_royalty_period_status (period, status),
  KEY idx_royalty_status (status, created_at),
  KEY idx_royalty_ledger (ledger_id),
  CONSTRAINT fk_ra_period FOREIGN KEY (period)  REFERENCES royalty_periods (period) ON DELETE CASCADE,
  CONSTRAINT fk_ra_user   FOREIGN KEY (user_id) REFERENCES users (id)              ON DELETE CASCADE,
  CONSTRAINT chk_ra_rank   CHECK (rank_level BETWEEN 2 AND 4),
  CONSTRAINT chk_ra_amount CHECK (amount >= 0),
  CONSTRAINT chk_ra_volume CHECK (group_volume >= 0 AND personal_volume >= 0
                                  AND personal_volume <= group_volume),
  CONSTRAINT chk_ra_rates  CHECK (group_pct BETWEEN 0 AND 100
                                  AND personal_pct BETWEEN 0 AND 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 4. settings — inert until an operator turns the stream on --------
INSERT INTO settings (key_name, value) VALUES
  ('royalty_enabled',                   '0'),
  ('royalty_pay_enabled',               '1'),
  ('royalty_leader_directs',            '10'),
  ('royalty_supervisor_leaders',        '10'),
  ('royalty_manager_supervisors',       '10'),
  ('royalty_director_managers',         '10'),
  ('royalty_supervisor_quota',          '500000'),
  ('royalty_manager_quota',             '1000000'),
  ('royalty_director_quota',            '5000000'),
  ('royalty_supervisor_group_pct',      '3.000'),
  ('royalty_supervisor_personal_pct',   '5.000'),
  ('royalty_manager_group_pct',         '5.000'),
  ('royalty_manager_personal_pct',      '10.000'),
  ('royalty_director_group_pct',        '10.000'),
  ('royalty_director_personal_pct',     '15.000'),
  ('royalty_ledger_since',              DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE key_name = key_name;
```

**Why no `royalty_processed_at` on `shop_orders`.** Royalty is not per-order; the month close reads
the period’s orders in aggregate. An order stamp would be a forward stub with no writer (D-02).

**Why no new index on `shop_orders`.** `idx_orders_paid (paid_at)` and
`idx_orders_user_paid (user_id, paid_at)` already serve the close aggregate and per-member reads. The
volume query filters `paid_at` and groups by `user_id`; `idx_orders_paid` is the range index.

### 13.6 Fresh-install mirror (`database/schema.sql`)

`database/schema.sql` is the fresh-install source of truth; the migration and the schema must stay
equivalent in effect (`MigrationTest` compares a migrated database with a fresh one).

| Location in `schema.sql` | Edit |
|---|---|
| `ewallet_ledger.ref_type` | append `royalty`; update the comment above it |
| after `unilevel_periods` (before `impersonation_log`) | add `royalty_periods`, then `royalty_awards` |
| `seeds/001_settings.sql` | add the sixteen keys in a “Royalty bonus (Phase 19)” block |
| `install/header.sql` | `33 tables · 73 settings` |
| `install/footer.sql` | the expected-counts comment becomes 33 / 73 |
| `database/install.sql` | rebuild (`php bin/build_install_sql.php`) and `--check` |

**Expected install counts:** `33 tables · 73 settings · 2 staff · 0 members · 7 products ·
2 packages · 18 levels · 10 contents · 0 codes`. `DatabaseInstaller::EXPECTED` is updated to
`tables_created 33`, `settings_rows 73`; the other seven values are unchanged.

**Expected migration range:** `001_settings_roles.sql` … `023_royalty_bonus.sql`.

**Expected statement count:** the bundle grows by the two `CREATE TABLE` statements (the settings
insert stays one statement); `build_install_sql --check` prints the new total. Do not hard-code the
byte size — the check compares the bundle with its sources.

### 13.7 Invariants

These are asserted by `RoyaltyService::reconcile()`, the reset integrity pack and the tests.

1. `SUM(royalty_awards.amount WHERE status='paid')` = `SUM(ewallet_ledger.amount WHERE
   ref_type='royalty')` (from `royalty_ledger_since` onwards).
2. Every `paid` award has a non-null `ledger_id` pointing at an existing ledger row; every
   `ref_type='royalty'` ledger row points at an existing award.
3. No award’s ledger amount differs from `royalty_awards.amount`.
4. Every `royalty_awards` row has a `royalty_periods` header with the same `period`.
5. For every period: `award_count` = count of its award rows; `paid_count` = count of `paid`;
   `skipped_count` = count of `skipped`; `award_total` = sum of `pending` + `paid`;
   `paid_total` = sum of `paid`.
6. A period with `status='paid'` has no `pending` award.
7. A period with `status='skipped'` has no award rows and `enabled=0`.
8. An `evaluated` period has at least one `pending` award, **or** it is awaiting finalisation
   after its last batch (a repairable warning, not a money mismatch: the next run finalises it).
9. Every award’s `rank_level` is 2–4, and the recipient is a member account (`role = 'member'` with
   a rank at the evaluation); Royalty has no buyer rebate, so no row exists for a purchase’s buyer as
   such.
10. Every award’s `period` has a `royalty_periods` header whose `as_of` is that period’s end; the job
    refuses the current/future month, so a header can only be written for a completed month.
11. `personal_volume <= group_volume` for every row.
12. `SUM(paid_total)` across periods = `SUM(amount)` of paid awards; `paid_total` never decreases.
13. `royalty_periods.members_count >= leaders_count + supervisors_count + managers_count +
    directors_count`.
14. A reset leaves zero rows in both tables and `RoyaltyService::reconcile()` clean.

**Auto-increment note.** `royalty_awards` has an auto-increment `id`; `royalty_periods` has a
natural primary key. `DatabaseResetService::AUTO_INCREMENT_TABLES` therefore gains
`royalty_awards` **only**.

### 13.8 Volume and retention

| Object | Growth | Notes |
|---|---|---|
| `royalty_awards` | one row per Supervisor+ per month; typically a few percent of members | 5,000 members → ~250–500 rows/month → 3,000–6,000/year; BIGINT PK, indexed by member/period/status |
| `royalty_periods` | 1 row/month | negligible |
| `ewallet_ledger` | +1 paid award | the existing ledger grows by the paid awards only |
| Settings | 16 rows | negligible |

No pruning is needed in v1. Awards are money history and are never deleted; a reset is the only
operation that empties them. `royalty_periods` is one row per month forever.

---

## 14. The money path

### 14.1 Components

| Class | File | Responsibility | Phase |
|---|---|---|---|
| `RoyaltyRank` | `system/app/Models/RoyaltyRank.php` | rank constants, settings ladder, full-network rank map, volume injection and rollup, next-rank requirements | 19B |
| `RoyaltyPeriod` | `system/app/Models/RoyaltyPeriod.php` | period helpers, baseline, existence/status, pending/evaluated periods, history | 19C |
| `RoyaltyAward` | `system/app/Models/RoyaltyAward.php` | award rows, create/mark paid, totals, filters, pages, export, reasons | 19C |
| `RoyaltyService` | `system/app/Services/RoyaltyService.php` | preview, evaluate, pay, close, status, reconcile, switches | 19C |
| `RoyaltyException` | `system/app/Services/RoyaltyException.php` | typed failures (`not_found`, `closed`, `future`, `current`, `state`, `paused`, `empty`) | 19C |
| `bin/royalty_month_close.php` | CLI runner | flags, lock, log, dry-run, evaluate-only, pay-only, doctor (19F), exit codes | 19C/19F |
| `public/cron/royalty_month_close.php` | web entry | `CronAccess`, no session, same runner | 19F |
| `bin/royalty_month_close.cmd` | Windows wrapper | same flags, Task Scheduler | 19F |

No new controller is added for the engine. The existing controllers compose the new models in 19D and
19E (D-16).

### 14.2 Public API

```php
final class RoyaltyRank
{
    public const MEMBER = 0, LEADER = 1, SUPERVISOR = 2, MANAGER = 3, DIRECTOR = 4;
    public const NAMES  = [0 => 'Member', 1 => 'Leader', 2 => 'Supervisor',
                           3 => 'Manager', 4 => 'Director'];
    public const PAYING = [2, 3, 4];

    public static function thresholds(): array;                 // the four ints
    /** thresholds + ['by_rank' => [2 => [quota, group_pct, personal_pct], 3 => …, 4 => …]] */
    public static function ladder(): array;
    /** @return array<int, array<string, mixed>> id => row (see below) */
    public static function map(?string $asOf = null): array;
    public static function forMember(int $userId, ?string $asOf = null): ?array;
    public static function counts(array $map): array;           // exclusive counts + ranked
    public static function withVolumes(array $map, array $volumesCents): array;
    public static function requirements(array $row): array;     // next-rank progress
}

final class RoyaltyPeriod
{
    public static function periodOf(?string $datetime = null): string;
    public static function previous(string $period): string;
    public static function next(string $period): string;
    public static function bounds(string $period): array;       // [start, end) 'Y-m-d H:i:s'
    public static function baseline(): string;                  // first payable 'YYYY-MM'
    public static function exists(string $period): bool;
    public static function status(string $period): ?string;
    public static function find(string $period): ?array;
    public static function periods(int $limit = 12): array;
    public static function pendingPeriods(): array;             // no header, oldest first
    public static function evaluatedPeriods(): array;           // header 'evaluated', oldest first
    public static function duePeriods(): array;                 // evaluated + pending, oldest first
    public static function lastPaid(): ?string;
}

final class RoyaltyAward
{
    public static function create(array $draft): int;
    public static function pendingForPeriod(string $period, int $limit): array;
    public static function markPaid(int $id, int $ledgerId): bool;
    public static function forMemberPeriod(int $userId, string $period): ?array;
    public static function totalsForPeriod(string $period): array;
    public static function totalsForUser(int $userId): array;
    public static function rankBreakdown(string $period): array;
    public static function filterDefaults(): array;
    public static function normaliseFilters(array $raw): array;
    public static function countForPeriod(string $period, array $filters): int;
    public static function pageForPeriod(string $period, array $filters, Paginator $p): array;
    public static function countForUser(int $userId, array $filters): int;
    public static function pageForUser(int $userId, array $filters, Paginator $p): array;
    public static function exportRows(array $filters): array;
}

final class RoyaltyService
{
    public function __construct(?PDO $pdo = null);

    /** Compute a period exactly as evaluate() would; write nothing. The current month may be
     *  previewed; a future month is refused. */
    public function preview(string $period): array;

    /** Write award rows + the period header; no wallet money. */
    public function evaluate(string $period, ?int $actorId = null): array;

    /** Credit pending awards in batches; resumable. */
    public function pay(string $period, int $limit = 500, int $maxBatches = 20, ?int $actorId = null): array;

    /** evaluate() then pay() until final; resumes an evaluated period. */
    public function close(string $period, int $limit = 500, int $maxBatches = 20, ?int $actorId = null): array;

    /** Rank, MTD PS/GS/quota, projection, history summary for one member. */
    public function statusFor(int $userId, ?string $period = null): array;

    /** Finance reconciliation, same contract as UnilevelService::reconcile(). */
    public function reconcile(): array;

    public static function enabled(): bool;          // royalty_enabled
    public static function paymentsEnabled(): bool;  // royalty_pay_enabled
}
```

`preview()` / `evaluate()` return:

```php
[
  'period'       => '2026-10',
  'as_of'        => '2026-11-01 00:00:00',
  'enabled'      => true,
  'eligible'     => 12,              // Supervisor+ rows
  'paid_members' => 10,              // rows that would be paid (at/above quota)
  'skipped'      => 2,
  'award_total'  => 123456.78,       // pending + paid in the preview
  'paid_total'   => 0.00,            // evaluate: 0 until pay()
  'rank_counts'  => ['member'=>..., 'leader'=>..., 'supervisor'=>..., 'manager'=>..., 'director'=>...],
  'awards'       => [ /* drafts/rows */ ],
  'header'       => [ /* the persisted header, null in preview */ ],
  'status'       => 'evaluated',     // or 'paid' when nothing pending
]
```

`pay()` returns:

```php
[
  'period'          => '2026-10',
  'paid'            => 250,          // this call
  'amount'          => 12345.67,     // this call
  'pending_left'    => 0,
  'status'          => 'paid',       // or 'evaluated'
  'paused'          => false,
  'resume_required' => false,
]
```

### 14.3 The rank algorithm

```php
public static function map(?string $asOf = null): array
{
    // One query. $asOf is a 'Y-m-d H:i:s' period end or null for the live tree.
    $sql = "SELECT id, sponsor_id, username, member_status, is_active, created_at
              FROM users
             WHERE role = 'member'";
    $params = [];
    if ($asOf !== null) { $sql .= ' AND created_at < ?'; $params[] = $asOf; }
    $sql .= ' ORDER BY id';

    $rows = query($sql, $params);                 // id => row

    // 1. Children map and roots; a missing/staff sponsor makes the member a root,
    //    a self-sponsor is corrupt and is excluded.
    $children = []; $roots = [];
    foreach ($rows as $id => $row) {
        $sp = (int) ($row['sponsor_id'] ?? 0);
        if ($sp > 0 && $sp === $id) {
            continue;                                   // self-sponsor: unranked
        }
        if ($sp > 0 && isset($rows[$sp])) {
            $children[$sp][] = $id;
        } else {
            $roots[] = $id;                             // company/missing/staff sponsor
        }
    }
    // A member's direct count is count($children[$id]); no separate query.

    // 2. Iterative post-order over the forest; visited set guards cycles.
    $state = []; $out = [];
    foreach ($roots as $root) {
        visit($root);   // computes children first, then this node
    }
    // Any account still unvisited is in a cycle: unranked, excluded.
    return $out;
}
```

`map()` row shape:

```php
[
  'id' => 42, 'username' => 'sup', 'member_status' => 'active', 'is_active' => 1,
  'parent_id' => 7,
  'rank' => 2, 'rank_name' => 'Supervisor',
  'directs' => 10,
  'downline_leaders' => 10, 'downline_supervisors' => 2, 'downline_managers' => 0,
  'team_size' => 130,
  'volume_cents' => 6000000,          // after withVolumes(); 0 before
  'group_volume_cents' => 51000000,   // after withVolumes(); 0 before
]
```

`visit($id)` computes, after its children:

```
SL = (L ? 1 : 0) + Σ child.SL
S  = L && (SL - 1) >= supervisor_leaders
SS = (S ? 1 : 0) + Σ child.SS
M  = S && (SS - 1) >= manager_supervisors
SM = (M ? 1 : 0) + Σ child.SM
D  = M && (SM - 1) >= director_managers
rank = D ? 4 : M ? 3 : S ? 2 : L ? 1 : 0
row = [id, rank, directs, downline_leaders: SL-1, downline_supervisors: SS-1,
       downline_managers: SM-1, team_size: Σ child.team_size + count(children)]
```

- The direct count is `count($children[$id])`, not a separate query.
- `downline_leaders` is `SL − 1` (Leader+ descendants, excluding self).
- `team_size` is all descendants, any rank.
- The pass is iterative, not recursive, so a 100,000-deep chain cannot exhaust the PHP stack.
- A cycle is unreachable from all roots; those accounts are absent from `$out`. The reconcile
  reports `cycle_members` as a warning.

**Volume injection.** `withVolumes($map, $volumesCents)` walks the same post-order and returns each
row with:

```
volume_cents       = $volumesCents[id] ?? 0          // the member's own PS
group_volume_cents = volume_cents + Σ child.group_volume_cents
```

It does not re-query; it reuses the children map already built by `map()`. (The implementation keeps
the children map on the rows or a private static for the duration of the call; either is acceptable
as long as there is no second query.)

### 14.4 The evaluation algorithm

```php
public function evaluate(string $period, ?int $actorId = null): array
{
    $this->assertPeriod($period);
    if ($period >= RoyaltyPeriod::periodOf()) throw RoyaltyException::currentOrFuture($period);
    if (RoyaltyPeriod::exists($period))        throw RoyaltyException::closed($period);

    [$start, $end] = RoyaltyPeriod::bounds($period);
    $enabled = self::enabled();
    $ladder  = RoyaltyRank::ladder();          // thresholds, quotas, rates

    if (!$enabled) {
        // One transaction: a final skipped header, no award rows.
        $this->insertHeader($period, 'skipped', enabled: false, note: 'royalty stream off', ...);
        AuditLog::record(['action' => 'royalty.period.skip', ...]);
        return $this->result($period, enabled: false);
    }

    $map     = RoyaltyRank::map($end);
    $volumes = $this->volumes($start, $end);   // [user_id => cents], one query
    $map     = RoyaltyRank::withVolumes($map, $volumes);

    $drafts = [];
    foreach ($map as $row) {
        if ((int) $row['rank'] < RoyaltyRank::SUPERVISOR) continue;

        $gs   = $row['group_volume_cents'];
        $ps   = $row['volume_cents'];
        $rank = (int) $row['rank'];
        [$quota, $gPct, $pPct] = $ladder['by_rank'][$rank];

        $reason = '';
        if (!$this->accountEligible((int) $row['id'])) {
            $reason = 'account is not active';
        } elseif ((int) $row['member_status'] !== 'active') {
            $reason = 'membership is not active';
        } elseif ($gs < $quota) {
            $reason = 'group sales below quota';
        }

        $amount = $reason === '' ? $this->awardCents($gs, $ps, $gPct, $pPct) : 0;
        if ($reason === '' && $amount <= 0) $reason = 'zero amount';

        $drafts[] = [
            'period' => $period, 'user_id' => (int) $row['id'], 'rank_level' => $rank,
            'directs' => $row['directs'], 'downline_rank_count' => $row['downline_rank_count'],
            'team_size' => $row['team_size'], 'group_volume' => $gs, 'personal_volume' => $ps,
            'quota' => $quota, 'group_pct' => $gPct, 'personal_pct' => $pPct,
            'amount' => $amount, 'status' => $reason === '' ? 'pending' : 'skipped',
            'reason' => $reason ?: null,
        ];
    }

    // One transaction: header first (FK target), then the rows. A failure rolls both back.
    $this->transaction(function () use ($period, $drafts, $ladder, $map, $end, $actorId) {
        $header = $this->insertHeader($period, 'evaluated', enabled: true, ...); // counts/totals from $drafts
        foreach ($drafts as $d) RoyaltyAward::create($d);
        if ($this->pendingCount($period) === 0) {
            $this->finalise($period, $actorId);
        }
    });

    return ...;
}
```

The exact SQL for the volume aggregate:

```sql
SELECT o.user_id, COALESCE(SUM(oi.line_total), 0) AS volume
  FROM shop_orders o
  JOIN shop_order_items oi ON oi.order_id = o.id
 WHERE o.paid_at >= ? AND o.paid_at < ?
   AND o.status <> 'cancelled'
 GROUP BY o.user_id;
```

- One row per buyer; members with no order are absent and default to 0.
- The query does not filter `role`; a staff buyer’s row is ignored because the rank map has no staff
  id.
- `line_total` is `DECIMAL(12,2)`; the sum is `DECIMAL(14,2)`; the PHP conversion to cents uses the
  decimal string.

### 14.5 The payment algorithm

```php
public function pay(string $period, int $limit = 500, int $maxBatches = 20, ?int $actorId = null): array
{
    $limit       = max(1, min(5000, $limit));
    $maxBatches  = max(1, min(1000, $maxBatches));

    // Lock the header first: one payer per period.
    $header = SELECT * FROM royalty_periods WHERE period = ? FOR UPDATE;
    if (!$header)                throw RoyaltyException::notFound($period);
    if ($header['status'] !== 'evaluated') return ['status' => $header['status'], 'paid' => 0, ...];

    // A crash after the last batch but before finalise leaves no pending rows; finish it even if
    // payments are paused now, because there is no money left to move.
    if ($this->pendingCount($period) === 0) {
        $this->finalise($period, $actorId);
        return ['paid' => 0, 'amount' => 0.0, 'pending_left' => 0, 'status' => 'paid', ...];
    }

    if (!self::paymentsEnabled()) return ['paused' => true, 'status' => 'evaluated', ...];

    $paid = 0; $amount = 0.0; $batches = 0;

    while ($batches < $maxBatches) {
        $batch = SELECT * FROM royalty_awards
                  WHERE period = ? AND status = 'pending'
                  ORDER BY user_id, id
                  LIMIT {$limit}
                  FOR UPDATE;

        if ($batch === []) break;

        $this->transaction(function () use ($batch, &$paid, &$amount) {
            foreach ($batch as $award) {
                $movement = $this->wallet->credit(
                    (int) $award['user_id'], (float) $award['amount'], 'royalty',
                    (int) $award['id'], $this->note($award), true
                );
                if (!RoyaltyAward::markPaid((int) $award['id'], (int) $movement['ledger_id'])) {
                    throw new RuntimeException('Royalty award could not be marked paid; batch rolled back.');
                }
                $paid++; $amount += (float) $award['amount'];
            }
            $this->refreshHeaderTotals($period);   // paid_count, paid_total from the rows
        });

        $batches++;
        Live::touch([...]);                        // after the commit only
    }

    $pending = $this->pendingCount($period);

    if ($pending > 0) {
        // The batch ceiling was reached; the header stays 'evaluated' and the next run resumes.
        AuditLog::record(['action' => 'royalty.period.pay', 'meta' => ['partial' => true, 'pending' => $pending, ...]]);
        return ['paid' => $paid, 'amount' => round($amount, 2), 'pending_left' => $pending,
                'status' => 'evaluated', 'resume_required' => true, ...];
    }

    $this->finalise($period, $actorId);            // status='paid', paid_at, paid_by
    AuditLog::record(['action' => 'royalty.period.pay', ...]);

    return ['paid' => $paid, 'amount' => round($amount, 2), 'status' => 'paid', ...];
}
```

Rules:

- **Lock order:** header → pending awards ascending `user_id` → wallet row (inside `credit`). No
  other path takes a wallet row before the header, so a concurrent payout cannot deadlock the close.
- **One batch, one transaction.** A failure rolls back the batch, leaves the previous batches paid,
  and leaves the header `evaluated`. The next run resumes from the first pending row.
- **`--max-batches` bounds a run.** When the ceiling is reached with rows still pending, the method
  returns `status='evaluated'`, `resume_required=true`, writes a partial audit row and exits 0; the
  next scheduled run continues. A crash after the last batch but before `finalise` is healed on the
  next call: no pending rows + `evaluated` finalises even when payments are paused.
- **The wallet credit is written first** because its ledger id is what the award points at. If
  `markPaid` fails, the batch throws and the credit rolls back with it.
- **`markPaid` is conditional** (`WHERE id = ? AND status = 'pending'`); a racing second payer gets
  0 rows and throws, which is the desired one-winner behaviour.
- **`refreshHeaderTotals`** recomputes `paid_count` and `paid_total` from the rows, so a resumed run
  cannot drift.
- **`finalise`** sets `status='paid'`, `paid_at=NOW()`, `paid_by=$actorId` only when no `pending`
  row remains. It is idempotent (a second call on a paid header is a no-op).
- **Live touches** are after the commit and batched; a failure to touch a revision never rolls back
  money (the `Live` methods are tolerant).

### 14.6 Idempotency

| Layer | Anchor | Guarantees |
|---|---|---|
| Period header | `royalty_periods.period` primary key | a second `evaluate()` is refused; a concurrent one loses at the insert |
| Award rows | `UNIQUE (period, user_id)` | even a racing duplicate cannot insert twice |
| Payment transition | `UPDATE … WHERE id = ? AND status = 'pending'` | only one payer can move a row to `paid` |
| Wallet | `UNIQUE (user_id, ref_type, ref_id, type)` with `ref_id = award.id` | a replayed credit returns the original movement; no second cent |
| Job | `CronLock('royalty_month_close')` | overlapping cron runs: one does the work, the other exits 0 |

The e-wallet checkout and manual verification are irrelevant here: Royalty never hooks an order. The
only writer of a Royalty credit is `pay()`.

### 14.7 Transaction boundary and failure policy

- **Evaluation** is one transaction: all award rows and the header commit together. If any insert
  fails, nothing exists and the period is retryable.
- **Payment** is one transaction **per batch**. Money already paid is committed and is never rolled
  back by a later batch failure. The header stays `evaluated` and the run exits 1 with a resume
  instruction.
- **A wallet failure** (missing user, amount over `WalletService::MAX_AMOUNT`, database error)
  propagates: the batch rolls back, the award stays `pending`, and the job logs the member id. A
  retry after fixing the cause resumes.
- **A header/finalise failure** after the last batch: the awards are paid and the header is still
  `evaluated`. The next run sees no pending rows and finalises; `reconcile()` classifies it as a
  **warning**, not a money mismatch.
- **The evaluation never swallows an error.** A missing table, a bad setting or a PHP exception
  aborts the period; the job exits 1. Silently writing a partial month would be worse than a red job.
- **`--dry-run`** runs the computation in memory and writes nothing, including no audit row.
- **`--evaluate-only`** commits the award rows and header but no wallet money; `--pay-only` is the
  only way they become money. This is the operational review window (and the reason
  `royalty_pay_enabled` exists).

### 14.8 Concurrency and lock order

| Resource | Lock | Order |
|---|---|---|
| `royalty_periods` row | `SELECT … FOR UPDATE` | first in `pay()` |
| `royalty_awards` batch | `SELECT … FOR UPDATE` on `(period, status)` ascending `user_id` | second |
| `users` wallet row | `SELECT … FOR UPDATE` inside `WalletService::credit()` | per award, ascending `user_id` |
| `royalty_awards` row update | conditional `WHERE id = ? AND status='pending'` | inside the batch transaction |
| Cron | `flock` on `royalty_month_close.lock` | before any DB work |

- The ascending `user_id` order is explicit, not incidental; it is what prevents two batches (or a
  batch and a payout) from deadlocking on the same wallets in opposite orders.
- `evaluate()` does not take wallet locks at all, so it cannot deadlock with `pay()`.
- A concurrent settings save during evaluation does not lock the period; the evaluation reads the
  ladder once at the start and snapshots it, so a mid-evaluation change applies to the next period.
- A concurrent registration during evaluation cannot change the period-end ranks (cutoff), but it is
  visible to the live tree; no lock is needed.

### 14.9 Performance budget

| Step | Queries | Notes |
|---|---|---|
| Rank map | 1 | `users` where role member, optional `created_at <`; index `idx_users_sponsor` is not needed for the scan; the pass is in memory |
| Period volumes | 1 | `idx_orders_paid` range + group by user; `idx_orders_user_paid` for per-member reads |
| Award inserts | 1 prepared statement per row; rows = Supervisor+ | typically a few percent of members |
| Header insert | 1 | — |
| Payment | 1 header lock + 1 batch select + 1 ledger insert + 1 award update per award + 1 header refresh | batches of 500 |
| Member status page | 1 rank map + 2 award queries + 1 volume query | per page, not per row |
| Admin ledger page | 2 count/page queries + 1 period query + stats queries | indexed by period |

**Budget:** the rank map must complete in **≤ 250 ms** and use **≤ 64 MB** for **≤ 50,000 member
accounts** on the production database (measure in 19G’s acceptance; document the result). Beyond
50,000, a materialized rank snapshot or a closure table is the next step; it is not in v1. The
member dashboard and the admin ledger page are the two hot paths; both are admin/member pages, not
order-path queries, and both are cached by the live layer’s ETag (the page is only rebuilt when its
surface changes).

### 14.10 The close job

`bin/royalty_month_close.php`:

```
Usage: php bin/royalty_month_close.php [flags]

  --period=YYYY-MM    close exactly that completed month
  --dry-run           compute and print; write nothing (no rows, no wallet, no audit)
  --evaluate-only     write awards and the header; do not pay
  --pay-only          pay an already-evaluated period; resume a partial payment
  --limit=N           payment batch size, 1–5000 (default 500)
  --max-batches=N     stop after N payment batches (default 20) and leave the period evaluated
  --json              machine-readable output
  --doctor            environment/settings/health report
  --quiet             no stdout (the log still records the run)
  --log-dir=<dir>     CLI only; default <storage>/logs
  --help

Without --period it resumes every evaluated period, then closes every pending
completed month, from the baseline through last month, oldest first. The current
month is refused; a final period is a no-op. A closed period is never recalculated.
```

- **One implementation, three entry points:** CLI, web (`public/cron/royalty_month_close.php`), and
  `.cmd`.
- **Default run:** `evaluatedPeriods()` first (resume payment), then `pendingPeriods()` oldest first;
  for each pending period, `--evaluate-only` if requested, else `close()` (evaluate + pay). A failure
  stops the loop; later months stay open and the next run resumes them.
- **`--period`:** exactly one month; an `evaluated` period is resumed, an absent one is evaluated,
  a final one is a no-op.
- **`--pay-only`:** requires an `evaluated` header; refuses absent/paid/skipped.
- **`--max-batches`:** after N batches, return `resume_required = true`, exit 0, log
  `status=evaluated`; the next scheduled run continues. This bounds a single run’s wall time on a
  large month.
- **`--dry-run`:** calls `preview()` for each target period; prints the would-be counts and totals;
  writes nothing. On a final period it prints the stored header, not a recomputation.
- **`--doctor`:** prints and checks:
  ```
  php           8.3.x
  mysql         8.4.x
  clocks        agree (±0.02s)
  timezone      Asia/Manila (PHP) / +08:00 (MySQL)
  tables        royalty_periods, royalty_awards present
  settings      16/16 present; ladder valid
  ledger since  2026-11-15 10:00:00 → first payable 2026-12
  enabled       royalty_enabled=0  royalty_pay_enabled=1
  last paid     2026-10 (paid 2026-11-01 00:30:02)
  pending       none
  evaluated     none
  lock          free
  wallet        Royalty reconcile clean
  ```
  Exit 1 if a hard check fails (missing table, invalid ladder, clock skew > 2 s, a paid period with a
  pending award); a paused payment switch is a warning.
- **Exit codes:** 0 success/no-op/busy; 1 processing or validation error; 2 environment (DB down,
  unwritable storage, missing tables). The web entry maps the body to 200/500 but the JSON keeps the
  code.
- **Log:** one line per run:
  ```
  [2026-12-01 00:30:02 +08:00] [OK   ] closed=1 periods=2026-11 award=12 paid=10 amount=183000.00 skipped=2 status=paid paused=0
  ```
- **Lock:** `CronLock('royalty_month_close')`; a second run prints `[SKIP ] busy` and exits 0.
- **No session, no cookies, no HTML** in the web entry; `CronAccess` and `X-Robots-Tag: noindex`.

### 14.11 Preview and status APIs

`RoyaltyService::preview($period)` returns the evaluation shape without writing. It is used by:

- the admin ledger’s “if this month closed now” panel (19D);
- the member rank card’s projection (19E) via `statusFor()`;
- `--dry-run` (19C/19F).

It deliberately uses the **current** tree (no cutoff) for an open month and the **period-end** cutoff
for a completed-but-unclosed month. For a period that already has a header (`evaluated`, `paid` or
`skipped`), `preview()` returns the stored header and rows, never a recomputation (so a preview can
never disagree with the ledger).

`statusFor($userId, $period = null)` returns:

```php
[
  'period'        => '2026-11',            // the open month by default
  'enabled'       => true,                 // royalty_enabled
  'payments_enabled' => true,              // royalty_pay_enabled
  'rank'          => 2,
  'rank_name'     => 'Supervisor',
  'directs'       => 10,
  'downline'      => ['leaders' => 10, 'supervisors' => 2, 'managers' => 0],
  'next'          => ['rank' => 3, 'name' => 'Manager', 'need' => 10, 'have' => 2],
  'personal'      => 60000.00,             // MTD PS
  'group'         => 510000.00,            // MTD GS
  'quota'         => 500000.00,
  'group_pct'     => 3.0,
  'personal_pct'  => 5.0,
  'progress'      => 100,                  // GS / quota, capped
  'eligible'      => true,
  'projected'     => 18300.00,             // if the month closed now
  'paid_all_time' => 54900.00,
  'last_paid'     => ['period' => '2026-10', 'amount' => 18300.00],
  'history'       => [ /* recent award rows */ ],
]
```

`statusFor()` must use the same `RoyaltyRank::map()` / `withVolumes()` and the same `awardCents()`
as the engine, so the member page cannot promise a different amount from the close. A test compares
the projected amount with a real `preview()` for the same fixture.

### 14.12 Reconciliation

`RoyaltyService::reconcile()` mirrors `CommissionService::reconcile()` and
`UnilevelService::reconcile()`:

| Check | Query |
|---|---|
| Total | `SUM(amount) WHERE status='paid'` vs `SUM(amount) FROM ewallet_ledger WHERE ref_type='royalty'` (from `royalty_ledger_since`) |
| Missing ledger | a paid award with `ledger_id IS NULL` or a missing ledger row |
| Orphan ledger | a `ref_type='royalty'` row whose `ref_id` is not an award |
| Drift | `ewallet_ledger.amount <> royalty_awards.amount` for a paid award |
| Period totals | `paid_count`, `paid_total`, `award_count`, `award_total`, `skipped_count` vs the rows |
| Period state | **mismatch:** a `paid` period with a `pending` award, a `skipped` period with rows, an award with no header. **Warning:** an `evaluated` period with no `pending` award (a crash after the last batch; the next run finalises it) |
| Orphan award | an award with no period header |
| Rank sanity | `rank_level` 2–4; `personal_volume <= group_volume`; `amount > 0` for a paid award |
| Cycle warning | member accounts unreachable from a root (not a money mismatch) |
| Legacy | `ref_type='royalty'` rows before `royalty_ledger_since` (reported, not failed) |

`bin/wallet_reconcile.php` gains a **Royalty** section; the exit code fails only on `mismatches`, while
`warnings` (the evaluated-no-pending case, cycle accounts) are printed and do not turn a clean
reconciliation into exit 1. `DatabaseResetService`’s integrity map gains the same service so a reset
verifies it too.

The reconcile output shape:

```php
[
  'ok'              => true,          // no mismatches; warnings may be non-empty
  'periods'         => 3,
  'paid_rows'       => 30,
  'paid_total'      => 54900.00,
  'pending_rows'    => 0,
  'pending_total'   => 0.00,
  'skipped_rows'    => 4,
  'ledger_total'    => 54900.00,
  'cycle_members'   => 0,
  'legacy_rows'     => 0,
  'mismatches'      => [],
  'warnings'        => [],
]
```

### 14.13 Cancellation policy

| Moment | Event | Effect |
|---|---|---|
| Before the close | order cancelled | the order is `status='cancelled'`; the volume query excludes it; the month’s GS/PS and the award reflect the smaller volume |
| During the close | a cancellation races the volume query | the close reads a consistent transaction snapshot; whichever state the snapshot sees is what the month pays. The window is seconds in a normal run |
| After evaluation, before payment (`--evaluate-only`) | order cancelled | the award is frozen; `--pay-only` pays it. The review window must be short; the runbook says so |
| After the period is `paid` | order cancelled | the award is unchanged; no clawback (D-13) |
| Any time | package activation cancelled | no effect; activations were never Royalty volume |

- There is **no** `royalty_reversal` wallet movement in v1. A future correction stream would be a
  new plan with its own ledger, not a silent change to this one.
- The admin ledger’s period card records the evaluation time; a cancellation after it is visible in
  the audit trail but does not rewrite the card.

### 14.14 Rollout and enablement

The feature is inert until an operator turns it on. The recommended sequence:

1. **Back up** the database (`mysqldump`) and the working tree, outside the repository.
2. **Migrate** with `database/migrations/023_royalty_bonus.sql`; confirm 33 tables / 73 settings,
   `build_install_sql --check` exit 0 and `wallet_reconcile.php` exit 0. No royalty row exists yet.
3. **Deploy the code**; run the full suite and the smoke walk. The royalty pages show history (empty)
   and the settings card; `royalty_enabled` is still `0`.
4. **Schedule** the two jobs (00:15 unilevel, 00:30 royalty) and run
   `php bin/royalty_month_close.php --doctor`; it must be green except “no periods yet”.
5. **Wait for the first full month after the migration** (the baseline rule). While the stream is
   off, the monthly job records a `skipped` period — that is correct and cannot be paid later.
6. **Enable at a month boundary.** To make the month that just ended (if it is at or after the
   baseline) the first paid month, enable **before** the 00:30 close on the 1st; to make the **new**
   month the first, enable **after** the close. Enabling mid-month pays that whole month when it
   closes (the month is the unit), so do not do it casually. Keep `royalty_pay_enabled = 1` for a
   normal launch, or `0` if finance wants to review the first evaluated month before any wallet is
   credited.
7. **First close with the stream on:** run `--dry-run` for the completed month, then
   `--evaluate-only`, inspect the awards in `/admin/commissions?ledger=royalty`, then `--pay-only`.
   From the second month the cron does both.
8. **Monitor** `royalty paid / paid sales`, the rank distribution, the skipped reasons and the pending
   liability for the first three months. Keep the runbook’s weekly `wallet_reconcile.php` check.
9. **If the plan is paused:** set `royalty_enabled = 0` (stops future earning) and/or
   `royalty_pay_enabled = 0` (stops payment), and remove the cron entry. Never drop the tables after a
   row exists; the paid awards are money history. Pending awards remain payable when the stream is
   resumed.

**Rollback of the feature** is the reverse: disable both switches, remove the cron entries, revert the
code and the docs. The migration is additive and its objects stay; a rollback that drops
`royalty_periods`/`royalty_awards` is only acceptable **before any award row exists**.

---

## 15. Security, audit and abuse control

### 15.1 Guards and input handling

| Surface | Guard | Protection | Phase |
|---|---|---|---|
| `/admin/settings` (Commissions card) | `admin` (router) | CSRF on POST; section allow-list (`commissions`); field validation; audit | 19B |
| `/admin/members` (rank column/distribution) | `admin` | read-only; no input | 19B |
| `/admin/commissions?ledger=royalty` (+ export) | `admin` | read-only; export audited; filters validated | 19D |
| `/admin/reports` (+ export) | `admin` | existing range validation; export audited | 19D |
| `/admin/members/{id}` (royalty panel) | `admin` | read-only; scoped to the path id | 19D |
| `/earnings?ledger=royalty` (+ export) | `member` | every query `WHERE user_id = ?`; `?user_id=` ignored | 19E |
| `/dashboard` (royalty tile) | `member` | own data only | 19E |
| `bin/royalty_month_close.php` | CLI / OS user | filesystem permissions; lock; no web exposure | 19C |
| `public/cron/royalty_month_close.php` | loopback or `CRON_KEY` | shared `CronAccess` rules, no session | 19F |

- No new routes means no new guard to forget (D-16). The cron file is a standalone entry point like
  the five existing jobs, not a routed page.
- All SQL is prepared; table and column names are constants. The only dynamic identifiers are
  `IN (?, ?, …)` placeholder lists built from counted ids.
- Every view escapes with `e()`; reason strings are truncated to 120 characters before storage and
  rendered as text.
- The settings numbers are validated server-side (`integer`, `decimal:2`, `decimal:3`, ranges,
  cross-field ladder rules) *and* constrained by the input attributes; the server never trusts the
  client’s computed “own purchase” column.
- `--period` is validated against `^[0-9]{4}-(0[1-9]|1[0-2])$`; `--limit` and `--max-batches` are
  clamped; `--log-dir` is a local path supplied by an operator with filesystem access (the same
  contract as the existing jobs).

### 15.2 Money integrity

1. **One transaction per evaluation; one per payment batch.** Award rows + header commit together;
   wallet credit + award update + header counters commit together. A crash never leaves a paid
   wallet row pointing at a pending award (the credit is first, and the batch rolls back if the
   update fails).
2. **Immutable history.** `royalty_awards` has no update path except `status`, `ledger_id`,
   `credited_at` on the `pending → paid` transition. The money columns, rank, GS, PS, quota and rates
   never change. `royalty_periods` has no update path except the payment counters and the final
   `paid` fields.
3. **Idempotency at five layers** (§14.6): period PK, award unique key, conditional payment update,
   wallet unique key, cron lock.
4. **Reconciliation.** `wallet_reconcile.php` fails non-zero if the Royalty ledger, the award ledger
   and the period headers disagree.
5. **Segregation of duties.** Royalty is machine-paid at the close; no admin approves an individual
   award, so there is no “admin approves their own payout” path. The settings that determine the
   rates are admin-only and audited. The job is CLI/cron, not a web form.
6. **No hidden write path.** `RoyaltyAward::markPaid()` is the only update; only `RoyaltyService::pay()`
   calls it; only the job calls `pay()`; the admin/member surfaces are read-only. A test greps the
   diff for a second writer.
7. **No negative balances.** Royalty only credits; it never debits. A payout request can spend the
   credited money through the existing payout service, which has its own balance rules.

### 15.3 Abuse and economic controls

The reference plan is aggressive. The engine implements it faithfully; this table is the control
surface and the monitoring list, not a tuning recommendation.

| Pattern | Control | Detect |
|---|---|---|
| **Rank grinding**: one person creates ten accounts to become a Leader, then ten Leaders to become a Supervisor | Registration requires a unique username/email and a sponsor; there is no KYC in this build. The **quota** is the real gate: a fake Supervisor still needs ₱500,000 of paid group sales to be paid | Rank distribution vs sales; members with many zero-purchase directs; `/admin/reports` |
| **Self-funded quota**: a member buys ₱500,000 personally to meet the Supervisor quota and earns ₱40,000 | The reference explicitly allows it (GS includes PS); the company receives the ₱500,000 of revenue | `personal_volume / group_volume` near 100%; a Supervisor whose PS alone meets the quota |
| **Personal sales paid twice**: a Director’s own purchase earns 25% | The reference’s formula; disclosed in the settings ladder and the member copy | `royalty paid / paid sales` ratio; top self-purchasers |
| **Collusive chains** (A sponsors B, B sponsors A) | Impossible without re-parenting (the app has none); the rank pass has a visited set and excludes cycles | `cycle_members` in reconcile/doctor |
| **Fake or unpaid orders** | Only `paid` orders count; a pending order is not volume | The volume query counts `paid_at` only |
| **Refund/cancel after close** | No clawback; the closed period is immutable | The audit trail and the period card; D-13 is a disclosed policy |
| **A member edits their own rank/rates** | Admin-only settings, CSRF, audit | `settings.update` |
| **An operator enables the stream and “catches up” years of volume** | The baseline is the first full month after migration; earlier months have no header and are never payable | `pendingPeriods()` starts at the baseline |
| **A paused payment is forgotten** | `--doctor` warns; the operations panel shows pending; the header’s `award_total − paid_total` is the liability | Weekly runbook check |
| **A closed month is recalculated by hand** | No reopen path; the runbook forbids SQL edits to a closed award | Reconcile detects a header/row drift |
| **Cron abuse (closing the current month)** | The service and the job refuse current/future; a final period is a no-op | `P19C-T42/T43` |
| **A huge month locks wallets for a long time** | Payment in batches of 500, ascending user id, one transaction per batch; `--max-batches` bounds a run | Job duration/log; `P19C-T29` |

**The load, in numbers.** With the reference terms and a Director:

| Event | Rate | On ₱5,000,000 group |
|---|---:|---:|
| Supervisor group | 3% | ₱150,000 (at the ₱500k quota: ₱15,000) |
| Manager group | 5% | ₱250,000 (at the ₱1M quota: ₱50,000) |
| Director group | 10% | ₱500,000 (at the ₱5M quota: ₱500,000) |
| Director own purchase, effective | 25% | — |

Royalty is **stacked on top of** the repeat-purchase ladder (up to 29% of the P.V. base) and the
package referral commissions. A single order can therefore pay the upline chain and feed a group
override. The company’s margin must absorb the sum; the build has no cost-of-goods model, so the
only observable is `royalty paid / paid sales` in `/admin/reports`. The plan’s defaults are the
reference’s, the stream ships off, and the settings card shows the “own purchase” effective rate
before an operator enables it. §17 risk 1 is the standing business risk.

**What the plan does not add:** no KYC, no anti-pyramiding check, no cap, no self-purchase exclusion,
no “ten real customers” QA gate. Those are business/legal decisions outside the reference
specification; a future plan can add them as a gate on eligibility without changing the ledger.

### 15.4 Audit trail

| Event | Action | Meta | Phase |
|---|---|---|---|
| Royalty settings saved | `settings.update` (existing) | section `commissions`, changed `royalty_*` keys | 19B |
| Period evaluated | `royalty.period.evaluate` | period, enabled, members/rank counts, award/paid/skipped, totals, ladder snapshot | 19C |
| Period paid (per batch) | `royalty.period.pay` | period, batch rows, amount, remaining pending | 19C |
| Period skipped (stream off) | `royalty.period.skip` | period, note | 19C |
| Partial run stopped by `--max-batches` | `royalty.period.pay` (partial) | period, batches, remaining | 19C |
| Ledger export | `royalty.export` | tab, filters, row count | 19D |
| Member CSV export | `royalty.export` | member id, filters, row count | 19E |
| Close run | the job log + the audit rows above | period(s), counts, totals, status, paused | 19C/19F |

The audit rows are append-only and shown on `/admin/audit` with their action vocabulary, so a finance
review can reconstruct who changed a rate and when, and which periods were evaluated or paid.

### 15.5 Privacy and data minimisation

- The admin ledger shows member username/full name; the member surfaces show only the session
  member’s own rows.
- No bank/GCash/Maya/USDT account data is copied into a Royalty row; the wallet credit is the only
  money movement and the payout uses the existing profile/payout path.
- The job log records ids and totals, never an account number or a password.
- The CSV exports are admin/member-scoped and audited; they contain no credentials.

---

## 16. Testing harness, fixtures and QA protocol

### 16.1 Harness

The project runs a hand-rolled suite: `php system/tests/run.php` executes every file in `tests/Cases`
and prints `Cases / Passed / Failed`; `tests/bootstrap.php` provides `assert_*`, `test_pdo()`,
`test_reset_app_database()`, `test_run_sql_file()`, `test_run_isolated()`, `test_order()`,
`test_deliver_order()`, `test_signed_in_as()` and the other helpers. Each case gets a clean database
(or a transaction that is rolled back). The HTTP layer is exercised through `View::capture()` and the
controllers’ public context methods; `tests/smoke.ps1` covers the real URLs.

**Baseline at `p19-start`:** **1,008** cases, **229** smoke checks, `build_install_sql --check` exit
0, `wallet_reconcile.php` exit 0, 31 tables, 57 settings, migration 022, `APP_PHASE` 17.

**Test ID convention.** `P19X-Tn`, where `X` is the phase letter. A phase’s cases live in files named
for the phase’s subject, and every case name starts with its ID:

| File | Phase | Subject |
|---|---|---|
| `RoyaltyFoundationTest.php` | 19A | migration, enum, settings, install/reset parity |
| `RoyaltyConfigurationTest.php` | 19B | settings card, validation, audit |
| `RoyaltyRankTest.php` | 19B | the rank engine (trees, depth, cycles, cutoff) |
| `RoyaltyAdminMemberViewTest.php` | 19B/19D | rank column/distribution; member royalty panel |
| `RoyaltyEngineTest.php` | 19C | evaluate/pay/volume/formula/idempotency |
| `RoyaltyCloseJobTest.php` | 19C | the CLI close, batches, resume |
| `RoyaltyReconcileTest.php` | 19C | reconcile and `wallet_reconcile` section |
| `RoyaltyAdminViewTest.php` | 19D | ledger tab, period cards, filters, CSV |
| `RoyaltyReportTest.php` | 19D | report cards and export |
| `RoyaltyMemberViewTest.php` | 19E | earnings tab, rank card, scoping, copy |
| `RoyaltyCronTest.php` | 19F | web entry, doctor, lock, exit codes |
| `RoyaltyReleaseTest.php` | 19G | `APP_PHASE`, docs counts (or the existing HardeningTest) |

`php tests/run.php Royalty` selects the whole stream; `php tests/run.php` runs everything.

### 16.2 Fixture trees

**A. Small-threshold tree (the default for rank/payout tests).** Set the settings to
`leader_directs=2`, `supervisor_leaders=2`, `manager_supervisors=2`, `director_managers=2`,
`royalty_enabled=1`, `royalty_pay_enabled=1`, `royalty_ledger_since` before the test period. Build:

```
director (id 10)
├── mgrA (11)
│   ├── supA (13)
│   │   ├── leadA (15)
│   │   │   ├── a1 (17)
│   │   │   └── a2 (18)
│   │   └── leadB (16)
│   │       ├── b1 (19)
│   │       └── b2 (20)
│   └── supB (14)  … same 7-member shape
└── mgrB (12)      … same 15-member shape
```

With thresholds 2, `director` is a Director (two Managers), each Manager has two Supervisors, each
Supervisor has two Leaders, each Leader has two directs. **31 members** (ids 10–40) suffice for the
full ladder: `director` + two 15-member Manager subtrees. The test asserts the exact rank of all 31.

**B. Reference tree (the default-threshold tests).** `sup` (2) with ten Leaders (3–12), each Leader
with ten directs (13–112) = **111 members**. This is the §6.7 fixture; it proves the 10/10 gate with
the production defaults without building 11,111 members. The Director rank is tested with the
small-threshold tree, not by building 11,111 rows.

**C. Chain tree (depth/cutoff tests).** 20 members in a single chain, each sponsoring the next. It
proves the rank pass has no 10-level cap and that `created_at` cutoff excludes the tail.

**D. Cycle fixtures.** A→B→A, and a self-sponsored member. Both must terminate and be unranked.

**E. Staff fixtures.** An `admin` account with ten member directs; a member whose `sponsor_id` is the
admin. The admin is never ranked; the member is a root.

All money fixtures use **paid orders in a fixed completed period**:

```php
$period = RoyaltyPeriod::previous(RoyaltyPeriod::periodOf());
[$start] = RoyaltyPeriod::bounds($period);
$order = test_order($memberId, [['product_id' => $p1, 'quantity' => 2]], 'paid', [
    'paid_at' => $start . ' 12:00:00',
]);
```

The helper sets the line totals; a second helper may set `users.created_at` to the period start for
the cutoff tests. Tests must not depend on the wall-clock date: they derive the period from
`RoyaltyPeriod::previous(periodOf())`.

### 16.3 Money fixture table

Reference rates (3/5, 5/10, 10/15) and quotas (500k/1M/5M). **Single rounding** on the combined
amount.

| Rank | GS | PS | Computation | Amount |
|---|---:|---:|---|---:|
| Supervisor | 510,000.00 | 60,000.00 | 15,300 + 3,000 | **18,300.00** |
| Supervisor | 500,000.00 | 0.00 | 15,000 | **15,000.00** |
| Supervisor | 500,000.00 | 500,000.00 | 15,000 + 25,000 | **40,000.00** |
| Supervisor | 499,999.99 | 60,000.00 | below quota | **0.00** (skipped) |
| Supervisor | 500,000.55 | 60,000.55 | 15,000.0165 + 3,000.0275 = 18,000.044 | **18,000.04** |
| Manager | 1,000,000.00 | 0.00 | 50,000 | **50,000.00** |
| Manager | 1,200,000.00 | 100,000.00 | 60,000 + 10,000 | **70,000.00** |
| Director | 5,000,000.00 | 0.00 | 500,000 | **500,000.00** |
| Director | 5,000,000.00 | 100,000.00 | 500,000 + 15,000 | **515,000.00** |
| Director | 4,999,999.99 | 100,000.00 | below quota | **0.00** (skipped) |
| Leader | 10,000,000.00 | any | not a paying rank | **0.00** |
| Member | any | any | not a paying rank | **0.00** |

The `555.55`-style per-component rounding case is not used here: the plan rounds **once**, so the
reference simulator’s per-level rounding examples do not apply. The only rounding test that matters
is the combined half-up case above, plus a 3-decimal rate case:

| GS | PS | Group % | Personal % | Computation | Amount |
|---:|---:|---:|---:|---|---:|
| 10,000.00 | 0.00 | 3.125 | 5.000 | 312.50 + 0 | **312.50** |
| 10,000.00 | 3,333.33 | 3.125 | 5.000 | 312.50 + 166.6665 = 479.1665 | **479.17** |

### 16.4 Existing cases to update, by phase

| Phase | File | Why |
|---|---|---|
| 19A | `MigrationTest.php` | add `023_royalty_bonus.sql` to the ordered list; a new P19A-T3 case; migrated-vs-fresh stays green; an idempotent re-run and an ENUM assertion |
| 19A | `InstallTest.php` | `tables_created 33`, `settings_rows 73` |
| 19A | `InstallSqlTest.php` | the header/footer count and any count assertion |
| 19A | `SeedParityTest.php` | no edit expected: it fails unless seed 001 carries all sixteen keys — a useful guard |
| 19A | `DbResetTest.php` | the 31-name helper becomes 33; the zero list gains the two tables; the auto-increment list gains `royalty_awards` |
| 19A | `DbTargetTest.php` / `SqlScriptTest.php` | the hard-coded table count 31 → 33 |
| 19A | `WalletTest.php` | `REF_TYPES` contains `royalty` and the ENUM matches (P5-T8) |
| 19B | `SettingsPageTest.php` | the new fields/toggles, the default ladder, a save round-trip, validation |
| 19B | `AdminSettingsLiveTest.php` | the `settings-commissions` fragment contains the Royalty sub-block |
| 19B | `AdminMemberLiveTest.php` | the rank column and distribution |
| 19B | `ProductTest.php` / `ProductViewTest.php` | regression only: the product pages have no Royalty UI |
| 19C | `WalletTest.php` | a `royalty` credit is accepted and labelled |
| 19C | `AuditLogTest.php` | the three new actions are recorded |
| 19C | `CronTest.php` | a placeholder until 19F’s `RoyaltyCronTest`; keep the existing job cases green |
| 19D | `ReportTest.php` / `ReportViewTest.php` | the royalty cards and the `royalty` export type |
| 19D | `AdminCommissionLiveTest.php` | the third tab, its fragments and its ETag |
| 19D | `AdminDashboardLiveTest.php` | the Royalty KPI |
| 19E | `MemberEarningsLiveTest.php` | the third tab, the rank card and its live revision |
| 19E | `MemberDashboardLiveTest.php` | the royalty tile |
| 19E | `LaunchTest.php` / `ShopLiveTest.php` | regression only: no shop change |
| 19F | `CronTest.php` | the shared cron contract (exit codes, lock, doctor) still holds |
| 19G | `HardeningTest.php` | `APP_PHASE === 19` |
| 19G | `tests/smoke.ps1` | the Phase 19 URL checks accumulated by 19B–19F (19 new checks) |

### 16.5 Cron cases (`RoyaltyCronTest.php`, phase 19F)

| # | Case | Expected |
|---|---|---|
| 1 | Web entry, remote without key | 403, no work |
| 2 | Web entry, loopback | 200, same numbers as CLI |
| 3 | Web entry with `CRON_KEY` | allowed; wrong key 403 |
| 4 | `--dry-run` on a database with a completed month | nothing written, output lists the month and totals |
| 5 | Real run closes the previous month | header + awards + wallet credits |
| 6 | Second run | `closed=0`/“nothing to close”, exit 0 |
| 7 | Catch-up: two missed months | both closed in one run, oldest first |
| 8 | `--period` current/future | exit 1, message |
| 9 | Lock held | `[SKIP ]`, exit 0 |
| 10 | `--max-batches=1` on a multi-batch month | header `evaluated`, exit 0, resume works |
| 11 | Log line | one line with period/counts/totals/paused |
| 12 | `--json` | machine-readable payload with the same numbers |
| 13 | `--doctor` clean | exit 0, all sections |
| 14 | `--doctor` missing table/clock skew | exit 1 |
| 15 | `.cmd` | runs the CLI with the same flags |
| 16 | Paused payment | evaluate writes pending; no wallet; doctor warns |

### 16.6 The QA pack

`docs/qa/phase-19-royalty.md` is created by 19A with **section A only**, and each later phase appends
its section before its gate. It is never rewritten, only appended. Each section:

- states the phase’s prerequisites (the previous tag, the fixtures) and its expected suite count;
- lists every check as `QA19X-nn` with the exact URL or command, the exact expected number, and one
  line on what to do when it fails;
- ends with a sign-off line (tester, date, evidence file names).

Evidence lives in `docs/qa/evidence/phase-19/`, one file per check (`QA19A-01.png`,
`QA19C-04.txt`, …), indexed by that folder’s `README.md`. A check that produces only a count is
captured as a text transcript rather than a screenshot.

`docs/qa/README.md` and `docs/qa/sign-off-sheet.md` gain one Phase 19 row when 19A lands, and the row
is finalised (check count, time needed, signed) by 19G.

### 16.7 Regression policy

The suite is cumulative. After each phase, every case from every earlier phase still runs, and the
phase’s gate requires the **whole** suite green — not just its own file. Four regressions matter most
and are asserted by name:

- `P19D-T2` — the package commission tab is unchanged.
- `P19D-T3` — the repeat-purchase commission tab is unchanged.
- `P19E-T2` / `T3` — the package and repeat earnings tabs are unchanged.
- `P19A-T13` — no producer exists before 19C (and the 19C rewrite asserts the engine is the only
  producer).

The referral and repeat-purchase ledgers, the wallet statement and the payout path must be unchanged
in behaviour; the suite’s own counts and the named regression cases enforce it.

### 16.8 Acceptance matrix

| Scenario | Expected | Test |
|---|---|---|
| Reference Supervisor 510k/60k | ₱18,300.00 | `P19C-T3` / Appendix B |
| Supervisor exactly at quota | ₱15,000.00 | `P19C-T9` |
| Supervisor below quota | ₱0, skipped | `P19C-T8` |
| Manager at quota | ₱50,000.00 | `P19C-T12` |
| Director at quota | ₱500,000.00 | `P19C-T12` |
| Director with PS 100k | ₱515,000.00 | `P19C-T13` |
| Leader 10M GS | ₱0 | `P19C-T10` |
| No stacking | one award, Director rates | `P19C-T12` |
| One rounding | ₱18,000.04 | `P19C-T16` |
| Stream off | skipped period, no money | `P19C-T2` |
| Payment paused | pending, no wallet | `P19C-T30` |
| Rerun | no double pay | `P19C-T5` |
| Partial batch | resume, no double | `P19C-T29` |
| Catch-up | oldest first, own cutoff | `P19C-T41` |
| Reset | zero rows, reconcile clean | `P19A-T11`, `P19C-T47` |
| Install parity | 33 tables / 73 settings | `P19A-T3/T10` |

---

## 17. Risks and mitigations

| # | Risk | Likelihood | Impact | Mitigation | Detected by |
|---:|---|---|---|---|---|
| 1 | **The plan pays more than the margin allows** — Royalty stacks on the repeat-purchase ladder and referral commissions | medium | high | The stream ships off; the settings card shows the “own purchase” effective rate; `/admin/reports` reports `royalty paid / paid sales`; quotas and rates are the knobs; run a small cohort first | 19B gate, 19D reports, weekly runbook |
| 2 | A rank is ground with ten dummy accounts | medium | medium | Ranks count people by design; the quota still requires real paid volume; the rank distribution report shows zero-purchase branches | 19D reports |
| 3 | A member self-funds the quota and withdraws most of it | medium | medium | The reference allows it; the company receives the sales; monitor PS/GS ratio and the top self-purchasers | 19D reports |
| 4 | The close is missed or the cron is late | medium | low | The close is a job, not a pre-condition: a late run uses the period-end cutoff and catches up exactly; the operations panel shows pending periods | 19F doctor, 19C catch-up test |
| 5 | A payment batch fails mid-month | low | medium | One transaction per batch; header stays `evaluated`; `--pay-only` resumes; the wallet unique key prevents a double credit | 19C-T29 |
| 6 | A cancelled order after the close | medium | low | D-13: the closed period is immutable; the policy is disclosed in the guide and the member copy; cancellations are off by default (`shop_cancel_enabled = 0`) | 19C-T19, 19D |
| 7 | A rate/threshold edit mid-month changes a rank unexpectedly | medium | medium | The period snapshot records what was used; the card warns that ranks are derived; the audit row names the change | 19B-T11 |
| 8 | The rank pass is slow or memory-heavy on a large network | low | medium | O(n) one-query pass; a 50k-member budget; the admin/member pages are live-cached; a future snapshot table if exceeded | 19G acceptance |
| 9 | A corrupt sponsor chain (cycle/self) inflates or crashes a rank | low | medium | Visited set, root handling, reconcile warning; `P19B-T22` | 19C reconcile |
| 10 | Wallet deadlock between the close and a payout | low | medium | Header lock first, ascending `user_id`, one batch per transaction | 19C-T31 |
| 11 | Timezone drift between PHP and MySQL | low | high | `APP_TIMEZONE`/`DB_TIMEZONE` both +08:00; `--doctor` compares clocks; the period is stored as `YYYY-MM` | 19F doctor |
| 12 | An operator expects a per-order royalty and reports a missing payment | medium | low | The admin/member copy says “once a month, after the close”; the ledger has one row per month; the guide says it | 19D/19E gates |
| 13 | An operator expects the simulator’s Harvest/QA rules | medium | low | §12.11 and the copy deck state the four ranks and no QA gate; older docs are explicitly out of scope | 19G cold read |
| 14 | A closed period is “fixed” by hand | low | high | No reopen path; the runbook forbids SQL edits to closed awards; reconcile detects a header/row drift | 19C reconcile |
| 15 | A stale count in the docs (tables/settings/cases/smoke) | medium | low | `P19G-T2`, the cold read, and the phase-log counts | 19G |
| 16 | A phase is declared done on partial evidence | medium | high | The universal gate (§2.4) requires the full suite, smoke, a tester who did not write the code, and a filled Appendix H row | every gate |
| 17 | A forward stub hides in a phase (a table with no writer, a switch with no reader) | medium | medium | §2.1’s five tests; the explicit **Not yet** list in every phase; 19A’s “no producer” test | review of each diff |
| 18 | The two monthly jobs confuse operators | medium | low | Two named jobs, two docs sections, a 15-minute gap, one runbook drill; royalty never reads unilevel tables | 19F docs |

---

## 18. Open questions

These need a business decision before the phase named in **Blocks**; the plan’s defaults are in
brackets.

| # | Question | Plan default | Blocks |
|---:|---|---|---|
| 1 | Is Royalty volume the **paid line total** or a product **P.V.** base? | **Paid line total** (D-03); it matches the reference’s “purchases” and keeps the quota a sales quota | 19C |
| 2 | Should an order cancelled **after** the close claw back the award? | **No** (D-13); the period is immutable. A future adjustment stream would be a separate plan | 19C |
| 3 | Should eligibility use the member’s status **at the close** or at the period end? | **At the close** (the app has no membership history); documented as a limitation | 19C |
| 4 | Should staff accounts count toward ranks or GS? | **No** (D-05) | 19B |
| 5 | Should a suspended member still count as a person for their upline’s rank? | **Yes** (reference §6.2); only the suspended member’s own payout is skipped | 19B |
| 6 | Should the thresholds be editable, and when do changes apply? | **Yes**; the next close (the current period snapshot is fixed) | 19B |
| 7 | Should a Director receive the lower-rank rates as well? | **No** (D-11); one rank, one triple | 19C |
| 8 | Should a member’s first (partial) month be prorated? | **No**; a month is a month, and the baseline skips the partial install month | 19C |
| 9 | Is there a per-member, per-period or network cap? | **No cap in v1** (reference §9.2); quotas and rates are the controls | 19C |
| 10 | Should the close pay automatically or wait for an admin approval? | **Auto-pay**; `--evaluate-only` + `royalty_pay_enabled` give a review window without a second approval workflow | 19C |
| 11 | Should a mid-month enable pay the whole month? | **Yes** for the first full month after the baseline; the runbook recommends enabling on the 1st | 19C |
| 12 | Should rank history be stored? | **No** in v1 (D-18); award rows are the paid-month record. A `member_ranks` snapshot is a separate plan | 19B |
| 13 | Should the repeat-purchase **Active** flag gate Royalty? | **No**; the reference has no Active rule for Royalty | 19C |
| 14 | Should package activations count as Royalty volume? | **No** (D-03); they are membership purchases, not shop sales | 19C |
| 15 | Should a QA/retail-customer gate or a Chairman rank be added? | **No** for this plan; the reference has four ranks and no QA gate. A future plan can add a gate without changing the ledger | 19G |
| 16 | Should the job run on the 1st at 00:30 or later to wait for late manual verifications? | **00:30 on the 1st**; a manual order verified on the 1st counts in the **new** month, not the closed one. If the business wants a grace window, move the cron to the 2nd; the period is still the calendar month | 19F |
| 17 | Should the admin page offer a “Run close now” button? | **No**; the close is CLI/cron. A button would need CSRF, a long request, a retry story and a second authorization path | 19F |
| 18 | Should the operations panel show the pending liability? | **Yes**; `award_total − paid_total` and the pending count are shown, read-only | 19F |

---

# Part IV — Appendices

## Appendix A — The reference ladder and the default preset

[`docs/comp-plan_addons.md`](../docs/comp-plan_addons.md) §6.2 (`RN`, `RANK_QUOTA`, `GROUP_PCT`,
`PERSONAL_PCT`) and §9.1, with every default preserved:

| Rank | Level | Requirement (default) | Royalty-eligible? | Monthly group quota | Group % | Personal % | Own purchase |
|---|---|---:|---|---:|---:|---:|---:|
| Member | 0 | automatic | no | — | — | — | — |
| Leader | 1 | 10 directs | **no** | — | — | — | — |
| Supervisor | 2 | Leader + 10 downline Leaders | yes | ₱500,000 | 3% | 5% | 8% |
| Manager | 3 | Supervisor + 10 downline Supervisors | yes | ₱1,000,000 | 5% | 10% | 15% |
| Director | 4 | Manager + 10 downline Managers | yes | ₱5,000,000 | 10% | 15% | 25% |

- “Own purchase” is `group % + personal %` because PS is inside GS and is paid a second time by the
  personal rate. It is shown on the settings card and in the member rank card.
- The ladder is **not** cumulative across ranks: a Director receives 10% of GS and 15% of PS, not the
  Supervisor/Manager rates as well (D-11).
- The default preset is loaded from the migration; an existing database upgraded by `023` receives
  the same values because the settings insert is `ON DUPLICATE KEY UPDATE key_name = key_name` (it
  never overwrites an operator’s value).
- The **load reference preset** button described for the repeat-purchase ladder is **not** needed
  here: the defaults are seeded. The settings card offers a **Restore reference defaults** action
  (client-side prefill only until saved) for an operator who has changed the ladder. It is optional
  and does not bypass validation.

**Rank names and badges.** Member (secondary), Leader (info), Supervisor (primary), Manager
(warning), Director (success). The badge text is the rank name; the level is shown in the rank card
(“Supervisor · rank 2 of 4”).

---

## Appendix B — Worked scenarios

### B.1 The reference example — Supervisor, GS ₱510,000, PS ₱60,000

Tree: `sup` with ten Leaders, each with ten directs (111 members). Each of the ten Leaders buys
₱45,000 (GS from the ten directs of the Supervisor: 10 × ₱45,000 = ₱450,000); `sup` buys ₱60,000.

| Quantity | Value |
|---|---:|
| PS (`sup`) | ₱60,000.00 |
| GS (`sup`) | ₱510,000.00 |
| Quota | ₱500,000.00 — met |
| Group component | `0.03 × 510,000 = ₱15,300.00` |
| Personal component | `0.05 × 60,000 = ₱3,000.00` |
| **Award** | **₱18,300.00** |

Each of the ten Leaders is a Leader (rank 1), not a paying rank: **₱0**. The ten directs of each
Leader are Members: **₱0**.

### B.2 The same tree, ₱410,000 GS

| Quantity | Value |
|---|---:|
| PS | ₱60,000.00 |
| GS | ₱410,000.00 |
| Quota | ₱500,000.00 — missed by ₱90,000 |
| **Award** | **₱0.00** — `skipped`, `group sales below quota` |

The personal component is **not** paid on its own; the quota is all-or-nothing.

### B.3 Director with a personal purchase

| Quantity | Value |
|---|---:|
| PS | ₱100,000.00 |
| GS | ₱5,000,000.00 (includes PS) |
| Quota | ₱5,000,000.00 — met exactly |
| Group component | `0.10 × 5,000,000 = ₱500,000.00` |
| Personal component | `0.15 × 100,000 = ₱15,000.00` |
| **Award** | **₱515,000.00** |

A downline purchase in the same group earns only the 10% group rate; the Director’s own purchase
earns 25%. This is the reference’s double-count, disclosed in Appendix A.

### B.4 Leader with ₱10,000,000 GS

| Quantity | Value |
|---|---:|
| GS | ₱10,000,000.00 |
| Rank | Leader (1) — not a paying rank |
| **Award** | **₱0.00** |

The Leader’s ten directs make it a Leader; the volume is irrelevant to Royalty until the rank is
Supervisor.

### B.5 Supervisor self-funds the quota

| Quantity | Value |
|---|---:|
| PS | ₱500,000.00 |
| GS | ₱500,000.00 (self only) |
| Quota | ₱500,000.00 — met |
| Group component | ₱15,000.00 |
| Personal component | ₱25,000.00 |
| **Award** | **₱40,000.00** |

The company received ₱500,000 of sales; the member earns ₱40,000 of Royalty on their own purchase.
This is the reference’s “self-fundable” quota and the main economic-abuse vector (§15.3).

### B.6 One rounding

GS ₱500,000.55, PS ₱60,000.55, Supervisor rates 3% / 5%:

```
0.03 × 500,000.55 = 15,000.0165
0.05 ×  60,000.55 =  3,000.0275
sum               = 18,000.0440  →  ₱18,000.04
```

The combined amount is rounded once, half-up. Rounding each component first would give
`15,000.02 + 3,000.03 = ₱18,000.05`, which the plan does **not** pay.

### B.7 Stream off

`royalty_enabled = 0`, a completed month with ₱1,000,000 GS:

- the close writes one `royalty_periods` row, `status='skipped'`, `enabled=0`,
  `note='royalty stream off'`, all counts/totals 0;
- no award rows, no wallet credits;
- the month can never be paid later (D-15). Enabling next month pays next month, not this one.

### B.8 Payment paused

`royalty_pay_enabled = 0`, a completed month with a qualifying Supervisor:

- `--evaluate-only` (or the default `close()`’s evaluate step) writes a `pending` award;
- `pay()` returns `paused`, no wallet credit;
- the header stays `evaluated`, `award_total` includes the pending amount, `paid_total = 0`;
- re-enabling and `--pay-only` credits it once.

### B.9 Catch-up over two months

Install on 2026-11-15 (`royalty_ledger_since`). First payable month: **2026-12**. The cron does not
run in January or February. On 2027-03-01:

- `pendingPeriods()` returns `['2026-12', '2027-01', '2027-02']` (2026-11 is before the baseline and
  is never closed);
- each period is evaluated with its own cutoff and volume window, oldest first;
- the March run pays December, January and February in three separate period headers, all using the
  ladder in force when the **March run evaluates them**. If a rate changed in February and was not
  closed month by month, the new rate applies to all three months — the snapshot is taken at
  evaluation, not at the period end;
- if the business wants the rate that was in force **during** each month, that is a different rule
  (Q6). The plan’s snapshot is evaluation-time, so the runbook says: run the close before changing a
  rate, or accept that a catch-up uses the current ladder.

> **Note.** The rate snapshot is taken when the period is **evaluated**, not when it ended. A
> catch-up after a rate change applies the new rates to the old months. This is intentional: the
> close is the moment the company commits the terms, and the snapshot records exactly what was used.
> If the business needs period-end rates, it must run the close on time; the doctor and the operations
> panel warn about pending periods.

### B.10 Cancellation before and after the close

| Moment | GS before | GS after | Award |
|---|---:|---:|---|
| A ₱100,000 order is cancelled **before** the close (Supervisor GS 500k → 400k) | ₱500,000 | ₱400,000 | **₱0** (below quota) |
| The same order is cancelled **after** the close (award already ₱15,000) | ₱500,000 | not recomputed | **₱15,000** unchanged |

### B.11 Minimum trees with the reference thresholds

| Rank | Smallest subtree | Shape |
|---|---:|---|
| Leader | 11 | self + 10 directs |
| Supervisor | 111 | self + 10 Leaders × (1 + 10 directs) |
| Manager | 1,111 | self + 10 Supervisors × 111 |
| Director | 11,111 | self + 10 Managers × 1,111 |

Each is a minimum under the most compact placement; the same rank can exist in a much larger tree.
The `P19B-T14…T16` tests use the small-threshold tree (2/2/2/2) to avoid building 11,111 rows.

### B.12 Rank derivation on a small tree

Thresholds 2/2/2/2, the fixture in §16.2 A:

| Member | directs | Leader? | Leader+ below | Supervisor? | Supervisor+ below | Manager? | Manager+ below | Director? | Rank |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| director | 2 | yes | 14 | yes | 6 | yes | 2 | yes | Director (4) |
| mgrA | 2 | yes | 6 | yes | 2 | yes | 0 | no | Manager (3) |
| supA | 2 | yes | 2 | yes | 0 | no | 0 | no | Supervisor (2) |
| leadA | 2 | yes | 0 | no | 0 | no | 0 | no | Leader (1) |
| a1 | 0 | no | 0 | no | 0 | no | 0 | no | Member (0) |

The counts exclude self. `director` has 14 Leader-or-above members below (2 Managers + 4 Supervisors +
8 Leaders), 6 Supervisor-or-above (2 Managers + 4 Supervisors) and 2 Managers; `mgrA` has 6, 2 and 0;
`supA` has 2, 0 and 0. The test asserts the whole fixture row by row; this table is the same tree, not
a second fixture.

---

## Appendix C — Copy deck

The exact strings the views use. A copy change is a UI change; it must keep the four claims:
**monthly close**, **group sales = own + team**, **Leader is not paid**, **the quota is
all-or-nothing**.

### C.1 Settings card

- Card title: **Royalty bonus**
- Toggle 1: **Enable the Royalty bonus** — “Off by default. When off, the monthly close records a
  skipped month and never pays a Royalty award.”
- Toggle 2: **Pay Royalty awards** — “Pause payments without losing evaluated awards. Pending awards
  stay pending until this is on.”
- Requirements block: **Rank requirements** — “Ranks are derived from the Team chain every time they
  are shown. Raising a requirement can demote members at the next close.”
- Quotas block: **Monthly group quota** — “Group sales are the member’s own paid purchases plus every
  member below them, at any depth, in the calendar month. Missing the quota by one centavo pays
  nothing.”
- Rates block: **Payout rates** — “Group % applies to group sales. Personal % applies again to the
  member’s own purchases, which are already inside group sales.”
- Ladder note: **Leader is a rank, not a royalty rank. The first rank that pays is Supervisor.**
- Warning: **A Director’s own purchase earns 25% (10% group + 15% personal). Review the plan
  economics before enabling.**

### C.2 Admin ledger

- Title: **Royalty bonus**
- Subtitle: “One award per member per closed month. Royalty is calculated from paid shop orders at
  the month close — never per order.”
- Period card labels: **Evaluated**, **Paid**, **Skipped (stream off)**; **Pending**, **Paid**,
  **Skipped** rows.
- Empty state: “No Royalty periods yet. The first period closes after the first full month following
  installation.”
- Current-month panel: **If this month closed now**.
- Reasons (verbatim): `group sales below quota`, `account is not active`, `membership is not active`,
  `zero amount`.
- Footnote: “A closed period is never recalculated. A cancellation after the close does not claw back
  Royalty.”

### C.3 Member earnings

- Tab: **Royalty**
- Title: **Your Royalty bonus**
- Subtitle: “Royalty is paid once a month, after the month closes, when your rank and your team’s
  group sales meet the quota.”
- Rank card: **Your rank**, **Next rank**, **Direct members**, **Leaders in your team**,
  **Supervisors in your team**, **Managers in your team**.
- Quota line: **This month: Personal ₱X · Group ₱Y · Supervisor quota ₱Z — met / not yet met**.
- Preview: **If this month closed now: ₱X** / **Royalty is currently off.**
- Leader: **You are a Leader. Leader is a rank, not a royalty rank — the first rank that pays is
  Supervisor.**
- Member: **Reach 10 direct members to become a Leader.**
- Pending row: **Awaiting payment**.
- Paused row: **Payment is paused; the award stays pending.**
- Skipped row: **Group sales below the ₱X quota** (with the GS/PS it was judged on).
- Footer: **Group sales include your own purchases and every member below you, at any depth. Your own
  purchases are also paid the personal rate, so they count twice.**

### C.4 Dashboard tile

- Title: **Royalty**
- Supervisor+: **Supervisor · ₱X of ₱Y group this month** + progress bar + **Paid all-time ₱Z**.
- Leader: **Leader — Royalty starts at Supervisor** + **₱X of 10 Leaders**.
- Member: **Reach 10 directs to become a Leader**.
- Off: **Royalty is currently off** (history link remains when history exists).

### C.5 Admin ledger CSV columns

`Period, Member, Full name, Rank, Group sales, Personal sales, Quota, Group %, Personal %, Amount,
Status, Reason, Evaluated at, Paid at, Ledger id`

### C.6 Member CSV columns

`Period, Rank, Group sales, Personal sales, Quota, Group %, Personal %, Amount, Status, Reason, Paid at`

### C.7 Reason strings (stable)

| Stored | Displayed |
|---|---|
| `group sales below quota` | Group sales below the ₱{quota} quota |
| `account is not active` | Your account is not active |
| `membership is not active` | Your membership is not active |
| `zero amount` | The configured rates produced a zero award |
| `royalty stream off` (period note) | Royalty was off for this month |

### C.8 Status labels

| Stored | Admin | Member |
|---|---|---|
| `pending` | Awaiting payment | Awaiting payment |
| `paid` | Paid | Paid |
| `skipped` | Skipped | Not paid |
| `evaluated` (period) | Evaluated — awaiting payment | — |
| `paid` (period) | Paid | — |
| `skipped` (period) | Skipped (stream off) | — |

### C.9 Empty states

- Admin, no periods: “No Royalty periods yet. The first period closes after the first full month
  following installation.”
- Admin, no rows for a period: “No Royalty awards for this period.” (with the reason: no Supervisor+
  or all below quota).
- Member, no history: “No Royalty awards yet. Royalty is paid after the month closes.”
- Member, Leader: “Leader is a rank, not a royalty rank.”

---

## Appendix D — SQL quick reference

**The rank source (one query; the pass is in PHP)**

```sql
SELECT id, sponsor_id, username, member_status, is_active, created_at
  FROM users
 WHERE role = 'member'
   AND created_at < ?          -- the period end; omit for the live tree
 ORDER BY id;
```

**Period volume (one query)**

```sql
SELECT o.user_id, COALESCE(SUM(oi.line_total), 0) AS volume
  FROM shop_orders o
  JOIN shop_order_items oi ON oi.order_id = o.id
 WHERE o.paid_at >= ? AND o.paid_at < ?
   AND o.status <> 'cancelled'
 GROUP BY o.user_id;
```

**Awards for a period**

```sql
SELECT a.*, u.username, u.full_name
  FROM royalty_awards a
  JOIN users u ON u.id = a.user_id
 WHERE a.period = ?
 ORDER BY a.rank_level DESC, a.amount DESC, a.user_id
 LIMIT ? OFFSET ?;
```

**A member’s history**

```sql
SELECT a.*, p.status AS period_status, p.paid_at AS period_paid_at
  FROM royalty_awards a
  JOIN royalty_periods p ON p.period = a.period
 WHERE a.user_id = ?
 ORDER BY a.period DESC
 LIMIT ?;
```

**Period totals (the header must agree)**

```sql
SELECT COUNT(*) AS rows,
       COUNT(CASE WHEN status = 'paid' THEN 1 END)    AS paid_rows,
       COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS skipped_rows,
       COALESCE(SUM(CASE WHEN status IN ('pending','paid') THEN amount ELSE 0 END), 0) AS award_total,
       COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0)              AS paid_total
  FROM royalty_awards
 WHERE period = ?;
```

**The reconciliation totals**

```sql
SELECT COALESCE(SUM(amount), 0) AS paid_total
  FROM royalty_awards
 WHERE status = 'paid';

SELECT COALESCE(SUM(amount), 0) AS ledger_total
  FROM ewallet_ledger
 WHERE ref_type = 'royalty'
   AND created_at >= ?;
```

**Missing ledger / orphan / drift**

```sql
-- paid award without a live ledger row
SELECT a.id, a.period, a.user_id, a.amount, a.ledger_id
  FROM royalty_awards a
  LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'royalty'
 WHERE a.status = 'paid'
   AND (a.ledger_id IS NULL OR l.id IS NULL);

-- royalty ledger row without an award
SELECT l.id, l.ref_id, l.amount
  FROM ewallet_ledger l
  LEFT JOIN royalty_awards a ON a.id = l.ref_id
 WHERE l.ref_type = 'royalty'
   AND a.id IS NULL;

-- amount drift
SELECT a.id, a.amount, l.amount AS ledger_amount
  FROM royalty_awards a
  JOIN ewallet_ledger l ON l.id = a.ledger_id AND l.ref_type = 'royalty'
 WHERE a.status = 'paid'
   AND a.amount <> l.amount;
```

**A paid period must have no pending award**

```sql
SELECT p.period, COUNT(*) AS pending_rows
  FROM royalty_periods p
  JOIN royalty_awards a ON a.period = p.period AND a.status = 'pending'
 WHERE p.status = 'paid'
 GROUP BY p.period;
```

**The first period to consider closing**

```sql
SELECT value FROM settings WHERE key_name = 'royalty_ledger_since';
-- first payable month = month(value), or the next month when value is not
-- the first instant of its month; then walk forward, skip existing periods,
-- and stop at the month before the current one
```

**The wallet movement**

```sql
SELECT l.id, l.user_id, l.amount, l.ref_id, l.created_at
  FROM ewallet_ledger l
 WHERE l.ref_type = 'royalty'
 ORDER BY l.id DESC
 LIMIT 20;
```

---

## Appendix E — Glossary

| Term | Meaning in this plan |
|---|---|
| **award** | one `royalty_awards` row: a member, a period, a rank snapshot, GS/PS, amount and status |
| **baseline** | `royalty_ledger_since`; the first payable month is the first full month after it |
| **close** | the monthly evaluate-then-pay job for one completed period |
| **cutoff** | the period end used to reconstruct the tree (`users.created_at < end`) |
| **direct** | a member whose `sponsor_id` is this member |
| **Director** | rank 4; pays 10% GS + 15% PS; quota ₱5,000,000 |
| **evaluated** | a period whose award rows exist but whose payment is not complete |
| **GS** | group sales: PS plus every descendant’s PS in the period |
| **Leader** | rank 1; 10 directs; **not** a paying rank |
| **Manager** | rank 3; pays 5% GS + 10% PS; quota ₱1,000,000 |
| **Member** | rank 0; automatic; not paid |
| **no stacking** | paying only the member’s achieved rank, never the lower ranks as well |
| **paid** | an award whose wallet credit exists; also a final period status |
| **paying rank** | Supervisor, Manager or Director |
| **pending** | an award evaluated but not yet credited |
| **period** | a calendar month, `YYYY-MM`, Asia/Manila |
| **PS** | personal sales: the member’s own paid order line totals in the period |
| **quota** | the GS a rank must reach for the month to pay at all |
| **rank** | Member/Leader/Supervisor/Manager/Director, derived from the tree |
| **skipped** | an award row for a due member that paid nothing, with a reason; also a final period with no awards because the stream was off |
| **Supervisor** | rank 2; pays 3% GS + 5% PS; quota ₱500,000; the first paying rank |
| **team / Team chain** | every member below an account, any depth (the member-facing word) |
| **threshold** | the direct/rank-count requirement for a rank |
| **unrecovered** | not used in this plan: Royalty has no reversal and no shortfall |
| **volume** | the paid line totals that feed PS/GS |

---

## Appendix F — Formula card

```
period(P)        = calendar month of a datetime in Asia/Manila, 'YYYY-MM'
bounds(P)        = [P-01 00:00:00, next(P)-01 00:00:00)
cutoff(P)        = bounds(P)[1]
first_payable    = month(royalty_ledger_since) if that timestamp is exactly the
                   first instant of the month, else the next month

RANK (per member, bottom-up)
  A(i)   = # direct members
  L(i)   = A(i) >= leader_directs
  SL(i)  = L(i) + Σ SL(children)
  S(i)   = L(i) && (SL(i)-1) >= supervisor_leaders
  SS(i)  = S(i) + Σ SS(children)
  M(i)   = S(i) && (SS(i)-1) >= manager_supervisors
  SM(i)  = M(i) + Σ SM(children)
  D(i)   = M(i) && (SM(i)-1) >= director_managers
  rank(i) = D ? 4 : M ? 3 : S ? 2 : L ? 1 : 0

VOLUME
  PS(m,P) = Σ shop_order_items.line_total
            over paid orders of m with paid_at ∈ [start,end) and status ≠ 'cancelled'
  GS(m,P) = PS(m,P) + Σ PS(d,P) over every member descendant d

PAYOUT
  quota(r), g(r), p(r) = the rank's snapshot values
  if !enabled or rank < 2 or !account_eligible or GS < quota:  amount = 0 (skip reason)
  else:
      num   = g_milli(r) * GS_cents + p_milli(r) * PS_cents
      den   = 100 * 1000
      cents = floor((num + den/2) / den)          # half-up, one rounding
      amount = cents / 100

STATUS
  stream off at evaluation                 -> period 'skipped'
  awards all skipped / none                -> period 'paid' (paid_total 0)
  pending rows after evaluation            -> period 'evaluated'
  no pending rows                          -> period 'paid'

IDEMPOTENCY
  UNIQUE royalty_periods(period)
  UNIQUE royalty_awards(period, user_id)
  UPDATE royalty_awards SET status='paid' WHERE id=? AND status='pending'
  UNIQUE ewallet_ledger(user_id, ref_type, ref_id, type)

RECONCILE
  Σ paid awards = Σ ewallet_ledger where ref_type='royalty'
  header counts/totals = the rows
  a paid period has no pending award
  a skipped period has no rows
```

---

## Appendix G — Traceability to the reference document

### G.1 `docs/comp-plan_addons.md` §6 → this plan

| Reference | Rule | This plan |
|---|---|---|
| §6.1 | A rank ladder plus a monthly override; paid at month end; independent of Harvest and purchases after the close | §12.1, §12.6 |
| §6.2 | Member/Leader/Supervisor/Manager/Director; self-prerequisite; 10 directs; 10 downline rank holders; quotas 500k/1M/5M; group 3/5/10; personal 5/10/15 | §12.2, Appendix A |
| §6.3 | Ranks derived from the sponsor tree every time; no stored rank | §12.3, 19B |
| §6.4 | Minimum trees 11 / 111 / 1,111 / 11,111 | §12.3, Appendix B.11 |
| §6.5 | GS = self + all depths; PS = self; quota all-or-nothing; one rounding; PS paid twice; quota self-fundable; rank at evaluation; no carry-over; Leader ₱0 | §12.4, §12.5, §12.8 |
| §6.6 | Registrations build the tree; purchases accumulate; Advance pays Royalty; ranks live | §12.6 (calendar close instead of a manual Advance) |
| §6.7 | Worked examples: 18,300 / 15,000 / 0 / 50,000 / 500,000 / Leader 0 | §12.7, Appendix B |
| §2.4 | The month clock; registration does not add to sales | §12.4 #5, §12.6 |
| §2.5 | Royalty is ledgered money, not derived | §13.3, §14.12 |
| §9.1 | `RANK_QUOTA`, `GROUP_PCT`, `PERSONAL_PCT` | §12.2, §13.4 |
| §9.2 | No cap on Royalty (rate × sales) | §12.4 #11 |
| §9.3 | Minimum structure | §12.3 |
| §11.2 | Leader ₱0; quota zeroes everything; GS includes self; PS twice; ranks count people; live ranks vs monthly payouts; no history; no carry-over | §12.2, §12.5, §12.10, §18 |
| Appendix B | Formula card | Appendix F |

### G.2 Package and repeat-purchase implementation mapped to Royalty

| Existing artefact | Royalty equivalent | Why |
|---|---|---|
| `package_levels` / `product_unilevel_levels` | **no per-product table** | Royalty is a company-wide rank override, not a product ladder |
| `commissions` (activation ledger) | `royalty_awards` (period ledger) | different grain and key |
| `unilevel_awards` (order-line ledger) | `royalty_awards` (member-month ledger) | different grain and lifecycle |
| `unilevel_periods` (Active snapshot) | `royalty_periods` (payout header) | one must stay money-free; the other is the money |
| `UnilevelCycle::close()` | `RoyaltyService::evaluate()` + `pay()` | the royalty close pays; the unilevel close must not |
| `UnilevelService::onOrderPaid()` | `RoyaltyService::evaluate()` at the month close | per-order vs monthly |
| `SponsorService::downline()` (10-level cap) | `RoyaltyRank::map()` (any depth, whole network) | rank counts are not capped at 10 |
| `WalletService::REF_TYPES` `unilevel` | `royalty` | one credit per award |
| `bin/unilevel_month_close.php` | `bin/royalty_month_close.php` | same job conventions, different money rule |

### G.3 Older documents deliberately not followed

| Document | Claim | Why not |
|---|---|---|
| [`dmp/workflow.md`](workflow.md) | A QA/retail-customer gate; “if non-QA for any day, the whole month is lost” | Not in the reference §6; would need a QA snapshot table and a different eligibility rule. Out of scope (Q15) |
| [`dmp/ecowealth2.md`](ecowealth2.md) | “Balanced” requirements: 10 directs, 5 qualified; 3 Supervisor legs; 3 Manager legs | Not the reference; changes the ladder and the economics |
| [`dmp/ecowealth3.md`](ecowealth3.md) | Adds a Chairman rank at 12%/20% | Not in the reference; a fifth rank would change `rank_level` and the enum |
| [`dmp/workflow.md`](workflow.md) | “Upline Support” / partnership bonus paid downward | A different stream; not part of Royalty |

---

## Appendix H — Phase gate record

Fill one row per gate and copy the block into `docs/phase-log.md` with the phase’s entry. A phase
without a filled row is not closed, whatever its code says.

| Phase | Tag | Date | Suite passed/ran | Smoke passed/ran | Migration/bundle | Manual QA `QA19X-nn` | Tester | Deviations | Signed |
|---|---|---|---:|---:|---|---|---|---|---|
| 19A Foundation | `p19a-foundation` | 2026-11-15 | 1,026 / 1,026 | 229 / 229 | migration `023` applied twice, idempotent; fresh = migrated; bundle `--check` exit 0 (60 statements, 82,518 bytes) | A: 8/8 | DSH (automated pass) | 5 recorded below | ☑ |
| 19B Configuration | `p19b-configuration` | — | — / ≥1,050 | __ / __ | no schema change | B: __/__ | — | — | ☐ |
| 19C Money engine | `p19c-money` | — | — / ≥1,090 | __ / __ | no schema change; `wallet_reconcile` exit 0 with Royalty | C: __/__ | — | — | ☐ |
| 19D Admin visibility | `p19d-admin` | — | — / ≥1,106 | __ / __ | no schema change | D: __/__ | — | — | ☐ |
| 19E Member visibility | `p19e-member` | — | — / ≥1,122 | __ / __ | no schema change | E: __/__ | — | — | ☐ |
| 19F Operations | `p19f-operations` | — | — / ≥1,140 | __ / __ | no schema change | F: __/__ | — | — | ☐ |
| 19G Release | `p19g-release` | — | — / ≥1,140 | 248 / 248 | no schema change; `APP_PHASE = 19` | G: __/__ | — | — | ☐ |

**Deviation log.** Any difference between this plan and what was built, with the reason and the phase
that owns it. An empty deviation log at the 19G gate means the plan and the build agreed.

| Phase | Deviation | Reason | Approved by |
|---|---|---|---|
| 19A | The branch is cut from `efeddbe` (the plan commit) instead of `d8e626a`; `p19-start` tags `efeddbe`. | The application code is identical; cutting from the plan commit keeps `dmp/plan-ecowealth-royalty.md` on `feature/royalty`. | lead (19A gate) |
| 19A | `royalty_periods` ships the third CHECK from §13.2 (`chk_rp_ranks`: `members_count >= leaders_count + supervisors_count + managers_count + directors_count`), which the §13.5 SQL block omitted. | §13.2 is the normative key list; the block was an oversight. Schema and migration agree, and `P19A-T14` proves the CHECK refuses an impossible header. | lead (19A gate) |
| 19A | `DatabaseInstaller::TRANSACTIONAL_TABLES` is **not** extended with the two Royalty tables, and the installer's settings hint is corrected to “70 keys plus the three `install_*` metadata rows”. | The plan lists only `EXPECTED` for the installer, the reset's zero pack covers both tables, and P19A-T13's “no reference outside the allow-list” stays sharp. The hint had drifted to 51 since Phase 17A. | lead (19A gate) |
| 19A | The development copy was migrated at this gate (backup `ecowealth_sneat-pre-19a-20261115-081838.sql`, 897,353 bytes outside the repo) and its real `storage/install.lock` was moved aside for the scratch installs and restored. | QA19A-01/02/03/04 need a migrated copy and QA19A-06/07 need an unlocked installation. | lead (19A gate) |
| 19A | `system/README.md` and `docs/runbook.md` were updated with the new **33 / 73** counts in addition to the two files the plan names. | Both state the same fresh-install numbers and would be stale on the next cold read. | lead (19A gate) |

**Acceptance sign-off.**

| Item | Value |
|---|---|
| Reference settings used | 10/10/10/10 · 500k/1M/5M · 3/5, 5/10, 10/15 |
| Fixture period(s) | — |
| Reference example B.1 observed | — |
| Reference example B.2 observed | — |
| Reference example B.3 observed | — |
| `wallet_reconcile.php` | exit — |
| `royalty_month_close.php --doctor` | exit — |
| Tester / date | — |
| Evidence folder | `docs/qa/evidence/phase-19/` |

---

*End of plan.*

