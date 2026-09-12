<?php
// ============================================================
//  pages/validation_stock_matin.php
//  Validation stock bobines matin — GSB / Admin / Superviseur
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();


$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$page_title  = 'Validation Stock Jour';
$active_page = 'validation_stock_matin';

$is_coord   = ($role_slug === 'coordinateur_site');
$site_force = ($is_coord && ($user['site_id'] ?? 0)) ? (int)$user['site_id'] : 0;

$can_valider = can('validation_stock', 'can_create') || is_support_it_with('gestionnaire_bobines');

if (!$can_valider && !$is_coord) {
    http_response_code(403);
    include __DIR__ . '/../templates/403.php';
    exit;
}

$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

// ============================================================
// AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── CALCULER ÉCARTS D'UN SITE
    if ($action === 'calculer_ecarts') {
        try {
            $site_id = (int)($_POST['site_id'] ?? 0);
            $date    = trim($_POST['date'] ?? date('Y-m-d'));
            if (!$site_id) json_response(false, 'Site obligatoire.');

            // ── Source : Point 18h validé par le superviseur site
            // C'est ce point qui résume la journée et sert de référence pour la comparaison
            $pj_entries = db_fetch_all(
                "SELECT fu.bobine_id,
                        SUM(fu.films_utilises)   AS films_utilises,
                        SUM(fu.films_endommages) AS films_endommages,
                        b.numero, b.type_code, b.format, b.serie,
                        b.stock_systeme, b.films_restants, b.statut,
                        pj.id AS point_id, pj.type_point
                 FROM op_films_utilises fu
                 JOIN op_points_journaliers pj ON pj.id = fu.point_id
                 JOIN op_bobines b ON b.id = fu.bobine_id
                 WHERE pj.site_id=?
                   AND pj.date_point=?
                   AND pj.type_point='point_18h'
                   AND pj.statut IN ('valide','en_attente_validation','suivi','rejete')
                 GROUP BY fu.bobine_id, b.numero, b.type_code, b.format, b.serie,
                          b.stock_systeme, b.films_restants, b.statut, pj.id, pj.type_point",
                [$site_id, $date]
            );

            // Fallback 1 : si pas de point_18h, prendre n'importe quel point soumis du jour
            if (empty($pj_entries)) {
                $pj_entries = db_fetch_all(
                    "SELECT fu.bobine_id,
                            SUM(fu.films_utilises)   AS films_utilises,
                            SUM(fu.films_endommages) AS films_endommages,
                            b.numero, b.type_code, b.format, b.serie,
                            b.stock_systeme, b.films_restants, b.statut,
                            ANY_VALUE(pj.id) AS point_id, ANY_VALUE(pj.type_point) AS type_point
                     FROM op_films_utilises fu
                     JOIN op_points_journaliers pj ON pj.id = fu.point_id
                     JOIN op_bobines b ON b.id = fu.bobine_id
                     WHERE pj.site_id=? AND pj.date_point=?
                       AND pj.statut IN ('valide','en_attente_validation','suivi','rejete')
                     GROUP BY fu.bobine_id, b.numero, b.type_code, b.format, b.serie,
                              b.stock_systeme, b.films_restants, b.statut",
                    [$site_id, $date]
                );
            }

            // Fallback 2 : consommations_bobines si aucun point validé
            if (empty($pj_entries)) {
                $pj_entries = db_fetch_all(
                    "SELECT cb.bobine_id,
                            SUM(cb.quantite) AS films_utilises,
                            0 AS films_endommages,
                            b.numero, b.type_code, b.format, b.serie,
                            b.stock_systeme, b.films_restants, b.statut,
                            MAX(cb.stock_avant) AS stock_avant
                     FROM consommations_bobines cb
                     JOIN op_bobines b ON b.id = cb.bobine_id
                     WHERE cb.site_id=? AND cb.date_conso=?
                     GROUP BY cb.bobine_id, b.numero, b.type_code, b.format, b.serie,
                              b.stock_systeme, b.films_restants, b.statut",
                    [$site_id, $date]
                );
            }

            // ── Dernier import OPTOTRACE pour ce site+date
            $dernier_import = db_fetch_value(
                "SELECT MAX(date_import) FROM import_optotrace WHERE site_id=? AND date_import=?",
                [$site_id, $date]
            );

            $ecarts        = [];
            $bobines_detail = [];
            $nb_ecarts     = 0;

            foreach ($pj_entries as $b) {
                $films_utilises  = (int)$b['films_utilises'];
                $films_endommages= (int)($b['films_endommages'] ?? 0);
                $films_total_pj  = $films_utilises + $films_endommages;

                // films_restants = stock physique réel (non écrasé par import EMUCI)
                // stock_systeme  = valeur EMUCI (mise à jour par import, comparaison uniquement)
                // Quand stock_avant est disponible (consommations_bobines), recalcul depuis début journée.
                // Sinon, op_bobines.films_restants a déjà été mis à jour par la validation du PJ
                // (point_journalier.php soustrait les films lors de la validation) → utiliser tel quel.
                if (!empty($b['stock_avant'])) {
                    $stock_debut    = (int)$b['stock_avant'];
                    $films_restants = $stock_debut - $films_total_pj;
                } else {
                    $films_restants = (int)($b['films_restants'] ?? $b['stock_systeme']);
                    $stock_debut    = $films_restants + $films_total_pj;
                }

                // Écart : ERP EMUCI restant vs EMUCI restant (stock_systeme mis par l'import)
                $stock_emuci = (int)($b['stock_systeme'] ?? 0);
                $ecart_val   = $dernier_import ? ($films_restants - $stock_emuci) : 0;
                $has_ecart   = $dernier_import !== null && $ecart_val !== 0;

                $ligne = [
                    'bobine_id'        => $b['bobine_id'],
                    'numero'           => $b['numero'],
                    'type_code'        => $b['type_code'] ?? '',
                    'format'           => $b['format'] ?? '',
                    'stock_debut'      => $stock_debut,
                    'films_utilises'   => $films_utilises,
                    'films_endommages' => $films_endommages,
                    'films_restants'   => $films_restants,
                    'stock_systeme'    => $stock_emuci,
                    'ecart'            => $ecart_val,
                    'has_ecart'        => $has_ecart,
                ];

                $bobines_detail[] = $ligne;
                if ($has_ecart) { $nb_ecarts++; $ecarts[] = $ligne; }
            }

            // ── Validation existante
            $exist = db_fetch_one(
                "SELECT * FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
                [$site_id, $date]
            );

            // Fallback historique : si données live absentes mais snapshot sauvegardé
            if (empty($bobines_detail) && !empty($exist['bobines_snapshot'])) {
                $bobines_detail = json_decode($exist['bobines_snapshot'], true) ?: [];
                $nb_ecarts      = 0;
                $ecarts         = [];
                foreach ($bobines_detail as $bl) {
                    if (!empty($bl['has_ecart'])) { $nb_ecarts++; $ecarts[] = $bl; }
                }
            }

            // ── Décisions déjà prises bobine par bobine pour ce site/date
            //    Sur une bobine tranchée, le recalcul live n'a plus de sens
            //    (un réajustement réaligne le stock de départ) : on réaffiche
            //    les valeurs constatées au moment de la décision.
            $decisions = _decisions_bobines_site($site_id, $date);
            foreach ($bobines_detail as &$_bl) {
                $_dec = $decisions[(int)$_bl['bobine_id']] ?? null;
                $_bl['decision'] = $_dec;
                if ($_dec) {
                    $_bl['stock_systeme']  = $_dec['stock_systeme'];
                    $_bl['films_restants'] = $_dec['films_restants'];
                    $_bl['ecart']          = $_dec['ecart'];
                    $_bl['has_ecart']      = $_dec['ecart'] !== 0;
                }
            }
            unset($_bl);
            // Écarts encore à traiter = écarts sans décision enregistrée.
            // (Après un réajustement, le recalcul fait réapparaître un écart
            //  purement arithmétique sur la bobine : la décision fait foi.)
            $ecarts_restants = array_values(array_filter(
                $ecarts,
                fn($e) => !isset($decisions[(int)$e['bobine_id']])
            ));

            // Corrections soumises par le coordinateur (réponse à un réajustement)
            $coord_corrections = $exist ? db_fetch_all(
                "SELECT cb.bobine_id, cb.films_final, cb.reponse_coord, cb.films_proposes AS films_gsb, b.numero
                 FROM corrections_bobines cb
                 JOIN op_bobines b ON b.id = cb.bobine_id
                 WHERE cb.site_id=? AND cb.date_point=? AND cb.statut='coord_repond'",
                [$site_id, $date]
            ) : [];

            // Corrections déjà validées par le GSB dans les rounds précédents
            $coord_corrections_valides = $exist ? db_fetch_all(
                "SELECT cb.bobine_id, cb.films_final, b.numero
                 FROM corrections_bobines cb
                 JOIN op_bobines b ON b.id = cb.bobine_id
                 WHERE cb.site_id=? AND cb.date_point=? AND cb.statut='valide'",
                [$site_id, $date]
            ) : [];

            // Bobines réajustées sans correction active ni validée (coordinator doit encore soumettre)
            $coord_en_attente = [];
            if ($exist && ($exist['statut'] ?? '') === 'reajuste') {
                $decs_json = json_decode($exist['details_ecarts'] ?? '[]', true) ?: [];
                $reaj_bids = array_column(
                    array_filter($decs_json, fn($d) => ($d['decision'] ?? '') === 'reajuste'),
                    'bobine_id'
                );
                $done_bids = array_map('intval', array_merge(
                    array_column($coord_corrections, 'bobine_id'),
                    array_column($coord_corrections_valides, 'bobine_id')
                ));
                foreach ($reaj_bids as $bid) {
                    if (!in_array((int)$bid, $done_bids)) {
                        $bnum = db_fetch_value("SELECT numero FROM op_bobines WHERE id=?", [(int)$bid]);
                        $coord_en_attente[] = ['bobine_id' => (int)$bid, 'numero' => $bnum ?? "#$bid"];
                    }
                }
            }

            json_response(true, '', [
                'ecarts'                    => $ecarts,
                'ecarts_restants'           => $ecarts_restants,
                'bobines_detail'            => $bobines_detail,
                'nb_ecarts'                 => $nb_ecarts,
                'nb_ecarts_restants'        => count($ecarts_restants),
                'nb_ecarts_traites'         => count($decisions),
                'nb_bobines'                => count($pj_entries) ?: count($bobines_detail),
                'dernier_import'            => $dernier_import,
                'validation'                => $exist,
                'coord_corrections'         => $coord_corrections,
                'coord_corrections_valides' => $coord_corrections_valides,
                'coord_en_attente'          => $coord_en_attente,
            ]);
        } catch (Exception $ex) {
            json_response(false, 'Erreur serveur : ' . $ex->getMessage());
        }
    }

    // ── VALIDER AUTO (écart = 0)
    if ($action === 'valider_auto') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $date    = trim($_POST['date'] ?? date('Y-m-d'));
        $snap    = _calculer_ecarts_site($site_id, $date);
        if (!$snap['dernier_import']) {
            json_response(false,
                'Validation impossible : aucun import OPTOTRACE trouvé pour le ' . $date . '. '
                . 'Effectuez l\'import EMUCI avant de valider le stock.');
        }
        if ($snap['nb_ecarts'] > 0) {
            json_response(false,
                $snap['nb_ecarts'] . ' bobine(s) ont un écart ERP EMUCI / EMUCI. '
                . 'Traitez les écarts avant de valider.');
        }
        $snapshot= json_encode($snap['bobines_detail'] ?: []);
        db_query(
            "INSERT INTO validations_stock_matin (site_id,date_validation,statut,nb_ecarts,bobines_snapshot,gsb_user_id,gsb_at)
             VALUES (?,?,'valide_auto',0,?,?,NOW())
             ON DUPLICATE KEY UPDATE statut='valide_auto',nb_ecarts=0,bobines_snapshot=VALUES(bobines_snapshot),gsb_user_id=VALUES(gsb_user_id),gsb_at=NOW()",
            [$site_id,$date,$snapshot,$user['id']]
        );
        // Notifier coordinateurs du site
        $coords = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",[$site_id]);
        foreach($coords as $c) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$c['id'],'stock_valide','✅ Stock validé',"Votre stock bobines du $date est validé. Vous pouvez commencer votre activité.",
                 '/pages/validation_stock_matin.php']);
        }
        audit_log($user['id'],'CREATE','validations_stock_matin',0,"Validation auto stock site:$site_id $date");
        json_response(true,'Stock validé automatiquement.');
    }

    // ── VALIDATION MANUELLE SANS IMPORT EMUCI (déblocage)
    //    Un site sans import EMUCI de référence pour cette date reste bloqué
    //    indéfiniment sinon : ni auto-validable (0 écart sans référence ne
    //    prouve rien), ni éligible à la décision GSB par bobine (pas d'écart
    //    à traiter), ni à valider_auto (rejette explicitement ce cas). Le
    //    coordinateur ne peut alors plus jamais saisir de nouveau point. Le
    //    GSB assume ici la responsabilité de valider sans référence, motif
    //    obligatoire pour la traçabilité.
    if ($action === 'valider_sans_import') {
        if (!$can_valider) json_response(false, 'Accès refusé.');
        $site_id     = (int)($_POST['site_id'] ?? 0);
        $date        = trim($_POST['date'] ?? date('Y-m-d'));
        $commentaire = trim($_POST['commentaire'] ?? '');
        if (!$site_id) json_response(false, 'Site obligatoire.');
        if (!$commentaire) json_response(false, 'Un commentaire est obligatoire pour valider sans import EMUCI.');

        $snap = _calculer_ecarts_site($site_id, $date);
        if ($snap['dernier_import']) {
            json_response(false, 'Un import EMUCI existe désormais pour ce site à cette date — utilisez la validation normale.');
        }

        $snapshot = json_encode($snap['bobines_detail'] ?: []);
        db_query(
            "INSERT INTO validations_stock_matin (site_id,date_validation,statut,nb_ecarts,bobines_snapshot,gsb_user_id,gsb_at,commentaire)
             VALUES (?,?,'valide_gsb',0,?,?,NOW(),?)
             ON CONFLICT (site_id,date_validation) DO UPDATE SET statut='valide_gsb',nb_ecarts=0,bobines_snapshot=EXCLUDED.bobines_snapshot,gsb_user_id=EXCLUDED.gsb_user_id,gsb_at=NOW(),commentaire=EXCLUDED.commentaire",
            [$site_id, $date, $snapshot, $user['id'], "Validé sans référence EMUCI (aucun import disponible) : $commentaire"]
        );
        $coords = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [$site_id]);
        foreach ($coords as $c) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$c['id'],'stock_valide','✅ Stock validé',
                 "Votre stock bobines du $date est validé (sans import EMUCI disponible). Vous pouvez saisir un nouveau point journalier.",
                 '/pages/validation_stock_matin.php']);
        }
        audit_log($user['id'],'CREATE','validations_stock_matin',0,"Validation manuelle sans import EMUCI site:$site_id $date : $commentaire");
        json_response(true,'Stock validé (sans référence EMUCI). Le coordinateur peut saisir un nouveau point.');
    }

    // ── DÉCISION GSB (autoriser / réajuster / refuser)
    //    Traite les bobines transmises dans ecarts_json : une seule (décision
    //    ligne par ligne dans le tableau) ou plusieurs (traitement groupé).
    //    Le site n'est validé que lorsque plus aucune bobine en écart n'attend
    //    de décision — c'est à ce moment que le coordinateur est notifié.
    if ($action === 'decision_gsb') {
        if (!$can_valider) json_response(false,'Accès refusé.');
        $site_id    = (int)($_POST['site_id'] ?? 0);
        $date       = trim($_POST['date'] ?? date('Y-m-d'));
        $decision   = trim($_POST['decision'] ?? '');
        $commentaire= trim($_POST['commentaire'] ?? '');
        $ecarts_json= $_POST['ecarts_json'] ?? '[]';

        if (!$site_id) json_response(false,'Site obligatoire.');
        if (!in_array($decision,['autorise_ecart','reajuste','refuse']))
            json_response(false,'Décision invalide.');
        if (!$commentaire) json_response(false,'Le commentaire est obligatoire.');

        $ecarts = json_decode($ecarts_json, true) ?: [];
        if (empty($ecarts)) json_response(false,'Aucune bobine sélectionnée pour cette décision.');

        // Sécurité : n'accepter que des bobines réellement rattachées au site
        $ids_site = array_map(
            'intval',
            array_column(db_fetch_all("SELECT id FROM op_bobines WHERE site_id=?", [$site_id]), 'id')
        );
        $ecarts = array_values(array_filter(
            $ecarts,
            fn($e) => in_array((int)($e['bobine_id'] ?? 0), $ids_site, true)
        ));
        if (empty($ecarts)) json_response(false,'Bobine(s) introuvable(s) sur ce site.');

        db_begin();
        try {
            // Si réajustement : aligner films_restants sur la valeur EMUCI (stock_systeme)
            if ($decision === 'reajuste') {
                foreach ($ecarts as $e) {
                    $stock_av = max(0, (int)$e['films_restants']); // ERP EMUCI avant ajust.
                    $nouveau  = max(0, (int)$e['stock_systeme']);   // cible = valeur EMUCI
                    $diff     = $nouveau - $stock_av;
                    db_query("UPDATE op_bobines SET films_restants=? WHERE id=?",
                        [$nouveau, $e['bobine_id']]);
                    db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                              VALUES (?,?,?,?,?,?,?)",
                        [$e['bobine_id'],'ajustement_gsb',$diff,$stock_av,$nouveau,
                         "Réajustement GSB matin $date (ERP EMUCI $stock_av → EMUCI $nouveau) : $commentaire",
                         $user['id']]);
                }
            }

            // Si refus : créer une demande de correction pour chaque bobine traitée
            if ($decision === 'refuse') {
                foreach ($ecarts as $e) {
                    // Annuler la demande précédente encore en attente pour CETTE bobine
                    // (les autres bobines du site gardent la leur)
                    db_query(
                        "UPDATE demandes_correction_saisie SET statut='valide'
                         WHERE bobine_id=? AND site_id=? AND date_cible=? AND statut='en_attente'",
                        [$e['bobine_id'], $site_id, $date]
                    );
                    db_query(
                        "INSERT INTO demandes_correction_saisie
                         (bobine_id, site_id, gsb_id, date_cible, films_pj, films_emuci, ecart, notes_gsb)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $e['bobine_id'],
                            $site_id,
                            $user['id'],
                            $date,
                            (int)$e['films_restants'],  // ERP EMUCI restant calculé
                            (int)$e['stock_systeme'],   // EMUCI/OPTOTRACE restant
                            (int)$e['ecart'],
                            $commentaire,
                        ]
                    );
                }
            }

            // ── Enregistrement dans ecarts_bobines
            //    La ligne la plus récente d'une bobine fait foi : une révision
            //    ajoute une ligne sans effacer l'historique des décisions.
            $statut_ecart = match($decision) {
                'reajuste'       => 'resolu',
                'autorise_ecart' => 'ignore',
                default          => 'ouvert',
            };
            foreach ($ecarts as $e) {
                if ($statut_ecart === 'ouvert') {
                    db_query(
                        "INSERT INTO ecarts_bobines
                         (bobine_id, date_constat, stock_systeme, stock_physique, ecart, motif, source, statut, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, 'validation_stock', 'ouvert', ?)",
                        [$e['bobine_id'], $date, (int)$e['stock_systeme'],
                         (int)($e['films_restants'] ?? 0), (int)$e['ecart'], $commentaire, $user['id']]
                    );
                } else {
                    $notes_res = $statut_ecart === 'resolu'
                        ? 'Réajusté : ERP EMUCI ' . (int)($e['films_restants'] ?? 0) . ' → EMUCI ' . (int)$e['stock_systeme']
                        : 'Écart autorisé : ' . $commentaire;
                    db_query(
                        "INSERT INTO ecarts_bobines
                         (bobine_id, date_constat, stock_systeme, stock_physique, ecart, motif, source, statut, resolu_at, resolu_par, resolution_notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, 'validation_stock', ?, NOW(), ?, ?, ?)",
                        [$e['bobine_id'], $date, (int)$e['stock_systeme'],
                         (int)($e['films_restants'] ?? 0), (int)$e['ecart'], $commentaire,
                         $statut_ecart, $user['id'], $notes_res, $user['id']]
                    );
                }
            }

            $nb_traite = count($ecarts);
            audit_log($user['id'],'UPDATE','ecarts_bobines',0,
                "$decision $nb_traite bobine(s) site:$site_id $date — $commentaire");

            // ── Reste-t-il des bobines en écart sans décision ?
            $decisions = _decisions_bobines_site($site_id, $date);
            $snap      = _calculer_ecarts_site($site_id, $date);
            $restants  = array_values(array_filter(
                $snap['ecarts'],
                fn($x) => !isset($decisions[(int)$x['bobine_id']])
            ));

            if (!empty($restants)) {
                // Traitement partiel : le site reste en attente, pas de notification
                db_commit();
                json_response(true,
                    "$nb_traite bobine(s) traitée(s). " . count($restants) . ' bobine(s) restante(s) à traiter.',
                    ['site_complet' => false, 'nb_restants' => count($restants)]
                );
            }

            // ── Toutes les bobines en écart ont une décision → validation du site
            $vals   = array_column($decisions, 'decision');
            $n_reaj = count(array_keys($vals, 'reajuste'));
            $n_auto = count(array_keys($vals, 'autorise_ecart'));
            $n_ref  = count(array_keys($vals, 'refuse'));

            // Statut du site : le plus contraignant l'emporte
            $statut_site = $n_ref > 0 ? 'refuse' : ($n_auto > 0 ? 'autorise_ecart' : 'reajuste');

            $parts = [];
            if ($n_reaj) $parts[] = "$n_reaj réajustée(s)";
            if ($n_auto) $parts[] = "$n_auto écart(s) autorisé(s)";
            if ($n_ref)  $parts[] = "$n_ref bloquée(s)";
            $mixte       = count(array_unique($vals)) > 1 || count($decisions) > $nb_traite;
            $comment_site= $mixte ? implode(' · ', $parts) . " — $commentaire" : $commentaire;

            // Détail des décisions (valeurs constatées au moment de la décision)
            $details_json = json_encode(array_values($decisions));
            // Snapshot : ce que le GSB avait sous les yeux, à défaut le recalcul
            $snap_client  = json_decode($_POST['bobines_json'] ?? '', true);
            $bobines_json = json_encode(
                is_array($snap_client) && $snap_client ? $snap_client : ($snap['bobines_detail'] ?: [])
            );

            db_query(
                "INSERT INTO validations_stock_matin (site_id,date_validation,statut,nb_ecarts,details_ecarts,bobines_snapshot,gsb_user_id,gsb_at,commentaire)
                 VALUES (?,?,?,?,?,?,?,NOW(),?)
                 ON DUPLICATE KEY UPDATE statut=VALUES(statut),nb_ecarts=VALUES(nb_ecarts),
                 details_ecarts=VALUES(details_ecarts),bobines_snapshot=VALUES(bobines_snapshot),
                 gsb_user_id=VALUES(gsb_user_id),gsb_at=NOW(),commentaire=VALUES(commentaire)",
                [$site_id,$date,$statut_site,count($decisions),$details_json,$bobines_json,$user['id'],$comment_site]
            );

            // Notifier coordinateurs
            $coords = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",[$site_id]);
            $msg_map = [
                'autorise_ecart' => "⚠️ Votre stock du $date présente des écarts mais vous êtes autorisé à travailler. Commentaire GSB : $comment_site",
                'reajuste'       => "🔄 Votre stock du $date a été réajusté (ERP EMUCI corrigé). Veuillez corriger votre saisie et soumettre un nouveau point journalier pour finaliser la validation. Motif : $comment_site",
                'refuse'         => "❌ Votre activité du $date est bloquée. Corrigez vos données et soumettez un nouveau point journalier. Commentaire : $comment_site",
            ];
            $titre_map = [
                'autorise_ecart' => '⚠️ Écart autorisé',
                'reajuste'       => '🔄 Stock réajusté — nouvelle saisie requise',
                'refuse'         => '❌ Activité bloquée — correction requise',
            ];
            foreach($coords as $c) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$c['id'],'stock_validation',$titre_map[$statut_site],$msg_map[$statut_site],
                     '/pages/validation_stock_matin.php']);
            }

            audit_log($user['id'],'UPDATE','validations_stock_matin',0,
                "$statut_site stock site:$site_id $date — $comment_site");
            db_commit();
            json_response(true, match($statut_site){
                'autorise_ecart'=>'Toutes les bobines sont traitées. Écart autorisé : le coordinateur peut travailler.',
                'reajuste'      =>'Toutes les bobines sont traitées. Stock réajusté. Le coordinateur doit soumettre un nouveau point journalier pour finaliser la validation.',
                'refuse'        =>'Toutes les bobines sont traitées. Coordinateur bloqué. En attente de correction.',
            }, ['site_complet' => true, 'nb_restants' => 0]);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── GSB VALIDE LES CORRECTIONS DU COORDINATEUR
    if ($action === 'valider_corrections_coord') {
        if (!$can_valider) json_response(false, 'Accès refusé.');
        $site_id     = (int)($_POST['site_id'] ?? 0);
        $date        = trim($_POST['date'] ?? date('Y-m-d'));
        $commentaire = trim($_POST['commentaire'] ?? '');

        $corrections = db_fetch_all(
            "SELECT cb.*, b.numero FROM corrections_bobines cb
             JOIN op_bobines b ON b.id = cb.bobine_id
             WHERE cb.site_id=? AND cb.date_point=? AND cb.statut='coord_repond'",
            [$site_id, $date]
        );
        if (empty($corrections)) json_response(false, 'Aucune correction de coordinateur trouvée.');

        db_begin();
        try {
            foreach ($corrections as $c) {
                $stock_av = (int)db_fetch_value("SELECT films_restants FROM op_bobines WHERE id=?", [$c['bobine_id']]);
                $nouveau  = (int)$c['films_final'];
                $diff     = $nouveau - $stock_av;
                db_query("UPDATE op_bobines SET films_restants=? WHERE id=?", [$nouveau, $c['bobine_id']]);
                if ($diff !== 0) {
                    db_query(
                        "INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                         VALUES (?,?,?,?,?,?,?)",
                        [$c['bobine_id'],'correction_coord',$diff,$stock_av,$nouveau,
                         "Correction coordinateur validée par GSB ($date) : ".($c['reponse_coord']??''),$user['id']]
                    );
                }
                db_query("UPDATE corrections_bobines SET statut='valide',traite_at=NOW() WHERE id=?", [(int)$c['id']]);
            }
            $final_comment = ($commentaire ?: 'Corrections coordinateur validées')
                           . ' (' . count($corrections) . ' bobine(s) corrigée(s))';
            db_query(
                "UPDATE validations_stock_matin SET statut='valide_gsb',commentaire=?,gsb_user_id=?,gsb_at=NOW()
                 WHERE site_id=? AND date_validation=?",
                [$final_comment,$user['id'],$site_id,$date]
            );
            $coords = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",[$site_id]);
            foreach ($coords as $c_user) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$c_user['id'],'stock_valide','✅ Stock validé',
                     "Vos corrections ont été acceptées par le gestionnaire. Votre stock du $date est validé.",
                     '/pages/validation_stock_matin.php']);
            }
            audit_log($user['id'],'UPDATE','validations_stock_matin',0,"Validation corrections coord site:$site_id $date");
            db_commit();
            json_response(true,'Corrections du coordinateur validées. Stock confirmé.');
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── GSB DEMANDE DE NOUVELLES MODIFICATIONS AU COORDINATEUR
    if ($action === 'demander_nouvelles_modifs') {
        if (!$can_valider) json_response(false, 'Accès refusé.');
        $site_id     = (int)($_POST['site_id'] ?? 0);
        $date        = trim($_POST['date'] ?? date('Y-m-d'));
        $commentaire = trim($_POST['commentaire'] ?? '');
        if (!$commentaire) json_response(false, 'Un commentaire explicatif est obligatoire.');

        // Annuler les corrections en attente pour permettre une nouvelle soumission
        db_query(
            "UPDATE corrections_bobines SET statut='annule' WHERE site_id=? AND date_point=? AND statut='coord_repond'",
            [$site_id, $date]
        );
        db_query(
            "UPDATE validations_stock_matin SET commentaire=?,gsb_at=NOW() WHERE site_id=? AND date_validation=?",
            ["Nouvelles modifications demandées : $commentaire",$site_id,$date]
        );
        $coords = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",[$site_id]);
        $gsb_nom = trim(($user['prenom']??'').' '.($user['nom']??''));
        foreach ($coords as $c_user) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$c_user['id'],'stock_validation','🔄 Nouvelles corrections demandées',
                 "Le gestionnaire $gsb_nom demande de nouvelles corrections pour votre site ($date). Motif : $commentaire",
                 '/pages/validation_stock_matin.php']);
        }
        audit_log($user['id'],'UPDATE','validations_stock_matin',0,"Nouvelles modifs demandées site:$site_id $date — $commentaire");
        json_response(true,'Demande de correction envoyée au coordinateur.');
    }

    // ── GSB : DÉCISIONS PAR BOBINE sur les corrections du coordinateur
    if ($action === 'traiter_corrections_coord') {
        if (!$can_valider) json_response(false, 'Accès refusé.');
        $site_id       = (int)($_POST['site_id'] ?? 0);
        $date          = trim($_POST['date'] ?? date('Y-m-d'));
        $decisions_raw = $_POST['decisions_json'] ?? '[]';
        $decisions     = json_decode($decisions_raw, true) ?: [];
        if (!$site_id || empty($decisions)) json_response(false, 'Données manquantes.');

        db_begin();
        try {
            $nb_valides  = 0;
            $nb_refuses  = 0;
            $bob_refuses = [];

            foreach ($decisions as $d) {
                $bobine_id  = (int)($d['bobine_id'] ?? 0);
                $action_bob = $d['action'] ?? '';
                $comment    = trim($d['commentaire'] ?? '');
                if (!$bobine_id || !in_array($action_bob, ['valider','refuser'])) continue;

                $corr = db_fetch_one(
                    "SELECT * FROM corrections_bobines WHERE site_id=? AND date_point=? AND bobine_id=? AND statut='coord_repond'",
                    [$site_id, $date, $bobine_id]
                );
                if (!$corr) continue;

                if ($action_bob === 'valider') {
                    $stock_av = (int)db_fetch_value("SELECT films_restants FROM op_bobines WHERE id=?", [$bobine_id]);
                    $nouveau  = (int)$corr['films_final'];
                    $diff     = $nouveau - $stock_av;
                    // Appliquer la valeur corrigée au stock physique
                    db_query("UPDATE op_bobines SET films_restants=? WHERE id=?", [$nouveau, $bobine_id]);
                    if ($diff !== 0) {
                        db_query(
                            "INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                             VALUES (?,?,?,?,?,?,?)",
                            [$bobine_id,'correction_coord',$diff,$stock_av,$nouveau,
                             "Correction coordinateur validée ($date) : ".($corr['reponse_coord']??''),$user['id']]
                        );
                    }
                    // Réconcilier ecarts_bobines avec les nouvelles valeurs pour que l'affichage
                    // montre le stock à jour (ecart=0, films_restants=nouveau) — sans cette entrée,
                    // _decisions_bobines_site renverrait encore les anciennes valeurs de l'écart initial.
                    db_query(
                        "INSERT INTO ecarts_bobines
                         (bobine_id,date_constat,stock_systeme,stock_physique,ecart,motif,source,statut,resolu_at,resolu_par,resolution_notes,created_by)
                         VALUES (?,?,?,?,0,?,'validation_stock','resolu',NOW(),?,'Correction coordinateur appliquée',?)",
                        [$bobine_id, $date, $nouveau, $nouveau,
                         "Stock réconcilié : $nouveau films (correction coord. validée par GSB)",
                         $user['id'], $user['id']]
                    );
                    db_query("UPDATE corrections_bobines SET statut='valide',traite_at=NOW() WHERE id=?", [(int)$corr['id']]);
                    $nb_valides++;
                } else {
                    // refuser → annuler cette correction, le coord devra re-soumettre pour cette bobine
                    db_query(
                        "UPDATE corrections_bobines SET statut='annule',traite_at=NOW() WHERE id=?",
                        [(int)$corr['id']]
                    );
                    $bob_num = db_fetch_value("SELECT numero FROM op_bobines WHERE id=?", [$bobine_id]);
                    $bob_refuses[] = $bob_num ?: "bobine #$bobine_id";
                    $nb_refuses++;
                }
            }

            if ($nb_refuses === 0) {
                // Vérifier que TOUTES les bobines réajustées ont maintenant une correction validée
                $details_raw = db_fetch_value(
                    "SELECT details_ecarts FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
                    [$site_id, $date]
                );
                $all_decs_chk  = json_decode($details_raw ?? '[]', true) ?: [];
                $reaj_bids_chk = array_column(
                    array_filter($all_decs_chk, fn($d) => ($d['decision'] ?? '') === 'reajuste'),
                    'bobine_id'
                );
                $all_reajuste_done = true;
                if (!empty($reaj_bids_chk)) {
                    $ph_chk = implode(',', array_fill(0, count($reaj_bids_chk), '?'));
                    $nb_val_total = (int)db_fetch_value(
                        "SELECT COUNT(DISTINCT bobine_id) FROM corrections_bobines WHERE site_id=? AND date_point=? AND statut='valide' AND bobine_id IN ($ph_chk)",
                        array_merge([$site_id, $date], $reaj_bids_chk)
                    );
                    $all_reajuste_done = ($nb_val_total >= count($reaj_bids_chk));
                }

                if ($all_reajuste_done) {
                    // Rafraîchir details_ecarts avec les valeurs réconciliées (stock_physique = nouveau)
                    // afin que les prochains "Demander modif" et l'affichage du détail soient cohérents
                    $new_decs_map = _decisions_bobines_site($site_id, $date);
                    db_query(
                        "UPDATE validations_stock_matin SET statut='valide_gsb',nb_ecarts=0,commentaire=?,gsb_user_id=?,gsb_at=NOW(),details_ecarts=?
                         WHERE site_id=? AND date_validation=?",
                        ["Corrections coordinateur validées ($nb_valides bobine(s))",$user['id'],
                         json_encode(array_values($new_decs_map)),$site_id,$date]
                    );
                    $msg_coord = "✅ Vos corrections ont été acceptées. Le stock du $date est validé.";
                    $titre_coord = '✅ Stock validé';
                } else {
                    // Ce round est ok mais d'autres bobines attendent encore le coordinateur
                    db_query(
                        "UPDATE validations_stock_matin SET commentaire=?,gsb_at=NOW()
                         WHERE site_id=? AND date_validation=?",
                        ["$nb_valides correction(s) validée(s) — d'autres bobines attendent encore",$site_id,$date]
                    );
                    $msg_coord = "✅ $nb_valides correction(s) validée(s). D'autres bobines de votre site nécessitent encore une correction de votre part.";
                    $titre_coord = '🔄 Corrections partiellement traitées';
                }
            } else {
                // Corrections partielles → site reste en réajustement pour les bobines refusées
                db_query(
                    "UPDATE validations_stock_matin SET commentaire=?,gsb_at=NOW()
                     WHERE site_id=? AND date_validation=?",
                    ["Corrections partielles : $nb_valides validée(s), $nb_refuses refusée(s) — ".implode(', ', $bob_refuses),$site_id,$date]
                );
                $msg_coord = "🔄 Certaines corrections ont été refusées (".implode(', ', $bob_refuses)."). Veuillez re-soumettre vos valeurs pour ces bobines.";
                $titre_coord = '🔄 Corrections partiellement refusées';
            }

            $coords = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",
                [$site_id]
            );
            foreach ($coords as $cu) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$cu['id'],'info',$titre_coord,$msg_coord,'/pages/validation_stock_matin.php']);
            }
            audit_log($user['id'],'UPDATE','validations_stock_matin',0,
                "traiter_corrections_coord site:$site_id $date — validées:$nb_valides refusées:$nb_refuses");
            db_commit();

            $msg_retour = $nb_refuses === 0
                ? "Toutes les corrections validées. Stock confirmé."
                : "$nb_valides correction(s) validée(s), $nb_refuses refusée(s). Le coordinateur doit re-soumettre les bobines refusées.";
            json_response(true, $msg_retour);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── CORRECTION COORDINATEUR : réponse à un réajustement GSB
    if ($action === 'coord_repond_reajust') {
        if (!$is_coord) json_response(false, 'Accès refusé.');
        $site_id     = (int)($_POST['site_id'] ?? 0);
        $date        = trim($_POST['date'] ?? date('Y-m-d'));
        $bobines_raw = $_POST['bobines_json'] ?? '[]';
        $bobines     = json_decode($bobines_raw, true) ?: [];
        if (!$site_id || empty($bobines)) json_response(false, 'Données manquantes.');

        db_begin();
        try {
            foreach ($bobines as $b) {
                $bobine_id     = (int)($b['bobine_id'] ?? 0);
                $films_correct = (int)($b['films_correct'] ?? 0);
                $motif_coord   = trim($b['motif_coord'] ?? '');
                if (!$bobine_id) continue;
                if (!$motif_coord) json_response(false, 'Le motif est obligatoire pour chaque bobine.');

                // Ne pas re-soumettre une bobine déjà validée par le GSB
                if ((int)db_fetch_value("SELECT COUNT(*) FROM corrections_bobines WHERE site_id=? AND date_point=? AND bobine_id=? AND statut='valide'", [$site_id, $date, $bobine_id])) continue;

                // Trouver le point journalier de cette bobine
                $point_id = (int)db_fetch_value(
                    "SELECT pj.id FROM op_points_journaliers pj
                     JOIN op_films_utilises fu ON fu.point_id = pj.id
                     WHERE fu.bobine_id=? AND pj.site_id=? AND pj.date_point=?
                     ORDER BY pj.id DESC LIMIT 1",
                    [$bobine_id, $site_id, $date]
                );
                if (!$point_id) json_response(false, "Point journalier introuvable pour une bobine.");

                // Récupérer valeurs GSB depuis ecarts_bobines
                $ecart_row = db_fetch_one(
                    "SELECT stock_physique, stock_systeme, motif FROM ecarts_bobines
                     WHERE bobine_id=? AND date_constat=? AND source='validation_stock' AND statut='resolu'
                     ORDER BY id DESC LIMIT 1",
                    [$bobine_id, $date]
                );
                $films_original = $ecart_row ? (int)$ecart_row['stock_physique'] : 0;
                $films_proposes = $ecart_row ? (int)$ecart_row['stock_systeme']  : $films_correct;
                $motif_gsb      = $ecart_row ? ($ecart_row['motif'] ?? '')       : '';

                // Mettre à jour ou créer l'entrée corrections_bobines
                $existing = db_fetch_value(
                    "SELECT id FROM corrections_bobines WHERE bobine_id=? AND site_id=? AND date_point=? AND statut='coord_repond'",
                    [$bobine_id, $site_id, $date]
                );
                if ($existing) {
                    db_query(
                        "UPDATE corrections_bobines
                         SET coord_id=?, reponse_coord=?, films_final=?, traite_at=NOW()
                         WHERE id=?",
                        [$user['id'], $motif_coord, $films_correct, (int)$existing]
                    );
                } else {
                    db_query(
                        "INSERT INTO corrections_bobines
                         (point_id,bobine_id,site_id,date_point,films_original,films_proposes,motif_gsb,gsb_id,statut,coord_id,reponse_coord,films_final,traite_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                        [$point_id,$bobine_id,$site_id,$date,$films_original,$films_proposes,$motif_gsb,
                         $user['id'],'coord_repond',$user['id'],$motif_coord,$films_correct]
                    );
                }
            }

            // Notifier les GSB
            $gsb_list = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE r.slug IN ('gsb','admin','superadmin') AND u.actif=1"
            );
            $site_nom  = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]) ?? "site #$site_id";
            $coord_nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
            foreach ($gsb_list as $g) {
                db_query(
                    "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$g['id'],'stock_validation','🔄 Correction coordinateur disponible',
                     "Le coordinateur $coord_nom a fourni les valeurs corrigées pour le site $site_nom ($date). À réviser avant re-validation.",
                     '/pages/validation_stock_matin.php']
                );
            }
            audit_log($user['id'],'UPDATE','corrections_bobines',0,"Réponse coord réajust site:$site_id $date");
            db_commit();
            json_response(true, 'Votre correction a été envoyée au gestionnaire. Il pourra re-valider avec ces données.');
        } catch(Exception $e) { db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── LISTE COMPLÈTE DES BOBINES D'UN SITE (pour détail)
    if ($action === 'get_bobines_detail') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        if (!$site_id) json_response(false,'Site obligatoire.');
        $bobines = db_fetch_all(
            "SELECT b.id, b.numero, b.type_code, b.serie,
                    b.films_restants, b.stock_systeme, b.statut, b.format
             FROM op_bobines b
             WHERE b.site_id=? AND b.statut IN ('en_cours','en_stock')
             ORDER BY b.serie, b.numero",
            [$site_id]
        );
        json_response(true, '', $bobines);
    }

    // ── DEMANDE DE CORRECTION PAR BOBINE (aller-retour GSB ↔ Coordinateur)
    if ($action === 'demander_correction_bobine') {
        if (!$can_valider) json_response(false, 'Accès refusé.');
        $bobine_id      = (int)($_POST['bobine_id']      ?? 0);
        $site_id        = (int)($_POST['site_id']        ?? 0);
        $date           = trim($_POST['date']            ?? date('Y-m-d'));
        $notes          = trim($_POST['notes_gsb']       ?? '');
        $films_pj       = (int)($_POST['films_pj']       ?? 0);
        $films_proposes = (int)($_POST['films_proposes'] ?? 0);
        if (!$bobine_id || !$site_id) json_response(false, 'Données manquantes.');
        if (!$notes) json_response(false, 'Le motif est obligatoire.');

        try {
            $bobine_info = db_fetch_one(
                "SELECT numero, type_code, format FROM op_bobines WHERE id=?",
                [$bobine_id]
            );
            $bobine_num = $bobine_info['numero'] ?? "bobine #$bobine_id";

            db_begin();

            // 1. Remettre le site en réajustement et mettre à jour details_ecarts
            $current_val = db_fetch_one(
                "SELECT statut, details_ecarts FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
                [$site_id, $date]
            );

            if (!$current_val) json_response(false, 'Aucune validation trouvée pour ce site à cette date.');

            // Mettre à jour details_ecarts pour que la bobine apparaisse dans le formulaire coordinateur
            $all_decs = json_decode($current_val['details_ecarts'] ?? '[]', true) ?: [];
            $dec_map  = [];
            foreach ($all_decs as $d) $dec_map[(int)($d['bobine_id'] ?? 0)] = $d;
            $ecart_val = $films_pj - $films_proposes;
            $dec_map[$bobine_id] = [
                'bobine_id'      => $bobine_id,
                'numero'         => $bobine_num,
                'type_code'      => $bobine_info['type_code'] ?? '',
                'format'         => $bobine_info['format'] ?? '',
                'stock_systeme'  => $films_proposes,
                'films_restants' => $films_pj,
                'ecart'          => $ecart_val,
                'decision'       => 'reajuste',
                'commentaire'    => $notes,
                'par'            => 'GSB',
                'at'             => date('Y-m-d H:i:s'),
            ];

            // Annuler les corrections précédentes (valide/coord_repond) pour cette bobine
            // afin que le coordinateur puisse re-soumettre
            db_query(
                "UPDATE corrections_bobines SET statut='annule', traite_at=NOW()
                 WHERE site_id=? AND date_point=? AND bobine_id=? AND statut IN ('valide','coord_repond','en_attente')",
                [$site_id, $date, $bobine_id]
            );

            // Recompter les bobines réajustées pour le statut (au moins cette bobine)
            $n_reaj_total = count(array_filter($dec_map, fn($d) => ($d['decision'] ?? '') === 'reajuste'));
            $n_ref_total  = count(array_filter($dec_map, fn($d) => ($d['decision'] ?? '') === 'refuse'));
            $statut_new   = $n_ref_total > 0 ? 'refuse' : 'reajuste';

            db_query(
                "UPDATE validations_stock_matin SET statut=?, details_ecarts=?, commentaire=?, gsb_user_id=?, gsb_at=NOW()
                 WHERE site_id=? AND date_validation=?",
                [$statut_new, json_encode(array_values($dec_map)),
                 "Nouvelle correction demandée sur bobine $bobine_num : $notes",
                 $user['id'], $site_id, $date]
            );

            // Traçabilité dans ecarts_bobines
            db_query(
                "INSERT INTO ecarts_bobines
                 (bobine_id, date_constat, stock_systeme, stock_physique, ecart, motif, source, statut, resolu_at, resolu_par, resolution_notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'validation_stock', 'resolu', NOW(), ?, ?, ?)",
                [$bobine_id, $date, $films_proposes, $films_pj, $ecart_val, $notes,
                 $user['id'], "Demande correction GSB : $notes", $user['id']]
            );

            // 2. Notifier le(s) coordinateur(s) → lien vers validation_stock_matin
            $gsb_nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
            $coords  = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1",
                [$site_id]
            );
            $notif_sent = 0;
            foreach ($coords as $c) {
                db_query(
                    "INSERT INTO notifications (user_id, type, titre, message, lien) VALUES (?,?,?,?,?)",
                    [$c['id'], 'info', '🔄 Correction de stock requise',
                     "Le gestionnaire $gsb_nom demande une correction sur la bobine $bobine_num ($date). Valeur ERP EMUCI : $films_pj → Valeur attendue : $films_proposes. Motif : $notes",
                     '/pages/validation_stock_matin.php']
                );
                $notif_sent++;
            }

            audit_log($user['id'], 'UPDATE', 'validations_stock_matin', 0,
                "Correction demandée bobine:$bobine_id site:$site_id date:$date films_pj:$films_pj proposes:$films_proposes");
            db_commit();

            $msg = $notif_sent > 0
                ? 'Demande envoyée. Le coordinateur a été notifié et doit soumettre les valeurs correctes.'
                : 'Demande enregistrée. Aucun coordinateur actif trouvé pour ce site.';
            json_response(true, $msg);
        } catch (Exception $ex) {
            db_rollback();
            json_response(false, $ex->getMessage());
        }
    }

    json_response(false,'Action inconnue.');
}

// ============================================================
// DONNÉES
// ============================================================
$f_date = trim($_GET['date'] ?? date('Y-m-d'));
$f_site = (int)($_GET['site'] ?? 0);

// ── Décisions GSB déjà enregistrées bobine par bobine pour un site/date.
//    Une bobine peut être traitée plusieurs fois (révision) : la ligne la plus
//    récente d'ecarts_bobines fait foi. Retourne une map bobine_id => décision.
function _decisions_bobines_site(int $site_id, string $date): array {
    $rows = db_fetch_all(
        "SELECT bobine_id, statut, motif, stock_systeme, stock_physique, ecart, created_at,
                numero, type_code, format, par_nom
         FROM (
             SELECT e.bobine_id, e.statut, e.motif, e.stock_systeme, e.stock_physique, e.ecart, e.created_at,
                    b.numero, b.type_code, b.format,
                    CONCAT(u.prenom,' ',u.nom) AS par_nom,
                    ROW_NUMBER() OVER (PARTITION BY e.bobine_id ORDER BY e.id DESC) AS rn
             FROM ecarts_bobines e
             JOIN op_bobines b ON b.id = e.bobine_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.source = 'validation_stock' AND e.date_constat = ? AND b.site_id = ?
         ) t
         WHERE rn = 1
         ORDER BY bobine_id",
        [$date, $site_id]
    );
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['bobine_id']] = [
            'bobine_id'      => (int)$r['bobine_id'],
            'numero'         => $r['numero'],
            'type_code'      => $r['type_code'] ?? '',
            'format'         => $r['format'] ?? '',
            'stock_systeme'  => (int)$r['stock_systeme'],
            'films_restants' => (int)$r['stock_physique'],
            'ecart'          => (int)$r['ecart'],
            'decision'       => match($r['statut']) {
                'resolu' => 'reajuste',
                'ignore' => 'autorise_ecart',
                default  => 'refuse',
            },
            'commentaire'    => $r['motif'] ?? '',
            'par'            => trim($r['par_nom'] ?? '') ?: 'GSB',
            'at'             => $r['created_at'],
        ];
    }
    return $map;
}

// ── Fonction réutilisable : calculer les écarts d'un site pour une date
function _calculer_ecarts_site(int $site_id, string $date): array {
    $pj_entries = db_fetch_all(
        "SELECT fu.bobine_id,
                SUM(fu.films_utilises)   AS films_utilises,
                SUM(fu.films_endommages) AS films_endommages,
                b.numero, b.type_code, b.format, b.serie,
                b.stock_systeme, b.films_restants, b.statut,
                pj.id AS point_id, pj.type_point
         FROM op_films_utilises fu
         JOIN op_points_journaliers pj ON pj.id = fu.point_id
         JOIN op_bobines b ON b.id = fu.bobine_id
         WHERE pj.site_id=? AND pj.date_point=?
           AND pj.type_point='point_18h' AND pj.statut='valide'
         GROUP BY fu.bobine_id, b.numero, b.type_code, b.format, b.serie,
                  b.stock_systeme, b.films_restants, b.statut, pj.id, pj.type_point",
        [$site_id, $date]
    );
    if (empty($pj_entries)) {
        $pj_entries = db_fetch_all(
            "SELECT fu.bobine_id,
                    SUM(fu.films_utilises)   AS films_utilises,
                    SUM(fu.films_endommages) AS films_endommages,
                    b.numero, b.type_code, b.format, b.serie,
                    b.stock_systeme, b.films_restants, b.statut,
                    ANY_VALUE(pj.id) AS point_id, ANY_VALUE(pj.type_point) AS type_point
             FROM op_films_utilises fu
             JOIN op_points_journaliers pj ON pj.id = fu.point_id
             JOIN op_bobines b ON b.id = fu.bobine_id
             WHERE pj.site_id=? AND pj.date_point=?
               AND pj.statut IN ('valide','en_attente_validation','suivi','rejete')
             GROUP BY fu.bobine_id, b.numero, b.type_code, b.format, b.serie,
                      b.stock_systeme, b.films_restants, b.statut",
            [$site_id, $date]
        );
    }
    $dernier_import = db_fetch_value(
        "SELECT MAX(date_import) FROM import_optotrace WHERE site_id=? AND date_import=?",
        [$site_id, $date]
    );
    $ecarts = []; $bobines_detail = []; $nb_ecarts = 0;
    foreach ($pj_entries as $b) {
        $films_utilises   = (int)$b['films_utilises'];
        $films_endommages = (int)($b['films_endommages'] ?? 0);
        $films_total_pj   = $films_utilises + $films_endommages;
        // Même logique que calculer_ecarts : op_bobines.films_restants est déjà mis à jour
        // par la validation du PJ → ne pas re-soustraire les films du jour.
        if (!empty($b['stock_avant'])) {
            $stock_debut    = (int)$b['stock_avant'];
            $films_restants = $stock_debut - $films_total_pj;
        } else {
            $films_restants = (int)($b['films_restants'] ?? $b['stock_systeme']);
            $stock_debut    = $films_restants + $films_total_pj;
        }
        $stock_emuci = (int)($b['stock_systeme'] ?? 0);
        $ecart_val   = $dernier_import ? ($films_restants - $stock_emuci) : 0;
        $has_ecart   = $dernier_import !== null && $ecart_val !== 0;
        $ligne = [
            'bobine_id' => $b['bobine_id'], 'numero' => $b['numero'],
            'type_code' => $b['type_code'] ?? '', 'format' => $b['format'] ?? '',
            'stock_debut' => $stock_debut, 'films_utilises' => $films_utilises,
            'films_endommages' => $films_endommages, 'films_restants' => $films_restants,
            'stock_systeme' => $stock_emuci,
            'ecart' => $ecart_val, 'has_ecart' => $has_ecart,
        ];
        $bobines_detail[] = $ligne;
        if ($has_ecart) { $nb_ecarts++; $ecarts[] = $ligne; }
    }
    return compact('nb_ecarts','ecarts','bobines_detail','dernier_import');
}

// ── Re-vérification des validations auto : un import EMUCI plus récent (après
//    la validation) écrase op_bobines.stock_systeme sans se soucier des sites
//    déjà validés. Si le recalcul révèle désormais un écart, la validation
//    auto n'est plus valable : elle est annulée, le site retombe dans la file
//    d'attente GSB comme s'il n'avait jamais été validé. Ne touche jamais aux
//    décisions manuelles (valide_gsb / autorise_ecart / reajuste / refuse) :
//    celles-ci reflètent un jugement humain, pas une simple absence d'écart.
if ($can_valider) {
    $valides_auto_jour = db_fetch_all(
        "SELECT site_id FROM validations_stock_matin WHERE date_validation=? AND statut='valide_auto'",
        [$f_date]
    );
    foreach ($valides_auto_jour as $sv) {
        $recheck = _calculer_ecarts_site((int)$sv['site_id'], $f_date);
        if ($recheck['nb_ecarts'] > 0) {
            db_query("DELETE FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
                [(int)$sv['site_id'], $f_date]);
            audit_log($user['id'], 'UPDATE', 'validations_stock_matin', 0,
                "Validation auto annulée site:{$sv['site_id']} $f_date — écart détecté suite à un import EMUCI plus récent");
            $coords = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [(int)$sv['site_id']]);
            foreach ($coords as $c) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$c['id'], 'info', '⚠️ Validation annulée',
                     "Votre stock bobines du $f_date, validé automatiquement, présente désormais un écart après un nouvel import EMUCI. Il repasse en attente de traitement par le GSB.",
                     '/pages/validation_stock_matin.php']);
            }
        }
    }
}

// ── Auto-traitement batch : valider silencieusement tous les sites sans écart
$sites_non_valides = [];
if ($can_valider) {
    $sites_non_valides = db_fetch_all(
        "SELECT s.id, s.nom, COUNT(b.id) AS nb_bobines
         FROM sites s
         LEFT JOIN op_bobines b ON b.site_id=s.id AND b.statut IN ('en_cours','en_stock')
         WHERE s.actif=1
           AND s.id NOT IN (SELECT site_id FROM validations_stock_matin WHERE date_validation=?)
         GROUP BY s.id HAVING COUNT(b.id) > 0
         ORDER BY s.nom",
        [$f_date]
    );
    // Pour chaque site non encore validé, calculer les écarts et auto-valider si 0 écart
    foreach ($sites_non_valides as &$s) {
        $result = _calculer_ecarts_site((int)$s['id'], $f_date);
        $s['nb_ecarts']     = $result['nb_ecarts'];
        $s['ecarts']        = $result['ecarts'];
        $s['bobines_detail']= $result['bobines_detail'];
        $s['dernier_import']= $result['dernier_import'];
        // Décisions déjà prises bobine par bobine : le site reste en attente
        // tant qu'il reste des écarts sans décision.
        $dec_site           = _decisions_bobines_site((int)$s['id'], $f_date);
        $s['nb_traites']    = count($dec_site);
        $s['nb_restants']   = count(array_filter(
            $result['ecarts'],
            fn($e) => !isset($dec_site[(int)$e['bobine_id']])
        ));
        if ($result['nb_ecarts'] === 0 && !empty($result['bobines_detail']) && $result['dernier_import']) {
            // Auto-valider avec snapshot — seulement si un import EMUCI existe pour comparer.
            // Sans import, has_ecart est forcé à false faute de référence : ce n'est pas
            // une preuve de conformité, donc pas d'auto-validation dans ce cas.
            $snapshot = json_encode($result['bobines_detail']);
            db_query(
                "INSERT INTO validations_stock_matin (site_id,date_validation,statut,nb_ecarts,bobines_snapshot,gsb_user_id,gsb_at)
                 VALUES (?,?,'valide_auto',0,?,?,NOW())
                 ON DUPLICATE KEY UPDATE statut='valide_auto',nb_ecarts=0,bobines_snapshot=VALUES(bobines_snapshot),gsb_user_id=VALUES(gsb_user_id),gsb_at=NOW()",
                [(int)$s['id'], $f_date, $snapshot, $user['id']]
            );
            $coords = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [(int)$s['id']]);
            foreach ($coords as $c) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$c['id'],'stock_valide','✅ Stock validé',"Votre stock bobines du $f_date est validé. Vous pouvez commencer votre activité.",'/pages/validation_stock_matin.php']);
            }
            $s['auto_valide'] = true;
        } else {
            $s['auto_valide'] = false;
        }
    }
    unset($s);
    // Séparer : sites avec écarts (action GSB) vs sites auto-validés maintenant
    $sites_avec_ecarts  = array_values(array_filter($sites_non_valides, fn($s) => !$s['auto_valide'] && $s['nb_ecarts'] > 0));
    $sites_sans_point   = array_values(array_filter($sites_non_valides, fn($s) => !$s['auto_valide'] && $s['nb_ecarts'] === 0 && empty($s['bobines_detail'])));
    $sites_sans_import  = array_values(array_filter($sites_non_valides, fn($s) => !$s['auto_valide'] && $s['nb_ecarts'] === 0 && !empty($s['bobines_detail']) && !$s['dernier_import']));
    $sites_non_valides  = $sites_avec_ecarts; // compatibilité JS
}

// Vue d'ensemble des validations du jour
$coord_where = $is_coord && $site_force ? "AND v.site_id=$site_force" : '';
// Statuts "vraiment validés" : le coordinateur peut travailler
$validations_jour = db_fetch_all(
    "SELECT v.*, s.nom AS site_nom,
            CONCAT(u.prenom,' ',u.nom) AS gsb_nom,
            (SELECT COUNT(*) FROM op_bobines b WHERE b.site_id=v.site_id AND b.statut IN ('en_cours','en_stock')) AS nb_bobines_actives
     FROM validations_stock_matin v
     JOIN sites s ON s.id=v.site_id
     LEFT JOIN users u ON u.id=v.gsb_user_id
     WHERE v.date_validation=? $coord_where
       AND v.statut NOT IN ('reajuste','refuse')
     ORDER BY v.created_at DESC",
    [$f_date]
);
// Sites réajustés ou refusés : en attente d'une nouvelle saisie coordinateur
$sites_en_attente_correction = [];
if ($can_valider) {
    $sites_en_attente_correction = db_fetch_all(
        "SELECT v.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS gsb_nom
         FROM validations_stock_matin v
         JOIN sites s ON s.id=v.site_id
         LEFT JOIN users u ON u.id=v.gsb_user_id
         WHERE v.date_validation=? AND v.statut IN ('reajuste','refuse')
         ORDER BY v.statut='refuse' DESC, s.nom",
        [$f_date]
    );
    // Vérifier si le coordinateur a déjà soumis des corrections pour chaque site réajusté
    foreach ($sites_en_attente_correction as &$srv) {
        $srv['nb_corrections_coord'] = ($srv['statut'] === 'reajuste')
            ? (int)db_fetch_value(
                "SELECT COUNT(*) FROM corrections_bobines WHERE site_id=? AND date_point=? AND statut='coord_repond'",
                [$srv['site_id'], $srv['date_validation']]
              )
            : 0;
    }
    unset($srv);
}

// Corrections soumises par le coordinateur (pour sa propre vue)
$coord_reajust_details = [];
$coord_corrections_soumises = [];
if ($is_coord && $site_force) {
    $coord_val_tmp = db_fetch_one(
        "SELECT details_ecarts, date_validation, statut FROM validations_stock_matin WHERE site_id=? AND date_validation=?",
        [$site_force, $f_date]
    );
    if ($coord_val_tmp && $coord_val_tmp['statut'] === 'reajuste') {
        $all_decs = json_decode($coord_val_tmp['details_ecarts'] ?? '[]', true) ?: [];
        $coord_reajust_details = array_values(array_filter($all_decs, fn($d) => ($d['decision'] ?? '') === 'reajuste'));
        if (!empty($coord_reajust_details)) {
            $bid_list = array_column($coord_reajust_details, 'bobine_id');
            $ph = implode(',', array_fill(0, count($bid_list), '?'));
            $coord_corrections_soumises = db_fetch_all(
                "SELECT bobine_id, films_final, reponse_coord FROM corrections_bobines
                 WHERE site_id=? AND date_point=? AND statut='coord_repond' AND bobine_id IN ($ph)",
                array_merge([$site_force, $f_date], $bid_list)
            );
            // Bobines déjà validées par le GSB dans des rounds précédents
            $valide_rows = db_fetch_all(
                "SELECT bobine_id, films_final FROM corrections_bobines
                 WHERE site_id=? AND date_point=? AND statut='valide' AND bobine_id IN ($ph)",
                array_merge([$site_force, $f_date], $bid_list)
            );
            foreach ($valide_rows as $vr) $coord_valides_map[(int)$vr['bobine_id']] = $vr;
        }
    }
}
$coord_valides_map = $coord_valides_map ?? [];

// ── Rapport journalier GSB : sites validés (exclu reajuste/refuse — pas encore finalisés)
$rapport_journalier = [];
if ($can_valider) {
    $rapport_journalier = db_fetch_all(
        "SELECT v.*, s.nom AS site_nom,
                CONCAT(u.prenom,' ',u.nom) AS gsb_nom,
                (SELECT COUNT(*) FROM op_bobines b WHERE b.site_id=v.site_id AND b.statut IN ('en_cours','en_stock')) AS nb_bobines
         FROM validations_stock_matin v
         JOIN sites s ON s.id=v.site_id
         LEFT JOIN users u ON u.id=v.gsb_user_id
         WHERE v.date_validation=? AND v.statut IN ('valide_auto','autorise_ecart')
         ORDER BY v.statut='autorise_ecart' DESC, s.nom ASC",
        [$f_date]
    );
}

// ── Export Excel rapport journalier
if (isset($_GET['export_rapport']) && $can_valider) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        $coord = fn(int $col, int $row): string =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

        $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $sp->getActiveSheet()->setTitle('Validation Stock');
        $sh->mergeCells('A1:G1');
        $sh->setCellValue('A1', 'RAPPORT VALIDATION STOCK JOURNALIER — ' . date('d/m/Y', strtotime($f_date)));
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sh->getStyle('A1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF06033A');
        $sh->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');

        $row = 3;
        $headers = ['Site', 'Statut', 'Nb bobines actives', 'Nb écarts', 'Validé/Traité par', 'Heure', 'Commentaire'];
        foreach ($headers as $i => $h) {
            $cell = $coord($i + 1, $row);
            $sh->setCellValue($cell, $h);
            $sh->getStyle($cell)->getFont()->setBold(true);
            $sh->getStyle($cell)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF1B75BC');
            $sh->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFFFF');
        }

        $statut_labels = [
            'valide_auto'    => 'Validé automatiquement',
            'reajuste'       => 'Réajusté par GSB',
            'autorise_ecart' => 'Écart autorisé',
        ];
        foreach ($rapport_journalier as $i => $r) {
            $dr = $row + 1 + $i;
            $sh->setCellValue($coord(1,$dr), $r['site_nom']);
            $sh->setCellValue($coord(2,$dr), $statut_labels[$r['statut']] ?? $r['statut']);
            $sh->setCellValue($coord(3,$dr), (int)$r['nb_bobines']);
            $sh->setCellValue($coord(4,$dr), (int)$r['nb_ecarts']);
            $sh->setCellValue($coord(5,$dr), $r['gsb_nom'] ?: 'Auto');
            $sh->setCellValue($coord(6,$dr), $r['gsb_at'] ? date('H:i', strtotime($r['gsb_at'])) : '—');
            $sh->setCellValue($coord(7,$dr), $r['commentaire'] ?? '');

            // Couleur selon statut
            $bgColor = match($r['statut']) {
                'valide_auto'    => 'FFD1FAE5',
                'reajuste'       => 'FFDBEAFE',
                'autorise_ecart' => 'FFFEF3C7',
                default          => 'FFFFFFFF',
            };
            $sh->getStyle($coord(1,$dr).':'.$coord(7,$dr))->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB($bgColor);
        }

        foreach (range('A','G') as $col) $sh->getColumnDimension($col)->setAutoSize(true);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="ValidationStock_' . $f_date . '.xlsx"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
        exit;
    }
}

// Historique validations (30 derniers jours) — GSB seulement
$historique_validations = [];
if ($can_valider) {
    $historique_validations = db_fetch_all(
        "SELECT v.*, s.nom AS site_nom,
                CONCAT(u.prenom,' ',u.nom) AS gsb_nom
         FROM validations_stock_matin v
         JOIN sites s ON s.id=v.site_id
         LEFT JOIN users u ON u.id=v.gsb_user_id
         WHERE v.date_validation >= (CURRENT_DATE - INTERVAL 30 DAY)
         ORDER BY v.date_validation DESC, s.nom",
        []
    );
}
$sites_list_filter = $can_valider ? db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom") : [];

// Vue coordinateur : son propre statut de validation pour aujourd'hui
$coord_validation = null;
if ($is_coord && $site_force) {
    $coord_validation = db_fetch_one(
        "SELECT v.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS gsb_nom
         FROM validations_stock_matin v
         JOIN sites s ON s.id=v.site_id
         LEFT JOIN users u ON u.id=v.gsb_user_id
         WHERE v.site_id=? AND v.date_validation=?",
        [$site_force, $f_date]
    );
}

include __DIR__ . '/../templates/header.php';
?>
<?php
$nb_en_attente  = count($sites_non_valides ?? []) + count($sites_en_attente_correction ?? []);
$nb_valides_jour= count($validations_jour);
$nb_avec_ecart  = count(array_filter($validations_jour, fn($v) => $v['statut'] !== 'valide_gsb' && (int)$v['nb_ecarts'] > 0))
                + count($sites_non_valides ?? [])
                + count($sites_en_attente_correction ?? []);
?>
<style>
.vsm-banner{background:linear-gradient(135deg,#06033A 0%,#1B75BC 100%);border-radius:16px;padding:22px 28px;margin-bottom:24px;color:white;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.vsm-banner h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:19px;font-weight:800;margin:0;color:white}
.vsm-banner p{font-size:13px;opacity:.8;margin:4px 0 0;color:white}
.vsm-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.vsm-kpi{background:white;border-radius:14px;border:1px solid var(--border);padding:20px 24px}
.vsm-kpi-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:36px;font-weight:900;line-height:1}
.vsm-kpi-label{font-size:12px;color:var(--muted);font-weight:700;margin-top:6px;text-transform:uppercase;letter-spacing:.06em}
.vsm-section{background:white;border-radius:14px;border:1px solid var(--border);margin-bottom:24px;overflow:hidden}
.vsm-section-hdr{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:#fafbfd}
.vsm-section-title{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:800;color:var(--navy);display:flex;align-items:center;gap:8px}
.vsm-cnt{background:#e8edf8;color:var(--navy);border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700}
.vsm-tbl{width:100%;border-collapse:collapse}
.vsm-tbl thead th{background:#06033A;color:white;padding:11px 16px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;text-align:left;white-space:nowrap}
.vsm-tbl thead th.tc{text-align:center}
.vsm-tbl tbody td{padding:13px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:var(--navy);vertical-align:middle}
.vsm-tbl tbody tr:last-child td{border-bottom:none}
.vsm-tbl tbody tr:hover td{background:#f8faff}
.vsm-tbl tbody td.tc{text-align:center}
.vsm-site-name{font-weight:800;color:var(--navy);font-size:14px}
.vsm-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700}
.vsm-badge.valide_auto,.vsm-badge.valide_gsb{background:#d1fae5;color:#065f46}
.vsm-badge.autorise_ecart{background:#fef3c7;color:#92400e}
.vsm-badge.reajuste{background:#dbeafe;color:#1d4ed8}
.vsm-badge.refuse{background:#fee2e2;color:#991b1b}
.vsm-badge.en_attente{background:#fff3e0;color:#e65100}
.vsm-ecart-chip{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:8px;font-size:11.5px;font-weight:700;display:inline-block}
.vsm-ok-chip{color:#065f46;font-weight:700;font-size:14px}
.vsm-actions{display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:nowrap}
.btn-traiter{background:#DC2626;color:white;border:none;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:background .15s}
.btn-traiter:hover{background:#b91c1c}
.btn-vsm-detail{background:white;color:var(--navy);border:1.5px solid var(--border);padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;}
.btn-vsm-detail:hover{background:#f0f4ff;border-color:#1B75BC;color:#1B75BC}
.btn-vsm-revise{background:#1B75BC;color:white;border:none;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.btn-vsm-revise:hover{background:#1565a8}
.detail-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--border);background:white;color:var(--navy);transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;text-decoration:none}
.detail-btn:hover{background:var(--tertiary);border-color:var(--primary);color:var(--primary-d)}
/* ── ONGLETS ── */
.vsm-tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px}
.vsm-tab{padding:10px 22px;font-size:13px;font-weight:700;color:var(--muted);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:7px;transition:color .15s,border-color .15s;background:none;border-top:none;border-left:none;border-right:none}
.vsm-tab:hover{color:var(--navy)}
.vsm-tab.active{color:#1B75BC;border-bottom-color:#1B75BC}
.vsm-tab-badge{background:#e8edf8;color:var(--navy);border-radius:20px;padding:1px 8px;font-size:12px;font-weight:700}
.vsm-tab.active .vsm-tab-badge{background:#dbeafe;color:#1d4ed8}
/* ── BANDEAU INFO ── */
.vsm-info-banner{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px;font-size:13px;color:#1d4ed8}
/* ── FILTRES ── */
.vsm-filters{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.vsm-filters input,.vsm-filters select{padding:7px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;outline:none;color:var(--navy);background:white;transition:border-color .15s}
.vsm-filters input:focus,.vsm-filters select:focus{border-color:#1B75BC}
.vsm-filters label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-right:4px}
/* ── LÉGENDE ── */
.vsm-legend{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.vsm-legend-title{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.vsm-legend-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700}
/* ── DÉCISION PAR BOBINE (modal de vérification) ── */
.vsm-dec-group{display:inline-flex;gap:4px;align-items:center;justify-content:center}
.vsm-dec-btn{border:1.5px solid;border-radius:8px;padding:5px 8px;font-size:12px;font-weight:800;line-height:1;cursor:pointer;background:white;transition:transform .12s,box-shadow .12s}
.vsm-dec-btn:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.14)}
.vsm-dec-btn.dec-autorise_ecart{border-color:#F59E0B;background:#FEF3C7;color:#92400E}
.vsm-dec-btn.dec-reajuste{border-color:#3B82F6;background:#DBEAFE;color:#1D4ED8}
.vsm-dec-btn.dec-refuse{border-color:#F87171;background:#FEE2E2;color:#991B1B}
.vsm-dec-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:8px;font-size:10.5px;font-weight:700;white-space:nowrap}
.vsm-dec-edit{background:none;border:none;color:var(--muted);cursor:pointer;font-size:10.5px;font-weight:600;text-decoration:underline;padding:2px 0;margin-top:3px;display:block;width:100%}
.vsm-dec-edit:hover{color:#1B75BC}
.vsm-dec-help{display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:11.5px;color:var(--muted);margin:-8px 0 16px;padding:8px 12px;background:#f8fafc;border-radius:8px}
</style>

<!-- BANNIÈRE -->
<div class="vsm-banner">
  <div>
    <h2><i class="ph ph-sun" aria-hidden="true"></i> Validation Stock Jour</h2>
    <p><?= $is_coord ? 'Statut du stock bobines de votre site pour la journée' : 'Vérification et validation des stocks bobines avant démarrage' ?></p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <input type="date" id="fDate" value="<?= h($f_date) ?>" onchange="location.href='?date='+this.value" aria-label="Choisir la date"
           style="padding:8px 14px;border:1.5px solid rgba(255,255,255,.35);background:rgba(255,255,255,.15);border-radius:10px;font-size:13px;outline:none;color:white;cursor:pointer">
    <?php if($can_valider): ?>
    <button class="btn btn-secondary" onclick="verifierTousSites()"
            style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.35);color:white">
      <i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Vérifier tous les sites
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if($is_coord): ?>
<!-- ── VUE COORDINATEUR ── -->
<?php
$statut_colors = [
  'valide_auto'   => ['bg'=>'#D1FAE5','border'=>'#34D399','icon'=>'ph-check-circle','label'=>'Validé automatiquement',    'color'=>'#065F46'],
  'valide_gsb'    => ['bg'=>'#D1FAE5','border'=>'#34D399','icon'=>'ph-check-circle','label'=>'Validé par le gestionnaire','color'=>'#065F46'],
  'autorise_ecart'=> ['bg'=>'#FEF3C7','border'=>'#F59E0B','icon'=>'ph-warning','label'=>'Écart autorisé — vous pouvez travailler','color'=>'#92400E'],
  'reajuste'      => ['bg'=>'#FFF7ED','border'=>'#F59E0B','icon'=>'ph-arrow-clockwise','label'=>'Stock réajusté — nouvelle saisie requise','color'=>'#92400E'],
  'refuse'        => ['bg'=>'#FEE2E2','border'=>'#F87171','icon'=>'ph-x-circle','label'=>'Activité bloquée — correction requise', 'color'=>'#991B1B'],
];
?>
<?php if($coord_validation): ?>
  <?php $sv = $statut_colors[$coord_validation['statut']] ?? ['bg'=>'#F1F5F9','border'=>'#CBD5E1','icon'=>'ph-question','label'=>$coord_validation['statut'],'color'=>'#64748B']; ?>
  <div style="background:<?= $sv['bg'] ?>;border:2px solid <?= $sv['border'] ?>;border-radius:18px;padding:24px 28px;margin-bottom:20px">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
      <div style="font-size:36px"><i class="ph <?= $sv['icon'] ?>" style="color:<?= $sv['color'] ?>" aria-hidden="true"></i></div>
      <div>
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:<?= $sv['color'] ?>"><?= $sv['label'] ?></div>
        <div style="font-size:12.5px;color:<?= $sv['color'] ?>;opacity:.8;margin-top:3px">
          Validé le <?= fmt_date($f_date,'d/m/Y') ?>
          <?= $coord_validation['gsb_nom'] ? ' par '.$coord_validation['gsb_nom'] : ' automatiquement' ?>
        </div>
      </div>
    </div>
    <?php if($coord_validation['commentaire']): ?>
    <div style="background:rgba(0,0,0,.06);border-radius:10px;padding:10px 14px;font-size:13px;color:<?= $sv['color'] ?>;margin-bottom:14px">
      <i class="ph ph-chat-circle" aria-hidden="true"></i> <?= h($coord_validation['commentaire']) ?>
    </div>
    <?php endif; ?>
    <?php if($coord_validation['statut'] === 'reajuste'): ?>
    <div style="background:#FEF3C7;border:1.5px solid #F59E0B;border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#92400E">
      <strong>Action requise :</strong> Le gestionnaire a réajusté les valeurs des bobines en écart.
      Consultez le tableau ci-dessous, saisissez la valeur correcte pour chaque bobine et envoyez votre correction au gestionnaire.
    </div>
    <?php elseif($coord_validation['statut'] === 'refuse'): ?>
    <div style="background:#FEE2E2;border:1.5px solid #F87171;border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#991B1B">
      <strong>Activité bloquée :</strong> Le gestionnaire a refusé les données transmises.
      Veuillez corriger votre saisie et soumettre un nouveau point journalier.
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:10px;margin-bottom:<?= !empty($coord_reajust_details) ? '0' : '0' ?>px">
      <button class="detail-btn"
        onclick="voirDetails(<?= $coord_validation['site_id'] ?>,'<?= h($coord_validation['site_nom']) ?>',<?= (int)$coord_validation['nb_ecarts'] ?>,<?= htmlspecialchars(json_encode($coord_validation['details_ecarts']??'[]'),ENT_QUOTES) ?>,'<?= h($coord_validation['statut']) ?>','<?= h($coord_validation['commentaire']??'') ?>','<?= h($coord_validation['gsb_nom']??'Auto') ?>','<?= h(fmt_datetime($coord_validation['gsb_at'])) ?>','<?= h($coord_validation['date_validation']) ?>')">
        <i class="ph-duotone ph-eye"></i> Voir le détail des bobines
      </button>
    </div>
  </div>

<?php if(!empty($coord_reajust_details)): ?>
<?php
$corr_done_map = [];
foreach ($coord_corrections_soumises as $cs) $corr_done_map[(int)$cs['bobine_id']] = $cs;
// Bobines qui nécessitent encore une correction (pas encore validées par GSB)
$bobines_a_corriger = array_filter($coord_reajust_details, fn($r) => !isset($coord_valides_map[(int)$r['bobine_id']]));
// Toutes les bobines non-validées ont-elles une correction en attente de review ?
$all_done = !empty($bobines_a_corriger)
    && count(array_filter($bobines_a_corriger, fn($r) => isset($corr_done_map[(int)$r['bobine_id']]))) >= count($bobines_a_corriger);
$nb_total_valides = count($coord_valides_map);
$nb_total_bobines = count($coord_reajust_details);
?>
<div style="background:white;border:2px solid #F59E0B;border-radius:18px;padding:24px 28px;margin-bottom:20px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px">
    <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:#92400E">
      <i class="ph ph-clipboard-text" aria-hidden="true"></i> Saisir les valeurs correctes
    </div>
    <?php if($nb_total_valides > 0): ?>
    <div style="font-size:12px;background:#D1FAE5;color:#065F46;border-radius:8px;padding:4px 10px;font-weight:700">
      <i class="ph ph-check-circle" aria-hidden="true"></i> <?= $nb_total_valides ?> / <?= $nb_total_bobines ?> bobine(s) déjà validée(s) par le gestionnaire
    </div>
    <?php endif; ?>
  </div>
  <p style="font-size:13px;color:#92400E;margin-bottom:18px">
    Pour chaque bobine réajustée, indiquez la valeur correcte. Le gestionnaire re-validera vos corrections bobine par bobine.
  </p>
  <?php if($all_done): ?>
  <div style="background:#d1fae5;border-radius:10px;padding:12px 16px;font-size:13px;color:#065f46;font-weight:700;margin-bottom:14px">
    <i class="ph ph-check-circle" aria-hidden="true"></i> Vos corrections ont été soumises au gestionnaire. En attente de re-validation.
  </div>
  <?php endif; ?>
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">
    <thead>
      <tr style="background:#F8FAFC">
        <th style="padding:9px 14px;text-align:left;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;border-bottom:1.5px solid var(--border)">N° Bobine</th>
        <th style="padding:9px 14px;text-align:center;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;border-bottom:1.5px solid var(--border)">Valeur ERP EMUCI</th>
        <th style="padding:9px 14px;text-align:center;font-size:12px;font-weight:700;color:#1d4ed8;text-transform:uppercase;border-bottom:1.5px solid var(--border)">Réajusté GSB</th>
        <th style="padding:9px 14px;text-align:center;font-size:12px;font-weight:700;color:var(--navy);text-transform:uppercase;border-bottom:1.5px solid var(--border)">Valeur correcte *</th>
        <th style="padding:9px 14px;text-align:left;font-size:12px;font-weight:700;color:var(--navy);text-transform:uppercase;border-bottom:1.5px solid var(--border)">Justification *</th>
        <th style="padding:9px 14px;text-align:center;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;border-bottom:1.5px solid var(--border)">Statut</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($coord_reajust_details as $idx => $r): ?>
    <?php
      $bid          = (int)$r['bobine_id'];
      $is_valide    = isset($coord_valides_map[$bid]);
      $existing_corr= $corr_done_map[$bid] ?? null;
      $row_bg       = $is_valide ? '#F0FDF4' : ($existing_corr ? '#EFF6FF' : '');
    ?>
    <tr style="border-bottom:1px solid var(--border);<?= $row_bg ? "background:$row_bg" : '' ?>">
      <td style="padding:10px 14px;font-weight:800;color:var(--navy);font-family:monospace"><?= h($r['numero']) ?></td>
      <td style="padding:10px 14px;text-align:center;color:var(--muted)"><?= (int)$r['films_restants'] ?></td>
      <td style="padding:10px 14px;text-align:center;color:#1d4ed8;font-weight:700"><?= (int)$r['stock_systeme'] ?></td>
      <td style="padding:10px 14px;text-align:center">
        <?php if($is_valide): ?>
          <span style="font-size:15px;font-weight:800;color:#065F46"><?= (int)$coord_valides_map[$bid]['films_final'] ?></span>
        <?php else: ?>
          <input type="number" id="cr-films-<?= $idx ?>" data-bobine="<?= $bid ?>"
                 min="0" value="<?= $existing_corr ? (int)$existing_corr['films_final'] : (int)$r['films_restants'] ?>"
                 <?= ($all_done || $existing_corr) ? 'disabled' : '' ?>
                 style="width:80px;padding:6px 8px;border:1.5px solid var(--border);border-radius:8px;text-align:center;font-size:14px;font-weight:700;<?= ($all_done || $existing_corr) ? 'background:#f1f5f9;color:var(--muted)' : '' ?>">
        <?php endif; ?>
      </td>
      <td style="padding:10px 14px">
        <?php if($is_valide): ?>
          <span style="font-size:12.5px;color:#065F46">—</span>
        <?php else: ?>
          <input type="text" id="cr-motif-<?= $idx ?>"
                 placeholder="Justification obligatoire…"
                 value="<?= $existing_corr ? h($existing_corr['reponse_coord'] ?? '') : '' ?>"
                 <?= ($all_done || $existing_corr) ? 'disabled' : '' ?>
                 style="width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12.5px;<?= ($all_done || $existing_corr) ? 'background:#f1f5f9;color:var(--muted)' : '' ?>">
        <?php endif; ?>
      </td>
      <td style="padding:10px 14px;text-align:center">
        <?php if($is_valide): ?>
          <span style="background:#D1FAE5;color:#065F46;border-radius:8px;padding:3px 10px;font-size:11.5px;font-weight:700"><i class="ph ph-check-circle" aria-hidden="true"></i> Validé</span>
        <?php elseif($existing_corr): ?>
          <span style="background:#DBEAFE;color:#1D4ED8;border-radius:8px;padding:3px 10px;font-size:11.5px;font-weight:700">⏳ En attente GSB</span>
        <?php else: ?>
          <span style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:3px 10px;font-size:11.5px;font-weight:700"><i class="ph ph-note-pencil" aria-hidden="true"></i> À renseigner</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if(!$all_done && !empty($bobines_a_corriger)): ?>
  <div style="display:flex;justify-content:flex-end">
    <button onclick="soumettreCorrectionsCoord(<?= (int)$coord_validation['site_id'] ?>,'<?= h($coord_validation['date_validation']) ?>',<?= count($coord_reajust_details) ?>)"
            style="background:#1B75BC;color:white;border:none;padding:11px 26px;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:8px">
      <i class="ph-duotone ph-paper-plane-tilt"></i> Soumettre mes corrections
    </button>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
  <!-- Pas encore validé ce jour -->
  <div style="background:#FEF3C7;border:2px solid #F59E0B;border-radius:18px;padding:28px;text-align:center;margin-bottom:20px">
    <div style="font-size:40px;margin-bottom:12px">⏳</div>
    <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:800;color:#92400E">Stock en attente de validation</div>
    <div style="font-size:13px;color:#92400E;margin-top:6px">
      Le gestionnaire stock n'a pas encore validé votre stock pour le <?= fmt_date($f_date,'d/m/Y') ?>.
    </div>
    <div style="font-size:12px;color:#B45309;margin-top:10px">
      Vous serez notifié dès que la validation est effectuée.
    </div>
  </div>
<?php endif; ?>

<?php else: ?>
<!-- ── VUE GSB / ADMIN ── -->

<!-- BANDEAU INFO -->
<div class="vsm-info-banner">
  <i class="ph-duotone ph-info" style="font-size:20px;flex-shrink:0"></i>
  <div>
    <strong>Comment ça marche ?</strong>
    Vérifiez chaque site, comparez les stocks avec l'import EMUCI, puis validez ou traitez les écarts.
    Les coordinateurs sont notifiés automatiquement après chaque décision.
  </div>
</div>

<!-- ONGLETS -->
<div class="vsm-tabs">
  <button class="vsm-tab active" id="tab-btn-encours" onclick="switchTab('encours')">
    <i class="ph-duotone ph-clock"></i> Validation en cours
    <span class="vsm-tab-badge"><?= $nb_en_attente + $nb_valides_jour ?></span>
  </button>
  <button class="vsm-tab" id="tab-btn-historique" onclick="switchTab('historique')">
    <i class="ph-duotone ph-clock-counter-clockwise"></i> Historique des validations
    <span class="vsm-tab-badge"><?= count($historique_validations) ?></span>
  </button>
</div>

<!-- TAB : VALIDATION EN COURS -->
<div id="tab-encours">

<!-- LÉGENDE -->
<div class="vsm-legend">
  <span class="vsm-legend-title">Légende :</span>
  <span class="vsm-legend-chip" style="background:#d1fae5;color:#065f46"><i class="ph ph-check-circle" aria-hidden="true"></i> Conforme</span>
  <span class="vsm-legend-chip" style="background:#fef3c7;color:#92400e"><i class="ph ph-warning" aria-hidden="true"></i> Avec écart</span>
  <span class="vsm-legend-chip" style="background:#dbeafe;color:#1d4ed8"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Réajusté</span>
  <span class="vsm-legend-chip" style="background:#fee2e2;color:#991b1b"><i class="ph ph-x-circle" aria-hidden="true"></i> Bloqué</span>
  <span class="vsm-legend-chip" style="background:#fff3e0;color:#e65100">⏳ En attente</span>
</div>

<!-- KPIs -->
<?php if($can_valider): ?>
<div class="vsm-kpis">
  <div class="vsm-kpi">
    <div class="vsm-kpi-val" style="color:#e65100"><?= $nb_en_attente ?></div>
    <div class="vsm-kpi-label">En attente</div>
  </div>
  <div class="vsm-kpi">
    <div class="vsm-kpi-val" style="color:#065f46"><?= $nb_valides_jour ?></div>
    <div class="vsm-kpi-label">Validés aujourd'hui</div>
  </div>
  <div class="vsm-kpi">
    <div class="vsm-kpi-val" style="color:<?= $nb_avec_ecart > 0 ? '#991b1b' : '#065f46' ?>"><?= $nb_avec_ecart ?></div>
    <div class="vsm-kpi-label">Avec écart</div>
  </div>
</div>
<?php endif; ?>

<!-- Sites sans point journalier -->
<?php if(!empty($sites_sans_point) && $can_valider): ?>
<div style="background:#F0F9FF;border:1.5px solid #BAE6FD;border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <i class="ph-duotone ph-info" style="color:#0369A1;font-size:20px;flex-shrink:0"></i>
  <span style="font-weight:700;color:#0369A1;font-size:13px"><?= count($sites_sans_point) ?> site(s) sans point journalier</span>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach($sites_sans_point as $s): ?>
    <span style="background:#E0F2FE;color:#0369A1;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600"><?= h($s['nom']) ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Sites avec point journalier mais sans import EMUCI (impossible de comparer) -->
<?php if(!empty($sites_sans_import) && $can_valider): ?>
<div style="background:#FFFBEB;border:1.5px solid #FDE68A;border-radius:12px;padding:13px 18px;margin-bottom:20px">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">
    <i class="ph-duotone ph-clock-countdown" style="color:#92400E;font-size:20px;flex-shrink:0"></i>
    <span style="font-weight:700;color:#92400E;font-size:13px"><?= count($sites_sans_import) ?> site(s) en attente d'import EMUCI (comparaison impossible)</span>
  </div>
  <p style="font-size:12px;color:#92400E;margin:0 0 10px">Sans import EMUCI, le coordinateur reste bloqué indéfiniment sur cette date. Vous pouvez valider manuellement (motif obligatoire) si l'import n'est pas attendu ou n'arrivera pas.</p>
  <div style="display:flex;flex-direction:column;gap:6px">
    <?php foreach($sites_sans_import as $s): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:#FEF3C7;border-radius:10px;padding:6px 10px 6px 14px">
      <span style="color:#92400E;font-size:13px;font-weight:600"><?= h($s['nom']) ?></span>
      <button onclick="validerSansImport(<?= (int)$s['id'] ?>,'<?= h($f_date) ?>','<?= addslashes(h($s['nom'])) ?>')"
              style="background:#92400E;color:white;border:none;border-radius:7px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer">
        Valider quand même
      </button>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- TABLE 1 : Sites en attente de validation -->
<div class="vsm-section">
  <div class="vsm-section-hdr">
    <div class="vsm-section-title">
      <i class="ph-duotone ph-warning-circle" style="color:#e65100;font-size:17px"></i>
      Sites en attente de validation
      <span class="vsm-cnt"><?= $nb_en_attente ?></span>
    </div>
  </div>
  <?php if(empty($sites_non_valides) && empty($sites_en_attente_correction)): ?>
  <div style="padding:36px;text-align:center;color:var(--muted)">
    <i class="ph-duotone ph-check-circle" style="font-size:36px;color:#34d399;display:block;margin-bottom:10px"></i>
    Tous les sites ont été traités pour le <?= fmt_date($f_date,'d/m/Y') ?>.
  </div>
  <?php else: ?>
  <table class="vsm-tbl">
    <thead><tr>
      <th>Site</th>
      <th class="tc">Écarts</th>
      <th class="tc">Statut</th>
      <th>Commentaire GSB</th>
      <th class="tc">Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach($sites_non_valides as $s): ?>
    <tr>
      <td><div class="vsm-site-name"><?= h($s['nom']) ?></div></td>
      <td class="tc">
        <span class="vsm-ecart-chip"><i class="ph ph-warning" aria-hidden="true"></i> <?= (int)$s['nb_restants'] ?> écart(s) à traiter</span>
        <?php if(!empty($s['nb_traites'])): ?>
        <div style="margin-top:4px"><span class="vsm-legend-chip" style="background:#dbeafe;color:#1d4ed8"><i class="ph ph-check-circle" aria-hidden="true"></i> <?= (int)$s['nb_traites'] ?> bobine(s) traitée(s)</span></div>
        <?php endif; ?>
      </td>
      <td class="tc"><span class="vsm-badge en_attente"><?= !empty($s['nb_traites']) ? 'Traitement en cours' : '⏳ En attente' ?></span></td>
      <td style="font-size:12px;color:var(--muted)">—</td>
      <td class="tc">
        <div class="vsm-actions">
          <button class="btn-traiter" data-site-id="<?= $s['id'] ?>"
                  onclick="verifierSite(<?= $s['id'] ?>,'<?= h($s['nom']) ?>')">
            <i class="ph-duotone ph-magnifying-glass"></i> Traiter
          </button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php foreach($sites_en_attente_correction as $v): ?>
    <tr style="background:#FFFBEB">
      <td><div class="vsm-site-name"><?= h($v['site_nom']) ?></div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= $v['gsb_at'] ? 'Traité le ' . date('H:i', strtotime($v['gsb_at'])) . ' par ' . h($v['gsb_nom'] ?: 'GSB') : 'Détecté par import' ?></div>
      </td>
      <td class="tc"><span class="vsm-ecart-chip"><i class="ph ph-warning" aria-hidden="true"></i> <?= (int)$v['nb_ecarts'] ?> écart(s)</span></td>
      <td class="tc">
        <?php if($v['statut'] === 'reajuste'): ?>
        <span class="vsm-badge reajuste"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Réajusté</span>
        <?php if($v['nb_corrections_coord'] > 0): ?>
        <div style="font-size:10.5px;color:#065f46;margin-top:4px;font-weight:700;background:#d1fae5;padding:2px 8px;border-radius:20px;display:inline-block">
          <i class="ph ph-check-circle" aria-hidden="true"></i> Coord a répondu (<?= (int)$v['nb_corrections_coord'] ?> bobine<?= $v['nb_corrections_coord'] > 1 ? 's' : '' ?>)
        </div>
        <?php else: ?>
        <div style="font-size:10.5px;color:#1d4ed8;margin-top:3px;font-weight:600">En attente de la réponse coord</div>
        <?php endif; ?>
        <?php else: ?>
        <span class="vsm-badge refuse"><i class="ph ph-x-circle" aria-hidden="true"></i> Refusé</span>
        <div style="font-size:10.5px;color:#991b1b;margin-top:3px;font-weight:600">Correction requise</div>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--muted);max-width:160px"><?= $v['commentaire'] ? h($v['commentaire']) : '—' ?></td>
      <td class="tc">
        <div class="vsm-actions">
          <button class="btn-vsm-revise" onclick="verifierSite(<?= $v['site_id'] ?>,'<?= h($v['site_nom']) ?>')">
            <i class="ph-duotone ph-arrow-counter-clockwise"></i> Re-vérifier
          </button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- TABLE 2 : Sites validés du jour (valide_auto, valide_gsb, autorise_ecart uniquement) -->
<div class="vsm-section">
  <div class="vsm-section-hdr">
    <div class="vsm-section-title">
      <i class="ph-duotone ph-check-circle" style="color:#065f46;font-size:17px"></i>
      Sites validés du jour
      <span class="vsm-cnt"><?= $nb_valides_jour ?></span>
    </div>
    <?php if($can_valider && !empty($rapport_journalier)): ?>
    <a href="?date=<?= h($f_date) ?>&export_rapport=1" class="btn btn-secondary btn-sm">
      <i class="ph-duotone ph-file-xls"></i> Export Excel
    </a>
    <?php endif; ?>
  </div>
  <?php if(empty($validations_jour)): ?>
  <div style="padding:36px;text-align:center;color:var(--muted)">
    Aucune validation enregistrée pour le <?= fmt_date($f_date,'d/m/Y') ?>.
  </div>
  <?php else: ?>
  <table class="vsm-tbl">
    <thead><tr>
      <th>Site</th>
      <th class="tc">Date validation</th>
      <th class="tc">Statut</th>
      <th>Traité par</th>
      <th>Commentaire</th>
      <th class="tc">Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach($validations_jour as $v):
      // Colonne "Statut" : ce qui s'est passé sur les écarts
      if ($v['statut'] === 'refuse') {
        $statut_badge = '<span class="vsm-badge refuse"><i class="ph ph-x-circle" aria-hidden="true"></i> Bloqué</span>';
      } elseif ($v['statut'] === 'reajuste') {
        $statut_badge = '<span class="vsm-badge reajuste"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Réajusté</span>';
      } elseif ($v['statut'] === 'autorise_ecart') {
        $statut_badge = '<span class="vsm-badge autorise_ecart"><i class="ph ph-warning" aria-hidden="true"></i> Avec écart</span>';
      } elseif ($v['statut'] !== 'valide_gsb' && (int)$v['nb_ecarts'] > 0) {
        $statut_badge = '<span class="vsm-badge autorise_ecart"><i class="ph ph-warning" aria-hidden="true"></i> Avec écart</span>';
      } else {
        $statut_badge = '<span class="vsm-badge valide_auto"><i class="ph ph-check-circle" aria-hidden="true"></i> Conforme</span>';
      }
      // Colonne "Date validation" : badge Validée + date
      $date_label = in_array($v['statut'], ['refuse']) ? '<i class="ph ph-x-circle" aria-hidden="true"></i> Bloquée' : '<i class="ph ph-check-circle" aria-hidden="true"></i> Validée';
      $date_col_bg = $v['statut'] === 'refuse' ? '#fee2e2' : '#d1fae5';
      $date_col_color = $v['statut'] === 'refuse' ? '#991b1b' : '#065f46';
    ?>
    <tr>
      <td><div class="vsm-site-name"><?= h($v['site_nom']) ?></div></td>
      <td class="tc">
        <div style="display:inline-flex;flex-direction:column;align-items:center;gap:3px">
          <span style="background:<?= $date_col_bg ?>;color:<?= $date_col_color ?>;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700"><?= $date_label ?></span>
          <span style="font-size:12px;color:var(--muted);font-weight:600"><?= fmt_date($v['date_validation'],'d/m/Y') ?></span>
          <?php if($v['gsb_at']): ?>
          <span style="font-size:12px;color:var(--muted)">à <?= date('H:i', strtotime($v['gsb_at'])) ?></span>
          <?php endif; ?>
        </div>
      </td>
      <td class="tc"><?= $statut_badge ?></td>
      <td style="font-size:12.5px"><?= $v['gsb_nom'] ? h($v['gsb_nom']) : '<span style="color:var(--muted)">Automatique</span>' ?></td>
      <td style="font-size:12px;color:var(--muted);max-width:180px"><?= $v['commentaire'] ? h($v['commentaire']) : '<span style="color:var(--border)">—</span>' ?></td>
      <td class="tc">
        <div class="vsm-actions">
          <button class="btn-vsm-detail"
            onclick="voirDetails(<?= $v['site_id'] ?>,'<?= h($v['site_nom']) ?>',<?= (int)$v['nb_ecarts'] ?>,<?= htmlspecialchars(json_encode($v['details_ecarts'] ?? '[]'), ENT_QUOTES) ?>,'<?= h($v['statut']) ?>','<?= h($v['commentaire']??'') ?>','<?= h($v['gsb_nom']??'Auto') ?>','<?= h(fmt_datetime($v['gsb_at'])) ?>','<?= h($v['date_validation']) ?>')">
            <i class="ph-duotone ph-eye"></i> Détails
          </button>
          <?php if($can_valider && in_array($v['statut'],['autorise_ecart','reajuste','refuse'])): ?>
          <button class="btn-vsm-revise" onclick="verifierSite(<?= $v['site_id'] ?>,'<?= h($v['site_nom']) ?>')">
            <i class="ph-duotone ph-arrow-counter-clockwise"></i> Réviser
          </button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</div><!-- fin tab-encours -->

<!-- TAB : HISTORIQUE -->
<div id="tab-historique" style="display:none">

<!-- FILTRES HISTORIQUE -->
<div class="vsm-filters">
  <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:200px">
    <label>Recherche</label>
    <input type="text" id="hist-search" placeholder="Nom du site…" oninput="filtrerHistorique()" style="flex:1">
  </div>
  <div style="display:flex;align-items:center;gap:6px">
    <label>Du</label>
    <input type="date" id="hist-date-from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" onchange="filtrerHistorique()">
  </div>
  <div style="display:flex;align-items:center;gap:6px">
    <label>Au</label>
    <input type="date" id="hist-date-to" value="<?= date('Y-m-d') ?>" onchange="filtrerHistorique()">
  </div>
  <div style="display:flex;align-items:center;gap:6px">
    <label>Site</label>
    <select id="hist-site" onchange="filtrerHistorique()">
      <option value="">Tous</option>
      <?php foreach($sites_list_filter as $sl): ?>
      <option value="<?= h($sl['nom']) ?>"><?= h($sl['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;align-items:center;gap:6px">
    <label>Statut</label>
    <select id="hist-statut" onchange="filtrerHistorique()">
      <option value="">Tous</option>
      <option value="conforme">✅ Conforme</option>
      <option value="avec_ecart">⚠️ Avec écart</option>
      <option value="reajuste">🔄 Réajusté</option>
      <option value="refuse">❌ Bloqué</option>
    </select>
  </div>
  <button onclick="resetFiltresHistorique()" style="padding:7px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;background:white;color:var(--muted)">↺ Réinitialiser</button>
</div>

<!-- LÉGENDE -->
<div class="vsm-legend">
  <span class="vsm-legend-title">Légende :</span>
  <span class="vsm-legend-chip" style="background:#d1fae5;color:#065f46"><i class="ph ph-check-circle" aria-hidden="true"></i> Conforme</span>
  <span class="vsm-legend-chip" style="background:#fef3c7;color:#92400e"><i class="ph ph-warning" aria-hidden="true"></i> Avec écart</span>
  <span class="vsm-legend-chip" style="background:#dbeafe;color:#1d4ed8"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Réajusté</span>
  <span class="vsm-legend-chip" style="background:#fee2e2;color:#991b1b"><i class="ph ph-x-circle" aria-hidden="true"></i> Bloqué</span>
</div>

<div class="vsm-section">
  <div class="vsm-section-hdr">
    <div class="vsm-section-title">
      <i class="ph-duotone ph-clock-counter-clockwise" style="font-size:17px"></i>
      Historique des 30 derniers jours
      <span class="vsm-cnt" id="hist-count"><?= count($historique_validations) ?></span>
    </div>
    <span style="font-size:12px;color:var(--muted)" id="hist-filter-info"></span>
  </div>
  <table class="vsm-tbl" id="hist-table">
    <thead><tr>
      <th>Site</th>
      <th class="tc">Date validation</th>
      <th class="tc">Statut</th>
      <th>Traité par</th>
      <th>Commentaire</th>
      <th class="tc">Détails</th>
    </tr></thead>
    <tbody id="hist-tbody">
    <?php foreach($historique_validations as $v):
      if ($v['statut'] === 'refuse') {
        $hbadge = '<span class="vsm-legend-chip" style="background:#fee2e2;color:#991b1b"><i class="ph ph-x-circle" aria-hidden="true"></i> Bloqué</span>';
        $hcat   = 'refuse';
      } elseif ($v['statut'] === 'reajuste') {
        $hbadge = '<span class="vsm-legend-chip" style="background:#dbeafe;color:#1d4ed8"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Réajusté</span>';
        $hcat   = 'reajuste';
      } elseif ($v['statut'] === 'autorise_ecart' || ($v['statut'] !== 'valide_gsb' && (int)$v['nb_ecarts'] > 0)) {
        $hbadge = '<span class="vsm-legend-chip" style="background:#fef3c7;color:#92400e"><i class="ph ph-warning" aria-hidden="true"></i> Avec écart</span>';
        $hcat   = 'avec_ecart';
      } else {
        $hbadge = '<span class="vsm-legend-chip" style="background:#d1fae5;color:#065f46"><i class="ph ph-check-circle" aria-hidden="true"></i> Conforme</span>';
        $hcat   = 'conforme';
      }
    ?>
    <tr data-site="<?= h($v['site_nom']) ?>" data-date="<?= h($v['date_validation']) ?>" data-cat="<?= $hcat ?>">
      <td><div class="vsm-site-name"><?= h($v['site_nom']) ?></div></td>
      <td class="tc">
        <div style="display:inline-flex;flex-direction:column;align-items:center;gap:2px">
          <span style="font-size:12.5px;font-weight:700;color:var(--navy)"><?= fmt_date($v['date_validation'],'d/m/Y') ?></span>
          <?php if($v['gsb_at']): ?>
          <span style="font-size:12px;color:var(--muted)">à <?= date('H:i', strtotime($v['gsb_at'])) ?></span>
          <?php endif; ?>
        </div>
      </td>
      <td class="tc"><?= $hbadge ?></td>
      <td style="font-size:12.5px"><?= $v['gsb_nom'] ? h($v['gsb_nom']) : '<span style="color:var(--muted)">Automatique</span>' ?></td>
      <td style="font-size:12px;color:var(--muted);max-width:160px"><?= $v['commentaire'] ? h($v['commentaire']) : '<span style="color:var(--border)">—</span>' ?></td>
      <td class="tc">
        <button class="btn-vsm-detail"
          onclick="voirDetails(<?= $v['site_id'] ?>,'<?= h($v['site_nom']) ?>',<?= (int)$v['nb_ecarts'] ?>,<?= htmlspecialchars(json_encode($v['details_ecarts'] ?? '[]'), ENT_QUOTES) ?>,'<?= h($v['statut']) ?>','<?= h($v['commentaire']??'') ?>','<?= h($v['gsb_nom']??'Auto') ?>','<?= h(fmt_datetime($v['gsb_at'])) ?>','<?= h($v['date_validation']) ?>')">
          <i class="ph-duotone ph-eye"></i> Voir
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($historique_validations)): ?>
    <tr><td colspan="7" style="text-align:center;padding:36px;color:var(--muted)">Aucune validation dans les 30 derniers jours.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

</div><!-- fin tab-historique -->

<?php endif; // fin else GSB/Admin ?>

<!-- MINI-MODAL DEMANDE DE CORRECTION PAR BOBINE -->
<div id="miniModalCorr" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1200;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;width:500px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3);padding:28px">
    <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin-bottom:4px">
      <i class="ph ph-pencil-simple" aria-hidden="true"></i> Demander une correction
    </div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:16px">
      Bobine : <strong id="miniCorrBobineNum" style="color:var(--navy)"></strong>
    </div>

    <!-- Comparatif Films PJ vs EMUCI -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
      <div style="background:#f0f4ff;border-radius:10px;padding:12px;text-align:center">
        <div style="font-size:12px;font-weight:700;color:#5b76ff;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Films saisie PJ</div>
        <div id="miniCorrFilmsPj" style="font-size:22px;font-weight:800;color:#1e2b4a">—</div>
      </div>
      <div style="background:#fff3e0;border-radius:10px;padding:12px;text-align:center">
        <div style="font-size:12px;font-weight:700;color:#e65100;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Films EMUCI</div>
        <div id="miniCorrFilmsEmuci" style="font-size:22px;font-weight:800;color:#1e2b4a">—</div>
      </div>
    </div>

    <!-- Films proposés -->
    <div style="margin-bottom:14px">
      <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">
        Films proposés <span style="color:#e74c3c">*</span>
        <span style="font-size:12px;font-weight:400;color:var(--muted);margin-left:6px">Valeur que vous proposez au coordinateur</span>
      </label>
      <input type="number" id="miniCorrFilmsProposes" min="0" class="form-control"
        style="text-align:center;font-size:20px;font-weight:800;color:var(--navy)">
    </div>

    <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">
      Motif <span style="color:#e74c3c">*</span>
    </label>
    <textarea id="miniCorrNotes" rows="2" class="form-control"
      placeholder="Expliquez la raison de la correction…"
      style="border-radius:10px;width:100%;box-sizing:border-box;margin-bottom:20px"></textarea>

    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button onclick="document.getElementById('miniModalCorr').style.display='none'"
              class="btn btn-secondary">Annuler</button>
      <button onclick="submitCorrectionBobine()" class="btn btn-primary">
        <i class="ph-duotone ph-paper-plane-tilt"></i> Envoyer au coordinateur
      </button>
    </div>
  </div>
</div>

<!-- MODAL DÉTAILS (lecture seule) -->
<div id="modalDetails" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:30px;overflow-y:auto">
  <div style="background:white;border-radius:20px;width:980px;max-width:96vw;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="padding:22px 28px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <div>
        <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:800;color:var(--navy)" id="detailTitle">Détails validation</h3>
        <div id="detailMeta" style="font-size:12px;color:var(--muted);margin-top:3px"></div>
      </div>
      <button onclick="document.getElementById('modalDetails').style.display='none'"
              style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="detailBody" style="padding:24px 28px;max-height:70vh;overflow-y:auto"></div>
  </div>
</div>

<!-- MODAL DÉCISION PAR BOBINE -->
<div id="modalDecBobine" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1300;align-items:center;justify-content:center;padding:20px;overflow-y:auto">
  <div style="background:white;border-radius:16px;width:520px;max-width:95vw;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);padding:26px">
    <div id="decBobTitle" style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin-bottom:4px">Décision</div>
    <div id="decBobSubtitle" style="font-size:13px;color:var(--muted);margin-bottom:16px"></div>
    <div id="decBobInfo"></div>
    <div id="decBobHint" style="border-left:4px solid;padding:11px 14px;border-radius:8px;font-size:12.5px;margin-bottom:16px"></div>
    <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">
      Commentaire / Motif <span style="color:#e74c3c">*</span>
    </label>
    <textarea id="decBobCommentaire" rows="2" class="form-control"
      placeholder="Expliquez la cause de l'écart ou la décision prise…"
      style="border-radius:10px;width:100%;box-sizing:border-box;margin-bottom:20px"></textarea>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button onclick="document.getElementById('modalDecBobine').style.display='none'" class="btn btn-secondary">Annuler</button>
      <button id="decBobConfirm" onclick="confirmerDecision()" class="btn btn-primary">Confirmer</button>
    </div>
  </div>
</div>

<!-- MODAL VÉRIFICATION -->
<div id="modalVSM" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:30px;overflow-y:auto">
  <div style="background:white;border-radius:20px;width:820px;max-width:95vw;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="padding:24px 28px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:17px;font-weight:800;color:var(--navy)" id="vsmTitle">Vérification stock</h3>
      <button onclick="fermerVSM()" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="vsmBody" style="padding:24px 28px">
      <div style="text-align:center;padding:40px;color:var(--muted)">⏳ Chargement...</div>
    </div>
  </div>
</div>

<script>
function ap(d){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(r=>r.json());}
function toast(m,t='success'){const bg={success:'#27ae60',error:'#e74c3c',info:'#1B75BC'}[t]||'#27ae60';let el=document.getElementById('toast-live');if(!el){el=document.createElement('div');el.id='toast-live';el.setAttribute('role','status');el.setAttribute('aria-live','polite');el.setAttribute('aria-atomic','true');document.body.appendChild(el);}clearTimeout(el._hideTimer);el.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;background:${bg};color:white;max-width:320px`;el.textContent=m;el._hideTimer=setTimeout(()=>{el.style.display='none';},4000);}

// ── ONGLETS
function switchTab(tab) {
  document.getElementById('tab-encours').style.display   = tab==='encours'    ? 'block' : 'none';
  document.getElementById('tab-historique').style.display= tab==='historique' ? 'block' : 'none';
  document.getElementById('tab-btn-encours').classList.toggle('active',    tab==='encours');
  document.getElementById('tab-btn-historique').classList.toggle('active', tab==='historique');
}

// ── FILTRES HISTORIQUE
function filtrerHistorique() {
  const search  = (document.getElementById('hist-search')?.value  || '').toLowerCase();
  const dateFrom= document.getElementById('hist-date-from')?.value || '';
  const dateTo  = document.getElementById('hist-date-to')?.value   || '';
  const site    = (document.getElementById('hist-site')?.value     || '').toLowerCase();
  const statut  = document.getElementById('hist-statut')?.value    || '';

  const rows = document.querySelectorAll('#hist-tbody tr[data-site]');
  let visible = 0;
  rows.forEach(row => {
    const rowSite = (row.dataset.site || '').toLowerCase();
    const rowDate = row.dataset.date || '';
    const rowCat  = row.dataset.cat  || '';
    const show = (!search  || rowSite.includes(search))
              && (!dateFrom|| rowDate >= dateFrom)
              && (!dateTo  || rowDate <= dateTo)
              && (!site    || rowSite.includes(site))
              && (!statut  || rowCat === statut);
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('hist-count').textContent = visible;
  const info = document.getElementById('hist-filter-info');
  if (info) info.textContent = visible < rows.length ? `${visible} / ${rows.length} résultats` : '';
}
function resetFiltresHistorique() {
  document.getElementById('hist-search').value    = '';
  document.getElementById('hist-date-from').value = '<?= date('Y-m-d', strtotime('-30 days')) ?>';
  document.getElementById('hist-date-to').value   = '<?= date('Y-m-d') ?>';
  document.getElementById('hist-site').value      = '';
  document.getElementById('hist-statut').value    = '';
  filtrerHistorique();
}

let currentSiteId=null, currentSiteNom='', currentEcarts=[], currentBobinesDetail=[];
let ccState = {}; // décisions par bobine pour les corrections coordinateur

// Métadonnées des 3 décisions possibles (libellés, couleurs, effet réel)
const DEC_META = {
  autorise_ecart:{icon:'⚠️', label:"Autoriser l'écart", court:'Écart autorisé',
    bg:'#FEF3C7', color:'#92400E', border:'#F59E0B',
    hint:"Le stock ERP EMUCI est conservé tel quel. Le coordinateur peut travailler malgré l'écart constaté sur cette bobine."},
  reajuste:{icon:'🔄', label:'Réajuster le stock', court:'Réajusté',
    bg:'#DBEAFE', color:'#1D4ED8', border:'#3B82F6',
    hint:"Le stock physique ERP EMUCI de cette bobine sera aligné sur la valeur EMUCI. Un mouvement d'ajustement est enregistré."},
  refuse:{icon:'❌', label:'Refuser / Bloquer', court:'Bloqué',
    bg:'#FEE2E2', color:'#991B1B', border:'#F87171',
    hint:"Une demande de correction de saisie est envoyée au coordinateur pour cette bobine. L'écart reste ouvert."},
};

async function verifierSite(siteId, siteNom) {
  currentSiteId = siteId;
  currentSiteNom = siteNom;
  ccState = {};
  const canValider = <?= $can_valider ? 'true' : 'false' ?>;

  // Spinner discret (pas de modal encore — on attend le résultat)
  toast(`⏳ Vérification ${siteNom}…`, 'info');

  let d;
  try {
    const r = await ap({action:'calculer_ecarts', site_id:siteId, date:'<?= h($f_date) ?>'});
    if (!r.success) { toast(`<i class="ph ph-x-circle" aria-hidden="true"></i> ${r.message}`, 'error'); return; }
    d = r.data;
  } catch(err) {
    toast('<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur réseau. Réessayez.', 'error'); return;
  }

  // ── 0 écart + import EMUCI présent → validation automatique silencieuse
  // Déclenché aussi si un record refuse/reajuste existe déjà (import antérieur incorrect)
  // mais PAS si le site est déjà valide_auto / valide_gsb / autorise_ecart (décision GSB déjà prise)
  const existStatut = d.validation?.statut ?? null;
  const dejaValide = ['valide_auto','valide_gsb','autorise_ecart'].includes(existStatut);
  if (canValider && !dejaValide && d.nb_ecarts === 0 && d.dernier_import) {
    const v = await ap({action:'valider_auto', site_id:siteId, date:'<?= h($f_date) ?>'});
    if (v.success) {
      toast(`<i class="ph ph-check-circle" aria-hidden="true"></i> ${siteNom} — Stock conforme, validé automatiquement`, 'success');
      // Supprimer la carte du site de la liste "en attente"
      const card = document.querySelector(`[data-site-id="${siteId}"]`);
      if (card) card.remove();
    } else {
      toast(`<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur validation : ${v.message}`, 'error');
    }
    return;
  }

  // ── Écarts détectés (ou déjà validé) → ouvrir le modal
  currentEcarts       = d.ecarts || [];
  currentBobinesDetail= d.bobines_detail || [];
  const bobines       = currentBobinesDetail;
  document.getElementById('vsmTitle').textContent = `🔍 ${siteNom}`;
  document.getElementById('modalVSM').style.display = 'flex';

  // ── KPIs
  let html = `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px">
      <div style="text-align:center;padding:12px;background:#D1FAE5;border-radius:10px">
        <div style="font-size:24px;font-weight:900;color:#065F46">${d.nb_bobines}</div>
        <div style="font-size:12px;color:#065F46;font-weight:700;text-transform:uppercase">Bobines vérifiées</div>
      </div>
      <div style="text-align:center;padding:12px;background:${(d.nb_ecarts_restants ?? d.nb_ecarts)>0?'#FEE2E2':'#D1FAE5'};border-radius:10px">
        <div style="font-size:24px;font-weight:900;color:${(d.nb_ecarts_restants ?? d.nb_ecarts)>0?'#991B1B':'#065F46'}">${d.nb_ecarts_restants ?? d.nb_ecarts}</div>
        <div style="font-size:12px;color:${(d.nb_ecarts_restants ?? d.nb_ecarts)>0?'#991B1B':'#065F46'};font-weight:700;text-transform:uppercase">Écarts à traiter</div>
        ${d.nb_ecarts_traites ? `<div style="font-size:10.5px;color:#1D4ED8;font-weight:700;margin-top:4px"><i class="ph ph-check-circle" aria-hidden="true"></i> ${d.nb_ecarts_traites} traitée(s)</div>` : ''}
      </div>
      <div style="text-align:center;padding:12px;background:#F0F4FF;border-radius:10px">
        <div style="font-size:12.5px;font-weight:700;color:var(--navy)">${d.dernier_import ? (() => { const dt=new Date(d.dernier_import.replace(' ','T')); return dt.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'})+' à '+dt.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}); })() : '—'}</div>
        <div style="font-size:12px;color:var(--muted);font-weight:700;text-transform:uppercase">Dernier import EMUCI</div>
      </div>
    </div>`;

  // ── Tableau toutes bobines
  //    En-têtes sur 2 lignes + conteneur scrollable : la colonne « Décision »
  //    doit rester visible quelle que soit la largeur d'écran.
  const thSub = (t, s) => `<div>${t}</div><div style="font-weight:500;opacity:.65;font-size:9.5px">${s}</div>`;
  html += `
    <div style="border:1.5px solid var(--border);border-radius:10px;overflow-x:auto;margin-bottom:16px">
      <table style="width:100%;border-collapse:collapse;min-width:620px">
        <thead><tr style="background:#06033A">
          <th style="padding:8px 10px;color:white;font-size:12px;text-align:left">N° Bobine</th>
          <th style="padding:8px 10px;color:white;font-size:12px;text-align:left">Type</th>
          ${d.dernier_import?`<th style="padding:8px 10px;color:white;font-size:12px;text-align:center">${thSub('Stock EMUCI','(Système)')}</th>`:''}
          <th style="padding:8px 10px;color:white;font-size:12px;text-align:center">${thSub('Stock Physique','(ERP EMUCI)')}</th>
          ${d.dernier_import?`<th style="padding:8px 10px;color:white;font-size:12px;text-align:center">Écart</th>`:''}
          <th style="padding:8px 10px;color:white;font-size:12px;text-align:center">Statut</th>
          ${canValider?`<th style="padding:8px 10px;color:white;font-size:12px;text-align:center">Décision</th>`:''}
        </tr></thead>
        <tbody>`;

  const nbCols = 4 + (d.dernier_import ? 2 : 0) + (canValider ? 1 : 0);
  if (bobines.length === 0) {
    html += `<tr><td colspan="${nbCols}" style="text-align:center;padding:20px;color:var(--muted)">Aucune bobine active sur ce site</td></tr>`;
  } else {
    bobines.forEach((b, i) => {
      const bg = b.has_ecart ? '#FFF7ED' : (i%2===0 ? 'white' : '#F8FAFC');
      html += `<tr style="background:${bg}">
        <td style="padding:9px 10px;font-family:monospace;font-weight:800;color:#06033A;font-size:12px">${b.numero}</td>
        <td style="padding:9px 10px;font-size:12px;color:var(--muted)">${b.type_code||b.format||'—'}</td>
        ${d.dernier_import ? `
        <td style="padding:9px 10px;text-align:center;font-weight:600;color:#1B75BC">${b.stock_systeme??'—'}</td>` : ''}
        <td style="padding:9px 10px;text-align:center;font-weight:700;color:${b.films_restants<=0?'#DC2626':b.films_restants<50?'#D97706':'#065F46'}">${b.films_restants??'—'}</td>
        ${d.dernier_import ? `
        <td style="padding:9px 10px;text-align:center;font-weight:800;color:${b.has_ecart?(b.ecart>0?'#DC2626':'#D97706'):'#065F46'}">
          ${b.has_ecart ? (b.ecart>0?'+':'')+b.ecart : '✓'}
        </td>` : ''}
        <td style="padding:9px 10px;text-align:center">
          ${b.has_ecart
            ? `<span style="background:#FEE2E2;color:#991B1B;padding:3px 8px;border-radius:8px;font-size:10.5px;font-weight:700;white-space:nowrap">⚠️ Écart</span>`
            : `<span style="background:#D1FAE5;color:#065F46;padding:3px 8px;border-radius:8px;font-size:10.5px;font-weight:700">✅ OK</span>`}
        </td>
        ${canValider ? `<td style="padding:7px 10px;text-align:center">${celluleDecision(b)}</td>` : ''}
      </tr>`;
    });
  }
  html += `</tbody></table></div>`;

  // Légende des boutons de décision par ligne
  if (canValider && d.nb_ecarts > 0) {
    html += `<div class="vsm-dec-help">
      <strong style="color:var(--navy)">Décision par bobine :</strong>
      <span>⚠️ Autoriser l'écart</span><span>🔄 Réajuster le stock</span><span>❌ Refuser / Bloquer</span>
    </div>`;
  }

  // ── Zone de décision (si GSB et écarts détectés — le cas 0 écart est auto-validé avant l'ouverture du modal)
  //    Chaque bobine se traite depuis sa propre ligne ; ce bloc ne sert qu'au
  //    traitement groupé des bobines encore en attente de décision.
  const nbRestants = d.nb_ecarts_restants ?? d.nb_ecarts ?? 0;
  if (canValider && d.nb_ecarts > 0) {
    if (nbRestants > 0) {
      html += `
        <div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:12px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#92400E">
          <strong>⚠️ ${nbRestants} écart(s) à traiter.</strong>
          Prenez votre décision bobine par bobine avec les boutons de la colonne « Décision »,
          ou appliquez une décision unique à toutes les bobines restantes ci-dessous.
          ${d.nb_ecarts_traites ? `<div style="margin-top:5px;font-size:12px">✔️ ${d.nb_ecarts_traites} bobine(s) déjà traitée(s). Le site sera validé et le coordinateur notifié une fois toutes les bobines traitées.</div>` : ''}
        </div>
        <div style="border:1.5px dashed var(--border);border-radius:10px;padding:14px 16px">
          <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
            Traitement groupé — ${nbRestants} bobine(s) restante(s)
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
            <button class="btn btn-success" onclick="ouvrirDecisionGroupee('autorise_ecart')" style="font-size:12.5px">
              ⚠️ Autoriser l'écart
            </button>
            <button class="btn btn-primary" onclick="ouvrirDecisionGroupee('reajuste')" style="font-size:12.5px">
              🔄 Réajuster le stock
            </button>
            <button class="btn btn-danger" onclick="ouvrirDecisionGroupee('refuse')" style="font-size:12.5px">
              ❌ Refuser / Bloquer
            </button>
          </div>
        </div>`;
    } else {
      html += `
        <div style="background:#D1FAE5;border-left:4px solid #34D399;padding:12px 16px;border-radius:8px;font-size:13px;color:#065F46">
          <strong>✅ Toutes les bobines en écart ont été traitées.</strong>
          ${d.validation ? '' : ' La validation du site est en cours d\'enregistrement.'}
        </div>`;
    }
  }
  if (d.validation) {
    const vStatuts = {
      valide_auto:'✅ Validé automatiquement', valide_gsb:'✅ Validé par GSB',
      autorise_ecart:'⚠️ Écart autorisé', reajuste:'🔄 Stock réajusté', refuse:'❌ Bloqué'
    };
    const vBg = {valide_auto:'#D1FAE5',valide_gsb:'#D1FAE5',autorise_ecart:'#FEF3C7',reajuste:'#EFF6FF',refuse:'#FEE2E2'};

    // ── Statut reajuste + panneau d'action par bobine
    if (canValider && d.validation && d.validation.statut === 'reajuste') {
      const ccNew     = d.coord_corrections         || [];
      const ccValides = d.coord_corrections_valides || [];
      const ccAttente = d.coord_en_attente          || [];
      const total     = ccNew.length; // seules les nouvelles soumissions nécessitent une décision

      let panelRows = '';

      // Lignes déjà validées (rounds précédents)
      ccValides.forEach(c => {
        panelRows += `
          <tr style="background:#F0FDF4;border-bottom:1px solid #E2E8F0">
            <td style="padding:9px 12px;font-family:monospace;font-weight:700;color:#065F46">${c.numero}</td>
            <td style="padding:9px 12px;text-align:center;color:#065F46;font-weight:800">${c.films_final}</td>
            <td style="padding:9px 12px;font-size:12px;color:#065F46">—</td>
            <td style="padding:9px 12px"></td>
            <td style="padding:9px 12px;text-align:center">
              <span style="background:#D1FAE5;color:#065F46;border-radius:8px;padding:3px 10px;font-size:11.5px;font-weight:700">✅ Validé</span>
            </td>
          </tr>`;
      });

      // Lignes en attente de re-correction coordinateur
      ccAttente.forEach(c => {
        panelRows += `
          <tr style="background:#FFFBEB;border-bottom:1px solid #E2E8F0">
            <td style="padding:9px 12px;font-family:monospace;font-weight:700;color:#92400E">${c.numero}</td>
            <td style="padding:9px 12px;text-align:center;color:#92400E">—</td>
            <td style="padding:9px 12px;font-size:12px;color:#92400E">—</td>
            <td style="padding:9px 12px"></td>
            <td style="padding:9px 12px;text-align:center">
              <span style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:3px 10px;font-size:11.5px;font-weight:700">⏳ Coord. doit re-corriger</span>
            </td>
          </tr>`;
      });

      // Lignes avec nouvelles soumissions coordinateur → boutons ✅/❌
      ccNew.forEach((c, i) => {
        panelRows += `
          <tr id="ccrow-${i}" style="border-bottom:1px solid #E2E8F0;transition:background .2s">
            <td style="padding:9px 12px;font-family:monospace;font-weight:700;color:#06033A">${c.numero}</td>
            <td style="padding:9px 12px;text-align:center;font-weight:800;color:#1D4ED8;font-size:15px">${c.films_final}</td>
            <td style="padding:9px 12px;font-size:12px;color:#475569">${c.reponse_coord || '—'}</td>
            <td style="padding:9px 12px">
              <input type="text" id="cc-comment-${i}" placeholder="Motif GSB (requis si refus)"
                     style="width:100%;box-sizing:border-box;padding:5px 8px;border:1.5px solid #E2E8F0;border-radius:6px;font-size:12px;min-width:130px">
            </td>
            <td style="padding:9px 12px;text-align:center;white-space:nowrap">
              <div style="display:flex;gap:6px;justify-content:center">
                <button id="cc-btn-ok-${i}" onclick="setCoordDec(${i},${c.bobine_id},'valider',${total})"
                        style="padding:6px 12px;background:#D1FAE5;color:#065F46;border:1.5px solid #34D399;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;">
                  ✅ Valider
                </button>
                <button id="cc-btn-ref-${i}" onclick="setCoordDec(${i},${c.bobine_id},'refuser',${total})"
                        style="padding:6px 12px;background:#FEE2E2;color:#991B1B;border:1.5px solid #F87171;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;">
                  ❌ Refuser
                </button>
              </div>
              <div id="cc-badge-${i}" style="display:none;font-size:10.5px;font-weight:700;margin-top:4px;text-align:center"></div>
            </td>
          </tr>`;
      });

      const hasAnything = ccNew.length > 0 || ccValides.length > 0 || ccAttente.length > 0;
      if (hasAnything) {
        html += `
          <div style="background:#EFF6FF;border:2px solid #BFDBFE;border-radius:12px;padding:18px 20px;margin-top:16px">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">
              <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;color:#1D4ED8;font-size:14px">
                📋 Corrections du coordinateur
              </div>
              ${ccValides.length > 0 ? `<span style="font-size:12px;background:#D1FAE5;color:#065F46;border-radius:8px;padding:3px 10px;font-weight:700">${ccValides.length} validée(s) ✅</span>` : ''}
            </div>
            ${ccNew.length > 0 ? `<p style="font-size:12.5px;color:#3B82F6;margin-bottom:14px">Validez (✅) ou refusez (❌) chaque nouvelle correction du coordinateur, puis confirmez.</p>` : ''}
            ${ccAttente.length > 0 && ccNew.length === 0 ? `<div style="font-size:12.5px;color:#92400E;background:#FEF3C7;border-radius:8px;padding:10px 14px;margin-bottom:14px">⏳ En attente : le coordinateur doit re-soumettre ${ccAttente.length} bobine(s) refusée(s).</div>` : ''}
            <div style="overflow-x:auto;margin-bottom:${total > 0 ? 14 : 0}px">
            <table style="width:100%;border-collapse:collapse;font-size:13px;background:white;border-radius:10px;overflow:hidden">
              <thead>
                <tr style="background:#1D4ED8">
                  <th style="padding:9px 12px;color:white;font-size:12px;font-weight:700;text-transform:uppercase;text-align:left">N° Bobine</th>
                  <th style="padding:9px 12px;color:white;font-size:12px;font-weight:700;text-transform:uppercase;text-align:center">Valeur coord</th>
                  <th style="padding:9px 12px;color:white;font-size:12px;font-weight:700;text-transform:uppercase;text-align:left">Justification coord</th>
                  <th style="padding:9px 12px;color:white;font-size:12px;font-weight:700;text-transform:uppercase;text-align:left">Commentaire GSB</th>
                  <th style="padding:9px 12px;color:white;font-size:12px;font-weight:700;text-transform:uppercase;text-align:center">Statut / Décision</th>
                </tr>
              </thead>
              <tbody>${panelRows}</tbody>
            </table>
            </div>
            ${total > 0 ? `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <span id="cc-progress" style="font-size:12.5px;color:var(--muted);font-weight:600">
                0 / ${total} bobine(s) décidée(s)
              </span>
              <button id="cc-confirm-btn" disabled onclick="confirmerDecisionsCoord(${siteId},${total})"
                      style="padding:11px 24px;background:#06033A;color:white;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:not-allowed;opacity:.5;transition: background-color .2s, border-color .2s, color .2s, box-shadow .2s, transform .2s, opacity .2s;white-space:nowrap">
                Confirmer les décisions
              </button>
            </div>` : ''}
          </div>`;
      } else {
        // Réajusté mais le coordinateur n'a pas encore rien soumis
        html += `
          <div style="background:#FEF3C7;border:1.5px solid #F59E0B;border-radius:10px;padding:14px 18px;margin-top:14px;font-size:13px;color:#92400E">
            <strong>🔄 Stock réajusté</strong>
            ${d.validation.commentaire ? `<div style="margin-top:4px">💬 ${d.validation.commentaire}</div>` : ''}
            <div style="margin-top:8px;font-size:12px;opacity:.85">
              ⏳ En attente des corrections du coordinateur — il sera notifié de fournir les valeurs correctes.
            </div>
          </div>`;
      }
    } else {
      // Autres statuts — récapitulatif standard
      html += `
        <div style="background:${vBg[d.validation.statut]||'#F0F9FF'};border-radius:10px;padding:14px 18px;font-size:13px;margin-top:14px">
          <strong>${vStatuts[d.validation.statut]||d.validation.statut}</strong>
          ${d.validation.commentaire ? `<div style="color:var(--muted);margin-top:4px">💬 ${d.validation.commentaire}</div>` : ''}
        </div>`;
      if (canValider) {
        html += `<div style="margin-top:12px;text-align:right">
          <button class="btn btn-secondary btn-sm" onclick="verifierSite(${siteId},'${siteNom}')">🔄 Réviser</button>
        </div>`;
      }
    }
  }

  document.getElementById('vsmBody').innerHTML = html;
}

async function validerAuto(){
  const d=await ap({action:'valider_auto',site_id:currentSiteId,date:'<?= h($f_date) ?>'});
  if(d.success){toast(d.message);fermerVSM();setTimeout(()=>location.reload(),800);}
  else toast(d.message,'error');
}

async function validerSansImport(siteId, date, siteNom){
  const commentaire = prompt(`Aucun import EMUCI n'existe pour « ${siteNom} » à cette date. Motif de la validation manuelle (obligatoire) :`);
  if (commentaire === null) return;
  if (!commentaire.trim()) { toast('Un commentaire est obligatoire.', 'error'); return; }
  try {
    const d = await ap({action:'valider_sans_import', site_id:siteId, date, commentaire: commentaire.trim()});
    toast(d.message, d.success ? 'success' : 'error');
    if (d.success) setTimeout(()=>location.reload(), 800);
  } catch(e) { toast('Erreur réseau.', 'error'); }
}

// ── Cellule « Décision » d'une ligne bobine
function celluleDecision(b) {
  if (b.decision) {
    const m = DEC_META[b.decision.decision] || {icon:'•', court:b.decision.decision, bg:'#F1F5F9', color:'#475569'};
    return `<span class="vsm-dec-badge" style="background:${m.bg};color:${m.color}" title="${(b.decision.commentaire||'').replace(/"/g,'&quot;')}">${m.icon} ${m.court}</span>
            <button class="vsm-dec-edit" onclick="ouvrirDecisionBobine(${b.bobine_id})">Modifier</button>`;
  }
  if (!b.has_ecart) return '<span style="color:var(--muted);font-size:12px">—</span>';
  return `<div class="vsm-dec-group">
      <button class="vsm-dec-btn dec-autorise_ecart" title="Autoriser l'écart" aria-label="Autoriser l'écart"
              onclick="ouvrirDecisionBobine(${b.bobine_id},'autorise_ecart')">⚠️</button>
      <button class="vsm-dec-btn dec-reajuste" title="Réajuster le stock" aria-label="Réajuster le stock"
              onclick="ouvrirDecisionBobine(${b.bobine_id},'reajuste')">🔄</button>
      <button class="vsm-dec-btn dec-refuse" title="Refuser / Bloquer" aria-label="Refuser / Bloquer"
              onclick="ouvrirDecisionBobine(${b.bobine_id},'refuse')">❌</button>
    </div>`;
}

// ── Décision en attente de confirmation : {bobines:[...], decision:'...'}
let decisionEnCours = null;

function ouvrirDecisionBobine(bobineId, decision) {
  const b = currentBobinesDetail.find(x => Number(x.bobine_id) === Number(bobineId));
  if (!b) { toast('Bobine introuvable.', 'error'); return; }
  if (!decision) decision = b.decision?.decision || 'reajuste';
  const m = DEC_META[decision];

  document.getElementById('decBobTitle').textContent    = `${m.icon} ${m.label}`;
  document.getElementById('decBobSubtitle').innerHTML   =
    `Bobine <strong style="color:var(--navy);font-family:monospace">${b.numero}</strong> — ${currentSiteNom}`;
  document.getElementById('decBobInfo').innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">
      <div style="background:#f0f4ff;border-radius:10px;padding:11px;text-align:center">
        <div style="font-size:12px;font-weight:700;color:#1B75BC;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Stock EMUCI</div>
        <div style="font-size:20px;font-weight:800;color:var(--navy)">${b.stock_systeme ?? '—'}</div>
      </div>
      <div style="background:#f0fdf4;border-radius:10px;padding:11px;text-align:center">
        <div style="font-size:12px;font-weight:700;color:#065F46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Stock ERP EMUCI</div>
        <div style="font-size:20px;font-weight:800;color:var(--navy)">${b.films_restants ?? '—'}</div>
      </div>
      <div style="background:#fef2f2;border-radius:10px;padding:11px;text-align:center">
        <div style="font-size:12px;font-weight:700;color:#991B1B;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Écart</div>
        <div style="font-size:20px;font-weight:800;color:${b.ecart>0?'#DC2626':'#D97706'}">${b.ecart>0?'+':''}${b.ecart ?? 0}</div>
      </div>
    </div>`;
  const hint = document.getElementById('decBobHint');
  hint.style.background   = m.bg;
  hint.style.borderColor  = m.border;
  hint.style.color        = m.color;
  hint.textContent        = m.hint;

  document.getElementById('decBobCommentaire').value = b.decision?.commentaire || '';
  document.getElementById('decBobConfirm').textContent = `${m.icon} ${m.label}`;

  decisionEnCours = { bobines: [b], decision };
  document.getElementById('modalDecBobine').style.display = 'flex';
  setTimeout(() => document.getElementById('decBobCommentaire').focus(), 60);
}

function ouvrirDecisionGroupee(decision) {
  // Toutes les bobines en écart n'ayant pas encore de décision
  const restantes = currentEcarts.filter(e =>
    !currentBobinesDetail.find(b => Number(b.bobine_id) === Number(e.bobine_id))?.decision);
  if (!restantes.length) { toast('Aucune bobine en attente de décision.', 'info'); return; }
  const m = DEC_META[decision];

  document.getElementById('decBobTitle').textContent  = `${m.icon} ${m.label}`;
  document.getElementById('decBobSubtitle').innerHTML =
    `<strong style="color:var(--navy)">${restantes.length} bobine(s)</strong> en écart — ${currentSiteNom}`;
  document.getElementById('decBobInfo').innerHTML = `
    <div style="border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;margin-bottom:14px;max-height:150px;overflow-y:auto;font-size:12px">
      ${restantes.map(e => `<div style="display:flex;justify-content:space-between;padding:3px 0">
        <span style="font-family:monospace;font-weight:700;color:var(--navy)">${e.numero}</span>
        <span style="color:${e.ecart>0?'#DC2626':'#D97706'};font-weight:700">${e.ecart>0?'+':''}${e.ecart}</span>
      </div>`).join('')}
    </div>`;
  const hint = document.getElementById('decBobHint');
  hint.style.background  = m.bg;
  hint.style.borderColor = m.border;
  hint.style.color       = m.color;
  hint.textContent       = m.hint.replace('cette bobine', 'chacune de ces bobines');

  document.getElementById('decBobCommentaire').value   = '';
  document.getElementById('decBobConfirm').textContent = `${m.icon} ${m.label} (${restantes.length})`;

  decisionEnCours = { bobines: restantes, decision };
  document.getElementById('modalDecBobine').style.display = 'flex';
  setTimeout(() => document.getElementById('decBobCommentaire').focus(), 60);
}

async function confirmerDecision() {
  if (!decisionEnCours) return;
  const commentaire = document.getElementById('decBobCommentaire').value.trim();
  if (!commentaire) { alert('Le commentaire est obligatoire.'); return; }

  const btn = document.getElementById('decBobConfirm');
  const txt = btn.textContent;
  btn.disabled = true; btn.textContent = '⏳ Enregistrement…';
  try {
    const d = await ap({
      action:'decision_gsb', site_id:currentSiteId, date:'<?= h($f_date) ?>',
      decision:decisionEnCours.decision, commentaire,
      ecarts_json:JSON.stringify(decisionEnCours.bobines),
      bobines_json:JSON.stringify(currentBobinesDetail)
    });
    if (!d.success) { toast(d.message, 'error'); return; }

    document.getElementById('modalDecBobine').style.display = 'none';
    decisionEnCours = null;
    toast(d.message);
    if (d.data?.site_complet) {
      // Site entièrement traité : validation enregistrée, on rafraîchit la page
      fermerVSM();
      setTimeout(() => location.reload(), 900);
    } else {
      // Traitement partiel : on recharge le tableau pour refléter la décision
      verifierSite(currentSiteId, currentSiteNom);
    }
  } catch(err) {
    toast('<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur réseau. Réessayez.', 'error');
  } finally {
    btn.disabled = false; btn.textContent = txt;
  }
}

async function verifierTousSites(){
  const sites=[...document.querySelectorAll('[onclick^="verifierSite"]')].map(b=>b.getAttribute('onclick').match(/\d+/)?.[0]);
  if(!sites.length){alert('Tous les sites sont déjà vérifiés.');return;}
  // Auto-vérifier le premier site non validé
  const first=document.querySelector('[onclick^="verifierSite"]');
  if(first) first.click();
}

function fermerVSM(){document.getElementById('modalVSM').style.display='none';}
document.getElementById('modalVSM').addEventListener('click',e=>{if(e.target===document.getElementById('modalVSM'))fermerVSM();});
document.getElementById('modalDetails').addEventListener('click',e=>{if(e.target===document.getElementById('modalDetails'))e.target.style.display='none';});
document.getElementById('miniModalCorr').addEventListener('click',e=>{if(e.target===document.getElementById('miniModalCorr'))e.target.style.display='none';});
document.getElementById('modalDecBobine').addEventListener('click',e=>{if(e.target===document.getElementById('modalDecBobine')){e.target.style.display='none';decisionEnCours=null;}});

function demanderModifBobine(bobineId, bobineNum, siteId, date, filmsPj, filmsEmuci, ecart) {
  const m = document.getElementById('miniModalCorr');
  document.getElementById('miniCorrBobineNum').textContent      = bobineNum;
  document.getElementById('miniCorrNotes').value                = '';
  document.getElementById('miniCorrFilmsPj').textContent        = filmsPj;
  document.getElementById('miniCorrFilmsEmuci').textContent     = filmsEmuci !== null && filmsEmuci !== undefined ? filmsEmuci : '—';
  document.getElementById('miniCorrFilmsProposes').value        = filmsEmuci !== null && filmsEmuci !== undefined ? filmsEmuci : filmsPj;
  m.dataset.bobineId   = bobineId;
  m.dataset.siteId     = siteId;
  m.dataset.date       = date;
  m.dataset.filmsPj    = filmsPj;
  m.dataset.filmsEmuci = filmsEmuci;
  m.dataset.ecart      = ecart;
  m.style.display = 'flex';
}

async function submitCorrectionBobine() {
  const m             = document.getElementById('miniModalCorr');
  const notes         = document.getElementById('miniCorrNotes').value.trim();
  const filmsProposes = document.getElementById('miniCorrFilmsProposes').value.trim();
  if (!notes)         { alert('Le motif est obligatoire.'); return; }
  if (filmsProposes === '' || isNaN(parseInt(filmsProposes))) {
    alert('Veuillez saisir le nombre de films proposé.'); return;
  }
  try {
    const d = await ap({
      action:          'demander_correction_bobine',
      bobine_id:       m.dataset.bobineId,
      site_id:         m.dataset.siteId,
      date:            m.dataset.date,
      notes_gsb:       notes,
      films_pj:        m.dataset.filmsPj,
      films_proposes:  filmsProposes,
      films_emuci:     m.dataset.filmsEmuci,
      ecart:           m.dataset.ecart,
    });
    if (d.success) {
      toast('<i class="ph ph-check-circle" aria-hidden="true"></i> Demande envoyée. Le coordinateur a été notifié.', 'success');
      m.style.display = 'none';
    } else {
      toast('<i class="ph ph-x-circle" aria-hidden="true"></i> ' + d.message, 'danger');
    }
  } catch(err) {
    toast('<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur réseau. Réessayez.', 'danger');
  }
}

// ── Voir détails d'une validation (lecture seule)
async function voirDetails(siteId, siteNom, nbEcarts, detailsJson, statut, commentaire, gsbNom, gsbAt, dateVal) {
  document.getElementById('detailTitle').textContent = `🔍 ${siteNom}`;
  document.getElementById('detailMeta').textContent  = `Validé le <?= h($f_date) ?> par ${gsbNom} — ${gsbAt}`;
  document.getElementById('detailBody').innerHTML    = '<div style="text-align:center;padding:30px;color:var(--muted)">⏳ Chargement...</div>';
  document.getElementById('modalDetails').style.display = 'flex';

  // Charger la liste complète des bobines vérifiées via AJAX
  const _r = await ap({action:'calculer_ecarts', site_id:siteId, date:'<?= h($f_date) ?>'});
  if(!_r.success){document.getElementById('detailBody').innerHTML='<div class="alert alert-danger">'+(_r.message||'Erreur')+'</div>';return;}
  const d = _r.data || {};

  const nb_bobines  = d.nb_bobines  ?? 0;
  const nb_ecarts   = d.nb_ecarts   ?? 0;
  const fmtImport = d.dernier_import
    ? (() => { const dt = new Date(d.dernier_import.replace(' ','T')); return dt.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'}) + ' à ' + dt.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}); })()
    : '—';

  const statutLabels = {
    valide_auto:'✅ Validé automatiquement', valide_gsb:'✅ Validé par GSB',
    autorise_ecart:'⚠️ Écart autorisé', reajuste:'🔄 Stock réajusté', refuse:'❌ Bloqué'
  };
  const statutColors = {
    valide_auto:'#d1fae5', valide_gsb:'#d1fae5',
    autorise_ecart:'#fef3c7', reajuste:'#dbeafe', refuse:'#fee2e2'
  };
  const statutTextColors = {
    valide_auto:'#065f46', valide_gsb:'#065f46',
    autorise_ecart:'#92400e', reajuste:'#1d4ed8', refuse:'#991b1b'
  };

  // En-tête statut
  let html = `
    <div style="background:${statutColors[statut]||'#f8fafc'};border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-weight:800;font-size:15px;color:${statutTextColors[statut]||'var(--navy)'}">${statutLabels[statut]||statut}</div>
        ${commentaire?`<div style="font-size:12.5px;margin-top:4px;color:${statutTextColors[statut]||'var(--muted)'}"><i class="ph ph-chat-circle" aria-hidden="true"></i> ${commentaire}</div>`:''}
      </div>
      <div style="text-align:right;font-size:12px;color:var(--muted)">
        <div>${gsbNom}</div><div>${gsbAt}</div>
      </div>
    </div>`;

  // Stats
  html += `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:18px">
      <div style="text-align:center;padding:12px;background:var(--tertiary);border-radius:10px">
        <div style="font-size:22px;font-weight:900;color:var(--navy)">${nb_bobines}</div>
        <div style="font-size:12px;color:var(--muted);font-weight:600">Bobines vérifiées</div>
      </div>
      <div style="text-align:center;padding:12px;background:${nb_ecarts>0?'#fee2e2':'#d1fae5'};border-radius:10px">
        <div style="font-size:22px;font-weight:900;color:${nb_ecarts>0?'#991b1b':'#065f46'}">${nb_ecarts}</div>
        <div style="font-size:12px;color:${nb_ecarts>0?'#991b1b':'#065f46'};font-weight:600">Écarts</div>
      </div>
      <div style="text-align:center;padding:12px;background:var(--tertiary);border-radius:10px">
        <div style="font-size:12.5px;font-weight:700;color:var(--navy)">${fmtImport}</div>
        <div style="font-size:12px;color:var(--muted);font-weight:600">Dernier import EMUCI</div>
      </div>
    </div>`;

  // ── Tableau détaillé : une ligne par bobine saisie dans le PJ
  const bobinesDetail = d.bobines_detail || [];
  const hasImport = !!d.dernier_import;

  let tableTitle = bobinesDetail.length > 0
    ? `🎞️ Détail par bobine saisie dans le Point Journalier (${bobinesDetail.length} bobine(s))`
    : '🎞️ Détail des bobines';

  html += `<div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:10px">${tableTitle}</div>`;

  if (bobinesDetail.length === 0) {
    // Si la validation existe déjà (auto ou manuelle), le point a été vérifié même sans détail lisible ici
    const msgValide = ['valide_auto','valide_gsb'].includes(statut);
    html += msgValide
      ? `<div style="background:#D1FAE5;border-radius:10px;padding:16px;font-size:13px;color:#065F46">
           ✅ Le stock a été validé pour cette date. Les données détaillées ne sont plus disponibles pour relecture.
         </div>`
      : `<div style="background:#FEF3C7;border-radius:10px;padding:16px;font-size:13px;color:#92400E">
           ⚠️ Aucune donnée bobine trouvée pour cette date. La comparaison avec EMUCI n'est pas possible.
         </div>`;
  } else {
    html += `
    <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="background:#06033A">
            <th style="padding:9px 12px;color:white;font-size:10.5px;text-align:left">N° Bobine</th>
            <th style="padding:9px 12px;color:white;font-size:10.5px;text-align:left">Type</th>
            ${hasImport ? '<th style="padding:9px 12px;color:white;font-size:10.5px;text-align:center">Stock EMUCI (Système)</th>' : ''}
            <th style="padding:9px 12px;color:white;font-size:10.5px;text-align:center">Stock Physique (ERP EMUCI)</th>
            ${hasImport ? '<th style="padding:9px 12px;color:white;font-size:10.5px;text-align:center">Écart</th>' : ''}
            <th style="padding:9px 12px;color:white;font-size:10.5px;text-align:center">Statut</th>
            ${<?= $can_valider ? 'true' : 'false' ?> ? '<th style="padding:9px 12px;color:white;font-size:10.5px;text-align:center">Action</th>' : ''}
          </tr>
        </thead>
        <tbody>`;

    bobinesDetail.forEach((b, i) => {
      const hasEcart = b.has_ecart;
      const rowBg = hasEcart ? '#FFF7ED' : (i%2===0 ? 'white' : '#F8FAFC');
      const ecartColor = b.ecart > 0 ? '#DC2626' : '#D97706';

      html += `<tr style="background:${rowBg}">
        <td style="padding:9px 12px;font-family:monospace;font-weight:800;color:#06033A">${b.numero}</td>
        <td style="padding:9px 12px;font-size:11.5px;color:var(--muted)">${b.type_code||b.format||'—'}</td>
        ${hasImport ? `
        <td style="padding:9px 12px;text-align:center;font-weight:600;color:#1B75BC">${b.stock_systeme}</td>` : ''}
        <td style="padding:9px 12px;text-align:center;font-weight:700;color:${b.films_restants<=0?'#DC2626':b.films_restants<50?'#D97706':'#065F46'};font-size:14px">${b.films_restants}</td>
        ${hasImport ? `
        <td style="padding:9px 12px;text-align:center;font-weight:800;color:${hasEcart?ecartColor:'var(--success-d)'}">
          ${hasEcart ? (b.ecart>0?'+':'')+b.ecart : '✓'}
        </td>` : ''}
        <td style="padding:9px 12px;text-align:center">
          ${hasEcart
            ? `<span style="background:#FEE2E2;color:#991B1B;padding:3px 9px;border-radius:8px;font-size:10.5px;font-weight:700">⚠️ Écart ${b.ecart>0?'+':''}${b.ecart}</span>`
            : '<span style="background:#D1FAE5;color:#065F46;padding:3px 9px;border-radius:8px;font-size:10.5px;font-weight:700">✅ OK</span>'}
          ${b.decision ? (() => {
            const dm = DEC_META[b.decision.decision] || {icon:'•', court:b.decision.decision, bg:'#F1F5F9', color:'#475569'};
            return `<div style="margin-top:4px"><span class="vsm-dec-badge" style="background:${dm.bg};color:${dm.color}" title="${(b.decision.commentaire||'').replace(/"/g,'&quot;')}">${dm.icon} ${dm.court}</span></div>`;
          })() : ''}
        </td>
        ${<?= $can_valider ? 'true' : 'false' ?> ? `<td style="padding:7px 12px;text-align:center">
          <button onclick="demanderModifBobine(${b.bobine_id},'${b.numero}',${siteId},'<?= h($f_date) ?>',${b.films_restants||0},${b.stock_systeme||0},${b.ecart||0})"
            style="background:#fff7ed;color:#c2410c;border:1.5px solid #fed7aa;padding:4px 9px;border-radius:7px;font-size:10.5px;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:4px">
            ✏️ Demander modif
          </button>
        </td>` : '<td></td>'}
      </tr>`;

    });

    html += `</tbody></table></div>`;

    // Note si pas d'import EMUCI disponible
    if (!hasImport) {
      html += `<div style="margin-top:10px;font-size:12px;color:var(--muted);background:var(--tertiary);padding:8px 12px;border-radius:8px">
        ℹ️ Aucun import OptoPlate disponible — la comparaison EMUCI n'est pas affichée.
      </div>`;
    }
  }

  html += `</div>`;
  document.getElementById('detailBody').innerHTML = html;
}

// ── Décision par bobine dans le panneau corrections coordinateur
function setCoordDec(idx, bobineId, action, total) {
  ccState[bobineId] = { action, idx };

  const row    = document.getElementById(`ccrow-${idx}`);
  const okBtn  = document.getElementById(`cc-btn-ok-${idx}`);
  const refBtn = document.getElementById(`cc-btn-ref-${idx}`);
  const badge  = document.getElementById(`cc-badge-${idx}`);

  if (row)    row.style.background = action === 'valider' ? '#F0FDF4' : '#FFF1F2';
  if (okBtn)  { okBtn.style.opacity = action === 'valider' ? '1' : '0.4'; okBtn.style.transform = action === 'valider' ? 'scale(1.06)' : 'scale(1)'; }
  if (refBtn) { refBtn.style.opacity = action === 'refuser' ? '1' : '0.4'; refBtn.style.transform = action === 'refuser' ? 'scale(1.06)' : 'scale(1)'; }
  if (badge)  {
    badge.style.display = 'block';
    badge.textContent   = action === 'valider' ? '✅ Validé' : '❌ À recorriger';
    badge.style.color   = action === 'valider' ? '#065F46' : '#991B1B';
  }

  const decided  = Object.keys(ccState).length;
  const progress = document.getElementById('cc-progress');
  const btn      = document.getElementById('cc-confirm-btn');
  if (progress) progress.textContent = `${decided} / ${total} bobine(s) décidée(s)`;
  if (btn && decided >= total) {
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor  = 'pointer';
  }
}

// ── Confirmer toutes les décisions par bobine
async function confirmerDecisionsCoord(siteId, total) {
  const payload = [];
  for (const [bobineId, dec] of Object.entries(ccState)) {
    const commentEl = document.getElementById(`cc-comment-${dec.idx}`);
    const comment   = commentEl?.value.trim() || '';
    if (dec.action === 'refuser' && !comment) {
      if (commentEl) { commentEl.style.borderColor = '#F87171'; commentEl.focus(); }
      toast('Un commentaire est obligatoire pour refuser la correction d\'une bobine.', 'error');
      return;
    }
    payload.push({ bobine_id: parseInt(bobineId), action: dec.action, commentaire: comment });
  }
  if (payload.length < total) { toast('Décidez pour toutes les bobines avant de confirmer.', 'error'); return; }

  const btn = document.getElementById('cc-confirm-btn');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Traitement…'; }
  try {
    const res = await ap({
      action:          'traiter_corrections_coord',
      site_id:         siteId,
      date:            '<?= h($f_date) ?>',
      decisions_json:  JSON.stringify(payload),
    });
    if (res.success) {
      toast('<i class="ph ph-check-circle" aria-hidden="true"></i> ' + res.message, 'success');
      fermerVSM();
      setTimeout(() => location.reload(), 900);
    } else {
      toast('<i class="ph ph-x-circle" aria-hidden="true"></i> ' + res.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Confirmer les décisions'; }
    }
  } catch(err) {
    toast('<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur réseau. Réessayez.', 'error');
    if (btn) { btn.disabled = false; btn.textContent = 'Confirmer les décisions'; }
  }
}

// ── Soumission des corrections coordinateur (réponse à un réajustement GSB)
async function soumettreCorrectionsCoord(siteId, date, nbBobines) {
  const bobines = [];
  for (let i = 0; i < nbBobines; i++) {
    const filmsEl = document.getElementById('cr-films-' + i);
    const motifEl = document.getElementById('cr-motif-' + i);
    if (!filmsEl || !motifEl) continue;
    if (filmsEl.disabled) continue; // bobine déjà validée par GSB ou soumise
    const films = parseInt(filmsEl.value);
    const motif = motifEl.value.trim();
    if (!motif) {
      motifEl.style.borderColor = '#F87171';
      motifEl.focus();
      toast('Veuillez saisir un motif pour chaque bobine.', 'error');
      return;
    }
    if (isNaN(films) || films < 0) {
      filmsEl.style.borderColor = '#F87171';
      filmsEl.focus();
      toast('Valeur incorrecte pour une bobine.', 'error');
      return;
    }
    bobines.push({
      bobine_id:     parseInt(filmsEl.dataset.bobine),
      films_correct: films,
      motif_coord:   motif,
    });
  }
  if (!bobines.length) return;

  const btn = document.querySelector('[onclick^="soumettreCorrectionsCoord"]');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Envoi…'; }

  try {
    const res = await ap({
      action:       'coord_repond_reajust',
      site_id:      siteId,
      date:         date,
      bobines_json: JSON.stringify(bobines),
    });
    if (res.success) {
      toast('<i class="ph ph-check-circle" aria-hidden="true"></i> ' + res.message, 'success');
      setTimeout(() => location.reload(), 1400);
    } else {
      toast('<i class="ph ph-x-circle" aria-hidden="true"></i> ' + res.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = '📤 Soumettre mes corrections'; }
    }
  } catch(err) {
    toast('<i class="ph ph-x-circle" aria-hidden="true"></i> Erreur réseau. Réessayez.', 'error');
    if (btn) { btn.disabled = false; btn.textContent = '📤 Soumettre mes corrections'; }
  }
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
