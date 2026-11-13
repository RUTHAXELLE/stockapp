<?php
// ============================================================
//  pages/admin/users.php  —  Gestion des utilisateurs
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/auth.php';

require_auth();
require_permission('users', 'can_read');

$user        = current_user();
$page_title  = 'Gestion des utilisateurs';
$active_page = 'users';

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── CRÉER
    if ($action === 'create') {
        require_permission('users', 'can_create');
        $result = auth_create_user([
            'nom'       => trim($_POST['nom']       ?? ''),
            'prenom'    => trim($_POST['prenom']    ?? ''),
            'email'     => trim($_POST['email']     ?? ''),
            'password'  => trim($_POST['password']  ?? ''),
            'role_id'   => (int)($_POST['role_id']  ?? 0),
            'site_id'   => (int)($_POST['site_id']  ?? 0) ?: null,
            'telephone' => trim($_POST['telephone'] ?? ''),
        ]);
        json_response($result['success'], $result['message'], $result['success'] ? ['id' => $result['id']] : []);
    }

    // ── MODIFIER
    if ($action === 'update') {
        require_permission('users', 'can_update');
        $id      = (int)($_POST['id']          ?? 0);
        $nom     = format_nom_prenom($_POST['nom']    ?? '');
        $prenom  = format_nom_prenom($_POST['prenom'] ?? '');
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $role_id = (int)($_POST['role_id']      ?? 0);
        $site_id = (int)($_POST['site_id']      ?? 0) ?: null;
        $tel     = trim($_POST['telephone']     ?? '');
        $actif   = (int)($_POST['actif']        ?? 1);
        $notif_mail = isset($_POST['notif_mail']) ? (int)!empty($_POST['notif_mail']) : null;

        if (!$nom || !$prenom || !$email) json_response(false, 'Nom, prénom et email obligatoires (lettres uniquement pour nom/prénom).');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(false, 'Email invalide.');
        if ($tel !== '' && !is_valid_telephone($tel)) json_response(false, 'Téléphone invalide (10 chiffres exactement).');

        // Empêcher de se rétrograder soi-même
        if ($id === $user['id'] && $role_id !== (int)$user['role_id'])
            json_response(false, 'Vous ne pouvez pas modifier votre propre rôle.');

        // Email unique (sauf lui-même)
        $exists = db_fetch_value("SELECT COUNT(*) FROM users WHERE email=? AND id!=?", [$email, $id]);
        if ($exists > 0) json_response(false, 'Cet email est déjà utilisé.');

        // Seul superadmin peut nommer un superadmin
        if ($user['role_slug'] !== 'superadmin' && $role_id === 1)
            json_response(false, 'Action non autorisée.');

        $old = db_fetch_one("SELECT * FROM users WHERE id=?", [$id]);
        db_query(
            "UPDATE users SET nom=?,prenom=?,email=?,role_id=?,site_id=?,telephone=?,actif=? WHERE id=?",
            [$nom, $prenom, $email, $role_id, $site_id, $tel, $actif, $id]
        );
        if ($notif_mail !== null && db_colonne_existe('users', 'notif_mail'))
            { db_query("UPDATE users SET notif_mail=? WHERE id=?", [$notif_mail, $id]); if (!$notif_mail) mail_ignorer_en_attente($id); }
        audit_log($user['id'], 'UPDATE', 'users', $id,
            "Modification utilisateur {$old['email']} → $email", $old,
            ['nom'=>$nom,'prenom'=>$prenom,'email'=>$email,'role_id'=>$role_id]);
        json_response(true, 'Utilisateur mis à jour.');
    }

    // ── RESET MOT DE PASSE (par admin)
    if ($action === 'reset_pwd') {
        require_permission('users', 'can_update');
        $id  = (int)($_POST['id']  ?? 0);
        $pwd = trim($_POST['pwd']  ?? '');
        $result = auth_change_password($id, '', $pwd, true);
        json_response($result['success'], $result['message']);
    }

    // ── ACTIVER / DÉSACTIVER
    if ($action === 'toggle_actif') {
        require_permission('users', 'can_update');
        $id    = (int)($_POST['id']    ?? 0);
        $actif = (int)($_POST['actif'] ?? 0);
        if ($id === $user['id']) json_response(false, 'Vous ne pouvez pas vous désactiver vous-même.');
        db_query("UPDATE users SET actif=? WHERE id=?", [$actif, $id]);
        $u = db_fetch_one("SELECT email FROM users WHERE id=?", [$id]);
        audit_log($user['id'], 'UPDATE', 'users', $id,
            ($actif ? 'Activation' : 'Désactivation') . " utilisateur {$u['email']}");
        json_response(true, 'Statut mis à jour.');
    }

    // ── GET ONE
    if ($action === 'get') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_fetch_one(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone,
                    u.role_id, u.site_id, u.actif, u.last_login, u.created_at,
                    r.nom AS role_nom, r.slug AS role_slug,
                    s.nom AS site_nom
             FROM users u
             JOIN roles r ON r.id=u.role_id
             LEFT JOIN sites s ON s.id=u.site_id
             WHERE u.id=?", [$id]
        );
        if (!$row) json_response(false, 'Introuvable.');
        // Stats de l'utilisateur
        $row['notif_mail'] = db_colonne_existe('users', 'notif_mail') ? (int)db_fetch_value("SELECT notif_mail FROM users WHERE id=?", [$id]) : 1;
        $row['nb_actions'] = (int)db_fetch_value("SELECT COUNT(*) FROM audit_log WHERE user_id=?", [$id]);
        $row['nb_equips']  = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE utilisateur_id=? AND actif=1", [$id]);
        json_response(true, '', $row);
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  LISTE
// ============================================================
$page     = max(1, (int)($_GET['page']     ?? 1));
$per_page = 20;
$search   = trim($_GET['q']                ?? '');
$f_role   = (int)($_GET['role']            ?? 0);
$f_actif  = $_GET['actif'] ?? '';

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = '(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ?)';
    $s = "%$search%"; $params = [$s, $s, $s];
}
if ($f_role)        { $where[] = 'u.role_id=?';  $params[] = $f_role; }
if ($f_actif !== '') { $where[] = 'u.actif=?';   $params[] = (int)$f_actif; }

$wsql   = implode(' AND ', $where);
$total  = (int)db_fetch_value("SELECT COUNT(*) FROM users u WHERE $wsql", $params);
$offset = ($page - 1) * $per_page;

$rows = db_fetch_all(
    "SELECT u.*, r.nom AS role_nom, r.slug AS role_slug,
            s.nom AS site_nom,
            COUNT(al.id) AS nb_actions
     FROM users u
     JOIN roles r ON r.id=u.role_id
     LEFT JOIN sites s ON s.id=u.site_id
     LEFT JOIN audit_log al ON al.user_id=u.id
     WHERE $wsql
     GROUP BY u.id, r.nom, r.slug, r.id, s.nom
     ORDER BY u.actif DESC, r.id ASC, u.nom ASC
     LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset])
);

$roles_list = db_fetch_all("SELECT id,nom,slug FROM roles ORDER BY id");
$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

include __DIR__ . '/../../templates/header.php';
?>
<style>
.user-avatar-sm{width:38px;height:38px;border-radius:50%;background:var(--navy);display:flex;align-items:center;justify-content:center;color:white;font-size:13px;font-weight:700;flex-shrink:0}
.role-badge{font-size:12px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.5px}
.role-badge.superadmin{background:#fdf0ef;color:var(--danger-d)}
.role-badge.admin{background:#fef9e7;color:var(--warning-d)}
.role-badge.gestionnaire{background:#d6eaf8;color:#1a5276}
.role-badge.lecteur{background:#eaecee;color:#424949}
.status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.status-dot.on{background:var(--success)}
.status-dot.off{background:var(--muted)}
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,31,53,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;max-width:95vw;max-height:92vh;overflow-y:auto;animation:mIn .25s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:700}
.mclose{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.mbody{padding:24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}
.fsel{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;cursor:pointer;outline:none;font-family:'Manrope',sans-serif}
.fsel:focus{border-color:var(--blue-mid, #1a56a0)}
/* Tabs */
.tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:20px}
.tab-btn{padding:12px 20px;font-size:13.5px;font-weight:500;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;font-family:'Manrope',sans-serif}
.tab-btn.active{color:var(--blue-mid, #1a56a0);border-bottom-color:var(--blue-mid, #1a56a0)}
.tab-pane{display:none}
.tab-pane.active{display:block}
/* Stats rapides — dashboard */
.stats-dash{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;margin-bottom:18px}
.stat-tile{position:relative;overflow:hidden;background:white;border:1px solid var(--border);border-radius:12px;padding:11px 14px 11px 16px}
.stat-tile::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent,var(--muted))}
.stat-tile-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.stat-tile-value{font-family:'Plus Jakarta Sans',sans-serif;font-size:21px;font-weight:700;color:var(--navy)}
.stat-tile-empty{opacity:.5}
.stat-tile-total{background:var(--navy);border-color:var(--navy)}
.stat-tile-total::before{display:none}
.stat-tile-total .stat-tile-label{color:rgba(255,255,255,.65)}
.stat-tile-total .stat-tile-value{color:white}
.form-control.field-invalid{border-color:#DC2626!important;box-shadow:0 0 0 3px rgba(220,38,38,.12)}
</style>

<!-- TOOLBAR -->
<div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;align-items:center">
  <form method="GET" id="usersFilterForm" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
    <div style="position:relative;flex:1;min-width:200px">
      <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none"><i class="ph ph-magnifying-glass" aria-hidden="true"></i></span>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Nom, prénom, email…" aria-label="Rechercher un utilisateur" autocomplete="off"
             style="width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;outline:none;font-family:'Manrope',sans-serif">
    </div>
    <select name="role" class="fsel" aria-label="Filtrer par rôle">
      <option value="0">Tous les rôles</option>
      <?php foreach($roles_list as $r): ?>
      <option value="<?= $r['id'] ?>" <?= $f_role===$r['id']?'selected':'' ?>><?= h($r['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="actif" class="fsel" aria-label="Filtrer par statut actif">
      <option value="">Tous statuts</option>
      <option value="1" <?= $f_actif==='1'?'selected':'' ?>>Actifs</option>
      <option value="0" <?= $f_actif==='0'?'selected':'' ?>>Inactifs</option>
    </select>
    <span id="clearFiltersWrap"><?php if($search||$f_role||$f_actif!==''): ?>
    <a href="users.php" style="padding:9px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;text-decoration:none;color:var(--text)"><i class="ph ph-x" aria-hidden="true"></i></a>
    <?php endif; ?></span>
  </form>
  <?php if(can('users','can_create')): ?>
  <button class="btn btn-primary" onclick="openMU()">+ Nouvel utilisateur</button>
  <?php endif; ?>
</div>

<!-- STATS RAPIDES -->
<?php
$role_palette = ['#1B75BC','#E67E22','#27AE60','#8E44AD','#C0392B','#16A085','#B7950B','#5D6D7E'];
?>
<div class="stats-dash">
  <div class="stat-tile stat-tile-total">
    <div class="stat-tile-label">Total actifs</div>
    <div class="stat-tile-value"><?= (int)db_fetch_value("SELECT COUNT(*) FROM users WHERE actif=1") ?></div>
  </div>
  <?php foreach($roles_list as $r):
    $cnt = (int)db_fetch_value("SELECT COUNT(*) FROM users WHERE role_id=? AND actif=1",[$r['id']]);
    $accent = $role_palette[$r['id'] % count($role_palette)];
  ?>
  <div class="stat-tile<?= $cnt===0?' stat-tile-empty':'' ?>" style="--accent:<?= $accent ?>">
    <div class="stat-tile-label"><?= h($r['nom']) ?></div>
    <div class="stat-tile-value"><?= $cnt ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- TABLE -->
<div class="card" id="usersTableWrap">
  <div class="card-header">
    <h3><i class="ph ph-users" aria-hidden="true"></i> Utilisateurs <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= fmt_number($total) ?>)</span></h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th style="width:44px"></th>
        <th>Nom complet</th>
        <th>Email</th>
        <th>Rôle</th>
        <th>Site principal</th>
        <th style="text-align:center">Actions</th>
        <th style="text-align:center">Statut</th>
        <th>Dernière connexion</th>
        <th>Actions</th>
      </tr></thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">Aucun utilisateur trouvé.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr style="<?= !$r['actif']?'opacity:.55':'' ?>">
          <td>
            <div class="user-avatar-sm"><?= strtoupper(substr($r['prenom'],0,1).substr($r['nom'],0,1)) ?></div>
          </td>
          <td>
            <div style="font-weight:600;font-size:13.5px"><?= h($r['prenom'].' '.$r['nom']) ?></div>
            <?php if($r['telephone']): ?><div style="font-size:11.5px;color:var(--muted)"><?= h($r['telephone']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:13px"><?= h($r['email']) ?></td>
          <td><span class="role-badge <?= $r['role_slug'] ?>"><?= h($r['role_slug']) ?></span></td>
          <td style="font-size:12.5px"><?= h($r['site_nom'] ?? '—') ?></td>
          <td style="text-align:center">
            <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:15px;color:var(--navy)"><?= $r['nb_actions'] ?></span>
          </td>
          <td style="text-align:center">
            <div style="display:flex;align-items:center;justify-content:center;gap:6px">
              <div class="status-dot <?= $r['actif']?'on':'off' ?>"></div>
              <span style="font-size:12px;color:var(--muted)"><?= $r['actif']?'Actif':'Inactif' ?></span>
            </div>
          </td>
          <td style="font-size:12px;color:var(--muted)"><?= fmt_datetime($r['last_login']) ?></td>
          <td style="white-space:nowrap;display:flex;gap:4px">
            <button class="btn btn-secondary btn-sm" onclick="viewU(<?= $r['id'] ?>)" title="Voir"><i class="ph ph-eye" aria-hidden="true"></i></button>
            <?php if(can('users','can_update') && ($user['role_slug']==='superadmin' || $r['role_slug']!=='superadmin')): ?>
            <button class="btn btn-secondary btn-sm" onclick="editU(<?= $r['id'] ?>)" title="Modifier"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
            <button class="btn btn-secondary btn-sm" onclick="resetPwd(<?= $r['id'] ?>,'<?= h($r['prenom'].' '.$r['nom']) ?>')" title="Reset MDP"><i class="ph ph-key" aria-hidden="true"></i></button>
            <?php if($r['id']!==$user['id']): ?>
            <button class="btn <?= $r['actif']?'btn-danger':'btn-success' ?> btn-sm"
                    onclick="toggleActif(<?= $r['id'] ?>,<?= $r['actif']?0:1 ?>,'<?= h($r['prenom'].' '.$r['nom']) ?>')"
                    title="<?= $r['actif']?'Désactiver':'Activer' ?>">
              <?= $r['actif']?'<i class="ph ph-prohibit" aria-hidden="true"></i>':'<i class="ph ph-check-circle" aria-hidden="true"></i>' ?>
            </button>
            <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total,$per_page,$page,'users.php?'.http_build_query(['q'=>$search,'role'=>$f_role,'actif'=>$f_actif])) ?>
</div>

<!-- MODAL CRÉER / MODIFIER -->
<div class="modal-overlay" id="mU">
  <div class="modal" style="width:600px">
    <div class="mhdr"><h3 id="mUT">Nouvel utilisateur</h3><button class="mclose" onclick="closeMU()"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <div id="mUAlert"></div>
      <input type="hidden" id="uId">
      <div class="form-row cols-2">
        <div class="form-group"><label>Prénom *</label>
          <input type="text" class="form-control" id="uPrenom" oninput="uFormatNom(this)">
        </div>
        <div class="form-group"><label>Nom *</label>
          <input type="text" class="form-control" id="uNom" oninput="uFormatNom(this)">
        </div>
      </div>
      <div class="form-group"><label>Email *</label>
        <input type="email" class="form-control" id="uEmail" placeholder="prenom.nom@entreprise.ci"
               oninput="this.value=this.value.toLowerCase().replace(/\s/g,'')" onblur="uCheckEmail(this)" onfocus="this.classList.remove('field-invalid')">
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Rôle *</label>
          <select class="form-control" id="uRole">
            <?php foreach($roles_list as $r):
              if($r['slug']==='superadmin' && $user['role_slug']!=='superadmin') continue; ?>
            <option value="<?= $r['id'] ?>"><?= h($r['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Site principal</label>
          <select class="form-control" id="uSite">
            <option value="">— Non assigné —</option>
            <?php foreach($sites_list as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Téléphone</label>
        <input type="text" class="form-control" id="uTel" placeholder="0700000000" maxlength="10" inputmode="numeric"
               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10)" onblur="uCheckTel(this)" onfocus="this.classList.remove('field-invalid')">
      </div>
      <div id="uPwdWrap">
        <div class="form-group"><label>Mot de passe * <span style="font-size:12px;color:var(--muted)">(min. 8 car., 1 majuscule, 1 chiffre, 1 caractère spécial)</span></label>
          <input type="password" class="form-control" id="uPwd" placeholder="••••••••">
        </div>
      </div>
      <div id="uActifWrap" style="display:none">
        <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer">
          <input type="checkbox" id="uActif" checked> Compte actif
        </label>
      </div>
      <div style="margin-top:10px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer">
          <input type="checkbox" id="uNotifMail" checked> Reçoit les notifications par mail
        </label>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="closeMU()">Annuler</button>
      <button class="btn btn-primary" id="bSU" onclick="saveU()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- MODAL DÉTAIL -->
<div class="modal-overlay" id="mUD">
  <div class="modal" style="width:560px">
    <div class="mhdr"><h3 id="udT">Détail</h3><button class="mclose" onclick="document.getElementById('mUD').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody" id="udB"></div>
  </div>
</div>

<!-- MODAL RESET MDP -->
<div class="modal-overlay" id="mPwd">
  <div class="modal" style="width:420px">
    <div class="mhdr"><h3><i class="ph ph-key" aria-hidden="true"></i> Réinitialiser le mot de passe</h3><button class="mclose" onclick="document.getElementById('mPwd').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <input type="hidden" id="pwdUId">
      <div class="alert alert-warning" style="margin-bottom:16px">Vous allez réinitialiser le mot de passe de <strong id="pwdUNom"></strong>.</div>
      <div id="pwdAlert"></div>
      <div class="form-group"><label>Nouveau mot de passe * <span style="font-size:12px;color:var(--muted)">(min. 8 car., 1 majuscule, 1 chiffre, 1 caractère spécial)</span></label>
        <input type="password" class="form-control" id="pwdNew" placeholder="••••••••">
      </div>
      <div class="form-group"><label>Confirmer</label>
        <input type="password" class="form-control" id="pwdConfirm" placeholder="••••••••">
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mPwd').classList.remove('open')">Annuler</button>
      <button class="btn btn-primary" onclick="savePwd()"><i class="ph ph-key" aria-hidden="true"></i> Réinitialiser</button>
    </div>
  </div>
</div>

<script>
function uFormatNom(el){ el.value=el.value.toUpperCase().replace(/[^A-ZÀ-ÖØ-Þ '-]/g,''); }
const PWD_RULE = /^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/;
function uCheckEmail(el){ el.classList.toggle('field-invalid', !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim())); }
function uCheckTel(el){ const v=el.value.trim(); el.classList.toggle('field-invalid', v!=='' && !/^[0-9]{10}$/.test(v)); }
function openMU(){ resetUF(); document.getElementById('mU').classList.add('open'); }
function closeMU(){ document.getElementById('mU').classList.remove('open'); }
function resetUF(){
  ['uId','uPrenom','uNom','uEmail','uTel','uPwd'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('uEmail').classList.remove('field-invalid');
  document.getElementById('uTel').classList.remove('field-invalid');
  document.getElementById('uRole').selectedIndex=1; document.getElementById('uSite').value='';
  document.getElementById('mUAlert').innerHTML=''; document.getElementById('mUT').textContent='Nouvel utilisateur';
  document.getElementById('uPwdWrap').style.display='block';
  document.getElementById('uActifWrap').style.display='none';
  document.getElementById('uActif').checked=true;
  document.getElementById('uNotifMail').checked=true;
}

function editU(id){
  ap({action:'get',id}).then(d=>{
    if(!d.success){toast(d.message,'danger');return;}
    const r=d.data;
    document.getElementById('mUT').textContent='Modifier : '+r.prenom+' '+r.nom;
    document.getElementById('uId').value=r.id; document.getElementById('uPrenom').value=r.prenom;
    document.getElementById('uNom').value=r.nom; document.getElementById('uEmail').value=r.email;
    document.getElementById('uRole').value=r.role_id; document.getElementById('uSite').value=r.site_id||'';
    document.getElementById('uTel').value=r.telephone||'';
    document.getElementById('uEmail').classList.remove('field-invalid');
    document.getElementById('uTel').classList.remove('field-invalid');
    document.getElementById('uPwdWrap').style.display='none';
    document.getElementById('uActifWrap').style.display='block';
    document.getElementById('uActif').checked=r.actif==1;
    document.getElementById('uNotifMail').checked=r.notif_mail!=0;
    document.getElementById('mU').classList.add('open');
  });
}

function saveU(){
  const id=document.getElementById('uId').value, btn=document.getElementById('bSU');
  const alertBox=document.getElementById('mUAlert');
  const prenom=document.getElementById('uPrenom').value.trim(), nom=document.getElementById('uNom').value.trim();
  const email=document.getElementById('uEmail').value.trim(), tel=document.getElementById('uTel').value.trim();
  const pwd=document.getElementById('uPwd').value;
  const err=m=>{alertBox.innerHTML=`<div class="alert alert-danger">${m}</div>`;};
  uCheckEmail(document.getElementById('uEmail')); uCheckTel(document.getElementById('uTel'));
  if(!prenom||!nom){err('Prénom et nom sont obligatoires (lettres uniquement).');return;}
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){err('Email invalide.');return;}
  if(tel && !/^[0-9]{10}$/.test(tel)){err('Téléphone invalide : 10 chiffres exactement.');return;}
  if(!id && !PWD_RULE.test(pwd)){err('Mot de passe invalide : min. 8 caractères, au moins une majuscule, un chiffre et un caractère spécial.');return;}
  alertBox.innerHTML='';
  btn.disabled=true; btn.textContent='Enregistrement…';
  ap({action:id?'update':'create',id, prenom, nom, email, telephone:tel, password:pwd,
    role_id:document.getElementById('uRole').value, site_id:document.getElementById('uSite').value,
    actif:document.getElementById('uActif').checked?1:0,
    notif_mail:document.getElementById('uNotifMail').checked?1:'',
  }).then(d=>{
    btn.disabled=false; btn.textContent='💾 Enregistrer';
    if(d.success){toast(d.message,'success');closeMU();setTimeout(()=>location.reload(),800);}
    else err(d.message);
  });
}

function viewU(id){
  document.getElementById('mUD').classList.add('open');
  document.getElementById('udB').innerHTML='<div style="text-align:center;padding:40px;color:var(--muted)">Chargement…</div>';
  ap({action:'get',id}).then(d=>{
    if(!d.success)return;
    const r=d.data;
    document.getElementById('udT').textContent=r.prenom+' '+r.nom;
    document.getElementById('udB').innerHTML=`
      <div style="text-align:center;margin-bottom:20px">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--navy);display:flex;align-items:center;justify-content:center;color:white;font-size:26px;font-weight:700;margin:0 auto 12px">${r.prenom[0]}${r.nom[0]}</div>
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:700">${r.prenom} ${r.nom}</div>
        <span class="role-badge ${r.role_slug}" style="margin-top:6px;display:inline-block">${r.role_slug}</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 20px">
        ${[['Email',r.email],['Téléphone',r.telephone||'—'],['Site',r.site_nom||'—'],
           ['Statut',r.actif==1?'<i class="ph ph-check-circle" aria-hidden="true"></i> Actif':'<i class="ph ph-x-circle" aria-hidden="true"></i> Inactif'],
           ['Dernière connexion',r.last_login||'Jamais'],
           ['Actions réalisées',r.nb_actions+' actions'],
           ['Équipements assignés',r.nb_equips+' équipement(s)'],
           ['Créé le',r.created_at]
          ].map(([l,v])=>`<div style="padding:7px 0;border-bottom:1px solid var(--border)">
            <div style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">${l}</div>
            <div style="font-size:13.5px">${v}</div></div>`).join('')}
      </div>`;
  });
}

function resetPwd(id,nom){
  document.getElementById('pwdUId').value=id;
  document.getElementById('pwdUNom').textContent=nom;
  document.getElementById('pwdNew').value=''; document.getElementById('pwdConfirm').value='';
  document.getElementById('pwdAlert').innerHTML='';
  document.getElementById('mPwd').classList.add('open');
}
function savePwd(){
  const p1=document.getElementById('pwdNew').value, p2=document.getElementById('pwdConfirm').value;
  const alertBox=document.getElementById('pwdAlert');
  if(p1!==p2){alertBox.innerHTML='<div class="alert alert-danger">Les mots de passe ne correspondent pas.</div>';return;}
  if(!PWD_RULE.test(p1)){alertBox.innerHTML='<div class="alert alert-danger">Mot de passe invalide : min. 8 caractères, au moins une majuscule, un chiffre et un caractère spécial.</div>';return;}
  alertBox.innerHTML='';
  ap({action:'reset_pwd',id:document.getElementById('pwdUId').value,pwd:p1}).then(d=>{
    if(d.success){toast(d.message,'success');document.getElementById('mPwd').classList.remove('open');}
    else document.getElementById('pwdAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
  });
}

function toggleActif(id,newVal,nom){
  const msg=newVal?`Activer le compte de ${nom} ?`:`Désactiver le compte de ${nom} ?`;
  if(!confirm(msg))return;
  ap({action:'toggle_actif',id,actif:newVal}).then(d=>{
    toast(d.message,d.success?'success':'danger');
    if(d.success)setTimeout(()=>location.reload(),800);
  });
}

function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
}
['mU','mUD','mPwd'].forEach(id=>document.getElementById(id).addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');}));

// ── Recherche / filtres réactifs (sans rechargement) ──────────
(function(){
  const form   = document.getElementById('usersFilterForm');
  const qInput = form.querySelector('input[name="q"]');
  const rSel   = form.querySelector('select[name="role"]');
  const aSel   = form.querySelector('select[name="actif"]');
  let debounceTimer, controller;

  function buildUrl(){
    const params = new URLSearchParams();
    for (const [k,v] of new FormData(form).entries()) if (v !== '') params.set(k, v);
    const qs = params.toString();
    return 'users.php' + (qs ? '?' + qs : '');
  }

  function loadUsers(url, push){
    if (controller) controller.abort();
    controller = new AbortController();
    fetch(url, {signal: controller.signal})
      .then(r => r.text())
      .then(html => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        ['usersTableWrap', 'clearFiltersWrap'].forEach(id => {
          const fresh = doc.getElementById(id), cur = document.getElementById(id);
          if (fresh && cur) cur.innerHTML = fresh.innerHTML;
        });
        const params = new URLSearchParams(url.split('?')[1] || '');
        if (document.activeElement !== qInput) qInput.value = params.get('q') || '';
        rSel.value = params.get('role') || '0';
        aSel.value = params.get('actif') || '';
        if (push) history.pushState({}, '', url);
      })
      .catch(err => { if (err.name !== 'AbortError') console.error(err); });
  }

  qInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadUsers(buildUrl(), true), 350);
  });
  rSel.addEventListener('change', () => loadUsers(buildUrl(), true));
  aSel.addEventListener('change', () => loadUsers(buildUrl(), true));
  form.addEventListener('submit', e => { e.preventDefault(); clearTimeout(debounceTimer); loadUsers(buildUrl(), true); });

  // Liens interceptés : pagination (dans le tableau) + bouton "effacer les filtres"
  document.addEventListener('click', e => {
    const a = e.target.closest('#usersTableWrap a[href], #clearFiltersWrap a[href]');
    if (!a) return;
    e.preventDefault();
    clearTimeout(debounceTimer);
    loadUsers(a.getAttribute('href'), true);
  });

  window.addEventListener('popstate', () => loadUsers(location.pathname + location.search, false));
})();
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
