<?php
// ============================================================
//  pages/mouvements_equipements.php  —  Historique des mouvements
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/upload.php';

require_auth();
require_permission('affectations', 'can_read');

$user        = current_user();
$page_title  = 'Historique des mouvements';
$active_page = 'mouvements_equipements';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── RETOUR EN STOCK (désaffecter)
    if ($action === 'retour') {
        require_permission('affectations', 'can_update');
        $equip_id = (int)($_POST['equipement_id'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');
        if (!$notes) json_response(false, 'Motif du retour obligatoire.');
        $equip    = db_fetch_one("SELECT * FROM equipements WHERE id=? AND actif=1", [$equip_id]);
        if (!$equip) json_response(false, 'Équipement introuvable.');

        db_begin();
        try {
            db_query(
                "INSERT INTO mouvements_equipements (equipement_id,type,site_source_id,notes,created_by)
                 VALUES (?,?,?,?,?)",
                [$equip_id, 'sortie', $equip['site_id'], $notes, $user['id']]
            );
            db_query("UPDATE equipements SET site_id=NULL, utilisateur_id=NULL WHERE id=?", [$equip_id]);
            audit_log($user['id'], 'UPDATE', 'affectations', $equip_id,
                "Retour stock {$equip['numero_serie_interne']}");
            db_commit();
            json_response(true, 'Équipement remis en stock.');
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  LISTE DES MOUVEMENTS
// ============================================================
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;
$f_site   = (int)($_GET['site']   ?? 0);
$f_type   = trim($_GET['type_mouv'] ?? '');

$where  = ['1=1'];
$params = [];
if ($f_site)  { $where[] = '(me.site_source_id=? OR me.site_dest_id=?)'; $params[] = $f_site; $params[] = $f_site; }
if ($f_type)  { $where[] = 'me.type=?'; $params[] = $f_type; }

$wsql   = implode(' AND ', $where);
$total  = (int)db_fetch_value("SELECT COUNT(*) FROM mouvements_equipements me WHERE $wsql", $params);
$offset = ($page - 1) * $per_page;

$mouvements = db_fetch_all(
    "SELECT me.*, e.numero_serie_interne, COALESCE(n.libelle,'—') AS type_equip,
            s1.nom AS site_source, s2.nom AS site_dest,
            CONCAT(u.prenom,' ',u.nom) AS agent,
            CONCAT(ud.prenom,' ',ud.nom) AS user_dest
     FROM mouvements_equipements me
     JOIN equipements e ON e.id=me.equipement_id
     LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
     LEFT JOIN sites s1 ON s1.id=me.site_source_id
     LEFT JOIN sites s2 ON s2.id=me.site_dest_id
     LEFT JOIN users u  ON u.id=me.created_by
     LEFT JOIN users ud ON ud.id=me.user_dest_id
     WHERE $wsql
     ORDER BY me.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset])
);

$sites_list = db_fetch_all("SELECT id,nom,type FROM sites WHERE actif=1 ORDER BY nom");

include __DIR__ . '/../templates/header.php';
?>
<style>
.mouv-type{font-size:12px;font-weight:700;padding:3px 8px;border-radius:5px;text-transform:uppercase;letter-spacing:.5px}
.mouv-type.entree    {background:#d5f5e3;color:#1e8449}
.mouv-type.sortie    {background:#fef9e7;color:#9a7d0a}
.mouv-type.transfert {background:#d6eaf8;color:#1a5276}
.mouv-type.reforme   {background:#fdedec;color:#922b21}
.mouv-type.maintenance{background:#faf5ff;color:#6b21a8}
.mouv-row{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.mouv-row:last-child{border-bottom:none}
.mouv-info{flex:1}
.mouv-equip{font-family:monospace;font-weight:700;font-size:13px;color:var(--navy)}
.mouv-detail{font-size:12px;color:var(--muted);margin-top:2px}
.mouv-date{font-size:12px;color:var(--muted);white-space:nowrap}
.mouv-scroll{max-height:600px;overflow-y:auto}

.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,31,53,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;width:560px;max-width:95vw;max-height:92vh;overflow-y:auto;animation:mIn .25s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Montserrat',sans-serif;font-size:17px;font-weight:700}
.mclose{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.mbody{padding:24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}
</style>

<!-- FILTRES MOUVEMENTS -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;flex:1;min-width:0">
    <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;cursor:pointer;outline:none;font-family:'DM Sans',sans-serif;max-width:100%">
      <option value="">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site===$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type_mouv" class="fsel" aria-label="Filtrer par type de mouvement" onchange="this.form.submit()" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;cursor:pointer;outline:none;font-family:'DM Sans',sans-serif">
      <option value="">Tous types</option>
      <option value="entree"    <?= $f_type==='entree'?'selected':'' ?>>Entrée</option>
      <option value="sortie"    <?= $f_type==='sortie'?'selected':'' ?>>Sortie</option>
      <option value="transfert" <?= $f_type==='transfert'?'selected':'' ?>>Transfert</option>
      <option value="reforme"   <?= $f_type==='reforme'?'selected':'' ?>>Réforme</option>
      <option value="maintenance" <?= $f_type==='maintenance'?'selected':'' ?>>Maintenance</option>
    </select>
    <?php if($f_site||$f_type): ?>
    <a href="mouvements_equipements.php" style="padding:9px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;text-decoration:none;color:var(--text)"><i class="ph ph-x" aria-hidden="true"></i> Reset</a>
    <?php endif; ?>
  </form>
  <?php if(can('affectations','can_export')): ?>
  <a href="<?= APP_URL ?>/api/export.php?type=mouvements<?= $f_site ? '&site='.$f_site : '' ?><?= $f_type ? '&type_mouv='.urlencode($f_type) : '' ?>" class="btn btn-secondary btn-sm"><i class="ph ph-download-simple" aria-hidden="true"></i> Excel</a>
  <?php endif; ?>
</div>

<!-- HISTORIQUE DES MOUVEMENTS -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <h3><i class="ph ph-clipboard-text" aria-hidden="true"></i> Historique des mouvements <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= fmt_number($total) ?>)</span></h3>
  </div>
  <div class="<?= count($mouvements) > 5 ? 'mouv-scroll' : '' ?>" style="padding:4px 20px">
    <?php if(empty($mouvements)): ?>
    <div style="text-align:center;padding:40px;color:var(--muted)">Aucun mouvement enregistré.</div>
    <?php else: foreach($mouvements as $m): ?>
    <div class="mouv-row">
      <span class="mouv-type <?= $m['type'] ?>"><?= $m['type'] ?></span>
      <div class="mouv-info">
        <div class="mouv-equip"><?= h($m['numero_serie_interne']) ?> <span style="font-family:'DM Sans';font-size:12px;font-weight:400;color:var(--muted)">· <?= h($m['type_equip']) ?></span></div>
        <div class="mouv-detail">
          <?php if($m['site_source']): ?>De : <strong><?= h($m['site_source']) ?></strong><?php endif; ?>
          <?php if($m['site_dest']): ?> → <strong><?= h($m['site_dest']) ?></strong><?php endif; ?>
          <?php if($m['user_dest']): ?> · <i class="ph ph-user" aria-hidden="true"></i> <?= h($m['user_dest']) ?><?php endif; ?>
          <?php if($m['notes']): ?><br><em><?= h($m['notes']) ?></em><?php endif; ?>
          <?php if(!empty($m['fichier_bl'])): ?>
          <br><?= upload_link($m['fichier_bl'],'bl','<i class="ph ph-paperclip" aria-hidden="true"></i> Bon de livraison') ?>
          <?php endif; ?>
        </div>
        <div class="mouv-detail">par <?= h($m['agent'] ?? 'Système') ?></div>
      </div>
      <div class="mouv-date"><?= fmt_datetime($m['created_at']) ?></div>
      <?php if(can('affectations','can_update') && in_array($m['type'],['entree','transfert'])): ?>
      <button class="btn btn-secondary btn-sm" onclick="openRetour(<?= $m['equipement_id'] ?>,'<?= h($m['numero_serie_interne']) ?>')" title="Retour stock">↩</button>
      <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <?= pagination($total,$per_page,$page,'mouvements_equipements.php?'.http_build_query(['site'=>$f_site,'type_mouv'=>$f_type])) ?>
</div>

<!-- MODAL RETOUR STOCK -->
<div class="modal-overlay" id="mRetour">
  <div class="modal">
    <div class="mhdr"><h3>↩ Retour en stock</h3><button class="mclose" onclick="document.getElementById('mRetour').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <input type="hidden" id="rEquipId">
      <div class="alert alert-warning">L'équipement <strong id="rEquipNum"></strong> sera retiré de son site et remis en stock central.</div>
      <div class="form-group" style="margin-top:16px"><label>Motif du retour</label>
        <textarea class="form-control" id="rNotes" rows="3" placeholder="Raison du retour…"></textarea>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mRetour').classList.remove('open')">Annuler</button>
      <button class="btn btn-primary" onclick="saveRetour()">↩ Confirmer le retour</button>
    </div>
  </div>
</div>

<script>
function openRetour(id,num){
  document.getElementById('rEquipId').value=id;
  document.getElementById('rEquipNum').textContent=num;
  document.getElementById('rNotes').value='';
  document.getElementById('mRetour').classList.add('open');
}
function saveRetour(){
  ap({action:'retour',equipement_id:document.getElementById('rEquipId').value,notes:document.getElementById('rNotes').value})
    .then(d=>{toast(d.message,d.success?'success':'danger');if(d.success){document.getElementById('mRetour').classList.remove('open');setTimeout(()=>location.reload(),900);}});
}

function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
}
document.getElementById('mRetour').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
