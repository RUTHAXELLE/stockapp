<?php
// ============================================================
//  pages/achats/param_fournisseurs.php — Référentiel fournisseurs
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/achats.php';
require_once __DIR__ . '/../../includes/upload.php';

// Pièces du dossier de conformité fournisseur : colonne DB => métadonnées.
// RCCM, DFE, RIB et PIRL sont obligatoires ; IDU, ARF et attestation CNPS
// sont facultatifs (CNPS "lorsque applicable").
const FOURN_DOCS = [
    'doc_rccm' => ['field' => 'doc_rccm', 'required' => true,  'label' => 'RCCM'],
    'doc_idu'  => ['field' => 'doc_idu',  'required' => false, 'label' => "IDU / document d'identification de l'entreprise"],
    'doc_dfe'  => ['field' => 'doc_dfe',  'required' => true,  'label' => 'DFE / identification fiscale'],
    'doc_arf'  => ['field' => 'doc_arf',  'required' => false, 'label' => 'ARF'],
    'doc_cnps' => ['field' => 'doc_cnps', 'required' => false, 'label' => 'Attestation CNPS'],
    'doc_rib'  => ['field' => 'doc_rib',  'required' => true,  'label' => 'RIB / attestation bancaire'],
    'doc_pirl' => ['field' => 'doc_pirl', 'required' => true,  'label' => "PIRL / pièce d'identité du représentant légal"],
];

require_auth();
$user = current_user();
require_permission('achats_param', 'can_read');
// RAF/DAF/PDG n'ont achats_param que pour le budget (param_budget.php) —
// cf. migration_achats_13_budget_validation.sql. Les autres écrans de
// paramétrage restent réservés à l'administration.
if (in_array($user['role_slug'] ?? '', ['raf', 'daf', 'lecteur'], true)) {
    http_response_code(403);
    include __DIR__ . '/../../templates/403.php';
    exit;
}
$can_edit = can('achats_param', 'can_update');
$_SESSION['groupe_actif'] = 'ACHATS';
$page_title  = 'Fournisseurs';
$active_page = 'achats_param_fournisseurs';

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$can_edit) json_response(false, 'Action réservée.');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $raison = trim($_POST['raison_sociale'] ?? '');
        $rccm   = trim($_POST['numero_rccm'] ?? '');
        $dfe    = trim($_POST['numero_dfe'] ?? '');
        $rib    = trim($_POST['numero_rib'] ?? '');
        if (!$raison) json_response(false, 'La raison sociale est obligatoire.');
        if (!$rccm)   json_response(false, 'Le numéro RCCM est obligatoire.');
        if (!$dfe)    json_response(false, 'Le numéro DFE est obligatoire.');
        if (!$rib)    json_response(false, 'Le numéro RIB est obligatoire.');

        $uploaded = [];
        foreach (FOURN_DOCS as $col => $meta) {
            $res = upload_document($meta['field'], 'fournisseur', 'fournisseur_new', $meta['required']);
            if (!$res['success']) {
                foreach ($uploaded as $f) upload_delete($f, 'fournisseur');
                json_response(false, $meta['label'] . ' : ' . $res['message']);
            }
            if ($res['filename']) $uploaded[$col] = $res['filename'];
        }

        db_query(
            "INSERT INTO fournisseurs (raison_sociale, contact_nom, telephone, email, adresse, coordonnees, conditions_paiement,
                                        numero_rccm, numero_dfe, numero_rib,
                                        doc_rccm, doc_idu, doc_dfe, doc_arf, doc_cnps, doc_rib, doc_pirl, cree_par)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$raison, trim($_POST['contact_nom'] ?? ''), trim($_POST['telephone'] ?? ''),
             trim($_POST['email'] ?? ''), trim($_POST['adresse'] ?? ''), trim($_POST['coordonnees'] ?? ''), trim($_POST['conditions_paiement'] ?? ''),
             $rccm, $dfe, $rib,
             $uploaded['doc_rccm'] ?? null, $uploaded['doc_idu'] ?? null, $uploaded['doc_dfe'] ?? null,
             $uploaded['doc_arf'] ?? null, $uploaded['doc_cnps'] ?? null, $uploaded['doc_rib'] ?? null, $uploaded['doc_pirl'] ?? null,
             $user['id']]
        );
        $id = (int)db_last_id('fournisseurs_id_seq');
        audit_log($user['id'], 'CREATE', 'achats_param', $id, "Création fournisseur : $raison");
        json_response(true, 'Fournisseur créé.', ['id' => $id]);
    }

    if ($action === 'update') {
        $id     = (int)($_POST['id'] ?? 0);
        $raison = trim($_POST['raison_sociale'] ?? '');
        $rccm   = trim($_POST['numero_rccm'] ?? '');
        $dfe    = trim($_POST['numero_dfe'] ?? '');
        $rib    = trim($_POST['numero_rib'] ?? '');
        if (!$id || !$raison) json_response(false, 'Données invalides.');
        if (!$rccm) json_response(false, 'Le numéro RCCM est obligatoire.');
        if (!$dfe)  json_response(false, 'Le numéro DFE est obligatoire.');
        if (!$rib)  json_response(false, 'Le numéro RIB est obligatoire.');

        $current = db_fetch_one("SELECT doc_rccm, doc_idu, doc_dfe, doc_arf, doc_cnps, doc_rib, doc_pirl FROM fournisseurs WHERE id=?", [$id]);
        if (!$current) json_response(false, 'Fournisseur introuvable.');

        $final      = [];
        $newUploads = [];
        foreach (FOURN_DOCS as $col => $meta) {
            $hasExisting = !empty($current[$col]);
            $res = upload_document($meta['field'], 'fournisseur', 'fournisseur_' . $id, $meta['required'] && !$hasExisting);
            if (!$res['success']) {
                foreach ($newUploads as $f) upload_delete($f, 'fournisseur');
                json_response(false, $meta['label'] . ' : ' . $res['message']);
            }
            if ($res['filename']) {
                $final[$col] = $res['filename'];
                $newUploads[] = $res['filename'];
            } else {
                $final[$col] = $current[$col];
            }
        }

        db_query(
            "UPDATE fournisseurs SET raison_sociale=?, contact_nom=?, telephone=?, email=?, adresse=?, coordonnees=?, conditions_paiement=?,
                    numero_rccm=?, numero_dfe=?, numero_rib=?,
                    doc_rccm=?, doc_idu=?, doc_dfe=?, doc_arf=?, doc_cnps=?, doc_rib=?, doc_pirl=?,
                    modifie_le=NOW()
             WHERE id=?",
            [$raison, trim($_POST['contact_nom'] ?? ''), trim($_POST['telephone'] ?? ''),
             trim($_POST['email'] ?? ''), trim($_POST['adresse'] ?? ''), trim($_POST['coordonnees'] ?? ''), trim($_POST['conditions_paiement'] ?? ''),
             $rccm, $dfe, $rib,
             $final['doc_rccm'], $final['doc_idu'], $final['doc_dfe'], $final['doc_arf'], $final['doc_cnps'], $final['doc_rib'], $final['doc_pirl'],
             $id]
        );

        // Les fichiers remplacés ne sont purgés qu'après l'UPDATE réussi.
        foreach (FOURN_DOCS as $col => $meta) {
            if (in_array($final[$col], $newUploads, true) && !empty($current[$col]) && $current[$col] !== $final[$col]) {
                upload_delete($current[$col], 'fournisseur');
            }
        }

        audit_log($user['id'], 'UPDATE', 'achats_param', $id, "Modification fournisseur : $raison");
        json_response(true, 'Fournisseur mis à jour.');
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $nv  = (int)($_POST['actif'] ?? 0) ? 1 : 0;
        if (!$id) json_response(false, 'Identifiant manquant.');
        db_query("UPDATE fournisseurs SET actif=?, modifie_le=NOW() WHERE id=?", [$nv, $id]);
        audit_log($user['id'], 'UPDATE', 'achats_param', $id, $nv ? 'Réactivation fournisseur' : 'Désactivation fournisseur');
        json_response(true, $nv ? 'Fournisseur réactivé.' : 'Fournisseur désactivé.');
    }

    json_response(false, 'Action inconnue.');
}

// ── PAGE PHP ─────────────────────────────────────────────────
$q       = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$where   = [];
$params  = [];
if ($q !== '') {
    $where[]  = '(raison_sociale LIKE ? OR contact_nom LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)db_fetch_value("SELECT COUNT(*) FROM fournisseurs $whereSql", $params);
$fournisseurs = db_fetch_all(
    "SELECT * FROM fournisseurs $whereSql ORDER BY raison_sociale ASC LIMIT ? OFFSET ?",
    [...$params, $perPage, ($page - 1) * $perPage]
);

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ach-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:18px}
.ach-search{display:flex;gap:8px;align-items:center;flex:1;min-width:0;max-width:360px}
.ach-search input{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;box-sizing:border-box}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 16px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:11px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.ach-badge.on{background:#D1FAE5;color:#065F46}
.ach-badge.off{background:#F1F5F9;color:#475569}
.ach-empty{padding:40px 20px;text-align:center;color:var(--muted);font-size:13px}
.ach-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ach-modal-bg.open{display:flex}
.ach-modal{background:white;border-radius:16px;padding:26px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ach-modal h3{margin:0 0 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.ach-fg{margin-bottom:14px}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg input,.ach-fg textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
.ach-fg textarea{resize:vertical;min-height:60px}
.ach-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
.ach-err{color:#dc2626;font-size:12px;margin-top:4px;display:none}
@media (max-width:768px) {
  .ach-search input, .ach-fg input, .ach-fg textarea { min-height:44px; }
  #docs-modal-list a { display:inline-flex; align-items:center; min-height:44px; padding:0 4px; }
}
</style>

<div class="ach-toolbar">
  <form class="ach-search" method="GET">
    <label for="ach-q" style="position:absolute;left:-9999px">Rechercher un fournisseur</label>
    <input type="text" id="ach-q" name="q" value="<?= h($q) ?>" placeholder="Raison sociale, contact…">
    <button type="submit" class="btn btn-secondary">Rechercher</button>
  </form>
  <?php if ($can_edit): ?>
  <button type="button" class="btn btn-primary" onclick="achOpenCreate()">
    <i class="ph ph-plus" aria-hidden="true"></i> Nouveau fournisseur
  </button>
  <?php endif; ?>
</div>

<div class="ach-table-wrap">
  <?php if (empty($fournisseurs)): ?>
    <div class="ach-empty">Aucun fournisseur trouvé.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="ach-table">
    <thead><tr>
      <th>Raison sociale</th><th>Contact</th><th>Téléphone</th><th>Email</th>
      <th>Score prix</th><th>Score délai</th><th>Dossier</th><th>Statut</th>
      <?php if ($can_edit): ?><th>Actions</th><?php endif; ?>
    </tr></thead>
    <tbody>
      <?php foreach ($fournisseurs as $f):
        // Deux critères mesurables aujourd'hui, jamais agrégés en une note
        // unique — le poids de chacun n'est pas encore arbitré (Bloc 6,
        // point 23). Le troisième critère annoncé à l'origine (le service)
        // reste hors V1, sa donnée n'existe pas encore.
        $score = ach_score_fournisseur((int)$f['id']);
        $docsManquants = [];
        foreach (FOURN_DOCS as $col => $meta) {
            if ($meta['required'] && empty($f[$col])) $docsManquants[] = $meta['label'];
        }
      ?>
      <tr id="frow-<?= $f['id'] ?>">
        <td style="font-weight:700;color:var(--navy)"><?= h($f['raison_sociale']) ?></td>
        <td><?= h($f['contact_nom'] ?: '—') ?></td>
        <td><?= h($f['telephone'] ?: '—') ?></td>
        <td><?= h($f['email'] ?: '—') ?></td>
        <td>
          <?php if ($score['score_prix'] === null): ?>
            <span style="color:var(--muted);font-size:12px">— (aucune offre)</span>
          <?php else: ?>
            <span style="font-weight:700;color:<?= $score['score_prix'] <= 0 ? '#065F46' : '#991B1B' ?>"><?= ($score['score_prix'] > 0 ? '+' : '') . $score['score_prix'] ?>%</span>
            <div style="font-size:10.5px;color:var(--muted)">vs moyenne du lot, <?= $score['nb_offres'] ?> offre(s)</div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($score['score_delai'] === null): ?>
            <span style="color:var(--muted);font-size:12px">— (aucune livraison)</span>
          <?php else: ?>
            <span style="font-weight:700;color:<?= $score['score_delai'] >= 80 ? '#065F46' : ($score['score_delai'] >= 50 ? '#92400E' : '#991B1B') ?>"><?= $score['score_delai'] ?>%</span>
            <div style="font-size:10.5px;color:var(--muted)">délais respectés, <?= $score['nb_livraisons'] ?> livraison(s)</div>
          <?php endif; ?>
        </td>
        <td>
          <?php if (empty($docsManquants)): ?>
            <span class="ach-badge on">Complet</span>
          <?php else: ?>
            <span class="ach-badge off" style="background:#FEF3C7;color:#92400E" title="Manquant : <?= h(implode(', ', $docsManquants)) ?>">Incomplet</span>
          <?php endif; ?>
        </td>
        <td><span class="ach-badge <?= $f['actif'] ? 'on' : 'off' ?>"><?= $f['actif'] ? 'Actif' : 'Inactif' ?></span></td>
        <?php if ($can_edit): ?>
        <td style="display:flex;gap:8px">
          <button type="button" class="btn btn-secondary btn-sm" title="Documents" aria-label="Voir les documents de <?= h($f['raison_sociale']) ?>"
                  onclick='achOuvrirDocs(<?= json_encode($f, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
            <i class="ph ph-folder-open" aria-hidden="true"></i>
          </button>
          <button type="button" class="btn btn-secondary btn-sm" title="Modifier" aria-label="Modifier <?= h($f['raison_sociale']) ?>"
                  onclick='achOpenEdit(<?= json_encode($f, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
            <i class="ph ph-pencil-simple" aria-hidden="true"></i>
          </button>
          <button type="button" class="btn btn-secondary btn-sm" title="<?= $f['actif'] ? 'Désactiver' : 'Réactiver' ?>"
                  aria-label="<?= $f['actif'] ? 'Désactiver' : 'Réactiver' ?> <?= h($f['raison_sociale']) ?>"
                  onclick="achToggle(<?= $f['id'] ?>, <?= $f['actif'] ? 0 : 1 ?>)">
            <i class="ph <?= $f['actif'] ? 'ph-prohibit' : 'ph-check-circle' ?>" aria-hidden="true"></i>
          </button>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?= pagination($total, $perPage, $page, strtok($_SERVER['REQUEST_URI'], '?') . ($q !== '' ? '?q=' . urlencode($q) : '')) ?>
</div>

<!-- MODALE création/édition -->
<div class="ach-modal-bg" id="ach-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="ach-modal-title" style="max-width:640px">
    <h3 id="ach-modal-title"><i class="ph ph-storefront" aria-hidden="true"></i> <span id="ach-modal-ttl-txt">Nouveau fournisseur</span></h3>
    <input type="hidden" id="f-id" value="">

    <div class="ach-fg">
      <label for="f-raison">Raison sociale *</label>
      <input type="text" id="f-raison" required>
    </div>
    <div class="ach-fg">
      <label for="f-contact">Contact</label>
      <input type="text" id="f-contact">
    </div>
    <div class="ach-fg">
      <label for="f-tel">Téléphone</label>
      <input type="text" id="f-tel">
    </div>
    <div class="ach-fg">
      <label for="f-email">Email</label>
      <input type="email" id="f-email">
    </div>
    <div class="ach-fg">
      <label for="f-coord">Coordonnées complémentaires</label>
      <input type="text" id="f-coord" placeholder="fax, site web, autre contact…">
    </div>
    <div class="ach-fg">
      <label for="f-adresse">Adresse complète</label>
      <textarea id="f-adresse"></textarea>
    </div>
    <div class="ach-fg">
      <label for="f-cond">Conditions de paiement</label>
      <input type="text" id="f-cond" placeholder="ex : 30 jours net">
    </div>

    <h4 style="margin:20px 0 10px;font-size:13px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.4px">Identification légale</h4>
    <div class="ach-fg">
      <label for="f-rccm">Numéro RCCM *</label>
      <input type="text" id="f-rccm" required>
    </div>
    <div class="ach-fg">
      <label for="f-dfe">Numéro DFE (identification fiscale) *</label>
      <input type="text" id="f-dfe" required>
    </div>
    <div class="ach-fg">
      <label for="f-rib">Numéro RIB *</label>
      <input type="text" id="f-rib" required>
    </div>

    <h4 style="margin:20px 0 10px;font-size:13px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.4px">Pièces jointes du dossier</h4>
    <div id="ach-docs"></div>

    <div class="ach-err" id="ach-err"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="achCloseModal()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="achSave()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- MODALE consultation des documents -->
<div class="ach-modal-bg" id="docs-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="docs-modal-title">
    <h3 id="docs-modal-title"><i class="ph ph-folder-open" aria-hidden="true"></i> Documents — <span id="docs-modal-nom"></span></h3>
    <ul id="docs-modal-list" style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px"></ul>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="achFermerDocs()">Fermer</button>
    </div>
  </div>
</div>

<!-- MODALE aperçu d'un document -->
<div class="ach-modal-bg" id="doc-preview-modal" style="z-index:2100">
  <div class="ach-modal" role="dialog" aria-labelledby="doc-preview-title" style="max-width:820px;width:95%;height:88vh;display:flex;flex-direction:column">
    <h3 id="doc-preview-title" style="margin-bottom:12px"><i class="ph ph-file-text" aria-hidden="true"></i> <span id="doc-preview-nom"></span></h3>
    <iframe id="doc-preview-frame" src="" style="flex:1;width:100%;border:1px solid var(--border);border-radius:9px;background:#f8fafc"></iframe>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="achFermerPreview()">Fermer</button>
    </div>
  </div>
</div>

<script>
const ACH_DOCS = <?= json_encode(array_map(fn($col, $meta) => ['col' => $col, 'field' => $meta['field'], 'label' => $meta['label'], 'required' => $meta['required']], array_keys(FOURN_DOCS), FOURN_DOCS)) ?>;
const ACH_DOC_URL = <?= json_encode(upload_url_base('fournisseur')) ?>;

function achOuvrirDocs(f) {
  document.getElementById('docs-modal-nom').textContent = f.raison_sociale || '';
  document.getElementById('docs-modal-list').innerHTML = ACH_DOCS.map(d => {
    const nom = f[d.col];
    const statut = nom
      ? `<button type="button" onclick="achApercuDoc('${esc(nom).replace(/'/g,"\\'")}', '${esc(d.label).replace(/'/g,"\\'")}')" style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:var(--blue-mid,#1a56a0);background:none;border:none;padding:0;cursor:pointer;text-decoration:none;font-weight:700"><i class="ph ph-file-text" aria-hidden="true"></i> Voir</button>`
      : `<span style="font-size:12.5px;color:${d.required ? '#991B1B' : 'var(--muted)'}">${d.required ? 'Manquant' : 'Non fourni'}</span>`;
    return `<li style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 12px;background:#f8fafc;border:1px solid var(--border);border-radius:9px">
      <span style="font-size:13px">${esc(d.label)}${d.required ? ' *' : ''}</span>
      ${statut}
    </li>`;
  }).join('');
  document.getElementById('docs-modal').classList.add('open');
}
function achFermerDocs() { document.getElementById('docs-modal').classList.remove('open'); }
document.getElementById('docs-modal').addEventListener('click', e => { if (e.target === e.currentTarget) achFermerDocs(); });
function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function achApercuDoc(nom, label) {
  document.getElementById('doc-preview-nom').textContent = label || '';
  document.getElementById('doc-preview-frame').src = ACH_DOC_URL + encodeURIComponent(nom);
  document.getElementById('doc-preview-modal').classList.add('open');
}
function achFermerPreview() {
  document.getElementById('doc-preview-modal').classList.remove('open');
  document.getElementById('doc-preview-frame').src = '';
}
document.getElementById('doc-preview-modal').addEventListener('click', e => { if (e.target === e.currentTarget) achFermerPreview(); });
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (document.getElementById('doc-preview-modal').classList.contains('open')) achFermerPreview();
  else achFermerDocs();
});

function achRenderDocs(current) {
  current = current || {};
  const wrap = document.getElementById('ach-docs');
  wrap.innerHTML = ACH_DOCS.map(d => {
    const existing = current[d.col];
    const link = existing
      ? `<button type="button" onclick="achApercuDoc('${esc(existing).replace(/'/g,"\\'")}', '${esc(d.label).replace(/'/g,"\\'")}')" style="font-size:12px;color:var(--blue-mid,#1a56a0);background:none;border:none;padding:0;cursor:pointer;text-decoration:none;margin-left:8px"><i class="ph ph-file-text" aria-hidden="true"></i> document actuel</button>`
      : '';
    return `<div class="ach-fg">
      <label for="doc-${d.col}">${d.label}${d.required ? ' *' : ''}${link}</label>
      <input type="file" id="doc-${d.col}" name="${d.field}" accept=".pdf,.jpg,.jpeg,.png,.webp">
    </div>`;
  }).join('');
}

function achOpenCreate() {
  document.getElementById('f-id').value = '';
  document.getElementById('ach-modal-ttl-txt').textContent = 'Nouveau fournisseur';
  ['f-raison','f-contact','f-tel','f-email','f-coord','f-adresse','f-cond','f-rccm','f-dfe','f-rib'].forEach(id => document.getElementById(id).value = '');
  achRenderDocs({});
  document.getElementById('ach-err').style.display = 'none';
  document.getElementById('ach-modal').classList.add('open');
  setTimeout(() => document.getElementById('f-raison').focus(), 80);
}
function achOpenEdit(f) {
  document.getElementById('f-id').value = f.id;
  document.getElementById('ach-modal-ttl-txt').textContent = 'Modifier le fournisseur';
  document.getElementById('f-raison').value  = f.raison_sociale || '';
  document.getElementById('f-contact').value = f.contact_nom || '';
  document.getElementById('f-tel').value     = f.telephone || '';
  document.getElementById('f-email').value   = f.email || '';
  document.getElementById('f-coord').value   = f.coordonnees || '';
  document.getElementById('f-adresse').value = f.adresse || '';
  document.getElementById('f-cond').value    = f.conditions_paiement || '';
  document.getElementById('f-rccm').value    = f.numero_rccm || '';
  document.getElementById('f-dfe').value     = f.numero_dfe || '';
  document.getElementById('f-rib').value     = f.numero_rib || '';
  achRenderDocs(f);
  document.getElementById('ach-err').style.display = 'none';
  document.getElementById('ach-modal').classList.add('open');
}
function achCloseModal() { document.getElementById('ach-modal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') achCloseModal(); });
document.getElementById('ach-modal').addEventListener('click', e => { if (e.target === e.currentTarget) achCloseModal(); });

function achPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  ACH_DOCS.forEach(d => {
    const input = document.getElementById('doc-' + d.col);
    if (input && input.files[0]) fd.append(d.field, input.files[0]);
  });
  return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
}

function achSave() {
  const id = document.getElementById('f-id').value;
  const raison = document.getElementById('f-raison').value.trim();
  const rccm = document.getElementById('f-rccm').value.trim();
  const dfe = document.getElementById('f-dfe').value.trim();
  const rib = document.getElementById('f-rib').value.trim();
  const err = document.getElementById('ach-err');
  if (!raison) { err.textContent = 'La raison sociale est obligatoire.'; err.style.display = 'block'; return; }
  if (!rccm)   { err.textContent = 'Le numéro RCCM est obligatoire.'; err.style.display = 'block'; return; }
  if (!dfe)    { err.textContent = 'Le numéro DFE est obligatoire.'; err.style.display = 'block'; return; }
  if (!rib)    { err.textContent = 'Le numéro RIB est obligatoire.'; err.style.display = 'block'; return; }
  if (!id) {
    for (const d of ACH_DOCS) {
      if (d.required && !document.getElementById('doc-' + d.col).files[0]) {
        err.textContent = `Le document « ${d.label} » est obligatoire.`; err.style.display = 'block'; return;
      }
    }
  }
  achPost({
    action: id ? 'update' : 'create',
    id: id,
    raison_sociale: raison,
    contact_nom: document.getElementById('f-contact').value.trim(),
    telephone: document.getElementById('f-tel').value.trim(),
    email: document.getElementById('f-email').value.trim(),
    coordonnees: document.getElementById('f-coord').value.trim(),
    adresse: document.getElementById('f-adresse').value.trim(),
    conditions_paiement: document.getElementById('f-cond').value.trim(),
    numero_rccm: rccm,
    numero_dfe: dfe,
    numero_rib: rib,
  }).then(res => {
    if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
    toast(res.message, 'success');
    achCloseModal();
    setTimeout(() => location.reload(), 500);
  });
}

function achToggle(id, actif) {
  if (!confirm(actif ? 'Réactiver ce fournisseur ?' : 'Désactiver ce fournisseur ?')) return;
  achPost({ action: 'toggle', id, actif }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
