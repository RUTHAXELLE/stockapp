-- ============================================================
--  Équipements : statut « perdu » (MySQL)
-- ============================================================
--  Un équipement manquant à l'inventaire peut être déclaré perdu depuis
--  Écarts équipements : statut_stock = 'perdu'. La colonne est un texte
--  depuis migration_enums_en_texte_mysql.sql ; si elle était restée une
--  liste fermée (ENUM), elle est convertie. Rejouable.
-- ============================================================
SET @enum := (SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'equipements'
                 AND column_name = 'statut_stock' AND data_type = 'enum');
SET @sql := IF(@enum > 0,
    'ALTER TABLE `equipements` MODIFY `statut_stock` varchar(30) NOT NULL DEFAULT ''affecte''',
    'SELECT ''statut_stock déjà en texte'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
