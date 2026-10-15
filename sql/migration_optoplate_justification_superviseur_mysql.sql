-- ============================================================
--  Migration MySQL : justification du superviseur et écart OptoPlate
-- ============================================================
--  Traduction de sql/migration_optoplate_justification_superviseur.sql
--  (main, 15/10/2026). Avant de valider un point 18h, le superviseur
--  compare la déclaration du coordinateur à OptoPlate ; un écart demande
--  un rejet ou une validation avec justification obligatoire, conservées
--  avec le point (détail et PDF).
--  Rejouable : information_schema + PREPARE (pas de ADD COLUMN IF NOT
--  EXISTS en MySQL 8.0).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'justification_superviseur') = 0,
  'ALTER TABLE op_points_journaliers ADD COLUMN justification_superviseur text DEFAULT NULL COMMENT ''Obligatoire si le superviseur valide malgré un écart OptoPlate''',
  'SELECT ''op_points_journaliers.justification_superviseur déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'ecart_optoplate') = 0,
  'ALTER TABLE op_points_journaliers ADD COLUMN ecart_optoplate text DEFAULT NULL COMMENT ''Comparaison coordinateur / OptoPlate (JSON) au moment de la validation''',
  'SELECT ''op_points_journaliers.ecart_optoplate déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
