<?php
// ============================================================
//  pages/operations/bobines.php  —  Gestion des bobines
//  Stock 500 unités fixe · Consommation tracée · Inventaire
// ============================================================
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/upload.php';

require_auth();
 // Force rechargement depuis DB (évite cache de rôle périmé)

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';

$is_coord = ($role_slug === 'coordinateur_site');
$is_gsb   = in_array($role_slug, ['gestionnaire_stock_bobines','admin','superadmin']);

// Gate pilotée par la permission DB — cf. sql/migration_bobines_permission_
// alignement.sql : cette page n'appelait jamais require_permission() pour
// la lecture, l'accès était géré à 100% par une liste de rôles en dur qui
// ignorait ce que Admin → Permissions affichait pourtant comme réglable.
// gestionnaire_stock reste explicitement exclu (can_read=0 en base), et
// support_it/gestionnaire_bobines — qui listait déjà 'bobines' dans son
// sous-rôle sans jamais pouvoir l'atteindre — accède enfin à cette page
// (trouvé en corrigeant, 2026-08-29).
require_permission('bobines', 'can_read');

$site_force = ($is_coord && ($user['site_id'] ?? 0)) ? (int)$user['site_id'] : 0;

// Bobine (plaques : Auto/Carré/Moto/MotoII) vs Vignette (Réservoir/Pare-brise) —
// meme page, meme fonctionnement que pages/equipements.php (?categorie=), pour
// ne pas dupliquer toute la logique (KPI, onglets, creation, import...).
$f_categorie_bob = (($_GET['categorie'] ?? 'bobine') === 'vignette') ? 'vignette' : 'bobine';
$series_actives  = $f_categorie_bob === 'vignette' ? ['TL','WSL'] : ['A','B','C','D'];
$serie_ph        = implode(',', array_fill(0, count($series_actives), '?'));

if ($f_categorie_bob === 'vignette') {
    $page_title       = $is_coord ? 'Mes Vignettes' : 'Gestion des Vignettes';
    $bob_label        = 'vignette';
    $bob_label_pl     = 'vignettes';
    $formats_bobines  = ['Réservoir','Pare-brise'];
    $versions_bobines = ['Privée','Transport Publique'];
} else {
    $page_title       = $is_coord ? 'Mes Bobines' : 'Gestion des Bobines';
    $bob_label        = 'bobine';
    $bob_label_pl     = 'bobines';
    $formats_bobines  = ['Auto','Carré','Moto','MotoII'];
    $versions_bobines = ['Privée','Transport Publique','Institution Internationale','Diplomatique','Gouvernementale','Temporaire'];
}
$active_page = $f_categorie_bob === 'vignette' ? 'bobines_vignette' : 'bobines';

$types_v    = db_fetch_all("SELECT * FROM op_types_vehicule ORDER BY ordre");
$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

// Catalogue reel (op_types_bobines) plutot qu'une liste codee en dur : couvre
// aussi bien A001-D006 (6 versions chacun) que TL001-TL002/WSL001-WSL002
// (2 versions seulement) sans supposer un nombre fixe de variantes.
$types_bobines = array_column(
    db_fetch_all("SELECT code FROM op_types_bobines WHERE actif=1 AND TRIM(serie) IN ($serie_ph) ORDER BY code", $series_actives),
    'code'
);

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD']==='POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── CRÉER BOBINE
    if ($action==='create') {
        require_permission('bobines','can_create');
        $numero    = strtoupper(trim($_POST['numero']    ?? ''));
        $type_code = strtoupper(trim($_POST['type_code'] ?? ''));
        $format    = trim($_POST['format'] ?? '');
        $site_id   = (int)($_POST['site_id'] ?? 0) ?: null;
        if (!$numero || !$type_code) json_response(false,'Numéro et type obligatoires.');
        if (db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE numero=?",[$numero])>0)
            json_response(false,"La bobine $numero existe déjà.");
        // Série lue depuis le catalogue (pas les premiers caractères du code) :
        // les préfixes TL/WSL (Vignette) font plus d'un caractère.
        $serie = trim((string)db_fetch_value("SELECT serie FROM op_types_bobines WHERE code=? AND actif=1", [$type_code]));
        if (!$serie) json_response(false,"Type '$type_code' inconnu au catalogue.");
        if (!in_array($serie, $series_actives, true)) json_response(false,"Ce type n'appartient pas à la catégorie « $bob_label_pl ».");
        $tv = db_fetch_one("SELECT id FROM op_types_vehicule WHERE serie_bobine=?",[$serie]);
        db_begin();
        try {
            db_query("INSERT INTO op_bobines (numero,type_code,serie,type_vehicule_id,site_id,format,qte_initiale,stock_systeme,statut) VALUES (?,?,?,?,?,?,500,500,'en_stock')",
                [$numero,$type_code,$serie,$tv['id']??null,$site_id,$format]);
            $id = (int)db_last_id();
            db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by) VALUES (?,?,?,?,?,?,?)",
                [$id,'entree',500,0,500,"Création bobine $numero",$user['id']]);
            audit_log($user['id'],'CREATE','operations',$id,"Création bobine $numero ($type_code) — stock initial: 500");
            db_commit();
            json_response(true,'Bobine créée avec stock initial de 500.',['id'=>$id]);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── AFFECTER AU SITE
    if ($action==='affecter_site') {
        require_permission('bobines','can_update');
        $id      = (int)($_POST['id']      ?? 0);
        $site_id = (int)($_POST['site_id'] ?? 0);
        $bob = db_fetch_one("SELECT * FROM op_bobines WHERE id=?",[$id]);
        if (!$bob) json_response(false,'Bobine introuvable.');
        db_query("UPDATE op_bobines SET site_id=?,statut='en_cours' WHERE id=?",[$site_id,$id]);
        db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by) VALUES (?,?,?,?,?,?,?)",
            [$id,'transfert',0,$bob['stock_systeme'],$bob['stock_systeme'],"Affectation site_id:$site_id",$user['id']]);
        audit_log($user['id'],'UPDATE','operations',$id,"Affectation bobine {$bob['numero']} -> site:$site_id");
        json_response(true,'Bobine affectée au site.');
    }

    // ── SAISIE CONSOMMATION JOURNALIÈRE
    if ($action==='consommation') {
        require_permission('bobines','can_create');
        $bobine_id = (int)($_POST['bobine_id'] ?? 0);
        $qte       = (int)($_POST['quantite']  ?? 0);
        $date_conso= trim($_POST['date_conso'] ?? date('Y-m-d'));
        $notes     = trim($_POST['notes']      ?? '');
        if (!$bobine_id || $qte <= 0) json_response(false,'Bobine et quantité (>0) obligatoires.');
        $bob = db_fetch_one("SELECT b.*,s.nom AS site_nom FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id WHERE b.id=?",[$bobine_id]);
        if (!$bob) json_response(false,'Bobine introuvable.');
        if ($is_coord && $bob['site_id'] != $site_force) json_response(false,"Cette bobine n'est pas sur votre site.");
        $stock_avant = (int)$bob['stock_systeme'];
        if ($qte > $stock_avant) json_response(false,"Stock insuffisant. Stock actuel : $stock_avant films.");
        $stock_apres = $stock_avant - $qte;
        $nouveau_statut = $stock_apres === 0 ? 'epuisee' : 'en_cours';
        db_begin();
        try {
            db_query("INSERT INTO consommations_bobines (bobine_id,site_id,date_conso,quantite,stock_avant,stock_apres,notes,created_by) VALUES (?,?,?,?,?,?,?,?)",
                [$bobine_id,$bob['site_id'],$date_conso,$qte,$stock_avant,$stock_apres,$notes,$user['id']]);
            $conso_id = (int)db_last_id();
            db_query("UPDATE op_bobines SET stock_systeme=?,statut=?,films_restants=? WHERE id=?",[$stock_apres,$nouveau_statut,$stock_apres,$bobine_id]);
            db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,ref_id,created_by) VALUES (?,?,?,?,?,?,?,?)",
                [$bobine_id,'sortie',-$qte,$stock_avant,$stock_apres,"Consommation du $date_conso",$conso_id,$user['id']]);
            audit_log($user['id'],'UPDATE','operations',$bobine_id,"Consommation {$bob['numero']}: -$qte (stock: $stock_avant -> $stock_apres)");
            db_commit();
            $msg = "Consommation enregistrée. Stock restant : <strong>$stock_apres</strong> films.";
            if ($stock_apres === 0) $msg .= " Bobine épuisée !";
            json_response(true, $msg, ['stock_apres'=>$stock_apres,'statut'=>$nouveau_statut]);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── RETIRER BOBINE
    if ($action==='retirer') {
        require_permission('bobines','can_update');
        $id = (int)($_POST['id'] ?? 0);
        $bob = db_fetch_one("SELECT * FROM op_bobines WHERE id=?",[$id]);
        db_query("UPDATE op_bobines SET statut='retiree' WHERE id=?",[$id]);
        audit_log($user['id'],'DELETE','operations',$id,"Retrait bobine {$bob['numero']}");
        json_response(true,'Bobine retirée.');
    }

    // ── CHANGER STATUT (en_cours ↔ en_stock)
    if ($action==='changer_statut') {
        require_permission('bobines','can_update');
        $id      = (int)($_POST['id'] ?? 0);
        $statut  = trim($_POST['statut'] ?? '');
        $motif   = trim($_POST['motif'] ?? '');
        $allowed = ['en_stock','en_cours'];
        if (!in_array($statut, $allowed)) json_response(false,'Statut invalide.');
        $bob = db_fetch_one("SELECT * FROM op_bobines WHERE id=?",[$id]);
        if (!$bob) json_response(false,'Bobine introuvable.');
        // En utilisation → En stock : libre avec motif
        // En stock → En utilisation : réservé aux non-coordinateurs (coordinateurs doivent demander)
        if ($statut==='en_cours' && ($role_slug==='coordinateur_site')) {
            json_response(false,'Veuillez faire une demande d\'utilisation pour cette bobine.');
        }
        db_query("UPDATE op_bobines SET statut=? WHERE id=?",[$statut,$id]);
        audit_log($user['id'],'UPDATE','operations',$id,"Changement statut bobine {$bob['numero']} → $statut".($motif?" | $motif":''));
        json_response(true,'Statut mis à jour.',['statut'=>$statut]);
    }

    // ── DÉCLARER PERDUE
    if ($action==='declarer_perdue') {
        require_permission('bobines','can_update');
        $id    = (int)($_POST['id'] ?? 0);
        $motif = trim($_POST['motif'] ?? '');
        if (!$motif) json_response(false,'Le motif est obligatoire pour déclarer une perte.');
        $bob = db_fetch_one("SELECT * FROM op_bobines WHERE id=?",[$id]);
        if (!$bob) json_response(false,'Bobine introuvable.');
        if (!in_array($bob['statut'],['en_cours','en_stock'])) json_response(false,'Cette bobine ne peut pas être déclarée perdue.');
        db_query("UPDATE op_bobines SET statut='perdue', notes_perte=? WHERE id=?",[$motif,$id]);
        // Tracer mouvement de perte
        db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                  VALUES (?,?,?,?,0,?,?)",
            [$id,'perte',(int)$bob['films_restants'],(int)$bob['films_restants'],"PERTE : $motif",$user['id']]);
        audit_log($user['id'],'UPDATE','operations',$id,"Bobine {$bob['numero']} déclarée PERDUE — $motif");
        notify_role('gestionnaire_stock_bobines',"Bobine perdue","La bobine {$bob['numero']} a été déclarée perdue par {$user['prenom']} {$user['nom']}. Motif : $motif");
        json_response(true,'Bobine déclarée perdue.',['films_perdus'=>$bob['films_restants']]);
    }

    // ── DEMANDER UTILISATION (coordinateur → GSB)
    if ($action==='demander_utilisation') {
        if (!$is_coord) json_response(false,'Réservé au coordinateur de site.');
        $id    = (int)($_POST['id'] ?? 0);
        $motif = trim($_POST['motif'] ?? '');
        if (!$motif) json_response(false,'Le motif est obligatoire pour une demande d\'utilisation.');
        $bob = db_fetch_one("SELECT b.*, s.nom AS site_nom FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id WHERE b.id=?",[$id]);
        if (!$bob) json_response(false,'Bobine introuvable.');
        if ($bob['statut'] !== 'en_stock') json_response(false,'Cette bobine n\'est pas en stock.');
        // Vérifier pas déjà une demande en attente
        $exist = db_fetch_one("SELECT id FROM demandes_bobines WHERE bobine_id=? AND statut='en_attente'",[$id]);
        if ($exist) json_response(false,'Une demande est déjà en attente pour cette bobine.');
        db_query(
            "INSERT INTO demandes_bobines (bobine_id,site_id,demande_par,motif) VALUES (?,?,?,?)",
            [$id,$bob['site_id'],$user['id'],$motif]
        );
        $dem_id = (int)db_last_id();
        // Notifier les GSB
        $gsb_users = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug IN ('gestionnaire_stock_bobines','gestionnaire_stock') AND u.actif=1");
        foreach($gsb_users as $gsb) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$gsb['id'],'info','🎞️ Demande utilisation bobine',
                 "{$user['prenom']} {$user['nom']} demande l'utilisation de la bobine {$bob['numero']} ({$bob['site_nom']}). Motif : $motif",
                 'bobines.php']);
        }
        audit_log($user['id'],'CREATE','demandes_bobines',$dem_id,"Demande utilisation bobine {$bob['numero']} — $motif");
        json_response(true,'Demande envoyée. En attente de validation du gestionnaire stock bobines.');
    }

    // ── TRAITER DEMANDE (GSB → approuver/refuser)
    if ($action==='traiter_demande') {
        $gsb_roles = ['gestionnaire_stock_bobines','gestionnaire_stock','superviseur_operation','admin','superadmin'];
        if (!in_array($role_slug,$gsb_roles)) json_response(false,'Accès refusé.');
        $dem_id    = (int)($_POST['dem_id'] ?? 0);
        $decision  = trim($_POST['decision'] ?? '');
        $motif_rep = trim($_POST['motif_reponse'] ?? '');
        if (!in_array($decision,['approuvee','refusee'])) json_response(false,'Décision invalide.');
        if (!$motif_rep) json_response(false,'Le motif de réponse est obligatoire.');
        $dem = db_fetch_one("SELECT d.*, b.numero AS bobine_num, b.site_id FROM demandes_bobines d JOIN op_bobines b ON b.id=d.bobine_id WHERE d.id=? AND d.statut='en_attente'",[$dem_id]);
        if (!$dem) json_response(false,'Demande introuvable ou déjà traitée.');
        db_begin();
        try {
            db_query("UPDATE demandes_bobines SET statut=?,traite_par=?,traite_at=NOW(),motif_reponse=? WHERE id=?",
                [$decision,$user['id'],$motif_rep,$dem_id]);
            if ($decision==='approuvee') {
                db_query("UPDATE op_bobines SET statut='en_cours' WHERE id=?",[$dem['bobine_id']]);
            }
            // Notifier le coordinateur
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$dem['demande_par'],'info',
                 $decision==='approuvee'?'🎞️ Demande bobine approuvée':'🎞️ Demande bobine refusée',
                 "Votre demande pour la bobine {$dem['bobine_num']} a été ".($decision==='approuvee'?'approuvée ✅':'refusée ❌').". Motif : $motif_rep",
                 'bobines.php']);
            audit_log($user['id'],'UPDATE','demandes_bobines',$dem_id,"$decision bobine {$dem['bobine_num']} — $motif_rep");
            db_commit();
            json_response(true,$decision==='approuvee'?'Demande approuvée. Bobine mise en utilisation.':'Demande refusée.');
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }


    if ($action==='import_lot') {
        require_permission('bobines','can_create');
        $items   = json_decode($_POST['items']??'[]',true);
        $site_id = (int)($_POST['site_id']??0)?:null;
        $created = 0; $errors = [];
        db_begin();
        try {
            foreach($items as $item){
                $num=$item['numero']??''; $type=strtoupper($item['type_code']??''); $format=$item['format']??'';
                if(!$num||!$type){$errors[]="Ligne invalide";continue;}
                if(db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE numero=?",[$num])>0){$errors[]="$num deja existante";continue;}
                $serie = trim((string)db_fetch_value("SELECT serie FROM op_types_bobines WHERE code=? AND actif=1", [$type]));
                if (!$serie) {$errors[]="$num : type '$type' inconnu";continue;}
                if (!in_array($serie, $series_actives, true)) {$errors[]="$num : type '$type' hors categorie $bob_label_pl";continue;}
                $tv=db_fetch_one("SELECT id FROM op_types_vehicule WHERE serie_bobine=?",[$serie]);
                db_query("INSERT INTO op_bobines (numero,type_code,serie,type_vehicule_id,site_id,format,qte_initiale,stock_systeme,statut) VALUES (?,?,?,?,?,?,500,500,'en_stock')",
                    [strtoupper($num),strtoupper($type),$serie,$tv['id']??null,$site_id,$format]);
                $new_id=(int)db_last_id();
                db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by) VALUES (?,?,?,?,?,?,?)",
                    [$new_id,'entree',500,0,500,"Import lot - bobine $num",$user['id']]);
                $created++;
            }
            db_commit();
            json_response(true,"$created bobine(s) importee(s)".($errors?". Erreurs: ".implode(', ',$errors):''));
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── DÉTAIL BOBINE
    if ($action==='get_detail') {
        $id = (int)($_POST['id'] ?? 0);
        $bob = db_fetch_one("SELECT b.*,s.nom AS site_nom,tv.libelle AS type_vehicule,t.format AS tb_format,t.version AS tb_version FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id LEFT JOIN op_types_vehicule tv ON tv.id=b.type_vehicule_id LEFT JOIN op_types_bobines t ON t.code=b.type_code WHERE b.id=?",[$id]);
        if (!$bob) json_response(false,'Introuvable.');
        $conso_moy = (float)db_fetch_value("SELECT COALESCE(SUM(quantite)/GREATEST(((NOW())::date - (MIN(date_conso)::date)),1),0) FROM consommations_bobines WHERE bobine_id=? AND date_conso>=(CURRENT_DATE - INTERVAL '30 DAY')",[$id]);
        $jours_restants = $conso_moy > 0 ? (int)ceil($bob['stock_systeme']/$conso_moy) : null;
        $date_epuisement = $jours_restants ? date('Y-m-d',strtotime("+{$jours_restants} days")) : null;
        $consos = db_fetch_all("SELECT date_conso,quantite,stock_avant,stock_apres,notes,CONCAT(u.prenom,' ',u.nom) AS agent FROM consommations_bobines cb LEFT JOIN users u ON u.id=cb.created_by WHERE cb.bobine_id=? ORDER BY date_conso DESC LIMIT 30",[$id]);
        // Écarts ouverts sur cette bobine
        $ecarts_ouverts = db_fetch_all("SELECT * FROM ecarts_bobines WHERE bobine_id=? AND statut='ouvert' ORDER BY date_constat DESC",[$id]);
        json_response(true,'',['bob'=>$bob,'conso_moy'=>round($conso_moy,1),'jours_restants'=>$jours_restants,'date_epuisement'=>$date_epuisement,'consos'=>$consos,'ecarts'=>$ecarts_ouverts]);
    }

    // ── DÉCLARER UN ÉCART MANUEL (écart connu hors application)
    if ($action==='declarer_ecart') {
        $bobine_id     = (int)($_POST['bobine_id']     ?? 0);
        $stock_physique= (int)($_POST['stock_physique'] ?? 0);
        $date_constat  = trim($_POST['date_constat']   ?? date('Y-m-d'));
        $motif         = trim($_POST['motif']           ?? '');
        if (!$bobine_id) json_response(false,'Bobine obligatoire.');
        if (!$motif)     json_response(false,'Le motif est obligatoire.');
        $bob = db_fetch_one("SELECT * FROM op_bobines WHERE id=?",[$bobine_id]);
        if (!$bob) json_response(false,'Bobine introuvable.');
        $ecart = $stock_physique - (int)$bob['stock_systeme'];
        // Vérifier qu'il y a bien un écart
        if ($ecart === 0) json_response(false,'Aucun écart : le stock physique est identique au stock système ('.$bob['stock_systeme'].' films).');
        db_query(
            "INSERT INTO ecarts_bobines (bobine_id,date_constat,stock_systeme,stock_physique,ecart,motif,source,statut,created_by)
             VALUES (?,?,?,?,?,?,'manuel','ouvert',?)",
            [$bobine_id,$date_constat,(int)$bob['stock_systeme'],$stock_physique,$ecart,$motif,$user['id']]
        );
        $ecart_id = (int)db_last_id();
        audit_log($user['id'],'CREATE','operations',$bobine_id,"Écart déclaré bobine {$bob['numero']} : $ecart films (physique:$stock_physique vs système:{$bob['stock_systeme']})");
        json_response(true,'Écart enregistré.',['id'=>$ecart_id,'ecart'=>$ecart]);
    }

    // ── RÉSOUDRE UN ÉCART
    if ($action==='resoudre_ecart') {
        $ecart_id = (int)($_POST['ecart_id'] ?? 0);
        $notes    = trim($_POST['notes']     ?? '');
        $ajuster  = !empty($_POST['ajuster_stock']); // Ajuster le stock système selon le physique
        if (!$ecart_id) json_response(false,'ID écart manquant.');
        $ec = db_fetch_one("SELECT e.*,b.numero,b.stock_systeme FROM ecarts_bobines e JOIN op_bobines b ON b.id=e.bobine_id WHERE e.id=?",[$ecart_id]);
        if (!$ec) json_response(false,'Écart introuvable.');
        db_begin();
        try {
            db_query("UPDATE ecarts_bobines SET statut='resolu',resolu_at=NOW(),resolu_par=?,resolution_notes=? WHERE id=?",
                [$user['id'],$notes,$ecart_id]);
            if ($ajuster) {
                $phy = (int)$ec['stock_physique'];
                db_query("UPDATE op_bobines SET stock_systeme=?,films_restants=?,statut=CASE WHEN ?>0 THEN 'en_cours' ELSE 'epuisee' END WHERE id=?",
                    [$phy,$phy,$phy,$ec['bobine_id']]);
                db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by) VALUES (?,?,?,?,?,?,?)",
                    [$ec['bobine_id'],'ajustement_inventaire',$ec['ecart'],(int)$ec['stock_systeme'],$phy,"Résolution écart #$ecart_id",$user['id']]);
            }
            audit_log($user['id'],'UPDATE','operations',$ec['bobine_id'],"Écart résolu bobine {$ec['numero']} #$ecart_id".($ajuster?' + ajustement stock':''));
            db_commit();
            json_response(true,'Écart résolu'.($ajuster?' et stock ajusté.':'.'));
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    // ── LISTE DES ÉCARTS OUVERTS (pour la section suivi)
    if ($action==='get_ecarts') {
        $f_site = $site_force ?: (int)($_POST['site_id'] ?? 0);
        $where  = ["eb.statut='ouvert'", "b.serie IN ($serie_ph)"]; $params = [...$series_actives];
        if ($f_site) { $where[] = 'b.site_id=?'; $params[] = $f_site; }
        $ecarts = db_fetch_all(
            "SELECT eb.*, b.numero, b.type_code, b.stock_systeme AS stock_sys_actuel,
                    s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS declarant
             FROM ecarts_bobines eb
             JOIN op_bobines b ON b.id=eb.bobine_id
             LEFT JOIN sites s ON s.id=b.site_id
             LEFT JOIN users u ON u.id=eb.created_by
             WHERE ".implode(' AND ',$where)."
             ORDER BY s.nom ASC, eb.date_constat DESC LIMIT 100",
            $params
        );
        json_response(true,'',['ecarts'=>$ecarts]);
    }

    json_response(false,'Action inconnue.');
}

// ============================================================
//  LISTE
// ============================================================
$f_site    = $site_force ?: (int)($_GET['site']    ?? 0);
$f_statut  = trim($_GET['statut']  ?? '');
$f_serie   = trim($_GET['serie']   ?? '');
$f_type    = trim($_GET['type']    ?? '');
$f_format  = trim($_GET['format']  ?? '');
$f_version = trim($_GET['version'] ?? '');
$f_search  = trim($_GET['q']       ?? '');
$where=['b.serie IN ('.$serie_ph.')']; $params=[...$series_actives];
if($f_site)    {$where[]='b.site_id=?';    $params[]=$f_site;}
if($f_statut)  {$where[]='b.statut=?';     $params[]=$f_statut;}
if($f_serie)   {$where[]='b.serie=?';      $params[]=$f_serie;}
if($f_type)    {$where[]='b.type_code=?';  $params[]=$f_type;}
if($f_format)  {$where[]='t.format=?';     $params[]=$f_format;}
if($f_version) {$where[]='t.version=?';    $params[]=$f_version;}
if($f_search)  {$where[]='b.numero ILIKE ?'; $params[]="%$f_search%";}
$wsql = 'WHERE '.implode(' AND ',$where);

$bobines = db_fetch_all(
    "SELECT b.*, s.nom AS site_nom, tv.libelle AS type_vehicule,
            t.format AS tb_format, t.version AS tb_version,
            -- Quantité réelle au moment de la dernière affectation (transfert)
            COALESCE(
                (SELECT m.stock_avant FROM mouvements_bobines m
                 WHERE m.bobine_id=b.id AND m.type='transfert'
                 ORDER BY m.created_at DESC LIMIT 1),
                b.qte_initiale
            ) AS qte_livree
     FROM op_bobines b
     LEFT JOIN sites s ON s.id=b.site_id
     LEFT JOIN op_types_vehicule tv ON tv.id=b.type_vehicule_id
     LEFT JOIN op_types_bobines t ON t.code=b.type_code
     $wsql ORDER BY b.serie ASC,b.type_code ASC,b.statut ASC,b.numero ASC",
    $params
);

$stats = db_fetch_all("SELECT statut,COUNT(*) AS n,COALESCE(SUM(stock_systeme),0) AS stock_total FROM op_bobines WHERE serie IN ($serie_ph) GROUP BY statut", $series_actives);
$stats_map = array_column($stats,null,'statut');
$total_stock = array_sum(array_column($stats,'stock_total'));

$conso_today_where = $site_force ? "AND cb.site_id=$site_force" : '';
$conso_today = (int)db_fetch_value("SELECT COALESCE(SUM(cb.quantite),0) FROM consommations_bobines cb JOIN op_bobines b ON b.id=cb.bobine_id WHERE b.serie IN ($serie_ph) AND cb.date_conso=CURRENT_DATE $conso_today_where", $series_actives);
$conso_mois  = (int)db_fetch_value("SELECT COALESCE(SUM(cb.quantite),0) FROM consommations_bobines cb JOIN op_bobines b ON b.id=cb.bobine_id WHERE b.serie IN ($serie_ph) AND EXTRACT(YEAR FROM cb.date_conso)=EXTRACT(YEAR FROM CURRENT_DATE) AND EXTRACT(MONTH FROM cb.date_conso)=EXTRACT(MONTH FROM CURRENT_DATE) $conso_today_where", $series_actives);

// Écarts ouverts
$ecart_site_where = $site_force ? "AND b.site_id=$site_force" : '';
$nb_ecarts_ouverts = (int)db_fetch_value("SELECT COUNT(*) FROM ecarts_bobines eb JOIN op_bobines b ON b.id=eb.bobine_id WHERE eb.statut='ouvert' AND b.serie IN ($serie_ph) $ecart_site_where", $series_actives);
$nb_ecarts_negatifs = (int)db_fetch_value("SELECT COUNT(*) FROM ecarts_bobines eb JOIN op_bobines b ON b.id=eb.bobine_id WHERE eb.statut='ouvert' AND eb.ecart<0 AND b.serie IN ($serie_ph) $ecart_site_where", $series_actives);

include __DIR__ . '/../../templates/header.php';

// ── VUE COORDINATEUR : simple liste lecture seule
if ($is_coord):
?>
<style>
.coord-bob-table td,.coord-bob-table th{padding:11px 14px;font-size:13px}
.coord-bob-table th{background:var(--navy);color:white;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.coord-bob-table tr:nth-child(even) td{background:#f8fafc}
.bstat{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.bstat-en_cours{background:#DBEAFE;color:#1D4ED8}
.bstat-en_stock{background:#D1FAE5;color:#065F46}
.bstat-epuisee{background:#FEE2E2;color:#991B1B}
.bstat-retiree{background:#F1F5F9;color:#64748B}
.bstat-perdue{background:#FEF3C7;color:#92400E}
</style>

<?php
// KPIs site coordinateur
$nb_actives = count(array_filter($bobines, fn($b) => in_array($b['statut'], ['en_cours','en_stock'])));
$nb_epuisees = count(array_filter($bobines, fn($b) => $b['statut'] === 'epuisee'));
$total_films = array_sum(array_column(array_filter($bobines, fn($b) => in_array($b['statut'],['en_cours','en_stock'])), 'films_restants'));
?>

<!-- KPIs coordinateur -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px">
  <div style="background:white;border:1.5px solid var(--border);border-radius:14px;padding:16px 20px;border-top:3px solid #1B75BC">
    <div style="font-size:26px;font-weight:900;color:#06033A;font-family:'Plus Jakarta Sans',sans-serif"><?= $nb_actives ?></div>
    <div style="font-size:12px;color:var(--muted);font-weight:600;margin-top:3px">Bobines actives sur votre site</div>
  </div>
  <div style="background:white;border:1.5px solid var(--border);border-radius:14px;padding:16px 20px;border-top:3px solid #34D399">
    <div style="font-size:26px;font-weight:900;color:#065F46;font-family:'Plus Jakarta Sans',sans-serif"><?= fmt_number($total_films) ?></div>
    <div style="font-size:12px;color:var(--muted);font-weight:600;margin-top:3px">Films restants (total)</div>
  </div>
  <div style="background:white;border:1.5px solid var(--border);border-radius:14px;padding:16px 20px;border-top:3px solid <?= $nb_epuisees>0?'#F87171':'#E2E8F0' ?>">
    <div style="font-size:26px;font-weight:900;color:<?= $nb_epuisees>0?'#991B1B':'var(--muted)' ?>;font-family:'Plus Jakarta Sans',sans-serif"><?= $nb_epuisees ?></div>
    <div style="font-size:12px;color:var(--muted);font-weight:600;margin-top:3px">Bobines épuisées</div>
  </div>
</div>

<?php
$coord_types = db_fetch_all("SELECT DISTINCT type_code FROM op_bobines WHERE site_id=? AND type_code IS NOT NULL AND serie IN ($serie_ph) ORDER BY type_code", [$site_force, ...$series_actives]);
?>
<!-- Filtres coordinateur -->
<form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
  <select name="statut" onchange="this.form.submit()"
    style="padding:8px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none">
    <option value="">Tous statuts</option>
    <option value="en_cours" <?= $f_statut==='en_cours'?'selected':'' ?>>▶️ En utilisation</option>
    <option value="en_stock" <?= $f_statut==='en_stock'?'selected':'' ?>>📦 En stock</option>
    <option value="epuisee"  <?= $f_statut==='epuisee'?'selected':'' ?>>❌ Épuisées</option>
    <option value="retiree"  <?= $f_statut==='retiree'?'selected':'' ?>>🔄 Retirées</option>
  </select>

  <select name="type" onchange="this.form.submit()"
    style="padding:8px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none">
    <option value="">Tous les types</option>
    <?php foreach($coord_types as $t): ?>
    <option value="<?= h($t['type_code']) ?>" <?= $f_type===$t['type_code']?'selected':'' ?>>
      <?= h($t['type_code']) ?>
    </option>
    <?php endforeach; ?>
  </select>

  <div style="position:relative;flex:1;min-width:160px">
    <i class="ph-duotone ph-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none"></i>
    <input type="text" name="q" value="<?= h($f_search) ?>" aria-label="Rechercher un numéro de bobine"
           placeholder="N° bobine…" onchange="this.form.submit()"
           style="width:100%;padding:8px 12px 8px 32px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none">
  </div>

  <?php if($f_statut||$f_type||$f_search): ?>
  <a href="bobines.php" class="btn btn-secondary btn-sm"><i class="ph ph-x" aria-hidden="true"></i> Effacer</a>
  <?php endif; ?>
</form>

<!-- Tableau bobines du site -->
<div class="card">
  <div class="card-header">
    <h3><i class="ph-duotone ph-film-strip" style="color:var(--primary-d)"></i> Bobines de votre site</h3>
    <span style="font-size:12px;color:var(--muted)"><?= count($bobines) ?> bobine(s)</span>
  </div>
  <?php if(empty($bobines)): ?>
  <div class="card-body" style="text-align:center;padding:40px;color:var(--muted)">
    <i class="ph-duotone ph-film-strip" style="font-size:40px;color:var(--border)"></i>
    <p style="margin-top:12px">Aucune bobine enregistrée pour votre site.</p>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="coord-bob-table" style="width:100%;border-collapse:collapse">
      <thead><tr>
        <th>N° Bobine</th>
        <th>Type</th>
        <th style="text-align:center">Qté livrée</th>
        <th style="text-align:center">Films restants</th>
        <th style="text-align:center">Consommé</th>
        <th style="text-align:center">Statut</th>
        <th style="text-align:center">Action</th>
      </tr></thead>
      <tbody>
      <?php foreach($bobines as $b):
        $qte_init  = (int)($b['qte_livree'] ?? $b['qte_initiale'] ?? 500);
        $restants  = (int)$b['films_restants'];
        $consomme  = max(0, $qte_init - $restants);
        $pct_conso = $qte_init > 0 ? round($consomme / $qte_init * 100) : 0;
        $pct_rest  = $qte_init > 0 ? round($restants  / $qte_init * 100) : 0;
        $bar_color = $pct_rest > 50 ? '#34D399' : ($pct_rest > 20 ? '#FBBF24' : '#F87171');
      ?>
      <tr>
        <td style="font-family:monospace;font-weight:800;color:var(--navy)"><?= h($b['numero']) ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= h($b['type_code']) ?></td>
        <td style="text-align:center;font-weight:700;color:var(--navy)"><?= fmt_number($qte_init) ?></td>
        <td style="text-align:center">
          <div style="font-weight:800;font-size:15px;color:<?= $pct_rest<=20?'#991B1B':($pct_rest<=50?'#92400E':'var(--navy)') ?>;font-family:'Plus Jakarta Sans',sans-serif">
            <?= fmt_number($restants) ?>
          </div>
          <div style="height:4px;background:var(--border);border-radius:2px;overflow:hidden;margin-top:4px;width:80px;margin:4px auto 0">
            <div style="width:<?= $pct_rest ?>%;height:100%;background:<?= $bar_color ?>;border-radius:2px"></div>
          </div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= $pct_rest ?>%</div>
        </td>
        <td style="text-align:center">
          <span style="font-weight:700;color:#1D4ED8"><?= fmt_number($consomme) ?></span>
          <?php if($pct_conso > 0): ?>
          <div style="font-size:12px;color:var(--muted)"><?= $pct_conso ?>%</div>
          <?php endif; ?>
        </td>
        <td style="text-align:center">
          <span class="bstat bstat-<?= $b['statut'] ?>">
            <?= ['en_cours'=>'▶️ En utilisation','en_stock'=>'<i class="ph ph-package" aria-hidden="true"></i> En stock','epuisee'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Épuisée','retiree'=>'<i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Retirée','perdue'=>'<i class="ph ph-warning" aria-hidden="true"></i> Perdue'][$b['statut']] ?? h($b['statut']) ?>
          </span>
        </td>
        <td style="text-align:center">
          <?php if($b['statut'] === 'en_stock'): ?>
          <button class="btn btn-primary btn-sm" onclick="demanderUtilisation(<?= $b['id'] ?>, '<?= h($b['numero']) ?>')">
            ▶️ Demander utilisation
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
function demanderUtilisation(id, numero) {
  const motif = prompt('Demande d\'utilisation de la bobine ' + numero + '.\nMotif de la demande :');
  if (motif === null) return;
  fetch('', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: 'action=demander_utilisation&id=' + id + '&motif=' + encodeURIComponent(motif)})
    .then(r => r.json())
    .then(d => {
      if (d.success) { alert('✅ ' + d.message); location.reload(); }
      else alert('❌ ' + d.message);
    });
}
</script>
<?php
include __DIR__ . '/../../templates/footer.php';
exit;
endif;
// ── FIN VUE COORDINATEUR — suite : vue GSB/Admin
?>
<style>
.bobine-status{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.bobine-status.en_stock{background:#e8f5e9;color:#2e7d32}
.bobine-status.en_cours{background:#e3f2fd;color:#1565c0}
.bobine-status.epuisee{background:#fce4ec;color:#b71c1c}
.bobine-status.retiree{background:#f5f5f5;color:#9e9e9e}
.bobine-status.perdue{background:#fff3e0;color:#e65100}
.bobine-status.terminee{background:#e8f5e9;color:#2e7d32}
.bob-kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.bk{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px}
.bk-icon{font-size:24px;flex-shrink:0}
.bk-val{font-family:'Montserrat',sans-serif;font-size:24px;font-weight:800;color:var(--navy);line-height:1}
.bk-label{font-size:11.5px;color:var(--muted);margin-top:2px}
.stock-bar{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:4px}
.stock-fill{height:100%;border-radius:3px}
.filter-bar{background:white;border:1px solid var(--border);border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fsel{padding:7px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:white;cursor:pointer;outline:none;font-family:'DM Sans',sans-serif}
.tab-bar{display:flex;gap:2px;background:var(--lighter);border-radius:10px;padding:4px;margin-bottom:20px;width:fit-content;max-width:100%;flex-wrap:wrap}
.tab-btn{padding:8px 20px;border:none;background:transparent;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;color:var(--muted);transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;font-family:'DM Sans',sans-serif}
.tab-btn.active{background:white;color:var(--navy);font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.08)}
/* Scroll interne au-delà de 9 lignes : hauteur = header (~40px) + 9 lignes (~54px/ligne) */
#bobinesTableWrap{max-height:526px;overflow-y:auto}
#bobinesTableWrap thead th{position:sticky;top:0;z-index:1}
</style>

<!-- KPIs -->
<div class="bob-kpi" style="grid-template-columns:repeat(6,1fr)">
  <div class="bk" style="border-left:3px solid var(--blue)"><div class="bk-icon">▶️</div><div><div class="bk-val" style="color:var(--blue)"><?= $stats_map['en_cours']['n']??0 ?></div><div class="bk-label">En utilisation</div></div></div>
  <div class="bk" style="border-left:3px solid var(--success)"><div class="bk-icon"><i class="ph ph-package" aria-hidden="true"></i></div><div><div class="bk-val" style="color:var(--success-d)"><?= $stats_map['en_stock']['n']??0 ?></div><div class="bk-label">En stock</div></div></div>
  <div class="bk" style="border-left:3px solid #9e9e9e"><div class="bk-icon"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i></div><div><div class="bk-val" style="color:#9e9e9e"><?= $stats_map['retiree']['n']??0 ?></div><div class="bk-label">Retirées</div></div></div>
  <div class="bk" style="border-left:3px solid #e65100"><div class="bk-icon"><i class="ph ph-x-circle" aria-hidden="true"></i></div><div><div class="bk-val" style="color:#e65100"><?= $stats_map['perdue']['n']??0 ?></div><div class="bk-label">Perdues</div></div></div>
  <div class="bk"><div class="bk-icon"><i class="ph ph-chart-line-down" aria-hidden="true"></i></div><div><div class="bk-val" style="color:var(--danger-d)"><?= $conso_today ?></div><div class="bk-label">Films conso. aujourd'hui</div></div></div>
  <div class="bk" style="<?= $nb_ecarts_ouverts>0?'border-color:var(--danger)':'' ?>">
    <div class="bk-icon"><i class="ph ph-warning" aria-hidden="true"></i></div>
    <div>
      <div class="bk-val" style="color:<?= $nb_ecarts_ouverts>0?'var(--danger-d)':'var(--muted)' ?>"><?= $nb_ecarts_ouverts ?></div>
      <div class="bk-label">Écarts ouverts</div>
    </div>
  </div>
</div>

<!-- TABS -->
<div class="tab-bar">
  <button class="tab-btn active" onclick="showTab('liste',this)"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Toutes les <?= $bob_label_pl ?></button>
  <button class="tab-btn" onclick="showTab('en_cours',this)">▶️ En utilisation <span style="background:var(--blue);color:white;border-radius:10px;padding:1px 7px;font-size:12px;margin-left:4px"><?= $stats_map['en_cours']['n']??0 ?></span></button>
  <button class="tab-btn" onclick="showTab('en_stock',this)"><i class="ph ph-package" aria-hidden="true"></i> En stock <span style="background:var(--success);color:white;border-radius:10px;padding:1px 7px;font-size:12px;margin-left:4px"><?= $stats_map['en_stock']['n']??0 ?></span></button>
  <button class="tab-btn" onclick="showTab('retiree',this)"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Retirées</button>
  <button class="tab-btn" onclick="showTab('perdue',this)"><i class="ph ph-x-circle" aria-hidden="true"></i> Perdues <?= ($stats_map['perdue']['n']??0)>0?"<span style='background:#e65100;color:white;border-radius:10px;padding:1px 7px;font-size:12px;margin-left:4px'>".($stats_map['perdue']['n']??0)."</span>":'' ?></button>
  <button class="tab-btn" onclick="showTab('ecarts',this)" id="tabEcartsBtn">
    <i class="ph ph-warning" aria-hidden="true"></i> Écarts <?= $nb_ecarts_ouverts>0?"<span style='background:var(--danger);color:white;border-radius:10px;padding:1px 7px;font-size:12px;margin-left:4px'>$nb_ecarts_ouverts</span>":'' ?>
  </button>
  <?php if(can('bobines','can_create') && !$is_coord): ?>
  <button class="tab-btn" onclick="showTab('conso',this)"><i class="ph ph-chart-line-down" aria-hidden="true"></i> Consommation</button>
  <?php endif; ?>
  <?php if(can('bobines','can_create') && !$is_coord): ?>
  <button class="tab-btn" onclick="showTab('nouvelle',this)"><i class="ph ph-plus" aria-hidden="true"></i> Nouvelle</button>
  <button class="tab-btn" onclick="showTab('import',this)"><i class="ph ph-upload-simple" aria-hidden="true"></i> Import</button>
  <?php endif; ?>
  <?php
  $nb_demandes_att = (int)db_fetch_value("SELECT COUNT(*) FROM demandes_bobines d JOIN op_bobines b ON b.id=d.bobine_id WHERE d.statut='en_attente' AND b.serie IN ($serie_ph)" . ($site_force?" AND b.site_id=$site_force":""), $series_actives);
  if(in_array($role_slug,['gestionnaire_stock_bobines','gestionnaire_stock','superviseur_operation','admin','superadmin']) || ($is_coord && $nb_demandes_att>0)):
  ?>
  <button class="tab-btn" onclick="showTab('demandes',this)">
    <i class="ph ph-tray" aria-hidden="true"></i> Demandes <?= $nb_demandes_att>0?"<span style='background:var(--warning);color:white;border-radius:10px;padding:1px 7px;font-size:12px;margin-left:4px'>$nb_demandes_att</span>":'' ?>
  </button>
  <?php endif; ?>
</div>

<!-- TAB LISTE -->
<div id="tab-liste">
  <form method="GET" class="filter-bar" id="bobFiltreForm">
    <?php if(!$site_force): ?>
    <select name="site" class="fsel" aria-label="Filtrer par site" onchange="bobFiltrer(this.form)">
      <option value="">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="statut" class="fsel" aria-label="Filtrer par statut" onchange="bobFiltrer(this.form)">
      <option value="">Tous statuts</option>
      <option value="en_stock" <?= $f_statut==='en_stock'?'selected':'' ?>>📦 En stock</option>
      <option value="en_cours" <?= $f_statut==='en_cours'?'selected':'' ?>>🎞️ En cours</option>
      <option value="epuisee"  <?= $f_statut==='epuisee'?'selected':'' ?>>❌ Épuisées</option>
      <option value="retiree"  <?= $f_statut==='retiree'?'selected':'' ?>>✅ Retirées</option>
      <option value="perdue"   <?= $f_statut==='perdue'?'selected':'' ?>>⚠️ Perdues</option>
    </select>

    <select name="format" class="fsel" aria-label="Filtrer par format" onchange="bobFiltrer(this.form)">
      <option value="">Tous les formats</option>
      <?php foreach($formats_bobines as $f): ?>
      <option value="<?= h($f) ?>" <?= $f_format===$f?'selected':'' ?>><?= h($f) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="version" class="fsel" aria-label="Filtrer par version" onchange="bobFiltrer(this.form)">
      <option value="">Toutes les versions</option>
      <?php foreach($versions_bobines as $v): ?>
      <option value="<?= h($v) ?>" <?= $f_version===$v?'selected':'' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>

    <div style="position:relative">
      <i class="ph-duotone ph-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none"></i>
      <input type="text" name="q" value="<?= h($f_search) ?>" aria-label="Rechercher un numéro de bobine"
             placeholder="N° bobine…" onchange="bobFiltrer(this.form)"
             style="padding:7px 12px 7px 32px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;outline:none;width:160px">
    </div>

    <a href="bobines.php" class="btn btn-secondary btn-sm" onclick="return bobEffacer(event)"
       style="<?= ($f_site||$f_statut||$f_serie||$f_type||$f_format||$f_version||$f_search) ? '' : 'display:none' ?>" id="bobEffacerBtn"><i class="ph ph-x" aria-hidden="true"></i> Effacer</a>

    <?php if(can('inventaire_bobines','can_read')): ?>
    <a href="<?= APP_URL ?>/pages/inventaire_bobines.php" class="btn btn-primary btn-sm" style="margin-left:auto"><i class="ph ph-chart-bar" aria-hidden="true"></i> Inventaire</a>
    <?php endif; ?>
  </form>

  <div class="card" id="bobinesResultCard" style="padding:0;overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:14px;font-weight:700;color:var(--navy)"><i class="ph ph-film-strip" aria-hidden="true"></i> <?= ucfirst($bob_label_pl) ?> (<?= count($bobines) ?>)</h3>
      <span style="font-size:12px;color:var(--muted)">Stock total système : <strong><?= number_format($total_stock) ?> films</strong></span>
    </div>
    <div class="table-wrap" id="bobinesTableWrap">
      <table>
        <thead><tr>
          <th>Numéro</th><th>Format</th><th>Version</th><th>Site</th>
          <th style="text-align:center">Qté livrée</th>
          <th style="text-align:center">Consommé</th>
          <th style="text-align:center">Restants</th>
          <th>Statut</th>
          <th style="text-align:center">Actions</th>
        </tr></thead>
        <tbody>
        <?php if(empty($bobines)): ?>
          <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">Aucune bobine trouvée.</td></tr>
        <?php else: foreach($bobines as $b):
          $qte_init  = max(1,(int)($b['qte_livree'] ?? $b['qte_initiale'] ?? 500));
          $restants  = (int)$b['stock_systeme'];
          $consomme  = max(0, $qte_init - $restants);
          $pct       = round($restants/$qte_init*100);
          $pct_conso = round($consomme/$qte_init*100);
          $bar_color = $pct>50?'var(--success-d)':($pct>20?'var(--warning-d)':'var(--danger-d)');
        ?>
          <tr>
            <td><span style="font-family:monospace;font-weight:700;font-size:13px;color:var(--navy)"><?= h($b['numero']) ?></span></td>
            <td style="font-size:12.5px"><?= h($b['tb_format'] ?? '—') ?></td>
            <td style="font-size:12.5px;color:var(--muted)"><?= h($b['tb_version'] ?? '—') ?></td>
            <td style="font-size:12.5px"><?= h($b['site_nom']??'') ?:('<span style="color:var(--muted)">Non affectée</span>') ?></td>
            <td style="text-align:center;font-size:12px;color:var(--muted);font-weight:600"><?= number_format($qte_init) ?></td>
            <td style="text-align:center">
              <span style="font-weight:700;color:#1D4ED8"><?= number_format($consomme) ?></span>
              <div style="font-size:12px;color:var(--muted)"><?= $pct_conso ?>%</div>
            </td>
            <td style="text-align:center">
              <span style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:15px;color:<?= $bar_color ?>"><?= number_format($restants) ?></span>
              <div style="font-size:12px;color:var(--muted)"><?= $pct ?>%</div>
            </td>
            <td><span class="bobine-status <?= $b['statut'] ?>"><?= ['en_stock'=>'<i class="ph ph-package" aria-hidden="true"></i> En stock','en_cours'=>'<i class="ph ph-film-strip" aria-hidden="true"></i> En cours','epuisee'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Épuisée','retiree'=>'<i class="ph ph-circle" aria-hidden="true"></i> Retirée'][$b['statut']]??$b['statut'] ?></span></td>
            <td style="text-align:center;white-space:nowrap">
              <button class="btn btn-secondary btn-sm" onclick="viewBobine(<?= $b['id'] ?>)" title="Détail"><i class="ph ph-magnifying-glass" aria-hidden="true"></i></button>
              <?php if(can('bobines','can_create') && $b['statut']!=='retiree' && $b['statut']!=='epuisee'): ?>
              <button class="btn btn-sm" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:4px 8px;border-radius:6px;cursor:pointer;margin-left:2px" onclick="openConso(<?= $b['id'] ?>,'<?= h($b['numero']) ?>',<?= $b['stock_systeme'] ?>)" title="Saisir consommation"><i class="ph ph-chart-line-down" aria-hidden="true"></i></button>
              <?php endif; ?>
              <?php if(can('bobines','can_create') && $b['statut']!=='retiree'): ?>
              <button class="btn btn-sm" style="background:#fff8e7;color:#b7791f;border:1px solid #f6d860;padding:4px 8px;border-radius:6px;cursor:pointer;margin-left:2px" onclick="openDeclarerEcart(<?= $b['id'] ?>,'<?= h($b['numero']) ?>',<?= $b['stock_systeme'] ?>)" title="Déclarer un écart"><i class="ph ph-warning" aria-hidden="true"></i></button>
              <?php endif; ?>
              <?php if(can('bobines','can_update') && !$is_coord && $b['statut']==='en_stock'): ?>
              <button class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;padding:4px 8px;border-radius:6px;cursor:pointer;margin-left:2px" onclick="openAffecter(<?= $b['id'] ?>,'<?= h($b['numero']) ?>')"><i class="ph ph-buildings" aria-hidden="true"></i></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB CONSOMMATION -->
<div id="tab-conso" style="display:none">
  <div class="card" style="max-width:560px">
    <div class="card-header"><h3><i class="ph ph-chart-line-down" aria-hidden="true"></i> Saisie de consommation journalière</h3></div>
    <div class="card-body">
      <div id="consoAlert"></div>
      <div class="form-group" style="margin-bottom:14px">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Bobine *</label>
        <select class="form-control" id="consoBobine" onchange="updateConsoStock()">
          <option value="">— Sélectionner une bobine —</option>
          <?php foreach($bobines as $b): if(in_array($b['statut'],['retiree','epuisee'])) continue; ?>
          <option value="<?= $b['id'] ?>" data-stock="<?= $b['stock_systeme'] ?>">
            <?= h($b['numero']) ?> — <?= h($b['type_code']) ?><?= $b['site_nom']?' ('.$b['site_nom'].')':'' ?> | Stock: <?= $b['stock_systeme'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="consoStockInfo" style="display:none;background:var(--lighter);padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px">
        Stock actuel : <strong id="consoStockVal" style="font-family:'Montserrat',sans-serif;font-size:16px;color:var(--navy)">—</strong> films
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <div class="form-group">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Quantité consommée *</label>
          <input type="number" class="form-control" id="consoQte" min="1" placeholder="0" oninput="checkConsoQte()">
          <div id="consoQteWarn" style="display:none;color:var(--danger-d);font-size:12px;margin-top:4px">Quantité > stock disponible !</div>
        </div>
        <div class="form-group">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Date *</label>
          <input type="date" class="form-control" id="consoDate" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Notes</label>
        <textarea class="form-control" id="consoNotes" rows="2" placeholder="Observations..."></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button class="btn btn-secondary" onclick="resetConso()">Réinitialiser</button>
        <button class="btn btn-primary" id="btnConso" onclick="saveConso()"><i class="ph ph-chart-line-down" aria-hidden="true"></i> Enregistrer</button>
      </div>
    </div>
  </div>
</div>

<!-- TAB NOUVELLE BOBINE -->
<?php if(can('bobines','can_create') && !$is_coord): ?>
<div id="tab-nouvelle" style="display:none">
  <div class="card" style="max-width:500px">
    <div class="card-header"><h3><i class="ph ph-plus" aria-hidden="true"></i> Créer une bobine</h3></div>
    <div class="card-body">
      <div id="nouvAlert"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <div class="form-group">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Numéro unique *</label>
          <input type="text" class="form-control" id="nouvNum" placeholder="Ex: BOB-2024-001" style="text-transform:uppercase">
        </div>
        <div class="form-group">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Type *</label>
          <select class="form-control" id="nouvType">
            <option value="">— Sélectionner —</option>
            <?php foreach($types_bobines as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Format</label>
          <input type="text" class="form-control" id="nouvFormat" placeholder="Ex: A4, 80m...">
        </div>
        <div class="form-group">
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Site initial</label>
          <select class="form-control" id="nouvSite">
            <option value="">— Stock central —</option>
            <?php foreach($sites_list as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="background:#eafaf1;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;border-left:3px solid var(--success)">
        <i class="ph ph-package" aria-hidden="true"></i> Stock initial automatique : <strong>500 films</strong>
      </div>
      <div style="display:flex;justify-content:flex-end">
        <button class="btn btn-primary" id="btnNouv" onclick="saveNouv()"><i class="ph ph-check-circle" aria-hidden="true"></i> Créer la bobine</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- TAB IMPORT -->
<?php if(can('bobines','can_create') && !$is_coord): ?>
<div id="tab-import" style="display:none">
  <div class="card" style="max-width:560px">
    <div class="card-header"><h3><i class="ph ph-upload-simple" aria-hidden="true"></i> Import en lot</h3></div>
    <div class="card-body">
      <div id="importAlert"></div>
      <div class="form-group" style="margin-bottom:14px">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Site d'affectation</label>
        <select class="form-control" id="importSite">
          <option value="">— Stock central —</option>
          <?php foreach($sites_list as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Liste <span style="font-size:12px;font-weight:400;color:var(--muted)">(une par ligne : NUMERO;TYPE;FORMAT)</span></label>
        <textarea class="form-control" id="importData" rows="8" placeholder="BOB-001;A001;A4" style="font-family:monospace;font-size:13px"></textarea>
      </div>
      <div style="display:flex;justify-content:flex-end">
        <button class="btn btn-primary" onclick="saveImport()"><i class="ph ph-upload-simple" aria-hidden="true"></i> Importer</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- TAB EN UTILISATION -->
<div id="tab-en_cours" style="display:none">
  <?php
  $bobines_en_cours = db_fetch_all(
    "SELECT b.*, s.nom AS site_nom FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id
     WHERE b.statut='en_cours' AND b.serie IN ($serie_ph)" . ($site_force?" AND b.site_id=$site_force":"") . " ORDER BY b.site_id, b.numero",
    $series_actives
  );
  ?>
  <div class="card">
    <div class="card-header">
      <h3>▶️ Bobines en utilisation <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($bobines_en_cours) ?>)</span></h3>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Numéro</th><th>Type</th><th>Site</th>
          <th style="text-align:center">Stock système</th>
          <th style="text-align:center">Films restants</th>
          <th style="text-align:center">Actions</th>
        </tr></thead>
        <tbody>
        <?php if(empty($bobines_en_cours)): ?>
          <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">Aucune bobine en utilisation.</td></tr>
        <?php else: foreach($bobines_en_cours as $b):
          $pct = $b['qte_initiale']>0 ? round($b['films_restants']/$b['qte_initiale']*100) : 0;
          $col = $pct<20?'var(--danger-d)':($pct<50?'#f39c12':'var(--success-d)');
        ?>
          <tr>
            <td style="font-family:monospace;font-weight:700"><?= h($b['numero']) ?></td>
            <td><?= h($b['type_code']) ?> <span style="color:var(--muted);font-size:12px"><?= h($b['serie']) ?></span></td>
            <td><?= h($b['site_nom']??'—') ?></td>
            <td style="text-align:center;font-weight:700"><?= fmt_number($b['stock_systeme']) ?></td>
            <td style="text-align:center">
              <div style="font-weight:700;color:<?= $col ?>"><?= fmt_number($b['films_restants']) ?></div>
              <div style="height:4px;background:var(--border);border-radius:2px;margin-top:3px;width:80px;margin:3px auto 0">
                <div style="width:<?= $pct ?>%;height:100%;background:<?= $col ?>;border-radius:2px"></div>
              </div>
            </td>
            <td style="text-align:center;white-space:nowrap">
              <?php if(can('bobines','can_update')): ?>
              <button class="btn btn-secondary btn-sm" onclick="changerStatut(<?= $b['id'] ?>,'en_stock')" title="Remettre en stock"><i class="ph ph-package" aria-hidden="true"></i> → Stock</button>
              <button class="btn btn-sm" style="background:#fff3e0;color:#e65100;border:1px solid #ffcc80"
                      onclick="declararerPerdue(<?= $b['id'] ?>, '<?= h($b['numero']) ?>')" title="Déclarer perdue"><i class="ph ph-x-circle" aria-hidden="true"></i> Perdue</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB EN STOCK -->
<div id="tab-en_stock" style="display:none">
  <?php
  $bobines_en_stock = db_fetch_all(
    "SELECT b.*, s.nom AS site_nom FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id
     WHERE b.statut='en_stock' AND b.serie IN ($serie_ph)" . ($site_force?" AND b.site_id=$site_force":"") . " ORDER BY b.site_id, b.numero",
    $series_actives
  );
  ?>
  <div class="card">
    <div class="card-header">
      <h3><i class="ph ph-package" aria-hidden="true"></i> Bobines en stock <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($bobines_en_stock) ?>)</span></h3>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Numéro</th><th>Type</th><th>Site</th>
          <th style="text-align:center">Stock</th>
          <th style="text-align:center">Actions</th>
        </tr></thead>
        <tbody>
        <?php if(empty($bobines_en_stock)): ?>
          <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">Aucune bobine en stock.</td></tr>
        <?php else: foreach($bobines_en_stock as $b): ?>
          <tr>
            <td style="font-family:monospace;font-weight:700"><?= h($b['numero']) ?></td>
            <td><?= h($b['type_code']) ?> <span style="color:var(--muted);font-size:12px"><?= h($b['serie']) ?></span></td>
            <td><?= h($b['site_nom']??'—') ?></td>
            <td style="text-align:center;font-weight:700"><?= fmt_number($b['stock_systeme']) ?></td>
            <td style="text-align:center">
              <?php if($is_coord): ?>
              <!-- Coordinateur : demande d'utilisation (pas besoin de can_update) -->
              <button class="btn btn-primary btn-sm" onclick="demanderUtilisation(<?= $b['id'] ?>, '<?= h($b['numero']) ?>')">
                ▶️ Demander utilisation
              </button>
              <?php elseif(can('bobines','can_update')): ?>
              <!-- Admin/Superviseur/GSB : changement direct -->
              <button class="btn btn-primary btn-sm" onclick="changerStatut(<?= $b['id'] ?>,'en_cours')" title="Mettre en utilisation">▶️ Mettre en utilisation</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB TERMINÉES -->
<div id="tab-retiree" style="display:none">
  <?php
  $bobines_retirees = db_fetch_all(
    "SELECT b.*, s.nom AS site_nom FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id
     WHERE b.statut IN ('retiree','epuisee') AND b.serie IN ($serie_ph)" . ($site_force?" AND b.site_id=$site_force":"") . " ORDER BY b.created_at DESC LIMIT 100",
    $series_actives
  );
  ?>
  <div class="card">
    <div class="card-header"><h3><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Bobines retirées <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($bobines_retirees) ?>)</span></h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Numéro</th><th>Type</th><th>Site</th><th style="text-align:center">Films utilisés</th><th>Statut</th></tr></thead>
        <tbody>
        <?php if(empty($bobines_retirees)): ?>
          <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">Aucune bobine terminée.</td></tr>
        <?php else: foreach($bobines_retirees as $b): ?>
          <tr>
            <td style="font-family:monospace;font-weight:700"><?= h($b['numero']) ?></td>
            <td><?= h($b['type_code']) ?></td>
            <td><?= h($b['site_nom']??'—') ?></td>
            <td style="text-align:center"><?= fmt_number($b['films_utilises']??0) ?> / <?= fmt_number($b['qte_initiale']??500) ?></td>
            <td><span class="bobine-status <?= $b['statut'] ?>"><?= $b['statut']==='epuisee'?'<i class="ph ph-check-circle" aria-hidden="true"></i> Épuisée':'<i class="ph ph-check-circle" aria-hidden="true"></i> Retirée' ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB PERDUES -->
<div id="tab-perdue" style="display:none">
  <?php
  $bobines_perdues = db_fetch_all(
    "SELECT b.*, s.nom AS site_nom FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id
     WHERE b.statut='perdue' AND b.serie IN ($serie_ph)" . ($site_force?" AND b.site_id=$site_force":"") . " ORDER BY b.created_at DESC",
    $series_actives
  );
  ?>
  <div class="card">
    <div class="card-header"><h3><i class="ph ph-x-circle" aria-hidden="true"></i> Bobines perdues <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($bobines_perdues) ?>)</span></h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Numéro</th><th>Type</th><th>Site</th><th style="text-align:center">Films restants perdus</th><th>Motif</th><th>Date</th></tr></thead>
        <tbody>
        <?php if(empty($bobines_perdues)): ?>
          <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">Aucune bobine perdue.</td></tr>
        <?php else: foreach($bobines_perdues as $b): ?>
          <tr>
            <td style="font-family:monospace;font-weight:700"><?= h($b['numero']) ?></td>
            <td><?= h($b['type_code']) ?></td>
            <td><?= h($b['site_nom']??'—') ?></td>
            <td style="text-align:center;color:#e65100;font-weight:700"><?= fmt_number($b['films_restants']??0) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= h($b['notes_perte']??'—') ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= fmt_date($b['created_at'],'d/m/Y') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB DEMANDES -->
<div id="tab-demandes" style="display:none">
  <?php
  $demandes = db_fetch_all(
    "SELECT d.*, b.numero AS bobine_num, b.type_code, b.films_restants,
            s.nom AS site_nom,
            CONCAT(u.prenom,' ',u.nom) AS demandeur,
            CONCAT(t.prenom,' ',t.nom) AS traiteur
     FROM demandes_bobines d
     JOIN op_bobines b ON b.id=d.bobine_id
     JOIN sites s ON s.id=d.site_id
     LEFT JOIN users u ON u.id=d.demande_par
     LEFT JOIN users t ON t.id=d.traite_par
     WHERE b.serie IN ($serie_ph) " . ($site_force?"AND d.site_id=$site_force":"") . "
     ORDER BY array_position(ARRAY['en_attente','approuvee','refusee']::text[], (d.statut)::text), d.created_at DESC LIMIT 50",
    $series_actives
  );
  $is_gsb = in_array($role_slug,['gestionnaire_stock_bobines','admin','superadmin']);
  ?>
  <div class="card">
    <div class="card-header">
      <h3><i class="ph ph-tray" aria-hidden="true"></i> Demandes d'utilisation de bobines</h3>
      <?php if($nb_demandes_att>0 && $is_gsb): ?>
      <span style="background:#fff3e0;color:#e65100;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700">
        ⏳ <?= $nb_demandes_att ?> en attente
      </span>
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Date</th><th>Bobine</th><th>Site</th>
          <th style="text-align:center">Films restants</th>
          <th>Motif</th><th>Demandé par</th>
          <th style="text-align:center">Statut</th>
          <th>Réponse GSB</th>
          <?php if($is_gsb): ?><th style="text-align:center">Actions</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if(empty($demandes)): ?>
          <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--muted)">Aucune demande.</td></tr>
        <?php else: foreach($demandes as $dem):
          $sc = match($dem['statut']){'en_attente'=>'#fff3e0;color:#e65100','approuvee'=>'#d1fae5;color:#065f46','refusee'=>'#fee2e2;color:#991b1b',default=>''};
          $sl = match($dem['statut']){'en_attente'=>'⏳ En attente','approuvee'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Approuvée','refusee'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Refusée',default=>$dem['statut']};
        ?>
          <tr style="<?= $dem['statut']==='en_attente'?'background:#fffbf0':'' ?>">
            <td style="font-size:12px;white-space:nowrap"><?= fmt_datetime($dem['created_at']) ?></td>
            <td style="font-family:monospace;font-weight:700"><?= h($dem['bobine_num']) ?> <span style="font-size:12px;color:var(--muted)"><?= h($dem['type_code']) ?></span></td>
            <td><?= h($dem['site_nom']) ?></td>
            <td style="text-align:center;font-weight:700"><?= fmt_number($dem['films_restants']) ?></td>
            <td style="font-size:12px;max-width:180px"><?= h($dem['motif']) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= h($dem['demandeur']??'—') ?></td>
            <td style="text-align:center">
              <span style="padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;background:<?= $sc ?>"><?= $sl ?></span>
            </td>
            <td style="font-size:12px;color:var(--muted);max-width:150px"><?= $dem['motif_reponse']?h($dem['motif_reponse']):'—' ?></td>
            <?php if($is_gsb): ?>
            <td style="text-align:center;white-space:nowrap">
              <?php if($dem['statut']==='en_attente'): ?>
              <button class="btn btn-success btn-sm" onclick="traiterDemande(<?= $dem['id'] ?>,'approuvee','<?= h($dem['bobine_num']) ?>')"><i class="ph ph-check-circle" aria-hidden="true"></i> Approuver</button>
              <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5"
                      onclick="traiterDemande(<?= $dem['id'] ?>,'refusee','<?= h($dem['bobine_num']) ?>')"><i class="ph ph-x-circle" aria-hidden="true"></i> Refuser</button>
              <?php else: ?>
              <span style="font-size:12px;color:var(--muted)"><?= fmt_date($dem['traite_at'],'d/m') ?></span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB ÉCARTS -->
<div id="tab-ecarts" style="display:none">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:14px;font-weight:700;color:var(--navy)"><i class="ph ph-warning" aria-hidden="true"></i> Suivi des écarts ouverts</h3>
    <button class="btn btn-secondary btn-sm" onclick="chargerEcarts()"><i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Actualiser</button>
  </div>
  <div id="ecartsContent">
    <div style="text-align:center;color:var(--muted);padding:40px;font-size:13px">Cliquez sur l'onglet pour charger les écarts…</div>
  </div>
</div>

<!-- MODAL DÉTAIL -->
<div id="modalDetail" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:620px;max-width:95vw;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--navy)"><i class="ph ph-film-strip" aria-hidden="true"></i> Détail bobine</h3>
      <button onclick="closeAllModals()" style="background:none;border:none;font-size:20px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="detailContent"><div style="text-align:center;color:var(--muted);padding:30px">Chargement…</div></div>
  </div>
</div>

<!-- MODAL CONSO RAPIDE -->
<div id="modalConso" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:var(--navy)"><i class="ph ph-chart-line-down" aria-hidden="true"></i> Consommation — <span id="mConsoNum"></span></h3>
      <button onclick="closeAllModals()" style="background:none;border:none;font-size:20px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <input type="hidden" id="mConsoBobineId">
    <div style="background:var(--lighter);padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px">
      Stock actuel : <strong id="mConsoStock" style="font-family:'Montserrat',sans-serif;font-size:16px;color:var(--navy)">—</strong> films
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
      <div><label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px">Quantité *</label>
        <input type="number" class="form-control" id="mConsoQte" min="1" placeholder="0"></div>
      <div><label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px">Date *</label>
        <input type="date" class="form-control" id="mConsoDate" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="form-group" style="margin-bottom:16px">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px">Notes</label>
      <textarea class="form-control" id="mConsoNotes" rows="2"></textarea>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="closeAllModals()">Annuler</button>
      <button class="btn btn-primary" id="btnMConso" onclick="saveMConso()"><i class="ph ph-chart-line-down" aria-hidden="true"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- MODAL AFFECTER -->
<div id="modalAffecter" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:var(--navy)"><i class="ph ph-buildings" aria-hidden="true"></i> Affecter — <span id="mAffNum"></span></h3>
      <button onclick="closeAllModals()" style="background:none;border:none;font-size:20px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <input type="hidden" id="mAffId">
    <div class="form-group" style="margin-bottom:18px">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Site de destination *</label>
      <select class="form-control" id="mAffSite">
        <option value="">— Sélectionner —</option>
        <?php foreach($sites_list as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="closeAllModals()">Annuler</button>
      <button class="btn btn-primary" onclick="saveAffecter()"><i class="ph ph-check-circle" aria-hidden="true"></i> Affecter</button>
    </div>
  </div>
</div>

<script>
const IS_COORD = <?= $is_coord ? 'true' : 'false' ?>;
const MODALS = ['modalDetail','modalConso','modalAffecter','modalEcart','modalResoudre'];
function openModal(id){ document.getElementById(id).style.display='flex'; }
function closeAllModals(){ MODALS.forEach(id=>{ const el=document.getElementById(id); if(el) el.style.display='none'; }); }
MODALS.forEach(id=>{ const el=document.getElementById(id); if(el) el.addEventListener('click',function(e){if(e.target===this)closeAllModals();}); });

function showTab(name,btn){
  document.querySelectorAll('[id^="tab-"]').forEach(t=>t.style.display='none');
  const el=document.getElementById('tab-'+name);
  if(el) el.style.display='block';
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');
  if(name==='ecarts') chargerEcarts();
}

// ── Filtres de la liste (site/statut/type/recherche) sans rechargement visible :
// on recupere le tableau filtre en arriere-plan et on l'echange sur place.
var bobEnVol = null;
function bobCharger(url){
  if(!window.fetch || !window.DOMParser){ location.href = url; return; }
  if(bobEnVol) try{ bobEnVol.abort(); }catch(e){}
  const ctrl = window.AbortController ? new AbortController() : null;
  bobEnVol = ctrl;
  fetch(url, {credentials:'same-origin', signal: ctrl?ctrl.signal:undefined, headers:{'X-Requested-With':'fetch'}})
    .then(r => { if(!r.ok) throw new Error(r.status); return r.text(); })
    .then(html => {
      if(bobEnVol !== ctrl) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const neuf = doc.getElementById('bobinesResultCard');
      const ancien = document.getElementById('bobinesResultCard');
      if(!neuf || !ancien) throw new Error('structure');
      ancien.replaceWith(neuf);
      history.pushState({bob:1}, '', url);
      bobEnVol = null;
    })
    .catch(e => {
      if(e && e.name==='AbortError') return;
      bobEnVol = null;
      location.href = url;
    });
}
function bobMajEffacer(form){
  const actif = ['site','statut','format','version','q'].some(n => form.elements[n] && form.elements[n].value);
  const btn = document.getElementById('bobEffacerBtn');
  if(btn) btn.style.display = actif ? '' : 'none';
}
function bobFiltrer(form){
  bobMajEffacer(form);
  bobCharger(location.pathname + '?' + new URLSearchParams(new FormData(form)).toString());
}
function bobEffacer(ev){
  ev.preventDefault();
  const form = document.getElementById('bobFiltreForm');
  ['site','statut','format','version','q'].forEach(n => { if(form.elements[n]) form.elements[n].value = ''; });
  document.getElementById('bobEffacerBtn').style.display = 'none';
  bobCharger(location.pathname);
  return false;
}
window.addEventListener('popstate', function(ev){ if(ev.state && ev.state.bob) location.reload(); });

function changerStatut(id, nouveauStatut) {
  const label = nouveauStatut==='en_cours' ? 'mettre cette bobine en utilisation' : 'remettre cette bobine en stock';
  let motif = '';
  if(nouveauStatut==='en_stock') {
    motif = prompt('Motif du retour en stock (obligatoire) :');
    if(motif===null) return;
    if(!motif.trim()) { alert('Le motif est obligatoire.'); return; }
  }
  if(!confirm(`Confirmer : ${label} ?`)) return;
  ap({action:'changer_statut', id, statut:nouveauStatut, motif}).then(d=>{
    if(d.success){ toast(d.message); setTimeout(()=>location.reload(),800); }
    else alert('Erreur : '+d.message);
  });
}

function declararerPerdue(id, numero) {
  const motif = prompt(`Déclarer la bobine ${numero} comme PERDUE.\nMotif obligatoire :`);
  if(motif===null) return;
  if(!motif.trim()) { alert('Le motif est obligatoire.'); return; }
  if(!confirm(`⚠️ Confirmer la perte de la bobine ${numero} ?\nLes films restants seront définitivement perdus.\nMotif : ${motif}`)) return;
  ap({action:'declarer_perdue', id, motif}).then(d=>{
    if(d.success){ toast(d.message+` (${d.data?.films_perdus||0} films perdus)`); setTimeout(()=>location.reload(),900); }
    else alert('Erreur : '+d.message);
  });
}

function traiterDemande(demId, decision, bobineNum) {
  const motif = prompt(`${decision==='approuvee'?'✅ Approuver':'❌ Refuser'} la demande pour la bobine ${bobineNum}.\nMotif (obligatoire) :`);
  if(motif===null) return;
  if(!motif.trim()) { alert('Le motif est obligatoire.'); return; }
  ap({action:'traiter_demande', dem_id:demId, decision, motif_reponse:motif}).then(d=>{
    if(d.success){ toast(d.message); setTimeout(()=>location.reload(),800); }
    else alert('Erreur : '+d.message);
  });
}

function demanderUtilisation(id, numero) {
  const motif = prompt(`Demande d'utilisation de la bobine ${numero}.\nMotif de la demande :`);
  if(motif===null) return;
  ap({action:'demander_utilisation', id, motif}).then(d=>{
    if(d.success){ toast(d.message); setTimeout(()=>location.reload(),800); }
    else alert('Erreur : '+d.message);
  });
}

function ap(data){ return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)}).then(r=>r.json()); }

function toast(msg,type='success'){
  let t=document.getElementById('toast-live');
  if(!t){t=document.createElement('div');t.id='toast-live';t.setAttribute('role','status');t.setAttribute('aria-live','polite');t.setAttribute('aria-atomic','true');document.body.appendChild(t);}
  clearTimeout(t._hideTimer);
  t.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);background:${type==='success'?'#27ae60':'#e74c3c'};color:white;max-width:380px`;
  t.innerHTML=msg; t._hideTimer=setTimeout(()=>{t.style.display='none';},3500);
}

// ── CONSOMMATION ONGLET
function updateConsoStock(){
  const sel=document.getElementById('consoBobine');
  const opt=sel.options[sel.selectedIndex];
  const info=document.getElementById('consoStockInfo');
  if(opt.value){ info.style.display='block'; document.getElementById('consoStockVal').textContent=opt.dataset.stock||'—'; }
  else info.style.display='none';
}
function checkConsoQte(){
  const qte=parseInt(document.getElementById('consoQte').value)||0;
  const stock=parseInt(document.getElementById('consoBobine').options[document.getElementById('consoBobine').selectedIndex]?.dataset?.stock||0);
  document.getElementById('consoQteWarn').style.display=qte>stock&&stock>0?'block':'none';
}
function resetConso(){
  ['consoBobine','consoQte','consoNotes'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('consoDate').value='<?= date('Y-m-d') ?>';
  document.getElementById('consoStockInfo').style.display='none';
  document.getElementById('consoQteWarn').style.display='none';
  document.getElementById('consoAlert').innerHTML='';
  document.getElementById('btnConso').disabled=false;
  document.getElementById('btnConso').textContent='📉 Enregistrer';
}
async function saveConso(){
  const bid=document.getElementById('consoBobine').value;
  const qte=document.getElementById('consoQte').value;
  if(!bid||!qte||parseInt(qte)<=0){document.getElementById('consoAlert').innerHTML='<div class="alert alert-danger">Bobine et quantité obligatoires.</div>';return;}
  const btn=document.getElementById('btnConso'); btn.disabled=true; btn.textContent='⏳…';
  const d=await ap({action:'consommation',bobine_id:bid,quantite:qte,date_conso:document.getElementById('consoDate').value,notes:document.getElementById('consoNotes').value});
  btn.disabled=false; btn.textContent='📉 Enregistrer';
  if(d.success){toast(d.message);resetConso();setTimeout(()=>location.reload(),1500);}
  else document.getElementById('consoAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}

// ── CONSOMMATION RAPIDE
function openConso(id,num,stock){
  document.getElementById('mConsoBobineId').value=id;
  document.getElementById('mConsoNum').textContent=num;
  document.getElementById('mConsoStock').textContent=stock;
  document.getElementById('mConsoQte').value='';
  document.getElementById('mConsoDate').value='<?= date('Y-m-d') ?>';
  document.getElementById('mConsoNotes').value='';
  openModal('modalConso');
}
async function saveMConso(){
  const qte=document.getElementById('mConsoQte').value;
  if(!qte||parseInt(qte)<=0){alert('Quantité obligatoire.');return;}
  const btn=document.getElementById('btnMConso'); btn.disabled=true; btn.textContent='⏳…';
  const d=await ap({action:'consommation',bobine_id:document.getElementById('mConsoBobineId').value,quantite:qte,date_conso:document.getElementById('mConsoDate').value,notes:document.getElementById('mConsoNotes').value});
  btn.disabled=false; btn.textContent='📉 Enregistrer';
  if(d.success){closeAllModals();toast(d.message);setTimeout(()=>location.reload(),1500);}
  else alert('Erreur : '+d.message);
}

// ── AFFECTER
function openAffecter(id,num){ document.getElementById('mAffId').value=id; document.getElementById('mAffNum').textContent=num; document.getElementById('mAffSite').value=''; openModal('modalAffecter'); }
async function saveAffecter(){
  const site=document.getElementById('mAffSite').value;
  if(!site){alert('Sélectionnez un site.');return;}
  const d=await ap({action:'affecter_site',id:document.getElementById('mAffId').value,site_id:site});
  if(d.success){closeAllModals();toast(d.message);setTimeout(()=>location.reload(),800);}
  else alert('Erreur : '+d.message);
}

// ── DÉTAIL BOBINE
async function viewBobine(id){
  document.getElementById('detailContent').innerHTML='<div style="text-align:center;color:var(--muted);padding:30px">Chargement…</div>';
  openModal('modalDetail');
  const d=await ap({action:'get_detail',id});
  if(!d.success){document.getElementById('detailContent').innerHTML='<div style="color:var(--danger-d)">Erreur.</div>';return;}
  const b=d.data.bob;
  const statuts={'en_stock':'📦 En stock','en_cours':'🎞️ En cours','epuisee':'❌ Épuisée','retiree':'⚫ Retirée'};
  const qteInit=Math.max(1,parseInt(b.qte_initiale)||500);
  const pct=Math.round(parseInt(b.stock_systeme)/qteInit*100);
  const barColor=pct>50?'#27ae60':pct>20?'#f39c12':'#e74c3c';
  let html=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:13px">
    <div><div style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:3px">Numéro</div><strong style="font-family:monospace;font-size:15px">${b.numero}</strong></div>
    <div><div style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:3px">Format · Version</div><strong>${b.tb_format||b.type_code}</strong>${b.tb_version?' · '+b.tb_version:''}</div>
    <div><div style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:3px">Site</div><strong>${b.site_nom||'Non affectée'}</strong></div>
    <div><div style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:3px">Statut</div>${statuts[b.statut]||b.statut}</div>
  </div>
  <div style="background:#f8fafc;padding:14px;border-radius:10px;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px">
      <span>Stock système</span>
      <strong style="color:${barColor}">${parseInt(b.stock_systeme).toLocaleString('fr-FR')} / ${qteInit.toLocaleString('fr-FR')} films (${pct}%)</strong>
    </div>
    <div style="height:10px;background:#e2e8f0;border-radius:5px;overflow:hidden">
      <div style="width:${Math.min(100,pct)}%;height:100%;background:${barColor};border-radius:5px"></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:10px;font-size:12.5px">
      <span style="color:#64748b">Conso moy. 30j : <strong>${d.data.conso_moy} roul./jour</strong></span>
      ${d.data.jours_restants?`<span style="color:${d.data.jours_restants<30?'#e74c3c':d.data.jours_restants<90?'#f39c12':'#27ae60'}">⏱ Épuisement dans <strong>${d.data.jours_restants}j</strong> (${d.data.date_epuisement})</span>`:'<span style="color:#94a3b8">Pas assez de données</span>'}
    </div>
  </div>`;
  if(d.data.consos.length){
    html+=`<h4 style="font-size:13px;font-weight:700;color:#0d1f35;margin-bottom:8px">📋 Historique (30 derniers jours)</h4>
    <div style="max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead><tr style="background:#f8fafc">
        <th style="padding:7px 10px;text-align:left;font-weight:600;color:#64748b">Date</th>
        <th style="padding:7px 10px;text-align:right;font-weight:600;color:#64748b">Consommé</th>
        <th style="padding:7px 10px;text-align:right;font-weight:600;color:#64748b">Avant</th>
        <th style="padding:7px 10px;text-align:right;font-weight:600;color:#64748b">Après</th>
        <th style="padding:7px 10px;font-weight:600;color:#64748b">Agent</th>
      </tr></thead><tbody>`;
    d.data.consos.forEach(c=>{
      html+=`<tr style="border-top:1px solid #e2e8f0">
        <td style="padding:6px 10px">${c.date_conso}</td>
        <td style="padding:6px 10px;text-align:right;color:#e74c3c;font-weight:700">-${c.quantite}</td>
        <td style="padding:6px 10px;text-align:right;color:#94a3b8">${c.stock_avant}</td>
        <td style="padding:6px 10px;text-align:right;font-weight:600">${c.stock_apres}</td>
        <td style="padding:6px 10px;color:#94a3b8">${c.agent||'—'}</td>
      </tr>`;
    });
    html+=`</tbody></table></div>`;
  } else html+=`<div style="text-align:center;color:#94a3b8;padding:16px;font-size:13px">Aucune consommation enregistrée.</div>`;
  document.getElementById('detailContent').innerHTML=html;
}

// ── NOUVELLE BOBINE
async function saveNouv(){
  const num=document.getElementById('nouvNum').value.trim().toUpperCase();
  const type=document.getElementById('nouvType').value;
  if(!num||!type){document.getElementById('nouvAlert').innerHTML='<div class="alert alert-danger">Numéro et type obligatoires.</div>';return;}
  const btn=document.getElementById('btnNouv'); btn.disabled=true; btn.textContent='⏳…';
  const d=await ap({action:'create',numero:num,type_code:type,format:document.getElementById('nouvFormat').value,site_id:document.getElementById('nouvSite').value});
  btn.disabled=false; btn.textContent='✅ Créer la bobine';
  if(d.success){toast(d.message);['nouvNum','nouvFormat'].forEach(i=>document.getElementById(i).value='');document.getElementById('nouvType').value='';document.getElementById('nouvSite').value='';setTimeout(()=>location.reload(),1000);}
  else document.getElementById('nouvAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}

// ── IMPORT LOT
async function saveImport(){
  const raw=document.getElementById('importData').value.trim();
  const site=document.getElementById('importSite').value;
  if(!raw){alert('Saisissez au moins une bobine.');return;}
  const items=raw.split('\n').map(l=>{const p=l.split(';');return{numero:(p[0]||'').trim(),type_code:(p[1]||'').trim(),format:(p[2]||'').trim()};}).filter(i=>i.numero&&i.type_code);
  if(!items.length){alert('Format invalide. Utilisez : NUMERO;TYPE;FORMAT');return;}
  const d=await ap({action:'import_lot',items:JSON.stringify(items),site_id:site});
  if(d.success){toast(d.message);document.getElementById('importData').value='';setTimeout(()=>location.reload(),1200);}
  else document.getElementById('importAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}

// ── DÉCLARER ÉCART MANUEL
function openDeclarerEcart(id,num,stockSys){
  document.getElementById('mEcartBobineId').value=id;
  document.getElementById('mEcartNum').textContent=num;
  document.getElementById('mEcartStockSys').textContent=stockSys;
  document.getElementById('mEcartPhysique').value='';
  document.getElementById('mEcartMotif').value='';
  document.getElementById('mEcartPreview').style.display='none';
  document.getElementById('mEcartPhysique').dataset.sys=stockSys;
  openModal('modalEcart');
}
function previewEcartManuel(){
  const phy=parseInt(document.getElementById('mEcartPhysique').value);
  const sys=parseInt(document.getElementById('mEcartPhysique').dataset.sys||0);
  const prev=document.getElementById('mEcartPreview');
  if(isNaN(phy)){prev.style.display='none';return;}
  const ecart=phy-sys;
  prev.style.display='block';
  prev.style.background=ecart<0?'#fdf0ef':ecart>0?'#eafaf1':'#f0f9ff';
  document.getElementById('mEcartVal').style.color=ecart<0?'#e74c3c':ecart>0?'#27ae60':'#1a56a0';
  document.getElementById('mEcartVal').textContent=(ecart>0?'+':'')+ecart;
  document.getElementById('mEcartLabel').textContent=ecart<0?' films manquants':ecart>0?' films en excedent':' OK';
}
async function saveEcart(){
  const bid=document.getElementById('mEcartBobineId').value;
  const phy=document.getElementById('mEcartPhysique').value;
  const motif=document.getElementById('mEcartMotif').value.trim();
  const date=document.getElementById('mEcartDate').value;
  if(phy===''){alert('Saisissez le stock physique.');return;}
  if(!motif){alert('Le motif est obligatoire.');return;}
  const btn=document.getElementById('btnSaveEcart');btn.disabled=true;btn.textContent='...';
  const d=await ap({action:'declarer_ecart',bobine_id:bid,stock_physique:phy,date_constat:date,motif});
  btn.disabled=false;btn.textContent='Enregistrer';
  if(d.success){closeAllModals();toast('Ecart de '+(d.ecart>0?'+':'')+d.ecart+' films enregistre.');setTimeout(()=>location.reload(),1200);}
  else alert('Erreur : '+d.message);
}
function openResoudre(ecartId,num,ecartVal,physique,systeme,motif){
  document.getElementById('mResoudreId').value=ecartId;
  const color=ecartVal<0?'#e74c3c':'#27ae60';
  document.getElementById('mResoudreInfo').innerHTML='<strong>'+num+'</strong> - Ecart : <strong style="color:'+color+'">'+(ecartVal>0?'+':'')+ecartVal+' films</strong><br>Physique: '+physique+' / Systeme: '+systeme+'<br><small>'+motif+'</small>';
  document.getElementById('mResoudreAjuster').checked=false;
  document.getElementById('mResoudreNotes').value='';
  openModal('modalResoudre');
}
async function saveResolution(){
  const id=document.getElementById('mResoudreId').value;
  const notes=document.getElementById('mResoudreNotes').value.trim();
  const ajust=document.getElementById('mResoudreAjuster').checked?'1':'0';
  const d=await ap({action:'resoudre_ecart',ecart_id:id,notes,ajuster_stock:ajust});
  if(d.success){closeAllModals();toast(d.message);setTimeout(()=>location.reload(),1500);}
  else alert('Erreur : '+d.message);
}
async function chargerEcarts(){
  document.getElementById('ecartsContent').innerHTML='<div style="text-align:center;color:#94a3b8;padding:40px">Chargement...</div>';
  const d=await ap({action:'get_ecarts'});
  if(!d.success){document.getElementById('ecartsContent').innerHTML='<div style="color:#e74c3c">Erreur.</div>';return;}
  const ecarts=d.data.ecarts;
  if(!ecarts.length){
    document.getElementById('ecartsContent').innerHTML='<div style="text-align:center;color:#27ae60;padding:40px;font-size:14px">✅ Aucun écart ouvert. Tout est conforme.</div>';
    return;
  }
  // Grouper par site
  const parSite={};
  ecarts.forEach(ec=>{
    const site=ec.site_nom||'Non affecté';
    if(!parSite[site]) parSite[site]={ecarts:[],total_manques:0,total_exces:0};
    parSite[site].ecarts.push(ec);
    if(ec.ecart<0) parSite[site].total_manques+=Math.abs(ec.ecart);
    else            parSite[site].total_exces+=ec.ecart;
  });
  let html='';
  Object.entries(parSite).forEach(([site,data])=>{
    const nbEcarts=data.ecarts.length;
    const hasMissing=data.total_manques>0;
    html+=`<div style="margin-bottom:20px;background:white;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
      <div style="padding:12px 18px;background:${hasMissing?'#fff5f5':'#f0fdf4'};border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:10px">
          <strong style="font-family:'Montserrat',sans-serif;font-size:14px;color:#0d1f35">🏢 ${site}</strong>
          <span style="background:#e2e8f0;color:#64748b;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600">${nbEcarts} bobine${nbEcarts>1?'s':''} avec écart</span>
        </div>
        <div style="display:flex;gap:12px;font-size:12px">
          ${data.total_manques>0?`<span style="color:#e74c3c;font-weight:700">▼ ${data.total_manques} films manquants</span>`:''}
          ${data.total_exces>0?`<span style="color:#27ae60;font-weight:700">▲ ${data.total_exces} films excédent</span>`:''}
        </div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="background:#f8fafc">
          <th style="padding:8px 14px;text-align:left;font-size:12px;color:#64748b;font-weight:600">BOBINE</th>
          <th style="padding:8px 14px;text-align:center;font-size:12px;color:#64748b;font-weight:600">TYPE</th>
          <th style="padding:8px 14px;text-align:right;font-size:12px;color:#64748b;font-weight:600">QTÉ SYSTÈME</th>
          <th style="padding:8px 14px;text-align:right;font-size:12px;color:#64748b;font-weight:600">QTÉ PHYSIQUE</th>
          <th style="padding:8px 14px;text-align:right;font-size:12px;color:#64748b;font-weight:600">ÉCART</th>
          <th style="padding:8px 14px;font-size:12px;color:#64748b;font-weight:600">MOTIF</th>
          <th style="padding:8px 14px;text-align:center;font-size:12px;color:#64748b;font-weight:600">DATE</th>
          <th style="padding:8px 14px;text-align:center;font-size:12px;color:#64748b;font-weight:600">ACTION</th>
        </tr></thead><tbody>`;
    data.ecarts.forEach(ec=>{
      const c=ec.ecart<0?'#e74c3c':'#27ae60';
      const src={'inventaire':'📊 Inventaire','manuel':'✏️ Manuel','import':'📤 Import'}[ec.source]||ec.source;
      html+=`<tr style="border-top:1px solid #e2e8f0;background:${ec.ecart<0?'#fff8f8':'#f8fff8'}">
        <td style="padding:9px 14px;font-family:monospace;font-weight:700;color:#0d1f35">${ec.numero}</td>
        <td style="padding:9px 14px;text-align:center"><span style="background:#f1f5f9;padding:2px 7px;border-radius:4px;font-size:12px;font-weight:600">${ec.type_code||'—'}</span></td>
        <td style="padding:9px 14px;text-align:right;font-family:'Montserrat',sans-serif;font-weight:700">${parseInt(ec.stock_systeme).toLocaleString('fr-FR')}</td>
        <td style="padding:9px 14px;text-align:right;font-family:'Montserrat',sans-serif;font-weight:700">${parseInt(ec.stock_physique).toLocaleString('fr-FR')}</td>
        <td style="padding:9px 14px;text-align:right;font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:${c}">${ec.ecart>0?'+':''}${ec.ecart}</td>
        <td style="padding:9px 14px;font-size:12px;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${ec.motif||''}">${ec.motif||'—'}</td>
        <td style="padding:9px 14px;text-align:center;font-size:12px;color:#64748b">${ec.date_constat}</td>
        <td style="padding:9px 14px;text-align:center">
          ${IS_COORD
            ? `<span style="font-size:12px;color:#94a3b8;font-style:italic">En attente GSB</span>`
            : `<button style="background:#eafaf1;color:#1e8449;border:1px solid #a9dfbf;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600"
                       onclick="openResoudre(${ec.id},'${ec.numero}',${ec.ecart},${ec.stock_physique},${ec.stock_systeme},'${(ec.motif||'').replace(/'/g,"\\'")}')">✅ Résoudre</button>`}
        </td>
      </tr>`;
    });
    html+=`</tbody></table></div>`;
  });
  html+=`<div style="text-align:right;font-size:12px;color:#94a3b8;margin-top:4px">${ecarts.length} écart(s) — ${Object.keys(parSite).length} site(s)</div>`;
  document.getElementById('ecartsContent').innerHTML=html;
}
</script>

<!-- MODAL ECART -->
<div id="modalEcart" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#0d1f35">Declarer un ecart - <span id="mEcartNum"></span></h3>
      <button onclick="closeAllModals()" style="background:none;border:none;font-size:20px;cursor:pointer">x</button>
    </div>
    <input type="hidden" id="mEcartBobineId">
    <div style="background:#fff8e7;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;border-left:3px solid #f39c12">
      Stock systeme actuel : <strong id="mEcartStockSys" style="font-family:'Montserrat',sans-serif;font-size:15px">-</strong> films
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
      <div><label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px">Films comptes (physique) *</label>
        <input type="number" class="form-control" id="mEcartPhysique" min="0" placeholder="0" oninput="previewEcartManuel()"></div>
      <div><label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px">Date du constat *</label>
        <input type="date" class="form-control" id="mEcartDate" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div id="mEcartPreview" style="display:none;padding:10px 14px;border-radius:8px;margin-bottom:14px;text-align:center">
      <strong id="mEcartVal" style="font-family:'Montserrat',sans-serif;font-size:22px"></strong>
      <span id="mEcartLabel" style="font-size:12px"></span>
    </div>
    <div class="form-group" style="margin-bottom:18px">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px">Motif *</label>
      <textarea class="form-control" id="mEcartMotif" rows="3" placeholder="Explication de l'ecart..."></textarea>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="closeAllModals()">Annuler</button>
      <button class="btn btn-primary" id="btnSaveEcart" onclick="saveEcart()">Enregistrer l'ecart</button>
    </div>
  </div>
</div>

<!-- MODAL RESOUDRE -->
<div id="modalResoudre" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:15px;font-weight:800;color:#0d1f35">Resoudre l'ecart</h3>
      <button onclick="closeAllModals()" style="background:none;border:none;font-size:20px;cursor:pointer">x</button>
    </div>
    <input type="hidden" id="mResoudreId">
    <div id="mResoudreInfo" style="background:#f8fafc;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px"></div>
    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;margin-bottom:14px;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px">
      <input type="checkbox" id="mResoudreAjuster" style="width:16px;height:16px">
      <div><strong>Ajuster le stock systeme selon le physique</strong>
        <div style="font-size:11.5px;color:#64748b">Le stock systeme sera mis a jour avec la valeur physique</div></div>
    </label>
    <div class="form-group" style="margin-bottom:18px">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px">Notes de resolution</label>
      <textarea class="form-control" id="mResoudreNotes" rows="3"></textarea>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="closeAllModals()">Annuler</button>
      <button class="btn btn-success" onclick="saveResolution()">Resoudre</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
