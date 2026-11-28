# EcoWealth — Phase 25 QA: Product commissions (a beginner's walkthrough)

This is a **manual test script** for the money EcoWealth pays when a **product order is placed, paid
in full, delivered, and released**. You do not need to know how the system is built. You need a
browser, the app running on your own computer, and the patience to follow the steps in order.

You will create six test accounts, place five small orders, and compare what the screens and the
database say with what this document says they should say. Where they disagree, you record a defect.
You never change code and you never "fix" data.

| | |
|---|---|
| **Phase** | 25 — product commissions: repeat-purchase (unilevel) ladder, Product binary Self Bonus, pairs, delivery escrow and release |
| **Document version** | **v1.1** — the amounts and states below were reproduced by the run against the isolated schema `ecowealth_sneat_qa25`; the labels, messages, screen wording and ledger/audit names were re-checked against the current build on 28 Nov 2026 (see the box below and the evidence in `docs/qa/evidence/phase-25/run/`) |
| **Who this is for** | A tester with no prior knowledge of EcoWealth |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Admin login** | `admin` / `Admin@123` |
| **Your test accounts** | all prefixed `QA25`, password `Demo@1234`, created by you during the run |
| **Checks** | 24 (QA25-01 … QA25-24) |
| **Time** | 2½–3½ hours, including setup. Do not split it across days: the walkthrough depends on the order. |
| **Evidence folder** | `docs/qa/evidence/phase-25/` |

> **What "fully paid" means in this document.** A commission is **earned** the moment an order
> becomes **paid** (e-wallet immediately, manual payment only after an admin verifies the proof).
> It is **not withdrawable** yet: the award is written as **pending** and is credited to the wallet
> by the release job only after the order is **delivered** plus the configured hold. This run sets
> the hold to **0 days**, so delivery makes the money due immediately. That two-step design is
> deliberate (it protects the company from returns) and every check below respects it.

> **About the version.** This walkthrough was executed against a throwaway database, not against
> your development copy, so it could be run start to finish without touching real data. The run
> placed 5 orders and passed **30 of 30 assertions**; the raw log, CSVs and the driver script are in
> `docs/qa/evidence/phase-25/run/`. Three differences between the v1 draft and the run are recorded
> in §15.5 — they are corrections to *this document*, not defects in the app. **v1.1** only aligns
> labels, messages, screen wording and ledger/audit names with the current build; no amount or
> state changed.

---

## 1. What EcoWealth is, in one minute

EcoWealth is a small online shop with a member network attached.

- **A member** is an account. Every member has a **sponsor** — the member who introduced them. That
  chain of sponsors is called the **sponsor tree**.
- Members buy **products** from the shop. A product has a price (₱) and a **P.V.** (point value).
  The repeat-purchase bonus is calculated on a **bonus base**, which is the P.V. when it is set.
- There is a second tree, unrelated to sponsors: the **binary tree**. Each member sits on the
  **left** or **right** side of one *binary upline*, and each side holds exactly one member. Product
  points travel up that tree and can pay a **pair**.
- Every member has two balances in one **e-wallet**: *withdrawable* money (commission you can take
  out) and *spend-only* money (top-ups you can only spend in the shop). Commission always lands in
  the **withdrawable** bucket.

The trigger is **a paid order**. When an order is fully paid, the system immediately writes the
commission **decisions** for that order: one row per recipient per level, a wallet movement only
later, and one line in the accounting table (`ewallet_ledger`) when the money is actually released.
Nothing is paid twice, and nothing is paid before the order is paid.

### The three product money streams you are testing

| Stream | Plain English | Paid to |
|---|---|---|
| **Repeat-purchase bonus** (unilevel) | Every product carries a ten-level ladder of percentages (Coffee: 5/5/5/3/3/2/2/2/1/1 = 29%). The order's base is multiplied by each level's rate and paid up the **sponsor** chain. | The buyer's sponsor (level 1), then their sponsor (level 2), and so on |
| **Product binary — Self Bonus** | The buyer earns a percentage of the points their own order carries (5% here). | The buyer |
| **Product binary — pairs** | Points land on the side the buyer chose and roll up every binary upline. When one member has 1,000 points on the left and 1,000 on the right, they earn one pair = 10% of 1,000 = ₱100. | The owner of the pair (the buyer or an upline) |

The repeat-purchase and binary streams **both** run on the same payment event. Each is switched on
separately, and each writes its own table. This document checks both.

**Not in this document.** Package commissions (registration codes, activations, Team Harvest,
General Harvest) belong to Phase 24. Royalty is a **monthly close**, not an order event; it belongs
to Phase 19. If you want those, use their own walkthroughs. Here, a product order must pay **only**
the three streams above.

---

## 2. What this walkthrough proves

By the end you will have shown that:

1. A paid order pays the repeat-purchase ladder at the right amount for each level, and **skips**
   the company account instead of paying it.
2. The buyer is **never** a repeat-purchase recipient, no matter how deep the chain is.
3. A manual (GCash) order pays **nothing** until an admin verifies the proof, and pays exactly like
   an e-wallet order once it is verified.
4. A product order stamps its **points**, pays the buyer's **Self Bonus**, and rolls volume up every
   binary upline on the correct side.
5. Two legs of 1,000 points pay exactly **one pair**, once, to the right member.
6. Paid money is **pending** until the order is delivered and the release job runs; the wallet and
   the `ewallet_ledger` then agree to the centavo.
7. Switching a stream off **stamps a zero decision** and writes no award; a suspended upline is
   skipped without compression; cancelling before release moves no money.
8. The admin and member screens are read-only, and a member cannot read another member's money.

---

## 3. Before you start

Work through this list once. It takes about ten minutes and saves an hour of confusion.

### 3.1 Start the app

1. Open **Laragon** and press **Start All**. Wait until Apache and MySQL show green.
2. Open `http://localhost/ecowealth/system/` in your browser. You should see the EcoWealth home page.
3. Sign in with `admin` / `Admin@123`. You land on the **back office** dashboard.
4. Click your name (top right) → **Sign out**. Confirm you can sign back in. If you cannot, stop
   here: the rest of the document cannot run and that is a blocker to report.

> **Never test on a live site.** The address must start with `http://localhost/`. If it does not,
> close the tab and start again.

### 3.2 Know your two windows

You will switch between an administrator and a brand-new member dozens of times. Use:

- **Window A** — your normal browser window, signed in as `admin`.
- **Window B** — an Incognito/Private window (`Ctrl+Shift+N`), signed out. This is where you register
  and sign in as `QA25…` members.

When a step says *"as `QA25_Buyer`"*, sign that account in inside Window B. When it says *"as
admin"*, use Window A. Never sign in as two members in the same window: the session replaces itself
and you will see the wrong dashboard.

> Signing up **signs the new member in**. After every registration, close Window B and open a fresh
> Incognito window before registering the next account. If you register the next member in the same
> window, the form runs as the previous member and refuses the placement with *"That upline is
> outside your subtree."*

### 3.3 Create the evidence folder

All screenshots and terminal output go in:

```text
C:\laragon\www\ecowealth\docs\qa\evidence\phase-25\
```

Create it now if it does not exist. Name each file exactly as the check's **Evidence** row says
(for example `QA25-06-unilevel-awards.png`). A check without its evidence file is not signed off.

### 3.4 Make a backup first (recommended)

The walkthrough creates and deletes data. A backup means a mistake costs minutes, not a day.

1. Open `http://localhost/phpmyadmin/`.
2. Click the database **`ecowealth_sneat`** in the left list.
3. Open the **Export** tab → **Export** (SQL format, default settings).
4. Save the file somewhere outside the project, e.g. `C:\laragon\www\ecowealth-backups\phase-25\`.

If you are comfortable with a terminal, this does the same thing:

```powershell
cd C:\laragon\www\ecowealth
mysqldump -u root -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-25\pre-phase-25.sql
```

### 3.5 Know how to read the database

Several checks ask you to confirm what the system wrote. You do not need to know SQL. You need to
copy, paste and compare.

1. `http://localhost/phpmyadmin/` → click **`ecowealth_sneat`** → **SQL** tab.
2. Paste the query from the check and press **Go**.
3. Compare the result with the table in the check.

The queries in this document all start by looking up an account id from its username, so you can
paste them exactly as written. **Never run a query that changes data** (`UPDATE`, `DELETE`, `INSERT`)
except the single cleanup block in QA25-24, which removes only `QA25…` rows.

### 3.6 What you need on screen

- The app in two windows, as above.
- A text note (Notepad is fine) for the **before** values you record in QA25-01.
- A folder for screenshots.
- A fresh terminal in `C:\laragon\www\ecowealth\system` for the commands near the end. Open
  **PowerShell**, then:

```powershell
cd C:\laragon\www\ecowealth\system
php -v
```

You should see a PHP 8.x version line. If `php` is not recognised, use Laragon's copy:
`C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe`.

### 3.7 One rule about the data

The whole walkthrough depends on the accounts being registered **in the order given**, and on the
binary sides in §8.3. If you skip a step or register an extra member, finish the run and note it in
the defect log rather than trying to delete your way back. Cleanup (QA25-24) removes every `QA25…`
account at the end.

Before you begin, confirm that no `QA25…` account already exists (Commerce → Members, search
`QA25`). If one does, it is from an earlier attempt: either finish that attempt or run QA25-24's
cleanup first.

> **The totals below are absolute.** They assume this is the only product-commission data in the
> database, as in the run (a fresh schema). If your copy already has paid product orders, QA25-11's
> cards, QA25-23's reconciler totals and QA25-24's tie-out include them; filter the rows and CSV to
> the QA25 accounts, and scope the tie-out's award and ledger subqueries by QA25 orders/users — the
> figures above are the QA25-only totals.

---

## 4. The words in this document

Look here whenever a word is unfamiliar. You are not expected to memorise it.

| Word | What it means here |
|---|---|
| **Award** | One `unilevel_awards` or `binary_awards` row: recipient, source order, amount, status. |
| **Base** | The amount the ladder percentages apply to. For Coffee it is the P.V. × quantity: 4 units = ₱1,000. |
| **Binary node** | A member's position in the binary tree: one upline, one side (`left`/`right`). |
| **Binary upline** | The member whose left or right slot you occupy. Not necessarily your sponsor. |
| **Cancelled** | An award that was pending and whose order was cancelled before release. No money moved. |
| **Commission row** | One award record. `pending` = earned, not in a wallet yet; `credited` = in the wallet; `skipped` = refused, no money; `cancelled` = order cancelled before release. |
| **Credited** | Money actually paid into the withdrawable wallet. |
| **Escrow / hold** | The gap between payment and withdrawable money. Awards stay `pending` until the order is delivered *and* the hold days have passed. |
| **E-wallet** | The member's two balances. Commission credits the **withdrawable** side. |
| **Ledger** (`ewallet_ledger`) | The accounting record of every wallet movement. Every credited award has exactly one matching line. |
| **Level** | Distance up the sponsor chain: sponsor = level 1, their sponsor = level 2, and so on. |
| **Ladder** | The ten percentages a product pays the sponsor chain. Coffee's is 5/5/5/3/3/2/2/2/1/1. |
| **Pair** | 1,000 points on the left and 1,000 on the right under one binary member = ₱100 here. |
| **P.V.** | Point value. Coffee's is 250 per unit. It is both the bonus base and the binary points. |
| **Pending** | Earned at payment, waiting for delivery + hold. Not in any wallet yet. |
| **Release** | The job (`bin\unilevel_release.php`) that turns due `pending` awards into wallet credits. |
| **Reconciler** | A command that checks every wallet balance and award against the ledger and reports mismatches. |
| **Self Bonus** | The buyer's own 5% of their order's points. |
| **Skipped** | A payment the rules refused (company account, suspended upline). Recorded, but no money moves and no ledger row exists. It consumes the level; it is not paid to anybody else. |
| **Sponsor** | The member who introduced this member (sponsor tree). |
| **Standard member** | A free account: can shop, can earn these product commissions. No activation needed. |
| **Withdrawable** | The part of the wallet a member can withdraw. Commission lands here. |

---

## 5. Where everything lives

You will use these screens repeatedly. Menu names are exact; URLs are there when a menu is easier to
skip.

| What you want | Sign in as | Menu path | URL |
|---|---|---|---|
| Turn the streams on/off, set percentages and holds | admin | System → Settings → **Commissions** tab | `/admin/settings#settings-commissions` |
| Turn order cancellation on/off | admin | System → Settings → **Shop** tab | `/admin/settings#settings-shop` |
| Configure a product's ladder, P.V. and binary points | admin | Catalogue → Products → open **Coffee** | `/admin/products/1/edit` |
| Fund a buyer's wallet | admin | Finance → Wallets → search member → **Credit wallet** | `/admin/wallets` |
| Suspend or restore an upline | admin | Commerce → Members → open member → **Suspend member** | `/admin/members` |
| The commission ledgers | admin | Finance → Commissions → **Repeat-purchase bonus** / **Product binary** tabs | `/admin/commissions?ledger=repeat` |
| Deliver an order | admin | Commerce → Orders → open the order | `/admin/orders` |
| Verify a manual payment | admin | Commerce → Orders → open the order → **Verify payment** | `/admin/orders` |
| See your own awards | the member | Membership → **Earnings** → **Repeat-purchase bonus** / **Product binary** | `/earnings?ledger=repeat` |
| See your own wallet | the member | Money → **E-wallet** | `/wallet` |
| Shop and check out | the member | Shopping → **Shop** → product → **Cart** | `/shop`, `/cart`, `/checkout` |
| Register someone | anyone | Membership → Register | `/register` |
| The audit trail | admin | System → Audit log | `/admin/audit` |

> **Menus differ by role.** An admin sees "Commerce", "Finance" and "System". A member sees
> "Shopping", "Membership", "Money" and "Account". If a menu in this document is missing, you are
> signed in as the wrong role.

---

## 6. How to use a check

Every check is a small table followed by numbered steps.

| | |
|---|---|
| **What it proves** | Why the check exists, in one sentence. |
| **Before** | What must already be true. If it is not, go back. |
| **Where** | The menu path and URL. |
| **Evidence** | The exact file name(s) to save. |
| **Result** | Tick one box when you are done. |

**Result definitions**

| Box | Use it when |
|---|---|
| **Pass** | Everything in *You should see* happened. |
| **Fail** | The app did something else. Record a defect with the ID, what you saw and what you expected. |
| **Blocked** | You could not run the check at all (app down, previous step failed, missing account). Say why. |

**Evidence rules**

- One screenshot per numbered evidence file. Capture the part of the screen that proves the point —
  the row, the amount, the status — not the whole browser.
- If a check asks for a command or a query result, save the text output as `.txt` in the same folder.
- Never crop out the amount or the member name; that is the evidence.

**When something fails**

1. Take the screenshot anyway.
2. Write the defect in the log at the end of this document: check ID, what happened, what should
   have happened, severity.
3. If the failure does not stop the next check, continue. If it does (for example the order was not
   paid), mark the dependent checks **Blocked** and keep going with another part.
4. Do **not** edit code, settings you were not told to change, or database rows to "make it pass".

---

## 7. The two trees you will build

### 7.1 Why this shape

- The **repeat-purchase ladder** needs a sponsor chain five deep, so the last order reaches level 5
  — the company account — and proves a company-root skip.
- The **binary pair** needs one buyer's points on the left of a member and another buyer's points on
  the right. `QA25_Boss` is that member.
- The two trees **disagree on purpose**: `QA25_Buyer2` is sponsored by `QA25_C` (so the ladder is
  the same for both buyers) but sits in the binary tree on `QA25_B`'s right. That is what gives
  `QA25_Boss` a right leg.

### 7.2 The shape

```text
Sponsor tree (who introduced whom)          Binary tree (who sits under whom)
admin (company root, never paid)            admin
└── QA25_Boss                                └── LEFT  QA25_Boss
    ├── QA25_A                                   ├── LEFT  QA25_A
    │   ├── QA25_B                               │   ├── LEFT  QA25_C
    │   │   └── QA25_Buyer2                      │   └── RIGHT QA25_Buyer
    │   └── QA25_C                               └── RIGHT QA25_B
    │       ├── QA25_Buyer                           └── RIGHT QA25_Buyer2
    │       └── QA25_Buyer2
    └── (no more)
```

`QA25_B` sits on `QA25_Boss`'s right although `QA25_B` is sponsored by `QA25_A`. `QA25_Buyer` sits
on `QA25_A`'s right although sponsored by `QA25_C`. Both splits are deliberate: sponsors decide the
repeat-purchase ladder; binary uplines decide points and pairs.

### 7.3 Every account you must create

The **Registration form** columns are literally what you type. Leave a cell saying *blank* empty.

| # | Username | Sponsor | Binary upline | Side | Mode |
|---|---|---|---|---|---|
| 1 | `QA25_Boss` | *blank* (→ admin) | *blank* (→ admin) | Left* | Standard |
| 2 | `QA25_A` | `QA25_Boss` | `QA25_Boss` | Left | Standard |
| 3 | `QA25_B` | `QA25_A` | `QA25_Boss` | Right | Standard |
| 4 | `QA25_C` | `QA25_B` | `QA25_A` | Left | Standard |
| 5 | `QA25_Buyer` | `QA25_C` | `QA25_A` | Right | Standard |
| 6 | `QA25_Buyer2` | `QA25_C` | `QA25_B` | Right | Standard |

\* **`QA25_Boss`'s own placement is the only flexible one.** The form leaves the upline empty and
uses `admin`; if `admin`'s left slot is already taken on your copy, choose the side the form offers
(the run used **Left**). What matters is that `QA25_A` ends up on `QA25_Boss`'s **left** and
`QA25_B` on the **right**. Every later number is unaffected by where `QA25_Boss` itself sits.

Emails can be anything unique that looks like an address (`qa25.boss@example.test`). Password for
every `QA25…` account: `Demo@1234`.

### 7.4 What the points do

Only an order with points adds volume:

- Coffee carries **250 P.V.** per unit.
- 4 units = **1,000 points** and a **₱1,000 bonus base**.
- The buyer's order places its points on the side they choose at checkout (its own node), and the
  same points roll up **every** binary upline on the side the buyer sits under.
- 1,000 on the left plus 1,000 on the right at one node = **one pair** = ₱100.

Points are integers; money is centavos. A pair consumes the points it matched, which is why
`QA25_Boss` ends with 0/0 after paying one pair.

---

## 8. Run order

Do the parts in this order. The check **numbers are fixed IDs**, not sequence: QA25-01 runs first
and QA25-24 last, but several checks are verified after money has moved.

| Part | Checks | What it does | Why here |
|---|---|---|---|
| **1** | QA25-01, QA25-02, QA25-03 | Turns the streams on, configures Coffee, builds the tree and funds the buyers | Idempotency: a product's ladder is read **at payment time**, so it must be configured before the first order |
| **2** | QA25-04 … QA25-08 | Places the first e-wallet order and reads the decisions | This is the money event; everything is `pending` here |
| **3** | QA25-09 … QA25-14 | Delivers, releases, then reads wallets and ledgers | Money only becomes withdrawable after delivery + release |
| **4** | QA25-15 … QA25-18 | Places a manual order, verifies it, and completes the pair | The second payment path and the right leg |
| **5** | QA25-19 … QA25-22 | Suspended upline, switches off, cancellation before release | Negative controls, after the positive money is proven |
| **6** | QA25-23, QA25-24 | Reconcile, tie out, restore and clean up | Always last |

**Time guide:** Part 1 40 min · Part 2 35 min · Part 3 40 min · Part 4 35 min · Part 5 25 min ·
Part 6 15 min.

---

## 9. Part 1 — Prepare the system

### QA25-01 — Record the defaults, then turn both streams on

| | |
|---|---|
| **What it proves** | Both streams can be switched on with the run's amounts, the values survive a save, and you have a record to restore at the end. |
| **Before** | You are signed in as `admin` on a development copy. |
| **Where** | System → Settings → **Commissions** tab (`/admin/settings#settings-commissions`) |
| **Evidence** | `QA25-01-settings-before.png`, `QA25-01-settings-after.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Open Settings → **Commissions**. One form holds these sub-blocks: *Direct referral bonus*,
   *Indirect referral (unilevel)*, *Repeat-purchase bonus*, *Royalty bonus*, **Team Harvest bonus**,
   **General Harvest bonus**, **Product binary**, **Package binary**.
2. In your notes, write down the **current** value of every control in the table below. You restore
   them in QA25-24, so a wrong "before" value means you cannot reset the system.
3. Change exactly these controls (leave everything else alone):

   | Control on the page | Card | Set to |
   |---|---|---|
   | Repeat-purchase bonus enabled (switch) | Repeat-purchase bonus | **on** |
   | Upline depth | Repeat-purchase bonus | **10** |
   | Monthly personal-purchase minimum | Repeat-purchase bonus | **500.00** |
   | Release delay after delivery | Repeat-purchase bonus | **0** |
   | Product binary enabled (switch) | Product binary | **on** |
   | Self Bonus % | Product binary | **5** |
   | Points per pair | Product binary | **1000** |
   | Pair value % | Product binary | **10** |
   | Pairs per day per member | Product binary | **20** |
   | Release delay after delivery | Product binary | **0** |
   | Require the monthly Active rule (both cards) | both | **leave on** |

4. Click **Save settings**. Reload the page.
5. Screenshot the two cards, then fill in the table below.

**You should see**

- A confirmation banner (or "No changes to save" if nothing differed).
- After the reload every control above still holds its value: both switches **on**, depth **10**,
  both holds **0**, 5% / 1,000 / 10% / 20.
- Nothing else on the page changed.

**Record here (fill this in as you go)**

| Control | Before | QA value | Restored in QA25-24 |
|---|---|---|---|
| Repeat-purchase bonus enabled | | on | ☐ |
| Upline depth | | 10 | ☐ |
| Monthly personal-purchase minimum | | 500.00 | ☐ |
| Repeat-purchase release delay | | 0 | ☐ |
| Product binary enabled | | on | ☐ |
| Self Bonus % | | 5 | ☐ |
| Points per pair | | 1000 | ☐ |
| Pair value % | | 10 | ☐ |
| Pairs per day per member | | 20 | ☐ |
| Product binary release delay | | 0 | ☐ |
| Require the monthly Active rule (×2) | | on | ☐ |

> **The two holds matter.** *Release delay after delivery* is what keeps the money in escrow. The
> default is **7 days**; this run sets **0** so you can see the release in one sitting. Each award
> snapshots its hold when it is written, so changing the setting later never moves an existing award.

> **Why the Active rule does not block this run.** It reads the previous month's personal purchases
> (₱500 minimum) for accounts that existed at a month close; a missing flag means Active. All six
> QA25 accounts are created fresh here, so they qualify. Reusing accounts from an earlier attempt
> can change that — start from a clean `QA25` prefix.

**If it fails** — a value refuses to save with a validation message: read it, correct the value, try
again; if it still refuses, that is a **Fail**. If a "before" note is missing, stop and take a fresh
backup rather than guessing later.

### QA25-02 — Give Coffee a P.V., the reference ladder and binary points

| | |
|---|---|
| **What it proves** | One product can carry the ladder, the bonus base and the points that the whole run depends on. All of them must be on **before** the first order, because the ladder is read at payment. |
| **Before** | QA25-01 passed. Coffee exists in the catalogue (it ships with every install). |
| **Where** | Catalogue → Products → **Coffee** → edit (`/admin/products/1/edit`) |
| **Evidence** | `QA25-02-product-repeat.png`, `QA25-02-product-binary.png`, `QA25-02-product-badge.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Catalogue → **Products** → open **Coffee**.
2. In **Pricing & stock**, set **Price** `300.00` and **Point value (P.V.)** `250.00`.
3. Find the **Unilevel repeat-purchase bonus** card:
   - switch **Pay this product's ladder** on;
   - **Bonus base** = **Auto — P.V. when set, else the price**;
   - enter the ten levels exactly: `5`, `5`, `5`, `3`, `3`, `2`, `2`, `2`, `1`, `1`.
4. Find the **Product binary points** card and switch **Pay product binary points** on. The readout
   should say **250.00 P.V. × 1 unit = 250 pts per unit**, and the **Self bonus** line shows
   **₱12.50 per unit at this P.V.**
5. Click **Save changes** (one save covers the whole product). Reopen Coffee.
6. Look at Coffee's row on `/admin/products`.

**You should see**

- Both cards still on after the reopen, P.V. `250.00`, base **Auto**, all ten rates as entered,
  and the ladder's Total row **29.000%**.
- The product row shows two badges: **Repeat bonus 29%** and **Product binary 250 pts**.
- In phpMyAdmin (`ecowealth_sneat` → **SQL**):

  ```sql
  SELECT pv, unilevel_enabled, unilevel_base, binary_enabled
    FROM products WHERE id = 1;
  ```

  One row: `250.00 | 1 | auto | 1`.

- And the ten stored rates:

  ```sql
  SELECT level, percent FROM product_unilevel_levels
   WHERE product_id = 1 ORDER BY level;
  ```

  Ten rows, 5/5/5/3/3/2/2/2/1/1, each `…000`.

**If it fails** — the form refuses to save the ladder because the total exceeds 100%: you typed an
extra digit. If the binary card refuses while P.V. is 0, set the P.V. first. If the badges do not
appear, the switch did not save: **Fail**, and stop. Every amount below depends on this check.

### QA25-03 — Build the six-account tree and fund the buyers

| | |
|---|---|
| **What it proves** | Free accounts can be placed in both trees, the sponsor chain and binary sides are exactly what the form said, and the buyers start with spend-only money. |
| **Before** | QA25-01 and QA25-02 passed. No `QA25…` account exists. |
| **Where** | `/register` (Window B), then Finance → Wallets (Window A) |
| **Evidence** | `QA25-03-register-boss.png`, `QA25-03-tree.png`, `QA25-03-wallets.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

Register six accounts, **each in a fresh Incognito window**, exactly as §7.3 says. For each:
open `/register` → choose **Standard** (note **Free · shop only**) → type the sponsor → in **Binary
placement**, type the
upline and pick the side → username, email, password `Demo@1234` → tick the terms → **Create
account**.

| Order | Username | Sponsor | Binary upline | Side |
|---|---|---|---|---|
| 1 | `QA25_Boss` | *blank* | *blank* | Left* |
| 2 | `QA25_A` | `QA25_Boss` | `QA25_Boss` | Left |
| 3 | `QA25_B` | `QA25_A` | `QA25_Boss` | Right |
| 4 | `QA25_C` | `QA25_B` | `QA25_A` | Left |
| 5 | `QA25_Buyer` | `QA25_C` | `QA25_A` | Right |
| 6 | `QA25_Buyer2` | `QA25_C` | `QA25_B` | Right |

Then, as admin (Window A):

1. Finance → **Wallets** → search `QA25_Buyer` → open → **Credit this wallet** → amount **1500.00**
   → note "QA25 float" → **Credit wallet**.
2. Repeat for `QA25_Buyer2` with **1500.00**.

**You should see**

- After each registration: *"Welcome! Your standard account is ready — you can start shopping."*
  and the new member is **active**, package `—`, wallet ₱0.00.
- After each top-up: *"₱1,500.00 credited to @QA25_Buyer — the amount is spendable in the shop and
  non-withdrawable."* (and the same for Buyer2).
- In phpMyAdmin, the tree matches §7.2:

  ```sql
  SELECT u.username, s.username AS sponsor, up.username AS upline, n.side, n.state
    FROM users u
    LEFT JOIN users s ON s.id = u.sponsor_id
    LEFT JOIN binary_nodes n ON n.user_id = u.id
    LEFT JOIN users up ON up.id = n.upline_id
   WHERE u.username LIKE 'QA25\_%'
   ORDER BY u.id;
  ```

  Six rows; `QA25_Buyer`'s sponsor is `QA25_C` and its upline is `QA25_A`, side **right**;
  `QA25_Buyer2`'s sponsor is `QA25_C` and its upline is `QA25_B`, side **right**.

- The wallet query:

  ```sql
  SELECT username, ewallet_balance, withdrawable_balance
    FROM users WHERE username IN ('QA25_Buyer','QA25_Buyer2','QA25_Boss');
  ```

  Buyer and Buyer2 `1500.00 | 0.00`; Boss `0.00 | 0.00`.

\* If `admin`'s left slot is taken, use the free side the form offers, and note it. The pair needs
`QA25_A` on Boss's **left** and `QA25_B` on Boss's **right**; that is what the table above builds.

**If it fails** — *"This side is already taken"*: a previous test left a node there. Pick the free
side the form shows **only for `QA25_Boss`**; for the other five, a taken slot means the fixture is
wrong and the pair cannot be built — note it and stop that part. *"That upline is outside your
subtree"*: you are still signed in as the previous member; use a fresh Incognito window.

---

## 10. Part 2 — The e-wallet order (the money event)

### QA25-04 — The cart and checkout quote what the order will pay

| | |
|---|---|
| **What it proves** | The shop builds an order of 4 × Coffee at ₱1,200, the checkout previews the ladder and the binary outcome, and the page asks for a binary side because the product carries points. |
| **Before** | QA25-03 passed. Buyer's wallet is ₱1,500. |
| **Where** | `/shop` → Coffee → `/cart` → `/checkout` as `QA25_Buyer` (Window B) |
| **Evidence** | `QA25-04-cart.png`, `QA25-04-checkout-preview.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In Window B, sign in as `QA25_Buyer` / `Demo@1234`.
2. Shopping → **Shop** → open **Coffee** → quantity **4** → **Add to cart**.
3. Open the cart and press **Proceed to checkout**.
4. Read the two preview cards. Do **not** place the order yet.

**You should see**

- The cart has one line: Coffee × 4, line total **₱1,200.00**, and the order total is **₱1,200.00**.
- A repeat-purchase line that begins **"Up to ₱290.00 is earned by the members above you when this
  order…"**.
- A **Place this purchase** card (the Product binary card) offering **Left** / **Right**, each
  side reading **1000 pts · you earn ₱50.00 on this order · 0 pairs now**. The choice is required.

> **Why ₱290 but only ₱180 later?** ₱290 is 29% of ₱1,000 — the **whole ladder**, including every
> configured level. The preview is an "up to" figure and does not know who is eligible. In this
> fixture level 5 is the company account, which is never paid, so the order records ₱180 credited
> and ₱30 skipped. The ledger (QA25-06) is the authoritative number.

**If it fails** — no repeat line: the product switch or the global switch is off (QA25-01/02). No
binary card: the product carries 0 points. A preview of ₱0.00 with the cards on: the product's base
is 0 — check the P.V. and **Auto**.

### QA25-05 — The e-wallet order is paid once, and the wallet is debited once

| | |
|---|---|
| **What it proves** | An e-wallet checkout is paid instantly, charges the wallet exactly the total, empties the cart and issues an invoice. |
| **Before** | QA25-04 passed. |
| **Where** | `/checkout` → **Place order** as `QA25_Buyer` |
| **Evidence** | `QA25-05-order-paid.png`, `QA25-05-wallet-debit.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Choose **E-wallet** as the payment method. Its hint should read *"Pay immediately from your wallet
   balance."*
2. Pick **Left** for this order's binary position.
3. Fill the delivery fields (name, phone, address "25 QA Street", barangay, city, province, postal
   `1200`), tick the terms, press **Place order**.
4. Read the success banner and the order page, then open **Money → E-wallet**.

**You should see**

- **"Order placed and paid. Thank you!"** and an order page whose status is **Paid**, with an order
  number like `EW26112300001` and a total of **₱1,200.00**.
- The cart is empty.
- The wallet shows **one** debit of ₱1,200.00 (order payment); balance **₱300.00**, withdrawable
  still **₱0.00**.
- No commission credit yet.

**If it fails** — *"Your e-wallet balance (₱…) is not enough for this order (₱1,200.00)"*: the
top-up did not land; go back to QA25-03. A double debit: **Fail**.

### QA25-06 — The ladder is written as five decisions, four of them pending

| | |
|---|---|
| **What it proves** | Payment writes the repeat-purchase rows immediately, at the product's rates, and does **not** put them in a wallet yet. |
| **Before** | Order #1 is paid. |
| **Where** | phpMyAdmin (`unilevel_awards`) |
| **Evidence** | `QA25-06-unilevel-awards.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Find the order id:

   ```sql
   SELECT id, order_no, status, unilevel_processed_at, unilevel_total
     FROM shop_orders
    WHERE user_id = (SELECT id FROM users WHERE username = 'QA25_Buyer')
    ORDER BY id DESC LIMIT 1;
   ```

   Write the id here: `order #1 = ____`. The run's was **#1**, stamped, total **180.00**.

2. Read the decisions:

   ```sql
   SELECT a.level, u.username AS recipient, a.amount, a.status, a.reason, a.hold_days,
          a.ledger_id, a.credited_at
     FROM unilevel_awards a JOIN users u ON u.id = a.user_id
    WHERE a.order_id = <order #1>
    ORDER BY a.level;
   ```

**You should see**

| level | recipient | amount | status | reason | ledger_id |
|---|---|---|---|---|---|
| 1 | `QA25_C` | 50.00 | **pending** | *empty* | *NULL* |
| 2 | `QA25_B` | 50.00 | **pending** | *empty* | *NULL* |
| 3 | `QA25_A` | 50.00 | **pending** | *empty* | *NULL* |
| 4 | `QA25_Boss` | 30.00 | **pending** | *empty* | *NULL* |
| 5 | `admin` | 30.00 | **skipped** | `company account is not paid` | *NULL* |

Five rows, no sixth: the chain is five deep. The amounts are **5% / 5% / 5% / 3% / 3%** of the
₱1,000 base. `hold_days` is **0** on every row (you set it in QA25-01). Pending rows have **no
ledger id** — that is the escrow: earned, not yet in a wallet.

> **Why a skipped row and not "no row"?** Finance must be able to see that a level was **decided**
> and refused. The ₱30 is not redirected to anyone; level 5 is consumed, and no deeper level exists.

**If it fails** — four pending rows but no level 5: the upline depth is below 5 or the company root
was compressed. Amounts of 0: the ladder did not save (QA25-02). Rows already `credited` with a
`ledger_id` before release: **Fail** — the escrow was bypassed.

### QA25-07 — The buyer is never a recipient of their own purchase

| | |
|---|---|
| **What it proves** | No level-0 rebate exists: the buyer's own award list and wallet contain nothing from this order except the payment debit. |
| **Before** | QA25-06 passed. |
| **Where** | phpMyAdmin (`unilevel_awards`, `ewallet_ledger`) |
| **Evidence** | `QA25-07-buyer-excluded.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In phpMyAdmin (`ecowealth_sneat` → **SQL**), run:

   ```sql
   SELECT COUNT(*) AS rows_for_buyer
     FROM unilevel_awards
    WHERE order_id = <order #1>
      AND user_id = (SELECT id FROM users WHERE username = 'QA25_Buyer');

   SELECT ref_type, amount, note
     FROM ewallet_ledger
    WHERE user_id = (SELECT id FROM users WHERE username = 'QA25_Buyer')
    ORDER BY id;

   ```

**You should see**

- `rows_for_buyer = 0`.
- One ledger row for this order: `shop_order | -1200.00 | Order EW…` (the debit). **No** row with
  `ref_type = unilevel` and **no** credit.

### QA25-08 — Points, the Self Bonus and the volume roll-up

| | |
|---|---|
| **What it proves** | The same paid order stamps 1,000 points, writes a pending Self Bonus for the buyer, and rolls 1,000 points up every binary upline on the correct side. |
| **Before** | Order #1 is paid. |
| **Where** | phpMyAdmin (`shop_orders`, `binary_awards`, `binary_volume`, `binary_nodes`) |
| **Evidence** | `QA25-08-order-binary.png`, `QA25-08-volume.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. The order snapshot:

   ```sql
   SELECT binary_position, binary_processed_at, binary_points,
          binary_self_total, binary_pair_total, binary_total
     FROM shop_orders WHERE id = <order #1>;
   ```

   Expected: `left | timestamp | 1000 | 50.00 | 0.00 | 50.00`.

2. The binary award:

   ```sql
   SELECT a.kind, u.username AS recipient, a.amount, a.status, a.pair_count, a.hold_days, a.ledger_id
     FROM binary_awards a JOIN users u ON u.id = a.user_id
    WHERE a.order_id = <order #1> ORDER BY a.id;
   ```

   Expected: one row, `self | QA25_Buyer | 50.00 | pending | 0 | 0 | NULL`.

3. The volume rows:

   ```sql
   SELECT u.username AS node, v.side, v.points, v.kind
     FROM binary_volume v JOIN users u ON u.id = v.node_user_id
    WHERE v.order_id = <order #1> ORDER BY v.id;
   ```

**You should see**

| node | side | points | kind |
|---|---|---|---|
| `QA25_Buyer` | left | 1000 | own |
| `QA25_A` | right | 1000 | team |
| `QA25_Boss` | left | 1000 | team |
| `admin` | left | 1000 | team |

Four rows. The buyer's own node takes the side they chose at checkout (**left**); each ancestor
takes the side the **path** gives it — Buyer is on A's right, A on Boss's left, Boss on admin's
left. The company root receives volume but is never paid money — that is the company-account rule,
and QA25-06 shows its decision for the ladder.

**If it fails** — 0 points: Coffee's P.V. or binary switch is off. `binary_processed_at` is NULL and
there are no rows: the stream was off at payment (it stamps zero rather than waiting). A self award
of 0: the Self Bonus percentage is 0.

---

## 11. Part 3 — Deliver, release and read the money

### QA25-09 — Delivery makes the awards due; the release job credits them

| | |
|---|---|
| **What it proves** | The escrow works in both directions: nothing is withdrawable before delivery, and after delivery + 0 hold the release job credits every due award exactly once. |
| **Before** | Order #1 is paid and has four pending repeat-purchase rows and one pending binary row. |
| **Where** | Commerce → Orders → order #1; terminal in `C:\laragon\www\ecowealth\system` |
| **Evidence** | `QA25-09-delivered.png`, `QA25-09-release-dry-run.txt`, `QA25-09-release.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, Commerce → **Orders** → open order #1.
2. Press **Start packing** (the order becomes **Packing**), then **Mark ready to ship**, then
   **Ship order**: courier `J&T Express`, tracking `QA251`, press **Ship order**. Finally press
   **Mark delivered**.
3. In the terminal, run the dry run first:

   ```bat
   php bin\unilevel_release.php --dry-run --stream=all
   ```

4. Then run it for real:

   ```bat
   php bin\unilevel_release.php --stream=all --limit=200
   ```

**You should see**

- The four admin messages: *"Packing started. The order is now Packing."*, *"The order is packed and
  ready to ship."*, *"Order shipped via J&T Express — tracking QA251. The stock has been deducted."*,
  *"Order marked delivered. The member can now confirm receipt."*
- The dry run exits **0**. The repeat-purchase block reports `pending: 4 award(s) · ₱180.00` and
  `candidates: 1 → orders 1`, then lists the four awards as
  `order EW… (#1) → award #… · QA25_C · level 1 · ₱50.00` (and B, A, Boss). The product-binary block
  reports `pending: 1 award(s) · ₱50.00` and `candidates: 1 → orders 1`, then the buyer's `self`.
- The real run exits **0** with `released: 1 order(s) · 4 award(s) · ₱180.00` for repeat-purchase
  and `released: 1 order(s) · 1 award(s) · ₱50.00` for product binary. The pair does not exist yet —
  the right leg arrives in QA25-17.
- Running the same command again changes nothing: each stream prints
  `released: 0 order(s) · 0 award(s) · ₱0.00`.

**If it fails** — `pending: 0` in the dry run: the order is not delivered, or the return window has
not elapsed. With the default 7-day hold the release waits a week; that is why QA25-01 set **0**.
A release that reports errors: stop and read `storage\logs\unilevel_release-*.log`.

### QA25-10 — Each upline's wallet holds exactly its award

| | |
|---|---|
| **What it proves** | A credited award is real money: one withdrawable credit per award, no duplicates, and the buyer's wallet is untouched by commission. |
| **Before** | QA25-09's real release ran. |
| **Where** | phpMyAdmin (`users`, `ewallet_ledger`); Money → E-wallet as each member |
| **Evidence** | `QA25-10-upline-wallets.png`, `QA25-10-ledger.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In phpMyAdmin (`ecowealth_sneat` → **SQL**), run:

   ```sql
   SELECT username, withdrawable_balance
     FROM users
    WHERE username IN ('QA25_C','QA25_B','QA25_A','QA25_Boss')
    ORDER BY username;

   SELECT u.username, l.amount, l.ref_type, l.ref_id
     FROM ewallet_ledger l JOIN users u ON u.id = l.user_id
    WHERE l.ref_type = 'unilevel'
    ORDER BY l.id;

   ```

2. Sign in as `QA25_C` → Money → **E-wallet** and find the **Repeat-purchase bonus** credit.

**You should see**

| Member | Withdrawable | Awards |
|---|---|---|
| `QA25_A` | 50.00 | 1 (level 3) |
| `QA25_B` | 50.00 | 1 (level 2) |
| `QA25_Boss` | 30.00 | 1 (level 4) |
| `QA25_C` | 50.00 | 1 (level 1) |

- Four ledger rows, each `positive | unilevel | ref_id = award id`, and every amount equals its
  award. The note reads *"Repeat-purchase bonus — Coffee (level N) — order EW…"*.
- `admin` has **no** repeat-purchase credit (its decision was skipped).
- The buyer's withdrawable is still **0.00**.

**If it fails** — five credits: a skipped award was paid. A credit with no award: **Fail**. A
balance lower than the award: the wallet was already spent; record it, do not "top it up".

### QA25-11 — The admin repeat-purchase ledger

| | |
|---|---|
| **What it proves** | Finance has one read-only ledger of every repeat-purchase decision, with counters and an export. |
| **Before** | QA25-10 passed. |
| **Where** | Finance → Commissions → **Repeat-purchase bonus** tab (`/admin/commissions?ledger=repeat`) |
| **Evidence** | `QA25-11-admin-repeat.png`, `QA25-11-admin-repeat.csv` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Open the tab. Read the stat cards, then the rows.
2. Press **Export CSV** and open the file.
3. Type `QA25_C` in the search box (labelled **Recipient, buyer, product or order**) and press
   **Filter**; then clear it and set **Status = Skipped**.

**You should see**

- **Total credited ₱180.00**, **Awards 4**, **Skipped 1**, and a **Pending release** of ₱0.00 (the
  release already ran).
- Four credited rows (C, B, A, Boss) and one muted **skipped** row for `admin` marked **not paid** —
  the skipped filter shows that one row.
- Each row links to its order and, when released, to the wallet movement; the page offers **no edit
  or delete**.
- The CSV matches the screen exactly (same four + one rows, same amounts).
- System → **Audit log** has an `unilevel.export` row.
- As a member, `/admin/commissions` returns **403**; signed out, it redirects to `/login`.

**If it fails** — a skipped row counted in "Total credited": **Fail**. A row without its wallet
link: **Fail** — that traceability is the point of the tab.

### QA25-12 — The member's own repeat-purchase statement

| | |
|---|---|
| **What it proves** | A recipient sees exactly their own award, with the source, level and amount — and the buyer's own tab shows nothing for their purchase. |
| **Before** | QA25-10 passed. |
| **Where** | `/earnings?ledger=repeat` as `QA25_C`, then as `QA25_Buyer` |
| **Evidence** | `QA25-12-member-repeat.png`, `QA25-12-buyer-repeat.png`, `QA25-12-member-repeat.csv` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign in as `QA25_C`, open **Membership → Earnings**, then the **Repeat-purchase bonus** tab.
2. Read the cards, the rows and the level breakdown; press **Export CSV**.
3. Sign in as `QA25_Buyer` and open the same tab.

**You should see**

- As `QA25_C`: **Total credited ₱50.00**, **Awards 1**, source `QA25_Buyer`, level **1**, amount
  **₱50.00**, status **credited**; the CSV has that one row.
- As `QA25_Buyer`: **Total credited ₱0.00** and **no award row** for their own order. (Their own
  binary Self Bonus appears on the **Product binary** tab, not here.)
- No other member's name or amount appears anywhere on the page.

**If it fails** — the buyer sees their own purchase as an award: **Fail** (a level-0 rebate exists).
A member sees another member's row: **Fail**, stop and report it as a privacy defect.

### QA25-13 — The Self Bonus lands, and the node balances match the volume

| | |
|---|---|
| **What it proves** | The buyer's 5% is real withdrawable money, and every cached node balance equals the points the order placed. |
| **Before** | QA25-09's binary release ran. |
| **Where** | phpMyAdmin (`binary_nodes`, `ewallet_ledger`); `/wallet` as `QA25_Buyer` |
| **Evidence** | `QA25-13-binary-wallet.png`, `QA25-13-node-balances.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In phpMyAdmin (`ecowealth_sneat` → **SQL**), run:

   ```sql
   SELECT u.username, n.side, n.left_points, n.right_points, n.pairs_paid, n.state
     FROM binary_nodes n JOIN users u ON u.id = n.user_id
    WHERE u.username LIKE 'QA25\_%'
    ORDER BY u.id;

   SELECT amount, ref_type FROM ewallet_ledger
    WHERE user_id = (SELECT id FROM users WHERE username = 'QA25_Buyer')
      AND ref_type = 'binary';

   ```

2. Open `/wallet` as `QA25_Buyer`.

**You should see**

Only order #1 exists at this point, so the six QA25 nodes read:

| Node | side | left_points | right_points | pairs_paid |
|---|---|---|---|---|
| `QA25_Boss` | left | **1000** | 0 | 0 |
| `QA25_A` | left | 0 | **1000** | 0 |
| `QA25_B` | right | 0 | 0 | 0 |
| `QA25_C` | left | 0 | 0 | 0 |
| `QA25_Buyer` | right | **1000** | 0 | 0 |
| `QA25_Buyer2` | right | 0 | 0 | 0 |

All are `state = placed`. `QA25_Boss` still has its right leg empty (that is QA25-17).

- The buyer's ledger has one `binary` credit of **₱50.00**, and `/wallet` shows it as a
  **Product binary** credit, with withdrawable **₱50.00**.

**If it fails** — points on the wrong side: the checkout side or the binary placement is wrong;
record both. A node balance that disagrees with its volume rows: **Fail** — that is cached-state
drift.

### QA25-14 — The binary ledgers, admin and member

| | |
|---|---|
| **What it proves** | The binary stream is visible and traceable to finance and to the member, with the same amounts, and the pages are read-only. |
| **Before** | QA25-13 passed. |
| **Where** | `/admin/commissions?ledger=binary`; `/earnings?ledger=binary` as `QA25_Buyer` |
| **Evidence** | `QA25-14-admin-binary.png`, `QA25-14-member-binary.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open Finance → Commissions → **Product binary** tab. Read the counters and the row;
   follow its order and wallet links.
2. As `QA25_Buyer`, open Membership → Earnings → **Product binary**. Read the Self Bonus card, the
   node card and the statement.

**You should see**

- Admin: a **Self** row for `QA25_Buyer` (the buyer's Self Bonus), **₱50.00**, status **credited**,
  with links to the order and the wallet movement; the cards separate **Self bonus** from **Pair
  bonus** money.
- The member page: the node (side right, 1,000 left points), the **Self Bonus** card explaining 5%
  of the order's points, and one credited ₱50.00 row.
- No edit, delete or "reverse" control on either page.

---

## 12. Part 4 — The manual order and the pair

### QA25-15 — A GCash order pays nothing until it is verified

| | |
|---|---|
| **What it proves** | The second payment path is not money at placement or at proof upload — only at admin verification. |
| **Before** | Part 3 passed. `QA25_Buyer2` has ₱1,500 spend-only, no withdrawable. |
| **Where** | `/checkout` and `/orders/{id}` as `QA25_Buyer2`; Commerce → Orders as admin |
| **Evidence** | `QA25-15-order-pending.png`, `QA25-15-proof-review.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In a fresh Incognito window, sign in as `QA25_Buyer2` / `Demo@1234`.
2. Add **4 × Coffee** to the cart, go to checkout, choose **GCash**, pick **Right** as the binary
   position, fill the address and place the order.
3. On the order page, upload a proof: amount **1200.00**, reference `QA25-GCASH-2`, today's date, a
   small PNG receipt, **Submit proof**.

**You should see**

- Placement: **"Order placed. Your payment is pending — the payment window is shown on the order
  page."**; status **Pending**, total **₱1,200.00**, with a payment deadline.
- Upload: **"Payment proof submitted (attempt 1). We will verify it shortly."**; status
  **Payment review**.
- In phpMyAdmin, for this order: `0` rows in `unilevel_awards`, `binary_processed_at` **NULL**,
  `binary_points` **0**, `0` rows in `binary_awards`. The order is not money yet.

**If it fails** — awards appear while the order is pending or in review: **Fail**, stop. That is a
serious money defect.

### QA25-16 — Verification pays exactly the same ladder

| | |
|---|---|
| **What it proves** | The admin verification is the money event for a manual order, and it pays identically to the e-wallet path. |
| **Before** | QA25-15 passed; the order is in **Payment review** with a pending proof. |
| **Where** | Commerce → Orders → order → **Verify payment** |
| **Evidence** | `QA25-16-verified.png`, `QA25-16-order2-awards.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, Commerce → **Orders** → open order #2 → open the proof.
2. Press **Verify and mark paid**: amount received **1200.00**, reference `QA25-GCASH-2`, note "QA25
   verification", confirm.
3. Re-read the order and the awards with the QA25-06 query, using order #2.

**You should see**

- **"Payment verified — ₱1,200.00 marked paid."**; status **Paid**, `paid_at` set, invoice issued.
- Five decisions again: levels 1–4 pending at **50 / 50 / 50 / 30**, level 5 `admin` **skipped**
  (`company account is not paid`), `unilevel_total` **180.00**.
- Verifying the same order again is refused (it is already paid) and changes nothing.

**If it fails** — the order is paid but has no awards: **Fail** — the verification did not run the
hook. Awards before verification: see QA25-15.

### QA25-17 — The right leg completes one pair for `QA25_Boss`

| | |
|---|---|
| **What it proves** | 1,000 points on each side of one member = one ₱100 pair, written once, for the right person, and credited only after delivery + release. |
| **Before** | QA25-16 passed. Order #1 is delivered and released; order #2 is now paid. |
| **Where** | phpMyAdmin (`binary_awards`, `binary_nodes`); Commerce → Orders (deliver #2); terminal |
| **Evidence** | `QA25-17-pair-row.png`, `QA25-17-node-after-pair.png`, `QA25-17-pair-release.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Read the pair immediately after verification:

   ```sql
   SELECT a.kind, u.username AS recipient, a.amount, a.status, a.pair_count, a.hold_days
     FROM binary_awards a JOIN users u ON u.id = a.user_id
    WHERE a.order_id = <order #2> ORDER BY a.id;
   ```

2. Read `QA25_Boss`'s node:

   ```sql
   SELECT n.left_points, n.right_points, n.pairs_paid
     FROM binary_nodes n JOIN users u ON u.id = n.user_id
    WHERE u.username = 'QA25_Boss';
   ```

3. Deliver order #2 exactly as in QA25-09 (pack → ready → ship with tracking `QA252` → deliver),
   then run:

   ```bat
   php bin\unilevel_release.php --stream=all --limit=200
   ```

4. Re-read `QA25_Boss`'s wallet and binary awards.

**You should see**

- Order #2 has **two** binary awards: the buyer's `self` **₱50.00 pending** and a `pair` for
  `QA25_Boss` **₱100.00 pending**, `pair_count = 1`.
- `QA25_Boss`'s node: **left_points 0, right_points 0, pairs_paid 1** — one 1,000-point block was
  consumed from each side.
- The release credits both: `released: 1 order(s) · 4 award(s) · ₱180.00` and
  `released: 1 order(s) · 2 award(s) · ₱150.00` (₱50 self + ₱100 pair).
- `QA25_Boss`'s withdrawable is **₱160.00** = ₱30 (order #1, level 4) + ₱30 (order #2, level 4) +
  ₱100 (pair). `QA25_Buyer2`'s withdrawable is **₱50.00** (own Self Bonus).
- Final node state: `QA25_A` right 1,000; `QA25_B` right 1,000; `QA25_Buyer` left 1,000;
  `QA25_Buyer2` right 1,000; `Boss` 0/0 with 1 pair paid.

**If it fails** — two pair rows for the same order: **Fail** (settled twice). A pair for the wrong
member: the binary sides or the buyer's checkout position are wrong. `pairs_paid` 0 while a pair
award exists: **Fail** — consumed points were not stamped.

### QA25-18 — A replay pays nothing twice

| | |
|---|---|
| **What it proves** | The order stamp is the idempotency anchor: re-running the payment hook, or re-verifying, cannot create a second award or a second wallet credit. |
| **Before** | QA25-17 passed. |
| **Where** | phpMyAdmin; optional terminal replay |
| **Evidence** | `QA25-18-replay.txt`, `QA25-18-counts.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open order #2 and try **Verify and mark paid** again. Read the refusal.
2. Re-run the hook directly for order #1 from `C:\laragon\www\ecowealth\system`:

   ```bat
   php -r "require 'app/config.php'; require 'app/helpers.php'; require 'app/autoload.php'; require 'app/database.php'; var_export((new UnilevelService())->onOrderPaid(1));"
   ```

   (Replace `1` with your order #1 id. The output contains `'replayed' => true`.)
3. Re-count the awards for orders #1 and #2.

**You should see**

- The second verification is refused because the order is already paid; no row changes.
- The hook prints `replayed => true`, `processed => false`.
- Order #1 still has **5** repeat-purchase rows (4 credited + 1 skipped) and **1** binary award;
  order #2 still has **5** and **2**. No ledger row was added.

**If it fails** — a fresh award or credit after a replay: **Fail**, highest severity. The system is
not idempotent and could double-pay a retried job.

---

## 13. Part 5 — Refusals and switches

Before this part, top up the two buyers so the control orders can be paid:

- `QA25_Buyer`: **+₱2,000.00** (wallet becomes ₱2,300 after order #1).
- `QA25_Buyer2`: **+₱1,000.00** (wallet becomes ₱1,300 after order #2).

### QA25-19 — A suspended upline is skipped, and the level is not compressed

| | |
|---|---|
| **What it proves** | An ineligible recipient consumes its level: the row is recorded as skipped, no money moves, and the next upline keeps its own level and rate. |
| **Before** | QA25-17 passed; buyers funded as above. |
| **Where** | Commerce → Members → `QA25_B` → **Suspend member**; checkout as `QA25_Buyer`; SQL |
| **Evidence** | `QA25-19-suspended.png`, `QA25-19-skipped-row.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, Commerce → **Members** → open `QA25_B` → **Suspend member** → confirm.
2. As `QA25_Buyer`, add **2 × Coffee** (₱600), check out with the **e-wallet**, side **Left**.
3. Read the awards for that order with the QA25-06 query.
4. As admin, open `QA25_B` again → **Restore member**.

**You should see**

| level | recipient | amount | status | reason |
|---|---|---|---|---|
| 1 | `QA25_C` | 25.00 | pending | *empty* |
| 2 | `QA25_B` | 25.00 | **skipped** | `recipient account is disabled` |
| 3 | `QA25_A` | 25.00 | pending | *empty* |
| 4 | `QA25_Boss` | 15.00 | pending | *empty* |
| 5 | `admin` | 15.00 | skipped | `company account is not paid` |

The base is **₱500** (2 × P.V. 250), so 5% = ₱25 and 3% = ₱15. `QA25_B`'s ₱25 is **not** paid to
`QA25_A`; A stays at level 3 and ₱25. The suspend message reads *"@QA25_B is now suspended — the
account cannot sign in."*; restore reads *"@QA25_B is now active."*

> Do **not** deliver or release this order yet. QA25-22 cancels it before release, which is exactly
> what proves that a pending award can be cancelled without moving money.

**If it fails** — A is paid ₱50 (level 2 + 3): compression happened. The skipped row is missing:
the decision was silently dropped. `QA25_B` is paid: **Fail**, stop.

### QA25-20 — With both streams off, a paid order is stamped with zeros

| | |
|---|---|
| **What it proves** | A kill switch is a decision, not a gap: the order is accepted and stamped `processed` with zero money, and turning the switch back on cannot pay it later. |
| **Before** | QA25-19 passed. |
| **Where** | Settings → Commissions; checkout as `QA25_Buyer`; SQL |
| **Evidence** | `QA25-20-switches-off.png`, `QA25-20-zero-order.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, Settings → Commissions: switch **Repeat-purchase bonus enabled** off and **Product
   binary enabled** off. Save.
2. As `QA25_Buyer`, buy **1 × Coffee** (₱300) with the e-wallet, side **Left**.
3. Read the order's `unilevel_processed_at`, `unilevel_total`, `binary_processed_at`,
   `binary_points` and count its award rows.
4. Switch both streams **back on** and save.

**You should see**

- The order is **Paid**, total ₱300.00.
- `unilevel_processed_at` and `binary_processed_at` are set; `unilevel_total` **0.00**;
  `binary_points` **0**; `binary_self_total`/`binary_total` **0.00**.
- **0** rows in `unilevel_awards`, **0** in `binary_awards`, **0** in `binary_volume`, and **0**
  ledger rows of either type for that order.
- Re-running the release job later pays nothing (the stamps block it).

**If it fails** — the order is paid but `processed_at` is NULL (a gap that a later job might pay):
**Fail**. Any award while a stream is off: **Fail**.

### QA25-21 — A product with its switches off pays nothing

| | |
|---|---|
| **What it proves** | The per-product switch is independent of the global one: a paid line can be worth zero without blocking checkout. |
| **Before** | QA25-20 passed; both global switches are back on. |
| **Where** | Products → Coffee; checkout as `QA25_Buyer2`; SQL |
| **Evidence** | `QA25-21-product-off.png`, `QA25-21-zero-line.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open Coffee and switch **Pay this product's ladder** off and **Pay product binary
   points** off. Save.
2. As `QA25_Buyer2`, buy **1 × Coffee** (₱300) with the e-wallet, side **Right**.
3. Read the order's stamps and award counts.
4. Turn both product switches back on and save.

**You should see**

- **Paid**, `unilevel_processed_at` set, `unilevel_total` **0.00**, `binary_points` 0, both binary
  totals 0.00.
- **0** award rows and **0** volume rows for this order.
- Coffee's row on the product list no longer carries the **Repeat bonus** / **Product binary**
  badges while the switches are off.

**If it fails** — the checkout errors instead of accepting a zero-value line: **Fail**. Awards
appear anyway: **Fail**.

### QA25-22 — Cancelling before release cancels the pending awards, and moves no money

| | |
|---|---|
| **What it proves** | The escrow's cancellation rule: pending money is cancelled (not credited), credited money is untouched at this point, and the wallet does not move. |
| **Before** | QA25-19's order is paid and **not delivered**; its five decisions (3 pending + 2 skipped) still stand. |
| **Where** | Settings → Shop (`Enable Cancel of Order`); Commerce → Orders → the QA25-19 order → **Cancel** |
| **Evidence** | `QA25-22-cancel-switch.png`, `QA25-22-cancelled-awards.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, Settings → **Shop** → switch **Enable Cancel of Order** on. Save.
2. Commerce → **Orders** → open the QA25-19 order → **Cancel order**, reason *Cancelled by the
   administrator*, confirm.
3. Read the order status and its awards; read `QA25_B`'s withdrawable balance.
4. Switch **Enable Cancel of Order** back to its previous value and save.

**You should see**

- **"Order cancelled."** and status **Cancelled**; stock released.
- The three pending awards become **cancelled**; the two skipped rows stay **skipped**. Nothing is
  credited and no reversal ledger row exists.
- `QA25_B`'s withdrawable is **unchanged** (₱100.00 from orders #1 and #2 — it was skipped here,
  not paid and clawed back).
- The reconciler's **cancelled awards** count rises by three, worth ₱65.00.

> **Why this is different from a reversal.** Nothing was ever credited, so there is nothing to claw
> back. Reversal is for a credited award whose order is cancelled later; this run never needs it.

**If it fails** — the order refuses to cancel with *"Order cancellation is turned off…"*: step 1
did not save. A cancelled award with a ledger row: **Fail** — money moved.

---

## 14. Part 6 — Reconcile, tie out, restore and clean up

### QA25-23 — Everything reconciles

| | |
|---|---|
| **What it proves** | Every credited award in both streams has exactly one wallet movement, no skipped award has one, and the order totals agree. |
| **Before** | Parts 2–5 are complete. |
| **Where** | Terminal in `C:\laragon\www\ecowealth\system` |
| **Evidence** | `QA25-23-reconcile.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Run:

   ```bat
   php bin\wallet_reconcile.php
   echo Exit: %ERRORLEVEL%
   ```

2. Save the full output.

**You should see**

- **Exit: 0** and a **Repeat-purchase** section:
  - `credited awards: 8 (360.00)`
  - `pending awards: 0 (0.00)`
  - `cancelled awards: 3 (65.00)`
  - `skipped awards: 4`
  - `unilevel ledger: 360.00`
  - `OK — every award has its wallet movement, every reversal its debit, and the order totals agree.`
- A **Product binary** section:
  - `credited awards: 3 (200.00)`
  - `cancelled awards: 1 (25.00)`
  - `binary ledger: 200.00`
  - the same OK line.
- Every other section (Wallets, Commissions, Transfers, Payouts, Royalty, Team Harvest, General
  Harvest) clean, and the final line `All good — wallets, commissions, transfers, payouts,
  repeat-purchase, royalty, team-harvest, general-harvest and product-binary awards reconcile.`
- The Team Harvest and General Harvest warnings about an unseeded queue / unarmed board are
  **normal** for this run: you did not use those streams.

**If it fails** — any non-zero exit, or a "mismatch" line: stop. Do not clean up until finance has
seen the output; the message names the award or ledger id.

### QA25-24 — The totals tie out; restore the settings and remove the QA data

| | |
|---|---|
| **What it proves** | Awarded money equals wallet money in both streams, the whole run is removable in a safe order, and the system is left as you found it. |
| **Before** | QA25-23 exited 0. |
| **Where** | phpMyAdmin; Settings → Commissions; Products → Coffee |
| **Evidence** | `QA25-24-totals.png`, `QA25-24-cleanup.png`, `QA25-24-restored-settings.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Tie out both streams:

   ```sql
   SELECT
     (SELECT COALESCE(SUM(amount),0) FROM unilevel_awards WHERE status = 'credited') AS repeat_awarded,
     (SELECT COALESCE(SUM(amount),0) FROM ewallet_ledger WHERE ref_type = 'unilevel') AS repeat_ledger,
     (SELECT COALESCE(SUM(amount),0) FROM binary_awards WHERE status = 'credited')   AS binary_awarded,
     (SELECT COALESCE(SUM(amount),0) FROM ewallet_ledger WHERE ref_type = 'binary')   AS binary_ledger;
   ```

   Expected: **360.00 | 360.00 | 200.00 | 200.00**.

2. Restore every control you recorded in QA25-01 (and Coffee's P.V., switches, base and ladder, if
   they were not already what QA25-02 set).
3. Remove the QA data **in this order**. `binary_volume` references its award with
   `ON DELETE RESTRICT`, so deleting orders or awards first fails with MySQL error 1451:

   ```sql
   DELETE FROM binary_volume
    WHERE order_id IN (SELECT id FROM shop_orders
                        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%'));

   DELETE FROM binary_awards
    WHERE order_id IN (SELECT id FROM shop_orders
                        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%'));

   DELETE FROM unilevel_awards
    WHERE order_id IN (SELECT id FROM shop_orders
                        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%'));

   DELETE FROM ewallet_ledger
    WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%');

   DELETE FROM shop_order_items
    WHERE order_id IN (SELECT id FROM shop_orders
                        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%'));

   DELETE FROM shop_order_events
    WHERE order_id IN (SELECT id FROM shop_orders
                        WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%'));

   DELETE FROM shop_orders
    WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%');

   DELETE FROM binary_nodes
    WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\_%');

   DELETE FROM users WHERE username LIKE 'QA25\_%';
   ```

4. Confirm the cleanup:

   ```sql
   SELECT COUNT(*) AS users_left FROM users WHERE username LIKE 'QA25\_%';
   SELECT COUNT(*) AS nodes_left FROM binary_nodes
    WHERE user_id NOT IN (SELECT id FROM users);
   ```

   Expected: **0** and **0**.

**You should see**

- The tie-out row `360.00 | 360.00 | 200.00 | 200.00`.
- Every statement runs without an error; MySQL 1451 does **not** appear.
- After the settings restore, the Commissions page shows your original values and Coffee is back to
  its original P.V./switches/ladder.
- `wallet_reconcile.php` still exits 0 after the cleanup (no orphan awards).

**If it fails** — error 1451 on `binary_volume`: you skipped the first delete or ran them out of
order. Restore the backup only if you also need the original settings back; otherwise re-run the
block from the top. A non-zero `users_left`: a username did not match the prefix (for example, it
was created as `qa25…`); find it with `SELECT username FROM users WHERE username LIKE '%QA25%'`.

---

## 15. The totals to expect

### 15.1 Per order

| # | Buyer | Method | Total | Repeat credited | Repeat skipped | Binary Self | Pair | Released? |
|---|---|---|---|---|---|---|---|---|
| 1 | `QA25_Buyer` | E-wallet | ₱1,200 | **₱180** (50/50/50/30) | ₱30 (company) | ₱50 | — | yes |
| 2 | `QA25_Buyer2` | GCash → verified | ₱1,200 | **₱180** (50/50/50/30) | ₱30 (company) | ₱50 | **₱100** (Boss) | yes |
| 3 | `QA25_Buyer` | E-wallet | ₱600 | **₱0** — ₱65 cancelled | ₱40 (B 25 + company 15) | ₱25 cancelled | — | no (cancelled) |
| 4 | `QA25_Buyer` | E-wallet | ₱300 | ₱0 — switches off | — | ₱0 | — | stamped only |
| 5 | `QA25_Buyer2` | E-wallet | ₱300 | ₱0 — product off | — | ₱0 | — | stamped only |

Order 3's three credit-bearing decisions are **₱25 (C) + ₱25 (A) + ₱15 (Boss) = ₱65**, recorded as
`cancelled`; its two skipped decisions are `QA25_B` (₱25, account disabled) and `admin` (₱15,
company). None of it reached a wallet.

### 15.2 After the run

| Member | Withdrawable | Where it came from |
|---|---|---|
| `QA25_C` | **₱100.00** | ₱50 (order 1, level 1) + ₱50 (order 2, level 1) |
| `QA25_B` | **₱100.00** | ₱50 + ₱50 (levels 2); order 3 skipped |
| `QA25_A` | **₱100.00** | ₱50 + ₱50 (levels 3) |
| `QA25_Boss` | **₱160.00** | ₱30 + ₱30 (levels 4) + ₱100 pair |
| `QA25_Buyer` | **₱50.00** | own Self Bonus (order 1) |
| `QA25_Buyer2` | **₱50.00** | own Self Bonus (order 2) |
| `admin` | **₱0.00** | never paid; volume only |

**Stream totals**

| Stream | Credited | Cancelled | Skipped decisions | Ledger |
|---|---|---|---|---|
| Repeat-purchase | **₱360.00** (8 awards) | ₱65.00 (3) | 4 | **₱360.00** |
| Product binary | **₱200.00** (3 awards) | ₱25.00 (1) | 0 | **₱200.00** |

### 15.3 The arithmetic, once

- **Base:** 4 × 250 P.V. = ₱1,000. 2 × 250 = ₱500. 1 × 250 = ₱250.
- **Repeat ladder:** 5% of 1,000 = 50; 3% = 30. On ₱500: 25 and 15. On ₱250: 12.50 and 7.50.
- **Self Bonus:** 5% of the points. 1,000 points → ₱50. 500 → ₱25. 250 → ₱12.50.
- **Pair:** 1,000 points matched × 10% = **₱100**, once per pair.

### 15.4 What this run did **not** cover

- A pair at the **buyer's own** node (it needs team volume on the opposite side of the buyer). The
  engine's own QA (Phase 22D) covers it.
- More than one pair in a day, the daily pair cap, and pair carry-over.
- A credited **reversal** (cancelling after release) and a shortfall. Phase 17C/22D cover those.
- The monthly Active rule. It only has a flag for accounts that existed at a month close, and a
  missing flag means Active, so the six new QA25 accounts qualify. An account that existed at a
  close with under ₱500 of personal purchases would be skipped (*recipient is not Active for …*);
  this run did not exercise that.
- Royalty (a monthly close) and package commissions (Phase 24).

### 15.5 The differences found while writing this document

These were corrected in v1 from the executed run; they are not app defects.

1. **The checkout preview says ₱290, not ₱180.** The preview multiplies the base by the **whole**
   ladder (29%), including levels that may be skipped. The ledger, not the preview, is the paid
   figure. QA25-04 explains this.
2. **`QA25_Boss`'s wallet is ₱160, not ₱130.** The second order pays it another ₱30 at level 4;
   the pair adds ₱100. An early draft forgot the second ladder payment.
3. **Cancelling needs the Shop switch.** `Enable Cancel of Order` ships **off**. QA25-22 now turns it
   on and restores it, and the failure message is quoted so a tester recognises it.

---

## 16. SQL appendix

Paste these into phpMyAdmin → `ecowealth_sneat` → **SQL**.

### 16.1 Look up an id by username

```sql
SELECT id, username, member_status, is_active, sponsor_id,
       ewallet_balance, withdrawable_balance
  FROM users WHERE username = 'QA25_Buyer';
```

### 16.2 The last order of one member

```sql
SELECT id, order_no, status, total, paid_at, delivered_at,
       unilevel_processed_at, unilevel_total,
       binary_position, binary_processed_at, binary_points,
       binary_self_total, binary_pair_total, binary_total
  FROM shop_orders
 WHERE user_id = (SELECT id FROM users WHERE username = 'QA25_Buyer')
 ORDER BY id DESC LIMIT 1;
```

### 16.3 Every repeat-purchase decision of one order

```sql
SELECT a.level, u.username AS recipient, a.amount, a.status, a.reason,
       a.hold_days, a.ledger_id, a.credited_at
  FROM unilevel_awards a JOIN users u ON u.id = a.user_id
 WHERE a.order_id = <order id>
 ORDER BY a.level;
```

### 16.4 One member's repeat-purchase total

```sql
SELECT u.username,
       SUM(a.status = 'credited') AS credited_rows,
       COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount END), 0) AS credited_total,
       SUM(a.status = 'pending')  AS pending_rows,
       SUM(a.status = 'skipped')  AS skipped_rows
  FROM unilevel_awards a JOIN users u ON u.id = a.user_id
 WHERE u.username = 'QA25_C'
 GROUP BY u.id;
```

### 16.5 The binary awards and volume of one order

```sql
SELECT a.kind, u.username AS recipient, a.amount, a.status, a.pair_count,
       a.hold_days, a.ledger_id
  FROM binary_awards a JOIN users u ON u.id = a.user_id
 WHERE a.order_id = <order id> ORDER BY a.id;

SELECT u.username AS node, v.side, v.points, v.kind
  FROM binary_volume v JOIN users u ON u.id = v.node_user_id
 WHERE v.order_id = <order id> ORDER BY v.id;
```

### 16.6 The node of one member

```sql
SELECT u.username, n.side, up.username AS upline, n.left_points, n.right_points,
       n.pairs_paid, n.state
  FROM binary_nodes n
  JOIN users u ON u.id = n.user_id
  LEFT JOIN users up ON up.id = n.upline_id
 WHERE u.username LIKE 'QA25\_%'
 ORDER BY u.id;
```

### 16.7 A wallet statement, with references

```sql
SELECT l.id, l.type, l.amount, l.ref_type, l.ref_id, l.note, l.created_at
  FROM ewallet_ledger l
 WHERE l.user_id = (SELECT id FROM users WHERE username = 'QA25_Boss')
 ORDER BY l.id;
```

### 16.8 The settings that drive both streams

```sql
SELECT key_name, value FROM settings
 WHERE key_name IN ('unilevel_enabled','unilevel_max_levels','unilevel_hold_days',
                    'unilevel_requires_active_upline','unilevel_active_threshold',
                    'unilevel_release_enabled','unilevel_reversal_enabled',
                    'binary_enabled','binary_self_pct','binary_pair_points',
                    'binary_pair_pct','binary_daily_pair_cap','binary_hold_days',
                    'binary_requires_active','binary_release_enabled',
                    'binary_reversal_enabled','shop_cancel_enabled')
 ORDER BY key_name;
```

---

## 17. When something looks wrong

Read the exact message first; most of them tell you what to fix.

| What you see | What it means | What to do |
|---|---|---|
| *"Your e-wallet balance (₱300.00) is not enough for this order (₱600.00). Choose manual payment or top up first."* | The buyer was not funded for this order. | Top up in Finance → Wallets (QA25-03's step). |
| *"That upline is outside your subtree."* | You registered a second member in the same window/session as the first. | Use a fresh Incognito window per registration. |
| *"The left slot under member #N is already taken."* | The chosen binary slot is occupied. | Allowed only for `QA25_Boss`; pick the free side the form offers. For the other five, revisit §7.2. |
| *"Order cancellation is turned off. Turn on “Enable Cancel of Order” under Settings → Shop before cancelling an order."* | `shop_cancel_enabled` is off (its default). | Turn it on for QA25-22, then restore it. |
| *"This order is “Delivered” now, so it cannot be cancelled."* | You delivered the order before cancelling it. | That order can no longer test the pre-release rule; note the deviation and use another pending order. |
| Dry run says `pending: 0` | The order is not delivered, or `hold_days` is above 0. | Deliver it; confirm **Release delay after delivery** is 0 on both cards. |
| Release says `released: 0` on a second run | Correct — it is idempotent. | Nothing to fix. |
| A `pending` award with a `ledger_id` | The escrow was bypassed. | **Defect.** Save the row and the order id; stop the part. |
| A skipped row that still credits a wallet | The skip was not enforced. | **Defect**, highest severity. The reconciler will fail; keep its output. |
| `SQLSTATE[23000] … 1451 … binary_volume → binary_awards` during cleanup | You deleted awards or orders before `binary_volume`. | Run QA25-24's block from the first statement. |
| The Commissions page shows a value you did not set | A concurrent tab saved an older form. | Reload, re-apply QA25-01, and re-check. |

**How to report a defect**

1. Check ID, the exact message, the order id and the member.
2. What you did, what you saw, what this document said you should see.
3. The evidence file names.
4. Severity: **money** (wrong amount or double payment), **gate** (paid when it should not be),
   **visibility** (screen disagrees with the ledger), **cosmetic**.

---

## 18. Defect log

| # | Check | What happened | Expected | Severity | Status |
|---|---|---|---|---|---|
| | | | | | |

*(No app defects were found in the executed run. The three document corrections are in §15.5.)*

---

## 19. Sign-off

| Item | Value |
|---|---|
| Tester | |
| Date | |
| Environment | Local development copy, `ecowealth_sneat`, app at `http://localhost/ecowealth/system/` |
| Checks passed | ____ / 24 |
| Defects found | |
| Decision | ☐ Approved ☐ Approved with notes ☐ Blocked |
| Evidence folder | `docs/qa/evidence/phase-25/` |
| Notes | |

---

## 20. Check index

| ID | Title | Part |
|---|---|---|
| QA25-01 | Record the defaults, then turn both streams on | 9 |
| QA25-02 | Give Coffee a P.V., the reference ladder and binary points | 9 |
| QA25-03 | Build the six-account tree and fund the buyers | 9 |
| QA25-04 | The cart and checkout quote what the order will pay | 10 |
| QA25-05 | The e-wallet order is paid once, and the wallet is debited once | 10 |
| QA25-06 | The ladder is written as five decisions, four of them pending | 10 |
| QA25-07 | The buyer is never a recipient of their own purchase | 10 |
| QA25-08 | Points, the Self Bonus and the volume roll-up | 10 |
| QA25-09 | Delivery makes the awards due; the release job credits them | 11 |
| QA25-10 | Each upline's wallet holds exactly its award | 11 |
| QA25-11 | The admin repeat-purchase ledger | 11 |
| QA25-12 | The member's own repeat-purchase statement | 11 |
| QA25-13 | The Self Bonus lands, and the node balances match the volume | 11 |
| QA25-14 | The binary ledgers, admin and member | 11 |
| QA25-15 | A GCash order pays nothing until it is verified | 12 |
| QA25-16 | Verification pays exactly the same ladder | 12 |
| QA25-17 | The right leg completes one pair for `QA25_Boss` | 12 |
| QA25-18 | A replay pays nothing twice | 12 |
| QA25-19 | A suspended upline is skipped, and the level is not compressed | 13 |
| QA25-20 | With both streams off, a paid order is stamped with zeros | 13 |
| QA25-21 | A product with its switches off pays nothing | 13 |
| QA25-22 | Cancelling before release cancels the pending awards, and moves no money | 13 |
| QA25-23 | Everything reconciles | 14 |
| QA25-24 | The totals tie out; restore the settings and remove the QA data | 14 |
