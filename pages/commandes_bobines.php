<?php
// ============================================================
//  pages/commandes_bobines.php — Commandes Bobines
//  Workflow : Coordinateur → Superviseur → GSB → Réception
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();


$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$is_coord  = ($role_slug === 'coordinateur_site');
$is_sup    = can('commandes_bobines', 'can_update');
$is_gsb    = can('commandes_bobines', 'can_create');
$site_force= ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;

// ID site ENTREPOT (stock central)
$entrepot_id = (int)(db_fetch_value("SELECT id FROM sites WHERE type='entrepot' LIMIT 1") ?? 0);

$page_title  = 'Commandes Bobines';
$active_page = 'commandes_bobines';

require_permission('commandes_bobines', 'can_read');

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── 1. Coordinateur crée une demande
    if ($action === 'creer_commande_bobine') {
        if (!$is_coord) json_response(false,'Accès refusé.');
        $type_code = trim($_POST['type_bobine'] ?? '');
        $notes     = trim($_POST['notes']       ?? '');
        if (!$type_code) json_response(false,'Type de bobine obligatoire.');

        $type = db_fetch_one("SELECT * FROM op_types_bobines WHERE code=? AND actif=1", [$type_code]);
        if (!$type) json_response(false,'Type de bobine inconnu.');

        // Vérification informative (non bloquante) — le GSB gère la disponibilité
        $dispo = (int)db_fetch_value(
            "SELECT COUNT(*) FROM op_bobines
             WHERE type_code=? AND statut='en_stock'
               AND (site_id=? OR site_id IS NULL OR site_id=?)",
            [$type_code, $entrepot_id, $entrepot_id]
        );

        $num = 'CMD-BOB-'.date('Ymd').'-'.str_pad(rand(1,999),3,'0',STR_PAD_LEFT);
        db_query(
            "INSERT INTO commandes_bobines
             (numero,site_id,type_bobine,libelle_type,statut,notes,demande_par)
             VALUES (?,?,?,?,'en_attente',?,?)",
            [$num, $site_force, $type_code, $type['code'].' — '.$type['libelle'], $notes, $user['id']]
        );
        $cmd_id = (int)db_last_id();

        // Notifier superviseurs
        $sups = db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
             WHERE r.slug IN ('superviseur_operation','admin','superadmin') AND u.actif=1"
        );
        $site_nom = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_force]);
        foreach ($sups as $s) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$s['id'],'info',"🎞️ Demande bobine $num",
                 "{$user['prenom']} {$user['nom']} ($site_nom) demande une bobine type {$type_code} — {$type['libelle']}",
                 '/pages/commandes_bobines.php']);
        }
        audit_log($user['id'],'CREATE','commandes_bobines',$cmd_id,"Demande $num — type $type_code");
        json_response(true,'Demande envoyée. En attente de validation.', ['numero'=>$num]);
    }

    // ── 2. Superviseur valide ou rejette
    if ($action === 'valider_commande_bobine') {
        if (!$is_sup) json_response(false,'Accès refusé.');
        $cmd_id  = (int)($_POST['cmd_id']    ?? 0);
        $decision= trim($_POST['decision']   ?? '');
        $motif   = trim($_POST['motif']      ?? '');
        if (!in_array($decision,['valide','rejete'])) json_response(false,'Décision invalide.');
        if ($decision==='rejete' && !$motif) json_response(false,'Motif de rejet obligatoire.');

        $cmd = db_fetch_one("SELECT * FROM commandes_bobines WHERE id=? AND statut='en_attente'", [$cmd_id]);
        if (!$cmd) json_response(false,'Commande introuvable ou déjà traitée.');

        db_query(
            "UPDATE commandes_bobines SET statut=?,superviseur_id=?,superviseur_at=NOW(),motif_rejet=? WHERE id=?",
            [$decision, $user['id'], $motif ?: null, $cmd_id]
        );

        // Notifier coordinateur
        $coord = db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
             WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [$cmd['site_id']]
        );
        foreach ($coord as $c) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$c['id'], $decision==='valide'?'success':'danger',
                 $decision==='valide'?"✅ Demande {$cmd['numero']} validée":"❌ Demande {$cmd['numero']} rejetée",
                 $decision==='valide'
                   ? "Votre demande de bobine {$cmd['libelle_type']} a été validée. En cours de préparation par le GSB."
                   : "Votre demande a été rejetée. Motif : $motif",
                 '/pages/commandes_bobines.php']);
        }

        if ($decision==='valide') {
            // Notifier GSB
            $gsbs = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE r.slug IN ('gestionnaire_stock_bobines','admin','superadmin') AND u.actif=1"
            );
            foreach ($gsbs as $g) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$g['id'],'info',"🎞️ Commande bobine à préparer",
                     "Commande {$cmd['numero']} validée — type {$cmd['type_bobine']} pour ".
                     db_fetch_value("SELECT nom FROM sites WHERE id=?",[$cmd['site_id']]),
                     '/pages/commandes_bobines.php']);
            }
        }
        audit_log($user['id'],'UPDATE','commandes_bobines',$cmd_id,"$decision par superviseur");
        json_response(true, $decision==='valide'?'Commande validée. GSB notifié.':'Commande rejetée.');
    }

    // ── 3. GSB : liste des bobines disponibles pour un type
    if ($action === 'get_bobines_dispo') {
        if (!$is_gsb) json_response(false,'Accès refusé.');
        $type_code = trim($_POST['type_bobine'] ?? '');
        $bobines = db_fetch_all(
            "SELECT b.id, b.numero, b.films_restants, b.type_code, b.format,
                    COALESCE(s.nom,'Entrepôt / Non affectée') AS site_actuel
             FROM op_bobines b
             LEFT JOIN sites s ON s.id=b.site_id
             WHERE b.type_code=? AND b.statut='en_stock'
               AND (b.site_id IS NULL OR b.site_id=?)
               -- déjà expédiée vers un site, pas encore réceptionnée
               AND NOT EXISTS (SELECT 1 FROM commandes_bobines_lignes cbl
                                 JOIN commandes_bobines cb ON cb.id = cbl.commande_id
                                 WHERE cbl.bobine_id = b.id AND cbl.statut = 'assigne' AND cb.statut = 'expedie')
             ORDER BY b.films_restants DESC, b.numero",
            [$type_code, $entrepot_id]
        );
        json_response(true,'', $bobines);
    }

    // ── 4. GSB assigne des bobines et expédie
    if ($action === 'expedier_commande_bobine') {
        if (!$is_gsb) json_response(false,'Accès refusé.');
        $cmd_id     = (int)($_POST['cmd_id']   ?? 0);
        $bobine_ids = json_decode($_POST['bobine_ids'] ?? '[]', true);
        if (empty($bobine_ids)) json_response(false,'Sélectionnez au moins une bobine.');

        $cmd = db_fetch_one("SELECT * FROM commandes_bobines WHERE id=? AND statut='valide'", [$cmd_id]);
        if (!$cmd) json_response(false,'Commande introuvable ou statut incorrect.');

        db_begin();
        try {
            foreach ($bobine_ids as $bid) {
                $bid = (int)$bid;
                $bob = db_fetch_one("SELECT * FROM op_bobines b WHERE b.id=? AND b.statut='en_stock' AND NOT EXISTS (SELECT 1 FROM commandes_bobines_lignes cbl
                                 JOIN commandes_bobines cb ON cb.id = cbl.commande_id
                                 WHERE cbl.bobine_id = b.id AND cbl.statut = 'assigne' AND cb.statut = 'expedie')", [$bid]);
                if (!$bob) continue;

                // Vérifier que la bobine est bien du bon type
                if ($bob['type_code'] !== $cmd['type_bobine'])
                    json_response(false,"La bobine {$bob['numero']} n'est pas du type {$cmd['type_bobine']}.");

                // La bobine reste à l'entrepôt (site et statut inchangés) tant que
                // le site ne l'a pas réceptionnée : si elle n'arrive jamais, l'ERP
                // ne la compte pas au site. La ligne 'assigne' d'une commande
                // expédiée la réserve (elle n'est plus proposée ailleurs).
                db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                          VALUES (?,?,?,?,?,?,?)",
                    [$bid,'transfert',0,$bob['stock_systeme'],$bob['stock_systeme'],
                     "Expédiée vers le site via commande {$cmd['numero']} (en attente de réception)",$user['id']]);

                // Enregistrer la ligne
                db_query("INSERT INTO commandes_bobines_lignes (commande_id,bobine_id,numero_bobine,statut)
                          VALUES (?,?,?,'assigne')",
                    [$cmd_id, $bid, $bob['numero']]);
            }

            db_query("UPDATE commandes_bobines SET statut='expedie',gsb_id=?,gsb_at=NOW() WHERE id=?",
                [$user['id'], $cmd_id]);

            // Notifier coordinateur
            $coords = db_fetch_all(
                "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [$cmd['site_id']]
            );
            $nb = count($bobine_ids);
            foreach ($coords as $c) {
                db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                    [$c['id'],'info',"🎞️ Bobine(s) expédiée(s)",
                     "$nb bobine(s) de type {$cmd['type_bobine']} expédiée(s) vers votre site. Confirmez la réception : elles y seront alors en stock.",
                     '/pages/commandes_bobines.php']);
            }
            audit_log($user['id'],'UPDATE','commandes_bobines',$cmd_id,"$nb bobine(s) expédiée(s)");
            db_commit();
            json_response(true,"$nb bobine(s) expédiée(s). Elles seront affectées au site à sa réception.");
        } catch(Exception $e) { db_rollback(); json_response(false,$e->getMessage()); }
    }

    // ── 5. Coordinateur confirme réception
    if ($action === 'confirmer_reception_bobine') {
        $cmd_id = (int)($_POST['cmd_id'] ?? 0);
        $cmd = db_fetch_one("SELECT * FROM commandes_bobines WHERE id=? AND statut='expedie'", [$cmd_id]);
        if (!$cmd) json_response(false,'Commande introuvable ou statut incorrect.');
        if ($is_coord && $cmd['site_id'] != $site_force) json_response(false,'Accès refusé.');

        // À la réception seulement, les bobines passent au site — EN STOCK :
        // comme toute bobine du site, elles passent ensuite en utilisation par
        // une demande d'utilisation.
        db_begin();
        try {
            $site_nom = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$cmd['site_id']]) ?? "site #{$cmd['site_id']}";
            foreach (db_fetch_all("SELECT cbl.bobine_id, b.stock_systeme FROM commandes_bobines_lignes cbl
                                   JOIN op_bobines b ON b.id = cbl.bobine_id
                                   WHERE cbl.commande_id=? AND cbl.statut='assigne'", [$cmd_id]) as $l) {
                db_query("UPDATE op_bobines SET site_id=?, statut='en_stock' WHERE id=?", [$cmd['site_id'], $l['bobine_id']]);
                db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                          VALUES (?,?,?,?,?,?,?)",
                    [$l['bobine_id'],'transfert',0,$l['stock_systeme'],$l['stock_systeme'],
                     "Réception $site_nom — commande {$cmd['numero']}",$user['id']]);
            }
            db_query("UPDATE commandes_bobines SET statut='recu',recu_par=?,recu_at=NOW() WHERE id=?",
                [$user['id'], $cmd_id]);
            db_query("UPDATE commandes_bobines_lignes SET statut='recu' WHERE commande_id=?", [$cmd_id]);
            audit_log($user['id'],'UPDATE','commandes_bobines',$cmd_id,'Réception confirmée — bobines affectées au site, en stock');
            db_commit();
        } catch (Exception $e) { db_rollback(); json_response(false, 'Erreur : '.$e->getMessage()); }
        json_response(true,"Réception confirmée. Les bobines sont en stock sur votre site : faites une demande d'utilisation pour les utiliser.");
    }

    // ── Transfert bobine (GSB)
    if ($action === 'transferer_bobine') {
        if (!$is_gsb) json_response(false,'Accès refusé.');
        $bobine_id   = (int)($_POST['bobine_id']    ?? 0);
        $new_site_id = (int)($_POST['new_site_id']  ?? 0);
        $motif       = trim($_POST['motif']          ?? '');
        if (!$bobine_id || !$new_site_id) json_response(false,'Bobine et site destination obligatoires.');
        if (!$motif) json_response(false,'Motif obligatoire.');

        $bob = db_fetch_one("SELECT b.*,s.nom AS site_actuel FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id WHERE b.id=?", [$bobine_id]);
        if (!$bob) json_response(false,'Bobine introuvable.');

        // Une bobine qui arrive sur un site y est EN STOCK, comme à l'entrepôt :
        // elle passe en utilisation par une demande d'utilisation.
        $new_statut = 'en_stock';
        $new_site_nom = db_fetch_value("SELECT nom FROM sites WHERE id=?", [$new_site_id]);

        db_query("UPDATE op_bobines SET site_id=?,statut=? WHERE id=?",
            [$new_site_id, $new_statut, $bobine_id]);
        db_query("INSERT INTO mouvements_bobines (bobine_id,type,quantite,stock_avant,stock_apres,motif,created_by)
                  VALUES (?,?,?,?,?,?,?)",
            [$bobine_id,'transfert',0,$bob['stock_systeme'],$bob['stock_systeme'],
             "Transfert {$bob['site_actuel']} → $new_site_nom : $motif",$user['id']]);

        audit_log($user['id'],'UPDATE','operations',$bobine_id,
            "Transfert {$bob['numero']} → $new_site_nom ($motif)");
        json_response(true,"Bobine {$bob['numero']} transférée vers $new_site_nom.");
    }

    // ── Annuler
    if ($action === 'annuler_commande_bobine') {
        $cmd_id = (int)($_POST['cmd_id'] ?? 0);
        $cmd = db_fetch_one("SELECT * FROM commandes_bobines WHERE id=? AND statut='en_attente'", [$cmd_id]);
        if (!$cmd) json_response(false,'Seules les commandes en attente peuvent être annulées.');
        if ($is_coord && $cmd['site_id'] != $site_force) json_response(false,'Accès refusé.');
        db_query("UPDATE commandes_bobines SET statut='annule' WHERE id=?", [$cmd_id]);
        json_response(true,'Commande annulée.');
    }

    // ── Détail d'une commande (coordinateur + GSB)
    if ($action === 'get_detail_commande') {
        $cmd_id = (int)($_POST['cmd_id'] ?? 0);
        $cmd = db_fetch_one(
            "SELECT cb.*, s.nom AS site_nom,
                    CONCAT(ug.prenom,' ',ug.nom) AS gsb_nom
             FROM commandes_bobines cb
             JOIN sites s ON s.id=cb.site_id
             LEFT JOIN users ug ON ug.id=cb.gsb_id
             WHERE cb.id=?", [$cmd_id]
        );
        if (!$cmd) json_response(false,'Commande introuvable.');
        if ($is_coord && $cmd['site_id'] != $site_force) json_response(false,'Accès refusé.');

        $lignes = db_fetch_all(
            "SELECT cbl.*, b.films_restants, b.qte_initiale,
                    -- Quantité livrée = films au moment du transfert (mouvement le plus récent)
                    -- plafonnée à qte_initiale (correction de la quantité initiale)
                    LEAST(COALESCE((
                        SELECT m.stock_avant FROM mouvements_bobines m
                        WHERE m.bobine_id=cbl.bobine_id AND m.type='transfert'
                        ORDER BY m.created_at DESC LIMIT 1
                    ), b.qte_initiale), b.qte_initiale) AS qte_livree,
                    cbl.statut AS statut_ligne
             FROM commandes_bobines_lignes cbl
             LEFT JOIN op_bobines b ON b.id=cbl.bobine_id
             WHERE cbl.commande_id=?
             ORDER BY cbl.numero_bobine", [$cmd_id]
        );
        json_response(true,'', ['commande'=>$cmd,'lignes'=>$lignes]);
    }

    // ── Recherche bobine par numéro (pour le transfert)
    if ($action === 'search_bobine_num') {
        $numero = trim($_POST['numero'] ?? '');
        if (!$numero) json_response(false,'Numéro obligatoire.');
        $bob = db_fetch_one(
            "SELECT b.id, b.numero, b.type_code, b.films_restants,
                    COALESCE(s.nom,'Entrepôt') AS site_actuel
             FROM op_bobines b LEFT JOIN sites s ON s.id=b.site_id
             WHERE b.numero LIKE ? LIMIT 1",
            ["%$numero%"]
        );
        if (!$bob) json_response(false,"Bobine '$numero' introuvable.");
        json_response(true, '', $bob);
    }

    json_response(false,'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$f_statut = trim($_GET['statut'] ?? '');
$where = ['1=1']; $params = [];
if ($site_force)  { $where[] = 'cb.site_id=?'; $params[] = $site_force; }
if ($f_statut)    { $where[] = 'cb.statut=?';  $params[] = $f_statut; }

$commandes = db_fetch_all(
    "SELECT cb.*, s.nom AS site_nom,
            CONCAT(ud.prenom,' ',ud.nom) AS demandeur,
            CONCAT(us.prenom,' ',us.nom) AS sup_nom,
            CONCAT(ug.prenom,' ',ug.nom) AS gsb_nom,
            (SELECT COUNT(*) FROM commandes_bobines_lignes l WHERE l.commande_id=cb.id) AS nb_bobines,
            (SELECT GROUP_CONCAT(l.numero_bobine ORDER BY l.numero_bobine SEPARATOR ', ')
             FROM commandes_bobines_lignes l WHERE l.commande_id=cb.id) AS numeros_bobines
     FROM commandes_bobines cb
     JOIN sites s ON s.id=cb.site_id
     LEFT JOIN users ud ON ud.id=cb.demande_par
     LEFT JOIN users us ON us.id=cb.superviseur_id
     LEFT JOIN users ug ON ug.id=cb.gsb_id
     WHERE ".implode(' AND ',$where)."
     ORDER BY cb.created_at DESC LIMIT 100",
    $params
);

$types_list   = db_fetch_all("SELECT * FROM op_types_bobines WHERE actif=1 ORDER BY serie,code");
$sites_list   = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

$kpi = [
    'attente'    => count(array_filter($commandes,fn($c)=>$c['statut']==='en_attente')),
    'valide'     => count(array_filter($commandes,fn($c)=>$c['statut']==='valide')),
    'expedie'    => count(array_filter($commandes,fn($c)=>$c['statut']==='expedie')),
    'recu'       => count(array_filter($commandes,fn($c)=>$c['statut']==='recu')),
];

include __DIR__ . '/../templates/header.php';
?>
<style>
.cb-steps{display:flex;gap:0;margin-bottom:20px;overflow:hidden;border-radius:12px;border:1.5px solid var(--border)}
.cb-step{flex:1;padding:12px 16px;text-align:center;cursor:pointer;transition:background .15s;border-right:1px solid var(--border)}
.cb-step:last-child{border-right:none}
.cb-step .sv{font-size:22px;font-weight:900;font-family:'Plus Jakarta Sans',sans-serif;line-height:1}
.cb-step .sl{font-size:10.5px;font-weight:600;color:var(--muted);text-transform:uppercase;margin-top:2px}
.s-attente{background:#FEF3C7}.s-attente .sv{color:#92400E}
.s-valide{background:#DBEAFE}.s-valide .sv{color:#1D4ED8}
.s-expedie{background:#FFF7ED}.s-expedie .sv{color:#C2410C}
.s-recu{background:#D1FAE5}.s-recu .sv{color:#065F46}

.st-badge{padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.detail-btn{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:7px;
  font-size:11.5px;font-weight:600;cursor:pointer;border:1.5px solid var(--border);
  background:white;color:var(--navy);transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;}
.detail-btn:hover{background:var(--tertiary);border-color:var(--primary);color:var(--primary-d)}
.st-en_attente{background:#FEF3C7;color:#92400E}
.st-valide{background:#DBEAFE;color:#1D4ED8}
.st-en_preparation{background:#FFF7ED;color:#C2410C}
.st-expedie{background:#FFF7ED;color:#C2410C}
.st-recu{background:#D1FAE5;color:#065F46}
.st-rejete{background:#FEE2E2;color:#991B1B}
.st-annule{background:#F1F5F9;color:#64748B}
</style>

<!-- KPIs -->
<div class="cb-steps">
  <div class="cb-step s-attente" onclick="filtrer('en_attente')">
    <div class="sv"><?= $kpi['attente'] ?></div>
    <div class="sl">⏳ À valider</div>
  </div>
  <div class="cb-step s-valide" onclick="filtrer('valide')">
    <div class="sv"><?= $kpi['valide'] ?></div>
    <div class="sl"><i class="ph ph-clipboard-text" aria-hidden="true"></i> À préparer</div>
  </div>
  <div class="cb-step s-expedie" onclick="filtrer('expedie')">
    <div class="sv"><?= $kpi['expedie'] ?></div>
    <div class="sl"><i class="ph ph-truck" aria-hidden="true"></i> Expédiées</div>
  </div>
  <div class="cb-step s-recu" onclick="filtrer('recu')">
    <div class="sv"><?= $kpi['recu'] ?></div>
    <div class="sl"><i class="ph ph-check-circle" aria-hidden="true"></i> Reçues</div>
  </div>
</div>

<!-- TOOLBAR -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <form method="GET" id="frmFiltres" style="display:flex;gap:8px;align-items:center">
    <select name="statut" id="selStatut" onchange="this.form.submit()" aria-label="Filtrer par statut"
      style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none">
      <option value="">Tous statuts</option>
      <option value="en_attente"  <?= $f_statut==='en_attente'?'selected':'' ?>>⏳ En attente</option>
      <option value="valide"      <?= $f_statut==='valide'?'selected':'' ?>>📋 À préparer</option>
      <option value="expedie"     <?= $f_statut==='expedie'?'selected':'' ?>>🚚 Expédiée</option>
      <option value="recu"        <?= $f_statut==='recu'?'selected':'' ?>>✅ Reçue</option>
      <option value="rejete"      <?= $f_statut==='rejete'?'selected':'' ?>>❌ Rejetée</option>
    </select>
  </form>
  <?php if($is_coord): ?>
  <button class="btn btn-primary" onclick="ouvrirNouvelle()">
    <i class="ph-duotone ph-film-strip"></i> Demander une bobine
  </button>
  <?php endif; ?>
  <?php if($is_gsb): ?>
  <button class="btn btn-secondary" onclick="ouvrirTransfert()">
    <i class="ph-duotone ph-arrows-left-right"></i> Transférer une bobine
  </button>
  <?php endif; ?>
</div>

<!-- LISTE -->
<div class="card">
  <div class="card-header">
    <h3><i class="ph-duotone ph-film-strip" style="color:var(--primary-d)"></i> Commandes Bobines
      <span style="font-size:12px;font-weight:400;color:var(--muted)">(<?= count($commandes) ?>)</span>
    </h3>
  </div>
  <div class="table-wrap">
    <table data-paginer="25">
      <thead><tr>
        <th>N° Commande</th>
        <th>Type bobine</th>
        <th>Site</th>
        <th>Date</th>
        <th style="text-align:center">Bobines</th>
        <th>Statut</th>
        <th>Demandeur</th>
        <th style="text-align:center">Actions</th>
      </tr></thead>
      <tbody>
      <?php if(empty($commandes)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucune commande.</td></tr>
      <?php else: foreach($commandes as $cmd): ?>
        <tr>
          <td style="font-family:monospace;font-weight:700;color:var(--primary-d);font-size:12px"><?= h($cmd['numero']) ?></td>
          <td>
            <div style="font-weight:700;color:var(--navy)"><?= h($cmd['type_bobine']) ?></div>
            <div style="font-size:12px;color:var(--muted)"><?= h($cmd['libelle_type']) ?></div>
            <?php if($cmd['numeros_bobines']): ?>
            <div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:3px">
              <?php foreach(explode(', ', $cmd['numeros_bobines']) as $num): ?>
              <span style="background:#EFF6FF;color:#1D4ED8;padding:1px 7px;border-radius:6px;font-size:10.5px;font-weight:700;font-family:monospace">
                <?= h(trim($num)) ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </td>
          <td><?= h($cmd['site_nom']) ?></td>
          <td style="font-size:12px;white-space:nowrap"><?= fmt_date($cmd['created_at'],'d/m/Y') ?></td>
          <td style="text-align:center;font-weight:700">
            <?= $cmd['nb_bobines'] > 0 ? $cmd['nb_bobines'] : '—' ?>
          </td>
          <td>
            <span class="st-badge st-<?= $cmd['statut'] ?>">
              <?= ['en_attente'=>'⏳ En attente','valide'=>'<i class="ph ph-clipboard-text" aria-hidden="true"></i> À préparer','expedie'=>'<i class="ph ph-truck" aria-hidden="true"></i> Expédiée',
                   'recu'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Reçue','rejete'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Rejetée','annule'=>'Annulée'][$cmd['statut']] ?? $cmd['statut'] ?>
            </span>
          </td>
          <td style="font-size:12px"><?= h($cmd['demandeur']??'—') ?></td>
          <td style="text-align:center;white-space:nowrap">
            <div style="display:flex;gap:4px;justify-content:center">
            <?php if($is_sup && $cmd['statut']==='en_attente'): ?>
              <button class="btn btn-primary btn-sm" onclick="valider(<?= $cmd['id'] ?>,'<?= h($cmd['numero']) ?>')"><i class="ph ph-check-circle" aria-hidden="true"></i> Valider</button>
              <button class="btn btn-danger btn-sm"  onclick="rejeter(<?= $cmd['id'] ?>,'<?= h($cmd['numero']) ?>')"><i class="ph ph-x-circle" aria-hidden="true"></i> Rejeter</button>
            <?php endif; ?>
            <?php if($is_gsb && $cmd['statut']==='valide'): ?>
              <button class="btn btn-primary btn-sm" onclick="ouvrirPreparation(<?= $cmd['id'] ?>,'<?= h($cmd['numero']) ?>','<?= h($cmd['type_bobine']) ?>','<?= $cmd['site_id'] ?>')"><i class="ph ph-film-strip" aria-hidden="true"></i> Préparer</button>
            <?php endif; ?>
            <?php if($cmd['statut']==='expedie' && ($is_coord || $is_gsb)): ?>
              <button class="btn btn-success btn-sm" onclick="confirmerReception(<?= $cmd['id'] ?>)"><i class="ph ph-check-circle" aria-hidden="true"></i> Réceptionner</button>
            <?php endif; ?>
            <?php if($cmd['statut']==='en_attente' && ($is_coord || $is_sup)): ?>
              <button class="btn btn-sm" style="background:#FEE2E2;color:#991B1B;border:1.5px solid #FCA5A5"
                      onclick="annuler(<?= $cmd['id'] ?>)"><i class="ph ph-x" aria-hidden="true"></i></button>
            <?php endif; ?>
            <?php if(in_array($cmd['statut'],['expedie','recu']) && $cmd['nb_bobines']>0): ?>
              <button class="detail-btn" onclick="voirDetailCommande(<?= $cmd['id'] ?>,'<?= h($cmd['numero']) ?>')">
                <i class="ph-duotone ph-eye"></i> Détails
              </button>
            <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ MODAL NOUVELLE DEMANDE (Coordinateur) ═══ -->
<?php if($is_coord): ?>
<div id="modalNouvelle" style="display:none;position:fixed;inset:0;background:rgba(6,3,58,.55);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:18px;padding:28px;width:520px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)">
        <i class="ph-duotone ph-film-strip"></i> Demander une bobine
      </h3>
      <button onclick="fermer('Nouvelle')" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="alertNouvelle"></div>

    <div class="form-group" style="margin-bottom:16px">
      <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">
        Type de bobine <span style="color:var(--danger-d)">*</span>
      </label>
      <select class="form-control" id="nTypeBobine" onchange="checkDispo()">
        <option value="">— Sélectionner le type —</option>
        <?php foreach($types_list as $t): ?>
        <option value="<?= h($t['code']) ?>"><?= h($t['code'].' — '.$t['libelle']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="dispoInfo" style="display:none;margin-bottom:16px;padding:12px;border-radius:10px;font-size:13px"></div>

    <div class="form-group" style="margin-bottom:20px">
      <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">Notes / Motif</label>
      <textarea class="form-control" id="nNotes" rows="2" placeholder="Précisions sur la demande…"></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer('Nouvelle')">Annuler</button>
      <button class="btn btn-primary" id="btnEnvoyer" onclick="envoyerDemande()">
        <i class="ph-duotone ph-paper-plane-tilt"></i> Envoyer la demande
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL VALIDATION SUPERVISEUR ═══ -->
<?php if($is_sup): ?>
<div id="modalValider" style="display:none;position:fixed;inset:0;background:rgba(6,3,58,.55);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:18px;padding:28px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin-bottom:16px" id="titleValider">Valider la commande</h3>
    <div id="alertValider"></div>
    <input type="hidden" id="vCmdId">
    <input type="hidden" id="vDecision">
    <div id="motifGrp" style="display:none;margin-bottom:14px">
      <label style="font-size:13px;font-weight:700;color:var(--danger-d);display:block;margin-bottom:6px">Motif de rejet *</label>
      <textarea class="form-control" id="vMotif" rows="2" placeholder="Raison du rejet…"></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer('Valider')">Annuler</button>
      <button class="btn btn-primary" onclick="confirmerValidation()" id="btnConfVal">Confirmer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL PRÉPARATION GSB ═══ -->
<?php if($is_gsb): ?>
<div id="modalPrep" style="display:none;position:fixed;inset:0;background:rgba(6,3,58,.55);z-index:1000;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
  <div style="background:white;border-radius:18px;padding:28px;width:700px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25);margin:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <div>
        <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)" id="titlePrep">Préparer la commande</h3>
        <div id="subtitlePrep" style="font-size:12px;color:var(--muted);margin-top:3px"></div>
      </div>
      <button onclick="fermer('Prep')" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="alertPrep"></div>

    <!-- Liste bobines disponibles -->
    <div style="background:#EFF6FF;border-left:4px solid #3B82F6;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#1D4ED8">
      Cochez les bobines à affecter au site demandeur. Seules les bobines de l'entrepôt sont listées.
    </div>
    <div id="listeBobinesDispo" style="max-height:320px;overflow-y:auto;border:1.5px solid var(--border);border-radius:10px;margin-bottom:16px">
      <div style="padding:20px;text-align:center;color:var(--muted)">Chargement…</div>
    </div>
    <div id="selectionInfo" style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:14px"></div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer('Prep')">Annuler</button>
      <button class="btn btn-primary" onclick="confirmerPreparation()">
        <i class="ph-duotone ph-truck"></i> Affecter et expédier
      </button>
    </div>
  </div>
</div>

<!-- ═══ MODAL TRANSFERT ═══ -->
<div id="modalTransfert" style="display:none;position:fixed;inset:0;background:rgba(6,3,58,.55);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:18px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)">
        <i class="ph-duotone ph-arrows-left-right"></i> Transférer une bobine
      </h3>
      <button onclick="fermer('Transfert')" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="alertTransfert"></div>

    <div class="form-group" style="margin-bottom:14px">
      <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">N° Bobine *</label>
      <input type="text" class="form-control" id="tNumeroBobine" placeholder="Ex: BOB-042"
             oninput="rechercherBobine(this.value)" autocomplete="off">
      <div id="tBobineInfo" style="display:none;margin-top:8px;padding:10px;border-radius:8px;background:var(--tertiary);font-size:13px"></div>
      <input type="hidden" id="tBobineId">
    </div>
    <div class="form-group" style="margin-bottom:14px">
      <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">Site destination *</label>
      <select class="form-control" id="tSiteDest">
        <option value="">— Sélectionner —</option>
        <?php foreach($sites_list as $s): ?>
        <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:20px">
      <label style="font-size:13px;font-weight:700;color:var(--navy);display:block;margin-bottom:6px">Motif *</label>
      <textarea class="form-control" id="tMotif" rows="2" placeholder="Raison du transfert…"></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer('Transfert')">Annuler</button>
      <button class="btn btn-primary" onclick="confirmerTransfert()">Transférer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function ap(d){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(r=>r.json());}

// ── Détail commande (coordinateur et GSB)
async function voirDetailCommande(cmdId, numCmd){
  // Utiliser une modale dynamique
  let modal = document.getElementById('modalDetailCmd');
  if(!modal){
    modal = document.createElement('div');
    modal.id='modalDetailCmd';
    modal.style.cssText='position:fixed;inset:0;background:rgba(6,3,58,.55);z-index:1000;align-items:center;justify-content:center;display:flex';
    modal.innerHTML=`<div style="background:white;border-radius:18px;padding:28px;width:560px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
        <div>
          <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)" id="dcTitle"></h3>
          <div id="dcSub" style="font-size:12px;color:var(--muted);margin-top:2px"></div>
        </div>
        <button onclick="document.getElementById('modalDetailCmd').remove()" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
      </div>
      <div id="dcBody">Chargement…</div>
    </div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', e=>{if(e.target===modal)modal.remove();});
  }

  document.getElementById('dcTitle').textContent=`🎞️ ${numCmd}`;
  document.getElementById('dcBody').innerHTML='<div style="text-align:center;padding:30px;color:var(--muted)">Chargement…</div>';

  const d = await ap({action:'get_detail_commande', cmd_id:cmdId});
  if(!d.success){document.getElementById('dcBody').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;return;}

  const cmd = d.data.commande;
  const lignes = d.data.lignes;

  document.getElementById('dcSub').textContent=`Type : ${cmd.type_bobine} — ${cmd.libelle_type}`;

  let html=`
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:18px">
      <div style="text-align:center;padding:11px;background:var(--tertiary);border-radius:10px">
        <div style="font-size:20px;font-weight:900;color:var(--navy)">${lignes.length}</div>
        <div style="font-size:12px;color:var(--muted);font-weight:600">Bobines affectées</div>
      </div>
      <div style="text-align:center;padding:11px;background:var(--tertiary);border-radius:10px">
        <div style="font-size:13px;font-weight:700;color:var(--navy)">${cmd.site_nom}</div>
        <div style="font-size:12px;color:var(--muted);font-weight:600">Site</div>
      </div>
      <div style="text-align:center;padding:11px;background:var(--tertiary);border-radius:10px">
        <div style="font-size:13px;font-weight:700;color:var(--navy)">${cmd.gsb_nom||'—'}</div>
        <div style="font-size:12px;color:var(--muted);font-weight:600">Préparé par</div>
      </div>
    </div>
    <div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:10px"><i class="ph ph-film-strip" aria-hidden="true"></i> Numéros de bobines affectées</div>
    <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
      <table style="width:100%;border-collapse:collapse">
        <thead><tr style="background:#06033A">
          <th style="padding:9px 12px;color:white;font-size:12px;text-align:left">N° Bobine</th>
          <th style="padding:9px 12px;color:white;font-size:12px;text-align:center">Qté livrée</th>
          <th style="padding:9px 12px;color:white;font-size:12px;text-align:center">Films restants</th>
          <th style="padding:9px 12px;color:white;font-size:12px;text-align:center">Consommé</th>
          <th style="padding:9px 12px;color:white;font-size:12px;text-align:center">Statut</th>
        </tr></thead>
        <tbody>`;

  lignes.forEach((l,i)=>{
    const livree   = parseInt(l.qte_livree) || parseInt(l.qte_initiale) || 0;
    const restants = parseInt(l.films_restants) || 0;
    const consomme = livree > 0 ? livree - restants : 0;
    const pct      = livree > 0 ? Math.round(consomme/livree*100) : 0;
    html+=`<tr style="background:${i%2===0?'white':'#f8fafc'};border-top:1px solid var(--border)">
      <td style="padding:9px 12px;font-family:monospace;font-weight:800;color:#06033A">${l.numero_bobine}</td>
      <td style="padding:9px 12px;text-align:center;font-weight:700;color:var(--navy)">${livree||'—'}</td>
      <td style="padding:9px 12px;text-align:center;font-weight:700;color:${restants===0?'#DC2626':restants<100?'#D97706':'#065F46'}">${restants}</td>
      <td style="padding:9px 12px;text-align:center">
        <span style="font-weight:700;color:#1D4ED8">${consomme}</span>
        ${pct>0?`<span style="color:var(--muted);font-size:12px"> (${pct}%)</span>`:''}
      </td>
      <td style="padding:9px 12px;text-align:center">
        ${l.statut_ligne==='recu'
          ? '<span style="background:#D1FAE5;color:#065F46;padding:2px 9px;border-radius:8px;font-size:10.5px;font-weight:700">✅ Reçue</span>'
          : '<span style="background:#FFF7ED;color:#C2410C;padding:2px 9px;border-radius:8px;font-size:10.5px;font-weight:700">🚚 En route</span>'}
      </td>
    </tr>`;
  });

  html+='</tbody></table></div>';
  if(cmd.notes){
    html+=`<div style="margin-top:12px;font-size:12.5px;color:var(--muted);background:var(--tertiary);padding:10px 12px;border-radius:8px">
      📝 ${cmd.notes}</div>`;
  }
  document.getElementById('dcBody').innerHTML=html;
}
function fermer(m){document.getElementById('modal'+m).style.display='none';}
function filtrer(s){document.getElementById('selStatut').value=s;document.getElementById('frmFiltres').submit();}

// ── Nouvelle demande coordinateur
function ouvrirNouvelle(){
  document.getElementById('nTypeBobine').value='';
  document.getElementById('nNotes').value='';
  document.getElementById('alertNouvelle').innerHTML='';
  document.getElementById('dispoInfo').style.display='none';
  document.getElementById('modalNouvelle').style.display='flex';
}
async function checkDispo(){
  const type = document.getElementById('nTypeBobine').value;
  const info = document.getElementById('dispoInfo');
  if(!type){info.style.display='none';return;}
  const d = await ap({action:'get_bobines_dispo',type_bobine:type});
  const nb = d.data?.length || 0;
  info.style.display='block';
  if(nb>0){
    info.style.background='#D1FAE5'; info.style.color='#065F46';
    info.innerHTML=`<i class="ph ph-check-circle" aria-hidden="true"></i> <strong>${nb}</strong> bobine(s) de ce type disponible(s) en entrepôt.`;
  } else {
    info.style.background='#FEF3C7'; info.style.color='#92400E';
    info.innerHTML=`<i class="ph ph-warning" aria-hidden="true"></i> Aucune bobine disponible en entrepôt pour l'instant. La demande sera transmise au GSB qui vérifiera.`;
  }
}
async function envoyerDemande(){
  const type  = document.getElementById('nTypeBobine').value;
  const notes = document.getElementById('nNotes').value;
  if(!type){document.getElementById('alertNouvelle').innerHTML='<div class="alert alert-danger">Sélectionnez un type de bobine.</div>';return;}
  const btn = document.getElementById('btnEnvoyer');
  btn.disabled=true; btn.textContent='⏳ Envoi…';
  try {
    const d = await ap({action:'creer_commande_bobine',type_bobine:type,notes});
    if(d.success){toast(d.message,'success');fermer('Nouvelle');setTimeout(()=>location.reload(),800);}
    else document.getElementById('alertNouvelle').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
  } finally { btn.disabled=false; btn.innerHTML='<i class="ph-duotone ph-paper-plane-tilt"></i> Envoyer la demande'; }
}

// ── Validation superviseur
let currentValCmdId = null, currentValDecision = null;
function valider(id, num){ currentValCmdId=id; currentValDecision='valide'; openValModal(num,'valide'); }
function rejeter(id, num){ currentValCmdId=id; currentValDecision='rejete'; openValModal(num,'rejete'); }
function openValModal(num, dec){
  document.getElementById('alertValider').innerHTML='';
  document.getElementById('vCmdId').value=currentValCmdId;
  document.getElementById('vDecision').value=dec;
  document.getElementById('vMotif').value='';
  document.getElementById('motifGrp').style.display=dec==='rejete'?'block':'none';
  document.getElementById('titleValider').textContent=dec==='valide'?`✅ Valider — ${num}`:`❌ Rejeter — ${num}`;
  document.getElementById('btnConfVal').className='btn '+(dec==='valide'?'btn-primary':'btn-danger');
  document.getElementById('btnConfVal').textContent=dec==='valide'?'✅ Confirmer validation':'❌ Confirmer rejet';
  document.getElementById('modalValider').style.display='flex';
}
async function confirmerValidation(){
  const motif = document.getElementById('vMotif').value.trim();
  if(currentValDecision==='rejete'&&!motif){document.getElementById('alertValider').innerHTML='<div class="alert alert-danger">Motif obligatoire.</div>';return;}
  const d = await ap({action:'valider_commande_bobine',cmd_id:currentValCmdId,decision:currentValDecision,motif});
  if(d.success){toast(d.message,'success');fermer('Valider');setTimeout(()=>location.reload(),800);}
  else document.getElementById('alertValider').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}

// ── Préparation GSB
let currentPrepCmdId = null, selectedBobines = new Set();
async function ouvrirPreparation(cmdId, num, type, siteId){
  currentPrepCmdId=cmdId; selectedBobines=new Set();
  document.getElementById('alertPrep').innerHTML='';
  document.getElementById('titlePrep').textContent=`🎞️ Préparer — ${num}`;
  document.getElementById('subtitlePrep').textContent=`Type : ${type}`;
  document.getElementById('selectionInfo').textContent='';
  document.getElementById('modalPrep').style.display='flex';
  document.getElementById('listeBobinesDispo').innerHTML='<div style="padding:20px;text-align:center;color:var(--muted)">Chargement des bobines disponibles…</div>';

  const d = await ap({action:'get_bobines_dispo',type_bobine:type});
  const bobines = d.data || [];
  if(bobines.length===0){
    document.getElementById('listeBobinesDispo').innerHTML='<div style="padding:20px;text-align:center;color:var(--danger-d)">❌ Aucune bobine disponible en entrepôt pour ce type.</div>';
    return;
  }
  let html='<table style="width:100%;border-collapse:collapse">';
  html+=`<thead><tr style="background:#F8FAFC">
    <th style="padding:9px 12px;font-size:12px;font-weight:700;color:var(--muted);text-align:center;width:44px">
      <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
    </th>
    <th style="padding:9px 12px;font-size:12px;font-weight:700;color:var(--muted)">N° Bobine</th>
    <th style="padding:9px 12px;font-size:12px;font-weight:700;color:var(--muted)">Format</th>
    <th style="padding:9px 12px;font-size:12px;font-weight:700;color:var(--muted);text-align:center">Films restants</th>
  </tr></thead><tbody>`;
  bobines.forEach((b,i)=>{
    html+=`<tr style="border-top:1px solid var(--border);background:${i%2===0?'white':'#F8FAFC'}">
      <td style="padding:9px 12px;text-align:center">
        <input type="checkbox" class="bob-check" data-id="${b.id}" onchange="updateSel(this,${b.id})">
      </td>
      <td style="padding:9px 12px;font-family:monospace;font-weight:800;color:var(--navy)">${b.numero}</td>
      <td style="padding:9px 12px;font-size:12px;color:var(--muted)">${b.format||'—'}</td>
      <td style="padding:9px 12px;text-align:center;font-weight:700;color:${b.films_restants<100?'#DC2626':'#065F46'}">${b.films_restants}</td>
    </tr>`;
  });
  html+='</tbody></table>';
  document.getElementById('listeBobinesDispo').innerHTML=html;
}
function toggleAll(cb){
  document.querySelectorAll('.bob-check').forEach(c=>{
    c.checked=cb.checked;
    updateSel(c, parseInt(c.dataset.id));
  });
}
function updateSel(cb, id){
  if(cb.checked) selectedBobines.add(id);
  else selectedBobines.delete(id);
  const n=selectedBobines.size;
  document.getElementById('selectionInfo').textContent=n>0?`${n} bobine(s) sélectionnée(s) à affecter`:'';
}
async function confirmerPreparation(){
  if(selectedBobines.size===0){document.getElementById('alertPrep').innerHTML='<div class="alert alert-danger">Sélectionnez au moins une bobine.</div>';return;}
  const d = await ap({action:'expedier_commande_bobine',cmd_id:currentPrepCmdId,bobine_ids:JSON.stringify([...selectedBobines])});
  if(d.success){toast(d.message,'success');fermer('Prep');setTimeout(()=>location.reload(),800);}
  else document.getElementById('alertPrep').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}

// ── Réception coordinateur
async function confirmerReception(id){
  if(!confirm('Confirmer la réception des bobines ?'))return;
  const d=await ap({action:'confirmer_reception_bobine',cmd_id:id});
  if(d.success){toast(d.message,'success');setTimeout(()=>location.reload(),800);}
  else toast(d.message,'danger');
}

async function annuler(id){
  if(!confirm('Annuler cette commande ?'))return;
  const d=await ap({action:'annuler_commande_bobine',cmd_id:id});
  if(d.success){toast(d.message,'success');setTimeout(()=>location.reload(),800);}
  else toast(d.message,'danger');
}

// ── Transfert bobine (GSB)
function ouvrirTransfert(){
  document.getElementById('tNumeroBobine').value='';
  document.getElementById('tBobineId').value='';
  document.getElementById('tBobineInfo').style.display='none';
  document.getElementById('tSiteDest').value='';
  document.getElementById('tMotif').value='';
  document.getElementById('alertTransfert').innerHTML='';
  document.getElementById('modalTransfert').style.display='flex';
}
let rechercheTimeout=null;
function rechercherBobine(val){
  clearTimeout(rechercheTimeout);
  if(val.length<3){document.getElementById('tBobineInfo').style.display='none';return;}
  rechercheTimeout=setTimeout(async()=>{
    const r=await fetch(`<?= APP_URL ?>/api/equipements.php?action=search_bobine&q=${encodeURIComponent(val)}`,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).catch(()=>null);
    // Fallback: chercher directement via AJAX POST
    const d=await ap({action:'get_bobines_dispo',type_bobine:'__all__'}).catch(()=>({data:[]}));
    // Simple: chercher la bobine par numéro direct
    const d2 = await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:`action=search_bobine_num&numero=${encodeURIComponent(val)}`}).then(r=>r.json()).catch(()=>null);
    if(d2&&d2.success&&d2.data){
      const b=d2.data;
      document.getElementById('tBobineId').value=b.id;
      const info=document.getElementById('tBobineInfo');
      info.style.display='block';
      info.innerHTML=`<strong>${b.numero}</strong> — Type: ${b.type_code} — Films: ${b.films_restants} — Site actuel: ${b.site_actuel||'Entrepôt'}`;
    }
  },500);
}
async function confirmerTransfert(){
  const bid=document.getElementById('tBobineId').value;
  const sid=document.getElementById('tSiteDest').value;
  const motif=document.getElementById('tMotif').value.trim();
  if(!bid||!sid||!motif){document.getElementById('alertTransfert').innerHTML='<div class="alert alert-danger">Tous les champs sont obligatoires.</div>';return;}
  const d=await ap({action:'transferer_bobine',bobine_id:bid,new_site_id:sid,motif});
  if(d.success){toast(d.message,'success');fermer('Transfert');setTimeout(()=>location.reload(),800);}
  else document.getElementById('alertTransfert').innerHTML=`<div class="alert alert-danger">${d.message}</div>`;
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
