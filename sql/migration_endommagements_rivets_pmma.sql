-- ============================================================
--  Justification des rivets et PMMA endommagés (point journalier)
--  Idempotente. 2026-10-13
--  Prérequis : sql/migration_endommagements_film_no.sql
-- ============================================================
--  op_endommagements ne portait que des films (une ligne par film, liée à
--  sa bobine). Les rivets endommagés (gonflables / éclatés) et les PMMA
--  endommagés (par type) se justifient désormais de la même façon, une
--  ligne par unité :
--    objet     : 'film' | 'rivet' | 'pmma'   (anciennes lignes = 'film')
--    sous_type : NULL pour un film, 'gonflable' | 'eclate' pour un rivet,
--                le type PMMA (ex : 'Type A') pour un PMMA
--    film_no   : numéro de l'unité dans son groupe
--  bobine_id reste NULL pour un rivet ou un PMMA.
--  Tant que cette migration n'est pas passée, l'application ne demande la
--  justification que des films.
-- ============================================================
BEGIN;

ALTER TABLE public.op_endommagements ADD COLUMN IF NOT EXISTS objet     varchar(10) NOT NULL DEFAULT 'film';
ALTER TABLE public.op_endommagements ADD COLUMN IF NOT EXISTS sous_type varchar(50);

CREATE INDEX IF NOT EXISTS idx_endo_objet ON public.op_endommagements (objet);

COMMIT;
