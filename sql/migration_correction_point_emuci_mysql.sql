-- ============================================================
--  Migration MySQL : corrections du Point EMUCI
-- ============================================================
--  Traduction de sql/migration_correction_point_emuci.sql (branche main),
--  restée sans équivalent MySQL : pages/point_emuci.php lit cette table
--  (includes/point_emuci_corrections.php) et plantait. Circuit : le
--  gestionnaire propose une correction du total déclaré, le coordinateur
--  accepte ou conteste, le gestionnaire valide ou refuse la contestation.
--
--  Traduction : SERIAL -> AUTO_INCREMENT ; clés en int(10) UNSIGNED ;
--  CREATE INDEX IF NOT EXISTS -> index déclarés dans CREATE TABLE ; le
--  bloc DO $$ … pg_constraint qui ajoutait le CHECK devient une
--  contrainte CHECK déclarée dans la table (appliquée depuis MySQL
--  8.0.16, ignorée sans erreur avant). Idempotente : IF NOT EXISTS.
--
--  ⚠️ Non vérifiée contre la vraie base MySQL de production — même
--  réserve que les autres migrations « _mysql.sql » de cette branche.
-- ============================================================

CREATE TABLE IF NOT EXISTS `corrections_point_emuci` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `pj_id`               int(10) UNSIGNED NOT NULL,
  `site_id`             int(10) UNSIGNED NOT NULL,
  `date_point`          date NOT NULL,
  `total_declare`       int NOT NULL,
  `total_propose`       int NOT NULL,
  `motif_gp`            text NOT NULL,
  `gp_id`               int(10) UNSIGNED NOT NULL,
  `statut`              varchar(20) NOT NULL DEFAULT 'en_attente',
  `reponse_coord`       text,
  `total_propose_coord` int DEFAULT NULL,
  `total_final`         int DEFAULT NULL,
  `traite_par`          int(10) UNSIGNED DEFAULT NULL,
  `traite_at`           timestamp NULL DEFAULT NULL,
  `created_at`          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `corrections_point_emuci_pj_idx` (`pj_id`),
  KEY `corrections_point_emuci_site_statut_idx` (`site_id`, `statut`),
  CONSTRAINT `corrections_point_emuci_statut_chk`
    CHECK (`statut` IN ('en_attente','accepte','conteste','valide','refuse')),
  CONSTRAINT `corrections_point_emuci_pj_fk`    FOREIGN KEY (`pj_id`)      REFERENCES `op_points_journaliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `corrections_point_emuci_site_fk`  FOREIGN KEY (`site_id`)    REFERENCES `sites` (`id`),
  CONSTRAINT `corrections_point_emuci_gp_fk`    FOREIGN KEY (`gp_id`)      REFERENCES `users` (`id`),
  CONSTRAINT `corrections_point_emuci_traite_fk` FOREIGN KEY (`traite_par`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
