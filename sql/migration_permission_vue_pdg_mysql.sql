-- ============================================================
--  Migration MySQL : droit « Vue exécutive (PDG) » (module pdg_overview)
-- ============================================================
--  La Vue exécutive n'avait aucun droit : le menu l'affichait à tous les
--  comptes et la page ne contrôlait que la connexion. Impossible donc de
--  la retirer à un rôle depuis Administration → Permissions, où elle
--  n'apparaissait même pas (demande du 14/10/2026).
--
--  Le module pdg_overview est créé avec la lecture ouverte à tous les
--  rôles : c'est l'état actuel (n° 07 réunion ERP, « visible par tous les
--  comptes »), personne ne perd l'accès au déploiement. L'administrateur
--  la retire ensuite rôle par rôle dans l'onglet Dashboard des permissions.
--
--  À jouer juste après le déploiement : tant qu'elle n'est pas passée,
--  seuls admin et superadmin ouvrent la Vue exécutive.
--
--  INSERT IGNORE : un droit déjà réglé dans l'écran Permissions (ligne
--  existante) n'est jamais écrasé. Rejouable sans effet.
-- ============================================================

INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT id, 'pdg_overview', 1, 0, 0, 0, 0
  FROM roles;
