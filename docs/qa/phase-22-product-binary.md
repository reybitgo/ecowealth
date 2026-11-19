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

## 8. Section E — Phase 22E: Admin visibility (`QA22E-01` … `QA22E-09`)

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

## 6. Cleanup

Section A creates no members, no QA rows and no money. If `QA22A-05` was run on a scratch schema, drop that schema or leave it — it is disposable. Nothing on the development copy changes.
