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

require_auth();
require_permission('operations', 'can_read');

$user        = current_user();
$page_title  = 'Point Journalier';
$active_page = 'operations';

// Types véhicules
$types_v = db_fetch_all("SELECT * FROM op_types_vehicule ORDER BY ordre");
$types_map = array_column($types_v, null, 'id');

// Sites accessibles
$sites_list = db_fetch_all("SELECT id,nom,type FROM sites WHERE actif=1 AND type NOT IN ('entrepot') ORDER BY nom");

// ── Vérifie si le coordinateur est bloqué par un écart GSB non résolu
function _site_bloque_ecart(int $site_id, string $date_today): ?string {
    $dernier_point = db_fetch_one(
        "SELECT date_point FROM op_points_journaliers
         WHERE site_id=? AND date_point < ? AND statut != 'brouillon'
         ORDER BY date_point DESC LIMIT 1",
        [$site_id, $date_today]
    );
    if (!$dernier_point) return null;
    $date_prec = $dernier_point['date_point'];
    $validation = db_fetch_one(
        "SELECT statut FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
        [$site_id, $date_prec]
    );
    if (!$validation)
        return "⚠️ Votre stock du ".date('d/m/Y', strtotime($date_prec))." n'a pas encore été validé par le GSB. Vous ne pouvez pas saisir un nouveau point journalier tant que les écarts ne sont pas résolus.";
    if ($validation['statut'] === 'refuse')
        return "🚫 Le GSB a bloqué votre activité suite à un écart non résolu sur le stock du ".date('d/m/Y', strtotime($date_prec)).". Contactez votre gestionnaire stock.";
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

        $types_valides = ['point_9h', 'point_13h', 'point_18h', 'final', 'intermediaire'];
        if (!$site_id)                              json_response(false, 'Veuillez sélectionner un site.');
        if (!$date_point)                           json_response(false, 'Veuillez saisir une date.');
        if (!in_array($type_point, $types_valides)) json_response(false, 'Veuillez sélectionner un type de point (9h, 13h ou 18h).');

        // ── Blocage coordinateur si écart non résolu par GSB
        if (($user['role_slug'] ?? '') === 'coordinateur_site') {
            $bloque = _site_bloque_ecart($site_id, $date_point);
            if ($bloque) json_response(false, $bloque);
        }

        // Calculs automatiques
        $total_engins  = $nb_vp + $nb_camion + $nb_semi + $nb_moto;
        $total_plaques = ($nb_vp * 2) + ($nb_camion * 2) + ($nb_semi * 1) + ($nb_moto * 1);
        $moy_prod      = $heures > 0 ? round($total_engins / $heures, 1) : 0;

        // Pré-vérifier si c'est une mise à jour (pour ajuster le check stock en conséquence)
        $existing_pre = db_fetch_one(
            "SELECT id, rivets_gonflables, rivets_eclates FROM op_points_journaliers WHERE site_id=? AND date_point=? AND type_point=?",
            [$site_id, $date_point, $type_point]
        );
        $old_riv_gonfl  = $existing_pre ? (int)($existing_pre['rivets_gonflables'] ?? 0) : 0;
        $old_riv_eclate = $existing_pre ? (int)($existing_pre['rivets_eclates']    ?? 0) : 0;

        // Vérifier stock rivets par type (stock actuel + anciens rivets qui seront restaurés)
        $stock_gonfl  = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='gonflable'", [$site_id]) ?? 0) + $old_riv_gonfl;
        $stock_eclate = (int)(db_fetch_value("SELECT COALESCE(quantite,0) FROM op_stock_rivets WHERE site_id=? AND type_rivet='eclate'", [$site_id]) ?? 0) + $old_riv_eclate;
        $stock_rivets = $stock_gonfl + $stock_eclate;
        if ($total_gonfl > $stock_gonfl)
            json_response(false, "Stock rivets gonflables insuffisant. Disponible : $stock_gonfl, Nécessaires : $total_gonfl");
        if ($total_eclate > $stock_eclate)
            json_response(false, "Stock rivets éclatés insuffisant. Disponible : $stock_eclate, Nécessaires : $total_eclate");

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
                    nb_heures_travail=?,observations=?,updated_at=NOW()
                    WHERE id=?",
                    [$nb_vp,$nb_camion,$nb_semi,$nb_moto,
                     $total_engins,$total_plaques,$moy_prod,
                     $rivets_util,$riv_endomm,$total_gonfl,$total_eclate,
                     $np_conc,$np_usag,$heures,$obs,$point_id]);
                // Restaurer le stock bobines avant de supprimer les anciens films
                $old_films = db_fetch_all(
                    "SELECT bobine_id, films_utilises, films_endommages FROM op_films_utilises WHERE point_id=?",
                    [$point_id]
                );
                foreach ($old_films as $of) {
                    $total_restore = (int)$of['films_utilises'] + (int)$of['films_endommages'];
                    if ($total_restore > 0) {
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
                // Restaurer le stock rivets avant de supprimer
                if ($old_riv_gonfl > 0)
                    db_query("UPDATE op_stock_rivets SET quantite = quantite + ? WHERE site_id=? AND type_rivet='gonflable'",
                        [$old_riv_gonfl, $site_id]);
                if ($old_riv_eclate > 0)
                    db_query("UPDATE op_stock_rivets SET quantite = quantite + ? WHERE site_id=? AND type_rivet='eclate'",
                        [$old_riv_eclate, $site_id]);
                // Restaurer le stock PMMA avant de supprimer
                $old_pmma = db_fetch_all("SELECT type_pmma, utilises, endommages FROM op_pmma_utilises WHERE point_id=?", [$point_id]);
                foreach ($old_pmma as $op_) {
                    $total_pm_restore = (int)$op_['utilises'] + (int)$op_['endommages'];
                    if ($total_pm_restore > 0)
                        db_query("UPDATE stock_pmma_site SET quantite = quantite + ? WHERE site_id=? AND type_pmma=?",
                            [$total_pm_restore, $site_id, $op_['type_pmma']]);
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
                     non_poses_concessionnaires,non_poses_usagers,nb_heures_travail,observations,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$site_id,$date_point,$type_point,$nb_vp,$nb_camion,$nb_semi,$nb_moto,
                     $total_engins,$total_plaques,$moy_prod,$rivets_util,$riv_endomm,
                     $total_gonfl,$total_eclate,
                     $np_conc,$np_usag,$heures,$obs,$user['id']]);
                $point_id = (int)db_last_id();
                if (!$point_id) throw new Exception("Impossible de créer le point journalier.");
            }

            // Déduire rivets du stock site par type
            if ($total_gonfl > 0)
                db_query("UPDATE op_stock_rivets SET quantite = GREATEST(0, quantite - ?) WHERE site_id=? AND type_rivet='gonflable'",
                    [$total_gonfl, $site_id]);
            if ($total_eclate > 0)
                db_query("UPDATE op_stock_rivets SET quantite = GREATEST(0, quantite - ?) WHERE site_id=? AND type_rivet='eclate'",
                    [$total_eclate, $site_id]);

            // Traiter PMMA par type
            $pmma_total_util   = 0;
            $pmma_total_endomm = 0;
            foreach ($pmma_data as $pd) {
                $type_pmma  = trim($pd['type_pmma'] ?? '');
                $pm_util    = (int)($pd['utilises']    ?? 0);
                $pm_endomm  = (int)($pd['endommages']  ?? 0);
                if (!$type_pmma || ($pm_util + $pm_endomm) === 0) continue;
                $total_pm = $pm_util + $pm_endomm;
                $stock_pm = (int)(db_fetch_value(
                    "SELECT COALESCE(quantite,0) FROM stock_pmma_site WHERE site_id=? AND type_pmma=?",
                    [$site_id, $type_pmma]
                ) ?? 0);
                if ($total_pm > $stock_pm)
                    throw new Exception("Stock PMMA $type_pmma insuffisant. Disponible : $stock_pm, Nécessaire : $total_pm");
                db_query("INSERT INTO op_pmma_utilises (point_id, type_pmma, utilises, endommages) VALUES (?,?,?,?)",
                    [$point_id, $type_pmma, $pm_util, $pm_endomm]);
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
                if (!$bobine_id || ($f_util + $f_endomm) === 0) continue;

                $bobine = db_fetch_one("SELECT * FROM op_bobines WHERE id=?", [$bobine_id]);
                if (!$bobine) continue;

                $total_sortis = $f_util + $f_endomm;
                if ($bobine['films_restants'] < $total_sortis)
                    throw new Exception("Bobine {$bobine['numero']} : stock insuffisant ({$bobine['films_restants']} restants, {$total_sortis} demandés).");

                db_query("INSERT INTO op_films_utilises (point_id,bobine_id,type_vehicule_id,films_utilises,films_endommages)
                          VALUES (?,?,?,?,?)", [$point_id, $bobine_id, $tv_id, $f_util, $f_endomm]);

                // Mettre à jour la bobine
                $new_restants = $bobine['films_restants'] - $total_sortis;
                $new_statut   = $new_restants <= 0 ? 'epuisee' : 'en_cours';
                db_query("UPDATE op_bobines SET
                          films_utilises=films_utilises+?,
                          films_endommages=films_endommages+?,
                          films_restants=?,
                          statut=?,
                          date_ouverture=COALESCE(date_ouverture,?)
                          WHERE id=?",
                    [$f_util, $f_endomm, $new_restants, $new_statut, $date_point, $bobine_id]);

                $films_detail[] = "Bobine {$bobine['numero']} : $f_util utilisés, $f_endomm endommagés";
            }

            // ── n° 2.1 CR PDG — déclarations d'endommagement
            // On n'accepte une déclaration que pour une bobine réellement
            // déclarée endommagée dans ce point : le pop-up est la seule
            // porte prévue, mais la requête peut être forgée.
            $bobines_endommagees = [];
            foreach ($films_data as $fd) {
                if ((int)($fd['films_endommages'] ?? 0) > 0) {
                    $bobines_endommagees[(int)$fd['bobine_id']] = (int)$fd['films_endommages'];
                }
            }
            $etapes_ok = ['pose','impression','transport','stockage','autre'];
            $causes_ok = ['manipulation','defaut_materiel','incident_externe','autre'];

            // Une déclaration par film endommagé : six films abîmés sur une
            // bobine peuvent l'avoir été à six moments et pour six causes.
            $rangs = [];   // bobine_id -> compteur de films déjà déclarés
            foreach ($endo_data as $ed) {
                $e_bobine = (int)($ed['bobine_id'] ?? 0);
                if (!isset($bobines_endommagees[$e_bobine])) continue;

                $rangs[$e_bobine] = ($rangs[$e_bobine] ?? 0) + 1;
                if ($rangs[$e_bobine] > $bobines_endommagees[$e_bobine]) {
                    throw new Exception("Déclarations d'endommagement plus nombreuses que les films endommagés déclarés sur une bobine.");
                }

                $e_personne = trim((string)($ed['personne'] ?? ''));
                $e_etape    = trim((string)($ed['etape'] ?? ''));
                $e_cause    = trim((string)($ed['cause'] ?? ''));
                if ($e_personne === '') throw new Exception("Déclaration d'endommagement : la personne concernée est obligatoire.");
                if (!in_array($e_etape, $etapes_ok, true)) throw new Exception("Déclaration d'endommagement : étape invalide.");
                if (!in_array($e_cause, $causes_ok, true)) throw new Exception("Déclaration d'endommagement : cause invalide.");

                $e_heure = trim((string)($ed['heure'] ?? ''));
                if ($e_heure !== '' && !preg_match('/^\d{2}:\d{2}$/', $e_heure)) $e_heure = '';

                db_query(
                    "INSERT INTO op_endommagements
                     (point_id,bobine_id,site_id,film_no,personne,etape,cause,heure,observations,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [$point_id, $e_bobine, $site_id, $rangs[$e_bobine],
                     $e_personne, $e_etape, $e_cause, $e_heure ?: null,
                     trim((string)($ed['observations'] ?? '')), $user['id']]
                );
            }

            // Chaque film endommagé doit avoir sa déclaration : sans ce
            // contrôle, un envoi partiel laisserait des films sans traçabilité,
            // ce que le module est précisément censé empêcher.
            foreach ($bobines_endommagees as $bid => $nb) {
                if (($rangs[$bid] ?? 0) !== $nb) {
                    throw new Exception("Chaque film endommagé doit être déclaré : "
                        . ($rangs[$bid] ?? 0) . " déclaration(s) pour $nb film(s) sur une bobine.");
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
                    db_query(
                        "INSERT INTO op_observations (point_id,ordre,site_id,type,texte,created_by)
                         VALUES (?,?,?,?,?,?)
                         ON CONFLICT (point_id,ordre) DO UPDATE
                            SET type=EXCLUDED.type, texte=EXCLUDED.texte
                          WHERE op_observations.statut='en_attente'",
                        [$point_id, $i, $site_id, $o_type, $o_texte, $user['id']]
                    );
                }
            }

            audit_log($user['id'], 'CREATE', 'operations', $point_id,
                "Point journalier $type_point — Site:$site_id — $date_point — $total_engins engins");
            db_commit();
            json_response(true, "Point enregistré. $total_engins engins, $total_plaques plaques, $rivets_util rivets déduits.", [
                'point_id'     => $point_id,
                'total_engins' => $total_engins,
                'total_plaques'=> $total_plaques,
                'rivets_util'  => $rivets_util,
                'moy_prod'     => $moy_prod,
                'films_detail' => $films_detail,
            ]);
        } catch (Exception $e) {
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
        $pmma  = db_fetch_all("SELECT type_pmma, utilises, endommages FROM op_pmma_utilises WHERE point_id=? ORDER BY type_pmma", [$point_id]);
        $films = db_fetch_all(
            "SELECT b.numero AS bobine_num, b.type_code, tv.libelle AS type_veh,
                    fu.films_utilises, fu.films_endommages
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
        $point['films'] = db_fetch_all(
            "SELECT bobine_id, films_utilises, films_endommages FROM op_films_utilises WHERE point_id=?",
            [$point_id]
        );
        json_response(true, '', ['point' => $point]);
    }

    // Coordinateur valide ses points 9h/13h
    if ($action === 'valider_point_coord') {
        $point_id = (int)($_POST['point_id'] ?? 0);
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        if (($point['created_by'] ?? 0) != $user['id']) json_response(false, 'Accès refusé.');
        if ($point['statut'] !== 'brouillon') json_response(false, 'Ce point ne peut plus être modifié.');
        $nb_films = (int)db_fetch_value("SELECT COUNT(*) FROM op_films_utilises WHERE point_id=?", [$point_id]);
        if ($nb_films === 0) json_response(false, 'Impossible de valider un point sans données bobines. Veuillez saisir les films utilisés avant de valider.');
        db_query("UPDATE op_points_journaliers SET statut='valide', validated_by=?, validated_at=NOW() WHERE id=?",
            [$user['id'], $point_id]);
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
        $nb_films = (int)db_fetch_value("SELECT COUNT(*) FROM op_films_utilises WHERE point_id=?", [$point_id]);
        if ($nb_films === 0) json_response(false, 'Impossible de soumettre un point sans données bobines. Veuillez saisir les films utilisés avant de soumettre.');
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

    if ($action === 'valider_point') {
        if (!in_array($user['role_slug'] ?? '', ['admin','superadmin','superviseur_operation']))
            json_response(false, 'Accès refusé.');
        $point_id = (int)($_POST['point_id'] ?? 0);
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        db_query("UPDATE op_points_journaliers SET statut='valide',validated_by=?,validated_at=NOW() WHERE id=?",
            [$user['id'], $point_id]);
        // Notifier le coordinateur
        if (!empty($point['created_by'])) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$point['created_by'], 'info', '✅ Point journalier validé',
                 "Votre point journalier du ".fmt_date($point['date_point'],'d/m/Y')." a été validé par le superviseur.",
                 '/pages/operations/point_journalier.php']);
        }
        audit_log($user['id'], 'UPDATE', 'operations', $point_id, "Validation point journalier #$point_id");
        json_response(true, 'Point validé.');
    }

    // ── REJETER POINT (superviseur) — reverse les déductions stock
    if ($action === 'rejeter_point') {
        if (!in_array($user['role_slug'] ?? '', ['admin','superadmin','superviseur_operation']))
            json_response(false, 'Accès refusé.');
        $point_id   = (int)($_POST['point_id']    ?? 0);
        $motif      = trim($_POST['motif_rejet']  ?? '');
        if (!$motif) json_response(false, 'Motif de rejet obligatoire.');
        $point = db_fetch_one("SELECT * FROM op_points_journaliers WHERE id=?", [$point_id]);
        if (!$point) json_response(false, 'Point introuvable.');
        if (!in_array($point['statut'], ['en_attente_validation','brouillon']))
            json_response(false, 'Ce point ne peut plus être rejeté (statut : '.$point['statut'].').');

        db_begin();
        try {
            // Restaurer les films dans chaque bobine
            $films = db_fetch_all(
                "SELECT bobine_id, films_utilises, films_endommages FROM op_films_utilises WHERE point_id=?",
                [$point_id]
            );
            foreach ($films as $f) {
                $total = (int)$f['films_utilises'] + (int)$f['films_endommages'];
                if ($total > 0) {
                    db_query(
                        "UPDATE op_bobines SET films_restants = films_restants + ?,
                         statut = CASE WHEN films_restants + ? >= qte_initiale THEN 'en_stock'
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
            $pmmats = db_fetch_all("SELECT type_pmma, utilises, endommages FROM op_pmma_utilises WHERE point_id=?", [$point_id]);
            foreach ($pmmats as $pm) {
                $total_pm = (int)$pm['utilises'] + (int)$pm['endommages'];
                if ($total_pm > 0) {
                    db_query("UPDATE stock_pmma_site SET quantite = quantite + ? WHERE site_id=? AND type_pmma=?",
                        [$total_pm, $point['site_id'], $pm['type_pmma']]);
                }
            }

            // Marquer rejeté
            db_query("UPDATE op_points_journaliers SET statut='rejete', motif_rejet=?, validated_by=?, validated_at=NOW() WHERE id=?",
                [$motif, $user['id'], $point_id]);

            // Notifier le coordinateur
            $coord = db_fetch_one("SELECT u.id FROM users u WHERE u.id=?", [$point['created_by']]);
            if ($coord) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$coord['id'], 'info', '❌ Point journalier rejeté',
                     "Votre point journalier du ".fmt_date($point['date_point'],'d/m/Y')." a été rejeté. Motif : $motif",
                     '/pages/operations/point_journalier.php']);
            }

            audit_log($user['id'], 'UPDATE', 'operations', $point_id, "Rejet point journalier #$point_id — stock restauré");
            db_commit();
            json_response(true, 'Point rejeté. Stock films, rivets et PMMA restaurés.');
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
                    if ($delta !== 0) {
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
                'approuvee'      => 'Correction confirmée. Le stock a été ajusté.',
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
.calc-item.warn .cv{color:#fbbf24}
.calc-item.danger .cv{color:#f87171}
.calc-item.success .cv{color:#34d399}

/* ── SECTION HEADERS IN MODAL ── */
.form-section{margin-bottom:24px}
.form-section-title{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid var(--border)}
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
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;max-width:95vw;max-height:95vh;overflow-y:auto;animation:mIn .22s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:16px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800}
.mclose{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.mbody{padding:20px 24px}
.mfoot{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}

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
<div style="display:flex;flex-direction:column;gap:8px">
<?php
$statut_cfg = [
  'valide'                => ['bg'=>'#d1fae5','col'=>'#065f46','icon'=>'<i class="ph ph-check-circle" aria-hidden="true"></i>','lbl'=>'Validé'],
  'brouillon'             => ['bg'=>'#fef3c7','col'=>'#92400e','icon'=>'⏳','lbl'=>'Brouillon'],
  'en_attente_validation' => ['bg'=>'#dbeafe','col'=>'#1d4ed8','icon'=>'<i class="ph ph-upload-simple" aria-hidden="true"></i>','lbl'=>'En attente'],
  'suivi'                 => ['bg'=>'#f1f5f9','col'=>'#475569','icon'=>'<i class="ph ph-chart-bar" aria-hidden="true"></i>','lbl'=>'Suivi'],
  'rejete'                => ['bg'=>'#fee2e2','col'=>'#991b1b','icon'=>'<i class="ph ph-x-circle" aria-hidden="true"></i>','lbl'=>'Rejeté'],
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
  if($p['nb_semi']>0)   $veh[]="<i class='ph ph-truck' aria-hidden='true'></i> {$p['nb_semi']}";
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
    <div class="pj-pills">
      <span class="pj-pill engins"><i class="ph ph-car" aria-hidden="true"></i> <?= $p['total_engins'] ?> engins</span>
      <span class="pj-pill plaques"><i class="ph ph-clipboard-text" aria-hidden="true"></i> <?= $p['total_plaques'] ?> plaques</span>
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
    <div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end">
      <button class="btn btn-secondary btn-sm" onclick="viewPoint(<?= $p['id'] ?>)" title="Aperçu"><i class="ph ph-eye" aria-hidden="true"></i></button>
      <button class="btn btn-secondary btn-sm" onclick="printPoint(<?= $p['id'] ?>)" title="Imprimer"><i class="ph ph-printer" aria-hidden="true"></i></button>
      <?php if($p['statut']==='brouillon' && ($p['agent_id']??0)==$user['id']): ?>
        <?php if(($p['type_point']??'')==='point_18h'): ?>
        <button class="btn btn-primary btn-sm" onclick="soumettrePoint(<?= $p['id'] ?>)" title="Soumettre"><i class="ph ph-upload-simple" aria-hidden="true"></i></button>
        <?php else: ?>
        <button class="btn btn-success btn-sm" onclick="validerPointCoord(<?= $p['id'] ?>)" title="Valider"><i class="ph ph-check-circle" aria-hidden="true"></i></button>
        <?php endif; ?>
        <button class="btn btn-secondary btn-sm" onclick="editPoint(<?= $p['id'] ?>)" title="Modifier" style="background:#e8f4f9;color:var(--blue)"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
      <?php endif; ?>
      <?php if(in_array($p['statut'],['brouillon','en_attente_validation']) && ($p['type_point']??'')==='point_18h' && in_array($role_slug_pj,['admin','superadmin','superviseur_operation'])): ?>
        <button class="btn btn-success btn-sm" onclick="validerPoint(<?= $p['id'] ?>)" title="Valider"><i class="ph ph-check-circle" aria-hidden="true"></i></button>
        <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" onclick="ouvrirRejet(<?= $p['id'] ?>,<?= htmlspecialchars(json_encode($p['date_point'])) ?>)" title="Rejeter"><i class="ph ph-x-circle" aria-hidden="true"></i></button>
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
      <h3><i class="ph ph-note-pencil" aria-hidden="true"></i> Nouveau point journalier</h3>
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
          </div>
          <div class="calc-item">
            <div class="cv" id="c-plaques">0</div>
            <div class="cl">Plaques</div>
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
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">1</div>
          <h4>Informations générales</h4>
        </div>
        <div class="form-row cols-3">
          <div class="form-group"><label for="p-site">Site</label>
            <?php if($role_slug_pj === 'coordinateur_site' && $user['site_id']): ?>
            <input type="hidden" id="p-site" value="<?= (int)$user['site_id'] ?>">
            <div class="form-control" style="background:var(--lighter);color:var(--navy);font-weight:600;cursor:default">
              <i class="ph ph-map-pin" aria-hidden="true"></i> <?= h(db_fetch_value("SELECT nom FROM sites WHERE id=?",[(int)$user['site_id']]) ?? '') ?>
            </div>
            <?php else: ?>
            <select class="form-control" id="p-site" required onchange="loadStockRivets();loadBobines();loadStockPMMA()">
              <option value="">— Sélectionner —</option>
              <?php foreach($sites_list as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
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
      </div>

      <!-- ② Véhicules posés -->
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">2</div>
          <h4>Véhicules posés</h4>
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
        $veh_icons = ['VP'=>'<i class="ph ph-car" aria-hidden="true"></i>','CAM'=>'<i class="ph ph-truck" aria-hidden="true"></i>','SEMI'=>'<i class="ph ph-truck" aria-hidden="true"></i>','MOTO'=>'<i class="ph ph-motorcycle" aria-hidden="true"></i>'];
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

      <!-- ③ Consommation rivets -->
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">3</div>
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
                     oninput="recalcule()" style="text-align:center;border-color:#f39c12">
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
                     oninput="recalcule()" style="text-align:center;border-color:#f39c12">
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
          <div class="form-section-num">4</div>
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
          <div class="form-section-num">5</div>
          <h4>Films utilisés par bobine</h4>
          <span style="margin-left:8px;font-size:12px;color:var(--muted)">Uniquement les bobines en utilisation</span>
        </div>
        <div id="films-container">
          <div style="text-align:center;color:var(--muted);padding:20px;font-size:13px">Chargement des bobines...</div>
        </div>
      </div><!-- /form-section ⑤ -->

      <!-- ⑥ Compléments -->
      <div class="form-section">
        <div class="form-section-title">
          <div class="form-section-num">6</div>
          <h4>Compléments</h4>
        </div>
        <div class="form-row cols-2" style="margin-bottom:16px">
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
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px" id="prev-stats"></div>
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
<div class="modal-overlay" id="mRejet">
  <div class="modal" style="width:480px">
    <div class="mhdr">
      <h3><i class="ph ph-x-circle" aria-hidden="true"></i> Rejeter le point journalier</h3>
      <button class="mclose" onclick="document.getElementById('mRejet').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div class="mbody">
      <input type="hidden" id="rejet-point-id" value="">
      <div id="rejet-info" style="background:#fee2e2;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#991b1b;font-weight:600"></div>
      <div class="form-group">
        <label>Motif du rejet *</label>
        <textarea class="form-control" id="rejet-motif" rows="3" placeholder="Expliquer pourquoi le point est rejeté…"></textarea>
      </div>
      <div style="background:#fef3c7;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400e;margin-top:12px">
        <i class="ph ph-warning" aria-hidden="true"></i> Le rejet va <strong>restaurer le stock</strong> films et rivets déduits par ce point. Le coordinateur sera notifié.
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="document.getElementById('mRejet').classList.remove('open')">Annuler</button>
      <button class="btn" style="background:#dc2626;color:white" onclick="confirmerRejet()"><i class="ph ph-x-circle" aria-hidden="true"></i> Confirmer le rejet</button>
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
}
function openPointForm(){
  resetPointForm();
  document.getElementById('mPoint').classList.add('open');
  <?php if($role_slug_pj === 'coordinateur_site' && $user['site_id']): ?>
  // Coordinateur : site déjà fixé, charger automatiquement
  loadStockRivets();
  loadBobines();
  loadStockPMMA();
  <?php endif; ?>
}
function resetPointForm(){
  TYPES_V.forEach(tv=>{ const el=document.getElementById('nb-'+tv.code); if(el) el.value=0; });
  <?php if($role_slug_pj !== 'coordinateur_site'): ?>
  document.getElementById('p-site').value='';
  <?php endif; ?>
  resetObservations();
  document.getElementById('p-date').value='<?= date('Y-m-d') ?>';
  document.getElementById('p-type').value='final';
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
  const moy    = heures>0 ? (engins/heures).toFixed(1) : 0;

  document.getElementById('c-engins').textContent=engins;
  document.getElementById('c-plaques').textContent=plaques;
  document.getElementById('c-rivets').textContent=totalRivets;
  document.getElementById('c-moy').textContent=moy+' V/H';

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
  // Éviter les doublons
  if(document.querySelector(`#pmma-lignes [data-type="${CSS.escape(type)}"]`)){
    sel.value=''; return;
  }
  const stock = pmmaStockDisponible[type] ?? '?';
  const div = document.createElement('div');
  div.dataset.type = type;
  div.style.cssText='display:grid;grid-template-columns:1fr 110px 110px 32px;gap:8px;align-items:end;padding:8px 10px;background:var(--lighter);border-radius:9px;margin-bottom:6px';
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
             class="form-control" style="font-size:13px;text-align:center">
    </div>
    <button type="button" onclick="this.closest('[data-type]').remove()"
            style="background:#fee2e2;border:none;border-radius:7px;cursor:pointer;font-size:15px;color:#991b1b;width:32px;height:32px;margin-bottom:1px"><i class="ph ph-x" aria-hidden="true"></i></button>`;
  document.getElementById('pmma-lignes').appendChild(div);
  sel.value='';
}

function collectPmmaData(){
  const result=[];
  document.querySelectorAll('#pmma-lignes [data-type]').forEach(row=>{
    const type_pmma = row.dataset.type;
    const util   = parseInt(row.querySelector('[data-kind="util"]')?.value||0);
    const endomm = parseInt(row.querySelector('[data-kind="end"]')?.value||0);
    result.push({type_pmma, utilises:util, endommages:endomm});
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
      display:grid;grid-template-columns:1fr 120px 120px 40px;gap:10px;
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
  const restants=b.films_restants-(util+endomm);
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
const ETAPES_ENDO = [
  ['pose',       'À la pose'],
  ['impression', "À l'impression"],
  ['transport',  'Au transport'],
  ['stockage',   'Au stockage'],
  ['autre',      'Autre'],
];
const CAUSES_ENDO = [
  ['manipulation',     'Manipulation incorrecte'],
  ['defaut_materiel',  'Défaut matériel'],
  ['incident_externe', 'Incident externe'],
  ['autre',            'Autre'],
];
// bobine_id -> tableau d'une entrée par film endommagé
let endommagementsSaisis = {};

// Appelé quand une quantité endommagée change : on ouvre le pop-up à la
// première saisie, sans harceler l'agent à chaque frappe suivante.
function onEndommageChange(idx){
  updateBobineRestants(idx);
  const bid    = document.getElementById('bsel-'+idx)?.value;
  const endomm = parseInt(document.getElementById('bendomm-'+idx)?.value||0);
  if (endomm > 0 && bid && !endommagementsSaisis[bid]) ouvrirPopupEndommagement();
  if (endomm === 0 && bid) delete endommagementsSaisis[bid];
}

function ouvrirPopupEndommagement(){
  const lignes = getBobinesData().filter(f => (f.films_endommages|0) > 0);
  const box = document.getElementById('endoLignes');
  if (!lignes.length){
    box.innerHTML = '<div style="color:var(--muted);font-size:13px">Aucune bobine endommagée saisie.</div>';
  } else {
    const opt = (arr, sel) => arr.map(([v,l]) =>
      `<option value="${v}" ${sel===v?'selected':''}>${l}</option>`).join('');

    box.innerHTML = lignes.map(f => {
      const b   = bobinesCache[f.bobine_id] || {};
      const nb  = f.films_endommages|0;
      const dej = endommagementsSaisis[f.bobine_id] || [];

      // Un bloc par film : chacun peut avoir sa propre étape, sa cause et
      // sa personne. Le report ci-dessous évite de tout ressaisir quand
      // les films ont été abîmés dans les mêmes circonstances.
      const films = Array.from({length: nb}, (_, i) => {
        const d = dej[i] || {};
        return `
        <div class="endo-film" data-bobine="${f.bobine_id}" data-film="${i+1}"
             style="border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-top:10px;background:white">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:9px">
            <span style="font-size:12px;font-weight:800;color:var(--danger-d);text-transform:uppercase;letter-spacing:.4px">
              Film ${i+1} sur ${nb}
            </span>
            ${i===0 && nb>1 ? `<button type="button" class="btn btn-secondary btn-sm"
                 onclick="reporterEndommagement('${f.bobine_id}')"
                 title="Recopier ces valeurs sur les films suivants de cette bobine">
                 <i class="ph ph-copy" aria-hidden="true"></i> Reporter sur les ${nb-1} suivants</button>` : ''}
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Personne concernée *</label>
              <input type="text" class="form-control endo-personne" value="${(d.personne||'').replace(/"/g,'&quot;')}"
                     placeholder="Nom de la personne" style="margin-top:4px">
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Heure de l'incident</label>
              <input type="time" class="form-control endo-heure" value="${d.heure||''}" style="margin-top:4px">
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Étape *</label>
              <select class="form-control endo-etape" style="margin-top:4px">${opt(ETAPES_ENDO, d.etape)}</select>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Cause *</label>
              <select class="form-control endo-cause" style="margin-top:4px">${opt(CAUSES_ENDO, d.cause)}</select>
            </div>
          </div>
          <div style="margin-top:9px">
            <label style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">Observations</label>
            <textarea class="form-control endo-obs" rows="2" placeholder="Précisions libres…"
                      style="margin-top:4px">${d.observations||''}</textarea>
          </div>
        </div>`;
      }).join('');

      return `
      <div style="border:1.5px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:14px;background:var(--lighter)">
        <div style="font-weight:800;color:var(--navy);font-size:14px">Bobine ${b.numero||f.bobine_id}</div>
        <div style="font-size:12px;color:var(--danger-d);font-weight:700">
          ${nb} film(s) endommagé(s) — une déclaration par film
        </div>
        ${films}
      </div>`;
    }).join('');
  }
  document.getElementById('endoAlert').innerHTML = '';
  document.getElementById('mEndommagement').classList.add('open');
}

// Recopie le premier film sur les suivants de la même bobine.
function reporterEndommagement(bobineId){
  const blocs = document.querySelectorAll(`.endo-film[data-bobine="${bobineId}"]`);
  if (blocs.length < 2) return;
  const src = blocs[0];
  const val = s => src.querySelector(s).value;
  for (let i = 1; i < blocs.length; i++){
    blocs[i].querySelector('.endo-personne').value = val('.endo-personne');
    blocs[i].querySelector('.endo-heure').value    = val('.endo-heure');
    blocs[i].querySelector('.endo-etape').value    = val('.endo-etape');
    blocs[i].querySelector('.endo-cause').value    = val('.endo-cause');
    blocs[i].querySelector('.endo-obs').value      = val('.endo-obs');
  }
  toast(`Valeurs reportées sur ${blocs.length-1} film(s).`,'success');
}

function validerEndommagements(){
  const blocs = document.querySelectorAll('#endoLignes .endo-film');
  const saisie = {};
  for (const l of blocs){
    const personne = l.querySelector('.endo-personne').value.trim();
    if (!personne){
      document.getElementById('endoAlert').innerHTML =
        '<div class="alert alert-danger" style="font-size:13px">La personne concernée est obligatoire pour chaque film.</div>';
      l.querySelector('.endo-personne').focus();
      l.scrollIntoView({behavior:'smooth', block:'center'});
      return;
    }
    const bid = l.dataset.bobine;
    (saisie[bid] = saisie[bid] || []).push({
      personne,
      etape:        l.querySelector('.endo-etape').value,
      cause:        l.querySelector('.endo-cause').value,
      heure:        l.querySelector('.endo-heure').value,
      observations: l.querySelector('.endo-obs').value.trim(),
    });
  }
  endommagementsSaisis = saisie;
  document.getElementById('mEndommagement').classList.remove('open');
  toast('Déclaration(s) enregistrée(s).','success');
}

// Aplatit en une entrée par film, et n'envoie que les bobines dont le
// nombre de déclarations correspond encore à la quantité endommagée :
// le serveur refuse tout écart, autant ne pas l'y conduire.
function collectEndommagements(films){
  const sortie = [];
  films.forEach(f => {
    const nb = f.films_endommages|0;
    const d  = endommagementsSaisis[f.bobine_id];
    if (nb > 0 && d && d.length === nb){
      d.forEach(x => sortie.push(Object.assign({bobine_id: f.bobine_id}, x)));
    }
  });
  return sortie;
}

function getBobinesData(){
  const rows=[];
  document.querySelectorAll('[id^="bsrow-"]').forEach(row=>{
    const idx=row.id.replace('bsrow-','');
    const bid=document.getElementById('bsel-'+idx)?.value;
    const util=parseInt(document.getElementById('butil-'+idx)?.value||0);
    const endomm=parseInt(document.getElementById('bendomm-'+idx)?.value||0);
    if(bid&&(util+endomm)>0) rows.push({bobine_id:bid,films_utilises:util,films_endommages:endomm,type_vehicule_id:1});
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
function autoFilmsFromVeh(code,tvId,serie){
  const nb=parseInt(document.getElementById('nb-'+code)?.value||0);
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
    ['<i class="ph ph-truck" aria-hidden="true"></i>',document.getElementById('nb-SEMI')?.value||0,'Semi'],
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
  const manquantes = films_data.filter(f => {
    const nb = f.films_endommages|0;
    const d  = endommagementsSaisis[f.bobine_id];
    return nb > 0 && (!d || d.length !== nb);
  });
  if (manquantes.length) {
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
    endommagements_data: JSON.stringify(collectEndommagements(films_data)),
  }).then(d=>{
    btn.disabled=false; btn.textContent='💾 Enregistrer le point';
    if(d.success){
      toast(d.message,'success');
      document.getElementById('mPoint').classList.remove('open');
      setTimeout(()=>location.reload(),900);
    } else {
      document.getElementById('pAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
    }
  });
}

// ── VOIR DÉTAIL POINT
async function viewPoint(id){
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
    'rejete':               {bg:'#fee2e2',col:'#991b1b',icon:'❌',lbl:'Rejeté'},
  };
  const typeLbl={'point_9h':'Point 9h','point_13h':'Point 13h','point_18h':'Point 18h — Final','final':'Final','intermediaire':'Intermédiaire'};
  const sc = statutCfg[p.statut]||{bg:'#f3f4f6',col:'#374151',icon:'·',lbl:p.statut};
  const dateStr = new Date(p.date_point).toLocaleDateString('fr-FR',{weekday:'long',day:'2-digit',month:'long',year:'numeric'});

  // Véhicules
  const vehs=[['🚗','VP',p.nb_vp],['🚛','Camion',p.nb_camion],['🚚','Semi',p.nb_semi],['🏍️','Moto',p.nb_moto]];
  const vehCards = vehs.map(([ic,lb,nb])=>`
    <div style="background:${nb>0?'#e3f2fd':'#f8fafc'};border-radius:10px;padding:12px;text-align:center;border:1px solid ${nb>0?'#90caf9':'#e2e8f0'}">
      <div style="font-size:20px">${ic}</div>
      <div style="font-family:'Montserrat',sans-serif;font-size:20px;font-weight:900;color:${nb>0?'#1565c0':'#cbd5e1'}">${nb}</div>
      <div style="font-size:12px;color:${nb>0?'#1565c0':'#94a3b8'};font-weight:600;text-transform:uppercase">${lb}</div>
    </div>`).join('');

  // Rivets
  const gonfl  = parseInt(p.rivets_gonflables)||0;
  const eclate = parseInt(p.rivets_eclates)||0;
  const rivEnd  = parseInt(p.rivets_endommages)||0;
  const rivSection = (gonfl+eclate+rivEnd)>0 ? `
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">🔩 Rivets</div>
      <div style="display:grid;grid-template-columns:1fr 1fr${rivEnd>0?' 1fr':''};gap:10px">
        ${gonfl>0?`<div style="background:#e3f2fd;border-radius:10px;padding:12px;text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#1565c0">${gonfl}</div><div style="font-size:12px;color:#1565c0;font-weight:700;text-transform:uppercase">🔵 Gonflables</div></div>`:''}
        ${eclate>0?`<div style="background:#fce4ec;border-radius:10px;padding:12px;text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#880e4f">${eclate}</div><div style="font-size:12px;color:#880e4f;font-weight:700;text-transform:uppercase">🔴 Éclatés</div></div>`:''}
        ${rivEnd>0?`<div style="background:#fff3e0;border-radius:10px;padding:12px;text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:22px;font-weight:900;color:#e65100">${rivEnd}</div><div style="font-size:12px;color:#e65100;font-weight:700;text-transform:uppercase">⚠️ Endommagés</div></div>`:''}
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

  const motifSection = p.motif_rejet ? `
    <div style="background:#fee2e2;border-radius:10px;padding:14px;margin-bottom:20px;border-left:3px solid #ef4444">
      <div style="font-size:12px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">❌ Motif de rejet</div>
      <div style="font-size:13px;color:#7f1d1d">${p.motif_rejet}</div>
    </div>` : '';

  // Section PMMA
  const pmmaSection = pmmaList.length>0 ? `
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">🟦 PMMA consommé</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        ${pmmaList.map(pm=>{
          const tot=(parseInt(pm.utilises)||0)+(parseInt(pm.endommages)||0);
          return `<div style="display:grid;grid-template-columns:1fr 90px 90px 80px;gap:8px;align-items:center;background:#f8fafc;border-radius:8px;padding:10px 12px">
            <div style="font-weight:600;font-size:13px;color:var(--navy)">${pm.type_pmma}</div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#1565c0">${pm.utilises}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Utilisés</div></div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#e65100">${pm.endommages}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Endommagés</div></div>
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
          <div style="display:grid;grid-template-columns:1fr 90px 90px;gap:8px;align-items:center;background:#f8fafc;border-radius:8px;padding:10px 12px">
            <div>
              <div style="font-family:'Montserrat',sans-serif;font-size:13px;font-weight:800;color:#1565c0">Bobine #${f.bobine_num}</div>
              <div style="font-size:12px;color:var(--muted)">${f.type_code||''}</div>
            </div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#1565c0">${f.films_utilises}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Utilisés</div></div>
            <div style="text-align:center"><div style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#e65100">${f.films_endommages||0}</div><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Endommagés</div></div>
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
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
        <div style="text-align:center">
          <div style="font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900">${p.total_engins}</div>
          <div style="font-size:12px;opacity:.5;text-transform:uppercase">Engins</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:'Montserrat',sans-serif;font-size:28px;font-weight:900">${p.total_plaques}</div>
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
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px"><i class="ph ph-car" aria-hidden="true"></i> Répartition véhicules</div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">${vehCards}</div>
      <div style="display:flex;justify-content:space-between;margin-top:10px;padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:13px">
        <span style="color:var(--muted)">Total engins</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:var(--navy)">${p.total_engins}</span>
        <span style="color:var(--muted)">Total plaques</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:#1565c0">${p.total_plaques}</span>
        <span style="color:var(--muted)">Heures</span>
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:var(--navy)">${p.nb_heures_travail||'—'}h</span>
      </div>
    </div>

    ${rivSection}
    ${pmmaSection}
    ${filmsSection}
    ${npSection}
    ${obsSection}
    ${motifSection}

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
  // Champs généraux
  if(document.getElementById('p-site').tagName==='SELECT')
    document.getElementById('p-site').value = p.site_id;
  document.getElementById('p-date').value  = p.date_point;
  document.getElementById('p-type').value  = p.type_point;
  document.getElementById('p-heures').value= p.nb_heures_travail||'8';
  document.getElementById('p-np-conc').value = p.non_poses_concessionnaires||'0';
  document.getElementById('p-np-usag').value = p.non_poses_usagers||'0';
  chargerObservations(p.observations);
  // Véhicules
  const nbVP   = document.getElementById('nb-VP');   if(nbVP)   nbVP.value   = p.nb_vp||'0';
  const nbCAM  = document.getElementById('nb-CAM');  if(nbCAM)  nbCAM.value  = p.nb_camion||'0';
  const nbSEMI = document.getElementById('nb-SEMI'); if(nbSEMI) nbSEMI.value = p.nb_semi||'0';
  const nbMOTO = document.getElementById('nb-MOTO'); if(nbMOTO) nbMOTO.value = p.nb_moto||'0';
  // Rivets (on stocke les totaux par type ; posés = total, endomm = 0 si non distinguables)
  document.getElementById('p-gonfl-util').value  = p.rivets_gonflables||'0';
  document.getElementById('p-gonfl-end').value   = '0';
  document.getElementById('p-eclate-util').value = p.rivets_eclates||'0';
  document.getElementById('p-eclate-end').value  = '0';
  await loadStockRivets();
  await loadBobines();
  // Restaurer les bobines sauvegardées
  if(p.films && p.films.length > 0){
    renderBobinesRows(p.films.map(f=>parseInt(f.bobine_id)));
    p.films.forEach((f,i)=>{
      const sel    = document.getElementById('bsel-'+i);
      const util   = document.getElementById('butil-'+i);
      const endomm = document.getElementById('bendomm-'+i);
      if(sel)    sel.value    = f.bobine_id;
      if(util)   util.value   = f.films_utilises;
      if(endomm) endomm.value = f.films_endommages;
      updateBobineRestants(i);
    });
  }
  await loadStockPMMA();
  recalcule();
  document.getElementById('mPoint').classList.add('open');
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
function validerPoint(id){
  if(!confirm('Valider ce point journalier ? Cette action est définitive.'))return;
  ap({action:'valider_point',point_id:id}).then(d=>{toast(d.message,d.success?'success':'danger');if(d.success)setTimeout(()=>location.reload(),800);});
}

function printPoint(id){window.open('<?= rtrim(APP_URL,'/') ?>/pages/operations/point_pdf.php?id='+id,'_blank');}

function ouvrirRejet(id, date){
  document.getElementById('rejet-point-id').value = id;
  document.getElementById('rejet-info').textContent = `Point journalier du ${date} — les films et rivets déduits seront restaurés.`;
  document.getElementById('rejet-motif').value = '';
  document.getElementById('mRejet').classList.add('open');
}
function confirmerRejet(){
  const id     = document.getElementById('rejet-point-id').value;
  const motif  = document.getElementById('rejet-motif').value.trim();
  if(!motif){alert('Veuillez saisir un motif de rejet.');return;}
  ap({action:'rejeter_point', point_id:id, motif_rejet:motif}).then(d=>{
    toast(d.message, d.success?'success':'danger');
    document.getElementById('mRejet').classList.remove('open');
    if(d.success) setTimeout(()=>location.reload(), 900);
  });
}
document.getElementById('mRejet').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');});

function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
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
