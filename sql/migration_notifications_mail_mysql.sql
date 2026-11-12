-- ============================================================
--  Notifications par mail : file d'attente d'envoi (MySQL).
--  notifications.mail_statut : 'attente' (à envoyer, valeur par défaut des
--  nouvelles lignes, INSERT directs compris), 'envoye', 'ignore' (déjà lue,
--  sans destinataire valide, trop ancienne), 'echec' (3 essais),
--  'historique' (notifications d'avant cette migration : jamais envoyées).
--  mail_tentatives : nombre d'essais d'envoi. Rejouable.
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'mail_statut');
-- Colonne ajoutée avec 'historique' (les lignes existantes ne partent pas par
-- mail), puis la valeur par défaut passe à 'attente' pour les nouvelles.
SET @sql := IF(@existe = 0,
    'ALTER TABLE `notifications` ADD COLUMN `mail_statut` varchar(12) NOT NULL DEFAULT ''historique'', ADD COLUMN `mail_tentatives` tinyint unsigned NOT NULL DEFAULT 0',
    'SELECT ''mail_statut déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;

SET @sql := IF(@existe = 0,
    'ALTER TABLE `notifications` ALTER COLUMN `mail_statut` SET DEFAULT ''attente'', ADD INDEX `idx_notif_mail` (`mail_statut`, `id`)',
    'SELECT ''défaut déjà posé'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;
