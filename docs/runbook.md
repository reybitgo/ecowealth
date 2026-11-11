# EcoWealth runbook

**Audience:** whoever operates the EcoWealth installation — the system administrator, the finance
admin and the developer on call. It is the day-to-day companion to
[`deploy.md`](deploy.md) (first install and upgrades), [`cron.md`](cron.md) (job detail) and
[`live-updates.md`](live-updates.md) (the no-refresh protocol).

**Scope:** the Phase 15 build — shop, orders, payments, fulfilment, registration and codes, the
dual-balance e-wallet, commissions, transfers, payouts, super-login and the reports/audit desk.

---

## 1. The five-minute daily check

| # | Check | Where | Healthy looks like |
|---|---|---|---|
| 1 | Finance health | **Admin → Reports → Finance health** | Green **All clear**, 7 checks, one line per invariant |
| 2 | Reconciliation | `php bin\wallet_reconcile.php` | exit **0**, "All good — wallets, commissions, transfers and payouts reconcile" |
| 3 | Orders that need action | **Admin → Dashboard → Orders needing action** | the number you expect for the day; nothing stuck in `payment_review` |
| 4 | Payout hold | **Admin → Dashboard → Held for payout** / **Payouts** | every open request has a reason to wait; none older than a day |
| 5 | Audit desk | **Admin → Audit log** | today's settings changes, exports and S-Login exits are the ones you made |
| 6 | Cron log | `system\storage\logs\*.log` | one line per run for each job; no `[ERROR]` |

If check 1 or 2 fails, stop and read §5 before touching money. Nothing in this app repairs a
balance automatically; a mismatch means a write happened outside its service.

---

## 2. Scheduled jobs

Three jobs run unattended; the exact commands, Windows Task Scheduler registration, kill switches
and log formats are in [`cron.md`](cron.md) (see §4 for the Task Scheduler block).

| Job | Schedule | Entry |
|---|---|---|
| Shop expiry (unpaid orders) | every 15 minutes | `bin\shop_expire.php --limit=200` |
| Delivered-order auto-complete | hourly | `bin\order_autocomplete.php` |
| Transfer-limit reset | daily at 00:00 | `bin\reset_transfer_limits.php` |

All three are idempotent, take an advisory lock, write one monthly log line and have a `--doctor`
mode. A second overlapping run logs `[SKIP ]` and exits 0.

**If a job misses a day:** the expiry and auto-complete jobs catch up on their next run (they act on
stored deadlines); the weekly transfer counter keeps yesterday's usage until the next Monday, and a
limit can only get *stricter* in the meantime, never wider. Run
`php bin\reset_transfer_limits.php --week` once to clear it by hand.

---

## 3. Backups

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

Checks 1–3 and 7 are also the four service reconciliations behind
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

### Double-spend and duplicate guards already in place

- Order placement: unique idempotency key + `SELECT … FOR UPDATE` on stock.
- Commissions: unique `(type, source_user_id, user_id, level)` + `commission_processed_at`.
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
3. Apply migrations in order — the Phase 15 build is at **019_audit_and_reporting.sql**.
   `php bin` has no migration runner; import with the MySQL client, then compare with
   `database\schema.sql` (`php tests\run.php Migration` does that automatically on a scratch
   schema).
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

- [ ] Production database created and migrated to `019`; `schema.sql` matches.
- [ ] Seeds replaced or removed: **only one admin and one superadmin**, no demo members, codes,
      orders or wallets (the `005_demo_tree.sql` data is demo only).
- [ ] `config.local.php` set; `APP_ENV=production`; `APP_DEBUG` off; PHP error display off (the
      bootstrap also forces it off when `APP_DEBUG` is false).
- [ ] HTTPS enabled; HSTS is sent only over TLS.
- [ ] `public/uploads/` writable, its `.htaccess` present; a PHP probe under `uploads/` returns 403.
- [ ] Security headers verified on `/login` and `/admin` (see §8).
- [ ] Cron scheduled and verified (three jobs, §2); one run appears in the log.
- [ ] A backup has been taken **and restored** (§3).
- [ ] Admin and superadmin passwords changed; payment accounts, transfer fee/limits, payout
      methods/fees/USDT rate and invoice seller block set on **Settings**.
- [ ] The repo-root `index.html` front door links into `/ecowealth/system/`.
- [ ] Finance health is **All clear** and `wallet_reconcile.php` exits 0.
- [ ] UAT signed (`docs/qa/phase-15-launch.md`).
- [ ] Release tag `v1.0-complete` created.

---

## 8. Health and security checks

| Check | Command / page |
|---|---|
| Finance invariants | **Admin → Reports** (Health card) or `php bin\wallet_reconcile.php --json` |
| Response headers | `curl -sI http://localhost/ecowealth/system/login` — `X-Content-Type-Options`, `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, `Referrer-Policy`, `Permissions-Policy`, `X-Robots-Tag` |
| Upload hardening | `curl -o NUL -w "%{http_code}" http://localhost/ecowealth/system/uploads/proofs/x.php` → **403** |
| Error pages | `/no-such-page` → 404; a member opening `/admin/reports` → 403; both branded |
| Money-form throttles | checkout 20 / 10 min · proof 10 / 15 min · transfer 10 / 15 min · payout 10 / 1 h (session-scoped) |
| Rate-limited login | 5 failed attempts / 15 min per session |
| Audit | **Admin → Audit log**, filter by action/subject/actor/date |
| S-Login audit | **Admin → S-Login console**, **Admin → Audit log** (`slogin.*`) |

Cron access, lock and log conventions are shared; see [`cron.md`](cron.md).

---

## 9. Release process

1. Full suite: `php tests\run.php` — must be green.
2. Smoke: `powershell -NoProfile -File tests\smoke.ps1` — must be green.
3. Reconciliation: `php bin\wallet_reconcile.php` — exit 0.
4. Phase gate checklist in `docs\phase-log.md` signed; QA doc updated.
5. Commit with the phase summary; tag `phase-15` for the build and `v1.0-complete` at launch.
6. Take a post-release backup and keep the previous release's files for one day.

---

## 10. Who to call

| Area | Owner | Notes |
|---|---|---|
| Hosting, DB, cron | _hosting admin_ | Laragon/Hostinger panel, MySQL credentials |
| Money, payouts, reconciliation | _finance admin_ | approve payouts only against a verified request |
| Code, migrations, incidents | _developer on call_ | keep the last dump and the phase log open |

Fill the names in before go-live; the checklist above is only as good as the people it names.
