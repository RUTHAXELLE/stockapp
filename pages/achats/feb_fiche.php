<?php
// ============================================================
//  pages/achats/feb_fiche.php — Création / édition d'un brouillon de FEB
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
require_permission('achats', 'can_create');
$_SESSION['groupe_actif'] = 'ACHATS';

// ── Charge un brouillon existant — un brouillon n'est vu que de son auteur,
//    et seul un statut 'brouillon' reste éditable ici.
function feb_charger_brouillon(int $feb_id, int $uid): ?array {
    $feb = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$feb || (int)$feb['demandeur_id'] !== $uid || $feb['statut'] !== 'brouillon') return null;
    return $feb;
}

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $feb_id_in = trim($_POST['feb_id'] ?? '');
        $feb_id    = $feb_id_in !== '' ? (int)$feb_id_in : null;
        if ($feb_id !== null && !feb_charger_brouillon($feb_id, (int)$user['id'])) {
            json_response(false, 'Ce brouillon est introuvable ou n\'est plus modifiable.');
        }

        $lignes = json_decode($_POST['lignes'] ?? '[]', true);
        if (!is_array($lignes)) $lignes = [];

        $entete = [
            'site_id'        => $_POST['site_id'] ?? null,
            'departement_id' => $_POST['departement_id'] ?? null,
            'fonction'       => $_POST['fonction'] ?? '',
            'urgence'        => $_POST['urgence'] ?? 0,
            'objet'          => $_POST['objet'] ?? '',
        ];
        $soumettre = ($_POST['soumettre'] ?? '0') === '1';

        try {
            $res = ach_creer_feb($user, $feb_id, $entete, $lignes, $soumettre);
            $msg = $soumettre ? "FEB {$res['numero']} soumise." : 'Brouillon enregistré.';
            json_response(true, $msg, $res);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage(), ['field' => $e->field]);
        } catch (Exception $e) {
            json_response(false, $e->getMessage());
        }
    }

    if ($action === 'upload_piece') {
        $feb_id = (int)($_POST['feb_id'] ?? 0);
        if (!$feb_id || !feb_charger_brouillon($feb_id, (int)$user['id'])) {
            json_response(false, 'Brouillon introuvable ou non modifiable.');
        }
        $up = upload_document('fichier', 'feb', 'feb_' . $feb_id, true);
        if (!$up['success']) json_response(false, $up['message']);

        $taille = (int)($_FILES['fichier']['size'] ?? 0);
        $finfo  = new finfo(FILEINFO_MIME_TYPE);
        $mime   = $finfo->file(UPLOAD_FEB_DIR . $up['filename']);
        $nom_origine = basename($_FILES['fichier']['name'] ?? $up['filename']);

        db_query(
            "INSERT INTO feb_pieces_jointes (feb_id, fichier, nom_origine, taille, mime, deposee_par)
             VALUES (?,?,?,?,?,?)",
            [$feb_id, $up['filename'], $nom_origine, $taille, $mime, $user['id']]
        );
        $piece_id = (int) db_last_id('feb_pieces_jointes_id_seq');
        json_response(true, 'Fichier déposé.', [
            'id' => $piece_id, 'nom_origine' => $nom_origine, 'taille' => $taille,
            'url' => upload_url($up['filename'], 'feb'),
        ]);
    }

    if ($action === 'delete_piece') {
        $feb_id   = (int)($_POST['feb_id'] ?? 0);
        $piece_id = (int)($_POST['piece_id'] ?? 0);
        if (!$feb_id || !feb_charger_brouillon($feb_id, (int)$user['id'])) {
            json_response(false, 'Brouillon introuvable ou non modifiable.');
        }
        $piece = db_fetch_one("SELECT * FROM feb_pieces_jointes WHERE id=? AND feb_id=?", [$piece_id, $feb_id]);
        if (!$piece) json_response(false, 'Pièce jointe introuvable.');
        upload_delete($piece['fichier'], 'feb');
        db_query("DELETE FROM feb_pieces_jointes WHERE id=?", [$piece_id]);
        json_response(true, 'Pièce jointe supprimée.');
    }

    json_response(false, 'Action inconnue.');
}

// ── PAGE PHP ─────────────────────────────────────────────────
$feb_id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$feb        = null;
$feb_lignes = [];
$feb_pieces = [];
if ($feb_id) {
    $feb = feb_charger_brouillon($feb_id, (int)$user['id']);
    if (!$feb) {
        header('Location: ' . APP_URL . '/pages/achats/mes_feb.php');
        exit;
    }
    $feb_lignes = db_fetch_all("SELECT * FROM feb_lignes WHERE feb_id=? ORDER BY numero_ligne", [$feb_id]);
    $feb_pieces = db_fetch_all("SELECT * FROM feb_pieces_jointes WHERE feb_id=? ORDER BY deposee_le", [$feb_id]);
}

$page_title  = $feb ? 'Modifier la FEB' : 'Nouvelle FEB';
$active_page = 'achats_feb_fiche';

// Préremplissage site/service depuis le profil (modifiable), sauf brouillon existant
$user_departement_id = db_fetch_value(
    "SELECT departement_id FROM user_departements WHERE user_id=? LIMIT 1", [$user['id']]
);
$default_site_id  = $feb ? $feb['site_id']        : ($user['site_id'] ?? null);
$default_dept_id  = $feb ? $feb['departement_id'] : $user_departement_id;
$default_fonction = $feb ? $feb['fonction']       : ($user['role_nom'] ?? '');
$default_urgence  = $feb ? (int)$feb['urgence']   : 0;
$default_objet    = $feb ? $feb['objet']          : '';

// code inclus pour chaque référentiel : RG-05 compose le code analytique
// côté client (aperçu en lecture seule) sur le même schéma que
// ach_code_analytique() côté serveur, qui reste seule autorité à l'écriture.
$sites        = db_fetch_all("SELECT id, nom, code FROM sites WHERE actif=1 ORDER BY nom");
$departements = db_fetch_all("SELECT id, label, code FROM departements WHERE actif=1 ORDER BY label");
$familles     = db_fetch_all("SELECT id, libelle, code FROM familles_achat WHERE actif=1 ORDER BY libelle");
$types_achat  = db_fetch_all("SELECT code, libelle FROM achat_types WHERE actif=1 ORDER BY libelle");
$articles     = db_fetch_all("SELECT id, code, libelle, unite FROM articles WHERE actif=1 ORDER BY libelle");
$max_lignes = (int)ach_param('max_lignes_feb', 14);

include __DIR__ . '/../../templates/header.php';
?>
<style>
.feb-hdr-card{background:white;border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:18px}
.feb-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 18px}
@media(max-width:768px){.feb-grid{grid-template-columns:minmax(0,1fr)}}
.ach-fg{margin:0}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg input,.ach-fg select,.ach-fg textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
.ach-fg textarea{resize:vertical;min-height:60px}
.ach-err{color:#dc2626;font-size:12px;margin-top:4px;display:none}
.feb-lignes-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:10px}
.feb-lignes-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy)}
.feb-lignes-count{font-size:12px;color:var(--muted)}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:18px}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 16px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:11px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-empty{padding:32px 20px;text-align:center;color:var(--muted);font-size:13px}
.ach-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ach-modal-bg.open{display:flex}
.ach-modal{background:white;border-radius:16px;padding:26px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ach-modal h3{margin:0 0 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.ach-modal .ach-fg{margin-bottom:14px}
.ach-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
.feb-pieces-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.feb-piece-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:#f8fafc;border:1px solid var(--border);border-radius:9px;font-size:13px}
.feb-piece-nom{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.feb-actions-bar{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap}
.feb-hint{font-size:12px;color:var(--muted);margin-top:10px}
@media (max-width:768px) {
  .ach-fg input, .ach-fg select, .ach-fg textarea, .btn { min-height:44px; }
}
</style>

<div style="margin-bottom:18px">
  <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:900;color:var(--navy)"><?= $feb ? 'Modifier la FEB' : 'Nouvelle FEB' ?></div>
  <div style="font-size:13px;color:var(--muted);margin-top:2px">Fiche d'Expression de Besoin — <?= $feb ? h($feb['objet']) : 'brouillon' ?></div>
</div>

<div class="feb-hdr-card">
  <div class="feb-grid">
    <div class="ach-fg">
      <label for="fh-site">Site</label>
      <select id="fh-site" onchange="renderLignes()">
        <option value="">— Aucun —</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= $s['id'] ?>" <?= (string)$default_site_id === (string)$s['id'] ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ach-fg">
      <label for="fh-dept">Service</label>
      <select id="fh-dept" onchange="renderLignes()">
        <option value="">— Aucun —</option>
        <?php foreach ($departements as $d): ?>
          <option value="<?= $d['id'] ?>" <?= (string)$default_dept_id === (string)$d['id'] ? 'selected' : '' ?>><?= h($d['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ach-fg">
      <label for="fh-fonction">Fonction du demandeur</label>
      <input type="text" id="fh-fonction" value="<?= h($default_fonction) ?>">
    </div>
    <div class="ach-fg">
      <label for="fh-urgence">Urgence</label>
      <select id="fh-urgence">
        <?php foreach (ach_urgences() as $code => $lbl): ?>
          <option value="<?= $code ?>" <?= $default_urgence === $code ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ach-fg" style="grid-column:1 / -1">
      <label for="fh-objet">Objet</label>
      <input type="text" id="fh-objet" required value="<?= h($default_objet) ?>" placeholder="ex : Renouvellement consommables informatiques site Abidjan">
      <div class="ach-err" id="err-objet"></div>
    </div>
  </div>
  <div class="ach-err" id="err-entete" style="margin-top:10px"></div>
</div>

<div class="ach-table-wrap">
  <div style="padding:16px 16px 0">
    <div class="feb-lignes-hdr">
      <div>
        <div class="feb-lignes-ttl">Lignes</div>
        <div class="feb-lignes-count" id="feb-lignes-count">0 / <?= $max_lignes ?> lignes</div>
      </div>
      <button type="button" class="btn btn-primary" id="btn-add-ligne" onclick="febOpenLigneModal()">
        <i class="ph ph-plus" aria-hidden="true"></i> Ajouter une ligne
      </button>
    </div>
  </div>
  <div id="feb-lignes-empty" class="ach-empty">Aucune ligne pour l'instant.</div>
  <div id="feb-lignes-table-wrap" style="overflow-x:auto;display:none">
    <table class="ach-table">
      <thead><tr>
        <th>Désignation</th><th>Qté</th><th>Unité</th><th>Famille</th><th>Type</th><th>Code analytique (lot)</th><th>Actions</th>
      </tr></thead>
      <tbody id="feb-lignes-tbody"></tbody>
    </table>
  </div>
  <div class="ach-err" id="err-lignes" style="padding:0 16px 16px"></div>
</div>

<div class="feb-hdr-card">
  <div class="feb-lignes-ttl" style="margin-bottom:12px">Pièces jointes</div>
  <?php if (!$feb_id): ?>
    <div class="feb-hint">Enregistrez d'abord le brouillon pour pouvoir joindre des fichiers.</div>
  <?php else: ?>
    <ul class="feb-pieces-list" id="feb-pieces-list"></ul>
    <div style="margin-top:12px">
      <label for="fh-piece" style="position:absolute;left:-9999px">Déposer une pièce jointe</label>
      <input type="file" id="fh-piece" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="febUploadPiece()">
      <div class="feb-hint">PDF, JPG, PNG ou WEBP — 10 Mo maximum par fichier.</div>
    </div>
  <?php endif; ?>
</div>

<div class="feb-actions-bar">
  <a href="mes_feb.php" class="btn btn-secondary">Annuler</a>
  <button type="button" class="btn btn-secondary" onclick="febSave(false)">Enregistrer le brouillon</button>
  <button type="button" class="btn btn-primary" onclick="febSave(true)">Soumettre</button>
</div>

<!-- MODALE ligne -->
<div class="ach-modal-bg" id="ligne-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="ligne-modal-title">
    <h3 id="ligne-modal-title"><span id="ligne-modal-ttl-txt">Nouvelle ligne</span></h3>
    <input type="hidden" id="lg-index" value="">
    <div class="ach-fg">
      <label for="lg-designation">Désignation</label>
      <input type="text" id="lg-designation" required list="articles-dl" oninput="febArticleAutoFill()">
      <datalist id="articles-dl">
        <?php foreach ($articles as $a): ?>
          <option value="<?= h($a['libelle']) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <div class="ach-err" id="err-lg-designation"></div>
    </div>
    <div class="feb-grid">
      <div class="ach-fg">
        <label for="lg-quantite">Quantité</label>
        <input type="number" id="lg-quantite" min="1" step="1" value="1" required>
        <div class="ach-err" id="err-lg-quantite"></div>
      </div>
      <div class="ach-fg">
        <label for="lg-unite">Unité</label>
        <input type="text" id="lg-unite" placeholder="ex : unité, boîte, paquet">
      </div>
      <div class="ach-fg">
        <label for="lg-famille">Famille</label>
        <select id="lg-famille" onchange="febMajCodePreview()">
          <option value="">— Sélectionner —</option>
          <?php foreach ($familles as $f): ?>
            <option value="<?= $f['id'] ?>"><?= h($f['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ach-fg">
        <label for="lg-type">Type d'achat</label>
        <select id="lg-type">
          <option value="">— Sélectionner —</option>
          <?php foreach ($types_achat as $t): ?>
            <option value="<?= h($t['code']) ?>"><?= h($t['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ach-fg" style="grid-column:1 / -1">
        <label>Code analytique (composé automatiquement — RG-05)</label>
        <div id="lg-code-preview" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:monospace;font-weight:700;color:var(--navy);background:#f8fafc">—</div>
      </div>
    </div>
    <div class="ach-err" id="err-ligne-modal"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="febCloseLigneModal()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="febSaveLigne()">Enregistrer la ligne</button>
    </div>
  </div>
</div>

<script>
const FEB_ID       = <?= $feb_id ? (int)$feb_id : 'null' ?>;
const MAX_LIGNES   = <?= $max_lignes ?>;
const ARTICLES     = <?= json_encode($articles, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let lignes = <?= json_encode(array_map(fn($l) => [
    'designation'     => $l['designation'],
    'article_id'      => $l['article_id'],
    'quantite'        => (int)$l['quantite'],
    'unite'           => $l['unite'],
    'famille_id'      => $l['famille_id'],
    'type_achat'      => $l['type_achat'],
], $feb_lignes), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let pieces = <?= json_encode(array_map(fn($p) => [
    'id' => (int)$p['id'], 'nom_origine' => $p['nom_origine'], 'taille' => (int)$p['taille'],
    'url' => upload_url($p['fichier'], 'feb'),
], $feb_pieces), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// ── Composition du code analytique (RG-05) — même schéma que
// ach_code_analytique() côté serveur : SITE/SERVICE/FAMILLE, majuscules,
// sans espace. Purement un aperçu ; le serveur recompose et fait autorité.
const SITE_CODES = {}; <?php foreach ($sites as $s): ?>SITE_CODES[<?= $s['id'] ?>] = <?= json_encode($s['code']) ?>;<?php endforeach; ?>
const DEPT_CODES  = {}; <?php foreach ($departements as $d): ?>DEPT_CODES[<?= $d['id'] ?>] = <?= json_encode($d['code']) ?>;<?php endforeach; ?>
const FAM_CODES   = {}; <?php foreach ($familles as $f): ?>FAM_CODES[<?= $f['id'] ?>] = <?= json_encode($f['code']) ?>;<?php endforeach; ?>
function febComputeCode(familleId) {
  const siteId = document.getElementById('fh-site').value;
  const deptId = document.getElementById('fh-dept').value;
  if (!siteId || !deptId || !familleId) return null;
  const s = SITE_CODES[siteId], d = DEPT_CODES[deptId], f = FAM_CODES[familleId];
  if (!s || !d || !f) return null;
  const norm = x => String(x).toUpperCase().replace(/\s+/g, '');
  return `${norm(s)}/${norm(d)}/${norm(f)}`;
}
function febMajCodePreview() {
  const familleId = document.getElementById('lg-famille').value;
  const code = febComputeCode(familleId);
  document.getElementById('lg-code-preview').textContent = code || (familleId ? '— (site et service requis)' : '—');
}

function achPost(data, isForm) {
  let body;
  if (isForm) { body = data; }
  else { body = new FormData(); Object.entries(data).forEach(([k, v]) => body.append(k, v)); }
  return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).then(r => r.json());
}
function clearFieldErrors() {
  document.querySelectorAll('.ach-err').forEach(e => { e.style.display = 'none'; e.textContent = ''; });
}
function showFieldError(field, message) {
  const map = { objet: 'err-objet', lignes: 'err-lignes', entete: 'err-entete' };
  const id = map[field] || null;
  const el = id ? document.getElementById(id) : document.getElementById('err-lignes');
  if (el) { el.textContent = message; el.style.display = 'block'; }
  else { toast(message, 'danger'); }
}

// ── Rendu du tableau des lignes
function renderLignes() {
  const tbody = document.getElementById('feb-lignes-tbody');
  const empty = document.getElementById('feb-lignes-empty');
  const wrap  = document.getElementById('feb-lignes-table-wrap');
  document.getElementById('feb-lignes-count').textContent = `${lignes.length} / ${MAX_LIGNES} lignes`;
  if (!lignes.length) { empty.style.display = ''; wrap.style.display = 'none'; return; }
  empty.style.display = 'none'; wrap.style.display = '';
  const famMap  = {}; <?php foreach ($familles as $f): ?>famMap[<?= $f['id'] ?>] = <?= json_encode($f['libelle']) ?>;<?php endforeach; ?>
  const typeMap = {}; <?php foreach ($types_achat as $t): ?>typeMap[<?= json_encode($t['code']) ?>] = <?= json_encode($t['libelle']) ?>;<?php endforeach; ?>
  tbody.innerHTML = lignes.map((l, i) => `
    <tr>
      <td>${esc(l.designation)}</td>
      <td>${l.quantite}</td>
      <td>${esc(l.unite || '—')}</td>
      <td>${esc(famMap[l.famille_id] || '—')}</td>
      <td>${esc(typeMap[l.type_achat] || '—')}</td>
      <td style="font-family:monospace">${esc(febComputeCode(l.famille_id) || '—')}</td>
      <td style="display:flex;gap:8px">
        <button type="button" class="btn btn-secondary btn-sm" aria-label="Modifier la ligne ${i+1}" onclick="febOpenLigneModal(${i})"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
        <button type="button" class="btn btn-secondary btn-sm" aria-label="Retirer la ligne ${i+1}" onclick="febRetirerLigne(${i})"><i class="ph ph-trash" aria-hidden="true"></i></button>
      </td>
    </tr>`).join('');
}
function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function febRetirerLigne(i) { lignes.splice(i, 1); renderLignes(); }

// ── Modale ligne
function febOpenLigneModal(index) {
  document.getElementById('err-lg-designation').style.display = 'none';
  document.getElementById('err-lg-quantite').style.display = 'none';
  document.getElementById('err-ligne-modal').style.display = 'none';
  if (index === undefined) {
    if (lignes.length >= MAX_LIGNES) { toast(`Plafond de ${MAX_LIGNES} lignes atteint (paramètre modifiable dans Achats → Paramètres généraux).`, 'danger'); return; }
    document.getElementById('lg-index').value = '';
    document.getElementById('ligne-modal-ttl-txt').textContent = 'Nouvelle ligne';
    document.getElementById('lg-designation').value = '';
    document.getElementById('lg-quantite').value = 1;
    document.getElementById('lg-unite').value = '';
    document.getElementById('lg-famille').value = '';
    document.getElementById('lg-type').value = '';
  } else {
    const l = lignes[index];
    document.getElementById('lg-index').value = index;
    document.getElementById('ligne-modal-ttl-txt').textContent = 'Modifier la ligne';
    document.getElementById('lg-designation').value = l.designation || '';
    document.getElementById('lg-quantite').value = l.quantite || 1;
    document.getElementById('lg-unite').value = l.unite || '';
    document.getElementById('lg-famille').value = l.famille_id || '';
    document.getElementById('lg-type').value = l.type_achat || '';
  }
  febMajCodePreview();
  document.getElementById('ligne-modal').classList.add('open');
  setTimeout(() => document.getElementById('lg-designation').focus(), 80);
}
function febCloseLigneModal() { document.getElementById('ligne-modal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') febCloseLigneModal(); });
document.getElementById('ligne-modal').addEventListener('click', e => { if (e.target === e.currentTarget) febCloseLigneModal(); });

function febArticleAutoFill() {
  const v = document.getElementById('lg-designation').value.trim().toLowerCase();
  const art = ARTICLES.find(a => a.libelle.toLowerCase() === v);
  if (art) document.getElementById('lg-unite').value = art.unite || '';
}

function febSaveLigne() {
  const designation = document.getElementById('lg-designation').value.trim();
  const quantite     = parseInt(document.getElementById('lg-quantite').value, 10);
  let ok = true;
  if (!designation) { document.getElementById('err-lg-designation').textContent = 'La désignation est obligatoire.'; document.getElementById('err-lg-designation').style.display = 'block'; ok = false; }
  if (!quantite || quantite < 1) { document.getElementById('err-lg-quantite').textContent = 'La quantité doit être strictement positive.'; document.getElementById('err-lg-quantite').style.display = 'block'; ok = false; }
  if (!ok) return;

  const familleId = document.getElementById('lg-famille').value || null;
  const idxStr = document.getElementById('lg-index').value;
  const idx = idxStr === '' ? null : parseInt(idxStr, 10);

  // RG-02 : trois codes analytiques au maximum par FEB. Site et service
  // étant constants sur toute la FEB, la limite revient en pratique à trois
  // familles distinctes (cf. ach_creer_feb(), qui refait ce contrôle côté
  // serveur — celui-ci n'est qu'un retour immédiat à l'utilisateur).
  if (familleId) {
    const distinctes = new Set(lignes.map((l, i) => (idx !== null && i === idx) ? null : l.famille_id).filter(Boolean));
    if (!distinctes.has(familleId) && distinctes.size >= 3) {
      document.getElementById('err-ligne-modal').textContent = 'Trois familles distinctes au maximum par FEB (donc trois codes analytiques) — créez une seconde FEB pour la suite.';
      document.getElementById('err-ligne-modal').style.display = 'block';
      return;
    }
  }

  const art = ARTICLES.find(a => a.libelle.toLowerCase() === designation.toLowerCase());
  const ligne = {
    designation, quantite,
    unite: document.getElementById('lg-unite').value.trim(),
    famille_id: familleId,
    type_achat: document.getElementById('lg-type').value || null,
    article_id: art ? art.id : null,
  };
  if (idx === null) lignes.push(ligne); else lignes[idx] = ligne;
  renderLignes();
  febCloseLigneModal();
}

// ── Pièces jointes
function renderPieces() {
  const list = document.getElementById('feb-pieces-list');
  if (!list) return;
  list.innerHTML = pieces.map(p => `
    <li class="feb-piece-row">
      <i class="ph ph-paperclip" aria-hidden="true"></i>
      <a class="feb-piece-nom" href="${p.url}" target="_blank">${esc(p.nom_origine)}</a>
      <button type="button" class="btn btn-secondary btn-sm" aria-label="Supprimer ${esc(p.nom_origine)}" onclick="febDeletePiece(${p.id})"><i class="ph ph-trash" aria-hidden="true"></i></button>
    </li>`).join('');
}
function febUploadPiece() {
  const input = document.getElementById('fh-piece');
  if (!input.files.length || !FEB_ID) return;
  const fd = new FormData();
  fd.append('action', 'upload_piece');
  fd.append('feb_id', FEB_ID);
  fd.append('fichier', input.files[0]);
  achPost(fd, true).then(res => {
    input.value = '';
    if (!res.success) { toast(res.message, 'danger'); return; }
    pieces.push(res.data);
    renderPieces();
    toast('Fichier déposé.', 'success');
  });
}
function febDeletePiece(id) {
  if (!confirm('Supprimer cette pièce jointe ?')) return;
  achPost({ action: 'delete_piece', feb_id: FEB_ID, piece_id: id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) { pieces = pieces.filter(p => p.id !== id); renderPieces(); }
  });
}

// ── Enregistrement (brouillon ou soumission)
function febSave(soumettre) {
  clearFieldErrors();
  const objet = document.getElementById('fh-objet').value.trim();
  if (!objet) { showFieldError('objet', "L'objet est obligatoire."); return; }
  if (soumettre && lignes.length === 0) { showFieldError('lignes', 'Ajoutez au moins une ligne avant de soumettre.'); return; }

  achPost({
    action: 'save',
    feb_id: FEB_ID || '',
    site_id: document.getElementById('fh-site').value,
    departement_id: document.getElementById('fh-dept').value,
    fonction: document.getElementById('fh-fonction').value.trim(),
    urgence: document.getElementById('fh-urgence').value,
    objet,
    lignes: JSON.stringify(lignes),
    soumettre: soumettre ? '1' : '0',
  }).then(res => {
    if (!res.success) {
      showFieldError(res.data && res.data.field, res.message);
      return;
    }
    toast(res.message, 'success');
    setTimeout(() => {
      window.location.href = soumettre ? 'mes_feb.php' : ('feb_fiche.php?id=' + res.data.id);
    }, 500);
  });
}

renderLignes();
renderPieces();
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
