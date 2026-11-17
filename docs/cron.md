# Scheduled jobs

Six scheduled jobs keep the platform moving without an administrator watching it:

| Job | What it does | Command | Guide |
|---|---|---|---|
| **Shop expiry** | cancels unpaid orders when their payment/correction window closes and releases the stock | `bin/shop_expire.php` | this page, §1 |
| **Order auto-complete** | completes delivered orders after *n* days when the member never confirms receipt | `bin/order_autocomplete.php` | this page, §2 |
| **Transfer-limit reset** | zeroes the daily transfer counters every midnight (and the weekly ones on Monday) | `bin/reset_transfer_limits.php` | this page, §3 |
| **Unilevel month close** | writes the monthly Active snapshot for the repeat-purchase bonus | `bin/unilevel_month_close.php` | this page, §4 |
| **Unilevel release** | credits due `pending` repeat-purchase awards to the uplines' withdrawable wallets after delivery + the hold window | `bin/unilevel_release.php` | this page, §6 |
| **Royalty month close** | evaluates every completed month and pays the rank-gated Royalty bonus to the withdrawable wallets | `bin/royalty_month_close.php` | this page, §7 |

All are idempotent, safe to run twice, log one line per run and share the same access rules, lock and
log conventions. The first two, the release job and the Royalty close have a kill switch on
**Admin → Settings**; the reset job has nothing to switch off — it only zeroes counters a transfer would
have incremented. The month close (unilevel) has none either, on purpose: it pays no money, so there is
nothing to kill.

**General Harvest has no cron entry.** Its engine settles on every queue append (one position inline,
via `RegistrationService`) and its backlog is cleared by the on-demand `bin/general_harvest_catchup.php`
(D-08, §14.15). There is nothing to aggregate on a schedule: a block completes when the queue reaches
a position, and the amount is fixed. Run the catch-up and its doctor after enabling the stream, after a
repair, or after a deploy that fixed a bug — never on a cron schedule.

---

# 1. Shop expiry

The job that cancels unpaid shop orders once their payment (or correction) window closes, releases
their reserved stock and records a `payment_expired` event. One implementation, three entry points.
It is governed only by its own `shop_expiry_enabled` switch, **not** by **Enable Cancel of Order**
(`shop_cancel_enabled`, off by default): the sweep touches only unpaid orders, which never produced a
wallet refund or a repeat-purchase award.

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
| Repeat-purchase bonuses are not being released | the release switch is off, the order is not delivered, or its hold has not elapsed | `bin/unilevel_release.php --doctor`, then `--dry-run` |
| An order's pending awards never release | the order was never delivered, so it has no `delivered_at`/`completed_at` | deliver it, or turn on **Enable Cancel of Order** and cancel it — cancellation cancels the pending awards and moves no money. There is no auto-release |
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

The six jobs run from Laragon on Windows. Register them once in Task Scheduler with these
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

# Repeat-purchase release - hourly at :20.
schtasks /Create /F /SC HOURLY /MO 1 /ST 00:20 /TN "EcoWealth - unilevel release" ^
  /TR "\"$php\" \"$app\bin\unilevel_release.php\" --limit=200"

# Royalty month close - 00:30 on the first of every month (15 minutes after the unilevel close).
schtasks /Create /F /SC MONTHLY /D 1 /ST 00:30 /TN "EcoWealth - royalty month close" ^
  /TR "\"$php\" \"$app\bin\royalty_month_close.php\" --max-batches=20"
```

`/SC HOURLY /MO 1 /ST 00:20` starts the hourly chain at 00:20, so it runs at :20 past every hour —
the schedule §6 documents. (`/SC HOURLY` alone would start at the current minute.)

The six launchers (`bin\shop_expire.cmd`, `bin\order_autocomplete.cmd`,
`bin\reset_transfer_limits.cmd`, `bin\unilevel_month_close.cmd`, `bin\unilevel_release.cmd` and
`bin\royalty_month_close.cmd`) exist for
a machine where PHP is not on `PATH`; point the task at the `.cmd` file instead if you prefer:

```powershell
schtasks /Create /F /SC MINUTE /MO 15 /TN "EcoWealth - shop expiry (cmd)" ^
  /TR "C:\laragon\www\ecowealth\system\bin\shop_expire.cmd --limit=200"

schtasks /Create /F /SC MONTHLY /D 1 /ST 00:15 /TN "EcoWealth - unilevel month close (cmd)" ^
  /TR "C:\laragon\www\ecowealth\system\bin\unilevel_month_close.cmd"

schtasks /Create /F /SC HOURLY /MO 1 /ST 00:20 /TN "EcoWealth - unilevel release (cmd)" ^
  /TR "C:\laragon\www\ecowealth\system\bin\unilevel_release.cmd --limit=200"

schtasks /Create /F /SC MONTHLY /D 1 /ST 00:30 /TN "EcoWealth - royalty month close (cmd)" ^
  /TR "C:\laragon\www\ecowealth\system\bin\royalty_month_close.cmd --max-batches=20"
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

---

# 6. Unilevel release

The job that **credits the repeat-purchase bonus to the upline's withdrawable e-wallet**. Since the
escrow change (Phase 18, a post-17 point release; plan §6–§7 and §17) a paid order writes **`pending`**
awards and moves **no money**. This job is the only place that money enters a wallet, and it does so
once per award, after the order is delivered and the award's snapshotted hold window has passed.

| Entry | Path | Use |
|---|---|---|
| **CLI** | `system/bin/unilevel_release.php` | Hostinger cron, SSH, CI |
| **Web** | `/system/cron/unilevel_release.php` → `system/public/cron/unilevel_release.php` | Local testing in a browser, or a host whose cron only calls a URL |
| **Windows** | `system/bin/unilevel_release.cmd` | Task Scheduler, double-click, Laragon terminal |

The logic lives in `UnilevelService::releaseDue()` and `UnilevelService::releaseForOrder()`; every entry
calls them, so they cannot disagree.

## What it does

An award is released only when **all** of these are true:

1. `unilevel_awards.status = 'pending'`.
2. The order is `delivered` or `completed`, and not cancelled (`cancelled_at IS NULL`).
3. `COALESCE(shop_orders.delivered_at, shop_orders.completed_at)` is set.
4. `COALESCE(delivered_at, completed_at) + unilevel_awards.hold_days <= NOW()` — the award's **own**
   snapshotted hold, not the current setting.

For each due award the job:

- locks the order row first (`SELECT … FOR UPDATE`), re-checks the status and the due date, then locks
  the order's `pending` awards;
- writes **one** `unilevel` wallet credit (`withdrawable: true`) with the usual note
  *“Repeat-purchase bonus — <product> (level N) — order <no>”*, and marks the award `credited` with
  `ledger_id` and `credited_at`;
- one transaction per order, so a failure rolls that order back and leaves its awards `pending`;
- writes one `unilevel.release` audit row per released order.

It does **not** create an award row, re-check eligibility, re-read the ladder, or change
`shop_orders.unilevel_total` (that stays the **awarded** total: `pending + credited`). It never moves
money for a `skipped`, `cancelled` or `voided` award. It is idempotent: a second run finds nothing due,
and the wallet's `(user, ref_type, ref_id, type)` key is the final guard against a double credit.

| Setting | Default | Role |
|---|---|---|
| `unilevel_hold_days` | `7` | days after delivery before release (0–365; 0 releases at delivery) |
| `unilevel_release_enabled` | `1` | **kill switch** — when `0`, every run is skipped and pending stays pending |
| `unilevel_enabled` | `0` | master switch — off stops **new** awards, but pending ones still release |
| `unilevel_reversal_enabled` | `1` | governs awards **already credited** only; pending awards are always cancelled with the order when cancellation is enabled (Enable Cancel of Order, off by default) |

> **The hold is a snapshot.** Every award stores the `unilevel_hold_days` value that was in force when
> the order was **paid**, so changing the setting moves no existing award's promised date. The setting
> applies to new awards only.
>
> **The window is day-granular.** Release happens on the first run after `delivery + hold_days`, so
> hourly is plenty; every 15 minutes buys nothing but log lines.

## When releases are paused

`unilevel_release_enabled = 0` pauses **this job only**:

- each run exits `0` and logs a `WARN`; nothing is credited;
- pending awards stay `pending` — they are an obligation, not a loss, and they release on the first run
  after the switch is back on (the due date is unchanged);
- **when Enable Cancel of Order is on, cancelling an order still cancels its pending awards.** The
  release switch never makes a cancelled order payable (ESC-06/ESC-07); with the cancellation switch
  off (the default) there is no cancellation to make.
- the member-facing reminder says **“Release is currently paused by the administrator.”**

Turning the global master `unilevel_enabled` off is different: it stops new awards (a paid order is
stamped with a zero total) but does **not** pause this job.

## Running it locally

**In a browser (no setup):**

```
http://localhost/ecowealth/system/cron/unilevel_release.php
```

A loopback request is trusted, so no key is needed on the development machine.

| URL | What it shows |
|---|---|
| `?doctor=1` | PHP/MySQL clocks, the two settings, pending count/amount, the oldest pending award, due now, undelivered, stuck `shipped`, `cancelled_due` (must be 0), the lock and the log folder |
| `?dry-run=1` | the orders, awards, recipients and amounts it would release; changes nothing |
| `?order=ID` | the one-order diagnostic; still respects the normal due rule |
| `?json=1` | the machine-readable payload |
| `?limit=50` | smaller batch |

**In a terminal:**

```powershell
cd C:\laragon\www\ecowealth\system

php bin\unilevel_release.php --doctor
php bin\unilevel_release.php --dry-run
php bin\unilevel_release.php
php bin\unilevel_release.php --order=93 --json

# or through the Windows launcher (no PATH setup needed if PHP_EXE is set)
bin\unilevel_release.cmd --dry-run
```

| Flag | Effect |
|---|---|
| `--limit=200` | due orders one run may release (clamped 1–1000) |
| `--order=ID` | try exactly one order, under the normal due rule — a diagnostic, never a force |
| `--dry-run` | list the candidates and their awards; write nothing, credit nothing |
| `--doctor` | environment / settings / health report (see below) |
| `--json` | machine-readable payload |
| `--log-dir=<dir>` | where the monthly log is written (CLI only) |
| `--quiet` | no stdout (the log still records the run; CLI only) |
| `--help` | usage |

## Running it on a live server

Hourly at `:20` is enough; the release window is day-granular:

```cron
20 * * * * /usr/bin/php /path/system/bin/unilevel_release.php --limit=200 >> /path/system/storage/logs/cron.out 2>&1
```

URL form (same access rules and `CRON_KEY` as the other jobs):

```cron
20 * * * * curl -s "https://example.com/ecowealth/system/cron/unilevel_release.php?key=a-long-random-string" > /dev/null
```

> **Run `--dry-run` first on a database with pending awards.** It lists exactly what the real run would
> credit and touches no wallet. If the list contains an order you expected to stay pending, read
> `--doctor`: the hold or the delivery stamp is the answer.

## Logs, locks and failure modes

| What | Where |
|---|---|
| Monthly log | `system/storage/logs/unilevel_release-YYYY-MM.log` |
| Lock | `system/storage/locks/unilevel_release.lock` (advisory; released automatically) |

One line per run:

```
[2026-11-21 09:20:01 +08:00] [OK   ] trigger=cli limit=200 hold=7 candidates=3 released_orders=3 released_awards=4 amount=50.00 skipped=0 errors=0 duration=120ms ids=93,94,95
```

`candidates` is how many orders were due; `released_orders`/`released_awards` are what the run actually
credited (an order can be a candidate and still release nothing if its awards stopped being due under
the lock); `ids` are the order ids. A dry run writes its own `[INFO ]` line
(`dry-run … limit=… hold=… pending=… candidates=… ids=…`) and credits nothing.

`--doctor` / `?doctor=1` is the first thing to run on a new server and the first thing to run when a
member says a bonus never arrived. It prints:

| Line | What it proves |
|---|---|
| `php`, `timezone`, `db_now` | PHP and MySQL agree on the time; the session zone must read `+08:00` |
| `master`, `release_enabled`, `hold_days` | which switches are on and the **current** hold setting |
| `pending`, `oldest_pending` | the pending count/amount and the age of the oldest award |
| `due_now`, `undelivered` | what the next run should credit, and what is waiting for a delivery that never happened |
| `stuck_shipped` | pending awards on `shipped` orders older than 30 days — the operational red flag |
| `cancelled_due` | pending awards on cancelled orders; **must be 0** |
| `lock`, `log`, `cron_key` | a free lock, a writable log folder, whether the URL form is keyed |

| Symptom | Cause | What to do |
|---|---|---|
| `skipped: another run is already in progress` (`[SKIP ]`) | a second run overlapped | normal; the line is the record. Investigate only if it repeats |
| `skipped: releases are paused in the admin settings` (`[WARN ]`) | `unilevel_release_enabled = 0` | expected while paused; switch it on to resume |
| `released_awards=0` and `candidates=0` | nothing is due: no delivery, or the hold has not elapsed | `--doctor`: read `undelivered`, `due_now` and `hold_days` |
| `released_awards=0` and `candidates>0` | an award stopped being due between the candidate query and the lock (or a wallet refusal rolled the order back) | read the log's `errors`; the order is retried on the next run |
| `errors>0` (exit 1) | a wallet or database failure for at least one order | the log names the order; the award stays `pending` and is retried. Do not edit award rows |
| pending never falls on a delivered order | the order has no `delivered_at`/`completed_at`, or the hold is longer than expected | `--doctor`; a delivered order always carries the stamp |
| pending on an undelivered order | the order is still `shipped` (or older) | **no auto-release by design.** Deliver it, or turn on **Enable Cancel of Order** and cancel it — cancellation cancels the award with no money moved |
| `cancelled_due` is not 0 | a cancelled order kept a pending award — a real money bug | stop releases (`unilevel_release_enabled=0`), take a backup, run `php bin/wallet_reconcile.php` and escalate; never hand-edit the row |

> **Undelivered orders never release.** Their pending awards stay pending forever, and that is correct
> under a delivery-based return window. `--doctor` and the admin repeat ledger are how they stay
> visible; there is no auto-release and no manual “release now” button.

## QA

The escrow walkthrough (v2) in [`qa-unilevel-product.md`](qa-unilevel-product.md) covers this job from
the browser and the terminal: C1a (payment moves no money), the fulfilment step, C1b (delivery +
release credits exactly once), E4 (cancellation before release pays nothing — turn on **Enable Cancel
of Order** for the check, then turn it back off) and E6 (the hold actually
holds).

---

# 7. Royalty month close

The job that **evaluates every completed calendar month and pays the Royalty bonus**. It is the only
place a `royalty_awards` row or a `royalty` wallet credit is written. It arrived with Phase 19F; the
money engine itself is Phase 19C (`RoyaltyService`, see [`admin-guide.md`](admin-guide.md) §16).

| Entry | Path | Use |
|---|---|---|
| **CLI** | `system/bin/royalty_month_close.php` | Hostinger cron, SSH, CI |
| **Web** | `/system/cron/royalty_month_close.php` -> `system/public/cron/royalty_month_close.php` | Local testing in a browser, or a host whose cron only calls a URL |
| **Windows** | `system/bin/royalty_month_close.cmd` | Task Scheduler, double-click, Laragon terminal |

The whole implementation lives in `bin/royalty_month_close.php`; the URL entry requires that one file,
so the three ways in cannot disagree about which months are final. A request that reaches the URL entry
without the web server (a CLI test, for example) still follows the HTTP rules: it is the URL entry.

## What it does

A run works through the months **oldest first**, one period at a time:

1. **Resume** every period whose header is `evaluated` (a payment paused, or a batch ceiling reached):
   pay what is still `pending`.
2. **Close** every completed month from the baseline (`royalty_ledger_since`, plan D-15) through last
   month that has **no header yet**: evaluate it (write the header and one award row per
   Supervisor/Manager/Director), then pay it.

A month is evaluated at **its own period-end cutoff** and its own volume window, so a catch-up months
later produces exactly the awards an on-time run would have produced. A failure stops the loop: the
later months stay open and the next scheduled run resumes them. A partial payment is therefore healed
by the next run with no operator action.

| Fact | Where it comes from |
|---|---|
| Personal sales (PS) | the member's paid order line totals in the month (`paid_at`, cancelled orders excluded) |
| Group sales (GS) | PS plus every member descendant's PS |
| Rank | derived from the sponsor tree **as of the period end** (`users.created_at < end`), so a late close reproduces the same ranks |
| Amount | `round_once(group% x GS + personal% x PS)`, in integer centavos |
| Quota | all-or-nothing: one centavo short pays nothing, including the personal share |
| State | `evaluated` -> `paid`; `skipped` when the stream is off. **A final month is never reopened, recalculated or voided.** |

- **One transaction per payment batch**, header locked first; a crash rolls the batch back, keeps the
  earlier batches paid and leaves the header `evaluated`.
- **One award per member per month** (`UNIQUE (period, user_id)`), and the wallet's own
  `(user_id, ref_type, ref_id, type)` key is the last guard against a double credit.
- **Only Supervisor, Manager and Director get a row.** A Leader is a rank, not a royalty rank.
- **`--evaluate-only` is the review window.** It writes the rows and pays nothing; `--pay-only` pays
  them. The runbook (`docs/runbook.md` §2) says how long that window should be.

| Setting | Default | Role |
|---|---|---|
| `royalty_enabled` | `0` | the earning switch: `0` closes the month as **skipped** and pays nothing |
| `royalty_pay_enabled` | `1` | the payment switch: `0` evaluates but leaves every award `pending` |
| `royalty_ledger_since` | install time | the baseline: months before the first payable month are never paid |
| the four thresholds / three quotas / six rates | 10 / 10 / 10 / 10, 500k / 1M / 5M, 3/5, 5/10, 10/15 | the ladder the close snapshots onto the header and every row |

> **The ladder is snapshotted.** Every period header stores the four thresholds, the three quotas and
> the six rates that were in force, and every award row stores the quota and the two rates it was
> priced with. A later settings change therefore never rewrites a closed month.

## Running it locally

**In a browser (no setup):**

```
http://localhost/ecowealth/system/cron/royalty_month_close.php
```

A loopback request is trusted, so no key is needed on the development machine. Any other caller needs
`?key=` matching `CRON_KEY`; without a configured key a remote caller is refused with **403**.

| URL | What it shows |
|---|---|
| `?doctor=1` | clocks, time zone, tables, the sixteen settings, the ladder, the baseline, both switches, the last final month, pending and evaluated months, the paid-with-pending check, the lock, the wallet reconcile |
| `?dry-run=1` | the awards it would write and pay, per month; changes nothing |
| `?period=2026-10&dry-run=1` | the same for one month |
| `?evaluate-only=1&period=2026-10` | write the rows, pay nothing |
| `?pay-only=1&period=2026-10` | pay an evaluated month |
| `?max-batches=5` | stop after five payment batches; the month stays `evaluated` |
| `?json=1` | the machine-readable payload (including the operations block) |

**In a terminal:**

```powershell
cd C:\laragon\www\ecowealth\system

php bin\royalty_month_close.php --doctor
php bin\royalty_month_close.php --dry-run
php bin\royalty_month_close.php --period=2026-10 --dry-run --json
php bin\royalty_month_close.php
php bin\royalty_month_close.php --pay-only --period=2026-10
php bin\royalty_month_close.php --max-batches=20

# or through the Windows launcher (no PATH setup needed if PHP_EXE is set)
bin\royalty_month_close.cmd --doctor
```

| Flag | Effect |
|---|---|
| `--period=YYYY-MM` | close (or resume/pay) exactly that **completed** month; the current and future months are refused |
| `--dry-run` | compute and print the awards; write nothing (no rows, no wallet, no audit) |
| `--evaluate-only` | write the award rows and the header; pay nothing |
| `--pay-only` | pay an already-evaluated month; resumes a partial payment. An absent, `paid` or `skipped` month is an error |
| `--limit=N` | payment batch size, 1-5000 (default 500) |
| `--max-batches=N` | stop after N batches (default 20) and leave the month `evaluated`; the next run continues |
| `--doctor` | environment / settings / health report |
| `--json` | machine-readable payload |
| `--log-dir=<dir>` | where the monthly log is written (CLI only) |
| `--quiet` | no stdout (the log still records the run; CLI only) |
| `--help` | usage |

## Running it on a live server

Once a month, at **00:30 on the first** (fifteen minutes after the unilevel close, so the two log files
read in a predictable order; the jobs are independent):

```cron
15 0 1 * *  /usr/bin/php /path/system/bin/unilevel_month_close.php
30 0 1 * *  /usr/bin/php /path/system/bin/royalty_month_close.php --max-batches=20 >> /path/system/storage/logs/cron.out 2>&1
```

`--max-batches=20` bounds one run's wall time. A month too large for twenty batches is left
`evaluated` with `status=evaluated` in the log and `resume_required` in the JSON; the next scheduled run
pays the rest. A monthly schedule is fine: once the month is final, the next run is an exit-0 no-op.

URL form (same access rules and `CRON_KEY` as the other jobs):

```cron
30 0 1 * *  curl -s "https://example.com/ecowealth/system/cron/royalty_month_close.php?key=a-long-random-string" > /dev/null
```

> **Enable the stream at a month boundary.** With `royalty_enabled = 0` the job still runs and records a
> **skipped** month, which can never be paid later. To make the month that just ended the first paid
> month, switch Royalty on **before** the 00:30 close on the 1st; to make the new month the first, wait
> until after the close. Either way, run `--doctor` first — it must be green.

## Logs, locks and failure modes

| What | Where |
|---|---|
| Monthly log | `system/storage/logs/royalty_month_close-YYYY-MM.log` |
| Lock | `system/storage/locks/royalty_month_close.lock` (advisory; released automatically) |

The log lives outside `public/`, so it is never web-readable. One line per run:

```
[2026-12-01 00:30:02 +08:00] [OK   ] trigger=cli closed=1 periods=2026-11 award=12 paid=10 amount=183000.00 skipped=2 status=paid paused=0
```

`closed` counts the months that reached a final status (`paid` or `skipped`) in this run — including a
month that finalised at evaluation time because every award row was skipped; `periods` lists every
month the run touched, in
order; `award` is the rows written; `paid`/`amount` are the wallet credits; `skipped` is the rows paid
nothing; `status` is the last month's status; `paused=1` means the payment switch was off. A catch-up
names every month it closed. A dry run writes its own `[INFO ]` line (`dry-run trigger=... periods=...
award=...`); a busy run writes `[SKIP ]`. A log write failure warns through PHP's error log (stderr on
the CLI) and never fails the run.

**Exit codes (all runs):**

| Code | Meaning |
|---|---|
| `0` | success, nothing to do, busy, or payments paused |
| `1` | a processing or validation error (a refused period, `--pay-only` on an absent month, a doctor failure) |
| `2` | environment (database down, missing tables, storage not writable) |

The web entry answers **HTTP 200** for exit `0` and **HTTP 500** for `1` and `2`, and carries the exit
code in the JSON (`exit_code`, `http_status`). A refused request (missing/wrong key from a non-loopback
address) is **403** and does no work.

**The doctor** (`--doctor` / `?doctor=1`) is the first thing to run on a new server and the first thing
to run when a month looks stuck. It exits **1** only for a hard failure and lists every line with a
`FAIL`/`WARN` marker:

| Line | What it proves |
|---|---|
| `php`, `mysql`, `db_now` | the versions and the database's own clock |
| `clocks` | PHP and MySQL agree within **2 seconds** (a skew fails the doctor) |
| `timezone` | PHP runs `Asia/Manila` and the MySQL session reads `+08:00` |
| `tables` | `royalty_periods` and `royalty_awards` exist (a missing table is named) |
| `settings` | all **16** keys are present and the ladder is valid (quota order, 0-100% rates) |
| `ledger since` | the baseline and the first payable month |
| `enabled` | both switches; a paused payment switch is a **warning** |
| `last paid`, `last final` | the newest paid month and the newest final month |
| `pending` | completed months with no header — the next run closes them (warning) |
| `evaluated` | months evaluated but not paid, with their pending count and age (warning) |
| `paid awards` | a `paid` month still holding a `pending` award — a **failure** |
| `counts` | member, award-row and period counts |
| `lock`, `log`, `storage` | a free lock, a writable log folder, the storage path |
| `wallet` | `RoyaltyService::reconcile()` — a mismatch fails the doctor |
| `cron key` | whether the URL form is keyed |

| Symptom | Cause | What to do |
|---|---|---|
| `[SKIP ] busy` | a second run overlapped | normal; the line is the record. Investigate only if it repeats |
| `closed=0` with `nothing to close` | every month through last month is final | nothing to do; `--dry-run` proves it |
| a month stays `evaluated` | the batch ceiling was reached, or payments are paused | `--doctor`, then `--pay-only --period=YYYY-MM`; a paused switch is the answer |
| `paused=1` in the log | `royalty_pay_enabled = 0` | awards stay pending by design; turn the switch on and resume |
| `refused: ... has not finished yet` | `--period` named the current month | only a completed month can be closed |
| `refused: ... before the Royalty ledger baseline` | the month precedes `royalty_ledger_since` | by design (D-15); no retroactive pay |
| a `skipped` month with `royalty stream off` | the stream was off when it closed | a skipped month is final and can never be paid later |
| `exit 2` | the database is down or a table is missing | restore the database, then `--doctor` |

## QA

The Phase 19F gate (`docs/qa/phase-19-royalty.md` section F, `QA19F-01` ... `QA19F-08`) walks the doctor,
the URL entry, a dry run, the real close, a second run, a held lock and the Task Scheduler launcher. The
automated cases are `P19F-T1` ... `T16` in `tests/Cases/RoyaltyCronTest.php`; the operations panel is
`P19F-T13` and the health check `P19F-T14`.

---

## Streams with no scheduled job

**Team Harvest (Phase 20) adds no cron entry, deliberately.** It has no month, no period, no quota and
no purchase input, so there is nothing to close and nothing to evaluate on a schedule: a level is decided
by the registration that completes it, and a completed level pays at that event (plan §12.7, D-08). The
stream's two jobs are **on demand** and neither is in the table above:

| Job | When | Why it is not scheduled |
|---|---|---|
| `bin/harvest_queue_seed.php` | **once**, at go-live, before the switch is turned on | it reconstructs history; running it again would refuse (positions are facts) |
| `bin/team_harvest_catchup.php` | after enabling the switch, after a repair, after a counter-drift alarm, after a deploy that fixed a bug | the engine is state-based; a schedule would evaluate a population that has not changed |

Both take their own lock, write one log line per run and audit their writes; neither has a guarded URL
entry, because a browser cannot be trusted with a money run. The go-live order and the incident drills
live in [`runbook.md`](runbook.md) §*Team Harvest go-live order* and §*Team Harvest award and reconcile
drill*. The reconciliation (`wallet_reconcile.php`) is the weekly check, not a job to schedule.