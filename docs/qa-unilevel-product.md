# EcoWealth — unilevel repeat-purchase bonus: a beginner's manual QA

**What this document is.** A step-by-step browser test of the **unilevel repeat-purchase bonus**: the
extra money a shop product pays out when it is bought and the order is paid, and the **Team chain**
income a member earns when their own team buys that product.

**Who it is for.** Someone who has never used EcoWealth before. You do not need to know PHP, SQL, the
database, or how the code is organised. Every check is done in a normal browser, through the same
screens a real administrator and a real member use.

**What you will do.** Set up one product with a bonus ladder, buy it once as a member, and then follow
the money through the buyer's order, the two uplines' wallets and statements, and the administrator's
order and commission pages. You will compare every screen with the expected result and record
**Pass**, **Fail** or **Blocked**.

**Time.** About **90–120 minutes** for the required checks, plus **30–40 minutes** for the optional
ones. Do not rush the setup; most false failures come from a product or an account that was not
prepared exactly as described.

| | |
|---|---|
| **Application** | EcoWealth, Phase 17 (the unilevel repeat-purchase release) |
| **Address** | `http://localhost/ecowealth/system/` |
| **Environment** | A **local development copy only** — never the live site |
| **Required checks** | **14** (A1–A3, B1–B4, C1–C5, D1–D2) |
| **Optional checks** | **4** (E1–E4) |
| **Evidence** | Create `docs/qa/evidence/unilevel-product/` and save your screenshots there |
| **Written** | 13 November 2026, against the Phase 17 build; member copy revised to the **Team chain** voice on 14 November 2026 |

> ### Stop — read this before you click anything
>
> This walkthrough **creates a product, places a real paid order in the test database, and moves test
> money into three e-wallets**. It also changes global commission settings.
>
> - Run it **only** on the local development copy (or a copy you are allowed to throw away).
> - **Never** run it on the live site or with a real customer's account.
> - The money is not real, but the database rows are. The last optional check (E4) reverses the bonus
>   and refunds the buyer; if you skip it, the test copy keeps the test order and the test money.
> - If you are not sure which copy you are looking at, **stop and ask the system owner** before you
>   continue.

---

## Route map

| Part | Checks | What it proves | Time |
|---|---|---|---|
| **Setup** | S1–S5 | You have the right people, the right chain, enough test money, and a record of the settings to restore | 15 min |
| **A — The admin sets it up** | A1–A3 | An operator can give a product a ladder, switch it on, and turn on the global bonus stream | 15 min |
| **B — What the buyer sees** | B1–B4 | The offer is visible before buying, the preview is honest, and the paid order reports the right total | 25 min |
| **C — What the uplines see** | C1–C5 | The two uplines are paid into their withdrawable wallets and can explain every row; the buyer earns nothing on their own purchase | 25 min |
| **D — What the admin can prove** | D1–D2 | The administrator can see exactly who was paid, who was skipped and why | 10 min |
| **E — Optional** | E1–E4 | The master switch hides the offer, an unconfigured product pays nothing, impossible ladders are refused, and a cancellation reverses the bonus | 30–40 min |

**Contents.** [§1 What you are testing](#1-what-you-are-testing-in-plain-english) ·
[§2 Before you start](#2-before-you-start) · [§3 Setup gates](#3-setup-gates-s1s5) ·
[§4 Admin setup](#4-the-administrator-sets-up-the-product-a1a3) ·
[§5 What the buyer sees](#5-what-the-buyer-sees-b1b4) ·
[§6 What the uplines see](#6-what-the-uplines-see-c1c5) ·
[§7 What the admin can prove](#7-what-the-administrator-can-prove-d1d2) ·
[§8 Optional checks](#8-optional-checks-e1e4) · [§9 Clean-up](#9-clean-up-and-restore) ·
[§10 Results sheet](#10-results-sheet) · [§11 Troubleshooting](#11-if-something-doesnt-match) ·
[§12 Glossary](#12-glossary) · [Appendix A](#appendix-a--the-numbers-in-one-place) ·
[Appendix B](#appendix-b--evidence-and-defect-reports)

---

## 1. What you are testing, in plain English

### 1.1 The idea in one minute

EcoWealth has **two different bonus programmes**:

- **Package commissions** are paid when a member activates a membership package. They are usually a
  one-time event.
- **Repeat-purchase bonuses** are paid **every time** a member buys a shop product that carries a
  repeat-purchase **ladder**.

This document is only about the second one.

A **ladder** is a list of ten percentages attached to one product. When an order containing that
product is **paid**, part of the product's **bonus base** is paid to the buyer's **sponsor chain**:

- **Level 1** is the buyer's own sponsor.
- **Level 2** is the sponsor's sponsor.
- **Level 3** is the sponsor's sponsor's sponsor, and so on up to level 10.

Each member has exactly **one sponsor**, so the sponsors form a single line. That is what **unilevel**
means: one line, not a binary tree.

> **The member's view is Team chain directed.** On the member's own screens the same line is called
> their **Team chain**: Level 1 is the members they personally sponsored, Level 2 their recruits, and
> so on. The screens answer *"what do I earn when my Team chain buys?"*, never *"what does my sponsor
> earn when I buy?"*. This manual keeps the technical words *sponsor chain* and *upline* when it
> explains the engine's upward walk, but every member **repeat-purchase** screen must say **Team
> chain** and must never show the words *sponsor chain*.

The **bonus base** is normally the product's **P.V.** ("point value"); if the product has no P.V., the
base is its retail price.

Two rules matter more than anything else:

1. **The money is paid when the order is paid** — not when it is placed, not when it is shipped.
2. **The buyer never receives their own bonus.** The money goes only to the uplines above the buyer.

"Repeat purchase" simply means the bonus is paid again on the next paid order of the same product. It
is not a one-time activation bonus.

### 1.2 The four people in this walkthrough

The walkthrough uses the demo accounts that ship with the development copy. If your copy has them,
use them exactly as they are. If it does not, read §2.2 before you continue.

| Role | Default account | Why this person |
|---|---|---|
| **Buyer** | `peter` | Buys one unit of the test product and pays with the e-wallet. |
| **Level 1** | `maria` | Peter's sponsor. Receives 5% of the bonus base. |
| **Level 2** | `juan` | Maria's sponsor. Receives 5% of the bonus base. |
| **Level 3 (company)** | `admin` | Juan's sponsor, and the company account. The company is **never paid**, so this level is recorded as **Skipped**. |

The chain is:

```text
peter  →  maria  →  juan  →  admin (company)
buyer     level 1   level 2   level 3 — skipped
```

The arrow means **"is sponsored by"**: peter is sponsored by maria, maria is sponsored by juan, and
juan is sponsored by the company account (`admin`).

### 1.3 Why the product page says "up to ₱72.50" but the order says ₱25.00

This is the one thing that confuses almost everyone, so read it once before you start.

The test product has a **₱250.00 bonus base** and this ladder:

```text
Level  1   2   3   4   5   6   7   8   9   10
Rate   5%  5%  5%  3%  3%  2%  2%  2%  1%   1%   = 29%
```

Twenty-nine percent of ₱250.00 is **₱72.50**. That is the **most** the ladder can pay on one unit, and
it is the number the product page and the checkout show.

But a level is paid only if there is a **real, eligible person** on it. In this walkthrough:

- Level 1 is maria: **₱12.50** (5% of ₱250.00).
- Level 2 is juan: **₱12.50** (5% of ₱250.00).
- Level 3 is the company account: **₱12.50 is due, but the company is never paid**, so the row is
  recorded as **Skipped**. The money is **not** moved to maria, juan, or anyone else.
- Levels 4–10 have nobody on them.

So the order actually pays **₱12.50 + ₱12.50 = ₱25.00**.

Both numbers are correct:

| Number | Where you see it | What it means |
|---|---|---|
| **₱72.50** | Product page, checkout | The maximum this product's ladder can pay on one unit |
| **₱25.00** | Paid order page, admin order, admin ledger | What this order actually paid, after the real chain and eligibility |
| **₱12.50 each** | Maria's and Juan's wallets | Their level's share |
| **₱12.50 skipped** | Admin order, level 3 | Due, but the company account is not a recipient |

The "**up to**" wording on the product page and the checkout is deliberate. It is a preview, not a
promise.

### 1.4 "Active" is a monthly purchase rule, not a job title

The repeat-purchase bonus has a second gate called the **monthly Active rule**:

- The **month that just ended** decides whether an upline is **Active this month**.
- If the upline bought at least **₱500.00** of products in that previous month, they are Active this
  month and can be paid.
- If they bought less than that, they are **Not Active**; any level that falls on them is **Skipped**,
  and the money is not moved to another upline.
- If there is **no record at all** for them in the previous month, they are treated as **Active**. This
  is the "missing flag counts as Active" rule, and it is why a fresh development copy can pay the demo
  uplines without any history.

The member's **Your Active status** panel shows exactly which case applies to them, in plain words.
Before you buy, both maria and juan must show **Active for the current month** (§3, S3).

### 1.5 What this walkthrough does **not** cover

This is the everyday product experience plus the mistakes an operator is most likely to make. It does
not try to repeat the 57 surgical checks in the deep Phase 17 pack
([`qa/phase-17-unilevel-repeat-purchase.md`](qa/phase-17-unilevel-repeat-purchase.md)). In particular,
this walkthrough does **not** cover:

- package commissions (the activation bonuses on the Packages page);
- manual GCash / Maya / bank payments and proof verification;
- disabled or suspended uplines, other than the company account;
- an upline who is **Not Active** and is skipped (the optional check E1 is about the global switch, not
  the monthly rule);
- the monthly close job, the closed-month snapshots, and the operations doctor;
- CSV exports, the reports desk and the admin dashboard KPIs;
- the 390 px mobile layout;
- multi-product orders and partial reversals.

Those are real and important, and they are covered in the Phase 17 pack. This document is the
"first day with the feature" path: one product, one order, one clean story.

---

## 2. Before you start

### 2.1 Your checklist

Tick every line before you begin. If any line is false, fix it first; otherwise later checks will fail
for reasons that have nothing to do with the feature.

- [x] The application is running at `http://localhost/ecowealth/system/` (ask the owner to start
      Laragon and click **Start All** if the page does not load).
- [x] I can sign in as **admin**.
- [x] I have the **three member accounts** described in §1.2, or I have read §2.2 and know how to
      substitute them.
- [x] I am on a **test copy**, not the live site.
- [x] I have **90–120 minutes** for the required checks.
- [x] I have created `docs/qa/evidence/unilevel-product/` for screenshots.
- [x] I have read §1.3 and understand why the preview and the order total differ.
- [x] I have written down the current repeat-purchase settings (§3, S5), because I must restore them
      at the end.

> The passwords in this guide (`Admin@123` for the administrator and `Demo@1234` for the demo members)
> are the development seed values. If they have been changed on your copy, use the passwords the system
> owner gave you; nothing else in the walkthrough changes.

### 2.2 Find and confirm the four people

You need **one buyer with exactly two paying uplines above them**, and the second upline's sponsor
must be the **company account** (which is never paid). That is the shape this guide's numbers assume.

1. Sign in as `admin`.
2. Click **Members** in the left menu.
3. Type `peter` in the search box (`Username, name, email, sponsor or code…`) and press Enter.
4. In peter's row, read the **Sponsor** column. It must say **@maria**.
5. Open peter's account (click the username) and confirm:
   - the line under his name says **Member** (not Staff or Admin);
   - the **Membership** card's **Status** tile says **Active associate** (or **Standard member** if he
     has no package) — the important part is that it is **not** Pending activation or Suspended;
   - the **Membership** card's **Sponsor** tile says **@maria**.
6. Repeat for `maria`: her **Sponsor** must be **@juan**, and she must be an active member.
7. Repeat for `juan`: his **Sponsor** must be **@admin** (shown as **Company** in some views), and he
   must be an active member.
8. You do **not** need to open the company account. Juan's **Sponsor** column already confirms the
   company is at level 3; opening it changes nothing for this test.

If the chain is different (for example, peter's sponsor is someone else, or juan's sponsor is a
regular member rather than the company), **stop**. The flow will still work, but the exact amounts in
this guide will not match. Ask the system owner for the demo copy, or fill in the worksheet in §2.5
with whichever people you have and read Appendix A.3 before you continue.

> **Why the company account matters.** The company account can sponsor members, but it is never a
> repeat-purchase recipient. The system records its level as **Skipped**, with the reason
> **company account is not paid**. If a regular member sits at level 3, that person **can** be paid
> (if they are active and eligible), and the order total will be higher than ₱25.00.

### 2.3 Set up your browser windows

You will switch between an administrator and three members. The simplest setup is:

- **Window A (normal window):** stays signed in as `admin`.
- **Window B (private / incognito window):** used for peter, then maria, then juan, signing out and
  back in each time.

| Browser | How to open a private window |
|---|---|
| Chrome / Edge | `Ctrl` + `Shift` + `N` |
| Firefox | `Ctrl` + `Shift` + `P` |

Do **not** open two private windows in the same browser and expect two different people to stay signed
in; they usually share the session. Use one private window and switch users, or use a second browser.

**How to tell which layout you are in:** look at the left menu. The member menu has **Shop**,
**Cart**, **My orders**, **Earnings** and **E-wallet**, and no admin items. The admin menu has
**Products**, **Members**, **Orders**, **Commissions**, **E-wallet monitor** and **Settings**.

**How to sign out:** scroll to the bottom of the left menu and click **Sign out**. It is a button, not
a link. On a narrow screen, open the menu first.

### 2.4 Safety rules

1. **Test copy only.** This walkthrough creates a product, a paid order, wallet movements and audit
   rows. Never do it on the live site.
2. **No real money and no real customers.** Use only the demo/test accounts named in this guide.
3. **Do not edit code or the database.** If you have a terminal open, close it; this test needs only a
   browser.
4. **Do not cancel the order until E4.** The rest of the checks need the order to stay paid. Cancelling
   reverses the bonus and refunds the buyer.
5. **Do not delete the test product.** The paid order references it, so deletion is refused. Switch it
   off at the end instead.
6. **Write down the "before" numbers** in §2.5 before you buy. If the copy has other test history, the
   change is what matters, not the absolute total.
7. **If a page shows an error, stop that check and capture it.** Do not retry the same button blindly;
   the error message and the address bar are the evidence.
8. **Keep your screenshots.** They are the only proof of what you saw.

### 2.5 The number worksheet

Fill in the **Before** column before you place the order (B3), and the **After** column immediately
after it. The "Expected change" is what this walkthrough's one-unit order must do.

| # | Number to record | Where to read it | Before | After | Expected change |
|---|---|---|---|---|---|
| 1 | Buyer's e-wallet **Total balance** | Peter → **E-wallet** | **₱1,700.00** | | **−₱300.00** |
| 2 | Level 1 **Withdrawable** | Maria → **E-wallet** | **₱1,020.00** | | **+₱12.50** |
| 3 | Level 2 **Withdrawable** | Juan → **E-wallet** | **₱396.00** | | **+₱12.50** |
| 4 | Level 1 **Total credited** | Maria → **Earnings** → **Repeat-purchase bonus** | **₱0.00** | | **+₱12.50** |
| 5 | Level 2 **Total credited** | Juan → **Earnings** → **Repeat-purchase bonus** | **₱0.00** | | **+₱12.50** |
| 6 | Buyer's **purchases so far** this month | Peter → **Earnings** → **Repeat-purchase bonus** | **₱0.00** | | **+₱300.00** |

> The exact totals in the checks assume a **clean copy** where these accounts have no earlier
> repeat-purchase history. If your copy already has some, the **change** must still be exactly as
> shown; the absolute total will be higher. Record the before value so you can tell the difference
> between "history" and "the feature is wrong".

> **Recorded 14 November 2026 (Asia/Manila), before any order.** The **After** column is read at B4,
> after the order is placed and paid; this setup run stops at S5, so that column is intentionally still
> blank.

### 2.6 How each check is laid out

Every check uses the same small table:

| | |
|---|---|
| **Why it matters** | One sentence about what would go wrong if this screen were wrong. |
| **Where** | The menu path and the URL, so you can find it again. |
| **Evidence** | The screenshot name to save. |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

Then **Do this** gives you the exact clicks, and **You should see** gives you the exact labels, amounts
and sentences to compare against. If everything in "You should see" is true, tick **Pass**. If the page
works but something is different, tick **Fail**. If you cannot run the check at all (missing account,
page down, earlier step impossible), tick **Blocked**.

Write the check ID (for example `B2`) in every screenshot name and in every defect report. It is the
fastest way for a developer to find the screen you mean.

---

## 3. Setup gates (S1–S5)

These are not scored checks. They are the preparation that makes the scored checks meaningful. Do them
in order.

> **Setup run — 14 November 2026 (Asia/Manila), local development copy.** All five gates passed. Every
> value below was read from the running screens, not assumed.

| Gate | Result | What the screen showed |
|---|---|---|
| **S1** | **Pass** | Signed in as `admin` on the **Dashboard**; the left menu showed **Products**, **Members**, **Orders**, **Commissions** and **Settings**. |
| **S2** | **Pass** | `peter` → **@maria**, `maria` → **@juan**, `juan` → **@admin**; all three are **Active associate** (Package A). |
| **S3** | **Pass** | `maria` and `juan` both showed the green badge **Active for November 2026**. |
| **S4** | **Pass** | Peter's **Total balance** was **₱1,700.00**, above the **₱300.00** order total, so no top-up was needed. |
| **S5** | **Pass** | All five settings recorded below; the master switch was **Off** before the run. |

### S1 — Sign in as the administrator

1. Open `http://localhost/ecowealth/system/login.php`.
2. In **Email or Username**, type `admin`.
3. In **Password**, type `Admin@123`.
4. Click **Login**.

**You are ready when:** the page opens on the admin **Dashboard**, and the left menu shows
**Products**, **Members**, **Orders**, **Settings** and **Commissions**. A member's menu would show
**Shop**, **My orders** and **Earnings** instead.

If you land on a member page instead, you are signed in as a member. Click **Sign out** at the bottom
of the left menu and sign in again as `admin`.

### S2 — Confirm the buyer and the two uplines

Use §2.2. You are ready when all of these are true:

- [x] `peter` is an active member and his sponsor is `maria`.
- [x] `maria` is an active member and her sponsor is `juan`.
- [x] `juan` is an active member and his sponsor is the company account (`admin`).
- [x] None of the three is suspended, pending or disabled.

If the status is not one of the active labels (Active associate or Standard member), stop and ask the
owner to activate the account. A pending membership is a different feature and will make the bonus skip
that person.

### S3 — Confirm both uplines are Active for this month

Do this **before** you buy. If an upline is Not Active, the level will be skipped and the expected
₱12.50 will not appear.

1. In Window B, sign in as `maria` (`Demo@1234` on the demo copy).
2. In the left menu, click **Earnings**.
3. Click the **Repeat-purchase bonus** pill (or open `/earnings?ledger=repeat` directly).
4. Read the card headed **Your Active status**.
5. Sign out and repeat for `juan`.

**You are ready when:** both pages show the green badge **Active for <current month>** (the month name
will be the current one on your machine, for example "Active for November 2026").

**Observed:** both badges were green and read **Active for November 2026**. Both panels also showed
**October 2026 purchases ₱0.00** and the missing-flag sentence quoted below, which is a **Pass**.

If a badge says **Not Active**, do **not** buy yet. Open §11, T1, and use the documented test-only
workaround (turn off **Require the monthly Active rule**), then come back to this gate. Restore the
switch at the end.

> The panel may say **"You have no counted purchases in <last month>, so no flag was set — a missing
> flag counts as Active for <this month>."** That is a **Pass** if the badge is green: a missing flag
> counts as Active. It is not an error.

### S4 — Make sure the buyer can pay

The order costs **₱300.00** and must be paid with the **e-wallet** so the bonus is triggered
immediately.

1. In Window B, sign out of juan and sign in as `peter`.
2. Click **E-wallet** in the left menu.
3. Read the **Total balance** card.
4. If the total is **at least ₱300.00**, you are ready; write the balance in worksheet row 1 and skip
   the rest of this gate.
5. If it is lower, go to Window A (still signed in as `admin`) and open **E-wallet monitor**.
6. In the **Members** tab, search `peter` and click **Open** on his row.
7. In the **Credit this wallet** card, enter **Amount** `1000.00` and **Note** `QA unilevel funding`.
8. Click **Credit wallet**, then confirm the dialog with **Credit wallet**.
9. Return to peter's **E-wallet**, confirm the total went up by exactly **₱1,000.00**, and write the
   **new** total in worksheet row 1.

**You are ready when:** peter's **Total balance** is at least **₱300.00** and the checkout will offer
**E-wallet**.

**Observed:** Peter's **Total balance** was **₱1,700.00** (withdrawable **₱900.00**, non-withdrawable
**₱800.00**), so steps 5–9 were not needed and no top-up was made.

> A top-up goes into the **non-withdrawable** bucket. That is correct: it can be spent in the shop but
> cannot be cashed out. Do not try to make it withdrawable.

### S5 — Record the current settings so you can restore them

1. In Window A, open **Settings** (`/admin/settings`).
2. Click the **Commissions** pill at the top, or scroll to the **Commissions** card.
3. Find the **Repeat-purchase bonus** sub-block.
4. Write down the current value of each of these five controls:

| Setting | Current value (recorded 14 November 2026) |
|---|---|
| **Repeat-purchase bonus enabled** | ☐ On ☑ Off |
| **Upline depth** | **10** levels |
| **Monthly personal-purchase minimum** | ₱ **500.00** |
| **Require the monthly Active rule** | ☑ On ☐ Off |
| **Reverse awards when a paid order is cancelled** | ☑ On ☐ Off |

**You are ready when:** you have all five values written down. You will put them back in §9.

**Recorded:** the five values above. The master switch was **Off** at this point; A3 turns it **On** for
the walkthrough, and §9 restores it to **Off**.

> Do **not** change them yet. A3 is where you set the values this test needs.

---

## 4. The administrator sets up the product (A1–A3)

> **Admin setup run — 14 November 2026, 13:09–13:24 (Asia/Manila), local development copy.** All three
> checks passed. The values below were read on the running pages, and the saved product and settings
> were read again after the run. Screenshots are in `docs/qa/evidence/unilevel-product/`.

| Check | Result | What the screen showed | Saved screenshot |
|---|---|---|---|
| **A1** | **Pass** | The product form carries the **Unilevel repeat-purchase bonus** card: badge **Levels 1–10**, switch **off**, base **Auto**, ten rates **5 / 5 / 5 / 3 / 3 / 2 / 2 / 2 / 1 / 1**, footer **29%** and **₱0.00**. | `4_a1_admin_product_new.jpeg` |
| **A2** | **Pass** | **Product created.** and the row shows **QA Repeat Product** with **Repeat bonus 29%**. Reopening it keeps price **₱300.00**, P.V. **₱250.00**, stock **50**, the ten rates and the switch **on**. One **Minor** cosmetic note on the base helper. | `4_a2_admin_product_new_complete.jpeg`, `4_a2_admin_product_new_created.jpeg` |
| **A3** | **Pass** | The **Repeat-purchase bonus** block is on: depth **10**, minimum **₱500.00**, Active rule **on**, reversal **on**, master switch **on**. A reload keeps them, and no sixth `unilevel*` key appears. | `4_a3_admin_settings_global_unilevel_product.jpeg` |

### A1 — The product form has the repeat-purchase card

| | |
|---|---|
| **Why it matters** | This is the only place an operator decides what a product pays. If the card is missing or misleading, no product can be configured correctly. |
| **Where** | Admin → **Products** → **New product** (`/admin/products/new`) |
| **Evidence** | `A1-product-form.png` |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

**Do this**

1. In Window A (signed in as `admin`), click **Products** in the left menu.
2. Click **New product**.
3. Type `QA Repeat Product` in **Product name**.
4. Type `QA-REPEAT-01` in **SKU**.
5. Type `Repeat-purchase QA product` in **Short description**.
6. Scroll down the left column to the card headed **Unilevel repeat-purchase bonus**. Do **not** change
   anything yet.
7. Read the card from top to bottom.

**You should see**

- The heading **Unilevel repeat-purchase bonus** and the badge **Levels 1–10**.
- A short paragraph explaining that the bonus is paid on a **paid** order, that level 1 is the direct
  sponsor and level 10 the tenth upline, and that a level left at **0** pays nothing but still counts —
  the next upline keeps its own level number (**no compression**).
- A switch labelled **Pay this product's ladder**. It is **off**.
- A **Bonus base** dropdown set to **Auto — P.V. when set, else the price**.
- A table with ten rows, **Level 1** to **Level 10**:
  - each row has a rate input with `%` after it, a **Pays per unit** amount and an **Upline** label;
  - level 1 says **Direct sponsor**; levels 2–10 say **Upline 2** … **Upline 10**;
  - the rate inputs are already filled with **5, 5, 5, 3, 3, 2, 2, 2, 1, 1** (the reference ladder);
  - the **Pays per unit** column reads **₱0.00** on every row, because no price or P.V. has been typed
    yet.
- A footer row **Total** reading **29%** and **₱0.00**, with **max 100%** on the right. The total badge
  may briefly show **29.000%** before the page's live preview updates; both mean twenty-nine percent.
- The buttons **Load reference ladder** and **Clear**, and the line
  **Reference: 5 / 5 / 5 / 3 / 3 / 2 / 2 / 2 / 1 / 1 — 29% in total.**
- A note at the bottom saying rates are read when an order is **paid**, so an edit applies to the next
  payment; the price and P.V. of an already-placed order are snapshotted at placement.
- **No** separate "direct" or "indirect" input, and **no** read-only level-1 row. All ten levels are
  edited in the same table. "Direct sponsor" is only a label on level 1.

> If the ten rate inputs are blank on your build, click **Load reference ladder**. The reference preset
> is the same ten rates, and the switch must still be **off** until A2.

**Observed:** signed in as `admin`, **Products → New product** opened the form at `/admin/products/new`.
`QA Repeat Product`, `QA-REPEAT-01` and `Repeat-purchase QA product` were typed, and the card
**Unilevel repeat-purchase bonus** showed the badge **Levels 1–10** with the paragraph about a **paid**
order, level 1 as the direct sponsor, level 10 as the tenth upline, and a level left at **0** paying
nothing without compression. The switch **Pay this product's ladder** was **off**, **Bonus base** was
**Auto — P.V. when set, else the price**, and the helper under it read
**This unit's base is ₱0.00 (price) (price).** (the duplicated basis is the cosmetic note recorded
under A2). The ten rows already carried **5, 5, 5, 3, 3, 2, 2, 2, 1, 1**, every **Pays per unit** cell
read **₱0.00**, and the **Upline** column read **Direct sponsor**, **Upline 2** … **Upline 10**. The
footer read **29.000%** on first paint and **29%** once the page's live preview ran, with **₱0.00** and
**max 100%**; the line **Reference: 5 / 5 / 5 / 3 / 3 / 2 / 2 / 2 / 1 / 1 — 29% in total.** and both
buttons were present. There was no separate **direct** or **indirect** input and no read-only level-1
row: all ten levels sit in the same table, and **Direct sponsor** is only a label on level 1.
Screenshot: `docs/qa/evidence/unilevel-product/4_a1_admin_product_new.jpeg`.

**If it fails:** do not try to make the card appear by saving. Capture the page and stop. The rest of
the walkthrough depends on this card.

### A2 — Save the product, switch it on, and see the badge

| | |
|---|---|
| **Why it matters** | This is the whole configuration path: set the base, load the ladder, switch the product on, and see the result in the catalogue. |
| **Where** | Same form → **Create product** → `/admin/products` → the product's **Edit** page |
| **Evidence** | `A2-product-saved.png`, `A2-product-list.png` |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

**Do this**

1. In the **Pricing & stock** card on the right, enter:
   - **Price** `300.00`;
   - **Point value (P.V.)** `250.00`;
   - **Stock on hand** `50`;
   - leave **Status** on **Active — visible in the shop**.
2. Look back at the **Unilevel repeat-purchase bonus** card. The helper under **Bonus base** should now
   read **This unit's base is ₱250.00 (P.V.)**, and the **Pays per unit** column should change to:
   **₱12.50, ₱12.50, ₱12.50, ₱7.50, ₱7.50, ₱5.00, ₱5.00, ₱5.00, ₱2.50, ₱2.50**.
   The footer should read **29%** (or 29.000%) and **₱72.50**.
   If the numbers do not update while you type, carry on with steps 3–4 and reopen the product after it
   is created; the stored values are the truth.
   **Minor cosmetic note:** the live preview may print the basis twice, for example
   `This unit's base is ₱250.00 (P.V.) (P.V.)`. If you see that, record it as a **Minor** wording
   defect and continue; the amount and the basis are correct, and the stored product is not affected.
3. Turn the **Pay this product's ladder** switch **on**.
4. Click **Create product** at the bottom of the form.
5. Read the green confirmation at the top of the products page.
6. Find `QA Repeat Product` in the **Catalogue** table. If the list is long, type `QA Repeat Product`
   in the search box next to the filters and click **Filter**.
7. In the filter row, select **Repeat bonus — on** and click **Filter**; then select
   **Repeat bonus — off** and click **Filter** again.
8. Click the product's name (or the pencil **Edit** icon), read the form again, and then click
   **Back to products** without changing anything.

**You should see**

- A green confirmation: **Product created.**
- The product row shows the name **QA Repeat Product**, SKU **QA-REPEAT-01**, price **₱300.00**,
  stock **50** and the badge **Repeat bonus 29%**.
- With **Repeat bonus — on**, the product is in the list. With **Repeat bonus — off**, it is not.
- When you reopen the product:
  - **Price** `300.00`, **P.V.** `250.00`, **Stock** `50`, **Status** Active;
  - **Bonus base** still **Auto**, the helper still **₱250.00 (P.V.)**;
  - the ten rates still **5, 5, 5, 3, 3, 2, 2, 2, 1, 1**;
  - the switch still **on**;
  - the footer still **29%** (or 29.000%) and **₱72.50**.

**Observed:** **Price** `300.00`, **P.V.** `250.00` and **Stock** `50` were entered, and **Status** stayed
**Active — visible in the shop**. The helper changed to **This unit's base is ₱250.00 (P.V.) …**,
**Pays per unit** became **₱12.50, ₱12.50, ₱12.50, ₱7.50, ₱7.50, ₱5.00, ₱5.00, ₱5.00, ₱2.50, ₱2.50**,
and the footer read **29%** and **₱72.50**. With **Pay this product's ladder** **on**, **Create product**
returned the green **Product created.**, and the row showed **QA Repeat Product**, SKU **QA-REPEAT-01**,
price **₱300.00**, stock **50** (**50 available**), status **Active**, badge **Repeat bonus 29%**.
Filtering the **Catalogue** with **Repeat bonus — on** left exactly that row (**Showing 1–1 of 1**); with
**Repeat bonus — off** the list read **No products match these filters**. Reopening the product
(step 8) showed **Price** `300.00`, **P.V.** `250.00`, **Stock** `50`, status **Active**, **Bonus base**
**Auto**, the helper **₱250.00 (P.V.)**, the ten rates **5, 5, 5, 3, 3, 2, 2, 2, 1, 1**, the switch
**on**, and the footer **29.000%** and **₱72.50**; nothing was changed. Screenshots:
`docs/qa/evidence/unilevel-product/4_a2_admin_product_new_complete.jpeg` and
`4_a2_admin_product_new_created.jpeg`.

**Minor (cosmetic) — recorded, not a failure.** While the live preview ran, the **Bonus base** helper
printed the basis twice: **This unit's base is ₱250.00 (P.V.) (price).** The trailing **price** is the
basis the form was drawn with before the P.V. was typed, and the preview then added the **(P.V.)**. This
is the duplicate-basis wording described in step 2 (there written **… (P.V.) (P.V.)**). The amount and
the basis are correct, the stored product is unaffected, and the check is recorded as **Pass** with this
**Minor** wording note, per Appendix B.2.

**If the badge says `Repeat bonus 0%`:** the ladder did not save. Reopen the product, check the ten
rates, and save again. Do not continue until the badge says **29%**.

### A3 — Turn on the global bonus stream

| | |
|---|---|
| **Why it matters** | A product can be fully configured and still pay nothing if the global master switch is off. Both switches must be on. |
| **Where** | Admin → **Settings** → **Commissions** (`/admin/settings#settings-commissions`) |
| **Evidence** | `A3-settings.png` |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

**Do this**

1. In Window A, click **Settings** in the left menu.
2. Click the **Commissions** pill, or scroll to the **Commissions** card.
3. Find the **Repeat-purchase bonus** sub-block, below the referral commission fields.
4. Set:
   - **Repeat-purchase bonus enabled** → **on**;
   - **Upline depth** → `10` (suffix **levels**);
   - **Monthly personal-purchase minimum** → `500.00`;
   - **Require the monthly Active rule** → **on**, **unless §11 T1 told you to turn it off for this
     test**; in that case leave it **off** and restore it in §9;
   - **Reverse awards when a paid order is cancelled** → **on**.
5. Click **Save settings** at the bottom of the **Commissions** card.
6. Read the confirmation banner, then reload the page and read the block again.

**You should see**

- The sub-block heading **Repeat-purchase bonus** and the badge **Levels 1–10, per product**.
- The paragraph explaining that the bonus is paid on every **paid shop order** to the buyer's sponsor
  chain, at the ladder each product carries, and that the per-product ladder is edited on the product
  form.
- The five controls above, with the values you just set.
- A green banner such as **Commissions: 4 settings saved.** (The number is how many values actually
  changed; the word **Commissions** and the green tone are what matter.)
- If nothing changed, the banner says **No changes to save.** — that is **not** a failure.
- **No sixth `unilevel*` key** is shown. In particular, `unilevel_ledger_since` must never appear on
  this page.

**Observed:** **Settings → Commissions** opened with the **Commissions** pill active and the
**Repeat-purchase bonus** sub-block showing the badge **Levels 1–10, per product** and the paragraph
about every **paid shop order**, the per-product ladder, and the five values that apply to every
product. The five controls were **Repeat-purchase bonus enabled** on, **Upline depth** `10` levels,
**Monthly personal-purchase minimum** `500.00`, **Require the monthly Active rule** on, and **Reverse
awards when a paid order is cancelled** on. Only one value had to move — the master switch, **off → on**
— so the green confirmation read **Commissions: 1 setting saved.** The other four controls already held
the values recorded in S5 and were not rewritten. Reloading the page showed the same five values, so the
save was stored. No sixth `unilevel*` key appeared: in particular `unilevel_ledger_since` was not
rendered anywhere in the block. Screenshot:
`docs/qa/evidence/unilevel-product/4_a3_admin_settings_global_unilevel_product.jpeg`.

**Why both switches matter:** if either the global switch or the product switch is off, nothing is
paid. The product page, the catalogue badge and the checkout preview disappear, and a paid order is
stamped as processed with a zero total. The admin order page explains which case applies.

---

## 5. What the buyer sees (B1–B4)

From here on, do the checks in order. B1–B4 are one continuous journey: find the product, read the
offer, buy it, read the result.

### B1 — The catalogue shows the offer

| | |
|---|---|
| **Why it matters** | A repeat-purchase product must be identifiable in the shop before the member opens it. |
| **Where** | Member → **Shop** (`/shop`), signed in as `peter` |
| **Evidence** | `B1-shop-badge.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. In Window B, sign out of any member and sign in as `peter` (`Demo@1234` on the demo copy).
2. Click **Shop** in the left menu.
3. Type `QA Repeat Product` in the **Search products…** box and click **Search**.
4. Find the product card.

**You should see**

- A product card titled **QA Repeat Product**, with the SKU **QA-REPEAT-01** above the title and the
  price **₱300.00** at the bottom.
- A blue badge under the title reading **Up to 29% repeat bonus**.
- A **Details** button and an **Add to cart** button (the cart button is an icon on the card).
- Products without a repeat-purchase ladder do **not** show the badge. (You do not need to open them
  now; E2 checks one.)

**If the badge is missing:** the global switch (A3), the product switch (A2) or the product's active
status is wrong. Do not continue; fix the setup and reload the shop.

### B2 — The product page explains what the Team chain pays the reader

| | |
|---|---|
| **Why it matters** | This is the only place a member sees the full ladder, the base and the per-level amounts before buying. It must be complete, and it must be Team chain directed: it shows what the reader earns when their Team chain buys, and that their own purchase does not pay them. |
| **Where** | `/shop/{id}` — click **Details** on the product card |
| **Evidence** | `B2-product-panel.png` (take a full-page screenshot; the panel is at the bottom) |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. Click **Details** on the **QA Repeat Product** card.
2. Scroll to the bottom of the product card, below **About this product** and the **SKU** /
   **Availability** list.
3. Read the panel headed **Repeat-purchase bonus** from top to bottom.
4. Compare every rate and amount with the table below.

**You should see**

- The heading **Repeat-purchase bonus** and the blue badge **Up to 29%**.
- This sentence: **"Your Team chain is your repeat-purchase income. When someone in your Team chain
  buys this product, the level that buyer sits at pays you that level's share: Level 1 when a member
  you personally sponsored buys, Level 2 when their recruits buy, and so on up to Level 10. The full
  ladder pays up to 29.000% of the bonus base, one share per level."**
- **Bonus base ₱250.00 per unit (the product's P.V.).** Then: **"Each level below is what you earn when
  a Team chain member at that depth buys one unit; their cart quantity multiplies it."**
- **10 of 10 levels pay on this base.**
- Ten level tiles, in this exact order:

| Level | Label under the level | Rate | Amount |
|---|---|---|---|
| Level 1 | Your direct recruit | 5.000% | ₱12.50 |
| Level 2 | Your Team chain | 5.000% | ₱12.50 |
| Level 3 | Your Team chain | 5.000% | ₱12.50 |
| Level 4 | Your Team chain | 3.000% | ₱7.50 |
| Level 5 | Your Team chain | 3.000% | ₱7.50 |
| Level 6 | Your Team chain | 2.000% | ₱5.00 |
| Level 7 | Your Team chain | 2.000% | ₱5.00 |
| Level 8 | Your Team chain | 2.000% | ₱5.00 |
| Level 9 | Your Team chain | 1.000% | ₱2.50 |
| Level 10 | Your Team chain | 1.000% | ₱2.50 |

- The footer: **Total up to 29.000%** on the left and **₱72.50 per unit** on the right.
- The first explanatory paragraph: **"Across all ten levels. Your own share is the rate on the level
  where your Team chain member sits, and it is credited to your e-wallet when that order is paid, to an
  eligible member who is Active for the month."**
- The second explanatory paragraph: **"Meet the ₱500.00 monthly minimum to stay Active: your counted
  purchases last month decide this month, and only an Active member is paid. If a level falls on you
  while you are not Active, it is skipped and never moved to another level."**
- The third explanatory paragraph: **"Your own purchase does not pay you. It counts toward your monthly
  minimum; the purchases your Team chain makes are what pay your level income."**

**Words that must not appear as labels:** **cashback**, **direct bonus**, **indirect**, **sponsor
chain**. **Your direct recruit** is the Team chain relationship, not a bonus type. The words **rebate**
and **cashback** must not appear anywhere on this panel. The ten levels are one ladder; there is no
direct/indirect split.

> **"10 of 10 levels pay" does not mean ten people will be paid.** It means all ten rates on this
> product are above zero. The real chain is only three levels deep, and the company account at level 3
> is skipped. That is why the order will pay ₱25.00, not ₱72.50.

### B3 — The checkout preview says "up to" and pivots to the Team chain

| | |
|---|---|
| **Why it matters** | The preview is the last thing a buyer reads before paying. It states the fact of the order, then turns the reader toward the income their own Team chain can produce. |
| **Where** | Product page → **Add to cart** → **Cart** → **Proceed to checkout** (`/checkout`) |
| **Evidence** | `B3-checkout-preview.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. On the product page, leave **Quantity** at `1` and click **Add to cart**.
2. Click **Cart** in the left menu (or **View cart** on the product page).
3. Confirm the cart has one row for **QA Repeat Product**, quantity **1**, and a line total of
   **₱300.00**.
4. Click **Proceed to checkout**.
5. On the checkout page, read the **Order summary** card on the right.
6. Scroll down inside the summary: the repeat-purchase note is below the **E-wallet** balance note.
7. Fill the required delivery fields if they are empty: **Recipient name** `QA Tester`,
   **Street address** `123 QA Street`, **Billing name** `QA Tester`.
8. Confirm **E-wallet** is selected under **Payment method** and shows a balance of at least
   **₱300.00**.
9. Leave the terms checkbox ticked.
10. Click **Place order**.

**You should see**

- **Order summary:** a line for **QA Repeat Product** with **1 × ₱300.00**, then **Items 1**,
  **Subtotal ₱300.00**, **Shipping Free**, **Total ₱300.00**.
- **E-wallet** with the buyer's current balance and the line "**₱… of it is withdrawable.**"
- The repeat-purchase note, exactly:
  - **"Up to ₱72.50 is paid to the members above you when this order is paid."**
  - **"The same repeat-purchase ladder pays you when your Team chain buys these products: Level 1 for
    the members you personally sponsored, Level 2 for their recruits, up to Level 10. Keep your monthly
    minimum to stay Active, so your level income is credited when their order is paid."**
- The note never says **sponsor chain**, **rebate** or **cashback**.
- **Payment method:** the **E-wallet** option is selected. It shows the available balance. If the
  balance is below ₱300.00, the option is disabled; go back to S4 and credit the buyer.
- After **Place order**, the page moves to the order page with a green flash:
  **"Order placed and paid. Thank you!"**

**If the preview is missing:** the global switch, the product switch, or the product's ladder is not
active. Do not place the order; fix the setup first.

### B4 — The paid order reports the actual total, not the preview

| | |
|---|---|
| **Why it matters** | The buyer must see what the order actually paid, with no recipient names or per-upline amounts. The buyer must also see their own wallet debit. |
| **Where** | The order page (`/orders/{id}`) and Peter → **E-wallet** (`/wallet`) |
| **Evidence** | `B4-order-bonus.png`, `B4-buyer-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. On the order page you reached after placing the order, read the status card at the top.
2. Scroll to the right column, below the **Payment** card, and read the **Repeat-purchase bonus** card.
3. Compare the amount with **₱25.00**, not ₱72.50.
4. Click **E-wallet** in the left menu and read the **Total balance** card and the newest statement
   row.
5. Write the after value in worksheet row 1.

**You should see**

- The status card says **Payment received** (the order status is **Paid**).
- The order total is **₱300.00**.
- A **Repeat-purchase bonus** card with a gift icon, reading:
  - **"₱25.00 was paid to the members above you when this order was paid."**
  - **"Your Team chain is your earning side: when a member you sponsored buys these products, the same
    ladder credits your level's share to your e-wallet — Level 1 for your direct recruits, up to Level
    10. Keep your monthly minimum to stay Active. See your Team chain statement."**
- The card never says **sponsor chain**, and it carries **no recipient username** and **no
  per-member amount** anywhere. The buyer sees only the order total.
- The buyer's **E-wallet** shows a **Shop order** debit of **₱300.00**, and the total balance is
  exactly **₱300.00 lower** than the before value in worksheet row 1.

**Why ₱25.00 and not ₱72.50:** see §1.3. The preview shows the ladder's maximum; the order shows what
the real chain paid. The company account at level 3 is skipped, and its ₱12.50 is not moved.

**If the card is missing:** open the admin order page (D1) and read the **Repeat-purchase bonus** card
there. It will say **No bonus** and explain why: the order is not paid, the stream was off when it was
paid, no product had a ladder, or there was no sponsor chain. Do not guess; the admin card tells you.

---

## 6. What the uplines see (C1–C5)

The money has been paid. Now look at it from the two uplines' side, and from the buyer's own side.

### C1 — Level 1's wallet shows the credit

| | |
|---|---|
| **Why it matters** | The award must be real, withdrawable wallet money — not a number on a report. |
| **Where** | Maria → **E-wallet** (`/wallet`) |
| **Evidence** | `C1-maria-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. In Window B, sign out of peter.
2. Sign in as `maria`.
3. Click **E-wallet** in the left menu.
4. Read the three bucket cards at the top.
5. Read the newest statement row under **Statement**.
6. In the **Source** filter, select **Repeat-purchase bonus** and click **Filter**; then choose
   **All sources** to restore the full statement.
7. Write the after value in worksheet row 2.

**You should see**

- **Total balance** and **Withdrawable** each increased by exactly **₱12.50** compared with your before
  values. The **Non-withdrawable** card is unchanged.
- A statement row, newest first, with:
  - the date and time of the payment;
  - a green **Credit** badge;
  - the source **Repeat-purchase bonus**;
  - the note **"Repeat-purchase bonus — QA Repeat Product (level 1) — order EW…"**;
  - the amount **+₱12.50**;
  - the balance after the row, and the withdrawable balance after the row.
- With **Source → Repeat-purchase bonus**, only this row (and any other repeat-purchase credits already
  on the account) is shown.
- **Withdrawable** is where commissions land. The fact that this money is withdrawable, while an admin
  top-up is not, is the difference between earnings and spending money.

**If the wallet did not change:** open Maria's **Earnings → Repeat-purchase bonus** (C2) and read the
row's status and reason. A **Skipped** row is not money; the reason tells you why. A **Credited** row
with an unchanged wallet would be a real defect — record it.

### C2 — Level 1's repeat-purchase statement explains the row

| | |
|---|---|
| **Why it matters** | This is the Team chain earner's statement. It must match the wallet, name the buyer and the level, and explain the monthly Active rule. |
| **Where** | Maria → **Earnings** → **Repeat-purchase bonus** (`/earnings?ledger=repeat`) |
| **Evidence** | `C2-maria-statement.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. Still signed in as `maria`, click **Earnings** in the left menu.
2. Click the **Repeat-purchase bonus** pill.
3. Read the page from top to bottom: the four tiles, the **Your Active status** card, the
   **Repeat-purchase by level** card and the award row.
4. Write the after value in worksheet row 4.

**You should see**

- The page heading **Repeat-purchase bonus** and the subtitle **"Every repeat-purchase award your Team
  chain's paid purchases earned you."**
- Four tiles:
  - **Total credited**: **₱12.50** on a clean copy, with the hint **1 award · withdrawable**;
  - **This month**: **₱12.50** on a clean copy, with a relative hint such as **Last award 2 minutes
    ago**;
  - **Awards**: **1**, with the hint **Team chain purchases that credited your level**;
  - **Not paid**: **0**, with the hint **No level was skipped**.
- The **Your Active status** card:
  - the heading **Your Active status** and the subtitle **"The month that just ended decides this
    month; this month decides the next one."**;
  - a green badge **Active for <current month>**;
  - two columns: **<previous month> purchases** and **<current month> purchases so far**, with the
    threshold **₱500.00**;
  - a sentence that explains maria's status: because she met last month's threshold she is Active; or,
    if she is **Not Active**, the sentence says that a Team chain purchase reaching her level this
    month is skipped and never moved to another member;
  - the paragraph that says the member's own purchases never pay them a repeat-purchase bonus — they
    keep them Active so their Team chain's orders can pay them.
  - **If you used the T1 workaround** and the Active rule is **off**, the card shows the blue notice
    that the rule is off; the badge may still say **Not Active** because it records the member's
    history. That is expected for this test; the award row and the wallet are what matter, and §9
    restores the rule.
- The **Repeat-purchase by level** card now lists **maria's Team chain**, not the buyer's sponsor
  chain:
  - the badge **1 level award · ₱12.50**;
  - **Level 1** lists the members maria personally sponsored. The paid buyer, `peter`, shows
    **₱12.50**; any other level-1 member with no paid purchase shows **₱0.00**; the card's
    **Level total** is **₱12.50**;
  - **Levels 2–10** list their own members at **₱0.00**, or read **No Team chain members at this level
    yet** when the Team chain is not that deep; their level totals are **₱0.00**;
  - each level card carries its own pager, exactly like the genealogy **Team by level** card: a
    **Rows per page** control and **Showing 1–X of X** (for the level's whole roster, even when it
    fits on one page), with page links once a level holds more than ten members.
- The award row:
  - **Date**: the payment date;
  - **Buyer**: **@peter** (and his full name if the account has one);
  - **Product**: **QA Repeat Product** (and the SKU);
  - **Level**: **L1**;
  - **Base**: **₱250.00**;
  - **Rate**: **5.000%**;
  - **Amount**: **+₱12.50**;
  - **Status**: **Credited**;
  - **Order**: the **EW…** order number, as plain text (not a link — the order belongs to the buyer).
- **No "as buyer" statistic** anywhere on the page. Maria's own purchases are not earnings.

**If the tile says ₱0.00 or the row is missing:** check the admin order page (D1). A skipped row with a
reason is the likely cause. If the admin page shows a credited row and Maria's page does not, that is a
real defect.

### C3 — Level 2's statement and wallet show their own share

| | |
|---|---|
| **Why it matters** | Each level is paid separately. Level 2 must see a level-2 row and its own wallet credit, not level 1's. |
| **Where** | Juan → **Earnings** → **Repeat-purchase bonus** · Juan → **E-wallet** |
| **Evidence** | `C3-juan-statement.png`, `C3-juan-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. Sign out of maria and sign in as `juan`.
2. Open **Earnings** → **Repeat-purchase bonus**.
3. Read the four tiles and the award row.
4. Open **E-wallet** and read the newest statement row.
5. Write the after values in worksheet rows 3 and 5.

**You should see**

- The same four tiles as C2, with **₱12.50** credited and **1** award on a clean copy.
- **Repeat-purchase by level**: the card now reads **juan's Team chain** by level:
  - **Level 1** lists maria at **₱0.00** (no paid repeat purchase from her yet);
  - **Level 2** lists `peter` at **₱12.50** and any other level-2 member at **₱0.00**, with the
    level total **₱12.50**;
  - deeper levels are empty or show **No Team chain members at this level yet** at **₱0.00**.
- The award row shows **@peter** as the buyer, **L2** as the level, base **₱250.00**, rate **5.000%**,
  amount **+₱12.50**, status **Credited**.
- Juan's **E-wallet** shows **Withdrawable** up by exactly **₱12.50**, with a
  **Repeat-purchase bonus** row whose note says **(level 2)**.
- Juan does **not** see maria's level-1 amount as his own; each upline sees only their own level.

### C4 — The buyer's own statement is empty and explains why

| | |
|---|---|
| **Why it matters** | A buyer must never be shown their own purchase as earnings. This is the rule that prevents the most damaging misunderstanding of the feature. |
| **Where** | Peter → **Earnings** → **Repeat-purchase bonus** |
| **Evidence** | `C4-buyer-statement.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. Sign out of juan and sign in as `peter`.
2. Open **Earnings** → **Repeat-purchase bonus**.
3. Read the empty state and the **Your Active status** card.
4. Write the after value in worksheet row 6.

**You should see**

- **No award row** for peter's own order. The list shows the empty state:
  - **No repeat-purchase bonus yet**;
  - the explanation that a level's share is credited when someone in the member's Team chain buys a
    product with a ladder and the order is paid;
  - the sentence **"Your own purchase counts toward your monthly minimum, not your income."**
  - an **Open the shop** button.
- **No "as buyer" statistic** and no tile that counts peter's own spending as earnings.
- The **Your Active status** card:
  - peter's **purchases so far this month** increased by exactly **₱300.00** compared with the before
    value;
  - the progress bar is at **60%** on a clean copy (₱300.00 of the ₱500.00 threshold);
  - the sentence says that reaching the threshold this month makes peter Active **next** month, not
    this one;
  - the note that peter's own purchases never pay him a repeat-purchase bonus; they keep him Active so
    his Team chain's purchases can pay him.

**If peter has an award row:** that is a serious defect. The buyer must never be a recipient. Capture
the row and report it.

### C5 — The dashboard and membership page explain the status without offering an action

| | |
|---|---|
| **Why it matters** | The monthly Active status must be visible where the member already looks, and it must never look like something the member can "claim". |
| **Where** | Peter → **Dashboard** (`/dashboard`) · Peter → **Membership** (`/membership`) |
| **Evidence** | `C5-dashboard.png`, `C5-membership.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. Still signed in as `peter`, click **Dashboard** in the left menu.
2. Find the **Earnings** card and read the **Repeat-purchase bonus** block at the bottom of it.
3. Click **Membership** in the left menu and read the paragraph under the current-membership card.
4. Look for any **form**, **input**, **button** or **link** that would let peter "claim" or "activate"
   the repeat-purchase bonus.

**You should see**

On the **Dashboard**:

- A **Repeat-purchase bonus** heading with a badge **Active for <current month>** (or **Not Active**,
  matching C4's status card).
- **Earned from your Team chain** and **₱0.00** (peter has no Team chain awards yet).
- The sentence **"No awards yet — a Team chain order credits your level when it is paid."**
- The sentence **"Your own purchases keep you Active; your Team chain pays you. See the
  repeat-purchase statement."** with a link to `/earnings?ledger=repeat`.

On **Membership**:

- A **Repeat-purchase bonus** card with a gift icon.
- The badge **Active for <current month>**.
- A sentence beginning **"You are Active for <current month>: a Team chain purchase that reaches your
  level this month pays your share into your e-wallet."** (If peter is Not Active, the sentence says
  the level is skipped and never moved to another member.)
- The sentence **"Your own purchases decide this — not your rank — and they keep you Active so your
  Team chain's purchases can pay you."**
- **₱0.00 earned from your Team chain** on the right.

**Both pages:** no form, no input box and no button for the repeat-purchase stream. There is nothing to
claim; the money is paid automatically when a paid order reaches an eligible level.

> **"Active" here is the monthly purchase rule from §1.4, not employment status and not package rank.**
> Peter's purchase this month makes him Active for **next** month; it does not pay him anything today.

---

## 7. What the administrator can prove (D1–D2)

The buyer sees only the order total. The administrator sees the audit detail: who was paid, at which
level, on what base, and why a level was skipped.

### D1 — The admin order page shows every level and every reason

| | |
|---|---|
| **Why it matters** | This is the only screen where an administrator can see which upline received which amount, and why a level was skipped. Finance and support depend on it. |
| **Where** | Admin → **Orders** → search `EW…` → open the order |
| **Evidence** | `D1-admin-order.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. In Window A, sign in as `admin` if you are not already.
2. Click **Orders** in the left menu.
3. Type the order number from B4 (it starts with `EW`) in the **Search** box
   (`Order # or member…`) and press Enter.
4. Open the order.
5. Scroll to the **Repeat-purchase bonus** card, below the **Payment review** card in the left column.
6. Read the badge, the table and the footer.

**You should see**

- The card heading **Repeat-purchase bonus** and the badge **₱25.00 to the sponsor chain**.
- A table with these columns: **Lvl**, **Recipient**, **Product**, **Base**, **Rate**, **Amount**,
  **Status**. It contains exactly three rows:

| Lvl | Recipient | Product | Base | Rate | Amount | Status |
|---|---|---|---|---|---|---|
| L1 | @maria | QA Repeat Product | ₱250.00 | 5.000% | +₱12.50 | Credited |
| L2 | @juan | QA Repeat Product | ₱250.00 | 5.000% | +₱12.50 | Credited |
| L3 | @admin | QA Repeat Product | ₱250.00 | 5.000% | ₱12.50 | **Skipped** — **company account is not paid** |

- The footer **Credited to the sponsor chain** and **₱25.00**.
- A note: one row per order line, recipient and level; a skipped level keeps its number and no money
  is redirected; the buyer never receives a rebate.
- The card footer: **Order EW… · processed <date and time>**.
- The card is **read-only**: there is no edit, no delete and no "pay again" button.

**Why the level-3 row exists:** the company account is a real member of the sponsor chain, so the system
records the level and the amount that would have been due, marks it **Skipped**, and explains why. It
is an audit record, not money. The credited total is only the two green rows: ₱25.00.

**If there are more than three rows:** the real chain is deeper than the three people in this
walkthrough. The extra levels may be credited if they have eligible members. Stop and compare with
§2.2; the expected amounts in this guide assume exactly two uplines plus the company.

### D2 — The admin commission ledger shows the same money from the other side

| | |
|---|---|
| **Why it matters** | Finance must be able to find the same three rows in the commission ledger, filter them, and reconcile the total with the order. |
| **Where** | Admin → **Commissions** → **Repeat-purchase bonus** (`/admin/commissions?ledger=repeat`) |
| **Evidence** | `D2-admin-ledger.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. In Window A, click **Commissions** in the left menu.
2. Click the **Repeat-purchase bonus** pill.
3. Read the six stat tiles at the top.
4. Read the table. On a clean copy it has the three rows from D1.
5. In the **Product** filter, select **QA Repeat Product** and click **Filter**.
6. Change **Level** to **1**, click **Filter**, then click **Clear** to restore the full list.

**You should see**

- The page heading **Repeat-purchase bonus** and the subtitle **"Every award a product ladder paid on a
  paid order. One row per line, recipient and level 1–10."**
- Six tiles, on a clean copy:

| Tile | Value | Hint |
|---|---|---|
| **Total credited** | ₱25.00 | 2 members paid |
| **This month** | ₱25.00 | Since the 1st |
| **Awards** | 2 | Average ₱12.50 |
| **Recipients** | 2 | Uplines credited at least once |
| **Skipped** | 1 | Ineligible upline — no money moved |
| **Unrecovered** | ₱0.00 | 0 voided awards — shortfall after reversal |

- The table columns **Lvl**, **Recipient**, **Buyer**, **Product**, **Base**, **Rate**, **Amount**,
  **Status**, **Order**, **Awarded**, **Wallet**.
- The same three rows as D1:
  - **L1 @maria**, buyer **@peter**, base **₱250.00**, rate **5.000%**, **+₱12.50**, **Credited**;
  - **L2 @juan**, the same base and rate, **+₱12.50**, **Credited**;
  - **L3 @admin**, the same base and rate, **₱12.50** with the small text **not paid**,
    **Skipped** and the reason **company account is not paid**.
- The order number in each row links to the admin order page.
- With **Product → QA Repeat Product**, only these three rows remain on a clean copy.
- With **Level → 1**, only the maria row remains on a clean copy.
- **Clear** restores the full list.
- The **Closed months** card is a read-only history of the monthly close. On a clean copy it says
  **No month has been closed yet…**. You do not need to change it for this walkthrough.

**If the tiles show different numbers:** the copy may already have other repeat-purchase awards from
earlier tests. The **table rows for your order** and the **difference** of ₱25.00 are what matter. If
the table cannot be filtered down to your three rows, capture the filter and the row count.

---

## 8. Optional checks (E1–E4)

These are optional for a first pass, but they cover the four things most likely to go wrong in real
use: the master switch, a product without a ladder, an impossible ladder, and a cancellation. E4 also
returns the test money.

### E1 — The global switch hides the offer everywhere

| | |
|---|---|
| **Why it matters** | An operator must be able to stop the whole programme without editing every product. |
| **Where** | Admin → **Settings** → **Commissions** · Peter → **Shop** / product / **Checkout** |
| **Evidence** | `E1-master-off.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A |

**Do this**

1. As `admin`, open **Settings → Commissions → Repeat-purchase bonus**.
2. Turn **Repeat-purchase bonus enabled** **off** and click **Save settings**.
3. As `peter`, reload **Shop** and search for `QA Repeat Product`.
4. Open the product page and the **Checkout** page with one unit in the cart.
5. As `admin`, turn **Repeat-purchase bonus enabled** back **on** and save again.
6. As `peter`, reload the three pages.

**You should see**

- With the master switch **off**:
  - no **Up to 29% repeat bonus** badge on the catalogue card;
  - no **Repeat-purchase bonus** panel on the product page;
  - no **Up to ₱72.50 is paid to the members above you…** note on the checkout;
  - no error page and no broken layout — the product and the checkout still work normally.
- With the master switch **on** again: all three return exactly as they were.
- The admin product form still shows the ladder while the stream is off. The configuration is kept;
  only the member-facing offer is hidden.
- No order is placed and no money moves during this check.

**If the offer is still visible with the switch off:** the save did not apply. Reload the settings page
and confirm the switch is off before you conclude it is a defect.

### E2 — A product without a ladder has no offer

| | |
|---|---|
| **Why it matters** | The bonus is per product. A product with no ladder must not show a badge, a panel or a checkout preview. |
| **Where** | Member → **Shop** → a product with no **Repeat bonus** badge · **Checkout** |
| **Evidence** | `E2-no-ladder.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A |

**Do this**

1. As `peter`, open **Shop**.
2. Find any product that does **not** show the **Repeat bonus** badge (for example **Coffee**, if it is
   still unconfigured on your copy).
3. Open its product page.
4. Add only that product to a fresh cart and open **Checkout**.
5. Look for the repeat-purchase panel and the checkout note. Then remove it from the cart.

**You should see**

- The product card has **no** repeat-bonus badge.
- The product page has **no** **Repeat-purchase bonus** panel at the bottom of the product card.
- The checkout has **no** **Up to … is paid to the members above you** note, because no product in the
  cart carries a ladder.
- The product and the checkout work normally in every other way.

**If the cart also contains QA Repeat Product:** the preview will still appear for that configured
product. Use a cart containing only the unconfigured product for this check.

### E3 — Impossible ladders are refused, and the stored ladder is not changed

| | |
|---|---|
| **Why it matters** | A ladder that pays more than 100%, or a switched-on product that pays nothing, is a configuration error. The form must refuse it clearly and leave the saved product alone. |
| **Where** | Admin → **Products** → **QA Repeat Product** → **Edit** |
| **Evidence** | `E3-empty-error.png`, `E3-sum-error.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A |

**Do this**

1. As `admin`, open **Products**, find **QA Repeat Product**, and click its name to edit it.
2. Click **Clear** in the ladder card. All ten rate inputs become blank.
3. Leave the **Pay this product's ladder** switch **on**.
4. Click **Save changes**.
5. Read the error. Then, still on the form, type `100` in **Level 1** and `100` in **Level 2** (the
   other eight stay blank).
6. Click **Save changes** again.
7. Read the error. Then click **Load reference ladder**, confirm the total is back to **29%**, and
   click **Save changes**.
8. Go back to **Products** and confirm the badge still reads **Repeat bonus 29%**.

**You should see**

- First save (all blank, switch on): the form comes back with a red banner
  **Please fix the highlighted field and try again.** and the message on the switch:
  **"Turn the switch off or give at least one level a rate above 0."**
- Second save (100 + 100, total 200%): the form comes back with the message at the top of the card:
  **"The ladder totals 200% — the ten levels cannot add up to more than 100%."**
- In both cases, the values stay **as you typed**; they are not silently cleared or clamped.
- The saved product is **not** changed by either failed save. After you navigate away and back, the
  ladder is still the reference ladder and the list badge is still **Repeat bonus 29%**.
- After **Load reference ladder** and **Save changes**, the product is back to 29% and the list badge
  is unchanged. Leave the product switched **on**; no money moves in this check.

**If a failed save changes the stored ladder:** that is a real defect. Capture the form before and
after, and the list badge.

### E4 — Cancelling the paid order reverses the bonus

| | |
|---|---|
| **Why it matters** | A cancelled sale must not leave the bonus behind. The buyer must be refunded, the uplines must be debited, and every screen must show the reversal honestly. |
| **Where** | Admin → **Orders** → the paid order → **Cancel order** |
| **Evidence** | `E4-cancel-order.png`, `E4-maria-after-cancel.png`, `E4-admin-after-cancel.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A |

**Do this**

1. As `admin`, open the paid order from D1 (**Orders** → search the **EW…** number).
2. In the right column, find the **Cancel order** card.
3. Leave the **Reason** on **Cancelled by the administrator**.
4. Click **Cancel order**, then confirm the dialog with **Cancel order**.
5. Read the order status, the **Repeat-purchase bonus** card and the **Cancel order** area.
6. As `maria`, open **E-wallet** and **Earnings → Repeat-purchase bonus**.
7. As `juan`, open **E-wallet**.
8. As `peter`, open **My orders** → the order, and then **E-wallet**.
9. As `admin`, reopen **Commissions → Repeat-purchase bonus**.

**You should see**

- The order status is **Cancelled**.
- The admin order's **Repeat-purchase bonus** card now shows the badge **No bonus**, because the
  order is no longer in a paid status. Its paragraph reads **"Paid orders earn the repeat-purchase
  bonus. This order is `cancelled`."** The card footer still shows the order number and the processed
  date. The voided rows are **not** shown on this card once the order is cancelled; the audit trail
  is in the admin commission ledger (below) and on the members' statements. The **Cancel order** card
  is gone, because the order can no longer be cancelled.
- **Maria**:
  - **Withdrawable** is back to its before value (₱12.50 was debited);
  - the statement has a **Repeat-purchase reversal** debit of **−₱12.50**;
  - the earnings row for the award is **Voided**, with **₱12.50 reversed after the order was
    cancelled**;
  - **Total credited** is back to its before value;
  - **Not paid** is still **0**; a voided award is not a skipped level.
- **Juan**: the same reversal and the same statement row.
- **Peter**:
  - the order page is **Cancelled** and **no Repeat-purchase bonus card** is shown (the order is no
    longer paid);
  - the **E-wallet** has a **Refund** credit of **₱300.00**, and the total is back to the before value
    from worksheet row 1.
- **Admin commission ledger**: the three rows are still there. The L1 and L2 rows are **Voided**,
  each with the small text **₱12.50 recovered of ₱12.50**; the L3 row is still **Skipped**. **Total
  credited** and **This month** are back to their before values (₱0.00 on a clean copy); on that same
  clean copy **Awards** is **0** and **Recipients** is **0**; **Skipped** is still **1**, and
  **Unrecovered** is **₱0.00** with the hint **2 voided awards — shortfall after reversal**.
- No balance is negative, and no row is deleted. The history is intact; the money is reversed with new
  rows.

**If an upline already spent part of the bonus:** the reversal is **partial**. In the **admin
commission ledger**, the row shows the amount recovered and, if there is a shortfall, a red
**₱… shortfall** line. That is the documented behaviour, not a bug; the wallet is never allowed to go
negative. In this walkthrough nobody spent the bonus, so the full ₱12.50 should be recovered.

---

## 9. Clean-up and restore

Do this before you close the browser.

- [ ] **Restore the five settings** from §3, S5. Pay particular attention to the master switch: if it
      was off before you started, turn it **off** again. If you turned off **Require the monthly Active
      rule** for T1, turn it back on.
- [ ] **Switch the test product off** (Admin → **Products** → the pause-circle icon in the product's
      **Actions** column, whose tooltip says **Disable (hide from shop)**; confirm **Disable product**)
      unless the system owner wants it left on. Do **not** delete it; the paid order references it, and
      the feature deliberately refuses to delete a product with awards.
- [ ] **If you ran E4:** the test money is reversed. The order, the award rows and the voided ledger
      rows remain as history. That is correct; the application never deletes money rows.
- [ ] **If you skipped E4:** the buyer spent **₱300.00**, and maria and juan each hold **₱12.50** of
      test money. On a disposable development copy this is acceptable; if the copy must be pristine,
      ask the owner to restore the pre-test backup. Do not hand-edit wallet or award rows.
- [ ] **Sign out** of every window.
- [ ] **Keep the evidence folder.** Do not edit the screenshots after capture.

---

## 10. Results sheet

Copy this table into your report, or print it and fill it in by hand. Use the check IDs exactly.

### Required checks

| ID | Check | Result | Evidence | What I saw instead / notes |
|---|---|---|---|---|
| A1 | The product form has the repeat-purchase card | ☐ Pass ☐ Fail ☐ Blocked | `A1-product-form.png` | |
| A2 | Save the product and see the badge | ☐ Pass ☐ Fail ☐ Blocked | `A2-product-saved.png`, `A2-product-list.png` | |
| A3 | Turn on the global bonus stream | ☐ Pass ☐ Fail ☐ Blocked | `A3-settings.png` | |
| B1 | The catalogue shows the offer | ☐ Pass ☐ Fail ☐ Blocked | `B1-shop-badge.png` | |
| B2 | The product page explains the ladder | ☐ Pass ☐ Fail ☐ Blocked | `B2-product-panel.png` | |
| B3 | The checkout preview is honest | ☐ Pass ☐ Fail ☐ Blocked | `B3-checkout-preview.png` | |
| B4 | The paid order reports the actual total | ☐ Pass ☐ Fail ☐ Blocked | `B4-order-bonus.png` | |
| C1 | Level 1's wallet shows the credit | ☐ Pass ☐ Fail ☐ Blocked | `C1-maria-wallet.png` | |
| C2 | Level 1's statement explains the row | ☐ Pass ☐ Fail ☐ Blocked | `C2-maria-statement.png` | |
| C3 | Level 2's statement and wallet show their share | ☐ Pass ☐ Fail ☐ Blocked | `C3-juan-statement.png`, `C3-juan-wallet.png` | |
| C4 | The buyer's own statement is empty | ☐ Pass ☐ Fail ☐ Blocked | `C4-buyer-statement.png` | |
| C5 | The dashboard and membership explain the status | ☐ Pass ☐ Fail ☐ Blocked | `C5-dashboard.png`, `C5-membership.png` | |
| D1 | The admin order shows every level and reason | ☐ Pass ☐ Fail ☐ Blocked | `D1-admin-order.png` | |
| D2 | The admin ledger shows the same money | ☐ Pass ☐ Fail ☐ Blocked | `D2-admin-ledger.png` | |

### Optional checks

| ID | Check | Result | Evidence | What I saw instead / notes |
|---|---|---|---|---|
| E1 | The master switch hides the offer | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `E1-master-off.png` | |
| E2 | A product without a ladder has no offer | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `E2-no-ladder.png` | |
| E3 | Impossible ladders are refused | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `E3-empty-error.png`, `E3-sum-error.png` | |
| E4 | Cancelling reverses the bonus | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `E4-cancel-order.png`, `E4-maria-after-cancel.png`, `E4-admin-after-cancel.png` | |

### Sign-off

| | |
|---|---|
| **Tester** | |
| **Date** | |
| **Build / commit** | |
| **Environment** | `http://localhost/ecowealth/system/` — development copy |
| **Required checks passed** | ______ / 14 |
| **Optional checks passed** | ______ / 4 |
| **Defects found** | ______ (attach the reports from Appendix B) |
| **Overall result** | ☐ Pass ☐ Pass with notes ☐ Fail ☐ Blocked |
| **Notes** | |

---

## 11. If something doesn't match

Work through the relevant row before you mark a check as **Fail**. Most "failures" during a first run
are setup problems, not feature defects.

### T1 — An upline says "Not Active"

**What it means:** the monthly rule found last month's purchases below **₱500.00**, or a closed month's
snapshot says the upline did not qualify. A level that falls on that person will be **Skipped**, and the
₱12.50 will not appear.

**What to do (test-only workaround):**

1. As `admin`, open **Settings → Commissions → Repeat-purchase bonus**.
2. Turn **Require the monthly Active rule** **off** and save.
3. Reload the upline's **Your Active status** page. It should now show the blue notice that the rule is
   off, and the payout will not be gated by it.
4. Run the walkthrough.
5. At the end, turn **Require the monthly Active rule** back **on** and save.

Do **not** fake last month's purchases, and do not change the ₱500.00 threshold to a negative number.
The switch is the supported operator control.

### T2 — The offer is missing from the shop, the product page or the checkout

Check, in this order:

1. **Both switches:** Settings → Commissions → **Repeat-purchase bonus enabled** is on; the product's
   **Pay this product's ladder** is on.
2. **The product:** status **Active**, stock above zero, ladder total above 0%, badge **Repeat bonus
   29%** on the admin list.
3. **The page:** reload it. The member pages poll for changes; a page left open before the switch was
   turned on may still show the old version for up to about 30 seconds.
4. **The cart:** the checkout preview only appears when a product with a ladder is in the cart.

If all four are true and the panel is still missing, mark the check **Fail** and capture the page.

### T3 — The product page says ₱72.50 but the order says ₱25.00

**This is expected** on this chain. See §1.3. The preview is the full ten-level ladder; the order paid
only the two real, eligible uplines. Do not mark it as a mismatch unless the order total is not
**₱25.00** or the admin order card does not show the two credited rows plus the skipped company row.

### T4 — The wallet did not change

Open the upline's **Earnings → Repeat-purchase bonus** and read the row's **Status** and reason:

- **Skipped** — the row is an audit record, not money. The reason says why (Not Active, disabled,
  pending membership, company account). This is correct behaviour; the level is not compressed.
- **Voided** — the order was cancelled. The money was reversed.
- **Credited** but the wallet is unchanged — this is a **real defect**. Capture the row, the wallet and
  the exact time.

### T5 — The E-wallet option is disabled at checkout

The buyer's balance is below the order total. Go back to S4 and credit the buyer (or reduce the
quantity to 1). The checkout shows the available balance on the E-wallet option and explains the
shortfall.

### T6 — The product badge says "Repeat bonus 0%"

The ladder did not save, or all ten rates are blank/zero. Reopen the product, click **Load reference
ladder**, check the total, turn the switch on and save. Do not continue until the badge says **29%**.

### T7 — The settings page says "No changes to save."

The values you submitted already match the stored values. That is not a failure. Confirm the five
controls are correct and move on.

### T8 — I cannot find the order

The order number is on the buyer's order page (it starts with `EW`). Use **Admin → Orders**, type the
number in the **Search** box and press Enter. If the order is not there, the order was not placed
successfully; check the buyer's **My orders** page.

### T9 — The admin card shows more rows than the three in this guide

Your real chain is deeper than peter → maria → juan → company, or a regular member sits at level 3.
The flow is still working; the amounts are simply different. Compare with §2.2. If the extra rows have
**Credited** status, those people were legitimately paid; if they are **Skipped**, read the reason.

### T10 — I accidentally placed two orders

Each paid order pays the ladder once; that is correct. The second order is not a duplicate of the
first, and the bonus is paid twice because there were two paid sales. For the cleanest evidence, keep
the first order for the checks and cancel the duplicate at the end with the same **Cancel order**
action. Do not try to delete rows.

---

## 12. Glossary

| Term | What it means here |
|---|---|
| **Admin / back office** | The staff side of the application. The left menu shows **Products**, **Members**, **Orders**, **Commissions** and **Settings**. |
| **Member** | A normal user with a member account. The left menu shows **Shop**, **My orders**, **Earnings** and **E-wallet**, and no admin items. |
| **Sponsor** | The member who introduced another member. Each member has one sponsor. |
| **Upline** | Anyone above a member in the sponsor line: their sponsor, the sponsor's sponsor, and so on. |
| **Downline** | The members below a member in the sponsor line. |
| **Level 1** | The buyer's direct sponsor. Level 2 is the sponsor's sponsor; level 10 is nine sponsors up. |
| **Unilevel** | One line of sponsors, one sponsor per member. Not a binary tree. |
| **Repeat purchase** | A later paid order of a product, as opposed to the one-time package activation. |
| **Product** | A shop item such as **QA Repeat Product**. Products can carry a repeat-purchase ladder. |
| **Package** | A membership product that is activated once. Package commissions are a different programme. |
| **Ladder** | The ten percentages attached to one product, levels 1–10. |
| **Rate** | One percentage in the ladder, for example 5.000% at level 1. |
| **Bonus base** | The amount the rates are applied to: the product's P.V. if set, otherwise its price. |
| **P.V. / point value** | A per-product value used as the bonus base. It is set in **Pricing & stock**. |
| **Active** | The monthly rule: an upline who bought at least ₱500.00 last month can be paid this month. |
| **E-wallet** | The member's money account. It has a total, a withdrawable part and a non-withdrawable part. |
| **Withdrawable** | Money the member can cash out. Commissions land here. |
| **Non-withdrawable** | Money from admin top-ups and transfers; spendable in the shop, not cashable. |
| **Credited** | The award was paid into the recipient's withdrawable wallet. |
| **Skipped** | The level was due but the recipient was not eligible. It is an audit row, not money, and it is not moved to another level. |
| **Voided** | The award was reversed after the order was cancelled. The row keeps its history. |
| **Processed / stamped** | The order has been through the repeat-purchase engine. A zero total is still a processed order. |
| **Company account** | The primary administrator (`admin`). It can sponsor members but is never paid a repeat-purchase bonus. |
| **Test copy** | A development copy of the application and database, safe to change. Never the live site. |

---

## Appendix A — The numbers, in one place

### A.1 The test product's ladder

| Level | Rate | Pays per unit on a ₱250.00 base | Who is on this level in the walkthrough |
|---|---|---|---|
| 1 | 5.000% | ₱12.50 | maria — **Credited** |
| 2 | 5.000% | ₱12.50 | juan — **Credited** |
| 3 | 5.000% | ₱12.50 | admin (company) — **Skipped**, not paid |
| 4 | 3.000% | ₱7.50 | nobody |
| 5 | 3.000% | ₱7.50 | nobody |
| 6 | 2.000% | ₱5.00 | nobody |
| 7 | 2.000% | ₱5.00 | nobody |
| 8 | 2.000% | ₱5.00 | nobody |
| 9 | 1.000% | ₱2.50 | nobody |
| 10 | 1.000% | ₱2.50 | nobody |
| **Total** | **29.000%** | **₱72.50** | **₱25.00 actually paid** |

### A.2 Where each number appears

| Number | Where | What it means |
|---|---|---|
| **₱72.50** | Product page, checkout | The maximum the full ten-level ladder can pay on one unit |
| **₱25.00** | Buyer's order page, admin order page, admin ledger | What this order actually paid |
| **₱12.50** | Maria's wallet, statement, earnings | Level 1's share |
| **₱12.50** | Juan's wallet, statement, earnings | Level 2's share |
| **₱12.50** | Admin order, level 3 | Due to the company, but **skipped** and not paid |
| **₱300.00** | Buyer's order and wallet | The price the buyer paid for one unit |
| **₱500.00** | Member's Active status panel | The monthly personal-purchase threshold |

### A.3 If your chain is different

The **flow** is the same, but the **amounts** change. The actual payout is the sum of the per-unit
amounts for the levels that have a real, eligible upline:

- The **company account** is never paid, and its level is recorded as skipped.
- A **Not Active**, **disabled** or **pending** upline is skipped, and the money is not moved.
- A **zero rate** at a level pays nothing and consumes the level (no compression).
- Levels with nobody on them pay nothing.

The product page's "up to" total is always the full ladder, regardless of the chain. If you need the
exact numbers in this guide, use the demo chain in §1.2. If you use a different chain, record the
per-level amounts from the admin order page (D1) and check that they add up to the order's credited
total; do not expect ₱25.00.

---

## Appendix B — Evidence and defect reports

### B.1 What to capture

For every check, capture the **whole browser window**, including the address bar, so the page can be
identified. For amount checks, make sure the account name, the amount and the page header are all
visible.

Use this naming convention:

```text
A1-product-form.png
A2-product-saved.png
A2-product-list.png
B2-product-panel.png
B4-order-bonus.png
C2-maria-statement.png
D1-admin-order.png
```

If a check fails, capture **two** screenshots: what you expected to see (the closest correct screen)
and what you actually saw. Do not crop or annotate the originals.

### B.2 How to report a defect

Use this template for each failed check:

```text
Check:            B4
Page:             /orders/123
Account:          peter
When:             2026-11-13 14:32 (Asia/Manila)
Expected:         "₱25.00 was paid to the members above you when this order was paid."
Actual:           "₱12.50 was paid to the members above you when this order was paid."
Steps:            1. ... 2. ... 3. ...
Severity:         Major
Evidence:         B4-order-bonus.png, D1-admin-order.png
Notes:            The admin order page shows two credited rows totalling ₱25.00,
                  so only the member-facing card is wrong.
```

Severity guide:

| Severity | Use it when |
|---|---|
| **Blocker** | The journey cannot continue, money is wrong, or a page errors. |
| **Major** | A visible amount, status or recipient is wrong, but the journey can continue. |
| **Minor** | A typo, spacing, colour or wording issue that does not change the meaning. |

### B.3 What "Pass" really means

A check is **Pass** only when **every** line in its "You should see" section is true. If you are unsure
about one line, mark **Fail** and describe the uncertainty in the notes. A false Pass is worse than a
Fail that a developer can investigate.

---

## Where to go next

- The deep, surgical Phase 17 checks — skipped uplines, the monthly close, reversals, CSV exports,
  reports, mobile layout and the release gate — are in
  [`qa/phase-17-unilevel-repeat-purchase.md`](qa/phase-17-unilevel-repeat-purchase.md).
- The rest of the manual QA pack, including the sign-off sheet, is indexed in
  [`qa/README.md`](qa/README.md).
- If you are testing on a copy that has no demo accounts, ask the system owner for the three-account
  chain described in §2.2 before you run this walkthrough. The flow can be checked with other people,
  but the exact amounts in this guide assume that chain.
