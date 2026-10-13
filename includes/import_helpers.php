<?php
// ============================================================
//  includes/import_helpers.php
//  Fonctions partagées par les imports Excel/CSV en masse
//  (équipements, PMMA, rivets, articles) — pour charger des
//  centaines/milliers de lignes sans saisie manuelle.
//
//  Ne touche pas à pages/import_emuci.php (import OptoTrace/OptoPlate
//  déjà en place pour les bobines) : mêmes idées (IOFactory pour lire
//  xlsx/xls/csv, résolution de site par nom, boucle qui compte succès/
//  erreurs sans tout annuler) mais redéfinies ici pour ne pas risquer
//  de régression sur un import déjà en production.
// ============================================================

/**
 * Lit un fichier .xlsx/.xls/.csv uploadé et renvoie ses lignes brutes
 * (tableau de tableaux, première ligne = en-têtes). IOFactory détecte le
 * format automatiquement à partir du contenu/extension — un seul loader
 * pour les trois formats.
 */
function import_lire_fichier(string $tmp_path): array {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_path);
    $sheet = $spreadsheet->getActiveSheet();
    return $sheet->toArray(null, true, true, false);
}

/**
 * Résout un nom de site saisi dans un fichier d'import vers son id,
 * tolérant aux variantes (casse, espaces, nom partiel) — même esprit que
 * trouver_site_id() dans pages/import_emuci.php, redéfini ici pour rester
 * indépendant de ce fichier.
 */
function import_resoudre_site(string $nom, array $sites): ?int {
    $nom = trim(strtolower($nom));
    if ($nom === '') return null;
    foreach ($sites as $s) {
        if (strtolower(trim($s['nom'])) === $nom) return (int)$s['id'];
    }
    foreach ($sites as $s) {
        if (str_contains(strtolower($s['nom']), $nom) || str_contains($nom, strtolower($s['nom'])))
            return (int)$s['id'];
    }
    return null;
}

/**
 * Construit et envoie en téléchargement un fichier .xlsx modèle avec les
 * en-têtes attendus (en gras) et une ligne d'exemple. Termine la requête.
 */
function import_generer_modele(array $headers, array $exemple, string $filename): void {
    $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $sp->getActiveSheet();
    foreach ($headers as $i => $h) {
        $cell = $ws->getCell([$i + 1, 1]);
        $cell->setValue($h);
        $cell->getStyle()->getFont()->setBold(true);
        $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
             ->getStartColor()->setARGB('FF06033A');
        $cell->getStyle()->getFont()->getColor()->setARGB('FFFFFFFF');
    }
    foreach ($exemple as $i => $v) {
        $ws->setCellValue([$i + 1, 2], $v);
    }
    foreach (range(1, count($headers)) as $col) {
        $ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment;filename=\"$filename\"");
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
    exit;
}

/**
 * Post/Redirect/Get pour les imports en masse.
 *
 * Appelée juste après le bloc qui traite l'envoi du fichier : si la page
 * vient de recevoir ce formulaire, le résultat est gardé en session et le
 * navigateur est redirigé vers la même adresse en GET ; sinon, le résultat
 * gardé (s'il y en a un) est rendu une seule fois.
 *
 * Sans cela, la page affichée après un import restait le résultat d'un
 * POST : le location.reload() qui suit chaque entrée/sortie AJAX — ou un
 * simple F5 — renvoyait le fichier, et l'import (qui fixe le stock à la
 * valeur du fichier) annulait l'opération qui venait d'être faite. Constaté
 * en production le 13/10/2026 sur le PMMA : chaque entrée était suivie
 * d'une « sortie » « Import stock » de même quantité.
 */
function import_apres_envoi(?array $resultat, string $cle): ?array {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_ajax() && isset($_FILES['fichier_import'])) {
        $_SESSION['import_result'][$cle] = $resultat;
        header('Location: ' . $_SERVER['REQUEST_URI'], true, 303);
        exit;
    }
    $garde = $_SESSION['import_result'][$cle] ?? null;
    unset($_SESSION['import_result'][$cle]);
    return $garde;
}
