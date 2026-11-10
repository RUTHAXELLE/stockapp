<?php
// ============================================================
//  pages/fichier.php — Lecture d'un fichier déposé (pièce jointe de FEB,
//  bon de livraison, fiche signée, fiche de validation, document
//  fournisseur).
//
//  Le fichier est lu dans la base (table fichiers_archives), sinon sur le
//  disque pour un dépôt antérieur à l'archivage — cf. includes/upload.php.
//  Connexion exigée : l'adresse directe dans uploads/ s'ouvrait sans.
//    ?d=<dossier>&f=<nom du fichier>
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/upload.php';

require_auth();

$type = array_search((string)($_GET['d'] ?? ''), UPLOAD_SOUS_DOSSIERS, true);
$nom  = basename((string)($_GET['f'] ?? ''));
if ($type === false || $nom === '' || $nom[0] === '.') { http_response_code(404); echo 'Fichier introuvable.'; exit; }

$contenu = upload_contenu($type, $nom);
if ($contenu === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo "<p style='font-family:Arial;padding:30px'>Ce fichier n'est plus disponible : il a été déposé avant l'archivage en base "
       . "et n'existe plus sur le serveur.</p>";
    exit;
}

$mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext   = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . (isset($mimes[$ext]) ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nom) . '"');
header('Content-Length: ' . strlen($contenu));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
echo $contenu;
