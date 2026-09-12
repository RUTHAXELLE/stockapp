-- ============================================================
--  Scission du module "demandes" par écran plutôt qu'un seul droit
--  partagé — demande de recette (2026-09-11) : Mes demandes, Nouvelle
--  demande, À valider et Traitements IT dépendaient tous du même module
--  "demandes" (can_read pour 3 d'entre eux, can_create pour le 4e),
--  impossible à détailler dans Administration → Permissions.
--
--  "demandes" reste le module de "Mes demandes" (nom historique, pas
--  renommé pour ne pas perdre les droits déjà accordés). Trois nouveaux
--  modules, chacun seedé avec les mêmes droits que "demandes" pour que
--  personne ne perde d'accès au déploiement :
--    - demandes_new     -> pages/demandes_new.php      (Nouvelle demande)
--    - demandes_valider -> pages/demandes_a_valider.php (À valider)
--    - demandes_it      -> pages/demandes_it.php        (Traitements IT)
--
--  "Types & circuits" et "Circuits avancés" restent réservés admin/
--  superadmin en dur (roles_include, hors table permissions) : hors
--  périmètre de cette migration.
--
--  Idempotente : ON CONFLICT DO NOTHING, rejouable sans effet de bord.
-- ============================================================

BEGIN;

INSERT INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'demandes_new', can_read, can_create, can_update, can_delete, can_export
FROM permissions
WHERE module = 'demandes'
ON CONFLICT (role_id, module) DO NOTHING;

INSERT INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'demandes_valider', can_read, can_create, can_update, can_delete, can_export
FROM permissions
WHERE module = 'demandes'
ON CONFLICT (role_id, module) DO NOTHING;

INSERT INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'demandes_it', can_read, can_create, can_update, can_delete, can_export
FROM permissions
WHERE module = 'demandes'
ON CONFLICT (role_id, module) DO NOTHING;

COMMIT;

-- Vérification — doit renvoyer le même nombre de lignes pour les 4 modules
SELECT module, COUNT(*) FROM permissions
WHERE module IN ('demandes','demandes_new','demandes_valider','demandes_it')
GROUP BY module ORDER BY module;
