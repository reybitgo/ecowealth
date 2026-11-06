# Shop expiry cron

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

## Exit codes

| Code | Meaning |
|---|---|
| `0` | run completed, skipped (locked) or disabled |
| `1` | the job could not run (database, configuration, unwritable storage) |

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Browser shows `403 Forbidden` | not loopback and no/wrong key | add `?key=…` matching `CRON_KEY` |
| “another run is already in progress” on every run | a crashed run left the process, or two crons overlap | check `--doctor`; reduce overlap by running every 15 min |
| `--doctor` says `log … (NOT writable)` | the account cannot write `system/storage/logs` | create the folder, `chmod 775`, or run as the account owner |
| Orders are not expiring | kill switch off, no overdue orders, or deadlines in the future | `--doctor`, then `--dry-run` |
| `db now` differs from `timezone` | MySQL session time zone drifted | check `DB_TIMEZONE` in `app/config.php` (default `+08:00`) |
| 500 on the URL entry | a PHP fatal — the response hides details by design | run the CLI version, or check the Apache error log |

---

## QA

QA7-13 in [`docs/qa/phase-7-payments.md`](qa/phase-7-payments.md) walks through: forcing an order
overdue with SQL, `--doctor`, `--dry-run`, the real URL run, a second idempotent run, the log line,
the event trail, the released stock, and the kill switch.
