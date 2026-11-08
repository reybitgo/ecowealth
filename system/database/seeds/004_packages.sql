-- =============================================================
--  EcoWealth — Phase 3 demo packages (plan §4.5)
--
--  Package A (₱4,498) and Package B (₱6,998) with the 2–10 level
--  ladder and their contents. Level 1 is the package's
--  direct_referral_bonus (₱500) and is never duplicated into
--  package_levels.
--
--  Values follow the plan's worked example; the official price
--  list (decision D10) is still open, so the descriptions say so.
--
--  Idempotent: re-running adds missing rows and never overwrites a
--  package an admin has edited. Levels/contents are only seeded
--  when the package has none — an admin who clears them stays
--  cleared.
-- =============================================================

SET NAMES utf8mb4;

-- Service product bundled with Package B (stock tracked manually).
INSERT INTO products
  (sku, name, slug, price, stock, short_description, description, status, sort_order)
VALUES
  (
    'EW-COLONICS', 'Colonics Session', 'colonics-session', 1500.00, 20,
    'One colonics session, booked after activation.',
    'Service product bundled with Package B. Stock is tracked manually; the session is scheduled with the clinic.',
    'active', 70
  )
ON DUPLICATE KEY UPDATE name = name;

-- Packages -----------------------------------------------------
INSERT INTO packages
  (name, slug, price, product_value, direct_referral_bonus, indirect_enabled,
   description, perks, status, sort_order)
VALUES
  (
    'Package A', 'package-a', 4498.00, 4750.00, 500.00, 1,
    'Entry associate package. Demo values follow the plan''s worked example — confirm the official price list before launch.',
    'Associate status\nShop access\nDirect referral + 10-level unilevel ladder',
    'active', 10
  ),
  (
    'Package B', 'package-b', 6998.00, 8300.00, 600.00, 1,
    'Growth associate package. Demo values follow the plan''s worked example; the referral ladder is 20% above Package A to reward the larger entry package. Confirm the official price list before launch.',
    'Associate status\nShop access\nDirect referral + 10-level unilevel ladder\nLarger product bundle',
    'active', 20
  )
ON DUPLICATE KEY UPDATE name = name;

-- Indirect ladder, levels 2–10.
-- Package A keeps the V3.0 base ladder; Package B pays exactly 20% more
-- at every level (600 / 120,60,48,36,24,12,6,6,6 vs 500 / 100,50,40,30,20,10,5,5,5).
INSERT INTO package_levels (package_id, level, amount)
SELECT p.id, v.level, v.amount
FROM packages p
JOIN (
            SELECT 2 AS level, 100.00 AS amount
  UNION ALL SELECT 3, 50.00
  UNION ALL SELECT 4, 40.00
  UNION ALL SELECT 5, 30.00
  UNION ALL SELECT 6, 20.00
  UNION ALL SELECT 7, 10.00
  UNION ALL SELECT 8, 5.00
  UNION ALL SELECT 9, 5.00
  UNION ALL SELECT 10, 5.00
) v
WHERE p.slug = 'package-a'
  AND NOT EXISTS (SELECT 1 FROM package_levels pl WHERE pl.package_id = p.id);

INSERT INTO package_levels (package_id, level, amount)
SELECT p.id, v.level, v.amount
FROM packages p
JOIN (
            SELECT 2 AS level, 120.00 AS amount
  UNION ALL SELECT 3, 60.00
  UNION ALL SELECT 4, 48.00
  UNION ALL SELECT 5, 36.00
  UNION ALL SELECT 6, 24.00
  UNION ALL SELECT 7, 12.00
  UNION ALL SELECT 8, 6.00
  UNION ALL SELECT 9, 6.00
  UNION ALL SELECT 10, 6.00
) v
WHERE p.slug = 'package-b'
  AND NOT EXISTS (SELECT 1 FROM package_levels pl WHERE pl.package_id = p.id);

-- Package A contents: 1 Coffee, 1 Immune Booster, 2 Spirulina,
-- 1 Serpentina, 2 Kojic Soap -----------------------------------
INSERT INTO package_products (package_id, product_id, quantity)
SELECT p.id, pr.id, v.quantity
FROM packages p
JOIN (
            SELECT 'EW-COFFEE' AS sku, 1 AS quantity
  UNION ALL SELECT 'EW-IMMUNE', 1
  UNION ALL SELECT 'EW-SPIRULINA', 2
  UNION ALL SELECT 'EW-SERPENTINA', 1
  UNION ALL SELECT 'EW-KOJIC', 2
) v
JOIN products pr ON pr.sku = v.sku
WHERE p.slug = 'package-a'
  AND NOT EXISTS (SELECT 1 FROM package_products pp WHERE pp.package_id = p.id);

-- Package B contents: 1 Coffee, 1 Immune Booster, 1 Spirulina,
-- 1 Stone Buster, 1 Colonics session ---------------------------
INSERT INTO package_products (package_id, product_id, quantity)
SELECT p.id, pr.id, v.quantity
FROM packages p
JOIN (
            SELECT 'EW-COFFEE' AS sku, 1 AS quantity
  UNION ALL SELECT 'EW-IMMUNE', 1
  UNION ALL SELECT 'EW-SPIRULINA', 1
  UNION ALL SELECT 'EW-STONE', 1
  UNION ALL SELECT 'EW-COLONICS', 1
) v
JOIN products pr ON pr.sku = v.sku
WHERE p.slug = 'package-b'
  AND NOT EXISTS (SELECT 1 FROM package_products pp WHERE pp.package_id = p.id);
