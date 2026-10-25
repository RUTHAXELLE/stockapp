-- ============================================================
--  Migration MySQL : plaques réellement posées sur les duplicatas VP
--  et camions
-- ============================================================
--  Traduction de sql/migration_duplicata_plaques.sql. Un duplicata ne
--  remplace pas toujours les deux plaques d'un VP ou d'un camion : le
--  coordinateur saisit le nombre de plaques posées (entre 1 et 2 par
--  véhicule) ; total_plaques_duplicata en découle et la comparaison
--  OptoPlate compare aussi les plaques. Nullable : un point antérieur
--  garde le forfait de 2.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers'
                   AND column_name = 'plaques_vp_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `plaques_vp_duplicata` int DEFAULT NULL',
    'SELECT ''plaques_vp_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers'
                   AND column_name = 'plaques_camion_duplicata') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `plaques_camion_duplicata` int DEFAULT NULL',
    'SELECT ''plaques_camion_duplicata déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
