-- =============================================================
--  Phase 11 migration — indirect referral commissions (levels 2–10)
--
--  No schema change is required: the `commissions` table created by
--  migration 012 already stores the indirect leg (`type =
--  'indirect_referral'`, `level = 2…10`, source package, activation).
--
--  What this migration adds are the settings that steer the walk:
--
--    commission_indirect_enabled     master switch for levels 2–10
--    commission_indirect_max_levels  depth of the ladder (2–10; below
--                                   2 means direct only)
--    commission_compression          reserved: 0 = no compression, an
--                                   ineligible upline consumes its level
--
--  They are inserted only when missing, so an existing database keeps
--  the values an operator has already chosen. The same keys ship in
--  database/seeds/001_settings.sql for a fresh install.
--
--      mysql -u root <db> < database/migrations/014_indirect_commissions.sql
--
--  No `down` note: the keys are configuration, not money history.
--  Dropping them would silently change how every future activation pays.
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO settings (key_name, value)
SELECT 'commission_indirect_enabled', '1'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'commission_indirect_enabled');

INSERT INTO settings (key_name, value)
SELECT 'commission_indirect_max_levels', '10'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'commission_indirect_max_levels');

INSERT INTO settings (key_name, value)
SELECT 'commission_compression', '0'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'commission_compression');
