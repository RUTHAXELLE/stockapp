-- ============================================================
--  Migration MySQL : seul le point 18h VALIDÉ par le superviseur retire du
--  stock (films des bobines, rivets, PMMA).
--  Traduction de sql/migration_point_stock_validation.sql. Rejouable. 2026-10-21
-- ============================================================
--  op_points_journaliers.stock_deduit dit si le stock d'un point est retiré :
--    0 = rien n'est retiré ; 1 = retiré par la validation 18h ;
--    2 = retiré sous l'ancienne règle (dès l'enregistrement) : ACQUIS
--        DÉFINITIVEMENT, jamais remis ni retiré une 2e fois.
--  À la création de la colonne (une seule fois), tous les points existants
--  sont marqués 2, sauf ceux en modification demandée ('rejete', stock déjà
--  remis) et les points « historique » reconstitués depuis OptoPlate (aucun
--  mouvement). Ensuite l'application gère seule la colonne.
--  Tant que cette migration n'est pas passée, l'ancien fonctionnement reste.
--  MySQL 8 n'accepte pas ADD COLUMN IF NOT EXISTS : information_schema + PREPARE.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'op_points_journaliers'
                   AND column_name = 'stock_deduit');

SET @sql := IF(@existe = 0,
    'ALTER TABLE `op_points_journaliers` ADD COLUMN `stock_deduit` smallint NOT NULL DEFAULT 0 COMMENT ''0 rien retiré, 1 retiré à la validation 18h, 2 retiré sous l''''ancienne règle (acquis)''',
    'SELECT ''stock_deduit déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- Marquage des points existants : seulement si la colonne vient d'être créée.
SET @sql := IF(@existe = 0,
    'UPDATE `op_points_journaliers` SET `stock_deduit` = 2 WHERE `statut` <> ''rejete'' AND COALESCE(`type_point`, '''') <> ''historique''',
    'SELECT ''points existants déjà marqués'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- Contrôle :
-- SELECT type_point, statut, stock_deduit, COUNT(*) FROM op_points_journaliers GROUP BY 1,2,3 ORDER BY 1,2,3;
