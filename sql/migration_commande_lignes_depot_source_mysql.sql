-- ============================================================
--  Migration MySQL : dépôt source choisi LIGNE PAR LIGNE
-- ============================================================
--  À l'expédition d'une commande (pages/commandes.php, action
--  'emettre_livraison'), le gestionnaire choisit pour chaque ligne
--  article / PMMA / rivets le dépôt d'où elle sort (site de type
--  'entrepot' ou 'magasin') : c'est ce dépôt qui est débité. Il est
--  tracé sur la ligne. commandes.depot_source_id ne garde le dépôt que
--  quand toute la commande sort du même.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commande_lignes'
                   AND column_name = 'depot_source_id') = 0,
    'ALTER TABLE `commande_lignes` ADD COLUMN `depot_source_id` int unsigned DEFAULT NULL',
    'SELECT ''depot_source_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND table_name = 'commande_lignes'
                   AND constraint_name = 'fk_commande_lignes_depot_source') = 0,
    'ALTER TABLE `commande_lignes` ADD CONSTRAINT `fk_commande_lignes_depot_source` FOREIGN KEY (`depot_source_id`) REFERENCES `sites` (`id`)',
    'SELECT ''fk_commande_lignes_depot_source déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
