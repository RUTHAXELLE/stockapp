<?php
// ============================================================
//  pages/achats/suivi_achats.php — Suivi Sage (DA/BC) des lignes
//  arbitrées "achat", éclatées à la confirmation de la FEB (J6).
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/achats.php';

require_auth();
$user = current_user();
require_permission('achats_suivi', 'can_read');
$can_edit = can('achats_suivi', 'can_update');
$_SESSION['groupe_actif'] = 'ACHATS';
$page_title  = 'Suivi Achats — DA / BC';
$active_page = 'achats_suivi';

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$can_edit) json_response(false, 'Action réservée.');
    $action = $_POST['action'] ?? '';

    if ($action === 'saisir_da') {
        $suivi_id = (int)($_POST['suivi_id'] ?? 0);
        $numero   = trim($_POST['numero'] ?? '');
        $date_da  = trim($_POST['date_da'] ?? '') ?: null;
        try {
            ach_saisir_da($suivi_id, $numero, $user, $date_da);
            json_response(true, 'N° DA enregistré.');
        } catch (AchValidationException $e) { json_response(false, $e->getMessage()); }
    }

    if ($action === 'saisir_bc') {
        $suivi_id = (int)($_POST['suivi_id'] ?? 0);
        $numero   = trim($_POST['numero'] ?? '');
        $date_liv = trim($_POST['date_livraison_prevue'] ?? '') ?: null;
        try {
            ach_saisir_bc($suivi_id, $numero, $user, $date_liv);
            json_response(true, 'N° BC enregistré.');
        } catch (AchValidationException $e) { json_response(false, $e->getMessage()); }
    }

    if ($action === 'saisir_da_lot') {
        $feb_id  = (int)($_POST['feb_id'] ?? 0);
        $lot     = trim($_POST['lot'] ?? '');
        $numero  = trim($_POST['numero'] ?? '');
        $date_da = trim($_POST['date_da'] ?? '') ?: null;
        if (!$numero) json_response(false, 'Le numéro de DA est obligatoire.');
        try {
            $n = ach_saisir_da_lot($feb_id, $lot, $numero, $user, $date_da);
            json_response(true, $n > 0 ? "N° DA appliqué à $n ligne(s) du lot." : 'Aucune ligne à mettre à jour — le lot est déjà pourvu.');
        } catch (AchValidationException $e) { json_response(false, $e->getMessage()); }
    }

    if ($action === 'saisir_bc_lot') {
        $feb_id   = (int)($_POST['feb_id'] ?? 0);
        $lot      = trim($_POST['lot'] ?? '');
        $numero   = trim($_POST['numero'] ?? '');
        $date_liv = trim($_POST['date_livraison_prevue'] ?? '') ?: null;
        if (!$numero) json_response(false, 'Le numéro de BC est obligatoire.');
        $n = ach_saisir_bc_lot($feb_id, $lot, $numero, $user, $date_liv);
        json_response(true, $n > 0 ? "N° BC appliqué à $n ligne(s) du lot." : "Aucune ligne à mettre à jour — le lot n'a pas de N° DA en attente ou est déjà pourvu.");
    }

    // ── Clôture d'un reliquat (J8, Bloc 3) — réservée à l'acheteur qui
    //    détient la FEB, contrôle fait dans ach_cloturer_reliquat() elle-même.
    if ($action === 'cloturer_reliquat') {
        $suivi_id = (int)($_POST['suivi_id'] ?? 0);
        $motif    = trim($_POST['motif'] ?? '');
        try {
            ach_cloturer_reliquat($suivi_id, $motif, $user);
            json_response(true, 'Reliquat clôturé.');
        } catch (AchValidationException $e) { json_response(false, $e->getMessage()); }
    }

    json_response(false, 'Action inconnue.');
}

// ── PAGE PHP ─────────────────────────────────────────────────
$f_site        = (int)($_GET['site'] ?? 0);
$f_statut      = trim($_GET['statut'] ?? '');
$f_fournisseur = (int)($_GET['fournisseur'] ?? 0);
$f_departement = (int)($_GET['departement'] ?? 0);
$f_q           = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($f_site)        { $where[] = 'fs.site_id = ?';       $params[] = $f_site; }
if ($f_fournisseur) { $where[] = 'fl.fournisseur_id = ?'; $params[] = $f_fournisseur; }
if ($f_departement) { $where[] = 'f.departement_id = ?';  $params[] = $f_departement; }
if ($f_q !== '')     { $where[] = 'f.numero LIKE ?';     $params[] = '%' . $f_q . '%'; }

$lignes = db_fetch_all(
    "SELECT fs.*, f.numero AS feb_numero, f.departement_id, f.acheteur_id, f.fiche_validation_path,
            d.label AS departement_label,
            fl.designation, fl.lot, fl.unite, fl.fournisseur_id,
            fo.raison_sociale AS fournisseur_nom,
            s.nom AS site_nom
     FROM feb_suivi fs
     JOIN feb f          ON f.id = fs.feb_id
     JOIN feb_lignes fl  ON fl.id = fs.feb_ligne_id
     LEFT JOIN departements d  ON d.id = f.departement_id
     LEFT JOIN fournisseurs fo ON fo.id = fl.fournisseur_id
     LEFT JOIN sites s         ON s.id = fs.site_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY f.numero ASC, fl.numero_ligne ASC",
    $params
);

// Statut calculé — jamais stocké (Bloc 3) — puis filtre statut appliqué en
// mémoire : le calcul dépend d'un paramètre (seuil_retard_jours) et de
// l'heure courante, pas seulement des colonnes de la ligne.
foreach ($lignes as &$l) { $l['statut_calcule'] = ach_statut_suivi_calcule($l); }
unset($l);
if ($f_statut !== '' && array_key_exists($f_statut, ach_statuts_suivi())) {
    $lignes = array_values(array_filter($lignes, fn($l) => $l['statut_calcule'] === $f_statut));
}

$sites_list        = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");
$fournisseurs_list = db_fetch_all("SELECT id, raison_sociale FROM fournisseurs WHERE actif=1 ORDER BY raison_sociale");
$departements_list = db_fetch_all("SELECT id, label FROM departements WHERE actif=1 ORDER BY label");

// ── Rafraîchissement live — fragment HTML seul (le tableau, pas la barre
//    de filtres), cf. le même mécanisme sur file_attente.php. isBusy par
//    défaut (templates/footer.php) évite d'effacer un N° DA/BC en cours
//    de frappe dans les <input> du tableau.
function ach_suivi_render_zone(array $lignes, bool $can_edit, array $user): void {
    ?>
    <div class="ach-table-wrap">
      <?php if (empty($lignes)): ?>
        <div class="ach-empty">Aucune ligne de suivi pour ces filtres.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table data-paginer="25" class="ach-table">
        <thead><tr>
          <th>FEB</th><th>Article</th><th>Qté</th><th>Fournisseur</th><th>N° DA</th><th>N° BC</th>
          <th>Livraison prévue</th><th>Reçu / Écart</th><th>Statut</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($lignes as $l):
            $st = ach_statuts_suivi()[$l['statut_calcule']] ?? ['label' => $l['statut_calcule'], 'bg' => '#F1F5F9', 'color' => '#475569'];
            $ecart = (int)$l['quantite_commandee'] - (int)$l['quantite_recue'];
            $reliquat_ouvert = $l['statut_calcule'] === 'livree' && !$l['cloture_reliquat'];
            $peut_cloturer = $can_edit && $reliquat_ouvert && (int)$l['acheteur_id'] === (int)$user['id'];
          ?>
          <tr class="<?= $l['statut_calcule'] === 'en_retard' ? 'en_retard' : '' ?>">
            <td style="font-weight:700;color:var(--navy)">
              <?= h($l['feb_numero'] ?: '—') ?>
              <?php if (!empty($l['fiche_validation_path'])): ?>
              <a href="feb_fiche_validation_pdf.php?id=<?= (int)$l['feb_id'] ?>" target="_blank" title="Fiche de validation archivée" style="margin-left:4px">
                <i class="ph ph-seal-check" aria-hidden="true"></i>
              </a>
              <?php endif; ?>
            </td>
            <td><?= h($l['designation']) ?></td>
            <td><?= (int)$l['quantite_commandee'] ?> <?= h($l['unite'] ?: '') ?></td>
            <td><?= h($l['fournisseur_nom'] ?: '—') ?></td>
            <td>
              <?php if (!$can_edit || $l['numero_da']): ?>
                <?= h($l['numero_da'] ?: '—') ?>
                <?php if ($l['numero_da'] && $l['date_da']): ?>
                  <div style="font-size:11px;color:var(--muted)">le <?= fmt_date($l['date_da']) ?></div>
                <?php endif; ?>
                <?php if ($can_edit && $l['numero_da']): ?>
                  <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 6px;font-size:11px" aria-label="Modifier le N° DA (administrateur)"
                          onclick="suiviEditerDA(<?= (int)$l['id'] ?>, this, <?= json_encode($l['date_da']) ?>)"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
                <?php endif; ?>
              <?php else: ?>
              <div class="ref-cell">
                <div class="ref-input-row">
                  <input type="text" id="da-<?= $l['id'] ?>" placeholder="N° DA">
                  <input type="date" id="dadate-<?= $l['id'] ?>" class="date-input" value="<?= date('Y-m-d') ?>" title="Date de la DA (aujourd'hui par défaut — ajustez si la DA est antérieure)">
                </div>
                <div class="ref-input-row">
                  <button type="button" class="btn btn-primary btn-sm" onclick="suiviSaisirDA(<?= (int)$l['id'] ?>)">OK</button>
                  <button type="button" class="btn btn-secondary btn-sm" onclick='suiviAppliquerLotDA(<?= (int)$l['feb_id'] ?>, <?= json_encode($l['lot'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= (int)$l['id'] ?>)'>Appliquer à tout le lot</button>
                </div>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$l['numero_da']): ?>
                <span style="color:var(--muted);font-size:12px">— (N° DA requis)</span>
              <?php elseif (!$can_edit || $l['numero_bc']): ?>
                <?= h($l['numero_bc'] ?: '—') ?>
                <?php if ($l['numero_bc'] && $l['date_da'] && $l['date_bc']):
                  $delai_da_bc = (strtotime($l['date_bc']) - strtotime($l['date_da'])) / 86400;
                ?>
                  <div style="font-size:11px;color:var(--muted)">DA → BC : <?= (int)$delai_da_bc ?> j</div>
                <?php endif; ?>
              <?php else: ?>
              <div class="ref-cell">
                <div class="ref-input-row">
                  <input type="text" id="bc-<?= $l['id'] ?>" placeholder="N° BC">
                  <input type="date" id="bcdate-<?= $l['id'] ?>" class="date-input" title="Date de livraison prévue réelle (facultatif — sinon l'estimation est conservée)">
                </div>
                <div class="ref-input-row">
                  <button type="button" class="btn btn-primary btn-sm" onclick="suiviSaisirBC(<?= (int)$l['id'] ?>)">OK</button>
                  <button type="button" class="btn btn-secondary btn-sm" onclick='suiviAppliquerLotBC(<?= (int)$l['feb_id'] ?>, <?= json_encode($l['lot'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= (int)$l['id'] ?>)'>Appliquer au lot</button>
                </div>
              </div>
              <?php endif; ?>
            </td>
            <td><?= fmt_date($l['date_livraison_prevue']) ?></td>
            <td>
              <?= (int)$l['quantite_recue'] ?> / <?= (int)$l['quantite_commandee'] ?>
              <?php if ($ecart > 0): ?>
                <div style="font-size:11.5px;color:<?= $l['cloture_reliquat'] ? 'var(--muted)' : '#991B1B' ?>">
                  écart <?= $ecart ?><?= $l['cloture_reliquat'] ? ' (figé — clôturé)' : '' ?>
                </div>
              <?php endif; ?>
            </td>
            <td><span class="ach-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>"><?= h($st['label']) ?></span></td>
            <td style="white-space:nowrap">
              <a href="feb_detail.php?id=<?= (int)$l['feb_id'] ?>" class="btn btn-secondary btn-sm" title="Détails de la FEB" aria-label="Voir les détails de la FEB <?= h($l['feb_numero'] ?: '') ?>">
                <i class="ph ph-eye" aria-hidden="true"></i> Voir
              </a>
              <?php if ($peut_cloturer): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="suiviClorereliquat(<?= (int)$l['id'] ?>)">Clôturer le reliquat</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
    <?php
}

if (is_ajax() && ($_GET['fragment'] ?? '') === '1') {
    ach_suivi_render_zone($lignes, $can_edit, $user);
    exit;
}
$fragmentQs = $_GET;
$fragmentQs['fragment'] = '1';
$fragmentUrl = APP_URL . '/pages/achats/suivi_achats.php?' . http_build_query($fragmentQs);

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ach-toolbar{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}
.ach-fg{margin:0}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg select,.ach-fg input{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;box-sizing:border-box}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-table tr.en_retard{background:#FEF2F2}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.ref-cell{display:flex;flex-direction:column;gap:5px;min-width:150px}
.ref-input-row{display:flex;gap:5px}
.ref-input-row input{width:100px;padding:5px 8px;border:1.5px solid var(--border);border-radius:6px;font-size:12.5px}
.ref-input-row input.date-input{width:120px}
.ach-empty{padding:40px 20px;text-align:center;color:var(--muted);font-size:13px}
@media (max-width:768px) { .ach-fg select, .ach-fg input, .btn { min-height:44px; } }
</style>

<form class="ach-toolbar" method="GET">
  <div class="ach-fg">
    <label for="f-q">FEB (fragment de numéro)</label>
    <input type="text" id="f-q" name="q" value="<?= h($f_q) ?>" placeholder="ex : 0007">
  </div>
  <div class="ach-fg">
    <label for="f-site">Site</label>
    <select id="f-site" name="site">
      <option value="0">Tous</option>
      <?php foreach ($sites_list as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $f_site === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="ach-fg">
    <label for="f-statut">Statut</label>
    <select id="f-statut" name="statut">
      <option value="">Tous</option>
      <?php foreach (ach_statuts_suivi() as $code => $st): ?>
        <option value="<?= h($code) ?>" <?= $f_statut === $code ? 'selected' : '' ?>><?= h($st['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="ach-fg">
    <label for="f-fournisseur">Fournisseur</label>
    <select id="f-fournisseur" name="fournisseur">
      <option value="0">Tous</option>
      <?php foreach ($fournisseurs_list as $f): ?>
        <option value="<?= $f['id'] ?>" <?= $f_fournisseur === (int)$f['id'] ? 'selected' : '' ?>><?= h($f['raison_sociale']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="ach-fg">
    <label for="f-departement">Département</label>
    <select id="f-departement" name="departement">
      <option value="0">Tous</option>
      <?php foreach ($departements_list as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $f_departement === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-secondary">Filtrer</button>
</form>

<div id="suivi-live-zone">
<?php ach_suivi_render_zone($lignes, $can_edit, $user); ?>
</div>

<script>
function suiviPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
  return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
}
function suiviSaisirDA(suivi_id) {
  const numero = document.getElementById('da-' + suivi_id).value.trim();
  const dateEl = document.getElementById('dadate-' + suivi_id);
  const date_da = dateEl ? dateEl.value : '';
  if (!numero) { toast('Le numéro de DA est obligatoire.', 'danger'); return; }
  suiviPost({ action: 'saisir_da', suivi_id, numero, date_da }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function suiviAppliquerLotDA(feb_id, lot, suivi_id) {
  const numero = document.getElementById('da-' + suivi_id).value.trim();
  const dateEl = document.getElementById('dadate-' + suivi_id);
  const date_da = dateEl ? dateEl.value : '';
  if (!numero) { toast('Renseignez le numéro de DA avant de l\'appliquer au lot.', 'danger'); return; }
  if (!confirm(`Appliquer le N° DA "${numero}" à toutes les lignes sans DA du lot ${lot} ?`)) return;
  suiviPost({ action: 'saisir_da_lot', feb_id, lot, numero, date_da }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function suiviSaisirBC(suivi_id) {
  const numero = document.getElementById('bc-' + suivi_id).value.trim();
  const date_livraison_prevue = document.getElementById('bcdate-' + suivi_id).value;
  if (!numero) { toast('Le numéro de BC est obligatoire.', 'danger'); return; }
  suiviPost({ action: 'saisir_bc', suivi_id, numero, date_livraison_prevue }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function suiviAppliquerLotBC(feb_id, lot, suivi_id) {
  const numero = document.getElementById('bc-' + suivi_id).value.trim();
  const date_livraison_prevue = document.getElementById('bcdate-' + suivi_id).value;
  if (!numero) { toast('Renseignez le numéro de BC avant de l\'appliquer au lot.', 'danger'); return; }
  if (!confirm(`Appliquer le N° BC "${numero}" à toutes les lignes du lot ${lot} déjà pourvues d'un N° DA ?`)) return;
  suiviPost({ action: 'saisir_bc_lot', feb_id, lot, numero, date_livraison_prevue }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function suiviEditerDA(suivi_id, btn, dateActuelle) {
  const cell = btn.closest('td');
  const actuel = cell.childNodes[0].textContent.trim();
  cell.innerHTML = `<div class="ref-cell"><div class="ref-input-row">
    <input type="text" id="da-${suivi_id}" value="${actuel === '—' ? '' : actuel}">
    <input type="date" id="dadate-${suivi_id}" class="date-input" value="${dateActuelle || ''}" title="Date de la DA">
    <button type="button" class="btn btn-primary btn-sm" onclick="suiviSaisirDA(${suivi_id})">OK</button>
  </div></div>`;
}
function suiviClorereliquat(suivi_id) {
  const motif = prompt("Motif de clôture du reliquat (rupture fournisseur, commande partiellement annulée…) :");
  if (motif === null) return;
  if (!motif.trim()) { toast('Le motif de clôture est obligatoire.', 'danger'); return; }
  suiviPost({ action: 'cloturer_reliquat', suivi_id, motif: motif.trim() }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  liveRefresh({ url: <?= json_encode($fragmentUrl) ?>, container: '#suivi-live-zone', interval: 10000 });
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
