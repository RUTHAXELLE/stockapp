-- ============================================================
--  Sites : point généré automatiquement depuis OptoPlate
--  Idempotente. 2026-10-17
-- ============================================================
--  sites.point_auto_depuis : pour un site qui ne saisit pas son point, date à
--  partir de laquelle chaque import OptoPlate crée/met à jour le point de chaque
--  jour (type « historique »). NULL = désactivé. Se vide tout seul dès que le
--  site saisit un point.
ALTER TABLE sites ADD COLUMN IF NOT EXISTS point_auto_depuis date DEFAULT NULL;
