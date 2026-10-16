-- ============================================================
--  Migration MySQL : utilisateur (agent de l'annuaire) d'un équipement
-- ============================================================
--  Traduction de sql/migration_equipements_agent_fonctionnel.sql (main).
--  Rejouable, 16/10/2026.
--
--  equipements.agent_id : l'agent (Annuaire des agents) qui utilise
--  l'équipement. Distinct de utilisateur_id (compte applicatif, utilisé
--  par les affectations) : la plupart des porteurs d'équipement n'ont pas
--  de compte StockApp. Tant que cette migration n'est pas passée, le champ
--  « Utilisateur » n'apparaît pas dans le formulaire des équipements.
--
--  MySQL 8 n'accepte ni ADD COLUMN IF NOT EXISTS ni CREATE INDEX IF NOT
--  EXISTS : chaque étape passe par information_schema et une requête
--  préparée, ce qui la rend rejouable.
-- ============================================================

-- 1. La colonne
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'equipements'
                   AND column_name  = 'agent_id');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `equipements` ADD COLUMN `agent_id` int unsigned DEFAULT NULL',
    'SELECT ''agent_id déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;

-- 2. L'index
SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'equipements'
                   AND index_name   = 'idx_equipements_agent');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `equipements` ADD KEY `idx_equipements_agent` (`agent_id`)',
    'SELECT ''idx_equipements_agent déjà présent'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;

-- 3. La clé étrangère : un agent supprimé ne laisse pas de référence orpheline
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema    = DATABASE()
                   AND table_name      = 'equipements'
                   AND constraint_name = 'fk_equipements_agent'
                   AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `equipements` ADD CONSTRAINT `fk_equipements_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL',
    'SELECT ''fk_equipements_agent déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;
