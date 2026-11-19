<?php
// ============================================================
//  includes/inventaire.php — Sessions & inventaire mensuel
//  Logique partagée entre l'ouverture de session (auto-provisionnement
//  de l'inventaire de chaque site, cf. pages/inventaire_sessions.php)
//  et pages/inventaire_bobines.php.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/consommation.php';
require_once __DIR__ . '/bobines_absentes.php';

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

// ── Bobines qu'un inventaire compte (alias b sur op_bobines) : toutes celles
//    du site, en stock ou en utilisation, sauf les retirées. Une bobine
//    épuisée n'y entre que si l'import OptoTrace lui voit encore des films :
//    passée à 0 à tort (saisie trop forte dans un point), elle sortait du
//    point journalier et l'inventaire, qui l'ignorait, ne pouvait plus la
//    rattraper. Recomptée avec des films, elle repasse « En cours » à la
//    validation (cf. inv_valider_bobines()).
//    Ni les perdues, ni les absentes (renvoyées à l'entrepôt principal par un
//    inventaire, cf. includes/bobines_absentes.php) : elles ne sont plus
//    physiquement nulle part où les compter — un site qui en retrouve une
//    l'ajoute à son inventaire.
const INV_BOBINES_A_COMPTER =
    "(b.statut NOT IN ('retiree','epuisee','perdue','absente') OR (b.statut='epuisee' AND b.stock_systeme > 0))";

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
         WHERE b.site_id=? AND " . INV_BOBINES_A_COMPTER . "
         ORDER BY b.serie, b.type_code, b.numero",
        [$site_id]
    );
    if (empty($bobines)) throw new Exception('Aucune bobine à compter sur ce site.');

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
    inv_verrouiller_brouillon('inventaires_bobines', $inv_id);
    inv_verifier_pas_plus_recent('inventaires_bobines', $inv);
    inv_verifier_point18h($inv);
    $lignes = db_fetch_all("SELECT * FROM inventaire_details_bobines WHERE inventaire_id=?", [$inv_id]);
    // Toutes les bobines doivent être comptées (ou déclarées absentes) : une
    // ligne oubliée était ignorée sans avertissement et l'inventaire validé.
    $non_comptees = [];
    foreach ($lignes as $l) {
        if (!bab_ligne_saisie($l) && empty($l['ajoutee']))
            $non_comptees[] = db_fetch_value("SELECT numero FROM op_bobines WHERE id=?", [$l['bobine_id']]);
    }
    if ($non_comptees) throw new Exception(count($non_comptees) . ' bobine(s) non comptée(s) : ' . implode(', ', array_slice($non_comptees, 0, 15))
        . (count($non_comptees) > 15 ? '…' : '') . '. Saisissez chaque bobine (0 si vide, ou « absente ») avant de valider.');
    $conso  = inv_conso_apres((int)$inv['site_id'], inv_date_stock($inv), 'films');
    $nb_ecarts = 0;
    $total_physique = 0;
    $bab = bab_actif();
    $site_inv = (int)$inv['site_id'];
    $lien = '/pages/inventaire_detail.php?id=' . $inv_id;
    $absentes = []; $retrouvees = []; $ignorees = []; $conflits = [];
    foreach ($lignes as $l) {
        // Ligne jamais saisie : stock_physique est NOT NULL (0 à la
        // création), seul un écart stocké (ou la mention « absente »)
        // distingue « compté à zéro » de « pas compté » — une bobine non
        // comptée garde son stock.
        if (!bab_ligne_saisie($l)) {
            if (!empty($l['ajoutee'])) {
                $num = db_fetch_value("SELECT numero FROM op_bobines WHERE id=?", [$l['bobine_id']]);
                throw new Exception("La bobine retrouvée $num n'a pas été comptée : saisissez sa quantité ou retirez-la de l'inventaire.");
            }
            continue;
        }
        $bob = db_fetch_one(
            "SELECT b.films_restants, b.site_id, b.statut, b.numero, s.nom AS site_nom
             FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id WHERE b.id=? FOR UPDATE OF b", [$l['bobine_id']]);
        if (!$bob) continue;
        $phy       = (int)$l['stock_physique'];
        $deja      = $conso[(int)$l['bobine_id']] ?? 0;
        $ailleurs  = $bab && (int)$bob['site_id'] !== $site_inv;

        if ($bab && !empty($l['absente'])) {
            // Déjà partie du site (retrouvée ailleurs entre-temps) : rien à faire.
            if ($ailleurs) { $ignorees[] = $bob['numero']; continue; }
            if ($deja > 0) throw new Exception("La bobine {$bob['numero']} est déclarée absente mais un point validé daté d'après l'inventaire l'a utilisée : corrigez la ligne.");
            bab_declarer_absente($l, $inv, $bob, $user_id);
            $absentes[] = $bob['numero'];
            continue;
        }
        if ($ailleurs) {
            // Comptée présente ici alors qu'elle est sur un autre site. Si cet
            // autre site l'a lui aussi comptée présente dans la même campagne
            // (ligne ajoutée = retrouvée là-bas, ou ligne de son inventaire),
            // la ligne n'est pas appliquée : le GSB tranche.
            $autre = $bob['statut'] === 'absente' ? null
                   : bab_comptee_ailleurs((int)$l['bobine_id'], $inv, empty($l['ajoutee']));
            if ($autre !== null) {
                $conflits[] = "{$bob['numero']} (aussi comptée sur $autre)";
                continue;
            }
            // Ligne d'origine dont la bobine est partie depuis l'ouverture par
            // un autre circuit (commande, transfert) : comportement inchangé,
            // le comptage s'applique là où elle est.
            if (empty($l['ajoutee'])) $ailleurs = false;
        }
        // Comptage de fin de journée (cf. RÈGLE INVENTAIRE) : le chiffre
        // compté devient le stock. Seuls des points datés d'après, déjà
        // validés, ont retiré des films depuis le comptage : stock au
        // comptage = ERP actuel + ce retrait ; nouveau stock = physique -
        // ce retrait (0 dans le cas courant : stock = physique).
        if ($ailleurs) $deja = 0;   // les points du site ne l'ont pas utilisée : elle n'y était pas
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
        if ($ailleurs) {
            bab_affecter_retrouvee($l, $inv, $bob, $nouveau, $user_id);
            $retrouvees[] = $bob['numero'];
        }
    }
    if ($absentes || $retrouvees || $conflits) {
        $site_nom = (string)(db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_inv]) ?? '');
        if ($absentes)
            bab_notifier_gsb('Bobines absentes', count($absentes) . " bobine(s) déclarée(s) absente(s) à l'inventaire de $site_nom, renvoyée(s) à l'entrepôt principal : " . implode(', ', $absentes) . '.', $lien);
        if ($retrouvees)
            bab_notifier_gsb('Bobines retrouvées', count($retrouvees) . " bobine(s) retrouvée(s) et affectée(s) à $site_nom : " . implode(', ', $retrouvees) . '.', $lien);
        if ($conflits)
            bab_notifier_gsb('Bobines comptées sur deux sites', "Inventaire de $site_nom : lignes non appliquées, à arbitrer — " . implode(', ', $conflits) . '.', $lien);
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
    return ['nb_ecarts' => $nb_ecarts, 'total_physique' => $total_physique,
            'absentes' => $absentes, 'retrouvees' => $retrouvees, 'ignorees' => $ignorees, 'conflits' => $conflits];
}

// ── Verrouille la ligne de l'inventaire et refuse si elle n'est plus un
//    brouillon : deux validations simultanées (double clic, deux gestionnaires)
//    lisaient toutes deux « brouillon » et rejouaient les ajustements.
//    À appeler dans la transaction de l'appelant. $table : inventaires_bobines
//    | inventaires_rivets | inventaires_pmma | inventaires_equipements.
function inv_verrouiller_brouillon(string $table, int $inv_id): void {
    $st = db_fetch_value("SELECT statut FROM $table WHERE id = ? FOR UPDATE", [$inv_id]);
    if ($st !== 'brouillon')
        throw new Exception("cet inventaire n'est plus en cours (déjà validé ou annulé).");
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

// ── MOMENT DU COMPTAGE (19/11/2026) : un inventaire fait le matin, avant
//    les activités, compte le stock d'ouverture du jour J, c'est-à-dire le
//    stock de fin de journée de J-1. inventaires_*.comptage_matin (NULL =
//    pas encore fixé, traité comme le soir) : 1 = matin. Toute la règle
//    « fin de journée » s'appuie alors sur J-1 : le point du soir de J reste
//    possible et sa consommation est déduite du chiffre compté.
function inv_date_stock(array $inv): string {
    $d = (string)$inv['date_inventaire'];
    return !empty($inv['comptage_matin']) ? date('Y-m-d', strtotime("$d -1 day")) : $d;
}

function inv_moment_dispo(string $table): bool {
    return db_colonne_existe($table, 'comptage_matin');
}

// ── Message commun du refus de validation tant que le point 18h attend.
function inv_verifier_point18h(array $inv): void {
    if (inv_point18h_en_attente((int)$inv['site_id'], inv_date_stock($inv)) !== null)
        throw new Exception("le point 18h du " . date('d/m/Y', strtotime(inv_date_stock($inv)))
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
    $moi = inv_date_stock($inv);
    $d = null;
    $col = inv_moment_dispo($table) ? ', comptage_matin' : '';
    foreach (db_fetch_all(
        "SELECT date_inventaire$col FROM $table
          WHERE site_id = ? AND statut = 'valide' AND date_inventaire >= ? AND id <> ?",
        [(int)$inv['site_id'], $moi, (int)$inv['id']]) as $autre) {
        if (inv_date_stock($autre) > $moi && ($d === null || $autre['date_inventaire'] > $d)) $d = $autre['date_inventaire'];
    }
    if ($d) throw new Exception("un inventaire plus récent de ce site (du " . date('d/m/Y', strtotime($d))
        . ") est déjà validé ; ce comptage plus ancien ne peut plus remplacer le stock."
        . " S'il n'a plus lieu d'être, annulez-le (bouton « Annuler l'inventaire » de la fiche).");
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
    if (inv_moment_dispo($table)) {
        // Date du stock compté (J, ou J-1 pour un comptage du matin) >= $date
        // (ou > $date si $inclus est faux).
        $ref = $inclus ? $date : date('Y-m-d', strtotime("$date +1 day"));
        $d = db_fetch_value(
            "SELECT MAX(date_inventaire) FROM $table
              WHERE site_id = ? AND statut = 'valide'
                AND (date_inventaire > ? OR (date_inventaire = ? AND COALESCE(comptage_matin,0) = 0))",
            [$site_id, $ref, $ref]);
        return $d ? (string)$d : null;
    }
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
        'bobines'     => "SELECT COUNT(*) FROM op_bobines b WHERE b.site_id=? AND " . INV_BOBINES_A_COMPTER,
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

// ============================================================
//  REVUE INVENTAIRE (19/11/2026)
// ============================================================

// ── Tables de chaque type d'inventaire.
const INV_TABLES = [
    'bobines'     => ['inv' => 'inventaires_bobines',     'det' => 'inventaire_details_bobines'],
    'rivets'      => ['inv' => 'inventaires_rivets',      'det' => 'inventaire_details_rivets'],
    'pmma'        => ['inv' => 'inventaires_pmma',        'det' => 'inventaire_details_pmma'],
    'equipements' => ['inv' => 'inventaires_equipements', 'det' => 'inventaire_details_equipements'],
];

// ── Condition SQL « ligne saisie » (alias d sur la table détail).
function inv_sql_ligne_saisie(string $type): string {
    if ($type === 'equipements') return 'd.trouve IS NOT NULL';
    $c = '(d.stock_physique > 0 OR d.ecart <> 0';
    if ($type === 'bobines' && db_colonne_existe('inventaire_details_bobines', 'absente')) $c .= ' OR d.absente = 1';
    if (db_colonne_existe(INV_TABLES[$type]['det'], 'saisi_at')) $c .= ' OR d.saisi_at IS NOT NULL';
    return $c . ')';
}

// ── Date de comptage. Un inventaire provisionné par une session portait la
//    date d'ouverture de la session, alors que le site compte plus tard :
//    la règle « stock de fin de journée » retirait une seconde fois tout ce
//    que les points avaient consommé entre-temps. À la première saisie,
//    l'inventaire prend la date du jour (jamais dans le futur). Les
//    inventaires créés à une date choisie (hors session) la gardent.
//    $type : bobines | rivets | pmma | equipements. Renvoie l'inventaire à jour.
function inv_dater_comptage(string $type, array $inv): array {
    if ($inv['statut'] !== 'brouillon') return $inv;
    $t = INV_TABLES[$type];
    $deja = (int)db_fetch_value("SELECT COUNT(*) FROM {$t['det']} d WHERE d.inventaire_id=? AND " . inv_sql_ligne_saisie($type), [(int)$inv['id']]);
    if ($deja > 0) return $inv;
    $auj = date('Y-m-d');
    if (!empty($inv['session_id']) && (string)$inv['date_inventaire'] !== $auj) {
        db_query("UPDATE {$t['inv']} SET date_inventaire=? WHERE id=? AND statut='brouillon'", [$auj, (int)$inv['id']]);
        $inv['date_inventaire'] = $auj;
    }
    // Moment pas encore choisi : matin si la saisie commence avant midi et
    // qu'aucun point du jour n'existe pour le site (activité pas commencée).
    if ((string)$inv['date_inventaire'] === $auj && inv_moment_dispo($t['inv'])
        && ($inv['comptage_matin'] ?? null) === null) {
        $matin = (int)date('G') < 12 && !(int)db_fetch_value(
            "SELECT COUNT(*) FROM op_points_journaliers WHERE site_id=? AND date_point=?", [(int)$inv['site_id'], $auj]);
        db_query("UPDATE {$t['inv']} SET comptage_matin=? WHERE id=?", [$matin ? 1 : 0, (int)$inv['id']]);
        $inv['comptage_matin'] = $matin ? 1 : 0;
    }
    return $inv;
}

// ── Choix manuel du moment du comptage (brouillon). Rivets et PMMA : la
//    référence notée à chaque saisie comptait comme « déjà consommé après le
//    comptage » les points datés d'après l'ancienne date de stock ; elle est
//    recalculée pour la nouvelle (points du jour validés avant la saisie,
//    ajoutés en passant au matin, retirés en passant au soir).
function inv_changer_moment(string $type, array $inv, bool $matin): void {
    $t = INV_TABLES[$type];
    if (!inv_moment_dispo($t['inv'])) throw new Exception('Option indisponible : migration non passée.');
    if ($inv['statut'] !== 'brouillon') throw new Exception("L'inventaire n'est plus en cours.");
    $ancienne = inv_date_stock($inv);
    $inv['comptage_matin'] = $matin ? 1 : 0;
    $nouvelle = inv_date_stock($inv);
    db_begin();
    try {
        db_query("UPDATE {$t['inv']} SET comptage_matin=? WHERE id=? AND statut='brouillon'", [$matin ? 1 : 0, (int)$inv['id']]);
        if ($ancienne !== $nouvelle && isset(INV_AGREGE[$type]) && inv_ag_reference_dispo($type)) {
            $c = INV_AGREGE[$type];
            [$de, $a] = $nouvelle < $ancienne ? [$nouvelle, $ancienne] : [$ancienne, $nouvelle];
            $signe = $nouvelle < $ancienne ? 1 : -1;
            foreach (db_fetch_all("SELECT * FROM {$c['det']} WHERE inventaire_id=? AND stock_reference IS NOT NULL AND saisi_at IS NOT NULL",
                                  [(int)$inv['id']]) as $l) {
                $delta = inv_conso_points((int)$inv['site_id'], $type, 'p.date_point > ? AND p.date_point <= ? AND p.validated_at <= ?',
                                          [$de, $a, (string)$l['saisi_at']])[(string)$l[$c['cle']]] ?? 0;
                if ($delta === 0) continue;
                $ref = (int)$l['stock_reference'] + $signe * $delta;
                db_query("UPDATE {$c['det']} SET stock_reference=?, ecart=? WHERE id=?", [$ref, (int)$l['stock_physique'] - $ref, (int)$l['id']]);
            }
        }
        db_commit();
    } catch (Exception $e) { db_rollback(); throw $e; }
}

// ── Bandeau « Comptage : … » de la fiche, avec le choix si modifiable.
function inv_moment_html(string $type, array $inv, bool $modifiable): string {
    if (!inv_moment_dispo(INV_TABLES[$type]['inv'])) return '';
    $j = date('d/m/Y', strtotime((string)$inv['date_inventaire']));
    $matin = !empty($inv['comptage_matin']);
    $txt = $matin ? "avant les activités du $j (stock d'ouverture ; le point du soir reste à faire)"
                  : "en fin de journée du $j, après le point 18h";
    if (!$modifiable || $inv['statut'] !== 'brouillon')
        return '<div class="inv-meta" style="margin-top:4px">Comptage : <strong>' . h($txt) . '</strong></div>';
    $opt = fn($v, $l, $sel) => '<option value="' . $v . '"' . ($sel ? ' selected' : '') . '>' . h($l) . '</option>';
    return '<div class="inv-meta" style="margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
         . '<label for="invMoment">Comptage réalisé :</label>'
         . '<select id="invMoment" class="form-control" style="width:auto;padding:4px 8px;font-size:13px" '
         . 'onchange="ap({action:\'moment_comptage\',matin:this.value}).then(d=>{toast(d.message,d.success?\'success\':\'error\');if(d.success)setTimeout(()=>location.reload(),600);})">'
         . $opt('1', "le $j au matin, avant les activités", $matin)
         . $opt('0', "le $j en fin de journée, après le point 18h", !$matin)
         . '</select></div>';
}

// ── Date saisie à la création d'un inventaire : AAAA-MM-JJ valide, pas dans
//    le futur (un inventaire daté d'après aujourd'hui, une fois validé,
//    bloquait toute correction de point du site jusqu'à cette date).
function inv_date_valide(string $d): ?string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) return 'Date invalide.';
    if ($d > date('Y-m-d')) return "La date de l'inventaire ne peut pas être dans le futur.";
    return null;
}

// ── Destinataires d'une demande d'autorisation du coordinateur : ceux qui
//    peuvent l'accorder (administrateurs et délégations de session).
function inv_destinataires_autorisation(): array {
    return array_map('intval', array_column(db_fetch_all(
        "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
          WHERE u.actif=1 AND (r.slug IN ('admin','superadmin')
             OR EXISTS (SELECT 1 FROM delegations dg WHERE dg.gestionnaire_id=u.id AND dg.module='inventaire_sessions' AND dg.actif=1))"), 'id'));
}

// ── Une ligne saisie ne se rouvre que par une demande (envoyée par l'admin,
//    ou du coordinateur et autorisée). Une saisie faite pendant cette
//    ouverture clôt la demande : sans cela la ligne restait modifiable
//    indéfiniment. $corr : table des demandes du type d'inventaire.
function inv_ligne_deverrouillee(string $corr, int $detail_id): bool {
    return (bool)db_fetch_value(
        "SELECT COUNT(*) FROM $corr WHERE detail_id=?
            AND ((type='demande_site' AND statut='en_attente')
              OR (type='demande_autorisation' AND statut='autorise'))", [$detail_id]);
}

function inv_clore_demandes(string $corr, int $detail_id, int $valeur, int $user_id): void {
    db_query(
        "UPDATE $corr SET statut='traite', valeur_finale=?, reponse=COALESCE(reponse, 'Corrigé par une nouvelle saisie'), traite_par=?, traite_at=NOW()
          WHERE detail_id=? AND ((type='demande_site' AND statut='en_attente') OR (type='demande_autorisation' AND statut='autorise'))",
        [$valeur, $user_id, $detail_id]);
}

// ── Consommation retirée par les points d'un site, selon un filtre SQL
//    (alias p), pour rivets (par type_rivet) ou PMMA (par type_pmma).
function inv_conso_points(int $site_id, string $quoi, string $filtre, array $params): array {
    $deduit = db_colonne_existe('op_points_journaliers', 'stock_deduit') ? ' AND p.stock_deduit IN (1,2)' : '';
    $base = array_merge([$site_id], $params);
    if ($quoi === 'rivets') {
        $r = db_fetch_one(
            "SELECT COALESCE(SUM(p.rivets_gonflables),0) AS g, COALESCE(SUM(p.rivets_eclates),0) AS e
               FROM op_points_journaliers p WHERE p.site_id = ? AND $filtre$deduit", $base);
        return ['gonflable' => (int)($r['g'] ?? 0), 'eclate' => (int)($r['e'] ?? 0)];
    }
    $nc = db_colonne_existe('op_pmma_utilises', 'non_conformes') ? ' + pm.non_conformes' : '';
    $rows = db_fetch_all(
        "SELECT pm.type_pmma AS k, SUM(pm.utilises + pm.endommages$nc) AS n
           FROM op_pmma_utilises pm JOIN op_points_journaliers p ON p.id = pm.point_id
          WHERE p.site_id = ? AND $filtre$deduit GROUP BY pm.type_pmma", $base);
    $res = [];
    foreach ($rows as $r) $res[$r['k']] = (int)$r['n'];
    return $res;
}

// ============================================================
//  RIVETS ET PMMA : stock agrégé par type
// ============================================================
//  À la saisie, on note la référence : stock du site à cet instant + ce que
//  les points datés d'après le comptage ont déjà retiré, soit le stock
//  théorique de fin de journée. À la validation, l'écart compté s'applique
//  au stock ACTUEL au lieu de le remplacer : une entrée ou une réception
//  faite entre la saisie et la validation est conservée. Un point daté du
//  jour du comptage (ou d'avant) validé après la saisie a retiré une
//  consommation déjà absente du comptage : elle est rendue.
const INV_AGREGE = [
    'rivets' => ['inv' => 'inventaires_rivets', 'det' => 'inventaire_details_rivets', 'corr' => 'inventaire_corrections_rivets',
                 'cle' => 'type_rivet', 'stock' => 'op_stock_rivets', 'ecarts' => 'ecarts_rivets'],
    'pmma'   => ['inv' => 'inventaires_pmma', 'det' => 'inventaire_details_pmma', 'corr' => 'inventaire_corrections_pmma',
                 'cle' => 'type_pmma', 'stock' => 'stock_pmma_site', 'ecarts' => 'ecarts_pmma'],
];

function inv_ag_reference_dispo(string $type): bool {
    return db_colonne_existe(INV_AGREGE[$type]['det'], 'stock_reference');
}

function inv_ag_ligne_saisie(array $l): bool {
    return !empty($l['saisi_at']) || (int)$l['stock_physique'] > 0 || (int)$l['ecart'] !== 0;
}

function inv_ag_stock_actuel(string $type, int $site_id, string $cle, bool $verrou = false): int {
    $c = INV_AGREGE[$type];
    return (int)(db_fetch_value("SELECT quantite FROM {$c['stock']} WHERE site_id=? AND {$c['cle']}=?" . ($verrou ? ' FOR UPDATE' : ''),
        [$site_id, $cle]) ?? 0);
}

/** Enregistre le comptage d'une ligne ; renvoie l'écart mesuré. */
function inv_ag_enregistrer(string $type, array $inv, array $det, int $phy, ?string $notes, int $user_id): int {
    $c = INV_AGREGE[$type];
    if ($phy < 0) throw new Exception('Le stock physique ne peut pas être négatif.');
    $cle = (string)$det[$c['cle']];
    $deja_saisie = inv_ag_ligne_saisie($det);
    $sets = 'stock_physique=?, ecart=?';
    if (inv_ag_reference_dispo($type)) {
        $ref = inv_ag_stock_actuel($type, (int)$inv['site_id'], $cle)
             + (inv_conso_apres((int)$inv['site_id'], inv_date_stock($inv), $type)[$cle] ?? 0);
        $ecart  = $phy - $ref;
        $params = [$phy, $ecart, $ref];
        $sets  .= ', stock_reference=?, saisi_at=NOW()';
    } else {
        $ecart  = $phy - (int)$det['stock_systeme'];
        $params = [$phy, $ecart];
    }
    if ($notes !== null) { $sets .= ', notes=?'; $params[] = $notes; }
    $params[] = (int)$det['id'];
    db_query("UPDATE {$c['det']} SET $sets WHERE id=?", $params);
    if ($deja_saisie) inv_clore_demandes($c['corr'], (int)$det['id'], $phy, $user_id);
    return $ecart;
}

/** Validation commune (fiche et liste). À appeler dans la transaction de l'appelant. */
function inv_valider_agrege(string $type, array $inv, int $user_id): array {
    $c = INV_AGREGE[$type];
    $inv_id = (int)$inv['id'];
    $site   = (int)$inv['site_id'];
    inv_verrouiller_brouillon($c['inv'], $inv_id);
    inv_verifier_pas_plus_recent($c['inv'], $inv);
    inv_verifier_point18h($inv);
    $lignes = db_fetch_all("SELECT * FROM {$c['det']} WHERE inventaire_id=?", [$inv_id]);
    $manque = array_map(fn($l) => $l[$c['cle']], array_filter($lignes, fn($l) => !inv_ag_ligne_saisie($l)));
    if ($manque) throw new Exception('ligne(s) non comptée(s) : ' . implode(', ', $manque) . '. Saisissez chaque ligne (0 si rien) avant de valider.');
    $conso = inv_conso_apres($site, inv_date_stock($inv), $type);
    $nb_ecarts = 0; $total_physique = 0;
    foreach ($lignes as $l) {
        $cle = (string)$l[$c['cle']];
        $phy = (int)$l['stock_physique'];
        $total_physique += $phy;
        $actuel = inv_ag_stock_actuel($type, $site, $cle, true);
        if (isset($l['stock_reference']) && $l['stock_reference'] !== null && !empty($l['saisi_at'])) {
            $tardif = inv_conso_points($site, $type, 'p.date_point <= ? AND p.validated_at > ?',
                [inv_date_stock($inv), (string)$l['saisi_at']])[$cle] ?? 0;
            $sys     = (int)$l['stock_reference'] - $tardif;   // stock théorique au comptage
            $ecart   = $phy - $sys;
            $nouveau = max(0, $actuel + $ecart);
        } else {
            // Ligne saisie avant cette version : ancienne règle.
            $sys     = (int)$l['stock_systeme'];
            $ecart   = $phy - $sys;
            $nouveau = max(0, $phy - ($conso[$cle] ?? 0));
        }
        $maj = db_query("UPDATE {$c['stock']} SET quantite=? WHERE site_id=? AND {$c['cle']}=?", [$nouveau, $site, $cle]);
        if ($maj->rowCount() === 0 && !db_fetch_value("SELECT COUNT(*) FROM {$c['stock']} WHERE site_id=? AND {$c['cle']}=?", [$site, $cle]))
            db_query("INSERT INTO {$c['stock']} (site_id,{$c['cle']},quantite) VALUES (?,?,?)", [$site, $cle, $nouveau]);
        db_query("UPDATE {$c['ecarts']} SET statut='resolu', resolu_at=NOW(), resolu_par=? WHERE site_id=? AND {$c['cle']}=? AND statut='ouvert'",
            [$user_id, $site, $cle]);
        if ($ecart !== 0) {
            $nb_ecarts++;
            db_query(
                "INSERT INTO {$c['ecarts']} (site_id,{$c['cle']},date_constat,stock_systeme,stock_physique,ecart,motif,source,inventaire_id,statut,resolu_at,resolu_par,created_by)
                 VALUES (?,?,?,?,?,?,?,'inventaire',?,'resolu',NOW(),?,?)",
                [$site, $cle, $inv['date_inventaire'], $sys, $phy, $ecart, $l['notes'] ?? '', $inv_id, $user_id, $user_id]);
        }
    }
    db_query("UPDATE {$c['inv']} SET statut='valide',nb_ecarts=?,total_quantite_physique=?,valide_par=?,valide_at=NOW() WHERE id=?",
        [$nb_ecarts, $total_physique, $user_id, $inv_id]);
    return ['nb_ecarts' => $nb_ecarts, 'total_physique' => $total_physique];
}

// ── Validation des équipements (fiche et liste). Dans la transaction de l'appelant.
function inv_valider_equipements(array $inv, int $user_id): array {
    require_once __DIR__ . '/equipements_trouves.php';
    $inv_id = (int)$inv['id'];
    inv_verrouiller_brouillon('inventaires_equipements', $inv_id);
    if (eqt_disponible() && ($n_att = eqt_nb_en_attente($inv_id)) > 0)
        throw new Exception("$n_att équipement(s) trouvé(s) attendent la décision du gestionnaire de stock (créer, transférer ou refuser) avant la validation.");
    $lignes = db_fetch_all("SELECT * FROM inventaire_details_equipements WHERE inventaire_id=?", [$inv_id]);
    $manque = count(array_filter($lignes, fn($l) => $l['trouve'] === null));
    if ($manque) throw new Exception("$manque équipement(s) non pointé(s) (trouvé ou manquant) : pointez chaque ligne avant de valider.");
    $nb_ecarts = 0; $nb_trouves = 0;
    foreach ($lignes as $l) {
        if ((int)$l['trouve'] === 1) {
            $nb_trouves++;
            db_query("UPDATE ecarts_equipements SET statut='resolu',resolu_at=NOW(),resolu_par=? WHERE equipement_id=? AND statut='ouvert'",
                [$user_id, $l['equipement_id']]);
        } else {
            $nb_ecarts++;
            if (!db_fetch_value("SELECT COUNT(*) FROM ecarts_equipements WHERE equipement_id=? AND statut='ouvert'", [$l['equipement_id']]))
                db_query("INSERT INTO ecarts_equipements (site_id,equipement_id,date_constat,motif,source,inventaire_id,statut,created_by) VALUES (?,?,?,?,?,?,'ouvert',?)",
                    [$inv['site_id'], $l['equipement_id'], $inv['date_inventaire'], $l['notes'] ?? '', 'inventaire', $inv_id, $user_id]);
        }
    }
    db_query("UPDATE inventaires_equipements SET statut='valide',nb_ecarts=?,nb_trouves=?,valide_par=?,valide_at=NOW() WHERE id=?",
        [$nb_ecarts, $nb_trouves, $user_id, $inv_id]);
    return ['nb_ecarts' => $nb_ecarts, 'nb_trouves' => $nb_trouves];
}
