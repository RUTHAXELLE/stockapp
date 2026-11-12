-- Lignes de commande issues d'une FEB (arbitrage « stock ») : type_article
-- recevait la catégorie du catalogue (papeterie, encre, materiel…) au lieu de
-- 'article', donc le dépôt n'était pas débité à l'expédition.
-- Corrige le type ; NE rattrape PAS les stocks des commandes déjà expédiées
-- (à vérifier à part : requête de contrôle ci-dessous).
-- Rejouable.
UPDATE commande_lignes
   SET type_article = 'article'
 WHERE article_id IS NOT NULL
   AND type_article IN ('papeterie','encre','materiel','pieces','fournitures','consommable','autre');
-- Liste explicite : les lignes bobine, vignette, pmma, rivet et équipement
-- (article_id y désigne une autre table) ne sont jamais touchées.

-- Contrôle : lignes déjà expédiées sans dépôt source (donc jamais débitées).
-- SELECT c.numero_commande, cl.libelle, cl.quantite_livree
--   FROM commande_lignes cl JOIN commandes c ON c.id = cl.commande_id
--  WHERE cl.type_article='article' AND cl.article_id IS NOT NULL
--    AND cl.quantite_livree > 0 AND cl.depot_source_id IS NULL;
