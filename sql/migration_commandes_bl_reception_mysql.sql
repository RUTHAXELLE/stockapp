-- ============================================================
--  Migration MySQL : bon de livraison joint à la réception
-- ============================================================
--  Traduction de sql/migration_commandes_bl_reception.sql. Le
--  coordinateur du site destinataire doit joindre le bon de livraison
--  signé (PDF) pour réceptionner une commande (pages/commandes.php,
--  action 'receptionner'). Le nom du fichier, rangé dans uploads/bl/,
--  est tracé sur la commande. Nullable : les commandes reçues avant
--  cette migration n'en ont pas.
--
--  Rejouable sans effet (MySQL 8 n'a pas ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'commandes'
                   AND column_name = 'bl_reception') = 0,
    'ALTER TABLE `commandes` ADD COLUMN `bl_reception` varchar(255) DEFAULT NULL',
    'SELECT ''bl_reception déjà présente'' AS info');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;
