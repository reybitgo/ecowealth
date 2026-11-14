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

**Time.** About **90–120 minutes** for the required checks, plus **40–50 minutes** for the optional
ones. Do not rush the setup; most false failures come from a product or an account that was not
prepared exactly as described.

| | |
|---|---|
| **Application** | EcoWealth, Phase 18 — repeat-purchase bonus escrow (a **post-17 point release**; `APP_PHASE` stays 17) |
| **Version** | **v2 — escrow**. v1 was the Phase 17 walkthrough; this version replaces the payment expectations it changed |
| **Address** | `http://localhost/ecowealth/system/` |
| **Environment** | A **local development copy only** — never the live site |
| **Required checks** | **15** (A1–A3, B1–B4, C1a, C1b, C2–C5, D1–D2) |
| **Optional checks** | **6** (E1–E6) |
| **Evidence** | v1 screenshots stay in `docs/qa/evidence/unilevel-product/`. The new escrow evidence goes in `docs/qa/evidence/unilevel-escrow/`; this document names those files as **expected** names, not as existing files |
| **Written** | v1: 13 November 2026, against the Phase 17 build; member copy revised to the **Team chain** voice on 14 November 2026. **v2: 14 November 2026, against the escrow build** |

> ### Stop — read this before you click anything
>
> This walkthrough **creates a product, places a real paid order in the test database, and moves test
> money into three e-wallets**. It also changes global commission settings, delivers the order and runs
> the release job.
>
> - Run it **only** on the local development copy (or a copy you are allowed to throw away).
> - **Never** run it on the live site or with a real customer's account.
> - The money is not real, but the database rows are. **E4** cancels an order *before* its bonus is
>   released and moves **no** money; the **C1b** release leaves **₱12.50** of test bonus in each upline's
>   wallet, and **E5/E6** leave further awards **Pending release**, so use a disposable copy or restore
>   the pre-test backup (§9).
> - If you are not sure which copy you are looking at, **stop and ask the system owner** before you
>   continue.

> ### v2 — what changed in this walkthrough
>
> A paid order now **earns** the repeat-purchase bonus but **moves no money**. The award is
> **Pending release** until the order is **delivered** and the award's snapshotted hold window has
> passed; the **release job** (hourly) then credits the upline's withdrawable e-wallet, once.
>
> - **C1 is split:** **C1a** checks payment (the wallet does **not** move; the pending figure appears)
>   and **C1b** checks delivery + release (the wallet rises by exactly ₱12.50).
> - A **fulfilment step** (pack → ready → ship → deliver) now sits between them; the fast path uses
>   `unilevel_hold_days = 0`.
> - **E4** is a cancellation **before** release: no debit, no reversal row, **no shortfall**.
> - **E5** (the payout reminder) and **E6** (the hold actually holds) are new.
> - Where a section's behaviour changed, the v1 **“Observed”** note (captured on the Phase 17 build,
>   which credited at payment) was **removed or relabelled as v1 history**; the expectation above it is
>   the v2 contract. Do not copy a v1 observation into a v2 result.
> - New screenshots go in `docs/qa/evidence/unilevel-escrow/`.

---

## Route map

| Part | Checks | What it proves | Time |
|---|---|---|---|
| **Setup** | S1–S5 | You have the right people, the right chain, enough test money, and a record of the settings to restore | 15 min |
| **A — The admin sets it up** | A1–A3 | An operator can give a product a ladder, switch it on, turn on the global bonus stream, and set the hold and release switches | 15 min |
| **B — What the buyer sees** | B1–B4 | The offer is visible before buying, the preview is honest, and the paid order reports the right **awarded** total | 25 min |
| **C — What the uplines see** | C1a–C5 | The bonus is **pending** at payment, then **credited** after delivery + release; every row is explainable; the buyer earns nothing on their own purchase | 30–35 min |
| **D — What the admin can prove** | D1–D2 | The administrator can see exactly who was paid, who is pending, who was skipped and why | 10 min |
| **E — Optional** | E1–E6 | The master switch hides the offer, an unconfigured product pays nothing, impossible ladders are refused, a cancellation before release pays nothing, the payout reminder explains itself, and the hold actually holds | 40–50 min |

**Contents.** [§1 What you are testing](#1-what-you-are-testing-in-plain-english) ·
[§2 Before you start](#2-before-you-start) · [§3 Setup gates](#3-setup-gates-s1s5) ·
[§4 Admin setup](#4-the-administrator-sets-up-the-product-a1a3) ·
[§5 What the buyer sees](#5-what-the-buyer-sees-b1b4) ·
[§6 What the uplines see](#6-what-the-uplines-see-c1ac5) ·
[§7 What the admin can prove](#7-what-the-administrator-can-prove-d1d2) ·
[§8 Optional checks](#8-optional-checks-e1e6) · [§9 Clean-up](#9-clean-up-and-restore) ·
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

1. **The bonus is earned when the order is paid, and paid into the upline's wallet when it is released**
   — earned at payment (never at placement), released after the order is delivered and the award's
   hold window has passed. Until then it is **Pending release**, not money.
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
| **₱25.00** | Paid order page, admin order, admin ledger | What this order **awarded**, after the real chain and eligibility (`pending` + `credited`) |
| **₱12.50 each** | Maria's and Juan's wallets | Their level's share — **Pending release** first, **Credited** after delivery + the hold |
| **₱12.50 skipped** | Admin order, level 3 | Due, but the company account is not a recipient |

The "**up to**" wording on the product page and the checkout is deliberate. It is a preview, not a
promise. The order page says the ₱25.00 is **reserved** for the members above you and is released to
their e-wallets after the return window; once the release job has run it says it **was released**.

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
- [x] I have created `docs/qa/evidence/unilevel-escrow/` for the new screenshots (the v1 screenshots
      stay in `docs/qa/evidence/unilevel-product/`).
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
4. **Do not cancel the main order at all.** The rest of the checks need it paid and delivered. E4 places
   its own second paid order and cancels that one before release; the main order's release is what C1b
   proves, and cancelling it would cancel the pending bonus instead.
5. **Do not delete the test product.** The paid order references it, so deletion is refused. Switch it
   off at the end instead.
6. **Do not run the release job on a live copy.** It credits real wallets; here it is the step that
   makes C1b happen, and it is safe because the copy is disposable.
7. **Write down the "before" numbers** in §2.5 before you buy. If the copy has other test history, the
   change is what matters, not the absolute total.
8. **If a page shows an error, stop that check and capture it.** Do not retry the same button blindly;
   the error message and the address bar are the evidence.
9. **Keep your screenshots.** They are the only proof of what you saw.

### 2.5 The number worksheet

Fill in the **Before** column before you place the order (B3), and the **After** column at the point
each check says: B4 for the buyer, **C1a** for the payment state of the two uplines, and **C1b** for
their post-release state. The "Expected change" is what this walkthrough's one-unit order must do.

| # | Number to record | Where to read it | Before | After | Expected change |
|---|---|---|---|---|---|
| 1 | Buyer's e-wallet **Total balance** | Peter → **E-wallet** | **₱1,700.00** | | **−₱300.00** at B4 |
| 2 | Level 1 **Withdrawable** | Maria → **E-wallet** | **₱1,020.00** | | **no change at payment (C1a); +₱12.50 at release (C1b)** |
| 3 | Level 2 **Withdrawable** | Juan → **E-wallet** | **₱396.00** | | **no change at payment (C1a); +₱12.50 at release (C1b)** |
| 4 | Level 1 **Total credited** | Maria → **Earnings** → **Repeat-purchase bonus** | **₱0.00** | | **₱0.00 at payment; +₱12.50 after release (C1b)** |
| 5 | Level 2 **Total credited** | Juan → **Earnings** → **Repeat-purchase bonus** | **₱0.00** | | **₱0.00 at payment; +₱12.50 after release (C1b)** |
| 6 | Level 1 **Pending release** | Maria → **E-wallet** (or the earnings tile) | **₱0.00** | | **+₱12.50 at payment (C1a); ₱0.00 after release (C1b)** |
| 7 | Buyer's **purchases so far** this month | Peter → **Earnings** → **Repeat-purchase bonus** | **₱0.00** | | **+₱300.00** |

> The exact totals in the checks assume a **clean copy** where these accounts have no earlier
> repeat-purchase history. If your copy already has some, the **change** must still be exactly as
> shown; the absolute total will be higher. Record the before value so you can tell the difference
> between "history" and "the feature is wrong".

> **Recorded 14 November 2026 (Asia/Manila), before any order.** The **After** column is read at B4
> (the buyer), C1a (payment: nothing has been credited yet) and C1b (after delivery + release); this
> setup run stops at S5, so that column is intentionally still blank.

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
| **S5** | **Pass** | All seven settings recorded below; the master switch was **Off** before the run. |

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
4. Write down the current value of each of these seven controls:

| Setting | Current value (recorded 14 November 2026) |
|---|---|
| **Repeat-purchase bonus enabled** | ☐ On ☑ Off |
| **Upline depth** | **10** levels |
| **Monthly personal-purchase minimum** | ₱ **500.00** |
| **Require the monthly Active rule** | ☑ On ☐ Off |
| **Release delay after delivery** | **7** days |
| **Release repeat-purchase bonuses** | ☑ On ☐ Off |
| **Reverse released awards when an order is cancelled** | ☑ On ☐ Off |

**You are ready when:** you have all seven values written down. You will put them back in §9.

**Recorded:** the seven values above. The master switch was **Off** at this point; A3 turns it **On** for
the walkthrough (and sets the hold to `0` for the fast path), and §9 restores them.

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
| **A3** | **Pass** | The **Repeat-purchase bonus** block is on: depth **10**, minimum **₱500.00**, Active rule **on**, **release delay 0 days**, **release on**, reversal **on**, master switch **on**. A reload keeps them, and no eighth `unilevel*` key appears. | `4_a3_admin_settings_escrow.jpeg` (v2, expected name) |

### A1 — The product form has the repeat-purchase card

| | |
|---|---|
| **Why it matters** | This is the only place an operator decides what a product pays. If the card is missing or misleading, no product can be configured correctly. |
| **Where** | Admin → **Products** → **New product** (`/admin/products/new`) |
| **Evidence** | `4_a1_admin_product_new.jpeg` |
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
| **Evidence** | `4_a2_admin_product_new_complete.jpeg`, `4_a2_admin_product_new_created.jpeg` |
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
| **Evidence** | `4_a3_admin_settings_escrow.jpeg` (expected name, in `docs/qa/evidence/unilevel-escrow/`) |
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
   - **Release delay after delivery** → `0` for the fast path (E6 sets it back to `7` on purpose);
   - **Release repeat-purchase bonuses** → **on**;
   - **Reverse released awards when an order is cancelled** → **on**.
5. Click **Save settings** at the bottom of the **Commissions** card.
6. Read the confirmation banner, then reload the page and read the block again.

**You should see**

- The sub-block heading **Repeat-purchase bonus** and the badge **Levels 1–10, per product**.
- The paragraph explaining that the bonus is earned on every **paid shop order** to the buyer's sponsor
  chain, at the ladder each product carries, and that the per-product ladder is edited on the product
  form.
- The seven controls above, with the values you just set — including **Release delay after delivery**
  (`0` days) and **Release repeat-purchase bonuses** (**on**).
- The hint under **Release delay after delivery**: **“Days after delivery before a repeat-purchase bonus
  becomes withdrawable. 0 releases at delivery. Applies to new awards only; each award keeps the value
  it was given at payment.”**
- The hint under **Release repeat-purchase bonuses**: **“Off pauses the release job; pending bonuses
  stay pending. Cancelling an order still cancels its pending awards.”**
- A green banner such as **Commissions: 4 settings saved.** (The number is how many values actually
  changed; the word **Commissions** and the green tone are what matter.)
- If nothing changed, the banner says **No changes to save.** — that is **not** a failure.
- **No eighth `unilevel*` key** is shown. In particular, `unilevel_ledger_since` must never appear on
  this page.

**Observed (v1, Phase 17 build):** the block rendered the five controls that existed then and saved
correctly. **The v2 build adds the two escrow controls above**, so the expected block has **seven**;
the v1 observation no longer describes it. Take the screenshot on the escrow build and save it as
`docs/qa/evidence/unilevel-escrow/4_a3_admin_settings_escrow.jpeg` (expected name).

**Why both switches matter:** if either the global switch or the product switch is off, nothing is
awarded. The product page, the catalogue badge and the checkout preview disappear, and a paid order is
stamped as processed with a zero total. The admin order page explains which case applies. The **release
switch** is separate: off pauses the wallet credits but never stops a cancellation.

---

## 5. What the buyer sees (B1–B4)

From here on, do the checks in order. B1–B4 are one continuous journey: find the product, read the
offer, buy it, read the result.

> **Buyer-journey run — 14 November 2026, 14:56–17:21 (Asia/Manila), local development copy.** All four
> checks passed, with one **Minor** cosmetic note on the product panel recorded under B2. The values
> below were read on `peter`'s own screens as the journey ran; the paid order was read again on the order
> page and in the ledger after the run. Screenshots are in `docs/qa/evidence/unilevel-product/`.
>
> **v2 note.** A3, B2, B3 and B4 changed with the escrow: the settings block gained the two release
> controls, the product panel now says the share is **earned when that order is paid** and **released
> after the return window**, the checkout note says the same, and the order card says the amount is
> **reserved**. Their v1 **Observed** notes are kept only as history; run the checks again on the
> escrow build and save the new images in `docs/qa/evidence/unilevel-escrow/`. B1 is unchanged.

| Check | Result | What the screen showed | Saved screenshot |
|---|---|---|---|
| **B1** | **Pass** | **Shop** with **1 result** after searching `QA Repeat Product`; card **QA Repeat Product**, SKU **QA-REPEAT-01**, **In stock**, blue **Up to 29% repeat bonus** badge, **₱300.00**, an icon cart button and **Details**. | `5_b1_shop_unilevel_product.jpeg` |
| **B2** | **Pass** | **Repeat-purchase bonus** panel: base **₱250.00** (P.V.), badge **Up to 29%**, **10 of 10 levels pay on this base**, the ten rates **5 / 5 / 5 / 3 / 3 / 2 / 2 / 2 / 1 / 1** worth **₱12.50 … ₱2.50**, footer **Total up to 29.000%** and **₱72.50 per unit**; Team chain voice throughout. One **Minor** clipped label. | `5_b2_shop_unilevel_product_detail.jpeg` (save the escrow-build copy in `unilevel-escrow/`) |
| **B3** | **Pass** | Checkout: **QA Repeat Product 1 × ₱300.00**, **Subtotal ₱300.00**, **Total ₱300.00**, **E-wallet ₱1,700.00**, and the note **Up to ₱72.50 is earned by the members above you when this order is paid. It is released to their e-wallets after the return window (delivery).** plus the Team chain paragraph; the order was placed. | `5_b3_shop_unilevel_product_checkout.jpeg` (v2 name: `5_b3_checkout.jpeg` in `unilevel-escrow/`) |
| **B4** | **Pass** | Paid order **EW26111400861**: **Payment received**, **Paid**, total **₱300.00**, the bonus card **₱25.00 is reserved for the members above you… released after the return window** with no recipient named; buyer's **E-wallet** **₱1,400.00**, and no upline wallet has moved. | `5_b4_shop_unilevel_product_order.jpeg` (v2 name: `5_b4_shop_unilevel_product_order.jpeg` in `unilevel-escrow/`) |

### B1 — The catalogue shows the offer

| | |
|---|---|
| **Why it matters** | A repeat-purchase product must be identifiable in the shop before the member opens it. |
| **Where** | Member → **Shop** (`/shop`), signed in as `peter` |
| **Evidence** | `5_b1_shop_unilevel_product.jpeg` |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

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

**Observed:** signed in as `peter`, **Shop** (`/shop`) showed the heading **Shop** with the count
**1 result** after `QA Repeat Product` was typed in **Search products…** and **Search** was clicked. The
single card carried the green **In stock** pill, the SKU **QA-REPEAT-01** above the title **QA Repeat
Product**, the blue badge **Up to 29% repeat bonus** under the title, the short description
**Repeat-purchase QA product**, the price **₱300.00** at the bottom, and an icon-only **Add to cart**
button next to **Details**. The search narrowed the catalogue to that one card, so no unconfigured
product was on the page to compare; E2 covers that case. Screenshot:
`docs/qa/evidence/unilevel-product/5_b1_shop_unilevel_product.jpeg`.

**If the badge is missing:** the global switch (A3), the product switch (A2) or the product's active
status is wrong. Do not continue; fix the setup and reload the shop.

### B2 — The product page explains what the Team chain pays the reader

| | |
|---|---|
| **Why it matters** | This is the only place a member sees the full ladder, the base and the per-level amounts before buying. It must be complete, and it must be Team chain directed: it shows what the reader earns when their Team chain buys, and that their own purchase does not pay them. |
| **Where** | `/shop/{id}` — click **Details** on the product card |
| **Evidence** | `5_b2_shop_unilevel_product_detail.jpeg` (full-page screenshot; the panel is at the bottom; v1 copy in `unilevel-product/`, save the escrow-build copy in `unilevel-escrow/`) |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

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
  where your Team chain member sits. It is earned when that order is paid and released to your e-wallet
  after the return window (delivery + N days), to an eligible member who is Active for the month."**
  On the A3 fast path the parenthetical reads **(delivery)** because the hold is `0`.
- The second explanatory paragraph: **"Meet the ₱500.00 monthly minimum to stay Active: your counted
  purchases last month decide this month, and only an Active member is paid. If a level falls on you
  while you are not Active, it is skipped and never moved to another level."**
- The third explanatory paragraph: **"Your own purchase does not pay you. It counts toward your monthly
  minimum; the purchases your Team chain makes are what pay your level income."**

**Words that must not appear as labels:** **cashback**, **direct bonus**, **indirect**, **sponsor
chain**. **Your direct recruit** is the Team chain relationship, not a bonus type. The words **rebate**
and **cashback** must not appear anywhere on this panel. The ten levels are one ladder; there is no
direct/indirect split.

**Observed (v1, Phase 17 build):** **Details** opened the product page with the breadcrumb **Shop / QA
Repeat Product**, the price **₱300.00**, **50 available**, the **In stock** pill, **SKU** `QA-REPEAT-01`
and **Availability 50 units available**. Below **About this product** and that list, the panel
**Repeat-purchase bonus** carried the gift icon, the blue badge **Up to 29%**, and the Team chain
paragraph quoted above, word for word. The base line read **Bonus base ₱250.00 per unit (the product's
P.V.). Each level below is what you earn when a Team chain member at that depth buys one unit; their
cart quantity multiplies it.**, followed by **10 of 10 levels pay on this base.** The ten tiles read
**Level 1 5.000% ₱12.50**, **Level 2 5.000% ₱12.50**, **Level 3 5.000% ₱12.50**, **Level 4 3.000%
₱7.50**, **Level 5 3.000% ₱7.50**, **Level 6 2.000% ₱5.00**, **Level 7 2.000% ₱5.00**, **Level 8 2.000%
₱5.00**, **Level 9 1.000% ₱2.50** and **Level 10 1.000% ₱2.50**, each carrying **Your direct recruit**
(level 1) or **Your Team chain** (levels 2–10) beneath it. The footer read **Total up to 29.000%** on the
left and **₱72.50 per unit** on the right. The words **cashback**, **direct bonus**, **indirect**,
**sponsor chain** and **rebate** were not present anywhere in the panel. **The v1 first paragraph said
the share was "credited to your e-wallet when that order is paid"; the escrow build replaces that with
the earned-then-released wording quoted above, so the old sentence must not be recorded as a v2 pass.**
Screenshot (v1): `docs/qa/evidence/unilevel-product/5_b2_shop_unilevel_product_detail.jpeg`. Save the
escrow-build screenshot as `docs/qa/evidence/unilevel-escrow/5_b2_shop_unilevel_product_detail.jpeg`
(expected name).

**Minor (cosmetic) — recorded, not a failure.** At this window width the **Level 1** tile cuts its
sub-label to **Your direct recr…**; the page's own label is **Your direct recruit**, and the longer text
overflows the tile's column and is trimmed with an ellipsis, while **Your Team chain** on levels 2–10
fits. The level number, the rate and the amount are unaffected, and nothing else on the panel changes.
Recorded as a **Minor** presentation note per Appendix B.2; the check is **Pass**.

> **"10 of 10 levels pay" does not mean ten people will be paid.** It means all ten rates on this
> product are above zero. The real chain is only three levels deep, and the company account at level 3
> is skipped. That is why the order will pay ₱25.00, not ₱72.50.

### B3 — The checkout preview says "up to" and pivots to the Team chain

| | |
|---|---|
| **Why it matters** | The preview is the last thing a buyer reads before paying. It states the fact of the order, then turns the reader toward the income their own Team chain can produce. |
| **Where** | Product page → **Add to cart** → **Cart** → **Proceed to checkout** (`/checkout`) |
| **Evidence** | v1: `5_b3_shop_unilevel_product_checkout.jpeg`; escrow build: `5_b3_checkout.jpeg` (expected name, in `unilevel-escrow/`) |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

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
- The repeat-purchase note, exactly (v2 wording):
  - **"Up to ₱72.50 is earned by the members above you when this order is paid. It is released to their
    e-wallets after the return window (delivery)."** On a copy with a hold above `0` the parenthetical
    reads **(delivery + N days)**.
  - **"The same repeat-purchase ladder pays you when your Team chain buys these products: Level 1 for
    the members you personally sponsored, Level 2 for their recruits, up to Level 10. Keep your monthly
    minimum to stay Active, so your level income is earned when their order is paid and released to your
    e-wallet after the return window."**
- The note never says **sponsor chain**, **rebate** or **cashback**, and it never promises a release
  date before delivery.
- **Payment method:** the **E-wallet** option is selected. It shows the available balance. If the
  balance is below ₱300.00, the option is disabled; go back to S4 and credit the buyer.
- After **Place order**, the page moves to the order page with a green flash:
  **"Order placed and paid. Thank you!"**

**Observed (v1, Phase 17 build):** the checkout showed the old note **"Up to ₱72.50 is paid to the
members above you when this order is paid."** The escrow build rewords it to **earned … released after
the return window** (quoted above), so the v1 note must not be recorded as a pass in v2. Take the
escrow-build screenshot as `docs/qa/evidence/unilevel-escrow/5_b3_checkout.jpeg` (expected name); the
v1 image stays in `unilevel-product/` as history.

**If the preview is missing:** the global switch, the product switch, or the product's ladder is not
active. Do not place the order; fix the setup first.

### B4 — The paid order reports the actual total, not the preview

| | |
|---|---|
| **Why it matters** | The buyer must see what the order actually **awarded**, with no recipient names or per-upline amounts, and must not be told the money has already moved. The buyer must also see their own wallet debit. |
| **Where** | The order page (`/orders/{id}`) and Peter → **E-wallet** (`/wallet`) |
| **Evidence** | `5_b4_shop_unilevel_product_order.jpeg` in `docs/qa/evidence/unilevel-escrow/` (expected name; the v1 copy is in `unilevel-product/`) |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

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
  - **"₱25.00 is reserved for the members above you. It is released to their e-wallets after the
    return window (delivery + 0 days)."** — the exact date phrase follows the hold you set in A3. Before
    delivery the card never promises a date; with `hold_days = 0` it says **delivery + 0 days**, and
    once the order is delivered it prints the estimated release date.
  - **"Your Team chain is your earning side: when a member you sponsored buys these products, the same
    ladder earns your level's share for your e-wallet — Level 1 for your direct recruits, up to Level
    10. Keep your monthly minimum to stay Active."** with the Team chain statement link.
- The card never says **sponsor chain**, and it carries **no recipient username** and **no
  per-member amount** anywhere. The buyer sees only the order's awarded total.
- The buyer's **E-wallet** shows a **Shop order** debit of **₱300.00**, and the total balance is
  exactly **₱300.00 lower** than the before value in worksheet row 1. **Nothing else on the buyer's
  wallet changes** — the buyer earned no bonus, and the uplines' pending money is not theirs.

**Why ₱25.00 and not ₱72.50:** see §1.3. The preview shows the ladder's maximum; the order shows what
the real chain **awarded**. The company account at level 3 is skipped, and its ₱12.50 is not moved.
The ₱25.00 is the **awarded** total: at this point it is all `pending`, so no wallet anywhere has moved.

**Observed (v1, Phase 17 build):** the order page showed **₱25.00 was paid to the members above you when
this order was paid.** and the buyer's wallet read **₱1,400.00**. **That wording and the payment-time
credit are gone in v2**: the card now says the amount is **reserved** and released after the return
window. Take the escrow-build screenshot as
`docs/qa/evidence/unilevel-escrow/5_b4_shop_unilevel_product_order.jpeg` (expected name); the v1 image
stays in `unilevel-product/` as history.

**If the card is missing:** open the admin order page (D1) and read the **Repeat-purchase bonus** card
there. It will say **No bonus** and explain why: the order is not paid, the stream was off when it was
paid, no product had a ladder, or there was no sponsor chain. Do not guess; the admin card tells you.

---

## 6. What the uplines see (C1a–C5)

The bonus has been **earned**. Now look at it from the two uplines' side, and from the buyer's own side:
first at payment (nothing has moved), then after delivery and the release run (the wallet is credited).

> **Upline-journey run — v1: 14 November 2026, 17:33–18:00 (Asia/Manila), local development copy.** All
> five v1 checks passed against the Phase 17 build, where the wallet was credited at payment.
> **v2 (escrow):** the same chain is used, but the wallet movements are read in two steps — **C1a** at
> payment and **C1b** after delivery + release. The v1 observation is kept only where the v2 screen is
> unchanged (C2–C5's layout, the Active panel, the Team chain voice). New evidence:
> `docs/qa/evidence/unilevel-escrow/`.

| Check | Result | What the screen showed | Saved screenshot |
|---|---|---|---|
| **C1a** | **Pass** | Maria's **E-wallet** at payment: **Withdrawable** still **₱1,020.00** (unchanged), **Pending release ₱12.50**, **Total funds ₱1,245.00**; **no** repeat-purchase statement row. | `6_c1a_maria_pending.jpeg` (expected name) |
| **C1b** | **Pass** | After delivery + `php bin/unilevel_release.php`: **Withdrawable ₱1,032.50** (+₱12.50, exactly once), **Pending release ₱0.00**, the statement row **Credit · Repeat-purchase bonus · Repeat-purchase bonus — QA Repeat Product (level 1) — order EW… · +₱12.50**; admin order **₱25.00 released**. | `6_c1b_maria_released.jpeg`, `6_c1b_admin_order_released.jpeg` (expected names) |
| **C2** | **Pass** | Maria's statement: the **Pending release** tile and the **Next release** line before C1b, then **Total credited ₱12.50** and the row badge **Credited** with the release date after it; **Active for <month>**; the Team chain by level card (**1 level award · ₱12.50**, Level 1 total **₱12.50** from `@peter`). | `6_c2_maria_statement.jpeg` (v2 name: `6_c2_maria_statement.jpeg` in `unilevel-escrow/`) |
| **C3** | **Pass** | Juan's statement and wallet: the same pending → credited sequence for the **L2** row; **Withdrawable ₱396.00 → ₱408.50** only after the release run. | `6_c3_juan_statement.jpeg`, `6_c3_juan_wallet.jpeg` (v2 names in `unilevel-escrow/`) |
| **C4** | **Pass** | Peter's statement: the **No repeat-purchase bonus yet** empty state with **Your own purchase counts toward your monthly minimum, not your income.**, no award row, and **no pending release** on his wallet; **<month> purchases so far ₱300.00** (about **60%** of **₱500.00**), green badge **Active for <month>**. | `6_c4_buyer_statement.jpeg` |
| **C5** | **Pass** | Dashboard **Repeat-purchase bonus** block: **Credited ₱0.00**, **Pending release** hidden at zero, **Total earned ₱0.00**, the "No awards yet…" and "Your own purchases keep you Active…" sentences; Membership: the same badge and **₱0.00 earned from your Team chain**. No form, input or claim button. | `6_c5_dashboard.jpeg`, `6_c5_membership.jpeg` |

### 6.1 The fulfilment step — deliver the order (before C1b)

The bonus does not become money until the order is **delivered**. Do this once, as `admin`, between
C1a and C1b.

1. In Window A, open **Orders**, search the **EW…** number from B4, and open the order.
2. In the fulfilment panel, click **Pack order** (status becomes `packing`).
3. Click **Ready to ship** (status becomes `ready_to_ship`).
4. Click **Ship order**, enter a courier (`QA Courier`) and a tracking number (`QA-TRACK-001`), and
   confirm (status becomes `shipped`, and the physical stock is deducted here).
5. Click **Mark delivered** (status becomes `delivered`, and `delivered_at` is stamped — this is the
   clock the hold starts from).

Then run the release job, either in the browser or in a terminal:

```
http://localhost/ecowealth/system/cron/unilevel_release.php?dry-run=1
http://localhost/ecowealth/system/cron/unilevel_release.php
```

```powershell
cd C:\laragon\www\ecowealth\system
php bin\unilevel_release.php --dry-run
php bin\unilevel_release.php
```

**You should see**

- The order timeline records **Pack**, **Ready to ship**, **Ship** (with the courier and tracking) and
  **Deliver**, each with actor and time.
- The dry run lists the order and both awards with their recipients and amounts, and says it changes
  nothing.
- The real run prints `released: 1 order(s) · 2 award(s) · ₱25.00` (the amounts depend on the chain) and
  writes one line to `storage/logs/unilevel_release-YYYY-MM.log`.
- **With `unilevel_hold_days = 0`** the release happens on the first run after delivery. With `7` it
  would stay pending — that is exactly what E6 proves.

**If the dry run lists nothing:** check the order is **delivered** (not `shipped`) and that A3 set
**Release delay after delivery** to `0`. `php bin/unilevel_release.php --doctor` names both facts.

### C1a — At payment the bonus is pending and the wallet does not move

| | |
|---|---|
| **Why it matters** | This is the escrow: the upline must be able to see the earned bonus, and must **not** be able to spend or withdraw it before the order is delivered and the return window has passed. |
| **Where** | Maria → **E-wallet** (`/wallet`) and Maria → **Earnings** → **Repeat-purchase bonus** (`/earnings?ledger=repeat`) |
| **Evidence** | `6_c1a_maria_pending.jpeg` (expected name, in `docs/qa/evidence/unilevel-escrow/`) |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. In Window B, sign out of peter and sign in as `maria`.
2. Click **E-wallet** in the left menu.
3. Read the three bucket cards and, below them, the two escrow cards.
4. Read the **Statement** card and look for a repeat-purchase row.
5. Open **Earnings → Repeat-purchase bonus** and read the tiles and the award row.
6. Write the after values in worksheet rows 2, 4 and 6.

**You should see**

- **Withdrawable** is **unchanged** at the value you recorded before the order (**₱1,020.00** on a clean
  copy). **Total balance** and **Non-withdrawable** are unchanged too.
- A **Pending release** card reading **₱12.50**, with the hint **Repeat-purchase bonus that is not
  withdrawable yet.**, a **next release** line (or **after delivery + 0 days** before delivery) and the
  link **See the repeat-purchase statement**.
- A **Total funds** card reading **wallet balance + ₱12.50**, with the hint **Wallet balance + pending
  release. Pending release is not spendable or withdrawable yet.** — the figure is **presentation only**.
- **No repeat-purchase row in the wallet statement**: nothing was credited, so there is no `Credit` row.
- On the earnings tab: the **Pending release** tile is **₱12.50** (hint **1 award · not withdrawable
  yet**), **Total credited** is **₱0.00**, and the row shows the **Pending release** badge with a note
  such as **Available after delivery + 0 days** (or the promised date once delivered — but this check
  runs before delivery).
- The row's **Amount** is **₱12.50** in the info colour, with the small line **not yet in your
  wallet**.
- Nothing on the page offers a "claim", "release" or "withdraw" action for the pending amount.

**If the wallet rose at payment:** the build is not the escrow build, or the order was already released.
Check `php bin/unilevel_release.php --doctor` (it names the hold) and the award's status on the admin
order (D1). A `credited` row right after payment is a **real defect** in v2 — record it.

### C1b — Delivery + release credits the wallet exactly once

| | |
|---|---|
| **Why it matters** | The bonus must end up as real, withdrawable wallet money — but only after delivery and the hold, and only once. |
| **Where** | Maria → **E-wallet**; then Admin → **Orders** → the order |
| **Evidence** | `6_c1b_maria_released.jpeg`, `6_c1b_admin_order_released.jpeg` (expected names) |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. Complete §6.1 (deliver the order, run the release job).
2. In Window B, still signed in as `maria`, reload **E-wallet** and read the buckets, the escrow cards and
   the newest statement row.
3. Run `php bin/unilevel_release.php` **a second time** and reload the wallet again.
4. In Window A, open the order and read the **Repeat-purchase bonus** card.
5. Write the after values in worksheet rows 2, 4 and 6.

**You should see**

- **Withdrawable** and **Total balance** each **+₱12.50** against C1a, and the **Pending release** card is
  **gone** (it is hidden when nothing is pending).
- A statement row, newest first: the release date, a green **Credit** badge, the source
  **Repeat-purchase bonus**, the note **"Repeat-purchase bonus — QA Repeat Product (level 1) — order
  EW…"**, the amount **+₱12.50**, and the balance/withdrawable after the row.
- The earnings tab's **Total credited** is **₱12.50**, the **Pending release** tile is **₱0.00**, and the
  row's badge reads **Credited** with the note **Credited to your e-wallet on <date>.**
- The **second run changes nothing**: no second credit, no new statement row, `released_awards=0` in the
  log. That is the idempotency guard, not a failure.
- The admin order card badge reads **₱25.00 released**; both rows read **Credited** with a release date
  and carry a **wallet** link; the footer reads **Awarded ₱25.00 · Released ₱25.00 · Pending ₱0.00**.

**If the wallet did not change:** open Maria's **Earnings → Repeat-purchase bonus** and read the row.
A **Skipped** row is not money (the reason says why); a still-**Pending release** row means the order was
not delivered or the hold has not elapsed (`--doctor` shows both). A **Credited** row with an unchanged
wallet is a **real defect** — record it.

**If the wallet rose twice:** stop. That is a double-credit incident: pause releases
(`unilevel_release_enabled = 0`), take a backup, run `php bin/wallet_reconcile.php`, and escalate. Do
not edit award or ledger rows.

### C2 — Level 1's repeat-purchase statement explains the row

| | |
|---|---|
| **Why it matters** | This is the Team chain earner's statement. It must match the wallet, name the buyer and the level, and explain the monthly Active rule. |
| **Where** | Maria → **Earnings** → **Repeat-purchase bonus** (`/earnings?ledger=repeat`) |
| **Evidence** | `6_c2_maria_statement.jpeg` (full-page screenshot; the award row is at the bottom) |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

**Do this**

1. Still signed in as `maria`, click **Earnings** in the left menu.
2. Click the **Repeat-purchase bonus** pill.
3. Read the page from top to bottom: the five tiles, the **Your Active status** card, the
   **Repeat-purchase by level** card and the award row.
4. Write the after value in worksheet row 4.

**You should see**

- The page heading **Repeat-purchase bonus** and the subtitle **"Every repeat-purchase award your Team
  chain's paid purchases earned you."**
- Five tiles:
  - **Total credited**: **₱12.50** after C1b on a clean copy, with the hint **1 award · in your wallet**;
  - **Pending release**: **₱12.50** before C1b (hint **1 award · not withdrawable yet**) and **₱0.00**
    (hint **Nothing pending**) after the release;
  - **This month**: **₱12.50** on a clean copy, with a relative hint such as **Last award 2 minutes
    ago**;
  - **Awards**: **1**, with the hint **<credited> credited · <pending> pending release**;
  - **Not paid**: **0**, with the hint **No level was skipped**.
- Before the release, a line under the tiles reads **Next release: <date>.** (or **Pending awards on
  orders still in transit are released after delivery + N days.**) followed by **Pending amounts become
  withdrawable after the return window.**; after the release the line is gone.
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
  - **Amount**: **+₱12.50** (in the credit colour only once credited; before the release it is the
    info colour with the small line **not yet in your wallet**);
  - **Status**: **Credited** after C1b — and **Pending release** with a note such as **Available after
    <date>** / **Available after delivery + N days** if you look before the release run;
  - **Order**: the **EW…** order number, as plain text (not a link — the order belongs to the buyer).
- **No "as buyer" statistic** anywhere on the page. Maria's own purchases are not earnings.

**Observed (v1, Phase 17 build):** the page showed four tiles — **Total credited ₱12.50**, **This month
₱12.50**, **Awards 1** and **Not paid 0** — and the row badge **Credited** from the moment of payment.
**v2 has five tiles** (the **Pending release** tile and the **Next release** line are new) and a
**Pending release** badge until the release run. Take the escrow-build screenshot as
`docs/qa/evidence/unilevel-escrow/6_c2_maria_statement.jpeg` (expected name).

**If the tile says ₱0.00 or the row is missing:** check the admin order page (D1). A skipped row with a
reason is the likely cause. If the admin page shows a credited row and Maria's page does not, that is a
real defect. If the row is **Pending release** when you expected **Credited**, the order was not
delivered or the hold has not elapsed — `php bin/unilevel_release.php --doctor` says which.

### C3 — Level 2's statement and wallet show their own share

| | |
|---|---|
| **Why it matters** | Each level is paid separately. Level 2 must see a level-2 row and its own wallet credit, not level 1's. |
| **Where** | Juan → **Earnings** → **Repeat-purchase bonus** · Juan → **E-wallet** |
| **Evidence** | `6_c3_juan_statement.jpeg`, `6_c3_juan_wallet.jpeg` |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

**Do this**

1. Sign out of maria and sign in as `juan`.
2. Open **Earnings** → **Repeat-purchase bonus**.
3. Read the five tiles and the award row.
4. Open **E-wallet** and read the newest statement row.
5. Write the after values in worksheet rows 3 and 5.

**You should see**

- The same five tiles as C2, with **₱12.50** credited (and **₱12.50** pending before C1b) and **1**
  award on a clean copy.
- **Repeat-purchase by level**: the card now reads **juan's Team chain** by level:
  - **Level 1** lists maria at **₱0.00** (no paid repeat purchase from her yet);
  - **Level 2** lists `peter` at **₱12.50** and any other level-2 member at **₱0.00**, with the
    level total **₱12.50**;
  - deeper levels list their own members at **₱0.00** or read **No Team chain members at this level
    yet**, with level totals of **₱0.00**.
- The award row shows **@peter** as the buyer, **L2** as the level, base **₱250.00**, rate **5.000%**,
  amount **+₱12.50**, status **Credited** (after C1b).
- Juan's **E-wallet** shows **Withdrawable** up by exactly **₱12.50**, with a
  **Repeat-purchase bonus** row whose note says **(level 2)**.
- Juan does **not** see maria's level-1 amount as his own; each upline sees only their own level.
- Before the release run, Juan's wallet shows the same **Pending release ₱12.50** and **Total funds**
  cards as Maria's did in C1a, and **no** statement row.

**Observed (v1, Phase 17 build):** Juan's statement showed the four v1 tiles and the **L2** row
**Credited** from payment, and his withdrawable bucket rose to **₱408.50** immediately. **v2 splits
that into C1a (pending, unchanged wallet) and C1b (credited after the release run)** and adds the fifth
tile. Take the escrow-build screenshots as
`docs/qa/evidence/unilevel-escrow/6_c3_juan_statement.jpeg` and `6_c3_juan_wallet.jpeg` (expected names).

### C4 — The buyer's own statement is empty and explains why

| | |
|---|---|
| **Why it matters** | A buyer must never be shown their own purchase as earnings. This is the rule that prevents the most damaging misunderstanding of the feature. |
| **Where** | Peter → **Earnings** → **Repeat-purchase bonus** and Peter → **E-wallet** (`/wallet`) |
| **Evidence** | `6_c4_buyer_statement.jpeg` (full-page screenshot; the empty state is at the bottom) |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

**Do this**

1. Sign out of juan and sign in as `peter`.
2. Open **Earnings** → **Repeat-purchase bonus**.
3. Read the empty state and the **Your Active status** card.
4. Open **E-wallet** and confirm there is **no Pending release** card and no **Total funds** card.
5. Write the after value in worksheet row 7.

**You should see**

- **No award row** for peter's own order. The list shows the empty state:
  - **No repeat-purchase bonus yet**;
  - the explanation that a level's share is **earned** when someone in the member's Team chain buys a
    product with a ladder and the order is paid, and **released after the return window**;
  - the sentence **"Your own purchase counts toward your monthly minimum, not your income."**
  - an **Open the shop** button.
- **No "as buyer" statistic** and no tile that counts peter's own spending as earnings.
- The **Pending release** tile is **₱0.00** — a buyer is not a recipient, so their own order creates no
  pending bonus for them, and their wallet has **no** **Pending release** or **Total funds** card at all
  (both are hidden when nothing is pending).
- The **Your Active status** card:
  - peter's **purchases so far this month** increased by exactly **₱300.00** compared with the before
    value;
  - the progress bar is at **60%** on a clean copy (₱300.00 of the ₱500.00 threshold);
  - the sentence says that reaching the threshold this month makes peter Active **next** month, not
    this one;
  - the note that peter's own purchases never pay him a repeat-purchase bonus; they keep him Active so
    his Team chain's purchases can pay him.

**Observed (v1, Phase 17 build):** Peter's statement showed the empty state and four tiles, and no award
row — the core result is unchanged in v2. **The tile row now has five tiles** (the **Pending release**
tile is **₱0.00**), and the empty-state sentence says the share is **earned** at payment and **released
after the return window** rather than credited at payment. Save the escrow-build screenshot as
`docs/qa/evidence/unilevel-escrow/6_c4_buyer_statement.jpeg` (expected name).

**If peter has an award row:** that is a serious defect. The buyer must never be a recipient. Capture
the row and report it.

### C5 — The dashboard and membership page explain the status without offering an action

| | |
|---|---|
| **Why it matters** | The monthly Active status must be visible where the member already looks, and it must never look like something the member can "claim". |
| **Where** | Peter → **Dashboard** (`/dashboard`) · Peter → **Membership** (`/membership`) |
| **Evidence** | `6_c5_dashboard.jpeg`, `6_c5_membership.jpeg` |
| **Result** | ☑ **Pass** ☐ Fail ☐ Blocked |

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
- Three lines: **Credited** **₱0.00** (peter has no Team chain awards yet), **Pending release** (shown
  only when something is pending) and **Total earned** **₱0.00**.
- The sentence **"No awards yet — a Team chain order earns your level when it is paid."**
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
- **₱0.00 earned from your Team chain** on the right; when this member has pending awards the card adds
  **"₱C credited + ₱P pending release. Pending amounts become withdrawable after the return window."**

**Both pages:** no form, no input box and no button for the repeat-purchase stream. There is nothing to
claim; the bonus is **earned** automatically when a paid order reaches an eligible level and **released**
after delivery + the return window.

**Observed (v1, Phase 17 build):** both pages showed the Active badge, the **"Earned from your Team
chain ₱0.00"** line and no claim action — the v2 Active panel and layout are unchanged. **v2 adds the
credited / pending / total split on the dashboard tile and the credited + pending sentence on the
Membership card**, so the v1 wording is history. Save the escrow-build screenshots as
`docs/qa/evidence/unilevel-escrow/6_c5_dashboard.jpeg` and `6_c5_membership.jpeg` (expected names).

> **"Active" here is the monthly purchase rule from §1.4, not employment status and not package rank.**
> Peter's purchase this month makes him Active for **next** month; it does not pay him anything today.

---

## 7. What the administrator can prove (D1–D2)

The buyer sees only the order total. The administrator sees the audit detail: who was paid, at which
level, on what base, and why a level was skipped.

> **Admin audit run — v2 expected state, not yet captured.** The v1 run on 14 November 2026
> (18:14–18:18 Asia/Manila) was against the Phase 17 build, which used the old payment-time badge and
> six tiles; that wording is **history**. The values below are the escrow build's contract for order
> **EW26111400861** and the wallets recorded under §6. Run D1 and D2 on the escrow build and save the
> new images in `docs/qa/evidence/unilevel-escrow/` (expected names).

| Check | Result | What the screen should show (v2) | Evidence (expected name) |
|---|---|---|---|
| **D1** | **Expected (v2)** | Admin order **EW26111400861**: green badge **₱25.00 released**; **L1 @maria** and **L2 @juan** at **₱250.00 · 5.000% · +₱12.50 · Credited — <date>** with wallet links, **L3 @admin** at **₱12.50 · Skipped — company account is not paid** with no link; footer **Awarded ₱25.00 · Released ₱25.00 · Pending ₱0.00**; read-only. | `7_d1_admin_order.jpeg` |
| **D2** | **Expected (v2)** | **Repeat-purchase bonus** ledger: eight tiles — **₱25.00 / ₱0.00 / ₱25.00 / 2 / 2 / 1 / 0 / ₱0.00** in the order **Total credited · Pending release · This month · Awards · Recipients · Skipped · Cancelled before release · Unrecovered**; the same three rows with the order linked; **Level 1** leaves only the maria row (**1 row**); **Clear** restores the list; **Closed months** empty. | `7_d2_admin_ledger.jpeg` |

### D1 — The admin order page shows every level and every reason

| | |
|---|---|
| **Why it matters** | This is the only screen where an administrator can see which upline received which amount, and why a level was skipped. Finance and support depend on it. |
| **Where** | Admin → **Orders** → search `EW…` → open the order |
| **Evidence** | `7_d1_admin_order.jpeg` (expected name, in `docs/qa/evidence/unilevel-escrow/`) |
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

- The card heading **Repeat-purchase bonus** and, because the walkthrough delivered and released this
  order in §6.1, the green badge **₱25.00 released**.
- A table with these columns: **Lvl**, **Recipient**, **Product**, **Base**, **Rate**, **Amount**,
  **Status**. It contains exactly three rows:

| Lvl | Recipient | Product | Base | Rate | Amount | Status |
|---|---|---|---|---|---|---|
| L1 | @maria — wallet link | QA Repeat Product | ₱250.00 | 5.000% | +₱12.50 | **Credited — <date>** |
| L2 | @juan — wallet link | QA Repeat Product | ₱250.00 | 5.000% | +₱12.50 | **Credited — <date>** |
| L3 | @admin — no link | QA Repeat Product | ₱250.00 | 5.000% | ₱12.50 | **Skipped** — **company account is not paid** |

- The note paragraph: **"Credited to the sponsor chain. The rows above are what the release job paid.
  One row per order line, recipient and level. A skipped level keeps its number — no money is
  redirected. The buyer never receives a rebate."**
- The card footer: **Awarded ₱25.00 · Released ₱25.00 · Pending ₱0.00** on the left, and
  **Order EW… · processed <date and time>** on the right.
- The two credited recipients' names are **wallet links**; the skipped **@admin** row has **no** wallet
  link.
- The card is **read-only**: there is no edit, no delete and no "pay again" button.

**The card's other two states** (they are checked in E4 and E6):

- **Paid, not yet released:** badge **₱X pending release**; each row's pill is **Pending release** with
  **Pending release — due <date>** once the order is delivered, or **Pending release — after delivery +
  N days** before delivery; **no** wallet link on any row; the footer reads **Awarded ₱X · Released
  ₱0.00 · Pending ₱X**.
- **Cancelled before release:** badge **No bonus released**; the rows stay visible with the **Cancelled
  before release** pill and the small line **no money moved**; **no** wallet links; the note reads
  **"No bonus released. This order was cancelled before any repeat-purchase bonus was released."**
  The rows are the audit trail and are not deleted.

**Why the level-3 row exists:** the company account is a real member of the sponsor chain, so the system
records the level and the amount that would have been due, marks it **Skipped**, and explains why. It
is an audit record, not money. The released total is only the two credited rows: ₱25.00.

**Expected (v2) — not yet captured.** On the escrow build, order **EW26111400861** should show the
breadcrumb **Orders › EW26111400861**, the **Delivered** pill and **₱300.00** at the top right, and the
**Payment review** card with **No proof required**. The **Repeat-purchase bonus** card should carry the
green **₱25.00 released** badge; **L1 @maria** and **L2 @juan** should read **Credited — <date>** with
their names linked to the admin wallet, and **L3 @admin** should read **Skipped** with **company account
is not paid** and no link. The note and footer should read exactly as above, and the card should hold no
field, edit, delete or **pay again** action. Save it as
`docs/qa/evidence/unilevel-escrow/7_d1_admin_order.jpeg` (expected name). The v1 run showed the old
payment-time badge and no release date; that is history, not a v2 result.

**If there are more than three rows:** the real chain is deeper than the three people in this
walkthrough. The extra levels may be credited if they have eligible members. Stop and compare with
§2.2; the expected amounts in this guide assume exactly two uplines plus the company.

### D2 — The admin commission ledger shows the same money from the other side

| | |
|---|---|
| **Why it matters** | Finance must be able to find the same three rows in the commission ledger, filter them, and reconcile the total with the order. |
| **Where** | Admin → **Commissions** → **Repeat-purchase bonus** (`/admin/commissions?ledger=repeat`) |
| **Evidence** | `7_d2_admin_ledger.jpeg` (expected name, in `docs/qa/evidence/unilevel-escrow/`) |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked |

**Do this**

1. In Window A, click **Commissions** in the left menu.
2. Click the **Repeat-purchase bonus** pill.
3. Read the eight stat tiles at the top.
4. Read the table. On a clean copy after C1b it has the three rows from D1.
5. In the **Product** filter, select **QA Repeat Product** and click **Filter**.
6. Change **Level** to **1**, click **Filter**, then click **Clear** to restore the full list.

**You should see**

- The page heading **Repeat-purchase bonus** and the subtitle **"Every award a paid order earned, with
  its release state. One row per line, recipient and level 1–10."** The heading is the same one v1
  used; the subtitle now names the release state.
- Eight tiles, on a clean copy:

| Tile | Value | Hint |
|---|---|---|
| **Total credited** | ₱25.00 | 2 members paid |
| **Pending release** | ₱0.00 | Earned but not yet in a wallet. |
| **This month** | ₱25.00 | Since the 1st |
| **Awards** | 2 | Average ₱12.50 |
| **Recipients** | 2 | Uplines credited at least once |
| **Skipped** | 1 | Ineligible upline — no money moved |
| **Cancelled before release** | 0 | Never paid; no shortfall. |
| **Unrecovered** | ₱0.00 | Voided awards only — shortfall after a post-release reversal. |

- The **Repeat-purchase ledger** card heading with the badge **3 rows**, and the subtitle **"Every
  award a paid shop order produced, credited, pending, skipped or cancelled. One row per order line,
  recipient and level."**
- The table columns **Lvl**, **Recipient**, **Buyer**, **Product**, **Base**, **Rate**, **Amount**,
  **Status**, **Order**, **Awarded**, **Wallet**.
- The same three rows as D1:
  - **L1 @maria**, buyer **@peter**, base **₱250.00**, rate **5.000%**, **+₱12.50**, **Credited**;
  - **L2 @juan**, the same base and rate, **+₱12.50**, **Credited**;
  - **L3 @admin**, the same base and rate, **₱12.50** with the small text **not paid**,
    **Skipped** and the reason **company account is not paid**.
- Each credited row has a wallet-icon button in its **Wallet** column that links to that recipient's
  wallet filtered to the order; the skipped row's **Wallet** cell shows **—** (no wallet movement).
- The **Status** dropdown lists **Pending release**, **Credited**, **Skipped**, **Voided** and
  **Cancelled before release** — the two escrow states read exactly like their row badge.
- The **Export CSV** header keeps its existing columns and now ends with **Hold days** and **Released
  at**.
- The order number in each row links to the admin order page.
- With **Product → QA Repeat Product**, only these three rows remain on a clean copy.
- With **Level → 1**, only the maria row remains on a clean copy.
- **Clear** restores the full list.
- The **Closed months** card is a read-only history of the monthly close. On a clean copy it says
  **No month has been closed yet…**. You do not need to change it for this walkthrough.

**Expected (v2) — not yet captured.** After C1b, still signed in as `admin`, **Commissions** opens
`/admin/commissions`, and the **Repeat-purchase bonus** pill opens
`/admin/commissions?ledger=repeat` as the active tab beside **Package commissions**, with **Export
CSV** and **E-wallet monitor** at the top right. The heading should read **Repeat-purchase bonus** with
the subtitle quoted above. The eight tiles should read **Total credited ₱25.00** (**2 members paid**),
**Pending release ₱0.00** (**Earned but not yet in a wallet.**), **This month ₱25.00** (**Since the
1st**), **Awards 2** (**Average ₱12.50**), **Recipients 2** (**Uplines credited at least once**),
**Skipped 1** (**Ineligible upline — no money moved**), **Cancelled before release 0** (**Never paid;
no shortfall.**) and **Unrecovered ₱0.00** (**Voided awards only — shortfall after a post-release
reversal.**) — the same **₱25.00** as D1. The **Closed months** card should show the badge **0 closed**
and the no-month-closed sentence, with no action. The **Repeat-purchase ledger** card should carry the
badge **3 rows** and list the same three rows as D1 under the columns quoted above: **L1 @maria (Maria
Santos)**, buyer **@peter**, **QA Repeat Product (QA-REPEAT-01)**, **₱250.00**, **5.000%**, **+₱12.50**,
**Credited**, order **EW26111400861** with its **Delivered** pill, and a wallet button; **L2 @juan** with the
same base, rate, amount, status and order; and **L3 @admin** at **₱250.00 · 5.000% · ₱12.50** with the
small line **not paid**, the **Skipped** pill, **company account is not paid** and a **—** wallet cell.
With **Product → QA Repeat Product** and **Filter** the three rows should stay; with **Level → 1** and
**Filter** only the maria row should remain, with the badge **1 row** and the footer **Rows per page 10
· Awards · Showing 1–1 of 1**; **Clear** should restore the full list. Save it as
`docs/qa/evidence/unilevel-escrow/7_d2_admin_ledger.jpeg` (expected name). The v1 run showed six tiles
and the old payment-time wording; that is history, not a v2 result.

**If the tiles show different numbers:** the copy may already have other repeat-purchase awards from
earlier tests. The **table rows for your order** and the **difference** of ₱25.00 are what matter. If
the table cannot be filtered down to your three rows, capture the filter and the row count.

---

## 8. Optional checks (E1–E6)

These are optional for a first pass, but they cover the six things most likely to go wrong in real use:
the master switch, a product without a ladder, an impossible ladder, a cancellation before release, the
payout reminder, and the hold itself.

### E1 — The global switch hides the offer everywhere

| | |
|---|---|
| **Why it matters** | An operator must be able to stop the whole programme without editing every product. |
| **Where** | Admin → **Settings** → **Commissions** · Peter → **Shop** / product / **Checkout** |
| **Evidence** | `8_e1_master_off.jpeg` |
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
  - no **Up to ₱72.50 is earned by the members above you…** note on the checkout;
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
| **Evidence** | `8_e2_no_ladder.jpeg` |
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
- The checkout has **no** **Up to … is earned by the members above you** note, because no product in
  the cart carries a ladder.
- The product and the checkout work normally in every other way.

**If the cart also contains QA Repeat Product:** the preview will still appear for that configured
product. Use a cart containing only the unconfigured product for this check.

### E3 — Impossible ladders are refused, and the stored ladder is not changed

| | |
|---|---|
| **Why it matters** | A ladder that pays more than 100%, or a switched-on product that pays nothing, is a configuration error. The form must refuse it clearly and leave the saved product alone. |
| **Where** | Admin → **Products** → **QA Repeat Product** → **Edit** |
| **Evidence** | `8_e3_empty_error.jpeg`, `8_e3_sum_error.jpeg` |
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

### E4 — Cancelling a paid order before release moves no money

| | |
|---|---|
| **Why it matters** | A cancelled sale must not leave bonus money behind. On a new order the award is still pending, so cancelling it must move **no** money: no debit, no reversal row and no shortfall. The order and its cancelled rows must stay visible as history. |
| **Where** | Peter → **Shop** → **Checkout** · Admin → **Orders** → the second order → **Cancel order** · Admin → **Commissions** → **Repeat-purchase bonus** |
| **Evidence** | `8_e4_cancel_order.jpeg`, `8_e4_maria_after_cancel.jpeg`, `8_e4_admin_order_cancelled.jpeg`, `8_e4_admin_ledger_cancelled.jpeg` (expected names, in `docs/qa/evidence/unilevel-escrow/`) |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A |

**Do this**

1. In Window B, sign in as `peter`. **Before buying**, record maria's and juan's current
   **Withdrawable**, **Pending release**, **Total funds** and **Earnings → Repeat-purchase bonus**
   **Total credited** values. C1b already released the first order, so these are the baseline; on a
   clean copy the pending figure is **₱0.00**.
2. Still as `peter`, open **Shop**, search `QA Repeat Product`, open it, and place a **second** paid
   order for one unit with **E-wallet**, exactly as in B3. **Do not deliver it.** Write down its new
   **EW…** number.
3. In Window A, open **Orders**, search the second order number and open it. In the
   **Repeat-purchase bonus** card, confirm the badge **₱25.00 pending release**, both rows
   **Pending release** with **after delivery + 0 days** (or **due <date>** once delivered), **no wallet
   link**, and the footer **Awarded ₱25.00 · Released ₱0.00 · Pending ₱25.00**.
4. In the right column, find the **Cancel order** card. Leave **Reason** on **Cancelled by the
   administrator**, click **Cancel order**, and confirm the dialog with **Cancel order**.
5. Read the order status, the **Repeat-purchase bonus** card and the **Cancel order** area.
6. As `maria`, open **E-wallet**, then **Earnings → Repeat-purchase bonus**. Read the wallet buckets,
   the statement and the award row.
7. As `juan`, open **E-wallet** and the same earnings tab.
8. As `peter`, open **My orders** → the second order, then **E-wallet**.
9. As `admin`, open **Commissions → Repeat-purchase bonus**.
10. Run the release job (§6.1 commands). It must not pay the cancelled order.

**You should see**

- The order status is **Cancelled**, and the **Cancel order** card is gone.
- **Peter** is refunded **₱300.00**: the **E-wallet** shows a **Refund** credit of **₱300.00**, and the
  total is back to the value it had before the second order. The cancelled order page has **no
  Repeat-purchase bonus card** (the order is no longer paid).
- **Maria** and **Juan**: **Withdrawable**, **Total balance** and **Total credited** are **unchanged** —
  there is **no debit**. **Pending release** returns to **₱0.00** (the card is hidden when nothing is
  pending) and **Total funds** returns to the wallet balance. Their wallet statements have **no
  Repeat-purchase reversal** row, because nothing was ever paid.
- Each upline's **Earnings → Repeat-purchase bonus** keeps the award row, with the **Cancelled before
  release** badge and the note **Order cancelled before release — no money was paid.** **Total
  credited** is unchanged and **Not paid** is still **0** — a cancelled award is not a skipped level.
- **Admin order card:** badge **No bonus released**; the rows stay visible with the **Cancelled before
  release** pill and the small line **no money moved**; **no wallet links**; the note reads **"No bonus
  released. This order was cancelled before any repeat-purchase bonus was released."** The footer keeps
  the order number and the processed date; its money line reads **Awarded ₱0.00 · Released ₱0.00 ·
  Pending ₱0.00**.
- **Admin ledger:** the two new rows are **Cancelled before release** with **no money moved** and a
  **—** wallet cell. **Total credited**, **This month**, **Awards** and **Recipients** are unchanged
  (**₱25.00**, **₱25.00**, **2**, **2** on a clean copy); **Skipped** stays **1**; the **Cancelled
  before release** tile reads **2** and its hint adds **₱25.00 cancelled.**; **Unrecovered stays
  ₱0.00** — a cancelled award is never a shortfall.
- Running the release job afterwards pays nothing for that order: the run reports **`released_awards=0`**
  and writes no wallet row. The two awards stay `cancelled`.
- No balance is negative, and no row is deleted. The order and its cancelled rows are the history.

**Why this is not the old reversal.** This order never paid the bonus: its awards were `pending`, so the
cancellation turned them into `cancelled` and **no money moved**. The **post-release** path still exists
for legacy awards and future returns (plan §6.4): a `credited` award is **voided** and the wallet is
**debited only up to its withdrawable balance**, recording any shortfall as **Unrecovered**. That is the
only case that writes a **Repeat-purchase reversal** statement row. A cancelled-before-release award
never becomes a shortfall.

**If an upline already spent part of the bonus (post-release case only):** the reversal is **partial**.
In the **admin commission ledger**, the row shows the amount recovered and, if there is a shortfall, a
red **₱… shortfall** line. That is the documented behaviour, not a bug; the wallet is never allowed to
go negative. E4 never enters this path, because a pending award has no wallet money to recover.

**If money moves anyway:** a pending cancellation that debits a wallet or writes a reversal row is a
**real v2 defect**. Capture the order, the wallet statement and the admin ledger, and stop.

### E5 — The payout page explains the pending amount

| | |
|---|---|
| **Why it matters** | The payout page is where a member expects money. A pending award must be explained beside the withdrawable figure, named in the form and in a refusal, and never mistaken for money that is missing or already included. |
| **Where** | Peter → **Shop** → **Checkout** (a fresh paid order) · Maria → **Payouts** (`/payouts`) |
| **Evidence** | `10_e5_payout_reminder.jpeg` (expected name, in `docs/qa/evidence/unilevel-escrow/`) |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A |

**Do this**

1. In Window B, sign in as `peter` and place a fresh paid order for **QA Repeat Product** with
   **E-wallet**, exactly as in B3. **Do not deliver it.** This gives maria (and juan) a new
   **₱12.50 pending release** award while maria keeps her released balance.
2. Sign in as `maria`. Open **E-wallet** and confirm the **Pending release** card (₱12.50 on a clean
   copy) and the **Total funds** card.
3. Click **Payouts** in the left menu (`/payouts`). Read the balance card, then the **Request a
   payout** card.
4. Read the informational alert above the **Amount** field.
5. Try to request **more than the withdrawable balance** (for example the withdrawable figure plus
   ₱100.00), choose an available method, fill its account if the box is empty, and submit. Read the
   refusal banner.
6. Now enter **exactly the withdrawable amount** and submit. Read the success flash and the
   **Withdrawal in progress** card.
7. Look for a modal, a checkbox or any gate that asks you to acknowledge the pending amount. There is
   none.
8. If you have a member whose whole repeat-purchase balance is pending and whose withdrawable balance
   is **₱0.00**, load their **Payouts** page as well and read the all-pending variant in the balance
   card.

**You should see**

- The balance card shows **Withdrawable** and, when pending is above zero, a fact line **Pending
  release ₱12.50 · not withdrawable yet** (the figure is the member's own pending total). The wallet
  balance and non-withdrawable figures are unchanged by the pending money.
- If **nothing** is withdrawable but something is pending, the card instead says **"You have ₱12.50
  pending release, but none is withdrawable yet. It becomes available after the return window."**
- The request form shows an inline informational alert above **Amount** (it is an alert, **not** a
  modal and **not** a checkbox): **"₱12.50 of repeat-purchase bonus is pending release and is not
  included in this request. It will be released after delivery + 0 days."** The last clause follows
  the member's data: a known date after delivery (**"…after the return window (next: <date>)."**),
  the snapshotted hold before delivery (**"…after delivery + N days."**), or the paused sentence
  **"Release is currently paused by the administrator."**
- Requesting more than withdrawable is refused, and the refusal names the pending amount: **"You can
  withdraw up to ₱…. Your ₱12.50 repeat-purchase bonus is pending release and is not yet
  withdrawable."** (A known next release adds **"The next release is on <date>."**)
- Requesting the withdrawable amount succeeds. The green flash reads **"₱… is held for payout by
  <method> to <account>. An administrator will process it shortly. Your ₱12.50 pending release was
  not part of this request; it will be released after the return window."** The **Withdrawal in
  progress** card shows the held amount, and the pending figure is shown beside it as a separate pile.
- The pending amount is **never subtracted** from the withdrawable figure and **never blocks** the
  request. There is no modal and no checkbox to tick.
- Save the balance card and the form alert as
  `docs/qa/evidence/unilevel-escrow/10_e5_payout_reminder.jpeg` (expected name). Capture the refusal
  and the success flash as separate frames if your evidence convention allows.

**If the pending amount is missing from the form:** the member-facing reminder is the point of this
check. Capture the balance card, the form and the refusal; do not mark it a pass on the balance card
alone.

### E6 — The hold actually holds

| | |
|---|---|
| **Why it matters** | The hold is the whole escrow: a delivered order must not pay until its snapshotted window has passed. If the release job ignores the hold, every delivery pays immediately. |
| **Where** | Admin → **Settings** → **Commissions** · Peter → **Shop** · Admin → **Orders** · Maria → **E-wallet** / **Earnings** |
| **Evidence** | `10_e6_hold.jpeg` (expected name, in `docs/qa/evidence/unilevel-escrow/`) |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A |

**Do this**

1. As `admin`, open **Settings → Commissions → Repeat-purchase bonus**, set **Release delay after
   delivery** to `7` and click **Save settings**. Read the hint back.
2. As `peter`, place a **new** paid order for **QA Repeat Product** with **E-wallet** and note its
   **EW…** number. It must be a separate order from C1b's (and from E4's and E5's).
3. As `admin`, deliver that order: **Pack order → Ready to ship → Ship order → Mark delivered**
   (§6.1). Do **not** wait seven days; the stored date is the evidence.
4. Run `php bin/unilevel_release.php --dry-run`, then `php bin/unilevel_release.php`. Read the output.
5. As `maria`, open **E-wallet** and **Earnings → Repeat-purchase bonus**. Read the pending card, the
   new award row and its note.
6. As `admin`, reopen the order (the **Repeat-purchase bonus** card) and the **Commissions →
   Repeat-purchase bonus** ledger.
7. Set **Release delay after delivery** back to `0` (or the value recorded in S5) and save.

**You should see**

- The order is **Delivered**, but its award is still **Pending release**.
- The release job releases **nothing** for it: the run reports **`released_awards=0`** (CLI:
  **released: 0 order(s) · 0 award(s) · ₱0.00**), and the wallet statement has no new repeat-purchase
  row.
- Maria's **Withdrawable** and **Total balance** are **unchanged**. Her **Pending release** figure
  includes this award, and the earnings row's note reads **Available after <date>.** — the delivery
  date **+ 7 days** — not "today".
- The admin order card shows the badge **₱25.00 pending release**, the rows **Pending release — due
  <date>**, and the footer **Awarded ₱25.00 · Released ₱0.00 · Pending ₱25.00**.
- The admin ledger shows the order's rows as **Pending release** with the same due date, and the
  **Pending release** tile includes them. **Total credited** does not move.
- The C1b fast path used a **separate** order created while **Release delay after delivery** was `0`;
  that is why C1b released. Do not change the main order's hold to force E6, and do not wait for the
  seven days — the due date is the proof.
- Save the admin order card (badge **₱25.00 pending release** and its due line) or the member's
  still-pending award row as `docs/qa/evidence/unilevel-escrow/10_e6_hold.jpeg` (expected name).

**If the job releases the award before the seventh day:** that is a **Major** defect in the hold. Stop,
capture the job output and the award row, and restore the setting before you continue.

---

## 9. Clean-up and restore

Do this before you close the browser.

- [ ] **Restore the seven settings** from §3, S5, not just the two escrow controls. Pay particular
      attention to **Release delay after delivery**: E6 set it to `7`, so put it back to the value you
      recorded. Restore **Release repeat-purchase bonuses** as you found it, and if you turned
      **Require the monthly Active rule** off for T1, turn it back on. Also restore the master switch:
      if it was off before you started, turn it **off** again.
- [ ] **If you completed E5:** the successful request is still **open** and holds maria's withdrawable
      balance. As `admin`, open **Payouts**, open that request and **Reject** it with a reason such as
      `QA clean-up`; confirm the held amount returns to her withdrawable balance. Do not approve it.
- [ ] **Switch the test product off** (Admin → **Products** → the pause-circle icon in the product's
      **Actions** column, whose tooltip says **Disable (hide from shop)**; confirm **Disable product**)
      unless the system owner wants it left on. Do **not** delete it; the paid order references it, and
      the feature deliberately refuses to delete a product with awards.
- [ ] **If you ran E4:** the second order is **Cancelled**, its two awards are **Cancelled before
      release**, and no wallet row was written; the buyer's **₱300.00** was refunded at cancellation.
      The order, the award rows and the cancelled ledger rows remain as history. That is correct; the
      application never deletes money rows.
- [ ] **The release path leaves test money behind.** C1b credited **₱12.50** to maria and **₱12.50**
      to juan; E5/E6 may also leave awards **Pending release**. On a disposable development copy this is
      acceptable; if the copy must be pristine, ask the owner to restore the pre-test backup. Do not
      hand-edit wallet or award rows to remove it.
- [ ] **Sign out** of every window.
- [ ] **Keep the evidence folder.** Do not edit the screenshots after capture.

---

## 10. Results sheet

Copy this table into your report, or print it and fill it in by hand. Use the check IDs exactly.

### Required checks

| ID | Check | Result | Evidence | What I saw instead / notes |
|---|---|---|---|---|
| A1 | The product form has the repeat-purchase card | ☐ Pass ☐ Fail ☐ Blocked | `4_a1_admin_product_new.jpeg` | |
| A2 | Save the product and see the badge | ☐ Pass ☐ Fail ☐ Blocked | `4_a2_admin_product_new_complete.jpeg`, `4_a2_admin_product_new_created.jpeg` | |
| A3 | Turn on the global bonus stream | ☐ Pass ☐ Fail ☐ Blocked | `4_a3_admin_settings_escrow.jpeg` | |
| B1 | The catalogue shows the offer | ☐ Pass ☐ Fail ☐ Blocked | `5_b1_shop_unilevel_product.jpeg` | |
| B2 | The product page explains the ladder | ☐ Pass ☐ Fail ☐ Blocked | `5_b2_shop_unilevel_product_detail.jpeg` | |
| B3 | The checkout preview is honest | ☐ Pass ☐ Fail ☐ Blocked | `5_b3_checkout.jpeg` | |
| B4 | The paid order reports the actual total | ☐ Pass ☐ Fail ☐ Blocked | `5_b4_shop_unilevel_product_order.jpeg` | |
| C1a | At payment the bonus is pending and the wallet does not move | ☐ Pass ☐ Fail ☐ Blocked | `6_c1a_maria_pending.jpeg` | |
| C1b | Delivery + release credits the wallet exactly once | ☐ Pass ☐ Fail ☐ Blocked | `6_c1b_maria_released.jpeg`, `6_c1b_admin_order_released.jpeg` | |
| C2 | Level 1's statement explains the row | ☐ Pass ☐ Fail ☐ Blocked | `6_c2_maria_statement.jpeg` | |
| C3 | Level 2's statement and wallet show their share | ☐ Pass ☐ Fail ☐ Blocked | `6_c3_juan_statement.jpeg`, `6_c3_juan_wallet.jpeg` | |
| C4 | The buyer's own statement is empty | ☐ Pass ☐ Fail ☐ Blocked | `6_c4_buyer_statement.jpeg` | |
| C5 | The dashboard and membership explain the status | ☐ Pass ☐ Fail ☐ Blocked | `6_c5_dashboard.jpeg`, `6_c5_membership.jpeg` | |
| D1 | The admin order shows every level and reason | ☐ Pass ☐ Fail ☐ Blocked | `7_d1_admin_order.jpeg` | |
| D2 | The admin ledger shows the same money | ☐ Pass ☐ Fail ☐ Blocked | `7_d2_admin_ledger.jpeg` | |

### Optional checks

| ID | Check | Result | Evidence | What I saw instead / notes |
|---|---|---|---|---|
| E1 | The master switch hides the offer | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `8_e1_master_off.jpeg` | |
| E2 | A product without a ladder has no offer | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `8_e2_no_ladder.jpeg` | |
| E3 | Impossible ladders are refused | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `8_e3_empty_error.jpeg`, `8_e3_sum_error.jpeg` | |
| E4 | Cancelling before release moves no money | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `8_e4_cancel_order.jpeg`, `8_e4_maria_after_cancel.jpeg`, `8_e4_admin_order_cancelled.jpeg`, `8_e4_admin_ledger_cancelled.jpeg` | |
| E5 | The payout page explains the pending amount | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `10_e5_payout_reminder.jpeg` | |
| E6 | The hold actually holds | ☐ Pass ☐ Fail ☐ Blocked ☐ N/A | `10_e6_hold.jpeg` | |

### Sign-off

| | |
|---|---|
| **Tester** | |
| **Date** | |
| **Build / commit** | |
| **Environment** | `http://localhost/ecowealth/system/` — development copy |
| **Required checks passed** | ______ / 15 |
| **Optional checks passed** | ______ / 6 |
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
**₱25.00** or the admin order card does not show the two paid rows (pending or credited) plus the
skipped company row.

### T4 — The wallet did not change

Open the upline's **Earnings → Repeat-purchase bonus** and read the row's **Status** and reason:

- **Pending release** — the award is earned but not in the wallet yet; the note gives the due date or
  the hold. The wallet correctly has not moved. This is not a failure.
- **Cancelled before release** — the order was cancelled while the award was pending. No money moved
  and there is no reversal row; a cancelled award is not a shortfall.
- **Skipped** — the row is an audit record, not money. The reason says why (Not Active, disabled,
  pending membership, company account). This is correct behaviour; the level is not compressed.
- **Voided** — the award had been released and was then reversed (legacy / returns path). Read the
  recovered and shortfall figures.
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

The values you submitted already match the stored values. That is not a failure. Confirm the seven
controls are correct and move on.

### T8 — I cannot find the order

The order number is on the buyer's order page (it starts with `EW`). Use **Admin → Orders**, type the
number in the **Search** box and press Enter. If the order is not there, the order was not placed
successfully; check the buyer's **My orders** page.

### T9 — The admin card shows more rows than the three in this guide

Your real chain is deeper than peter → maria → juan → company, or a regular member sits at level 3.
The flow is still working; the amounts are simply different. Compare with §2.2. If the extra rows have
**Credited** or **Pending release** status, those people were legitimately awarded; if they are
**Skipped**, read the reason.

### T10 — I accidentally placed two orders

Each paid order earns the ladder once; that is correct. The second order is not a duplicate of the
first, so it earns its own awards. If it is still **undelivered**, cancel it as in E4 before release —
no money moves, and its rows become **Cancelled before release**. If it was already delivered and
released, leave it: cancelling then is the post-release path and can debit the uplines. Do not try to
delete rows.

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
| **Pending release** | An award status (`pending`): the bonus is earned but is **not in a wallet yet** — not spendable and not withdrawable. It becomes wallet money only after delivery + the snapshotted hold, when the release job runs. |
| **Released / Credited** | An award status (`credited`): the release job has paid the award into the recipient's **withdrawable** wallet. |
| **Cancelled before release** | An award status (`cancelled`): the order was cancelled while the award was still pending. It was **never paid**, so there is no debit, no reversal row and **no shortfall**. |
| **Voided** | An award status (`voided`): the award was released and then reversed after a cancellation (the legacy / returns path). The wallet gave back only what its withdrawable balance could cover; any remainder is **Unrecovered**. |
| **Hold days** | Days after delivery before a pending award can be released. The value is **snapshotted** on the award at payment, so changing the setting later does not change existing awards. `0` releases at delivery. |
| **Return window** | The business period after delivery in which a sale can still be returned or cancelled; the repeat-purchase **hold** is how this walkthrough models it. |
| **Total funds** | A **presentation-only** sum on the wallet page: wallet balance + pending release. It is not a wallet column, and the pending part is not spendable or withdrawable. |
| **Unrecovered** | The part of a **voided** award the wallet could not return — the shortfall after a post-release reversal. **Cancelled** awards are never counted here. |
| **Release job** | `bin/unilevel_release.php` (also the web and `.cmd` entries): credits due `pending` awards once, after delivery + the award's hold. Paused by **Release repeat-purchase bonuses**. |
| **Skipped** | The level was due but the recipient was not eligible. It is an audit row, not money, and it is not moved to another level. |
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
| **Total** | **29.000%** | **₱72.50** | **₱25.00 awarded — released after delivery** |

### A.2 Where each number appears

| Number | Where | What it means |
|---|---|---|
| **₱72.50** | Product page, checkout | The maximum the full ten-level ladder can pay on one unit |
| **₱25.00** | Buyer's order page, admin order page, admin ledger | What this order **awarded** (`pending` + `credited`) |
| **₱12.50** | Maria's wallet, statement, earnings | Level 1's share — **Pending release** until C1b, then **Credited** |
| **₱12.50** | Juan's wallet, statement, earnings | Level 2's share — **Pending release** until C1b, then **Credited** |
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

Use this naming convention: the **part number** of the section the check lives in, the **check ID** in
lower case, and the **screen or state**, joined with underscores and saved as a `.jpeg` —
`<part>_<check>_<screen>.jpeg`.

```text
4_a1_admin_product_new.jpeg
4_a2_admin_product_new_created.jpeg
5_b2_shop_unilevel_product_detail.jpeg
5_b4_shop_unilevel_product_order.jpeg
6_c2_maria_statement.jpeg
7_d1_admin_order.jpeg
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
Expected:         "₱25.00 is reserved for the members above you. It is released after the return window."
Actual:           "₱12.50 is reserved for the members above you. It is released after the return window."
Steps:            1. ... 2. ... 3. ...
Severity:         Major
Evidence:         5_b4_shop_unilevel_product_order.jpeg, 7_d1_admin_order.jpeg
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
