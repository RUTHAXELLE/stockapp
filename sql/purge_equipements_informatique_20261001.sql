-- ============================================================
-- Purge du stock "Équipements Informatique" (categorie='informatique')
-- Ne touche PAS : equipements categorie='operationnel', nomenclatures,
-- bobines, rivets, ni aucune autre table métier.
-- À exécuter manuellement : psql "<connection string Neon>" < sql/purge_equipements_informatique_20261001.sql
-- ============================================================

BEGIN;

-- 1) Mouvements de stock liés aux équipements informatique
DELETE FROM mouvements_equipements
WHERE equipement_id IN (SELECT id FROM equipements WHERE categorie = 'informatique');

-- 2) Affectations (site/utilisateur) des équipements informatique
DELETE FROM affectations_equipements
WHERE equipement_id IN (SELECT id FROM equipements WHERE categorie = 'informatique');

-- 3) Suppression des équipements informatique eux-mêmes
--    (cascade automatique : equipement_affectations, inventaire_details_equipements,
--     ecarts_equipements ; interventions_maintenance.equipement_id et
--     receptions_site.equipement_id passent à NULL automatiquement)
DELETE FROM equipements WHERE categorie = 'informatique';

-- 4) Recalage propre de la séquence id (pas un reset à 0 brutal :
--    tient compte des équipements "operationnel" restants pour éviter
--    tout conflit de clé primaire sur une future insertion)
SELECT setval('equipements_id_seq', GREATEST((SELECT COALESCE(MAX(id), 0) FROM equipements), 1));

COMMIT;
