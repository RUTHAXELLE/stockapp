-- ============================================================
--  Migration MySQL : droits des modules scindés et des nouveaux modules
-- ============================================================
--  Traduction de quatre migrations de main restées sans équivalent MySQL :
--    · migration_split_demandes_par_ecran.sql
--    · migration_split_equipements_operationnel_vignette.sql
--    · migration_kpi_dashboard_permission_pdg.sql
--    · migration_permissions_groupes_nouveaux_modules.sql
--
--  Sans elles, ces modules n'avaient AUCUNE ligne de droits en production
--  (constaté le 12/10/2026) : seuls admin et superadmin y accédaient.
--  Conséquence la plus visible : « Nouvelle demande », « À valider » et
--  « Traitements IT » refusés à tous les autres rôles — aucun employé ne
--  pouvait créer de demande interne, ni RAF/DAF/DG la valider.
--
--  Même règle que main :
--    · demandes_new / demandes_valider / demandes_it reprennent les droits
--      de « demandes » ; equipements_operationnel ceux de « equipements » ;
--      vignette ceux de « bobines » ;
--    · Dashboard KPI en lecture pour le rôle Lecteur ;
--    · kpi_dashboard, simulation_stocks, tracabilite_endommagements et
--      observations : une ligne à 0 pour chaque rôle — rien n'est ouvert,
--      les droits se donnent dans Administration → Permissions.
--
--  INSERT IGNORE : un droit déjà réglé dans l'écran Permissions (ligne
--  existante) n'est jamais écrasé. Rejouable sans effet.
-- ============================================================

INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'demandes_new', can_read, can_create, can_update, can_delete, can_export
  FROM permissions WHERE module = 'demandes';

INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'demandes_valider', can_read, can_create, can_update, can_delete, can_export
  FROM permissions WHERE module = 'demandes';

INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'demandes_it', can_read, can_create, can_update, can_delete, can_export
  FROM permissions WHERE module = 'demandes';

INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'equipements_operationnel', can_read, can_create, can_update, can_delete, can_export
  FROM permissions WHERE module = 'equipements';

INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT role_id, 'vignette', can_read, can_create, can_update, can_delete, can_export
  FROM permissions WHERE module = 'bobines';

INSERT INTO permissions (role_id, module, can_read)
SELECT r.id, 'kpi_dashboard', 1 FROM roles r WHERE r.slug = 'lecteur'
ON DUPLICATE KEY UPDATE can_read = 1;

INSERT IGNORE INTO permissions (role_id, module, can_create, can_read, can_update, can_delete, can_export)
SELECT r.id, m.module, 0, 0, 0, 0, 0
  FROM roles r
 CROSS JOIN (SELECT 'kpi_dashboard' AS module UNION ALL SELECT 'simulation_stocks'
             UNION ALL SELECT 'tracabilite_endommagements' UNION ALL SELECT 'observations') m;
