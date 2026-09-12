<?php
// ============================================================
//  pages/tracabilite_endommagements.php
//  Historique des bobines endommagées — n° 2.1 du CR de réunion PDG.
//
//  Les déclarations sont saisies dans le pop-up du point journalier
//  (pages/operations/point_journalier.php) et stockées dans
//  op_endommagements, une ligne PAR FILM endommagé : six films abîmés
//  sur une même bobine peuvent l'avoir été à six moments et pour six
//  causes, et c'est ce détail que la traçabilité doit restituer.
//  Cet écran ne fait que lire : la source reste le point journalier,
//  pour garder un seul chemin d'écriture.
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();
require_permission('tracabilite_endommagements', 'can_read');

$user        = current_user();
$role_slug   = $user['role_slug'] ?? '';
$page_title  = 'Traçabilité des endommagements';
$active_page = 'tracabilite_endommagements';

// Le coordinateur ne voit que son site, comme partout ailleurs.
$is_coord   = ($role_slug === 'coordinateur_site');
$site_force = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;

$ETAPES = ['pose'=>'À la pose','impression'=>"À l'impression",'transport'=>'Au transport',
           'stockage'=>'Au stockage','autre'=>'Autre'];
$CAUSES = ['manipulation'=>'Manipulation incorrecte','defaut_materiel'=>'Défaut matériel',
           'incident_externe'=>'Incident externe','autre'=>'Autre'];

// ── FILTRES
$f_site  = $site_force ?: (int)($_GET['site'] ?? 0);
$f_du    = trim($_GET['du'] ?? date('Y-m-01'));
$f_au    = trim($_GET['au'] ?? date('Y-m-d'));
$f_etape = trim($_GET['etape'] ?? '');
$f_cause = trim($_GET['cause'] ?? '');
$f_pers  = trim($_GET['personne'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_du)) $f_du = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_au)) $f_au = date('Y-m-d');

$where  = ["e.created_at::date BETWEEN ? AND ?"];
$params = [$f_du, $f_au];
if ($f_site)  { $where[] = "e.site_id = ?";  $params[] = $f_site; }
if (isset($ETAPES[$f_etape])) { $where[] = "e.etape = ?"; $params[] = $f_etape; }
if (isset($CAUSES[$f_cause])) { $where[] = "e.cause = ?"; $params[] = $f_cause; }
if ($f_pers)  { $where[] = "e.personne ILIKE ?"; $params[] = '%'.$f_pers.'%'; }
$wsql = implode(' AND ', $where);

$lignes = db_fetch_all(
    "SELECT e.*, b.numero AS bobine_num, b.type_code,
            s.nom AS site_nom, p.date_point, p.type_point,
            CONCAT(u.prenom,' ',u.nom) AS declare_par
     FROM op_endommagements e
     LEFT JOIN op_bobines b ON b.id = e.bobine_id
     LEFT JOIN sites s      ON s.id = e.site_id
     LEFT JOIN op_points_journaliers p ON p.id = e.point_id
     LEFT JOIN users u      ON u.id = e.created_by
     WHERE $wsql
     ORDER BY e.created_at DESC, e.id DESC",
    $params
);

$total_films = 0;
$par_etape = $par_cause = [];
foreach ($lignes as $l) {
    $total_films += 1;
    $par_etape[$l['etape']] = ($par_etape[$l['etape']] ?? 0) + 1;
    $par_cause[$l['cause']] = ($par_cause[$l['cause']] ?? 0) + 1;
}
arsort($par_etape); arsort($par_cause);
$etape_top = $par_etape ? array_key_first($par_etape) : null;
$cause_top = $par_cause ? array_key_first($par_cause) : null;

$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

// ============================================================
//  EXPORTS — même périmètre que l'écran, filtres compris.
// ============================================================
$export = trim($_GET['export'] ?? '');
if ($export !== '' && !can('tracabilite_endommagements', 'can_export')) {
    http_response_code(403);
    exit("Export non autorisé pour ce profil.");
}

if ($export === 'xlsx') {
    $sp = new Spreadsheet();
    $sh = $sp->getActiveSheet()->setTitle('Endommagements');
    $entetes = ['Date','Site','Bobine','Film n°','Étape','Cause','Personne','Heure','Observations','Déclaré par'];
    foreach ($entetes as $i => $t) {
        $col = chr(65 + $i);
        $sh->setCellValue($col.'1', $t);
        $sh->getStyle($col.'1')->applyFromArray([
            'font'      => ['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'06033A']],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
        ]);
    }
    $r = 2;
    foreach ($lignes as $l) {
        $sh->setCellValue("A$r", fmt_date($l['date_point'] ?: $l['created_at']));
        $sh->setCellValue("B$r", $l['site_nom'] ?? '—');
        $sh->setCellValue("C$r", $l['bobine_num'] ?? '—');
        $sh->setCellValue("D$r", (int)$l['film_no']);
        $sh->setCellValue("E$r", $ETAPES[$l['etape']] ?? $l['etape']);
        $sh->setCellValue("F$r", $CAUSES[$l['cause']] ?? $l['cause']);
        $sh->setCellValue("G$r", $l['personne']);
        $sh->setCellValue("H$r", $l['heure'] ? substr($l['heure'],0,5) : '—');
        $sh->setCellValue("I$r", $l['observations'] ?? '');
        $sh->setCellValue("J$r", $l['declare_par'] ?? '—');
        $r++;
    }
    foreach (range('A','J') as $c) $sh->getColumnDimension($c)->setAutoSize(true);
    audit_log($user['id'],'READ','tracabilite_endommagements',0,"Export XLSX ($f_du → $f_au)");
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="endommagements_'.$f_du.'_'.$f_au.'.xlsx"');
    header('Cache-Control: max-age=0');
    (new XlsxWriter($sp))->save('php://output');
    exit;
}

if ($export === 'pdf') {
    ob_start(); ?>
    <style>
      body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1a1a2e}
      h1{font-size:15px;color:#06033A;margin:0 0 3px}
      .meta{font-size:9px;color:#64748b;margin-bottom:12px}
      table{width:100%;border-collapse:collapse}
      th{background:#06033A;color:#fff;font-size:8.5px;padding:5px 6px;text-align:left}
      td{border-bottom:1px solid #e2e8f0;padding:5px 6px;font-size:9px}
    </style>
    <h1>Traçabilité des endommagements</h1>
    <div class="meta">
      Du <?= fmt_date($f_du) ?> au <?= fmt_date($f_au) ?>
      · <?= count($lignes) ?> déclaration(s) · <?= $total_films ?> film(s)
      · Généré le <?= date('d/m/Y H:i') ?>
    </div>
    <table>
      <thead><tr>
        <th>Date</th><th>Site</th><th>Bobine</th><th>Film n°</th>
        <th>Étape</th><th>Cause</th><th>Personne</th><th>Observations</th>
      </tr></thead>
      <tbody>
      <?php foreach ($lignes as $l): ?>
        <tr>
          <td><?= h(fmt_date($l['date_point'] ?: $l['created_at'])) ?></td>
          <td><?= h($l['site_nom'] ?? '—') ?></td>
          <td><?= h($l['bobine_num'] ?? '—') ?></td>
          <td><?= (int)$l['film_no'] ?></td>
          <td><?= h($ETAPES[$l['etape']] ?? $l['etape']) ?></td>
          <td><?= h($CAUSES[$l['cause']] ?? $l['cause']) ?></td>
          <td><?= h($l['personne']) ?></td>
          <td><?= h($l['observations'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    $html = ob_get_clean();
    $opt = new Options(); $opt->set('isHtml5ParserEnabled', true); $opt->set('defaultFont','DejaVu Sans');
    $pdf = new Dompdf($opt);
    $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','landscape'); $pdf->render();
    audit_log($user['id'],'READ','tracabilite_endommagements',0,"Export PDF ($f_du → $f_au)");
    $pdf->stream("endommagements_{$f_du}_{$f_au}.pdf", ['Attachment'=>true]);
    exit;
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.endo-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.endo-kpi{background:white;border:1px solid var(--border);border-radius:14px;padding:16px 18px;border-left:4px solid var(--danger)}
.endo-kpi.blue{border-left-color:var(--blue)}
.endo-kpi.orange{border-left-color:#f39c12}
.endo-kpi-v{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;color:var(--navy);line-height:1}
.endo-kpi-l{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:5px}
.endo-f{display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px}
.endo-f label{display:block;font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.endo-f input,.endo-f select{padding:8px 11px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none}
.badge-etape,.badge-cause{display:inline-block;padding:2px 9px;border-radius:11px;font-size:12px;font-weight:700}
.badge-etape{background:#e3f2fd;color:#1565c0}
.badge-cause{background:#fdecea;color:#c0392b}
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">
      <i class="ph ph-first-aid-kit" aria-hidden="true"></i> Traçabilité des endommagements
    </h2>
    <p style="font-size:13px;color:var(--muted);margin-top:4px">
      Déclarations saisies dans le point journalier — une ligne par bobine endommagée.
    </p>
  </div>
  <?php if(can('tracabilite_endommagements','can_export')): ?>
  <div style="display:flex;gap:8px">
    <a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'xlsx']))) ?>">
      <i class="ph-duotone ph-microsoft-excel-logo"></i> Excel
    </a>
    <a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'pdf']))) ?>">
      <i class="ph-duotone ph-file-pdf"></i> PDF
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- KPIs -->
<div class="endo-kpis">
  <div class="endo-kpi">
    <div class="endo-kpi-v"><?= fmt_number($total_films) ?></div>
    <div class="endo-kpi-l">Films endommagés</div>
  </div>
  <div class="endo-kpi blue">
    <div class="endo-kpi-v"><?= fmt_number(count(array_unique(array_column($lignes,'bobine_id')))) ?></div>
    <div class="endo-kpi-l">Bobines concernées</div>
  </div>
  <div class="endo-kpi orange">
    <div class="endo-kpi-v" style="font-size:16px;line-height:1.3">
      <?= $etape_top ? h($ETAPES[$etape_top]) : '—' ?>
    </div>
    <div class="endo-kpi-l">Étape la plus fréquente</div>
  </div>
  <div class="endo-kpi orange">
    <div class="endo-kpi-v" style="font-size:16px;line-height:1.3">
      <?= $cause_top ? h($CAUSES[$cause_top]) : '—' ?>
    </div>
    <div class="endo-kpi-l">Cause la plus fréquente</div>
  </div>
</div>

<!-- FILTRES -->
<form method="GET" class="endo-f">
  <div><label>Du</label><input type="date" name="du" value="<?= h($f_du) ?>"></div>
  <div><label>Au</label><input type="date" name="au" value="<?= h($f_au) ?>"></div>
  <?php if(!$is_coord): ?>
  <div><label>Site</label>
    <select name="site">
      <option value="0">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= $f_site===(int)$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div><label>Étape</label>
    <select name="etape">
      <option value="">Toutes</option>
      <?php foreach($ETAPES as $k=>$lbl): ?>
      <option value="<?= $k ?>" <?= $f_etape===$k?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Cause</label>
    <select name="cause">
      <option value="">Toutes</option>
      <?php foreach($CAUSES as $k=>$lbl): ?>
      <option value="<?= $k ?>" <?= $f_cause===$k?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Personne</label><input type="text" name="personne" value="<?= h($f_pers) ?>" placeholder="Nom…"></div>
  <button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-funnel" aria-hidden="true"></i> Filtrer</button>
  <a class="btn btn-secondary btn-sm" href="tracabilite_endommagements.php"><i class="ph ph-x" aria-hidden="true"></i> Réinitialiser</a>
</form>

<!-- TABLEAU -->
<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap" style="overflow-x:auto">
    <table>
      <thead><tr>
        <th>Date</th><th>Site</th><th>Bobine</th>
        <th style="text-align:center">Film n°</th>
        <th>Étape</th><th>Cause</th><th>Personne</th>
        <th style="text-align:center">Heure</th>
        <th>Observations</th><th>Déclaré par</th>
      </tr></thead>
      <tbody>
      <?php if (empty($lignes)): ?>
        <tr><td colspan="10" style="text-align:center;padding:44px;color:var(--muted)">
          Aucune déclaration d'endommagement sur cette période.
        </td></tr>
      <?php else: foreach ($lignes as $l): ?>
        <tr>
          <td style="white-space:nowrap;font-weight:600"><?= h(fmt_date($l['date_point'] ?: $l['created_at'])) ?></td>
          <td style="font-size:12.5px"><?= h($l['site_nom'] ?? '—') ?></td>
          <td style="font-family:monospace;font-weight:700;color:var(--navy)"><?= h($l['bobine_num'] ?? '—') ?></td>
          <td style="text-align:center;font-weight:800;color:var(--danger-d)"><?= (int)$l['film_no'] ?></td>
          <td><span class="badge-etape"><?= h($ETAPES[$l['etape']] ?? $l['etape']) ?></span></td>
          <td><span class="badge-cause"><?= h($CAUSES[$l['cause']] ?? $l['cause']) ?></span></td>
          <td style="font-size:12.5px;font-weight:600"><?= h($l['personne']) ?></td>
          <td style="text-align:center;font-size:12.5px;color:var(--muted)"><?= $l['heure'] ? h(substr($l['heure'],0,5)) : '—' ?></td>
          <td style="font-size:12.5px;color:var(--muted);max-width:280px"><?= h($l['observations'] ?: '—') ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= h($l['declare_par'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
