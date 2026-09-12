<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';

if (!empty($_SESSION['user_id'])) redirect_to('/pages/dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $result = auth_forgot_password(trim($_POST['email'] ?? ''));
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mot de passe oublié — <?= APP_NAME ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: #0d1f35; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .card { background: white; border-radius: 16px; padding: 48px 44px; width: 420px; box-shadow: 0 20px 60px rgba(13,31,53,.3); }
    h2 { font-family: 'Syne', sans-serif; font-size: 24px; color: #0d1f35; margin-bottom: 8px; }
    p.sub { color: #7f8c8d; font-size: 14px; margin-bottom: 32px; }
    label { display: block; font-size: 13px; font-weight: 500; color: #2c3e50; margin-bottom: 8px; }
    input { width: 100%; padding: 13px 14px; border: 1.5px solid #d5dde8; border-radius: 10px; font-size: 14px; outline: none; background: #f0f4f8; }
    input:focus { border-color: #e67e22; background: white; }
    .btn { margin-top: 24px; width: 100%; padding: 14px; background: linear-gradient(135deg, #e67e22, #c0392b); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; }
    .back { display: block; text-align: center; margin-top: 20px; color: #e67e22; font-size: 13px; text-decoration: none; }
    .alert { display: none; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
    .alert.show { display: block; }
    .alert-success { background: #eafaf1; color: #27ae60; border-left: 3px solid #27ae60; }
    .alert-danger  { background: #fdf0ef; color: #e74c3c; border-left: 3px solid #e74c3c; }
  </style>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700&family=DM+Sans&display=swap" rel="stylesheet">
</head>
<body>
<div class="card">
  <h2>Mot de passe oublié</h2>
  <p class="sub">Entrez votre email, nous vous enverrons un lien de réinitialisation.</p>
  <div class="alert alert-success" id="alert-success"></div>
  <div class="alert alert-danger"  id="alert-error"></div>
  <label>Adresse email</label>
  <input type="email" id="email" placeholder="vous@domaine.ci">
  <button class="btn" onclick="submit()">Envoyer le lien</button>
  <a class="back" href="login.php">← Retour à la connexion</a>
</div>
<script>
function submit() {
  const fd = new FormData();
  fd.append('email', document.getElementById('email').value);
  fetch('', { method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(r => r.json()).then(d => {
      const ok  = document.getElementById('alert-success');
      const err = document.getElementById('alert-error');
      ok.classList.remove('show'); err.classList.remove('show');
      if (d.success) { ok.textContent = d.message; ok.classList.add('show'); }
      else           { err.textContent = d.message; err.classList.add('show'); }
    });
}
document.addEventListener('keydown', e => { if (e.key === 'Enter') submit(); });
</script>
</body>
</html>
