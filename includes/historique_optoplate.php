<?php
// ============================================================
//  includes/historique_optoplate.php — Production d'avant l'ERP, dans le dashboard
//
//  Le dashboard et la vue PDG calculent tout à partir des points journaliers
//  saisis dans l'ERP : la production d'avant la mise en service n'y figure
//  pas. Cette fonction la reconstitue à partir des plaques OptoPlate déjà
//  importées (écran Import EMUCI) : UN POINT PAR SITE ET PAR JOUR, avec
//  les engins et plaques par type de véhicule, les réimpressions
//  Duplicata / Post-Immat (même règle de classement que la comparaison du
//  superviseur, includes/optoplate_comparaison.php) et les films par
//  bobine lorsque la bobine existe dans l'ERP.
//
//  Appelée par l'écran Import EMUCI (encadré « Alimenter le dashboard »,
//  simulation puis application) et par tools/historique_optoplate.php.
//
//  Garde-fous :
//   · SIMULATION par défaut : rien n'est écrit sans $appliquer ;
//   · aucun mouvement de stock : les points s'écrivent directement, sans
//     passer par l'enregistrement normal qui déduit films, rivets et PMMA ;
//   · type de point « historique » : ni le blocage des coordinateurs ni la
//     validation du stock (qui ne regardent que le point 18h) ne les voient ;
//   · un jour qui a déjà un point dans l'ERP (n'importe quel type) n'est
//     jamais touché ;
//   · rejouable : un jour déjà reconstitué est ignoré, $refaire le recrée.
//  Limites : OptoPlate ne connaît ni les heures, ni les rivets, ni le PMMA :
//  ils restent vides dans l'historique (le V/H n'est pas calculé).
// ============================================================
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/optoplate_comparaison.php';

const TYPE_HISTORIQUE = 'historique';

/** Colonnes nécessaires : null si tout est en place, sinon le message à afficher. */
function historique_optoplate_prerequis(): ?string {
    foreach ([['import_optoplate', 'type_desactivation'], ['import_optoplate', 'categorie'],
              ['op_points_journaliers', 'nb_vp_duplicata'], ['op_points_journaliers', 'total_engins_postimmat']] as [$t, $c]) {
        if (!db_colonne_existe($t, $c)) {
            return "Colonne $t.$c absente : jouer d'abord les migrations OptoPlate et Re-Immatriculation.";
        }
    }
    return null;
}

/**
 * Période proposée par défaut : de la première plaque importée jusqu'à la veille du
 * premier vrai point de l'ERP (la mise en service), sans dépasser hier (la journée en
 * cours n'est pas terminée). `du` est null si aucune plaque n'est importée.
 * Si le premier point de l'ERP précède toutes les plaques (données d'essai, par exemple),
 * « la veille » n'a pas de sens : on propose jusqu'à hier — les jours déjà saisis ne sont
 * jamais touchés. `coupe_a_la_mise_en_service` dit quel cas s'applique.
 */
function historique_optoplate_periode(): array {
    $du = db_fetch_value("SELECT DATE(MIN(date_installation)) FROM import_optoplate WHERE site_id IS NOT NULL");
    $premier_reel = db_fetch_value("SELECT MIN(date_point) FROM op_points_journaliers WHERE type_point <> ?", [TYPE_HISTORIQUE]);
    $hier = date('Y-m-d', strtotime('-1 day'));
    $au = $hier; $coupe = false;
    if ($premier_reel && $du) {
        $veille = date('Y-m-d', strtotime($premier_reel . ' -1 day'));
        if ($veille >= $du) { $au = min($hier, $veille); $coupe = true; }
    }
    return ['du' => $du ?: null, 'au' => $au, 'premier_reel' => $premier_reel ?: null, 'coupe_a_la_mise_en_service' => $coupe];
}

/**
 * Reconstitue (ou simule) les points historiques de $du à $au.
 * Lève RuntimeException si les prérequis manquent ; une erreur d'écriture annule le site
 * en cours (transaction) et se propage.
 * Retourne le compte rendu : stats, par_mois, par_site, sans_site, bobines_inconnues.
 */
function historique_optoplate(string $du, string $au, bool $appliquer = false, int $site_seul = 0, bool $refaire = false): array {
    if ($err = historique_optoplate_prerequis()) throw new RuntimeException($err);
    if ($du > $au) throw new RuntimeException("Période vide : $du est après $au.");

    // ── Références
    $bobines = [];   // numéro => [id, type_vehicule_id]
    foreach (db_fetch_all("SELECT id, numero, COALESCE(type_vehicule_id, 1) AS tv FROM op_bobines") as $b) {
        $bobines[$b['numero']] = [(int)$b['id'], (int)$b['tv']];
    }
    $sites = array_column(db_fetch_all("SELECT id, nom FROM sites"), 'nom', 'id');

    // Jours déjà couverts : un vrai point (jamais touché) ou un jour déjà reconstitué.
    $reels = []; $hist = [];
    foreach (db_fetch_all(
        "SELECT site_id, date_point, type_point FROM op_points_journaliers WHERE date_point BETWEEN ? AND ?",
        [$du, $au]) as $r) {
        $cle = $r['site_id'] . '|' . $r['date_point'];
        if ($r['type_point'] === TYPE_HISTORIQUE) $hist[$cle] = true; else $reels[$cle] = true;
    }

    $ids_sites = array_map('intval', array_column(db_fetch_all(
        "SELECT DISTINCT site_id FROM import_optoplate
          WHERE site_id IS NOT NULL AND date_installation >= ? AND date_installation < DATE_ADD(?, INTERVAL 1 DAY)"
        . ($site_seul ? " AND site_id = $site_seul" : ''), [$du, $au]), 'site_id'));
    sort($ids_sites);
    $sans_site = (int)db_fetch_value(
        "SELECT COUNT(*) FROM import_optoplate WHERE site_id IS NULL
          AND date_installation >= ? AND date_installation < DATE_ADD(?, INTERVAL 1 DAY)", [$du, $au]);

    $obs = json_encode([['type' => 'info', 'texte' =>
        "Reconstitué à partir des plaques OptoPlate (poses du jour). Heures, rivets et PMMA non disponibles."]],
        JSON_UNESCAPED_UNICODE);

    $stats = ['points' => 0, 'engins' => 0, 'plaques' => 0, 'films' => 0, 'ignores_reels' => 0, 'ignores_hist' => 0,
              'plaques_sans_bobine' => 0, 'plaques_total' => 0, 'vehicules_autre' => 0, 'refaits' => 0];
    $par_mois = []; $par_site = []; $bobines_inconnues = [];

    foreach ($ids_sites as $site_id) {
        // Poses du site sur la période, par jour (même sélection que la comparaison du superviseur).
        $poses_jour = [];
        foreach (db_fetch_all(
            "SELECT id, immatriculation, vin, num_bobine, categorie, DATE(date_installation) AS jour
             FROM import_optoplate
             WHERE site_id=? AND date_installation >= ? AND date_installation < DATE_ADD(?, INTERVAL 1 DAY)
               AND (statut_plaque='in_use' OR type_desactivation IN ('Duplicata','Modification POST'))
             ORDER BY date_installation, id", [$site_id, $du, $au]) as $p) {
            $poses_jour[$p['jour']][] = $p;
        }
        if (!$poses_jour) continue;
        ksort($poses_jour);

        // Désactivations Duplicata / Modification POST : TOUTES celles d'avant la fin de la
        // période (y compris avant son début), de la plus ancienne à la plus récente.
        $desact = db_fetch_all(
            "SELECT immatriculation, vin, type_desactivation, date_desactivation
             FROM import_optoplate
             WHERE site_id=? AND type_desactivation IN ('Duplicata','Modification POST')
               AND date_desactivation IS NOT NULL AND date_desactivation <= ?
             ORDER BY date_desactivation, id", [$site_id, $au]);
        // Incidents (plaques cassées) par jour et par bobine.
        $incidents = [];
        foreach (db_fetch_all(
            "SELECT date_desactivation AS jour, num_bobine, COUNT(*) AS n
             FROM import_optoplate
             WHERE site_id=? AND type_desactivation='Incident' AND date_desactivation BETWEEN ? AND ?
             GROUP BY date_desactivation, num_bobine", [$site_id, $du, $au]) as $i) {
            $incidents[$i['jour']][(string)$i['num_bobine']] = (int)$i['n'];
        }

        $type_vehicule = []; $ptr = 0; $nd = count($desact);
        $a_ecrire = [];
        foreach ($poses_jour as $jour => $poses) {
            // Désactivations connues à ce jour, comprises (la dernière décide du type du véhicule).
            while ($ptr < $nd && $desact[$ptr]['date_desactivation'] <= $jour) {
                foreach (array_filter([trim((string)$desact[$ptr]['immatriculation']), trim((string)$desact[$ptr]['vin'])]) as $cle) {
                    $type_vehicule[$cle] = $desact[$ptr]['type_desactivation'];
                }
                $ptr++;
            }
            $cle_jour = $site_id . '|' . $jour;
            if (isset($reels[$cle_jour])) { $stats['ignores_reels']++; continue; }
            if (isset($hist[$cle_jour]) && !$refaire) { $stats['ignores_hist']++; continue; }

            $c = op_classer_poses($poses, $type_vehicule);
            // Véhicules de catégorie inconnue (colonne vide) : comptés en VP, signalés.
            $fusion = function (array $par_type) use (&$stats): array {
                $r = ['VP' => 0, 'CAM' => 0, 'SEMI' => 0, 'MOTO' => 0, 'plaques' => 0];
                foreach ($par_type as $t => $v) {
                    $r[$t === 'AUTRE' ? 'VP' : $t] += $v['vehicules'];
                    $r['plaques'] += $v['plaques'];
                    if ($t === 'AUTRE') $stats['vehicules_autre'] += $v['vehicules'];
                }
                return $r;
            };
            $base = $fusion($c['primo']); $dup = $fusion($c['duplicata_par_type']); $pi = $fusion($c['post_immat_par_type']);
            $eng = fn(array $r) => $r['VP'] + $r['CAM'] + $r['SEMI'] + $r['MOTO'];

            // Films par bobine connue de l'ERP : plaques posées, plus plaques cassées du jour.
            $films = [];
            foreach ($c['par_bobine'] as $num => $n) {
                $stats['plaques_total'] += $n;
                if (isset($bobines[$num])) $films[$num] = ['u' => $n, 'e' => 0];
                else { $stats['plaques_sans_bobine'] += $n; $bobines_inconnues[$num] = ($bobines_inconnues[$num] ?? 0) + $n; }
            }
            foreach ($incidents[$jour] ?? [] as $num => $n) {
                if (isset($bobines[$num])) { $films[$num]['u'] = $films[$num]['u'] ?? 0; $films[$num]['e'] = $n; }
            }

            $a_ecrire[] = ['jour' => $jour, 'base' => $base, 'dup' => $dup, 'pi' => $pi,
                           'eng_base' => $eng($base), 'eng_dup' => $eng($dup), 'eng_pi' => $eng($pi), 'films' => $films];
        }
        if (!$a_ecrire) continue;

        if ($appliquer) db_begin();
        try {
            foreach ($a_ecrire as $j) {
                $jour = $j['jour'];
                if ($appliquer) {
                    if (isset($hist[$site_id . '|' . $jour])) {   // $refaire : on remplace le jour reconstitué
                        $ancien = db_fetch_value("SELECT id FROM op_points_journaliers WHERE site_id=? AND date_point=? AND type_point=?",
                            [$site_id, $jour, TYPE_HISTORIQUE]);
                        db_query("DELETE FROM op_films_utilises WHERE point_id=?", [$ancien]);
                        db_query("DELETE FROM op_points_journaliers WHERE id=?", [$ancien]);
                        $stats['refaits']++;
                    }
                    db_query(
                        "INSERT INTO op_points_journaliers
                           (site_id, date_point, type_point, statut,
                            nb_vp, nb_camion, nb_semi, nb_moto, total_engins, total_plaques, moyenne_prod,
                            rivets_utilises, rivets_endommages, nb_heures_travail, observations,
                            nb_vp_duplicata, nb_camion_duplicata, nb_semi_duplicata, nb_moto_duplicata,
                            total_engins_duplicata, total_plaques_duplicata,
                            nb_vp_postimmat, nb_camion_postimmat, nb_semi_postimmat, nb_moto_postimmat,
                            total_engins_postimmat, total_plaques_postimmat)
                         VALUES (?,?,?,'valide', ?,?,?,?,?,?,0, 0,0,NULL,?, ?,?,?,?,?,?, ?,?,?,?,?,?)",
                        [$site_id, $jour, TYPE_HISTORIQUE,
                         $j['base']['VP'], $j['base']['CAM'], $j['base']['SEMI'], $j['base']['MOTO'], $j['eng_base'], $j['base']['plaques'],
                         $obs,
                         $j['dup']['VP'], $j['dup']['CAM'], $j['dup']['SEMI'], $j['dup']['MOTO'], $j['eng_dup'], $j['dup']['plaques'],
                         $j['pi']['VP'], $j['pi']['CAM'], $j['pi']['SEMI'], $j['pi']['MOTO'], $j['eng_pi'], $j['pi']['plaques']]
                    );
                    $point_id = (int)db_last_id();
                    foreach ($j['films'] as $num => $f) {
                        db_query("INSERT INTO op_films_utilises (point_id, bobine_id, type_vehicule_id, films_utilises, films_endommages)
                                  VALUES (?,?,?,?,?)", [$point_id, $bobines[$num][0], $bobines[$num][1], $f['u'], $f['e']]);
                    }
                }
                $eng_total = $j['eng_base'] + $j['eng_dup'] + $j['eng_pi'];
                $plq_total = $j['base']['plaques'] + $j['dup']['plaques'] + $j['pi']['plaques'];
                $mois = substr($jour, 0, 7);
                $stats['points']++; $stats['engins'] += $eng_total; $stats['plaques'] += $plq_total;
                $stats['films'] += array_sum(array_column($j['films'], 'u'));
                $par_mois[$mois]['points'] = ($par_mois[$mois]['points'] ?? 0) + 1;
                $par_mois[$mois]['engins'] = ($par_mois[$mois]['engins'] ?? 0) + $eng_total;
                $par_mois[$mois]['plaques'] = ($par_mois[$mois]['plaques'] ?? 0) + $plq_total;
                $par_site[$site_id]['points'] = ($par_site[$site_id]['points'] ?? 0) + 1;
                $par_site[$site_id]['engins'] = ($par_site[$site_id]['engins'] ?? 0) + $eng_total;
                $par_site[$site_id]['dup_pi'] = ($par_site[$site_id]['dup_pi'] ?? 0) + $j['eng_dup'] + $j['eng_pi'];
            }
            if ($appliquer) db_commit();
        } catch (Throwable $e) {
            if ($appliquer) db_rollback();
            throw new RuntimeException("Site n°$site_id : erreur, rien n'est écrit pour ce site. " . $e->getMessage(), 0, $e);
        }
    }

    ksort($par_mois);
    foreach ($par_site as $id => &$v) $v['nom'] = $sites[$id] ?? "site n°$id";
    unset($v);
    arsort($bobines_inconnues);

    return ['du' => $du, 'au' => $au, 'appliquer' => $appliquer, 'refaire' => $refaire, 'site_seul' => $site_seul,
            'stats' => $stats, 'par_mois' => $par_mois, 'par_site' => $par_site, 'sans_site' => $sans_site,
            'bobines_inconnues' => array_slice($bobines_inconnues, 0, 5, true)];
}

/**
 * Sites dont le point est généré depuis OptoPlate : [site_id => date de début].
 * Réglage par site (écran Sites). Vide tant que la migration n'est pas passée.
 */
function historique_auto_sites(): array {
    if (!db_colonne_existe('sites', 'point_auto_depuis')) return [];
    $o = [];
    foreach (db_fetch_all("SELECT id, point_auto_depuis FROM sites WHERE actif = 1 AND point_auto_depuis IS NOT NULL") as $r) {
        $o[(int)$r['id']] = (string)$r['point_auto_depuis'];
    }
    return $o;
}

/**
 * Après un import OptoPlate : met à jour, pour les sites réglés « point généré depuis
 * OptoPlate », les points des jours couverts par le fichier ($premier_jour à $dernier_jour,
 * poses). Même règles que la reconstitution : aucun stock, jamais un jour déjà saisi par un
 * coordinateur, jour recalculé à chaque import (OptoPlate met les plaques à jour dans le temps).
 * Un site qui commence à saisir (premier point non brouillon à partir de la date de début)
 * sort de la génération automatique.
 * Retourne ['sites' => noms, 'points' => n, 'arretes' => [...], 'erreurs' => [...]].
 */
function historique_auto_import(string $premier_jour, string $dernier_jour): array {
    $res = ['sites' => [], 'points' => 0, 'arretes' => [], 'erreurs' => []];
    $sites = historique_auto_sites();
    if (!$sites || historique_optoplate_prerequis()) return $res;
    $noms = array_column(db_fetch_all("SELECT id, nom FROM sites"), 'nom', 'id');
    $dernier = min($dernier_jour, date('Y-m-d'));
    foreach ($sites as $id => $depuis) {
        $nom = $noms[$id] ?? "site n°$id";
        $reel = db_fetch_value(
            "SELECT MIN(date_point) FROM op_points_journaliers
              WHERE site_id = ? AND type_point <> ? AND statut <> 'brouillon' AND date_point >= ?",
            [$id, TYPE_HISTORIQUE, $depuis]);
        if ($reel) {
            db_query("UPDATE sites SET point_auto_depuis = NULL WHERE id = ?", [$id]);
            audit_log(null, 'UPDATE', 'sites', $id, "Point automatique OptoPlate arrêté : premier point saisi le $reel");
            $res['arretes'][] = "$nom (saisie depuis le " . date('d/m/Y', strtotime($reel)) . ')';
            continue;
        }
        $du = max($premier_jour, $depuis);
        if ($du > $dernier) continue;
        try {
            $r = historique_optoplate($du, $dernier, true, $id, true);
            if ($r['stats']['points'] > 0) { $res['sites'][] = $nom; $res['points'] += $r['stats']['points']; }
        } catch (Throwable $e) {
            $res['erreurs'][] = "$nom : " . $e->getMessage();
        }
    }
    return $res;
}
