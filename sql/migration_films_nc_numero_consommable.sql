-- ============================================================
--  Films non conformes par bobine + n° du consommable endommagé
--  Idempotente. 2026-10-13
--  Prérequis : sql/migration_endommagements_film_no.sql
-- ============================================================
--  - op_films_utilises.non_conformes : films reçus qui ne respectent pas
--    les règles, déclarés dans le point journalier (comme pour les PMMA).
--    Ils sortent des films restants de la bobine.
--  - op_endommagements.numero_consommable : numéro du film (ou du PMMA)
--    endommagé, obligatoire pour un film.
--  Tant que cette migration n'est pas passée, l'application ignore ces
--  deux saisies (le reste du point s'enregistre normalement).
-- ============================================================
BEGIN;

ALTER TABLE public.op_films_utilises ADD COLUMN IF NOT EXISTS non_conformes integer NOT NULL DEFAULT 0;
ALTER TABLE public.op_endommagements ADD COLUMN IF NOT EXISTS numero_consommable varchar(100);

COMMIT;
