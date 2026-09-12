<?php
// ============================================================
//  pages/interventions.php — Maintenance préventive & curative
//  + Demandes d'intervention coordinateurs
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

require_auth();
require_permission('interventions', 'can_read');

$user      = current_user();
$role_slug = $user['role_slug'] ?? '';
$page_title  = 'Interventions & Maintenance';
$active_page = 'interventions';
$is_coord    = $role_slug === 'coordinateur_site';
$site_force  = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;
$can_create  = can('interventions','can_create');
$sites_list  = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

// ── AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Créer intervention (maintenancier/superviseur IT/admin)
    if ($action === 'creer') {
        if (!$can_create) json_response(false,'Accès refusé.');
        $equipement_id = (int)($_POST['equipement_id'] ?? 0);
        $type          = trim($_POST['type_intervention'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $site_id       = $site_force ?: (int)($_POST['site_id'] ?? 0);
        $date_prevue   = trim($_POST['date_prevue'] ?? date('Y-m-d'));
        $priorite      = trim($_POST['priorite'] ?? 'normale');

        if (!in_array($type, ['preventive','curative']))
            json_response(false,'Type invalide. Choisir preventive ou curative.');
        if (!$description) json_response(false,'Description obligatoire.');

        $statut = $type === 'preventive' ? 'planifiee' : 'en_cours';

        // Mapper les nouveaux champs vers la structure existante
        $type_action_map = ['preventive'=>'maintenance_preventive','curative'=>'maintenance_corrective'];
        $type_action = $type_action_map[$type] ?? 'maintenance_corrective';
        db_query(
            "INSERT INTO interventions_maintenance
             (technicien_id,site_id,equipement_id,date_intervention,type_action,description,statut_apres)
             VALUES (?,?,?,?,?,?,'en_attente')",
            [$user['id'], $site_id ?: null, $equipement_id ?: null, $date_prevue, $type_action, $description]
        );
        $id = (int)db_last_id();
        audit_log($user['id'],'CREATE','interventions',$id,"Intervention $type créée");
        json_response(true,'Intervention créée.', ['id'=>$id]);
    }

    // Demande d'intervention par coordinateur
    if ($action === 'demande_coordinateur') {
        require_permission('interventions', 'can_create');
        $equipement_id = (int)($_POST['equipement_id'] ?? 0);
        $description   = trim($_POST['description'] ?? '');
        $site_id       = $site_force ?: (int)($_POST['site_id'] ?? 0);
        if (!$description) json_response(false,'Description de la panne obligatoire.');

        db_query(
            "INSERT INTO interventions_maintenance
             (technicien_id,site_id,equipement_id,date_intervention,type_action,description,probleme_signale,statut_apres)
             VALUES (?,?,?,CURRENT_DATE,'maintenance_corrective',?,?,'en_attente')",
            [$user['id'], $site_id ?: null, $equipement_id ?: null,
             "Panne signalée par coordinateur: $description", $description]
        );
        $id = (int)db_last_id();

        // Notifier maintenanciers et superviseur IT
        $notif_targets = db_fetch_all(
            "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
             WHERE (r.slug IN ('admin','superadmin','superviseur_it')
               OR (r.slug='support_it' AND EXISTS (
                   SELECT 1 FROM support_it_roles sir WHERE sir.user_id=u.id AND sir.sous_role='maintenance' AND sir.actif=1
               ))) AND u.actif=1"
        );
        $equip_nom = $equipement_id
            ? (db_fetch_value(
                "SELECT CONCAT(numero_serie_interne, CASE WHEN marque IS NOT NULL THEN CONCAT(' — ', marque, ' ', COALESCE(modele,'')) ELSE '' END)
                 FROM equipements WHERE id=?", [$equipement_id]) ?? 'Équipement non spécifié')
            : 'Équipement non spécifié';
        $site_nom  = $site_id ? db_fetch_value("SELECT nom FROM sites WHERE id=?",[$site_id]) : '';
        foreach ($notif_targets as $t) {
            db_query("INSERT INTO notifications (user_id,type,titre,message,lien) VALUES (?,?,?,?,?)",
                [$t['id'], 'info', '🔧 Demande intervention', "Panne signalée sur {$equip_nom} — Site {$site_nom} par {$user['prenom']} {$user['nom']}: {$description}",
                 '/pages/interventions.php']);
        }
        audit_log($user['id'],'CREATE','interventions',$id,"Demande intervention coordinateur: $description");
        json_response(true,'Demande envoyée au service maintenance.');
    }

    // Changer statut
    if ($action === 'changer_statut') {
        if (!$can_create) json_response(false,'Accès refusé.');
        $id     = (int)($_POST['id'] ?? 0);
        $statut = trim($_POST['statut'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        $allowed = ['planifiee','en_cours','terminee','annulee','demandee'];
        if (!in_array($statut,$allowed)) json_response(false,'Statut invalide.');
        $extra = $statut === 'terminee' ? ", date_realisation=NOW(), notes_resolution=?" : "";
        $params = $statut === 'terminee' ? [$statut,$notes,$id] : [$statut,$id];
        // Mapper statut vers statut_apres
        $statut_map = ['planifiee'=>'en_attente','en_cours'=>'en_attente','terminee'=>'resolu','annulee'=>'escalade','demandee'=>'en_attente'];
        $statut_mapped = $statut_map[$statut] ?? 'en_attente';
        if($statut==="terminee"){db_query("UPDATE interventions_maintenance SET statut_apres='resolu',solution_apportee=? WHERE id=?",[$notes,$id]);}else{db_query("UPDATE interventions_maintenance SET statut_apres=? WHERE id=?",[$statut_mapped,$id]);}
        audit_log($user['id'],'UPDATE','interventions',$id,"Statut → $statut");
        json_response(true,'Statut mis à jour.');
    }

    json_response(false,'Action inconnue.');
}

// ── DONNÉES
$f_site  = $site_force ?: (int)($_GET['site'] ?? 0);
$f_type  = trim($_GET['type'] ?? '');
$f_statut= trim($_GET['statut'] ?? '');
$onglet  = trim($_GET['tab'] ?? 'toutes');

$where = ['1=1']; $params = [];
if ($site_force)  { $where[] = 'i.site_id=?'; $params[] = $site_force; }
elseif ($f_site)  { $where[] = 'i.site_id=?'; $params[] = $f_site; }
if ($f_type)      { $where[] = 'i.type_action=?'; $params[] = $f_type; }
if ($f_statut)    { $where[] = 'i.statut_apres=?'; $params[] = $f_statut; }

$interventions = db_fetch_all(
    "SELECT i.*,
            CONCAT(e.numero_serie_interne, CASE WHEN e.marque IS NOT NULL THEN CONCAT(' — ', e.marque, ' ', COALESCE(e.modele,'')) ELSE '' END) AS equip_nom,
            e.numero_serie_interne,
            s.nom AS site_nom,
            CONCAT(u.prenom,' ',u.nom) AS createur,
            -- Mapping colonnes existantes vers noms attendus
            i.type_action AS type_intervention,
            i.statut_apres AS statut,
            i.date_intervention AS date_prevue,
            i.technicien_id AS created_by_id,
            'normale' AS priorite,
            0 AS demande_par_coord
     FROM interventions_maintenance i
     LEFT JOIN equipements e ON e.id=i.equipement_id
     LEFT JOIN sites s ON s.id=i.site_id
     LEFT JOIN users u ON u.id=i.technicien_id
     WHERE ".implode(' AND ',$where)."
     ORDER BY i.created_at DESC LIMIT 100",
    $params
);

$equipements_site = db_fetch_all(
    "SELECT id, CONCAT(numero_serie_interne, CASE WHEN marque IS NOT NULL THEN CONCAT(' — ', marque, ' ', COALESCE(modele,'')) ELSE '' END) AS nom,numero_serie_interne,categorie FROM equipements WHERE actif=1"
    . ($site_force ? " AND site_id=$site_force" : "")
    . " ORDER BY nom"
);

// KPIs
$nb_preventive  = count(array_filter($interventions, fn($i)=>$i['type_intervention']==='preventive'));
$nb_curative    = count(array_filter($interventions, fn($i)=>$i['type_intervention']==='curative'));
$nb_demandees   = count(array_filter($interventions, fn($i)=>$i['statut']==='demandee'));
$nb_en_cours    = count(array_filter($interventions, fn($i)=>$i['statut']==='en_cours'));

include __DIR__ . '/../templates/header.php';
?>
<style>
.int-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.ik{background:white;border-radius:14px;border:1px solid var(--border);padding:16px 20px;border-left:4px solid var(--blue)}
.ik.orange{border-left-color:#f39c12} .ik.red{border-left-color:var(--danger)} .ik.green{border-left-color:var(--success)}
.ik-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:26px;font-weight:900;color:var(--navy)}
.ik-lbl{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.type-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.type-preventive{background:#e8f4f9;color:var(--blue)} .type-curative{background:#fee2e2;color:#991b1b}
.statut-planifiee{background:#dbeafe;color:#1d4ed8} .statut-en_cours{background:#fef3c7;color:#92400e}
.statut-terminee{background:#d1fae5;color:#065f46} .statut-demandee{background:#fee2e2;color:#991b1b}
.statut-annulee{background:#f1f5f9;color:#64748b}
.prio-urgente{background:#fee2e2;color:#991b1b;font-weight:700} .prio-haute{background:#fef3c7;color:#92400e}
.prio-normale{background:#f1f5f9;color:#475569}
.tab-nav{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px}
.tab-btn{padding:11px 22px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:var(--muted);border-bottom:3px solid transparent;margin-bottom:-2px;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;}
.tab-btn.active{color:var(--primary,var(--blue));border-bottom-color:var(--primary,var(--blue))}
</style>

<!-- KPIs -->
<div class="int-kpis">
  <div class="ik"><div class="ik-val"><?= $nb_preventive ?></div><div class="ik-lbl"><i class="ph ph-calendar" aria-hidden="true"></i> Préventives</div></div>
  <div class="ik red"><div class="ik-val" style="color:var(--danger-d)"><?= $nb_curative ?></div><div class="ik-lbl"><i class="ph ph-wrench" aria-hidden="true"></i> Curatives</div></div>
  <div class="ik orange"><div class="ik-val" style="color:#f39c12"><?= $nb_demandees ?></div><div class="ik-lbl"><i class="ph ph-tray" aria-hidden="true"></i> Demandes en attente</div></div>
  <div class="ik green"><div class="ik-val" style="color:#f39c12"><?= $nb_en_cours ?></div><div class="ik-lbl"><i class="ph ph-gear" aria-hidden="true"></i> En cours</div></div>
</div>

<!-- TABS -->
<div class="tab-nav">
  <button class="tab-btn <?= $onglet==='toutes'?'active':'' ?>" onclick="showTab('toutes',this)"><i class="ph ph-wrench" aria-hidden="true"></i> Toutes</button>
  <button class="tab-btn <?= $onglet==='preventive'?'active':'' ?>" onclick="showTab('preventive',this)"><i class="ph ph-calendar" aria-hidden="true"></i> Préventives</button>
  <button class="tab-btn <?= $onglet==='curative'?'active':'' ?>" onclick="showTab('curative',this)"><i class="ph ph-warning-octagon" aria-hidden="true"></i> Curatives</button>
  <?php if($nb_demandees>0): ?>
  <button class="tab-btn <?= $onglet==='demandes'?'active':'' ?>" onclick="showTab('demandes',this)">
    <i class="ph ph-tray" aria-hidden="true"></i> Demandes <span style="background:var(--danger);color:white;border-radius:10px;padding:1px 7px;font-size:12px;margin-left:4px"><?= $nb_demandees ?></span>
  </button>
  <?php endif; ?>
</div>

<!-- TOOLBAR -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= h($onglet) ?>">
    <?php if(!$site_force): ?>
    <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:white;outline:none">
      <option value="">Tous les sites</option>
      <?php foreach($sites_list as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </form>
  <div style="display:flex;gap:8px">
    <?php if($is_coord): ?>
    <button class="btn btn-primary" onclick="ouvrirDemande()"><i class="ph ph-wrench" aria-hidden="true"></i> Signaler une panne</button>
    <?php endif; ?>
    <?php if($can_create && !$is_coord): ?>
    <button class="btn btn-primary" onclick="ouvrirCreation('preventive')"><i class="ph ph-calendar" aria-hidden="true"></i> + Préventive</button>
    <button class="btn" style="background:#fee2e2;color:#991b1b;border:1.5px solid #fca5a5" onclick="ouvrirCreation('curative')"><i class="ph ph-warning-octagon" aria-hidden="true"></i> + Curative</button>
    <?php endif; ?>
  </div>
</div>

<!-- LISTES PAR ONGLET -->
<?php
$types_onglets = ['toutes'=>null,'preventive'=>'preventive','curative'=>'curative','demandes'=>null];
foreach($types_onglets as $tab_key => $type_filtre):
  $items = $tab_key === 'demandes'
    ? array_filter($interventions, fn($i)=>$i['statut']==='demandee')
    : ($type_filtre ? array_filter($interventions,fn($i)=>$i['type_intervention']===$type_filtre) : $interventions);
  $items = array_values($items);
?>
<div id="tab-<?= $tab_key ?>" style="display:<?= $onglet===$tab_key?'block':'none' ?>">
  <div class="card">
    <div class="card-header">
      <h3>
        <?= $tab_key==='preventive'?'<i class="ph ph-calendar" aria-hidden="true"></i> Interventions Préventives':($tab_key==='curative'?'<i class="ph ph-warning-octagon" aria-hidden="true"></i> Interventions Curatives':($tab_key==='demandes'?'<i class="ph ph-tray" aria-hidden="true"></i> Demandes Coordinateurs':'<i class="ph ph-wrench" aria-hidden="true"></i> Toutes les Interventions')) ?>
        <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($items) ?>)</span>
      </h3>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Date</th><th>Type</th><th>Équipement</th><th>Site</th>
          <th>Description</th><th style="text-align:center">Priorité</th>
          <th>Statut</th><th>Créé par</th>
          <?php if(!$is_coord): ?><th style="text-align:center">Actions</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if(empty($items)): ?>
          <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--muted)">Aucune intervention.</td></tr>
        <?php else: foreach($items as $int): ?>
          <tr style="<?= $int['statut']==='demandee'?'background:#fffbf0':'' ?>">
            <td style="font-weight:600;white-space:nowrap"><?= fmt_date($int['date_intervention']??$int['created_at'],'d/m/Y') ?></td>
            <?php $is_prev = in_array($int['type_action']??'',['maintenance_preventive']); ?>
            <td><span class="type-badge type-<?= $is_prev?'preventive':'curative' ?>"><?= $is_prev ? '<i class="ph ph-calendar" aria-hidden="true"></i> Préventive' : '<i class="ph ph-warning-octagon" aria-hidden="true"></i> Curative' ?></span></td>
            <td style="font-size:13px">
              <?= h($int['equip_nom']??'—') ?>
              <?php if($int['numero_serie_interne']): ?><div style="font-size:12px;color:var(--muted)"><?= h($int['numero_serie_interne']) ?></div><?php endif; ?>
            </td>
            <td><?= h($int['site_nom']??'—') ?></td>
            <td style="font-size:12px;max-width:200px"><?= h($int['description']) ?></td>
            <td style="text-align:center"><span class="type-badge prio-<?= 'normale' ?>"><?= ucfirst('normale') ?></span></td>
            <td><span class="type-badge statut-<?= $int['statut'] ?>"><?= [
              'planifiee'=>'<i class="ph ph-calendar" aria-hidden="true"></i> Planifiée','en_cours'=>'<i class="ph ph-gear" aria-hidden="true"></i> En cours','terminee'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Terminée',
              'demandee'=>'<i class="ph ph-tray" aria-hidden="true"></i> Demandée','annulee'=>'<i class="ph ph-x-circle" aria-hidden="true"></i> Annulée'
            ][$int['statut']] ?? $int['statut'] ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= h($int['createur']??'—') ?></td>
            <?php if(!$is_coord): ?>
            <td style="text-align:center;white-space:nowrap">
              <?php if(in_array($int['statut'],['demandee','planifiee'])): ?>
              <button class="btn btn-primary btn-sm" onclick="changerStatut(<?= $int['id'] ?>,'en_cours')">▶️ Démarrer</button>
              <?php endif; ?>
              <?php if($int['statut']==='en_cours'): ?>
              <button class="btn btn-success btn-sm" onclick="terminer(<?= $int['id'] ?>)"><i class="ph ph-check-circle" aria-hidden="true"></i> Terminer</button>
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
<?php endforeach; ?>

<!-- MODAL DEMANDE COORDINATEUR -->
<?php if($is_coord): ?>
<div id="modalDemande" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:20px;padding:28px;width:500px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)"><i class="ph ph-wrench" aria-hidden="true"></i> Signaler une panne</h3>
      <button onclick="fermer('Demande')" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="alertDemande"></div>
    <div class="form-group" style="margin-bottom:14px">
      <label>Équipement concerné</label>
      <select class="form-control" id="dEquip">
        <option value="">— Sélectionner (optionnel) —</option>
        <?php foreach($equipements_site as $e): ?>
        <option value="<?= $e['id'] ?>"><?= h($e['nom']) ?> — <?= h($e['numero_serie_interne']??'') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:20px">
      <label>Description de la panne *</label>
      <textarea class="form-control" id="dDesc" rows="3" placeholder="Décrivez le problème observé..."></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer('Demande')">Annuler</button>
      <button class="btn btn-primary" onclick="envoyerDemande()"><i class="ph ph-upload-simple" aria-hidden="true"></i> Envoyer la demande</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL CRÉATION (maintenancier) -->
<?php if($can_create && !$is_coord): ?>
<div id="modalCreation" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:20px;padding:28px;width:520px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy)" id="titleCreation">Nouvelle intervention</h3>
      <button onclick="fermer('Creation')" style="background:none;border:none;font-size:22px;cursor:pointer"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <input type="hidden" id="cType" value="preventive">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
      <div class="form-group">
        <label>Site</label>
        <select class="form-control" id="cSite">
          <option value="">Tous</option>
          <?php foreach($sites_list as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Date prévue</label>
        <input type="date" class="form-control" id="cDate" value="<?= date('Y-m-d') ?>">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:14px">
      <label>Équipement</label>
      <select class="form-control" id="cEquip">
        <option value="">— Sélectionner —</option>
        <?php foreach($equipements_site as $e): ?>
        <option value="<?= $e['id'] ?>"><?= h($e['nom']) ?> (<?= h($e['categorie']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:14px">
      <label>Priorité</label>
      <select class="form-control" id="cPrio">
        <option value="normale">Normale</option>
        <option value="haute">Haute</option>
        <option value="urgente">Urgente</option>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:20px">
      <label>Description *</label>
      <textarea class="form-control" id="cDesc" rows="3" placeholder="Travaux prévus, nature de l'intervention..."></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer('Creation')">Annuler</button>
      <button class="btn btn-primary" onclick="creer()"><i class="ph ph-check-circle" aria-hidden="true"></i> Créer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL TERMINER -->
<div id="modalTerminer" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:20px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin-bottom:20px"><i class="ph ph-check-circle" aria-hidden="true"></i> Clôturer l'intervention</h3>
    <input type="hidden" id="tId">
    <div class="form-group" style="margin-bottom:20px">
      <label>Notes de résolution</label>
      <textarea class="form-control" id="tNotes" rows="3" placeholder="Travaux effectués, pièces remplacées..."></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn-secondary" onclick="fermer('Terminer')">Annuler</button>
      <button class="btn btn-success" onclick="confirmerTerminer()"><i class="ph ph-check-circle" aria-hidden="true"></i> Clôturer</button>
    </div>
  </div>
</div>

<script>
function ap(d){return fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(r=>r.json());}
function toast(m,t='success'){let el=document.getElementById('toast-live');if(!el){el=document.createElement('div');el.id='toast-live';el.setAttribute('role','status');el.setAttribute('aria-live','polite');el.setAttribute('aria-atomic','true');document.body.appendChild(el);}clearTimeout(el._hideTimer);el.style.cssText=`position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;background:${t==='success'?'#27ae60':'#e74c3c'};color:white`;el.textContent=m;el._hideTimer=setTimeout(()=>{el.style.display='none';},3500);}
function showTab(name,btn){document.querySelectorAll('[id^="tab-"]').forEach(t=>t.style.display='none');document.getElementById('tab-'+name).style.display='block';document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}
function fermer(mod){document.getElementById('modal'+mod).style.display='none';}

function ouvrirDemande(){document.getElementById('dDesc').value='';document.getElementById('alertDemande').innerHTML='';document.getElementById('modalDemande').style.display='flex';}
async function envoyerDemande(){
  const desc  = document.getElementById('dDesc').value.trim();
  const equip = document.getElementById('dEquip').value;
  const alertEl = document.getElementById('alertDemande');
  if(!desc){alertEl.innerHTML='<div class="alert alert-danger">Description de la panne obligatoire.</div>';return;}
  const btn = document.querySelector('#modalDemande .btn-primary');
  btn.disabled = true; btn.textContent = '⏳ Envoi…';
  try {
    const d = await ap({action:'demande_coordinateur', description:desc, equipement_id:equip||0});
    if(d.success){
      toast(d.message,'success');
      fermer('Demande');
      setTimeout(()=>location.reload(), 800);
    } else {
      alertEl.innerHTML = `<div class="alert alert-danger">${d.message}</div>`;
    }
  } catch(err) {
    alertEl.innerHTML = '<div class="alert alert-danger">Erreur réseau. Réessayez.</div>';
  } finally {
    btn.disabled = false; btn.textContent = '📤 Envoyer la demande';
  }
}

function ouvrirCreation(type){
  document.getElementById('cType').value=type;
  document.getElementById('titleCreation').textContent=type==='preventive'?'📅 Nouvelle intervention préventive':'🚨 Nouvelle intervention curative';
  document.getElementById('modalCreation').style.display='flex';
}
async function creer(){
  const d=await ap({action:'creer',type_intervention:document.getElementById('cType').value,site_id:document.getElementById('cSite').value,equipement_id:document.getElementById('cEquip').value,date_prevue:document.getElementById('cDate').value,priorite:document.getElementById('cPrio').value,description:document.getElementById('cDesc').value});
  if(d.success){toast(d.message);fermer('Creation');setTimeout(()=>location.reload(),800);}else toast(d.message,'error');
}

async function changerStatut(id,statut){if(!confirm('Confirmer ?'))return;const d=await ap({action:'changer_statut',id,statut});if(d.success){toast(d.message);setTimeout(()=>location.reload(),800);}else toast(d.message,'error');}

function terminer(id){document.getElementById('tId').value=id;document.getElementById('tNotes').value='';document.getElementById('modalTerminer').style.display='flex';}
async function confirmerTerminer(){const d=await ap({action:'changer_statut',id:document.getElementById('tId').value,statut:'terminee',notes:document.getElementById('tNotes').value});if(d.success){toast(d.message);fermer('Terminer');setTimeout(()=>location.reload(),800);}else toast(d.message,'error');}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
