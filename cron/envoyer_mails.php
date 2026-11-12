<?php
// ============================================================
//  cron/envoyer_mails.php — envoie par mail les notifications en attente.
//  Chaque minute (cf. docs/deploiement/cron-stockapp). Sans effet tant que
//  MAIL_ENABLED n'est pas à 1 (et MAIL_HOST renseigné) dans le .env.
// ============================================================
define('CLI_MODE', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';

if (!mail_actif()) exit(0);

// Un seul envoi à la fois : si la passe précédente dure encore, on laisse faire.
$verrou = fopen(sys_get_temp_dir() . '/stockapp_envoyer_mails.lock', 'c');
if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) exit(0);

$r = mail_envoyer_notifications(100);
if ($r['envoyes'] || $r['echecs'] || $r['ignores'])
    echo '[' . date('Y-m-d H:i:s') . "] Mails : {$r['envoyes']} envoyé(s), {$r['ignores']} ignoré(s), {$r['echecs']} échec(s)"
       . ($r['echecs'] ? ' — ' . mailer_derniere_erreur() : '') . "
";
