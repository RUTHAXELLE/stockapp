<?php
// ============================================================
//  pages/reception_site.php  —  Réception de commandes (Coordinateur)
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/upload.php';

require_auth();
require_permission('receptions', 'can_read');

$user        = current_user();
$page_title  = 'Réceptions de commandes';
$active_page = 'receptions';

// Filtre site : coordinateur voit uniquement son site, superviseur/admin voient tout
$is_coord = is_coordinateur_site();
$is_sup   = is_superviseur_operation();
$site_force = ($is_coord && $user['site_id']) ? (int)$user['site_id'] : 0;
$f_site     = $site_force ?: (int)($_GET['site'] ?? 0);
$f_statut   = $_GET['statut'] ?? '';
$f_type     = $_GET['type']   ?? '';

// ─── AJAX ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── RÉCEPTIONNER (coordinateur valide + upload fiche signée)
    if ($action === 'receptionner') {
        require_permission('receptions', 'can_create');

        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if (!$id) json_response(false, 'ID manquant.');

        $rec = db_fetch_one("SELECT * FROM receptions_site WHERE id=?", [$id]);
        if (!$rec) json_response(false, 'Réception introuvable.');

        // Coordinateur ne peut réceptionner que son site
        if ($is_coord && (int)$rec['site_id'] !== $site_force) {
            json_response(false, 'Accès refusé à cette réception.');
        }

        // Upload fiche signée OBLIGATOIRE
        $upload = upload_document('fichier_fiche', 'fiche', 'fiche_' . $id, true);
        if (!$upload['success']) json_response(false, $upload['message']);

        db_query(
            "UPDATE receptions_site SET statut='receptionnee', fichier_fiche=?, notes=?, updated_at=NOW() WHERE id=?",
            [$upload['filename'], $notes, $id]
        );
        audit_log($user['id'], 'UPDATE', 'receptions', $id,
            "Réception validée avec fiche signée — site_id:{$rec['site_id']}");
        json_response(true, 'Réception validée avec succès.', ['filename' => $upload['filename']]);
    }

    // ── SIGNALER LITIGE
    if ($action === 'litige') {
        require_permission('receptions', 'can_update');
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if (!$id || !$notes) json_response(false, 'ID et motif obligatoires.');

        $rec = db_fetch_one("SELECT rs.*, s.nom AS site_nom, c.libelle AS conso_lib, e.numero_serie_interne AS equip_num
                              FROM receptions_site rs
                              LEFT JOIN sites s ON s.id=rs.site_id
                              LEFT JOIN articles c ON c.id=rs.consommable_id
                              LEFT JOIN equipements e ON e.id=rs.equipement_id
                              WHERE rs.id=?", [$id]);
        if (!$rec) json_response(false, 'Réception introuvable.');
        if ($is_coord && (int)$rec['site_id'] !== $site_force) json_response(false, 'Accès refusé.');

        db_begin();
        try {
            db_query("UPDATE receptions_site SET statut='litige', litige_motif=?, notes=?, updated_at=NOW() WHERE id=?", [$notes, $notes, $id]);

            // Premier message dans le fil de discussion
            db_query("INSERT INTO litige_messages (reception_id,user_id,message) VALUES (?,?,?)",
                [$id, $user['id'], "⚠️ Litige signalé par {$user['prenom']} {$user['nom']} : $notes"]);

            // Notifier TOUS les gestionnaires de stock + admins
            $gestionnaires = db_fetch_all(
                "SELECT u.id, u.email, u.prenom FROM users u
                 JOIN roles r ON r.id=u.role_id
                 WHERE r.slug IN ('gestionnaire_stock','admin','superadmin') AND u.actif=1"
            );
            $article = $rec['conso_lib'] ?? $rec['equip_num'] ?? 'Article inconnu';
            $titre   = "⚠️ Litige réception — {$rec['site_nom']} : $article";
            $msg     = "Le coordinateur du site <strong>{$rec['site_nom']}</strong> a signalé un litige sur la réception de <strong>$article</strong>.<br><br>"
                     . "<strong>Motif :</strong> $notes<br><br>"
                     . "Veuillez traiter ce litige et renvoyer la commande si nécessaire.";

            foreach ($gestionnaires as $g) {
                notif_create('alerte_conso', $titre, $msg, $g['id'], '/pages/reception_site.php?statut=litige', false);
            }

            audit_log($user['id'], 'UPDATE', 'receptions', $id, "Litige signalé : $notes");
            db_commit();
            json_response(true, 'Litige enregistré. Les gestionnaires ont été notifiés.');
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : '.$e->getMessage());
        }
    }

    // ── AJOUTER MESSAGE DANS LE FIL DE DISCUSSION LITIGE
    if ($action === 'add_message') {
        require_permission('receptions', 'can_update');
        $id  = (int)($_POST['id'] ?? 0);
        $msg = trim($_POST['message'] ?? '');
        if (!$id || !$msg) json_response(false, 'Message vide.');
        db_query("INSERT INTO litige_messages (reception_id,user_id,message) VALUES (?,?,?)", [$id, $user['id'], $msg]);
        audit_log($user['id'], 'UPDATE', 'receptions', $id, "Message litige ajouté");
        json_response(true, 'Message envoyé.', [
            'nom'    => $user['prenom'].' '.$user['nom'],
            'msg'    => h($msg),
            'heure'  => date('d/m/Y H:i'),
        ]);
    }

    // ── TRAITER LITIGE (gestionnaire : renvoyer ou clôturer)
    if ($action === 'traiter_litige') {
        require_permission('receptions', 'can_update');
        if ($is_coord) json_response(false, 'Seul le gestionnaire peut traiter un litige.');
        $id     = (int)($_POST['id'] ?? 0);
        $action_type = trim($_POST['action_type'] ?? ''); // 'renvoi' ou 'cloture'
        $notes  = trim($_POST['notes'] ?? '');
        if (!$id || !$action_type) json_response(false, 'Données manquantes.');

        $rec = db_fetch_one("SELECT rs.*, s.nom AS site_nom, c.libelle AS conso_lib
                              FROM receptions_site rs LEFT JOIN sites s ON s.id=rs.site_id
                              LEFT JOIN articles c ON c.id=rs.consommable_id WHERE rs.id=?", [$id]);
        if (!$rec) json_response(false, 'Réception introuvable.');

        db_begin();
        try {
            $new_statut = $action_type === 'renvoi' ? 'en_attente' : 'receptionnee';
            db_query("UPDATE receptions_site SET statut=?, litige_traite_by=?, litige_traite_at=NOW(), remplacement_notes=?, updated_at=NOW() WHERE id=?",
                [$new_statut, $user['id'], $notes, $id]);

            $msg_fil = $action_type === 'renvoi'
                ? "🔄 Litige traité par {$user['prenom']} {$user['nom']} : commande renvoyée. $notes"
                : "✅ Litige clôturé par {$user['prenom']} {$user['nom']}. $notes";
            db_query("INSERT INTO litige_messages (reception_id,user_id,message) VALUES (?,?,?)", [$id, $user['id'], $msg_fil]);

            // Notifier le coordinateur du site
            $coord = db_fetch_all("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='coordinateur_site' AND u.site_id=? AND u.actif=1", [$rec['site_id']]);
            foreach ($coord as $c) {
                $titre_notif = $action_type === 'renvoi' ? "🔄 Commande renvoyée — {$rec['site_nom']}" : "✅ Litige clôturé — {$rec['site_nom']}";
                notif_create('info', $titre_notif, $msg_fil, $c['id'], '/pages/reception_site.php', false);
            }

            audit_log($user['id'], 'UPDATE', 'receptions', $id, "Litige traité ($action_type) : $notes");
            db_commit();
            json_response(true, $action_type === 'renvoi' ? 'Commande marquée comme renvoyée.' : 'Litige clôturé.');
        } catch (Exception $e) {
            db_rollback();
            json_response(false, 'Erreur : '.$e->getMessage());
        }
    }

    // ── DÉTAIL D'UNE RÉCEPTION (messages litige inclus)
    if ($action === 'get_detail') {
        $id  = (int)($_POST['id'] ?? 0);
        $rec = db_fetch_one(
            "SELECT rs.*, s.nom AS site_nom,
                    c.libelle AS conso_lib, c.unite, c.code AS conso_code,
                    e.numero_serie_interne AS equip_num, n.libelle AS equip_type,
                    CONCAT(u.prenom,' ',u.nom) AS createur
             FROM receptions_site rs
             JOIN sites s ON s.id=rs.site_id
             LEFT JOIN articles c ON c.id=rs.consommable_id
             LEFT JOIN equipements e ON e.id=rs.equipement_id
             LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
             LEFT JOIN users u ON u.id=rs.created_by
             WHERE rs.id=?", [$id]);
        if (!$rec) json_response(false, 'Introuvable.');
        if ($is_coord && (int)$rec['site_id'] !== $site_force) json_response(false, 'Accès refusé.');

        $messages = db_fetch_all(
            "SELECT lm.message, lm.created_at, CONCAT(u.prenom,' ',u.nom) AS auteur, r.nom AS role_nom
             FROM litige_messages lm
             JOIN users u ON u.id=lm.user_id
             JOIN roles r ON r.id=u.role_id
             WHERE lm.reception_id=? ORDER BY lm.created_at ASC", [$id]);

        json_response(true, '', ['rec' => $rec, 'messages' => $messages]);
    }

    json_response(false, 'Action inconnue.');
}

// ─── DONNÉES ─────────────────────────────────────────────────
$where   = ['1=1'];
$params  = [];

if ($f_site) { $where[] = 'rs.site_id=?'; $params[] = $f_site; }
if ($f_statut) { $where[] = 'rs.statut=?'; $params[] = $f_statut; }
if ($f_type)   { $where[] = 'rs.type_reception=?'; $params[] = $f_type; }

$where_sql = implode(' AND ', $where);

$receptions = db_fetch_all(
    "SELECT rs.*,
            s.nom AS site_nom,
            c.libelle AS conso_libelle, c.unite,
            e.numero_serie_interne AS equip_numero,
            n.libelle AS equip_type,
            CONCAT(u.prenom,' ',u.nom) AS createur
     FROM receptions_site rs
     JOIN sites s ON s.id = rs.site_id
     LEFT JOIN articles c ON c.id = rs.consommable_id
     LEFT JOIN equipements e ON e.id = rs.equipement_id
     LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id
     LEFT JOIN users u ON u.id = rs.created_by
     WHERE $where_sql
     ORDER BY rs.created_at DESC
     LIMIT 200",
    $params
);

// KPIs
$kpi_attente     = (int)db_fetch_value("SELECT COUNT(*) FROM receptions_site WHERE statut='en_attente'" . ($f_site ? " AND site_id=$f_site" : ''));
$kpi_receptionne = (int)db_fetch_value("SELECT COUNT(*) FROM receptions_site WHERE statut='receptionnee'" . ($f_site ? " AND site_id=$f_site" : ''));
$kpi_litige      = (int)db_fetch_value("SELECT COUNT(*) FROM receptions_site WHERE statut='litige'" . ($f_site ? " AND site_id=$f_site" : ''));

$sites_list = db_fetch_all("SELECT id,nom FROM sites WHERE actif=1 ORDER BY nom");

include __DIR__ . '/../templates/header.php';
?>
<style>
.rec-kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}
.rk{background:white;border:1px solid var(--border);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:14px}
.rk-icon{font-size:26px;flex-shrink:0}
.rk-val{font-family:'Montserrat',sans-serif;font-size:26px;font-weight:800;color:var(--navy)}
.rk-label{font-size:11.5px;color:var(--muted);margin-top:2px}
.rk.warning .rk-val{color:var(--warning-d)}
.rk.danger .rk-val{color:var(--danger-d)}
.rk.success .rk-val{color:var(--success-d)}

.statut-badge{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11.5px;font-weight:700}
.statut-badge.en_attente{background:#fff8e7;color:#b7791f}
.statut-badge.receptionnee{background:#eafaf1;color:#1e8449}
.statut-badge.litige{background:#fdf0ef;color:#c0392b}

.filter-bar{background:white;border:1px solid var(--border);border-radius:12px;padding:13px 18px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fsel{padding:7px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:white;cursor:pointer;font-family:'DM Sans',sans-serif}
.fsel:focus{border-color:var(--blue-mid,#1a56a0);outline:none}
</style>

<!-- KPIs -->
<div class="rec-kpi">
  <div class="rk warning">
    <div class="rk-icon">⏳</div>
    <div><div class="rk-val"><?= $kpi_attente ?></div><div class="rk-label">En attente de réception</div></div>
  </div>
  <div class="rk success">
    <div class="rk-icon"><i class="ph ph-check-circle" aria-hidden="true"></i></div>
    <div><div class="rk-val"><?= $kpi_receptionne ?></div><div class="rk-label">Réceptionnées</div></div>
  </div>
  <div class="rk danger">
    <div class="rk-icon"><i class="ph ph-warning" aria-hidden="true"></i></div>
    <div><div class="rk-val"><?= $kpi_litige ?></div><div class="rk-label">En litige</div></div>
  </div>
</div>

<!-- FILTRES -->
<form method="GET" class="filter-bar">
  <?php if(!$is_coord): ?>
  <label style="font-size:13px;font-weight:500">Site :</label>
  <select name="site" class="fsel" aria-label="Filtrer par site" onchange="this.form.submit()">
    <option value="0">Tous les sites</option>
    <?php foreach($sites_list as $s): ?>
    <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= h($s['nom']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="statut" class="fsel" aria-label="Filtrer par statut" onchange="this.form.submit()">
    <option value="">Tous les statuts</option>
    <option value="en_attente"  <?= $f_statut==='en_attente'?'selected':'' ?>>⏳ En attente</option>
    <option value="receptionnee" <?= $f_statut==='receptionnee'?'selected':'' ?>>✅ Réceptionnées</option>
    <option value="litige"      <?= $f_statut==='litige'?'selected':'' ?>>⚠️ Litige</option>
  </select>
  <select name="type" class="fsel" aria-label="Filtrer par type" onchange="this.form.submit()">
    <option value="">Tous les types</option>
    <option value="consommable" <?= $f_type==='consommable'?'selected':'' ?>>🧴 Consommables</option>
    <option value="equipement"  <?= $f_type==='equipement'?'selected':'' ?>>💻 Équipements</option>
  </select>
</form>

<!-- TABLEAU -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:14px;font-weight:700;color:var(--navy)">
      <i class="ph ph-package" aria-hidden="true"></i> Liste des réceptions
    </h3>
    <span style="font-size:12px;color:var(--muted)"><?= count($receptions) ?> enregistrement(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Date</th>
        <th>Site</th>
        <th>Type</th>
        <th>Article / Équipement</th>
        <th style="text-align:right">Quantité</th>
        <th>Statut</th>
        <th>Fiche signée</th>
        <th style="text-align:center">Actions</th>
      </tr></thead>
      <tbody>
      <?php if(empty($receptions)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucune réception trouvée.</td></tr>
      <?php else: foreach($receptions as $r): ?>
        <tr>
          <td style="font-size:12.5px;color:var(--muted)"><?= fmt_date($r['date_reception']) ?></td>
          <td style="font-size:13px;font-weight:500"><?= h($r['site_nom']) ?></td>
          <td>
            <span style="font-size:11.5px;font-weight:700;color:<?= $r['type_reception']==='consommable'?'#27ae60':'#1a56a0' ?>">
              <?= $r['type_reception']==='consommable'?'<i class="ph ph-flask" aria-hidden="true"></i> Consommable':'<i class="ph ph-desktop" aria-hidden="true"></i> Équipement' ?>
            </span>
          </td>
          <td style="font-size:13px">
            <?php if($r['type_reception']==='consommable'): ?>
              <?= h($r['conso_libelle']??'—') ?>
              <?php if($r['quantite']): ?><span style="color:var(--muted);font-size:12px"> · <?= fmt_number($r['quantite'],1) ?> <?= h($r['unite']??'') ?></span><?php endif; ?>
            <?php else: ?>
              <?= h($r['equip_type']??'—') ?>
              <?php if($r['equip_numero']): ?><br><span style="font-family:monospace;font-size:12px;color:var(--muted)"><?= h($r['equip_numero']) ?></span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td style="text-align:right;font-weight:700;font-family:'Montserrat',sans-serif">
            <?= $r['quantite'] ? fmt_number($r['quantite'],1) : '—' ?>
          </td>
          <td><span class="statut-badge <?= $r['statut'] ?>"><?= match($r['statut']){
            'en_attente'  =>'⏳ En attente',
            'receptionnee'=>'<i class="ph ph-check-circle" aria-hidden="true"></i> Réceptionnée',
            'litige'      =>'<i class="ph ph-warning" aria-hidden="true"></i> Litige',
            default       =>h($r['statut'])
          } ?></span></td>
          <td><?= upload_link($r['fichier_fiche']??'','fiche','Voir fiche') ?></td>
          <td style="text-align:center;white-space:nowrap">
            <!-- Bouton Détail : tout le monde -->
            <button class="btn btn-sm" style="background:#e8f4fd;color:#1a56a0;border:none;padding:4px 8px;border-radius:6px;cursor:pointer" onclick="openDetail(<?= $r['id'] ?>)"><i class="ph ph-magnifying-glass" aria-hidden="true"></i> Détail</button>

            <?php
            $role = $user['role_slug'];
            $peut_receptionner = $is_coord; // seul le coordinateur réceptionne
            $peut_traiter_litige = !$is_coord && in_array($role, ['gestionnaire_stock','admin','superadmin']);
            ?>

            <?php if($r['statut']==='en_attente' && $peut_receptionner): ?>
              <!-- Coordinateur : réceptionner ou signaler litige -->
              <button class="btn btn-sm btn-success" onclick="openReceptionner(<?= $r['id'] ?>)" style="margin-left:4px"><i class="ph ph-check-circle" aria-hidden="true"></i> Réceptionner</button>
              <button class="btn btn-sm btn-danger"  onclick="openLitige(<?= $r['id'] ?>)" style="margin-left:4px"><i class="ph ph-warning" aria-hidden="true"></i> Litige</button>

            <?php elseif($r['statut']==='litige' && $peut_traiter_litige): ?>
              <!-- Gestionnaire/Admin : traiter le litige -->
              <button class="btn btn-sm" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:5px 10px;border-radius:6px;cursor:pointer;margin-left:4px;font-weight:600" onclick="openTraiterLitige(<?= $r['id'] ?>)"><i class="ph ph-wrench" aria-hidden="true"></i> Traiter litige</button>

            <?php elseif($r['statut']==='receptionnee'): ?>
              <span style="font-size:12px;color:var(--success-d);margin-left:4px"><i class="ph ph-check-circle" aria-hidden="true"></i> Validé</span>

            <?php elseif($r['statut']==='litige' && $is_coord): ?>
              <span style="font-size:12px;color:var(--danger-d);margin-left:4px"><i class="ph ph-warning" aria-hidden="true"></i> En cours de traitement</span>

            <?php elseif($r['statut']==='en_attente' && !$is_coord): ?>
              <span style="font-size:12px;color:var(--muted);margin-left:4px">⏳ Attente coordinateur</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL RÉCEPTIONNER -->
<div id="modalReceptionner" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin-bottom:18px">
      <i class="ph ph-check-circle" aria-hidden="true"></i> Valider la réception
    </h3>
    <input type="hidden" id="recId">

    <div class="form-group" style="margin-bottom:14px">
      <label style="font-size:13px;font-weight:600;color:var(--text);display:block;margin-bottom:6px">
        <i class="ph ph-paperclip" aria-hidden="true"></i> Fiche signée <span style="color:var(--danger-d)">*</span>
        <span style="font-size:12px;font-weight:400;color:var(--muted)">(PDF ou image — obligatoire)</span>
      </label>
      <input type="file" id="recFiche" accept=".pdf,.jpg,.jpeg,.png,.webp"
             style="width:100%;padding:8px;border:2px dashed var(--border);border-radius:8px;font-size:13px;cursor:pointer;background:var(--lighter)">
      <div id="recFichePreview" style="display:none;margin-top:8px;font-size:12px;color:var(--success-d)"></div>
    </div>

    <div class="form-group" style="margin-bottom:18px">
      <label style="font-size:13px;font-weight:600;color:var(--text);display:block;margin-bottom:6px">Notes</label>
      <textarea id="recNotes" rows="3" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:13px;resize:vertical" placeholder="Observations éventuelles..."></textarea>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="closeModal('modalReceptionner')">Annuler</button>
      <button class="btn btn-success" id="btnRecValider" onclick="doReceptionner()"><i class="ph ph-check-circle" aria-hidden="true"></i> Valider la réception</button>
    </div>
  </div>
</div>

<!-- MODAL LITIGE -->
<div id="modalLitige" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--danger-d);margin-bottom:18px">
      <i class="ph ph-warning" aria-hidden="true"></i> Signaler un litige
    </h3>
    <input type="hidden" id="litId">
    <div class="form-group">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Motif du litige <span style="color:var(--danger-d)">*</span></label>
      <textarea id="litNotes" rows="4" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:13px;resize:vertical" placeholder="Décrivez le problème constaté (manque, dommage, erreur...)"></textarea>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-secondary" onclick="closeModal('modalLitige')">Annuler</button>
      <button class="btn btn-danger" onclick="doLitige()"><i class="ph ph-warning" aria-hidden="true"></i> Confirmer le litige</button>
    </div>
  </div>
</div>

<!-- MODAL DÉTAIL RÉCEPTION -->
<div id="modalDetail" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:600px;max-width:95vw;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--navy)"><i class="ph ph-magnifying-glass" aria-hidden="true"></i> Détail réception</h3>
      <button onclick="closeModal('modalDetail')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted)"><i class="ph ph-x" aria-hidden="true"></i></button>
    </div>
    <div id="detailContent" style="font-size:13px">
      <div style="text-align:center;color:var(--muted);padding:20px">Chargement…</div>
    </div>

    <!-- FIL DE DISCUSSION LITIGE -->
    <div id="litFil" style="display:none;margin-top:18px;border-top:1px solid var(--border);padding-top:16px">
      <h4 style="font-family:'Montserrat',sans-serif;font-size:13px;font-weight:700;color:var(--navy);margin-bottom:10px"><i class="ph ph-chat-circle" aria-hidden="true"></i> Discussion litige</h4>
      <div id="litMessages" style="max-height:200px;overflow-y:auto;margin-bottom:12px;display:flex;flex-direction:column;gap:8px"></div>
      <div style="display:flex;gap:8px">
        <textarea id="litMsg" rows="2" style="flex:1;padding:8px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;resize:none" placeholder="Votre message…"></textarea>
        <button class="btn btn-primary btn-sm" onclick="sendMessage()" style="align-self:flex-end">Envoyer</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL TRAITER LITIGE (gestionnaire) -->
<div id="modalTraiterLitige" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:16px;padding:28px;width:500px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800;color:var(--navy);margin-bottom:18px"><i class="ph ph-wrench" aria-hidden="true"></i> Traiter le litige</h3>
    <input type="hidden" id="traitId">
    <div class="form-group" style="margin-bottom:14px">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px">Action à effectuer</label>
      <div style="display:flex;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;padding:10px 16px;border:2px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;flex:1">
          <input type="radio" name="action_type" value="renvoi" checked> <i class="ph ph-arrow-clockwise" aria-hidden="true"></i> Renvoyer la commande
        </label>
        <label style="display:flex;align-items:center;gap:6px;padding:10px 16px;border:2px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;flex:1">
          <input type="radio" name="action_type" value="cloture"> <i class="ph ph-check-circle" aria-hidden="true"></i> Clôturer le litige
        </label>
      </div>
    </div>
    <div class="form-group" style="margin-bottom:18px">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Notes / Actions effectuées <span style="color:var(--danger-d)">*</span></label>
      <textarea id="traitNotes" rows="4" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:13px;resize:vertical" placeholder="Ex: Commande renvoyée le 19/04, manque de 5 unités compensé…"></textarea>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="closeModal('modalTraiterLitige')">Annuler</button>
      <button class="btn btn-primary" onclick="doTraiterLitige()"><i class="ph ph-wrench" aria-hidden="true"></i> Valider le traitement</button>
    </div>
  </div>
</div>

<script>
function openModal(id){ document.getElementById(id).style.display='flex'; }
function closeModal(id){ document.getElementById(id).style.display='none'; }

let currentDetailId = null;

function openDetail(id){
  currentDetailId = id;
  document.getElementById('detailContent').innerHTML = '<div style="text-align:center;color:var(--muted);padding:20px">Chargement…</div>';
  document.getElementById('litFil').style.display = 'none';
  document.getElementById('litMessages').innerHTML = '';
  openModal('modalDetail');

  const fd = new FormData();
  fd.append('action','get_detail'); fd.append('id',id);
  fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
      if(!d.success){document.getElementById('detailContent').innerHTML='<div style="color:var(--danger-d)">Erreur.</div>';return;}
      const rec = d.data.rec;
      const statutColor = rec.statut==='receptionnee'?'#1e8449':rec.statut==='litige'?'#c0392b':'#b7791f';
      const statutBg    = rec.statut==='receptionnee'?'#eafaf1':rec.statut==='litige'?'#fdf0ef':'#fff8e7';
      const statutLabel = rec.statut==='receptionnee'?'✅ Réceptionnée':rec.statut==='litige'?'⚠️ Litige':'⏳ En attente';

      let html = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
          <div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">Site</div><div style="font-weight:700">${rec.site_nom}</div></div>
          <div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">Statut</div>
            <span style="padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;background:${statutBg};color:${statutColor}">${statutLabel}</span>
          </div>
          <div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">Date réception</div><div>${rec.date_reception}</div></div>
          <div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">Type</div>
            <div>${rec.type_reception==='consommable'?'<i class="ph ph-flask" aria-hidden="true"></i> Consommable':'<i class="ph ph-desktop" aria-hidden="true"></i> Équipement'}</div>
          </div>`;

      if(rec.type_reception==='consommable'){
        html += `<div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">Article</div>
          <div style="font-weight:600">${rec.conso_lib||'—'} <small style="color:var(--muted)">(${rec.conso_code||''})</small></div></div>
          <div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">Quantité</div>
          <div style="font-weight:700;font-size:16px">${rec.quantite||'—'} <small>${rec.unite||''}</small></div></div>`;
      } else {
        html += `<div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">Équipement</div>
          <div style="font-weight:600">${rec.equip_type||'—'}</div></div>
          <div><div style="font-size:12px;color:var(--muted);margin-bottom:3px;text-transform:uppercase">N° Série</div>
          <div style="font-family:monospace;font-size:12px">${rec.equip_num||'—'}</div></div>`;
      }
      html += `</div>`;

      if(rec.notes){
        html += `<div style="background:var(--lighter);padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:12px">
          <strong>📝 Notes :</strong> ${rec.notes}</div>`;
      }
      if(rec.litige_motif){
        html += `<div style="background:#fdf0ef;padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:12px;border-left:3px solid var(--danger)">
          <strong>⚠️ Motif du litige :</strong> ${rec.litige_motif}</div>`;
      }
      if(rec.remplacement_notes){
        html += `<div style="background:#eafaf1;padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:12px;border-left:3px solid var(--success)">
          <strong>🔧 Traitement :</strong> ${rec.remplacement_notes}</div>`;
      }
      if(rec.fichier_fiche){
        html += `<div style="margin-bottom:12px"><a href="<?= APP_URL ?>/uploads/fiches/${encodeURIComponent(rec.fichier_fiche)}" target="_blank" style="color:var(--blue-mid,#1a56a0);font-size:13px">📄 Voir la fiche signée</a></div>`;
      }

      document.getElementById('detailContent').innerHTML = html;

      // Fil de discussion si litige
      if(rec.statut === 'litige' || d.data.messages.length > 0){
        document.getElementById('litFil').style.display = 'block';
        const msgs = d.data.messages;
        const cont = document.getElementById('litMessages');
        cont.innerHTML = msgs.length ? msgs.map(m=>`
          <div style="background:var(--lighter);padding:8px 12px;border-radius:8px;font-size:12.5px">
            <strong>${m.auteur}</strong> <span style="color:var(--muted);font-size:12px">(${m.role_nom}) · ${m.created_at}</span><br>
            ${m.message}
          </div>`).join('') : '<div style="color:var(--muted);font-size:12px;text-align:center">Aucun message encore.</div>';
        cont.scrollTop = cont.scrollHeight;
      }
    });
}

async function sendMessage(){
  const msg = document.getElementById('litMsg').value.trim();
  if(!msg || !currentDetailId) return;
  const fd = new FormData();
  fd.append('action','add_message'); fd.append('id',currentDetailId); fd.append('message',msg);
  const r = await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
  const d = await r.json();
  if(d.success){
    document.getElementById('litMsg').value='';
    const cont = document.getElementById('litMessages');
    cont.innerHTML += `<div style="background:#e8f4fd;padding:8px 12px;border-radius:8px;font-size:12.5px">
      <strong>${d.data.nom}</strong> <span style="color:var(--muted);font-size:12px">· ${d.data.heure}</span><br>${d.data.msg}</div>`;
    cont.scrollTop = cont.scrollHeight;
  }
}

function openTraiterLitige(id){
  document.getElementById('traitId').value = id;
  document.getElementById('traitNotes').value = '';
  document.querySelectorAll('input[name="action_type"]')[0].checked = true;
  openModal('modalTraiterLitige');
}

async function doTraiterLitige(){
  const notes = document.getElementById('traitNotes').value.trim();
  if(!notes){alert('Les notes sont obligatoires.');return;}
  const action_type = document.querySelector('input[name="action_type"]:checked').value;
  const fd = new FormData();
  fd.append('action','traiter_litige');
  fd.append('id', document.getElementById('traitId').value);
  fd.append('action_type', action_type);
  fd.append('notes', notes);
  const r = await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
  const d = await r.json();
  if(d.success){closeModal('modalTraiterLitige');window.location.reload();}
  else alert('Erreur : '+d.message);
}

['modalDetail','modalTraiterLitige'].forEach(id=>{
  document.getElementById(id).addEventListener('click',function(e){if(e.target===this)closeModal(id);});
});

function openReceptionner(id){
  document.getElementById('recId').value    = id;
  document.getElementById('recFiche').value = '';
  document.getElementById('recNotes').value = '';
  document.getElementById('recFichePreview').style.display='none';
  openModal('modalReceptionner');
}

document.getElementById('recFiche').addEventListener('change', function(){
  const prev = document.getElementById('recFichePreview');
  if(this.files.length){
    prev.style.display='block';
    prev.textContent = '✔ Fichier sélectionné : ' + this.files[0].name + ' (' + (this.files[0].size/1024).toFixed(1) + ' Ko)';
  } else {
    prev.style.display='none';
  }
});

async function doReceptionner(){
  const fiche = document.getElementById('recFiche').files[0];
  if(!fiche){ alert('La fiche signée est obligatoire.'); return; }

  const btn = document.getElementById('btnRecValider');
  btn.disabled = true; btn.textContent = '⏳ Envoi en cours...';

  const fd = new FormData();
  fd.append('action','receptionner');
  fd.append('id', document.getElementById('recId').value);
  fd.append('notes', document.getElementById('recNotes').value);
  fd.append('fichier_fiche', fiche);

  try {
    const r = await fetch(window.location.href, {
      method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd
    });
    const d = await r.json();
    if(d.success){
      closeModal('modalReceptionner');
      window.location.reload();
    } else {
      alert('Erreur : ' + d.message);
      btn.disabled=false; btn.textContent='✅ Valider la réception';
    }
  } catch(e){
    alert('Erreur réseau.'); btn.disabled=false; btn.textContent='✅ Valider la réception';
  }
}

function openLitige(id){
  document.getElementById('litId').value    = id;
  document.getElementById('litNotes').value = '';
  openModal('modalLitige');
}

async function doLitige(){
  const notes = document.getElementById('litNotes').value.trim();
  if(!notes){ alert('Le motif est obligatoire.'); return; }

  const fd = new FormData();
  fd.append('action','litige');
  fd.append('id', document.getElementById('litId').value);
  fd.append('notes', notes);

  const r = await fetch(window.location.href, {
    method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd
  });
  const d = await r.json();
  if(d.success){ closeModal('modalLitige'); window.location.reload(); }
  else alert('Erreur : ' + d.message);
}

['modalReceptionner','modalLitige'].forEach(id=>{
  const el = document.getElementById(id);
  if(el) el.addEventListener('click', function(e){
    if(e.target===this) closeModal(id);
  });
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
