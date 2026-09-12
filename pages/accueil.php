<?php
// ============================================================
//  pages/accueil.php — Page d'accueil post-connexion (standalone)
// ============================================================
if (!headers_sent()) {
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: default-src * 'unsafe-inline' 'unsafe-eval' data: blob:; script-src * 'unsafe-inline' 'unsafe-eval' blob: data:; style-src * 'unsafe-inline' data:; font-src * data:; img-src * data: blob:;");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/groupes_config.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();

$user   = current_user();
$notifs = notif_get_unread($user['id']);
$unread = count($notifs);

// ── Sélection d'un groupe → mémorise en session et redirige
if (!empty($_GET['set_groupe'])) {
    $slug = strtoupper(trim($_GET['set_groupe']));
    // get_groupes_utilisateur() plutôt que get_groupes_pour_role() : ce
    // dernier ne connaît que les groupes en dur dans $map, pas ceux ajoutés
    // par droit DB (ex. INVENTAIRE via can('inventaire','can_read')) —
    // sinon le clic sur ce groupe ne redirigeait nulle part (trouvé en
    // rendant INVENTAIRE éditable depuis Admin → Permissions, 2026-08-28).
    $accessibles = array_keys(get_groupes_utilisateur());
    if (in_array($slug, $accessibles)) {
        $_SESSION['groupe_actif'] = $slug;
        $def = get_groupe_def($slug);
        // On atterrit sur la première entrée réellement accessible au rôle,
        // plutôt que sur une page fixe : le lecteur n'a pas le tableau de
        // bord dans son menu et serait envoyé sur une page absente de sa
        // navigation.
        $items = get_groupe_nav_items($slug);
        $first = $items[0]['url'] ?? ($def['first_page'] ?? 'pages/dashboard.php');
        header('Location: ' . APP_URL . '/' . $first);
        exit;
    }
}

unset($_SESSION['groupe_actif']);

$groupes   = get_groupes_utilisateur();
$initiales = strtoupper(substr($user['prenom']??'',0,1).substr($user['nom']??'',0,1));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accueil — <?= APP_NAME ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:    #06033A;
      --blue:    #1B75BC;
      --sky:     #00AEEF;
      --text:    #1E2B4A;
      --muted:   #64748B;
      --border:  #E2E8F0;
      --bg:      #F4F7FB;
      --white:   #ffffff;
      --primary: #7C92FF;
      --primary-l: #EEF2FF;
    }

    body {
      font-family: 'Manrope', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ===== NAVBAR ===== */
    .navbar {
      height: 60px;
      background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 32px;
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 1px 8px rgba(6,3,58,.06);
    }

    .navbar-brand {
      display: flex; align-items: center; gap: 10px;
      text-decoration: none; color: var(--text);
    }
    .navbar-brand-logo {
      width: 36px; height: 36px; border-radius: 10px;
      background: linear-gradient(135deg, var(--navy), var(--blue));
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .navbar-brand-logo svg { display: block; }
    .navbar-brand-name {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 16px; font-weight: 800;
      color: var(--navy); letter-spacing: -.3px;
    }
    .navbar-brand-sep { width: 1px; height: 18px; background: var(--border); margin: 0 4px; }
    .navbar-accueil {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 14px; font-weight: 600; color: var(--blue);
    }

    /* ── Notifications ── */
    .notif-wrap { position: relative; }
    .notif-btn {
      width: 38px; height: 38px; border-radius: 10px;
      background: transparent; border: 1.5px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; transition: background .15s, border-color .15s;
      position: relative;
    }
    .notif-btn:hover { background: var(--primary-l); border-color: var(--primary); }
    .notif-count {
      position: absolute; top: -5px; right: -5px;
      min-width: 18px; height: 18px; padding: 0 4px;
      background: #EF4444; color: white;
      font-size: 12px; font-weight: 700;
      border-radius: 9px; display: flex; align-items: center; justify-content: center;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .notif-dropdown {
      display: none; position: absolute;
      top: calc(100% + 8px); right: 0;
      width: 340px; background: white;
      border: 1.5px solid var(--border); border-radius: 16px;
      box-shadow: 0 8px 28px rgba(6,3,58,.14); z-index: 200;
      overflow: hidden;
    }
    .notif-dropdown.open { display: block; animation: dropDown .2s ease; }
    @keyframes dropDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
    .notif-header {
      padding: 14px 18px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .notif-header h4 { font-size: 14px; font-weight: 700; color: var(--navy); font-family: 'Plus Jakarta Sans', sans-serif; }
    .notif-header a  { font-size: 12px; color: var(--blue); text-decoration: none; font-weight: 600; }
    .notif-list { max-height: 320px; overflow-y: auto; }
    .notif-item { padding: 12px 18px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background .15s; }
    .notif-item:hover { background: #F8FAFC; }
    .notif-item:last-child { border-bottom: none; }
    .notif-item .n-titre { font-size: 13px; font-weight: 600; color: var(--navy); }
    .notif-item .n-date  { font-size: 12px; color: var(--muted); margin-top: 3px; }
    .notif-empty { padding: 28px; text-align: center; color: var(--muted); font-size: 13px; }

    /* ── User chip (dropdown) ── */
    .uc-wrap { position: relative; }
    .uc-btn {
      display: flex; align-items: center; gap: 8px;
      background: var(--primary-l);
      border: 1.5px solid var(--primary-l);
      border-radius: 12px;
      padding: 7px 12px 7px 8px;
      cursor: pointer;
      transition: border-color .2s, background .2s;
      font-family: 'Manrope', sans-serif;
    }
    .uc-btn:hover { border-color: var(--primary); background: #E0E7FF; }
    .uc-avatar {
      width: 30px; height: 30px; border-radius: 8px;
      background: var(--primary-d);
      display: flex; align-items: center; justify-content: center;
      color: white; font-size: 12px; font-weight: 800;
      font-family: 'Plus Jakarta Sans', sans-serif;
      flex-shrink: 0;
    }
    .uc-name { font-size: 12.5px; font-weight: 700; color: var(--navy); line-height: 1.2; }
    .uc-role { font-size: 10.5px; color: var(--muted); line-height: 1.2; }
    .uc-caret { font-size: 12px; color: var(--muted); margin-left: 2px; flex-shrink: 0; transition: transform .2s; }

    /* Dropdown */
    .uc-dd {
      display: none;
      position: absolute; top: calc(100% + 6px); right: 0;
      background: white; border: 1px solid var(--border);
      border-radius: 14px;
      box-shadow: 0 8px 28px rgba(6,3,58,.14);
      min-width: 200px; z-index: 200;
      padding: 6px;
      overflow: hidden;
    }
    .uc-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px;
      border-radius: 9px;
      text-decoration: none;
      font-size: 13.5px; font-weight: 600;
      color: var(--navy);
      transition: background .15s;
    }
    .uc-item:hover { background: var(--primary-l); }
    .uc-item i { font-size: 18px; color: var(--primary-d); }
    .uc-item.logout { color: #B91C1C; }
    .uc-item.logout i { color: #B91C1C; }
    .uc-item.logout:hover { background: #FEE2E2; }
    .uc-divider { height: 1px; background: var(--border); margin: 4px 0; }

    /* ===== PAGE BODY ===== */
    .page-body {
      flex: 1;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 40px 24px 60px;
    }
    .accueil-wrap { width: 100%; max-width: 860px; }

    .accueil-subtitle {
      font-size: 12px; font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 1.2px;
      margin-bottom: 20px;
      display: flex; align-items: center; gap: 8px;
    }
    .accueil-subtitle::after {
      content: ''; flex: 1;
      height: 1px; background: var(--border);
    }

    /* ===== GRILLE GROUPES ===== */
    .groupes-list {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
    }

    .groupe-bloc {
      aspect-ratio: 1 / 1;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      gap: 12px; padding: 20px 16px;
      background: var(--white);
      border-radius: 18px; border: 1.5px solid var(--border);
      box-shadow: 0 2px 10px rgba(6,3,58,.05);
      text-decoration: none; color: inherit;
      transition: background-color .2s cubic-bezier(.4,0,.2,1), border-color .2s cubic-bezier(.4,0,.2,1), color .2s cubic-bezier(.4,0,.2,1), box-shadow .2s cubic-bezier(.4,0,.2,1), transform .2s cubic-bezier(.4,0,.2,1), opacity .2s cubic-bezier(.4,0,.2,1);
      position: relative; overflow: hidden; text-align: center;
    }
    .groupe-bloc::after {
      content: ''; position: absolute; inset: 0;
      background: var(--g-gradient); opacity: 0; transition: opacity .2s; border-radius: 18px;
    }
    .groupe-bloc:hover {
      border-color: transparent;
      box-shadow: 0 10px 32px rgba(6,3,58,.16);
      transform: translateY(-4px);
    }
    .groupe-bloc:hover::after            { opacity: 1; }
    .groupe-bloc:hover .groupe-icon-box  { background: rgba(255,255,255,.22); box-shadow: none; }
    .groupe-bloc:hover .groupe-text h3   { color: #fff; }
    .groupe-bloc:hover .groupe-text p    { color: rgba(255,255,255,.72); }

    .groupe-icon-box, .groupe-text { position: relative; z-index: 1; }

    .groupe-icon-box {
      width: 58px; height: 58px; border-radius: 16px;
      background: var(--g-gradient);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 4px 14px rgba(6,3,58,.18);
      transition: background .2s, box-shadow .2s;
    }
    .groupe-icon-box i { font-size: 28px; color: white; }

    .groupe-text { text-align: center; }
    .groupe-text h3 {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 13.5px; font-weight: 700; color: var(--navy);
      margin-bottom: 4px; transition: color .2s; line-height: 1.2;
    }
    .groupe-text p {
      font-size: 12px; color: var(--muted);
      line-height: 1.4; transition: color .2s;
    }

    @media (max-width: 700px) {
      .groupes-list { grid-template-columns: repeat(3, 1fr); gap: 10px; }
      .groupe-icon-box { width: 46px; height: 46px; border-radius: 12px; }
      .groupe-icon-box i { font-size: 22px; }
      .groupe-text p { display: none; }
    }
    @media (max-width: 480px) {
      .navbar { padding: 0 14px; }
      .navbar-brand-sep, .navbar-accueil { display: none; }
      .uc-name, .uc-role, .uc-caret { display: none; }
      .page-body { padding: 20px 12px 40px; }
      .groupes-list { grid-template-columns: repeat(3, 1fr); gap: 8px; }
      .groupe-bloc { border-radius: 14px; gap: 8px; }
      .groupe-text h3 { font-size: 12px; }
    }
  </style>
</head>
<body>

<!-- ===== NAVBAR ===== -->
<nav class="navbar">

  <!-- Gauche : Logo + "Accueil" -->
  <a href="<?= APP_URL ?>/pages/accueil.php" class="navbar-brand">
    <div class="navbar-brand-logo">
      <svg viewBox="0 0 22 22" width="22" height="22" fill="none">
        <rect x="1"    y="1"    width="8.5" height="8.5" rx="2" fill="white" opacity=".95"/>
        <rect x="12.5" y="1"    width="8.5" height="8.5" rx="2" fill="white" opacity=".5"/>
        <rect x="1"    y="12.5" width="8.5" height="8.5" rx="2" fill="white" opacity=".5"/>
        <rect x="12.5" y="12.5" width="8.5" height="8.5" rx="2" fill="white" opacity=".95"/>
      </svg>
    </div>
    <span class="navbar-brand-name">ERP EMUCI</span>
    <span class="navbar-brand-sep"></span>
    <span class="navbar-accueil">Accueil</span>
  </a>

  <!-- Droite : Notifications + Chip utilisateur -->
  <div style="display:flex;align-items:center;gap:10px">

  <!-- Cloche notifications -->
  <div class="notif-wrap">
    <button class="notif-btn" onclick="toggleNotifs()" title="Notifications">
      <i class="ph-duotone ph-bell" style="font-size:20px;color:var(--muted)"></i>
      <?php if ($unread > 0): ?>
        <span class="notif-count"><?= $unread > 9 ? '9+' : $unread ?></span>
      <?php endif; ?>
    </button>
    <div class="notif-dropdown" id="notif-dropdown">
      <div class="notif-header">
        <h4>Notifications <?php if ($unread): ?><span style="color:#EF4444">(<?= $unread ?>)</span><?php endif; ?></h4>
        <a href="javascript:void(0)" onclick="markAllRead()">Tout marquer lu</a>
      </div>
      <div class="notif-list" id="notif-list">
        <?php if (empty($notifs)): ?>
          <div class="notif-empty"><i class="ph ph-check-circle" aria-hidden="true"></i> Aucune notification</div>
        <?php else: ?>
          <?php foreach ($notifs as $n): ?>
            <div class="notif-item" onclick="readNotif(<?= $n['id'] ?>, '<?= h($n['lien']??'') ?>')">
              <div class="n-titre"><?= h($n['titre']??'') ?></div>
              <div class="n-date"><?= fmt_datetime($n['created_at']??'') ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Chip utilisateur avec dropdown -->
  <div class="uc-wrap">
    <button type="button" class="uc-btn" onclick="toggleUcMenu(event)">
      <div class="uc-avatar"><?= $initiales ?></div>
      <div style="text-align:left">
        <div class="uc-name"><?= h(($user['prenom']??'').' '.($user['nom']??'')) ?></div>
        <div class="uc-role"><?= h($user['role_nom']??'') ?></div>
      </div>
      <i class="ph-duotone ph-caret-down uc-caret" id="uc-caret"></i>
    </button>

    <div class="uc-dd" id="uc-dd">
      <a href="<?= APP_URL ?>/pages/mon_profil.php" class="uc-item">
        <i class="ph-duotone ph-user-circle"></i>
        Mon profil
      </a>
      <div class="uc-divider"></div>
      <a href="<?= APP_URL ?>/logout.php" class="uc-item logout">
        <i class="ph-duotone ph-sign-out"></i>
        Déconnexion
      </a>
    </div>
  </div>

  </div><!-- /.navbar-right -->

</nav>

<!-- ===== CONTENU ===== -->
<div class="page-body">
  <div class="accueil-wrap">

    <div class="accueil-subtitle">Mes espaces (<?= count($groupes) ?>)</div>

    <div class="groupes-list">
      <?php foreach ($groupes as $slug => $groupe): ?>
      <a href="?set_groupe=<?= urlencode($slug) ?>"
         class="groupe-bloc"
         style="--g-gradient: <?= h($groupe['gradient']) ?>">

        <div class="groupe-icon-box">
          <i class="ph-duotone <?= h($groupe['icon']) ?>"></i>
        </div>

        <div class="groupe-text">
          <h3><?= h($groupe['titre']) ?></h3>
          <p><?= h($groupe['description']) ?></p>
        </div>

      </a>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<script>
const _APP_URL = '<?= APP_URL ?>';

// ── Notifications ─────────────────────────────────────────────
function toggleNotifs() {
  document.getElementById('notif-dropdown').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.notif-wrap') && !e.target.closest('.uc-wrap')) {
    document.getElementById('notif-dropdown').classList.remove('open');
  }
});
function readNotif(id, lien) {
  fetch(_APP_URL + '/api/notifications.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=mark_read&id=' + id
  }).then(() => { if (lien) window.location.href = _APP_URL + lien; else refreshNotifs(); });
}
function markAllRead() {
  fetch(_APP_URL + '/api/notifications.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=mark_all'
  }).then(() => refreshNotifs());
}
function refreshNotifs() {
  fetch(_APP_URL + '/api/notifications.php?action=get', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  }).then(r => r.json()).then(d => {
    if (!d.success) return;
    const count  = d.data.count || 0;
    const badge  = document.querySelector('.notif-count');
    const btn    = document.querySelector('.notif-btn');
    if (badge) { badge.textContent = count > 9 ? '9+' : count; badge.style.display = count > 0 ? '' : 'none'; }
    else if (count > 0 && btn) {
      const s = document.createElement('span');
      s.className = 'notif-count'; s.textContent = count > 9 ? '9+' : count;
      btn.appendChild(s);
    }
    const list = document.getElementById('notif-list');
    if (!list) return;
    if (count === 0) { list.innerHTML = '<div class="notif-empty"><i class="ph ph-check-circle" aria-hidden="true"></i> Aucune notification</div>'; return; }
    const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    list.innerHTML = d.data.data.map(n => `
      <div class="notif-item" data-id="${n.id}" data-lien="${esc(n.lien||'')}">
        <div class="n-titre">${esc(n.titre)}</div>
        <div class="n-date">${esc(n.created_at)}</div>
      </div>`).join('');
    list.querySelectorAll('.notif-item').forEach(el => {
      el.addEventListener('click', () => readNotif(el.dataset.id, el.dataset.lien));
    });
  }).catch(() => {});
}
// Page standalone (n'inclut pas templates/footer.php) : copie propre du
// même intervalle resserré + pause sur onglet caché que footer.php.
setInterval(() => { if (!document.hidden) refreshNotifs(); }, 20000);

// ===== DÉCONNEXION AUTOMATIQUE APRÈS INACTIVITÉ =====
// Page standalone (n'inclut pas templates/footer.php) : copie du même
// minuteur — sans lui, "Mes espaces" (première page vue après connexion)
// ne surveille jamais l'inactivité réelle, et le polling notifications
// ci-dessus suffit à maintenir la session active indéfiniment côté
// serveur (last_activity rafraîchi à chaque appel). Basé sur de vrais
// événements utilisateur, jamais sur les requêtes de fond, pour la même
// raison que dans footer.php.
(function() {
  const DELAI_MS = 15 * 60 * 1000; // doit rester aligné sur INACTIVITY_TIMEOUT (includes/db.php)
  let timer = null;
  function reinitialiser() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      window.location.href = _APP_URL + '/logout.php?timeout=1';
    }, DELAI_MS);
  }
  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'].forEach(evt => {
    document.addEventListener(evt, reinitialiser, { passive: true });
  });
  reinitialiser();
})();

// ── User chip ─────────────────────────────────────────────────
function toggleUcMenu(e) {
  e.stopPropagation();
  const dd    = document.getElementById('uc-dd');
  const caret = document.getElementById('uc-caret');
  const open  = dd.style.display === 'block';
  dd.style.display       = open ? 'none' : 'block';
  caret.style.transform  = open ? ''     : 'rotate(180deg)';
}
document.addEventListener('click', function(e) {
  const wrap = e.target.closest('.uc-wrap');
  if (!wrap) {
    const dd    = document.getElementById('uc-dd');
    const caret = document.getElementById('uc-caret');
    if (dd)    dd.style.display      = 'none';
    if (caret) caret.style.transform = '';
  }
});
</script>

</body>
</html>
