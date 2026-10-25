-- ============================================================
--  Journée sans production (point journalier 18h)
--
--  Un site peut ne rien produire un jour donné (jour férié, panne du
--  système, coupure d'électricité, absence de personnel, rupture de
--  stock, site fermé, autre). Le coordinateur le déclare dans son point
--  18h, avec un motif obligatoire : le point est enregistré à zéro (sans
--  mouvement de stock), puis soumis au superviseur qui le confirme
--  (pages/operations/point_journalier.php). Un tel point est dispensé de
--  la règle « films + rivets + PMMA » des points de production.
--
--  Idempotente : ADD COLUMN IF NOT EXISTS.
-- ============================================================
ALTER TABLE op_points_journaliers ADD COLUMN IF NOT EXISTS sans_production smallint NOT NULL DEFAULT 0;
ALTER TABLE op_points_journaliers ADD COLUMN IF NOT EXISTS motif_sans_production varchar(30) DEFAULT NULL;
ALTER TABLE op_points_journaliers ADD COLUMN IF NOT EXISTS commentaire_sans_production text DEFAULT NULL;
