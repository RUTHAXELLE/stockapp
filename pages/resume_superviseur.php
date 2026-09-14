<?php
// ============================================================
//  pages/resume_superviseur.php
//  Résumé superviseur — Points journaliers + état bobines
//  Vue par site / jour / mois / année + Export Excel
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$page_title  = 'Résumé Superviseur';
$active_page = 'resume_superviseur';

// Module dédié plutôt que 'rapports' (partagé avec des écrans plus
// larges) + liste de rôles en dur — cf. sql/migration_resume_superviseur_
// permission_dediee.sql (audit permissions du 2026-08-29).
require_permission('resume_superviseur', 'can_read');

$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

// ============================================================
// EXPORT EXCEL
// ============================================================
if (isset($_GET['export'])) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) { die('PhpSpreadsheet non installé.'); }
    require_once $autoload;

    $type    = $_GET['export'];
    $f_date  = trim($_GET['date'] ?? date('Y-m-d'));
    $f_mois  = trim($_GET['mois'] ?? date('Y-m'));
    $f_annee = (int)($_GET['annee'] ?? date('Y'));
    $f_site  = (int)($_GET['site'] ?? 0);

    $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $sp->getActiveSheet();

    $navy  = 'FF06033A';
    $blue  = 'FF1b75bc';
    $light = 'FFE8F4F9';

    $header_style = [
        'font'      => ['bold'=>true,'color'=>['argb'=>'FFFFFFFF'],'size'=>11],
        'fill'      => ['fillType'=>'solid','startColor'=>['argb'=>$navy]],
        'alignment' => ['horizontal'=>'center'],
    ];

    if ($type === 'journalier') {
        $ws->setTitle('Points Journaliers');
        $where = ["p.date_point=?"]; $params = [$f_date];
        if ($f_site) { $where[] = "p.site_id=?"; $params[] = $f_site; }
        $data = db_fetch_all(
            "SELECT p.date_point, s.nom AS site, p.type_point,
                    p.nb_vp, p.nb_camion, p.nb_semi, p.nb_moto,
                    p.total_engins, p.total_plaques,
                    p.rivets_utilises, p.rivets_endommages,
                    p.nb_heures_travail, p.statut,
                    CONCAT(u.prenom,' ',u.nom) AS agent
             FROM op_points_journaliers p
             JOIN sites s ON s.id=p.site_id
             LEFT JOIN users u ON u.id=p.created_by
             WHERE ".implode(' AND ',$where)."
             ORDER BY p.date_point DESC, s.nom",
            $params
        );
        $headers = ['Date','Site','Type','VP','Camion','Semi','Moto','Total Engins','Total Plaques','Rivets util.','Rivets endomm.','Heures','Statut','Agent'];
        foreach($headers as $i=>$h) $ws->setCellValueByColumnAndRow($i+1,1,$h);
        $ws->getStyle('A1:N1')->applyFromArray($header_style);
        foreach($data as $ri=>$row) {
            $vals = [$row['date_point'],$row['site'],$row['type_point'],$row['nb_vp'],$row['nb_camion'],$row['nb_semi'],$row['nb_moto'],$row['total_engins'],$row['total_plaques'],$row['rivets_utilises'],$row['rivets_endommages'],$row['nb_heures_travail'],$row['statut'],$row['agent']];
            foreach($vals as $ci=>$v) $ws->setCellValueByColumnAndRow($ci+1,$ri+2,$v);
        }
        foreach(range('A','N') as $c) $ws->getColumnDimension($c)->setAutoSize(true);
        $filename = "PJ_ERP_EMUCI_{$f_date}.xlsx";

    } elseif ($type === 'mensuel') {
        $ws->setTitle('Résumé Mensuel');
        $where = ["TO_CHAR(p.date_point,'YYYY-MM')=?"]; $params = [$f_mois];
        if ($f_site) { $where[] = "p.site_id=?"; $params[] = $f_site; }
        $data = db_fetch_all(
            "SELECT s.nom AS site, TO_CHAR(p.date_point,'YYYY-MM') AS mois,
                    COUNT(*) AS nb_points,
                    SUM(p.total_plaques) AS total_plaques,
                    SUM(p.total_engins) AS total_engins,
                    SUM(p.rivets_utilises) AS rivets_utilises,
                    SUM(p.nb_heures_travail) AS heures_total,
                    SUM(CASE WHEN p.statut='valide' THEN 1 ELSE 0 END) AS points_valides
             FROM op_points_journaliers p
             JOIN sites s ON s.id=p.site_id
             WHERE ".implode(' AND ',$where)."
             GROUP BY p.site_id, mois ORDER BY s.nom",
            $params
        );
        $headers = ['Site','Mois','Nb Points','Total Plaques','Total Engins','Rivets utilisés','Heures travail','Points validés'];
        foreach($headers as $i=>$h) $ws->setCellValueByColumnAndRow($i+1,1,$h);
        $ws->getStyle('A1:H1')->applyFromArray($header_style);
        foreach($data as $ri=>$row) {
            $vals = [$row['site'],$row['mois'],$row['nb_points'],$row['total_plaques'],$row['total_engins'],$row['rivets_utilises'],$row['heures_total'],$row['points_valides']];
            foreach($vals as $ci=>$v) $ws->setCellValueByColumnAndRow($ci+1,$ri+2,$v);
        }
        foreach(range('A','H') as $c) $ws->getColumnDimension($c)->setAutoSize(true);
        $filename = "Resume_Mensuel_{$f_mois}.xlsx";

    } elseif ($type === 'optoplate') {
        $ws->setTitle('OptoPlate');
        $where = ["date_import=?"]; $params = [$f_date];
        if ($f_site) { $where[] = "site_id=?"; $params[] = $f_site; }
        $data = db_fetch_all("SELECT * FROM import_optoplate WHERE ".implode(' AND ',$where)." ORDER BY site_nom_emuci, statut_plaque", $params);
        $headers = ['Date install','Immat','VIN','Type plaque','Statut','Position','N°Film','N°Bobine','Site EMUCI'];
        foreach($headers as $i=>$h) $ws->setCellValueByColumnAndRow($i+1,1,$h);
        $ws->getStyle('A1:I1')->applyFromArray($header_style);
        foreach($data as $ri=>$row) {
            $vals = [$row['date_installation'],$row['immatriculation'],$row['vin'],$row['type_plaque'],$row['statut_plaque'],$row['position'],$row['num_consommable'],$row['num_bobine'],$row['site_nom_emuci']];
            foreach($vals as $ci=>$v) $ws->setCellValueByColumnAndRow($ci+1,$ri+2,$v);
        }
        foreach(range('A','I') as $c) $ws->getColumnDimension($c)->setAutoSize(true);
        $filename = "OptoPlate_{$f_date}.xlsx";
    } else {
        die('Type export inconnu.');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment;filename=\"$filename\"");
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
    exit;
}

// ============================================================
// DONNÉES
// ============================================================
$f_vue   = trim($_GET['vue'] ?? 'journalier');
$f_date  = trim($_GET['date'] ?? date('Y-m-d'));
$f_mois  = trim($_GET['mois'] ?? date('Y-m'));
$f_annee = (int)($_GET['annee'] ?? date('Y'));
$f_site  = (int)($_GET['site'] ?? 0);

// ── VUE JOURNALIÈRE
$pj_jour = [];
if ($f_vue === 'journalier') {
    $where = ["p.date_point=?"]; $params = [$f_date];
    if ($f_site) { $where[] = "p.site_id=?"; $params[] = $f_site; }
    $pj_jour = db_fetch_all(
        "SELECT p.*, s.nom AS site_nom,
                CONCAT(u.prenom,' ',u.nom) AS agent_nom,
                CONCAT(v.prenom,' ',v.nom) AS valideur_nom
         FROM op_points_journaliers p
         JOIN sites s ON s.id=p.site_id
         LEFT JOIN users u ON u.id=p.created_by
         LEFT JOIN users v ON v.id=p.validated_by
         WHERE ".implode(' AND ',$where)."
         ORDER BY s.nom, p.type_point DESC",
        $params
    );
}

// ── VUE MENSUELLE
$pj_mois = [];
$graph_mois = [];
if ($f_vue === 'mensuel') {
    $where = ["TO_CHAR(p.date_point,'YYYY-MM')=?"]; $params = [$f_mois];
    if ($f_site) { $where[] = "p.site_id=?"; $params[] = $f_site; }
    $pj_mois = db_fetch_all(
        "SELECT s.nom AS site_nom, p.site_id,
                COUNT(*) AS nb_points,
                SUM(p.total_plaques) AS total_plaques,
                SUM(p.total_engins) AS total_engins,
                SUM(p.rivets_utilises) AS rivets_utilises,
                SUM(p.nb_heures_travail) AS heures_total,
                AVG(p.moyenne_prod) AS moy_vh_avg,
                SUM(CASE WHEN p.statut='valide' THEN 1 ELSE 0 END) AS points_valides,
                SUM(CASE WHEN p.statut='brouillon' THEN 1 ELSE 0 END) AS points_brouillon
         FROM op_points_journaliers p
         JOIN sites s ON s.id=p.site_id
         WHERE ".implode(' AND ',$where)."
         GROUP BY p.site_id ORDER BY total_plaques DESC",
        $params
    );
    // Données graphique — plaques par jour du mois
    $graph_mois = db_fetch_all(
        "SELECT p.date_point, SUM(p.total_plaques) AS plaques, SUM(p.total_engins) AS engins
         FROM op_points_journaliers p
         WHERE TO_CHAR(p.date_point,'YYYY-MM')=?" . ($f_site?" AND p.site_id=$f_site":"") . "
         GROUP BY p.date_point ORDER BY p.date_point",
        [$f_mois]
    );
}

// ── VUE ANNUELLE
$pj_annee = [];
if ($f_vue === 'annuel') {
    $where = ["EXTRACT(YEAR FROM p.date_point)=?"]; $params = [$f_annee];
    if ($f_site) { $where[] = "p.site_id=?"; $params[] = $f_site; }
    $pj_annee = db_fetch_all(
        "SELECT TO_CHAR(p.date_point,'YYYY-MM') AS mois,
                SUM(p.total_plaques) AS total_plaques,
                SUM(p.total_engins) AS total_engins,
                COUNT(*) AS nb_points,
                SUM(p.rivets_utilises) AS rivets_utilises
         FROM op_points_journaliers p
         WHERE ".implode(' AND ',$where)."
         GROUP BY mois ORDER BY mois",
        $params
    );
}

// KPIs globaux du jour
$kpi = [
    'plaques_jour'  => (int)db_fetch_value("SELECT COALESCE(SUM(total_plaques),0) FROM op_points_journaliers WHERE date_point=?".($f_site?" AND site_id=$f_site":""),[$f_date]),
    'engins_jour'   => (int)db_fetch_value("SELECT COALESCE(SUM(total_engins),0) FROM op_points_journaliers WHERE date_point=?".($f_site?" AND site_id=$f_site":""),[$f_date]),
    'points_valides'=> (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND statut='valide'".($f_site?" AND site_id=$f_site":""),[$f_date]),
    'points_brouillon'=>(int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND statut='en_attente_validation'".($f_site?" AND site_id=$f_site":""),[$f_date]),
    'in_use_emuci'  => (int)db_fetch_value("SELECT COUNT(*) FROM import_optoplate WHERE date_import=? AND statut_plaque='in_use'".($f_site?" AND site_id=$f_site":""),[$f_date]),
    'ecart_jour'    => 0,
];
$kpi['ecart_jour'] = $kpi['in_use_emuci'] - $kpi['plaques_jour'];

include __DIR__ . '/../templates/header.php';
?>
<style>
.vue-tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:24px}
.vue-tab{padding:12px 28px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;color:var(--muted);border-bottom:3px solid transparent;margin-bottom:-2px;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;}
.vue-tab.active{color:var(--primary,var(--blue));border-bottom-color:var(--primary,var(--blue))}
.kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px}
.kk{background:white;border:1px solid var(--border);border-radius:14px;padding:16px 18px;border-left:4px solid var(--blue)}
.kk.green{border-left-color:var(--success)} .kk.orange{border-left-color:#f39c12} .kk.red{border-left-color:var(--danger)} .kk.purple{border-left-color:#8e44ad}
.kk-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;color:var(--navy);line-height:1}
.kk-lbl{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.statut-pj{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.s-valide{background:#d1fae5;color:#065f46} .s-brouillon{background:#fef3c7;color:#92400e} .s-refuse{background:#fee2e2;color:#991b1b} .s-en_attente_validation{background:#dbeafe;color:#1d4ed8}
.fsel{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none;cursor:pointer}
</style>

<!-- FILTRES -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px">
  <div class="vue-tabs" style="margin-bottom:0;border:none">
    <a href="?vue=journalier&date=<?= h($f_date) ?>&site=<?= $f_site ?>" class="vue-tab <?= $f_vue==='journalier'?'active':'' ?>"><i class="ph ph-calendar" aria-hidden="true"></i> Journalier</a>
    <a href="?vue=mensuel&mois=<?= h($f_mois) ?>&site=<?= $f_site ?>" class="vue-tab <?= $f_vue==='mensuel'?'active':'' ?>"><i class="ph ph-calendar-blank" aria-hidden="true"></i> Mensuel</a>
    <a href="?vue=annuel&annee=<?= $f_annee ?>&site=<?= $f_site ?>" class="vue-tab <?= $f_vue==='annuel'?'active':'' ?>"><i class="ph ph-chart-bar" aria-hidden="true"></i> Annuel</a>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <select class="fsel" aria-label="Filtrer par site" onchange="location.href=updateParam('site',this.value)">
      <option value="0">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if($f_vue==='journalier'): ?>
    <input type="date" value="<?= h($f_date) ?>" onchange="location.href=updateParam('date',this.value)" aria-label="Choisir la date"
           style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
    <a href="?vue=journalier&date=<?= h($f_date) ?>&site=<?= $f_site ?>&export=journalier" class="btn btn-secondary btn-sm"><i class="ph ph-download-simple" aria-hidden="true"></i> Excel</a>
    <?php elseif($f_vue==='mensuel'): ?>
    <input type="month" value="<?= h($f_mois) ?>" onchange="location.href=updateParam('mois',this.value)" aria-label="Choisir le mois"
           style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
    <a href="?vue=mensuel&mois=<?= h($f_mois) ?>&site=<?= $f_site ?>&export=mensuel" class="btn btn-secondary btn-sm"><i class="ph ph-download-simple" aria-hidden="true"></i> Excel</a>
    <?php else: ?>
    <select class="fsel" aria-label="Filtrer par année" onchange="location.href=updateParam('annee',this.value)">
      <?php for($y=date('Y');$y>=2023;$y--): ?>
      <option value="<?= $y ?>" <?= $f_annee==$y?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <?php endif; ?>
  </div>
</div>

<!-- KPIs JOUR -->
<div class="kpi-row">
  <div class="kk green">
    <div class="kk-val" style="color:var(--success-d)"><?= fmt_number($kpi['plaques_jour']) ?></div>
    <div class="kk-lbl">Plaques posées (PJ)</div>
  </div>
  <div class="kk">
    <div class="kk-val"><?= fmt_number($kpi['engins_jour']) ?></div>
    <div class="kk-lbl">Engins traités</div>
  </div>
  <div class="kk purple">
    <div class="kk-val" style="color:#8e44ad"><?= fmt_number($kpi['in_use_emuci']) ?></div>
    <div class="kk-lbl">In Use EMUCI</div>
  </div>
  <div class="kk <?= $kpi['ecart_jour']!=0?'red':'green' ?>">
    <div class="kk-val" style="color:<?= $kpi['ecart_jour']!=0?'var(--danger-d)':'var(--success-d)' ?>"><?= $kpi['ecart_jour']>0?'+':'' ?><?= $kpi['ecart_jour'] ?></div>
    <div class="kk-lbl">Écart EMUCI vs PJ</div>
  </div>
  <div class="kk orange">
    <div class="kk-val" style="color:#f39c12"><?= $kpi['points_brouillon'] ?></div>
    <div class="kk-lbl">Points en attente validation</div>
  </div>
</div>

<?php if($f_vue === 'journalier'): ?>
<!-- ═══ VUE JOURNALIÈRE ═══ -->
<div class="card">
  <div class="card-header">
    <h3><i class="ph ph-calendar" aria-hidden="true"></i> Points journaliers du <?= fmt_date($f_date,'d/m/Y') ?></h3>
    <span style="font-size:13px;color:var(--muted)"><?= count($pj_jour) ?> point(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Site</th><th>Type</th>
        <th style="text-align:center">VP</th>
        <th style="text-align:center">Camion</th>
        <th style="text-align:center">Semi</th>
        <th style="text-align:center">Moto</th>
        <th style="text-align:center">Total engins</th>
        <th style="text-align:center">Plaques</th>
        <th style="text-align:center">Moy. V/H</th>
        <th style="text-align:center">Rivets</th>
        <th>Statut</th>
        <th>Agent</th>
        <th style="text-align:center">Actions</th>
      </tr></thead>
      <tbody>
      <?php if(empty($pj_jour)): ?>
        <tr><td colspan="13" style="text-align:center;padding:40px;color:var(--muted)">Aucun point journalier pour cette date.</td></tr>
      <?php else: foreach($pj_jour as $p): ?>
        <tr>
          <td style="font-weight:600;color:var(--navy)"><?= h($p['site_nom']) ?></td>
          <td><span style="padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;background:<?= $p['type_point']==='final'?'#dbeafe':'#fef3c7' ?>;color:<?= $p['type_point']==='final'?'#1d4ed8':'#92400e' ?>"><?= ucfirst($p['type_point']) ?></span></td>
          <td style="text-align:center"><?= $p['nb_vp']??0 ?></td>
          <td style="text-align:center"><?= $p['nb_camion']??0 ?></td>
          <td style="text-align:center"><?= $p['nb_semi']??0 ?></td>
          <td style="text-align:center"><?= $p['nb_moto']??0 ?></td>
          <td style="text-align:center;font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:16px"><?= $p['total_engins']??0 ?></td>
          <td style="text-align:center;font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:16px;color:var(--blue)"><?= $p['total_plaques']??0 ?></td>
          <td style="text-align:center"><?= number_format($p['moyenne_prod']??0,1) ?> V/H</td>
          <td style="text-align:center"><?= $p['rivets_utilises']??0 ?> <i class="ph ph-wrench" aria-hidden="true"></i></td>
          <td><span class="statut-pj s-<?= $p['statut'] ?>"><?= ['valide'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Validé','brouillon'=>'⏳ Brouillon','en_attente_validation'=>'<i class="ph ph-upload-simple" aria-hidden="true"></i> En attente validation','refuse'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Refusé'][$p['statut']] ?? $p['statut'] ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= h($p['agent_nom']??'—') ?></td>
          <td style="text-align:center;white-space:nowrap">
            <a href="<?= APP_URL ?>/pages/operations/point_journalier.php?view=<?= $p['id'] ?>" class="btn btn-secondary btn-sm"><i class="ph ph-eye" aria-hidden="true"></i></a>
            <?php if($p['statut']==='en_attente_validation' && in_array($role_slug,['superviseur_operation','admin','superadmin'])): ?>
            <button class="btn btn-success btn-sm" onclick="validerPJ(<?= $p['id'] ?>)"><i class="ph ph-check-circle" aria-hidden="true"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif($f_vue === 'mensuel'): ?>
<!-- ═══ VUE MENSUELLE ═══ -->
<?php if(!empty($graph_mois)): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><h3><i class="ph ph-chart-line-up" aria-hidden="true"></i> Évolution des plaques posées — <?= h($f_mois) ?></h3></div>
  <div class="card-body"><canvas id="chartMois" height="100"></canvas></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h3><i class="ph ph-calendar-blank" aria-hidden="true"></i> Résumé mensuel — <?= h($f_mois) ?></h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Site</th>
        <th style="text-align:center">Points</th>
        <th style="text-align:center">Validés</th>
        <th style="text-align:center">Total plaques</th>
        <th style="text-align:center">Total engins</th>
        <th style="text-align:center">Rivets utilisés</th>
        <th style="text-align:center">Heures travail</th>
        <th style="text-align:center">Moy. V/H</th>
      </tr></thead>
      <tbody>
      <?php if(empty($pj_mois)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucune donnée pour ce mois.</td></tr>
      <?php else:
        $tot = ['nb'=>0,'valides'=>0,'plaques'=>0,'engins'=>0,'rivets'=>0,'heures'=>0];
        foreach($pj_mois as $p):
          $tot['nb']+=$p['nb_points']; $tot['valides']+=$p['points_valides'];
          $tot['plaques']+=$p['total_plaques']; $tot['engins']+=$p['total_engins'];
          $tot['rivets']+=$p['rivets_utilises']; $tot['heures']+=$p['heures_total'];
      ?>
        <tr>
          <td style="font-weight:600;color:var(--navy)"><?= h($p['site_nom']) ?></td>
          <td style="text-align:center"><?= $p['nb_points'] ?></td>
          <td style="text-align:center">
            <span style="color:<?= $p['points_valides']>=$p['nb_points']?'var(--success-d)':'#f39c12' ?>;font-weight:700"><?= $p['points_valides'] ?></span>
            / <?= $p['nb_points'] ?>
          </td>
          <td style="text-align:center;font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:16px;color:var(--blue)"><?= fmt_number($p['total_plaques']) ?></td>
          <td style="text-align:center;font-weight:700"><?= fmt_number($p['total_engins']) ?></td>
          <td style="text-align:center"><?= fmt_number($p['rivets_utilises']) ?> <i class="ph ph-wrench" aria-hidden="true"></i></td>
          <td style="text-align:center"><?= fmt_number($p['heures_total']) ?>h</td>
          <td style="text-align:center"><?= number_format($p['moy_vh_avg']??0,1) ?> V/H</td>
        </tr>
      <?php endforeach; ?>
        <!-- Totaux -->
        <tr style="background:var(--tertiary,#f0f4ff);font-weight:700;border-top:2px solid var(--border)">
          <td style="font-weight:800;color:var(--navy)">TOTAL</td>
          <td style="text-align:center"><?= $tot['nb'] ?></td>
          <td style="text-align:center"><?= $tot['valides'] ?> / <?= $tot['nb'] ?></td>
          <td style="text-align:center;font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;color:var(--blue)"><?= fmt_number($tot['plaques']) ?></td>
          <td style="text-align:center"><?= fmt_number($tot['engins']) ?></td>
          <td style="text-align:center"><?= fmt_number($tot['rivets']) ?></td>
          <td style="text-align:center"><?= fmt_number($tot['heures']) ?>h</td>
          <td style="text-align:center">—</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif($f_vue === 'annuel'): ?>
<!-- ═══ VUE ANNUELLE ═══ -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><h3><i class="ph ph-chart-bar" aria-hidden="true"></i> Évolution annuelle <?= $f_annee ?></h3></div>
  <div class="card-body"><canvas id="chartAnnee" height="120"></canvas></div>
</div>

<div class="card">
  <div class="card-header"><h3><i class="ph ph-chart-bar" aria-hidden="true"></i> Résumé annuel <?= $f_annee ?></h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Mois</th>
        <th style="text-align:center">Points</th>
        <th style="text-align:center">Total plaques</th>
        <th style="text-align:center">Total engins</th>
        <th style="text-align:center">Rivets utilisés</th>
      </tr></thead>
      <tbody>
      <?php if(empty($pj_annee)): ?>
        <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--muted)">Aucune donnée pour <?= $f_annee ?>.</td></tr>
      <?php else:
        $tot_a = ['nb'=>0,'plaques'=>0,'engins'=>0,'rivets'=>0];
        foreach($pj_annee as $p):
          $tot_a['nb']+=$p['nb_points']; $tot_a['plaques']+=$p['total_plaques'];
          $tot_a['engins']+=$p['total_engins']; $tot_a['rivets']+=$p['rivets_utilises'];
      ?>
        <tr>
          <td style="font-weight:600"><?= date('F Y', strtotime($p['mois'].'-01')) ?></td>
          <td style="text-align:center"><?= $p['nb_points'] ?></td>
          <td style="text-align:center;font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:16px;color:var(--blue)"><?= fmt_number($p['total_plaques']) ?></td>
          <td style="text-align:center;font-weight:700"><?= fmt_number($p['total_engins']) ?></td>
          <td style="text-align:center"><?= fmt_number($p['rivets_utilises']) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr style="background:var(--tertiary,#f0f4ff);font-weight:700;border-top:2px solid var(--border)">
          <td style="font-weight:800">TOTAL <?= $f_annee ?></td>
          <td style="text-align:center"><?= $tot_a['nb'] ?></td>
          <td style="text-align:center;font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;color:var(--blue)"><?= fmt_number($tot_a['plaques']) ?></td>
          <td style="text-align:center"><?= fmt_number($tot_a['engins']) ?></td>
          <td style="text-align:center"><?= fmt_number($tot_a['rivets']) ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
function updateParam(key, val) {
  const u = new URL(window.location.href);
  u.searchParams.set(key, val);
  return u.toString();
}

function validerPJ(id) {
  if(!confirm('Valider ce point journalier ?')) return;
  fetch('<?= APP_URL ?>/pages/operations/point_journalier.php', {
    method:'POST',
    headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'valider_point',point_id:id})
  }).then(r=>r.json()).then(d=>{
    if(d.success){alert(d.message);location.reload();}
    else alert('Erreur : '+d.message);
  });
}

<?php if($f_vue==='mensuel' && !empty($graph_mois)): ?>
const moisLabels = <?= json_encode(array_column($graph_mois,'date_point')) ?>;
const moisPlaques = <?= json_encode(array_map(fn($r)=>(int)$r['plaques'],$graph_mois)) ?>;
const moisEngins  = <?= json_encode(array_map(fn($r)=>(int)$r['engins'],$graph_mois)) ?>;
new Chart(document.getElementById('chartMois'),{
  type:'line',
  data:{labels:moisLabels,datasets:[
    {label:'Plaques posées',data:moisPlaques,borderColor:'#1b75bc',backgroundColor:'rgba(27,117,188,.08)',tension:.3,fill:true,pointRadius:4},
    {label:'Engins traités',data:moisEngins,borderColor:'#00aeef',backgroundColor:'rgba(0,174,239,.06)',tension:.3,fill:true,pointRadius:4}
  ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}}}
});
<?php endif; ?>

<?php if($f_vue==='annuel' && !empty($pj_annee)): ?>
const anneeLabels  = <?= json_encode(array_column($pj_annee,'mois')) ?>;
const anneePlaques = <?= json_encode(array_map(fn($r)=>(int)$r['total_plaques'],$pj_annee)) ?>;
new Chart(document.getElementById('chartAnnee'),{
  type:'bar',
  data:{labels:anneeLabels,datasets:[
    {label:'Plaques posées',data:anneePlaques,backgroundColor:'rgba(27,117,188,.7)',borderColor:'#1b75bc',borderRadius:6}
  ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}}}
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
