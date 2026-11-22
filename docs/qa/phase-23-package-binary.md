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

---

## Section B — Phase 23B: Configuration and readiness (`QA23B-01` … `QA23B-06`)

**Status:** in progress (23B). Section B is appended by 23B-S2; sections C–G follow their phases.

> **Where each check runs.** `QA23B-01` to `QA23B-05` run against the development copy at migration 027;
> `QA23B-06` runs on a scratch schema provisioned from `system/database/install.sql`. No check touches
> `ecowealth_sneat`.

> **Notation.** `mysql` means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` with the app
> account; `php` means Laragon's PHP 8.3 CLI. Every command runs from `system/`.

### QA23B-01 — The Package binary card renders on the package edit page

| Why | 23B Rules 1–2: the switch and the points field render on every package form; the switch ships off and the points field suggests `ROUND(product_value)` but does not save itself (D-13). |
|---|---|
| Where | Browser: `/admin/packages/{id}/edit` (Package A) |
| Evidence | `QA23B-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open Package A's edit page.
2. Confirm the **Package binary** card sits after the referral ladder.
3. Confirm the switch is **off** and the points field shows the suggested value (`ROUND(product_value)`) as a prefill/placeholder only.
4. Confirm the live readout shows the shared preset (pair value at PP 1,000 / 10%).

Expected

- The card renders with exactly two inputs: the `binary_enabled` switch and the `binary_points` integer field.
- No pair-size, pair-rate, cap or hold input exists anywhere in the HTML (P23B-T12).
- The switch is off; nothing is persisted yet.

### QA23B-02 — Saving a flagged package persists switch and points

| Why | P23B-T1: `binary_enabled = 1` with `binary_points > 0` persists; the form re-reads both on re-open. |
|---|---|
| Where | Browser: `/admin/packages/{id}/edit` (Package A) |
| Evidence | `QA23B-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set the switch to **on**.
2. Set points to `1000`.
3. Save.
4. Re-open the package edit page.

Expected

- Both values persist: switch on, points `1000`.
- The list badge on `/admin/packages` reads `Binary 1,000 pts` (P23B-T8).
- `SELECT binary_enabled, binary_points FROM packages WHERE id = <A>` → `1`, `1000`.

### QA23B-03 — Switch on with zero points is refused

| Why | P23B-T2 / Rule 3: `binary_enabled = 1` with `binary_points = 0` is a configuration error, refused with a field error on `binary_points`; the row is left untouched. |
|---|---|
| Where | Browser: `/admin/packages/{id}/edit` (Package A, already at 1000 points) |
| Evidence | `QA23B-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Keep the switch **on**.
2. Clear the points field to `0`.
3. Save.

Expected

- A field error on `binary_points` is shown.
- The package keeps `binary_points = 1000` (row unchanged).
- No `package.binary.update` audit row is written (nothing changed).

### QA23B-04 — The `?binary=on|off` filter and the readiness counts

| Why | P23B-T9 / T13: the list filter narrows to flagged / unflagged packages; the readiness panel's four counts match SQL and the links filter correctly. |
|---|---|
| Where | Browser: `/admin/packages?binary=on` and `/admin/settings` → Commissions |
| Evidence | `QA23B-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/packages?binary=on`: confirm only Package A (flagged) appears.
2. Open `/admin/packages?binary=off`: confirm only Package B (unflagged) appears.
3. Open `/admin/settings` → Commissions → Package binary readiness panel.
4. Compare the four counts against `SELECT binary_enabled, binary_points, COUNT(*) FROM packages GROUP BY binary_enabled, binary_points`.

Expected

- `?binary=on` returns exactly the flagged packages; `?binary=off` returns the rest; the filter survives pagination and combines with `?status=`.
- The readiness panel shows: packages flagged with 0 points, packages with the switch off, the global switch state, and the preset sentence.
- Each count links to the correctly filtered list; none is a button (Rule 7).

### QA23B-05 — The settings card saves the master switch and the qualification toggle

| Why | P23B-T11 / Rule 5: the master switch (`package_binary_enabled`) and the recipient-qualification toggle (`package_binary_requires_package`) render under the product-binary block; `package_binary_ledger_since` is never rendered. A save audits `settings.update` only when something changed. |
|---|---|
| Where | Browser: `/admin/settings` → Commissions → Package binary; then `/admin/audit` |
| Evidence | `QA23B-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/settings` → Commissions.
2. Confirm the **Package binary** sub-block sits **under** the **Product binary** block.
3. Confirm the master switch is off and the qualification toggle is on.
4. Confirm `package_binary_ledger_since` is **not** rendered anywhere in the card.
5. Switch the master **on**, leave the qualification toggle **on**, save.
6. Open `/admin/audit`: confirm a `settings.update` row for the two package binary keys with before/after.
7. Switch the master **off**, save.

Expected

- The package block renders with exactly two toggles and no editable fields (no points input — points are per-package on the package form).
- `package_binary_ledger_since` is never rendered (Rule 5).
- Saving with the master on writes a `settings.update` audit row; saving with no change writes nothing (Rule 10).
- Turning the master off changes no money (D-13).

### QA23B-06 — An approval after 23B writes no binary row

| Why | P23B-T15 / Rule: no code path reads the new columns until 23C; an approved activation still pays only the referral ladder. |
|---|---|
| Where | Browser: `/admin/activations/{id}/approve` (a binary-flagged package); then `mysql` |
| Evidence | `QA23B-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Configure Package A with `binary_enabled = 1`, `binary_points = 1000`.
2. Approve an activation of Package A (master switch on or off — either way, no engine is wired yet).
3. Query `binary_awards`, `binary_volume` and `activations`.

Expected

- `SELECT COUNT(*) FROM binary_awards WHERE source = 'activation'` → `0`.
- `SELECT COUNT(*) FROM binary_volume WHERE source = 'activation'` → `0`.
- `activations.binary_processed_at IS NULL`, `binary_points = 0`, `binary_total = 0.00`.
- The referral ladder pays exactly as before 23B (no regression).

## What is already automated

Section B's claims are also asserted by the automated suite:

- `PackageBinaryTest` — P23B-T1 … T18: validation, persistence, audit, status map, list badge, filter, form defaults, settings card, readiness panel, preset sentence and the no-binary-row-on-activation guard.
- `SettingsPageTest` — the commissions card catalogs include the two package binary keys.
- `BinaryFoundationTest` — the Phase 22 vocabulary manifest lists the package-binary files.

## Evidence index

| Check | Evidence file |
|---|---|
| QA23A-01 | `evidence/phase-23/QA23A-01.txt` |
| QA23A-02 | `evidence/phase-23/QA23A-02.txt` |
| QA23A-03 | `evidence/phase-23/QA23A-03.txt` |
| QA23A-04 | `evidence/phase-23/QA23A-04.txt` |
| QA23A-05 | `evidence/phase-23/QA23A-05.txt` |
| QA23A-06 | `evidence/phase-23/QA23A-06.txt` |
| QA23B-01 | `evidence/phase-23/QA23B-01.txt` |
| QA23B-02 | `evidence/phase-23/QA23B-02.txt` |
| QA23B-03 | `evidence/phase-23/QA23B-03.txt` |
| QA23B-04 | `evidence/phase-23/QA23B-04.txt` |
| QA23B-05 | `evidence/phase-23/QA23B-05.txt` |
| QA23B-06 | `evidence/phase-23/QA23B-06.txt` |

---

## Section C — Phase 23C: Money engine (`QA23C-01` … `QA23C-08`)

**Status:** in progress (23C). Section C is appended by 23C-S5; sections D–G follow their phases.

> **Where each check runs.** `QA23C-01` to `QA23C-08` run on a scratch schema provisioned from
> `system/database/install.sql` with migration 027 applied, Package A flagged `binary_enabled = 1`
> with `binary_points = 1000`, the global `package_binary_enabled = 1`, and `package_binary_requires_package = 1`.
> The fixture tree is `admin → boss → a → b → c → {buyerA, buyerB}`. No check touches `ecowealth_sneat`.

> **Notation.** `mysql` means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`; `php` means Laragon's PHP 8.3 CLI.
> Every command runs from `system/`.

### QA23C-01 — First activation places team volume, no own row, no award

| Why | P23C-T1 / Rule 6: an approved activation of a binary-enabled package places `binary_points` once, team-only, on every ancestor; no own row and no award until a pair completes. |
|---|---|
| Where | `mysql` + `/admin/activations/{id}/approve` (buyerA, Package A) |
| Evidence | `QA23C-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Approve buyerA's activation of Package A (1,000 points).
2. `SELECT * FROM binary_volume WHERE source = 'activation' ORDER BY node_user_id, side;`
3. `SELECT COUNT(*) FROM binary_volume WHERE activation_id = <aid> AND kind = 'own';`
4. `SELECT COUNT(*) FROM binary_awards WHERE activation_id = <aid>;`
5. `SELECT binary_points, binary_total, binary_processed_at FROM activations WHERE id = <aid>;`

Expected

- Five `team` rows: c (left), b (left), a (left), boss (left), root (right) — each 1,000 points, `source = 'activation'`.
- `kind = 'own'` count → `0`.
- `binary_awards` count → `0` (all sides are left, no pair).
- `binary_points = 1000`, `binary_total = 0.00`, `binary_processed_at` is not NULL.

### QA23C-02 — Second activation completes one pair, ₱100 credited immediately

| Why | P23C-T2 / Rule 11: buyerB (right of c) activates → c has L=1000/R=1000 → one pair, ₱100.00 credited to c's withdrawable wallet in the same transaction. |
|---|---|
| Where | `/admin/activations/{id}/approve` (buyerB, Package A) + `mysql` |
| Evidence | `QA23C-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set c's package to Package A (binary-enabled) so c qualifies.
2. Approve buyerB's activation of Package A.
3. `SELECT id, kind, user_id, status, hold_days, source, activation_id, order_id, ledger_id, credited_at FROM binary_awards WHERE activation_id = <aidB>;`
4. `SELECT withdrawable_balance FROM ewallet_balance WHERE user_id = <c>;`
5. `SELECT * FROM ewallet_ledger WHERE ref_type = 'binary' AND ref_id = <award_id>;`
6. `php bin/binary_audit.php --check`

Expected

- One `pair` award at c: `status = 'credited'`, `hold_days = 0`, `source = 'activation'`, `activation_id = <aidB>`, `order_id IS NULL`, `ledger_id` and `credited_at` set.
- c's withdrawable wallet is up ₱100.00 with one `binary` ledger row (`ref_id = award.id`).
- `binary_audit --check` exits 0.

### QA23C-03 — Release job ignores credited package awards

| Why | P23C-T32 / D-07: package awards are `credited` at creation; the release job must never see them as pending and must not move them again. |
|---|---|
| Where | `php bin/unilevel_release.php --stream=all` |
| Evidence | `QA23C-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. After QA23C-02, note the package award's `ledger_id`.
2. `php bin/unilevel_release.php --stream=all`
3. `SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'binary' AND ref_id = <award_id>;`

Expected

- The release job reports no binary awards released (or releases only product awards).
- The package award's ledger row count is still 1 — no second movement.
- The award remains `credited`.

### QA23C-04 — Non-qualified recipient: no pair, no consumption, audit names the reason

| Why | P23C-T22 / Rule 10: when `package_binary_requires_package = 1`, a recipient without a binary-enabled package is skipped; volume flows but no points are consumed. |
|---|---|
| Where | `/admin/packages/{id}/edit` (switch c off) + `/admin/activations/` + `mysql` |
| Evidence | `QA23C-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Switch Package A's `binary_enabled` to 0 (or put c on a non-binary package).
2. Approve two activations of Package A (buyerA left of c, buyerB right of c).
3. `SELECT left_points, right_points FROM binary_nodes WHERE user_id = <c>;`
4. `SELECT COUNT(*) FROM binary_awards WHERE activation_id IN (<aidA>, <aidB>);`
5. `php bin/binary_audit.php --check` — the blocked pair is not a drift.

Expected

- c still has L=1000/R=1000 (volume placed but not consumed — no pair).
- No `binary_awards` rows for either activation.
- The audit exits 0; `blockedMap()` names c with the package reason.

### QA23C-05 — Daily cap: one pair pays, the second carries

| Why | P23C-T33 / Rule 9: `binary_daily_pair_cap` bounds pairs per recipient per day; over-cap volume carries, it is not flushed. |
|---|---|
| Where | `/admin/settings` (cap = 1) + `/admin/activations/` + `mysql` |
| Evidence | `QA23C-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set `binary_daily_pair_cap = 1`.
2. Approve buyerA's and buyerB's activations (both complete a pair at c) — the first pays ₱100, the second carries.
3. Confirm c's wallet is up exactly ₱100.00 and c's points are 0/0 after the first pair.
4. Approve a third activation right of c the same day — no pair, points carried.
5. Reset `binary_daily_pair_cap = 20`.

Expected

- Exactly one pair paid today; the second activation's points carry to the next day.
- No points are lost (no flush).

### QA23C-06 — Mixed sources: product order and package activation in one balance

| Why | P23C-T42 / D-06: one tree, one balance — product volume and package volume can pair across sources, and the ledger splits them exactly. |
|---|---|
| Where | `/checkout` (product order) + `/admin/activations/` + `mysql` |
| Evidence | `QA23C-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Pay a product order of a binary-enabled product that places 1,000 points left at c.
2. Approve a package activation of Package A that places 1,000 points right at c.
3. `SELECT source, SUM(points) FROM binary_volume WHERE node_user_id = <c> AND kind = 'team' GROUP BY source;`
4. `SELECT id, source, amount, status FROM binary_awards WHERE user_id = <c> AND kind = 'pair';`

Expected

- One pair award, ₱100.00, with volume rows of both `source = 'order'` and `source = 'activation'` in c's balance.
- The product rows carry `source = 'order'`; the package rows carry `source = 'activation'`.

### QA23C-07 — Reversal: dry run, then apply, then idempotent repeat

| Why | P23C-T45–T48 / D-11: the support CLI voids awards, claws back the wallet, reverses volume, and is idempotent. |
|---|---|
| Where | `php bin/package_binary_reverse.php --activation=<id>` (dry run, then `--apply`, then again) |
| Evidence | `QA23C-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. `php bin/package_binary_reverse.php --activation=<aidB>` — dry run prints the award, points, wallet effect; exits 0; writes nothing.
2. `php bin/package_binary_reverse.php --activation=<aidB> --apply` — award voided, wallet debited ₱100.00 (`binary_reversal` movement), volume reversed at every node, pair-return rows written, activation total recomputed to 0.00.
3. `php bin/binary_audit.php --check` → exit 0.
4. `php bin/package_binary_reverse.php --activation=<aidB> --apply` again → no change; no duplicate rows.

Expected

- Dry run writes nothing; apply is atomic and idempotent; the audit is clean after each step.

### QA23C-08 — Zero-point and disabled packages stamp zeros, referral ladder untouched

| Why | P23C-T7–T9 / Rule 5: an activation of a 0-point or disabled package (or with the global switch off) stamps zeros and places nothing; the referral ladder still pays. |
|---|---|
| Where | `/admin/packages/{id}/edit` + `/admin/activations/` + `mysql` |
| Evidence | `QA23C-08.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set Package A to `binary_points = 0`, `binary_enabled = 1`; approve an activation → stamps 0/0.00, no binary rows.
2. Set Package A to `binary_enabled = 0`, `binary_points = 1000`; approve an activation → same.
3. Set `package_binary_enabled = 0`; approve an activation → same.
4. Restore Package A to `binary_enabled = 1`, `binary_points = 1000`, global switch on.
5. Confirm the referral ladder (direct + levels 2–10) paid the same amounts as before.

Expected

- All three cases stamp `binary_points = 0`, `binary_total = 0.00`, `binary_processed_at` not NULL.
- `binary_awards` and `binary_volume` gain no activation-sourced rows.
- The referral ladder is unaffected.

## What is already automated

Section C's claims are also asserted by the automated suite:

- `PackageBinaryTest` — P23C-T1 … T50: the event and its paths, the routing and team-only rule, the qualification gate, the money/cap/wallet, idempotency, transaction safety, the product regression, reconciliation, audit, and the reversal CLI.
- `BinaryService::audit()` — INV-P1 … INV-P7 (package-pending award, activation own row, package self award, package root award, stamped-no-volume, stamp-vs-volume, stamp-vs-total).

## Evidence index

| Check | Evidence file |
|---|---|
| QA23A-01 | `evidence/phase-23/QA23A-01.txt` |
| QA23A-02 | `evidence/phase-23/QA23A-02.txt` |
| QA23A-03 | `evidence/phase-23/QA23A-03.txt` |
| QA23A-04 | `evidence/phase-23/QA23A-04.txt` |
| QA23A-05 | `evidence/phase-23/QA23A-05.txt` |
| QA23A-06 | `evidence/phase-23/QA23A-06.txt` |
| QA23B-01 | `evidence/phase-23/QA23B-01.txt` |
| QA23B-02 | `evidence/phase-23/QA23B-02.txt` |
| QA23B-03 | `evidence/phase-23/QA23B-03.txt` |
| QA23B-04 | `evidence/phase-23/QA23B-04.txt` |
| QA23B-05 | `evidence/phase-23/QA23B-05.txt` |
| QA23B-06 | `evidence/phase-23/QA23B-06.txt` |
| QA23C-01 | `evidence/phase-23/QA23C-01.txt` |
| QA23C-02 | `evidence/phase-23/QA23C-02.txt` |
| QA23C-03 | `evidence/phase-23/QA23C-03.txt` |
| QA23C-04 | `evidence/phase-23/QA23C-04.txt` |
| QA23C-05 | `evidence/phase-23/QA23C-05.txt` |
| QA23C-06 | `evidence/phase-23/QA23C-06.txt` |
| QA23C-07 | `evidence/phase-23/QA23C-07.txt` |
| QA23C-08 | `evidence/phase-23/QA23C-08.txt` |

---

## Section D — Phase 23D: Admin visibility (`QA23D-01` … `QA23D-07`)

**Status:** in progress (23D). Section D is appended by 23D-S2; sections E–F follow their phases.

> **Where each check runs.** `QA23D-01` to `QA23D-07` run against the development copy at migration 027,
> with at least one package flagged `binary_enabled = 1` with `binary_points > 0` and at least one
> approved activation of that package. No check touches `ecowealth_sneat`.

> **Notation.** `mysql` means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`; `php` means
> Laragon's PHP 8.3 CLI. Every command runs from `system/`.

### QA23D-01 — The Source column and the source-aware stats

| Why | P23D-T1 / Rule 2: every row in `/admin/commissions?ledger=binary` carries a Source column; the stats split credited/pending and pairs/points by source. |
|---|---|
| Where | Browser: `/admin/commissions?ledger=binary` |
| Evidence | `QA23D-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/commissions?ledger=binary`.
2. Confirm the table header has a **Source** column between Side and Points.
3. Confirm order-source rows read **Repeat purchase** and activation-source rows read **Package activation**.
4. Confirm the stats cards show **Package pairs** and **Package points** beside the product-sourced cards.

Expected

- The Source column header renders and every row shows `Repeat purchase` or `Package activation` (never `—` when both sources exist).
- The Package pairs card reads `n pairs · ₱m.m0 credited`; the Package points card reads `n pts`.
- Product-sourced cards keep their Phase 22 values (byte-identical except the added Source column).

### QA23D-02 — The source filter narrows and falls back

| Why | P23D-T2 / Rule 6: `?source=activation` shows only activation rows; `?source=order` shows only order rows; `?source=` shows all; an unknown value falls back to all. |
|---|---|
| Where | Browser: `/admin/commissions?ledger=binary&source=activation` then `&source=order` then `&source=bogus` |
| Evidence | `QA23D-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/commissions?ledger=binary&source=activation`: confirm only Package activation rows appear.
2. Open `/admin/commissions?ledger=binary&source=order`: confirm only Repeat purchase rows appear.
3. Open `/admin/commissions?ledger=binary&source=`: confirm all rows appear.
4. Open `/admin/commissions?ledger=binary&source=bogus`: confirm all rows appear (silent fallback).

Expected

- The filter dropdown selects the chosen source; the table rows match it exactly.
- An unknown `source` value silently returns all rows — no error, no empty state.

### QA23D-03 — The activation card shows the package binary outcome

| Why | P23D-T5 / Rule 8: an activation's card shows points placed, pairs paid, money credited, status and wallet links; a 0-point package explains why nothing was placed. |
|---|---|
| Where | Browser: `/admin/activations` (queue) — look for activations with binary data |
| Evidence | `QA23D-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Approve an activation of a binary-enabled package (QA23C-01/QA23C-02 setup).
2. Open `/admin/activations`: find the activation in the queue.
3. Confirm the row shows `Binary: N pts · ₱m.m0 · Mmm d` (points, total, processed date).
4. Below the table, confirm the **Package binary** card renders:
   - Points placed, pairs paid, amount paid, volume rows, award rows, processed at, activation status.
   - Wallet links on each pair award row (`#<ledger_id>`).
5. Open an activation of a 0-point or disabled package: the card says *Nothing placed* with the reason.

Expected

- Processed activations show a Package binary card with points, pairs, money and wallet links.
- A 0-point/disabled/stream-off activation shows *Nothing placed* with the reason (stream off / package disabled / not placed).
- Every pair award row links to its wallet movement.

### QA23D-04 — The member panel split by source

| Why | P23D-T6 / Rule 4: the admin member panel shows product vs package points/pairs/earnings side by side, plus the qualification state. |
|---|---|
| Where | Browser: `/admin/members/{id}` — a member with both product and package binary activity |
| Evidence | `QA23D-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/members/{id}` for a member who has received both product pairs and package pairs.
2. Confirm the Product binary panel shows a source split: **Product points** / **Package points**, **Product pairs** / **Package pairs**, **Product earned** / **Package earned**.
3. Confirm the Recent volume table has a **Source** column reading *Product* or *Package*.
4. Confirm the Recent awards table has a **Source** column reading *Product* or *Package*.
5. Confirm the **Package qualification** badge reads *Binary package — earns package pairs* or *No binary package — package activations place no points here*.

Expected

- The split numbers match `SELECT source, SUM(points) FROM binary_volume WHERE node_user_id = ? GROUP BY source` and the equivalent award query.
- Volume rows show `Package` when `source = 'activation'`, `Product` otherwise.
- The qualification badge matches `Package::binaryEnabled(member.package_id)`.

### QA23D-05 — The CSV export carries Source and activation id

| Why | P23D-T4 / Rule 5: the export header has a Source column; activation rows carry their activation number. |
|---|---|
| Where | Browser: `/admin/commissions/export?ledger=binary&source=activation` |
| Evidence | `QA23D-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/commissions/export?ledger=binary&source=activation`.
2. Confirm the CSV header has **Source** as a column.
3. Confirm every data row shows `activation` in the Source column.
4. Confirm `binary.export` audit row is written with the tab, filters and row count (`/admin/audit`).

Expected

- The CSV header contains `Source`; every data row's Source is `activation` (or `order` when filtered that way).
- The audit desk shows a `binary.export` row for this download.

### QA23D-06 — The reports desk shows the three package cards

| Why | P23D-T7: `/admin/reports` shows Package binary summary, by-package and by-activation cards; totals match SQL; the range CSV includes both sources. |
|---|---|
| Where | Browser: `/admin/reports` — the Package binary section at the bottom |
| Evidence | `QA23D-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/reports`: scroll to the Package binary section (below Product binary readiness).
2. Confirm three cards: **Package binary — by package**, **Package binary — by activation**, and the summary figures.
3. Confirm the numbers match `mysql` queries against `binary_awards`/`binary_volume` with `source = 'activation'`.
4. Export `Package binary bonus` CSV: confirm rows for both `Package` and `Activation` scopes.

Expected

- The by-package card lists each flagged package with activations, points placed, pairs paid, amount, recipients.
- The by-activation card lists each processed activation with member, package, points, pairs, amount, status.
- The CSV has a `Scope` column distinguishing `Package` rows from `Activation` rows.

### QA23D-07 — Other tabs unchanged

| Why | P23D-T1 / Rule 2: the source column is additive; every other tab (package commissions, repeat, royalty, team harvest, general harvest) is byte-identical to `p22i-release`. |
|---|---|
| Where | Browser: `/admin/commissions` and each other tab |
| Evidence | `QA23D-07.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/admin/commissions` (package tab): unchanged from Phase 22.
2. Open `/admin/commissions?ledger=repeat`: unchanged.
3. Open `/admin/commissions?ledger=royalty`: unchanged.
4. Open `/admin/commissions?ledger=team_harvest`: unchanged.
5. Open `/admin/commissions?ledger=general_harvest`: unchanged.
6. Open `/admin/activations`: unchanged except activation rows now show binary points/total when processed.

Expected

- Every tab except binary renders exactly as at `p22i-release`; no CSS, HTML or behaviour change outside the binary tab.
- Activation rows show binary data only when `binary_processed_at` is set; no new columns appear on other tabs.

## What is already automated

Section D's claims are also asserted by the automated suite:

- `AdminCommissionLiveTest` — P23D-T5 (activation card), T6 (member panel split), T7 (report cards), T9 (audit/reconcile unchanged).
- `BinaryService::audit()` — INV-P1 … INV-P7 still pass; `binary_audit --check` exits 0.
- `wallet_reconcile.php` — exits 0; the binary section totals both sources.

## Evidence index

| Check | Evidence file |
|---|---|
| QA23A-01 | `evidence/phase-23/QA23A-01.txt` |
| QA23A-02 | `evidence/phase-23/QA23A-02.txt` |
| QA23A-03 | `evidence/phase-23/QA23A-03.txt` |
| QA23A-04 | `evidence/phase-23/QA23A-04.txt` |
| QA23A-05 | `evidence/phase-23/QA23A-05.txt` |
| QA23A-06 | `evidence/phase-23/QA23A-06.txt` |
| QA23B-01 | `evidence/phase-23/QA23B-01.txt` |
| QA23B-02 | `evidence/phase-23/QA23B-02.txt` |
| QA23B-03 | `evidence/phase-23/QA23B-03.txt` |
| QA23B-04 | `evidence/phase-23/QA23B-04.txt` |
| QA23B-05 | `evidence/phase-23/QA23B-05.txt` |
| QA23B-06 | `evidence/phase-23/QA23B-06.txt` |
| QA23C-01 | `evidence/phase-23/QA23C-01.txt` |
| QA23C-02 | `evidence/phase-23/QA23C-02.txt` |
| QA23C-03 | `evidence/phase-23/QA23C-03.txt` |
| QA23C-04 | `evidence/phase-23/QA23C-04.txt` |
| QA23C-05 | `evidence/phase-23/QA23C-05.txt` |
| QA23C-06 | `evidence/phase-23/QA23C-06.txt` |
| QA23C-07 | `evidence/phase-23/QA23C-07.txt` |
| QA23C-08 | `evidence/phase-23/QA23C-08.txt` |
| QA23D-01 | `evidence/phase-23/QA23D-01.txt` |
| QA23D-02 | `evidence/phase-23/QA23D-02.txt` |
| QA23D-03 | `evidence/phase-23/QA23D-03.txt` |
| QA23D-04 | `evidence/phase-23/QA23D-04.txt` |
| QA23D-05 | `evidence/phase-23/QA23D-05.txt` |
| QA23D-06 | `evidence/phase-23/QA23D-06.txt` |
| QA23D-07 | `evidence/phase-23/QA23D-07.txt` |

---

## Section E — Phase 23E: Member visibility (`QA23E-01` … `QA23E-06`)

**Status:** not started. Section E is appended by 23E-S2; Section F follows in 23F.

> **Where each check runs.** `QA23E-01` to `QA23E-06` run against the development copy at migration 027,
> with at least one package flagged `binary_enabled = 1` with `binary_points > 0` and at least one
> approved activation of that package producing package pairs for the viewer. No check touches
> `ecowealth_sneat`.

> **Notation.** `mysql` means `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`; `php` means
> Laragon's PHP 8.3 CLI. Every command runs from `system/`.

### QA23E-01 — The dashboard binary tile shows package indicators

| Why | P23E-T6: the dashboard Product binary tile gains a package line showing package pairs earned and points placed. |
|---|---|
| Where | Browser: `/dashboard` |
| Evidence | `QA23E-01.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Approve activations of a binary-enabled package so the viewer earns package pairs.
2. Open `/dashboard`.
3. Confirm the binary tile shows a **Package pairs earned** line and a **Package points placed** line when package pairs exist.
4. Confirm no package line appears when the member has no package activity.

Expected

- The package line reads "N pair(s) earned · ₱m.m0 credited" and "N pts placed".
- When `package_pairs = 0` and `package_points = 0`, the package lines are absent.

### QA23E-02 — The tree node shows the package name and binary chip

| Why | P23E-T6: the genealogy tree node card shows the member's package name; a binary-enabled package shows a chip badge. |
|---|---|
| Where | Browser: `/genealogy?view=binary` |
| Evidence | `QA23E-02.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/genealogy?view=binary`.
2. Confirm each node card shows the member's package name beside their points.
3. Confirm a binary-enabled package node shows a small package chip badge.
4. Confirm a non-binary-enabled package node shows no chip.

Expected

- Package name renders on every node that has a package.
- The chip badge appears only when `packages.binary_enabled = 1`.

### QA23E-03 — Stream off: no package card, no chip, tab falls back

| Why | P23E-T7: when `package_binary_enabled = 0`, no package indicators appear anywhere on member pages. |
|---|---|
| Where | Browser: `/dashboard`, `/genealogy?view=binary`, `/earnings?ledger=binary` |
| Evidence | `QA23E-03.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Set `package_binary_enabled = 0`.
2. Open `/dashboard`: confirm the binary tile has no package line.
3. Open `/genealogy?view=binary`: confirm no package chip on any node.
4. Open `/earnings?ledger=binary`: confirm the tab reads **Binary bonus** and no package card appears.

Expected

- No package line on the dashboard tile.
- No package chip on any tree node.
- The earnings tab still renders (falls back to product-only shape at 0/0).

### QA23E-04 — Product-only member: Phase 22 page plus 0/0 split

| Why | P23E-T8: a member with no binary package activity sees the Phase 22 binary page with source split at 0/0. |
|---|---|
| Where | Browser: `/earnings?ledger=binary` |
| Evidence | `QA23E-04.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/earnings?ledger=binary` as a member with product-only binary activity (or none).
2. Confirm the tab reads **Binary bonus**.
3. Confirm the stats show **Repeat purchases** and **Package activations** cards, the latter at 0/0.
4. Confirm no **Package A** name appears anywhere (no package card).

Expected

- The page is the Phase 22 binary statement plus the additive source split.
- The Package activations card reads "No package activation placed points yet".
- No upline, sponsor, "Team chain" or "Level" words appear anywhere.

### QA23E-05 — Source filter narrows awards, stats stay whole

| Why | P23E-T5: `?source=activation` shows only package rows; the stats cards stay whole (balances not filtered). |
|---|---|
| Where | Browser: `/earnings?ledger=binary&source=activation` then `&source=order` |
| Evidence | `QA23E-05.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Open `/earnings?ledger=binary`: note the total credited and award count.
2. Open `&source=activation`: confirm only Package activation rows appear; stats unchanged.
3. Open `&source=order`: confirm only Repeat purchase rows appear; stats unchanged.

Expected

- The Source column reads **Package activation** or **Repeat purchase** per row.
- The stats cards show the same totals regardless of source filter.

### QA23E-06 — JS off and mobile: cards stack, pagination works

| Why | P23E-T9: without JavaScript and at 390 px width, the source filter, cards and pagination still work. |
|---|---|
| Where | Browser: resize to 390 px; disable JavaScript |
| Evidence | `QA23E-06.txt` |
| Result | ☐ Pass  ☐ Fail  ☐ Blocked |

Steps

1. Resize the browser to 390 px width.
2. Confirm the stats cards stack vertically without horizontal scroll.
3. Disable JavaScript and reload `/earnings?ledger=binary`.
4. Confirm the source filter dropdown works (full-page GET), pagination works, and cards render.

Expected

- No horizontal scroll at 390 px.
- Without JavaScript, the source filter submits a normal GET and paginates server-side.
- All cards render without JavaScript hydration.

