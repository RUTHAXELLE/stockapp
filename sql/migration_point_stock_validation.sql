-- ============================================================
--  Règle de stock : seul le point 18h VALIDÉ par le superviseur retire
--  du stock (films des bobines, rivets, PMMA).
--  Idempotente. 2026-10-21
-- ============================================================
--  Avant cette règle, tout point (9h, 13h, 18h, brouillon compris) retirait
--  son stock dès l'enregistrement, et une demande de modification le
--  remettait. op_points_journaliers.stock_deduit dit si le stock d'un point
--  est actuellement retiré :
--    - à la création de la colonne (une seule fois), on marque 1 tous les
--      points qui ont retiré leur stock sous l'ancienne règle : tous sauf
--      ceux en modification demandée (stock déjà remis) et les points
--      « historique » reconstitués depuis OptoPlate (aucun mouvement) ;
--    - ensuite, l'application gère seule la colonne : retrait à la
--      validation du point 18h (0 -> 1), remise en stock si un point marqué
--      1 est modifié, supprimé, renvoyé en modification ou validé alors que
--      ce n'est pas un 18h (1 -> 0).
--  Les points en attente marqués 1 se remettent donc d'eux-mêmes dans la
--  règle dès qu'on y touche ; aucun stock n'est retiré ou remis deux fois.
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
        SET stock_deduit = 1
        WHERE statut <> 'rejete'
          AND COALESCE(type_point, '') <> 'historique';
    END IF;
END $$;

-- Contrôle :
-- SELECT type_point, statut, stock_deduit, COUNT(*)
-- FROM op_points_journaliers GROUP BY 1,2,3 ORDER BY 1,2,3;
