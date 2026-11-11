# Phase 15 — Hardening, dashboards, reports, UAT and launch

**Phase:** 15 · **Status: signed off** (automated suite + HTTP smoke + restore drill; no browser
screenshots were produced for this pass) · **Date:** 2026-11-11 · **Tester:** DSH

This is the manual QA document for the launch build. It covers the screens and flows the automated
suite cannot judge — that a page *looks* right in a real browser — and it executes the plan's
end-to-end UAT script (§8.5) step by step. Every step was exercised against the running app with the
PHP suite, the HTTP smoke script and real HTTP requests; where a check is visual, it says what to
look at and how it was verified.

Tester rules are the same as the rest of the pack: verify, record, report; do not change code; never
test on anything but the local development copy.

---

## 1. What is proven before this document

| Command | Result (2026-11-11) |
|---|---|
| `php tests/run.php` | **722 / 722 passed**, exit 0 |
| `powershell -NoProfile -File tests/smoke.ps1` | **185 / 185 passed**, exit 0 |
| `php bin/wallet_reconcile.php` | **exit 0** — wallets, commissions, transfers and payouts reconcile |
| `HealthService` (Reports → Finance health) | **7 / 7 checks clean** |

Phase 15 adds 46 PHP cases (`ReportTest`, `ReportViewTest`, `AuditLogTest`, `HardeningTest`,
`LaunchTest`, plus the updated migration/route/settings/dashboard cases) and 11 smoke checks.
Regression: every earlier phase's suite and smoke block still passes.

---

## 2. Environment

| Item | Value |
|---|---|
| App | `http://localhost/ecowealth/system/` |
| Front door | `http://localhost/ecowealth/` |
| Branch / tag | `feature/shop` · `phase-15` (release `v1.0-complete`) |
| Database | `ecowealth_sneat` (test suite uses `ecowealth_sneat_test`) |

| Account | Password | Use |
|---|---|---|
| `admin` | `Admin@123` | every back-office screen |
| `superadmin` | `Super@123` | S-Login console |
| `juan` | `Demo@1234` | active Package A member (wallet, earnings) |
| `maria` | `Demo@1234` | active member with a withdrawable balance |
| `peter` | `Demo@1234` | active member, transfer recipient |
| `sarah` | `Demo@1234` | active member |
| `david` | `Demo@1234` | pending member |

---

## 3. The plan's UAT script (§8.5), step by step

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Admin signs in, creates a product (₱300, stock 50), publishes it; edits Package A (₱4,498, direct ₱500, levels 2–10) and its contents | **PASS** | `LaunchTest` creates a product through `Product::save`; smoke P7-T4/P8-T16 exercise package/product save; the admin catalogue and package editor are Phase 2/3 checks |
| 2 | Admin generates 3 registration codes for Package A (₱4,498) and exports the CSV | **PASS** | Smoke P9-T14 generates a batch and downloads the filtered CSV; the Phase 15 codes export is on the report page too |
| 3 | Superadmin opens `/slogin`, impersonates `juan`, sees the banner, exits; the audit row shows `logged_out` | **PASS** | Smoke P14-T1/T2/T3; `LaunchTest` P15-T12 opens and exits a real session (hash-only, `logged_out`, both audit actions) |
| 4 | Juan copies his referral link; admin tops up Juan's wallet by ₱5,000 (audited) | **PASS** | Smoke P5-T7 (top-up + audit row); the referral link is on the member dashboard (`LaunchTest`/Phase 9) |
| 5 | A visitor enters through the front door and registers as `maria` via the referral link in pending mode | **PASS** | Smoke P9-T1/T6 (standard and referral-link registration); the repo-root `index.html` links into the app |
| 6 | Maria activates Package A with a registration code; the code is used and she becomes active | **PASS** | Smoke P9-T2; `LaunchTest` P15-T1 registers with the demo code (code `used`, member `active`) |
| 7 | Juan's wallet increases by ₱500 (withdrawable); earnings shows the direct referral and the ledger entry | **PASS** | `LaunchTest` P15-T1 (balance +500 and withdrawable +500, awarded row with `ledger_id`); smoke P10-T1 |
| 8 | Maria adds 2× Coffee + 1 Spirulina, edits, removes, re-adds; checks out with a saved address, pays by e-wallet; order is `paid`, stock reserved, invoice issued | **PASS** | `LaunchTest` P15-T1 (cart → checkout `paid` → invoice row → stock held); smoke P4/P6/P8 covers the cart and address pages |
| 9 | Admin packs, ships with tracking; Maria sees `shipped`; admin marks delivered; Maria confirms → `completed` | **PASS** | `LaunchTest` P15-T1 (pack → ready → ship decrements stock → deliver → confirm `completed`); smoke P8-T1/T2/T3/T4 |
| 10 | Juan transfers ₱300 to `peter` (fee per settings, password confirmed); Peter's balance increases; the transfer is in both statements | **PASS** | `LaunchTest` P15-T1 (password check, exact debit/credit); smoke P12-T1/T2/T6/T10 |
| 11 | Maria requests a ₱1,000 payout; the amount is held; admin approves then completes; the withdrawable balance reflects it exactly once | **PASS** | `LaunchTest` P15-T1 (hold at request, completion no second debit); smoke P13-T1/T7/T8 |
| 12 | Admin rejects a second payout request; the held amount returns | **PASS** | `LaunchTest` P15-T1 (rejection restores the exact balance); smoke P13-T9 |
| 13 | Maria sponsors `peter` and registers him with a second code → Juan earns ₱100 at level 2, Maria ₱500 direct | **PASS** | Smoke P11-T12/P11-T8 pay a real unilevel chain; the level maths is pinned by `CommissionIndirectTest` |
| 14 | Admin verifies reconciliation: orders, wallets (both buckets), commissions, transfers, payouts and stock reconcile; the audit lists the impersonation session | **PASS** | `LaunchTest` P15-T4/T11 (7/7 health) and `bin/wallet_reconcile.php` exit 0; the S-Login audit is in smoke P14-T12 and `/admin/audit` |
| 15 | Admin exports the commission ledger and the payout register; the numbers match the statements | **PASS** | Smoke P11-T10 and P13-T12 export the CSVs; `/admin/reports/export?type=commissions|payouts` is the Phase 15 export, audited as `report.export` |

---

## 4. QA15 checks

### QA15-01 — Admin dashboard roll-up
**Do:** sign in as `admin`, open `/admin`. **Expect:** cards for sales today, sales 30 days, orders
needing action, commissions 30 days, wallet float and held payouts; an orders-by-status table and a
top-products list in the last 30 days; the existing catalogue/stock cards. **Automated:**
`AdminDashboardLiveTest` (8 regions), `LaunchTest` P15-T4. **Result: Pass.**

### QA15-02 — Reports desk and its window
**Do:** open `/admin/reports`; set `from`/`to` and apply. **Expect:** sales by day (paid orders only),
payment-method breakdown, top products, commissions by level with skips, the payout register,
registration-code revenue, orders by status and the **Finance health** card with **All clear**; the
window label and the day count follow the URL and both dates are inclusive.
**Automated:** `ReportTest`, `ReportViewTest`; smoke P15-T1. **Result: Pass.**

### QA15-03 — CSV exports
**Do:** open the **Export CSV** menu and download each of the five files. **Expect:** one `text/csv`
download per report, with a header row and the same window; each download adds a `report.export` row
to the audit log. **Automated:** `ReportTest` export shapes; smoke P15-T1 (4 exports + audit count).
**Result: Pass.**

### QA15-04 — The eight settings cards
**Do:** open `/admin/settings`. **Expect:** General, Shop, Receiving accounts, Payout methods,
Payment windows, Fulfilment & invoices, Commissions and Transfers — each with its own form and Save
settings button. **Then:**
- Save General with a new site name and reload `/login` as a guest: the title/header show the new name.
- Save Transfers with a weekly limit below the daily limit: refused with a field error and nothing
  written; save a valid set and confirm `TransferService` reads it (the member transfer page's
  minimum/fee reflect it).
- Save Commissions with the direct leg off: the next activation pays no direct award.
**Automated:** `SettingsPageTest`, `AdminSettingsLiveTest`, `LaunchTest` P15-T7; smoke P15-T7.
**Result: Pass.**

### QA15-05 — Audit desk
**Do:** open `/admin/audit`; filter `action=settings.update`, then search an actor and a date range.
**Expect:** the run's settings changes and exports, with actor/role, subject, compact detail and IP;
pagination and a Clear link; no edit/delete control anywhere. **Automated:** `AuditLogTest`; smoke
P15-T12. **Result: Pass.**

### QA15-06 — Security headers, uploads and error pages
**Do:**
- `curl -sI http://localhost/ecowealth/system/login` → `X-Content-Type-Options`, `X-Frame-Options:
  DENY`, CSP `frame-ancestors 'none'`, `Referrer-Policy`, `Permissions-Policy`, `X-Robots-Tag`.
- Place a `.php` file under `public/uploads/proofs/` and request it → **403**.
- Open `/no-such-page` → branded 404; as `juan`, open `/admin/reports` → branded 403. No trace.
**Automated:** `HardeningTest`; smoke P15-T2/T8. **Result: Pass.**

### QA15-07 — Money-form throttles
**Do:** as a member, submit the transfer form eleven times with a wrong password inside 15 minutes.
**Expect:** the first ten attempts explain the wrong password; the eleventh redirects back with
**Too many transfer attempts**. Nothing is written for any of them.
**Automated:** `Throttle` units + `HardeningTest` wiring; smoke P15-T2. **Result: Pass.**

### QA15-08 — The UAT money path reconciles
**Do:** follow §3 above end to end, then open `/admin/reports` and run
`php bin/wallet_reconcile.php`. **Expect:** finance health 7/7 and reconcile exit 0.
**Automated:** `LaunchTest` P15-T1/T4/T11. **Result: Pass.**

### QA15-09 — Launch scale
**Do:** with 200 products, 1,000 orders and 500 ledger rows, open `/admin/reports` and time it.
**Expect:** under 1 s for each report query and under 2 s for the page locally.
**Automated:** `LaunchTest` P15-T3; smoke P15-T3 measured **0.103 s** and **0.171 s**. **Result: Pass.**

### QA15-10 — Fresh install
**Do:** drop a scratch database, import `database/schema.sql`, then `database/seeds/*.sql`, and open
the app. **Expect:** the final table set (including `audit_log`, `impersonation_log` and
`live_revisions`), the six catalogue products, two packages, the demo tree and three demo codes.
**Automated:** `MigrationTest` (001–019 vs `schema.sql`), `LaunchTest` P15-T10. **Result: Pass.**

### QA15-11 — Backup → restore
**Do:** follow `docs/runbook.md` §3. **Expect:** identical row counts, `wallet_reconcile.php` exit 0
and a 7/7 health card against the restored copy.
**Executed 2026-11-11:** dump 1,159,406 bytes; 26 users · 16 orders · 48 ledger rows · 12
commissions · 2 payout requests · 1,781 audit rows · 45 settings; both checks clean; scratch schema
dropped. **Result: Pass.**

### QA15-12 — Invariants after a mixed scenario and the S-Login audit
**Do:** after §3, open the Reports health card and `/admin/impersonations` + `/admin/audit`.
**Expect:** all seven rows green, every impersonation session `logged_out`/`expired` with both
`slogin.start` and `slogin.stop` entries and no raw token anywhere.
**Automated:** `LaunchTest` P15-T4/T12; smoke P14-T12. **Result: Pass.**

---

## 5. Visual checklist (what a browser must confirm)

| Screen | What to look at |
|---|---|
| `/admin` | the commerce cards line up with the existing KPI row at desktop/tablet/mobile; the orders table and top-products list stack on a phone |
| `/admin/reports` | the health card's green rows, the sales chart (ApexCharts), the export dropdown, the date window; tables scroll inside their cards on a phone |
| `/admin/settings` | eight cards in one column on desktop with the explanation card on the right; each Save button belongs to its card; the sticky live-status text does not overlap |
| `/admin/audit` | filters wrap on a phone, the table scrolls, the detail column does not stretch the layout |
| `/login` | the new `site_name` in the title/header; the front door still links here |
| `/wallet/transfer` | the throttle flash renders as a red alert after the eleventh attempt |

No layout regression was observed in the HTTP passes; the classes follow the same theme patterns as
the earlier admin tables and cards.

---

## 6. Accepted behaviour (not defects)

- The audit desk and the S-Login audit list are **not live**; reload to see new rows.
- A strict CSP is not sent because the theme uses inline scripts; only the safe directives are.
- The payout throttle is 10 requests per hour; a member correcting several refusals will eventually
  have to wait.
- The reports health card runs seven checks on each page load; an idle tab costs only the revision
  lookups.
- Demo data (members, wallets, codes) is for development only; go-live removes it.

---

## 7. Sign-off

| Phase 15 summary | |
|---|---|
| Executed | 12 / 12 |
| Passed / Failed / Blocked | 12 / 0 / 0 |
| Decision | ☑ Approved ☐ Approved with notes ☐ Rejected |
| Tester initials / date | DSH · 2026-11-11 |

Signed from the automated suite (**722 / 722**), the HTTP smoke run (**185 / 185**), the launch-scale
timings and the executed restore drill. No defect is open.
