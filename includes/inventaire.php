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
//    physique comme quantité ERP déclarée (films_restants). Le stock système
//    (stock_systeme) n'est PAS touché : il vient de l'import OptoTrace, et la
//    validation du stock compare justement l'import au déclaratif — le
//    recopier effacerait cette comparaison. Rivets et PMMA, sans import,
//    n'ont qu'une quantité : leur inventaire la remplace. Partagée par la liste des inventaires et la fiche
//    détail, qui divergeaient : la liste n'appliquait que les lignes à
//    écart non nul — écart mesuré contre OptoTrace, si bien qu'une bobine
//    comptée au chiffre d'OptoTrace gardait sa quantité ERP fausse
//    (« Inventaire validé » affiché, rien d'appliqué) ; la fiche, elle,
//    traçait l'ajustement contre OptoTrace et non contre l'ERP. L'écart
//    tracé est désormais celui de la quantité ERP, la seule que
//    l'inventaire corrige. À appeler dans la transaction de l'appelant.
function inv_valider_bobines(array $inv, int $user_id): array {
    $inv_id = (int)$inv['id'];
    inv_verifier_pas_plus_recent('inventaires_bobines', $inv);
    inv_verifier_point18h($inv);
    $lignes = db_fetch_all("SELECT * FROM inventaire_details_bobines WHERE inventaire_id=?", [$inv_id]);
    $conso  = inv_conso_apres((int)$inv['site_id'], (string)$inv['date_inventaire'], 'films');
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
        $deja      = $conso[(int)$l['bobine_id']] ?? 0;
        // Comptage de fin de journée (cf. RÈGLE INVENTAIRE) : le chiffre
        // compté devient le stock. Seuls des points datés d'après, déjà
        // validés, ont retiré des films depuis le comptage : stock au
        // comptage = ERP actuel + ce retrait ; nouveau stock = physique -
        // ce retrait (0 dans le cas courant : stock = physique).
        $erp_actuel = (int)$bob['films_restants'];
        $erp_avant  = $erp_actuel + $deja;
        $nouveau    = max(0, $phy - $deja);
        $total_physique += $phy;

        // Statut : même règle que l'import OptoTrace (entamée = en_cours).
        db_query(
            "UPDATE op_bobines SET
             statut = CASE WHEN statut='retiree' THEN 'retiree'
                           WHEN ? <= 0 THEN 'epuisee'
                           WHEN ? < qte_initiale THEN 'en_cours'
                           ELSE 'en_stock' END,
             films_restants=?
             WHERE id=?",
            [$nouveau, $nouveau, $nouveau, $l['bobine_id']]
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
                [$l['bobine_id'], 'ajustement_inventaire', $nouveau - $erp_actuel, $erp_actuel, $nouveau,
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

// ── RÈGLE INVENTAIRE (02/11/2026) : le comptage se fait en FIN de journée,
//    après la validation du point 18h. Le chiffre compté est le stock de
//    clôture du jour de l'inventaire : il devient le stock tel quel, et le
//    lendemain en part. Conséquences :
//    - un point daté du jour de l'inventaire ou d'avant ne touche plus au
//      stock une fois l'inventaire validé (inv_recompte_apres()) ;
//    - les points datés d'APRÈS, déjà validés au moment de la validation de
//      l'inventaire, ont retiré une consommation postérieure au comptage :
//      elle est déduite du chiffre compté (inv_conso_apres()) ;
//    - l'inventaire ne se valide qu'une fois le point 18h du jour validé
//      (inv_point18h_en_attente()), sinon l'écart mesuré compterait la
//      consommation du jour comme un manque.

// ── Point 18h du site daté du jour de l'inventaire, saisi mais dont le stock
//    n'est pas encore retiré (non validé par le superviseur). Renvoie son
//    statut, ou null s'il n'y en a pas (pas de point : rien à attendre).
function inv_point18h_en_attente(int $site_id, string $date): ?string {
    if (!db_colonne_existe('op_points_journaliers', 'stock_deduit')) return null;
    $st = db_fetch_value(
        "SELECT statut FROM op_points_journaliers
          WHERE site_id = ? AND date_point = ? AND type_point = 'point_18h' AND stock_deduit = 0
          ORDER BY id DESC LIMIT 1", [$site_id, $date]);
    return $st !== null && $st !== false ? (string)$st : null;
}

// ── Message commun du refus de validation tant que le point 18h attend.
function inv_verifier_point18h(array $inv): void {
    if (inv_point18h_en_attente((int)$inv['site_id'], (string)$inv['date_inventaire']) !== null)
        throw new Exception("le point 18h du " . date('d/m/Y', strtotime($inv['date_inventaire']))
            . " de ce site n'est pas encore validé par le superviseur. Le comptage est le stock de fin de journée :"
            . " validez d'abord ce point, puis l'inventaire.");
}

// ── Consommation retirée du stock par les points du site datés d'APRÈS le
//    jour de l'inventaire : postérieure au comptage de fin de journée, elle
//    est déduite du chiffre compté à la validation.
//    $quoi : 'films' (par bobine_id), 'rivets' (par type_rivet), 'pmma'
//    (par type_pmma). Seuls les points dont le stock est retiré comptent
//    (stock_deduit 1 ou 2 ; sans la colonne, ancienne règle : tous).
function inv_conso_apres(int $site_id, string $date, string $quoi): array {
    $deduit = db_colonne_existe('op_points_journaliers', 'stock_deduit') ? ' AND p.stock_deduit IN (1,2)' : '';
    if ($quoi === 'rivets') {
        $r = db_fetch_one(
            "SELECT COALESCE(SUM(p.rivets_gonflables),0) AS g, COALESCE(SUM(p.rivets_eclates),0) AS e
               FROM op_points_journaliers p WHERE p.site_id = ? AND p.date_point > ?$deduit", [$site_id, $date]);
        return ['gonflable' => (int)($r['g'] ?? 0), 'eclate' => (int)($r['e'] ?? 0)];
    }
    if ($quoi === 'films') {
        $rows = db_fetch_all(
            "SELECT fu.bobine_id AS k, SUM(fu.films_utilises + fu.films_endommages) AS n
               FROM op_films_utilises fu JOIN op_points_journaliers p ON p.id = fu.point_id
              WHERE p.site_id = ? AND p.date_point > ?$deduit GROUP BY fu.bobine_id", [$site_id, $date]);
    } else {
        $nc = db_colonne_existe('op_pmma_utilises', 'non_conformes') ? ' + pm.non_conformes' : '';
        $rows = db_fetch_all(
            "SELECT pm.type_pmma AS k, SUM(pm.utilises + pm.endommages$nc) AS n
               FROM op_pmma_utilises pm JOIN op_points_journaliers p ON p.id = pm.point_id
              WHERE p.site_id = ? AND p.date_point > ?$deduit GROUP BY pm.type_pmma", [$site_id, $date]);
    }
    $res = [];
    foreach ($rows as $r) $res[$r['k']] = (int)$r['n'];
    return $res;
}

// ── Un inventaire plus ancien qu'un inventaire déjà validé du même site ne
//    se valide plus : il remplacerait un stock recompté par un comptage
//    antérieur. $table : inventaires_bobines | inventaires_rivets | inventaires_pmma.
function inv_verifier_pas_plus_recent(string $table, array $inv): void {
    $d = db_fetch_value(
        "SELECT MAX(date_inventaire) FROM $table
          WHERE site_id = ? AND statut = 'valide' AND date_inventaire > ? AND id <> ?",
        [(int)$inv['site_id'], (string)$inv['date_inventaire'], (int)$inv['id']]);
    if ($d) throw new Exception("un inventaire plus récent de ce site (du " . date('d/m/Y', strtotime($d))
        . ") est déjà validé ; ce comptage plus ancien ne peut plus remplacer le stock.");
}

// ── Garde-fou : un inventaire validé fait foi pour le stock de son site.
//    Le comptage du jour J (fin de journée, cf. RÈGLE INVENTAIRE) tient déjà
//    compte de tout ce qui a été consommé jusqu'à J inclus : une opération
//    datée de J ou d'avant qui toucherait encore au stock fausserait la
//    quantité comptée — un réajustement la remplacerait par une valeur
//    d'avant, un point validé ou corrigé après coup déduirait ce qui était
//    déjà absent au comptage, un point rejeté en remettrait.
//    $table : inventaires_bobines | inventaires_rivets | inventaires_pmma.
//    $inclus = false : inventaire daté strictement d'après $date (clôture
//    de journée, cf. _site_bloque_ecart()).
//    Renvoie la date du dernier inventaire validé concerné, ou null.
function inv_recompte_apres(string $table, int $site_id, string $date, bool $inclus = true): ?string {
    if ($site_id <= 0 || $date === '') return null;
    $op = $inclus ? '>=' : '>';
    $d = db_fetch_value(
        "SELECT MAX(date_inventaire) FROM $table
          WHERE site_id = ? AND statut = 'valide' AND date_inventaire $op ?",
        [$site_id, $date]
    );
    return $d ? (string)$d : null;
}

function inv_bobines_recompte_apres(int $site_id, string $date, bool $inclus = true): ?string {
    return inv_recompte_apres('inventaires_bobines', $site_id, $date, $inclus);
}

// ── Rien à compter sur ce site pour ce type d'inventaire (mêmes critères que
//    inv_creer_*() : sans bobine active, stock de rivets ou de PMMA, ni
//    équipement actif, l'ouverture de session ne crée pas d'inventaire). Une
//    session ne doit pas attendre un inventaire qui n'existera jamais.
//    $type : bobines | rivets | pmma | equipements.
function inv_rien_a_compter(string $type, int $site_id): bool {
    $sql = [
        'bobines'     => "SELECT COUNT(*) FROM op_bobines WHERE site_id=? AND statut NOT IN ('retiree','epuisee')",
        'rivets'      => "SELECT COUNT(*) FROM op_stock_rivets WHERE site_id=?",
        'pmma'        => "SELECT COUNT(*) FROM stock_pmma_site WHERE site_id=? AND type_pmma <> ''",
        'equipements' => "SELECT COUNT(*) FROM equipements WHERE site_id=? AND actif=1",
    ][$type] ?? null;
    return $sql !== null && (int)db_fetch_value($sql, [$site_id]) === 0;
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
