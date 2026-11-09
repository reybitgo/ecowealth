-- =============================================================
--  Phase 12 migration — e-wallet transfers, limits and monitoring
--
--  Applies to a Phase 11 database and adds:
--
--    users.ewallet_sent_today       daily transfer usage (members)
--    users.ewallet_sent_this_week   weekly transfer usage (members)
--    ewallet_transfers              one row per member-to-member transfer
--
--  Why the counters live on `users`
--
--    The limit check must run inside the same transaction that debits
--    the sender, against the locked account row (plan §5.8). Keeping
--    the two counters on `users` means one `SELECT … FOR UPDATE` covers
--    the balance, both buckets and the limit usage, so two concurrent
--    transfers cannot both pass a limit they only fit once. The reset
--    cron (bin/reset_transfer_limits.php) zeroes them on a schedule.
--
--  Why `ewallet_transfers` stores the fee and the net
--
--    The sender's debit is amount + fee; the recipient receives the
--    amount. Recording both numbers on the transfer row means the three
--    ledger movements (sender debit, recipient credit, admin fee credit)
--    can be reconciled from one row without re-reading the settings,
--    which may have changed since (the same reasoning as the payout
--    rows in Phase 13).
--
--    `status` is present for audit symmetry with the reference; this
--    build only ever writes 'completed' — a refused transfer writes
--    nothing at all (plan §5.8), so it is never recorded as 'failed'.
--
--      mysql -u root <db> < database/migrations/016_transfers.sql
--
--  No `down` note: dropping the table would erase the transfer history,
--  and dropping the counters would silently lift every live limit.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. users — the two limit counters (guarded so the file re-runs)
-- -------------------------------------------------------------
SET @has_sent_today := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ewallet_sent_today'
);
SET @sql := IF(@has_sent_today = 0,
  'ALTER TABLE users ADD COLUMN ewallet_sent_today DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER withdrawable_balance',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_sent_week := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ewallet_sent_this_week'
);
SET @sql := IF(@has_sent_week = 0,
  'ALTER TABLE users ADD COLUMN ewallet_sent_this_week DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER ewallet_sent_today',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 2. ewallet_transfers — the transfer log
--    No updated_at column: a completed transfer is never edited.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ewallet_transfers (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sender_id    INT UNSIGNED  NOT NULL,
  recipient_id INT UNSIGNED  NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,                 -- what the recipient receives
  fee          DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- credited to the primary admin
  net_amount   DECIMAL(12,2) NOT NULL,                 -- amount received by the recipient
  note         VARCHAR(255)  NULL,
  status       ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transfers_sender (sender_id, created_at),
  KEY idx_transfers_recipient (recipient_id, created_at),
  KEY idx_transfers_status (status, created_at),
  CONSTRAINT fk_transfers_sender    FOREIGN KEY (sender_id)    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_transfers_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3. The four settings the transfer rules read
--
--  A fresh install gets them from database/seeds/001_settings.sql;
--  an earlier database may predate that seed, so add only the missing
--  keys and never overwrite a value an operator already chose.
-- -------------------------------------------------------------
INSERT INTO settings (key_name, value)
SELECT 'ewallet_transfer_fee', '0'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'ewallet_transfer_fee');

INSERT INTO settings (key_name, value)
SELECT 'ewallet_min_transfer', '50'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'ewallet_min_transfer');

INSERT INTO settings (key_name, value)
SELECT 'ewallet_transfer_daily_limit', '5000'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'ewallet_transfer_daily_limit');

INSERT INTO settings (key_name, value)
SELECT 'ewallet_transfer_weekly_limit', '20000'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'ewallet_transfer_weekly_limit');
