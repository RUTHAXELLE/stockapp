-- ============================================================
--  Migration MySQL : bobines commandées depuis les Commandes générales
--  (ligne « bobine ») — fusion de « Commande bobines » (option B).
--  Traduction de sql/migration_commande_ligne_bobines.sql. Rejouable. 2026-10-24
-- ============================================================
--  Après validation du superviseur, le GSB choisit les bobines précises d'une
--  ligne bobine : 'assigne' (réservée, encore à l'entrepôt), puis 'recu' quand
--  le site réceptionne (elle passe alors au site, en stock).
--  Tant que cette migration n'est pas passée, une ligne bobine garde l'ancien
--  fonctionnement (aucune affectation).
CREATE TABLE IF NOT EXISTS `commande_ligne_bobines` (
  `id`                int UNSIGNED NOT NULL AUTO_INCREMENT,
  `commande_id`       int UNSIGNED NOT NULL,
  `commande_ligne_id` int UNSIGNED NOT NULL,
  `bobine_id`         int UNSIGNED NOT NULL,
  `numero_bobine`     varchar(50)  NOT NULL,
  `statut`            varchar(20)  NOT NULL DEFAULT 'assigne' COMMENT 'assigne | recu',
  `assigne_par`       int UNSIGNED DEFAULT NULL,
  `assigne_at`        datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clb_commande` (`commande_id`),
  KEY `idx_clb_ligne` (`commande_ligne_id`),
  KEY `idx_clb_bobine` (`bobine_id`, `statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
