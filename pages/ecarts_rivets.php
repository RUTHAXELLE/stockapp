<?php
// ============================================================
//  pages/ecarts_rivets.php — Historique des écarts rivets
//  Miroir de pages/ecarts_bobines.php. ecarts_rivets porte déjà
//  site_id/type_rivet directement (pas de jointure vers un objet
//  individuel comme op_bobines).
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();
require_permission('ecarts_rivets', 'can_read');

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';

$page_title  = 'Écarts rivets';
$active_page = 'ecarts_rivets';

$sites_list   = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");
$types_rivets = ['gonflable' => 'Gonflables', 'eclate' => 'Éclatés'];

// ── Filtres
$f_statut     = trim($_GET['statut']     ?? '');
$f_site       = (int)($_GET['site']      ?? 0);
$f_date_debut = trim($_GET['date_debut'] ?? date('Y-m-01'));
$f_date_fin   = trim($_GET['date_fin']   ?? date('Y-m-d'));
$f_source     = trim($_GET['source']     ?? '');

// ── Construction de la requête
$where  = ["er.date_constat BETWEEN ? AND ?"];
$params = [$f_date_debut, $f_date_fin];

if ($f_statut) { $where[] = "er.statut = ?"; $params[] = $f_statut; }
if ($f_site)   { $where[] = "er.site_id = ?"; $params[] = $f_site; }
if ($f_source) { $where[] = "er.source = ?"; $params[] = $f_source; }

$sql_where = 'WHERE ' . implode(' AND ', $where);

$ecarts = db_fetch_all(
    "SELECT er.id, er.date_constat, er.stock_systeme, er.stock_physique, er.ecart,
            er.motif, er.source, er.statut, er.resolu_at, er.type_rivet, er.created_at,
            s.nom AS site_nom,
            CONCAT(ur.prenom,' ',ur.nom) AS resolu_par_nom,
            CONCAT(uc.prenom,' ',uc.nom) AS created_by_nom
     FROM ecarts_rivets er
     JOIN sites s ON s.id = er.site_id
     LEFT JOIN users ur ON ur.id = er.resolu_par
     LEFT JOIN users uc ON uc.id = er.created_by
     $sql_where
     ORDER BY er.date_constat DESC, er.created_at DESC",
    $params
);

// ── KPIs
$kpis = db_fetch_one(
    "SELECT
        COUNT(*) FILTER (WHERE er.statut='ouvert')  AS nb_ouvert,
        COUNT(*) FILTER (WHERE er.statut='resolu')  AS nb_resolu,
        COUNT(*)                                     AS nb_total
     FROM ecarts_rivets er
     $sql_where",
    $params
);

$source_labels = [
    'inventaire' => 'Inventaire',
    'manuel'     => 'Manuel',
];

include __DIR__ . '/../templates/header.php';
?>

<style>
.ecarts-page { padding: 24px; max-width: 1400px; margin: 0 auto; }
.ecarts-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
.ecarts-header h2 { font-size: 22px; font-weight: 800; color: var(--navy); margin: 0; }

.ecarts-kpis { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; margin-bottom: 24px; }
.kpi-card { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; gap: 14px; }
.kpi-icon { width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center; font-size: 20px; flex-shrink: 0; }
.kpi-val { font-size: 26px; font-weight: 800; line-height: 1; }
.kpi-lbl { font-size: 12px; color: var(--muted); margin-top: 2px; }

.filtre-bar { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.filtre-bar label { font-size: 12px; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px; }
.filtre-bar select, .filtre-bar input { border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; font-size: 13px; background: #f8fafc; }
.filtre-bar button { padding: 8px 18px; background: var(--navy); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }

.table-wrap { background: white; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.table-header { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.table-header h2 { font-size: 14px; font-weight: 700; color: var(--navy); margin: 0; }
.table-scroll { overflow-x: auto; }
.ecarts-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ecarts-table thead th { background: var(--navy); color: white; padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; white-space: nowrap; }
.ecarts-table tbody tr:nth-child(even) td { background: #f8fafc; }
.ecarts-table tbody tr:hover td { background: #EFF6FF; }
.ecarts-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.ecarts-table td:empty::after { content: '—'; color: var(--muted); }

.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; white-space: nowrap; }
.badge-ouvert  { background: #FEE2E2; color: #991B1B; }
.badge-resolu  { background: #D1FAE5; color: #065F46; }

.ecart-pos { color: #D97706; font-weight: 800; }
.ecart-neg { color: #DC2626; font-weight: 800; }
.ecart-zero { color: #6B7280; }

.empty-state { text-align: center; padding: 48px 20px; color: var(--muted); }
.empty-state i { font-size: 40px; display: block; margin-bottom: 10px; opacity: .4; }

@media (max-width: 768px) {
    .ecarts-kpis { grid-template-columns: repeat(2,1fr); }
}
</style>

<div class="ecarts-page">

  <div class="ecarts-header">
    <i class="ph ph-warning-diamond" style="font-size:28px;color:#D97706"></i>
    <div>
      <h2>Écarts rivets</h2>
      <p style="margin:0;font-size:13px;color:var(--muted)">Historique des écarts détectés lors des inventaires rivets</p>
    </div>
  </div>

  <div class="ecarts-kpis">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#FEE2E2"><i class="ph ph-warning" style="color:#DC2626"></i></div>
      <div>
        <div class="kpi-val" style="color:#DC2626"><?= (int)$kpis['nb_ouvert'] ?></div>
        <div class="kpi-lbl">En attente de résolution</div>
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#D1FAE5"><i class="ph ph-check-circle" style="color:#065F46"></i></div>
      <div>
        <div class="kpi-val" style="color:#065F46"><?= (int)$kpis['nb_resolu'] ?></div>
        <div class="kpi-lbl">Réajustés</div>
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#EFF6FF"><i class="ph ph-list-numbers" style="color:#1B75BC"></i></div>
      <div>
        <div class="kpi-val" style="color:#1B75BC"><?= (int)$kpis['nb_total'] ?></div>
        <div class="kpi-lbl">Total sur la période</div>
      </div>
    </div>
  </div>

  <form method="get" class="filtre-bar">
    <div>
      <label>Période du</label>
      <input type="date" name="date_debut" value="<?= h($f_date_debut) ?>">
    </div>
    <div>
      <label>au</label>
      <input type="date" name="date_fin" value="<?= h($f_date_fin) ?>">
    </div>
    <div>
      <label>Site</label>
      <select name="site">
        <option value="">Tous les sites</option>
        <?php foreach ($sites_list as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $f_site == $s['id'] ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Statut</label>
      <select name="statut">
        <option value="">Tous</option>
        <option value="ouvert"  <?= $f_statut === 'ouvert'  ? 'selected' : '' ?>>En attente</option>
        <option value="resolu"  <?= $f_statut === 'resolu'  ? 'selected' : '' ?>>Réajustés</option>
      </select>
    </div>
    <div>
      <label>Source</label>
      <select name="source">
        <option value="">Toutes</option>
        <option value="inventaire" <?= $f_source === 'inventaire' ? 'selected' : '' ?>>Inventaire</option>
        <option value="manuel"     <?= $f_source === 'manuel'     ? 'selected' : '' ?>>Manuel</option>
      </select>
    </div>
    <button type="submit"><i class="ph ph-funnel"></i> Filtrer</button>
    <a href="?date_debut=<?= date('Y-m-01') ?>&date_fin=<?= date('Y-m-d') ?>"
       style="padding:8px 14px;color:var(--muted);font-size:13px;text-decoration:none;align-self:flex-end">Réinitialiser</a>
  </form>

  <div class="table-wrap">
    <div class="table-header">
      <h2><i class="ph ph-rows" style="margin-right:6px"></i><?= count($ecarts) ?> écart<?= count($ecarts) > 1 ? 's' : '' ?></h2>
      <span style="font-size:12px;color:var(--muted)"><?= h($f_date_debut) ?> → <?= h($f_date_fin) ?></span>
    </div>

    <?php if (empty($ecarts)): ?>
      <div class="empty-state">
        <i class="ph ph-check-circle"></i>
        Aucun écart trouvé pour cette période et ces filtres.
      </div>
    <?php else: ?>
    <div class="table-scroll">
      <table class="ecarts-table">
        <thead>
          <tr>
            <th>Date constat</th>
            <th>Site</th>
            <th>Format</th>
            <th style="text-align:center">Stock système</th>
            <th style="text-align:center">Stock physique</th>
            <th style="text-align:center">Écart</th>
            <th>Source</th>
            <th>Statut</th>
            <th>Motif</th>
            <th>Traité par</th>
            <th>Le</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ecarts as $e): ?>
          <?php
            $ecart_val = (int)$e['ecart'];
            $ecart_cls = $ecart_val > 0 ? 'ecart-pos' : ($ecart_val < 0 ? 'ecart-neg' : 'ecart-zero');
            $ecart_txt = $ecart_val > 0 ? "+$ecart_val" : (string)$ecart_val;
          ?>
          <tr>
            <td style="white-space:nowrap;font-weight:600"><?= fmt_date($e['date_constat']) ?></td>
            <td><?= h($e['site_nom']) ?></td>
            <td style="font-weight:700;color:var(--navy)"><?= h($types_rivets[$e['type_rivet']] ?? $e['type_rivet']) ?></td>
            <td style="text-align:center;color:#1B75BC;font-weight:600"><?= (int)$e['stock_systeme'] ?></td>
            <td style="text-align:center;font-weight:700"><?= (int)$e['stock_physique'] ?></td>
            <td style="text-align:center" class="<?= $ecart_cls ?>"><?= $ecart_txt ?></td>
            <td style="font-size:12px"><?= h($source_labels[$e['source']] ?? $e['source']) ?></td>
            <td>
              <?php if ($e['statut'] === 'ouvert'): ?>
                <span class="badge badge-ouvert">En attente</span>
              <?php else: ?>
                <span class="badge badge-resolu">Réajusté</span>
              <?php endif; ?>
            </td>
            <td style="max-width:220px;font-size:12px">
              <?= $e['motif'] ? h($e['motif']) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td style="font-size:12px;white-space:nowrap"><?= $e['resolu_par_nom'] ? h($e['resolu_par_nom']) : '<span style="color:var(--muted)">—</span>' ?></td>
            <td style="font-size:12px;white-space:nowrap;color:var(--muted)">
              <?= $e['resolu_at'] ? fmt_date($e['resolu_at']) : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
