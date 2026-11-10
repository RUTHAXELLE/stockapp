<?php
// ============================================================
//  pages/achats/feb_fiche_validation_pdf.php — Téléchargement de la
//  fiche de validation archivée (Bloc 1, J9). Ne régénère jamais : la
//  pièce est écrite une seule fois par ach_generer_fiche_validation(),
//  au moment où ach_viser() confirme la FEB.
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/achats.php';
require_once __DIR__ . '/../../includes/upload.php';

require_auth();
$user = current_user();
require_permission('achats', 'can_read');

$feb_id = (int)($_GET['id'] ?? 0);
$feb = db_fetch_one("SELECT numero, fiche_validation_path, demandeur_id, n1_user_id FROM feb WHERE id=?", [$feb_id]);
if (!$feb) { http_response_code(404); echo 'FEB introuvable.'; exit; }
if (!ach_peut_lire_feb($user, $feb)) { http_response_code(403); include __DIR__ . '/../../templates/403.php'; exit; }
if (empty($feb['fiche_validation_path'])) {
    http_response_code(404);
    echo "Cette FEB n'a pas encore de fiche de validation — elle n'est générée qu'à la confirmation.";
    exit;
}

$contenu = upload_contenu('validation', $feb['fiche_validation_path']);
if ($contenu === null) {
    // Jamais régénérée en silence : la pièce est opposable, un document
    // manquant est une anomalie à signaler, pas à recalculer à la volée.
    http_response_code(404);
    echo "Document introuvable sur le serveur — contactez l'administration.";
    exit;
}

$filename = 'Validation_' . preg_replace('/[^A-Za-z0-9_-]/', '', $feb['numero'] ?: "feb$feb_id") . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($contenu));
echo $contenu;
