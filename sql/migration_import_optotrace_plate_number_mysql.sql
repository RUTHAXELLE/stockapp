-- ============================================================
--  Migration MySQL : import_optotrace.plate_number facultative
-- ============================================================
--  Colonne historique NOT NULL sans défaut, absente de l'INSERT de
--  pages/import_emuci.php : en MySQL 8 strict, l'import OptoTrace
--  échouait (« Field 'plate_number' doesn't have a default value »,
--  constaté en production le 12/10/2026). Même correction que sur main
--  pour PostgreSQL (commit a2eebd0, DROP NOT NULL).
--
--  DÉJÀ APPLIQUÉE en production le 12/10/2026, à la main, avec cette
--  instruction exacte (commentaire compris) : la rejouer ne change rien.
--  Elle reste ici pour qu'une installation neuve ait le même schéma.
-- ============================================================

ALTER TABLE `import_optotrace`
  MODIFY `plate_number` VARCHAR(30) DEFAULT NULL COMMENT 'Numero de plaque historique, non alimente par import OptoTrace';
