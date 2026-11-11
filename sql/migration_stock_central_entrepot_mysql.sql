-- ============================================================================
--  Stock central des articles = magasin + entrepôt.
--
--  articles.stock_global ne comptait que les sites de type « magasin » à
--  l'import et à l'ajustement, alors que les commandes internes expédient
--  aussi depuis l'entrepôt : un article avec 393 à l'entrepôt et 20 au
--  magasin affichait 20, et la Distribution refusait au-delà. Le code compte
--  désormais les deux types de dépôts ; cette migration rattrape les articles
--  qui ont du stock dans un entrepôt.
--
--  Rejouable : stock central = somme des sites magasin + entrepôt actifs, pour
--  les seuls articles ayant du stock dans un entrepôt.
-- ============================================================================

UPDATE articles a
JOIN (
    SELECT ss.article_id,
           SUM(ss.quantite) AS qte_depots,
           SUM(CASE WHEN s.type = 'entrepot' THEN ss.quantite ELSE 0 END) AS qte_entrepot
      FROM stock_site ss
      JOIN sites s ON s.id = ss.site_id
     WHERE s.type IN ('magasin', 'entrepot') AND s.actif = 1
     GROUP BY ss.article_id
) d ON d.article_id = a.id
   SET a.stock_global = d.qte_depots
 WHERE d.qte_entrepot > 0
   AND COALESCE(a.stock_global, 0) <> d.qte_depots;
