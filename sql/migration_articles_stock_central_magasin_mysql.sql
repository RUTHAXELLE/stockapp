-- ============================================================================
--  Articles importés au magasin : report du stock magasin dans le stock
--  central (articles.stock_global).
--
--  L'import des articles (pages/articles.php) posait la quantité sur le site
--  « Magasin » sans toucher au stock central : l'article affichait 0 dans la
--  liste et la Distribution le refusait (« Stock insuffisant »). L'import
--  corrigé fait désormais ce report ; cette migration rattrape les articles
--  déjà importés.
--
--  Rejouable : ne touche que les articles dont le stock central est encore à
--  0 alors qu'ils ont du stock sur un site de type 'magasin'.
-- ============================================================================

UPDATE articles a
JOIN (
    SELECT ss.article_id, SUM(ss.quantite) AS qte_magasin
      FROM stock_site ss
      JOIN sites s ON s.id = ss.site_id
     WHERE s.type = 'magasin'
     GROUP BY ss.article_id
) m ON m.article_id = a.id
   SET a.stock_global = m.qte_magasin
 WHERE COALESCE(a.stock_global, 0) = 0
   AND m.qte_magasin > 0;

-- ----------------------------------------------------------------------------
--  receptions_site.consommable_id contient un id d'ARTICLE (pages/articles.php
--  et pages/reception_site.php le joignent à `articles`), mais sa clé
--  étrangère pointait encore sur l'ancienne table `consommables` : toute
--  Distribution depuis la page Articles échouait. On retire cette contrainte
--  (rejouable : ne fait rien si elle n'existe plus).
-- ----------------------------------------------------------------------------
SET @fk := (SELECT constraint_name FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name = 'receptions_site'
               AND referenced_table_name = 'consommables'
             LIMIT 1);
SET @sql := IF(@fk IS NULL, 'SELECT 1',
               CONCAT('ALTER TABLE receptions_site DROP FOREIGN KEY `', @fk, '`'));
PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;
