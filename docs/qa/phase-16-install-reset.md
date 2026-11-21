# EcoWealth — Phase 16 manual QA: install, reset and go-live

**Scope.** The install/reset workstream (`dmp/plan-ecowealth-install-reset.md`, IR-1 … IR-8): the
production `install.sql` bundle, the CLI and browser installers, the guarded development reset
(engine, CLI and web console), the demo fixtures and the tool-retirement checklist. This is **not**
an application phase: no route, controller, view or layout changed, and the Phase 1–15 checks remain
the application's manual pack.

**Audience.** The developer/operator who runs the phase gate. These checks are destructive by
design: every one except the production-gate checks runs on a **scratch schema** created for the
exercise, never on `ecowealth_sneat`.

**Document status.** Signed from the automated suites, the HTTP smoke run, the IR evidence folder
and the final IR-8 verification pass (2026-11-12).

---

## 1. What is already automated

Run these first (from `system/`). If any fails, stop and report before starting the manual pass.

```powershell
php bin\build_install_sql.php --check     # exit 0, no output: the bundle matches its sources
php tests\run.php                         # full suite
powershell -NoProfile -File tests\smoke.ps1 -DbUser root -DbPass ''
```

Expected final lines:

```text
Cases: 816   Passed: 816   Failed: 0
All good.
```

```text
Passed: 195   Failed: 0
```

The install/reset groups in the suite (92 of the 816 cases):

| Group | Cases | What it proves |
|---|---:|---|
| `SqlScript` | 15 | The statement parser: quotes, comments, backticks, line numbers, `DELIMITER`/database-switch rejection, failure context, checksum, `schema.sql` import |
| `DbTarget` | 12 | Connection/database probing, identifier validation, table listing, operator hints (no DSN, no password) |
| `SeedParity` | 4 | Every setting a migration inserts exists in seed 001 and in `install.sql`; no duplicate keys; no executable database switch |
| `InstallSql` | 6 | The bundle is current, imports into an empty scratch schema, matches the fresh schema/settings/catalogue by natural key, and is refused by a non-empty one |
| `Install` | 15 | Installer preflight, install + lock, re-run and non-empty refusals, password policy, `verify()`, JSON shape, production key gate, demo install |
| `DbReset` | 28 | Preview/dry-run, the transactional reset, staff defaults, audit kept/cleared, catalogue reset, codes, rollback, uploads, live revisions, AUTO_INCREMENT, the refusal matrix, demo + parity |
| `DbToolAccess` | 12 | Install-key matching, reset visibility, the actor matrix, the confirmation phrase per environment, S-Login refusal |

Two pre-provisioned scratch schemas back the tool cases: `ecowealth_sneat_tool_a_test` and
`ecowealth_sneat_tool_b_test`. Each case recreates its slot; no case drops the schema (that would
remove the grant) and no case targets `ecowealth_sneat` or `ecowealth_sneat_test`.

The HTTP smoke script adds ten tool checks: `IR4-T1…T5` (web installer refuses a locked/non-empty
tree, sends the hardening headers, writes no lock on GET or a CSRF-less POST, serves the shared
stylesheet) and `IR6-T1…T5` (guest redirect, member 403, admin form with no secret, three refusals
that change nothing, console stylesheet).

---

## 2. Environment and rules

| Item | Value |
|---|---|
| App | `http://localhost/ecowealth/system/` (Laragon Apache + MySQL) |
| Development database | `ecowealth_sneat` — **never** a target of these checks |
| Scratch schemas used here | `ecowealth_sneat_install_manual`, `ecowealth_sneat_reset_manual`, `ecowealth_sneat_ir8_test`, … — created as root, granted to `ecowealth`, dropped afterwards |
| Pre-provisioned tool schemas | `ecowealth_sneat_tool_a_test`, `ecowealth_sneat_tool_b_test` (automated cases only) |
| App selection without editing config | `$env:ECO_DB_NAME = '<scratch>'` (PowerShell) or `export ECO_DB_NAME=<scratch>` (bash) |
| PHP / MySQL | 8.3.16 · 8.4.3 |
| Evidence | `docs/qa/evidence/install-reset/` (see §4) |

Rules that protect the project:

1. **Create every scratch schema as root** and grant it to the app user; the app user has no
   `CREATE DATABASE` privilege, and that is deliberate.
2. **Never run an install or reset against `ecowealth_sneat`.** The engine proves its connection is
   on the named database, but the checks below are destructive anyway.
3. **Back up before anything destructive**: `docs/runbook.md` §3. A reset has no undo.
4. **Do not leave demo data or a lock behind** on a schema you keep; drop the scratch schema when
   the evidence is filed.
5. **Never put a password, key, cookie or DSN in the evidence folder.** The tools do not print them;
   if a screenshot shows one, retake it.
6. **Restore `APP_ENV` and `DB_RESET_ENABLED`** after the production checks (QA16-12 … QA16-14). The
   IR-4/IR-6 passes used a temporary router wrapper that defines `APP_ENV=production`, so
   `config.local.php` was never edited; either method is acceptable if the local config is restored.

---

## 3. The checks

### QA16-01 — A fresh install through `mysql` produces the documented state

| Why it matters | A fresh production install must be one command and one known state. |
|---|---|
| Where | Scratch schema `ecowealth_sneat_install_manual` |
| Evidence | `install-sql-mysql.txt`, `ir2-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. As root: `DROP DATABASE IF EXISTS ecowealth_sneat_install_manual; CREATE DATABASE ecowealth_sneat_install_manual CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` and grant it to `ecowealth`.
2. From `system/`: `mysql -u root ecowealth_sneat_install_manual < database/install.sql`.
3. Read the footer verification `SELECT`.
4. Point the app at it (`$env:ECO_DB_NAME = 'ecowealth_sneat_install_manual'`), open `/login`, sign in as `admin` / `Admin@123`.

Expected

- The guard passes on an empty schema; the footer prints **27 tables · 48 settings · 2 staff ·
  0 members · 7 products · 2 packages · 18 levels · 10 contents · 0 codes**.
- The app boots and the seeded admin signs in; `/admin/products` shows 7, `/admin/packages` 2,
  `/admin/members` 0, `/admin/codes` 0.
- No `CREATE DATABASE` or `USE` is executed (the bundle carries only prose).

### QA16-02 — A non-empty schema is refused by the bundle guard

| Why it matters | A re-import must stop loudly instead of half-applying. |
|---|---|
| Where | A scratch schema with one table |
| Evidence | `install-sql-guard.txt`, `ir2-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Create a scratch schema and one unrelated table.
2. Run `mysql -u root <scratch> < database/install.sql`.
3. Confirm the exit code and the error text.

Expected

- MySQL exits **1** with `ERROR 1146 (42S02)` naming
  `ecowealth_install_requires_an_empty_database`; the schema still holds 1 table (no partial
  schema was created).
- The installer paths refuse a non-empty target **before** running a statement (QA16-03/05).

### QA16-03 — The CLI installer installs, verifies and locks

| Why it matters | A guided install must never leave a default password or an unlocked tree. |
|---|---|
| Where | Scratch schema `ecowealth_sneat_install_cli_test` |
| Evidence | `install-cli-check.txt`, `install-cli-success.txt`, `install-cli-success.json`, `install-cli-refused.txt`, `ir3-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. `php bin/db_install.php --check` — read the 15-check preflight.
2. Create an empty scratch schema and run the guided install with both passwords on stdin and
   `--force`, as recorded in `install-cli-success.txt`.
3. Re-run the same command; then move the lock aside, add a probe table and re-run again.
4. Run the same install with `--json` and inspect the document.

Expected

- `--check` exits **0** and lists 15 green checks (PHP, four extensions, readable local config,
  reachable MySQL, safe database name, schema exists, schema empty, parseable bundle, writable
  storage and lock folder, lock absent, production key).
- The install exits **0**, prints 27/48/2/0/7/2/18/10/0, both chosen passwords verify, the lock is
  written, and the lock JSON carries `installed_at`, `database`, `install_version`,
  `install_sql_sha256`, `staff` (usernames only) and `channel: "cli"`.
- The second run exits **3** with the "already installed" lock message and the lock hash unchanged.
- The non-empty run exits **3** with the empty-database message, writes no lock, and the probe row
  is untouched.
- `--json` prints exactly one document with `tool: "db_install"` and `ok: true`.

### QA16-04 — phpMyAdmin imports the same bundle into an empty schema

| Why it matters | Shared hosting usually has no SSH; the browser import is the primary online path. |
|---|---|
| Where | phpMyAdmin 5.2.2 → scratch schema `ecowealth_sneat_install_pma_test` |
| Evidence | `install-sql-phpmyadmin.png`, `install-sql-phpmyadmin-import.png` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Create an empty scratch schema in phpMyAdmin.
2. Select it → **Import** → `database/install.sql` → **Stop on error** → **Go**.
3. Read the "Import has been successfully finished" panel and the footer counts.

Expected

- "54 queries executed"; the footer prints 27/48/2/0/7/2/18/10/0 and no `CREATE DATABASE`/`USE`
  error (the file names neither).
- The same bundle bytes as QA16-01 (the `--check` gate keeps one artefact).

### QA16-05 — The web installer refuses, then installs once

| Why it matters | The first anonymous visitor must not be able to own the site, and a second install must be impossible. |
|---|---|
| Where | `http://localhost/ecowealth/system/install.php` (development), production via a temporary `APP_ENV=production` wrapper |
| Evidence | `install-preflight.png`, `install-preflight-details.png`, `install-success.png`, `install-locked.png`, `install-nonempty.png`, `install-mobile.png`, `install-web.txt`, `ir4-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Open the page on a locked tree (the IR-4 evidence tree) → "Already installed"; on a non-empty
   unlocked tree → "not empty" refusal with no form; on an empty tree → the 15-check preflight and
   the form.
2. Submit the site name, both staff credentials, the key and the typed `INSTALL`.
3. Reopen the page; then delete the lock and point it at a non-empty schema.
4. Repeat on a 390 × 844 viewport; send a GET and a CSRF-less POST and watch the lock file.

Expected

- The locked and non-empty states render independently; neither offers the form.
- The success page lists the accounts **without passwords**, the verification counts and the lock
  path; the lock appears; reopening says "already installed".
- A GET or a CSRF-less POST creates no lock and writes nothing.
- The production page without `INSTALL_KEY` refuses with the exact hint and a disabled form
  (QA16-14).
- The page is responsive at 390 px and never prints the key, a password or a DSN.

### QA16-06 — The reset CLI previews without writing

| Why it matters | The operator must be able to see the target and the counts before anything is deleted. |
|---|---|
| Where | Scratch schema `ecowealth_sneat_reset_manual` (`install.sql` + `seeds/005_demo_tree.sql`) |
| Evidence | `reset-cli-preview.txt`, `ir5-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. `$env:ECO_DB_NAME = 'ecowealth_sneat_reset_manual'`.
2. `php bin/db_reset.php --dry-run`.
3. Compare the printed counts with a direct `SELECT` after the run.

Expected

- The preview shows the target (host/port/version), the member/staff/order/ledger/code counts, the
  catalogue, the audit size, the code and demo options, and `last_reset_at` (or none).
- `--dry-run` exits **0**, prints the statement plan and `writes: 0`; every table count is unchanged
  and no transaction is left open.

### QA16-07 — The reset returns a clean, verified install state

| Why it matters | "Succeeded" is not enough: money and members must actually be clean afterwards. |
|---|---|
| Where | The same scratch schema |
| Evidence | `reset-cli-success.txt`, `reset-cli-success.json`, `reset-app-smoke.txt`, `ir5-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. `php bin/db_reset.php --force --confirm=ecowealth_sneat_reset_manual --codes=5 --code-package=1`.
2. Read the step log and the verification checklist.
3. `php bin/wallet_reconcile.php`; then boot the app on the schema and open `/dashboard`, `/admin`,
   `/admin/members`, `/admin/orders`, `/admin/codes`, `/shop`, `/admin/packages`, `/admin/products`.

Expected

- Exit **0**; every delete step reports its count; the four reconciliations, the zero-transactional
  rows, the staff defaults, the catalogue, the live revisions and the AUTO_INCREMENT checks are all
  green (12/12, or 13/13 with demo).
- The staff rows are kept and active with both balances `0.00`, no package/sponsor and the password
  hash unchanged; five fresh codes are `unused` and package-bound.
- `wallet_reconcile.php` exits **0**; the app signs in; `/admin/members` and `/admin/orders` are
  empty; the catalogue (7 products / 2 packages) is intact.

### QA16-08 — A second reset is idempotent

| Why it matters | Re-running the tool must not be a second destructive surprise. |
|---|---|
| Where | The same scratch schema after QA16-07 |
| Evidence | `final-verification.txt` §[6], `ir5-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Run `php bin/db_reset.php --force --confirm=<db>` again with no code/catalogue options.
2. Compare the per-step `deleted` counts with the first run.

Expected

- Exit **0**; every business-data step reports `0 deleted`. The only rows a second run can remove are
  the registration codes the previous run generated (codes are inventory, not business data) — with
  `--codes=0` the first time, every step reports 0. The staff rows are reset again, verification is
  green, and the catalogue and settings are unchanged.

### QA16-09 — The web console refuses a bad phrase, missing CSRF and missing backup ack

| Why it matters | A destructive form must not run on a single click or a forged request. |
|---|---|
| Where | `/system/reset.php` as a signed-in `admin` (development) |
| Evidence | `reset-form.png`, `reset-validation.png`, `reset-web.txt`, `ir6-smoke.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Open the console; read the stats, the "what will be cleared" list and the options.
2. Submit with a wrong phrase; then without the backup acknowledgement; then with the CSRF field
   removed.
3. After each attempt, read the counts on the console and in a direct query.

Expected

- Each attempt re-renders the form with an inline error and **nothing** changes (same counts, no
  audit row, no `last_reset_at`).
- The Reset button stays disabled until the phrase and the acknowledgement are present, but the
  server re-checks everything.
- The page contains no password, DSN, SQLSTATE or stack trace.

### QA16-10 — The web console resets once and PRG prevents a double run

| Why it matters | Refresh must re-render the report, never re-execute the reset. |
|---|---|
| Where | The same console |
| Evidence | `reset-success.png`, `reset-web.txt`, `ir6-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Tick the backup acknowledgement, type `RESET`, choose the options (keep catalogue, keep audit,
   five codes) and submit.
2. Read the report: step log, verification checklist, before/after counts, code chips.
3. Refresh the report URL; then click **Done** and refresh again.

Expected

- The report is green, with every step and all verification checks visible; the codes are copyable
  and exist only in the session.
- Refresh re-renders the same report with no second run (the counts do not move again); **Done**
  clears it and the form returns.
- The audit log has exactly one new `system.db_reset` row.

### QA16-11 — A member or impersonated session can never see the console

| Why it matters | The reset page is the most dangerous page in the app. |
|---|---|
| Where | `/system/reset.php` as a member, and in an S-Login tab (development) |
| Evidence | `reset-member-403.png`, `reset-impersonation-403.png`, `ir6-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Sign in as a member (for example `juan`) and open the console URL.
2. As a superadmin, open an S-Login session and open the console URL in that tab.
3. As a guest, open the URL.

Expected

- Member → branded **403**; impersonated tab → branded **403** (before any form); guest → **302**
  to `/login`.
- No console HTML, counts, codes or SQL is served in the refused responses.

### QA16-12 — Production without the opt-in is a 404 for every role

| Why it matters | A forgotten file on a production host must not expose the console at all. |
|---|---|
| Where | A production-env app (`APP_ENV=production`, `DB_RESET_ENABLED` absent/false) |
| Evidence | `reset-production-404.png` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Serve the app with `APP_ENV=production` and no `DB_RESET_ENABLED` (temporary wrapper; restore
   afterwards).
2. Open `/system/reset.php` as a guest and as a signed-in superadmin.

Expected

- Both return the branded **404** page, not the console and not a redirect.
- The response carries the shared security headers and no console content.

### QA16-13 — Production with the opt-in requires a superadmin and the database name

| Why it matters | Even an enabled console must be role- and target-gated. |
|---|---|
| Where | A production-env app with `DB_RESET_ENABLED=true` |
| Evidence | `reset-production-form.png`, `reset-production-admin-403.png` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Sign in as an `admin` and open the console.
2. Sign in as `superadmin` and open it.
3. Read the header band, the confirmation phrase and the option set.

Expected

- `admin` → **403**; `superadmin` → the form, with a red **PRODUCTION** band, the **database name**
  (not `RESET`) as the phrase, and no demo checkbox.
- The typed phrase must match exactly; a wrong name runs nothing.

### QA16-14 — A production install without `INSTALL_KEY` is refused

| Why it matters | The browser installer is the other way to gain an admin account. |
|---|---|
| Where | A production-env app on an empty schema |
| Evidence | `install-key-refused.png`, `install-key-refused-full.png`, `install-key-wrong.png`, `install-key-throttle.png`, `ir6-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Serve the app with `APP_ENV=production` and an empty `INSTALL_KEY`.
2. Open `/system/install.php`.
3. Configure a key (via `ECO_INSTALL_KEY`), submit a wrong key five times, then the right one.

Expected

- With no key: a red PRODUCTION badge, a failing `INSTALL_KEY` preflight row, the exact "not
  configured" alert and a **disabled** form; a crafted POST writes nothing.
- Wrong key: refused with the field error, then throttled after five attempts in 15 minutes.
- Correct key: the install runs and the success page prints no key, password or DSN.

### QA16-15 — A reset matches a fresh install by natural key

| Why it matters | The reset's promise is "the state a fresh install produces". |
|---|---|
| Where | Automated parity cases (`DbResetTest`); the demo tree on a scratch schema |
| Evidence | `parity-reset-vs-install.txt`, `reset-demo.txt`, `reset-demo-genealogy.png`, `reset-demo-earnings.png`, `ir7-suite.log` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. Run the `DbReset` group and read the two parity cases.
2. On a scratch install, run `--reset-catalogue --demo`, sign in as `juan` / `Demo@1234`, open
   `/genealogy` and `/earnings`.

Expected

- Both comparisons match on staff, settings, products, packages, levels, contents and — in the demo
  case — the five demo members, three codes and three ledger rows, comparing `username`, `sku`,
  `slug`, `level` and money strings only (never ids or timestamps).
- The excluded data is exactly `audit_log`, `live_revisions`, `last_reset_*` and the dynamic
  `commission_ledger_since`, each named in the transcript.
- The demo tree renders (`juan → maria → peter/sarah/david`, `david` pending).

### QA16-16 — Go-live retires the tools and the state verifies

| Why it matters | The final gate is that production cannot install or reset anything. |
|---|---|
| Where | A production-env app (`APP_ENV=production`, `DB_RESET_ENABLED` false) on a scratch install |
| Evidence | `go-live-tools-404.png`, `go-live-checklist.txt`, `ir8-security-review.txt`, `ir8-suite.log`, `ir8-smoke.log`, `final-verification.txt` |
| Result | ☑ Pass ☐ Fail ☐ Blocked |

Steps

1. On the production-env copy, delete `public/install.php` and `public/reset.php` (move them aside
   in the local tree, then restore them for development).
2. Request both URLs.
3. Run the verification pack on the scratch install and the demo-leakage and default-password
   queries.
4. Confirm the lock and key state described in `docs/runbook.md` §7.

Expected

- Both URLs return **404** (the branded page); no tool HTML, form or secret is served.
- The verification pack prints **27 / 48 / 2 / 0 / 7 / 2 / 18 / 10 / 0**; both demo queries return
  `0`; neither staff password is a documented default after the operator changed them.
- `storage/install.lock` is present for a guided install and not web-readable;
  `INSTALL_KEY` is removed (or rotated) and `DB_RESET_ENABLED` is absent or `false`.
- The security review checklist in `ir8-security-review.txt` is green (headers, no secret in HTML,
  lock outside `public/`, no route/nav link, uploads/storage guards, hardened sessions).

---

## 4. Evidence index

All files live in `docs/qa/evidence/install-reset/`; the naming convention and the full per-phase
index are in that folder's `README.md`.

| Area | Files |
|---|---|
| Automated gates | `ir1-suite.log` … `ir8-suite.log`, `ir4-smoke.log`, `ir6-smoke.log`, `ir7-smoke.log`, `ir8-smoke.log` |
| Bundle and local import | `build-install-check.txt`, `install-sql-mysql.txt`, `install-sql-guard.txt`, `install-sql-phpmyadmin.png`, `install-sql-phpmyadmin-import.png` |
| CLI installer | `install-cli-check.txt`, `install-cli-success.txt`, `install-cli-success.json`, `install-cli-refused.txt` |
| Web installer | `install-preflight.png`, `install-preflight-details.png`, `install-success.png`, `install-locked.png`, `install-nonempty.png`, `install-mobile.png`, `install-mobile-full.png`, `install-key-refused.png`, `install-key-refused-full.png`, `install-key-wrong.png`, `install-key-throttle.png`, `install-web.txt` |
| Reset CLI | `reset-cli-preview.txt`, `reset-cli-success.txt`, `reset-cli-success.json`, `reset-cli-refused.txt`, `reset-cli-production.txt`, `reset-app-smoke.txt` |
| Reset console | `reset-form.png`, `reset-validation.png`, `reset-success.png`, `reset-member-403.png`, `reset-impersonation-403.png`, `reset-production-404.png`, `reset-production-admin-403.png`, `reset-production-form.png`, `reset-mobile.png`, `reset-web.txt` |
| Demo and parity | `reset-demo.txt`, `reset-demo-genealogy.png`, `reset-demo-earnings.png`, `parity-reset-vs-install.txt` |
| Final go-live | `go-live-tools-404.png`, `go-live-checklist.txt`, `ir8-security-review.txt`, `final-verification.txt` |
| Baseline and safety rails | `baseline.txt`, `baseline-suite.log`, `db-backup.txt`, `ir1-plumbing.txt` |

---

## 5. Sign-off

| Check | Result |
|---|---|
| QA16-01 … QA16-16 | ☑ 16 / 16 passed · 0 failed · 0 blocked |
| Automated gates | ☑ `build_install_sql --check` 0 · suite **816 / 816** · smoke **195 / 195** |
| Evidence folder | ☑ complete (see §4) |
| Security review | ☑ green (`ir8-security-review.txt`) |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-12 |

The Phase 16 checks were signed from the automated suite, the HTTP smoke run, the IR-2 … IR-7
evidence and the IR-8 final verification pass. No defect was raised; the only accepted deviations
are the documented ones in `docs/phase-log.md` (the installer's pre-bundle demo/catalogue
limitation, the Windows lock-file ACL, and the `--reset-catalogue` + customized-seed residual case).
