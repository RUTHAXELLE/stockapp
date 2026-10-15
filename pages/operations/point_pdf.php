<?php
// ============================================================
//  pages/operations/point_pdf.php — Génération PDF point journalier
// ============================================================
//  Refonte du 15/10/2026 : le PDF est envoyé à la direction et lu sur
//  écran. Il répond d'abord à « combien d'engins posés », puis à « y a-t-il
//  un problème » — un bloc « À signaler » qui ne liste que ce qui n'est pas
//  nul —, et le détail (véhicules, consommables, déclarations) suit.
//  Gabarit Dompdf : mise en page en tableaux, police DejaVu Sans, aucun
//  emoji (la police ne les a pas : ils sortaient en carrés vides).
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

require_auth();
$user = current_user();

$point_id = (int)($_GET['id'] ?? 0);
if (!$point_id) { http_response_code(400); exit('Identifiant manquant.'); }

$sites_operation_ok = db_colonne_existe('sites', 'operation');
$point = db_fetch_one(
    "SELECT p.*, s.nom AS site_nom, " . ($sites_operation_ok ? "s.operation AS site_operation," : "'primo' AS site_operation,") . "
            CONCAT(u.prenom,' ',u.nom) AS agent,
            CONCAT(v.prenom,' ',v.nom) AS valideur
     FROM op_points_journaliers p
     JOIN sites s ON s.id=p.site_id
     LEFT JOIN users u ON u.id=p.created_by
     LEFT JOIN users v ON v.id=p.validated_by
     WHERE p.id=?",
    [$point_id]
);
if (!$point) { http_response_code(404); exit('Point introuvable.'); }

// Restriction brouillon : seul l'auteur peut consulter son brouillon
if ($point['statut'] === 'brouillon' && $point['created_by'] != $user['id']) {
    http_response_code(403); exit('Accès refusé.');
}

// n° 16 réunion ERP — l'observation est une liste de lignes typées (JSON),
// avec repli sur l'ancien format texte libre pour les points plus anciens.
$OBS_TYPES = [
    'info'     => ['label'=>'Info',     'bg'=>'#F1F5F9', 'bord'=>'#CBD5E1', 'texte'=>'#334155'],
    'alerte'   => ['label'=>'Alerte',   'bg'=>'#FEF9E7', 'bord'=>'#FCD34D', 'texte'=>'#8A5A00'],
    'relance'  => ['label'=>'Relance',  'bg'=>'#E3F2FD', 'bord'=>'#90CAF9', 'texte'=>'#1565C0'],
    'incident' => ['label'=>'Incident', 'bg'=>'#FDECEA', 'bord'=>'#F5B7B1', 'texte'=>'#B03A2E'],
    'urgence'  => ['label'=>'Urgence',  'bg'=>'#FEE2E2', 'bord'=>'#FCA5A5', 'texte'=>'#991B1B'],
    'autre'    => ['label'=>'Autre',    'bg'=>'#F5F3FF', 'bord'=>'#C4B5FD', 'texte'=>'#6D28D9'],
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

$pmma  = db_fetch_all("SELECT * FROM op_pmma_utilises WHERE point_id=? ORDER BY type_pmma", [$point_id]);
$films = db_fetch_all(
    "SELECT fu.*, b.numero AS bobine_num, b.type_code
     FROM op_films_utilises fu
     JOIN op_bobines b ON b.id=fu.bobine_id
     WHERE fu.point_id=? ORDER BY b.numero",
    [$point_id]
);
// Déclarations d'endommagement (une ligne par unité) — colonnes objet et
// numéro de consommable ajoutées par migration : repli si absentes.
$endo_objet_ok = db_colonne_existe('op_endommagements', 'objet');
$endo_conso_ok = db_colonne_existe('op_endommagements', 'numero_consommable');
$endommagements = db_fetch_all(
    "SELECT e.film_no, e.personne, e.etape, e.cause, b.numero AS bobine_num"
    . ($endo_objet_ok ? ", e.objet, e.sous_type" : ", 'film' AS objet, NULL AS sous_type")
    . ($endo_conso_ok ? ", e.numero_consommable" : ", NULL AS numero_consommable")
    . " FROM op_endommagements e
        LEFT JOIN op_bobines b ON b.id = e.bobine_id
        WHERE e.point_id=? ORDER BY e.id",
    [$point_id]
);

$type_lbl = [
    'point_9h'       => 'Point 9h',
    'point_13h'      => 'Point 13h',
    'point_18h'      => 'Point 18h, fin de journée',
    'final'          => 'Point final',
    'intermediaire'  => 'Point intermédiaire',
];
$statut_lbl = [
    'brouillon'              => 'Brouillon',
    'en_attente_validation'  => 'En attente de validation',
    'valide'                 => 'Validé',
    'rejete'                 => 'Rejeté',
    'suivi'                  => 'Suivi',
];
$statut_cls = [
    'valide' => 'st-valide', 'brouillon' => 'st-brouillon', 'en_attente_validation' => 'st-attente',
    'rejete' => 'st-rejete',
];
$ETAPES = [
    'verification' => 'Vérification', 'impression' => 'Impression', 'lamination' => 'Lamination',
    'percee' => 'Percée', 'fissuree' => 'Plaque fissurée', 'transport' => 'Transport',
    'stockage' => 'Stockage', 'autre' => 'Autre', 'pose' => 'Pose (ancien)',
];

function strftime_fr(DateTimeInterface $d): string {
    $jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
    $mois  = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    return $jours[(int)$d->format('w')] . ' ' . $d->format('j') . ' ' . $mois[(int)$d->format('n')] . ' ' . $d->format('Y');
}
// Nombre décimal à la française, sans zéros inutiles : 10.60 → « 10,6 ».
function pdf_dec($v): string {
    $s = number_format((float)$v, 2, ',', ' ');
    return rtrim(rtrim($s, '0'), ',');
}
// Écart signé : +1, -2.
function pdf_signe(int $e): string { return ($e > 0 ? '+' : '') . $e; }
// Cellule numérique : zéro discret, valeur en alerte colorée si demandé.
function pdf_num(int $n, string $cls_si_positif = ''): string {
    if ($n === 0) return '<span class="zero">–</span>';
    return $cls_si_positif ? "<span class=\"$cls_si_positif\">$n</span>" : (string)$n;
}
$date_fr  = date_create($point['date_point']);
$date_str = $date_fr ? strftime_fr($date_fr) : $point['date_point'];

// ── Production : engins posés = section principale + Duplicatas + Post-Immat
$est_reimmat_site = ($point['site_operation'] ?? 'primo') === 'reimmat_post';
$sections = [
    'base' => ['libelle' => $est_reimmat_site ? 'Ré-immat' : 'Primo',
               'vp' => (int)$point['nb_vp'], 'cam' => (int)$point['nb_camion'],
               'semi' => (int)$point['nb_semi'], 'moto' => (int)$point['nb_moto'],
               'engins' => (int)$point['total_engins'], 'plaques' => (int)$point['total_plaques']],
    'dup'  => ['libelle' => 'Duplicata',
               'vp' => (int)($point['nb_vp_duplicata'] ?? 0), 'cam' => (int)($point['nb_camion_duplicata'] ?? 0),
               'semi' => (int)($point['nb_semi_duplicata'] ?? 0), 'moto' => (int)($point['nb_moto_duplicata'] ?? 0),
               'engins' => (int)($point['total_engins_duplicata'] ?? 0), 'plaques' => (int)($point['total_plaques_duplicata'] ?? 0)],
    'pi'   => ['libelle' => 'Post-immat',
               'vp' => (int)($point['nb_vp_postimmat'] ?? 0), 'cam' => (int)($point['nb_camion_postimmat'] ?? 0),
               'semi' => (int)($point['nb_semi_postimmat'] ?? 0), 'moto' => (int)($point['nb_moto_postimmat'] ?? 0),
               'engins' => (int)($point['total_engins_postimmat'] ?? 0), 'plaques' => (int)($point['total_plaques_postimmat'] ?? 0)],
];
$par_operation = $est_reimmat_site || $sections['dup']['engins'] + $sections['pi']['engins'] > 0;
$eng_total = $sections['base']['engins'] + $sections['dup']['engins'] + $sections['pi']['engins'];
$plq_total = $sections['base']['plaques'] + $sections['dup']['plaques'] + $sections['pi']['plaques'];
$TYPES_VEH = ['vp' => 'Véhicule particulier', 'cam' => 'Camion', 'semi' => 'Semi-remorque', 'moto' => 'Moto'];
$PLAQUES_PAR = ['vp' => 2, 'cam' => 2, 'semi' => 1, 'moto' => 1];

// ── Rivets
$gonfl  = (int)($point['rivets_gonflables'] ?? 0);
$eclate = (int)($point['rivets_eclates']    ?? 0);
$endomm = (int)($point['rivets_endommages'] ?? 0);
$g_end  = (int)($point['rivets_gonflables_end'] ?? 0);
$e_end  = (int)($point['rivets_eclates_end']    ?? 0);
$rivets_detail_connu = $g_end + $e_end === $endomm;   // anciens points : total seul

// ── Totaux films / PMMA
$films_util = array_sum(array_map(fn($f) => (int)$f['films_utilises'], $films));
$films_endo = array_sum(array_map(fn($f) => (int)$f['films_endommages'], $films));
$films_nc   = array_sum(array_map(fn($f) => (int)($f['non_conformes'] ?? 0), $films));
$pmma_util  = array_sum(array_map(fn($p) => (int)$p['utilises'], $pmma));
$pmma_endo  = array_sum(array_map(fn($p) => (int)$p['endommages'], $pmma));
$pmma_nc    = array_sum(array_map(fn($p) => (int)($p['non_conformes'] ?? 0), $pmma));

// ── « À signaler » : seulement ce qui n'est pas nul, du plus grave au moins grave.
//    Chaque ligne porte un mot (la couleur ne porte jamais seule le sens).
$alertes = [];
if ($point['statut'] === 'rejete') {
    $alertes[] = ['danger', 'Rejet', 'Point rejeté par le superviseur',
                  !empty($point['motif_rejet']) ? 'Motif : ' . $point['motif_rejet'] : ''];
}
$comp = !empty($point['ecart_optoplate']) ? json_decode($point['ecart_optoplate'], true) : null;
$justif = trim((string)($point['justification_superviseur'] ?? ''));
if (is_array($comp) && !empty($comp['has_ecart'])) {
    $lignes = [];
    foreach ($comp['primo'] ?? [] as $l) {
        if ((int)$l['ecart'] !== 0)
            $lignes[] = "{$l['libelle']} : {$l['coordinateur']} déclaré(s), {$l['optoplate']} sur OptoPlate (" . pdf_signe((int)$l['ecart']) . ")";
    }
    foreach (['duplicata' => 'Duplicatas', 'post_immat' => 'Post-immat'] as $k => $lb) {
        $l = $comp[$k] ?? null;
        if ($l && (int)$l['ecart'] !== 0)
            $lignes[] = "$lb : {$l['coordinateur']} déclaré(s), {$l['optoplate']} sur OptoPlate (" . pdf_signe((int)$l['ecart']) . ")";
    }
    foreach ($comp['endommages'] ?? [] as $l) {
        if ((int)$l['ecart'] !== 0)
            $lignes[] = 'Endommagés à l\'étape « ' . ($ETAPES[$l['etape']] ?? $l['etape']) . " » : {$l['coordinateur']} déclaré(s), {$l['optoplate']} sur OptoPlate (" . pdf_signe((int)$l['ecart']) . ")";
    }
    foreach ($comp['par_bobine'] ?? [] as $l) {
        if ((int)$l['ecart'] !== 0)
            $lignes[] = "Bobine {$l['numero']} : {$l['coordinateur']} film(s) déclaré(s), {$l['optoplate']} plaque(s) sur OptoPlate (" . pdf_signe((int)$l['ecart']) . ")";
    }
    $alertes[] = [$justif !== '' ? 'warning' : 'danger', 'Écart OptoPlate',
                  $justif !== '' ? 'Validé malgré un écart avec OptoPlate' : 'Écart avec OptoPlate',
                  implode("\n", array_merge($lignes, $justif !== '' ? ['Justification du superviseur : « ' . $justif . ' »'] : [])),];
} elseif ($justif !== '') {
    $alertes[] = ['warning', 'Écart OptoPlate', 'Validé malgré un écart avec OptoPlate',
                  'Justification du superviseur : « ' . $justif . ' »'];
}
// Endommagés et non conformes : une ligne par nature d'anomalie, tous
// consommables confondus, le détail par bobine / type dessous.
$pluriel = fn(int $n, string $mot) => "$n $mot" . ($n > 1 ? 's' : '');
$endo_parts = []; $endo_det = [];
if ($films_endo > 0) {
    $endo_parts[] = $pluriel($films_endo, 'film');
    $det = [];
    foreach ($films as $f) if ((int)$f['films_endommages'] > 0) $det[] = "bobine {$f['bobine_num']} : " . (int)$f['films_endommages'];
    $endo_det[] = 'Films : ' . implode(' · ', $det);
}
if ($pmma_endo > 0) {
    $endo_parts[] = "$pmma_endo PMMA";
    $det = [];
    foreach ($pmma as $p) if ((int)$p['endommages'] > 0) $det[] = "{$p['type_pmma']} : " . (int)$p['endommages'];
    $endo_det[] = 'PMMA : ' . implode(' · ', $det);
}
if ($endomm > 0) {
    $endo_parts[] = $pluriel($endomm, 'rivet');
    $endo_det[] = 'Rivets : ' . ($rivets_detail_connu
        ? implode(' · ', array_filter([$g_end ? $pluriel($g_end, 'gonflable') : '', $e_end ? $pluriel($e_end, 'éclaté') : '']))
        : 'type non précisé');
}
if ($endo_parts) {
    $alertes[] = ['warning', 'Endommagés', implode(', ', $endo_parts) . ' endommagé' . (count($endo_parts) > 1 || $films_endo + $pmma_endo + $endomm > 1 ? 's' : ''),
                  implode("
", $endo_det)];
}
$nc_parts = []; $nc_det = [];
if ($films_nc > 0) {
    $nc_parts[] = $pluriel($films_nc, 'film');
    $det = [];
    foreach ($films as $f) if ((int)($f['non_conformes'] ?? 0) > 0) $det[] = "bobine {$f['bobine_num']} : " . (int)$f['non_conformes'];
    $nc_det[] = 'Films : ' . implode(' · ', $det);
}
if ($pmma_nc > 0) {
    $nc_parts[] = "$pmma_nc PMMA";
    $det = [];
    foreach ($pmma as $p) if ((int)($p['non_conformes'] ?? 0) > 0) $det[] = "{$p['type_pmma']} : " . (int)$p['non_conformes'];
    $nc_det[] = 'PMMA : ' . implode(' · ', $det);
}
if ($nc_parts) {
    $alertes[] = ['warning', 'Non conformes', implode(', ', $nc_parts) . ' non conforme' . ($films_nc + $pmma_nc > 1 ? 's' : ''),
                  implode("
", $nc_det)];
}
$np_conc = (int)($point['non_poses_concessionnaires'] ?? 0);
$np_usag = (int)($point['non_poses_usagers'] ?? 0);
if ($np_conc + $np_usag > 0) {
    $np = $np_conc + $np_usag;
    $alertes[] = ['warning', 'Non posés', "$np véhicule" . ($np > 1 ? 's' : '') . ' non posé' . ($np > 1 ? 's' : ''),
                  implode(' · ', array_filter([$np_conc ? "$np_conc concessionnaire" . ($np_conc > 1 ? 's' : '') : '',
                                               $np_usag ? "$np_usag usager" . ($np_usag > 1 ? 's' : '') : '']))];
}

// ── Ligne d'état de l'en-tête
$quand = !empty($point['validated_at']) ? date('d/m/Y à H:i', strtotime($point['validated_at'])) : '';
$etat_ligne = match ($point['statut']) {
    'valide'                => 'Validé' . ($point['valideur'] ? ' par ' . $point['valideur'] : '') . ($quand ? " le $quand" : ''),
    'rejete'                => 'Rejeté' . ($point['valideur'] ? ' par ' . $point['valideur'] : '') . ($quand ? " le $quand" : ''),
    'en_attente_validation' => 'En attente de la validation du superviseur',
    'brouillon'             => 'Brouillon, pas encore soumis',
    default                 => '',
};

// ─── HTML ────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 20px 24px 38px; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1E2B4A; background: #fff; }
  table { border-collapse: collapse; }

  /* En-tête */
  .entete { width:100%; background:#1E2B4A; color:#fff; }
  .entete td { padding: 12px 16px; vertical-align: middle; }
  .logo { background:#fff; padding:3px 5px; display:inline-block; margin-bottom:6px; }
  .site { font-size: 17px; font-weight: bold; color:#fff; }
  .sous-titre { font-size: 10.5px; color: #C7D0E6; margin-top: 3px; }
  .meta { font-size: 10px; color: #C7D0E6; margin-top: 3px; }
  .pastille { display:inline-block; padding:3px 10px; font-size:10px; font-weight:bold; }
  .st-valide   { background:#D1FAE5; color:#065F46; }
  .st-attente  { background:#DBEAFE; color:#1D4ED8; }
  .st-rejete   { background:#FEE2E2; color:#991B1B; }
  .st-brouillon{ background:#FEF3C7; color:#92400E; }
  .st-suivi    { background:#F1F5F9; color:#475569; }

  /* Production */
  .prod { width:100%; border:1px solid #E2E8F0; margin-top:10px; }
  .prod td { padding: 10px 12px; vertical-align: bottom; }
  .prod td.sep { border-left:1px solid #E2E8F0; }
  .grand { font-size: 32px; font-weight: bold; color:#1E2B4A; }
  .grand-lbl { font-size: 12px; font-weight: bold; color: #1E2B4A; }
  .repartition { font-size: 10.5px; color: #475569; margin-top: 5px; }
  .second { font-size: 18px; font-weight: bold; color:#1E2B4A; }
  .second-lbl { font-size: 9.5px; color: #475569; margin-top: 2px; }

  /* Titres */
  h2 { font-size: 12px; font-weight: bold; color: #1E2B4A; margin: 13px 0 5px; page-break-after: avoid; }
  h2 span { font-weight: normal; color: #475569; }

  /* À signaler */
  .signal { width:100%; border:1px solid #E2E8F0; }
  .signal td { padding: 6px 10px; border-top: 1px solid #EEF2F7; vertical-align: top; }
  .signal td.mot-col { width: 122px; }
  .mot { display:inline-block; padding:2px 8px; font-size:9.5px; font-weight:bold; white-space:nowrap; }
  .mot-danger  { background:#FEE2E2; color:#991B1B; }
  .mot-warning { background:#FEF3C7; color:#8A5A00; }
  .signal-texte { font-weight:bold; }
  .signal-detail { color:#475569; margin-top:2px; }
  .rien { border:1px solid #A7F3D0; background:#ECFDF5; color:#065F46; padding:9px 12px; font-weight:bold; }

  /* Tableaux de détail */
  table.grille { width:100%; }
  table.grille th { font-size:9.5px; font-weight:bold; color:#475569; text-align:right; padding:4px 8px; border-bottom:1px solid #CBD5E1; }
  table.grille td { padding:4px 8px; border-bottom:1px solid #EEF2F7; text-align:right; }
  table.grille .g { text-align:left; }
  table.grille tr.total td { font-weight:bold; border-top:1px solid #CBD5E1; border-bottom:none; }
  table.grille tr.sous td { color:#475569; border-bottom:none; }
  .zero { color:#94A3B8; }
  .alerte { color:#B45309; font-weight:bold; }
  .alerte-nc { color:#6D28D9; font-weight:bold; }
  .bobine { font-size:9.5px; }

  /* Observations */
  .obs { border:1px solid #CBD5E1; padding:6px 10px; margin-bottom:5px; page-break-inside: avoid; }
  .signal tr, table.grille tr { page-break-inside: avoid; }
  .obs-mot { font-size:9.5px; font-weight:bold; margin-right:6px; }
</style>
</head>
<body>

  <!-- En-tête : de quel point il s'agit, et où il en est -->
  <table class="entete">
    <tr>
      <td>
        <?php $logo = pdf_logo_img('26px'); if ($logo): ?><div class="logo"><?= $logo ?></div><?php endif; ?>
        <div class="site"><?= h($point['site_nom']) ?></div>
        <div class="sous-titre"><?= h($type_lbl[$point['type_point']] ?? $point['type_point']) ?> · <?= h($date_str) ?></div>
        <div class="meta">Saisi par <?= h($point['agent'] ?: '—') ?></div>
      </td>
      <td style="text-align:right">
        <span class="pastille <?= $statut_cls[$point['statut']] ?? 'st-suivi' ?>"><?= h($statut_lbl[$point['statut']] ?? $point['statut']) ?></span>
        <?php if ($etat_ligne): ?><div class="meta"><?= h($etat_ligne) ?></div><?php endif; ?>
      </td>
    </tr>
  </table>

  <!-- Production : un seul grand chiffre, les engins posés -->
  <table class="prod">
    <tr>
      <td style="width:48%">
        <span class="grand"><?= $eng_total ?></span>
        <span class="grand-lbl">engin<?= $eng_total > 1 ? 's' : '' ?> posé<?= $eng_total > 1 ? 's' : '' ?></span>
        <?php if ($par_operation): ?>
        <div class="repartition">
          <?= $sections['base']['engins'] ?> <?= $est_reimmat_site ? 'ré-immat' : 'primo' ?>
          · <?= $sections['dup']['engins'] ?> duplicata<?= $sections['dup']['engins'] > 1 ? 's' : '' ?>
          · <?= $sections['pi']['engins'] ?> post-immat
        </div>
        <?php endif; ?>
      </td>
      <td class="sep">
        <div class="second"><?= $plq_total ?></div>
        <div class="second-lbl">plaques</div>
      </td>
      <td class="sep">
        <div class="second"><?= pdf_dec($point['moyenne_prod']) ?></div>
        <div class="second-lbl">véhicules par heure</div>
      </td>
      <td class="sep">
        <div class="second"><?= $point['nb_heures_travail'] !== null && $point['nb_heures_travail'] !== '' ? pdf_dec($point['nb_heures_travail']) . ' h' : '—' ?></div>
        <div class="second-lbl">de travail</div>
      </td>
    </tr>
  </table>

  <!-- À signaler : seulement ce qui n'est pas nul -->
  <h2>À signaler<?php if ($alertes): ?> <span>(<?= count($alertes) ?>)</span><?php endif; ?></h2>
  <?php if ($alertes): ?>
  <table class="signal">
    <?php foreach ($alertes as $i => [$niveau, $mot, $texte, $detail]): ?>
    <tr>
      <td class="mot-col"<?= $i === 0 ? ' style="border-top:none"' : '' ?>><span class="mot mot-<?= $niveau ?>"><?= h($mot) ?></span></td>
      <td<?= $i === 0 ? ' style="border-top:none"' : '' ?>>
        <div class="signal-texte"><?= h($texte) ?></div>
        <?php if ($detail !== ''): ?><div class="signal-detail"><?= nl2br(h($detail)) ?></div><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="rien">Aucun écart, incident ni non-conformité déclaré.</div>
  <?php endif; ?>

  <!-- Véhicules -->
  <h2>Véhicules</h2>
  <table class="grille">
    <?php if ($par_operation): ?>
    <tr>
      <th class="g">Type</th>
      <th><?= h($sections['base']['libelle']) ?></th>
      <th>Duplicata</th>
      <th>Post-immat</th>
      <th>Total</th>
    </tr>
    <?php foreach ($TYPES_VEH as $k => $lbl):
        $tot = $sections['base'][$k] + $sections['dup'][$k] + $sections['pi'][$k];
        if ($tot === 0) continue; ?>
    <tr>
      <td class="g"><?= h($lbl) ?></td>
      <td><?= pdf_num($sections['base'][$k]) ?></td>
      <td><?= pdf_num($sections['dup'][$k]) ?></td>
      <td><?= pdf_num($sections['pi'][$k]) ?></td>
      <td><?= $tot ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total">
      <td class="g">Engins</td>
      <td><?= $sections['base']['engins'] ?></td>
      <td><?= $sections['dup']['engins'] ?></td>
      <td><?= $sections['pi']['engins'] ?></td>
      <td><?= $eng_total ?></td>
    </tr>
    <tr class="sous">
      <td class="g">Plaques</td>
      <td><?= $sections['base']['plaques'] ?></td>
      <td><?= $sections['dup']['plaques'] ?></td>
      <td><?= $sections['pi']['plaques'] ?></td>
      <td><?= $plq_total ?></td>
    </tr>
    <?php else: ?>
    <tr>
      <th class="g">Type</th>
      <th>Engins</th>
      <th>Plaques</th>
    </tr>
    <?php foreach ($TYPES_VEH as $k => $lbl):
        if ($sections['base'][$k] === 0) continue; ?>
    <tr>
      <td class="g"><?= h($lbl) ?></td>
      <td><?= $sections['base'][$k] ?></td>
      <td><?= $sections['base'][$k] * $PLAQUES_PAR[$k] ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total">
      <td class="g">Total</td>
      <td><?= $sections['base']['engins'] ?></td>
      <td><?= $sections['base']['plaques'] ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <!-- Consommables : films par bobine | PMMA et rivets -->
  <?php if ($films || $pmma || $gonfl + $eclate > 0): ?>
  <h2>Consommables</h2>
  <table style="width:100%">
    <tr>
      <td style="width:56%;vertical-align:top;padding-right:10px">
        <?php if ($films): ?>
        <table class="grille">
          <tr>
            <th class="g">Bobine</th>
            <th class="g">Code</th>
            <th>Films</th>
            <?php if ($films_endo): ?><th>Endom.</th><?php endif; ?>
            <?php if ($films_nc): ?><th>Non conf.</th><?php endif; ?>
          </tr>
          <?php foreach ($films as $f): ?>
          <tr>
            <td class="g bobine"><?= h($f['bobine_num']) ?></td>
            <td class="g"><?= h($f['type_code'] ?? '—') ?></td>
            <td><?= (int)$f['films_utilises'] ?></td>
            <?php if ($films_endo): ?><td><?= pdf_num((int)$f['films_endommages'], 'alerte') ?></td><?php endif; ?>
            <?php if ($films_nc): ?><td><?= pdf_num((int)($f['non_conformes'] ?? 0), 'alerte-nc') ?></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <tr class="total">
            <td class="g" colspan="2">Total films</td>
            <td><?= $films_util ?></td>
            <?php if ($films_endo): ?><td><span class="alerte"><?= $films_endo ?></span></td><?php endif; ?>
            <?php if ($films_nc): ?><td><span class="alerte-nc"><?= $films_nc ?></span></td><?php endif; ?>
          </tr>
        </table>
        <?php else: ?>
        <div class="zero">Aucun film déclaré.</div>
        <?php endif; ?>
      </td>
      <td style="width:44%;vertical-align:top;padding-left:10px">
        <?php if ($pmma): ?>
        <table class="grille">
          <tr>
            <th class="g">PMMA</th>
            <th>Utilisés</th>
            <?php if ($pmma_endo): ?><th>Endom.</th><?php endif; ?>
            <?php if ($pmma_nc): ?><th>Non conf.</th><?php endif; ?>
          </tr>
          <?php foreach ($pmma as $p): ?>
          <tr>
            <td class="g"><?= h($p['type_pmma']) ?></td>
            <td><?= pdf_num((int)$p['utilises']) ?></td>
            <?php if ($pmma_endo): ?><td><?= pdf_num((int)$p['endommages'], 'alerte') ?></td><?php endif; ?>
            <?php if ($pmma_nc): ?><td><?= pdf_num((int)($p['non_conformes'] ?? 0), 'alerte-nc') ?></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <tr class="total">
            <td class="g">Total</td>
            <td><?= $pmma_util ?></td>
            <?php if ($pmma_endo): ?><td><span class="alerte"><?= $pmma_endo ?></span></td><?php endif; ?>
            <?php if ($pmma_nc): ?><td><span class="alerte-nc"><?= $pmma_nc ?></span></td><?php endif; ?>
          </tr>
        </table>
        <?php endif; ?>
        <?php if ($gonfl + $eclate > 0): ?>
        <table class="grille" style="margin-top:<?= $pmma ? '12px' : '0' ?>">
          <tr>
            <th class="g">Rivets</th>
            <th>Sortis</th>
            <?php if ($endomm): ?><th>dont endom.</th><?php endif; ?>
          </tr>
          <tr>
            <td class="g">Gonflables</td>
            <td><?= pdf_num($gonfl) ?></td>
            <?php if ($endomm): ?><td><?= $rivets_detail_connu ? pdf_num($g_end, 'alerte') : '<span class="zero">?</span>' ?></td><?php endif; ?>
          </tr>
          <tr>
            <td class="g">Éclatés</td>
            <td><?= pdf_num($eclate) ?></td>
            <?php if ($endomm): ?><td><?= $rivets_detail_connu ? pdf_num($e_end, 'alerte') : '<span class="zero">?</span>' ?></td><?php endif; ?>
          </tr>
          <tr class="total">
            <td class="g">Total</td>
            <td><?= $gonfl + $eclate ?></td>
            <?php if ($endomm): ?><td><span class="alerte"><?= $endomm ?></span></td><?php endif; ?>
          </tr>
          <tr class="sous">
            <td class="g">Posés</td>
            <td><?= (int)$point['rivets_utilises'] ?></td>
            <?php if ($endomm): ?><td></td><?php endif; ?>
          </tr>
        </table>
        <?php endif; ?>
      </td>
    </tr>
  </table>
  <?php endif; ?>

  <!-- Endommagements déclarés (une ligne par unité) -->
  <?php if ($endommagements): ?>
  <h2>Endommagements déclarés <span>(<?= count($endommagements) ?>)</span></h2>
  <table class="grille">
    <tr>
      <th class="g">Élément</th>
      <th class="g">N° consommable</th>
      <th class="g">Étape</th>
      <th class="g">Cause</th>
      <th class="g">Personne</th>
    </tr>
    <?php foreach ($endommagements as $e):
        $objet = match ($e['objet'] ?? 'film') {
            'rivet' => 'Rivet ' . (['gonflable' => 'gonflable', 'eclate' => 'éclaté'][$e['sous_type'] ?? ''] ?? ($e['sous_type'] ?? '')),
            'pmma'  => 'PMMA ' . ($e['sous_type'] ?? ''),
            default => 'Film' . ($e['bobine_num'] ? ' · ' . $e['bobine_num'] : ''),
        }; ?>
    <tr>
      <td class="g"><?= h(trim($objet)) ?></td>
      <td class="g"><?= h($e['numero_consommable'] ?: (($e['objet'] ?? 'film') === 'film' && $e['film_no'] ? 'film n° ' . $e['film_no'] : '—')) ?></td>
      <td class="g"><?= h($ETAPES[$e['etape']] ?? ($e['etape'] ?: '—')) ?></td>
      <td class="g"><?= h($e['cause'] ?: '—') ?></td>
      <td class="g"><?= h($e['personne'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <!-- Observations -->
  <?php if ($observations_list): ?>
  <h2>Observations</h2>
  <?php foreach ($observations_list as $o): $cfg = $OBS_TYPES[$o['type']] ?? $OBS_TYPES['info']; ?>
  <div class="obs" style="background:<?= $cfg['bg'] ?>;border-color:<?= $cfg['bord'] ?>">
    <span class="obs-mot" style="color:<?= $cfg['texte'] ?>"><?= h($cfg['label']) ?></span><?= h($o['texte']) ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

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

// Pied de page sur chaque page : origine du document et pagination.
$canvas = $dompdf->getCanvas();
$police = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
$gris   = [0.39, 0.45, 0.55];
$canvas->page_text(26, $canvas->get_height() - 28,
    'ERP EMUCI · généré le ' . date('d/m/Y à H:i'), $police, 7.5, $gris);
$canvas->page_text($canvas->get_width() - 80, $canvas->get_height() - 28,
    'Page {PAGE_NUM} / {PAGE_COUNT}', $police, 7.5, $gris);

$site  = preg_replace('/[^a-z0-9]/i', '_', $point['site_nom'] ?? 'site');
$date  = str_replace('-', '', $point['date_point'] ?? date('Ymd'));
$fname = "point_{$site}_{$date}.pdf";

$dompdf->stream($fname, ['Attachment' => true]);
