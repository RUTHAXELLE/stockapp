-- ============================================================
--  Dashboard KPI (pages/kpi_dashboard.php) invisible pour le PDG.
--
--  migration_permissions_groupes_nouveaux_modules.sql avait déclaré le
--  module 'kpi_dashboard' à zéro pour tous les rôles (par design : pas
--  d'écran au moment de la migration, attribution prévue ensuite depuis
--  Admin -> Permissions). Cette attribution n'a jamais été faite : le
--  menu ['perm'=>['kpi_dashboard','can_read']] (includes/groupes_config.php)
--  et le require_permission('kpi_dashboard','can_read') de la page
--  restent donc bloqués pour tous les rôles non-admin, y compris le PDG
--  (slug technique 'lecteur') — l'écran a pourtant été conçu pour lui
--  (n° 2.2 CR PDG, cf. commentaire dans groupes_config.php).
--
--  Admin/superadmin ne sont pas concernés : can() les fait passer avant
--  toute vérification en base (includes/session.php).
--
--  Idempotente : ON CONFLICT met can_read à 1 sans toucher aux autres
--  droits déjà en base sur ce module.
-- ============================================================
INSERT INTO permissions (role_id, module, can_read)
SELECT r.id, 'kpi_dashboard', 1 FROM roles r
WHERE r.slug = 'lecteur'
ON CONFLICT (role_id, module) DO UPDATE SET can_read = 1;
