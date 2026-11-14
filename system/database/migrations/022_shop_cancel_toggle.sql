-- =============================================================
--  Phase 18 follow-up migration — the order-cancellation kill switch
--
--  Adds the setting the member and admin cancellation paths read:
--
--    shop_cancel_enabled  0 = no order may be cancelled by a member or an
--                             administrator; no e-wallet refund and no
--                             repeat-purchase escrow reversal is issued.
--                             This is the default.
--                         1 = the normal cancellation/refund path is open
--                             (turn it on only for a special case).
--
--  Automatic expiry of unpaid orders is NOT controlled by this key; it
--  keeps its own `shop_expiry_enabled` switch (migration 009), and an
--  expired unpaid order never produced an escrow award or a refund.
--
--  The value is exposed on /admin/settings (Shop card) as
--  **Enable Cancel of Order**. The code defaults to 0 when the row is
--  missing, so cancellation is closed even before this migration runs.
--
--  Idempotent: safe to re-run, and it never overwrites a value an
--  admin has already chosen. An existing install that predates the
--  setting gets the safe default, not the old open behaviour.
--
--      mysql -u root <db> < database/migrations/022_shop_cancel_toggle.sql
--
--  No `down` note: dropping the row only reverts to the code default,
--  which is also closed (0).
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO settings (key_name, value) VALUES
  ('shop_cancel_enabled', '0')
ON DUPLICATE KEY UPDATE key_name = key_name;
