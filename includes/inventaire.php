<?php
// ============================================================
//  includes/inventaire.php — Sessions & inventaire mensuel
//  Logique partagée entre l'ouverture de session (auto-provisionnement
//  de l'inventaire de chaque site, cf. pages/inventaire_sessions.php)
//  et pages/inventaire_bobines.php.
// ============================================================
require_once __DIR__ . '/db.php';

// ── Durée (en mois) de chaque type de période standard
const INV_PERIODES = ['mensuel' => 1, 'trimestriel' => 3, 'semestriel' => 6, 'annuel' => 12];

function inv_periode_label(string $p): string {
    return ['mensuel' => 'Mensuelle', 'trimestriel' => 'Trimestrielle',
            'semestriel' => 'Semestrielle', 'annuel' => 'Annuelle'][$p] ?? ucfirst($p);
}

// ── Date de fin déduite de la périodicité (jamais saisie librement)
function inv_date_fin(string $debut, string $typePeriode): string {
    $mois = INV_PERIODES[$typePeriode] ?? 1;
    return date('Y-m-d', strtotime("$debut +$mois months -1 day"));
}

// ── Libellé par défaut quand l'admin n'en saisit pas (colonne jamais vide)
function inv_libelle_auto(string $debut, string $typePeriode): string {
    $ts    = strtotime($debut);
    $annee = (int)date('Y', $ts);
    $mois  = (int)date('n', $ts);
    $mois_fr = [1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',
                7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
    switch ($typePeriode) {
        case 'mensuel':     $periode = $mois_fr[$mois] . ' ' . $annee; break;
        case 'trimestriel': $periode = 'T' . (int)ceil($mois / 3) . ' ' . $annee; break;
        case 'semestriel':  $periode = 'S' . ($mois <= 6 ? 1 : 2) . ' ' . $annee; break;
        case 'annuel':      $periode = (string)$annee; break;
        default:            $periode = $debut;
    }
    return 'Inventaire ' . mb_strtolower(inv_periode_label($typePeriode)) . ' — ' . $periode;
}

// ── Crée l'inventaire mensuel d'un site (+ ses lignes détail) et le
//    rattache à une session. Lève une Exception si impossible (déjà
//    existant, ou aucune bobine active sur le site).
function inv_creer_mensuel(int $site_id, string $date, ?int $session_id, int $user_id): array {
    $exist = db_fetch_one(
        "SELECT id FROM inventaires_bobines WHERE site_id=? AND date_inventaire=? AND type_inventaire='mensuel' AND statut!='annule'",
        [$site_id, $date]
    );
    if ($exist) throw new Exception("Un inventaire mensuel existe déjà pour ce site à cette date.");

    $bobines = db_fetch_all(
        "SELECT b.id, b.numero, b.type_code, b.serie, b.stock_systeme
         FROM op_bobines b
         WHERE b.site_id=? AND b.statut NOT IN ('retiree','epuisee')
         ORDER BY b.serie, b.type_code, b.numero",
        [$site_id]
    );
    if (empty($bobines)) throw new Exception('Aucune bobine active sur ce site.');

    $total_systeme = array_sum(array_column($bobines, 'stock_systeme'));
    try {
        $films_emuci = (int)db_fetch_value(
            "SELECT COALESCE(SUM(plaques_posees),0) FROM points_emuci WHERE site_id=? AND date_point=?",
            [$site_id, $date]
        );
    } catch (Exception $e) { $films_emuci = 0; }
    $films_digi = (int)db_fetch_value(
        "SELECT COALESCE(SUM(total_plaques),0) FROM op_points_journaliers WHERE site_id=? AND date_point=?",
        [$site_id, $date]
    );

    db_query(
        "INSERT INTO inventaires_bobines (site_id,date_inventaire,type_inventaire,statut,nb_bobines,total_films_systeme,total_films_emuci,ecart_digistock_emuci,cree_par,session_id)
         VALUES (?,?,'mensuel','brouillon',?,?,?,?,?,?)",
        [$site_id, $date, count($bobines), $total_systeme, $films_emuci, ($films_emuci - $films_digi), $user_id, $session_id]
    );
    $inv_id = (int)db_last_id();

    foreach ($bobines as $b) {
        $conso_moy = (float)db_fetch_value(
            "SELECT COALESCE(SUM(quantite)/GREATEST(((NOW())::date - (MIN(date_conso)::date)),1),0) FROM consommations_bobines WHERE bobine_id=? AND date_conso>=(CURRENT_DATE - INTERVAL '30 DAY')",
            [$b['id']]
        );
        $ecart_connu = (int)db_fetch_value("SELECT COALESCE(SUM(ecart),0) FROM ecarts_bobines WHERE bobine_id=? AND statut='ouvert'", [$b['id']]);
        db_query(
            "INSERT INTO inventaire_details_bobines (inventaire_id,bobine_id,stock_systeme,qte_temps_reel,stock_physique,ecart,ecart_connu_avant,conso_quotidienne_moy)
             VALUES (?,?,?,?,0,0,?,?)",
            [$inv_id, $b['id'], (int)$b['stock_systeme'], (int)$b['stock_systeme'], $ecart_connu, round($conso_moy, 2)]
        );
    }

    return ['id' => $inv_id, 'nb' => count($bobines)];
}

// ── Crée l'inventaire mensuel des rivets d'un site (+ ses lignes détail,
//    une par type_rivet en stock) et le rattache à une session. Même
//    logique que inv_creer_mensuel(), mais rivets = stock agrégé par
//    (site_id,type_rivet) dans op_stock_rivets, pas d'objet individuel :
//    le détail clé sur type_rivet plutôt que sur un id.
function inv_creer_rivets(int $site_id, string $date, ?int $session_id, int $user_id): array {
    $exist = db_fetch_one(
        "SELECT id FROM inventaires_rivets WHERE site_id=? AND date_inventaire=? AND type_inventaire='mensuel' AND statut!='annule'",
        [$site_id, $date]
    );
    if ($exist) throw new Exception("Un inventaire mensuel de rivets existe déjà pour ce site à cette date.");

    $stocks = db_fetch_all(
        "SELECT type_rivet, quantite FROM op_stock_rivets WHERE site_id=? ORDER BY type_rivet",
        [$site_id]
    );
    if (empty($stocks)) throw new Exception('Aucun stock de rivets sur ce site.');

    $total_systeme = array_sum(array_column($stocks, 'quantite'));

    db_query(
        "INSERT INTO inventaires_rivets (site_id,date_inventaire,type_inventaire,statut,nb_types,total_quantite_systeme,cree_par,session_id)
         VALUES (?,?,'mensuel','brouillon',?,?,?,?)",
        [$site_id, $date, count($stocks), $total_systeme, $user_id, $session_id]
    );
    $inv_id = (int)db_last_id();

    foreach ($stocks as $s) {
        $ecart_connu = (int)db_fetch_value(
            "SELECT COALESCE(SUM(ecart),0) FROM ecarts_rivets WHERE site_id=? AND type_rivet=? AND statut='ouvert'",
            [$site_id, $s['type_rivet']]
        );
        db_query(
            "INSERT INTO inventaire_details_rivets (inventaire_id,type_rivet,stock_systeme,stock_physique,ecart,ecart_connu_avant)
             VALUES (?,?,?,0,0,?)",
            [$inv_id, $s['type_rivet'], (int)$s['quantite'], $ecart_connu]
        );
    }

    return ['id' => $inv_id, 'nb' => count($stocks)];
}

// ── Crée l'inventaire mensuel des Équipements d'un site (+ ses lignes
//    détail, une par équipement affecté) et le rattache à une session.
//    Différent des trois autres : équipements = objets individuels sans
//    quantité, donc pas de stock_systeme à figer — juste une checklist
//    de présence à cocher (trouve NULL au départ). Périmètre calqué sur
//    la liste principale de pages/equipements.php : tous les équipements
//    actifs affectés au site, toutes catégories confondues.
function inv_creer_equipements(int $site_id, string $date, ?int $session_id, int $user_id): array {
    $exist = db_fetch_one(
        "SELECT id FROM inventaires_equipements WHERE site_id=? AND date_inventaire=? AND type_inventaire='mensuel' AND statut!='annule'",
        [$site_id, $date]
    );
    if ($exist) throw new Exception("Un inventaire mensuel d'équipements existe déjà pour ce site à cette date.");

    $equipements = db_fetch_all(
        "SELECT id FROM equipements WHERE site_id=? AND actif=1 ORDER BY numero_serie_interne",
        [$site_id]
    );
    if (empty($equipements)) throw new Exception('Aucun équipement actif sur ce site.');

    db_query(
        "INSERT INTO inventaires_equipements (site_id,date_inventaire,type_inventaire,statut,nb_equipements,cree_par,session_id)
         VALUES (?,?,'mensuel','brouillon',?,?,?)",
        [$site_id, $date, count($equipements), $user_id, $session_id]
    );
    $inv_id = (int)db_last_id();

    foreach ($equipements as $e) {
        $ecart_connu = db_fetch_value(
            "SELECT COUNT(*) FROM ecarts_equipements WHERE equipement_id=? AND statut='ouvert'",
            [$e['id']]
        ) ? 1 : 0;
        db_query(
            "INSERT INTO inventaire_details_equipements (inventaire_id,equipement_id,ecart_connu_avant)
             VALUES (?,?,?)",
            [$inv_id, $e['id'], $ecart_connu]
        );
    }

    return ['id' => $inv_id, 'nb' => count($equipements)];
}

// ── Crée l'inventaire mensuel des PMMA d'un site (+ ses lignes détail,
//    une par type_pmma en stock) et le rattache à une session. Même
//    logique que inv_creer_rivets() — stock agrégé par (site_id,type_pmma)
//    dans stock_pmma_site, sauf que type_pmma est un texte libre (pas un
//    format fixe) : la liste des types dépend de ce qui existe en stock.
function inv_creer_pmma(int $site_id, string $date, ?int $session_id, int $user_id): array {
    $exist = db_fetch_one(
        "SELECT id FROM inventaires_pmma WHERE site_id=? AND date_inventaire=? AND type_inventaire='mensuel' AND statut!='annule'",
        [$site_id, $date]
    );
    if ($exist) throw new Exception("Un inventaire mensuel de PMMA existe déjà pour ce site à cette date.");

    $stocks = db_fetch_all(
        "SELECT type_pmma, quantite FROM stock_pmma_site WHERE site_id=? AND type_pmma <> '' ORDER BY type_pmma",
        [$site_id]
    );
    if (empty($stocks)) throw new Exception('Aucun stock de PMMA sur ce site.');

    $total_systeme = array_sum(array_column($stocks, 'quantite'));

    db_query(
        "INSERT INTO inventaires_pmma (site_id,date_inventaire,type_inventaire,statut,nb_types,total_quantite_systeme,cree_par,session_id)
         VALUES (?,?,'mensuel','brouillon',?,?,?,?)",
        [$site_id, $date, count($stocks), $total_systeme, $user_id, $session_id]
    );
    $inv_id = (int)db_last_id();

    foreach ($stocks as $s) {
        $ecart_connu = (int)db_fetch_value(
            "SELECT COALESCE(SUM(ecart),0) FROM ecarts_pmma WHERE site_id=? AND type_pmma=? AND statut='ouvert'",
            [$site_id, $s['type_pmma']]
        );
        db_query(
            "INSERT INTO inventaire_details_pmma (inventaire_id,type_pmma,stock_systeme,stock_physique,ecart,ecart_connu_avant)
             VALUES (?,?,?,0,0,?)",
            [$inv_id, $s['type_pmma'], (int)$s['quantite'], $ecart_connu]
        );
    }

    return ['id' => $inv_id, 'nb' => count($stocks)];
}
