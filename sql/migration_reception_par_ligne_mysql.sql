-- ============================================================
--  Migration MySQL : réception d'une commande ligne par ligne
-- ============================================================
--  À la réception, le coordinateur indique pour chaque ligne ce qu'il a
--  réellement reçu (totalité, partiel, rien) et, pour les bobines, coche
--  les numéros arrivés. Le site n'est crédité que du reçu ; chaque manque
--  ouvre un écart que le gestionnaire de stock tranche : remise au stock
--  d'origine, renvoi (nouvelle commande à expédier) ou perte.
--  Cf. includes/reception_commande.php, pages/commandes.php.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commande_lignes'
                   AND column_name = 'quantite_recue') = 0,
    'ALTER TABLE `commande_lignes` ADD COLUMN `quantite_recue` int unsigned DEFAULT NULL COMMENT ''Quantité déclarée reçue par le site''',
    'SELECT ''quantite_recue déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commande_lignes'
                   AND column_name = 'motif_reception') = 0,
    'ALTER TABLE `commande_lignes` ADD COLUMN `motif_reception` text COMMENT ''Motif si reçu < livré''',
    'SELECT ''motif_reception déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commande_ligne_bobines'
                   AND column_name = 'films_expedies') = 0,
    'ALTER TABLE `commande_ligne_bobines` ADD COLUMN `films_expedies` int unsigned DEFAULT NULL COMMENT ''Films du rouleau à l''''expédition''',
    'SELECT ''films_expedies déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

CREATE TABLE IF NOT EXISTS commande_ecarts_reception (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    commande_id INT UNSIGNED NOT NULL,
    commande_ligne_id INT UNSIGNED NOT NULL,
    bobine_id INT UNSIGNED DEFAULT NULL COMMENT 'bobine / rouleau non reçu',
    quantite INT UNSIGNED NOT NULL COMMENT 'manquant, dans l''unité de la ligne (films pour une bobine)',
    motif TEXT,
    statut VARCHAR(20) NOT NULL DEFAULT 'ouvert',
    declare_par INT UNSIGNED DEFAULT NULL,
    declare_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    traite_par INT UNSIGNED DEFAULT NULL,
    traite_at DATETIME DEFAULT NULL,
    notes_traitement TEXT,
    commande_renvoi_id INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_cmd_ecarts_rec_cmd (commande_id),
    KEY idx_cmd_ecarts_rec_statut (statut),
    CONSTRAINT cmd_ecarts_rec_cmd_fkey FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE,
    CONSTRAINT cmd_ecarts_rec_ligne_fkey FOREIGN KEY (commande_ligne_id) REFERENCES commande_lignes(id) ON DELETE CASCADE,
    CONSTRAINT cmd_ecarts_rec_renvoi_fkey FOREIGN KEY (commande_renvoi_id) REFERENCES commandes(id) ON DELETE SET NULL,
    CONSTRAINT cmd_ecarts_rec_declare_fkey FOREIGN KEY (declare_par) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT cmd_ecarts_rec_traite_fkey FOREIGN KEY (traite_par) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT cmd_ecarts_rec_statut_chk CHECK (statut IN ('ouvert','restitue','renvoye','perte'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
