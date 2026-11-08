-- =============================================================
--  Phase 11 follow-up migration — Package B premium referral ladder
--
--  Package A stays on the V3.0 base ladder:
--    direct ₱500 · levels 2–10: 100, 50, 40, 30, 20, 10, 5, 5, 5
--
--  Package B (₱6,998) now pays exactly 20% more at every level:
--    direct ₱600 · levels 2–10: 120, 60, 48, 36, 24, 12, 6, 6, 6
--
--  The price difference already buys more product value; this premium
--  adds a visible upgrade incentive without changing the plan's shape.
--
--  Safe to re-run: only the old default values are replaced, so an
--  operator's edited package (or any custom package) is never touched.
--
--      mysql -u root <db> < database/migrations/015_package_b_premium_ladder.sql
--
--  No `down` note: restoring Package B to the old ladder would change
--  what future activations pay. Reverse it as an explicit admin edit.
-- =============================================================

SET NAMES utf8mb4;

UPDATE packages
   SET direct_referral_bonus = 600.00
 WHERE slug = 'package-b'
   AND direct_referral_bonus = 500.00;

UPDATE package_levels pl
  JOIN packages p ON p.id = pl.package_id
   SET pl.amount = CASE pl.level
       WHEN 2  THEN 120.00
       WHEN 3  THEN 60.00
       WHEN 4  THEN 48.00
       WHEN 5  THEN 36.00
       WHEN 6  THEN 24.00
       WHEN 7  THEN 12.00
       WHEN 8  THEN 6.00
       WHEN 9  THEN 6.00
       WHEN 10 THEN 6.00
       ELSE pl.amount
   END
 WHERE p.slug = 'package-b'
   AND (
        (pl.level = 2  AND pl.amount = 100.00)
     OR (pl.level = 3  AND pl.amount = 50.00)
     OR (pl.level = 4  AND pl.amount = 40.00)
     OR (pl.level = 5  AND pl.amount = 30.00)
     OR (pl.level = 6  AND pl.amount = 20.00)
     OR (pl.level = 7  AND pl.amount = 10.00)
     OR (pl.level = 8  AND pl.amount = 5.00)
     OR (pl.level = 9  AND pl.amount = 5.00)
     OR (pl.level = 10 AND pl.amount = 5.00)
   );
