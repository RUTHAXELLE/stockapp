-- ============================================================
--  Migration MySQL : dépôt source d'une livraison PMMA/rivets
-- ============================================================
--  Traduction de sql/migration_commandes_depot_source.sql (main,
--  12/10/2026). À l'expédition d'une commande contenant du PMMA ou des
--  rivets (pages/commandes.php, action 'emettre_livraison'), le
--  gestionnaire choisit le dépôt réellement débité (site de type
--  'entrepot' ou 'magasin') ; il est tracé sur la commande. Nullable :
--  seules ces commandes en ont besoin.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'depot_source_id') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `depot_source_id` int unsigned DEFAULT NULL',
    'SELECT ''depot_source_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND constraint_name = 'fk_commandes_depot_source') = 0,
    'ALTER TABLE `commandes` ADD CONSTRAINT `fk_commandes_depot_source` FOREIGN KEY (`depot_source_id`) REFERENCES `sites` (`id`)',
    'SELECT ''fk_commandes_depot_source déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
