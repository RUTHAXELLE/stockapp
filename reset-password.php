<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) redirect_to('/pages/dashboard.php');

$token = trim($_GET['token'] ?? '');
if (!$token) redirect_to('/login.php');

// Vérifier token avant affichage
$valid = db_fetch_one(
    "SELECT id, prenom FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()",
    [$token]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    // trim() comme login.php : sinon un mot de passe avec une espace de
    // tête/fin se sauvegarde tel quel ici mais échoue à la connexion,
    // qui trime avant password_verify (cf. changer-mot-de-passe.php).
    $result = auth_reset_password($token, trim($_POST['new_password'] ?? ''));
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Réinitialisation — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700&family=DM+Sans&display=swap" rel="stylesheet">
  <style>
    body { font-family:'DM Sans',sans-serif; background:#0d1f35; min-height:100vh; display:flex; align-items:center; justify-content:center; }
    .card { background:white; border-radius:16px; padding:48px 44px; width:420px; box-shadow:0 20px 60px rgba(13,31,53,.3); }
    h2 { font-family:'Syne',sans-serif; font-size:24px; color:#0d1f35; margin-bottom:8px; }
    p.sub { color:#7f8c8d; font-size:14px; margin-bottom:32px; }
    label { display:block; font-size:13px; font-weight:500; color:#2c3e50; margin-bottom:8px; margin-top:16px; }
    input { width:100%; padding:13px 14px; border:1.5px solid #d5dde8; border-radius:10px; font-size:14px; outline:none; background:#f0f4f8; }
    input:focus { border-color:#e67e22; background:white; }
    .btn { margin-top:24px; width:100%; padding:14px; background:linear-gradient(135deg,#e67e22,#c0392b); color:white; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; }
    .back { display:block; text-align:center; margin-top:16px; color:#e67e22; font-size:13px; text-decoration:none; }
    .alert { display:none; padding:12px 16px; border-radius:8px; font-size:13px; margin-bottom:16px; }
    .alert.show { display:block; }
    .alert-success { background:#eafaf1; color:#27ae60; border-left:3px solid #27ae60; }
    .alert-danger  { background:#fdf0ef; color:#e74c3c; border-left:3px solid #e74c3c; }
    .invalid { text-align:center; padding:20px 0; }
    .invalid .icon { font-size:48px; margin-bottom:12px; }
  </style>
</head>
<body>
<div class="card">
<?php if (!$valid): ?>
  <div class="invalid">
    <div class="icon">❌</div>
    <h2>Lien invalide</h2>
    <p class="sub">Ce lien de réinitialisation est invalide ou a expiré. Veuillez en demander un nouveau.</p>
    <a href="forgot-password.php" class="btn" style="display:block;text-align:center;text-decoration:none">Demander un nouveau lien</a>
  </div>
<?php else: ?>
  <h2>Nouveau mot de passe</h2>
  <p class="sub">Bonjour <?= h($valid['prenom']) ?>, définissez votre nouveau mot de passe.</p>
  <div class="alert alert-success" id="a-ok"></div>
  <div class="alert alert-danger"  id="a-err"></div>
  <label>Nouveau mot de passe</label>
  <input type="password" id="pwd1" placeholder="Min. 8 caractères">
  <label>Confirmer</label>
  <input type="password" id="pwd2" placeholder="••••••••">
  <button class="btn" onclick="reset()">Enregistrer</button>
  <a href="login.php" class="back">← Retour à la connexion</a>
<?php endif; ?>
</div>
<script>
function reset() {
  const p1=document.getElementById('pwd1').value, p2=document.getElementById('pwd2').value;
  if(p1!==p2){show('a-err','Les mots de passe ne correspondent pas.');return;}
  const fd=new FormData(); fd.append('new_password',p1);
  fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.success){show('a-ok',d.message);setTimeout(()=>window.location.href='login.php',1500);}
      else show('a-err',d.message);
    });
}
function show(id,msg){
  document.querySelectorAll('.alert').forEach(a=>a.classList.remove('show'));
  const el=document.getElementById(id); el.textContent=msg; el.classList.add('show');
}
document.addEventListener('keydown',e=>{if(e.key==='Enter')reset();});
</script>
</body>
</html>
