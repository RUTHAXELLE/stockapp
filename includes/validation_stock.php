<?php
// ============================================================
//  includes/validation_stock.php — Validation stock jour (bobines)
// ============================================================
//  Règle (plan « Point 18h → validation superviseur → import EMUCI →
//  Validation Stock Jour », 15/10/2026) : le stock d'un site n'est comparé
//  que lorsque la chaîne est complète — point 18h du jour VALIDÉ par le
//  superviseur ET import EMUCI (OptoTrace) de cette date. Avant, le GSB ne
//  voit qu'une ligne « en attente » avec le statut du point, aucun chiffre.
//
//  La comparaison est déclenchée par le second des deux événements, dans
//  n'importe quel ordre : la validation du point (pages/operations/
//  point_journalier.php) ou l'import (pages/import_emuci.php). L'écran du
//  GSB ne fait plus que lire — il comparait et validait à son ouverture, y
//  compris sur des points seulement soumis (constaté le 15/10/2026 : stock
//  « validé, vous pouvez travailler » sur un point que le superviseur
//  n'avait pas vu). Un nouvel import de la même date relance la
//  comparaison : c'est le seul événement qui puisse encore en changer le
//  résultat, un point validé ne se modifiant ni ne se rejetant plus.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';

// ── Point 18h d'un site pour une date (unique : uq_site_date_type).
function vsm_point_18h(int $site_id, string $date): ?array {
    return db_fetch_one(
        "SELECT id, statut, motif_rejet FROM op_points_journaliers
          WHERE site_id=? AND date_point=? AND type_point='point_18h'",
        [$site_id, $date]
    );
}

// ── Heure de l'import OptoTrace de ce site pour cette date, ou null.
function vsm_import_du(int $site_id, string $date): ?string {
    $d = db_fetch_value(
        "SELECT COALESCE(MAX(created_at), MAX(date_import)) FROM import_optotrace
          WHERE site_id=? AND date_import=?",
        [$site_id, $date]
    );
    return $d ? (string)$d : null;
}

/**
 * Comparaison ERP / EMUCI d'un site pour une date, sur le seul point 18h
 * VALIDÉ — plus de repli sur un point soumis, rejeté, d'un autre type, ni
 * sur les consommations : sans chaîne complète, rien n'est comparé.
 *
 * Côté EMUCI, la quantité de l'import de CETTE date (import_optotrace), et
 * non op_bobines.stock_systeme qui ne garde que le dernier import : un
 * point validé après l'import du lendemain était comparé aux chiffres du
 * lendemain, d'où de faux écarts (essai du 15/10/2026 : écart 30 sans
 * aucun écart réel). Une bobine absente de l'import du jour retombe sur la
 * dernière quantité connue.
 *
 * Retour : nb_ecarts, ecarts, bobines_detail (mêmes clés qu'avant),
 * dernier_import, point (ou null), chaine_complete.
 */
// ── Causes possibles d'un écart bobine (ERP − EMUCI), pour aider le GSB à
//    trancher. Chaque cause porte, quand on sait la chiffrer, sa part signée
//    de l'écart : + quand EMUCI a retiré des films que le point ne déclare
//    pas (ou pas ce jour-là), − quand l'ERP en a retiré qu'EMUCI n'a pas
//    retirés. Indicatif : rien n'est corrigé automatiquement.
function vs_causes_ecart(int $site_id, string $date, array $l): array {
    $bobine_id = (int)$l['bobine_id'];
    $numero    = (string)$l['numero'];
    $lendemain = date('Y-m-d', strtotime($date . ' +1 day'));
    $jj        = date('d/m', strtotime($date));
    $causes    = [];

    // 1. Plaques OptoPlate imprimées ce jour sur la bobine mais posées plus
    //    tard (« reserved ») — et l'inverse : posées ce jour, imprimées avant.
    if (db_colonne_existe('import_optoplate', 'date_impression')) {
        $imp_non_posees = (int)db_fetch_value(
            "SELECT COUNT(*) FROM import_optoplate
             WHERE num_bobine=? AND date_impression=?
               AND (date_installation IS NULL OR date_installation >= ?)
               AND COALESCE(type_desactivation,'') <> 'Incident'", [$numero, $date, $lendemain]);
        if ($imp_non_posees > 0)
            $causes[] = ['films' => $imp_non_posees, 'texte' =>
                "$imp_non_posees plaque(s) imprimée(s) le $jj sur cette bobine mais posée(s) plus tard : EMUCI a déjà retiré le film. "
                . "Le coordinateur les a-t-il déclarées en films utilisés ?"];
        $posees_avant = (int)db_fetch_value(
            "SELECT COUNT(*) FROM import_optoplate
             WHERE num_bobine=? AND date_installation >= ? AND date_installation < ?
               AND date_impression < ?", [$numero, $date, $lendemain, $date]);
        if ($posees_avant > 0)
            $causes[] = ['films' => -$posees_avant, 'texte' =>
                "$posees_avant plaque(s) posée(s) le $jj mais imprimée(s) un jour précédent : EMUCI a retiré leur film ce jour-là. "
                . "Si le coordinateur les déclare aujourd'hui, l'ERP retire le film une 2e fois."];
    }

    // 2. Export OptoTrace pris après la journée : sa quantité inclut déjà la
    //    consommation des jours suivants.
    $last_use = db_fetch_value(
        "SELECT MAX(last_use) FROM import_optotrace WHERE keyname=? AND date_import=?", [$numero, $date]);
    if ($last_use && substr((string)$last_use, 0, 10) >= $lendemain)
        $causes[] = ['films' => null, 'texte' =>
            "L'export OptoTrace du $jj a été pris après la journée (dernière utilisation de la bobine le "
            . date('d/m à H:i', strtotime((string)$last_use)) . ") : la consommation de ces jours-là est déjà retirée côté EMUCI."];

    // 3. Films retirés par des points postérieurs : le restant ERP comparé est
    //    celui d'aujourd'hui, pas celui du soir de la date contrôlée.
    $regle_18h = db_colonne_existe('op_points_journaliers', 'stock_deduit');
    $apres = (int)db_fetch_value(
        "SELECT COALESCE(SUM(fu.films_utilises + fu.films_endommages),0)
         FROM op_films_utilises fu JOIN op_points_journaliers p ON p.id = fu.point_id
         WHERE fu.bobine_id=? AND p.date_point > ? AND "
        . ($regle_18h ? "p.stock_deduit >= 1" : "p.statut <> 'rejete'"), [$bobine_id, $date]);
    if ($apres > 0)
        $causes[] = ['films' => -$apres, 'texte' =>
            "$apres film(s) déjà retiré(s) de cette bobine par des points postérieurs au $jj : le stock ERP comparé est celui d'aujourd'hui."];

    // 4. Films non conformes du jour : déclarés par le coordinateur, ils ne
    //    sortent pas du stock ERP de la bobine (seuls utilisés + endommagés),
    //    alors qu'OptoTrace a pu les retirer. Non chiffré : selon la date, l'ERP
    //    les retirait encore (ancienne règle) ou non.
    if (db_colonne_existe('op_films_utilises', 'non_conformes')) {
        $nc = (int)db_fetch_value(
            "SELECT COALESCE(SUM(fu.non_conformes),0) FROM op_films_utilises fu
             JOIN op_points_journaliers p ON p.id = fu.point_id
             WHERE fu.bobine_id=? AND p.date_point=? AND p.type_point='point_18h'", [$bobine_id, $date]);
        if ($nc > 0)
            $causes[] = ['films' => null, 'texte' =>
                "$nc film(s) non conforme(s) déclaré(s) le $jj sur cette bobine : ils ne sortent pas du stock ERP. "
                . "Si OptoTrace les a retirés, ils expliquent +$nc de l'écart."];
    }

    // 5. Même écart déjà constaté avant et non réajusté : décalage ancien
    //    reporté de jour en jour. Il explique à lui seul tout l'écart.
    $prec = db_fetch_one(
        "SELECT date_constat, ecart, statut FROM ecarts_bobines
         WHERE bobine_id=? AND source='validation_stock' AND date_constat < ?
         ORDER BY date_constat DESC, id DESC LIMIT 1", [$bobine_id, $date]);
    if ($prec && (int)$prec['ecart'] === (int)$l['ecart'] && $prec['statut'] !== 'resolu')
        $causes = [['films' => (int)$l['ecart'], 'texte' =>
            "Le même écart (" . ((int)$prec['ecart'] > 0 ? '+' : '') . (int)$prec['ecart'] . ") a déjà été constaté le "
            . date('d/m', strtotime((string)$prec['date_constat'])) . " sans réajustement : décalage ancien reporté. Un réajustement le soldera."]];

    return $causes;
}

function vsm_calculer(int $site_id, string $date): array {
    $point  = vsm_point_18h($site_id, $date);
    $import = vsm_import_du($site_id, $date);
    $res = [
        'nb_ecarts' => 0, 'ecarts' => [], 'bobines_detail' => [],
        'dernier_import' => $import, 'point' => $point,
        'chaine_complete' => $point && $point['statut'] === 'valide' && $import !== null,
    ];
    if (!$point || $point['statut'] !== 'valide') return $res;

    $nc_sql = db_colonne_existe('op_films_utilises', 'non_conformes')
        ? 'COALESCE(SUM(fu.non_conformes),0)' : '0';
    $lignes = db_fetch_all(
        "SELECT fu.bobine_id,
                SUM(fu.films_utilises)   AS films_utilises,
                SUM(fu.films_endommages) AS films_endommages,
                $nc_sql                  AS films_nc,
                b.numero, b.type_code, b.format, b.films_restants, b.stock_systeme,
                (SELECT io.quantity FROM import_optotrace io
                  WHERE io.keyname = b.numero AND io.date_import = ?
                  ORDER BY io.id DESC LIMIT 1) AS qte_import
         FROM op_films_utilises fu
         JOIN op_bobines b ON b.id = fu.bobine_id
         WHERE fu.point_id = ?
         GROUP BY fu.bobine_id, b.numero, b.type_code, b.format, b.films_restants, b.stock_systeme",
        [$date, (int)$point['id']]
    );
    foreach ($lignes as $b) {
        $util  = (int)$b['films_utilises'];
        $endo  = (int)$b['films_endommages'];
        // op_bobines.films_restants a déjà été déduit à l'enregistrement du
        // point : c'est le stock ERP du soir (le coordinateur ne peut rien
        // saisir de plus tant que ce stock n'est pas validé).
        $erp   = (int)$b['films_restants'];
        $emuci = $b['qte_import'] !== null ? (int)$b['qte_import'] : (int)$b['stock_systeme'];
        $ecart = $import !== null ? $erp - $emuci : 0;
        $ligne = [
            'bobine_id'        => $b['bobine_id'],
            'numero'           => $b['numero'],
            'type_code'        => $b['type_code'] ?? '',
            'format'           => $b['format'] ?? '',
            'stock_debut'      => $erp + $util + $endo,   // non conformes : pas retirés du stock (22/10)
            'films_utilises'   => $util,
            'films_endommages' => $endo,
            'films_restants'   => $erp,
            'stock_systeme'    => $emuci,
            'ecart'            => $ecart,
            'has_ecart'        => $import !== null && $ecart !== 0,
        ];
        if ($ligne['has_ecart']) $ligne['causes'] = vs_causes_ecart($site_id, $date, $ligne);
        $res['bobines_detail'][] = $ligne;
        if ($ligne['has_ecart']) { $res['nb_ecarts']++; $res['ecarts'][] = $ligne; }
    }
    return $res;
}

// ── Où en est un site dont la chaîne n'a pas encore produit de validation :
//    code (pour les actions proposées) et libellé (rubrique « en attente »).
function vsm_attente(?array $point, ?string $import): array {
    if (!$point) return ['code' => 'aucun', 'libelle' => 'Aucun point 18h'];
    $motif = trim((string)($point['motif_rejet'] ?? ''));
    return match ($point['statut']) {
        'brouillon' => $motif !== ''
            ? ['code' => 'brouillon', 'libelle' => "Point 18h corrigé après une demande de modification, mais pas renvoyé (motif : $motif)"]
            : ['code' => 'brouillon', 'libelle' => 'Point 18h en brouillon, pas encore soumis'],
        'en_attente_validation', 'suivi'
                    => ['code' => 'soumis', 'libelle' => 'Point 18h soumis, en attente du superviseur'],
        'rejete'    => ['code' => 'rejete', 'libelle' => 'Modification demandée par le superviseur sur le point 18h' . ($motif !== '' ? " — motif : $motif" : '')],
        'valide'    => $import === null
            ? ['code' => 'import', 'libelle' => 'Point 18h validé, import EMUCI attendu']
            : ['code' => 'relancer', 'libelle' => 'Point 18h validé et import fait, comparaison pas encore lancée'],
        default     => ['code' => 'autre', 'libelle' => 'Point 18h : ' . $point['statut']],
    };
}

// ── Alerte le GSB d'un écart — une seule fois par site et par date (le
//    titre porte le site et la date) : un nouvel import du même jour, une
//    relance ou un rattrapage ne renvoient rien à qui l'a déjà reçue. Le
//    support IT au sous-rôle « gestionnaire bobines », qui a la page de
//    validation, était oublié.
function vsm_alerter_gsb(string $site_nom, string $date, int $nb_ecarts): int {
    $titre = mb_substr("⚠️ Écart stock — $site_nom — " . date('d/m/Y', strtotime($date)), 0, 200);
    $deja  = array_flip(array_map('intval', array_column(db_fetch_all(
        "SELECT DISTINCT user_id FROM notifications WHERE type='stock_validation' AND titre=?", [$titre]
    ), 'user_id')));
    $nb = 0;
    foreach (_notif_users(['gestionnaire_stock_bobines', 'admin', 'superadmin'], null, 'gestionnaire_bobines') as $uid) {
        if (isset($deja[$uid])) continue;
        notif_create('stock_validation', $titre,
            "$site_nom : $nb_ecarts écart(s) entre le stock ERP et l'import EMUCI du "
            . date('d/m/Y', strtotime($date)) . ". Validation requise.",
            $uid, '/pages/validation_stock_matin.php?date=' . $date);
        $nb++;
    }
    return $nb;
}

/**
 * Compare un site pour une date dès que la chaîne est complète, et en
 * tire la conséquence — appelée par la validation du point 18h, par
 * l'import, par la relance du GSB et par le rattrapage. Ne touche jamais à
 * une décision humaine (valide_gsb, autorise_ecart, reajuste, refuse).
 *  · 0 écart : validation automatique, coordinateurs notifiés ;
 *  · écart   : GSB alerté (une fois) ; une validation automatique
 *              antérieure (import précédent du même jour) est annulée.
 * Statut renvoyé : valide_auto, ecart, en_attente, decision_gsb, perime,
 * sans_bobine.
 */
function vsm_evaluer_site(int $site_id, string $date, ?int $user_id, string $origine): array {
    $exist = db_fetch_one(
        "SELECT statut FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
        [$site_id, $date]
    );
    if ($exist && $exist['statut'] !== 'valide_auto') return ['statut' => 'decision_gsb', 'nb_ecarts' => 0];

    $calc = vsm_calculer($site_id, $date);
    if (!$calc['chaine_complete']) return ['statut' => 'en_attente', 'nb_ecarts' => 0];
    if (empty($calc['bobines_detail'])) return ['statut' => 'sans_bobine', 'nb_ecarts' => 0];
    // Un point postérieur a déjà déduit d'autres films : le stock ERP n'est
    // plus celui du soir de $date, une comparaison automatique serait
    // fausse (réimport tardif d'une ancienne date). Laissé au GSB.
    if ((int)db_fetch_value(
            "SELECT COUNT(*) FROM op_points_journaliers WHERE site_id=? AND date_point > ?",
            [$site_id, $date]) > 0) {
        return ['statut' => 'perime', 'nb_ecarts' => $calc['nb_ecarts']];
    }

    $site_nom = (string)db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]);
    $jour     = date('d/m/Y', strtotime($date));
    $coords   = _notif_users(['coordinateur_site'], $site_id);

    if ($calc['nb_ecarts'] === 0) {
        if ($exist) return ['statut' => 'valide_auto', 'nb_ecarts' => 0];
        // id=id : deux déclencheurs simultanés (validation et import) ne
        // doivent pas faire échouer le second sur la clé unique.
        db_query(
            "INSERT INTO validations_stock_matin (site_id,date_validation,statut,nb_ecarts,bobines_snapshot,gsb_user_id,gsb_at)
             VALUES (?,?,'valide_auto',0,?,?,NOW())
             ON DUPLICATE KEY UPDATE id=id",
            [$site_id, $date, json_encode($calc['bobines_detail']), $user_id]
        );
        foreach ($coords as $uid) {
            notif_create('stock_valide', '✅ Stock validé',
                "Votre stock bobines du $jour est validé automatiquement (point 18h validé, import EMUCI conforme). Vous pouvez saisir votre prochain point.",
                $uid, '/pages/validation_stock_matin.php');
        }
        audit_log($user_id, 'CREATE', 'validations_stock_matin', 0,
            "Validation auto stock site:$site_id $date ($origine) — 0 écart");
        return ['statut' => 'valide_auto', 'nb_ecarts' => 0];
    }

    if ($exist) {
        db_query("DELETE FROM validations_stock_matin WHERE site_id=? AND date_validation=? AND statut='valide_auto'",
            [$site_id, $date]);
        foreach ($coords as $uid) {
            notif_create('info', '⚠️ Validation annulée',
                "Votre stock bobines du $jour, validé automatiquement, présente désormais un écart après un nouvel import EMUCI. Il repasse en attente de traitement par le GSB.",
                $uid, '/pages/validation_stock_matin.php');
        }
        audit_log($user_id, 'UPDATE', 'validations_stock_matin', 0,
            "Validation auto annulée site:$site_id $date ($origine) — {$calc['nb_ecarts']} écart(s)");
    }
    $alertes = vsm_alerter_gsb($site_nom, $date, $calc['nb_ecarts']);
    return ['statut' => 'ecart', 'nb_ecarts' => $calc['nb_ecarts'], 'alertes' => $alertes];
}

// ── Déclencheur « import » : chaque site dont le point 18h de la date est
//    validé. Ceux qui ne le sont pas attendent la validation du superviseur,
//    qui lancera la comparaison à son tour.
function vsm_evaluer_import(string $date, ?int $user_id): array {
    $res = [];
    foreach (db_fetch_all(
        "SELECT DISTINCT site_id FROM op_points_journaliers
          WHERE date_point=? AND type_point='point_18h' AND statut='valide'",
        [$date]) as $s) {
        $r = vsm_evaluer_site((int)$s['site_id'], $date, $user_id, 'import');
        $res[] = ['site_id' => (int)$s['site_id']] + $r;
    }
    return $res;
}

// ── Rattrapage (une fois, au déploiement) : pour chaque site, le DERNIER
//    point 18h s'il est validé sans validation de stock — c'est lui qui
//    bloque le coordinateur. Les dates plus anciennes ne sont pas reprises :
//    leur stock ERP a bougé depuis, la comparaison serait fausse.
function vsm_rattrapage(?int $user_id): array {
    $res = [];
    foreach (db_fetch_all(
        "SELECT p.site_id, p.date_point FROM op_points_journaliers p
          WHERE p.type_point='point_18h' AND p.statut='valide'
            AND p.date_point = (SELECT MAX(p2.date_point) FROM op_points_journaliers p2
                                 WHERE p2.site_id=p.site_id AND p2.type_point='point_18h')
            AND NOT EXISTS (SELECT 1 FROM validations_stock_matin v
                             WHERE v.site_id=p.site_id AND v.date_validation=p.date_point)") as $p) {
        $r = vsm_evaluer_site((int)$p['site_id'], (string)$p['date_point'], $user_id, 'rattrapage');
        $res[] = ['site_id' => (int)$p['site_id'], 'date' => (string)$p['date_point']] + $r;
    }
    return $res;
}
