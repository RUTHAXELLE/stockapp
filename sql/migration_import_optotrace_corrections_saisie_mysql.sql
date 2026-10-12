-- ============================================================
--  Migration MySQL : import OptoTrace et demandes de correction GSB
-- ============================================================
--  Deux tables héritées de l'ancien schéma refusent les INSERT du code
--  actuel en MySQL 8 strict (base de production installée le 09/10/2026) :
--
--  1. import_optotrace.plate_number — colonne historique NOT NULL sans
--     défaut, absente de l'INSERT de pages/import_emuci.php. L'import
--     OptoTrace échouait : « Field 'plate_number' doesn't have a default
--     value » (constaté en production le 12/10/2026). Même correction que
--     sur main pour PostgreSQL (commit a2eebd0, DROP NOT NULL).
--
--  2. demandes_correction_saisie — sql/stockapp.sql la crée avec son
--     ancienne structure (point_id, demande_par, motif), et
--     migration_demandes_correction_saisie.sql, qui porte la nouvelle,
--     ne fait rien sur une table déjà existante (CREATE TABLE IF NOT
--     EXISTS). Le refus du stock du matin par le GSB
--     (pages/validation_stock_matin.php) échouait donc à l'INSERT, et
--     l'encart du coordinateur (pages/operations/point_journalier.php)
--     à la lecture.
--     includes/dashboard.php lit encore l'ancienne structure : la table
--     garde donc les deux jeux de colonnes, les anciennes devenant
--     facultatives. Aucune donnée n'est supprimée.
--
--  Rejouable sans effet : chaque ajout est conditionné par
--  information_schema (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

-- ── 1. import_optotrace.plate_number facultative ─────────────
ALTER TABLE `import_optotrace`
  MODIFY `plate_number` varchar(30) DEFAULT NULL COMMENT 'Numéro de plaque (colonne historique, non alimentée par l''import OptoTrace)';

-- ── 2. demandes_correction_saisie : anciennes colonnes facultatives
ALTER TABLE `demandes_correction_saisie`
  MODIFY `point_id`    int unsigned DEFAULT NULL,
  MODIFY `demande_par` int unsigned DEFAULT NULL,
  MODIFY `motif`       text DEFAULT NULL,
  MODIFY `statut`      enum('en_attente','corrige','valide','approuvee','refusee') NOT NULL DEFAULT 'en_attente';

-- ── 3. demandes_correction_saisie : colonnes de la structure actuelle
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

-- ── 4. Index de la lecture coordinateur (site + date) ────────
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = @t AND index_name = 'idx_site_date') = 0,
    'ALTER TABLE `demandes_correction_saisie` ADD KEY `idx_site_date` (`site_id`, `date_cible`)',
    'SELECT ''idx_site_date déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
