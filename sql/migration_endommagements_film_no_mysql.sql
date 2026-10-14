-- ============================================================
--  Migration MySQL : op_endommagements.film_no et unicité par film
-- ============================================================
--  Traduction de sql/migration_endommagements_film_no.sql (main, 13/10/2026).
--
--  Sur main, film_no manquait sur les bases où la table existait avant la
--  version « une déclaration par film ». La table MySQL a été créée d'emblée
--  avec film_no et la contrainte (migration_tracabilite_endommagements_mysql) :
--  cette migration ne fait rien sur une base installée par l'installateur,
--  elle garantit seulement le même état partout.
--
--  Rejouable sans effet : MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS ni
--  CREATE INDEX IF NOT EXISTS ; chaque ajout est conditionné par
--  information_schema et passe par une requête préparée.
-- ============================================================
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'op_endommagements' AND column_name = 'film_no') = 0,
    'ALTER TABLE `op_endommagements` ADD COLUMN `film_no` smallint NOT NULL DEFAULT 1 AFTER `site_id`',
    'SELECT ''op_endommagements.film_no déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- Numérotation des lignes existantes, UNIQUEMENT si la contrainte n'existe
-- pas encore (colonne tout juste ajoutée, toutes les lignes à 1). Une fois
-- la contrainte posée, film_no est le rang écrit par le point journalier —
-- par bobine pour les films, par type pour les rivets et le PMMA (lignes
-- sans bobine) : le recalculer par (point, bobine) mélangerait ces types.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'op_endommagements' AND index_name = 'op_endommagements_film_key') = 0,
    'UPDATE op_endommagements e JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY point_id, bobine_id ORDER BY id) AS rn FROM op_endommagements) n ON n.id = e.id SET e.film_no = n.rn WHERE e.film_no <> n.rn',
    'SELECT ''numérotation inutile : contrainte op_endommagements_film_key déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'op_endommagements' AND index_name = 'op_endommagements_film_key') = 0,
    'ALTER TABLE `op_endommagements` ADD UNIQUE KEY `op_endommagements_film_key` (`point_id`, `bobine_id`, `film_no`)',
    'SELECT ''op_endommagements_film_key déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

ALTER TABLE `op_endommagements`
  MODIFY `cause` varchar(255) NOT NULL COMMENT 'Cause en saisie libre (anciens codes : manipulation | defaut_materiel | incident_externe | autre)';
