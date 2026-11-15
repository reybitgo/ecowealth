-- =============================================================
--  Phase 19 migration — Royalty bonus
--
--  Applies to a Phase 18 database and adds:
--
--    royalty_periods   one row per evaluated month (payout header)
--    royalty_awards    the Royalty money ledger (one row per member
--                      per month)
--
--    ewallet_ledger.ref_type + 'royalty'
--    sixteen settings (the stream is off by default)
--
--  No backfill: months before `royalty_ledger_since` are never paid
--  (plan D-15). A closed period is immutable (plan §12.6). Nothing in
--  the application reads either table until 19C; 19A ships them
--  empty and inert.
--
--      mysql -u root <db> < database/migrations/023_royalty_bonus.sql
--
--  Idempotent: every CREATE TABLE is IF NOT EXISTS, the ENUM change is
--  guarded by an information_schema probe and the settings insert is
--  ON DUPLICATE KEY UPDATE key_name = key_name. Running the file twice
--  changes nothing and never overwrites an operator's value.
--
--  No `down` note: the tables are additive. Dropping them would erase
--  the record of every Royalty award and would reopen a closed month.
-- =============================================================

SET NAMES utf8mb4;

-- 1. ewallet_ledger.ref_type — append 'royalty' -------------------
--    Appended last so MySQL can use the instant/in-place ENUM path
--    (plan §13.4, D-19). `unilevel_reversal` stays second-to-last.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type'
    AND COLUMN_TYPE LIKE '%royalty%');
SET @sql := IF(@has = 0,
  "ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM('commission','payout','transfer','topup','registration','shop_order',
          'adjustment','refund','unilevel','unilevel_reversal','royalty')
     NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. royalty_periods — the monthly header -------------------------
--    No auto-increment column: the primary key is the period.
CREATE TABLE IF NOT EXISTS royalty_periods (
  period                 CHAR(7)       NOT NULL,
  status                 ENUM('evaluated','paid','skipped') NOT NULL DEFAULT 'evaluated',
  enabled                TINYINT(1)    NOT NULL DEFAULT 0,
  evaluated_at           DATETIME      NOT NULL,
  evaluated_by           INT UNSIGNED  NULL,
  paid_at                DATETIME      NULL,
  paid_by                INT UNSIGNED  NULL,
  as_of                  DATETIME      NOT NULL,
  leader_directs         SMALLINT UNSIGNED NOT NULL,
  supervisor_leaders     INT UNSIGNED  NOT NULL,
  manager_supervisors    INT UNSIGNED  NOT NULL,
  director_managers      INT UNSIGNED  NOT NULL,
  quota_supervisor       DECIMAL(12,2) NOT NULL,
  quota_manager          DECIMAL(12,2) NOT NULL,
  quota_director         DECIMAL(12,2) NOT NULL,
  group_pct_supervisor   DECIMAL(6,3)  NOT NULL,
  personal_pct_supervisor DECIMAL(6,3) NOT NULL,
  group_pct_manager      DECIMAL(6,3)  NOT NULL,
  personal_pct_manager   DECIMAL(6,3)  NOT NULL,
  group_pct_director     DECIMAL(6,3)  NOT NULL,
  personal_pct_director  DECIMAL(6,3)  NOT NULL,
  members_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  leaders_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  supervisors_count      INT UNSIGNED  NOT NULL DEFAULT 0,
  managers_count         INT UNSIGNED  NOT NULL DEFAULT 0,
  directors_count        INT UNSIGNED  NOT NULL DEFAULT 0,
  award_count            INT UNSIGNED  NOT NULL DEFAULT 0,
  paid_count             INT UNSIGNED  NOT NULL DEFAULT 0,
  skipped_count          INT UNSIGNED  NOT NULL DEFAULT 0,
  award_total            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  paid_total             DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  note                   VARCHAR(160)  NULL,
  created_at             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (period),
  KEY idx_royalty_periods_status (status, paid_at),
  CONSTRAINT fk_rp_evaluator FOREIGN KEY (evaluated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_rp_payer     FOREIGN KEY (paid_by)      REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_rp_period   CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
  CONSTRAINT chk_rp_totals   CHECK (award_total >= 0 AND paid_total >= 0 AND paid_total <= award_total),
  CONSTRAINT chk_rp_ranks    CHECK (members_count >= leaders_count + supervisors_count
                                     + managers_count + directors_count)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3. royalty_awards — the money ledger ----------------------------
--    One row per member per closed period; UNIQUE (period, user_id)
--    is the money anchor (plan D-08, §13.3). `ledger_id` is not a
--    foreign key: the wallet ledger outlives an award's lifecycle.
CREATE TABLE IF NOT EXISTS royalty_awards (
  id                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  period               CHAR(7)          NOT NULL,
  user_id              INT UNSIGNED     NOT NULL,
  rank_level           TINYINT UNSIGNED NOT NULL,
  directs              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  downline_rank_count  INT UNSIGNED     NOT NULL DEFAULT 0,
  team_size            INT UNSIGNED     NOT NULL DEFAULT 0,
  group_volume         DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  personal_volume      DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  quota                DECIMAL(12,2)    NOT NULL,
  group_pct            DECIMAL(6,3)     NOT NULL,
  personal_pct         DECIMAL(6,3)     NOT NULL,
  amount               DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  status               ENUM('pending','paid','skipped') NOT NULL DEFAULT 'pending',
  reason               VARCHAR(120)     NULL,
  ledger_id            INT UNSIGNED     NULL,
  credited_at          DATETIME         NULL,
  created_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_royalty_award (period, user_id),
  KEY idx_royalty_user (user_id, period),
  KEY idx_royalty_period_status (period, status),
  KEY idx_royalty_status (status, created_at),
  KEY idx_royalty_ledger (ledger_id),
  CONSTRAINT fk_ra_period FOREIGN KEY (period)  REFERENCES royalty_periods (period) ON DELETE CASCADE,
  CONSTRAINT fk_ra_user   FOREIGN KEY (user_id) REFERENCES users (id)              ON DELETE CASCADE,
  CONSTRAINT chk_ra_rank   CHECK (rank_level BETWEEN 2 AND 4),
  CONSTRAINT chk_ra_amount CHECK (amount >= 0),
  CONSTRAINT chk_ra_volume CHECK (group_volume >= 0 AND personal_volume >= 0
                                  AND personal_volume <= group_volume),
  CONSTRAINT chk_ra_rates  CHECK (group_pct BETWEEN 0 AND 100
                                  AND personal_pct BETWEEN 0 AND 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 4. settings — inert until an operator turns the stream on --------
--    Reference defaults exactly (plan §13.4, Appendix A). The earning
--    switch ships off (D-14); the payment switch ships on because it
--    only pauses an evaluated period, and nothing is evaluated yet.
--    `royalty_ledger_since` is the first-payable-month baseline for
--    the close job and for reconciliation (D-15).
INSERT INTO settings (key_name, value) VALUES
  ('royalty_enabled',                   '0'),
  ('royalty_pay_enabled',               '1'),
  ('royalty_leader_directs',            '10'),
  ('royalty_supervisor_leaders',        '10'),
  ('royalty_manager_supervisors',       '10'),
  ('royalty_director_managers',         '10'),
  ('royalty_supervisor_quota',          '500000'),
  ('royalty_manager_quota',             '1000000'),
  ('royalty_director_quota',            '5000000'),
  ('royalty_supervisor_group_pct',      '3.000'),
  ('royalty_supervisor_personal_pct',   '5.000'),
  ('royalty_manager_group_pct',         '5.000'),
  ('royalty_manager_personal_pct',      '10.000'),
  ('royalty_director_group_pct',        '10.000'),
  ('royalty_director_personal_pct',     '15.000'),
  ('royalty_ledger_since',              DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE key_name = key_name;
