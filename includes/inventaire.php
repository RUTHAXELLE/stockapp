<?php
// ============================================================
//  includes/inventaire.php — Sessions & inventaire mensuel
//  Logique partagée entre l'ouverture de session (auto-provisionnement
//  de l'inventaire de chaque site, cf. pages/inventaire_sessions.php)
//  et pages/inventaire_bobines.php.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/consommation.php';

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

    // Référence de l'inventaire : la quantité ERP (films_restants), celle
    // que le comptage corrige — pas stock_systeme, qui n'est plus que le
    // relevé OptoTrace (cf. inv_valider_bobines()).
    $bobines = db_fetch_all(
        "SELECT b.id, b.numero, b.type_code, b.serie, b.films_restants
         FROM op_bobines b
         WHERE b.site_id=? AND b.statut NOT IN ('retiree','epuisee')
         ORDER BY b.serie, b.type_code, b.numero",
        [$site_id]
    );
    if (empty($bobines)) throw new Exception('Aucune bobine active sur ce site.');

    $total_systeme = array_sum(array_column($bobines, 'films_restants'));
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
        // n° 3.0 — formule centralisee dans includes/consommation.php,
        // partagee avec les modules KPI et Simulation.
        $conso_moy = conso_moy_bobine((int)$b['id']);
        $ecart_connu = (int)db_fetch_value("SELECT COALESCE(SUM(ecart),0) FROM ecarts_bobines WHERE bobine_id=? AND statut='ouvert'", [$b['id']]);
        db_query(
            "INSERT INTO inventaire_details_bobines (inventaire_id,bobine_id,stock_systeme,qte_temps_reel,stock_physique,ecart,ecart_connu_avant,conso_quotidienne_moy)
             VALUES (?,?,?,?,0,0,?,?)",
            [$inv_id, $b['id'], (int)$b['films_restants'], (int)$b['films_restants'], $ecart_connu, round($conso_moy, 2)]
        );
    }

    return ['id' => $inv_id, 'nb' => count($bobines)];
}

// ── Valide un inventaire de bobines : chaque bobine comptée prend son stock
//    physique comme quantité ERP (films_restants) et OptoTrace
//    (stock_systeme). Partagée par la liste des inventaires et la fiche
//    détail, qui divergeaient : la liste n'appliquait que les lignes à
//    écart non nul — écart mesuré contre OptoTrace, si bien qu'une bobine
//    comptée au chiffre d'OptoTrace gardait sa quantité ERP fausse
//    (« Inventaire validé » affiché, rien d'appliqué) ; la fiche, elle,
//    traçait l'ajustement contre OptoTrace et non contre l'ERP. L'écart
//    tracé est désormais celui de la quantité ERP, la seule que
//    l'inventaire corrige. À appeler dans la transaction de l'appelant.
function inv_valider_bobines(array $inv, int $user_id): array {
    $inv_id = (int)$inv['id'];
    $lignes = db_fetch_all("SELECT * FROM inventaire_details_bobines WHERE inventaire_id=?", [$inv_id]);
    $nb_ecarts = 0;
    $total_physique = 0;
    foreach ($lignes as $l) {
        // Ligne jamais saisie : stock_physique est NOT NULL (0 à la
        // création), seul un écart stocké distingue « compté à zéro » de
        // « pas compté » — une bobine non comptée garde son stock.
        if ((int)$l['stock_physique'] <= 0 && (int)$l['ecart'] == 0) continue;
        $bob = db_fetch_one("SELECT films_restants FROM op_bobines WHERE id=?", [$l['bobine_id']]);
        if (!$bob) continue;
        $phy       = (int)$l['stock_physique'];
        $erp_avant = (int)$bob['films_restants'];
        $total_physique += $phy;

        // Statut : même règle que l'import OptoTrace (entamée = en_cours).
        db_query(
            "UPDATE op_bobines SET films_restants=?, stock_systeme=?,
             statut = CASE WHEN statut='retiree' THEN 'retiree'
                           WHEN ? <= 0 THEN 'epuisee'
                           WHEN ? < qte_initiale THEN 'en_cours'
                           ELSE 'en_stock' END
             WHERE id=?",
            [$phy, $phy, $phy, $phy, $l['bobine_id']]
        );
        db_query(
            "UPDATE ecarts_bobines SET statut='resolu', resolu_at=NOW(), resolu_par=?, resolution_notes=?
             WHERE bobine_id=? AND statut='ouvert'",
            [$user_id, "Résolu par inventaire #$inv_id", $l['bobine_id']]
        );

        $ecart = $phy - $erp_avant;
        if ($ecart !== 0) {
            $nb_ecarts++;
            db_query(
                "INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,ref_id,created_by)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$l['bobine_id'], 'ajustement_inventaire', $ecart, $erp_avant, $phy,
                 "Inventaire #$inv_id ({$inv['type_inventaire']})", $inv_id, $user_id]
            );
            db_query(
                "INSERT INTO ecarts_bobines (bobine_id,date_constat,stock_systeme,stock_physique,ecart,motif,source,inventaire_id,statut,resolu_at,resolu_par,created_by)
                 VALUES (?,?,?,?,?,?,'inventaire',?,'resolu',NOW(),?,?)",
                [$l['bobine_id'], $inv['date_inventaire'], $erp_avant, $phy, $ecart,
                 $l['notes'] ?? '', $inv_id, $user_id, $user_id]
            );
        }
    }
    if ($inv['type_inventaire'] === 'mensuel') {
        $mois = date('Y-m', strtotime($inv['date_inventaire']));
        db_query(
            "INSERT INTO bilans_mensuels_bobines (site_id,mois,inventaire_id,stock_fin_mois,nb_ajustements,statut,valide_par,valide_at)
             VALUES (?,?,?,?,?,'valide',?,NOW())
             ON DUPLICATE KEY UPDATE stock_fin_mois=VALUES(stock_fin_mois),nb_ajustements=nb_ajustements+VALUES(nb_ajustements),
                                     statut='valide',valide_par=VALUES(valide_par),valide_at=NOW()",
            [$inv['site_id'], $mois, $inv_id, $total_physique, $nb_ecarts, $user_id]
        );
    }
    db_query(
        "UPDATE inventaires_bobines SET statut='valide',nb_ecarts=?,total_films_physique=?,valide_par=?,valide_at=NOW() WHERE id=?",
        [$nb_ecarts, $total_physique, $user_id, $inv_id]
    );
    return ['nb_ecarts' => $nb_ecarts, 'total_physique' => $total_physique];
}

// ── Garde-fou : un inventaire de bobines validé fait foi pour le stock de
//    son site. Le comptage du jour J (le matin, avant la production) tient
//    déjà compte de tout ce qui a été consommé jusqu'à J-1 : une opération
//    datée d'avant J qui toucherait encore aux films restants fausserait la
//    quantité comptée — un réajustement la remplacerait par une valeur
//    d'avant, un point saisi après coup déduirait des films déjà absents
//    au comptage, un brouillon supprimé en remettrait.
//    Renvoie la date du dernier inventaire validé du site postérieur à
//    $date, ou null si rien n'a été recompté depuis.
function inv_bobines_recompte_apres(int $site_id, string $date): ?string {
    if ($site_id <= 0 || $date === '') return null;
    $d = db_fetch_value(
        "SELECT MAX(date_inventaire) FROM inventaires_bobines
          WHERE site_id = ? AND statut = 'valide' AND date_inventaire > ?",
        [$site_id, $date]
    );
    return $d ? (string)$d : null;
}

// ── Phrase commune aux messages du garde-fou.
function inv_bobines_msg_recompte(string $date_inv): string {
    return "le stock des bobines de ce site a été recompté par l'inventaire validé du "
         . date('d/m/Y', strtotime($date_inv));
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
