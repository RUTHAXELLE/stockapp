-- ============================================================
--  Migration MySQL : opération Primo / Re-Immatriculation des sites
-- ============================================================
--  Traduction de sql/migration_operation_sites_duplicata_postimmat.sql (main, 13/10/2026).
--
--  sites.operation (primo par défaut, ou reimmat_post) active les sections
--  Duplicatas et Post-Immat du point journalier et des tableaux de bord.
--  Leurs compteurs ne sont jamais fusionnés dans total_engins/total_plaques
--  (qui restent Primo) : les chiffres historiques ne changent pas.
--  CHECK : appliqué par MySQL depuis 8.0.16, comme la contrainte de main.
--
--  Rejouable sans effet : MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS ni
--  CREATE INDEX IF NOT EXISTS ; chaque ajout est conditionné par
--  information_schema et passe par une requête préparée.
-- ============================================================
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sites' AND column_name = 'operation') = 0,
    'ALTER TABLE `sites` ADD COLUMN `operation` varchar(20) NOT NULL DEFAULT ''primo'' COMMENT ''primo (par défaut) ou reimmat_post : active les sections Duplicatas / Post-Immat sur le point journalier et les tableaux de bord.''',
    'SELECT ''sites.operation déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'sites' AND constraint_name = 'sites_operation_check') = 0,
    'ALTER TABLE `sites` ADD CONSTRAINT `sites_operation_check` CHECK (`operation` IN (''primo'',''reimmat_post''))',
    'SELECT ''sites_operation_check déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_vp_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_vp_duplicata` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_vp_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_camion_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_camion_duplicata` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_camion_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_semi_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_semi_duplicata` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_semi_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_moto_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_moto_duplicata` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_moto_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'total_engins_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `total_engins_duplicata` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.total_engins_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'total_plaques_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `total_plaques_duplicata` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.total_plaques_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_vp_postimmat') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_vp_postimmat` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_vp_postimmat déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_camion_postimmat') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_camion_postimmat` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_camion_postimmat déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_semi_postimmat') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_semi_postimmat` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_semi_postimmat déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'nb_moto_postimmat') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `nb_moto_postimmat` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.nb_moto_postimmat déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'total_engins_postimmat') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `total_engins_postimmat` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.total_engins_postimmat déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'total_plaques_postimmat') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `total_plaques_postimmat` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.total_plaques_postimmat déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
