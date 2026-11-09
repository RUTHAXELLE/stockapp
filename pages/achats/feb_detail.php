<?php
// ============================================================
//  pages/achats/feb_detail.php — Détail d'une FEB (lecture seule)
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/achats.php';

require_auth();
$user = current_user();
require_permission('achats', 'can_read');
$_SESSION['groupe_actif'] = 'ACHATS';

$feb_id = (int)($_GET['id'] ?? 0);
$row = $feb_id ? db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]) : null;

// Même règle de visibilité que la liste : un admin ou le service Achats
// voit toutes les FEB, un demandeur ne voit que les siennes. Un utilisateur
// avec lecture sur achats_suivi (RAF/DAF/PDG, gestionnaire stock) voit aussi
// tout : c'est déjà ce que suivi_achats.php lui montre, sans filtre de
// périmètre — le lien « Voir » qu'il y ouvre ne doit pas le rediriger.
$voit_tout = ach_est_acheteur($user) || can('achats_suivi', 'can_read');
if (!$row || (!$voit_tout && (int)$row['demandeur_id'] !== (int)$user['id'])) {
    header('Location: ' . APP_URL . '/pages/achats/mes_feb.php');
    exit;
}
$feb = ach_feb_decode($row);

$demandeur_nom = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$feb['demandeur_id']]);
$site_nom      = $feb['site_id']        ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$feb['site_id']]) : null;
$dept_nom      = $feb['departement_id'] ? db_fetch_value("SELECT label FROM departements WHERE id=?", [$feb['departement_id']]) : null;
$acheteur_nom  = $feb['acheteur_id']    ? db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$feb['acheteur_id']]) : null;

$lignes = db_fetch_all(
    "SELECT l.*, fa.libelle AS famille_libelle, t.libelle AS type_libelle
     FROM feb_lignes l
     LEFT JOIN familles_achat fa ON fa.id = l.famille_id
     LEFT JOIN achat_types t ON t.code = l.type_achat
     WHERE l.feb_id=? ORDER BY l.numero_ligne",
    [$feb_id]
);
$pieces = db_fetch_all("SELECT id, nom_origine, taille, fichier FROM feb_pieces_jointes WHERE feb_id=? ORDER BY deposee_le", [$feb_id]);

$s = ach_statuts_feb()[$feb['statut']] ?? ['label' => $feb['statut'], 'bg' => '#F1F5F9', 'color' => '#475569'];
$urgence_label = ach_urgences()[(int)$feb['urgence']] ?? 'Normale';

$page_title  = 'FEB ' . ($feb['numero'] ?: '(brouillon)');
$active_page = 'achats_mes_feb';

include __DIR__ . '/../../templates/header.php';
?>
<style>
.feb-detail-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.feb-detail-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:900;color:var(--navy)}
.feb-detail-sub{font-size:13px;color:var(--muted);margin-top:2px}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.feb-hdr-card{background:white;border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:18px}
.feb-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 18px}
@media(max-width:900px){.feb-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.feb-grid{grid-template-columns:minmax(0,1fr)}}
.feb-info-lbl{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.feb-info-val{font-size:14px;color:var(--navy);font-weight:600}
.feb-lignes-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy);padding:16px 16px 0}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:18px}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 16px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:11px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-empty{padding:32px 20px;text-align:center;color:var(--muted);font-size:13px}
.feb-pieces-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.feb-piece-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:#f8fafc;border:1px solid var(--border);border-radius:9px;font-size:13px}
.feb-piece-nom{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.feb-visa-track{display:flex;gap:0;overflow-x:auto;padding:4px 2px}
.feb-visa-step{flex:1;min-width:150px;padding:12px;border:1px solid var(--border);border-left:none;position:relative}
.feb-visa-step:first-child{border-left:1px solid var(--border);border-radius:9px 0 0 9px}
.feb-visa-step:last-child{border-radius:0 9px 9px 0}
.feb-visa-step.done{background:#f0fdf4}
.feb-visa-lbl{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px}
.feb-visa-nom{font-size:13px;font-weight:700;color:var(--navy)}
.feb-visa-date{font-size:12px;color:var(--muted);margin-top:2px}
.feb-visa-attente{font-size:12px;color:#94a3b8}
.feb-actions-bar{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap}
</style>

<div class="feb-detail-hdr">
  <div>
    <div class="feb-detail-ttl"><?= h($feb['numero'] ?: 'Brouillon') ?></div>
    <div class="feb-detail-sub"><?= h($feb['objet']) ?></div>
  </div>
  <span class="ach-badge" style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;font-size:13px;padding:6px 14px"><?= h($s['label']) ?></span>
</div>

<div class="feb-hdr-card">
  <div class="feb-grid">
    <div><div class="feb-info-lbl">Demandeur</div><div class="feb-info-val"><?= h($demandeur_nom ?: '—') ?></div></div>
    <div><div class="feb-info-lbl">Site</div><div class="feb-info-val"><?= h($site_nom ?: '—') ?></div></div>
    <div><div class="feb-info-lbl">Service</div><div class="feb-info-val"><?= h($dept_nom ?: '—') ?></div></div>
    <div><div class="feb-info-lbl">Fonction</div><div class="feb-info-val"><?= h($feb['fonction'] ?: '—') ?></div></div>
    <div><div class="feb-info-lbl">Urgence</div><div class="feb-info-val"><?= h($urgence_label) ?></div></div>
    <div><div class="feb-info-lbl">Montant</div><div class="feb-info-val"><?= fmt_number((float)$feb['montant_total']) ?> XOF</div></div>
    <div><div class="feb-info-lbl">Créée le</div><div class="feb-info-val"><?= fmt_datetime($feb['date_creation']) ?></div></div>
    <div><div class="feb-info-lbl">Soumise le</div><div class="feb-info-val"><?= $feb['date_soumission'] ? fmt_datetime($feb['date_soumission']) : '—' ?></div></div>
    <?php if ($acheteur_nom): ?>
    <div><div class="feb-info-lbl">Acheteur</div><div class="feb-info-val"><?= h($acheteur_nom) ?></div></div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($feb['workflow_snapshot'])): ?>
<div class="feb-hdr-card">
  <div class="feb-lignes-ttl" style="padding:0 0 12px">Circuit de validation</div>
  <?php
    $signatures_par_etape = [];
    foreach ($feb['signatures'] as $sig) { $signatures_par_etape[$sig['etape_label'] ?? ''] = $sig; }
  ?>
  <div class="feb-visa-track">
    <?php foreach ($feb['workflow_snapshot'] as $etape):
      $label = $etape['label'] ?? $etape['role'] ?? '';
      $sig   = $signatures_par_etape[$label] ?? null;
    ?>
      <div class="feb-visa-step <?= $sig ? 'done' : '' ?>">
        <div class="feb-visa-lbl"><?= h($label) ?></div>
        <?php if ($sig): ?>
          <div class="feb-visa-nom"><i class="ph ph-check-circle" aria-hidden="true" style="color:#16a34a"></i> <?= h($sig['nom'] ?? '') ?></div>
          <div class="feb-visa-date"><?= !empty($sig['date']) ? fmt_date($sig['date'], 'd/m/Y') : '' ?></div>
        <?php else: ?>
          <div class="feb-visa-attente">En attente</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($feb['motif_rejet'])): ?>
    <div style="margin-top:14px;padding:10px 14px;background:#FEE2E2;border-radius:9px;font-size:13px;color:#991B1B">
      <strong>Motif de rejet :</strong> <?= h($feb['motif_rejet']) ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="ach-table-wrap">
  <div class="feb-lignes-ttl">Lignes (<?= count($lignes) ?>)</div>
  <?php if (empty($lignes)): ?>
    <div class="ach-empty">Aucune ligne.</div>
  <?php else: ?>
  <div style="overflow-x:auto;margin-top:12px">
    <table class="ach-table">
      <thead><tr>
        <th>Désignation</th><th>Qté</th><th>Unité</th><th>Famille</th><th>Type</th><th>Code analytique</th>
      </tr></thead>
      <tbody>
        <?php foreach ($lignes as $l): ?>
        <tr>
          <td><?= h($l['designation']) ?></td>
          <td><?= (int)$l['quantite'] ?></td>
          <td><?= h($l['unite'] ?: '—') ?></td>
          <td><?= h($l['famille_libelle'] ?: '—') ?></td>
          <td><?= h($l['type_libelle'] ?: '—') ?></td>
          <td><?= h($l['code_analytique'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="feb-hdr-card">
  <div class="feb-lignes-ttl" style="padding:0 0 12px">Pièces jointes</div>
  <?php if (empty($pieces)): ?>
    <div class="feb-info-val" style="color:var(--muted);font-weight:400">Aucune pièce jointe.</div>
  <?php else: ?>
    <ul class="feb-pieces-list">
      <?php foreach ($pieces as $p): ?>
      <li class="feb-piece-row">
        <i class="ph ph-paperclip" aria-hidden="true"></i>
        <a class="feb-piece-nom" href="/uploads/feb/<?= rawurlencode($p['fichier']) ?>" target="_blank"><?= h($p['nom_origine']) ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="feb-actions-bar">
  <a href="mes_feb.php" class="btn btn-secondary">Retour à Mes FEB</a>
  <a href="feb_fiche_pdf.php?id=<?= $feb_id ?>" target="_blank" class="btn btn-secondary">
    <i class="ph ph-printer" aria-hidden="true"></i> Fiche imprimable
  </a>
  <?php if (!empty($feb['fiche_validation_path'])): ?>
  <a href="feb_fiche_validation_pdf.php?id=<?= $feb_id ?>" target="_blank" class="btn btn-secondary">
    <i class="ph ph-seal-check" aria-hidden="true"></i> Fiche de validation
  </a>
  <?php endif; ?>
  <?php if ($feb['statut'] === 'brouillon' && (int)$feb['demandeur_id'] === (int)$user['id']): ?>
  <a href="feb_fiche.php?id=<?= $feb_id ?>" class="btn btn-primary">
    <i class="ph ph-pencil-simple" aria-hidden="true"></i> Modifier le brouillon
  </a>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
