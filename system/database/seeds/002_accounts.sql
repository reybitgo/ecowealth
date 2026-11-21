-- =============================================================
--  EcoWealth — Phase 1 accounts (fresh install only)
--
--  admin      / Admin@123   role admin       (back office)
--  superadmin / Super@123   role superadmin  (S-Login console, Phase 14)
--
--  These are development/demo credentials. The go-live checklist
--  (plan §9.6) requires both passwords to be changed and the demo
--  accounts removed before production.
--
--  Idempotent: existing rows are left untouched, so re-seeding
--  never resets a changed password.
--
--  member_status is written explicitly (F-09): the schema default is
--  'pending', while a reset returns staff to 'active'. A fresh install
--  must match the reset state, or the two paths disagree on a staff field
--  that controls sign-in.
-- =============================================================

SET NAMES utf8mb4;

INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active) VALUES
  (
    'admin',
    'admin@sneat.dev',
    '$2y$10$Zb7S7VhUUy1BqfkZz0pjJ.2x1YQr67cqdgExziYaSMKbFkhLx6ZvW',
    'EcoWealth Administrator',
    'admin',
    'active',
    1
  ),
  (
    'superadmin',
    'superadmin@sneat.dev',
    '$2y$10$b49wTwncaKZOyP8XvxSjKu7t5iIQcG5d0bKuPuxOMikhbwIBG9.ya',
    'EcoWealth Super Administrator',
    'superadmin',
    'active',
    1
  )
ON DUPLICATE KEY UPDATE username = username;
