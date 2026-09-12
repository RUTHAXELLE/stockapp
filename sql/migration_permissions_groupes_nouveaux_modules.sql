-- ============================================================
--  Phase 0 du plan PDG (n° 2.7 du CR de réunion) — déclaration des
--  4 modules à venir dans la matrice des permissions.
--
--  Les écrans correspondants n'existent pas encore : ils arrivent en
--  phases 2 et 3. On crée néanmoins leurs lignes de permission dès
--  maintenant, à zéro pour tous les rôles, pour deux raisons :
--
--   1. Le CR pose comme principe transversal que chaque module ait ses
--      permissions configurables. Déclarer le module en même temps que
--      sa page laisse une fenêtre où la page existe sans son droit.
--   2. pages/admin/permissions.php lit la liste des modules depuis son
--      tableau PHP, mais l'état affiché vient de la table permissions.
--      Sans ces lignes, les nouvelles bascules apparaissent à zéro puis
--      sont créées au premier enregistrement — ce qui marche, mais rend
--      l'écart entre code et base invisible jusque-là.
--
--  Aucun rôle ne reçoit de droit : tout est à 0, y compris can_read.
--  L'attribution se fait ensuite depuis Admin > Permissions.
--
--  Idempotente : ON CONFLICT DO NOTHING, rejouable sans effet.
-- ============================================================
BEGIN;

INSERT INTO permissions (role_id, module, can_create, can_read, can_update, can_delete, can_export)
SELECT r.id, m.module, 0, 0, 0, 0, 0
FROM roles r
CROSS JOIN (VALUES
    ('kpi_dashboard'),              -- phase 3 — pages/kpi_dashboard.php
    ('simulation_stocks'),          -- phase 3 — pages/simulation_stocks.php
    ('tracabilite_endommagements'), -- phase 2 — pages/tracabilite_endommagements.php
    ('observations')                -- phase 2 — pages/observations.php
) AS m(module)
ON CONFLICT (role_id, module) DO NOTHING;

COMMIT;

-- Contrôle : doit renvoyer une ligne par rôle pour chacun des 4 modules.
-- SELECT module, COUNT(*) AS nb_roles
-- FROM permissions
-- WHERE module IN ('kpi_dashboard','simulation_stocks',
--                  'tracabilite_endommagements','observations')
-- GROUP BY module ORDER BY module;
