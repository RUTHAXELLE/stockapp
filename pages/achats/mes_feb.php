<?php
// ============================================================
//  pages/achats/mes_feb.php — Liste des FEB (Fiches d'Expression de Besoin)
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/achats.php';

require_auth();
$user = current_user();
require_permission('achats', 'can_read');
$peut_creer = ach_peut_creer($user);
// Un admin ou le service Achats voit toutes les FEB ; un demandeur ne voit
// que les siennes, plus celles qu'il a endossées comme N+1 (créées par
// quelqu'un d'autre de son département) — filtre serveur, jamais côté
// affichage.
$voit_tout = ach_est_acheteur($user);
$est_n1    = ach_est_n1_quelque_part((int)$user['id']);
$_SESSION['groupe_actif'] = 'ACHATS';
$page_title  = 'Mes FEB';
$active_page = 'achats_mes_feb';

// ── PAGE PHP ─────────────────────────────────────────────────
$statut  = trim($_GET['statut'] ?? '');
$du      = trim($_GET['du'] ?? '');
$au      = trim($_GET['au'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where  = [];
$params = [];
if (!$voit_tout && $est_n1) {
    $where[]  = '(f.demandeur_id = ? OR f.n1_user_id = ?)';
    $params[] = (int)$user['id'];
    $params[] = (int)$user['id'];
} elseif (!$voit_tout) {
    $where[]  = 'f.demandeur_id = ?';
    $params[] = (int)$user['id'];
}
$statuts_valides = array_keys(ach_statuts_feb());
if ($statut !== '' && in_array($statut, $statuts_valides, true)) {
    $where[]  = 'f.statut = ?';
    $params[] = $statut;
}
if ($du !== '') {
    $where[]  = 'f.date_creation >= ?';
    $params[] = $du . ' 00:00:00';
}
if ($au !== '') {
    $where[]  = 'f.date_creation <= ?';
    $params[] = $au . ' 23:59:59';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)db_fetch_value("SELECT COUNT(*) FROM feb f $whereSql", $params);
$febs  = db_fetch_all(
    "SELECT f.id, f.numero, f.objet, f.statut, f.urgence, f.montant_total, f.date_creation, f.date_soumission, f.fiche_validation_path,
            f.demandeur_id, CONCAT(u.prenom,' ',u.nom) AS demandeur_nom
     FROM feb f
     LEFT JOIN users u ON u.id = f.demandeur_id
     $whereSql
     ORDER BY f.date_creation DESC
     LIMIT ? OFFSET ?",
    [...$params, $perPage, ($page - 1) * $perPage]
);

$qs = [];
if ($statut !== '') $qs[] = 'statut=' . urlencode($statut);
if ($du !== '')     $qs[] = 'du=' . urlencode($du);
if ($au !== '')     $qs[] = 'au=' . urlencode($au);
$baseUrl = strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . implode('&', $qs) : '');

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ach-toolbar{display:flex;gap:10px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;margin-bottom:18px}
.ach-filters{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.ach-fg{margin:0}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg select,.ach-fg input{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;box-sizing:border-box}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 16px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:11px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-row-clic{cursor:pointer}
.ach-row-clic:hover{background:#f8fafc}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.ach-empty{padding:40px 20px;text-align:center;color:var(--muted);font-size:13px}
.ach-urg{font-size:12px;font-weight:700}
@media (max-width:768px) {
  .ach-fg select, .ach-fg input, .btn { min-height:44px; }
}
</style>

<div class="ach-toolbar">
  <form class="ach-filters" method="GET">
    <div class="ach-fg">
      <label for="ach-statut">Statut</label>
      <select id="ach-statut" name="statut">
        <option value="">Tous</option>
        <?php foreach (ach_statuts_feb() as $code => $s): ?>
          <option value="<?= h($code) ?>" <?= $statut === $code ? 'selected' : '' ?>><?= h($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ach-fg">
      <label for="ach-du">Du</label>
      <input type="date" id="ach-du" name="du" value="<?= h($du) ?>">
    </div>
    <div class="ach-fg">
      <label for="ach-au">Au</label>
      <input type="date" id="ach-au" name="au" value="<?= h($au) ?>">
    </div>
    <button type="submit" class="btn btn-secondary">Filtrer</button>
  </form>
  <?php if ($peut_creer): ?>
  <a href="feb_fiche.php" class="btn btn-primary">
    <i class="ph ph-plus" aria-hidden="true"></i> Nouvelle FEB
  </a>
  <?php endif; ?>
</div>

<div class="ach-table-wrap">
  <?php if (empty($febs)): ?>
    <div class="ach-empty">Aucune FEB trouvée.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="ach-table">
    <thead><tr>
      <th>Numéro</th><th>Objet</th>
      <?php if ($voit_tout || $est_n1): ?><th>Demandeur</th><?php endif; ?>
      <?php if ($est_n1 && !$voit_tout): ?><th>Mon rôle</th><?php endif; ?>
      <th>Urgence</th><th>Date</th><th>Montant</th><th>Statut</th><th>Actions</th>
    </tr></thead>
    <tbody>
      <?php foreach ($febs as $f):
        $s = ach_statuts_feb()[$f['statut']] ?? ['label' => $f['statut'], 'bg' => '#F1F5F9', 'color' => '#475569'];
        $u = ach_urgences()[(int)$f['urgence']] ?? 'Normale';
        $resumable = $f['statut'] === 'brouillon';
      ?>
      <tr <?php if ($resumable): ?>class="ach-row-clic" onclick="location.href='feb_fiche.php?id=<?= $f['id'] ?>'"<?php endif; ?>>
        <td style="font-weight:700;color:var(--navy)"><?= h($f['numero'] ?: '— (brouillon)') ?></td>
        <td><?= h($f['objet']) ?></td>
        <?php if ($voit_tout || $est_n1): ?><td><?= h($f['demandeur_nom'] ?: '—') ?></td><?php endif; ?>
        <?php if ($est_n1 && !$voit_tout):
          $mon_role = (int)$f['demandeur_id'] === (int)$user['id'] ? 'Demandeur' : 'N+1';
        ?><td><span class="ach-badge" style="background:#F1F5F9;color:#475569"><?= h($mon_role) ?></span></td><?php endif; ?>
        <td class="ach-urg" style="color:<?= (int)$f['urgence'] >= 2 ? '#991B1B' : ((int)$f['urgence'] === 1 ? '#92400E' : 'var(--muted)') ?>"><?= h($u) ?></td>
        <td><?= fmt_date($f['date_creation'], 'd/m/Y') ?></td>
        <td><?= fmt_number((float)$f['montant_total']) ?> XOF</td>
        <td><span class="ach-badge" style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>"><?= h($s['label']) ?></span></td>
        <td onclick="event.stopPropagation()" style="white-space:nowrap">
          <a href="feb_detail.php?id=<?= $f['id'] ?>" class="btn btn-secondary btn-sm" title="Détails" aria-label="Détails de la FEB <?= h($f['numero'] ?: $f['objet']) ?>">
            <i class="ph ph-eye" aria-hidden="true"></i> Détails
          </a>
          <?php if ($f['id']): ?>
          <a href="feb_fiche_pdf.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="Fiche imprimable">
            <i class="ph ph-printer" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
          <?php if (!empty($f['fiche_validation_path'])): ?>
          <a href="feb_fiche_validation_pdf.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="Fiche de validation archivée">
            <i class="ph ph-seal-check" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?= pagination($total, $perPage, $page, $baseUrl) ?>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
