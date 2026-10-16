<?php
// ============================================================
//  pages/achats/file_attente.php — File d'attente Achats
//  Prise en charge exclusive des FEB soumises.
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/achats.php';

require_auth();
$user = current_user();
require_permission('achats', 'can_update');
// Étapes 2 à 4 du processus : réservées au service Achats. Le droit
// can_update sur « achats » ne suffit pas à distinguer un acheteur d'un
// valideur (RAF/DAF/PDG l'ont aussi, pour mes_visas.php).
ach_require_acheteur($user);
$uid      = (int)$user['id'];
$is_admin = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
$_SESSION['groupe_actif'] = 'ACHATS';
$page_title  = "File d'attente Achats";
$active_page = 'achats_file_attente';

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'prendre_en_charge') {
        $feb_id = (int)($_POST['feb_id'] ?? 0);
        if (ach_prendre_en_charge($feb_id, $user)) {
            json_response(true, 'FEB prise en charge.');
        }
        // Zéro ligne affectée = quelqu'un a été plus rapide — message
        // explicite au perdant, pas une erreur technique (Bloc 2, point 12).
        $courante = db_fetch_one(
            "SELECT f.statut, CONCAT(u.prenom,' ',u.nom) AS acheteur_nom
             FROM feb f LEFT JOIN users u ON u.id = f.acheteur_id WHERE f.id=?",
            [$feb_id]
        );
        if ($courante && $courante['acheteur_nom'] && trim($courante['acheteur_nom']) !== '') {
            json_response(false, "Cette FEB vient d'être prise en charge par {$courante['acheteur_nom']}.");
        }
        json_response(false, "Cette FEB n'est plus disponible pour une prise en charge.");
    }

    if ($action === 'restituer') {
        $feb_id = (int)($_POST['feb_id'] ?? 0);
        if (ach_restituer_feb($feb_id, $user)) {
            json_response(true, 'FEB restituée à la file d\'attente.');
        }
        json_response(false, 'Restitution impossible — vérifiez que vous détenez toujours cette FEB.');
    }

    if ($action === 'reattribuer') {
        if (!$is_admin) json_response(false, 'Réservé à un administrateur.');
        $feb_id    = (int)($_POST['feb_id'] ?? 0);
        $nouvel_id = (int)($_POST['nouvel_acheteur_id'] ?? 0);
        if (!$nouvel_id) json_response(false, 'Choisissez un acheteur.');
        if (ach_reattribuer_feb($feb_id, $nouvel_id, $user)) {
            json_response(true, 'FEB réattribuée.');
        }
        json_response(false, 'Réattribution impossible — cette FEB n\'est plus en cours de traitement.');
    }

    // ── Réouverture administrative d'une FEB bloquée en validation (Bloc 5,
    //    RG-12) : les offres/montants sont verrouillés dès en_validation
    //    (feb_traitement.php) — c'est l'unique porte de sortie, tracée.
    if ($action === 'reouvrir_admin') {
        if (!$is_admin) json_response(false, 'Réservé à un administrateur.');
        $feb_id = (int)($_POST['feb_id'] ?? 0);
        if (ach_admin_reouvrir_validation($feb_id, $user)) {
            json_response(true, "FEB rouverte — signatures annulées, l'acheteur doit relancer la validation après correction.");
        }
        json_response(false, "Réouverture impossible — cette FEB n'est plus en cours de validation.");
    }

    // ── Consultation sans engagement : lecture seule, n'importe quelle FEB
    //    visible de la file (Bloc 1, point 8 — regarder ne doit pas engager).
    if ($action === 'get_feb_detail') {
        $feb_id = (int)($_POST['feb_id'] ?? 0);
        $feb = db_fetch_one(
            "SELECT f.*, CONCAT(u.prenom,' ',u.nom) AS demandeur_nom, s.nom AS site_nom,
                    CONCAT(a.prenom,' ',a.nom) AS acheteur_nom
             FROM feb f
             LEFT JOIN users u ON u.id = f.demandeur_id
             LEFT JOIN sites s ON s.id = f.site_id
             LEFT JOIN users a ON a.id = f.acheteur_id
             WHERE f.id=? AND f.statut IN ('soumise','prise_en_charge','en_validation')",
            [$feb_id]
        );
        if (!$feb) json_response(false, 'FEB introuvable.');
        $lignes = db_fetch_all(
            "SELECT numero_ligne, designation, quantite, unite FROM feb_lignes WHERE feb_id=? ORDER BY numero_ligne",
            [$feb_id]
        );
        json_response(true, '', ['feb' => $feb, 'lignes' => $lignes]);
    }

    json_response(false, 'Action inconnue.');
}

// ── PAGE PHP ─────────────────────────────────────────────────
$f_site      = (int)($_GET['site'] ?? 0);
$f_urgence   = $_GET['urgence'] ?? '';
$f_demandeur = (int)($_GET['demandeur'] ?? 0);

$where  = [];
$params = [];
if ($f_site)      { $where[] = 'f.site_id = ?';      $params[] = $f_site; }
if ($f_urgence !== '' && array_key_exists((int)$f_urgence, ach_urgences())) {
    $where[] = 'f.urgence = ?'; $params[] = (int)$f_urgence;
}
if ($f_demandeur) { $where[] = 'f.demandeur_id = ?'; $params[] = $f_demandeur; }
$whereSql = $where ? (' AND ' . implode(' AND ', $where)) : '';

$select_base = "SELECT f.id, f.numero, f.objet, f.urgence, f.date_soumission, f.acheteur_id,
        CONCAT(u.prenom,' ',u.nom) AS demandeur_nom, s.nom AS site_nom,
        (SELECT COUNT(*) FROM feb_lignes fl WHERE fl.feb_id = f.id) AS nb_lignes";

// Section 1 — à prendre en charge
$a_prendre = db_fetch_all(
    "$select_base
     FROM feb f
     LEFT JOIN users u ON u.id = f.demandeur_id
     LEFT JOIN sites s ON s.id = f.site_id
     WHERE f.statut='soumise' AND f.acheteur_id IS NULL $whereSql
     ORDER BY f.urgence DESC, f.date_soumission ASC",
    $params
);

// Section 2 — prises en charge par d'autres (avec le nom du preneur)
$prises_autres = db_fetch_all(
    "$select_base, CONCAT(a.prenom,' ',a.nom) AS acheteur_nom
     FROM feb f
     LEFT JOIN users u ON u.id = f.demandeur_id
     LEFT JOIN sites s ON s.id = f.site_id
     LEFT JOIN users a ON a.id = f.acheteur_id
     WHERE f.statut='prise_en_charge' AND f.acheteur_id IS NOT NULL AND f.acheteur_id != ? $whereSql
     ORDER BY f.urgence DESC, f.date_soumission ASC",
    array_merge([$uid], $params)
);

// Section 3 — les miennes
$mes_feb = db_fetch_all(
    "$select_base
     FROM feb f
     LEFT JOIN users u ON u.id = f.demandeur_id
     LEFT JOIN sites s ON s.id = f.site_id
     WHERE f.statut='prise_en_charge' AND f.acheteur_id = ? $whereSql
     ORDER BY f.urgence DESC, f.date_soumission ASC",
    array_merge([$uid], $params)
);

// Section 4 — en circuit de validation, réservée à l'administration : la
// seule action possible ici est la réouverture d'urgence (Bloc 5, RG-12).
$en_validation = $is_admin ? db_fetch_all(
    "$select_base, f.date_lancement_validation
     FROM feb f
     LEFT JOIN users u ON u.id = f.demandeur_id
     LEFT JOIN sites s ON s.id = f.site_id
     WHERE f.statut='en_validation' $whereSql
     ORDER BY f.date_lancement_validation ASC",
    $params
) : [];

// ── Compteur en tête — global, non filtré : c'est ce chiffre qui alimente
//    « À traiter aujourd'hui » sur le tableau de bord (hors de ce lot).
$total_attente = (int) db_fetch_value("SELECT COUNT(*) FROM feb WHERE statut='soumise' AND acheteur_id IS NULL");
$plus_ancienne = db_fetch_value(
    "SELECT date_soumission FROM feb WHERE statut='soumise' AND acheteur_id IS NULL ORDER BY date_soumission ASC LIMIT 1"
);
$anciennete_max = $plus_ancienne ? ach_anciennete_heures_ouvrees($plus_ancienne) : 0.0;

// Piège classique : foreach ([$a, $b, $c] as &$x) itère sur le tableau
// littéral temporaire créé par [...], pas sur $a/$b/$c eux-mêmes — la
// référence ne remonte jamais aux variables d'origine. D'où trois boucles
// séparées, chacune sur la vraie variable (J10, recette au clic — le
// warning "Undefined array key anciennete_h" s'affichait sur les 3
// sections concernées).
foreach ($a_prendre as &$f) {
    $f['anciennete_h'] = ach_anciennete_heures_ouvrees($f['date_soumission']);
}
unset($f);
foreach ($prises_autres as &$f) {
    $f['anciennete_h'] = ach_anciennete_heures_ouvrees($f['date_soumission']);
}
unset($f);
foreach ($mes_feb as &$f) {
    $f['anciennete_h'] = ach_anciennete_heures_ouvrees($f['date_soumission']);
}
unset($f);
foreach ($en_validation as &$f) {
    $f['anciennete_h'] = ach_anciennete_heures_ouvrees($f['date_lancement_validation'] ?? $f['date_soumission']);
}
unset($f);

$sites_list  = db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");
$demandeurs  = db_fetch_all(
    "SELECT DISTINCT u.id, CONCAT(u.prenom,' ',u.nom) AS nom
     FROM feb f JOIN users u ON u.id = f.demandeur_id
     WHERE f.statut IN ('soumise','prise_en_charge') ORDER BY nom"
);
$acheteurs_possibles = $is_admin ? db_fetch_all(
    "SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS nom FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE r.slug IN ('superviseur_achat','admin','superadmin') AND u.actif=1
     ORDER BY nom"
) : [];

$qs = [];
if ($f_site)      $qs[] = 'site=' . $f_site;
if ($f_urgence !== '') $qs[] = 'urgence=' . urlencode($f_urgence);
if ($f_demandeur) $qs[] = 'demandeur=' . $f_demandeur;
$qsSuffix = $qs ? ('?' . implode('&', $qs)) : '';
$fragmentUrl = APP_URL . '/pages/achats/file_attente.php?' . implode('&', array_merge($qs, ['fragment=1']));

// ── Rafraîchissement live — fragment HTML seul (KPI + les 4 sections),
//    sans le chrome de page (header/footer/toolbar/modales), pour un
//    remplacement périodique en arrière-plan (cf. liveRefresh() dans
//    templates/footer.php). Les filtres de l'URL s'appliquent normalement,
//    la fonction de rendu est définie plus bas mais PHP compile toutes les
//    déclarations de fonction top-level avant d'exécuter le script — donc
//    disponible ici malgré l'ordre d'apparition dans le fichier.
if (is_ajax() && ($_GET['fragment'] ?? '') === '1') {
    ach_fa_render_zone($total_attente, $plus_ancienne, $anciennete_max, $a_prendre, $prises_autres, $mes_feb, $en_validation, $is_admin);
    exit;
}

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ach-kpi{background:linear-gradient(135deg,#B45309 0%,#F59E0B 100%);border-radius:16px;padding:18px 22px;color:white;margin-bottom:18px;display:flex;gap:28px;flex-wrap:wrap;align-items:center}
.ach-kpi-item{display:flex;flex-direction:column}
.ach-kpi-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;line-height:1.1}
.ach-kpi-lbl{font-size:12px;opacity:.9;margin-top:2px}
.ach-toolbar{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}
.ach-fg{margin:0}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg select{padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;box-sizing:border-box;min-width:180px}
.ach-section{margin-bottom:26px}
.ach-section-hdr{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.ach-section-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy)}
.ach-section-count{font-size:12px;color:var(--muted);background:#f1f5f9;padding:2px 10px;border-radius:20px;font-weight:700}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 14px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-empty{padding:26px 20px;text-align:center;color:var(--muted);font-size:13px}
.ach-urg{font-size:12px;font-weight:700}
.ach-actions{display:flex;gap:8px;flex-wrap:wrap}
.ach-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ach-modal-bg.open{display:flex}
.ach-modal{background:white;border-radius:16px;padding:26px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ach-modal h3{margin:0 0 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.ach-modal .ach-fg{margin-bottom:14px}
.ach-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
.ach-consult-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:16px}
.ach-consult-lbl{font-size:11.5px;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.4px;margin-bottom:2px}
.ach-consult-val{font-size:13.5px;font-weight:700;color:var(--navy)}
@media (max-width:768px) {
  .ach-fg select, .btn { min-height:44px; }
  .ach-consult-grid { grid-template-columns:minmax(0,1fr); }
}
</style>

<form class="ach-toolbar" method="GET">
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
    <label for="f-urgence">Urgence</label>
    <select id="f-urgence" name="urgence">
      <option value="">Toutes</option>
      <?php foreach (ach_urgences() as $code => $lbl): ?>
        <option value="<?= $code ?>" <?= (string)$f_urgence === (string)$code ? 'selected' : '' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="ach-fg">
    <label for="f-demandeur">Demandeur</label>
    <select id="f-demandeur" name="demandeur">
      <option value="0">Tous</option>
      <?php foreach ($demandeurs as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $f_demandeur === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-secondary">Filtrer</button>
</form>

<?php
// ── Rendu d'une section (fonction locale, pas de duplication entre les 3)
function ach_fa_render_section(string $titre, array $feb_list, string $mode, bool $is_admin): void {
    ?>
    <div class="ach-section">
      <div class="ach-section-hdr">
        <span class="ach-section-ttl"><?= h($titre) ?></span>
        <span class="ach-section-count"><?= count($feb_list) ?></span>
      </div>
      <div class="ach-table-wrap">
        <?php if (empty($feb_list)): ?>
          <div class="ach-empty">Aucune FEB dans cette section.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table data-paginer="25" class="ach-table">
          <thead><tr>
            <th>Numéro</th><th>Objet</th><th>Demandeur</th><th>Site</th>
            <th>Urgence</th><th>Lignes</th><th>Ancienneté</th>
            <?php if ($mode === 'autres'): ?><th>Pris par</th><?php endif; ?>
            <th>Actions</th>
          </tr></thead>
          <tbody>
            <?php foreach ($feb_list as $f):
              $u = ach_urgences()[(int)$f['urgence']] ?? 'Normale';
              $urg_color = (int)$f['urgence'] >= 2 ? '#991B1B' : ((int)$f['urgence'] === 1 ? '#92400E' : 'var(--muted)');
            ?>
            <tr>
              <td style="font-weight:700;color:var(--navy)"><?= h($f['numero'] ?: '—') ?></td>
              <td><?= h($f['objet']) ?></td>
              <td><?= h($f['demandeur_nom'] ?: '—') ?></td>
              <td><?= h($f['site_nom'] ?: '—') ?></td>
              <td class="ach-urg" style="color:<?= $urg_color ?>"><?= h($u) ?></td>
              <td><?= (int)$f['nb_lignes'] ?></td>
              <td><?= fmt_number((float)$f['anciennete_h'], 1) ?> h</td>
              <?php if ($mode === 'autres'): ?><td><?= h($f['acheteur_nom'] ?: '—') ?></td><?php endif; ?>
              <td class="ach-actions">
                <button type="button" class="btn btn-secondary btn-sm" onclick="faConsulter(<?= $f['id'] ?>)">Voir</button>
                <?php if ($mode === 'a_prendre'): ?>
                  <button type="button" class="btn btn-primary btn-sm" onclick="faPrendre(<?= $f['id'] ?>)">Prendre en charge</button>
                <?php elseif ($mode === 'mine'): ?>
                  <a href="feb_traitement.php?id=<?= $f['id'] ?>" class="btn btn-primary btn-sm">Traiter</a>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="faRestituer(<?= $f['id'] ?>)">Restituer</button>
                <?php elseif ($mode === 'autres' && $is_admin): ?>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="faReattribuerOuvrir(<?= $f['id'] ?>)">Réattribuer</button>
                <?php elseif ($mode === 'en_validation'): ?>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="faReouvrir(<?= $f['id'] ?>)">Réouvrir</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

// ── KPI + les 4 sections, tout ce que le rafraîchissement live doit
//    reproduire à l'identique (cf. l'appel en fragment plus haut dans le
//    fichier). Un seul point de vérité pour les deux usages.
function ach_fa_render_zone(int $total_attente, ?string $plus_ancienne, float $anciennete_max, array $a_prendre, array $prises_autres, array $mes_feb, array $en_validation, bool $is_admin): void {
    ?>
    <div class="ach-kpi">
      <div class="ach-kpi-item">
        <div class="ach-kpi-val"><?= $total_attente ?></div>
        <div class="ach-kpi-lbl">FEB en attente de prise en charge</div>
      </div>
      <div class="ach-kpi-item">
        <div class="ach-kpi-val"><?= $plus_ancienne ? fmt_number($anciennete_max, 1) . ' h' : '—' ?></div>
        <div class="ach-kpi-lbl">Ancienneté de la plus ancienne (heures ouvrées)</div>
      </div>
    </div>
    <?php
    ach_fa_render_section('À prendre en charge', $a_prendre, 'a_prendre', $is_admin);
    ach_fa_render_section('Prises en charge', $prises_autres, 'autres', $is_admin);
    ach_fa_render_section('Les miennes', $mes_feb, 'mine', $is_admin);
    if ($is_admin) {
        ach_fa_render_section('En circuit de validation (administration)', $en_validation, 'en_validation', $is_admin);
    }
}
?>

<div id="fa-live-zone">
<?php ach_fa_render_zone($total_attente, $plus_ancienne, $anciennete_max, $a_prendre, $prises_autres, $mes_feb, $en_validation, $is_admin); ?>
</div>

<!-- MODALE consultation (lecture seule) -->
<div class="ach-modal-bg" id="consult-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="consult-modal-title">
    <h3 id="consult-modal-title">Consultation de la FEB</h3>
    <div class="ach-consult-grid">
      <div><div class="ach-consult-lbl">Numéro</div><div class="ach-consult-val" id="cs-numero"></div></div>
      <div><div class="ach-consult-lbl">Statut</div><div class="ach-consult-val" id="cs-statut"></div></div>
      <div><div class="ach-consult-lbl">Demandeur</div><div class="ach-consult-val" id="cs-demandeur"></div></div>
      <div><div class="ach-consult-lbl">Site</div><div class="ach-consult-val" id="cs-site"></div></div>
      <div><div class="ach-consult-lbl">Urgence</div><div class="ach-consult-val" id="cs-urgence"></div></div>
      <div><div class="ach-consult-lbl">Acheteur</div><div class="ach-consult-val" id="cs-acheteur"></div></div>
      <div style="grid-column:1/-1"><div class="ach-consult-lbl">Objet</div><div class="ach-consult-val" id="cs-objet"></div></div>
    </div>
    <div class="ach-table-wrap">
      <table class="ach-table">
        <thead><tr><th>#</th><th>Désignation</th><th>Qté</th><th>Unité</th></tr></thead>
        <tbody id="cs-lignes"></tbody>
      </table>
    </div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="faFermerModal('consult-modal')">Fermer</button>
    </div>
  </div>
</div>

<!-- MODALE réattribution (admin) -->
<div class="ach-modal-bg" id="reattr-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="reattr-modal-title">
    <h3 id="reattr-modal-title">Réattribuer la FEB</h3>
    <input type="hidden" id="reattr-feb-id" value="">
    <div class="ach-fg">
      <label for="reattr-acheteur">Nouvel acheteur</label>
      <select id="reattr-acheteur" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box">
        <option value="">— Sélectionner —</option>
        <?php foreach ($acheteurs_possibles as $a): ?>
          <option value="<?= $a['id'] ?>"><?= h($a['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="faFermerModal('reattr-modal')">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="faReattribuerConfirmer()">Réattribuer</button>
    </div>
  </div>
</div>

<script>
const URGENCE_LABELS = <?= json_encode(ach_urgences()) ?>;
const STATUT_LABELS  = <?= json_encode(array_map(fn($s) => $s['label'], ach_statuts_feb())) ?>;

function faPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
}
function faFermerModal(id) { document.getElementById(id).classList.remove('open'); }

function faPrendre(id) {
  faPost({ action: 'prendre_en_charge', feb_id: id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
    else setTimeout(() => location.reload(), 1200); // rafraîchit la file même en cas de refus
  });
}
function faRestituer(id) {
  if (!confirm('Restituer cette FEB à la file d\'attente ?')) return;
  faPost({ action: 'restituer', feb_id: id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function faReattribuerOuvrir(id) {
  document.getElementById('reattr-feb-id').value = id;
  document.getElementById('reattr-acheteur').value = '';
  document.getElementById('reattr-modal').classList.add('open');
}
function faReattribuerConfirmer() {
  const feb_id = document.getElementById('reattr-feb-id').value;
  const nouvel_acheteur_id = document.getElementById('reattr-acheteur').value;
  if (!nouvel_acheteur_id) { toast('Choisissez un acheteur.', 'danger'); return; }
  faPost({ action: 'reattribuer', feb_id, nouvel_acheteur_id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) { faFermerModal('reattr-modal'); setTimeout(() => location.reload(), 500); }
  });
}
function faReouvrir(id) {
  if (!confirm("Réouvrir cette FEB ? Les signatures obtenues seront annulées et le circuit devra être relancé après correction.")) return;
  faPost({ action: 'reouvrir_admin', feb_id: id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function faConsulter(id) {
  faPost({ action: 'get_feb_detail', feb_id: id }).then(res => {
    if (!res.success) { toast(res.message, 'danger'); return; }
    const f = res.data.feb;
    document.getElementById('cs-numero').textContent    = f.numero || '— (brouillon)';
    document.getElementById('cs-statut').textContent     = STATUT_LABELS[f.statut] || f.statut;
    document.getElementById('cs-demandeur').textContent  = f.demandeur_nom || '—';
    document.getElementById('cs-site').textContent       = f.site_nom || '—';
    document.getElementById('cs-urgence').textContent    = URGENCE_LABELS[f.urgence] || 'Normale';
    document.getElementById('cs-acheteur').textContent   = f.acheteur_nom || '—';
    document.getElementById('cs-objet').textContent      = f.objet || '—';
    const esc = s => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    document.getElementById('cs-lignes').innerHTML = (res.data.lignes || []).map(l =>
      `<tr><td>${l.numero_ligne}</td><td>${esc(l.designation)}</td><td>${l.quantite}</td><td>${esc(l.unite || '—')}</td></tr>`
    ).join('') || '<tr><td colspan="4" style="text-align:center;padding:16px;color:var(--muted)">Aucune ligne.</td></tr>';
    document.getElementById('consult-modal').classList.add('open');
  });
}
document.querySelectorAll('.ach-modal-bg').forEach(m => {
  m.addEventListener('click', e => { if (e.target === e.currentTarget) m.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.ach-modal-bg.open').forEach(m => m.classList.remove('open'));
});

// Pilote du rafraîchissement live (liveRefresh() vit dans templates/footer.php,
// chargé après ce bloc — DOMContentLoaded garantit qu'il est bien défini).
document.addEventListener('DOMContentLoaded', () => {
  liveRefresh({ url: <?= json_encode($fragmentUrl) ?>, container: '#fa-live-zone', interval: 10000 });
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
