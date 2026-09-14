-- ============================================================
--  Migration MySQL : table op_pmma_utilises manquante sur vps-mysql
-- ============================================================
--  Utilisée par pages/operations/point_journalier.php et
--  pages/operations/point_pdf.php mais absente de sql/stockapp.sql sur
--  cette branche — la page plante (table inconnue) dès qu'un point
--  journalier a du PMMA saisi.
--
--  Structure reprise telle quelle de sql/stockapp_pg.sql (branche main),
--  où le commentaire d'origine précise : « creee par fix_columns.php
--  cote MySQL » — ce script MySQL a dû tourner sur l'environnement de
--  départ mais n'a jamais été reversé dans sql/stockapp.sql. Convention
--  de types alignée sur la table sœur op_films_utilises (int UNSIGNED).
--
--  ⚠️ Structure déduite du code + du schéma Postgres, PAS vérifiée contre
--  une vraie base MySQL de production (indisponible au moment d'écrire ce
--  fichier — administrateur BD non joignable). À confirmer/ajuster avant
--  d'aller en production si une vraie table MySQL existe déjà quelque part
--  avec une structure différente.
-- ============================================================

CREATE TABLE IF NOT EXISTS `op_pmma_utilises` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `point_id` int(10) UNSIGNED NOT NULL,
  `type_pmma` varchar(50) NOT NULL,
  `utilises` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `endommages` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `op_pmma_utilises_idx_point_id` (`point_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
