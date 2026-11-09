-- ============================================================
--  Achats : options « Acheteur » et « Magasin » dans la matrice des
--  permissions (onglet Achats) — MySQL (ligne VPS)
-- ============================================================
--
--  Jusqu'ici ces deux métiers étaient codés en dur par rôle :
--    · acheteur (prise en charge des FEB, arbitrage, offres, lancement de
--      la validation) = rôle superviseur_achat ;
--    · magasin (préparation et expédition des commandes internes, écarts
--      de réception, litiges des réceptions site, réceptions magasin des
--      achats, alertes de stock bas) = rôles gestionnaire_stock et
--      superviseur_achat.
--  Ils deviennent deux modules de la table permissions, cochables pour
--  n'importe quel rôle dans Administration → Permissions → Achats. Le droit
--  qui compte est « Modifier » (can_update).
--
--  Ce script reprend exactement l'existant : rien ne change tant qu'on ne
--  coche pas ces options pour un autre rôle. Tant qu'il n'est pas passé, le
--  code garde l'ancienne règle par rôle (repli dans includes/session.php,
--  droit_fonction()).
--
--  Rejouable sans effet de bord.

INSERT INTO permissions (role_id, module, can_create, can_read, can_update, can_delete, can_export)
SELECT r.id, 'achats_acheteur', 0, 1, 1, 0, 0
FROM roles r
WHERE r.slug = 'superviseur_achat'
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'achats_acheteur');

INSERT INTO permissions (role_id, module, can_create, can_read, can_update, can_delete, can_export)
SELECT r.id, 'achats_magasin', 0, 1, 1, 0, 0
FROM roles r
WHERE r.slug IN ('gestionnaire_stock', 'superviseur_achat')
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'achats_magasin');
