-- ============================================================
--  Migration MySQL : rivets endommagés par type
-- ============================================================
--  Traduction de sql/migration_rivets_endommages_par_type.sql (main, 13/10/2026).
--
--  Les rivets endommagés du point journalier sont saisis par type
--  (gonflables / éclatés).
--
--  Rejouable sans effet : MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS ni
--  CREATE INDEX IF NOT EXISTS ; chaque ajout est conditionné par
--  information_schema et passe par une requête préparée.
-- ============================================================
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'rivets_gonflables_end') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `rivets_gonflables_end` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.rivets_gonflables_end déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers' AND column_name = 'rivets_eclates_end') = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `rivets_eclates_end` int NOT NULL DEFAULT 0',
    'SELECT ''op_points_journaliers.rivets_eclates_end déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
