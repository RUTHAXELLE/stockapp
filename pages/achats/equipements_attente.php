<?php
// ============================================================
//  pages/achats/equipements_attente.php — File d'attente d'affectation
//  Exemplaires equipements créés à la réception d'une ligne DAI rattachée
//  à une nomenclature (Étape 3c). Propose l'affectation (Étape 4) — le
//  circuit de visa lui-même se joue sur pages/achats/mes_visas.php.
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/achats.php';
require_once __DIR__ . '/../../includes/upload.php';

require_auth();
$user = current_user();
$uid  = (int)$user['id'];
$is_admin = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
// Pas require_permission() strict : le N+1 d'un département doit pouvoir
// confirmer la réception de ses équipements sans porter achats_suivi.can_read
// — même porte que pages/achats/receptions.php (étape 3 du circuit magasin
// -> département, décision du 2026-08-23).
if (!can('achats_suivi', 'can_read') && !ach_est_n1_quelque_part($uid)) {
    http_response_code(403);
    include __DIR__ . '/../../templates/403.php';
    exit;
}
// Même population que la réception (pages/achats/receptions.php) : ceux
// qui réceptionnent un exemplaire sont ceux qui en proposent l'affectation.
$can_proposer = can('achats_suivi', 'can_create');
$_SESSION['groupe_actif'] = 'ACHATS';
$page_title  = "File d'attente d'affectation";
$active_page = 'achats_equipements_attente';

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Filet de sécurité : une erreur inattendue (SQL, colonne manquante après
    // une migration oubliée, etc.) plantait ce script en silence — display_
    // errors est désactivé en production (cf. Dockerfile), la réponse HTTP
    // revenait donc vide, indiscernable d'un bouton qui ne répond pas.
    // Repéré en recette (2026-08-25) : migration_achats_21 pas encore
    // appliquée sur la base, equipements.bon_livraison inexistante. Les
    // erreurs métier (AchValidationException) restent gérées au plus près,
    // ce filet n'attrape que ce qu'elles ne couvrent pas.
    try {
        if ($action === 'proposer') {
            if (!$can_proposer) json_response(false, 'Action réservée.');
            $equipement_id  = (int)($_POST['equipement_id'] ?? 0);
            $type           = $_POST['type'] ?? '';
            if (!in_array($type, ['departement', 'site'], true)) json_response(false, "Choisissez d'abord le type d'affectation.");
            $site_id        = $type === 'site' ? ((int)($_POST['site_id'] ?? 0) ?: null) : null;
            if ($type === 'site' && !$site_id) json_response(false, 'Le site de destination est obligatoire.');
            $utilisateur_id = (int)($_POST['utilisateur_id'] ?? 0) ?: null;
            try {
                $res = ach_proposer_affectation($equipement_id, $site_id, $utilisateur_id, $user);
                json_response(true, "Affectation proposée — palier « {$res['palier']} », {$res['etapes']} étape(s).", $res);
            } catch (AchValidationException $e) {
                json_response(false, $e->getMessage());
            }
        }

        // Étape 3 du circuit magasin -> département : le N+1 confirme que
        // l'exemplaire est physiquement arrivé — bon de livraison obligatoire,
        // comme pour la réception département des consommables (bon_transfert).
        if ($action === 'confirmer_reception') {
            $equipement_id = (int)($_POST['equipement_id'] ?? 0);
            if (empty($_FILES['bl']['name'])) json_response(false, 'Le bon de livraison est obligatoire.');
            $up = upload_document('bl', 'bl', 'bl_equipement_' . $equipement_id, false);
            if (!$up['success']) json_response(false, $up['message']);
            try {
                $ok = ach_confirmer_reception_equipement($equipement_id, $user, $up['filename']);
                if ($ok) json_response(true, 'Réception confirmée.');
                json_response(false, "Cet exemplaire n'est plus en attente de confirmation.");
            } catch (AchValidationException $e) {
                json_response(false, $e->getMessage());
            }
        }

        json_response(false, 'Action inconnue.');
    } catch (\Throwable $e) {
        error_log("equipements_attente.php AJAX ($action) : " . $e->getMessage());
        json_response(false, "Erreur interne du serveur — contactez l'administrateur si ça persiste.");
    }
}

// ── PAGE PHP ─────────────────────────────────────────────────
$f_departement = (int)($_GET['departement'] ?? 0);
// Les deux premières sections (proposer/suivre une affectation) restent
// du ressort du service Achats — un N+1 pur n'y voit rien, il n'a affaire
// qu'à la 3e (confirmer la réception dans son département).
$voit_toutes_sections = can('achats_suivi', 'can_read');

$where  = ["e.statut_stock = 'en_attente_affectation'"];
$params = [];
if ($f_departement) { $where[] = 'e.departement_id = ?'; $params[] = $f_departement; }
$equipements = $voit_toutes_sections ? db_fetch_all(
    "SELECT e.id, e.numero_serie_interne, e.prix_achat, e.date_acquisition,
            n.libelle AS nomenclature_libelle, n.categorie,
            e.departement_id, d.label AS departement_label,
            f.numero AS feb_numero, fl.designation AS ligne_designation
     FROM equipements e
     LEFT JOIN nomenclatures n  ON n.id = e.nomenclature_id
     LEFT JOIN departements d   ON d.id = e.departement_id
     LEFT JOIN feb_lignes fl    ON fl.id = e.feb_ligne_id
     LEFT JOIN feb f            ON f.id = fl.feb_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY e.date_acquisition DESC, e.id DESC",
    $params
) : [];
$total_valorise = array_sum(array_column($equipements, 'prix_achat'));

// Propositions déjà lancées, en cours de visa — visibilité sur où en est
// chaque exemplaire, pas seulement sur ce qui n'a encore rien proposé.
$where2  = ["e.statut_stock = 'affectation_en_cours'"];
$params2 = [];
if ($f_departement) { $where2[] = 'e.departement_id = ?'; $params2[] = $f_departement; }
$en_cours = $voit_toutes_sections ? db_fetch_all(
    "SELECT e.id, e.numero_serie_interne, e.prix_achat,
            n.libelle AS nomenclature_libelle,
            d.label AS departement_label,
            ea.id AS affectation_id, ea.etape_actuelle, ea.workflow_snapshot, ea.proposee_le,
            s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS utilisateur_nom
     FROM equipements e
     JOIN equipement_affectations ea ON ea.equipement_id = e.id AND ea.statut='en_validation'
     LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id
     LEFT JOIN departements d  ON d.id = e.departement_id
     LEFT JOIN sites s         ON s.id = ea.site_id
     LEFT JOIN users u         ON u.id = ea.utilisateur_id
     WHERE " . implode(' AND ', $where2) . "
     ORDER BY ea.proposee_le DESC",
    $params2
) : [];

// Étape 3 du circuit magasin -> département : exemplaires validés,
// expédiés, en attente de confirmation physique par le N+1.
$en_transit = ach_equipements_en_transit($user);
foreach ($en_transit as &$et) {
    $et['departement_label'] = $et['departement_id'] ? db_fetch_value("SELECT label FROM departements WHERE id=?", [$et['departement_id']]) : null;
    $et['nomenclature_libelle'] = $et['nomenclature_id'] ? db_fetch_value("SELECT libelle FROM nomenclatures WHERE id=?", [$et['nomenclature_id']]) : null;
    $et['site_nom'] = $et['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$et['site_id']]) : null;
    $et['utilisateur_nom'] = $et['utilisateur_id'] ? db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$et['utilisateur_id']]) : null;
}
unset($et);

$departements_actifs = db_fetch_all("SELECT id, label FROM departements WHERE actif=1 ORDER BY label");
$sites_actifs = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");

// Utilisateur nommé à l'affectation (Étape 4) : restreint aux membres du
// département de l'exemplaire — signalé sur la recette (FEB-2026-0008),
// la liste montrait n'importe quel utilisateur actif de l'application.
// Regroupé par département ici, filtré côté client dans eaOuvrir().
$users_par_departement = [];
foreach (db_fetch_all(
    "SELECT ud.departement_id, u.id, CONCAT(u.prenom,' ',u.nom) AS nom
     FROM user_departements ud
     JOIN users u ON u.id = ud.user_id
     WHERE u.actif = 1
     ORDER BY nom"
) as $row) {
    $users_par_departement[(int)$row['departement_id']][] = ['id' => (int)$row['id'], 'nom' => $row['nom']];
}

// Même chose pour une affectation à un site précis : l'utilisateur nommé
// doit être quelqu'un de ce site (users.site_id), pas de son département.
$users_par_site = [];
foreach (db_fetch_all(
    "SELECT site_id, id, CONCAT(prenom,' ',nom) AS nom FROM users WHERE actif = 1 AND site_id IS NOT NULL ORDER BY nom"
) as $row) {
    $users_par_site[(int)$row['site_id']][] = ['id' => (int)$row['id'], 'nom' => $row['nom']];
}

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ach-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:18px}
.ach-toolbar select{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;background:white}
.ach-section-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy);margin:24px 0 12px}
.ach-section-ttl:first-child{margin-top:0}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 16px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:10px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-empty{padding:40px 20px;text-align:center;color:var(--muted);font-size:13px}
.ach-summary{font-size:13px;color:var(--muted)}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.ach-badge.on{background:#D1FAE5;color:#065F46}
.ach-badge.off{background:#FEF3C7;color:#92400E}
.ach-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ach-modal-bg.open{display:flex}
.ach-modal{background:white;border-radius:16px;padding:26px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ach-modal h3{margin:0 0 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.ach-fg{margin-bottom:14px}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;background:white}
.ach-err{color:#dc2626;font-size:12px;margin-top:4px;display:none}
.ach-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
@media (max-width:768px) { .ach-toolbar select, .ach-fg select, .btn { min-height:44px; } }
</style>

<?php if ($voit_toutes_sections): ?>
<div class="ach-toolbar">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <label for="ach-departement" style="font-size:13px;font-weight:600;color:#374151">Département</label>
    <select id="ach-departement" name="departement" onchange="this.form.submit()">
      <option value="0">Tous</option>
      <?php foreach ($departements_actifs as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $f_departement === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <div class="ach-summary"><?= count($equipements) ?> exemplaire(s) en attente — <?= fmt_number((float)$total_valorise) ?> XOF valorisés</div>
</div>

<div class="ach-section-ttl">En attente d'affectation</div>
<div class="ach-table-wrap">
  <?php if (empty($equipements)): ?>
    <div class="ach-empty">Aucun exemplaire en attente d'affectation<?= $f_departement ? ' pour ce département' : '' ?>.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table data-paginer="25" class="ach-table">
    <thead><tr>
      <th>N° série (provisoire)</th><th>Nomenclature</th><th>Département</th><th>FEB d'origine</th><th>Valeur</th><th>Date de réception</th>
      <?php if ($can_proposer): ?><th>Actions</th><?php endif; ?>
    </tr></thead>
    <tbody>
      <?php foreach ($equipements as $e): ?>
      <tr>
        <td style="font-family:monospace;font-size:12px"><?= h($e['numero_serie_interne']) ?></td>
        <td><?= h($e['nomenclature_libelle'] ?: '—') ?></td>
        <td><?= h($e['departement_label'] ?: '—') ?></td>
        <td>
          <?php if ($e['feb_numero']): ?>
            <span style="font-weight:700;color:var(--navy)"><?= h($e['feb_numero']) ?></span>
            <div style="font-size:11.5px;color:var(--muted)"><?= h($e['ligne_designation'] ?: '') ?></div>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= fmt_number((float)$e['prix_achat']) ?> XOF</td>
        <td><?= fmt_date($e['date_acquisition']) ?></td>
        <?php if ($can_proposer): ?>
        <td>
          <button type="button" class="btn btn-primary btn-sm"
                  onclick='eaOuvrir(<?= json_encode(["id"=>(int)$e["id"], "nsi"=>$e["numero_serie_interne"], "nomenclature"=>$e["nomenclature_libelle"], "valeur"=>(float)$e["prix_achat"], "departement_id"=>(int)($e["departement_id"] ?? 0), "departement"=>$e["departement_label"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>
            Affecter
          </button>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="ach-section-ttl">Affectation en cours de validation</div>
<div class="ach-table-wrap">
  <?php if (empty($en_cours)): ?>
    <div class="ach-empty">Aucune proposition d'affectation en cours<?= $f_departement ? ' pour ce département' : '' ?>.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table data-paginer="25" class="ach-table">
    <thead><tr>
      <th>N° série (provisoire)</th><th>Nomenclature</th><th>Département</th><th>Destination proposée</th><th>Valeur</th><th>Étape en attente</th><th>Proposée le</th>
    </tr></thead>
    <tbody>
      <?php foreach ($en_cours as $e):
        $wf = json_decode($e['workflow_snapshot'] ?? '[]', true) ?: [];
        $etape_label = $wf[(int)$e['etape_actuelle']]['label'] ?? '—';
      ?>
      <tr>
        <td style="font-family:monospace;font-size:12px"><?= h($e['numero_serie_interne']) ?></td>
        <td><?= h($e['nomenclature_libelle'] ?: '—') ?></td>
        <td><?= h($e['departement_label'] ?: '—') ?></td>
        <td><?= $e['site_nom'] ? h($e['site_nom']) : ($e['departement_label'] ? h($e['departement_label']) . ' (département)' : '—') ?><?= $e['utilisateur_nom'] ? ' — ' . h($e['utilisateur_nom']) : '' ?></td>
        <td><?= fmt_number((float)$e['prix_achat']) ?> XOF</td>
        <td><span class="ach-badge off"><?= h($etape_label) ?></span></td>
        <td><?= fmt_date($e['proposee_le']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="ach-section-ttl">Étape 3 — En transit, à confirmer par mon département</div>
<div class="ach-table-wrap">
  <?php if (empty($en_transit)): ?>
    <div class="ach-empty">Rien en attente de confirmation pour votre département.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table data-paginer="25" class="ach-table">
    <thead><tr>
      <th>N° série (provisoire)</th><th>Nomenclature</th><th>Département</th><th>Destination</th><th>Valeur</th><th>Actions</th>
    </tr></thead>
    <tbody>
      <?php foreach ($en_transit as $e): ?>
      <tr>
        <td style="font-family:monospace;font-size:12px"><?= h($e['numero_serie_interne']) ?></td>
        <td><?= h($e['nomenclature_libelle'] ?: '—') ?></td>
        <td><?= h($e['departement_label'] ?: '—') ?></td>
        <td><?= $e['site_nom'] ? h($e['site_nom']) : ($e['departement_label'] ? h($e['departement_label']) . ' (département)' : '—') ?><?= $e['utilisateur_nom'] ? ' — ' . h($e['utilisateur_nom']) : '' ?></td>
        <td><?= fmt_number((float)$e['prix_achat']) ?> XOF</td>
        <td>
          <button type="button" class="btn btn-primary btn-sm"
                  onclick='etOuvrir(<?= (int)$e["id"] ?>, <?= json_encode($e["numero_serie_interne"], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
            Confirmer la réception
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- MODALE proposer une affectation -->
<div class="ach-modal-bg" id="ea-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="ea-modal-title">

    <!-- Étape 1 : à quoi affecter l'exemplaire -->
    <div id="ea-etape-choix">
      <h3 id="ea-modal-title">Affecter cet exemplaire</h3>
      <div class="ach-fg">
        <label>Exemplaire</label>
        <div id="ea-recap-choix" style="font-size:13px;font-weight:700;color:var(--navy)"></div>
      </div>
      <div class="ach-fg" style="display:flex;gap:10px">
        <button type="button" class="btn btn-secondary" id="ea-btn-departement" style="flex:1" onclick="eaChoisir('departement')"></button>
        <button type="button" class="btn btn-secondary" style="flex:1" onclick="eaChoisir('site')">À un site</button>
      </div>
      <div class="ach-modal-actions">
        <button type="button" class="btn btn-secondary" onclick="eaFermer()">Annuler</button>
      </div>
    </div>

    <!-- Étape 2 : formulaire de proposition -->
    <div id="ea-etape-form" style="display:none">
      <h3>Proposer une affectation</h3>
      <input type="hidden" id="ea-equipement-id" value="">
      <input type="hidden" id="ea-departement-id" value="">
      <input type="hidden" id="ea-type" value="">
      <div class="ach-fg">
        <label>Exemplaire</label>
        <div id="ea-recap" style="font-size:13px;font-weight:700;color:var(--navy)"></div>
      </div>
      <div class="ach-fg">
        <label>Département</label>
        <div id="ea-departement-label" style="font-size:13px;font-weight:700;color:var(--navy)"></div>
      </div>
      <div class="ach-fg" id="ea-site-wrap">
        <label for="ea-site">Site de destination</label>
        <select id="ea-site">
          <option value="">— Sélectionner —</option>
          <?php foreach ($sites_actifs as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="ach-fg">
        <label for="ea-utilisateur">Utilisateur nommé (facultatif)</label>
        <select id="ea-utilisateur">
          <option value="">—</option>
        </select>
        <div id="ea-utilisateur-hint" style="font-size:11.5px;color:var(--muted);margin-top:4px"></div>
      </div>
      <div class="ach-hint" style="font-size:11.5px;color:var(--muted);margin-bottom:10px">
        Le circuit de validation (palier RAF/RAF+DAF/RAF+DAF+PDG) est déterminé automatiquement par la valeur de l'exemplaire.
      </div>
      <div class="ach-err" id="ea-err"></div>
      <div class="ach-modal-actions">
        <button type="button" class="btn btn-secondary" onclick="eaEtapeChoix()">← Changer</button>
        <button type="button" class="btn btn-secondary" onclick="eaFermer()">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="eaValider()">Proposer</button>
      </div>
    </div>

  </div>
</div>

<!-- MODALE confirmer la réception (bon de livraison) -->
<div class="ach-modal-bg" id="et-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="et-modal-title">
    <h3 id="et-modal-title">Confirmer la réception</h3>
    <input type="hidden" id="et-equipement-id" value="">
    <div class="ach-fg">
      <label>Exemplaire</label>
      <div id="et-recap" style="font-size:13px;font-weight:700;color:var(--navy)"></div>
    </div>
    <div class="ach-fg">
      <label for="et-bl">Bon de livraison (PDF, JPG, PNG ou WEBP) *</label>
      <input type="file" id="et-bl" accept=".pdf,.jpg,.jpeg,.png,.webp">
    </div>
    <div class="ach-err" id="et-err"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="etFermer()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="etValider()">Confirmer</button>
    </div>
  </div>
</div>

<script>
const usersParDepartement = <?= json_encode($users_par_departement, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const usersParSite = <?= json_encode($users_par_site, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let eaCourant = null;

function etOuvrir(equipementId, nsi) {
  document.getElementById('et-equipement-id').value = equipementId;
  document.getElementById('et-recap').textContent = nsi;
  document.getElementById('et-bl').value = '';
  document.getElementById('et-err').style.display = 'none';
  document.getElementById('et-modal').classList.add('open');
}
function etFermer() { document.getElementById('et-modal').classList.remove('open'); }
document.getElementById('et-modal').addEventListener('click', e => { if (e.target === e.currentTarget) etFermer(); });

function etValider() {
  const err = document.getElementById('et-err');
  if (!document.getElementById('et-bl').files[0]) { err.textContent = 'Le bon de livraison est obligatoire.'; err.style.display = 'block'; return; }
  const fd = new FormData();
  fd.append('action', 'confirmer_reception');
  fd.append('equipement_id', document.getElementById('et-equipement-id').value);
  fd.append('bl', document.getElementById('et-bl').files[0]);
  fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
    .then(achParseJson)
    .then(res => {
      if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
      toast(res.message, 'success');
      etFermer();
      setTimeout(() => location.reload(), 600);
    })
    .catch(e => { err.textContent = e.message || 'Erreur de communication avec le serveur — réessayez.'; err.style.display = 'block'; });
}

function eaOuvrir(e) {
  eaCourant = e;
  document.getElementById('ea-recap-choix').textContent = e.nsi + ' — ' + (e.nomenclature || '—') + ' — ' + Number(e.valeur).toLocaleString('fr-FR') + ' XOF';
  const btnDept = document.getElementById('ea-btn-departement');
  btnDept.textContent = 'Au département' + (e.departement ? ' (' + e.departement + ')' : '');
  btnDept.disabled = !e.departement_id;
  btnDept.title = e.departement_id ? '' : "Cet exemplaire ne porte aucun département.";

  eaEtapeChoix();
  document.getElementById('ea-err').style.display = 'none';
  document.getElementById('ea-modal').classList.add('open');
}

function eaEtapeChoix() {
  document.getElementById('ea-etape-choix').style.display = '';
  document.getElementById('ea-etape-form').style.display = 'none';
}

function eaChoisir(type) {
  const e = eaCourant;
  document.getElementById('ea-equipement-id').value = e.id;
  document.getElementById('ea-departement-id').value = e.departement_id || '';
  document.getElementById('ea-type').value = type;
  document.getElementById('ea-recap').textContent = e.nsi + ' — ' + (e.nomenclature || '—') + ' — ' + Number(e.valeur).toLocaleString('fr-FR') + ' XOF';
  document.getElementById('ea-departement-label').textContent = e.departement || '—';
  document.getElementById('ea-site').value = '';

  const siteWrap = document.getElementById('ea-site-wrap');
  const sel = document.getElementById('ea-utilisateur');
  const hint = document.getElementById('ea-utilisateur-hint');

  if (type === 'departement') {
    siteWrap.style.display = 'none';
    sel.innerHTML = '<option value="">— Aucun (affectation au département lui-même) —</option>';
    (usersParDepartement[e.departement_id] || []).forEach(u => sel.add(new Option(u.nom, u.id)));
    hint.textContent = 'Limité aux membres du département de l\'exemplaire.';
  } else {
    siteWrap.style.display = '';
    sel.innerHTML = '<option value="">— Aucun (affectation au site) —</option>';
    hint.textContent = 'Choisissez un site pour voir les personnes qui y sont rattachées.';
  }

  document.getElementById('ea-err').style.display = 'none';
  document.getElementById('ea-etape-choix').style.display = 'none';
  document.getElementById('ea-etape-form').style.display = '';
}

document.getElementById('ea-site').addEventListener('change', function () {
  const sel = document.getElementById('ea-utilisateur');
  sel.innerHTML = '<option value="">— Aucun (affectation au site) —</option>';
  (usersParSite[this.value] || []).forEach(u => sel.add(new Option(u.nom, u.id)));
});

function eaFermer() { document.getElementById('ea-modal').classList.remove('open'); eaCourant = null; }
document.getElementById('ea-modal').addEventListener('click', e => { if (e.target === e.currentTarget) eaFermer(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { eaFermer(); etFermer(); } });

function eaValider() {
  const err = document.getElementById('ea-err');
  const type = document.getElementById('ea-type').value;
  const site_id = document.getElementById('ea-site').value;
  if (type === 'site' && !site_id) { err.textContent = 'Le site de destination est obligatoire.'; err.style.display = 'block'; return; }
  const fd = new FormData();
  fd.append('action', 'proposer');
  fd.append('type', type);
  fd.append('equipement_id', document.getElementById('ea-equipement-id').value);
  fd.append('site_id', type === 'site' ? site_id : '');
  fd.append('utilisateur_id', document.getElementById('ea-utilisateur').value);
  fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
    .then(achParseJson)
    .then(res => {
      if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
      toast(res.message, 'success');
      eaFermer();
      setTimeout(() => location.reload(), 600);
    })
    .catch(e => { err.textContent = e.message || 'Erreur de communication avec le serveur — réessayez.'; err.style.display = 'block'; });
}
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
