-- ============================================================
--  Commandes inter-sites et sorties diverses (MySQL). 2026-11-22, complétée le 2026-11-28
-- ============================================================
--  Sortie diverse : sur une commande validée, le gestionnaire de stock fait
--  servir tout ou partie d'une ligne par un autre site de production (B).
--  Cela crée une commande liée (commande_origine_id, ligne_origine_id), que
--  B accepte (accepte_par, accepte_at) ; puis choix des bobines (coordinateur
--  de B ou GSB), enlèvement chez B par le chauffeur du gestionnaire de stock
--  (stock de B débité), réception par le site demandeur.
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

-- ── Sortie diverse (2026-11-28) : la commande liée naît d'une ligne d'une
--    commande validée (gestionnaire de stock) ; le site fournisseur l'accepte
--    avant de préparer.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'commande_origine_id') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `commande_origine_id` int unsigned DEFAULT NULL COMMENT ''Sortie diverse ou reprise : commande d''''origine''',
    'SELECT ''commandes.commande_origine_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'accepte_par') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `accepte_par` int unsigned DEFAULT NULL COMMENT ''Sortie diverse : acceptée par (site fournisseur)''',
    'SELECT ''commandes.accepte_par déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'accepte_at') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `accepte_at` datetime DEFAULT NULL COMMENT ''Sortie diverse : date d''''acceptation''',
    'SELECT ''commandes.accepte_at déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commande_lignes'
                   AND column_name = 'ligne_origine_id') = 0,
    'ALTER TABLE `commande_lignes` ADD COLUMN `ligne_origine_id` int unsigned DEFAULT NULL COMMENT ''Sortie diverse ou reprise : ligne d''''origine''',
    'SELECT ''commande_lignes.ligne_origine_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND index_name = 'idx_commandes_origine') = 0,
    'CREATE INDEX `idx_commandes_origine` ON `commandes` (`commande_origine_id`)',
    'SELECT ''idx_commandes_origine déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'commande_lignes'
                   AND index_name = 'idx_commande_lignes_origine') = 0,
    'CREATE INDEX `idx_commande_lignes_origine` ON `commande_lignes` (`ligne_origine_id`)',
    'SELECT ''idx_commande_lignes_origine déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
