<?php
// ============================================================
//  pages/rapport_journalier.php
//  Rapport journalier — Maintenance Informatique
//  Bilan quotidien : état équipements info + interventions du jour
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$page_title  = 'Rapport journalier';
$active_page = 'rapport_journalier';

require_permission('rapport_journalier', 'can_read');

$sites_list  = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");
$is_maint    = ($role_slug === 'maintenance_info');
$site_force  = ($is_maint && $user['site_id']) ? (int)$user['site_id'] : 0;

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── SAUVEGARDER RAPPORT
    if ($action === 'sauver') {
        $site_id      = $site_force ?: (int)($_POST['site_id'] ?? 0);
        $date_rapport = trim($_POST['date_rapport'] ?? date('Y-m-d'));
        $nb_ok        = (int)($_POST['nb_ok']       ?? 0);
        $nb_hs        = (int)($_POST['nb_hs']        ?? 0);
        $nb_maint     = (int)($_POST['nb_maint']     ?? 0);
        $nb_interv    = (int)($_POST['nb_interv']    ?? 0);
        $observations = trim($_POST['observations']  ?? '');
        $actions_prev = trim($_POST['actions_prev']  ?? '');

        if (!$site_id || !$date_rapport)
            json_response(false, 'Site et date obligatoires.');

        $exist = db_fetch_one(
            "SELECT id FROM rapports_journaliers_info
             WHERE site_id=? AND date_rapport=? AND technicien_id=?",
            [$site_id, $date_rapport, $user['id']]
        );

        if ($exist) {
            db_query(
                "UPDATE rapports_journaliers_info
                 SET nb_equip_ok=?, nb_equip_hs=?, nb_equip_maintenance=?,
                     nb_interventions=?, observations=?, actions_preventives=?,
                     updated_at=NOW()
                 WHERE id=?",
                [$nb_ok,$nb_hs,$nb_maint,$nb_interv,$observations,$actions_prev,$exist['id']]
            );
            audit_log($user['id'],'UPDATE','interventions',$exist['id'],"MAJ rapport journalier info site:$site_id $date_rapport");
            json_response(true,'Rapport mis à jour.',['id'=>$exist['id'],'mode'=>'update']);
        } else {
            db_query(
                "INSERT INTO rapports_journaliers_info
                 (technicien_id,site_id,date_rapport,nb_equip_ok,nb_equip_hs,
                  nb_equip_maintenance,nb_interventions,observations,actions_preventives)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                [$user['id'],$site_id,$date_rapport,$nb_ok,$nb_hs,$nb_maint,$nb_interv,$observations,$actions_prev]
            );
            $new_id = (int)db_last_id();
            audit_log($user['id'],'CREATE','interventions',$new_id,"Rapport journalier info site:$site_id $date_rapport");
            json_response(true,'Rapport enregistré.',['id'=>$new_id,'mode'=>'create']);
        }
    }

    // ── CHARGER UN RAPPORT EXISTANT + STATS ÉQUIPEMENTS
    if ($action === 'charger') {
        $site_id      = $site_force ?: (int)($_POST['site_id'] ?? 0);
        $date_rapport = trim($_POST['date_rapport'] ?? date('Y-m-d'));

        $rapport = db_fetch_one(
            "SELECT r.*, CONCAT(u.prenom,' ',u.nom) AS technicien_nom
             FROM rapports_journaliers_info r
             LEFT JOIN users u ON u.id=r.technicien_id
             WHERE r.site_id=? AND r.date_rapport=?",
            [$site_id, $date_rapport]
        );

        // Stats réelles équipements info du site
        $stats = db_fetch_one(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN etat IN ('neuf','bon') THEN 1 ELSE 0 END) AS ok,
               SUM(CASE WHEN etat='hs' THEN 1 ELSE 0 END) AS hs,
               SUM(CASE WHEN etat='maintenance' THEN 1 ELSE 0 END) AS en_maint
             FROM equipements
             WHERE actif=1 AND categorie='informatique' AND site_id=?",
            [$site_id]
        );

        // Interventions du jour sur ce site
        $interv_jour = db_fetch_all(
            "SELECT im.type_action, im.description, im.statut_apres,
                    e.numero_serie_interne, n.libelle AS type_equip
             FROM interventions_maintenance im
             LEFT JOIN equipements e ON e.id=im.equipement_id
             LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
             WHERE im.site_id=? AND im.date_intervention=? AND im.technicien_id=?
             ORDER BY im.id DESC",
            [$site_id, $date_rapport, $user['id']]
        );

        json_response(true,'',['rapport'=>$rapport,'stats'=>$stats,'interventions'=>$interv_jour]);
    }

    // ── VALIDER
    if ($action === 'valider') {
        $id = (int)($_POST['rapport_id'] ?? 0);
        db_query("UPDATE rapports_journaliers_info SET statut='valide',valide_par=?,valide_at=NOW() WHERE id=?",
            [$user['id'],$id]);
        audit_log($user['id'],'UPDATE','interventions',$id,"Validation rapport journalier info #$id");
        json_response(true,'Rapport validé.');
    }

    json_response(false,'Action inconnue.');
}

// ============================================================
//  DONNÉES LISTE
// ============================================================
$f_site  = $site_force ?: (int)($_GET['site'] ?? 0);
$f_mois  = trim($_GET['mois'] ?? date('Y-m'));

$rapports = db_fetch_all(
    "SELECT r.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS technicien_nom
     FROM rapports_journaliers_info r
     JOIN sites s ON s.id=r.site_id
     LEFT JOIN users u ON u.id=r.technicien_id
     WHERE DATE_FORMAT(r.date_rapport,'%Y-%m')=?
       AND (? = 0 OR r.site_id=?)
     ORDER BY r.date_rapport DESC",
    [$f_mois, $f_site, $f_site]
);

// KPIs
$where_kpi = $site_force ? "AND site_id=$site_force" : '';
$kpi_total     = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 AND categorie='informatique' $where_kpi");
$kpi_hs        = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 AND categorie='informatique' AND etat='hs' $where_kpi");
$kpi_maint     = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 AND categorie='informatique' AND etat='maintenance' $where_kpi");
$kpi_interv    = (int)db_fetch_value("SELECT COUNT(*) FROM interventions_maintenance WHERE DATE_FORMAT(date_intervention,'%Y-%m')=? AND technicien_id=?", [date('Y-m'),$user['id']]);

include __DIR__ . '/../templates/header.php';
?>
<style>
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.kpi{background:white;border:1px solid var(--border);border-radius:12px;padding:16px 18px;border-left:4px solid var(--blue-light)}
.kpi.warn{border-left-color:#e74c3c}
.kpi.orange{border-left-color:#f39c12}
.kpi.ok{border-left-color:var(--success)}
.kpi-val{font-family:'Montserrat',sans-serif;font-size:28px;font-weight:800;color:var(--navy);line-height:1}
.kpi-lbl{font-size:12px;color:var(--muted);margin-top:5px}
.fsel{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none;font-family:'DM Sans',sans-serif;cursor:pointer;color:var(--text)}
.rbadge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.rbadge.valide{background:#d5f5e3;color:#1e7e40}
.rbadge.brouillon{background:#fef9e7;color:#9a6c00}
.statut-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600}
.statut-pill.resolu{background:#d5f5e3;color:#1e7e40}
.statut-pill.partiel{background:#fef9e7;color:#9a6c00}
.statut-pill.en_attente{background:#fdedec;color:#c0392b}
</style>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi">
    <div class="kpi-val"><?= $kpi_total ?></div>
    <div class="kpi-lbl">Équipements informatiques</div>
  </div>
  <div class="kpi ok">
    <div class="kpi-val" style="color:var(--success-d)"><?= $kpi_total - $kpi_hs - $kpi_maint ?></div>
    <div class="kpi-lbl">Opérationnels</div>
  </div>
  <div class="kpi warn">
    <div class="kpi-val" style="color:#e74c3c"><?= $kpi_hs ?></div>
    <div class="kpi-lbl">Hors service</div>
  </div>
  <div class="kpi orange">
    <div class="kpi-val" style="color:#f39c12"><?= $kpi_interv ?></div>
    <div class="kpi-lbl">Interventions ce mois</div>
  </div>
</div>

<!-- TOOLBAR -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <?php if(!$site_force): ?>
    <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()">
      <option value="0">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site===$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <span style="padding:9px 14px;background:var(--lighter);border-radius:9px;font-size:13px;font-weight:600;color:var(--navy)">
      <i class="ph ph-map-pin" aria-hidden="true"></i> <?= h(db_fetch_value("SELECT nom FROM sites WHERE id=?",[$site_force]) ?? '') ?>
    </span>
    <?php endif; ?>
    <input type="month" name="mois" value="<?= h($f_mois) ?>" onchange="this.form.submit()" aria-label="Choisir le mois"
           style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
  </form>
  <?php if(in_array($role_slug,['maintenance_info','admin','superadmin'])): ?>
  <button class="btn btn-primary" onclick="ouvrirForm()">+ Nouveau rapport</button>
  <?php endif; ?>
</div>

<!-- LISTE -->
<div class="card">
  <div class="card-header">
    <h3><i class="ph ph-clipboard-text" aria-hidden="true"></i> Rapports journaliers <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($rapports) ?>)</span></h3>
  </div>
  <div class="table-wrap">
    <table data-paginer="25">
      <thead><tr>
        <th>Date</th>
        <th>Site</th>
        <th style="text-align:center">Opérationnels</th>
        <th style="text-align:center">Hors service</th>
        <th style="text-align:center">En maintenance</th>
        <th style="text-align:center">Interventions</th>
        <th>Observations</th>
        <th>Actions prévues</th>
        <th>Statut</th>
        <th>Technicien</th>
        <th style="text-align:center">Actions</th>
      </tr></thead>
      <tbody>
      <?php if(empty($rapports)): ?>
        <tr><td colspan="11" style="text-align:center;padding:40px;color:var(--muted)">Aucun rapport ce mois.</td></tr>
      <?php else: foreach($rapports as $r): ?>
        <tr>
          <td style="font-weight:700;white-space:nowrap"><?= fmt_date($r['date_rapport'],'d/m/Y') ?></td>
          <td><?= h($r['site_nom']) ?></td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:800;color:var(--success-d)"><?= $r['nb_equip_ok'] ?></td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:800;color:<?= $r['nb_equip_hs']>0?'#e74c3c':'var(--muted)' ?>"><?= $r['nb_equip_hs'] ?></td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:800;color:<?= $r['nb_equip_maintenance']>0?'#f39c12':'var(--muted)' ?>"><?= $r['nb_equip_maintenance'] ?></td>
          <td style="text-align:center;font-family:'Montserrat',sans-serif;font-weight:700;color:var(--blue)"><?= $r['nb_interventions'] ?></td>
          <td style="font-size:12.5px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['observations']) ?>"><?= h($r['observations']??'—') ?></td>
          <td style="font-size:12.5px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['actions_preventives']) ?>"><?= h($r['actions_preventives']??'—') ?></td>
          <td><span class="rbadge <?= $r['statut']??'brouillon' ?>"><?= ($r['statut']??'brouillon')==='valide'?'<i class="ph ph-check-circle" aria-hidden="true"></i> Validé':'⏳ Brouillon' ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= h($r['technicien_nom']??'—') ?></td>
          <td style="text-align:center;white-space:nowrap;display:flex;gap:4px;justify-content:center">
            <button class="btn btn-secondary btn-sm" onclick="editer(<?= $r['id'] ?>,<?= $r['site_id'] ?>,'<?= $r['date_rapport'] ?>')" title="Modifier"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
            <?php if(($r['statut']??'brouillon')==='brouillon' && in_array($role_slug,['admin','superadmin'])): ?>
            <button class="btn btn-success btn-sm" onclick="valider(<?= $r['id'] ?>)" title="Valider"><i class="ph ph-check-circle" aria-hidden="true"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:flex-start;justify-content:center;padding:30px;overflow-y:auto">
  <div style="background:white;border-radius:16px;padding:28px;width:620px;max-width:95vw;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--navy)"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Rapport journalier informatique</h3>
      <button onclick="fermer()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted)"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>

    <div id="mAlert"></div>

    <!-- Stats temps réel -->
    <div id="statsBox" style="display:none;background:var(--lighter);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;border-left:3px solid var(--blue-light)">
      <div style="font-weight:700;color:var(--navy);margin-bottom:6px"><i class="ph ph-chart-bar" aria-hidden="true"></i> État actuel des équipements informatiques</div>
      <div style="display:flex;gap:20px;flex-wrap:wrap">
        <span>Total : <strong id="sTotal" style="color:var(--navy)">—</strong></span>
        <span style="color:var(--success-d)">OK : <strong id="sOk">—</strong></span>
        <span style="color:#e74c3c">H.S : <strong id="sHs">—</strong></span>
        <span style="color:#f39c12">Maintenance : <strong id="sMaint">—</strong></span>
      </div>
    </div>

    <!-- Interventions du jour -->
    <div id="intervBox" style="display:none;margin-bottom:16px">
      <div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:6px"><i class="ph ph-wrench" aria-hidden="true"></i> Interventions enregistrées ce jour</div>
      <div id="intervList" style="font-size:12.5px;color:var(--muted)"></div>
    </div>

    <input type="hidden" id="fId">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
      <?php if(!$site_force): ?>
      <div class="form-group">
        <label>Site *</label>
        <select class="form-control" id="fSite" onchange="charger()">
          <option value="">— Sélectionner —</option>
          <?php foreach($sites_list as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" id="fSite" value="<?= $site_force ?>">
      <div class="form-group">
        <label>Site</label>
        <div style="padding:10px 12px;background:var(--lighter);border-radius:8px;font-weight:600;color:var(--navy)">
          <?= h(db_fetch_value("SELECT nom FROM sites WHERE id=?",[$site_force]) ?? '') ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Date *</label>
        <input type="date" class="form-control" id="fDate" value="<?= date('Y-m-d') ?>" onchange="charger()">
      </div>
    </div>

    <div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:10px">État des équipements au moment du rapport</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:16px">
      <div class="form-group">
        <label>Opérationnels</label>
        <input type="number" class="form-control" id="fOk" min="0" value="0">
      </div>
      <div class="form-group">
        <label>Hors service</label>
        <input type="number" class="form-control" id="fHs" min="0" value="0">
      </div>
      <div class="form-group">
        <label>En maintenance</label>
        <input type="number" class="form-control" id="fMaint" min="0" value="0">
      </div>
      <div class="form-group">
        <label>Interventions</label>
        <input type="number" class="form-control" id="fInterv" min="0" value="0">
      </div>
    </div>

    <div class="form-group" style="margin-bottom:14px">
      <label>Observations / Problèmes constatés</label>
      <textarea class="form-control" id="fObs" rows="3" placeholder="État général, anomalies, pannes détectées..."></textarea>
    </div>
    <div class="form-group" style="margin-bottom:22px">
      <label>Actions préventives planifiées</label>
      <textarea class="form-control" id="fPrev" rows="2" placeholder="Maintenances à programmer, pièces à commander..."></textarea>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer()">Annuler</button>
      <button class="btn btn-primary" id="btnSave" onclick="sauver()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer</button>
    </div>
  </div>
</div>

<script>
function ap(data){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)}).then(r=>r.json());}
function toast(msg,type='success'){let t=document.getElementById('toast-live');if(!t){t=document.createElement('div');t.id='toast-live';t.setAttribute('role','status');t.setAttribute('aria-live','polite');t.setAttribute('aria-atomic','true');document.body.appendChild(t);}clearTimeout(t._hideTimer);t.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);background:${type==='success'?'#27ae60':'#e74c3c'};color:white;max-width:380px`;t.textContent=msg;t._hideTimer=setTimeout(()=>{t.style.display='none';},3500);}

function getSite(){const el=document.getElementById('fSite');return el.tagName==='SELECT'?el.value:(el.value||el.getAttribute('value'));}

function ouvrirForm(){
  document.getElementById('fId').value='';
  document.getElementById('fDate').value='<?= date('Y-m-d') ?>';
  ['fOk','fHs','fMaint','fInterv'].forEach(id=>document.getElementById(id).value='0');
  document.getElementById('fObs').value='';
  document.getElementById('fPrev').value='';
  document.getElementById('mAlert').innerHTML='';
  document.getElementById('statsBox').style.display='none';
  document.getElementById('intervBox').style.display='none';
  document.getElementById('modal').style.display='flex';
  <?php if($site_force): ?>charger();<?php endif; ?>
}

function editer(id,siteId,date){
  if(document.getElementById('fSite').tagName==='SELECT') document.getElementById('fSite').value=siteId;
  document.getElementById('fDate').value=date;
  document.getElementById('fId').value=id;
  document.getElementById('mAlert').innerHTML='';
  document.getElementById('modal').style.display='flex';
  charger();
}

function fermer(){document.getElementById('modal').style.display='none';}
document.getElementById('modal').addEventListener('click',function(e){if(e.target===this)fermer();});

async function charger(){
  const site=getSite();
  const date=document.getElementById('fDate').value;
  if(!site||!date) return;
  const d=await ap({action:'charger',site_id:site,date_rapport:date});
  if(!d.success) return;

  // Afficher stats équipements
  const s=d.data.stats||{};
  document.getElementById('sTotal').textContent=s.total||0;
  document.getElementById('sOk').textContent=s.ok||0;
  document.getElementById('sHs').textContent=s.hs||0;
  document.getElementById('sMaint').textContent=s.en_maint||0;
  document.getElementById('statsBox').style.display='block';

  // Pré-remplir si rapport existant
  if(d.data.rapport){
    document.getElementById('fId').value=d.data.rapport.id;
    document.getElementById('fOk').value=d.data.rapport.nb_equip_ok||0;
    document.getElementById('fHs').value=d.data.rapport.nb_equip_hs||0;
    document.getElementById('fMaint').value=d.data.rapport.nb_equip_maintenance||0;
    document.getElementById('fInterv').value=d.data.rapport.nb_interventions||0;
    document.getElementById('fObs').value=d.data.rapport.observations||'';
    document.getElementById('fPrev').value=d.data.rapport.actions_preventives||'';
  } else {
    // Pré-remplir avec les stats réelles
    document.getElementById('fOk').value=s.ok||0;
    document.getElementById('fHs').value=s.hs||0;
    document.getElementById('fMaint').value=s.en_maint||0;
  }

  // Interventions du jour
  if(d.data.interventions && d.data.interventions.length){
    const types={'maintenance_preventive':'Prév.','maintenance_corrective':'Corr.','installation':'Install.','remplacement':'Remplac.','diagnostic':'Diag.','autre':'Autre'};
    const statuts={'resolu':'✅','partiel':'⚠️','en_attente':'❌','escalade':'🔺'};
    let html='<div style="background:#f8fafc;border-radius:8px;padding:10px 12px;max-height:120px;overflow-y:auto">';
    d.data.interventions.forEach(iv=>{
      html+=`<div style="padding:4px 0;border-bottom:1px solid #e2e8f0;font-size:12px">
        ${statuts[iv.statut_apres]||''} <strong>${types[iv.type_action]||iv.type_action}</strong>
        ${iv.numero_serie_interne?' — '+iv.numero_serie_interne+' ('+iv.type_equip+')':''}
        <span style="color:#94a3b8"> · ${iv.description?.substring(0,60)||''}</span>
      </div>`;
    });
    html+='</div>';
    document.getElementById('fInterv').value=d.data.interventions.length;
    document.getElementById('intervList').innerHTML=html;
    document.getElementById('intervBox').style.display='block';
  } else {
    document.getElementById('intervBox').style.display='none';
  }
}

async function sauver(){
  const site=getSite();
  const date=document.getElementById('fDate').value;
  if(!site||!date){document.getElementById('mAlert').innerHTML='<div class="alert alert-danger">Site et date obligatoires.</div>';return;}
  const btn=document.getElementById('btnSave');btn.disabled=true;btn.textContent='⏳...';
  const d=await ap({
    action:'sauver',
    site_id:site,
    date_rapport:date,
    nb_ok:document.getElementById('fOk').value||0,
    nb_hs:document.getElementById('fHs').value||0,
    nb_maint:document.getElementById('fMaint').value||0,
    nb_interv:document.getElementById('fInterv').value||0,
    observations:document.getElementById('fObs').value,
    actions_prev:document.getElementById('fPrev').value
  });
  btn.disabled=false;btn.textContent='💾 Enregistrer';
  if(d.success){toast(d.message);fermer();setTimeout(()=>location.reload(),900);}
  else document.getElementById('mAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}

async function valider(id){
  if(!confirm('Valider ce rapport ?')) return;
  const d=await ap({action:'valider',rapport_id:id});
  if(d.success){toast(d.message);setTimeout(()=>location.reload(),800);}
  else alert('Erreur : '+d.message);
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
