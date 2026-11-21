# EcoWealth — Phase 23A QA: Foundation (`QA23A-01` … `QA23A-06`)

This document is section **A only** of Phase 23A — Foundation. Phase 23A is a foundation release:
it adds the package-binary schema columns, the three `package_binary_*` settings, the idempotent
migration `027_package_binary.sql`, and the shared `source`/`activation_id` vocabulary. It moves no
money, ships no settings card and no member panel. Everything it adds is inert until Phase 23C wires
the engine.

**Status:** in progress (23A). Sections B–G are appended by their phases; the gate opens at 23G.

> **Where each check runs.** `QA23A-01` takes the backup on the development copy; `QA23A-02`, `03`,
> `04`, `05` and `06` run on scratch schemas provisioned from `system/database/install.sql`. No check
> touches `ecowealth_sneat`.

> **Notation.** `mysql` means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the app
> account; `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`.

## Prerequisites

- Tag `p23-start` on `feature/package-binary` (the baseline before 23A); the development copy at
  migration **026** (42 tables, 89 settings); the app running only if you want to eyeball the admin
  settings page; the scratch schemas
  `ecowealth_sneat_tool_a_test` and `ecowealth_sneat_tool_b_test` provisioned (the tools recreate
  them themselves).

### QA23A-01 — Back up the development copy and confirm it is at migration 026

| Why | 23A is the first schema change of Phase 23; the standing rule is a dump outside the repository before any migration. |
|---|---|
| Where | PowerShell + `mysqldump` |
| Evidence | `QA23A-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `mysqldump -u ecowealth -p --single-transaction --routines --databases ecowealth_sneat > C:\laragon\www\ecowealth-backups\phase-23\ecowealth_sneat-pre-23a-<stamp>.sql`
2. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ecowealth_sneat'"`
3. `mysql -u ecowealth -p -N -e "SELECT COUNT(*) FROM ecowealth_sneat.settings"`
4. `mysql -u ecowealth -p -e "SHOW TABLES LIKE 'binary%'" ecowealth_sneat`

Expected

- The dump exists, is non-empty and lives **outside** the repository.
- **42** tables and **89** settings (the development copy was built by migrations `001`–`026`, so it
  carries no `install_*` metadata rows; a fresh install holds **92**).
- `SHOW TABLES` returns `binary_nodes`, `binary_volume`, `binary_awards` — the Phase 22 tables exist;
  no package-binary column exists yet.

### QA23A-02 — Apply migration 027 twice to a p23-start schema

| Why | Rule 1: the migration is additive and idempotent. A second run must be a no-op. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_a_test` |
| Evidence | `QA23A-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Build a **p23-start** scratch schema from this tag's bundle:
   `git show p23-start:system/database/install.sql | mysql -u ecowealth -p ecowealth_sneat_tool_a_test`
2. Confirm the baseline: `SHOW TABLES LIKE '%binary%'` → exactly `binary_nodes`, `binary_volume`,
   `binary_awards`; `SHOW COLUMNS FROM packages LIKE 'binary_enabled'` → no row.
3. `mysql -u ecowealth -p ecowealth_sneat_tool_a_test < database/migrations/027_package_binary.sql`
4. `SHOW COLUMNS FROM packages LIKE 'binary%';` → `binary_enabled`, `binary_points`.
   `SHOW COLUMNS FROM activations LIKE 'binary%';` → `binary_processed_at`, `binary_points`,
   `binary_total`; `SHOW INDEX FROM activations WHERE Key_name = 'idx_activations_binary'` → one row.
   `SHOW COLUMNS FROM binary_awards LIKE 'source';` → `source`, `activation_id`.
   `SHOW COLUMNS FROM binary_volume LIKE 'source';` → `source`, `activation_id`.
5. Run the same file a **second** time.
6. Re-run step 4's `SHOW` commands; then `SELECT COUNT(*) FROM settings WHERE key_name LIKE 'package\_binary\_%'`.

Expected

- Step 2 shows the three Phase 22 binary tables and no package-binary column.
- Steps 3 and 4 add the columns, the index and the three settings exactly once.
- The second run exits **0** with no error and changes nothing: same table list, same column list,
  same settings count.
- Step 6 returns exactly **3** rows:
  `package_binary_enabled = 0`, `package_binary_requires_package = 1`,
  `package_binary_ledger_since = <today's date-time>`.

### QA23A-03 — The fresh install reports 42 tables / 92 settings

| Why | Rule 5 / P23A-T5-T6: the committed bundle equals its sources (`build_install_sql --check`), and a fresh install reports the new counts. |
|---|---|
| Where | PowerShell on `system/` |
| Evidence | `QA23A-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin\build_install_sql.php` (rebuild) and `php bin\build_install_sql.php --check` (must exit 0).
2. `php bin\db_install.php --check --database=ecowealth_sneat_tool_a_test`
3. `php bin\db_install.php --database=ecowealth_sneat_tool_a_test --force --admin-password=QaAdmin!23456 --superadmin-password=QaSuper!23456`
4. `php bin\wallet_reconcile.php`

Expected

- `--check` exits **0** with no output; the header reads **42 tables · 92 settings · 10 product binary level amounts**.
- The install preflight reports every check OK.
- The install verifies and prints `tables_created = 42`, `settings_rows = 92`, `staff_rows = 2`, `members_rows = 0`, `products_rows = 7`, `packages_rows = 2`, `levels_rows = 18`, `contents_rows = 10`, `codes_rows = 0`.
- `wallet_reconcile.php` exits **0**; the package-binary stream is not part of the reconciler yet.

### QA23A-04 — Every package keeps the binary master switch off

| Why | D-13 / P23A-T7: a deployment never starts paying. The master switch and the per-package switch default to OFF on migrated and fresh databases alike. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_a_test` (fresh install from step 3 above) |
| Evidence | `QA23A-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT id, name, slug, binary_enabled, binary_points FROM packages ORDER BY id;`

Expected

- Exactly **2** rows (Package A, Package B) and every one reports `binary_enabled = 0`,
  `binary_points = 0`.
- No package has been given points by the migration: `SUM(binary_points) = 0`.

### QA23A-05 — Legacy activations are grandfathered (binary columns absent)

| Why | Rule 8: an activation approved before 23A is never awarded. Its `binary_*` columns take the safe default and nothing is backfilled. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_a_test` (fresh install from step 3 above) |
| Evidence | `QA23A-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SELECT id, user_id, package_id, status, binary_processed_at, binary_points, binary_total FROM activations WHERE status = 'approved' LIMIT 5;`

Expected

- Every pre-23A activation reports `binary_processed_at IS NULL`, `binary_points = 0`,
  `binary_total = 0.00`.
- The migration never writes a `binary_awards` or `binary_volume` row from legacy activations:
  `SELECT COUNT(*) FROM binary_awards WHERE source = 'activation'` → `0`.

### QA23A-06 — The source constraints accept both sources and reject mixes

| Why | D-13 / §13.5: `chk_ba_source` / `chk_bv_source` gate every row so an order award always carries its order and an activation award always carries its activation — never both, never neither. |
|---|---|
| Where | `mysql` on `ecowealth_sneat_tool_a_test` (fresh install) |
| Evidence | `QA23A-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `SHOW CREATE TABLE binary_awards;` → note `chk_ba_source`; `SHOW CREATE TABLE binary_volume;` → note `chk_bv_source`.
2. Try to insert a `binary_awards` row with `source = 'order'` and `order_id = NULL` → must fail.
3. Try to insert a `binary_awards` row with `source = 'activation'` and `order_id IS NOT NULL` → must fail.
4. Try to insert a `binary_volume` row with `source = 'order'` and both `order_id`/`order_item_id` NULL → must fail.
5. Try to insert a `binary_volume` row with `source = 'activation'` and `activation_id IS NULL` → must fail.

Expected

- Both `CREATE TABLE` outputs carry `chk_ba_source` / `chk_bv_source` exactly as written in
  `system/database/migrations/027_package_binary.sql` (no `NULL`/`NULL` hybrid, no missing source).
- Steps 2–5 each fail with a CHECK-constraint violation (the row is not written): the database,
  not PHP, is the anchor.
- A well-formed order row (`source = 'order'`, `order_id` set, `activation_id` NULL) inserts cleanly,
  and a well-formed activation row (`source = 'activation'`, `activation_id` set, `order_id` NULL)
  inserts cleanly.

## What is already automated

Section A's schema and count claims are also asserted by the automated suite:

- `MigrationTest` — migration `027` re-runs cleanly; migrated ≡ fresh for
  `binary_nodes`, `binary_volume`, `binary_awards`; `ref_type` gains nothing new (no new movement
  kind yet).
- `InstallTest` — fresh install reports **42 / 92 / 2**.
- `InstallSqlTest` — `build_install_sql --check` exits 0.
- `BinaryFoundationTest` — binary vocabulary is confined to the manifest in `dmp/plan-ecowealth-product-binary.md` §15.6; `Package.php`, `BinaryAward.php` and `BinaryVolume.php` are on the list.

## Evidence index

| Check | Evidence file |
|---|---|
| QA23A-01 | `evidence/phase-23/QA23A-01.txt` |
| QA23A-02 | `evidence/phase-23/QA23A-02.txt` |
| QA23A-03 | `evidence/phase-23/QA23A-03.txt` |
| QA23A-04 | `evidence/phase-23/QA23A-04.txt` |
| QA23A-05 | `evidence/phase-23/QA23A-05.txt` |
| QA23A-06 | `evidence/phase-23/QA23A-06.txt` |
