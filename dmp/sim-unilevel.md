# Unilevel Repeat-Purchase Bonus — End-to-End Walkthrough

**Source of truth:** `simulator.html` (root of `C:\laragon\www\ecowealth`, 49,623 bytes, last modified 2026-11-01 14:52).
**Scope:** the Unilevel Repeat-Purchase Bonus only. Harvest (General/Team), Royalty, and Indirect Referral appear only where they touch Unilevel or where the user first meets them.
**Method:** every number, rule, and edge case below was read from the file and then **re-executed** by loading the file's own `<script>` block into Node 22 with a stubbed `document`/`localStorage` (2026-11-12). No rules were inferred from UI copy where the code disagreed with it; the code wins, and the one place where the UI prose is looser than the code is flagged in §7.4.

---

## 0. Contents

1. [TL;DR — the whole mechanic in eight lines](#1-tldr--the-whole-mechanic-in-eight-lines)
2. [The numbers (constants)](#2-the-numbers-constants)
3. [Where the state lives](#3-where-the-state-lives)
4. [Step 1 — The user registers](#4-step-1--the-user-registers)
5. [Step 2 — A purchase is recorded (the engine)](#5-step-2--a-purchase-is-recorded-the-engine)
6. [Step 3 — Auto-purchase (the bulk version)](#6-step-3--auto-purchase-the-bulk-version)
7. [Step 4 — Advance month (the eligibility gate)](#7-step-4--advance-month-the-eligibility-gate)
8. [Who gets paid — the full decision table](#8-who-gets-paid--the-full-decision-table)
9. [Quota rules and edge cases](#9-quota-rules-and-edge-cases)
10. [Verified end-to-end trace](#10-verified-end-to-end-trace)
11. [How Unilevel relates to the other three streams](#11-how-unilevel-relates-to-the-other-three-streams)
12. [UI map — where you see it](#12-ui-map--where-you-see-it)
13. [Pseudocode](#13-pseudocode)
14. [Reproduce it yourself in the browser](#14-reproduce-it-yourself-in-the-browser)
15. [Code map (line index)](#15-code-map-line-index)
16. [Gotchas / FAQ](#16-gotchas--faq)

---

## 1. TL;DR — the whole mechanic in eight lines

1. **Registration pays ₱0 of Unilevel.** It only creates the sponsor pointer that Unilevel will later walk upward. The only money at registration is Indirect Referral.
2. **Unilevel pays on every recorded purchase, instantly** — no "Advance month" needed, no approval step, no queue.
3. **The buyer always gets 10% of their own purchase** — Level 0 — even if the buyer is Inactive.
4. **Up to 10 uplines get a cut**: 5%, 5%, 5%, 3%, 3%, 2%, 2%, 2%, 1%, 1% for Levels 1–10 (each level = one sponsor hop up).
5. **An upline is paid only if their flag is not explicitly `false`.** `true` or *no flag at all* both pay.
6. **The flag is decided only at "Advance month",** from that month's **personal** purchases: ≥ ₱500 → Active for the whole next month; < ₱500 → Inactive for the whole next month.
7. **A missing flag means Active.** That is why Month 1 works, and why a brand-new member earns from their downline immediately.
8. **Ceiling:** if all 10 uplines are Active, the network pays out 39% of the purchase (10% + 29%), rounded line by line.

> One sentence: *every purchase pays 10% to the buyer and 5/5/5/3/3/2/2/2/1/1% to as many as ten sponsors above them who were Active as of the last month-end.*

```
REGISTER ──► ₱0 Unilevel. Creates the sponsor pointer. Flag stays absent ⇒ Active.
   │
   ├─ ANY MEMBER BUYS ──► recordPurchase(buyer, amount)
   │      ├─ buyer +10% ........................................ always, flag ignored
   │      ├─ sponsor, then sponsor's sponsor, … up to 10 hops
   │      │      +5% +5% +5% +3% +3% +2% +2% +2% +1% +1% ..... only if that upline's flag ≠ false
   │      ├─ company (id 0) ................................... never paid, not counted as skipped
   │      └─ buyer's month bucket += amount .................. feeds next month's flag AND Royalty
   │
   └─ ADVANCE MONTH ──► for every member: flag = (this month's personal purchases ≥ ₱500)
                        report the month's Unilevel total, zero the counter, wipe month buckets, month++
```

---

## 2. The numbers (constants)

`simulator.html:75–86`

| Constant | Value | What it controls |
|---|---|---|
| `UNILEVEL_PCT` | `[.10, .05, .05, .05, .03, .03, .02, .02, .02, .01, .01]` | Payout rate per level. Index = level; index 0 = the buyer. |
| `UNILEVEL_QUOTA` | `500` | Personal-purchase floor to stay/return Active (₱ per month). |
| `PUR_RATE` | `.3` | Auto-purchase: probability each member buys in one pass. |
| `PUR_MIN` / `PUR_MAX` | `500` / `5000` | Auto-purchase amount band (₱, steps of ₱100). |
| `BASIC_ACCOUNT_PRICE` | `5000` | Registration revenue in the Company Report. **Not** an Unilevel input. |
| `KEY` | `'royalty_harvests_v1'` | The single `localStorage` key for all state. |

### The rate ladder

| Level | Who | Rate | Cumulative if every level pays |
|---|---|---|---|
| 0 | The buyer (self) | **10%** | 10% |
| 1 | Direct sponsor | 5% | 15% |
| 2 | 2 up | 5% | 20% |
| 3 | 3 up | 5% | 25% |
| 4 | 4 up | 3% | 28% |
| 5 | 5 up | 3% | 31% |
| 6 | 6 up | 2% | 33% |
| 7 | 7 up | 2% | 35% |
| 8 | 8 up | 2% | 37% |
| 9 | 9 up | 1% | 38% |
| 10 | 10 up | 1% | **39%** |

The upline half sums to **29%** (`5+5+5+3+3+2+2+2+1+1`); with the buyer's 10% the maximum outflow is **39%** of the purchase. That ceiling is what keeps a purchase from ever paying out more than it brings in — the Company Report's "Unilevel paid" line is bounded by it.

### Payout reference (pesos per line, before per-line rounding)

| Purchase | L0 10% | L1–L3 5% each | L4–L5 3% each | L6–L8 2% each | L9–L10 1% each | Uplines (29%) | Grand total (39%) |
|---:|---:|---:|---:|---:|---:|---:|---:|
| ₱500 | 50 | 25 | 15 | 10 | 5 | 145 | 195 |
| ₱555 | 56 | 28 | 17 | 11 | 6 | 163 | **219** |
| ₱1,000 | 100 | 50 | 30 | 20 | 10 | 290 | 390 |
| ₱1,500 | 150 | 75 | 45 | 30 | 15 | 435 | 585 |
| ₱2,500 | 250 | 125 | 75 | 50 | 25 | 725 | 975 |
| ₱5,000 | 500 | 250 | 150 | 100 | 50 | 1,450 | 1,950 |

Note the ₱555 row: 39% of 555 is 216.45, but the actual total is **219**, because each line is rounded separately (see §5.5).

---

## 3. Where the state lives

One object `S`, serialized to `localStorage['royalty_harvests_v1']` on every mutation (`save()`, `simulator.html:101`). Unilevel-relevant fields, seeded in `fresh()` (`simulator.html:90`):

| Field | Shape | Written by | Read by |
|---|---|---|---|
| `S.users` | `[{id, name, sp}]` | `reg()`, `bulkAdd()` | everything; `sp` is the sponsor id (`0` = company) |
| `S.sales` | `{id: ₱}` | `recordPurchase()`; wiped by `evaluateMonth()` | Unilevel quota + Royalty group/personal |
| `S.purchasedAll` | `{id: ₱}` | `recordPurchase()` | Company Report revenue (all-time) |
| `S.unilevelEarned` | `{id: ₱}` | `recordPurchase()` (L0 **and** upline cuts) | dashboard, members table, Company Report |
| `S.unilevelMonthTotal` | `₱` | `recordPurchase()` | rolled into `lastMonthUnilevelTotal` at advance |
| `S.lastMonthUnilevelTotal` | `₱` | `evaluateMonth()` | "Whole network this month" panel, advance toast |
| `S.unilevelFlag` | `{id: boolean}` | `evaluateMonth()` — **whole map replaced** | the payout gate, "active members" stat, dashboard |
| `S.unilevelLastSales` | `{id: ₱}` | `evaluateMonth()` | dashboard ("you bought X last month") |
| `S.lastAutoPurchaseLog` | `[{buyer, amt, l0, dist}]` | `autoPurchasePass()` — **replaced, never appended** | the breakdown panel |
| `S.month` | `number` | `evaluateMonth()` | header, panels |

Two loading-time repairs (`simulator.html:92–100`):

- `S.on` is back-filled with `u:1` and `i:1` so old saves gain the Unilevel/Indirect tick-boxes.
- **Migration:** `S.unilevelFlag = S.unilevelFlag || S.unilevelGate || {}` then `delete S.unilevelGate`. The field used to be `unilevelGate` and used the *opposite* default (missing = closed). Explicit `true`/`false` values carry over; only never-evaluated members change meaning from "closed" to "Active".

`S.unilevelEarned` never has a key for the company (id `0`) — the payout loop only ever credits `cur > 0`.

---

## 4. Step 1 — The user registers

### 4.1 The function

`simulator.html:126–137`:

```js
// Register a free member. If the sponsor now has 3 directs, they join the Harvest queue. Royalty rank
// is never tracked here - it is derived fresh from the tree whenever ranks() is called. Indirect
// Referral always runs, as an unconditional side effect of registering - see payIndirectReferral().
function reg(name,spName){
  name=name.trim();
  if(!/^[a-z0-9_]{2,20}$/i.test(name))return'Username must be 2 to 20 letters, numbers or underscores.';
  if(byName(name))return'That username is already taken.';
  const sp=byName(spName||'company');
  if(!sp)return'Sponsor not found. Use an existing username, or "company".';
  S.users.push({id:S.users.length,name,sp:sp.id});
  if(sp.id&&pos(sp.id)<0&&directs(sp.id)>=3)S.mx.push(sp.id);
  const u=S.users[S.users.length-1];
  payIndirectReferral(u.id);
  return u;
}
```

### 4.2 What happens, in order

| # | Action | Failure mode | Unilevel effect |
|---|---|---|---|
| 1 | Trim the name | — | none |
| 2 | Validate `/^[a-z0-9_]{2,20}$/i` | returns the error string; caller re-renders it in the red `.err` box | none |
| 3 | Reject duplicate names (case-insensitive, `byName`) | `"That username is already taken."` | none |
| 4 | Resolve sponsor by name; blank defaults to `'company'` | `"Sponsor not found…"` | none |
| 5 | Append `{id: S.users.length, name, sp: sp.id}` | — | **This `sp` pointer is the only thing Unilevel ever needs.** |
| 6 | If the sponsor is a real member (`sp.id` truthy), not already queued, and now has ≥ 3 directs → push to the Harvest queue `S.mx` | — | none (Harvest only) |
| 7 | `payIndirectReferral(u.id)` — flat pesos to up to 10 uplines | — | none (Indirect only) |
| 8 | Return the user object | — | — |

The new member's `id` is `S.users.length` **before** the push, so ids are strictly increasing in join order and a sponsor always has a smaller id than its sponsee. Unilevel doesn't depend on that, but Royalty's `rollup()` does.

### 4.3 Unilevel state immediately after registration

| Field | Value |
|---|---|
| `S.unilevelEarned[newId]` | **absent** |
| `S.unilevelFlag[newId]` | **absent** — and absence is read as Active |
| `S.unilevelLastSales[newId]` | **absent** |
| `S.unilevelMonthTotal` | unchanged |

So the moment a member exists, they are **already Active for Unilevel** without doing anything, and they are already in the sponsor chain where their own later purchases will pay uplines. Nothing is "pending".

`dash()` renders this nuance explicitly (`simulator.html:381`):

```js
const myEverChecked = me.id in (S.unilevelLastSales||{});
```

…and the dashboard says *"(not checked yet - everyone starts Active until their first missed quota)"* until their first "Advance month" (`simulator.html:440`).

### 4.4 The one payment that *does* happen at registration — and why it isn't Unilevel

`payIndirectReferral()` (`simulator.html:110–122`) pays a flat, one-time, unconditional bonus up the same sponsor chain:

| Level up | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Bonus | ₱500 | ₱100 | ₱50 | ₱40 | ₱30 | ₱20 | ₱10 | ₱5 | ₱5 | ₱5 |

Contrast, because this is the classic point of confusion:

| | Indirect Referral | Unilevel Repeat-Purchase |
|---|---|---|
| Trigger | someone **registers** under you at any depth | someone **buys** product |
| Timing | once per new member, at registration | every purchase, forever |
| Amount | flat pesos per level | percentage of the purchase |
| Condition | none — no quota, no rank, no Active flag | upline must not be flagged Inactive (buyer's own 10% is unconditional) |
| Cap | 10 levels, max ₱765 per registration | 10 levels, max 29% + buyer's 10% |

A registration with 10 uplines therefore pays at most ₱765 in Indirect and **₱0** in Unilevel.

### 4.5 Worked tree for the rest of this document

Register in this order, sponsor in brackets — a pure "chain" so every level is easy to see:

```
company
└── m1        (sp = company)          ← 1 hop from company, 0 real uplines
    └── m2    (sp = m1)
        └── m3
            └── m4
                └── m5
                    └── m6
                        └── m7
                            └── m8
                                └── m9
                                    └── m10
                                        └── m11
                                            └── m12   ← deepest; 11 real hops from company
```

Because `m1`'s sponsor is the company (id `0`), any purchase by `m1` has **no** upline to pay. Because `m12` has eleven real ancestors, the 10-level cap bites: `m1` is 11 hops up and is **not** paid on `m12`'s purchases.

---

## 5. Step 2 — A purchase is recorded (the engine)

Two entry points reach the same function:

- **Manual:** the "Record a purchase for me" form (`doPurchase`, `simulator.html:545`) — always the **logged-in** member (`S.me`), no other member can be picked from the UI.
- **Bulk:** "Auto-purchase: simulate ~30% of the network buying" (`autoPurchasePass`, §6).

### 5.1 The function

`simulator.html:206–231`, including the author's own summary comment:

```js
// Unilevel pays in real time, on every purchase: Level 0 always goes to the buyer; levels 1-10 walk the
// sponsor chain and pay each upline unless THEIR flag (set by last month's evaluateMonth) is explicitly
// Inactive. A member with no entry yet - never evaluated, brand new, or still in month 1 - reads as
// Active by default, which is what lets month 1 (and any new member) earn from their downline immediately.
function recordPurchase(id,amt){
  amt=Math.max(0,Math.round(+amt||0));
  if(amt<=0)return null;
  S.sales[id]=(S.sales[id]||0)+amt;
  S.purchasedAll[id]=(S.purchasedAll[id]||0)+amt;
  const l0=Math.round(UNILEVEL_PCT[0]*amt);
  S.unilevelEarned[id]=(S.unilevelEarned[id]||0)+l0;
  S.unilevelMonthTotal=(S.unilevelMonthTotal||0)+l0;
  let cur=U(id).sp,upTotal=0,paidCount=0,skippedCount=0;const dist=[];
  for(let L=1;L<=10&&cur!==null;L++){
    if(cur>0){
      if(S.unilevelFlag[cur]!==false){
        const pay=Math.round(UNILEVEL_PCT[L]*amt);
        S.unilevelEarned[cur]=(S.unilevelEarned[cur]||0)+pay;
        S.unilevelMonthTotal=(S.unilevelMonthTotal||0)+pay;
        upTotal+=pay;paidCount++;dist.push({id:cur,level:L,amount:pay});
      }else skippedCount++;
    }
    cur=U(cur).sp;
  }
  return{amt,l0,upTotal,paidCount,skippedCount,dist};
}
```

### 5.2 Step by step

| # | Line | What it does |
|---|---|---|
| 1 | `amt = Math.max(0, Math.round(+amt \|\| 0))` | Coerce and round to whole pesos. `"abc"` → `0`; negatives → `0`. |
| 2 | `if(amt<=0)return null` | Guard. `doPurchase` turns `null` into *"Enter a purchase amount greater than 0."* |
| 3 | `S.sales[id] += amt` | Month bucket. Feeds this member's **next** Active decision **and** every upline's Royalty group sales. |
| 4 | `S.purchasedAll[id] += amt` | All-time bucket, used only as repeat-purchase revenue in the Company Report. |
| 5 | `l0 = round(10% × amt)` | **Level 0 to the buyer, unconditionally.** No flag is consulted. |
| 6 | `S.unilevelEarned[id] += l0`, `S.unilevelMonthTotal += l0` | Buyer's wallet and the network's month total. |
| 7 | `cur = U(id).sp` | Start at the direct sponsor. |
| 8 | `for L = 1..10 while cur !== null` | Walk **at most 10 sponsor hops**. |
| 9 | `if (cur > 0)` | **Company is skipped**: id `0` earns nothing and is not counted as "skipped". |
| 10 | `if (S.unilevelFlag[cur] !== false)` | **The gate.** Absent or `true` → pay; only an explicit `false` → don't. |
| 11 | `pay = round(UNILEVEL_PCT[L] × amt)` | Level's own rate, independently rounded. |
| 12 | credit `unilevelEarned[cur]`, `unilevelMonthTotal`; `upTotal`, `paidCount`, `dist.push` | |
| 13 | `else skippedCount++` | Tracked only for the toast; **the money is not redirected anywhere**. |
| 14 | `cur = U(cur).sp` | One hop up. Note `L` advances even when the node is the company, but the company's `sp` is `null` so the loop ends there anyway. |
| 15 | `return {amt, l0, upTotal, paidCount, skippedCount, dist}` | `dist` lists **only the paid** uplines, each `{id, level, amount}`. |

### 5.3 What a purchase does **not** do

This matters because the panel copy ("Advance month") can be misread:

- It does **not** change anyone's `unilevelFlag`. Eligibility is re-decided only in `evaluateMonth()` (§7).
- It does **not** pay Royalty, reward a Harvest level, or qualify anyone for Harvest.
- It does **not** pay Indirect Referral.
- It does **not** advance the month.
- It does **not** cap at any per-member monthly total — only the 10-level/39% structure.

### 5.4 Worked example — ₱1,000 purchase

Setup: the §4.5 chain, Month 1, everyone Active by default.

**(a) `m1` buys ₱1,000. `m1`'s sponsor is the company.**

| Level | Recipient | Rate | Pesos |
|---|---|---|---:|
| 0 | m1 (buyer) | 10% | 100 |
| — | (company) | — | 0 |

`{amt:1000, l0:100, upTotal:0, paidCount:0, skippedCount:0, dist:[]}` — the company is silently skipped, **not** counted in `skippedCount`.

**(b) `m12` buys ₱1,000.** Eleven real members sit above `m12` (`m11` … `m1`); the cap allows only ten.

| Level | Recipient | Rate | Pesos |
|---|---|---|---:|
| 0 | m12 (buyer) | 10% | 100 |
| 1 | m11 | 5% | 50 |
| 2 | m10 | 5% | 50 |
| 3 | m9 | 5% | 50 |
| 4 | m8 | 3% | 30 |
| 5 | m7 | 3% | 30 |
| 6 | m6 | 2% | 20 |
| 7 | m5 | 2% | 20 |
| 8 | m4 | 2% | 20 |
| 9 | m3 | 1% | 10 |
| 10 | m2 | 1% | 10 |
| — | **m1** | *11 hops up* | **0 — beyond the cap** |
| | **Total** | | **390** |

`upTotal = 290`, `paidCount = 10`, `skippedCount = 0`, and `dist` contains exactly the ten rows above.

### 5.5 Rounding — read this before reconciling any numbers

Every line is rounded **on its own**, with `Math.round`, at the moment it is paid:

```js
const l0 = Math.round(UNILEVEL_PCT[0]*amt);
const pay = Math.round(UNILEVEL_PCT[L]*amt);
```

Consequences:

- On ₱555, `0.05 × 555 = 27.75` → **28**, and `0.03 × 555 = 16.65` → **17**, `0.01 × 555 = 5.55` → **6**.
- The actual total can therefore drift a few pesos from the nominal 39%. ₱555 pays **₱219**, not ₱216.45.
- The buyer's 10% and each upline's cut are separate roundings — you cannot compute the network total as `round(0.39 × amt)`.
- `Math.round(-0.5)` is `-0`, irrelevant here because amounts are clamped to `≥ 0`.
- Amounts are integers from step 1, so downstream `peso()` formatting never shows centavos.

### 5.6 What the caller does with the result

`doPurchase` (`simulator.html:545–554`) re-renders, clears the input, and toasts:

> "Recorded **₱1,000** in personal purchases this month. Unilevel paid **₱100** to you, **₱290** across 10 Active uplines."

If any uplines were skipped, it appends *"N uplines skipped (not Active)"*.

---

## 6. Step 3 — Auto-purchase (the bulk version)

`simulator.html:235–244`:

```js
function autoPurchasePass(){
  const N=S.users.length-1;let buyers=0,total=0,unilevel=0;const log=[];
  for(let i=1;i<=N;i++)if(Math.random()<PUR_RATE){
    const amt=PUR_MIN+Math.floor(Math.random()*((PUR_MAX-PUR_MIN)/100+1))*100;
    const r=recordPurchase(i,amt);
    if(r){buyers++;total+=r.amt;unilevel+=r.l0+r.upTotal;log.push({buyer:i,amt:r.amt,l0:r.l0,dist:r.dist})}
  }
  S.lastAutoPurchaseLog=log;
  return{buyers,total,unilevel};
}
```

| Property | Value |
|---|---|
| Who buys | each member `i` independently with probability **30%** (`Math.random() < .3`) |
| Amount | `500 + floor(rand × 46) × 100` → **₱500–₱5,000 in ₱100 steps**, 46 possible values (verified over 200k samples) |
| Payout path | every purchase goes through the **same `recordPurchase()`** — identical real-time Unilevel, no special case |
| Company | never buys (`i` runs `1..N`) |
| Log | `S.lastAutoPurchaseLog` is **replaced wholesale**, not appended; the panel only ever shows the most recent pass |
| Side effects on flags/month | **none** — it is a purchase action, not an evaluation |
| Return | `{buyers, total, unilevel}` where `unilevel = Σ(l0 + upTotal)` |

The toast (`doAutoPurchase`, `simulator.html:555–561`) reports buyers, total spend, Unilevel generated, and *your* delta; the panel below (`simulator.html:465–468`) shows the per-buyer breakdown filtered to the logged-in member — one row if they were the buyer (Level 0), one row if they were a paid upline (Level *d*) — and otherwise says:

> *"Nobody in that pass earned you anything - either none of them were in your line, or you weren't Active at the time."*

Because `dist` holds only paid uplines, an Inactive upline never appears in the breakdown at all — their skip is invisible here, which is exactly why the panel hedges with "or you weren't Active".

---

## 7. Step 4 — Advance month (the eligibility gate)

### 7.1 The Unilevel half

`simulator.html:254–263` (the Royalty half above it is separate and summarised in §11):

```js
  // Unilevel's status for the WHOLE next month is fixed here, from this month's personal sales alone -
  // always an explicit true/false for every member, replacing whatever they had before. A member who has
  // never been through this check at all (brand new, or month 1) has no entry here yet, and the
  // eligibility check in recordPurchase() treats that absence as Active, not Inactive.
  const newFlag={},lastSales={};let activeNext=0;
  for(let i=1;i<=N;i++){const s=S.sales[i]||0;lastSales[i]=s;newFlag[i]=s>=UNILEVEL_QUOTA;if(newFlag[i])activeNext++}
  S.unilevelFlag=newFlag;S.unilevelLastSales=lastSales;
  const unilevelTotal=S.unilevelMonthTotal||0;
  S.lastMonthUnilevelTotal=unilevelTotal;S.unilevelMonthTotal=0;
  S.sales={};S.month=(S.month||1)+1;S.lastMonthTotal=total;S.lastMonthCount=count;
  return{total,count,unilevelTotal,activeNext};
```

Order of operations:

1. Royalty is paid first (Supervisor+ who cleared quota) — unrelated to Unilevel.
2. **Every** member with an id is scored: `s = S.sales[i] || 0`.
3. `newFlag[i] = s >= 500` — **always a boolean**, never omitted.
4. `S.unilevelFlag` is **replaced** by `newFlag` (not merged). Stale entries cannot survive.
5. `S.unilevelLastSales` = this month's personal sales snapshot (display only).
6. `S.lastMonthUnilevelTotal` = this month's real-time Unilevel total; `S.unilevelMonthTotal` resets to `0`.
7. `S.sales = {}` — the month bucket is wiped for everyone.
8. `S.month++`.

### 7.2 Why "explicit boolean" matters

Three different states exist, and they are **not** the same thing:

| `S.unilevelFlag[id]` | Meaning | Payout result |
|---|---|---|
| *(key absent)* | never evaluated — brand new, or Month 1 | **pays** (`undefined !== false`) |
| `true` | evaluated, met quota | pays |
| `false` | evaluated, missed quota | **skipped** |

`evaluateMonth()` converts the first state into one of the other two for everyone who exists at that moment. Anyone registered **after** the advance starts again in the first state (Active) until the next advance — which is why a recruit can earn from their own recruits immediately.

### 7.3 The month timeline

| Moment | Who is Active? | What a purchase pays |
|---|---|---|
| **Month 1** (fresh save) | everyone, by absence of flags | buyer 10% + all active uplines (all of them) |
| end of Month 1 → "Advance month" | flags written from Month-1 personal sales; month becomes 2 | — |
| **Month 2** | only members who personally bought ≥ ₱500 during Month 1 | buyer 10% + only Active uplines |
| registrations during Month 2 | new members have no flag → Active | new member pays uplines per *their* flags; new member is paid by downline immediately |
| end of Month 2 → advance | flags rewritten from Month-2 sales | — |
| **Month 3** | …and so on | … |

### 7.4 The subtle timing rule

**Buying does not change your flag mid-month.** `recordPurchase()` only *reads* `S.unilevelFlag`. So a member flagged Inactive who buys ₱5,000 on day 3 of Month 2 stays Inactive for the rest of Month 2; the ₱5,000 guarantees they are Active for Month 3, but their downline's purchases **until the next advance** still skip them.

The panel copy at `simulator.html:489` says "falling short switches it to Inactive **until quota is cleared again**" — true in intent, but the clearing is only observed at the next "Advance month", not the instant the purchase is recorded. That is the one place where the prose is looser than the code.

---

## 8. Who gets paid — the full decision table

For one purchase of `amt` by member `buyer`:

| Recipient | Distance | Paid? | Amount | Condition |
|---|---|---|---|---|
| the buyer | Level 0 | **always** | `round(10% × amt)` | none — Inactive buyer still gets it |
| direct sponsor | Level 1 | if `flag !== false` | `round(5% × amt)` | |
| 2nd sponsor up | Level 2 | if `flag !== false` | `round(5% × amt)` | |
| 3rd | Level 3 | if `flag !== false` | `round(5% × amt)` | |
| 4th | Level 4 | if `flag !== false` | `round(3% × amt)` | |
| 5th | Level 5 | if `flag !== false` | `round(3% × amt)` | |
| 6th | Level 6 | if `flag !== false` | `round(2% × amt)` | |
| 7th | Level 7 | if `flag !== false` | `round(2% × amt)` | |
| 8th | Level 8 | if `flag !== false` | `round(2% × amt)` | |
| 9th | Level 9 | if `flag !== false` | `round(1% × amt)` | |
| 10th | Level 10 | if `flag !== false` | `round(1% × amt)` | |
| 11th and beyond | — | **never** | 0 | loop bound `L<=10` |
| company (id 0) | any | **never** | 0 | `if (cur > 0)`; also not counted as skipped |
| Inactive upline | 1–10 | **never** | 0 | `flag === false`; **not redirected** to anyone else |

Notes:

- A member can appear only **once** in a given walk (the sponsor chain is a path, and ids are strictly increasing), so no double-dipping.
- The maximum number of payees per purchase is **11** (buyer + 10 uplines).
- Skipped uplines do not "roll up" to the next eligible person — the ladder position is fixed by hop count, not by eligibility.
- If a member is sponsored by the company, their purchase pays only themselves.

---

## 9. Quota rules and edge cases

### 9.1 The quota

- **₱500 per calendar month** (`UNILEVEL_QUOTA`), measured on **personal** purchases only — `S.sales[self]`, never downline sales.
- Measured **per month bucket**, which `evaluateMonth()` wipes each time.
- Qualifies at exactly ₱500 (`>=`, not `>`).
- Accumulates **any** kind of purchase — manual form, auto-purchase, or both.
- Decides the **whole of the next month**, once.
- Applies to **every** member, including ones who bought nothing (they get an explicit `false`).
- **Independent of rank and Harvest.** A Director with ₱0 personal purchases is Inactive for Unilevel; a plain Member with ₱500 is Active. The schedule table's footnote says it outright: *"regardless of rank"* (`simulator.html:489`).

### 9.2 Edge cases, and what actually happens

| Case | Behaviour | Where |
|---|---|---|
| Buyer is Inactive | still receives their own 10% | `l0` is credited before the flag check |
| Upline is Inactive | skipped; nothing is redirected; `skippedCount++` (toast only) | `else skippedCount++` |
| Buyer sponsored by company | 0 uplines; `skippedCount` stays 0 | `if(cur>0)` + `cur=U(0).sp===null` |
| Chain shorter than 10 | walk ends at company; fewer payees, **not** fewer pesos to the ones present | loop ends on `cur===null` |
| Chain longer than 10 | 11th ancestor and above get nothing | `L<=10` |
| Member registered after an advance | no flag → Active immediately | `undefined !== false` |
| Advance month with zero purchases | everyone becomes explicitly `false` → all uplines skip from then on; buyers still get 10% | `newFlag[i]=s>=500` |
| Amount `0` / negative / `"abc"` | `recordPurchase` returns `null`; UI shows *"Enter a purchase amount greater than 0."* | guard at line 211 |
| Amount fractional (e.g. `555.7`) | rounded to whole pesos first (`556`) | `Math.round(+amt \|\| 0)` |
| Two purchases in one month | both add to `sales`, both pay Unilevel in real time; quota is on the **sum** | `S.sales[id] += amt` |
| Purchase by company (id 0) | unreachable from the UI — the company cannot log in (`doLogin` requires `u.id` truthy) | `doLogin` |
| Log out / re-login | no effect on flags or balances; state is in `localStorage` | `save()` |
| "Reset" | `S=fresh()` — all Unilevel balances, flags, sales, log wiped; second click required | `reset()`, line 569 |
| Old saved state with `unilevelGate` | migrated: explicit values kept, absent values become Active | lines 97 |
| "Advance month" pays no Unilevel | it only reports the month's real-time Unilevel total and resets the counter | comment at line 497 |

### 9.3 The "restore" path in practice

An Inactive member wanting back in needs **two** things, in order:

1. Buy ≥ ₱500 in the current month (any mix of purchases).
2. Click "Advance month".

Only then do their upline cuts resume — from the first purchase of the new month. Their own 10% kept paying throughout.

---

## 10. Verified end-to-end trace

Executed against the real script. Chain of 12 members from §4.5, all amounts in pesos.

**Month 1** — everyone Active by default:

| Action | Level 0 | Upline cut | Paid / skipped | Running `unilevelMonthTotal` |
|---|---:|---:|---|---:|
| m1 buys 1,000 | 100 | 0 | 0 / 0 | 100 |
| m12 buys 1,000 | 100 | 290 | 10 / 0 | 490 |
| m12 buys 555 | 56 | 163 | 10 / 0 | 709 |

Per-level for the ₱555 purchase: `28, 28, 28, 17, 17, 11, 11, 11, 6, 6` — note the rounding, not `27, 27, 27, 16, 16, 11, 11, 11, 5, 5`.

**Advance #1** → `{total: 0, count: 0, unilevelTotal: 709, activeNext: 2}`, month becomes 2.

- Active: `m1` (bought 1,000) and `m12` (bought 1,555). Everyone else explicitly `false`.
- `S.sales` is now `{}`; `lastMonthUnilevelTotal = 709`.

**Month 2:**

| Action | Level 0 | Upline cut | Paid / skipped | Why |
|---|---:|---:|---|---|
| m12 buys 1,000 | 100 | 0 | 0 / 10 | m11…m2 all Inactive |
| m11 buys 500 | 50 | 5 | 1 / 9 | m10…m2 Inactive; **m1 is 10 hops up → 1% of 500 = 5** |

**Advance #2** → `activeNext: 2`, month becomes 3. Active: `m11` (500) and `m12` (1,000).

**Month 3:**

| Action | Level 0 | Upline cut | Paid / skipped | Why |
|---|---:|---:|---|---|
| m12 buys 1,000 | 100 | 50 | 1 / 9 | only m11 (Level 1) is Active; m10…m2 skipped |

**Resulting all-time Unilevel balances** (every member, total ₱1,014 = 709 + 155 + 150 across the three months):

| Member | Unilevel ₱ | Composition |
|---|---:|---|
| m12 | 356 | own L0: 100 + 100 + 56 + 100 |
| m11 | 178 | L1 of m12: 50 + 28 + 50, plus own L0: 50 |
| m1 | 105 | own L0: 100, plus L10 of m11's ₱500: 5 |
| m10 | 78 | L2 of m12: 50 + 28 |
| m9 | 78 | L3 of m12: 50 + 28 |
| m8 | 47 | L4 of m12: 30 + 17 |
| m7 | 47 | L5 of m12: 30 + 17 |
| m6 | 31 | L6 of m12: 20 + 11 |
| m5 | 31 | L7 of m12: 20 + 11 |
| m4 | 31 | L8 of m12: 20 + 11 |
| m3 | 16 | L9 of m12: 10 + 6 |
| m2 | 16 | L10 of m12: 10 + 6 |

Note how the ladder is visible even in the members who did nothing after Month 1: m2–m3 sit at 1%, m4–m6 at 2%, m7–m8 at 3%, m9–m10 at 5%. They stop earning at the end of Month 1, when `evaluateMonth()` flags all of them Inactive for having zero personal purchases.

Also verified:

- Right after building the chain of 12: `unilevelEarned = {}` — **registration pays no Unilevel**.
- `S.unilevelEarned` has **no key `0`** — the company is never credited.
- A member registered *after* an advance and never evaluated reads Active and **was paid** L1 on their recruit's purchase.
- A purchase does **not** flip a `undefined` flag — it stays `undefined` until the next advance.
- Auto-purchase amounts observed: 500…5,000, all multiples of 100, 46 distinct values.

---

## 11. How Unilevel relates to the other three streams

| | Harvest General/Team | Royalty | Indirect Referral | **Unilevel** |
|---|---|---|---|---|
| Trigger | 3 directs → matrix position, then slots filled | 10-direct rank ladder + monthly group-sales quota | someone registers under you | someone **buys** |
| Requires qualification? | yes — 3 directs | yes — rank + quota met | **no** | **no** (but upline must be Active) |
| Unit | one-time level completion | monthly, at Advance | one-time per registration | **every purchase** |
| Depth | 10 levels (3×10 matrix slots) | 4 ranks | 10 uplines | **10 uplines** |
| Paid when | at qualification | at "Advance month" | at registration | **instantly, on purchase** |
| Uses sponsor chain? | matrix position instead | subtree rollup | yes | **yes** |

Key intersections:

- **`S.sales` is shared.** A purchase is both Unilevel's quota input and Royalty's group-sales input for every upline. The two systems read the same number and neither writes it.
- **Advance month does two unrelated jobs** in one click: pays Royalty, then sets Unilevel flags. It pays no Unilevel money and creates no purchases.
- **Company Report** (`simulator.html:391–405`) counts `S.purchasedAll` as revenue and `Σ S.unilevelEarned` as the "Unilevel paid" payout line — independent of the Count-* checkboxes, which only affect personal ranking.
- Unilevel requires **no** Harvest qualification and **no** Royalty rank. A member with only one or two directs is not Harvest-qualified yet still earns Unilevel on those directs' purchases (as long as they are Active).

---

## 12. UI map — where you see it

| Location | Shows | Source |
|---|---|---|
| Header stat tiles | `Unilevel credited`, `Unilevel active members` | `simulator.html:474` |
| **Company Report** | "Unilevel paid" line inside total payouts; affects net profit & margin | `simulator.html:400` |
| **Your Unilevel account** | Active/Inactive, last month's personal purchases vs quota, "not checked yet" state, all-time earnings, this month's progress and how much more to buy | `simulator.html:440–442` |
| **Unilevel Repeat-Purchase Bonus** panel | the 11-row rate schedule (Level 0 = buyer, 1–10 = uplines) and the rules paragraph | `simulator.html:197`, `489` |
| Purchase toast | "Unilevel paid ₱X to you, ₱Y across N Active uplines" + skipped count | `simulator.html:545–554` |
| Auto-purchase toast | buyers, total spend, Unilevel generated, **your** delta | `simulator.html:555–561` |
| Advance toast | month number, Royalty paid, Unilevel paid last month, how many will be Active next month | `simulator.html:562–568` |
| **Last Auto-purchase: Unilevel breakdown** | your rows for the latest pass — as buyer (Level 0) or as paid upline (Level *d*) | `simulator.html:458–468` |
| **All members, richest first** | "Unilevel ₱" column, included in the Total | `simulator.html:502–503` |
| **Whole network this month** | last month's total Unilevel across the network | `simulator.html:501` |
| Count checkboxes | "Count Unilevel" only changes *your* total/ranking, never payouts | `simulator.html:469` |

---

## 13. Pseudocode

```
on register(name, sponsor):
    validate name, uniqueness, sponsor exists
    append user {id: next_id, sp: sponsor.id}
    if sponsor is a real member and sponsor has >= 3 directs: enqueue sponsor for Harvest
    payIndirectReferral(newUser.id)          # unrelated to Unilevel
    # no Unilevel state touched: user is Active by default (flag absent)

on purchase(buyerId, amount):
    amount = round(max(0, amount)); if amount <= 0: return
    sales[buyerId]        += amount         # month bucket (also Royalty group sales)
    purchasedAll[buyerId] += amount         # all-time revenue

    credit(buyerId, round(0.10 * amount))   # Level 0, ALWAYS, even if Inactive

    node = sponsor(buyerId)
    for level in 1..10 while node != null:
        if node != company:                 # company id 0 never earns
            if flag[node] != false:         # absent or true -> pay
                credit(node, round(rate[level] * amount))
            else:
                skipped++                   # not redirected
        node = sponsor(node)

on advanceMonth():
    pay Royalty to Supervisor+ who cleared quota      # separate stream
    for each member: flag[member] = (sales[member] >= 500)
    lastMonthUnilevelTotal = unilevelMonthTotal
    unilevelMonthTotal = 0
    sales = {}                                        # new month bucket
    month += 1

rate = [0.10, 0.05, 0.05, 0.05, 0.03, 0.03, 0.02, 0.02, 0.02, 0.01, 0.01]
```

---

## 14. Reproduce it yourself in the browser

Open `simulator.html` (file:// or served), then:

**A. Registration pays no Unilevel**
1. Reset (twice) → register `alice` with sponsor `company`.
2. Register `bob` under `alice`, then `carol` under `bob`.
3. Log in as each: *"Your Unilevel account"* shows **₱0** and *"Unilevel earned all-time: ₱0"*; the Indirect panel shows the registration bonuses instead.

**B. Buyer always gets 10%, even with no uplines**
1. Log in as `alice` (sponsored by company) → enter `1000` → "Record a purchase for me".
2. Toast: `₱100 to you` with no upline clause. Balance: ₱100.

**C. Ten-level ladder**
1. Reset (twice), then register 12 members in a chain — each sponsored by the one before it — or use *Grow whole network* with the **Chain** pattern.
2. Log in as the deepest member, buy ₱1,000 → toast shows `₱100 to you, ₱290 across 10 Active uplines`.

**D. The gate**
1. Reset (twice), rebuild the 12-member chain, and click *Advance month* **without** recording any purchase → the stat tile reads `0 Unilevel active members`.
2. Log in as the deepest member, buy ₱1,000 → toast: `₱100 to you, 10 uplines skipped (not Active)`.
3. Log in as one of those uplines, buy ₱500, click *Advance month* → they become Active for next month. Log back in as the deepest and buy again → that upline is now paid.

**E. Bulk**
1. "Auto-purchase: simulate ~30% of the network buying" → toast + the breakdown panel for whoever is logged in.

---

## 15. Code map (line index)

| Lines | Symbol | Role |
|---|---|---|
| 75–86 | constants | `UNILEVEL_PCT`, `UNILEVEL_QUOTA`, `PUR_*`, `BASIC_ACCOUNT_PRICE`, `RN` |
| 90 | `fresh()` | seeds `unilevelEarned`, `unilevelFlag`, `unilevelLastSales`, totals |
| 91–101 | `load()` / `save()` | `localStorage` persistence, `warn` if blocked |
| 92–100 | migration | `S.on.u/i` defaults; `unilevelGate` → `unilevelFlag` |
| 102–105 | `U`, `byName`, `directs`, `pos` | lookups used by every stream |
| 110–122 | `payIndirectReferral()` | registration-time flat bonuses (not Unilevel) |
| 126–137 | `reg()` | registration; creates the `sp` chain Unilevel walks |
| 140–155 | `levelsG`/`levelsT`/`snap` | Harvest only |
| 197 | `unilevelT()` | renders the 11-row rate table |
| 206–231 | `recordPurchase()` | **the Unilevel engine** |
| 235–244 | `autoPurchasePass()` | bulk buyer simulation, calls `recordPurchase` |
| 247–265 | `evaluateMonth()` | Royalty payout + **Unilevel flag decision** + month reset |
| 299–317 | `bulkAdd()` | fast bulk registration (Indirect paid, no Unilevel) |
| 381, 406 | dashboard derivations | `myEverChecked`, `activeNow` |
| 391–405 | Company Report | "Unilevel paid" line at 400 |
| 440–442 | `mineU` | "Your Unilevel account" panel |
| 458–468 | `apl` | auto-purchase breakdown filtered to the viewer |
| 474 | stats | Unilevel credited / active members |
| 489 | panel copy | the rules paragraph |
| 501 | network panel | last month's Unilevel total |
| 502–503 | members table | "Unilevel ₱" column |
| 545–554 | `doPurchase()` | manual purchase + toast |
| 555–561 | `doAutoPurchase()` | bulk pass + toast |
| 562–568 | `doAdvance()` | advance + toast |
| 569 | `reset()` | two-click wipe to `fresh()` |

---

## 16. Gotchas / FAQ

**Does registration trigger Unilevel?**
No. `reg()` never touches a Unilevel field. Registration pays Indirect Referral (up to ₱765) and creates the sponsor pointer.

**Does an Inactive member stop earning entirely?**
No. They keep their own 10% on personal purchases — that is credited before the flag gate. They only lose *upline* cuts on downline purchases.

**Is the quota on personal or group volume?**
Personal only: `S.sales[i]`, i.e. that member's own purchase bucket for the month.

**At exactly ₱500?**
Active (`>=`).

**When does buying restore Active?**
At the next "Advance month", not instantly. The flag is only recomputed there.

**Is Unilevel paid at Advance?**
No. It is paid the instant a purchase is recorded; Advance only reports the month's total and resets the counter.

**What happens to a skipped upline's share?**
Nothing — it is not paid, not rerouted, and not banked. `skippedCount` exists only for the toast.

**Can the same person be paid twice on one purchase?**
No. The sponsor chain is a path; each ancestor appears at exactly one level.

**What about the company account?**
It is id `0`, never receives Unilevel, is never evaluated, and never appears in `unilevelEarned`. If it is your sponsor, your purchase has zero uplines.

**Does the 10-level cap count the company?**
The loop increments `L` at every hop, and the company is one hop, but since the company's `sp` is `null` the walk ends there anyway — so in practice the cap is simply "10 sponsor hops". Your 11th real ancestor is never paid.

**Why did the network pay 219 on a 555 purchase instead of 216.45?**
Per-line `Math.round` (§5.5).

**Does Unilevel affect my Royalty?**
No. The earnings are separate ledgers and Royalty is computed only in `evaluateMonth()`. They share only the `S.sales` month bucket as an input.

**Does Unilevel require Harvest qualification or rank?**
No. It depends only on (a) being in the sponsor chain, and (b) not being flagged Inactive. Rank and matrix position are irrelevant.

**Is anything actually persisted?**
Yes — everything is one JSON blob in `localStorage['royalty_harvests_v1']`, unless storage is blocked, in which case the page says so and the data dies with the tab. "Reset" (two clicks) returns to `fresh()`.

---

*Prepared 2026-11-12 from `simulator.html`; all figures re-derived by executing the file's own script in Node 22 with stubbed DOM/localStorage.*
