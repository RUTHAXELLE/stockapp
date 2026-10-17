-- ============================================================
--  Migration MySQL : sites.point_auto_depuis (point généré depuis OptoPlate)
--  Traduction de sql/migration_site_point_auto.sql. Rejouable. 2026-10-17
-- ============================================================
--  MySQL 8 n'accepte pas ADD COLUMN IF NOT EXISTS : information_schema + PREPARE.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'sites'
                   AND column_name  = 'point_auto_depuis');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `sites` ADD COLUMN `point_auto_depuis` date DEFAULT NULL',
    'SELECT ''point_auto_depuis déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;
