<?php
// ============================================================
//  changer-mot-de-passe.php — Changement obligatoire (mot de passe
//  attribué par un admin à la création ou à une réinitialisation)
// ============================================================
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/auth.php';

require_auth();
$user = current_user();

// Rien à faire ici si le mot de passe a déjà été changé — évite un écran
// mort accessible par URL directe une fois la contrainte levée.
if (empty($_SESSION['must_change_password'])) {
    redirect_to('/pages/accueil.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    // trim() comme login.php/reset admin (pages/admin/users.php) : sans ça,
    // un mot de passe saisi ici avec une espace de tête/fin (copié-collé
    // depuis un email/message) se sauvegarde tel quel mais échoue ensuite à
    // la connexion (login.php trime avant password_verify) — compte
    // bloqué en pratique après un changement obligatoire.
    $old  = trim($_POST['old_password']     ?? '');
    $new  = trim($_POST['new_password']     ?? '');
    $conf = trim($_POST['confirm_password'] ?? '');

    if (!$old) json_response(false, 'Le mot de passe actuel est requis.');
    if ($new !== $conf) json_response(false, 'Les nouveaux mots de passe ne correspondent pas.');

    $result = auth_change_password($user['id'], $old, $new, false);
    json_response($result['success'], $result['message']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Changement de mot de passe requis — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700&family=DM+Sans&display=swap" rel="stylesheet">
  <style>
    body { font-family:'DM Sans',sans-serif; background:#0d1f35; min-height:100vh; display:flex; align-items:center; justify-content:center; margin:0; }
    .card { background:white; border-radius:16px; padding:48px 44px; width:440px; box-shadow:0 20px 60px rgba(13,31,53,.3); }
    h2 { font-family:'Syne',sans-serif; font-size:24px; color:#0d1f35; margin-bottom:8px; }
    p.sub { color:#7f8c8d; font-size:14px; margin-bottom:28px; line-height:1.5; }
    label { display:block; font-size:13px; font-weight:500; color:#2c3e50; margin-bottom:8px; margin-top:16px; }
    input { width:100%; padding:13px 14px; border:1.5px solid #d5dde8; border-radius:10px; font-size:14px; outline:none; background:#f0f4f8; box-sizing:border-box; }
    input:focus { border-color:#1B75BC; background:white; }
    .btn { margin-top:24px; width:100%; padding:14px; background:linear-gradient(135deg,#1B75BC,#06033A); color:white; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; }
    .btn:disabled { opacity:.6; cursor:default; }
    .alert { display:none; padding:12px 16px; border-radius:8px; font-size:13px; margin-bottom:16px; }
    .alert.show { display:block; }
    .alert-success { background:#eafaf1; color:#27ae60; border-left:3px solid #27ae60; }
    .alert-danger  { background:#fdf0ef; color:#e74c3c; border-left:3px solid #e74c3c; }
    .pw-track { height:4px; background:#e2e8f0; border-radius:2px; margin-top:8px; overflow:hidden; }
    .pw-fill  { height:100%; width:0; transition:width .2s, background .2s; }
    .hint { font-size:12px; color:#94a3b8; margin-top:4px; }
    .back { display:block; text-align:center; margin-top:20px; color:#1B75BC; font-size:13px; text-decoration:none; }
  </style>
</head>
<body>
<div class="card">
  <h2>Changement de mot de passe requis</h2>
  <p class="sub">Bonjour <?= h($user['prenom']) ?>, votre mot de passe vous a été attribué par un administrateur. Choisissez-en un nouveau avant de continuer.</p>
  <div class="alert alert-success" id="a-ok"></div>
  <div class="alert alert-danger"  id="a-err"></div>
  <form id="form-pwd" autocomplete="off">
    <label for="old_password">Mot de passe actuel</label>
    <input type="password" id="old_password" name="old_password" required autocomplete="current-password">

    <label for="new_password">Nouveau mot de passe</label>
    <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
    <div class="pw-track"><div class="pw-fill" id="pw-bar"></div></div>
    <div class="hint" id="pw-strength-lbl">Min. 8 caractères, 1 majuscule, 1 chiffre, 1 caractère spécial.</div>

    <label for="confirm_password">Confirmer le nouveau mot de passe</label>
    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
    <div class="hint" id="pw-match-lbl"></div>

    <button type="submit" class="btn" id="btn-pwd">Enregistrer et continuer</button>
  </form>
  <a href="<?= APP_URL ?>/logout.php" class="back">Se déconnecter</a>
</div>
<script>
document.getElementById('form-pwd').addEventListener('submit', async function (e) {
  e.preventDefault();
  const btn = document.getElementById('btn-pwd');
  btn.disabled = true;
  btn.textContent = 'Enregistrement...';

  const fd = new FormData(this);
  try {
    const res = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
    const d = await res.json();
    show(d.success ? 'a-ok' : 'a-err', d.message);
    if (d.success) {
      setTimeout(() => { window.location.href = '<?= APP_URL ?>/pages/accueil.php'; }, 1200);
      return;
    }
  } catch {
    show('a-err', 'Erreur réseau.');
  }
  btn.disabled = false;
  btn.textContent = 'Enregistrer et continuer';
});

function show(id, msg) {
  document.querySelectorAll('.alert').forEach(a => a.classList.remove('show'));
  const el = document.getElementById(id);
  el.textContent = msg;
  el.classList.add('show');
}

document.getElementById('new_password').addEventListener('input', function () {
  const v = this.value;
  let s = 0;
  if (v.length >= 8)          s++;
  if (v.length >= 12)         s++;
  if (/[A-Z]/.test(v))        s++;
  if (/[0-9]/.test(v))        s++;
  if (/[^A-Za-z0-9]/.test(v)) s++;

  const colors = ['', '#F87171', '#FBBF24', '#A3E635', '#34D399', '#059669'];
  const widths = ['0%', '20%', '40%', '65%', '85%', '100%'];

  const bar = document.getElementById('pw-bar');
  bar.style.width      = v ? widths[s] : '0%';
  bar.style.background = v ? colors[s] : '';
  checkMatch();
});
document.getElementById('confirm_password').addEventListener('input', checkMatch);

function checkMatch() {
  const a = document.getElementById('new_password').value;
  const b = document.getElementById('confirm_password').value;
  const lbl = document.getElementById('pw-match-lbl');
  if (!b) { lbl.textContent = 'Min. 8 caractères, 1 majuscule, 1 chiffre, 1 caractère spécial.'; lbl.style.color = ''; return; }
  lbl.style.color = a === b ? '#059669' : '#F87171';
  lbl.textContent = a === b ? '✓ Les mots de passe correspondent' : '✗ Ne correspondent pas';
}
</script>
</body>
</html>
