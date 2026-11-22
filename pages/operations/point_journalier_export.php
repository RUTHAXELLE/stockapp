<?php
// ============================================================
//  pages/operations/point_journalier_export.php
//  Export Excel des points journaliers : un tableau par site
//  (VP, Camions, Semi-rem., Motos, Engins, Plaques, Duplicatas,
//  Observations) avec la ligne TOTAL, sur une date ou une période.
//  Ne compte que les points 18h (et les points reconstitués depuis
//  OptoPlate), comme le tableau de bord.
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../vendor/autoload.php';

require_auth();
require_export('operations');

$user     = current_user();
$is_coord = (($user['role_slug'] ?? '') === 'coordinateur_site');

// ── Paramètres
$re_date = '/^\d{4}-\d{2}-\d{2}$/';
$du = (string)($_GET['du'] ?? date('Y-m-d'));
$au = (string)($_GET['au'] ?? $du);
if (!preg_match($re_date, $du) || !strtotime($du)) $du = date('Y-m-d');
if (!preg_match($re_date, $au) || !strtotime($au)) $au = $du;
if ($au < $du) [$du, $au] = [$au, $du];
// Garde-fou : un an au plus.
if ((strtotime($au) - strtotime($du)) / 86400 > 366) {
    http_response_code(400);
    exit('Période trop longue : un an au plus.');
}

$site_id = $is_coord ? (int)($user['site_id'] ?? 0) : (int)($_GET['site'] ?? 0);
if ($is_coord && !$site_id) { http_response_code(403); exit('Aucun site ne vous est affecté.'); }

$avec_soumis     = !empty($_GET['soumis']);
$avec_sans_point = !empty($_GET['sans_point']);

$dup_ok  = db_colonne_existe('op_points_journaliers', 'total_engins_duplicata');
$post_ok = db_colonne_existe('op_points_journaliers', 'total_engins_postimmat');
$sp_ok   = db_colonne_existe('op_points_journaliers', 'sans_production');

$statuts = $avec_soumis ? ['valide', 'soumis'] : ['valide'];
$in_st   = implode(',', array_fill(0, count($statuts), '?'));

$points = db_fetch_all(
    "SELECT p.id, p.site_id, p.date_point, p.statut,
            p.nb_vp, p.nb_camion, p.nb_semi, p.nb_moto, p.total_engins, p.total_plaques,
            " . ($dup_ok  ? "p.total_engins_duplicata"  : "0") . " AS duplicatas,
            " . ($post_ok ? "p.total_engins_postimmat"  : "0") . " AS postimmat,
            " . ($sp_ok   ? "p.sans_production, p.motif_sans_production" : "0 AS sans_production, NULL AS motif_sans_production") . ",
            (SELECT COALESCE(SUM(fu.films_endommages),0) FROM op_films_utilises fu WHERE fu.point_id = p.id) AS films_end,
            (SELECT COALESCE(SUM(pu.endommages),0) FROM op_pmma_utilises pu WHERE pu.point_id = p.id) AS pmma_end,
            COALESCE(p.rivets_endommages,0) AS rivets_end,
            p.observations, s.nom AS site_nom
     FROM op_points_journaliers p
     JOIN sites s ON s.id = p.site_id
     WHERE p.date_point BETWEEN ? AND ?
       AND p.type_point IN ('point_18h','historique')
       AND p.statut IN ($in_st)
       AND (? = 0 OR p.site_id = ?)
     ORDER BY p.date_point, s.nom",
    array_merge([$du, $au], $statuts, [$site_id, $site_id])
);

// Sites de production sans point (option)
$sites_sans_point = [];
if ($avec_sans_point) {
    $sites_sans_point = db_fetch_all(
        "SELECT id, nom FROM sites
         WHERE actif = 1 AND type NOT IN ('entrepot','siege')
           AND (? = 0 OR id = ?)
         ORDER BY nom",
        [$site_id, $site_id]
    );
}

// ── Observations d'un point
function pje_observation(array $p): string {
    $parts = [];
    if (!empty($p['sans_production'])) {
        $parts[] = 'Sans production' . (!empty($p['motif_sans_production']) ? ' : ' . $p['motif_sans_production'] : '');
    }
    // Observations saisies : liste JSON [{type, texte}] (comme point_pdf.php),
    // ou texte libre des anciens points.
    $o = trim((string)($p['observations'] ?? ''));
    if ($o !== '') {
        $liste = json_decode($o, true);
        if (is_array($liste)) {
            $types = ['alerte' => 'Alerte', 'relance' => 'Relance', 'incident' => 'Incident',
                      'urgence' => 'Urgence', 'autre' => 'Autre'];
            foreach ($liste as $obs) {
                $t = trim((string)($obs['texte'] ?? ''));
                if ($t === '') continue;
                $lib = $types[$obs['type'] ?? ''] ?? null;
                $parts[] = $lib ? mb_strtoupper($lib) . ' : ' . $t : $t;
            }
        } else {
            $parts[] = $o;
        }
    }
    if ($p['statut'] !== 'valide') $parts[] = 'Point non encore validé';
    return implode(' — ', $parts);
}

// ── Agrégation par site (et par jour)
$cles = ['nb_vp', 'nb_camion', 'nb_semi', 'nb_moto', 'total_engins', 'total_plaques', 'duplicatas', 'postimmat',
         'films_end', 'pmma_end', 'rivets_end'];
$vide = array_fill_keys($cles, 0);

function pje_cumuler(array &$ligne, array $p, array $cles): void {
    foreach ($cles as $c) $ligne[$c] += (int)$p[$c];
    $o = pje_observation($p);
    if ($o !== '') $ligne['obs'][] = $o;
}

$par_site = [];   // site_nom => ligne
$par_jour = [];   // date => site_nom => ligne
foreach ($points as $p) {
    $nom = $p['site_nom'];
    $par_site[$nom] ??= $vide + ['obs' => []];
    $obs_avant = count($par_site[$nom]['obs']);
    pje_cumuler($par_site[$nom], $p, $cles);
    // Sur une période, l'observation de la synthèse porte sa date.
    if ($du !== $au && count($par_site[$nom]['obs']) > $obs_avant) {
        $i = count($par_site[$nom]['obs']) - 1;
        $par_site[$nom]['obs'][$i] = date('d/m', strtotime($p['date_point'])) . ' : ' . $par_site[$nom]['obs'][$i];
    }
    $par_jour[$p['date_point']][$nom] ??= $vide + ['obs' => []];
    pje_cumuler($par_jour[$p['date_point']][$nom], $p, $cles);
}
foreach ($sites_sans_point as $s) {
    if (!isset($par_site[$s['nom']])) {
        $par_site[$s['nom']] = $vide + ['obs' => [$du === $au ? 'Aucun point 18h' : 'Aucun point 18h sur la période']];
    }
}
ksort($par_site, SORT_NATURAL | SORT_FLAG_CASE);

// ── Classeur
$navy   = '1F3864';
$vert   = 'C6EFCE';
$blanc  = 'FFFFFF';

$colonnes = ['Site' => null, 'VP' => 'nb_vp', 'Camions' => 'nb_camion', 'Semi-rem.' => 'nb_semi',
             'Motos' => 'nb_moto',
             'Duplicatas' => 'duplicatas', 'Post-Immat' => 'postimmat', 'Engins' => 'total_engins',
             'Plaques' => 'total_plaques',
             'Films endom.' => 'films_end', 'PMMA endom.' => 'pmma_end', 'Rivets endom.' => 'rivets_end',
             'Observations' => 'obs'];

$nb_col   = count($colonnes);
$col_fin  = chr(ord('A') + $nb_col - 1);
$col_obs  = $col_fin;

/** Écrit un tableau (titre, en-tête, lignes, total) à partir de $ligne_debut. Retourne la ligne suivante. */
function pje_tableau(Worksheet $ws, int $r, string $titre, array $lignes, array $colonnes, array $cles,
                     string $col_fin, string $navy, string $vert, string $blanc, bool $marquer_vides): int {
    $ws->setCellValue("A$r", $titre);
    $ws->mergeCells("A$r:$col_fin$r");
    $ws->getStyle("A$r")->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($navy);
    $r++;

    // En-tête
    $c = 'A';
    foreach (array_keys($colonnes) as $lib) { $ws->setCellValue("$c$r", $lib); $c++; }
    $ws->getStyle("A$r:$col_fin$r")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $blanc]],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $ws->getRowDimension($r)->setRowHeight(36);
    $r_entete = $r;
    $r++;

    $r_debut = $r;
    $total = array_fill_keys($cles, 0);
    foreach ($lignes as $nom => $l) {
        $c = 'A';
        foreach ($colonnes as $cle) {
            if ($cle === null)       $ws->setCellValue("$c$r", $nom);
            elseif ($cle === 'obs')  $ws->setCellValue("$c$r", implode("\n", $l['obs']));
            else { $ws->setCellValue("$c$r", (int)$l[$cle]); $total[$cle] += (int)$l[$cle]; }
            $c++;
        }
        $ws->getStyle("A$r:$col_fin$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($vert);
        $r++;
    }
    if ($r === $r_debut) {
        $ws->setCellValue("A$r", 'Aucun point sur la période.');
        $ws->mergeCells("A$r:$col_fin$r");
        $ws->getStyle("A$r")->getFont()->setItalic(true);
        $r++;
    }
    $r_fin = $r - 1;

    // TOTAL
    $c = 'A';
    foreach ($colonnes as $cle) {
        if ($cle === null)      $ws->setCellValue("$c$r", 'TOTAL');
        elseif ($cle !== 'obs') $ws->setCellValue("$c$r", $total[$cle]);
        $c++;
    }
    $ws->getStyle("A$r:$col_fin$r")->getFont()->setBold(true)->setSize(12);
    $ws->getStyle("A$r:$col_fin$r")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

    // Grille, alignements
    $ws->getStyle("A$r_entete:$col_fin$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('000000');
    $ws->getStyle("A$r_debut:$col_fin$r")->getFont()->setSize(12);
    $ws->getStyle("A$r_debut:$col_fin$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getStyle("$col_fin$r_debut:$col_fin$r")->getAlignment()->setWrapText(true);
    if ($r_fin >= $r_debut) {
        $ws->getStyle("$col_fin$r_debut:$col_fin$r_fin")->getFont()->setBold(true)->getColor()->setRGB('FF0000');
    }
    if ($marquer_vides) {
        // Site sans point : ligne grisée plutôt que verte.
        for ($i = $r_debut; $i <= $r_fin; $i++) {
            if (str_starts_with((string)$ws->getCell("{$col_fin}$i")->getValue(), 'Aucun point')) {
                $ws->getStyle("A$i:$col_fin$i")->getFill()->getStartColor()->setRGB('EDEDED');
                $ws->getStyle("$col_fin$i")->getFont()->setBold(false)->setItalic(true)->getColor()->setRGB('7F7F7F');
            }
        }
    }
    return $r + 2;
}

function pje_mise_en_page(Worksheet $ws, string $col_fin): void {
    $ws->getColumnDimension('A')->setWidth(34);
    $ws->freezePane('B1');
    for ($c = 'B'; $c !== $col_fin; $c++) $ws->getColumnDimension($c)->setWidth(13);
    $ws->getColumnDimension($col_fin)->setWidth(40);
    $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
                      ->setFitToWidth(1)->setFitToHeight(0);
}

$fmt = fn(string $d) => date('d/m/Y', strtotime($d));
$periode_lib = $du === $au ? 'du ' . $fmt($du) : 'du ' . $fmt($du) . ' au ' . $fmt($au);
$site_lib = $site_id ? (string)db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]) : 'Tous les sites';
$statut_lib = $avec_soumis ? 'points 18h validés et soumis' : 'points 18h validés';

$book = new Spreadsheet();
$book->getProperties()->setCreator('ERP EMUCI')->setTitle('Points journaliers ' . $periode_lib);

$ws = $book->getActiveSheet();
$ws->setTitle($du === $au ? date('d-m-Y', strtotime($du)) : 'Synthèse');
$ws->setShowGridlines(false);
$titre = 'Point journalier ' . $periode_lib . ' — ' . $site_lib;
$r = pje_tableau($ws, 1, $titre, $par_site, $colonnes, $cles, $col_fin, $navy, $vert, $blanc, $avec_sans_point);
$ws->setCellValue("A$r", 'Source : ' . $statut_lib . '. Édité le ' . date('d/m/Y à H:i') . ' par '
    . trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) . '.');
$ws->getStyle("A$r")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('7F7F7F');
pje_mise_en_page($ws, $col_fin);

// Sur une période : un tableau par jour, à la suite, sur une seconde feuille.
if ($du !== $au) {
    $wj = $book->createSheet();
    $wj->setTitle('Par jour');
    $wj->setShowGridlines(false);
    $r = 1;
    if (!$par_jour) {
        $wj->setCellValue('A1', 'Aucun point sur la période.');
    }
    foreach ($par_jour as $date => $lignes) {
        ksort($lignes, SORT_NATURAL | SORT_FLAG_CASE);
        $jour = ucfirst(strtr(date('l', strtotime($date)), [
            'Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi', 'Thursday' => 'jeudi',
            'Friday' => 'vendredi', 'Saturday' => 'samedi', 'Sunday' => 'dimanche']));
        $r = pje_tableau($wj, $r, $jour . ' ' . $fmt($date), $lignes, $colonnes, $cles, $col_fin, $navy, $vert, $blanc, false);
    }
    pje_mise_en_page($wj, $col_fin);
}
$book->setActiveSheetIndex(0);

audit_log($user['id'], 'EXPORT', 'op_points_journaliers', null, "Export points journaliers $periode_lib ($site_lib)");

$nom_fichier = 'points_journaliers_' . ($du === $au ? $du : "{$du}_au_{$au}") . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nom_fichier . '"');
header('Cache-Control: max-age=0');
(new XlsxWriter($book))->save('php://output');
exit;
