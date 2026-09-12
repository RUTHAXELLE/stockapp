<?php
// ============================================================
//  pages/point_emuci.php
//  Dashboard EMUCI — comparaison PJ coordinateur vs import OptoPlate
//  Lecture + correction GP
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/point_emuci_corrections.php';

require_auth();

$user        = current_user();
$role_slug   = $user['role_slug'] ?? '';
$page_title  = 'Point EMUCI';
$active_page = 'point_emuci';

require_permission('point_emuci', 'can_read');
$can_correct = can('point_emuci', 'can_update');

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'demander_correction') {
        if (!$can_correct) json_response(false, 'Accès refusé.');
        $pj_id    = (int)($_POST['pj_id'] ?? 0);
        $propose  = (int)($_POST['new_total'] ?? -1);
        $motif    = trim($_POST['motif'] ?? '');
        if (!$pj_id) json_response(false, 'Point journalier introuvable.');
        if ($propose < 0) json_response(false, 'Valeur invalide.');
        $pj = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$pj_id]);
        if (!$pj) json_response(false, 'Point journalier introuvable.');
        try {
            $id = pec_demander($pj, $user, $propose, $motif);
            audit_log($user['id'], 'CREATE', 'corrections_point_emuci', $id,
                "Demande correction PJ#$pj_id → $propose | motif: $motif");
            json_response(true, 'Demande envoyée au coordinateur.');
        } catch (Exception $e) { json_response(false, $e->getMessage()); }
    }

    if ($action === 'valider_contestation') {
        if (!$can_correct) json_response(false, 'Accès refusé.');
        $corr_id = (int)($_POST['corr_id'] ?? 0);
        $corr = db_fetch_one("SELECT * FROM corrections_point_emuci WHERE id=? AND statut='conteste'", [$corr_id]);
        if (!$corr) json_response(false, 'Contestation introuvable ou déjà traitée.');
        try {
            pec_valider_contestation($corr, $user);
            audit_log($user['id'], 'UPDATE', 'corrections_point_emuci', $corr_id, "Contestation validée");
            json_response(true, 'Contre-proposition du coordinateur validée.');
        } catch (Exception $e) { json_response(false, $e->getMessage()); }
    }

    if ($action === 'refuser_contestation') {
        if (!$can_correct) json_response(false, 'Accès refusé.');
        $corr_id = (int)($_POST['corr_id'] ?? 0);
        $corr = db_fetch_one("SELECT * FROM corrections_point_emuci WHERE id=? AND statut='conteste'", [$corr_id]);
        if (!$corr) json_response(false, 'Contestation introuvable ou déjà traitée.');
        try {
            pec_refuser_contestation($corr, $user);
            audit_log($user['id'], 'UPDATE', 'corrections_point_emuci', $corr_id, "Contestation refusée");
            json_response(true, 'Contestation refusée, valeur d\'origine maintenue.');
        } catch (Exception $e) { json_response(false, $e->getMessage()); }
    }

    if ($action === 'annuler_correction') {
        if (!$can_correct) json_response(false, 'Accès refusé.');
        $pj_id = (int)($_POST['pj_id'] ?? 0);
        if (!$pj_id) json_response(false, 'Point journalier introuvable.');
        db_query(
            "UPDATE op_points_journaliers SET correction_gp=NULL, motif_correction_gp=NULL, corrected_by_gp=NULL, corrected_at=NULL WHERE id=?",
            [$pj_id]
        );
        audit_log($user['id'], 'UPDATE', 'op_points_journaliers', $pj_id, "Annulation correction GP");
        json_response(true, 'Correction annulée.');
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$f_date = trim($_GET['date'] ?? '');
if (!$f_date) {
    $last = db_fetch_value("SELECT MAX(date_import) FROM import_optoplate");
    $f_date = $last ?: date('Y-m-d');
}

$has_import = (int)db_fetch_value(
    "SELECT COUNT(*) FROM import_optoplate WHERE date_import=? OR date_installation::date=?", [$f_date, $f_date]
) > 0;

$sites_list = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");

$rows = [];
$total_in_use   = 0;
$total_reserved = 0;
$total_declared = 0;
$total_corrections = 0;

foreach ($sites_list as $s) {
    $sid = (int)$s['id'];

    $pj = db_fetch_one(
        "SELECT pj.id, pj.total_plaques, pj.correction_gp, pj.motif_correction_gp,
                pj.corrected_at, CONCAT(u.prenom,' ',u.nom) AS corrected_by_nom
         FROM op_points_journaliers pj
         LEFT JOIN users u ON u.id = pj.corrected_by_gp
         WHERE pj.site_id=? AND pj.date_point=?",
        [$sid, $f_date]
    );

    // Comparaison au jour réel d'installation (date_installation), pas à la date
    // saisie à l'écran d'import (date_import) : un import OptoPlate couvre souvent
    // plusieurs jours ("export_plates_from_...until_..."), voire un historique
    // complet — compter par date_import y ferait remonter tout le fichier sous
    // une seule journée.
    $in_use   = (int)db_fetch_value(
        "SELECT COUNT(*) FROM import_optoplate WHERE site_id=? AND statut_plaque='in_use' AND date_installation::date=?",
        [$sid, $f_date]
    );
    $reserved = (int)db_fetch_value(
        "SELECT COUNT(*) FROM import_optoplate WHERE site_id=? AND statut_plaque='reserved' AND date_import=?",
        [$sid, $f_date]
    );

    $declared   = $pj ? (int)$pj['total_plaques'] : null;
    $corrected  = ($pj && $pj['correction_gp'] !== null) ? (int)$pj['correction_gp'] : null;
    $effective  = $corrected ?? $declared;
    $ecart      = ($effective !== null && ($in_use > 0 || $reserved > 0))
                  ? ($in_use - $effective)
                  : null;

    $demande = $pj ? pec_active((int)$pj['id']) : null;

    if ($declared === null && $in_use === 0 && $reserved === 0) continue;

    $total_in_use   += $in_use;
    $total_reserved += $reserved;
    $total_declared += $effective ?? 0;
    if ($corrected !== null) $total_corrections++;

    $rows[] = [
        'site_id'      => $sid,
        'site_nom'     => $s['nom'],
        'pj_id'        => $pj['id']                  ?? null,
        'declared'     => $declared,
        'corrected'    => $corrected,
        'motif'        => $pj['motif_correction_gp']  ?? null,
        'corrected_by' => $pj['corrected_by_nom']     ?? null,
        'corrected_at' => $pj['corrected_at']         ?? null,
        'effective'    => $effective,
        'in_use'       => $in_use,
        'reserved'     => $reserved,
        'ecart'        => $ecart,
        'demande'      => $demande,
    ];
}

$total_ecart = $total_in_use - $total_declared;

// ============================================================
include __DIR__ . '/../templates/header.php';
?>
<style>
.emuci-card{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.07);padding:20px 24px;display:flex;flex-direction:column;gap:4px}
.emuci-card .label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.emuci-card .value{font-size:28px;font-weight:700;color:var(--text)}
.emuci-card.accent .value{color:var(--primary-d)}
.emuci-card.green  .value{color:#2e7d32}
.emuci-card.orange .value{color:#e65100}
.emuci-card.red    .value{color:#c62828}
.pill-ok   {background:#e8f5e9;color:#2e7d32;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pill-ecart{background:#fff3e0;color:#e65100;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pill-neg  {background:#fce4ec;color:#c62828;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pill-nd   {background:#f3f4f6;color:#9ca3af;padding:3px 10px;border-radius:20px;font-size:12px}
.corr-badge{background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer}
.btn-correct{background:#1976d2;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:13px;cursor:pointer;font-weight:600;white-space:nowrap}
.btn-correct:hover{background:#1565c0}
.btn-annul{background:#f3f4f6;color:#555;border:none;border-radius:8px;padding:7px 12px;font-size:12px;cursor:pointer}
.btn-annul:hover{background:#e0e0e0}
.no-pj{color:#9ca3af;font-style:italic;font-size:13px}
</style>

<div class="page-header">
  <div>
    <h2 class="page-title" style="font-size:22px;font-weight:800;color:var(--navy);margin:0"><i class="ph ph-chart-bar" aria-hidden="true"></i> Point EMUCI</h2>
    <p style="color:var(--muted);margin:4px 0 0;font-size:14px">
      Comparaison plaques posées (coordinateur) vs import OptoPlate
    </p>
  </div>
  <form method="GET" style="display:flex;align-items:center;gap:10px">
    <label for="inp-date" style="font-size:13px;color:var(--muted)">Date</label>
    <input type="date" id="inp-date" name="date" value="<?= h($f_date) ?>"
           style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:13px"
           onchange="this.form.submit()">
  </form>
</div>

<?php if (!$has_import): ?>
<div style="background:#fff3e0;border:1px solid #ffcc80;border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
  <span style="font-size:22px"><i class="ph ph-warning" aria-hidden="true"></i></span>
  <div>
    <strong>Aucun import OptoPlate pour le <?= h(date('d/m/Y', strtotime($f_date))) ?></strong>
    <div style="font-size:13px;color:#7f4f00;margin-top:2px">
      Chargez l'import OptoPlate pour voir la comparaison EMUCI.
      <?php if (in_array($role_slug, ['admin','superadmin','gestionnaire_operation'])): ?>
        <a href="import_emuci.php" style="color:#1976d2;font-weight:600;margin-left:8px">→ Import EMUCI</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- CARTES RÉSUMÉ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:28px">
  <div class="emuci-card accent">
    <span class="label">OptoPlate in_use</span>
    <span class="value"><?= number_format($total_in_use) ?></span>
    <span style="font-size:12px;color:var(--muted)">plaques posées EMUCI</span>
  </div>
  <div class="emuci-card">
    <span class="label">OptoPlate reserved</span>
    <span class="value"><?= number_format($total_reserved) ?></span>
    <span style="font-size:12px;color:var(--muted)">plaques réservées EMUCI</span>
  </div>
  <div class="emuci-card green">
    <span class="label">Déclaratif coordinateurs</span>
    <span class="value"><?= number_format($total_declared) ?></span>
    <span style="font-size:12px;color:var(--muted)"><?= $total_corrections > 0 ? "$total_corrections correction(s) GP" : 'depuis points journaliers' ?></span>
  </div>
  <div class="emuci-card <?= $total_ecart == 0 ? 'green' : ($total_ecart > 0 ? 'orange' : 'red') ?>">
    <span class="label">Écart global</span>
    <span class="value"><?= $total_ecart > 0 ? "+$total_ecart" : $total_ecart ?></span>
    <span style="font-size:12px;color:var(--muted)">EMUCI − coordinateurs</span>
  </div>
</div>

<!-- TABLEAU PAR SITE -->
<?php if (empty($rows)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--muted)">
  <div style="font-size:40px;margin-bottom:12px"><i class="ph ph-clipboard-text" aria-hidden="true"></i></div>
  <div>Aucune donnée pour le <?= h(date('d/m/Y', strtotime($f_date))) ?></div>
</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden">
  <table class="data-table" style="margin:0">
    <thead>
      <tr>
        <th>Site</th>
        <th style="text-align:center">Plaques posées (système)</th>
        <th style="text-align:center">Plaques réservées</th>
        <th style="text-align:center">Déclaratif coord.</th>
        <th style="text-align:center">Écart</th>
        <th style="text-align:center">Correction</th>
        <?php if ($can_correct): ?><th style="text-align:center">Action</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <?php
        $ecart = $r['ecart'];
        $ecart_cls = $ecart === null ? 'pill-nd' : ($ecart == 0 ? 'pill-ok' : ($ecart > 0 ? 'pill-ecart' : 'pill-neg'));
        $ecart_lbl = $ecart === null ? '—' : ($ecart > 0 ? "+$ecart" : "$ecart");
    ?>
    <tr id="row-<?= $r['site_id'] ?>">
      <td><strong><?= h($r['site_nom']) ?></strong></td>
      <td style="text-align:center;font-weight:600;color:#1565c0"><?= $r['in_use'] ?></td>
      <td style="text-align:center;color:var(--muted)"><?= $r['reserved'] ?></td>
      <td style="text-align:center">
        <?php if ($r['declared'] !== null): ?>
          <span style="font-weight:600;font-size:15px"><?= $r['declared'] ?></span>
        <?php else: ?>
          <span class="no-pj">Pas de point</span>
        <?php endif; ?>
      </td>
      <td style="text-align:center">
        <span id="ecart-<?= $r['site_id'] ?>" class="<?= $ecart_cls ?>"><?= $ecart_lbl ?></span>
      </td>
      <td style="text-align:center" id="corr-<?= $r['site_id'] ?>">
        <?php if ($r['demande'] && $r['demande']['statut'] === 'en_attente'): ?>
          <span class="corr-badge" style="background:#fff3e0;color:#e65100;cursor:default" title="Motif : <?= h($r['demande']['motif_gp']) ?>">
            ⏳ En attente (<?= (int)$r['demande']['total_propose'] ?>)
          </span>
        <?php elseif ($r['demande'] && $r['demande']['statut'] === 'conteste'): ?>
          <span class="corr-badge" style="background:#fce4ec;color:#c62828;cursor:default" title="Réponse du coordinateur : <?= h($r['demande']['reponse_coord']) ?>">
            ⚠️ Contesté (<?= (int)$r['demande']['total_propose_coord'] ?>)
          </span>
        <?php elseif ($r['corrected'] !== null): ?>
          <span class="corr-badge" title="<?= h($r['motif']) ?> — <?= h($r['corrected_by']) ?>"
                onclick="showMotif(<?= $r['pj_id'] ?>, '<?= h(addslashes($r['motif'])) ?>', '<?= h(addslashes($r['corrected_by'] ?? '')) ?>', '<?= h($r['corrected_at']) ?>')">
            <?= $r['corrected'] ?> <i class="ph ph-pencil-simple" aria-hidden="true"></i>
          </span>
        <?php else: ?>
          <span style="color:var(--muted);font-size:13px">—</span>
        <?php endif; ?>
      </td>
      <?php if ($can_correct): ?>
      <td style="text-align:center;white-space:nowrap;padding:8px 12px">
        <?php if (!$r['pj_id']): ?>
          <span class="no-pj">Pas de PJ</span>
        <?php elseif ($r['demande'] && $r['demande']['statut'] === 'en_attente'): ?>
          <span style="color:var(--muted);font-size:12px;font-style:italic">En attente du coordinateur</span>
        <?php elseif ($r['demande'] && $r['demande']['statut'] === 'conteste'): ?>
          <button class="btn-correct" style="background:#2e7d32" onclick="validerContestation(<?= $r['demande']['id'] ?>,<?= $r['site_id'] ?>)">
            <i class="ph ph-check" aria-hidden="true"></i> Valider
          </button>
          <button class="btn-annul" onclick="refuserContestation(<?= $r['demande']['id'] ?>,<?= $r['site_id'] ?>)" title="Refuser, garder ma valeur"><i class="ph ph-x" aria-hidden="true"></i></button>
        <?php else: ?>
          <button class="btn-correct" onclick="openCorrection(<?= $r['pj_id'] ?>,<?= $r['site_id'] ?>,<?= $r['effective'] ?? 0 ?>,'<?= h(addslashes($r['site_nom'])) ?>')">
            <i class="ph ph-pencil-simple" aria-hidden="true"></i> Demander correction
          </button>
          <?php if ($r['corrected'] !== null): ?>
          <button class="btn-annul" onclick="annulerCorrection(<?= $r['pj_id'] ?>,<?= $r['site_id'] ?>)" title="Annuler la correction"><i class="ph ph-x" aria-hidden="true"></i></button>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- MODAL CORRECTION -->
<?php if ($can_correct): ?>
<div id="modalCorrection" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px 32px;width:90%;max-width:460px;box-shadow:0 8px 40px rgba(0,0,0,.18)">
    <h3 style="margin:0 0 6px;font-size:17px"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Demander une correction</h3>
    <p id="mCorrSite" style="color:var(--muted);font-size:14px;margin:0 0 20px"></p>
    <input type="hidden" id="mCorrPjId">
    <input type="hidden" id="mCorrSiteId">

    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Valeur proposée (plaques posées)</label>
    <input type="number" id="mCorrTotal" min="0"
           style="width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:22px;font-weight:700;text-align:center;margin-bottom:16px;box-sizing:border-box">

    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Motif <span style="color:red">*</span></label>
    <textarea id="mCorrMotif" rows="3" placeholder="Ex : Le coordinateur a oublié 2 engins..."
              style="width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;resize:vertical;box-sizing:border-box"></textarea>

    <p style="font-size:12px;color:var(--muted);margin:10px 0 0"><i class="ph ph-info" aria-hidden="true"></i> Le coordinateur du site sera notifié et pourra accepter cette valeur ou proposer la sienne.</p>

    <div id="mCorrAlert" style="margin-top:12px"></div>

    <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="closeCorrection()">Annuler</button>
      <button class="btn btn-primary" onclick="submitCorrection()"><i class="ph ph-paper-plane-tilt" aria-hidden="true"></i> Envoyer la demande</button>
    </div>
  </div>
</div>

<!-- MODAL MOTIF -->
<div id="modalMotif" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px 32px;width:90%;max-width:420px;box-shadow:0 8px 40px rgba(0,0,0,.18)">
    <h3 style="margin:0 0 16px;font-size:17px"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Détail de la correction</h3>
    <div id="mMotifContent"></div>
    <div style="text-align:right;margin-top:20px">
      <button class="btn btn-secondary" onclick="document.getElementById('modalMotif').style.display='none'">Fermer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const DATE_F = '<?= h($f_date) ?>';

function ap(data){
  return fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams(data)})
    .then(r=>r.json());
}
function toast(msg,ok=true){
  let t=document.getElementById('toast-live');
  if(!t){t=document.createElement('div');t.id='toast-live';t.setAttribute('role','status');t.setAttribute('aria-live','polite');t.setAttribute('aria-atomic','true');document.body.appendChild(t);}
  clearTimeout(t._hideTimer);
  t.style.cssText=`position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;background:${ok?'#2e7d32':'#c62828'};color:#fff;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2)`;
  t.textContent=msg;
  t._hideTimer=setTimeout(()=>{t.style.display='none';},3000);
}

<?php if ($can_correct): ?>
function openCorrection(pjId, siteId, current, siteNom){
  document.getElementById('mCorrPjId').value   = pjId;
  document.getElementById('mCorrSiteId').value = siteId;
  document.getElementById('mCorrTotal').value  = current;
  document.getElementById('mCorrMotif').value  = '';
  document.getElementById('mCorrAlert').innerHTML = '';
  document.getElementById('mCorrSite').textContent = siteNom + ' — ' + DATE_F;
  document.getElementById('modalCorrection').style.display = 'flex';
}
function closeCorrection(){
  document.getElementById('modalCorrection').style.display = 'none';
}
async function submitCorrection(){
  const pjId    = document.getElementById('mCorrPjId').value;
  const siteId  = document.getElementById('mCorrSiteId').value;
  const total   = document.getElementById('mCorrTotal').value;
  const motif   = document.getElementById('mCorrMotif').value.trim();
  if (!motif){ document.getElementById('mCorrAlert').innerHTML='<div style="color:red;font-size:13px">Motif obligatoire.</div>'; return; }
  try {
    const d = await ap({action:'demander_correction', pj_id:pjId, new_total:total, motif});
    if(d.success){
      toast(d.message);
      closeCorrection();
      setTimeout(()=>location.reload(), 800);
    } else {
      document.getElementById('mCorrAlert').innerHTML=`<div style="color:red;font-size:13px">${d.message}</div>`;
    }
  } catch(e) {
    document.getElementById('mCorrAlert').innerHTML='<div style="color:red;font-size:13px">Erreur réseau.</div>';
  }
}

async function annulerCorrection(pjId, siteId){
  if(!confirm('Annuler la correction GP pour ce site ?')) return;
  try {
    const d = await ap({action:'annuler_correction', pj_id:pjId});
    if(d.success){ toast(d.message); setTimeout(()=>location.reload(),600); }
    else alert(d.message);
  } catch(e){ alert('Erreur réseau.'); }
}

async function validerContestation(corrId, siteId){
  if(!confirm('Valider la contre-proposition du coordinateur ?')) return;
  try {
    const d = await ap({action:'valider_contestation', corr_id:corrId});
    if(d.success){ toast(d.message); setTimeout(()=>location.reload(),600); }
    else alert(d.message);
  } catch(e){ alert('Erreur réseau.'); }
}

async function refuserContestation(corrId, siteId){
  if(!confirm('Refuser la contre-proposition et maintenir votre valeur d\'origine ?')) return;
  try {
    const d = await ap({action:'refuser_contestation', corr_id:corrId});
    if(d.success){ toast(d.message); setTimeout(()=>location.reload(),600); }
    else alert(d.message);
  } catch(e){ alert('Erreur réseau.'); }
}

function showMotif(pjId, motif, by, at){
  document.getElementById('mMotifContent').innerHTML =
    `<div style="background:#f0f4ff;border-radius:10px;padding:14px 16px;margin-bottom:12px">
      <div style="font-size:13px;color:var(--muted);margin-bottom:4px">Motif</div>
      <div style="font-weight:600">${motif}</div>
    </div>
    <div style="font-size:13px;color:var(--muted)">Corrigé par <strong>${by||'—'}</strong> le ${at||'—'}</div>`;
  document.getElementById('modalMotif').style.display = 'flex';
}
<?php endif; ?>
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
