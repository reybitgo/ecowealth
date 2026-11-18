# Phase 22 evidence index

This folder holds the evidence for Phase 22 (Product binary bonus) manual checks:

- `evidence/phase-22/QA22A-01-*.txt` — pre-22A backup and table/setting counts on the development copy.
- `evidence/phase-22/QA22A-02-*.txt` — migration 026 applied twice on a scratch schema; column lists for `binary_nodes`, `binary_volume`, `binary_awards` and the altered tables.
- `evidence/phase-22/QA22A-03-*.txt` — the ten binary settings and their defaults; stream off.
- `evidence/phase-22/QA22A-04-*.txt` — `/admin/settings`, `/admin/products/{id}/edit` and `/wallet` rendered exactly as at `p22-start`; wallet filter names Product binary / Product binary reversal.
- `evidence/phase-22/QA22A-05-*.txt` — paid order writes zero `binary_nodes`, `binary_volume`, `binary_awards` and `binary`/`binary_reversal` ledger rows on a scratch schema.
- `evidence/phase-22/QA22A-06-*.txt` — wallet statement filter names both movements; selecting either returns no rows; no PHP warning in `storage/logs/`.
- `evidence/phase-22/QA22A-07-*.txt` — `build_install_sql.php --check` exits 0; fresh install prints `tables_created = 42`, `settings_rows = 89`; `wallet_reconcile.php` exits 0.
- `evidence/phase-22/QA22A-08-*.txt` — business reset empties `binary_nodes`, `binary_volume`, `binary_awards`; the ten settings survive.

Each check in [`../phase-22-product-binary.md`](../phase-22-product-binary.md) §5 names its evidence file. Paste text transcripts into the matching file; name screenshots `QA22A-nn-<short-name>.png`.

A check whose only output is a count, a SQL row or a rendered fragment is a text transcript. No check in section A moves money or touches the development database except the page-read checks (`QA22A-04`, `QA22A-06`), which are read-only.
