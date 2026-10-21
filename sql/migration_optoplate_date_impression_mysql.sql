-- ============================================================
--  Migration MySQL : OptoPlate, date d'impression des plaques (films / PMMA)
--  Traduction de sql/migration_optoplate_date_impression.sql. Rejouable. 2026-10-21
-- ============================================================
--  Une plaque « reserved » est imprimée mais pas encore posée : elle consomme
--  son film le jour de l'impression, mais ne compte comme engin posé que le
--  jour où elle passe « in_use ». date_impression est figée au premier import
--  qui voit la plaque (vue en reserved : jour de la « Date dernière action »,
--  vue posée : jour de la date d'installation), jamais réécrite.
--  MySQL 8 n'accepte pas ADD COLUMN IF NOT EXISTS : information_schema + PREPARE.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate'
                   AND column_name = 'date_derniere_action');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `import_optoplate` ADD COLUMN `date_derniere_action` datetime DEFAULT NULL COMMENT ''Date dernière action OptoPlate (mise à jour à chaque import)''',
    'SELECT ''date_derniere_action déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate'
                   AND column_name = 'date_impression');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `import_optoplate` ADD COLUMN `date_impression` date DEFAULT NULL COMMENT ''Jour d''''impression du film : fixé au premier import qui voit la plaque''',
    'SELECT ''date_impression déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- Reprise de l'existant : plaques posées -> jour de pose ; plaques encore
-- « reserved » -> date de l'import qui les a rapportées.
UPDATE `import_optoplate`
   SET `date_impression` = CASE WHEN `statut_plaque` = 'reserved' OR `date_installation` IS NULL
                                THEN `date_import` ELSE DATE(`date_installation`) END
 WHERE `date_impression` IS NULL;

SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate'
                   AND index_name = 'idx_optoplate_site_impression');
SET @sql := IF(@existe = 0,
    'CREATE INDEX `idx_optoplate_site_impression` ON `import_optoplate` (`site_id`, `date_impression`)',
    'SELECT ''idx_optoplate_site_impression déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
