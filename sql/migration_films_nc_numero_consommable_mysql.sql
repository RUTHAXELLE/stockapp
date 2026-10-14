-- ============================================================
--  Migration MySQL : films non conformes et n° du consommable endommagé
-- ============================================================
--  Traduction de sql/migration_films_nc_numero_consommable.sql (main, 13/10/2026).
--
--  Films reçus non conformes par bobine (hors stock utilisable) et numéro
--  du consommable endommagé (rivet, PMMA) dans la déclaration.
--
--  Rejouable sans effet : MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS ni
--  CREATE INDEX IF NOT EXISTS ; chaque ajout est conditionné par
--  information_schema et passe par une requête préparée.
-- ============================================================
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_films_utilises' AND column_name = 'non_conformes') = 0,
    'ALTER TABLE `op_films_utilises` ADD COLUMN `non_conformes` int NOT NULL DEFAULT 0',
    'SELECT ''op_films_utilises.non_conformes déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_endommagements' AND column_name = 'numero_consommable') = 0,
    'ALTER TABLE `op_endommagements` ADD COLUMN `numero_consommable` varchar(100) DEFAULT NULL',
    'SELECT ''op_endommagements.numero_consommable déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
