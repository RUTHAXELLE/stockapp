<?php
// ============================================================
//  tools/historique_optoplate.php — Historique de production dans le dashboard
//
//  Le dashboard et la vue PDG calculent tout à partir des points journaliers
//  saisis dans l'ERP : la production d'avant la mise en service n'y figure
//  pas. Ce script la reconstitue à partir des plaques OptoPlate déjà
//  importées (écran Import EMUCI) : UN POINT PAR SITE ET PAR JOUR, avec
//  les engins et plaques par type de véhicule, les réimpressions
//  Duplicata / Post-Immat (même règle de classement que la comparaison du
//  superviseur, includes/optoplate_comparaison.php) et les films par
//  bobine lorsque la bobine existe dans l'ERP.
//
//  Garde-fous :
//   · SIMULATION par défaut : rien n'est écrit sans --appliquer ;
//   · aucun mouvement de stock : les points s'écrivent directement, sans
//     passer par l'enregistrement normal qui déduit films, rivets et PMMA ;
//   · type de point « historique » : ni le blocage des coordinateurs ni la
//     validation du stock (qui ne regardent que le point 18h) ne les voient ;
//   · un jour qui a déjà un point dans l'ERP (n'importe quel type) n'est
//     jamais touché ;
//   · rejouable : un jour déjà reconstitué est ignoré, --refaire le recrée.
//  Limites : OptoPlate ne connaît ni les heures, ni les rivets, ni le PMMA :
//  ils restent vides dans l'historique (le V/H n'est pas calculé).
//
//  Usage (depuis la racine de l'application) :
//      sudo -u www-data php tools/historique_optoplate.php            (simulation)
//      sudo -u www-data php tools/historique_optoplate.php --appliquer
//  Options : --du=AAAA-MM-JJ  --au=AAAA-MM-JJ  --site=ID  --refaire
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('CLI_MODE', true);
ini_set('memory_limit', '1G');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/optoplate_comparaison.php';

const TYPE_HISTORIQUE = 'historique';

$o = getopt('', ['du:', 'au:', 'site:', 'appliquer', 'refaire']);
$appliquer = isset($o['appliquer']);
$refaire   = isset($o['refaire']);
$site_seul = isset($o['site']) ? (int)$o['site'] : 0;
$date_ok   = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

foreach (['du', 'au'] as $k) {
    if (isset($o[$k]) && !$date_ok($o[$k])) { fwrite(STDERR, "Option --$k : date attendue au format AAAA-MM-JJ.\n"); exit(1); }
}

// Les colonnes de désactivation et de réimpression doivent exister.
foreach ([['import_optoplate', 'type_desactivation'], ['import_optoplate', 'categorie'],
          ['op_points_journaliers', 'nb_vp_duplicata'], ['op_points_journaliers', 'total_engins_postimmat']] as [$t, $c]) {
    if (!db_colonne_existe($t, $c)) {
        fwrite(STDERR, "Colonne $t.$c absente : jouer d'abord les migrations OptoPlate et Re-Immatriculation.\n");
        exit(1);
    }
}

$premiere = db_fetch_value("SELECT DATE(MIN(date_installation)) FROM import_optoplate WHERE site_id IS NOT NULL");
if (!$premiere) {
    echo "Aucune plaque OptoPlate importée : importer d'abord l'export OptoPlate (Import EMUCI).\n";
    exit(0);
}
$du = $o['du'] ?? $premiere;
$au = $o['au'] ?? date('Y-m-d', strtotime('-1 day'));   // la journée en cours n'est pas terminée
if ($du > $au) { fwrite(STDERR, "Période vide : $du est après $au.\n"); exit(1); }

echo ($appliquer ? "APPLICATION" : "SIMULATION (rien n'est écrit)") . " : du $du au $au"
   . ($site_seul ? ", site n°$site_seul" : ', tous les sites') . ($refaire ? ', jours reconstitués refaits' : '') . "\n\n";

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
                if (isset($hist[$site_id . '|' . $jour])) {   // --refaire : on remplace le jour reconstitué
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
        fwrite(STDERR, "Site n°$site_id : erreur, rien n'est écrit pour ce site. " . $e->getMessage() . "\n");
        exit(1);
    }
}

// ── Compte rendu
if (!$stats['points']) {
    echo "Rien à " . ($appliquer ? 'écrire' : 'reconstituer') . " : "
       . "{$stats['ignores_reels']} jour(s) ont déjà un point dans l'ERP, {$stats['ignores_hist']} déjà reconstitué(s).\n";
    exit(0);
}
echo str_pad('Mois', 9) . str_pad('Points', 9) . str_pad('Engins', 10) . "Plaques\n";
ksort($par_mois);
foreach ($par_mois as $m => $v) {
    echo str_pad($m, 9) . str_pad($v['points'], 9) . str_pad($v['engins'], 10) . $v['plaques'] . "\n";
}
echo str_pad('TOTAL', 9) . str_pad($stats['points'], 9) . str_pad($stats['engins'], 10) . $stats['plaques'] . "\n\n";

echo "Par site :\n";
foreach ($par_site as $id => $v) {
    printf("  %-30s %4d jours  %6d engins  dont %d duplicata/post-immat\n", $sites[$id] ?? "site n°$id", $v['points'], $v['engins'], $v['dup_pi']);
}
echo "\n";
if ($stats['ignores_reels']) echo "Jours ignorés, déjà saisis dans l'ERP : {$stats['ignores_reels']}\n";
if ($stats['ignores_hist'])  echo "Jours ignorés, déjà reconstitués (utiliser --refaire pour les recréer) : {$stats['ignores_hist']}\n";
if ($refaire && $stats['refaits']) echo "Jours reconstitués puis refaits : {$stats['refaits']}\n";
if ($sans_site)              echo "ATTENTION : $sans_site plaque(s) d'un site inconnu ne sont dans aucun point (créer le site dans l'écran Sites puis réimporter).\n";
if ($stats['vehicules_autre']) echo "ATTENTION : {$stats['vehicules_autre']} véhicule(s) de catégorie inconnue comptés en VP.\n";
if ($stats['plaques_sans_bobine']) {
    $pct = round($stats['plaques_sans_bobine'] / max(1, $stats['plaques_total']) * 100);
    arsort($bobines_inconnues);
    echo "Films : {$stats['plaques_sans_bobine']} plaque(s) sur {$stats['plaques_total']} ($pct %) sur des bobines absentes de l'ERP : "
       . "les films par bobine du dashboard seront en dessous des engins.\n  Bobines les plus concernées : "
       . implode(', ', array_map(fn($n, $v) => "$n ($v)", array_keys(array_slice($bobines_inconnues, 0, 5, true)), array_slice($bobines_inconnues, 0, 5, true))) . "\n";
}
if ($appliquer) {
    audit_log(null, 'CREATE', 'operations', 0,
        "Reconstitution historique OptoPlate $du au $au : {$stats['points']} point(s), {$stats['engins']} engins");
    echo "\nTerminé : {$stats['points']} point(s) historique(s) écrits. Aucun stock modifié.\n";
} else {
    echo "\nSimulation terminée, rien n'a été écrit. Pour appliquer : relancer avec --appliquer.\n";
}
