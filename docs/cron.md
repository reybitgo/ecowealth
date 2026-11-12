# Scheduled jobs

Four scheduled jobs keep the platform moving without an administrator watching it:

| Job | What it does | Command | Guide |
|---|---|---|---|
| **Shop expiry** | cancels unpaid orders when their payment/correction window closes and releases the stock | `bin/shop_expire.php` | this page, §1 |
| **Order auto-complete** | completes delivered orders after *n* days when the member never confirms receipt | `bin/order_autocomplete.php` | this page, §2 |
| **Transfer-limit reset** | zeroes the daily transfer counters every midnight (and the weekly ones on Monday) | `bin/reset_transfer_limits.php` | this page, §3 |
| **Unilevel month close** | writes the monthly Active snapshot for the repeat-purchase bonus | `bin/unilevel_month_close.php` | this page, §4 |

All are idempotent, safe to run twice, log one line per run and share the same access rules, lock and
log conventions. The first two have a kill switch on **Admin → Settings**; the reset job has nothing
to switch off — it only zeroes counters a transfer would have incremented. The month close has none
either, on purpose: it pays no money, so there is nothing to kill.

---

# 1. Shop expiry

The job that cancels unpaid shop orders once their payment (or correction) window closes, releases
their reserved stock and records a `payment_expired` event. One implementation, three entry points.

| Entry | Path | Use |
|---|---|---|
| **CLI** | `system/bin/shop_expire.php` | Hostinger cron, SSH, CI |
| **Web** | `/system/cron/shop_expire.php` → `system/public/cron/shop_expire.php` | Local testing in a browser, or a host whose cron only calls a URL |
| **Windows** | `system/bin/shop_expire.cmd` | Task Scheduler, double-click, Laragon terminal |

The logic lives in `PaymentService::expireOverdue()`; both entry points call it, so they can never
disagree.

---

## What it does

Only two statuses can expire:

| Status | Deadline used |
|---|---|
| `pending` | `shop_orders.payment_deadline` — written at checkout from **Payment window (hours)** |
| `payment_failed` | `shop_orders.correction_deadline` — written at rejection from **Correction window (hours)** |

- `payment_review` is **exempt**: a proof that was submitted is an admin decision, never a timer
  decision.
- `paid`, `packing`, `ready_to_ship`, `shipped`, `delivered`, `completed` and `cancelled` are never
  touched.
- Each cancellation runs in one transaction: it locks the order, re-checks the status and deadline,
  sets `cancelled` with reason **Payment window expired**, releases the reservation and writes a
  `payment_expired` event with actor `system`.
- Running it twice in a row is harmless: the second run finds nothing to do.
- It runs even when the shop is closed or the site is in maintenance mode.

The three settings that drive it live on **Admin → Settings → Payment windows**:

| Setting | Default | Role |
|---|---|---|
| `shop_payment_deadline_hours` | 24 | how long a member has to pay a manual order |
| `shop_correction_window_hours` | 24 | how long a rejected payment may be corrected |
| `shop_expiry_enabled` | 1 | **kill switch** — when `0`, every run is skipped |

`shop_max_proof_attempts` is *not* used by the cron: it caps uploads. After the last attempt, the
correction deadline still governs expiry.

> **Windows are snapshots.** Each order keeps the deadline it was given when placed/rejected, so
> lowering a setting never retroactively cancels older orders. Use `--from-settings` (below) to see
> or apply the *current* values instead.

---

## Running it locally

**In a browser (no setup):**

```
http://localhost/ecowealth/system/cron/shop_expire.php
```

A loopback request is trusted, so no key is needed on the development machine.

| URL | What it shows |
|---|---|
| `?doctor=1` | PHP/DB time and time zone, settings, candidate count, lock and log state |
| `?dry-run=1` | what would be cancelled, changes nothing |
| `?from-settings=1&dry-run=1` | the same but with deadlines recomputed from the current settings |
| `?json=1` | the machine-readable payload |
| `?limit=50` | smaller batch |

**In a terminal:**

```powershell
cd C:\laragon\www\ecowealth\system

php bin\shop_expire.php --doctor
php bin\shop_expire.php --dry-run
php bin\shop_expire.php
php bin\shop_expire.php --from-settings --dry-run --json

# or through the Windows launcher (no PATH setup needed if PHP_EXE is set)
bin\shop_expire.cmd --dry-run
```

If `php` is not on `PATH` for Task Scheduler, set it once:

```powershell
setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
```

---

## Running it on a live server (Hostinger)

### Option A — CLI cron (recommended)

```cron
15,45 * * * * /usr/bin/php /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/bin/shop_expire.php --limit=200 >> /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/storage/logs/cron.out 2>&1
```

- Every 15 minutes; the shortest window it acts on is hours.
- Use the PHP binary the account provides (`/usr/bin/php` on Hostinger is usually fine; their
  hPanel cron UI may show `php` or an Alt-PHP path).
- The job writes its own monthly log, so the redirect only catches PHP fatals.

### Option B — URL cron

1. Add the shared key to `system/app/config.local.php` (git-ignored, already holds the DB credentials):

   ```php
   define('CRON_KEY', 'a-long-random-string');
   ```

2. Create the cron job in hPanel with a URL:

   ```cron
   15,45 * * * * curl -s "https://example.com/ecowealth/system/cron/shop_expire.php?key=a-long-random-string" > /dev/null
   ```

Access rules for the web entry:

| Caller | Result |
|---|---|
| CLI | always allowed |
| `127.0.0.1` / `::1` | always allowed (local testing, server-to-self) |
| any other address, key matches `CRON_KEY` | allowed |
| any other address, no key or wrong key | `403 Forbidden` |

The response is plain text (or JSON with `--json`), never cached and marked `noindex`. The key is
never stored in the database and never rendered on a page. If you use the URL form, remember the key
appears in the web server access log; prefer Option A when SSH is available.

---

## Logs, locks and diagnostics

| What | Where |
|---|---|
| Monthly log | `system/storage/logs/shop_expire-YYYY-MM.log` |
| Lock | `system/storage/locks/shop_expire.lock` (advisory; released automatically) |
| Extra output (cron redirect) | wherever your crontab sends it |

One line per run:

```
[2026-11-06 11:20:00 +08:00] [OK   ] mode=stamps trigger=http limit=200 candidates=1 expired=1 duration=45ms ids=81
```

`mode=stamps` is the normal run; `mode=settings` is `--from-settings`.

- **Overlap:** a second run while one is in progress exits 0 with “another run is already in
  progress”. That is normal, not an error.
- **Disabled:** when `shop_expiry_enabled=0` the run exits 0 and logs a `WARN`.
- **Logs are never web-readable** — `system/storage/` is outside `public/` and also carries a
  deny-all `.htaccess`.
- **Retention:** one file per month, no automatic deletion (text logs are tiny). Remove old files
  whenever you like; the next run recreates the current month's file.

`--doctor` / `?doctor=1` is the first thing to run on a new server. It prints PHP and MySQL time
(they must agree), the session time zone, the three windows, the kill switch, the candidate count,
the lock state, whether the log folder is writable and whether `CRON_KEY` is set.

---

## Exit codes (all jobs)

| Code | Meaning |
|---|---|
| `0` | run completed, skipped (locked), disabled, or a no-op (already closed) |
| `1` | the job could not run (database, configuration, unwritable storage) **or** it refused the request (a future/incomplete period, an unreadable `--period`) |

## Troubleshooting (all jobs)

| Symptom | Likely cause | Fix |
|---|---|---|
| Browser shows `403 Forbidden` | not loopback and no/wrong key | add `?key=…` matching `CRON_KEY` |
| “another run is already in progress” on every run | a crashed run left the process, or two crons overlap | check `--doctor`; reduce overlap by running every 15 min |
| `--doctor` says `log … (NOT writable)` | the account cannot write `system/storage/logs` | create the folder, `chmod 775`, or run as the account owner |
| Orders are not expiring | kill switch off, no overdue orders, or deadlines in the future | `--doctor`, then `--dry-run` |
| Delivered orders are not completing | auto-complete switch off, days not reached, or no delivered orders | `--doctor`, then `--dry-run` |
| No period was closed on the 1st | nothing was pending, the ledger baseline is later than last month, or the month was already closed | `--doctor`, then `--dry-run` |
| `db now` differs from `timezone` | MySQL session time zone drifted | check `DB_TIMEZONE` in `app/config.php` (default `+08:00`) |
| 500 on the URL entry | a PHP fatal — the response hides details by design | run the CLI version, or check the Apache error log |

---

## QA

QA7-13 in [`docs/qa/phase-7-payments.md`](qa/phase-7-payments.md) walks through: forcing an order
overdue with SQL, `--doctor`, `--dry-run`, the real URL run, a second idempotent run, the log line,
the event trail, the released stock, and the kill switch.

---

# 2. Order auto-complete

Delivered orders complete themselves after **`shop_autocomplete_days`** (default 7) when the member
never presses **Confirm receipt**. The order gets `completed_at` and an `auto_completed` event with
actor `system`; the member can still confirm earlier, and a manual confirmation always wins.

| Entry | Path | Use |
|---|---|---|
| **CLI** | `system/bin/order_autocomplete.php` | Hostinger cron, SSH, CI |
| **Web** | `/system/cron/order_autocomplete.php` → `system/public/cron/order_autocomplete.php` | Local testing in a browser, or a URL-only host |
| **Windows** | `system/bin/order_autocomplete.cmd` | Task Scheduler, double-click, Laragon terminal |

The logic lives in `FulfilmentService::autoComplete()`; both entries call it, so they cannot
disagree.

### What it does

- Candidates are orders in `delivered` whose `delivered_at` is at least `shop_autocomplete_days` old.
- Each completion runs in one transaction: it locks the order, re-checks `delivered` and the
  delivered_at stamp, then sets `completed`, stamps `completed_at` and writes the event. A member
  confirming at the same moment has exactly one winner.
- Running it twice in a row is harmless.
- `shipped` and younger `delivered` orders are never touched.

| Setting | Default | Role |
|---|---|---|
| `shop_autocomplete_days` | 7 | days after delivery before the order completes itself (1–365) |
| `shop_autocomplete_enabled` | 1 | **kill switch** — when `0`, every run is skipped |

### Running it locally

**In a browser:**

```
http://localhost/ecowealth/system/cron/order_autocomplete.php
```

| URL | What it shows |
|---|---|
| `?doctor=1` | PHP/DB time, days, kill switch, candidates, lock and log state |
| `?dry-run=1` | the orders that would be completed, changes nothing |
| `?json=1` | the machine-readable payload |
| `?limit=50` | smaller batch |

**In a terminal:**

```powershell
cd C:\laragon\www\ecowealth\system

php bin\order_autocomplete.php --doctor
php bin\order_autocomplete.php --dry-run
php bin\order_autocomplete.php
bin\order_autocomplete.cmd --dry-run
```

### Running it on a live server

Hourly is plenty; the job only acts on orders that are days old.

```cron
5 * * * * /usr/bin/php /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/bin/order_autocomplete.php --limit=200 >> /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/storage/logs/cron.out 2>&1
```

URL form (same access rules and `CRON_KEY` as the expiry job):

```cron
5 * * * * curl -s "https://example.com/ecowealth/system/cron/order_autocomplete.php?key=a-long-random-string" > /dev/null
```

### Logs, locks and evidence

| What | Where |
|---|---|
| Monthly log | `system/storage/logs/order_autocomplete-YYYY-MM.log` |
| Lock | `system/storage/locks/order_autocomplete.lock` (advisory; released automatically) |

One line per run:

```
[2026-11-06 13:05:00 +08:00] [OK   ] trigger=http limit=200 days=7 candidates=1 completed=1 duration=12ms ids=93
```

The member's order timeline shows **Completed automatically 7 days after delivery** (actor
`system`), and the admin order page shows the same event. `--doctor` is the first thing to run on a
new server: it reports PHP/MySQL time, the day count, the kill switch, the candidate count, the lock
and whether the log folder is writable.

> **A note on backfilling:** invoices for paid orders from before Phase 8 are issued the first time
> their invoice page is opened (`InvoiceService::ensureForOrder()`). No cron is needed for that.

### QA

QA8-11 in [`docs/qa/phase-8-fulfilment.md`](qa/phase-8-fulfilment.md) walks through the auto-complete
job from the URL entry: doctor, dry run, the real run, the second idempotent run, the log line, the
event trail and the kill switch.


---

# 3. Transfer-limit reset

Members may send e-wallet money to each other up to a **daily** and a **weekly** limit
(`ewallet_transfer_daily_limit` = 5000, `ewallet_transfer_weekly_limit` = 20000 by default). The
service tracks usage in `users.ewallet_sent_today` and `users.ewallet_sent_this_week` **inside the
same row lock that guards the balance**, so two concurrent transfers can never both fit a limit they
only fit once. This job zeroes those counters on a schedule.

| Entry | Path | Use |
|---|---|---|
| **CLI** | `system/bin/reset_transfer_limits.php` | cron, SSH, CI |
| **Web** | `/system/cron/reset_transfer_limits.php` → `system/public/cron/reset_transfer_limits.php` | Local testing, or a URL-only host |
| **Windows** | `system/bin/reset_transfer_limits.cmd` | Task Scheduler, double-click, Laragon terminal |

The logic lives in `TransferService::resetLimits()`; both entries call it, so they cannot disagree.

## What it does

- **Daily counters** (`ewallet_sent_today`) are zeroed on **every** run.
- **Weekly counters** (`ewallet_sent_this_week`) are zeroed **only when the run's weekday is Monday**
  (app time zone, `Asia/Manila`).
- Only accounts with a non-zero counter are updated; the run reports how many it changed.
- No `audit_log` row is written — a daily job would grow that table forever. The monthly cron log is
  the record of each run.
- Running it twice in a row is harmless: the second run changes nothing and logs `0` counters.

| Flag | Effect |
|---|---|
| `--week` | force the weekly reset whatever the weekday (repairing a missed Monday) |
| `--daily-only` | never touch the weekly counter, even on Monday |
| `--dry-run` | print what would be zeroed, change nothing |
| `--doctor` | PHP/DB time, the weekday, the counters, the lock and log state |
| `--json` | machine-readable payload |

## Running it locally

**In a browser:**

```
http://localhost/ecowealth/system/cron/reset_transfer_limits.php
```

| URL | What it shows |
|---|---|
| `?doctor=1` | the weekday, both counter totals, the lock and log state |
| `?dry-run=1` | the counters that would be zeroed, changes nothing |
| `?json=1` | the machine-readable payload |
| `?week=1` | forces the weekly reset |

**In a terminal:**

```powershell
cd C:\laragon\www\ecowealth\system

php bin\reset_transfer_limits.php --doctor
php bin\reset_transfer_limits.php --dry-run
php bin\reset_transfer_limits.php
bin\reset_transfer_limits.cmd --dry-run
```

## Running it on a live server

Once a day, just after midnight:

```cron
5 0 * * * /usr/bin/php /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/bin/reset_transfer_limits.php >> /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/storage/logs/cron.out 2>&1
```

URL form (same access rules and `CRON_KEY` as the other jobs):

```cron
5 0 * * * curl -s "https://example.com/ecowealth/system/cron/reset_transfer_limits.php?key=a-long-random-string" > /dev/null
```

> **If the job misses a Monday**, the weekly counter keeps yesterday's usage; run it once with
> `--week` (or wait for the next Monday). Limits are always enforced from the counters, never from
> the clock, so a missed run can only ever make a limit *stricter*, never wider.

## Logs, locks and evidence

| What | Where |
|---|---|
| Monthly log | `system/storage/logs/reset_transfer_limits-YYYY-MM.log` |
| Lock | `system/storage/locks/reset_transfer_limits.lock` (advisory; released automatically) |

One line per run:

```
[2026-11-09 00:05:01 +08:00] [OK   ] trigger=http day=Mon weekly=yes daily_zeroed=3 weekly_zeroed=3 accounts=26 duration=9ms
```

`--doctor` is the first thing to run on a new server: it reports PHP/MySQL time, the weekday, how
many accounts carry usage, the lock state and whether the log folder is writable.

## QA

QA12-06 in [`docs/qa/phase-12-transfers.md`](qa/phase-12-transfers.md) walks through the reset job
from the URL entry: doctor, dry run, the real run, the second idempotent run and the log line.

---

# 4. Unilevel month close

The job that writes the monthly **Active** snapshot for the repeat-purchase bonus (Phase 17, plan
§12.7 and §14.8). It closes every completed month that is still open — oldest first, from the month
of `unilevel_ledger_since` through last month — and pays no money.

| Entry | Path | Use |
|---|---|---|
| **CLI** | `system/bin/unilevel_month_close.php` | Hostinger cron, SSH, CI |
| **Web** | `/system/cron/unilevel_month_close.php` → `system/public/cron/unilevel_month_close.php` | Local testing in a browser, or a URL-only host |
| **Windows** | `system/bin/unilevel_month_close.cmd` | Task Scheduler, double-click, Laragon terminal |

The logic lives in `UnilevelCycle::close()`; every entry calls it, so they cannot disagree.

## What it does

- **The schedule is 00:15 on the first of the month, `Asia/Manila`.** Fifteen minutes is enough for
  the last payments of the month to commit; the live fallback covers a late or missed run.
- A member is **Active** for month M when their personal purchases in M−1 reached
  `unilevel_active_threshold` (₱500 by default). The close freezes that answer for the month.
- **One transaction per period**: the `unilevel_activity` rows first (one per account, including
  members with zero purchases), then the `unilevel_periods` header. A crash rolls the period back
  and the next run redoes it.
- **A closed period is immutable.** There is no reopen: `--period` on a closed month is a no-op with
  a clear message.
- **`paid_at` decides the month**, not the order date. An order verified on 2 December belongs to
  December — for the award and for the buyer's qualification alike.
- **Catching up is normal.** If the job misses a month, the next run closes every open month in
  order. A fresh install closes nothing: the baseline is its install month, so there are no years of
  empty months to snapshot.
- **Idempotent.** A second run finds nothing to close, exits 0 and logs `closed=0`.
- **It runs even when `unilevel_enabled = 0`.** The close pays no money, so it has no kill switch;
  it exists so the snapshots are ready the moment the stream is switched on.
- **The engine does not need it.** Without any close, `UnilevelCycle::isActive()` computes the same
  answer live from the orders (plan §12.7). The close makes it explicit, auditable and visible on the
  admin period card.

| Setting | Default | Role |
|---|---|---|
| `unilevel_active_threshold` | 500 | monthly personal purchases (₱) that make a member Active |
| `unilevel_ledger_since` | install time | the baseline: every month from here through last month is closed |

> **The threshold is snapshotted, not recomputed.** Every closed header stores the threshold that was
> in force, so a later change to the setting never rewrites history (`SELECT * FROM unilevel_periods`).

## Running it locally

**In a browser (no setup):**

```
http://localhost/ecowealth/system/cron/unilevel_month_close.php
```

A loopback request is trusted, so no key is needed on the development machine.

| URL | What it shows |
|---|---|
| `?doctor=1` | PHP/MySQL clocks, time zone, threshold, closed and pending months, the account count, the lock and the log folder |
| `?dry-run=1` | the months it would close and their projected qualified counts, changes nothing |
| `?period=2026-10&dry-run=1` | the same for one month |
| `?json=1` | the machine-readable payload |

**In a terminal:**

```powershell
cd C:\laragon\www\ecowealth\system

php bin\unilevel_month_close.php --doctor
php bin\unilevel_month_close.php --dry-run
php bin\unilevel_month_close.php
php bin\unilevel_month_close.php --period=2026-10 --json

# or through the Windows launcher (no PATH setup needed if PHP_EXE is set)
bin\unilevel_month_close.cmd --dry-run
```

| Flag | Effect |
|---|---|
| `--period=YYYY-MM` | close exactly that completed month |
| `--dry-run` | list the months it would close and the projected counts; change nothing |
| `--doctor` | environment / settings / health report |
| `--json` | machine-readable payload |
| `--log-dir=<dir>` | where the monthly log is written (CLI only) |
| `--quiet` | no stdout (the log still records the run; CLI only) |

## Running it on a live server

Once a month, at 00:15 on the first:

```cron
15 0 1 * * /usr/bin/php /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/bin/unilevel_month_close.php >> /home/uXXXXXX/domains/example.com/public_html/ecowealth/system/storage/logs/cron.out 2>&1
```

URL form (same access rules and `CRON_KEY` as the other jobs):

```cron
15 0 1 * * curl -s "https://example.com/ecowealth/system/cron/unilevel_month_close.php?key=a-long-random-string" > /dev/null
```

> **Run `--dry-run` first on a database that has history.** It lists exactly which months the real run
> would close and how many members each snapshot will qualify, and it writes nothing.

## Logs, locks and failure modes

| What | Where |
|---|---|
| Monthly log | `system/storage/logs/unilevel_month_close-YYYY-MM.log` |
| Lock | `system/storage/locks/unilevel_month_close.lock` (advisory; released automatically) |

One line per run:

```
[2026-12-01 00:15:01 +08:00] [OK   ] trigger=cli closed=1 period=2026-11 members=26 qualified=3 award_total=90.00 duration=42ms
```

A catch-up run names every month it closed, in order, and sums the counters it wrote:

```
[2026-12-01 00:15:02 +08:00] [OK   ] trigger=cli closed=2 period=2026-10,2026-11 members=52 qualified=6 award_total=180.00 duration=51ms
```

`members` and `qualified` count the **snapshot rows the run wrote**; `award_total` is the credited
repeat-purchase money of those months, stored on the header for the admin period card.

| Symptom | Cause | What to do |
|---|---|---|
| `skipped: another run is already in progress` (`[SKIP ]`) | a second run overlapped | normal; the line is the record. Investigate only if it repeats |
| `refused: … has not finished yet` (exit 1) | `--period` names the current or a future month | only a completed month can be closed; run without `--period` |
| `already closed` (exit 0) | the month already has a header | nothing to do — there is no reopen |
| `closed=0` | nothing was pending | normal, e.g. a second run on the 1st |
| `members` is lower than the account count | accounts created after the close | expected: a snapshot covers the accounts that exist at close time |
| exit 1 with `FAILED` | database or storage problem | run `--doctor`; the log has the error |

**Failure modes**

- **A missed month** is harmless: the next run catches up, and the live fallback kept paying
  correctly in the meantime.
- **A crashed run** rolls its period back; the next run redoes it.
- **Two runs at once** — the lock makes the second one skip, and the `unilevel_activity` primary key
  would roll the loser back anyway. One winner, no rewrite.
- **A late run** is fine. The 00:15 offset only matters for a payment that commits in the last
  minutes of a month; the live resolution covers that window.
- **Timezone drift** between PHP and MySQL is the one silent risk: `--doctor` compares both clocks and
  prints the session zone, which must read `+08:00`.

## QA

QA17F-01 … QA17F-07 in [`docs/qa/phase-17-unilevel-repeat-purchase.md`](qa/phase-17-unilevel-repeat-purchase.md)
walk through: doctor, dry run, the real run against `unilevel_periods`/`unilevel_activity`, the
second idempotent run, `--period` on a closed and a future month, the web entry, and the `.cmd`
launcher on Windows.

---

# 5. Registering the Windows tasks (Phase 15)

The four jobs run from Laragon on Windows. Register them once in Task Scheduler with these
commands (run an **elevated** PowerShell; adapt the app path if it is not `C:\laragon\www\ecowealth`):

```powershell
$php = 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'
$app = 'C:\laragon\www\ecowealth\system'

# Shop expiry - every 15 minutes.
schtasks /Create /F /SC MINUTE /MO 15 /TN "EcoWealth - shop expiry" ^
  /TR "\"$php\" \"$app\bin\shop_expire.php\" --limit=200"

# Delivered-order auto-complete - every hour.
schtasks /Create /F /SC HOURLY /MO 1 /TN "EcoWealth - order auto-complete" ^
  /TR "\"$php\" \"$app\bin\order_autocomplete.php\""

# Transfer-limit reset - every midnight.
schtasks /Create /F /SC DAILY /ST 00:00 /TN "EcoWealth - transfer limit reset" ^
  /TR "\"$php\" \"$app\bin\reset_transfer_limits.php\""

# Unilevel month close - 00:15 on the first of every month.
schtasks /Create /F /SC MONTHLY /D 1 /ST 00:15 /TN "EcoWealth - unilevel month close" ^
  /TR "\"$php\" \"$app\bin\unilevel_month_close.php\""
```

The four launchers (`bin\shop_expire.cmd`, `bin\order_autocomplete.cmd`,
`bin\reset_transfer_limits.cmd`, `bin\unilevel_month_close.cmd`) exist for a machine where PHP is not
on `PATH`; point the task at the `.cmd` file instead if you prefer:

```powershell
schtasks /Create /F /SC MINUTE /MO 15 /TN "EcoWealth - shop expiry (cmd)" ^
  /TR "C:\laragon\www\ecowealth\system\bin\shop_expire.cmd --limit=200"

schtasks /Create /F /SC MONTHLY /D 1 /ST 00:15 /TN "EcoWealth - unilevel month close (cmd)" ^
  /TR "C:\laragon\www\ecowealth\system\bin\unilevel_month_close.cmd"
```

`/SC MONTHLY /D 1 /ST 00:15` fires at 00:15 on the first of each month, which is the schedule the
month close is designed for. Its `--doctor` run is the quickest way to prove the task account can
reach the database and write `system\storage`.

## Verify a task

```powershell
schtasks /Query /TN "EcoWealth - shop expiry" /V /FO LIST
schtasks /Run   /TN "EcoWealth - shop expiry"      # run it once now
schtasks /Delete /TN "EcoWealth - shop expiry" /F  # remove it
```

Every run appends one line to `system\storage\logs\<job>-YYYY-MM.log` and takes an advisory lock.
A second copy started while the first is running logs `[SKIP ]` and exits 0, so an overlapping
schedule is harmless.

## Task Scheduler checklist

- [ ] The account running the task can write `system\storage\logs` and `system\storage\locks`.
- [ ] `php -v` works for that account (Laragon's PHP path is absolute, not a `PATH` entry).
- [ ] The expiry job answered `--doctor` correctly before the first real run.
- [ ] The first scheduled run appears in the monthly log within the expected window.
- [ ] `php bin\wallet_reconcile.php` stays clean after a day of scheduled runs.
