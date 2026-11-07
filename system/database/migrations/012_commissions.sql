-- =============================================================
--  Phase 10 migration — direct referral commissions
--
--  Applies to a Phase 9 database and adds the compensation ledger
--  from plan §4.2 / Appendix B, §6.2–6.5 and §7 Phase 10:
--
--    commissions   one row per award (credited or skipped)
--
--  Why this table exists
--
--    Every package activation triggers the compensation plan. The
--    award must be traceable to the member who caused it, the
--    package that prices it and the activation that fired it — and
--    it must be impossible to pay the same event twice.
--
--    UNIQUE (type, source_user_id, user_id, level) is the money
--    anchor (plan §6.5): a replayed approval, a retried job or a
--    double-clicked request cannot create a second row for the same
--    (event, recipient, level). The service also stamps
--    `activations.commission_processed_at` inside the same
--    transaction, so the happy path never relies on catching a
--    duplicate-key error.
--
--    `ledger_id` links the award to the immutable wallet movement
--    (ref_type = 'commission', ref_id = commissions.id). It is not a
--    foreign key: the ledger is append-only and this column records
--    which row the award wrote, not an ownership relation.
--
--    `status = 'skipped'` records an award that was due but not
--    paid (ineligible upline, no package) so the back office can see
--    why a commission is missing. A skipped row never moves money.
--
--  Notes
--
--    • Level 1 is the direct referral bonus (packages.direct_referral_bonus);
--      levels 2–10 are the indirect ladder and arrive in Phase 11. The
--      table is created here because both phases share it.
--    • `amount` is the amount the package's matrix put on the event,
--      recorded even for a skipped row (audit), never credited.
--    • Deleting a member removes their awards (CASCADE) — the app has
--      no delete path, so this is defensive only. Deleting an
--      activation removes its awards the same way.
--
--      mysql -u root <db> < database/migrations/012_commissions.sql
--
--  No `down` note: the table is additive. Dropping it would erase the
--  record of every commission ever paid.
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS commissions (
  id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED     NOT NULL,                 -- recipient
  type           ENUM('direct_referral','indirect_referral') NOT NULL,
  amount         DECIMAL(12,2)    NOT NULL,
  level          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  source_user_id INT UNSIGNED     NOT NULL,                 -- the activated member
  package_id     INT UNSIGNED     NOT NULL,                 -- source package (prices the award)
  activation_id  INT UNSIGNED     NOT NULL,
  status         ENUM('credited','skipped','voided') NOT NULL DEFAULT 'credited',
  reason         VARCHAR(120)     NULL,
  ledger_id      INT UNSIGNED     NULL,                     -- ewallet_ledger row credited
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_commission_event (type, source_user_id, user_id, level),
  KEY idx_commissions_user (user_id, created_at),
  KEY idx_commissions_source (source_user_id, created_at),
  KEY idx_commissions_activation (activation_id),
  KEY idx_commissions_status (status, created_at),
  KEY idx_commissions_ledger (ledger_id),
  CONSTRAINT fk_comm_user       FOREIGN KEY (user_id)        REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_comm_source     FOREIGN KEY (source_user_id) REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_comm_package    FOREIGN KEY (package_id)     REFERENCES packages (id)     ON DELETE RESTRICT,
  CONSTRAINT fk_comm_activation FOREIGN KEY (activation_id)  REFERENCES activations (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Compensation-ledger start
--
--  Commission credits made before this migration exists in the
--  wallet ledger of a live database (QA fixtures, an earlier build)
--  have no award row and never will: their source member, package and
--  activation are not recoverable. This setting marks the instant the
--  award history begins, so CommissionService::reconcile() enforces
--  "one ledger row per credited award" for every movement from here
--  on while reporting the older credits separately as legacy.
--
--  A fresh install never needs it (there is no earlier history); an
--  empty value means "check every commission ledger row".
-- -------------------------------------------------------------
INSERT INTO settings (key_name, value)
SELECT 'commission_ledger_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key_name = 'commission_ledger_since');
