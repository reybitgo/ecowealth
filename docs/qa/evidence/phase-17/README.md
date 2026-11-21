# Phase 17 — evidence index

This folder holds the manual evidence for the seven Phase 17 gates
(`dmp/plan-ecowealth-unilevel-repeat-purchase.md`). One file per check, named after the check:
a screenshot for a browser observation, a text transcript for a count or a command.

## Naming

| Check kind | File name | Format |
|---|---|---|
| Browser page or panel | `QA17<X>-<nn>-<short-name>.png` | PNG, full window, URL visible |
| SQL count / `SHOW` output | `QA17<X>-<nn>-<short>.txt` | plain text, the query **and** its result |
| Command + exit code | `QA17<X>-<nn>-<short>.txt` | the command line, its output and `exit 0` |

Examples: `QA17A-01-tables.txt`, `QA17A-06-wallet-filter.png`, `QA17B-03-ladder-card.png`.

## What must never be in here

- A password, `INSTALL_KEY`, session cookie, DSN or database user password.
- A screenshot of a real member's personal data (use the demo tree: `juan`, `maria`, `peter`, `sarah`,
  `david` with `Demo@1234`).
- A dump of a whole database.

## Contents

| Phase | Section | Checks | Files |
|---|---|---|---|
| 17A Foundation | A | `QA17A-01` … `QA17A-08` | `QA17A-01-tables.txt`, `QA17A-02-columns.txt`, `QA17A-03-settings.txt`, `QA17A-04-admin-settings.png`, `QA17A-04-product-edit.png`, `QA17A-04-admin-settings.txt`, `QA17A-04-product-edit.txt`, `QA17A-05-order.txt`, `QA17A-05-awards-count.txt`, `QA17A-06-wallet-filter.png`, `QA17A-06-wallet-reversal.png`, `QA17A-06-wallet-filter.txt`, `QA17A-07-verification.txt`, `QA17A-08-reset.txt`, `QA17A-dev-db-migration.txt` |
| 17B Configuration | B | `QA17B-01` … `QA17B-09` | `QA17B-01-product-edit.txt`, `QA17B-02-saved-ladder.txt`, `QA17B-03-sum-error.txt`, `QA17B-04-empty-ladder.txt`, `QA17B-05-pv-base.txt`, `QA17B-06-products-list.txt`, `QA17B-07-settings.txt`, `QA17B-08-no-js.txt`, `QA17B-09-order.txt` |
| 17C Money engine | C | `QA17C-01` … `QA17C-10` | `QA17C-01-order.txt`, `QA17C-02-wallets.txt`, `QA17C-03-admin-wallet.txt`, `QA17C-04-reconcile.txt`, `QA17C-05-manual.txt`, `QA17C-06-skipped.txt`, `QA17C-07-reversal.txt`, `QA17C-08-active.txt`, `QA17C-09-kill-switch.txt`, `QA17C-10-close.txt` |
| 17D Admin visibility | D | `QA17D-01` … `QA17D-08` | `QA17D-00-setup-cleanup.txt`, `QA17D-01-ledger.txt`, `QA17D-02-filters.txt`, `QA17D-03-reversal.txt`, `QA17D-04-order-wallet.txt`, `QA17D-05-export.txt`, `QA17D-05-export.csv`, `QA17D-06-order-states.txt`, `QA17D-07-reports.txt`, `QA17D-08-package-tab.txt` |
| 17E Member visibility | E | `QA17E-01` … `QA17E-09` | `QA17E-00-setup-cleanup.txt`, `QA17E-01-product-panel.txt`, `QA17E-02-checkout-preview.txt`, `QA17E-03-order-outcome.txt`, `QA17E-04-earnings-tab.txt`, `QA17E-05-filters-export.txt`, `QA17E-05-export.csv`, `QA17E-06-package-regression.txt`, `QA17E-07-buyer-view.txt`, `QA17E-08-dashboard-membership.txt`, `QA17E-09-mobile.txt`, `QA17E-09-product-390.png`, `QA17E-09-product-390-full.png`, `QA17E-09-earnings-390.png`, `QA17E-09-earnings-390-full.png` |
| 17F Operations | F | `QA17F-01` … `QA17F-07` | `QA17F-00-setup-cleanup.txt`, `QA17F-01-doctor.txt`, `QA17F-02-dry-run.txt`, `QA17F-03-close.txt`, `QA17F-04-idempotent.txt`, `QA17F-05-period.txt`, `QA17F-06-web.txt`, `QA17F-07-cmd.txt` |
| 17G Release | G | `QA17G-01` … `QA17G-06` | `QA17G-00-setup-cleanup.txt`, `QA17G-01-suite.txt`, `QA17G-01-smoke.txt`, `QA17G-01-build.txt`, `QA17G-01-reconcile.txt`, `QA17G-02-cold-read.txt`, `QA17G-03-acceptance-table.txt`, `QA17G-04-phase.txt`, `QA17G-05-deploy.txt`, `QA17G-05-runbook.txt`, `QA17G-06-evidence.txt` |

The check text lives in [`../phase-17-unilevel-repeat-purchase.md`](../phase-17-unilevel-repeat-purchase.md).
Evidence is filed by the tester named in that document's sign-off block; the project lead compares the
files with the phase log before closing a gate.
