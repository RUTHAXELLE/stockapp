-- ============================================================
--  Migration MySQL : listes fermées (ENUM) -> texte, comme sur main
-- ============================================================
--  Sur main (PostgreSQL), ces 46 colonnes sont en texte libre (text, sans
--  contrainte CHECK). Le schéma MySQL les a héritées de l'ancien dump
--  MariaDB sous forme d'ENUM : le code y écrit des valeurs absentes de ces
--  listes, que MariaDB non strict remplaçait silencieusement par '' et
--  que MySQL 8 strict refuse (« Data truncated for column »). Relevé par
--  tools/verifier.php le 12/10/2026 — 26 écritures refusées dont :
--    · mouvements_bobines.type : 'sync_emuci' (import OptoTrace d'une
--      bobine connue), 'epuisement_auto' (tâche des 5 minutes), 'perte',
--      'ajustement_gsb', 'correction_coord', 'correction_qte_initiale' ;
--    · notifications.type, corrections_bobines.statut, ecarts_bobines.source
--      (validation du stock du matin) ; commandes.statut
--      'modification_demandee' ; op_points_journaliers.statut 'rejete'.
--  Les valeurs passées par variable ne sont pas contrôlables : toutes les
--  listes fermées sont donc converties, pas seulement celles relevées.
--  La colonne calculée comparaisons_stock.statut_ecart reste une liste.
--
--  Données conservées (une valeur de liste devient le même texte).
--  Nullabilité, valeur par défaut et commentaire de chaque colonne
--  conservés. Rejouable sans effet.
-- ============================================================

ALTER TABLE `affectations_equipements`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','valide_n1','recu','refuse')

ALTER TABLE `articles`
  MODIFY `type_article` VARCHAR(50) NULL DEFAULT 'autre';   -- était enum('papeterie','encre','materiel','pieces','fournitures','autre')

ALTER TABLE `audit_log`
  MODIFY `action` VARCHAR(50) NOT NULL;   -- était enum('CREATE','READ','UPDATE','DELETE','LOGIN','LOGOUT','EXPORT','TRANSFER')

ALTER TABLE `bilans_mensuels_bobines`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_cours';   -- était enum('en_cours','valide')

ALTER TABLE `commande_lignes`
  MODIFY `type_article` VARCHAR(50) NOT NULL DEFAULT 'article',   -- était enum('article','consommable','bobine','pmma','rivet','equipement','autre')
  MODIFY `statut_ligne` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','valide','modifie','rejete')

ALTER TABLE `commandes`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','en_attente_livraison','en_cours_livraison','livre','recu','rejete','annule')

ALTER TABLE `commandes_bobines`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','valide','en_preparation','expedie','recu','rejete','annule')

ALTER TABLE `commandes_bobines_lignes`
  MODIFY `statut` VARCHAR(50) NULL DEFAULT 'assigne';   -- était enum('assigne','recu')

ALTER TABLE `configurations_site`
  MODIFY `type_site` VARCHAR(50) NOT NULL;   -- était enum('saisie','pose','mixte','caissier','entrepot','siege')

ALTER TABLE `consommables`
  MODIFY `unite` VARCHAR(50) NOT NULL DEFAULT 'unite';   -- était enum('unite','kg','litre','boite','rame','paquet')

ALTER TABLE `corrections_bobines`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','approuvee','contreproposee','refusee')

ALTER TABLE `demandes_bobines`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','approuvee','refusee')

ALTER TABLE `demandes_correction_saisie`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','corrige','valide','approuvee','refusee')

ALTER TABLE `distribution_lignes`
  MODIFY `statut` VARCHAR(50) NULL DEFAULT 'en_cours_livraison';   -- était enum('en_cours_livraison','livre','litige')

ALTER TABLE `distributions_site`
  MODIFY `statut` VARCHAR(50) NULL DEFAULT 'en_cours_livraison';   -- était enum('en_cours_livraison','livre','annule')

ALTER TABLE `ecarts_bobines`
  MODIFY `source` VARCHAR(50) NOT NULL DEFAULT 'manuel',   -- était enum('inventaire','manuel','import')
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'ouvert';   -- était enum('ouvert','resolu','ignore')

ALTER TABLE `emuci_sites_inconnus`
  MODIFY `type_import` VARCHAR(50) NOT NULL,   -- était enum('optoplate','optotrace')
  MODIFY `statut` VARCHAR(50) NULL DEFAULT 'en_attente';   -- était enum('en_attente','cree','lie','ignore')

ALTER TABLE `equipements`
  MODIFY `categorie` VARCHAR(50) NOT NULL DEFAULT 'informatique' COMMENT 'Catégorie : informatique ou opérationnel',   -- était enum('informatique','operationnel')
  MODIFY `etat` VARCHAR(50) NOT NULL DEFAULT 'neuf';   -- était enum('neuf','bon','usage','hs','reforme')

ALTER TABLE `import_sessions_emuci`
  MODIFY `type_import` VARCHAR(50) NOT NULL,   -- était enum('optoplate','optotrace','les_deux')
  MODIFY `statut` VARCHAR(50) NULL DEFAULT 'en_cours';   -- était enum('en_cours','termine','erreur')

ALTER TABLE `interventions_maintenance`
  MODIFY `type_action` VARCHAR(50) NOT NULL DEFAULT 'maintenance_corrective',   -- était enum('maintenance_preventive','maintenance_corrective','installation','remplacement','diagnostic','formation','autre')
  MODIFY `statut_apres` VARCHAR(50) NOT NULL DEFAULT 'resolu';   -- était enum('resolu','partiel','en_attente','escalade')

ALTER TABLE `inventaires_bobines`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'brouillon',   -- était enum('brouillon','valide','annule')
  MODIFY `type_inventaire` VARCHAR(50) NOT NULL DEFAULT 'journalier' COMMENT 'Journalier = suivi quotidien, Mensuel = bilan de fin de mois';   -- était enum('journalier','mensuel')

ALTER TABLE `livraisons_consommables`
  MODIFY `type_mouvement` VARCHAR(50) NULL DEFAULT 'distribution';   -- était enum('distribution','retour')

ALTER TABLE `mouvements_bobines`
  MODIFY `type` VARCHAR(50) NOT NULL;   -- était enum('entree','sortie','ajustement_inventaire','transfert')

ALTER TABLE `mouvements_equipements`
  MODIFY `type` VARCHAR(50) NOT NULL;   -- était enum('entree','sortie','transfert','reforme','maintenance')

ALTER TABLE `mouvements_stock`
  MODIFY `type_mouvement` VARCHAR(50) NOT NULL;   -- était enum('entree','sortie','ajustement','distribution','reception_fourn','reception_site')

ALTER TABLE `nomenclatures`
  MODIFY `categorie` VARCHAR(50) NOT NULL DEFAULT 'informatique' COMMENT 'Catégorie de la nomenclature';   -- était enum('informatique','operationnel')

ALTER TABLE `notifications`
  MODIFY `type` VARCHAR(50) NOT NULL;   -- était enum('fin_cycle','stock_bas','alerte_conso','info')

ALTER TABLE `op_bobines`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_stock';   -- était enum('en_stock','en_cours','retiree','epuisee','perdue')

ALTER TABLE `op_points_journaliers`
  MODIFY `type_point` VARCHAR(50) NOT NULL DEFAULT 'point_18h',   -- était enum('point_9h','point_13h','point_18h','debut_activite','mi_journee','fin_journee','final','intermediaire')
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'brouillon';   -- était enum('suivi','brouillon','en_attente_validation','valide','refuse')

ALTER TABLE `points_emuci`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'brouillon';   -- était enum('brouillon','soumis','valide')

ALTER TABLE `points_journaliers_info`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'brouillon';   -- était enum('brouillon','valide')

ALTER TABLE `rapports_journaliers_info`
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'brouillon';   -- était enum('brouillon','valide')

ALTER TABLE `receptions_fournisseur`
  MODIFY `statut` VARCHAR(50) NULL DEFAULT 'en_attente';   -- était enum('en_attente','recu_partiel','recu_complet')

ALTER TABLE `receptions_site`
  MODIFY `type_reception` VARCHAR(50) NOT NULL,   -- était enum('equipement','consommable')
  MODIFY `statut` VARCHAR(50) NOT NULL DEFAULT 'en_attente';   -- était enum('en_attente','receptionnee','litige')

ALTER TABLE `stock_pmma`
  MODIFY `type_mouvement` VARCHAR(50) NOT NULL;   -- était enum('entree','sortie')

ALTER TABLE `support_it_roles`
  MODIFY `sous_role` VARCHAR(50) NOT NULL;   -- était enum('maintenance','controleur_production','gestionnaire_bobines')

ALTER TABLE `validations_stock_matin`
  MODIFY `statut` VARCHAR(50) NOT NULL;   -- était enum('valide_auto','valide_gsb','autorise_ecart','reajuste','refuse')
