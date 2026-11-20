# Phase 22 evidence index

This folder holds the evidence for Phase 22 (Product binary bonus) manual checks:

## Section A — Phase 22A: Foundation (`QA22A-01` … `QA22A-08`)

- `evidence/phase-22/QA22A-01-*.txt` — pre-22A backup and table/setting counts on the development copy.
- `evidence/phase-22/QA22A-02-*.txt` — migration 026 applied twice on a scratch schema; column lists for `binary_nodes`, `binary_volume`, `binary_awards` and the altered tables.
- `evidence/phase-22/QA22A-03-*.txt` — the ten binary settings and their defaults; stream off.
- `evidence/phase-22/QA22A-04-*.txt` — `/admin/settings`, `/admin/products/{id}/edit` and `/wallet` rendered exactly as at `p22-start`; wallet filter names Product binary / Product binary reversal.
- `evidence/phase-22/QA22A-05-*.txt` — paid order writes zero `binary_nodes`, `binary_volume`, `binary_awards` and `binary`/`binary_reversal` ledger rows on a scratch schema.
- `evidence/phase-22/QA22A-06-*.txt` — wallet statement filter names both movements; selecting either returns no rows; no PHP warning in `storage/logs/`.
- `evidence/phase-22/QA22A-07-*.txt` — `build_install_sql.php --check` exits 0; fresh install prints `tables_created = 42`, `settings_rows = 89`; `wallet_reconcile.php` exits 0.
- `evidence/phase-22/QA22A-08-*.txt` — business reset empties `binary_nodes`, `binary_volume`, `binary_awards`; the ten settings survive.

## Section B — Phase 22B: Configuration and readiness (`QA22B-01` … `QA22B-09`)

- `evidence/phase-22/QA22B-01-*.txt` — the product edit card renders off by default; no per-product pair controls.
- `evidence/phase-22/QA22B-02-*.txt` — flag a product and save; the switch persists; the badge reads `Binary N pts`.
- `evidence/phase-22/QA22B-03-*.txt` — binary on with P.V. 0 is refused with the field error.
- `evidence/phase-22/QA22B-04-*.txt` — the product list badge and the `?binary=on|off` filter.
- `evidence/phase-22/QA22B-05-*.txt` — the settings card: all nine keys, toggles, validation, audit.
- `evidence/phase-22/QA22B-06-*.txt` — the readiness panel counts and links.
- `evidence/phase-22/QA22B-07-*.txt` — the stream is on but still pays nothing.
- `evidence/phase-22/QA22B-08-*.txt` — the product form works without JavaScript.
- `evidence/phase-22/QA22B-09-*.txt` — member pages show no binary content.

## Section C — Phase 22C: Placement, the tree and registration (`QA22C-01` … `QA22C-10`)

- `evidence/phase-22/QA22C-01-*.txt` — every registration creates exactly one legal node; invalid placements roll back.
- `evidence/phase-22/QA22C-02-*.txt` — the admin placement console and the audited move route; exactly one placement route.
- `evidence/phase-22/QA22C-03-*.txt` — the backfill tool is deterministic and idempotent.
- `evidence/phase-22/QA22C-04-*.txt` — no money exists at placement.
- `evidence/phase-22/QA22C-05-*.txt` — placement guard: sponsor-tree facts unchanged.
- `evidence/phase-22/QA22C-06-*.txt` — slot uniqueness and check constraints.
- `evidence/phase-22/QA22C-07-*.txt` — the root is the company account and is never paid.
- `evidence/phase-22/QA22C-08-*.txt` — depth and path are derived correctly.
- `evidence/phase-22/QA22C-09-*.txt` — registration placement rolls back on invalid placement.
- `evidence/phase-22/QA22C-10-*.txt` — the genealogy controller's binary facts; sponsor view unchanged.

## Section D — Phase 22D: Money engine (`QA22D-01` … `QA22D-12`)

- `evidence/phase-22/QA22D-01-*.txt` — the Self Bonus on a paid order (₱50.00 pending self).
- `evidence/phase-22/QA22D-02-*.txt` — volume rolls up to every binary upline; root never paid.
- `evidence/phase-22/QA22D-03-*.txt` — pair matching consumes points and pays pairs (₱100.00 per pair).
- `evidence/phase-22/QA22D-04-*.txt` — eligibility: an ineligible recipient keeps its volume.
- `evidence/phase-22/QA22D-05-*.txt` — the daily pair cap bounds one member's day.
- `evidence/phase-22/QA22D-06-*.txt` — idempotency: a replay writes nothing twice.
- `evidence/phase-22/QA22D-07-*.txt` — escrow: release after delivery + hold_days.
- `evidence/phase-22/QA22D-08-*.txt` — cancellation reverses pending awards.
- `evidence/phase-22/QA22D-09-*.txt` — reversal returns consumed pair points without re-pairing.
- `evidence/phase-22/QA22D-10-*.txt` — stream off: a paid order writes zeros.
- `evidence/phase-22/QA22D-11-*.txt` — no node / disabled product: honest stamping.
- `evidence/phase-22/QA22D-12-*.txt` — `totalsFor()`, `reconcile()` and the engine-level audit.

## Section E — Phase 22E: Admin visibility (`QA22E-01` … `QA22E-09`)

- `evidence/phase-22/QA22E-01-*.txt` — the binary ledger header counters (credited / pending / awards / pairs) and its award rows for the QA22D awards.
- `evidence/phase-22/QA22E-02-*.txt` — the `kind=pair` filter (one row), the `side=right` filter (buyerB rows), their intersection, and a bogus value falling back to "no filter".
- `evidence/phase-22/QA22E-03-*.txt` — cancelling a paid order: the cancelled/voided rows, the recovered amount, and the header *Unrecovered* shortfall.
- `evidence/phase-22/QA22E-04-*.txt` — clicking a row's order link opens `/admin/orders/{id}` with the binary card; clicking the wallet link opens the movement.
- `evidence/phase-22/QA22E-05-*.txt` — the filtered CSV: header row matches the screen, one row per filtered award, values agree with the screen.
- `evidence/phase-22/QA22E-06-*.txt` — a `payment_review` order and a pre-22D order: each card explains nothing is placed, no rows created.
- `evidence/phase-22/QA22E-07-*.txt` — `/admin/reports`: the five binary cards match the ledger totals; the `type=binary` range CSV carries the binary rows; the dashboard **Product binary (30 d)** tile shows the 30-day total.
- `evidence/phase-22/QA22E-08-*.txt` — `/admin/members/{id}`: the binary panel shows the node, balances and blocked-pair reason for a placed member; "Not placed in the binary tree" for a member without a node; *Open in tree* disabled with a tooltip.
- `evidence/phase-22/QA22E-09-*.txt` — the **Repeat-purchase bonus** and **Royalty** tabs are byte-identical to the 22D release; no binary content leaked in.

## Section F — Phase 22F: Member visibility (`QA22F-01` … `QA22F-10`)

- `evidence/phase-22/QA22F-01-*.txt` — the binary tab renders with stats, self card, both team cards.
- `evidence/phase-22/QA22F-02-*.txt` — Left/Right team cards list the member's own team.
- `evidence/phase-22/QA22F-03-*.txt` — independent per-side roster pagination.
- `evidence/phase-22/QA22F-04-*.png` — desktop and mobile rendering screenshots.
- `evidence/phase-22/QA22F-05-*.txt` — copy guard: no upline, sponsor, Team chain, level.
- `evidence/phase-22/QA22F-06-*.txt` — checkout position card: side choice required when points exist.
- `evidence/phase-22/QA22F-07-*.txt` — order card: before and after payment.
- `evidence/phase-22/QA22F-08-*.txt` — dashboard tile and product panel.
- `evidence/phase-22/QA22F-09-*.txt` — session-scoped: a member cannot read another's tab.
- `evidence/phase-22/QA22F-10-*.txt` — stream off: every binary affordance disappears.

## Section G — Phase 22G: The binary genealogy tree (`QA22G-01` … `QA22G-12`)

- `evidence/phase-22/QA22G-01-*.txt` — server-rendered first paint works without JavaScript.
- `evidence/phase-22/QA22G-02-*.png` — d3 layout: expand/collapse, zoom, fit, search, breadcrumb.
- `evidence/phase-22/QA22G-03-*.txt` — node cards carry points, pairs and today's pairs.
- `evidence/phase-22/QA22G-04-*.txt` — lazy subtree loading and URL deep-link state.
- `evidence/phase-22/QA22G-05-*.txt` — in-node registration modal registers into a free slot.
- `evidence/phase-22/QA22G-06-*.txt` — no-JS register link and invite links.
- `evidence/phase-22/QA22G-07-*.txt` — live refresh preserves pan/zoom state.
- `evidence/phase-22/QA22G-08-*.txt` — keyboard and ARIA behaviour.
- `evidence/phase-22/QA22G-09-*.png` — responsive and compact mode screenshots.
- `evidence/phase-22/QA22G-10-*.txt` — search respects privacy.
- `evidence/phase-22/QA22G-11-*.txt` — the sponsor view is byte-identical.
- `evidence/phase-22/QA22G-12-*.txt` — performance and query budget.

## Section H — Phase 22H: Operations (`QA22H-01` … `QA22H-07`)

- `evidence/phase-22/QA22H-01-*.txt` — the four binary health checks render on `/admin/reports`.
- `evidence/phase-22/QA22H-02-*.txt` — readiness: pass on a clean fixture, warn on zero-PV and unplaced.
- `evidence/phase-22/QA22H-03-*.txt` — points: pass on a clean fixture, fail on drift, `--fix` repairs.
- `evidence/phase-22/QA22H-04-*.txt` — escrow: pending count, worth and oldest age.
- `evidence/phase-22/QA22H-05-*.txt` — release job: last run, from `CronLog`.
- `evidence/phase-22/QA22H-06-*.txt` — readiness panel links to the health panel (read-only).
- `evidence/phase-22/QA22H-07-*.txt` — one release job, both streams; no second cron.

## Section I — Phase 22I: Release (`QA22I-01` … `QA22I-06`)

- `evidence/phase-22/QA22I-01-*.txt` — the QA pack: sections A–H executed on the scratch database.
- `evidence/phase-22/QA22I-02-*.txt` — the acceptance run: Appendix B's 12 steps on the scratch database.
- `evidence/phase-22/QA22I-03-*.txt` — docs copy-paste verification: every command runs, every key exists, every route exists.
- `evidence/phase-22/QA22I-04-*.txt` — badges and phase: footer/sidebar reads 22.
- `evidence/phase-22/QA22I-05-*.txt` — the phase log: nine entries, counts, deviations, hand-off.
- `evidence/phase-22/QA22I-06-*.txt` — the sign-off sheet: every box ticked.

Each check in [`../phase-22-product-binary.md`](../phase-22-product-binary.md) §5–§13 names its evidence file. Paste text transcripts into the matching file; name screenshots `QA22X-nn-<short-name>.png`. No check in sections B–I moves money or writes an award, a volume row, a node balance or a wallet row (Rule 1); every money-carrying check runs on a scratch schema.

**Total checks: 83** (A 8 · B 9 · C 10 · D 12 · E 9 · F 10 · G 12 · H 7 · I 6).
