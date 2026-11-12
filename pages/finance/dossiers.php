<?php
// ============================================================
//  pages/finance/dossiers.php — Finance : suivi des dossiers
//  De l'ouverture du dossier chez CIL à la pose de ses plaques : étapes,
//  montants, délais, exceptions, et fiche détaillée de chaque dossier.
//  Données : includes/finance_dossiers.php (sql/migration_finance_01_dossiers.sql).
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/finance_dossiers.php';

require_auth();
$user = current_user();
require_permission('finance_dossiers', 'can_read');
$_SESSION['groupe_actif'] = 'FINANCE';
$page_title  = 'Suivi des dossiers';
$active_page = 'finance_dossiers';

$pret          = fin_module_pret();
$peut_importer = can('finance_dossiers', 'can_create');
$peut_exporter = can('finance_dossiers', 'can_export');

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$pret) json_response(false, 'Module non installé : la migration finance 01 n\'a pas été passée.');
    $action = $_POST['action'] ?? '';
    // Une synchronisation peut durer : la session est libérée pour ne pas
    // bloquer les autres onglets de l'utilisateur pendant ce temps.
    session_write_close();

    if ($action === 'detail') {
        $d = fin_dossier_detail((string)($_POST['cle'] ?? ''));
        if (!$d) json_response(false, 'Dossier introuvable.');
        json_response(true, '', $d);
    }

    if ($action === 'actualiser_cil') {
        if (!$peut_importer) json_response(false, 'Action réservée.');
        $d = fin_dossier_detail((string)($_POST['cle'] ?? ''));
        if (!$d || !$d['chrono']) json_response(false, 'Ce dossier n\'a pas de chrono CIL.');
        try {
            $l = fin_cil_par_chrono($d['chrono']);
            if (!$l) json_response(false, 'Chrono inconnu de l\'API CIL.');
            fin_consolider();
        } catch (Throwable $e) {
            json_response(false, $e->getMessage());
        }
        audit_log((int)$user['id'], 'UPDATE', 'finance_dossiers', null, 'Dossier actualisé depuis l\'API CIL : ' . $d['chrono']);
        $nouv = fin_dossier_detail($l['vin'] ?: (string)$_POST['cle']) ?? fin_dossier_detail((string)$_POST['cle']);
        json_response(true, 'Dossier actualisé depuis l\'API CIL.', $nouv);
    }

    // Paiements CIL récents, au plus toutes les FIN_SYNC_AUTO_HEURES heures,
    // déclenché par l'ouverture de l'écran.
    if ($action === 'sync_auto') {
        $derniere = fin_derniere_sync();
        if ($derniere && strtotime($derniere) > time() - FIN_SYNC_AUTO_HEURES * 3600)
            json_response(true, '', ['synced' => false]);
        $max = db_fetch_value("SELECT MAX(date_paiement) FROM fin_src_cil");
        $du  = $max ? date('Y-m-d', strtotime($max . ' -2 days')) : date('Y-m-d', strtotime('-30 days'));
        // Un trou plus long se charge depuis l'écran Imports (historique).
        if ($du < date('Y-m-d', strtotime('-90 days'))) $du = date('Y-m-d', strtotime('-90 days'));
        try {
            $b = fin_sync_cil($du, date('Y-m-d'), (int)$user['id']);
            if ($b['ajoutees'] || $b['maj']) fin_consolider();
        } catch (Throwable $e) {
            json_response(false, 'Synchronisation CIL impossible : ' . $e->getMessage());
        }
        json_response(true, '', ['synced' => true, 'ajoutees' => $b['ajoutees'], 'maj' => $b['maj']]);
    }
    json_response(false, 'Action inconnue.');
}

// ── PAGE ────────────────────────────────────────────────────
$f = [
    'du'       => trim($_GET['du'] ?? '') ?: date('Y-m-d', strtotime('-30 days')),
    'au'       => trim($_GET['au'] ?? '') ?: date('Y-m-d'),
    'periode'  => isset(FIN_PERIODES[$_GET['periode'] ?? '']) ? $_GET['periode'] : 'paiement',
    'canal'    => trim($_GET['canal'] ?? ''),
    'type'     => trim($_GET['type'] ?? ''),
    'genre'    => trim($_GET['genre'] ?? ''),
    'etape'    => trim($_GET['etape'] ?? ''),
    'anomalie' => trim($_GET['anomalie'] ?? ''),
    'q'        => trim($_GET['q'] ?? ''),
];
// Une recherche par VIN, chrono ou NSIIV porte sur toutes les dates : le
// formulaire renvoie toujours la période affichée, qui cacherait un dossier
// ancien.
if ($f['q'] !== '') { $f['du'] = ''; $f['au'] = ''; }

// ── Export Excel des dossiers filtrés, au format du tableau de bord finance
if (($_GET['export'] ?? '') === 'xlsx') {
    if (!$pret || !$peut_exporter) { http_response_code(403); include __DIR__ . '/../../templates/403.php'; exit; }
    session_write_close();
    [$where, $params] = fin_filtres_sql($f);
    try {
        [$fichier, $nb] = fin_exporter_xlsx($where, $params);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Export impossible : ' . h($e->getMessage()));
    }
    audit_log((int)$user['id'], 'EXPORT', 'finance_dossiers', null, "Export Excel des dossiers : $nb dossier(s)");
    $nom = 'suivi_dossiers_' . ($f['du'] ?: 'debut') . '_' . ($f['au'] ?: date('Y-m-d')) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($fichier));
    header('Cache-Control: no-store');
    readfile($fichier);
    @unlink($fichier);
    exit;
}

$par_page = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$kpi = $lignes = $couverture = $genres = [];
$total = 0;
if ($pret) {
    [$where, $params] = fin_filtres_sql($f);
    $kpi   = fin_kpis($where, $params);
    $total = (int)($kpi['total'] ?? 0);
    $lignes = db_fetch_all(
        "SELECT cle, vin, chrono, nsiiv, immat, canal, type_dossier, genre, date_paiement, date_reception, date_pose,
                montant_dossier, montant_attendu, montant_reconcilie, code_rec, ecart, etape, paye_par_bon,
                " . implode(', ', array_keys(FIN_ANOMALIES)) . "
         FROM fin_dossiers WHERE $where
         ORDER BY " . fin_periode_sql($f['periode']) . " DESC NULLS LAST, cle LIMIT $par_page OFFSET " . (($page - 1) * $par_page), $params);
    $couverture = fin_couverture();
    $genres = FIN_CATEGORIES;
}
$qs = array_filter($f, fn($v) => $v !== '');
$base_url = 'dossiers.php?' . http_build_query($qs);
$lien = fn(array $sup) => 'dossiers.php?' . http_build_query(array_filter(array_merge($f, $sup), fn($v) => $v !== ''));

function fin_fcfa($n): string { return $n === null || $n === '' ? '—' : number_format((float)$n, 0, ',', ' '); }
function fin_jours($n): string { return $n === null || $n === '' ? '—' : number_format((float)$n, 1, ',', ' ') . ' j'; }

include __DIR__ . '/../../templates/header.php';
?>
<style>
.fin-toolbar{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}
.fin-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fin-fg select,.fin-fg input{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;background:white}
.fin-flux{display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin-bottom:18px;border:1px solid var(--border);border-radius:16px;overflow:hidden;background:white}
.fin-etape{padding:16px 18px;text-decoration:none;color:inherit;position:relative;transition:background .15s}
.fin-etape+.fin-etape{border-left:1px solid var(--border)}
.fin-etape:hover{background:#f8fafc}
.fin-etape.actif{background:#eef2ff}
.fin-etape .n{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;color:var(--navy);line-height:1.1}
.fin-etape .l{font-size:12.5px;font-weight:700;color:var(--navy);margin-top:4px}
.fin-etape .s{font-size:11.5px;color:#64748b;margin-top:3px}
.fin-etape .num{display:inline-flex;width:20px;height:20px;border-radius:50%;background:var(--navy);color:white;font-size:11px;font-weight:800;align-items:center;justify-content:center;margin-right:6px}
.fin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:18px}
.fin-tile{background:white;border:1px solid var(--border);border-radius:14px;padding:14px 16px}
.fin-tile .v{font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:900;color:var(--navy);line-height:1.15}
.fin-tile .l{font-size:12px;color:#64748b;margin-top:4px}
.fin-tile .s{font-size:11.5px;color:#64748b;margin-top:3px}
.fin-alerte{display:block;text-decoration:none;color:inherit;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px 14px;transition:box-shadow .15s}
.fin-alerte:hover{box-shadow:0 4px 14px rgba(0,0,0,.07)}
.fin-alerte.zero{background:#f0fdf4;border-color:#bbf7d0}
.fin-alerte.info{background:#eff6ff;border-color:#bfdbfe}
.fin-alerte.actif{outline:2px solid var(--navy)}
.fin-alerte .v{font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:900;color:#9a3412}
.fin-alerte.zero .v{color:#166534}
.fin-alerte.info .v{color:#1e40af}
.fin-alerte .l{font-size:12px;color:#334155;margin-top:2px}
.fin-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:800;color:var(--navy);margin:20px 0 10px}
.fin-cov{background:white;border:1px solid var(--border);border-radius:14px;padding:12px 16px;margin-bottom:18px;font-size:12.5px}
.fin-cov summary{cursor:pointer;font-weight:700;color:var(--navy)}
.fin-cov table{width:100%;border-collapse:collapse;margin-top:10px}
.fin-cov td,.fin-cov th{padding:6px 8px;border-bottom:1px solid var(--border);text-align:left}
.fin-table{width:100%;border-collapse:collapse;font-size:12.5px}
.fin-table th{white-space:nowrap}
.fin-table td{padding:9px 10px;border-bottom:1px solid var(--border);vertical-align:top}
.fin-table tr.clic{cursor:pointer}
.fin-table tr.clic:hover td{background:#f8fafc}
.fin-mono{font-family:ui-monospace,Consolas,monospace;font-size:12px}
.fin-sub{font-size:11px;color:#64748b}
.fin-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap;margin:1px 2px 1px 0}
.fin-pill.ouvert{background:#f1f5f9;color:#334155}.fin-pill.recu{background:#dbeafe;color:#1e40af}
.fin-pill.reconcilie{background:#ede9fe;color:#5b21b6}.fin-pill.pose{background:#dcfce7;color:#166534}
.fin-pill.alerte{background:#ffedd5;color:#9a3412}.fin-pill.bon{background:#e0f2fe;color:#075985}
.fin-ecart-neg{color:#b91c1c;font-weight:700}.fin-ecart-pos{color:#b45309;font-weight:700}
.fin-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto}
.fin-modal-bg.open{display:flex}
.fin-modal{background:white;border-radius:16px;padding:24px;width:100%;max-width:880px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.fin-timeline{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:14px 0}
.fin-tl{border:1px solid var(--border);border-radius:12px;padding:10px 12px;background:#f8fafc}
.fin-tl.fait{background:#f0fdf4;border-color:#bbf7d0}
.fin-tl .t{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:#64748b}
.fin-tl .d{font-size:13.5px;font-weight:700;color:var(--navy);margin-top:3px}
.fin-kv{display:grid;grid-template-columns:repeat(3,1fr);gap:10px 16px;font-size:13px}
.fin-kv .k{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px}
.fin-kv .vv{font-weight:600;color:var(--navy);word-break:break-word}
.fin-src{margin-top:12px;border:1px solid var(--border);border-radius:10px;padding:8px 12px}
.fin-src summary{cursor:pointer;font-weight:700;font-size:13px;color:var(--navy)}
.fin-src table{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}
.fin-src td{padding:4px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.fin-src td:first-child{color:#64748b;width:38%}
.fin-bandeau{display:none;align-items:center;gap:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:12px;padding:10px 14px;margin-bottom:14px;font-size:13px}
@media(max-width:900px){.fin-flux{grid-template-columns:repeat(2,1fr)}.fin-etape:nth-child(3){border-left:none}.fin-etape:nth-child(n+3){border-top:1px solid var(--border)}.fin-timeline,.fin-kv{grid-template-columns:repeat(2,1fr)}}
</style>

<?php if (!$pret): ?>
  <div class="alert alert-warning">
    Le module de suivi des dossiers n'est pas encore installé sur cette base :
    la migration <code>sql/migration_finance_01_dossiers.sql</code> doit être passée par l'administrateur.
  </div>
<?php else: ?>

<div class="fin-bandeau" id="fin-bandeau" role="status"></div>

<form class="fin-toolbar" method="GET">
  <div class="fin-fg"><label for="f-du">Du</label><input type="date" id="f-du" name="du" value="<?= h($f['du']) ?>"></div>
  <div class="fin-fg"><label for="f-au">Au</label><input type="date" id="f-au" name="au" value="<?= h($f['au']) ?>"></div>
  <div class="fin-fg"><label for="f-periode">Période selon</label>
    <select id="f-periode" name="periode">
      <?php foreach (FIN_PERIODES as $k => $l): ?><option value="<?= $k ?>" <?= $f['periode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select></div>
  <div class="fin-fg"><label for="f-canal">Canal</label>
    <select id="f-canal" name="canal"><option value="">Tous</option>
      <?php foreach (FIN_CANAUX as $k => $l): ?><option value="<?= $k ?>" <?= $f['canal'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select></div>
  <div class="fin-fg"><label for="f-type">Type</label>
    <select id="f-type" name="type"><option value="">Tous</option>
      <?php foreach (['Primo', 'Post', 'Reimmat', 'Diplo'] as $t): ?><option <?= $f['type'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
    </select></div>
  <div class="fin-fg"><label for="f-genre">Genre</label>
    <select id="f-genre" name="genre"><option value="">Tous</option>
      <?php foreach ($genres as $k => $g): ?><option value="<?= h($k) ?>" <?= $f['genre'] === $k ? 'selected' : '' ?>><?= h($g) ?></option><?php endforeach; ?>
    </select></div>
  <div class="fin-fg"><label for="f-etape">Étape</label>
    <select id="f-etape" name="etape"><option value="">Toutes</option>
      <?php foreach (FIN_ETAPES as $k => $l): ?><option value="<?= $k ?>" <?= $f['etape'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select></div>
  <div class="fin-fg"><label for="f-anomalie">Exception</label>
    <select id="f-anomalie" name="anomalie"><option value="">—</option>
      <option value="toutes" <?= $f['anomalie'] === 'toutes' ? 'selected' : '' ?>>Toutes les exceptions</option>
      <?php foreach (FIN_ANOMALIES as $k => $l): ?><option value="<?= $k ?>" <?= $f['anomalie'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      <option value="bon" <?= $f['anomalie'] === 'bon' ? 'selected' : '' ?>>Payés par bon</option>
    </select></div>
  <div class="fin-fg"><label for="f-q">VIN, chrono, NSIIV, immat.</label><input type="search" id="f-q" name="q" value="<?= h($f['q']) ?>" placeholder="Rechercher" style="width:180px"></div>
  <button type="submit" class="btn btn-primary">Filtrer</button>
  <a href="dossiers.php" class="btn btn-secondary">Réinitialiser</a>
</form>

<div class="fin-flux" aria-label="Dossiers par étape">
  <?php $i = 0; foreach ([
      'ouvert'     => ['Payés chez CIL, pas encore reçus', fin_fcfa($kpi['n_ouvert'] ?? 0)],
      'recu'       => ['Reçus chez EMUCI, pas encore posés', fin_fcfa($kpi['n_recu'] ?? 0)],
      'reconcilie' => ['Réconciliés, pas encore posés', fin_fcfa($kpi['n_reconcilie'] ?? 0)],
      'pose'       => ['Plaques posées', fin_fcfa($kpi['n_pose'] ?? 0)],
  ] as $k => [$sous, $n]): $i++; ?>
  <a class="fin-etape <?= $f['etape'] === $k ? 'actif' : '' ?>" href="<?= h($lien(['etape' => $f['etape'] === $k ? '' : $k, 'page' => ''])) ?>">
    <div class="n"><?= $n ?></div>
    <div class="l"><span class="num"><?= $i ?></span><?= h(FIN_ETAPES[$k]) ?></div>
    <div class="s"><?= h($sous) ?></div>
  </a>
  <?php endforeach; ?>
</div>

<div class="fin-grid">
  <div class="fin-tile">
    <div class="v"><?= fin_fcfa($kpi['mt_dossiers'] ?? 0) ?></div>
    <div class="l">Montant des dossiers CIL (FCFA)</div>
    <div class="s">Plaques <?= fin_fcfa($kpi['mt_plaques'] ?? 0) ?> · Sécurisation pose <?= fin_fcfa($kpi['mt_secupose'] ?? 0) ?></div>
  </div>
  <div class="fin-tile">
    <div class="v"><?= fin_fcfa($kpi['mt_reconcilie'] ?? 0) ?></div>
    <div class="l">Montant réconcilié (X3)</div>
    <div class="s"><?= fin_fcfa($kpi['n_reconcilies'] ?? 0) ?> dossier(s) réconcilié(s)</div>
  </div>
  <div class="fin-tile">
    <div class="v"><?= fin_fcfa($kpi['mt_a_reconcilier'] ?? 0) ?></div>
    <div class="l">Posés en attente de réconciliation</div>
    <div class="s"><?= fin_fcfa($kpi['a_pose_non_reconcilie'] ?? 0) ?> dossier(s), hors payés par bon · montant attendu connu</div>
  </div>
  <div class="fin-tile">
    <div class="v"><?= fin_jours($kpi['d_transmission']) ?></div>
    <div class="l">Paiement CIL → réception EMUCI</div>
    <div class="s">Médiane <?= fin_jours($kpi['d_transmission_med']) ?></div>
  </div>
  <div class="fin-tile">
    <div class="v"><?= fin_jours($kpi['d_pose']) ?></div>
    <div class="l">Réception → pose</div>
    <div class="s">Médiane <?= fin_jours($kpi['d_pose_med']) ?></div>
  </div>
</div>

<div class="fin-ttl">Les exceptions</div>
<div class="fin-grid">
  <?php foreach (FIN_ANOMALIES as $k => $l): $n = (int)($kpi[$k] ?? 0); ?>
  <a class="fin-alerte <?= $n === 0 ? 'zero' : '' ?> <?= $f['anomalie'] === $k ? 'actif' : '' ?>" href="<?= h($lien(['anomalie' => $f['anomalie'] === $k ? '' : $k, 'page' => ''])) ?>">
    <div class="v"><?= fin_fcfa($n) ?></div>
    <div class="l"><?= h($l) ?><?= $k === 'a_ecart' && $n ? ' · ' . fin_fcfa($kpi['mt_ecart']) . ' FCFA' : '' ?></div>
  </a>
  <?php endforeach; ?>
  <a class="fin-alerte info <?= $f['anomalie'] === 'bon' ? 'actif' : '' ?>" href="<?= h($lien(['anomalie' => $f['anomalie'] === 'bon' ? '' : 'bon', 'page' => ''])) ?>">
    <div class="v"><?= fin_fcfa($kpi['n_bon'] ?? 0) ?></div>
    <div class="l">Payés par bon (réconciliés à 0)</div>
  </a>
</div>

<details class="fin-cov">
  <summary>Période couverte par chaque source</summary>
  <div style="margin-top:6px;color:#64748b">Une exception n'a de sens que sur les dates couvertes par toutes les sources : un dossier payé chez CIL
    en août paraît « non reçu » tant que l'export Brique d'août n'a pas été importé.</div>
  <table>
    <thead><tr><th>Source</th><th>Lignes</th><th>Du</th><th>Au</th><th>Dernier import</th></tr></thead>
    <tbody>
    <?php foreach ($couverture as $src => $c): ?>
      <tr><td><?= h(FIN_SOURCES[$src]) ?></td><td><?= fin_fcfa($c['n']) ?></td><td><?= fmt_date($c['du']) ?></td><td><?= fmt_date($c['au']) ?></td>
          <td><?= $c['dernier'] ? h(fmt_datetime($c['dernier'])) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($peut_importer): ?><div style="margin-top:8px"><a href="imports.php">Importer un export ou synchroniser l'API CIL</a></div><?php endif; ?>
</details>

<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="font-weight:800;color:var(--navy)">Dossiers <span class="fin-sub">(<?= fin_fcfa($total) ?>)</span></div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div class="fin-sub">Date de référence : réception, sinon paiement CIL, sinon pose</div>
      <?php if ($peut_exporter && $total): ?>
      <a class="btn btn-secondary btn-sm" href="<?= h($lien(['export' => 'xlsx', 'page' => ''])) ?>"
         onclick="toast('Préparation de l\'export (<?= fin_fcfa($total) ?> dossiers)…', 'info')">
        <i class="ph ph-file-xls" aria-hidden="true"></i> Exporter Excel</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap">
  <table class="fin-table">
    <thead><tr>
      <th>Dossier</th><th>Canal / type</th><th>Genre</th>
      <th>Payé CIL</th><th>Reçu</th><th>Posé</th>
      <th style="text-align:right">Attendu</th><th style="text-align:right">Réconcilié</th><th style="text-align:right">Écart</th>
      <th>Statut</th>
    </tr></thead>
    <tbody>
    <?php if (!$lignes): ?>
      <tr><td colspan="10" style="text-align:center;padding:36px;color:#64748b">Aucun dossier pour ces filtres.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $l): ?>
      <tr class="clic" data-cle="<?= h($l['cle']) ?>" tabindex="0">
        <td><div class="fin-mono"><?= h($l['vin'] ?? '—') ?></div>
            <div class="fin-sub fin-mono"><?= h(implode(' · ', array_filter([$l['chrono'], $l['nsiiv'], $l['immat']]))) ?></div></td>
        <td><?= h(FIN_CANAUX[$l['canal']] ?? '—') ?><div class="fin-sub"><?= h($l['type_dossier'] ?? '') ?></div></td>
        <td><?= h(fin_categorie_libelle($l['genre']) ?? '—') ?></td>
        <td><?= fmt_date($l['date_paiement']) ?></td>
        <td><?= fmt_date($l['date_reception']) ?></td>
        <td><?= fmt_date($l['date_pose']) ?></td>
        <td style="text-align:right"><?= fin_fcfa($l['montant_attendu']) ?></td>
        <td style="text-align:right"><?= $l['montant_reconcilie'] !== null ? fin_fcfa($l['montant_reconcilie']) : '—' ?></td>
        <td style="text-align:right" class="<?= $l['ecart'] !== null && (float)$l['ecart'] != 0 ? ((float)$l['ecart'] < 0 ? 'fin-ecart-neg' : 'fin-ecart-pos') : '' ?>">
          <?= $l['ecart'] !== null && (float)$l['ecart'] != 0 ? ((float)$l['ecart'] > 0 ? '+' : '') . fin_fcfa($l['ecart']) : '—' ?></td>
        <td><span class="fin-pill <?= h($l['etape'] ?? '') ?>"><?= h(FIN_ETAPES[$l['etape']] ?? '—') ?></span>
          <?php if ($l['paye_par_bon']): ?><span class="fin-pill bon">Bon</span><?php endif; ?>
          <?php foreach (FIN_ANOMALIES as $k => $al): if ($l[$k]): ?><span class="fin-pill alerte"><?= h($al) ?></span><?php endif; endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= pagination($total, $par_page, $page, $base_url) ?>
</div>

<div class="fin-modal-bg" id="fin-modal">
  <div class="fin-modal" role="dialog" aria-modal="true" aria-labelledby="fin-modal-titre">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
      <div>
        <h3 id="fin-modal-titre" style="margin:0;font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:800;color:var(--navy)">Dossier</h3>
        <div class="fin-sub fin-mono" id="fin-modal-ids"></div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" onclick="finFermer()">Fermer</button>
    </div>
    <div id="fin-modal-corps"><div style="padding:30px;text-align:center;color:#64748b">Chargement…</div></div>
  </div>
</div>

<script>
const FIN_PEUT_IMPORTER = <?= $peut_importer ? 'true' : 'false' ?>;
const FIN_ANOMALIES = <?= json_encode(FIN_ANOMALIES, JSON_UNESCAPED_UNICODE) ?>;
const FIN_SOURCES = <?= json_encode(FIN_SOURCES, JSON_UNESCAPED_UNICODE) ?>;
const finEsc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const finNb = n => (n === null || n === undefined || n === '') ? '—' : Number(n).toLocaleString('fr-FR', {maximumFractionDigits: 0});
const finDate = d => d ? String(d).slice(0, 10).split('-').reverse().join('/') : '—';
function finPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  return fetch('dossiers.php', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(achParseJson);
}
function finFermer() { document.getElementById('fin-modal').classList.remove('open'); }
document.getElementById('fin-modal').addEventListener('click', e => { if (e.target === e.currentTarget) finFermer(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') finFermer(); });
document.querySelectorAll('tr.clic').forEach(tr => {
  tr.addEventListener('click', () => finOuvrir(tr.dataset.cle));
  tr.addEventListener('keydown', e => { if (e.key === 'Enter') finOuvrir(tr.dataset.cle); });
});

function finOuvrir(cle) {
  document.getElementById('fin-modal-titre').textContent = 'Dossier';
  document.getElementById('fin-modal-ids').textContent = '';
  document.getElementById('fin-modal-corps').innerHTML = '<div style="padding:30px;text-align:center;color:#64748b">Chargement…</div>';
  document.getElementById('fin-modal').classList.add('open');
  finPost({action: 'detail', cle}).then(r => {
    if (!r.success) { document.getElementById('fin-modal-corps').innerHTML = `<div class="alert alert-danger">${finEsc(r.message)}</div>`; return; }
    finRendre(r.data);
  });
}

function finRendre(d) {
  document.getElementById('fin-modal-titre').textContent = d.vin ? 'VIN ' + d.vin : 'Dossier ' + (d.chrono || d.nsiiv || '');
  document.getElementById('fin-modal-ids').textContent = [d.chrono && 'Chrono ' + d.chrono, d.nsiiv && 'NSIIV ' + d.nsiiv, d.immat && 'Immat. ' + d.immat].filter(Boolean).join(' · ');
  const etapes = [
    ['Payé chez CIL', d.date_paiement, d.date_ouverture ? 'ouvert le ' + finDate(d.date_ouverture) : ''],
    ['Reçu chez EMUCI', d.date_reception, d.statut_brique || ''],
    ['Réconcilié', d.est_reconcilie ? (d.date_reconciliation || true) : null, d.code_rec ? 'Code ' + d.code_rec : (d.paye_par_bon ? 'Payé par bon' : '')],
    ['Posé', d.date_pose, [d.plaque_avant && 'Avant ' + d.plaque_avant, d.plaque_arriere && 'Arrière ' + d.plaque_arriere].filter(Boolean).join(' · ')],
  ];
  const tl = etapes.map(([t, v, s]) => `<div class="fin-tl ${v ? 'fait' : ''}"><div class="t">${t}</div>
      <div class="d">${v === true ? 'Oui' : (v ? finDate(v) : '—')}</div><div class="fin-sub">${finEsc(s)}</div></div>`).join('');
  const kv = (k, v) => `<div><div class="k">${k}</div><div class="vv">${v === null || v === undefined || v === '' ? '—' : v}</div></div>`;
  const anomalies = Object.entries(FIN_ANOMALIES).filter(([k]) => d[k]).map(([, l]) => `<span class="fin-pill alerte">${finEsc(l)}</span>`).join('');
  const ecart = d.ecart !== null && Number(d.ecart) !== 0 ? `<span class="${Number(d.ecart) < 0 ? 'fin-ecart-neg' : 'fin-ecart-pos'}">${Number(d.ecart) > 0 ? '+' : ''}${finNb(d.ecart)}</span>` : '—';
  let html = `<div class="fin-timeline">${tl}</div>`;
  if (anomalies || d.paye_par_bon) html += `<div style="margin-bottom:12px">${anomalies}${d.paye_par_bon ? '<span class="fin-pill bon">Payé par bon</span>' : ''}</div>`;
  html += '<div class="fin-kv">'
    + kv('Canal', finEsc(d.canal_libelle)) + kv('Type de dossier', finEsc(d.type_dossier)) + kv('Type CIL', finEsc(d.type_dossier_cil))
    + kv('Genre', finEsc(d.categorie)) + kv('Client', finEsc(d.client)) + kv('Centre', finEsc(d.centre)) + kv('Site', d.site_nom ? finEsc(d.site_nom) + `<div class="fin-sub">${finEsc(d.site)}</div>` : finEsc(d.site))
    + kv('Mode de paiement', finEsc(d.mode_paiement)) + kv('N° reçu CIL', finEsc(d.numero_recu))
    + kv('Reçu PDF', d.pdf_recu && /^https:\/\//.test(d.pdf_recu) ? `<a href="${finEsc(d.pdf_recu)}" target="_blank" rel="noopener noreferrer">Ouvrir le reçu</a>` : '—')
    + kv('Montant plaques', finNb(d.mt_plaques)) + kv('Montant sécurisation pose', finNb(d.mt_secupose)) + kv('Montant du dossier', finNb(d.montant_dossier))
    + kv('Montant attendu', finNb(d.montant_attendu))
    + (d.formule_attente && d.formule_attente !== 'f' ? kv('Formule', 'En attente de la pose<div class="fin-sub">Plusieurs sites possibles : la formule et le montant attendu seront fixés par le site de pose.</div>') : '')
    + (d.formule ? kv('Formule', finEsc(d.formule) + `<div class="fin-sub">${(d.services || []).map(s => finEsc(s.libelle) + ' : '
        + (s.variable ? (d.montant_variable !== null ? finNb(d.montant_variable) : 'variable') : (s.montant_ttc !== null ? finNb(s.montant_ttc) : 'prix manquant'))).join(' · ')}</div>`) : '')
    +kv('Réconcilié (X3)', finNb(d.montant_reconcilie)) + kv('Écart', ecart)
    + kv('HT / TTC (X3)', finNb(d.montant_ht) + ' / ' + finNb(d.montant_ttc)) + kv('N° facture', finEsc(d.n_facture)) + kv('Caisse', finEsc(d.caisse_statut))
    + '</div>';
  // Seuls les dossiers CIL existent dans l'API (DGTTC et FDS portent un N° CIL sans y figurer).
  if (FIN_PEUT_IMPORTER && d.chrono && d.canal === 'CIL') {
    html += `<div style="margin-top:14px"><button type="button" class="btn btn-secondary btn-sm" id="fin-btn-cil">Actualiser depuis l'API CIL</button></div>`;
  }
  html += '<div class="fin-ttl" style="margin-top:18px">Lignes des sources</div>';
  const cache = ['cree_le', 'maj_le'];
  Object.entries(d.sources || {}).forEach(([src, rows]) => {
    const titre = `${finEsc(FIN_SOURCES[src] || src)} <span class="fin-sub">(${rows.length})</span>`;
    if (!rows.length) { html += `<div class="fin-src"><div style="font-size:13px;color:#64748b"><strong style="color:var(--navy)">${titre}</strong> : aucune ligne</div></div>`; return; }
    html += `<details class="fin-src"><summary>${titre}</summary>` + rows.map(r => '<table>' + Object.entries(r)
      .filter(([k, v]) => !cache.includes(k) && v !== null && v !== '')
      .map(([k, v]) => `<tr><td>${finEsc(k.replace(/_/g, ' '))}</td><td>${finEsc(v)}</td></tr>`).join('') + '</table>').join('<hr style="border:none;border-top:1px dashed var(--border)">') + '</details>';
  });
  document.getElementById('fin-modal-corps').innerHTML = html;
  const b = document.getElementById('fin-btn-cil');
  if (b) b.addEventListener('click', () => {
    b.disabled = true; b.textContent = 'Interrogation de CIL…';
    finPost({action: 'actualiser_cil', cle: d.cle}).then(r => {
      if (!r.success) { toast(finEsc(r.message), 'danger'); b.disabled = false; b.textContent = "Actualiser depuis l'API CIL"; return; }
      toast(finEsc(r.message)); finRendre(r.data);
    });
  });
}

// Filtres appliqués dès qu'un champ change : plus besoin de cliquer sur
// « Filtrer » (le bouton reste en secours). La recherche part à Entrée, à la
// perte du focus ou au clic sur la croix, pas à chaque touche.
(function () {
  const form = document.querySelector('.fin-toolbar');
  if (!form) return;
  const go = () => { if (form.requestSubmit) form.requestSubmit(); else form.submit(); };
  form.querySelectorAll('select, input[type=date]').forEach(el => el.addEventListener('change', go));
  const q = form.querySelector('input[type=search]');
  if (q) {
    let avant = q.value;
    const envoyer = () => { if (q.value !== avant) { avant = q.value; go(); } };
    q.addEventListener('change', envoyer);
    q.addEventListener('search', envoyer);
  }
})();

// Paiements CIL récents : synchronisation en arrière-plan, au plus toutes
// les <?= (int)FIN_SYNC_AUTO_HEURES ?> heures.
finPost({action: 'sync_auto'}).then(r => {
  const b = document.getElementById('fin-bandeau');
  if (!r.success) { b.textContent = r.message; b.style.display = 'flex'; return; }
  if (r.data && r.data.synced && (r.data.ajoutees || r.data.maj)) {
    b.innerHTML = `API CIL synchronisée : ${finNb(r.data.ajoutees)} nouveau(x) paiement(s), ${finNb(r.data.maj)} mis à jour.
      <a href="${finEsc(location.href)}" class="btn btn-secondary btn-sm">Actualiser l'écran</a>`;
    b.style.display = 'flex';
  }
}).catch(() => {});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
