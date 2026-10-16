-- ============================================================
--  Équipements : utilisateur (agent de l'annuaire)
--  Idempotente. 2026-10-16
-- ============================================================
--  - equipements.agent_id : l'agent (Annuaire des agents) qui utilise
--    l'équipement. Distinct de utilisateur_id (compte applicatif, utilisé
--    par les affectations) : la plupart des porteurs d'équipement n'ont pas
--    de compte StockApp.
--  Tant que cette migration n'est pas passée, le champ Utilisateur
--  n'apparaît pas dans le formulaire.
-- ============================================================
ALTER TABLE public.equipements
    ADD COLUMN IF NOT EXISTS agent_id integer REFERENCES public.agents(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_equipements_agent ON public.equipements (agent_id);
