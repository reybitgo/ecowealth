-- =============================================================
--  Phase 7 follow-up migration — the expiry kill switch
--
--  Adds the setting the shop-expiry cron reads:
--
--    shop_expiry_enabled  1 = the sweep cancels unpaid orders whose
--                             payment / correction window has closed
--                         0 = every run is skipped (proofs and the
--                             admin review keep working)
--
--  The value is exposed on /admin/settings (Payment windows card).
--  The code defaults to 1 when the row is missing, so the job works
--  even before this migration is applied; the row makes the state
--  explicit and editable.
--
--  Idempotent: safe to re-run, and it never overwrites a value an
--  admin has already chosen.
--
--      mysql -u root <db> < database/migrations/009_shop_expiry.sql
--
--  No `down` note: removing the row only reverts to the default (on).
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO settings (key_name, value) VALUES
  ('shop_expiry_enabled', '1')
ON DUPLICATE KEY UPDATE key_name = key_name;
