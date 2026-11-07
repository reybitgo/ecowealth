# Phase 9 QA — Registration, registration codes, membership and activation

| | |
|---|---|
| **Phase** | 9 — four registration modes, registration codes, membership activation, genealogy and the member dashboard |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §4.4, §5.10, §6.2, §7 Phase 9 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · demo tree `juan`, `maria`, `peter`, `sarah`, `david` / `Demo@1234` · evidence fixtures `QA_Member_09`, `QA_Member_10 / QaMember!2345`, `QA_Assoc_09 / QaAssoc!2345` · `QA_Member_01 / QaPass!2345` (Phase 1) |
| **Checks** | 16 (QA9-01 … QA9-16) |
| **Time** | 60–75 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-9/` |

## What this phase is meant to prove

A visitor can **join** in one of four ways and end up with a real membership record: standard (free,
shopping only), registration code, e-wallet (paid by a signed-in sponsor) or pending (activate
later). Every account has a sponsor — typed, or the company admin when the optional field is left
blank — and its own referral code. A pending member becomes an active associate the moment their
package is paid — and that moment is the single trigger the commission engine of Phases 10–11 will
listen to.

Five rules matter most:

1. **No binary placement.** There is no `binary_parent`, no `binary_position`, no left/right leg and
   no upline username anywhere in the registration form or the schema. Every relationship is
   `users.sponsor_id`.
2. **Codes are single-use.** `ABCD-EFGH-JKLM`, stored uppercase, compared case-insensitively. Two
   parallel redemptions cannot both succeed: the loser's conditional update changes no row and its
   transaction rolls back.
3. **Activation is atomic.** One package at a time, amount from `packages.price`, one open request
   per member. Approval sets the member active, attaches the package and runs the commission hook
   **once**; a second approval changes nothing.
4. **A suspended member cannot sign in.** Suspending clears `is_active`; restoring returns them to
   `active` (or `pending` when they never had a package).
5. **Money still reconciles.** Every registration/activation wallet movement is a ledger row written
   by `WalletService`; `bin/wallet_reconcile.php` stays clean.

**Already proven automatically:** P9-T1 standard registration, the `free_registration_enabled`
gate and the blank-sponsor default (joins under the primary admin, audited) · P9-T2 code registration
activates the code's package · P9-T3 invalid/used/expired codes are
refused without an account · P9-T4 e-wallet registration debits the payer once, credits the admin,
keeps the registrar's session · P9-T5 guest e-wallet refused · P9-T6 referral signup resolves the
sponsor by code and stays pending · P9-T7 unknown/self/suspended sponsors refused · P9-T8 duplicate
username/email · P9-T9 wallet activation debits once and invokes the hook once · P9-T10 manual proof
→ review → approval activates · P9-T11 rejection returns to pending with a reason and no debit ·
P9-T12 double activation/approval refused · P9-T13 two parallel redemptions use a code exactly once
(two database connections) · P9-T14 generation, expiry and export · P9-T15 route guards · P9-T16
member detail + suspend/restore + audit · P9-T17 genealogy up/down, cycle safety and no binary
column · P9-T18 referral-code uniqueness. `MembershipTest`, `MembershipViewTest` and the extended
`MigrationTest`/`RouterTest` cover these (**389 cases in total**). The HTTP smoke script walks the
registration, code, pending, manual-activation, approve/reject, suspend/restore, codes and genealogy
paths over HTTP — **130 checks in total**.

**Already captured headlessly** (reference screenshots — still run the checks; refresh them during
your pass):

| File | Shows |
|---|---|
| `final-register.png` | the register page: mode selector, sponsor field, account fields, terms |
| `final-register-ref.png` | a `?ref=` link: sponsor pre-filled and **Activate later** selected |
| `final-register-code.png` | the code mode after **Validate**: the green verified box with the package |
| `final-register-mobile.png` | the same page at 390 × 844 |
| `final-dashboard.png` | juan's dashboard: package, both wallet buckets, referral link, orders, earnings |
| `final-dashboard-associate.png` | an active associate with a mixed wallet (top-up + commission) |
| `final-dashboard-pending.png` | a pending member told to activate from Membership |
| `final-dashboard-mobile.png` | the dashboard at 390 × 844 |
| `final-membership-pending.png` | a pending member: code / e-wallet / proof paths, receiving accounts, history |
| `final-membership-review.png` | an open proof request: "Activation in progress" replaces the forms |
| `final-membership-active.png` / `final-membership-associate.png` | active memberships with package and referral link |
| `final-membership-mobile.png` | the membership page at 390 × 844 |
| `final-genealogy-team.png` | juan's upline and team grouped by level |
| `final-genealogy-mobile.png` | the genealogy page at 390 × 844 |
| `final-admin-members.png` | member directory: sponsor, package, status, both wallet buckets |
| `final-admin-members-mobile.png` | the directory at 390 × 844 |
| `final-admin-member.png` | member detail: account, membership, wallet, activation history, upline, audit |
| `final-admin-codes.png` | codes: counters, issue form, filters and the list |
| `final-admin-activations.png` | the review queue with **Approve**, the reason field and **View proof** |

> The reference screenshots come from the evidence fixtures created by
> `tests/tmp/seed-membership-evidence.php` (a pending member with a rejected history, a pending
> member with an open GCash proof, and an active associate with a mixed wallet). Delete them with
> the cleanup SQL at the end of this document, or leave them as demo data.

> **Before you start:** Phases 1–8 should be signed off, the demo tree must be seeded
> (`database/seeds/005_demo_tree.sql`) and the automated suites must be green:
> `php tests\run.php` → **389 cases**; `powershell -NoProfile -File tests\smoke.ps1` → **130 checks**.
> Create `docs\qa\evidence\phase-9\` for your own screenshots.

---

## A. Registration

### QA9-01 — A guest registers a standard account and is signed in

| | |
|---|---|
| **Why it matters** | This is the free front door: shopping only, no package, no commissions. The sponsor is optional so an unsponsored visitor can still join. |
| **Where** | `/register` (signed out) |
| **Evidence** | `QA9-01-register-standard.png` · reference `final-register.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign out, open `/register`.
2. Keep **Standard** selected and **leave the sponsor field blank** (it is labelled *(optional)*).
3. Fill in a new username, email and a password of at least 8 characters, tick the terms.
4. Submit.

**Expected**

- The four mode cards render; **E-wallet** is shown as a teaser with a *Sign in* link for a guest.
- The sponsor label says **(optional)** and the hint says *"Leave this blank to join under the company account."*
- On submit you land on `/dashboard` with a success flash — no separate sign-in step.
- The dashboard says **Standard member**, wallet ₱0.00, and the membership card says no package.
- In `/admin/members` the new account is **Active**, package `—`, sponsor **@admin** (the company
  account), with an 8-character referral code.

### QA9-02 — A registration code lands the member active with its package

| | |
|---|---|
| **Why it matters** | Codes are sold offline; redeeming one must create the account and activate the package in one step. |
| **Where** | `/admin/codes` (issue) then `/register` (redeem) |
| **Evidence** | `QA9-02-register-code.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin`, open **Registration codes**, issue 1 code for **Package A** at ₱4,498 with an
   expiry date a month away.
2. Copy the new code from the list.
3. Sign out and open `/register`, choose **Registration code**, paste the code, fill the account
   fields with `juan` as the sponsor and submit.

**Expected**

- The account is **Active** with **Package A**, `reg_payment_method = code`.
- The code row turns **Used** with the new member's username and a `used_at` timestamp.
- The member has an activation record (status **Approved**) in their membership history.

### QA9-03 — Invalid, used and expired codes are refused

| | |
|---|---|
| **Why it matters** | A code is money; a bad one must never create an account or activate a package. |
| **Where** | `/register` (code mode) |
| **Evidence** | `QA9-03-code-refused.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/register`, choose **Registration code** and press **Validate** on `ZZZZ-ZZZZ-ZZZZ`.
2. Try to submit that value with a fresh username.
3. Try the code you redeemed in QA9-02 again with another username.
4. Try `DEMO-EXPD-4C9M` (the seeded expired code).

**Expected**

- **Validate** answers *"That registration code is not recognised."* / *"…has already been used."* /
  *"…has expired."*
- Each submit returns to `/register` with the error and **no account is created** (check
  `/admin/members` or the username availability check).
- `DEMO-EXPD-4C9M` stays **Expired** in the code list.

### QA9-04 — A referral link pre-fills the sponsor and creates a pending member

| | |
|---|---|
| **Why it matters** | This is how sponsors recruit: the link carries their code, and the recruit can join free and pay later. |
| **Where** | `?ref=JUAN0001` on `/register` |
| **Evidence** | `QA9-04-register-referral.png` · reference `final-register-ref.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/register?ref=JUAN0001`.
2. Confirm the sponsor field is pre-filled and **Activate later** is selected.
3. Register a new account.

**Expected**

- The sponsor field shows `juan`; **Activate later** is selected.
- The dashboard says **Pending activation** and links to Membership.
- In `/admin/members` the account is **Pending**, sponsor `@juan` (a typed sponsor overrides the
  company default), and it has its own referral code.

### QA9-05 — A guest cannot pay with an e-wallet

| | |
|---|---|
| **Why it matters** | The payer must be a signed-in account; there is no anonymous wallet debit. |
| **Where** | `/register` as a guest (e-wallet mode is not offered) |
| **Evidence** | — |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. While signed out, inspect `/register`: there is no selectable **E-wallet** card, only the teaser.
2. POST an e-wallet registration by hand if you are testing with a tool (or follow the teaser link).

**Expected**

- The guest is redirected to `/login` with a warning, and no account or ledger row is written.

### QA9-06 — A signed-in sponsor registers someone and pays from the e-wallet

| | |
|---|---|
| **Why it matters** | The reference behaviour: a sponsor can pay for a recruit, and the company receives the package price. |
| **Where** | `/register` as `juan` |
| **Evidence** | `QA9-06-register-ewallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `juan` (`Demo@1234`), whose wallet holds ₱2,000 — top it up first with
   **Admin → E-wallet monitor → juan → Credit** if the package price is higher.
2. Open `/register`, choose **E-wallet**, pick a package, fill in the new account and submit.
3. Check `juan`'s statement and the new member's membership page.

**Expected**

- The balance shown in the e-wallet card covers the package price.
- The new account is **Active** with the chosen package.
- juan's statement shows one **Registration** debit for the price; the primary admin's wallet shows
  one **Registration** credit for the same amount (revenue).
- juan stays signed in — the registrar's session is never replaced by the new member.

### QA9-07 — A pending member activates with a code

| | |
|---|---|
| **Why it matters** | The pending flow is how free referrals become associates later. |
| **Where** | `/membership` as a pending member |
| **Evidence** | `QA9-07-membership-code.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as a pending member (or `david / Demo@1234`), open **Membership**.
2. Issue a **Package B** code as admin, then enter it in the **Registration code** card and submit.

**Expected**

- The member becomes **Active** with **Package B**.
- The activation history lists the approved request with the method **Code** and the package price.
- No wallet movement was written (the code was sold offline).

### QA9-08 — A pending member activates with the e-wallet

| | |
|---|---|
| **Why it matters** | Wallet-funded activation is a direct package purchase; it must debit once and credit the company. |
| **Where** | `/membership` as a pending member |
| **Evidence** | `QA9-08-membership-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As admin, credit the pending member's wallet with more than the package price
   (**Admin → E-wallet monitor → member → Credit**).
2. Sign in as the member, open **Membership**, choose the package in the **E-wallet** card and submit.
3. Open the member's wallet statement and the admin's own statement.

**Expected**

- The member is **Active** with the package; the wallet shows one **Registration** debit.
- The primary admin has one **Registration** credit for the same amount.
- The activation history shows **Approved** / **E-wallet**, and the commission hook timestamp is set
  (Phases 10–11 will use it; no commission money moves yet).

### QA9-09 — A manual proof waits for approval and activates on approve

| | |
|---|---|
| **Why it matters** | Not every member can pay by wallet; a proof must be reviewable and auditable. |
| **Where** | `/membership` then `/admin/activations` |
| **Evidence** | `QA9-09-membership-proof.png`, `QA9-09-admin-approve.png` · reference `final-membership-review.png`, `final-admin-activations.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As a pending member, open **Membership**, choose **Payment proof**, pick **GCash**, choose a
   package, enter a reference number, attach a PNG/PDF receipt and submit.
2. Check the page: the three activation cards are replaced by **Activation in progress**.
3. As admin, open **Activations** → **Awaiting review**: the request shows the member, package,
   reference and **View proof**. Open the proof (it streams; the file is not a public URL).
4. Press **Approve** and confirm the modal.

**Expected**

- The member becomes **Active** with the package and the request moves to **Approved** with the
  reviewer and timestamp.
- Approving again (reload and retry, or POST directly) is refused and changes nothing — the
  commission hook never runs twice.
- The proof stream is `image/png`/`application/pdf` for the admin, **403** for a member and a
  login redirect for a guest.

### QA9-10 — A rejected activation returns the member to pending

| | |
|---|---|
| **Why it matters** | A rejected proof must explain itself and leave the member able to try again. |
| **Where** | `/admin/activations` then the member's **Membership** page |
| **Evidence** | `QA9-10-admin-reject.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Submit another proof as a pending member (use `QA_Member_10` or a fresh pending account).
2. As admin, open **Activations**, type a reason in the row's **Rejection reason** field and press
   **Reject** (an empty reason is refused).

**Expected**

- The request is **Rejected** with the reason; the member is still **Pending**.
- The member's **Activation history** shows the rejected row with the reason under the badge.
- No wallet debit was written; the member can open a new request.

---

## B. Back office

### QA9-11 — Codes: issue, filter, expire and export

| | |
|---|---|
| **Why it matters** | Codes are the only paid onboarding product the company sells offline. |
| **Where** | `/admin/codes` |
| **Evidence** | `final-admin-codes.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Issue a batch of 10 codes for Package A at a chosen price, no expiry.
2. Filter the list by **Unused**; search one code.
3. Set one code's expiry date to yesterday (SQL below), then open the member's `/register` page and
   press **Validate** on it.
4. Press **Export CSV**.

**Expected**

- The counters (total / unused / used / expired / revenue) follow the data.
- The 10 codes are present, grouped `ABCD-EFGH-JKLM`, copyable from the row.
- Validation flips the past-dated code to **Expired**; the **Expired** filter lists it.
- The CSV download opens in Excel with `Code, Package, Price, Status, Type, Created, Expires, …`
  and follows the applied filters.

### QA9-12 — Member directory, detail and suspend/restore

| | |
|---|---|
| **Why it matters** | The back office needs one place to see a membership and to stop a bad account. |
| **Where** | `/admin/members`, `/admin/members/{id}` |
| **Evidence** | `final-admin-members.png`, `final-admin-member.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open **Members**. Filter by status **Pending** and by package **Package A**; search a username.
2. Open one member's page.
3. Press **Suspend member** and confirm.
4. Try to sign in as that member in a separate browser profile.
5. Press **Restore member**.

**Expected**

- The directory shows sponsor, package, status, both wallet buckets, joined date and the actions.
- The detail page shows account (email, phone, referral code/link), membership (package, sponsor,
  joined as, activated), wallet buckets, order/commission counters, activation history, upline and
  the recent audit trail.
- Suspending sets **Suspended** / sign-in blocked; the member's existing session is dropped on the
  next request and a new sign-in is refused with the usual invalid-credentials message.
- Restoring returns an associate to **Active** (a member who never had a package returns to
  **Pending**). Both changes appear in the member's audit list (`member.status`).

### QA9-13 — Genealogy shows the sponsor chain and the team, never binary legs

| | |
|---|---|
| **Why it matters** | The V3.0 upline is one sponsor link; the genealogy is the member's proof of who they recruited. |
| **Where** | `/genealogy` as `juan`, then as `maria` |
| **Evidence** | `final-genealogy-team.png`, `final-genealogy-mobile.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `juan`, open **Genealogy**.
2. Sign in as `maria`, open **Genealogy**.

**Expected**

- juan: upline **admin** (L1), direct recruit **maria**, and the team grouped by level with
  peter/sarah/david at level 2.
- maria: upline **juan** (L1) then **admin** (L2); direct recruits peter/sarah/david.
- The referral link and code are shown (Copy works).
- No page mentions binary parent/position, legs, pairs or a placement mode (view source too).

### QA9-14 — The member dashboard is the membership overview

| | |
|---|---|
| **Why it matters** | One screen must answer "what is my membership, what is my money, who is my team, what did I buy". |
| **Where** | `/dashboard` |
| **Evidence** | `final-dashboard.png`, `final-dashboard-pending.png`, `final-dashboard-mobile.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as juan and open `/dashboard`.
2. Sign in as a pending member and open `/dashboard`.
3. Open **Membership** from the dashboard.

**Expected**

- juan: **Active associate**, package, total vs withdrawable wallet, referral link, recent orders,
  team counters and the commissions card (₱0.00 until Phase 10, with the explanatory note).
- Pending: **Pending activation** and the call to activate from Membership.
- Admins still see the back-office dashboard at the same URL.

### QA9-15 — Members cannot reach the back-office membership screens

| | |
|---|---|
| **Why it matters** | Guards are server-side; the menu is only a convenience. |
| **Where** | `/admin/members`, `/admin/codes`, `/admin/activations` as a member |
| **Evidence** | — |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as a member and open each of the three URLs (also POST to `/admin/codes/generate`).

**Expected**

- **403 Access denied** in the EcoWealth layout for each, and no data or rows are exposed.
- A guest is redirected to `/login` instead.

### QA9-16 — Money and data invariants after a membership pass

| | |
|---|---|
| **Why it matters** | Registration and activation move money; the ledger must still reconcile. |
| **Where** | CLI (from `system/`) and the SQL client |
| **Evidence** | — |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Run `php bin\wallet_reconcile.php --json` — expect `"ok": true` and no mismatches.
2. Check that every approved activation has exactly one row and a hook timestamp:
   `SELECT id, user_id, status, commission_processed_at FROM activations WHERE status='approved';`
3. Check no code is used twice and every used code has a member:
   `SELECT code, used_by, used_at FROM reg_codes WHERE status='used';`

**Expected**

- The reconcile command exits 0.
- Approved activations are unique per member (one package at a time) and stamped.
- Used codes all carry `used_by` and `used_at`.

---

## Cleanup (optional)

The demo tree is **seed data** and should stay in development. The two QA evidence fixtures and
their activations/proofs can be removed with:

```sql
DELETE FROM users WHERE username IN ('QA_Member_09', 'QA_Member_10', 'QA_Assoc_09');
DELETE FROM reg_codes WHERE code LIKE 'QA9-%';
```

Any activation proof files those users left under `public/uploads/proofs/` can be deleted once the
rows are gone (the file names are random and start with `qa9-`). The seed's own `DEMO-*` codes and
the demo wallets are part of the seed; remove `database/seeds/005_demo_tree.sql` from a production
install instead of deleting rows by hand.

## Sign-off

| | |
|---|---|
| **Tester** | |
| **Date** | |
| **Result** | ☐ Pass ☐ Fail |
| **Notes** | |
