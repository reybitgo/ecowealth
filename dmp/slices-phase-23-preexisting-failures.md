# Phase 23 — Pre-Existing Test Failures (Phase 19-22 Regression from 23A)

Source: `dmp/plan-ecowealth-package-binary-sliced.md` §2.7 / execution log
Branch: `feature/package-binary`
Created: after commit `76199f3` (23B-S2 complete)

## Summary

Four test cases fail after Phase 23A's changes landed. None are caused by 23B-S2 (all 18 PackageBinaryTest cases pass; SettingsPageTest 9/9; BinarySettingsTest 12/12; RoyaltyAdminMemberViewTest 5/5). They are pre-existing test-maintenance debt from Phase 23A: 23A added migration `027_package_binary.sql` and three `package_binary_*` settings to `database/seeds/001_settings.sql`, changing the shape of the shared test database (`DB_NAME`) that Phase 19-22 foundation/release tests compare against.

| Test | Case | Failure | Root cause |
|------|------|---------|------------|
| `BinaryReleaseTest` | P22I-T2 | `expected 92 settings, got 89` | Asserts DatabaseInstaller fresh-install count (92) but queries `db()` = `test_reset_app_database()` DB which has only seed settings (89; the +3 are install-time footer settings never loaded by the bootstrap). |
| `BinaryReleaseTest` | P22I-T8 | `expected 92 settings, got 89` | Same root cause as P22I-T2 on a scratch DB rebuilt via `test_reset_app_database`. |
| `RoyaltyFoundationTest` | P19A-T3 | migrated schema vs fresh install not equivalent | `$buildFoundation()` replays schema + migrations 001–018 only; `$fresh = test_pdo(['db' => DB_NAME])` is the shared DB from `test_reset_app_database()` which now includes schema.sql columns + seed settings from migrations 026/027 (binary tables, package_binary columns/settings). Table/column/index/FK/enum lists differ. |
| `TeamHarvestFoundationTest` | P20A-T3 | migrated schema vs fresh install not equivalent | Same root cause as P19A-T3 for Phase 20's fixture (migrations 001–024). |

## Root-cause detail

### The database shapes differ

`test_reset_app_database()` (bootstrap.php:468) builds `DB_NAME` from:

```
schema.sql + seeds/001_settings.sql + seeds/002–005_settings.sql
```

Measured: **42 tables, 89 settings**.

A full fresh install via `DatabaseInstaller` builds from `database/install.sql`:

```
header.sql + schema.sql + seeds/001–005 + footer.sql
```

Measured: **42 tables, 92 settings** (the +3 are `install_version`, `install_channel`, `install_completed_at` from the footer, never loaded by `test_reset_app_database`).

### What 23A changed

`database/seeds/001_settings.sql` now carries the three `package_binary_*` settings (lines 154–156), and migration `027_package_binary.sql` adds `packages.binary_enabled/binary_points`, `activations.binary_*`, and `binary_awards`/`binary_volume` source/activation columns. Because `schema.sql` was also updated to include the binary tables (binary_nodes, binary_volume, binary_awards), the shared `DB_NAME` now differs from the phase-limited fixtures that P19A-T3 and P20A-T3 build.

### Why P22I-T2/T8 expected 92

Those tests were updated (in this session) to assert `assert_same(92, ...)` matching `DatabaseInstaller::EXPECTED['settings_rows']`, but they run against `db()` → `DB_NAME` which holds only 89 settings (seed only, no footer). The assertion target and the database under test disagree by exactly the 3 install-time footer settings.

## Recommended fix (not part of 23B-S2)

These are Phase 19-22 test debt, not 23B defects. Options:

1. **P22I-T2/T8:** Change the expected value from `92` back to `89` when querying `db()` / `DB_NAME`, or switch the tests to build a fresh install via `DatabaseInstaller` and assert 92 there. The comment already notes "re-measure, never copy" — the measured value on `DB_NAME` is 89.

2. **P19A-T3/P20A-T3:** These parity tests compare a phase-limited migration replay against `DB_NAME`, which now contains post-phase schema/seed changes. Either:
   - Rebuild `$fresh` from schema + migrations up to the phase's last migration (matching `$migrated`), or
   - Scope the comparison to only the objects the phase owns (tables/columns/indexes/FKs introduced by that phase's migrations), ignoring later-phase additions.

## 23B-S2 status (for context)

All 23B-S2 work is complete and committed at `76199f3`:

| Area | Files changed | Tests | Status |
|------|--------------|-------|--------|
| Model | `system/app/Models/Package.php` | `binaryReadinessCounts()` | PASS |
| Controller | `system/app/Controllers/AdminSettingsController.php` | `PACKAGE_BINARY_TOGGLES/FIELDS` wired into keys/toggles/rules/labels/context/feed | PASS |
| View | `system/app/views/admin/settings/_commissions.php` | package binary sub-block + readiness panel | PASS |
| View | `system/app/views/admin/settings/index.php` | passes `package_binary_*` to commissions card | PASS |
| Tests | `system/tests/Cases/PackageBinaryTest.php` | P23B-T11, T13, T14, T15 + helpers | 18/18 PASS |
| Tests | `system/tests/Cases/SettingsPageTest.php` | commission catalog updated for 2 new keys | 9/9 PASS |
| Tests | 5 foundation files | settings-count (89→92) and newest-migration (026→027) expectations | PASS (except P19A/P20A-T3 noted above) |

## Usage

This document is a record of pre-existing failures to triage before any Phase 23C+ release gate. It does not block 23B-S2 completion. Resolve during 23G release acceptance when the Phase 19-22 test fixtures are reconciled against the post-23A database shape.
