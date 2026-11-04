-- ============================================================
--  Migration MySQL : type d'équipement rattaché à un article
-- ============================================================
--  Les articles de la famille « Matériel et outillage industriel »
--  deviennent des équipements opérationnels du site qui les reçoit
--  (includes/articles_equipements.php). articles.nomenclature_id garde
--  le type d'équipement utilisé, créé à la première arrivée.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'articles'
                   AND column_name = 'nomenclature_id') = 0,
    'ALTER TABLE `articles` ADD COLUMN `nomenclature_id` int unsigned DEFAULT NULL',
    'SELECT ''nomenclature_id déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND table_name = 'articles'
                   AND constraint_name = 'fk_articles_nomenclature') = 0,
    'ALTER TABLE `articles` ADD CONSTRAINT `fk_articles_nomenclature` FOREIGN KEY (`nomenclature_id`) REFERENCES `nomenclatures` (`id`) ON DELETE SET NULL',
    'SELECT ''fk_articles_nomenclature déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
