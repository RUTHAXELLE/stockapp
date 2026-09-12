<?php
// ============================================================
//  includes/consommation.php
//  Socle de calcul de la consommation moyenne de films.
//
//  Phase 3, tache 3.0 du plan PDG. La meme formule etait recopiee a
//  l'identique dans includes/inventaire.php et pages/inventaire_bobines.php ;
//  les modules KPI (n° 2.2) et Simulation (n° 2.6) en ont besoin a leur
//  tour. Trois copies auraient fini par diverger, et un ecart de calcul
//  entre l'inventaire et la projection serait invisible et couteux.
//
//  ── Semantique de la moyenne, a connaitre avant de s'en servir ──
//  Le denominateur n'est pas la fenetre (30 jours) mais le nombre de
//  jours ecoules depuis la PREMIERE consommation observee dans cette
//  fenetre. Une bobine ouverte il y a 5 jours est donc divisee par 5,
//  pas par 30 : c'est une moyenne "par jour d'activite depuis
//  l'ouverture", pas une moyenne lissee sur la periode.
//
//  Ce choix vient du code d'origine (calcul des jours restants a
//  l'inventaire) et il est conserve tel quel : le modifier changerait
//  silencieusement les projections deja affichees dans les inventaires.
//  Les nouveaux ecrans s'y alignent donc plutot que d'inventer une
//  seconde definition de la meme grandeur.
//
//  ── Un defaut corrige au passage : la division etait entiere ──
//  SUM(quantite) et l'ecart de dates sont deux entiers : PostgreSQL
//  faisait donc une division ENTIERE. 200 films sur 9 jours donnaient
//  22 et non 22,22 ; surtout, 5 films sur 9 jours donnaient 0, soit
//  une consommation declaree nulle alors qu'elle est reelle — et donc
//  aucun "jours restants" affiche pour cette bobine.
//
//  L'erreur allait toujours dans le meme sens : consommation
//  sous-estimee, donc autonomie surestimee. Le cast ::numeric la
//  corrige. Consequence assumee : les jours restants affiches dans les
//  inventaires deviennent legerement plus courts, parce que plus
//  justes. Les valeurs conso_quotidienne_moy deja enregistrees dans
//  inventaire_details_bobines gardent leur ancien chiffre tronque :
//  seuls les inventaires ouverts apres cette correction en beneficient.
// ============================================================

require_once __DIR__ . '/referentiels.php';   // libelles format / version

/**
 * Consommation moyenne journaliere d'une bobine, en films par jour.
 */
function conso_moy_bobine(int $bobine_id, int $jours = 30): float {
    return (float) db_fetch_value(
        "SELECT COALESCE(SUM(quantite)::numeric / GREATEST(((NOW())::date - (MIN(date_conso)::date)), 1), 0)
           FROM consommations_bobines
          WHERE bobine_id = ?
            AND date_conso >= (CURRENT_DATE - (? || ' DAY')::interval)",
        [$bobine_id, $jours]
    );
}

/**
 * Consommation moyenne journaliere d'un site, en films par jour.
 * site_id = 0 : tous les sites confondus.
 */
function conso_moy_site(int $site_id = 0, int $jours = 30): float {
    $filtre = $site_id ? "AND site_id = ?" : "";
    $params = $site_id ? [$jours, $site_id] : [$jours];
    return (float) db_fetch_value(
        "SELECT COALESCE(SUM(quantite)::numeric / GREATEST(((NOW())::date - (MIN(date_conso)::date)), 1), 0)
           FROM consommations_bobines
          WHERE date_conso >= (CURRENT_DATE - (? || ' DAY')::interval)
            $filtre",
        $params
    );
}

/**
 * Consommation moyenne journaliere par site, en films par jour.
 * Retourne site_id => ['nom' => ..., 'conso' => float].
 *
 * Une seule requete plutot qu'un appel a conso_moy_site() par site :
 * l'ecran de simulation liste tous les sites actifs, ce qui ferait
 * autant d'allers-retours pour une grandeur qu'un GROUP BY suffit a
 * produire.
 */
function conso_moy_par_site(int $jours = 30): array {
    $rows = db_fetch_all(
        "SELECT s.id, s.nom,
                COALESCE(SUM(c.quantite)::numeric / GREATEST(((NOW())::date - (MIN(c.date_conso)::date)), 1), 0) AS conso
           FROM sites s
           LEFT JOIN consommations_bobines c
                  ON c.site_id = s.id
                 AND c.date_conso >= (CURRENT_DATE - (? || ' DAY')::interval)
          WHERE s.actif = 1
          GROUP BY s.id, s.nom
          ORDER BY s.nom",
        [$jours]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = ['nom' => $r['nom'], 'conso' => (float)$r['conso']];
    }
    return $out;
}

/**
 * Consommation moyenne journaliere PAR FORMAT de bobine, en films/jour,
 * accompagnee du stock et du conditionnement de chaque format.
 *
 * Une bobine n'est pas substituable a une autre : op_types_vehicule
 * relie chaque type de vehicule a une serie de bobine, donc un stock
 * global peut afficher des semaines d'autonomie alors que le format
 * moto est deja epuise et que cette production s'arrete. La projection
 * doit donc se faire format par format, et la contrainte est celle qui
 * arrive a echeance en premier.
 *
 * Retourne type_code => [serie, films_restants, bobines, films_par_bobine,
 *                        conso, jours, date_epuisement]
 */
function conso_stock_par_format(int $site_id = 0, int $jours = 30): array {
    $f_b = $site_id ? "AND b.site_id = $site_id" : "";

    $stock = db_fetch_all(
        "SELECT b.type_code, MIN(b.serie) AS serie,
                COALESCE(SUM(b.films_restants),0) AS films,
                COUNT(*) AS nb_bobines,
                COALESCE(AVG(NULLIF(b.qte_initiale,0)),500) AS fpb
           FROM op_bobines b
          WHERE b.statut IN ('en_stock','en_cours') AND b.type_code IS NOT NULL $f_b
          GROUP BY b.type_code");

    // Conditionnement declare au catalogue, quand il existe : c'est la
    // valeur de reference, la moyenne des qte_initiale n'en etait qu'une
    // estimation. Un parc melangeant des bobines entamees avant la mise en
    // place du referentiel produisait un "films par bobine" ne correspondant
    // a aucune bobine reelle.
    //
    // La lecture est isolee dans un try : le code peut etre deploye avant
    // que la migration ne soit passee sur la base, et la simulation doit
    // continuer de fonctionner avec l'ancienne estimation en attendant.
    //
    // Le catalogue fournit aussi le format et la version en clair. Les
    // ecrans montraient jusqu'ici le code type (A001) et la serie (A), qui
    // ne disent rien a un lecteur non initie ; "Auto" et "Privee" portent
    // la meme information de facon lisible.
    $catalogue = [];
    try {
        foreach (db_fetch_all(
            "SELECT UPPER(code) AS c, films_par_bobine, format, version FROM op_types_bobines") as $t) {
            $catalogue[$t['c']] = [
                'fpb'     => (int)$t['films_par_bobine'] > 0 ? (int)$t['films_par_bobine'] : null,
                'format'  => $t['format']  ?: null,
                'version' => $t['version'] ?: null,
            ];
        }
    } catch (Throwable $e) {
        // Colonnes format/version absentes (migration anterieure non
        // passee) : on retente sans elles avant de renoncer.
        try {
            foreach (db_fetch_all(
                "SELECT UPPER(code) AS c, films_par_bobine FROM op_types_bobines") as $t) {
                $catalogue[$t['c']] = [
                    'fpb'     => (int)$t['films_par_bobine'] > 0 ? (int)$t['films_par_bobine'] : null,
                    'format'  => null,
                    'version' => null,
                ];
            }
        } catch (Throwable $e2) {
            // Table ou colonne absente : estimation par le parc, libelles
            // deduits de la serie et du code.
        }
    }

    $conso = db_fetch_all(
        "SELECT b.type_code,
                COALESCE(SUM(c.quantite)::numeric
                         / GREATEST(((NOW())::date - (MIN(c.date_conso)::date)), 1), 0) AS conso
           FROM consommations_bobines c
           JOIN op_bobines b ON b.id = c.bobine_id
          WHERE c.date_conso >= (CURRENT_DATE - (? || ' DAY')::interval)
            AND b.type_code IS NOT NULL $f_b
          GROUP BY b.type_code", [$jours]);
    $par_conso = [];
    foreach ($conso as $c) $par_conso[$c['type_code']] = (float)$c['conso'];

    $out = [];
    foreach ($stock as $s) {
        $code = $s['type_code'];
        $c    = $par_conso[$code] ?? 0.0;
        $f    = (int)$s['films'];
        $j    = $c > 0 ? (int) floor($f / $c) : null;
        $cat  = $catalogue[strtoupper($code)] ?? [];
        $out[$code] = [
            'serie'            => $s['serie'],
            // A/B/C/D sont des bobines de films, TL et WSL des vignettes :
            // deux articles distincts, que l'application separe deja dans
            // ses ecrans de gestion. Un ecran qui l'ignore finit par
            // annoncer des "bobines WSL", qui n'existent pas.
            'categorie'        => categorie_serie($s['serie']),
            // Un type absent du catalogue garde des libelles deduits de sa
            // serie et de son code, plutot qu'une ligne vide.
            'format'           => $cat['format']  ?? libelle_format_serie($s['serie']),
            'version'          => $cat['version'] ?? libelle_version_code($code),
            'films_restants'   => $f,
            'bobines'          => (int)$s['nb_bobines'],
            'films_par_bobine' => $cat['fpb']
                                  ?? max(1, (int) round((float)$s['fpb'])),
            'conso'            => $c,
            'jours'            => $j,
            'date_epuisement'  => $j !== null ? date('Y-m-d', strtotime("+$j days")) : null,
        ];
    }
    ksort($out);
    return $out;
}

/**
 * Meme logique pour le PMMA : stock et consommation par type, un type
 * ne remplacant pas un autre. La consommation vient des points
 * journaliers (op_pmma_utilises), le stock de stock_pmma_site.
 */
function conso_stock_par_pmma(int $site_id = 0, int $jours = 30): array {
    $f_s = $site_id ? "AND sp.site_id = $site_id" : "";
    $f_p = $site_id ? "AND p.site_id = $site_id"  : "";

    $stock = db_fetch_all(
        "SELECT sp.type_pmma,
                COALESCE(SUM(sp.quantite),0)                AS qte,
                COALESCE(MIN(sp.seuil_alerte),10)           AS seuil
           FROM stock_pmma_site sp WHERE 1=1 $f_s
          GROUP BY sp.type_pmma");

    // Denominateur aligne sur les autres fonctions : jours ecoules depuis
    // la premiere consommation observee dans la fenetre.
    $conso = db_fetch_all(
        "SELECT pu.type_pmma,
                COALESCE(SUM(pu.utilises)::numeric
                         / GREATEST(((NOW())::date - (MIN(p.date_point)::date)), 1), 0) AS conso
           FROM op_pmma_utilises pu
           JOIN op_points_journaliers p ON p.id = pu.point_id
          WHERE p.date_point >= (CURRENT_DATE - (? || ' DAY')::interval)
            AND p.statut <> 'brouillon' $f_p
          GROUP BY pu.type_pmma", [$jours]);
    $par_conso = [];
    foreach ($conso as $c) $par_conso[$c['type_pmma']] = (float)$c['conso'];

    $out = [];
    foreach ($stock as $s) {
        $t = $s['type_pmma'];
        $c = $par_conso[$t] ?? 0.0;
        $q = (int)$s['qte'];
        $j = $c > 0 ? (int) floor($q / $c) : null;
        $out[$t] = [
            'quantite'        => $q,
            'seuil'           => (int)$s['seuil'],
            'conso'           => $c,
            'jours'           => $j,
            'date_epuisement' => $j !== null ? date('Y-m-d', strtotime("+$j days")) : null,
        ];
    }
    ksort($out);
    return $out;
}

/**
 * Nombre moyen de films par bobine, pour convertir une quantite de
 * bobines en films. Le stock est suivi en films ; les demandes de
 * projection s'expriment en bobines.
 *
 * S'appuie sur qte_initiale des bobines reellement en service (500 ou
 * 2000 selon le format) plutot que sur une constante : le parc melange
 * les deux, et une valeur en dur fausserait la projection des le jour
 * ou la repartition change.
 */
function films_par_bobine_moyen(int $site_id = 0): int {
    $filtre = $site_id ? "AND site_id = ?" : "";
    $params = $site_id ? [$site_id] : [];
    $v = (float) db_fetch_value(
        "SELECT COALESCE(AVG(NULLIF(qte_initiale, 0)), 0)
           FROM op_bobines
          WHERE statut IN ('en_stock','en_cours') $filtre",
        $params
    );
    return $v > 0 ? (int) round($v) : 500;   // 500 = format standard
}
