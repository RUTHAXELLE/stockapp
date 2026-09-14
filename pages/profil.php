<?php
// ============================================================
//  pages/profil.php  —  Profil utilisateur
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();

$user        = current_user();
$page_title  = 'Mon profil';
$active_page = '';

// ── AJAX : modifier infos perso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $nom      = trim($_POST['nom']      ?? '');
        $prenom   = trim($_POST['prenom']   ?? '');
        $tel      = trim($_POST['telephone'] ?? '');

        if (!$nom || !$prenom) json_response(false, 'Nom et prénom obligatoires.');

        $old = db_fetch_one("SELECT nom, prenom, telephone FROM users WHERE id = ?", [$user['id']]);
        db_query("UPDATE users SET nom=?, prenom=?, telephone=? WHERE id=?", [$nom, $prenom, $tel, $user['id']]);
        audit_log($user['id'], 'UPDATE', 'users', $user['id'], 'Mise à jour des informations personnelles', $old, ['nom'=>$nom,'prenom'=>$prenom,'telephone'=>$tel]);
        json_response(true, 'Informations mises à jour.');
    }

    if ($action === 'change_password') {
        // trim() comme login.php : cf. changer-mot-de-passe.php.
        $result = auth_change_password(
            $user['id'],
            trim($_POST['old_password'] ?? ''),
            trim($_POST['new_password'] ?? '')
        );
        json_response($result['success'], $result['message']);
    }

    json_response(false, 'Action inconnue.');
}

// Activités récentes de l'utilisateur
$activites = db_fetch_all(
    "SELECT action, module, description, created_at, ip_address
     FROM audit_log WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 15",
    [$user['id']]
);

include __DIR__ . '/../templates/header.php';
?>
<style>
.profil-grid { display: grid; grid-template-columns: 300px 1fr; gap: 20px; }
.profil-card { background: white; border-radius: 14px; border: 1px solid var(--border); overflow: hidden; }
.profil-avatar-section {
  background: linear-gradient(135deg, var(--navy), #2563a8);
  padding: 32px 20px; text-align: center; color: white;
}
.big-avatar {
  width: 80px; height: 80px; border-radius: 50%;
  background: var(--primary-d);
  display: flex; align-items: center; justify-content: center;
  font-size: 32px; font-weight: 700; margin: 0 auto 12px;
  border: 3px solid rgba(255,255,255,.3);
}
.profil-avatar-section h3 { font-family: 'Montserrat', sans-serif; font-size: 18px; font-weight: 700; }
.profil-avatar-section .role-badge {
  display: inline-block; margin-top: 8px;
  background: rgba(255,255,255,.15); padding: 4px 12px;
  border-radius: 20px; font-size: 12px;
}
.profil-meta { padding: 16px 20px; }
.meta-item { display: flex; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.meta-item:last-child { border-bottom: none; }
.meta-item .m-icon { font-size: 16px; width: 20px; text-align: center; }
.meta-item .m-val  { color: var(--text); flex: 1; }

.tabs { display: flex; border-bottom: 1px solid var(--border); }
.tab-btn {
  padding: 14px 24px; font-size: 13.5px; font-weight: 500;
  color: var(--muted); cursor: pointer; border-bottom: 2px solid transparent;
  background: none; border-top: none; border-left: none; border-right: none;
  transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s; font-family: 'DM Sans', sans-serif;
}
.tab-btn.active { color: var(--blue-mid, #1a56a0); border-bottom-color: var(--blue-mid, #1a56a0); }
.tab-pane { display: none; padding: 24px; }
.tab-pane.active { display: block; }

@media(max-width:900px){ .profil-grid{ grid-template-columns:minmax(0,1fr); } }
</style>

<div class="profil-grid">

  <!-- COLONNE GAUCHE -->
  <div>
    <div class="profil-card">
      <div class="profil-avatar-section">
        <div class="big-avatar"><?= strtoupper(substr($user['prenom'],0,1).substr($user['nom'],0,1)) ?></div>
        <h3><?= h($user['prenom'].' '.$user['nom']) ?></h3>
        <div class="role-badge"><?= h($user['role_nom']) ?></div>
      </div>
      <div class="profil-meta">
        <div class="meta-item"><span class="m-icon"><i class="ph ph-envelope-simple" aria-hidden="true"></i></span><span class="m-val"><?= h($user['email']) ?></span></div>
        <div class="meta-item"><span class="m-icon"><i class="ph ph-device-mobile" aria-hidden="true"></i></span><span class="m-val"><?= h($user['telephone'] ?: '—') ?></span></div>
        <div class="meta-item"><span class="m-icon"><i class="ph ph-calendar" aria-hidden="true"></i></span><span class="m-val">Inscrit le <?= fmt_date($user['created_at']) ?></span></div>
        <div class="meta-item"><span class="m-icon"><i class="ph ph-clock" aria-hidden="true"></i></span><span class="m-val">Dernière connexion : <?= fmt_datetime($user['last_login']) ?></span></div>
      </div>
    </div>
  </div>

  <!-- COLONNE DROITE -->
  <div class="profil-card">
    <div class="tabs">
      <button class="tab-btn active" onclick="showTab('infos',this)"><i class="ph ph-note-pencil" aria-hidden="true"></i> Informations</button>
      <button class="tab-btn" onclick="showTab('password',this)"><i class="ph ph-lock" aria-hidden="true"></i> Mot de passe</button>
      <button class="tab-btn" onclick="showTab('activites',this)"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Activités</button>
    </div>

    <!-- TAB : INFOS -->
    <div class="tab-pane active" id="tab-infos">
      <div id="alert-infos"></div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Prénom</label>
          <input type="text" class="form-control" id="f-prenom" value="<?= h($user['prenom']) ?>">
        </div>
        <div class="form-group"><label>Nom</label>
          <input type="text" class="form-control" id="f-nom" value="<?= h($user['nom']) ?>">
        </div>
      </div>
      <div class="form-group"><label>Email <span style="color:var(--muted)">(non modifiable)</span></label>
        <input type="email" class="form-control" value="<?= h($user['email']) ?>" disabled>
      </div>
      <div class="form-group"><label>Téléphone</label>
        <input type="text" class="form-control" id="f-tel" value="<?= h($user['telephone'] ?? '') ?>" placeholder="+225 00 00 00 00">
      </div>
      <button class="btn btn-primary" onclick="saveInfos()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer</button>
    </div>

    <!-- TAB : MOT DE PASSE -->
    <div class="tab-pane" id="tab-password">
      <div id="alert-pwd"></div>
      <div class="form-group"><label>Mot de passe actuel</label>
        <input type="password" class="form-control" id="f-old-pwd" placeholder="••••••••">
      </div>
      <div class="form-group"><label>Nouveau mot de passe</label>
        <input type="password" class="form-control" id="f-new-pwd" placeholder="Min. 8 caractères">
      </div>
      <div class="form-group"><label>Confirmer le nouveau mot de passe</label>
        <input type="password" class="form-control" id="f-confirm-pwd" placeholder="••••••••">
      </div>
      <button class="btn btn-primary" onclick="changePwd()"><i class="ph ph-lock" aria-hidden="true"></i> Modifier le mot de passe</button>
    </div>

    <!-- TAB : ACTIVITÉS -->
    <div class="tab-pane" id="tab-activites">
      <?php if (empty($activites)): ?>
        <div style="text-align:center;color:var(--muted);padding:40px 0">Aucune activité enregistrée.</div>
      <?php else: ?>
        <?php foreach($activites as $a): ?>
        <div class="audit-item">
          <span class="audit-action-badge <?= $a['action'] ?>"><?= $a['action'] ?></span>
          <div>
            <div class="audit-desc"><?= h($a['description']) ?></div>
            <div class="audit-meta"><?= fmt_datetime($a['created_at']) ?> · IP: <?= h($a['ip_address']) ?> · <?= h($a['module']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<style>
.audit-item { display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border); }
.audit-item:last-child{border-bottom:none;}
.audit-action-badge{font-size:12px;font-weight:700;padding:3px 7px;border-radius:5px;flex-shrink:0;margin-top:2px;text-transform:uppercase;letter-spacing:.5px;}
.audit-action-badge.CREATE{background:#d5f5e3;color:#1e8449;}
.audit-action-badge.UPDATE{background:#d6eaf8;color:#1a5276;}
.audit-action-badge.DELETE{background:#fdedec;color:#922b21;}
.audit-action-badge.LOGIN{background:#fef9e7;color:#9a7d0a;}
.audit-action-badge.LOGOUT{background:#eaecee;color:#424949;}
.audit-desc{font-size:12.5px;flex:1;}
.audit-meta{font-size:12px;color:var(--muted);margin-top:2px;}
</style>

<script>
function showTab(id, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  btn.classList.add('active');
}

function showAlert(elId, msg, type='success') {
  const el = document.getElementById(elId);
  el.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML='', 4000);
}

function apiPost(data) {
  const fd = new FormData();
  for (const [k,v] of Object.entries(data)) fd.append(k,v);
  return fetch(window.location.href, {
    method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd
  }).then(r=>r.json());
}

function saveInfos() {
  apiPost({
    action:'update_info',
    nom: document.getElementById('f-nom').value,
    prenom: document.getElementById('f-prenom').value,
    telephone: document.getElementById('f-tel').value
  }).then(d => showAlert('alert-infos', d.message, d.success?'success':'danger'));
}

function changePwd() {
  const np = document.getElementById('f-new-pwd').value;
  const cp = document.getElementById('f-confirm-pwd').value;
  if (np !== cp) { showAlert('alert-pwd','Les mots de passe ne correspondent pas.','danger'); return; }
  apiPost({
    action:'change_password',
    old_password: document.getElementById('f-old-pwd').value,
    new_password: np
  }).then(d => {
    showAlert('alert-pwd', d.message, d.success?'success':'danger');
    if (d.success) { document.getElementById('f-old-pwd').value=''; document.getElementById('f-new-pwd').value=''; document.getElementById('f-confirm-pwd').value=''; }
  });
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
