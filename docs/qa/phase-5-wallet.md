# Phase 5 QA — E-wallet core (manual browser test)

| | |
|---|---|
| **Phase** | 5 — dual balance, immutable ledger, admin top-ups, monitor |
| **Plan reference** | `dmp/plan-ecowealth-cart-system.md` §4.4, §5.8, §7 Phase 5 |
| **App URL** | `http://localhost/ecowealth/system/` |
| **Accounts** | `admin / Admin@123` · `QA_Member_01 / QaPass!2345` (from Phase 1) |
| **Checks** | 16 (QA5-01 … QA5-16) |
| **Time** | 50–65 minutes |
| **Evidence folder** | `docs/qa/evidence/phase-5/` |

## What this phase is meant to prove

Phase 5 adds the money store every later phase writes into. A member has one wallet with **two buckets**
— the total they can spend, and the part they may cash out — and every movement is one immutable ledger
row that records the balance after it. An admin can credit a member's wallet and see the whole float;
the member sees the same movement on their statement.

Three rules matter most:

1. **Top-ups are not cashable.** An admin credit raises the total and the *non-withdrawable* bucket.
   That is deliberate (reference behaviour): the money can buy products but cannot be paid out.
2. **The ledger is the truth.** `ewallet_balance` and `withdrawable_balance` must always equal the
   ledger; the reconciliation command proves it.
3. **A member never reaches the admin screens.** The wallet monitor and its top-up form answer `403`
   to a member, whatever they type in the address bar.

**Already proven automatically:** P5-T1 withdrawable credit · P5-T2 non-withdrawable credit ·
P5-T3 internal debit (locked money first) · P5-T4 refusals (total and bucket) · P5-T5 idempotent replay ·
P5-T6 two-connection concurrency · P5-T7 audited admin top-up + invalid input · P5-T8 invalid
`ref_type` and schema drift · P5-T9 filters/pagination/totals · P5-T10 immutability ·
P5-T11 reconciliation (clean and tampered). The HTTP smoke script repeats the top-up, filter, tab,
guard and reconciliation paths.

**Already captured headlessly** (reference screenshots — still run the checks):

| File | Shows |
|---|---|
| `final-wallet-member.png` | member statement: ₱1,450 total, ₱1,250 withdrawable, ₱200 non-withdrawable, 4 movements |
| `final-wallet-mobile.png` | the same statement at 390 × 844 (Source + Amount layout) |
| `final-wallet-admin-monitor.png` | monitor: float, both buckets, top-up count, member directory |
| `final-wallet-admin-topups.png` | the Top-ups tab |
| `final-wallet-admin-ledger.png` | the All-movements tab with the member column |
| `final-wallet-admin-topup.png` | one member's wallet + the Credit-this-wallet form |
| `final-wallet-topup-success.png` | the green “₱100.00 credited” flash after a real top-up |

> **Before you start:** Phases 1–4 should be signed off. The automated suite must be green
> (`php tests\run.php` → 207 cases; `powershell -NoProfile -File tests\smoke.ps1` → 68 checks). Create
> `docs\qa\evidence\phase-5\` for your own screenshots.

---

## A. Member statement

### QA5-01 — The empty e-wallet explains itself

| | |
|---|---|
| **Why it matters** | A new member lands on a blank page; the two buckets need a plain explanation. |
| **Where** | `/wallet` as `QA_Member_01` (before any top-up) |
| **Evidence** | `QA5-01-wallet-empty.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `QA_Member_01`.
2. Open `http://localhost/ecowealth/system/wallet`.

**Expected**

- Sidebar **Money → E-wallet** is highlighted.
- Three cards: **Total balance ₱0.00**, **Withdrawable ₱0.00**, **Non-withdrawable ₱0.00**.
- The blue **“How the two buckets work”** note mentions commissions, top-ups and spending order.
- The statement card says **“No wallet movements yet”** with the explanation that movements appear as they happen.
- No table rows, no **Clear filters** link.

### QA5-02 — An admin credit appears on both screens

| | |
|---|---|
| **Why it matters** | The core Phase 5 path: admin creates money, the member sees it, the ledger records it. |
| **Where** | `/admin/wallets` → member wallet → `/wallet` |
| **Evidence** | `QA5-02-topup.png`, `QA5-03-member-topup.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Sign in as `admin` and open `/admin/wallets`.
2. Find `QA_Member_01` in the **Members** table and click **Open**.
3. In **Credit this wallet**, enter amount `500.25`, note `QA welcome credit`, click **Credit wallet**.
4. Note the green flash and the new balances on the page.
5. Sign in as `QA_Member_01` (another browser or private window) and open `/wallet`.

**Expected**

- Flash: **“₱500.25 credited to @QA_Member_01 — the amount is spendable in the shop and non-withdrawable.”**
- Admin page now shows **Total balance ₱500.25**, **Withdrawable ₱0.00**, **Non-withdrawable ₱500.25**.
- **Top-up history** lists ₱500.25 by `admin` with the note.
- **Wallet statement** has one row: Date, Credit badge, **Admin top-up**, `QA welcome credit`, **+₱500.25**, **Balance ₱500.25**, **₱0.00 withdrawable**.
- As the member: the three cards match, the statement row matches, and the totals line reads **Credits ₱500.25 / Debits ₱0.00 / Net +₱500.25**.
- **Withdrawable stays ₱0.00** — this is correct for a top-up, not a bug.

### QA5-03 — A second top-up keeps the running balance honest

| | |
|---|---|
| **Why it matters** | Each row must show the balance after it, not the current balance repeated. |
| **Where** | Member wallet after a second credit |
| **Evidence** | `QA5-04-running-balance.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, open the same member wallet and credit `250.00` with note `QA second credit`.
2. Reload `/wallet` as the member.

**Expected**

- Newest row first: **+₱250.00** → **Balance ₱750.25**; below it the older **+₱500.25** → **Balance ₱500.25**.
- Totals: **Credits ₱750.25**, **Debits ₱0.00**, **Net +₱750.25**.
- The header badge says **2 movements**.
- Total balance card **₱750.25**, withdrawable **₱0.00**.

### QA5-04 — An invalid amount is refused cleanly

| | |
|---|---|
| **Why it matters** | The form must reject zero, negative, non-numeric and over-precision amounts without writing anything. |
| **Where** | `/admin/wallets/{id}` as admin |
| **Evidence** | `QA5-05-invalid-topup.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. On the member's wallet page, submit **Credit wallet** with amount `0`.
2. Repeat with `-5`, then `12.345`, then `abc`.
3. Reload the page each time and watch the balance and **Top-up history**.

**Expected**

- Each attempt comes back with a red **“Please fix the highlighted top-up fields.”** flash.
- The **Amount** field is outlined red with the specific message (e.g. “Top-up amount must be greater than 0.”).
- The submitted value stays in the field until you reload.
- **Total balance and Top-up history do not change** — still ₱750.25 and two rows.

### QA5-05 — The statement filters work

| | |
|---|---|
| **Why it matters** | A ledger is only useful if it can be narrowed by type, source, date and note. |
| **Where** | `/wallet` |
| **Evidence** | `QA5-06-filters.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Set **Type** = `Credit`, **Source** = `Admin top-up`, click **Filter**.
2. Check the URL carries `type=credit&ref_type=topup` and both rows remain.
3. Set **Type** = `Debit` and filter.
4. Set **Note** = `second` (keep Type = Credit) and filter.
5. Click **Clear filters**.

**Expected**

- Credit + Admin top-up: 2 rows, both **Admin top-up**.
- Debit: **“No wallet movements yet”** (there are no debits in this phase), the filter values stay selected and **Clear filters** appears.
- Note `second`: only the ₱250.00 row.
- Clear filters returns to both rows and hides the **Clear filters** link.
- Totals always describe the filtered set, never the whole ledger.

### QA5-06 — The admin monitor shows the whole float

| | |
|---|---|
| **Why it matters** | The back office needs one screen for total money held, what is cashable and what is locked. |
| **Where** | `/admin/wallets` as admin |
| **Evidence** | `QA5-07-monitor.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/admin/wallets`.
2. Read the four cards and the Members table.
3. Use the search box for `QA_Member`, then select **Funded only** and filter.

**Expected**

- Cards: **Total float** (sum of every wallet, including the QA member's ₱750.25), **Withdrawable**, **Non-withdrawable**, **Top-ups today** (count + lifetime amount in the hint).
- The header chips show **Credits today** and **Debits today**.
- Members table rows show username, full name/email, role badge, total, withdrawable, non-withdrawable, last movement (“just now”) and an **Open** button.
- Tab badges: **Members** = all accounts (4 in the dev DB), **Top-ups** = the number of admin credits, **All movements** = the ledger row count.
- Search `QA_Member` leaves one row; **Funded only** removes the zero-balance admin accounts.
- The numbers equal the member statement: the member's total 750.25 appears in the table.

### QA5-07 — The two extra tabs list top-ups and all movements

| | |
|---|---|
| **Why it matters** | Top-ups are an audited operation and the ledger is cross-member. |
| **Where** | `/admin/wallets?tab=topups` and `?tab=ledger` |
| **Evidence** | `QA5-08-topups-tab.png`, `QA5-09-ledger-tab.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Click **Top-ups**.
2. Check the row for each credit.
3. Click **All movements**.
4. Search the note `QA second` and inspect the member column.

**Expected**

- Top-ups tab: Date, Recipient (`QA_Member_01`), Amount (+₱500.25 / +₱250.00), Credited by (`admin` badge), Note.
- All movements: a **Member** column; the newest row is the ₱250 credit; clicking a member name opens that wallet.
- The search keeps the tab (`?tab=ledger`) and shows only matching rows.
- The statement filters on this tab work the same as the member page.

### QA5-08 — A member cannot use the admin wallet pages

| | |
|---|---|
| **Why it matters** | Menu hiding is not a guard; the routes must answer 403. |
| **Where** | `/admin/wallets`, `/admin/wallets/{id}` as `QA_Member_01` |
| **Evidence** | `QA5-10-member-403.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Signed in as `QA_Member_01`, open `/admin/wallets`.
2. Open `/admin/wallets/1` directly.
3. In DevTools → Console, run:
   `fetch('/ecowealth/system/admin/wallets/1/credit', {method:'POST', body:new URLSearchParams({amount:'1'})}).then(r=>r.status)`
4. Reload `/wallet` and check nothing was credited.

**Expected**

- Both pages show the branded **403 — Access denied** page.
- The POST returns **403** as well (the guard runs before CSRF/validation).
- The member's balance is unchanged.

### QA5-09 — A guest is sent to login

| | |
|---|---|
| **Why it matters** | The statement is private. |
| **Where** | Incognito window → `/wallet` |
| **Evidence** | `QA5-11-guest-redirect.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open a private window.
2. Open `http://localhost/ecowealth/system/wallet`.

**Expected**

- Redirect to `/login` with the flash **“Please sign in to continue.”** (or the yellow banner on the login page).
- `/admin/wallets` behaves the same way for a guest.

### QA5-10 — The admin has their own statement too

| | |
|---|---|
| **Why it matters** | Admins may use the member portal; `/wallet` must not break their back-office layout. |
| **Where** | Sidebar **Finance → My e-wallet** |
| **Evidence** | `QA5-12-admin-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. As `admin`, click **My e-wallet** in the sidebar (or open `/wallet`).

**Expected**

- The page opens inside the **back-office** layout (Back office menu, E-wallet monitor still listed).
- **My e-wallet** is the highlighted sidebar item.
- The admin's own balances are shown (likely ₱0.00 / ₱0.00 / ₱0.00) with an empty statement.
- No member-only menu items appear.

### QA5-11 — The top-up is audited

| | |
|---|---|
| **Why it matters** | Money created by an admin must be attributable after the fact. |
| **Where** | phpMyAdmin → `ecowealth_sneat` → SQL |
| **Evidence** | `QA5-13-audit.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open phpMyAdmin → database `ecowealth_sneat` → **SQL**.
2. Run:

   ```sql
   SELECT actor_id, actor_role, action, subject_type, subject_id, meta_json, created_at
   FROM audit_log
   WHERE action = 'wallet.topup'
   ORDER BY id DESC
   LIMIT 5;
   ```

3. Compare `subject_id` with the QA member's id (`SELECT id FROM users WHERE username='QA_Member_01';`).

**Expected**

- One row per top-up made in this run, newest first.
- `actor_id` is the admin's id, `actor_role` is `admin`, `subject_type` is `user`, `subject_id` is the QA member.
- `meta_json` contains `amount` and `topup_id` matching the credit.

### QA5-12 — The ledger reconciles

| | |
|---|---|
| **Why it matters** | Success criterion 4/7: the two buckets always equal the ledger. |
| **Where** | Git Bash **or** PowerShell, in `C:\laragon\www\ecowealth\system` |
| **Evidence** | `QA5-14-reconcile.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open a terminal in `C:\laragon\www\ecowealth\system` — Git Bash or PowerShell both work.
2. Run the command for your shell (they print the same report).

   **Git Bash** — use forward slashes. In bash a backslash is an escape character, so
   `bin\wallet_reconcile.php` becomes `binwallet_reconcile.php` and the script will not run.

   ```bash
   cd /c/laragon/www/ecowealth/system
   php bin/wallet_reconcile.php
   ```

   If `php` is not found on your Git Bash `PATH`, use the absolute PHP path (works from any folder):

   ```bash
   "/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe" "/c/laragon/www/ecowealth/system/bin/wallet_reconcile.php"
   ```

   **PowerShell** — keep the backslashes, and put `&` in front of a quoted path:

   ```powershell
   cd C:\laragon\www\ecowealth\system
   & 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' .\bin\wallet_reconcile.php
   ```

   Add `--json` to either command for machine-readable output.

3. Then run the SQL below in phpMyAdmin.

   ```sql
   SELECT u.username, u.ewallet_balance, u.withdrawable_balance,
          COALESCE(SUM(CASE WHEN l.type='credit' THEN l.amount ELSE -l.amount END), 0) AS ledger_total
   FROM users u
   LEFT JOIN ewallet_ledger l ON l.user_id = u.id
   GROUP BY u.id
   HAVING u.ewallet_balance <> ledger_total;
   ```

**Expected**

- The command prints the same report in both shells:

  ```text
  EcoWealth wallet reconciliation
    database:          ecowealth_sneat (127.0.0.1)
    accounts checked:  4
    accounts with ledger: 1

  OK — every wallet matches its ledger on both buckets.
  ```

  (`accounts with ledger` is 1 after the top-ups from QA5-02/03; it is the number of accounts that
  have any ledger row, never the number of rows.)
- Exit code **0**. A mismatch exits **1** and prints the account and the expected/actual amounts;
  **2** means the command could not run at all (for example, the database is down).
- If the shell says **“Could not open input file: bin\wallet_reconcile.php”**, the command was run from
  the wrong folder (the repo root instead of `system/`) or a backslash was lost in Git Bash — rerun the
  matching block above from `C:\laragon\www\ecowealth\system`.
- The SQL query returns **zero rows**.
- No wallet has `withdrawable_balance > ewallet_balance` or a negative bucket.

### QA5-13 — Mobile layout

| | |
|---|---|
| **Why it matters** | The statement is the page members check on a phone. |
| **Where** | DevTools device toolbar, iPhone 12 Pro (390 × 844) |
| **Evidence** | `QA5-15-mobile-wallet.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/wallet` as `QA_Member_01`.
2. Press `Ctrl+Shift+M`, choose **iPhone 12 Pro**.
3. Scroll to the statement.

**Expected**

- Cards stack vertically with readable amounts.
- Filters stack one per line; **Filter** stays reachable.
- The ledger shows **Source** and **Amount** only; the date and **Credit** tag appear under the source, and **Balance** appears under the amount.
- No horizontal scrolling of the page (the table must not push the layout wider than 390px).

### QA5-14 — Admin monitor at mobile width (optional)

| | |
|---|---|
| **Why it matters** | The back office is desktop-first, but the monitor should not break on a tablet. |
| **Where** | `/admin/wallets` at 390 × 844 |
| **Evidence** | `QA5-16-monitor-mobile.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Keep the device toolbar on and open `/admin/wallets` as admin.

**Expected**

- The four cards stack two per row (or one per row on the narrowest setting).
- Tabs stay on one row and are tappable.
- The member table scrolls **inside its card** rather than widening the page.

### QA5-15 — Earlier phases still work

| | |
|---|---|
| **Why it matters** | Phase 5 changed the menu, the schema and `APP_PHASE`. |
| **Where** | `/shop`, `/cart`, `/packages`, `/admin/products` |
| **Evidence** | `QA5-17-regression.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Open `/shop` — the product cards and prices render.
2. Add a product to the cart; the drawer and badges update.
3. Open `/packages` — Package A ₱4,498 and Package B ₱6,998 with their ladders.
4. Open `/admin/products` — the catalogue table renders, search works.
5. Check the sidebar: **E-wallet** under Money; no **Orders**, **Payouts**, **Earnings** (later phases).
6. In DevTools → Console, confirm there are no red errors.

**Expected**

- All pages load, the Phase 1–4 behaviour is unchanged, and the console is clean.
- The only new menu items are the wallet ones.

### QA5-16 — Cleanup restores the starting state

| | |
|---|---|
| **Why it matters** | The dev database should be left as found. |
| **Where** | phpMyAdmin → SQL |
| **Evidence** | `QA5-18-cleanup.png` |
| **Result** | ☐ Pass ☐ Fail ☐ Blocked — Notes: |

**Steps**

1. Run:

   ```sql
   SET @qa = (SELECT id FROM users WHERE username = 'QA_Member_01');
   DELETE FROM ewallet_admin_topups WHERE recipient_id = @qa;
   DELETE FROM ewallet_ledger       WHERE user_id      = @qa;
   DELETE FROM audit_log            WHERE action = 'wallet.topup' AND subject_id = @qa;
   UPDATE users SET ewallet_balance = 0.00, withdrawable_balance = 0.00 WHERE id = @qa;
   ```

2. Reload `/wallet` as the member.

**Expected**

- The member balance is ₱0.00 / ₱0.00 / ₱0.00 and the statement is empty again.
- The admin monitor's float matches the remaining accounts.
- The reconciliation command from QA5-12 (Git Bash or PowerShell) still prints **OK**.
- No `QA5-*` rows are left in `products`, `packages` or `users`.

---

## Cleanup checklist

- [ ] Wallet rows and audit entries for `QA_Member_01` deleted (QA5-16 SQL)
- [ ] No `SMOKE-` products or `Smoke Package` rows left (the smoke script removes its own)
- [ ] Maintenance mode off
- [ ] Evidence screenshots saved in `docs/qa/evidence/phase-5/`
- [ ] Results filled into [sign-off-sheet.md](sign-off-sheet.md)

## Known and accepted behaviour (not bugs)

- **A top-up never raises the withdrawable bucket.** Phase 5 has no commissions yet, so a wallet funded
  only by top-ups shows **Withdrawable ₱0.00** by design.
- **There are no debit rows in manual QA.** Purchases arrive in Phase 6, transfers in Phase 12 and payouts
  in Phase 13; the internal-debit, transfer and payout paths are covered by the automated suite until then.
- **The statement is append-only.** There is no edit or delete button anywhere; corrections are new
  `refund`/`adjustment` rows.
- **The admin can credit their own wallet.** Allowed on purpose and recorded in the audit log.
- **Top-ups never collapse.** A reminder: each admin credit is a new top-up row even with the same amount
  and note; idempotency applies to system movements that carry a source id.
