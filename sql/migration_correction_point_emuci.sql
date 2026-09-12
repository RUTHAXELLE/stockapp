-- ============================================================
--  Circuit demande/reponse pour la correction du declaratif coordinateur
--  sur Point EMUCI (comparaison OptoPlate vs points journaliers)
-- ============================================================
--
--  Avant cette migration, le bouton "Corriger" de pages/point_emuci.php
--  ecrasait directement la valeur declaree par le coordinateur, sans
--  qu'il en soit informe ni consulte. Introduit un circuit sur le modele
--  de corrections_bobines (point journalier bobines) mais borne a un
--  seul aller-retour, adapte a une valeur unique (total_plaques) plutot
--  qu'un detail par bobine :
--
--    1. Le GP propose une valeur + motif (statut 'en_attente'), le
--       coordinateur du site est notifie.
--    2. Le coordinateur accepte telle quelle (statut 'accepte', valeur
--       appliquee immediatement) ou conteste avec sa propre valeur et
--       un commentaire (statut 'conteste').
--    3. Si conteste, le GP tranche : valide la contre-proposition du
--       coordinateur (statut 'valide', sa valeur appliquee) ou la
--       refuse et impose sa valeur d'origine (statut 'refuse').
--
--  Un seul aller-retour, pas de boucle : le GP a toujours le dernier mot.
--
--  Idempotent.
-- ============================================================
BEGIN;

CREATE TABLE IF NOT EXISTS corrections_point_emuci (
    id                    SERIAL PRIMARY KEY,
    pj_id                 integer NOT NULL REFERENCES op_points_journaliers(id) ON DELETE CASCADE,
    site_id               integer NOT NULL REFERENCES sites(id),
    date_point            date NOT NULL,
    total_declare         integer NOT NULL,
    total_propose         integer NOT NULL,
    motif_gp              text NOT NULL,
    gp_id                 integer NOT NULL REFERENCES users(id),
    statut                varchar(20) NOT NULL DEFAULT 'en_attente',
    reponse_coord         text DEFAULT NULL,
    total_propose_coord   integer DEFAULT NULL,
    total_final           integer DEFAULT NULL,
    traite_par            integer DEFAULT NULL REFERENCES users(id),
    traite_at             timestamp DEFAULT NULL,
    created_at            timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS corrections_point_emuci_pj_idx ON corrections_point_emuci (pj_id);
CREATE INDEX IF NOT EXISTS corrections_point_emuci_site_statut_idx ON corrections_point_emuci (site_id, statut);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'corrections_point_emuci_statut_chk'
  ) THEN
    ALTER TABLE corrections_point_emuci
      ADD CONSTRAINT corrections_point_emuci_statut_chk
      CHECK (statut IN ('en_attente','accepte','conteste','valide','refuse'));
  END IF;
END $$;

COMMIT;
