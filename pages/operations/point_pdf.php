<?php
// ============================================================
//  pages/operations/point_pdf.php — Génération PDF point journalier
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

require_auth();
$user = current_user();

$point_id = (int)($_GET['id'] ?? 0);
if (!$point_id) { http_response_code(400); exit('Identifiant manquant.'); }

$point = db_fetch_one(
    "SELECT p.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS agent
     FROM op_points_journaliers p
     JOIN sites s ON s.id=p.site_id
     LEFT JOIN users u ON u.id=p.created_by
     WHERE p.id=?",
    [$point_id]
);
if (!$point) { http_response_code(404); exit('Point introuvable.'); }

// Restriction brouillon : seul l'auteur peut consulter son brouillon
if ($point['statut'] === 'brouillon' && $point['created_by'] != $user['id']) {
    http_response_code(403); exit('Accès refusé.');
}

// n° 16 réunion ERP — l'observation est désormais une liste de lignes
// typées (JSON), avec repli sur l'ancien format texte libre pour les
// points saisis avant ce lot.
$OBS_TYPES = [
    'info'     => ['label'=>'Info',     'border'=>'#64748b', 'bg'=>'#f1f5f9', 'text'=>'#334155'],
    'alerte'   => ['label'=>'Alerte',   'border'=>'#d97706', 'bg'=>'#fef9e7', 'text'=>'#92400e'],
    'relance'  => ['label'=>'Relance',  'border'=>'#1565c0', 'bg'=>'#e3f2fd', 'text'=>'#1565c0'],
    'incident' => ['label'=>'Incident', 'border'=>'#c0392b', 'bg'=>'#fdecea', 'text'=>'#c0392b'],
    'urgence'  => ['label'=>'Urgence',  'border'=>'#991b1b', 'bg'=>'#fee2e2', 'text'=>'#991b1b'],
    'autre'    => ['label'=>'Autre',    'border'=>'#7c3aed', 'bg'=>'#f5f3ff', 'text'=>'#6d28d9'],
];
$observations_list = [];
if (!empty($point['observations'])) {
    $decoded = json_decode($point['observations'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $o) {
            if (!empty($o['texte'])) $observations_list[] = ['type'=>$o['type'] ?? 'info', 'texte'=>$o['texte']];
        }
    } else {
        $observations_list[] = ['type'=>'autre', 'texte'=>$point['observations']];
    }
}

$pmma  = db_fetch_all("SELECT type_pmma, utilises, endommages FROM op_pmma_utilises WHERE point_id=? ORDER BY type_pmma", [$point_id]);
$films = db_fetch_all(
    "SELECT b.numero AS bobine_num, b.type_code, fu.films_utilises, fu.films_endommages
     FROM op_films_utilises fu
     JOIN op_bobines b ON b.id=fu.bobine_id
     WHERE fu.point_id=? ORDER BY b.numero",
    [$point_id]
);

$type_lbl = [
    'point_9h'       => 'Point 9h',
    'point_13h'      => 'Point 13h',
    'point_18h'      => 'Point 18h — Fin de journée',
    'final'          => 'Point Final',
    'intermediaire'  => 'Point Intermédiaire',
];
$statut_lbl = [
    'brouillon'              => 'Brouillon',
    'en_attente_validation'  => 'En attente de validation',
    'valide'                 => 'Validé',
    'rejete'                 => 'Rejeté',
    'suivi'                  => 'Suivi',
];

$date_fr = date_create($point['date_point']);
$date_str = $date_fr ? strftime_fr($date_fr) : $point['date_point'];

function strftime_fr(DateTimeInterface $d): string {
    $jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
    $mois  = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    return $jours[(int)$d->format('w')] . ' ' . $d->format('d') . ' ' . $mois[(int)$d->format('n')] . ' ' . $d->format('Y');
}

// ─── HTML ────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; background: #fff; }

  .page { padding: 28px 32px; }

  /* En-tête */
  .header { background: #0a1628; color: white; border-radius: 8px; padding: 18px 22px; margin-bottom: 18px; }
  .header-top { display: table; width: 100%; margin-bottom: 10px; }
  .header-left  { display: table-cell; vertical-align: middle; }
  .header-right { display: table-cell; vertical-align: middle; text-align: right; }
  .site-name { font-size: 16px; font-weight: bold; }
  .type-lbl  { font-size: 11px; opacity: .7; margin-top: 2px; }
  .date-lbl  { font-size: 11px; opacity: .7; }
  .statut-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: bold; }
  .statut-valide   { background: #d1fae5; color: #065f46; }
  .statut-brouillon{ background: #fef3c7; color: #92400e; }
  .statut-attente  { background: #dbeafe; color: #1d4ed8; }
  .statut-rejete   { background: #fee2e2; color: #991b1b; }
  .statut-suivi    { background: #f1f5f9; color: #475569; }

  /* KPIs */
  .kpi-row { display: table; width: 100%; margin-bottom: 16px; }
  .kpi-cell { display: table-cell; text-align: center; padding: 10px 6px; background: #f0f4ff; border-radius: 8px; }
  .kpi-cell + .kpi-cell { margin-left: 8px; }
  .kpi-val { font-size: 22px; font-weight: bold; color: #0a1628; }
  .kpi-lbl { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }

  /* Sections */
  .section { margin-bottom: 14px; }
  .section-title { font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 8px; }

  /* Tableau véhicules */
  table.veh { width: 100%; border-collapse: collapse; }
  table.veh th { background: #f8fafc; font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; padding: 5px 8px; text-align: left; border: 1px solid #e2e8f0; }
  table.veh td { padding: 6px 8px; border: 1px solid #e2e8f0; font-size: 11px; }
  table.veh tr.total td { font-weight: bold; background: #eff6ff; color: #1e40af; }

  /* Rivets */
  .rivets-row { display: table; width: 100%; }
  .rivet-cell { display: table-cell; text-align: center; padding: 10px; border-radius: 8px; }
  .rivet-gonfl { background: #e3f2fd; }
  .rivet-eclate{ background: #fce4ec; }
  .rivet-endomm{ background: #fff3e0; }
  .rivet-val  { font-size: 20px; font-weight: bold; }
  .rivet-lbl  { font-size: 9px; text-transform: uppercase; margin-top: 2px; }

  /* PMMA */
  table.pmma { width: 100%; border-collapse: collapse; }
  table.pmma th { background: #f8fafc; font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; padding: 5px 8px; text-align: left; border: 1px solid #e2e8f0; }
  table.pmma td { padding: 6px 8px; border: 1px solid #e2e8f0; font-size: 11px; }

  /* Films */
  table.films { width: 100%; border-collapse: collapse; }
  table.films th { background: #f8fafc; font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; padding: 5px 8px; text-align: left; border: 1px solid #e2e8f0; }
  table.films td { padding: 6px 8px; border: 1px solid #e2e8f0; font-size: 11px; }

  /* Non posés */
  .np-box { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 10px 14px; display: table; width: 100%; }
  .np-cell { display: table-cell; }
  .np-val  { font-size: 18px; font-weight: bold; color: #c2410c; }
  .np-lbl  { font-size: 10px; color: #9a3412; }

  /* Observations */
  .obs-box { background: #f8fafc; border-left: 3px solid #94a3b8; border-radius: 4px; padding: 10px 12px; font-size: 11px; color: #334155; }

  /* Motif rejet */
  .rejet-box { background: #fee2e2; border-left: 3px solid #ef4444; border-radius: 4px; padding: 10px 12px; font-size: 11px; color: #7f1d1d; }

  /* Footer */
  .footer { margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 10px; display: table; width: 100%; font-size: 10px; color: #94a3b8; }
  .footer-left  { display: table-cell; }
  .footer-right { display: table-cell; text-align: right; }

  .gap { height: 8px; }
</style>
</head>
<body>
<div class="page">

  <!-- En-tête -->
  <div class="header">
    <div class="header-top">
      <div class="header-left">
        <?= pdf_logo_img('34px') ?>
        <div style="height:6px"></div>
        <div class="site-name"><?= h($point['site_nom']) ?></div>
        <div class="type-lbl"><?= h($type_lbl[$point['type_point']] ?? $point['type_point']) ?></div>
        <div class="date-lbl"><?= h($date_str) ?></div>
      </div>
      <div class="header-right">
        <?php
          $sc = match($point['statut']) {
            'valide'                => 'valide',
            'brouillon'             => 'brouillon',
            'en_attente_validation' => 'attente',
            'rejete'                => 'rejete',
            default                 => 'suivi',
          };
        ?>
        <span class="statut-badge statut-<?= $sc ?>"><?= h($statut_lbl[$point['statut']] ?? $point['statut']) ?></span>
      </div>
    </div>
    <!-- KPIs -->
    <table style="width:100%;background:rgba(255,255,255,.08);border-radius:6px">
      <tr>
        <td style="text-align:center;padding:8px;color:white">
          <div style="font-size:20px;font-weight:bold"><?= $point['total_engins'] ?></div>
          <div style="font-size:9px;opacity:.6;text-transform:uppercase">Engins</div>
        </td>
        <td style="text-align:center;padding:8px;color:white;border-left:1px solid rgba(255,255,255,.15)">
          <div style="font-size:20px;font-weight:bold"><?= $point['total_plaques'] ?></div>
          <div style="font-size:9px;opacity:.6;text-transform:uppercase">Plaques</div>
        </td>
        <td style="text-align:center;padding:8px;color:white;border-left:1px solid rgba(255,255,255,.15)">
          <div style="font-size:20px;font-weight:bold"><?= (int)$point['rivets_utilises'] ?></div>
          <div style="font-size:9px;opacity:.6;text-transform:uppercase">Rivets posés</div>
        </td>
        <td style="text-align:center;padding:8px;color:white;border-left:1px solid rgba(255,255,255,.15)">
          <div style="font-size:20px;font-weight:bold"><?= $point['moyenne_prod'] ?></div>
          <div style="font-size:9px;opacity:.6;text-transform:uppercase">V/H</div>
        </td>
        <td style="text-align:center;padding:8px;color:white;border-left:1px solid rgba(255,255,255,.15)">
          <div style="font-size:20px;font-weight:bold"><?= $point['nb_heures_travail'] ?: '—' ?>h</div>
          <div style="font-size:9px;opacity:.6;text-transform:uppercase">Heures</div>
        </td>
      </tr>
    </table>
  </div>

  <!-- Véhicules -->
  <div class="section">
    <div class="section-title">Répartition des véhicules</div>
    <table class="veh">
      <thead>
        <tr>
          <th>Type</th>
          <th style="text-align:center">Nombre</th>
          <th style="text-align:center">Plaques</th>
        </tr>
      </thead>
      <tbody>
        <?php if($point['nb_vp']>0): ?>
        <tr><td>🚗 Véhicule Particulier</td><td style="text-align:center"><?= $point['nb_vp'] ?></td><td style="text-align:center"><?= $point['nb_vp']*2 ?></td></tr>
        <?php endif; ?>
        <?php if($point['nb_camion']>0): ?>
        <tr><td>🚛 Camion</td><td style="text-align:center"><?= $point['nb_camion'] ?></td><td style="text-align:center"><?= $point['nb_camion']*2 ?></td></tr>
        <?php endif; ?>
        <?php if($point['nb_semi']>0): ?>
        <tr><td>🚚 Semi-remorque</td><td style="text-align:center"><?= $point['nb_semi'] ?></td><td style="text-align:center"><?= $point['nb_semi'] ?></td></tr>
        <?php endif; ?>
        <?php if($point['nb_moto']>0): ?>
        <tr><td>🏍️ Moto</td><td style="text-align:center"><?= $point['nb_moto'] ?></td><td style="text-align:center"><?= $point['nb_moto'] ?></td></tr>
        <?php endif; ?>
        <tr class="total">
          <td>TOTAL</td>
          <td style="text-align:center"><?= $point['total_engins'] ?></td>
          <td style="text-align:center"><?= $point['total_plaques'] ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Rivets -->
  <?php
  $gonfl  = (int)($point['rivets_gonflables'] ?? 0);
  $eclate = (int)($point['rivets_eclates']    ?? 0);
  $endomm = (int)($point['rivets_endommages'] ?? 0);
  if ($gonfl + $eclate + $endomm > 0):
  ?>
  <div class="section">
    <div class="section-title">Rivets</div>
    <table style="width:100%">
      <tr>
        <?php if($gonfl>0): ?>
        <td style="text-align:center;padding:8px;background:#e3f2fd;border-radius:6px">
          <div style="font-size:18px;font-weight:bold;color:#1565c0"><?= $gonfl ?></div>
          <div style="font-size:9px;color:#1565c0;text-transform:uppercase;margin-top:2px">Gonflables</div>
        </td>
        <?php endif; ?>
        <?php if($eclate>0): ?>
        <td style="text-align:center;padding:8px;background:#fce4ec;border-radius:6px<?= $gonfl>0 ? ';padding-left:8px' : '' ?>">
          <div style="font-size:18px;font-weight:bold;color:#880e4f"><?= $eclate ?></div>
          <div style="font-size:9px;color:#880e4f;text-transform:uppercase;margin-top:2px">Éclatés</div>
        </td>
        <?php endif; ?>
        <?php if($endomm>0): ?>
        <td style="text-align:center;padding:8px;background:#fff3e0;border-radius:6px">
          <div style="font-size:18px;font-weight:bold;color:#e65100"><?= $endomm ?></div>
          <div style="font-size:9px;color:#e65100;text-transform:uppercase;margin-top:2px">Endommagés</div>
        </td>
        <?php endif; ?>
      </tr>
    </table>
  </div>
  <?php endif; ?>

  <!-- PMMA -->
  <?php if(!empty($pmma)): ?>
  <div class="section">
    <div class="section-title">PMMA consommé</div>
    <table class="pmma">
      <thead>
        <tr>
          <th>Type PMMA</th>
          <th style="text-align:center">Utilisés</th>
          <th style="text-align:center">Endommagés</th>
          <th style="text-align:center">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($pmma as $pm): ?>
        <tr>
          <td><?= h($pm['type_pmma']) ?></td>
          <td style="text-align:center;color:#1565c0;font-weight:bold"><?= (int)$pm['utilises'] ?></td>
          <td style="text-align:center;color:#e65100;font-weight:bold"><?= (int)$pm['endommages'] ?></td>
          <td style="text-align:center;font-weight:bold"><?= (int)$pm['utilises']+(int)$pm['endommages'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Films -->
  <?php if(!empty($films)): ?>
  <div class="section">
    <div class="section-title">Films utilisés par bobine</div>
    <table class="films">
      <thead>
        <tr>
          <th>Bobine</th>
          <th>Code</th>
          <th style="text-align:center">Films utilisés</th>
          <th style="text-align:center">Films endommagés</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($films as $f): ?>
        <tr>
          <td style="font-weight:bold;color:#1565c0">#<?= h($f['bobine_num']) ?></td>
          <td><?= h($f['type_code'] ?? '—') ?></td>
          <td style="text-align:center;font-weight:bold"><?= (int)$f['films_utilises'] ?></td>
          <td style="text-align:center;color:<?= (int)$f['films_endommages']>0?'#e65100':'#94a3b8' ?>;font-weight:bold"><?= (int)$f['films_endommages'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Véhicules non posés -->
  <?php
  $np_conc = (int)($point['non_poses_concessionnaires'] ?? 0);
  $np_usag = (int)($point['non_poses_usagers'] ?? 0);
  if ($np_conc + $np_usag > 0):
  ?>
  <div class="section">
    <div class="section-title">Véhicules non posés</div>
    <div class="np-box">
      <table style="width:100%"><tr>
        <?php if($np_conc>0): ?>
        <td style="padding:4px 12px">
          <div class="np-val"><?= $np_conc ?></div>
          <div class="np-lbl">Concessionnaires</div>
        </td>
        <?php endif; ?>
        <?php if($np_usag>0): ?>
        <td style="padding:4px 12px">
          <div class="np-val"><?= $np_usag ?></div>
          <div class="np-lbl">Usagers</div>
        </td>
        <?php endif; ?>
      </tr></table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Observations -->
  <?php if(!empty($observations_list)): ?>
  <div class="section">
    <div class="section-title">Observations</div>
    <?php foreach ($observations_list as $o): $cfg = $OBS_TYPES[$o['type']] ?? $OBS_TYPES['info']; ?>
    <div class="obs-box" style="background:<?= $cfg['bg'] ?>;border-left-color:<?= $cfg['border'] ?>;margin-bottom:6px">
      <div style="font-size:9px;font-weight:bold;color:<?= $cfg['text'] ?>;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px"><?= h($cfg['label']) ?></div>
      <?= h($o['texte']) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Motif rejet -->
  <?php if(!empty($point['motif_rejet'])): ?>
  <div class="section">
    <div class="section-title">Motif de rejet</div>
    <div class="rejet-box"><?= h($point['motif_rejet']) ?></div>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="footer">
    <div class="footer-left">Enregistré par <strong><?= h($point['agent'] ?? '—') ?></strong></div>
    <div class="footer-right">Généré le <?= date('d/m/Y à H:i') ?> · ERP EMUCI</div>
  </div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$site  = preg_replace('/[^a-z0-9]/i', '_', $point['site_nom'] ?? 'site');
$date  = str_replace('-', '', $point['date_point'] ?? date('Ymd'));
$fname = "point_{$site}_{$date}.pdf";

$dompdf->stream($fname, ['Attachment' => true]);
