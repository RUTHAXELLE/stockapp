<?php
// ============================================================
//  pages/operations/point_journalier.php
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/point_emuci_corrections.php';
require_once __DIR__ . '/../../includes/inventaire.php';
require_once __DIR__ . '/../../includes/validation_stock.php';
require_once __DIR__ . '/../../includes/optoplate_comparaison.php';

// Comparaison OptoPlate disponible seulement une fois les deux migrations
// jouées (colonnes de désactivation + justification/écart sur le point).
function _pj_optoplate_comparaison_ok(): bool {
    return db_colonne_existe('import_optoplate', 'type_desactivation')
        && db_colonne_existe('op_points_journaliers', 'justification_superviseur');
}

require_auth();
require_permission('operations', 'can_read');

$user        = current_user();
$page_title  = 'Point Journalier';
$active_page = 'operations';

// Types véhicules
$types_v = db_fetch_all("SELECT * FROM op_types_vehicule ORDER BY ordre");
$types_map = array_column($types_v, null, 'id');

// Sites accessibles
// Colonne sites.operation présente ? (sql/migration_operation_sites_duplicata_postimmat.sql)
// — ignorée tant qu'elle n'est pas passée, pour ne pas casser la page.
$sites_operation_ok = db_colonne_existe('sites', 'operation');
$sites_list = db_fetch_all("SELECT id,nom,type" . ($sites_operation_ok ? ',operation' : "") . "
    FROM sites WHERE actif=1 AND type NOT IN ('entrepot') ORDER BY nom");
// Opération du site du coordinateur (champ fixe, pas de <select> à interroger côté JS)
$coord_site_operation = ($sites_operation_ok && ($user['role_slug'] ?? '') === 'coordinateur_site' && !empty($user['site_id']))
    ? (db_fetch_value("SELECT operation FROM sites WHERE id=?", [(int)$user['site_id']]) ?: 'primo')
    : 'primo';

// ── Vérifie si le coordinateur est bloqué par un écart GSB non résolu
// Colonnes rivets_gonflables_end / rivets_eclates_end présentes en base ?
// (sql/migration_rivets_endommages_par_type.sql) — la saisie ne doit pas
// échouer tant que la migration n'est pas passée sur Neon.
function _pj_rivets_end_par_type(): bool {
    return db_colonne_existe('op_points_journaliers', 'rivets_gonflables_end');
}
// Idem pour op_pmma_utilises.non_conformes (sql/migration_pmma_non_conformes.sql).
function _pj_pmma_nc(): bool {
    return db_colonne_existe('op_pmma_utilises', 'non_conformes');
}
// Idem pour les compteurs Duplicatas/Post-Immat
// (sql/migration_operation_sites_duplicata_postimmat.sql).
function _pj_reimmat_ok(): bool {
    return db_colonne_existe('op_points_journaliers', 'nb_vp_duplicata');
}
// Films non conformes par bobine et n° du consommable endommagé
// (sql/migration_films_nc_numero_consommable.sql).
function _pj_films_nc(): bool {
    return db_colonne_existe('op_films_utilises', 'non_conformes');
}
function _pj_endo_numero(): bool {
    return db_colonne_existe('op_endommagements', 'numero_consommable');
}

// Règle métier : un point ne part pas en validation sans films utilisés,
// rivets posés ET PMMA utilisés. S'il manque du stock sur le site, le
// coordinateur garde son point en brouillon (visible de lui seul) jusqu'au
// réapprovisionnement, puis le complète.
function _pj_elements_manquants(array $point): array {
    // Journée sans production confirmée par le coordinateur (motif
    // obligatoire) : point à zéro, dispensé de la règle — c'est le
    // superviseur qui la confirme.
    if (!empty($point['sans_production'])) return [];
    $manque = [];
    if ((int)db_fetch_value("SELECT COALESCE(SUM(films_utilises),0) FROM op_films_utilises WHERE point_id=?", [(int)$point['id']]) === 0)
        $manque[] = 'films utilisés';
    if ((int)($point['rivets_utilises'] ?? 0) === 0)
        $manque[] = 'rivets posés';
    if ((int)db_fetch_value("SELECT COALESCE(SUM(utilises),0) FROM op_pmma_utilises WHERE point_id=?", [(int)$point['id']]) === 0)
        $manque[] = 'PMMA utilisés';
    return $manque;
}
// Plaques réellement posées sur les duplicatas VP / camions
// (sql/migration_duplicata_plaques*.sql) : 1 ou 2 par véhicule.
function _pj_dup_plaques_ok(): bool {
    return db_colonne_existe('op_points_journaliers', 'plaques_vp_duplicata');
}
// Journée sans production (sql/migration_point_sans_production*.sql).
function _pj_sans_prod_ok(): bool {
    return db_colonne_existe('op_points_journaliers', 'sans_production');
}
const PJ_MOTIFS_SANS_PROD = [
    'jour_ferie'          => 'Jour férié',
    'panne_systeme'       => 'Panne du système',
    'coupure_electricite' => "Coupure d'électricité",
    'absence_personnel'   => 'Absence de personnel',
    'rupture_stock'       => 'Rupture de stock',
    'site_ferme'          => 'Site fermé',
    'autre'               => 'Autre',
];
function _pj_libelle_sans_prod(array $p): string {
    $lib = PJ_MOTIFS_SANS_PROD[$p['motif_sans_production'] ?? ''] ?? 'Motif non précisé';
    $com = trim((string)($p['commentaire_sans_production'] ?? ''));
    return $com !== '' ? "$lib — $com" : $lib;
}
function _pj_msg_manquants(array $manque): string {
    return "Impossible : ce point ne comporte aucun " . implode(', ', $manque) . ". Un point doit contenir des films, des rivets et des PMMA. "
         . "S'il manque du stock sur le site, gardez le point en brouillon jusqu'à réception, puis modifiez-le pour compléter.";
}

// Remet dans le stock du site tout ce qu'un point avait retiré (films des
// bobines, rivets, PMMA utilisés/endommagés/non conformes). Le stock est
// déduit dès l'enregistrement, brouillon compris : on l'appelle avant de
// réécrire un point modifié et avant de supprimer un brouillon.
// $deja_remis : point rejeté, dont le rejet a déjà rendu films restants,
// rivets et PMMA — on ne fait plus que décompter les compteurs « consommés »
// des bobines, que le rejet ne touche pas, sans rien remettre une 2e fois.
// $films_recomptes : point daté d'avant un inventaire de bobines validé du
// site — ses films ne sont pas rendus aux bobines, le comptage en a déjà
// tenu compte (cf. inv_bobines_recompte_apres()) ; rivets et PMMA, eux, si.
function _pj_restaurer_stock(int $point_id, int $site_id, int $riv_gonfl, int $riv_eclate, bool $deja_remis = false, bool $films_recomptes = false): void {
    $old_films = db_fetch_all("SELECT * FROM op_films_utilises WHERE point_id=?", [$point_id]);
    foreach ($old_films as $of) {
        $total_restore = (int)$of['films_utilises'] + (int)$of['films_endommages'];   // non conformes : jamais retirés
        if ($total_restore > 0 && ($deja_remis || $films_recomptes)) {
            db_query(
                "UPDATE op_bobines SET
                 films_utilises   = GREATEST(0, films_utilises   - ?),
                 films_endommages = GREATEST(0, films_endommages - ?)
                 WHERE id=?",
                [(int)$of['films_utilises'], (int)$of['films_endommages'], (int)$of['bobine_id']]
            );
        } elseif ($total_restore > 0) {
            db_query(
                "UPDATE op_bobines SET
                 films_utilises   = GREATEST(0, films_utilises   - ?),
                 films_endommages = GREATEST(0, films_endommages - ?),
                 films_restants   = films_restants + ?,
                 statut = CASE WHEN statut='epuisee' AND films_restants + ? > 0 THEN 'en_cours' ELSE statut END
                 WHERE id=?",
                [(int)$of['films_utilises'], (int)$of['films_endommages'], $total_restore, $total_restore, (int)$of['bobine_id']]
            );
        }
    }
    if ($deja_remis) return;
    if ($riv_gonfl > 0)
        db_query("UPDATE op_stock_rivets SET quantite = quantite + ? WHERE site_id=? AND type_rivet='gonflable'",
            [$riv_gonfl, $site_id]);
    if ($riv_eclate > 0)
        db_query("UPDATE op_stock_rivets SET quantite = quantite + ? WHERE site_id=? AND type_rivet='eclate'",
            [$riv_eclate, $site_id]);
    $old_pmma = db_fetch_all("SELECT * FROM op_pmma_utilises WHERE point_id=?", [$point_id]);
    foreach ($old_pmma as $op_) {
        $total_pm_restore = (int)$op_['utilises'] + (int)$op_['endommages'] + (int)($op_['non_conformes'] ?? 0);
        if ($total_pm_restore > 0)
            db_query("UPDATE stock_pmma_site SET quantite = quantite + ? WHERE site_id=? AND type_pmma=?",
                [$total_pm_restore, $site_id, $op_['type_pmma']]);
    }
}

// ── RÈGLE DE STOCK (21/10/2026) : seul le point 18h VALIDÉ par le
// superviseur retire du stock (films des bobines, rivets, PMMA). Brouillon,
// point soumis, modification demandée, points 9h / 13h : aucun mouvement.
// op_points_journaliers.stock_deduit :
//   0 = rien n'est retiré ;
//   1 = stock retiré par la validation 18h (règle actuelle) ;
//   2 = retiré sous l'ancienne règle (dès l'enregistrement), posé par
//       sql/migration_point_stock_validation.sql : ACQUIS DÉFINITIVEMENT.
//       Le stock a depuis été recalé (validations GSB, imports EMUCI,
//       inventaires) : le remettre gonflerait un stock juste. Ces points ne
//       sont donc jamais remis en stock ni retirés une 2e fois.
// Tant que la migration n'est pas passée, l'ancien fonctionnement reste actif.
function _pj_regle_18h(): bool {
    return db_colonne_existe('op_points_journaliers', 'stock_deduit');
}

// Retire du stock ce que contient un point (au moment de la validation
// superviseur du point 18h). Vérifie d'abord que tout est disponible : rien
// n'est retiré si un seul élément manque (Exception, à appeler dans une
// transaction).
function _pj_deduire_stock(array $point): void {
    $point_id = (int)$point['id'];
    $site_id  = (int)$point['site_id'];
    $manques  = [];
    // Garde-fou inventaire (vps) : un inventaire validé après la date du point
    // a déjà recompté les bobines — seuls leurs compteurs de consommation
    // bougent, films_restants n'est ni contrôlé ni retiré.
    $films_recomptes = inv_bobines_recompte_apres($site_id, (string)$point['date_point']) !== null;

    $films = db_fetch_all(
        "SELECT fu.*, b.numero, b.films_restants FROM op_films_utilises fu
         JOIN op_bobines b ON b.id = fu.bobine_id WHERE fu.point_id=? FOR UPDATE", [$point_id]);
    $besoin_bob = [];
    foreach ($films as $f) {
        $t = (int)$f['films_utilises'] + (int)$f['films_endommages'];   // non conformes : jamais retirés
        $besoin_bob[(int)$f['bobine_id']] = ($besoin_bob[(int)$f['bobine_id']] ?? 0) + $t;
    }
    foreach ($films as $f) {
        $b = (int)$f['bobine_id'];
        if (!$films_recomptes && isset($besoin_bob[$b]) && $besoin_bob[$b] > (int)$f['films_restants'])
            $manques[$b] = "bobine {$f['numero']} : {$f['films_restants']} films restants, {$besoin_bob[$b]} demandés";
    }
    $g = (int)($point['rivets_gonflables'] ?? 0);
    $e = (int)($point['rivets_eclates'] ?? 0);
    $stock_g = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='gonflable'", [$site_id]) ?? 0);
    $stock_e = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='eclate'", [$site_id]) ?? 0);
    if ($g > $stock_g) $manques[] = "rivets gonflables : $stock_g disponibles, $g demandés";
    if ($e > $stock_e) $manques[] = "rivets éclatés : $stock_e disponibles, $e demandés";
    $pmma = db_fetch_all("SELECT * FROM op_pmma_utilises WHERE point_id=?", [$point_id]);
    foreach ($pmma as $pm) {
        $t = (int)$pm['utilises'] + (int)$pm['endommages'] + (int)($pm['non_conformes'] ?? 0);
        $dispo = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM stock_pmma_site WHERE site_id=? AND type_pmma=?",
                       [$site_id, $pm['type_pmma']]) ?? 0);
        if ($t > $dispo) $manques[] = "PMMA {$pm['type_pmma']} : $dispo disponibles, $t demandés";
    }
    if ($manques)
        throw new Exception("Stock insuffisant pour valider ce point : " . implode(' ; ', $manques)
            . ". Le stock n'a pas été modifié — demandez une modification au coordinateur ou réapprovisionnez le site.");

    foreach ($films as $f) {
        $t = (int)$f['films_utilises'] + (int)$f['films_endommages'];   // non conformes : jamais retirés
        if ($t <= 0) continue;
        if ($films_recomptes) {
            db_query("UPDATE op_bobines SET films_utilises = films_utilises + ?, films_endommages = films_endommages + ?,
                      date_ouverture = COALESCE(date_ouverture, ?) WHERE id=?",
                [(int)$f['films_utilises'], (int)$f['films_endommages'], $point['date_point'], (int)$f['bobine_id']]);
            continue;
        }
        db_query("UPDATE op_bobines SET
                  films_utilises   = films_utilises + ?,
                  films_endommages = films_endommages + ?,
                  films_restants   = films_restants - ?,
                  statut = CASE WHEN films_restants - ? <= 0 THEN 'epuisee'
                                WHEN statut = 'epuisee' THEN statut ELSE 'en_cours' END,
                  date_ouverture = COALESCE(date_ouverture, ?)
                  WHERE id=?",
            [(int)$f['films_utilises'], (int)$f['films_endommages'], $t, $t, $point['date_point'], (int)$f['bobine_id']]);
    }
    if ($g > 0) db_query("UPDATE op_stock_rivets SET quantite = quantite - ? WHERE site_id=? AND type_rivet='gonflable'", [$g, $site_id]);
    if ($e > 0) db_query("UPDATE op_stock_rivets SET quantite = quantite - ? WHERE site_id=? AND type_rivet='eclate'", [$e, $site_id]);
    foreach ($pmma as $pm) {
        $t = (int)$pm['utilises'] + (int)$pm['endommages'] + (int)($pm['non_conformes'] ?? 0);
        if ($t > 0) db_query("UPDATE stock_pmma_site SET quantite = quantite - ? WHERE site_id=? AND type_pmma=?",
                             [$t, $site_id, $pm['type_pmma']]);
    }
    db_query("UPDATE op_points_journaliers SET stock_deduit=1 WHERE id=?", [$point_id]);
}

// Remet le stock d'un point dont le stock est retiré (stock_deduit = 1), puis
// le marque non retiré. Sans effet sinon.
function _pj_restaurer_si_deduit(array $point, bool $films_recomptes = false): bool {
    if ((int)($point['stock_deduit'] ?? 0) !== 1) return false;   // 0 : rien à remettre ; 2 : acquis
    _pj_restaurer_stock((int)$point['id'], (int)$point['site_id'],
        (int)($point['rivets_gonflables'] ?? 0), (int)($point['rivets_eclates'] ?? 0), false, $films_recomptes);
    db_query("UPDATE op_points_journaliers SET stock_deduit=0 WHERE id=?", [(int)$point['id']]);
    return true;
}

// Le coordinateur ne saisit pas de nouveau point tant que la journée
// précédente n'est pas close : son point 18h soumis, validé par le
// superviseur, puis son stock validé. On regarde le dernier point 18h d'un
// jour passé, BROUILLON COMPRIS : le filtre « statut != brouillon » laissait
// passer un point rejeté puis réenregistré sans être resoumis — il
// redevient brouillon, le contrôle remontait à la veille déjà validée et
// débloquait le coordinateur (constaté le 15/10/2026). Les points 9h/13h,
// qui restent en brouillon par construction, ne comptent pas. Chaque cas a
// son message : le message « GSB » unique parlait d'écart de stock à un
// coordinateur dont le point avait été rejeté par le superviseur.
function _site_bloque_ecart(int $site_id, string $date_today): ?string {
    $dernier_point = db_fetch_one(
        "SELECT date_point, statut, motif_rejet FROM op_points_journaliers
         WHERE site_id=? AND date_point < ? AND type_point='point_18h'
         ORDER BY date_point DESC LIMIT 1",
        [$site_id, $date_today]
    );
    if (!$dernier_point) return null;
    $date_prec = $dernier_point['date_point'];
    // Un inventaire de bobines validé depuis ce point a rétabli le stock du
    // site : la validation de ce jour-là ne décide plus de rien.
    if (inv_bobines_recompte_apres($site_id, $date_prec)) return null;
    $jour  = date('d/m/Y', strtotime($date_prec));
    $motif = trim((string)($dernier_point['motif_rejet'] ?? ''));
    switch ($dernier_point['statut']) {
        case 'brouillon':
            return $motif !== ''
                ? "📝 Votre point 18h du $jour, corrigé après une demande de modification, n'a pas été renvoyé. Renvoyez-le au superviseur avant de saisir un nouveau point."
                : "📝 Votre point 18h du $jour est resté en brouillon. Soumettez-le au superviseur (ou supprimez-le s'il est en trop) avant de saisir un nouveau point.";
        case 'rejete':
            return "✏️ Le superviseur a demandé une modification sur votre point 18h du $jour" . ($motif !== '' ? " — motif : $motif" : '')
                 . ". Corrigez-le et renvoyez-le avant de saisir un nouveau point.";
        case 'en_attente_validation':
        case 'suivi':
            return "⏳ Votre point 18h du $jour attend la validation du superviseur. Vous pourrez saisir un nouveau point une fois votre stock validé.";
    }
    $validation = db_fetch_one(
        "SELECT statut FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
        [$site_id, $date_prec]
    );
    if (!$validation) {
        if (!vsm_import_du($site_id, $date_prec))
            return "⏳ Votre point 18h du $jour est validé ; votre stock sera comparé dès l'import EMUCI de cette date. Vous pourrez saisir un nouveau point une fois votre stock validé.";
        return "⚠️ Votre stock du $jour n'a pas encore été validé par le GSB. Vous ne pouvez pas saisir un nouveau point journalier tant que les écarts ne sont pas résolus.";
    }
    if ($validation['statut'] === 'refuse')
        return "🚫 Le GSB a bloqué votre activité suite à un écart non résolu sur le stock du $jour. Contactez votre gestionnaire stock.";
    return null;
}

// ── AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── SAUVEGARDER POINT
    if ($action === 'save_point') {
        $site_id    = (int)($_POST['site_id']    ?? 0);
        $date_point = trim($_POST['date_point']  ?? date('Y-m-d'));
        $type_point = trim($_POST['type_point']  ?? 'final');
        $nb_vp      = (int)($_POST['nb_vp']      ?? 0);
        $nb_camion  = (int)($_POST['nb_camion']  ?? 0);
        $nb_semi    = (int)($_POST['nb_semi']    ?? 0);
        $nb_moto    = (int)($_POST['nb_moto']    ?? 0);
        // Duplicatas / Post-Immat (sites operation='reimmat_post') — jamais
        // fusionnés dans nb_vp/total_engins/total_plaques : ce sont des
        // compteurs de reporting distincts qui ne touchent pas les chiffres
        // Primo. Les rivets/films consommés restent dans les champs partagés
        // ci-dessous (stock unique, saisie globale).
        $nb_vp_dup      = (int)($_POST['nb_vp_duplicata']     ?? 0);
        $nb_camion_dup  = (int)($_POST['nb_camion_duplicata'] ?? 0);
        $nb_semi_dup    = (int)($_POST['nb_semi_duplicata']   ?? 0);
        $nb_moto_dup    = (int)($_POST['nb_moto_duplicata']   ?? 0);
        // Un duplicata VP / camion peut ne remplacer qu'une des deux plaques :
        // le coordinateur saisit les plaques posées (par défaut 2 par véhicule).
        $plq_vp_dup     = (isset($_POST['plaques_vp_duplicata'])     && $_POST['plaques_vp_duplicata']     !== '') ? (int)$_POST['plaques_vp_duplicata']     : $nb_vp_dup * 2;
        $plq_camion_dup = (isset($_POST['plaques_camion_duplicata']) && $_POST['plaques_camion_duplicata'] !== '') ? (int)$_POST['plaques_camion_duplicata'] : $nb_camion_dup * 2;
        $nb_vp_pi       = (int)($_POST['nb_vp_postimmat']     ?? 0);
        $nb_camion_pi   = (int)($_POST['nb_camion_postimmat'] ?? 0);
        $nb_semi_pi     = (int)($_POST['nb_semi_postimmat']   ?? 0);
        $nb_moto_pi     = (int)($_POST['nb_moto_postimmat']   ?? 0);
        $riv_gonfl      = (int)($_POST['rivets_gonflables']     ?? 0);
        $riv_gonfl_end  = (int)($_POST['rivets_gonflables_end'] ?? 0);
        $riv_eclate     = (int)($_POST['rivets_eclates']         ?? 0);
        $riv_eclate_end = (int)($_POST['rivets_eclates_end']    ?? 0);
        $riv_endomm     = $riv_gonfl_end + $riv_eclate_end;
        $total_gonfl    = $riv_gonfl  + $riv_gonfl_end;
        $total_eclate   = $riv_eclate + $riv_eclate_end;
        $rivets_util    = $riv_gonfl  + $riv_eclate;
        $np_conc    = (int)($_POST['non_poses_concessionnaires'] ?? 0);
        $np_usag    = (int)($_POST['non_poses_usagers'] ?? 0);
        $heures     = (float)($_POST['nb_heures'] ?? 8);
        $obs        = trim($_POST['observations'] ?? '');
        $films_data = json_decode($_POST['films_data'] ?? '[]', true);
        $pmma_data  = json_decode($_POST['pmma_data']  ?? '[]', true);
        // n° 2.1 CR PDG — une ligne par bobine endommagée, saisie dans le
        // pop-up déclenché par la quantité endommagée.
        $endo_data  = json_decode($_POST['endommagements_data'] ?? '[]', true);
        if (!is_array($endo_data)) $endo_data = [];

        // Journée sans production : tout est remis à zéro côté serveur, quoi
        // qu'envoie l'écran — pas de mouvement de stock, pas d'heures (le
        // jour ne dilue pas les moyennes V/H). Seules les observations restent.
        $sans_prod   = !empty($_POST['sans_production']);
        $motif_sp    = trim((string)($_POST['motif_sans_production'] ?? ''));
        $comment_sp  = trim((string)($_POST['commentaire_sans_production'] ?? ''));
        if ($sans_prod) {
            if (!_pj_sans_prod_ok())
                json_response(false, "Migration point_sans_production non jouée sur cette base.");
            if ($type_point !== 'point_18h')
                json_response(false, 'Une journée sans production se déclare dans le point 18h.');
            if (!isset(PJ_MOTIFS_SANS_PROD[$motif_sp]))
                json_response(false, 'Choisissez le motif de la journée sans production.');
            if ($motif_sp === 'autre' && $comment_sp === '')
                json_response(false, 'Précisez le motif « Autre » dans le commentaire.');
            $nb_vp = $nb_camion = $nb_semi = $nb_moto = 0;
            $nb_vp_dup = $nb_camion_dup = $nb_semi_dup = $nb_moto_dup = 0;
            $plq_vp_dup = $plq_camion_dup = 0;
            $nb_vp_pi = $nb_camion_pi = $nb_semi_pi = $nb_moto_pi = 0;
            $riv_gonfl = $riv_gonfl_end = $riv_eclate = $riv_eclate_end = 0;
            $riv_endomm = $total_gonfl = $total_eclate = $rivets_util = 0;
            $np_conc = $np_usag = 0;
            $heures = 0;
            $films_data = []; $pmma_data = []; $endo_data = [];
        } else {
            $motif_sp = ''; $comment_sp = '';
        }

        $types_valides = ['point_9h', 'point_13h', 'point_18h', 'final', 'intermediaire'];
        if (!$site_id)                              json_response(false, 'Veuillez sélectionner un site.');
        if (!$date_point)                           json_response(false, 'Veuillez saisir une date.');
        if (!in_array($type_point, $types_valides)) json_response(false, 'Veuillez sélectionner un type de point (9h, 13h ou 18h).');

        // ── Blocage coordinateur si écart non résolu par GSB
        if (($user['role_slug'] ?? '') === 'coordinateur_site') {
            $bloque = _site_bloque_ecart($site_id, $date_point);
            if ($bloque) json_response(false, $bloque);
        }

        // ── Garde-fou inventaire : point daté d'avant un inventaire de
        // bobines validé du site (oublié, corrigé ou repris après coup). La
        // production, les rivets et le PMMA s'enregistrent normalement ; les
        // films restants des bobines ne bougent plus — le comptage tient déjà
        // compte de ce que ce jour-là a consommé.
        $inv_recompte = inv_bobines_recompte_apres($site_id, $date_point);

        // Calculs automatiques
        $total_engins  = $nb_vp + $nb_camion + $nb_semi + $nb_moto;
        $total_plaques = ($nb_vp * 2) + ($nb_camion * 2) + ($nb_semi * 1) + ($nb_moto * 1);
        $total_engins_dup   = $nb_vp_dup + $nb_camion_dup + $nb_semi_dup + $nb_moto_dup;
        if (!_pj_dup_plaques_ok()) { $plq_vp_dup = $nb_vp_dup * 2; $plq_camion_dup = $nb_camion_dup * 2; }
        foreach ([['VP', $nb_vp_dup, $plq_vp_dup], ['camion', $nb_camion_dup, $plq_camion_dup]] as [$lib_d, $nb_d, $plq_d])
            if ($plq_d < $nb_d || $plq_d > 2 * $nb_d)
                json_response(false, "Duplicatas $lib_d : $plq_d plaque(s) pour $nb_d véhicule(s). Comptez 1 ou 2 plaques par véhicule (entre $nb_d et " . (2 * $nb_d) . ").");
        $total_plaques_dup  = $plq_vp_dup + $plq_camion_dup + ($nb_semi_dup * 1) + ($nb_moto_dup * 1);
        $total_engins_pi    = $nb_vp_pi + $nb_camion_pi + $nb_semi_pi + $nb_moto_pi;
        $total_plaques_pi   = ($nb_vp_pi * 2) + ($nb_camion_pi * 2) + ($nb_semi_pi * 1) + ($nb_moto_pi * 1);
        // V/H sur tous les engins posés : Primo/Re-Immatriculation +
        // Duplicatas + Post-Immat (15/10/2026 — sur un site en
        // Re-Immatriculation, la seule première section sous-estimait la
        // production horaire). Sans effet sur un site Primo (0 + 0).
        $moy_prod      = $heures > 0 ? round(($total_engins + $total_engins_dup + $total_engins_pi) / $heures, 1) : 0;

        // Pré-vérifier si c'est une mise à jour (pour ajuster le check stock en conséquence)
        $regle_18h = _pj_regle_18h();
        $existing_pre = db_fetch_one(
            "SELECT id, statut, rivets_gonflables, rivets_eclates" . ($regle_18h ? ", stock_deduit" : "") . "
             FROM op_points_journaliers WHERE site_id=? AND date_point=? AND type_point=?",
            [$site_id, $date_point, $type_point]
        );
        // Un point soumis ou validé ne se réécrit pas en ressaisissant la
        // même date : seul un brouillon ou un point rejeté (à corriger) le peut.
        if ($existing_pre && !in_array($existing_pre['statut'], ['brouillon', 'rejete'], true))
            json_response(false, "Un point existe déjà pour ce site, cette date et ce type (statut : {$existing_pre['statut']}). Il ne peut plus être modifié.");
        // Point rejeté : le rejet a déjà remis son stock en place. Il ne
        // faut pas le remettre une 2e fois en le corrigeant.
        // Règle 18h : le stock n'est remis que si ce point l'avait retiré
        // (point enregistré avant la règle) — stock_deduit le dit exactement.
        $deja_remis     = $regle_18h
            ? !($existing_pre && (int)$existing_pre['stock_deduit'] === 1)
            : ($existing_pre && $existing_pre['statut'] === 'rejete');
        $old_riv_gonfl  = ($existing_pre && !$deja_remis) ? (int)($existing_pre['rivets_gonflables'] ?? 0) : 0;
        $old_riv_eclate = ($existing_pre && !$deja_remis) ? (int)($existing_pre['rivets_eclates']    ?? 0) : 0;

        // Vérifier stock rivets par type (stock actuel + anciens rivets qui seront restaurés)
        $stock_gonfl  = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='gonflable'", [$site_id]) ?? 0) + $old_riv_gonfl;
        $stock_eclate = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='eclate'", [$site_id]) ?? 0) + $old_riv_eclate;
        $stock_rivets = $stock_gonfl + $stock_eclate;
        if ($total_gonfl > $stock_gonfl)
            json_response(false, "Stock rivets gonflables insuffisant. Disponible : $stock_gonfl, Nécessaires : $total_gonfl");
        if ($total_eclate > $stock_eclate)
            json_response(false, "Stock rivets éclatés insuffisant. Disponible : $stock_eclate, Nécessaires : $total_eclate");

        // Colonnes Duplicatas/Post-Immat présentes en base ? (migration
        // sql/migration_operation_sites_duplicata_postimmat.sql) — ignorées
        // tant qu'elle n'est pas passée, pour ne pas casser la saisie.
        $reimmat_ok = _pj_reimmat_ok();
        $reimmat_set_sql = $reimmat_ok ? ",
                    nb_vp_duplicata=?,nb_camion_duplicata=?,nb_semi_duplicata=?,nb_moto_duplicata=?,
                    total_engins_duplicata=?,total_plaques_duplicata=?,
                    nb_vp_postimmat=?,nb_camion_postimmat=?,nb_semi_postimmat=?,nb_moto_postimmat=?,
                    total_engins_postimmat=?,total_plaques_postimmat=?" : "";
        $reimmat_params = $reimmat_ok ? [
            $nb_vp_dup,$nb_camion_dup,$nb_semi_dup,$nb_moto_dup,
            $total_engins_dup,$total_plaques_dup,
            $nb_vp_pi,$nb_camion_pi,$nb_semi_pi,$nb_moto_pi,
            $total_engins_pi,$total_plaques_pi,
        ] : [];
        $reimmat_cols_sql    = $reimmat_ok ? ",
                     nb_vp_duplicata,nb_camion_duplicata,nb_semi_duplicata,nb_moto_duplicata,
                     total_engins_duplicata,total_plaques_duplicata,
                     nb_vp_postimmat,nb_camion_postimmat,nb_semi_postimmat,nb_moto_postimmat,
                     total_engins_postimmat,total_plaques_postimmat" : "";
        $reimmat_placeholders = $reimmat_ok ? ',' . implode(',', array_fill(0, 12, '?')) : "";

        db_begin();
        try {
            // Insérer ou mettre à jour le point
            $existing = db_fetch_one(
                "SELECT id FROM op_points_journaliers WHERE site_id=? AND date_point=? AND type_point=?",
                [$site_id, $date_point, $type_point]
            );

            if ($existing) {
                $point_id = $existing['id'];
                db_query("UPDATE op_points_journaliers SET
                    nb_vp=?,nb_camion=?,nb_semi=?,nb_moto=?,
                    total_engins=?,total_plaques=?,moyenne_prod=?,
                    rivets_utilises=?,rivets_endommages=?,
                    rivets_gonflables=?,rivets_eclates=?,
                    non_poses_concessionnaires=?,non_poses_usagers=?,
                    nb_heures_travail=?,observations=?,updated_at=NOW(),
                    statut = CASE WHEN statut='rejete' THEN 'brouillon' ELSE statut END
                    $reimmat_set_sql
                    WHERE id=?",
                    array_merge([$nb_vp,$nb_camion,$nb_semi,$nb_moto,
                     $total_engins,$total_plaques,$moy_prod,
                     $rivets_util,$riv_endomm,$total_gonfl,$total_eclate,
                     $np_conc,$np_usag,$heures,$obs],
                     $reimmat_params, [$point_id]));
                // Remettre en stock ce que l'ancienne version du point avait retiré
                if ($regle_18h) {
                    if (!$deja_remis) {
                        _pj_restaurer_stock((int)$point_id, $site_id, $old_riv_gonfl, $old_riv_eclate, false, $inv_recompte !== null);
                        db_query("UPDATE op_points_journaliers SET stock_deduit=0 WHERE id=?", [$point_id]);
                    }
                } else {
                    _pj_restaurer_stock((int)$point_id, $site_id, $old_riv_gonfl, $old_riv_eclate, $deja_remis, $inv_recompte !== null);
                }
                // Supprimer anciens films et PMMA
                db_query("DELETE FROM op_films_utilises WHERE point_id=?", [$point_id]);
                db_query("DELETE FROM op_pmma_utilises  WHERE point_id=?", [$point_id]);
                // n° 2.1 CR PDG — les déclarations d'endommagement suivent le
                // même sort que les films : sans cela, corriger un point en
                // retirant une bobine laisserait une déclaration orpheline
                // pointant sur une bobine absente du point.
                db_query("DELETE FROM op_endommagements WHERE point_id=?", [$point_id]);
                // n° 2.8 CR PDG — idem pour les observations, mais on épargne
                // celles déjà prises en charge : les supprimer effacerait le
                // travail du superviseur (responsable, dates, commentaire).
                db_query("DELETE FROM op_observations WHERE point_id=? AND statut='en_attente'", [$point_id]);
            } else {
                db_query("INSERT INTO op_points_journaliers
                    (site_id,date_point,type_point,nb_vp,nb_camion,nb_semi,nb_moto,
                     total_engins,total_plaques,moyenne_prod,rivets_utilises,rivets_endommages,
                     rivets_gonflables,rivets_eclates,
                     non_poses_concessionnaires,non_poses_usagers,nb_heures_travail,observations,created_by
                     $reimmat_cols_sql)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?$reimmat_placeholders)",
                    array_merge([$site_id,$date_point,$type_point,$nb_vp,$nb_camion,$nb_semi,$nb_moto,
                     $total_engins,$total_plaques,$moy_prod,$rivets_util,$riv_endomm,
                     $total_gonfl,$total_eclate,
                     $np_conc,$np_usag,$heures,$obs,$user['id']],
                     $reimmat_params));
                $point_id = (int)db_last_id();
                if (!$point_id) throw new Exception("Impossible de créer le point journalier.");
            }

            // Répartition des rivets endommagés par type : seul le total
            // (rivets_endommages) était gardé, on ne savait plus dire s'il
            // s'agissait de gonflables ou d'éclatés. Colonnes ajoutées par
            // sql/migration_rivets_endommages_par_type.sql — ignorées tant
            // qu'elle n'est pas passée, pour ne pas bloquer la saisie.
            if (_pj_rivets_end_par_type()) {
                db_query("UPDATE op_points_journaliers SET rivets_gonflables_end=?, rivets_eclates_end=? WHERE id=?",
                    [$riv_gonfl_end, $riv_eclate_end, $point_id]);
            }

            // Déduire rivets du stock site par type (ancienne règle seulement :
            // avec la règle 18h, le retrait se fait à la validation superviseur)
            if (!$regle_18h && $total_gonfl > 0)
                db_query("UPDATE op_stock_rivets SET quantite = GREATEST(0, quantite - ?) WHERE site_id=? AND type_rivet='gonflable'",
                    [$total_gonfl, $site_id]);
            if (!$regle_18h && $total_eclate > 0)
                db_query("UPDATE op_stock_rivets SET quantite = GREATEST(0, quantite - ?) WHERE site_id=? AND type_rivet='eclate'",
                    [$total_eclate, $site_id]);

            // Traiter PMMA par type
            $pmma_total_util   = 0;
            $pmma_total_endomm = 0;
            foreach ($pmma_data as $pd) {
                $type_pmma  = trim($pd['type_pmma'] ?? '');
                $pm_util    = (int)($pd['utilises']    ?? 0);
                $pm_endomm  = (int)($pd['endommages']  ?? 0);
                // Non conformes : PMMA reçus qui ne respectent pas les règles,
                // sortis du stock utilisable comme les endommagés. Ignorés si
                // la colonne n'existe pas encore (migration non passée).
                $pm_nc      = _pj_pmma_nc() ? max(0, (int)($pd['non_conformes'] ?? 0)) : 0;
                if (!$type_pmma || ($pm_util + $pm_endomm + $pm_nc) === 0) continue;
                $total_pm = $pm_util + $pm_endomm + $pm_nc;
                $stock_pm = (int)(db_fetch_value(
                    "SELECT COALESCE(quantite,0) FROM stock_pmma_site WHERE site_id=? AND type_pmma=?",
                    [$site_id, $type_pmma]
                ) ?? 0);
                if ($total_pm > $stock_pm)
                    throw new Exception("Stock PMMA $type_pmma insuffisant. Disponible : $stock_pm, Nécessaire : $total_pm");
                if (_pj_pmma_nc())
                    db_query("INSERT INTO op_pmma_utilises (point_id, type_pmma, utilises, endommages, non_conformes) VALUES (?,?,?,?,?)",
                        [$point_id, $type_pmma, $pm_util, $pm_endomm, $pm_nc]);
                else
                    db_query("INSERT INTO op_pmma_utilises (point_id, type_pmma, utilises, endommages) VALUES (?,?,?,?)",
                        [$point_id, $type_pmma, $pm_util, $pm_endomm]);
                if (!$regle_18h)
                    db_query("UPDATE stock_pmma_site SET quantite = GREATEST(0, quantite - ?) WHERE site_id=? AND type_pmma=?",
                        [$total_pm, $site_id, $type_pmma]);
                $pmma_total_util   += $pm_util;
                $pmma_total_endomm += $pm_endomm;
            }

            // Traiter films par bobine
            $films_detail = [];
            foreach ($films_data as $fd) {
                $bobine_id    = (int)($fd['bobine_id'] ?? 0);
                $tv_id = max(1, (int)($fd['type_vehicule_id'] ?? 1));
                $f_util       = (int)($fd['films_utilises'] ?? 0);
                $f_endomm     = (int)($fd['films_endommages'] ?? 0);
                // Films non conformes (reçus hors règles) : déclarés et tracés,
                // mais ne sortent PAS du stock de la bobine (décision du
                // 22/10/2026) — seuls utilisés + endommagés sont retirés.
                $f_nc         = _pj_films_nc() ? max(0, (int)($fd['films_non_conformes'] ?? 0)) : 0;
                if (!$bobine_id || ($f_util + $f_endomm + $f_nc) === 0) continue;

                $bobine = db_fetch_one("SELECT * FROM op_bobines WHERE id=?", [$bobine_id]);
                if (!$bobine) continue;

                $total_sortis = $f_util + $f_endomm;
                if ($inv_recompte === null && $bobine['films_restants'] < $total_sortis)
                    throw new Exception("Bobine {$bobine['numero']} : stock insuffisant ({$bobine['films_restants']} restants, {$total_sortis} demandés).");

                if (_pj_films_nc())
                    db_query("INSERT INTO op_films_utilises (point_id,bobine_id,type_vehicule_id,films_utilises,films_endommages,non_conformes)
                              VALUES (?,?,?,?,?,?)", [$point_id, $bobine_id, $tv_id, $f_util, $f_endomm, $f_nc]);
                else
                    db_query("INSERT INTO op_films_utilises (point_id,bobine_id,type_vehicule_id,films_utilises,films_endommages)
                              VALUES (?,?,?,?,?)", [$point_id, $bobine_id, $tv_id, $f_util, $f_endomm]);

                // Mettre à jour la bobine — ancienne règle seulement (après un inventaire validé : seuls
                // les compteurs de consommation bougent, cf. $inv_recompte)
                if (!$regle_18h && $inv_recompte !== null) {
                    db_query("UPDATE op_bobines SET
                              films_utilises=films_utilises+?,
                              films_endommages=films_endommages+?,
                              date_ouverture=COALESCE(date_ouverture,?)
                              WHERE id=?",
                        [$f_util, $f_endomm, $date_point, $bobine_id]);
                    $films_detail[] = "Bobine {$bobine['numero']} : $f_util utilisés, $f_endomm endommagés" . ($f_nc ? ", $f_nc non conformes" : '');
                    continue;
                }
                $new_restants = $bobine['films_restants'] - $total_sortis;
                $new_statut   = $new_restants <= 0 ? 'epuisee' : 'en_cours';
                if (!$regle_18h) db_query("UPDATE op_bobines SET
                          films_utilises=films_utilises+?,
                          films_endommages=films_endommages+?,
                          films_restants=?,
                          statut=?,
                          date_ouverture=COALESCE(date_ouverture,?)
                          WHERE id=?",
                    [$f_util, $f_endomm, $new_restants, $new_statut, $date_point, $bobine_id]);

                $films_detail[] = "Bobine {$bobine['numero']} : $f_util utilisés, $f_endomm endommagés" . ($f_nc ? ", $f_nc non conformes" : '');
            }

            // ── n° 2.1 CR PDG — déclarations d'endommagement
            // Chaque unité endommagée porte sa justification : film (par
            // bobine), et — depuis sql/migration_endommagements_rivets_pmma.sql
            // — rivet (par type) et PMMA (par type). Clé de groupe :
            // « film:<bobine_id> », « rivet:gonflable|eclate », « pmma:<type> ».
            // On n'accepte une déclaration que pour un groupe réellement
            // déclaré endommagé dans ce point : la requête peut être forgée.
            $endo_multi = db_colonne_existe('op_endommagements', 'objet');
            $attendus = [];   // clé -> nombre d'unités endommagées
            $libelles = [];   // clé -> libellé pour les messages
            foreach ($films_data as $fd) {
                if ((int)($fd['films_endommages'] ?? 0) > 0) {
                    $k = 'film:' . (int)$fd['bobine_id'];
                    $attendus[$k] = (int)$fd['films_endommages'];
                    $libelles[$k] = 'film(s) sur une bobine';
                }
            }
            if ($endo_multi) {
                if ($riv_gonfl_end  > 0) { $attendus['rivet:gonflable'] = $riv_gonfl_end;  $libelles['rivet:gonflable'] = 'rivet(s) gonflable(s)'; }
                if ($riv_eclate_end > 0) { $attendus['rivet:eclate']    = $riv_eclate_end; $libelles['rivet:eclate']    = 'rivet(s) éclaté(s)'; }
                foreach ($pmma_data as $pd) {
                    $t = trim($pd['type_pmma'] ?? ''); $n = (int)($pd['endommages'] ?? 0);
                    if ($t !== '' && $n > 0) { $attendus["pmma:$t"] = $n; $libelles["pmma:$t"] = "PMMA $t"; }
                }
            }
            // Étapes par objet (plan comparaison OptoPlate, 2026-10-15) : le
            // film perd "pose" pour les nouvelles déclarations (comparé
            // étape par étape à OptoPlate — vérification/percée/fissurée
            // remplacent et complètent l'ancienne liste), le PMMA se limite
            // à lamination/percée (non comparé), le rivet reste inchangé.
            // Les anciennes déclarations film en "pose" restent lisibles
            // (non réémises) : seule l'écriture est désormais bloquée.
            $etapes_par_objet = [
                'film'  => ['verification','impression','lamination','percee','fissuree','transport','stockage','autre'],
                'pmma'  => ['lamination','percee','transport','stockage','autre'],
                'rivet' => ['pose','impression','lamination','transport','stockage','autre'],
            ];
            // Cause en saisie libre (demande métier) : seule la longueur est
            // contrôlée, sur la taille réelle de la colonne — 30 tant que
            // sql/migration_endommagements_cause_libre.sql n'est pas passée.
            $cause_max = (int)(db_fetch_value(
                "SELECT character_maximum_length FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name='op_endommagements' AND column_name='cause'") ?: 255);

            // Une déclaration par unité endommagée : six films abîmés sur une
            // bobine peuvent l'avoir été à six moments et pour six causes.
            $rangs = [];   // clé -> compteur d'unités déjà déclarées
            foreach ($endo_data as $ed) {
                $objet = $ed['objet'] ?? 'film';
                $e_bobine = null; $sous_type = null;
                if ($objet === 'film') {
                    $e_bobine = (int)($ed['bobine_id'] ?? 0);
                    $k = "film:$e_bobine";
                } elseif ($objet === 'rivet' || $objet === 'pmma') {
                    $sous_type = trim((string)($ed['sous_type'] ?? ''));
                    $k = "$objet:$sous_type";
                } else {
                    continue;
                }
                if (!isset($attendus[$k])) continue;

                $rangs[$k] = ($rangs[$k] ?? 0) + 1;
                if ($rangs[$k] > $attendus[$k]) {
                    throw new Exception("Déclarations d'endommagement plus nombreuses que les {$libelles[$k]} déclarés endommagés.");
                }

                $e_personne = trim((string)($ed['personne'] ?? ''));
                $e_etape    = trim((string)($ed['etape'] ?? ''));
                $e_cause    = trim((string)($ed['cause'] ?? ''));
                if ($e_personne === '') throw new Exception("Déclaration d'endommagement : la personne concernée est obligatoire.");
                $etapes_ok = $etapes_par_objet[$objet] ?? $etapes_par_objet['rivet'];
                if (!in_array($e_etape, $etapes_ok, true)) throw new Exception("Déclaration d'endommagement : étape invalide pour cet élément.");
                if ($e_cause === '') throw new Exception("Déclaration d'endommagement : la cause est obligatoire.");
                if (mb_strlen($e_cause) > $cause_max) throw new Exception("Déclaration d'endommagement : cause trop longue ($cause_max caractères maximum).");

                $e_heure = trim((string)($ed['heure'] ?? ''));
                if ($e_heure !== '' && !preg_match('/^\d{2}:\d{2}$/', $e_heure)) $e_heure = '';
                $e_obs = trim((string)($ed['observations'] ?? ''));
                // N° du consommable : obligatoire pour un film endommagé.
                $e_numero = mb_substr(trim((string)($ed['numero_consommable'] ?? '')), 0, 100);
                if (_pj_endo_numero() && $objet === 'film' && $e_numero === '')
                    throw new Exception("Déclaration d'endommagement : le n° du consommable est obligatoire pour chaque film.");

                $cols = ['point_id','bobine_id','site_id','film_no','personne','etape','cause','heure','observations','created_by'];
                $vals = [$point_id, $e_bobine, $site_id, $rangs[$k],
                         $e_personne, $e_etape, $e_cause, $e_heure ?: null, $e_obs, $user['id']];
                if ($endo_multi)       { array_push($cols, 'objet', 'sous_type'); array_push($vals, $objet, $sous_type); }
                if (_pj_endo_numero()) { $cols[] = 'numero_consommable'; $vals[] = $e_numero !== '' ? $e_numero : null; }
                db_query("INSERT INTO op_endommagements (" . implode(',', $cols) . ")
                          VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", $vals);
            }

            // Chaque unité endommagée doit avoir sa déclaration : sans ce
            // contrôle, un envoi partiel laisserait des unités sans
            // traçabilité, ce que le module est précisément censé empêcher.
            foreach ($attendus as $k => $nb) {
                if (($rangs[$k] ?? 0) !== $nb) {
                    throw new Exception("Chaque endommagement doit être justifié : "
                        . ($rangs[$k] ?? 0) . " déclaration(s) pour $nb {$libelles[$k]}.");
                }
            }

            // ── n° 2.8 CR PDG — observations suivies
            // La colonne JSON reste écrite (elle sert au réaffichage du
            // formulaire et à la fiche PDF) ; ces lignes portent le workflow.
            // Les deux restent alignés parce qu'écrits dans la même
            // transaction. On ne recrée que les lignes encore en attente :
            // celles déjà prises en charge ont été épargnées par le DELETE.
            $obs_list = json_decode($obs ?: '[]', true);
            if (is_array($obs_list)) {
                $types_obs = ['info','alerte','relance','incident','urgence','autre'];
                foreach (array_values($obs_list) as $i => $o) {
                    $o_texte = trim((string)($o['texte'] ?? ''));
                    if ($o_texte === '') continue;
                    $o_type = (string)($o['type'] ?? 'info');
                    if (!in_array($o_type, $types_obs, true)) $o_type = 'info';
                    // MySQL : ON DUPLICATE KEY UPDATE n'a pas de WHERE. La
                    // condition de Postgres (ne réécrire qu'une observation
                    // encore en attente) passe dans un IF() par colonne :
                    // une observation déjà prise en charge garde son texte.
                    db_query(
                        "INSERT INTO op_observations (point_id,ordre,site_id,`type`,texte,created_by)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            `type` = IF(statut = 'en_attente', VALUES(`type`), `type`),
                            texte  = IF(statut = 'en_attente', VALUES(texte), texte)",
                        [$point_id, $i, $site_id, $o_type, $o_texte, $user['id']]
                    );
                }
            }

            if (_pj_dup_plaques_ok())
                db_query("UPDATE op_points_journaliers SET plaques_vp_duplicata=?, plaques_camion_duplicata=? WHERE id=?",
                    [$plq_vp_dup, $plq_camion_dup, $point_id]);
            if (_pj_sans_prod_ok())
                db_query("UPDATE op_points_journaliers SET sans_production=?, motif_sans_production=?, commentaire_sans_production=? WHERE id=?",
                    [$sans_prod ? 1 : 0, $sans_prod ? $motif_sp : null, $sans_prod && $comment_sp !== '' ? $comment_sp : null, $point_id]);

            audit_log($user['id'], 'CREATE', 'operations', $point_id,
                "Point journalier $type_point — Site:$site_id — $date_point — "
                . ($sans_prod ? "sans production (" . PJ_MOTIFS_SANS_PROD[$motif_sp] . ")" : "$total_engins engins"));
            db_commit();
            $msg_save = $regle_18h
                ? "Point enregistré. $total_engins engins, $total_plaques plaques. "
                  . ($type_point === 'point_18h'
                      ? "Le stock (films, rivets, PMMA) sera retiré à la validation du superviseur."
                      : "Point de suivi : il ne retire pas de stock (seul le point 18h validé le fait).")
                : "Point enregistré. $total_engins engins, $total_plaques plaques, $rivets_util rivets déduits.";
            if (!$regle_18h && $inv_recompte !== null)
                $msg_save .= " Films des bobines non déduits : " . inv_bobines_msg_recompte($inv_recompte) . ", qui en tient déjà compte.";
            $films_total_util = array_sum(array_map(fn($fd) => (int)($fd['films_utilises'] ?? 0), $films_data ?: []));
            $manque_save = array_keys(array_filter([
                'films utilisés' => $films_total_util === 0,
                'rivets posés'   => $rivets_util === 0,
                'PMMA utilisés'  => $pmma_total_util === 0,
            ]));
            if ($sans_prod)
                $msg_save = "Journée sans production enregistrée (" . PJ_MOTIFS_SANS_PROD[$motif_sp] . "). Soumettez le point au superviseur, qui la confirmera.";
            elseif ($manque_save)
                $msg_save .= " ⚠️ Aucun " . implode(', ', $manque_save) . " : le point reste en brouillon et ne pourra être soumis/validé qu'une fois complété.";
            json_response(true, $msg_save, [
                'point_id'     => $point_id,
                'total_engins' => $total_engins,
                'total_plaques'=> $total_plaques,
                'rivets_util'  => $rivets_util,
                'moy_prod'     => $moy_prod,
                'films_detail' => $films_detail,
            ]);
        } catch (Throwable $e) {
            // Throwable et pas seulement Exception : une erreur PHP (Error,
            // TypeError) passait à travers et renvoyait une page d'erreur au
            // lieu d'un JSON — le bouton restait bloqué sur « Enregistrement… ».
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    // ── CHARGER BOBINES D'UN SITE
    if ($action === 'get_bobines_site') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        // Uniquement les bobines EN UTILISATION pour le PJ
        $rows = db_fetch_all(
            "SELECT b.id, b.numero, b.type_code, b.serie, b.films_restants, b.stock_systeme,
                     b.statut, tv.libelle AS type_vehicule
             FROM op_bobines b
             LEFT JOIN op_types_vehicule tv ON tv.id=b.type_vehicule_id
             WHERE b.site_id=? AND b.statut='en_cours'
             ORDER BY b.serie, b.numero ASC",
            [$site_id]
        );
        json_response(true, '', $rows);
    }

    // ── CHARGER STOCK PMMA D'UN SITE
    if ($action === 'get_stock_pmma') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $rows = db_fetch_all(
            "SELECT type_pmma, quantite FROM stock_pmma_site WHERE site_id=? AND quantite > 0 ORDER BY type_pmma",
            [$site_id]
        );
        json_response(true, '', $rows);
    }

    // ── GET POINT EXISTANT
    if ($action === 'get_point') {
        $site_id    = (int)($_POST['site_id']   ?? 0);
        $date_point = trim($_POST['date_point'] ?? '');
        $type_point = trim($_POST['type_point'] ?? 'final');
        $point = db_fetch_one(
            "SELECT p.*, CONCAT(u.prenom,' ',u.nom) AS agent
             FROM op_points_journaliers p
             LEFT JOIN users u ON u.id=p.created_by
             WHERE p.site_id=? AND p.date_point=? AND p.type_point=?",
            [$site_id, $date_point, $type_point]
        );
        if (!$point) json_response(false, 'Aucun point pour cette date.');
        $point['films'] = db_fetch_all(
            "SELECT fu.*, b.numero AS bobine_num, b.type_code, tv.libelle AS type_veh
             FROM op_films_utilises fu
             JOIN op_bobines b ON b.id=fu.bobine_id
             JOIN op_types_vehicule tv ON tv.id=fu.type_vehicule_id
             WHERE fu.point_id=?", [$point['id']]
        );
        json_response(true, '', $point);
    }

    // ── STOCK RIVETS SITE
    if ($action === 'get_stock_rivets') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $gonfl  = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='gonflable'", [$site_id]) ?? 0);
        $eclate = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='eclate'", [$site_id]) ?? 0);
        json_response(true, '', ['stock' => $gonfl + $eclate, 'gonflable' => $gonfl, 'eclate' => $eclate]);
    }

    // ── DÉTAIL POINT (PMMA + films pour la vue détail)
    if ($action === 'get_point_detail') {
        $point_id = (int)($_POST['point_id'] ?? 0);
        $pmma  = db_fetch_all("SELECT * FROM op_pmma_utilises WHERE point_id=? ORDER BY type_pmma", [$point_id]);
        $films = db_fetch_all(
            "SELECT fu.*, b.numero AS bobine_num, b.type_code, tv.libelle AS type_veh
             FROM op_films_utilises fu
             JOIN op_bobines b ON b.id=fu.bobine_id
             LEFT JOIN op_types_vehicule tv ON tv.id=fu.type_vehicule_id
             WHERE fu.point_id=? ORDER BY b.numero",
            [$point_id]
        );
        json_response(true, '', ['pmma' => $pmma, 'films' => $films]);
    }

    // ── VALIDER POINT
    if ($action === 'load_point') {
        $point_id = (int)($_POST['point_id'] ?? 0);
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        $point['films'] = db_fetch_all("SELECT * FROM op_films_utilises WHERE point_id=?", [$point_id]);
        // Lignes PMMA et déclarations d'endommagement : rechargées dans le
        // formulaire, sinon une simple mise à jour du point effaçait la
        // consommation PMMA et obligeait à tout re-justifier.
        $point['pmma'] = db_fetch_all("SELECT * FROM op_pmma_utilises WHERE point_id=? ORDER BY type_pmma", [$point_id]);
        $point['endommagements'] = db_fetch_all(
            "SELECT * FROM op_endommagements WHERE point_id=? ORDER BY bobine_id, film_no, id", [$point_id]);
        json_response(true, '', ['point' => $point]);
    }

    // Coordinateur valide ses points 9h/13h
    if ($action === 'valider_point_coord') {
        $point_id = (int)($_POST['point_id'] ?? 0);
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        if (($point['created_by'] ?? 0) != $user['id']) json_response(false, 'Accès refusé.');
        if ($point['statut'] !== 'brouillon') json_response(false, 'Ce point ne peut plus être modifié.');
        // Le point 18h se soumet au superviseur, il ne s'autovalide pas :
        // l'écran ne propose ce bouton que pour 9h/13h, le serveur ne le
        // vérifiait pas — une validation par le coordinateur aurait ouvert
        // la comparaison du stock sans le superviseur.
        if (($point['type_point'] ?? '') === 'point_18h')
            json_response(false, 'Le point 18h se soumet au superviseur pour validation.');
        if ($manque = _pj_elements_manquants($point)) json_response(false, _pj_msg_manquants($manque));
        db_begin();
        try {
            // Règle 18h : un point 9h/13h ne retire pas de stock. S'il en avait
            // retiré (enregistré avant la règle), on le remet.
            if (_pj_regle_18h()) _pj_restaurer_si_deduit($point);
            db_query("UPDATE op_points_journaliers SET statut='valide', validated_by=?, validated_at=NOW() WHERE id=?",
                [$user['id'], $point_id]);
            db_commit();
        } catch (Throwable $e) { db_rollback(); json_response(false, 'Erreur : ' . $e->getMessage()); }
        json_response(true, 'Point validé avec succès.');
    }

    // Coordinateur soumet le point 18h au superviseur
    if ($action === 'soumettre_point') {
        $point_id = (int)($_POST['point_id'] ?? 0);
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        if (($point['created_by'] ?? 0) != $user['id']) json_response(false, 'Accès refusé.');
        if ($point['statut'] !== 'brouillon') json_response(false, 'Ce point a déjà été soumis.');
        if (($point['type_point'] ?? '') !== 'point_18h') json_response(false, 'Seul le point 18h peut être soumis au superviseur.');
        if ($manque = _pj_elements_manquants($point)) json_response(false, _pj_msg_manquants($manque));
        db_query("UPDATE op_points_journaliers SET statut='en_attente_validation' WHERE id=?", [$point_id]);
        // Notifier le superviseur
        $notif_targets = db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug IN ('admin','superadmin','superviseur_operation') AND u.actif=1"
        );
        foreach ($notif_targets as $t) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$t['id'], 'info', '📋 Point 18h à valider',
                 "Le coordinateur {$user['prenom']} {$user['nom']} a soumis son point journalier 18h pour validation.",
                 '/pages/operations/point_journalier.php']);
        }
        json_response(true, 'Point soumis au superviseur pour validation.');
    }

    // ── COMPARAISON OPTOPLATE avant validation (superviseur) — plan 15/10/2026
    if ($action === 'comparer_optoplate') {
        if (!in_array($user['role_slug'] ?? '', ['admin','superadmin','superviseur_operation']))
            json_response(false, 'Accès refusé.');
        if (!_pj_optoplate_comparaison_ok())
            json_response(false, "Comparaison indisponible : les migrations OptoPlate ne sont pas encore jouées sur cette base.");
        $point_id = (int)($_POST['point_id'] ?? 0);
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        $site_nom = db_fetch_value("SELECT nom FROM sites WHERE id=?", [(int)$point['site_id']]);
        // MySQL : plage horaire plutôt que ::date (PostgreSQL)
        $dernier_import = db_fetch_value(
            "SELECT DATE(MAX(date_installation)) FROM import_optoplate
              WHERE site_id=? AND date_installation >= ? AND date_installation < DATE_ADD(?, INTERVAL 1 DAY)",
            [(int)$point['site_id'], $point['date_point'], $point['date_point']]
        );
        json_response(true, '', [
            'comparaison'    => op_comparer_point($point),
            'site_nom'       => $site_nom,
            'import_present' => (bool)$dernier_import,
        ]);
    }

    if ($action === 'valider_point') {
        if (!in_array($user['role_slug'] ?? '', ['admin','superadmin','superviseur_operation']))
            json_response(false, 'Accès refusé.');
        $point_id = (int)($_POST['point_id'] ?? 0);
        $justification = trim($_POST['justification_superviseur'] ?? '');
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        if ($manque = _pj_elements_manquants($point))
            json_response(false, "Impossible de valider : ce point ne comporte aucun " . implode(', ', $manque) . ". Demandez une modification pour correction.");

        // Comparaison OptoPlate : écart tolérance 0 (plan 15/10/2026). Une
        // justification est exigée côté serveur, pas seulement côté écran —
        // la requête pourrait être rejouée sans être passée par la comparaison.
        $ecart_json = null;
        if (($point['type_point'] ?? '') === 'point_18h' && _pj_optoplate_comparaison_ok()) {
            $comparaison = op_comparer_point($point);
            if ($comparaison['has_ecart'] && $justification === '')
                json_response(false, "Un écart a été constaté avec OptoPlate : une justification est obligatoire pour valider malgré cet écart (ou demandez une modification du point).");
            $ecart_json = json_encode($comparaison);
        }

        db_begin();
        try {
            // Règle 18h : la validation du point 18h retire le stock (une seule
            // fois — un point enregistré avant la règle l'a déjà retiré). Les
            // autres types ne retirent rien ; s'ils avaient retiré, on remet.
            if (_pj_regle_18h()) {
                if (($point['type_point'] ?? '') === 'point_18h') {
                    if ((int)($point['stock_deduit'] ?? 0) === 0) _pj_deduire_stock($point);   // 1 ou 2 : déjà retiré
                } else {
                    _pj_restaurer_si_deduit($point);
                }
            }
            db_query(
                "UPDATE op_points_journaliers SET statut='valide',validated_by=?,validated_at=NOW()"
                . (_pj_optoplate_comparaison_ok() ? ",justification_superviseur=?,ecart_optoplate=?" : "")
                . " WHERE id=?",
                _pj_optoplate_comparaison_ok()
                    ? [$user['id'], $justification ?: null, $ecart_json, $point_id]
                    : [$user['id'], $point_id]
            );
            db_commit();
        } catch (Throwable $e) {
            db_rollback();
            json_response(false, $e->getMessage());
        }
        // Notifier le coordinateur
        if (!empty($point['created_by'])) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$point['created_by'], 'info', '✅ Point journalier validé',
                 "Votre point journalier du ".fmt_date($point['date_point'],'d/m/Y')." a été validé par le superviseur.",
                 '/pages/operations/point_journalier.php']);
        }
        audit_log($user['id'], 'UPDATE', 'operations', $point_id, "Validation point journalier #$point_id");
        // Déclencheur de la validation stock jour : si l'import EMUCI de
        // cette date est déjà là, la comparaison se fait maintenant (sinon
        // c'est l'import qui la lancera). Un incident de comparaison ne doit
        // pas faire croire que la validation du point a échoué : elle est
        // faite, le GSB pourra relancer la comparaison.
        $msg_stock = '';
        if (($point['type_point'] ?? '') === 'point_18h') {
            try {
                $eval = vsm_evaluer_site((int)$point['site_id'], (string)$point['date_point'], (int)$user['id'], 'validation_superviseur');
                $msg_stock = match ($eval['statut']) {
                    'valide_auto' => ' Stock comparé à l\'import EMUCI : conforme, validé automatiquement.',
                    'ecart'       => " Stock comparé à l'import EMUCI : {$eval['nb_ecarts']} écart(s), le GSB est alerté.",
                    'en_attente'  => ' Le stock sera comparé dès l\'import EMUCI de cette date.',
                    default       => '',
                };
            } catch (Throwable $e) {
                error_log('validation stock jour, point #' . $point_id . ' : ' . $e->getMessage());
                $msg_stock = ' La comparaison du stock n\'a pas pu être faite : le GSB peut la relancer depuis la validation stock jour.';
            }
        }
        json_response(true, 'Point validé.' . $msg_stock);
    }

    // ── REJETER POINT (superviseur) — reverse les déductions stock
    // Supprimer un brouillon : le stock ayant été retiré dès
    // l'enregistrement, il est remis en place avant la suppression (sinon un
    // brouillon abandonné laissait le stock diminué). Réservé à l'auteur,
    // comme la modification.
    if ($action === 'supprimer_brouillon') {
        $point_id = (int)($_POST['point_id'] ?? 0);
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        if (($point['created_by'] ?? 0) != $user['id']) json_response(false, 'Accès refusé : seul l\'auteur peut supprimer son brouillon.');
        if ($point['statut'] !== 'brouillon') json_response(false, 'Seul un point en brouillon peut être supprimé.');
        // Ne pas effacer le travail d'un superviseur sur une observation déjà prise en charge.
        $obs_suivies = (int)db_fetch_value(
            "SELECT COUNT(*) FROM op_observations WHERE point_id=? AND statut <> 'en_attente'", [$point_id]);
        if ($obs_suivies > 0)
            json_response(false, 'Impossible : une observation de ce point est déjà prise en charge par un superviseur.');

        // Garde-fou inventaire : un brouillon d'avant le comptage ne rend pas
        // ses films aux bobines (cf. save_point).
        $inv_recompte = inv_bobines_recompte_apres((int)$point['site_id'], (string)$point['date_point']);

        db_begin();
        try {
            if (_pj_regle_18h()) {
                $remis = _pj_restaurer_si_deduit($point, $inv_recompte !== null);
            } else {
                _pj_restaurer_stock($point_id, (int)$point['site_id'],
                    (int)($point['rivets_gonflables'] ?? 0), (int)($point['rivets_eclates'] ?? 0),
                    false, $inv_recompte !== null);
                $remis = true;
            }
            // Lignes liées (films, PMMA, endommagements, observations…) :
            // ON DELETE CASCADE ; supprimées explicitement pour ne pas
            // dépendre des contraintes de chaque base.
            db_query("DELETE FROM op_films_utilises WHERE point_id=?", [$point_id]);
            db_query("DELETE FROM op_pmma_utilises  WHERE point_id=?", [$point_id]);
            db_query("DELETE FROM op_endommagements WHERE point_id=?", [$point_id]);
            db_query("DELETE FROM op_observations   WHERE point_id=?", [$point_id]);
            db_query("DELETE FROM op_points_journaliers WHERE id=?", [$point_id]);
            audit_log($user['id'], 'DELETE', 'operations', $point_id,
                "Suppression brouillon point journalier #$point_id du {$point['date_point']} — stock films, rivets et PMMA restauré");
            db_commit();
            json_response(true, !$remis ? 'Brouillon supprimé.'
                : ($inv_recompte !== null
                    ? 'Brouillon supprimé. Rivets et PMMA remis en stock ; films des bobines inchangés : '
                      . inv_bobines_msg_recompte($inv_recompte) . '.'
                    : 'Brouillon supprimé. Films, rivets et PMMA remis en stock.'));
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    // Historiquement nommée "rejet" ; c'est en réalité une demande de
    // modification adressée au coordinateur, qui peut corriger le point
    // (statut repasse en brouillon à la prochaine sauvegarde) et le
    // renvoyer pour validation — le point n'est jamais définitivement
    // perdu. Le statut interne 'rejete' et la colonne motif_rejet sont
    // conservés tels quels (pas de migration), seul l'habillage change.
    if ($action === 'demander_modification_point') {
        if (!in_array($user['role_slug'] ?? '', ['admin','superadmin','superviseur_operation']))
            json_response(false, 'Accès refusé.');
        $point_id   = (int)($_POST['point_id']    ?? 0);
        $motif      = trim($_POST['motif_rejet']  ?? '');
        if (!$motif) json_response(false, 'Motif de la demande de modification obligatoire.');
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        if (!in_array($point['statut'], ['en_attente_validation','brouillon']))
            json_response(false, 'Impossible de demander une modification sur ce point (statut : '.$point['statut'].').');

        // Garde-fou inventaire : point d'avant le comptage, ses films ne sont
        // pas rendus aux bobines (cf. save_point, qui ne les redéduira pas).
        $inv_recompte = inv_bobines_recompte_apres((int)$point['site_id'], (string)$point['date_point']);

        db_begin();
        try {
          $regle_18h_dm = _pj_regle_18h();
          // Règle 18h : seul un point dont le stock est retiré (enregistré
          // avant la règle) a quelque chose à remettre.
          $stock_remis = $regle_18h_dm ? (int)($point['stock_deduit'] ?? 0) === 1 : true;   // 2 : acquis, jamais remis
          if ($stock_remis) {
            // Restaurer les films dans chaque bobine
            $films = $inv_recompte === null
                ? db_fetch_all("SELECT * FROM op_films_utilises WHERE point_id=?", [$point_id])
                : [];
            foreach ($films as $f) {
                $total = (int)$f['films_utilises'] + (int)$f['films_endommages'];   // non conformes : jamais retirés
                if ($total > 0) {
                    db_query(
                        "UPDATE op_bobines SET films_restants = films_restants + ?,
                         statut = CASE WHEN statut = 'en_cours' THEN statut   -- reste en utilisation
                                       WHEN films_restants + ? >= qte_initiale THEN 'en_stock'
                                       WHEN films_restants + ? > 0 THEN 'en_cours'
                                       ELSE statut END
                         WHERE id=?",
                        [$total, $total, $total, $f['bobine_id']]
                    );
                }
            }

            // Restaurer les rivets
            $riv_gonfl  = (int)($point['rivets_gonflables'] ?? 0);
            $riv_eclate = (int)($point['rivets_eclates']    ?? 0);
            if ($riv_gonfl > 0)
                db_query("UPDATE op_stock_rivets SET quantite = quantite + ? WHERE site_id=? AND type_rivet='gonflable'",
                    [$riv_gonfl, $point['site_id']]);
            if ($riv_eclate > 0)
                db_query("UPDATE op_stock_rivets SET quantite = quantite + ? WHERE site_id=? AND type_rivet='eclate'",
                    [$riv_eclate, $point['site_id']]);

            // Restaurer les PMMA
            $pmmats = db_fetch_all("SELECT * FROM op_pmma_utilises WHERE point_id=?", [$point_id]);
            foreach ($pmmats as $pm) {
                $total_pm = (int)$pm['utilises'] + (int)$pm['endommages'] + (int)($pm['non_conformes'] ?? 0);
                if ($total_pm > 0) {
                    db_query("UPDATE stock_pmma_site SET quantite = quantite + ? WHERE site_id=? AND type_pmma=?",
                        [$total_pm, $point['site_id'], $pm['type_pmma']]);
                }
            }
            if ($regle_18h_dm) db_query("UPDATE op_points_journaliers SET stock_deduit=0 WHERE id=?", [$point_id]);
          }

            // Marquer en modification demandée (statut DB inchangé : 'rejete')
            db_query("UPDATE op_points_journaliers SET statut='rejete', motif_rejet=?, validated_by=?, validated_at=NOW() WHERE id=?",
                [$motif, $user['id'], $point_id]);

            // Notifier le coordinateur
            $coord = db_fetch_one("SELECT u.id FROM users u WHERE u.id=?", [$point['created_by']]);
            if ($coord) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$coord['id'], 'info', '✏️ Modification demandée sur votre point journalier',
                     "Le superviseur demande une modification de votre point journalier du ".fmt_date($point['date_point'],'d/m/Y').". Motif : $motif — corrigez-le puis renvoyez-le pour validation.",
                     '/pages/operations/point_journalier.php']);
            }

            audit_log($user['id'], 'UPDATE', 'operations', $point_id, "Demande de modification sur le point journalier #$point_id — stock restauré");
            db_commit();
            json_response(true, !$stock_remis ? 'Demande de modification envoyée.'
                : ($inv_recompte !== null
                    ? 'Demande de modification envoyée. Rivets et PMMA restaurés ; films des bobines inchangés : '
                      . inv_bobines_msg_recompte($inv_recompte) . '.'
                    : 'Demande de modification envoyée. Stock films, rivets et PMMA restaurés.'));
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : '.$e->getMessage());
        }
    }

    // ── CORRECTIONS BOBINES — liste pour le coordinateur
    if ($action === 'get_corrections_coord') {
        $site_id = (int)($_POST['site_id'] ?? ($user['site_id'] ?? 0));
        if (!$site_id) json_response(false, 'Site introuvable.');
        $rows = db_fetch_all(
            "SELECT cb.*, b.numero AS bobine_num,
                    CONCAT(gsb.prenom,' ',gsb.nom) AS gsb_nom
             FROM corrections_bobines cb
             JOIN op_bobines b ON b.id = cb.bobine_id
             JOIN users gsb   ON gsb.id = cb.gsb_id
             WHERE cb.site_id = ? AND cb.statut = 'en_attente'
             ORDER BY cb.created_at DESC",
            [$site_id]
        );
        json_response(true, '', $rows);
    }

    // ── CORRECTIONS BOBINES — réponse du coordinateur
    if ($action === 'repondre_correction') {
        $role_user = $user['role_slug'] ?? '';
        if ($role_user !== 'coordinateur_site' && !in_array($role_user, ['admin','superadmin']))
            json_response(false, 'Accès refusé.');
        $correction_id  = (int)($_POST['correction_id'] ?? 0);
        $reponse        = trim($_POST['reponse']         ?? '');
        $films_final    = isset($_POST['films_final']) && $_POST['films_final'] !== '' ? (int)$_POST['films_final'] : null;
        $reponse_coord  = trim($_POST['reponse_coord']   ?? '');

        if (!$correction_id) json_response(false, 'Correction introuvable.');
        if (!in_array($reponse, ['approuvee','contreproposee','refusee'])) json_response(false, 'Réponse invalide.');
        if ($reponse !== 'refusee' && $films_final === null) json_response(false, 'La valeur finale est obligatoire.');
        if ($reponse === 'refusee' && !$reponse_coord) json_response(false, 'Veuillez expliquer le refus.');

        $corr = db_fetch_one("SELECT * FROM corrections_bobines WHERE id=? AND statut='en_attente'", [$correction_id]);
        if (!$corr) json_response(false, 'Demande introuvable ou déjà traitée.');
        // Garde-fou inventaire : correction d'un jour d'avant le comptage —
        // le point est corrigé, les films restants de la bobine ne bougent pas.
        $inv_recompte = inv_bobines_recompte_apres((int)$corr['site_id'], (string)$corr['date_point']);

        db_begin();
        try {
            if ($reponse !== 'refusee') {
                // Appliquer la correction sur op_films_utilises
                $old_fu = db_fetch_one(
                    "SELECT films_utilises FROM op_films_utilises WHERE point_id=? AND bobine_id=?",
                    [$corr['point_id'], $corr['bobine_id']]
                );
                if ($old_fu) {
                    $delta = $films_final - (int)$old_fu['films_utilises'];
                    db_query(
                        "UPDATE op_films_utilises SET films_utilises=? WHERE point_id=? AND bobine_id=?",
                        [$films_final, $corr['point_id'], $corr['bobine_id']]
                    );
                    // Règle 18h : la bobine n'est ajustée que si le stock de ce
                    // point est effectivement retiré (point 18h validé).
                    $pt_deduit = !_pj_regle_18h()
                        || (int)db_fetch_value("SELECT stock_deduit FROM op_points_journaliers WHERE id=?", [$corr['point_id']]) >= 1;
                    if ($delta !== 0 && $pt_deduit && $inv_recompte !== null) {
                        db_query(
                            "UPDATE op_bobines SET films_utilises = GREATEST(0, films_utilises + ?) WHERE id=?",
                            [$delta, $corr['bobine_id']]
                        );
                    } elseif ($delta !== 0 && $pt_deduit) {
                        db_query(
                            "UPDATE op_bobines SET
                             films_utilises = GREATEST(0, films_utilises + ?),
                             films_restants = films_restants - ?
                             WHERE id=?",
                            [$delta, $delta, $corr['bobine_id']]
                        );
                    }
                }
                // Passer la validation du jour en "réajusté"
                db_query(
                    "UPDATE validations_stock_matin SET statut='reajuste' WHERE site_id=? AND date_validation=?",
                    [$corr['site_id'], $corr['date_point']]
                );
            }

            // Mettre à jour la correction
            db_query(
                "UPDATE corrections_bobines SET statut=?, films_final=?, reponse_coord=?, coord_id=?, traite_at=NOW() WHERE id=?",
                [$reponse, $films_final, $reponse_coord ?: null, $user['id'], $correction_id]
            );

            // Notifier le GSB
            $coord_nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
            $bobine_num = db_fetch_value("SELECT numero FROM op_bobines WHERE id=?", [$corr['bobine_id']]) ?? '';
            $notif_msg = match($reponse) {
                'approuvee'      => "Le coordinateur $coord_nom a confirmé la correction sur la bobine $bobine_num. Valeur appliquée : $films_final films.",
                'contreproposee' => "Le coordinateur $coord_nom propose $films_final films pour la bobine $bobine_num. Motif : $reponse_coord",
                'refusee'        => "Le coordinateur $coord_nom a refusé la correction sur la bobine $bobine_num. Motif : $reponse_coord",
            };
            $notif_titre = match($reponse) {
                'approuvee'      => '✅ Correction confirmée',
                'contreproposee' => '🔄 Contre-proposition du coordinateur',
                'refusee'        => '❌ Correction refusée',
            };
            db_query(
                "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$corr['gsb_id'], 'info', $notif_titre, $notif_msg, '/pages/validation_stock_matin.php']
            );

            audit_log($user['id'], 'UPDATE', 'corrections_bobines', $correction_id,
                "Réponse coordinateur: $reponse, films_final: $films_final");
            db_commit();

            $msg = match($reponse) {
                'approuvee'      => $inv_recompte !== null
                    ? 'Correction confirmée. Point corrigé, films de la bobine inchangés : ' . inv_bobines_msg_recompte($inv_recompte) . '.'
                    : 'Correction confirmée. Le stock a été ajusté.',
                'contreproposee' => 'Contre-proposition envoyée au GSB.',
                'refusee'        => 'Refus enregistré. Le GSB a été notifié.',
            };
            json_response(true, $msg);
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    // ── CORRECTION POINT EMUCI — réponse du coordinateur
    if ($action === 'pec_accepter') {
        $role_user = $user['role_slug'] ?? '';
        if ($role_user !== 'coordinateur_site' && !in_array($role_user, ['admin','superadmin']))
            json_response(false, 'Accès refusé.');
        $corr_id = (int)($_POST['corr_id'] ?? 0);
        $corr = db_fetch_one("SELECT * FROM corrections_point_emuci WHERE id=? AND statut='en_attente'", [$corr_id]);
        if (!$corr) json_response(false, 'Demande introuvable ou déjà traitée.');
        if ($role_user === 'coordinateur_site' && (int)$corr['site_id'] !== (int)$user['site_id']) json_response(false, 'Accès refusé.');
        try {
            pec_accepter($corr, $user);
            audit_log($user['id'], 'UPDATE', 'corrections_point_emuci', $corr_id, 'Correction Point EMUCI acceptée');
            json_response(true, 'Correction acceptée.');
        } catch (Exception $e) { json_response(false, $e->getMessage()); }
    }

    if ($action === 'pec_contester') {
        $role_user = $user['role_slug'] ?? '';
        if ($role_user !== 'coordinateur_site' && !in_array($role_user, ['admin','superadmin']))
            json_response(false, 'Accès refusé.');
        $corr_id  = (int)($_POST['corr_id'] ?? 0);
        $propose  = (int)($_POST['total_propose_coord'] ?? -1);
        $reponse  = trim($_POST['reponse'] ?? '');
        $corr = db_fetch_one("SELECT * FROM corrections_point_emuci WHERE id=? AND statut='en_attente'", [$corr_id]);
        if (!$corr) json_response(false, 'Demande introuvable ou déjà traitée.');
        if ($role_user === 'coordinateur_site' && (int)$corr['site_id'] !== (int)$user['site_id']) json_response(false, 'Accès refusé.');
        if ($propose < 0) json_response(false, 'Valeur invalide.');
        try {
            pec_contester($corr, $user, $propose, $reponse);
            audit_log($user['id'], 'UPDATE', 'corrections_point_emuci', $corr_id, "Correction Point EMUCI contestée → $propose");
            json_response(true, 'Contre-proposition envoyée au GP.');
        } catch (Exception $e) { json_response(false, $e->getMessage()); }
    }

    json_response(false, 'Action inconnue.');
}

// ── LISTE DES POINTS
$role_slug_pj = current_user()['role_slug'] ?? '';
// Coordinateur : forcé sur son site (jamais les autres sites)
if ($role_slug_pj === 'coordinateur_site') {
    // Sans site assigné → résultat vide (-1 ne matche aucun site_id)
    $f_site = $user['site_id'] ? (int)$user['site_id'] : -1;
} else {
    // Superviseur et autres : peuvent voir tous les sites
    $f_site = (int)($_GET['site'] ?? 0);
}
$f_mois = trim($_GET['mois'] ?? date('Y-m'));

// Les brouillons ne sont visibles que par leur auteur (coordinateur)
$filtre_brouillon = ($role_slug_pj === 'coordinateur_site')
    ? ""
    : "AND p.statut != 'brouillon'";

$points = db_fetch_all(
    "SELECT p.*, s.nom AS site_nom,
            p.created_by AS agent_id,
            " . ($sites_operation_ok ? "s.operation AS site_operation," : "'primo' AS site_operation,") . "
            (SELECT COALESCE(SUM(pu.utilises),0) FROM op_pmma_utilises pu WHERE pu.point_id=p.id) AS pmma_utilises,
            (SELECT COALESCE(SUM(fu.films_utilises),0) FROM op_films_utilises fu WHERE fu.point_id=p.id) AS films_utilises_total,
            CONCAT(u.prenom,' ',u.nom) AS agent
     FROM op_points_journaliers p
     JOIN sites s ON s.id=p.site_id
     LEFT JOIN users u ON u.id=p.created_by
     WHERE DATE_FORMAT(p.date_point,'%Y-%m')=?
       AND (? = 0 OR p.site_id=?)
       $filtre_brouillon
     ORDER BY p.date_point DESC, p.type_point DESC",
    [$f_mois, $f_site, $f_site]
);

// KPIs du mois
$kpi_engins  = array_sum(array_column($points, 'total_engins'));
$kpi_plaques = array_sum(array_column($points, 'total_plaques'));
$kpi_rivets  = array_sum(array_column($points, 'rivets_utilises'));
$kpi_valides = count(array_filter($points, fn($p) => $p['statut'] === 'valide'));

// Stock rivets — chips : coordinateur voit uniquement son site
if ($role_slug_pj === 'coordinateur_site') {
    $stock_rivets_all = $user['site_id']
        ? db_fetch_all("SELECT sr.*, s.nom FROM op_stock_rivets sr JOIN sites s ON s.id=sr.site_id WHERE sr.site_id=?", [(int)$user['site_id']])
        : [];
} elseif ($f_site > 0) {
    // Superviseur filtré sur un site
    $stock_rivets_all = db_fetch_all("SELECT sr.*, s.nom FROM op_stock_rivets sr JOIN sites s ON s.id=sr.site_id WHERE sr.site_id=?", [$f_site]);
} else {
    $stock_rivets_all = db_fetch_all("SELECT sr.*, s.nom FROM op_stock_rivets sr JOIN sites s ON s.id=sr.site_id ORDER BY s.nom");
}

// Vérifier si le coordinateur est autorisé à travailler aujourd'hui
$stock_bloque        = false;
$stock_bloque_msg    = '';
$validation_matin    = null;
if ($role_slug_pj === 'coordinateur_site' && $user['site_id']) {
    $validation_matin = db_fetch_one(
        "SELECT * FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
        [(int)$user['site_id'], date('Y-m-d')]
    );
    // Blocage via écart non résolu (veille ou plus récent)
    $msg_ecart = _site_bloque_ecart((int)$user['site_id'], date('Y-m-d'));
    if ($msg_ecart) {
        $stock_bloque     = true;
        $stock_bloque_msg = $msg_ecart;
    }
}

// Corrections bobines en attente (pour coordinateur seulement)
$corrections_en_attente = [];
$nb_corrections_attente = 0;
if ($role_slug_pj === 'coordinateur_site' && $user['site_id']) {
    $corrections_en_attente = db_fetch_all(
        "SELECT cb.*, b.numero AS bobine_num,
                CONCAT(gsb.prenom,' ',gsb.nom) AS gsb_nom
         FROM corrections_bobines cb
         JOIN op_bobines b ON b.id = cb.bobine_id
         JOIN users gsb   ON gsb.id = cb.gsb_id
         WHERE cb.site_id = ? AND cb.statut = 'en_attente'
         ORDER BY cb.created_at DESC",
        [(int)$user['site_id']]
    );
    $nb_corrections_attente = count($corrections_en_attente);
}

// Corrections Point EMUCI en attente (pour coordinateur seulement)
$pec_en_attente = [];
$nb_pec_attente = 0;
if ($role_slug_pj === 'coordinateur_site' && $user['site_id']) {
    $pec_en_attente = db_fetch_all(
        "SELECT c.*, CONCAT(gp.prenom,' ',gp.nom) AS gp_nom
         FROM corrections_point_emuci c
         JOIN users gp ON gp.id = c.gp_id
         WHERE c.site_id = ? AND c.statut = 'en_attente'
         ORDER BY c.created_at DESC",
        [(int)$user['site_id']]
    );
    $nb_pec_attente = count($pec_en_attente);
}

include __DIR__ . '/../../templates/header.php';
?>
<style>
/* Icône de la semi-remorque : la remorque seule (conteneur à nervures, deux roues). La
   bibliothèque d'icônes ne l'a pas — « truck-trailer » y dessine un camion avec sa cabine.
   Masque SVG teinté par currentColor : elle suit la couleur du texte comme les autres icônes. */
.ph-semi{display:inline-block;width:1em;height:1em;vertical-align:-.125em;background-color:currentColor;
  -webkit-mask:url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20256%20256'%20fill='none'%20stroke='black'%20stroke-width='16'%20stroke-linecap='round'%20stroke-linejoin='round'%3E%3Cg%20transform='translate%280%2C-5%29'%3E%3Cpath%20d='M28%2066H44L54%2078H202L212%2066H228V170H28Z'/%3E%3Cpath%20d='M74%2098V142M110%2098V142M146%2098V142M182%2098V142'/%3E%3Ccircle%20cx='54'%20cy='184'%20r='16'/%3E%3Ccircle%20cx='98'%20cy='184'%20r='16'/%3E%3C/g%3E%3C/svg%3E") center/contain no-repeat;mask:url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20256%20256'%20fill='none'%20stroke='black'%20stroke-width='16'%20stroke-linecap='round'%20stroke-linejoin='round'%3E%3Cg%20transform='translate%280%2C-5%29'%3E%3Cpath%20d='M28%2066H44L54%2078H202L212%2066H228V170H28Z'/%3E%3Cpath%20d='M74%2098V142M110%2098V142M146%2098V142M182%2098V142'/%3E%3Ccircle%20cx='54'%20cy='184'%20r='16'/%3E%3Ccircle%20cx='98'%20cy='184'%20r='16'/%3E%3C/g%3E%3C/svg%3E") center/contain no-repeat}
/* ── KPI BAR ── */
.kpi-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.kpi-card{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px}
.kpi-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.kpi-val{font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:var(--navy);line-height:1}
.kpi-lbl{font-size:12px;color:var(--muted);margin-top:2px}

/* ── POINTS CARDS ── */
.pj-card{background:white;border:1px solid var(--border);border-radius:14px;padding:14px 18px;display:grid;grid-template-columns:64px 1fr auto;gap:14px;align-items:center;transition:box-shadow .18s,border-color .18s;cursor:default}
.pj-card:hover{box-shadow:0 4px 16px rgba(10,22,40,.08);border-color:#c5d3e8}
.pj-card.today{border-left:4px solid #1a56a0}
.pj-date-block{text-align:center}
.pj-date-block .pjd{font-family:'Montserrat',sans-serif;font-size:26px;font-weight:900;color:var(--navy);line-height:1}
.pj-date-block .pjm{font-size:12px;text-transform:uppercase;color:var(--muted);letter-spacing:.5px}
.pj-type-pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:700;margin-top:5px}
.pj-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.pj-pill{padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.pj-pill.engins{background:#e3f2fd;color:#1565c0}
.pj-pill.plaques{background:#e8f5e9;color:#1b5e20}
.pj-pill.rivets{background:#f3e5f5;color:#6a1b9a}
.pj-pill.prod{background:#fff3e0;color:#e65100}
.pj-pill.veh{background:#f8fafc;color:#475569;border:1px solid #e2e8f0}
.pj-actions{display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.pj-status{padding:4px 11px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;margin-bottom:6px;text-align:center}

/* ── FORM POINT ── */
.point-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.veh-card{background:white;border:1.5px solid var(--border);border-radius:12px;padding:16px;transition:border-color .2s}
.veh-card:hover{border-color:var(--blue-mid,#1a56a0)}
.veh-card .vc-header{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.veh-card .vc-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.veh-card .vc-title{font-family:'Montserrat',sans-serif;font-size:13px;font-weight:700;color:var(--navy)}
.veh-card .vc-sub{font-size:12px;color:var(--muted)}
.veh-card .vc-badges{display:flex;gap:5px;margin-bottom:10px;flex-wrap:wrap}
.badge-info{background:#e3f2fd;color:#1565c0;padding:2px 7px;border-radius:6px;font-size:12px;font-weight:600}
.nb-input{width:100%;padding:14px;text-align:center;font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900;border:2px solid var(--border);border-radius:10px;outline:none;color:var(--navy);transition:border-color .2s}
.nb-input:focus{border-color:#1a56a0;box-shadow:0 0 0 3px rgba(26,86,160,.1)}
.nb-input:not([value="0"]):not(:placeholder-shown){border-color:#1a56a0;color:#1a56a0}
.nb-input::-webkit-inner-spin-button{opacity:1;transform:scale(1.4)}

/* ── CALC PANEL (compact) ── */
.calc-panel{background:linear-gradient(135deg,#0a1628,#163566);border-radius:14px;padding:14px 20px;margin-bottom:20px;position:sticky;top:0;z-index:10}
.calc-panel-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.calc-panel-top h3{font-family:'Montserrat',sans-serif;font-size:12px;font-weight:700;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.8px;margin:0}
.calc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.calc-item{text-align:center}
.calc-item .cv{font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:white;line-height:1}
.calc-item .cl{font-size:12px;color:rgba(255,255,255,.5);margin-top:3px;text-transform:uppercase;letter-spacing:.5px}
.calc-item .cx{font-size:11.5px;color:#c4b5fd;font-weight:700;margin-top:3px}
.calc-item.warn .cv{color:#fbbf24}
.calc-item.danger .cv{color:#f87171}
.calc-item.success .cv{color:#34d399}

/* ── SECTION HEADERS IN MODAL ── */
.form-section{margin-bottom:24px}
.form-section-title{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid var(--border)}
/* Journée sans production : seules les informations générales et les observations restent saisissables. */
#mPoint.sans-prod .form-section:not(.fs-garde), #mPoint.sans-prod .pj-non-poses{opacity:.35;pointer-events:none;filter:grayscale(1)}
.form-section-num{width:24px;height:24px;border-radius:50%;background:var(--navy);color:white;font-family:'Montserrat',sans-serif;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.form-section-title h4{font-family:'Montserrat',sans-serif;font-size:14px;font-weight:700;color:var(--navy);margin:0}

/* ── FILMS ── */
.film-serie{background:white;border:1px solid var(--border);border-radius:12px;margin-bottom:10px;overflow:hidden}
.film-serie-header{padding:11px 16px;background:var(--lighter);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;cursor:pointer}
.film-serie-header h4{font-family:'Montserrat',sans-serif;font-size:13px;font-weight:700}
.film-serie-body{padding:14px 16px;display:none}
.film-serie-body.open{display:block}
.bobine-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.bobine-row:last-child{border-bottom:none}
.bobine-num{font-family:monospace;font-weight:700;font-size:12px;color:var(--navy);min-width:120px}
.bobine-restant{font-size:12px;color:var(--muted)}
.bobine-input{width:72px;padding:6px;border:1.5px solid var(--border);border-radius:7px;text-align:center;font-size:13px;font-family:'Montserrat',sans-serif;font-weight:700;outline:none}
.bobine-input:focus{border-color:var(--blue-mid,#1a56a0)}

/* ── STATUS BADGES ── */
.point-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.point-badge.valide{background:#d1fae5;color:#065f46}
.point-badge.en_attente_validation{background:#dbeafe;color:#1d4ed8}
.point-badge.brouillon{background:#fef3c7;color:#92400e}
.point-badge.suivi{background:#f1f5f9;color:#475569}
.point-badge.rejete{background:#fee2e2;color:#991b1b}

/* ── MODALS ── */
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(10,22,40,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center}
/* S'ouvre par-dessus le formulaire du point (#mPoint), placé après lui dans
   le DOM : à z-index égal il passait derrière. */
#mEndommagement{z-index:600}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;max-width:95vw;max-height:95vh;overflow-y:auto;animation:mIn .22s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:16px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800}
.mclose{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.mbody{padding:20px 24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}
/* Téléphone : quatre cartes de front étaient écrasées (textes tronqués) et trois boutons alignés
   à droite débordaient de l'écran par la gauche (« Annuler » coupé). */
.g4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}
@media(max-width:640px){
  .g4{grid-template-columns:repeat(2,minmax(0,1fr))}
  .mbody{padding:16px}
  .mfoot{padding:12px 16px;flex-wrap:wrap}
  .mfoot .btn{flex:1 1 auto;justify-content:center;white-space:normal}
  #comp-footer{flex-direction:column-reverse}
  #comp-footer .btn{width:100%}
}

/* ── APERÇU ── */
.point-preview{font-family:'Montserrat',sans-serif;background:linear-gradient(135deg,#0a1628,#163566);color:white;border-radius:12px;padding:20px;margin-bottom:20px}
.point-preview h4{font-size:15px;font-weight:800;margin-bottom:4px;text-align:center}
.point-preview .pp-date{text-align:center;opacity:.7;font-size:12px;margin-bottom:16px}
.point-preview .pp-section{margin-bottom:14px}
.point-preview .pp-title{font-size:12px;font-weight:700;opacity:.5;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.point-preview .pp-row{display:flex;justify-content:space-between;font-size:13px;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.08)}
.point-preview .pp-total{display:flex;justify-content:space-between;font-size:15px;font-weight:800;padding:8px 0;border-top:2px solid rgba(255,255,255,.2);margin-top:6px}
.point-preview .pp-stat{background:rgba(255,255,255,.1);border-radius:8px;padding:10px 14px;text-align:center}
.point-preview .pp-stat .psv{font-size:22px;font-weight:900}
.point-preview .pp-stat .psl{font-size:12px;opacity:.6;margin-top:2px}
@media(max-width:900px){.point-grid{grid-template-columns:minmax(0,1fr)}.calc-grid{grid-template-columns:repeat(2,1fr)}.kpi-bar{grid-template-columns:repeat(2,1fr)}.pj-card{grid-template-columns:minmax(0,1fr)}}
</style>

<!-- HEADER BAR -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;min-width:0">
      <?php if($role_slug_pj !== 'coordinateur_site'): ?>
      <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()"
              style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;cursor:pointer;outline:none;font-family:'DM Sans',sans-serif;max-width:100%">
        <option value="0">Tous les sites</option>
        <?php foreach($sites_list as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $f_site===$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <span style="padding:9px 14px;background:var(--lighter);border-radius:9px;font-size:13px;font-weight:600;color:var(--navy)">
        <i class="ph ph-map-pin" aria-hidden="true"></i> <?= h(db_fetch_value("SELECT nom FROM sites WHERE id=?", [$f_site]) ?? 'Mon site') ?>
      </span>
      <?php endif; ?>
      <input type="month" name="mois" value="<?= h($f_mois) ?>" onchange="this.form.submit()" aria-label="Choisir le mois"
             style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
    </form>
  </div>
  <?php if(in_array($role_slug_pj, ['coordinateur_site','admin','superadmin'])): ?>
  <?php if($stock_bloque): ?>
  <button class="btn btn-secondary" disabled style="opacity:.5;cursor:not-allowed"><i class="ph ph-lock" aria-hidden="true"></i> Activité bloquée</button>
  <?php else: ?>
  <button class="btn btn-primary" onclick="openPointForm()">+ Nouveau point journalier</button>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if($stock_bloque && $stock_bloque_msg): ?>
<div style="background:#FEF2F2;border:2px solid #FECACA;border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px">
  <span style="font-size:22px;flex-shrink:0"><i class="ph ph-prohibit" aria-hidden="true"></i></span>
  <div>
    <div style="font-weight:800;color:#991B1B;font-size:14px;margin-bottom:4px">Saisie de point journalier bloquée</div>
    <div style="color:#7F1D1D;font-size:13px"><?= h($stock_bloque_msg) ?></div>
  </div>
</div>
<?php elseif($stock_bloque && $validation_matin): ?>
<?php
// Charger les corrections demandées pour ce site/date
$corrections_demandees = ($role_slug_pj === 'coordinateur_site' && $user['site_id'])
    ? db_fetch_all(
        "SELECT dc.*, b.numero, b.type_code
         FROM demandes_correction_saisie dc
         JOIN op_bobines b ON b.id=dc.bobine_id
         WHERE dc.site_id=? AND dc.date_cible=? AND dc.statut='en_attente'
         ORDER BY b.serie, b.numero",
        [(int)$user['site_id'], date('Y-m-d')]
      )
    : [];
?>
<div style="background:#fee2e2;border:2px solid #fca5a5;border-radius:16px;padding:20px 24px;margin-bottom:20px">
  <div style="display:flex;align-items:flex-start;gap:16px">
    <div style="font-size:40px;flex-shrink:0"><i class="ph ph-lock" aria-hidden="true"></i></div>
    <div style="flex:1">
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:#991b1b;margin-bottom:4px">Activité bloquée par le gestionnaire stock bobines</div>
      <div style="font-size:13px;color:#991b1b">Votre stock présente des écarts non résolus. Vous ne pouvez pas saisir de point journalier aujourd'hui.</div>
      <?php if($validation_matin['commentaire']): ?>
      <div style="margin-top:8px;font-size:13px;color:#7f1d1d"><i class="ph ph-chat-circle" aria-hidden="true"></i> Motif GSB : <strong><?= h($validation_matin['commentaire']) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if(!empty($corrections_demandees)): ?>
  <div style="margin-top:16px;background:white;border-radius:12px;overflow:hidden;border:1.5px solid #fca5a5">
    <div style="padding:10px 16px;background:#fef2f2;border-bottom:1px solid #fca5a5;font-size:13px;font-weight:700;color:#991b1b">
      <i class="ph ph-warning" aria-hidden="true"></i> Corrections à apporter sur <?= count($corrections_demandees) ?> bobine(s)
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#fff5f5">
          <th style="padding:8px 14px;text-align:left;font-size:12px;color:#7f1d1d;font-weight:700">BOBINE</th>
          <th style="padding:8px 14px;text-align:center;font-size:12px;color:#7f1d1d;font-weight:700">FILMS DÉCLARÉS (PJ)</th>
          <th style="padding:8px 14px;text-align:center;font-size:12px;color:#7f1d1d;font-weight:700">FILMS EMUCI</th>
          <th style="padding:8px 14px;text-align:center;font-size:12px;color:#7f1d1d;font-weight:700">ÉCART</th>
          <th style="padding:8px 14px;font-size:12px;color:#7f1d1d;font-weight:700">NOTE DU GSB</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($corrections_demandees as $corr):
        $ecart_col = $corr['ecart'] < 0 ? '#e74c3c' : '#f39c12';
      ?>
        <tr style="border-top:1px solid #fecaca">
          <td style="padding:9px 14px;font-family:monospace;font-weight:700;color:#0d1f35">
            <?= h($corr['numero']) ?>
            <span style="font-size:12px;color:#6b7280;margin-left:4px"><?= h($corr['type_code']) ?></span>
          </td>
          <td style="padding:9px 14px;text-align:center;font-weight:700"><?= $corr['films_pj'] ?></td>
          <td style="padding:9px 14px;text-align:center;font-weight:700"><?= $corr['films_emuci'] ?></td>
          <td style="padding:9px 14px;text-align:center;font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:<?= $ecart_col ?>">
            <?= $corr['ecart'] > 0 ? '+' : '' ?><?= $corr['ecart'] ?> films
          </td>
          <td style="padding:9px 14px;font-size:12px;color:#6b7280"><?= h($corr['notes_gsb'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="padding:10px 16px;font-size:12px;color:#991b1b;background:#fef2f2;border-top:1px solid #fca5a5">
      Contactez votre gestionnaire stock bobines pour corriger ces saisies et débloquer votre activité.
    </div>
  </div>
  <?php else: ?>
  <div style="margin-top:10px;font-size:12px;color:#991b1b">Contactez votre gestionnaire stock bobines pour débloquer votre activité.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if(!$stock_bloque && $validation_matin && $validation_matin['statut']==='autorise_ecart'): ?>
<div style="background:#fef3c7;border:2px solid #fcd34d;border-radius:14px;padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px">
  <div style="font-size:24px"><i class="ph ph-warning" aria-hidden="true"></i></div>
  <div>
    <div style="font-size:14px;font-weight:700;color:#92400e">Écart autorisé par le gestionnaire</div>
    <div style="font-size:12px;color:#92400e;margin-top:2px">Commentaire : <?= h($validation_matin['commentaire']) ?></div>
  </div>
</div>
<?php endif; ?>

<!-- KPI BAR -->
<div class="kpi-bar">
  <div class="kpi-card">
    <div class="kpi-icon" style="background:#e3f2fd"><i class="ph ph-car" aria-hidden="true"></i></div>
    <div><div class="kpi-val"><?= fmt_number($kpi_engins) ?></div><div class="kpi-lbl">Engins ce mois</div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon" style="background:#e8f5e9"><i class="ph ph-clipboard-text" aria-hidden="true"></i></div>
    <div><div class="kpi-val"><?= fmt_number($kpi_plaques) ?></div><div class="kpi-lbl">Plaques ce mois</div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon" style="background:#f3e5f5"><i class="ph ph-wrench" aria-hidden="true"></i></div>
    <div><div class="kpi-val"><?= fmt_number($kpi_rivets) ?></div><div class="kpi-lbl">Rivets posés</div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon" style="background:#d1fae5"><i class="ph ph-check-circle" aria-hidden="true"></i></div>
    <div><div class="kpi-val"><?= $kpi_valides ?> / <?= count($points) ?></div><div class="kpi-lbl">Points validés</div></div>
  </div>
</div>

<!-- CORRECTIONS BOBINES EN ATTENTE — visible coordinateur uniquement -->
<?php if($role_slug_pj === 'coordinateur_site' && $nb_corrections_attente > 0): ?>
<div id="panel-corrections-bobines" style="background:white;border:2px solid #f59e0b;border-radius:14px;margin-bottom:20px;overflow:hidden">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:linear-gradient(90deg,#fffbeb,#fef3c7);border-bottom:1px solid #fcd34d">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-size:20px"><i class="ph ph-bell" aria-hidden="true"></i></span>
      <div>
        <div style="font-family:'Montserrat',sans-serif;font-size:14px;font-weight:800;color:#92400e">
          <?= $nb_corrections_attente ?> demande<?= $nb_corrections_attente > 1 ? 's' : '' ?> de correction bobine<?= $nb_corrections_attente > 1 ? 's' : '' ?> en attente
        </div>
        <div style="font-size:12px;color:#a16207">Le gestionnaire stock bobines demande votre validation sur des écarts de films.</div>
      </div>
    </div>
    <button onclick="const b=this.closest('#panel-corrections-bobines').querySelector('.corr-body');b.style.display=b.style.display==='none'?'block':'none'" style="background:none;border:1px solid #fcd34d;border-radius:8px;padding:4px 12px;font-size:12px;color:#92400e;cursor:pointer">Afficher / Masquer</button>
  </div>
  <div class="corr-body">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#fffbeb">
          <th style="padding:9px 14px;text-align:left;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase;letter-spacing:.4px">Bobine</th>
          <th style="padding:9px 14px;text-align:center;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Films déclarés (PJ)</th>
          <th style="padding:9px 14px;text-align:center;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Films proposés (GSB)</th>
          <th style="padding:9px 14px;text-align:left;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Motif GSB</th>
          <th style="padding:9px 14px;text-align:left;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Demandé par</th>
          <th style="padding:9px 14px;text-align:left;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Date</th>
          <th style="padding:9px 14px;text-align:center;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($corrections_en_attente as $cb): ?>
        <tr style="border-top:1px solid #fde68a" data-corr-id="<?= $cb['id'] ?>">
          <td style="padding:9px 14px">
            <span style="font-family:monospace;font-weight:700;color:#0d1f35"><?= h($cb['bobine_num']) ?></span>
          </td>
          <td style="padding:9px 14px;text-align:center;font-family:'Montserrat',sans-serif;font-weight:700;color:#475569"><?= (int)$cb['films_original'] ?></td>
          <td style="padding:9px 14px;text-align:center;font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#d97706"><?= (int)$cb['films_proposes'] ?></td>
          <td style="padding:9px 14px;font-size:12px;color:#374151;max-width:220px"><?= h($cb['motif_gsb']) ?></td>
          <td style="padding:9px 14px;font-size:12px;color:#6b7280"><?= h($cb['gsb_nom']) ?></td>
          <td style="padding:9px 14px;font-size:12px;color:#6b7280"><?= fmt_date($cb['date_point'], 'd/m/Y') ?></td>
          <td style="padding:9px 14px;text-align:center">
            <div style="display:flex;gap:6px;justify-content:center">
              <button onclick="ouvrirReponseCorrection(<?= $cb['id'] ?>, <?= (int)$cb['films_original'] ?>, <?= (int)$cb['films_proposes'] ?>, '<?= addslashes(h($cb['bobine_num'])) ?>')"
                      style="background:#1a56a0;color:white;border:none;border-radius:7px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer">
                Répondre
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- CORRECTIONS POINT EMUCI EN ATTENTE — visible coordinateur uniquement -->
<?php if($role_slug_pj === 'coordinateur_site' && $nb_pec_attente > 0): ?>
<div id="panel-pec" style="background:white;border:2px solid #f59e0b;border-radius:14px;margin-bottom:20px;overflow:hidden">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:linear-gradient(90deg,#fffbeb,#fef3c7);border-bottom:1px solid #fcd34d">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-size:20px"><i class="ph ph-bell" aria-hidden="true"></i></span>
      <div>
        <div style="font-family:'Montserrat',sans-serif;font-size:14px;font-weight:800;color:#92400e">
          <?= $nb_pec_attente ?> demande<?= $nb_pec_attente > 1 ? 's' : '' ?> de correction Point EMUCI en attente
        </div>
        <div style="font-size:12px;color:#a16207">Le service Gestion Production conteste votre déclaratif de plaques posées.</div>
      </div>
    </div>
    <button onclick="const b=this.closest('#panel-pec').querySelector('.corr-body');b.style.display=b.style.display==='none'?'block':'none'" style="background:none;border:1px solid #fcd34d;border-radius:8px;padding:4px 12px;font-size:12px;color:#92400e;cursor:pointer">Afficher / Masquer</button>
  </div>
  <div class="corr-body">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#fffbeb">
          <th style="padding:9px 14px;text-align:left;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase;letter-spacing:.4px">Date</th>
          <th style="padding:9px 14px;text-align:center;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Déclaré</th>
          <th style="padding:9px 14px;text-align:center;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Proposé (GP)</th>
          <th style="padding:9px 14px;text-align:left;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Motif</th>
          <th style="padding:9px 14px;text-align:left;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Demandé par</th>
          <th style="padding:9px 14px;text-align:center;font-size:12px;color:#78350f;font-weight:700;text-transform:uppercase">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($pec_en_attente as $pc): ?>
        <tr style="border-top:1px solid #fde68a" data-pec-id="<?= $pc['id'] ?>">
          <td style="padding:9px 14px;font-size:12px;color:#6b7280"><?= fmt_date($pc['date_point'], 'd/m/Y') ?></td>
          <td style="padding:9px 14px;text-align:center;font-family:'Montserrat',sans-serif;font-weight:700;color:#475569"><?= (int)$pc['total_declare'] ?></td>
          <td style="padding:9px 14px;text-align:center;font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#d97706"><?= (int)$pc['total_propose'] ?></td>
          <td style="padding:9px 14px;font-size:12px;color:#374151;max-width:220px"><?= h($pc['motif_gp']) ?></td>
          <td style="padding:9px 14px;font-size:12px;color:#6b7280"><?= h($pc['gp_nom']) ?></td>
          <td style="padding:9px 14px;text-align:center">
            <div style="display:flex;gap:6px;justify-content:center">
              <button onclick="ouvrirReponsePec(<?= $pc['id'] ?>, <?= (int)$pc['total_declare'] ?>, <?= (int)$pc['total_propose'] ?>)"
                      style="background:#1a56a0;color:white;border:none;border-radius:7px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer">
                Répondre
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- STOCK RIVETS RAPIDE — visible coordinateur uniquement -->
<?php if(!empty($stock_rivets_all) && $role_slug_pj === 'coordinateur_site'): ?>
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <span style="font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Stock rivets :</span>
  <?php foreach($stock_rivets_all as $sr):
    $label = $sr['type_rivet'] === 'eclate' ? 'Éclatés' : 'Gonflables';
    $icon  = $sr['type_rivet'] === 'eclate' ? '<i class="ph ph-circle" aria-hidden="true"></i>' : '<i class="ph ph-circle" aria-hidden="true"></i>';
    $col   = $sr['quantite']<100 ? '#dc2626' : ($sr['quantite']<500 ? '#d97706' : '#1e40af');
    $bg    = $sr['quantite']<100 ? '#fee2e2' : ($sr['quantite']<500 ? '#fef3c7' : '#eff6ff');
  ?>
  <div style="padding:5px 14px;background:<?= $bg ?>;border-radius:20px;font-size:12px;font-weight:700;color:<?= $col ?>;display:flex;align-items:center;gap:5px">
    <?= $icon ?> <?= $label ?> : <?= fmt_number($sr['quantite']) ?>
    <?php if(!empty($sr['nom'])&&$role_slug_pj!=='coordinateur_site'): ?>
    <span style="font-weight:400;opacity:.7">· <?= h($sr['nom']) ?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- LISTE DES POINTS — CARDS -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
  <h3 style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:700;color:var(--navy)">
    <i class="ph ph-clipboard-text" aria-hidden="true"></i> Points journaliers <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($points) ?>)</span>
  </h3>
</div>

<?php if(empty($points)): ?>
<div style="background:white;border:1px solid var(--border);border-radius:14px;padding:48px;text-align:center;color:var(--muted)">
  <div style="font-size:40px;margin-bottom:12px"><i class="ph ph-clipboard-text" aria-hidden="true"></i></div>
  <div style="font-size:15px;font-weight:600">Aucun point journalier ce mois</div>
  <div style="font-size:13px;margin-top:4px">Cliquez sur "Nouveau point" pour commencer</div>
</div>
<?php else: ?>
<div data-paginer="25" style="display:flex;flex-direction:column;gap:8px">
<?php
$statut_cfg = [
  'valide'                => ['bg'=>'#d1fae5','col'=>'#065f46','icon'=>'<i class="ph ph-check-circle" aria-hidden="true"></i>','lbl'=>'Validé'],
  'brouillon'             => ['bg'=>'#fef3c7','col'=>'#92400e','icon'=>'⏳','lbl'=>'Brouillon'],
  'en_attente_validation' => ['bg'=>'#dbeafe','col'=>'#1d4ed8','icon'=>'<i class="ph ph-upload-simple" aria-hidden="true"></i>','lbl'=>'En attente'],
  'suivi'                 => ['bg'=>'#f1f5f9','col'=>'#475569','icon'=>'<i class="ph ph-chart-bar" aria-hidden="true"></i>','lbl'=>'Suivi'],
  'rejete'                => ['bg'=>'#fee2e2','col'=>'#991b1b','icon'=>'<i class="ph ph-pencil-simple-line" aria-hidden="true"></i>','lbl'=>'Modif. demandée'],
];
$type_cfg = [
  'point_9h'  => ['lbl'=>'9h', 'bg'=>'#fef3c7','col'=>'#92400e'],
  'point_13h' => ['lbl'=>'13h','bg'=>'#ffedd5','col'=>'#c2410c'],
  'point_18h' => ['lbl'=>'18h <i class="ph ph-check-circle" aria-hidden="true"></i>','bg'=>'#dbeafe','col'=>'#1d4ed8'],
  'final'     => ['lbl'=>'Final','bg'=>'#e0f2fe','col'=>'#0369a1'],
  'intermediaire'=>['lbl'=>'Interm.','bg'=>'#fff7ed','col'=>'#c2410c'],
];
foreach($points as $p):
  $sc  = $statut_cfg[$p['statut']] ?? ['bg'=>'#f3f4f6','col'=>'#374151','icon'=>'·','lbl'=>$p['statut']];
  $tc  = $type_cfg[$p['type_point']] ?? ['lbl'=>$p['type_point'],'bg'=>'#f3f4f6','col'=>'#374151'];
  $today = ($p['date_point'] === date('Y-m-d'));
  $veh = [];
  if($p['nb_vp']>0)     $veh[]="<i class='ph ph-car' aria-hidden='true'></i> {$p['nb_vp']}";
  if($p['nb_camion']>0) $veh[]="<i class='ph ph-truck' aria-hidden='true'></i> {$p['nb_camion']}";
  if($p['nb_semi']>0)   $veh[]="<i class='ph-semi' aria-hidden='true'></i> {$p['nb_semi']}";
  if($p['nb_moto']>0)   $veh[]="<i class='ph ph-motorcycle' aria-hidden='true'></i> {$p['nb_moto']}";
?>
<div class="pj-card<?= $today ? ' today' : '' ?>">

  <!-- Date + Type -->
  <div class="pj-date-block">
    <div class="pjd"><?= date('d', strtotime($p['date_point'])) ?></div>
    <div class="pjm"><?= date('M', strtotime($p['date_point'])) ?></div>
    <div class="pj-type-pill" style="background:<?= $tc['bg'] ?>;color:<?= $tc['col'] ?>"><?= $tc['lbl'] ?></div>
  </div>

  <!-- Stats -->
  <div>
    <div style="font-size:13px;font-weight:600;color:var(--navy);margin-bottom:6px">
      <?= h($p['site_nom']) ?>
      <?php if($p['agent']): ?>
      <span style="font-size:12px;color:var(--muted);font-weight:400"> · <?= h($p['agent']) ?></span>
      <?php endif; ?>
    </div>
    <?php
      // Site en Re-Immatriculation : les engins de la section principale
      // sont des ré-immatriculations. Ordre : ré-immat, duplicata,
      // post-immat, plaques (des trois sections), rivets, puis le reste.
      $pj_reimmat = ($p['site_operation'] ?? 'primo') === 'reimmat_post';
      $pj_plaques = (int)$p['total_plaques'] + (int)($p['total_plaques_duplicata'] ?? 0)
                  + (int)($p['total_plaques_postimmat'] ?? 0);
    ?>
    <div class="pj-pills">
      <?php if(!empty($p['sans_production'])): ?>
      <span class="pj-pill" style="background:#e2e8f0;color:#334155;font-weight:700"><i class="ph ph-moon" aria-hidden="true"></i> Sans production · <?= h(_pj_libelle_sans_prod($p)) ?></span>
      <?php endif; ?>
      <span class="pj-pill engins"><i class="ph ph-car" aria-hidden="true"></i> <?= $p['total_engins'] ?> <?= $pj_reimmat ? 'ré-immat' : 'engins' ?></span>
      <?php if(($p['total_engins_duplicata'] ?? 0) > 0): ?>
      <span class="pj-pill" style="background:#f3e8ff;color:#6b21a8"><i class="ph ph-copy" aria-hidden="true"></i> <?= $p['total_engins_duplicata'] ?> Duplicata</span>
      <?php endif; ?>
      <?php if(($p['total_engins_postimmat'] ?? 0) > 0): ?>
      <span class="pj-pill" style="background:#fff3e0;color:#9a3412"><i class="ph ph-stamp" aria-hidden="true"></i> <?= $p['total_engins_postimmat'] ?> Post-Immat</span>
      <?php endif; ?>
      <span class="pj-pill plaques"><i class="ph ph-clipboard-text" aria-hidden="true"></i> <?= $pj_plaques ?> plaques</span>
      <?php if($p['rivets_utilises'] > 0): ?>
      <span class="pj-pill rivets"><i class="ph ph-wrench" aria-hidden="true"></i> <?= $p['rivets_utilises'] ?> rivets<?= $p['rivets_endommages']>0?' (<i class="ph ph-warning" aria-hidden="true"></i>'.$p['rivets_endommages'].' end.)':'' ?></span>
      <?php endif; ?>
      <span class="pj-pill prod"><i class="ph ph-lightning" aria-hidden="true"></i> <?= $p['moyenne_prod'] ?> V/H</span>
      <?php if($veh): ?>
      <span class="pj-pill veh"><?= implode(' · ', $veh) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Statut + Actions -->
  <div class="pj-actions" style="flex-direction:column">
    <div class="pj-status" style="background:<?= $sc['bg'] ?>;color:<?= $sc['col'] ?>"><?= $sc['icon'] ?> <?= $sc['lbl'] ?></div>
    <?php if($p['statut']==='rejete' && trim((string)($p['motif_rejet'] ?? '')) !== ''): ?>
    <div style="max-width:320px;font-size:12px;color:#7f1d1d;background:#fff1f2;border:1px solid #fecdd3;border-radius:8px;padding:6px 9px;text-align:left">
      <strong>Motif du superviseur :</strong> <?= h($p['motif_rejet']) ?>
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end">
      <button class="btn btn-secondary btn-sm" onclick="viewPoint(<?= $p['id'] ?>)" title="Aperçu"><i class="ph ph-eye" aria-hidden="true"></i></button>
      <button class="btn btn-secondary btn-sm" onclick="printPoint(<?= $p['id'] ?>)" title="Imprimer"><i class="ph ph-printer" aria-hidden="true"></i></button>
      <?php
        $manque_liste = ($p['statut'] === 'brouillon' && empty($p['sans_production'])) ? array_keys(array_filter([
            'Films'  => (int)($p['films_utilises_total'] ?? 0) === 0,
            'Rivets' => (int)($p['rivets_utilises'] ?? 0) === 0,
            'PMMA'   => (int)($p['pmma_utilises'] ?? 0) === 0,
        ])) : [];
        if ($manque_liste): ?>
        <span style="font-size:11px;font-weight:700;color:#92400e;background:#fef3c7;border-radius:6px;padding:3px 7px" title="À saisir avant de soumettre/valider">⚠️ <?= h(implode(', ', $manque_liste)) ?> manquant(s)</span>
      <?php endif; ?>
      <?php if($p['statut']==='brouillon' && ($p['agent_id']??0)==$user['id']): ?>
        <?php if(($p['type_point']??'')==='point_18h'): ?>
        <button class="btn btn-primary btn-sm" onclick="soumettrePoint(<?= $p['id'] ?>)" title="Soumettre"><i class="ph ph-upload-simple" aria-hidden="true"></i></button>
        <?php else: ?>
        <button class="btn btn-success btn-sm" onclick="validerPointCoord(<?= $p['id'] ?>)" title="Valider"><i class="ph ph-check-circle" aria-hidden="true"></i></button>
        <?php endif; ?>
        <button class="btn btn-secondary btn-sm" onclick="editPoint(<?= $p['id'] ?>)" title="Modifier" style="background:#e8f4f9;color:var(--blue)"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
        <button class="btn btn-sm" onclick="supprimerBrouillon(<?= $p['id'] ?>)" title="Supprimer le brouillon" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5"><i class="ph ph-trash" aria-hidden="true"></i></button>
      <?php endif; ?>
      <?php if($p['statut']==='rejete' && ($p['agent_id']??0)==$user['id']): ?>
        <!-- Modification demandée par le superviseur : le coordinateur corrige
             (l'enregistrement repasse le point en brouillon), puis le renvoie. -->
        <button class="btn btn-sm" onclick="editPoint(<?= $p['id'] ?>)" title="Corriger le point" style="background:#e8f4f9;color:var(--blue);border:1px solid #bfdbfe"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Corriger</button>
      <?php endif; ?>
      <?php if(in_array($p['statut'],['brouillon','en_attente_validation']) && ($p['type_point']??'')==='point_18h' && in_array($role_slug_pj,['admin','superadmin','superviseur_operation'])): ?>
        <button class="btn btn-success btn-sm" onclick="validerPoint(<?= $p['id'] ?>,<?= htmlspecialchars(json_encode($p['date_point'])) ?>)" title="Valider"><i class="ph ph-check-circle" aria-hidden="true"></i></button>
        <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" onclick="ouvrirDemandeModification(<?= $p['id'] ?>,<?= htmlspecialchars(json_encode($p['date_point'])) ?>)" title="Demander une modification"><i class="ph ph-pencil-simple-line" aria-hidden="true"></i></button>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═════════ MODAL SAISIE POINT ═════════ -->
<!-- ══════ n° 2.1 CR PDG — DÉCLARATION D'ENDOMMAGEMENT ══════
     Une ligne indépendante par bobine endommagée. Le pop-up s'ouvre
     automatiquement à la saisie d'une quantité endommagée, et de nouveau
     à l'enregistrement si une bobine reste sans déclaration. -->
<div class="modal-overlay" id="mEndommagement">
  <div class="modal" style="width:720px">
    <div class="mhdr">
      <h3><i class="ph ph-first-aid-kit" aria-hidden="true"></i> Déclaration d'endommagement</h3>
      <button class="mclose" onclick="document.getElementById('mEndommagement').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <p style="font-size:13px;color:var(--muted);margin:0 0 16px">
        Renseignez les circonstances pour chaque bobine endommagée. Ces informations
        alimentent le suivi de traçabilité et ne peuvent pas être saisies plus tard.
      </p>
      <div id="endoAlert"></div>
      <div id="endoLignes"></div>
      <datalist id="endoCausesList"><option value="Manipulation incorrecte"><option value="Défaut matériel"><option value="Incident externe"></datalist>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mEndommagement').classList.remove('open')">Annuler</button>
      <button class="btn btn-primary" onclick="validerEndommagements()"><i class="ph ph-check" aria-hidden="true"></i> Valider les déclarations</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="mPoint">
  <div class="modal" style="width:900px">
    <div class="mhdr">
      <h3><i class="ph ph-note-pencil" aria-hidden="true"></i> <span id="mPointTitre">Nouveau point journalier</span></h3>
      <button class="mclose" onclick="document.getElementById('mPoint').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <div id="pAlert"></div>

      <!-- Panneau calcul en temps réel (compact) -->
      <div class="calc-panel">
        <div class="calc-panel-top">
          <h3>Calcul en temps réel</h3>
          <div style="font-size:12px;color:rgba(255,255,255,.4)" id="c-stock-info"></div>
        </div>
        <div class="calc-grid">
          <div class="calc-item">
            <div class="cv" id="c-engins">0</div>
            <div class="cl">Engins</div>
            <div class="cx" id="c-engins-x"></div>
          </div>
          <div class="calc-item">
            <div class="cv" id="c-plaques">0</div>
            <div class="cl">Plaques</div>
            <div class="cx" id="c-plaques-x"></div>
          </div>
          <div class="calc-item" id="c-rivets-wrap">
            <div class="cv" id="c-rivets">0</div>
            <div class="cl">Rivets sortis</div>
          </div>
          <div class="calc-item">
            <div class="cv" id="c-moy">0</div>
            <div class="cl">Moy. V/H</div>
          </div>
        </div>
      </div>

      <!-- ① Informations générales -->
      <div class="form-section fs-garde">
        <div class="form-section-title">
          <div class="form-section-num">1</div>
          <h4>Informations générales</h4>
        </div>
        <div class="form-row cols-3">
          <div class="form-group"><label for="p-site">Site</label>
            <?php if($role_slug_pj === 'coordinateur_site' && $user['site_id']): ?>
            <input type="hidden" id="p-site" value="<?= (int)$user['site_id'] ?>" data-operation="<?= h($coord_site_operation) ?>">
            <div class="form-control" style="background:var(--lighter);color:var(--navy);font-weight:600;cursor:default">
              <i class="ph ph-map-pin" aria-hidden="true"></i> <?= h(db_fetch_value("SELECT nom FROM sites WHERE id=?",[(int)$user['site_id']]) ?? '') ?>
            </div>
            <?php else: ?>
            <select class="form-control" id="p-site" required onchange="loadStockRivets();loadBobines();loadStockPMMA();toggleReimmatSections()">
              <option value="">— Sélectionner —</option>
              <?php foreach($sites_list as $s): ?>
              <option value="<?= $s['id'] ?>" data-operation="<?= h($s['operation'] ?? 'primo') ?>"><?= h($s['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>
          <div class="form-group"><label for="p-date">Date</label>
            <input type="date" class="form-control" id="p-date" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group"><label for="p-type">Type de point</label>
            <select class="form-control" id="p-type" onchange="onTypePointChange(this.value)">
              <option value="point_9h">🕘 Point 9h — Suivi matinée</option>
              <option value="point_13h">🕐 Point 13h — Suivi mi-journée</option>
              <option value="point_18h" selected>🕕 Point 18h — Fin de journée ✅</option>
            </select>
            <div id="type-info" style="margin-top:6px;font-size:12px;padding:7px 12px;border-radius:8px;background:#d1fae5;color:#065f46;font-weight:600">
              <i class="ph ph-check-circle" aria-hidden="true"></i> Ce point sera envoyé en validation au superviseur.
            </div>
          </div>
        </div>
        <?php if(_pj_sans_prod_ok()): ?>
        <!-- Journée sans production : point 18h à zéro, motif obligatoire,
             confirmé par le superviseur. -->
        <div id="sp-bloc" style="margin-top:12px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;padding:10px 13px">
          <label style="display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:700;color:var(--navy);cursor:pointer;margin:0">
            <input type="checkbox" id="p-sans-prod" onchange="onSansProdChange()"> Aucune production ce jour
          </label>
          <div id="sp-details" style="display:none;margin-top:10px">
            <div class="form-row cols-2">
              <div class="form-group"><label for="p-sp-motif">Motif <span style="color:var(--danger-d)">*</span></label>
                <select class="form-control" id="p-sp-motif" onchange="onSansProdChange()">
                  <option value="">— Choisir le motif —</option>
                  <?php foreach (PJ_MOTIFS_SANS_PROD as $code => $lib): ?>
                  <option value="<?= $code ?>"><?= h($lib) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group"><label for="p-sp-comment">Commentaire <span id="sp-comment-req" style="color:var(--danger-d);display:none">*</span></label>
                <input type="text" class="form-control" id="p-sp-comment" maxlength="500" placeholder="Précisions (obligatoire pour « Autre »)">
              </div>
            </div>
            <div style="font-size:12px;color:#475569">Le point est enregistré à zéro, sans mouvement de stock. Le superviseur confirmera la journée sans production.</div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ② Véhicules posés -->
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">2</div>
          <h4 id="vehicules-poses-title"><?= $coord_site_operation === 'reimmat_post' ? 'Re-Immatriculation' : 'Véhicules posés' ?></h4>
          <span style="margin-left:auto;font-size:12px;color:var(--muted)">⏱
            <input type="number" id="p-heures" value="8" min="0" max="24" step="0.5"
                   aria-label="Heures de travail"
                   oninput="recalcule()"
                   style="width:52px;padding:3px 6px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-weight:700;text-align:center;outline:none;color:var(--navy)">
            h de travail
          </span>
        </div>
      <div class="point-grid" style="margin-bottom:0">
        <?php
        $veh_icons = ['VP'=>'<i class="ph ph-car" aria-hidden="true"></i>','CAM'=>'<i class="ph ph-truck" aria-hidden="true"></i>','SEMI'=>'<i class="ph-semi" aria-hidden="true"></i>','MOTO'=>'<i class="ph ph-motorcycle" aria-hidden="true"></i>'];
        $veh_colors= ['VP'=>'#e3f2fd','CAM'=>'#e8f5e9','SEMI'=>'#fff3e0','MOTO'=>'#fce4ec'];
        foreach($types_v as $tv):
        ?>
        <div class="veh-card">
          <div class="vc-header">
            <div class="vc-icon" style="background:<?= $veh_colors[$tv['code']] ?>"><?= $veh_icons[$tv['code']] ?></div>
            <div>
              <div class="vc-title"><?= h($tv['libelle']) ?></div>
              <div class="vc-sub">Série bobine : <?= $tv['serie_bobine'] ?>xxx</div>
            </div>
          </div>
          <div class="vc-badges">
            <span class="badge-info"><i class="ph ph-identification-card" aria-hidden="true"></i> <?= $tv['nb_plaques'] ?> plaque(s)</span>
            <span class="badge-info"><i class="ph ph-wrench" aria-hidden="true"></i> <?= $tv['nb_rivets'] ?> rivets</span>
            <span class="badge-info"><i class="ph ph-film-strip" aria-hidden="true"></i> <?= $tv['nb_plaques'] ?> film(s)</span>
          </div>
          <input type="number" class="nb-input" id="nb-<?= $tv['code'] ?>"
                 min="0" value="0" placeholder="0"
                 aria-label="Nombre de <?= h($tv['libelle']) ?> posés"
                 data-plaques="<?= $tv['nb_plaques'] ?>"
                 data-rivets="<?= $tv['nb_rivets'] ?>"
                 data-serie="<?= $tv['serie_bobine'] ?>"
                 data-tvid="<?= $tv['id'] ?>"
                 oninput="recalcule();autoFilmsFromVeh('<?= $tv['code'] ?>',<?= $tv['id'] ?>,'<?= $tv['serie_bobine'] ?>')">
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Stock rivets (info discrète) -->
      <div style="margin-top:10px;margin-bottom:20px;display:flex;align-items:center;gap:8px">
        <span style="font-size:12px;color:var(--muted)">Stock rivets :</span>
        <input type="text" id="p-stock-rivets" disabled style="border:none;background:none;font-weight:700;font-size:12px;color:var(--navy);padding:0;flex:1">
      </div>
      </div><!-- /form-section ② -->

      <?php
      // Sections Duplicatas / Post-Immat — uniquement pour les sites dont
      // l'opération est reimmat_post. Mêmes compteurs par type de véhicule
      // que "Véhicules posés", mais jamais fusionnés dans total_engins/
      // total_plaques : ce sont des totaux de reporting séparés.
      $reimmat_sections = [
          ['suffix' => 'DUP', 'field' => 'duplicata', 'num' => 3, 'titre' => 'Duplicatas',  'bg' => '#f3e8ff', 'color' => '#6b21a8'],
          ['suffix' => 'PI',  'field' => 'postimmat', 'num' => 4, 'titre' => 'Post-Immat',  'bg' => '#fff3e0', 'color' => '#9a3412'],
      ];
      foreach ($reimmat_sections as $rs): ?>
      <div class="form-section section-reimmat" id="section-<?= $rs['field'] ?>" style="display:none">
        <div class="form-section-title">
          <div class="form-section-num"><?= $rs['num'] ?></div>
          <h4><?= $rs['titre'] ?></h4>
          <span style="margin-left:8px;font-size:12px;color:var(--muted)">Site Re-Immatriculation uniquement — ne modifie pas les totaux Primo</span>
        </div>
        <div class="point-grid" style="margin-bottom:0">
          <?php foreach ($types_v as $tv): ?>
          <div class="veh-card">
            <div class="vc-header">
              <div class="vc-icon" style="background:<?= $rs['bg'] ?>"><?= $veh_icons[$tv['code']] ?></div>
              <div>
                <div class="vc-title"><?= h($tv['libelle']) ?></div>
                <div class="vc-sub"><?= $rs['titre'] ?></div>
              </div>
            </div>
            <div class="vc-badges">
              <span class="badge-info"><i class="ph ph-identification-card" aria-hidden="true"></i> <?= $tv['nb_plaques'] ?> plaque(s)</span>
            </div>
            <input type="number" class="nb-input" id="nb-<?= $tv['code'] ?>-<?= $rs['suffix'] ?>"
                   min="0" value="0" placeholder="0"
                   aria-label="Nombre de <?= h($tv['libelle']) ?> posés en <?= $rs['titre'] ?>"
                   oninput="<?= $rs['suffix'] === 'DUP' ? "plaquesDupSync('{$tv['code']}');" : '' ?>recalculeExtra();autoFilmsFromVeh('<?= $tv['code'] ?>',<?= $tv['id'] ?>,'<?= $tv['serie_bobine'] ?>')">
            <?php if ($rs['suffix'] === 'DUP' && in_array($tv['code'], ['VP','CAM'], true) && _pj_dup_plaques_ok()): ?>
            <!-- Un duplicata peut ne remplacer qu'une des deux plaques. -->
            <label style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px;font-size:12px;color:#6b21a8;font-weight:600">
              Plaques posées
              <input type="number" class="form-control" id="plq-<?= $tv['code'] ?>-DUP" min="0" value="0"
                     style="width:80px;padding:5px 8px;font-size:13px;text-align:center"
                     aria-label="Plaques posées sur les duplicatas <?= h($tv['libelle']) ?> (1 ou 2 par véhicule)"
                     oninput="this.dataset.manuel='1';recalculeExtra()">
            </label>
            <div style="font-size:11px;color:var(--muted);margin-top:2px">1 ou 2 par véhicule</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;font-size:12px;color:var(--muted)">
          Total <?= $rs['titre'] ?> : <strong id="c-engins-<?= $rs['suffix'] ?>" style="color:var(--navy)">0</strong> engin(s), <strong id="c-plaques-<?= $rs['suffix'] ?>" style="color:var(--navy)">0</strong> plaque(s)
        </div>
      </div><!-- /form-section <?= $rs['titre'] ?> -->
      <?php endforeach; ?>

      <!-- ⑤ Consommation rivets -->
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">5</div>
          <h4>Consommation rivets</h4>
        </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:0">
        <div style="background:#e3f2fd;border-radius:12px;padding:14px;border:1.5px solid #90caf9">
          <div style="font-size:12px;font-weight:700;color:#1565c0;margin-bottom:10px"><i class="ph ph-circle" aria-hidden="true"></i> Rivets Gonflables</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <div class="form-group" style="margin:0">
              <label style="font-size:12px;color:#1565c0">Posés</label>
              <input type="number" class="form-control" id="p-gonfl-util" min="0" value="0"
                     oninput="recalcule()" style="text-align:center;font-size:16px;font-weight:800">
            </div>
            <div class="form-group" style="margin:0">
              <label style="font-size:12px;color:#e65100">Endommagés</label>
              <input type="number" class="form-control" id="p-gonfl-end" min="0" value="0"
                     oninput="recalcule()" onchange="onRivetEndChange('gonflable', this)" style="text-align:center;border-color:#f39c12">
            </div>
          </div>
          <div id="info-gonfl" style="font-size:12px;margin-top:8px;color:#1565c0;font-weight:600">Stock: — | Sortis: 0</div>
        </div>
        <div style="background:#fce4ec;border-radius:12px;padding:14px;border:1.5px solid #f48fb1">
          <div style="font-size:12px;font-weight:700;color:#880e4f;margin-bottom:10px"><i class="ph ph-circle" aria-hidden="true"></i> Rivets Éclatés</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <div class="form-group" style="margin:0">
              <label style="font-size:12px;color:#880e4f">Posés</label>
              <input type="number" class="form-control" id="p-eclate-util" min="0" value="0"
                     oninput="recalcule()" style="text-align:center;font-size:16px;font-weight:800">
            </div>
            <div class="form-group" style="margin:0">
              <label style="font-size:12px;color:#e65100">Endommagés</label>
              <input type="number" class="form-control" id="p-eclate-end" min="0" value="0"
                     oninput="recalcule()" onchange="onRivetEndChange('eclate', this)" style="text-align:center;border-color:#f39c12">
            </div>
          </div>
          <div id="info-eclate" style="font-size:12px;margin-top:8px;color:#880e4f;font-weight:600">Stock: — | Sortis: 0</div>
        </div>
      </div>
      <input type="hidden" id="p-riv-endomm" value="0">
      </div><!-- /form-section ③ -->

      <!-- ④ PMMA -->
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">6</div>
          <h4>PMMA</h4>
          <span style="margin-left:8px;font-size:12px;color:var(--muted)">Sélectionner et ajouter les types consommés</span>
        </div>
        <div id="pmma-container">
          <div style="text-align:center;color:var(--muted);padding:12px;font-size:13px">Chargement du stock PMMA...</div>
        </div>
      </div><!-- /form-section ④ -->

      <!-- ⑤ Films -->
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">7</div>
          <h4>Films utilisés par bobine</h4>
          <span style="margin-left:8px;font-size:12px;color:var(--muted)">Uniquement les bobines en utilisation</span>
        </div>
        <div id="films-container">
          <div style="text-align:center;color:var(--muted);padding:20px;font-size:13px">Chargement des bobines...</div>
        </div>
      </div><!-- /form-section ⑤ -->

      <!-- ⑥ Compléments -->
      <div class="form-section fs-garde">
        <div class="form-section-title">
          <div class="form-section-num">8</div>
          <h4>Compléments</h4>
        </div>
        <div class="form-row cols-2 pj-non-poses" style="margin-bottom:16px">
          <div class="form-group"><label><i class="ph ph-prohibit" aria-hidden="true"></i> Non posés — Concessionnaires</label>
            <input type="number" class="form-control" id="p-np-conc" min="0" value="0">
          </div>
          <div class="form-group"><label><i class="ph ph-prohibit" aria-hidden="true"></i> Non posés — Usagers</label>
            <input type="number" class="form-control" id="p-np-usag" min="0" value="0">
          </div>
        </div>
        <div class="form-group">
          <label>Observations</label>
          <div id="obs-lignes"></div>
          <button type="button" class="btn btn-secondary" style="font-size:12.5px;padding:6px 12px" onclick="ajouterObservation()">+ Ajouter une observation</button>
        </div>
      </div><!-- /form-section ⑥ -->

      <!-- Aperçu en temps réel -->
      <div class="point-preview" id="point-preview" style="display:none">
        <h4 id="prev-titre">Point Final</h4>
        <div class="pp-date" id="prev-date"></div>
        <div class="g4" style="gap:10px;margin-bottom:16px" id="prev-stats"></div>
        <div class="pp-section">
          <div class="pp-title">Total Immatriculation</div>
          <div id="prev-detail"></div>
          <div class="pp-total"><span>TOTAL ENGINS</span><span id="prev-engins">0</span></div>
          <div class="pp-total" style="border-top:none;margin-top:0;padding-top:0"><span>TOTAL PLAQUES</span><span id="prev-plaques">0</span></div>
        </div>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mPoint').classList.remove('open')">Annuler</button>
      <button class="btn btn-secondary" onclick="togglePreview()"><i class="ph ph-eye" aria-hidden="true"></i> Aperçu</button>
      <button class="btn btn-primary" id="btn-save-point" onclick="savePoint()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer le point</button>
    </div>
  </div>
</div>

<!-- MODAL REJET POINT -->
<!-- MODAL COMPARAISON OPTOPLATE (superviseur, avant validation du point 18h) -->
<div class="modal-overlay" id="mCompOpto">
  <div class="modal" style="width:820px">
    <div class="mhdr">
      <h3><i class="ph ph-scales" aria-hidden="true"></i> Comparaison OptoPlate avant validation</h3>
      <button class="mclose" onclick="document.getElementById('mCompOpto').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <input type="hidden" id="comp-point-id" value="">
      <input type="hidden" id="comp-point-date" value="">
      <div id="comp-body" style="min-height:120px">
        <div style="text-align:center;padding:30px;color:var(--muted)">Chargement…</div>
      </div>
    </div>
    <div class="mfoot" id="comp-footer"></div>
  </div>
</div>

<div class="modal-overlay" id="mDemandeModif">
  <div class="modal" style="width:480px">
    <div class="mhdr">
      <h3><i class="ph ph-pencil-simple-line" aria-hidden="true"></i> Demander une modification</h3>
      <button class="mclose" onclick="document.getElementById('mDemandeModif').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <input type="hidden" id="dmod-point-id" value="">
      <div id="dmod-info" style="background:#fee2e2;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#991b1b;font-weight:600"></div>
      <div class="form-group">
        <label>Motif de la demande *</label>
        <textarea class="form-control" id="dmod-motif" rows="3" placeholder="Expliquer ce qui doit être corrigé…"></textarea>
      </div>
      <div style="background:#fef3c7;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400e;margin-top:12px">
        <i class="ph ph-warning" aria-hidden="true"></i> La demande va <strong>restaurer le stock</strong> films et rivets déduits par ce point. Le coordinateur sera notifié et pourra corriger puis renvoyer le point.
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mDemandeModif').classList.remove('open')">Annuler</button>
      <button class="btn" style="background:#dc2626;color:white" onclick="confirmerDemandeModification()"><i class="ph ph-pencil-simple-line" aria-hidden="true"></i> Envoyer la demande</button>
    </div>
  </div>
</div>

<!-- MODAL RÉPONSE CORRECTION BOBINE (coordinateur) -->
<div class="modal-overlay" id="mReponseCorr">
  <div class="modal" style="width:520px">
    <div class="mhdr"><h3><i class="ph ph-bell" aria-hidden="true"></i> Répondre à la demande de correction</h3>
      <button class="mclose" onclick="fermerReponseCorrection()"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <input type="hidden" id="rc-correction-id" value="">
      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px">
        <div style="font-weight:700;color:#92400e;margin-bottom:6px">Bobine : <span id="rc-bobine-num" style="font-family:monospace"></span></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div style="text-align:center;background:white;border-radius:8px;padding:8px">
            <div style="font-size:12px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px">Films déclarés (vous)</div>
            <div id="rc-films-original" style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#0d1f35"></div>
          </div>
          <div style="text-align:center;background:#fef3c7;border-radius:8px;padding:8px">
            <div style="font-size:12px;color:#92400e;text-transform:uppercase;font-weight:600;margin-bottom:4px">Films proposés (GSB)</div>
            <div id="rc-films-proposes" style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#d97706"></div>
          </div>
        </div>
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:13px;font-weight:700;color:#374151;display:block;margin-bottom:8px">Votre décision :</label>
        <div style="display:flex;flex-direction:column;gap:8px">
          <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer" id="rc-opt-approuvee">
            <input type="radio" name="rc-reponse" value="approuvee" onchange="onRcReponseChange()">
            <div>
              <div style="font-size:13px;font-weight:700;color:#065f46"><i class="ph ph-check-circle" aria-hidden="true"></i> Confirmer la proposition du GSB</div>
              <div style="font-size:12px;color:#6b7280">Le stock sera ajusté à la valeur proposée par le GSB.</div>
            </div>
          </label>
          <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer" id="rc-opt-contreproposee">
            <input type="radio" name="rc-reponse" value="contreproposee" onchange="onRcReponseChange()">
            <div>
              <div style="font-size:13px;font-weight:700;color:#b45309"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Contre-proposer une autre valeur</div>
              <div style="font-size:12px;color:#6b7280">Vous proposez un nombre de films différent, avec explication.</div>
            </div>
          </label>
          <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer" id="rc-opt-refusee">
            <input type="radio" name="rc-reponse" value="refusee" onchange="onRcReponseChange()">
            <div>
              <div style="font-size:13px;font-weight:700;color:#991b1b"><i class="ph ph-x-circle" aria-hidden="true"></i> Refuser la demande de correction</div>
              <div style="font-size:12px;color:#6b7280">Votre saisie initiale est maintenue telle quelle.</div>
            </div>
          </label>
        </div>
      </div>

      <div id="rc-section-films" style="display:none;margin-bottom:14px">
        <label style="font-size:13px;font-weight:700;color:#374151;display:block;margin-bottom:6px">Nombre de films final <span style="color:#dc2626">*</span></label>
        <input type="number" id="rc-films-final" min="0" step="1" placeholder="0"
               style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-family:'Montserrat',sans-serif;font-size:20px;font-weight:800;text-align:center">
      </div>

      <div id="rc-section-note" style="display:none;margin-bottom:4px">
        <label style="font-size:13px;font-weight:700;color:#374151;display:block;margin-bottom:6px">Explication <span style="color:#dc2626">*</span></label>
        <textarea id="rc-note" rows="3" placeholder="Expliquez votre décision…"
                  style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;resize:vertical;box-sizing:border-box"></textarea>
      </div>
    </div>
    <div class="mfoot" style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermerReponseCorrection()">Annuler</button>
      <button class="btn btn-primary" id="rc-submit-btn" onclick="submitReponseCorrection()">Envoyer ma réponse</button>
    </div>
  </div>
</div>

<!-- MODAL REPONSE CORRECTION POINT EMUCI -->
<div class="modal-overlay" id="mReponsePec">
  <div class="modal" style="width:520px">
    <div class="mhdr"><h3><i class="ph ph-bell" aria-hidden="true"></i> Répondre à la demande de correction</h3>
      <button class="mclose" onclick="fermerReponsePec()"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <input type="hidden" id="pec-id" value="">
      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div style="text-align:center;background:white;border-radius:8px;padding:8px">
            <div style="font-size:12px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px">Déclaré (vous)</div>
            <div id="pec-declare" style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#0d1f35"></div>
          </div>
          <div style="text-align:center;background:#fef3c7;border-radius:8px;padding:8px">
            <div style="font-size:12px;color:#92400e;text-transform:uppercase;font-weight:600;margin-bottom:4px">Proposé (GP)</div>
            <div id="pec-propose" style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#d97706"></div>
          </div>
        </div>
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:13px;font-weight:700;color:#374151;display:block;margin-bottom:8px">Votre décision :</label>
        <div style="display:flex;flex-direction:column;gap:8px">
          <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer">
            <input type="radio" name="pec-reponse" value="accepter" onchange="onPecReponseChange()">
            <div>
              <div style="font-size:13px;font-weight:700;color:#065f46"><i class="ph ph-check-circle" aria-hidden="true"></i> Accepter la valeur proposée</div>
              <div style="font-size:12px;color:#6b7280">Votre déclaratif sera corrigé à la valeur proposée par le GP.</div>
            </div>
          </label>
          <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer">
            <input type="radio" name="pec-reponse" value="contester" onchange="onPecReponseChange()">
            <div>
              <div style="font-size:13px;font-weight:700;color:#b45309"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Contester avec ma propre valeur</div>
              <div style="font-size:12px;color:#6b7280">Le GP tranchera en dernier ressort entre votre valeur et la sienne.</div>
            </div>
          </label>
        </div>
      </div>

      <div id="pec-section-valeur" style="display:none;margin-bottom:14px">
        <label style="font-size:13px;font-weight:700;color:#374151;display:block;margin-bottom:6px">Votre valeur <span style="color:#dc2626">*</span></label>
        <input type="number" id="pec-valeur-coord" min="0" step="1" placeholder="0"
               style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-family:'Montserrat',sans-serif;font-size:20px;font-weight:800;text-align:center">
      </div>

      <div id="pec-section-note" style="display:none;margin-bottom:4px">
        <label style="font-size:13px;font-weight:700;color:#374151;display:block;margin-bottom:6px">Explication <span style="color:#dc2626">*</span></label>
        <textarea id="pec-note" rows="3" placeholder="Expliquez votre décision…"
                  style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;resize:vertical;box-sizing:border-box"></textarea>
      </div>
    </div>
    <div class="mfoot" style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermerReponsePec()">Annuler</button>
      <button class="btn btn-primary" id="pec-submit-btn" onclick="submitReponsePec()">Envoyer ma réponse</button>
    </div>
  </div>
</div>

<!-- MODAL DETAIL POINT -->
<div class="modal-overlay" id="mDetail">
  <div class="modal" style="width:700px">
    <div class="mhdr"><h3><i class="ph ph-chart-bar" aria-hidden="true"></i> Détail du point</h3>
      <div style="display:flex;gap:8px">
        <button class="btn btn-secondary btn-sm" onclick="printPoint(currentPointId)" title="Imprimer"><i class="ph ph-printer" aria-hidden="true"></i> Imprimer</button>
        <button class="mclose" onclick="document.getElementById('mDetail').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
      </div>
    </div>
    <div class="mbody" id="detail-body"></div>
  </div>
</div>

<script>
const TYPES_V = <?= json_encode(array_values($types_v)) ?>;
const SERIES  = {VP:'A',CAM:'B',SEMI:'C',MOTO:'D'};
let bobinesCache = {};
let stockRivets  = 0;
let stockGonfl   = 0;
let stockEclate  = 0;
let previewOpen  = false;

// ── OUVRIR FORMULAIRE
function onTypePointChange(type){
  const info=document.getElementById('type-info');
  if(!info)return;
  const msgs={'point_9h':'📊 Suivi uniquement — non envoyé en validation.','point_13h':'📊 Suivi uniquement — non envoyé en validation.','point_18h':'✅ Ce point sera envoyé en validation au superviseur.'};
  const colors={'point_9h':'#dbeafe','point_13h':'#dbeafe','point_18h':'#d1fae5'};
  const textColors={'point_9h':'#1d4ed8','point_13h':'#1d4ed8','point_18h':'#065f46'};
  info.textContent=msgs[type]||'';
  info.style.background=colors[type]||'#f8f9fa';
  info.style.color=textColors[type]||'#333';
  onSansProdChange();
}
// Journée sans production : grise la saisie de production (le serveur remet
// de toute façon tout à zéro) ; disponible pour le seul point 18h.
function onSansProdChange(){
  const cb = document.getElementById('p-sans-prod');
  if (!cb) return;
  const est18h = document.getElementById('p-type').value === 'point_18h';
  document.getElementById('sp-bloc').style.display = est18h ? '' : 'none';
  if (!est18h) cb.checked = false;
  document.getElementById('sp-details').style.display = cb.checked ? '' : 'none';
  document.getElementById('mPoint').classList.toggle('sans-prod', cb.checked);
  document.getElementById('sp-comment-req').style.display =
    document.getElementById('p-sp-motif').value === 'autre' ? '' : 'none';
}
function openPointForm(){
  resetPointForm();
  document.getElementById('mPointTitre').textContent = 'Nouveau point journalier';
  document.getElementById('mPoint').classList.add('open');
  <?php if($role_slug_pj === 'coordinateur_site' && $user['site_id']): ?>
  // Coordinateur : site déjà fixé, charger automatiquement
  loadStockRivets();
  loadBobines();
  loadStockPMMA();
  toggleReimmatSections();
  <?php endif; ?>
}
// ── AFFICHAGE SECTIONS DUPLICATAS / POST-IMMAT (site operation='reimmat_post')
function toggleReimmatSections(){
  const el = document.getElementById('p-site');
  if(!el) return;
  const operation = el.tagName === 'SELECT'
    ? (el.options[el.selectedIndex]?.dataset.operation || 'primo')
    : (el.dataset.operation || 'primo');
  const show = operation === 'reimmat_post';
  const dup = document.getElementById('section-duplicata');
  const pi  = document.getElementById('section-postimmat');
  if(dup) dup.style.display = show ? '' : 'none';
  if(pi)  pi.style.display  = show ? '' : 'none';
  const titre = document.getElementById('vehicules-poses-title');
  if(titre) titre.textContent = show ? 'Re-Immatriculation' : 'Véhicules posés';
}
function resetPointForm(){
  TYPES_V.forEach(tv=>{
    const el=document.getElementById('nb-'+tv.code); if(el) el.value=0;
    const elDup=document.getElementById('nb-'+tv.code+'-DUP'); if(elDup) elDup.value=0;
    const plqDup=document.getElementById('plq-'+tv.code+'-DUP'); if(plqDup){ plqDup.value=0; plqDup.dataset.manuel=''; }
    const elPi=document.getElementById('nb-'+tv.code+'-PI');  if(elPi)  elPi.value=0;
  });
  <?php if($role_slug_pj !== 'coordinateur_site'): ?>
  document.getElementById('p-site').value='';
  <?php endif; ?>
  toggleReimmatSections();
  resetObservations();
  document.getElementById('p-date').value='<?= date('Y-m-d') ?>';
  // 'final' n'est plus une option de la liste (le type restait vide) : le
  // point 18h est le choix présélectionné du formulaire.
  document.getElementById('p-type').value='point_18h';
  onTypePointChange('point_18h');
  document.getElementById('p-heures').value='8';
  document.getElementById('p-gonfl-util').value='0';
  document.getElementById('p-gonfl-end').value='0';
  document.getElementById('p-eclate-util').value='0';
  document.getElementById('p-eclate-end').value='0';
  document.getElementById('p-np-conc').value='0';
  document.getElementById('p-np-usag').value='0';
  document.getElementById('p-stock-rivets').value='';
  document.getElementById('films-container').innerHTML='<div style="text-align:center;color:var(--muted);padding:20px;font-size:13px"><?= $role_slug_pj === 'coordinateur_site' ? 'Chargement...' : 'Sélectionnez un site pour charger les bobines disponibles.' ?></div>';
  document.getElementById('pmma-container').innerHTML='<div style="text-align:center;color:var(--muted);padding:12px;font-size:13px">Chargement du stock PMMA...</div>';
  document.getElementById('pAlert').innerHTML='';
  document.getElementById('point-preview').style.display='none';
  bobinesCache={}; stockRivets=0; stockGonfl=0; stockEclate=0;
  endommagementsSaisis = {};
  ordreEndo = {}; ordreEndoSeq = 0;
  if (document.getElementById('p-sans-prod')) {
    document.getElementById('p-sans-prod').checked = false;
    document.getElementById('p-sp-motif').value = '';
    document.getElementById('p-sp-comment').value = '';
    onSansProdChange();
  }
  recalcule();
}

// ── CALCUL TEMPS RÉEL
function recalcule(){
  let engins=0,plaques=0;
  TYPES_V.forEach(tv=>{
    const n=parseInt(document.getElementById('nb-'+tv.code)?.value||0);
    engins  += n;
    plaques += n * tv.nb_plaques;
  });
  const gonflUtil  = parseInt(document.getElementById('p-gonfl-util')?.value||0);
  const gonflEnd   = parseInt(document.getElementById('p-gonfl-end')?.value||0);
  const eclateUtil = parseInt(document.getElementById('p-eclate-util')?.value||0);
  const eclateEnd  = parseInt(document.getElementById('p-eclate-end')?.value||0);
  const totalGonfl  = gonflUtil + gonflEnd;
  const totalEclate = eclateUtil + eclateEnd;
  const totalRivets = totalGonfl + totalEclate;
  const heures = parseFloat(document.getElementById('p-heures')?.value||8);
  // V/H sur tous les engins posés (Duplicatas et Post-Immat compris) —
  // même calcul que l'enregistrement du point.
  const extra = {DUP:{engins:0,plaques:0}, PI:{engins:0,plaques:0}};
  ['DUP','PI'].forEach(s => TYPES_V.forEach(tv => {
    const n = parseInt(document.getElementById('nb-'+tv.code+'-'+s)?.value||0) || 0;
    const plq = s==='DUP' ? document.getElementById('plq-'+tv.code+'-DUP') : null;
    extra[s].engins  += n;
    extra[s].plaques += plq ? (parseInt(plq.value||0)||0) : n * tv.nb_plaques;
  }));
  const enginsExtra = extra.DUP.engins + extra.PI.engins;
  const moy    = heures>0 ? ((engins+enginsExtra)/heures).toFixed(1) : 0;

  document.getElementById('c-engins').textContent=engins;
  document.getElementById('c-plaques').textContent=plaques;
  document.getElementById('c-rivets').textContent=totalRivets;
  document.getElementById('c-moy').textContent=moy+' V/H';
  // Duplicatas et Post-Immat restent à part des totaux Primo : sous-ligne.
  const lib = {DUP:'dup.', PI:'post-immat'};
  const sousLigne = k => ['DUP','PI'].filter(s=>extra[s][k]>0)
    .map(s=>'+ '+extra[s][k]+' '+lib[s]).join(' · ');
  document.getElementById('c-engins-x').textContent  = sousLigne('engins');
  document.getElementById('c-plaques-x').textContent = sousLigne('plaques');

  // Info par type
  const ig=document.getElementById('info-gonfl');
  if(ig){
    ig.textContent=`Stock: ${stockGonfl.toLocaleString('fr-FR')} | Sortis: ${totalGonfl}`;
    ig.style.color=totalGonfl>stockGonfl?'var(--danger-d)':'#1565c0';
  }
  const ie=document.getElementById('info-eclate');
  if(ie){
    ie.textContent=`Stock: ${stockEclate.toLocaleString('fr-FR')} | Sortis: ${totalEclate}`;
    ie.style.color=totalEclate>stockEclate?'var(--danger-d)':'#880e4f';
  }

  const rw=document.getElementById('c-rivets-wrap');
  const si=document.getElementById('c-stock-info');
  if(stockRivets>0){
    rw.className='calc-item '+(totalRivets>stockRivets?'danger':totalRivets>stockRivets*0.8?'warn':'success');
    si.textContent=`Stock rivets: ${stockRivets} | Sortis: ${totalRivets}${totalRivets>stockRivets?' ⚠️ INSUFFISANT':''}`;
  }
  if(previewOpen) updatePreview(engins,plaques,moy);
}

// ── CALCUL DUPLICATAS / POST-IMMAT (totaux séparés, jamais fusionnés)
// Duplicata VP / camion : plaques posées par défaut 2 par véhicule, ajustables
// entre 1 et 2 par véhicule ; une saisie manuelle est conservée tant
// qu'elle reste dans ces limites.
function plaquesDupSync(code){
  const el = document.getElementById('plq-'+code+'-DUP');
  if (!el) return;
  const nb = parseInt(document.getElementById('nb-'+code+'-DUP')?.value||0) || 0;
  el.min = nb; el.max = 2*nb;
  let v = parseInt(el.value||0) || 0;
  if (el.dataset.manuel !== '1') v = 2*nb;
  el.value = Math.min(2*nb, Math.max(nb, v));
}
function recalculeExtra(){
  recalcule();   // totaux Duplicatas / Post-Immat et V/H calculés dans recalcule()
}

// ── CHARGER STOCK RIVETS
function loadStockRivets(){
  const sid=document.getElementById('p-site').value;
  if(!sid) return;
  ap({action:'get_stock_rivets',site_id:sid}).then(d=>{
    stockGonfl  = d.data.gonflable||0;
    stockEclate = d.data.eclate||0;
    stockRivets = stockGonfl + stockEclate;
    document.getElementById('p-stock-rivets').value=
      `🔵 ${stockGonfl.toLocaleString('fr-FR')} gonfl. | 🔴 ${stockEclate.toLocaleString('fr-FR')} écl.`;
    document.getElementById('p-stock-rivets').style.color=stockRivets<200?'var(--danger-d)':stockRivets<1000?'var(--warning-d)':'var(--navy)';
    recalcule();
  });
}

// ── CHARGER BOBINES DU SITE
let bobinesEnCours = []; // toutes les bobines en utilisation du site

// ── PMMA
let pmmaStockDisponible = {}; // type_pmma => quantite

async function loadStockPMMA(){
  const sid = document.getElementById('p-site').value;
  const pc  = document.getElementById('pmma-container');
  pmmaStockDisponible = {};
  if(!sid){
    pc.innerHTML='<div style="text-align:center;color:var(--muted);padding:12px;font-size:13px">Sélectionnez un site.</div>';
    return;
  }
  pc.innerHTML='<div style="text-align:center;color:var(--muted);padding:12px">⏳...</div>';
  const d = await ap({action:'get_stock_pmma', site_id:sid});
  const types = d.data || [];
  if(!types.length){
    pc.innerHTML='<div style="text-align:center;color:var(--muted);padding:12px;background:var(--lighter);border-radius:10px;font-size:13px">Aucun stock PMMA disponible sur ce site.</div>';
    return;
  }
  types.forEach(t => { pmmaStockDisponible[t.type_pmma] = t.quantite; });

  let opts = types.map(t=>`<option value="${t.type_pmma}">${t.type_pmma} (stock : ${t.quantite})</option>`).join('');
  pc.innerHTML=`
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
      <select id="pmma-select" class="form-control" style="flex:1;font-size:13px">
        <option value="">— Sélectionner un type de PMMA —</option>
        ${opts}
      </select>
      <button type="button" class="btn btn-secondary" style="white-space:nowrap;font-size:13px" onclick="ajouterLignePmma()">+ Ajouter</button>
    </div>
    <div id="pmma-lignes"></div>`;
}

function ajouterLignePmma(){
  const sel = document.getElementById('pmma-select');
  const type = sel.value;
  if(!type) return;
  ajouterLignePmmaType(type);
  sel.value='';
}

// Ajoute la ligne d'un type ; `deja` = quantité déjà sortie par le point
// rouvert, rendue au stock affiché (le serveur la restaure avant contrôle).
function ajouterLignePmmaType(type, deja = 0){
  // Éviter les doublons
  if(document.querySelector(`#pmma-lignes [data-type="${CSS.escape(type)}"]`)) return null;
  let lignes = document.getElementById('pmma-lignes');
  if(!lignes){
    document.getElementById('pmma-container').innerHTML = '<div id="pmma-lignes"></div>';
    lignes = document.getElementById('pmma-lignes');
  }
  const stock = (pmmaStockDisponible[type] ?? 0) + deja;
  const div = document.createElement('div');
  div.dataset.type = type;
  div.style.cssText='display:grid;grid-template-columns:1fr 100px 100px 100px 32px;gap:8px;align-items:end;padding:8px 10px;background:var(--lighter);border-radius:9px;margin-bottom:6px';
  div.innerHTML=`
    <div style="font-size:13px;font-weight:600;color:var(--navy);padding-bottom:2px"><i class="ph ph-app-window" aria-hidden="true"></i> ${type} <span style="font-size:12px;color:var(--muted);font-weight:400">stock : ${stock}</span></div>
    <div>
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Utilisés</label>
      <input type="number" data-kind="util" min="0" max="${stock}" value="0"
             class="form-control" style="font-size:13px;text-align:center">
    </div>
    <div>
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Endommagés</label>
      <input type="number" data-kind="end" min="0" max="${stock}" value="0"
             class="form-control" style="font-size:13px;text-align:center"
             onchange="onPmmaEndChange(this.closest('[data-type]').dataset.type, this)">
    </div>
    <div>
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px" title="PMMA reçus qui ne respectent pas les règles">Non conformes</label>
      <input type="number" data-kind="nc" min="0" max="${stock}" value="0"
             class="form-control" style="font-size:13px;text-align:center">
    </div>
    <button type="button" onclick="this.closest('[data-type]').remove()"
            style="background:#fee2e2;border:none;border-radius:7px;cursor:pointer;font-size:15px;color:#991b1b;width:32px;height:32px;margin-bottom:1px"><i class="ph ph-x" aria-hidden="true"></i></button>`;
  lignes.appendChild(div);
  return div;
}

function collectPmmaData(){
  const result=[];
  document.querySelectorAll('#pmma-lignes [data-type]').forEach(row=>{
    const type_pmma = row.dataset.type;
    const util   = parseInt(row.querySelector('[data-kind="util"]')?.value||0);
    const endomm = parseInt(row.querySelector('[data-kind="end"]')?.value||0);
    const nc     = parseInt(row.querySelector('[data-kind="nc"]')?.value||0);
    result.push({type_pmma, utilises:util, endommages:endomm, non_conformes:nc});
  });
  return result;
}

// ── OBSERVATIONS (n° 16 réunion ERP) — plusieurs lignes typées et colorées
const OBS_TYPES = {
  info:     {label:'Info',     icon:'ℹ️', border:'#64748b', bg:'#f1f5f9', text:'#334155'},
  alerte:   {label:'Alerte',   icon:'⚠️', border:'#d97706', bg:'#fef9e7', text:'#92400e'},
  relance:  {label:'Relance',  icon:'🔁', border:'#1565c0', bg:'#e3f2fd', text:'#1565c0'},
  incident: {label:'Incident', icon:'🔴', border:'#c0392b', bg:'#fdecea', text:'#c0392b'},
  urgence:  {label:'Urgence',  icon:'🚨', border:'#991b1b', bg:'#fee2e2', text:'#991b1b'},
  autre:    {label:'Autre',    icon:'📝', border:'#7c3aed', bg:'#f5f3ff', text:'#6d28d9'},
};
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s){ return escHtml(s).replace(/"/g,'&quot;'); }

function ajouterObservation(type, texte){
  type = type || 'info'; texte = texte || '';
  const optsHtml = Object.entries(OBS_TYPES).map(([k,v])=>`<option value="${k}"${k===type?' selected':''}>${v.icon} ${v.label}</option>`).join('');
  const div = document.createElement('div');
  div.setAttribute('data-obs-row','1');
  div.style.cssText='display:flex;gap:8px;align-items:center;margin-bottom:6px;padding:6px 8px;border-radius:8px;border-left:3px solid';
  div.innerHTML = `
    <span class="obs-num" style="font-size:12px;font-weight:700;color:var(--muted);flex:0 0 56px">Obs.</span>
    <select class="form-control" style="flex:0 0 130px;font-size:12.5px" onchange="majCouleurObservation(this)">${optsHtml}</select>
    <input type="text" class="form-control" style="flex:1;font-size:13px" placeholder="Observation…" value="${escAttr(texte)}">
    <button type="button" onclick="this.closest('[data-obs-row]').remove();renumeroterObservations();"
            style="background:#fee2e2;border:none;border-radius:7px;cursor:pointer;font-size:14px;color:#991b1b;width:30px;height:30px;flex:0 0 30px"><i class="ph ph-x" aria-hidden="true"></i></button>`;
  document.getElementById('obs-lignes').appendChild(div);
  majCouleurObservation(div.querySelector('select'));
  renumeroterObservations();
}
function renumeroterObservations(){
  document.querySelectorAll('#obs-lignes [data-obs-row]').forEach((row,i)=>{
    const num = row.querySelector('.obs-num');
    if(num) num.textContent = `Obs. ${i+1}`;
  });
}
function majCouleurObservation(select){
  const row = select.closest('[data-obs-row]');
  const cfg = OBS_TYPES[select.value] || OBS_TYPES.info;
  row.style.background = cfg.bg;
  row.style.borderLeftColor = cfg.border;
}
function resetObservations(){
  document.getElementById('obs-lignes').innerHTML = '';
  ajouterObservation('info', '');
}
function chargerObservations(raw){
  document.getElementById('obs-lignes').innerHTML = '';
  let list = [];
  if (raw) {
    try { const parsed = JSON.parse(raw); if (Array.isArray(parsed)) list = parsed; }
    catch(e) { list = [{type:'autre', texte:raw}]; } // ancien format texte libre, saisi avant ce lot
  }
  if (!list.length) { ajouterObservation('info',''); return; }
  list.forEach(o=>ajouterObservation(o.type, o.texte));
}
function collectObservations(){
  const result = [];
  document.querySelectorAll('#obs-lignes [data-obs-row]').forEach(row=>{
    const type  = row.querySelector('select').value;
    const texte = row.querySelector('input[type="text"]').value.trim();
    if (texte) result.push({type, texte});
  });
  return result;
}
function renderObservationsSection(raw){
  let list = [];
  if (raw) {
    try { const parsed = JSON.parse(raw); if (Array.isArray(parsed)) list = parsed.filter(o=>o && o.texte); }
    catch(e) { if (String(raw).trim()) list = [{type:'autre', texte:raw}]; }
  }
  if (!list.length) return '';
  const rows = list.map(o=>{
    const cfg = OBS_TYPES[o.type] || OBS_TYPES.info;
    return `<div style="background:${cfg.bg};border-left:3px solid ${cfg.border};border-radius:8px;padding:8px 12px;margin-bottom:6px">
      <span style="font-size:12px;font-weight:700;color:${cfg.text};text-transform:uppercase;letter-spacing:.4px">${cfg.icon} ${cfg.label}</span>
      <div style="font-size:13px;color:var(--navy);margin-top:2px">${escHtml(o.texte)}</div>
    </div>`;
  }).join('');
  return `<div style="margin-bottom:20px">
    <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">Observations</div>
    ${rows}
  </div>`;
}

async function loadBobines(){
  const sid=document.getElementById('p-site').value;
  const fc=document.getElementById('films-container');
  if(!sid){fc.innerHTML='<div style="text-align:center;color:var(--muted);padding:20px">Sélectionnez un site.</div>';return;}
  fc.innerHTML='<div style="text-align:center;color:var(--muted);padding:20px">⏳ Chargement...</div>';
  const d = await ap({action:'get_bobines_site',site_id:sid});
  bobinesCache={};
  bobinesEnCours = d.data||[];
  bobinesEnCours.forEach(b=>bobinesCache[b.id]=b);

  if(!bobinesEnCours.length){
    fc.innerHTML='<div style="text-align:center;color:var(--muted);padding:24px;background:var(--lighter);border-radius:10px">⚠️ Aucune bobine en utilisation sur ce site.</div>';
    return;
  }
  // Afficher 1 ligne de saisie par défaut (la première bobine)
  renderBobinesRows([bobinesEnCours[0].id]);
}

function renderBobinesRows(selectedIds){
  const fc=document.getElementById('films-container');
  const rows = selectedIds.map((bid,i)=>buildBobineRow(bid,i)).join('');
  fc.innerHTML=`
    <div id="bobine-rows">${rows}</div>
    <button type="button" onclick="addBobineRow()"
      style="margin-top:10px;width:100%;padding:10px;border:2px dashed var(--border);border-radius:10px;
             background:none;color:var(--primary-d);font-weight:600;font-size:13px;cursor:pointer">
      + Ajouter une bobine
    </button>`;
}

function buildBobineRow(selectedBobineId, idx){
  const opts = bobinesEnCours.map(b=>
    `<option value="${b.id}" ${b.id==selectedBobineId?'selected':''}>${b.numero} — ${b.type_code||''} — ${b.films_restants} films restants</option>`
  ).join('');
  const b = bobinesCache[selectedBobineId]||{};
  return `
    <div class="bobine-saisie-row" id="bsrow-${idx}" style="
      display:grid;grid-template-columns:1fr 110px 110px 110px 40px;gap:10px;
      align-items:center;background:var(--lighter);border-radius:12px;
      padding:12px 14px;margin-bottom:8px;border:1.5px solid var(--border)">
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Bobine en utilisation</label>
        <select class="form-control" id="bsel-${idx}" onchange="onBobineChange(${idx})" style="margin-top:4px">
          ${opts}
        </select>
        <div id="binfo-${idx}" style="font-size:12px;color:var(--muted);margin-top:3px">
          ${b.films_restants||0} films restants · ${b.type_code||''}
        </div>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.5px">Films utilisés</label>
        <input type="number" class="form-control" id="butil-${idx}" min="0"
               max="${b.films_restants||500}" value="0"
               oninput="updateBobineRestants(${idx})" style="margin-top:4px;text-align:center;font-size:16px;font-weight:700">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--danger-d);text-transform:uppercase;letter-spacing:.5px">Endommagés</label>
        <input type="number" class="form-control" id="bendomm-${idx}" min="0" value="0"
               oninput="updateBobineRestants(${idx})" onchange="onEndommageChange(${idx})"
               style="margin-top:4px;text-align:center;border-color:var(--danger)">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.5px" title="Films reçus qui ne respectent pas les règles : déclarés, mais pas retirés du stock de la bobine">Non conformes</label>
        <input type="number" class="form-control" id="bnc-${idx}" min="0" value="0"
               oninput="updateBobineRestants(${idx})"
               style="margin-top:4px;text-align:center;border-color:#c4b5fd">
      </div>
      <div style="text-align:center;margin-top:18px">
        ${idx>0?`<button type="button" onclick="removeBobineRow(${idx})"
          style="background:none;border:none;color:var(--danger-d);font-size:20px;cursor:pointer;padding:4px">✕</button>`:''}
      </div>
    </div>`;
}

let nbBobineRows=1;
function addBobineRow(){
  // Trouver une bobine pas encore sélectionnée
  const usedIds = getSelectedBobineIds();
  const nextB = bobinesEnCours.find(b=>!usedIds.includes(String(b.id)));
  if(!nextB){alert('Toutes les bobines en utilisation sont déjà ajoutées.');return;}
  const container=document.getElementById('bobine-rows');
  const div=document.createElement('div');
  div.innerHTML=buildBobineRow(nextB.id, nbBobineRows);
  container.appendChild(div.firstElementChild);
  nbBobineRows++;
}

function removeBobineRow(idx){
  const el=document.getElementById('bsrow-'+idx);
  if(el) el.remove();
  recalcule();
}

function onBobineChange(idx){
  const bid=document.getElementById('bsel-'+idx).value;
  const b=bobinesCache[bid]||{};
  document.getElementById('binfo-'+idx).textContent=`${b.films_restants||0} films restants · ${b.type_code||''}`;
  document.getElementById('butil-'+idx).max=b.films_restants||500;
  updateBobineRestants(idx);
}

function updateBobineRestants(idx){
  const bid=document.getElementById('bsel-'+idx)?.value;
  const b=bobinesCache[bid]||{films_restants:0};
  const util=parseInt(document.getElementById('butil-'+idx)?.value||0);
  const endomm=parseInt(document.getElementById('bendomm-'+idx)?.value||0);
  const restants=b.films_restants-(util+endomm);   // non conformes : pas retirés du stock
  const info=document.getElementById('binfo-'+idx);
  if(info){
    info.textContent=`${Math.max(0,restants)} films restants · ${b.type_code||''}`;
    info.style.color=restants<0?'var(--danger-d)':restants<50?'var(--warning-d)':'var(--muted)';
  }
  recalcule();
}

function getSelectedBobineIds(){
  return Array.from(document.querySelectorAll('[id^="bsel-"]')).map(s=>s.value);
}

/* ══════════════════════════════════════════════════════════════
   n° 2.1 CR PDG — déclaration d'endommagement
   Le pop-up s'ouvre dès qu'une quantité endommagée est saisie, et
   affiche une ligne indépendante par bobine concernée. Les réponses
   sont conservées ici, indexées par bobine, puis envoyées avec le
   point : une ligne par bobine, comme demandé au compte rendu.
   ══════════════════════════════════════════════════════════════ */
// Étapes par objet (plan comparaison OptoPlate, 2026-10-15) : film et PMMA
// ont leur propre liste, le rivet reste inchangé. "pose" n'est plus
// proposé pour un film (sauf "pose (ancien)" injecté à l'affichage d'une
// déclaration existante — cf. ouvrirPopupEndommagement).
const ETAPES_ENDO = {
  film: [
    ['verification', 'À la vérification'],
    ['impression',   "À l'impression"],
    ['lamination',   'À la lamination'],
    ['percee',       'À la percée'],
    ['fissuree',     'Plaque fissurée'],
    ['transport',    'Au transport'],
    ['stockage',     'Au stockage'],
    ['autre',        'Autre'],
  ],
  pmma: [
    ['lamination', 'À la lamination'],
    ['percee',     'À la percée'],
    ['transport',  'Au transport'],
    ['stockage',   'Au stockage'],
    ['autre',      'Autre'],
  ],
  rivet: [
    ['pose',       'À la pose'],
    ['impression', "À l'impression"],
    ['lamination', 'À la lamination'],
    ['transport',  'Au transport'],
    ['stockage',   'Au stockage'],
    ['autre',      'Autre'],
  ],
};
// Causes proposées en suggestion (datalist) — la cause est en saisie libre.
// Sert aussi à réafficher en clair une cause enregistrée sous l'ancien code.
const CAUSES_ENDO = [
  ['manipulation',     'Manipulation incorrecte'],
  ['defaut_materiel',  'Défaut matériel'],
  ['incident_externe', 'Incident externe'],
  ['autre',            'Autre'],
];
// Rivets et PMMA endommagés ne se justifient qu'une fois les colonnes
// objet/sous_type créées (sql/migration_endommagements_rivets_pmma.sql).
const ENDO_MULTI = <?= db_colonne_existe('op_endommagements', 'objet') ? 'true' : 'false' ?>;
// Comparaison OptoPlate avant validation (plan 15/10/2026) — désactivée
// tant que les migrations correspondantes n'ont pas été jouées.
const OPTO_COMPARAISON_OK = <?= _pj_optoplate_comparaison_ok() ? 'true' : 'false' ?>;
// N° du consommable (obligatoire pour un film) — sql/migration_films_nc_numero_consommable.sql
const ENDO_NUMERO = <?= db_colonne_existe('op_endommagements', 'numero_consommable') ? 'true' : 'false' ?>;
// clé de groupe -> tableau d'une entrée par unité endommagée.
// Clés : « film:<bobine_id> », « rivet:gonflable|eclate », « pmma:<type> ».
let endommagementsSaisis = {};
// Une couleur par type d'élément, pour ne pas confondre films, rivets et
// PMMA dans le pop-up.
const ENDO_STYLE = {
  film:  {c:'#1565c0', bg:'#e3f2fd', bd:'#90caf9', ic:'ph-film-strip', lbl:'Film'},
  rivet: {c:'#6a1b9a', bg:'#f3e5f5', bd:'#ce93d8', ic:'ph-nut',        lbl:'Rivet'},
  pmma:  {c:'#00695c', bg:'#e0f2f1', bd:'#80cbc4', ic:'ph-app-window', lbl:'PMMA'},
};
// Ordre d'arrivée des groupes : le dernier saisi s'affiche en haut, les
// précédents descendent (on retrouve tout de suite ce qu'on vient d'ajouter).
let ordreEndo = {}, ordreEndoSeq = 0;

const escEndo = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

// Groupes à justifier, d'après ce qui est saisi dans le formulaire.
function groupesEndommages(){
  const g = getBobinesData().filter(f => (f.films_endommages|0) > 0).map(f => ({
    cle: 'film:'+f.bobine_id, objet: 'film', bobine_id: f.bobine_id, sous_type: null,
    titre: 'Bobine ' + ((bobinesCache[f.bobine_id]||{}).numero || f.bobine_id),
    unite: 'Film', nb: f.films_endommages|0,
  }));
  if (ENDO_MULTI){
    const gEnd = parseInt(document.getElementById('p-gonfl-end')?.value||0);
    const eEnd = parseInt(document.getElementById('p-eclate-end')?.value||0);
    if (gEnd > 0) g.push({cle:'rivet:gonflable', objet:'rivet', sous_type:'gonflable', titre:'Rivets gonflables', unite:'Rivet', nb:gEnd});
    if (eEnd > 0) g.push({cle:'rivet:eclate',    objet:'rivet', sous_type:'eclate',    titre:'Rivets éclatés',    unite:'Rivet', nb:eEnd});
    collectPmmaData().filter(pm => (pm.endommages|0) > 0).forEach(pm => g.push({
      cle:'pmma:'+pm.type_pmma, objet:'pmma', sous_type:pm.type_pmma, titre:'PMMA '+pm.type_pmma, unite:'PMMA', nb:pm.endommages|0,
    }));
  }
  return g;
}

// Appelé quand une quantité endommagée change : on ouvre le pop-up à la
// première saisie, sans harceler l'agent à chaque frappe suivante.
function onEndoQuantite(cle, nb){
  if (nb > 0 && !endommagementsSaisis[cle]) ouvrirPopupEndommagement();
  if (nb === 0) delete endommagementsSaisis[cle];
}
function onEndommageChange(idx){
  updateBobineRestants(idx);
  const bid = document.getElementById('bsel-'+idx)?.value;
  if (bid) onEndoQuantite('film:'+bid, parseInt(document.getElementById('bendomm-'+idx)?.value||0));
}
function onRivetEndChange(type, el){
  if (ENDO_MULTI) onEndoQuantite('rivet:'+type, parseInt(el.value||0));
}
function onPmmaEndChange(type, el){
  if (ENDO_MULTI) onEndoQuantite('pmma:'+type, parseInt(el.value||0));
}

function causeLibelle(c){
  return (CAUSES_ENDO.find(([v]) => v === c) || [null, c || ''])[1];
}

function ouvrirPopupEndommagement(){
  const groupes = groupesEndommages();
  const box = document.getElementById('endoLignes');
  if (!groupes.length){
    box.innerHTML = '<div style="color:var(--muted);font-size:13px">Aucun endommagement saisi.</div>';
  } else {
    const opt = (arr, sel) => arr.map(([v,l]) =>
      `<option value="${v}" ${sel===v?'selected':''}>${l}</option>`).join('');

    groupes.forEach(g => { if (!(g.cle in ordreEndo)) ordreEndo[g.cle] = ++ordreEndoSeq; });
    groupes.sort((a, b) => ordreEndo[b.cle] - ordreEndo[a.cle]);

    box.innerHTML = groupes.map(g => {
      const nb  = g.nb;
      const dej = endommagementsSaisis[g.cle] || [];
      const st  = ENDO_STYLE[g.objet] || ENDO_STYLE.film;
      const complet = dej.length === nb && dej.every(d => d.personne && d.cause);

      // Un bloc par unité : chacune peut avoir sa propre étape, sa cause et
      // sa personne. Le report ci-dessous évite de tout ressaisir quand
      // les unités ont été abîmées dans les mêmes circonstances.
      // Affichées de la plus récente à la plus ancienne ; data-idx garde le
      // vrai rang pour l'enregistrement.
      const unites = Array.from({length: nb}, (_, k) => nb - 1 - k).map(i => {
        const d = dej[i] || {};
        return `
        <div class="endo-film" data-cle="${escEndo(g.cle)}" data-idx="${i}"
             style="border:1px solid var(--border);border-left:4px solid ${st.c};border-radius:10px;padding:12px 14px;margin-top:10px;background:white">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:9px">
            <span style="font-size:12px;font-weight:800;color:${st.c};text-transform:uppercase;letter-spacing:.4px">
              ${g.unite} ${i+1} sur ${nb}
            </span>
            ${i===0 && nb>1 ? `<button type="button" class="btn btn-secondary btn-sm"
                 onclick="reporterEndommagement(this)"
                 title="Recopier ces valeurs sur les autres éléments de ce groupe">
                 <i class="ph ph-copy" aria-hidden="true"></i> Reporter sur les ${nb-1} autres</button>` : ''}
          </div>
          ${ENDO_NUMERO && g.objet !== 'rivet' ? `
          <div style="margin-bottom:10px">
            <label style="font-size:12px;font-weight:700;color:${st.c};text-transform:uppercase">N° du consommable${g.objet === 'film' ? ' *' : ''}</label>
            <input type="text" class="form-control endo-numero" maxlength="100" value="${escEndo(d.numero_consommable)}"
                   placeholder="${g.objet === 'film' ? 'Numéro du film endommagé' : 'Numéro du PMMA (si connu)'}"
                   style="margin-top:4px;font-family:monospace;font-weight:700">
          </div>` : ''}
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Personne concernée *</label>
              <input type="text" class="form-control endo-personne" value="${escEndo(d.personne)}"
                     placeholder="Nom de la personne" style="margin-top:4px">
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Heure de l'incident</label>
              <input type="time" class="form-control endo-heure" value="${escEndo(d.heure)}" style="margin-top:4px">
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Étape *</label>
              <select class="form-control endo-etape" style="margin-top:4px">${opt(
                (g.objet === 'film' && d.etape === 'pose')
                  ? [['pose','Pose (ancien)'], ...ETAPES_ENDO.film]
                  : (ETAPES_ENDO[g.objet] || ETAPES_ENDO.rivet),
                d.etape
              )}</select>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Cause *</label>
              <input type="text" class="form-control endo-cause" list="endoCausesList" maxlength="255"
                     value="${escEndo(causeLibelle(d.cause))}"
                     placeholder="Décrivez la cause" style="margin-top:4px">
            </div>
          </div>
          <div style="margin-top:9px">
            <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Observations</label>
            <textarea class="form-control endo-obs" rows="2" placeholder="Précisions libres…"
                      style="margin-top:4px">${escEndo(d.observations)}</textarea>
          </div>
        </div>`;
      }).join('');

      return `
      <div style="border:1.5px solid ${st.bd};border-left:6px solid ${st.c};border-radius:12px;padding:14px 16px;margin-bottom:14px;background:${st.bg}">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="background:${st.c};color:white;font-size:11px;font-weight:800;letter-spacing:.5px;padding:3px 9px;border-radius:20px;text-transform:uppercase">
            <i class="ph ${st.ic}" aria-hidden="true"></i> ${st.lbl}
          </span>
          <span style="font-weight:800;color:${st.c};font-size:14px">${escEndo(g.titre)}</span>
          ${complet ? `<span style="margin-left:auto;font-size:12px;font-weight:700;color:#2e7d32"><i class="ph ph-check-circle" aria-hidden="true"></i> Justifié</span>` : ''}
        </div>
        <div style="font-size:12px;color:var(--danger-d);font-weight:700;margin-top:4px">
          ${nb} endommagé(s) — une déclaration par ${g.unite.toLowerCase()}
        </div>
        ${unites}
      </div>`;
    }).join('');
  }
  document.getElementById('endoAlert').innerHTML = '';
  document.getElementById('mEndommagement').classList.add('open');
  box.scrollTop = 0;
  box.closest('.mbody')?.scrollTo?.(0, 0);
}

// Recopie la première unité sur les suivantes du même groupe.
function reporterEndommagement(btn){
  const src = btn.closest('.endo-film');
  const cle = src.dataset.cle;
  const blocs = [...document.querySelectorAll('#endoLignes .endo-film')].filter(b => b.dataset.cle === cle && b !== src);
  if (!blocs.length) return;
  const val = s => src.querySelector(s).value;
  for (let i = 0; i < blocs.length; i++){
    blocs[i].querySelector('.endo-personne').value = val('.endo-personne');
    blocs[i].querySelector('.endo-heure').value    = val('.endo-heure');
    blocs[i].querySelector('.endo-etape').value    = val('.endo-etape');
    blocs[i].querySelector('.endo-cause').value    = val('.endo-cause');
    blocs[i].querySelector('.endo-obs').value      = val('.endo-obs');
  }
  toast(`Valeurs reportées sur ${blocs.length} élément(s).`,'success');
}

function validerEndommagements(){
  const blocs = document.querySelectorAll('#endoLignes .endo-film');
  const saisie = {};
  for (const l of blocs){
    const personne = l.querySelector('.endo-personne').value.trim();
    if (!personne){
      document.getElementById('endoAlert').innerHTML =
        '<div class="alert alert-danger" style="font-size:13px">La personne concernée est obligatoire pour chaque élément endommagé.</div>';
      l.querySelector('.endo-personne').focus();
      l.scrollIntoView({behavior:'smooth', block:'center'});
      return;
    }
    const numEl = l.querySelector('.endo-numero');
    if (numEl && l.dataset.cle.startsWith('film:') && !numEl.value.trim()){
      document.getElementById('endoAlert').innerHTML =
        '<div class="alert alert-danger" style="font-size:13px">Le n° du consommable est obligatoire pour chaque film endommagé.</div>';
      numEl.focus();
      l.scrollIntoView({behavior:'smooth', block:'center'});
      return;
    }
    if (!l.querySelector('.endo-cause').value.trim()){
      document.getElementById('endoAlert').innerHTML =
        '<div class="alert alert-danger" style="font-size:13px">La cause est obligatoire pour chaque élément endommagé.</div>';
      l.querySelector('.endo-cause').focus();
      l.scrollIntoView({behavior:'smooth', block:'center'});
      return;
    }
    const cle = l.dataset.cle;
    (saisie[cle] = saisie[cle] || []).push({
      idx: parseInt(l.dataset.idx)||0,
      numero_consommable: numEl ? numEl.value.trim() : '',
      personne,
      etape:        l.querySelector('.endo-etape').value,
      cause:        l.querySelector('.endo-cause').value.trim(),
      heure:        l.querySelector('.endo-heure').value,
      observations: l.querySelector('.endo-obs').value.trim(),
    });
  }
  // Affichage décroissant -> on remet chaque groupe dans l'ordre 1, 2, 3…
  Object.values(saisie).forEach(arr => { arr.sort((a, b) => a.idx - b.idx); arr.forEach(x => delete x.idx); });
  endommagementsSaisis = saisie;
  document.getElementById('mEndommagement').classList.remove('open');
  toast('Déclaration(s) enregistrée(s).','success');
}

// Groupes dont le nombre de déclarations ne correspond pas (encore) à la
// quantité endommagée saisie.
function groupesNonJustifies(){
  return groupesEndommages().filter(g => {
    const d = endommagementsSaisis[g.cle];
    return !d || d.length !== g.nb;
  });
}

// Aplatit en une entrée par unité, et n'envoie que les groupes dont le
// nombre de déclarations correspond encore à la quantité endommagée :
// le serveur refuse tout écart, autant ne pas l'y conduire.
function collectEndommagements(){
  const sortie = [];
  groupesEndommages().forEach(g => {
    const d = endommagementsSaisis[g.cle];
    if (d && d.length === g.nb){
      d.forEach(x => sortie.push(Object.assign({objet: g.objet, bobine_id: g.bobine_id || null, sous_type: g.sous_type}, x)));
    }
  });
  return sortie;
}

// Recharge les déclarations déjà enregistrées d'un point rouvert : sans
// elles, la moindre mise à jour obligeait à tout re-justifier.
function chargerEndommagementsSaisis(liste){
  endommagementsSaisis = {};
  ordreEndo = {}; ordreEndoSeq = 0;
  (liste || []).forEach(e => {
    const objet = e.objet || 'film';
    const cle = objet === 'film' ? 'film:'+e.bobine_id : objet+':'+(e.sous_type||'');
    if (!(cle in ordreEndo)) ordreEndo[cle] = ++ordreEndoSeq;
    (endommagementsSaisis[cle] = endommagementsSaisis[cle] || []).push({
      numero_consommable: e.numero_consommable || '',
      personne: e.personne || '', etape: e.etape || 'autre', cause: e.cause || '',
      heure: (e.heure || '').slice(0,5), observations: e.observations || '',
    });
  });
}

function getBobinesData(){
  const rows=[];
  document.querySelectorAll('[id^="bsrow-"]').forEach(row=>{
    const idx=row.id.replace('bsrow-','');
    const bid=document.getElementById('bsel-'+idx)?.value;
    const util=parseInt(document.getElementById('butil-'+idx)?.value||0);
    const endomm=parseInt(document.getElementById('bendomm-'+idx)?.value||0);
    const nc=parseInt(document.getElementById('bnc-'+idx)?.value||0);
    if(bid&&(util+endomm+nc)>0) rows.push({bobine_id:bid,films_utilises:util,films_endommages:endomm,films_non_conformes:nc,type_vehicule_id:1});
  });
  return rows;
}

function toggleSerie(id){
  const el=document.getElementById(id);
  el.classList.toggle('open');
}

function checkBobineStock(bid){
  const util=parseInt(document.getElementById('bu-'+bid)?.value||0);
  const endomm=parseInt(document.getElementById('be-'+bid)?.value||0);
  const bobine=bobinesCache[bid];
  if(!bobine) return;
  const restants=bobine.films_restants-(util+endomm);
  const el=document.getElementById('brest-'+bid);
  if(el){ el.textContent=Math.max(0,restants); el.style.color=restants<0?'var(--danger-d)':restants<50?'var(--warning-d)':'var(--navy)'; }
}

// ── AUTO-REMPLIR FILMS selon nb véhicules
// Somme Primo + Duplicata + Post-Immat : les films consommés sont partagés
// (saisie globale), donc la suggestion doit couvrir les 3 catégories.
function autoFilmsFromVeh(code,tvId,serie){
  const nb=parseInt(document.getElementById('nb-'+code)?.value||0)
         + parseInt(document.getElementById('nb-'+code+'-DUP')?.value||0)
         + parseInt(document.getElementById('nb-'+code+'-PI')?.value||0);
  // Trouver la bobine en cours pour cette série
  const sid=document.getElementById('p-site').value; if(!sid) return;
  const bobinesDispos=Object.values(bobinesCache).filter(b=>b.serie===serie&&b.films_restants>0);
  if(!bobinesDispos.length) return;
  // Prendre la bobine en cours en premier
  const bobine=bobinesDispos.find(b=>b.statut==='en_cours')||bobinesDispos[0];
  const films=nb; // 1 film par véhicule par plaque → déjà géré dans le nb_plaques
  const input=document.getElementById('bu-'+bobine.id);
  if(input){ input.value=films; checkBobineStock(bobine.id); }
  // Ouvrir la série correspondante
  const el=document.getElementById('serie-'+serie);
  if(el&&!el.classList.contains('open')) el.classList.add('open');
}

// ── APERÇU
function togglePreview(){
  previewOpen=!previewOpen;
  document.getElementById('point-preview').style.display=previewOpen?'block':'none';
  if(previewOpen) recalcule();
}
function updatePreview(engins,plaques,moy){
  const date=document.getElementById('p-date').value;
  const type=document.getElementById('p-type').value;
  const pSiteEl=document.getElementById('p-site');
  const site=pSiteEl.tagName==='SELECT'?(pSiteEl.options[pSiteEl.selectedIndex]?.text||''):(pSiteEl.closest('.form-group')?.querySelector('div')?.textContent?.trim()||'<?= $role_slug_pj==='coordinateur_site'?h(db_fetch_value("SELECT nom FROM sites WHERE id=?",[(int)($user['site_id']??0)])??""):"" ?>');
  document.getElementById('prev-titre').textContent=(type==='final'?'Point Final':'Point Intermédiaire')+' — '+site;
  document.getElementById('prev-date').textContent='Date : '+new Date(date).toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'});
  const rivets=(parseInt(document.getElementById('c-rivets').textContent)||0);
  document.getElementById('prev-stats').innerHTML=[
    ['<i class="ph ph-car" aria-hidden="true"></i>',document.getElementById('nb-VP')?.value||0,'VP'],
    ['<i class="ph ph-truck" aria-hidden="true"></i>',document.getElementById('nb-CAM')?.value||0,'Camion'],
    ['<i class="ph-semi" aria-hidden="true"></i>',document.getElementById('nb-SEMI')?.value||0,'Semi'],
    ['<i class="ph ph-motorcycle" aria-hidden="true"></i>',document.getElementById('nb-MOTO')?.value||0,'Moto'],
  ].map(([i,v,l])=>`<div class="pp-stat"><div class="psv">${v}</div><div class="psl">${i} ${l}</div></div>`).join('');
  const detail=TYPES_V.map(tv=>{
    const n=parseInt(document.getElementById('nb-'+tv.code)?.value||0);
    return n>0?`<div class="pp-row"><span>• ${tv.libelle}</span><span>${n}</span></div>`:'';
  }).join('');
  document.getElementById('prev-detail').innerHTML=detail;
  document.getElementById('prev-engins').textContent=engins;
  document.getElementById('prev-plaques').textContent=plaques;
}

// ── SAUVEGARDER
function savePoint(){
  const site_id=document.getElementById('p-site').value;
  if(!site_id){document.getElementById('pAlert').innerHTML='<div class="alert alert-danger">Sélectionnez un site.</div>';return;}
  const btn=document.getElementById('btn-save-point');
  btn.disabled=true; btn.textContent='Enregistrement…';

  // Collecter films depuis le nouveau sélecteur dynamique
  const films_data = getBobinesData();

  // n° 2.1 CR PDG — chaque FILM endommagé doit porter sa déclaration : on
  // compare le nombre de déclarations à la quantité saisie, un simple
  // « la bobine a une déclaration » laisserait passer les films suivants.
  // On bloque avant l'envoi plutôt que de laisser le serveur refuser :
  // l'agent est déjà dans le formulaire, autant lui ouvrir le pop-up.
  const sansProd = !!document.getElementById('p-sans-prod')?.checked;
  if (sansProd) {
    const motif = document.getElementById('p-sp-motif').value;
    const com   = document.getElementById('p-sp-comment').value.trim();
    const err = !motif ? 'Choisissez le motif de la journée sans production.'
              : (motif === 'autre' && !com ? 'Précisez le motif « Autre » dans le commentaire.' : '');
    if (err) {
      btn.disabled = false; btn.textContent = '💾 Enregistrer le point';
      document.getElementById('pAlert').innerHTML = `<div class="alert alert-danger">${err}</div>`;
      document.getElementById('pAlert').scrollIntoView({behavior:'smooth', block:'center'});
      return;
    }
  }
  if (!sansProd && groupesNonJustifies().length) {
    btn.disabled = false; btn.textContent = '💾 Enregistrer le point';
    ouvrirPopupEndommagement();
    return;
  }

  ap({
    action:'save_point', site_id,
    date_point:  document.getElementById('p-date').value,
    type_point:  document.getElementById('p-type').value,
    nb_vp:       document.getElementById('nb-VP').value,
    nb_camion:   document.getElementById('nb-CAM').value,
    nb_semi:     document.getElementById('nb-SEMI').value,
    nb_moto:     document.getElementById('nb-MOTO').value,
    nb_vp_duplicata:     document.getElementById('nb-VP-DUP')?.value   || 0,
    nb_camion_duplicata: document.getElementById('nb-CAM-DUP')?.value  || 0,
    nb_semi_duplicata:   document.getElementById('nb-SEMI-DUP')?.value || 0,
    nb_moto_duplicata:   document.getElementById('nb-MOTO-DUP')?.value || 0,
    plaques_vp_duplicata:     document.getElementById('plq-VP-DUP')?.value  ?? '',
    plaques_camion_duplicata: document.getElementById('plq-CAM-DUP')?.value ?? '',
    nb_vp_postimmat:     document.getElementById('nb-VP-PI')?.value    || 0,
    nb_camion_postimmat: document.getElementById('nb-CAM-PI')?.value   || 0,
    nb_semi_postimmat:   document.getElementById('nb-SEMI-PI')?.value  || 0,
    nb_moto_postimmat:   document.getElementById('nb-MOTO-PI')?.value  || 0,
    rivets_gonflables:     document.getElementById('p-gonfl-util').value,
    rivets_gonflables_end: document.getElementById('p-gonfl-end').value,
    rivets_eclates:        document.getElementById('p-eclate-util').value,
    rivets_eclates_end:    document.getElementById('p-eclate-end').value,
    non_poses_concessionnaires: document.getElementById('p-np-conc').value,
    non_poses_usagers: document.getElementById('p-np-usag').value,
    nb_heures:   document.getElementById('p-heures').value,
    observations:JSON.stringify(collectObservations()),
    films_data:  JSON.stringify(films_data),
    pmma_data:   JSON.stringify(collectPmmaData()),
    endommagements_data: JSON.stringify(collectEndommagements()),
    sans_production: sansProd ? 1 : 0,
    motif_sans_production: sansProd ? document.getElementById('p-sp-motif').value : '',
    commentaire_sans_production: sansProd ? document.getElementById('p-sp-comment').value.trim() : '',
  }).then(d=>{
    btn.disabled=false; btn.textContent='💾 Enregistrer le point';
    if(d.success){
      toast(d.message,'success');
      document.getElementById('mPoint').classList.remove('open');
      setTimeout(()=>location.reload(),900);
    } else {
      document.getElementById('pAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
      document.getElementById('pAlert').scrollIntoView({behavior:'smooth', block:'center'});
    }
  }).catch(err=>{
    // Réponse illisible (erreur serveur, coupure réseau) : sans ce catch, le
    // bouton restait figé sur « Enregistrement… » sans rien dire.
    btn.disabled=false; btn.textContent='💾 Enregistrer le point';
    document.getElementById('pAlert').innerHTML=
      `<div class="alert alert-danger">Échec de l'enregistrement : ${escEndo(err.message)}</div>`;
    document.getElementById('pAlert').scrollIntoView({behavior:'smooth', block:'center'});
  });
}

// ── VOIR DÉTAIL POINT
// Point affiché dans le détail ou ouvert en modification — sert au bouton
// « Imprimer » du détail. N'était renseigné que par editPoint() : pour un
// superviseur qui ne fait que consulter, la variable n'existait pas et le
// bouton levait une erreur sans rien ouvrir.
var currentPointId = null;
async function viewPoint(id){
  currentPointId = id;
  document.getElementById('mDetail').classList.add('open');
  document.getElementById('detail-body').innerHTML='<div style="text-align:center;padding:40px;color:var(--muted)">Chargement…</div>';
  const pts=<?= json_encode(array_values($points)) ?>;
  const p=pts.find(x=>x.id==id);
  if(!p){document.getElementById('detail-body').innerHTML='Introuvable.';return;}
  // Charger PMMA + films via AJAX
  let pmmaList=[], filmsList=[];
  try {
    const det = await ap({action:'get_point_detail', point_id:id});
    if(det.success){ pmmaList=det.data.pmma||[]; filmsList=det.data.films||[]; }
  } catch(e){}

  const statutCfg={
    'valide':               {bg:'#d1fae5',col:'#065f46',icon:'✅',lbl:'Validé'},
    'brouillon':            {bg:'#fef3c7',col:'#92400e',icon:'⏳',lbl:'Brouillon'},
    'en_attente_validation':{bg:'#dbeafe',col:'#1d4ed8',icon:'📤',lbl:'En attente validation'},
    'suivi':                {bg:'#f1f5f9',col:'#475569',icon:'📊',lbl:'Suivi'},
    'rejete':               {bg:'#fee2e2',col:'#991b1b',icon:'✏️',lbl:'Modif. demandée'},
  };
  const typeLbl={'point_9h':'Point 9h','point_13h':'Point 13h','point_18h':'Point 18h — Final','final':'Final','intermediaire':'Intermédiaire'};
  const sc = statutCfg[p.statut]||{bg:'#f3f4f6',col:'#374151',icon:'·',lbl:p.statut};
  const dateStr = new Date(p.date_point).toLocaleDateString('fr-FR',{weekday:'long',day:'2-digit',month:'long',year:'numeric'});

  // Véhicules
  const vehs=[['<i class="ph ph-car" aria-hidden="true"></i>','VP',p.nb_vp],['<i class="ph ph-truck" aria-hidden="true"></i>','Camion',p.nb_camion],['<i class="ph-semi" aria-hidden="true"></i>','Semi',p.nb_semi],['<i class="ph ph-motorcycle" aria-hidden="true"></i>','Moto',p.nb_moto]];
  const vehCards = vehs.map(([ic,lb,nb])=>`
    <div style="background:${nb>0?'#e3f2fd':'#f8fafc'};border-radius:10px;padding:12px;text-align:center;border:1px solid ${nb>0?'#90caf9':'#e2e8f0'}">
      <div style="font-size:20px">${ic}</div>
      <div style="font-family:'Montserrat',sans-serif;font-size:20px;font-weight:900;color:${nb>0?'#1565c0':'#cbd5e1'}">${nb}</div>
      <div style="font-size:12px;color:${nb>0?'#1565c0':'#94a3b8'};font-weight:600;text-transform:uppercase">${lb}</div>
    </div>`).join('');

  // Duplicatas / Post-Immat (jamais inclus dans les totaux Primo ci-dessus)
  const reimmatCat = [
    ['Duplicatas', '#6b21a8', '#f3e8ff', p.nb_vp_duplicata, p.nb_camion_duplicata, p.nb_semi_duplicata, p.nb_moto_duplicata, p.total_engins_duplicata, p.total_plaques_duplicata],
    ['Post-Immat', '#9a3412', '#fff3e0', p.nb_vp_postimmat,  p.nb_camion_postimmat,  p.nb_semi_postimmat,  p.nb_moto_postimmat,  p.total_engins_postimmat,  p.total_plaques_postimmat],
  ].filter(([,,, vp,cam,semi,moto]) => (parseInt(vp)||0)+(parseInt(cam)||0)+(parseInt(semi)||0)+(parseInt(moto)||0) > 0);
  const reimmatSection = reimmatCat.length ? reimmatCat.map(([lbl,color,bg,vp,cam,semi,moto,engins,plaques])=>`
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:${color};text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">${lbl}</div>
      <div class="g4" style="gap:8px">
        ${[['<i class="ph ph-car" aria-hidden="true"></i>','VP',vp],['<i class="ph ph-truck" aria-hidden="true"></i>','Camion',cam],['<i class="ph-semi" aria-hidden="true"></i>','Semi',semi],['<i class="ph ph-motorcycle" aria-hidden="true"></i>','Moto',moto]].map(([ic,lb,nb])=>`
        <div style="background:${bg};border-radius:10px;padding:12px;text-align:center;border:1px solid ${color}33">
          <div style="font-size:20px">${ic}</div>
          <div style="font-family:'Montserrat',sans-serif;font-size:20px;font-weight:900;color:${color}">${nb||0}</div>
          <div style="font-size:12px;color:${color};font-weight:600;text-transform:uppercase">${lb}</div>
        </div>`).join('')}
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:10px;padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:13px">
        <span style="color:var(--muted)">Total engins</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:${color}">${engins||0}</span>
        <span style="color:var(--muted)">Total plaques</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:${color}">${plaques||0}</span>
      </div>
    </div>`).join('') : '';

  // Site en Re-Immatriculation : engins posés = Re-Immatriculation +
  // Duplicatas + Post-Immat (total_engins ne compte que la première).
  const engDup = parseInt(p.total_engins_duplicata)||0, engPi = parseInt(p.total_engins_postimmat)||0;
  const plqDup = parseInt(p.total_plaques_duplicata)||0, plqPi = parseInt(p.total_plaques_postimmat)||0;
  const estReimmat = p.site_operation === 'reimmat_post' || engDup + engPi > 0;
  const engTotal = (parseInt(p.total_engins)||0) + engDup + engPi;
  const plqTotal = (parseInt(p.total_plaques)||0) + plqDup + plqPi;
  const totalPosesSection = estReimmat ? `
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px"><i class="ph ph-calculator" aria-hidden="true"></i> Total engins posés</div>
      <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;font-size:13px">
        ${[[p.site_operation==='reimmat_post' ? 'Re-Immatriculation' : 'Primo', parseInt(p.total_engins)||0, parseInt(p.total_plaques)||0, 'var(--navy)'],
           ['Duplicatas', engDup, plqDup, '#6b21a8'], ['Post-Immat', engPi, plqPi, '#9a3412']].map(([lb, e, pl, c]) => `
        <div style="display:flex;justify-content:space-between;padding:8px 14px;border-bottom:1px solid #f1f5f9">
          <span style="color:${c};font-weight:600">${lb}</span><span>${e} engin${e>1?'s':''} · ${pl} plaque${pl>1?'s':''}</span>
        </div>`).join('')}
        <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#eff6ff;font-weight:800;color:#1e40af">
          <span>TOTAL</span><span>${engTotal} engin${engTotal>1?'s':''} · ${plqTotal} plaque${plqTotal>1?'s':''}</span>
        </div>
      </div>
    </div>` : '';

  // Rivets
  const gonfl  = parseInt(p.rivets_gonflables)||0;
  const eclate = parseInt(p.rivets_eclates)||0;
  const rivEnd  = parseInt(p.rivets_endommages)||0;
  const gEnd = parseInt(p.rivets_gonflables_end)||0, eEnd = parseInt(p.rivets_eclates_end)||0;
  // Anciens points : seul le total endommagé est connu.
  const rivEndDetail = rivEnd === 0 ? '' : (gEnd + eEnd === rivEnd
    ? [gEnd ? `${gEnd} gonflable${gEnd>1?'s':''}` : '', eEnd ? `${eEnd} éclaté${eEnd>1?'s':''}` : ''].filter(Boolean).join(' · ')
    : 'type non précisé');
  const rivSection = (gonfl+eclate+rivEnd)>0 ? `
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">🔩 Rivets</div>
      <div style="display:grid;grid-template-columns:1fr 1fr${rivEnd>0?' 1fr':''};gap:10px">
        ${gonfl>0?`<div style="background:#e3f2fd;border-radius:10px;padding:12px;text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#1565c0">${gonfl}</div><div style="font-size:12px;color:#1565c0;font-weight:700;text-transform:uppercase">🔵 Gonflables</div></div>`:''}
        ${eclate>0?`<div style="background:#fce4ec;border-radius:10px;padding:12px;text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#880e4f">${eclate}</div><div style="font-size:12px;color:#880e4f;font-weight:700;text-transform:uppercase">🔴 Éclatés</div></div>`:''}
        ${rivEnd>0?`<div style="background:#fff3e0;border-radius:10px;padding:12px;text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#e65100">${rivEnd}</div><div style="font-size:12px;color:#e65100;font-weight:700;text-transform:uppercase">⚠️ Endommagés</div><div style="font-size:12px;color:#e65100;margin-top:3px">${rivEndDetail}</div></div>`:''}
      </div>
    </div>` : '';

  // Non posés
  const npConc = parseInt(p.non_poses_concessionnaires)||0;
  const npUsag = parseInt(p.non_poses_usagers)||0;
  const npSection = (npConc+npUsag)>0 ? `
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px;margin-bottom:20px;display:flex;gap:20px">
      <div style="font-size:12px;font-weight:700;color:#c2410c;text-transform:uppercase;align-self:center">🚫 Non posés</div>
      <div style="flex:1;display:flex;gap:16px">
        ${npConc>0?`<div><span style="font-family:'Montserrat',sans-serif;font-size:18px;font-weight:800;color:#c2410c">${npConc}</span> <span style="font-size:12px;color:#9a3412">Concess.</span></div>`:''}
        ${npUsag>0?`<div><span style="font-family:'Montserrat',sans-serif;font-size:18px;font-weight:800;color:#c2410c">${npUsag}</span> <span style="font-size:12px;color:#9a3412">Usagers</span></div>`:''}
      </div>
    </div>` : '';

  const obsSection = renderObservationsSection(p.observations);

  const SP_MOTIFS = <?= json_encode(PJ_MOTIFS_SANS_PROD, JSON_UNESCAPED_UNICODE) ?>;
  const spSection = parseInt(p.sans_production||0) === 1 ? `
    <div style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;padding:14px;margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">🌙 Journée sans production</div>
      <div style="font-size:13px;color:#1e293b"><strong>${escEndo(SP_MOTIFS[p.motif_sans_production] || 'Motif non précisé')}</strong>${p.commentaire_sans_production ? ' — ' + escEndo(p.commentaire_sans_production) : ''}</div>
    </div>` : '';
  const motifSection = p.motif_rejet ? `
    <div style="background:#fee2e2;border-radius:10px;padding:14px;margin-bottom:20px;border-left:3px solid #ef4444">
      <div style="font-size:12px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">✏️ Motif de la demande de modification</div>
      <div style="font-size:13px;color:#7f1d1d">${p.motif_rejet}</div>
    </div>` : '';

  // Justification superviseur (plan comparaison OptoPlate, 15/10/2026) —
  // saisie à la validation quand un écart avait été constaté.
  const justifSection = p.justification_superviseur ? `
    <div style="background:#fef3c7;border-radius:10px;padding:14px;margin-bottom:20px;border-left:3px solid #f59e0b">
      <div style="font-size:12px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px"><i class="ph ph-scales" aria-hidden="true"></i> Justification du superviseur (écart OptoPlate)</div>
      <div style="font-size:13px;color:#78350f">${p.justification_superviseur}</div>
    </div>` : '';

  // Section PMMA
  const pmmaSection = pmmaList.length>0 ? `
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">🟦 PMMA consommé</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        ${pmmaList.map(pm=>{
          const nc=parseInt(pm.non_conformes)||0;
          const tot=(parseInt(pm.utilises)||0)+(parseInt(pm.endommages)||0)+nc;
          return `<div style="display:grid;grid-template-columns:1fr 80px 80px 80px 70px;gap:8px;align-items:center;background:#f8fafc;border-radius:8px;padding:10px 12px">
            <div style="font-weight:600;font-size:13px;color:var(--navy)">${pm.type_pmma}</div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#1565c0">${pm.utilises}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Utilisés</div></div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#e65100">${pm.endommages}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Endommagés</div></div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#7c3aed">${nc}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Non conformes</div></div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800">${tot}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Total</div></div>
          </div>`;
        }).join('')}
      </div>
    </div>` : '';

  // Section Films
  const filmsSection = filmsList.length>0 ? `
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">🎞️ Films utilisés par bobine</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        ${filmsList.map(f=>`
          <div style="display:grid;grid-template-columns:1fr 90px 90px 90px;gap:8px;align-items:center;background:#f8fafc;border-radius:8px;padding:10px 12px">
            <div>
              <div style="font-family:'Montserrat',sans-serif;font-size:13px;font-weight:800;color:#1565c0">Bobine #${f.bobine_num}</div>
              <div style="font-size:12px;color:var(--muted)">${f.type_code||''}</div>
            </div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#1565c0">${f.films_utilises}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Utilisés</div></div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#e65100">${f.films_endommages||0}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Endommagés</div></div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#7c3aed">${f.non_conformes||0}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Non conformes</div></div>
          </div>`).join('')}
      </div>
    </div>` : '';

  document.getElementById('detail-body').innerHTML=`
    <!-- En-tête -->
    <div style="background:linear-gradient(135deg,#0a1628,#163566);border-radius:14px;padding:20px 24px;margin-bottom:20px;color:white">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
        <div style="font-family:'Montserrat',sans-serif;font-size:18px;font-weight:900">${p.site_nom}</div>
        <span style="padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:${sc.bg};color:${sc.col}">${sc.icon} ${sc.lbl}</span>
      </div>
      <div style="font-size:12px;opacity:.6;margin-bottom:16px">${typeLbl[p.type_point]||p.type_point} · ${dateStr}</div>
      <div class="g4" style="gap:10px">
        <div style="text-align:center">
          <div style="font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900">${estReimmat ? engTotal : p.total_engins}</div>
          <div style="font-size:12px;opacity:.5;text-transform:uppercase">${estReimmat ? 'Engins posés' : 'Engins'}</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900">${estReimmat ? plqTotal : p.total_plaques}</div>
          <div style="font-size:12px;opacity:.5;text-transform:uppercase">Plaques</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900">${parseInt(p.rivets_utilises)||0}</div>
          <div style="font-size:12px;opacity:.5;text-transform:uppercase">Rivets posés</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900">${p.moyenne_prod}</div>
          <div style="font-size:12px;opacity:.5;text-transform:uppercase">V/H</div>
        </div>
      </div>
    </div>

    <!-- Véhicules -->
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px"><i class="ph ph-car" aria-hidden="true"></i> ${p.site_operation==='reimmat_post' ? 'Re-Immatriculation' : 'Répartition véhicules'}</div>
      <div class="g4" style="gap:8px">${vehCards}</div>
      <div style="display:flex;justify-content:space-between;margin-top:10px;padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:13px">
        <span style="color:var(--muted)">Total engins</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:var(--navy)">${p.total_engins}</span>
        <span style="color:var(--muted)">Total plaques</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:#1565c0">${p.total_plaques}</span>
        <span style="color:var(--muted)">Heures</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:var(--navy)">${p.nb_heures_travail||'—'}h</span>
      </div>
    </div>

    ${reimmatSection}
    ${totalPosesSection}
    ${rivSection}
    ${pmmaSection}
    ${filmsSection}
    ${npSection}
    ${obsSection}
    ${spSection}
    ${motifSection}
    ${justifSection}

    <!-- Footer -->
    <div style="border-top:1px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--muted)">
      <span>Enregistré par <strong style="color:var(--navy)">${p.agent||'—'}</strong></span>
      <span>${p.date_point}</span>
    </div>`;
}

async function editPoint(id){
  const d = await ap({action:'load_point', point_id:id});
  if(!d.success){ alert('Erreur de chargement'); return; }
  const p = d.data.point;
  currentPointId = id;
  document.getElementById('mPointTitre').textContent = p.statut === 'rejete'
    ? 'Corriger le point (modification demandée)' : 'Modifier le point journalier';
  // Champs généraux
  if(document.getElementById('p-site').tagName==='SELECT')
    document.getElementById('p-site').value = p.site_id;
  toggleReimmatSections();
  document.getElementById('p-date').value  = p.date_point;
  document.getElementById('p-type').value  = p.type_point;
  document.getElementById('p-heures').value= p.nb_heures_travail||'8';
  document.getElementById('p-np-conc').value = p.non_poses_concessionnaires||'0';
  document.getElementById('p-np-usag').value = p.non_poses_usagers||'0';
  if (document.getElementById('p-sans-prod')) {
    document.getElementById('p-sans-prod').checked = parseInt(p.sans_production||0) === 1;
    document.getElementById('p-sp-motif').value = p.motif_sans_production || '';
    document.getElementById('p-sp-comment').value = p.commentaire_sans_production || '';
    onSansProdChange();
  }
  chargerObservations(p.observations);
  // Véhicules
  const nbVP   = document.getElementById('nb-VP');   if(nbVP)   nbVP.value   = p.nb_vp||'0';
  const nbCAM  = document.getElementById('nb-CAM');  if(nbCAM)  nbCAM.value  = p.nb_camion||'0';
  const nbSEMI = document.getElementById('nb-SEMI'); if(nbSEMI) nbSEMI.value = p.nb_semi||'0';
  const nbMOTO = document.getElementById('nb-MOTO'); if(nbMOTO) nbMOTO.value = p.nb_moto||'0';
  // Duplicatas / Post-Immat
  [['VP','nb_vp'],['CAM','nb_camion'],['SEMI','nb_semi'],['MOTO','nb_moto']].forEach(([code,field])=>{
    const elDup = document.getElementById('nb-'+code+'-DUP'); if(elDup) elDup.value = p[field+'_duplicata'] || '0';
    const plqDup = document.getElementById('plq-'+code+'-DUP');
    if (plqDup) {
      const nbD = parseInt(p[field+'_duplicata']||0) || 0;
      const enreg = p['plaques_'+field.replace(/^nb_/,'')+'_duplicata'];
      plqDup.value = (enreg === null || enreg === undefined) ? 2*nbD : enreg;
      plqDup.dataset.manuel = parseInt(plqDup.value) !== 2*nbD ? '1' : '';
      plaquesDupSync(code);
    }
    const elPi  = document.getElementById('nb-'+code+'-PI');  if(elPi)  elPi.value  = p[field+'_postimmat'] || '0';
  });
  recalculeExtra();
  // Rivets : rivets_gonflables/eclates sont des totaux (posés + endommagés).
  // Avec la répartition par type, on restaure posés et endommagés ; sinon
  // (anciens points) posés = total et endommagés = 0, faute de mieux.
  const gEndR = parseInt(p.rivets_gonflables_end)||0, eEndR = parseInt(p.rivets_eclates_end)||0;
  document.getElementById('p-gonfl-util').value  = Math.max(0,(parseInt(p.rivets_gonflables)||0) - gEndR);
  document.getElementById('p-gonfl-end').value   = gEndR;
  document.getElementById('p-eclate-util').value = Math.max(0,(parseInt(p.rivets_eclates)||0) - eEndR);
  document.getElementById('p-eclate-end').value  = eEndR;
  await loadStockRivets();
  await loadBobines();
  // Restaurer les bobines sauvegardées
  if(p.films && p.films.length > 0){
    renderBobinesRows(p.films.map(f=>parseInt(f.bobine_id)));
    p.films.forEach((f,i)=>{
      const sel    = document.getElementById('bsel-'+i);
      const util   = document.getElementById('butil-'+i);
      const endomm = document.getElementById('bendomm-'+i);
      const nc     = document.getElementById('bnc-'+i);
      if(sel)    sel.value    = f.bobine_id;
      if(util)   util.value   = f.films_utilises;
      if(endomm) endomm.value = f.films_endommages;
      if(nc)     nc.value     = f.non_conformes||0;
      updateBobineRestants(i);
    });
  }
  await loadStockPMMA();
  (p.pmma || []).forEach(pm => {
    const nc  = parseInt(pm.non_conformes)||0;
    const div = ajouterLignePmmaType(pm.type_pmma, (parseInt(pm.utilises)||0)+(parseInt(pm.endommages)||0)+nc);
    if(!div) return;
    div.querySelector('[data-kind="util"]').value = pm.utilises||0;
    div.querySelector('[data-kind="end"]').value  = pm.endommages||0;
    const ncEl = div.querySelector('[data-kind="nc"]'); if(ncEl) ncEl.value = nc;
  });
  chargerEndommagementsSaisis(p.endommagements);
  recalcule();
  document.getElementById('mPoint').classList.add('open');
}

function supprimerBrouillon(id){
  if(!confirm("Supprimer ce brouillon ?\n\nCette action est définitive."))return;
  ap({action:'supprimer_brouillon',point_id:id}).then(d=>{
    toast(d.message,d.success?'success':'danger');
    if(d.success)setTimeout(()=>location.reload(),800);
  });
}
function validerPointCoord(id){
  if(!confirm('Valider ce point ? Vous ne pourrez plus le modifier après.'))return;
  ap({action:'valider_point_coord',point_id:id}).then(d=>{
    toast(d.message,d.success?'success':'danger');
    if(d.success)setTimeout(()=>location.reload(),800);
  });
}
function soumettrePoint(id){
  if(!confirm('Soumettre ce point 18h au superviseur pour validation ?'))return;
  ap({action:'soumettre_point',point_id:id}).then(d=>{
    toast(d.message,d.success?'success':'danger');
    if(d.success)setTimeout(()=>location.reload(),800);
  });
}
function validerPoint(id, date){
  if(!OPTO_COMPARAISON_OK){
    if(!confirm('Valider ce point journalier ? Cette action est définitive.'))return;
    ap({action:'valider_point',point_id:id}).then(d=>{toast(d.message,d.success?'success':'danger');if(d.success)setTimeout(()=>location.reload(),800);});
    return;
  }
  ouvrirComparaisonOptoplate(id, date);
}

// ── Comparaison OptoPlate (plan 15/10/2026) : coordinateur / OptoPlate / écart,
// avant que le superviseur ne valide un point 18h.
const ETAPE_LBL_COMP = {verification:'À la vérification',impression:"À l'impression",lamination:'À la lamination',percee:'À la percée',fissuree:'Plaque fissurée'};
const VEH_ICON_COMP = {VP:'ph-car', CAM:'ph-truck', SEMI:'ph-semi', MOTO:'ph-motorcycle'};
const VEH_BG_COMP   = {VP:'#e3f2fd', CAM:'#e8f5e9', SEMI:'#fff3e0', MOTO:'#fce4ec'};
const VEH_FG_COMP   = {VP:'#1565c0', CAM:'#2e7d32', SEMI:'#e65100', MOTO:'#ad1457'};

function ecartBadgeComp(ecart){
  if(ecart===0) return `<span style="display:inline-flex;align-items:center;gap:3px;background:#dcfce7;color:#166534;font-weight:800;font-size:12px;padding:2px 10px;border-radius:20px;white-space:nowrap"><i class="ph ph-check" aria-hidden="true"></i> 0</span>`;
  const signe = ecart>0 ? '+' : '';
  return `<span style="display:inline-flex;align-items:center;gap:3px;background:#fee2e2;color:#991b1b;font-weight:800;font-size:12px;padding:2px 10px;border-radius:20px;white-space:nowrap"><i class="ph ph-warning" aria-hidden="true"></i> ${signe}${ecart}</span>`;
}
function compSectionTitreComp(txt){
  return `<div style="font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin:18px 0 8px">${txt}</div>`;
}
function compCategCard(icon, bg, fg, libelle, coord, opt, ecart){
  return `
  <div style="background:white;border:1.5px solid ${ecart!==0?'#fca5a5':'var(--border)'};border-radius:12px;padding:12px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <div style="width:30px;height:30px;border-radius:9px;background:${bg};display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="ph ${icon}" style="color:${fg};font-size:16px" aria-hidden="true"></i>
      </div>
      <div style="font-size:12.5px;font-weight:700;color:var(--navy);line-height:1.2">${libelle}</div>
    </div>
    <div style="display:flex;align-items:center;gap:4px">
      <div style="text-align:center;flex:1">
        <div style="font-size:17px;font-weight:800;color:var(--navy)">${coord}</div>
        <div style="font-size:9.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.3px">Coord.</div>
      </div>
      <div style="text-align:center;flex:1;border-left:1px solid var(--border)">
        <div style="font-size:17px;font-weight:800;color:var(--navy)">${opt}</div>
        <div style="font-size:9.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.3px">OptoPlate</div>
      </div>
    </div>
    <div style="margin-top:8px;text-align:center">${ecartBadgeComp(ecart)}</div>
  </div>`;
}
function compListRow(libelle, coord, opt, ecart, icon){
  return `
  <div style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid var(--border)">
    <i class="ph ${icon}" style="color:var(--muted);font-size:15px;flex-shrink:0" aria-hidden="true"></i>
    <div style="flex:1;font-size:13px;color:var(--navy);font-weight:600">${libelle}</div>
    <div style="width:70px;text-align:center" title="Coordinateur"><span style="font-weight:700;font-size:13px">${coord}</span></div>
    <div style="width:70px;text-align:center" title="OptoPlate"><span style="font-weight:700;font-size:13px;color:var(--muted)">${opt}</span></div>
    <div style="width:64px;text-align:right">${ecartBadgeComp(ecart)}</div>
  </div>`;
}
function compBandeau(hasEcart, nbEcarts){
  return hasEcart
    ? `<div style="display:flex;align-items:center;gap:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px">
         <div style="width:36px;height:36px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ph ph-warning" style="color:#991b1b;font-size:18px" aria-hidden="true"></i></div>
         <div><div style="font-weight:800;color:#991b1b;font-size:13.5px">${nbEcarts} écart(s) constaté(s) avec OptoPlate</div><div style="font-size:12px;color:#991b1b">Demandez une modification, ou justifiez pour valider quand même.</div></div>
       </div>`
    : `<div style="display:flex;align-items:center;gap:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px">
         <div style="width:36px;height:36px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ph ph-check-circle" style="color:#166534;font-size:18px" aria-hidden="true"></i></div>
         <div style="font-weight:800;color:#166534;font-size:13.5px">Aucun écart avec OptoPlate</div>
       </div>`;
}

async function ouvrirComparaisonOptoplate(id, date){
  document.getElementById('comp-point-id').value = id;
  document.getElementById('comp-point-date').value = date;
  document.getElementById('comp-body').innerHTML = '<div style="text-align:center;padding:30px;color:var(--muted)">Chargement…</div>';
  document.getElementById('comp-footer').innerHTML = '';
  document.getElementById('mCompOpto').classList.add('open');

  const d = await ap({action:'comparer_optoplate', point_id:id});
  if(!d.success){
    document.getElementById('comp-body').innerHTML = `<div class="alert alert-danger">${d.message}</div>`;
    document.getElementById('comp-footer').innerHTML = `<button class="btn btn-secondary" onclick="document.getElementById('mCompOpto').classList.remove('open')">Fermer</button>`;
    return;
  }
  const c = d.data.comparaison;
  if(!d.data.import_present){
    document.getElementById('comp-body').innerHTML = `
      <div style="display:flex;align-items:center;gap:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px">
        <div style="width:36px;height:36px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ph ph-clock-countdown" style="color:#92400e;font-size:18px" aria-hidden="true"></i></div>
        <div><div style="font-weight:800;color:#92400e;font-size:13.5px">Comparaison impossible</div>
        <div style="font-size:12px;color:#92400e">Aucun import OptoPlate du ${date} pour <strong>${escEndo(d.data.site_nom||'')}</strong>. Vous pouvez valider avec une justification, ou attendre l'import.</div></div>
      </div>
      <div class="form-group" style="margin-top:16px">
        <label>Justification *</label>
        <textarea class="form-control" id="comp-justif" rows="3" placeholder="Import OptoPlate non disponible…"></textarea>
      </div>`;
  } else {
    const toutes = [...c.primo, c.duplicata, ...(c.duplicata_plaques ? [c.duplicata_plaques] : []), c.post_immat, ...c.endommages, ...(c.par_bobine||[])];
    const nbEcarts = toutes.filter(r => r.ecart !== 0).length;

    const primoCards = c.primo.map(r => compCategCard(VEH_ICON_COMP[r.code], VEH_BG_COMP[r.code], VEH_FG_COMP[r.code], r.libelle, r.coordinateur, r.optoplate, r.ecart)).join('');
    const reimprCards = `<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">
        ${compCategCard('ph-copy', '#f3e8ff', '#6b21a8', 'Duplicatas', c.duplicata.coordinateur, c.duplicata.optoplate, c.duplicata.ecart)}
        ${c.duplicata_plaques ? compCategCard('ph-identification-card', '#f3e8ff', '#6b21a8', 'Plaques duplicatas', c.duplicata_plaques.coordinateur, c.duplicata_plaques.optoplate, c.duplicata_plaques.ecart) : ''}
        ${compCategCard('ph-stamp', '#fff3e0', '#9a3412', 'Post-Immat', c.post_immat.coordinateur, c.post_immat.optoplate, c.post_immat.ecart)}
      </div>`;
    const endoList = c.endommages.length
      ? c.endommages.map(r => compListRow(ETAPE_LBL_COMP[r.etape]||r.etape, r.coordinateur, r.optoplate, r.ecart, 'ph-warning-circle')).join('')
      : `<div style="padding:16px;text-align:center;color:var(--muted);font-size:12.5px">Aucun endommagement déclaré.</div>`;
    const bobineList = (c.par_bobine||[]).length
      ? c.par_bobine.map(r => compListRow('Bobine ' + r.numero, r.coordinateur, r.optoplate, r.ecart, 'ph-film-strip')).join('')
      : `<div style="padding:16px;text-align:center;color:var(--muted);font-size:12.5px">Aucune donnée par bobine.</div>`;

    document.getElementById('comp-body').innerHTML = `
      ${compBandeau(c.has_ecart, nbEcarts)}
      ${compSectionTitreComp('Véhicules Primo')}
      <div class="g4" style="gap:10px">${primoCards}</div>
      ${compSectionTitreComp('Réimpressions')}
      ${reimprCards}
      ${compSectionTitreComp('Endommagés par étape')}
      <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden">${endoList}</div>
      ${compSectionTitreComp('Films par bobine')}
      <div style="border:1px solid var(--border);border-radius:10px;max-height:170px;overflow-y:auto">${bobineList}</div>
      ${c.has_ecart ? `
      <div class="form-group" style="margin-top:16px">
        <label>Justification (obligatoire pour valider malgré l'écart) *</label>
        <textarea class="form-control" id="comp-justif" rows="3" placeholder="Expliquer l'écart constaté…"></textarea>
      </div>` : ''}`;
  }

  document.getElementById('comp-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="document.getElementById('mCompOpto').classList.remove('open')">Annuler</button>
    <button class="btn" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" onclick="demanderModifDepuisComparaison()"><i class="ph ph-pencil-simple-line" aria-hidden="true"></i> Demander une modification</button>
    <button class="btn btn-success" onclick="validerDepuisComparaison()"><i class="ph ph-check-circle" aria-hidden="true"></i> Valider</button>`;
}

function demanderModifDepuisComparaison(){
  const id   = document.getElementById('comp-point-id').value;
  const date = document.getElementById('comp-point-date').value;
  document.getElementById('mCompOpto').classList.remove('open');
  ouvrirDemandeModification(id, date);
  const justif = document.getElementById('comp-justif');
  if(justif && justif.value.trim()) document.getElementById('dmod-motif').value = 'Écart OptoPlate : ' + justif.value.trim();
}

function validerDepuisComparaison(){
  const id    = document.getElementById('comp-point-id').value;
  const justifEl = document.getElementById('comp-justif');
  const justif = justifEl ? justifEl.value.trim() : '';
  if(justifEl && !justif){ toast('La justification est obligatoire.', 'danger'); return; }
  ap({action:'valider_point', point_id:id, justification_superviseur:justif}).then(d=>{
    toast(d.message, d.success?'success':'danger');
    if(d.success){ document.getElementById('mCompOpto').classList.remove('open'); setTimeout(()=>location.reload(),800); }
  });
}

function printPoint(id){if(!id)return;window.open('<?= rtrim(APP_URL,'/') ?>/pages/operations/point_pdf.php?id='+id,'_blank');}

function ouvrirDemandeModification(id, date){
  document.getElementById('dmod-point-id').value = id;
  document.getElementById('dmod-info').textContent = `Point journalier du ${date} — les films et rivets déduits seront restaurés.`;
  document.getElementById('dmod-motif').value = '';
  document.getElementById('mDemandeModif').classList.add('open');
}
function confirmerDemandeModification(){
  const id     = document.getElementById('dmod-point-id').value;
  const motif  = document.getElementById('dmod-motif').value.trim();
  if(!motif){alert('Veuillez saisir le motif de la demande de modification.');return;}
  ap({action:'demander_modification_point', point_id:id, motif_rejet:motif}).then(d=>{
    toast(d.message, d.success?'success':'danger');
    // En cas d'échec la fenêtre reste ouverte : le motif saisi n'est pas perdu.
    if(d.success){
      document.getElementById('mDemandeModif').classList.remove('open');
      setTimeout(()=>location.reload(), 900);
    }
  });
}
document.getElementById('mDemandeModif').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');});

function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(achParseJson).catch(err=>{
    // Sans ceci, une réponse illisible (session expirée, erreur serveur, coupure
    // réseau) ne produisait AUCUN effet visible : le bouton semblait mort alors
    // que l'action avait parfois bien été exécutée. Même forme qu'un refus du
    // serveur : les appelants affichent déjà d.message.
    console.error(err);
    const brut = String((err && err.message) || err);
    const page = /<\s*(!doctype|html|body|br|b)\b/i.test(brut);
    return {success:false, message: page
      ? 'Réponse inattendue du serveur (session expirée ou erreur). Rechargez la page, reconnectez-vous si besoin, puis vérifiez le statut du point avant de réessayer.'
      : (/failed to fetch|networkerror|load failed/i.test(brut) ? 'Connexion au serveur impossible. Vérifiez le réseau et réessayez.' : brut)};
  });
}
document.getElementById('mPoint').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');});
document.getElementById('mDetail').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');});

// ── CORRECTIONS BOBINES — réponse coordinateur
function ouvrirReponseCorrection(corrId, filmsOriginal, filmsProposes, bobineNum){
  document.getElementById('rc-correction-id').value = corrId;
  document.getElementById('rc-bobine-num').textContent = bobineNum;
  document.getElementById('rc-films-original').textContent = filmsOriginal;
  document.getElementById('rc-films-proposes').textContent = filmsProposes;
  document.getElementById('rc-films-final').value = filmsProposes;
  document.querySelectorAll('input[name="rc-reponse"]').forEach(r=>r.checked=false);
  document.getElementById('rc-section-films').style.display='none';
  document.getElementById('rc-section-note').style.display='none';
  document.getElementById('mReponseCorr').classList.add('open');
}
function fermerReponseCorrection(){
  document.getElementById('mReponseCorr').classList.remove('open');
}
function onRcReponseChange(){
  const val = document.querySelector('input[name="rc-reponse"]:checked')?.value;
  const secFilms = document.getElementById('rc-section-films');
  const secNote  = document.getElementById('rc-section-note');
  if(val === 'approuvee'){
    secFilms.style.display = 'none';
    secNote.style.display  = 'none';
    const fp = document.getElementById('rc-films-proposes').textContent;
    document.getElementById('rc-films-final').value = fp;
  } else if(val === 'contreproposee'){
    secFilms.style.display = 'block';
    secNote.style.display  = 'block';
  } else if(val === 'refusee'){
    secFilms.style.display = 'none';
    secNote.style.display  = 'block';
    document.getElementById('rc-films-final').value = '';
  }
}
async function submitReponseCorrection(){
  const corrId   = document.getElementById('rc-correction-id').value;
  const reponse  = document.querySelector('input[name="rc-reponse"]:checked')?.value;
  const note     = document.getElementById('rc-note').value.trim();
  const filmsFin = document.getElementById('rc-films-final').value;
  if(!reponse){ toast('Veuillez choisir une réponse.','warning'); return; }
  if(reponse === 'refusee' && !note){ toast('Veuillez saisir un motif de refus.','warning'); return; }
  if(reponse === 'contreproposee' && (filmsFin===''||isNaN(parseInt(filmsFin)))){ toast('Veuillez saisir le nombre de films final.','warning'); return; }
  const payload = { action:'repondre_correction', correction_id:corrId, reponse, reponse_coord:note };
  if(reponse !== 'refusee') payload.films_final = reponse==='approuvee'
    ? document.getElementById('rc-films-proposes').textContent
    : filmsFin;
  const btn = document.getElementById('rc-submit-btn');
  btn.disabled = true; btn.textContent = 'Envoi…';
  try {
    const d = await ap(payload);
    toast(d.message, d.success?'success':'danger');
    if(d.success){
      fermerReponseCorrection();
      setTimeout(()=>location.reload(), 1200);
    }
  } finally {
    btn.disabled = false; btn.textContent = 'Envoyer ma réponse';
  }
}
document.getElementById('mReponseCorr').addEventListener('click',e=>{if(e.target===e.currentTarget)fermerReponseCorrection();});

function ouvrirReponsePec(id, declare, propose){
  document.getElementById('pec-id').value = id;
  document.getElementById('pec-declare').textContent = declare;
  document.getElementById('pec-propose').textContent = propose;
  document.getElementById('pec-valeur-coord').value = declare;
  document.querySelectorAll('input[name="pec-reponse"]').forEach(r=>r.checked=false);
  document.getElementById('pec-section-valeur').style.display = 'none';
  document.getElementById('pec-section-note').style.display = 'none';
  document.getElementById('pec-note').value = '';
  document.getElementById('mReponsePec').classList.add('open');
}
function fermerReponsePec(){
  document.getElementById('mReponsePec').classList.remove('open');
}
function onPecReponseChange(){
  const val = document.querySelector('input[name="pec-reponse"]:checked')?.value;
  const secValeur = document.getElementById('pec-section-valeur');
  const secNote   = document.getElementById('pec-section-note');
  secValeur.style.display = val === 'contester' ? 'block' : 'none';
  secNote.style.display   = val === 'contester' ? 'block' : 'none';
}
async function submitReponsePec(){
  const id      = document.getElementById('pec-id').value;
  const reponse = document.querySelector('input[name="pec-reponse"]:checked')?.value;
  const valeur  = document.getElementById('pec-valeur-coord').value;
  const note    = document.getElementById('pec-note').value.trim();
  if(!reponse){ toast('Veuillez choisir une réponse.','warning'); return; }
  if(reponse === 'contester' && (valeur===''||isNaN(parseInt(valeur)))){ toast('Veuillez saisir votre valeur.','warning'); return; }
  if(reponse === 'contester' && !note){ toast('Veuillez expliquer votre contestation.','warning'); return; }
  const payload = reponse === 'accepter'
    ? { action:'pec_accepter', corr_id:id }
    : { action:'pec_contester', corr_id:id, total_propose_coord:valeur, reponse:note };
  const btn = document.getElementById('pec-submit-btn');
  btn.disabled = true; btn.textContent = 'Envoi…';
  try {
    const d = await ap(payload);
    toast(d.message, d.success?'success':'danger');
    if(d.success){
      fermerReponsePec();
      setTimeout(()=>location.reload(), 1200);
    }
  } finally {
    btn.disabled = false; btn.textContent = 'Envoyer ma réponse';
  }
}
document.getElementById('mReponsePec').addEventListener('click',e=>{if(e.target===e.currentTarget)fermerReponsePec();});
</script>
<style>@media print{.sidebar,.topbar,.mhdr button,.mfoot{display:none!important}.modal{position:static;border:none;box-shadow:none}.point-preview{color:black!important;background:white!important}.point-preview *{color:black!important}}</style>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
