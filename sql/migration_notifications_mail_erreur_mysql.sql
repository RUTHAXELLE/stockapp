-- ============================================================
--  Notifications par mail : dernier message d'erreur et date d'envoi (MySQL).
--  notifications.mail_erreur : motif du dernier refus SMTP, ou raison pour
--  laquelle le mail n'est pas parti (statut 'ignore').
--  notifications.mail_envoye_le : date d'envoi effective.
--  Affichés par la page Administration > Mails. Rejouable.
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'mail_erreur');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `notifications` ADD COLUMN `mail_erreur` varchar(255) DEFAULT NULL, ADD COLUMN `mail_envoye_le` datetime DEFAULT NULL',
    'SELECT ''mail_erreur déjà présente'' AS info');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;
