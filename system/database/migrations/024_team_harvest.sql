-- =============================================================
--  Phase 20 migration — Team Harvest bonus
--
--  Applies to a Phase 19 database and adds:
--
--    harvest_queue          one row per qualified member; id = position
--    team_harvest_levels    the ten configured amounts (catalogue)
--    team_harvest_awards    the Team Harvest money ledger
--
--    ewallet_ledger.ref_type + 'team_harvest'
--    four settings (the stream is off and unseeded by default)
--    the ten reference ladder rows
--
--  No backfill: the queue is reconstructed by
--  bin/harvest_queue_seed.php, on demand, with a dry run (plan D-04).
--  Nothing in the application reads either money table until 20D;
--  20A ships them empty and inert.
--
--      mysql -u root <db> < database/migrations/024_team_harvest.sql
--
--  Idempotent: every CREATE TABLE is IF NOT EXISTS; the ENUM change
--  is guarded; the settings insert is ON DUPLICATE KEY UPDATE
--  key_name = key_name; the ladder insert is ON DUPLICATE KEY UPDATE
--  level = level. Running the file twice changes nothing and never
--  overwrites an operator's value.
--
--  No `down` note: the tables are additive. Dropping them would erase
--  the record of every position and every award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. ewallet_ledger.ref_type — append 'team_harvest' ---------------
--    Appended last so MySQL can use the instant/in-place ENUM path
--    (plan §13.5, D-11). `royalty` stays second-to-last.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type'
    AND COLUMN_TYPE LIKE '%team_harvest%');
SET @sql := IF(@has = 0,
  "ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM('commission','payout','transfer','topup','registration','shop_order',
          'adjustment','refund','unilevel','unilevel_reversal','royalty','team_harvest')
     NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. harvest_queue — positions, counters, baseline -----------------
--    `id` IS the position (D-03): auto-increment, never renumbered,
--    gaps allowed. `source` says how the row was created; `repair`
--    only comes from the rebuild job's --apply (20C).
CREATE TABLE IF NOT EXISTS harvest_queue (
  id                      INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id                 INT UNSIGNED     NOT NULL,
  qualified_at            DATETIME         NOT NULL,
  directs_at_qualification SMALLINT UNSIGNED NOT NULL,
  teammates               INT UNSIGNED     NOT NULL DEFAULT 0,
  baseline_n              INT UNSIGNED     NOT NULL DEFAULT 0,
  source                  ENUM('registration','seed','repair') NOT NULL DEFAULT 'registration',
  created_at              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_user (user_id),
  KEY idx_queue_qualified (qualified_at),
  KEY idx_queue_teammates (teammates),
  CONSTRAINT fk_hq_user     FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_hq_directs CHECK (directs_at_qualification >= 1),
  CONSTRAINT chk_hq_baseline CHECK (teammates >= baseline_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3. team_harvest_levels — the ten amounts (catalogue) -------------
--    Structural thresholds are fixed (D-18); only the amounts are
--    configurable. Kept by a business reset, like package_levels.
CREATE TABLE IF NOT EXISTS team_harvest_levels (
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED     NULL,
  PRIMARY KEY (level),
  CONSTRAINT chk_thl_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_thl_amount CHECK (amount >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 4. team_harvest_awards — the money ledger ------------------------
--    One row per (member, level); UNIQUE (user_id, level) is the
--    money anchor (D-06). `ledger_id` is not a foreign key: the wallet
--    ledger outlives an award's lifecycle (same as royalty_awards).
--    There is no `voided` status in v1 (D-12): a row is written once
--    and never edited; `baseline` rows are recorded and never paid.
CREATE TABLE IF NOT EXISTS team_harvest_awards (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
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
  UNIQUE KEY uq_tha_member_level (user_id, level),
  KEY idx_tha_level (level, credited_at),
  KEY idx_tha_credited (credited_at),
  KEY idx_tha_status (status),
  KEY idx_tha_ledger (ledger_id),
  CONSTRAINT fk_tha_user   FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_tha_level  CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_tha_amount CHECK (amount >= 0),
  CONSTRAINT chk_tha_n      CHECK (n_at_award >= threshold_n)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. the four settings --------------------------------------------
--    The stream is off AND unseeded: `team_harvest_ledger_since` is
--    deliberately empty — it is the proof the queue was seeded (D-04),
--    not a first-payable date. No DATE_FORMAT(NOW()) here on purpose.
INSERT INTO settings (key_name, value) VALUES
  ('team_harvest_enabled',         '0'),
  ('team_harvest_qualify_directs', '3'),
  ('team_harvest_max_levels',      '10'),
  ('team_harvest_ledger_since',    '')
ON DUPLICATE KEY UPDATE key_name = key_name;

-- 6. the reference ladder (never overwrites an operator's amount) --
INSERT INTO team_harvest_levels (level, amount) VALUES
  (1,   1000.00),
  (2,   2000.00),
  (3,   4000.00),
  (4,   6000.00),
  (5,  12000.00),
  (6,  50000.00),
  (7, 120000.00),
  (8, 200000.00),
  (9, 1200000.00),
  (10, 3500000.00)
ON DUPLICATE KEY UPDATE level = level;
