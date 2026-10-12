-- ============================================================
--  Dépôt source d'une livraison PMMA/Rivets
--
--  Avant, la réception d'une commande PMMA/rivets créditait le site du
--  coordinateur sans qu'aucun site ne soit débité en contrepartie (stock
--  fantôme). Deux dépôts existent réellement (sites type='entrepot' et
--  type='magasin') : le gestionnaire doit pouvoir choisir lequel des deux
--  il expédie physiquement, au moment de l'expédition (pages/commandes.php,
--  action 'emettre_livraison'). Colonne tracée sur la commande pour
--  l'audit/historique, nullable car seules les commandes contenant du
--  PMMA/rivets en ont besoin.
--
--  Idempotente : ADD COLUMN IF NOT EXISTS.
-- ============================================================
ALTER TABLE commandes ADD COLUMN IF NOT EXISTS depot_source_id integer DEFAULT NULL
    REFERENCES sites(id);
