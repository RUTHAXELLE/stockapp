<?php
// Test de la configuration SMTP : php tools/tester_mail.php adresse@exemple.ci
define('CLI_MODE', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';
$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Usage : php tools/tester_mail.php adresse@exemple.ci\n"); exit(2); }
echo 'MAIL_ENABLED : ' . (mail_actif() ? 'oui' : 'NON (envoi désactivé : ce test envoie quand même)') . "\n";
$ok = mailer_send($to, '[' . APP_NAME . "] Test d'envoi",
    mail_notification_html('', "Test d'envoi de mail", 'Si vous lisez ce message, la configuration SMTP fonctionne.', '/'));
echo $ok ? "Mail envoyé à $to.\n" : 'ÉCHEC : ' . mailer_derniere_erreur() . "\n";
exit($ok ? 0 : 1);
