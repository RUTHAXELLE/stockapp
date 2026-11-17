-- ============================================================
--  Migration MySQL : revue des permissions (17/11/2026)
-- ============================================================
--  Accompagne deux changements de code, pour que personne ne gagne ni ne
--  perde d'accès le jour du déploiement :
--
--  1. Support IT : les sous-rôles (Maintenance, Contrôleur production,
--     Gestionnaire bobines) ne filtrent plus que les modules techniques
--     (interventions, equipements, sites, import_emuci, point_emuci,
--     bobines, inventaire_bobines). Les autres modules suivent désormais
--     la matrice. Jusqu'ici, toute case cochée hors de cette liste restait
--     sans effet : on remet donc à zéro ces droits jamais exercés, sauf
--     ceux qu'on ouvre volontairement (demandes internes, rapport
--     journalier, Achats/FEB, vue exécutive).
--
--  2. Colonne « Exporter » : appliquée désormais aux exports de PMMA,
--     vue stock bobines, rapports GSB, inventaires, rivets, équipements,
--     résumé superviseur et sites, qui ne demandaient que la lecture. On la
--     coche partout où la lecture l'est, sur ces modules uniquement.
--
--  À usage unique : la table migrations_ponctuelles retient le passage. Un
--  deuxième passage ne fait rien, et ne revient donc jamais sur un réglage
--  fait ensuite dans Administration → Permissions.
-- ============================================================

CREATE TABLE IF NOT EXISTS migrations_ponctuelles (
  nom          VARCHAR(100) NOT NULL PRIMARY KEY,
  appliquee_le DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1. Support IT : droits sans effet jusqu'ici, remis à zéro
UPDATE permissions p
  JOIN roles r ON r.id = p.role_id
   SET p.can_read = 0, p.can_create = 0, p.can_update = 0, p.can_delete = 0, p.can_export = 0
 WHERE r.slug = 'support_it'
   AND p.module NOT IN ('interventions','equipements','sites','import_emuci','point_emuci',
                        'bobines','inventaire_bobines',
                        'demandes','demandes_new','demandes_valider','demandes_it',
                        'rapport_journalier','achats','pdg_overview',
                        'achats_acheteur','achats_magasin')
   AND NOT EXISTS (SELECT 1 FROM migrations_ponctuelles m WHERE m.nom = 'permissions_revue_2026_11');

-- 2. « Exporter » coché là où « Lire » l'est, sur les modules désormais contrôlés
UPDATE permissions p
   SET p.can_export = 1
 WHERE p.can_read = 1 AND p.can_export = 0
   AND p.module IN ('pmma','stock_bobines','rapports_gsb',
                    'inventaire_bobines','inventaire_rivets','inventaire_pmma','inventaire_equipements',
                    'rivets','equipements','equipements_operationnel','resume_superviseur','sites')
   AND NOT EXISTS (SELECT 1 FROM migrations_ponctuelles m WHERE m.nom = 'permissions_revue_2026_11');

INSERT IGNORE INTO migrations_ponctuelles (nom) VALUES ('permissions_revue_2026_11');
