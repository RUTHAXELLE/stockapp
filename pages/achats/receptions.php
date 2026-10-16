<?php
// ============================================================
//  pages/achats/receptions.php — Circuit de réception en 3 étapes
//  1. Réception magasin (fournisseur -> magasin) — service Achats.
//  2. Expédition vers le département (magasin -> département) — service Achats.
//  3. Réception département (confirmation physique) — N+1 du département.
//  Décision du 2026-08-23 : le crédit stock_departement n'a lieu qu'à
//  l'étape 3, plus à la réception magasin — cf. includes/achats.php,
//  ach_receptionner()/ach_expedier_departement()/ach_receptionner_departement().
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

// Étapes 1 et 2 : même profil (achats_suivi.can_create), décision du
// 2026-08-23 — ce n'est plus le N+1, qui n'intervient qu'à l'étape 3.
$can_magasin = can('achats_suivi', 'can_create');

$is_admin        = in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true);
$departements_n1 = ach_departements_n1($uid);
// Étape 3 : action réservée au N+1 du département destinataire — pas au
// service Achats, même s'il porte achats_suivi.can_read. Signalé sur la
// recette (2026-08-23) : achat@recette.local est aussi N+1 du département
// ACHAT (pour endosser ses propres FEB, cf. Étape 1) et se voyait de ce
// seul fait autorisé à confirmer la réception de N'IMPORTE QUEL
// département — $can_dept_reception ne doit donc jamais servir à décider
// qui voit le bouton, seulement qui est autorisé à ouvrir l'écran.
// ach_departements_n1() renvoie SES départements ; le bouton n'apparaît
// plus bas que ligne par ligne, quand departement_id de la ligne y figure.
$can_dept_reception = $is_admin || !empty($departements_n1);
// Étape 3, visibilité seule (statut, pas d'action) : le service Achats la
// voit pour suivre où en est chaque expédition, sans pouvoir la confirmer
// à la place du département destinataire.
$peut_voir_etape3 = can('achats_suivi', 'can_read') || $can_dept_reception;

if (!can('achats_suivi', 'can_read') && !$can_dept_reception) {
    http_response_code(403);
    include __DIR__ . '/../../templates/403.php';
    exit;
}
$_SESSION['groupe_actif'] = 'ACHATS';
$page_title  = 'Réceptions';
$active_page = 'achats_receptions';

// Filtré sur le site de l'utilisateur, pas sur toute la FEB (point 3) — ne
// s'applique qu'au coordinateur de site ; le magasin (gestionnaire_stock)
// et l'administration voient toutes les réceptions à traiter.
$role_slug  = $user['role_slug'] ?? '';
$site_force = ($role_slug === 'coordinateur_site' && $user['site_id']) ? (int)$user['site_id'] : 0;

// Étape 3 seule : un N+1 sans lecture globale (achats_suivi.can_read) ne
// voit que les lignes des FEB de son (ses) département(s).
$departements_n1_force = (!can('achats_suivi', 'can_read') && !$is_admin) ? $departements_n1 : [];

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Filet de sécurité : une erreur inattendue (SQL, colonne manquante après
    // une migration oubliée, etc.) plantait ce script en silence — display_
    // errors est désactivé en production (cf. Dockerfile), la réponse HTTP
    // revenait donc vide, indiscernable d'un bouton qui ne répond pas.
    // Repéré en recette (2026-08-25) sur equipements_attente.php, même
    // filet posé ici par précaution. Les erreurs métier (AchValidationException)
    // restent gérées au plus près, ce filet n'attrape que ce qu'elles ne couvrent pas.
    try {

    if ($action === 'lier_nomenclature') {
        if (!$can_magasin) json_response(false, 'Action réservée.');
        $feb_ligne_id    = (int)($_POST['feb_ligne_id'] ?? 0);
        $nomenclature_id = (int)($_POST['nomenclature_id'] ?? 0) ?: null;
        try {
            ach_lier_nomenclature_ligne($feb_ligne_id, $nomenclature_id, $user);
            json_response(true, $nomenclature_id ? 'Nomenclature rattachée.' : 'Rattachement retiré.');
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    if ($action === 'receptionner') {
        if (!$can_magasin) json_response(false, 'Action réservée.');
        $suivi_id    = (int)($_POST['suivi_id'] ?? 0);
        $quantite    = (int)($_POST['quantite'] ?? 0);
        $date        = trim($_POST['date_reception'] ?? '') ?: date('Y-m-d');
        $observation = trim($_POST['observation'] ?? '');

        // Un coordinateur ne peut réceptionner que pour son propre site —
        // même filtre que la liste, revérifié côté serveur.
        if ($site_force) {
            $site_ligne = (int) db_fetch_value("SELECT site_id FROM feb_suivi WHERE id=?", [$suivi_id]);
            if ($site_ligne !== $site_force) json_response(false, "Cette ligne n'appartient pas à votre site.");
        }

        if (empty($_FILES['bl']['name'])) json_response(false, 'Le bon de livraison est obligatoire.');
        $up = upload_document('bl', 'bl', 'bl_suivi_' . $suivi_id, false);
        if (!$up['success']) json_response(false, $up['message']);
        $bl_filename = $up['filename'];

        try {
            $res = ach_receptionner($suivi_id, $quantite, $date, $bl_filename, $observation, $user);
            $msg = $res['solde']
                ? "Réception magasin enregistrée — ligne soldée (cumul {$res['cumul']})."
                : "Réception magasin enregistrée — cumul {$res['cumul']}, reste {$res['ecart']}.";
            json_response(true, $msg, $res);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    if ($action === 'expedier') {
        if (!$can_magasin) json_response(false, 'Action réservée.');
        $suivi_id    = (int)($_POST['suivi_id'] ?? 0);
        $quantite    = (int)($_POST['quantite'] ?? 0);
        $date        = trim($_POST['date_expedition'] ?? '') ?: date('Y-m-d');
        $observation = trim($_POST['observation'] ?? '');
        try {
            $res = ach_expedier_departement($suivi_id, $quantite, $date, $observation, $user);
            json_response(true, "Expédition enregistrée — {$res['cumul_expediee']} unité(s) expédiée(s) au total.", $res);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    if ($action === 'receptionner_departement') {
        if (!$can_dept_reception) json_response(false, 'Action réservée.');
        $suivi_id    = (int)($_POST['suivi_id'] ?? 0);
        $quantite    = (int)($_POST['quantite'] ?? 0);
        $date        = trim($_POST['date_reception'] ?? '') ?: date('Y-m-d');
        $observation = trim($_POST['observation'] ?? '');

        if ($departements_n1_force) {
            $dept_ligne = (int) db_fetch_value(
                "SELECT f.departement_id FROM feb_suivi fs JOIN feb f ON f.id=fs.feb_id WHERE fs.id=?", [$suivi_id]
            );
            if (!in_array($dept_ligne, $departements_n1_force, true)) json_response(false, "Cette ligne n'appartient pas à votre département.");
        }

        // Bon de transfert obligatoire — symétrique du bon de livraison de
        // la réception magasin (même exigence : une pièce, pas seulement
        // une déclaration). Stocké dans le même dossier bl/, préfixe
        // distinct pour ne pas se confondre avec le BL fournisseur.
        if (empty($_FILES['bt']['name'])) json_response(false, 'Le bon de transfert est obligatoire.');
        $up = upload_document('bt', 'bl', 'bt_suivi_' . $suivi_id, false);
        if (!$up['success']) json_response(false, $up['message']);
        $bon_transfert = $up['filename'];

        try {
            $res = ach_receptionner_departement($suivi_id, $quantite, $date, $observation, $user, $bon_transfert);
            $msg = $res['reste'] <= 0
                ? "Réception département confirmée — ligne soldée (cumul {$res['cumul']})."
                : "Réception département confirmée — cumul {$res['cumul']}, reste {$res['reste']}.";
            json_response(true, $msg, $res);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    json_response(false, 'Action inconnue.');

    } catch (\Throwable $e) {
        error_log("receptions.php AJAX ($action) : " . $e->getMessage());
        json_response(false, "Erreur interne du serveur — contactez l'administrateur si ça persiste.");
    }
}

// ── PAGE PHP ─────────────────────────────────────────────────

// Étape 1 — Réception magasin : une ligne sans BC n'est pas réceptionnable
// (règle d'ordre J7, point 4) ; un reliquat clôturé de force ne l'est plus
// non plus.
$where1  = ["fs.numero_bc IS NOT NULL", "fs.numero_bc <> ''", "fs.quantite_recue < fs.quantite_commandee", "fs.cloture_reliquat = 0"];
$params1 = [];
if ($site_force) { $where1[] = 'fs.site_id = ?'; $params1[] = $site_force; }
$lignes_magasin = $can_magasin ? db_fetch_all(
    "SELECT fs.*, f.numero AS feb_numero,
            fl.designation, fl.unite, fl.fournisseur_id, fl.type_achat, fl.nomenclature_id,
            fo.raison_sociale AS fournisseur_nom,
            s.nom AS site_nom,
            n.libelle AS nomenclature_libelle
     FROM feb_suivi fs
     JOIN feb f          ON f.id = fs.feb_id
     JOIN feb_lignes fl  ON fl.id = fs.feb_ligne_id
     LEFT JOIN fournisseurs fo ON fo.id = fl.fournisseur_id
     LEFT JOIN sites s         ON s.id = fs.site_id
     LEFT JOIN nomenclatures n ON n.id = fl.nomenclature_id
     WHERE " . implode(' AND ', $where1) . "
     ORDER BY fs.date_livraison_prevue ASC",
    $params1
) : [];

// Étape 2 — Expédition vers le département : ce qui est arrivé au magasin
// et pas encore totalement expédié. Tout sauf DAI (fl.type_achat <> 'DAI')
// — les lignes DAI suivent leur propre circuit (équipements). Une ligne
// sans article_id (saisie libre non rattachée au référentiel) reste
// éligible : rien à créditer en stock, mais le suivi de quantité/expédition
// n'a pas de raison de s'arrêter pour autant (cf. ach_expedier_departement()).
$lignes_expedition = $can_magasin ? db_fetch_all(
    "SELECT fs.*, f.numero AS feb_numero, f.departement_id,
            fl.designation, fl.unite, fl.article_id,
            s.nom AS site_nom, d.label AS departement_label
     FROM feb_suivi fs
     JOIN feb f          ON f.id = fs.feb_id
     JOIN feb_lignes fl  ON fl.id = fs.feb_ligne_id
     LEFT JOIN sites s        ON s.id = fs.site_id
     LEFT JOIN departements d ON d.id = f.departement_id
     WHERE NOT (fl.type_achat <=> 'DAI') AND fs.quantite_recue > fs.quantite_expediee
     ORDER BY fs.id ASC",
    []
) : [];

// Étape 3 — Réception département : ce qui a été expédié et pas encore
// confirmé reçu par le département.
$where3  = ["NOT (fl.type_achat <=> 'DAI')", "fs.quantite_expediee > fs.quantite_receptionnee_departement"];
$params3 = [];
if ($departements_n1_force) {
    $placeholders = implode(',', array_fill(0, count($departements_n1_force), '?'));
    $where3[] = "f.departement_id IN ($placeholders)";
    array_push($params3, ...$departements_n1_force);
}
$lignes_reception_dept = $peut_voir_etape3 ? db_fetch_all(
    "SELECT fs.*, f.numero AS feb_numero, f.departement_id,
            fl.designation, fl.unite,
            d.label AS departement_label
     FROM feb_suivi fs
     JOIN feb f          ON f.id = fs.feb_id
     JOIN feb_lignes fl  ON fl.id = fs.feb_ligne_id
     LEFT JOIN departements d ON d.id = f.departement_id
     WHERE " . implode(' AND ', $where3) . "
     ORDER BY fs.id ASC",
    $params3
) : [];

// Lignes DAI : rattachement optionnel à une nomenclature avant réception
// (Étape 3 du chantier équipements, décision du 2026-08-20) — référentiel
// chargé une seule fois, utilisé par la modale de réception magasin.
$nomenclatures = db_fetch_all("SELECT id, code, libelle, categorie FROM nomenclatures ORDER BY categorie, libelle");

// ── Rafraîchissement live — fragment HTML seul (les trois tableaux), cf.
//    le même mécanisme sur file_attente.php.
function ach_rc_render_zone(array $lignes_magasin, array $lignes_expedition, array $lignes_reception_dept,
                             bool $can_magasin, bool $peut_voir_etape3, int $site_force,
                             array $departements_n1 = [], bool $is_admin = false): void {
    ?>
    <?php if ($can_magasin): ?>
    <div class="ach-section-ttl">Étape 1 — Réception magasin</div>
    <div class="ach-table-wrap" style="margin-bottom:24px">
      <?php if (empty($lignes_magasin)): ?>
        <div class="ach-empty">Aucune ligne à réceptionner<?= $site_force ? ' pour votre site' : '' ?>.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table data-paginer="25" class="ach-table">
        <thead><tr>
          <th>FEB</th><th>Article</th><th>Nomenclature</th><th>Fournisseur</th><th>Commandée</th><th>Déjà reçue</th><th>Reste à recevoir</th>
          <th>Livraison prévue</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($lignes_magasin as $l):
            $reste = (int)$l['quantite_commandee'] - (int)$l['quantite_recue'];
            $est_dai = $l['type_achat'] === 'DAI';
          ?>
          <tr>
            <td style="font-weight:700;color:var(--navy)"><?= h($l['feb_numero'] ?: '—') ?></td>
            <td><?= h($l['designation']) ?></td>
            <td>
              <?php if (!$est_dai): ?>—
              <?php elseif ($l['nomenclature_libelle']): ?>
                <span class="ach-badge on"><?= h($l['nomenclature_libelle']) ?></span>
              <?php else: ?>
                <span class="ach-badge off" style="background:#FEF3C7;color:#92400E">DAI — non rattachée</span>
              <?php endif; ?>
            </td>
            <td><?= h($l['fournisseur_nom'] ?: '—') ?></td>
            <td><?= (int)$l['quantite_commandee'] ?> <?= h($l['unite'] ?: '') ?></td>
            <td><?= (int)$l['quantite_recue'] ?></td>
            <td style="font-weight:700"><?= $reste ?></td>
            <td><?= fmt_date($l['date_livraison_prevue']) ?></td>
            <td>
              <button type="button" class="btn btn-primary btn-sm"
                      onclick='rcOuvrir(<?= json_encode([
                        "id"=>(int)$l["id"], "feb_ligne_id"=>(int)$l["feb_ligne_id"], "feb_numero"=>$l["feb_numero"], "designation"=>$l["designation"],
                        "unite"=>$l["unite"], "reste"=>$reste, "commandee"=>(int)$l["quantite_commandee"],
                        "recue"=>(int)$l["quantite_recue"], "est_dai"=>$est_dai, "nomenclature_id"=>$l["nomenclature_id"],
                      ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                Réceptionner
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="ach-section-ttl">Étape 2 — Expédition vers le département</div>
    <div class="ach-table-wrap" style="margin-bottom:24px">
      <?php if (empty($lignes_expedition)): ?>
        <div class="ach-empty">Rien au magasin en attente d'expédition.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table data-paginer="25" class="ach-table">
        <thead><tr>
          <th>FEB</th><th>Article</th><th>Département</th><th>Reçu au magasin</th><th>Déjà expédié</th><th>Reste à expédier</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($lignes_expedition as $l):
            $reste_exp = (int)$l['quantite_recue'] - (int)$l['quantite_expediee'];
            if ($reste_exp <= 0) continue;
          ?>
          <tr>
            <td style="font-weight:700;color:var(--navy)"><?= h($l['feb_numero'] ?: '—') ?></td>
            <td><?= h($l['designation']) ?></td>
            <td><?= h($l['departement_label'] ?: '—') ?></td>
            <td><?= (int)$l['quantite_recue'] ?> <?= h($l['unite'] ?: '') ?></td>
            <td><?= (int)$l['quantite_expediee'] ?></td>
            <td style="font-weight:700"><?= $reste_exp ?></td>
            <td>
              <button type="button" class="btn btn-primary btn-sm"
                      onclick='expOuvrir(<?= json_encode([
                        "id"=>(int)$l["id"], "feb_numero"=>$l["feb_numero"], "designation"=>$l["designation"],
                        "unite"=>$l["unite"], "reste"=>$reste_exp, "departement"=>$l["departement_label"],
                      ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                Expédier
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($peut_voir_etape3): ?>
    <div class="ach-section-ttl">Étape 3 — Réception département</div>
    <div class="ach-table-wrap">
      <?php if (empty($lignes_reception_dept)): ?>
        <div class="ach-empty">Rien en attente de confirmation.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table data-paginer="25" class="ach-table">
        <thead><tr>
          <th>FEB</th><th>Article</th><th>Département</th><th>Expédié</th><th>Déjà confirmé</th><th>Reste à confirmer</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($lignes_reception_dept as $l):
            $reste_dept = (int)$l['quantite_expediee'] - (int)$l['quantite_receptionnee_departement'];
            if ($reste_dept <= 0) continue;
            // Le bouton n'apparaît que pour le vrai N+1 DE CETTE LIGNE (ou
            // un administrateur) — voir le commentaire au chargement de la
            // page : avoir achats_suivi.can_read, ou même être N+1 d'un
            // AUTRE département, ne doit jamais suffire. Un service Achats
            // qui n'est N+1 nulle part voit ce statut en simple lecture.
            $peut_confirmer_cette_ligne = $is_admin || in_array((int)$l['departement_id'], $departements_n1, true);
          ?>
          <tr>
            <td style="font-weight:700;color:var(--navy)"><?= h($l['feb_numero'] ?: '—') ?></td>
            <td><?= h($l['designation']) ?></td>
            <td><?= h($l['departement_label'] ?: '—') ?></td>
            <td><?= (int)$l['quantite_expediee'] ?> <?= h($l['unite'] ?: '') ?></td>
            <td><?= (int)$l['quantite_receptionnee_departement'] ?></td>
            <td style="font-weight:700"><?= $reste_dept ?></td>
            <td>
              <?php if ($peut_confirmer_cette_ligne): ?>
              <button type="button" class="btn btn-primary btn-sm"
                      onclick='rdOuvrir(<?= json_encode([
                        "id"=>(int)$l["id"], "feb_numero"=>$l["feb_numero"], "designation"=>$l["designation"],
                        "unite"=>$l["unite"], "reste"=>$reste_dept,
                      ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                Confirmer la réception
              </button>
              <?php else: ?>
                <span class="ach-badge off">En attente du département</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
}

if (is_ajax() && ($_GET['fragment'] ?? '') === '1') {
    ach_rc_render_zone($lignes_magasin, $lignes_expedition, $lignes_reception_dept, $can_magasin, $peut_voir_etape3, $site_force, $departements_n1, $is_admin);
    exit;
}
$fragmentUrl = APP_URL . '/pages/achats/receptions.php?fragment=1';

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ach-section-ttl{font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy);margin:0 0 12px}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-empty{padding:40px 20px;text-align:center;color:var(--muted);font-size:13px}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.ach-badge.on{background:#D1FAE5;color:#065F46}
.ach-badge.off{background:#F1F5F9;color:#475569}
.ach-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ach-modal-bg.open{display:flex}
.ach-modal{background:white;border-radius:16px;padding:26px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ach-modal h3{margin:0 0 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.rc-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:16px}
.rc-lbl{font-size:11.5px;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.4px;margin-bottom:2px}
.rc-val{font-size:14px;font-weight:700;color:var(--navy)}
.rc-val.big{font-size:20px;color:#B45309}
.ach-fg{margin-bottom:14px}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg input,.ach-fg textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
.ach-fg textarea{resize:vertical;min-height:60px}
.ach-err{color:#dc2626;font-size:12px;margin-top:4px;display:none}
.ach-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
@media (max-width:768px) { .ach-fg input, .ach-fg textarea, .btn { min-height:44px; } .rc-grid { grid-template-columns:minmax(0,1fr); } }
</style>

<div id="rc-live-zone">
<?php ach_rc_render_zone($lignes_magasin, $lignes_expedition, $lignes_reception_dept, $can_magasin, $peut_voir_etape3, $site_force, $departements_n1, $is_admin); ?>
</div>

<!-- MODALE réception magasin -->
<div class="ach-modal-bg" id="rc-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="rc-modal-title">
    <h3 id="rc-modal-title">Réception magasin — <span id="rc-feb"></span></h3>
    <input type="hidden" id="rc-suivi-id" value="">
    <input type="hidden" id="rc-feb-ligne-id" value="">
    <div class="rc-grid">
      <div style="grid-column:1/-1"><div class="rc-lbl">Article</div><div class="rc-val" id="rc-article"></div></div>
      <div><div class="rc-lbl">Commandée</div><div class="rc-val" id="rc-commandee"></div></div>
      <div><div class="rc-lbl">Déjà reçue</div><div class="rc-val" id="rc-recue"></div></div>
      <div><div class="rc-lbl">Reste à recevoir</div><div class="rc-val big" id="rc-reste"></div></div>
    </div>
    <div class="ach-fg" id="rc-nomenclature-wrap" style="display:none">
      <label for="rc-nomenclature">Nomenclature (immobilisation — facultatif)</label>
      <select id="rc-nomenclature">
        <option value="">— Ne pas créer de fiche équipement —</option>
        <?php foreach ($nomenclatures as $n): ?>
          <option value="<?= $n['id'] ?>"><?= h($n['libelle']) ?> (<?= h($n['categorie']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <div class="ach-hint" style="font-size:11.5px;color:var(--muted);margin-top:4px">
        Rattachée, chaque unité reçue crée une fiche équipement individuelle en attente d'affectation. Laissé vide, aucune fiche n'est créée.
      </div>
    </div>
    <div class="ach-fg">
      <label for="rc-quantite">Quantité reçue cette fois</label>
      <input type="number" id="rc-quantite" min="1" step="1" required>
    </div>
    <div class="ach-fg">
      <label for="rc-date">Date de réception</label>
      <input type="date" id="rc-date">
    </div>
    <div class="ach-fg">
      <label for="rc-bl">Bon de livraison (PDF, JPG, PNG ou WEBP) *</label>
      <input type="file" id="rc-bl" accept=".pdf,.jpg,.jpeg,.png,.webp">
    </div>
    <div class="ach-fg">
      <label for="rc-observation">Observation</label>
      <textarea id="rc-observation" placeholder="Remarque sur cette livraison (facultatif)"></textarea>
    </div>
    <div class="ach-err" id="rc-err"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="rcFermer()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="rcValider()">Enregistrer la réception</button>
    </div>
  </div>
</div>

<!-- MODALE expédition vers le département -->
<div class="ach-modal-bg" id="exp-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="exp-modal-title">
    <h3 id="exp-modal-title">Expédition — <span id="exp-feb"></span></h3>
    <input type="hidden" id="exp-suivi-id" value="">
    <div class="rc-grid">
      <div style="grid-column:1/-1"><div class="rc-lbl">Article</div><div class="rc-val" id="exp-article"></div></div>
      <div style="grid-column:1/-1"><div class="rc-lbl">Département destinataire</div><div class="rc-val" id="exp-departement"></div></div>
      <div style="grid-column:1/-1"><div class="rc-lbl">Reste à expédier</div><div class="rc-val big" id="exp-reste"></div></div>
    </div>
    <div class="ach-fg">
      <label for="exp-quantite">Quantité expédiée cette fois</label>
      <input type="number" id="exp-quantite" min="1" step="1" required>
    </div>
    <div class="ach-fg">
      <label for="exp-date">Date d'expédition</label>
      <input type="date" id="exp-date">
    </div>
    <div class="ach-fg">
      <label for="exp-observation">Observation</label>
      <textarea id="exp-observation" placeholder="Remarque sur cette expédition (facultatif)"></textarea>
    </div>
    <div class="ach-err" id="exp-err"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="expFermer()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="expValider()">Enregistrer l'expédition</button>
    </div>
  </div>
</div>

<!-- MODALE réception département -->
<div class="ach-modal-bg" id="rd-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="rd-modal-title">
    <h3 id="rd-modal-title">Réception département — <span id="rd-feb"></span></h3>
    <input type="hidden" id="rd-suivi-id" value="">
    <div class="rc-grid">
      <div style="grid-column:1/-1"><div class="rc-lbl">Article</div><div class="rc-val" id="rd-article"></div></div>
      <div style="grid-column:1/-1"><div class="rc-lbl">Reste à confirmer</div><div class="rc-val big" id="rd-reste"></div></div>
    </div>
    <div class="ach-fg">
      <label for="rd-quantite">Quantité reçue cette fois</label>
      <input type="number" id="rd-quantite" min="1" step="1" required>
    </div>
    <div class="ach-fg">
      <label for="rd-date">Date de réception</label>
      <input type="date" id="rd-date">
    </div>
    <div class="ach-fg">
      <label for="rd-bt">Bon de transfert (PDF, JPG, PNG ou WEBP) *</label>
      <input type="file" id="rd-bt" accept=".pdf,.jpg,.jpeg,.png,.webp">
    </div>
    <div class="ach-fg">
      <label for="rd-observation">Observation</label>
      <textarea id="rd-observation" placeholder="Remarque sur cette réception (facultatif)"></textarea>
    </div>
    <div class="ach-err" id="rd-err"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="rdFermer()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="rdValider()">Confirmer la réception</button>
    </div>
  </div>
</div>

<script>
function rcOuvrir(l) {
  document.getElementById('rc-suivi-id').value = l.id;
  document.getElementById('rc-feb').textContent = l.feb_numero || '—';
  document.getElementById('rc-article').textContent = l.designation;
  document.getElementById('rc-commandee').textContent = l.commandee + ' ' + (l.unite || '');
  document.getElementById('rc-recue').textContent = l.recue + ' ' + (l.unite || '');
  document.getElementById('rc-reste').textContent = l.reste + ' ' + (l.unite || '');
  document.getElementById('rc-quantite').value = l.reste;
  document.getElementById('rc-quantite').max = l.reste;
  document.getElementById('rc-date').value = new Date().toISOString().slice(0, 10);
  document.getElementById('rc-bl').value = '';
  document.getElementById('rc-observation').value = '';
  document.getElementById('rc-feb-ligne-id').value = l.feb_ligne_id || '';
  document.getElementById('rc-nomenclature-wrap').style.display = l.est_dai ? '' : 'none';
  document.getElementById('rc-nomenclature').value = l.nomenclature_id || '';
  document.getElementById('rc-err').style.display = 'none';
  document.getElementById('rc-modal').classList.add('open');
  setTimeout(() => document.getElementById('rc-quantite').focus(), 80);
}
function rcFermer() { document.getElementById('rc-modal').classList.remove('open'); }
document.getElementById('rc-modal').addEventListener('click', e => { if (e.target === e.currentTarget) rcFermer(); });

function rcValider() {
  const err = document.getElementById('rc-err');
  const quantite = parseInt(document.getElementById('rc-quantite').value, 10);
  if (!quantite || quantite < 1) { err.textContent = 'La quantité reçue doit être strictement positive.'; err.style.display = 'block'; return; }
  if (!document.getElementById('rc-bl').files[0]) { err.textContent = 'Le bon de livraison est obligatoire.'; err.style.display = 'block'; return; }

  // Ligne DAI : le rattachement (ou son retrait) est enregistré avant la
  // réception elle-même, dans un aller séparé — plus simple qu'une
  // transaction unique côté serveur, et sans conséquence si l'un réussit
  // sans l'autre (le rattachement seul ne crée aucun exemplaire).
  const nomenclatureWrap = document.getElementById('rc-nomenclature-wrap');
  const lierPuisReceptionner = () => {
    if (nomenclatureWrap.style.display === 'none') return Promise.resolve(true);
    const fdLien = new FormData();
    fdLien.append('action', 'lier_nomenclature');
    fdLien.append('feb_ligne_id', document.getElementById('rc-feb-ligne-id').value);
    fdLien.append('nomenclature_id', document.getElementById('rc-nomenclature').value);
    return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fdLien })
      .then(achParseJson)
      .then(res => {
        if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return false; }
        return true;
      });
  };

  const fd = new FormData();
  fd.append('action', 'receptionner');
  fd.append('suivi_id', document.getElementById('rc-suivi-id').value);
  fd.append('quantite', quantite);
  fd.append('date_reception', document.getElementById('rc-date').value);
  fd.append('observation', document.getElementById('rc-observation').value.trim());
  const blFile = document.getElementById('rc-bl').files[0];
  if (blFile) fd.append('bl', blFile);

  lierPuisReceptionner().then(ok => {
    if (!ok) return;
    return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
    .then(achParseJson)
    .then(res => {
      if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
      toast(res.message, 'success');
      rcFermer();
      setTimeout(() => location.reload(), 600);
    });
  }).catch(e => { err.textContent = e.message || 'Erreur de communication avec le serveur — réessayez.'; err.style.display = 'block'; });
}

function expOuvrir(l) {
  document.getElementById('exp-suivi-id').value = l.id;
  document.getElementById('exp-feb').textContent = l.feb_numero || '—';
  document.getElementById('exp-article').textContent = l.designation;
  document.getElementById('exp-departement').textContent = l.departement || '—';
  document.getElementById('exp-reste').textContent = l.reste + ' ' + (l.unite || '');
  document.getElementById('exp-quantite').value = l.reste;
  document.getElementById('exp-quantite').max = l.reste;
  document.getElementById('exp-date').value = new Date().toISOString().slice(0, 10);
  document.getElementById('exp-observation').value = '';
  document.getElementById('exp-err').style.display = 'none';
  document.getElementById('exp-modal').classList.add('open');
  setTimeout(() => document.getElementById('exp-quantite').focus(), 80);
}
function expFermer() { document.getElementById('exp-modal').classList.remove('open'); }
document.getElementById('exp-modal').addEventListener('click', e => { if (e.target === e.currentTarget) expFermer(); });

function expValider() {
  const err = document.getElementById('exp-err');
  const quantite = parseInt(document.getElementById('exp-quantite').value, 10);
  if (!quantite || quantite < 1) { err.textContent = 'La quantité expédiée doit être strictement positive.'; err.style.display = 'block'; return; }
  const fd = new FormData();
  fd.append('action', 'expedier');
  fd.append('suivi_id', document.getElementById('exp-suivi-id').value);
  fd.append('quantite', quantite);
  fd.append('date_expedition', document.getElementById('exp-date').value);
  fd.append('observation', document.getElementById('exp-observation').value.trim());
  fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
    .then(achParseJson)
    .then(res => {
      if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
      toast(res.message, 'success');
      expFermer();
      setTimeout(() => location.reload(), 600);
    })
    .catch(e => { err.textContent = e.message || 'Erreur de communication avec le serveur — réessayez.'; err.style.display = 'block'; });
}

function rdOuvrir(l) {
  document.getElementById('rd-suivi-id').value = l.id;
  document.getElementById('rd-feb').textContent = l.feb_numero || '—';
  document.getElementById('rd-article').textContent = l.designation;
  document.getElementById('rd-reste').textContent = l.reste + ' ' + (l.unite || '');
  document.getElementById('rd-quantite').value = l.reste;
  document.getElementById('rd-quantite').max = l.reste;
  document.getElementById('rd-date').value = new Date().toISOString().slice(0, 10);
  document.getElementById('rd-bt').value = '';
  document.getElementById('rd-observation').value = '';
  document.getElementById('rd-err').style.display = 'none';
  document.getElementById('rd-modal').classList.add('open');
  setTimeout(() => document.getElementById('rd-quantite').focus(), 80);
}
function rdFermer() { document.getElementById('rd-modal').classList.remove('open'); }
document.getElementById('rd-modal').addEventListener('click', e => { if (e.target === e.currentTarget) rdFermer(); });

function rdValider() {
  const err = document.getElementById('rd-err');
  const quantite = parseInt(document.getElementById('rd-quantite').value, 10);
  if (!quantite || quantite < 1) { err.textContent = 'La quantité reçue doit être strictement positive.'; err.style.display = 'block'; return; }
  if (!document.getElementById('rd-bt').files[0]) { err.textContent = 'Le bon de transfert est obligatoire.'; err.style.display = 'block'; return; }
  const fd = new FormData();
  fd.append('action', 'receptionner_departement');
  fd.append('suivi_id', document.getElementById('rd-suivi-id').value);
  fd.append('quantite', quantite);
  fd.append('date_reception', document.getElementById('rd-date').value);
  fd.append('observation', document.getElementById('rd-observation').value.trim());
  const btFile = document.getElementById('rd-bt').files[0];
  if (btFile) fd.append('bt', btFile);
  fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
    .then(achParseJson)
    .then(res => {
      if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
      toast(res.message, 'success');
      rdFermer();
      setTimeout(() => location.reload(), 600);
    })
    .catch(e => { err.textContent = e.message || 'Erreur de communication avec le serveur — réessayez.'; err.style.display = 'block'; });
}

document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  rcFermer(); expFermer(); rdFermer();
});

document.addEventListener('DOMContentLoaded', () => {
  liveRefresh({ url: <?= json_encode($fragmentUrl) ?>, container: '#rc-live-zone', interval: 10000 });
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
