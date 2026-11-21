# EcoWealth — Phase 22 manual QA: Product binary bonus

**Scope.** Phase 22 of the member system (`dmp/plan-ecowealth-product-binary-sliced.md`): a product binary bonus paid from the points a member's own repeat purchases and their binary team's purchases place on the two sides of a placement tree. The work is delivered in nine gated phases **22A → 22I**. This document is created by **22A with section A only**; every later phase appends its own section before its gate, and the file is never rewritten.

**Audience.** The person running the phase gate. If you are new to this system, start with the **glossary** (§2) and the **setup** (§3) before touching any check. Section A moves **no money** (migration, counts, page reads, install/reset tools on scratch schemas). Sections B–I may configure, place, pay and report; every money-carrying section runs on a scratch schema — **never on a production database**.

**Document status.** Section A complete by 22A. Sections B–I are appended by their phases before their gates. **8 checks in section A** (QA22A-01 … QA22A-08). Every check has an evidence file under `docs/qa/evidence/phase-22/`, indexed by that folder's `README.md`; a check whose only output is a count, a SQL row or a rendered fragment is captured as a text transcript, and the browser-only steps are recorded there as harness transcripts at the markup level.

---

## 1. What is already automated

Run these first, from `system/`. If any fails, stop and report before starting the manual pass.

```powershell
php bin\build_install_sql.php --check     # exit 0, no output: the bundle matches its sources
php tests\run.php                         # full suite
powershell -NoProfile -File tests\smoke.ps1
php bin\wallet_reconcile.php              # exit 0: this phase moves no money
```

Expected final lines at `p22a-foundation`:

```text
Cases: 1506   Passed: 1506   Failed: 0
All good.
```

```text
Passed: 278   Failed: 0
```

The baseline was **1,484 / 1,484** PHP cases and **278 / 278** smoke checks at `p22-start` (the Phase 21 release gate, `p21g-release`); 22A adds **22** cases and **no** smoke check, because it adds no route and no page. The bundle reports **42 tables / 89 settings**. The only visible change of the phase is two new entries in the wallet statement filter; selecting either returns no rows until 22D.

No automated case in this phase writes a binary node, volume or award row on the development database. Section A verifies counts and *absence* of UI, plus the wallet filter.

---

## 2. Glossary (read first if you are new to this system)

| Term | Meaning |
|---|---|
| **Product binary** | The bonus stream built in Phase 22. Members place repeat purchases on the left or right side of a placement tree, match left against right points into pairs, and earn a Self Bonus on their own purchase plus pair awards up the tree. |
| **Binary node** | One row in `binary_nodes` per member: upline, side, depth, path and cached point balances. Separate from `users.sponsor_id`. |
| **Binary volume** | The append-only signed points ledger (`binary_volume`): own, team, pair, pair_return, reversal and adjustment rows. |
| **Binary award** | A pending money row in `binary_awards` (kind `self` or `pair`), escrowed like the unilevel stream and released after delivery + hold days. |
| **ref_type** | A column on `ewallet_ledger` naming the movement kind. Product binary uses `ref_type = 'binary'` and `binary_reversal`. |
| **Scratch schema** | A throwaway database copy used for QA so you never touch production or the development copy. |
| **APP_PHASE** | A build constant. Phase 22 sets it to `22` at 22I only; it is still **21** during 22A. |

---

## 3. Before you begin (setup for beginners)

You need three things before running any check:

1. **Laragon running** (Start All in the Laragon control panel) — gives you MySQL, PHP and the web app.
2. **Two scratch schemas** named `ecowealth_sneat_tool_a_test` and `ecowealth_sneat_tool_b_test`. The install and reset tools recreate these themselves, but you may need to create them once:
   ```powershell
   & "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS ecowealth_sneat_tool_a_test;"
   & "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS ecowealth_sneat_tool_b_test;"
   ```
3. **The notation** used throughout this document:
   - `mysql` means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the app account.
   - `php` means Laragon's PHP 8.3 CLI.
   - Every command runs from `system/` (e.g., `C:\laragon\www\ecowealth\system`).
   - `powershell -NoProfile -File` runs a PowerShell script without loading your profile.

**Rule: never run the money-carrying sections on the development or production database.** Section A is read-only for the app and runs on a scratch schema where noted. Never run sections that move money on `ecowealth_sneat`.

---

## 4. How to read a check

Every check has the same shape:

| Field | Meaning |
|---|---|
| **Why** | the phase rule or contract the check proves |
| **Where** | the exact shell, URL or SQL client |
| **Evidence** | the file name under `docs/qa/evidence/phase-22/` |
| **Result** | `☐ Pass  ☐ Fail  ☐ Blocked` — replace the box |

A check that produces only counts is captured as a **text transcript**, pasted into its evidence file; a check that shows a browser screen is captured as a screenshot. Section A produces only text.

When a check says "Expected," compare what you see against every bullet. If anything differs, mark the check `☐ Blocked`, save your output as evidence, and report before continuing.

---

## 5. Section A — Phase 22A: Foundation (`QA22A-01` … `QA22A-08`)

**Prerequisites.** Tag `p22-start` on `feature/product-binary` (the Phase 21 release gate); the development copy at migration **025** (**39 tables, 79 settings**); the app running (Laragon → Start All) for the page checks; the scratch schemas `ecowealth_sneat_tool_a_test` and `ecowealth_sneat_tool_b_test` provisioned (they are recreated by the tools themselves). Expected suite count for this section: **1,506** PHP cases and **278** smoke checks.

> **Where each check runs.** `QA22A-01` takes the backup. `QA22A-02`, `03`, `07` and `08` run on scratch schemas. `QA22A-04`, `05` and `06` need the running application, which reads the development copy: apply migration `026` to `ecowealth_sneat` once, then run them. The backup from `QA22A-01` is the rollback. Nothing the pages do writes a binary row, and `QA22A-05` is run on a scratch schema so the development copy gains no QA member.

### QA22A-01 — Back up the development copy and confirm it is at migration 025

| Why | 22A is the first schema change of Phase 22; the standing rule is a dump outside the repository before any migration. |
|---|---|
| Where | PowerShell + `mysqldump` |
| Evidence | `QA22A-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysqldump -u ecowealth -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-22\ecowealth_sneat-pre-22a-<stamp>.sql`
2. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM ecowealth_sneat.settings"`
4. `mysql -u ecowealth -p -e "SHOW TABLES LIKE 'binary%'" ecowealth_sneat`

Expected

- The dump exists, is non-empty and lives **outside** the repository.
- **39** tables and **79** settings (the development copy was built by migrations `001`–`025`).
- `SHOW TABLES` returns **no row**: the feature does not exist yet.

### QA22A-02 — Apply migration 026 twice to a p22-start schema

| Why | Rule 1: the migration is additive and idempotent. A second run must be a no-op that never re-arms anything. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_b_test` |
| Evidence | `QA22A-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build a **p22-start** scratch schema from the previous tag's bundle, not the current one:
   `git show p21g-release:system/database/install.sql | mysql -u ecowealth -p ecowealth_sneat_tool_b_test`
2. Confirm it is the previous shape: `SHOW TABLES LIKE 'binary%'` → no row; **39 tables, 79 settings**.
3. `mysql -u ecowealth -p ecowealth_sneat_tool_b_test < database/migrations/026_product_binary.sql`
4. `mysql -u ecowealth -p -e "SHOW TABLES LIKE 'binary%'" ecowealth_sneat_tool_b_test`
5. Run step 3 again (the second run must also exit 0).
6. `SHOW COLUMNS FROM binary_nodes;`
7. `SHOW COLUMNS FROM binary_volume;`
8. `SHOW COLUMNS FROM binary_awards;`
9. `SHOW COLUMNS FROM shop_orders LIKE 'binary%';`
10. `SHOW COLUMNS FROM shop_order_items LIKE '%points%';`
11. `SHOW COLUMNS FROM products LIKE 'binary%';`
12. `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type';`

Expected

- Step 2: **no** `binary%` table, 39 tables, 79 settings.
- Step 4: exactly three tables — `binary_nodes`, `binary_volume`, `binary_awards`.
- Step 5: exit **0**, and the counts after both runs are unchanged: **42 tables, 89 settings**.
- Step 6: `user_id, upline_id, side, root_id, depth, path, state, left_points, right_points, left_credited, right_credited, points_matched, pairs_paid, personal_points, placed_at, placed_by, moved_at, moved_by, move_count, created_at, updated_at`.
- Step 7: `id, node_user_id, side, kind, points, order_id, order_item_id, buyer_id, award_id, note, created_by, created_at`.
- Step 8: `id, kind, order_id, buyer_id, user_id, side, points, pair_count, pair_points, rate_pct, amount, status, hold_days, ledger_id, credited_at, reversal_ledger_id, reversed_amount, paid_at, voided_at, void_reason, created_at`.
- Step 9: `binary_position, binary_processed_at, binary_points, binary_self_total, binary_pair_total, binary_total`.
- Step 10: `unit_points, points_total`.
- Step 11: `binary_enabled`.
- Step 12: the enum ends `…,'binary','binary_reversal')`.
- The settings carry the ten binary keys with their defaults, and `binary_enabled = '0'` on every product.

### QA22A-03 — The ten settings, the defaults and the stream off

| Why | Rule 4: defaults are off. `products.binary_enabled = 0`, `binary_enabled = '0'`. A fresh install and a migrated install both pay nothing. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_b_test` (or the migrated development copy) |
| Evidence | `QA22A-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT key_name, value FROM settings WHERE key_name LIKE 'binary\_%' ORDER BY key_name;`
2. `SELECT COUNT(*) FROM products WHERE binary_enabled <> 0;`
3. `SELECT COUNT(*) FROM shop_orders WHERE binary_processed_at IS NOT NULL;`

Expected

- Step 1: exactly ten rows — `binary_enabled = 0`, `binary_self_pct = 5.000`, `binary_pair_points = 1000`, `binary_pair_pct = 10.000`, `binary_daily_pair_cap = 20`, `binary_requires_active = 1`, `binary_hold_days = 7`, `binary_release_enabled = 1`, `binary_reversal_enabled = 1`, `binary_ledger_since` a valid timestamp.
- Step 2: **0** — every product starts with the switch off.
- Step 3: **0** — no order has been processed by the stream.

### QA22A-04 — The pages that must *not* have changed

| Why | Rule 10 and D-16: 22A ships data only. The settings card is 22B's and the product binary card is 22B's; this check proves the phase did not creep into a view. |
|---|---|
| Where | the running app as `admin` — `/admin/settings`, `/admin/products/{id}/edit`, `/wallet` |
| Evidence | `QA22A-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as `admin`; open **System → Settings** (`/admin/settings`).
2. Scroll to the commissions section and look for any **Product binary** block, switch or amount.
3. Open **Products**, then any product's edit page (`/admin/products/{id}/edit`).
4. Look for a Product binary card, badge or switch.
5. Open the wallet statement (`/wallet`) and look for **Product binary** and **Product binary reversal** in the movement filter.
6. Open `/admin/commissions` and look for a binary tab or filter.

Expected

- No Product binary block, field, switch or amount on the settings page.
- No binary card on any product edit page.
- **Product binary** and **Product binary reversal** appear in the wallet statement filter (Rule 6 / Rule 7 — the only visible change), with their `bx-` icons.
- Selecting either returns **no rows** and the total reads ₱0.00: no row can carry the value until 22D.
- No binary tab, filter or figure on the commissions page.
- Nothing on those pages errors, and every other card renders exactly as it did at `p22-start`.

### QA22A-05 — Placing and paying an order writes no binary row

| Why | Rule 10: no application file outside the 22A manifest reads or writes the new objects. A paid order must not create binary volume or awards until 22D connects the hooks. |
|---|---|
| Where | a scratch schema with the migrated code (run through the order/payment path, not the browser on the development copy) |
| Evidence | `QA22A-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a scratch schema (never the development copy), place and pay an e-wallet order as a member whose cart carries points.
2. `SELECT COUNT(*) FROM binary_nodes;`
3. `SELECT COUNT(*) FROM binary_volume;`
4. `SELECT COUNT(*) FROM binary_awards;`
5. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type IN ('binary','binary_reversal');`

Expected

- Steps 2–5: **0** each — no node, no volume, no award, no wallet movement.
- The order itself is accepted and paid normally; the order page and the sponsor's wallet are unchanged.

### QA22A-06 — The wallet statement filter names Product binary

| Why | Rule 6 / Rule 7: the only visible change of this phase. The filter enumerates `WalletService::REF_TYPES`, so it must list the new movements — and they cannot match a row until 22D. |
|---|---|
| Where | the member wallet statement (`/wallet`) and the admin wallet monitor |
| Evidence | `QA22A-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Sign in as a member (for example `juan`), open the wallet page (`/wallet`).
2. Open the statement filter's movement-type list and look for **Product binary** and **Product binary reversal**.
3. Choose each and apply the filter.
4. Sign in as `admin`, open **Finance → Wallets** and repeat steps 2–3 on the statement tab.

Expected

- **Product binary** and **Product binary reversal** appear in both filter lists (the member statement and the admin monitor), with `bx-git-branch` and `bx-undo` icons.
- Selecting either returns **no rows** and the total reads ₱0.00: no row can carry the value until 22D.
- The other movement kinds still filter exactly as they did, and no page error appears and no new PHP warning lands in `storage/logs/`.

### QA22A-07 — Rebuild the bundle and re-install on an empty schema

| Why | Rule 2 / P22A-T5: the committed bundle must equal its sources, and a fresh install reports the new counts. |
|---|---|
| Where | PowerShell on `system/` |
| Evidence | `QA22A-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\build_install_sql.php` (rebuild) and `php bin\build_install_sql.php --check` (must exit 0).
2. `php bin\db_install.php --check --database=ecowealth_sneat_tool_a_test`
3. `php bin\db_install.php --database=ecowealth_sneat_tool_a_test --force --admin-password=QaAdmin!23456 --superadmin-password=QaSuper!23456`
4. `php bin\wallet_reconcile.php`
5. On the fresh schema: `SELECT COUNT(*) FROM binary_nodes;`, `SELECT COUNT(*) FROM binary_volume;`, `SELECT COUNT(*) FROM binary_awards;`, `SELECT key_name, value FROM settings WHERE key_name LIKE 'binary\_%' ORDER BY key_name;`

Expected

- `--check` exits **0** with no output; the header reads **42 tables · 89 settings**.
- The install verifies and prints `tables_created = 42`, `settings_rows = 89`, `binary_nodes_rows = 0`, `binary_volume_rows = 0`, `binary_awards_rows = 0` (and 2 staff, 0 members, 7 products, 2 packages, 18 levels, 10 contents, 0 codes).
- The verification's transactional check passes (the binary tables are empty).
- `wallet_reconcile.php` exits **0**; no binary section is part of the reconciler until 22H, and no binary ledger row exists.
- Step 5: the three tables are empty; ten settings rows with the defaults above, `binary_enabled = 0` and every product's `binary_enabled = 0`.

### QA22A-08 — A business reset empties the three binary tables and keeps the ten settings

| Why | Rule 8 / §13.8 I-12: after a reset `binary_nodes`, `binary_volume` and `binary_awards` are empty; the ten settings survive. |
|---|---|
| Where | the reset console (`bin\db_reset.php`) on a scratch schema |
| Evidence | `QA22A-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a scratch schema, insert one node, one volume row and one award row (through raw SQL, not through any app writer).
2. `php bin\db_reset.php --dry-run` — the plan must carry a **product_binary** step.
3. `php bin\db_reset.php --force --confirm=<scratch schema>`
4. `SELECT COUNT(*) FROM binary_nodes;`
5. `SELECT COUNT(*) FROM binary_volume;`
6. `SELECT COUNT(*) FROM binary_awards;`
7. `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'binary\_%';`

Expected

- The dry run writes nothing and plans the **product_binary** step (`DELETE FROM binary_volume`, `DELETE FROM binary_awards`, `DELETE FROM binary_nodes`).
- The reset reports `ok` and a clean verification (the zero pack, the finance reconciliations, the counters at 1).
- Step 4–6: **0** each — the three tables are empty.
- Step 7: **10** settings survive, including `binary_enabled = 0`.

---

## 7. Section B — Phase 22B: Configuration and readiness (`QA22B-01` … `QA22B-09`)

**Prerequisites.** Tag `p22a-foundation`; migration 026 applied to the development copy (39 → 42 tables, 79 → 89 settings); the app running. All of these checks are **read-only for money**: no binary award, volume or wallet row may be created in this section. Expected suite count for this section: **≥ 1,530** PHP cases and **278** smoke checks.

### QA22B-01 — The product edit card renders off by default

| Why | Rule 1 / D-16: the switch ships off and renders nowhere until configured. |
|---|---|
| Where | the running app as `admin` — `/admin/products/{id}/edit` |
| Evidence | `QA22B-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open any product's edit page.
2. Look for the **Product binary** card (after the *Unilevel repeat-purchase bonus* card, in the left column).
3. Confirm the switch is **off** (unchecked).
4. Confirm the points readout shows *0 points — set a P.V.* when `pv = 0`, or *N points per unit* when `pv > 0`.
5. Confirm the self/pair sentence names the global preset (5% self, 1,000-point pair at 10% → ₱100.00).

Expected

- The card renders with the switch **off**.
- The points readout matches the product's P.V. (`ROUND(pv)` per unit).
- No per-product pair-size, pair-rate or self-rate input exists (D-09).
- No binary award, volume or wallet row exists.

### QA22B-02 — Flag a product and save; the switch persists

| Why | P22B-T1 / P22B-T12: `binary_enabled` saves and re-reads; the badge reads `Binary N pts`. |
|---|---|
| Where | the running app as `admin` — `/admin/products/{id}/edit`, then `/admin/products` |
| Evidence | `QA22B-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a product's edit page, set P.V. to ₱250 and switch **Product binary** on.
2. Save. Re-open the edit page.
3. Open `/admin/products` and look at the product's row badge.

Expected

- Re-opened: the switch is **on** and the card reads *250 points per unit*.
- The list badge reads **`Binary 250 pts`**.
- The self/pair sentence still names the global preset.

### QA22B-03 — Binary on with P.V. 0 is refused

| Why | P22B-T2 / Rule 8: a flagged product with no P.V. earns nothing, so the pair is refused. |
|---|---|
| Where | the running app as `admin` — `/admin/products/{id}/edit` |
| Evidence | `QA22B-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a product's edit page with the switch **on**, set P.V. to `0`.
2. Save.
3. Confirm the form returns with a field error on the switch and the values as typed.
4. Confirm the product row still shows its previous P.V. (one transaction, nothing changed).

Expected

- Field error: *"Give the product a P.V. or turn its binary switch off."*
- The product row is unchanged.
- With JS off, the same refusal happens (Rule 11).

### QA22B-04 — The product list badge and the `?binary=on|off` filter

| Why | P22B-T12 / P22B-T13: the badge and the filter work and combine with pagination. |
|---|---|
| Where | the running app as `admin` — `/admin/products?binary=on` and `?binary=off` |
| Evidence | `QA22B-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Have at least one product flagged on (P.V. > 0) and one flagged off.
2. Open `/admin/products?binary=on` — only flagged products appear.
3. Open `/admin/products?binary=off` — only unflagged products appear.
4. Apply `?status=` together with `?binary=` — both filters apply.

Expected

- The on-filter shows exactly the flagged products, each with `Binary N pts`.
- The off-filter shows exactly the unflagged products, with no badge.
- The filter survives pagination and combines with `?status=`.

### QA22B-05 — The settings card: all nine keys, toggles, validation, audit

| Why | P22B-T6 … T11, P22B-T18: the commissions card carries the nine binary keys, master first, validates ranges, and audits changes. |
|---|---|
| Where | the running app as `admin` — `/admin/settings` → **Commissions** → *Product binary* |
| Evidence | `QA22B-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open **System → Settings** → **Commissions** → *Product binary*.
2. Confirm the master switch, Self Bonus %, Points per pair, Pair value %, Pairs per day, Release delay, and the four toggles render, master first.
3. Confirm `binary_ledger_since` is NOT rendered.
4. Save with **Points per pair = 0** — refused.
5. Save with **Self Bonus % = 100.001** (or four decimals) — refused.
6. Save **Points per pair = 1000**, **Self 5.000**, **Pair 10.000**, **Cap 20**, **Hold 7** — persisted.
7. Open the audit desk — one `settings.update` row for the commissions section.

Expected

- Nine visible binary keys, master switch first; `binary_ledger_since` absent.
- Pair size 0 refused ("at least 1"); self above 100 or four decimals refused.
- The preset saves; the audit desk shows the before/after.
- A repeat save with identical values writes no audit row.

### QA22B-06 — The readiness panel counts and links

| Why | P22B-T19: the four readiness counts match the products table and the links filter correctly. |
|---|---|
| Where | the running app as `admin` — `/admin/settings` → **Commissions** → *Product binary readiness* |
| Evidence | `QA22B-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Flag two products on (one with P.V. > 0, one with P.V. = 0 via a direct row or a prior version) and leave one off.
2. Open the readiness panel.
3. Confirm the counts: "Binary on, no points", "Binary off", "Global switch", "Preset check".
4. Follow each link — it opens `/admin/products?binary=on` or `?binary=off`.

Expected

- The **Binary on, no points** count equals `COUNT(*) FROM products WHERE binary_enabled = 1 AND pv = 0`; its link opens the on-filter.
- The **Binary off** count equals `COUNT(*) FROM products WHERE binary_enabled = 0`; its link opens the off-filter.
- The **Global switch** reads On/Off; the **Preset check** reads *1,000 points per pair pays ₱100.00 at 10%.* (recomputed when the pair values change).
- None of the four is a button that changes a setting (Rule 12).

### QA22B-07 — The stream is on but still pays nothing

| Why | P22B-T11 / P22B-T22 / D-16: configuring the stream in 22B writes no money; the engine arrives in 22D. |
|---|---|
| Where | the running app as `admin` + a member purchase; SQL on the development copy |
| Evidence | `QA22B-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Turn the global switch **on**, set Self 0% and Pair 0%.
2. Confirm the readiness sentence says pairs pay ₱0.00.
3. As a member, place and pay an e-wallet order whose cart carries points (a product flagged on, P.V. > 0).
4. `SELECT COUNT(*) FROM binary_awards; SELECT COUNT(*) FROM binary_volume; SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type IN ('binary','binary_reversal');`

Expected

- The card warns *Orders now place points and pay awards when the money engine is live.* (P22B-T24).
- All three counts are **0**: no award, no volume, no wallet movement.
- The order page and the sponsor's wallet are unchanged.

### QA22B-08 — The product form works without JavaScript

| Why | Rule 11: no JavaScript-only validation; with JS off the form still saves and validates. |
|---|---|
| Where | the running app as `admin` — `/admin/products/{id}/edit` with JS disabled |
| Evidence | `QA22B-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Disable JavaScript in the browser.
2. Open a product's edit page, flip the binary switch, set P.V. to 250, save.
3. Re-open: the switch is on, the card reads *250 points per unit*.
4. Try P.V. 0 with the switch on: the field error returns.

Expected

- The form saves and validates without JavaScript.
- Only the live points readout is missing (progressive enhancement).

### QA22B-09 — Member pages show no binary content

| Why | P22B-T21 / D-22: 22B adds no member-facing binary surface; 22F owns the earnings tab. |
|---|---|
| Where | the running app as a member — `/shop/{id}`, `/checkout`, `/orders/{id}`, `/earnings`, `/genealogy` |
| Evidence | `QA22B-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As a member, open each of the five pages.
2. Confirm none contains **Product binary**, **binary upline**, **Left team**, **Right team**, or any points/pairs figure.
3. `SELECT COUNT(*) FROM binary_awards; SELECT COUNT(*) FROM binary_volume;` — **0**.

Expected

- No binary copy appears on any member page.
- No binary award or volume row exists.

## 10. Section C — Phase 22C: Placement, the tree and registration (`QA22C-01` … `QA22C-10`)

**Prerequisites.** Tag `p22b-configuration`; migration 026 applied to the development copy (42 tables, 89 settings); the app running. None of these checks writes money: no `binary_volume` or `binary_awards` row may be created in this section, and no award or wallet movement exists yet (the money engine is 22D). Expected suite count for this section: **≥ 1,576** PHP cases and **≈284** smoke checks.

### QA22C-01 — Every registration creates exactly one legal node

| Why | D-03: placement is required at registration and validated server-side; upline exists, side is left/right, slot is free, no cycle, inside the registrar's or sponsor's subtree (admins: anywhere). |
|---|---|
| Where | the running app as a member who may register another (`/register`, mode `standard`) |
| Evidence | `QA22C-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As `admin`, open `/register` with `sponsor=juan` and `binary_upline=juan`, `binary_position=left`, then submit.
2. Confirm the new member exists and `binary_nodes` has exactly one row for them: `upline_id = juan`, `side = 'left'`, `state = 'placed'`.
3. Try again with the same slot (`juan` / `left`): confirm refusal — the slot is taken.
4. Try a cycle (`binary_upline = the new member's own id`): confirm refusal.
5. Try `binary_position = bogus`: confirm refusal.

Expected

- A valid placement creates exactly one node, atomically with the user insert.
- A taken slot, a cycle, a bad side, or an ineligible upline is refused and no member row is left behind.
- The sponsor tree (`users.sponsor_id`) is unchanged by the binary placement.

### QA22C-02 — The admin placement console and the audited move route

| Why | D-04 / D-18: placement is immutable once the subtree has volume or awards; an admin may move an empty subtree only; every move is audited with before/after; exactly one route (`POST /admin/members/{id}/placement`). |
|---|---|
| Where | the running app as `admin` — `/admin/members/{id}` (placement panel) and SQL |
| Evidence | `QA22C-02.txt`, `QA22C-02-audit.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Place two members under `juan` (left and right), each with no children, no volume, no awards.
2. Move the left member to a different upline (`boss`) via the admin placement panel: confirm success.
3. `SELECT * FROM audit_log WHERE subject LIKE 'binary%'` — confirm one before/after move row.
4. Give the moved member a child (place someone under it), then try to move it again: confirm refusal (non-empty subtree).
5. Try to move a member to its own descendant: confirm refusal (cycle).
6. Confirm no route other than `POST /admin/members/{id}/placement` contains `placement` (route inventory).

Expected

- Empty-subtree moves succeed and are audited; non-empty, cyclic, self-parent and taken-slot moves are refused.
- Exactly one placement route exists; registration placement rides on the existing `register.php`.

### QA22C-03 — The backfill tool is deterministic and idempotent

| Why | D-24 / 22C-S5: `binary_place_tree.php` builds the demo tree deterministically; `--check` writes nothing; `--apply` is idempotent. |
|---|---|
| Where | `system/bin/binary_place_tree.php` on a scratch schema |
| Evidence | `QA22C-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\binary_place_tree.php --check` on a fresh p22b-configuration schema → `0 written`, exit 0.
2. `php bin\binary_place_tree.php --apply` → the demo tree (root + five demo members on fixed slots) is built; exit 0.
3. Run `--apply` again → `0 written` (idempotent).
4. `SHOW TABLES LIKE 'binary%'` → 3 tables; `SELECT COUNT(*) FROM binary_nodes` → 6 (root + five demo members).
5. Confirm every member is reachable from the root; no cycles.

Expected

- `--check` never writes; `--apply` builds the same tree every time; a second `--apply` is a no-op.
- The tree is a single rooted tree rooted at the company account.

### QA22C-04 — No money exists at placement

| Why | Rule 1 / D-16: 22C ships the tree and placement only; no award, volume or wallet row exists anywhere. |
|---|---|
| Where | SQL on the development copy |
| Evidence | `QA22C-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT COUNT(*) FROM binary_volume;` → **0**.
2. `SELECT COUNT(*) FROM binary_awards;` → **0**.
3. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type IN ('binary','binary_reversal');` → **0**.
4. Place and pay an order as any member: all three counts stay **0**.

Expected

- Zero binary money rows before 22D connects the hooks; a paid order writes nothing binary.

### QA22C-05 — Placement guard: sponsor-tree facts unchanged

| Why | D-01 / Rule 10: the binary tree is separate from the sponsor tree; `users.sponsor_id` is never rewritten by a binary placement. |
|---|---|
| Where | SQL on the development copy |
| Evidence | `QA22C-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Before any placement: `SELECT id, sponsor_id FROM users WHERE id IN (<placed members>)`.
2. Place several members with binary uplines different from their sponsors.
3. Re-query: every member's `sponsor_id` is unchanged; `binary_nodes.upline_id` reflects the binary choice only.

Expected

- The sponsor tree is byte-identical before and after binary placement; a binary upline never becomes a sponsor link.

### QA22C-06 — Slot uniqueness and check constraints

| Why | §15.4: `UNIQUE (upline_id, side)`, check constraints (both-null-or-both-set upline/side, no self-parent, `pair` volume rows need an award, `own` rows non-negative). |
|---|---|
| Where | SQL on a scratch schema |
| Evidence | `QA22C-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Insert two rows with the same `(upline_id, side)` → PDOException 23000.
2. Insert a node with `upline_id = NULL, side = 'left'` → check constraint fails.
3. Insert a node with `upline_id = user_id` → check constraint fails.
4. Insert a `binary_volume` `pair` row with no `award_id` → check constraint fails.
5. Insert an `own` row with negative points → check constraint fails.

Expected

- Every malformed row is refused by the schema; the slot unique key holds.

### QA22C-07 — The root is the company account and is never paid

| Why | D-02: the primary admin's node has no upline and no side; the pairing walk stops at its children; its own purchases place no points. |
|---|---|
| Where | SQL on the development copy |
| Evidence | `QA22C-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT * FROM binary_nodes WHERE state = 'root';` → exactly one row, `upline_id IS NULL`, `side IS NULL`, `user_id = <primary admin>`.
2. Confirm no `binary_volume` or `binary_awards` row names the root as a recipient.

Expected

- Exactly one root node; it is the company account; the root never pairs and never earns.

### QA22C-08 — Depth and path are derived correctly

| Why | §14.2 rule 4: `depth = upline.depth + 1`; `path = upline.path . side-letter`; the root has `depth = 0`, `path = ''`. |
|---|---|
| Where | SQL on the development copy (demo tree) |
| Evidence | `QA22C-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Walk the demo tree: for every non-root node, `depth = parent.depth + 1` and `path = parent.path || side`.
2. Confirm `MAX_DEPTH` (100) is never exceeded.

Expected

- Depth and path match the parent for every placed node; the tree stays bounded.

### QA22C-09 — Registration placement rolls back on invalid placement

| Why | 22C-S3: an invalid placement rolls back the user insert; no orphan user without a node, no node without a user. |
|---|---|
| Where | the running app + SQL on a scratch schema |
| Evidence | `QA22C-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Attempt registration with an invalid binary upline (unknown user): confirm the user is NOT created.
2. Attempt registration with a taken slot: confirm the user is NOT created.
3. `SELECT COUNT(*) FROM users WHERE id NOT IN (SELECT user_id FROM binary_nodes);` for the attempted registration → the attempted user does not exist.

Expected

- Every invalid placement rolls back the entire registration transaction; no orphan rows.

### QA22C-10 — The genealogy controller's binary facts

| Why | 22C-S5: `/genealogy` binary facts render without the d3 explorer (22G); the sponsor view is unchanged. |
|---|---|
| Where | the running app as a member — `/genealogy` and `/genealogy?view=binary` |
| Evidence | `QA22C-10.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/genealogy` as a member: confirm the sponsor chain renders exactly as at `p22b-configuration` (no binary content).
2. Open `/genealogy?view=binary`: confirm binary node facts render (placement, side, depth) for the viewer's subtree only.

Expected

- The sponsor view of `/genealogy` is byte-identical to 22B; the binary view names no one outside the viewer's subtree.

---

## 11. Section D — Phase 22D: Money engine (`QA22D-01` … `QA22D-12`)

**Prerequisites.** Tag `p22c-placement`; migration 026 applied; the stream on at the preset; products flagged; placed members and the demo tree present on the development copy; the app running. **This is the only phase that moves money.** Every check runs on a scratch schema or against QA-controlled orders — never against production member balances. Expected suite count for this section: **≥ 1,650** PHP cases and **≈288** smoke checks.

### QA22D-01 — The Self Bonus on a paid order

| Why | D-07 / D-08: the buyer earns `round(order_points × binary_self_pct / 100, 2)` once per order, no Active gate; the purchase lands on the buyer's own node. |
|---|---|
| Where | the running app as `buyerA`; SQL on the development copy |
| Evidence | `QA22D-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Flag P1 (P.V. 250) for the binary; stream on at the preset.
2. As `buyerA` (placed under `c`, left), put 4 × P1 in the cart, choose **Left**, pay with e-wallet.
3. `SELECT kind, amount, status FROM binary_awards WHERE order_id = <order>;` → one `self` row, `pending`, **₱50.00**.
4. `SELECT binary_points, binary_self_total, binary_pair_total, binary_total FROM shop_orders WHERE id = <order>;` → `1000`, `50.00`, `0.00`, `50.00`.
5. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'binary';` → **0** (escrow: pending, not yet credited).

Expected

- One pending self award of ₱50.00; order snapshots stamped; no wallet movement yet (escrow until delivery + hold).

### QA22D-02 — Volume rolls up to every binary upline

| Why | D-08: the purchase points roll up to every binary upline on the side the buyer sits under; the company root receives volume and is never paid. |
|---|---|
| Where | SQL on the development copy |
| Evidence | `QA22D-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. With the QA22D-01 order placed (buyerA path LLLLL under c→b→a→boss→root): `SELECT user_id, side, points, kind FROM binary_volume WHERE order_id = <order> ORDER BY user_id;`
2. Confirm rows: buyerA (own, left, 1000), c (team, left, 1000), b (team, left, 1000), a (team, left, 1000), boss (team, left, 1000), root (team, left, 1000).
3. Confirm `cached left_points` on each node equals the sum of its `own`+`team` left rows.

Expected

- Volume appears on the buyer's node and every upline, on the side the buyer sits under; the root receives volume and is never a money recipient.

### QA22D-03 — Pair matching consumes points and pays pairs

| Why | D-09: `binary_pair_points` on the left matched with the same on the right is one pair; a pair pays `round(pair_points × binary_pair_pct / 100, 2)`; matched points are consumed with negative `pair` volume rows. |
|---|---|
| Where | the running app as `buyerB`; SQL |
| Evidence | `QA22D-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. As `buyerB` (placed under `c`, right), buy 4 × P1 (1,000 points) right.
2. `c` now has left 1,000 (from QA22D-01) and right 1,000 → 1 pair.
3. Confirm `c`'s pair award: ₱100.00, `pending`, `pair_count = 1`.
4. Confirm `c`'s left_points = 0 and right_points = 0 (consumed).
5. Confirm buyerB's own node did not pair (no left volume on buyerB).

Expected

- One pair at `c` paying ₱100.00; matched points consumed; the buyer's own node pairs only when its own opposite side has volume.

### QA22D-04 — Eligibility: an ineligible recipient keeps its volume

| Why | D-13: no pair is paid, no points are consumed, no `skipped` row; the points pair when the recipient becomes eligible. |
|---|---|
| Where | SQL + the running app |
| Evidence | `QA22D-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Make an upline ineligible (suspend membership or set `binary_requires_active` with an inactive month).
2. Place an order that would pair at that node: confirm no pair award, no consumed points, no `skipped` row.
3. Restore eligibility; place another order: confirm the pair now pays from the carried points.

Expected

- Ineligible points are preserved, not consumed; they pair when the recipient becomes eligible.

### QA22D-05 — The daily pair cap bounds one member's day

| Why | D-14: `binary_daily_pair_cap` (20) counted on the Asia/Manila day of `paid_at`; excess volume carries. |
|---|---|
| Where | SQL on a scratch schema |
| Evidence | `QA22D-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set `binary_daily_pair_cap = 1`.
2. Place an order due 3 pairs at the buyer's node and 1 at the parent: confirm 1 pair paid at the buyer's node (cap reached), 1 at the parent (separate cap).
3. Confirm 2 pairs' worth of points stay on the buyer's node (carry).
4. The same member's next order the same day: 0 pairs at the buyer's node (cap used).

Expected

- The cap is per recipient per Asia/Manila day; excess carries to the next day.

### QA22D-06 — Idempotency: a replay writes nothing twice

| Why | D-10: `UNIQUE (order_id, user_id, kind)`; a replay of `onOrderPaid()` changes nothing. |
|---|---|
| Where | SQL on a scratch schema |
| Evidence | `QA22D-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Call `BinaryService::onOrderPaid()` twice for the same order (same PDO transaction or two).
2. `SELECT COUNT(*) FROM binary_awards WHERE order_id = <order>;` → exactly the same count as after the first call.
3. `SELECT SUM(points) FROM binary_volume WHERE order_id = <order>;` → unchanged.

Expected

- Replay is a no-op; no duplicate awards, no double volume.

### QA22D-07 — Escrow: release after delivery + hold_days

| Why | D-11: `pending` at payment, `credited` after delivery + `binary_hold_days` (7), by the shared release job. |
|---|---|
| Where | `system/bin/unilevel_release.php --stream=binary` on a scratch schema |
| Evidence | `QA22D-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Deliver the QA22D-01 order (mark `delivered`).
2. `php bin\unilevel_release.php --stream=binary --dry-run` → lists the due self award, credits nothing.
3. Run for real: `php bin\unilevel_release.php --stream=binary --limit=200` → exit 0.
4. `SELECT status, credited_at FROM binary_awards WHERE id = <self award>;` → `credited`, `credited_at` set.
5. `SELECT * FROM ewallet_ledger WHERE ref_type = 'binary' AND ref_id = <award id>;` → one `₱50.00` credit.

Expected

- Release is due only after delivery + hold; one idempotent wallet movement per award; a second run credits nothing.

### QA22D-08 — Cancellation reverses pending awards

| Why | §16.9: a cancellation cancels pending awards (no money moved) and reverses credited awards best-effort. |
|---|---|
| Where | the running app as `admin` (cancel a paid order); SQL |
| Evidence | `QA22D-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Cancel a paid order whose binary award is still `pending`: confirm the award becomes `cancelled`, no wallet row.
2. Cancel a paid order whose binary award is `credited`: confirm best-effort reversal (void row + reversal amount).
3. `SELECT SUM(points) FROM binary_volume WHERE order_id = <cancelled order>;` → the volume is reversed (negative rows).

Expected

- Pending cancellation moves no money; credited cancellation is voided and clawed back best-effort; volume reverses at every node.

### QA22D-09 — Reversal returns consumed pair points without re-pairing

| Why | §16.9: pair consumption is returned; no re-pairing happens on reversal. |
|---|---|
| Where | SQL on a scratch schema |
| Evidence | `QA22D-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. With a pair consumed at `c` (left 0, right 0 after QA22D-03): cancel the order that created the pair.
2. Confirm `c`'s left_points and right_points are restored to their pre-pair values.
3. Confirm no new pair award is created from the returned points.

Expected

- Consumed pair points return; no re-pairing on reversal.

### QA22D-10 — Stream off: a paid order writes zeros

| Why | D-16: with `binary_enabled = 0`, a paid order is stamped with zeros and writes no volume or award. |
|---|---|
| Where | the running app + SQL |
| Evidence | `QA22D-10.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Turn the master switch off (`binary_enabled = 0`).
2. As a member, place and pay an order with a points-bearing cart.
3. `SELECT binary_points, binary_self_total, binary_pair_total, binary_total FROM shop_orders WHERE id = <order>;` → all `0` / `0.00`.
4. `SELECT COUNT(*) FROM binary_awards WHERE order_id = <order>;` → **0**; `SELECT COUNT(*) FROM binary_volume WHERE order_id = <order>;` → **0**.

Expected

- The order is accepted normally; binary columns are zeroed; no volume or award rows.

### QA22D-11 — No node / disabled product: honest stamping

| Why | §14.3 rule 6: a purchase with a side but no points writes no volume and no self, and the order is stamped with zeros; no node / no eligible line / stream off → `binary_position NULL`. |
|---|---|
| Where | SQL on the development copy |
| Evidence | `QA22D-11.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Place and pay an order as a member with no binary node: confirm `binary_position IS NULL`, all binary columns zero, no rows.
2. Place and pay an order whose only lines have the binary switch off: confirm zero points, no rows.

Expected

- Unplaced members and disabled products stamp truthfully; no error, no warning, no rows.

### QA22D-12 — `totalsFor()`, `reconcile()` and the engine-level audit

| Why | §16.2 / §16.10: `totalsFor()` per member; `reconcile()` compares awards vs wallet; `audit()` compares cached balances vs the append-only ledger. |
|---|---|
| Where | `system/bin/binary_audit.php --check` + SQL |
| Evidence | `QA22D-12.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\binary_audit.php --check` → exit 0; every cached node balance equals the ledger.
2. `BinaryService::totalsFor(<member>)` → points and money agree with the rows.
3. `BinaryService::reconcile()` → awards vs wallet agree; no mismatch.

Expected

- Both ledgers and cached node totals reconcile; audit is read-only unless `--fix` is explicitly requested.

---

## 9. Section E — Phase 22E: Admin visibility (`QA22E-01` … `QA22E-09`)

**Prerequisites.** Tag `p22e-admin`; migration 026 applied; the app running. All checks in this section are **read-only**: no award, volume, node or wallet row is created or modified. Expected suite count for this section: **≥ 1,668** PHP cases and **≈298** smoke checks.

---

## 12. Section G — Phase 22G: The binary genealogy tree (`QA22G-01` … `QA22G-12`)

**Prerequisites.** Tag `p22g-tree`; migration 026 applied; the demo tree present; the app running. All checks are **read-only for money**: no award, volume, node or wallet row is created here. Expected suite count for this section: **≥ 1,722** PHP cases and **≈298** smoke checks.

### QA22G-01 — Server-rendered first paint works without JavaScript

| Why | §11.1 / D-19: `/genealogy?view=binary` renders a semantic list and links without JS; the no-JS fallback is the usable page. |
|---|---|
| Where | the running app as a member — `/genealogy?view=binary` with JS disabled |
| Evidence | `QA22G-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Disable JavaScript; open `/genealogy?view=binary`.
2. Confirm the tree/list renders: nodes, sides, depths, links; no d3, no modal.
3. Re-enable JavaScript; reload: confirm the d3 explorer takes over.

Expected

- First paint works without JS; the explorer enhances, never replaces, the server-rendered page.

### QA22G-02 — d3 layout: expand/collapse, zoom, fit, search, breadcrumb

| Why | §11.1–11.5: the vendored d3 v7 explorer renders server data and all controls preserve the access boundary. |
|---|---|
| Where | the running app — `/genealogy?view=binary` |
| Evidence | `QA22G-02.png` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Expand/collapse a branch; confirm children appear/disappear.
2. Zoom and fit; confirm the view scales and recenters.
3. Search for a member; confirm only matching nodes highlight.
4. Focus a node; confirm the breadcrumb shows the path to root.
5. Confirm no CDN: the d3 script is served from `public/assets/`.

Expected

- All controls work; the d3 asset is vendored with its licence; no external network call.

### QA22G-03 — Node cards carry points, pairs and today's pairs

| Why | §11.2: node cards show left/right points, pairs paid and today's pairs (replacing the reference's `left_count`/`right_count`). |
|---|---|
| Where | `/genealogy?view=binary` on a node with volume |
| Evidence | `QA22G-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open a node card that has placed points: confirm left pts, right pts, pairs paid, pairs today.
2. Confirm the numbers match `binary_nodes` cached balances and `binary_volume`.

Expected

- Node cards display cached points and pairs, always traceable to the ledger.

### QA22G-04 — Lazy subtree loading and URL deep-link state

| Why | §11.4: one query per expanded branch, capped; `?view=binary&root=&focus=` deep-links; ETag scoping. |
|---|---|
| Where | the running app + browser DevTools Network |
| Evidence | `QA22G-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Expand a deep branch; confirm exactly one JSON request per new branch.
2. Open `?view=binary&focus=<member>`; confirm that member's card is centred and the breadcrumb renders.
3. Confirm the JSON response is privacy-scoped (no ancestor outside the viewer's subtree).

Expected

- Lazy loading is bounded and server-authoritative; deep links focus the right node.

### QA22G-05 — In-node registration modal registers into a free slot

| Why | D-26 / 22G-S4: the `+` on an empty slot opens the modal; submit goes through `register.php` / `RegistrationService` only; the first valid registration wins the slot. |
|---|---|
| Where | the running app as `admin` — `/genealogy?view=binary`, click `+` on an empty slot |
| Evidence | `QA22G-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Click `+` on an empty slot under `juan`, left.
2. Fill the registration form (username, email, password, terms) and submit.
3. Confirm the new member exists with `binary_nodes.upline_id = juan`, `side = 'left'`.
4. Try to register a second member into the same slot: confirm refusal (slot taken).

Expected

- Legal slot registration works through the canonical service; a concurrent slot winner is a normal field error.

### QA22G-06 — No-JS register link and invite links

| Why | D-26: the invite link (`/register?ref=…&upline=…&side=…`) is a hint; the first valid registration wins; no slot reservation. |
|---|---|
| Where | the running app |
| Evidence | `QA22G-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Copy an invite link from a node card; open it with JS disabled.
2. Confirm the registration form pre-fills the upline and side.
3. Submit; confirm the member is placed in that slot.

Expected

- The no-JS link works; the slot goes to the first valid registrant, never reserved.

### QA22G-07 — Live refresh preserves pan/zoom state

| Why | §11.1: live refresh updates the tree without losing the user's pan/zoom/focus. |
|---|---|
| Where | the running app — `/genealogy?view=binary` |
| Evidence | `QA22G-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Expand a branch, zoom, focus a node.
2. Trigger live refresh (the app's Live revision system).
3. Confirm pan/zoom/focus are preserved after refresh.

Expected

- State survives live refresh; no full-page reload.

### QA22G-08 — Keyboard and ARIA behaviour

| Why | §17.4–17.6: ARIA tree semantics, keyboard navigation, reduced motion. |
|---|---|
| Where | `/genealogy?view=binary` with a keyboard only |
| Evidence | `QA22G-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Navigate the tree with Tab / arrow keys; confirm focus moves between nodes.
2. Activate a node with Enter; confirm expand/collapse.
3. Confirm ARIA labels announce node role, side and points.

Expected

- The tree is fully keyboard-navigable with ARIA semantics.

### QA22G-09 — Responsive and compact mode

| Why | §11.1: `viewBox` + container sizing; compact list mode for narrow screens; print list. |
|---|---|
| Where | `/genealogy?view=binary` at 1440×900 and 390×844 |
| Evidence | `QA22G-09.png` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Screenshot at desktop: nodes side by side, controls visible.
2. Screenshot at mobile: cards stack, compact mode, no horizontal scroll.
3. Confirm every number remains legible at both widths.

Expected

- Responsive by container size; compact mode on narrow screens; printable list.

### QA22G-10 — Search respects privacy

| Why | §17.4: the search never surfaces a member outside the viewer's subtree. |
|---|---|
| Where | `/genealogy?view=binary` |
| Evidence | `QA22G-10.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Search for a member outside the viewer's subtree: confirm no result.
2. Search for a member inside: confirm the result highlights.

Expected

- Privacy-scoped search; no hint of out-of-subtree members.

### QA22G-11 — The sponsor view is byte-identical

| Why | Rule 2 / D-19: `/genealogy` without `?view=binary` is unchanged. |
|---|---|
| Where | `/genealogy` as a member |
| Evidence | `QA22G-11.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/genealogy` (no `view=binary`): confirm the sponsor-only team chain, direct-recruits table and team-by-level grid render exactly as at `p22f-member`.
2. Confirm no binary content leaked into the sponsor view.

Expected

- The sponsor view is byte-identical to the pre-22G release.

### QA22G-12 — Performance and query budget

| Why | §17.6: hard node/depth caps, lazy branches, indexed paths; a 2,000-node performance case. |
|---|---|
| Where | `system/bin/binary_place_tree.php` + `/genealogy?view=binary` on a large tree |
| Evidence | `QA22G-12.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Seed a large tree (2,000 nodes) via `binary_place_tree.php`.
2. Open the binary view: confirm first paint under budget and no runaway queries.
3. Confirm depth and node caps hold.

Expected

- The tree stays usable at scale; caps enforced.

---

## 13. Section I — Phase 22I: Release (`QA22I-01` … `QA22I-06`)

**Prerequisites.** Tag `p22h-operations`; all nine phase tags cut; `APP_PHASE === 22`; the app running. Every check in this section verifies the release record, not behaviour — 22I changes no code path. Expected suite count: **1,734** PHP cases (the floor), **≈300** smoke checks.

### QA22I-01 — The QA pack: sections A–H executed on the scratch database

| Why | P22I-T6: sections A–H present; every `QA22X-nn` unique; the evidence index matches the files. |
|---|---|
| Where | `docs/qa/phase-22-product-binary.md` + `docs/qa/evidence/phase-22/` |
| Evidence | `QA22I-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Confirm sections A, B, C, D, E, F, G, H exist in the pack, in order.
2. Confirm every check id is unique (`QA22A-01`…`QA22I-06`, no duplicates).
3. Confirm every `Evidence` row names a file that exists under `docs/qa/evidence/phase-22/`.
4. Execute every check in sections A–H on the scratch database; record Pass/Fail/Blocked per check.

Expected

- All sections present, all ids unique, all evidence files exist; results recorded per check.

### QA22I-02 — The acceptance run: Appendix B's 12 steps

| Why | P22I-T9 / QA22I-02: the twelve steps pass on a scratch database built from `p21g-release` + the branch, executed by someone who did not implement the feature. |
|---|---|
| Where | scratch schema + the running app |
| Evidence | `QA22I-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

Run Appendix B's 12 steps (migration + backfill; install parity; configuration; placement at registration; checkout position; payment and escrow; a pair; the tree; member page; admin pages; release; reversal and stop). Record each step's result.

Expected

- 12 / 12 steps pass; evidence committed for each step.

### QA22I-03 — Docs copy-paste verification

| Why | P22I-T7 / QA22I-03: every command in the docs runs; every key named in the docs exists in `settings`; every route named exists. |
|---|---|
| Where | `system/` + SQL + the running app |
| Evidence | `QA22I-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\unilevel_release.php --dry-run` → exit 0, lists due awards, credits nothing.
2. `php bin\binary_audit.php --check` → exit 0.
3. `php bin\binary_place_tree.php --check` → `0 written`, exit 0.
4. `php bin\wallet_reconcile.php` → exit 0.
5. `php bin\build_install_sql.php --check` → exit 0, no output.
6. `SELECT key_name FROM settings WHERE key_name LIKE 'binary\_%';` → exactly the ten keys named in the docs.
7. Confirm the one placement route (`POST /admin/members/{id}/placement`) is registered.

Expected

- Every documented command exits 0; every documented setting key exists; the one new route exists.

### QA22I-04 — Badges and phase: footer/sidebar reads 22

| Why | P22I-T1 / QA22I-04: `APP_PHASE` is 22; the phase badge renders 22 on settings and reports. |
|---|---|
| Where | `/admin/settings`, `/admin/reports`, footer/sidebar |
| Evidence | `QA22I-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Confirm `APP_PHASE === 22` in `system/app/config.php` and `HardeningTest`.
2. Open `/admin/settings` → Commissions → Product binary: the phase badge reads 22.
3. Open `/admin/reports`: the phase badge reads 22.

Expected

- `APP_PHASE` is 22 everywhere; the badge reads 22; `HardeningTest` is green.

### QA22I-05 — The phase log: nine entries, counts, deviations, hand-off

| Why | QA22I-05: `docs/phase-log.md` records all nine phases (22A–22I), the counts, the deviations and the hand-off. |
|---|---|
| Where | `docs/phase-log.md` |
| Evidence | `QA22I-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Confirm nine phase-log entries exist (22A through 22I).
2. Confirm each entry records its tag, dates, suite/smoke counts and deviations.
3. Confirm the 22I release entry is filled.

Expected

- Nine complete entries; counts match the measured release values; deviations noted; hand-off recorded.

### QA22I-06 — The sign-off sheet: every box ticked

| Why | QA22I-06: the release owner signs the Phase 22 block; the decision is recorded. |
|---|---|
| Where | `docs/qa/sign-off-sheet.md` |
| Evidence | `QA22I-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Confirm the Phase 22 block exists in the sign-off sheet.
2. Confirm every check row is ticked (Pass/Fail/Blocked recorded).
3. Confirm the Final decision is ticked (Approved / Approved with notes / Rejected).

Expected

- The Phase 22 block is complete and signed.

---

## 6. Cleanup

Section A creates no members, no QA rows and no money. If `QA22A-05` was run on a scratch schema, drop that schema or leave it — it is disposable. Nothing on the development copy changes. Sections B–I likewise create no lasting QA data on the development copy; every money-carrying check runs on a scratch schema.

**Prerequisites.** Tag `p22d-money` (the Phase 22D release gate); migration 026 applied; the Product binary awards of QA22D-01 … QA22D-03 present on the development copy; the app running. All of these checks are **read-only for money**: no controller, model or view in this phase writes an award, a volume row, a node balance or a wallet row (Rule 1). Expected suite count for this section: **≥ 1,668** PHP cases and **278** smoke checks.

### QA22E-01 — The binary ledger shows credited and pending awards

| Why | P22E-T1, P22E-T2, P22E-T3: the binary ledger tab renders its header counters, its award rows and its empty state. |
|---|---|
| Where | the running app as `admin` — `/admin/commissions?ledger=binary` |
| Evidence | `QA22E-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Open `/admin/commissions?ledger=binary` as `admin`.
2. Confirm the header shows the credited money, the pending liability and the award count from the QA22D awards (₱150.00 credited after release or pending before, 3 awards, 2 pairs).
3. Confirm the rows render the columns: Date, Kind, Member, Buyer, Product, Side, Points, Rate, Amount, Status, Order.

**Expected**

- The header totals match `Report::binarySummary()` for the fixture window exactly.
- The columns never mix points and money in a single total (Rule 4); points and money sit side by side.
- The page is read-only: no setting is changed.

---

### QA22E-02 — Filters narrow the ledger; a bad value falls back

| Why | P22E-T4: each filter narrows the ledger; two combined filters intersect; an unknown value falls back to "no filter" (Rule 7). |
|---|---|
| Where | the running app as `admin` — `/admin/commissions?ledger=binary&kind=pair`, then `&side=right` |
| Evidence | `QA22E-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Filter `kind=pair`: confirm exactly one row (member `c`, ₱100.00, 1 pair) remains.
2. Filter `side=right`: confirm the buyerB rows remain.
3. Combine `kind=pair&side=right`: confirm the intersection remains.
4. Filter with a bogus `kind` (e.g. `kind=bogus`): confirm it falls back to "no filter" and every row shows again.

**Expected**

- Each filter narrows correctly; combined filters intersect.
- An unknown `kind`, `status`, `side`, `product_id`, `user_id` or date means "no filter".

---

### QA22E-03 — A voided row shows recovered amount and unrecovered shortfall

| Why | P22E-T6: a voided row shows recovered amount and the header's *Unrecovered* equals the sum of shortfalls (Rule 6). |
|---|---|
| Where | the running app as `admin` — cancel a paid order, then reload `/admin/commissions?ledger=binary` |
| Evidence | `QA22E-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. As `admin`, cancel a paid order that has a binary award.
2. Reload `/admin/commissions?ledger=binary`.
3. Confirm the cancelled/voided row shows the recovered amount and `voided · ₱X recovered of ₱Y`.
4. Confirm the header's *Unrecovered* equals `Σ (amount − reversed_amount)` over voided rows.

**Expected**

- The recovered amount and unrecovered shortfall show exactly; nothing is hidden.

---

### QA22E-04 — Every row links to its order and its wallet movement

| Why | P22E-T8: every row links to its order and to the wallet movement (Rule 8). |
|---|---|
| Where | the running app as `admin` — click a binary ledger row's order link, then its wallet link |
| Evidence | `QA22E-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Click a row's order link on `/admin/commissions?ledger=binary`.
2. Confirm `/admin/orders/{id}` opens with the binary card showing the same numbers.
3. Back on the ledger, click the row's wallet link.
4. Confirm the wallet movement opens.

**Expected**

- A finance reviewer can walk from a number to the order and to the ledger row in one click each.

---

### QA22E-05 — The filtered CSV matches the screen

| Why | P22E-T7: the range export carries one row per filtered award; columns match the screen; an audit row records the filters. |
|---|---|
| Where | the running app as `admin` — Export CSV on `/admin/commissions?ledger=binary` (or `/admin/reports?type=binary`) |
| Evidence | `QA22E-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Apply a filter (e.g. `kind=self`) on the binary ledger.
2. Export CSV.
3. Open the CSV: confirm the header row matches the screen columns and one row per filtered award is present.
4. Confirm the values match the screen exactly.

**Expected**

- The CSV columns match the screen; every filtered award is one row; values agree with the screen.

---

### QA22E-06 — An unpaid or pre-22D order places nothing

| Why | P22E-T9, P22E-T10, P22E-T11: orders that cannot place points show an explanatory card, never rows. |
|---|---|
| Where | the running app as `admin` — open a `payment_review` order and an order paid before 22D |
| Evidence | `QA22E-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Open an order in `payment_review`: confirm the card explains nothing is placed yet.
2. Open an order paid before the Product binary stream was switched on: confirm the card says so.
3. Open an order paid with the stream off: confirm the card says no points were placed.

**Expected**

- No award or volume row is created for these orders; the card teaches why.

---

### QA22E-07 — The reports page and the dashboard tile

| Why | P22E-T14, P22E-T15: the five binary report cards show the same totals as the ledger; the range CSV carries the binary rows; the dashboard tile reads the last 30 days. |
|---|---|
| Where | the running app as `admin` — `/admin/reports` and the admin dashboard |
| Evidence | `QA22E-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Open `/admin/reports`.
2. Confirm the five binary cards (summary, self vs pair, by day, by product, readiness) show the same totals as the binary ledger.
3. Export the range CSV with `type=binary`; confirm the binary rows are present and the header names the stream.
4. Open the dashboard: confirm the **Product binary (30 d)** tile shows the last-30-days total.

**Expected**

- Report totals match the ledger (report totals match the ledger — the stop condition).
- The dashboard tile reads the last 30 days, never the current month's preview.

---

### QA22E-08 — The member panel shows the node and balances, read-only

| Why | P22E-T12, P22E-T13: the member binary panel shows this member's node, balances and blocked-pair reason; nothing is a button that changes a setting (Rule 10). |
|---|---|
| Where | the running app as `admin` — `/admin/members/{id}` for a placed member and for a member with no node |
| Evidence | `QA22E-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Open `/admin/members/{id}` for a placed member: confirm the binary panel shows the node (upline, side, depth, path), L/R balances, pairs, points matched, personal points, pairs today vs cap, and blocked-pair reasons when a recipient is inactive.
2. Open `/admin/members/{id}` for a member with no node: confirm "Not placed in the binary tree." with the console link.
3. Confirm *Open in tree* is disabled with a tooltip until 22G ships.

**Expected**

- The panel is counts and links, never actions (Rule 10). It shows this member's node only, never another member's upline chain (Rule 11).

---

### QA22E-09 — The other tabs are untouched

| Why | P22E-T1, P22E-T9: the Repeat-purchase bonus and Royalty tabs look and behave exactly as before (Rule 2). |
|---|---|
| Where | the running app as `admin` — `/admin/commissions` with `ledger=repeat_purchase` and `ledger=royalty` |
| Evidence | `QA22E-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Switch to the **Repeat-purchase bonus** tab: confirm its markup, filters, counts and export are byte-identical to `p22d-money`.
2. Switch to the **Royalty** tab: confirm it looks and behaves exactly as before.
3. Confirm no binary content leaked into either tab.

**Expected**

- The other three tabs are byte-identical to the 22D release; `P22E-T1` is a regression test, not a formality.

---

## 9. Section F — Phase 22F: Member visibility (`QA22F-01` … `QA22F-10`)

**Prerequisites.** Tag `p22e-admin` (the Phase 22E release gate); migration 026 applied; the Product binary awards of QA22D-01 … QA22D-03 present on the development copy; the app running. All of these checks are **read-only for money**: no controller, model or view in this phase writes an award, a volume row, a node balance or a wallet row. Expected suite count for this section: **≥ 1,694** PHP cases and **278** smoke checks.

### QA22F-01 — The binary tab renders with stats, self card, both team cards

| Why | P22F-T1, P22F-T2, P22F-T3: the binary tab is active, carries stats, the self bonus card and both Left/Right team cards. |
|---|---|
| Where | the running app as a member — `/earnings?ledger=binary` |
| Evidence | `QA22F-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. As `buyerA` after QA22D-01, open `/earnings?ledger=binary`.
2. Confirm the *Product binary* tab pill is active.
3. Confirm the stats tile, the *Your self bonus* card, the *Left team* card and the *Right team* card all render.
4. Confirm the self card shows *₱50.00 pending* and *You earn on your own purchase: 5% of its points*.

**Expected**

- Four tabs render (package, repeat, royalty, binary); the binary tab is active.
- Stats, progress, both team cards, the self card and the awards table all render.
- The tab names no upline, sponsor or ancestor (D-22).

---

### QA22F-02 — Left/Right team cards list the member's own team

| Why | P22F-T2, P22F-T3, P22F-T4: each side card shows its roster, own points, branch points and independent pagination. |
|---|---|
| Where | the running app as a member — `/earnings?ledger=binary` |
| Evidence | `QA22F-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. As `c` after QA22D-01..03, open `/earnings?ledger=binary`.
2. Confirm the Left team card lists the members under `c`'s left with their own points.
3. Confirm the Right team card lists `buyerB` under `c`'s right.
4. Confirm each row shows username, status badge, package, own points and branch points.
5. Confirm the pair progress shows matched points and the pair.

**Expected**

- Both sides work independently; points carry *pts*, money carries currency.
- No member outside the viewer's subtree is named, counted or hinted at (D-22).

---

### QA22F-03 — Independent per-side roster pagination

| Why | P22F-T2, P22F-T3: `left_page`/`left_per_page` and `right_page`/`right_per_page` move independently. |
|---|---|
| Where | the running app as a member — `/earnings?ledger=binary&left_page=2&left_per_page=5` |
| Evidence | `QA22F-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Page the left roster with `?ledger=binary&left_page=2&left_per_page=5`.
2. Confirm only the left card moves; the right card and the awards table keep their page.
3. Page the right side independently; confirm the left card is unaffected.

**Expected**

- Each side paginates independently with its own namespaced keys.
- Moving one side never moves the other side or the awards table.

---

### QA22F-04 — Desktop and mobile rendering

| Why | P22F-T22, P22F-T24: the two cards stack on mobile, no horizontal scroll, the roster stays readable, the checkout radios are full-width. |
|---|---|
| Where | the running app as a member — `/earnings?ledger=binary` at 1440×900 and 390×844 |
| Evidence | `QA22F-04.png` (screenshots) |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Screenshot `/earnings?ledger=binary` at 1440×900: the two cards sit side by side.
2. Screenshot at 390×844: the cards stack vertically, no horizontal page scroll.
3. Confirm every number remains legible at both widths.

**Expected**

- Desktop: two cards side by side (`col-xl-6`).
- Mobile: cards stack; roster readable; checkout radios full-width.

---

### QA22F-05 — Copy guard: no upline, sponsor, Team chain, level

| Why | P22F-T6 (D-22): the binary tab names no ancestor, no upline, no sponsor, no *Team chain*, no *level*. |
|---|---|
| Where | the running app as a member — `/earnings?ledger=binary` |
| Evidence | `QA22F-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Read the rendered HTML of `/earnings?ledger=binary`.
2. Confirm no ancestor username, no `upline`, no `sponsor`, no *Team chain*, no *Level N* appears anywhere in the binary tab.
3. Confirm *Left team*, *Right team*, *your points*, *pairs* are present.

**Expected**

- D-22 holds: downward-only copy, no upline surface.

---

### QA22F-06 — Checkout position card: side choice required when points exist

| Why | P22F-T9, P22F-T10, P22F-T11: the position card shows left/right radios, points, self, expected pairs; submitting without a side is refused. |
|---|---|
| Where | the running app as a member — `/checkout` with a points-bearing cart |
| Evidence | `QA22F-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Put 4 × P1 (P.V. ₱250, binary on) in the cart as `buyerA`.
2. Open `/checkout`: confirm the position card renders with two radios, *1000 pts*, *₱50.00* self and expected own-node pairs per side.
3. Choose *Right* and place the order with e-wallet: confirm the order page shows *Right · 1,000 pts*.
4. Try to place with eligible points and no side selected: confirm refusal with the `binary_position` field error; nothing is placed.

**Expected**

- Side is required only when eligible points exist; no-points/unplaced/off states are truthful.
- Member sees only their own self and own-node result, never upline awards.

---

### QA22F-07 — Order card: before and after payment

| Why | P22F-T13, P22F-T14, P22F-T15: the order card shows the side, points, self and own-node pairs after payment; an unpaid order explains it places points when paid; an order with no points shows no card. |
|---|---|
| Where | the running app as a member — `/orders/{id}` |
| Evidence | `QA22F-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Open an unpaid order: confirm the card says *This order places points when it is paid.*
2. Pay the order: confirm the card shows the side, the points and the pending self.
3. Open an order paid before the feature or with the stream off: confirm the honest absence message.

**Expected**

- The card is the buyer's view: side, points, self award, own-node pairs; no upline awards listed.

---

### QA22F-08 — Dashboard tile and product panel

| Why | P22F-T16, P22F-T17: the dashboard tile shows left/right points, next pair, earned this month; the product panel shows points per unit and self rate. |
|---|---|
| Where | the running app as a member — `/dashboard` and `/shop/{id}` |
| Evidence | `QA22F-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Open `/dashboard`: confirm the *Product binary* tile shows left/right points, the next pair and the binary earned this month; the link opens the earnings tab.
2. Open `/shop/{id}` for a binary product: confirm the panel shows *N points per unit* and the self rate.
3. For a product with the switch off or P.V. 0: confirm the panel is absent.

**Expected**

- The tile and product panel read the same points as the earnings tab.
- No panel when the product is not flagged or P.V. is 0.

---

### QA22F-09 — Session-scoped: a member cannot read another's tab

| Why | P22F-T23, P22F-T25: the statement carries the session member's id; a `?user_id=` query cannot widen it. |
|---|---|
| Where | the running app as two members |
| Evidence | `QA22F-09.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. As `buyerA`, open `/earnings?ledger=binary`: confirm only buyerA's awards appear.
2. As `buyerB`, open the same URL: confirm only buyerB's awards appear; buyerA's awards are absent.
3. Append `?user_id=` to the URL: confirm nothing changes (the model forces the session member's id).

**Expected**

- The page is session-scoped; another member's awards are never visible.

---

### QA22F-10 — Stream off: every binary affordance disappears

| Why | P22F-T8, P22F-T18, P22F-T20: with `binary_enabled = 0`, the tab pill, the checkout card and the product panel are absent; no dead affords. |
|---|---|
| Where | the running app as `admin` (Settings) and as a member |
| Evidence | `QA22F-10.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

**Steps**

1. Switch the stream off in Settings (`binary_enabled = 0`).
2. Reload `/earnings`: confirm the *Product binary* tab pill is absent; `/earnings?ledger=binary` falls back to the package tab with no error.
3. Reload `/checkout`, `/shop/{id}`, `/dashboard`: confirm no binary card, panel or tile appears.
4. Confirm the package, repeat and royalty tabs are unchanged.

**Expected**

- Every binary affordance disappears; the other three earnings tabs are byte-identical.

---

## 8. Section H — Phase 22H: Operations (`QA22H-01` … `QA22H-07`)

**Prerequisites.** Tag `p22g-release` (the Phase 22G release gate); migration 026 applied to the development copy (42 tables, 89 settings); the app running. Every check in this section is **read-only for money**: no binary award, volume, node or wallet row may be created here. Expected suite count for this section: **≥ 1,720** PHP cases and **278** smoke checks.

> **Where each check runs.** `QA22H-01` through `QA22H-06` need the running application (reads the development copy). `QA22H-07` runs from `system/` in PowerShell. Nothing in this section writes money, and the health checks never change a setting (plan Rule 10).

### QA22H-01 — The four binary health checks render on `/admin/reports`

| Why | P22H-T14: the health widget carries the four Product binary checks, each with a state, a summary and a remedy link. |
|---|---|
| Where | the running app as `admin` — `/admin/reports` (Health card) |
| Evidence | `QA22H-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/reports` as `admin`.
2. Confirm the Health card shows **twelve** checks: wallets, commissions, awards, royalty, transfers, payouts, orders, stock, **Product binary readiness**, **Product binary points**, **Product binary escrow**, **Product binary release job**.
3. Confirm each binary section renders a state (`pass`/`warn`/`fail`), a one-line summary and a remedy naming an executable command or page.

Expected

- Exactly twelve checks render; the four binary sections are present and read-only.
- No section is a button that changes a setting.
- No page errors and no new PHP warning lands in `storage/logs/`.

### QA22H-02 — Readiness: pass on a clean fixture, warn on zero-PV and unplaced

| Why | P22H-T14: binary readiness reports `pass` when the stream is on, flagged products earn points and every member has a node; it reports `warn` (never `fail`) for products with P.V. 0 and members with no node, and names each remedy. |
|---|---|
| Where | the running app as `admin` — `/admin/reports`, plus SQL on the development copy |
| Evidence | `QA22H-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On the development copy, confirm `binary_enabled = '1'` and flag one product with P.V. > 0; confirm every member has a `binary_nodes` row.
2. Reload `/admin/reports`: **Product binary readiness** reads `pass`; its summary names the stream on and no remedy is needed.
3. Flag one product on with P.V. `0` (direct row, or via the product form refused path): reload — readiness reads `warn` and its summary names *"earn 0 points — set a P.V. or switch them off."*
4. Create one member with no `binary_nodes` row: reload — readiness reads `warn` and its summary names *"no node — place them in the tree."*
5. Revert: set every flagged product's P.V. back above 0, switch off any product with P.V. 0, and give every member a node (or delete the scratch member).

Expected

- Step 2: `pass`, no `warn`/`fail` in the summary.
- Step 3 and 4: `warn` (state stays green), the summary names the remedy, and no setting was changed.
- Step 5: the fixture is back to clean; no money row exists.

### QA22H-03 — Points: pass on a clean fixture, fail on drift, `--fix` repairs

| Why | P22H-T14: binary points delegates to `BinaryService::audit()`; it reports `pass` when every cached balance equals the ledger and `fail` (with remedy `bin/binary_audit.php --fix`) on drift. |
|---|---|
| Where | the running app as `admin` — `/admin/reports`; SQL + `system/bin/binary_audit.php` on a scratch schema |
| Evidence | `QA22H-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. On a scratch schema with a placed member and no volume: reload `/admin/reports` — **Product binary points** reads `pass`; summary: *"every cached node balance equals the append-only ledger."*
2. Corrupt one cached balance (`UPDATE binary_nodes SET left_points = 999999 WHERE user_id = <member>`): reload — **Product binary points** reads `fail`; the summary names the drift count and the `--fix` remedy.
3. `php bin\binary_audit.php --fix --database=<scratch>` — exit 0.
4. Reload `/admin/reports` (against the scratch, or re-check): **Product binary points** reads `pass` again.
5. `SELECT COUNT(*) FROM binary_awards; SELECT COUNT(*) FROM binary_volume; SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type IN ('binary','binary_reversal');` — all **0** (`--fix` writes no money).

Expected

- Steps 1 and 4: `pass`; step 2: `fail` naming `bin/binary_audit.php --fix`; step 3 exit 0; step 5 zero money rows.

### QA22H-04 — Escrow: pending count, worth and oldest age

| Why | P22H-T14: binary escrow reports the pending award count, their total worth and the oldest pending age, with a `warn` remedy when anything is owed. |
|---|---|
| Where | the running app as `admin` — `/admin/reports`; SQL on the development copy |
| Evidence | `QA22H-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Confirm no pending binary award exists: `SELECT COUNT(*) FROM binary_awards WHERE status = 'pending';` → **0**; **Product binary escrow** reads `pass` (*no pending binary awards*).
2. On a scratch schema, insert one `pending` binary award (kind `pair`, amount ₱100.00, `created_at` ten days ago) for a placed member: reload — **Product binary escrow** reads `warn`; the summary names `1` pending award, worth ₱100.00, oldest `10` day(s), and the release-job remedy.
3. Delete the scratch award row.

Expected

- Step 1: `pass`, zero pending.
- Step 2: `warn`, count/amount/age shown exactly, remedy names `bin/unilevel_release.php --stream=binary`.
- Step 3: the scratch row is gone; no money row exists on the development copy.

### QA22H-05 — Release job: last run, from `CronLog`

| Why | P22H-T14: binary release job reads the last `unilevel_release` line from the monthly cron log and reports `pass` when it ran `stream=binary` without error, `warn` when it never ran or ran `stream=unilevel` only, `fail` on `[ERROR]`. |
|---|---|
| Where | `system/storage/logs/unilevel_release-YYYY-MM.log` plus `system/bin/unilevel_release.php` on a scratch schema |
| Evidence | `QA22H-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\unilevel_release.php --doctor` — exit 0; read `pending`, `oldest_pending`, `due_now`, `errors` (all green).
2. `php bin\unilevel_release.php --stream=binary --dry-run` — lists due binary awards, credits nothing; its own `[INFO ]` log line is written.
3. Run the job for real: `php bin\unilevel_release.php --stream=binary --limit=200` — exit 0; the monthly log gains an `[OK   ]` line with `stream=binary` and `errors=0`.
4. Reload `/admin/reports` — **Product binary release job** reads `pass`; summary names the last run credited N award(s).
5. Confirm no second cron entry exists for binary: crontab / Task Scheduler shows one `unilevel_release` entry (`--stream=all` or `--stream=binary`), never two.

Expected

- Steps 1–3: exit 0, the log line carries `stream=binary errors=0`.
- Step 4: `pass`, no `warn`/`fail`.
- Step 5: exactly one release-job schedule.

### QA22H-06 — Readiness panel links to the health panel (read-only)

| Why | P22B-T19 / 22H-S3: the readiness card ends with **Open the health panel**, which opens `/admin/reports`; nothing there changes a setting. |
|---|---|
| Where | the running app as `admin` — `/admin/settings` → Commissions → Product binary readiness; then `/admin/reports` |
| Evidence | `QA22H-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open **System → Settings → Commissions → Product binary readiness**.
2. Confirm the card ends with **Open the health panel** linking to `/admin/reports`.
3. Follow the link — `/admin/reports` opens with the Health card and the four binary sections.
4. Confirm no button, link or form on `/admin/reports` changes a setting (read-only page).

Expected

- The link exists, opens `/admin/reports`, and the health card renders the four binary checks with states and remedies.
- No setting is changed anywhere in steps 1–4.

### QA22H-07 — One release job, both streams; no second cron

| Why | The stop condition: health checks have executable remedies, commands are verified, and no second cron schedule is introduced. |
|---|---|
| Where | `system/bin/unilevel_release.php` on a scratch schema; crontab / Task Scheduler |
| Evidence | `QA22H-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\unilevel_release.php --stream=unilevel --dry-run` — lists due unilevel awards, credits nothing.
2. `php bin\unilevel_release.php --stream=binary --dry-run` — lists due binary awards, credits nothing.
3. `php bin\unilevel_release.php --stream=all --dry-run` — lists both, credits nothing; one log line per run.
4. Confirm `ECO_DB_NAME` points at the scratch schema and `wallet_reconcile.php` exits 0.
5. Count the `unilevel_release` cron entries in crontab / Task Scheduler: exactly one.

Expected

- Steps 1–3: exit 0 each, `--dry-run` credits nothing (all `binary_awards.status = 'pending'` before and after).
- Step 4: exit 0.
- Step 5: one schedule only — no second cron for binary.

---

## 6. Cleanup

Section A creates no members, no QA rows and no money. If `QA22A-05` was run on a scratch schema, drop that schema or leave it — it is disposable. Nothing on the development copy changes.
