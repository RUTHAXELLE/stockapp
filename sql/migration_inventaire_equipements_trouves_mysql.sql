-- ============================================================
--  Migration MySQL : équipements trouvés à l'inventaire
-- ============================================================
--  Pendant un inventaire d'équipements, le coordinateur déclare un
--  équipement présent sur son site mais absent du système. La
--  déclaration attend la décision du gestionnaire de stock avant la validation : créer la
--  fiche (accepte), transférer l'équipement connu d'un autre site
--  (transfere), ou refuser (refuse, motif obligatoire).
--  Cf. includes/equipements_trouves.php.
--
--  Rejouable sans effet (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS inventaire_equipements_trouves (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    inventaire_id INT UNSIGNED NOT NULL,
    nomenclature_id INT UNSIGNED NOT NULL,
    marque VARCHAR(100) DEFAULT NULL,
    modele VARCHAR(100) DEFAULT NULL,
    numero_serie_origine VARCHAR(100) DEFAULT NULL,
    etat VARCHAR(50) NOT NULL DEFAULT 'bon',
    emplacement VARCHAR(150) DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    equipement_existant_id INT UNSIGNED DEFAULT NULL COMMENT 'équipement connu au même n° de série fabricant',
    statut VARCHAR(20) NOT NULL DEFAULT 'en_attente',
    motif_refus TEXT,
    equipement_id INT UNSIGNED DEFAULT NULL COMMENT 'fiche créée ou transférée',
    declare_par INT UNSIGNED DEFAULT NULL,
    declare_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    traite_par INT UNSIGNED DEFAULT NULL,
    traite_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_inv_equip_trouves_inv (inventaire_id),
    KEY idx_inv_equip_trouves_statut (statut),
    CONSTRAINT inv_equip_trouves_inv_fkey FOREIGN KEY (inventaire_id) REFERENCES inventaires_equipements(id) ON DELETE CASCADE,
    CONSTRAINT inv_equip_trouves_nom_fkey FOREIGN KEY (nomenclature_id) REFERENCES nomenclatures(id),
    CONSTRAINT inv_equip_trouves_existant_fkey FOREIGN KEY (equipement_existant_id) REFERENCES equipements(id) ON DELETE SET NULL,
    CONSTRAINT inv_equip_trouves_equip_fkey FOREIGN KEY (equipement_id) REFERENCES equipements(id) ON DELETE SET NULL,
    CONSTRAINT inv_equip_trouves_declare_fkey FOREIGN KEY (declare_par) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT inv_equip_trouves_traite_fkey FOREIGN KEY (traite_par) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT inv_equip_trouves_statut_chk CHECK (statut IN ('en_attente','accepte','transfere','refuse'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
