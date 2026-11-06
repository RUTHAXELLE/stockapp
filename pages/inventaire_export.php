<?php
// ============================================================
//  pages/inventaire_export.php — Export global des inventaires
//  Tous les sites à la fois, sur une date ou une période : synthèse par
//  site puis détail, pour les types choisis (bobines, rivets, PMMA,
//  équipements). Excel = synthèse + une feuille de détail par type ;
//  PDF = synthèse, pour la lecture et l'impression.
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();

$user        = current_user();
$page_title  = 'Export des inventaires';
$active_page = 'inventaire_export';

// Les exports contiennent la quantité de référence et l'écart, masqués au
// coordinateur pendant le comptage (même règle que inventaire_detail.php).
if (($user['role_slug'] ?? '') === 'coordinateur_site') {
    http_response_code(403);
    exit('Export réservé aux profils de validation.');
}

// ── Types d'inventaire : libellé, et accès selon le droit de lecture du module
$TYPES = [
    'bobines'     => 'Bobines',
    'rivets'      => 'Rivets',
    'pmma'        => 'PMMA',
    'equipements' => 'Équipements',
];
$acces = [
    'bobines'     => can('inventaire_bobines', 'can_read'),
    'rivets'      => can('inventaire_rivets', 'can_read'),
    'pmma'        => can('inventaire_pmma', 'can_read'),
    'equipements' => can('inventaire_equipements', 'can_read'),
];
$types_dispo = array_filter($TYPES, fn($k) => $acces[$k], ARRAY_FILTER_USE_KEY);
if (!$types_dispo) {
    http_response_code(403);
    exit('Accès refusé.');
}

// ── Filtres
$date_ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
$du = $_GET['du'] ?? '';
$au = $_GET['au'] ?? '';
if (!$date_ok($du)) $du = date('Y-m-01');
if (!$date_ok($au)) $au = date('Y-m-d');
if ($au < $du) [$du, $au] = [$au, $du];

$types_sel = array_values(array_intersect((array)($_GET['types'] ?? array_keys($types_dispo)), array_keys($types_dispo)));
if (!$types_sel) $types_sel = array_keys($types_dispo);
$statut  = in_array($_GET['statut'] ?? '', ['valide', 'tous'], true) ? $_GET['statut'] : 'valide';
$site_id = (int)($_GET['site'] ?? 0);
$export  = $_GET['export'] ?? '';

$sites = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");

// ── Inventaires de la période (tous sites, ou le site choisi)
function inv_export_entetes(string $type, string $du, string $au, string $statut, int $site_id): array {
    $sql = "SELECT i.*, s.nom AS site_nom FROM inventaires_$type i
            LEFT JOIN sites s ON s.id = i.site_id
            WHERE i.date_inventaire BETWEEN ? AND ?"
         . ($statut === 'valide' ? " AND i.statut = 'valide'" : " AND i.statut <> 'annule'")
         . ($site_id ? " AND i.site_id = ?" : '')
         . " ORDER BY s.nom, i.date_inventaire, i.id";
    $p = [$du, $au];
    if ($site_id) $p[] = $site_id;
    return db_fetch_all($sql, $p);
}

// ── Lignes de détail d'un ensemble d'inventaires, indexées par inventaire
function inv_export_details(string $type, array $ids): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    switch ($type) {
        case 'bobines':
            $sql = "SELECT d.*, b.numero AS article, b.type_code AS sous_type FROM inventaire_details_bobines d
                    JOIN op_bobines b ON b.id = d.bobine_id WHERE d.inventaire_id IN ($ph) ORDER BY b.type_code, b.numero";
            break;
        case 'rivets':
            $sql = "SELECT d.*, d.type_rivet AS article FROM inventaire_details_rivets d
                    WHERE d.inventaire_id IN ($ph) ORDER BY d.type_rivet";
            break;
        case 'pmma':
            $sql = "SELECT d.*, d.type_pmma AS article FROM inventaire_details_pmma d
                    WHERE d.inventaire_id IN ($ph) ORDER BY d.type_pmma";
            break;
        default:
            $sql = "SELECT d.*, e.numero_serie_interne AS article, n.libelle AS sous_type, e.marque, e.modele
                    FROM inventaire_details_equipements d
                    JOIN equipements e ON e.id = d.equipement_id
                    LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id
                    WHERE d.inventaire_id IN ($ph) ORDER BY n.libelle, e.numero_serie_interne";
    }
    $res = [];
    foreach (db_fetch_all($sql, $ids) as $l) $res[(int)$l['inventaire_id']][] = $l;
    return $res;
}

// Ligne comptée : stock_physique est NOT NULL (0 à la création) ; seul un
// écart stocké distingue « compté à zéro » de « pas compté » (même critère
// que la validation, cf. inv_valider_bobines()).
function inv_export_compte(array $l): bool {
    return (int)$l['stock_physique'] > 0 || (int)$l['ecart'] !== 0 || !empty($l['absente']);
}

// ── Données : par type, chaque inventaire avec ses lignes et sa synthèse
$donnees = [];
foreach ($types_sel as $t) {
    $invs = inv_export_entetes($t, $du, $au, $statut, $site_id);
    $det  = inv_export_details($t, array_map(fn($i) => (int)$i['id'], $invs));
    foreach ($invs as &$inv) {
        $lignes = $det[(int)$inv['id']] ?? [];
        $inv['lignes'] = $lignes;
        if ($t === 'equipements') {
            $tr = count(array_filter($lignes, fn($l) => $l['trouve'] !== null && (int)$l['trouve'] === 1));
            $mq = count(array_filter($lignes, fn($l) => $l['trouve'] !== null && (int)$l['trouve'] === 0));
            $inv['syn'] = ['nb' => count($lignes), 'comptes' => $tr + $mq, 'trouves' => $tr, 'manquants' => $mq];
        } else {
            $comptees = array_filter($lignes, 'inv_export_compte');
            $inv['syn'] = [
                'nb'        => count($lignes),
                'comptes'   => count($comptees),
                'reference' => array_sum(array_map(fn($l) => (int)$l['stock_systeme'], $comptees)),
                'compte'    => array_sum(array_map(fn($l) => (int)$l['stock_physique'], $comptees)),
                'ecart'     => array_sum(array_map(fn($l) => (int)$l['ecart'], $comptees)),
                'nb_ecarts' => count(array_filter($comptees, fn($l) => (int)$l['ecart'] !== 0)),
            ];
        }
    }
    unset($inv);
    $donnees[$t] = $invs;
}

$periode_txt = $du === $au ? 'du ' . fmt_date($du) : 'du ' . fmt_date($du) . ' au ' . fmt_date($au);
$site_txt    = 'Tous les sites';
foreach ($sites as $s) if ((int)$s['id'] === $site_id) $site_txt = $s['nom'];
$statut_lbl  = fn($s) => ['valide' => 'Validé', 'brouillon' => 'En cours'][$s] ?? ucfirst((string)$s);
$ref_lbl     = fn($t) => $t === 'bobines' ? 'Qté physique (déclarée)' : 'Qté système';
$nom_fichier = 'inventaires_' . str_replace('-', '', $du) . ($du !== $au ? '_' . str_replace('-', '', $au) : '');

// ============================================================
//  EXPORT EXCEL : synthèse + une feuille de détail par type
// ============================================================
if ($export === 'xlsx') {
    $sp = new Spreadsheet();
    $style_titre = ['font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF06033A']]];
    $style_entete = ['font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                     'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B75BC']],
                     'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]];
    $style_total = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F0FE']]];

    // Écrit un tableau (titre, en-têtes, lignes, total) et met en forme
    $ecrire = function ($sh, string $titre, array $entetes, array $rows, ?array $total, array $largeurs, array $col_ecart = []) use ($style_titre, $style_entete, $style_total) {
        $der = Coordinate::stringFromColumnIndex(count($entetes));
        $sh->mergeCells("A1:{$der}1");
        $sh->setCellValue('A1', $titre);
        $sh->getStyle("A1:{$der}1")->applyFromArray($style_titre);
        $sh->getRowDimension(1)->setRowHeight(24);
        $sh->fromArray($entetes, null, 'A2');
        $sh->getStyle("A2:{$der}2")->applyFromArray($style_entete);
        $sh->getRowDimension(2)->setRowHeight(30);
        $r = 3;
        foreach ($rows as $row) {
            $sh->fromArray($row, null, "A$r", true);
            foreach ($col_ecart as $ci) {
                $v = $row[$ci - 1] ?? null;
                if (is_int($v) && $v !== 0) {
                    $c = Coordinate::stringFromColumnIndex($ci);
                    $sh->getStyle("$c$r")->getFont()->setBold(true)->getColor()->setARGB($v < 0 ? 'FFDC2626' : 'FF16A34A');
                }
            }
            $r++;
        }
        if ($total) {
            $sh->fromArray($total, null, "A$r", true);
            $sh->getStyle("A$r:$der$r")->applyFromArray($style_total);
        } else {
            $r--;
        }
        if ($r >= 2) $sh->getStyle("A2:$der$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach ($largeurs as $i => $w) $sh->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        $sh->freezePane('A3');
    };

    // Feuille Synthèse
    $sh = $sp->getActiveSheet()->setTitle('Synthèse');
    $rows = [];
    foreach ($donnees as $t => $invs) {
        foreach ($invs as $inv) {
            $s = $inv['syn'];
            $rows[] = $t === 'equipements'
                ? [$TYPES[$t], $inv['site_nom'] ?? '—', fmt_date($inv['date_inventaire']), $inv['type_inventaire'], $statut_lbl($inv['statut']),
                   $s['nb'], $s['comptes'], '', '', -$s['manquants'], $s['manquants'], $inv['valide_at'] ? fmt_datetime($inv['valide_at']) : '']
                : [$TYPES[$t], $inv['site_nom'] ?? '—', fmt_date($inv['date_inventaire']), $inv['type_inventaire'], $statut_lbl($inv['statut']),
                   $s['nb'], $s['comptes'], $s['reference'], $s['compte'], $s['ecart'], $s['nb_ecarts'], $inv['valide_at'] ? fmt_datetime($inv['valide_at']) : ''];
        }
    }
    $ecrire($sh, "Inventaires $periode_txt — $site_txt",
        ['Type', 'Site', 'Date', 'Inventaire', 'Statut', 'Lignes', 'Lignes comptées', 'Qté de référence', 'Qté comptée', 'Écart', 'Nb écarts / manquants', 'Validé le'],
        $rows, null, [14, 24, 12, 13, 11, 9, 11, 14, 12, 10, 13, 17], [10]);
    $r = count($rows) + 4;
    $sh->setCellValue("A$r", 'Qté de référence : stock physique déclaré (bobines) ou stock système (rivets, PMMA) au moment de l\'inventaire. Équipements : l\'écart compte les manquants.');
    $sh->getStyle("A$r")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF64748B');

    // Une feuille de détail par type
    foreach ($donnees as $t => $invs) {
        $sh = $sp->createSheet()->setTitle($TYPES[$t]);
        $rows = [];
        if ($t === 'equipements') {
            foreach ($invs as $inv) foreach ($inv['lignes'] as $l)
                $rows[] = [$inv['site_nom'] ?? '—', fmt_date($inv['date_inventaire']), $statut_lbl($inv['statut']), $l['article'], $l['sous_type'] ?? '',
                           trim(($l['marque'] ?? '') . ' ' . ($l['modele'] ?? '')),
                           $l['trouve'] === null ? 'Non vérifié' : ((int)$l['trouve'] === 1 ? 'Trouvé' : 'Manquant'), $l['notes'] ?? ''];
            $ecrire($sh, "Inventaires équipements $periode_txt — $site_txt",
                ['Site', 'Date', 'Statut', 'N° série interne', 'Type', 'Marque / Modèle', 'Constat', 'Notes'],
                $rows, null, [24, 12, 11, 20, 22, 24, 13, 30]);
            continue;
        }
        $tot = [0, 0, 0];
        foreach ($invs as $inv) foreach ($inv['lignes'] as $l) {
            $c = inv_export_compte($l);
            if ($c) { $tot[0] += (int)$l['stock_systeme']; $tot[1] += (int)$l['stock_physique']; $tot[2] += (int)$l['ecart']; }
            $base = [$inv['site_nom'] ?? '—', fmt_date($inv['date_inventaire']), $statut_lbl($inv['statut']), $l['article']];
            if ($t === 'bobines') $base[] = $l['sous_type'] ?? '';
            $rows[] = array_merge($base, [(int)$l['stock_systeme'], $c ? (!empty($l['absente']) ? 'Absente' : (int)$l['stock_physique']) : 'Non compté', $c ? (int)$l['ecart'] : '', $l['notes'] ?? '']);
        }
        $entetes = ['Site', 'Date', 'Statut', $t === 'bobines' ? 'Bobine' : 'Format'];
        if ($t === 'bobines') $entetes[] = 'Type';
        $entetes = array_merge($entetes, [$ref_lbl($t), 'Qté comptée', 'Écart', 'Notes']);
        $n = count($entetes);
        $total = array_fill(0, $n, '');
        $total[0] = 'TOTAL (lignes comptées)';
        [$total[$n - 4], $total[$n - 3], $total[$n - 2]] = $tot;
        $largeurs = $t === 'bobines' ? [24, 12, 11, 16, 10, 16, 13, 10, 30] : [24, 12, 11, 18, 16, 13, 10, 30];
        $ecrire($sh, "Inventaires " . ($t === "pmma" ? "PMMA" : mb_strtolower($TYPES[$t])) . " $periode_txt — $site_txt", $entetes, $rows, $total, $largeurs, [$n - 1]);
    }
    $sp->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nom_fichier . '.xlsx"');
    header('Cache-Control: max-age=0');
    $tmp = tempnam(sys_get_temp_dir(), 'invx_');
    (new XlsxWriter($sp))->save($tmp);
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ============================================================
//  EXPORT PDF : synthèse par type, un tableau par type
// ============================================================
if ($export === 'pdf') {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $nb  = fn($v) => number_format((int)$v, 0, ',', ' ');
    $ecart_html = function (int $v) use ($nb) {
        if ($v === 0) return '<span style="color:#94a3b8">0</span>';
        return '<strong style="color:' . ($v < 0 ? '#DC2626' : '#16A34A') . '">' . ($v > 0 ? '+' : '−') . $nb(abs($v)) . '</strong>';
    };
    $sections = '';
    foreach ($donnees as $t => $invs) {
        $sections .= '<h2>' . $e($TYPES[$t]) . ' <span class="cnt">' . count($invs) . ' inventaire(s)</span></h2>';
        if (!$invs) { $sections .= '<p class="vide">Aucun inventaire sur la période.</p>'; continue; }
        if ($t === 'equipements') {
            $sections .= '<table><thead><tr><th>Site</th><th>Date</th><th>Statut</th><th class="n">Équipements</th><th class="n">Vérifiés</th><th class="n">Trouvés</th><th class="n">Manquants</th></tr></thead><tbody>';
            $T = [0, 0, 0, 0];
            foreach ($invs as $inv) {
                $s = $inv['syn'];
                $T[0] += $s['nb']; $T[1] += $s['comptes']; $T[2] += $s['trouves']; $T[3] += $s['manquants'];
                $sections .= '<tr><td>' . $e($inv['site_nom'] ?? '—') . '</td><td>' . fmt_date($inv['date_inventaire']) . '</td><td>' . $e($statut_lbl($inv['statut'])) . '</td>'
                    . '<td class="n">' . $nb($s['nb']) . '</td><td class="n">' . $nb($s['comptes']) . '</td><td class="n">' . $nb($s['trouves']) . '</td>'
                    . '<td class="n">' . ($s['manquants'] ? '<strong style="color:#DC2626">' . $nb($s['manquants']) . '</strong>' : '0') . '</td></tr>';
            }
            $sections .= '<tr class="tot"><td colspan="3">Total</td><td class="n">' . $nb($T[0]) . '</td><td class="n">' . $nb($T[1]) . '</td><td class="n">' . $nb($T[2]) . '</td><td class="n">' . $nb($T[3]) . '</td></tr></tbody></table>';
            continue;
        }
        $sections .= '<table><thead><tr><th>Site</th><th>Date</th><th>Statut</th><th class="n">Lignes comptées</th><th class="n">' . $e($ref_lbl($t)) . '</th><th class="n">Qté comptée</th><th class="n">Écart</th><th class="n">Lignes en écart</th></tr></thead><tbody>';
        $T = [0, 0, 0, 0, 0, 0];
        foreach ($invs as $inv) {
            $s = $inv['syn'];
            $T[0] += $s['comptes']; $T[1] += $s['nb']; $T[2] += $s['reference']; $T[3] += $s['compte']; $T[4] += $s['ecart']; $T[5] += $s['nb_ecarts'];
            $sections .= '<tr><td>' . $e($inv['site_nom'] ?? '—') . '</td><td>' . fmt_date($inv['date_inventaire']) . '</td><td>' . $e($statut_lbl($inv['statut'])) . '</td>'
                . '<td class="n">' . $nb($s['comptes']) . ' / ' . $nb($s['nb']) . '</td><td class="n">' . $nb($s['reference']) . '</td><td class="n">' . $nb($s['compte']) . '</td>'
                . '<td class="n">' . $ecart_html($s['ecart']) . '</td><td class="n">' . $nb($s['nb_ecarts']) . '</td></tr>';
        }
        $sections .= '<tr class="tot"><td colspan="3">Total</td><td class="n">' . $nb($T[0]) . ' / ' . $nb($T[1]) . '</td><td class="n">' . $nb($T[2]) . '</td><td class="n">' . $nb($T[3]) . '</td><td class="n">' . $ecart_html($T[4]) . '</td><td class="n">' . $nb($T[5]) . '</td></tr></tbody></table>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
    @page{margin:14mm 12mm}
    body{font-family:DejaVu Sans,Arial,sans-serif;font-size:9px;color:#1e293b}
    h2{font-size:11px;color:#06033A;margin:16px 0 6px;padding-bottom:3px;border-bottom:1.5px solid #06033A}
    h2 .cnt{font-size:8.5px;color:#64748b;font-weight:normal}
    table{width:100%;border-collapse:collapse;page-break-inside:auto}
    tr{page-break-inside:avoid}
    th{background:#06033A;color:#fff;padding:5px 6px;font-size:8px;text-align:left}
    td{padding:4px 6px;border-bottom:1px solid #e2e8f0}
    tr:nth-child(even) td{background:#f8fafc}
    .n{text-align:right}
    tr.tot td{background:#e8f0fe;font-weight:bold}
    .vide{color:#94a3b8;font-style:italic;margin:4px 0}
    .note{margin-top:14px;font-size:7.5px;color:#64748b}
    </style></head><body>
    <table style="margin-bottom:6px"><tr>
      <td style="width:85px;border:none;background:none">' . pdf_logo_img('38px') . '</td>
      <td style="border:none;background:none;border-left:3px solid #06033A;padding-left:12px">
        <div style="font-size:13px;font-weight:bold;color:#06033A">Inventaires ' . $e($periode_txt) . '</div>
        <div style="font-size:9px;color:#64748b;margin-top:2px">' . $e($site_txt) . ' · ' . ($statut === 'valide' ? 'inventaires validés' : 'inventaires validés et en cours')
            . ' · généré le ' . date('d/m/Y H:i') . '</div>
      </td>
    </tr></table>' . $sections . '
    <p class="note">Qté de référence : stock physique déclaré (bobines) ou stock système (rivets, PMMA) au moment de l\'inventaire. Les totaux ne portent que sur les lignes comptées. Le détail ligne par ligne est dans l\'export Excel.</p>
    </body></html>';

    $opts = new Options();
    $opts->set('isRemoteEnabled', false);
    $opts->set('defaultFont', 'DejaVu Sans');
    $pdf = new Dompdf($opts);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nom_fichier . '.pdf"');
    echo $pdf->output();
    exit;
}

// ============================================================
//  PAGE
// ============================================================
include __DIR__ . '/../templates/header.php';
$qs = fn(string $exp) => '?' . http_build_query(['du' => $du, 'au' => $au, 'types' => $types_sel, 'statut' => $statut, 'site' => $site_id, 'export' => $exp]);
?>
<style>
.exp-filtres{background:white;border:1px solid var(--border);border-radius:14px;padding:18px 20px;margin-bottom:20px;
  display:flex;flex-wrap:wrap;gap:18px 24px;align-items:flex-end}
.exp-champ{display:flex;flex-direction:column;gap:6px}
.exp-champ > label,.exp-champ > span{font-size:12px;font-weight:700;color:var(--navy)}
.exp-champ input[type=date],.exp-champ select{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white}
.exp-types{display:flex;flex-wrap:wrap;gap:8px}
.exp-types label{display:flex;align-items:center;gap:6px;padding:8px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;cursor:pointer}
.exp-types label:has(input:checked){border-color:var(--blue);background:#eaf2fb;color:var(--navy);font-weight:600}
.exp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
.exp-bloc{margin-bottom:22px}
.exp-bloc h3{font-size:15px;color:var(--navy);margin:0 0 8px;display:flex;align-items:baseline;gap:8px}
.exp-bloc h3 small{font-size:12px;color:var(--muted);font-weight:600}
.exp-vide{color:var(--muted);font-size:13px;padding:10px 0}
.ec-neg{color:#dc2626;font-weight:700}.ec-pos{color:#16a34a;font-weight:700}
.exp-bloc .table-wrap{background:white;border:1px solid var(--border);border-radius:14px}
.statut-inv{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.statut-brouillon{background:#fef9e7;color:#9a6c00} .statut-valide{background:#d5f5e3;color:#1e7e40}
@media (max-width:700px){.exp-actions{margin-left:0;width:100%}.exp-actions .btn{flex:1;justify-content:center}}
</style>

<form method="get" class="exp-filtres">
  <div class="exp-champ"><label for="du">Du</label><input type="date" id="du" name="du" value="<?= h($du) ?>"></div>
  <div class="exp-champ"><label for="au">Au</label><input type="date" id="au" name="au" value="<?= h($au) ?>"></div>
  <div class="exp-champ"><span>Inventaires</span>
    <div class="exp-types">
      <?php foreach ($types_dispo as $k => $lib): ?>
      <label><input type="checkbox" name="types[]" value="<?= h($k) ?>" <?= in_array($k, $types_sel, true) ? 'checked' : '' ?>> <?= h($lib) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="exp-champ"><label for="statut">Statut</label>
    <select id="statut" name="statut">
      <option value="valide" <?= $statut === 'valide' ? 'selected' : '' ?>>Validés seulement</option>
      <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Validés et en cours</option>
    </select>
  </div>
  <div class="exp-champ"><label for="site">Site</label>
    <select id="site" name="site">
      <option value="0">Tous les sites</option>
      <?php foreach ($sites as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $site_id ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="exp-actions">
    <button type="submit" class="btn btn-secondary"><i class="ph ph-funnel" aria-hidden="true"></i> Afficher</button>
    <a href="<?= h($qs('xlsx')) ?>" class="btn btn-primary"><i class="ph-duotone ph-microsoft-excel-logo" aria-hidden="true"></i> Exporter en Excel</a>
    <a href="<?= h($qs('pdf')) ?>" class="btn btn-primary"><i class="ph-duotone ph-file-pdf" aria-hidden="true"></i> Exporter en PDF</a>
  </div>
</form>

<p style="font-size:13px;color:var(--muted);margin:-6px 0 18px">
  Inventaires <?= h($periode_txt) ?> · <?= h($site_txt) ?>. Les boutons d'export reprennent les filtres affichés ;
  après une modification, cliquez d'abord sur « Afficher ».
</p>

<?php foreach ($donnees as $t => $invs): ?>
<div class="exp-bloc">
  <h3><?= h($TYPES[$t]) ?> <small><?= count($invs) ?> inventaire(s)</small></h3>
  <?php if (!$invs): ?>
  <div class="exp-vide">Aucun inventaire sur la période.</div>
  <?php else: ?>
  <div class="table-wrap">
  <table data-paginer="25">
    <thead><tr>
      <th>Site</th><th>Date</th><th>Statut</th>
      <?php if ($t === 'equipements'): ?>
      <th style="text-align:right">Équipements</th><th style="text-align:right">Vérifiés</th><th style="text-align:right">Manquants</th>
      <?php else: ?>
      <th style="text-align:right">Lignes comptées</th><th style="text-align:right"><?= h($ref_lbl($t)) ?></th>
      <th style="text-align:right">Qté comptée</th><th style="text-align:right">Écart</th>
      <?php endif; ?>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($invs as $inv): $s = $inv['syn'];
          $lien = $t === 'bobines' ? 'inventaire_detail.php' : "inventaire_detail_$t.php"; ?>
      <tr>
        <td><?= h($inv['site_nom'] ?? '—') ?></td>
        <td><?= fmt_date($inv['date_inventaire']) ?></td>
        <td><span class="statut-inv statut-<?= h($inv['statut']) ?>"><?= h($statut_lbl($inv['statut'])) ?></span></td>
        <?php if ($t === 'equipements'): ?>
        <td style="text-align:right"><?= number_format($s['nb'], 0, ',', ' ') ?></td>
        <td style="text-align:right"><?= number_format($s['comptes'], 0, ',', ' ') ?></td>
        <td style="text-align:right" class="<?= $s['manquants'] ? 'ec-neg' : '' ?>"><?= number_format($s['manquants'], 0, ',', ' ') ?></td>
        <?php else: ?>
        <td style="text-align:right"><?= $s['comptes'] ?> / <?= $s['nb'] ?></td>
        <td style="text-align:right"><?= number_format($s['reference'], 0, ',', ' ') ?></td>
        <td style="text-align:right"><?= number_format($s['compte'], 0, ',', ' ') ?></td>
        <td style="text-align:right" class="<?= $s['ecart'] < 0 ? 'ec-neg' : ($s['ecart'] > 0 ? 'ec-pos' : '') ?>"><?= $s['ecart'] > 0 ? '+' : '' ?><?= number_format($s['ecart'], 0, ',', ' ') ?></td>
        <?php endif; ?>
        <td style="text-align:right"><a href="<?= h($lien) ?>?id=<?= (int)$inv['id'] ?>" class="btn btn-secondary btn-sm">Voir</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
