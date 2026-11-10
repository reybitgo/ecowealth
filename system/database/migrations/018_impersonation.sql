-- =============================================================
--  Phase 14 migration — super-login (superadmin impersonation)
--
--  Applies to a Phase 13 database and adds:
--
--    impersonation_log   one row per S-Login (impersonation) session
--
--  Why the raw nonce is never stored
--
--    A superadmin opens a member session from /slogin. The browser is
--    redirected to /dashboard?imp=<nonce>, where the 64-character nonce
--    is the PHP session id of a cookie-less "impersonation" session.
--    The URL is therefore a bearer credential; only
--    hash('sha256', $nonce) goes into `nonce_hash`. The console cannot
--    re-offer a lost link, and a database dump cannot be replayed as a
--    live member session.
--
--  Why the display names are stored beside the ids
--
--    `superadmin_name` and `target_username` are snapshots taken when
--    the session opened. `superadmin_id` / `target_user_id` keep the
--    live links (both FKs cascade with the account), but the audit must
--    still say who acted even after an account is renamed. The columns
--    are VARCHAR(50) because `users.username` is VARCHAR(50); the plan's
--    Appendix B sketch said 40, which would truncate a valid username.
--
--  Status machine (plan §4.4)
--
--      active ──(Exit / logout)───────────▶ logged_out
--      active ──(TTL / IP-UA / target)────▶ expired
--
--    A terminal row is never re-opened: `mark()` updates only rows whose
--    status is still `active`, so the first end reason wins.
--
--      mysql -u root <db> < database/migrations/018_impersonation.sql
--
--  No `down` note: dropping the table would erase the audit trail of
--  every superadmin impersonation, including the exits already logged.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. impersonation_log — the S-Login audit
--
--    `expires_at` is an absolute DATETIME in the app's Asia/Manila
--    zone (the connection sets time_zone +08:00). `created_at` is the
--    TIMESTAMP the session opened. The status index covers both the
--    overdue sweep and the audit's tab counts.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS impersonation_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  superadmin_id   INT UNSIGNED  NOT NULL,
  superadmin_name VARCHAR(50)   NOT NULL,
  target_user_id  INT UNSIGNED  NOT NULL,
  target_username VARCHAR(50)   NOT NULL,
  nonce_hash      CHAR(64)      NOT NULL,
  ip              VARCHAR(45)   NOT NULL,
  user_agent      VARCHAR(255)  NOT NULL,
  status          ENUM('active','expired','logged_out') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_imp_nonce (nonce_hash),
  KEY idx_imp_super (superadmin_id, created_at),
  KEY idx_imp_target (target_user_id, created_at),
  KEY idx_imp_status (status, expires_at),
  CONSTRAINT fk_imp_super  FOREIGN KEY (superadmin_id)  REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_imp_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
