# Phase 15 evidence (2026-11-11)

Browser screenshots were not captured for this pass. The evidence below is the output of the commands
that back the sign-off sheet; run them again on any machine to reproduce it.

## PHP suite

```
EcoWealth test suite
PHP 8.3.16 · schema ecowealth_sneat_test · phase 15
...
Cases: 722   Passed: 722   Failed: 0
All good.
```

Command: `php tests/run.php` from `system/`.

## HTTP smoke

```
EcoWealth HTTP smoke test (phases 1-15)
  app:      http://localhost/ecowealth/system
  database: ecowealth_sneat
...
  P15-T2 PASS   security headers are sent on a public page (nosniff=nosniff, frame=DENY)
  P15-T2 PASS   uploads/ refuses to execute PHP (status=403)
  P15-T2 PASS   reports and audit stay admin-only (member=403, guest=302)
  P15-T8 PASS   an unknown URL renders the branded 404 (status=404)
  P15-T7 PASS   each settings card saves, audits and is effective (brand=True, rendered=True, limits=True, refused=True)
  P15-T1 PASS   the reports desk renders every report and the reconciliation widget (status=200)
  P15-T1 PASS   the report window is applied and echoed (status=200)
  P15-T3 PASS   the reports page answers in 0.182s (budget 2.000s)
  P15-T1 PASS   the five CSV exports download and are audited (sales=200, audit=5)
  P15-T12 PASS  the audit desk lists and filters the run's actions (all=200, filtered=200)
  P15-T2 PASS   the transfer form is rate-limited (last=302, flash=True)
...
Passed: 185   Failed: 0
```

Command: `powershell -NoProfile -File tests\smoke.ps1` from `system/` (Laragon running; MySQL `root`
with an empty password on the local machine).

## Headers (curl)

```
HTTP/1.1 302 Found
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()
Cross-Origin-Opener-Policy: same-origin
X-Permitted-Cross-Domain-Policies: none
X-Robots-Tag: noindex, nofollow
```

Command: `curl -s -D - -o NUL http://localhost/ecowealth/system/admin/reports`.

## Finance health (Reports → Finance health / `HealthService`)

```
ok=yes failing=0 checks=7
  OK  wallets: 26 accounts reconcile against the ledger on both buckets
  OK  commissions: 8 credited award(s) (2,430.00) match the ledger; 4 skipped
  OK  awards: Every award points at its activation and ledger row; every approved activation is processed
  OK  transfers: 3 transfer(s) carry their sender, recipient and fee legs
  OK  payouts: 2 request(s) checked; 0.00 currently held by open requests
  OK  orders: 16 order(s) reconcile with their line items
  OK  stock: 10 product(s) hold no more stock than they have
```

## Restore drill

```
dump bytes: 1,159,406
restore exit: 0
users 26 · orders 16 · ledger 48 · commissions 12 · payouts 2 · audit 1,781 · settings 45
reconcile restored: All good — wallets, commissions, transfers and payouts reconcile. (exit 0)
health restored: ok=yes failing=0 checks=7
scratch schema dropped: exit 0
```

Commands and the full procedure: `docs/runbook.md` §3.

## Migration 019

```
first apply exit: 0
second apply exit: 0
SHOW INDEX: idx_audit_action, idx_audit_created, idx_orders_created, idx_orders_paid,
            idx_ewallet_created, idx_commissions_level, idx_reg_codes_created
```

`MigrationTest` also applies 001–019 to the legacy fixture and compares the result with
`database/schema.sql` on every suite run.
