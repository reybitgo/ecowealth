# EcoWealth runbook

**Audience:** whoever operates the EcoWealth installation — the system administrator, the finance
admin and the developer on call. It is the day-to-day companion to
[`deploy.md`](deploy.md) (first install and upgrades), [`cron.md`](cron.md) (job detail) and
[`live-updates.md`](live-updates.md) (the no-refresh protocol).

**Scope:** the Phase 19 build — shop, orders, payments, fulfilment, registration and codes, the
dual-balance e-wallet, commissions, transfers, payouts, super-login, the reports/audit desk, the
repeat-purchase bonus (earn, escrow and release) and the monthly Royalty bonus.

---

## 1. The five-minute daily check

| # | Check | Where | Healthy looks like |
|---|---|---|---|
| 1 | Finance health | **Admin → Reports → Finance health** | Green **All clear**, 8 checks, one line per invariant |
| 2 | Reconciliation | `php bin\wallet_reconcile.php` | exit **0**, "All good — wallets, commissions, transfers, payouts, repeat-purchase and royalty awards reconcile" |
| 3 | Orders that need action | **Admin → Dashboard → Orders needing action** | the number you expect for the day; nothing stuck in `payment_review` |
| 4 | Payout hold | **Admin → Dashboard → Held for payout** / **Payouts** | every open request has a reason to wait; none older than a day |
| 5 | Audit desk | **Admin → Audit log** | today's settings changes, exports and S-Login exits are the ones you made |
| 6 | Cron log | `system\storage\logs\*.log` | one line per run for each job; no `[ERROR]` |
| 7 | Repeat-purchase release | `php bin\unilevel_release.php --doctor` | free lock, writable log, `cancelled_due` **0**, `due_now` 0 after the last run; pending is the only figure that should be non-zero on a normal day |

If check 1 or 2 fails, stop and read §5 before touching money. Nothing in this app repairs a
balance automatically; a mismatch means a write happened outside its service.

---

## 2. Scheduled jobs

Six jobs run unattended; the exact commands, Windows Task Scheduler registration, kill switches
and log formats are in [`cron.md`](cron.md) (see §5 for the Task Scheduler block).

| Job | Schedule | Entry |
|---|---|---|
| Shop expiry (unpaid orders) | every 15 minutes | `bin\shop_expire.php --limit=200` |
| Delivered-order auto-complete | hourly | `bin\order_autocomplete.php` |
| Transfer-limit reset | daily at 00:00 | `bin\reset_transfer_limits.php` |
| Unilevel month close (the Active snapshot) | monthly, 00:15 on the 1st | `bin\unilevel_month_close.php` |
| Unilevel release (credits pending repeat-purchase awards) | hourly at :20 | `bin\unilevel_release.php --limit=200` |
| Royalty month close (evaluates and pays the Royalty bonus) | monthly, 00:30 on the 1st | `bin\royalty_month_close.php --max-batches=20` |

All six are idempotent, take an advisory lock, write one monthly log line and have a `--doctor`
mode. A second overlapping run logs `[SKIP ]` and exits 0. The unilevel month close pays no money and
has no kill switch: it must snapshot a month even while the repeat-purchase stream is off. It catches
up on any month it missed, oldest first. The release job **does** have a switch
(`unilevel_release_enabled`): turning it off pauses the credits but keeps every pending award, and
cancelling an order still cancels its pending awards — provided **Enable Cancel of Order** is on
(it ships off; see §“Order cancellation is off by default” in the admin guide).

**If a job misses a day:** the expiry and auto-complete jobs catch up on their next run (they act on
stored deadlines); the month close closes every pending month on its next run; every due release is
credited on the next release run (the window is day-granular, so a missed hour loses nothing); the
weekly transfer counter keeps yesterday's usage until the next Monday, and a limit can only get
*stricter* in the meantime, never wider. Run `php bin\reset_transfer_limits.php --week` once to clear
it by hand.

### The monthly Royalty close drill

Royalty is the other monthly stream: one award per member per **closed calendar month**, paid to the
withdrawable e-wallet. It has its **own** job and its own tables; it never reads or writes the
repeat-purchase ledger, and the two closes are independent. Phase 19F schedules it, adds the doctor
and the operations panel. On the 1st the cron runs it unattended; `docs/cron.md` §7 is the reference.
Run this drill once by hand, then on the first live 1st:

```powershell
cd C:\laragon\www\ecowealth\system

# 1. The unilevel close runs first, so the two logs read in order (00:15).
php bin\unilevel_month_close.php --doctor
php bin\unilevel_month_close.php

# 2. The Royalty doctor must be green before any money moves (00:25-ish).
php bin\royalty_month_close.php --doctor

# 3. See exactly what the close would write and pay. Nothing is written.
php bin\royalty_month_close.php --dry-run --json

# 4. The real run: resume evaluated months, then close every pending month.
php bin\royalty_month_close.php --max-batches=20
exit 0

# 5. The panel says the same thing the job just did.
#    Admin -> Commissions -> Royalty -> Operations:
#      open month (projection only), last final month + amount,
#      pending periods (should be none), awaiting payment (should be none),
#      the two switches, the last close time.

# 6. The books reconcile, and the first scheduled run is a no-op.
php bin\wallet_reconcile.php
php bin\royalty_month_close.php
exit 0        # "nothing to close" — the month is final
```

**Read the result.** The run prints `closed`, the months it touched, the award rows written and the
money paid; the log line in `system/storage/logs/royalty_month_close-YYYY-MM.log` carries the same
numbers (`closed= periods= award= paid= amount= skipped= status= paused=`). A second run exits 0 with
`closed=0`. On the panel, `Awaiting payment` must be **None** and `Pending periods` must be **None**;
anything else means the close did not finish, and the panel names the month and the command to finish
it.

**The review window (optional).** `--evaluate-only` writes the month's awards and pays nothing — the
window `cron.md` §7 mentions. Use it when a month should be checked before money moves: run
`php bin\royalty_month_close.php --period=<P> --evaluate-only`, read the ledger
(**Admin → Commissions → Royalty**, or `php bin\royalty_month_close.php --period=<P> --dry-run --json`
for a preview that writes nothing at all), then pay with
`php bin\royalty_month_close.php --period=<P> --pay-only`. Keep the window to **a day or two**, and
never past the next close: until it is paid the month reads **Awaiting payment** on the operations
panel, its money is not in any wallet, and the doctor warns once an evaluated month is older than
48 hours.

**If a month is left `evaluated`** (a crash, or `--max-batches` reached its ceiling): the batches
already paid are committed and the remaining rows are still `pending`. There is nothing to repair —
run `php bin\royalty_month_close.php --pay-only --period=<P>` (the panel shows the exact command).
The conditional `pending → paid` update and the wallet's own `(user, ref_type, ref_id, type)` anchor
make the resume exact: a row that is already paid is skipped and no award can credit twice.
`php bin\wallet_reconcile.php` reports the remaining liability in its **Royalty** section.

**If the month reads `skipped`:** the stream was off when it closed. That is final — a skipped month
can never be paid later. Turn `royalty_enabled` on **before** the close on the 1st to make that month
the first paid one.

**Never edit a closed award.** A `paid`/`skipped` month is a financial record: there is no reopen, no
`UPDATE royalty_awards`, no recalculation, and no clawback when an order is cancelled afterwards
(D-13). The only legal writes are `pay()`'s `pending → paid` plus `ledger_id`/`credited_at`. If a
month was closed with the wrong ladder or the wrong volume, that is a **finance correction outside
the app** — record it in the period's note in the books and change the setting for the next month;
do not rewrite the closed row. To stop future Royalty, set `royalty_enabled = 0` (earning) and/or
`royalty_pay_enabled = 0` (payment); never drop the two tables once an award row exists.

**Never run a close from a browser tab as the "official" run.** The URL entry exists for local
testing and for a host whose cron only calls URLs; it follows the same lock, the same rules and the
same log, but the CLI is the scheduled path and the one whose exit code your cron mail reports.

### Team Harvest go-live order (once, Phase 20C)

Team Harvest is the **only** stream with no schedule: no month, no close job, no cron entry. Its one
job is the go-live **seed**, and the order is the whole point of it — the registration hook is inert
until the seed writes the baseline instant, so a deploy can never queue or pay anything by accident.

```powershell
cd C:\laragon\www\ecowealth\system

# 1. Deploy the code (it ships with team_harvest_enabled = 0 and an unseeded queue).
#    backend -> /admin/settings -> Commissions -> Team Harvest bonus must read
#    "Not seeded - no position has been assigned".

# 2. Preview. This writes nothing and is the report the business decides on:
#    the members that would be queued, positions 1..N in qualification order,
#    the baseline instant, and the levels already complete at that instant
#    (their count and their amount - the money the baseline excludes, D-07).
php bin\harvest_queue_seed.php --dry-run

# 3. Reconstruct the queue. One transaction; refused if the queue already has rows.
php bin\harvest_queue_seed.php
exit 0

# 4. Check the counters against a from-scratch recount. Must report 0 drift.
php bin\harvest_queue_seed.php --rebuild --dry-run
exit 0

# 5. Only now turn the money on: /admin/settings -> Commissions ->
#    Enable the Team Harvest bonus, then Save settings.
#    No award is written while the switch is off; the queue grows regardless.
```

**Read the result.** Step 2 and step 3 print the same positions; `baseline_n` equals `teammates` on
every row the seed wrote, and `team_harvest_ledger_since` holds the seed's timestamp. Step 4 says
every counter equals a recount. `php bin\wallet_reconcile.php` is untouched by the whole sequence: the
seed moves no money (levels complete at the baseline are recorded by the award engine as `baseline`
rows and are never credited).

**If the seed is run twice:** it refuses (`exit 1`, "already seeded … holds N position(s)"). That is
not an error — positions are facts and are never renumbered. Use `--dry-run` to look again, or
`--force` to append only the members that are missing (after the existing positions, never before).

**If a member is missing from the queue** because a registration happened while the seed had not run
or a job failed: `php bin\harvest_queue_seed.php --rebuild` names them under `missing:`; `--rebuild
--apply` appends them with `source = 'repair'` and audits `harvest.queue.repair`. A member who holds
the gate is never deleted from the queue and never renumbered.

**If a counter looks wrong** (a level completes that should not, or a level that should complete does
not): `php bin\harvest_queue_seed.php --rebuild` reports `stored N  recount M` per member and writes
nothing; `--rebuild --apply` repairs the counter to the recount, refuses to lower a counter below its
`baseline_n` (that can only mean a descendant left the tree), and audits every before/after. Positions
are never touched by a repair.

**Never edit `harvest_queue` by hand.** There is no supported way to change a position, and a manual
`UPDATE teammates` is exactly what `--rebuild` exists to detect and undo. If the queue is wrong in a
way the repair cannot express, stop and record it: the alternative (re-seeding a live queue) rewrites
history.

### Team Harvest award and reconcile drill (Phase 20D)

The stream pays **at the qualification event**: a registration that completes a level writes the award
row and credits the withdrawable e-wallet in the same transaction. There is no cron entry, no month and
no close (D-08) — the only on-demand job is the catch-up/doctor, and it exists for four moments:

1. **After enabling the switch.** Registrations that happened while it was off queued positions but
   paid nothing; the catch-up settles that backlog once.
2. **After a repair** (`harvest_queue_seed.php --rebuild --apply`): the recount may have completed a
   level the engine has not seen.
3. **After a counter-drift alarm**: pay whatever the corrected counter now supports.
4. **After a deploy that fixed a bug**: the engine is state-based, so re-running it is always safe.

```powershell
cd C:\laragon\www\ecowealth\system

# 0. Is the stream healthy? Read-only: clocks, tables, settings, seed state,
#    baseline, queue, awards, counter drift and the reconcile verdict.
php bin\team_harvest_catchup.php --doctor
exit 0

# 1. What would it pay, level by level? Nothing is evaluated or written.
php bin\team_harvest_catchup.php --dry-run

# 2. Pay the backlog. One transaction per batch (default 500 members). It audits
#    team_harvest.catchup with what it evaluated and what it paid.
php bin\team_harvest_catchup.php
exit 0

# 3. One member only (a disputed award, a support ticket).
php bin\team_harvest_catchup.php --member=42

# 4. The books.
php bin\wallet_reconcile.php
exit 0
```

**Read the result.** The catch-up prints `evaluated`, `paid` (count and amount) and `baseline` (levels
recorded as complete before go-live, never paid). A second run prints `paid: 0` — if it does not, a
level is being paid twice and you stop, dump the database, and report it: `team_harvest_awards` has a
`UNIQUE (user_id, level)` anchor precisely so that cannot happen.

**Exit codes.** `0` clean / nothing to do / another run holds the lock; `1` refused (the queue is not
seeded) or a problem found; `2` the database is unreachable or a table is missing. A `1` from
`--doctor` means one of the nine checks is red — read the failing line, it names the member and both
numbers where it can.

**Rediscovering a backlog after an incident.** Run `--dry-run` first, read the per-level counts, then
the real run, then `--doctor` and `wallet_reconcile.php`. Never enable the switch to "see what happens":
the dry run shows the same number without paying anybody.

**A `baseline` row is not a missing payment.** It is a level that was already complete at the go-live
seed: the amount is recorded so the member can see it, `ledger_id` is `NULL` and it is never credited
(D-07). The decision to pay those levels is a business decision with its own budget (§18 Q1), not a
repair.

**If the reconcile reports `counter_drift`:** the stored `teammates` disagrees with a from-scratch
recount. Run `php bin\harvest_queue_seed.php --rebuild` to see the member, then `--rebuild --apply` to
repair the counter (never the position), then the catch-up. If it reports `baseline_violation` (a
counter below its `baseline_n`), a descendant left the tree — that cannot happen through the
application, so stop and restore the backup rather than lowering the counter.

**Never edit `team_harvest_awards` or its wallet movement.** There is no void, no reopen and no
correction tool in v1 (D-12): a wrong award is a forward-only business decision recorded in the phase
log (§18 Q3). The reconciler *detects* a hand edit — it never repairs one.

---

## 3. Backups, install, reset and restore

### What to back up

| Item | Why |
|---|---|
| **Database** `ecowealth_sneat` | every order, wallet, ledger row, audit entry |
| `system/app/config.local.php` | DB credentials and local flags (never committed) |
| `system/public/uploads/` | product/package images and payment proofs |
| `docs/` and the repo | the runbook and the phase history |

`backups/` is git-ignored; keep copies **outside** the web root. Never expose a dump through Apache.

### Take one

```powershell
$dump = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe'
New-Item -ItemType Directory -Force backups\$(Get-Date -Format yyyyMMdd) | Out-Null

& $dump -u root --no-tablespaces --single-transaction --routines --triggers `
    --default-character-set=utf8mb4 ecowealth_sneat > backups\$(Get-Date -Format yyyyMMdd)\db.sql
```

- `--single-transaction` gives a consistent InnoDB snapshot without locking the shop.
- `--no-tablespaces` avoids the `PROCESS` privilege error on a restricted MySQL account.
- Verify the file is not 0 bytes and starts with `-- MySQL dump`.

Back up **before every migration** and keep the last seven daily dumps plus one monthly dump.

### Restore drill (executed 2026-11-11)

The Phase 15 gate requires a real restore, not just a dump. The drill that was signed off:

```powershell
# 1. Dump the live dev database.
& $dump -u root --no-tablespaces --single-transaction --routines --triggers `
    --default-character-set=utf8mb4 ecowealth_sneat > backups\phase-15\db-restore-drill-source.sql

# 2. Restore into a scratch schema (never the live one).
& $mysql -u root -e "DROP DATABASE IF EXISTS ecowealth_sneat_restore_test;
                     CREATE DATABASE ecowealth_sneat_restore_test
                       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content backups\phase-15\db-restore-drill-source.sql -Raw |
    & $mysql -u root ecowealth_sneat_restore_test

# 3. Give the app's MySQL user access to the scratch schema.
& $mysql -u root -e "GRANT ALL PRIVILEGES ON ecowealth_sneat_restore_test.* TO 'ecowealth'@'127.0.0.1';
                     FLUSH PRIVILEGES;"

# 4. Point the app at it without editing config: ECO_DB_NAME is read by config.php.
$env:ECO_DB_NAME = 'ecowealth_sneat_restore_test'
php bin\wallet_reconcile.php          # exit 0
Remove-Item Env:ECO_DB_NAME

# 5. Drop the scratch schema when done.
& $mysql -u root -e "DROP DATABASE IF EXISTS ecowealth_sneat_restore_test;"
```

**Result (2026-11-11):** dump 1,159,406 bytes; restore exit 0; row counts identical to the source
(26 users · 16 orders · 48 ledger rows · 12 commissions · 2 payout requests · 1,781 audit rows ·
45 settings); `wallet_reconcile.php` exit 0; `HealthService` 7/7 checks clean against the restored
copy. The scratch schema was dropped afterwards.

**Recovery point:** a dump taken with `--single-transaction` is consistent to the instant it
started. A full restore on this machine took under ten seconds. Restoring onto a different host
also needs `config.local.php`, the uploads folder and the `system/storage` folders.

### Install a fresh database

Every install path ends at the same state: **33 tables, 73 settings, 2 staff accounts, 7
products, 2 packages, 18 ladder levels, 10 package contents, 0 members, 0 codes**. None of
them may be run against a schema that already holds data — `install.sql` is fresh-install only,
and an existing database is upgraded with `database/migrations/NNN_*.sql` ([§5](#5-deploying-an-update)).

**Local (Laragon) through the MySQL client** — fastest and exact:

```powershell
# once: create the schema and user (system/README.md quick start has the SQL)
mysql -u root ecowealth_sneat < database/install.sql

# verify
mysql -u root ecowealth_sneat -e "SHOW TABLES; SELECT COUNT(*) FROM settings;"
```

**Local with operator-chosen staff passwords** (imports the same bundle, verifies it, writes
`storage/install.lock`):

```powershell
php bin/db_install.php --check
php bin/db_install.php --database=ecowealth_sneat --force `
  --admin-email=owner@example.com --admin-password-stdin `
  --superadmin-email=owner+super@example.com --superadmin-password-stdin
```

**Online through phpMyAdmin (no SSH):** create the empty database and user in hPanel, select
the schema in phpMyAdmin, **Import** → `database/install.sql` → **Stop on error** → **Go**, and
read the footer counts.

**Online through the browser installer (no SSH):** upload the app, create
`config.local.php` with `APP_ENV=production` and a 32+ character `INSTALL_KEY`, then open
`https://…/system/install.php`, resolve every red preflight row, fill the site name and both
staff credentials, paste the key, and type `INSTALL`. The success page lists the accounts
(never their passwords), the verification counts and the lock path; delete `public/install.php`
immediately afterwards.

After any install: sign in, change both staff passwords if the static bundle was used (it seeds
`Admin@123` / `Super@123`), run the verification pack in [deploy.md §11.6](deploy.md#116-check-the-database),
and retire the tools ([§7](#7-go-live-checklist-plan-96)). If an import fails midway, do **not**
re-run the bundle: drop the schema, recreate it and import again (or restore the pre-install
dump).

### Reset a development/QA database

A reset returns a **scratch or development** schema to the clean install state and keeps both
staff accounts and the configuration. It is the supported way to clear test members, orders,
wallets and codes; it is **not** a restore, a backup or an uninstall.

```powershell
# 0. It has no undo: take the dump above, or make sure the schema is disposable.
$env:ECO_DB_NAME = 'ecowealth_sneat_qa'

# 1. Look first. --dry-run writes nothing and prints the statement plan.
php bin\db_reset.php --dry-run

# 2. Reset. Type the database name at the prompt.
php bin\db_reset.php

#    Non-interactive form (CI, scripts): --force skips only the prompt.
php bin\db_reset.php --force --confirm=$env:ECO_DB_NAME --codes=5 --code-package=1

# 3. Prove it: the same four reconciliations the reset itself runs.
php bin\wallet_reconcile.php          # exit 0

# 4. Point the app at the schema and sign in as admin (password unchanged).
#    Confirm /admin/members = 0, /admin/orders = 0 and the catalogue is intact.
Remove-Item Env:ECO_DB_NAME
```

#### Through the web console (development/QA)

When there is no terminal, use `http://localhost/ecowealth/system/reset.php`:

1. Sign in as an `admin` or `superadmin` first; a member gets **403** and an S-Login
   (impersonated) tab gets **403**.
2. Check the preview counts and the "what will be cleared" list.
3. Tick the backup acknowledgement, type `RESET`, choose the catalogue/audit/upload/code
   options, and submit. The Reset button is disabled until the phrase and the
   acknowledgement are present; the server re-checks both.
4. Read the report page: step log, verification checklist, warnings, before/after counts and
   the fresh codes (click to copy). Refresh re-renders the report and never runs again;
   **Done** clears it from the session.
5. Take the same post-reset checks as the CLI path (`/admin/members` = 0, `/admin/orders` = 0,
   catalogue intact, `php bin\wallet_reconcile.php` exit 0 when CLI is available).

Do **not** put this page on a production host. Production returns the branded **404**
unless `DB_RESET_ENABLED` is explicitly `true`; even then only a superadmin may use it and
the confirmation is the database name. Delete `public/reset.php` after the QA round.

What it does, in one transaction: deletes S-Login and remember-me sessions, orders and their
children, carts, addresses, ledger, top-ups, transfers, payouts, commissions, activations,
codes and finally the **members** (staff rows survive); resets both staff rows to the install
defaults; optionally deletes and re-seeds the catalogue (`--reset-catalogue`); optionally
clears the audit log (`--clear-audit`); bumps every live revision; writes
`last_reset_at`/`last_reset_by` and one `system.db_reset` audit row. After the commit it resets
the AUTO_INCREMENT counters of empty tables and deletes proof images under
`public/uploads/proofs/` (unless `--keep-uploads`). It then verifies the result and exits `1`
if any check fails.

| Rule | Why |
|---|---|
| **Never run it on production without an approved change window** | `APP_ENV=production` needs `--allow-production`; the web console (IR-6) is off by default |
| **Never run it on a schema you cannot lose** | The only recovery is the dump from §3 |
| **Never patch `users` balances by hand after a failure** | Read the verification detail and restore the backup; the services are the only writers |
| **The confirmation is a database name, not `y`** | It makes the target explicit and cannot be satisfied by an accidental Enter |
| **`--keep-uploads` still deletes the rows** | It only leaves the image files behind (they become orphans) |

If the transaction fails, the report says nothing was deleted — re-run after fixing the
cause. If the reset commits but verification fails, the data is reset: run
`php bin\wallet_reconcile.php`, read the failing check, and restore the pre-reset dump if real
money was involved. Full engine detail: `dmp/plan-ecowealth-install-reset.md` §7; CLI flags:
`php bin\db_reset.php --help`.

---

## 4. Money: what to check and what never to do

### Invariants (plan §6.8)

1. `users.ewallet_balance` = credits − debits in `ewallet_ledger`.
2. `withdrawable_balance ≤ ewallet_balance` and both ≥ 0.
3. Credited commission rows equal the commission ledger total.
4. Every order total equals the sum of its lines.
5. No product holds more stock than it has.
6. Every award points at a real activation and its ledger row; every approved activation is processed.
7. Every completed payout has one ledger debit; a rejected payout has one refund and no hold.
8. Every credited or voided repeat-purchase award has its `ref_type='unilevel'` credit; every
   `ref_type='unilevel_reversal'` debit equals the award's `reversed_amount`; a `pending` or
   `cancelled` award has **no** ledger row.
9. `shop_orders.unilevel_total` equals the sum of that order's **`pending` + `credited`** awards (the
   awarded total, not the released total).

Checks 1–3, 7 and 8 are also the five service reconciliations behind
`bin\wallet_reconcile.php`; the Reports page adds 4–6 in the **Finance health** widget.

### If a mismatch appears

1. **Do not edit `users.ewallet_balance` and do not delete ledger rows.** The ledger is the audit
   trail; deleting it turns one visible problem into an untraceable one.
2. Run `php bin\wallet_reconcile.php --json` and note the affected user ids and amounts.
3. Open **Admin → E-wallet monitor → the member** and match the latest ledger row
   (`balance_after`, `withdrawable_after`) against the wallet.
4. Search **Admin → Audit log** for the member and the period; a correct movement always has an
   audit row or an order/commission/transfer/payout reference.
5. Fix forward: a correction is a new `adjustment` movement (or a `voided` commission plus an
   adjustment), written by an admin through a service — never an `UPDATE users SET …` from SQL.
6. Re-run the reconciliation and the Reports health card; both must be clean before the incident is
   closed. Record what happened in the phase log.

### Repeat-purchase awards — escrow and release (Phases 17C–17F + 18)

**What it pays.** A paid shop order awards the product's 1–10 ladder to the buyer's sponsor chain:
level 1 is the direct sponsor, level 10 the tenth upline. The buyer earns nothing. Since the escrow
change (Phase 18) the award is written as **`pending`** and **no money moves** at payment; the release
job later credits the upline's **withdrawable** wallet (`ref_type = 'unilevel'`,
`ref_id = unilevel_awards.id`) once the order is delivered and the award's snapshotted
`hold_days` window has passed. The stream is off until **both** `settings.unilevel_enabled = 1` and
the product's `products.unilevel_enabled = 1`; an order paid with either off is stamped with a zero
total and can never be paid later.

The award states are the whole operational picture:

| `status` | Money | Meaning |
|---|---|---|
| `pending` | none | earned at payment; **not** in a wallet; releases after delivery + its hold |
| `credited` | one `unilevel` credit | released; `credited_at` and `ledger_id` set |
| `cancelled` | none | the order was cancelled before release; never paid, **no shortfall** |
| `voided` | one reversal debit | released then reversed; `reversed_amount` may be less than `amount` |
| `skipped` | none | the recipient was ineligible; an audit note, never money |

`shop_orders.unilevel_total` is the **awarded** total — `pending + credited`, not the released total.

**Weekly checks.**

```sql
-- Paid orders the app paid but the stream never decided (should be empty).
SELECT o.id, o.order_no, o.paid_at FROM shop_orders o
 WHERE o.paid_at >= (SELECT value FROM settings WHERE key_name = 'unilevel_ledger_since')
   AND o.status IN ('paid','packing','ready_to_ship','shipped','delivered','completed')
   AND o.unilevel_processed_at IS NULL
   AND EXISTS (SELECT 1 FROM shop_order_events e WHERE e.order_id = o.id AND e.to_status = 'paid');

-- Award status counts and money by period (a quick finance read: pending is earned, not yet paid).
SELECT DATE_FORMAT(paid_at, '%Y-%m') AS period, status, COUNT(*) AS n, SUM(amount) AS amount
  FROM unilevel_awards GROUP BY period, status ORDER BY period DESC, status;

-- Unrecovered shortfalls: released money the wallet could not return. Only voided awards count.
SELECT order_id, user_id, amount, reversed_amount, amount - reversed_amount AS shortfall
  FROM unilevel_awards WHERE status = 'voided' AND reversed_amount < amount ORDER BY id DESC;
```

**The escrow checks (weekly, and whenever a member asks about a pending bonus).**

```sql
-- 1. Pending total and count: the liability that is earned and not yet in a wallet.
SELECT COUNT(*) AS pending_awards, COALESCE(SUM(amount), 0) AS pending_total
  FROM unilevel_awards WHERE status = 'pending';

-- 2. The oldest pending award and its age — a large age means an undelivered order.
SELECT MIN(a.created_at) AS oldest_pending,
       DATEDIFF(NOW(), MIN(a.created_at)) AS age_days
  FROM unilevel_awards a WHERE a.status = 'pending';

-- 3. Pending awards on shipped orders (in transit, waiting for delivery — normal but watch the age).
SELECT o.id, o.order_no, o.shipped_at, COUNT(*) AS awards, SUM(a.amount) AS amount
  FROM unilevel_awards a JOIN shop_orders o ON o.id = a.order_id
 WHERE a.status = 'pending' AND o.status = 'shipped'
 GROUP BY o.id, o.order_no, o.shipped_at ORDER BY o.shipped_at ASC;

-- 4. Due but not released: the release job should have credited these. Must be 0 after a clean run.
SELECT a.order_id, COUNT(*) AS awards, SUM(a.amount) AS amount,
       MIN(DATE_ADD(COALESCE(o.delivered_at, o.completed_at), INTERVAL a.hold_days DAY)) AS first_due
  FROM unilevel_awards a JOIN shop_orders o ON o.id = a.order_id
 WHERE a.status = 'pending'
   AND o.cancelled_at IS NULL
   AND o.status IN ('delivered','completed')
   AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
   AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at), INTERVAL a.hold_days DAY) <= NOW()
 GROUP BY a.order_id ORDER BY first_due ASC;

-- 5. A cancelled order must never keep a pending award. Must return 0 rows.
SELECT o.id, o.order_no, o.cancelled_at, COUNT(*) AS pending_awards
  FROM unilevel_awards a JOIN shop_orders o ON o.id = a.order_id
 WHERE a.status = 'pending' AND o.cancelled_at IS NOT NULL
 GROUP BY o.id, o.order_no, o.cancelled_at;
```

`php bin\wallet_reconcile.php` reports a **Repeat-purchase** section (pending/cancelled counts and
amounts, credited/released, the `unilevel` and `unilevel_reversal` totals, and the baseline). Exit 0
means the awards, their wallet movements and the order totals agree. Stuck pending is printed as a
warning, not a mismatch.

**The release job and its doctor.** Run the job by hand only to catch up or to diagnose; the hourly
cron does the real work ([`cron.md` §6](cron.md)).

```powershell
php bin\unilevel_release.php --doctor     # clocks, switches, hold, pending, oldest, due, undelivered, stuck, cancelled_due, lock, log
php bin\unilevel_release.php --dry-run    # the orders/awards it would credit; changes nothing
php bin\unilevel_release.php              # release everything due (one transaction per order)
php bin\unilevel_release.php --order=93   # one order, still under the normal due rule
```

In a browser: `http://localhost/ecowealth/system/cron/unilevel_release.php?doctor=1` (loopback is
trusted; any other caller needs `?key=`). The HTTP form works when there is no terminal.

**If a release is wrong.**

1. **Do not hand-edit `unilevel_awards`, `unilevel_periods` or any wallet row.** The award row *is* the
   escrow ledger; changing a status or a `ledger_id` by SQL breaks the reconcile and the audit trail.
2. `--doctor` first: `cancelled_due` must be `0`; `due_now` should be `0` right after a run; a large
   `undelivered`/`stuck_shipped` figure is a fulfilment problem, not a release bug.
3. `errors>0` in the log (exit 1) means the order's transaction rolled back: the awards are still
   `pending` and the next run retries them. Fix the cause (wallets, database) and let it retry.
4. A credit that looks wrong is a reconcile question: run `php bin\wallet_reconcile.php --json`, then
   read the award's `ledger_id`/`credited_at` against the wallet statement. A missing or duplicated
   movement is a developer incident with a one-off script and its own approval — never a manual
   `UPDATE`.

**Pausing releases.** Set `settings.unilevel_release_enabled = 0`. The job then exits 0, logs a
`WARN` and credits nothing; pending awards stay pending with their due dates unchanged, so they release
on the first run after the switch is back on. When **Enable Cancel of Order** is on, cancelling an
order still cancels its pending awards while releases are paused; with the cancellation switch off
(the default) there is no cancellation to make. Do not “pause” by turning off the master switch —
`unilevel_enabled = 0` stops *new* awards and still lets the pending ones release.

**Rollback and roll-forward.** The release is safe to pause (`unilevel_release_enabled = 0`) and safe
to resume; there is no lost money either way. Do **not** revert the cancellation path: when an order is
cancelled, pending awards must always be cancelled with it, before the `unilevel_reversal_enabled`
check. Do not bypass **Enable Cancel of Order** with a direct SQL status change either; turning the
switch on is the supported way to open the path. If a bad release must be undone, that is a reversal
decision for the developer and finance, not an `UPDATE`; the supported lever is to pause releases and
fix the cause.

**The month clock (weekly, and on the 1st).** The Active rule reads the month that just ended. Confirm
the snapshot exists before you trust a "not Active" complaint:

```sql
-- The months that have a snapshot, newest first (the period card shows the same rows).
SELECT period, members_total, qualified_count, award_total, threshold, closed_at
  FROM unilevel_periods ORDER BY period DESC LIMIT 6;

-- A month with paid activity but no header is the one failure that matters: run the close.
SELECT DISTINCT DATE_FORMAT(paid_at, '%Y-%m') AS period
  FROM shop_orders WHERE paid_at IS NOT NULL
   AND paid_at >= (SELECT value FROM settings WHERE key_name = 'unilevel_ledger_since');
```

```powershell
php bin\unilevel_month_close.php --doctor     # both clocks, the threshold, closed and pending months, lock, log
php bin\unilevel_month_close.php --dry-run    # what the next run would close; writes nothing
```

The job runs at **00:15 on the 1st**; a missed run catches up on every pending month, oldest first. A
closed month is final — there is no reopen, so never hand-edit `unilevel_activity` or
`unilevel_periods`. If the doctor reports a clock drift, fix `DB_TIMEZONE`/the host time before the
close runs: the snapshot decides who is Active, and the engine's live fallback is only as good as the
clock it reads.

> **The month close's `award_total` is the month's EARNED repeat-purchase money: `pending + credited`.**
> A bonus that was still pending at close is included, and a later cancellation does not rewrite the
> closed month (the period is an immutable snapshot). Do not “correct” a closed period when an award is
> cancelled afterwards.

**If it fails.**

1. Read the mismatch kind: `total`, `reversal_total`, `missing_ledger`, `orphan_ledger`, `amount`,
   `reversal_amount`, `order_total`, `pending_on_cancelled`, `pending_ledger` or `unstamped`.
2. Do **not** delete an award or a ledger row, do **not** edit `users.ewallet_balance`, and do **not**
   hand-edit an award's `status`: the ledger is the audit trail. A missing movement is repaired by
   fixing the cause, then asking a developer for a one-off correction script with its own approval.
3. `missing_ledger` / `amount` / `pending_ledger`: a `credited`/`voided` row has no wallet movement, or a
   `pending`/`cancelled` row has one. Re-run the release for that order (`--order=ID`) only when the
   award is still `pending`; otherwise escalate.
4. `pending_on_cancelled`: a cancelled order kept a pending award — the exact bug the cancellation path
   exists to prevent. Pause releases, take a backup, run the reconcile and escalate.
5. `unstamped`: an order was paid through the app but the hook did not run. Do **not** re-run it
   blindly; open the order, confirm the awards are absent, then have a developer call
   `UnilevelService::onOrderPaid()` once and re-run the reconcile.
6. `order_total`: `shop_orders.unilevel_total` disagrees with the `pending + credited` awards. A
   cancellation recomputes it; a manual correction is a developer script, never an
   `UPDATE … SET unilevel_total`.

**Reversing a payout.** Turn on **Settings → Shop → Enable Cancel of Order** for the special case,
then admin-cancel the paid order (`/admin/orders/{id}`). Turn the switch back off afterwards.

- **Before release**, its `pending` awards become `cancelled`: **no wallet debit, no reversal row, no
  shortfall**. The buyer is refunded as usual and the order's `unilevel_total` drops to `0`. This is
  the normal path for a new order.
- **After release** (legacy awards, or a future returns module), the `credited` awards are voided and
  each upline's wallet is debited only as far as its withdrawable balance allows; the rest is recorded
  on the award as a shortfall and shown as *Unrecovered*. `unilevel_reversal_enabled = 0` leaves those
  credited awards alone — a business decision, not a repair path. In the current lifecycle an order
  cannot be cancelled after it ships, so this path is legacy.

A negative balance is impossible. A wallet rule never blocks a cancellation; the only gate is the
operator's **Enable Cancel of Order** switch, which ships off.

**Turning it off.** Set `settings.unilevel_enabled = 0` first; new payments are stamped and pay
nothing immediately. Existing `pending` awards still release unless `unilevel_release_enabled` is also
off (see **Pausing releases**). Existing `credited` money stays in the wallets and in
`unilevel_awards` — it is real money and a code rollback must not delete it. The ledger remains
reconcilable because the command reads the tables, not the code path.

**Who writes the clock.** `unilevel_activity` / `unilevel_periods` are written only by
`UnilevelCycle::close()` (17C) and, from 17F, by its scheduled job — never from a page. A member with
no snapshot row is Active. If the close is late or missed, the engine computes the same aggregate
live, so a payment is never blocked by the job; the snapshot is what makes the answer auditable.

### Double-spend and duplicate guards already in place

- Order placement: unique idempotency key + `SELECT … FOR UPDATE` on stock.
- Commissions: unique `(type, source_user_id, user_id, level)` + `commission_processed_at`.
- Repeat-purchase: unique `(order_item_id, user_id, level)` + `shop_orders.unilevel_processed_at` +
  the wallet's `(user, ref_type, ref_id, type)` replay.
- Transfers: one transaction writes the sender debit, recipient credit and fee credit; a refused
  transfer writes nothing.
- Payouts: the amount is held at request time; one open request per member; status guards refuse a
  second approval/completion and refund exactly once on rejection.
- Registration codes: `UPDATE … WHERE status='unused'` with a row-count check.

---

## 5. Deploying an update

Full instructions are in [`deploy.md`](deploy.md). The short version:

1. Back up the database (§3) and the current files.
2. Copy the files; keep `config.local.php` and `uploads/` from the server.
3. Apply migrations in order — the current build is at
   **023_royalty_bonus.sql** (the Royalty bonus tables and its sixteen settings, stream off; the
   order-cancellation kill switch was `022`, the repeat-purchase escrow `021` and the Phase 17 build
   `020`).
   `php bin` has no migration runner; import with the MySQL client, then compare with
   `database\schema.sql` (`php tests\run.php Migration` does that automatically on a scratch
   schema). Never import `database/install.sql` into an existing database: it is fresh-install
   only and its guard stops the import.
4. Visit `/admin/reports` and `/admin/audit`; both must render.
5. Run `php bin\wallet_reconcile.php`; exit 0.
6. Run the smoke test if the machine has PHP and curl:
   `powershell -NoProfile -File tests\smoke.ps1`.

Phase 15 adds no new hosting requirement: the security headers are sent by PHP, the reports and
audit pages read existing tables, and `019` only adds indexes.

---

## 6. Incident playbooks

### The site answers 503

Expected while `maintenance_mode = 1` on **Admin → Settings → General**. Admins and superadmins
bypass it; the front door and cron are unaffected. Turn it off in the same card. If the card is
unreachable, set `maintenance_mode` to `0` in `settings` and clear the browser cache.

### "Database connection failed"

Check MySQL is running, then `system/app/config.local.php` (host, port, user, password, database),
then that the `ecowealth` user still has privileges on the schema. The page never shows credentials
or a stack trace; the detail is in PHP's `error_log`.

### A payment proof is stuck or a manual order cannot be verified

1. **Admin → Orders → filter `payment_review`.**
2. Open the order: the proof pane shows the attempt, amount and reference.
3. Verify only when the amount and reference match the order and the reference is not reused by
   another order; otherwise reject with a reason — that releases the reserved stock and opens the
   correction window.
4. An admin may not verify their own order (self-review guard). A second admin must do it.

### A member says money left the wallet unexpectedly

1. **Admin → E-wallet monitor → the member**: every debit names its `ref_type` and reference.
2. If it is a shop order, open the order; an e-wallet order is paid at checkout by design.
3. If it is a transfer, open **Wallet → Transfers**; the sender, recipient, fee and note are there.
4. If it is a payout, open **Payouts**; the hold happened when the member asked.
5. Only after the trail is understood, correct forward with an adjustment (see §4).

### A Royalty month is missing, stuck or looks wrong

1. **Is the month due?** Only a **completed** month can close; the current month is a labelled preview.
   The operations panel's **Pending periods** names every completed month with no header.
2. **Did the job run?** Read `system/storage/logs/royalty_month_close-YYYY-MM.log`: one line per run
   (`closed= periods= award= paid= amount= skipped= status= paused=`). `[SKIP ] busy` is an
   overlapping run (harmless); `[ERROR]` names the refusal.
3. **Doctor first:** `php bin\royalty_month_close.php --doctor`. Exit **1** is a hard failure (a
   missing table, a clock skew over 2 s, an invalid stored ladder, a paid month with a pending row, a
   malformed baseline) and the failing line names it. A `WARN` line with exit **0** is a paused
   payment switch, a pending month or an evaluated month older than 48 hours.
4. **Evaluated but unpaid:** `php bin\royalty_month_close.php --pay-only --period=YYYY-MM` pays it
   exactly once; the operations panel prints the exact command for the month it names.
5. **Skipped:** the stream was off when that month closed. A skipped month is final and is never paid
   later; turn `royalty_enabled` on **before** a close (see §2's enable-at-a-month-boundary note).
6. **"The amount is wrong":** open the ledger row and compare the stored GS/PS/quota/rates with the
   month's paid orders (`paid_at` inside the month, cancelled orders excluded). Ranks are derived at
   the period-end cutoff and never stored, so a tree change after the close cannot move an award. A
   closed month is never recalculated: if it was closed with the wrong ladder, that is a finance
   correction outside the app — record it, then change the setting for the next month.
7. **A member who was skipped:** read the stored reason on their statement or on
   **Admin → Members → member → Royalty** — `group sales below quota`, `account is not active`,
   `membership is not active` or `zero amount`.
8. **Never edit `royalty_awards`, `royalty_periods` or a `royalty` ledger row by hand.** The wallet
   ledger is the source of truth, the engine is the only writer, and `php bin\wallet_reconcile.php`
   must stay clean.

### A registration code will not redeem

`unused` codes can expire; an expired code is refused with a message and flipped to `expired`.
Check **Admin → Codes** for the code's status and expiry, and whether it was already used (the used
member is recorded). Codes are case-insensitive and single-use.

### A super-login session is stuck

Sessions expire after 8 hours, on IP/user-agent change, on member suspension and on **Exit**. If a
tab is closed without Exit, the console marks it `expired` on the next load or when the sweep runs.
**Admin → S-Login console** shows the state; the audit trail keeps both ends. A raw token is never
stored, so a lost link cannot be re-offered — open a new session.

### Something looks wrong in the audit trail

The audit log is append-only by design; there is no edit or delete path in the app. If an entry is
missing, ask what wrote outside the app (direct SQL, a restored dump, an old script). Preserve the
database dump before investigating further.

---

## 7. Go-live checklist (plan §9.6)

- [ ] Production database created and migrated to `023`; `schema.sql` matches. A fresh install
      was verified with the pack in [deploy.md §11.6](deploy.md#116-check-the-database):
      **33 / 73 / 2 / 0 / 7 / 2 / 18 / 10 / 0**.
- [ ] Seeds replaced or removed: **only one admin and one superadmin**, no demo members, codes,
      orders or wallets (the `005_demo_tree.sql` data is demo only). The two demo queries in
      [deploy.md §12](deploy.md#12-secure-and-clean-up) both return `0`.
- [ ] Install/reset tools retired (see [Retire the tools](#retire-the-tools-installphp-resetphp)
      below): `public/install.php` and `public/reset.php` deleted and both URLs 404;
      `database/install.sql` / `bin/db_install.php` removed when they were uploaded;
      `INSTALL_KEY` removed or rotated; `DB_RESET_ENABLED` absent or `false`;
      `storage/install.lock` present for a guided install (absent for a static import) and not
      web-readable.
- [ ] `config.local.php` set; `APP_ENV=production`; `APP_DEBUG` off; PHP error display off (the
      bootstrap also forces it off when `APP_DEBUG` is false).
- [ ] HTTPS enabled; HSTS is sent only over TLS.
- [ ] `public/uploads/` writable, its `.htaccess` present; a PHP probe under `uploads/` returns 403.
- [ ] Security headers verified on `/login` and `/admin` (see §8).
- [ ] Cron scheduled and verified (six jobs, §2); one run appears in the log.
- [ ] A backup has been taken **and restored** (§3).
- [ ] Admin and superadmin passwords changed and neither is a documented default
      (`Admin@123`, `Super@123`); payment accounts, transfer fee/limits, payout methods/fees/USDT
      rate and invoice seller block set on **Settings**.
- [ ] The repo-root `index.html` front door links into `/ecowealth/system/`.
- [ ] Finance health is **All clear** and `wallet_reconcile.php` exits 0.
- [ ] UAT signed (`docs/qa/phase-15-launch.md` and `docs/qa/phase-16-install-reset.md`).
- [ ] A post-launch database backup was taken after the checklist.
- [ ] Release tag `v1.0-complete` created.

### Retire the tools (`install.php`, `reset.php`)

Both tools are standalone files, not routes: deleting the file is the whole removal. Do it in a
change window after the last install/reset, then confirm the URLs and the config.

```powershell
# 1. Delete the tools (and the bundle/CLI installer if they were uploaded)
Remove-Item public\install.php, public\reset.php -ErrorAction SilentlyContinue
Remove-Item database\install.sql, bin\db_install.php, bin\db_reset.php -ErrorAction SilentlyContinue

# 2. Both URLs must now return 404 (the branded page, never the tool)
curl.exe -s -o NUL -w "install.php %{http_code}`n" https://example.com/ecowealth/system/install.php
curl.exe -s -o NUL -w "reset.php   %{http_code}`n" https://example.com/ecowealth/system/reset.php
```

```bash
rm -f public/install.php public/reset.php database/install.sql bin/db_install.php bin/db_reset.php
curl -s -o /dev/null -w 'install.php %{http_code}\n' https://example.com/ecowealth/system/install.php
curl -s -o /dev/null -w 'reset.php   %{http_code}\n' https://example.com/ecowealth/system/reset.php
```

Then, in `config.local.php`: remove `INSTALL_KEY` (or rotate it) and make sure
`DB_RESET_ENABLED` is absent or `false`. Keep `storage/install.lock` when a guided installer
created it — it is the proof of the install, it sits outside `public/`, and `storage/.htaccess`
denies it. The services (`DatabaseInstaller`, `DatabaseResetService`, `DemoDataService`) and
`public/assets/css/db-tools.css` may stay: they are unreachable without the pages. The full
matrix by environment is in `dmp/plan-ecowealth-install-reset.md` §11.4.

---

## 8. Health and security checks

| Check | Command / page |
|---|---|
| Finance invariants | **Admin → Reports** (Health card) or `php bin\wallet_reconcile.php --json` |
| Response headers | `curl -sI http://localhost/ecowealth/system/login` — `X-Content-Type-Options`, `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, `Referrer-Policy`, `Permissions-Policy`, `X-Robots-Tag` |
| Upload hardening | `curl -o NUL -w "%{http_code}" http://localhost/ecowealth/system/uploads/proofs/x.php` → **403** |
| Error pages | `/no-such-page` → 404; a member opening `/admin/reports` → 403; both branded |
| Tool retirement | `/system/install.php` and `/system/reset.php` → **404** on production (deleted, or a 404 from `DB_RESET_ENABLED`) |
| Install lock | `storage/install.lock` exists for a guided install and is not web-readable |
| Money-form throttles | checkout 20 / 10 min · proof 10 / 15 min · transfer 10 / 15 min · payout 10 / 1 h (session-scoped) |
| Rate-limited login | 5 failed attempts / 15 min per session |
| Audit | **Admin → Audit log**, filter by action/subject/actor/date |
| S-Login audit | **Admin → S-Login console**, **Admin → Audit log** (`slogin.*`) |

Cron access, lock and log conventions are shared; see [`cron.md`](cron.md).

---

## 9. Release process

1. Bundle: `php bin\build_install_sql.php --check` — exit 0 (the committed `install.sql` matches
   `schema.sql` + seeds 001–004; rebuild with `php bin\build_install_sql.php` after editing a source).
2. Full suite: `php tests\run.php` — must be green (1,162 cases at the Phase 19 release).
3. Smoke: `powershell -NoProfile -File tests\smoke.ps1` — must be green (248 checks).
4. Reconciliation: `php bin\wallet_reconcile.php` — exit 0.
5. Phase gate checklist in `docs\phase-log.md` signed; QA doc updated.
6. Commit with the phase summary; tag `phase-15` for the build and `v1.0-complete` at launch.
7. Take a post-release backup and keep the previous release's files for one day.

---

## 10. Who to call

| Area | Owner | Notes |
|---|---|---|
| Hosting, DB, cron | _hosting admin_ | Laragon/Hostinger panel, MySQL credentials |
| Money, payouts, reconciliation | _finance admin_ | approve payouts only against a verified request |
| Code, migrations, incidents | _developer on call_ | keep the last dump and the phase log open |

Fill the names in before go-live; the checklist above is only as good as the people it names.
