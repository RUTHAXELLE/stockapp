-- ============================================================
--  Comparaison OptoPlate avant validation superviseur — justification
--  et écart constaté, conservés avec le point journalier.
--  Idempotente. 2026-10-15
-- ============================================================
--  Avant de valider un point 18h, le superviseur compare ce que le
--  coordinateur a déclaré (Primo par type de véhicule, Duplicatas,
--  Post-Immat, endommagés par étape, plaques par bobine) à ce qu'OptoPlate
--  a réellement enregistré ce jour-là. Un écart demande soit un rejet
--  (motif pré-rempli côté écran), soit une validation avec justification
--  obligatoire — les deux restent visibles dans le détail du point et le
--  PDF.
-- ============================================================

ALTER TABLE op_points_journaliers
    ADD COLUMN IF NOT EXISTS justification_superviseur text DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ecart_optoplate            text DEFAULT NULL;

COMMENT ON COLUMN op_points_journaliers.justification_superviseur IS 'Texte obligatoire si le superviseur valide malgré un écart OptoPlate constaté.';
COMMENT ON COLUMN op_points_journaliers.ecart_optoplate IS 'Snapshot JSON de la comparaison coordinateur/OptoPlate au moment de la validation (ou du rejet).';
