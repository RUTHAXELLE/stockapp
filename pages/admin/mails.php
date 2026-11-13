<?php
// ============================================================
//  pages/admin/mails.php  —  Suivi des mails de notification
//  Ce qui est parti, ce qui attend, ce qui a échoué (et pourquoi),
//  avec relance des échecs et mail de test.
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_auth();
require_permission('users', 'can_update');

$user        = current_user();
$page_title  = 'Mails de notification';
$active_page = 'mails_notifications';

$col_ok      = db_colonne_existe('notifications', 'mail_statut');
$col_erreur  = $col_ok && db_colonne_existe('notifications', 'mail_erreur') && db_colonne_existe('notifications', 'mail_envoye_le');
$limite_48h  = date('Y-m-d H:i:s', time() - MAIL_NOTIF_AGE_MAX_H * 3600);
$seuil_blocage = 15;   // minutes : au-delà, un mail en attente signale un envoi à l'arrêt

// ============================================================
//  AJAX : relances et mail de test
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    if (!$col_ok) json_response(false, "La migration des mails n'est pas appliquée sur cette base.");
    $action = $_POST['action'] ?? '';
    $reset  = "UPDATE notifications SET mail_statut='attente', mail_tentatives=0" . ($col_erreur ? ', mail_erreur=NULL' : '')
            . " WHERE mail_statut='echec' AND created_at >= ?";

    // ── Relancer un mail en échec
    if ($action === 'relancer') {
        $id = (int)($_POST['id'] ?? 0);
        $n  = db_query($reset . ' AND id=?', [$limite_48h, $id])->rowCount();
        if (!$n) json_response(false, "Relance impossible : ce mail n'est plus en échec, ou sa notification a plus de " . MAIL_NOTIF_AGE_MAX_H . ' h.');
        audit_log($user['id'], 'UPDATE', 'notifications', $id, "Relance du mail de la notification #$id");
        json_response(true, 'Mail remis en file : il partira au prochain passage.');
    }

    // ── Relancer tous les échecs récents
    if ($action === 'relancer_echecs') {
        $n = db_query($reset, [$limite_48h])->rowCount();
        audit_log($user['id'], 'UPDATE', 'notifications', 0, "Relance de $n mail(s) en échec");
        json_response(true, $n ? "$n mail(s) remis en file." : 'Aucun échec récent à relancer.');
    }

    // ── Mail de test : une vraie notification pour l'administrateur connecté,
    //    qui suit donc le même chemin qu'un mail réel (file d'attente, puis envoi).
    if ($action === 'test') {
        if (empty($user['email'])) json_response(false, "Votre compte n'a pas d'adresse e-mail.");
        notif_create('info', 'Test d\'envoi de mail',
            'Ce message vérifie que les notifications partent bien par e-mail. Aucune action n\'est attendue.', (int)$user['id'], '');
        audit_log($user['id'], 'CREATE', 'notifications', 0, "Mail de test demandé par {$user['email']}");
        json_response(true, 'Notification de test créée : le mail part au prochain passage (environ une minute, jusqu\'à 5 minutes selon l\'hébergement). Elle ne part pas si vous avez coupé vos mails dans Mon profil.');
    }
    json_response(false, 'Action inconnue.');
}

// ============================================================
//  LIBELLÉS
// ============================================================
function mail_statut_label(string $s): string {
    return ['attente' => 'En attente', 'envoye' => 'Envoyé', 'echec' => 'En échec', 'ignore' => 'Non envoyé', 'historique' => 'Historique'][$s] ?? $s;
}
function mail_statut_style(string $s): string {
    return [
        'attente'    => 'background:#fef9e7;color:#9a7d0a',
        'envoye'     => 'background:#d5f5e3;color:#1e8449',
        'echec'      => 'background:#fdedec;color:#922b21',
        'ignore'     => 'background:#eaecee;color:#424949',
        'historique' => 'background:#f0f4f8;color:#475569',
    ][$s] ?? 'background:#f0f4f8;color:#475569';
}

// ============================================================
//  DONNÉES
// ============================================================
$f_statut   = trim($_GET['statut'] ?? '');
$search     = trim($_GET['q'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 50;
$statuts_ok = ['attente', 'envoye', 'echec', 'ignore'];
if (!in_array($f_statut, $statuts_ok, true)) $f_statut = '';

$compteurs = array_fill_keys($statuts_ok, 0);
$total = 0; $rows = []; $dernier_envoi = null; $derniere_erreur = null; $bloques = 0; $plus_ancien = null; $echecs_relancables = 0;

if ($col_ok) {
    foreach (db_fetch_all(
        "SELECT mail_statut, COUNT(*) AS n FROM notifications WHERE created_at >= ? AND mail_statut <> 'historique' GROUP BY mail_statut",
        [$limite_48h]) as $c) $compteurs[$c['mail_statut']] = (int)$c['n'];

    $where  = ["n.mail_statut <> 'historique'"];
    $params = [];
    if ($f_statut) { $where[] = 'n.mail_statut = ?'; $params[] = $f_statut; }
    else           { $where[] = 'n.created_at >= ?'; $params[] = date('Y-m-d H:i:s', time() - 7 * 86400); }
    if ($search !== '') {
        $where[] = "(n.titre LIKE ? OR u.email LIKE ? OR CONCAT(u.prenom,' ',u.nom) LIKE ?)";
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    $wsql  = implode(' AND ', $where);
    $total = (int)db_fetch_value("SELECT COUNT(*) FROM notifications n LEFT JOIN users u ON u.id=n.user_id WHERE $wsql", $params);
    $rows  = db_fetch_all(
        "SELECT n.id, n.titre, n.mail_statut, n.mail_tentatives, n.created_at,
                " . ($col_erreur ? 'n.mail_erreur, n.mail_envoye_le' : "NULL AS mail_erreur, NULL AS mail_envoye_le") . ",
                u.email, CONCAT(u.prenom,' ',u.nom) AS user_nom
           FROM notifications n LEFT JOIN users u ON u.id = n.user_id
          WHERE $wsql ORDER BY n.id DESC LIMIT ? OFFSET ?",
        array_merge($params, [$per_page, ($page - 1) * $per_page]));

    if ($col_erreur) {
        $dernier_envoi   = db_fetch_value("SELECT MAX(mail_envoye_le) FROM notifications");
        $derniere_erreur = db_fetch_one(
            "SELECT mail_erreur, mail_statut, created_at FROM notifications
              WHERE mail_erreur IS NOT NULL AND mail_statut IN ('attente','echec') ORDER BY id DESC LIMIT 1");
        $echecs_relancables = (int)db_fetch_value("SELECT COUNT(*) FROM notifications WHERE mail_statut='echec' AND created_at >= ?", [$limite_48h]);
    }
    // Mails en attente depuis trop longtemps : le cron ou le workflow GitHub ne tourne plus.
    $limite_blocage = date('Y-m-d H:i:s', time() - $seuil_blocage * 60);
    $bloques = (int)db_fetch_value("SELECT COUNT(*) FROM notifications WHERE mail_statut='attente' AND created_at < ? AND created_at >= ?", [$limite_blocage, $limite_48h]);
    $plus_ancien = db_fetch_value("SELECT MIN(created_at) FROM notifications WHERE mail_statut='attente' AND created_at >= ?", [$limite_48h]);
}

$mode_file = file_exists(__DIR__ . '/../../cron_mails.php');   // main : envoi par GitHub Actions (pas de SMTP sur Render)
$actif     = $mode_file ? in_array(strtolower((string)env('MAIL_ENABLED', '')), ['1', 'true', 'oui', 'yes', 'on'], true) : mail_actif();
$expediteur = (string)env('MAIL_FROM', '');
$base      = 'mails.php?' . http_build_query(['statut' => $f_statut, 'q' => $search]);

include __DIR__ . '/../../templates/header.php';
?>
<style>
.mail-tuile{flex:1;min-width:150px;background:white;border:1px solid var(--border);border-radius:12px;padding:14px 16px;text-decoration:none;color:var(--text);display:block}
.mail-tuile:hover{border-color:var(--blue-mid,#1a56a0)}
.mail-tuile.actif{border-color:var(--blue-mid,#1a56a0);box-shadow:0 0 0 2px rgba(26,86,160,.15)}
.mail-tuile .n{font-size:26px;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif;line-height:1.1}
.mail-tuile .l{font-size:12.5px;color:var(--muted);margin-top:2px}
.mail-badge{font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;white-space:nowrap}
.mail-alerte{border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;display:flex;gap:10px;align-items:flex-start}
.mail-alerte.rouge{background:#fdedec;color:#922b21;border:1px solid #f5b7b1}
.mail-alerte.jaune{background:#fef9e7;color:#7d6608;border:1px solid #f7dc6f}
.mail-erreur{font-size:12px;color:#922b21;max-width:340px;word-break:break-word}
.mail-motif{font-size:12px;color:var(--muted);max-width:340px;word-break:break-word}
.table-wrap{max-height:70vh;overflow-y:auto}
.table-wrap thead th{position:sticky;top:0;z-index:2;background:var(--navy,#0d1f35);color:white}
.fsel{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none;font-family:'Manrope',sans-serif}
</style>

<?php if (!$col_ok): ?>
<div class="mail-alerte rouge"><i class="ph ph-warning" aria-hidden="true"></i>
  <div>La migration des mails n'est pas appliquée sur cette base (colonne <code>notifications.mail_statut</code> absente) : aucun mail ne peut partir.</div></div>
<?php else: ?>

<!-- ÉTAT DE L'ENVOI -->
<?php if (!$actif): ?>
<div class="mail-alerte rouge"><i class="ph ph-warning" aria-hidden="true"></i>
  <div><strong>L'envoi par mail est désactivé.</strong> Renseignez <code>MAIL_ENABLED=1</code> et les variables <code>MAIL_*</code> dans la configuration du serveur : les notifications restent visibles dans l'application, mais aucun mail ne part.</div></div>
<?php elseif ($bloques > 0): ?>
<div class="mail-alerte rouge"><i class="ph ph-warning" aria-hidden="true"></i>
  <div><strong><?= $bloques ?> mail(s) en attente depuis plus de <?= $seuil_blocage ?> minutes</strong> (le plus ancien : <?= h(fmt_datetime($plus_ancien)) ?>).
  L'envoi semble à l'arrêt : <?= $mode_file
      ? "vérifiez l'onglet Actions du dépôt GitHub (workflow « envoyer-mails »)."
      : "vérifiez la tâche cron (<code>/var/log/stockapp/cron.log</code>)." ?></div></div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3><i class="ph ph-envelope-simple" aria-hidden="true"></i> État de l'envoi</h3></div>
  <div style="padding:14px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 24px;font-size:13px">
    <div><div style="font-size:12px;color:var(--muted)">Envoi</div>
      <strong><?= $actif ? 'Activé' : 'Désactivé' ?></strong>
      <span style="color:var(--muted)">— <?= $mode_file ? 'via GitHub Actions (toutes les 5 min)' : 'direct par SMTP (chaque minute)' ?></span></div>
    <div><div style="font-size:12px;color:var(--muted)">Expéditeur</div><strong><?= $expediteur !== '' ? h($expediteur) : '—' ?></strong></div>
    <div><div style="font-size:12px;color:var(--muted)">Dernier mail envoyé</div>
      <strong><?= $dernier_envoi ? h(fmt_datetime($dernier_envoi)) : ($col_erreur ? 'Aucun' : 'Inconnu (migration à appliquer)') ?></strong></div>
    <div><div style="font-size:12px;color:var(--muted)">Dernière erreur</div>
      <?php if ($derniere_erreur): ?><span class="mail-erreur"><?= h($derniere_erreur['mail_erreur']) ?></span>
      <?php else: ?><strong>Aucune</strong><?php endif; ?></div>
  </div>
  <?php if (!$col_erreur): ?>
  <div style="padding:0 18px 14px;font-size:12.5px;color:var(--muted)">Appliquez la migration <code><?= $mode_file ? 'migration_notifications_mail_erreur.sql' : 'migration_notifications_mail_erreur_mysql.sql' ?></code> pour afficher les motifs d'erreur et la date d'envoi.</div>
  <?php endif; ?>
</div>

<!-- COMPTEURS 48 H -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:6px">
  <?php foreach ($statuts_ok as $s): ?>
  <a class="mail-tuile <?= $f_statut === $s ? 'actif' : '' ?>" href="mails.php?<?= h(http_build_query(['statut' => $f_statut === $s ? '' : $s])) ?>">
    <div class="n"><?= fmt_number($compteurs[$s]) ?></div>
    <div class="l"><span class="mail-badge" style="<?= mail_statut_style($s) ?>"><?= h(mail_statut_label($s)) ?></span></div>
  </a>
  <?php endforeach; ?>
</div>
<div style="font-size:12px;color:var(--muted);margin-bottom:14px">Compteurs sur les dernières <?= MAIL_NOTIF_AGE_MAX_H ?> h. Sans filtre de statut, la liste couvre les 7 derniers jours.</div>

<!-- FILTRES + ACTIONS -->
<form method="GET" style="background:white;border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:16px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" value="<?= h($search) ?>" aria-label="Rechercher" placeholder="Destinataire, e-mail ou titre…" class="fsel" style="flex:1;min-width:200px">
    <select name="statut" class="fsel" aria-label="Filtrer par statut" onchange="this.form.submit()">
      <option value="">Tous les statuts</option>
      <?php foreach ($statuts_ok as $s): ?><option value="<?= $s ?>" <?= $f_statut === $s ? 'selected' : '' ?>><?= h(mail_statut_label($s)) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
    <?php if ($search !== '' || $f_statut): ?><a href="mails.php" class="btn btn-secondary btn-sm">Réinitialiser</a><?php endif; ?>
    <span style="flex:0 0 1px;height:24px;background:var(--border)"></span>
    <button type="button" class="btn btn-secondary btn-sm" onclick="mailAction('test')"><i class="ph ph-paper-plane-tilt" aria-hidden="true"></i> Envoyer un mail de test</button>
    <?php if ($echecs_relancables > 0): ?>
    <button type="button" class="btn btn-secondary btn-sm" onclick="if(confirm('Remettre en file les <?= $echecs_relancables ?> mail(s) en échec des dernières <?= MAIL_NOTIF_AGE_MAX_H ?> h ?'))mailAction('relancer_echecs')"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Relancer les échecs (<?= $echecs_relancables ?>)</button>
    <?php endif; ?>
  </div>
</form>

<!-- LISTE -->
<div class="card">
  <div class="card-header"><h3><i class="ph ph-list-bullets" aria-hidden="true"></i> Notifications <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= fmt_number($total) ?>)</span></h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th style="width:130px">Créée le</th>
        <th>Destinataire</th>
        <th>Titre</th>
        <th style="width:110px;text-align:center">Statut</th>
        <th style="width:70px;text-align:center">Essais</th>
        <th>Détail</th>
        <th style="width:90px;text-align:center">Action</th>
      </tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">Aucune notification.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td style="white-space:nowrap;font-size:12.5px"><?= h(fmt_datetime($r['created_at'])) ?></td>
          <td>
            <?php if (trim((string)$r['email']) !== ''): ?>
              <div style="font-size:13px;font-weight:500"><?= h(trim((string)$r['user_nom'])) ?></div>
              <div style="font-size:12px;color:var(--muted)"><?= h($r['email']) ?></div>
            <?php else: ?><span style="font-size:12px;color:var(--muted)">—</span><?php endif; ?>
          </td>
          <td style="font-size:13px"><?= h($r['titre']) ?></td>
          <td style="text-align:center"><span class="mail-badge" style="<?= mail_statut_style($r['mail_statut']) ?>"><?= h(mail_statut_label($r['mail_statut'])) ?></span></td>
          <td style="text-align:center;font-size:12.5px"><?= (int)$r['mail_tentatives'] ?>/<?= MAIL_NOTIF_TENTATIVES ?></td>
          <td>
            <?php if ($r['mail_statut'] === 'envoye' && $r['mail_envoye_le']): ?>
              <span class="mail-motif">Envoyé le <?= h(fmt_datetime($r['mail_envoye_le'])) ?></span>
            <?php elseif ($r['mail_statut'] === 'ignore'): ?>
              <span class="mail-motif"><?= h($r['mail_erreur'] ?: '—') ?></span>
            <?php elseif ($r['mail_erreur']): ?>
              <span class="mail-erreur"><?= h($r['mail_erreur']) ?></span>
            <?php else: ?><span class="mail-motif">—</span><?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if ($r['mail_statut'] === 'echec' && $r['created_at'] >= $limite_48h): ?>
              <button class="btn btn-secondary btn-sm" onclick="mailAction('relancer', <?= (int)$r['id'] ?>)" title="Remettre ce mail en file"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Relancer</button>
            <?php else: ?><span style="color:var(--border)">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per_page, $page, $base) ?>
</div>

<script>
function mailAction(action, id) {
  const fd = new FormData();
  fd.append('action', action);
  if (id) fd.append('id', id);
  fetch(window.location.pathname, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd})
    .then(r => r.json())
    .then(d => { alert(d.message || (d.success ? 'Fait.' : 'Échec.')); if (d.success) location.reload(); })
    .catch(() => alert('Erreur réseau.'));
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
