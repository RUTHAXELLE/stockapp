-- ============================================================
--  Migration MySQL : journée sans production (point journalier 18h)
-- ============================================================
--  Traduction de sql/migration_point_sans_production.sql. Un site peut
--  ne rien produire un jour donné : le coordinateur le déclare dans son
--  point 18h avec un motif obligatoire. Le point est enregistré à zéro
--  (sans mouvement de stock), puis soumis au superviseur qui le confirme
--  (pages/operations/point_journalier.php). Un tel point est dispensé de
--  la règle « films + rivets + PMMA » des points de production.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers'
                   AND column_name = 'sans_production') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `sans_production` tinyint(1) NOT NULL DEFAULT 0',
    'SELECT ''sans_production déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers'
                   AND column_name = 'motif_sans_production') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `motif_sans_production` varchar(30) DEFAULT NULL',
    'SELECT ''motif_sans_production déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers'
                   AND column_name = 'commentaire_sans_production') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `commentaire_sans_production` text DEFAULT NULL',
    'SELECT ''commentaire_sans_production déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
