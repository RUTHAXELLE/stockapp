-- ============================================================
--  Migration MySQL : PMMA non conformes
-- ============================================================
--  Traduction de sql/migration_pmma_non_conformes.sql (main, 13/10/2026).
--
--  Le coordinateur déclare les PMMA reçus non conformes, hors stock
--  utilisable comme les endommagés.
--
--  Rejouable sans effet : MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS ni
--  CREATE INDEX IF NOT EXISTS ; chaque ajout est conditionné par
--  information_schema et passe par une requête préparée.
-- ============================================================
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_pmma_utilises' AND column_name = 'non_conformes') = 0,
    'ALTER TABLE `op_pmma_utilises` ADD COLUMN `non_conformes` int NOT NULL DEFAULT 0',
    'SELECT ''op_pmma_utilises.non_conformes déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
