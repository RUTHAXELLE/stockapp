<?php
// ============================================================
//  pages/observations.php  —  Suivi des observations coordinateurs
//  n° 2.8 du CR de réunion PDG.
//
//  Les observations sont saisies dans le point journalier ; cet écran
//  porte leur cycle de vie :
//    en_attente -> en_cours -> traite -> cloture
//  avec une branche escalade au-delà de 3 relances.
//
//  Le coordinateur relance et confirme ; le superviseur prend en
//  charge, traite et clôture. L'historique n'est jamais supprimable.
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();
require_permission('observations', 'can_read');

$user        = current_user();
$role_slug   = $user['role_slug'] ?? '';
$page_title  = 'Suivi des observations';
$active_page = 'observations';

$is_coord    = ($role_slug === 'coordinateur_site');
$site_force  = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;
// Prendre en charge, traiter et clôturer relèvent du traitement, donc de
// can_update. Le coordinateur relance et confirme sans ce droit.
$can_traiter = can('observations', 'can_update');

$STATUTS = [
    'en_attente' => ['En attente', '#fef3c7', '#92400e'],
    'en_cours'   => ['En cours',   '#dbeafe', '#1d4ed8'],
    'traite'     => ['Traité',     '#d1fae5', '#065f46'],
    'cloture'    => ['Clôturé',    '#f1f5f9', '#475569'],
    'escalade'   => ['Escaladé',   '#fee2e2', '#991b1b'],
];
$TYPES = ['info'=>'Info','alerte'=>'Alerte','relance'=>'Relance',
          'incident'=>'Incident','urgence'=>'Urgence','autre'=>'Autre'];

const OBS_MAX_RELANCES = 3;
const OBS_DELAI_RELANCE_H = 24;

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $obs_id = (int)($_POST['id'] ?? 0);

    $obs = $obs_id ? db_fetch_one(
        "SELECT o.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS auteur
         FROM op_observations o
         LEFT JOIN sites s ON s.id=o.site_id
         LEFT JOIN users u ON u.id=o.created_by
         WHERE o.id=?", [$obs_id]) : null;
    if (!$obs) json_response(false, 'Observation introuvable.');

    // Un coordinateur n'agit que sur les observations de son site.
    if ($is_coord && (int)$obs['site_id'] !== $site_force)
        json_response(false, 'Accès refusé à cette observation.');

    // ── PRENDRE EN CHARGE
    if ($action === 'prendre_en_charge') {
        if (!$can_traiter) json_response(false, 'Action réservée au traitement.');
        if (!in_array($obs['statut'], ['en_attente','escalade'], true))
            json_response(false, 'Cette observation est déjà prise en charge.');
        db_query("UPDATE op_observations
                     SET statut='en_cours', responsable_id=?, pris_en_charge_at=NOW()
                   WHERE id=?", [$user['id'], $obs_id]);
        notif_create('info', 'Observation prise en charge',
            "Votre observation du site {$obs['site_nom']} est prise en charge par {$user['prenom']} {$user['nom']}.",
            (int)$obs['created_by'], '/pages/observations.php');
        audit_log($user['id'],'UPDATE','observations',$obs_id,'Prise en charge');
        json_response(true, 'Observation prise en charge.');
    }

    // ── MARQUER TRAITÉE
    if ($action === 'traiter') {
        if (!$can_traiter) json_response(false, 'Action réservée au traitement.');
        if ($obs['statut'] !== 'en_cours') json_response(false, 'L\'observation doit être en cours.');
        $com = trim($_POST['commentaire'] ?? '');
        if ($com === '') json_response(false, 'Un commentaire de traitement est obligatoire.');
        db_query("UPDATE op_observations SET statut='traite', commentaire=? WHERE id=?", [$com, $obs_id]);
        notif_create('info', 'Observation traitée',
            "Votre observation a été traitée : $com",
            (int)$obs['created_by'], '/pages/observations.php');
        audit_log($user['id'],'UPDATE','observations',$obs_id,"Traitée : $com");
        json_response(true, 'Observation marquée traitée.');
    }

    // ── CLÔTURER — par le superviseur, ou par le coordinateur qui confirme
    if ($action === 'cloturer') {
        $est_auteur = (int)$obs['created_by'] === (int)$user['id'];
        if (!$can_traiter && !$est_auteur)
            json_response(false, 'Seul le responsable ou l\'auteur peut clôturer.');
        if ($obs['statut'] === 'cloture') json_response(false, 'Déjà clôturée.');
        if ($obs['statut'] === 'en_attente') json_response(false, 'Une observation en attente ne peut pas être clôturée.');
        db_query("UPDATE op_observations SET statut='cloture', cloture_at=NOW() WHERE id=?", [$obs_id]);
        if (!$est_auteur) {
            notif_create('info', 'Observation clôturée',
                "Votre observation a été clôturée par {$user['prenom']} {$user['nom']}.",
                (int)$obs['created_by'], '/pages/observations.php');
        }
        audit_log($user['id'],'UPDATE','observations',$obs_id,'Clôturée');
        json_response(true, 'Observation clôturée.');
    }

    // ── RELANCER — réservé à l'auteur, après le délai, tant qu'elle attend
    if ($action === 'relancer') {
        if ((int)$obs['created_by'] !== (int)$user['id'])
            json_response(false, 'Seul l\'auteur de l\'observation peut relancer.');
        if ($obs['statut'] !== 'en_attente')
            json_response(false, 'Une observation prise en charge n\'a pas besoin de relance.');

        // Le délai court depuis la dernière relance, sinon depuis la saisie.
        $depuis = db_fetch_value(
            "SELECT COALESCE(MAX(relance_at), (SELECT created_at FROM op_observations WHERE id=?))
             FROM op_observation_relances WHERE observation_id=?", [$obs_id, $obs_id]);
        $heures = (time() - strtotime((string)$depuis)) / 3600;
        if ($heures < OBS_DELAI_RELANCE_H) {
            $reste = (int)ceil(OBS_DELAI_RELANCE_H - $heures);
            json_response(false, "Relance possible dans $reste h — le délai de "
                . OBS_DELAI_RELANCE_H . " h n'est pas écoulé.");
        }

        db_begin();
        try {
            db_query("INSERT INTO op_observation_relances (observation_id,par_user_id) VALUES (?,?)",
                [$obs_id, $user['id']]);
            $nb = (int)$obs['nb_relances'] + 1;
            // Au-delà du plafond, l'observation bascule en 'escalade' : un
            // statut visible et filtrable, plutôt qu'une simple notification
            // noyée dans la liste (arbitrage rendu avec le métier).
            $escalade = $nb >= OBS_MAX_RELANCES;
            db_query("UPDATE op_observations SET nb_relances=?, statut=? WHERE id=?",
                [$nb, $escalade ? 'escalade' : 'en_attente', $obs_id]);

            $cibles = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                  WHERE u.actif=1 AND r.slug IN " .
                ($escalade
                    ? "('admin','superadmin')"
                    : "('admin','superadmin','superviseur_operation','gestionnaire_operation')"));
            $titre = $escalade ? 'Observation escaladée' : 'Relance sur une observation';
            $msg   = $escalade
                ? "Observation du site {$obs['site_nom']} relancée $nb fois sans prise en charge — escalade automatique."
                : "Relance n°$nb sur une observation du site {$obs['site_nom']}, toujours sans prise en charge.";
            foreach ($cibles as $c) {
                notif_create('info', $titre, $msg, (int)$c['id'], '/pages/observations.php');
            }
            audit_log($user['id'],'UPDATE','observations',$obs_id,
                "Relance n°$nb" . ($escalade ? ' — escalade automatique' : ''));
            db_commit();
            json_response(true, $escalade
                ? "Relance n°$nb enregistrée. L'observation est escaladée à l'administrateur."
                : "Relance n°$nb envoyée.");
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  FILTRES & DONNÉES
// ============================================================
$f_site   = $site_force ?: (int)($_GET['site'] ?? 0);
$f_statut = trim($_GET['statut'] ?? '');
$f_du     = trim($_GET['du'] ?? date('Y-m-01'));
$f_au     = trim($_GET['au'] ?? date('Y-m-d'));
$f_coord  = (int)($_GET['coordinateur'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_du)) $f_du = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_au)) $f_au = date('Y-m-d');

$where  = ["o.created_at::date BETWEEN ? AND ?"];
$params = [$f_du, $f_au];
if ($f_site)  { $where[] = "o.site_id=?";    $params[] = $f_site; }
if ($f_coord) { $where[] = "o.created_by=?"; $params[] = $f_coord; }
if (isset($STATUTS[$f_statut])) { $where[] = "o.statut=?"; $params[] = $f_statut; }
$wsql = implode(' AND ', $where);

$observations = db_fetch_all(
    "SELECT o.*, s.nom AS site_nom, p.date_point,
            CONCAT(ua.prenom,' ',ua.nom) AS auteur,
            CONCAT(ur.prenom,' ',ur.nom) AS responsable,
            (SELECT COUNT(*) FROM op_observation_relances r WHERE r.observation_id=o.id) AS nb_rel,
            (SELECT MAX(relance_at) FROM op_observation_relances r WHERE r.observation_id=o.id) AS derniere_relance
     FROM op_observations o
     LEFT JOIN sites s  ON s.id=o.site_id
     LEFT JOIN op_points_journaliers p ON p.id=o.point_id
     LEFT JOIN users ua ON ua.id=o.created_by
     LEFT JOIN users ur ON ur.id=o.responsable_id
     WHERE $wsql
     ORDER BY CASE o.statut WHEN 'escalade' THEN 0 WHEN 'en_attente' THEN 1
                            WHEN 'en_cours' THEN 2 WHEN 'traite' THEN 3 ELSE 4 END,
              o.created_at DESC",
    $params
);

$compte = array_fill_keys(array_keys($STATUTS), 0);
foreach ($observations as $o) { $compte[$o['statut']] = ($compte[$o['statut']] ?? 0) + 1; }

$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$coord_list = $is_coord ? [] : db_fetch_all(
    "SELECT DISTINCT u.id, CONCAT(u.prenom,' ',u.nom) AS nom
     FROM op_observations o JOIN users u ON u.id=o.created_by ORDER BY nom");

// ── EXPORT EXCEL
if (($_GET['export'] ?? '') === 'xlsx') {
    if (!can('observations','can_export')) { http_response_code(403); exit('Export non autorisé.'); }
    $sp = new Spreadsheet(); $sh = $sp->getActiveSheet()->setTitle('Observations');
    $ent = ['Date','Site','Type','Observation','Statut','Auteur','Responsable',
            'Prise en charge','Clôture','Relances','Commentaire'];
    foreach ($ent as $i=>$t) {
        $c = chr(65+$i); $sh->setCellValue($c.'1', $t);
        $sh->getStyle($c.'1')->applyFromArray([
            'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'06033A']],
            'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
        ]);
    }
    $r = 2;
    foreach ($observations as $o) {
        $sh->setCellValue("A$r", fmt_date($o['date_point'] ?: $o['created_at']));
        $sh->setCellValue("B$r", $o['site_nom'] ?? '—');
        $sh->setCellValue("C$r", $TYPES[$o['type']] ?? $o['type']);
        $sh->setCellValue("D$r", $o['texte']);
        $sh->setCellValue("E$r", $STATUTS[$o['statut']][0] ?? $o['statut']);
        $sh->setCellValue("F$r", $o['auteur'] ?? '—');
        $sh->setCellValue("G$r", $o['responsable'] ?? '—');
        $sh->setCellValue("H$r", $o['pris_en_charge_at'] ? fmt_datetime($o['pris_en_charge_at']) : '—');
        $sh->setCellValue("I$r", $o['cloture_at'] ? fmt_datetime($o['cloture_at']) : '—');
        $sh->setCellValue("J$r", (int)$o['nb_rel']);
        $sh->setCellValue("K$r", $o['commentaire'] ?? '');
        $r++;
    }
    foreach (range('A','K') as $c) $sh->getColumnDimension($c)->setAutoSize(true);
    audit_log($user['id'],'READ','observations',0,"Export XLSX ($f_du → $f_au)");
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="observations_'.$f_du.'_'.$f_au.'.xlsx"');
    header('Cache-Control: max-age=0');
    (new XlsxWriter($sp))->save('php://output');
    exit;
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.obs-chips{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:18px}
.obs-chip{padding:6px 14px;border-radius:20px;font-size:12.5px;font-weight:700;border:1px solid transparent;text-decoration:none}
.obs-f{display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px}
.obs-f label{display:block;font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.obs-f input,.obs-f select{padding:8px 11px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none}
.obs-card{background:white;border:1px solid var(--border);border-radius:14px;padding:16px 18px;margin-bottom:12px;border-left:4px solid var(--border)}
.obs-card.s-en_attente{border-left-color:#f39c12}
.obs-card.s-en_cours{border-left-color:#1d4ed8}
.obs-card.s-traite{border-left-color:#065f46}
.obs-card.s-cloture{border-left-color:#94a3b8}
.obs-card.s-escalade{border-left-color:#c0392b}
.obs-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.obs-txt{font-size:14.5px;line-height:1.55;color:var(--navy);font-weight:600}
.obs-meta{font-size:12px;color:var(--muted);margin-top:6px;display:flex;gap:14px;flex-wrap:wrap}
.obs-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700}
.obs-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.obs-com{background:var(--lighter);border-radius:9px;padding:9px 12px;font-size:13px;color:var(--navy);margin-top:10px}
.obs-rel{background:#fff8e7;border-left:3px solid #f39c12;border-radius:0 8px 8px 0;padding:8px 12px;font-size:12.5px;color:#92400e;margin-top:8px}
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:16px">
  <div>
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">
      <i class="ph ph-chat-dots" aria-hidden="true"></i> Suivi des observations
    </h2>
    <p style="font-size:13px;color:var(--muted);margin-top:4px">
      Observations saisies dans les points journaliers — de la prise en charge à la clôture.
    </p>
  </div>
  <?php if(can('observations','can_export')): ?>
  <a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'xlsx']))) ?>">
    <i class="ph-duotone ph-microsoft-excel-logo"></i> Excel
  </a>
  <?php endif; ?>
</div>

<div class="obs-chips">
  <?php foreach ($STATUTS as $k=>[$lbl,$bg,$fg]):
    $q = array_merge($_GET, ['statut'=>$k]); unset($q['export']); ?>
  <a class="obs-chip" href="?<?= h(http_build_query($q)) ?>"
     style="background:<?= $bg ?>;color:<?= $fg ?><?= $f_statut===$k?';border-color:'.$fg:'' ?>">
    <?= h($lbl) ?> · <?= (int)($compte[$k] ?? 0) ?>
  </a>
  <?php endforeach; ?>
  <?php $q = $_GET; unset($q['statut'], $q['export']); ?>
  <a class="obs-chip" href="?<?= h(http_build_query($q)) ?>"
     style="background:var(--lighter);color:var(--navy)<?= $f_statut===''?';border-color:var(--navy)':'' ?>">
    Tous · <?= count($observations) ?>
  </a>
</div>

<form method="GET" class="obs-f">
  <?php if($f_statut): ?><input type="hidden" name="statut" value="<?= h($f_statut) ?>"><?php endif; ?>
  <div><label>Du</label><input type="date" name="du" value="<?= h($f_du) ?>"></div>
  <div><label>Au</label><input type="date" name="au" value="<?= h($f_au) ?>"></div>
  <?php if(!$is_coord): ?>
  <div><label>Site</label>
    <select name="site"><option value="0">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= $f_site===(int)$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Coordinateur</label>
    <select name="coordinateur"><option value="0">Tous</option>
      <?php foreach($coord_list as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $f_coord===(int)$c['id']?'selected':'' ?>><?= h($c['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-funnel" aria-hidden="true"></i> Filtrer</button>
  <a class="btn btn-secondary btn-sm" href="observations.php"><i class="ph ph-x" aria-hidden="true"></i> Réinitialiser</a>
</form>

<?php if (empty($observations)): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:52px;color:var(--muted)">
  <i class="ph-duotone ph-chat-dots" style="font-size:40px;color:var(--border)"></i>
  <p style="margin-top:12px">Aucune observation sur cette période.</p>
</div></div>
<?php else: foreach ($observations as $o):
  $est_auteur = (int)$o['created_by'] === (int)$user['id'];
  [$slbl,$sbg,$sfg] = $STATUTS[$o['statut']] ?? ['?','#eee','#333'];
  // Le bouton de relance n'apparaît qu'une fois le délai écoulé, pour ne
  // pas proposer une action que le serveur refusera.
  $depuis     = $o['derniere_relance'] ?: $o['created_at'];
  $heures     = (time() - strtotime((string)$depuis)) / 3600;
  $peut_relancer = $est_auteur && $o['statut']==='en_attente'
                   && $heures >= OBS_DELAI_RELANCE_H && (int)$o['nb_rel'] < OBS_MAX_RELANCES;
?>
<div class="obs-card s-<?= h($o['statut']) ?>">
  <div class="obs-hd">
    <div style="flex:1;min-width:240px">
      <div class="obs-txt"><?= h($o['texte']) ?></div>
      <div class="obs-meta">
        <span><i class="ph ph-calendar" aria-hidden="true"></i> <?= h(fmt_date($o['date_point'] ?: $o['created_at'])) ?></span>
        <span><i class="ph ph-buildings" aria-hidden="true"></i> <?= h($o['site_nom'] ?? '—') ?></span>
        <span><i class="ph ph-user" aria-hidden="true"></i> <?= h($o['auteur'] ?? '—') ?></span>
        <?php if($o['responsable']): ?>
        <span><i class="ph ph-user-check" aria-hidden="true"></i> <?= h($o['responsable']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
      <span class="obs-badge" style="background:var(--lighter);color:var(--muted)"><?= h($TYPES[$o['type']] ?? $o['type']) ?></span>
      <span class="obs-badge" style="background:<?= $sbg ?>;color:<?= $sfg ?>"><?= h($slbl) ?></span>
    </div>
  </div>

  <?php if ((int)$o['nb_rel'] > 0): ?>
  <div class="obs-rel">
    <i class="ph ph-bell-ringing" aria-hidden="true"></i>
    <strong><?= (int)$o['nb_rel'] ?> relance(s)</strong>
    <?php if($o['derniere_relance']): ?> · dernière le <?= h(fmt_datetime($o['derniere_relance'])) ?><?php endif; ?>
    <?php if($o['statut']==='escalade'): ?> · escaladée à l'administrateur<?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($o['commentaire']): ?>
  <div class="obs-com"><strong>Traitement :</strong> <?= h($o['commentaire']) ?></div>
  <?php endif; ?>

  <div class="obs-actions">
    <?php if ($can_traiter && in_array($o['statut'], ['en_attente','escalade'], true)): ?>
    <button class="btn btn-primary btn-sm" onclick="obsAction('prendre_en_charge',<?= (int)$o['id'] ?>)">
      <i class="ph ph-hand-pointing" aria-hidden="true"></i> Prendre en charge
    </button>
    <?php endif; ?>

    <?php if ($can_traiter && $o['statut']==='en_cours'): ?>
    <button class="btn btn-success btn-sm" onclick="ouvrirTraitement(<?= (int)$o['id'] ?>)">
      <i class="ph ph-check-circle" aria-hidden="true"></i> Marquer traitée
    </button>
    <?php endif; ?>

    <?php if (in_array($o['statut'], ['traite','en_cours'], true) && ($can_traiter || $est_auteur)): ?>
    <button class="btn btn-secondary btn-sm" onclick="obsAction('cloturer',<?= (int)$o['id'] ?>)">
      <i class="ph ph-seal-check" aria-hidden="true"></i> <?= $est_auteur && !$can_traiter ? 'Confirmer et clôturer' : 'Clôturer' ?>
    </button>
    <?php endif; ?>

    <?php if ($peut_relancer): ?>
    <button class="btn btn-secondary btn-sm" onclick="obsAction('relancer',<?= (int)$o['id'] ?>)">
      <i class="ph ph-bell-ringing" aria-hidden="true"></i> Relancer
    </button>
    <?php elseif ($est_auteur && $o['statut']==='en_attente' && (int)$o['nb_rel'] < OBS_MAX_RELANCES): ?>
    <span style="font-size:12px;color:var(--muted);align-self:center">
      Relance possible dans <?= max(1,(int)ceil(OBS_DELAI_RELANCE_H - $heures)) ?> h
    </span>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; endif; ?>

<!-- MODALE TRAITEMENT -->
<div class="modal-overlay" id="mTraiter" style="display:none;position:fixed;inset:0;background:rgba(13,31,53,.5);z-index:600;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;width:460px;max-width:95vw;padding:22px 24px">
    <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin:0 0 6px">
      Marquer l'observation traitée
    </h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px">
      Le commentaire est transmis au coordinateur : décrivez ce qui a été fait.
    </p>
    <div id="traitAlert"></div>
    <textarea id="traitCom" class="form-control" rows="3" placeholder="Action réalisée…"></textarea>
    <div style="display:flex;justify-content:flex-end;gap:9px;margin-top:16px">
      <button class="btn btn-secondary" onclick="document.getElementById('mTraiter').style.display='none'">Annuler</button>
      <button class="btn btn-primary" onclick="confirmerTraitement()">Confirmer</button>
    </div>
  </div>
</div>

<script>
let obsEnCours = 0;
function ap(d){
  return fetch(window.location.href,{method:'POST',
    headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams(d)}).then(r=>r.json());
}
function obsAction(action, id, extra){
  ap(Object.assign({action, id}, extra||{})).then(d=>{
    toast(d.message, d.success?'success':'danger');
    if(d.success) setTimeout(()=>location.reload(), 900);
  }).catch(()=>toast('Erreur réseau.','danger'));
}
function ouvrirTraitement(id){
  obsEnCours = id;
  document.getElementById('traitCom').value = '';
  document.getElementById('traitAlert').innerHTML = '';
  document.getElementById('mTraiter').style.display = 'flex';
}
function confirmerTraitement(){
  const com = document.getElementById('traitCom').value.trim();
  if(!com){
    document.getElementById('traitAlert').innerHTML =
      '<div class="alert alert-danger" style="font-size:13px">Le commentaire est obligatoire.</div>';
    return;
  }
  document.getElementById('mTraiter').style.display='none';
  obsAction('traiter', obsEnCours, {commentaire: com});
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
