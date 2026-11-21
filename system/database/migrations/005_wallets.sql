-- =============================================================
--  Phase 5 migration — e-wallet core (plan §4.4, §7 Phase 5)
--
--  Applies to a Phase 4 database and adds:
--
--    users.ewallet_balance       total spendable money (both buckets)
--    users.withdrawable_balance  the subset that may be paid out
--    ewallet_ledger              immutable wallet movements
--    ewallet_admin_topups        audited admin-created credits
--
--  Extensions over plan Appendix B (documented in docs/phase-log.md):
--
--    • ewallet_ledger.withdrawable_after records the second bucket.
--      Appendix B snapshots only balance_after, which makes the
--      `ewallet_balance = withdrawable_balance` invariant impossible
--      to reconcile from the ledger — success criterion 7 asks for it.
--    • uq_ewallet_idempotency (user_id, ref_type, ref_id, type) is the
--      database half of the service-level idempotency anchor. It is
--      scoped by user because one transfer writes three ledger rows
--      under the same (ref_type, ref_id).
--
--      mysql -u root <db> < database/migrations/005_wallets.sql
--
--  No seed: demo wallet balances arrive with the demo tree (Phase 9).
--  No `down` note: the tables are additive and dropping the two users
--  columns would erase balances.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. users — the dual bucket (guarded so the file is re-runnable)
-- -------------------------------------------------------------
SET @has_ewallet := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ewallet_balance'
);
SET @sql := IF(@has_ewallet = 0,
  'ALTER TABLE users ADD COLUMN ewallet_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER is_active',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_withdrawable := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'withdrawable_balance'
);
SET @sql := IF(@has_withdrawable = 0,
  'ALTER TABLE users ADD COLUMN withdrawable_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER ewallet_balance',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 2. ewallet_ledger — immutable wallet movements
--    No updated_at column: a ledger row is never edited.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ewallet_ledger (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED  NOT NULL,
  type              ENUM('credit','debit') NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  balance_after     DECIMAL(14,2) NOT NULL,
  withdrawable_after DECIMAL(14,2) NOT NULL,
  ref_type          ENUM('commission','payout','transfer','topup','registration',
                         'shop_order','adjustment','refund') NOT NULL,
  ref_id            INT UNSIGNED  NULL,
  note              VARCHAR(255)  NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ewallet_idempotency (user_id, ref_type, ref_id, type),
  KEY idx_ewallet_user (user_id, created_at),
  KEY idx_ewallet_ref (ref_type, ref_id),
  CONSTRAINT fk_ewallet_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3. ewallet_admin_topups — every admin-created credit, auditable
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ewallet_admin_topups (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  admin_id     INT UNSIGNED  NOT NULL,
  recipient_id INT UNSIGNED  NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  note         VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_topups_admin (admin_id, created_at),
  KEY idx_topups_recipient (recipient_id, created_at),
  CONSTRAINT fk_topups_admin     FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_topups_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
