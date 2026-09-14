<?php
// ============================================================
//  pages/demandes_new.php — Nouvelle demande interne
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/demandes.php';
require_once __DIR__ . '/../includes/demandes_champs.php';

require_auth();
require_permission('demandes', 'can_create');
$user = current_user();
$_SESSION['groupe_actif'] = 'DEMANDES';
$page_title  = 'Nouvelle demande';
$active_page = 'demandes_new';

// Le lien est retiré du menu pour le lecteur, mais l'adresse resterait
// atteignable et le formulaire soumissible : on ferme ici, pour la page
// comme pour l'envoi.
if (!di_peut_creer($user)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        json_response(false, "Votre profil ne permet pas de déposer une demande.");
    }
    redirect_to('/pages/demandes.php');
    exit;
}

// ── AJAX : création / soumission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $is_admin_role = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
    $has_dept = (bool)db_fetch_value(
        "SELECT COUNT(*) FROM user_departements WHERE user_id=?", [$user['id']]
    );
    if (!$has_dept && !$is_admin_role) {
        json_response(false, 'Vous devez être rattaché à un département pour soumettre une demande.');
    }
    $type      = trim($_POST['type_code'] ?? '');
    $champs    = $_POST['champs'] ?? [];
    $soumettre = ($_POST['action'] ?? '') === 'soumettre';
    if (!is_array($champs)) $champs = [];
    try {
        $id = di_creer($user, $type, $champs, $soumettre);
        json_response(true, $soumettre ? 'Demande soumise.' : 'Brouillon enregistré.', ['id' => $id]);
    } catch (Exception $e) {
        json_response(false, $e->getMessage());
    }
}

$types = di_types_actifs();
$sel   = trim($_GET['type'] ?? '');
$sel_type = $sel !== '' ? di_type($sel) : null;

// Vérifier si l'utilisateur a un département assigné
$user_dept = db_fetch_one(
    "SELECT d.label FROM user_departements ud JOIN departements d ON d.id=ud.departement_id WHERE ud.user_id=?",
    [$user['id']]
);
$has_dept = $user_dept !== null;

// Admin et superadmin peuvent toujours soumettre (pas soumis au circuit N+1)
$is_admin_role = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
$can_submit = $has_dept || $is_admin_role;

include __DIR__ . '/../templates/header.php';
?>
<style>
  .di-wrap{max-width:880px;margin:0 auto}
  .di-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
  .di-type-card{display:flex;flex-direction:column;gap:8px;padding:20px;border:1.5px solid #e8ecf3;
    border-radius:16px;background:var(--card,#fff);cursor:pointer;text-decoration:none;color:inherit;transition:.18s;box-shadow:0 1px 2px rgba(20,30,80,.04)}
  .di-type-card:hover{border-color:#7C92FF;transform:translateY(-3px);box-shadow:0 12px 28px rgba(59,79,190,.14)}
  .di-type-card .ic{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;
    background:#3B4FBE;color:#fff;font-size:21px}
  .di-type-card h4{margin:6px 0 0;font-size:15px;font-weight:700}
  .di-type-card p{margin:0;font-size:12.5px;color:#475569;line-height:1.5}
  .di-back{display:inline-flex;align-items:center;gap:7px;font-size:13px;color:#31405e;text-decoration:none;
    font-weight:700;margin:0 0 16px;padding:0 16px;min-height:38px;border-radius:9px;
    background:#fff;border:1px solid #b9c3d6;box-shadow:0 1px 2px rgba(20,30,80,.04);
    transition:background-color .15s,border-color .15s,color .15s}
  .di-back i{font-size:15px}
  .di-back:hover{color:#3B4FBE;background:#eef2f9;border-color:#7d8aa5}
  .di-back:focus-visible{outline:2px solid #3B4FBE;outline-offset:2px}
  .di-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:18px}
  .di-head .ic{width:48px;height:48px;flex-shrink:0;border-radius:14px;display:flex;align-items:center;justify-content:center;
    background:#3B4FBE;color:#fff;font-size:23px;box-shadow:0 6px 16px rgba(59,79,190,.25)}
  .di-head h2{margin:0 0 3px;font-size:21px;font-weight:800;color:var(--navy,#06033A);letter-spacing:-.2px}
  .di-head p{margin:0;font-size:13.5px;color:#475569;line-height:1.5}
  .di-circuit{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:13px 16px;margin-bottom:20px;background:#f4f6fd;border:1px solid #e6eaf8;border-radius:13px}
  .di-circuit-lbl{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#5a6678;margin-right:2px}
  .di-cstep{display:inline-flex;align-items:center;gap:7px;background:#fff;border:1px solid #dfe4fb;border-radius:9px;padding:5px 12px 5px 6px;font-size:12.5px;font-weight:600;color:#3B4FBE}
  .di-cnum{width:19px;height:19px;border-radius:6px;background:#3B4FBE;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
  .di-carrow{color:#b9c2e6;font-size:13px}
  /* ══════════════════════════════════════════════════════════════
     LE FORMULAIRE, EN DOCUMENT RÉGLÉ

     La version précédente posait chaque champ dans sa propre boîte, sur une
     grille à deux colonnes. Deux défauts mesurés : la bordure des champs
     donnait 1,17:1 sur le fond de la carte, là où la norme demande 3:1 pour
     la limite d'un contrôle de saisie, autrement dit on ne voyait pas où
     cliquer ; et chaque champ recevait la même boîte, si bien qu'un nombre
     de jours occupait la largeur d'un nom complet.

     Ici c'est la trame qui porte la limite. Chaque champ est une ligne
     réglée, libellé à gauche, saisie à droite, et la saisie n'a plus de
     boîte à elle : la cellule EST le champ. Les filets sont à 3,5:1, donc
     visibles pour de bon.

     C'est aussi la forme du document imprimé que la demande produit, ce qui
     rapproche l'écran de ce que le visa signera.
     ══════════════════════════════════════════════════════════════ */

  .di-form{background:var(--card,#fff);border:1px solid #cbd3e2;border-radius:12px;
    padding:0;overflow:hidden;box-shadow:0 4px 24px rgba(20,30,80,.05)}

  /* --di-rule : #7d8aa5 donne 3,47:1 sur blanc. En dessous, le filet
     redevient décoratif et la limite du champ disparaît. */
  .di-doc{--di-rule:#7d8aa5;display:grid;grid-template-columns:230px minmax(0,1fr)}
  .di-lbl,.di-val,.di-band{min-width:0}
  .di-val input,.di-val select,.di-val textarea{min-width:0}

  .di-band{grid-column:1/-1;margin:0;padding:9px 18px;background:#e8ecfa;
    border-bottom:1px solid var(--di-rule);border-top:1px solid var(--di-rule);
    font-size:12px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
    color:#2c3a7a;font-family:'Montserrat',sans-serif}
  .di-band:first-child{border-top:none}

  .di-row{display:contents}
  .di-lbl,.di-val{border-bottom:1px solid var(--di-rule);display:flex;align-items:center;min-height:46px}
  .di-lbl{padding:10px 14px 10px 18px;background:#f6f8fd;border-right:1px solid var(--di-rule)}
  .di-lbl label{font-size:13px;font-weight:600;color:#31405e;line-height:1.35;cursor:pointer}
  .di-val{padding:0}
  .di-doc>.di-row:last-child .di-lbl,.di-doc>.di-row:last-child .di-val{border-bottom:none}

  /* Champ à contenu haut : le libellé passe au-dessus, sinon sa colonne
     reste vide sur toute la hauteur du bloc. */
  .di-row-haut .di-lbl{grid-column:1/-1;border-right:none;min-height:0;padding:9px 18px}
  .di-row-haut .di-val{grid-column:1/-1;padding:12px 18px}

  /* La saisie occupe sa cellule et n'a pas de bordure propre : deux limites
     concentriques feraient bavure, et le filet porte déjà le sens. */
  .di-val input:not([type=checkbox]),.di-val select,.di-val textarea{
    width:100%;border:none;background:transparent;font-family:inherit;font-size:14px;
    color:#141c33;padding:0 16px;height:44px;outline:none;border-radius:0;
    transition:background-color .14s}
  .di-val textarea{height:auto;min-height:88px;padding:11px 16px;resize:vertical;line-height:1.55}
  .di-val input::placeholder,.di-val textarea::placeholder{color:#5d6a85}
  .di-val input:not([type=checkbox]):hover,.di-val select:hover,.di-val textarea:hover{background:#f3f6fd}

  /* Le focus peint la cellule et pose un liseré intérieur : sur une trame
     réglée, un anneau extérieur passerait sous les filets voisins. */
  .di-val input:not([type=checkbox]):focus-visible,.di-val select:focus-visible,
  .di-val textarea:focus-visible{background:#eef2ff;box-shadow:inset 0 0 0 2px #3B4FBE}

  /* Champ calculé : lisible, désigné comme calculé, et non modifiable tant
     qu'un type de permission impose sa durée. */
  .di-val input.di-auto{background:#eef1fc;color:#25336f;font-weight:700;cursor:not-allowed}

  /* Une date ou un nombre n'a pas besoin de 400 px. La cellule reste pleine
     largeur pour que le clic porte partout, seul le champ se resserre. */
  .di-val input.di-court{max-width:210px}
  .di-suffixe{font-size:12.5px;color:#475569;padding-right:16px;white-space:nowrap;flex-shrink:0}

  .di-val select{cursor:pointer;appearance:none;-webkit-appearance:none;padding-right:42px;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
    background-repeat:no-repeat;background-position:right 15px center}

  .di-plage{display:flex;align-items:center;gap:8px 20px;width:100%;flex-wrap:wrap}
  .di-plage-part{display:inline-flex;align-items:center;gap:8px;min-width:0}
  .di-plage-part label{font-size:13px;font-weight:600;color:#31405e;cursor:pointer;flex-shrink:0}
  .di-plage input{max-width:180px;padding:0;height:40px}
  .di-plage input:focus-visible{box-shadow:inset 0 0 0 2px #3B4FBE}
  @media(max-width:640px){
    .di-plage{flex-direction:column;align-items:stretch;gap:10px}
    .di-plage-part{justify-content:flex-start}
    .di-plage-part label{width:28px}
    .di-plage input{max-width:none;flex:1}
  }

  .di-req{color:#b42318;text-decoration:none;font-weight:800;margin-left:2px;cursor:help}

  .di-val input[type=checkbox]{width:18px;height:18px;accent-color:#3B4FBE;cursor:pointer;padding:0}
  .di-plats{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;width:100%}
  .di-plat{display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:500;cursor:pointer;
    padding:0 14px;min-height:44px;border:1px solid #b9c3d6;border-radius:8px;background:#fff;transition:.14s}
  .di-plat:hover{border-color:#7d8aa5;background:#f6f8fd}
  .di-plat:has(input:checked){border-color:#3B4FBE;background:#eef1fc;color:#25336f;font-weight:600}
  .di-plat:has(input:focus-visible){box-shadow:0 0 0 3px rgba(59,79,190,.28)}

  .di-req-note{font-size:12.5px;color:#5d6a85}
  .di-req-note b{color:#b42318;font-weight:800}
  .di-actions{display:flex;gap:12px;justify-content:space-between;align-items:center;
    padding:18px 20px;background:#f6f8fd;border-top:1px solid #cbd3e2;flex-wrap:wrap}
  .di-btn{display:inline-flex;align-items:center;gap:8px;padding:0 22px;min-height:44px;
    border:1px solid transparent;border-radius:9px;font-weight:700;font-size:14px;cursor:pointer;
    font-family:inherit;transition:background-color .16s,border-color .16s}
  .di-btn i{font-size:17px}
  .di-btn:focus-visible{outline:2px solid #3B4FBE;outline-offset:2px}
  .di-btn[disabled]{opacity:.62;cursor:progress}
  /* Aplat plutôt que dégradé : PRODUCT.md acte l'abandon des dégradés, et le
     tableau de bord s'en est déjà défait. */
  .di-btn-primary{background:#3B4FBE;color:#fff}
  .di-btn-primary:hover:not([disabled]){background:#31419e}
  .di-btn-ghost{background:#fff;color:#31405e;border-color:#b9c3d6}
  .di-btn-ghost:hover:not([disabled]){background:#eef2f9;border-color:#7d8aa5}

  .di-calc-note{display:flex;gap:9px;align-items:flex-start;margin:0;padding:11px 18px;
    background:#f2f5ff;border-bottom:1px solid #cbd3e2;font-size:12.5px;color:#31405e;line-height:1.5}
  .di-calc-note i{font-size:16px;flex-shrink:0;margin-top:1px;color:#3B4FBE}
  .di-calc-note[hidden]{display:none}

  /* Le bouton dit qu'il travaille. Sans ça, une soumission lente ressemble à
     un clic perdu, et on reclique. */
  .di-tourne{display:inline-block;animation:di-spin .8s linear infinite}
  @keyframes di-spin{to{transform:rotate(360deg)}}
  @media(prefers-reduced-motion:reduce){
    .di-tourne{animation:none;opacity:.65}
    .di-btn,.di-plat,.di-val input,.di-val select,.di-val textarea{transition:none}
  }

  /* Message d'erreur : encadré plein, pas de liseré latéral. */
  .di-alert{display:none}
  .di-alert.err{display:flex;gap:9px;align-items:flex-start;margin:0;padding:13px 18px;
    background:#fdf2f1;color:#8a1c13;border-bottom:1px solid #e6b4ae;font-size:13.5px;font-weight:500}
  .di-alert.err i{font-size:17px;flex-shrink:0;margin-top:1px}

  /* Écran étroit : la colonne des libellés passe au-dessus de la saisie.
     Les filets restent, ce sont eux qui font tenir la lecture. */
  @media(max-width:640px){
    .di-doc{grid-template-columns:minmax(0,1fr)}
    .di-lbl{grid-column:1/-1;border-right:none;border-bottom:none;min-height:0;padding:9px 16px 3px}
    .di-val{grid-column:1/-1}
    .di-actions{flex-direction:column-reverse;align-items:stretch}
    .di-actions .di-btn{justify-content:center}
    .di-req-note{text-align:center}
  }
</style>

<div class="di-wrap">

<?php if (!$can_submit): ?>
  <div style="text-align:center;padding:60px 20px;background:white;border:1.5px solid var(--border,#e2e8f0);border-radius:16px">
    <div style="width:56px;height:56px;border-radius:16px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;color:#94a3b8">
      <i class="ph-duotone ph-lock"></i>
    </div>
    <div style="font-family:'Montserrat',sans-serif;font-size:17px;font-weight:800;color:var(--navy,#06033A);margin-bottom:8px">
      Département non assigné
    </div>
    <div style="font-size:13px;color:var(--muted,#94a3b8);max-width:380px;margin:0 auto;line-height:1.6">
      Vous devez être rattaché à un département avant de pouvoir soumettre une demande interne.<br>
      Contactez un administrateur pour qu'il vous assigne à votre département.
    </div>
  </div>

<?php elseif (!$sel_type): ?>
  <h2 style="margin:0 0 6px">Nouvelle demande interne</h2>
  <p style="color:var(--muted,#7f8c8d);margin:0 0 22px">Choisissez le type de demande à soumettre.</p>
  <div class="di-types">
    <?php foreach ($types as $t): ?>
      <a class="di-type-card" href="?type=<?= urlencode($t['code']) ?>">
        <div class="ic"><i class="ph-duotone ph-file-text"></i></div>
        <h4><?= h($t['label']) ?></h4>
        <p><?= h($t['description']) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
<?php else:
    $type   = $sel_type;
    $fields = di_champs_of($sel);
    $wf     = di_workflow($sel);
?>
  <a href="<?= APP_URL ?>/pages/demandes_new.php" class="di-back"><i class="ph-bold ph-arrow-left"></i> Changer de type</a>
  <div class="di-head">
    <div class="ic"><i class="ph-duotone ph-file-text"></i></div>
    <div>
      <h2><?= h($type['label']) ?></h2>
      <p><?= h($type['description']) ?></p>
    </div>
  </div>
  <div class="di-circuit">
    <span class="di-circuit-lbl">Circuit</span>
    <?php foreach ($wf as $i => $s): ?>
      <?php if ($i): ?><i class="ph-bold ph-arrow-right di-carrow"></i><?php endif; ?>
      <span class="di-cstep"><span class="di-cnum"><?= $i + 1 ?></span><?= h($s['label']) ?></span>
    <?php endforeach; ?>
  </div>

  <form class="di-form" id="di-form" onsubmit="return false">
    <input type="hidden" name="type_code" value="<?= h($sel) ?>">
    <!-- role=alert : l'échec de soumission est annoncé, pas seulement affiché.
         aria-live=assertive parce qu'il interrompt une action volontaire. -->
    <div class="di-alert" id="di-err" role="alert" aria-live="assertive"></div>
    <?= di_render_form($fields) ?>
    <?php if ($sel === 'autorisation_absence'): ?>
      <!-- Masquée tant qu'aucun type de permission n'impose sa durée. Elle
           explique le verrouillage plutôt que de le laisser deviner. -->
      <p class="di-calc-note" id="di-calc-note" hidden>
        <i class="ph-duotone ph-lock-simple"></i>
        Le nombre de jours, la date de fin et la reprise découlent du type de
        permission choisi. Sélectionnez « Aucune » pour les saisir vous-même.
      </p>
    <?php endif; ?>
    <div class="di-actions">
      <span class="di-req-note"><b>*</b> champ obligatoire</span>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <button type="button" class="di-btn di-btn-ghost" data-di-act="brouillon" onclick="diSubmit(this,'brouillon')"><i class="ph-duotone ph-floppy-disk"></i> Enregistrer le brouillon</button>
        <button type="button" class="di-btn di-btn-primary" data-di-act="soumettre" onclick="diSubmit(this,'soumettre')"><i class="ph-duotone ph-paper-plane-tilt"></i> Soumettre la demande</button>
      </div>
    </div>
  </form>
<?php endif; ?>
</div>

<script>
function diSubmit(btn, action){
  const form = document.getElementById('di-form');
  const err  = document.getElementById('di-err');

  // Les champs obligatoires sont vérifiés avant l'envoi : laisser partir la
  // requête pour se voir refuser côté serveur fait perdre un aller-retour et
  // ne dit pas QUEL champ manque. Le brouillon échappe au contrôle, c'est
  // justement ce qu'on enregistre quand la saisie n'est pas finie.
  if (action === 'soumettre' && !form.reportValidity()) return;

  const boutons = form.querySelectorAll('[data-di-act]');
  const libelle = btn.innerHTML;
  boutons.forEach(function(b){ b.disabled = true; });
  btn.innerHTML = '<i class="ph-duotone ph-circle-notch di-tourne"></i> ' +
                  (action === 'soumettre' ? 'Envoi…' : 'Enregistrement…');

  function echouer(message){
    boutons.forEach(function(b){ b.disabled = false; });
    btn.innerHTML = libelle;
    err.className = 'di-alert err';
    err.innerHTML = '<i class="ph-duotone ph-warning-circle"></i><span></span>';
    err.querySelector('span').textContent = message;
    err.scrollIntoView({block:'nearest', behavior:'smooth'});
  }

  err.className = 'di-alert'; err.textContent = '';
  const fd = new FormData(form);
  fd.append('action', action);
  fetch(window.location.href, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(r=>r.json())
    .then(d=>{
      if(d.success){ window.location.href='<?= APP_URL ?>/pages/demandes.php?id='+d.data.id; }
      else { echouer(d.message || "La demande n'a pas pu être enregistrée."); }
    })
    .catch(()=>{ echouer("Envoi impossible : vérifiez votre connexion, puis réessayez."); });
}

// Auto-remplissage agent : quand le demandeur sélectionne un agent dans la datalist,
// tous les champs correspondants présents dans le formulaire se remplissent automatiquement.
(function(){
  // Correspondance data-attribute → id du champ cible dans le formulaire
  var AGENT_FIELDS = {
    'email':       'f_agent_email',
    'telephone':   'f_agent_telephone',
    'fonction':    'f_agent_fonction',
    'matricule':   'f_agent_matricule',
    'departement': 'f_departement',
    'direction':   'f_direction',
    'site':        'f_site'
  };

  document.querySelectorAll('input[data-agentfill]').forEach(function(inp){
    var dl = document.getElementById(inp.getAttribute('list'));
    if(!dl) return;

    function findMatch(val){
      var opts = dl.options;
      for(var i = 0; i < opts.length; i++){
        if(opts[i].value === val) return opts[i];
      }
      return null;
    }

    function fillFromMatch(match){
      Object.keys(AGENT_FIELDS).forEach(function(attr){
        var el = document.getElementById(AGENT_FIELDS[attr]);
        if(!el) return;
        var val = match ? (match.dataset[attr] || '') : '';
        el.value = val;
        // data-editable="1" : le champ reste saisissable même après autofill
        if(val && !el.dataset.editable){
          el.classList.add('di-auto');
          el.readOnly = true;
        } else {
          el.classList.remove('di-auto');
          el.readOnly = false;
        }
      });
    }

    inp.addEventListener('change', function(){
      var match = findMatch(inp.value.trim());
      fillFromMatch(match);
    });

    // Réinitialiser si l'utilisateur efface le champ
    inp.addEventListener('input', function(){
      if(!inp.value.trim()) fillFromMatch(null);
    });
  });
})();
</script>

<?php if ($sel === 'autorisation_absence'): ?>
<script>
// Auto-remplissage : Type de permission (jours entre parenthèses) + Du → jours, Au, reprise.
// La reprise saute au prochain jour ouvré (week-ends + jours fériés de Côte d'Ivoire).
(function(){
  var type  = document.getElementById('f_type_permission');
  var debut = document.getElementById('f_date_debut');
  var jours = document.getElementById('f_nb_jours');
  var fin   = document.getElementById('f_date_fin');
  var rep   = document.getElementById('f_date_reprise');
  var note  = document.getElementById('di-calc-note');
  if(!type || !debut || !jours || !fin || !rep) return;
  var autos = [jours, fin, rep];

  // Fériés islamiques (calendrier lunaire) : à confirmer/compléter chaque année selon le décret
  // officiel. Aïd el-Fitr, Tabaski, Maouloud. 2026 = dates officielles ; 2027 = estimations.
  var FERIES_ISLAM = {
    2026: ['2026-03-20', '2026-05-27', '2026-08-26'],
    2027: ['2027-03-10', '2027-05-16', '2027-08-15']
  };
  var cacheFeries = {};

  function parseISO(iso){ var p = iso.split('-'); return new Date(Date.UTC(+p[0], +p[1]-1, +p[2])); }
  function toISO(d){ return d.toISOString().slice(0, 10); }
  function addDays(iso, n){
    if(!iso) return '';
    var d = parseISO(iso);
    if(isNaN(d.getTime())) return '';
    d.setUTCDate(d.getUTCDate() + n);
    return toISO(d);
  }
  // Dimanche de Pâques (grégorien, algorithme de Computus)
  function paques(y){
    var a=y%19, b=Math.floor(y/100), c=y%100, d=Math.floor(b/4), e=b%4,
        f=Math.floor((b+8)/25), g=Math.floor((b-f+1)/3), h=(19*a+b-d-g+15)%30,
        i=Math.floor(c/4), k=c%4, l=(32+2*e+2*i-h-k)%7, m=Math.floor((a+11*h+22*l)/451),
        mo=Math.floor((h+l-7*m+114)/31), jr=((h+l-7*m+114)%31)+1;
    return new Date(Date.UTC(y, mo-1, jr));
  }
  function feriesDe(y){
    if(cacheFeries[y]) return cacheFeries[y];
    var s = {};
    // Fixes : jour de l'an, fête du travail, fête nationale, assomption, toussaint, paix, noël
    ['01-01','05-01','08-07','08-15','11-01','11-15','12-25'].forEach(function(md){ s[y+'-'+md]=1; });
    // Chrétiens mobiles : lundi de Pâques (+1), Ascension (+39), lundi de Pentecôte (+50)
    var p = paques(y);
    [1,39,50].forEach(function(off){ var d=new Date(p); d.setUTCDate(d.getUTCDate()+off); s[toISO(d)]=1; });
    (FERIES_ISLAM[y] || []).forEach(function(iso){ s[iso]=1; });
    cacheFeries[y] = s;
    return s;
  }
  function estChome(iso){
    var d = parseISO(iso), j = d.getUTCDay();
    if(j===0 || j===6) return true;            // dimanche / samedi
    return !!feriesDe(d.getUTCFullYear())[iso]; // jour férié
  }
  function prochainOuvre(iso){
    if(!iso) return '';
    for(var g=0; g<60 && estChome(iso); g++){ iso = addDays(iso, 1); }
    return iso;
  }
  function setAuto(on){
    autos.forEach(function(el){
      el.readOnly = on;
      el.classList.toggle('di-auto', on);
      el.style.pointerEvents = on ? 'none' : '';
      el.tabIndex = on ? -1 : 0;
      // Un champ calculé doit s'annoncer comme tel : readOnly seul ne dit
      // rien à un lecteur d'écran sur la raison du verrouillage.
      if(on) el.setAttribute('aria-describedby', 'di-calc-note');
      else   el.removeAttribute('aria-describedby');
    });
    if(note) note.hidden = !on;
  }

  function selDays(){
    var opt = type.options[type.selectedIndex];
    var m = opt ? opt.text.match(/\((\d+)\s*j\)/i) : null;
    return m ? parseInt(m[1], 10) : null;
  }

  function ecart(a, b){
    if(!a || !b) return null;
    var d1 = parseISO(a), d2 = parseISO(b);
    if(isNaN(d1.getTime()) || isNaN(d2.getTime())) return null;
    var n = Math.round((d2 - d1) / 86400000) + 1;   // bornes incluses
    return n > 0 ? n : null;
  }

  // Mode manuel. Les quatre champs décrivent deux informations seulement :
  // une date de départ et une durée. Les laisser tous libres permettait de
  // soumettre « du 01/07 au 05/07, 12 jours, reprise le 01/01 » — quatre
  // valeurs qui se contredisent, toutes acceptées jusqu'au visa.
  //
  // Le dernier champ touché commande, les autres suivent. La reprise reste
  // toujours dérivée de la fin, puisqu'elle n'est jamais une donnée d'entrée.
  function depuisJours(){
    var n = parseInt(jours.value, 10);
    if(!debut.value || !(n > 0)) return;
    fin.value = addDays(debut.value, n - 1);
    majReprise();
  }
  function depuisFin(){
    var n = ecart(debut.value, fin.value);
    if(n === null) return;
    jours.value = n;
    majReprise();
  }
  function majReprise(){
    rep.value = fin.value ? prochainOuvre(addDays(fin.value, 1)) : '';
  }

  function apply(){
    var d = selDays();
    if(d){
      setAuto(true);
      jours.value = d;
      fin.value = debut.value ? addDays(debut.value, d - 1) : '';
      majReprise();
    } else {
      setAuto(false);
      // Repartir de ce que la personne a déjà saisi, sans rien effacer.
      if(jours.value)     depuisJours();
      else if(fin.value)  depuisFin();
    }
  }

  type.addEventListener('change', apply);
  debut.addEventListener('change', function(){
    if(selDays()) { apply(); return; }
    if(jours.value)    depuisJours();
    else if(fin.value) depuisFin();
  });
  jours.addEventListener('input',  function(){ if(!selDays()) depuisJours(); });
  fin.addEventListener('change',   function(){ if(!selDays()) depuisFin();   });
  apply();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
