<?php
// ============================================================
//  includes/optoplate_comparaison.php
//  Plan comparaison OptoPlate avant validation superviseur (15/10/2026).
//  Calcule, pour un site et une date, ce qu'OptoPlate a réellement
//  enregistré — à comparer au point 18h du coordinateur avant que le
//  superviseur ne le valide.
// ============================================================
require_once __DIR__ . '/db.php';

/** Catégorie OptoPlate brute → type de véhicule du point journalier. */
function op_categorie_vers_type(?string $categorie): string {
    $c = mb_strtolower(trim((string)$categorie));
    if ($c === '') return 'AUTRE';
    $map = [
        'VP'   => ['voiture particulière', 'voiture particuliere', 'camionnette', 'véhicule utilitaire', 'vehicule utilitaire', 'autocar', 'bus', 'autocar-bus'],
        'CAM'  => ['camion', 'tracteur routier'],
        'SEMI' => ['semi-remorque routier', 'semi-remorque', 'semi remorque routier'],
        // Tricycles, quadricycles et scooters : déclarés en Moto par le coordinateur.
        'MOTO' => ['motocyclette', 'moto', 'tricycle', 'quadricycle', 'scooter'],
    ];
    foreach ($map as $type => $labels) {
        foreach ($labels as $l) {
            if ($c === $l || str_contains($c, $l)) return $type;
        }
    }
    return 'AUTRE';
}

/**
 * Classe les poses d'une journée de site : Primo (ou ré-immat) par type de
 * véhicule, réimpressions Duplicata et Post-Immat, plaques par bobine.
 * Fonction pure, partagée par la comparaison du superviseur
 * (op_calc_jour_site) et par la reconstitution de l'historique : une seule
 * règle de classement pour les deux.
 *  $poses         : lignes d'import (id, immatriculation, vin, num_bobine,
 *                   categorie) des plaques posées ce jour-là ;
 *  $type_vehicule : [clé immat/VIN => 'Duplicata'|'Modification POST'], la
 *                   dernière désactivation connue de chaque véhicule À CE
 *                   JOUR (désactivation et réimpression peuvent tomber le
 *                   même jour).
 * Un véhicule compte une fois, quel que soit son nombre de plaques posées.
 */
function op_classer_poses(array $poses, array $type_vehicule): array {
    $zero = fn() => ['vehicules' => 0, 'plaques' => 0];
    $types = ['VP', 'CAM', 'SEMI', 'MOTO', 'AUTRE'];
    $primo = []; $dup_par_type = []; $pi_par_type = [];
    foreach ($types as $t) { $primo[$t] = $zero(); $dup_par_type[$t] = $zero(); $pi_par_type[$t] = $zero(); }
    $duplicata = $zero(); $post_immat = $zero(); $par_bobine = [];

    $vehicules_jour = [];
    foreach ($poses as $p) {
        $cle = trim((string)$p['immatriculation']) ?: trim((string)$p['vin']) ?: ('#' . $p['id']);
        $vehicules_jour[$cle]['nb_plaques'] = ($vehicules_jour[$cle]['nb_plaques'] ?? 0) + 1;
        $vehicules_jour[$cle]['categorie']  = $p['categorie'] ?? ($vehicules_jour[$cle]['categorie'] ?? null);

        $nb_b = (string)($p['num_bobine'] ?: '—');
        $par_bobine[$nb_b] = ($par_bobine[$nb_b] ?? 0) + 1;
    }

    foreach ($vehicules_jour as $cle => $v) {
        $type = op_categorie_vers_type($v['categorie']);
        $type_desact = $type_vehicule[$cle] ?? null;
        if ($type_desact === 'Duplicata') {
            $duplicata['vehicules']++;
            $duplicata['plaques'] += $v['nb_plaques'];
            $dup_par_type[$type]['vehicules']++;
            $dup_par_type[$type]['plaques'] += $v['nb_plaques'];
        } elseif ($type_desact === 'Modification POST') {
            $post_immat['vehicules']++;
            $post_immat['plaques'] += $v['nb_plaques'];
            $pi_par_type[$type]['vehicules']++;
            $pi_par_type[$type]['plaques'] += $v['nb_plaques'];
        } else {
            $primo[$type]['vehicules']++;
            $primo[$type]['plaques'] += $v['nb_plaques'];
        }
    }
    return ['primo' => $primo, 'duplicata' => $duplicata, 'post_immat' => $post_immat,
            'par_bobine' => $par_bobine,
            'duplicata_par_type' => $dup_par_type, 'post_immat_par_type' => $pi_par_type];
}

/**
 * Films imprimés par jour et par bobine sur un site : [jour => [num_bobine => n]].
 * Un film est consommé le jour où sa plaque est imprimée, posée ou non :
 * une plaque « reserved » (imprimée, en attente de pose) compte ici le jour
 * de l'impression, puis ne compte plus quand elle passe « in_use » — elle
 * ne compte alors que comme engin posé. Les incidents de production restent
 * à part (endommagés). Retourne null tant que la migration
 * migration_optoplate_date_impression n'est pas passée : l'appelant garde
 * alors l'ancien calcul (films = plaques posées du jour).
 */
function op_films_imprimes(int $site_id, string $du, string $au): ?array {
    static $colonne = null;
    if ($colonne === null) $colonne = db_colonne_existe('import_optoplate', 'date_impression');
    if (!$colonne) return null;
    $r = [];
    foreach (db_fetch_all(
        "SELECT date_impression AS jour, num_bobine, COUNT(*) AS n
         FROM import_optoplate
         WHERE site_id=? AND date_impression BETWEEN ? AND ?
           AND (statut_plaque IN ('in_use','reserved','in_use_need_reprint')
                OR type_desactivation IN ('Duplicata','Modification POST'))
         GROUP BY date_impression, num_bobine", [$site_id, $du, $au]) as $f) {
        $r[substr((string)$f['jour'], 0, 10)][(string)($f['num_bobine'] ?: '—')] = (int)$f['n'];
    }
    return $r;
}

/**
 * Calcule, pour un site et une date (jour calendaire, 0h-23h59), ce
 * qu'OptoPlate a enregistré :
 *  - primo       : ['VP'=>['vehicules'=>n,'plaques'=>n], 'CAM'=>...] — poses
 *                   du jour qui ne sont ni une réimpression Duplicata ni
 *                   Post-Immat.
 *  - duplicata   : ['vehicules'=>n,'plaques'=>n] — poses du jour d'un
 *                   véhicule (immat/VIN) dont une plaque a été désactivée
 *                   en Duplicata, à n'importe quelle date antérieure.
 *  - post_immat  : idem, désactivation 'Modification POST'.
 *  - endommages  : [motif => n] — désactivations DU JOUR en 'Incident'.
 *  - par_bobine  : [num_bobine => n] — films imprimés du jour (plaques
 *                   posées ou encore « reserved », cf. op_films_imprimes).
 *  - total_jour  : nombre de poses du jour (avant classement).
 */
function op_calc_jour_site(int $site_id, string $date): array {
    // Poses du jour : plaques en service, ou remplacées depuis par un
    // duplicata / une post-immat (elles étaient bien posées ce jour-là —
    // l'import met une plaque à jour dans le temps). Ni les plaques
    // « reserved » (pas posées), ni les incidents de production (comptés en
    // endommagés). MySQL : plage horaire plutôt que ::date (PostgreSQL).
    $poses = db_fetch_all(
        "SELECT id, immatriculation, vin, num_consommable, num_bobine, categorie
         FROM import_optoplate
         WHERE site_id=? AND date_installation >= ? AND date_installation < DATE_ADD(?, INTERVAL 1 DAY)
           AND (statut_plaque='in_use' OR type_desactivation IN ('Duplicata','Modification POST'))",
        [$site_id, $date, $date]
    );

    $primo      = ['VP' => ['vehicules' => 0, 'plaques' => 0], 'CAM' => ['vehicules' => 0, 'plaques' => 0],
                    'SEMI' => ['vehicules' => 0, 'plaques' => 0], 'MOTO' => ['vehicules' => 0, 'plaques' => 0],
                    'AUTRE' => ['vehicules' => 0, 'plaques' => 0]];
    $duplicata  = ['vehicules' => 0, 'plaques' => 0];
    $post_immat = ['vehicules' => 0, 'plaques' => 0];
    $par_bobine = [];
    $dup_par_type = []; $pi_par_type = [];

    if (!empty($poses)) {
        // Véhicules (immat/VIN) du site ayant une désactivation Duplicata ou
        // Modification POST jusqu'au jour comparé COMPRIS — désactivation et
        // réimpression peuvent tomber le même jour (« < » les comptait en
        // Primo). Lues de la plus ancienne à la plus récente : la dernière
        // désactivation décide du type (un duplicata d'il y a trois mois ne
        // fait pas d'une post-immat d'aujourd'hui un duplicata).
        $desact = db_fetch_all(
            "SELECT immatriculation, vin, type_desactivation
             FROM import_optoplate
             WHERE site_id=? AND type_desactivation IN ('Duplicata','Modification POST')
               AND date_desactivation <= ?
             ORDER BY date_desactivation, id",
            [$site_id, $date]
        );
        $type_vehicule = [];
        foreach ($desact as $d) {
            foreach (array_filter([trim((string)$d['immatriculation']), trim((string)$d['vin'])]) as $cle) {
                $type_vehicule[$cle] = $d['type_desactivation'];
            }
        }

        $classe = op_classer_poses($poses, $type_vehicule);
        $primo      = $classe['primo'];
        $duplicata  = $classe['duplicata'];
        $post_immat = $classe['post_immat'];
        $par_bobine = $classe['par_bobine'];
        $dup_par_type = $classe['duplicata_par_type'];
        $pi_par_type  = $classe['post_immat_par_type'];
    }
    // Films : jour d'impression (une plaque reserved compte aujourd'hui en film,
    // demain en engin posé seulement). Sans la migration : plaques posées du jour.
    $imprimes = op_films_imprimes($site_id, $date, $date);
    if ($imprimes !== null) $par_bobine = $imprimes[$date] ?? [];

    // Endommagés : désactivations DU JOUR en Incident, par motif.
    $endo_rows = db_fetch_all(
        "SELECT motif_desactivation, COUNT(*) AS n
         FROM import_optoplate
         WHERE site_id=? AND type_desactivation='Incident' AND date_desactivation=?
         GROUP BY motif_desactivation",
        [$site_id, $date]
    );
    $endommages = [];
    foreach ($endo_rows as $e) {
        $endommages[$e['motif_desactivation'] ?: 'Non précisé'] = (int)$e['n'];
    }

    return [
        'primo'      => $primo,
        'duplicata'  => $duplicata,
        'post_immat' => $post_immat,
        'endommages' => $endommages,
        'par_bobine' => $par_bobine,
        'total_jour' => count($poses),
        // Répartition par type de véhicule des réimpressions (reconstitution
        // de l'historique, tools/historique_optoplate.php).
        'duplicata_par_type'  => $dup_par_type,
        'post_immat_par_type' => $pi_par_type,
    ];
}

/** Motif de désactivation Incident (OptoPlate) → étape de déclaration film. */
function op_motif_vers_etape(string $motif): string {
    $m = mb_strtolower($motif);
    foreach (['verification' => 'vérification', 'impression' => 'impression', 'lamination' => 'lamination',
              'percee' => 'percée', 'fissuree' => 'fissur'] as $etape => $mot) {
        if (str_contains($m, $mot)) return $etape;
    }
    return 'autre';
}

/**
 * Compare un point 18h (ligne complète op_points_journaliers) à ce
 * qu'OptoPlate a enregistré pour le même site/date. Retourne les lignes
 * de comparaison (Primo par type, Duplicatas, Post-Immat, endommagés par
 * étape) et un indicateur global has_ecart (tolérance 0, plan 15/10/2026).
 */
function op_comparer_point(array $point): array {
    $site_id = (int)$point['site_id'];
    $date    = $point['date_point'];
    $opto    = op_calc_jour_site($site_id, $date);

    // code OptoPlate/interne => [colonne du point, libellé]
    $types_lbl = [
        'VP'   => ['nb_vp',     'Véhicule Particulier'],
        'CAM'  => ['nb_camion', 'Camion'],
        'SEMI' => ['nb_semi',   'Semi-remorque'],
        'MOTO' => ['nb_moto',   'Moto'],
    ];
    $has_ecart = false;

    $primo = [];
    foreach ($types_lbl as $code => [$col, $lbl]) {
        $coord = (int)($point[$col] ?? 0);
        $opt   = (int)($opto['primo'][$code]['vehicules'] ?? 0);
        $ecart = $coord - $opt;
        if ($ecart !== 0) $has_ecart = true;
        $primo[] = ['code' => $code, 'libelle' => $lbl, 'coordinateur' => $coord, 'optoplate' => $opt, 'ecart' => $ecart];
    }

    $coord_dup = (int)($point['nb_vp_duplicata'] ?? 0) + (int)($point['nb_camion_duplicata'] ?? 0)
               + (int)($point['nb_semi_duplicata'] ?? 0) + (int)($point['nb_moto_duplicata'] ?? 0);
    $opt_dup   = (int)($opto['duplicata']['vehicules'] ?? 0);
    $ecart_dup = $coord_dup - $opt_dup;
    if ($ecart_dup !== 0) $has_ecart = true;
    // Plaques des duplicatas : un duplicata VP / camion peut n'en remplacer
    // qu'une (saisie du coordinateur, sql/migration_duplicata_plaques*.sql).
    // Comparées seulement quand le point les a saisies : un point antérieur
    // porte le forfait de 2, qui n'est pas une déclaration.
    $dup_plaques = null;
    if (array_key_exists('plaques_vp_duplicata', $point) && $point['plaques_vp_duplicata'] !== null) {
        $coord_dup_plq = (int)($point['total_plaques_duplicata'] ?? 0);
        $opt_dup_plq   = (int)($opto['duplicata']['plaques'] ?? 0);
        $dup_plaques = ['coordinateur' => $coord_dup_plq, 'optoplate' => $opt_dup_plq, 'ecart' => $coord_dup_plq - $opt_dup_plq];
        if ($dup_plaques['ecart'] !== 0) $has_ecart = true;
    }

    $coord_pi = (int)($point['nb_vp_postimmat'] ?? 0) + (int)($point['nb_camion_postimmat'] ?? 0)
              + (int)($point['nb_semi_postimmat'] ?? 0) + (int)($point['nb_moto_postimmat'] ?? 0);
    $opt_pi   = (int)($opto['post_immat']['vehicules'] ?? 0);
    $ecart_pi = $coord_pi - $opt_pi;
    if ($ecart_pi !== 0) $has_ecart = true;

    // Endommagés — déclarations film du point, comparées étape par étape
    // aux désactivations Incident du même jour (motif → étape).
    $coord_endo_rows = db_fetch_all(
        "SELECT etape, COUNT(*) AS n FROM op_endommagements
         WHERE point_id=? AND (objet='film' OR objet IS NULL) GROUP BY etape",
        [(int)$point['id']]
    );
    $coord_endo = [];
    foreach ($coord_endo_rows as $r) $coord_endo[$r['etape']] = (int)$r['n'];
    $opt_endo = [];
    foreach ($opto['endommages'] as $motif => $n) $opt_endo[op_motif_vers_etape($motif)] = ($opt_endo[op_motif_vers_etape($motif)] ?? 0) + $n;

    $etapes_comparees = ['verification','impression','lamination','percee','fissuree'];
    $endommages = [];
    foreach ($etapes_comparees as $et) {
        $c = $coord_endo[$et] ?? 0;
        $o = $opt_endo[$et] ?? 0;
        if ($c === 0 && $o === 0) continue;
        $ecart = $c - $o;
        if ($ecart !== 0) $has_ecart = true;
        $endommages[] = ['etape' => $et, 'coordinateur' => $c, 'optoplate' => $o, 'ecart' => $ecart];
    }

    // Films par bobine : films UTILISÉS déclarés par le coordinateur vs
    // films imprimés du jour comptés par OptoPlate (posés ou « reserved »)
    // sur le même n° de bobine. Les films endommagés correspondent aux incidents OptoPlate
    // (plaques jamais posées), comparés à part étape par étape : les
    // ajouter ici créait un écart à chaque film endommagé.
    $coord_bobine_rows = db_fetch_all(
        "SELECT b.numero, SUM(fu.films_utilises) AS n
         FROM op_films_utilises fu JOIN op_bobines b ON b.id = fu.bobine_id
         WHERE fu.point_id=? GROUP BY b.numero",
        [(int)$point['id']]
    );
    $coord_bobine = [];
    foreach ($coord_bobine_rows as $r) $coord_bobine[$r['numero']] = (int)$r['n'];
    $par_bobine = [];
    $bobines_vues = array_unique(array_merge(array_keys($coord_bobine), array_keys($opto['par_bobine'])));
    sort($bobines_vues);
    foreach ($bobines_vues as $num) {
        $c = $coord_bobine[$num] ?? 0;
        $o = $opto['par_bobine'][$num] ?? 0;
        $ecart = $c - $o;
        if ($ecart !== 0) $has_ecart = true;
        $par_bobine[] = ['numero' => $num, 'coordinateur' => $c, 'optoplate' => $o, 'ecart' => $ecart];
    }

    return [
        'primo'       => $primo,
        'duplicata'   => ['coordinateur' => $coord_dup, 'optoplate' => $opt_dup, 'ecart' => $ecart_dup],
        'duplicata_plaques' => $dup_plaques,
        'post_immat'  => ['coordinateur' => $coord_pi,  'optoplate' => $opt_pi,  'ecart' => $ecart_pi],
        'endommages'  => $endommages,
        'par_bobine'  => $par_bobine,
        'total_optoplate_jour' => $opto['total_jour'],
        'has_ecart'   => $has_ecart,
    ];
}
