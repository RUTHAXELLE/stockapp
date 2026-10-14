-- ============================================================
--  Mise à niveau de op_endommagements créée par la 1re version de
--  migration_tracabilite_endommagements.sql (commit 9dc0282).
--  Idempotente. 2026-10-13
-- ============================================================
--  Constat : be96a38 (« une déclaration par film ») a modifié le CREATE
--  TABLE de la migration d'origine au lieu d'ajouter un ALTER. Sur une base
--  où la table existait déjà, CREATE TABLE IF NOT EXISTS n'a rien fait :
--  la colonne film_no manque et l'enregistrement d'un point avec films
--  endommagés échoue (SQLSTATE 42703 column "film_no" does not exist).
--
--  - ajoute film_no et numérote les lignes existantes par (point, bobine) ;
--  - ajoute la contrainte d'unicité (point_id, bobine_id, film_no) ;
--  - élargit cause à varchar(255) (cause en saisie libre, 6345036) ;
--  - garde l'ancienne colonne quantite (lignes v1 = une ligne par bobine),
--    sans effet sur les nouvelles saisies (DEFAULT 1).
-- ============================================================
BEGIN;

ALTER TABLE public.op_endommagements ADD COLUMN IF NOT EXISTS film_no smallint NOT NULL DEFAULT 1;

-- Numérotation des lignes déjà présentes, pour que la contrainte passe.
WITH n AS (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY point_id, bobine_id ORDER BY id) AS rn
    FROM public.op_endommagements
)
UPDATE public.op_endommagements e SET film_no = n.rn
FROM n WHERE n.id = e.id AND e.film_no <> n.rn;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'op_endommagements_film_key') THEN
        ALTER TABLE public.op_endommagements
            ADD CONSTRAINT op_endommagements_film_key UNIQUE (point_id, bobine_id, film_no);
    END IF;
END $$;

ALTER TABLE public.op_endommagements ALTER COLUMN cause TYPE varchar(255);

COMMIT;

-- Contrôle :
-- SELECT column_name, data_type, character_maximum_length
-- FROM information_schema.columns WHERE table_name = 'op_endommagements' ORDER BY ordinal_position;
