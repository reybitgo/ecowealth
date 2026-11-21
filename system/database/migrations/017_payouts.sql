-- =============================================================
--  Phase 13 migration — payouts
--
--  Applies to a Phase 12 database and adds:
--
--    payout_requests                      one row per withdrawal request
--    users.gcash_number                   payout account (normally 007)
--    users.maya_number                    payout account (normally 007)
--    users.usdt_trc20_address             payout account (normally 007)
--    users.usdt_bep20_address             payout account (normally 007)
--
--  Why the money is held on the request row's amount
--
--    Plan §5.9 (D17) holds the funds at request time: the member's
--    withdrawable balance is debited by `amount` in the same
--    transaction that inserts the row, so the money cannot be spent
--    while the request is in the queue. `amount` is therefore the
--    gross the member asked for — the same number that comes back on a
--    rejection — and the fee/net/USDT figures beside it describe what
--    will actually be sent off-platform. Rejection writes a `refund`
--    ledger row; completion moves no money at all (it was already
--    debited), it only records that staff sent the funds.
--
--  Why the rate and the gas fee are stored on every row
--
--    `usdt_rate` and `usdt_gas_fee` are snapshots of the settings at
--    request time. Settings can change before an admin settles the
--    queue; the member must be paid the amount the request promised,
--    and the audit must show which rate produced it (plan §5.9 — the
--    rate is never supplied by the client).
--
--  Column notes
--
--    payout_account VARCHAR(120) NOT NULL DEFAULT ''   the number or
--                address the company sends to; prefilled from the
--                profile but stored per request so a later profile edit
--                cannot rewrite history.
--    admin_note  the rejection reason, or a completion note.
--    processed_by / processed_at
--                the staff account and moment of the last decision.
--    requested_at
--                when the hold was taken.
--
--  `status` is the four-state machine of plan §4.4:
--    pending → approved → completed, and pending/approved → rejected.
--
--      mysql -u root <db> < database/migrations/017_payouts.sql
--
--  No `down` note: dropping the table would erase every withdrawal
--  request and the record of money already paid out.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. users — the four payout accounts
--
--    Migration 007 already adds these (the profile form ships in
--    Phase 6). The guarded copies keep this file self-sufficient on a
--    database that predates 007, and are no-ops everywhere else.
-- -------------------------------------------------------------
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

-- -------------------------------------------------------------
-- 2. payout_requests — the withdrawal queue
--    No updated_at column: a request is never edited, only moved
--    through its status machine (processed_at records the decision).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payout_requests (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED  NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,               -- gross, held at request time
  payout_method       ENUM('gcash','maya','usdt_trc20','usdt_bep20') NOT NULL DEFAULT 'gcash',
  payout_account      VARCHAR(120)  NOT NULL DEFAULT '',
  service_fee_pct     DECIMAL(5,2)  NOT NULL DEFAULT 0.00,  -- settings.service_fee_<method>
  service_fee_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- amount × pct / 100
  usdt_rate           DECIMAL(12,4) NOT NULL DEFAULT 0.0000,-- ₱ per 1 USDT at request time
  usdt_gas_fee        DECIMAL(10,4) NOT NULL DEFAULT 0.0000,-- USDT network fee at request time
  usdt_amount         DECIMAL(12,4) NOT NULL DEFAULT 0.0000,-- USDT actually sent
  status              ENUM('pending','approved','completed','rejected') NOT NULL DEFAULT 'pending',
  admin_note          VARCHAR(500)  NULL,                   -- rejection reason / completion note
  processed_by        INT UNSIGNED  NULL,
  requested_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at        DATETIME      NULL,
  PRIMARY KEY (id),
  KEY idx_payouts_user_status (user_id, status),
  KEY idx_payouts_status (status, requested_at),
  CONSTRAINT fk_payouts_user  FOREIGN KEY (user_id)      REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_payouts_admin FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3. The payout settings the service reads
--
--  A fresh install gets them from database/seeds/001_settings.sql;
--  an earlier database may predate that seed, so add only the missing
--  keys and never overwrite a value an operator already chose.
--
--  `usdt_rate` seeds as 0 on purpose: an install that has not set the
--  ₱/USDT rate must refuse USDT payouts rather than invent one (the
--  reference asked the browser for the rate — plan §2.3 drops that).
-- -------------------------------------------------------------
INSERT INTO settings (key_name, value)
SELECT 'min_payout', '500'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'min_payout');

INSERT INTO settings (key_name, value)
SELECT 'service_fee_gcash', '0'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'service_fee_gcash');

INSERT INTO settings (key_name, value)
SELECT 'service_fee_maya', '0'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'service_fee_maya');

INSERT INTO settings (key_name, value)
SELECT 'service_fee_usdt_trc20', '5'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'service_fee_usdt_trc20');

INSERT INTO settings (key_name, value)
SELECT 'service_fee_usdt_bep20', '5'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'service_fee_usdt_bep20');

INSERT INTO settings (key_name, value)
SELECT 'usdt_rate', '0'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'usdt_rate');

INSERT INTO settings (key_name, value)
SELECT 'usdt_trc20_gas_fee', '2.50'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'usdt_trc20_gas_fee');

INSERT INTO settings (key_name, value)
SELECT 'usdt_bep20_gas_fee', '0.05'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'usdt_bep20_gas_fee');

INSERT INTO settings (key_name, value)
SELECT 'payout_gcash_enabled', '1'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'payout_gcash_enabled');

INSERT INTO settings (key_name, value)
SELECT 'payout_maya_enabled', '1'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'payout_maya_enabled');

INSERT INTO settings (key_name, value)
SELECT 'payout_usdt_trc20_enabled', '1'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'payout_usdt_trc20_enabled');

INSERT INTO settings (key_name, value)
SELECT 'payout_usdt_bep20_enabled', '1'
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'payout_usdt_bep20_enabled');
