-- ============================================================
--  Ajout d'un seuil d'alerte configurable par site/type sur
--  op_stock_rivets, sur le modèle de stock_pmma_site.seuil_alerte.
--
--  Avant cette migration, le seuil était figé en dur à 200 dans
--  pages/operations/rivets.php (3 occurrences), sans possibilité de
--  variation par site ou par type de rivet — contrairement au PMMA
--  qui porte déjà cette colonne. Trouvé lors de l'audit sécurité/
--  cohérence du 2026-09-01.
--
--  Additive uniquement : ADD COLUMN ... DEFAULT 200 ne modifie aucune
--  ligne existante en dehors de peupler la nouvelle colonne, et les
--  INSERT/UPDATE de pages/operations/rivets.php n'énumèrent jamais
--  cette colonne (ils ne touchent que site_id/quantite) donc rien ne
--  casse côté application.
-- ============================================================
BEGIN;

ALTER TABLE op_stock_rivets
  ADD COLUMN IF NOT EXISTS seuil_alerte integer DEFAULT 200;

COMMIT;
