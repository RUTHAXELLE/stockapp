-- ============================================================
--  Commandes inter-sites (MySQL). 2026-11-22
-- ============================================================
--  Un site (A) commande à un autre site de production (B) au lieu d'un
--  dépôt. Même circuit qu'une commande : validation du superviseur, choix
--  des bobines (coordinateur de B ou GSB), enlèvement chez B par le chauffeur
--  du gestionnaire de stock (stock de B débité), réception par A.
--   - site_source_id   : site fournisseur B (NULL = commande classique) ;
--   - motif_inter_site : motif de la demande ;
--   - chauffeur        : chauffeur envoyé par le gestionnaire de stock.
--  Tant que cette migration n'est pas passée, l'option n'apparaît pas.
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'site_source_id') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `site_source_id` int unsigned DEFAULT NULL COMMENT ''Commande inter-site : site fournisseur (NULL = servie par un dépôt)''',
    'SELECT ''commandes.site_source_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'motif_inter_site') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `motif_inter_site` text DEFAULT NULL COMMENT ''Commande inter-site : motif de la demande''',
    'SELECT ''commandes.motif_inter_site déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'chauffeur') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `chauffeur` varchar(150) DEFAULT NULL COMMENT ''Commande inter-site : chauffeur envoyé pour l''''enlèvement''',
    'SELECT ''commandes.chauffeur déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND index_name = 'idx_commandes_site_source') = 0,
    'CREATE INDEX `idx_commandes_site_source` ON `commandes` (`site_source_id`, `statut`)',
    'SELECT ''idx_commandes_site_source déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
