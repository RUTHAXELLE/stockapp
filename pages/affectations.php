<?php
// ============================================================
//  pages/affectations.php  —  Nouvelle affectation / transfert
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/upload.php';

require_auth();
require_permission('affectations', 'can_create');

$user        = current_user();
$page_title  = 'Affectations';
$active_page = 'affectations';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── AFFECTER / TRANSFÉRER UN ÉQUIPEMENT
    if ($action === 'affecter') {
        require_permission('affectations', 'can_create');
        $equip_id   = (int)($_POST['equipement_id'] ?? 0);
        $site_id    = (int)($_POST['site_id']        ?? 0) ?: null;
        $user_id    = (int)($_POST['utilisateur_id'] ?? 0) ?: null;
        $notes      = trim($_POST['notes']            ?? '');
        $type_mouv  = trim($_POST['type_mouvement']   ?? 'transfert');

        if (!$equip_id) json_response(false, 'Équipement obligatoire.');

        $equip = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1", [$equip_id]);
        if (!$equip) json_response(false, 'Équipement introuvable ou inactif.');

        // ── UPLOAD BON DE LIVRAISON OBLIGATOIRE pour transfert/sortie vers un site
        $fichier_bl = null;
        if ($site_id && in_array($type_mouv, ['transfert', 'sortie'])) {
            $upload_bl = upload_document('fichier_bl', 'bl', 'bl_equip_' . $equip_id, true);
            if (!$upload_bl['success']) json_response(false, $upload_bl['message']);
            $fichier_bl = $upload_bl['filename'];
        }

        db_begin();
        try {
            // Enregistrer le mouvement
            db_query(
                "INSERT INTO mouvements_equipements
                 (equipement_id,type,site_source_id,site_dest_id,user_dest_id,notes,fichier_bl,created_by)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$equip_id, $type_mouv, $equip['site_id'], $site_id, $user_id, $notes, $fichier_bl, $user['id']]
            );
            // Mettre à jour l'équipement
            db_query(
                "UPDATE equipements SET site_id=?, utilisateur_id=? WHERE id=?",
                [$site_id, $user_id, $equip_id]
            );

            // Créer réception_site en_attente si livraison vers un site
            if ($site_id && $type_mouv === 'transfert') {
                db_query(
                    "INSERT INTO receptions_site (site_id,type_reception,equipement_id,mouvement_ref_id,date_reception,statut,created_by)
                     VALUES (?,?,?,?,CURRENT_DATE,?,?)",
                    [$site_id, 'equipement', $equip_id, db_last_id(), 'en_attente', $user['id']]
                );
            }

            audit_log($user['id'], 'TRANSFER', 'affectations', $equip_id,
                "Affectation {$equip['numero_serie_interne']} → site:$site_id user:$user_id" . ($fichier_bl ? " (BL:$fichier_bl)" : ''));
            db_commit();
            json_response(true, 'Affectation enregistrée.');
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    // ── RECHERCHER ÉQUIPEMENTS (pour autocomplete)
    if ($action === 'search_equip') {
        $q  = trim($_POST['q'] ?? '');
        if (strlen($q) < 2) json_response(true, '', []);
        $rows = db_fetch_all(
            "SELECT e.id, e.numero_serie_interne, e.etat, COALESCE(n.libelle,'—') AS type,
                    s.nom AS site_actuel, CONCAT(u.prenom,' ',u.nom) AS user_actuel
             FROM equipements e
             LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
             LEFT JOIN sites s ON s.id=e.site_id
             LEFT JOIN users u ON u.id=e.utilisateur_id
             WHERE e.actif=1 AND (e.numero_serie_interne ILIKE ? OR e.numero_serie_origine ILIKE ?)
             LIMIT 10",
            ["%$q%", "%$q%"]
        );
        json_response(true, '', $rows);
    }

    json_response(false, 'Action inconnue.');
}

// Équipements non affectés
$non_affectes_count = (int)db_fetch_value(
    "SELECT COUNT(*) FROM equipements WHERE actif=1 AND site_id IS NULL AND etat IN ('neuf','bon')"
);

$sites_list = db_fetch_all("SELECT id,nom,type FROM sites WHERE actif=1 ORDER BY nom");
$users_list = db_fetch_all("SELECT id,prenom,nom FROM users WHERE actif=1 ORDER BY prenom");

include __DIR__ . '/../templates/header.php';
?>
<style>
.aff-form-layout{display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start}
@media(max-width:900px){.aff-form-layout{grid-template-columns:minmax(0,1fr)}}

.autocomplete-wrap{position:relative}
.autocomplete-results{position:absolute;top:100%;left:0;right:0;background:white;border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:100;max-height:200px;overflow-y:auto;display:none}
.ac-item{padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border)}
.ac-item:last-child{border-bottom:none}
.ac-item:hover{background:var(--lighter)}
.ac-num{font-family:monospace;font-weight:700;color:var(--navy)}
.ac-meta{font-size:12px;color:var(--muted);margin-top:2px}
</style>

<div class="aff-form-layout">

  <!-- COLONNE GAUCHE : FORMULAIRE -->
  <div>
    <!-- FORMULAIRE AFFECTATION -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <h3><i class="ph ph-link" aria-hidden="true"></i> Nouvelle affectation / Transfert</h3>
      </div>
      <div class="card-body">
        <div id="affAlert"></div>

        <!-- Recherche équipement -->
        <div class="form-group">
          <label for="affSearch">Équipement * <span style="font-size:12px;color:var(--muted)">(tapez le N° série pour rechercher)</span></label>
          <div class="autocomplete-wrap">
            <input type="text" class="form-control" id="affSearch" placeholder="Tapez le numéro série…" autocomplete="off" oninput="searchEquip(this.value)">
            <div class="autocomplete-results" id="affResults"></div>
          </div>
          <input type="hidden" id="affEquipId">
          <div id="affEquipInfo" style="margin-top:8px;display:none">
            <div style="padding:10px;background:var(--lighter);border-radius:8px;font-size:13px" id="affEquipInfoContent"></div>
          </div>
        </div>

        <div class="form-row cols-2">
          <div class="form-group"><label for="affTypeMouv">Type de mouvement</label>
            <select class="form-control" id="affTypeMouv">
              <option value="entree">Entrée en stock</option>
              <option value="transfert" selected>Transfert / Affectation</option>
              <option value="maintenance">Maintenance</option>
            </select>
          </div>
          <div class="form-group"><label for="affSite">Site de destination</label>
            <select class="form-control" id="affSite">
              <option value="">— Stock central —</option>
              <?php foreach($sites_list as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?> (<?= $s['type'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group"><label for="affUser">Utilisateur assigné</label>
          <select class="form-control" id="affUser">
            <option value="">— Non assigné —</option>
            <?php foreach($users_list as $u): ?>
            <option value="<?= $u['id'] ?>"><?= h($u['prenom'].' '.$u['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group"><label for="affNotes">Notes</label>
          <textarea class="form-control" id="affNotes" rows="2" placeholder="Raison du transfert, remarques…"></textarea>
        </div>

        <!-- BON DE LIVRAISON OBLIGATOIRE pour transfert vers site -->
        <div id="affBLWrap" style="display:none;background:#fff8e7;border:2px dashed #f39c12;border-radius:10px;padding:14px;margin-bottom:14px">
          <label style="font-size:13px;font-weight:700;color:#b7791f;display:block;margin-bottom:6px">
            <i class="ph ph-paperclip" aria-hidden="true"></i> Bon de livraison <span style="color:var(--danger-d)">*</span>
            <span style="font-size:12px;font-weight:400;color:var(--muted)"> — obligatoire pour tout transfert vers un site</span>
          </label>
          <input type="file" id="affFichierBL" accept=".pdf,.jpg,.jpeg,.png,.webp"
                 style="width:100%;padding:8px;border:1.5px solid #f39c12;border-radius:8px;font-size:13px;background:white">
          <div id="affBLPreview" style="display:none;margin-top:6px;font-size:12px;color:var(--success-d);font-weight:600"></div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button class="btn btn-secondary" onclick="resetAff()">Réinitialiser</button>
          <button class="btn btn-primary" id="btnAff" onclick="saveAff()"><i class="ph ph-check-circle" aria-hidden="true"></i> Valider l'affectation</button>
        </div>
      </div>
    </div>
  </div>

  <!-- COLONNE DROITE : RÉSUMÉ -->
  <div>
    <div class="card">
      <div class="card-header"><h3><i class="ph ph-chart-bar" aria-hidden="true"></i> Résumé des affectations</h3></div>
      <div class="card-body" style="padding:16px">
        <?php
        $stats_sites = db_fetch_all(
            "SELECT s.nom, COUNT(e.id) AS nb
             FROM sites s JOIN equipements e ON e.site_id=s.id AND e.actif=1
             GROUP BY s.id ORDER BY nb DESC LIMIT 8"
        );
        foreach($stats_sites as $st): $pct=min(100,round($st['nb']/max(1,$kpi_equip_total??1)*100)); ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:3px">
            <span><?= h($st['nom']) ?></span>
            <strong><?= $st['nb'] ?></strong>
          </div>
          <div style="height:5px;background:var(--border);border-radius:3px">
            <div style="width:<?= $pct ?>%;height:100%;background:var(--blue-mid, #1a56a0);border-radius:3px"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);font-size:13px;color:var(--muted)">
          <i class="ph ph-check-circle" aria-hidden="true"></i> <strong><?= $non_affectes_count ?></strong> équipement(s) disponible(s) en stock
        </div>
      </div>
    </div>
  </div>

</div>

<script>
let searchTimer;
function escHtml(s){
  return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function searchEquip(q){
  clearTimeout(searchTimer);
  if(q.length<2){document.getElementById('affResults').style.display='none';return;}
  searchTimer=setTimeout(()=>{
    ap({action:'search_equip',q}).then(d=>{
      const res=document.getElementById('affResults');
      if(!d.success){res.style.display='none';document.getElementById('affAlert').innerHTML=`<div class="alert alert-danger">Erreur recherche : ${escHtml(d.message||'inconnue')}</div>`;return;}
      if(!d.data.length){res.style.display='none';return;}
      res.innerHTML=d.data.map(r=>{
        const site=r.site_actuel||'Stock', usr=r.user_actuel||'Non assigné';
        // data-* + délégation d'événement : évite tout onclick="..." avec des
        // valeurs interpolées (un nom avec apostrophe, ex. "N'Guessan",
        // cassait l'attribut inline et rendait le clic silencieusement inopérant).
        return `<div class="ac-item" data-id="${r.id}" data-num="${escHtml(r.numero_serie_interne)}" data-type="${escHtml(r.type)}" data-site="${escHtml(site)}" data-user="${escHtml(usr)}" data-etat="${escHtml(r.etat)}">
          <div class="ac-num">${escHtml(r.numero_serie_interne)} <span style="font-weight:400;color:var(--muted)">· ${escHtml(r.type)}</span></div>
          <div class="ac-meta">📍 ${escHtml(site)} · 👤 ${escHtml(usr)} · ${escHtml(r.etat)}</div>
        </div>`;
      }).join('');
      res.style.display='block';
    }).catch(err=>{
      document.getElementById('affResults').style.display='none';
      document.getElementById('affAlert').innerHTML=`<div class="alert alert-danger">Erreur réseau lors de la recherche : ${escHtml(err.message)}</div>`;
    });
  },300);
}
document.getElementById('affResults').addEventListener('click',e=>{
  const item=e.target.closest('.ac-item');
  if(!item)return;
  selectEquip(item.dataset.id,item.dataset.num,item.dataset.type,item.dataset.site,item.dataset.user,item.dataset.etat);
});

function selectEquip(id,num,type,site,user,etat){
  document.getElementById('affEquipId').value=id;
  document.getElementById('affSearch').value=num;
  document.getElementById('affResults').style.display='none';
  document.getElementById('affEquipInfo').style.display='block';
  document.getElementById('affEquipInfoContent').innerHTML=`
    <strong>${escHtml(num)}</strong> · ${escHtml(type)}<br>
    <i class="ph ph-map-pin" aria-hidden="true"></i> Actuellement : <strong>${escHtml(site)}</strong> · <i class="ph ph-user" aria-hidden="true"></i> ${escHtml(user)} · État : <strong>${escHtml(etat)}</strong>`;
}

function resetAff(){
  document.getElementById('affSearch').value=''; document.getElementById('affEquipId').value='';
  document.getElementById('affEquipInfo').style.display='none'; document.getElementById('affResults').style.display='none';
  document.getElementById('affSite').value=''; document.getElementById('affUser').value='';
  document.getElementById('affNotes').value=''; document.getElementById('affTypeMouv').value='transfert';
  document.getElementById('affAlert').innerHTML='';
  document.getElementById('affFichierBL').value='';
  document.getElementById('affBLPreview').style.display='none';
  document.getElementById('affBLWrap').style.display='none';
  document.getElementById('btnAff').disabled=false;
}

// Afficher/masquer le champ BL selon le type de mouvement et le site
function toggleBLField(){
  const type = document.getElementById('affTypeMouv').value;
  const site = document.getElementById('affSite').value;
  const wrap = document.getElementById('affBLWrap');
  wrap.style.display = (site && (type==='transfert'||type==='sortie')) ? 'block' : 'none';
}
document.getElementById('affTypeMouv').addEventListener('change', toggleBLField);
document.getElementById('affSite').addEventListener('change', toggleBLField);

document.getElementById('affFichierBL').addEventListener('change',function(){
  const prev=document.getElementById('affBLPreview');
  if(this.files.length){
    prev.style.display='block';
    prev.textContent='✔ '+this.files[0].name+' ('+(this.files[0].size/1024).toFixed(1)+' Ko)';
  } else { prev.style.display='none'; }
});

async function saveAff(){
  const eid  = document.getElementById('affEquipId').value;
  const site = document.getElementById('affSite').value;
  const type = document.getElementById('affTypeMouv').value;
  if(!eid){document.getElementById('affAlert').innerHTML='<div class="alert alert-danger">Sélectionnez un équipement.</div>';return;}

  // Vérifier BL si transfert vers site
  const fichierBL = document.getElementById('affFichierBL').files[0];
  if(site && (type==='transfert'||type==='sortie') && !fichierBL){
    document.getElementById('affAlert').innerHTML='<div class="alert alert-danger"><i class="ph ph-warning" aria-hidden="true"></i> Le bon de livraison est obligatoire pour un transfert vers un site.</div>';
    document.getElementById('affFichierBL').focus(); return;
  }

  const btn = document.getElementById('btnAff');
  btn.disabled=true; btn.textContent='⏳ En cours…';

  const fd = new FormData();
  fd.append('action','affecter');
  fd.append('equipement_id',eid);
  fd.append('site_id',site);
  fd.append('utilisateur_id',document.getElementById('affUser').value);
  fd.append('notes',document.getElementById('affNotes').value);
  fd.append('type_mouvement',type);
  if(fichierBL) fd.append('fichier_bl',fichierBL);

  try {
    const r = await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const d = await r.json();
    btn.disabled=false; btn.textContent='✅ Valider l\'affectation';
    if(d.success){toast(d.message,'success');resetAff();setTimeout(()=>location.reload(),1000);}
    else document.getElementById('affAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
  } catch(e){
    btn.disabled=false; btn.textContent='✅ Valider l\'affectation';
    document.getElementById('affAlert').innerHTML='<div class="alert alert-danger">Erreur réseau.</div>';
  }
}

// Fermer dropdown au clic dehors
document.addEventListener('click',e=>{
  if(!e.target.closest('.autocomplete-wrap'))document.getElementById('affResults').style.display='none';
});

function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
}
</script>

<?php
// KPI pour les stats (si pas encore défini)
$kpi_equip_total = $kpi_equip_total ?? (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1");
include __DIR__ . '/../templates/footer.php';
?>
