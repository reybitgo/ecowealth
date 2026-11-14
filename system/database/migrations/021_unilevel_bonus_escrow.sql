-- =============================================================
--  Phase 18 migration — repeat-purchase bonus escrow (A++)
--
--  Applies to a Phase 17 database and adds:
--
--    unilevel_awards.hold_days     days after delivery before release
--    unilevel_awards.credited_at   when the wallet actually received it
--    unilevel_awards.status        + 'pending' (new default), + 'cancelled'
--    two lookup indexes for the pending/release queries
--
--    two settings keys: unilevel_hold_days / unilevel_release_enabled
--
--  Behaviour change: a paid order now writes `pending` awards and moves
--  no money. The release job credits the wallet after delivery + the
--  snapshotted hold window. Existing `credited`/`voided` awards are
--  grandfathered: their status does not change and they are not locked.
--
--  Backfill: credited_at = created_at for rows that were paid under the
--  old immediate-credit model, so the UI always has a release date.
--  `hold_days = 0` for every existing row (they never had a hold).
--
--  No new table. No change to ewallet_ledger, users, shop_orders,
--  payout_requests or any other table.
--
--      mysql -u root <db> < database/migrations/021_unilevel_bonus_escrow.sql
--
--  Idempotent: every change is guarded by information_schema, so a
--  second run is a no-op. No `down` note: the columns and the two new
--  enum values are additive; dropping them would erase release history.
-- =============================================================

SET NAMES utf8mb4;

-- 1. unilevel_awards.hold_days — the per-award return window ----------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards' AND COLUMN_NAME = 'hold_days');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards
     ADD COLUMN hold_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. unilevel_awards.credited_at — when the wallet was credited ------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards' AND COLUMN_NAME = 'credited_at');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards
     ADD COLUMN credited_at DATETIME NULL AFTER ledger_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. unilevel_awards.status — add pending (escrow) + cancelled -------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%pending%');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards
     MODIFY COLUMN status
       ENUM(''pending'',''credited'',''skipped'',''voided'',''cancelled'')
       NOT NULL DEFAULT ''pending''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. lookup indexes for the pending / release queries ----------------
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND INDEX_NAME = 'idx_unilevel_user_status');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards ADD KEY idx_unilevel_user_status (user_id, status)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unilevel_awards'
    AND INDEX_NAME = 'idx_unilevel_order_status');
SET @sql := IF(@has = 0,
  'ALTER TABLE unilevel_awards ADD KEY idx_unilevel_order_status (order_id, status)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. backfill release dates for rows paid under the old model --------
UPDATE unilevel_awards
   SET credited_at = created_at
 WHERE credited_at IS NULL
   AND status IN ('credited', 'voided');

-- 6. settings — the hold window and the release switch ---------------
INSERT INTO settings (key_name, value) VALUES
  ('unilevel_hold_days',       '7'),
  ('unilevel_release_enabled', '1')
ON DUPLICATE KEY UPDATE key_name = key_name;
