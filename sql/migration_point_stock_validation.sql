-- ============================================================
--  Règle de stock : seul le point 18h VALIDÉ par le superviseur retire
--  du stock (films des bobines, rivets, PMMA).
--  Idempotente. 2026-10-21
-- ============================================================
--  Avant cette règle, tout point (9h, 13h, 18h, brouillon compris) retirait
--  son stock dès l'enregistrement, et une demande de modification le
--  remettait. op_points_journaliers.stock_deduit dit si le stock d'un point
--  est actuellement retiré :
--    0 = rien n'est retiré ; 1 = retiré par la validation 18h ;
--    2 = retiré sous l'ancienne règle : ACQUIS DÉFINITIVEMENT.
--    - à la création de la colonne (une seule fois), on marque 2 tous les
--      points qui ont retiré leur stock sous l'ancienne règle : tous sauf
--      ceux en modification demandée (stock déjà remis) et les points
--      « historique » reconstitués depuis OptoPlate (aucun mouvement).
--      Le stock a depuis été recalé (validations GSB, imports EMUCI,
--      inventaires) : ces retraits ne sont jamais remis ni refaits, même si
--      le point est modifié, supprimé ou validé ;
--    - ensuite, l'application gère seule la colonne pour les nouveaux points :
--      retrait à la validation du point 18h (0 -> 1), remise en stock si un
--      point à 1 est renvoyé en modification (1 -> 0).
--  Tant que cette migration n'est pas passée, l'ancien fonctionnement reste.
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'op_points_journaliers' AND column_name = 'stock_deduit'
    ) THEN
        ALTER TABLE public.op_points_journaliers
            ADD COLUMN stock_deduit smallint NOT NULL DEFAULT 0;

        UPDATE public.op_points_journaliers
        SET stock_deduit = 2
        WHERE statut <> 'rejete'
          AND COALESCE(type_point, '') <> 'historique';
    END IF;
END $$;

-- Contrôle :
-- SELECT type_point, statut, stock_deduit, COUNT(*)
-- FROM op_points_journaliers GROUP BY 1,2,3 ORDER BY 1,2,3;
