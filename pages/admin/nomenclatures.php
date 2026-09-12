<?php
// ============================================================
//  pages/admin/nomenclatures.php  —  Types d'équipements & liaisons
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_auth();
require_permission('nomenclatures','can_read');

$user        = current_user();
$page_title  = 'Nomenclatures';
$active_page = 'nomenclatures';

if ($_SERVER['REQUEST_METHOD']==='POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action==='create') {
        require_permission('nomenclatures','can_create');
        $code      = strtoupper(trim($_POST['code']    ?? ''));
        $libelle   = trim($_POST['libelle']  ?? '');
        $desc      = trim($_POST['desc']     ?? '');
        $duree     = (int)($_POST['duree']   ?? 0);
        $seuil     = (int)($_POST['seuil']   ?? 5);
        $categorie = trim($_POST['categorie'] ?? 'informatique');
        if (!in_array($categorie, ['informatique','operationnel'], true)) $categorie = 'informatique';
        if (!$code || !$libelle) json_response(false,'Code et libellé obligatoires.');
        if (db_fetch_value("SELECT COUNT(*) FROM nomenclatures WHERE code=?",[$code])>0)
            json_response(false,"Le code $code existe déjà.");
        db_query("INSERT INTO nomenclatures (code,libelle,description,duree_vie_mois,seuil_alerte,categorie) VALUES (?,?,?,?,?,?)",
            [$code,$libelle,$desc,$duree?:null,$seuil,$categorie]);
        $id=(int)db_last_id();
        audit_log($user['id'],'CREATE','nomenclatures',$id,"Création nomenclature $code");
        json_response(true,'Nomenclature créée.',['id'=>$id]);
    }

    if ($action==='update') {
        require_permission('nomenclatures','can_update');
        $id        = (int)($_POST['id']      ?? 0);
        $libelle   = trim($_POST['libelle']  ?? '');
        $desc      = trim($_POST['desc']     ?? '');
        $duree     = (int)($_POST['duree']   ?? 0);
        $seuil     = (int)($_POST['seuil']   ?? 5);
        $categorie = trim($_POST['categorie'] ?? 'informatique');
        if (!in_array($categorie, ['informatique','operationnel'], true)) $categorie = 'informatique';
        if (!$libelle) json_response(false,'Libellé obligatoire.');
        $old=db_fetch_one("SELECT * FROM nomenclatures WHERE id=?",[$id]);
        db_query("UPDATE nomenclatures SET libelle=?,description=?,duree_vie_mois=?,seuil_alerte=?,categorie=? WHERE id=?",
            [$libelle,$desc,$duree?:null,$seuil,$categorie,$id]);
        audit_log($user['id'],'UPDATE','nomenclatures',$id,"Modification {$old['code']}",$old);
        json_response(true,'Nomenclature mise à jour.');
    }

    if ($action==='delete') {
        require_permission('nomenclatures','can_delete');
        $id  = (int)($_POST['id'] ?? 0);
        $cnt = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE nomenclature_id=? AND actif=1",[$id]);
        if ($cnt>0) json_response(false,"Impossible : $cnt équipement(s) actif(s) utilisent cette nomenclature.");
        $old=db_fetch_one("SELECT code FROM nomenclatures WHERE id=?",[$id]);
        db_query("DELETE FROM nomenclatures WHERE id=?",[$id]);
        audit_log($user['id'],'DELETE','nomenclatures',$id,"Suppression {$old['code']}");
        json_response(true,'Nomenclature supprimée.');
    }

    if ($action==='add_lien') {
        require_permission('nomenclatures','can_update');
        $parent=(int)($_POST['parent_id']??0);
        $enfant=(int)($_POST['enfant_id']??0);
        $qte   =(int)($_POST['quantite'] ??1);
        if ($parent===$enfant) json_response(false,'Liaison circulaire non autorisée.');
        if (db_fetch_value("SELECT COUNT(*) FROM nomenclature_liens WHERE parent_id=? AND enfant_id=?",[$parent,$enfant])>0)
            json_response(false,'Ce lien existe déjà.');
        db_query("INSERT INTO nomenclature_liens (parent_id,enfant_id,quantite) VALUES (?,?,?)",[$parent,$enfant,$qte]);
        audit_log($user['id'],'CREATE','nomenclatures',$parent,"Lien composition ajouté");
        json_response(true,'Composant ajouté.');
    }

    if ($action==='del_lien') {
        require_permission('nomenclatures','can_update');
        $id=(int)($_POST['id']??0);
        db_query("DELETE FROM nomenclature_liens WHERE id=?",[$id]);
        audit_log($user['id'],'DELETE','nomenclatures',$id,"Lien composition supprimé");
        json_response(true,'Composant supprimé.');
    }

    if ($action==='get') {
        $id  = (int)($_POST['id']??0);
        $row = db_fetch_one("SELECT * FROM nomenclatures WHERE id=?",[$id]);
        if (!$row) json_response(false,'Introuvable.');
        $row['liens'] = db_fetch_all(
            "SELECT nl.id,nl.quantite,n.code,n.libelle
             FROM nomenclature_liens nl JOIN nomenclatures n ON n.id=nl.enfant_id
             WHERE nl.parent_id=?",[$id]);
        $row['stock_total'] = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE nomenclature_id=? AND actif=1",[$id]);
        json_response(true,'',$row);
    }

    json_response(false,'Action inconnue.');
}

$noms = db_fetch_all(
    "SELECT n.*, COUNT(e.id) AS stock_total
     FROM nomenclatures n
     LEFT JOIN equipements e ON e.nomenclature_id=n.id AND e.actif=1
     GROUP BY n.id ORDER BY n.libelle"
);
$noms_json = json_encode(array_map(fn($n)=>['id'=>$n['id'],'code'=>$n['code'],'libelle'=>$n['libelle']],$noms));

include __DIR__ . '/../../templates/header.php';
?>
<style>
.nom-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px}
.nom-card{background:white;border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:box-shadow .2s}
.nom-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08)}
.nom-card-top{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.nom-code{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--navy),#2563a8);display:flex;align-items:center;justify-content:center;font-family:'Plus Jakarta Sans',sans-serif;font-size:12px;font-weight:800;color:white;letter-spacing:.5px;flex-shrink:0}
.nom-info h4{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:var(--navy)}
.nom-info span{font-size:12px;color:var(--muted)}
.nom-stats{display:flex}
.nom-stat{flex:1;padding:12px;text-align:center;border-right:1px solid var(--border)}
.nom-stat:last-child{border-right:none}
.nom-stat .sv{font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:800;color:var(--navy)}
.nom-stat .sl{font-size:12px;color:var(--muted);margin-top:2px}
.nom-actions{padding:10px 18px;display:flex;gap:6px;background:var(--lighter);flex-wrap:wrap}
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,31,53,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;width:620px;max-width:95vw;max-height:92vh;overflow-y:auto;animation:mIn .25s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:700}
.mclose{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.mbody{padding:24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}
.lien-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.lien-item:last-child{border-bottom:none}
.lien-badge{background:var(--lighter);border:1px solid var(--border);border-radius:6px;padding:3px 10px;font-size:12px;font-weight:700}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <span style="font-size:13.5px;color:var(--muted)"><?= count($noms) ?> type(s) configuré(s)</span>
  <?php if(can('nomenclatures','can_create')): ?>
  <button class="btn btn-primary" onclick="openMN()">+ Nouvelle nomenclature</button>
  <?php endif; ?>
</div>

<div class="nom-grid">
<?php foreach($noms as $n):
  $lc=(int)db_fetch_value("SELECT COUNT(*) FROM nomenclature_liens WHERE parent_id=?",[$n['id']]);
?>
<div class="nom-card">
  <div class="nom-card-top">
    <div class="nom-code"><?= h($n['code']) ?></div>
    <div class="nom-info">
      <h4><?= h($n['libelle']) ?>
        <span style="font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:8px;margin-left:6px;vertical-align:middle;background:<?= $n['categorie']==='operationnel'?'#FEF3C7':'#DBEAFE' ?>;color:<?= $n['categorie']==='operationnel'?'#92400E':'#1D4ED8' ?>">
          <?= $n['categorie']==='operationnel'?'Opérationnel':'Informatique' ?>
        </span>
      </h4>
      <span><?= $n['description'] ? h(substr($n['description'],0,55)).'…' : 'Aucune description' ?></span>
    </div>
  </div>
  <div class="nom-stats">
    <div class="nom-stat"><div class="sv"><?= $n['stock_total'] ?></div><div class="sl">En stock</div></div>
    <div class="nom-stat"><div class="sv"><?= $n['duree_vie_mois']?$n['duree_vie_mois'].'m':'∞' ?></div><div class="sl">Durée vie</div></div>
    <div class="nom-stat"><div class="sv"><?= $lc ?></div><div class="sl">Composants</div></div>
    <div class="nom-stat"><div class="sv"><?= $n['seuil_alerte'] ?></div><div class="sl">Seuil alerte</div></div>
  </div>
  <div class="nom-actions">
    <button class="btn btn-secondary btn-sm" onclick="viewN(<?= $n['id'] ?>)"><i class="ph ph-eye" aria-hidden="true"></i> Détail & Liens</button>
    <?php if(can('nomenclatures','can_update')): ?>
    <button class="btn btn-secondary btn-sm" onclick="editN(<?= $n['id'] ?>)"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
    <?php endif; ?>
    <?php if(can('nomenclatures','can_delete')&&$n['stock_total']==0): ?>
    <button class="btn btn-danger btn-sm" onclick="delN(<?= $n['id'] ?>,'<?= h($n['code']) ?>')"><i class="ph ph-trash" aria-hidden="true"></i></button>
    <?php endif; ?>
    <a href="../equipements.php?nom=<?= $n['id'] ?>" class="btn btn-secondary btn-sm" style="margin-left:auto"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Stock</a>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($noms)): ?>
<div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--muted)">
  <div style="font-size:48px;margin-bottom:12px"><i class="ph ph-tag" aria-hidden="true"></i></div>
  <p>Aucune nomenclature. Créez votre premier type d'équipement.</p>
</div>
<?php endif; ?>
</div>

<!-- MODAL FORM -->
<div class="modal-overlay" id="mN">
  <div class="modal">
    <div class="mhdr"><h3 id="mNT">Nouvelle nomenclature</h3><button class="mclose" onclick="closeMN()"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <div id="mNAlert"></div>
      <input type="hidden" id="nId">
      <div class="form-row cols-2">
        <div class="form-group"><label>Code * <span style="font-size:12px;color:var(--muted)">(ex: CLV, ECR, UC)</span></label>
          <input type="text" class="form-control" id="nCode" placeholder="CLV" maxlength="10" oninput="this.value=this.value.toUpperCase()">
        </div>
        <div class="form-group"><label>Libellé *</label>
          <input type="text" class="form-control" id="nLib" placeholder="Clavier">
        </div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Catégorie *</label>
          <select class="form-control" id="nCategorie">
            <option value="informatique">Informatique</option>
            <option value="operationnel">Opérationnel</option>
          </select>
        </div>
        <div class="form-group"><label>Seuil d'alerte stock</label>
          <input type="number" class="form-control" id="nSeuil" min="0" value="5">
        </div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Durée de vie (mois) <span style="font-size:12px;color:var(--muted)">0 = illimitée</span></label>
          <input type="number" class="form-control" id="nDuree" min="0" placeholder="36">
        </div>
      </div>
      <div class="form-group"><label>Description</label>
        <textarea class="form-control" id="nDesc" rows="3" placeholder="Description…"></textarea>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="closeMN()">Annuler</button>
      <button class="btn btn-primary" id="bSN" onclick="saveN()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- MODAL DÉTAIL / COMPOSITION -->
<div class="modal-overlay" id="mND">
  <div class="modal" style="width:600px">
    <div class="mhdr"><h3 id="ndT">Détail</h3><button class="mclose" onclick="document.getElementById('mND').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody" id="ndB"></div>
  </div>
</div>

<script>
const NOMS_LIST = <?= $noms_json ?>;
const CAN_UPDATE = <?= can('nomenclatures','can_update')?'true':'false' ?>;

function openMN(){ resetNF(); document.getElementById('mN').classList.add('open'); }
function closeMN(){ document.getElementById('mN').classList.remove('open'); }
function resetNF(){
  ['nId','nCode','nLib','nDuree','nDesc'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('nSeuil').value='5'; document.getElementById('mNAlert').innerHTML='';
  document.getElementById('nCategorie').value='informatique';
  document.getElementById('mNT').textContent='Nouvelle nomenclature';
  document.getElementById('nCode').disabled=false;
}

function editN(id){
  ap({action:'get',id}).then(d=>{
    if(!d.success)return;
    const r=d.data;
    document.getElementById('mNT').textContent='Modifier : '+r.code;
    document.getElementById('nId').value=r.id; document.getElementById('nCode').value=r.code;
    document.getElementById('nCode').disabled=true; document.getElementById('nLib').value=r.libelle;
    document.getElementById('nDuree').value=r.duree_vie_mois||''; document.getElementById('nSeuil').value=r.seuil_alerte||5;
    document.getElementById('nDesc').value=r.description||'';
    document.getElementById('nCategorie').value=r.categorie||'informatique';
    document.getElementById('mN').classList.add('open');
  });
}

function viewN(id){
  document.getElementById('mND').classList.add('open');
  document.getElementById('ndB').innerHTML='<div style="text-align:center;padding:40px;color:var(--muted)">Chargement…</div>';
  ap({action:'get',id}).then(d=>{
    if(!d.success)return;
    const r=d.data;
    document.getElementById('ndT').textContent=r.code+' — '+r.libelle;
    const liens=r.liens.map(l=>`
      <div class="lien-item">
        <span class="lien-badge">${l.code}</span>
        <span style="flex:1;font-size:13px">${l.libelle}</span>
        <span style="font-size:12px;font-weight:700;color:var(--muted)">× ${l.quantite}</span>
        ${CAN_UPDATE?`<button class="btn btn-danger btn-sm" onclick="delLien(${l.id},${r.id})">✕</button>`:''}
      </div>`).join('');
    const opts=NOMS_LIST.filter(n=>n.id!=r.id).map(n=>`<option value="${n.id}">${n.code} — ${n.libelle}</option>`).join('');
    const addForm=CAN_UPDATE?`
      <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
        <select id="lEnfant" class="form-control" style="flex:2"><option value="">Choisir un composant…</option>${opts}</select>
        <input type="number" id="lQte" class="form-control" style="width:72px" value="1" min="1">
        <button class="btn btn-primary btn-sm" onclick="addLien(${r.id})">+ Ajouter</button>
      </div>`:'';
    document.getElementById('ndB').innerHTML=`
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 20px;margin-bottom:20px">
        ${[['Code',r.code],['Stock actif',r.stock_total+' équipement(s)'],
           ['Durée de vie',r.duree_vie_mois?r.duree_vie_mois+' mois':'Illimitée'],
           ['Seuil alerte stock',r.seuil_alerte],
           ['Description',r.description||'—']
          ].map(([l,v])=>`<div style="padding:7px 0;border-bottom:1px solid var(--border)">
            <div style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">${l}</div>
            <div style="font-size:13.5px">${v}</div></div>`).join('')}
      </div>
      <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;margin-bottom:10px"><i class="ph ph-link" aria-hidden="true"></i> Composition du poste</h4>
      <p style="font-size:12px;color:var(--muted);margin-bottom:10px">Définissez les composants requis pour constituer un poste complet de ce type.</p>
      <div id="liensC">${liens||'<div style="color:var(--muted);font-size:13px;margin-bottom:8px">Aucun composant défini.</div>'}</div>
      ${addForm}`;
  });
}

function addLien(pid){
  const e=document.getElementById('lEnfant').value, q=document.getElementById('lQte').value||1;
  if(!e){toast('Sélectionnez un composant.','danger');return;}
  ap({action:'add_lien',parent_id:pid,enfant_id:e,quantite:q}).then(d=>{toast(d.message,d.success?'success':'danger');if(d.success)viewN(pid);});
}
function delLien(lid,pid){
  if(!confirm('Supprimer ce composant ?'))return;
  ap({action:'del_lien',id:lid}).then(d=>{toast(d.message,d.success?'success':'danger');if(d.success)viewN(pid);});
}

function saveN(){
  const id=document.getElementById('nId').value, btn=document.getElementById('bSN');
  btn.disabled=true; btn.textContent='Enregistrement…';
  ap({action:id?'update':'create',id,
    code:document.getElementById('nCode').value,libelle:document.getElementById('nLib').value,
    duree:document.getElementById('nDuree').value,seuil:document.getElementById('nSeuil').value,
    desc:document.getElementById('nDesc').value,categorie:document.getElementById('nCategorie').value,
  }).then(d=>{
    btn.disabled=false; btn.textContent='💾 Enregistrer';
    if(d.success){toast(d.message,'success');closeMN();setTimeout(()=>location.reload(),800);}
    else document.getElementById('mNAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
  });
}
function delN(id,code){
  if(!confirm(`Supprimer la nomenclature ${code} ?`))return;
  ap({action:'delete',id}).then(d=>{toast(d.message,d.success?'success':'danger');if(d.success)setTimeout(()=>location.reload(),800);});
}
function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
}
document.getElementById('mN').addEventListener('click',e=>{if(e.target===e.currentTarget)closeMN();});
document.getElementById('mND').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
