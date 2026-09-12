<?php
// ============================================================
//  pages/inventaire_detail.php  —  Saisie inventaire bobines
//  Page dédiée : stock physique + écart connu éditables
// ============================================================
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/upload.php';

require_auth();
$_tmp_user = current_user();
$_tmp_role = $_tmp_user['role_slug'] ?? '';
if (!in_array($_tmp_role, ['coordinateur_site','gestionnaire_stock_bobines','gestionnaire_stock','superviseur_operation','admin','superadmin'])) {
    http_response_code(403); include __DIR__ . '/../templates/403.php'; exit;
}
unset($_tmp_user, $_tmp_role);

$user   = current_user();
$inv_id = (int)($_GET['id'] ?? 0);
if (!$inv_id) { header('Location: inventaire_bobines.php'); exit; }

$inv = db_fetch_one(
    "SELECT i.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS createur
     FROM inventaires_bobines i
     LEFT JOIN sites s ON s.id=i.site_id
     LEFT JOIN users u ON u.id=i.cree_par
     WHERE i.id=?", [$inv_id]
);
if (!$inv) { header('Location: inventaire_bobines.php'); exit; }

$role_slug    = $user['role_slug'] ?? '';
$can_edit     = $inv['statut'] === 'brouillon' && can('inventaire_bobines','can_update');
$can_validate = $inv['statut'] === 'brouillon'
    && in_array($role_slug, ['admin','superadmin','superviseur_operation','gestionnaire_stock_bobines','gestionnaire_stock']);
$is_coord           = ($role_slug === 'coordinateur_site');
// Seuls l'admin/superadmin (ou une délégation du module) peuvent interpeller
// directement le site sur une ligne verrouillée, ou autoriser/refuser une
// demande d'autorisation venue du coordinateur.
$can_demander_site  = can('inventaire_sessions','can_read');
$page_title  = 'Inventaire du ' . fmt_date($inv['date_inventaire']);
$active_page = 'inventaire_bobines';

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD']==='POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── SAUVER UNE LIGNE (stock physique — l'écart connu est en lecture seule,
    //    renseigné par le système à l'ouverture de l'inventaire, cf. n° 17
    //    réunion ERP : la saisie libre était une source d'erreur.)
    if ($action==='sauver_ligne') {
        if (!$can_edit) json_response(false,'Inventaire non modifiable.');
        $detail_id      = (int)($_POST['detail_id']     ?? 0);
        $stock_physique = $_POST['stock_physique'] !== '' ? (int)$_POST['stock_physique'] : null;
        $notes          = trim($_POST['notes'] ?? '');

        $det = db_fetch_one(
            "SELECT d.*, b.stock_systeme FROM inventaire_details_bobines d
             JOIN op_bobines b ON b.id=d.bobine_id WHERE d.id=? AND d.inventaire_id=?",
            [$detail_id, $inv_id]
        );
        if (!$det) json_response(false,'Ligne introuvable.');

        $stock_sys    = (int)$det['stock_systeme'];
        $stock_phy    = $stock_physique !== null ? $stock_physique : (int)$det['stock_physique'];
        $ecart_connu  = (int)($det['ecart_connu_avant'] ?? 0);
        $ecart_mesure = $stock_phy - $stock_sys;       // écart détecté lors de l'inventaire
        $ecart_total  = $ecart_mesure + $ecart_connu;  // écart total = mesuré + connu

        // Calcul projections
        $conso_moy = (float)$det['conso_quotidienne_moy'];
        $jours_sys  = $conso_moy > 0 ? (int)ceil($stock_sys / $conso_moy) : null;
        $jours_phy  = $conso_moy > 0 ? (int)ceil($stock_phy / $conso_moy) : null;
        $date_epuis = $jours_phy && $conso_moy > 0
            ? date('Y-m-d', strtotime("+{$jours_phy} days")) : null;

        db_query(
            "UPDATE inventaire_details_bobines
             SET stock_physique=?, ecart=?, jours_restants_systeme=?,
                 jours_restants_physique=?, date_epuisement_estime=?, notes=?
             WHERE id=?",
            [$stock_phy, $ecart_mesure, $jours_sys, $jours_phy, $date_epuis, $notes, $detail_id]
        );

        json_response(true,'Sauvegardé.',['ecart_mesure'=>$ecart_mesure,'ecart_total'=>$ecart_total,'jours_phy'=>$jours_phy,'date_epuis'=>$date_epuis]);
    }

    // ── DEMANDER UNE MODIFICATION sur une ligne déjà saisie (verrouillée) —
    //    réservé à l'admin/superadmin ou à la personne qui a ouvert la
    //    session (délégation) : le site répond directement (ligne déjà
    //    déverrouillée), pas d'étape d'autorisation.
    if ($action==='demander_modif') {
        if (!$can_demander_site) json_response(false,'Action réservée à l\'administrateur ou au responsable de session.');
        $detail_id       = (int)($_POST['detail_id'] ?? 0);
        $motif           = trim($_POST['motif'] ?? '');
        $valeur_proposee = ($_POST['valeur_proposee'] ?? '') !== '' ? (int)$_POST['valeur_proposee'] : null;
        if ($motif === '') json_response(false,'Le motif est obligatoire.');

        $det = db_fetch_one(
            "SELECT d.*, b.numero FROM inventaire_details_bobines d
             JOIN op_bobines b ON b.id=d.bobine_id WHERE d.id=? AND d.inventaire_id=?",
            [$detail_id, $inv_id]
        );
        if (!$det) json_response(false,'Ligne introuvable.');
        if ((int)$det['stock_physique']===0 && (int)$det['ecart']===0) json_response(false,"Cette ligne n'a pas encore été saisie.");
        if (db_fetch_value("SELECT COUNT(*) FROM inventaire_corrections WHERE detail_id=? AND statut IN ('en_attente','autorise')", [$detail_id])) {
            json_response(false,'Une demande est déjà en cours pour cette ligne.');
        }

        db_query(
            "INSERT INTO inventaire_corrections (detail_id,inventaire_id,bobine_id,site_id,stock_physique_actuel,valeur_proposee,motif,demandeur_id,type)
             VALUES (?,?,?,?,?,?,?,?,'demande_site')",
            [$detail_id, $inv_id, $det['bobine_id'], $inv['site_id'], (int)$det['stock_physique'], $valeur_proposee, $motif, $user['id']]
        );
        $corr_id = (int)db_last_id();

        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        $coords = db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
             WHERE r.slug='coordinateur_site' AND u.actif=1 AND u.site_id=?",
            [$inv['site_id']]
        );
        foreach ($coords as $c) {
            db_query(
                "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$c['id'], 'info', '🔁 Demande de modification',
                 "$nom demande une modification sur la bobine {$det['numero']} de votre inventaire du " . fmt_date($inv['date_inventaire']) . " : $motif",
                 '/pages/inventaire_detail.php?id=' . $inv_id]
            );
        }
        audit_log($user['id'],'CREATE','inventaire_corrections',$corr_id,"Demande modif bobine {$det['numero']} inventaire #$inv_id");
        json_response(true,'Demande envoyée au site.',['id'=>$corr_id]);
    }

    // ── DEMANDER UNE AUTORISATION DE MODIFICATION — le coordinateur (site)
    //    ne peut pas rouvrir sa ligne lui-même : il demande l'accord de
    //    l'admin, qui doit autoriser avant que le champ ne se déverrouille.
    if ($action==='demander_autorisation') {
        if (!$is_coord) json_response(false,'Action réservée au coordinateur de site.');
        $detail_id       = (int)($_POST['detail_id'] ?? 0);
        $motif           = trim($_POST['motif'] ?? '');
        $valeur_proposee = ($_POST['valeur_proposee'] ?? '') !== '' ? (int)$_POST['valeur_proposee'] : null;
        if ($motif === '') json_response(false,'Le motif est obligatoire.');

        $det = db_fetch_one(
            "SELECT d.*, b.numero FROM inventaire_details_bobines d
             JOIN op_bobines b ON b.id=d.bobine_id WHERE d.id=? AND d.inventaire_id=?",
            [$detail_id, $inv_id]
        );
        if (!$det) json_response(false,'Ligne introuvable.');
        if ((int)$det['stock_physique']===0 && (int)$det['ecart']===0) json_response(false,"Cette ligne n'a pas encore été saisie.");
        if (db_fetch_value("SELECT COUNT(*) FROM inventaire_corrections WHERE detail_id=? AND statut IN ('en_attente','autorise')", [$detail_id])) {
            json_response(false,'Une demande est déjà en cours pour cette ligne.');
        }

        db_query(
            "INSERT INTO inventaire_corrections (detail_id,inventaire_id,bobine_id,site_id,stock_physique_actuel,valeur_proposee,motif,demandeur_id,type)
             VALUES (?,?,?,?,?,?,?,?,'demande_autorisation')",
            [$detail_id, $inv_id, $det['bobine_id'], $inv['site_id'], (int)$det['stock_physique'], $valeur_proposee, $motif, $user['id']]
        );
        $corr_id = (int)db_last_id();

        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        $admins = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug IN ('admin','superadmin') AND u.actif=1");
        foreach ($admins as $a) {
            db_query(
                "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$a['id'], 'info', '🔒 Demande d\'autorisation de modification',
                 "$nom demande à modifier la bobine {$det['numero']} sur l'inventaire du " . fmt_date($inv['date_inventaire']) . " ({$inv['site_nom']}) : $motif",
                 '/pages/inventaire_detail.php?id=' . $inv_id]
            );
        }
        audit_log($user['id'],'CREATE','inventaire_corrections',$corr_id,"Demande autorisation bobine {$det['numero']} inventaire #$inv_id");
        json_response(true,"Demande d'autorisation envoyée à l'administrateur.",['id'=>$corr_id]);
    }

    // ── AUTORISER une demande du coordinateur — déverrouille la ligne
    if ($action==='autoriser_modif') {
        if (!$can_demander_site) json_response(false,'Action réservée à l\'administrateur ou au responsable de session.');
        $corr_id = (int)($_POST['correction_id'] ?? 0);
        $corr = db_fetch_one(
            "SELECT * FROM inventaire_corrections WHERE id=? AND inventaire_id=? AND type='demande_autorisation' AND statut='en_attente'",
            [$corr_id, $inv_id]
        );
        if (!$corr) json_response(false,'Demande introuvable ou déjà traitée.');

        db_query("UPDATE inventaire_corrections SET statut='autorise', autorise_par=?, autorise_at=NOW() WHERE id=?", [$user['id'], $corr_id]);
        $det = db_fetch_one("SELECT numero FROM op_bobines WHERE id=?", [$corr['bobine_id']]);
        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$corr['demandeur_id'], 'info', '✅ Autorisation accordée',
             "$nom a autorisé la modification de la bobine {$det['numero']} : vous pouvez corriger la quantité physique.",
             '/pages/inventaire_detail.php?id=' . $inv_id]
        );
        audit_log($user['id'],'UPDATE','inventaire_corrections',$corr_id,"Autorisation accordée bobine {$det['numero']} inventaire #$inv_id");
        json_response(true,'Autorisation accordée — le site peut maintenant corriger la ligne.');
    }

    // ── REFUSER une demande du coordinateur — la ligne reste verrouillée
    if ($action==='refuser_modif') {
        if (!$can_demander_site) json_response(false,'Action réservée à l\'administrateur ou au responsable de session.');
        $corr_id = (int)($_POST['correction_id'] ?? 0);
        $motif_refus = trim($_POST['motif'] ?? '');
        $corr = db_fetch_one(
            "SELECT * FROM inventaire_corrections WHERE id=? AND inventaire_id=? AND type='demande_autorisation' AND statut='en_attente'",
            [$corr_id, $inv_id]
        );
        if (!$corr) json_response(false,'Demande introuvable ou déjà traitée.');

        db_query(
            "UPDATE inventaire_corrections SET statut='refuse', reponse=?, traite_par=?, traite_at=NOW() WHERE id=?",
            [$motif_refus ?: null, $user['id'], $corr_id]
        );
        $det = db_fetch_one("SELECT numero FROM op_bobines WHERE id=?", [$corr['bobine_id']]);
        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$corr['demandeur_id'], 'info', '❌ Autorisation refusée',
             "$nom a refusé la demande de modification sur la bobine {$det['numero']}" . ($motif_refus ? " : $motif_refus" : '.'),
             '/pages/inventaire_detail.php?id=' . $inv_id]
        );
        audit_log($user['id'],'UPDATE','inventaire_corrections',$corr_id,"Autorisation refusée bobine {$det['numero']} inventaire #$inv_id");
        json_response(true,'Demande refusée.');
    }

    // ── RÉPONDRE à une demande de modification — le site corrige la valeur.
    //    Couvre les deux cas : demande directe de l'admin (en_attente) et
    //    demande d'autorisation déjà accordée (autorise).
    if ($action==='repondre_modif') {
        if (!$can_edit) json_response(false,'Action non autorisée.');
        $corr_id       = (int)($_POST['correction_id'] ?? 0);
        $valeur_finale = ($_POST['valeur_finale'] ?? '') !== '' ? (int)$_POST['valeur_finale'] : null;
        $reponse       = trim($_POST['reponse'] ?? '');
        if ($valeur_finale === null) json_response(false,'La valeur corrigée est obligatoire.');

        $corr = db_fetch_one("SELECT * FROM inventaire_corrections WHERE id=? AND inventaire_id=? AND statut IN ('en_attente','autorise')", [$corr_id, $inv_id]);
        if (!$corr) json_response(false,'Demande introuvable ou déjà traitée.');
        if ($corr['type'] === 'demande_autorisation' && $corr['statut'] !== 'autorise') {
            json_response(false,"Cette demande doit d'abord être autorisée par l'administrateur.");
        }

        $det = db_fetch_one(
            "SELECT d.*, b.stock_systeme, b.numero FROM inventaire_details_bobines d
             JOIN op_bobines b ON b.id=d.bobine_id WHERE d.id=?",
            [$corr['detail_id']]
        );
        $stock_sys    = (int)$det['stock_systeme'];
        $ecart_mesure = $valeur_finale - $stock_sys;
        $conso_moy    = (float)$det['conso_quotidienne_moy'];
        $jours_phy    = $conso_moy > 0 ? (int)ceil($valeur_finale / $conso_moy) : null;
        $date_epuis   = $jours_phy ? date('Y-m-d', strtotime("+{$jours_phy} days")) : null;

        db_begin();
        try {
            db_query(
                "UPDATE inventaire_details_bobines SET stock_physique=?,ecart=?,jours_restants_physique=?,date_epuisement_estime=? WHERE id=?",
                [$valeur_finale, $ecart_mesure, $jours_phy, $date_epuis, $corr['detail_id']]
            );
            db_query(
                "UPDATE inventaire_corrections SET statut='traite',valeur_finale=?,reponse=?,traite_par=?,traite_at=NOW() WHERE id=?",
                [$valeur_finale, $reponse ?: null, $user['id'], $corr_id]
            );
            audit_log($user['id'],'UPDATE','inventaire_corrections',$corr_id,"Réponse modif bobine {$det['numero']} inventaire #$inv_id : $valeur_finale");
            db_commit();
        } catch (Exception $e) { db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }

        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$corr['demandeur_id'], 'info', '↩ Réponse à votre demande',
             "$nom a répondu à votre demande de modification sur la bobine {$det['numero']} : nouvelle valeur $valeur_finale.",
             '/pages/inventaire_detail.php?id=' . $inv_id]
        );

        json_response(true,'Réponse envoyée.',['ecart_mesure'=>$ecart_mesure,'jours_phy'=>$jours_phy,'date_epuis'=>$date_epuis]);
    }

    // ── TOUT SAUVER
    if ($action==='sauver_tout') {
        if (!$can_edit) json_response(false,'Inventaire non modifiable.');
        $lignes_data = json_decode($_POST['lignes'] ?? '[]', true);
        $saved = 0; $errors = 0;
        db_begin();
        try {
            foreach ($lignes_data as $ld) {
                $detail_id   = (int)($ld['id'] ?? 0);
                $phy_val     = $ld['phy'] !== '' ? (int)$ld['phy'] : null;
                $notes       = trim($ld['notes'] ?? '');
                if ($phy_val === null) continue;

                $det = db_fetch_one(
                    "SELECT d.*, b.stock_systeme AS b_stock_systeme, b.films_restants
                     FROM inventaire_details_bobines d
                     JOIN op_bobines b ON b.id=d.bobine_id WHERE d.id=? AND d.inventaire_id=?",
                    [$detail_id, $inv_id]
                );
                if (!$det) continue;

                // Utiliser films_restants comme référence (valeur réelle affichée)
                $stock_sys  = (int)($det['films_restants'] ?: $det['b_stock_systeme']);
                $ecart_mes  = $phy_val - $stock_sys;
                $conso_moy  = (float)$det['conso_quotidienne_moy'];
                $jours_phy  = $conso_moy > 0 ? (int)ceil($phy_val / $conso_moy) : null;
                $date_epuis = $jours_phy ? date('Y-m-d', strtotime("+{$jours_phy} days")) : null;

                db_query(
                    "UPDATE inventaire_details_bobines SET stock_physique=?,ecart=?,jours_restants_physique=?,date_epuisement_estime=?,notes=? WHERE id=?",
                    [$phy_val,$ecart_mes,$jours_phy,$date_epuis,$notes,$detail_id]
                );
                $saved++;
            }
            db_commit();
            json_response(true,"$saved ligne(s) sauvegardée(s).",['saved'=>$saved]);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── VALIDER L'INVENTAIRE
    if ($action==='valider') {
        if (!$can_validate) json_response(false,'Seul le GSB ou un administrateur peut valider l\'inventaire.');
        $lignes = db_fetch_all("SELECT * FROM inventaire_details_bobines WHERE inventaire_id=?",[$inv_id]);
        $nb_ecarts = 0;
        $total_physique = 0;
        db_begin();
        try {
            foreach ($lignes as $l) {
                // Ignorer les lignes sans stock physique déclaré. La colonne est
                // NOT NULL (défaut 0 à la création, cf. inventaire_bobines.php),
                // donc le seul moyen fiable de distinguer « jamais saisi » de
                // « saisi à zéro » est le même critère que le récap de
                // validation (n° 18) : stock_physique>0 OU un écart déjà stocké.
                // Un test sur === null/'' ne se déclenche jamais et laissait
                // passer toutes les lignes non saisies avec physique=0, ce qui
                // vidait à tort le stock système de chaque bobine non comptée.
                if ((int)$l['stock_physique'] <= 0 && (int)$l['ecart'] == 0) continue;
                $phy     = (int)$l['stock_physique'];
                $sys_inv = (int)$l['stock_systeme']; // snapshot au moment de la saisie
                $total_physique += $phy;

                // Toujours appliquer le stock physique déclaré — source de vérité
                db_query(
                    "UPDATE op_bobines SET films_restants=?, stock_systeme=?,
                     statut=CASE WHEN ? > 0 THEN (CASE WHEN statut IN('retiree') THEN 'retiree' ELSE (CASE WHEN statut='en_cours' THEN 'en_cours' ELSE 'en_stock' END) END) ELSE 'epuisee' END
                     WHERE id=?",
                    [$phy, $phy, $phy, $l['bobine_id']]
                );

                // Fermer les écarts ouverts précédents
                db_query("UPDATE ecarts_bobines SET statut='resolu', resolu_at=NOW(), resolu_par=?
                          WHERE bobine_id=? AND statut='ouvert'",
                    [$user['id'], $l['bobine_id']]);

                // Recalculer l'écart réel (phy vs films_restants système actuel)
                $ecart_reel = (int)db_fetch_value(
                    "SELECT films_restants FROM op_bobines WHERE id=?", [$l['bobine_id']]
                );
                // L'écart = ce qu'on vient de poser vs ce qu'il y avait avant
                $ecart_applique = $phy - $sys_inv;

                if ($ecart_applique != 0) {
                    $nb_ecarts++;
                    db_query(
                        "INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,ref_id,created_by)
                         VALUES (?,?,?,?,?,?,?,?)",
                        [$l['bobine_id'],'ajustement_inventaire',$ecart_applique,$sys_inv,$phy,"Inventaire #$inv_id",$inv_id,$user['id']]
                    );
                    db_query(
                        "INSERT INTO ecarts_bobines (bobine_id,date_constat,stock_systeme,stock_physique,ecart,motif,source,inventaire_id,statut,resolu_at,resolu_par,created_by)
                         VALUES (?,?,?,?,?,?,?,?,'resolu',NOW(),?,?)",
                        [$l['bobine_id'],$inv['date_inventaire'],$sys_inv,$phy,$ecart_applique,
                         $l['notes']??'','inventaire',$inv_id,$user['id'],$user['id']]
                    );
                }
            }
            db_query("UPDATE inventaires_bobines SET statut='valide',nb_ecarts=?,total_films_physique=?,valide_par=?,valide_at=NOW() WHERE id=?",
                [$nb_ecarts,$total_physique,$user['id'],$inv_id]);
            audit_log($user['id'],'UPDATE','inventaire_bobines',$inv_id,"Validation inventaire #$inv_id — $nb_ecarts écart(s)");
            db_commit();
            json_response(true,"Inventaire validé. $nb_ecarts écart(s) traité(s).");
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    json_response(false,'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$lignes = db_fetch_all(
    "SELECT d.*,
            b.numero, b.type_code, b.format, b.statut AS bob_statut,
            b.stock_systeme AS stock_realtime,  -- stock actuel (temps réel)
            s.nom AS site_nom,
            -- Consommation moyenne 30j glissants (dynamique)
            COALESCE(
              (SELECT SUM(cb.quantite)/GREATEST(DATEDIFF(CURRENT_DATE, MIN(cb.date_conso)),1)
               FROM consommations_bobines cb
               WHERE cb.bobine_id=b.id AND cb.date_conso>=(CURRENT_DATE - INTERVAL 30 DAY)),
            0) AS conso_moy_realtime
     FROM inventaire_details_bobines d
     JOIN op_bobines b ON b.id=d.bobine_id
     LEFT JOIN sites s ON s.id=b.site_id
     WHERE d.inventaire_id=?
     ORDER BY b.serie, b.type_code, b.numero",
    [$inv_id]
);

// Écarts connus = écarts OUVERTS liés à CET inventaire uniquement
// Après validation, tous les écarts précédents sont fermés
$bobine_ids = array_column($lignes,'bobine_id');
$ecarts_connus_map = [];
if (!empty($bobine_ids)) {
    $ph  = implode(',', array_fill(0,count($bobine_ids),'?'));
    $params_ec = array_merge($bobine_ids, [$inv_id]);
    $ecs = db_fetch_all(
        "SELECT bobine_id, SUM(ecart) AS total_ecart, COUNT(*) AS nb,
                GROUP_CONCAT(CONCAT(date_constat,': ',ecart,' films') ORDER BY date_constat DESC SEPARATOR ' | ') AS detail
         FROM ecarts_bobines
         WHERE bobine_id IN ($ph) AND statut='ouvert'
           AND inventaire_id = ?
         GROUP BY bobine_id", $params_ec
    );
    foreach ($ecs as $ec) $ecarts_connus_map[$ec['bobine_id']] = $ec;
}

// Demandes de modification par ligne : la plus récente active (en_attente
// ou autorise — déverrouille ou non selon le type), et la plus récente
// close (traite/refuse, pour l'historique). Une ligne ne peut avoir
// qu'une seule demande active à la fois (contrôlé à la création).
$corrections_map = [];
$corrections_rows = db_fetch_all(
    "SELECT c.*, CONCAT(u1.prenom,' ',u1.nom) AS demandeur_nom, CONCAT(u2.prenom,' ',u2.nom) AS traite_par_nom
     FROM inventaire_corrections c
     LEFT JOIN users u1 ON u1.id = c.demandeur_id
     LEFT JOIN users u2 ON u2.id = c.traite_par
     WHERE c.inventaire_id = ?
     ORDER BY c.created_at DESC",
    [$inv_id]
);
foreach ($corrections_rows as $c) {
    $did = (int)$c['detail_id'];
    if (!isset($corrections_map[$did])) $corrections_map[$did] = ['active' => null, 'close' => null];
    if (in_array($c['statut'], ['en_attente','autorise'], true) && !$corrections_map[$did]['active']) {
        $corrections_map[$did]['active'] = $c;
    }
    if (in_array($c['statut'], ['traite','refuse'], true) && !$corrections_map[$did]['close']) {
        $corrections_map[$did]['close'] = $c;
    }
}

// Stats
$nb_saisis    = count(array_filter($lignes, fn($l)=>$l['stock_physique']>0||$l['ecart']!=0));
$nb_ecarts    = count(array_filter($lignes, fn($l)=>$l['ecart']!=0));
$nb_non_saisi = count($lignes) - $nb_saisis;

// ============================================================
//  EXPORTS
// ============================================================
$export = trim($_GET['export'] ?? '');

// n° 2.3 CR PDG — les exports XLSX et PDF contiennent la quantité système et
// l'écart, précisément ce qu'on masque au coordinateur à l'écran. Sans cette
// garde, un simple ?export=xlsx sur la page qu'il consulte pour compter lui
// rendait l'intégralité de la comparaison : le masquage n'aurait tenu que sur
// l'affichage.
if ($export !== '' && $is_coord) {
    http_response_code(403);
    exit("Export réservé aux profils de validation.");
}

if ($export === 'xlsx') {
    $sp = new Spreadsheet();
    $sh = $sp->getActiveSheet()->setTitle('Inventaire');

    $titre = 'Inventaire ' . $inv['type_inventaire'] . ' — ' . fmt_date($inv['date_inventaire']) . ' — ' . ($inv['site_nom'] ?? 'Tous');
    $sh->mergeCells('A1:L1');
    $sh->setCellValue('A1', $titre);
    $sh->getStyle('A1:L1')->applyFromArray([
        'font'      => ['bold'=>true,'size'=>13,'color'=>['argb'=>'FFFFFFFF']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF06033A']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    ]);
    $sh->getRowDimension(1)->setRowHeight(24);

    $headers = ['Numéro','Type','Site','Qté Système','Qté Physique','Écart Mesuré','Conso/jour','Jours restants','Date épuisement','Statut Bobine','Notes'];
    foreach ($headers as $i => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sh->setCellValue("{$col}2", $h);
        $sh->getStyle("{$col}2")->applyFromArray([
            'font'      => ['bold'=>true,'color'=>['argb'=>'FFFFFFFF']],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF1B75BC']],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'wrapText'=>true],
        ]);
    }
    $sh->getRowDimension(2)->setRowHeight(28);

    $row = 3;
    foreach ($lignes as $l) {
        $ecart = (int)$l['ecart'];
        $sh->setCellValue("A$row", $l['numero']);
        $sh->setCellValue("B$row", $l['type_code']);
        $sh->setCellValue("C$row", $l['site_nom'] ?? '—');
        $sh->setCellValue("D$row", (int)$l['stock_systeme']);
        $sh->setCellValue("E$row", $l['stock_physique'] !== null ? (int)$l['stock_physique'] : '');
        $sh->setCellValue("F$row", $ecart);
        $sh->setCellValue("G$row", $l['conso_moy_realtime'] ? round((float)$l['conso_moy_realtime'], 1) : '');
        $sh->setCellValue("H$row", $l['jours_restants_physique'] ?? '');
        $sh->setCellValue("I$row", $l['date_epuisement_estime'] ? fmt_date($l['date_epuisement_estime']) : '');
        $sh->setCellValue("J$row", $l['bob_statut'] ?? '');
        $sh->setCellValue("K$row", $l['notes'] ?? '');

        if ($ecart < 0) {
            $sh->getStyle("F$row")->getFont()->getColor()->setARGB('FFDC2626');
            $sh->getStyle("F$row")->getFont()->setBold(true);
        } elseif ($ecart > 0) {
            $sh->getStyle("F$row")->getFont()->getColor()->setARGB('FF16A34A');
            $sh->getStyle("F$row")->getFont()->setBold(true);
        }
        if ($row % 2 === 0) {
            $sh->getStyle("A$row:K$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
        }
        $row++;
    }

    // Ligne totaux
    $total_sys = array_sum(array_column($lignes,'stock_systeme'));
    $total_phy = array_sum(array_filter(array_column($lignes,'stock_physique'), fn($v)=>$v!==null));
    $total_eca = array_sum(array_column($lignes,'ecart'));
    $sh->setCellValue("A$row", 'TOTAL');
    $sh->setCellValue("D$row", $total_sys);
    $sh->setCellValue("E$row", $total_phy);
    $sh->setCellValue("F$row", $total_eca);
    $sh->getStyle("A$row:K$row")->applyFromArray([
        'font' => ['bold'=>true],
        'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFE8F0FE']],
    ]);

    $widths = [16,10,18,14,14,14,12,14,16,14,28];
    foreach ($widths as $i => $w) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sh->getColumnDimension($col)->setWidth($w);
    }
    $sh->getStyle("A2:K$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="inventaire_' . $inv_id . '_' . date('Ymd') . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new XlsxWriter($sp);
    $tmp = tempnam(sys_get_temp_dir(), 'inv_');
    $writer->save($tmp); readfile($tmp); unlink($tmp);
    exit;
}

if ($export === 'pdf') {
    $rows_html = '';
    $total_sys = 0; $total_phy = 0; $total_eca = 0;
    foreach ($lignes as $l) {
        $ecart = (int)$l['ecart'];
        $total_sys += (int)$l['stock_systeme'];
        $total_phy += $l['stock_physique'] !== null ? (int)$l['stock_physique'] : 0;
        $total_eca += $ecart;
        $ec = $ecart < 0 ? '#DC2626' : ($ecart > 0 ? '#16A34A' : '#94a3b8');
        $rows_html .= '<tr>
            <td style="font-weight:700">' . htmlspecialchars($l['numero']) . '</td>
            <td>' . htmlspecialchars($l['type_code']) . '</td>
            <td>' . htmlspecialchars($l['site_nom'] ?? '—') . '</td>
            <td style="text-align:right">' . number_format((int)$l['stock_systeme']) . '</td>
            <td style="text-align:right">' . ($l['stock_physique'] !== null ? number_format((int)$l['stock_physique']) : '—') . '</td>
            <td style="text-align:right;font-weight:700;color:' . $ec . '">' . ($ecart != 0 ? ($ecart > 0 ? '+' : '') . $ecart : '—') . '</td>
            <td style="text-align:center">' . ($l['jours_restants_physique'] ?? '—') . '</td>
            <td style="text-align:center">' . ($l['date_epuisement_estime'] ? fmt_date($l['date_epuisement_estime'], 'd/m/Y') : '—') . '</td>
            <td>' . htmlspecialchars($l['notes'] ?? '') . '</td>
        </tr>';
    }
    $statut_color = $inv['statut'] === 'valide' ? '#065f46' : '#92400e';
    $statut_bg    = $inv['statut'] === 'valide' ? '#d1fae5' : '#fef3c7';
    $statut_txt   = $inv['statut'] === 'valide' ? 'Validé' : 'En cours';
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
    body{font-family:Arial,sans-serif;font-size:9px;margin:15px}
    h1{font-size:13px;color:#06033A;margin:0 0 2px}
    .meta{font-size:9px;color:#64748b;margin-bottom:12px;display:flex;gap:16px}
    .badge{padding:2px 8px;border-radius:8px;font-size:8px;font-weight:700}
    table{width:100%;border-collapse:collapse}
    th{background:#06033A;color:#fff;padding:6px 7px;font-size:8px;text-align:left}
    td{padding:4px 7px;border-bottom:1px solid #e2e8f0;font-size:8.5px}
    tr:nth-child(even) td{background:#f8fafc}
    tr.tot td{background:#e8f0fe;font-weight:700;font-size:9px}
    </style></head><body>
    <table width="100%" style="border-collapse:collapse;margin-bottom:8px"><tr>
      <td style="vertical-align:middle;width:85px;padding-right:10px">' . pdf_logo_img('38px') . '</td>
      <td style="vertical-align:middle;padding-left:12px;border-left:3px solid #06033A">
        <div style="font-size:13px;font-weight:bold;color:#06033A">Inventaire ' . htmlspecialchars($inv['type_inventaire']) . ' — ' . fmt_date($inv['date_inventaire']) . '</div>
        <div style="font-size:9px;color:#64748b;margin-top:2px">Express Multiservices CI</div>
      </td>
    </tr></table>
    <div class="meta">
      <span>' . htmlspecialchars($inv['site_nom'] ?? 'Tous les sites') . '</span>
      <span>' . count($lignes) . ' bobines</span>
      <span>Créé par ' . htmlspecialchars($inv['createur'] ?? '—') . '</span>
      <span><span class="badge" style="background:' . $statut_bg . ';color:' . $statut_color . '">' . $statut_txt . '</span></span>
      <span>Généré le ' . date('d/m/Y H:i') . '</span>
    </div>
    <table>
      <thead><tr>
        <th>Numéro</th><th>Type</th><th>Site</th>
        <th style="text-align:right">Qté Système</th>
        <th style="text-align:right">Qté Physique</th>
        <th style="text-align:right">Écart</th>
        <th style="text-align:center">Jours rest.</th>
        <th style="text-align:center">Date épuis.</th>
        <th>Notes</th>
      </tr></thead>
      <tbody>' . $rows_html . '
      <tr class="tot">
        <td colspan="3">TOTAL</td>
        <td style="text-align:right">' . number_format($total_sys) . '</td>
        <td style="text-align:right">' . number_format($total_phy) . '</td>
        <td style="text-align:right;color:' . ($total_eca < 0 ? '#DC2626' : ($total_eca > 0 ? '#16A34A' : '#06033A')) . '">' . ($total_eca != 0 ? ($total_eca > 0 ? '+' : '') . $total_eca : '—') . '</td>
        <td colspan="3"></td>
      </tr>
      </tbody>
    </table></body></html>';

    $opts = new Options(); $opts->set('isRemoteEnabled', false);
    $pdf = new Dompdf($opts);
    $pdf->loadHtml($html); $pdf->setPaper('A4','landscape'); $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="inventaire_' . $inv_id . '_' . date('Ymd') . '.pdf"');
    echo $pdf->output(); exit;
}

include __DIR__ . '/../templates/header.php';
?>
<style>
.inv-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.inv-meta{font-size:13px;color:var(--muted)}
.inv-meta strong{color:var(--navy)}
.stat-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.chip{padding:5px 12px;border-radius:20px;font-size:12.5px;font-weight:600}
.chip.blue{background:#e3f2fd;color:#1565c0}
.chip.green{background:#eafaf1;color:#1e8449}
.chip.red{background:#fdf0ef;color:#c0392b}
.chip.orange{background:#fff8e7;color:#b7791f}
.chip.gray{background:#f1f5f9;color:#64748b}

input.saisie{width:80px;padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:6px;text-align:right;
             font-family:'Montserrat',sans-serif;font-weight:700;font-size:13px;transition:border-color .2s}
input.saisie:focus{outline:none;border-color:var(--blue-mid,#1a56a0)}
input.saisie.ok{border-color:#27ae60;background:#f0fdf4}
input.saisie.nok{border-color:#e74c3c;background:#fff5f5}
input.saisie:disabled{background:#f1f5f9;color:#64748b;cursor:not-allowed;opacity:1}

.ecart-val{font-family:'Montserrat',sans-serif;font-size:14px;font-weight:800}
.ecart-pos{color:#27ae60}
.ecart-neg{color:#e74c3c}
.ecart-zero{color:#27ae60}

.badge-connu{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700;white-space:nowrap}
</style>

<!-- TOOLBAR -->
<div class="inv-toolbar">
  <div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
      <?php if ($inv['session_id'] && can('inventaire_sessions','can_read')): ?>
      <a href="<?= APP_URL ?>/pages/inventaire_sessions.php?id=<?= (int)$inv['session_id'] ?>" style="color:var(--muted);font-size:13px;text-decoration:none">← Session</a>
      <?php else: ?>
      <a href="<?= APP_URL ?>/pages/inventaire_bobines.php" style="color:var(--muted);font-size:13px;text-decoration:none">← Inventaires</a>
      <?php endif; ?>
      <h2 style="font-family:'Montserrat',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">
        Inventaire du <?= fmt_date($inv['date_inventaire']) ?>
      </h2>
      <span style="padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;background:<?= $inv['statut']==='valide'?'#eafaf1':($inv['statut']==='brouillon'?'#fff8e7':'#fdf0ef') ?>;color:<?= $inv['statut']==='valide'?'#1e8449':($inv['statut']==='brouillon'?'#b7791f':'#c0392b') ?>">
        <?= ['brouillon'=>'⏳ Brouillon','valide'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Validé','annule'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Annulé'][$inv['statut']]??$inv['statut'] ?>
      </span>
    </div>
    <div class="inv-meta">
      <?= h($inv['site_nom']??'Tous les sites') ?> · <strong><?= count($lignes) ?></strong> bobines · Créé par <?= h($inv['createur']??'—') ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if(!$is_coord): /* exports refusés au coordinateur côté serveur —
         cf. la garde en tête de fichier ; on masque aussi les boutons pour
         ne pas lui proposer un lien qui renverra 403. */ ?>
    <a href="?id=<?= $inv_id ?>&export=xlsx" class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:5px">
      <i class="ph-duotone ph-microsoft-excel-logo"></i> Excel
    </a>
    <a href="?id=<?= $inv_id ?>&export=pdf" class="btn btn-secondary" style="font-size:13px;display:flex;align-items:center;gap:5px">
      <i class="ph-duotone ph-file-pdf"></i> PDF
    </a>
    <?php endif; ?>
    <?php if($can_edit): ?>
    <button class="btn btn-secondary" id="btnSauverTout" onclick="sauverTout()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Tout sauver</button>
    <?php endif; ?>
    <?php if($can_validate): ?>
    <button class="btn btn-success" onclick="ouvrirRecapValidation()"><i class="ph ph-check-circle" aria-hidden="true"></i> Valider l'inventaire</button>
    <?php endif; ?>
  </div>
</div>

<!-- STATS -->
<div class="stat-chips">
  <span class="chip blue"><i class="ph ph-package" aria-hidden="true"></i> Bobines : <strong id="statTotal"><?= count($lignes) ?></strong></span>
  <?php if(!$is_coord): /* n° 2.3 CR PDG : ces deux compteurs comparent au
       stock système — les afficher au coordinateur reviendrait à lui livrer
       la valeur système qu'on masque par ailleurs. */ ?>
  <span class="chip green"><i class="ph ph-check-circle" aria-hidden="true"></i> OK : <strong id="statOk"><?= $nb_saisis - $nb_ecarts ?></strong></span>
  <span class="chip red"><i class="ph ph-warning" aria-hidden="true"></i> Écarts : <strong id="statEcarts"><?= $nb_ecarts ?></strong></span>
  <?php endif; ?>
  <span class="chip gray">⏳ Non saisis : <strong id="statNonSaisis"><?= $nb_non_saisi ?></strong></span>
  <?php
  $nb_ecarts_connus = count(array_filter($bobine_ids, fn($id)=>isset($ecarts_connus_map[$id])));
  if($nb_ecarts_connus>0):
  ?>
  <span class="chip orange"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Écarts connus : <strong><?= $nb_ecarts_connus ?></strong></span>
  <?php endif; ?>
</div>

<div id="alertZone"></div>

<!-- MODAL RÉCAP VALIDATION (n° 18 réunion ERP) -->
<div id="modalRecapValidation" style="display:none;position:fixed;inset:0;background:rgba(13,31,53,.5);z-index:800;align-items:center;justify-content:center"
     onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:16px;padding:24px 26px;width:100%;max-width:560px;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:17px;font-weight:800;color:var(--navy);margin:0 0 4px"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Récapitulatif avant validation</h3>
    <p style="font-size:13px;color:var(--muted);margin:0 0 16px">Vérifiez les données ci-dessous avant d'enregistrer définitivement l'inventaire.</p>
    <div id="recapStats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px"></div>
    <div id="recapNonSaisi" style="display:none;background:#fff8e7;color:#b7791f;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px"></div>
    <div id="recapEcarts"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid var(--border)">
      <button class="btn btn-secondary" onclick="document.getElementById('modalRecapValidation').style.display='none'">Non, modifier les infos</button>
      <button class="btn btn-success" id="btnConfirmerValidation" onclick="confirmerValidation()"><i class="ph ph-check-circle" aria-hidden="true"></i> Oui, valider l'inventaire</button>
    </div>
  </div>
</div>

<!-- MODAL MOTIF (partagée : demander modif au site / demander autorisation / refuser) -->
<div id="modalMotif" style="display:none;position:fixed;inset:0;background:rgba(13,31,53,.5);z-index:800;align-items:center;justify-content:center"
     onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:16px;padding:22px 24px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 id="modalMotifTitre" style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin:0 0 14px"></h3>
    <div id="modalMotifAlert"></div>
    <div class="form-group" style="margin-bottom:12px">
      <label id="modalMotifLabel" style="font-size:12.5px;font-weight:700">Motif *</label>
      <textarea id="modalMotifTexte" rows="3" class="form-control" placeholder="Expliquez la raison…"></textarea>
    </div>
    <div class="form-group" id="modalMotifValeurWrap" style="margin-bottom:6px">
      <label style="font-size:12.5px;font-weight:700">Valeur proposée <span style="font-weight:400;color:var(--muted)">(optionnel)</span></label>
      <input type="number" min="0" id="modalMotifValeur" class="form-control" placeholder="Laisser vide si vous n'avez pas de valeur précise">
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
      <button class="btn btn-secondary" onclick="document.getElementById('modalMotif').style.display='none'">Annuler</button>
      <button class="btn btn-primary" id="btnModalMotif" onclick="envoyerModalMotif()">Envoyer</button>
    </div>
  </div>
</div>

<!-- MODAL RÉPONDRE À UNE DEMANDE -->
<div id="modalRepondreModif" style="display:none;position:fixed;inset:0;background:rgba(13,31,53,.5);z-index:800;align-items:center;justify-content:center"
     onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:16px;padding:22px 24px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin:0 0 6px">↩ Répondre à la demande</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px">
      Corrigez d'abord la valeur dans la colonne « Qté physique », puis confirmez ici.
    </p>
    <div id="repModifAlert"></div>
    <div class="form-group" style="margin-bottom:6px">
      <label style="font-size:12.5px;font-weight:700">Commentaire <span style="font-weight:400;color:var(--muted)">(optionnel)</span></label>
      <textarea id="repModifTexte" rows="2" class="form-control" placeholder="Précision sur la correction…"></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
      <button class="btn btn-secondary" onclick="document.getElementById('modalRepondreModif').style.display='none'">Annuler</button>
      <button class="btn btn-primary" id="btnEnvoyerRepModif" onclick="envoyerReponseModif()">Confirmer la correction</button>
    </div>
  </div>
</div>

<!-- LÉGENDE -->
<div style="display:flex;gap:16px;font-size:12px;color:var(--muted);margin-bottom:12px;align-items:center">
  <span><i class="ph ph-note-pencil" aria-hidden="true"></i> <strong>Films comptés</strong> : stock réel compté physiquement</span>
  <?php if(!$is_coord): /* décrit une colonne masquée au coordinateur */ ?>
  <span style="border-left:1px solid var(--border);padding-left:16px">
    <span style="border-bottom:2px dashed #f39c12;padding-bottom:1px"><strong>Écart connu</strong></span> : différence connue avant l'inventaire, renseignée automatiquement par le système
  </span>
  <?php endif; ?>
</div>

<!-- TABLEAU PRINCIPAL -->
<?php if(!$is_coord): /* décrit les colonnes masquées au coordinateur */ ?>
<div style="background:#e8f4fd;border:1px solid #90caf9;border-radius:10px;padding:10px 16px;margin-bottom:14px;font-size:13px;display:flex;gap:20px;flex-wrap:wrap">
  <span><i class="ph ph-push-pin" aria-hidden="true"></i> <strong>Qté physique</strong> = photo figée à la date de l'inventaire</span>
  <span style="border-left:1px solid #90caf9;padding-left:20px"><i class="ph ph-circle" aria-hidden="true"></i> <strong>Qté temps réel</strong> = stock actuel mis à jour en continu par les consommations</span>
  <span style="border-left:1px solid #90caf9;padding-left:20px;border-bottom:2px dashed #f39c12;padding-bottom:1px"><strong>Écart connu</strong> = différence connue avant l'inventaire</span>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap" style="overflow-x:auto">
    <table id="invTable">
      <thead>
        <tr style="background:#0d1f35;color:white">
          <th style="padding:10px 14px;text-align:left">Numéro</th>
          <th style="padding:10px 14px">Type</th>
          <th style="padding:10px 14px">Site</th>
          <?php if(!$is_coord): ?>
          <th style="padding:10px 14px;text-align:right">Qté système <i class="ph ph-monitor" aria-hidden="true"></i></th>
          <?php endif; ?>
          <th style="padding:10px 14px;text-align:right;border-right:1px solid rgba(255,255,255,.15)">Qté physique <i class="ph ph-push-pin" aria-hidden="true"></i></th>
          <?php if(!$is_coord): /* n° 2.3 CR PDG — les écarts, mais aussi tout
               le bloc « temps réel » : la quantité temps réel EST la valeur
               système, et jours restants / épuisement s'en déduisent. */ ?>
          <th style="padding:10px 14px;text-align:center">Écart connu</th>
          <th style="padding:10px 14px;text-align:center">Écart mesuré</th>
          <th style="padding:10px 14px;text-align:right;background:rgba(255,255,255,.08);border-left:2px solid #f39c12"><i class="ph ph-circle" aria-hidden="true"></i> Qté temps réel</th>
          <th style="padding:10px 14px;text-align:right;background:rgba(255,255,255,.08)">Conso/j moy</th>
          <th style="padding:10px 14px;text-align:right;background:rgba(255,255,255,.08)">Jours restants</th>
          <th style="padding:10px 14px;text-align:center;background:rgba(255,255,255,.08)">Épuisement estimé</th>
          <?php endif; ?>
          <?php if($can_edit): ?>
          <th style="padding:10px 14px;text-align:center">Enregistrement</th>
          <th style="padding:10px 14px;text-align:left">Demande de modification</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach($lignes as $l):
        // ── Données figées (photo inventaire)
        $stock_phy  = (int)$l['stock_physique'];   // figé
        $ecart_mes  = (int)$l['ecart'];            // figé
        $saisi      = $l['stock_physique'] > 0 || $ecart_mes != 0;
        $ec_connu   = $ecarts_connus_map[$l['bobine_id']] ?? null;
        $demande_active = $corrections_map[$l['id']]['active'] ?? null;
        $demande_close  = $corrections_map[$l['id']]['close']  ?? null;
        // Déverrouille la ligne : toujours pour une demande directe de
        // l'admin (demande_site), seulement une fois autorisée pour une
        // demande venant du coordinateur (demande_autorisation).
        $deverrouille = $demande_active && (
            $demande_active['type'] === 'demande_site'
            || ($demande_active['type'] === 'demande_autorisation' && $demande_active['statut'] === 'autorise')
        );

        // ── Données temps réel (dynamiques)
        $stock_rt   = (int)$l['stock_realtime'];          // stock actuel
        $conso_moy  = (float)$l['conso_moy_realtime'];    // conso 30j glissants
        $jours_rt   = $conso_moy > 0 && $stock_rt > 0 ? (int)ceil($stock_rt/$conso_moy) : null;
        $jours_color= $jours_rt ? ($jours_rt<30?'#e74c3c':($jours_rt<90?'#f39c12':'#27ae60')) : '#94a3b8';
        $date_epuis_rt = $jours_rt ? date('Y-m-d', strtotime("+{$jours_rt} days")) : null;

        // Couleur de fond — n° 2.3 CR PDG : pour le coordinateur, elle ne
        // distingue que « saisi » de « non saisi ». Le rouge de l'écart et
        // l'ambre de l'écart connu révéleraient la comparaison au système.
        $row_bg = $is_coord
                ? ($saisi ? 'white' : '#fffbf0')
                : (!$saisi && $ec_connu ? '#fffbec'
                : (!$saisi             ? '#fffbf0'
                : ($ecart_mes != 0     ? '#fff5f5'
                :                        'white')));
      ?>
      <tr id="row-<?= $l['id'] ?>" style="border-bottom:1px solid #e2e8f0;background:<?= $row_bg ?>"
          data-numero="<?= h($l['numero']) ?>" data-connu="<?= (int)($l['ecart_connu_avant'] ?? 0) ?>">

        <!-- Numéro -->
        <td style="padding:9px 14px;font-family:monospace;font-weight:700;color:var(--navy);font-size:13px"><?= h($l['numero']) ?></td>

        <!-- Type -->
        <td style="padding:9px 14px;text-align:center">
          <span style="background:#f1f5f9;padding:2px 7px;border-radius:4px;font-size:12px;font-weight:600"><?= h($l['type_code']) ?></span>
          <?php if($l['format']): ?><br><span style="font-size:12px;color:#94a3b8"><?= h($l['format']) ?></span><?php endif; ?>
        </td>

        <!-- Site -->
        <td style="padding:9px 14px;font-size:12px;color:var(--muted)"><?= h($l['site_nom']??'—') ?></td>

        <?php if(!$is_coord): ?>
        <!-- Qté système (figée — valeur au moment de l'inventaire) -->
        <td style="padding:9px 14px;text-align:right">
          <span style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:15px;color:var(--navy)">
            <?= number_format((int)$l['stock_systeme']) ?>
          </span>
        </td>
        <?php endif; ?>

        <!-- Qté physique (figée) -->
        <td style="padding:9px 14px;text-align:right;border-right:1px solid #e2e8f0">
          <?php if($can_edit): ?>
          <?php /* n° 2.3 CR PDG — pour le coordinateur, ni data-sys ni
             data-conso ni classe ok/nok : la valeur système ne doit
             apparaître nulle part dans la page, même en attribut. */ ?>
          <input type="number" min="0"
                 class="saisie <?= (!$is_coord && $saisi) ? ($ecart_mes!=0?'nok':'ok') : '' ?>"
                 id="phy-<?= $l['id'] ?>"
                 value="<?= $saisi ? $stock_phy : '' ?>"
                 placeholder="—"
                 <?php if(!$is_coord): ?>data-sys="<?= $stock_rt ?>"
                 data-conso="<?= $conso_moy ?>"<?php endif; ?>
                 data-saved="<?= $saisi ? $stock_phy : '' ?>"
                 <?= ($saisi && !$deverrouille) ? 'disabled' : '' ?>
                 oninput="onPhyInput(<?= $l['id'] ?>,<?= $is_coord ? 'null,null' : $stock_rt.','.$conso_moy ?>)">
          <?php else: ?>
          <span style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:14px">
            <?= $saisi ? number_format($stock_phy) : '<span style="color:#94a3b8">—</span>' ?>
          </span>
          <?php endif; ?>
        </td>

        <?php if(!$is_coord): /* n° 2.3 CR PDG — bloc masqué au coordinateur :
             écarts + quantité temps réel + tout ce qui s'en déduit. */ ?>
        <!-- Écart connu — lecture seule, valeur système à l'ouverture de l'inventaire
             (n° 17 réunion ERP : plus de saisie libre, source d'erreur) -->
        <td style="padding:9px 14px;text-align:center">
          <?php $ecart_connu_avant = (int)($l['ecart_connu_avant'] ?? 0); ?>
          <?php if ($ecart_connu_avant !== 0): ?>
            <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:<?= $ecart_connu_avant<0?'#e74c3c':'#27ae60' ?>"
                  title="Renseigné automatiquement à l'ouverture de l'inventaire">
              <?= $ecart_connu_avant>0?'+':'' ?><?= $ecart_connu_avant ?>
            </span>
          <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
        </td>

        <!-- Écart mesuré (figé — calculé au moment de l'inventaire) -->
        <td style="padding:9px 14px;text-align:center" id="ecart-<?= $l['id'] ?>">
          <?php if($saisi): ?>
          <span class="ecart-val <?= $ecart_mes<0?'ecart-neg':($ecart_mes>0?'ecart-pos':'ecart-zero') ?>">
            <?= $ecart_mes!=0?(($ecart_mes>0?'+':'').$ecart_mes.' films'):'<i class="ph ph-check-circle" aria-hidden="true"></i> OK' ?>
          </span>
          <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
        </td>

        <!-- Qté TEMPS RÉEL (dynamique — bouge avec les consos) -->
        <td style="padding:9px 14px;text-align:right;background:#f8fafc;border-left:2px solid #f39c12">
          <?php
          $rt_pct = $stock_rt > 0 ? min(100, round($stock_rt / max(1,(int)($l['stock_systeme']??500)) * 100)) : 0;
          $rt_color = $rt_pct > 50 ? '#27ae60' : ($rt_pct > 20 ? '#f39c12' : '#e74c3c');
          ?>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:3px">
            <span style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:15px;color:<?= $rt_color ?>">
              <?= number_format($stock_rt) ?>
            </span>
            <?php if($saisi && $stock_rt !== $stock_phy): ?>
            <span style="font-size:12px;color:<?= $stock_rt<$stock_phy?'#e74c3c':'#27ae60' ?>">
              <?= $stock_rt<$stock_phy ? '▼' : '▲' ?> <?= abs($stock_rt - $stock_phy) ?> depuis inventaire
            </span>
            <?php endif; ?>
          </div>
        </td>

        <!-- Conso/j moy (dynamique 30j) -->
        <td style="padding:9px 14px;text-align:right;background:#f8fafc;font-size:12px;color:#64748b">
          <?= $conso_moy > 0 ? number_format($conso_moy,1) : '—' ?>
        </td>

        <!-- Jours restants (basé sur temps réel) -->
        <td style="padding:9px 14px;text-align:right;background:#f8fafc" id="jours-<?= $l['id'] ?>">
          <?php if($jours_rt): ?>
          <span style="color:<?= $jours_color ?>;font-weight:700"><?= $jours_rt ?>j</span>
          <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
        </td>

        <!-- Épuisement estimé (dynamique) -->
        <td style="padding:9px 14px;text-align:center;background:#f8fafc;font-size:12px" id="epuis-<?= $l['id'] ?>">
          <?php if($date_epuis_rt): ?>
          <span style="color:<?= $jours_color ?>"><?= fmt_date($date_epuis_rt) ?></span>
          <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
        </td>
        <?php endif; /* fin du bloc masqué au coordinateur */ ?>

        <!-- Sauver -->
        <?php if($can_edit): ?>
        <td style="padding:9px 14px;text-align:center;white-space:nowrap">
          <span id="etat-<?= $l['id'] ?>"
                style="display:<?= $saisi ? 'inline' : 'none' ?>;font-size:12px;font-weight:700;
                       color:<?= $saisi ? '#1e8449' : '#b7791f' ?>;margin-right:8px">
            <?= $saisi ? '<i class="ph ph-check-circle" aria-hidden="true"></i> Sauvegardé' : '<i class="ph ph-circle" aria-hidden="true"></i> En cours' ?>
          </span>
          <button id="btn-<?= $l['id'] ?>"
                  style="display:<?= $saisi ? 'none' : 'inline-block' ?>;background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700"
                  onclick="sauverLigne(<?= $l['id'] ?>)"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer</button>
        </td>

        <!-- Demande de modification -->
        <td style="padding:9px 14px;text-align:left;max-width:270px">
          <?php if ($demande_active && $demande_active['type'] === 'demande_site'): ?>
            <!-- Flux direct : admin/responsable de session -> site, réponse immédiate -->
            <div style="background:#fff8e7;border:1px solid #f0d999;border-radius:8px;padding:8px 10px;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#b7791f"><i class="ph ph-diamond" aria-hidden="true"></i> Demandé par <?= h($demande_active['demandeur_nom'] ?? '—') ?></div>
              <div style="font-size:12px;color:#5a4a1f;margin-top:2px"><?= h($demande_active['motif']) ?></div>
              <?php if ($demande_active['valeur_proposee'] !== null): ?>
              <div style="font-size:12px;color:#5a4a1f;margin-top:2px">Valeur proposée : <strong><?= (int)$demande_active['valeur_proposee'] ?></strong></div>
              <?php endif; ?>
            </div>
            <?php if ($can_edit): ?>
            <button style="background:#fef3e2;color:#b7791f;border:1px solid #f0d999;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700"
                    onclick="repondreModif(<?= (int)$demande_active['id'] ?>,<?= $l['id'] ?>)">↩ Répondre</button>
            <?php endif; ?>

          <?php elseif ($demande_active && $demande_active['type'] === 'demande_autorisation' && $demande_active['statut'] === 'en_attente'): ?>
            <!-- Le coordinateur a demandé l'autorisation de l'admin -->
            <div style="background:#eaf2fb;border:1px solid #90caf9;border-radius:8px;padding:8px 10px;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#1565c0">⏳ Demandé par <?= h($demande_active['demandeur_nom'] ?? '—') ?> — en attente d'autorisation</div>
              <div style="font-size:12px;color:#1a3a5c;margin-top:2px"><?= h($demande_active['motif']) ?></div>
              <?php if ($demande_active['valeur_proposee'] !== null): ?>
              <div style="font-size:12px;color:#1a3a5c;margin-top:2px">Valeur proposée : <strong><?= (int)$demande_active['valeur_proposee'] ?></strong></div>
              <?php endif; ?>
            </div>
            <?php if ($can_demander_site): ?>
            <div style="display:flex;gap:6px">
              <button style="background:#eafaf1;color:#1e8449;border:1px solid #bfe6d0;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700"
                      onclick="autoriserModif(<?= (int)$demande_active['id'] ?>)"><i class="ph ph-check-circle" aria-hidden="true"></i> Autoriser</button>
              <button style="background:#fdf0ef;color:#c0392b;border:1px solid #f6c9c4;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700"
                      onclick="ouvrirModalMotif('refuser_modif',<?= (int)$demande_active['id'] ?>)"><i class="ph ph-x-circle" aria-hidden="true"></i> Refuser</button>
            </div>
            <?php else: ?>
            <span style="font-size:12px;color:#94a3b8">En attente de réponse de l'administrateur</span>
            <?php endif; ?>

          <?php elseif ($demande_active && $demande_active['type'] === 'demande_autorisation' && $demande_active['statut'] === 'autorise'): ?>
            <!-- Autorisation accordée : le site peut maintenant corriger -->
            <div style="background:#eafaf1;border:1px solid #bfe6d0;border-radius:8px;padding:8px 10px;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#1e8449"><i class="ph ph-check-circle" aria-hidden="true"></i> Autorisation accordée</div>
              <div style="font-size:12px;color:#1e5c3a;margin-top:2px">Corrigez la quantité physique puis confirmez.</div>
            </div>
            <?php if ($can_edit): ?>
            <button style="background:#eafaf1;color:#1e8449;border:1px solid #bfe6d0;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700"
                    onclick="repondreModif(<?= (int)$demande_active['id'] ?>,<?= $l['id'] ?>)"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer la correction</button>
            <?php endif; ?>

          <?php elseif ($saisi): ?>
            <?php if ($demande_close && $demande_close['statut'] === 'traite'): ?>
            <div style="font-size:12px;color:#1e8449;margin-bottom:6px">
              <i class="ph ph-check-circle" aria-hidden="true"></i> Modifiée suite à demande (<?= h($demande_close['demandeur_nom'] ?? '—') ?> → <?= (int)$demande_close['valeur_finale'] ?>)
            </div>
            <?php elseif ($demande_close && $demande_close['statut'] === 'refuse'): ?>
            <div style="font-size:12px;color:#c0392b;margin-bottom:6px">
              <i class="ph ph-x-circle" aria-hidden="true"></i> Autorisation refusée<?= $demande_close['reponse'] ? ' : ' . h($demande_close['reponse']) : '' ?>
            </div>
            <?php endif; ?>
            <?php if ($can_demander_site): ?>
            <button style="background:#f8f9fb;color:#5a6480;border:1px solid #e2e8f0;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600"
                    onclick="ouvrirModalMotif('demander_modif',<?= $l['id'] ?>)"><i class="ph ph-repeat" aria-hidden="true"></i> Demander modif au site</button>
            <?php elseif ($is_coord): ?>
            <button style="background:#f8f9fb;color:#5a6480;border:1px solid #e2e8f0;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600"
                    onclick="ouvrirModalMotif('demander_autorisation',<?= $l['id'] ?>)"><i class="ph ph-repeat" aria-hidden="true"></i> Demander une modification</button>
            <?php endif; ?>
          <?php else: ?>
            <span style="font-size:12px;color:#94a3b8">—</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($can_edit || $can_validate): ?>
<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
  <?php if($can_edit): ?>
  <button class="btn btn-secondary" onclick="sauverTout()"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Tout sauver</button>
  <?php endif; ?>
  <?php if($can_validate): ?>
  <button class="btn btn-success" style="font-size:14px;padding:10px 24px" onclick="ouvrirRecapValidation()"><i class="ph ph-check-circle" aria-hidden="true"></i> Valider l'inventaire</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
function ap(data){ return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)}).then(r=>r.json()); }

function toast(msg,type='success'){
  let t=document.getElementById('toast-live');
  if(!t){t=document.createElement('div');t.id='toast-live';t.setAttribute('role','status');t.setAttribute('aria-live','polite');t.setAttribute('aria-atomic','true');document.body.appendChild(t);}
  clearTimeout(t._hideTimer);
  t.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);background:${type==='success'?'#27ae60':'#e74c3c'};color:white;max-width:380px`;
  t.innerHTML=msg; t._hideTimer=setTimeout(()=>{t.style.display='none';},3500);
}

// État de la ligne : rien tant que rien n'est saisi, orange "En cours" tant
// que la valeur diffère de la dernière sauvegardée, vert "Sauvegardé" sinon.
function majEtat(id, val, saved){
  const etatEl = document.getElementById('etat-'+id);
  if(!etatEl) return;
  if(val === ''){ etatEl.style.display='none'; return; }
  if(val === saved){
    etatEl.style.display='inline'; etatEl.style.color='#1e8449'; etatEl.textContent='✅ Sauvegardé';
  } else {
    etatEl.style.display='inline'; etatEl.style.color='#b7791f'; etatEl.textContent='🟠 En cours';
  }
}

// n° 2.3 CR PDG — le coordinateur compte sans voir le stock système. Tout
// retour visuel dérivé de l'écart (classe ok/nok, cellule écart, jours
// restants, couleur de ligne) est neutralisé pour lui : ces cellules ne sont
// même pas rendues, et la coloration trahirait la valeur masquée.
const MASQUE_SYSTEME = <?= $is_coord ? 'true' : 'false' ?>;

function onPhyInput(id, stockSys, consoMoy){
  const inp  = document.getElementById('phy-'+id);
  const val  = inp.value;
  const ecEl = document.getElementById('ecart-'+id);
  const jEl  = document.getElementById('jours-'+id);
  const eEl  = document.getElementById('epuis-'+id);
  const row  = document.getElementById('row-'+id);
  majEtat(id, val, inp.dataset.saved||'');

  if(MASQUE_SYSTEME){
    inp.className = 'saisie';
    row.style.background = val === '' ? '#fffbf0' : 'white';
    updateStats();
    return;
  }

  if(val === ''){
    inp.className='saisie'; ecEl.innerHTML='<span style="color:#94a3b8">—</span>';
    jEl.innerHTML='<span style="color:#94a3b8">—</span>'; eEl.innerHTML='<span style="color:#94a3b8">—</span>';
    row.style.background='#fffbf0'; return;
  }

  const phy   = parseInt(val);
  const ecart = phy - parseInt(stockSys);
  const ecColor = ecart<0?'#e74c3c':ecart>0?'#27ae60':'#27ae60';
  const ecText  = ecart!==0?(ecart>0?'+':'')+ecart+' films':'✅ OK';
  const ecClass = ecart<0?'ecart-neg':ecart>0?'ecart-pos':'ecart-zero';

  inp.className = 'saisie '+(ecart!==0?'nok':'ok');
  ecEl.innerHTML= `<span class="ecart-val ${ecClass}">${ecText}</span>`;
  row.style.background = ecart!==0?'#fff5f5':'#f0fdf4';

  if(consoMoy>0 && phy>=0){
    const jours = phy>0?Math.ceil(phy/consoMoy):0;
    const jColor= jours<30?'#e74c3c':jours<90?'#f39c12':'#27ae60';
    jEl.innerHTML = jours>0?`<span style="color:${jColor};font-weight:700">${jours}j</span>`:'<span style="color:#e74c3c;font-weight:700">Épuisée</span>';
    if(jours>0){
      const d=new Date(); d.setDate(d.getDate()+jours);
      eEl.innerHTML=`<span style="color:${jColor};font-size:12px">${d.toISOString().split('T')[0]}</span>`;
    } else eEl.innerHTML='—';
  }
  updateStats();
}

async function sauverLigne(id){
  const phyEl = document.getElementById('phy-'+id);
  const phy   = phyEl ? phyEl.value : '';
  if(phy===''){toast('Saisissez d\'abord le stock physique.','error');return;}
  const d = await ap({action:'sauver_ligne',detail_id:id,stock_physique:phy,notes:''});
  if(d.success){
    if(phyEl){ phyEl.style.borderColor='#27ae60'; phyEl.dataset.saved = phy; phyEl.disabled = true; }
    majEtat(id, phy, phy);
    const btnEl = document.getElementById('btn-'+id);
    if(btnEl) btnEl.style.display='none';
    setTimeout(()=>{if(phyEl)phyEl.style.borderColor='';},2000);
    toast('Ligne sauvegardée.');
  } else toast('Erreur : '+d.message,'error');
}

// Modal motif partagée : mode = 'demander_modif' (admin -> site) |
// 'demander_autorisation' (coordinateur -> admin) | 'refuser_modif'
let modalMotifMode = null, modalMotifId = null;
function ouvrirModalMotif(mode, id){
  modalMotifMode = mode; modalMotifId = id;
  document.getElementById('modalMotifTexte').value='';
  document.getElementById('modalMotifValeur').value='';
  document.getElementById('modalMotifAlert').innerHTML='';
  const titres = {
    demander_modif: '🔁 Demander une modification au site',
    demander_autorisation: '🔁 Demander une modification',
    refuser_modif: '❌ Refuser la demande',
  };
  document.getElementById('modalMotifTitre').textContent = titres[mode];
  document.getElementById('modalMotifLabel').textContent = mode==='refuser_modif' ? 'Motif du refus (optionnel)' : 'Motif *';
  document.getElementById('modalMotifValeurWrap').style.display = mode==='refuser_modif' ? 'none' : 'block';
  document.getElementById('btnModalMotif').textContent = mode==='demander_modif' ? 'Envoyer au site'
    : mode==='demander_autorisation' ? "Envoyer à l'administrateur" : 'Refuser';
  document.getElementById('modalMotif').style.display='flex';
}
async function envoyerModalMotif(){
  const motif = document.getElementById('modalMotifTexte').value.trim();
  const alertEl = document.getElementById('modalMotifAlert');
  if(modalMotifMode!=='refuser_modif' && !motif){ alertEl.innerHTML='<div style="background:#fdf0ef;color:#c0392b;padding:8px 12px;border-radius:8px;font-size:12.5px;margin-bottom:10px">Le motif est obligatoire.</div>'; return; }
  const btn = document.getElementById('btnModalMotif');
  btn.disabled = true;
  let d;
  if(modalMotifMode==='refuser_modif'){
    d = await ap({action:'refuser_modif', correction_id:modalMotifId, motif});
  } else {
    const valeur = document.getElementById('modalMotifValeur').value;
    d = await ap({action:modalMotifMode, detail_id:modalMotifId, motif, valeur_proposee:valeur});
  }
  btn.disabled = false;
  if(d.success){ toast(d.message); setTimeout(()=>location.reload(), 800); }
  else alertEl.innerHTML = `<div style="background:#fdf0ef;color:#c0392b;padding:8px 12px;border-radius:8px;font-size:12.5px;margin-bottom:10px">${d.message}</div>`;
}
async function autoriserModif(correctionId){
  if(!confirm('Autoriser la modification de cette ligne par le site ?')) return;
  const d = await ap({action:'autoriser_modif', correction_id:correctionId});
  if(d.success){ toast(d.message); setTimeout(()=>location.reload(), 800); } else toast(d.message,'error');
}

let repModifCorrId = null, repModifDetailId = null;
function repondreModif(correctionId, detailId){
  repModifCorrId = correctionId; repModifDetailId = detailId;
  document.getElementById('repModifTexte').value='';
  document.getElementById('repModifAlert').innerHTML='';
  document.getElementById('modalRepondreModif').style.display='flex';
}
async function envoyerReponseModif(){
  const phyEl = document.getElementById('phy-'+repModifDetailId);
  const valeur = phyEl ? phyEl.value : '';
  const alertEl = document.getElementById('repModifAlert');
  if(valeur===''){ alertEl.innerHTML='<div style="background:#fdf0ef;color:#c0392b;padding:8px 12px;border-radius:8px;font-size:12.5px;margin-bottom:10px">Renseignez la qté physique corrigée avant de confirmer.</div>'; return; }
  const reponse = document.getElementById('repModifTexte').value.trim();
  const btn = document.getElementById('btnEnvoyerRepModif');
  btn.disabled = true; btn.textContent = '⏳…';
  const d = await ap({action:'repondre_modif', correction_id:repModifCorrId, valeur_finale:valeur, reponse});
  btn.disabled = false; btn.textContent = 'Confirmer la correction';
  if(d.success){ toast(d.message); setTimeout(()=>location.reload(), 800); }
  else alertEl.innerHTML = `<div style="background:#fdf0ef;color:#c0392b;padding:8px 12px;border-radius:8px;font-size:12.5px;margin-bottom:10px">${d.message}</div>`;
}

async function sauverTout(){
  const btn=document.getElementById('btnSauverTout');
  if(btn){btn.disabled=true;btn.textContent='⏳ Sauvegarde…';}
  const rows=document.querySelectorAll('tr[id^="row-"]');
  const lignes=[];
  rows.forEach(row=>{
    const id=row.id.replace('row-','');
    const phyEl=document.getElementById('phy-'+id);
    if(phyEl&&!phyEl.disabled&&phyEl.value!=='') lignes.push({id,phy:phyEl.value,notes:''});
  });
  if(!lignes.length){toast('Aucune valeur à sauvegarder.','error');if(btn){btn.disabled=false;btn.textContent='💾 Tout sauver';}return;}
  const d=await ap({action:'sauver_tout',lignes:JSON.stringify(lignes)});
  if(btn){btn.disabled=false;btn.textContent='💾 Tout sauver';}
  if(d.success){
    lignes.forEach(l=>{
      const phyEl=document.getElementById('phy-'+l.id);
      if(phyEl){ phyEl.dataset.saved = l.phy; phyEl.disabled = true; }
      majEtat(l.id, l.phy, l.phy);
      const btnEl=document.getElementById('btn-'+l.id);
      if(btnEl) btnEl.style.display='none';
    });
    toast(d.message);
    document.getElementById('alertZone').innerHTML=`<div style="background:#eafaf1;padding:10px 16px;border-radius:8px;font-size:13px;color:#1e8449;margin-bottom:12px"><i class="ph ph-check-circle" aria-hidden="true"></i> ${d.message}</div>`;
  } else toast('Erreur : '+d.message,'error');
}

function ouvrirRecapValidation(){
  const rows = document.querySelectorAll('tr[id^="row-"]');
  let total = rows.length, saisis = 0, nonSaisis = 0;
  const lignesEcart = [];
  rows.forEach(row=>{
    const id     = row.id.replace('row-','');
    const phyEl  = document.getElementById('phy-'+id);
    const connu  = parseInt(row.dataset.connu||'0');
    const numero = row.dataset.numero||'';
    if(!phyEl || phyEl.value===''){
      nonSaisis++;
      if(connu!==0) lignesEcart.push({numero, mesure:null, connu});
      return;
    }
    saisis++;
    const sys    = parseInt(phyEl.dataset.sys||'0');
    const mesure = parseInt(phyEl.value) - sys;
    if(mesure!==0 || connu!==0) lignesEcart.push({numero, mesure, connu});
  });

  document.getElementById('recapStats').innerHTML = `
    <div style="text-align:center;padding:10px;background:var(--tertiary,#f1f5f9);border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:var(--navy)">${total}</div>
      <div style="font-size:10.5px;color:var(--muted)">Bobines</div>
    </div>
    <div style="text-align:center;padding:10px;background:#eafaf1;border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:#1e8449">${saisis}</div>
      <div style="font-size:10.5px;color:#1e8449">Saisies</div>
    </div>
    <div style="text-align:center;padding:10px;background:${lignesEcart.length?'#fdf0ef':'#eafaf1'};border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:${lignesEcart.length?'#c0392b':'#1e8449'}">${lignesEcart.length}</div>
      <div style="font-size:10.5px;color:${lignesEcart.length?'#c0392b':'#1e8449'}">Avec écart</div>
    </div>
    <div style="text-align:center;padding:10px;background:${nonSaisis?'#fff8e7':'var(--tertiary,#f1f5f9)'};border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:${nonSaisis?'#b7791f':'var(--navy)'}">${nonSaisis}</div>
      <div style="font-size:10.5px;color:${nonSaisis?'#b7791f':'var(--muted)'}">Non saisies</div>
    </div>`;

  const nonSaisiEl = document.getElementById('recapNonSaisi');
  if(nonSaisis>0){
    nonSaisiEl.style.display='block';
    nonSaisiEl.innerHTML = `<i class="ph ph-warning" aria-hidden="true"></i> ${nonSaisis} bobine(s) sans stock physique saisi ne seront pas prises en compte dans la validation.`;
  } else nonSaisiEl.style.display='none';

  const recapEl = document.getElementById('recapEcarts');
  if(lignesEcart.length){
    recapEl.innerHTML = `<div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:8px">Lignes avec écart</div>
      <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;max-height:220px;overflow-y:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px">
          <thead><tr style="background:#f1f5f9"><th style="padding:6px 10px;text-align:left">Numéro</th>
            <th style="padding:6px 10px;text-align:right">Écart mesuré</th>
            <th style="padding:6px 10px;text-align:right">Écart connu</th></tr></thead>
          <tbody>${lignesEcart.map(l=>`<tr style="border-top:1px solid var(--border)">
            <td style="padding:6px 10px;font-family:monospace">${l.numero}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:${l.mesure===null?'#94a3b8':(l.mesure<0?'#e74c3c':l.mesure>0?'#27ae60':'#94a3b8')}">${l.mesure===null?'—':(l.mesure>0?'+':'')+l.mesure}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:${l.connu<0?'#e74c3c':l.connu>0?'#27ae60':'#94a3b8'}">${l.connu!==0?(l.connu>0?'+':'')+l.connu:'—'}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>`;
  } else {
    recapEl.innerHTML = `<div style="background:#eafaf1;color:#1e8449;border-radius:8px;padding:10px 14px;font-size:13px"><i class="ph ph-check-circle" aria-hidden="true"></i> Aucun écart, tous les stocks concordent.</div>`;
  }

  document.getElementById('modalRecapValidation').style.display='flex';
}

async function confirmerValidation(){
  const btn = document.getElementById('btnConfirmerValidation');
  btn.disabled = true; btn.textContent = '⏳ Validation…';
  const d = await ap({action:'valider'});
  document.getElementById('modalRecapValidation').style.display='none';
  btn.disabled = false; btn.textContent = "✅ Oui, valider l'inventaire";
  if(d.success){toast(d.message);setTimeout(()=>location.href='inventaire_bobines.php',1500);}
  else{document.getElementById('alertZone').innerHTML=`<div style="background:#fdf0ef;padding:10px 16px;border-radius:8px;font-size:13px;color:#c0392b;margin-bottom:12px"><i class="ph ph-x-circle" aria-hidden="true"></i> ${d.message}</div>`;}
}

function updateStats(){
  const rows=document.querySelectorAll('tr[id^="row-"]');
  let total=rows.length, ok=0, ecarts=0, nonSaisis=0;
  rows.forEach(row=>{
    const id=row.id.replace('row-','');
    const phyEl=document.getElementById('phy-'+id);
    const ecEl=document.getElementById('ecart-'+id);
    if(!phyEl||phyEl.value===''){nonSaisis++;}
    else if(!MASQUE_SYSTEME){
      const phy=parseInt(phyEl.value);
      const sys=parseInt(phyEl.dataset.sys||0);
      if(phy===sys) ok++; else ecarts++;
    }
  });
  const el=n=>document.getElementById(n);
  if(el('statTotal')) el('statTotal').textContent=total;
  if(el('statOk'))    el('statOk').textContent=ok;
  if(el('statEcarts'))el('statEcarts').textContent=ecarts;
  if(el('statNonSaisis'))el('statNonSaisis').textContent=nonSaisis;
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
