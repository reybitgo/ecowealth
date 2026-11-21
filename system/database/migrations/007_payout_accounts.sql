-- =============================================================
--  Migration 007 — member payout accounts (Phase 6 profile)
--
--  The reference member profile stores the accounts the company
--  pays out to; plan §4 (users columns) and §5.9 (payouts) use the
--  same four fields. They ship with the profile now so members can
--  fill them in, and Phase 13's payout form pre-fills from them.
--
--  The columns are additive and guarded: Phase 13's migration can
--  re-run these ADD COLUMN statements (or skip them) safely.
--
--      mysql -u root <db> < database/migrations/007_payout_accounts.sql
-- =============================================================

SET NAMES utf8mb4;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'gcash_number'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN gcash_number VARCHAR(20) NULL AFTER withdrawable_balance',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'maya_number'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN maya_number VARCHAR(20) NULL AFTER gcash_number',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'usdt_trc20_address'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN usdt_trc20_address VARCHAR(100) NULL AFTER maya_number',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'usdt_bep20_address'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN usdt_bep20_address VARCHAR(100) NULL AFTER usdt_trc20_address',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
