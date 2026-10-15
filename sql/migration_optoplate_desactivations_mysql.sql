-- ============================================================
--  Migration MySQL : désactivations OptoPlate (comparaison du point 18h)
-- ============================================================
--  Traduction de sql/migration_optoplate_desactivations.sql (main,
--  15/10/2026). L'export OptoPlate porte, sur la ligne de chaque plaque,
--  son éventuelle désactivation (Duplicata, Modification POST, Incident) :
--  l'import met désormais une plaque à jour dans le temps (une ligne par
--  n° de consommable) au lieu d'effacer et réinsérer la journée.
--
--  MySQL 8.0 n'a ni ADD COLUMN IF NOT EXISTS, ni CREATE INDEX IF NOT
--  EXISTS, ni index partiel : information_schema + PREPARE, et un index
--  unique classique — les NULL ne s'y heurtent pas, comme l'index partiel
--  « WHERE num_consommable IS NOT NULL » de PostgreSQL.
--
--  Avant de créer l'index, les doublons éventuels de num_consommable (une
--  même plaque importée sous deux dates) sont réduits à la ligne la plus
--  récente : ce sont des copies d'export, rien d'autre n'y renvoie.
--  Rejouable : une étape déjà faite affiche « … déjà présent(e) ».
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate' AND column_name = 'categorie') = 0,
  'ALTER TABLE import_optoplate ADD COLUMN categorie varchar(30) DEFAULT NULL COMMENT ''Catégorie du véhicule fournie par OptoPlate (VOITURE PARTICULIERE, MOTOCYCLETTE…)''',
  'SELECT ''import_optoplate.categorie déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate' AND column_name = 'ancienne_plaque') = 0,
  'ALTER TABLE import_optoplate ADD COLUMN ancienne_plaque varchar(30) DEFAULT NULL COMMENT ''Ancienne plaque du véhicule (re-immatriculation)''',
  'SELECT ''import_optoplate.ancienne_plaque déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate' AND column_name = 'type_desactivation') = 0,
  'ALTER TABLE import_optoplate ADD COLUMN type_desactivation varchar(30) DEFAULT NULL COMMENT ''Duplicata / Modification POST / Incident — tel que fourni par OptoPlate''',
  'SELECT ''import_optoplate.type_desactivation déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate' AND column_name = 'motif_desactivation') = 0,
  'ALTER TABLE import_optoplate ADD COLUMN motif_desactivation varchar(100) DEFAULT NULL',
  'SELECT ''import_optoplate.motif_desactivation déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate' AND column_name = 'date_desactivation') = 0,
  'ALTER TABLE import_optoplate ADD COLUMN date_desactivation date DEFAULT NULL',
  'SELECT ''import_optoplate.date_desactivation déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- Doublons de num_consommable : seulement tant que l'index n'existe pas.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate'
                   AND index_name = 'idx_optoplate_num_consommable') = 0,
  'DELETE o1 FROM import_optoplate o1 JOIN import_optoplate o2 ON o1.num_consommable = o2.num_consommable AND o1.id < o2.id',
  'SELECT ''import_optoplate : index num_consommable déjà présent, pas de dédoublonnage'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'import_optoplate'
                   AND index_name = 'idx_optoplate_num_consommable') = 0,
  'CREATE UNIQUE INDEX idx_optoplate_num_consommable ON import_optoplate (num_consommable)',
  'SELECT ''import_optoplate.idx_optoplate_num_consommable déjà présent'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
