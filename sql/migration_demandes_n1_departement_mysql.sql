-- ============================================================
--  Demandes internes : visa N+1 ouvert à tous les N+1 du département (MySQL)
-- ============================================================
--  di_demandes.n1_departement_id : département retenu pour le visa N+1 à la
--  soumission (bénéficiaire, à défaut demandeur). Tous les N+1 actifs de ce
--  département peuvent viser ; auparavant seul le premier créé recevait la
--  demande (n1_user_id, conservé pour l'affichage).
--
--  Demandes en attente : département déduit du N+1 désigné (de préférence
--  un département dont le demandeur est membre). Rejouable.
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'di_demandes' AND column_name = 'n1_departement_id');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `di_demandes` ADD COLUMN `n1_departement_id` int DEFAULT NULL AFTER `n1_user_id`',
    'SELECT ''n1_departement_id déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;

UPDATE di_demandes d
   SET d.n1_departement_id = (
       SELECT ud.departement_id FROM user_departements ud
         LEFT JOIN user_departements m ON m.departement_id = ud.departement_id AND m.user_id = d.demandeur_id
        WHERE ud.user_id = d.n1_user_id AND ud.is_n1 = 1
        ORDER BY (m.user_id IS NULL), ud.departement_id LIMIT 1)
 WHERE d.n1_departement_id IS NULL AND d.n1_user_id IS NOT NULL
   AND d.statut IN ('en_attente','en_cours');
