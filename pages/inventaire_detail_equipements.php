<?php
// ============================================================
//  pages/inventaire_detail_equipements.php — Saisie inventaire équipements
//  Calqué sur pages/inventaire_detail_rivets.php, y compris le
//  sous-workflow de correction complet — mais en mode "présence" :
//  pas de quantité, chaque ligne se coche Trouvé/Manquant.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();
// Gate pilotée par la permission DB — cf. pages/inventaire_equipements.php.
require_permission('inventaire_equipements', 'can_read');

$user   = current_user();
$inv_id = (int)($_GET['id'] ?? 0);
if (!$inv_id) { header('Location: inventaire_equipements.php'); exit; }

$inv = db_fetch_one(
    "SELECT i.*, s.nom AS site_nom, CONCAT(u.prenom,' ',u.nom) AS createur
     FROM inventaires_equipements i
     LEFT JOIN sites s ON s.id=i.site_id
     LEFT JOIN users u ON u.id=i.cree_par
     WHERE i.id=?", [$inv_id]
);
if (!$inv) { header('Location: inventaire_equipements.php'); exit; }

$role_slug    = $user['role_slug'] ?? '';
$is_coord     = ($role_slug === 'coordinateur_site');
$can_edit     = $inv['statut'] === 'brouillon' && can('inventaire_equipements','can_update');
$can_validate = $inv['statut'] === 'brouillon'
    && in_array($role_slug, ['admin','superadmin','superviseur_operation','gestionnaire_stock_bobines','gestionnaire_stock']);
$can_demander_site  = can('inventaire_sessions','can_read');
$page_title  = 'Inventaire équipements du ' . fmt_date($inv['date_inventaire']);
$active_page = 'inventaire_equipements';

// ============================================================
//  AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD']==='POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action==='sauver_ligne') {
        if (!$can_edit) json_response(false,'Inventaire non modifiable.');
        $detail_id = (int)($_POST['detail_id'] ?? 0);
        $trouve    = $_POST['trouve'] !== '' ? (int)$_POST['trouve'] : null;
        $notes     = trim($_POST['notes'] ?? '');
        if ($trouve === null) json_response(false,'Statut Trouvé/Manquant obligatoire.');

        $det = db_fetch_one("SELECT * FROM inventaire_details_equipements WHERE id=? AND inventaire_id=?", [$detail_id, $inv_id]);
        if (!$det) json_response(false,'Ligne introuvable.');

        db_query("UPDATE inventaire_details_equipements SET trouve=?, notes=? WHERE id=?", [$trouve, $notes, $detail_id]);
        json_response(true,'Sauvegardé.',['trouve'=>$trouve]);
    }

    if ($action==='demander_modif') {
        if (!$can_demander_site) json_response(false,'Action réservée à l\'administrateur ou au responsable de session.');
        $detail_id       = (int)($_POST['detail_id'] ?? 0);
        $motif           = trim($_POST['motif'] ?? '');
        $valeur_proposee = ($_POST['valeur_proposee'] ?? '') !== '' ? (int)$_POST['valeur_proposee'] : null;
        if ($motif === '') json_response(false,'Le motif est obligatoire.');

        $det = db_fetch_one(
            "SELECT d.*, e.numero_serie_interne FROM inventaire_details_equipements d
             JOIN equipements e ON e.id=d.equipement_id WHERE d.id=? AND d.inventaire_id=?",
            [$detail_id, $inv_id]
        );
        if (!$det) json_response(false,'Ligne introuvable.');
        if ($det['trouve'] === null) json_response(false,"Cette ligne n'a pas encore été saisie.");
        if (db_fetch_value("SELECT COUNT(*) FROM inventaire_corrections_equipements WHERE detail_id=? AND statut IN ('en_attente','autorise')", [$detail_id])) {
            json_response(false,'Une demande est déjà en cours pour cette ligne.');
        }

        db_query(
            "INSERT INTO inventaire_corrections_equipements (detail_id,inventaire_id,equipement_id,site_id,valeur_actuelle,valeur_proposee,motif,demandeur_id,type)
             VALUES (?,?,?,?,?,?,?,?,'demande_site')",
            [$detail_id, $inv_id, $det['equipement_id'], $inv['site_id'], (int)$det['trouve'], $valeur_proposee, $motif, $user['id']]
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
                 "$nom demande une modification sur l'équipement {$det['numero_serie_interne']} de votre inventaire du " . fmt_date($inv['date_inventaire']) . " : $motif",
                 '/pages/inventaire_detail_equipements.php?id=' . $inv_id]
            );
        }
        audit_log($user['id'],'CREATE','inventaire_corrections_equipements',$corr_id,"Demande modif équipement {$det['numero_serie_interne']} inventaire #$inv_id");
        json_response(true,'Demande envoyée au site.',['id'=>$corr_id]);
    }

    if ($action==='demander_autorisation') {
        if (!$is_coord) json_response(false,'Action réservée au coordinateur de site.');
        $detail_id       = (int)($_POST['detail_id'] ?? 0);
        $motif           = trim($_POST['motif'] ?? '');
        $valeur_proposee = ($_POST['valeur_proposee'] ?? '') !== '' ? (int)$_POST['valeur_proposee'] : null;
        if ($motif === '') json_response(false,'Le motif est obligatoire.');

        $det = db_fetch_one(
            "SELECT d.*, e.numero_serie_interne FROM inventaire_details_equipements d
             JOIN equipements e ON e.id=d.equipement_id WHERE d.id=? AND d.inventaire_id=?",
            [$detail_id, $inv_id]
        );
        if (!$det) json_response(false,'Ligne introuvable.');
        if ($det['trouve'] === null) json_response(false,"Cette ligne n'a pas encore été saisie.");
        if (db_fetch_value("SELECT COUNT(*) FROM inventaire_corrections_equipements WHERE detail_id=? AND statut IN ('en_attente','autorise')", [$detail_id])) {
            json_response(false,'Une demande est déjà en cours pour cette ligne.');
        }

        db_query(
            "INSERT INTO inventaire_corrections_equipements (detail_id,inventaire_id,equipement_id,site_id,valeur_actuelle,valeur_proposee,motif,demandeur_id,type)
             VALUES (?,?,?,?,?,?,?,?,'demande_autorisation')",
            [$detail_id, $inv_id, $det['equipement_id'], $inv['site_id'], (int)$det['trouve'], $valeur_proposee, $motif, $user['id']]
        );
        $corr_id = (int)db_last_id();

        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        $admins = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug IN ('admin','superadmin') AND u.actif=1");
        foreach ($admins as $a) {
            db_query(
                "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$a['id'], 'info', '🔒 Demande d\'autorisation de modification',
                 "$nom demande à modifier l'équipement {$det['numero_serie_interne']} sur l'inventaire du " . fmt_date($inv['date_inventaire']) . " ({$inv['site_nom']}) : $motif",
                 '/pages/inventaire_detail_equipements.php?id=' . $inv_id]
            );
        }
        audit_log($user['id'],'CREATE','inventaire_corrections_equipements',$corr_id,"Demande autorisation équipement {$det['numero_serie_interne']} inventaire #$inv_id");
        json_response(true,"Demande d'autorisation envoyée à l'administrateur.",['id'=>$corr_id]);
    }

    if ($action==='autoriser_modif') {
        if (!$can_demander_site) json_response(false,'Action réservée à l\'administrateur ou au responsable de session.');
        $corr_id = (int)($_POST['correction_id'] ?? 0);
        $corr = db_fetch_one(
            "SELECT * FROM inventaire_corrections_equipements WHERE id=? AND inventaire_id=? AND type='demande_autorisation' AND statut='en_attente'",
            [$corr_id, $inv_id]
        );
        if (!$corr) json_response(false,'Demande introuvable ou déjà traitée.');

        db_query("UPDATE inventaire_corrections_equipements SET statut='autorise', autorise_par=?, autorise_at=NOW() WHERE id=?", [$user['id'], $corr_id]);
        $num = db_fetch_value("SELECT numero_serie_interne FROM equipements WHERE id=?", [$corr['equipement_id']]);
        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$corr['demandeur_id'], 'info', '✅ Autorisation accordée',
             "$nom a autorisé la modification de l'équipement {$num} : vous pouvez corriger le statut.",
             '/pages/inventaire_detail_equipements.php?id=' . $inv_id]
        );
        audit_log($user['id'],'UPDATE','inventaire_corrections_equipements',$corr_id,"Autorisation accordée équipement {$num} inventaire #$inv_id");
        json_response(true,'Autorisation accordée — le site peut maintenant corriger la ligne.');
    }

    if ($action==='refuser_modif') {
        if (!$can_demander_site) json_response(false,'Action réservée à l\'administrateur ou au responsable de session.');
        $corr_id = (int)($_POST['correction_id'] ?? 0);
        $motif_refus = trim($_POST['motif'] ?? '');
        $corr = db_fetch_one(
            "SELECT * FROM inventaire_corrections_equipements WHERE id=? AND inventaire_id=? AND type='demande_autorisation' AND statut='en_attente'",
            [$corr_id, $inv_id]
        );
        if (!$corr) json_response(false,'Demande introuvable ou déjà traitée.');

        db_query(
            "UPDATE inventaire_corrections_equipements SET statut='refuse', reponse=?, traite_par=?, traite_at=NOW() WHERE id=?",
            [$motif_refus ?: null, $user['id'], $corr_id]
        );
        $num = db_fetch_value("SELECT numero_serie_interne FROM equipements WHERE id=?", [$corr['equipement_id']]);
        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$corr['demandeur_id'], 'info', '❌ Autorisation refusée',
             "$nom a refusé la demande de modification sur l'équipement {$num}" . ($motif_refus ? " : $motif_refus" : '.'),
             '/pages/inventaire_detail_equipements.php?id=' . $inv_id]
        );
        audit_log($user['id'],'UPDATE','inventaire_corrections_equipements',$corr_id,"Autorisation refusée équipement {$num} inventaire #$inv_id");
        json_response(true,'Demande refusée.');
    }

    if ($action==='repondre_modif') {
        if (!$can_edit) json_response(false,'Action non autorisée.');
        $corr_id       = (int)($_POST['correction_id'] ?? 0);
        $valeur_finale = ($_POST['valeur_finale'] ?? '') !== '' ? (int)$_POST['valeur_finale'] : null;
        $reponse       = trim($_POST['reponse'] ?? '');
        if ($valeur_finale === null) json_response(false,'Le statut corrigé est obligatoire.');

        $corr = db_fetch_one("SELECT * FROM inventaire_corrections_equipements WHERE id=? AND inventaire_id=? AND statut IN ('en_attente','autorise')", [$corr_id, $inv_id]);
        if (!$corr) json_response(false,'Demande introuvable ou déjà traitée.');
        if ($corr['type'] === 'demande_autorisation' && $corr['statut'] !== 'autorise') {
            json_response(false,"Cette demande doit d'abord être autorisée par l'administrateur.");
        }

        $num = db_fetch_value("SELECT numero_serie_interne FROM equipements WHERE id=?", [$corr['equipement_id']]);

        db_begin();
        try {
            db_query("UPDATE inventaire_details_equipements SET trouve=? WHERE id=?", [$valeur_finale, $corr['detail_id']]);
            db_query(
                "UPDATE inventaire_corrections_equipements SET statut='traite',valeur_finale=?,reponse=?,traite_par=?,traite_at=NOW() WHERE id=?",
                [$valeur_finale, $reponse ?: null, $user['id'], $corr_id]
            );
            audit_log($user['id'],'UPDATE','inventaire_corrections_equipements',$corr_id,"Réponse modif équipement {$num} inventaire #$inv_id : $valeur_finale");
            db_commit();
        } catch (Exception $e) { db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }

        $nom = trim(($user['prenom'] ?? '').' '.($user['nom'] ?? ''));
        db_query(
            "INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
            [$corr['demandeur_id'], 'info', '↩ Réponse à votre demande',
             "$nom a répondu à votre demande de modification sur l'équipement {$num} : nouveau statut " . ($valeur_finale ? 'Trouvé' : 'Manquant') . '.',
             '/pages/inventaire_detail_equipements.php?id=' . $inv_id]
        );

        json_response(true,'Réponse envoyée.',['trouve'=>$valeur_finale]);
    }

    if ($action==='sauver_tout') {
        if (!$can_edit) json_response(false,'Inventaire non modifiable.');
        $lignes_data = json_decode($_POST['lignes'] ?? '[]', true);
        $saved = 0;
        db_begin();
        try {
            foreach ($lignes_data as $ld) {
                $detail_id = (int)($ld['id'] ?? 0);
                $trouve    = $ld['trouve'] !== '' ? (int)$ld['trouve'] : null;
                $notes     = trim($ld['notes'] ?? '');
                if ($trouve === null) continue;

                $det = db_fetch_one("SELECT * FROM inventaire_details_equipements WHERE id=? AND inventaire_id=?", [$detail_id, $inv_id]);
                if (!$det) continue;

                db_query("UPDATE inventaire_details_equipements SET trouve=?,notes=? WHERE id=?", [$trouve,$notes,$detail_id]);
                $saved++;
            }
            db_commit();
            json_response(true,"$saved ligne(s) sauvegardée(s).",['saved'=>$saved]);
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    if ($action==='valider') {
        if (!$can_validate) json_response(false,'Seul le GSB ou un administrateur peut valider l\'inventaire.');
        $lignes = db_fetch_all("SELECT * FROM inventaire_details_equipements WHERE inventaire_id=?",[$inv_id]);
        $nb_ecarts = 0; $nb_trouves = 0;
        db_begin();
        try {
            foreach ($lignes as $l) {
                if ($l['trouve'] === null) continue;

                if ((int)$l['trouve'] === 1) {
                    $nb_trouves++;
                    db_query("UPDATE ecarts_equipements SET statut='resolu',resolu_at=NOW(),resolu_par=? WHERE equipement_id=? AND statut='ouvert'",
                        [$user['id'], $l['equipement_id']]);
                } else {
                    $nb_ecarts++;
                    $deja_ouvert = db_fetch_value("SELECT COUNT(*) FROM ecarts_equipements WHERE equipement_id=? AND statut='ouvert'", [$l['equipement_id']]);
                    if (!$deja_ouvert) {
                        db_query("INSERT INTO ecarts_equipements (site_id,equipement_id,date_constat,motif,source,inventaire_id,statut,created_by) VALUES (?,?,?,?,?,?,'ouvert',?)",
                            [$inv['site_id'],$l['equipement_id'],$inv['date_inventaire'],$l['notes']??'','inventaire',$inv_id,$user['id']]);
                    }
                }
            }
            db_query("UPDATE inventaires_equipements SET statut='valide',nb_ecarts=?,nb_trouves=?,valide_par=?,valide_at=NOW() WHERE id=?",
                [$nb_ecarts,$nb_trouves,$user['id'],$inv_id]);
            audit_log($user['id'],'UPDATE','inventaire_equipements',$inv_id,"Validation inventaire équipements #$inv_id — $nb_ecarts manquant(s)");
            db_commit();
            json_response(true,"Inventaire validé. $nb_ecarts équipement(s) manquant(s).");
        } catch(Exception $e){ db_rollback(); json_response(false,'Erreur: '.$e->getMessage()); }
    }

    json_response(false,'Action inconnue.');
}

// ============================================================
//  DONNÉES
// ============================================================
$lignes = db_fetch_all(
    "SELECT d.*,
            e.numero_serie_interne, e.marque, e.modele, e.etat, e.categorie,
            n.libelle AS type_libelle,
            CONCAT(u.prenom,' ',u.nom) AS utilisateur_nom
     FROM inventaire_details_equipements d
     JOIN equipements e ON e.id=d.equipement_id
     LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
     LEFT JOIN users u ON u.id=e.utilisateur_id
     WHERE d.inventaire_id=?
     ORDER BY e.numero_serie_interne",
    [$inv_id]
);

$corrections_map = [];
$corrections_rows = db_fetch_all(
    "SELECT c.*, CONCAT(u1.prenom,' ',u1.nom) AS demandeur_nom, CONCAT(u2.prenom,' ',u2.nom) AS traite_par_nom
     FROM inventaire_corrections_equipements c
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

$nb_saisis    = count(array_filter($lignes, fn($l)=>$l['trouve']!==null));
$nb_manquants = count(array_filter($lignes, fn($l)=>(int)$l['trouve']===0));
$nb_non_saisi = count($lignes) - $nb_saisis;

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

.presence-btn{padding:6px 14px;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;border:1.5px solid #e2e8f0;background:white;color:#64748b}
.presence-btn.found{border-color:#27ae60;color:#27ae60}
.presence-btn.found.active{background:#27ae60;color:white}
.presence-btn.missing{border-color:#e74c3c;color:#e74c3c}
.presence-btn.missing.active{background:#e74c3c;color:white}
.presence-btn:disabled{opacity:.55;cursor:not-allowed}
.presence-group{display:flex;gap:6px}
</style>

<!-- TOOLBAR -->
<div class="inv-toolbar">
  <div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
      <?php if ($inv['session_id'] && can('inventaire_sessions','can_read')): ?>
      <a href="<?= APP_URL ?>/pages/inventaire_sessions.php?id=<?= (int)$inv['session_id'] ?>" style="color:var(--muted);font-size:13px;text-decoration:none">← Session</a>
      <?php else: ?>
      <a href="<?= APP_URL ?>/pages/inventaire_equipements.php" style="color:var(--muted);font-size:13px;text-decoration:none">← Inventaires</a>
      <?php endif; ?>
      <h2 style="font-family:'Montserrat',sans-serif;font-size:18px;font-weight:800;color:var(--navy)">
        Inventaire équipements du <?= fmt_date($inv['date_inventaire']) ?>
      </h2>
      <span style="padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;background:<?= $inv['statut']==='valide'?'#eafaf1':($inv['statut']==='brouillon'?'#fff8e7':'#fdf0ef') ?>;color:<?= $inv['statut']==='valide'?'#1e8449':($inv['statut']==='brouillon'?'#b7791f':'#c0392b') ?>">
        <?= ['brouillon'=>'⏳ Brouillon','valide'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Validé','annule'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Annulé'][$inv['statut']]??$inv['statut'] ?>
      </span>
    </div>
    <div class="inv-meta">
      <?= h($inv['site_nom']??'Tous les sites') ?> · <strong><?= count($lignes) ?></strong> équipement(s) · Créé par <?= h($inv['createur']??'—') ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
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
  <span class="chip blue"><i class="ph ph-package" aria-hidden="true"></i> Équipements : <strong id="statTotal"><?= count($lignes) ?></strong></span>
  <span class="chip green"><i class="ph ph-check-circle" aria-hidden="true"></i> Trouvés : <strong id="statOk"><?= $nb_saisis - $nb_manquants ?></strong></span>
  <span class="chip red"><i class="ph ph-warning" aria-hidden="true"></i> Manquants : <strong id="statEcarts"><?= $nb_manquants ?></strong></span>
  <span class="chip gray">⏳ Non saisis : <strong id="statNonSaisis"><?= $nb_non_saisi ?></strong></span>
</div>

<div id="alertZone"></div>

<!-- MODAL RÉCAP VALIDATION -->
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

<!-- MODAL MOTIF -->
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
      <label style="font-size:12.5px;font-weight:700">Statut proposé <span style="font-weight:400;color:var(--muted)">(optionnel)</span></label>
      <select id="modalMotifValeur" class="form-control">
        <option value="">— Non précisé —</option>
        <option value="1">Trouvé</option>
        <option value="0">Manquant</option>
      </select>
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
      Corrigez d'abord le statut dans la colonne « Statut constaté », puis confirmez ici.
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
  <span><i class="ph ph-note-pencil" aria-hidden="true"></i> <strong>Statut constaté</strong> : présence physique confirmée sur site</span>
  <span style="border-left:1px solid var(--border);padding-left:16px">
    <span style="border-bottom:2px dashed #f39c12;padding-bottom:1px"><strong>Écart connu</strong></span> : déjà signalé manquant lors d'un précédent inventaire, non résolu
  </span>
</div>

<!-- TABLEAU PRINCIPAL -->
<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap" style="overflow-x:auto">
    <table id="invTable">
      <thead>
        <tr style="background:#0d1f35;color:white">
          <th style="padding:10px 14px;text-align:left">N° série interne</th>
          <th style="padding:10px 14px;text-align:left">Type</th>
          <th style="padding:10px 14px;text-align:left">Marque / Modèle</th>
          <th style="padding:10px 14px;text-align:left">Affecté à</th>
          <th style="padding:10px 14px;text-align:center">Écart connu</th>
          <th style="padding:10px 14px;text-align:center">Statut constaté</th>
          <?php if($can_edit): ?>
          <th style="padding:10px 14px;text-align:center">Enregistrement</th>
          <th style="padding:10px 14px;text-align:left">Demande de modification</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach($lignes as $l):
        $trouve   = $l['trouve'] !== null ? (int)$l['trouve'] : null;
        $saisi    = $trouve !== null;
        $manquant = $trouve === 0;
        $ecart_connu_avant = (int)($l['ecart_connu_avant'] ?? 0);
        $demande_active = $corrections_map[$l['id']]['active'] ?? null;
        $demande_close  = $corrections_map[$l['id']]['close']  ?? null;
        $deverrouille = $demande_active && (
            $demande_active['type'] === 'demande_site'
            || ($demande_active['type'] === 'demande_autorisation' && $demande_active['statut'] === 'autorise')
        );

        $row_bg = !$saisi && $ecart_connu_avant ? '#fffbec'
                : (!$saisi                      ? '#fffbf0'
                : ($manquant                     ? '#fff5f5'
                :                                  'white'));
      ?>
      <tr id="row-<?= $l['id'] ?>" style="border-bottom:1px solid #e2e8f0;background:<?= $row_bg ?>"
          data-numero="<?= h($l['numero_serie_interne']) ?>" data-connu="<?= $ecart_connu_avant ?>">

        <td style="padding:9px 14px;font-family:monospace;font-weight:700;color:var(--navy);font-size:13px"><?= h($l['numero_serie_interne']) ?></td>
        <td style="padding:9px 14px;font-size:12.5px"><?= h($l['type_libelle'] ?? '—') ?></td>
        <td style="padding:9px 14px;font-size:12.5px;color:var(--muted)"><?= h(trim(($l['marque']??'').' '.($l['modele']??'')) ?: '—') ?></td>
        <td style="padding:9px 14px;font-size:12.5px"><?= h($l['utilisateur_nom'] ?? '—') ?></td>

        <td style="padding:9px 14px;text-align:center">
          <?php if ($ecart_connu_avant): ?>
            <span style="font-family:'Montserrat',sans-serif;font-weight:800;color:#e74c3c"
                  title="Déjà signalé manquant, non résolu">Manquant</span>
          <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
        </td>

        <td style="padding:9px 14px;text-align:center" id="statut-<?= $l['id'] ?>">
          <?php if($can_edit): ?>
          <div class="presence-group" id="grp-<?= $l['id'] ?>">
            <button type="button" class="presence-btn found <?= $trouve===1?'active':'' ?>"
                    id="btn-found-<?= $l['id'] ?>"
                    data-sys="1"
                    <?= ($saisi && !$deverrouille) ? 'disabled' : '' ?>
                    onclick="setPresence(<?= $l['id'] ?>,1)"><i class="ph ph-check-circle" aria-hidden="true"></i> Trouvé</button>
            <button type="button" class="presence-btn missing <?= $trouve===0?'active':'' ?>"
                    id="btn-missing-<?= $l['id'] ?>"
                    <?= ($saisi && !$deverrouille) ? 'disabled' : '' ?>
                    onclick="setPresence(<?= $l['id'] ?>,0)"><i class="ph ph-warning" aria-hidden="true"></i> Manquant</button>
          </div>
          <input type="hidden" id="phy-<?= $l['id'] ?>" value="<?= $saisi ? $trouve : '' ?>" data-saved="<?= $saisi ? $trouve : '' ?>">
          <?php else: ?>
          <?php if($saisi): ?>
          <span style="font-weight:700;color:<?= $manquant?'#e74c3c':'#27ae60' ?>"><?= $manquant?'Manquant':'Trouvé' ?></span>
          <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
          <?php endif; ?>
        </td>

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

        <td style="padding:9px 14px;text-align:left;max-width:270px">
          <?php if ($demande_active && $demande_active['type'] === 'demande_site'): ?>
            <div style="background:#fff8e7;border:1px solid #f0d999;border-radius:8px;padding:8px 10px;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#b7791f"><i class="ph ph-diamond" aria-hidden="true"></i> Demandé par <?= h($demande_active['demandeur_nom'] ?? '—') ?></div>
              <div style="font-size:12px;color:#5a4a1f;margin-top:2px"><?= h($demande_active['motif']) ?></div>
              <?php if ($demande_active['valeur_proposee'] !== null): ?>
              <div style="font-size:12px;color:#5a4a1f;margin-top:2px">Statut proposé : <strong><?= $demande_active['valeur_proposee'] ? 'Trouvé' : 'Manquant' ?></strong></div>
              <?php endif; ?>
            </div>
            <?php if ($can_edit): ?>
            <button style="background:#fef3e2;color:#b7791f;border:1px solid #f0d999;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700"
                    onclick="repondreModif(<?= (int)$demande_active['id'] ?>,<?= $l['id'] ?>)">↩ Répondre</button>
            <?php endif; ?>

          <?php elseif ($demande_active && $demande_active['type'] === 'demande_autorisation' && $demande_active['statut'] === 'en_attente'): ?>
            <div style="background:#eaf2fb;border:1px solid #90caf9;border-radius:8px;padding:8px 10px;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#1565c0">⏳ Demandé par <?= h($demande_active['demandeur_nom'] ?? '—') ?> — en attente d'autorisation</div>
              <div style="font-size:12px;color:#1a3a5c;margin-top:2px"><?= h($demande_active['motif']) ?></div>
              <?php if ($demande_active['valeur_proposee'] !== null): ?>
              <div style="font-size:12px;color:#1a3a5c;margin-top:2px">Statut proposé : <strong><?= $demande_active['valeur_proposee'] ? 'Trouvé' : 'Manquant' ?></strong></div>
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
            <div style="background:#eafaf1;border:1px solid #bfe6d0;border-radius:8px;padding:8px 10px;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#1e8449"><i class="ph ph-check-circle" aria-hidden="true"></i> Autorisation accordée</div>
              <div style="font-size:12px;color:#1e5c3a;margin-top:2px">Corrigez le statut puis confirmez.</div>
            </div>
            <?php if ($can_edit): ?>
            <button style="background:#eafaf1;color:#1e8449;border:1px solid #bfe6d0;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700"
                    onclick="repondreModif(<?= (int)$demande_active['id'] ?>,<?= $l['id'] ?>)"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Enregistrer la correction</button>
            <?php endif; ?>

          <?php elseif ($saisi): ?>
            <?php if ($demande_close && $demande_close['statut'] === 'traite'): ?>
            <div style="font-size:12px;color:#1e8449;margin-bottom:6px">
              <i class="ph ph-check-circle" aria-hidden="true"></i> Modifiée suite à demande (<?= h($demande_close['demandeur_nom'] ?? '—') ?> → <?= $demande_close['valeur_finale'] ? 'Trouvé' : 'Manquant' ?>)
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

function setPresence(id, trouve){
  const inp = document.getElementById('phy-'+id);
  const btnFound = document.getElementById('btn-found-'+id);
  const btnMissing = document.getElementById('btn-missing-'+id);
  if(!inp || btnFound.disabled) return;
  inp.value = trouve;
  btnFound.classList.toggle('active', trouve===1);
  btnMissing.classList.toggle('active', trouve===0);
  majEtat(id, String(trouve), inp.dataset.saved||'');
  const row = document.getElementById('row-'+id);
  row.style.background = trouve===0 ? '#fff5f5' : '#f0fdf4';
  updateStats();
}

async function sauverLigne(id){
  const phyEl = document.getElementById('phy-'+id);
  const val   = phyEl ? phyEl.value : '';
  if(val===''){toast('Choisissez d\'abord Trouvé ou Manquant.','error');return;}
  const d = await ap({action:'sauver_ligne',detail_id:id,trouve:val,notes:''});
  if(d.success){
    if(phyEl){ phyEl.dataset.saved = val; }
    document.getElementById('btn-found-'+id).disabled = true;
    document.getElementById('btn-missing-'+id).disabled = true;
    majEtat(id, val, val);
    const btnEl = document.getElementById('btn-'+id);
    if(btnEl) btnEl.style.display='none';
    toast('Ligne sauvegardée.');
  } else toast('Erreur : '+d.message,'error');
}

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
  if(valeur===''){ alertEl.innerHTML='<div style="background:#fdf0ef;color:#c0392b;padding:8px 12px;border-radius:8px;font-size:12.5px;margin-bottom:10px">Choisissez Trouvé/Manquant avant de confirmer.</div>'; return; }
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
    const btnFound=document.getElementById('btn-found-'+id);
    if(phyEl && btnFound && !btnFound.disabled && phyEl.value!=='') lignes.push({id,trouve:phyEl.value,notes:''});
  });
  if(!lignes.length){toast('Aucune ligne à sauvegarder.','error');if(btn){btn.disabled=false;btn.textContent='💾 Tout sauver';}return;}
  const d=await ap({action:'sauver_tout',lignes:JSON.stringify(lignes)});
  if(btn){btn.disabled=false;btn.textContent='💾 Tout sauver';}
  if(d.success){
    lignes.forEach(l=>{
      const phyEl=document.getElementById('phy-'+l.id);
      if(phyEl){ phyEl.dataset.saved = l.trouve; }
      document.getElementById('btn-found-'+l.id).disabled = true;
      document.getElementById('btn-missing-'+l.id).disabled = true;
      majEtat(l.id, l.trouve, l.trouve);
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
      if(connu) lignesEcart.push({numero, statut:'—', connu});
      return;
    }
    saisis++;
    const trouve = parseInt(phyEl.value);
    if(trouve===0 || connu) lignesEcart.push({numero, statut: trouve===0?'Manquant':'Trouvé', connu});
  });

  document.getElementById('recapStats').innerHTML = `
    <div style="text-align:center;padding:10px;background:var(--tertiary,#f1f5f9);border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:var(--navy)">${total}</div>
      <div style="font-size:10.5px;color:var(--muted)">Équipements</div>
    </div>
    <div style="text-align:center;padding:10px;background:#eafaf1;border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:#1e8449">${saisis}</div>
      <div style="font-size:10.5px;color:#1e8449">Saisis</div>
    </div>
    <div style="text-align:center;padding:10px;background:${lignesEcart.length?'#fdf0ef':'#eafaf1'};border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:${lignesEcart.length?'#c0392b':'#1e8449'}">${lignesEcart.length}</div>
      <div style="font-size:10.5px;color:${lignesEcart.length?'#c0392b':'#1e8449'}">À vérifier</div>
    </div>
    <div style="text-align:center;padding:10px;background:${nonSaisis?'#fff8e7':'var(--tertiary,#f1f5f9)'};border-radius:8px">
      <div style="font-size:20px;font-weight:900;color:${nonSaisis?'#b7791f':'var(--navy)'}">${nonSaisis}</div>
      <div style="font-size:10.5px;color:${nonSaisis?'#b7791f':'var(--muted)'}">Non saisis</div>
    </div>`;

  const nonSaisiEl = document.getElementById('recapNonSaisi');
  if(nonSaisis>0){
    nonSaisiEl.style.display='block';
    nonSaisiEl.innerHTML = `<i class="ph ph-warning" aria-hidden="true"></i> ${nonSaisis} équipement(s) sans statut saisi ne seront pas pris en compte dans la validation.`;
  } else nonSaisiEl.style.display='none';

  const recapEl = document.getElementById('recapEcarts');
  if(lignesEcart.length){
    recapEl.innerHTML = `<div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:8px">Lignes à vérifier</div>
      <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;max-height:220px;overflow-y:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px">
          <thead><tr style="background:#f1f5f9"><th style="padding:6px 10px;text-align:left">N° série</th>
            <th style="padding:6px 10px;text-align:right">Statut constaté</th>
            <th style="padding:6px 10px;text-align:right">Écart connu</th></tr></thead>
          <tbody>${lignesEcart.map(l=>`<tr style="border-top:1px solid var(--border)">
            <td style="padding:6px 10px;font-family:monospace">${l.numero}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:${l.statut==='Manquant'?'#e74c3c':(l.statut==='Trouvé'?'#27ae60':'#94a3b8')}">${l.statut}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:${l.connu?'#e74c3c':'#94a3b8'}">${l.connu?'Manquant':'—'}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>`;
  } else {
    recapEl.innerHTML = `<div style="background:#eafaf1;color:#1e8449;border-radius:8px;padding:10px 14px;font-size:13px"><i class="ph ph-check-circle" aria-hidden="true"></i> Tous les équipements ont été retrouvés.</div>`;
  }

  document.getElementById('modalRecapValidation').style.display='flex';
}

async function confirmerValidation(){
  const btn = document.getElementById('btnConfirmerValidation');
  btn.disabled = true; btn.textContent = '⏳ Validation…';
  const d = await ap({action:'valider'});
  document.getElementById('modalRecapValidation').style.display='none';
  btn.disabled = false; btn.textContent = "✅ Oui, valider l'inventaire";
  if(d.success){toast(d.message);setTimeout(()=>location.href='inventaire_equipements.php',1500);}
  else{document.getElementById('alertZone').innerHTML=`<div style="background:#fdf0ef;padding:10px 16px;border-radius:8px;font-size:13px;color:#c0392b;margin-bottom:12px"><i class="ph ph-x-circle" aria-hidden="true"></i> ${d.message}</div>`;}
}

function updateStats(){
  const rows=document.querySelectorAll('tr[id^="row-"]');
  let total=rows.length, ok=0, ecarts=0, nonSaisis=0;
  rows.forEach(row=>{
    const id=row.id.replace('row-','');
    const phyEl=document.getElementById('phy-'+id);
    if(!phyEl||phyEl.value===''){nonSaisis++;}
    else{
      if(parseInt(phyEl.value)===1) ok++; else ecarts++;
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
