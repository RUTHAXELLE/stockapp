-- ============================================================
--  Migration MySQL : champ « Emplacement » sur les équipements
-- ============================================================
--  Traduction de sql/migration_equipements_emplacement.sql (branche
--  main). Le champ « Site » ne donne que le site ; l'emplacement précise
--  la localisation dans le site (« Bureau DG, 2e étage », « Salle
--  serveur »). Utilisé par pages/equipements.php, pour les deux
--  catégories (informatique et opérationnel).
--
--  MySQL 8 n'accepte pas ADD COLUMN IF NOT EXISTS (MariaDB oui) :
--  l'idempotence passe par information_schema et une requête préparée.
--  Rejouer ce fichier ne change rien si la colonne existe déjà.
--
--  ⚠️ Non vérifiée contre une vraie base MySQL de production — même
--  réserve que les autres migrations « _mysql.sql » de cette branche.
-- ============================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'equipements'
                   AND column_name  = 'emplacement');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `equipements` ADD COLUMN `emplacement` varchar(150) DEFAULT NULL',
    'SELECT ''emplacement déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;
