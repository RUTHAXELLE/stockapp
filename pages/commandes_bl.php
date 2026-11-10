<?php
// ============================================================
//  pages/commandes_bl.php — Archives des bons de livraison
//
//  · ?id=N          : BL signé reçu pour la commande N (PDF), lu en base —
//                     ou sur le disque pour un dépôt antérieur, importé au
//                     passage. Coordinateur : son site seulement.
//  · sans id        : archives (magasin, superviseurs, administration) —
//                     commandes expédiées, BL émis (régénéré) et BL signé
//                     côte à côte, filtres site / période / numéro.
//  · ?zip=1         : BL signés de la sélection, dans une archive ZIP.
//  Cf. includes/commande_bl.php.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/commande_bl.php';

require_auth();
$user     = current_user();
$is_coord = ($user['role_slug'] ?? '') === 'coordinateur_site';
$is_admin = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
$pret     = blc_disponible();

// ── Un BL signé ─────────────────────────────────────────────
if (isset($_GET['id'])) {
    $cmd = db_fetch_one("SELECT id, numero_commande, site_id, bl_reception, recu_par FROM commandes WHERE id=?", [(int)$_GET['id']]);
    if (!$cmd || !blc_peut_voir($user, $cmd)) { http_response_code(403); include __DIR__ . '/../templates/403.php'; exit; }
    $doc = $pret ? blc_lire((int)$cmd['id']) : null;
    if (!$doc && ($p = blc_fichier_disque($cmd['bl_reception']))) {
        if ($pret) {
            // Dépôt antérieur à l'archivage en base : importé maintenant.
            try {
                blc_enregistrer((int)$cmd['id'], $p, basename($cmd['bl_reception']), $cmd['recu_par'] !== null ? (int)$cmd['recu_par'] : null);
                @unlink($p);
                $doc = blc_lire((int)$cmd['id']);
            } catch (Throwable $e) { error_log('BL import: ' . $e->getMessage()); }
        }
        $doc ??= ['nom' => basename($p), 'contenu' => file_get_contents($p)];
    }
    // Ni dans commande_bl ni sur le disque : archive générique des fichiers.
    if (!$doc && ($c = upload_contenu('bl', $cmd['bl_reception'])) !== null) $doc = ['nom' => basename($cmd['bl_reception']), 'contenu' => $c];
    if (!$doc) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<p style='font-family:Arial;padding:30px'>Le bon de livraison signé de la commande " . h($cmd['numero_commande'])
           . " n'est plus disponible : il a été déposé avant l'archivage en base et le fichier a été perdu.</p>";
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="BL-signe-' . preg_replace('/[^A-Za-z0-9_-]/', '', $cmd['numero_commande']) . '.pdf"');
    header('Content-Length: ' . strlen($doc['contenu']));
    header('X-Content-Type-Options: nosniff');
    echo $doc['contenu'];
    exit;
}

// ── Archives : réservé au magasin, aux superviseurs et à l'administration
if ($is_coord || !(can('commandes', 'can_read') || droit_fonction(FONCTION_MAGASIN, $user))) {
    http_response_code(403); include __DIR__ . '/../templates/403.php'; exit;
}

if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') !== 'importer') json_response(false, 'Action inconnue.');
    if (!$is_admin) json_response(false, 'Action réservée à l\'administration.');
    if (!$pret) json_response(false, 'Migration commande_bl_archive non jouée sur cette base.');
    session_write_close();
    try { $n = blc_importer_disque((int)$user['id']); $n_autres = upload_importer_disque(); }
    catch (Throwable $e) { json_response(false, 'Import interrompu : ' . $e->getMessage()); }
    audit_log((int)$user['id'], 'UPDATE', 'commandes', null, "Archives BL : {$n['importes']} BL importé(s) en base, {$n['perdus']} fichier(s) introuvable(s)");
    json_response(true, "{$n['importes']} BL importé(s) en base" . ($n['perdus'] ? ", {$n['perdus']} fichier(s) déjà perdu(s)." : '.')
        . ($n_autres ? " $n_autres autre(s) pièce(s) du disque archivée(s) (FEB, réceptions, fournisseurs…)." : ''));
}

// Filtres
$f = [
    'site' => (int)($_GET['site'] ?? 0),
    'du'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : '',
    'au'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : '',
    'q'    => trim((string)($_GET['q'] ?? '')),
    'bl'   => in_array($_GET['bl'] ?? '', ['base', 'manquant'], true) ? $_GET['bl'] : '',
];
$w = ["c.statut IN ('en_cours_livraison','recu','livre')"]; $p = [];
if ($f['site']) { $w[] = 'c.site_id = ?'; $p[] = $f['site']; }
if ($f['du'])   { $w[] = 'CAST(COALESCE(c.recu_at, c.livraison_at) AS date) >= ?'; $p[] = $f['du']; }
if ($f['au'])   { $w[] = 'CAST(COALESCE(c.recu_at, c.livraison_at) AS date) <= ?'; $p[] = $f['au']; }
if ($f['q'] !== '') { $w[] = 'UPPER(c.numero_commande) LIKE ?'; $p[] = '%' . mb_strtoupper($f['q']) . '%'; }
$jb = $pret ? 'LEFT JOIN commande_bl b ON b.commande_id = c.id' : '';
if ($f['bl'] === 'base' && $pret) $w[] = 'b.commande_id IS NOT NULL';
if ($f['bl'] === 'manquant')      $w[] = "c.statut IN ('recu','livre')" . ($pret ? ' AND b.commande_id IS NULL' : '');
$where = implode(' AND ', $w);

// Export ZIP des BL signés de la sélection
if (isset($_GET['zip'])) {
    $ids = array_map('intval', array_column(db_fetch_all("SELECT c.id FROM commandes c $jb WHERE $where ORDER BY c.id", $p), 'id'));
    if (count($ids) > 500) { http_response_code(413); echo 'Plus de 500 commandes : resserrez les filtres.'; exit; }
    $tmp = tempnam(sys_get_temp_dir(), 'bl_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $nb = 0;
    foreach ($ids as $id) {
        $c = db_fetch_one("SELECT numero_commande, bl_reception FROM commandes WHERE id=?", [$id]);
        $doc = $pret ? blc_lire($id) : null;
        $contenu = $doc['contenu'] ?? upload_contenu('bl', $c['bl_reception']);
        if ($contenu === null) continue;
        $zip->addFromString('BL-signe-' . preg_replace('/[^A-Za-z0-9_-]/', '', $c['numero_commande']) . '.pdf', $contenu);
        $nb++;
    }
    if (!$nb) $zip->addFromString('LISEZMOI.txt', "Aucun bon de livraison signé dans cette sélection.\n");
    $zip->close();
    audit_log((int)$user['id'], 'EXPORT', 'commandes', null, "Archives BL : export ZIP de $nb BL signé(s)");
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="BL-signes-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

$total = (int)db_fetch_value("SELECT COUNT(*) FROM commandes c $jb WHERE $where", $p);
$lignes = db_fetch_all(
    "SELECT c.id, c.numero_commande, c.statut, c.livraison_at, c.recu_at, c.bl_reception, s.nom AS site_nom,
            CONCAT(ul.prenom, ' ', ul.nom) AS expediteur, CONCAT(ur.prenom, ' ', ur.nom) AS recepteur"
          . ($pret ? ", b.taille AS bl_taille, b.depose_le AS bl_depose_le, CASE WHEN b.commande_id IS NULL THEN 0 ELSE 1 END AS bl_en_base"
                   : ", NULL AS bl_taille, NULL AS bl_depose_le, 0 AS bl_en_base") . "
       FROM commandes c
       LEFT JOIN sites s  ON s.id  = c.site_id
       LEFT JOIN users ul ON ul.id = c.livraison_par
       LEFT JOIN users ur ON ur.id = c.recu_par
       $jb
      WHERE $where
      ORDER BY COALESCE(c.recu_at, c.livraison_at) DESC, c.id DESC
      LIMIT 300", $p);
$sites = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");
$nb_disque = $pret ? (int)db_fetch_value("SELECT COUNT(*) FROM commandes c WHERE COALESCE(c.bl_reception, '') <> ''
                                          AND NOT EXISTS (SELECT 1 FROM commande_bl b WHERE b.commande_id = c.id)") : 0;
$etats = ['base' => ['Archivé', '#15803d', '#DCFCE7'], 'disque' => ['Sur le disque', '#1d4ed8', '#DBEAFE'],
          'perdu' => ['Fichier perdu', '#b91c1c', '#FEE2E2']];
$qs = http_build_query(array_filter($f));

$page_title  = 'Archives des bons de livraison';
$active_page = 'commandes_bl';
include __DIR__ . '/../templates/header.php';
?>
<style>
.bl-card{background:white;border:1px solid var(--border);border-radius:16px;padding:18px 20px}
.bl-filtres{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:flex-end;margin-bottom:14px}
.bl-filtres label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--navy)}
.bl-filtres input,.bl-filtres select{padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;font-weight:400}
.bl-table{width:100%;border-collapse:collapse;font-size:13px}
.bl-table th{text-align:left;font-size:11.5px;color:#64748b;padding:8px 10px;border-bottom:1.5px solid var(--border);white-space:nowrap}
.bl-table td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.bl-sub{font-size:11.5px;color:#64748b}
.bl-etat{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:700;white-space:nowrap}
.bl-aide{margin:0 0 14px;font-size:12.5px;color:#64748b;line-height:1.55;max-width:80ch}
.bl-alerte{margin:0 0 14px;padding:9px 12px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12.5px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
</style>

<div class="bl-card">
  <p class="bl-aide">Bons de livraison des commandes expédiées : le BL émis par le magasin (régénéré à partir de l'expédition) et le BL signé
    joint par le site à la réception, archivé dans la base.</p>
  <?php if (!$pret): ?>
    <div class="bl-alerte">L'archivage en base n'est pas encore installé (migration commande_bl_archive) : les BL signés sont lus sur le disque.</div>
  <?php elseif ($nb_disque): ?>
    <div class="bl-alerte"><span><?= $nb_disque ?> BL signé(s) déposé(s) avant l'archivage en base ne sont pas encore importés
      (ou leur fichier est perdu).</span>
      <?php if ($is_admin): ?><button type="button" class="btn btn-secondary btn-sm" id="bl-importer">Importer en base</button><?php endif; ?></div>
  <?php endif; ?>

  <form class="bl-filtres" method="get">
    <label>Site<select name="site"><option value="">Tous</option>
      <?php foreach ($sites as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $f['site'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['nom']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Du<input type="date" name="du" value="<?= h($f['du']) ?>"></label>
    <label>Au<input type="date" name="au" value="<?= h($f['au']) ?>"></label>
    <label>N° de commande<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="CMD-…"></label>
    <label>BL signé<select name="bl"><option value="">Tous</option>
      <option value="base" <?= $f['bl'] === 'base' ? 'selected' : '' ?>>Archivé</option>
      <option value="manquant" <?= $f['bl'] === 'manquant' ? 'selected' : '' ?>>Manquant</option></select></label>
    <button class="btn btn-primary btn-sm" type="submit">Filtrer</button>
    <a class="btn btn-secondary btn-sm" href="?<?= h($qs ? $qs . '&' : '') ?>zip=1"><i class="ph ph-file-zip" aria-hidden="true"></i> Télécharger les BL signés (ZIP)</a>
  </form>

  <div class="bl-sub" style="margin-bottom:8px"><?= number_format($total, 0, ',', ' ') ?> commande(s)<?= $total > 300 ? ' — 300 plus récentes affichées' : '' ?></div>
  <div class="table-wrap">
  <table class="bl-table">
    <thead><tr><th>Commande</th><th>Site</th><th>Expédiée</th><th>Reçue</th><th>BL émis</th><th>BL signé</th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $l):
        $etat = $l['statut'] === 'en_cours_livraison' && !$l['bl_en_base'] && empty($l['bl_reception']) ? null : blc_etat($l, (bool)$l['bl_en_base']); ?>
      <tr>
        <td><strong><?= h($l['numero_commande']) ?></strong></td>
        <td><?= h($l['site_nom'] ?? '—') ?></td>
        <td><?= $l['livraison_at'] ? h(fmt_datetime($l['livraison_at'])) : '—' ?><div class="bl-sub"><?= h(trim((string)$l['expediteur']) ?: '') ?></div></td>
        <td><?= $l['recu_at'] ? h(fmt_datetime($l['recu_at'])) : '<span class="bl-sub">en livraison</span>' ?><div class="bl-sub"><?= h(trim((string)$l['recepteur']) ?: '') ?></div></td>
        <td><a class="btn btn-secondary btn-sm" target="_blank" href="commandes.php?id=<?= (int)$l['id'] ?>&bl=1"><i class="ph ph-truck" aria-hidden="true"></i> BL</a></td>
        <td><?php if ($etat === null): ?><span class="bl-sub"><?= $l['recu_at'] ? 'aucun' : '—' ?></span>
          <?php else: [$t, $c, $bg] = $etats[$etat]; ?>
            <span class="bl-etat" style="color:<?= $c ?>;background:<?= $bg ?>"><?= $t ?></span>
            <?php if ($etat !== 'perdu'): ?><a class="btn btn-secondary btn-sm" target="_blank" href="?id=<?= (int)$l['id'] ?>" style="margin-left:6px"><i class="ph ph-file-pdf" aria-hidden="true"></i> Ouvrir</a><?php endif; ?>
            <?php if ($l['bl_taille']): ?><div class="bl-sub"><?= number_format($l['bl_taille'] / 1024, 0, ',', ' ') ?> Ko</div><?php endif; ?>
          <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lignes): ?><tr><td colspan="6" style="text-align:center;color:#64748b;padding:24px">Aucune commande expédiée pour ces filtres.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
const blImp = document.getElementById('bl-importer');
if (blImp) blImp.addEventListener('click', async () => {
  blImp.disabled = true; blImp.textContent = 'Import en cours…';
  const fd = new FormData(); fd.append('action', 'importer');
  try {
    const r = await fetch('commandes_bl.php', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(x => x.json());
    toast(r.message, r.success ? 'success' : 'danger');
    if (r.success) setTimeout(() => location.reload(), 1000);
  } catch (e) { toast('Import impossible.', 'danger'); }
  blImp.disabled = false; blImp.textContent = 'Importer en base';
});
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
