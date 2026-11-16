-- =============================================================
--  Phase 21 migration — General Harvest bonus
--
--  Applies to a Phase 20 database and adds:
--
--    general_harvest_levels   the ten configured amounts (catalogue)
--    general_harvest_board    the singleton: baseline_n, armed_at, settled_n
--    general_harvest_awards   the General Harvest money ledger
--
--    ewallet_ledger.ref_type + 'general_harvest'
--    two settings (the stream is off by default)
--    the ten reference ladder rows and the unarmed board row
--
--  No backfill: the baseline is recorded by
--  bin/general_harvest_arm.php, on demand, with a dry run (plan D-07).
--  Nothing in the application reads the ledger until 21D; 21A ships
--  it empty and inert.
--
--      mysql -u root <db> < database/migrations/025_general_harvest.sql
--
--  Idempotent: every CREATE TABLE is IF NOT EXISTS; the ENUM change
--  is guarded; the settings insert is ON DUPLICATE KEY UPDATE
--  key_name = key_name; the ladder insert is ON DUPLICATE KEY UPDATE
--  level = level; the board insert is ON DUPLICATE KEY UPDATE id = id.
--  Running the file twice changes nothing and never overwrites an
--  operator's amount or an armed board.
--
--  No `down` note: the tables are additive. Dropping them would erase
--  the record of every node and every award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. ewallet_ledger.ref_type — append 'general_harvest' -----------
--    Appended last so MySQL can use the instant/in-place ENUM path
--    (the same technique as 19A's `royalty` and 20A's `team_harvest`).
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type'
    AND COLUMN_TYPE LIKE '%general_harvest%');
SET @sql := IF(@has = 0,
  "ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM('commission','payout','transfer','topup','registration','shop_order',
          'adjustment','refund','unilevel','unilevel_reversal','royalty',
          'team_harvest','general_harvest')
     NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. general_harvest_levels — the ten amounts (catalogue) ---------
CREATE TABLE IF NOT EXISTS general_harvest_levels (
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED     NULL,
  PRIMARY KEY (level),
  CONSTRAINT chk_ghl_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_ghl_amount CHECK (amount >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3. general_harvest_board — the singleton state -------------------
CREATE TABLE IF NOT EXISTS general_harvest_board (
  id          TINYINT UNSIGNED NOT NULL,
  baseline_n  INT UNSIGNED     NULL,
  armed_at    DATETIME         NULL,
  armed_by    INT UNSIGNED     NULL,
  settled_n   INT UNSIGNED     NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ghb_armed_by FOREIGN KEY (armed_by) REFERENCES users (id)
    ON DELETE SET NULL,
  CONSTRAINT chk_ghb_singleton CHECK (id = 1),
  CONSTRAINT chk_ghb_armed CHECK (
    (baseline_n IS NULL AND armed_at IS NULL)
    OR (baseline_n IS NOT NULL AND armed_at IS NOT NULL)),
  CONSTRAINT chk_ghb_settled CHECK (baseline_n IS NULL OR settled_n >= baseline_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 4. general_harvest_awards — the money ledger ---------------------
CREATE TABLE IF NOT EXISTS general_harvest_awards (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  seq          INT UNSIGNED     NOT NULL,
  user_id      INT UNSIGNED     NOT NULL,
  level        TINYINT UNSIGNED NOT NULL,
  threshold_n  INT UNSIGNED     NOT NULL,
  n_at_award   INT UNSIGNED     NOT NULL,
  amount       DECIMAL(12,2)    NOT NULL,
  status       ENUM('credited','baseline') NOT NULL DEFAULT 'credited',
  ledger_id    INT UNSIGNED     NULL,
  credited_at  DATETIME         NOT NULL,
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gha_seq_level (seq, level),
  KEY idx_gha_user (user_id, level),
  KEY idx_gha_level (level, seq),
  KEY idx_gha_credited (credited_at),
  KEY idx_gha_status (status),
  KEY idx_gha_ledger (ledger_id),
  CONSTRAINT fk_gha_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_gha_level    CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_gha_amount   CHECK (amount >= 0),
  CONSTRAINT chk_gha_n        CHECK (n_at_award >= threshold_n),
  CONSTRAINT chk_gha_baseline CHECK (status <> 'baseline' OR ledger_id IS NULL)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. the two settings ---------------------------------------------
INSERT INTO settings (key_name, value) VALUES
  ('general_harvest_enabled',    '0'),
  ('general_harvest_max_levels', '10')
ON DUPLICATE KEY UPDATE key_name = key_name;

-- 6. the reference ladder (never overwrites an operator's amount) ---
INSERT INTO general_harvest_levels (level, amount) VALUES
  (1,   2000.00),
  (2,   4000.00),
  (3,   8000.00),
  (4,  12000.00),
  (5,  25000.00),
  (6, 100000.00),
  (7, 250000.00),
  (8, 480000.00),
  (9, 2500000.00),
  (10, 5000000.00)
ON DUPLICATE KEY UPDATE level = level;

-- 7. the board singleton (never disturbs an armed board) -----------
INSERT INTO general_harvest_board (id, baseline_n, armed_at, armed_by, settled_n)
VALUES (1, NULL, NULL, NULL, 0)
ON DUPLICATE KEY UPDATE id = id;
