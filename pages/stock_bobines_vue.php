<?php
// ============================================================
//  pages/stock_bobines_vue.php
//  Vue synthétique stock bobines : par site × type
//  Exports : CSV · XLSX · PPTX
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

$_autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($_autoload)) {
    require_once $_autoload;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Dompdf\Dompdf;
use Dompdf\Options;

require_auth();

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$page_title  = 'Vue Stock Bobines';
$active_page = 'stock_bobines_vue';

require_permission('stock_bobines', 'can_read');

// ── FILTRES ──────────────────────────────────────────────────
$f_site   = (int)($_GET['site']   ?? 0);
// Le coordinateur ne consulte que le stock de SON site.
if ($role_slug === 'coordinateur_site' && !empty($user['site_id'])) $f_site = (int)$user['site_id'];
$f_type   = trim($_GET['type']    ?? '');
$f_statut = trim($_GET['statut']  ?? '');   // en_cours | en_stock | (vide=tous)
$export   = trim($_GET['export']  ?? '');   // csv | xlsx | pptx

// ── REQUÊTE BASE ─────────────────────────────────────────────
$where  = ["b.statut IN ('en_cours','en_stock')"];
$params = [];
if ($f_site)   { $where[] = 'b.site_id=?';   $params[] = $f_site; }
if ($f_type)   { $where[] = 'b.type_code=?'; $params[] = $f_type; }
if ($f_statut) { $where[] = 'b.statut=?';    $params[] = $f_statut; }

$resume = db_fetch_all(
    "SELECT s.nom AS site_nom, s.id AS site_id,
            b.type_code,
            COUNT(*)                          AS nb_bobines,
            SUM(b.films_restants)             AS total_films,
            SUM(CASE WHEN b.statut='en_cours' THEN 1 ELSE 0 END) AS nb_en_cours,
            SUM(CASE WHEN b.statut='en_stock' THEN 1 ELSE 0 END) AS nb_en_stock
     FROM op_bobines b
     JOIN sites s ON s.id = b.site_id
     WHERE " . implode(' AND ', $where) . "
     GROUP BY s.id, b.type_code
     ORDER BY s.nom, b.type_code",
    $params
);

// Bobines sans site
$where_ss = ["b.site_id IS NULL", "b.statut IN ('en_cours','en_stock')"];
$params_ss = [];
if ($f_type)   { $where_ss[] = 'b.type_code=?'; $params_ss[] = $f_type; }
if ($f_statut) { $where_ss[] = 'b.statut=?';    $params_ss[] = $f_statut; }
$sans_site = db_fetch_all(
    "SELECT b.type_code, COUNT(*) AS nb, SUM(b.films_restants) AS films
     FROM op_bobines b WHERE " . implode(' AND ', $where_ss) . "
     GROUP BY b.type_code ORDER BY b.type_code",
    $params_ss
);

// Listes pour filtres
$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$types_list = db_fetch_all("SELECT DISTINCT type_code FROM op_bobines WHERE type_code IS NOT NULL ORDER BY type_code");

// ── INDEX & TOTAUX ────────────────────────────────────────────
$types = array_unique(array_column($resume, 'type_code'));
// Ajouter types de sans_site
foreach ($sans_site as $ss) { if (!in_array($ss['type_code'], $types)) $types[] = $ss['type_code']; }
sort($types);

$idx = [];
foreach ($resume as $r) $idx[$r['site_nom']][$r['type_code']] = $r;

$par_site = [];
foreach ($resume as $r) {
    $par_site[$r['site_nom']]['nb_bobines']  = ($par_site[$r['site_nom']]['nb_bobines']  ?? 0) + $r['nb_bobines'];
    $par_site[$r['site_nom']]['total_films'] = ($par_site[$r['site_nom']]['total_films'] ?? 0) + $r['total_films'];
}
$par_type = [];
foreach ($resume as $r) {
    $par_type[$r['type_code']]['nb_bobines']  = ($par_type[$r['type_code']]['nb_bobines']  ?? 0) + $r['nb_bobines'];
    $par_type[$r['type_code']]['total_films'] = ($par_type[$r['type_code']]['total_films'] ?? 0) + $r['total_films'];
}
foreach ($sans_site as $ss) {
    $par_type[$ss['type_code']]['nb_bobines']  = ($par_type[$ss['type_code']]['nb_bobines']  ?? 0) + $ss['nb'];
    $par_type[$ss['type_code']]['total_films'] = ($par_type[$ss['type_code']]['total_films'] ?? 0) + $ss['films'];
}

$total_bobines = array_sum(array_column($resume, 'nb_bobines')) + array_sum(array_column($sans_site, 'nb'));
$total_films   = array_sum(array_column($resume, 'total_films')) + array_sum(array_column($sans_site, 'films'));

// ============================================================
//  EXPORTS
// ============================================================

// ── Export CSV ───────────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stock_bobines_' . date('Ymd') . '.csv"');
    $f = fopen('php://output','w');
    fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    $header = ['Site'];
    foreach ($types as $t) { $header[] = "Bobines $t"; $header[] = "Films $t"; }
    $header[] = 'Total bobines'; $header[] = 'Total films';
    fputcsv($f, $header, ';');

    foreach (array_keys($par_site) as $site_nom) {
        $row = [$site_nom];
        foreach ($types as $t) {
            $cell = $idx[$site_nom][$t] ?? null;
            $row[] = $cell ? $cell['nb_bobines']  : 0;
            $row[] = $cell ? $cell['total_films']  : 0;
        }
        $row[] = $par_site[$site_nom]['nb_bobines'];
        $row[] = $par_site[$site_nom]['total_films'];
        fputcsv($f, $row, ';');
    }
    if (!empty($sans_site)) {
        $row = ['En dépôt (sans site)'];
        $idx_ss = array_column($sans_site, null, 'type_code');
        foreach ($types as $t) {
            $ss = $idx_ss[$t] ?? null;
            $row[] = $ss ? $ss['nb']    : 0;
            $row[] = $ss ? $ss['films'] : 0;
        }
        $row[] = array_sum(array_column($sans_site,'nb'));
        $row[] = array_sum(array_column($sans_site,'films'));
        fputcsv($f, $row, ';');
    }
    $rowt = ['TOTAL'];
    foreach ($types as $t) {
        $rowt[] = $par_type[$t]['nb_bobines'] ?? 0;
        $rowt[] = $par_type[$t]['total_films'] ?? 0;
    }
    $rowt[] = $total_bobines; $rowt[] = $total_films;
    fputcsv($f, $rowt, ';');
    fclose($f); exit;
}

// ── Export XLSX ──────────────────────────────────────────────
if ($export === 'xlsx') {
    if (!file_exists($_autoload)) { echo 'PhpSpreadsheet non installé.'; exit; }

    $sp = new Spreadsheet();
    $ws = $sp->getActiveSheet();
    $ws->setTitle('Stock Bobines');

    // En-tête titre
    $nbCols = 1 + count($types) * 2 + 2;
    $lastCol = Coordinate::stringFromColumnIndex($nbCols);
    $ws->mergeCells("A1:{$lastCol}1");
    $ws->setCellValue([1,1], 'Vue Stock Bobines — ' . date('d/m/Y'));
    $ws->getStyle("A1:{$lastCol}1")->applyFromArray([
        'font'      => ['bold'=>true,'size'=>14,'color'=>['argb'=>'FFFFFFFF']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF06033A']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    ]);
    $ws->getRowDimension(1)->setRowHeight(28);

    // En-têtes colonnes
    $col = 1; $row = 2;
    $ws->setCellValue([$col++, $row], 'Site');
    foreach ($types as $t) {
        $ws->setCellValue([$col++, $row], "Bobines\n$t");
        $ws->setCellValue([$col++, $row], "Films\n$t");
    }
    $ws->setCellValue([$col++, $row], 'Total\nBobines');
    $ws->setCellValue([$col,   $row], 'Total\nFilms');
    $hdrRange = "A2:{$lastCol}2";
    $ws->getStyle($hdrRange)->applyFromArray([
        'font'      => ['bold'=>true,'color'=>['argb'=>'FFFFFFFF']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF1B75BC']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'wrapText'=>true,'vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $ws->getRowDimension(2)->setRowHeight(32);

    // Données
    $row = 3;
    foreach (array_keys($par_site) as $site_nom) {
        $col = 1;
        $ws->setCellValue([$col++, $row], $site_nom);
        foreach ($types as $t) {
            $cell = $idx[$site_nom][$t] ?? null;
            $ws->setCellValue([$col++, $row], $cell ? (int)$cell['nb_bobines']  : 0);
            $ws->setCellValue([$col++, $row], $cell ? (int)$cell['total_films']  : 0);
        }
        $ws->setCellValue([$col++, $row], (int)$par_site[$site_nom]['nb_bobines']);
        $ws->setCellValue([$col,   $row], (int)$par_site[$site_nom]['total_films']);
        if ($row % 2 === 0) {
            $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5F8FF');
        }
        $row++;
    }

    // Ligne sans site
    if (!empty($sans_site)) {
        $col = 1; $idx_ss = array_column($sans_site, null, 'type_code');
        $ws->setCellValue([$col++, $row], 'En dépôt (sans site)');
        foreach ($types as $t) {
            $ss = $idx_ss[$t] ?? null;
            $ws->setCellValue([$col++, $row], $ss ? (int)$ss['nb']    : 0);
            $ws->setCellValue([$col++, $row], $ss ? (int)$ss['films'] : 0);
        }
        $ws->setCellValue([$col++, $row], array_sum(array_column($sans_site,'nb')));
        $ws->setCellValue([$col,   $row], array_sum(array_column($sans_site,'films')));
        $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF8E1');
        $row++;
    }

    // Ligne total
    $col = 1;
    $ws->setCellValue([$col++, $row], 'TOTAL');
    foreach ($types as $t) {
        $ws->setCellValue([$col++, $row], (int)($par_type[$t]['nb_bobines']  ?? 0));
        $ws->setCellValue([$col++, $row], (int)($par_type[$t]['total_films'] ?? 0));
    }
    $ws->setCellValue([$col++, $row], $total_bobines);
    $ws->setCellValue([$col,   $row], $total_films);
    $ws->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
        'font' => ['bold'=>true],
        'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFE8F0FE']],
    ]);

    // Largeurs colonnes
    $ws->getColumnDimension('A')->setWidth(22);
    for ($c = 2; $c <= $nbCols; $c++) {
        $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(13);
    }
    // Bordures
    $ws->getStyle("A2:{$lastCol}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $ws->getStyle("A2:{$lastCol}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle('A2:A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="stock_bobines_' . date('Ymd') . '.xlsx"');
    (new XlsxWriter($sp))->save('php://output');
    exit;
}

// ── Export PPTX (Open XML via ZipArchive::addFromString) ─────
if ($export === 'pptx') {

    // ── Données tableau ──────────────────────────────────────────
    $hdr = ['Site'];
    foreach ($types as $t) { $hdr[] = "Bobines $t"; $hdr[] = "Films $t"; }
    $hdr[] = 'Total Bobines'; $hdr[] = 'Total Films';
    $rows = [['cells' => $hdr, 'hdr' => true, 'tot' => false]];

    foreach (array_keys($par_site) as $sn) {
        $r = [$sn];
        foreach ($types as $t) {
            $c = $idx[$sn][$t] ?? null;
            $r[] = $c ? (string)(int)$c['nb_bobines']           : '0';
            $r[] = $c ? number_format((int)$c['total_films'])    : '0';
        }
        $r[] = (string)(int)$par_site[$sn]['nb_bobines'];
        $r[] = number_format((int)$par_site[$sn]['total_films']);
        $rows[] = ['cells' => $r, 'hdr' => false, 'tot' => false];
    }
    if (!empty($sans_site)) {
        $r = ['En depot (sans site)'];
        $iss = array_column($sans_site, null, 'type_code');
        foreach ($types as $t) {
            $ss = $iss[$t] ?? null;
            $r[] = $ss ? (string)(int)$ss['nb']           : '0';
            $r[] = $ss ? number_format((int)$ss['films'])  : '0';
        }
        $r[] = (string)array_sum(array_column($sans_site,'nb'));
        $r[] = number_format(array_sum(array_column($sans_site,'films')));
        $rows[] = ['cells' => $r, 'hdr' => false, 'tot' => false];
    }
    $r = ['TOTAL'];
    foreach ($types as $t) {
        $r[] = (string)(int)($par_type[$t]['nb_bobines']  ?? 0);
        $r[] = number_format((int)($par_type[$t]['total_films'] ?? 0));
    }
    $r[] = (string)$total_bobines;
    $r[] = number_format($total_films);
    $rows[] = ['cells' => $r, 'hdr' => false, 'tot' => true];

    // ── EMU dimensions ───────────────────────────────────────────
    $SW = 9144000; $SH = 5143500;
    $nC = count($hdr);
    $cW0 = (int)($SW * 0.20);
    $cWn = $nC > 1 ? (int)(($SW * 0.80) / ($nC - 1)) : $SW;
    $rH  = 380000;
    $tY  = 650000;
    $tH  = count($rows) * $rH + 80000;

    // ── Helper cellule ───────────────────────────────────────────
    $tc = static function(string $txt, bool $h, bool $f, bool $tot): string {
        $b   = ($h || $f || $tot) ? '1' : '0';
        $fg  = $h ? 'FFFFFF' : ($tot ? '06033A' : '222222');
        $bg  = $h ? '1B75BC' : ($tot ? 'E8EDF8' : 'FFFFFF');
        $sz  = $h  ? '1100' : '1000';
        $ln  = '<a:solidFill><a:srgbClr val="CCCCCC"/></a:solidFill>';
        return '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r>'
             . '<a:rPr lang="fr-FR" b="' . $b . '" sz="' . $sz . '" dirty="0">'
             . '<a:solidFill><a:srgbClr val="' . $fg . '"/></a:solidFill></a:rPr>'
             . '<a:t>' . htmlspecialchars($txt, ENT_XML1, 'UTF-8') . '</a:t>'
             . '</a:r></a:p></a:txBody>'
             . '<a:tcPr marL="91440" marR="91440" marT="45720" marB="45720">'
             . '<a:lnL w="9525">' . $ln . '</a:lnL>'
             . '<a:lnR w="9525">' . $ln . '</a:lnR>'
             . '<a:lnT w="9525">' . $ln . '</a:lnT>'
             . '<a:lnB w="9525">' . $ln . '</a:lnB>'
             . '<a:solidFill><a:srgbClr val="' . $bg . '"/></a:solidFill>'
             . '</a:tcPr></a:tc>';
    };

    // ── Construire XML tableau ────────────────────────────────────
    $grid = '<a:tblGrid>';
    $grid .= '<a:gridCol w="' . $cW0 . '"/>';
    for ($i = 1; $i < $nC; $i++) $grid .= '<a:gridCol w="' . $cWn . '"/>';
    $grid .= '</a:tblGrid>';

    $trows = '';
    foreach ($rows as $row) {
        $trows .= '<a:tr h="' . $rH . '">';
        foreach ($row['cells'] as $ci => $val) {
            $trows .= $tc($val, $row['hdr'], $ci === 0 && !$row['hdr'], $row['tot']);
        }
        $trows .= '</a:tr>';
    }

    // ── slide1.xml ───────────────────────────────────────────────
    $dateStr = date('d/m/Y');
    $slide = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
           . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
           . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
           . '<p:cSld>'
           . '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="F4F6FB"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
           . '<p:spTree>'
           . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
           . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $SW . '" cy="' . $SH . '"/>'
           . '<a:chOff x="0" y="0"/><a:chExt cx="' . $SW . '" cy="' . $SH . '"/></a:xfrm></p:grpSpPr>'
           // bande titre
           . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="bg2"/>'
           . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
           . '<p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $SW . '" cy="600000"/></a:xfrm>'
           . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
           . '<a:solidFill><a:srgbClr val="06033A"/></a:solidFill>'
           . '<a:ln><a:noFill/></a:ln></p:spPr>'
           . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>'
           // texte titre
           . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="titre"/>'
           . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
           . '<p:spPr><a:xfrm><a:off x="270000" y="90000"/><a:ext cx="8600000" cy="430000"/></a:xfrm>'
           . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
           . '<a:noFill/><a:ln><a:noFill/></a:ln></p:spPr>'
           . '<p:txBody><a:bodyPr anchor="ctr" wrap="square"><a:spAutoFit/></a:bodyPr><a:lstStyle/>'
           . '<a:p>'
           . '<a:r><a:rPr lang="fr-FR" b="1" sz="2200" dirty="0">'
           . '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:rPr>'
           . '<a:t>Vue Stock Bobines</a:t></a:r>'
           . '<a:r><a:rPr lang="fr-FR" sz="1500" dirty="0">'
           . '<a:solidFill><a:srgbClr val="8BB8D8"/></a:solidFill></a:rPr>'
           . '<a:t>  ' . $total_bobines . ' bobines  |  ' . number_format($total_films) . ' films  |  ' . $dateStr . '</a:t></a:r>'
           . '</a:p>'
           . '</p:txBody></p:sp>'
           // tableau
           . '<p:graphicFrame>'
           . '<p:nvGraphicFramePr>'
           . '<p:cNvPr id="4" name="tbl"/>'
           . '<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr>'
           . '<p:nvPr/></p:nvGraphicFramePr>'
           . '<p:xfrm><a:off x="0" y="' . $tY . '"/><a:ext cx="' . $SW . '" cy="' . $tH . '"/></p:xfrm>'
           . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">'
           . '<a:tbl><a:tblPr/>' . $grid . $trows . '</a:tbl>'
           . '</a:graphicData></a:graphic>'
           . '</p:graphicFrame>'
           . '</p:spTree></p:cSld>'
           . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';

    // ── Fichiers statiques ────────────────────────────────────────
    $NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $NS_OFF  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $NS_PML  = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    $NS_ADR  = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $NS_CT   = 'http://schemas.openxmlformats.org/package/2006/content-types';

    $rels_root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Relationships xmlns="' . $NS_PKG . '">'
               . '<Relationship Id="rId1" Type="' . $NS_OFF . '/officeDocument" Target="ppt/presentation.xml"/>'
               . '</Relationships>';

    $rels_pres = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Relationships xmlns="' . $NS_PKG . '">'
               . '<Relationship Id="rId1" Type="' . $NS_OFF . '/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
               . '<Relationship Id="rId2" Type="' . $NS_OFF . '/slide"       Target="slides/slide1.xml"/>'
               . '<Relationship Id="rId3" Type="' . $NS_OFF . '/theme"       Target="theme/theme1.xml"/>'
               . '</Relationships>';

    $rels_slide = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="' . $NS_PKG . '">'
                . '<Relationship Id="rId1" Type="' . $NS_OFF . '/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
                . '</Relationships>';

    $rels_layout = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 . '<Relationships xmlns="' . $NS_PKG . '">'
                 . '<Relationship Id="rId1" Type="' . $NS_OFF . '/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
                 . '</Relationships>';

    $rels_master = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 . '<Relationships xmlns="' . $NS_PKG . '">'
                 . '<Relationship Id="rId1" Type="' . $NS_OFF . '/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
                 . '<Relationship Id="rId2" Type="' . $NS_OFF . '/theme"       Target="../theme/theme1.xml"/>'
                 . '</Relationships>';

    $presentation = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                  . '<p:presentation xmlns:p="' . $NS_PML . '" xmlns:a="' . $NS_ADR . '"'
                  . ' xmlns:r="' . $NS_OFF . '">'
                  . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
                  . '<p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>'
                  . '<p:sldSz cx="9144000" cy="5143500" type="screen4x3"/>'
                  . '<p:notesSz cx="6858000" cy="9144000"/>'
                  . '</p:presentation>';

    $layout = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldLayout xmlns:p="' . $NS_PML . '" xmlns:a="' . $NS_ADR . '"'
            . ' xmlns:r="' . $NS_OFF . '" type="blank" preserve="1">'
            . '<p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            . '</p:sldLayout>';

    $master = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldMaster xmlns:p="' . $NS_PML . '" xmlns:a="' . $NS_ADR . '"'
            . ' xmlns:r="' . $NS_OFF . '">'
            . '<p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2"'
            . ' accent1="acc1" accent2="acc2" accent3="acc3" accent4="acc4"'
            . ' accent5="acc5" accent6="acc6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles>'
            . '<p:titleStyle><a:lstStyle/></p:titleStyle>'
            . '<p:bodyStyle><a:lstStyle/></p:bodyStyle>'
            . '<p:otherStyle><a:lstStyle/></p:otherStyle>'
            . '</p:txStyles>'
            . '</p:sldMaster>';

    $theme = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<a:theme xmlns:a="' . $NS_ADR . '" name="Office Theme">'
           . '<a:themeElements>'
           . '<a:clrScheme name="Office">'
           . '<a:dk1><a:srgbClr val="06033A"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
           . '<a:dk2><a:srgbClr val="1B75BC"/></a:dk2><a:lt2><a:srgbClr val="E8EDF8"/></a:lt2>'
           . '<a:accent1><a:srgbClr val="4472C4"/></a:accent1>'
           . '<a:accent2><a:srgbClr val="ED7D31"/></a:accent2>'
           . '<a:accent3><a:srgbClr val="A9D18E"/></a:accent3>'
           . '<a:accent4><a:srgbClr val="FFC000"/></a:accent4>'
           . '<a:accent5><a:srgbClr val="5B9BD5"/></a:accent5>'
           . '<a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
           . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink>'
           . '<a:folHlink><a:srgbClr val="954F72"/></a:folHlink>'
           . '</a:clrScheme>'
           . '<a:fontScheme name="Office">'
           . '<a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
           . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
           . '</a:fontScheme>'
           . '<a:fmtScheme name="Office">'
           . '<a:fillStyleLst>'
           . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
           . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
           . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
           . '</a:fillStyleLst>'
           . '<a:lnStyleLst>'
           . '<a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
           . '<a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
           . '<a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
           . '</a:lnStyleLst>'
           . '<a:effectStyleLst>'
           . '<a:effectStyle><a:effectLst/></a:effectStyle>'
           . '<a:effectStyle><a:effectLst/></a:effectStyle>'
           . '<a:effectStyle><a:effectLst/></a:effectStyle>'
           . '</a:effectStyleLst>'
           . '<a:bgFillStyleLst>'
           . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
           . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
           . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
           . '</a:bgFillStyleLst>'
           . '</a:fmtScheme>'
           . '</a:themeElements></a:theme>';

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                   . '<Types xmlns="' . $NS_CT . '">'
                   . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                   . '<Default Extension="xml"  ContentType="application/xml"/>'
                   . '<Override PartName="/ppt/presentation.xml"'
                   . ' ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
                   . '<Override PartName="/ppt/slides/slide1.xml"'
                   . ' ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>'
                   . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml"'
                   . ' ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
                   . '<Override PartName="/ppt/slideMasters/slideMaster1.xml"'
                   . ' ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
                   . '<Override PartName="/ppt/theme/theme1.xml"'
                   . ' ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
                   . '</Types>';

    // ── Assemblage ZIP en mémoire ─────────────────────────────────
    $pptxFile = tempnam(sys_get_temp_dir(), 'pptx_');
    $zip = new ZipArchive();
    if ($zip->open($pptxFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo 'Erreur: impossible de créer le fichier PPTX.'; exit;
    }
    $zip->addFromString('[Content_Types].xml',                          $content_types);
    $zip->addFromString('_rels/.rels',                                  $rels_root);
    $zip->addFromString('ppt/presentation.xml',                         $presentation);
    $zip->addFromString('ppt/_rels/presentation.xml.rels',              $rels_pres);
    $zip->addFromString('ppt/slides/slide1.xml',                        $slide);
    $zip->addFromString('ppt/slides/_rels/slide1.xml.rels',             $rels_slide);
    $zip->addFromString('ppt/slideLayouts/slideLayout1.xml',            $layout);
    $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $rels_layout);
    $zip->addFromString('ppt/slideMasters/slideMaster1.xml',            $master);
    $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $rels_master);
    $zip->addFromString('ppt/theme/theme1.xml',                         $theme);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
    header('Content-Disposition: attachment; filename="stock_bobines_' . date('Ymd') . '.pptx"');
    header('Content-Length: ' . filesize($pptxFile));
    readfile($pptxFile);
    unlink($pptxFile);
    exit;
}

// ── Export PDF ───────────────────────────────────────────────
if ($export === 'pdf') {
    if (!file_exists($_autoload)) { echo 'Dompdf non installé.'; exit; }

    // Deux colonnes par type (bobines, films) : au-delà d'une dizaine de
    // types, le tableau dépassait la largeur de l'A4 et les derniers types
    // (et les totaux) sortaient de la page. Le tableau est donc découpé en
    // blocs de $par_bloc types, un bloc par page, la colonne Site répétée ;
    // les totaux par site sont dans le dernier bloc.
    $par_bloc = 8;
    $blocs    = array_chunk(array_values($types), $par_bloc);
    if (!$blocs) $blocs = [[]];
    $idx_ss   = !empty($sans_site) ? array_column($sans_site, null, 'type_code') : [];
    $nb_c     = fn($nb) => "<td class='c' style='color:" . ($nb > 0 ? '#1B75BC' : '#94a3b8') . "'>$nb</td>";

    $tables = '';
    foreach ($blocs as $i => $bloc) {
        $dernier = $i === count($blocs) - 1;
        $th = '';
        foreach ($bloc as $t) {
            $t_h = htmlspecialchars($t);
            $th .= "<th>Bobs.<br>$t_h</th><th>Films<br>$t_h</th>";
        }
        if ($dernier) $th .= '<th>Total<br>bobines</th><th>Total<br>films</th>';

        $rows = '';
        $even = false;
        foreach (array_keys($par_site) as $sn) {
            $cells = '';
            foreach ($bloc as $t) {
                $cell = $idx[$sn][$t] ?? null;
                $cells .= $nb_c($cell ? (int)$cell['nb_bobines'] : 0)
                        . "<td class='c'>" . number_format($cell ? (int)$cell['total_films'] : 0) . "</td>";
            }
            if ($dernier)
                $cells .= "<td class='c tot'>" . (int)$par_site[$sn]['nb_bobines'] . "</td>"
                        . "<td class='c tot'>" . number_format((int)$par_site[$sn]['total_films']) . "</td>";
            $rows .= "<tr style='background:" . ($even ? '#f8fafc' : '#fff') . "'><td class='site'>" . htmlspecialchars($sn) . "</td>$cells</tr>";
            $even = !$even;
        }
        if (!empty($sans_site)) {
            $cells = '';
            foreach ($bloc as $t) {
                $ss = $idx_ss[$t] ?? null;
                $cells .= "<td class='c'>" . ($ss ? (int)$ss['nb'] : 0) . "</td><td class='c'>" . ($ss ? number_format((int)$ss['films']) : '0') . "</td>";
            }
            if ($dernier)
                $cells .= "<td class='c tot'>" . array_sum(array_column($sans_site, 'nb')) . "</td>"
                        . "<td class='c tot'>" . number_format(array_sum(array_column($sans_site, 'films'))) . "</td>";
            $rows .= "<tr style='background:#fffbeb'><td class='site' style='font-style:italic;color:#92400e'>En dépôt (sans site)</td>$cells</tr>";
        }
        $cells = '';
        foreach ($bloc as $t)
            $cells .= "<td class='c tot'>" . (int)($par_type[$t]['nb_bobines'] ?? 0) . "</td>"
                    . "<td class='c tot'>" . number_format((int)($par_type[$t]['total_films'] ?? 0)) . "</td>";
        if ($dernier)
            $cells .= "<td class='c tot'>$total_bobines</td><td class='c tot'>" . number_format($total_films) . "</td>";
        $rows .= "<tr class='total'><td class='site'>TOTAL</td>$cells</tr>";

        $titre_bloc = count($blocs) > 1
            ? '<div class="bloc">Types ' . htmlspecialchars(reset($bloc) . ' à ' . end($bloc)) . ' &nbsp;·&nbsp; partie ' . ($i + 1) . ' / ' . count($blocs) . '</div>'
            : '';
        $tables .= ($i > 0 ? '<div style="page-break-before:always"></div>' : '') . $titre_bloc
                 . "<table class='grille'><thead><tr><th class='site'>Site</th>$th</tr></thead><tbody>$rows</tbody></table>";
    }

    $filtre_txt = '';
    if ($f_site)   $filtre_txt .= ' · Site filtré';
    if ($f_type)   $filtre_txt .= ' · Type : ' . htmlspecialchars($f_type);
    if ($f_statut) $filtre_txt .= ' · Statut : ' . htmlspecialchars($f_statut);

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
    @page{margin:12mm 10mm}
    body{font-family:DejaVu Sans,Arial,sans-serif;font-size:8px;color:#1e293b}
    .sub{font-size:8px;color:#64748b;margin-bottom:8px}
    .bloc{font-size:9px;font-weight:bold;color:#06033A;margin:0 0 5px}
    table.grille{width:100%;border-collapse:collapse}
    table.grille thead{display:table-header-group}
    table.grille tr{page-break-inside:avoid}
    table.grille th{background:#06033A;color:#fff;padding:5px 4px;font-size:7.5px;text-align:center;font-weight:bold}
    table.grille td{padding:4px 4px;border-bottom:1px solid #e2e8f0}
    table.grille .site{text-align:left;font-weight:bold;width:110px}
    table.grille .c{text-align:center;white-space:nowrap}
    table.grille .tot{font-weight:bold;color:#06033A}
    table.grille tr.total td{background:#e8f0fe;font-weight:bold;color:#06033A}
    </style></head><body>
    <table width="100%" style="border-collapse:collapse;margin-bottom:8px"><tr>
      <td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>
      <td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">
        <div style="font-size:13px;font-weight:bold;color:#06033A">Vue Stock Bobines</div>
        <div style="font-size:8px;color:#64748b;margin-top:2px">Express Multiservices CI</div>
      </td>
    </tr></table>
    <div class="sub">Généré le ' . date('d/m/Y H:i') . $filtre_txt . ' &nbsp;·&nbsp; ' . count(array_keys($par_site)) . ' site(s) &nbsp;·&nbsp; ' . $total_bobines . ' bobines &nbsp;·&nbsp; ' . number_format($total_films) . ' films &nbsp;·&nbsp; ' . count($types) . ' type(s)</div>
    ' . $tables . '
    </body></html>';

    $opts = new Options();
    $opts->set('isRemoteEnabled', false);
    $opts->set('defaultFont', 'DejaVu Sans');
    $pdf = new Dompdf($opts);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="stock_bobines_' . date('Ymd') . '.pdf"');
    echo $pdf->output();
    exit;
}

// ============================================================
//  VUE HTML
// ============================================================
include __DIR__ . '/../templates/header.php';
?>
<style>
/* ── Table ── */
.vue-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px}

/* ── En-têtes types (colonnes) ── */
.vue-table thead tr.row-types th{
  background:#0a1a3a;
  color:#fff;padding:16px 18px;text-align:center;
  white-space:nowrap;position:sticky;top:0;z-index:2;
  border-right:1px solid rgba(255,255,255,.1)}
.vue-table thead tr.row-types th.th-site{
  text-align:left;min-width:190px;padding-left:22px;
  background:#06033A;border-right:3px solid #4da6d8}
.vue-table thead tr.row-types th.th-type .type-label{
  display:inline-block;background:rgba(255,255,255,.12);
  border:1.5px solid rgba(255,255,255,.28);border-radius:8px;
  padding:5px 14px;font-size:13px;font-weight:800;letter-spacing:.5px}
.vue-table thead tr.row-types th.th-total{
  background:#04102a;border-left:3px solid #4da6d8;
  padding:16px 22px}
.vue-table thead tr.row-types th.th-total .type-label{
  background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.25)}

/* ── En-têtes sous-labels (Qté / Films) ── */
.vue-table thead tr.row-sub th{
  background:#0f2144;color:rgba(255,255,255,.6);
  font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
  padding:5px 18px 8px;text-align:center;
  position:sticky;top:52px;z-index:2;
  border-bottom:2px solid rgba(255,255,255,.08);
  border-right:1px solid rgba(255,255,255,.06)}
.vue-table thead tr.row-sub th.th-site-sub{
  text-align:left;padding-left:22px;background:#081530;
  border-right:3px solid #1B75BC}
.vue-table thead tr.row-sub th.th-total-sub{
  background:#081530;border-left:3px solid #4da6d8}

/* ── Colonne site (en-tête de ligne) ── */
.vue-table td.site-name{
  background:linear-gradient(90deg,#e8eeff 0%,#f0f4ff 100%);
  text-align:left;font-weight:700;font-size:13px;color:#06033A;
  padding:14px 16px 14px 20px;
  border-right:4px solid #1B75BC;
  white-space:nowrap;min-width:190px}
.vue-table td.site-name .site-icon{
  display:inline-block;width:7px;height:7px;border-radius:50%;
  background:#1B75BC;margin-right:8px;vertical-align:middle;flex-shrink:0}

/* ── Cellules données ── */
.vue-table td{
  padding:12px 14px;border-bottom:1px solid #eef0f5;
  text-align:center;vertical-align:middle;background:#fff;
  border-right:1px solid #f0f2f8}
.vue-table tbody tr:nth-child(even) td{background:#fafbff}
.vue-table tbody tr:nth-child(even) td.site-name{
  background:linear-gradient(90deg,#dde5ff 0%,#e8eeff 100%)}
.vue-table tbody tr:not(.total-row):hover td{background:#edf2ff}
.vue-table tbody tr:not(.total-row):hover td.site-name{
  background:linear-gradient(90deg,#c7d4ff 0%,#d8e2ff 100%)}
.vue-table tbody tr:not(.total-row):hover td.td-total{background:#bdd6f5}

/* ── Colonne Total ── */
.vue-table td.td-total{
  background:linear-gradient(90deg,#d6e8fa 0%,#e4f0fd 100%);
  border-left:4px solid #1B75BC;border-right:none;min-width:110px}
.vue-table tbody tr:nth-child(even) td.td-total{
  background:linear-gradient(90deg,#c5ddf7 0%,#d6e8fa 100%)}

/* ── Ligne sans site ── */
.vue-table tr.sans-site-row td{background:#fffbeb}
.vue-table tr.sans-site-row td.site-name{
  background:linear-gradient(90deg,#fef3c7 0%,#fffbeb 100%);
  color:#92400e;border-right-color:#f59e0b}
.vue-table tr.sans-site-row td.td-total{
  background:linear-gradient(90deg,#fde68a 0%,#fef3c7 100%);
  border-left-color:#f59e0b}

/* ── Ligne TOTAL GÉNÉRAL ── */
.vue-table tbody tr.total-row td,
.vue-table tbody tr.total-row:nth-child(even) td{
  background:#06033A !important;border-top:2px solid #1B75BC;border-bottom:none;
  border-right:1px solid rgba(255,255,255,.08)}
.vue-table tbody tr.total-row td.site-name,
.vue-table tbody tr.total-row:nth-child(even) td.site-name{
  background:linear-gradient(90deg,#06033A 0%,#0d1a4a 100%) !important;
  color:#fff;font-size:12px;font-weight:900;letter-spacing:.8px;text-transform:uppercase;
  border-right:4px solid #4da6d8}
.vue-table tbody tr.total-row td.td-total,
.vue-table tbody tr.total-row:nth-child(even) td.td-total{
  background:linear-gradient(90deg,#04102a 0%,#06033A 100%) !important;
  border-left:4px solid #4da6d8}

/* ── Contenu cellule ── */
.cell-nb{font-size:20px;font-weight:900;color:#06033A;line-height:1}
.cell-films{font-size:12px;color:#9ca3af;margin-top:4px;letter-spacing:.2px}
.cell-empty{color:#d1d5db;font-size:16px}
.tag-cours{display:inline-block;background:#dbeafe;color:#1d4ed8;border-radius:20px;
  padding:2px 7px;font-size:12px;font-weight:700;margin-top:5px}
.total-row .cell-nb{color:#fff;font-size:22px}
.total-row .cell-films{color:rgba(255,255,255,.45)}
.td-total .cell-nb{color:#1B75BC;font-size:22px}
.td-total .cell-films{color:#6b9fd4}
.total-row .td-total .cell-nb{color:#7dc4f5}
.total-row .td-total .cell-films{color:rgba(125,196,245,.5)}
/* KPI */
.kpi-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px}
.kpi{
  background:#fff;border-radius:14px;border:1px solid #e8edf8;
  padding:18px 20px;position:relative;overflow:hidden;
  box-shadow:0 1px 6px rgba(6,3,58,.06)}
.kpi::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--kpi-c,#1B75BC);border-radius:4px 0 0 4px}
.kpi-v{font-size:30px;font-weight:900;color:var(--kpi-c,#06033A);line-height:1;margin-bottom:4px}
.kpi-l{font-size:12px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.kpi-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:28px;opacity:.12}
/* Section titre */
.page-banner{
  background:linear-gradient(135deg,#06033A 0%,#1B75BC 100%);
  border-radius:16px;padding:24px 28px;margin-bottom:24px;
  display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;
  box-shadow:0 4px 20px rgba(6,3,58,.2)}
.page-banner h2{color:#fff;font-size:22px;font-weight:800;margin:0;display:flex;align-items:center;gap:10px}
.page-banner p{color:rgba(255,255,255,.75);font-size:13px;margin:4px 0 0}
.export-btn{
  display:inline-flex;align-items:center;gap:7px;padding:10px 18px;
  border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;
  text-decoration:none;transition:transform .1s,box-shadow .1s}
.export-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn-csv {background:#fff;color:#2e7d32;border:1.5px solid #a5d6a7}
.btn-xlsx{background:#fff;color:#1565c0;border:1.5px solid #90caf9}
.btn-pptx{background:#fff;color:#c62828;border:1.5px solid #ef9a9a}
.btn-pdf {background:#fff;color:#7e1d1d;border:1.5px solid #fca5a5}
/* Filtres */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;
  background:#fff;padding:14px 20px;border-radius:12px;
  border:1px solid #e8edf8;margin-bottom:22px;
  box-shadow:0 1px 4px rgba(6,3,58,.04)}
.fsel{padding:9px 12px;border:1.5px solid #e0e4ef;border-radius:9px;font-size:13px;background:white;outline:none;color:#06033A;cursor:pointer}
.fsel:focus{border-color:#1B75BC}
.overflow-wrap{overflow-x:auto;border-radius:14px;box-shadow:0 2px 16px rgba(6,3,58,.08);border:1px solid #e8edf8}
</style>

<!-- BANNIÈRE TITRE -->
<div class="page-banner">
  <div>
    <h2><i class="ph ph-film-strip" aria-hidden="true"></i> Vue Stock Bobines</h2>
    <p>Synthèse par site et par type — <?= date('d/m/Y') ?> · <?= $total_bobines ?> bobines · <?= number_format($total_films) ?> films</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv']))  ?>" class="export-btn btn-csv"><i class="ph ph-file-text" aria-hidden="true"></i> CSV</a>
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'xlsx'])) ?>" class="export-btn btn-xlsx"><i class="ph ph-chart-bar" aria-hidden="true"></i> Excel</a>
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'pdf']))  ?>" class="export-btn btn-pdf"><i class="ph ph-note" aria-hidden="true"></i> PDF</a>
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'pptx'])) ?>" class="export-btn btn-pptx"><i class="ph ph-files" aria-hidden="true"></i> PowerPoint</a>
  </div>
</div>

<!-- FILTRES -->
<div class="filter-bar">
  <form method="GET" style="display:contents">
    <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()">
      <option value="">🏢 Tous les sites</option>
      <?php foreach ($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="fsel" aria-label="Filtrer par type" onchange="this.form.submit()">
      <option value="">🎞️ Tous les types</option>
      <?php foreach ($types_list as $t): ?>
      <option value="<?= h($t['type_code']) ?>" <?= $f_type===$t['type_code']?'selected':'' ?>><?= h($t['type_code']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="statut" class="fsel" aria-label="Filtrer par statut" onchange="this.form.submit()">
      <option value="">⚡ Tous statuts</option>
      <option value="en_cours" <?= $f_statut==='en_cours'?'selected':'' ?>>En cours</option>
      <option value="en_stock" <?= $f_statut==='en_stock'?'selected':'' ?>>En stock</option>
    </select>
    <?php if ($f_site || $f_type || $f_statut): ?>
    <a href="stock_bobines_vue.php" style="font-size:13px;color:#9ca3af;text-decoration:none;padding:8px 12px;border-radius:8px;border:1.5px solid #e0e4ef"><i class="ph ph-x" aria-hidden="true"></i> Réinitialiser</a>
    <?php endif; ?>
  </form>
</div>

<!-- KPI -->
<div class="kpi-bar">
  <div class="kpi" style="--kpi-c:#1B75BC">
    <div class="kpi-icon"><i class="ph ph-film-strip" aria-hidden="true"></i></div>
    <div class="kpi-v"><?= $total_bobines ?></div>
    <div class="kpi-l">Bobines actives</div>
  </div>
  <div class="kpi" style="--kpi-c:#2e7d32">
    <div class="kpi-icon">🎬</div>
    <div class="kpi-v"><?= number_format($total_films) ?></div>
    <div class="kpi-l">Films restants</div>
  </div>
  <div class="kpi" style="--kpi-c:#1565c0">
    <div class="kpi-icon"><i class="ph ph-buildings" aria-hidden="true"></i></div>
    <div class="kpi-v"><?= count($par_site) ?></div>
    <div class="kpi-l">Sites actifs</div>
  </div>
  <div class="kpi" style="--kpi-c:#7b1fa2">
    <div class="kpi-icon"><i class="ph ph-tag" aria-hidden="true"></i></div>
    <div class="kpi-v"><?= count($types) ?></div>
    <div class="kpi-l">Types de bobine</div>
  </div>
</div>

<!-- TABLEAU -->
<?php if (empty($par_site) && empty($sans_site)): ?>
<div style="text-align:center;padding:60px;color:#9ca3af;background:#fff;border-radius:14px;border:1px solid #e8edf8">
  <div style="font-size:48px;margin-bottom:12px"><i class="ph ph-film-strip" aria-hidden="true"></i></div>
  <div style="font-size:16px;font-weight:600">Aucune bobine active pour les filtres sélectionnés.</div>
</div>
<?php else: ?>
<div class="overflow-wrap">
<table class="vue-table">
  <thead>
    <!-- Ligne 1 : types de bobine -->
    <tr class="row-types">
      <th class="th-site" rowspan="2">Site</th>
      <?php foreach ($types as $t): ?>
      <th class="th-type"><span class="type-label"><?= h($t) ?></span></th>
      <?php endforeach; ?>
      <th class="th-total" rowspan="2"><span class="type-label">Total</span></th>
    </tr>
    <!-- Ligne 2 : sous-labels Qté / Films -->
    <tr class="row-sub">
      <?php foreach ($types as $t): ?>
      <th class="th-sub">Qté · Films</th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($par_site as $site_nom => $site_tot): ?>
    <tr>
      <td class="site-name"><span class="site-icon"></span><?= h($site_nom) ?></td>
      <?php foreach ($types as $t):
            $cell = $idx[$site_nom][$t] ?? null; ?>
      <td>
        <?php if ($cell): ?>
          <div class="cell-nb"><?= $cell['nb_bobines'] ?></div>
          <div class="cell-films"><?= number_format($cell['total_films']) ?> films</div>
          <?php if ($cell['nb_en_cours'] > 0): ?>
          <div class="tag-cours"><?= $cell['nb_en_cours'] ?> en cours</div>
          <?php endif; ?>
        <?php else: ?><span class="cell-empty">—</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
      <td class="td-total">
        <div class="cell-nb" style="color:#1B75BC"><?= $site_tot['nb_bobines'] ?></div>
        <div class="cell-films"><?= number_format($site_tot['total_films']) ?> films</div>
      </td>
    </tr>
    <?php endforeach; ?>

    <?php if (!empty($sans_site)): ?>
    <tr class="sans-site-row">
      <td class="site-name"><i class="ph ph-package" aria-hidden="true"></i> En dépôt</td>
      <?php $idx_ss = array_column($sans_site, null, 'type_code');
            foreach ($types as $t): $found = $idx_ss[$t] ?? null; ?>
      <td>
        <?php if ($found): ?>
          <div class="cell-nb" style="color:#92400e"><?= $found['nb'] ?></div>
          <div class="cell-films"><?= number_format($found['films']) ?> films</div>
        <?php else: ?><span class="cell-empty">—</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
      <td class="td-total">
        <div class="cell-nb" style="color:#92400e"><?= array_sum(array_column($sans_site,'nb')) ?></div>
        <div class="cell-films"><?= number_format(array_sum(array_column($sans_site,'films'))) ?> films</div>
      </td>
    </tr>
    <?php endif; ?>

    <tr class="total-row">
      <td class="site-name">TOTAL GÉNÉRAL</td>
      <?php foreach ($types as $t): $tc = $par_type[$t] ?? null; ?>
      <td>
        <?php if ($tc): ?>
          <div class="cell-nb"><?= $tc['nb_bobines'] ?></div>
          <div class="cell-films"><?= number_format($tc['total_films']) ?> films</div>
        <?php else: ?><span class="cell-empty" style="color:rgba(255,255,255,.2)">—</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
      <td class="td-total">
        <div class="cell-nb"><?= $total_bobines ?></div>
        <div class="cell-films"><?= number_format($total_films) ?> films</div>
      </td>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
