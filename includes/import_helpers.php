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
