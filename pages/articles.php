<?php
// ============================================================
//  pages/articles.php  —  Gestion des Articles (ex-Consommables)
//  Papeterie, encre, matériel, pièces, fournitures
//  Prix unitaire et seuil d'alerte = entiers uniquement
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/upload.php';

require_auth();
require_permission('consommables', 'can_read');

$user        = current_user();
$role_slug   = $user['role_slug'] ?? '';
$page_title  = 'Articles';
$active_page = 'consommables';

$TYPES_ARTICLE = [
    'papeterie'   => 'Papeterie',
    'encre'       => 'Encre / Cartouche',
    'materiel'    => 'Matériel',
    'pieces'      => 'Pièces détachées',
    'fournitures' => 'Fournitures',
    'autre'       => 'Autre',
];
$UNITES = ['unite'=>'Unité','kg'=>'Kilogramme','litre'=>'Litre','boite'=>'Boîte','rame'=>'Rame','paquet'=>'Paquet','rouleau'=>'Rouleau'];

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── CRÉER ARTICLE
    if ($action === 'create_article') {
        require_permission('consommables', 'can_create');
        $code  = strtoupper(trim($_POST['code']        ?? ''));
        $lib   = trim($_POST['libelle']                ?? '');
        $type  = trim($_POST['type_article']           ?? 'autre');
        $unite = trim($_POST['unite']                  ?? 'unite');
        $desc  = trim($_POST['description']            ?? '');
        $seuil = (int)($_POST['seuil_alerte']          ?? 10);
        $prix  = (int)($_POST['prix_unitaire']         ?? 0);
        $famille_id = (int)($_POST['famille_id']       ?? 0) ?: null;
        if (!$code || !$lib) json_response(false, 'Code et libellé obligatoires.');
        if (db_fetch_value("SELECT COUNT(*) FROM articles WHERE code=?", [$code]) > 0)
            json_response(false, "Le code $code existe déjà.");
        db_query(
            "INSERT INTO articles (code,libelle,type_article,unite,description,seuil_alerte,prix_unitaire,famille_id)
             VALUES (?,?,?,?,?,?,?,?)",
            [$code, $lib, $type, $unite, $desc, $seuil, $prix, $famille_id]
        );
        $id = (int)db_last_id();
        audit_log($user['id'], 'CREATE', 'articles', $id, "Création article $code — $lib");
        json_response(true, 'Article créé.', ['id' => $id]);
    }

    // ── MODIFIER ARTICLE
    if ($action === 'update_article') {
        require_permission('consommables', 'can_update');
        $id    = (int)($_POST['id']            ?? 0);
        $lib   = trim($_POST['libelle']        ?? '');
        $type  = trim($_POST['type_article']   ?? 'autre');
        $unite = trim($_POST['unite']          ?? 'unite');
        $desc  = trim($_POST['description']    ?? '');
        $seuil = (int)($_POST['seuil_alerte']  ?? 10);
        $prix  = (int)($_POST['prix_unitaire'] ?? 0);
        $famille_id = (int)($_POST['famille_id'] ?? 0) ?: null;
        if (!$lib) json_response(false, 'Libellé obligatoire.');
        $old = db_fetch_one("SELECT * FROM articles WHERE id=?", [$id]);
        db_query(
            "UPDATE articles SET libelle=?,type_article=?,unite=?,description=?,seuil_alerte=?,prix_unitaire=?,famille_id=? WHERE id=?",
            [$lib, $type, $unite, $desc, $seuil, $prix, $famille_id, $id]
        );
        // Reclassement possible à tout moment (Bloc 1, point 9) : sans effet
        // rétroactif, les lignes de FEB déjà émises portent déjà leur propre
        // famille_id, copié au moment de la composition (ach_creer_feb).
        if ((int)($old['famille_id'] ?? 0) !== (int)($famille_id ?? 0)) {
            $ancienne = $old['famille_id'] ? db_fetch_value("SELECT libelle FROM familles_achat WHERE id=?", [$old['famille_id']]) : '—';
            $nouvelle = $famille_id ? db_fetch_value("SELECT libelle FROM familles_achat WHERE id=?", [$famille_id]) : '—';
            audit_log($user['id'], 'UPDATE', 'articles', $id, "Reclassement article {$old['code']} : famille « $ancienne » → « $nouvelle »");
        }
        audit_log($user['id'], 'UPDATE', 'articles', $id, "Modification article {$old['code']}", $old);
        json_response(true, 'Article mis à jour.');
    }

    // ── RÉCEPTION FOURNISSEUR (entrée en stock central)
    if ($action === 'reception') {
        require_permission('consommables', 'can_create');
        $article_id = (int)($_POST['article_id']     ?? 0);
        $qte        = (int)($_POST['quantite']        ?? 0);
        $prix_unit  = (int)($_POST['prix_unitaire']   ?? 0);
        $date_rec   = trim($_POST['date_reception']   ?? date('Y-m-d'));
        $fourn      = trim($_POST['fournisseur']      ?? '');
        $bon        = trim($_POST['numero_bon']       ?? '');
        $notes      = trim($_POST['notes']            ?? '');

        if (!$article_id || $qte <= 0)
            json_response(false, 'Article et quantité (> 0) obligatoires.');

        $article = db_fetch_one("SELECT * FROM articles WHERE id=?", [$article_id]);
        if (!$article) json_response(false, 'Article introuvable.');

        db_begin();
        try {
            $num_rec = 'RF-'.date('Ymd').'-'.str_pad(rand(1,999),3,'0',STR_PAD_LEFT);
            $rec_id  = null;

            // Enregistrer la réception globale si table receptions_fournisseur existe
            $has_rf = (bool)db_fetch_value("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='receptions_fournisseur') > 0");
            if ($has_rf) {
                db_query(
                    "INSERT INTO receptions_fournisseur (numero_reception,fournisseur,date_reception,statut,notes,created_by)
                     VALUES (?,?,?,'recu_complet',?,?)",
                    [$num_rec, $fourn, $date_rec, $notes, $user['id']]
                );
                $rec_id = (int)db_last_id();
                db_query(
                    "INSERT INTO reception_lignes (reception_id,article_id,libelle,quantite_attendue,quantite_recue,unite,prix_unitaire)
                     VALUES (?,?,?,?,?,?,?)",
                    [$rec_id, $article_id, $article['libelle'], $qte, $qte, $article['unite'], $prix_unit]
                );
            }

            // Compat legacy : enregistrer aussi dans receptions_consommables si elle existe
            $has_old = (bool)db_fetch_value("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='receptions_consommables') > 0");
            if ($has_old) {
                db_query(
                    "INSERT INTO receptions_consommables (consommable_id,quantite,prix_unitaire,prix_total,date_reception,fournisseur,numero_bon,notes,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$article_id, $qte, $prix_unit, $qte*$prix_unit, $date_rec, $fourn, $bon, $notes, $user['id']]
                );
            }

            // Mettre à jour stock global
            db_query("UPDATE articles SET stock_global = stock_global + ?, prix_unitaire = CASE WHEN ? > 0 THEN ? ELSE prix_unitaire END WHERE id=?",
                [$qte, $prix_unit, $prix_unit, $article_id]);

            // Enregistrer mouvement
            $new_stock = (int)db_fetch_value("SELECT stock_global FROM articles WHERE id=?", [$article_id]);
            if ((bool)db_fetch_value("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mouvements_stock') > 0")) {
                db_query(
                    "INSERT INTO mouvements_stock (article_id,type_mouvement,quantite,solde_apres,reference,notes,created_by)
                     VALUES (?,?,?,?,?,?,?)",
                    [$article_id, 'reception_fourn', $qte, $new_stock, $fourn.' '.$bon, $notes, $user['id']]
                );
            }

            audit_log($user['id'], 'CREATE', 'articles', $article_id,
                "Réception $qte {$article['unite']}(s) de {$article['libelle']} (fournisseur: $fourn)");
            db_commit();
            json_response(true, "Réception de $qte {$article['unite']}(s) enregistrée. Stock central : $new_stock.");
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    // ── DISTRIBUTION (sortie stock vers site)
    if ($action === 'distribution') {
        require_permission('consommables', 'can_create');
        $article_id = (int)($_POST['article_id'] ?? 0);
        $site_id    = (int)($_POST['site_id']     ?? 0);
        $qte        = (int)($_POST['quantite']    ?? 0);
        $prix_unit  = (int)($_POST['prix_unitaire'] ?? 0);
        $date_liv   = trim($_POST['date_livraison'] ?? date('Y-m-d'));
        $bl         = trim($_POST['bon_livraison']  ?? '');
        $notes      = trim($_POST['notes']          ?? '');

        if (!$article_id || !$site_id || $qte <= 0)
            json_response(false, 'Article, site et quantité (> 0) obligatoires.');

        $article = db_fetch_one("SELECT * FROM articles WHERE id=?", [$article_id]);
        if (!$article) json_response(false, 'Article introuvable.');
        if ($article['stock_global'] < $qte)
            json_response(false, "Stock insuffisant. Disponible : {$article['stock_global']} {$article['unite']}(s).");

        $upload_bl = upload_document('fichier_bl', 'bl', 'bl_art_'.$article_id.'_site_'.$site_id, true);
        if (!$upload_bl['success']) json_response(false, $upload_bl['message']);

        db_begin();
        try {
            $num_dist = 'DS-'.date('Ymd').'-'.str_pad(rand(1,999),3,'0',STR_PAD_LEFT);

            // compat legacy livraisons_consommables
            $has_lc = (bool)db_fetch_value("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='livraisons_consommables') > 0");
            if ($has_lc) {
                db_query(
                    "INSERT INTO livraisons_consommables (consommable_id,site_id,type_mouvement,quantite,prix_unitaire,prix_total,date_livraison,bon_livraison,fichier_bl,notes,created_by)
                     VALUES (?,?,'distribution',?,?,?,?,?,?,?,?)",
                    [$article_id,$site_id,$qte,$prix_unit,$qte*$prix_unit,$date_liv,$bl,$upload_bl['filename'],$notes,$user['id']]
                );
                $liv_id = (int)db_last_id();
            }

            // Nouvelle table distributions_site
            $has_ds = (bool)db_fetch_value("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='distributions_site') > 0");
            $dist_id = null;
            if ($has_ds) {
                db_query(
                    "INSERT INTO distributions_site (numero_distribution,site_id,date_distribution,statut,notes,fichier_bl,created_by,expedie_at)
                     VALUES (?,?,?,'en_cours_livraison',?,?,?,NOW())",
                    [$num_dist,$site_id,$date_liv,$notes,$upload_bl['filename'],$user['id']]
                );
                $dist_id = (int)db_last_id();
                db_query(
                    "INSERT INTO distribution_lignes (distribution_id,article_id,libelle,quantite_envoyee,unite,statut)
                     VALUES (?,?,?,?,?,'en_cours_livraison')",
                    [$dist_id,$article_id,$article['libelle'],$qte,$article['unite']]
                );
            }

            // Stock global -
            db_query("UPDATE articles SET stock_global = stock_global - ? WHERE id=?", [$qte, $article_id]);
            // Stock site +
            db_query("INSERT INTO stock_site (article_id,site_id,quantite) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite)",
                [$article_id,$site_id,$qte]);

            // Réception site en_attente
            db_query(
                "INSERT INTO receptions_site (site_id,type_reception,consommable_id,quantite,date_reception,statut,created_by)
                 VALUES (?,?,?,?,?,?,?)",
                [$site_id,'consommable',$article_id,$qte,$date_liv,'en_attente',$user['id']]
            );

            // Mouvement stock
            $new_stock = (int)db_fetch_value("SELECT stock_global FROM articles WHERE id=?", [$article_id]);
            if ((bool)db_fetch_value("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mouvements_stock') > 0")) {
                db_query(
                    "INSERT INTO mouvements_stock (article_id,site_id,type_mouvement,quantite,solde_apres,reference,notes,created_by)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$article_id,$site_id,'distribution',$qte,$new_stock,$bl,$notes,$user['id']]
                );
            }

            // Alerte stock bas
            if ($new_stock <= $article['seuil_alerte']) {
                notif_create('stock_bas', "⚠️ Stock bas — {$article['libelle']}",
                    "Stock restant : <strong>$new_stock {$article['unite']}(s)</strong> (seuil : {$article['seuil_alerte']}).",
                    null, '/pages/articles.php', false);
            }
            audit_log($user['id'], 'CREATE', 'articles', $article_id,
                "Distribution $qte {$article['unite']}(s) → site:$site_id (BL:{$upload_bl['filename']})");
            db_commit();
            json_response(true, "Distribution de $qte {$article['unite']}(s) enregistrée. Stock restant : $new_stock.");
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : ' . $e->getMessage());
        }
    }

    // ── GET ONE
    if ($action === 'get_article') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_fetch_one("SELECT * FROM articles WHERE id=?", [$id]);
        if (!$row) json_response(false, 'Introuvable.');
        $row['stock_par_site'] = db_fetch_all(
            "SELECT ss.quantite, s.nom AS site_nom, s.id AS site_id
             FROM stock_site ss JOIN sites s ON s.id=ss.site_id
             WHERE ss.article_id=? ORDER BY s.nom", [$id]
        );
        $row['receptions'] = db_fetch_all(
            "SELECT r.date_reception, r.quantite, r.prix_unitaire,
                    r.fournisseur, r.numero_bon, CONCAT(u.prenom,' ',u.nom) AS agent
             FROM receptions_consommables r
             LEFT JOIN users u ON u.id=r.created_by
             WHERE r.consommable_id=? ORDER BY r.date_reception DESC LIMIT 8", [$id]
        );
        $row['distributions'] = db_fetch_all(
            "SELECT lc.date_livraison, lc.quantite, s.nom AS site,
                    lc.bon_livraison, CONCAT(u.prenom,' ',u.nom) AS agent
             FROM livraisons_consommables lc
             JOIN sites s ON s.id=lc.site_id
             LEFT JOIN users u ON u.id=lc.created_by
             WHERE lc.consommable_id=? AND lc.type_mouvement='distribution'
             ORDER BY lc.date_livraison DESC LIMIT 8", [$id]
        );
        json_response(true, '', $row);
    }

    // ── AJUSTEMENT STOCK
    if ($action === 'ajustement') {
        require_permission('consommables', 'can_update');
        $article_id = (int)($_POST['article_id'] ?? 0);
        $site_id    = (int)($_POST['site_id']     ?? 0);
        $new_qte    = (int)($_POST['new_qte']     ?? 0);
        $motif      = trim($_POST['motif']        ?? '');
        if (!$article_id || !$site_id) json_response(false, 'Article et site obligatoires.');
        $old_qte = (int)(db_fetch_value(
            "SELECT quantite FROM stock_site WHERE article_id=? AND site_id=?",
            [$article_id, $site_id]) ?? 0);
        $diff = $new_qte - $old_qte;
        db_begin();
        try {
            db_query("INSERT INTO stock_site (article_id,site_id,quantite) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantite=VALUES(quantite)",
                [$article_id,$site_id,$new_qte]);
            db_query("UPDATE articles SET stock_global = stock_global + ? WHERE id=?", [$diff, $article_id]);
            audit_log($user['id'],'UPDATE','articles',$article_id,
                "Ajustement site:$site_id : $old_qte → $new_qte ($motif)");
            db_commit();
            json_response(true, 'Stock ajusté.');
        } catch (Exception $e) { db_rollback(); json_response(false, 'Erreur : '.$e->getMessage()); }
    }

    json_response(false, 'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$is_coord   = $role_slug === 'coordinateur_site';
$site_force = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;

$search   = trim($_GET['q']       ?? '');
$f_alerte = (int)($_GET['alerte'] ?? 0);
$f_type   = trim($_GET['type']    ?? '');
// Filtre site manuel (superviseur achat, etc.) — remplace l'ancien écran
// « Stock magasin » d'Achats, jamais consulté en pratique : au lieu d'un
// écran séparé, un site (magasin ou non) se choisit ici, et le nombre
// affiché sur chaque carte devient alors sa quantité sur CE site plutôt
// que le stock central agrégé.
$f_site      = $site_force ?: (int)($_GET['site'] ?? 0);
$where = ['1=1']; $params = [];
if ($search)   { $where[] = '(a.code LIKE ? OR a.libelle LIKE ?)'; $s="%$search%"; $params=[$s,$s]; }
if ($f_alerte) { $where[] = 'a.stock_global <= a.seuil_alerte'; }
if ($f_type)   { $where[] = 'a.type_article=?'; $params[] = $f_type; }
if ($f_site) {
    $where[] = 'EXISTS (SELECT 1 FROM stock_site ss WHERE ss.article_id=a.id AND ss.site_id=?)';
    $params[] = $f_site;
}
$wsql = implode(' AND ', $where);

$articles_list = db_fetch_all(
    "SELECT a.*,
            COUNT(DISTINCT ss.site_id) AS nb_sites,
            COALESCE(ss_f.quantite, 0) AS stock_site_filtre,
            COALESCE((SELECT SUM(quantite) FROM receptions_consommables r
                      WHERE r.consommable_id=a.id
                        AND r.date_reception >= (CURRENT_DATE - INTERVAL 30 DAY)),0) AS receptions_30j,
            COALESCE((SELECT SUM(quantite) FROM livraisons_consommables l
                      WHERE l.consommable_id=a.id AND l.type_mouvement='distribution'
                        AND l.date_livraison >= (CURRENT_DATE - INTERVAL 30 DAY)),0) AS distributions_30j
     FROM articles a
     LEFT JOIN stock_site ss ON ss.article_id=a.id
     LEFT JOIN stock_site ss_f ON ss_f.article_id=a.id AND ss_f.site_id=?
     WHERE $wsql GROUP BY a.id, ss_f.quantite ORDER BY a.type_article, a.libelle",
    array_merge([$f_site ?: 0], $params)
);

$sites_list  = db_fetch_all("SELECT id,nom,type FROM sites WHERE actif=1 ORDER BY nom");
$familles_achat = db_fetch_all("SELECT id, code, libelle FROM familles_achat WHERE actif=1 ORDER BY libelle");

// Saisie libre (hors référentiel) au magasin : lignes FEB sans article_id,
// jamais présentes dans stock_site — reprise de l'ancien écran Achats
// « Stock magasin », visible seulement pour qui suit les achats.
$lignes_libres = [];
if (can('achats_suivi', 'can_read')) {
    $where_libre  = ["fl.article_id IS NULL", "NOT (fl.type_achat <=> 'DAI')", "(fs.quantite_recue - fs.quantite_expediee) > 0", "s.type = 'magasin'"];
    $params_libre = [];
    if ($f_site)  { $where_libre[] = 'fs.site_id = ?'; $params_libre[] = $f_site; }
    if ($search)  { $where_libre[] = 'fl.designation LIKE ?'; $params_libre[] = '%' . $search . '%'; }
    $lignes_libres = db_fetch_all(
        "SELECT f.numero AS feb_numero, fl.designation, fl.unite, s.nom AS site_nom,
                (fs.quantite_recue - fs.quantite_expediee) AS quantite_magasin
         FROM feb_suivi fs
         JOIN feb_lignes fl ON fl.id = fs.feb_ligne_id
         JOIN feb f         ON f.id  = fs.feb_id
         JOIN sites s       ON s.id = fs.site_id
         WHERE " . implode(' AND ', $where_libre) . "
         ORDER BY f.numero",
        $params_libre
    );
}

$kpi_total   = count($articles_list);
$kpi_alertes = count(array_filter($articles_list, fn($a) => $a['stock_global'] <= $a['seuil_alerte']));

// Valorisation (quantité × prix_unitaire) — reprise de l'ancien écran Achats
// « Stock magasin » (fusionné ici le 2026-08-26, cf. commit 345f164), qui
// avait ajouté cette colonne un jour avant la fusion sans qu'elle soit
// reportée. Même calcul que Stock par département : $stock_affiche suit le
// même filtre site que l'affichage des cartes ci-dessous.
$total_valorisation = 0;
foreach ($articles_list as $a) {
    $qte = $f_site ? (int)$a['stock_site_filtre'] : (int)$a['stock_global'];
    $total_valorisation += $qte * (int)($a['prix_unitaire'] ?? 0);
}
$kpi_receptions_mois = (int)db_fetch_value("SELECT COALESCE(SUM(quantite),0) FROM receptions_consommables WHERE date_reception >= DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')");
$kpi_distrib_mois    = (int)db_fetch_value("SELECT COALESCE(SUM(quantite),0) FROM livraisons_consommables WHERE type_mouvement='distribution' AND date_livraison >= DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')");

include __DIR__ . '/../templates/header.php';
?>
<style>
.art-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:24px}
.art-card{background:white;border:1.5px solid var(--border);border-radius:14px;overflow:hidden;transition:box-shadow .2s,transform .2s;cursor:pointer}
.art-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.09);transform:translateY(-2px)}
.art-card.alerte{border-left:4px solid var(--danger)}
.art-card.warning{border-left:4px solid var(--warning)}
.ac-header{padding:13px 15px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.ac-type-badge{padding:2px 8px;border-radius:20px;font-size:12px;font-weight:700;background:var(--primary-l);color:var(--primary-d)}
.ac-code{width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#06033A,#1B75BC);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;letter-spacing:.5px;text-align:center;padding:4px;flex-shrink:0}
.ac-info h4{font-size:13px;font-weight:700;color:var(--navy);margin-bottom:2px}
.ac-info span{font-size:12px;color:var(--muted)}
.ac-stock{padding:12px 15px;display:flex;align-items:center;gap:12px}
.ac-stock-num{font-size:26px;font-weight:800;color:var(--navy);line-height:1;font-family:'Plus Jakarta Sans',sans-serif}
.stock-bar{height:5px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:4px}
.stock-fill{height:100%;border-radius:3px}
.ac-footer{padding:8px 15px;background:var(--tertiary);display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--border);font-size:11.5px}
.tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;overflow-x:auto;gap:0}
.tab-btn{padding:11px 18px;font-size:13px;font-weight:500;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;background:none;border-top:none;border-left:none;border-right:none;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;white-space:nowrap;font-family:'Manrope',sans-serif}
.tab-btn.active{color:var(--primary-d);border-bottom-color:var(--primary-d)}
.tab-pane{display:none}.tab-pane.active{display:block}
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(10,22,40,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;max-width:95vw;max-height:92vh;overflow-y:auto;animation:mIn .25s cubic-bezier(.22,1,.36,1)}
@keyframes mIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
.mhdr{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:10}
.mhdr h3{font-size:16px;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif}
.mclose{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.mbody{padding:22px}
.mfoot{padding:13px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:white}
.flux-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:700}
.flux-badge.reception{background:#e8f5e9;color:#2e7d32}
.flux-badge.distribution{background:#e3f2fd;color:#1565c0}
</style>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
  <?php foreach([
    ['ph-cube',       'Articles',       $kpi_total,                   '',             '#1B75BC', false],
    ['ph-warning',    'Alertes stock',  $kpi_alertes,                 'à réappro',    '#F87171', true],
    ['ph-arrow-down', 'Reçu ce mois',   fmt_number($kpi_receptions_mois), 'en stock', '#34D399', false],
    ['ph-arrow-up',   'Distribué',      fmt_number($kpi_distrib_mois),    'vs sites',  '#7C92FF', false],
  ] as [$ico,$lbl,$val,$sub,$col,$cliquable]): ?>
  <div<?= $cliquable ? ' onclick="filtrerAlertes()" role="button" tabindex="0" onkeydown="if(event.key===\'Enter\')filtrerAlertes()"' : '' ?>
       style="background:white;border:1.5px solid var(--border);border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:14px;border-top:3px solid <?= $col ?><?= $cliquable ? ';cursor:pointer' : '' ?>">
    <div style="width:44px;height:44px;border-radius:12px;background:var(--tertiary);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="ph-duotone <?= $ico ?>" style="font-size:22px;color:<?= $col ?>"></i>
    </div>
    <div>
      <div style="font-size:24px;font-weight:800;color:var(--navy);line-height:1;font-family:'Plus Jakarta Sans',sans-serif"><?= $val ?></div>
      <div style="font-size:11.5px;color:var(--muted);margin-top:3px"><?= $lbl ?><?= $sub?" · $sub":'' ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- TABS -->
<div class="tabs" id="mainTabs">
  <button class="tab-btn active" onclick="showTab('stock',this)"><i class="ph-duotone ph-cube"></i> Stock articles</button>
  <?php if(can('consommables','can_create')): ?>
  <button class="tab-btn" onclick="showTab('reception',this)"><i class="ph-duotone ph-arrow-down"></i> Réception fournisseur</button>
  <button class="tab-btn" onclick="showTab('distribution',this)"><i class="ph-duotone ph-arrow-up"></i> Distribution site</button>
  <?php endif; ?>
  <button class="tab-btn" onclick="showTab('historique',this)"><i class="ph-duotone ph-list"></i> Historique</button>
</div>

<!-- ═══ TAB STOCK ═══ -->
<div class="tab-pane active" id="tab-stock">
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
    <div style="position:relative;flex:1;min-width:200px">
      <i class="ph-duotone ph-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted)"></i>
      <input type="text" id="searchQ" value="<?= h($search) ?>" placeholder="Rechercher un article…" aria-label="Rechercher un article"
        style="width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;outline:none;font-family:'Manrope',sans-serif"
        oninput="filterArticles(this.value)">
    </div>
    <select id="filterType" onchange="filterArticles()" aria-label="Filtrer par type d'article"
      style="padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none;font-family:'Manrope',sans-serif">
      <option value="">Tous les types</option>
      <?php foreach($TYPES_ARTICLE as $k=>$l): ?>
      <option value="<?= $k ?>" <?= $f_type===$k?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$is_coord): ?>
    <select id="filterSite" onchange="filterBySite(this.value)" aria-label="Filtrer par site"
      style="padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none;font-family:'Manrope',sans-serif">
      <option value="">Tous les sites (stock central)</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site===(int)$s['id']?'selected':'' ?>><?= h($s['nom']) ?><?= $s['type']==='magasin'?' (magasin)':'' ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;background:white">
      <input type="checkbox" id="alerteOnly" <?= $f_alerte?'checked':'' ?> onchange="filterArticles()">
      <i class="ph-duotone ph-warning" style="color:var(--warning-d)"></i> Alertes
    </label>
    <?php if(can('consommables','can_create')): ?>
    <button class="btn btn-primary" onclick="openMA()">
      <i class="ph-duotone ph-plus"></i> Nouvel article
    </button>
    <?php endif; ?>
  </div>

  <?php if (can('achats_suivi', 'can_read')): ?>
  <div class="ach-summary" style="margin-bottom:14px;font-size:13px;color:var(--muted)">
    <?= $kpi_total ?> article(s) — <strong style="color:var(--navy)"><?= fmt_number((float)$total_valorisation) ?> FCFA</strong> valorisés<?= $f_site ? '' : ' (stock central)' ?>
  </div>
  <?php endif; ?>

  <div class="art-grid" id="artGrid">
  <?php foreach($articles_list as $a):
    // Un site est filtré : la carte montre sa quantité sur CE site plutôt
    // que le stock central agrégé (remplace l'ancien écran « Stock magasin »).
    $stock_affiche = $f_site ? (int)$a['stock_site_filtre'] : (int)$a['stock_global'];
    $ratio = $a['seuil_alerte']>0 ? $stock_affiche/$a['seuil_alerte'] : 2;
    $pct   = min(100, $ratio * 100);
    $cls   = $ratio<=0?'alerte':($ratio<=1?'warning':'');
    $fill  = $ratio<=0.5?'var(--danger)':($ratio<=1?'var(--warning)':'var(--success)');
  ?>
  <div class="art-card <?= $cls ?>" onclick="viewArticle(<?= $a['id'] ?>)"
       data-lib="<?= strtolower(h($a['libelle'])) ?>"
       data-type="<?= h($a['type_article']) ?>"
       data-alerte="<?= $cls?'1':'0' ?>">
    <div class="ac-header">
      <div class="ac-code"><?= h($a['code']) ?></div>
      <div class="ac-info" style="flex:1;min-width:0">
        <h4><?= h($a['libelle']) ?></h4>
        <div style="display:flex;gap:5px;align-items:center">
          <span class="ac-type-badge"><?= $TYPES_ARTICLE[$a['type_article']]??$a['type_article'] ?></span>
          <span style="font-size:12px;color:var(--muted)"><?= $UNITES[$a['unite']]??$a['unite'] ?></span>
        </div>
      </div>
      <?php if($ratio<=0): ?><span style="font-size:18px"><i class="ph ph-circle" aria-hidden="true"></i></span>
      <?php elseif($cls): ?><span style="font-size:18px"><i class="ph ph-circle" aria-hidden="true"></i></span>
      <?php endif; ?>
    </div>
    <div class="ac-stock">
      <div>
        <div class="ac-stock-num" style="color:<?= $ratio<=0?'var(--danger-d)':($ratio<=1?'var(--warning-d)':'var(--navy)') ?>">
          <?= fmt_number($stock_affiche) ?>
        </div>
        <div style="font-size:10.5px;color:var(--muted)"><?= $UNITES[$a['unite']]??$a['unite'] ?></div>
      </div>
      <div style="flex:1">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
          <span style="color:var(--muted)">Seuil : <?= $a['seuil_alerte'] ?></span>
          <span style="color:<?= $cls?'var(--danger-d)':'var(--success-d)' ?>;font-weight:700"><?= round($pct) ?>%</span>
        </div>
        <div class="stock-bar"><div class="stock-fill" style="width:<?= $pct ?>%;background:<?= $fill ?>"></div></div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px;display:flex;gap:8px">
          <?php if($a['receptions_30j']>0): ?><span>+<?= fmt_number($a['receptions_30j']) ?> reçus</span><?php endif; ?>
          <?php if($a['distributions_30j']>0): ?><span>-<?= fmt_number($a['distributions_30j']) ?> distrib.</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="ac-footer">
      <span style="color:var(--muted)">Seuil : <?= $a['seuil_alerte'] ?></span>
      <?php if(!empty($a['prix_unitaire'])&&$a['prix_unitaire']>0): ?>
      <span style="font-weight:700;color:var(--primary-d)"><?= fmt_number($a['prix_unitaire']) ?> FCFA/<?= $a['unite'] ?></span>
      <?php endif; ?>
    </div>
    <?php if (can('achats_suivi', 'can_read') && !empty($a['prix_unitaire']) && $a['prix_unitaire']>0): ?>
    <div class="ac-footer" style="border-top:1px dashed var(--border);padding-top:6px;margin-top:2px">
      <span style="color:var(--muted)">Valorisation</span>
      <span style="font-weight:700;color:var(--navy)"><?= fmt_number($stock_affiche * (int)$a['prix_unitaire']) ?> FCFA</span>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if(empty($articles_list)): ?>
  <div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--muted)">
    <i class="ph-duotone ph-cube" style="font-size:48px;color:var(--border)"></i>
    <p style="margin-top:12px">Aucun article trouvé.</p>
  </div>
  <?php endif; ?>
  </div>

  <?php if (can('achats_suivi', 'can_read') && !empty($lignes_libres)): ?>
  <div class="card" style="margin-top:20px">
    <div class="card-header"><h3><i class="ph-duotone ph-note"></i> Saisie libre (hors référentiel)</h3></div>
    <div class="card-body" style="padding:0">
      <div style="overflow-x:auto">
      <table class="ach-table" style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr>
          <th style="background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)">FEB</th>
          <th style="background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)">Désignation</th>
          <th style="background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)">Site</th>
          <th style="background:#f8fafc;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)">Quantité</th>
        </tr></thead>
        <tbody>
          <?php foreach($lignes_libres as $ll): ?>
          <tr>
            <td style="padding:10px 14px;border-bottom:1px solid var(--border);font-family:monospace;color:var(--muted)"><?= h($ll['feb_numero']) ?></td>
            <td style="padding:10px 14px;border-bottom:1px solid var(--border)"><?= h($ll['designation']) ?></td>
            <td style="padding:10px 14px;border-bottom:1px solid var(--border)"><?= h($ll['site_nom']) ?></td>
            <td style="padding:10px 14px;border-bottom:1px solid var(--border);font-weight:700"><?= fmt_number($ll['quantite_magasin']) ?> <?= h($ll['unite'] ?: '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ TAB RÉCEPTION ═══ -->
<div class="tab-pane" id="tab-reception">
  <div style="max-width:580px">
    <div class="alert alert-info" style="margin-bottom:20px">
      <i class="ph-duotone ph-arrow-down"></i>
      <strong>Réception fournisseur</strong> — Enregistrez les livraisons reçues du fournisseur. Le stock central augmente.
    </div>
    <div class="card">
      <div class="card-header"><h3><i class="ph-duotone ph-arrow-down"></i> Nouvelle réception</h3></div>
      <div class="card-body">
        <div id="recAlert"></div>
        <div class="form-group"><label>Article *</label>
          <select class="form-control" id="recArt" onchange="updStockActuel('rec')">
            <option value="">— Sélectionner —</option>
            <?php foreach($articles_list as $a): ?>
            <option value="<?= $a['id'] ?>"
                    data-unite="<?= $a['unite'] ?>"
                    data-stock="<?= $a['stock_global'] ?>"
                    data-prix="<?= $a['prix_unitaire']??0 ?>">
              [<?= h($a['code']) ?>] <?= h($a['libelle']) ?> (stock: <?= $a['stock_global'] ?> <?= $a['unite'] ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="recStockInfo" style="display:none;margin-bottom:14px">
          <div style="padding:12px;border-radius:9px;background:var(--tertiary);border:1px solid var(--border);display:flex;align-items:center;gap:12px" id="recStockIndicator">
            <div style="font-size:22px;font-weight:800;color:var(--navy);font-family:'Plus Jakarta Sans',sans-serif" id="recStockVal">0</div>
            <div>
              <div style="font-size:12px;color:var(--muted);font-weight:600">Stock actuel</div>
              <div style="font-size:12px" id="recStockMsg"></div>
            </div>
          </div>
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Quantité reçue *</label>
            <input type="number" class="form-control" id="recQte" min="1" step="1" placeholder="0">
          </div>
          <div class="form-group"><label>Prix unitaire (FCFA)</label>
            <input type="number" class="form-control" id="recPrix" min="0" step="1" placeholder="0">
          </div>
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Date de réception</label>
            <input type="date" class="form-control" id="recDate" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group"><label>Fournisseur</label>
            <input type="text" class="form-control" id="recFourn" placeholder="Nom du fournisseur">
          </div>
        </div>
        <div class="form-group"><label>N° bon de livraison fournisseur</label>
          <input type="text" class="form-control" id="recBon" placeholder="BLF-2025-001">
        </div>
        <div class="form-group"><label>Notes</label>
          <textarea class="form-control" id="recNotes" rows="2" placeholder="Remarques…"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button class="btn btn-secondary" onclick="resetRec()">Réinitialiser</button>
          <button class="btn btn-primary" onclick="saveRec()"><i class="ph-duotone ph-arrow-down"></i> Valider la réception</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ TAB DISTRIBUTION ═══ -->
<div class="tab-pane" id="tab-distribution">
  <div style="max-width:580px">
    <div class="alert alert-info" style="margin-bottom:20px">
      <i class="ph-duotone ph-arrow-up"></i>
      <strong>Distribution vers site</strong> — Envoyez des articles depuis le stock central vers un site.
    </div>
    <div class="card">
      <div class="card-header"><h3><i class="ph-duotone ph-arrow-up"></i> Nouvelle distribution</h3></div>
      <div class="card-body">
        <div id="distAlert"></div>
        <div class="form-group"><label>Article *</label>
          <select class="form-control" id="distArt" onchange="updStockActuel('dist')">
            <option value="">— Sélectionner —</option>
            <?php foreach($articles_list as $a): ?>
            <option value="<?= $a['id'] ?>"
                    data-unite="<?= $a['unite'] ?>"
                    data-stock="<?= $a['stock_global'] ?>"
                    data-prix="<?= $a['prix_unitaire']??0 ?>"
                    data-seuil="<?= $a['seuil_alerte'] ?>">
              [<?= h($a['code']) ?>] <?= h($a['libelle']) ?> (dispo: <?= $a['stock_global'] ?> <?= $a['unite'] ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="distStockInfo" style="display:none;margin-bottom:14px">
          <div style="padding:12px;border-radius:9px;display:flex;align-items:center;gap:12px" id="distStockIndicator">
            <div style="font-size:22px;font-weight:800;font-family:'Plus Jakarta Sans',sans-serif" id="distStockVal">0</div>
            <div>
              <div style="font-size:12px;color:var(--muted);font-weight:600">Disponible en stock central</div>
              <div style="font-size:12px" id="distStockMsg"></div>
            </div>
          </div>
        </div>
        <div class="form-group"><label>Site destinataire *</label>
          <select class="form-control" id="distSite">
            <option value="">— Sélectionner —</option>
            <?php foreach($sites_list as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Quantité *</label>
            <input type="number" class="form-control" id="distQte" min="1" step="1" placeholder="0" oninput="checkDistQte()">
          </div>
          <div class="form-group"><label>Prix unitaire (FCFA)</label>
            <input type="number" class="form-control" id="distPrix" min="0" step="1" placeholder="0">
          </div>
        </div>
        <div id="distQteWarn" style="display:none" class="alert alert-danger">
          <i class="ph-duotone ph-warning"></i> Quantité supérieure au stock disponible !
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Date</label>
            <input type="date" class="form-control" id="distDate" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group"><label>Bon de sortie</label>
            <input type="text" class="form-control" id="distBL" placeholder="BS-2025-001">
          </div>
        </div>
        <div class="form-group" style="background:#fff8e7;border:2px dashed #f59e0b;border-radius:10px;padding:13px;margin-bottom:13px">
          <label style="font-size:13px;font-weight:700;color:#b7791f;display:block;margin-bottom:6px">
            <i class="ph-duotone ph-paperclip"></i> Bon de livraison <span style="color:var(--danger-d)">*</span>
            <span style="font-size:12px;font-weight:400;color:var(--muted)"> — PDF ou image obligatoire</span>
          </label>
          <input type="file" id="distFichierBL" accept=".pdf,.jpg,.jpeg,.png,.webp"
            style="width:100%;padding:8px;border:1.5px solid #f59e0b;border-radius:8px;font-size:13px;background:white">
          <div id="distBLPreview" style="display:none;margin-top:6px;font-size:12px;color:var(--success-d);font-weight:600"></div>
        </div>
        <div class="form-group"><label>Notes</label>
          <textarea class="form-control" id="distNotes" rows="2" placeholder="Destination, motif…"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button class="btn btn-secondary" onclick="resetDist()">Réinitialiser</button>
          <button class="btn btn-primary" id="btnDist" onclick="saveDist()"><i class="ph-duotone ph-arrow-up"></i> Valider la distribution</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ TAB HISTORIQUE ═══ -->
<div class="tab-pane" id="tab-historique">
  <div class="card">
    <div class="card-header">
      <h3><i class="ph-duotone ph-list"></i> Historique mouvements (80 derniers)</h3>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Date</th><th>Type</th><th>Article</th>
          <th style="text-align:right">Quantité</th>
          <th>Référence / Site</th><th>Agent</th>
        </tr></thead>
        <tbody>
        <?php
        $historique = db_fetch_all(
            "SELECT 'reception' AS type_op, r.date_reception AS date_op, a.code AS art_code,
                    a.libelle AS art_lib, a.unite, r.quantite, NULL AS site_nom,
                    r.fournisseur AS ref1, r.numero_bon AS ref2,
                    CONCAT(u.prenom,' ',u.nom) AS agent
             FROM receptions_consommables r
             JOIN articles a ON a.id=r.consommable_id
             LEFT JOIN users u ON u.id=r.created_by
             UNION ALL
             SELECT 'distribution', lc.date_livraison, a.code, a.libelle, a.unite,
                    lc.quantite, s.nom, lc.bon_livraison, NULL,
                    CONCAT(u.prenom,' ',u.nom)
             FROM livraisons_consommables lc
             JOIN articles a ON a.id=lc.consommable_id
             JOIN sites s ON s.id=lc.site_id
             LEFT JOIN users u ON u.id=lc.created_by
             WHERE lc.type_mouvement='distribution'
             ORDER BY date_op DESC LIMIT 80"
        );
        if(empty($historique)): ?>
          <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--muted)">Aucun mouvement.</td></tr>
        <?php else: foreach($historique as $h): ?>
          <tr>
            <td style="font-size:12px;white-space:nowrap"><?= fmt_date($h['date_op']) ?></td>
            <td><span class="flux-badge <?= $h['type_op'] ?>"><?= $h['type_op']==='reception'?'Réception':'Distribution' ?></span></td>
            <td><strong style="font-size:11.5px;color:var(--navy)"><?= h($h['art_code']) ?></strong> <?= h($h['art_lib']) ?></td>
            <td style="text-align:right;font-weight:800;font-size:15px;color:<?= $h['type_op']==='reception'?'var(--success-d)':'var(--primary-d)' ?>">
              <?= $h['type_op']==='reception'?'+':'-' ?><?= fmt_number($h['quantite']) ?>
              <span style="font-size:12px;font-weight:400;color:var(--muted)"><?= $h['unite'] ?></span>
            </td>
            <td style="font-size:12px"><?= $h['type_op']==='reception' ? h($h['ref1']??'—') : h($h['site_nom']??'—') ?></td>
            <td style="font-size:12px"><?= h($h['agent']??'—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL CRÉER/MODIFIER ARTICLE -->
<div class="modal-overlay" id="mA">
  <div class="modal" style="width:540px">
    <div class="mhdr"><h3 id="mAT">Nouvel article</h3><button class="mclose" onclick="closeMA()"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody">
      <div id="mAAlert"></div>
      <input type="hidden" id="aId">
      <div class="form-row cols-2">
        <div class="form-group"><label>Code *</label>
          <input type="text" class="form-control" id="aCode" oninput="this.value=this.value.toUpperCase()" maxlength="30">
        </div>
        <div class="form-group"><label>Unité *</label>
          <select class="form-control" id="aUnite">
            <?php foreach($UNITES as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Libellé *</label>
        <input type="text" class="form-control" id="aLib" placeholder="Nom de l'article…">
      </div>
      <div class="form-group"><label>Type d'article</label>
        <select class="form-control" id="aType">
          <?php foreach($TYPES_ARTICLE as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Famille d'achat</label>
        <select class="form-control" id="aFamille">
          <option value="">— Aucune —</option>
          <?php foreach($familles_achat as $fa): ?>
            <option value="<?= $fa['id'] ?>"><?= h($fa['libelle']) ?> (<?= h($fa['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11.5px;color:var(--muted);margin-top:4px">Rattachement au référentiel achat (budget, comptabilité). Reclassable à tout moment, sans effet sur les FEB déjà émises.</div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Seuil d'alerte</label>
          <input type="number" class="form-control" id="aSeuil" value="10" min="0" step="1">
        </div>
        <div class="form-group"><label>Prix unitaire (FCFA)</label>
          <input type="number" class="form-control" id="aPrix" value="0" min="0" step="1">
        </div>
      </div>
      <div class="form-group"><label>Description</label>
        <textarea class="form-control" id="aDesc" rows="3" placeholder="Description…"></textarea>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-secondary" onclick="closeMA()">Annuler</button>
      <button class="btn btn-primary" id="bSA" onclick="saveArticle()"><i class="ph-duotone ph-floppy-disk"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- MODAL DÉTAIL ARTICLE -->
<div class="modal-overlay" id="mAD">
  <div class="modal" style="width:620px">
    <div class="mhdr"><h3 id="adT">Détail article</h3><button class="mclose" onclick="document.getElementById('mAD').classList.remove('open')"><i class="ph ph-x" aria-hidden="true"></i></button></div>
    <div class="mbody" id="adB">Chargement…</div>
  </div>
</div>

<script>
function showTab(id,btn){
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('#mainTabs .tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active'); btn.classList.add('active');
}
function filtrerAlertes(){
  document.getElementById('alerteOnly').checked = true;
  filterArticles();
  const stockTabBtn = document.querySelector('#mainTabs .tab-btn');
  if(stockTabBtn) showTab('stock', stockTabBtn);
  document.getElementById('artGrid')?.scrollIntoView({behavior:'smooth', block:'start'});
}
function filterArticles(){
  const q=document.getElementById('searchQ').value.toLowerCase();
  const a=document.getElementById('alerteOnly').checked;
  const t=document.getElementById('filterType').value;
  document.querySelectorAll('#artGrid .art-card').forEach(c=>{
    const mQ=!q||c.dataset.lib.includes(q);
    const mA=!a||c.dataset.alerte==='1';
    const mT=!t||c.dataset.type===t;
    c.style.display=(mQ&&mA&&mT)?'':'none';
  });
}
function filterBySite(siteId){
  const url=new URL(window.location.href);
  if(siteId) url.searchParams.set('site',siteId); else url.searchParams.delete('site');
  const q=document.getElementById('searchQ').value;
  if(q) url.searchParams.set('q',q); else url.searchParams.delete('q');
  const t=document.getElementById('filterType').value;
  if(t) url.searchParams.set('type',t); else url.searchParams.delete('type');
  window.location.href=url.toString();
}
function updStockActuel(pfx){
  const sel=document.getElementById(pfx+(pfx==='rec'?'Art':'Art'));
  const o=sel.options[sel.selectedIndex];
  if(!o||!o.value){document.getElementById(pfx+'StockInfo').style.display='none';return;}
  const stock=parseInt(o.dataset.stock||0),seuil=parseInt(o.dataset.seuil||0);
  const info=document.getElementById(pfx+'StockInfo');
  const ind=document.getElementById(pfx+'StockIndicator');
  const val=document.getElementById(pfx+'StockVal');
  const msg=document.getElementById(pfx+'StockMsg');
  info.style.display='block';
  val.textContent=stock.toLocaleString('fr-FR');
  ind.style.background=stock<=0?'#FEE2E2':stock<=seuil?'#FEF3C7':'#D1FAE5';
  val.style.color=stock<=0?'var(--danger-d)':stock<=seuil?'var(--warning-d)':'var(--success-d)';
  msg.innerHTML=stock<=0?'<span style="color:var(--danger-d);font-weight:600">Stock épuisé</span>':
                stock<=seuil?'<span style="color:var(--warning-d);font-weight:600">Stock bas</span>':
                '<span style="color:var(--success-d)">Stock suffisant</span>';
  if(o.dataset.prix>0){
    const pEl=document.getElementById(pfx==='rec'?'recPrix':'distPrix');
    if(pEl&&pEl.value==0) pEl.value=o.dataset.prix;
  }
}
function checkDistQte(){
  const sel=document.getElementById('distArt');
  const o=sel.options[sel.selectedIndex];
  const stock=o?parseInt(o.dataset.stock||0):0;
  const qte=parseInt(document.getElementById('distQte').value)||0;
  const warn=document.getElementById('distQteWarn');
  const btn=document.getElementById('btnDist');
  if(qte>stock&&stock>0){warn.style.display='block';btn.disabled=true;}
  else{warn.style.display='none';btn.disabled=false;}
}
function resetRec(){
  ['recArt','recFourn','recBon','recNotes'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('recQte').value=''; document.getElementById('recPrix').value='';
  document.getElementById('recDate').value='<?= date('Y-m-d') ?>';
  document.getElementById('recAlert').innerHTML=''; document.getElementById('recStockInfo').style.display='none';
}
function saveRec(){
  const aid=document.getElementById('recArt').value;
  const qte=document.getElementById('recQte').value;
  if(!aid||!qte||parseInt(qte)<=0){
    document.getElementById('recAlert').innerHTML='<div class="alert alert-danger">Article et quantité obligatoires.</div>'; return;
  }
  ap({action:'reception',article_id:aid,quantite:qte,
    prix_unitaire:document.getElementById('recPrix').value||0,
    date_reception:document.getElementById('recDate').value,
    fournisseur:document.getElementById('recFourn').value,
    numero_bon:document.getElementById('recBon').value,
    notes:document.getElementById('recNotes').value,
  }).then(d=>{
    if(d.success){toast(d.message,'success');resetRec();setTimeout(()=>location.reload(),800);}
    else document.getElementById('recAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
  });
}
function resetDist(){
  ['distArt','distSite','distBL','distNotes'].forEach(i=>document.getElementById(i).value='');
  ['distQte','distPrix'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('distDate').value='<?= date('Y-m-d') ?>';
  document.getElementById('distAlert').innerHTML=''; document.getElementById('distStockInfo').style.display='none';
  document.getElementById('distQteWarn').style.display='none'; document.getElementById('btnDist').disabled=false;
  document.getElementById('distFichierBL').value=''; document.getElementById('distBLPreview').style.display='none';
}
function saveDist(){
  const aid=document.getElementById('distArt').value;
  const sid=document.getElementById('distSite').value;
  const qte=document.getElementById('distQte').value;
  const fichierBL=document.getElementById('distFichierBL').files[0];
  if(!aid||!sid||!qte||parseInt(qte)<=0){
    document.getElementById('distAlert').innerHTML='<div class="alert alert-danger">Article, site et quantité obligatoires.</div>'; return;
  }
  if(!fichierBL){
    document.getElementById('distAlert').innerHTML='<div class="alert alert-danger">Le bon de livraison est obligatoire.</div>';
    document.getElementById('distFichierBL').focus(); return;
  }
  const btn=document.getElementById('btnDist');
  btn.disabled=true; btn.textContent='Envoi…';
  const fd=new FormData();
  fd.append('action','distribution'); fd.append('article_id',aid); fd.append('site_id',sid);
  fd.append('quantite',qte); fd.append('prix_unitaire',document.getElementById('distPrix').value||0);
  fd.append('date_livraison',document.getElementById('distDate').value);
  fd.append('bon_livraison',document.getElementById('distBL').value);
  fd.append('notes',document.getElementById('distNotes').value);
  fd.append('fichier_bl',fichierBL);
  fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
      btn.disabled=false; btn.innerHTML='<i class="ph-duotone ph-arrow-up"></i> Valider la distribution';
      if(d.success){toast(d.message,'success');resetDist();setTimeout(()=>location.reload(),800);}
      else document.getElementById('distAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
    }).catch(()=>{btn.disabled=false;btn.innerHTML='<i class="ph-duotone ph-arrow-up"></i> Valider';document.getElementById('distAlert').innerHTML='<div class="alert alert-danger">Erreur réseau.</div>';});
}
document.getElementById('distFichierBL').addEventListener('change',function(){
  const prev=document.getElementById('distBLPreview');
  if(this.files.length){prev.style.display='block';prev.textContent='✔ '+this.files[0].name;}
  else prev.style.display='none';
});
function openMA(){resetAF();document.getElementById('mA').classList.add('open');}
function closeMA(){document.getElementById('mA').classList.remove('open');}
function resetAF(){
  ['aId','aCode','aLib','aDesc'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('aSeuil').value='10'; document.getElementById('aPrix').value='0';
  document.getElementById('aUnite').value='unite'; document.getElementById('aType').value='autre';
  document.getElementById('aFamille').value='';
  document.getElementById('mAAlert').innerHTML=''; document.getElementById('mAT').textContent='Nouvel article';
  document.getElementById('aCode').disabled=false;
}
function saveArticle(){
  const id=document.getElementById('aId').value;
  const btn=document.getElementById('bSA'); btn.disabled=true;
  ap({action:id?'update_article':'create_article',id,
    code:document.getElementById('aCode').value,libelle:document.getElementById('aLib').value,
    type_article:document.getElementById('aType').value,unite:document.getElementById('aUnite').value,
    seuil_alerte:document.getElementById('aSeuil').value,prix_unitaire:document.getElementById('aPrix').value||0,
    description:document.getElementById('aDesc').value,famille_id:document.getElementById('aFamille').value,
  }).then(d=>{
    btn.disabled=false;
    if(d.success){toast(d.message,'success');closeMA();setTimeout(()=>location.reload(),800);}
    else document.getElementById('mAAlert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
  });
}
function viewArticle(id){
  document.getElementById('mAD').classList.add('open');
  document.getElementById('adB').innerHTML='<div style="text-align:center;padding:40px;color:var(--muted)">Chargement…</div>';
  ap({action:'get_article',id}).then(d=>{
    if(!d.success)return;
    const r=d.data;
    document.getElementById('adT').textContent=r.code+' — '+r.libelle;
    const sites=r.stock_par_site.map(s=>`
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px">
        <span>${s.site_nom}</span><strong>${s.quantite} ${r.unite}</strong>
      </div>`).join('')||'<p style="color:var(--muted);font-size:13px">Aucun stock par site.</p>';
    const recs=r.receptions.map(rc=>`
      <div style="padding:7px 0;border-bottom:1px solid var(--border);font-size:12.5px;display:flex;gap:10px">
        <span class="flux-badge reception">+${rc.quantite}</span>
        <div>${rc.date_reception} · ${rc.fournisseur||'—'}</div>
      </div>`).join('')||'<p style="color:var(--muted);font-size:13px">Aucune réception.</p>';
    const dists=r.distributions.map(dl=>`
      <div style="padding:7px 0;border-bottom:1px solid var(--border);font-size:12.5px;display:flex;gap:10px">
        <span class="flux-badge distribution">-${dl.quantite}</span>
        <div>${dl.date_livraison} → ${dl.site}</div>
      </div>`).join('')||'<p style="color:var(--muted);font-size:13px">Aucune distribution.</p>';
    document.getElementById('adB').innerHTML=`
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 18px;margin-bottom:20px">
        ${[['Code',r.code],['Unité',r.unite],['Type',r.type_article||'—'],
           ['Stock central','<strong style="font-size:20px;font-family:\'Plus Jakarta Sans\',sans-serif;color:var(--navy)">'+r.stock_global+' '+r.unite+'</strong>'],
           ['Prix unitaire',r.prix_unitaire>0?r.prix_unitaire.toLocaleString('fr-FR')+' FCFA':'—'],
           ['Seuil alerte',r.seuil_alerte]
          ].map(([l,v])=>`<div style="padding:6px 0;border-bottom:1px solid var(--border)">
            <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px">${l}</div>
            <div style="font-size:13.5px">${v}</div></div>`).join('')}
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
        <div><h4 style="font-size:13px;margin-bottom:10px;color:var(--navy)">Stock par site</h4>${sites}<h4 style="font-size:13px;margin:14px 0 10px;color:var(--navy)">Dernières réceptions</h4>${recs}</div>
        <div><h4 style="font-size:13px;margin-bottom:10px;color:var(--navy)">Dernières distributions</h4>${dists}</div>
      </div>
      ${<?= can('consommables','can_update')?'true':'false' ?>?`<div style="text-align:right;margin-top:16px"><button class="btn btn-secondary btn-sm" onclick="editArticle(${r.id})"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Modifier</button></div>`:''}`;
  });
}
function editArticle(id){
  ap({action:'get_article',id}).then(d=>{
    if(!d.success)return; const r=d.data;
    document.getElementById('mAT').textContent='Modifier : '+r.libelle;
    document.getElementById('aId').value=r.id; document.getElementById('aCode').value=r.code;
    document.getElementById('aCode').disabled=true; document.getElementById('aLib').value=r.libelle;
    document.getElementById('aUnite').value=r.unite; document.getElementById('aSeuil').value=r.seuil_alerte;
    document.getElementById('aPrix').value=r.prix_unitaire||0; document.getElementById('aDesc').value=r.description||'';
    document.getElementById('aType').value=r.type_article||'autre';
    document.getElementById('aFamille').value=r.famille_id||'';
    document.getElementById('mAD').classList.remove('open'); document.getElementById('mA').classList.add('open');
  });
}
function ap(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))if(v!==undefined)fd.append(k,v);
  return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
}
['mA','mAD'].forEach(id=>document.getElementById(id).addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.classList.remove('open');}));
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
