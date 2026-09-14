-- ============================================================
--  Migration : droits pour les 6 nouveaux écrans Inventaire/Écarts
--  Équipements, PMMA, Rivets (module achats — branche recette-achats)
-- ============================================================
--  Ces écrans sont câblés dans includes/groupes_config.php dans le même
--  groupe de menu qu'« Écarts bobines » — on leur applique donc exactement
--  le même périmètre de droits que migration_ecarts_permissions.sql :
--  lecture seule (can_read + can_export), pas de création/modification.
--  La création de session d'inventaire reste gérée séparément par la
--  page elle-même (délégation / rôles admin), pas par cette permission.
--
--  Idempotente et additive (GREATEST : ne retire jamais un droit existant).
-- ============================================================
BEGIN;

INSERT INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT r.id, m.module, 1, 0, 0, 0, 1
FROM roles r
CROSS JOIN (VALUES
    ('ecarts_equipements'),
    ('ecarts_pmma'),
    ('ecarts_rivets'),
    ('inventaire_equipements'),
    ('inventaire_pmma'),
    ('inventaire_rivets')
) AS m(module)
WHERE r.slug IN ('gestionnaire_stock_bobines', 'superviseur_operation', 'lecteur',
                 'gestionnaire_stock', 'controleur_production')
ON CONFLICT (role_id, module) DO UPDATE SET
    can_read   = GREATEST(permissions.can_read,   EXCLUDED.can_read),
    can_export = GREATEST(permissions.can_export, EXCLUDED.can_export);

COMMIT;

-- Vérification
SELECT r.slug, p.module, p.can_read, p.can_create, p.can_export
FROM permissions p JOIN roles r ON r.id = p.role_id
WHERE p.module IN ('ecarts_equipements','ecarts_pmma','ecarts_rivets',
                   'inventaire_equipements','inventaire_pmma','inventaire_rivets')
ORDER BY p.module, r.slug;
