-- =============================================================
--  Phase 7 migration — payment proofs
--
--  Applies to a Phase 6 database and adds the manual-payment
--  evidence table from plan §4.2 / Appendix B, §7 Phase 7:
--
--    shop_payment_proofs   one row per upload attempt, reviewed by
--                          an admin (pending → verified | rejected)
--
--  The order columns the phase needs were already shipped in Phase 6
--  (`payment_deadline`, `correction_deadline`, `payment_reference`),
--  so this migration is additive and changes no existing table.
--
--  Lifecycle (plan §4.4, §5.4):
--
--    pending        → payment_review   member uploads a proof
--    payment_review → paid             admin verifies (stock committed)
--    payment_review → payment_failed   admin rejects (stock released,
--                                      correction window opens)
--    payment_failed → payment_review   member re-uploads within it
--
--  `attempt_no` is derived by the service (max + 1 under the locked
--  order row), never trusted from input; the row is the audit record
--  of what the member claimed, so the admin can compare it with the
--  amount and reference before verifying.
--
--      mysql -u root <db> < database/migrations/008_payment_proofs.sql
--
--  No seed: proofs are uploaded by members.
--  No `down` note: the table is additive. Dropping it would destroy
--  the payment evidence behind verified orders, so a rollback needs
--  a deliberate dump.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. shop_payment_proofs — manual payment evidence (plan §5.4)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_payment_proofs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       INT UNSIGNED NOT NULL,
  attempt_no     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  file_path      VARCHAR(255) NOT NULL,
  mime           VARCHAR(40)  NULL,
  size_bytes     INT UNSIGNED NULL,
  amount_claimed DECIMAL(12,2) NULL,
  reference_no   VARCHAR(40)  NULL,
  transfer_date  DATE         NULL,
  status         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  reject_reason  VARCHAR(160) NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_proofs_order (order_id, attempt_no),
  KEY idx_proofs_reference (reference_no),
  CONSTRAINT fk_proofs_order    FOREIGN KEY (order_id)    REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_proofs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
