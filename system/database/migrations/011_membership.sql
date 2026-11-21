-- =============================================================
--  Phase 9 migration — registration, codes, membership, activation
--
--  Applies to a Phase 8 database and adds the membership core from
--  plan §4.2 / Appendix B, §5.10 and §7 Phase 9:
--
--    users.phone              contact number captured at registration
--    users.member_status      pending | active | inactive | suspended
--    users.package_id         the activated associate package
--    users.sponsor_id         the ONE upline link (no binary tree)
--    users.referral_code      8-character public code for ?ref= links
--    users.reg_payment_method how the account joined (standard/code/ewallet/pending)
--    users.reg_code_id        the registration code redeemed, if any
--    users.reg_paid_by        the payer when a sponsor funded the account
--    users.activated_at       when the package was approved
--
--    reg_codes               admin-issued, package-bound, priced, expiring
--    activations             package purchase / activation requests
--
--  Deliberate differences from plan Appendix B (documented in
--  docs/phase-log.md):
--
--    • `activated_at` sits after `reg_paid_by` (Appendix B does not
--      fix its position; keeping it with the membership block makes a
--      migrated schema identical to a fresh install).
--    • The referral backfill below gives every existing account a
--      deterministic `EW` + 6 hex digits code. New registrations get
--      a random 8-character code (SponsorService); only legacy rows
--      need the deterministic form, and the unique key still holds.
--    • No FK on `reg_code_id` / `reg_paid_by`: Appendix B leaves them
--      unconstrained, and a deleted code must not delete the member.
--
--      mysql -u root <db> < database/migrations/011_membership.sql
--
--  No `down` note: the columns and tables are additive. Dropping
--  them would erase membership history and redeemed codes.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. users — registration and membership columns
--    Each ALTER is guarded so the file can be re-run safely.
-- -------------------------------------------------------------

-- phone -------------------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER full_name',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- member_status ----------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'member_status'
);
SET @sql := IF(@has = 0,
  "ALTER TABLE users ADD COLUMN member_status ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'pending' AFTER role",
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- package_id --------------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'package_id'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN package_id INT UNSIGNED NULL AFTER member_status',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- sponsor_id --------------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'sponsor_id'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN sponsor_id INT UNSIGNED NULL AFTER package_id',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- referral_code ----------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'referral_code'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN referral_code VARCHAR(16) NULL AFTER sponsor_id',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- reg_payment_method -----------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reg_payment_method'
);
SET @sql := IF(@has = 0,
  "ALTER TABLE users ADD COLUMN reg_payment_method ENUM('standard','code','ewallet','pending') NOT NULL DEFAULT 'standard' AFTER referral_code",
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- reg_code_id ------------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reg_code_id'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN reg_code_id INT UNSIGNED NULL AFTER reg_payment_method',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- reg_paid_by ------------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reg_paid_by'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN reg_paid_by INT UNSIGNED NULL AFTER reg_code_id',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- activated_at -----------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'activated_at'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD COLUMN activated_at DATETIME NULL AFTER reg_paid_by',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 2. Backfill existing accounts.
--
--    Every account that existed before this phase is a working
--    account today, so it becomes `active` (staff included — their
--    login is governed by is_active, and member_status describes
--    the membership record either way).
--
--    The guard `referral_code IS NULL` limits the backfill to
--    legacy rows: every account created from Phase 9 on receives its
--    referral code in the same INSERT, so a real pending member is
--    never flipped to active by a re-run of this file.
-- -------------------------------------------------------------
UPDATE users
   SET member_status = 'active'
 WHERE member_status = 'pending'
   AND referral_code IS NULL;

UPDATE users
   SET referral_code = UPPER(CONCAT('EW', LPAD(HEX(id), 6, '0')))
 WHERE referral_code IS NULL;

-- -------------------------------------------------------------
-- 3. users — indexes and foreign keys (all guarded)
-- -------------------------------------------------------------

-- uq_users_referral ------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_referral'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_referral (referral_code)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_users_sponsor ------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_sponsor'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD KEY idx_users_sponsor (sponsor_id)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_users_package ------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_package'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD KEY idx_users_package (package_id)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- fk_users_package -------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_package'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- fk_users_sponsor -------------------------------------------
SET @has := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_sponsor'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_sponsor FOREIGN KEY (sponsor_id) REFERENCES users (id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 4. reg_codes — admin-issued, package-bound, one-time codes
--
--    `code` is stored uppercase and compared case-insensitively.
--    Single use is enforced by a conditional UPDATE in CodeService
--    (WHERE status='unused', row count must be 1), not by a
--    read-then-write race (plan §3.7, §4.3).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reg_codes (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code       VARCHAR(24)   NOT NULL,
  package_id INT UNSIGNED  NOT NULL,
  price      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  code_type  ENUM('registration','upgrade') NOT NULL DEFAULT 'registration',
  status     ENUM('unused','used','expired') NOT NULL DEFAULT 'unused',
  created_by INT UNSIGNED  NOT NULL,
  used_by    INT UNSIGNED  NULL,
  used_at    DATETIME      NULL,
  expires_at DATE          NULL,
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_codes_code (code),
  KEY idx_reg_codes_status (status),
  KEY idx_reg_codes_package (package_id),
  KEY idx_reg_codes_user (used_by),
  CONSTRAINT fk_reg_codes_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_codes_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_codes_user    FOREIGN KEY (used_by)    REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 5. activations — one row per package purchase attempt
--
--    Registration-time payments (code / e-wallet) also write a row
--    with status `approved`, so every commission in Phases 10–11 can
--    point at the activation that produced it (plan §6.2).
--
--    `commission_processed_at` is the idempotency anchor: it is
--    stamped inside the same transaction that awards commissions,
--    and a second approval bails out when it is already set.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activations (
  id                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id                 INT UNSIGNED  NOT NULL,
  package_id              INT UNSIGNED  NOT NULL,
  amount                  DECIMAL(12,2) NOT NULL,
  payment_method          ENUM('ewallet','code','gcash','maya','bank') NOT NULL,
  status                  ENUM('pending_payment','pending_review','approved','rejected','cancelled')
                          NOT NULL DEFAULT 'pending_payment',
  reg_code_id             INT UNSIGNED  NULL,
  reference_no            VARCHAR(40)   NULL,
  proof_path              VARCHAR(255)  NULL,
  reject_reason           VARCHAR(160)  NULL,
  reviewed_by             INT UNSIGNED  NULL,
  reviewed_at             DATETIME      NULL,
  commission_processed_at DATETIME      NULL,
  created_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activations_user (user_id, status),
  KEY idx_activations_status (status, created_at),
  CONSTRAINT fk_activations_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_activations_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
