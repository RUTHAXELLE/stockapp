<?php
// ============================================================
//  pages/affectations_it.php
//  Gestion des sous-rôles Support IT — Admin / Superviseur IT
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_auth();

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$page_title  = 'Gestion Support IT';
$active_page = 'support_it_gestion';

require_permission('affectations_it', 'can_read');

$sous_roles_dispo = [
    'maintenance'           => ['icon'=>'<i class="ph ph-wrench" aria-hidden="true"></i>','label'=>'Maintenance','desc'=>'Interventions équipements, rapport journalier'],
    'controleur_production' => ['icon'=>'<i class="ph ph-clipboard-text" aria-hidden="true"></i>','label'=>'Contrôleur Production','desc'=>'Import OptoPlate & OptoTrace, Point EMUCI'],
    'gestionnaire_bobines'  => ['icon'=>'<i class="ph ph-film-strip" aria-hidden="true"></i>','label'=>'Gestionnaire Bobines','desc'=>'Validation stock matin, suivi bobines coordinateurs'],
];

// ── AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        require_permission('affectations_it', 'can_update');
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $sous_role = trim($_POST['sous_role'] ?? '');
        $actif     = (int)($_POST['actif'] ?? 0);

        if (!isset($sous_roles_dispo[$sous_role])) json_response(false,'Sous-rôle invalide.');
        $target = db_fetch_one("SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS nom, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.actif=1", [$user_id]);
        if (!$target) json_response(false,'Compte introuvable.');
        $label = $sous_roles_dispo[$sous_role]['label'];
        if ($actif) {
            // Promouvoir en support_it si ce n'est pas déjà le cas
            if ($target['role_slug'] !== 'support_it') {
                $sit_role = db_fetch_one("SELECT id FROM roles WHERE slug='support_it'");
                if (!$sit_role) json_response(false,'Rôle support_it introuvable.');
                db_query("UPDATE users SET role_id=? WHERE id=?", [$sit_role['id'], $user_id]);
            }
            db_query("INSERT INTO support_it_roles (user_id,sous_role,actif,affecte_par) VALUES (?,?,1,?)
                      ON DUPLICATE KEY UPDATE actif=1, affecte_par=?",
                [$user_id, $sous_role, $user['id'], $user['id']]);
            json_response(true, "Sous-rôle '$label' affecté à {$target['nom']}.");
        } else {
            db_query("UPDATE support_it_roles SET actif=0 WHERE user_id=? AND sous_role=?", [$user_id, $sous_role]);
            json_response(true, "Sous-rôle '$label' retiré.");
        }
    }

    if ($action === 'promouvoir') {
        require_permission('affectations_it', 'can_update');
        $user_id = (int)($_POST['user_id'] ?? 0);
        $target  = db_fetch_one("SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS nom, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.actif=1", [$user_id]);
        if (!$target) json_response(false,'Compte introuvable.');
        if ($target['role_slug'] === 'support_it') json_response(false,'Déjà un compte Support IT.');
        $sit_role = db_fetch_one("SELECT id FROM roles WHERE slug='support_it'");
        if (!$sit_role) json_response(false,'Rôle support_it introuvable.');
        db_query("UPDATE users SET role_id=? WHERE id=?", [$sit_role['id'], $user_id]);
        json_response(true, "{$target['nom']} est maintenant Support IT.");
    }

    json_response(false,'Action inconnue.');
}

// ── DONNÉES
$supports = db_fetch_all(
    "SELECT u.id, u.prenom, u.nom, u.email, s.nom AS site_nom
     FROM users u JOIN roles r ON r.id=u.role_id
     LEFT JOIN sites s ON s.id=u.site_id
     WHERE r.slug='support_it' AND u.actif=1 ORDER BY u.nom"
);

// Tous les autres utilisateurs actifs (pour promouvoir)
$autres_users = db_fetch_all(
    "SELECT u.id, u.prenom, u.nom, u.email, r.nom AS role_nom
     FROM users u JOIN roles r ON r.id=u.role_id
     WHERE r.slug != 'support_it' AND u.actif=1
     ORDER BY u.nom"
);

// Sous-rôles actifs par user
$sr_actifs = db_fetch_all("SELECT user_id, sous_role FROM support_it_roles WHERE actif=1");
$sr_map = [];
foreach ($sr_actifs as $sr) $sr_map[$sr['user_id']][$sr['sous_role']] = true;

include __DIR__ . '/../templates/header.php';
?>
<style>
.sit-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:20px}
.sit-card{background:white;border-radius:16px;border:1px solid var(--border);overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.sit-head{background:var(--navy);padding:16px 20px;display:flex;align-items:center;gap:12px}
.sit-avatar{width:40px;height:40px;border-radius:50%;background:var(--primary-d);display:flex;align-items:center;justify-content:center;font-weight:800;color:white;font-size:15px;flex-shrink:0}
.sit-name{color:white;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700}
.sit-sub{color:#94c2d4;font-size:12px;margin-top:2px}
.sit-body{padding:16px 20px}
.sr-row{display:grid;grid-template-columns:32px 1fr auto;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)}
.sr-row:last-child{border-bottom:none}
.sr-icon{font-size:20px;text-align:center}
.sr-label{font-size:13px;font-weight:600;color:var(--navy)}
.sr-desc{font-size:12px;color:var(--muted);margin-top:2px}
.toggle-wrap{position:relative;width:44px;height:24px;cursor:pointer;flex-shrink:0}
.toggle-wrap input{opacity:0;width:0;height:0;position:absolute}
.toggle-track{position:absolute;inset:0;background:#ccc;border-radius:12px;transition:.2s}
.toggle-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;background:white;border-radius:50%;transition:.2s;box-shadow:0 1px 4px rgba(0,0,0,.2)}
.toggle-wrap input:checked ~ .toggle-track{background:var(--blue)}
.toggle-wrap input:checked ~ .toggle-thumb{transform:translateX(20px)}
.badge-sr{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;background:var(--primary-l);color:var(--primary-d)}
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:var(--navy)"><i class="ph ph-desktop" aria-hidden="true"></i> Gestion des Support IT</h2>
    <p style="font-size:13px;color:var(--muted);margin-top:4px">Affectez un ou plusieurs sous-rôles à chaque compte Support IT. Les droits sont mis à jour immédiatement.</p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('mPromouvoir').style.display='flex'">
    + Ajouter un compte
  </button>
</div>

<?php if(empty($supports)): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:60px;color:var(--muted)">
  <div style="font-size:48px;margin-bottom:16px"><i class="ph ph-desktop" aria-hidden="true"></i></div>
  <div style="font-size:15px;font-weight:600;margin-bottom:8px">Aucun compte Support IT actif</div>
  <div style="font-size:13px">Créez des comptes avec le profil "Support IT" depuis l'administration.</div>
</div></div>
<?php else: ?>
<div class="sit-grid">
  <?php foreach($supports as $sup):
    $initiales = strtoupper(substr($sup['prenom'],0,1).substr($sup['nom'],0,1));
    $actifs = $sr_map[$sup['id']] ?? [];
    $nb_actifs = count($actifs);
  ?>
  <div class="sit-card">
    <div class="sit-head">
      <div class="sit-avatar"><?= $initiales ?></div>
      <div style="flex:1">
        <div class="sit-name"><?= h($sup['prenom'].' '.$sup['nom']) ?></div>
        <div class="sit-sub">
          <?= $sup['site_nom'] ? h($sup['site_nom']) : 'Multi-sites' ?>
          <?php if($nb_actifs>0): ?>
          · <span style="color:#27ae60;font-weight:700"><?= $nb_actifs ?> sous-rôle(s) actif(s)</span>
          <?php else: ?>
          · <span style="color:#e74c3c">Aucun sous-rôle — accès limité</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="sit-body">
      <?php foreach($sous_roles_dispo as $sr_key => $sr_info):
        $checked = !empty($actifs[$sr_key]);
      ?>
      <div class="sr-row">
        <div class="sr-icon"><?= $sr_info['icon'] ?></div>
        <div>
          <div class="sr-label"><?= $sr_info['label'] ?></div>
          <div class="sr-desc"><?= $sr_info['desc'] ?></div>
        </div>
        <label class="toggle-wrap">
          <input type="checkbox" <?= $checked?'checked':'' ?>
                 onchange="toggle(<?= $sup['id'] ?>,'<?= $sr_key ?>',this.checked)">
          <span class="toggle-track"></span>
          <span class="toggle-thumb"></span>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══ MODAL PROMOUVOIR EN SUPPORT IT ═══ -->
<div id="mPromouvoir" style="display:none;position:fixed;inset:0;background:rgba(6,3,58,.5);z-index:500;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:700;color:var(--navy)"><i class="ph ph-desktop" aria-hidden="true"></i> Promouvoir en Support IT</h3>
      <button onclick="document.getElementById('mPromouvoir').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--muted)"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div style="padding:22px">
      <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Sélectionnez un compte existant à transformer en Support IT. Son rôle sera changé et vous pourrez ensuite lui affecter des sous-rôles.</p>
      <div class="form-group" style="margin-bottom:18px">
        <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--navy);display:block;margin-bottom:6px">Compte à promouvoir</label>
        <select id="selUser" class="form-control">
          <option value="">— Sélectionner un utilisateur —</option>
          <?php foreach ($autres_users as $u): ?>
          <option value="<?= $u['id'] ?>"><?= h($u['prenom'].' '.$u['nom']) ?> — <?= h($u['role_nom']) ?><?= $u['email'] ? ' ('.$u['email'].')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="alertPromouvoir"></div>
    </div>
    <div style="padding:12px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="document.getElementById('mPromouvoir').style.display='none'">Annuler</button>
      <button class="btn btn-primary" onclick="promouvoir()">Promouvoir en Support IT</button>
    </div>
  </div>
</div>

<script>
function ap(d){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(r=>r.json());}
function toast(m,t='success'){let el=document.getElementById('toast-live');if(!el){el=document.createElement('div');el.id='toast-live';el.setAttribute('role','status');el.setAttribute('aria-live','polite');el.setAttribute('aria-atomic','true');document.body.appendChild(el);}clearTimeout(el._hideTimer);el.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;background:${t==='success'?'#27ae60':'#e74c3c'};color:white;box-shadow:0 4px 20px rgba(0,0,0,.15)`;el.textContent=m;el._hideTimer=setTimeout(()=>{el.style.display='none';},3000);}

function toggle(userId, sousRole, actif){
  ap({action:'toggle',user_id:userId,sous_role:sousRole,actif:actif?1:0}).then(d=>{
    if(d.success) toast(d.message);
    else { toast(d.message,'error'); location.reload(); }
  });
}

function promouvoir(){
  const uid = document.getElementById('selUser').value;
  if(!uid){document.getElementById('alertPromouvoir').innerHTML='<div class="alert alert-danger" style="font-size:13px">Sélectionnez un utilisateur.</div>';return;}
  ap({action:'promouvoir',user_id:uid}).then(d=>{
    if(d.success){
      toast(d.message);
      document.getElementById('mPromouvoir').style.display='none';
      setTimeout(()=>location.reload(),800);
    } else {
      document.getElementById('alertPromouvoir').innerHTML=`<div class="alert alert-danger" style="font-size:13px">${d.message}</div>`;
    }
  });
}
</script>

<?php include __DIR__.'/../templates/footer.php'; ?>
