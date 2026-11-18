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

## 6. Cleanup

Section A creates no members, no QA rows and no money. If `QA22A-05` was run on a scratch schema, drop that schema or leave it — it is disposable. Nothing on the development copy changes.
