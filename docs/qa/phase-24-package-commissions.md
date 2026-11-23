# Phase 24 QA — Package commissions: registration, activation, direct, indirect, binary package, team harvest and general harvest

| | |
|---|---|
| **Phase** | 24 — every commission stream tied to a package activation: registration codes and manual approval, then direct referral, indirect referral, binary package, team harvest and general harvest |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §4.4, §5.10, §6.2–6.8 · `dmp/plan-ecowealth-team-harvest.md` · `dmp/plan-ecowealth-general-harvest.md` · `dmp/plan-ecowealth-package-binary-sliced.md` |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · QA tree accounts created during the test (all prefixed `QA24`) |
| **Checks** | 29 (QA24-01 … QA24-29) |
| **Time** | About 2.5–3.5 hours |
| **Evidence folder** | `docs/qa/evidence/phase-24/` |

## What this phase is meant to prove

A new member's **package activation** is the single trigger for every commission stream. This walkthrough builds the smallest possible member tree, tunes the commission settings so each stream pays with the fewest members, then activates packages one at a time and watches the money kick in:

1. **Direct referral (level 1)** — the sponsor is paid the package's direct bonus the moment the recruit activates.
2. **Indirect referral (levels 2–4)** — the sponsor chain above the recruit is paid, level by level, using the source package's ladder; the company root is walked and **skipped**.
3. **Binary package** — two activations placed left/right under the same binary parent complete one pair and credit the pair award immediately.
4. **Team harvest** — a member who hits the qualify gate enters the Harvest queue; once enough qualified-after teammates appear, the first matrix level pays a flat amount.
5. **General harvest** — qualified members hold nodes on the shared 3-ary board; once the block is full, the node's holder is paid a flat amount.

Every award lands as one commission row, one wallet credit (withdrawable) and one ledger row, written in the same transaction as the activation. The administrator can see it all on **Membership → Earnings**, **Finance → Commissions**, the wallet statements and the CSV exports.

## The idea: minimum members, tuned settings

The harvest streams have a structural gate (a member must recruit a set number of direct members before qualifying), so they cannot pay with just two people. Everything else can. To keep the tree as small as possible while still making **every** stream kick in, this test deliberately lowers the configurable gates and caps:

| Setting | Default | QA value | Reason |
|---|---|---|---|
| `commission_direct_enabled` | 1 | 1 | Direct referral on |
| `commission_indirect_enabled` | 1 | 1 | Indirect ladder on |
| `commission_indirect_max_levels` | 10 | 4 | Walks levels 1–4 so the company root at level 4 is **skipped** and visible |
| `package_binary_enabled` | 0 | 1 | Package binary stream on |
| `package_binary_requires_package` | 1 | 1 | Only binary-enabled-package recipients earn pair awards |
| `binary_pair_points` | 1000 | 1000 | One activation (1,000 pts) = exactly one pair's worth |
| `teams.harvest` gate `team_harvest_qualify_directs` | 3 | **1** | Minimum the UI allows; the plan's reference gate is 3 (see note below) |
| `team_harvest_max_levels` | 10 | 1 | Level 1 pays as soon as the teammate threshold is met |
| `general_harvest_enabled` | 0 | 1 | General harvest board on |
| `general_harvest_max_levels` | 10 | 1 | Apex level 1 pays as soon as N ≥ 4 qualified members exist |

> **Gate note.** `team_harvest_qualify_directs` clamps to 1–10, so `1` is valid. The plan's reference preset is 3. If you want to test the reference behaviour instead of the absolute minimum, restore the gate to 3 — you will then need roughly twice as many members for team harvest to pay. The tree below assumes gate = 1.

### The minimum tree (11 members + the company root)

All QA accounts use password `Demo@1234` unless a step says otherwise. Every account below is created by you during the test; nothing is pre-seeded.

```text
admin  (company, root — never paid, never a recipient)
└── QA24_Sponsor  [Package A, binary-enabled]  sponsor of A, B, C, D, T1, T2, T3
    ├── QA24_Member_A  [Package A, binary-enabled]   sponsor-tree child;  BINARY LEFT  of QA24_Sponsor
    ├── QA24_Member_B  [Package A, binary-enabled]   child of A;          BINARY RIGHT of QA24_Sponsor  → pair completes at QA24_Sponsor
    ├── QA24_Member_C  [Package A]                   child of B
    ├── QA24_Member_D  [Package A]                   child of C
    ├── QA24_Team_1    [standard, no package]        child of A; qualifies via QA24_Team_1a
    │   └── QA24_Team_1a  [standard]
    ├── QA24_Team_2    [standard, no package]        child of A; qualifies via QA24_Team_2a
    │   └── QA24_Team_2a  [standard]
    └── QA24_Team_3    [standard, no package]        child of A; qualifies via QA24_Team_3a
        └── QA24_Team_3a  [standard]
```

Qualified members (general-harvest `N`): QA24_Sponsor, QA24_Team_1, QA24_Team_2, QA24_Team_3 = **4** → apex level 1 pays.
Team-harvest teammates of QA24_Sponsor (qualified after it): QA24_Team_1, QA24_Team_2, QA24_Team_3 = **n = 3** → team-harvest level 1 pays.

### Activation order and the commissions each one produces

| # | Activation | Sponsor (level 1 direct) | Indirect uplines | Binary |
|---|---|---|---|---|
| 1 | QA24_Member_A | QA24_Sponsor **+₱500** | — | places 1,000 pts LEFT at QA24_Sponsor |
| 2 | QA24_Member_B | QA24_Member_A **+₱500** | QA24_Sponsor L2 **+₱100** | places 1,000 pts RIGHT at QA24_Sponsor → **pair ₱100** to QA24_Sponsor |
| 3 | QA24_Member_C | QA24_Member_B **+₱500** | QA24_Member_A L2 **+₱100**, QA24_Sponsor L3 **+₱50** | — |
| 4 | QA24_Member_D | QA24_Member_C **+₱500** | QA24_Member_B L2 **+₱100**, QA24_Member_A L3 **+₱50**, **admin L4 skipped** (company root) | — |

After all four activations QA24_Sponsor has earned **₱650** referral + **₱100** binary pair + **₱1,000** team harvest + **₱2,000** general harvest.

## Rules that protect the project

1. **Work on the local copy only.** URL must start with `http://localhost/`.
2. **Prefix everything you create with `QA24`** — `QA24_Sponsor`, `QA24_Member_A`, … Delete only `QA24…` rows at the end.
3. **Never change the `admin` or `superadmin` password.**
4. **Never delete seeded products, packages or accounts.** Delete only `QA24…` rows.
5. **Do not leave test data behind.** Each section ends with cleanup; §9 does the final restore.
6. **Guest checks need a clean session** — use Incognito/Private windows (`Ctrl+Shift+N`).
7. **One activation at a time.** Do not approve two activations simultaneously; the hook runs once per activation.
8. If you see a **500 page**, stop that check, capture it, mark **Fail**, and continue with the others.

## Register the tester accounts

Create `docs\qa\evidence\phase-24\` for your screenshots before you begin.

## A. Registration and activation (QA24-01 … QA24-06)

### QA24-01 — A guest registers a standard (free) account

| | |
|---|---|
| **Why it matters** | The free front door: shopping only, no package, no commissions. It also creates the standard members used by the harvest streams. |
| **Where** | `/register` (signed out) |
| **Evidence** | `QA24-01-register-standard.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign out, open `/register`.
2. Keep **Standard** selected and leave the sponsor field blank (joins under `@admin`).
3. Register `QA24_Team_1a` (new email, password ≥ 8 chars, tick terms).
4. Repeat for `QA24_Team_2a` and `QA24_Team_3a`, each with sponsor left blank.

**Expected**

- Each submit lands on `/dashboard` with a success flash; dashboard says **Standard member**, wallet ₱0.00, no package.
- In `/admin/members` each account is **Active**, package `—`, sponsor **@admin**, with an 8-character referral code.

### QA24-02 — A registration code creates an active member with its package

| | |
|---|---|
| **Why it matters** | Codes are sold offline; redeeming one creates the account **and** activates the package in one step — the commission trigger. |
| **Where** | `/admin/codes` (issue) → `/register` (redeem) |
| **Evidence** | `QA24-02-register-code.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign in as `admin`, open **Commerce → Codes**, issue 1 code for **Package A** (₱4,498), expiry a month away.
2. Copy the code. Sign out, open `/register`, choose **Registration code**, paste the code, sponsor `QA24_Sponsor`, register `QA24_Member_A`.
3. Issue a second Package A code; redeem it with sponsor `QA24_Member_A`, registering `QA24_Member_B`.

**Expected**

- Each account is **Active** with **Package A**, `reg_payment_method = code`.
- Each code row turns **Used** with the new member's username and `used_at`.
- Each member has an activation record (status **Approved**) in their membership history.

### QA24-03 — Invalid, used and expired codes are refused

| | |
|---|---|
| **Why it matters** | A code is money; a bad one must never create an account or activate a package. |
| **Where** | `/register` (code mode) |
| **Evidence** | `QA24-03-code-refused.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Open `/register`, choose **Registration code**, press **Validate** on `ZZZZ-ZZZZ-ZZZZ`.
2. Try to submit that value with a fresh username.
3. Try the QA24-02 code again with another username.
4. Try any seeded `DEMO-EXPD-…` expired code.

**Expected**

- **Validate** answers *"not recognised"* / *"already used"* / *"expired"*.
- Each submit returns to `/register` with the error and **no account is created** (check `/admin/members`).

### QA24-04 — A manual proof waits for approval and activates on approve

| | |
|---|---|
| **Why it matters** | Not every recruit can pay by wallet; a proof must be reviewable, auditable, and approvable exactly once. |
| **Where** | `/membership` → `/admin/activations` |
| **Evidence** | `QA24-04-membership-proof.png`, `QA24-04-admin-approve.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Register a pending member with sponsor `QA24_Sponsor` (`/register`, **Activate later**): `QA24_Member_C`.
2. As that member, open **Membership**, choose **Payment proof** → **GCash**, choose **Package A**, enter a reference, attach a PNG/PDF receipt, submit.
3. Confirm the page now shows **Activation in progress**.
4. As `admin`, open **Commerce → Activations → Awaiting review**; open the proof (**View proof**).
5. Press **Approve** and confirm the modal.

**Expected**

- `QA24_Member_C` becomes **Active** with **Package A**; the request moves to **Approved** with reviewer and timestamp.
- Approving the same request again is refused ("already processed") and changes nothing.
- The proof streams as `image/png`/`application/pdf` to admin, **403** to a member, login redirect to a guest.

### QA24-05 — A rejected activation returns the member to pending

| | |
|---|---|
| **Why it matters** | A rejection must explain itself and leave the member able to try again, with no money moved. |
| **Where** | `/admin/activations` |
| **Evidence** | `QA24-05-admin-reject.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Submit a second proof as a pending member (`QA24_Member_D`, sponsor `QA24_Member_C`, Package A).
2. As `admin`, open **Activations**, type a reason in the row's **Rejection reason** field and press **Reject** (empty reason refused).

**Expected**

- The request is **Rejected** with the reason; `QA24_Member_D` is still **Pending**.
- The member's **Activation history** shows the rejected row with the reason.
- No wallet debit was written; the member can open a new request.

### QA24-06 — A sponsor without a package is skipped, never paid

| | |
|---|---|
| **Why it matters** | "Active with a package" is the eligibility rule. A skipped award must be visible and must never release money. |
| **Where** | `/register` → `/admin/commissions` |
| **Evidence** | `QA24-06-admin-ledger-skipped.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Register a **standard** (free, no package) account with sponsor `QA24_Sponsor`: username `QA24_NoPkg_Sponsor`.
2. Issue a Package A code; redeem it with sponsor `QA24_NoPkg_Sponsor`, registering a new member.
3. As `admin`, open **Finance → Commissions**, filter **Status → Skipped**.
4. Sign in as `QA24_NoPkg_Sponsor` and open **Membership → Earnings**.

**Expected**

- The new member is **Active** with Package A.
- The ledger shows one **Skipped** row: recipient `@QA24_NoPkg_Sponsor`, amount ₱500.00 marked **not paid**, reason *"recipient has no package"*, no wallet link.
- `QA24_NoPkg_Sponsor`'s wallet and **Total credited** are unchanged.

## B. Tune the settings for minimum members (QA24-07 … QA24-08)

### QA24-07 — Record the defaults, then apply the minimum-member settings

| | |
|---|---|
| **Why it matters** | Every stream must be on, and the gates/caps must be low enough that the small tree triggers every commission. You must restore the defaults at the end (§9). |
| **Where** | `/admin/settings` → **Commissions** (`/admin/settings#settings-commissions`) |
| **Evidence** | `QA24-07-admin-settings.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign in as `admin`, open **Settings → Commissions**.
2. Write down the **before** value of every control below (you restore them in QA24-29).
3. Set:
   - `commission_indirect_max_levels` → `4`
   - `commission_indirect_enabled` → on
   - `commission_direct_enabled` → on
   - `package_binary_enabled` → on
   - `team_harvest_qualify_directs` → `1`
   - `team_harvest_max_levels` → `1`
   - `general_harvest_enabled` → on
   - `general_harvest_max_levels` → `1`
4. Click **Save settings**, reload, confirm the values stuck.

**Expected**

- All eight controls show the QA values after save + reload.
- A green confirmation banner appears (or *"No changes to save"* if nothing differed).
- Restore these exact values in QA24-29.

| Setting | Before (write down) | QA value |
|---|---|---|
| commission_indirect_max_levels | | 4 |
| team_harvest_qualify_directs | | 1 |
| team_harvest_max_levels | | 1 |
| general_harvest_enabled | | on |
| general_harvest_max_levels | | 1 |
| package_binary_enabled | | on |

### QA24-08 — Enable Package A for the binary package stream

| | |
|---|---|
| **Why it matters** | Package binary pays only when the package is binary-enabled with points, the global switch is on, and the recipient holds a binary-enabled package. |
| **Where** | `/admin/packages` → edit **Package A** |
| **Evidence** | `QA24-08-admin-package-binary.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Open Package A's edit page, open the **Package binary** card.
2. Switch **Package binary** on, set points `1000`, save.
3. Re-open: switch on, points `1000`; the list badge reads **Binary 1,000 pts**.
4. Confirm `SELECT binary_enabled, binary_points FROM packages WHERE slug='package-a'` → `1`, `1000`.

**Expected**

- Package A is binary-enabled with 1,000 points; one activation = one pair's worth (pair_points = 1,000).
- QA24_Sponsor, QA24_Member_A and QA24_Member_B must all hold Package A (binary-enabled) to earn pair awards.

## C. Direct + indirect referral commissions (QA24-09 … QA24-14)

Activate QA24_Member_A, B, C, D **in that order**, each under the sponsor shown in §intro's tree, each with Package A (code, wallet or manual proof — whichever is quickest; manual proof exercises QA24-04's approve path).

### QA24-09 — Activation 1: QA24_Member_A pays the direct referral to QA24_Sponsor

| | |
|---|---|
| **Why it matters** | Level 1 is the package's direct referral bonus (₱500 on Package A), paid exactly once into the sponsor's withdrawable wallet. |
| **Where** | `/admin/activations` (approve) → QA24_Sponsor's **Membership → Earnings** |
| **Evidence** | `QA24-09-member-earnings.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Approve QA24_Member_A's Package A activation.
2. Sign in as `QA24_Sponsor`, open **Membership → Earnings**, note **Total credited**.
3. Read the award row: recipient `QA24_Sponsor`, source `QA24_Member_A`, Package A, **Level 1 · Direct**, **Credited**, **+₱500.00**.
4. Check `QA24_Sponsor`'s **E-wallet**: one **Commission** credit ₱500; **Withdrawable** rose by ₱500.

**Expected**

- QA24_Sponsor's **Total credited** rose by exactly **₱500.00**; **Direct referral** equals that.
- The e-wallet shows one **Commission** credit; no duplicate row.

### QA24-10 — Activation 2: QA24_Member_B pays direct + one indirect level

| | |
|---|---|
| **Why it matters** | The sponsor chain pays: level 1 to the direct sponsor, level 2 to the next upline, using the source package's ladder. |
| **Where** | `/admin/commissions` (filter **Type = Indirect referral**) |
| **Evidence** | `QA24-10-admin-ledger-indirect.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Approve QA24_Member_B's activation (sponsor QA24_Member_A).
2. As admin, filter **Commissions → Type = Indirect referral**: exactly one row, level 2, `QA24_Sponsor` **credited ₱100.00**.
3. Clear the filter (or set **Direct referral**): find `QA24_Member_A`'s level-1 award **credited ₱500.00**.
4. Sign in as `QA24_Member_A`: **Earnings** **Direct referral** = ₱500, **Indirect referral** = ₱0.

**Expected**

- Three awards so far: QA24_Sponsor ₱500 direct, QA24_Member_A ₱500 direct + QA24_Sponsor ₱100 indirect (L2).
- `QA24_Sponsor`'s wallet: +₱600 total commission; `QA24_Member_A`: +₱500.

### QA24-11 — Activation 3: QA24_Member_C pays direct + two indirect levels

| | |
|---|---|
| **Why it matters** | The walk continues up the sponsor chain; each upline keeps its own level amount (no compression). |
| **Where** | `/admin/commissions` → QA24_Member_B's and QA24_Member_A's **Earnings** |
| **Evidence** | `QA24-11-admin-ledger-levels.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Approve QA24_Member_C's activation (sponsor QA24_Member_B).
2. Open the ledger filtered to the new member's activation: three new rows — level 1 `QA24_Member_B` **+₱500**, level 2 `QA24_Member_A` **+₱100**, level 3 `QA24_Sponsor` **+₱50**.
3. Sign in as `QA24_Member_B`: **Direct referral** = ₱500. As `QA24_Member_A`: **Direct** ₱500 + **Indirect** ₱100. As `QA24_Sponsor`: indirect total now ₱150.

**Expected**

- Exactly the three rows above; level amounts match the Package A ladder (L1 ₱500, L2 ₱100, L3 ₱50).
- Each member's **Earnings** level breakdown updates accordingly.

### QA24-12 — Activation 4: QA24_Member_D pays levels 1–3 and skips the company root

| | |
|---|---|
| **Why it matters** | With `max_levels = 4` the walk reaches the company root at level 4, which is **skipped** (no package, never paid) and the level is consumed — no money moves. |
| **Where** | `/admin/commissions` (filter **Status = Skipped**) |
| **Evidence** | `QA24-12-admin-ledger-skipped.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Approve QA24_Member_D's activation (sponsor QA24_Member_C).
2. Filter **Status = Skipped**: one row — level 4, recipient `admin`, amount ₱50.00 **not paid**, reason *"recipient has no package"* (company root).
3. Filter **Type = Indirect referral**: four credited rows for this activation (levels 1–3) plus the skipped level-4 row.
4. Confirm `admin`'s **E-wallet** shows **no** commission credit.

**Expected**

- Level 1 `QA24_Member_C` **+₱500**, level 2 `QA24_Member_B` **+₱100**, level 3 `QA24_Member_A` **+₱50**, level 4 `admin` **skipped ₱50**.
- The skipped ₱50 is not redirected to anyone; admin's wallet is unchanged.

### QA24-13 — The member sees the level breakdown

| | |
|---|---|
| **Why it matters** | The member must see every award with its level, package and status, plus a filterable CSV. |
| **Where** | `/earnings` as `QA24_Sponsor` |
| **Evidence** | `QA24-13-member-earnings.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign in as `QA24_Sponsor`, open **Membership → Earnings**.
2. Read the **Unilevel breakdown**: level 1, 2, 3 rows with amounts and statuses; totals update.
3. Click **Export CSV**: one row per award (level, type, source, package, amount, status, activation, ledger).

**Expected**

- QA24_Sponsor's breakdown shows direct + indirect rows across levels 1–3; total credited = **₱650**.
- The CSV downloads; opening it shows one row per award matching the screen.

### QA24-14 — The admin commission ledger and export

| | |
|---|---|
| **Why it matters** | This is the company's compensation record: every award, filterable by member/type/level/status, with an audited CSV export. |
| **Where** | `/admin/commissions` |
| **Evidence** | `QA24-14-admin-ledger.png`, `QA24-14-admin-ledger-export.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As `admin`, open **Finance → Commissions**.
2. Filter by `QA24_Sponsor`, then **Type**, **Level**, **Status** and a date range; press the eye button on a row.
3. Click **Export CSV**; confirm the audit desk (`/admin/audit`) records a `commissions.export` row.
4. Confirm a member opening `/admin/commissions` gets **403** and a guest is redirected to `/login`.

**Expected**

- Four stat cards: **Total credited**, **This month**, **Awards**, **Skipped**.
- Rows list recipient, source, package, type/level, amount, status, activation, awarded date; credited green, skipped muted **not paid**.
- Filters narrow and keep their values; **Clear** restores; empty filter shows the empty state.
- The drawer traces the award → activation → wallet entry; read-only (no edit/delete).
- CSV matches the screen; export is audited; 403 / redirect guards hold.

## D. Binary package commission (QA24-15 … QA24-18)

### QA24-15 — Place the binary pair: left then right under QA24_Sponsor

| | |
|---|---|
| **Why it matters** | A pair completes only when the same binary parent has volume on both sides; placement is separate from the sponsor tree. |
| **Where** | Admin placement console / registration placement; SQL on `binary_nodes` |
| **Evidence** | `QA24-15-binary-placement.png`, `QA24-15-binary-nodes.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Confirm QA24_Sponsor, QA24_Member_A and QA24_Member_B all hold Package A (binary-enabled, 1,000 pts).
2. Place QA24_Member_A **left** under QA24_Sponsor, then QA24_Member_B **right** under QA24_Sponsor (binary upline = QA24_Sponsor, side left/right).
3. `SELECT user_id, upline_id, side, state, left_points, right_points FROM binary_nodes WHERE user_id IN (QA24_Sponsor, QA24_Member_A, QA24_Member_B);`

**Expected**

- Exactly one node per member; QA24_Member_A side `left`, QA24_Member_B side `right`, both `upline_id = QA24_Sponsor`, state `placed`.
- A taken slot, a cycle, or a bad side is refused (no orphan member).

### QA24-16 — The pair completes: ₱100 credited to QA24_Sponsor

| | |
|---|---|
| **Why it matters** | Left 1,000 + right 1,000 = one pair; the pair pays `round(1,000 × 10% / 100) = ₱100` to the pair's owner, credited immediately (hold_days = 0). |
| **Where** | Activate QA24_Member_B (if not already) → `binary_awards` → QA24_Sponsor's **E-wallet** |
| **Evidence** | `QA24-16-binary-award.png`, `QA24-16-binary-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Confirm QA24_Member_B's activation placed 1,000 team points right at QA24_Sponsor (QA24_Sponsor now has left 1,000 from A + right 1,000 from B).
2. `SELECT id, kind, user_id, status, amount, hold_days, source FROM binary_awards WHERE user_id = QA24_Sponsor;` → one **pair** award, **credited**, **₱100.00**, `hold_days = 0`.
3. `QA24_Sponsor`'s **E-wallet**: one **Commission**/**binary** credit ₱100; **Withdrawable** rose by ₱100.
4. `SELECT * FROM ewallet_ledger WHERE ref_type = 'binary' AND ref_id = <award id>;` → one matching row.

**Expected**

- One pending→credited pair award at QA24_Sponsor; matched points consumed (left/right → 0); wallet credited exactly once.
- Replaying the award writes nothing twice (`UNIQUE (order_id, user_id, kind)` / activation anchor).

### QA24-17 — The binary ledger and audit

| | |
|---|---|
| **Why it matters** | Finance must trace the pair award to its activation and wallet movement, with Source = *Package activation*. |
| **Where** | `/admin/commissions?ledger=binary` |
| **Evidence** | `QA24-17-binary-ledger.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Open `/admin/commissions?ledger=binary` as `admin`.
2. Confirm the **Source** column reads **Package activation** for the pair row; header shows credited money, pending liability, award count.
3. Click the row's order/activation link, then its wallet link; confirm both open and match.

**Expected**

- Source column present; package-sourced rows read **Package activation**; wallet links resolve; page is read-only.

### QA24-18 — Negative control: a zero-point / disabled package stamps zeros

| | |
|---|---|
| **Why it matters** | A package with no binary points, or the global switch off, places nothing and pays nothing — the referral ladder still pays. |
| **Where** | `/admin/packages` → edit Package A → `/admin/activations` → `mysql` |
| **Evidence** | `QA24-18-binary-zeros.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Set Package A `binary_points = 0` (switch on); approve an activation → `binary_points = 0`, `binary_total = 0.00`, no `binary_awards`/`binary_volume` activation rows.
2. Set `binary_enabled = 0` on Package A; approve another activation → same zeros.
3. Set `package_binary_enabled = 0`; approve another activation → same zeros.
4. Restore Package A to `binary_enabled = 1`, `binary_points = 1000`, global switch on; confirm the referral ladder paid the same amounts as before.

**Expected**

- All three cases stamp zeros and write no binary rows; the referral ladder is unaffected (no regression).

## E. Team harvest (QA24-19 … QA24-22)

### QA24-19 — Qualification: members enter the Harvest queue

| | |
|---|---|
| **Why it matters** | With `team_harvest_qualify_directs = 1`, a member qualifies the moment they have one direct member; QA24_Sponsor qualified when QA24_Member_A joined, and QA24_Team_1/2/3 qualify when their Team_Xa joins. |
| **Where** | `mysql` on `harvest_queue` |
| **Evidence** | `QA24-19-harvest-queue.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. `SELECT id, user_id, teammates, baseline_n, source, qualified_at FROM harvest_queue ORDER BY id;`
2. Confirm QA24_Sponsor (teammates = its direct count), QA24_Team_1, QA24_Team_2, QA24_Team_3 each have a queue row, `source = 'registration'`, contiguous positions `1 … 4`, `baseline_n = teammates`.
3. `SELECT value FROM settings WHERE key_name = 'team_harvest_ledger_since';` → a datetime (the seed/queue is live).

**Expected**

- Four contiguous queue positions with no gaps; every qualified member's `teammates` equals its direct count at qualification; no `team_harvest_awards` row yet.

### QA24-20 — Level 1 pays: ₱1,000 to QA24_Sponsor

| | |
|---|---|
| **Why it matters** | With `max_levels = 1`, team-harvest level 1 (3 slots) pays once `n = 3` qualified-after teammates appear. QA24_Team_1, _2, _3 each qualified after QA24_Sponsor → `n = 3` → level 1 pays ₱1,000. |
| **Where** | `team_harvest_awards` → QA24_Sponsor's **E-wallet** |
| **Evidence** | `QA24-20-team-harvest-award.png`, `QA24-20-team-harvest-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. `SELECT level, threshold_n, n_at_award, amount, status, ledger_id FROM team_harvest_awards WHERE user_id = QA24_Sponsor;` → one row: level 1, `threshold_n 3`, `n_at_award 3`, **₱1,000.00**, **credited**.
2. QA24_Sponsor's **E-wallet**: one **Team Harvest bonus** credit ₱1,000; **Withdrawable** rose by ₱1,000.
3. Confirm QA24_Member_A / QA24_Member_B / QA24_Member_C / QA24_Member_D (no qualified-after teammates) have **no** team-harvest award.

**Expected**

- Exactly one team-harvest award: QA24_Sponsor level 1, ₱1,000, credited, withdrawable.
- No other member is paid; no double credit.

### QA24-21 — The member team-harvest statement

| | |
|---|---|
| **Why it matters** | The member sees their queue position, gate, matrix and earnings on `/earnings`. |
| **Where** | `/earnings` as `QA24_Sponsor` (team-harvest tab) |
| **Evidence** | `QA24-21-member-team-harvest.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign in as `QA24_Sponsor`, open the **Team Harvest** tab on **Earnings**.
2. Confirm the position (#1), the gate sentence, the 3×10 matrix with level 1 **Paid** (₱1,000) and levels 2–10 unpaid.
3. Click **Export CSV**: one row per award.

**Expected**

- Position #1, gate = 1 direct, level 1 **Paid ₱1,000**, levels 2–10 not paid (0 teammates at those depths).
- CSV matches the screen; stats cards unchanged by source filter.

### QA24-22 — The admin team-harvest ledger

| | |
|---|---|
| **Why it matters** | The company sees every team-harvest credit with filters and an audited export. |
| **Where** | `/admin/commissions?ledger=team_harvest` |
| **Evidence** | `QA24-22-admin-team-harvest.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As `admin`, open **Finance → Commissions → team_harvest**.
2. Confirm one credited row: QA24_Sponsor, level 1, ₱1,000.
3. Filter by member/level/status; export CSV; confirm `/admin/audit` logs `team_harvest.export`.

**Expected**

- Header counters match SQL (`team_harvest_awards` sum = ₱1,000); row columns render; export matches screen and is audited.

## F. General harvest (QA24-23 … QA24-26)

### QA24-23 — Arm the board and confirm the qualified count N = 4

| | |
|---|---|
| **Why it matters** | General harvest is a separate board: every qualified member holds one node; a level pays when its block is full. Apex level 1 pays at N ≥ 4. |
| **Where** | `/admin/commissions?ledger=general_harvest` → board/position view; `mysql` |
| **Evidence** | `QA24-23-general-harvest-board.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Confirm four qualified members exist (QA24_Sponsor, QA24_Team_1, QA24_Team_2, QA24_Team_3) → `N = 4`.
2. Arm the board if not armed (the arm control on the settings card), record `N₀`.
3. Open the apex (position #1) board: level 1 block = queue indices 1–3 → filled at N = 4 → **Paid ₱2,000** to the apex holder (QA24_Sponsor).

**Expected**

- `N = 4`; apex level 1 block full (3/3), status **Paid**, amount **₱2,000**; recipient = first qualifier (QA24_Sponsor).
- No level-2 row (block needs N ≥ 13).

### QA24-24 — The general-harvest credit lands in the wallet

| | |
|---|---|
| **Why it matters** | The board payout becomes a withdrawable e-wallet credit with a ledger row. |
| **Where** | `general_harvest_awards` → QA24_Sponsor's **E-wallet** |
| **Evidence** | `QA24-24-general-harvest-award.png`, `QA24-24-general-harvest-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. `SELECT level, amount, status, ledger_id FROM general_harvest_awards WHERE user_id = QA24_Sponsor;` → level 1, **₱2,000.00**, **credited**.
2. QA24_Sponsor's **E-wallet**: one **General Harvest bonus** credit ₱2,000; **Withdrawable** rose by ₱2,000.
3. Confirm `SELECT SUM(amount) FROM general_harvest_awards;` = **₱2,000.00** (only the apex is paid at N = 4).

**Expected**

- One general-harvest award: QA24_Sponsor level 1, ₱2,000, credited, withdrawable.
- No other general-harvest award exists yet (positions #2–#4 need N ≥ 7 / 10 / 13 for their level-1 blocks).

### QA24-25 — The general-harvest position and progress view

| | |
|---|---|
| **Why it matters** | Each qualified member sees their own node, block fill and next completion. |
| **Where** | `/admin/commissions?ledger=general_harvest` (position table) |
| **Evidence** | `QA24-25-general-harvest-positions.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Show all four nodes with their positions, `left/right` block fill, and next completion (`next_n`).
2. Confirm the frontier: apex level 1 complete; positions #2–#4 level 1 each waiting for their own 3-slot block.

**Expected**

- Position table shows queue position, owner, filled/total per level, status Paid/progress, and next completion; no page error.

### QA24-26 — The admin general-harvest ledger

| | |
|---|---|
| **Why it matters** | Finance sees every general-harvest credit with filters and CSV export. |
| **Where** | `/admin/commissions?ledger=general_harvest` |
| **Evidence** | `QA24-26-admin-general-harvest.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Filter by member `QA24_Sponsor`, level 1, status credited.
2. Export CSV; confirm audit row `general_harvest.export` in `/admin/audit`.

**Expected**

- One credited row: QA24_Sponsor, level 1, ₱2,000; export matches screen and is audited.

## G. Reconciliation, restore and cleanup (QA24-27 … QA24-29)

### QA24-27 — Wallet reconciliation is clean

| | |
|---|---|
| **Why it matters** | Every commission, binary pair, team-harvest and general-harvest credit is a ledger row; the reconciler must agree. |
| **Where** | CLI from `system/` |
| **Evidence** | `QA24-27-reconcile.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. `php bin\wallet_reconcile.php` (and `--json`).
2. Confirm the commissions section, binary section and team/general harvest sections all report OK.

**Expected**

- Prints **Wallets OK**, **Commissions OK** (and any binary/harvest sections OK) and exits **0**; no mismatches.

### QA24-28 — Commission ↔ ledger tie-out

| | |
|---|---|
| **Why it matters** | Every credited award has a matching ledger movement (`ref_type = commission` or `binary`/`team_harvest`/`general_harvest`); skipped rows have none. |
| **Where** | `mysql` |
| **Evidence** | `QA24-28-tieout.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. `SELECT c.id, c.user_id, c.amount, c.status, c.ledger_id, l.amount AS ledger_amount, l.ref_type FROM commissions c LEFT JOIN ewallet_ledger l ON l.id = c.ledger_id ORDER BY c.id;` — every **credited** award has a `ledger_id` and matching amount; **skipped** rows have no `ledger_id`.
2. `SELECT (SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='credited') AS awards, (SELECT COALESCE(SUM(amount),0) FROM ewallet_ledger WHERE ref_type='commission') AS ledger;` — the two sums are equal.
3. Same tie-out for `ref_type IN ('binary','team_harvest','general_harvest')`.

**Expected**

- Credited awards ⇔ ledger rows one-to-one; skipped awards have no ledger row; the two sums agree.

### QA24-29 — Restore settings and clean up QA data

| | |
|---|---|
| **Why it matters** | Return the system to its pre-test state; remove only `QA24…` rows, never seeded data. |
| **Where** | `/admin/settings` → **Commissions** → restore all QA values; `mysql` |
| **Evidence** | — |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Restore every setting recorded in QA24-07 to its **before** value; save; reload to confirm.
2. Set Package A back to `binary_enabled = 0`, `binary_points = 0`.
3. `DELETE FROM users WHERE username LIKE 'QA24_%';` (cascade removes activations, commissions, binary nodes/volume/awards, harvest queue/awards, ledger rows).
4. `DELETE FROM reg_codes WHERE code LIKE 'QA24-%';`
5. `php bin\wallet_reconcile.php` → exit 0.

**Expected**

- All settings back to their original values; Package A binary off; only `QA24…` rows removed; reconcile still exits 0.

## Expected commission totals at the end of the walkthrough

| Member | Direct referral | Indirect referral | Binary pair | Team harvest | General harvest | Total |
|---|---|---|---|---|---|---|
| QA24_Sponsor | ₱500 | ₱250 (L2 ₱100 + L3 ₱50 + L2 ₱100) | ₱100 | ₱1,000 | ₱2,000 | **₱3,850** |
| QA24_Member_A | ₱500 | ₱100 | — | — | — | **₱600** |
| QA24_Member_B | ₱500 | ₱100 | — | — | — | **₱600** |
| QA24_Member_C | ₱500 | — | — | — | — | **₱500** |
| admin | — | — | — | — | — | **₱0** (skipped at L4) |

| Stream | Rows | Amount |
|---|---|---|
| Direct referral | 4 | ₱2,000 |
| Indirect referral (credited) | 6 | ₱750 |
| Indirect referral (skipped) | 1 | ₱50 not paid |
| Binary package (pair) | 1 | ₱100 |
| Team harvest | 1 | ₱1,000 |
| General harvest | 1 | ₱2,000 |

## Defect log template

| ID | Phase/check | Severity | What happened | Expected | Evidence | Status |
|---|---|---|---|---|---|---|
| QA24-D1 | QA24-04 | | | | `evidence/phase-24/QA24-04-…` | Open |

Severity: **Blocker** (cannot continue) · **Major** (wrong result) · **Minor** (visible, flow continues) · **Cosmetic** (visual only). Report; do not fix.

## Sign-off

| | |
|---|---|
| **Tester** | |
| **Date(s)** | |
| **Branch / tag** | |
| **Automated** | `php tests\run.php` green · `powershell -NoProfile -File tests\smoke.ps1` green · `php bin\wallet_reconcile.php` exit 0 |
| **Checks** | ☐ 29/29 Pass ☐ Fail ☐ Blocked |
| **Evidence** | `docs\qa\evidence\phase-24\` |
| **Decision** | ☐ Approved ☐ Approved with notes ☐ Open (blocker/major) |

Hand the completed sheet plus `docs/qa/evidence/phase-24/` to the project lead. The lead records the visual sign-off in `docs/phase-log.md`.
