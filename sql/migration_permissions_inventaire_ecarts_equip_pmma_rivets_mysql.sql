-- ============================================================
--  Migration MySQL : droits pour les 6 écrans Inventaire/Écarts
--  Équipements, PMMA, Rivets — équivalent MySQL de
--  sql/migration_permissions_inventaire_ecarts_equip_pmma_rivets.sql
--  (branche main / Postgres), pour la branche vps-mysql.
-- ============================================================
--  Même périmètre que la version Postgres : les 5 rôles déjà lecteurs
--  d'écarts_bobines obtiennent can_read + can_export sur ces 6 modules.
--  Idempotente (ON DUPLICATE KEY UPDATE + GREATEST : ne retire jamais un
--  droit existant) — clé unique uq_role_module (role_id, module).
-- ============================================================

INSERT INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT r.id, m.module, 1, 0, 0, 0, 1
FROM roles r
CROSS JOIN (
    SELECT 'ecarts_equipements' AS module
    UNION ALL SELECT 'ecarts_pmma'
    UNION ALL SELECT 'ecarts_rivets'
    UNION ALL SELECT 'inventaire_equipements'
    UNION ALL SELECT 'inventaire_pmma'
    UNION ALL SELECT 'inventaire_rivets'
) AS m
WHERE r.slug IN ('gestionnaire_stock_bobines', 'superviseur_operation', 'lecteur',
                 'gestionnaire_stock', 'controleur_production')
ON DUPLICATE KEY UPDATE
    can_read   = GREATEST(can_read,   VALUES(can_read)),
    can_export = GREATEST(can_export, VALUES(can_export));

-- Vérification
SELECT r.slug, p.module, p.can_read, p.can_create, p.can_export
FROM permissions p JOIN roles r ON r.id = p.role_id
WHERE p.module IN ('ecarts_equipements','ecarts_pmma','ecarts_rivets',
                   'inventaire_equipements','inventaire_pmma','inventaire_rivets')
ORDER BY p.module, r.slug;
