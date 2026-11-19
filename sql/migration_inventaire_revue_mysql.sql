-- ============================================================
--  Revue Inventaire (19/11/2026) — MySQL
-- ============================================================
--  1. inventaire_details_rivets / _pmma : stock_reference (stock théorique de
--     fin de journée noté à la saisie) et saisi_at. À la validation, l'écart
--     compté s'applique au stock actuel : une entrée faite entre la saisie et
--     la validation n'est plus effacée.
--  2. inventaire_details_bobines.saisi_at : une bobine comptée à 0 dont le
--     stock était 0 restait « non saisie ».
--  3. Droit « Valider » par type d'inventaire (option « Modifier » des
--     modules inventaire_*_valider dans la matrice), accordé aux rôles qui
--     validaient jusqu'ici (liste écrite dans le code). Une ligne déjà réglée
--     n'est jamais écrasée. Rejouable.
-- ============================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_rivets' AND column_name = 'stock_reference');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaire_details_rivets` ADD COLUMN `stock_reference` int DEFAULT NULL', 'SELECT ''inventaire_details_rivets.stock_reference déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_rivets' AND column_name = 'saisi_at');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaire_details_rivets` ADD COLUMN `saisi_at` datetime DEFAULT NULL', 'SELECT ''inventaire_details_rivets.saisi_at déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_pmma' AND column_name = 'stock_reference');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaire_details_pmma` ADD COLUMN `stock_reference` int DEFAULT NULL', 'SELECT ''inventaire_details_pmma.stock_reference déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_pmma' AND column_name = 'saisi_at');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaire_details_pmma` ADD COLUMN `saisi_at` datetime DEFAULT NULL', 'SELECT ''inventaire_details_pmma.saisi_at déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaire_details_bobines' AND column_name = 'saisi_at');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaire_details_bobines` ADD COLUMN `saisi_at` datetime DEFAULT NULL', 'SELECT ''inventaire_details_bobines.saisi_at déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT id, 'inventaire_bobines_valider', 0, 0, CASE WHEN slug IN ('superviseur_operation','gestionnaire_stock_bobines','gestionnaire_stock') THEN 1 ELSE 0 END, 0, 0 FROM roles;
INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT id, 'inventaire_rivets_valider', 0, 0, CASE WHEN slug IN ('superviseur_operation','gestionnaire_stock_bobines','gestionnaire_stock') THEN 1 ELSE 0 END, 0, 0 FROM roles;
INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT id, 'inventaire_pmma_valider', 0, 0, CASE WHEN slug IN ('superviseur_operation','gestionnaire_stock_bobines','gestionnaire_stock') THEN 1 ELSE 0 END, 0, 0 FROM roles;
INSERT IGNORE INTO permissions (role_id, module, can_read, can_create, can_update, can_delete, can_export)
SELECT id, 'inventaire_equipements_valider', 0, 0, CASE WHEN slug IN ('superviseur_operation','gestionnaire_stock') THEN 1 ELSE 0 END, 0, 0 FROM roles;

-- 4. Moment du comptage : comptage_matin (NULL = pas encore fixé, 1 = matin avant les activités)
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaires_bobines' AND column_name = 'comptage_matin');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaires_bobines` ADD COLUMN `comptage_matin` smallint DEFAULT NULL', 'SELECT ''inventaires_bobines.comptage_matin déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaires_rivets' AND column_name = 'comptage_matin');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaires_rivets` ADD COLUMN `comptage_matin` smallint DEFAULT NULL', 'SELECT ''inventaires_rivets.comptage_matin déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaires_pmma' AND column_name = 'comptage_matin');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaires_pmma` ADD COLUMN `comptage_matin` smallint DEFAULT NULL', 'SELECT ''inventaires_pmma.comptage_matin déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'inventaires_equipements' AND column_name = 'comptage_matin');
SET @sql := IF(@existe = 0, 'ALTER TABLE `inventaires_equipements` ADD COLUMN `comptage_matin` smallint DEFAULT NULL', 'SELECT ''inventaires_equipements.comptage_matin déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
