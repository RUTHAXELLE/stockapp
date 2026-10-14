-- ============================================================
--  Migration MySQL : endommagements de rivets et de PMMA
-- ============================================================
--  Traduction de sql/migration_endommagements_rivets_pmma.sql (main, 13/10/2026).
--
--  Une déclaration par unité endommagée : film (défaut), rivet
--  gonflable/éclaté ou PMMA. objet = film | rivet | pmma, sous_type précise
--  le type de rivet ou de PMMA.
--
--  Rejouable sans effet : MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS ni
--  CREATE INDEX IF NOT EXISTS ; chaque ajout est conditionné par
--  information_schema et passe par une requête préparée.
-- ============================================================
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_endommagements' AND column_name = 'objet') = 0,
    'ALTER TABLE `op_endommagements` ADD COLUMN `objet` varchar(10) NOT NULL DEFAULT ''film'' COMMENT ''film | rivet | pmma''',
    'SELECT ''op_endommagements.objet déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_endommagements' AND column_name = 'sous_type') = 0,
    'ALTER TABLE `op_endommagements` ADD COLUMN `sous_type` varchar(50) DEFAULT NULL',
    'SELECT ''op_endommagements.sous_type déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'op_endommagements' AND index_name = 'idx_endo_objet') = 0,
    'ALTER TABLE `op_endommagements` ADD KEY `idx_endo_objet` (`objet`)',
    'SELECT ''idx_endo_objet déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
