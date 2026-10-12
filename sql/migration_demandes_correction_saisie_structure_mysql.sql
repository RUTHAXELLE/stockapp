-- ============================================================
--  Migration MySQL : demandes_correction_saisie, structure actuelle
-- ============================================================
--  sql/stockapp.sql crée cette table avec son ancienne structure
--  (point_id, demande_par, motif), et migration_demandes_correction_saisie.sql,
--  qui porte la nouvelle, ne fait rien sur une table déjà existante
--  (CREATE TABLE IF NOT EXISTS). En MySQL 8 strict, le refus du stock du
--  matin par le GSB (pages/validation_stock_matin.php) échoue donc à
--  l'INSERT (« Unknown column 'bobine_id' »), et l'encart du coordinateur
--  (pages/operations/point_journalier.php) à la lecture.
--
--  includes/dashboard.php lit encore l'ancienne structure : la table garde
--  les deux jeux de colonnes, les anciennes devenant facultatives. Aucune
--  donnée n'est supprimée.
--
--  À jouer en production (non appliquée au 12/10/2026). Rejouable sans
--  effet : chaque ajout est conditionné par information_schema (MySQL 8
--  n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

-- ── 1. Anciennes colonnes facultatives
ALTER TABLE `demandes_correction_saisie`
  MODIFY `point_id`    int unsigned DEFAULT NULL,
  MODIFY `demande_par` int unsigned DEFAULT NULL,
  MODIFY `motif`       text DEFAULT NULL,
  MODIFY `statut`      enum('en_attente','corrige','valide','approuvee','refusee') NOT NULL DEFAULT 'en_attente';

-- ── 2. Colonnes de la structure actuelle
SET @t := 'demandes_correction_saisie';

SET @c := 'bobine_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `bobine_id` int unsigned DEFAULT NULL AFTER `id`',
    'SELECT ''bobine_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'site_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `site_id` int unsigned DEFAULT NULL AFTER `bobine_id`',
    'SELECT ''site_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'gsb_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `gsb_id` int unsigned DEFAULT NULL AFTER `site_id`',
    'SELECT ''gsb_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'date_cible';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `date_cible` date DEFAULT NULL AFTER `gsb_id`',
    'SELECT ''date_cible déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'films_pj';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `films_pj` int DEFAULT NULL COMMENT ''Films déclarés dans le PJ coordinateur'' AFTER `date_cible`',
    'SELECT ''films_pj déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'films_emuci';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `films_emuci` int DEFAULT NULL COMMENT ''Films du fichier OptoPlate/EMUCI importé'' AFTER `films_pj`',
    'SELECT ''films_emuci déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'ecart';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `ecart` int DEFAULT NULL COMMENT ''films_emuci - films_pj'' AFTER `films_emuci`',
    'SELECT ''ecart déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'notes_gsb';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `notes_gsb` text DEFAULT NULL AFTER `statut`',
    'SELECT ''notes_gsb déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @c := 'updated_at';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = @c) = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD COLUMN `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SELECT ''updated_at déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- ── 3. Index de la lecture coordinateur (site + date) ────────
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = @t AND index_name = 'idx_site_date') = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD KEY `idx_site_date` (`site_id`, `date_cible`)',
    'SELECT ''idx_site_date déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
