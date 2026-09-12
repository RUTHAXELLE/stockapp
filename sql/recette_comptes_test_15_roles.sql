-- ============================================================
--  Comptes de test — recette UAT (15 profils)
-- ============================================================
--
--  admin@stockapp.local existe déjà (compte d'installation) : non recréé
--  ici. Les 14 autres comptes ci-dessous couvrent chacun un rôle restant.
--
--  Mot de passe unique pour ces 14 comptes : Recette@2026
--  (hash bcrypt précalculé, cost 12, compatible password_verify() —
--  includes/auth.php)
--
--  Idempotent : un email déjà présent en base est ignoré, on peut
--  rejouer ce script sans risque de doublon.
--
--  Postgres / Neon — coller tel quel dans l'éditeur SQL Neon, ou :
--    psql "<connection string Neon>" -f sql/recette_comptes_test_15_roles.sql
-- ============================================================

BEGIN;

INSERT INTO users (nom, prenom, email, password_hash, role_id, actif, created_at, updated_at)
SELECT 'TEST', v.prenom, v.email,
       '$2b$12$5GKcT8F9YXkGi10tqo2n8u5p2A7Ms4RgmMis3gOLc1dAG/wb/hymi',
       r.id, 1, NOW(), NOW()
FROM (VALUES
  ('SUPERADMIN',         'superadmin@test.local',     'superadmin'),
  ('PDG',                'pdg@test.local',             'lecteur'),
  ('GESTIONNAIRE STOCK', 'gest.stock@test.local',      'gestionnaire_stock'),
  ('COORDINATEUR',       'coordinateur@test.local',    'coordinateur_site'),
  ('SUPERVISEUR OPS',    'sup.operation@test.local',   'superviseur_operation'),
  ('GESTIONNAIRE OPS',   'gest.operation@test.local',  'gestionnaire_operation'),
  ('CONTROLEUR PROD',    'controleur.prod@test.local', 'controleur_production'),
  ('GSB',                'gsb@test.local',             'gestionnaire_stock_bobines'),
  ('SUPERVISEUR IT',     'sup.it@test.local',          'superviseur_it'),
  ('SUPPORT IT',         'support.it@test.local',      'support_it'),
  ('SUPERVISEUR ACHAT',  'sup.achat@test.local',       'superviseur_achat'),
  ('RAF',                'raf@test.local',             'raf'),
  ('DAF',                'daf@test.local',             'daf'),
  ('DIRECTEUR GENERAL',  'dg@test.local',              'directeur_general')
) AS v(prenom, email, role_slug)
JOIN roles r ON r.slug = v.role_slug
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.email = v.email);

-- Le profil Support IT a besoin d'au moins un sous-rôle actif pour ne pas
-- rester "sans accès" (cf. pages/affectations_it.php) : on active
-- « maintenance » par défaut, modifiable ensuite dans Administration →
-- Affectations IT.
INSERT INTO support_it_roles (user_id, sous_role, actif)
SELECT u.id, 'maintenance', 1
FROM users u
WHERE u.email = 'support.it@test.local'
  AND NOT EXISTS (
    SELECT 1 FROM support_it_roles s WHERE s.user_id = u.id AND s.sous_role = 'maintenance'
  );

COMMIT;

-- Vérification — doit lister 14 lignes (+ le compte support.it dans
-- support_it_roles si la 2e requête a bien tourné)
SELECT u.email, u.prenom, r.nom AS role, u.actif
FROM users u
JOIN roles r ON r.id = u.role_id
WHERE u.email LIKE '%@test.local'
ORDER BY r.id;
