<?php
// ============================================================
//  pages/demandes_it.php — Onglet dédié IT : file de traitement
//  Demandes internes approuvées par le circuit de validation et
//  qui nécessitent une action du service IT (accès, comptes, sites…).
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/demandes.php';

require_auth();
require_permission('demandes', 'can_read');
$user = current_user();
$my_roles = di_user_roles((int)$user['id']);
if (!di_user_can_traiter_it((int)$user['id'], $my_roles)) {
    http_response_code(403); include __DIR__ . '/../templates/403.php'; exit;
}
$_SESSION['groupe_actif'] = 'DEMANDES';
$page_title  = 'Traitements IT';
$active_page = 'demandes_it';

// ── Calcul de la plage de dates selon la période choisie
$periode  = $_GET['periode'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

$today = date('Y-m-d');
if ($periode === 'today') {
    $date_from = $date_to = $today;
} elseif ($periode === 'week') {
    $date_from = date('Y-m-d', strtotime('monday this week'));
    $date_to   = $today;
} elseif ($periode === 'month') {
    $date_from = date('Y-m-01');
    $date_to   = $today;
} elseif ($periode === '3months') {
    $date_from = date('Y-m-d', strtotime('-3 months'));
    $date_to   = $today;
}
$fFrom = $date_from !== '' ? $date_from : null;
$fTo   = $date_to   !== '' ? $date_to   : null;

$a_traiter   = di_a_traiter($user, $fFrom, $fTo);
$deja_traite = di_it_traitees($fFrom, $fTo);
$type_labels = [];
foreach (di_types_actifs() as $t) $type_labels[$t['code']] = $t['label'];

// ── Recherche simple (référence ou demandeur)
$fq = trim($_GET['q'] ?? '');
if ($fq !== '') {
    $match = function ($d) use ($fq) {
        return stripos((string)($d['numero'] ?? ''), $fq) !== false
            || stripos((string)($d['_demandeur'] ?? ''), $fq) !== false;
    };
    $a_traiter   = array_values(array_filter($a_traiter, $match));
    $deja_traite = array_values(array_filter($deja_traite, $match));
}

include __DIR__ . '/../templates/header.php';
?>
<style>
  .di-wrap{max-width:980px;margin:0 auto}
  .di-tbl{width:100%;border-collapse:collapse;background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden}
  .di-tbl th{text-align:left;padding:12px 16px;font-size:12px;color:var(--muted,#7f8c8d);background:var(--input,#f8fafc)}
  .di-tbl td{padding:12px 16px;border-top:1px solid var(--border,#eef2f7);font-size:14px}
  .di-tbl tr:hover td{background:var(--input,#f8fafc);cursor:pointer}
  .di-empty{text-align:center;padding:36px 20px;color:var(--muted,#7f8c8d);background:var(--card,#fff);
    border:1.5px solid var(--border,#e2e8f0);border-radius:16px}
  .di-section{font-size:13px;font-weight:700;color:var(--navy,#06033A);margin:26px 0 10px;
    display:flex;align-items:center;gap:10px}
  .di-count{font-size:12px;font-weight:700;padding:2px 9px;border-radius:9px;background:#e8f8f5;color:#16a085}
  .di-count.done{background:#eafaf1;color:#1f9d5b}

  .fbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    background:var(--card,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:12px;
    padding:12px 16px;margin-bottom:20px}
  .fbar-label{font-size:12px;font-weight:700;color:var(--muted,#7f8c8d);white-space:nowrap}
  .fpill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;
    border:1.5px solid var(--border,#e2e8f0);background:var(--input,#f8fafc);color:var(--muted,#7f8c8d);
    text-decoration:none;transition:.15s}
  .fpill:hover,.fpill.active{background:#16a085;color:#fff;border-color:#16a085}
  .fdate{padding:7px 11px;border:1.5px solid var(--border,#d5dde8);border-radius:9px;
    font-size:13px;font-family:inherit;background:var(--input,#f8fafc);color:var(--text,#2c3e50)}
  .fsep{color:var(--muted,#cbd5e1);font-weight:300}
  .fbtn{padding:7px 16px;border:none;border-radius:9px;background:#16a085;color:#fff;
    font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
  .fsearch{flex:1;min-width:200px;padding:7px 12px;border:1.5px solid var(--border,#d5dde8);border-radius:9px;
    font-size:13px;font-family:inherit;background:var(--input,#f8fafc);color:var(--text,#2c3e50)}

  .di-scroll{max-height:calc(44px + 6 * 45px);overflow-y:auto;
    border:1.5px solid var(--border,#e2e8f0);border-radius:14px;background:var(--card,#fff)}
  .di-scroll .di-tbl{border:none;border-radius:0}
  .di-scroll .di-tbl thead th{position:sticky;top:0;z-index:1;
    background:var(--input,#f8fafc);box-shadow:0 1px 0 var(--border,#e2e8f0)}
</style>

<div class="di-wrap">
  <div style="margin-bottom:16px">
    <h2 style="margin:0 0 3px">Traitements IT</h2>
    <p style="color:var(--muted,#7f8c8d);margin:0;font-size:13px">
      Demandes approuvées par le circuit de validation, en attente d'exécution par le service IT.
    </p>
  </div>

  <form method="get" class="fbar" id="fform">
    <span class="fbar-label">Période</span>
    <?php
    $lien = function (array $over) use ($periode, $date_from, $date_to, $fq) {
        $p = array_merge(['periode'=>$periode, 'date_from'=>$date_from, 'date_to'=>$date_to, 'q'=>$fq], $over);
        $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
        return $p ? '?' . http_build_query($p) : '?';
    };
    $pills = ['' => 'Tout', 'today' => "Aujourd'hui", 'week' => 'Cette semaine',
              'month' => 'Ce mois', '3months' => '3 derniers mois', 'custom' => 'Personnalisé'];
    foreach ($pills as $val => $lbl):
        $active = ($periode === $val) ? 'active' : '';
        $href = $lien(['periode' => $val, 'date_from' => '', 'date_to' => '']);
    ?>
      <a href="<?= h($href) ?>" class="fpill <?= $active ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
    <div id="custom-dates" style="display:<?= $periode === 'custom' ? 'flex' : 'none' ?>;align-items:center;gap:8px">
      <span class="fbar-label">Du</span>
      <input type="date" name="date_from" class="fdate" value="<?= h($date_from) ?>">
      <span class="fsep">au</span>
      <input type="date" name="date_to" class="fdate" value="<?= h($date_to) ?>">
      <input type="hidden" name="periode" value="custom">
      <?php if ($fq !== ''): ?><input type="hidden" name="q" value="<?= h($fq) ?>"><?php endif; ?>
      <button type="submit" class="fbtn">Appliquer</button>
    </div>
    <input type="search" name="q" class="fsearch" placeholder="Référence ou demandeur…" value="<?= h($fq) ?>">
    <button type="submit" class="fbtn">Rechercher</button>
  </form>

  <!-- ── À traiter -->
  <div class="di-section">
    À traiter
    <span class="di-count"><?= count($a_traiter) ?></span>
  </div>

  <?php if (empty($a_traiter)): ?>
    <div class="di-empty">
      <i class="ph-duotone ph-check-circle" style="font-size:40px;color:#cbd5e1"></i>
      <p style="margin:10px 0 0">Rien à traiter<?= $fq !== '' ? ' avec cette recherche' : ' pour le moment' ?>.</p>
    </div>
  <?php else: ?>
    <div<?= count($a_traiter) > 6 ? ' class="di-scroll"' : '' ?>>
    <table class="di-tbl">
      <thead><tr><th>Référence</th><th>Type</th><th>Demandeur</th><th>Approuvée le</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($a_traiter as $d): ?>
        <tr onclick="location.href='<?= APP_URL ?>/pages/demandes.php?id=<?= (int)$d['id'] ?>'">
          <td style="font-weight:700"><?= h($d['numero']) ?></td>
          <td><?= h($type_labels[$d['type_code']] ?? $d['type_code']) ?></td>
          <td><?= h($d['_demandeur'] ?? '') ?></td>
          <td style="color:var(--muted,#7f8c8d);font-size:13px"><?= $d['updated_at'] ? date('d/m/Y', strtotime($d['updated_at'])) : '—' ?></td>
          <td><?= di_badge(di_statut_effectif($d)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <!-- ── Historique des traitements -->
  <div class="di-section">
    Historique des traitements
    <span class="di-count done"><?= count($deja_traite) ?></span>
  </div>

  <?php if (empty($deja_traite)): ?>
    <div class="di-empty">Aucune demande traitée<?= $fq !== '' ? ' ne correspond à cette recherche' : '' ?>.</div>
  <?php else: ?>
  <div<?= count($deja_traite) > 4 ? ' class="di-scroll"' : '' ?>>
    <table class="di-tbl">
      <thead><tr><th>Référence</th><th>Type</th><th>Demandeur</th><th>Ticket GLPI</th><th>Traitée par</th><th>Traitée le</th></tr></thead>
      <tbody>
      <?php foreach ($deja_traite as $d): ?>
        <tr onclick="location.href='<?= APP_URL ?>/pages/demandes.php?id=<?= (int)$d['id'] ?>'">
          <td style="font-weight:700"><?= h($d['numero']) ?></td>
          <td><?= h($type_labels[$d['type_code']] ?? $d['type_code']) ?></td>
          <td><?= h($d['_demandeur'] ?? '') ?></td>
          <td style="font-size:13px;font-weight:700"><?= !empty($d['ticket_glpi']) ? h($d['ticket_glpi']) : '<span style="font-weight:400;color:var(--muted,#7f8c8d)">—</span>' ?></td>
          <td><?= h($d['_traite_par'] ?? '') ?></td>
          <td style="color:var(--muted,#7f8c8d);font-size:13px"><?= $d['traite_date'] ? date('d/m/Y H:i', strtotime($d['traite_date'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.fpill').forEach(function(a) {
  a.addEventListener('click', function(e) {
    if (this.getAttribute('href').includes('custom')) {
      e.preventDefault();
      document.getElementById('custom-dates').style.display = 'flex';
    } else {
      document.getElementById('custom-dates').style.display = 'none';
    }
  });
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
