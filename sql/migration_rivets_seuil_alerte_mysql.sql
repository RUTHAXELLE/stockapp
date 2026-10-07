-- ============================================================
--  Migration MySQL : seuil d'alerte par stock de rivets
-- ============================================================
--  Traduction de sql/migration_rivets_seuil_alerte.sql (branche main),
--  restée sans équivalent MySQL : pages/kpi_dashboard.php (panneau
--  Rivets) lit op_stock_rivets.seuil_alerte et la page plantait
--  (« Unknown column 'seuil_alerte' ») — constaté en rendant la page
--  contre MySQL 8 le 07/10/2026.
--
--  MySQL 8 n'accepte pas ADD COLUMN IF NOT EXISTS : l'idempotence passe
--  par information_schema et une requête préparée.
--
--  ⚠️ Non vérifiée contre la vraie base MySQL de production — même
--  réserve que les autres migrations « _mysql.sql » de cette branche.
-- ============================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'op_stock_rivets'
                   AND column_name  = 'seuil_alerte');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `op_stock_rivets` ADD COLUMN `seuil_alerte` int DEFAULT 200',
    'SELECT ''seuil_alerte déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;
