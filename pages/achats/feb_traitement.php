<?php
// ============================================================
//  pages/achats/feb_traitement.php — Arbitrage stock/achat d'une FEB
//  Réservé à l'acheteur qui détient la FEB (acheteur_id = utilisateur
//  courant), pas seulement masqué côté écran — contrôle serveur systématique.
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/achats.php';

require_auth();
$user = current_user();
// Réservée à l'acheteur (option Acheteur de la matrice), cf. ci-dessous.
// Étapes 3 et 4 du processus : réservées au service Achats — cf.
// ach_est_acheteur(). Un RAF pouvait jusqu'ici arbitrer et saisir des
// offres, alors qu'il n'intervient qu'à l'étape 5 (visa).
ach_require_acheteur($user);
$uid = (int)$user['id'];
$_SESSION['groupe_actif'] = 'ACHATS';

// ── Charge la FEB si, et seulement si, l'utilisateur courant en est
//    l'acheteur détenteur ET qu'elle est éditable — contrôle serveur, pas un
//    simple masquage de bouton (Bloc 3, point 17). Verrouille de fait toute
//    modification (offres, montants, arbitrage) dès que la FEB quitte
//    prise_en_charge — en particulier au lancement de la validation
//    (Bloc 5, RG-12) : rien à ajouter côté verrou, ce gate suffit.
function feb_charger_pour_traitement(int $feb_id, int $uid): ?array {
    $feb = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$feb) return null;
    if ((int)$feb['acheteur_id'] !== $uid || $feb['statut'] !== 'prise_en_charge') return null;
    return $feb;
}

// ── Chargement large pour la simple consultation (y compris une FEB
//    rejetée, pour afficher le motif et proposer la reprise).
function feb_charger_pour_vue(int $feb_id, int $uid): ?array {
    $feb = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$feb) return null;
    if ((int)$feb['acheteur_id'] !== $uid || !in_array($feb['statut'], ['prise_en_charge', 'rejetee'], true)) return null;
    return $feb;
}

$feb_id = (int)($_GET['id'] ?? 0);

// ── AJAX ────────────────────────────────────────────────────
if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $post_feb_id = (int)($_POST['feb_id'] ?? 0);

    // Seule action valable sur une FEB rejetée : la reprendre. Toutes les
    // autres exigent prise_en_charge (gate strict ci-dessus).
    if ($action === 'reprendre') {
        if (ach_reprendre_feb_rejetee($post_feb_id, $user)) {
            json_response(true, 'FEB reprise — le circuit est à relancer une fois vos corrections faites.');
        }
        json_response(false, "Reprise impossible — vérifiez que vous détenez cette FEB et qu'elle est bien rejetée.");
    }

    $feb = feb_charger_pour_traitement($post_feb_id, $uid);
    if (!$feb) json_response(false, "Cette FEB n'est pas (ou plus) en cours de traitement par vous.");

    if ($action === 'arbitrer') {
        $ligne_id = (int)($_POST['ligne_id'] ?? 0);
        $choix    = $_POST['choix'] ?? '';
        if (!in_array($choix, ['achat', 'stock'], true)) json_response(false, 'Choix invalide.');

        $l = db_fetch_one("SELECT * FROM feb_lignes WHERE id=? AND feb_id=?", [$ligne_id, $post_feb_id]);
        if (!$l) json_response(false, 'Ligne introuvable.');
        if (!$l['article_id']) json_response(false, 'Une ligne en saisie libre part toujours en achat — elle n\'est pas arbitrable.');

        if ($choix === 'stock') {
            $stock_magasin = ach_stock_magasin((int)$l['article_id']);
            if ($stock_magasin < (int)$l['quantite']) {
                json_response(false, "Couverture insuffisante ($stock_magasin disponible en magasin pour {$l['quantite']} demandé) — utilisez l'arbitrage partiel.");
            }
        }

        if ($choix !== $l['arbitrage']) {
            db_query("UPDATE feb_lignes SET arbitrage=? WHERE id=?", [$choix, $ligne_id]);
            audit_log($uid, 'UPDATE', 'achats', $post_feb_id,
                "Arbitrage ligne #{$l['numero_ligne']} ({$l['designation']}) : {$l['arbitrage']} → $choix");
        }
        json_response(true, 'Arbitrage enregistré.');
    }

    if ($action === 'arbitrer_partiel') {
        $ligne_id      = (int)($_POST['ligne_id'] ?? 0);
        $quantite_stock = (int)($_POST['quantite_stock'] ?? 0);

        $l = db_fetch_one("SELECT * FROM feb_lignes WHERE id=? AND feb_id=?", [$ligne_id, $post_feb_id]);
        if (!$l) json_response(false, 'Ligne introuvable.');
        if (!$l['article_id']) json_response(false, 'Une ligne en saisie libre n\'est pas arbitrable.');
        $quantite_totale = (int)$l['quantite'];
        if ($quantite_stock < 1 || $quantite_stock >= $quantite_totale) {
            json_response(false, 'La quantité servie sur stock doit être strictement comprise entre 1 et la quantité demandée.');
        }
        $stock_magasin = ach_stock_magasin((int)$l['article_id']);
        if ($quantite_stock > $stock_magasin) {
            json_response(false, "Stock magasin insuffisant ($stock_magasin) pour servir $quantite_stock.");
        }

        db_begin();
        try {
            $quantite_achat = $quantite_totale - $quantite_stock;
            // Somme des quantités conservée : la ligne d'origine devient la
            // portion « stock » (quantité réduite), une nouvelle ligne porte
            // le reste en « achat ».
            db_query("UPDATE feb_lignes SET quantite=?, arbitrage='stock' WHERE id=?", [$quantite_stock, $ligne_id]);
            $numero_suivant = (int)db_fetch_value("SELECT COALESCE(MAX(numero_ligne),0) FROM feb_lignes WHERE feb_id=?", [$post_feb_id]) + 1;
            db_query(
                "INSERT INTO feb_lignes (feb_id, numero_ligne, designation, article_id, quantite, unite, famille_id, code_analytique, lot, type_achat, arbitrage)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'achat')",
                [$post_feb_id, $numero_suivant, $l['designation'], $l['article_id'], $quantite_achat, $l['unite'], $l['famille_id'], $l['code_analytique'], $l['lot'], $l['type_achat']]
            );
            audit_log($uid, 'UPDATE', 'achats', $post_feb_id,
                "Scission ligne #{$l['numero_ligne']} ({$l['designation']}) : $quantite_stock sur stock / $quantite_achat à acheter (total $quantite_totale conservé)");
            db_commit();
        } catch (Exception $e) {
            db_rollback();
            json_response(false, $e->getMessage());
        }
        json_response(true, 'Ligne scindée.');
    }

    if ($action === 'tout_servir_stock') {
        $lignes = db_fetch_all(
            "SELECT fl.*,
                    COALESCE((SELECT SUM(ss2.quantite) FROM stock_site ss2 JOIN sites s2 ON s2.id = ss2.site_id
                               WHERE ss2.article_id = fl.article_id AND s2.type = 'magasin' AND s2.actif = 1), 0) AS stock_magasin,
                    COALESCE(ss.quantite, 0) AS stock_site
               FROM feb_lignes fl
               JOIN articles a ON a.id = fl.article_id
               LEFT JOIN stock_site ss ON ss.article_id = fl.article_id AND ss.site_id = ?
              WHERE fl.feb_id=?", [$feb['site_id'], $post_feb_id]
        );
        if (!$lignes) json_response(false, 'Aucune ligne arbitrable sur cette FEB.');
        foreach ($lignes as $l) {
            if ((int)$l['stock_magasin'] < (int)$l['quantite']) {
                json_response(false, "Toutes les lignes ne sont pas couvertes par le stock — « {$l['designation']} » ne l'est pas.");
            }
        }
        foreach ($lignes as $l) {
            if ($l['arbitrage'] !== 'stock') {
                db_query("UPDATE feb_lignes SET arbitrage='stock' WHERE id=?", [$l['id']]);
            }
        }
        // Le geste groupé cache le détail ligne à ligne : on rapporte combien
        // d'entre elles supposent un transfert, sinon l'avertissement de la
        // modale est contourné sans que personne ne le voie.
        $transferts = 0;
        foreach ($lignes as $l) {
            if ((int)$l['stock_site'] < (int)$l['quantite']) $transferts++;
        }
        audit_log($uid, 'UPDATE', 'achats', $post_feb_id,
            'Toutes les lignes arbitrables servies sur stock'
            . ($transferts ? " — $transferts ligne(s) nécessitant un transfert entre sites" : ''));
        json_response(true, 'Toutes les lignes sont arbitrées sur stock.'
            . ($transferts ? " Attention : $transferts ligne(s) ne sont pas en stock sur le site demandeur et supposent un transfert." : ''));
    }

    if ($action === 'basculer') {
        try {
            $cmd_id = ach_basculer_vers_commande($post_feb_id, $user);
            $numero = db_fetch_value("SELECT numero_commande FROM commandes WHERE id=?", [$cmd_id]);
            json_response(true, "Commande interne $numero créée.", ['cmd_id' => $cmd_id, 'numero' => $numero]);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        } catch (Exception $e) {
            json_response(false, $e->getMessage());
        }
    }

    if ($action === 'lancer_validation') {
        try {
            $res = ach_lancer_validation($post_feb_id, $user);
            $msg = "Validation lancée — {$res['etapes']} étape(s), palier « {$res['palier']} ».";
            if ($res['avertissements']) $msg .= ' ' . implode(' ', $res['avertissements']);
            json_response(true, $msg, $res);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    // ── Comparatif d'offres (Bloc 2) ───────────────────────────
    if ($action === 'add_offre' || $action === 'update_offre') {
        $offre_id  = (int)($_POST['offre_id'] ?? 0);
        $lot       = trim($_POST['lot'] ?? '');
        $four_id   = (int)($_POST['fournisseur_id'] ?? 0);
        $delai_raw = trim($_POST['delai_annonce'] ?? '');
        $delai     = $delai_raw !== '' ? (int)$delai_raw : null;
        $cond_pai  = trim($_POST['conditions_paiement'] ?? '');
        $montant   = (int)($_POST['montant_ttc'] ?? -1);
        $prix_init = trim($_POST['prix_initial'] ?? '') !== '' ? (int)$_POST['prix_initial'] : null;
        $obs       = trim($_POST['observation'] ?? '');

        $lots_valides = array_column(ach_lots_feb($post_feb_id), 'lot');
        if (!in_array($lot, $lots_valides, true)) json_response(false, 'Lot introuvable ou entièrement servi sur stock.');
        if (!$four_id) json_response(false, 'Le fournisseur est obligatoire — choisissez-le dans le référentiel.');
        $four_actif = db_fetch_value("SELECT actif FROM fournisseurs WHERE id=?", [$four_id]);
        if (!$four_actif) json_response(false, 'Fournisseur introuvable ou inactif.');
        if ($montant < 0) json_response(false, 'Le montant TTC est obligatoire et doit être positif ou nul.');

        if ($action === 'add_offre') {
            // Verrou sur la FEB porteuse : compter puis écrire est un
            // « vérifier puis agir ». Le serveur PHP intégré sert les
            // requêtes une à une, ce qui masque aujourd'hui le défaut, mais
            // rien ne garantit que ce sera toujours le cas (PHP_CLI_SERVER_
            // WORKERS, un vrai FPM, plusieurs instances). Le verrou rend le
            // plafond vrai dans tous les cas.
            db_begin();
            try {
                db_query("SELECT id FROM feb WHERE id=? FOR UPDATE", [$post_feb_id]);

                // Double envoi : deux clics sur « Enregistrer » créaient deux
                // offres identiques, chacune valide prise isolément. Une même
                // proposition du même fournisseur au même montant sur le même
                // lot n'a pas de sens dans un comparatif — c'est un doublon,
                // pas une seconde offre.
                $doublon = db_fetch_value(
                    "SELECT id FROM feb_offres WHERE feb_id=? AND lot=? AND fournisseur_id=? AND montant_ttc=?",
                    [$post_feb_id, $lot, $four_id, $montant]
                );
                if ($doublon) {
                    db_rollback();
                    json_response(false, "Cette offre existe déjà pour ce fournisseur et ce montant — modifiez-la plutôt que d'en créer une seconde.");
                }

                $nb_offres = (int)db_fetch_value("SELECT COUNT(*) FROM feb_offres WHERE feb_id=? AND lot=?", [$post_feb_id, $lot]);
                if ($nb_offres >= 3) {
                    db_rollback();
                    json_response(false, "Trois offres au maximum par lot — supprimez-en une avant d'en ajouter une nouvelle.");
                }

                db_query(
                    "INSERT INTO feb_offres (feb_id, lot, fournisseur_id, delai_annonce, conditions_paiement, montant_ttc, prix_initial, observation)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$post_feb_id, $lot, $four_id, $delai, $cond_pai ?: null, $montant, $prix_init, $obs ?: null]
                );
                $offre_id = (int)db_last_id('feb_offres_id_seq');
                audit_log($uid, 'CREATE', 'achats', $post_feb_id, "Ajout offre lot $lot — fournisseur #$four_id, $montant XOF");
                db_commit();
            } catch (Exception $e) {
                db_rollback();
                json_response(false, $e->getMessage());
            }
            json_response(true, 'Offre ajoutée.', ['id' => $offre_id]);
        } else {
            $existante = db_fetch_one("SELECT * FROM feb_offres WHERE id=? AND feb_id=?", [$offre_id, $post_feb_id]);
            if (!$existante) json_response(false, 'Offre introuvable.');
            // Une offre retenue a déjà reporté son fournisseur et son montant
            // sur les lignes du lot (ach_retenir_offre_lot()) : la modifier
            // sans passer par là désynchroniserait silencieusement les
            // lignes de l'offre qu'elles sont censées refléter. Même garde
            // que la suppression, juste en dessous.
            if ($existante['retenue']) json_response(false, "Annulez d'abord la sélection de cette offre avant de la modifier.");
            db_query(
                "UPDATE feb_offres SET lot=?, fournisseur_id=?, delai_annonce=?, conditions_paiement=?, montant_ttc=?, prix_initial=?, observation=? WHERE id=?",
                [$lot, $four_id, $delai, $cond_pai ?: null, $montant, $prix_init, $obs ?: null, $offre_id]
            );
            audit_log($uid, 'UPDATE', 'achats', $post_feb_id, "Modification offre #$offre_id (lot $lot)");
            json_response(true, 'Offre mise à jour.');
        }
    }

    if ($action === 'delete_offre') {
        $offre_id = (int)($_POST['offre_id'] ?? 0);
        $offre = db_fetch_one("SELECT * FROM feb_offres WHERE id=? AND feb_id=?", [$offre_id, $post_feb_id]);
        if (!$offre) json_response(false, 'Offre introuvable.');
        if ($offre['retenue']) json_response(false, "Annulez d'abord la sélection de cette offre avant de la supprimer.");
        db_query("DELETE FROM feb_offres WHERE id=?", [$offre_id]);
        audit_log($uid, 'DELETE', 'achats', $post_feb_id, "Suppression offre #$offre_id (lot {$offre['lot']})");
        json_response(true, 'Offre supprimée.');
    }

    // ── Annuler la retenue — remet le lot sans offre retenue (bloquant pour
    //    ach_verifier_comparatif(), comme un lot jamais comparé) pour
    //    permettre de modifier ou supprimer l'offre, ou d'en retenir une
    //    autre. Ne touche pas feb_lignes : le fournisseur/montant reportés
    //    seront écrasés à la prochaine offre retenue, ou laissés en l'état
    //    si aucune ne l'est encore — ach_verifier_comparatif() bloque de
    //    toute façon la suite tant qu'aucune offre n'est retenue sur ce lot.
    if ($action === 'annuler_retenue') {
        $offre_id = (int)($_POST['offre_id'] ?? 0);
        $offre = db_fetch_one("SELECT * FROM feb_offres WHERE id=? AND feb_id=?", [$offre_id, $post_feb_id]);
        if (!$offre) json_response(false, 'Offre introuvable.');
        if (!$offre['retenue']) json_response(false, "Cette offre n'est pas retenue.");
        db_query("UPDATE feb_offres SET retenue=0 WHERE id=?", [$offre_id]);
        audit_log($uid, 'UPDATE', 'achats', $post_feb_id, "Annulation de la retenue — offre #$offre_id (lot {$offre['lot']})");
        json_response(true, 'Sélection annulée — vous pouvez modifier, supprimer ou retenir une autre offre.');
    }

    if ($action === 'retenir_offre') {
        $offre_id = (int)($_POST['offre_id'] ?? 0);
        try {
            $res = ach_retenir_offre_lot($offre_id, $user);
            // Le message dit ce qui s'est réellement passé : annoncer un
            // report alors que toutes les lignes sont dérogées laissait
            // croire que le fournisseur avait changé sur la ligne.
            $msg = $res['reportees'] > 0
                ? "Offre retenue — fournisseur reporté sur {$res['reportees']} ligne(s)."
                : 'Offre retenue.';
            if ($res['derogees'] > 0) {
                $msg .= " {$res['derogees']} ligne(s) dérogée(s) conservent leur fournisseur choisi à la main"
                      . " — rechoisissez le fournisseur de l'offre retenue sur la ligne pour la réaligner.";
            }
            json_response(true, $msg);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }
    }

    // ── Fournisseur et montant par ligne (Bloc 3/4) ────────────
    if ($action === 'deroger_fournisseur_ligne') {
        $ligne_id      = (int)($_POST['ligne_id'] ?? 0);
        $fournisseur_id = (int)($_POST['fournisseur_id'] ?? 0);
        $l = db_fetch_one("SELECT * FROM feb_lignes WHERE id=? AND feb_id=?", [$ligne_id, $post_feb_id]);
        if (!$l) json_response(false, 'Ligne introuvable.');
        if (!$fournisseur_id) json_response(false, 'Choisissez un fournisseur.');

        // Désigner un fournisseur à la main sur une ligne attribue le marché
        // tout autant que retenir son offre : même exigence de dossier, sinon
        // le contrôle posé sur la retenue se contournerait par ici.
        try {
            ach_exiger_fournisseur_conforme($fournisseur_id);
        } catch (AchValidationException $e) {
            json_response(false, $e->getMessage());
        }

        // Choisir sur la ligne le fournisseur de l'offre retenue du lot n'est
        // pas une dérogation : c'est un retour à la règle. Sans cette porte
        // de sortie, une ligne dérogée par erreur le restait définitivement —
        // le report du lot l'ignorant pour toujours (RG-09).
        $offre_retenue = db_fetch_value(
            "SELECT fournisseur_id FROM feb_offres WHERE feb_id=? AND lot=? AND retenue=1",
            [$post_feb_id, $l['lot']]
        );
        $realignement = $offre_retenue && (int)$offre_retenue === $fournisseur_id;

        // Une offre porte sur tout le lot (feb_offres n'a qu'un montant_ttc
        // global) : il n'existe donc aucun moyen de déduire la part exacte
        // qui revient à CETTE ligne chez le fournisseur dérogé. Remettre le
        // montant à zéro plutôt que de laisser l'ancien report — sinon il
        // reste affiché tel quel, prix d'un autre fournisseur pour un autre
        // lot de lignes, sans que rien ne signale qu'il est à ressaisir.
        if ($realignement) {
            db_query(
                "UPDATE feb_lignes SET fournisseur_id=?, fournisseur_derogation=0 WHERE id=?",
                [$fournisseur_id, $ligne_id]
            );
        } else {
            db_query(
                "UPDATE feb_lignes SET fournisseur_id=?, fournisseur_derogation=1, montant_ttc=0 WHERE id=?",
                [$fournisseur_id, $ligne_id]
            );
            ach_recalculer_montant_total($post_feb_id);
        }
        audit_log($uid, 'UPDATE', 'achats', $post_feb_id, $realignement
            ? "Réalignement ligne #{$l['numero_ligne']} ({$l['designation']}) sur l'offre retenue du lot"
            : "Dérogation fournisseur ligne #{$l['numero_ligne']} ({$l['designation']}) — montant remis à zéro");
        json_response(true, $realignement
            ? "Ligne réalignée sur l'offre retenue du lot — elle suivra de nouveau les changements d'offre."
            : 'Fournisseur dérogé sur cette ligne — son montant a été remis à zéro, saisissez le prix réellement proposé par ce fournisseur pour cette ligne.');
    }

    if ($action === 'update_ligne_montant') {
        $ligne_id   = (int)($_POST['ligne_id'] ?? 0);
        $montant    = (int)($_POST['montant_ttc'] ?? -1);
        $l = db_fetch_one("SELECT * FROM feb_lignes WHERE id=? AND feb_id=?", [$ligne_id, $post_feb_id]);
        if (!$l) json_response(false, 'Ligne introuvable.');
        if ($montant < 0) json_response(false, 'Le montant doit être positif ou nul.');
        db_query("UPDATE feb_lignes SET montant_ttc=? WHERE id=?", [$montant, $ligne_id]);
        ach_recalculer_montant_total($post_feb_id);
        audit_log($uid, 'UPDATE', 'achats', $post_feb_id, "Montant ligne #{$l['numero_ligne']} ({$l['designation']}) : $montant XOF");
        json_response(true, 'Montant enregistré.');
    }

    if ($action === 'update_ligne_type') {
        $ligne_id   = (int)($_POST['ligne_id'] ?? 0);
        $type_achat = trim($_POST['type_achat'] ?? '');
        $l = db_fetch_one("SELECT * FROM feb_lignes WHERE id=? AND feb_id=?", [$ligne_id, $post_feb_id]);
        if (!$l) json_response(false, 'Ligne introuvable.');
        if ($type_achat !== '') {
            $existe = db_fetch_value("SELECT 1 FROM achat_types WHERE code=? AND actif=1", [$type_achat]);
            if (!$existe) json_response(false, "Type d'achat inconnu.");
        }
        db_query("UPDATE feb_lignes SET type_achat=? WHERE id=?", [$type_achat ?: null, $ligne_id]);
        audit_log($uid, 'UPDATE', 'achats', $post_feb_id, "Type d'achat ligne #{$l['numero_ligne']} ({$l['designation']}) : " . ($type_achat ?: '—'));
        json_response(true, "Type d'achat enregistré.");
    }

    if ($action === 'verifier_comparatif') {
        $res = ach_verifier_comparatif($post_feb_id);
        json_response($res['ok'], $res['message']);
    }

    json_response(false, 'Action inconnue.');
}

// ── PAGE PHP ─────────────────────────────────────────────────
$feb = feb_charger_pour_vue($feb_id, $uid);
if (!$feb) {
    http_response_code(403);
    include __DIR__ . '/../../templates/403.php';
    exit;
}
// FEB rejetée : consultation + reprise uniquement, aucune modification tant
// qu'elle n'est pas reprise (retour en prise_en_charge).
$editable = $feb['statut'] === 'prise_en_charge';

$demandeur = db_fetch_one(
    "SELECT CONCAT(prenom,' ',nom) AS nom FROM users WHERE id=?", [$feb['demandeur_id']]
);
$site_nom = $feb['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$feb['site_id']]) : null;

$lignes = db_fetch_all(
    "SELECT fl.*, a.libelle AS article_libelle,
            COALESCE((SELECT SUM(ss2.quantite) FROM stock_site ss2 JOIN sites s2 ON s2.id = ss2.site_id
                       WHERE ss2.article_id = fl.article_id AND s2.type = 'magasin' AND s2.actif = 1), 0) AS stock_magasin,
            COALESCE(ss.quantite, 0) AS stock_site
     FROM feb_lignes fl
     LEFT JOIN articles a ON a.id = fl.article_id
     LEFT JOIN stock_site ss ON ss.article_id = fl.article_id AND ss.site_id = ?
     WHERE fl.feb_id = ?
     ORDER BY fl.numero_ligne",
    [$feb['site_id'] ?: 0, $feb_id]
);

$commande_liee = db_fetch_one(
    "SELECT id, numero_commande, statut FROM commandes WHERE feb_id=?", [$feb_id]
);

// Couverture individuelle des lignes arbitrables (article_id renseigné) —
// pilote l'activation du bouton « Tout servir sur stock » (point 21).
$toutes_couvertes = true;
$au_moins_une_arbitrable = false;
foreach ($lignes as $l) {
    if ($l['article_id']) {
        $au_moins_une_arbitrable = true;
        if ((int)$l['stock_magasin'] < (int)$l['quantite']) $toutes_couvertes = false;
    }
}

// ── Comparatif d'offres — un lot = un code analytique (Bloc 1). Les lots
// entièrement servis sur stock sont déjà exclus par ach_lots_feb().
$lots = ach_lots_feb($feb_id);
$offres_par_lot = [];
foreach ($lots as $lot) {
    $offres_par_lot[$lot['lot']] = db_fetch_all(
        "SELECT o.*, f.raison_sociale AS fournisseur_nom
         FROM feb_offres o LEFT JOIN fournisseurs f ON f.id = o.fournisseur_id
         WHERE o.feb_id=? AND o.lot=? ORDER BY o.id",
        [$feb_id, $lot['lot']]
    );
}
$fournisseurs = db_fetch_all("SELECT id, raison_sociale FROM fournisseurs WHERE actif=1 ORDER BY raison_sociale");
$types_achat  = db_fetch_all("SELECT code, libelle FROM achat_types WHERE actif=1 ORDER BY libelle");

$page_title  = 'Traitement FEB ' . ($feb['numero'] ?: '');
$active_page = 'achats_file_attente';

include __DIR__ . '/../../templates/header.php';
?>
<style>
.feb-hdr-card{background:white;border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:18px}
.feb-hdr-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px 18px}
@media(max-width:900px){.feb-hdr-grid{grid-template-columns:repeat(2,1fr)}}
.feb-hdr-lbl{font-size:11.5px;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.4px;margin-bottom:3px}
.feb-hdr-val{font-size:14px;font-weight:700;color:var(--navy)}
.ach-table-wrap{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:18px}
.ach-table{width:100%;border-collapse:collapse;font-size:13px}
.ach-table th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 14px;text-align:left;border-bottom:1px solid var(--border)}
.ach-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.ach-table tr:last-child td{border-bottom:none}
.ach-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.badge-stock{background:#D1FAE5;color:#065F46}
.badge-achat{background:#FEF3C7;color:#92400E}
.badge-libre{background:#F1F5F9;color:#475569}
/* Transfert requis : le stock magasin couvre, le site demandeur non.
   On avertit sans interdire — stock_site n'est renseigné que sur 4 sites sur
   21, un refus strict rendrait l'arbitrage inutilisable partout ailleurs. */
.stock-transfert{color:var(--warning-d,#8A5A00);font-weight:700;white-space:nowrap}
.arb-avert{background:#FEF3C7;color:#8A5A00;border-left:3px solid #8A5A00;
           padding:9px 12px;border-radius:6px;font-size:12.5px;margin-bottom:14px}
.feb-actions-bar{display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.feb-commande-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.ach-modal-bg{display:none;position:fixed;inset:0;background:rgba(6,3,58,.45);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ach-modal-bg.open{display:flex}
.ach-modal{background:white;border-radius:16px;padding:26px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ach-modal h3{margin:0 0 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)}
.ach-fg{margin-bottom:14px}
.ach-fg label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.lot-card{background:white;border:1px solid var(--border);border-radius:16px;margin-bottom:18px;overflow:hidden}
.lot-hdr{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 18px;background:#fff7ed;border-bottom:1px solid #fed7aa}
.lot-code{font-family:monospace;font-weight:800;font-size:14px;color:#B45309}
.lot-meta{font-size:12px;color:var(--muted)}
.lot-section-ttl{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);padding:12px 18px 0}
.inline-input{padding:6px 9px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;font-family:inherit;width:100%;box-sizing:border-box}
.derog-badge{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:12px;font-size:10.5px;font-weight:700;background:#FEE2E2;color:#991B1B}
.offre-retenue-row{background:#F0FDF4}
.arb-choice{display:flex;gap:10px;margin-bottom:14px}
.arb-choice label{flex:1;border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;text-align:center;cursor:pointer;font-size:13px;font-weight:700}
.arb-choice input{margin-right:6px}
.arb-choice label.disabled{opacity:.4;cursor:not-allowed}
.ach-err{color:#dc2626;font-size:12px;margin-top:4px;display:none}
.feb-hint{font-size:12px;color:var(--muted);margin-top:6px}
.ach-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
</style>

<div style="margin-bottom:18px">
  <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:900;color:var(--navy)">Traitement de la FEB <?= h($feb['numero'] ?: '') ?></div>
  <div style="font-size:13px;color:var(--muted);margin-top:2px"><?= h($feb['objet']) ?></div>
</div>

<div class="feb-hdr-card">
  <div class="feb-hdr-grid">
    <div><div class="feb-hdr-lbl">Demandeur</div><div class="feb-hdr-val"><?= h($demandeur['nom'] ?? '—') ?></div></div>
    <div><div class="feb-hdr-lbl">Site</div><div class="feb-hdr-val"><?= h($site_nom ?: '—') ?></div></div>
    <div><div class="feb-hdr-lbl">Urgence</div><div class="feb-hdr-val"><?= h(ach_urgences()[(int)$feb['urgence']] ?? 'Normale') ?></div></div>
    <div><div class="feb-hdr-lbl">Nombre de lignes</div><div class="feb-hdr-val"><?= count($lignes) ?></div></div>
  </div>
  <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
    <a href="feb_fiche_pdf.php?id=<?= $feb_id ?>" target="_blank" class="btn btn-secondary btn-sm">
      <i class="ph ph-printer" aria-hidden="true"></i> Fiche imprimable
    </a>
    <?php if (!empty($feb['fiche_validation_path'])): ?>
    <a href="feb_fiche_validation_pdf.php?id=<?= $feb_id ?>" target="_blank" class="btn btn-secondary btn-sm">
      <i class="ph ph-seal-check" aria-hidden="true"></i> Fiche de validation archivée
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($commande_liee): ?>
<div class="feb-commande-box">
  <div>
    <div class="feb-hdr-lbl" style="color:#B45309">Commande interne liée</div>
    <div class="feb-hdr-val"><?= h($commande_liee['numero_commande']) ?></div>
  </div>
  <a href="../commandes.php" class="btn btn-secondary btn-sm">Voir dans Commandes</a>
</div>
<?php endif; ?>

<?php if (!$editable): ?>
<div class="feb-commande-box" style="background:#FEE2E2;border-color:#FCA5A5">
  <div>
    <div class="feb-hdr-lbl" style="color:#991B1B">FEB rejetée — étape <?= h($feb['workflow_snapshot'] ? (json_decode($feb['workflow_snapshot'], true)[$feb['etape_rejet']]['label'] ?? '') : '') ?></div>
    <div class="feb-hdr-val" style="color:#991B1B"><?= h($feb['motif_rejet'] ?: 'Aucun motif renseigné.') ?></div>
  </div>
  <button type="button" class="btn btn-primary btn-sm" onclick="febReprendre()">Reprendre la FEB</button>
</div>
<?php endif; ?>

<div class="feb-actions-bar">
  <div class="feb-hint" style="margin:0">L'arbitrage se décide ligne par ligne. Aucun mouvement de stock n'a lieu à ce stade.</div>
  <?php if ($editable): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <?php if ($au_moins_une_arbitrable): ?>
    <button type="button" class="btn btn-secondary" id="btn-tout-stock" <?= $toutes_couvertes ? '' : 'disabled' ?> onclick="febToutStock()">
      Tout servir sur stock
    </button>
    <?php endif; ?>
    <?php if (!$commande_liee): ?>
    <button type="button" class="btn btn-secondary" onclick="febBasculer()">Basculer vers la commande interne</button>
    <?php endif; ?>
    <button type="button" class="btn btn-primary" onclick="febLancerValidation()">Lancer la validation</button>
  </div>
  <?php endif; ?>
</div>

<div class="ach-table-wrap">
  <div style="overflow-x:auto">
  <table class="ach-table">
    <thead><tr>
      <th>Désignation</th><th>Qté</th><th>Unité</th><th>Stock site</th><th>Stock magasin</th><th>Arbitrage</th><th>Actions</th>
    </tr></thead>
    <tbody>
      <?php foreach ($lignes as $l):
        $arbitrable = (bool)$l['article_id'];
        $couverte   = $arbitrable && (int)$l['stock_magasin'] >= (int)$l['quantite'];
        $partiel_ok = $arbitrable && (int)$l['stock_magasin'] > 0 && (int)$l['stock_magasin'] < (int)$l['quantite'];
        // Le stock magasin couvre, mais pas celui du site demandeur :
        // servir cette ligne suppose un transfert entre sites. On le dit, on
        // ne le refuse pas.
        $transfert  = $couverte && (int)$l['stock_site'] < (int)$l['quantite'];
        $badge_class = !$arbitrable ? 'badge-libre' : ($l['arbitrage'] === 'stock' ? 'badge-stock' : 'badge-achat');
        $badge_label = !$arbitrable ? 'Saisie libre — achat direct' : ($l['arbitrage'] === 'stock' ? 'Sur stock' : 'À acheter');
      ?>
      <tr>
        <td style="font-weight:700;color:var(--navy)"><?= h($l['designation']) ?></td>
        <td><?= (int)$l['quantite'] ?></td>
        <td><?= h($l['unite'] ?: '—') ?></td>
        <td>
          <?php if (!$arbitrable): ?>—
          <?php elseif ($transfert): ?>
            <span class="stock-transfert"
                  title="Transfert requis : <?= (int)$l['stock_magasin'] ?> disponible(s) en magasin, <?= (int)$l['stock_site'] ?> sur ce site.">
              <?= (int)$l['stock_site'] ?> <i class="ph ph-warning" aria-hidden="true"></i>
            </span>
          <?php else: ?><?= (int)$l['stock_site'] ?><?php endif; ?>
        </td>
        <td><?= $arbitrable ? (int)$l['stock_magasin'] : '—' ?></td>
        <td><span class="ach-badge <?= $badge_class ?>"><?= h($badge_label) ?></span></td>
        <td>
          <?php if ($arbitrable && !$commande_liee && $editable): ?>
          <button type="button" class="btn btn-secondary btn-sm"
                  onclick='febOuvrirArbitrage(<?= json_encode([
                    "id"=>(int)$l["id"], "designation"=>$l["designation"], "quantite"=>(int)$l["quantite"],
                    "stock_magasin"=>(int)$l["stock_magasin"], "arbitrage"=>$l["arbitrage"],
                    "couverte"=>$couverte, "partiel_ok"=>$partiel_ok,
                    "stock_site"=>(int)$l["stock_site"], "transfert"=>$transfert,
                  ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
            Arbitrer
          </button>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($lots): ?>
<div style="margin:26px 0 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)">Comparatif des offres</div>
  <button type="button" class="btn btn-secondary" onclick="febVerifierComparatif()">Vérifier le comparatif</button>
</div>

<?php foreach ($lots as $lot):
  $offres = $offres_par_lot[$lot['lot']];
  $retenue = null;
  foreach ($offres as $o) { if ($o['retenue']) { $retenue = $o; break; } }
?>
<div class="lot-card">
  <div class="lot-hdr">
    <div>
      <div class="lot-code">Lot <?= h($lot['lot']) ?></div>
      <div class="lot-meta"><?= $lot['nb_articles'] ?> article(s) — <?= $lot['somme_quantites'] ?> unité(s) au total</div>
    </div>
    <div class="lot-meta"><?= count($offres) ?> / 3 offre(s)</div>
  </div>

  <div class="lot-section-ttl">Lignes</div>
  <div style="overflow-x:auto">
  <table class="ach-table">
    <thead><tr><th>Désignation</th><th>Qté</th><th>Type d'achat</th><th>Fournisseur</th><th>Montant TTC</th></tr></thead>
    <tbody>
      <?php foreach ($lot['lignes'] as $l): ?>
      <tr>
        <td style="font-weight:700;color:var(--navy)"><?= h($l['designation']) ?></td>
        <td><?= (int)$l['quantite'] ?></td>
        <td>
          <select class="inline-input" <?= $editable ? '' : 'disabled' ?> onchange="febMajLigneType(<?= (int)$l['id'] ?>, this.value)">
            <option value="">— Sélectionner —</option>
            <?php foreach ($types_achat as $t): ?>
              <option value="<?= h($t['code']) ?>" <?= $l['type_achat'] === $t['code'] ? 'selected' : '' ?>><?= h($t['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="inline-input" <?= $editable ? '' : 'disabled' ?> onchange="febDerogerFournisseur(<?= (int)$l['id'] ?>, this.value)">
            <option value="">— Aucun —</option>
            <?php foreach ($fournisseurs as $f): ?>
              <option value="<?= $f['id'] ?>" <?= (string)$l['fournisseur_id'] === (string)$f['id'] ? 'selected' : '' ?>><?= h($f['raison_sociale']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($l['fournisseur_derogation']): ?><span class="derog-badge">Dérogée</span><?php endif; ?>
        </td>
        <td>
          <input type="number" min="0" step="1" class="inline-input" style="max-width:140px" <?= $editable ? '' : 'disabled' ?>
                 value="<?= (int)$l['montant_ttc'] ?>"
                 onchange="febMajLigneMontant(<?= (int)$l['id'] ?>, this.value)">
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="lot-section-ttl">Offres</div>
  <div style="overflow-x:auto">
  <table class="ach-table">
    <thead><tr>
      <th>Fournisseur</th><th>Délai</th><th>Conditions paiement</th><th>Montant TTC</th><th>Prix initial</th><th>Observation</th><th>Actions</th>
    </tr></thead>
    <tbody>
      <?php if (!$offres): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:18px">Aucune offre pour ce lot.</td></tr>
      <?php endif; ?>
      <?php foreach ($offres as $o): ?>
      <tr class="<?= $o['retenue'] ? 'offre-retenue-row' : '' ?>">
        <td style="font-weight:700"><?= h($o['fournisseur_nom'] ?: '—') ?></td>
        <td><?= $o['delai_annonce'] !== null ? (int)$o['delai_annonce'] . ' j' : '—' ?></td>
        <td><?= h($o['conditions_paiement'] ?: '—') ?></td>
        <td style="font-weight:700"><?= fmt_number((float)$o['montant_ttc']) ?> XOF</td>
        <td><?= $o['prix_initial'] !== null ? fmt_number((float)$o['prix_initial']) . ' XOF' : '—' ?></td>
        <td><?= h($o['observation'] ?: '—') ?></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          <?php if ($o['retenue']): ?>
            <span class="ach-badge badge-stock">Retenue</span>
            <?php if ($editable): ?>
            <button type="button" class="btn btn-secondary btn-sm" onclick="febAnnulerRetenue(<?= (int)$o['id'] ?>)">Annuler la sélection</button>
            <?php endif; ?>
          <?php elseif ($editable): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="febRetenirOffre(<?= (int)$o['id'] ?>)">Retenir</button>
            <button type="button" class="btn btn-secondary btn-sm" aria-label="Modifier l'offre de <?= h($o['fournisseur_nom'] ?: '') ?>"
                    onclick='febOuvrirOffre(<?= json_encode([
                      "id"=>(int)$o["id"], "lot"=>$lot["lot"], "fournisseur_id"=>$o["fournisseur_id"],
                      "delai_annonce"=>$o["delai_annonce"], "conditions_paiement"=>$o["conditions_paiement"],
                      "montant_ttc"=>(int)$o["montant_ttc"], "prix_initial"=>$o["prix_initial"], "observation"=>$o["observation"],
                    ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
              <i class="ph ph-pencil-simple" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-secondary btn-sm"
                    aria-label="Supprimer l'offre de <?= h($o['fournisseur_nom'] ?: '') ?>"
                    onclick="febSupprimerOffre(<?= (int)$o['id'] ?>)">
              <i class="ph ph-trash" aria-hidden="true"></i>
            </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($editable): ?>
  <div style="padding:14px 18px">
    <button type="button" class="btn btn-secondary btn-sm" <?= count($offres) >= 3 ? 'disabled title="Trois offres au maximum par lot."' : '' ?>
            onclick='febOuvrirOffre(null, <?= json_encode($lot['lot'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
      <i class="ph ph-plus" aria-hidden="true"></i> Ajouter une offre
    </button>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- MODALE arbitrage d'une ligne -->
<div class="ach-modal-bg" id="arb-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="arb-modal-title">
    <h3 id="arb-modal-title">Arbitrer la ligne</h3>
    <input type="hidden" id="arb-ligne-id" value="">
    <div class="feb-hdr-lbl">Désignation</div>
    <div class="feb-hdr-val" id="arb-designation" style="margin-bottom:14px"></div>
    <div class="arb-avert" id="arb-transfert" style="display:none"></div>
    <div class="arb-choice">
      <label id="arb-lbl-achat"><input type="radio" name="arb-choix" value="achat"> Acheter</label>
      <label id="arb-lbl-stock"><input type="radio" name="arb-choix" value="stock"> Servir sur stock</label>
    </div>
    <div id="arb-partiel-box" style="display:none">
      <div class="ach-fg">
        <label for="arb-qte-stock">Quantité à servir sur stock (couverture partielle)</label>
        <input type="number" id="arb-qte-stock" min="1" step="1">
        <div class="feb-hint" id="arb-partiel-hint"></div>
      </div>
    </div>
    <div class="ach-err" id="arb-err"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="febFermerArbitrage()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="febEnregistrerArbitrage()">Enregistrer</button>
    </div>
  </div>
</div>

<!-- MODALE offre -->
<div class="ach-modal-bg" id="offre-modal">
  <div class="ach-modal" role="dialog" aria-labelledby="offre-modal-title">
    <h3 id="offre-modal-title"><span id="offre-modal-ttl-txt">Nouvelle offre</span> — lot <span id="offre-modal-lot"></span></h3>
    <input type="hidden" id="of-id" value="">
    <input type="hidden" id="of-lot" value="">
    <div class="ach-fg">
      <label for="of-fournisseur">Fournisseur</label>
      <select id="of-fournisseur">
        <option value="">— Sélectionner —</option>
        <?php foreach ($fournisseurs as $f): ?>
          <option value="<?= $f['id'] ?>"><?= h($f['raison_sociale']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$fournisseurs): ?><div class="feb-hint">Référentiel vide — créez au moins un fournisseur dans Achats → Fournisseurs.</div><?php endif; ?>
    </div>
    <div class="feb-hdr-grid" style="grid-template-columns:1fr 1fr">
      <div class="ach-fg">
        <label for="of-delai">Délai annoncé (jours)</label>
        <input type="number" id="of-delai" min="0" step="1">
      </div>
      <div class="ach-fg">
        <label for="of-cond">Conditions de paiement</label>
        <input type="text" id="of-cond" placeholder="ex : 30 jours net">
      </div>
      <div class="ach-fg">
        <label for="of-montant">Montant TTC (XOF)</label>
        <input type="number" id="of-montant" min="0" step="1" required>
      </div>
      <div class="ach-fg">
        <label for="of-prix-init">Prix initial avant négociation (XOF)</label>
        <input type="number" id="of-prix-init" min="0" step="1" placeholder="facultatif">
      </div>
    </div>
    <div class="ach-fg">
      <label for="of-obs">Observation</label>
      <input type="text" id="of-obs">
    </div>
    <div class="ach-err" id="offre-err"></div>
    <div class="ach-modal-actions">
      <button type="button" class="btn btn-secondary" onclick="febFermerOffre()">Annuler</button>
      <button type="button" class="btn btn-primary" id="offre-btn-enregistrer" onclick="febEnregistrerOffre()">Enregistrer</button>
    </div>
  </div>
</div>

<script>
const FEB_ID = <?= (int)$feb_id ?>;
let arbLigne = null;

function febPost(data) {
  const fd = new FormData();
  fd.append('feb_id', FEB_ID);
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  return fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
}

function febOuvrirArbitrage(l) {
  arbLigne = l;
  document.getElementById('arb-ligne-id').value = l.id;
  document.getElementById('arb-designation').textContent = l.designation + ' — qté ' + l.quantite + ' (stock magasin : ' + l.stock_magasin + ')';
  document.getElementById('arb-err').style.display = 'none';

  // Avertissement de transfert : le stock existe en magasin, mais pas
  // là où la FEB est émise. L'option reste ouverte, l'acheteur décide en
  // connaissance de cause.
  const avert = document.getElementById('arb-transfert');
  if (l.transfert) {
    avert.textContent = `Rien sur le site demandeur (${l.stock_site} en stock) alors que `
      + `${l.stock_magasin} sont disponibles en magasin. Servir cette ligne sur stock suppose `
      + `un transfert entre sites.`;
    avert.style.display = '';
  } else {
    avert.style.display = 'none';
  }

  const lblStock = document.getElementById('arb-lbl-stock');
  const radios = document.querySelectorAll('input[name="arb-choix"]');
  radios.forEach(r => { r.checked = (r.value === l.arbitrage); r.onchange = febMajPartiel; });

  if (l.couverte) {
    lblStock.classList.remove('disabled');
    document.querySelector('input[name="arb-choix"][value="stock"]').disabled = false;
  } else {
    lblStock.classList.add('disabled');
    document.querySelector('input[name="arb-choix"][value="stock"]').disabled = true;
    if (l.arbitrage === 'stock') document.querySelector('input[name="arb-choix"][value="achat"]').checked = true;
  }
  febMajPartiel();
  document.getElementById('arb-modal').classList.add('open');
}
function febMajPartiel() {
  const box = document.getElementById('arb-partiel-box');
  if (!arbLigne || arbLigne.couverte || !arbLigne.partiel_ok) { box.style.display = 'none'; return; }
  box.style.display = '';
  const input = document.getElementById('arb-qte-stock');
  input.max = Math.min(arbLigne.stock_magasin, arbLigne.quantite - 1);
  document.getElementById('arb-partiel-hint').textContent =
    `Couverture insuffisante pour la totalité (stock magasin : ${arbLigne.stock_magasin} / demandé : ${arbLigne.quantite}). `
    + `Renseignez la quantité à servir sur stock (maximum ${input.max}) — le reste part en achat.`;
}
function febFermerArbitrage() { document.getElementById('arb-modal').classList.remove('open'); }
document.getElementById('arb-modal').addEventListener('click', e => { if (e.target === e.currentTarget) febFermerArbitrage(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') febFermerArbitrage(); });

function febEnregistrerArbitrage() {
  const err = document.getElementById('arb-err');
  err.style.display = 'none';
  const choix = document.querySelector('input[name="arb-choix"]:checked');
  if (!choix) { err.textContent = 'Choisissez une option.'; err.style.display = 'block'; return; }

  const partielVisible = document.getElementById('arb-partiel-box').style.display !== 'none';
  if (partielVisible && document.getElementById('arb-qte-stock').value) {
    const qte = parseInt(document.getElementById('arb-qte-stock').value, 10);
    febPost({ action: 'arbitrer_partiel', ligne_id: arbLigne.id, quantite_stock: qte }).then(res => {
      if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
      toast(res.message, 'success');
      setTimeout(() => location.reload(), 500);
    });
    return;
  }

  febPost({ action: 'arbitrer', ligne_id: arbLigne.id, choix: choix.value }).then(res => {
    if (!res.success) { err.textContent = res.message; err.style.display = 'block'; return; }
    toast(res.message, 'success');
    setTimeout(() => location.reload(), 500);
  });
}

function febToutStock() {
  if (!confirm('Servir toutes les lignes arbitrables sur le stock ?')) return;
  febPost({ action: 'tout_servir_stock' }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}

function febBasculer() {
  if (!confirm('Basculer les lignes arbitrées "stock" vers une commande interne ?')) return;
  febPost({ action: 'basculer' }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 800);
  });
}

// ── Comparatif d'offres ─────────────────────────────────────
function febOuvrirOffre(o, lot) {
  document.getElementById('offre-err').style.display = 'none';
  if (o === null) {
    document.getElementById('offre-modal-ttl-txt').textContent = 'Nouvelle offre';
    document.getElementById('offre-modal-lot').textContent = lot;
    document.getElementById('of-id').value = '';
    document.getElementById('of-lot').value = lot;
    document.getElementById('of-fournisseur').value = '';
    document.getElementById('of-delai').value = '';
    document.getElementById('of-cond').value = '';
    document.getElementById('of-montant').value = '';
    document.getElementById('of-prix-init').value = '';
    document.getElementById('of-obs').value = '';
  } else {
    document.getElementById('offre-modal-ttl-txt').textContent = 'Modifier l\'offre';
    document.getElementById('offre-modal-lot').textContent = o.lot;
    document.getElementById('of-id').value = o.id;
    document.getElementById('of-lot').value = o.lot;
    document.getElementById('of-fournisseur').value = o.fournisseur_id || '';
    document.getElementById('of-delai').value = o.delai_annonce ?? '';
    document.getElementById('of-cond').value = o.conditions_paiement || '';
    document.getElementById('of-montant').value = o.montant_ttc;
    document.getElementById('of-prix-init').value = o.prix_initial ?? '';
    document.getElementById('of-obs').value = o.observation || '';
  }
  document.getElementById('offre-modal').classList.add('open');
  setTimeout(() => document.getElementById('of-fournisseur').focus(), 80);
}
function febFermerOffre() { document.getElementById('offre-modal').classList.remove('open'); }
document.getElementById('offre-modal').addEventListener('click', e => { if (e.target === e.currentTarget) febFermerOffre(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') febFermerOffre(); });

// Verrou d'envoi : sans lui, deux clics sur « Enregistrer » partaient en
// deux requêtes — l'écran ne se recharge qu'après 500 ms, la fenêtre est
// large. Le serveur refuse désormais le doublon, mais l'utilisateur ne doit
// pas voir passer un message d'erreur pour un geste aussi banal.
let offreEnCours = false;

function febEnregistrerOffre() {
  if (offreEnCours) return;
  const err = document.getElementById('offre-err');
  const fournisseur_id = document.getElementById('of-fournisseur').value;
  const montant_ttc = document.getElementById('of-montant').value;
  if (!fournisseur_id) { err.textContent = 'Le fournisseur est obligatoire.'; err.style.display = 'block'; return; }
  if (montant_ttc === '') { err.textContent = 'Le montant TTC est obligatoire.'; err.style.display = 'block'; return; }

  const id = document.getElementById('of-id').value;
  const bouton = document.getElementById('offre-btn-enregistrer');
  offreEnCours = true;
  if (bouton) { bouton.disabled = true; bouton.dataset.libelle = bouton.textContent; bouton.textContent = 'Enregistrement…'; }
  const relacher = () => {
    offreEnCours = false;
    if (bouton) { bouton.disabled = false; if (bouton.dataset.libelle) bouton.textContent = bouton.dataset.libelle; }
  };

  febPost({
    action: id ? 'update_offre' : 'add_offre',
    offre_id: id,
    lot: document.getElementById('of-lot').value,
    fournisseur_id,
    delai_annonce: document.getElementById('of-delai').value,
    conditions_paiement: document.getElementById('of-cond').value.trim(),
    montant_ttc,
    prix_initial: document.getElementById('of-prix-init').value,
    observation: document.getElementById('of-obs').value.trim(),
  }).then(res => {
    if (!res.success) { relacher(); err.textContent = res.message; err.style.display = 'block'; return; }
    toast(res.message, 'success');
    // Le bouton reste inerte jusqu'au rechargement : le relâcher ici
    // rouvrirait la fenêtre de double envoi qu'on vient de fermer.
    setTimeout(() => location.reload(), 500);
  }).catch(() => { relacher(); err.textContent = "La requête n'a pas abouti — réessayez."; err.style.display = 'block'; });
}
function febSupprimerOffre(id) {
  if (!confirm('Supprimer cette offre ?')) return;
  febPost({ action: 'delete_offre', offre_id: id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function febRetenirOffre(id) {
  if (!confirm('Retenir cette offre pour le lot ? Son fournisseur sera reporté sur les lignes non dérogées.')) return;
  febPost({ action: 'retenir_offre', offre_id: id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function febAnnulerRetenue(id) {
  if (!confirm('Annuler la sélection de cette offre ? Le lot repassera sans offre retenue.')) return;
  febPost({ action: 'annuler_retenue', offre_id: id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function febDerogerFournisseur(ligne_id, fournisseur_id) {
  if (!fournisseur_id) return;
  febPost({ action: 'deroger_fournisseur_ligne', ligne_id, fournisseur_id }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
function febMajLigneMontant(ligne_id, montant_ttc) {
  if (montant_ttc === '') return;
  febPost({ action: 'update_ligne_montant', ligne_id, montant_ttc }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
  });
}
function febMajLigneType(ligne_id, type_achat) {
  febPost({ action: 'update_ligne_type', ligne_id, type_achat }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
  });
}
function febVerifierComparatif() {
  febPost({ action: 'verifier_comparatif' }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
  });
}
function febLancerValidation() {
  if (!confirm('Lancer la validation ? Le circuit sera figé sur le montant actuel de la FEB.')) return;
  febPost({ action: 'lancer_validation' }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.href = '../achats/file_attente.php', 900);
  });
}
function febReprendre() {
  if (!confirm('Reprendre cette FEB ? Le circuit devra être relancé après correction.')) return;
  febPost({ action: 'reprendre' }).then(res => {
    toast(res.message, res.success ? 'success' : 'danger');
    if (res.success) setTimeout(() => location.reload(), 500);
  });
}
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
