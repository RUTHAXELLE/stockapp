-- ============================================================
--  Correctif : stock restant modifié à tort par la correction de
--  quantité initiale des bobines (commit e285dcc, remplacé par 57e21ee).
--  Idempotente : ne traite que les mouvements encore non nuls, puis les
--  remet à 0 — la relancer ne change plus rien.
--  2026-10-12
-- ============================================================
--
--  Constat : la première version de « Corriger la quantité initiale »
--  (pages/operations/bobines.php) reportait la différence sur le stock
--  restant (stock_systeme/films_restants). Règle métier retenue depuis :
--  seule qte_initiale change, le stock restant ne bouge pas.
--
--  Ces corrections ont laissé un mouvement 'correction_qte_initiale' dont
--  la quantité (signée) est exactement ce qui a été ajouté au stock. On la
--  retire du stock actuel — les consommations faites depuis restent donc
--  prises en compte — et on remet le mouvement à 0 pour qu'il corresponde
--  à la nouvelle règle.
--
--  À lancer dans la console SQL Neon. Vérifier d'abord avec le SELECT
--  d'aperçu ci-dessous.
-- ============================================================

-- ── Aperçu (lecture seule) : stock actuel et stock après correctif
-- SELECT b.numero, b.qte_initiale, b.stock_systeme AS stock_actuel,
--        LEAST(b.qte_initiale, GREATEST(0, b.stock_systeme - c.delta)) AS stock_corrige, b.statut
-- FROM op_bobines b
-- JOIN (SELECT bobine_id, SUM(quantite) AS delta FROM mouvements_bobines
--       WHERE type = 'correction_qte_initiale' AND quantite <> 0
--       GROUP BY bobine_id) c ON c.bobine_id = b.id
-- ORDER BY b.numero;

BEGIN;

WITH c AS (
    SELECT bobine_id, SUM(quantite) AS delta
    FROM mouvements_bobines
    WHERE type = 'correction_qte_initiale' AND quantite <> 0
    GROUP BY bobine_id
)
UPDATE op_bobines b
-- Borné à [0, qte_initiale] : le restant ne peut pas dépasser la quantité
-- initiale (cas d'une bobine jamais entamée corrigée 500 → 499).
SET stock_systeme  = LEAST(b.qte_initiale, GREATEST(0, b.stock_systeme - c.delta)),
    films_restants = LEAST(b.qte_initiale, GREATEST(0, b.stock_systeme - c.delta)),
    -- Le statut avait pu basculer à cause du stock modifié : on le recalcule
    -- sur le stock rétabli (une bobine retirée reste retirée).
    statut = CASE
        WHEN b.statut = 'retiree' THEN b.statut
        WHEN b.stock_systeme - c.delta <= 0 THEN 'epuisee'
        WHEN b.statut = 'epuisee' THEN CASE WHEN b.site_id IS NOT NULL THEN 'en_cours' ELSE 'en_stock' END
        ELSE b.statut
    END
FROM c
WHERE c.bobine_id = b.id;

UPDATE mouvements_bobines
SET quantite    = 0,
    stock_apres = stock_avant,
    motif       = LEFT(COALESCE(motif, '') || ' [stock rétabli]', 255)
WHERE type = 'correction_qte_initiale' AND quantite <> 0;

COMMIT;
