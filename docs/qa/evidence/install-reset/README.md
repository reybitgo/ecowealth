# Install & Reset evidence — naming convention

Evidence for the IR workstream (`dmp/plan-ecowealth-install-reset.md`). This folder is the
single place the IR phase gates look for proof; every manual gate item names one of the
files below.

> `docs/qa/` is for the application's Phases 1–16. This folder is the follow-on
> install/reset workstream (IR-0 … IR-8). Do not mix the two; Phase 16's QA document
> (`docs/qa/phase-16-install-reset.md`) cites the files below.

## Rules

1. **One file per gate state.** A retake replaces the file (same name) so a gate always
   points at current evidence; superseded copies go to `archive/` as
   `<name>-<yyyy-mm-dd>.<ext>`, never stay beside the live file.
2. **Screenshots** are full browser windows **with the address bar visible**, PNG,
   1440×900. Mobile variants are 390×844 and end in `-mobile.png`. Crop only menus that
   would otherwise expose unrelated private data.
3. **Text logs** are UTF-8, LF. Start with the date, the exact command and the exit code,
   then paste the output verbatim. Strip ANSI colour codes only; never edit the numbers.
4. **Never** put a password, password hash, install key, session cookie, CSRF token, full
   DSN or `config.local.php` content in this folder. The tools must not print them; if a
   screenshot shows one, retake it.
5. **Names are stable and referenced from the phase gates.** Do not rename a file in this
   table without updating the plan and the QA doc that cites it.
6. Screenshots may be taken in a real browser or headless Chrome; state which one in the
   log/README row when it matters.

## Files

### IR-0 — baseline and safety rails

| File | What it proves |
|---|---|
| `baseline.txt` | Starting point: versions, commit, 27 tables + 4,287 rows, 45 settings, suite result, F-01 reproduced, frozen names/flags. |
| `baseline-suite.log` | Full `php tests/run.php` output behind the gate: `Cases: 724   Passed: 724   Failed: 0`, exit 0. |
| `db-backup.txt` | Pre-work dump command/checksum and the restore drill (27/27 tables, 4,287/4,287 rows). |

### IR-1 — shared database plumbing and test harness

| File | What it proves |
|---|---|
| `ir1-plumbing.txt` | One-time provisioning of `ecowealth_sneat_tool_a_test` / `ecowealth_sneat_tool_b_test` and their grants, the two filtered gate runs (`SqlScript`, `DbTarget`, exit 0) and `SHOW DATABASES LIKE 'ecowealth_sneat_tool_%_test'` after the tool runs: both schemas still exist, recreated rather than dropped. |
| `ir1-suite.log` | Full `php tests/run.php` output behind the gate: the new groups pass and the suite ends `Cases: 751   Passed: 751   Failed: 0`, exit 0. |

### IR-2 — production install bundle

| File | What it proves |
|---|---|
| `install-sql-mysql.txt` | `mysql <db> < database/install.sql` transcript: guard passed, footer counts 27/48/2/0/7/2/18/10/0, exit 0; then the app boots against that schema and the seeded admin signs in (7 products / 2 packages / 0 members on the pages). |
| `install-sql-phpmyadmin-import.png` | phpMyAdmin 5.2.2 imported the same bundle into `ecowealth_sneat_install_pma_test`: "Import has been successfully finished, 54 queries executed. (install.sql)". Headless Chrome, 1440×900. |
| `install-sql-phpmyadmin.png` | phpMyAdmin ran the footer verification pack and rendered the counts 27 / 48 / 2 / 0 / 7 / 2 / 18 / 10 / 0 on the same database. Headless Chrome, 1440×900. |
| `install-sql-guard.txt` | Re-import into a non-empty schema fails with `ecowealth_install_requires_an_empty_database` (ERROR 1146, exit 1) and the 27/48/2 state is unchanged. |
| `build-install-check.txt` | `php bin/build_install_sql.php --check` exit 0; a stale-bundle simulation exits 1 with the first differing line; the rebuild restores the exact bytes. |
| `ir2-suite.log` | Full `php tests/run.php` output behind the gate: `Cases: 761   Passed: 761   Failed: 0`, exit 0 (751 existing + 10 new). |

### IR-3 — CLI installer

| File | What it proves |
|---|---|
| `install-cli-check.txt` | `php bin/db_install.php --check` preflight listing and exit code. |
| `install-cli-success.txt` | Guided install on an empty scratch schema: verification counts, `storage/install.lock` written, exit 0. |
| `install-cli-success.json` | The same run with `--json`: one document, `"ok": true`, `tool: db_install`. |
| `install-cli-refused.txt` | Locked/non-empty target refused before any write (exit 3), no lock overwritten. |

### IR-4 — web installer

| File | What it proves |
|---|---|
| `install-preflight.png` | Preflight page on the empty `ecowealth_sneat_install_web_test`: every visible check green, database name shown, form below. Headless Chrome, 1440×900. |
| `install-preflight-details.png` | All 15 preflight checks (including storage, lock and `INSTALL_KEY`) plus the enabled install form. Headless Chrome, 1440×1500 (checks + form do not fit in 900 px). |
| `install-success.png` | Success page: "Installation complete", VERIFIED badge, the §6.5 counts and the lock facts. Headless Chrome, 1440×900. |
| `install-success-full.png` | The whole success page: counts, both account usernames/emails (**no passwords**), the 10-check verification table, warnings and next steps. Headless Chrome, 1440×2153. |
| `install-locked.png` | Reopening the installer after success shows "Already installed" and the lock details, with no form. Headless Chrome, 1440×900. |
| `install-nonempty.png` | The lock removed, the schema still populated: "Database not empty", no form, no write. Headless Chrome, 1440×900. |
| `install-key-refused.png` | Production with no `INSTALL_KEY`: the red PRODUCTION badge and the start of the preflight. The failing key row and the disabled form are in the full capture below. Headless Chrome, 1440×900. |
| `install-key-refused-full.png` | The full production refusal: the `FAIL INSTALL_KEY` row, the "INSTALL_KEY is not configured" alert and the disabled form. Headless Chrome, 1440×1500. |
| `install-key-wrong.png` | Production with a key configured: a wrong key re-renders the form with "does not match … 4 attempts left" and writes nothing. Headless Chrome, 1440×1400. |
| `install-key-throttle.png` | Five wrong keys in one session: "Too many install-key attempts. Wait 15 minutes before trying again." Headless Chrome, 1440×1400. |
| `install-mobile.png` | The preflight/form at a true 390×844 viewport (DevTools device override; Chrome's `--window-size` floor is 500 px). |
| `install-mobile-full.png` | The whole installer at 390 px: all checks, every form field and the disabled demo option. |
| `install-web.txt` | The acceptance transcript: commands, HTTP results, counts, lock JSON, the production wrapper (no `config.local.php` edit), the throttle and the cleanup. |
| `ir4-suite.log` | Full `php tests/run.php` output behind the gate: `Cases: 781   Passed: 781   Failed: 0`, exit 0 (771 existing + 7 `DbToolAccess` + 3 `InstallTest`). |
| `ir4-smoke.log` | Full `tests/smoke.ps1` run against the Apache app: `Passed: 190   Failed: 0`, exit 0, including IR4-T1…T5 (installer refusal, hardening headers, no lock writes, CSRF-less POST, stylesheet). |

### IR-5 — reset engine and CLI

| File | What it proves |
|---|---|
| `reset-cli-preview.txt` | `--dry-run` on the fresh QA fixture: counts, staff, catalogue, the 46-statement plan, `writes: 0`, exit 0, nothing changed. |
| `reset-cli-success.txt` | Real reset (`--force --confirm=… --codes=5`) on a scratch schema: step log, counts before/after, 12/12 verification checks, five fresh codes, exit 0; then `wallet_reconcile.php` exit 0 and the state query (0 members · 2 staff · 5 codes · 0 orders · 7 products). |
| `reset-cli-success.json` | A second scratch run with `php bin/db_reset.php --force --confirm=ecowealth_sneat_reset_manual --codes=2 --json` (exit 0): one JSON document, `tool: db_reset`, `"ok": true`, the codes and verification blocks. |
| `reset-cli-refused.txt` | Refusals that write nothing: `--force` without `--confirm` (exit 2), a wrong confirmation (exit 3), `--demo` before IR-7 (exit 2); the state query is unchanged after all three. |
| `reset-cli-production.txt` | The production guard exercised through `options['env']` (the `DbResetTest` case) — the local `APP_ENV` is `development`, so no real production database is involved. |
| `reset-app-smoke.txt` | After the reset: the app boots against the scratch schema, the seeded admin signs in (HTTP 302), and `/dashboard`, `/admin`, `/admin/members` ("No members yet"), `/admin/orders` ("No orders yet"), `/admin/codes`, `/shop`, `/admin/packages` and `/admin/products` (7 SKUs) all return 200. |
| `ir5-suite.log` | Full `php tests/run.php` output behind the gate: `Cases: 803   Passed: 803   Failed: 0`, exit 0 (781 existing + 22 `DbResetTest`). |

### IR-6 — reset web console

| File | What it proves |
|---|---|
| `reset-form.png` | Ready state on `ecowealth_sneat` as `admin`: stats (24 members / 16 orders / 48 ledger / 19 codes / 10 products), "what will be cleared" with red/amber/green dots, options, typed `RESET` confirmation, disabled Reset button. Full page. Headless Chrome via DevTools protocol, 1440 px wide. |
| `reset-validation.png` | A crafted POST with `confirm=WRONG` and the backup ack ticked re-renders the form with "Nothing was reset" and "Type RESET exactly to confirm"; no row changes. Headless Chrome, 1440×1624 (full page). |
| `reset-success.png` | Success report on `ecowealth_sneat_reset_web_test`: green banner, 23-step log, 12/12 verification checks, five click-to-copy codes, before/after counts and the "what now" actions. Full page. |
| `reset-production-404.png` | Production with `DB_RESET_ENABLED` left `false`: the branded 404, not the console. Headless Chrome, 1440×900. |
| `reset-member-403.png` | A signed-in member (`juan`) gets 403 "Access denied" and never sees the form. Headless Chrome, 1440×900. |
| `reset-impersonation-403.png` | An S-Login tab (superadmin viewing `juan`, URL `reset.php?imp=<nonce>`) gets 403 before any preview or form. Visually identical to the member 403 by design; the transcript records the exact URL and status. Headless Chrome, 1440×900. |
| `reset-production-form.png` | Production with `DB_RESET_ENABLED=true`, signed in as `superadmin`: the PRODUCTION band, the database name (`ecowealth_sneat_reset_prod_test`) as the confirmation phrase, and the demo checkbox absent. Full page. |
| `reset-production-admin-403.png` | The same production-enabled setup signed in as `admin` (not superadmin) is refused with 403. Headless Chrome, 1440×900. |
| `reset-mobile.png` | The ready console at a true 390×844 viewport (CDP device metrics); header, stats and the start of the cleared list stack without horizontal overflow. |
| `reset-web.txt` | The ten-state matrix with exact URLs/statuses, the wrong-phrase / missing-ack / missing-CSRF refusals, the successful scratch reset, the refresh-does-not-rerun proof, the HTTP and database counts, and the cleanup. |
| `ir6-suite.log` | Full `php tests/run.php` output behind the gate: `Cases: 808   Passed: 808   Failed: 0`, exit 0 (803 existing + 5 new `DbToolAccess` cases). |
| `ir6-smoke.log` | Full `tests/smoke.ps1` run against the Apache app: `Passed: 195   Failed: 0`, exit 0, including IR6-T1…T5 (guest redirect, member 403, admin form with no secret, three refusals with unchanged counts, reset console stylesheet). |

### IR-7 — demo fixtures and parity

| File | What it proves |
|---|---|
| `reset-demo.txt` | `--demo` run on a clean scratch install: the demo step creates 5 members / 3 codes / 3 ledger rows, the verification's Demo fixtures row passes (wallets 2000/1500, 1000/800, 500/400; david pending), the production request is refused before any delete with the state unchanged, and the same refusals are asserted in `ir7-suite.log`. |
| `parity-reset-vs-install.txt` | Natural-key comparison of a reset schema against a fresh install bundle: both cases (reset ≡ fresh production install, reset(catalogue+demo) ≡ fresh QA install) match on staff, settings, products, packages, levels, contents and — in case 2 — the demo members, codes and ledger rows, with the excluded keys named. |
| `reset-demo-genealogy.png` | `juan` (the documented demo password) signed in after the reset: `/genealogy` renders the tree `juan → maria → peter/sarah/david` with david pending and package A. Headless Chrome, 1440 px wide. |
| `reset-demo-earnings.png` | The same demo session on `/earnings`: the unilevel breakdown and the empty awards table (the demo seed funds wallets and codes, not commissions). Headless Chrome, 1440 px wide. |
| `ir7-suite.log` | Full `php tests/run.php` output behind the gate: `Cases: 816   Passed: 816   Failed: 0`, exit 0 (808 existing + 2 `InstallTest` + 6 `DbResetTest` new cases). |
| `ir7-smoke.log` | Full `tests/smoke.ps1` run against the Apache app after the demo changes: `Passed: 195   Failed: 0`, exit 0, including the IR-4/IR-6 tool probes (the pages gained only the demo option/report card; no gate changed). |

### IR-8 — hardening, docs and go-live

| File | What it proves |
|---|---|
| `ir8-security-review.txt` | The IR-8-T1 checklist with its evidence: both tool pages carry the shared nosniff/frame-deny/CSP/referrer/permissions/no-store headers and an HttpOnly/SameSite session cookie; neither page prints a password, DSN, bcrypt hash or install key; `storage/install.lock` is outside `public/`; the tools appear in no route, nav or `routes.php`; the uploads/storage `.htaccess` guards and the hardened application session are in place; `DB_RESET_ENABLED` is false and `INSTALL_KEY` empty in this development config (both documented for the production copy). |
| `go-live-tools-404.png` | Both tool files deleted and requested on a production-env copy: `/system/install.php` and `/system/reset.php` return the branded 404, never the tool. Headless Chrome, 1440×900. |
| `go-live-checklist.txt` | The signed go-live checklist against a scratch production-like install: the Appendix E verification pack (27 / 48 / 2 / 0 / 7 / 2 / 18 / 10 / 0), the two demo-leakage queries at 0, the default-password check, `storage/install.lock` present and not web-readable, `INSTALL_KEY` removed, `DB_RESET_ENABLED` absent/false, and the tool-404 results. |
| `final-verification.txt` | The IR-8-T4 command transcript in order: `build_install_sql --check`, a scratch `mysql` import with the footer counts and app boot, a CLI install + lock, a web-installer run on another fresh schema, a CLI reset + `wallet_reconcile` exit 0, and the phpMyAdmin attestation for the same bundle bytes. |
| `ir8-suite.log` | Full `php tests/run.php` output behind the IR-8 gate: `Cases: 816   Passed: 816   Failed: 0`, exit 0 (no new cases in IR-8). |
| `ir8-smoke.log` | Full `tests/smoke.ps1` run against the Apache app behind the IR-8 gate: `Passed: 195   Failed: 0`, exit 0. |

## Capture template for a text log

```
<yyyy-mm-dd HH:mm:ss zzz> — IR-<n> <gate item>
Host: <Windows version> · PHP <version> · MySQL <version> · schema <name>
Command: <exact command; no secrets>
Exit: <code>

<verbatim output>
```

A screenshot's README row should say what state it proves, not only the filename.
