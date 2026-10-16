  </main><!-- end .page-content -->
</div><!-- end .main-wrap -->

<script>
// ===== USER MENU =====
function toggleUserMenu(e) {
  e.stopPropagation();
  const dd     = document.getElementById('user-menu-dd');
  const caret  = document.getElementById('user-chip-caret');
  const open   = dd.style.display !== 'none';
  dd.style.display    = open ? 'none' : 'block';
  caret.style.transform = open ? '' : 'rotate(180deg)';
}
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('user-menu-wrap');
  if (wrap && !wrap.contains(e.target)) {
    const dd    = document.getElementById('user-menu-dd');
    const caret = document.getElementById('user-chip-caret');
    if (dd)    dd.style.display     = 'none';
    if (caret) caret.style.transform = '';
  }
});

// ===== NOTIFICATIONS =====
function toggleNotifs() {
  document.getElementById('notif-dropdown').classList.toggle('open');
}
document.addEventListener('click', e => {
  const dd = document.getElementById('notif-dropdown');
  if (!e.target.closest('.notif-btn') && !e.target.closest('.notif-dropdown')) {
    dd.classList.remove('open');
  }
});
function readNotif(id, lien) {
  fetch('<?= APP_URL ?>/api/notifications.php', {
    method: 'POST',
    headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=mark_read&id=' + id
  }).then(() => { if (lien) window.location.href = '<?= APP_URL ?>' + lien; else refreshNotifs(); });
}
function markAllRead() {
  fetch('<?= APP_URL ?>/api/notifications.php', {
    method: 'POST',
    headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=mark_all'
  }).then(() => refreshNotifs());
}
// ── Session expirée : l'onglet reste affiché avec sa saisie, mais toute requête
//    ramène désormais la page de connexion. Sans avertissement, on ne le
//    découvrait qu'au clic sur « Enregistrer », devant un message illisible.
//    Se reconnecter dans un AUTRE onglet suffit (même cookie) : le formulaire
//    de celui-ci n'est pas perdu.
function sessionExpiree() {
  if (document.getElementById('bandeau-session')) return;
  const b = document.createElement('div');
  b.id = 'bandeau-session';
  b.setAttribute('role', 'alert');
  b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:10000;padding:12px 20px;background:#FEF3C7;color:#8A5A00;border-bottom:2px solid #FBBF24;font-size:14px;font-weight:600;text-align:center';
  b.innerHTML = 'Votre session a expiré (inactivité). <a href="<?= APP_URL ?>/login.php" target="_blank" rel="noopener" style="color:#8A5A00;text-decoration:underline">Reconnectez-vous dans un nouvel onglet</a>, puis revenez ici : ce que vous avez saisi est conservé.';
  document.body.appendChild(b);
}
function sessionRetablie() {
  const b = document.getElementById('bandeau-session');
  if (b) b.remove();
}
function refreshNotifs() {
  fetch('<?= APP_URL ?>/api/notifications.php?action=get', {
    headers: {'X-Requested-With':'XMLHttpRequest'}
  }).then(r => {
    if (r.redirected && /\/login\.php/i.test(r.url)) { sessionExpiree(); return null; }
    sessionRetablie();
    return r.json();
  }).then(d=>{
    if (!d || !d.success) return;
    const count = d.data.count || 0;
    const badge = document.querySelector('.notif-count');
    const btn   = document.querySelector('.notif-btn');
    if (badge) { badge.textContent = count > 9 ? '9+' : count; badge.style.display = count > 0 ? '' : 'none'; }
    else if (count > 0 && btn) {
      const s = document.createElement('span');
      s.className = 'notif-count'; s.textContent = count > 9 ? '9+' : count;
      btn.appendChild(s);
    }
    const list = document.querySelector('.notif-list');
    if (!list) return;
    if (count === 0) { list.innerHTML = '<div class="notif-empty"><i class="ph ph-check-circle" aria-hidden="true"></i> Aucune notification</div>'; return; }
    const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    list.innerHTML = d.data.data.map(n=>`
      <div class="notif-item" data-id="${n.id}" data-lien="${esc(n.lien||'')}">
        <div class="n-titre">${esc(n.titre)}</div>
        <div class="n-date">${esc(n.created_at)}</div>
      </div>`).join('');
    list.querySelectorAll('.notif-item').forEach(el=>{
      el.addEventListener('click',()=>readNotif(el.dataset.id, el.dataset.lien));
    });
  }).catch(()=>{});
}
// Rafraîchir toutes les 20 secondes — pause quand l'onglet n'est pas
// visible, même principe que liveRefresh() ci-dessous : pas la peine
// d'interroger le serveur pour un écran que personne ne regarde.
setInterval(() => { if (!document.hidden) refreshNotifs(); }, 20000);
// Retour sur l'onglet (téléphone, autre application, écran verrouillé) : on
// vérifie tout de suite que la session tient, sans attendre les 20 secondes.
document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshNotifs(); });

// ===== RAFRAICHISSEMENT LIVE (polling léger) =====
// Remplace périodiquement le contenu d'un conteneur par un fragment HTML
// renvoyé par le serveur (même page, ?fragment=1) — pas de connexion
// persistante (l'hébergement actuel ne le permet pas), juste un appel
// espacé. S'interrompt quand l'onglet n'est pas visible ou qu'une modale
// est ouverte : on ne doit jamais remplacer l'écran sous les yeux de
// quelqu'un qui a une fenêtre ouverte ou un champ en cours de saisie.
// Usage : liveRefresh({ url, container: '#id', interval: 10000 }).
function liveRefresh(opts) {
  const container = typeof opts.container === 'string' ? document.querySelector(opts.container) : opts.container;
  if (!container) return;
  const interval = opts.interval || 10000;
  // Par défaut : pause si une modale est ouverte, OU si le focus est
  // actuellement sur un champ de saisie à l'intérieur du conteneur (ex.
  // suivi_achats.php a des <input> de N° DA/BC directement dans le
  // tableau, sans modale — remplacer le HTML sous un champ en cours de
  // frappe effacerait ce que la personne est en train de taper).
  const isBusy = opts.isBusy || (() => {
    if (document.querySelector('.ach-modal-bg.open, .modal-overlay.show')) return true;
    const active = document.activeElement;
    return !!active && container.contains(active) && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName);
  });
  let inFlight = false;
  let timer = null;
  // Une fois la session perdue, le rafraîchissement ne peut plus rien
  // rapporter : on l'arrête et on le dit, plutôt que de laisser un écran
  // figé qui se présente comme vivant.
  function arreter(raison) {
    if (timer) { clearInterval(timer); timer = null; }
    if (raison && typeof toast === 'function') toast(raison, 'danger');
  }
  function tick() {
    if (document.hidden || isBusy() || inFlight) return;
    inFlight = true;
    fetch(opts.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => {
        // fetch() suit les redirections sans le dire : session expirée,
        // require_auth() renvoie un 302 vers login.php et la réponse finale
        // est un 200 — r.ok est donc vrai. Sans ce test, le HTML complet de
        // la page de connexion (formulaire, styles et scripts) s'injectait
        // dans le tableau de l'écran.
        if (r.redirected) { arreter('Session expirée — rechargez la page pour continuer.'); return null; }
        return r.ok ? r.text() : Promise.reject();
      })
      .then(html => {
        if (html === null) return;
        container.innerHTML = html;
        if (opts.onUpdate) opts.onUpdate();
      })
      .catch(() => {})
      .finally(() => { inFlight = false; });
  }
  timer = setInterval(tick, interval);
}

// ===== DÉCONNEXION AUTOMATIQUE APRÈS INACTIVITÉ =====
// Basé sur de vrais événements utilisateur (souris, clavier, tactile) —
// jamais sur les requêtes de fond (refreshNotifs, liveRefresh) : sinon un
// onglet resté ouvert et visible mais que personne ne touche ne se
// déconnecterait jamais, à cause du polling qui tournerait quand même.
(function() {
  // 15 minutes — doit rester aligné sur INACTIVITY_TIMEOUT (includes/db.php) :
  // deux délais différents donneraient une déconnexion qui dépend de l'onglet.
  const DELAI_MS = 15 * 60 * 1000;
  let timer = null;
  function reinitialiser() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      window.location.href = '<?= APP_URL ?>/logout.php?timeout=1';
    }, DELAI_MS);
  }
  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'].forEach(evt => {
    document.addEventListener(evt, reinitialiser, { passive: true });
  });
  reinitialiser();
})();

// ===== SIDEBAR SCROLL RESTORE =====
(function() {
  const sidebar = document.querySelector('.sidebar');
  if (!sidebar) return;
  const KEY = 'digistock_sidebar_scroll';
  const saved = sessionStorage.getItem(KEY);
  if (saved) sidebar.scrollTop = parseInt(saved, 10);
  sidebar.addEventListener('scroll', () => {
    sessionStorage.setItem(KEY, sidebar.scrollTop);
  });
})();

// ===== TOAST NOTIFICATIONS =====
// Région aria-live unique et réutilisée (pas un <div> recréé à chaque appel) :
// un lecteur d'écran surveille un nœud stable de façon fiable, alors que
// détecter l'insertion répétée de nouveaux nœuds porteurs de role="status"
// est moins constant d'un lecteur d'écran à l'autre.
function toast(msg, type = 'success') {
  let t = document.getElementById('toast-live');
  const neuf = !t;
  if (neuf) {
    t = document.createElement('div');
    t.id = 'toast-live';
    t.setAttribute('role', 'status');
    t.setAttribute('aria-live', 'polite');
    t.setAttribute('aria-atomic', 'true');
    document.body.appendChild(t);
  }
  clearTimeout(t._hideTimer);
  t.className = `toast toast-${type}`;
  t.innerHTML = msg;
  setTimeout(() => t.classList.add('show'), neuf ? 10 : 0);
  t._hideTimer = setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Lit une réponse fetch() en JSON, mais fait apparaître l'erreur réelle
//    (code HTTP + début du corps) si ce n'en est pas — sans elle, un fatal
//    PHP ou une coupure réseau fait échouer r.json() en silence : le bouton
//    « ne répond pas », sans aucun message ni dans l'écran ni ailleurs.
function achParseJson(r) {
  return r.text().then(t => {
    // Session perdue : require_auth() renvoie un 302 vers login.php, que fetch()
    // suit sans le dire — la « réponse » est la page de connexion, en HTTP 200.
    // Message clair plutôt que le début de ce HTML.
    if (r.redirected && /\/login\.php/i.test(r.url)) {
      if (typeof sessionExpiree === 'function') sessionExpiree();
      throw new Error('Votre session a expiré (inactivité). Reconnectez-vous dans un nouvel onglet : ce que vous avez saisi ici est conservé. Revenez ensuite sur cet onglet et recommencez.');
    }
    try {
      return JSON.parse(t);
    } catch (e) {
      // Avertissement ou notice PHP écrits avant la réponse : le serveur a
      // bien répondu (et exécuté l'action), seule la lecture échouait — et le
      // bouton restait muet. On récupère le JSON qui suit, et on signale la
      // sortie parasite dans la console pour qu'elle soit corrigée.
      const i = t.search(/\{\s*"success"\s*:/);
      const j = t.lastIndexOf('}');
      if (i > 0 && j > i) {
        try {
          const d = JSON.parse(t.slice(i, j + 1));
          console.warn('Sortie inattendue du serveur avant la réponse :', t.slice(0, i).trim());
          return d;
        } catch (e2) { /* illisible : erreur ci-dessous */ }
      }
      throw new Error(`Réponse serveur invalide (HTTP ${r.status}) : ${t.slice(0, 300)}`);
    }
  });
}
</script>

<style>
.toast {
  position: fixed; bottom: 24px; right: 24px; z-index: 9999;
  padding: 14px 20px; border-radius: 10px;
  font-size: 13.5px; font-weight: 500;
  box-shadow: 0 8px 24px rgba(0,0,0,.15);
  opacity: 0; transform: translateY(10px);
  transition: background-color .3s ease, border-color .3s ease, color .3s ease, box-shadow .3s ease, transform .3s ease, opacity .3s ease;
  max-width: 360px;
}
.toast.show { opacity: 1; transform: translateY(0); }
.toast-success { background: #eafaf1; color: #1e8449; border-left: 3px solid #27ae60; }
.toast-danger  { background: #fdf0ef; color: #922b21; border-left: 3px solid #e74c3c; }
.toast-warning { background: #fef9e7; color: #9a7d0a; border-left: 3px solid #f39c12; }
.toast-info    { background: #ebf5fb; color: #1a5276; border-left: 3px solid #2980b9; }
</style>

</body>
</html>
