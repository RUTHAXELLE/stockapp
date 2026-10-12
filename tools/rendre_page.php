<?php
// ============================================================
//  tools/rendre_page.php — affiche un écran en ligne de commande
//
//  Utilisé par tools/verifier.php : un écran par processus, car les pages
//  appellent exit et déclarent des fonctions. Connecté comme l'utilisateur
//  dont l'id est passé en 2e argument, requête GET sans paramètre.
//
//      php tools/rendre_page.php pages/kpi_dashboard.php 1
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

$racine = dirname(__DIR__);
$page   = $argv[1] ?? '';
$uid    = (int)($argv[2] ?? 1);
if ($page === '' || !is_file("$racine/$page")) { fwrite(STDERR, "Page introuvable : $page\n"); exit(2); }

$_SERVER += [
    'REQUEST_METHOD'  => 'GET',
    'HTTP_HOST'       => 'localhost',
    'REQUEST_URI'     => "/$page",
    'SCRIPT_NAME'     => "/$page",
    'PHP_SELF'        => "/$page",
    'SCRIPT_FILENAME' => "$racine/$page",
    'REMOTE_ADDR'     => '127.0.0.1',
    'HTTP_USER_AGENT' => 'verifier',
];
$_GET = [];
chdir(dirname("$racine/$page"));

require "$racine/includes/db.php";
require "$racine/includes/session.php";
$_SESSION['user_id']       = $uid;
$_SESSION['last_activity'] = time();

include "$racine/$page";
