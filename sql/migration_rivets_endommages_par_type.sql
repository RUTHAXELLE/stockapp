-- ============================================================
--  Rivets endommagés par type (gonflables / éclatés)
--  Idempotente. 2026-10-13
-- ============================================================
--  Le formulaire du point journalier saisit les rivets endommagés par type,
--  mais seul le total (rivets_endommages) était enregistré : impossible de
--  dire ensuite s'il s'agissait de gonflables ou d'éclatés. Les points
--  enregistrés avant cette migration restent à 0/0 (« type non précisé »).
--  Tant qu'elle n'est pas passée, l'application continue sans ces colonnes.
-- ============================================================
ALTER TABLE public.op_points_journaliers ADD COLUMN IF NOT EXISTS rivets_gonflables_end integer NOT NULL DEFAULT 0;
ALTER TABLE public.op_points_journaliers ADD COLUMN IF NOT EXISTS rivets_eclates_end    integer NOT NULL DEFAULT 0;
