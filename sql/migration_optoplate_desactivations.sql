-- ============================================================
--  Comparaison OptoPlate avant validation superviseur — colonnes
--  de désactivation sur import_optoplate.
--  Idempotente. 2026-10-15
-- ============================================================
--  Jusqu'ici, import_optoplate ne portait que les poses du jour (export
--  "installations"), effacées puis réinsérées à chaque import. Le même
--  export OptoPlate porte en réalité aussi l'éventuelle désactivation de
--  la plaque (catégorie métier Primo/Duplicata/Post-Immat/Endommagé,
--  plaque remplacée, type et motif de désactivation, date de
--  désactivation) : une ligne par plaque, identifiée par num_consommable,
--  mise à jour dans le temps plutôt qu'effacée/réinsérée.
--  Tant que cette migration n'est pas passée, ces colonnes sont ignorées
--  à l'import (comportement inchangé, sans erreur).
-- ============================================================

ALTER TABLE import_optoplate
    ADD COLUMN IF NOT EXISTS categorie           varchar(30)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ancienne_plaque      varchar(30)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS type_desactivation   varchar(30)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS motif_desactivation  varchar(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS date_desactivation   date         DEFAULT NULL;

COMMENT ON COLUMN import_optoplate.categorie IS 'Primo / Duplicata / Post-Immat / Endommage — classement métier de la pose du jour.';
COMMENT ON COLUMN import_optoplate.type_desactivation IS 'Duplicata / Modification POST / Incident — tel que fourni par OptoPlate.';

-- Index par n° de consommable pour l'upsert de l'import désactivations
-- (une ligne par plaque). N'empêche pas plusieurs lignes NULL : seules les
-- valeurs renseignées doivent être uniques. Si l'historique contient déjà
-- des doublons sur num_consommable, cette instruction échoue : nettoyer les
-- doublons avant de la rejouer (elle est sans effet si l'index existe déjà).
CREATE UNIQUE INDEX IF NOT EXISTS idx_optoplate_num_consommable
    ON import_optoplate (num_consommable)
    WHERE num_consommable IS NOT NULL;
