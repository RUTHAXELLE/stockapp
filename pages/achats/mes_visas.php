<?php
// ============================================================
//  pages/achats/mes_visas.php — File des visas FEB en attente
//  FEB dont l'étape courante correspond à un rôle porté par
//  l'utilisateur (ach_a_viser, includes/achats.php).
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/achats.php';

require_auth();
$user = current_user();
// Pas require_permission() : le supérieur hiérarchique n'a pas
// achats.can_update — cf. ach_peut_ouvrir_visas().
if (!ach_peut_ouvrir_visas($user)) {
    http_response_code(403);
    include __DIR__ . '/../../templates/403.php';
    exit;
}
$uid = (int)$user['id'];
$_SESSION['groupe_actif'] = 'ACHATS';
$page_title  = 'Mes visas';
$active_page = 'achats_mes_visas';

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $feb_id = (int)($_POST['feb_id'] ?? 0);

    if ($action === 'get_detail') {
        $row = db_fetch_one("SELECT * FROM feb WHERE id=? AND statut='en_validation'", [$feb_id]);
        if (!$row) json_response(false, 'FEB introuvable ou plus en cours de validation.');
        $feb = ach_feb_decode($row);
        $roles = di_user_roles($uid);
        // n1_user_id figé au lancement, comme dans ach_viser() : passer null
        // ferait retomber di_can_validate() sur « tout porteur du code n1 »,
        // que seuls les administrateurs possèdent — le supérieur hiérarchique
        // verrait la FEB dans sa liste sans pouvoir l'ouvrir.
        $n1_user_id = $feb['n1_user_id'] ? (int)$feb['n1_user_id'] : null;
        if (!di_can_validate($roles, $uid, $feb['workflow_snapshot'], (int)$feb['etape_actuelle'], (int)$feb['demandeur_id'], $n1_user_id)) {
            json_response(false, "Ce n'est pas votre étape à viser.");
        }

        $demandeur_nom = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$feb['demandeur_id']]);
        $site_nom      = $feb['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$feb['site_id']]) : null;

        $lots = ach_lots_feb($feb_id);
        $offres_par_lot = [];
        foreach ($lots as $lot) {
            $offres_par_lot[$lot['lot']] = db_fetch_all(
                "SELECT o.*, f.raison_sociale AS fournisseur_nom
                 FROM feb_offres o LEFT JOIN fournisseurs f ON f.id = o.fournisseur_id
                 WHERE o.feb_id=? AND o.lot=? ORDER BY o.id",
                [$feb_id, $lot['lot']]
            );
        }
        $pieces = db_fetch_all("SELECT id, nom_origine, fichier FROM feb_pieces_jointes WHERE feb_id=?", [$feb_id]);

        // Budget du département de la FEB, par famille (Bloc 5, point 25) —
        // le département est nommé explicitement : un signataire qui en
        // couvre plusieurs (le DAF, par exemple) ne doit jamais avoir à
        // deviner duquel il s'agit. Situation hors cette FEB (exclure_feb)
        // pour montrer la place qui existait avant elle.
        $departement_nom = $feb['departement_id'] ? db_fetch_value("SELECT label FROM departements WHERE id=?", [$feb['departement_id']]) : null;
        $budget = [];
        if ($feb['departement_id']) {
            $familles_feb = db_fetch_all(
                "SELECT famille_id, SUM(montant_ttc) AS montant FROM feb_lignes
                  WHERE feb_id=? AND arbitrage='achat' AND famille_id IS NOT NULL GROUP BY famille_id",
                [$feb_id]
            );
            foreach ($familles_feb as $ff) {
                $sit = ach_budget_situation((int)$feb['departement_id'], (int)$ff['famille_id'], (int)$feb['exercice'], $feb_id);
                $budget[] = [
                    'famille'      => db_fetch_value("SELECT libelle FROM familles_achat WHERE id=?", [$ff['famille_id']]),
                    'montant_feb'  => (int)$ff['montant'],
                    'total_engage' => $sit['total_engage'],
                    'enveloppe'    => $sit['enveloppe'],
                    'disponible'   => $sit['disponible'],
                ];
            }
        }

        json_response(true, '', [
            'feb'            => $feb,
            'demandeur_nom'  => $demandeur_nom ?: '—',
            'site_nom'       => $site_nom ?: '—',
            'departement_nom'=> $departement_nom ?: '—',
            'etape_label'    => $feb['workflow_snapshot'][(int)$feb['etape_actuelle']]['label'] ?? '',
            'lots'           => $lots,
            'offres'         => $offres_par_lot,
            'budget'         => $budget,
            'pieces'         => array_map(fn($p) => [
                'nom_origine' => $p['nom_origine'], 'url' => '/uploads/feb/' . rawurlencode($p['fichier']),
            ], $pieces),
        ]);
    }

    if ($action === 'viser') {
        $accepte     = ($_POST['accepte'] ?? '') === '1';
        $commentaire = trim($_POST['commentaire'] ?? '');
        try {
            $ok = ach_viser($feb_id, $user, $accepte, $commentaire);
            if ($ok) json_response(true, $accepte ? 'Visa enregistré.' : 'FEB rejetée.');
            json_response(false, 'Cette étape vient déjà d\'être traitée par un autre signataire.');
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    // ── Affectations d'équipements (Étape 4) — même palier, même moteur de
    //    circuit que la FEB, table et détail distincts (pas de lots/offres/
    //    budget à afficher, juste l'exemplaire et sa destination proposée).
    if ($action === 'get_detail_affectation') {
        $affectation_id = (int)($_POST['affectation_id'] ?? 0);
        $row = db_fetch_one("SELECT * FROM equipement_affectations WHERE id=? AND statut='en_validation'", [$affectation_id]);
        if (!$row) json_response(false, "Proposition introuvable ou plus en cours de validation.");
        $affectation = ach_affectation_decode($row);
        $roles = di_user_roles($uid);
        if (!di_can_validate($roles, $uid, $affectation['workflow_snapshot'], (int)$affectation['etape_actuelle'], (int)$affectation['proposee_par'], null)) {
            json_response(false, "Ce n'est pas votre étape à viser.");
        }

        $equipement = db_fetch_one(
            "SELECT e.*, n.libelle AS nomenclature_libelle FROM equipements e LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id WHERE e.id=?",
            [$affectation['equipement_id']]
        );
        $proposeur_nom  = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$affectation['proposee_par']]);
        $site_nom       = $affectation['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$affectation['site_id']]) : null;
        // Affectation « au département » (pas de site précis) : même repli
        // que la liste ci-dessus, sinon la fiche affiche « — » alors que la
        // destination réelle (le département) est connue.
        $departement_label = $affectation['site_id'] ? null : db_fetch_value(
            "SELECT d.label FROM departements d WHERE d.id = ?", [$equipement['departement_id'] ?? 0]
        );
        $utilisateur_nom= $affectation['utilisateur_id'] ? db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$affectation['utilisateur_id']]) : null;

        json_response(true, '', [
            'affectation'     => $affectation,
            'numero_serie'    => $equipement['numero_serie_interne'] ?? '—',
            'nomenclature'    => $equipement['nomenclature_libelle'] ?? '—',
            'prix_achat'      => (float)($equipement['prix_achat'] ?? 0),
            'proposeur_nom'   => $proposeur_nom ?: '—',
            'site_nom'        => $site_nom ?: ($departement_label ? "$departement_label (département)" : '—'),
            'utilisateur_nom' => $utilisateur_nom ?: '—',
            'etape_label'     => $affectation['workflow_snapshot'][(int)$affectation['etape_actuelle']]['label'] ?? '',
        ]);
    }

    // ── Endossement N+1 avant prise en charge par les achats — porte
    //    distincte du circuit RAF/DAF/PDG (pas de workflow_snapshot ici,
    //    cf. ach_endosser_n1() dans includes/achats.php).
    if ($action === 'get_detail_endosser') {
        $row = db_fetch_one("SELECT * FROM feb WHERE id=? AND statut='en_attente_n1'", [$feb_id]);
        if (!$row) json_response(false, "FEB introuvable ou n'attend plus votre aval.");
        if ((int)$row['n1_user_id'] !== $uid) json_response(false, "Vous n'êtes pas le supérieur hiérarchique désigné pour cette demande.");

        $demandeur_nom = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$row['demandeur_id']]);
        $site_nom      = $row['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$row['site_id']]) : null;
        $lignes        = db_fetch_all("SELECT designation, quantite, unite, montant_ttc FROM feb_lignes WHERE feb_id=? ORDER BY numero_ligne", [$feb_id]);

        json_response(true, '', [
            'feb'           => ['id' => $row['id'], 'numero' => $row['numero'], 'objet' => $row['objet'],
                                 'montant_total' => (float)$row['montant_total'], 'urgence' => (int)$row['urgence']],
            'demandeur_nom' => $demandeur_nom ?: '—',
            'site_nom'      => $site_nom ?: '—',
            'lignes'        => $lignes,
        ]);
    }

    if ($action === 'endosser') {
        $accepte     = ($_POST['accepte'] ?? '') === '1';
        $commentaire = trim($_POST['commentaire'] ?? '');
        try {
            $ok = ach_endosser_n1($feb_id, $user, $accepte, $commentaire);
            if ($ok) json_response(true, $accepte ? 'FEB endossée et transmise aux Achats.' : 'FEB renvoyée au demandeur.');
            json_response(false, 'Cette FEB vient déjà d\'être traitée.');
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    if ($action === 'viser_affectation') {
        $affectation_id = (int)($_POST['affectation_id'] ?? 0);
        $accepte     = ($_POST['accepte'] ?? '') === '1';
        $commentaire = trim($_POST['commentaire'] ?? '');
        try {
            $ok = ach_viser_affectation($affectation_id, $user, $accepte, $commentaire);
            if ($ok) json_response(true, $accepte ? 'Visa enregistré.' : 'Affectation rejetée.');
            json_response(false, 'Cette étape vient déjà d\'être traitée par un autre signataire.');
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    json_response(false, 'Action inconnue.');
}

// ── PAGE PHP ─────────────────────────────────────────────────
$a_viser = ach_a_viser($user);
foreach ($a_viser as &$f) {
    $f['anciennete_h']     = ach_anciennete_heures_ouvrees($f['date_lancement_validation']);
    $f['demandeur_nom']    = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$f['demandeur_id']]);
    $f['fournisseur_retenu'] = db_fetch_value(
        "SELECT GROUP_CONCAT(DISTINCT f2.raison_sociale SEPARATOR ', ') FROM feb_offres o JOIN fournisseurs f2 ON f2.id = o.fournisseur_id WHERE o.feb_id=? AND o.retenue=1",
        [$f['id']]
    );
    $f['nb_pieces'] = (int) db_fetch_value("SELECT COUNT(*) FROM feb_pieces_jointes WHERE feb_id=?", [$f['id']]);
}
unset($f);

$total_a_viser  = count($a_viser);
$anciennete_max = $a_viser ? max(array_column($a_viser, 'anciennete_h')) : 0.0;

// ── Endossement N+1 avant prise en charge par les achats.
$a_endosser = ach_a_endosser($user);
foreach ($a_endosser as &$e) {
    $e['demandeur_nom'] = db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$e['demandeur_id']]);
}
unset($e);

// ── Étape 4 : propositions d'affectation en attente du même visa.
$affectations_a_viser = ach_a_viser_affectations($user);
foreach ($affectations_a_viser as &$a) {
    $a['numero_serie'] = db_fetch_value("SELECT numero_serie_interne FROM equipements WHERE id=?", [$a['equipement_id']]);
    $a['nomenclature'] = db_fetch_value(
        "SELECT n.libelle FROM equipements e LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id WHERE e.id=?", [$a['equipement_id']]
    );
    $a['prix_achat']   = (float) db_fetch_value("SELECT prix_achat FROM equipements WHERE id=?", [$a['equipement_id']]);
    $a['site_nom']     = $a['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$a['site_id']]) : null;
    // Une affectation « au département » (pas de site précis, cf. la modale
    // en deux étapes de equipements_attente.php) laissait ce champ à « — »
    // sans jamais montrer le département — repli identique à celui déjà
    // posé sur equipements_attente.php et stock_departements.php.
    $a['departement_label'] = $a['site_id'] ? null : db_fetch_value(
        "SELECT d.label FROM equipements e LEFT JOIN departements d ON d.id = e.departement_id WHERE e.id=?",
        [$a['equipement_id']]
    );
    $a['proposeur_nom']= db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [$a['proposee_par']]);
}
unset($a);

// ── Rafraîchissement live — fragment HTML seul (KPI + tableau), cf. le
//    même mécanisme sur file_attente.php (templates/footer.php).
function ach_mv_render_zone(int $total_a_viser, float $anciennete_max, array $a_viser, array $a_endosser, array $affectations_a_viser): void {
    ?>
    <div class="ach-kpi">
      <div class="ach-kpi-item">
        <div class="ach-kpi-val"><?= count($a_endosser) ?></div>
        <div class="ach-kpi-lbl">FEB à endosser (avant prise en charge Achats)</div>
      </div>
      <div class="ach-kpi-item">
        <div class="ach-kpi-val"><?= $total_a_viser ?></div>
        <div class="ach-kpi-lbl">FEB en attente de votre visa</div>
      </div>
      <div class="ach-kpi-item">
        <div class="ach-kpi-val"><?= $a_viser ? fmt_number($anciennete_max, 1) . ' h' : '—' ?></div>
        <div class="ach-kpi-lbl">Ancienneté de la plus ancienne (heures ouvrées)</div>
      </div>
    </div>

    <div class="ach-section-ttl" style="font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy);margin:0 0 12px">À endosser avant prise en charge Achats</div>
    <div class="ach-table-wrap" style="margin-bottom:24px">
      <?php if (empty($a_endosser)): ?>
        <div class="ach-empty">Aucune FEB en attente de votre aval.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table data-paginer="25" class="ach-table">
        <thead><tr>
          <th>Numéro</th><th>Objet</th><th>Demandeur</th><th>Montant estimé</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($a_endosser as $e): ?>
          <tr>
            <td style="font-weight:700;color:var(--navy)"><?= h($e['numero'] ?: '—') ?></td>
            <td><?= h($e['objet']) ?></td>
            <td><?= h($e['demandeur_nom'] ?: '—') ?></td>
            <td style="font-weight:700"><?= fmt_number((float)$e['montant_total']) ?> XOF</td>
            <td><button type="button" class="btn btn-primary btn-sm" onclick="mvOuvrirEndosser(<?= (int)$e['id'] ?>)">Examiner</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="ach-section-ttl" style="font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy);margin:0 0 12px">Circuit de validation (RAF/DAF/PDG)</div>
    <div class="ach-table-wrap">
      <?php if (empty($a_viser)): ?>
        <div class="ach-empty">Aucune FEB en attente de votre visa.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table data-paginer="25" class="ach-table">
        <thead><tr>
          <th>Numéro</th><th>Objet</th><th>Demandeur</th><th>Montant</th><th>Fournisseur retenu</th>
          <th>Ancienneté</th><th>Pièces jointes</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($a_viser as $f): ?>
          <tr>
            <td style="font-weight:700;color:var(--navy)"><?= h($f['numero'] ?: '—') ?></td>
            <td><?= h($f['objet']) ?></td>
            <td><?= h($f['demandeur_nom'] ?: '—') ?></td>
            <td style="font-weight:700"><?= fmt_number((float)$f['montant_total']) ?> XOF</td>
            <td><?= h($f['fournisseur_retenu'] ?: '—') ?></td>
            <td><?= fmt_number((float)$f['anciennete_h'], 1) ?> h</td>
            <td><?= (int)$f['nb_pieces'] ?></td>
            <td><button type="button" class="btn btn-primary btn-sm" onclick="mvOuvrir(<?= (int)$f['id'] ?>)">Examiner</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="ach-section-ttl" style="font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:var(--navy);margin:24px 0 12px">Affectations d'équipements (Étape 4)</div>
    <div class="ach-table-wrap">
      <?php if (empty($affectations_a_viser)): ?>
        <div class="ach-empty">Aucune affectation en attente de votre visa.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table data-paginer="25" class="ach-table">
        <thead><tr>
          <th>N° série</th><th>Nomenclature</th><th>Destination proposée</th><th>Valeur</th><th>Proposée par</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($affectations_a_viser as $a): ?>
          <tr>
            <td style="font-family:monospace;font-size:12px"><?= h($a['numero_serie'] ?: '—') ?></td>
            <td><?= h($a['nomenclature'] ?: '—') ?></td>
            <td><?= $a['site_nom'] ? h($a['site_nom']) : ($a['departement_label'] ? h($a['departement_label']) . ' (département)' : '—') ?></td>
            <td style="font-weight:700"><?= fmt_number((float)$a['prix_achat']) ?> XOF</td>
            <td><?= h($a['proposeur_nom'] ?: '—') ?></td>
            <td><button type="button" class="btn btn-primary btn-sm" onclick="mvOuvrirAffectation(<?= (int)$a['id'] ?>)">Examiner</button></td>
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
    ach_mv_render_zone($total_a_viser, $anciennete_max, $a_viser, $a_endosser, $affectations_a_viser);
    exit;
}

include __DIR__ . '/../../templates/header.php';
?>
<style>
.ach-kpi{background:linear-gradient(135deg,#B45309 0%,#F59E0B 100%);border-radius:16px;padding:18px 22px;color:white;margin-bottom:18px;display:flex;gap:28px;flex-wrap:wrap;align-items:center}
.ach-kpi-item{display:flex;flex-direction:column}
.ach-kpi-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;line-height:1.1}
.ach-kpi-lbl{font-size:12px;opacity:.9;margin-top:2px}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 14px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-empty{padding:30px 20px;text-align:center;color:var(--muted);font-size:13px}
.ach-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ach-modal-bg.open{display:flex}
.ach-modal{background:white;border-radius:16px;padding:26px;width:100%;max-width:760px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ach-modal h3{margin:0 0 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.visa-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px 16px;margin-bottom:18px}
@media(max-width:768px){.visa-grid{grid-template-columns:repeat(2,1fr)}}
.visa-lbl{font-size:11.5px;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.4px;margin-bottom:2px}
.visa-val{font-size:14px;font-weight:700;color:var(--navy)}
.visa-val.big{font-size:18px;color:#B45309}
.lot-card{background:#f8fafc;border:1px solid var(--border);border-radius:12px;margin-bottom:14px;overflow:hidden}
.lot-hdr{padding:10px 14px;background:#fff7ed;border-bottom:1px solid #fed7aa;font-family:monospace;font-weight:800;color:#B45309;font-size:13px}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.badge-retenue{background:#D1FAE5;color:#065F46}
.visa-pieces{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}
.ach-fg{margin:14px 0}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.ach-fg textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;min-height:70px;resize:vertical}
.ach-err{color:#dc2626;font-size:12px;margin-top:4px;display:none}
.visa-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap}
</style>

<div id="mv-live-zone">
<?php ach_mv_render_zone($total_a_viser, $anciennete_max, $a_viser, $a_endosser, $affectations_a_viser); ?>
</div>

<!-- MODALE visa -->
<div class="ach-modal-bg" id="visa-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="visa-modal-title">
    <h3 id="visa-modal-title">Visa — <span id="mv-numero"></span></h3>
    <input type="hidden" id="mv-feb-id" value="">
    <div class="visa-grid">
      <div><div class="visa-lbl">Demandeur</div><div class="visa-val" id="mv-demandeur"></div></div>
      <div><div class="visa-lbl">Site</div><div class="visa-val" id="mv-site"></div></div>
      <div><div class="visa-lbl">Département</div><div class="visa-val" id="mv-departement" style="color:#B45309"></div></div>
      <div><div class="visa-lbl">Étape</div><div class="visa-val" id="mv-etape"></div></div>
      <div><div class="visa-lbl">Montant total</div><div class="visa-val big" id="mv-montant"></div></div>
      <div style="grid-column:1/-1"><div class="visa-lbl">Objet</div><div class="visa-val" id="mv-objet"></div></div>
    </div>

    <div class="visa-lbl">Budget du département, par famille</div>
    <div style="overflow-x:auto;margin-bottom:18px">
    <table data-paginer="25" class="ach-table">
      <thead><tr><th>Famille</th><th>Demandé (cette FEB)</th><th>Déjà engagé (hors cette FEB)</th><th>Enveloppe</th><th>Disponible avant cette FEB</th></tr></thead>
      <tbody id="mv-budget"></tbody>
    </table>
    </div>

    <div id="mv-lots"></div>

    <div class="visa-lbl">Pièces jointes</div>
    <ul class="visa-pieces" id="mv-pieces"></ul>

    <div class="ach-fg">
      <label for="mv-commentaire">Commentaire (obligatoire en cas de rejet)</label>
      <textarea id="mv-commentaire" placeholder="Motif du rejet, ou remarque optionnelle si vous acceptez"></textarea>
      <div class="ach-err" id="mv-err"></div>
    </div>

    <div class="visa-actions">
      <button type="button" class="btn btn-secondary" onclick="mvFermer()">Fermer</button>
      <button type="button" class="btn btn-danger" onclick="mvViser(false)">Rejeter</button>
      <button type="button" class="btn btn-primary" onclick="mvViser(true)">Accepter</button>
    </div>
  </div>
</div>

<!-- MODALE endossement N+1 -->
<div class="ach-modal-bg" id="endos-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="endos-modal-title" style="max-width:640px">
    <h3 id="endos-modal-title">Endossement — <span id="me-numero"></span></h3>
    <input type="hidden" id="me-feb-id" value="">
    <div class="visa-grid" style="grid-template-columns:repeat(2,1fr)">
      <div><div class="visa-lbl">Demandeur</div><div class="visa-val" id="me-demandeur"></div></div>
      <div><div class="visa-lbl">Site</div><div class="visa-val" id="me-site"></div></div>
      <div><div class="visa-lbl">Montant estimé</div><div class="visa-val big" id="me-montant"></div></div>
      <div style="grid-column:1/-1"><div class="visa-lbl">Objet</div><div class="visa-val" id="me-objet"></div></div>
    </div>
    <div class="visa-lbl">Lignes</div>
    <div style="overflow-x:auto;margin-bottom:16px">
    <table data-paginer="25" class="ach-table">
      <thead><tr><th>Désignation</th><th>Qté</th><th>Unité</th><th>Montant estimé</th></tr></thead>
      <tbody id="me-lignes"></tbody>
    </table>
    </div>
    <div class="ach-fg">
      <label for="me-commentaire">Commentaire (obligatoire en cas de refus)</label>
      <textarea id="me-commentaire" placeholder="Motif du refus, ou remarque optionnelle si vous endossez"></textarea>
      <div class="ach-err" id="me-err"></div>
    </div>
    <div class="visa-actions">
      <button type="button" class="btn btn-secondary" onclick="meFermer()">Fermer</button>
      <button type="button" class="btn btn-danger" onclick="meViser(false)">Refuser</button>
      <button type="button" class="btn btn-primary" onclick="meViser(true)">Endosser</button>
    </div>
  </div>
</div>

<!-- MODALE visa affectation -->
<div class="ach-modal-bg" id="visa-affectation-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="visa-affectation-title" style="max-width:520px">
    <h3 id="visa-affectation-title">Visa affectation — <span id="mva-numero-serie"></span></h3>
    <input type="hidden" id="mva-affectation-id" value="">
    <div class="visa-grid" style="grid-template-columns:repeat(2,1fr)">
      <div><div class="visa-lbl">Nomenclature</div><div class="visa-val" id="mva-nomenclature"></div></div>
      <div><div class="visa-lbl">Valeur</div><div class="visa-val big" id="mva-valeur"></div></div>
      <div><div class="visa-lbl">Site de destination</div><div class="visa-val" id="mva-site"></div></div>
      <div><div class="visa-lbl">Utilisateur nommé</div><div class="visa-val" id="mva-utilisateur"></div></div>
      <div><div class="visa-lbl">Proposée par</div><div class="visa-val" id="mva-proposeur"></div></div>
      <div><div class="visa-lbl">Étape</div><div class="visa-val" id="mva-etape"></div></div>
    </div>
    <div class="ach-fg">
      <label for="mva-commentaire">Commentaire (obligatoire en cas de rejet)</label>
      <textarea id="mva-commentaire" placeholder="Motif du rejet, ou remarque optionnelle si vous acceptez"></textarea>
      <div class="ach-err" id="mva-err"></div>
    </div>
    <div class="visa-actions">
      <button type="button" class="btn btn-secondary" onclick="mvaFermer()">Fermer</button>
      <button type="button" class="btn btn-danger" onclick="mvaViser(false)">Rejeter</button>
      <button type="button" class="btn btn-primary" onclick="mvaViser(true)">Accepter</button>
    </div>
  </div>
</div>

<script>
function mvPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
}
const esc = s => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

function mvOuvrir(febId) {
  mvPost({ action: 'get_detail', feb_id: febId }).then(res => {
    if (!res.success) { toast(res.message, 'danger'); return; }
    const d = res.data;
    document.getElementById('mv-feb-id').value = febId;
    document.getElementById('mv-numero').textContent = d.feb.numero || '—';
    document.getElementById('mv-demandeur').textContent = d.demandeur_nom;
    document.getElementById('mv-site').textContent = d.site_nom;
    document.getElementById('mv-departement').textContent = d.departement_nom;
    document.getElementById('mv-etape').textContent = d.etape_label;
    document.getElementById('mv-montant').textContent = Number(d.feb.montant_total).toLocaleString('fr-FR') + ' XOF';
    document.getElementById('mv-objet').textContent = d.feb.objet;
    document.getElementById('mv-commentaire').value = '';
    document.getElementById('mv-err').style.display = 'none';

    document.getElementById('mv-budget').innerHTML = (d.budget || []).map(b => `
      <tr>
        <td style="font-weight:700">${esc(b.famille)}</td>
        <td>${Number(b.montant_feb).toLocaleString('fr-FR')} XOF</td>
        <td>${Number(b.total_engage).toLocaleString('fr-FR')} XOF</td>
        <td>${b.enveloppe != null ? Number(b.enveloppe).toLocaleString('fr-FR') + ' XOF' : 'Non plafonnée'}</td>
        <td style="font-weight:700;${b.disponible != null && b.disponible < b.montant_feb ? 'color:#991B1B' : ''}">${b.disponible != null ? Number(b.disponible).toLocaleString('fr-FR') + ' XOF' : '—'}</td>
      </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:14px">Aucune ligne budgétaire pour ce département — non contrôlé.</td></tr>';

    document.getElementById('mv-lots').innerHTML = d.lots.map(lot => {
      const offres = d.offres[lot.lot] || [];
      const lignesHtml = lot.lignes.map(l => `
        <tr>
          <td>${esc(l.designation)}</td><td>${l.quantite}</td>
          <td>${esc(l.type_achat || '—')}</td>
          <td>${Number(l.montant_ttc).toLocaleString('fr-FR')} XOF</td>
        </tr>`).join('');
      const offresHtml = offres.map(o => `
        <tr>
          <td>${esc(o.fournisseur_nom || '—')}</td>
          <td>${o.delai_annonce != null ? o.delai_annonce + ' j' : '—'}</td>
          <td>${esc(o.conditions_paiement || '—')}</td>
          <td>${Number(o.montant_ttc).toLocaleString('fr-FR')} XOF</td>
          <td>${o.retenue == 1 ? '<span class="ach-badge badge-retenue">Retenue</span>' : ''}</td>
        </tr>`).join('');
      return `
        <div class="lot-card">
          <div class="lot-hdr">Lot ${esc(lot.lot)} — ${lot.nb_articles} article(s), ${lot.somme_quantites} unité(s)</div>
          <table class="ach-table"><thead><tr><th>Désignation</th><th>Qté</th><th>Type</th><th>Montant</th></tr></thead>
            <tbody>${lignesHtml}</tbody></table>
          <table class="ach-table"><thead><tr><th>Fournisseur</th><th>Délai</th><th>Conditions</th><th>Montant</th><th></th></tr></thead>
            <tbody>${offresHtml || '<tr><td colspan="5" style="text-align:center;color:var(--muted)">Aucune offre.</td></tr>'}</tbody></table>
        </div>`;
    }).join('') || '<div class="ach-empty">Aucun lot à comparer (FEB entièrement servie sur stock).</div>';

    document.getElementById('mv-pieces').innerHTML = d.pieces.map(p =>
      `<li><a href="${p.url}" target="_blank"><i class="ph ph-paperclip" aria-hidden="true"></i> ${esc(p.nom_origine)}</a></li>`
    ).join('') || '<li style="color:var(--muted)">Aucune pièce jointe.</li>';

    document.getElementById('visa-modal').classList.add('open');
  });
}
function mvFermer() { document.getElementById('visa-modal').classList.remove('open'); }
document.getElementById('visa-modal').addEventListener('click', e => { if (e.target === e.currentTarget) mvFermer(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') mvFermer(); });

function mvViser(accepte) {
  const err = document.getElementById('mv-err');
  const commentaire = document.getElementById('mv-commentaire').value.trim();
  if (!accepte && !commentaire) {
    err.textContent = 'Le motif de rejet est obligatoire.'; err.style.display = 'block'; return;
  }
  if (accepte && !confirm('Confirmer votre visa favorable ?')) return;
  if (!accepte && !confirm('Confirmer le rejet de cette FEB ?')) return;

  mvPost({ action: 'viser', feb_id: document.getElementById('mv-feb-id').value, accepte: accepte ? '1' : '0', commentaire }).then(res => {
    if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
    toast(res.message, 'success');
    mvFermer();
    setTimeout(() => location.reload(), 600);
  });
}

function mvOuvrirEndosser(febId) {
  mvPost({ action: 'get_detail_endosser', feb_id: febId }).then(res => {
    if (!res.success) { toast(res.message, 'danger'); return; }
    const d = res.data;
    document.getElementById('me-feb-id').value = febId;
    document.getElementById('me-numero').textContent = d.feb.numero || '—';
    document.getElementById('me-demandeur').textContent = d.demandeur_nom;
    document.getElementById('me-site').textContent = d.site_nom;
    document.getElementById('me-montant').textContent = Number(d.feb.montant_total).toLocaleString('fr-FR') + ' XOF';
    document.getElementById('me-objet').textContent = d.feb.objet;
    document.getElementById('me-commentaire').value = '';
    document.getElementById('me-err').style.display = 'none';
    document.getElementById('me-lignes').innerHTML = (d.lignes || []).map(l => `
      <tr>
        <td>${esc(l.designation)}</td><td>${l.quantite}</td><td>${esc(l.unite || '—')}</td>
        <td>${Number(l.montant_ttc).toLocaleString('fr-FR')} XOF</td>
      </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--muted)">Aucune ligne.</td></tr>';
    document.getElementById('endos-modal').classList.add('open');
  });
}
function meFermer() { document.getElementById('endos-modal').classList.remove('open'); }
document.getElementById('endos-modal').addEventListener('click', e => { if (e.target === e.currentTarget) meFermer(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') meFermer(); });

function meViser(accepte) {
  const err = document.getElementById('me-err');
  const commentaire = document.getElementById('me-commentaire').value.trim();
  if (!accepte && !commentaire) {
    err.textContent = 'Le motif de refus est obligatoire.'; err.style.display = 'block'; return;
  }
  if (accepte && !confirm('Endosser cette FEB et la transmettre aux Achats ?')) return;
  if (!accepte && !confirm('Confirmer le refus de cette FEB ?')) return;

  mvPost({ action: 'endosser', feb_id: document.getElementById('me-feb-id').value, accepte: accepte ? '1' : '0', commentaire }).then(res => {
    if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
    toast(res.message, 'success');
    meFermer();
    setTimeout(() => location.reload(), 600);
  });
}

function mvOuvrirAffectation(affectationId) {
  mvPost({ action: 'get_detail_affectation', affectation_id: affectationId }).then(res => {
    if (!res.success) { toast(res.message, 'danger'); return; }
    const d = res.data;
    document.getElementById('mva-affectation-id').value = affectationId;
    document.getElementById('mva-numero-serie').textContent = d.numero_serie;
    document.getElementById('mva-nomenclature').textContent = d.nomenclature;
    document.getElementById('mva-valeur').textContent = Number(d.prix_achat).toLocaleString('fr-FR') + ' XOF';
    document.getElementById('mva-site').textContent = d.site_nom;
    document.getElementById('mva-utilisateur').textContent = d.utilisateur_nom;
    document.getElementById('mva-proposeur').textContent = d.proposeur_nom;
    document.getElementById('mva-etape').textContent = d.etape_label;
    document.getElementById('mva-commentaire').value = '';
    document.getElementById('mva-err').style.display = 'none';
    document.getElementById('visa-affectation-modal').classList.add('open');
  });
}
function mvaFermer() { document.getElementById('visa-affectation-modal').classList.remove('open'); }
document.getElementById('visa-affectation-modal').addEventListener('click', e => { if (e.target === e.currentTarget) mvaFermer(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') mvaFermer(); });

function mvaViser(accepte) {
  const err = document.getElementById('mva-err');
  const commentaire = document.getElementById('mva-commentaire').value.trim();
  if (!accepte && !commentaire) {
    err.textContent = 'Le motif de rejet est obligatoire.'; err.style.display = 'block'; return;
  }
  if (accepte && !confirm('Confirmer votre visa favorable ?')) return;
  if (!accepte && !confirm('Confirmer le rejet de cette affectation ?')) return;

  mvPost({ action: 'viser_affectation', affectation_id: document.getElementById('mva-affectation-id').value, accepte: accepte ? '1' : '0', commentaire }).then(res => {
    if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
    toast(res.message, 'success');
    mvaFermer();
    setTimeout(() => location.reload(), 600);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  liveRefresh({ url: <?= json_encode(APP_URL . '/pages/achats/mes_visas.php?fragment=1') ?>, container: '#mv-live-zone', interval: 10000 });
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
