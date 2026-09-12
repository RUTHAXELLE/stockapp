-- ============================================================
--  Scission de deux modules de permission en fonction de la catégorie
--  affichée sur la même page (pages/equipements.php et pages/operations/
--  bobines.php gèrent chacune deux catégories via ?categorie=), pour
--  pouvoir les accorder indépendamment dans Administration → Permissions.
--
--  Demande de recette (2026-09-11) : l'onglet Stock ne comptait que 7
--  modules pour 9 écrans de menu, "Équipements Opérationnel" et
--  "Vignette" partageant chacun le module de leur écran jumeau
--  ("equipements" / "bobines") sans pouvoir être octroyés séparément.
--
--  Les nouveaux modules sont seedés avec exactement les mêmes droits que
--  leur module d'origine, pour qu'aucun rôle ne perde d'accès au moment
--  du déploiement — à réajuster ensuite si les deux écrans doivent
--  diverger pour un rôle donné.
--
--  Idempotente : ON CONFLICT DO NOTHING, rejouable sans effet de bord.
-- ============================================================

BEGIN;

INSERT INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'equipements_operationnel', can_read, can_create, can_update, can_delete, can_export
FROM permissions
WHERE module = 'equipements'
ON CONFLICT (role_id, module) DO NOTHING;

INSERT INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'vignette', can_read, can_create, can_update, can_delete, can_export
FROM permissions
WHERE module = 'bobines'
ON CONFLICT (role_id, module) DO NOTHING;

COMMIT;

-- Vérification — doit renvoyer le même nombre de lignes pour chaque paire
-- (equipements / equipements_operationnel, bobines / vignette)
SELECT module, COUNT(*) FROM permissions
WHERE module IN ('equipements','equipements_operationnel','bobines','vignette')
GROUP BY module ORDER BY module;
