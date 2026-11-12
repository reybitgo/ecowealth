# Compensation-plan add-ons: General Harvest, Team Harvest, Royalty and Unilevel

**Scope.** The four bonus systems implemented in the browser-local prototype
[`simulator.html`](../simulator.html): the General Harvest Bonus, the Team Harvest Bonus, the
Royalty Bonus and the Unilevel Repeat-Purchase Bonus. The fifth stream in the same file, the
Indirect Referral Bonus, is summarised in Appendix A because the simulator's Company Report
includes it, but it is not one of the four add-ons this document specifies.

**Source of truth.** Every rule below was read out of [`simulator.html`](../simulator.html) and
then checked by executing the file's own functions against constructed trees and queues. The
documented amounts, thresholds and example payouts are therefore the code's actual output, not a
reconstruction. Line references point at the implementation: `../simulator.html#L75-L86` and so
on.

**This is a simulator, not the production plan.** The file is the prototype reference; it is not
connected to the PHP application in [`system/`](../system/README.md), it has no server, no login
and no real money (see the note in [`README.md`](../README.md)). The figures are the terms the
prototype models, not verified official compensation terms.

| | |
|---|---|
| Source file | [`simulator.html`](../simulator.html) (582 lines, one `<script>`, no dependencies) |
| Verified against | the working copy, 12 November 2026 |
| Runtime | any modern browser; state in `localStorage` under `royalty_harvests_v1` |
| Money unit | Philippine peso, whole pesos only |
| Members | synthetic `company` (id 0) plus every registered member (id ≥ 1) |
| Backend | none; refreshing keeps state, clearing site data wipes it |

**Conventions used below.** `p` is a member's **0-based** position in the Harvest queue, so
position **#1** is `p = 0` (the apex). `N` is the number of Harvest-qualified members at the time
of the calculation. `n` is one member's count of qualified teammates (Team Harvest). `GS` and `PS`
are a member's group sales and personal sales for the current month. Amounts are in pesos. "Month
M" is the simulator's month counter, which starts at 1 and only advances when **Advance month** is
clicked (§2.4).

---

## Contents

1. [The model in one page](#1-the-model-in-one-page)
2. [Shared primitives](#2-shared-primitives)
3. [Harvest qualification](#3-harvest-qualification)
4. [General Harvest Bonus](#4-general-harvest-bonus)
5. [Team Harvest Bonus](#5-team-harvest-bonus)
6. [Royalty Bonus](#6-royalty-bonus)
7. [Unilevel Repeat-Purchase Bonus](#7-unilevel-repeat-purchase-bonus)
8. [Cross-stream behaviour and company economics](#8-cross-stream-behaviour-and-company-economics)
9. [Parameters, thresholds and ceilings](#9-parameters-thresholds-and-ceilings)
10. [Where each number appears](#10-where-each-number-appears)
11. [Edge cases, quirks and non-goals](#11-edge-cases-quirks-and-non-goals)
12. [Verification recipes](#12-verification-recipes)
13. [Source map and glossary](#13-source-map-and-glossary)
- [Appendix A — Indirect Referral Bonus](#appendix-a--indirect-referral-bonus)
- [Appendix B — Formula card](#appendix-b--formula-card)

---

## 1. The model in one page

There is exactly **one sponsor tree**, **two member-facing events** (a registration and a purchase)
and **one manual month close**. Everything else is derived.

```
company (id 0; root; never qualified; never paid; cannot be logged into)
└── sponsor tree: each member's sp is the id of the member who registered them

REGISTRATION ──► sponsor gains a direct
             ├─► Indirect Referral paid instantly up the sponsor chain (10 levels, flat)
             └─► if the sponsor now has ≥ 3 directs, the sponsor is appended to the Harvest queue

PURCHASE ─────► buyer's personal sales this month += amount
             ├─► Unilevel paid instantly (10% to the buyer, up to 10 uplines if Active)
             └─► the amount feeds the Royalty group sales of every ancestor and the buyer's own quota

ADVANCE MONTH ► Royalty paid to Supervisor/Manager/Director whose group sales cleared the quota
             ├─► every member's Unilevel Active flag is set for next month (personal sales ≥ ₱500)
             ├─► this month's Unilevel total is snapshotted; the monthly accumulator is reset
             └─► sales reset to zero, month++, last month's totals recorded

HARVEST ──────► nothing but registrations change it; it has no monthly cycle, no purchase input,
                no Active status and no rank requirement beyond being in the queue
```

### 1.1 The gates

| Gate | Rule | Feeds |
|---|---|---|
| Harvest-qualified | at least 3 direct sponsees; checked when a registration happens | Position in the Harvest queue; General and Team Harvest |
| Leader | at least 10 direct sponsees | Royalty rank 1 (not royalty-eligible) |
| Supervisor | Leader **and** at least 10 members anywhere below who are Leader or above | Royalty rank 2 (royalty-eligible) |
| Manager | Supervisor **and** at least 10 members anywhere below who are Supervisor or above | Royalty rank 3 |
| Director | Manager **and** at least 10 members anywhere below who are Manager or above | Royalty rank 4 |
| Royalty quota | monthly group sales ≥ ₱500,000 / ₱1,000,000 / ₱5,000,000 | Whether the month's Royalty pays at all |
| Unilevel Active | personal purchases ≥ ₱500 in the month just ended | Whether that member receives upline Unilevel next month |

The two 3-direct / 10-direct gates both read the same direct count but are independent: a member
can be Harvest-qualified (`3 directs`) long before becoming a Royalty Leader (`10 directs`), and a
Supervisor is necessarily Harvest-qualified because 10 directs is already ≥ 3. In normal
registration flow, every royalty-eligible member is therefore also in the Harvest queue, but the
money is computed by separate systems and can differ wildly.

### 1.2 The four streams side by side

| | General Harvest | Team Harvest | Royalty | Unilevel |
|---|---|---|---|---|
| **What it pays for** | filling levels of the shared board | filling levels of your own board | holding a rank and hitting the monthly group quota | purchases by you and your downline |
| **Structural basis** | global Harvest queue order | your qualified-after downline, in qualification order | rank derived from directs and ranked downline | sponsor chain, up to 10 uplines |
| **Gate** | Harvest-qualified + level block full | Harvest-qualified + teammate threshold | Supervisor/Manager/Director + quota | upline must be Active; level 0 always pays |
| **Amount** | flat per level: ₱2,000 … ₱5,000,000 | flat per level: ₱1,000 … ₱3,500,000 | % of the month's group and personal sales | 10% self + 5/5/5/3/3/2/2/2/1/1% uplines |
| **Timing** | derived immediately when the block fills | derived immediately when the threshold is crossed | once per month, on **Advance month** | immediately on every purchase |
| **Repeats** | once per level per member | once per level per member | every month the quota is met | every purchase |
| **Stored as** | derived from the queue (no ledger) | derived from the tree (no ledger) | ledger `earnedAll` | ledger `unilevelEarned` |
| **Reset** | never | never | monthly sales reset; earnings persist | monthly accumulator reset; earnings persist |
| **Purchase input** | none | none | yes (group sales and personal sales) | yes (the purchase itself) |
| **Rank input** | none | none | the rank ladder | none |

---

## 2. Shared primitives

### 2.1 The sponsor tree, ids and names

- `company` is a synthetic member with `id: 0` and `sp: null`. It is created by `fresh()` and is
  the default sponsor. It is **not** counted in "members registered", never enters the Harvest
  queue, never receives Unilevel or Indirect money, and cannot be logged into (the login handler
  rejects id 0). See [`fresh()`](../simulator.html#L90) and
  [`doLogin()`](../simulator.html#L523).
- Ids are array indices and are assigned in registration order, so a sponsor always has a smaller
  id than the member they sponsored. Several algorithms rely on that (notably the subtree rollup).
- There is no deletion and no re-parenting. The tree only grows, so ranks and Harvest positions
  only move forward.
- Registration rules: the username must match `/^[a-z0-9_]{2,20}$/i`, must be unique
  case-insensitively, and the sponsor must be an existing member or `company`. Bulk registration
  (`bulkAdd`) skips validation and names members `m<id>`, for example `m42`.
- `directs(id)` counts direct sponsees of any age or status. `teamOf(id)` walks the entire subtree
  (all descendants, any depth) in no particular order.
- Two useful derived walks:
  - `rollup(fn)` returns, for every member, `fn(member)` plus `fn` summed over the whole downline;
    the company's entry totals the network. It is a single reverse pass over the user array, valid
    because ids increase down every chain ([`rollup()`](../simulator.html#L159-L163)).
  - `counts()` returns, for the company and for every ancestor in the Harvest queue, how many
    qualified descendants joined the queue *after* that ancestor
    ([`counts()`](../simulator.html#L152-L153)). It is the Team Harvest teammate count `n`.

### 2.2 Money and rounding

- Every amount is an integer peso. The only currency formatter is `peso()` (`₱` plus
  `toLocaleString`).
- `recordPurchase()` first rounds the amount: `Math.round(+amt || 0)`, then ignores anything ≤ 0.
  `Math.round` is half-up for positive amounts. A manual entry of `100.6` is treated as `₱101`;
  `100.4` as `₱100`.
- **Unilevel rounds per level**, not on the total: each level's `round(rate × amount)` is credited
  separately. Summing eleven rounded amounts can drift a few pesos from `39% × amount` (see §7.6).
- **Royalty rounds once**, on the combined `group% × GS + personal% × PS`.
- Harvest and Indirect amounts are already integers.

### 2.3 Persistent state

`fresh()` at [`L90`](../simulator.html#L90) defines the whole model. The saved object is:

| Field | Meaning | Reset by Advance month? | Reset by **Reset**? |
|---|---|---|---|
| `users` | array; index is the member id; `{id, name, sp}` | no | yes |
| `mx` | Harvest queue: qualified ids in qualification order | no | yes |
| `me` | id of the logged-in member (null = signed out) | no | yes |
| `month` | month counter, starts at 1 | increments | yes |
| `sales` | this month's personal purchase total per member | cleared | yes |
| `purchasedAll` | all-time personal purchases per member (company revenue) | no | yes |
| `earnedAll` | all-time Royalty credited per member | no | yes |
| `lastMonthTotal`, `lastMonthCount` | Royalty paid in the month just ended | replaced | yes |
| `unilevelEarned` | all-time Unilevel credited per member | no | yes |
| `unilevelFlag` | Active (`true`) / Inactive (`false`) **for the current month** | replaced for all | yes |
| `unilevelLastSales` | the personal sales the current flag was decided from | replaced for all | yes |
| `unilevelMonthTotal` | Unilevel credited across the network this month | snapshot then 0 | yes |
| `lastMonthUnilevelTotal` | Unilevel credited in the month just ended | replaced | yes |
| `lastAutoPurchaseLog` | per-purchase log of the **last** Auto-purchase pass | replaced on next pass | yes |
| `indirectEarned` | all-time Indirect Referral credited per member | no | yes |
| `on` | which streams count toward personal totals: `{g,t,r,u,i}`, all 1 by default | no | yes |

Loading also performs two migrations: `on.i` is added if missing, and the old `unilevelGate` field
is renamed to `unilevelFlag`; a missing flag means Active
([`load()`](../simulator.html#L91-L100)).

### 2.4 The month clock

- `month` starts at 1. Nothing changes it except **Advance month** (or a full Reset).
- Purchases accumulate in `sales` for the current month. Registration does **not** add to `sales`.
- **Advance month** pays Royalty for the month that just ended, then sets every member's Unilevel
  flag for the next month from that same month's sales, then clears `sales` and increments
  `month`.
- The Unilevel flag used during month M is the one decided at the end of month M−1. A brand-new
  member has no flag at all, which reads as **Active** — that is how month 1 works before the
  first advance.

```
month 1: everyone Active (no flag stored)
   purchases → Unilevel pays immediately using "missing flag = Active"
   Advance → Royalty for month 1; flags for month 2 = (month 1 personal sales ≥ 500); sales cleared
month 2: Unilevel upline receipts use the flags just set
   purchases → Unilevel pays immediately
   Advance → Royalty for month 2; flags for month 3 = (month 2 sales ≥ 500); sales cleared
```

### 2.5 Derived money versus ledger money

This distinction explains several numbers in the UI, and the Company Report in particular.

| Stream | How the number exists | Consequence |
|---|---|---|
| General Harvest | Derived from `mx.length` and the member's queue position on every render | No payment event and no payout history. It is "credited" by construction and is recomputed as the queue grows. |
| Team Harvest | Derived from the member's teammate count `n` on every render | Same: no event, no history, monotone as qualified teammates appear. |
| Royalty | Accumulated into `earnedAll` at each Advance | A true ledger: the amount is fixed when paid, one entry per month. |
| Unilevel | Accumulated into `unilevelEarned` at each purchase | A true ledger: fixed at purchase time. |
| Indirect | Accumulated into `indirectEarned` at each registration | A true ledger: fixed at registration time. |

A Harvest level is paid as a **lump sum when the whole level block is filled**; partial blocks pay
nothing. Because the Harvest amounts are derived, they can never be paid twice, but they also
cannot be "paid out" as a distinct transaction — the Company Report simply sums what the board
currently owes.

### 2.6 Persistence and reset

- Every mutation calls `save()`, which stringifies the state into `localStorage` under
  `royalty_harvests_v1` and also keeps an in-memory copy. If storage throws (private mode, file
  origin restrictions), the page warns that data will reset when the tab closes and keeps working
  in memory.
- **Reset** is a two-click action: the first click arms it, the second replaces the state with
  `fresh()`. Any other render without a message disarms it. A Reset also logs the operator out
  (`me: null`).
- The logged-in member id is part of the saved state, so a reload returns to the same dashboard.
- There is no export/import and no server sync.

### 2.7 Bulk growth and the three sponsor patterns

The dashboard's **Grow the network** forms can add 1–20,000 members in one action.
[`sponsorPlan()`](../simulator.html#L269-L294) chooses a sponsor for each new member;
[`bulkAdd()`](../simulator.html#L299-L317) then creates them in order, assigns `id = users.length`,
names them `m<id>`, pays Indirect per member and qualifies sponsors when they reach 3 directs. Two
scopes are available:

- **under me**: the candidate pool is the logged-in member plus their entire subtree;
- **whole network**: every member (the company is the root but is never a candidate).

| Pattern | How the sponsor is chosen | Direct cap | What it tends to produce |
|---|---|---|---|
| Random | uniform pick from the pool; each newly created member joins the pool for later picks | none | broad, uneven trees; Leaders and qualified members appear by chance |
| Balanced | breadth-first: fills the head's open direct slots, moves to the next member at 10; each new member joins the back of the queue with 10 slots | 10 per member (soft) | Leaders quickly, then Harvest-qualified members in creation order |
| Chain | each new member is sponsored by the previous new member; the first by the highest-id member in scope | 1 per member | each member in the chain gets at most one new direct, so the batch itself creates no 3-direct sponsors |

Every member in a batch still runs the normal registration side effects, so one action can push
many sponsors into the Harvest queue, pay a batch total of Indirect, and change Royalty ranks live.
The batch size is clamped by the form with `Math.max(1, Math.min(20000, …))`.

---

## 3. Harvest qualification

**Rule.** A member is Harvest-qualified the moment they have **at least 3 direct sponsees**. The
check runs inside `reg()` after the new user is appended
([`reg()`](../simulator.html#L126-L137)):

```
if sponsor.id > 0 and sponsor not already in mx and directs(sponsor) >= 3:
    mx.push(sponsor.id)
```

- The queue `mx` is **append-only** and is ordered by qualification time. The first member ever to
  reach 3 directs becomes the apex (queue index 0, displayed position #1).
- Qualification is not a rank and does not depend on purchases, activity, Royalty rank or who the
  three directs are. A member with three direct sponsees who never buy anything is qualified.
- Once in the queue, the member never leaves and their position never changes — even if the tree
  grows underneath them, and even if their directs later become inactive.
- The **company never qualifies**: the check requires a sponsor id greater than 0.
- Qualification can also happen in bulk registration: `bulkAdd()` tracks direct counts
  incrementally and appends a sponsor when their count reaches 3
  ([`bulkAdd()`](../simulator.html#L299-L317)).
- An unqualified member earns **nothing** from either Harvest stream, even if they have qualified
  members below them. General requires a queue position; Team requires the member's own entry in
  the queue for `counts()` to credit them.

### 3.1 The queue is not the sponsor tree

| | Sponsor tree | Harvest queue |
|---|---|---|
| Node | every registered member | only qualified members |
| Order | registration order (id) | qualification order (3rd direct) |
| Parent | the sponsor (`sp`) | arithmetic: 0-based parent = `floor((q-1)/3)` |
| Used by | Royalty ranks, Indirect, Unilevel, Team roster | General Harvest board, position numbers |

A member's sponsor is fixed at registration; their queue position is fixed when they qualify.
The two orders can diverge: a downline member can qualify before their sponsor, in which case the
downline member sits *earlier* in the queue than the person who sponsored them.

Example: `ada` registers under `company`; `d1`, `d2`, `d3` register under `ada`. After `d1` and
`d2`, `ada` is not qualified (2/3). When `d3` registers, `ada` is appended to the queue and is
position #1. Registering a fourth direct afterwards does not re-add or move `ada`.

---

## 4. General Harvest Bonus

### 4.1 What it is

One **shared 3-ary, 10-level board** laid over the Harvest queue. The board is a single ternary
heap whose node index is the queue index `q` (0-based), so:

- node 0 is the apex (position #1);
- the children of node `q` are nodes `3q+1`, `3q+2`, `3q+3`;
- the level-L descendants of node `q` are the `3^L` consecutive nodes starting at
  `3^L·q + (3^L − 1)/2`.

Those level-L slots are **queue positions, not sponsor descendants**. The board does not care who
sponsored whom. That is why the General Harvest "by position" table has a *Relation* column with
the values **Direct**, **Indirect** and **Outside your team**: a General level can be completed by
members who are not in your sponsor downline at all. This is intended: General Harvest is a global
board, and every qualified member holds one node on it.

The board is not limited to ten levels in total; each node has ten levels of reach, and the queue
can grow indefinitely. Only the node's own 10 levels pay.

### 4.2 Fill rule

For a member at queue index `q`, level `L` (1 … 10):

```
slots   t = 3^L
start   s = t·q + (t−1)/2
filled  f = clamp(N − s, 0, t)          // N = number of qualified members
paid    f == t                          // equivalently N ≥ s + t
```

The level pays the **entire** level bonus the first time `f` reaches `t`, and pays nothing before
that. There is no partial or pro-rata payout, and a level cannot pay twice because the status is
derived from the (monotone) queue length.

For the apex (`q = 0`) the level-1 block is queue indices 1–3 (positions #2–#4), so the apex needs
three *other* qualified members to complete level 1 — not four. In general the threshold is
`N ≥ 3^L·q + (3^(L+1) − 1)/2`; for the apex this is `(3^(L+1) − 1)/2`.

While a level is unpaid the UI shows a progress bar whose width is `f/t` (its tooltip gives `f` of
`t`); once it is paid the row shows a **Paid** badge. The unused helper `prg()` would have rendered
the text `Level L: f/t`, but the dashboard uses the table and bar instead
([`lvT()`](../simulator.html#L144)).

### 4.3 The bonus ladder

The level bonus is flat per level, independent of position, team size or the number of members in
the block ([`BONUS_G`](../simulator.html#L75)):

| Level | Slots `3^L` | Bonus |
|---:|---:|---:|
| 1 | 3 | ₱2,000 |
| 2 | 9 | ₱4,000 |
| 3 | 27 | ₱8,000 |
| 4 | 81 | ₱12,000 |
| 5 | 243 | ₱25,000 |
| 6 | 729 | ₱100,000 |
| 7 | 2,187 | ₱250,000 |
| 8 | 6,561 | ₱480,000 |
| 9 | 19,683 | ₱2,500,000 |
| 10 | 59,049 | ₱5,000,000 |
| **Total** | **88,572 slots** | **₱8,381,000** |

### 4.4 Queue-size thresholds

For the apex, `N` must reach the following values. For any other position, multiply `3^L` by the
0-based queue index and add the same apex threshold (table in §4.5).

| Level | Apex needs `N ≥` | Bonus paid | Cumulative earnings of the apex |
|---:|---:|---:|---:|
| 1 | 4 | ₱2,000 | ₱2,000 |
| 2 | 13 | ₱4,000 | ₱6,000 |
| 3 | 40 | ₱8,000 | ₱14,000 |
| 4 | 121 | ₱12,000 | ₱26,000 |
| 5 | 364 | ₱25,000 | ₱51,000 |
| 6 | 1,093 | ₱100,000 | ₱151,000 |
| 7 | 3,280 | ₱250,000 | ₱401,000 |
| 8 | 9,841 | ₱480,000 | ₱881,000 |
| 9 | 29,524 | ₱2,500,000 | ₱3,381,000 |
| 10 | 88,573 | ₱5,000,000 | ₱8,381,000 |

### 4.5 Thresholds by position

`N_min(q, L) = 3^L·q + (3^(L+1) − 1)/2`. The first four positions:

| Position | `q` | Level 1 | Level 2 | Level 3 | Level 4 |
|---|---:|---:|---:|---:|---:|
| #1 (apex) | 0 | 4 | 13 | 40 | 121 |
| #2 | 1 | 7 | 22 | 67 | 202 |
| #3 | 2 | 10 | 31 | 94 | 283 |
| #4 | 3 | 13 | 40 | 121 | 364 |

Position #4's thresholds are exactly the apex's shifted one level, because node 3 is the apex's
last child: its level-L block is the final `3^L` slots of the apex's level-(L+1) block, so it
completes exactly when that larger block does. Later positions simply move the whole staircase to
the right.

### 4.6 Whole-board credit

Because the level-L blocks of different nodes are disjoint and tile the queue from index
`(3^L − 1)/2` onwards, the total General credits on the board for a given `N` are:

```
GeneralBoardTotal(N) = Σ_L  BONUS_G[L] × max(0, floor( (N − (3^L − 1)/2) / 3^L ))
```

This is the number the dashboard's **General credited** tile is summing (it computes the same
thing position by position). Verified totals:

| `N` | Board pays | `N` | Board pays |
|---:|---:|---:|---:|
| 4 | ₱2,000 | 364 | ₱579,000 |
| 13 | ₱12,000 | 1,093 | ₱1,888,000 |
| 40 | ₱50,000 | 3,280 | ₱6,065,000 |
| 121 | ₱176,000 | 88,573 | ₱190,208,000 |

At `N = 88,573` the apex has all ten levels; the board has paid ₱190.2 million in total at that
point, against ₱442.9 million of ₱5,000 registration revenue if the queue grew entirely by
registrations.

### 4.7 Worked example: 20 qualified members

Take a queue of 20 qualified members in qualification order. Nothing about sponsorship matters
for this calculation.

| Position | Level 1 filled | Level 1 paid | Level 2 filled | Level 2 paid | Other | Total for the member |
|---|---:|---|---:|---|---|---:|
| #1 | 3/3 | ₱2,000 | 9/9 | ₱4,000 | Level 3: 7/27 | ₱6,000 |
| #2 | 3/3 | ₱2,000 | 7/9 | — | — | ₱2,000 |
| #3 | 3/3 | ₱2,000 | 0/9 | — | — | ₱2,000 |
| #4 | 3/3 | ₱2,000 | 0/9 | — | — | ₱2,000 |
| #5 | 3/3 | ₱2,000 | 0/9 | — | — | ₱2,000 |
| #6 | 3/3 | ₱2,000 | 0/9 | — | — | ₱2,000 |
| #7 | 1/3 | — | 0/9 | — | — | ₱0 |

Board total: `6,000 + 5 × 2,000 = ₱16,000`. Check with the closed form: L1 pays
`floor(19/3) = 6` nodes (₱12,000), L2 pays `floor(16/9) = 1` node (₱4,000), L3 pays 0.

The example says nothing about sponsorship: position #1's level-1 block (positions #2–#4) need
not be its own directs, and a member can be paid from a block containing people outside their
team.

### 4.8 What does *not* affect General Harvest

- No purchase or sales volume, no Active flag, no Royalty rank, no monthly cycle.
- The member's own direct count after qualification does not matter.
- The member's sponsor does not matter; only `q` and `N` do.
- Being unqualified means no General earnings at all; an unqualified member has no queue position
  to be paid from.

### 4.9 UI presentation

- **General matrix** panel: one row per level with *Slots*, *Filled*, *Bonus* and *Status*
  (`Paid` or a progress bar). Rendered by `lvT(levelsG(p))` ([`L447`](../simulator.html#L447)).
- **Your general matrix, by position**: every filled slot in the member's ten blocks, showing the
  position, the member occupying it, that member's sponsor, the relation to the viewer
  (Direct / Indirect / Outside your team), the level and the node it is placed under
  (`floor((q−1)/3)`).
- **Whole network: fill at the top of the matrix**: the apex's table, shown when at least one
  member is qualified.
- A member's own General and Team tables are hidden until they qualify; the whole-network apex
  table appears as soon as the queue is non-empty.

---

## 5. Team Harvest Bonus

### 5.1 What it is

A **private 3×10 matrix per qualified member**, filled by that member's own qualified downline —
any depth — but **only those who qualified *after* the member**. The fill order is qualification
order, and the bonus is driven solely by the count `n` of such teammates. It is called
"unilevel" in structure: the teammates can be at any depth, not only direct sponsees.

`n` is computed by `counts()` as follows: for every qualified member `u` at queue index `i`, walk
`u`'s sponsor chain; for each ancestor `a` that is itself in the queue at index `q < i`, increment
`n[a]`. The company (`a = 0`, `q = −1`) counts everyone but never earns. Consequently:

- a downline member who qualified **before** you is never in your Team matrix, no matter how deep
  or how productive they later become;
- a downline member who qualifies **after** you enters your Team matrix at the moment they
  qualify, wherever they sit in the tree;
- unqualified downline do not count yet.

### 5.2 Fill rule

For a member with `n` qualified-after teammates:

```
slots   t = 3^L
start   s = (t − 3)/2                     // 0 for L1, 3 for L2, 12 for L3, ...
filled  f = clamp(n − s, 0, t)
paid    f == t                            // equivalently n ≥ (3^(L+1) − 3)/2
```

The thresholds are the cumulative slots through level L: `3 + 9 + … + 3^L`. The first three
teammates fill level 1; the next nine fill level 2; and so on. Again the payout is all-or-nothing.

### 5.3 The bonus ladder

[`BONUS_T`](../simulator.html#L76):

| Level | Slots `3^L` | Teammates needed `n ≥` | Bonus | Cumulative |
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

### 5.4 What does *not* count

- **Downline who qualified before you.** They are permanently excluded. The dashboard reports the
  count: *"X downline members qualified before you, so they are not in your Team Harvest matrix."*
- **Unqualified downline.** They are invisible to Team Harvest until they reach 3 directs; if they
  do so after you, they then count.
- **Your sponsor, your uplines, or anyone outside your sponsor subtree.** Team Harvest never pays
  across team lines.
- **Your own direct count** after qualification. What matters is how many qualified people appear
  below you *after* you, not how many directs you hold.
- **Purchases, rank and Active status.** None of them feed Team Harvest.

The fill is not the sponsor-tree placement either. Team members are laid out level-order in the
order they qualified: the first three are "under you", the next nine under those three, and so on.
The **Placed under** column in the team table is a display of that arithmetic
(`r < 3 ? you : tm[floor(r/3)−1]`), not a statement about sponsorship.

### 5.5 Qualification is mandatory

A member who is not Harvest-qualified has no `counts()` entry (the walk only credits ancestors
that are in the queue), so `n` reads 0 and Team Harvest pays ₱0 even when they have qualified
members below them. The Team panels are also hidden until the member qualifies. In normal
registration flow this cannot happen — the third direct qualifies the sponsor immediately — but
the guard is still part of the arithmetic (`earnedT(0) = 0`) and it matters for state edited
outside the UI.

### 5.6 Worked example

Tree: `1` sponsored `2`; `2` sponsored `4` and `5`; `3` (also sponsored by `1`) sponsored `6`; `6`
sponsored `7`. Qualification order (queue): `3, 1, 2, 4, 5, 6, 7`.

- Member `1` qualified second (`q = 1`). Qualified descendants after it: `2, 4, 5, 6, 7` → `n = 5`.
  Levels: L1 3/3 paid ₱1,000, L2 2/9 unpaid → **₱1,000**.
- Member `2` (`q = 2`): descendants after it are `4, 5` → `n = 2` → L1 2/3 → **₱0**.
- Member `3` (`q = 0`): descendants after it are `6, 7` → `n = 2` → L1 2/3 → **₱0**.
- Member `6` (`q = 5`): descendant `7` qualifies after it → `n = 1` → **₱0**.

`counts()` returns `{0: 7, 1: 5, 2: 2, 3: 2, 6: 1}`. Member `1` is the "qualified before" case:
`3` is in its subtree but qualified *first*, so it is not counted; `1`'s five are `2, 4, 5, 6, 7`.
Member `2`'s count is 2, from its directs `4` and `5`; its sibling `3` is not below it. Member
`6`'s count is 1 (only `7`); its sponsor `3` is an ancestor, not a teammate.

### 5.7 UI presentation

- **Team matrix** panel: levels 1–10 with slots, filled, bonus and Paid / progress.
- **Your team, in qualification order**: one row per qualified-after teammate with their general
  position, the level they occupy in the private matrix and who they are "placed under" in the
  level-order layout.
- The Harvest account panel shows `n` and the direct count among the team.

---

## 6. Royalty Bonus

### 6.1 What it is

A **rank ladder plus a monthly override**. The rank is derived live from the sponsor tree; the
money is paid only at **Advance month**, to Supervisor, Manager and Director, and only when the
month's group sales reach the rank's quota. It is completely independent of Harvest qualification
and of purchases made after the month closes.

### 6.2 The ladder

Ranks are self-prerequisite: to be a Manager you must already be a Supervisor, and so on. That
makes the ladder automatically cumulative — a Director also satisfies "Leader", "Supervisor" and
"Manager", so counting "Leader or above" is just counting the base predicate.

| Rank | Level | Requirement | Royalty-eligible? | Monthly group quota | Group % | Personal % |
|---|---|---:|---|---:|---:|---:|
| Member | 0 | automatic on registration | no | — | — | — |
| Leader | 1 | at least 10 direct sponsees | **no** | — | — | — |
| Supervisor | 2 | Leader **and** at least 10 downline members who are Leader or above | yes | ₱500,000 | 3% | 5% |
| Manager | 3 | Supervisor **and** at least 10 downline members who are Supervisor or above | yes | ₱1,000,000 | 5% | 10% |
| Director | 4 | Manager **and** at least 10 downline members who are Manager or above | yes | ₱5,000,000 | 10% | 15% |

The "10 downline members" are **anywhere below**, at any depth, not just directs. The downline
members counted may themselves be at a higher rank (a Director counts as a Manager for its
upline's Manager requirement) because of the cumulative property. They do **not** need to be
Harvest-qualified: a member with 10 directs and 10 ranked descendants makes their upline a
Supervisor even if nobody in the subtree ever bought anything.

### 6.3 How ranks are derived

`ranks()` ([`L168-L179`](../simulator.html#L168-L179)) recomputes every rank from scratch on each
call — no rank is stored:

```
d   = directCounts()                        // direct sponsees per member
isL = d[i] >= 10                            // Leader
r1  = subtreeSum(isL)                       // Leader-or-above count, including self
isS = isL && (r1[i] − 1) >= 10              // Supervisor
r2  = subtreeSum(isS)
isM = isS && (r2[i] − 1) >= 10              // Manager
r3  = subtreeSum(isM)
isD = isM && (r3[i] − 1) >= 10              // Director
ts  = subtreeSum(1)                         // entire downline + self
```

The `− 1` removes the member themselves from the count, so the requirement really is ten *other*
rank holders. `subtreeSum` is the `rollup()` reverse pass; `ts` is used for the "Royalty team"
column and table, which are counts of all downline regardless of rank.

Because ranks are derived: a member cannot "lose" a rank in normal use (the tree only grows),
no rank history exists, and the toasts can only report rank-ups by comparing a before/after
snapshot taken around a single action ([`rankSnap()` / `rankMsg()`](../simulator.html#L319-L326)).

### 6.4 Minimum tree for each rank

Assuming the most compact placement (all required rank holders directly below the candidate, each
with the minimum needed structure):

| Rank | Minimal members in the candidate's subtree (including the candidate) | Shape |
|---|---:|---|
| Leader | 11 | self + 10 directs |
| Supervisor | 111 | self + 10 Leaders + 10 directs each |
| Manager | 1,111 | self + 10 Supervisors, each of the 111-member shape |
| Director | 11,111 | self + 10 Managers, each of the 1,111-member shape |

These are minima, not requirements: the ten rank holders may sit anywhere below.

### 6.5 Group sales and the payout formula

**Group sales (GS)** is the sum of **this month's** personal purchases of the member **plus** every
member below them at any depth. The member's own purchases are included — the rollup adds `fn(i)`
to `out[i]` ([`groupSalesAll()`](../simulator.html#L182), [`rollup()`](../simulator.html#L159-L163)).
**Personal sales (PS)** is the member's own purchases this month.

For a royalty-eligible member at month end:

```
if GS < quota(rank):   royalty = 0
else:                  royalty = round( groupPct(rank) × GS + personalPct(rank) × PS )
```

Notes:

- **All-or-nothing.** Missing the quota by one peso zeroes the whole payout, including the personal
  percentage.
- **One rounding** on the combined amount.
- **Personal sales count twice**: once inside GS (all of it) and again via PS. A Director's own
  purchase is therefore paid at effective 10% + 15% = 25%, while a downline purchase is paid at the
  group rate only.
- **The quota can be met by the member's own purchases**, since GS includes PS. A Supervisor who
  personally buys ₱500,000 in the month receives `3% × 500,000 + 5% × 500,000 = ₱40,000` — the
  quota is a self-fundable gate in this model.
- **The payout uses the rank held at evaluation time**, which is the live rank at the moment
  **Advance month** is clicked. There is no proration: a member who ranks up mid-month is evaluated
  for the whole month at the new rank.
- **No carry-over or catch-up.** Sales reset after the advance; a month with no purchases pays ₱0 to
  everyone.
- **Leader earns nothing** even with 10 directs; it only unlocks the Supervisor requirement.

### 6.6 Month workflow

1. Registrations build the tree. Ranks change immediately and the dashboard shows them, but no
   money moves.
2. Purchases accumulate in `sales` (manual or Auto-purchase). The Royalty account panel shows
   month-to-date PS, GS, quota progress and an "If evaluated now" preview.
3. **Advance month** ([`evaluateMonth()`](../simulator.html#L247-L265)):
   - snapshot ranks and group sales;
   - for every member, compute `payout(rankName, GS, PS)`; credit `earnedAll` and count the
     recipients;
   - set `unilevelFlag[i] = (sales[i] ≥ 500)` and `unilevelLastSales[i] = sales[i]`;
   - snapshot `unilevelMonthTotal` into `lastMonthUnilevelTotal` and reset it;
   - clear `sales`, increment `month`, record `lastMonthTotal` / `lastMonthCount`.
4. The toast reports the month's Royalty total, the number of recipients and how many members will
   be Active for Unilevel next month.

### 6.7 Examples

| Scenario | GS | PS | Result |
|---|---:|---:|---|
| Supervisor, quota met through self + downline | ₱510,000 | ₱60,000 | `0.03 × 510,000 + 0.05 × 60,000 = ₱18,300` |
| Supervisor at exactly the quota, no personal purchases | ₱500,000 | ₱0 | `₱15,000` |
| Supervisor below the quota | ₱410,000 | ₱60,000 | ₱0 (not `₱12,300`) |
| Manager at the quota, no personal purchases | ₱1,000,000 | ₱0 | `₱50,000` |
| Director at the quota, no personal purchases | ₱5,000,000 | ₱0 | `₱500,000` |
| Leader with ₱10,000,000 group sales | any | any | ₱0 — Leader is not eligible |

The first row is the constructed 111-member tree used in §12: a Supervisor with ten Leaders below,
each Leader holding ten directs. Its GS is ten directs × ₱45,000 = ₱450,000 plus ₱60,000 of the
Supervisor's own purchases, so the quota is met only because personal purchases are included.

---

## 7. Unilevel Repeat-Purchase Bonus

### 7.1 What it is

A real-time override on **every recorded purchase**, paid along the buyer's **sponsor chain**:
10% to the buyer, then 5/5/5/3/3/2/2/2/1/1% to the first ten uplines, subject to each upline
being **Active**. It has no rank requirement, no Harvest requirement and no monthly payout step;
the money is credited the instant the purchase is recorded. **Advance month** does not pay
Unilevel — it only decides who is Active for the *next* month.

### 7.2 The rate ladder

[`UNILEVEL_PCT`](../simulator.html#L82):

| Level | Who | Rate | On ₱10,000 | On ₱555 | On ₱1,050 | On ₱100 |
|---:|---|---:|---:|---:|---:|---:|
| 0 | the buyer (self) | 10% | ₱1,000 | ₱56 | ₱105 | ₱10 |
| 1 | upline 1 | 5% | ₱500 | ₱28 | ₱53 | ₱5 |
| 2 | upline 2 | 5% | ₱500 | ₱28 | ₱53 | ₱5 |
| 3 | upline 3 | 5% | ₱500 | ₱28 | ₱53 | ₱5 |
| 4 | upline 4 | 3% | ₱300 | ₱17 | ₱32 | ₱3 |
| 5 | upline 5 | 3% | ₱300 | ₱17 | ₱32 | ₱3 |
| 6 | upline 6 | 2% | ₱200 | ₱11 | ₱21 | ₱2 |
| 7 | upline 7 | 2% | ₱200 | ₱11 | ₱21 | ₱2 |
| 8 | upline 8 | 2% | ₱200 | ₱11 | ₱21 | ₱2 |
| 9 | upline 9 | 1% | ₱100 | ₱6 | ₱11 | ₱1 |
| 10 | upline 10 | 1% | ₱100 | ₱6 | ₱11 | ₱1 |
| | **total** | **39%** | **₱3,900** | **₱219** | **₱413** | **₱39** |

The totals in those columns are sums of individually rounded level amounts, not `39%` rounded
once: ₱555 × 39% = ₱216.45, but the simulator credits ₱219 because every level rounds up to the
nearest peso (`56 + 3×28 + 2×17 + 3×11 + 2×6`). ₱10,000 and ₱100 happen to be exact; ₱1,050 gives
₱413 against a nominal ₱409.50.

### 7.3 Level 0: the self-rebate

The buyer is always paid 10% of their own purchase, into `unilevelEarned[buyer]` and the month's
pool:

- even when the buyer is Inactive;
- even when the buyer is not Harvest-qualified;
- even when the buyer has no uplines (they bought as a direct of the company, for example);
- before any upline is considered.

This is a company-funded rebate, not a transfer from an upline, and it is included in the
"Unilevel paid" figures and the month's Unilevel total. It is the reason the maximum Unilevel cost
of a purchase is 39% rather than 29%.

### 7.4 The upline walk

[`recordPurchase()`](../simulator.html#L210-L231):

```
amount = max(0, round(input));  if amount == 0: stop
sales[buyer] += amount;  purchasedAll[buyer] += amount
credit buyer 10% of amount (level 0)

cur = sponsor(buyer);  L = 1
while cur != null and L <= 10:
    if cur > 0:                                  # company is skipped, never paid
        if unilevelFlag[cur] !== false:          # missing flag = Active
            credit cur round(rate[L] × amount); paidCount++
        else:
            skippedCount++                       # level consumed, no compression
    cur = sponsor(cur);  L++
```

Rules that follow from this:

- **Sponsor chain only.** The walk is up `sp`, not through the tree, so a member's Unilevel
  uplines are exactly their sponsor, their sponsor's sponsor, and so on.
- **Ten sponsor-chain steps maximum.** A chain of 11 uplines pays levels 1–10; the 11th gets
  nothing. The company is never paid, so a purchase by a direct of the company gets only the
  level-0 rebate.
- **Inactive uplines are skipped, not compressed.** The next upline still receives the rate for
  their own level; nobody moves up.
- **No purchase requirement on the buyer**, no rank requirement on anyone, no annual/monthly cap on
  the number of purchases.

### 7.5 Active status

The flag is `unilevelFlag[id]`; `true` = Active, `false` = Inactive, absent = Active by default.

| Question | Answer |
|---|---|
| Who decides it? | **Advance month**, for every member at once |
| From what? | that month's **personal purchases** (`sales[id]`), manual or Auto-purchase |
| Threshold | `sales[id] ≥ ₱500` — exactly ₱500 qualifies |
| Effect | whether that member receives levels 1–10 from downline purchases next month |
| Not affected | their own level-0 rebate; their ability to buy; their rank; their Harvest position |
| Default before the first advance | Active (no flag stored) |
| Stored alongside | `unilevelLastSales[id]`, shown as "you personally bought ₱X last month" |
| Next advance | overwritten for every member, `true` or `false` — no stale entries |

The panel's "This month so far" line measures the *current* month's purchases against ₱500 and is
about next month's flag; the "currently Active/Inactive" line is this month's flag, set at the last
advance. Both are visible at once, which is why a member can be Inactive today while already on
track to be Active next month.

Before the first advance no flags exist, so the dashboard's "Unilevel active members" count equals
the number of members. After the first advance it counts only the members who cleared ₱500 in the
month that just ended.

### 7.6 Worked examples

**A. A 12-deep sponsor chain, purchase ₱10,000.** Buyer is member 12; sponsors are 11, 10, …, 1,
then company.

| Level | Recipient | Amount |
|---:|---|---:|
| 0 | buyer 12 | ₱1,000 |
| 1 | 11 | ₱500 |
| 2 | 10 | ₱500 |
| 3 | 9 | ₱500 |
| 4 | 8 | ₱300 |
| 5 | 7 | ₱300 |
| 6 | 6 | ₱200 |
| 7 | 5 | ₱200 |
| 8 | 4 | ₱200 |
| 9 | 3 | ₱100 |
| 10 | 2 | ₱100 |
| — | member 1 and company | ₱0 (beyond ten levels) |

`upTotal = ₱2,900`, `paidCount = 10`, `skippedCount = 0`; total credited ₱3,900.

**B. The same chain, purchase ₱555.** Level 0 ₱56; levels 1–3 ₱28 each; levels 4–5 ₱17 each;
levels 6–8 ₱11 each; levels 9–10 ₱6 each. Total ₱219, as in the rate table.

**C. Upline 11 is Inactive.** With a ₱1,000 purchase: level 0 ₱100; level 1 skipped (₱50 not
paid); levels 2–10 pay ₱50, ₱50, ₱30, ₱30, ₱20, ₱20, ₱20, ₱10, ₱10 = ₱240. `paidCount = 9`,
`skippedCount = 1`, total credited ₱340. The skipped ₱50 is not redirected to anyone.

**D. Buyer is a direct of the company.** Purchase by member 1 (`sp = 0`): `l0` is paid, the walk
hits company at level 1 and pays nobody; `dist` is empty.

### 7.7 Auto-purchase

The **Auto-purchase** button ([`autoPurchasePass()`](../simulator.html#L235-L244)) runs
`recordPurchase()` for roughly 30% of the network:

- each member independently buys with probability `PUR_RATE = 0.30`;
- the amount is `500 + floor(random × 46) × 100`, i.e. 46 equally likely values from ₱500 to
  ₱5,000 in ₱100 steps;
- every simulated purchase pays Unilevel in real time exactly like a manual purchase;
- the log of the pass (`buyer`, `amount`, level-0 amount, paid uplines) replaces the previous log
  wholesale; the dashboard's **Last Auto-purchase: Unilevel breakdown** panel filters that one pass
  for the logged-in member as buyer and/or paid upline;
- only members are sampled (`i = 1 … N`); the company is never a buyer;
- the pass does **not** advance the month and does not pay Royalty; it only creates sales and
  Unilevel.

### 7.8 What does *not* affect Unilevel

- Rank, Harvest qualification, team size, direct count.
- The buyer's Active flag (only the *recipient's* flag matters, and level 0 ignores flags).
- The month boundary — the rate does not change with the month, and a purchase on the last day pays
  exactly like one on the first.
- The purchase's product or package: a "purchase" in the simulator is an arbitrary peso amount with
  no SKU, order or receipt model.

---

## 8. Cross-stream behaviour and company economics

### 8.1 Event → effect matrix

| Action | Harvest queue | General / Team | Royalty | Unilevel | Revenue model | Saved |
|---|---|---|---|---|---|---|
| Register one member | sponsor may be appended when reaching 3 directs | derived amounts may increase at once (new `N` / new `n`) | ranks may change live; no payment | none | +₱5,000 registration | yes |
| Bulk register (up to 20,000) | qualification tracked incrementally | same, per added member | same | none | +₱5,000 each | yes |
| Record a purchase for self | — | — | adds to GS/PS; preview updates | pays instantly | +amount | yes |
| Auto-purchase | — | — | adds to GS/PS | pays instantly per buyer | +total bought | yes |
| Advance month | — | — | pays eligible rank holders | sets next month's flags; snapshots the month's total; pays nothing | — | yes |
| Reset (2 clicks) | cleared | cleared | cleared | cleared | cleared | yes |

### 8.2 Order inside an event

**Registration** ([`reg()`](../simulator.html#L126-L137)): validate name → find sponsor → append
the user (direct count implicitly rises) → possibly append the sponsor to `mx` → run the Indirect
walk → stash `lastIndirect` → the caller saves, re-renders and builds a toast from before/after
snapshots of the Harvest and rank states. General and Team amounts are not stored, so the toast's
before/after comparison of paid-level counts is how the UI reports new Harvest money. Registering
from the signed-out form also signs the operator in as the new member; the dashboard's **Register
under me** and **Grow** forms do not change the login.

**Purchase**: round the amount → add to `sales` and `purchasedAll` → pay level 0 → walk the sponsor
chain → accumulate `unilevelMonthTotal` → return a result object the caller toasts.

**Advance month** ([`evaluateMonth()`](../simulator.html#L247-L265)): ranks and group sales are
snapshotted first, then Royalty is credited, then the Unilevel flags and last-sales snapshot are
written, then the Unilevel month total is rolled over, then `sales` is cleared and `month`
incremented. Royalty therefore uses the month's sales *before* they are cleared, and the Unilevel
flags are derived from the same numbers.

### 8.3 The Company Report

The dashboard's Company Report is a separate panel from the per-stream tiles. It always includes
every peso, regardless of the Count checkboxes.

| Line | Formula |
|---|---|
| Registration revenue | `(users.length − 1) × ₱5,000` |
| Repeat-purchase revenue | `Σ purchasedAll` |
| **Total revenue** | registration + purchases |
| General Harvest paid | `Σ over queue of earnedG(position)` (derived) |
| Team Harvest paid | `Σ over queue of earnedT(n)` (derived) |
| Royalty paid | `Σ earnedAll` (ledger) |
| Unilevel paid | `Σ unilevelEarned` (ledger) |
| Indirect Referral paid | `Σ indirectEarned` (ledger) |
| **Total payouts** | the five lines above |
| Net profit | total revenue − total payouts |
| Margin | `net / revenue × 100`, one decimal; 0 when revenue is 0 |

Caveats built into the model:

- Registration is modeled as a ₱5,000 Basic account for every member, whether they were added
  singly or in a batch.
- Every purchase is counted as revenue in full; there is **no cost of goods**, no fees, no tax, no
  refunds or chargebacks.
- The two Harvest lines are current-state credits, not cash paid out; they rise as the queue/tree
  grows, without a payment event. The other three lines are ledgers.
- The margin can go negative; the simulator does not cap payouts or tune the plan.

### 8.4 The Count checkboxes

The `Count General / Team / Royalty / Unilevel / Indirect Referral` checkboxes (`S.on`) change only
*whose numbers are added together for personal ranking*:

- the **Your total** panel;
- the **Total ₱** column of the "All members, richest first" table (and therefore its sort order);
- the **total credited** stat tile.

They do **not** change the Company Report, the per-stream columns, the per-stream account panels,
or any stored ledger. All five default to on; the state is persisted.

### 8.5 Plan-economics observations

These are properties of the model, not tuning advice:

- **Unilevel alone pays 39% of every purchase** when an 11-level chain exists and every upline is
  Active (10% self + 29% uplines). With a shorter chain or inactive uplines it pays less; it never
  pays more.
- **Royalty adds up to 10% of group sales plus 15% of personal sales** for a Director. Personal
  sales are already inside group sales, so a Director's own purchase is paid at an effective 25%.
- **Harvest is a fixed liability per completed level, independent of purchase volume.** The full
  General ladder is ₱8,381,000 per member and the full Team ladder is ₱5,095,000 per member. The
  General ladder needs 88,573 qualified members for the apex (later positions need more) and the
  full Team ladder needs 88,572 qualified-after teammates, but the obligations do not scale with
  the ₱5,000 registration fee.
- At `N = 88,573` qualified members, the General board alone has credited ₱190,208,000 against
  ₱442,865,000 of registration revenue (about 43%), before any Team, Royalty, Unilevel or Indirect
  money.
- **The gates are cheap by design**: 3 directs for Harvest, 10 directs for Leader, ₱500 a month for
  Unilevel Active. Volume quotas only appear at the royalty ranks (₱500k / ₱1M / ₱5M).

---

## 9. Parameters, thresholds and ceilings

### 9.1 Constants

[`L75–L86`](../simulator.html#L75-L86):

| Constant | Value | Used by |
|---|---|---|
| `BONUS_G` | `[0, 2,000, 4,000, 8,000, 12,000, 25,000, 100,000, 250,000, 480,000, 2,500,000, 5,000,000]` | General level bonuses, index 1–10 |
| `BONUS_T` | `[0, 1,000, 2,000, 4,000, 6,000, 12,000, 50,000, 120,000, 200,000, 1,200,000, 3,500,000]` | Team level bonuses |
| `T(L)` | `3^L` | slot counts, thresholds |
| `RANK_QUOTA` | Supervisor 500,000; Manager 1,000,000; Director 5,000,000 | Royalty gate |
| `GROUP_PCT` | Supervisor .03; Manager .05; Director .10 | Royalty group rate |
| `PERSONAL_PCT` | Supervisor .05; Manager .10; Director .15 | Royalty personal rate |
| `PUR_RATE` | .30 | Auto-purchase buyer probability |
| `PUR_MIN` / `PUR_MAX` | 500 / 5,000 | Auto-purchase amount range |
| `UNILEVEL_PCT` | `[.10, .05, .05, .05, .03, .03, .02, .02, .02, .01, .01]` | Unilevel levels 0–10 |
| `UNILEVEL_QUOTA` | 500 | Active threshold |
| `INDIRECT_BONUS` | `[0, 500, 100, 50, 40, 30, 20, 10, 5, 5, 5]` | Indirect levels 1–10 (index 0 unused) |
| `BASIC_ACCOUNT_PRICE` | 5,000 | Registration revenue model |
| `RN` | Member, Leader, Supervisor, Manager, Director | rank display |
| `KEY` | `royalty_harvests_v1` | `localStorage` key |

### 9.2 Ceilings per member

| Stream | Maximum per member | Reached when |
|---|---:|---|
| General Harvest | ₱8,381,000 | the member's ten level blocks are all filled (apex at `N = 88,573`) |
| Team Harvest | ₱5,095,000 | `n ≥ 88,572` qualified-after teammates |
| Royalty | rate × sales, no cap | any month the quota is met |
| Unilevel | 39% of each purchase (10% self + 29% uplines) | 10 uplines exist and all are Active |
| Indirect Referral | ₱765 per registration (see Appendix A) | 10 uplines above the new member |

### 9.3 Minimum structure for the royalty ladder

Already tabulated in §6.4: Leader 11 members, Supervisor 111, Manager 1,111, Director 11,111
(assuming the most compact tree). These are the smallest networks in which each rank can exist; a
real tree will usually be larger.

---

## 10. Where each number appears

| UI element | What it shows | Source |
|---|---|---|
| Stats strip | members registered (`users.length − 1`), Harvest-qualified (`S.mx.length`), counts of Leader/Supervisor/Manager/Director, General/Team/Royalty/Unilevel/Indirect **credited**, checkbox-filtered total, Unilevel active members, deepest General/Team level | [`dash()`](../simulator.html#L373-L505) |
| Company Report | revenue, payouts, net, margin and the ten-line table described in §8.3 | `dash()` |
| Count checkboxes | which streams feed the personal total, the members table total and sort | `S.on` |
| Your Harvest account | qualification progress or position, parent, team size, direct count in team, General + Team amounts, count of downline qualified before you | `dash()` |
| Your Royalty account | rank, directs, rank progress, month PS/GS/quota, progress bar, "If evaluated now" preview, all-time royalty | `dash()` |
| Your Unilevel account | current Active/Inactive, last month's personal sales and quota, month-to-date personal sales and next-month progress, all-time Unilevel | `dash()` |
| Your Indirect Referral account | explanation and all-time Indirect | `dash()` |
| Your total | five streams added with the checkbox mask | `sum()` in `dash()` |
| Grow the network | register one under me, or bulk under me / whole network with Random, Balanced or Chain | [`sponsorPlan()`](../simulator.html#L269-L294), [`bulkAdd()`](../simulator.html#L299-L317) |
| Rank ladder | rank requirements, quotas and percentages | [`ladderT()`](../simulator.html#L189-L196) |
| Indirect schedule / Unilevel schedule | the two rate tables | [`indirectT()` / `unilevelT()`](../simulator.html#L197-L198) |
| Month N | purchase for me, Auto-purchase, Advance month | [`doPurchase()`](../simulator.html#L545-L554), [`doAutoPurchase()`](../simulator.html#L555-L561), [`doAdvance()`](../simulator.html#L562-L568) |
| Last Auto-purchase breakdown | the last pass's buyers, amounts and the viewer's level-0/upline slices | `lastAutoPurchaseLog` |
| General matrix / Team matrix | the ten-level slot tables | `lvT(levelsG(p))`, `lvT(levelsT(n))` |
| Your general matrix, by position | filled slots with relation and placed-under | `dash()` |
| Your team, in qualification order | the qualified-after roster with general position and private-matrix level | `dash()` |
| Your Royalty team | entire downline sorted by rank then id | `dash()` |
| Whole network fill at the top | the apex's General table | `levelsG(0)` |
| Whole network this month | combined personal sales this month; last month's Royalty count/total and Unilevel total | `gsAll[0]`, `lastMonth*` |
| All members, richest first | 13 columns; clicking a name logs in as them | `dash()` |
| Toasts | registration/purchase/advance/auto results, including rank-ups and Harvest level completions | [`toast()`](../simulator.html#L333-L347), `qmsg()`, `rankMsg()` |

Two UI mechanics worth knowing:

- **View state is preserved across re-renders.** Text inputs and selects are keyed by `data-f` and
  restored by `keep()`, and keyboard focus returns to the same key. The bulk "how many" inputs have
  no `data-f`, so they reset to their defaults (10 and 100) on every render.
- **Pagination** is per table (`gen`, `team`, `rteam`, `apl`, `members`), defaults to 10 rows and
  offers 10/25/50/100. Page state is not persisted across reloads.

The members table sorts by the checkbox-filtered total, highest first; ties put Harvest-qualified
members before unqualified ones, then the lower queue position, then the lower id.

---

## 11. Edge cases, quirks and non-goals

### 11.1 Harvest and the matrices

1. **General Harvest pays across team lines.** Level blocks are queue positions, not sponsor
   descendants. A member can be paid from someone they did not sponsor, and the "Relation" column
   shows this openly. This is a design property of a shared board, not a bug.
2. **Harvest credits are derived, never ledgered.** The Company Report's General/Team lines are
   recomputed from the current queue and tree on every render. They cannot be paid twice, but they
   are not a record of cash paid either.
3. **Team Harvest needs its own qualification.** An unqualified member with qualified downline
   still earns ₱0 Team (and no General), because `counts()` only credits ancestors that are in the
   queue.
4. **Earlier-qualified downline are permanently excluded** from your Team matrix. A very productive
   downline member who reached 3 directs one day before you never counts toward your Team levels.
5. **Positions and qualification are irreversible.** No deletion, no re-parenting, no moving
   positions, so General earnings can only rise as the network grows.
6. **All-or-nothing levels.** No partial level payment; a block at `t−1` of `t` slots pays nothing.
7. **The deepest-level tile is optimistic.** It reports the deepest level with at least one filled
   slot (`dep()` tests `(3^(L+1)−1)/2 ≤ n`), not the deepest *completed* level. In absurdly large
   queues the General side could even name a level above 10, where nothing pays.
8. **Bulk registration can be very large** (1–20,000 per action) and each added member is written
   to `mx` at most once. There is no global cap.

### 11.2 Royalty

9. **Leader earns nothing.** Reaching 10 directs only opens the Supervisor path; the ladder table
   labels Leader "not royalty-eligible".
10. **Missing the quota zeroes everything**, including the personal percentage. A Supervisor with
    ₱499,999 GS earns ₱0.
11. **Group sales include self.** The quota is not "downline volume"; the member's own purchases
    count, which makes the quota self-fundable (see §6.5).
12. **Personal sales are counted twice**: once inside GS and again as PS. This is deliberate in the
    model but worth stating when reconciling.
13. **Ranks count people, not production.** Ten direct sponsees make their sponsor a Leader whether
    or not any of them ever buy anything; ten such Leaders below a member make that member a
    Supervisor.
14. **Ranks are live, payouts are monthly.** A rank-up in the middle of a month changes the
    preview immediately but only pays at the next Advance.
15. **No rank demotion or history.** The tree only grows; `ranks()` derives from the current tree
    and stores nothing. There is no way to see last month's rank.
16. **No carry-over.** Sales reset after every Advance; unused group volume is lost, and a month
    with no purchases pays ₱0.

### 11.3 Unilevel

17. **Level 0 pays even when the buyer is Inactive.** Active status only gates upline receipts.
18. **No compression.** An Inactive upline is skipped; the next upline keeps their original level
    rate and receives no redirected volume.
19. **The company is never paid, and its step is consumed.** A direct of the company has no earning
    uplines; an 11-deep chain pays only the first ten uplines.
20. **Rounding is per level**, so the total can exceed the nominal percentage by a few pesos on
    small purchases (see the ₱555 example).
21. **A missing flag means Active.** Month 1 and every brand-new member are treated as Active until
    their first Advance, so they can receive upline Unilevel immediately.
22. **The flag is set from the month just ended**, so the panel's current status and the
    "on track for next month" line can disagree; that is the two-clock design, not a stale value.
23. **Only the logged-in member can buy manually.** There is no "record a purchase for someone
    else" control, so keeping a specific member Active requires logging in as them (or relying on
    Auto-purchase, which picks randomly).
24. **A purchase is an arbitrary amount.** There is no package, product, quantity, order or
    receipt; the Unilevel rate applies to whatever number is entered or generated.

### 11.4 Data, UI and simulator limits

25. **Single-browser state.** `localStorage` key `royalty_harvests_v1`; no server, no accounts, no
    audit. Anyone can log in as any member by name (the member table is a login shortcut).
26. **The company cannot be logged into** (`doLogin` rejects id 0), so the "Admin view" is simply a
    member's dashboard with network-wide panels.
27. **Reset is armed by one click** and disarmed by any other render; it deletes everything,
    including the login.
28. **Auto-purchase replaces its log**; only the last pass is visible, and its per-purchase rows
    omit skipped uplines.
29. **Bulk names are `m<id>`** and skip the 2–20-character/unique validation. A member who
    registered a name like `m42` before id 42 existed could collide with the generated name.
30. **Balanced bulk is a soft 10-direct cap** intended to build Leaders; Random has no cap and
    Chain gives each new member one direct (so the batch itself creates no 3-direct sponsors).
    Balanced fills breadth-first; growing the whole network from empty puts the first member under
    company, then ten per member in creation order.
31. **Only the last month's network totals are kept.** There is no monthly history beyond
    `lastMonthTotal`, `lastMonthCount` and `lastMonthUnilevelTotal`.
32. **The checkboxes are cosmetic to the plan.** They only change personal totals and ranking.
33. **No cost of goods, tax, fees, refunds, chargebacks, payout caps, anti-abuse or KYC.** The model
    is a plan calculator, not an accounting system.
34. **No package, product or purchase-eligibility rules.** A "purchase" does not have to be a
    repeat purchase, a minimum, or tied to a package; the name "Repeat-Purchase" describes the
    intended use, not an enforced rule.
35. **No official terms.** The file is the prototype reference; the README says final compensation
    terms must come from verified official material.

---

## 12. Verification recipes

### Recipe 1 — build a reproducible network (UI)

1. Open [`simulator.html`](../simulator.html) in a browser.
2. If the page is signed in, click **Reset** twice. If it is signed out but still has old data,
   use a private window or clear this site's storage — the signed-out screen has no Reset button.
3. Register `ada` under sponsor `company`.
4. On **Grow the network** → *whole network* form, choose **Balanced**, count **100**, click **Grow
   whole network**.

Verified result: **101 members registered**, **10 Harvest-qualified**, **10 Leaders**, 0
Supervisors, **General ₱6,000**, **Team ₱1,000**, **Indirect ₱59,000**, Royalty ₱0, Unilevel ₱0.
The queue is ids 1–10 (`ada`, `m2` … `m10`); `ada` is position #1 and has `n = 9`; positions #1–#3
each earn General ₱2,000 (level 1 only) and `ada` earns Team ₱1,000.

### Recipe 2 — one purchase and one month (UI)

Continuing Recipe 1, still signed in as `ada`:

1. Record a purchase of **5,000**.

Verified result: level 0 pays `ada` ₱500; its sponsor is company, so no upline is paid;
`unilevelEarned[ada] = 500`, `purchasedAll[ada] = 5,000`.

2. Click **Advance month**.

Verified result: month 2; Royalty ₱0 (the 10 Leaders are not royalty-eligible and `ada`'s GS is
₱5,000, far below ₱500,000); Unilevel active members becomes 1 (`ada`); the other 100 are
Inactive; `lastMonthUnilevelTotal = 500`; `sales` is empty; `lastMonthTotal = 0`.

### Recipe 3 — Unilevel arithmetic (the simulator's functions)

A 12-deep sponsor chain (member `i` sponsored by `i−1`, company at the top), purchase of ₱10,000 by
the last member: level 0 ₱1,000, ten uplines ₱2,900, total **₱3,900**, ten paid, none skipped.
With ₱555: **₱219** after per-level rounding. Mark upline 11 Inactive and buy ₱1,000: **₱340**
(₱100 level 0 + ₱240 across nine uplines, one skipped). These are the figures in §7.6.

### Recipe 4 — Royalty arithmetic (constructed tree)

Build `boss` with 10 directs, and give each of those 10 directs 10 directs (111 members). `boss`
is a Leader (10 directs) and a Supervisor (10 downline Leaders). Set this month's sales to
₱45,000 for each of the 10 directs and ₱60,000 for `boss`. Group sales = ₱510,000, quota met;
payout = `0.03 × 510,000 + 0.05 × 60,000 =` **₱18,300**. Each direct is a Leader and earns no
royalty. See §6.7.

### Recipe 5 — General board arithmetic

With a queue of exactly 20 qualified members, total General credited is **₱16,000** (six level-1
payments and one level-2 payment). With 121, it is **₱176,000**; with 88,573, **₱190,208,000**.
These match the closed form in §4.6 and are independent of the sponsor tree.

---

## 13. Source map and glossary

### 13.1 Functions

| Function / block | Lines | Responsibility |
|---|---|---|
| Constants, `RN`, formatters, `KEY` | [75–88](../simulator.html#L75-L88) | all tunable numbers |
| `fresh()` | [90](../simulator.html#L90) | initial state and field list |
| `load()` / `save()` and migrations | [91–101](../simulator.html#L91-L101) | persistence, `unilevelGate` → `unilevelFlag` |
| `U`, `byName`, `directs`, `pos` | [102–105](../simulator.html#L102-L105) | lookups |
| `payIndirectReferral()` | [110–122](../simulator.html#L110-L122) | 10-level flat registration bonus |
| `reg()` | [126–137](../simulator.html#L126-L137) | validation, registration, Harvest qualification, Indirect |
| `levelsG`, `levelsT`, `earn`, `lvT`, `dep`, `depth`, `lvOf` | [140–149](../simulator.html#L140-L149) | matrix fill arithmetic and table rendering |
| `isUp`, `counts`, `team` | [150–154](../simulator.html#L150-L154) | subtree membership, Team Harvest counts |
| `snap()` | [155](../simulator.html#L155) | paid-level counts for toasts |
| `rollup()` | [159–163](../simulator.html#L159-L163) | subtree sums (ranks, group sales, Royalty team) |
| `directCounts()` | [164](../simulator.html#L164) | direct sponsee counts |
| `ranks()`, `rankName`, `rankLvl` | [168–181](../simulator.html#L168-L181) | live rank ladder |
| `groupSalesAll()`, `teamOf()` | [182–188](../simulator.html#L182-L188) | Royalty GS and full subtree |
| `ladderT`, `unilevelT`, `indirectT` | [189–198](../simulator.html#L189-L198) | rate tables |
| `payout()` | [200–205](../simulator.html#L200-L205) | Royalty quota gate and formula |
| `recordPurchase()` | [210–231](../simulator.html#L210-L231) | sales, level 0, upline walk, skips |
| `autoPurchasePass()` | [235–244](../simulator.html#L235-L244) | 30% / ₱500–₱5,000 simulated purchases |
| `evaluateMonth()` | [247–265](../simulator.html#L247-L265) | Royalty, Active flags, month rollover |
| `sponsorPlan()` | [269–294](../simulator.html#L269-L294) | chain / balanced / random sponsors |
| `bulkAdd()` | [299–317](../simulator.html#L299-L317) | fast batch registration |
| `rankSnap`, `rankMsg`, `qmsg` | [319–330](../simulator.html#L319-L330) | before/after toast sentences |
| `toast`, `keep` | [333–355](../simulator.html#L333-L355) | notifications, focus/value preservation |
| Pagination helpers | [358–371](../simulator.html#L358-L371) | per-table paging |
| `dash()` | [373–505](../simulator.html#L373-L505) | the entire signed-in view |
| `authView()` | [507–512](../simulator.html#L507-L512) | register / log in |
| Event handlers | [514–569](../simulator.html#L514-L569) | register, login, grow, purchase, auto, advance, reset |
| `render()` | [571–579](../simulator.html#L571-L579) | view switch and alert region |

### 13.2 Glossary

| Term | Meaning in this document |
|---|---|
| **sponsor** | the member whose id is stored in `sp`; the person who registered you |
| **direct** | a member whose sponsor is you |
| **downline / team** | every member reachable through `sp` links below a member, any depth, any status |
| **qualified** | Harvest-qualified: in `S.mx`, 3 directs, position fixed |
| **queue / position** | the qualified members in qualification order; position #1 = 0-based index 0 |
| **N** | number of qualified members = queue length |
| **n** | a member's count of qualified-after downline — the Team Harvest fill count |
| **GS / PS** | group sales (self + downline purchases this month) / personal sales (own purchases this month) |
| **Active** | the Unilevel recipient flag for the current month, set at the previous Advance from personal sales ≥ ₱500 |
| **credited** | an amount the model says has been earned; for Harvest it is derived and not ledgered |
| **month** | the simulator's monthly cycle, advanced only by **Advance month** |
| **apex** | position #1, the first member ever to reach 3 directs |
| **block** | the `3^L` consecutive queue positions that form a member's level-L slots |

---

## Appendix A — Indirect Referral Bonus

Not one of the four add-ons, but present in the same model, paid on the same registration event,
and included in the Company Report. It is a flat, unconditional bonus to each upline on
registration ([`payIndirectReferral()`](../simulator.html#L110-L122)).

| Level | Amount | Level | Amount |
|---:|---:|---:|---:|
| 1 | ₱500 | 6 | ₱20 |
| 2 | ₱100 | 7 | ₱10 |
| 3 | ₱50 | 8 | ₱5 |
| 4 | ₱40 | 9 | ₱5 |
| 5 | ₱30 | 10 | ₱5 |
| | | **Total** | **₱765** |

Rules: walks the new member's sponsor chain up to 10 levels; the company is skipped but consumes a
level; each recipient gets the flat amount for that level; no quota, no rank, no Active status, no
monthly cycle; paid inside the registration (including bulk registration, where the batch total is
summed for the toast). A short chain simply pays fewer levels — nothing is redirected or
compressed.

## Appendix B — Formula card

```
T(L)                  = 3^L

HARVEST QUALIFICATION
  qualified           ⇔ directs ≥ 3 (checked at registration; append-only queue)

GENERAL HARVEST
  start(q,L)          = 3^L·q + (3^L − 1)/2
  filled(q,L)         = clamp(N − start(q,L), 0, 3^L)
  paid(q,L)           = filled == 3^L  ⇔  N ≥ 3^L·q + (3^(L+1) − 1)/2
  earned(q)           = Σ_{paid L} BONUS_G[L]
  boardTotal(N)       = Σ_L BONUS_G[L]·max(0, floor((N − (3^L − 1)/2) / 3^L))

TEAM HARVEST
  n                   = # qualified descendants that qualified after the member
  filled(n,L)         = clamp(n − (3^L − 3)/2, 0, 3^L)
  paid(n,L)           = filled == 3^L  ⇔  n ≥ (3^(L+1) − 3)/2
  earned(n)           = Σ_{paid L} BONUS_T[L]

ROYALTY
  Leader              ⇔ directs ≥ 10
  Supervisor          ⇔ Leader ∧ #{downline Leader+} ≥ 10
  Manager             ⇔ Supervisor ∧ #{downline Supervisor+} ≥ 10
  Director            ⇔ Manager ∧ #{downline Manager+} ≥ 10
  GS                  = PS + Σ downline PS            (this month, all depths)
  royalty             = GS ≥ quota ? round(g%·GS + p%·PS) : 0

UNILEVEL
  pay(0)              = round(0.10·amount)            always, to the buyer
  pay(L)              = round(rate[L]·amount)         if upline L exists, is not company, is Active
  earned              = Σ paid levels, up to L = 10
  Active(next month)  ⇔ this month's personal purchases ≥ 500

INDIRECT
  pay(L)              = INDIRECT_BONUS[L]             L = 1…10, on registration, unconditional
```
