-- ============================================================
--  Migration MySQL : bobines absentes à l'inventaire
-- ============================================================
--  Pendant l'inventaire, le coordinateur déclare « Absente » une bobine
--  que l'ERP (ou l'import BI) place sur son site mais qu'il ne trouve
--  pas. À la validation, elle part à l'entrepôt principal au statut
--  « absente », hors de tout stock disponible. Le coordinateur qui la
--  retrouve l'ajoute à l'inventaire de son site ; à la validation, elle
--  y est affectée avec la quantité comptée.
--  Cf. includes/bobines_absentes.php, includes/inventaire.php.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_bobines'
                   AND column_name = 'absente') = 0,
    'ALTER TABLE `inventaire_details_bobines` ADD COLUMN `absente` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Déclarée absente du site au comptage''',
    'SELECT ''absente déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_bobines'
                   AND column_name = 'ajoutee') = 0,
    'ALTER TABLE `inventaire_details_bobines` ADD COLUMN `ajoutee` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Bobine retrouvée, ajoutée par le site''',
    'SELECT ''ajoutee déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_bobines'
                   AND column_name = 'site_origine_id') = 0,
    'ALTER TABLE `inventaire_details_bobines` ADD COLUMN `site_origine_id` int unsigned DEFAULT NULL COMMENT ''Site de la bobine au moment de l''''ajout''',
    'SELECT ''site_origine_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_bobines'
                   AND column_name = 'absente_site_id') = 0,
    'ALTER TABLE `op_bobines` ADD COLUMN `absente_site_id` int unsigned DEFAULT NULL COMMENT ''Site où la bobine a été déclarée absente''',
    'SELECT ''absente_site_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_bobines'
                   AND column_name = 'absente_le') = 0,
    'ALTER TABLE `op_bobines` ADD COLUMN `absente_le` date DEFAULT NULL COMMENT ''Date de l''''inventaire qui l''''a déclarée absente''',
    'SELECT ''absente_le déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_bobines'
                   AND column_name = 'absente_inventaire_id') = 0,
    'ALTER TABLE `op_bobines` ADD COLUMN `absente_inventaire_id` int unsigned DEFAULT NULL COMMENT ''Inventaire qui l''''a déclarée absente''',
    'SELECT ''absente_inventaire_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
