<?php
// ============================================================
//  pages/rapports_gsb.php — Rapports & Exports GSB
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();

$user        = current_user();
$role_slug   = $user['role_slug'] ?? '';
require_permission('rapports_gsb', 'can_read');

$page_title  = 'Rapports & Exports';
$active_page = 'rapports_gsb';

$export     = trim($_GET['export']     ?? '');
if ($export !== '') require_export('rapports_gsb');
$date_from  = trim($_GET['date_from']  ?? date('Y-m-01'));
$date_to    = trim($_GET['date_to']    ?? date('Y-m-d'));
$f_site     = (int)($_GET['site']      ?? 0);

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// ── Helpers couleur pour Excel
function xlsColor(string $hex): string {
    return 'FF' . ltrim($hex, '#');
}
function xlsApplyHeader(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh, string $range, string $bgHex, string $fgHex = 'FFFFFF'): void {
    $sh->getStyle($range)->applyFromArray([
        'fill'  => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['argb'=>xlsColor($bgHex)]],
        'font'  => ['bold'=>true, 'color'=>['argb'=>'FF'.$fgHex]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    ]);
}

// ── Données communes (réutilisées par tous les exports)
function get_consommations(string $from, string $to, int $site): array {
    $where  = "WHERE pj.date_point BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($site) { $where .= " AND pj.site_id=?"; $params[] = $site; }
    return db_fetch_all(
        "SELECT pj.date_point, pj.type_point, s.nom AS site_nom,
                b.numero AS bobine_num, b.type_code,
                fu.films_utilises, fu.films_endommages,
                b.films_restants, b.films_total,
                CONCAT(u.prenom,' ',u.nom) AS coordinateur
         FROM op_films_utilises fu
         JOIN op_points_journaliers pj ON pj.id = fu.point_id
         JOIN op_bobines b             ON b.id  = fu.bobine_id
         JOIN sites s                  ON s.id  = pj.site_id
         LEFT JOIN users u             ON u.id  = pj.created_by
         $where
         ORDER BY pj.date_point DESC, s.nom, b.numero",
        $params
    );
}
function get_stock_actuel(int $site): array {
    $where  = "WHERE b.statut IN ('en_cours','en_stock')";
    $params = [];
    if ($site) { $where .= " AND b.site_id=?"; $params[] = $site; }
    return db_fetch_all(
        "SELECT s.nom AS site_nom, b.numero, b.type_code, b.serie,
                b.films_total, b.films_utilises, b.films_endommages, b.films_restants, b.statut
         FROM op_bobines b
         JOIN sites s ON s.id = b.site_id
         $where
         ORDER BY s.nom, b.serie, b.numero",
        $params
    );
}
function get_validations(string $from, string $to, int $site): array {
    $where  = "WHERE v.date_validation BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($site) { $where .= " AND v.site_id=?"; $params[] = $site; }
    return db_fetch_all(
        "SELECT v.date_validation, s.nom AS site_nom, v.statut, v.nb_ecarts,
                CONCAT(u.prenom,' ',u.nom) AS gsb_nom, v.gsb_at, v.commentaire
         FROM validations_stock_matin v
         JOIN sites s   ON s.id  = v.site_id
         LEFT JOIN users u ON u.id = v.gsb_user_id
         $where
         ORDER BY v.date_validation DESC, s.nom",
        $params
    );
}

// ══════════════════════════════════════════════════════
//  EXPORTS
// ══════════════════════════════════════════════════════
if ($export) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) { echo "Composer non installé."; exit; }
    require_once $autoload;

    $label_site = $f_site ? (db_fetch_value("SELECT nom FROM sites WHERE id=?", [$f_site]) ?? "Site $f_site") : 'Tous les sites';
    $label_periode = fmt_date($date_from,'d/m/Y') . ' — ' . fmt_date($date_to,'d/m/Y');

    // ── EXCEL JOURNALIER
    if ($export === 'xlsx_journalier') {
        $conso   = get_consommations($date_from, $date_to, $f_site);
        $stock   = get_stock_actuel($f_site);
        $validat = get_validations($date_from, $date_to, $f_site);

        $sp = new Spreadsheet();

        // Feuille 1 — Consommations
        $sh1 = $sp->getActiveSheet()->setTitle('Consommations');
        $titre = "RAPPORT JOURNALIER — $label_site — $label_periode";
        $sh1->mergeCells('A1:J1'); $sh1->setCellValue('A1', $titre);
        xlsApplyHeader($sh1,'A1:J1','06033A');
        $sh1->getStyle('A1')->getFont()->setSize(12);
        $sh1->getRowDimension(1)->setRowHeight(28);
        $cols1 = ['Date','Type','Site','Bobine','Type bobine','Films utilisés','Endommagés','Restants','Total','Coordinateur'];
        foreach ($cols1 as $i => $h) {
            $cell = Coordinate::stringFromColumnIndex($i+1).'2';
            $sh1->setCellValue($cell, $h);
        }
        xlsApplyHeader($sh1,'A2:J2','1B75BC');
        $row = 3;
        foreach ($conso as $c) {
            $sh1->setCellValue("A$row", $c['date_point']);
            $sh1->setCellValue("B$row", $c['type_point']);
            $sh1->setCellValue("C$row", $c['site_nom']);
            $sh1->setCellValue("D$row", $c['bobine_num']);
            $sh1->setCellValue("E$row", $c['type_code']);
            $sh1->setCellValue("F$row", (int)$c['films_utilises']);
            $sh1->setCellValue("G$row", (int)$c['films_endommages']);
            $sh1->setCellValue("H$row", (int)$c['films_restants']);
            $sh1->setCellValue("I$row", (int)$c['films_total']);
            $sh1->setCellValue("J$row", $c['coordinateur']);
            if ($row % 2 === 0) {
                $sh1->getStyle("A$row:J$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $row++;
        }
        if ($row === 3) $sh1->setCellValue('A3', 'Aucune donnée pour cette période.');
        foreach (range('A','J') as $col) $sh1->getColumnDimension($col)->setAutoSize(true);

        // Feuille 2 — Stock actuel
        $sp->createSheet(); $sh2 = $sp->getSheet(1)->setTitle('Stock actuel');
        $sh2->mergeCells('A1:I1'); $sh2->setCellValue('A1', "ÉTAT DU STOCK BOBINES — $label_site");
        xlsApplyHeader($sh2,'A1:I1','06033A');
        $sh2->getStyle('A1')->getFont()->setSize(12);
        $sh2->getRowDimension(1)->setRowHeight(28);
        $cols2 = ['Site','Bobine','Type','Série','Total films','Utilisés','Endommagés','Restants','Statut'];
        foreach ($cols2 as $i => $h) {
            $cell = Coordinate::stringFromColumnIndex($i+1).'2';
            $sh2->setCellValue($cell, $h);
        }
        xlsApplyHeader($sh2,'A2:I2','1B75BC');
        $row = 3;
        foreach ($stock as $b) {
            $sh2->setCellValue("A$row", $b['site_nom']);
            $sh2->setCellValue("B$row", $b['numero']);
            $sh2->setCellValue("C$row", $b['type_code']);
            $sh2->setCellValue("D$row", $b['serie']);
            $sh2->setCellValue("E$row", (int)$b['films_total']);
            $sh2->setCellValue("F$row", (int)$b['films_utilises']);
            $sh2->setCellValue("G$row", (int)$b['films_endommages']);
            $sh2->setCellValue("H$row", (int)$b['films_restants']);
            $sh2->setCellValue("I$row", $b['statut']);
            $bg = (int)$b['films_restants'] <= 0 ? 'FFFEE2E2' : ((int)$b['films_restants'] < 50 ? 'FFFEF3C7' : 'FFFFFFFF');
            if ($bg !== 'FFFFFFFF') {
                $sh2->getStyle("A$row:I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
            } elseif ($row % 2 === 0) {
                $sh2->getStyle("A$row:I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $row++;
        }
        foreach (range('A','I') as $col) $sh2->getColumnDimension($col)->setAutoSize(true);

        // Feuille 3 — Validations
        $sp->createSheet(); $sh3 = $sp->getSheet(2)->setTitle('Validations');
        $sh3->mergeCells('A1:G1'); $sh3->setCellValue('A1', "HISTORIQUE DES VALIDATIONS — $label_site — $label_periode");
        xlsApplyHeader($sh3,'A1:G1','06033A');
        $sh3->getStyle('A1')->getFont()->setSize(12);
        $sh3->getRowDimension(1)->setRowHeight(28);
        $cols3 = ['Date','Site','Statut','Nb écarts','Validé par','Heure','Commentaire'];
        foreach ($cols3 as $i => $h) {
            $cell = Coordinate::stringFromColumnIndex($i+1).'2';
            $sh3->setCellValue($cell, $h);
        }
        xlsApplyHeader($sh3,'A2:G2','1B75BC');
        $statut_labels = [
            'valide_auto'=>'Auto-validé','valide_gsb'=>'Validé GSB',
            'autorise_ecart'=>'Avec écart','reajuste'=>'Réajusté','refuse'=>'Bloqué',
        ];
        $row = 3;
        foreach ($validat as $v) {
            $sh3->setCellValue("A$row", $v['date_validation']);
            $sh3->setCellValue("B$row", $v['site_nom']);
            $sh3->setCellValue("C$row", $statut_labels[$v['statut']] ?? $v['statut']);
            $sh3->setCellValue("D$row", (int)$v['nb_ecarts']);
            $sh3->setCellValue("E$row", $v['gsb_nom'] ?: 'Automatique');
            $sh3->setCellValue("F$row", $v['gsb_at'] ? date('H:i', strtotime($v['gsb_at'])) : '—');
            $sh3->setCellValue("G$row", $v['commentaire'] ?? '');
            $bgVal = match($v['statut']) {
                'refuse'         => 'FFFEE2E2',
                'autorise_ecart' => 'FFFEF3C7',
                'reajuste'       => 'FFDBEAFE',
                default          => ($row%2===0 ? 'FFF8FAFC' : 'FFFFFFFF'),
            };
            $sh3->getStyle("A$row:G$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bgVal);
            $row++;
        }
        foreach (range('A','G') as $col) $sh3->getColumnDimension($col)->setAutoSize(true);

        $sp->setActiveSheetIndex(0);
        $filename = 'RapportJournalier_' . str_replace('-','', $date_from) . '_' . str_replace('-','', $date_to) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment;filename=\"$filename\"");
        header('Cache-Control: max-age=0');
        (new XlsxWriter($sp))->save('php://output');
        exit;
    }

    // ── EXCEL MENSUEL
    if ($export === 'xlsx_mensuel') {
        $conso = get_consommations($date_from, $date_to, $f_site);
        // Grouper par mois × site × bobine
        $par_mois = [];
        foreach ($conso as $c) {
            $mois = substr($c['date_point'], 0, 7);
            $key  = $mois . '|' . $c['site_nom'] . '|' . $c['bobine_num'];
            if (!isset($par_mois[$key])) {
                $par_mois[$key] = ['mois'=>$mois, 'site_nom'=>$c['site_nom'], 'bobine_num'=>$c['bobine_num'],
                                   'type_code'=>$c['type_code'], 'films_utilises'=>0, 'films_endommages'=>0, 'nb_points'=>0];
            }
            $par_mois[$key]['films_utilises']   += (int)$c['films_utilises'];
            $par_mois[$key]['films_endommages']  += (int)$c['films_endommages'];
            $par_mois[$key]['nb_points']++;
        }

        $sp  = new Spreadsheet();
        $sh1 = $sp->getActiveSheet()->setTitle('Synthèse mensuelle');
        $sh1->mergeCells('A1:G1');
        $sh1->setCellValue('A1', "RAPPORT MENSUEL — $label_site — $label_periode");
        xlsApplyHeader($sh1,'A1:G1','06033A');
        $sh1->getStyle('A1')->getFont()->setSize(12);
        $sh1->getRowDimension(1)->setRowHeight(28);
        $cols = ['Mois','Site','Bobine','Type','Films utilisés','Endommagés','Nb points'];
        foreach ($cols as $i => $h) {
            $cell = Coordinate::stringFromColumnIndex($i+1).'2';
            $sh1->setCellValue($cell, $h);
        }
        xlsApplyHeader($sh1,'A2:G2','1B75BC');
        $row = 3;
        foreach (array_values($par_mois) as $idx => $m) {
            $sh1->setCellValue("A$row", $m['mois']);
            $sh1->setCellValue("B$row", $m['site_nom']);
            $sh1->setCellValue("C$row", $m['bobine_num']);
            $sh1->setCellValue("D$row", $m['type_code']);
            $sh1->setCellValue("E$row", $m['films_utilises']);
            $sh1->setCellValue("F$row", $m['films_endommages']);
            $sh1->setCellValue("G$row", $m['nb_points']);
            if ($row % 2 === 0) {
                $sh1->getStyle("A$row:G$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }
            $row++;
        }
        if ($row === 3) $sh1->setCellValue('A3', 'Aucune donnée pour cette période.');
        foreach (range('A','G') as $col) $sh1->getColumnDimension($col)->setAutoSize(true);

        $filename = 'RapportMensuel_' . str_replace('-','', $date_from) . '_' . str_replace('-','', $date_to) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment;filename=\"$filename\"");
        header('Cache-Control: max-age=0');
        (new XlsxWriter($sp))->save('php://output');
        exit;
    }

    // ── PDF (journalier, mensuel, validations) — génération HTML→dompdf
    if (in_array($export, ['pdf_journalier','pdf_mensuel','pdf_validations'])) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $dompdf = new \Dompdf\Dompdf($options);

        $css = '
        body{font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#1a1a2e;margin:0;padding:0}
        h1{font-size:16px;color:#06033A;margin:0 0 4px}
        .subtitle{font-size:11px;color:#64748b;margin:0 0 16px}
        table{width:100%;border-collapse:collapse;margin-bottom:18px;font-size:10px}
        thead th{background:#06033A;color:white;padding:7px 10px;text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em}
        tbody tr:nth-child(even) td{background:#f8fafc}
        tbody td{padding:6px 10px;border-bottom:1px solid #e2e8f0}
        .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:bold}
        .badge-ok{background:#d1fae5;color:#065f46}
        .badge-warn{background:#fef3c7;color:#92400e}
        .badge-blue{background:#dbeafe;color:#1d4ed8}
        .badge-red{background:#fee2e2;color:#991b1b}
        .total-row td{background:#06033A!important;color:white;font-weight:bold}
        .section-title{font-size:13px;font-weight:bold;color:#06033A;margin:18px 0 8px;border-bottom:2px solid #06033A;padding-bottom:4px}
        ';

        ob_start();
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>$css</style></head><body>";

        if ($export === 'pdf_journalier') {
            $conso = get_consommations($date_from, $date_to, $f_site);
            echo '<table width="100%" style="border-collapse:collapse;margin-bottom:10px"><tr>'
               . '<td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>'
               . '<td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">'
               . '<div style="font-size:16px;font-weight:bold;color:#06033A">Rapport journalier — ' . htmlspecialchars($label_site) . '</div>'
               . '<div style="font-size:9px;color:#64748b;margin-top:2px">Express Multiservices CI</div>'
               . '</td></tr></table>';
            echo "<p class='subtitle'>Période : $label_periode · Généré le " . date('d/m/Y à H:i') . "</p>";

            echo "<p class='section-title'>Détail des consommations</p>";
            echo "<table><thead><tr><th>Date</th><th>Site</th><th>Bobine</th><th>Type</th><th>Utilisés</th><th>Endomm.</th><th>Restants</th></tr></thead><tbody>";
            $total_util = 0; $total_end = 0;
            foreach ($conso as $c) {
                $total_util += (int)$c['films_utilises'];
                $total_end  += (int)$c['films_endommages'];
                echo "<tr><td>" . htmlspecialchars($c['date_point']) . "</td><td>" . htmlspecialchars($c['site_nom']) . "</td><td><b>" . htmlspecialchars($c['bobine_num']) . "</b></td><td>" . htmlspecialchars($c['type_code']) . "</td><td><b>" . (int)$c['films_utilises'] . "</b></td><td>" . (int)$c['films_endommages'] . "</td><td>" . (int)$c['films_restants'] . "</td></tr>";
            }
            if (!$conso) echo "<tr><td colspan='7' style='text-align:center;color:#94a3b8'>Aucune donnée</td></tr>";
            echo "<tr class='total-row'><td colspan='4'>TOTAL</td><td>$total_util</td><td>$total_end</td><td></td></tr>";
            echo "</tbody></table>";

            // Stock actuel
            $stock = get_stock_actuel($f_site);
            echo "<p class='section-title'>État du stock par bobine</p>";
            echo "<table><thead><tr><th>Site</th><th>Bobine</th><th>Type</th><th>Total</th><th>Utilisés</th><th>Restants</th><th>Statut</th></tr></thead><tbody>";
            foreach ($stock as $b) {
                $pct   = $b['films_total'] > 0 ? round((int)$b['films_restants']/$b['films_total']*100) : 0;
                $badge = (int)$b['films_restants'] <= 0 ? 'badge-red' : ((int)$b['films_restants'] < 50 ? 'badge-warn' : 'badge-ok');
                echo "<tr><td>" . htmlspecialchars($b['site_nom']) . "</td><td><b>" . htmlspecialchars($b['numero']) . "</b></td><td>" . htmlspecialchars($b['type_code']) . "</td><td>" . (int)$b['films_total'] . "</td><td>" . (int)$b['films_utilises'] . "</td><td><b>" . (int)$b['films_restants'] . "</b></td><td><span class='badge $badge'>$pct%</span></td></tr>";
            }
            if (!$stock) echo "<tr><td colspan='7' style='text-align:center;color:#94a3b8'>Aucune bobine active</td></tr>";
            echo "</tbody></table>";
        }

        elseif ($export === 'pdf_mensuel') {
            $conso = get_consommations($date_from, $date_to, $f_site);
            // Grouper par mois × site
            $par_mois_site = [];
            foreach ($conso as $c) {
                $mois = date('Y-m', strtotime($c['date_point']));
                $key  = $mois . '|' . $c['site_nom'];
                if (!isset($par_mois_site[$key])) {
                    $par_mois_site[$key] = ['mois'=>$mois,'site_nom'=>$c['site_nom'],'films_utilises'=>0,'films_endommages'=>0,'nb_bobines'=>[],'nb_points'=>0];
                }
                $par_mois_site[$key]['films_utilises']  += (int)$c['films_utilises'];
                $par_mois_site[$key]['films_endommages'] += (int)$c['films_endommages'];
                $par_mois_site[$key]['nb_bobines'][$c['bobine_num']] = true;
                $par_mois_site[$key]['nb_points']++;
            }

            echo '<table width="100%" style="border-collapse:collapse;margin-bottom:10px"><tr>'
               . '<td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>'
               . '<td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">'
               . '<div style="font-size:16px;font-weight:bold;color:#06033A">Rapport mensuel — ' . htmlspecialchars($label_site) . '</div>'
               . '<div style="font-size:9px;color:#64748b;margin-top:2px">Express Multiservices CI</div>'
               . '</td></tr></table>';
            echo "<p class='subtitle'>Période : $label_periode · Généré le " . date('d/m/Y à H:i') . "</p>";
            echo "<p class='section-title'>Synthèse mensuelle</p>";
            echo "<table><thead><tr><th>Mois</th><th>Site</th><th>Films utilisés</th><th>Endommagés</th><th>Bobines actives</th><th>Points saisis</th></tr></thead><tbody>";
            $gtotal_u = 0; $gtotal_e = 0;
            foreach ($par_mois_site as $m) {
                $gtotal_u += $m['films_utilises'];
                $gtotal_e += $m['films_endommages'];
                echo "<tr><td>" . htmlspecialchars($m['mois']) . "</td><td>" . htmlspecialchars($m['site_nom']) . "</td><td><b>" . $m['films_utilises'] . "</b></td><td>" . $m['films_endommages'] . "</td><td>" . count($m['nb_bobines']) . "</td><td>" . $m['nb_points'] . "</td></tr>";
            }
            if (!$par_mois_site) echo "<tr><td colspan='6' style='text-align:center;color:#94a3b8'>Aucune donnée</td></tr>";
            echo "<tr class='total-row'><td colspan='2'>TOTAL</td><td>$gtotal_u</td><td>$gtotal_e</td><td></td><td></td></tr>";
            echo "</tbody></table>";
        }

        elseif ($export === 'pdf_validations') {
            $validat = get_validations($date_from, $date_to, $f_site);
            $statut_labels = [
                'valide_auto'=>'Auto-validé','valide_gsb'=>'Validé GSB',
                'autorise_ecart'=>'Avec écart','reajuste'=>'Réajusté','refuse'=>'Bloqué',
            ];
            $badge_class = [
                'valide_auto'=>'badge-ok','valide_gsb'=>'badge-ok',
                'autorise_ecart'=>'badge-warn','reajuste'=>'badge-blue','refuse'=>'badge-red',
            ];
            echo '<table width="100%" style="border-collapse:collapse;margin-bottom:10px"><tr>'
               . '<td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>'
               . '<td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">'
               . '<div style="font-size:16px;font-weight:bold;color:#06033A">Historique des validations — ' . htmlspecialchars($label_site) . '</div>'
               . '<div style="font-size:9px;color:#64748b;margin-top:2px">Express Multiservices CI</div>'
               . '</td></tr></table>';
            echo "<p class='subtitle'>Période : $label_periode · Généré le " . date('d/m/Y à H:i') . "</p>";
            echo "<table><thead><tr><th>Date</th><th>Site</th><th>Statut</th><th>Écarts</th><th>Validé par</th><th>Heure</th><th>Commentaire</th></tr></thead><tbody>";
            foreach ($validat as $v) {
                $label = $statut_labels[$v['statut']] ?? $v['statut'];
                $cls   = $badge_class[$v['statut']] ?? 'badge-ok';
                echo "<tr><td>" . htmlspecialchars($v['date_validation']) . "</td><td>" . htmlspecialchars($v['site_nom']) . "</td><td><span class='badge $cls'>$label</span></td><td>" . (int)$v['nb_ecarts'] . "</td><td>" . htmlspecialchars($v['gsb_nom'] ?: 'Auto') . "</td><td>" . ($v['gsb_at'] ? date('H:i', strtotime($v['gsb_at'])) : '—') . "</td><td style='font-size:9px'>" . htmlspecialchars($v['commentaire'] ?? '') . "</td></tr>";
            }
            if (!$validat) echo "<tr><td colspan='7' style='text-align:center;color:#94a3b8'>Aucune validation</td></tr>";
            echo "</tbody></table>";
        }

        echo "</body></html>";
        $html = ob_get_clean();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $filename = 'Rapport_' . $export . '_' . str_replace('-','', $date_from) . '.pdf';
        $dompdf->stream($filename, ['Attachment'=>true]);
        exit;
    }
}

// ── LISTE DES SITES POUR LE FILTRE
$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

include __DIR__ . '/../templates/header.php';
?>
<style>
.rg-banner{background:linear-gradient(135deg,#06033A 0%,#1B75BC 100%);border-radius:16px;padding:22px 28px;margin-bottom:24px;color:white;display:flex;justify-content:space-between;align-items:center}
.rg-banner h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:19px;font-weight:800;margin:0;color:white}
.rg-banner p{font-size:13px;opacity:.8;margin:4px 0 0;color:white}
.rg-filters{background:white;border:1px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:24px}
.rg-filters-title{font-size:13px;font-weight:800;color:var(--navy);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.rg-filters-row{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap}
.rg-field{display:flex;flex-direction:column;gap:5px}
.rg-field label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.rg-field input,.rg-field select{padding:8px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;color:var(--navy);outline:none;transition:border-color .15s;background:white}
.rg-field input:focus,.rg-field select:focus{border-color:#1B75BC}
.rg-cards{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
.rg-card{background:white;border:1px solid var(--border);border-radius:14px;overflow:hidden}
.rg-card-hdr{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:#fafbfd}
.rg-card-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.rg-card-title{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:800;color:var(--navy)}
.rg-card-sub{font-size:12px;color:var(--muted);margin-top:2px}
.rg-card-body{padding:16px 20px}
.rg-card-desc{font-size:13px;color:var(--muted);margin-bottom:16px}
.rg-btns{display:flex;gap:10px;flex-wrap:wrap}
.btn-export-xl{background:#16a34a;color:white;border:none;border-radius:9px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;text-decoration:none;transition:background .15s}
.btn-export-xl:hover{background:#15803d}
.btn-export-pdf{background:#dc2626;color:white;border:none;border-radius:9px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;text-decoration:none;transition:background .15s}
.btn-export-pdf:hover{background:#b91c1c}
.rg-card-full{background:white;border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px}
.rg-info{background:white;border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px}
.rg-info-hdr{padding:14px 20px;border-bottom:1px solid var(--border);background:#fafbfd;font-weight:800;color:var(--navy);font-size:13px;display:flex;align-items:center;gap:8px}
.rg-info-body{padding:16px 20px;display:grid;grid-template-columns:1fr 1fr;gap:24px}
.rg-info-col h4{font-size:13px;font-weight:700;color:var(--navy);margin:0 0 10px}
.rg-info-col ul{margin:0;padding:0 0 0 18px;color:var(--muted);font-size:13px;line-height:1.8}
@media(max-width:800px){.rg-cards{grid-template-columns:minmax(0,1fr)}.rg-info-body{grid-template-columns:minmax(0,1fr)}}
</style>

<!-- BANNIÈRE -->
<div class="rg-banner">
  <div>
    <h2><i class="ph-duotone ph-file-arrow-down"></i> Rapports & Exports</h2>
    <p>Générez des rapports Excel ou PDF sur les consommations, le stock et les validations.</p>
  </div>
</div>

<!-- FILTRES -->
<div class="rg-filters">
  <div class="rg-filters-title"><i class="ph-duotone ph-funnel"></i> Filtres du rapport</div>
  <div class="rg-filters-row">
    <div class="rg-field">
      <label>Date de début</label>
      <input type="date" id="f-from" value="<?= h(date('Y-m-01')) ?>">
    </div>
    <div class="rg-field">
      <label>Date de fin</label>
      <input type="date" id="f-to" value="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="rg-field">
      <label>Site</label>
      <select id="f-site">
        <option value="0">Tous les sites</option>
        <?php foreach($sites_list as $s): ?>
        <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:8px;align-items:flex-end">
      <button onclick="setAujourdhui()" style="padding:8px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;background:#f0f4ff;color:#1B75BC;display:flex;align-items:center;gap:6px">
        <i class="ph-duotone ph-calendar-blank"></i> Aujourd'hui
      </button>
      <button onclick="setCeMois()" style="padding:8px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;background:#f0f4ff;color:#1B75BC;display:flex;align-items:center;gap:6px">
        <i class="ph-duotone ph-calendar"></i> Ce mois
      </button>
      <button onclick="resetFiltres()" style="padding:8px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;background:white;color:var(--muted)">
        Réinitialiser
      </button>
    </div>
  </div>
</div>

<!-- CARTES RAPPORT JOURNALIER + MENSUEL -->
<div class="rg-cards">

  <!-- Rapport journalier -->
  <div class="rg-card">
    <div class="rg-card-hdr">
      <div class="rg-card-icon" style="background:#e3f2fd"><i class="ph ph-calendar" aria-hidden="true"></i></div>
      <div>
        <div class="rg-card-title">Rapport journalier</div>
        <div class="rg-card-sub">Consommations et état du stock pour la période sélectionnée.</div>
      </div>
    </div>
    <div class="rg-card-body">
      <p class="rg-card-desc">Détail par bobine et par jour avec comparaison stock. Idéal pour les suivis quotidiens.</p>
      <div class="rg-btns">
        <a id="btn-xl-jour" href="#" onclick="return goExport('xlsx_journalier')" class="btn-export-xl">
          <i class="ph-duotone ph-file-xls"></i> Export Excel
        </a>
        <a id="btn-pdf-jour" href="#" onclick="return goExport('pdf_journalier')" class="btn-export-pdf">
          <i class="ph-duotone ph-file-pdf"></i> Export PDF
        </a>
      </div>
    </div>
  </div>

  <!-- Rapport mensuel -->
  <div class="rg-card">
    <div class="rg-card-hdr">
      <div class="rg-card-icon" style="background:#e8f5e9"><i class="ph ph-calendar-blank" aria-hidden="true"></i></div>
      <div>
        <div class="rg-card-title">Rapport mensuel</div>
        <div class="rg-card-sub">Synthèse mensuelle des consommations avec totaux et tendances.</div>
      </div>
    </div>
    <div class="rg-card-body">
      <p class="rg-card-desc">Regroupement par mois et par site. Vue d'ensemble pour les analyses de tendances.</p>
      <div class="rg-btns">
        <a href="#" onclick="return goExport('xlsx_mensuel')" class="btn-export-xl">
          <i class="ph-duotone ph-file-xls"></i> Export Excel
        </a>
        <a href="#" onclick="return goExport('pdf_mensuel')" class="btn-export-pdf">
          <i class="ph-duotone ph-file-pdf"></i> Export PDF
        </a>
      </div>
    </div>
  </div>

</div>

<!-- HISTORIQUE VALIDATIONS -->
<div class="rg-card-full">
  <div class="rg-card-hdr">
    <div class="rg-card-icon" style="background:#d1fae5"><i class="ph ph-check-circle" aria-hidden="true"></i></div>
    <div>
      <div class="rg-card-title">Historique des validations</div>
      <div class="rg-card-sub">Export de toutes les validations de stock (date, bobine, écarts, validateur, commentaires).</div>
    </div>
  </div>
  <div class="rg-card-body">
    <p class="rg-card-desc">Liste complète des validations journalières sur la période : statuts, écarts détectés, décisions prises et commentaires GSB.</p>
    <div class="rg-btns">
      <a href="#" onclick="return goExport('pdf_validations')" class="btn-export-pdf" style="background:#1B75BC">
        <i class="ph-duotone ph-file-pdf"></i> Export PDF — Historique validations
      </a>
    </div>
  </div>
</div>

<!-- INFO CONTENU -->
<div class="rg-info">
  <div class="rg-info-hdr"><i class="ph-duotone ph-info" style="color:#1B75BC;font-size:17px"></i> Contenu des fichiers exportés</div>
  <div class="rg-info-body">
    <div class="rg-info-col">
      <h4>Rapport Excel (.xlsx)</h4>
      <ul>
        <li>Feuille 1 : Détail des consommations</li>
        <li>Feuille 2 : État du stock actuel</li>
        <li>Feuille 3 : Historique des validations</li>
      </ul>
    </div>
    <div class="rg-info-col">
      <h4>Rapport PDF</h4>
      <ul>
        <li>En-tête avec titre, période</li>
        <li>Tableau des consommations</li>
        <li>Total consommé sur la période</li>
        <li>État du stock par bobine</li>
      </ul>
    </div>
  </div>
</div>

<script>
function buildUrl(exportType) {
  const from = document.getElementById('f-from').value;
  const to   = document.getElementById('f-to').value;
  const site = document.getElementById('f-site').value;
  if (!from || !to) { alert('Veuillez sélectionner une période.'); return null; }
  if (from > to) { alert('La date de début doit être antérieure à la date de fin.'); return null; }
  return `?export=${exportType}&date_from=${from}&date_to=${to}&site=${site}`;
}
function goExport(type) {
  const url = buildUrl(type);
  if (url) window.location.href = url;
  return false;
}
function setAujourdhui() {
  const today = new Date().toISOString().split('T')[0];
  document.getElementById('f-from').value = today;
  document.getElementById('f-to').value   = today;
}
function setCeMois() {
  const now   = new Date();
  const year  = now.getFullYear();
  const month = String(now.getMonth()+1).padStart(2,'0');
  const last  = new Date(year, now.getMonth()+1, 0).getDate();
  document.getElementById('f-from').value = `${year}-${month}-01`;
  document.getElementById('f-to').value   = `${year}-${month}-${last}`;
}
function resetFiltres() {
  const now   = new Date();
  const year  = now.getFullYear();
  const month = String(now.getMonth()+1).padStart(2,'0');
  const day   = String(now.getDate()).padStart(2,'0');
  const last  = new Date(year, now.getMonth()+1, 0).getDate();
  document.getElementById('f-from').value = `${year}-${month}-01`;
  document.getElementById('f-to').value   = `${year}-${month}-${day}`;
  document.getElementById('f-site').value = '0';
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
