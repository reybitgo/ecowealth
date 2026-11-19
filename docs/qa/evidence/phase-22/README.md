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

Each check in [`../phase-22-product-binary.md`](../phase-22-product-binary.md) §8 names its evidence file. Paste text transcripts into the matching file; name screenshots `QA22E-nn-<short-name>.png`.

Section E (Phase 22E — Admin visibility) evidence files:

- `evidence/phase-22/QA22E-01-*.txt` — the binary ledger header counters (credited / pending / awards / pairs) and its award rows for the QA22D awards.
- `evidence/phase-22/QA22E-02-*.txt` — the `kind=pair` filter (one row), the `side=right` filter (buyerB rows), their intersection, and a bogus value falling back to "no filter".
- `evidence/phase-22/QA22E-03-*.txt` — cancelling a paid order: the cancelled/voided rows, the recovered amount, and the header *Unrecovered* shortfall.
- `evidence/phase-22/QA22E-04-*.txt` — clicking a row's order link opens `/admin/orders/{id}` with the binary card; clicking the wallet link opens the movement.
- `evidence/phase-22/QA22E-05-*.txt` — the filtered CSV: header row matches the screen, one row per filtered award, values agree with the screen.
- `evidence/phase-22/QA22E-06-*.txt` — a `payment_review` order and a pre-22D order: each card explains nothing is placed, no rows created.
- `evidence/phase-22/QA22E-07-*.txt` — `/admin/reports`: the five binary cards match the ledger totals; the `type=binary` range CSV carries the binary rows; the dashboard **Product binary (30 d)** tile shows the 30-day total.
- `evidence/phase-22/QA22E-08-*.txt` — `/admin/members/{id}`: the binary panel shows the node, balances and blocked-pair reason for a placed member; "Not placed in the binary tree" for a member without a node; *Open in tree* disabled with a tooltip.
- `evidence/phase-22/QA22E-09-*.txt` — the **Repeat-purchase bonus** and **Royalty** tabs are byte-identical to the 22D release; no binary content leaked in.

A check whose only output is a count, a SQL row or a rendered fragment is a text transcript. No check in section E moves money or writes an award, a volume row, a node balance or a wallet row (Rule 1).
