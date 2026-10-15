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
        'MOTO' => ['motocyclette', 'moto'],
    ];
    foreach ($map as $type => $labels) {
        foreach ($labels as $l) {
            if ($c === $l || str_contains($c, $l)) return $type;
        }
    }
    return 'AUTRE';
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
 *  - par_bobine  : [num_bobine => n] — plaques posées du jour.
 *  - total_jour  : nombre de poses du jour (avant classement).
 */
function op_calc_jour_site(int $site_id, string $date): array {
    $poses = db_fetch_all(
        "SELECT id, immatriculation, vin, num_consommable, num_bobine, categorie
         FROM import_optoplate
         WHERE site_id=? AND statut_plaque='in_use' AND date_installation::date=?",
        [$site_id, $date]
    );

    $primo      = ['VP' => ['vehicules' => 0, 'plaques' => 0], 'CAM' => ['vehicules' => 0, 'plaques' => 0],
                    'SEMI' => ['vehicules' => 0, 'plaques' => 0], 'MOTO' => ['vehicules' => 0, 'plaques' => 0],
                    'AUTRE' => ['vehicules' => 0, 'plaques' => 0]];
    $duplicata  = ['vehicules' => 0, 'plaques' => 0];
    $post_immat = ['vehicules' => 0, 'plaques' => 0];
    $par_bobine = [];

    if (!empty($poses)) {
        // Véhicules (immat/VIN) du site ayant une désactivation Duplicata ou
        // Modification POST antérieure au jour comparé — peu importe quand.
        $desact = db_fetch_all(
            "SELECT DISTINCT immatriculation, vin, type_desactivation
             FROM import_optoplate
             WHERE site_id=? AND type_desactivation IN ('Duplicata','Modification POST')
               AND date_desactivation < ?",
            [$site_id, $date]
        );
        $dup_vehicules = []; $pi_vehicules = [];
        foreach ($desact as $d) {
            $cles = array_filter([trim((string)$d['immatriculation']), trim((string)$d['vin'])]);
            foreach ($cles as $cle) {
                if ($d['type_desactivation'] === 'Duplicata') $dup_vehicules[$cle] = true;
                else $pi_vehicules[$cle] = true;
            }
        }

        // Un véhicule compte une fois (son nombre de plaques posées ce
        // jour), pas une fois par plaque — regroupement par immat/VIN.
        $vehicules_jour = [];
        foreach ($poses as $p) {
            $cle = trim((string)$p['immatriculation']) ?: trim((string)$p['vin']) ?: ('#' . $p['id']);
            $vehicules_jour[$cle]['nb_plaques'] = ($vehicules_jour[$cle]['nb_plaques'] ?? 0) + 1;
            $vehicules_jour[$cle]['categorie']  = $p['categorie'] ?? ($vehicules_jour[$cle]['categorie'] ?? null);

            $nb_b = (string)($p['num_bobine'] ?: '—');
            $par_bobine[$nb_b] = ($par_bobine[$nb_b] ?? 0) + 1;
        }

        foreach ($vehicules_jour as $cle => $v) {
            if (isset($dup_vehicules[$cle])) {
                $duplicata['vehicules']++;
                $duplicata['plaques'] += $v['nb_plaques'];
            } elseif (isset($pi_vehicules[$cle])) {
                $post_immat['vehicules']++;
                $post_immat['plaques'] += $v['nb_plaques'];
            } else {
                $type = op_categorie_vers_type($v['categorie']);
                $primo[$type]['vehicules']++;
                $primo[$type]['plaques'] += $v['nb_plaques'];
            }
        }
    }

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

    // Films par bobine : total déclaré par le coordinateur (utilisés +
    // endommagés, comme pour le reste du point) vs plaques posées du jour
    // comptées par OptoPlate sur le même n° de bobine.
    $coord_bobine_rows = db_fetch_all(
        "SELECT b.numero, SUM(fu.films_utilises + fu.films_endommages) AS n
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
        'post_immat'  => ['coordinateur' => $coord_pi,  'optoplate' => $opt_pi,  'ecart' => $ecart_pi],
        'endommages'  => $endommages,
        'par_bobine'  => $par_bobine,
        'total_optoplate_jour' => $opto['total_jour'],
        'has_ecart'   => $has_ecart,
    ];
}
