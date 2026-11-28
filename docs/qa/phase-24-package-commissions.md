# EcoWealth — Phase 24 QA: Package commissions (a beginner's walkthrough)

This is a **manual test script** for one part of EcoWealth: the money a member is paid when a
package is activated. You do not need to know how the system is built. You need a browser, the app
running on your own computer, and the patience to follow the steps in order.

You will create sixteen test accounts — thirteen in the main tree plus the three zero-point controls of
QA24-18 — activate packages one at a time, and compare what the screens and the database say with what
this document says they should say. Where they disagree, you record a defect. You never change code and
you never "fix" data.

| | |
|---|---|
| **Phase** | 24 — package commissions: registration, activation, direct, indirect, binary package, team harvest and general harvest |
| **Document version** | **v3.2** — v3.1's amounts and states were reproduced by an automated HTTP run against a fresh schema (`ecowealth_sneat_qa24`); v3.2 changes only the QA24-29 cleanup, which remains a manual check (see the box below and `phase-24-run-report.md`) |
| **Who this is for** | A tester with no prior knowledge of EcoWealth |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Admin login** | `admin` / `Admin@123` |
| **Your test accounts** | all prefixed `QA24`, password `Demo@1234`, created by you during the run |
| **Checks** | 29 (QA24-01 … QA24-29) |
| **Time** | 3–4 hours, including setup. Do not split it across days: the walkthrough depends on the order. |
| **Evidence folder** | `docs/qa/evidence/phase-24/` |

> **If you tested v1 or v2 of this document, discard those results.** v3 was corrected by executing
> the walkthrough against a fresh schema; the run report and raw log are in
> `docs/qa/evidence/phase-24/run/`. Items 1–4 were already v2 fixes; **5–9 are new in v3** and were
> each confirmed by the run — except item 8's replacement cleanup, which is the workaround for the
> constraint that stopped the original delete and was not itself executed end to end. **10 is new in
> v3.2** and finishes that cleanup:
>
> 1. The settings and Package A's binary points are now switched on **before** the first package
>    activation. The system stamps points **when the member activates**; switching the feature on
>    afterwards cannot add points to activations that already happened.
> 2. `QA24_Sponsor` is now created explicitly. v1 used it everywhere but never said how to make it.
> 3. Binary placement is chosen **on the registration form**, not later from an admin screen. The
>    admin screen can only *move* a member who already has a node.
> 4. Three numbers were wrong: the indirect total (it is ₱150, not ₱250), one member's unilevel
>    total (₱650, not ₱600) and the count of indirect rows (5, not 6). The corrected figures are in
>    [Part 16](#16-the-totals-to-expect).
> 5. **Both harvest baselines are prepared in Part 1, before anyone registers.** The Team Harvest
>    queue is seeded there, and the General Harvest board is armed there (with its stream still off).
>    Seeding or arming later freezes every already-complete level as `baseline`, which is never paid
>    (D-06/D-07) — v2 armed at N = 6 and could never see a payout.
> 6. **Team Harvest pays the Sponsor only.** The count runs over sponsor-lineage descendants, not the
>    binary tree; `QA24_Member_A` never reaches three and gets nothing. v2 wrongly expected ₱2,000.
> 7. **D pays four levels, with no skip**: C ₱500, B ₱100, A ₱50, Sponsor ₱40. The level-4 skip of
>    `admin` happens on **C's** activation, not D's — and the level-4 amount is ₱40, not ₱50.
> 8. **Cleanup cannot delete members after a package-binary run.** `binary_volume.award_id` is
>    `ON DELETE RESTRICT`; v2's plain `DELETE FROM users` fails with MySQL 1451. QA24-29 now clears
>    `binary_volume` first, and no longer assumes codes are prefixed `QA24`.
> 9. The three zero-point members need **free binary slots** (upline `QA24_Team_1a/2a/3a`); the
>    sponsor's own left slot is taken, and the form refuses with *"The left slot under member #3 is
>    already taken."*
> 10. **QA24-29 ends with a business reset.** Deleting the `QA24…` users cascades the General Harvest
>     awards away, but `general_harvest_board` is a singleton and stays armed, so the next
>     `wallet_reconcile.php` exits 1 (`board_bounds`). QA24-29 step 5 now runs `bin\db_reset.php`,
>     which returns the board to unarmed; the deletes alone are not a finish.

---

## 1. What EcoWealth is, in one minute

EcoWealth is a small online shop with a member network attached.

- **A member** is an account. Every member has a **sponsor** — the member who introduced them. That
  chain of sponsors is called the **sponsor tree**.
- A member can buy a **package** (Package A costs ₱4,498). Buying or redeeming a code for a package
  **activates** the membership. An activated member can earn commission; a free "standard" member
  cannot.
- Every member has two balances in one **e-wallet**: *withdrawable* money (commission you can take
  out) and *spend-only* money (top-ups you can only spend in the shop). Commission always lands in
  the **withdrawable** bucket.
- **Activation is the trigger.** When a package activation is approved, the system pays several
  people in the same instant, writes one row for each payment, credits each wallet, and writes one
  line in an accounting table (`ewallet_ledger`). Nothing is paid later and nothing is paid twice.

There is a second tree, unrelated to sponsors: the **binary tree**. Each member sits on the **left**
or **right** side of one *binary upline*, and each side holds exactly one member. When both sides of
a member carry enough points, that member earns a **pair** award. A member's binary upline is chosen
**when the member registers** and can be a different person from their sponsor.

### The five money streams you are testing

| Stream | Plain English | Paid to |
|---|---|---|
| **Direct referral** | The person who directly introduced the new member gets a fixed bonus (Package A: ₱500). | The sponsor (level 1) |
| **Indirect referral** | Members further up the sponsor chain also get a smaller amount each (Package A: level 2 ₱100, level 3 ₱50). | Uplines at levels 2–4 in this run |
| **Binary package** | When a member's left and right legs each hold 1,000 package points, they earn one pair = ₱100. | The owner of the pair |
| **Team Harvest** | A member who recruits enough direct members joins a queue. When enough qualified members appear *after* them, the first matrix level pays ₱1,000. | The queued member |
| **General Harvest** | Qualified members each hold a slot on a shared 3-slot-wide board. When a slot's block fills, its holder is paid ₱2,000. | The slot's holder |

Every award listed in this document must produce exactly **one award row, one wallet credit and one
ledger row**, must pay a member only once per level, and must never pay a member who is not eligible.
The same stream can pay more than one member in a run (General Harvest pays the Sponsor at position #1 and `QA24_Member_A` at #2),
but each member's level pays once, ever. That is what this document checks.

---

## 2. What this walkthrough proves

By the end you will have shown that:

1. A package activation pays the direct and indirect referral ladder at the right amounts, and
   **skips** an upline who has no package instead of paying them.
2. A registration code can only be used once, and an invalid or expired code creates nothing.
3. A manual payment proof can be reviewed, approved once, and rejected with a reason.
4. A binary pair is paid once, credited to the withdrawable wallet, and visible in the binary ledger.
5. Team Harvest and General Harvest qualify, pay once, and appear in the member and admin views.
6. Every credited award has a matching ledger line, every skipped award has none, and the wallet
   reconciler agrees with all of it.
7. Every admin screen is read-only and guarded (a member gets **403**, a guest is redirected).

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
  and sign in as `QA24…` members.

When a step says *"as `QA24_Sponsor`"*, sign that account in inside Window B. When it says *"as
admin"*, use Window A. Never sign in as two members in the same window: the session replaces itself
and you will see the wrong dashboard.

**Every registration signs that window in as the new member.** Sign out (or open a fresh Incognito
window) before the next registration: a signed-in member may only place a new member inside their own
binary subtree or their sponsor's, so staying signed in as the previous account is refused with
*That upline is outside your subtree.* — that is the form protecting the tree, not a defect.

### 3.3 Create the evidence folder

All screenshots and terminal output go in:

```text
C:\laragon\www\ecowealth\docs\qa\evidence\phase-24\
```

Create it now if it does not exist. Name each file exactly as the check's **Evidence** row says
(for example `QA24-16-binary-wallet.png`). A check without its evidence file is not signed off.

### 3.4 Make a backup first (recommended)

The walkthrough creates and deletes data. A backup means a mistake costs minutes, not a day.

1. Open `http://localhost/phpmyadmin/`.
2. Click the database **`ecowealth_sneat`** in the left list.
3. Open the **Export** tab → **Export** (SQL format, default settings).
4. Save the file somewhere outside the project, e.g. `C:\laragon\www\ecowealth-backups\phase-24\`.

If you are comfortable with a terminal, this does the same thing:

```powershell
cd C:\laragon\www\ecowealth
mysqldump -u root -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-24\pre-phase-24.sql
```

### 3.5 Know how to read the database

Several checks ask you to confirm what the system wrote. You do not need to know SQL. You need to
copy, paste and compare.

1. `http://localhost/phpmyadmin/` → click **`ecowealth_sneat`** → **SQL** tab.
2. Paste the query from the check and press **Go**.
3. Compare the result with the table in the check.

The queries in this document all start by looking up an account id from its username, so you can
paste them exactly as written. **Never run a query that changes data** (`UPDATE`, `DELETE`, `INSERT`)
except the `DELETE` statements and the `bin\db_reset.php` step in QA24-29 (read both before you run
them).

### 3.6 What you need on screen

- The app in two windows, as above.
- A text note (Notepad is fine) for the **before** values you record in QA24-07 and the codes you
  issue in QA24-02. Codes look like `ABCD-EFGH-JKLM`.
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

The whole walkthrough depends on the accounts being registered **in the order given**. Each
registration adds points, queue positions or commission rows that later checks expect. If you skip a
step or register an extra member, finish the run and note it in the defect log rather than trying to
delete your way back. Cleanup (QA24-29) removes every `QA24…` account at the end.

---

## 4. The words in this document

Look here whenever a word is unfamiliar. You are not expected to memorise it.

| Word | What it means here |
|---|---|
| **Activation** | The moment a package is attached to a member. It triggers every commission. |
| **Approve / reject** | The admin decision on a manual payment proof. Approve activates the member; reject leaves them pending. |
| **Binary node** | A member's position in the binary tree: one upline, one side (`left`/`right`). |
| **Binary upline** | The member whose left or right slot you occupy in the **binary** tree. Not necessarily your sponsor. |
| **Code** | A one-time registration code for a package, sold offline (`XXXX-XXXX-XXXX`). |
| **Commission row** | One `commissions` record: recipient, source member, type, level, amount, status. |
| **Credited** | Money actually paid into the wallet. |
| **Skipped** | A payment the rules refused (e.g. recipient has no package). It is recorded, but no money moves and there is no ledger row. Consumes the level; it is not paid to anybody else. |
| **Direct referral** | Level 1: the sponsor's fixed bonus. |
| **Indirect referral** | Levels 2+: the smaller amounts paid further up the sponsor chain. |
| **E-wallet** | The member's two balances. Commission credits the **withdrawable** side. |
| **Ledger** (`ewallet_ledger`) | The accounting record of every wallet movement. Every credited award has exactly one matching line. |
| **Level** | Distance up the sponsor chain: sponsor = level 1, their sponsor = level 2, and so on. |
| **Package** | The membership product (Package A here) that carries the bonuses, the ladder and the binary points. |
| **Pair** | Two legs of 1,000 points each under one binary member = ₱100 in this run. |
| **Points** | Package A carries 1,000 binary points. Points are stamped on the activation, never recalculated. |
| **Qualified member** | A member with at least one direct member. That is the Harvest gate in this run. |
| **Queue** | The ordered list (`harvest_queue`) of qualified members. Position 1 is the first ever qualified. |
| **Reconciler** | A command that checks every wallet balance and award against the ledger and reports mismatches. |
| **Sponsor** | The member who introduced this member (sponsor tree). |
| **Standard member** | A free account: can shop, cannot earn commission. No package, no activation. |
| **Withdrawable** | The part of the wallet a member can withdraw. Commission lands here. |

---

## 5. Where everything lives

You will use these screens repeatedly. Menu names are exact; URLs are there when a menu is easier to
skip.

| What you want | Sign in as | Menu path | URL |
|---|---|---|---|
| Issue a registration code | admin | Commerce → Codes | `/admin/codes` |
| Approve or reject an activation | admin | Commerce → Activations | `/admin/activations` |
| Find a member, see their binary placement | admin | Commerce → Members → search → open | `/admin/members` |
| The commission ledger (all streams) | admin | Finance → Commissions | `/admin/commissions` |
| The binary ledger | admin | Finance → Commissions → binary tab | `/admin/commissions?ledger=binary` |
| Turn features on/off, set depths and gates | admin | System → Settings → **Commissions** tab | `/admin/settings#settings-commissions` |
| Turn Package A's binary points on | admin | Catalogue → Packages → Package A → **Package binary** card | `/admin/packages` |
| See a member's own earnings | the member | Membership → **Earnings** | `/earnings` |
| See a member's wallet | the member | Money → **E-wallet** | `/wallet` |
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
3. If the failure does not stop the next check, continue. If it does (for example the member was not
   created), mark the dependent checks **Blocked** and keep going with another part.
4. Do **not** edit code, settings you were not told to change, or database rows to "make it pass".

---

## 7. The tree you will build

### 7.1 Why this shape

- The **referral ladder** needs four activations in a chain, so the last one reaches level 4 — the
  company account — and proves a level-4 skip.
- The **binary pair** needs one member on each side of the same binary parent.
- The **harvest streams** need qualified members: with the gate lowered to one direct, a member
  qualifies the moment they have one direct. Three extra standard members give the queue enough
  positions for the first Harvest level.

### 7.2 The shape

```text
Sponsor tree (who introduced whom)              Binary tree (who sits under whom)
admin (company root, never paid)                admin/root
└── QA24_Sponsor                                 ├── LEFT  QA24_Sponsor
    ├── QA24_Member_A                            │          ├── LEFT  QA24_Member_A
    │   └── QA24_Member_B                        │          │          ├── LEFT  QA24_Team_1
    │       └── QA24_Member_C                    │          │          │          ├── LEFT  QA24_Team_1a
    │           └── QA24_Member_D                │          │          │          └── RIGHT QA24_Team_3
    ├── QA24_Team_1                              │          │          │                    └── LEFT QA24_Team_3a
    │   └── QA24_Team_1a                         │          │          └── RIGHT QA24_Team_2
    ├── QA24_Team_2                              │          │                    └── LEFT QA24_Team_2a
    │   └── QA24_Team_2a                         │          └── RIGHT QA24_Member_B
    ├── QA24_Team_3                              │                     └── LEFT  QA24_Member_C
    │   └── QA24_Team_3a                         │                                 └── LEFT QA24_Member_D
    ├── QA24_NoPkg_Sponsor  (standard)           └── RIGHT QA24_NoPkg_Sponsor
    │   └── QA24_NoPkg_Member  (activated)                  └── LEFT QA24_NoPkg_Member
```

Notice the two trees disagree for `QA24_Member_B`: its **sponsor** is `QA24_Member_A`, but its
**binary upline** is `QA24_Sponsor`. That is deliberate — it is what creates the pair, and it is the
single most important thing to get right on the registration form.

### 7.3 Every account you must create

The **Registration form** columns are literally what you type. Leave a cell saying *blank* empty.

| # | Username | Sponsor | Binary upline | Side | Mode | Created in |
|---|---|---|---|---|---|---|
| 1 | `QA24_Sponsor` | *blank* (→ admin) | *blank* (→ admin) | Left | Registration code | QA24-02 |
| 2 | `QA24_Member_A` | `QA24_Sponsor` | *blank* (→ sponsor) | Left | Registration code | QA24-02 |
| 3 | `QA24_Member_B` | `QA24_Member_A` | `QA24_Sponsor` | Right | Registration code | QA24-02 |
| 4 | `QA24_Team_1` | `QA24_Sponsor` | `QA24_Member_A` | Left | Standard | QA24-01 |
| 5 | `QA24_Team_1a` | `QA24_Team_1` | *blank* (→ sponsor) | Left | Standard | QA24-01 |
| 6 | `QA24_Team_2` | `QA24_Sponsor` | `QA24_Member_A` | Right | Standard | QA24-01 |
| 7 | `QA24_Team_2a` | `QA24_Team_2` | *blank* (→ sponsor) | Left | Standard | QA24-01 |
| 8 | `QA24_Team_3` | `QA24_Sponsor` | `QA24_Team_1` | Right | Standard | QA24-01 |
| 9 | `QA24_Team_3a` | `QA24_Team_3` | *blank* (→ sponsor) | Left | Standard | QA24-01 |
| 10 | `QA24_Member_C` | `QA24_Member_B` | *blank* (→ sponsor) | Left | Activate later + proof | QA24-04 |
| 11 | `QA24_Member_D` | `QA24_Member_C` | *blank* (→ sponsor) | Left | Activate later + proof | QA24-05 |
| 12 | `QA24_NoPkg_Sponsor` | `QA24_Sponsor` | `admin` | Right | Standard | QA24-06 |
| 13 | `QA24_NoPkg_Member` | `QA24_NoPkg_Sponsor` | *blank* (→ sponsor) | Left | Registration code | QA24-06 |

Emails can be anything unique that looks like an address (`qa24.sponsor@example.test`). Password for
every `QA24…` account: `Demo@1234`.

> **Why the binary sides matter.** `admin`'s left slot is taken by `QA24_Sponsor`; its right slot is
> kept free for the no-package sponsor, so the second half of the run cannot disturb the pair you
> verified in Part 2. `QA24_Member_A`'s two slots hold the Team accounts (zero points, so they add
> no volume), and `QA24_Member_B`'s left slot holds `QA24_Member_C`.

### 7.4 What the points do

Only an **activated package** adds points: each Package A activation adds 1,000 points to its binary
upline's side, and those points roll up the binary tree. `QA24_Team_*` are standard members: they
occupy slots but add **0** points. That is why they can sit anywhere without changing the pair
arithmetic.

---

## 8. Run order

Do the parts in this order. The check **numbers are fixed IDs**, not sequence: QA24-07 runs before
QA24-02, and QA24-12 runs almost last. That is intentional, and the reason is in the right-hand
column.

| Part | Checks | What it does | Why here |
|---|---|---|---|
| **1** | QA24-07, QA24-08 (+ seed and arm) | Turns on the streams, stamps Package A's points, seeds the Team Harvest queue and arms the General Harvest board while the tree is empty | Points are stamped **at activation**, and both harvest baselines freeze anything already complete — preparing them after the tree exists makes the money unpayable |
| **2** | QA24-02, QA24-15, QA24-16, QA24-17 | Creates the anchor account, the left/right pair, and verifies the pair award | The pair is the most order-sensitive money in the run |
| **3** | QA24-01, QA24-03, QA24-04 | Adds the standard members, the refused codes and member C | These supply the Harvest queue positions and the referral levels |
| **4** | QA24-09, QA24-10, QA24-11, QA24-13, QA24-14 | Verifies the referral ladder and the admin ledger | The awards were written by Part 3; verify before late activations add rows |
| **5** | QA24-19 … QA24-26 | Verifies Team Harvest and the General Harvest board | The awards were written as the tree grew in Part 3; this part reads them, it does not create them |
| **6** | QA24-05, QA24-12, QA24-06, QA24-18 | Registers and rejects D, then activates D; the no-package skip; the zero-point control | Registering D adds a Harvest position, so it runs after the totals |
| **7** | QA24-27, QA24-28, QA24-29 | Reconcile, tie out, restore and clean up | Always last |

**Time guide:** Part 1 15 min · Part 2 45 min · Part 3 60 min · Part 4 40 min · Part 5 45 min ·
Part 6 30 min · Part 7 15 min.

---

## 9. Part 1 — Prepare the system

### QA24-07 — Record the defaults, then apply the QA settings

| | |
|---|---|
| **What it proves** | The streams can be switched on and the gates lowered in the admin settings, values survive a save, and you have a record to restore at the end. |
| **Before** | You are signed in as `admin` on a development copy. |
| **Where** | System → Settings → **Commissions** tab (`/admin/settings#settings-commissions`) |
| **Evidence** | `QA24-07-settings-before.png`, `QA24-07-settings-after.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Open Settings → **Commissions**. One form holds these sub-blocks: *Direct referral bonus*,
   *Indirect referral (unilevel)*, *Repeat-purchase bonus*, *Royalty bonus*, **Team Harvest bonus**,
   **General Harvest bonus**, **Product binary**, **Package binary**.
2. In your notes, write down the **current** value of every control in the table below. You restore
   them in QA24-29, so a wrong "before" value means you cannot reset the system.
3. Change exactly these controls (leave everything else alone):

   | Control on the page | Set to |
   |---|---|
   | Direct referral bonus (switch) | **on** |
   | Indirect referral (unilevel) (switch) | **on** |
   | Unilevel depth (levels) | **4** |
   | Enable the Team Harvest bonus (switch) | **on** |
   | Direct members needed to qualify | **1** |
   | Levels that pay (Team Harvest card) | **1** |
   | Enable the General Harvest bonus (switch) | **leave off** — the board is armed first, then switched on |
   | Levels that pay (General Harvest card) | **1** |
   | Package binary enabled (switch) | **leave off for now** — QA24-08 turns it on |

4. Click **Save settings**. Reload the page.
5. Screenshot the card with your values, then fill in the table.

**You should see**

- A confirmation banner (or "No changes to save" if nothing differed).
- After the reload every control above still holds the value in the table: Team Harvest **on**,
  General Harvest and Package binary still **off** (the next block switches the package switch on).
- Nothing else on the page changed.

**Record here (fill this in as you go)**

| Control | Before | QA value | Restored in QA24-29 |
|---|---|---|---|
| Direct referral bonus | | on | ☐ |
| Indirect referral (unilevel) | | on | ☐ |
| Unilevel depth (levels) | | 4 | ☐ |
| Enable the Team Harvest bonus | | on | ☐ |
| Direct members needed to qualify | | 1 | ☐ |
| Levels that pay (Team Harvest) | | 1 | ☐ |
| Enable the General Harvest bonus | | off now, on after the arm | ☐ |
| Levels that pay (General Harvest) | | 1 | ☐ |
| Package binary enabled | | off now, on in QA24-08 | ☐ |

> **Two limits you cannot cross.** *Unilevel depth* accepts only **2–10** (so 4 is valid, 1 is not).
> *Direct members needed to qualify* accepts **1–10** (1 is the minimum this run needs).

**If it fails** — a value refuses to save with a validation message: read it, correct the value, try
again; if it still refuses, that is a **Fail**. If your "before" notes are incomplete, stop and take a
fresh backup rather than guessing later.

### Before QA24-08 — seed the queue and arm the board (do this now)

Both harvest streams freeze a **baseline** the first time they are prepared, and anything already
complete at that instant is recorded but **never paid**. So both must be prepared while the tree is
still empty — before you register anybody.

1. Open a terminal in `C:\laragon\www\ecowealth\system` and run:

   ```bat
   php bin\harvest_queue_seed.php
   ```

   Expect `inserted: 0 position(s)` and a line `baseline: … (team_harvest_ledger_since)`. The last
   line tells you the registration hook is now live. (If the gate is still 3, the seed says
   `gate: 3`; go back to QA24-07 and set **Direct members needed to qualify** to 1.)

2. With **Enable the General Harvest bonus** still **off**, run:

   ```bat
   php bin\general_harvest_arm.php
   ```

   Expect `N0 = 0` and a row count of zero: no position is complete on an empty queue. The tool
   **refuses to run while the stream is on**, which is why the switch waits.

3. Settings → **Commissions** → **General Harvest** card: switch **Enable the General Harvest bonus**
   **on** and save. From now on, each qualification settles the board as the queue grows.

4. Keep the two terminal outputs with your evidence (`qa24-harvest-seed.txt`,
   `qa24-general-arm.txt`).

> **Why here, not later.** If you seed after the tree exists, every level that is already complete is
> written as `baseline` and is never credited (D-07). If you arm at six positions, position #1's block
> is already full and becomes a `baseline` row too — the ₱2,000 never pays. Both are correct
> behaviour; both ruin the walkthrough if the order is wrong.

### QA24-08 — Give Package A binary points and turn the stream on

| | |
|---|---|
| **What it proves** | A package can carry binary points, and the global switch gates the whole stream. Both must be on **before** the first activation, because the activation is what stamps the points. |
| **Before** | QA24-07 passed. No package activation has been approved yet on this copy (if you already created one, restore the backup and start again). |
| **Where** | Catalogue → Packages → **Package A** (`/admin/packages`) and Settings → Commissions |
| **Evidence** | `QA24-08-package-binary.png`, `QA24-08-package-binary-setting.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Catalogue → **Packages** → open **Package A**.
2. Find the **Package binary** card. Switch **Package binary enabled** on, set **Points per
   activation** to `1000`, save.
3. Reopen Package A: the switch is on, points `1000`, and the list badge reads **Package binary 1000
   pts**.
4. Settings → **Commissions** → **Package binary** card: switch **Package binary enabled** on, and
   leave **Require a binary package to earn package pairs** on.
5. Save, reload, and confirm both switches are still on.
6. In phpMyAdmin (`ecowealth_sneat` → **SQL**):

   ```sql
   SELECT slug, binary_enabled, binary_points FROM packages WHERE slug = 'package-a';
   ```

**You should see**

- One row: `package-a | 1 | 1000`.
- Both package-binary switches **on** after a reload.
- Package A's card on the packages list shows the binary badge.

**If it fails** — points 0 or the switch reverts: **Fail**, and stop. Every later binary number in
this document depends on this check.

---

## 10. Part 2 — The anchor account and the pair

### QA24-02 — Registration codes create active members with their package

| | |
|---|---|
| **What it proves** | A code is single-use; redeeming one creates the member **and** activates the package in one step; the activation pays the sponsor immediately. |
| **Before** | QA24-08 passed (points are on). You can sign in as admin and you have an Incognito window. |
| **Where** | Commerce → Codes (issue) → `/register` (redeem) |
| **Evidence** | `QA24-02-register-code.png`, `QA24-02-code-used.png`, `QA24-02-three-members.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. **Window A (admin):** Commerce → **Codes** → issue a code for **Package A**. Leave the price at
   the package default (₱4,498), expiry one month from today, quantity **1**. Copy the code it shows
   (for example `ABCD-EFGH-JKLM`).
2. **Window B (Incognito):** open `/register`. Choose **Registration code**, paste the code, press
   **Validate** (it should confirm the package). Leave **Sponsor** empty. In **Binary placement**,
   leave *Your upline in the binary tree* empty (it defaults to your sponsor's placement) and pick
   the **Left** side. Register `QA24_Sponsor`, with `qa24.sponsor@example.test` and password
   `Demo@1234`, tick the terms, press **Create account**.
3. Issue a second Package A code. Register `QA24_Member_A` with sponsor `QA24_Sponsor`, binary
   upline empty (it will use the sponsor), side **Left**.
4. Issue a third code. Register `QA24_Member_B` with sponsor `QA24_Member_A`, **binary upline
   `QA24_Sponsor`** (type it over the sponsor that appears), side **Right**.
5. As admin, open Commerce → **Codes**. All three codes show **Used**, with the member who used each
   one and the time.

**You should see**

- Three members, each **Active** with **Package A**; the member page's **Joined as** tile reads **Code**.
- `QA24_Sponsor`'s binary placement: upline `admin`, side left (Commerce → Members →
  `QA24_Sponsor` → **Binary placement** card).
- `QA24_Member_A`: upline `QA24_Sponsor`, left. `QA24_Member_B`: upline `QA24_Sponsor`, **right**.
- Money already paid (you will verify it in Part 2 and Part 4):
  - `QA24_Sponsor`: **₱500** direct (from A) **+ ₱100** indirect level 2 (from B) **+ ₱100** binary
    pair (B completed the pair).
  - `QA24_Member_A`: **₱500** direct (from B).
  - `admin`: nothing — every award addressed to it is **skipped** because the company account holds
    no package: ₱500 at level 1 (Sponsor's activation), ₱100 at level 2 (A's), ₱50 at level 3 (B's).
    C adds a ₱40 level-4 skip later.

**If it fails** — "code not recognised": check you copied the whole code and that it is for Package
A. "Upline is not in the binary tree": the binary upline must already be placed; `QA24_Sponsor` is,
after step 2. If a member is created but stays **Pending**, the code mode was not used — that is a
**Fail**.

### QA24-15 — Confirm the placement in the binary tree

| | |
|---|---|
| **What it proves** | Placement is exactly what the registration form said, one node per member, and a side holds one member only. |
| **Before** | QA24-02 created `QA24_Sponsor`, `QA24_Member_A` and `QA24_Member_B`. |
| **Where** | Commerce → Members → each member → **Binary placement** card; phpMyAdmin |
| **Evidence** | `QA24-15-binary-placement.png`, `QA24-15-binary-nodes.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open Commerce → **Members**, search `QA24_Member_B`, open it. The **Binary placement**
   card reads: Upline `QA24_Sponsor`, Side **Right**, Depth 1, State *Placed*.
2. Repeat for `QA24_Member_A` (upline `QA24_Sponsor`, **Left**) and `QA24_Sponsor` (upline `admin`,
   **Left**).
3. In phpMyAdmin:

   ```sql
   SELECT u.username AS member, up.username AS upline, n.side, n.state,
          n.left_points, n.right_points, n.pairs_paid
     FROM binary_nodes n
     JOIN users u  ON u.id = n.user_id
     LEFT JOIN users up ON up.id = n.upline_id
    WHERE u.username IN ('QA24_Sponsor','QA24_Member_A','QA24_Member_B')
    ORDER BY u.username;
   ```

**You should see**

- Three rows, one per member, all `state = placed`.
- `QA24_Member_A` left of `QA24_Sponsor`; `QA24_Member_B` right of `QA24_Sponsor`.
- On `QA24_Sponsor`'s row: `pairs_paid = 1` and `left_points = 0`, `right_points = 0` — the pair
  consumed one 1,000-point block from each side. (If you run this query before B's activation was
  approved, the pair row is absent; finish QA24-02 first.)

**If it fails** — a member is missing, has `state = floating`, or both A and B are on the same side:
**Fail**. The pair award cannot exist without both sides.

### QA24-16 — The pair completes: ₱100 to `QA24_Sponsor`

| | |
|---|---|
| **What it proves** | Left 1,000 + right 1,000 = one pair; the pair pays `1,000 × 10% = ₱100`, credited to the withdrawable wallet, exactly once. |
| **Before** | QA24-02 approved all three activations. |
| **Where** | phpMyAdmin (`binary_awards`, `ewallet_ledger`); member E-wallet |
| **Evidence** | `QA24-16-binary-award.png`, `QA24-16-binary-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In phpMyAdmin:

   ```sql
   SELECT a.id, a.kind, a.status, a.amount, a.pair_count, a.hold_days,
          a.activation_id, a.ledger_id
     FROM binary_awards a
     JOIN users u ON u.id = a.user_id
    WHERE u.username = 'QA24_Sponsor' AND a.kind = 'pair'
    ORDER BY a.id;
   ```

2. Note the award `id`. Then check its ledger line:

   ```sql
   SELECT id, type, amount, ref_type, ref_id
     FROM ewallet_ledger
    WHERE ref_type = 'binary' AND ref_id = <the award id from step 1>;
   ```

3. In Window B, sign in as `QA24_Sponsor` → Money → **E-wallet**.

**You should see**

- Exactly **one** `pair` award for `QA24_Sponsor`: status `credited`, amount **₱100.00**,
  `pair_count = 1`, `hold_days = 0`, with an `activation_id` and a `ledger_id`.
- One ledger row of **₱100.00** with `ref_type = binary` and the award's id.
- The wallet shows one credit of ₱100 labelled **Product binary** (a package pair shares the `binary`
  ledger stream) and **Withdrawable** is higher by ₱100.
- No second pair award, no second credit.

**If it fails** — the award is missing: confirm both members are activated *and* on opposite sides
(QA24-15). Two awards for the same activation: **Fail** (the pair was settled twice).

### QA24-17 — The binary ledger shows the package source

| | |
|---|---|
| **What it proves** | Finance can read the pair's activation source and open its wallet line, and the screen is read-only. |
| **Before** | QA24-16 passed. |
| **Where** | Finance → Commissions → **binary** tab (`/admin/commissions?ledger=binary`) |
| **Evidence** | `QA24-17-binary-ledger.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open the binary ledger.
2. Find the pair row and read its **Source**, **Points** and **Rate** columns, then open its wallet
   link. There is no activation link on this row; **Source** is the activation reference.
3. Look for any edit or delete control.

**You should see**

- One credited pair row for `QA24_Sponsor`: kind **Pair**, source **Package activation**, **2000**
  points, **10.000%**, **₱100.00**, status **Credited**, with a wallet link.
- Tiles: **Total credited ₱100.00**, **Awards 1**, **Recipients 1**, **Package pairs 1**,
  **Package points 2000 pts**, **Pending release ₱0.00** — a package pair is credited at once.
- No edit, delete or "reverse" button (reversal is a separate audited tool, not this screen).

---

## 11. Part 3 — Fill the tree

### QA24-01 — Standard members: free accounts with no package

| | |
|---|---|
| **What it proves** | The free front door works, a standard member can be placed in the binary tree without points, and the Harvest gate is about *directs*, not packages. |
| **Before** | `QA24_Sponsor` and `QA24_Member_A` exist (QA24-02). |
| **Where** | `/register` in Window B (signed out) |
| **Evidence** | `QA24-01-register-standard.png`, `QA24-01-team-tree.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

Register the six Team accounts exactly as in §7.3 — **sponsor is not blank**, this is the part v1
got wrong. For each: `/register` → **Standard** (free) → fill sponsor and binary upline as listed →
username → email → password `Demo@1234` → terms → **Create account**. Sign out (or open a fresh
Incognito window) before **each** account: registering signs you in as the member you just created,
and a signed-in member may only place inside their own subtree.

| Username | Sponsor | Binary upline | Side |
|---|---|---|---|
| `QA24_Team_1` | `QA24_Sponsor` | `QA24_Member_A` | Left |
| `QA24_Team_1a` | `QA24_Team_1` | *blank* | Left |
| `QA24_Team_2` | `QA24_Sponsor` | `QA24_Member_A` | Right |
| `QA24_Team_2a` | `QA24_Team_2` | *blank* | Left |
| `QA24_Team_3` | `QA24_Sponsor` | `QA24_Team_1` | Right |
| `QA24_Team_3a` | `QA24_Team_3` | *blank* | Left |

**You should see**

- Six **Active** members, package `—`, wallet ₱0.00, each with a sponsor as listed.
- Each has a binary node on the side you chose; **no** points were added by these registrations.
- `QA24_Sponsor`'s **Genealogy** (Membership → Genealogy) shows Team 1–3 as direct children, and
  each Team member shows its `a` child.

**If it fails** — "This side is already taken": a previous test left a node there; pick the free side
shown on the form and note the deviation. A standard member who cannot register at all is a **Fail**.

### QA24-03 — Invalid, used and expired codes are refused

| | |
|---|---|
| **What it proves** | A code is money: a bad one must never create a member or activate a package. |
| **Before** | You have one used code from QA24-02. |
| **Where** | `/register` (code mode) |
| **Evidence** | `QA24-03-code-refused.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. `/register` → **Registration code** → type `ZZZZ-ZZZZ-ZZZZ` → **Validate**.
2. Try to submit it anyway with a fresh username.
3. Type the code you used in QA24-02 → **Validate**; try to submit it with another username.
4. Find an expired code (Commerce → Codes, filter **Expired**) and try the same.

**You should see**

- **Validate** answers "not recognised", "already used" and "expired" respectively.
- Each submit returns to `/register` with the error and **creates no member** (check Commerce →
  Members) and no activation.
- The used code still shows the member from QA24-02, unchanged.

### QA24-04 — A manual proof waits for approval, then activates

| | |
|---|---|
| **What it proves** | A member can pay by proof; the admin reviews and approves once; the activation then pays the ladder. |
| **Before** | QA24-02 and QA24-01 passed. |
| **Where** | `/membership` (as the member) → Commerce → Activations (as admin) |
| **Evidence** | `QA24-04-membership-proof.png`, `QA24-04-admin-approve.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. **Window B:** `/register` → **Activate later**, sponsor `QA24_Member_B`, binary upline blank, side
   **Left**, username `QA24_Member_C`, password `Demo@1234`. This creates a **Pending** member.
2. Still as `QA24_Member_C`, open **Membership** → choose **Payment proof** → **GCash** → Package A →
   enter a reference number → attach a small PNG or PDF receipt → submit.
3. Confirm the page now shows **Activation in progress** and no longer offers the forms.
4. **Window A:** Commerce → **Activations** → *Awaiting review* → **View proof** on C's row. The
   receipt opens in the browser.
5. Press **Approve** and confirm.

**You should see**

- `QA24_Member_C` becomes **Active** with **Package A**; the request shows **Approved** with the
  reviewer and a timestamp.
- The approval paid the ladder from C's activation:
  - `QA24_Member_B` **₱500** direct,
  - `QA24_Member_A` **₱100** indirect level 2,
  - `QA24_Sponsor` **₱50** indirect level 3.
- Approving the same request a second time is refused with *This activation has already been
  completed. Nothing was changed.* and changes nothing.
- The proof image returns `403` to a member and a login redirect to a guest (you can check this by
  copying the proof URL into Window B while signed out).

**If it fails** — the proof is rejected for size/type: use a smaller PNG. If approval leaves the
member Pending, **Fail** — the review step did not complete.

## 12. Part 4 — Referral commissions

Nothing is activated in this part: you are verifying awards that Parts 2 and 3 already wrote. Sign in
as the member named in each check (Window B) and as admin (Window A).

### QA24-09 — Activation 1: `QA24_Member_A` pays ₱500 to `QA24_Sponsor`

| | |
|---|---|
| **What it proves** | Level 1 is the package's direct bonus, paid once, into the withdrawable wallet. |
| **Before** | A is active; B, C and the Team members exist. |
| **Where** | Membership → **Earnings** and Money → **E-wallet** as `QA24_Sponsor`; Finance → Commissions as admin |
| **Evidence** | `QA24-09-member-earnings.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin: Finance → **Commissions** → filter **Member = `QA24_Sponsor`**, **Type = Direct
   referral**.
2. Sign in as `QA24_Sponsor` → **Membership → Earnings** → read the award rows and the totals.
3. Open **Money → E-wallet** and read the statement.

**You should see**

- One direct award: recipient `QA24_Sponsor`, source `QA24_Member_A`, Package A, level 1,
  **credited ₱500.00**.
- `QA24_Sponsor`'s **Total credited** includes that ₱500, and the **Direct referral** figure equals
  ₱500.
- One **Commission** credit of ₱500 in the wallet; **Withdrawable** rose by ₱500; no duplicate row.

### QA24-10 — Activation 2: `QA24_Member_B` pays direct + one indirect level

| | |
|---|---|
| **What it proves** | The chain pays level 1 to the sponsor and level 2 to the next upline, each at its own amount. |
| **Before** | QA24-09 passed. |
| **Where** | Finance → Commissions (filter **Type = Indirect referral**) |
| **Evidence** | `QA24-10-admin-ledger-indirect.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, filter **Type = Indirect referral** and **Source = `QA24_Member_B`**.
2. Clear the source filter and find `QA24_Member_A`'s direct award for B.
3. Sign in as `QA24_Member_A` → Earnings.

**You should see**

- Exactly one indirect row: level 2, `QA24_Sponsor`, **credited ₱100.00**, source B.
- One direct row: level 1, `QA24_Member_A`, **credited ₱500.00**, source B.
- `QA24_Member_A`'s Earnings: **Direct ₱500**, **Indirect ₱0**.
- Totals by the end of Part 3: `QA24_Sponsor` **₱650** commission (₱500 direct + ₱150 indirect —
  B's ₱100 and C's ₱50); `QA24_Member_A` **₱600** (₱500 direct + ₱100 indirect from C).

### QA24-11 — Activation 3: `QA24_Member_C` pays three levels and skips level 4

| | |
|---|---|
| **What it proves** | The walk continues up the chain; each upline keeps its own level amount (no compression). |
| **Before** | QA24-04 approved C. |
| **Where** | Finance → Commissions (filter **Source = `QA24_Member_C`**) |
| **Evidence** | `QA24-11-admin-ledger-levels.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, filter **Source = `QA24_Member_C`**.
2. Read the four rows; then check the three members' Earnings pages.

**You should see**

- **Four** rows for source `QA24_Member_C`:
  - level 1 `QA24_Member_B` **₱500** — credited;
  - level 2 `QA24_Member_A` **₱100** — credited;
  - level 3 `QA24_Sponsor` **₱50** — credited;
  - level 4 `admin` **₱40** — **skipped**, reason *recipient has no package*, no ledger link.
- `QA24_Member_B` direct ₱500; `QA24_Member_A` direct ₱500 + indirect ₱100; `QA24_Sponsor` indirect
  total **₱150** (₱100 from B + ₱50 from C).
- The skipped ₱50 is not redirected to anyone — that is the level-4 company-root skip, and this check
  is where you see it.

### QA24-13 — The member sees the level breakdown and can export

| | |
|---|---|
| **What it proves** | The member's statement shows every award with level, package and status, and the CSV matches the screen. |
| **Before** | QA24-11 passed. |
| **Where** | `/earnings` as `QA24_Sponsor` |
| **Evidence** | `QA24-13-member-earnings.png`, `QA24-13-earnings.csv` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign in as `QA24_Sponsor` → **Membership → Earnings**.
2. Read the **Unilevel breakdown** (levels 1–3) and the **Total credited** figure.
3. Press **Export CSV**, open the file.

**You should see**

- `QA24_Sponsor`'s total credited is **₱650** at this point (₱500 direct + ₱150 indirect). The
  binary pair and the Harvest awards are separate streams, shown elsewhere.
- The breakdown lists the direct award and the two indirect awards, with credited statuses.
- The CSV has one row per award and matches the screen (level, type, source, package, amount,
  status). No extra or missing rows.

### QA24-14 — The admin commission ledger and its export

| | |
|---|---|
| **What it proves** | Finance has one auditable record of every award, filterable, read-only, with an export that is itself audited. |
| **Before** | QA24-13 passed. |
| **Where** | `/admin/commissions` |
| **Evidence** | `QA24-14-admin-ledger.png`, `QA24-14-admin-ledger-export.png`, `QA24-14-audit-row.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open **Finance → Commissions**.
2. Filter by `QA24_Sponsor`, then by **Type**, **Level**, **Status** and a date range; press the eye
   button on a row to open its detail.
3. Press **Export CSV**, then open System → **Audit log**.

**You should see**

- Four stat cards: **Total credited**, **This month**, **Awards**, **Skipped**.
- Rows show recipient, source, package, type/level, amount, status, activation and date. Credited
  rows are green; skipped rows are muted and marked **not paid**.
- The detail view traces award → activation → wallet entry and offers no edit or delete.
- CSV matches the screen. The audit log records a `commission.export` row (singular: that is the
  action string the package ledger writes).
- As a member, `/admin/commissions` returns **403**; as a guest it redirects to `/login`.

**If it fails** — a skipped row without a "not paid" marker, or a credited row without a wallet link:
**Fail**. That is the distinction the whole document relies on.

---

## 13. Part 5 — Harvest

### QA24-19 — The queue: who qualified, and in what order

| | |
|---|---|
| **What it proves** | Qualification happens on a registration, one row per member, with contiguous positions and a direct count recorded at that moment. |
| **Before** | Parts 2–4 passed. |
| **Where** | phpMyAdmin (`harvest_queue`, `settings`); System → Settings → Commissions (the **Seeded** status strip) |
| **Evidence** | `QA24-19-harvest-queue.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In phpMyAdmin:

   ```sql
   SELECT q.id AS position, u.username, q.teammates, q.baseline_n,
          q.directs_at_qualification, q.source, q.qualified_at
     FROM harvest_queue q
     JOIN users u ON u.id = q.user_id
    ORDER BY q.id;
   ```

2. Note how many rows there are; write **N** here: `N = ____`.
3. Read the setting that proves the queue was seeded:

   ```sql
   SELECT key_name, value FROM settings WHERE key_name = 'team_harvest_ledger_since';
   ```

**You should see**

- **Six** rows, in this order: `QA24_Sponsor` (1), `QA24_Member_A` (2), `QA24_Team_1` (3),
  `QA24_Team_2` (4), `QA24_Team_3` (5), `QA24_Member_B` (6).
- Positions are 1…6 with no gaps; each `directs_at_qualification` is at least 1; `source` is
  `registration`.
- The setting query returns a date-time (the queue is live, not empty).

> **Why six, and why B is last.** Any member with at least one direct qualifies, and qualification
> happens when that direct *registers*. `QA24_Sponsor` qualified when A joined, A when B joined,
> and the three Team members when their `a` child joined. `QA24_Member_B` only qualified when
> `QA24_Member_C` registered (QA24-04), which happens after the Team accounts — hence position 6.
> The count and the order are both correct; neither is a defect. `QA24_Member_C` joins the queue
> later, in QA24-05 (when D registers).

**If it fails** — no rows at all: the queue was never seeded. Run
`php bin\harvest_queue_seed.php` once from `C:\laragon\www\ecowealth\system`, re-register nothing,
and re-run this query; if rows then appear, mark this check **Pass with note**. A gap in positions
(1,2,4) is a **Fail**.

### QA24-20 — Team Harvest level 1 pays each member whose block fills

| | |
|---|---|
| **What it proves** | A queued member is paid once, when enough qualified members appear after them, into the withdrawable wallet. More than one member can complete level 1 in the same run. |
| **Before** | QA24-19 shows the queue. The rows were written by the Part 3 registrations, when each member's block filled. |
| **Where** | phpMyAdmin (`team_harvest_awards`); Money → E-wallet as `QA24_Sponsor`, then `QA24_Member_A` |
| **Evidence** | `QA24-20-team-harvest-award.png`, `QA24-20-team-harvest-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In phpMyAdmin:

   ```sql
   SELECT a.id, u.username, a.level, a.threshold_n, a.n_at_award, a.amount, a.status, a.ledger_id
     FROM team_harvest_awards a
     JOIN users u ON u.id = a.user_id
    ORDER BY a.id;
   ```

2. Sign in as `QA24_Sponsor` → Money → **E-wallet**.

**You should see**

- Exactly **one** award: `QA24_Sponsor`, level 1, `threshold_n = 3`, `n_at_award = 3`,
  **₱1,000.00**, status `credited`, with a ledger id.
- One **Team Harvest bonus** credit of ₱1,000; withdrawable up by ₱1,000.
- No award for A, B, C, the Team accounts or the `…a` accounts.

> **Why only the Sponsor.** The count is over *sponsor-lineage* descendants that qualified after
> you, not over the binary tree. Sponsor is the sponsor of A, Team_1, Team_2 and Team_3, so its
> counter reaches 3 first (at Team_2) and pays once. `QA24_Member_A` sponsors only B (and, through
> B, C and D), so its counter stays below 3 in this walkthrough — no award, which is correct.
> Binary placement does not feed this stream.

### QA24-21 — The member Team Harvest statement

| | |
|---|---|
| **What it proves** | The member can see the position, the gate, the matrix and the paid level. |
| **Before** | QA24-20 passed. |
| **Where** | `/earnings` → **Team Harvest** tab, as `QA24_Sponsor` |
| **Evidence** | `QA24-21-member-team-harvest.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Sign in as `QA24_Sponsor` → Earnings → **Team Harvest** tab (URL `/earnings?ledger=team_harvest`).
2. Read the position, the gate sentence and the 3×10 matrix.
3. Press **Export CSV** and open it.

**You should see**

- Position **#1**, with *You qualified for position #1 when you registered your 1st direct member.*
  and the gate **1 direct**.
- Level 1 **Paid ₱1,000**; level 2 **In progress** (2 of 9 slots filled, the queue needs 12); levels
  3–10 empty.
- The CSV matches the screen; the stats cards do not change when you switch source filters.

### QA24-22 — The admin Team Harvest ledger

| | |
|---|---|
| **What it proves** | Finance sees the same award, filterable, with an audited export. |
| **Before** | QA24-20 passed. |
| **Where** | Finance → Commissions → **team_harvest** tab |
| **Evidence** | `QA24-22-admin-team-harvest.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open the team_harvest ledger.
2. Filter by member, level and status; export the CSV; open the audit log.

**You should see**

- One credited row: `QA24_Sponsor`, level 1, ₱1,000.00.
- Tiles: **Credited ₱1,000.00**, **Awards 1**, **Recipients 1**, **Before go-live ₱0.00**,
  **Open liability ₱0.00** — level 2 needs 12 qualified members, so nothing is due yet.
- The export matches the screen and an audit row `team_harvest.export` exists.

### QA24-23 — Confirm the board's baseline and its first settled position

| | |
|---|---|
| **What it proves** | The board was armed at N₀ = 0 before the tree existed, so it settled position #1 as the queue grew — and it settled no position twice. |
| **Before** | The arm was run in the "Before QA24-08" block; QA24-19 shows six positions. |
| **Where** | Finance → Commissions → **general_harvest** tab; phpMyAdmin |
| **Evidence** | `QA24-23-general-harvest-board.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. As admin, open the general_harvest ledger: the header badges read **Armed** and **Paying**. In the
   **Level distribution** table, level 1 shows **1** paid award (**₱2,000.00**) and one block waiting
   at **N = 7**.
2. In phpMyAdmin:

   ```sql
   SELECT baseline_n, settled_n, armed_at FROM general_harvest_board WHERE id = 1;
   SELECT COUNT(*) AS n, MAX(id) AS max_id FROM harvest_queue;
   SELECT COUNT(*) AS awards, COALESCE(SUM(amount),0) AS paid FROM general_harvest_awards;
   ```

**You should see**

- `baseline_n = 0`, `armed_at` set, `settled_n` = the queue length (**6**).
- Queue `n = max_id` = **6** (no gaps).
- `awards = 1`, `paid = 2000.00` — position #1 (`QA24_Sponsor`) only.
- Position #2 (`QA24_Member_A`) is **not** complete at N = 6: its block needs the queue to reach 7,
  which happens in Part 6.

> **The "N ≥ 4" rule.** Position #1's level-1 block needs the queue to reach 4; position #2 needs 7;
> each later position needs three more. So at N = 6 exactly one award exists — and when Part 6
> lifts N to 7 (D's registration), position #2 pays too (that is the +₱2,000 note in QA24-05 and
> §16). N reaches 8 when the no-package sponsor qualifies in QA24-06, which completes no further block.

**If it fails** — `baseline_n` is 6 rather than 0: the board was armed after the tree was built, so
position #1 was frozen as *baseline* and can never pay. That is the ordering error described in the
"Before QA24-08" block; restore the backup and start again (there is no supported un-arm in the app).

### QA24-24 — The General Harvest credit lands in the wallet

| | |
|---|---|
| **What it proves** | The board award becomes a withdrawable credit with a ledger line, once. |
| **Before** | QA24-23 passed. |
| **Where** | phpMyAdmin (`general_harvest_awards`); Money → E-wallet as `QA24_Sponsor` |
| **Evidence** | `QA24-24-general-harvest-award.png`, `QA24-24-general-harvest-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In phpMyAdmin:

   ```sql
   SELECT a.id, a.seq, u.username, a.level, a.amount, a.status, a.ledger_id
     FROM general_harvest_awards a
     JOIN users u ON u.id = a.user_id
    ORDER BY a.id;
   ```

2. Check the total:

   ```sql
   SELECT SUM(amount) AS total FROM general_harvest_awards;
   ```

3. Sign in as `QA24_Sponsor` → E-wallet.

**You should see**

- One award: sequence 0 (queue position #1), `QA24_Sponsor`, level 1, **₱2,000.00**, credited.
  (By the end of Part 6 a second award exists for position #2 — see §16.)
- `total` = **2000.00** at this point.
- One **General Harvest bonus** credit of ₱2,000; withdrawable up by ₱2,000.

### QA24-25 — The progress view: the admin level table and the member's next block

| | |
|---|---|
| **What it proves** | The ledger shows, per level, how far the queue has come and what it is waiting for; the member's own card shows the same position and progress. |
| **Before** | QA24-24 passed. |
| **Where** | Finance → Commissions → **general_harvest** (Level distribution table); `/earnings?ledger=general_harvest` as `QA24_Sponsor` |
| **Evidence** | `QA24-25-general-harvest-levels.png`, `QA24-25-member-next-block.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. In the admin ledger, read the **Level distribution** table: members needed, block size, amount,
   paid awards, paid total, before go-live, state and **Next at N**.
2. **Window B:** sign in as `QA24_Sponsor` → Earnings → **General Harvest** tab.

**You should see**

- Level 1: **4** members needed, **3** block size, **₱2,000.00**, **1** paid award, **₱2,000.00**
  paid, state **Pays**, one block waiting at **N = 7**.
- Levels 2–10: their configured amounts and next completion (**level 2: N = 13**), with no paid row
  and no blank state.
- The member card reads **Position #1 · queue at 6**, and **Your next block** says
  *Level 2 — block #5–#13: 2 of 9 filled*, needing the queue to reach **13**.

### QA24-26 — The admin General Harvest ledger

| | |
|---|---|
| **What it proves** | Finance sees the award with filters and an audited export. |
| **Before** | QA24-24 passed. |
| **Where** | Finance → Commissions → general_harvest |
| **Evidence** | `QA24-26-admin-general-harvest.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Filter member `QA24_Sponsor`, level 1, status credited.
2. Export the CSV; open the audit log.

**You should see**

- One credited row: ₱2,000.00, level 1.
- Tiles: **Credited ₱2,000.00**, **Awards 1**, **Recipients 1**, **Before go-live ₱0.00**,
  **Open liability ₱0.00** at the current queue length.
- Export matches; `general_harvest.export` appears in the audit log.

---

## 14. Part 6 — The late activations

These four checks deliberately add members **after** the totals. Do not re-run the earlier checks
afterwards: the queue and the board will have moved.

### QA24-05 — A rejected proof returns the member to pending

| | |
|---|---|
| **What it proves** | A rejection explains itself, moves no money, and leaves the member able to try again. |
| **Before** | QA24-04 passed, Part 5 finished and its totals recorded (registering D adds a Harvest position) |
| **Where** | `/membership` (member) → Commerce → Activations (admin) |
| **Evidence** | `QA24-05-admin-reject.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. **Window B:** register `QA24_Member_D` with **Activate later**, sponsor `QA24_Member_C`, binary
   upline blank, side **Left**. Submit a payment proof for Package A as in QA24-04.
2. **Window A:** Commerce → **Activations** → on D's row type a **Rejection reason** (for example
   "Blurred receipt") → press **Reject**.
3. Try rejecting again with the reason field empty.

**You should see**

- D's request is **Rejected** with your reason; `QA24_Member_D` is still **Pending** with no package.
- D's **Activation history** shows the rejected row and the reason.
- No wallet debit and **no commission** was paid by the rejection (compare C's awards, which are
  unchanged).
- The empty-reason rejection is refused.
- The member can open a **new** request (you will do that in QA24-12).

> **Expected side effect.** Registering D gives `QA24_Member_C` its first direct, so C now qualifies:
> the queue grows to 7 and the General Harvest board pays position #2 (`QA24_Member_A`)
> **₱2,000**. Record it with this check's evidence; it is correct, not a duplicate. The rejection
> itself pays nothing.

### QA24-12 — Activation 4 pays all four configured levels

| | |
|---|---|
| **What it proves** | The ladder walks exactly to its configured depth (4) and no further: D's fourth upline is a member with a package, so level 4 pays; the company root would be level 5 and is never reached. |
| **Before** | QA24-05 rejected D; D is still pending. Part 5 is finished and the totals recorded. |
| **Where** | `/membership` (D) → Commerce → Activations → Finance → Commissions |
| **Evidence** | `QA24-12-admin-ledger-skipped.png`, `QA24-12-levels.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. **Window B:** sign in as `QA24_Member_D` → **Membership** → submit a **new** payment proof for
   Package A (new reference, new receipt).
2. **Window A:** Commerce → **Activations** → **Approve** D.
3. Finance → **Commissions** → filter **Source = `QA24_Member_D`**.
4. Filter **Status = Skipped**, then read `admin`'s wallet.

**You should see**

- **Four credited rows** for source `QA24_Member_D`, with no skipped row:
  level 1 `QA24_Member_C` **₱500**, level 2 `QA24_Member_B` **₱100**, level 3 `QA24_Member_A`
  **₱50**, level 4 `QA24_Sponsor` **₱40**.
- No row at level 5 — `admin` (D's fifth upline) is beyond the configured depth, so nothing is even
  evaluated there.
- Compare with QA24-11: C's chain is one link shorter, which is why C's level 4 was `admin` and was
  skipped. Both behaviours are correct.

> **Already settled.** C qualified when D *registered* (QA24-05), so the General Harvest payment to
> position #2 belongs to that check's evidence. Approving D now only adds the referral rows above.
> The level-4 amount is **₱40**, not ₱50 — the seeded ladder is 500 / 100 / 50 / 40 / 30 …

### QA24-06 — A sponsor without a package is skipped, never paid

| | |
|---|---|
| **What it proves** | "Active with a package" is the eligibility rule: a sponsor with no package is recorded as skipped and its wallet does not move. |
| **Before** | Part 5 finished. `admin`'s right binary slot is free. |
| **Where** | `/register` (Window B) → Commerce → Codes → Finance → Commissions |
| **Evidence** | `QA24-06-admin-ledger-skipped.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. **Window B:** register `QA24_NoPkg_Sponsor` as **Standard**, sponsor `QA24_Sponsor`, **binary
   upline `admin`**, side **Right**.
2. **Window A:** issue a Package A code.
3. **Window B:** register `QA24_NoPkg_Member` with **Registration code**, sponsor
   `QA24_NoPkg_Sponsor`, binary upline blank, side **Left**.
4. Finance → **Commissions** → filter **Status = Skipped** and **Source = `QA24_NoPkg_Member`**.
5. Sign in as `QA24_NoPkg_Sponsor` → E-wallet.

**You should see**

- The new member is **Active** with Package A.
- One skipped row: level 1, recipient `QA24_NoPkg_Sponsor`, **₱500.00**, *recipient has no
  package*, not paid, no ledger link.
- `QA24_NoPkg_Sponsor`'s wallet and total credited are **unchanged** (₱0).
- One **credited** row for `QA24_Sponsor` at level 2 (₱100) — the ladder correctly pays the next
  eligible upline; this is expected.
- `QA24_Sponsor`'s binary pair count is **still 1** (this activation is outside its legs, by design).

**If it fails** — NoPkg's wallet moved, or the row says credited: **Fail**, that is money released to
an ineligible member.

### QA24-18 — Negative control: zero points or a disabled package stamps zeros

| | |
|---|---|
| **What it proves** | With no points, or the package flag off, or the global switch off, an activation places no volume and pays no pair — while the referral ladder still pays. |
| **Before** | Every earlier binary number is verified and recorded. |
| **Where** | Catalogue → Packages → Package A; System → Settings; Commerce → Activations |
| **Evidence** | `QA24-18-binary-zeros.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

0. Before you start, register three pending members you will activate here — `QA24_Zero1`,
   `QA24_Zero2`, `QA24_Zero3`, sponsor `QA24_Sponsor`. **Their binary upline must be a free slot**,
   because the sponsor's own left slot already holds `QA24_Member_A`: use `QA24_Team_1a` (left),
   `QA24_Team_2a` (left) and `QA24_Team_3a` (left). Without this the form refuses with *"The left
   slot under member #3 is already taken."*
1. Set Package A `binary_points = 0` (flag still on). Approve `QA24_Zero1`'s proof. Then check:

   ```sql
   SELECT a.id, a.binary_points, a.binary_total, a.binary_processed_at
     FROM activations a
    ORDER BY a.id DESC LIMIT 1;
   ```

2. Set Package A `binary_enabled = 0`; approve `QA24_Zero2`; check its row again.
3. Set `package_binary_enabled = 0` (global); approve `QA24_Zero3`; check its row.
4. Restore Package A to `binary_enabled = 1`, `binary_points = 1000`, and the global switch **on**.
5. Confirm no `binary_awards` or `binary_volume` rows were written by any of the three.

**You should see**

- Each of the three activations has `binary_points = 0`, `binary_total = 0.00`, and no volume/award
  rows.
- The referral ladder still paid its normal amounts for each activation (extra rows are expected).
- After step 4 the package and switches are back to the QA values.

**If it fails** — any of the three wrote volume or a pair: **Fail**. That is money out of nothing.

---

## 15. Part 7 — Reconcile, restore, clean up

### QA24-27 — Wallet reconciliation is clean

| | |
|---|---|
| **What it proves** | Every balance agrees with the ledger, including all the awards this run created. |
| **Before** | Parts 1–6 finished. Terminal open in `C:\laragon\www\ecowealth\system`. |
| **Where** | PowerShell |
| **Evidence** | `QA24-27-reconcile.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Run:

   ```powershell
   php bin\wallet_reconcile.php
   php bin\wallet_reconcile.php --json
   ```

2. Save both outputs to `QA24-27-reconcile.txt`.

**You should see**

- Every section prints its own OK line — **Wallets**, **Commissions**, **Transfers**, **Payouts**,
  **Repeat-purchase**, **Royalty**, **Team Harvest**, **General Harvest**, **Product binary** — and the
  command ends with *All good — wallets, commissions, transfers, payouts, repeat-purchase, royalty,
  team-harvest, general-harvest and product-binary awards reconcile.* Exit code **0**.
- No mismatch lines.

**If it fails** — copy the whole output to the evidence file and **stop**: a reconciliation mismatch
is a blocker, not something to work around.

### QA24-28 — Every credited award has a ledger line; skipped ones do not

| | |
|---|---|
| **What it proves** | The awards and the accounting agree, one to one. |
| **Before** | QA24-27 passed. |
| **Where** | phpMyAdmin |
| **Evidence** | `QA24-28-tieout.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Award-to-ledger join:

   ```sql
   SELECT c.id, u.username, c.amount, c.status, c.ledger_id, l.amount AS ledger_amount
     FROM commissions c
     JOIN users u ON u.id = c.user_id
     LEFT JOIN ewallet_ledger l ON l.id = c.ledger_id
    ORDER BY c.id;
   ```

2. Sum comparison:

   ```sql
   SELECT (SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status = 'credited') AS awards,
          (SELECT COALESCE(SUM(amount),0) FROM ewallet_ledger WHERE ref_type = 'commission') AS ledger;
   ```

3. Repeat the sum idea for the other streams:

   ```sql
   SELECT (SELECT COALESCE(SUM(amount),0) FROM team_harvest_awards)   AS team_awards,
          (SELECT COALESCE(SUM(amount),0) FROM general_harvest_awards) AS general_awards,
          (SELECT COALESCE(SUM(amount),0) FROM binary_awards WHERE status = 'credited') AS pairs;
   ```

**You should see**

- Every **credited** row has a `ledger_id` and `ledger_amount` equal to `amount`.
- Every **skipped** row has `ledger_id` empty and `ledger_amount` empty.
- `awards = ledger` in step 2.
- Step 3's figures match the awards you recorded: team **₱1,000** (one award), general
  **₱4,000** (two awards — position #1 and position #2), pairs **₱100**.

### QA24-29 — Restore the settings and remove the QA data

| | |
|---|---|
| **What it proves** | You can return the system to its pre-test state without touching the seeded staff, catalogue or settings. |
| **Before** | Every other check is finished and its evidence saved. |
| **Where** | Settings → Commissions; Catalogue → Packages; phpMyAdmin; PowerShell |
| **Evidence** | `QA24-29-settings-restored.png`, `QA24-29-users-after-delete.png`, `QA24-29-reset.txt`, `QA24-29-reconcile.txt` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Steps**

1. Settings → **Commissions**: restore every value you wrote in QA24-07's table, including
   `package_binary_enabled`. Save and reload to confirm.
2. Catalogue → Packages → Package A: set `binary_enabled = 0`, `binary_points = 0`, save.
3. phpMyAdmin. **The plain `DELETE FROM users` does not work after a package-binary run.**
   `binary_volume.award_id` is `ON DELETE RESTRICT`, so MySQL refuses with error 1451
   (`Cannot delete or update a parent row … fk_bv_award`). Delete the volume rows first, then the
   accounts:

   ```sql
   DELETE bv FROM binary_volume bv
     JOIN binary_awards ba ON ba.id = bv.award_id
     JOIN users u ON u.id = ba.user_id
    WHERE u.username LIKE 'QA24\_%';
   DELETE FROM users WHERE username LIKE 'QA24\_%';
   ```

   Registration codes are **not** prefixed `QA24` — they are random (`KKWD-7PP5-53BF`). Delete the
   batch you issued by date and issuer instead:

   ```sql
   DELETE FROM reg_codes
    WHERE created_by = (SELECT id FROM users WHERE username = 'admin')
      AND created_at >= '<the date you started this run>';
   ```

   The member delete then cascades to activations, commissions, binary nodes/awards, harvest rows
   and ledger lines. If any other FK refuses, **do not disable foreign-key checks** — restore the
   backup instead and report which constraint blocked you.
4. Confirm the seeded accounts are untouched:

   ```sql
   SELECT username, role FROM users ORDER BY id LIMIT 10;
   ```

5. Return the General Harvest board to unarmed. The deletes above remove the awards, but
   `general_harvest_board` is a **singleton**: it is not a child of `users`, so the member delete
   cannot cascade it, and it stays armed (`baseline_n = 0`, `settled_n` above 0). That is what makes
   the next `wallet_reconcile.php` fail `board_bounds`. Do **not** edit the board by hand. Use the
   supported business reset — its *Return the General Harvest board to unarmed* step also clears the
   Team Harvest seed marker (`team_harvest_ledger_since`):

   ```powershell
   cd C:\laragon\www\ecowealth\system
   php bin\db_reset.php --dry-run
   php bin\db_reset.php --force --confirm=<your database>
   ```

   Replace `<your database>` with the name in phpMyAdmin (`ecowealth_sneat`, or your QA schema). The
   dry run writes nothing; the real run exits **0** and prints *Reset complete and verified*, with the
   step *Return the General Harvest board to unarmed* marked `OK`.

   A business reset deletes **every member and every business row** (it keeps the staff accounts, the
   catalogue and the settings). On a copy whose demo members must survive, restore the pre-run backup
   from §3.4 instead — that also returns the board to unarmed. Either way, the board must read
   `NULL, NULL, NULL, 0` before step 6:

   ```sql
   SELECT baseline_n, armed_at, armed_by, settled_n FROM general_harvest_board;
   SELECT COUNT(*) FROM general_harvest_awards;
   ```

6. Run `php bin\wallet_reconcile.php` once more.

**You should see**

- All settings back to their recorded **before** values; Package A binary off.
- No `QA24…` users or run-issued codes remain; the staff accounts are still there. A copy that keeps
  its demo members (the backup-restore path in step 5) keeps them too.
- The board is unarmed — `baseline_n`, `armed_at` and `armed_by` NULL, `settled_n` **0** — and
  `general_harvest_awards` is empty.
- Reconcile exits **0**.

**If it fails** — never delete rows that do not start with `QA24`; if a delete refuses because of a
foreign key, report the constraint name instead of disabling checks. The known one is
`binary_volume → binary_awards` (see step 3); a restore from the QA24-07 backup is always an
acceptable finish. Step 5's business reset is the supported exception to the delete rule above: it
removes every member and business row by design.

---

## 16. The totals to expect

Record these at the end of **Part 5**, before the late activations. Amounts are commission credited
to the member, not company revenue.

| Member | Direct | Indirect | Binary pair | Team Harvest | General Harvest | **Total** |
|---|---|---|---|---|---|---|
| `QA24_Sponsor` | ₱500 | ₱150 | ₱100 | ₱1,000 | ₱2,000 | **₱3,750** |
| `QA24_Member_A` | ₱500 | ₱100 | — | — | — | **₱600** |
| `QA24_Member_B` | ₱500 | — | — | — | — | **₱500** |
| `QA24_Member_C` | — | — | — | — | — | **₱0** |
| `admin` | skipped | — | skipped | — | — | **₱0** |

| Stream | Rows at end of Part 5 | Money |
|---|---|---|
| Direct referral | 3 (A→Sponsor, B→A, C→B) | ₱1,500 |
| Indirect referral (credited) | 3 (B→Sponsor 100, C→A 100, C→Sponsor 50) | ₱250 |
| Skipped rows (no money) | 4, all to `admin`: ₱500 direct (Sponsor's activation), ₱100 L2 (A), ₱50 L3 (B), ₱40 L4 (C) | ₱690 not paid |
| Binary package (pair) | 1 | ₱100 |
| Team Harvest | 1 (Sponsor) | ₱1,000 |
| General Harvest | 1 | ₱2,000 |
| **Total paid to members** | | **₱4,850** |

The member totals above are the ones to sign off; the late rows are listed in the next table.

### What the late activations add (Part 6)

Part 6 is not totalled: it exists to prove skips and zero-point behaviour, and each step names what
it should produce. If you want to keep score:

| After | New rows | Money |
|---|---|---|
| QA24-05 (D registers) side effect | C qualifies (queue reaches 7) → the General Harvest board pays position #2 (`QA24_Member_A`) | +₱2,000 to A |
| QA24-12 (D activates) | C ₱500 direct, B ₱100 L2, A ₱50 L3, Sponsor ₱40 L4 — no skipped row | +₱690 to members |
| QA24-06 (no-package sponsor) | NoPkg_Sponsor skipped ₱500, Sponsor ₱100 L2, admin ₱50 L3 skipped | +₱100 to Sponsor |
| QA24-18 (zero points) | Zero1–3 each pay Sponsor ₱500 direct; no volume, no pair | +₱1,500 to Sponsor |

This is why the sign-off records the **Part 5** totals: they are the stable, reproducible numbers.

---

## 17. SQL appendix

Every query in this document is copy-paste. The pieces you may want to reuse:

### 17.1 Look up an id by username

```sql
SELECT id, username, role, member_status, package_id FROM users WHERE username = 'QA24_Sponsor';
```

### 17.2 Everything a member earned, with status

```sql
SELECT c.type, c.level, s.username AS source, c.amount, c.status, c.reason, c.created_at
  FROM commissions c
  JOIN users u ON u.id = c.user_id
  JOIN users s ON s.id = c.source_user_id
 WHERE u.username = 'QA24_Sponsor'
 ORDER BY c.id;
```

### 17.3 A member's wallet movements

```sql
SELECT l.type, l.amount, l.ref_type, l.ref_id, l.note, l.created_at
  FROM ewallet_ledger l
  JOIN users u ON u.id = l.user_id
 WHERE u.username = 'QA24_Sponsor'
 ORDER BY l.id;
```

### 17.4 The binary node of a member

```sql
SELECT n.*, up.username AS upline
  FROM binary_nodes n
  LEFT JOIN users up ON up.id = n.upline_id
  JOIN users u ON u.id = n.user_id
 WHERE u.username = 'QA24_Member_B';
```

### 17.5 Every package activation and its binary stamp

```sql
SELECT a.id, u.username, a.status, a.binary_points, a.binary_total, a.binary_processed_at
  FROM activations a
  JOIN users u ON u.id = a.user_id
 ORDER BY a.id;
```

---

## 18. When something looks wrong

| What you see | What it usually means | What to do |
|---|---|---|
| A menu item in this document is missing | Wrong role signed in | Check the top-right name; sign in as `admin` or the named member |
| `/register` says the binary upline is not placed | The upline has no node yet | Register the upline first; check its **Binary placement** card |
| "This side is already taken" | Leftover data from an earlier attempt | Use the free side the form shows, and note the deviation |
| A member stays **Pending** after a code | The code was not validated, or the mode was Standard | Re-register with **Registration code** and press Validate first |
| Pair award missing | One side has no points (activation before QA24-08) | Restore the backup and restart from Part 1 |
| Queue is empty | Never seeded | `php bin\harvest_queue_seed.php` once, then re-query |
| Board refuses to arm | Board not seeded/armed | `php bin\general_harvest_arm.php`, then re-check |
| Reconcile reports a mismatch | A real defect, or a manual edit | Stop; save the output; report as Blocker |
| A proof will not upload | File too big or wrong type | Use a small PNG (< 2 MB) |
| Page shows **500** | Server error | Save the URL and screenshot; mark that check **Fail**; continue if the next check does not depend on it |

---

## 19. Defect log

Copy this table into your report and add one row per defect.

| ID | Check | Severity | Steps to reproduce | What happened | What you expected | Evidence | Status |
|---|---|---|---|---|---|---|---|
| QA24-D1 | | | | | | `evidence/phase-24/QA24-…` | Open |

**Severity**

- **Blocker** — you cannot continue, or money moved that should not have. Stop and report.
- **Major** — a wrong amount, a wrong status, or a payment that is missing or duplicated.
- **Minor** — a wrong label, a stale count, a filter that forgets its value.
- **Cosmetic** — spacing, colour, wording.

Report; do not fix. Include the member name, the award or ledger id, the amount and the URL — that
is what makes a defect actionable.

---

## 20. Sign-off

| | |
|---|---|
| **Tester** | |
| **Date(s)** | |
| **Build / branch** | |
| **Checks** | ☐ 29/29 Pass ☐ Fail (list IDs) ☐ Blocked (list IDs) |
| **Totals recorded (Part 5)** | `QA24_Sponsor` ₱______ · `QA24_Member_A` ₱______ · `QA24_Member_B` ₱______ |
| **Automated** | `php tests\run.php` ______ · `powershell -NoProfile -File tests\smoke.ps1` ______ · `php bin\wallet_reconcile.php` exit ______ |
| **Evidence** | `docs\qa\evidence\phase-24\` (_____ files) |
| **Decision** | ☐ Approved ☐ Approved with notes ☐ Open (blocker/major) |

Hand the completed sheet and the evidence folder to the project lead, who records the sign-off in
`docs/phase-log.md`.

---

## 21. Check index

| Check | One line |
|---|---|
| QA24-01 | Standard members register free and are placed with no points |
| QA24-02 | Codes create active members; three anchors and the pair are born |
| QA24-03 | Invalid, used and expired codes are refused |
| QA24-04 | A proof waits, is approved once, and pays the ladder |
| QA24-05 | A rejected proof returns the member to pending with a reason |
| QA24-06 | A sponsor without a package is skipped, never paid |
| QA24-07 | The commission and Harvest settings are recorded and applied |
| QA24-08 | Package A carries 1,000 binary points and the stream is on |
| QA24-09 | Activation 1 pays the direct referral |
| QA24-10 | Activation 2 pays direct + level 2 |
| QA24-11 | Activation 3 pays direct + levels 2–3 |
| QA24-12 | Activation 4 reaches level 4 and skips the company root |
| QA24-13 | The member's earnings breakdown and CSV |
| QA24-14 | The admin commission ledger, trace and export |
| QA24-15 | The binary placement is exactly what was chosen |
| QA24-16 | The pair pays ₱100 once, into the withdrawable wallet |
| QA24-17 | The binary ledger shows the package source |
| QA24-18 | Zero points, a disabled package or a closed switch stamps zeros |
| QA24-19 | The Harvest queue: who, in what order, with what direct count |
| QA24-20 | Team Harvest level 1 pays ₱1,000 |
| QA24-21 | The member Team Harvest statement |
| QA24-22 | The admin Team Harvest ledger |
| QA24-23 | The board arms and the first position pays ₱2,000 |
| QA24-24 | The General Harvest credit lands in the wallet |
| QA24-25 | The position and progress view |
| QA24-26 | The admin General Harvest ledger |
| QA24-27 | Wallet reconciliation is clean |
| QA24-28 | Awards and ledger tie out |
| QA24-29 | Settings restored, QA data removed |
