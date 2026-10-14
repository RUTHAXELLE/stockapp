-- ============================================================
-- Champ "Opération" sur les sites (Primo / Re-Immatriculation)
-- + compteurs Duplicatas / Post-Immat sur les points journaliers.
-- Rejouable : chaque instruction est protégée (IF NOT EXISTS / vérif catalogue).
-- ============================================================

ALTER TABLE sites ADD COLUMN IF NOT EXISTS operation VARCHAR(20) NOT NULL DEFAULT 'primo';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'sites_operation_check'
    ) THEN
        ALTER TABLE sites ADD CONSTRAINT sites_operation_check
            CHECK (operation IN ('primo','reimmat_post'));
    END IF;
END $$;

COMMENT ON COLUMN sites.operation IS 'primo (par défaut) ou reimmat_post : active les sections Duplicatas / Post-Immat sur le point journalier et les tableaux de bord.';

-- Compteurs Duplicatas / Post-Immat — jamais fusionnés dans total_engins/
-- total_plaques (qui restent Primo uniquement), pour ne pas changer les
-- chiffres historiques des sites Primo. Rivets et films consommés par ces
-- plaques restent saisis dans les champs existants (stock partagé).
ALTER TABLE op_points_journaliers
    ADD COLUMN IF NOT EXISTS nb_vp_duplicata          INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nb_camion_duplicata       INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nb_semi_duplicata         INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nb_moto_duplicata         INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_engins_duplicata    INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_plaques_duplicata   INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nb_vp_postimmat           INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nb_camion_postimmat       INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nb_semi_postimmat         INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nb_moto_postimmat         INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_engins_postimmat    INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_plaques_postimmat   INT NOT NULL DEFAULT 0;
